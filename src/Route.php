<?php
namespace RC;
use RC\Config;
use FastRoute\Dispatcher;
use FastRoute\RouteCollector;
class Route{
	private static $_disp = null;
	private static $_route = null;
	private static $_hasRoute = false;
    private static $_fallback = null;

	public static function init(){
		if(!self::$_disp){
			self::$_disp = \FastRoute\simpleDispatcher(function(RouteCollector $route){
			  	 self::$_route = $route;
                 Config::get('route',null);
			});
		}
		return true;
	}

	public static function group($path, $callbacks){
        if($callbacks && is_array($callbacks)){
        	$_route = self::$_route;
			static::$_route->addGroup($path, function(RouteCollector $route) use ($callbacks){
				foreach($callbacks as $callback){
					self::$_route->addRoute($callback[0], $callback[1], $callback[2]);
				}
			});
		}
    }

    public static function get($path, $callback){
        return static::addRoute('GET', $path, $callback);
    }

    public static function post($path, $callback){
        return static::addRoute('POST', $path, $callback);
    }

    public static function put($path, $callback){
        return static::addRoute('PUT', $path, $callback);
    }

    public static function patch($path, $callback){
        return static::addRoute('PATCH', $path, $callback);
    }

    public static function delete($path, $callback){
        return static::addRoute('DELETE', $path, $callback);
    }

    public static function head($path, $callback){
        return static::addRoute('HEAD', $path, $callback);
    }

    public static function options($path, $callback){
        return static::addRoute('OPTIONS', $path, $callback);
    }

	public static function add($method, $path, $callback){
        return static::addRoute($method, $path, $callback);
    }

	public static function dispatch($method, $path){
		if(!static::$_disp){
			return;
		}
        return static::$_disp->dispatch($method, $path);
    }

    public static function any($path, $callback){
        return static::addRoute(['GET', 'POST', 'PUT', 'DELETE', 'PATCH', 'HEAD', 'OPTIONS'], $path, $callback);
    }

    protected static function addRoute($method, $path, $callback){
        static::$_hasRoute = true;
        self::$_route->addRoute($method, $path, $callback);
        return true;
    }

    public static function fallback(callable $callback) {
        if (is_callable($callback)) {
            static::$_fallback = $callback;
        }
    }

    public static function getFallback() {
        return is_callable(static::$_fallback) ? static::$_fallback : null;
    }

}
?>