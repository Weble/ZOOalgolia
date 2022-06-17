<?php

namespace Weble\ZOOAlgolia;

use Algolia\AlgoliaSearch\SearchClient;
use Algolia\AlgoliaSearch\SearchIndex;
use App;
use Application;
use Category;
use ElementTextareaPro;
use ItemRenderer;
use JFolder;
use Joomla\CMS\Factory;
use Joomla\CMS\Language\LanguageHelper;
use Joomla\CMS\Menu\MenuItem;
use Joomla\CMS\Router\Route;
use Joomla\Filesystem\Folder;
use Joomla\Registry\Registry;
use stdClass;
use Type;

class AlgoliaSync
{

    private App $zoo;
    private ItemRenderer $renderer;
    private ?SearchClient $client = null;
    private ?SearchIndex $index = null;
    private ?array $menuItems = null;
    private array $categories = [];
    private Application $application;

    public function __construct(\Type $type)
    {
        $this->application = $type->getApplication();


        $this->zoo = $this->application->app;
        $this->renderer = $this->zoo->renderer->create('item', ['path' => $this->zoo->path]);

        if ($this->application->getParams()->get('global.config.algolia_app_id') && $this->application->getParams()->get('global.config.algolia_app_id')) {
            $this->client = SearchClient::create(
                $this->application->getParams()->get('global.config.algolia_app_id'),
                $this->application->getParams()->get('global.config.algolia_secret_key')
            );
        }

        if ($this->client && $this->application->getParams()->get('global.config.algolia_index_'. $type->identifier)) {
            $this->index = $this->client->initIndex($this->application->getParams()->get('global.config.algolia_index_'. $type->identifier));
        }

    }

    public function isConfigured(): bool
    {
        return $this->client && $this->index;
    }

    public function batchSync(array $items): void
    {
        if (!$this->isConfigured()) {
            return;
        }

        $save = [];
        $delete = [];
        foreach ($items as $item) {
            $data = $this->algoliaData($item);

            if (!$data) {
                $delete[] = $item;
                continue;
            }

            $save[] = $data;
        }


        if (count($delete) > 0) {
            $this->index->deleteObjects($delete, [
                'objectIDKey' => 'id'
            ]);
        }

        if (count($save) > 0) {
            $this->index->saveObjects($save, [
                'objectIDKey' => 'id'
            ]);
        }
    }

    public function batchDelete(array $items): void
    {
        if (!$this->isConfigured()) {
            return;
        }

        if (count($items) > 0) {
            $this->index->deleteObjects($items);
        }
    }

    public function clearSync(): void
    {
        if (!$this->isConfigured()) {
            return;
        }

        $this->index->clearObjects();
    }

    public function sync(\Item $item): bool
    {
        if (!$this->isConfigured()) {
            return false;
        }

        $data = $this->algoliaData($item);

        if ($data) {
            return $this->index->saveObject($data, [
                'objectIDKey' => 'id'
            ])->valid();
        }

        return $this->index->deleteObject($item->id)->valid();
    }

    private function algoliaData(\Item $item, $full_related_data = true): ?array
    {
        if (!$item->isPublished()) {
            return null;
        }

        /** @var Application $application */
        $application = $this->application;

        /** @var Type $type */
        $type = $item->getType();

        $config = new Registry($this->renderer->getConfig('root:plugins/system/zooalgolia/renderer/item')->get($application->getGroup() . '.' . $type->identifier . '.algolia'));
        $config = $config->get('search', []);

        if (!count($config)) {
            return null;
        }
        
        $this->categories = $application->getCategoryTree();
        $data = [
            'id'  => $item->id,
            'url' => [],
            'category_ids' => array_filter(array_merge($item->getRelatedCategoryIds(), $this->array_flatten(array_map(function($id) {
                /** @var Category $category */
                $category = $this->categories[$id] ?? null;
                if (!$category) {
                    return [];
                }

                return array_map(function($parent) {
                    return $parent->id;
                }, $category->getPathway());

            }, $item->getRelatedCategoryIds()))))
        ];

        foreach (LanguageHelper::getContentLanguages() as $lang => $languageDetails) {
            $data['url'][$lang] = $this->getItemUrls($item, $lang);
        };

        foreach ($config as $elementConfig) {
            $element = $item->getElement($elementConfig['element']);

            if (!$element) {
                continue;
            }

            $key = $elementConfig['element'];

            if ($elementConfig['altlabel'] ?? false) {
                $key = $elementConfig['altlabel'];
            }

            $value = $this->elementValueFor($element, $elementConfig, $full_related_data);

            $data[$key] = $value;

            $parts = explode(".", $key);

            if (count($parts) > 1) {
                $newKey = array_shift($parts);
                $previousData = $data[$newKey] ?? [];
                $language = array_shift($parts);
                $data[$newKey] = $previousData + [$language => $value];

                unset($data[$key]);

            }
        }

        $data_override = $this->notify('onYooAlgoliaData', [$item, $data]);

        if ($data_override != null) {
            return $data_override;
        }

        return $data;
    }

    private function elementValueFor(\Element $element, array $params, $full_related_data = true)
    {
        $value = null;

        $value = $this->notify('onYooAlgoliaBeforeElementData', [$element, $params]);

        if ($value !== null) {
            return $value;
        }


        if ($element instanceof \ElementItemName) {
            return $element->getItem()->name;
        }

        if ($element instanceof \ElementItemPrimaryCategory) {
            $category = $element->getItem()->getPrimaryCategory();

            return $this->getDataForCategory($category);
        }

        if ($element instanceof \ElementTextPro || $element instanceof ElementTextareaPro) {

            $data = $element->data();
            if ($data === null) {
                $data = [];
            }

            $values = array_filter(array_map(function ($item) use ($params) {
                $value = $item['value'] ?? '';
                $value = strip_tags($value);
                $max_length = $params['specific._max_car'] ?? 0;
                $tr_suffix = $params['specific._max_car_suffix'] ?? '';

                if ($max_length > 0) {
                    $value = $item->app->zlstring->truncate($value, $max_length, $tr_suffix);
                }

                return strlen($value) > 0 ? $value : null;
            }, $data));

            $repeatable = $element->config->get('repeatable', false);
            if ($params['filter']['_limit'] ?? null === 1) {
                $repeatable = false;
            }


            if ($repeatable) {
                return $values;
            }

            return array_shift($values);
        }

        if ($element instanceof \ElementItemCategory) {
            $categories = $element->getItem()->getRelatedCategories();

            $data = [];
            foreach ($categories as $category) {
                $data[] = $this->getDataForCategory($category);
            }

            return $data;
        }


        if ($element instanceof \ElementFilesPro) {

            if (!$element->data()) {
                return null;
            }

            /* If image */
            if ($element instanceof \ElementImagePro) {

                $values = [];

                foreach ($element->data() as $item) {

                    if (!isset($item['file'])) {
                        continue;
                    }


                    $values[] = '/' . $item['file'];
                }

                if ($element->config->get('repeatable', false)) {
                    return $values;
                }

                return $values ? array_shift($values) : null;
            }

            /* If file */
            $values = array_filter(array_map(function ($item) use ($element) {

                if (!isset($item['file'])) {
                    return null;
                }

                $file = $this->zoo->zoo->resizeImage(JPATH_ROOT . '/' . $item['file'], 200, 300);

                $source_dir = $element->getConfig()->files['_source_dir'];

                if ($source_dir) {
                    $file = Folder::move($file, $source_dir);
                }

                $url = $this->zoo->path->relative($file);

                return $url ? '/' . $url : null;

            }, $element->data()));

            if ($element->config->get('repeatable', false)) {
                return $values;
            }

            return $values ? array_shift($values) : null;
        }


        if ($element instanceof \ElementRelatedItemsPro) {

            $related_items = $element->getRelatedItems(true);
            $items = [];

            foreach ($related_items as $item) {

                if ($full_related_data) {
                    $data = $this->algoliaData($item, false);
                } else {
                    $data = [];
                    $data['id'] = $item->id;
                }

                if ($data) {
                    $items[] = $data;
                }
            }

           return $items;

        }

        if ($element instanceof \ElementOption) {

            $values = $element->get('option', []);

            $options = [];
            foreach ($element->config->get('option', []) as $option) {
                if (in_array($option['value'], $values)) {
                    $options[] = $option['name'];
                }
            }

            return [
                'values' => $element->get('option', []),
                'names'  => $options
            ];
        }


        if ($element instanceof \ElementRepeatable) {

            $values = array_filter(array_map(function ($item) {
                $value = $item['value'] ?? '';
                return strlen($value) > 0 ? $value : null;
            }, $element->data()));

            if ($element->config->get('repeatable', false)) {
                return $values;
            }

            return array_shift($values);
        }


        if ($element instanceof \ElementItemTag) {
            return $element->getItem()->getTags();
        }

        if ($value = $element->getValue()) {
            return $value;
        }

        return $this->notify('onYooAlgoliaElementData', [$element]);

    }

    private function notify($event_name, $data)
    {
        $application = Factory::getApplication();

        $value = $application->triggerEvent($event_name, $data);

        if (!is_array($value) || count($value) == 0) {
            return null;
        }

        return count($value) > 1 ? array_merge($value) : array_shift($value);

    }

    protected function findMenuItem($type, $id, $lang)
    {
        $zoo = App::getInstance('zoo');
        if ($this->menuItems === null) {
            $this->menuItems = array_fill_keys(
                [
                    'frontpage',
                    'category',
                    'item',
                    'submission',
                    'mysubmissions'
                ],
                []
            );

            foreach (LanguageHelper::getContentLanguages() as $langCode => $language) {
                $menu_items = $zoo->system->application->getMenu('site')->getItems([
                    'language',
                    'component_id'
                ], [
                    $langCode,
                    \JComponentHelper::getComponent('com_zoo')->id
                ]) ?: [];

                /** @var MenuItem $menu_item */
                foreach ($menu_items as $menu_item) {
                    /** @var Registry $menuItemParams */
                    $menuItemParams = $zoo->parameter->create($menu_item->params);
                    $menuItemLanguage = $menu_item->language;

                    switch (@$menu_item->query['view']) {
                        case 'frontpage':
                            $this->menuItems['frontpage'][$menuItemParams->get('application')][$menuItemLanguage] = $menu_item;
                            break;
                        case 'category':
                            $this->menuItems['category'][$menuItemParams->get('category')][$menuItemLanguage] = $menu_item;
                            break;
                        case 'item':
                            $this->menuItems['item'][$menuItemParams->get('item_id')][$menuItemLanguage] = $menu_item;
                            break;
                        case 'submission':
                            $this->menuItems[(@$menu_item->query['layout'] == 'submission' ? 'submission' : 'mysubmissions')][$menuItemParams->get('submission')][$menuItemLanguage] = $menu_item;
                            break;
                    }
                }
            }
        }

        return @$this->menuItems[$type][$id][$lang] ?: @$this->menuItems[$type][$id]['*'];
    }

    private function getItemUrls(\Item $item, $lang)
    {
        $urls = [];
        // Priority 1: direct link to item
        if ($menu_item = $this->findMenuItem('item', $item->id, $lang)) {
            return [
                'default' => str_replace('/item/', '/',
                    Route::link('site', $menu_item->link . '&Itemid=' . $menu_item->id))
            ];
        }

        $menu_item_frontpage = $this->findMenuItem('frontpage', $item->application_id, $lang);

        // build item link
        $langCode = $lang === 'en-GB' ? 'en' : 'it';
        $link = $this->getLinkBase() . '&task=item&item_id=' . $item->id . '&lang=' . $langCode;
        $categories = $item->getRelatedCategories(true);
        $primary_category = $item->getPrimaryCategory();

        if (!$categories && $menu_item_frontpage) {
            return [
                'default' => str_replace('/item/', '/',
                    Route::link('site', $link . '&Itemid=' . $menu_item_frontpage->id))
            ];
        }

        if (!$categories) {
            return ['default' => str_replace('/item/', '/', Route::link('site', $link))];
        }

        foreach ($categories as $category) {

            $link_cat = $link;
            $itemid = null;

            /* If not category */
            if (!$category || !$category->id) {
                $urls['default'] = str_replace('/item/', '/', Route::link('site', $link_cat));
                continue;
            }

            /* Else */
            // direct link to category
            if ($menu_item = $this->findMenuItem('category', $category->id, $lang)) {
                $itemid = $menu_item->id;
                // find in category path
            } elseif ($menu_item = $this->findInCategoryPath($category, $lang)) {
                $itemid = $menu_item->id;
            } elseif ($menu_item_frontpage) {
                $itemid = $menu_item_frontpage->id;
            }

            if ($itemid) {
                $link_cat .= '&Itemid=' . $itemid;
            }

            if ($category->id) {
                $urls[$category->id] = str_replace('/item/', '/', Route::link('site', $link_cat));
            }

            if ($primary_category && $primary_category->id == $category->id) {
                $urls['default'] = str_replace('/item/', '/', Route::link('site', $link_cat));
            }
        }

        return $urls;
    }

    private function getCategoryUrl(\Category $category, $lang)
    {
        $urls = [];
        // Priority 1: direct link to item
        if ($menu_item = $this->findMenuItem('category', $category->id, $lang)) {
            return str_replace('/category/', '/', Route::link('site', $menu_item->link . '&Itemid=' . $menu_item->id));
        }

        $menu_item_frontpage = $this->findMenuItem('frontpage', $category->application_id, $lang);

        // build category link
        $langCode = $lang === 'en-GB' ? 'en' : 'it';
        $link = $this->getLinkBase() . '&task=category&category_id=' . $category->id . '&lang=' . $langCode;

        $itemid = null;
        if ($menu_item = $this->findInCategoryPath($category, $lang)) {
            $itemid = $menu_item->id;
        } elseif ($menu_item_frontpage) {
            $itemid = $menu_item_frontpage->id;
        }

        return str_replace('/category/', '/', Route::link('site', $link . '&Itemid=' . $itemid));
    }

    /**
     * Finds the category in the pathway
     *
     * @param Category $category
     * @return stdClass menu item
     * @since 2.0
     */
    protected function findInCategoryPath($category, $lang)
    {
        foreach ($category->getPathway() as $id => $cat) {
            if ($menu_item = $this->findMenuItem('category', $id, $lang)) {
                return $menu_item;
            }
        }
    }

    /**
     * Gets this route helpers link base
     *
     * @return string the link base
     * @since 2.0
     */
    public function getLinkBase()
    {
        return 'index.php?option=com_zoo';
    }


    private function getDataForCategory(?Category $category = null, bool $pathway = true): ?array
    {
        if (!$category) {
            return null;
        }

        if (!isset($this->categories[$category->id])) {
            return null;
        }

        /** @var Category $category */
        $category = $this->categories[$category->id];

        $image = $category->getParams()->get('content.teaser_image');
        $data = [
            'id'   => $category->id,
            'name' => [],
            'url' => [],
            'image' =>  $image ? '/' . $image : null
        ];

        if ($pathway) {
            $data['path'] = [];
            foreach ($category->getPathWay() as $cat) {
                $data['path'][] = $this->getDataForCategory($cat, false);
            }
        }

        foreach (LanguageHelper::getContentLanguages() as $lang => $languageDetail) {
            $data['name'][$lang] = $category->getParams()->get('content.name_translation')[$lang] ?? $category->name;
            $data['url'][$lang] = $this->getCategoryUrl($category, $lang);
        }

        return $data;
    }

    private function array_flatten($array = null) {
        $result = array();

        if (!is_array($array)) {
            $array = func_get_args();
        }

        foreach ($array as $key => $value) {
            if (is_array($value)) {
                $result = array_merge($result, array_flatten($value));
            } else {
                $result = array_merge($result, array($key => $value));
            }
        }

        return $result;
    }
}
