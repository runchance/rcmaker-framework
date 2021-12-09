<?php
namespace RC\Helper\View;
use RC\View;
use RC\Config;


/**
 * Class Raw
 * @package support\view
 */
class Raw implements View
{
    /**
     * @var array
     */
    protected static $_vars = [];
    public static $_version = '0.1';

    /**
     * @param $name
     * @param null $value
     */
    public static function assign($name, $value = null)
    {
        static::$_vars = \array_merge(static::$_vars, \is_array($name) ? $name : [$name => $value]);
    }

    /**
     * @param $template
     * @param $vars
     * @param null $app
     * @return string
     */
    public static function render($template, $vars, $app = 'default')
    {
        static $suffix;
        $suffix = $suffix ? : Config::get('view','suffix') ?? 'html';
        $view_path = \view_path() . "/$app/$template.$suffix";
        \extract(static::$_vars);
        \extract($vars);
        \ob_start();
        // Try to include php file.
        include $view_path;
        static::$_vars = [];
        return \ob_get_clean();
    }

}
