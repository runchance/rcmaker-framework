<?php
namespace RC;
use RC\FileOperator as file;
define('ENV_PATH', phar_path().'/.env');
define('CONFIG_PATH', BASE_PATH.'/config');
final class Config{
	use file;
	private static $_c = [];
	private static $_envData = [];
	public static function getEnv($name = null, $default = null){
		if(is_file(ENV_PATH)){
			static::$_c['__env__'] = static::$_c['__env__'] ?? parse_ini_file(ENV_PATH, true) ?: [];
		}

		if(!static::$_envData){
			static::setEnv(static::$_c['__env__'] ?? []);
		}
		$name = strtoupper(str_replace('.', '_', $name));

        if (isset(static::$_envData[$name])) {

        	if(is_string(static::$_envData[$name]) && is_object(json_decode(static::$_envData[$name]))){
        		return json_decode(static::$_envData[$name]);
        	}
            return static::$_envData[$name];
        }else{
        	$explode = explode('_',$name);
			$end = end($explode);
			array_pop($explode);
			$array_name = implode('_',$explode);
			if(isset(static::$_envData[$array_name][$end])){
				return static::$_envData[$array_name][$end];
			}
        }

        return static::getSystemEnv($name, $default);
	}

	

	protected static function getSystemEnv(string $name, $default = null)
    {
        $result = getenv('PHP_' . $name);

        if (false === $result) {
            return $default;
        }

        if ('false' === $result) {
            $result = false;
        } elseif ('true' === $result) {
            $result = true;
        }

        if (!isset(static::$_envData[$name])) {
            static::$_envData[$name] = $result;
        }

        return $result;
    }


	public static function setEnv($env, $value = null){
		if (is_array($env)) {
            $env = array_change_key_case($env, CASE_UPPER);

            foreach ($env as $key => $val) {
                if (is_array($val)) {
                    foreach ($val as $k => $v) {
                        static::$_envData[$key . '_' . strtoupper($k)] = $v;
                    }
                } else {
                    static::$_envData[$key] = $val;
                }
            }
        } else {
            $name = strtoupper(str_replace('.', '_', $env));

            static::$_envData[$name] = $value;
        }
	}


	public static function get($file=null,$key=null,$force=null,$default=null){ 
		if($file==null){
			return false;
		}
		if(!$force){
			if($key==null){
				if(isset(static::$_c[$file])){
					return static::$_c[$file];
				}
			}else{
				if(isset(static::$_c[$file][$key])){
					return static::$_c[$file][$key];
				}else{
					if($default){
						return $default;
					}
				}
			}
		}
		if($force===true){
			if(is_file(ENV_PATH)){
				static::$_c['__env__'] = parse_ini_file(ENV_PATH, true) ?: [];
			}
			
			static::setEnv(static::$_c['__env__'] ?? []);
		}
		$config_file = static::checkfile($file);
		if(!$config_file){
			return false;
		}
		static::$_c[$file] = $force ? include($config_file) : (static::$_c[$file] ?? include($config_file));
		if($key==null){
			return static::$_c[$file];
		}else{
			return static::$_c[$file][$key] ?? $default;
		}
	}
	public static function getAll($force=null,$exclude=[]){
		foreach (glob(CONFIG_PATH . '/*.php') as $file) {
            $basename = basename($file, '.php');
            if (in_array($basename, $exclude)) {
                continue;
            }
            static::$_c[$basename] = $force ? include($file) : (static::$_c[$basename] ?? include($file));
        }
        return static::$_c;
	}
	public static function set($file=null,$key=null,$value=null){
		if($file==null || $key==null){
			return false;
		}
		$config_file = static::checkfile($file);
		if(!$config_file){
			$config_file = CONFIG_PATH.'/'.$file.'.php';
			file::write($config_file,"<?php\nreturn ".var_export(array(),true).";\n?>");
		}
		static::$_c[$file] = static::$_c[$file] ?? include($config_file);
		if(isset(static::$_c[$file][$key]) && static::$_c[$file][$key]==$value){
			return false;
		}
		static::$_c[$file][$key] = $value;
		file::write($config_file,"<?php \nreturn ".var_export(static::$_c[$file],true).";\n?>");
	}
	private static function checkfile($file){
		$config_file = CONFIG_PATH.'/'.$file.'.php';
		if(!is_file($config_file)){
			return false;
		}
		return $config_file;
	}
}
?>