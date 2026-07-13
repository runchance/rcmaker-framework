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
	private static $_staticMemory = [];
	public static $_mb = [];
	
	private static function normalizeHost($host){
		$host = strtolower(trim((string)$host));
		if(substr_count($host, ':') === 1){
			$host = explode(':', $host)[0];
		}
		return $host;
	}

	private static function normalizeAppName($app){
		$app = trim((string)$app, " /\\\t\n\r\0\x0B");
		$app = str_replace('/', '\\', $app);
		return $app === '' ? 'index' : $app;
	}

	private static function normalizeIndex($index){
		if(is_array($index)){
			$index = array_values($index);
			return [$index[0] ?? 'index', $index[1] ?? 'index'];
		}
		$index = trim((string)$index, " /\\\t\n\r\0\x0B");
		if($index === ''){
			return ['index', 'index'];
		}
		$index = str_replace('\\', '/', $index);
		$index = str_replace('@', '/', $index);
		$index = array_values(array_filter(explode('/', $index), 'strlen'));
		return [$index[0] ?? 'index', $index[1] ?? 'index'];
	}

	private static function isValidClassPart($name){
		return is_string($name) && preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $name) === 1;
	}

	private static function isValidAppName($app){
		$app = self::normalizeAppName($app);
		foreach(explode('\\', $app) as $part){
			if(!self::isValidClassPart($part)){
				return false;
			}
		}
		return true;
	}

	private static function appToPath($app){
		return str_replace('\\', '/', self::normalizeAppName($app));
	}

	private static function matchPathApp($segments, $apps){
		$matchedApp = null;
		$matchedLength = 0;
		foreach($apps as $appname=>$appconf){
			if(!empty($appconf['domains'])){
				continue;
			}
			$appSegments = explode('/', self::appToPath($appname));
			$length = count($appSegments);
			if($length <= $matchedLength || $length > count($segments)){
				continue;
			}
			if(array_slice($segments, 0, $length) === $appSegments){
				$matchedApp = $appname;
				$matchedLength = $length;
			}
		}
		return [$matchedApp, $matchedLength];
	}

	private static function getAppDir($baseAppPath, $app){
		return $baseAppPath.'/'.self::appToPath($app);
	}

	private static function isAbsolutePath($path){
		if(!is_string($path) || $path === ''){
			return false;
		}
		if($path[0] === '/' || $path[0] === '\\'){
			return true;
		}
		return isset($path[1]) && ctype_alpha($path[0]) && $path[1] === ':';
	}

	private static function resolveDocumentRoot($documentRoot){
		if(!is_string($documentRoot) || trim($documentRoot) === ''){
			return BASE_PATH . '/public';
		}
		$documentRoot = trim($documentRoot);
		if(self::isAbsolutePath($documentRoot)){
			return $documentRoot;
		}
		return rtrim(public_path(), '/\\') . '/' . trim(str_replace('\\', '/', $documentRoot), '/');
	}

	private static function isStaticGzipEnabled($config){
		return !array_key_exists('enable_static_gzip', $config) ? true : (bool)$config['enable_static_gzip'];
	}

	private static function isStaticPreloadEnabled($config){
		return !empty($config['enable_static_preload']);
	}

	private static function getStaticPreloadExtensions($config){
		$extensions = $config['static_preload_extensions'] ?? ['css', 'js', 'html', 'htm', 'json', 'svg', 'txt', 'xml'];
		if(is_string($extensions)){
			$extensions = explode(',', str_replace('，', ',', $extensions));
		}
		if(!is_array($extensions)){
			$extensions = ['css', 'js', 'html', 'htm', 'json', 'svg', 'txt', 'xml'];
		}
		$extensions = array_map(function ($extension) {
			return ltrim(strtolower(trim((string)$extension)), '.');
		}, $extensions);
		$extensions = array_values(array_filter(array_unique($extensions), 'strlen'));
		return $extensions ?: ['css', 'js', 'html', 'htm', 'json', 'svg', 'txt', 'xml'];
	}

	private static function getStaticPreloadTimeLimit($config){
		$limit = $config['static_preload_time_limit'] ?? 0.5;
		if(!is_numeric($limit)){
			return 0.5;
		}
		$limit = (float)$limit;
		return $limit < 0 ? 0.0 : $limit;
	}

	private static function clientAcceptsGzip($request){
		$acceptEncoding = (string) $request->header('accept-encoding', '');
		return stripos($acceptEncoding, 'gzip') !== false;
	}

	private static function isCompressibleStaticFile($file){
		$extension = strtolower((string) pathinfo($file, PATHINFO_EXTENSION));
		return in_array($extension, ['css', 'js', 'html', 'htm', 'json', 'svg', 'txt', 'xml'], true);
	}

	private static function getStaticContentType($file){
		$extension = strtolower((string) pathinfo($file, PATHINFO_EXTENSION));
		$mimeMap = [
			'css' => 'text/css; charset=UTF-8',
			'js' => 'application/javascript; charset=UTF-8',
			'html' => 'text/html; charset=UTF-8',
			'htm' => 'text/html; charset=UTF-8',
			'json' => 'application/json; charset=UTF-8',
			'svg' => 'image/svg+xml',
			'txt' => 'text/plain; charset=UTF-8',
			'xml' => 'text/xml; charset=UTF-8',
		];
		if(isset($mimeMap[$extension])){
			return $mimeMap[$extension];
		}
		if(function_exists('mime_content_type')){
			$mime = mime_content_type($file);
			if(is_string($mime) && $mime !== ''){
				return $mime;
			}
		}
		return 'application/octet-stream';
	}

	private static function shouldPreloadStaticFile($file, $config){
		$extension = strtolower((string) pathinfo($file, PATHINFO_EXTENSION));
		return in_array($extension, self::getStaticPreloadExtensions($config), true);
	}

	private static function buildStaticMemoryEntry($file){
		$body = @file_get_contents($file);
		if(!is_string($body)){
			return null;
		}
		$mtime = @filemtime($file) ?: null;
		$gzip = null;
		if(self::isCompressibleStaticFile($file) && function_exists('gzencode')){
			$encoded = gzencode($body, 6);
			$gzip = is_string($encoded) ? $encoded : null;
		}
		return [
			'body' => $body,
			'mime' => self::getStaticContentType($file),
			'mtime' => $mtime,
			'gzip' => $gzip,
		];
	}

	private static function preloadStaticDirectory($documentRoot, $config){
		$documentRoot = realpath($documentRoot);
		if($documentRoot === false || isset(self::$_staticMemory[$documentRoot])){
			return;
		}
		self::$_staticMemory[$documentRoot] = [];
		$started = microtime(true);
		$timeLimit = self::getStaticPreloadTimeLimit($config);
		echo '[static-preload] start ' . $documentRoot . ' limit=' . $timeLimit . 's' . PHP_EOL;
		$loaded = 0;
		$iterator = new \RecursiveIteratorIterator(
			new \RecursiveDirectoryIterator($documentRoot, \FilesystemIterator::SKIP_DOTS),
			\RecursiveIteratorIterator::SELF_FIRST
		);
		foreach($iterator as $item){
			if($timeLimit > 0 && (microtime(true) - $started) >= $timeLimit){
				echo '[static-preload] stop ' . $documentRoot . ' reason=time_limit loaded=' . $loaded . ' elapsed=' . round(microtime(true) - $started, 4) . 's' . PHP_EOL;
				break;
			}
			if(!$item->isFile()){
				continue;
			}
			$file = $item->getPathname();
			if(!self::shouldPreloadStaticFile($file, $config)){
				continue;
			}
			$entry = self::buildStaticMemoryEntry($file);
			if($entry !== null){
				self::$_staticMemory[$documentRoot][$file] = $entry;
				$loaded++;
			}
		}
		echo '[static-preload] done ' . $documentRoot . ' loaded=' . $loaded . ' elapsed=' . round(microtime(true) - $started, 4) . 's' . PHP_EOL;
	}

	private static function getPreloadedStaticEntry($config, $file){
		if(!self::isStaticPreloadEnabled($config)){
			return null;
		}
		$documentRoot = realpath(self::resolveDocumentRoot($config['document_root'] ?? ''));
		$file = realpath($file);
		if($documentRoot === false || $file === false){
			return null;
		}
		return self::$_staticMemory[$documentRoot][$file] ?? null;
	}

	public static function warmupStaticPreload(){
		self::parseConfig();
		$configs = [];
		if(self::isStaticPreloadEnabled(self::$_config)){
			$configs[] = self::$_config;
		}
		foreach((self::$_config['app'] ?? []) as $appconf){
			$merged = array_merge(self::$_config, $appconf);
			if(self::isStaticPreloadEnabled($merged)){
				$configs[] = $merged;
			}
		}
		$warmedRoots = [];
		foreach($configs as $config){
			$documentRoot = self::resolveDocumentRoot($config['document_root'] ?? '');
			$realRoot = realpath($documentRoot);
			if($realRoot === false || isset($warmedRoots[$realRoot])){
				continue;
			}
			self::preloadStaticDirectory($realRoot, $config);
			$warmedRoots[$realRoot] = true;
		}
	}

	private static function memoryStaticResponse($request, $config, $file){
		$entry = self::getPreloadedStaticEntry($config, $file);
		if(!$entry){
			return null;
		}
		$headers = [
			'Content-Type' => $entry['mime'],
		];
		if($entry['mtime']){
			$headers['Last-Modified'] = gmdate('D, d M Y H:i:s', $entry['mtime']) . ' GMT';
		}
		if(self::isStaticGzipEnabled($config) && self::clientAcceptsGzip($request) && !$request->header('range', '') && is_string($entry['gzip'])){
			$headers['Content-Encoding'] = 'gzip';
			$headers['Vary'] = 'Accept-Encoding';
			return new ResponseObj($request, 200, $headers, $entry['gzip']);
		}
		return new ResponseObj($request, 200, $headers, $entry['body']);
	}

	private static function gzipStaticResponse($request, $config, $file){
		if(self::isStaticPreloadEnabled($config)){
			return null;
		}
		if(!self::isStaticGzipEnabled($config) || !function_exists('gzencode')){
			return null;
		}
		if(!self::clientAcceptsGzip($request)){
			return null;
		}
		if($request->header('range', '')){
			return null;
		}
		if(!self::isCompressibleStaticFile($file)){
			return null;
		}
		$size = @filesize($file);
		if($size === false || $size < 256){
			return null;
		}
		$contents = @file_get_contents($file);
		if(!is_string($contents) || $contents === ''){
			return null;
		}
		$body = gzencode($contents, 6);
		if($body === false){
			return null;
		}
		$headers = [
			'Content-Type' => self::getStaticContentType($file),
			'Content-Encoding' => 'gzip',
			'Vary' => 'Accept-Encoding',
		];
		if($mtime = @filemtime($file)){
			$headers['Last-Modified'] = gmdate('D, d M Y H:i:s', $mtime) . ' GMT';
		}
		return new ResponseObj($request, 200, $headers, $body);
	}

	private static function isPathInBase($path, $basePath){
		$path = realpath($path);
		$basePath = realpath($basePath);
		if($path === false || $basePath === false){
			return false;
		}
		if(IS_WIN){
			$path = strtolower($path);
			$basePath = strtolower($basePath);
		}
		return $path === $basePath || strpos($path, $basePath . DIRECTORY_SEPARATOR) === 0;
	}

	private static function getAppNamespace($app){
		return self::normalizeAppName($app);
	}



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
	        	$controller_class = static::$_callbacks[$key][6] ?? "app\\$app\\controller\\$controller";
        	if($app=='__static__'){
        		$response->findStaticFile = true;
        		$response->staticFile = $controller;
        	}
        	if($count){
        		Stopwatch::start('__controller__');
			}
	        	$request->app = ['app'=>$app,'controller'=>$controller,'action'=>$action,'class'=>$controller_class];
        	$request->setGet($args);
            $response->response($app,$callback,$request);
            return null;
        }
		self::parseConfig();
		$parseUrl = self::parseUrl($key,$request);
		$staticConfig = $config ?? [];
		foreach (['document_root', 'index_default', 'enable_static_file', 'enable_static_php', 'enable_static_gzip', 'enable_static_preload', 'static_preload_extensions', 'static_preload_time_limit'] as $staticKey) {
			if (array_key_exists($staticKey, self::$_mb)) {
				$staticConfig[$staticKey] = self::$_mb[$staticKey];
			}
		}
		if(!empty($staticConfig['enable_static_file'])){
			if(self::staticMode($path, $key,$staticConfig,$request,$response)){
				return null;
			}
		}
		
		if(self::$_mb['count']){
			Stopwatch::start('__controller__');
		}
		$with_custom_route = self::$_mb['with_custom_route'];
		if($with_custom_route){
			if(self::routeMode($path,$key,$config,$request,$response)===null){
				return null;
			}
		}
		list($name,$appdir,$app,$appNamespace,$controller,$action,$args,$count) = [
			self::$_mb['name'],
			self::$_mb['appdir'],
			self::$_mb['app'],
			self::$_mb['app_namespace'],
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
		if(!self::isValidAppName($appNamespace) || !self::isValidClassPart($name) || !self::isValidClassPart($controller) || !self::isValidClassPart($action)){
			$response->bad($request,'404');
			return null;
		}
		if(!is_phar() && !self::isPathInBase($appdir, Config::get('app','apps_path') ?? BASE_PATH.'/apps')){
			$response->bad($request,'404');
			return null;
		}
		$controller_class = "".$name."\\$appNamespace\\controller\\$controller";
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
			static::$_callbacks[$key] = [$callback, $app, $controller, $action, $args, $count, $controller_class];
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
		if(is_array($ret) && $ret[0] === Dispatcher::FOUND) { //查找全局路由
			$app = $controller = $action = '';
			$handler = $ret[1]['callback'];
			$route = $ret[1]['route'];
            $route = clone $route;
			$args = !empty($ret[2]) ? $ret[2] : null;
			if (\is_array($handler)) {
				if(($handler[0] ?? null)==='__static__'){
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

	private static function buildStaticResponse($request, $config, $file){
		if(($memoryResponse = self::memoryStaticResponse($request, $config, $file)) instanceof ResponseObj){
			return $memoryResponse;
		}
		if(($gzipResponse = self::gzipStaticResponse($request, $config, $file)) instanceof ResponseObj){
			return $gzipResponse;
		}
		\clearstatcache(false, $file);
		if (!\is_file($file)) {
			return new ResponseObj($request, 404, [], '404 Not Found');
		}
		return (new ResponseObj($request))->file($file);
	}

	protected static function staticMode($path, $key, $config, $request, $response, $file = null){
		
		$document_root = self::resolveDocumentRoot($config['document_root'] ?? '');
		$path = $path === '' ? '/' : $path;
		$file = $file ?? rtrim($document_root, '/\\') . $path;
		if (($file === false || !\is_file($file)) && ($path === '/' || substr($path, -1) === '/' || \is_dir($file))) {
			$indexDefault = ltrim((string) ($config['index_default'] ?? 'index.html'), '/\\');
			$indexFile = rtrim($file, '/\\') . '/' . $indexDefault;
			if (\is_file($indexFile)) {
				$file = $indexFile;
			}
		}
		if (false === $file || false === \is_file($file)) {
            return false;
        }
		if (!is_phar() && !self::isPathInBase($file, $document_root)) {
            $response->bad($request,'400');
            return true;
        }
		$file = realpath($file);
        $ext = \pathinfo($file, PATHINFO_EXTENSION);
	    if ($ext === 'php') {
	    	if($path=='/index.php'){
	    		return false;
	    	}
	    	if(!empty($config['enable_static_php'])){
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
	    static::$_callbacks[$key] = [static::getCallback('__static__', function ($request) use ($config, $file) {
				return self::buildStaticResponse($request, $config, $file);
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
			'index'=>self::normalizeIndex($conf['index'] ?? 'index/index'),
			'index_default'=>$conf['index_default'] ?? 'index.html',
			'route'=>$conf['route'] ?? true,
			'with_custom_route'=>$conf['with_custom_route'] ?? true,
			'document_root'=>$conf['document_root'] ?? null,
			'enable_static_file'=>$conf['enable_static_file'] ?? false,
			'enable_static_php'=>$conf['enable_static_php'] ?? false,
			'enable_static_gzip'=>$conf['enable_static_gzip'] ?? true,
			'enable_static_preload'=>$conf['enable_static_preload'] ?? false,
			'static_preload_extensions'=>$conf['static_preload_extensions'] ?? ['css', 'js', 'html', 'htm', 'json', 'svg', 'txt', 'xml'],
			'static_preload_time_limit'=>$conf['static_preload_time_limit'] ?? 0.5,
			'default_app'=>self::normalizeAppName($conf['default_app'] ?? 'index'),
			'appcount'=>isset($conf['app']) ? count($conf['app']) : 1,
			'count'=>$conf['count'] ?? ['memory'=>true,'loadtime'=>true],
			'app'=>[],
			'domain_map'=>[]
		];
		foreach(($conf['app'] ?? []) as $appname=>$appconf){
			$appname = self::normalizeAppName($appname);
			if(isset($appconf['index'])){
				$appconf['index'] = self::normalizeIndex($appconf['index']);
			}
			self::$_config['app'][$appname] = $appconf;
			foreach(($appconf['domains'] ?? []) as $domain){
				$domain = self::normalizeHost($domain);
				if($domain !== ''){
					self::$_config['domain_map'][$domain] = $appname;
				}
			}
		}
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
		$explode = $path ? array_values(array_filter(explode("/",$path), 'strlen')) : array();
		$getParm = [];
		$host = self::normalizeHost($request->host(true));
		$app = $appInstance = self::normalizeAppName($request->get('a',self::$_config['default_app']));
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
		$domainMap = self::$_config['domain_map'] ?? [];
		$domainMatched = false;
		$matchedApp = $domainMap[$host] ?? null;
		$matchedLength = 0;
		if($config['route']){
			if($matchedApp !== null){
				$domainMatched = true;
				$app = $matchedApp;
				$controller = $explode[0] ?? $controller;
				$action = $explode[1] ?? $action;
				$getParm = array_slice($explode, 2);
			}elseif($appcount<=1){
				$controller = $explode[0] ?? $controller;
				$action = $explode[1] ?? $action;
				$getParm = array_slice($explode, 2);
			}else{
				list($matchedApp, $matchedLength) = self::matchPathApp($explode, $apps);
				if($matchedApp !== null){
					$app = $matchedApp;
					$controller = $explode[$matchedLength] ?? $controller;
					$action = $explode[$matchedLength + 1] ?? $action;
					$getParm = array_slice($explode, $matchedLength + 2);
				}else{
					$app = self::$_config['default_app'];
					$controller = $explode[0] ?? $controller;
					$action = $explode[1] ?? $action;
					$getParm = array_slice($explode, 2);
				}
			}
		}
		$baseAppName = Config::get('app','app_name') ?? 'app';
		$baseAppPath = Config::get('app','apps_path') ?? BASE_PATH.'/apps';
		$appdir = self::getAppDir($baseAppPath, $app);

		
		$config_map = [
			'route','with_custom_route','index','index_default','debug','error_msg','count','document_root','enable_static_file','enable_static_php','enable_static_gzip','enable_static_preload','static_preload_extensions','static_preload_time_limit'
		];
		$matchedApp = null;
		$matchedAppConf = null;
		if($apps){
			if(isset($domainMap[$host])){
				$matchedApp = $domainMap[$host];
				$matchedAppConf = $apps[$matchedApp] ?? [];
				$domainMatched = true;
			}

			if($matchedApp === null){
				if(isset($apps[$app])){
					if(!empty($apps[$app]['domains'])){
						$app = $controller = $action = '';
					}elseif(isset($apps[$app]['route']) && !$apps[$app]['route'] && $app !== $appInstance){
						$app = $controller = $action = '';
					}else{
						$matchedApp = $app;
						$matchedAppConf = $apps[$app];
					}
				}elseif(isset($apps[$appInstance]) && isset($apps[$appInstance]['route']) && !$apps[$appInstance]['route']){
					$matchedApp = $appInstance;
					$matchedAppConf = $apps[$appInstance];
					$app = $appInstance;
					$controller = $controllerInstance;
					$action = $actionInstance;
					$getParm = [];
				}
			}

			if($matchedApp !== null){
				foreach($config_map as $map){
					$config[$map] = $matchedAppConf[$map] ?? self::$_config[$map];
				}
				$app = $matchedApp;
				$appdir = self::getAppDir($baseAppPath, $app);
				if(isset($config['route']) && $config['route']===true){
					if($domainMatched){
						$controller = $explode[0] ?? ($config['index'][0] ?? $controller);
						$action = $explode[1] ?? ($config['index'][1] ?? $action);
						$getParm = array_slice($explode, 2);
					}else{
						$offset = $matchedLength > 0 ? $matchedLength : 0;
						$controller = $explode[$offset] ?? ($config['index'][0] ?? $controller);
						$action = $explode[$offset + 1] ?? ($config['index'][1] ?? $action);
						$getParm = array_slice($explode, $offset + 2);
					}
				}else{
					$app = $domainMatched ? $matchedApp : $appInstance;
					$controller = $controllerInstance;
					$action = $actionInstance;
					$appdir = self::getAppDir($baseAppPath, $app);
					$getParm = [];
				}
			}
		}
		$args = self::parseParams($getParm);
		self::$_mb = array_merge([
			'name'=>$baseAppName,
			'app'=>$app,
			'app_namespace'=>self::getAppNamespace($app),
			'controller'=>$controller,
			'action'=>$action,
			'appcount'=>$appcount,
			'appdir'=>$appdir,
			'args'=>$args
		],$config);
		self::$_pathparse[$key] = self::$_mb;
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