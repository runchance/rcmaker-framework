<?php
namespace RC\Helper\Redis;
use RC\Bootstrap;
use RC\Config;
use RC\Request;
class Raw implements Bootstrap{
	public static $_version = '1.0.0';
	private static $_config = null;
	private static $default_config =[
		'type'=>'common',
		'host'=>'127.0.0.1',
		'port'=>6379,
		'password'=>null,
		'database'=>0,
		'timeout'=>5,
		'retryInterval'=>0,
		'readTimeout'=>-1,
	];

	private static function creat_config($config){
		$_config = [];
		if(!$config){
			return false;
		}
		if(isset($config['default_frame'])){
			unset($config['default_frame']);
		}
		foreach($config as $driver=>$conf){	
			if(isset($_config['connections'][$driver])){
				continue;
			}
			$_config['connections'][$driver] = static::$default_config;
			foreach($conf as $key=>$val){
				if(array_key_exists($key,$_config['connections'][$driver])){
					$_config['connections'][$driver][$key] = $conf[$key] ?? $_config['connections'][$driver][$key];
				}

			}
		}
		return $_config;
	}
	public static function start()
    {
    	static::$_config = static::$_config ?? static::creat_config(Config::get('redis') ?? []);
    	if(!static::$_config){
    		return;
    	}
        $config = static::$_config;
        \redis('raw', '', 1, \Redis::class, \RedisCluster::class, $config);
    }
}
?>