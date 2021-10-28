<?php

use Joomla\CMS\Factory;

defined('_JEXEC') or die('Restricted access');

class plgSystemZooalgoliaInstallerScript
{
    public const MIN_PHP = '7.4';

    public function preflight($type, $parent)
    {
        $app = Factory::getApplication();
        $msg = null;

        if (!in_array($type, ['install', 'update'])) {
            return;
        }

        // check minimum PHP version
        if (!version_compare(PHP_VERSION, self::MIN_PHP, 'ge')) {
            $msg = sprintf('You need PHP %s or later to install this extension.', self::minPHP);
        }

        if ($msg) {
            $app->enqueueMessage($msg, 'warning');

            return false;
        }
    }

    function install($parent)
    {
        // $db = \JFactory::getDBO();
    }
}
