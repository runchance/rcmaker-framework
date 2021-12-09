<?php
namespace RC\Helper\View;
use RC\View;
use RC\Config;
use RC\Helper\Template\think\Template;



/**
 * Class Raw
 * @package support\view
 */
class ThinkPHP implements View
{
    /**
     * @var array
     */
    protected static $_vars = [];
    protected static $_view = null;
    public static $_version = '2.0.8';
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
        static $view;
        $view_path = \view_path() . "/$app/";
        $default_options = [
            'view_path'   => $view_path,
            'cache_path'  => \runtime_path() . '/views/think/',
            'view_suffix' => Config::get('view','suffix') ?? 'html'
        ];
        $options = $default_options + (Config::get('view','options') ?? []);
        if(!$view){
           $view =  new Template($options);
        }
        \ob_start();

        $vars = \array_merge(static::$_vars, $vars);
        $view->fetch($template, $vars);
        $content = \ob_get_clean();
        static::$_vars = [];
        return $content;
    }

}
