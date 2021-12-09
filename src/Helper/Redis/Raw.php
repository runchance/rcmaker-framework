<?php
namespace RC\Helper\Redis;
use RC\Bootstrap;
use RC\Config;
use RC\Request;
use RC\Helper\Redis\mix\Redis;
use RC\Helper\Redis\mix\Driver;
use RC\Helper\Redis\MixCluster;
use RC\Helper\Redis\MixClusterDriver;
class Raw implements Bootstrap{
	public static $_version = '3.0.7';
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
        \redis('raw', '', 1, Redis::class, MixCluster::class, $config);
    }
}

Class MixClusterDriver extends Driver{
	public function __construct($config){
		$timeout = $config['timeout'] ?? 2;
        $read_timeout = $config['read_timeout'] ?? $timeout;
        $persistent = $config['persistent'] ?? false;
        $password = $config['password'] ?? '';
        $args = [null, $config['host'], $timeout, $read_timeout, $persistent];
        if ($password) {
            $args[] = $password;
        }
       	
        $this->redis = new \RedisCluster(...$args);
        if (empty($config['prefix'])) {
            $config['prefix'] = 'rcmaker_';
        }
        $this->redis->setOption(\Redis::OPT_PREFIX, $config['prefix']);
		
	}
}

Class MixCluster extends Redis{
	public function __construct($config)
    {
        $this->driver = new MixClusterDriver($config);
    }
}
?>