<?php
namespace RC;
use RC\Bootstrap;
use Workerman\Protocols\Http\Session as SessionBase;
class Session implements Bootstrap {
	protected static $_sessionName = 'PHPSID';
	protected $_data = [];
	public $session = null;
	protected $id = null;
	protected $request = null;
	protected $connection = null;
	public function __construct($id,$request=null,$connection=null)
    {
        $this->id = $id;
		$this->request = $request;
		$this->connection = $connection;
    }
	protected static function createSessionId(){
        return \bin2hex(\pack('d', \microtime(true)) . \pack('N', \mt_rand()));
	}
	private function sessionId($eng=null){
		if (!isset($this->_data['sid'])) {
			$session_name = static::sessionName();
			switch($eng){
				case 'swoole':
			
					$sid = $this->request->cookie[$session_name] ?? null;
					if ($sid === '' || $sid === null) {
						$sid = static::createSessionId();
				        $cookie_params = \session_get_cookie_params();
						$conn = $this->connection;
						if(!$conn){
							return false;
						}
						$conn->header('Set-Cookie',$session_name . '=' . $sid
	                	. (empty($cookie_params['domain']) ? '' : '; Domain=' . $cookie_params['domain'])
	                    . (empty($cookie_params['lifetime']) ? '' : '; Max-Age=' . ($cookie_params['lifetime'] + \time()))
	                    . (empty($cookie_params['path']) ? '' : '; Path=' . $cookie_params['path'])
	                    . (empty($cookie_params['samesite']) ? '' : '; SameSite=' . $cookie_params['samesite'])
	                    . (!$cookie_params['secure'] ? '' : '; Secure')
	                    . (!$cookie_params['httponly'] ? '' : '; HttpOnly'));
					}
				break;
				default: 
				     $sid = $_COOKIE[$session_name] ?? null;
				     if ($sid === '' || $sid === null) {
				     	 $sid = static::createSessionId();
				         $cookie_params = \session_get_cookie_params();
				         header('Set-Cookie:'.$session_name . '=' . $sid
		                	. (empty($cookie_params['domain']) ? '' : '; Domain=' . $cookie_params['domain'])
		                    . (empty($cookie_params['lifetime']) ? '' : '; Max-Age=' . ($cookie_params['lifetime'] + \time()))
		                    . (empty($cookie_params['path']) ? '' : '; Path=' . $cookie_params['path'])
		                    . (empty($cookie_params['samesite']) ? '' : '; SameSite=' . $cookie_params['samesite'])
		                    . (!$cookie_params['secure'] ? '' : '; Secure')
		                    . (!$cookie_params['httponly'] ? '' : '; HttpOnly')
			             );
 
				     }
				break;
			}
			 
		    $this->_data['sid'] = $sid;
		}
		
		return $this->_data['sid'];
	}

	public function session($eng=null){
		if ($this->session === null) {
            $session_id = $this->sessionId($eng);
            if ($session_id === false) {
                return false;
            }
            $this->session = new SessionBase($session_id);

        }
        return $this->session;
	}

	public static function sessionName($name = null){
		if ($name !== null && $name !== '') {
            static::$_sessionName = (string)$name;
        }
        return static::$_sessionName;
	}

	public static function start()
    {
        $config = Config::get('session');
        if(!$config){
        	return null;
        }
        static::sessionName($config['session_name']);
        switch($config['handler']){
        	case 'RC\Http\RedisSessionHandler':
        		$config['type'] = 'redis';
        	break;
        	case 'RC\Http\RedisClusterSessionHandler':
        		$config['type'] = 'redis_cluster';
        	break;
        	case 'RC\Http\FileSessionHandler': default:
        		$config['type'] = 'file';
        	break;
        }
        SessionBase::handlerClass($config['handler'], $config['config'][$config['type']]);
    }
}
?>
