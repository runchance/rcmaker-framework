<?php
namespace RC\Helper\Db;
use RC\Bootstrap;
use RC\Config;
use RC\Request;
use RC\Helper\Db\Medoo\Medoo as Db;
class Medoo implements Bootstrap{
	/**
     * @param Worker $worker
     *
     * @return void
     */
	public static $_version = '2.1.3';
	public static $support = ['mysql','sqlite','pgsql','sqlsrv','oracle','sybase'];
	private static $_config = null;

	private static $map = [
		'host' => 'server',
		'database' => 'database_name'
	];
	private static $default_config =[
		'database_type'=>null,
		'database_name'=>null,
		'database_file'=>null,
		'server'=>'localhost',
		'username'=>null,
		'password'=>null,
		'charset'=>null,
		'port'=>null,
		'prefix'=>null,
		'logging'=>false,
		'socket'=>null,
		'option'=>null,
		'command'=>null,
		'dsn'=>null
	];

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
			$map = static::$map;

			foreach($config['driver'] as $driver=>$configs){
				
				if(isset($_config['connections'][$driver])){
					continue;
				}
				$_config['connections'][$driver] = static::$default_config;
				$_config['connections'][$driver]['database_type'] = $driver;
				if($driver=='sqlsrv'){
					$_config['connections'][$driver]['database_type'] = 'mssql';
				}
				$config_org = [];
				foreach($configs as $key=>$val){
					if($key!=='options'){
						$config_org[$key] = $val;
					}else{
						$config_org = array_merge($config_org,$val);
					}
					if(array_key_exists(($map[$key] ?? null),$_config['connections'][$driver])===true){
						$_config['connections'][$driver][$map[$key]] = $val ?? $_config['connections'][$driver][$map[$key]];
					}
				}

				foreach($config_org as $k=>$conf){
					if(array_key_exists($k,$_config['connections'][$driver]))
					$_config['connections'][$driver][$k] = $config_org[$k] ?? $_config['connections'][$driver][$k];
				}
					
			}
			return $_config;
			
		}

	}

    public static function start()
    {
    	static::$_config = static::$_config ?? static::creat_config(Config::get('db') ?? []);
    	if(!static::$_config){
    		return;
    	}

        $config = static::$_config;
        \database('medoo','',1,Db::class,$config,static::$support);
    }
}
?>