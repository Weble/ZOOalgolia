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
use Joomla\CMS\Application\CMSApplication;
use Joomla\CMS\Component\ComponentHelper;
use Joomla\CMS\Factory;
use Joomla\CMS\Language\Language;
use Joomla\CMS\Language\LanguageHelper;
use Joomla\CMS\Menu\MenuItem;
use Joomla\CMS\Plugin\PluginHelper;
use Joomla\CMS\Router\Route;
use Joomla\CMS\Router\Router;
use Joomla\CMS\Uri\Uri;
use Joomla\Filesystem\Folder;
use Joomla\Registry\Registry;
use Joomla\String\StringHelper;
use stdClass;
use Type;
use PlgSystemLanguageFilter;

class AlgoliaSync
{

    private App $zoo;
    private ItemRenderer $renderer;
    private ?SearchClient $client = null;
    private ?SearchIndex $index = null;
    private ?array $menuItems = null;
    private array $categories = [];
    private Application $application;
    private CMSApplication $app;
    /**
     * @var mixed
     */
    private bool $mode_sef;
    private array $lang_codes;
    /**
     * @var mixed|stdClass
     */
    private string $default_lang;
    /**
     * @var false|mixed|stdClass|string
     */
    private string $current_lang;
    private Registry $params;

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

        if ($this->client && $this->application->getParams()->get('global.config.algolia_index_' . $type->identifier)) {
            $this->index = $this->client->initIndex($this->application->getParams()->get('global.config.algolia_index_' . $type->identifier));
        }

        $this->loadRouterLanguageRules();
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
        $application = $item->getApplication();

        /** @var Type $type */
        $type = $item->getType();

        $config = new Registry($this->renderer->getConfig('root:plugins/system/zooalgolia/renderer/item')->get($application->getGroup() . '.' . $type->identifier . '.algolia'));
        $config = $config->get('search', []);

        if (!count($config)) {
            return null;
        }

        $this->categories = $application->getCategoryTree();
        $data = [
            'id'           => $item->id,
            'url'          => [],
            'category_ids' => array_filter(array_merge($item->getRelatedCategoryIds(), $this->array_flatten(array_map(function ($id) {
                /** @var Category $category */
                $category = $this->categories[$id] ?? null;
                if (!$category) {
                    return [];
                }

                return array_map(function ($parent) {
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

            if (!isset($data[$key])) {
                $data[$key] = $value;
            } else {
                if (!is_array($data[$key])) {
                    $data[$key] = [$data[$key]];

                    $data[$key][] = $value;
                }
            }

            $parts = explode(".", $key);

            if (count($parts) > 1) {
                $newKey = array_shift($parts);
                $previousData = $data[$newKey] ?? [];
                $language = array_shift($parts);
                $data[$newKey] = $previousData + [$language => $value];

                unset($data[$key]);

            }
        }

        $data_override = $this->notify('onYooAlgoliaData', [
            $item,
            $data
        ]);

        if ($data_override != null) {
            return $data_override;
        }

        return $data;
    }

    private function elementValueFor(\Element $element, array $params, $full_related_data = true)
    {
        $value = null;

        $value = $this->notify('onYooAlgoliaBeforeElementData', [
            $element,
            $params
        ]);

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


            $menu_items = $zoo->system->application->getMenu('site')->getItems([
                'component_id'
            ], [
                \JComponentHelper::getComponent('com_zoo')->id
            ]) ?: [];


            /** @var MenuItem $menu_item */
            foreach ($menu_items as $menu_item) {
                /** @var Registry $menuItemParams */
                $menuItemParams = $menu_item->getParams();
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
                'default' => $this->formatItemUrl($link . '&Itemid=' . $menu_item_frontpage->id)
            ];
        }

        if (!$categories) {
            return ['default' => $this->formatItemUrl($link)];
        }

        foreach ($categories as $category) {

            $link_cat = $link;
            $itemid = null;

            /* If not category */
            if (!$category || !$category->id) {
                $urls['default'] = $this->formatItemUrl($link_cat);
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
                $urls[$category->id] = $this->formatItemUrl($link_cat);
            }

            if ($primary_category && $primary_category->id == $category->id) {
                $urls['default'] = $this->formatItemUrl($link_cat);
            }
        }

        return $urls;
    }

    private function formatItemUrl($raw_link)
    {

        $plugin = \JPluginHelper::getPlugin('system', 'zooseo');

        if (!$plugin) {
            return \JRoute::link('site', $raw_link);
        }

        if (!\JPluginHelper::isEnabled('system', 'zooseo')) {
            return \JRoute::link('site', $raw_link);
        }

        $params = json_decode($plugin->params);

        if ($params->remove_item != '1') {
            return \JRoute::link('site', $raw_link);
        }

        return str_replace('/item/', '/', \JRoute::link('site', $raw_link));

    }


    private function formatCategoryUrl($raw_link)
    {

        $plugin = \JPluginHelper::getPlugin('system', 'zooseo');

        if (!$plugin) {
            return \JRoute::link('site', $raw_link);
        }

        if (!\JPluginHelper::isEnabled('system', 'zooseo')) {
            return \JRoute::link('site', $raw_link);
        }

        $params = json_decode($plugin->params);

        if ($params->remove_category != '1') {
            return \JRoute::link('site', $raw_link);
        }

        return str_replace('/category/', '/', \JRoute::link('site', $raw_link));

    }

    private function getCategoryUrl(\Category $category, $lang)
    {
        $urls = [];
        // Priority 1: direct link to item
        if ($menu_item = $this->findMenuItem('category', $category->id, $lang)) {
            return $this->formatCategoryUrl($menu_item->link . '&Itemid=' . $menu_item->id);
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

        return $this->formatCategoryUrl($link . '&Itemid=' . $itemid);
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
            'id'    => $category->id,
            'name'  => [],
            'url'   => [],
            'image' => $image ? '/' . $image : null
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

    public function array_flatten(array $array): array
    {
        $return = [];

        array_walk_recursive($array, function ($x) use (&$return) {
            $return[] = $x;
        });

        return $return;
    }

    private function loadRouterLanguageRules(): void
    {
        $this->app = CMSApplication::getInstance('site');
        $this->mode_sef = $this->app->get('sef', 0);
        $this->lang_codes = LanguageHelper::getLanguages('lang_code');
        $this->default_lang = ComponentHelper::getParams('com_languages')->get('site', 'en-GB');
        $this->current_lang = isset($this->lang_codes[$this->default_lang]) ? $this->default_lang : 'en-GB';

        $this->params = (new Registry(PluginHelper::getPlugin('system', 'languagefilter')->params));

        // We need to make sure we are always using the site router, even if the language plugin is executed in admin app.
        $router = $this->app->getRouter('site');

        // Attach build rules for language SEF.
        $router->attachBuildRule([
            $this,
            'preprocessBuildRule'
        ], Router::PROCESS_BEFORE);

        $router->attachBuildRule([
            $this,
            'buildRule'
        ], Router::PROCESS_BEFORE);
        $router->attachBuildRule([
            $this,
            'postprocessSEFBuildRule'
        ], Router::PROCESS_AFTER);

        // Attach parse rule.
        $router->attachParseRule(array(
            $this,
            'parseRule'
        ), Router::PROCESS_BEFORE);
    }

    /**
     * Add build preprocess rule to router.
     *
     * @param Router  &$router Router object.
     * @param Uri     &$uri Uri object.
     *
     * @return  void
     *
     * @since   3.4
     */
    public function preprocessBuildRule(&$router, &$uri)
    {
        $lang = $uri->getVar('lang', $this->current_lang);

        if (isset($this->sefs[$lang])) {
            $lang = $this->sefs[$lang]->lang_code;
        }

        $uri->setVar('lang', $lang);
    }

    /**
     * Add build rule to router.
     *
     * @param Router  &$router Router object.
     * @param Uri     &$uri Uri object.
     *
     * @return  void
     *
     * @since   1.6
     */
    public function buildRule(&$router, &$uri)
    {
        $lang = $uri->getVar('lang');

        if (isset($this->lang_codes[$lang])) {
            $sef = $this->lang_codes[$lang]->sef;
        } else {
            $sef = $this->lang_codes[$this->current_lang]->sef;
        }

        if (!$this->params->get('remove_default_prefix', 0)
            || $lang !== $this->default_lang
            || $lang !== $this->current_lang) {
            $uri->setPath($uri->getPath() . '/' . $sef . '/');
        }
    }

    /**
     * postprocess build rule for SEF URLs
     *
     * @param Router  &$router Router object.
     * @param Uri     &$uri Uri object.
     *
     * @return  void
     *
     * @since   3.4
     */
    public function postprocessSEFBuildRule(&$router, &$uri)
    {
        $uri->delVar('lang');
    }

    /**
     * Add parse rule to router.
     *
     * @param Router  &$router Router object.
     * @param Uri     &$uri Uri object.
     *
     * @since   1.6
     */
    public function parseRule(&$router, &$uri)
    {
        // Did we find the current and existing language yet?
        $found = false;

        // Are we in SEF mode or not?
        if ($this->mode_sef) {
            $path = $uri->getPath();
            $parts = explode('/', $path);

            $sef = StringHelper::strtolower($parts[0]);

            // Do we have a URL Language Code ?
            if (!isset($this->sefs[$sef])) {
                // Check if remove default URL language code is set
                if ($this->params->get('remove_default_prefix', 0)) {
                    if ($parts[0]) {
                        // We load a default site language page
                        $lang_code = $this->default_lang;
                    } else {
                        // We check for an existing language cookie
                        $lang_code = $this->getLanguageCookie();
                    }
                } else {
                    $lang_code = $this->getLanguageCookie();
                }

                // No language code. Try using browser settings or default site language
                if (!$lang_code && $this->params->get('detect_browser', 0) == 1) {
                    $lang_code = LanguageHelper::detectLanguage();
                }

                if (!$lang_code) {
                    $lang_code = $this->default_lang;
                }

                if ($lang_code === $this->default_lang && $this->params->get('remove_default_prefix', 0)) {
                    $found = true;
                }
            } else {
                // We found our language
                $found = true;
                $lang_code = $this->sefs[$sef]->lang_code;

                // If we found our language, but it's the default language and we don't want a prefix for that, we are on a wrong URL.
                // Or we try to change the language back to the default language. We need a redirect to the proper URL for the default language.
                if ($lang_code === $this->default_lang && $this->params->get('remove_default_prefix', 0)) {
                    // Create a cookie.
                    $this->setLanguageCookie($lang_code);

                    $found = false;
                    array_shift($parts);
                    $path = implode('/', $parts);
                }

                // We have found our language and the first part of our URL is the language prefix
                if ($found) {
                    array_shift($parts);

                    // Empty parts array when "index.php" is the only part left.
                    if (count($parts) === 1 && $parts[0] === 'index.php') {
                        $parts = array();
                    }

                    $uri->setPath(implode('/', $parts));
                }
            }
        } // We are not in SEF mode
        else {
            $lang_code = $this->getLanguageCookie();

            if (!$lang_code && $this->params->get('detect_browser', 1)) {
                $lang_code = LanguageHelper::detectLanguage();
            }

            if (!isset($this->lang_codes[$lang_code])) {
                $lang_code = $this->default_lang;
            }
        }

        $lang = $uri->getVar('lang', $lang_code);

        if (isset($this->sefs[$lang])) {
            // We found our language
            $found = true;
            $lang_code = $this->sefs[$lang]->lang_code;
        }

        // We are called via POST or the nolangfilter url parameter was set. We don't care about the language
        // and simply set the default language as our current language.
        if ($this->app->input->getMethod() === 'POST'
            || $this->app->input->get('nolangfilter', 0) == 1
            || count($this->app->input->post) > 0
            || count($this->app->input->files) > 0) {
            $found = true;

            if (!isset($lang_code)) {
                $lang_code = $this->getLanguageCookie();
            }

            if (!$lang_code && $this->params->get('detect_browser', 1)) {
                $lang_code = LanguageHelper::detectLanguage();
            }

            if (!isset($this->lang_codes[$lang_code])) {
                $lang_code = $this->default_lang;
            }
        }

        // We have not found the language and thus need to redirect
        if (!$found) {
            // Lets find the default language for this user
            if (!isset($lang_code) || !isset($this->lang_codes[$lang_code])) {
                $lang_code = false;

                if ($this->params->get('detect_browser', 1)) {
                    $lang_code = LanguageHelper::detectLanguage();

                    if (!isset($this->lang_codes[$lang_code])) {
                        $lang_code = false;
                    }
                }

                if (!$lang_code) {
                    $lang_code = $this->default_lang;
                }
            }

            if ($this->mode_sef) {
                // Use the current language sef or the default one.
                if ($lang_code !== $this->default_lang
                    || !$this->params->get('remove_default_prefix', 0)) {
                    $path = $this->lang_codes[$lang_code]->sef . '/' . $path;
                }

                $uri->setPath($path);

                if (!$this->app->get('sef_rewrite')) {
                    $uri->setPath('index.php/' . $uri->getPath());
                }

                $redirectUri = $uri->base() . $uri->toString(array(
                        'path',
                        'query',
                        'fragment'
                    ));
            } else {
                $uri->setVar('lang', $this->lang_codes[$lang_code]->sef);
                $redirectUri = $uri->base() . 'index.php?' . $uri->getQuery();
            }

            // Set redirect HTTP code to "302 Found".
            $redirectHttpCode = 302;

            // If selected language is the default language redirect code is "301 Moved Permanently".
            if ($lang_code === $this->default_lang) {
                $redirectHttpCode = 301;

                // We cannot cache this redirect in browser. 301 is cacheable by default so we need to force to not cache it in browsers.
                $this->app->setHeader('Expires', 'Wed, 17 Aug 2005 00:00:00 GMT', true);
                $this->app->setHeader('Last-Modified', gmdate('D, d M Y H:i:s') . ' GMT', true);
                $this->app->setHeader('Cache-Control', 'no-store, no-cache, must-revalidate', false);
                $this->app->sendHeaders();
            }

            // Redirect to language.
            $this->app->redirect($redirectUri, $redirectHttpCode);
        }

        // We have found our language and now need to set the cookie and the language value in our system
        $array = array('lang' => $lang_code);
        $this->current_lang = $lang_code;

        // Set the request var.
        $this->app->input->set('language', $lang_code);
        $this->app->set('language', $lang_code);
        $language = $this->app->getLanguage();

        if ($language->getTag() !== $lang_code) {
            $language_new = Language::getInstance($lang_code, (bool)$this->app->get('debug_lang'));

            foreach ($language->getPaths() as $extension => $files) {
                if (strpos($extension, 'plg_system') !== false) {
                    $extension_name = substr($extension, 11);

                    $language_new->load($extension, JPATH_ADMINISTRATOR)
                    || $language_new->load($extension, JPATH_PLUGINS . '/system/' . $extension_name);

                    continue;
                }

                $language_new->load($extension);
            }

            Factory::$language = $language_new;
            $this->app->loadLanguage($language_new);
        }

        return $array;
    }
}
