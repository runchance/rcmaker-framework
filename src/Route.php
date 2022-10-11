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
    private static $_instance = null;
    private $_middlewares = [];

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
        $routeSatatic = static::$_instance =  new static;
        $routeSatatic::$_route->addGroup($path,$callbacks);
        static::$_instance = null;
        return $routeSatatic;
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

    public function middleware($middleware)
    {
        if ($middleware === null) {
            return $this->_middlewares;
        }
        $this->_middlewares = array_merge($this->_middlewares, (array)$middleware);
        return $this;
    }

    public function getMiddleware()
    {
        return $this->_middlewares;
    }

    protected static function addRoute($method, $path, $callback){
        $routeSatatic = static::$_instance ?? new static;
        $routeSatatic::$_hasRoute = true;
        $routeSatatic::$_route->addRoute($method, $path, ['callback' => $callback, 'route' => $routeSatatic]);
        return $routeSatatic;
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