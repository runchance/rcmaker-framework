<?php
namespace RC;
use RC\Config;
use RC\Session;
use RC\Response;
use RC\Helper;
use Workerman\Protocols\Http;
class Request{
	protected static $_frame = null;
	protected $id = null;
	protected $nativeRequest = null;
	protected $_get = array();
	protected $_attributes = [];
	public $app = [null,null,null];
	public $RCresponse = null;
	protected static $injection = ['response','R','json','xml','jsonp','redirect','pdf','P','view','V','model','M','qrcode','Q','setcookies','SC','getcookies','GC','sessions','S','captcha','C','download','D','autoForm','AF','simple_database','SDB','token','T','sms','captchaCheck','CC'];
	public function __construct($id)
    {
        $this->id = $id;
    }

    public function id()
    {
        return $this->id;
    }

    public function __call($method, $args){
    	if(in_array($method,static::$injection)){
    		return $args ? $method($this,...array_values($args)) : $method($this);
    	}
    }

	public function __set($name, $value)
	{
		$this->_attributes[$name] = $value;
	}

	public function __get($name)
	{
		return $this->_attributes[$name] ?? null;
	}

	public function __isset($name)
	{
		return array_key_exists($name, $this->_attributes);
	}

	public function __unset($name)
	{
		unset($this->_attributes[$name]);
	}


    public function set($request = null,$id = null,$RCresponse = null){
		static::$_frame = static::$_frame ?? Config::get('app','cli_frame');
		$this->nativeRequest = $request;
		$this->_attributes = [];
		$this->RCresponse = $RCresponse;
	}

	public function unset($id){
		$this->nativeRequest = null;
		$this->_attributes = [];
		$this->RCresponse = null;
	}

	protected static function stripHostPort($host)
	{
		$host = trim((string)$host);
		if($host === ''){
			return $host;
		}
		if($host[0] === '[' && ($pos = strpos($host, ']')) !== false){
			return substr($host, 1, $pos - 1);
		}
		if(substr_count($host, ':') === 1){
			return explode(':', $host)[0];
		}
		return $host;
	}

	protected static function serverHeader($name, $default = '', $hasDefault = false)
	{
		$key = strtoupper(str_replace('-', '_', (string)$name));
		$serverKey = 'HTTP_'.$key;
		if(array_key_exists($serverKey, $_SERVER)){
			return $_SERVER[$serverKey];
		}
		if(in_array($key, ['CONTENT_TYPE', 'CONTENT_LENGTH', 'CONTENT_MD5'], true) && array_key_exists($key, $_SERVER)){
			return $_SERVER[$key];
		}
		return $hasDefault ? $default : '';
	}

	public function ip(){
        $remoteIp = $this->remoteIp();
        return $this->header('x-real-ip', $this->header('x-forwarded-for',
            $this->header('client-ip', $this->header('x-client-ip',
                $this->header('via', $remoteIp)))));
    }

	public function remoteIp(){
		$frame = static::$_frame;
		if(!IS_CLI || !$frame){
			if (getenv("HTTP_CLIENT_IP") && strcasecmp(getenv("HTTP_CLIENT_IP"), "unknown")) $ip = getenv("HTTP_CLIENT_IP"); 
			else if (getenv("HTTP_X_FORWARDED_FOR") && strcasecmp(getenv("HTTP_X_FORWARDED_FOR"), "unknown")) $ip = getenv("HTTP_X_FORWARDED_FOR"); 
			else if (getenv("REMOTE_ADDR") && strcasecmp(getenv("REMOTE_ADDR"), "unknown")) $ip = getenv("REMOTE_ADDR"); 
			else if (isset($_SERVER['REMOTE_ADDR']) && $_SERVER['REMOTE_ADDR'] && strcasecmp($_SERVER['REMOTE_ADDR'], "unknown")) $ip = $_SERVER['REMOTE_ADDR']; 
			else $ip = "unknown"; 
			return $ip; 
		}
		if($frame=='workerman'){
			$connection = $this->RCresponse ? $this->RCresponse->getConnection() : null;
			return $connection ? $connection->getRemoteIp() : null;
		}
		$req = $this->nativeRequest;
		if($frame=='swoole' && $req){
			$ip = $req->server['remote_addr'];
			return $ip;
		}
		return null;
	}

	public function file($name=null){
		$frame = static::$_frame;
		if(!IS_CLI || !$frame){
			if (null === $name) {
				$files = $_FILES;
			}else{
				$files = isset($_FILES[$name]) ? $_FILES[$name] : null;
			}
		}else{
			$req = $this->nativeRequest;
		}
		
		if($frame=='workerman' && $req){
			$files = $req->file($name);

		}
		if($frame=='swoole' && $req){
			$files = $req->files ?? null;
			if (null !== $name) {
				$files = $files[$name] ?? null;
			}
		}
		if (null === $files) {
            return $name === null ? [] : null;
        }
      
        if ($name !== null) {
            // Multi files
            if (\is_array(\current($files))) {
                return static::parseFiles($files);
            }
            return static::parseFile($files);
        }

        $upload_files = [];
        foreach ($files as $name => $file) {
            // Multi files
            if (\is_array(\current($file))) {
                $upload_files[$name] = static::parseFiles($file);
            } else {
                $upload_files[$name] = static::parseFile($file);
            }
            if($upload_files[$name]===null || !$upload_files[$name]){
            	unset($upload_files[$name]);
            }
        }
        return $upload_files;
	}

	protected static function parseFile($file)
    {
    	if(isset($file['tmp_name']) && $file['tmp_name']){
    		return new \RC\Http\UploadFile($file['tmp_name'], $file['name'], $file['type'], $file['error']);
    	}
    	return null;
    }

    protected static function parseFiles($files)
    {
    	$parse = [];
        $upload_files = [];
        if(isset($files['name'])){
        	foreach($files as $key=>$name){
        		foreach($files[$key] as $k=>$value){
        			$parse[$k][$key] = $value;
        		}
        	}
        }else{
        	$parse = $files;
        }
        foreach ($parse as $file) {
            $upload_files[$file['name']] = static::parseFile($file);
            if($upload_files[$file['name']]===null){
            	unset($upload_files[$file['name']]);
            }
        }
        return $upload_files;
    }

	public function protocol(){
		$frame = static::$_frame;
		if(!IS_CLI || !$frame){
			return getenv('SERVER_PROTOCOL'); 
		}
		$req = $this->nativeRequest;
		if($frame=='workerman' && $req){
			$protocol = 'HTTP/' . $req->protocolVersion();
			return $protocol;
		}
		if($frame=='swoole' && $req){
			$protocol = $req->server['server_protocol'];
			return $protocol;
		}
		return null;
	}

	public function setGet($get):bool{
		if(!$get || !is_array($get)){
			$this->_get = [];
			return false;
		}
		$frame = static::$_frame;
		if(!IS_CLI || !$frame){
			$_GET = array_merge($get,$_GET);
			return true;
		}
		$req = $this->nativeRequest;
		$this->_get = $get;
		return false;
	}

	public function rawBody(){
		$frame = static::$_frame;
		if(!IS_CLI || !$frame){
			return file_get_contents('php://input');
		}
		$req = $this->nativeRequest;
		if($frame=='workerman' && $req){
			return $req->rawBody();
		}
		if($frame=='swoole' && $req){
			return $req->getContent();
		}
	}

	public function raw($function,...$args){
		$frame = static::$_frame;
		if(!IS_CLI || !$frame){
			return null;
		}
		$req = $this->nativeRequest;
		if($req){
			if(is_callable([$req,$function])){
				return $req->{$function}(...$args);
			}else{
				if(is_array($req->{$function})){
					return isset($args[0]) ? $req->{$function}[$args[0]] : $req->{$function};
				}
			}
		}
	}

	public function session(){
		$frame = static::$_frame;
		if(!IS_CLI || !$frame){
			if (!session_id()){
				session_name(Session::sessionName());
				session_start();
			}
			$session = new Session($this->id);
			return $session->session();
		}
		$req = $this->nativeRequest;
		if($frame=='workerman' && $req){
			Http::sessionName(Session::sessionName());
			return $req->session();
		}
		if($frame=='swoole' && $req){
			$connection = $this->RCresponse ? $this->RCresponse->getConnection() : null;
			$session = new Session($this->id,$req,$connection);
			return $session->session('swoole');
		}
	}

	public function cookie($var=null,$default=null){
		$frame = static::$_frame;
		$hasDefault = func_num_args() >= 2;
		if(!IS_CLI || !$frame){
			if(!$var){
				return $_COOKIE;
			}
			return array_key_exists($var, $_COOKIE) ? $_COOKIE[$var] : ($hasDefault ? $default : '');
		}
		$req = $this->nativeRequest;
		if($frame=='workerman' && $req){
			if(!$var){
				return $req->cookie();
			}
			return isset($default) ? $req->cookie($var,$default) : $req->cookie($var);
		}
		if($frame=='swoole' && $req){
			if(!$var){
				return $req->cookie ?? [];
			}
			$cookie = $req->cookie ?? [];
			return array_key_exists($var, $cookie) ? $cookie[$var] : ($hasDefault ? $default : '');
		}
		return null;

	}

	public function getRequest(){
		return $this->nativeRequest;
	}
	
	public function header($var=null,$default=null,$id=null){
		$frame = static::$_frame;
		$hasDefault = func_num_args() >= 2;
		if(!IS_CLI || !$frame){
			if(!$var){
				return $_SERVER;
			}
			return static::serverHeader($var, $default, $hasDefault);
			
		}
		$req = $this->nativeRequest;
		if($frame=='workerman' && $req){
			if(!$var){
				return $req->header();
			}
			return isset($default) ? $req->header($var,$default) : $req->header($var);
		}
		if($frame=='swoole' && $req){
			if(!$var){
				return $req->header ?? [];
			}
			$headers = $req->header ?? [];
			$key = strtolower((string)$var);
			return array_key_exists($key, $headers) ? $headers[$key] : ($hasDefault ? $default : '');
		}
		return null;
	}
	public function method(){
		$frame = static::$_frame;
		if(!IS_CLI || !$frame){
			return $_SERVER['REQUEST_METHOD'];
		}
		$req = $this->nativeRequest;
		if($frame=='workerman' && $req){
			return $req->method();
		}
		if($frame=='swoole' && $req){
			return $req->server['request_method'];
		}
		return null;
	}
	public function host($hideport = false){
		$frame = static::$_frame;
		if(!IS_CLI || !$frame){
			$host = $_SERVER['HTTP_HOST'] ?? '';
			if($hideport){
				return static::stripHostPort($host);
			}
			if($host === '' || strpos($host, ':') !== false){
				return $host;
			}
			return isset($_SERVER['SERVER_PORT']) ? $host.":".$_SERVER['SERVER_PORT'] : $host;
		}
		$req = $this->nativeRequest;
		if($frame=='workerman' && $req){
			return $req->host($hideport);
		}
		if($frame=='swoole' && $req){
			$host = $req->header['host'] ?? '';
			return $hideport ? static::stripHostPort($host) : $host;
		}
		return null;
	}
	public function get($var=null,$default=null){
		$frame = static::$_frame;
		if(!IS_CLI || !$frame){
			return $var ? ($_GET[$var] ?? $default) : $_GET;
		}
		$req = $this->nativeRequest;
		if($frame=='workerman' && $req){
			if(!$var){
				return array_merge($this->_get,$req->get() ?? []);
			}
			return isset($default) ? ($req->get($var) ?? ($this->_get[$var] ?? $default)) : ($req->get($var) ?? ($this->_get[$var] ?? null));
		}
		if($frame=='swoole' && $req){
			if(!$var){
				return array_merge($this->_get,$req->get ?? []);
			}
			return isset($default) ? ($req->get[$var] ?? ($this->_get[$var] ?? $default)) : ($req->get[$var] ?? ($this->_get[$var] ?? null));
		}
		return $default ?? '';
	}

	public function post($var=null,$default=null){
		$frame = static::$_frame;
		if(!IS_CLI || !$frame){
			return $var ? ($_POST[$var] ?? $default) : $_POST;
		}
		$req = $this->nativeRequest;
		if($frame=='workerman' && $req){
			if(!$var){
				return $req->post();
			}
			return isset($default) ? $req->post($var,$default) : ($req->post($var) ?? null);
		}
		if($frame=='swoole' && $req){
			if(!$var){
				return $req->post;
			}
			return isset($default) ? ($req->post[$var] ?? $default) : ($req->post[$var] ?? null);
		}
		return $default ?? '';
	}
	public function path(){
		$frame = static::$_frame;
		if(!IS_CLI || !$frame){
			return parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
		}	
		$req = $this->nativeRequest;
		if($frame=='workerman' && $req){
			return $req->path();
		}
		if($frame=='swoole' && $req){
			return $req->server['path_info'];
		}
		return null;
	}

	public function queryString(){
		$frame = static::$_frame;
		if(!IS_CLI || !$frame){
			return parse_url($_SERVER['REQUEST_URI'], PHP_URL_QUERY);
		}	
		$req = $this->nativeRequest;
		if($frame=='workerman' && $req){
			return $req->queryString() ?? null;
		}
		if($frame=='swoole' && $req){
			return $req->server['query_string'] ?? null;
		}
		return null;
	}

    public function isAjax(){
        return $this->header('X-Requested-With') === 'XMLHttpRequest';
    }

	public function isPjax(){
        return (bool)$this->header('X-PJAX');
    }

	public function expectsJson(){
        return ($this->isAjax() && !$this->isPjax()) || $this->acceptJson();
    }

	public function acceptJson(){
		return false !== stripos((string)$this->header('accept', ''), 'json');
    }
}
?>
