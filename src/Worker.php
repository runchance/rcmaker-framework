<?php
namespace RC;
use RC\Config;
use RC\Container;
use RC\Controller;
use RC\Response;
use RC\Request;
use RC\Middleware;
use RC\ExceptionHandler;
use Workerman\Worker as Workerman;
use Workerman\Timer;
use Workerman\Protocols\Http;
use Workerman\Connection\TcpConnection;
use RC\Helper\GlobalData\Server as GlobalDataServer;
use RC\Stopwatch;
class worker{
	protected static $_frame = null;
	protected static $_worker = null;
	protected static $_request = null;
	protected static $_config = null;
	protected static $_connection = null;
	protected static $_gracefulStopTimer = null;
	protected static $_maxRequestCount = 1000000;
	protected static $_pid = null;
	protected static $_count = 0;
	public static $_swoole_table = null;
	public static $_workid = null;
	protected static $config_ssl_map = [
		'local_cert'=>'ssl_cert_file',
		'local_pk'=>'ssl_key_file',
		'verify_peer'=>'ssl_verify_peer',
		'allow_self_signed'=>'ssl_allow_self_signed',
		'cafile'=>'ssl_client_cert_file',
		'disable_compression'=>'ssl_compress',
		'verify_depth'=>'ssl_verify_depth',
		'ciphers'=>'ssl_ciphers'
	];
	protected static $config_exclude = [
	    'listen',
	    'port',
	    'coroutine',
	    'run_model',
	    'transport',
	    'table_size',
	    'protocol',
	    'reloadable',
	    'name',
	    'ssl',
	    'context',
	    'enable_static_file',
	    'enable_static_php',
	    'handler',
	    'bootstrap'
	];

	public static function getWorker(){
		static $_responselist;
		return $_responselist;
	}

	
	public static function stopMaster(){
		if(isset(static::$_worker)){
			if(self::$_frame=='workerman'){
				$pidfile = Config::get('worker','pid_file');
			}
			if(self::$_frame=='swoole'){
				$pidfile = Config::get('swoole','pid_file');
			}
			$pid = \is_file($pidfile) ? \file_get_contents($pidfile) : null;
			$master_pid = $pid ?? posix_getppid(); 
			$sig = \SIGTERM;
			\posix_kill($master_pid, $sig);
			return null;
		}
		
	}
	private static function tryToGracefulExit(){
		if (static::$_gracefulStopTimer === null) {
            static::$_gracefulStopTimer = Timer::add(rand(1, 10), function () {
                if (\count(static::$_worker->connections) === 0) {
                	static::$_gracefulStopTimer = null;
                    Workerman::stopAll();
                }
            });
        }
	}
	public static function onMessage($connection,$request,$config){
		static $request_count = 0; static $requests; static $responses; 
		if (++$request_count > static::$_maxRequestCount) {
			if(self::$_frame=='workerman'){
	            static::tryToGracefulExit();
	            $request_count=0;
	        	echo 'Request Count > '.static::$_maxRequestCount.' reload now'."\n";
	        }
        }
        $id = self::$_frame=='workerman' ? $connection->id : $connection->fd;
        $requests[$id] = $requests[$id] ?? new Request($id);
        $responses[$id] = $responses[$id] ?? new Response($id);
        try {
            $requests[$id]->set($request,$id,$responses[$id]);
           	$responses[$id]->set($connection,$id,$requests[$id]);
            Controller::call($requests[$id],$responses[$id],$config);
            $requests[$id]->unset($id);
            $responses[$id]->unset($id);
        } catch (\Throwable $e){
        	$render = Controller::exceptionResponse($e,$requests[$id],$responses[$id]);
        	if(is_array($render)){
        		list($code,$headers,$message) = $render;
        		$responses[$id]->bad($requests[$id],$code,$message);
        		$requests[$id]->unset($id);
           		$responses[$id]->unset($id);
        	}else{
        		$responses[$id]->bad($requests[$id],500,$e->getMessage());
        	}
        }
        return null;
    }

	public static function load($frame=null){
		$frame = Config::get('app','cli_frame');
		$start_app = Config::get('app','start_app');
		$_logDir = runtime_path() . '/logs';
        if (!is_dir($_logDir)) {
               mkdir($_logDir, 0755, true);
        }
		if(!$frame){
			exit("\033[31;40mno cli mode frame setting!\033[0m\n");
		}
		self::$_frame = $frame;
		if($frame=='swoole'){
			if(!extension_loaded('swoole')){
				exit("\033[31;40mno swoole extension loaded!\033[0m\n");
			}
			cliCheck(['posix_kill','posix_getppid']);
			global $argv;
			$config = self::$_config = Config::get('swoole');
			if(!isset($argv[1]) || ($argv[1]!=='start' && $argv[1]!=='stop' && $argv[1]!=='reload')){
				$usage = "Usage: php yourfile <command> [mode]\nCommands: \nstart\t\tStart worker in DEBUG mode.\n\t\tUse mode -d to start in DAEMON mode.\nstop\t\tStop worker.\nreload\t\tReload codes.\n";
				exit($usage);
			}else{
				$master_pid = \is_file($config['pid_file']) ? \file_get_contents($config['pid_file']) : 0;
				$mode = isset($argv[2]) ? trim($argv[2]) : '';
				if($argv[1]=='stop'){
					if($master_pid==0){
						exit("\033[31;40mno swoole pid file found!\033[0m\n");
						return;
					}
					$sig = \SIGTERM;
					\posix_kill($master_pid, $sig);
					return;
				}
				if($argv[1]=='reload'){
					if($master_pid==0){
						exit("\033[31;40mno swoole pid file found!\033[0m\n");
						return;
					}
					$sig = \SIGUSR1;
					if($config['coroutine']){
						$sig = \SIGUSR2;
					}
					\posix_kill($master_pid, $sig);
					return;
				}
				if($argv[1]=='start' && $mode=='-d'){
					$config['daemonize'] = true;
				}
			}

			if(!class_exists(\Swoole\Http\Server::class)){
				exit("\033[31;40mno swoole class!\033[0m\n");
			}
			if(!$config){
				exit("\033[31;40mno swoole config found!\033[0m\n");
			}

			if(!$config['coroutine']){
				$server = static::$_worker = $start_app ? new \Swoole\WebSocket\Server($config['listen'],$config['port'],$config['run_model'],$config['ssl'] ? SWOOLE_SOCK_TCP | SWOOLE_SSL : SWOOLE_SOCK_TCP) : new \Swoole\WebSocket\Server('127.0.0.1',0,SWOOLE_PROCESS, SWOOLE_SOCK_TCP);
				

				if(isset($config['context']['ssl']) && $config['context']['ssl'] && $start_app){
					$ssl = [];
					foreach($config['context']['ssl'] as $key=>$val){
						if(isset(static::$config_ssl_map[$key])){
							$ssl[static::$config_ssl_map[$key]] = $val;
						}else{
							$ssl[$key] = $val;
						}
					}
					if($ssl){
						$server->set($ssl);
					}
					
				}

				if($start_app){
					foreach($config as $key=>$conf){
					
						if(!in_array($key,static::$config_exclude)){
							if($key=='reactor_num' || $key=='worker_num'){
					    		$config[$key] = $config[$key] ?? cpu_count()*4;
					    	}
							$server->set([
							    $key => $config[$key]
							]);
						}
						
					}
				}else{
					$server->set([
					    'worker_num'=>1,
					]);
				}

				
				
				$config_map = [
					'count'=>'worker_num',
					'reusePort'=>''
				];

				$processes = array();
				$servers = array();
				$process_config = Config::get('process', null, null, []);
				if(Config::get('app','cli_log') && $start_app){
					static::$_swoole_table = new \Swoole\Table($config['table_size']);
					static::$_swoole_table->column('app', \Swoole\Table::TYPE_STRING,30);
					static::$_swoole_table->column('path', \Swoole\Table::TYPE_STRING,250);
					static::$_swoole_table->column('ip', \Swoole\Table::TYPE_STRING,30);
					static::$_swoole_table->column('protocol', \Swoole\Table::TYPE_STRING,30);
					static::$_swoole_table->column('time', \Swoole\Table::TYPE_INT);
					static::$_swoole_table->column('referer', \Swoole\Table::TYPE_STRING,255);
					static::$_swoole_table->column('method', \Swoole\Table::TYPE_STRING,20);
					static::$_swoole_table->column('status', \Swoole\Table::TYPE_INT);
					static::$_swoole_table->column('agent', \Swoole\Table::TYPE_STRING,200);
					static::$_swoole_table->create();
					$process_config = array_merge($process_config,['RCmaker_logger'  => [
				       'handler'  => \RC\Helper\Process\Logger::class,
				       'count'  => 1,
				       'reusePort' => true,
				       'constructor'=>['table'=>static::$_swoole_table]
				    ]]);
				    
				}

				if(Config::get('queue','enable')){
				     $process_config = array_merge($process_config, Config::get('queue','consumer_process') ?? []);
			    }

				foreach ($process_config as $proc_name => $pconfig) {
					if(!isset($pconfig['handler'])){
						exit("\033[31;40mprocess error: process handler not exists!\033[0m\n");
					}
					$workmode = null;
					if(!isset($pconfig['listen'])){
						$workmode = 'process';
					}else{
						$parselisten = explode('://',$pconfig['listen']);
						$protocol = null;
						switch(strtolower($parselisten[0])){
							case "websocket": $workmode='websocket'; $protocol='open_websocket_protocol'; break;
							case "http": case "https": $workmode='http'; $protocol=['open_http_protocol','open_http2_protocol']; break;
							case "mqtt": $workmode='mqtt'; $protocol='open_mqtt_protocol';  break;
							case "tcp": $workmode='tcp';  break;
							case "udp": $workmode='udp';  break;
							case "unix": $workmode='unix';  break;
							case "text": $workmode='text';  break;
						}
						$listen = explode(':',$parselisten[1]);
						$address = $listen[0];
						$port = $listen[1];
					}
					if($workmode){
						switch($workmode){
							case 'process':
								if(!class_exists($pconfig['handler'])){
									$class_file = BASE_PATH.'/support/process/'.$pconfig['handler'].'.php';
									$class = "support\\process\\".$pconfig['handler'];
									if(!Container::loadClass($class_file,$class)){
										exit("\033[31;40mprocess error: class {$class} not exists!\033[0m\n");
										return ;
									}
								}else{
									$class = $pconfig['handler'];
								}
								
								$process = new \Swoole\Process(function($process) use ($server,$pconfig,$class){
									$instance = Container::make($class, array_merge(['type'=>'swoole','worker'=>$process,'timer'=>\Swoole\Timer::class],$pconfig['constructor'] ?? []) ?? []);
									
								}, false, 0, true);
								
								foreach(($pconfig['bootstrap'] ?? []) as $bootstrap){
									$bootstrap::start();
								}
								foreach (($pconfig['autoload'] ?? []) as $file) {
							        include_once $file;
							    }
							    
								$process->name($proc_name);
								$server->addProcess($process);
								$processes[] = [$proc_name,'process',$pconfig['bootstrap'] ?? []];
							break;
							case 'websocket':case 'http':case 'tcp':case 'udp': case 'https': case 'mqtt': case 'text':
								if(isset($pconfig['ssl'])){
									$transport = $pconfig['ssl'] ? SWOOLE_SOCK_TCP | SWOOLE_SSL : SWOOLE_SOCK_TCP;
								}else{
									$transport = SWOOLE_SOCK_TCP;
								}
								
								if($workmode=='udp'){
									$transport = SWOOLE_SOCK_UDP;
								}
								$servers[$proc_name] = $server->listen($address, $port, $transport);

								if(isset($pconfig['context']['ssl']) && $pconfig['context']['ssl']){
									$ssl = [];
									foreach($pconfig['context']['ssl'] as $key=>$val){
										if(isset(static::$config_ssl_map[$key])){
											$ssl[static::$config_ssl_map[$key]] = $val;
										}else{
											$ssl[$key] = $val;
										}
									}
									if($ssl){
				
										$servers[$proc_name]->set($ssl);
									}
									
								}
								foreach($pconfig as $key=>$conf){
									$orgikey = $key;
									$key = $config_map[$key] ?? $key;
									if(!in_array($key,static::$config_exclude) && $key){
										if($key=='reactor_num' || $key=='worker_num'){
											
											$server->set([
											    $key => $start_app ? ($config[$key]+$pconfig[$orgikey]) : ($pconfig[$orgikey]+1) 
											]);
											
								    	}
										$servers[$proc_name]->set([
										    $key => $pconfig[$orgikey]
										]);
									}
								}

								if($workmode=='text'){;
									$servers[$proc_name]->set(array(
									    'open_eof_check' => true,
									    'package_eof' => "\r\n"
									  	
									));
								}

								if($protocol){
									if(is_array($protocol)){
										foreach($protocol as $p){
											$servers[$proc_name]->set([
											    $p => true
											]);
										}
									}else{
										$servers[$proc_name]->set([
										    $protocol => true
										]);
									}
									

									if($workmode=='websocket'){
										$servers[$proc_name]->set([
										    'open_http_protocol' => false,
										    'open_http2_protocol' => false,
										]);
									}
								}
								if(!class_exists($pconfig['handler'])){
									$class_file = BASE_PATH.'/support/process/'.$pconfig['handler'].'.php';
									$class = "support\\process\\".$pconfig['handler'];
									if(!Container::loadClass($class_file,$class)){
										exit("\033[31;40mprocess error: class {$class} not exists!\033[0m\n");
									}
								}else{
									$class = $pconfig['handler'];
								}
								$instance = Container::make($class, array_merge(['type'=>'swoole','worker'=>$servers[$proc_name],'timer'=>\Swoole\Timer::class],$pconfig['constructor'] ?? []));
								worker_bind($servers[$proc_name], $instance,'swoole');
								$processes[] = [$proc_name,$pconfig['listen'],$pconfig['bootstrap'] ?? []];
								
							break;
						}
					}
				}

				$server->on('AfterReload', function () {
					//opcache_clean();
					
				});
				$server->on('BeforeReload', function () {
					
					if(Config::get('app','count')===true){
						Stopwatch::$_framework = null;
						Stopwatch::start('frame');  
					}
					
				});

				$server->on('start',function() use ($config,$process,$processes,$start_app){
					swoole_set_process_name($config['name']);
					if($start_app){
						echo "Http(s) Server [".$config['name']."] [".$config['listen'].":".$config['port']."] Is Started\r\n";
					}
					
					foreach($processes as $process){
						echo 'process ['.$process[0].'] ['.$process[1].'] Is Started'."\r\n";
					}
					
				});
				$server->on('WorkerStop',function($server,$workid){
					
				});

				$server->on('WorkerStart',function($server,$workid) use ($start_app,$processes,&$config){
					static::$_workid = $workid;
				    Config::get('app',null,true);
				    $config = Config::get('swoole',null,true);
					\error_reporting(Config::get('app','error_types') ?? E_ALL &~E_NOTICE &~E_STRICT &~E_DEPRECATED);
					\set_error_handler(function ($level, $message, $file = '', $line = 0) {
				        if (\error_reporting(Config::get('app','error_types') ?? E_ALL &~E_NOTICE &~E_STRICT &~E_DEPRECATED) & $level) {
				            throw new \ErrorException($message, 0, $level, $file, $line);
				        }
				    });
				    foreach ((Config::get('autoload') ?? []) as $file) {
				        include_once $file;
				    }
				    foreach ((Config::get('bootstrap') ?? []) as $class_name) {
				        $class_name::start($server);
				    }
				    $bootstraps = [];
				    foreach($processes as $process){
			    		foreach(($process[2] ?? []) as $bootstrap){
			    			if(!in_array($bootstrap,(Config::get('bootstrap') ?? []))){
			    				$bootstraps[$bootstrap] = 'beload';
			    			}
			    		}
				    }
				    foreach(($bootstraps ?? []) as $key=>$name){
				    	$key::start($server);
				    }
				    if($start_app){
				    	Middleware::load(Config::get('middleware',null,true) ?? []);
				    }
				    
				    if(Config::get('app','count')!==true){
				    	Stopwatch::$_framework = null;
				    }
				});

				$server->on('Request', function ($request, $response) use ($config) {
					self::onMessage($response,$request,$config);
				});
				$server->on('Message', function ($request, $response){
					return null;
				});
				$server->on('handshake', function ($request, $response) {
					$response->end();
	        		return false;
				});

				Stopwatch::$_framework = stopwatch('__frame__');
				$server->start();
			}else{
				//多进程管理模块
				$process_count = 0;
				$process = $start_app ? [
					['protocol'=>'http','name'=>$config['name'] ?? 'RC_Swoole','listen'=>$config['listen'],'port'=>$config['port'],'workers'=>$config['worker_num'],'ssl'=>$config['ssl'],'callback'=>function($server,$workid) use (&$config, &$process_count){
						Config::get('app',null,true);
						$config = Config::get('swoole',null,true);
						\error_reporting(Config::get('app','error_types') ?? E_ALL &~E_NOTICE &~E_STRICT &~E_DEPRECATED);
						\set_error_handler(function ($level, $message, $file = '', $line = 0) {
					        if (\error_reporting(Config::get('app','error_types') ?? E_ALL &~E_NOTICE &~E_STRICT &~E_DEPRECATED) & $level) {
					            throw new \ErrorException($message, 0, $level, $file, $line);
					        }
					    });
					    foreach ((Config::get('autoload') ?? []) as $file) {
					        include_once $file;
					    }
					    foreach ((Config::get('bootstrap') ?? []) as $class_name) {
					        $class_name::start($server);
					    }
				
						Middleware::load(Config::get('middleware',null,true) ?? []);
						\register_shutdown_function(function ($start_time) {
					        if (time() - $start_time <= 1) {
					            sleep(1);
					        }
					    }, time());
					    
					    if(Config::get('app','count')!==true){
					    	Stopwatch::$_framework = null;
					    }
						
						$server->set([
						    'open_http_protocol' => true,
						    'open_http2_protocol' => true
						]);

						if(isset($config['context']['ssl']) && $config['context']['ssl']){
							$ssl = [];
							foreach($config['context']['ssl'] as $key=>$val){
								if(isset(static::$config_ssl_map[$key])){
									$ssl[static::$config_ssl_map[$key]] = $val;
								}else{
									$ssl[$key] = $val;
								}
							}
							if($ssl){
								$server->set($ssl);
							}
						}
						foreach($config as $key=>$conf){
							if(!in_array($key,static::$config_exclude)){
								if($key=='reactor_num' || $key=='worker_num'){
						    		$config[$key] = $config[$key] ?? cpu_count()*4;
						    		$process_count+=$config[$key];
						    	}
								$server->set([
								    $key => $config[$key]
								]);
							}
						}
						$server->handle('/', function ($request, $response) use ($workid,$config) {
							self::onMessage($response,$request,$config);
					        
					    });
						return null;
					}],
				] : [];

				$config_map = [
					'count'=>'worker_num',
					'reusePort'=>''
				];

				$processes = array();
				$servers = array();
				$process_config = Config::get('process', null, null, []);
				if(Config::get('app','cli_log') && $start_app){
					static::$_swoole_table = new \Swoole\Table($config['table_size']);
					static::$_swoole_table->column('app', \Swoole\Table::TYPE_STRING,30);
					static::$_swoole_table->column('path', \Swoole\Table::TYPE_STRING,250);
					static::$_swoole_table->column('ip', \Swoole\Table::TYPE_STRING,30);
					static::$_swoole_table->column('protocol', \Swoole\Table::TYPE_STRING,30);
					static::$_swoole_table->column('time', \Swoole\Table::TYPE_INT);
					static::$_swoole_table->column('referer', \Swoole\Table::TYPE_STRING,255);
					static::$_swoole_table->column('method', \Swoole\Table::TYPE_STRING,20);
					static::$_swoole_table->column('status', \Swoole\Table::TYPE_INT);
					static::$_swoole_table->column('agent', \Swoole\Table::TYPE_STRING,200);
					static::$_swoole_table->create();
					$process_config = array_merge($process_config,['RCmaker_logger'  => [
				       'handler'  => \RC\Helper\Process\Logger::class,
				       'count'  => 1,
				       'reusePort' => true,
				       'constructor'=>['table'=>static::$_swoole_table]
				    ]]);
				    
				}

				if(Config::get('queue','enable')){
				     $process_config = array_merge($process_config, Config::get('queue','consumer_process') ?? []);
			    }

	

				foreach ($process_config as $proc_name => $pconfig) {
					if(!isset($pconfig['handler'])){
						exit("\033[31;40mprocess error: process handler not exists!\033[0m\n");
					}
					$workmode = null;
					if(!isset($pconfig['listen'])){
						$workmode = 'process';
					}else{
						$parselisten = explode('://',$pconfig['listen']);
						$protocol = null;
						switch(strtolower($parselisten[0])){
							case "websocket": $workmode='websocket';  break;
							case "http": case "https": $workmode='http'; break;
							case "mqtt": $workmode='mqtt';   break;
							case "tcp": $workmode='tcp';  break;
							case "udp": $workmode='udp';  break;
							case "unix": $workmode='unix';  break;
							case 'text': $workmode='text';  break;
						}
						$listen = explode(':',$parselisten[1]);
						$address = $listen[0];
						$port = $listen[1];
					}
					if($workmode){
						switch($workmode){
							case 'process':
								if(!class_exists($pconfig['handler'])){
									$class_file = BASE_PATH.'/support/process/'.$pconfig['handler'].'.php';
									$class = "support\\process\\".$pconfig['handler'];
									if(!Container::loadClass($class_file,$class)){
										exit("\033[31;40mprocess error: class {$class} not exists!\033[0m\n");
										return ;
									}
								}else{
									$class = $pconfig['handler'];
								}

								$process[] = ['protocol'=>'process','name'=>$proc_name,'workers'=>$pconfig['count'] ?? 1,'callback'=>function($server,$workid) use ($pconfig,$class){
									foreach(($pconfig['bootstrap'] ?? []) as $bootstrap){
										$bootstrap::start();
									}
									foreach (($pconfig['autoload'] ?? []) as $file) {
								        include_once $file;
								    }
									$instance = Container::make($class, array_merge(['type'=>'swoole','worker'=>$server,'timer'=>\Swoole\Timer::class],$pconfig['constructor'] ?? []) ?? []);

								}];
							break;
							case 'websocket':case 'http': case 'tcp': case 'https': case 'text':
								
								if(!class_exists($pconfig['handler'])){
									$class_file = BASE_PATH.'/support/process/'.$pconfig['handler'].'.php';
									$class = "support\\process\\".$pconfig['handler'];
									if(!Container::loadClass($class_file,$class)){
										exit("\033[31;40mprocess error: class {$class} not exists!\033[0m\n");
									}
								}else{
									$class = $pconfig['handler'];
								}

								$process[] = ['protocol'=>$workmode,'name'=>$proc_name,'listen'=>$address,'port'=>$port,'workers'=>$pconfig['count'] ?? 1,'ssl'=>$pconfig['ssl'] ?? false,'callback'=>function($server,$workid) use ($pconfig,$class,$workmode){
									if(isset($pconfig['context']['ssl']) && $pconfig['context']['ssl']){
										$ssl = [];
										foreach($pconfig['context']['ssl'] as $key=>$val){
											if(isset(static::$config_ssl_map[$key])){
												$ssl[static::$config_ssl_map[$key]] = $val;
											}else{
												$ssl[$key] = $val;
											}
										}
										if($ssl){
											$server->set($ssl);
										}
									}

									


									foreach($pconfig as $key=>$conf){
										$orgikey = $key;
										$key = $config_map[$key] ?? $key;
										if(!in_array($key,static::$config_exclude) && $key){
											if($key!=='worker_num'){
												$server->set([
												    $key => $pconfig[$key]+$pconfig[$orgikey]
												]);
											}
											
										}
									}

									if($workmode=='text'){;
										$server->set(array(
										    'open_eof_check' => true,
										    'package_eof' => "\r\n"
										  	
										));
									}


									foreach(($pconfig['bootstrap'] ?? []) as $bootstrap){
										$bootstrap::start($server);
									}
									foreach (($pconfig('autoload') ?? []) as $file) {
								        include_once $file;
								    }
								
									
									$instance = Container::make($class, array_merge(['type'=>'swoole','worker'=>$server,'timer'=>\Swoole\Timer::class],$pconfig['constructor'] ?? []));
									return $instance;
								}];
								
							break;
						}
					}
				}




				$process_counts = array_sum(array_column($process,'workers'));
				$processid = [];
				if($config['daemonize']===true){
					\Swoole\process::daemon();
				}
				$pool = new \Swoole\Process\Pool($process_counts);
				$pool->set(['enable_coroutine' => true]);
				for($i=0;$i<$process_counts;$i++){
					array_push($processid,$i);
				}

				foreach($process as $key=>$proc){
					$workers_num = $process[$key]['workers'];
					$process[$key]['workers_range'] = array_slice($processid, 0, $workers_num);
					
					
					if(!$process[$key]['workers_range']){
						echo 'worker Insufficient quantity '."\n";
						return null;
					}
					$processid = array_diff($processid,$process[$key]['workers_range']);

				}

				if($config['daemonize']===true){
					\Swoole\process::daemon(true,false);
				}
				$pool->on('Start', function ($server) use ($process,$config){
					if($config['daemonize']===true){
						\file_put_contents($config['pid_file'],$server->master_pid,LOCK_EX);
					}
					foreach($process as $proc){
						echo ''.$proc['protocol'].' Coroutine Server ['.$proc['name'].'] processes ['.$proc['workers'].'] '.(isset($proc['listen']) ? '['.$proc['listen'].':'.$proc['port'].']' : '['.$proc['protocol'].']').' Is Started'."\r\n";
					}
				});
				$pool->on('workerStart', function ($pool, $workid) use ($process,$config){
					static $workers,$process;
					static::$_workid = $workid;
					foreach($process as $proc){
						switch($proc['protocol']){
							//process
							case 'process':
								if(in_array($workid,$proc['workers_range'])){
									$workers = $pool->getProcess($workid);
									$proc['callback']($workers,$workid);
									/*
									\Swoole\Process::signal(SIGTERM,function($sig) use (&$workers,$proc,$pool,$workid){
										$workers->exit();
									});
									*/
								}
							break;
							case 'tcp':case 'text':
								if(in_array($workid,$proc['workers_range'])){
									$workers = $workers ?? new \Swoole\Coroutine\Server($proc['listen'],$proc['port'],$proc['ssl'],true);
									$instance = $proc['callback']($workers,$workid);
									if($instance){
										$workers->handle(function (\Swoole\Coroutine\Server\Connection $conn) use ($workid,$instance,$proc) {
											$instance->handle($conn);
									    });
									}
									/*
								    \Swoole\Process::signal(SIGTERM,function($sig) use (&$workers){
										$workers->shutdown();
									});
									*/
									$workers->start();
								}
							break;
							//http
							case 'http': case 'https': case 'websocket':
								if(in_array($workid,$proc['workers_range'])){
									$workers = new \Swoole\Coroutine\Http\Server($proc['listen'],$proc['port'],$proc['ssl'],true);
									$instance = $proc['callback']($workers,$workid);
									if($instance){
										$workers->handle('/', function ($request, $response) use ($workid,$instance,$proc) {
											$instance->handle($request, $response);
									    });
									}
									/*						
								    \Swoole\Process::signal(SIGTERM,function($sig) use (&$workers){
										$workers->shutdown();
									});
									*/
									$workers->start();
								}
							break;
						}
					}

					
				});

				$pool->on("WorkerStop", function ($pool, $workerId) {
				});
				
				$pool->start();
			}

			
			
		}
		if($frame=='workerman'){
			if(!class_exists(Workerman::class)){
				exit("\033[31;40mno workerman class!\033[0m\n");
			}
			cliCheck(['stream_socket_server','stream_socket_client','pcntl_signal_dispatch','pcntl_signal','pcntl_alarm','pcntl_fork','posix_getuid','posix_getpwuid','posix_kill','posix_setsid','posix_getpid','posix_getppid','posix_getpwnam','posix_getgrnam','posix_getgid','posix_setgid','posix_initgroups','posix_setuid','posix_isatty','pcntl_wait']);

			$config = self::$_config = Config::get('worker');
			if(!$config){
				exit("\033[31;40mno workerman config found!\033[0m\n");
			}
			static::$_maxRequestCount = $config['max_request'] ?? static::$_maxRequestCount;
			Workerman::$onMasterReload = function(){
			    //opcache_clean();
			};

			Workerman::$pidFile                      = $config['pid_file'];
			Workerman::$stdoutFile                   = $config['stdout_file'];
			TcpConnection::$defaultMaxPackageSize = $config['max_package_size'] ?? 10*1024*1024;


			$worker = $start_app ? static::$_worker = new Workerman($config['listen'], $config['context']) : null;
			if($start_app){
				$property_map = [
				    'name',
				    'count',
				    'user',
				    'group',
				    'reusePort',
				    'transport',
				];
				foreach ($property_map as $property) {
				    if (isset($config[$property])) {
				    	if($property=='count'){
				    		$config[$property] = $config[$property] ?? cpu_count();
				    	}
				        $worker->$property = $config[$property];
				    }
				}
				$worker->reusePort = true;
				$worker->onWorkerReload = function($worker){
					
				};
				$worker->onWorkerStart = function ($worker) use (&$config){
				    Config::get('app',null,true);
				    $config = Config::get('worker',null,true);
					\error_reporting(Config::get('app','error_types') ?? E_ALL &~E_NOTICE &~E_STRICT &~E_DEPRECATED);
					\set_error_handler(function ($level, $message, $file = '', $line = 0) {
				        if (\error_reporting(Config::get('app','error_types') ?? E_ALL &~E_NOTICE &~E_STRICT &~E_DEPRECATED) & $level) {
				            throw new \ErrorException($message, 0, $level, $file, $line);
				        }
				    });
				    foreach ((Config::get('autoload') ?? []) as $file) {
				        include_once $file;
				    }
				    foreach ((Config::get('bootstrap') ?? []) as $class_name) {
				        $class_name::start($worker);
				    }
			
					Middleware::load(Config::get('middleware',null,true) ?? []);
					\register_shutdown_function(function ($start_time) {
				        if (time() - $start_time <= 1) {
				            sleep(1);
				        }
				    }, time());
				    
				    if(Config::get('app','count')!==true){
				    	Stopwatch::$_framework = null;
				    }
				    $worker->onMessage = function($connection, $request) use ($config) {
				    	self::onMessage($connection, $request, $config);
				    };
				
				};
			}
			


			$process_config = Config::get('process', null, null, []);
			if(Config::get('app','cli_log')){
				$process_config = array_merge($process_config,['RCmaker_logger'  => [
			       'handler'  => \RC\Helper\Process\Logger::class,
			       'name'  => 'RCmaker_logger',
			       'listen'  => $config['logger_listen'],
			       'count'  => 1,
			       'reusePort' => true
			    ]]);
			}
			if(Config::get('queue','enable')){
				$process_config = array_merge($process_config, Config::get('queue','consumer_process') ?? []);
			}
			foreach ($process_config as $proc_name => $config) {
				if (isset($config['handler'])) {
					$processworker = new Workerman($config['listen'] ?? null, $config['context'] ?? []);
					$property_map = [
				        'count',
				        'user',
				        'group',
				        'reloadable',
				        'reusePort',
				        'transport',
				        'protocol',
				    ];
				    $processworker->name = $proc_name;
				    foreach ($property_map as $property) {
				        if (isset($config[$property])) {
				            $processworker->$property = $config[$property];
				        }
				    }
				    if(isset($config['ssl']) && $config['ssl']===true){
				    	$processworker->transport = 'ssl';
				    }
				    if(!class_exists($config['handler'])){
				    	$class_file = BASE_PATH.'/support/process/'.$config['handler'].'.php';
					    $class = "support\\process\\".$config['handler'];
					    if(!Container::loadClass($class_file,$class)){
			                exit("\033[31;40mprocess error: class {$class} not exists!\033[0m\n");
			    		}
				    }else{
				    	$class = $config['handler'];
				    }
				   
				    $processworker->onWorkerStart = function ($processworker) use ($worker,$config,$class) {
				    	foreach(($config['bootstrap'] ?? []) as $bootstrap){
							$bootstrap::start();
						}
						foreach (($config['autoload'] ?? []) as $file) {
					        include_once $file;
					    }
			    		$instance = Container::make($class, array_merge(['type'=>'workerman','worker'=>$processworker,'timer'=>Timer::class],$config['constructor'] ?? []));
			    		worker_bind($processworker, $instance);
				    };
				}
			}
			Stopwatch::$_framework = stopwatch('__frame__');
			Workerman::runAll();
		}
	}
}
?>