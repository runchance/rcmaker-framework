<?php
namespace RC\Helper\Db;
use RC\Bootstrap;
use RC\Config;
use RC\Request;
use Illuminate\Database\Capsule\Manager as LaravelDb;
use Illuminate\Events\Dispatcher;
use Illuminate\Container\Container;
use Jenssegers\Mongodb\Connection;
class Laravel implements Bootstrap{
	/**
     * @param Worker $worker
     *
     * @return void
     */
	public static $dbs = [];
	public static $_version = '';
	public static $support = ['mysql','sqlite','pgsql','sqlsrv','oracle','mongodb'];
	private static $_config = null;


	private static function creat_config($config){
		$_config = [];
		if(!$config){
			return false;
		}
		if($config){
			$default = $config['default'];
			if(!isset($config['driver'][$default])){
				return false;
			}else{
				$_config['default'] = $default;
			}

			foreach($config['driver'] as $driver=>$configs){		
				if(isset($_config['connections'][$driver])){
					continue;
				}
				$_config['connections'][$driver] = $configs;
				$_config['connections'][$driver]['driver'] = $driver;
					
			}
			return $_config;
			
		}

	}

    public static function start()
    {
    	if (!class_exists('\Illuminate\Database\Capsule\Manager')) {
            return;
        }
    	static::$_config = static::$_config ?? static::creat_config(Config::get('db') ?? []);
    	if(!static::$_config){
    		return;
    	}
    	$config = static::$_config;
        \database('laravel','',1,LaravelDb::class,$config,static::$support);
    }
}
?>