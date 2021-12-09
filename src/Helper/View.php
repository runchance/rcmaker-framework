<?php
namespace RC\Helper;
use RC\Config;
class View{
	public static function assign($name, $value = null)
    {
        static $handler;
        $handler = $handler ? : Config::get('view','handler');
        $handler::assign($name, $value);
    }
}
?>