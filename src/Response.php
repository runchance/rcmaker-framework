<?php
namespace RC;
use RC\View;
use RC\Http\Workerman\Response as ResponseObj;
use RC\Request;
use RC\Count;
use RC\Config;
use RC\Worker;
use RC\Helper;
use RC\Helper\view\Raw;
class Response{
	public static $_frame = null;
	public static $_connection = null;
	public static $_client = null;
	public static $_log_count = 0;
	protected $id = null;
	protected $_cookies = null;
	public $findStaticFile = false;
	public $staticFile = null;
	public $_status = 200;
	public $RCrequest = null;


	public function __construct($id)
    {
        $this->id = $id;
    }

    public function id()
    {
        return $this->id;
    }

    public function set($connection,$id,$RCrequest):void{
    	static::$_connection['id_'.$this->id] = $connection;
    	static::$_frame = static::$_frame ?? Config::get('app','cli_frame');
    	$this->RCrequest = $RCrequest;
    }

	public function unset($id){
		if(isset(static::$_connection['id_'.$this->id])){
			static::$_connection['id_'.$this->id] = null;
		}
		$this->RCrequest = null;
		$this->_cookies = null;
	}

	public function setWorkermanCookie($cookies){
		$this->_cookies = $cookies; 
	}
	public function send($response){
		if(static::$_frame=='workerman'){
			$this->workerman_send($response);
			return null;
		}
		if(static::$_frame=='swoole'){
			$this->swoole_send($response);
			return null;
		}
		echo $response;
	}
	public function workerman_send($response,$request){
		if($this->_cookies){
			$header = [];
			foreach($this->_cookies as $cookies){
				$header['Set-Cookie'][] = $cookies;
			}
			if($response instanceof ResponseObj){
				$response->withHeaders($header);
			}else{
				
				$response = new ResponseObj($this->RCrequest,200,$header,$response);
			}
			$this->_cookies = null;
		}

        $keep_alive = $request->header('connection');
        if (($keep_alive === null && $request->protocolVersion() === '1.1')
            || $keep_alive === 'keep-alive' || $keep_alive === 'Keep-Alive'
        ) {
        	
            static::$_connection['id_'.$this->id]->send($response);
            return;
        }
        static::$_connection['id_'.$this->id]->close($response);
        return;
    }
    private function log_list(){
    	static $_client; static $_pingTimer;
    	$cli_log = Config::get('app','cli_log');
    	if($cli_log){
    		$log = [
				'app'=>$this->RCrequest->app['app'] ?? '__static__',
				'path'=>$this->RCrequest->path(),
				'ip'=>$this->RCrequest->ip(),
				'protocol'=>$this->RCrequest->protocol(),
				'time'=>time(),
				'referer'=>$this->RCrequest->header('referer'),
				'method'=>$this->RCrequest->method(),
				'status'=>$this->_status,
				'agent'=>$this->RCrequest->header('user-agent')
			];
			if(static::$_frame=='workerman'){
				//异步通讯方式
				$_client = $_client ?? new \Workerman\Connection\AsyncTcpConnection(Config::get('worker','logger_listen'));

	    		$_client->send(json_encode(['type'=>'log','data'=>$log]));

	    		$_client->onMessage = function($connection, $result){
			         //$connection->close();
			    };
			    $_client->connect();
			    if($_pingTimer===null){
			    	$_pingTimer = \Workerman\Lib\Timer::add(25, function() use ($_client){
			    		$_client->send(json_encode(['type'=>'ping','data'=>'']));
			    	});
			    }
			}
			if(static::$_frame=='swoole'){
				//内存方式
				$table = Worker::$_swoole_table;
				$workid = Worker::$_workid;
				$logkey = ''.$workid.'-'.static::$_log_count.'';
				$table->set($logkey,$log);
				static::$_log_count++;
			}
			
    	}
    }
    public function swoole_send($response){
    	$this->_status = 200;
    	static::$_connection['id_'.$this->id]->header('Content-Type','text/html;charset=UTF-8');
    	if($response instanceof ResponseObj || $this->findStaticFile){
    		$resps = explode("\r\n",$response);
	    	$status = explode(" ",$resps[0]);
	    	if($status[1]!=='200'){
	    		$this->_status = $status[1];
	    		static::$_connection['id_'.$this->id]->status($status[1]);
	    	}
	    	$headers = array_slice($resps, 1);
	    	foreach($headers as $key => $header){
	    		if($header){
	    			$headerformat = explode(':',$header);
	    			if($headerformat[0]=='Server'){
	    				$headerformat[1] = 'swoole-http-server';
	    			}
	    			if($headerformat[0]!=='Content-Length'){
    					static::$_connection['id_'.$this->id]->header($headerformat[0],trim($headerformat[1]));
    				}
	    		}else{
	    			break;
	    		}
	    	}
	    	if(null!==$response->rawBody()){
	    		$response = $response->rawBody();
	    	}
    	}

    	if(!$this->findStaticFile){
    		static::$_connection['id_'.$this->id]->end($response);
    		return;
    	}
    	static::$_connection['id_'.$this->id]->sendfile($this->staticFile);
    }
    
	public function response($app,$callback,$request){
		$response = $callback($request);
		if(static::$_frame=='workerman'){
			$this->log_list();
			$this->workerman_send($response,$request);
			return null;
		}
		if(static::$_frame=='swoole'){
			$this->log_list();
			$this->swoole_send($response,$request);
			return null;
		}
		if($response instanceof ResponseObj){
			$resps = explode("\r\n",$response);
	    	$status = explode(" ",$resps[0]);
	    	if($status[1]!=='200'){
	    		http_response_code($status[1]);
	    	}
	    	$headers = array_slice($resps, 1);
	    	foreach($headers as $key => $header){
	    		if($header){
	    			$headerformat = explode(':',$header);
	    			if($headerformat[0]=='Server'){
	    				$headerformat[1] = 'rcmaker';
	    			}
	    			if($headerformat[0]!=='Content-Length'){
	    				header(''.$headerformat[0].':'.$headerformat[1].'');
    				}
	    		}else{
	    			break;
	    		}
	    	}
	    	if(null!==$response->rawBody()){
	    		$response = $response->rawBody();
	    	}
		}

		if($this->staticFile){
			header('Accept-Ranges:bytes');
			readfile($this->staticFile);
		}
		echo $response;
		return null;
	}

	public function bad($request,$status=404,$e='404 Not Found'){
		$this->_status = $status;
		$badfile = BASE_PATH . '/public/'.$status.'.html';
		if(static::$_frame=='workerman'){
			$this->log_list();
			if(is_file($badfile)){
				\extract(['msg'=>$e]);
				\ob_start();
				include $badfile;
				$this->workerman_send(new ResponseObj($this->RCrequest,$status, [], \ob_get_clean()),$request);
			}else{
				$this->workerman_send(new ResponseObj($this->RCrequest,$status, [], $e),$request);
			}
			return;
			
		}
		if(static::$_frame=='swoole'){
			$this->log_list();
			static::$_connection['id_'.$this->id]->status($status);
			if(is_file($badfile)){
				\extract(['msg'=>$e]);
				\ob_start();
				include $badfile;
				$this->swoole_send(\ob_get_clean());
			}else{
				$this->swoole_send($e);
			}
			return;
			
		}
		if(is_file($badfile)){
			http_response_code($status);
			\extract(['msg'=>$e]);
			\ob_start();
			include $badfile;
			echo \ob_get_clean();
			return;
		}
		http_response_code($status);
		echo $e;
		return null;
	}
}
?>