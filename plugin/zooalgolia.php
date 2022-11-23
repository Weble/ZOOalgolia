<?php

defined('_JEXEC') or die('Restricted access');

require_once __DIR__ . '/vendor/autoload.php';

use Joomla\CMS\Factory;
use Weble\ZOOAlgolia\AlgoliaSync;
use Joomla\Application\ApplicationEvents;
use Weble\ZOOAlgolia\AlgoliaSyncCommandJ4;

class plgSystemZooAlgolia extends Joomla\CMS\Plugin\CMSPlugin
{
    protected $app;

    public function __construct(&$subject, $config = [])
    {
        parent::__construct($subject, $config);

        if (!$this->app->isClient('cli'))
        {
            return;
        }

        $this->registerAlgoliaSyncCommand();
    }

    public static function getSubscribedEvents(): array
    {
        if ($this->app->isClient('cli'))
        {
            return [
                ApplicationEvents::BEFORE_EXECUTE => 'registerAlgoliaSyncCommand',
            ];
        }
    }

    public function registerAlgoliaSyncCommand()
    {
        $this->app->addCommand(new AlgoliaSyncCommandJ4());
    }

    /**
     * onAfterInitialise handler
     */
    public function onAfterInitialise()
    {
        $this->init();
    }

    protected function init()
    {
        if (file_exists(JPATH_ADMINISTRATOR."/components/com_zoo/config.php")) {
            require_once JPATH_ADMINISTRATOR."/components/com_zoo/config.php";
        }

        if (file_exists(JPATH_ROOT . '/plugins/system/zlframework/config.php')) {
            require_once JPATH_ROOT . '/plugins/system/zlframework/config.php';
        }

        /** @var App $zoo */
        $zoo = App::getInstance('zoo');

        // register plugin path
        if ($path = $zoo->path->path('root:plugins/system/zooalgolia')) {
            $zoo->path->register($path, 'zooalgolia');
        }

        $zoo->event->dispatcher->connect('layout:init', [
            $this,
            'initTypeLayouts'
        ]);

        $zoo->event->dispatcher->connect('item:deleted', [
            $this,
            'removeItems'
        ]);
        $zoo->event->dispatcher->connect('item:saved', [
            $this,
            'sync'
        ]);

        // only if not submission
        if (!strstr(Factory::getApplication()->input->getCmd('controller'), 'submission')) {
            $zoo->event->dispatcher->connect('application:init', array(
                $this,
                'applicationAlgoliaConfiguration'
            ));
        }
    }

    public function applicationAlgoliaConfiguration(AppEvent $event)
    {
        /** @var Application $app */
        $app = $event->getSubject();

        // Call the helper method
        $file = __DIR__ . '/config/application.xml';

        $this->addApplicationParams($app, $file);
    }

    public function sync(AppEvent $event)
    {
        /** @var \Item $item */
        $item = $event->getSubject();

        $algoliaSync = new AlgoliaSync($item->getType());
        $algoliaSync->sync($item);
    }

    public function removeItems(AppEvent $event)
    {
        /** @var \Item $item */
        $item = $event->getSubject();

        $algoliaSync = new AlgoliaSync($item->getType());
        $algoliaSync->batchDelete([$event->getSubject()->id]);
    }

    public function initTypeLayouts(AppEvent $event)
    {
        $zoo = App::getInstance('zoo');
        $extensions = (array)$event->getReturnValue();

        // clean all previous layout references
        $newExtensions = array();
        foreach ($extensions as $ext) {
            if (stripos($ext['name'], 'zooalgolia') === false) {
                $newExtensions[] = $ext;
            }
        }

        // add new ones
        $newExtensions[] = [
            'name' => 'Algolia',
            'path' => $zoo->path->path('zooalgolia:'),
            'type' => 'plugin'
        ];

        $event->setReturnValue($newExtensions);
    }

    private function addApplicationParams(Application $app, string $file)
    {
        // Custom XML File
        $xml = simplexml_load_file($file);


        // Appication XML file
        $old_file = $app->app->path->path($app->getResource() . $app->metaxml_file);
        $old_xml = simplexml_load_file($old_file);


        // Application is right?
        if (!isset($xml->application)) {
            return;
        }

        if (!$this->generateNewXmlFile($old_xml, $xml, $app)) {
            return;
        }

        // Save the new file and set it as the default one
        $new_file = $app->app->path->path($app->getResource()) . '/' . \Joomla\Filesystem\File::stripExt($app->metaxml_file) . '_zooalgolia.xml';

        // Save the new version
        $data = $old_xml->asXML();
        \Joomla\Filesystem\File::write($new_file, $data);

        // set it as the default one
        $app->metaxml_file = \Joomla\Filesystem\File::stripExt($app->metaxml_file) . '_zooalgolia.xml';
    }

    private function appendChild(\SimpleXMLElement $parent, \SimpleXMLElement $child): void
    {
        // use dom for this kind of things
        $domparent = dom_import_simplexml($parent);
        $domchild = dom_import_simplexml($child);

        // Import
        $domchild = $domparent->ownerDocument->importNode($domchild, true);

        // Append
        $domparent->appendChild($domchild);
    }

    private function generateNewXmlFile(\SimpleXMLElement $old_xml, \SimpleXMLElement $xml, Application $app): bool
    {
        $app_file_changed = false;

        foreach ($xml->application as $a) {
            // Check the parameter group
            $group = (string)$a->attributes()->group ? (string)$a->attributes()->group : 'all';
            if ($group !== 'all' && $group !== $app->application_group) {
                continue;
            }

            if (!isset($a->params)) {
                continue;
            }

            foreach ($a->params as $param) {
                // Second level grouping
                $group = (string)$param->attributes()->group ? (string)$param->attributes()->group : '_default';
                $new_params = new \SimpleXMLElement('<params></params>');
                $new_params->addAttribute('group', $group);

                if (!@$old_xml->params) {
                    continue;
                }

                $param_added = false;
                // Merge with already existing param groups
                foreach ($old_xml->params as $ops) {

                    if ((string)$ops->attributes()->group !== $group) {
                        continue;
                    }

                    $param_added = true;

                    // Check for addPath
                    if (($a->params->attributes()->addpath != '') && !($old_xml->params->attributes()->addpath)) {
                        @$ops->addAttribute('addpath', $a->params->attributes()->addpath);
                        $app_file_changed = true;
                    }

                    // Add the parameters for this group
                    foreach ($param->param as $p) {

                        // If it doesn't exists already
                        if (!count($ops->xpath("param[@name='" . $p->attributes()->name . "']"))) {

                            // Push changes with default
                            $p->attributes()->default = $this->params->get($p->attributes()->name, $p->attributes()->default);
                            $this->appendChild($ops, $p);
                            $app_file_changed = true;
                        }

                    }

                    foreach ($app->getTypes() as $type) {
                        if (!count($ops->xpath("param[@name='algolia_index_" . $type->identifier . "']"))) {

                            $p = new SimpleXMLElement('<param></param>');
                            $p->addAttribute('name', 'algolia_index_' . $type->identifier);
                            $p->addAttribute('type', 'text');
                            $p->addAttribute('label', 'Algolia Index for '. $type->name);
                            $p->addAttribute('description', '');
                            $p->addAttribute('default', '');

                            $this->appendChild($ops, $p);
                            $app_file_changed = true;
                        }
                    }
                }

                // Create a new param group if necessary
                if (!$param_added) {
                    $this->appendChild($old_xml, $param);
                    $app_file_changed = true;
                }
            }
        }

        return $app_file_changed;
    }
}
