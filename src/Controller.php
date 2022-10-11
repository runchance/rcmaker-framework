<?php
namespace RC;
use RC\Config;
use RC\Container;
use RC\Route;
use RC\Response;
use RC\Request;
use RC\Middleware;
use RC\Stopwatch;
use RC\Exception\ExceptionHandler;
use FastRoute\Dispatcher;
use RC\Http\Workerman\Response as ResponseObj;
class Controller{
	private static $_config = [];
	private static $_pathparse = [];
	private static $_callbacks = [];
	public static $_mb = [];
	


	//调用控制器主方法
	public static function call($request,$response,$config = array()){
		$host = $request->host();
		$path = $request->path();
		$query = $request->queryString();
        $key = $host . $request->method() . $path . ($query ?? '');
        $response->findStaticFile = false;
        $response->staticFile = null;
        $response->_status = 200;
		if(isset(static::$_callbacks[$key])){
        	list($callback, $app, $controller, $action, $args, $count) = static::$_callbacks[$key];
        	if($app=='__static__'){
        		$response->findStaticFile = true;
        		$response->staticFile = $controller;
        	}
        	if($count){
        		Stopwatch::start('__controller__');
			}
        	$request->app = ['app'=>$app,'controller'=>$controller,'action'=>$action,'class'=>"app\\$app\\controller\\$controller"];
        	$request->setGet($args);
            $response->response($app,$callback,$request);
            return null;
        }
        if($config){
	        if ($config['enable_static_file']) {
	        	if(self::staticMode($path, $key,$config,$request,$response)){
	        		return null;
	        	}
	        }
        }
		self::parseConfig();
		$parseUrl = self::parseUrl($key,$request);
		
		if(self::$_mb['count']){
			Stopwatch::start('__controller__');
		}
		$with_custom_route = self::$_mb['with_custom_route'];
		if($with_custom_route){
			if(self::routeMode($path,$key,$config,$request,$response)===null){
				return null;
			}
		}
		list($appdir,$app,$controller,$action,$args,$count) = [
			self::$_mb['appdir'],
			self::$_mb['app'],
			self::$_mb['controller'],
			self::$_mb['action'],
			self::$_mb['args'],
			self::$_mb['count']
		];

		if(!$app){
			$response->bad($request,'404','unkown app!');
			return null;
		}
		if(!$controller){
			$response->bad($request,'404','unkown controller class!');
			return null;
		}
		if(!$action){
			$action = 'index';
		}
		$controller_class = "app\\$app\\controller\\$controller";
		$controller_class_file = $appdir.'/controller/'.$controller.'.php';
		$request->setGet($args);
		$request->app = ['app'=>$app,'controller'=>$controller,'action'=>$action,'class'=>$controller_class];
		if(!class_exists($controller_class)){
			if(Container::loadClass($controller_class_file,$controller_class)===false){
				if($with_custom_route && ($callback = Route::getFallback())!==null){
					$response->response($app,$callback,$request);
					return null;
				}
				$response->bad($request,'404');
				return null;
			}
		}
		
		if(\is_callable([$instance = Container::get($controller_class), $action])){
			$callback = static::getCallback($app, [$instance, $action],$request,$args);
			static::$_callbacks[$key] = [$callback, $app, $controller, $action, $args, $count];
			$response->response($app,$callback,$request);
			
		}else{
			if($with_custom_route && ($callback = Route::getFallback())!==null){
				$response->response($app,$callback,$request);
				return null;
			}
			$response->bad($request,'404');
		}
		return null;

	}

	protected static function parseController($controller_class){
		$app = $controller = '';
		$appArray = [];
		$parseHandle = explode('\\',$controller_class);
		$controller = end($parseHandle);

		$newHandle = array_pop($parseHandle);
		foreach($parseHandle as $key=>$parse){
			if($key!==0 && $key!==count($parseHandle)-1){
				$appArray[] = $parse;
			}
		}
		if($appArray){
			$app = implode('\\',$appArray);
		}
		return [$app,$controller];
	}

	protected static function routeMode($path, $key, $config, $request,$response){
		static $routeInit;

		if($routeInit===null){
			Route::init();
			$routeInit = true;
		}
         
		$ret = Route::dispatch($request->method(), $path);
		if($ret[0] === Dispatcher::FOUND) { //查找全局路由
			$app = $controller = $action = '';
			$handler = $ret[1]['callback'];
			$route = $ret[1]['route'];
            $route = clone $route;
			$args = !empty($ret[2]) ? $ret[2] : null;
			if (\is_array($handler)) {
				if($handler[0]==='__static__'){
				    if ((isset($config['enable_static_file']) && $config['enable_static_file']) || !IS_CLI) {
	    	        	if(self::staticMode($path, $key,$config,$request,$response,$handler[1])){
	    	        		return null;
	    	        	}
	    	        }
				}
	            $handler = \array_values($handler);
	            if (isset($handler[1]) && \is_string($handler[0]) && \class_exists($handler[0])) {
	                $handler = [Container::get($handler[0]), $handler[1]];
	            }
	        }

			if(is_callable($handler)){
				$callback = static::getCallback($app, $handler, $request, $args, $route);
				static::$_callbacks[$key] = [$callback, $app, $controller, $action, $args, false];
				$request->setGet($args);
				$request->app = ['app'=>$app,'controller'=>$controller,'action'=>$action,'class'=>null];
				$response->response($app,$callback,$request);
				return null;
			}
			

		}
		return true;
	}

	protected static function execFile($file){
		\ob_start();
        try {
            include $file;
        } catch (\Exception $e) {
            echo $e;
        }
        return \ob_get_clean();
	}

	protected static function staticMode($path, $key, $config, $request, $response, $file = null){
		$document_root = $config['document_root'] ?? BASE_PATH . '/public';
		$file = $file ?? \realpath("$document_root/$path");
		if (false === $file || false === \is_file($file)) {
            return false;
        }
        if (strpos($file, $document_root) !== 0) {
            $response->bad($request,'400');
            return true;
        }
        $ext = \pathinfo($file, PATHINFO_EXTENSION);
	    if ($ext === 'php') {
	    	if($path=='/index.php'){
	    		return false;
	    	}
	    	if($config['enable_static_php']){
		    		static::$_callbacks[$key] = [function ($request) use ($file) {
		           return static::execFile($file);
		       }, null,null,null,false];
		       list($callback, $app, $controller, $action, $args, $count) = static::$_callbacks[$key];
		       $request->app['app'] = '__static__';
		       $response->response('__static__',$callback,$request);
		       return true;
	    	}
	    	return false;
	       
	    }
	    static::$_callbacks[$key] = [static::getCallback('__static__', function ($request) use ($file) {
            \clearstatcache(null, $file);
            if (!\is_file($file)) {
                $response->bad($request,'404');
                return false;
            }
            return (new ResponseObj($request))->file($file);
        }, $request, null), '__static__', $file, null,null,null,false];
        list($callback, $app, $controller, $action, $args,$count) = static::$_callbacks[$key];
        $request->app['app'] = '__static__';
        $response->response('__static__',$callback,$request);
        return true;
	}

    protected static function getCallback($app, $call, $request, $args = null,$route = null)
    {
        $args = $args === null ? null : \array_values($args);
        $middlewares = [];
        if ($route) {
            $route_middlewares = \array_reverse($route->getMiddleware());
            foreach ($route_middlewares as $class_name) {
                $middlewares[] = [Container::get($class_name), 'handle'];
            }
        }
        $middleware =  \array_merge($middlewares, Middleware::getMiddleware($app));
        if ($middleware) {
        	$callback = array_reduce($middleware,function($carry, $item){
        		return function ($request) use ($carry, $item) {
                    return $item($request, $carry);
                };
        	},static::initial($call, $args));
        	return $callback;
        }else{
        	return static::initial($call, $args);

        }
    }

	protected static function initial($call, $args = null){
		if ($args === null) {
            $callback = $call;
        } else {
            $callback = function ($request) use ($call, $args) {
                return $call($request, ...$args);
            };
        }
        return $callback;
	}


	//解析config并设置系统参数
	private static function parseConfig(){
		if(self::$_config){
			return true;
		}
		$conf = Config::get('app');
		if(!$conf){
			throw new \Exception('config error!');
		}
		self::$_config = [
			'default_timezone'=>$conf['default_timezone'] ?? 'Asia/Shanghai',
			'debug'=>$conf['debug'] ?? true,
			'error_msg'=>$conf['error_msg'] ?? 'page error!',
			'error_types'=>$conf['error_types'] ?? E_ALL &~E_NOTICE &~E_STRICT &~E_DEPRECATED,
			'index'=>$conf['index'] ?? 'index/index',
			'route'=>$conf['route'] ?? true,
			'with_custom_route'=>$conf['with_custom_route'] ?? true,
			'appcount'=>isset($conf['app']) ? count($conf['app']) : 1,
			'count'=>$conf['count'] ?? ['memory'=>true,'loadtime'=>true],
			'app'=>$conf['app'] ?? []
		];
		return true;
	}

	public static function exceptionResponse(\Throwable $e,$request,$response){
		$members = self::$_mb;
		$app = $members['app'] ?? '';
		$members['debug'] = $members['debug'] ?? Config::get('app','debug');
    	$members['error_msg'] = $members['error_msg'] ?? Config::get('app','error_msg');
    	try{
    		$response->findStaticFile = false;
        	$response->staticFile = null;
			$config = Config::get('exception');
			$default = $config[''] ?? ExceptionHandler::class;
			$handler_class = $config[$app] ?? $default;
			if(is_array($handler_class)){
				$class_file = BASE_PATH.'/support/exception/'.$handler_class['handle'].'.php';
				$class = "support\\exception\\".$handler_class['handle'];
				if(!class_exists($class)){
					if(!Container::loadClass($class_file,$class)){
						return [500,[],(string)$e];
					}
				}
				$exception_handler = Container::make($class,[
					'logger'=>null,
					'debug'=>$members['debug'], 
					'error_msg'=>$members['error_msg']
				]);
			}else{
				$exception_handler = Container::make($handler_class,[
					'logger'=>null,
					'debug'=>$members['debug'],
					'error_msg'=>$members['error_msg']
				]);
			}
			$exception_handler->report($e);
			$render = $exception_handler->render($e,$request);
			return $render;
    	}catch(\Throwable $e){
    		 return $members['debug'] ? [500,[],(string)$e] : [500,[],$e->getMessage()];
    	}
    }

	//解析URL地址并赋值GET
	private static function parseUrl($key,$request):bool{
		$path = $request->path();
		if(isset(self::$_pathparse[$key])){
			self::$_mb = self::$_pathparse[$key];
			return true;
		}
		if($path && $path[0] === '/'){
			$path = substr($path,1);
		}
		$explode = $path ? explode("/",$path) : array();
		$getParm = [];
		$host = $request->host(true);
		$app = $appInstance = $request->get('a','index');
		$config = [
			'route'=>self::$_config['route'],
			'with_custom_route'=>self::$_config['with_custom_route'],
			'index'=>self::$_config['index'],
			'debug'=>self::$_config['debug'],
			'error_msg'=>self::$_config['error_msg'],
			'count'=>self::$_config['count']
		];
		$controller = $controllerInstance = $request->get('c',$config['index'][0]);
		$action = $actionInstance = $request->get('m',$config['index'][1]);
		$appcount = self::$_config['appcount'];
		$apps = self::$_config['app'] ?? [];
		if($config['route']){
			if($appcount<=1){
				$controller = $explode[0] ?? $controller;
				$action = $explode[1] ?? $action;
				$getParm = array_slice($explode, 2);
			}else{
			    $app = $explode[0] ?? $app;
    			$controller = $explode[1] ?? $controller;
    			$action = $explode[2] ?? $action;
    			$getParm = array_slice($explode, 3); 
			}
		}
		$appdir = BASE_PATH.'/apps/'.$app;

		
		$config_map = [
			'route','with_custom_route','index','debug','error_msg','count'
		];

		foreach($apps as $appname=>$appconf){
			foreach($config_map as $map){
				$config[$map] = $appconf[$map] ?? self::$_config[$map];
			}
			if(isset($appconf['route']) && !$appconf['route']){
				$app = $appInstance;
				$controller = $controllerInstance;
				$action = $actionInstance;
			}
			//根据绑定域名查询应用
			if(isset($appconf['domains']) && $appconf['domains']){
				$app = $controller = $action = '';
				if(in_array($host,$appconf['domains'])){
					$appdir = BASE_PATH.'/apps/'.$appname;
					$app = $appname;
					if(isset($config['route']) && $config['route']===true){
						$controller = $explode[0] ?? ($config['index'][0] ?? $controller);
						$action = $explode[1] ?? ($config['index'][1] ?? $action);
						$getParm = array_slice($explode, 2);
					}
					break;
				}
			}else{
				if($appname==$app){
					if(isset($config['route']) && $config['route']===true && $appcount==1){
						$controller = $explode[0] ?? ($config['index'][0] ?? $controller);
						$action = $explode[1] ?? ($config['index'][1] ?? $action);
						$getParm = array_slice($explode, 2);
					}
					break;
				}
			}
		}
		$args = self::parseParams($getParm);
		self::$_mb = array_merge([
			'app'=>$app,
			'controller'=>$controller,
			'action'=>$action,
			'appcount'=>$appcount,
			'appdir'=>$appdir,
			'args'=>$args
		],$config);
		self::$_pathparse[$path] = self::$_mb;
		return true;
	}

	private static function parseParams($parms=null){
		$vars = null;
		if($parms){
			foreach($parms as $k=>$v){
				if($k%2==0){
					if(!empty($parms[$k])){
						$vars[$parms[$k]] = $parms[$k+1] ?? '';
					}
				}
			}
			return $vars;
		}
		return null;
	}
}
?>