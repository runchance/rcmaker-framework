<?php
namespace RC\Helper\View;
use RC\View;
use RC\Config;
use Twig\Loader\FilesystemLoader;
use Twig\Environment;

/**
 * Class Raw
 * @package support\view
 */
class Twig implements View
{
    /**
     * @var array
     */
    protected static $_vars = [];
    public static $_version = '';

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
        static $views = [], $suffix;
        $suffix = $suffix ? : Config::get('view','suffix') ?? 'html';
        $config = Config::get('view','options') ?? [];
        if (!isset($views[$app])) {
            $view_path = \view_path() . "/$app/";
            $views[$app] = new Environment(new FilesystemLoader($view_path), $config);
        }
        $view_path = \view_path() . "/$app/$template.$suffix";
        $vars = \array_merge(static::$_vars, $vars);
        $content = $views[$app]->render("$template.$suffix", $vars);
        static::$_vars = [];
        return $content;
    }

}
