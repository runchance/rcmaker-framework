<?php
namespace RC\Helper\Db;
use RC\Bootstrap;
use RC\Config;
use RC\Request;
use RC\Helper\Db\think\facade\Db;
class Think implements Bootstrap{
	/**
     * @param Worker $worker
     *
     * @return void
     */
	public static $dbs = [];
	public static $_version = '2.0.44';
	public static $support = ['mysql','sqlite','pgsql','sqlsrv','mongodb','oracle'];
	private static $_config = null;

	private static $map = [
		'host' => 'hostname',
		'port' => 'hostport',
	];

	private static $default_config =[
		'type'=>null,
		'hostname'=>'127.0.0.1',
		'database'=>null,
		'username'=>null,
		'password'=>null,
		'hostport'=>null,
		'dsn'=>null,
		'params'=>null,
		'params'=>'',
		'charset'=>'utf8',
		'prefix'=>null,
		'deploy'=>0,
		'rw_separate'=>false,
		'master_num'=>1,
		'slave_no'=>null,
		'fields_strict'=>true,
		'auto_timestamp'=>false,
		'break_reconnect'=>false,
		'fields_cache'=>false,
		'schema_cache_path'=>null,
		'trigger_sql'=>true,
		'query'=>null
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
				if($driver=='mongodb'){
					$configs['type'] = 'mongo';
				}		
				if(isset($_config['connections'][$driver])){
					continue;
				}
				$_config['connections'][$driver] = static::$default_config;
				$_config['connections'][$driver]['type'] = $driver;
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

        \database('think','',1,Db::class,$config,static::$support);
    }
}
?>