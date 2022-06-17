<?php
/**
 * @package   com_zoo
 * @author    YOOtheme https://yootheme.com
 * @copyright Copyright (C) YOOtheme GmbH
 * @license   https://www.gnu.org/licenses/gpl.html GNU/GPL
 */

use Joomla\CMS\Application\CMSApplication;
use Joomla\CMS\Menu\MenuItem;
use Joomla\CMS\Menu\AbstractMenu;

/**
 * Menu helper class.
 *
 * @package Component.Helpers
 * @since 2.0
 */
class MenuHelper extends AppHelper {

    /**
     * The menus
     * @var AppMenu[]
     */
    protected static $_menus = array();

    /**
     * The active site menu item
     * @var MenuItem
     */
    protected $_active;

    /**
     * Class constructor
     *
     * @param string $app App instance.
     * @since 2.0
     */
    public function __construct($app) {
        parent::__construct($app);

        // load class
        $this->app->loader->register('AppTree', 'classes:tree.php');
        $this->app->loader->register('AppMenu', 'classes:menu.php');
    }

    /**
     * Gets the AppMenu instance
     *
     * @param string $name Menu name
     * @return AppMenu
     * @since 2.0
     */
    public function get($name) {

        if (isset(self::$_menus[$name])) {
            return self::$_menus[$name];
        }

        self::$_menus[$name] = $this->app->object->create('AppMenu', array($name));

        return self::$_menus[$name];
    }

    /**
     * Gets the active site menu
     */
    public function getActive() {
        if ($this->_active === null) {
            $app = CMSApplication::getInstance('site');

            if ($menu = $app->getMenu('site') and $menu instanceof AbstractMenu) {
                $this->_active = $menu->getActive();
            }
        }
        return $this->_active;
    }

}
