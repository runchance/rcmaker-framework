<?php
	namespace RC;
	use RC\Config;
	use RC\Model;
	use RC\View;
	use RC\Count;
	use RC\Container;
	use RC\Controller;
	use RC\Middleware;
	use RC\Request;
	use RC\Response;
	use RC\Route;
	use RC\Worker;
	class Rcmaker{
		public static function start(){
			static $requests;
			if(defined('IS_SCRIPT')){
			    $id = 999999;
		        $requests[$id] = $requests[$id] ?? new Request($id);
			    foreach ((Config::get('autoload') ?? []) as $file) {
			        include_once $file;
			    }
			    foreach ((Config::get('bootstrap') ?? []) as $class_name) {
					$class_name::start($requests[$id]);
				}
				return null;
			}
			if(IS_CLI){
				Worker::load();
				return null;
			}
			
			if(!RUN_PATH){
				return null;
			}
			\error_reporting(Config::get('app','error_types') ?? E_ALL &~E_NOTICE &~E_STRICT &~E_DEPRECATED);
			\set_error_handler(function ($level, $message, $file = '', $line = 0) {
		        if (\error_reporting(Config::get('app','error_types') ?? E_ALL &~E_NOTICE &~E_STRICT &~E_DEPRECATED) & $level) {
		            throw new \ErrorException($message, 0, $level, $file, $line);
		        }
		    });
		    $id = 999999;
		    $requests[$id] = $requests[$id] ?? new Request($id);
		    $responses[$id] = $responses[$id] ?? new Response($id);
			try {
				foreach ((Config::get('autoload') ?? []) as $file) {
			        include_once $file;
			    }
			    foreach ((Config::get('bootstrap') ?? []) as $class_name) {
					$class_name::start($requests[$id]);
				}
				Middleware::load(Config::get('middleware',null,true) ?? []);
				if(Config::get('app','with_custom_route')===true){
					Route::init();
				}
				if ($timezone = Config::get('app','default_timezone')) {
				    \date_default_timezone_set($timezone);
				}
				if(Config::get('app','count')===true){
					Stopwatch::$_framework = stopwatch('__frame__');
				}

				Controller::call($requests[$id],$responses[$id]);

			} catch (\Throwable $e) {
	        	$render = Controller::exceptionResponse($e,$requests[$id],$responses[$id]);
	        	if(is_array($render)){
	        		list($code,$headers,$message) = $render;
	        		$responses[$id]->bad($requests[$id],$code,$message);
	        	}else{
	        		$responses[$id]->bad($requests[$id],500,$e->getMessage());
	        	}
	        }
		}

	}
?>