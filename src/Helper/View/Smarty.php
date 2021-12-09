<?php
namespace RC\Helper\View;
use RC\View;
use RC\Config;
use RC\Container;
use RC\Helper\Template\Smarty\Smarty as SmartyClass;

/**
 * Class Raw
 * @package support\view
 */
class Smarty implements View
{
    use Container;
    /**
     * @var array
     */
    protected static $_vars = [];
    public static $_version = '3.1.39';

    
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
        static $views;
        $suffix = $suffix ? : Config::get('view','suffix') ?? 'html';
        $view_path = \view_path() . "/$app/";

        $config = Config::get('view','options');
        $default_options = [
            'cache_dir' => \runtime_path() . '/views/smarty/cache',
            'compile_dir' => \runtime_path() . '/views/smarty/compile',
            'template_dir' => $view_path,
        ];

        $options = $default_options + ($config ?? []);
        $smartyclass = FRAME_PATH . '/helper/template/smarty/Smarty.class.php';
        
        if(!$views){
            if(!Container::loadClass($smartyclass,'Smarty')){
                throw new \Exception("Class '$smartyclass' not found");
            }
            $views =  Container::get('Smarty');
        }
        
        foreach($options as $key=>$op){
           $views->{$key} = $op;
        }
        $vars = \array_merge(static::$_vars, $vars);
        if(isset($vars))
        {
            foreach($vars as $k=>$v)
            {
                $views->assign($k,$v);
            }
        }
        \ob_start();
        $views->display($template.'.'.$suffix);
        $content = \ob_get_clean();
        static::$_vars = [];
        return $content;
    }

}
