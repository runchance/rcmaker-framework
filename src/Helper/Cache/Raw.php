<?php
namespace RC\Helper\Cache;
use RC\Bootstrap;
use RC\Config;
use RC\Request;
use RC\Helper\Cache\think\facade\Cache;
class Raw implements Bootstrap{
	public static $_version = '2.1.3';
	private static $_config = null;
	public static $support = ['File','Memcache','Memcached','Redis','Wincache'];
	public static function start()
    {
        static::$_config = static::$_config ?? Config::get('cache') ?? [];
    	if(!static::$_config){
    		return;
    	}
        $config = static::$_config;
        \cache('raw', '', 1, Cache::class, $config);
    }
}
?>