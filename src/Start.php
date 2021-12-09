<?php
use RC\Rcmaker;
use RC\Response;
use RC\Controller;
use RC\Stopwatch;
use RC\Container;
use RC\Config;
use RC\Worker;
use RC\Request;
use RC\Helper\Captcha\CaptchaBuilder;
use RC\Helper\Captcha\PhraseBuilder;
use RC\Helper\Validator;
use RC\Helper\AutoForm;
use RC\Helper\Xlsx;
use RC\Helper\Pdf;
use RC\Helper\QRcode;
use RC\Helper\Curl\Curl;
use RC\Helper\Curl\MultiCurl;
use RC\Http\Workerman\Response as ResponseObj;
use Jenssegers\Mongodb\Connection as mongodbConnection;
use RC\Helper\Db as SimpleDb;

define('IS_CLI',PHP_SAPI=='cli' ? 1 : 0);
define('BASE_PATH', ROOT_PATH);
define('FRAME_PATH', realpath(__DIR__));
define('IS_WIN',strstr(PHP_OS, 'WIN') ? 1 : 0);
define('VER','1.0');

function response($request,$body = '', $status = 200, $headers = array()){
   $response = $request->RCresponse ?? null;
   if($response){
       $response->findStaticFile = false;
       $response->staticFile = null;
       $response->_status = $status;
   }
   return new ResponseObj($request, $status, $headers, $body);  
}



function json($request,$data, $options = JSON_UNESCAPED_UNICODE){
    return new ResponseObj($request, 200, ['Content-Type' => 'application/json'], json_encode($data, $options));
}

function xml($request,$xml){
    if ($xml instanceof SimpleXMLElement) {
        $xml = $xml->asXML();
    }
    return new ResponseObj($request, 200, ['Content-Type' => 'text/xml'], $xml);
}

function jsonp($request,$data, $callback_name = 'callback'){
    if (!is_scalar($data) && null !== $data) {
        $data = json_encode($data);
    }
    return new ResponseObj($request, 200, [], "$callback_name($data)");
}

function redirect($request,$location, $status = 302, $headers = []){
    $response = new ResponseObj($request, $status, ['Location' => $location]);
    if (!empty($headers)) {
        $response->withHeaders($headers);
    }
    return $response;
}

function pdf($request,$config=[
    'orientation'=>'P',
    'unit'=>'mm',
    'format'=>'A4',
    'unicode'=>true,
    'encoding'=>'UTF-8',
    'diskcache'=>false
]){
    $tcpdf = new Pdf($config);
    $instance = $tcpdf->get_Instance();
    $instance->request = $request;
    return $instance;
}

function view($request,$template, $vars = []){
    static $handler;
    if (null === $handler) {
        $handler = Config::get('view','handler');
        if(!$handler){
            throw new \Exception('no view handler found!');
        }
    }
    $app = $request->app['app'];
    return $handler::render($template, $vars, $app);
}

function model($request,$model=null,$app=null,$constructor=[]){
    $app = $app ?? $request->app['app'];
    $model = $model ?? $request->app['controller'];

    if(strpos($model,'\\')===false){
        $class = "\\app\\$app\\model\\".$model;
    }else{
        $class = $model;
    }
    if(class_exists($class)){
        return Container::make($class,$constructor);
    }
    $class_file = BASE_PATH."/apps/$app/model/$model".'.php';
    if(!Container::loadClass($class_file,$class)){
        throw new \Exception($class_file.' can not be load!');
    }
    return Container::make($class,$constructor);
}

function qrcode($request,$text,$format = 'png',$outfile = false, $level = 0, $size = 3, $margin = 4,$saveandprint=false){
    static $QRcode;
    $QRcode = $QRcode ?? new QRcode();
    
    $header = ['Content-Type'=>'image/png'];
    $format = $format ?? 'png';
    $content = null;
    if($format=='png'){
        $header = ['Content-Type'=>'image/png'];
        \ob_start();
        $QRcode::png('Hello RCmaker',$outfile,$level,$size,$margin,$saveandprint);
        $content = \ob_get_clean();
        return response($request,$content,200,$header);
    }
    if($format=='text'){
        return json($request,$QRcode::text($text,$outfile,$level,$size,$margin));
    }
    if($format=='raw'){
        return $QRcode::raw($text,$outfile,$level,$size,$margin);
    }
    return null;
}
function setcookies($request,$keyvalue = [], $expires = 0, $path = '', $domain = '', $secure = false, $http_only = false){
    $RCresponse = $request->RCresponse;
    $id = $RCresponse->id();
    
    $frame = $RCresponse::$_frame;
    $return = true;
    if($frame=='workerman' || $frame=='swoole'){
        if($frame=='workerman'){
           $response = new ResponseObj($request);
        }else{
           $response =  $RCresponse::$_connection['id_'.$id];
        }
        if($response===null){
            return false;
        }
        if($keyvalue){
            foreach($keyvalue as $key=>$value){
                $return = $response->cookie($key,$value,$expires,$path,$domain,$secure,$http_only);
            }
            if($frame=='workerman'){
               $cookies = $response->getHeader('Set-Cookie');
               $RCresponse->setWorkermanCookie($cookies);
            }
            return $return;
        }
        return false;
        
    }
    foreach($keyvalue as $key=>$value){
        $return = setcookie($key,$value,$expires,$path,$domain,$secure,$http_only);
    }
    return $return;
}
function getcookies($request,$key = null, $default = null){
    return $request->cookie($key,$default);
}
function pinyin($charset = 'utf-8'){
    return new \RC\Helper\PinYin($charset);
}
function sessions($request,$key = null, $default = null){
    $session = $request->session();
    if (null === $key) {
        return $session->all();
    }
    if (\is_array($key)) {
        $session->put($key);
        return null;
    }
    return $session->get($key, $default);
}
function captcha($request,$name = 'captcha', $length = 5, $phrase=[], $charset = 'abcdefghijklmnpqrstuvwxyz123456789ABCDEFGHIJKLMNOPQRSTUVWXYZ'){
    static $builder,$PhraseBuilder;
    $key = (is_array($phrase) ? json_encode($phrase) : $phrase).'_'.$length.'_'.$charset;
    $phraseBuilder[$key] = $phraseBuilder[$key] ?? new PhraseBuilder($length, $charset);
    // 初始化验证码类
    $builder[$key] = new CaptchaBuilder(null,$phraseBuilder[$key]);
    // 生成验证码
    $builder[$key]->build(...$phrase);
    // 将验证码的值存储到session中
    sessions($request,[$name=>strtolower($builder[$key]->getPhrase())]);
    // 获得验证码图片二进制数据
    $img_content = $builder[$key]->get();
    // 输出验证码二进制数据
    return response($request,$img_content, 200, ['Content-Type' => 'image/jpeg']);
    
}

function download($request,$file,$download_name=''){
    return (new ResponseObj($request))->download($file,$download_name);
}

function autoForm($request,$vars){
    return new \RC\Helper\AutoForm($request,$vars);
}

function validator(){
    return new validator();
}

function xlsx(){
    static $xlsx;
    $xlsx = $xlsx ?? new Xlsx();
    return $xlsx;
}

function redis($engine='',$type=null,$id=1,$class=null,$clusterclass=null,$config=null){
    static $RD,$_config,$_class,$_cluster_class;
    if(!$engine){
        $engine = Config::get('redis','default_frame') ?? ''; 
    }
    if(!isset($RD[$engine])){
        if(!class_exists($class)){
            throw new \Exception('redis engine '.$engine.' class not load!');
        }
        if($config){
            $_config[$engine] = $config;
            $_class = $class;
            $_cluster_class = $clusterclass;
            $RD[$engine] = [];
        }
        return null;
    }else{
        $type = $type ?? 'default';
    }

    if(!isset($RD[$engine])){
        throw new \Exception('no db engine load!');
    }
    if($class){
        return ;
    }
    if($type){
        switch($engine){
            case 'raw':
                if(!isset($_config[$engine]['connections'][$type])){
                    throw new \Exception('no redis '.$type.' config set');
                }
                $conf = $_config[$engine]['connections'][$type];
                if($conf['type']=='cluster'){
                    $RD[$engine][$type]['id_'.$id] = $RD[$engine][$type]['id_'.$id] ?? new $_cluster_class($conf);
                }else{
                    $RD[$engine][$type]['id_'.$id] = $RD[$engine][$type]['id_'.$id] ?? new $_class($conf['host'],$conf['port'],$conf['password'] ?? '',$conf['database'],$conf['timeout'],$conf['retryInterval'],$conf['readTimeout']);
                }
                
            break;
        }
    }
    return $RD[$engine][$type]['id_'.$id];
}
function curl($multiCurl = false){
    static $curl;
    if(!$multiCurl){
        $curl['curl'] = $curl['curl'] ?? new Curl();
        return $curl['curl'];
    }
    $curl['multiCurl'] = $curl['multiCurl'] ?? new MultiCurl();
    return $curl['multiCurl'];
}

function simple_database($request,...$config){
    return new SimpleDb($request, ... $config);
}

function database($engine='',$type=null,$id=1,$class=null,$config=null,$support=null){
    static $DB,$_config,$_class,$_support,$_heartbeat;
    $_support[$engine] = $_support[$engine] ?? ($support ?? []);
    if(!$engine){
        $engine = Config::get('db','default_frame') ?? ''; 
    }
    if(!isset($DB[$engine])){
        
        if(!class_exists($class)){
            throw new \Exception('db engine '.$engine.' class not load!');   
        }
        if(!in_array($config['default'],$_support[$engine])){
            throw new \Exception('db driver ['.$config['default'].'] not supported by db engine ['.$engine.'] yet!');
        }
        $type = $config['default'];
        if($config){
            $_config[$engine] = [$type,$config];
            $_class[$engine] = $class;
            $DB[$engine] = [];
        }
    }else{
        $type = $type ?? $_config[$engine][0];
    }
    if(!isset($DB[$engine])){
        throw new \Exception('no db engine load!');
    }
    if($class){
        return ;
    }
    if($type){
        if(!in_array($type,$_support[$engine])){
            throw new \Exception('db driver ['.$type.'] not supported by db engine ['.$engine.'] yet!');
        }
        $default_type = $_config[$engine][0] ?? 'mysql';
        $config = $_config[$engine][1] ?? [];

        if(!$config){
            throw new \Exception('no db '.$type.' config set');
        }
        switch($engine){
            case 'medoo':
                $DB[$engine][$type]['id_'.$id] = $DB[$engine][$type]['id_'.$id] ?? new $_class[$engine]($config['connections'][$type]);
                if(!isset($_heartbeat[$engine][$type]['id_'.$id]) && IS_CLI && $type!=='mongodb'){
                    $_heartbeat[$engine][$type]['id_'.$id] = Config::get('app','cli_frame');
                    if($_heartbeat[$engine][$type]['id_'.$id]=='workerman'){
                        \Workerman\Timer::add(240, function () use ($DB,$engine,$type,$id){
                            $DB[$engine][$type]['id_'.$id]->query('select 1');   
                        });
                    }
                    if($_heartbeat[$engine][$type]['id_'.$id]=='swoole'){
                        \Swoole\Timer::tick(24000, function ()  use ($DB,$engine,$type,$id){
                            $DB[$engine][$type]['id_'.$id]->query('select 1');   
                        });
                    }
                }
            break;
            case 'think':
                if(!isset($DB[$engine][$type]['id_'.$id])){
                    $config['default'] = $type;
                    $DB[$engine][$type]['id_'.$id] = $_class[$engine];
                    $DB[$engine][$type]['id_'.$id]::setConfig($config);

                    if(!isset($_heartbeat[$engine][$type]['id_'.$id]) && IS_CLI && $type!=='mongodb'){
                        $_heartbeat[$engine][$type]['id_'.$id] = Config::get('app','cli_frame');
                        if($_heartbeat[$engine][$type]['id_'.$id]=='workerman'){
                            \Workerman\Timer::add(240, function ()  use ($DB,$engine,$type,$id){
                                $DB[$engine][$type]['id_'.$id]::query('select 1');   
                            });
                        }
                        if($_heartbeat[$engine][$type]['id_'.$id]=='swoole'){
                            \Swoole\Timer::tick(24000, function ()  use ($DB,$engine,$type,$id){
                                $DB[$engine][$type]['id_'.$id]::query('select 1');   
                            });
                        }
                    }
                }
            break;
            case 'laravel':
                if(!isset($DB[$engine][$type]['id_'.$id])){
                    $DB[$engine][$type]['id_'.$id] = new $_class[$engine];
                    $DB[$engine][$type]['id_'.$id]->getDatabaseManager()->extend('mongodb', function($config, $name) {
                        $config['name'] = $name;
                        return new mongodbConnection($config);
                    });
                    $DB[$engine][$type]['id_'.$id]->addConnection($config['connections'][$type]);
                    if (class_exists('\Illuminate\Events\Dispatcher')) {
                        $DB[$engine][$type]['id_'.$id]->setEventDispatcher(new \Illuminate\Events\Dispatcher(new \Illuminate\Container\Container));
                    }
                    $DB[$engine][$type]['id_'.$id]->setAsGlobal();
                    $DB[$engine][$type]['id_'.$id]->bootEloquent();

                    if(!isset($_heartbeat[$engine][$type]['id_'.$id]) && IS_CLI && $type!=='mongodb'){
                        $_heartbeat[$engine][$type]['id_'.$id] = Config::get('app','cli_frame');
                        if($_heartbeat[$engine][$type]['id_'.$id]=='workerman'){
                            \Workerman\Timer::add(240, function ()  use ($DB,$engine,$type,$id){
                                $DB[$engine][$type]['id_'.$id]::select('select 1');   
                            });
                        }
                        if($_heartbeat[$engine][$type]['id_'.$id]=='swoole'){
                            \Swoole\Timer::tick(2400, function ()  use ($DB,$engine,$type,$id){
                                $DB[$engine][$type]['id_'.$id]::select('select 1');   
                            });
                        }
                    }
                }  
            break;
        }
    }
    return $DB[$engine][$type]['id_'.$id];
}

function rcEnv(string $name = null, $default = null)
{
    return Config::getEnv($name, $default);
}

function cliCheck($check_func_map = []){
    $check = true; $returns = [];
    if($disable_func_string = ini_get("disable_functions"))
    {
        $disable_func_map = array_flip(explode(",", $disable_func_string));
    }
    if(!version_compare(phpversion(), "7.2.0", ">="))
    {
      $check = false;
      $returns[] = "\033[31;40mPHP version mast be >= 7.2.0\033[0m \n";
      $returns[] = "\033[31;40mPHP version mast be >= 7.2.0\033[0m \n";
      $returns[] = "\033[31;40mPHP version mast be >= 7.2.0\033[0m \n";
      $returns[] = "\033[31;40mPHP version mast be >= 7.2.0\033[0m \n";
    }
    foreach($check_func_map as $func)
    {
        if(isset($disable_func_map[$func]))
        {
            $check = false;
            $returns[] = "\033[31;40mFunction [$func] may be disabled. Please check disable_functions in [php.ini]\033[0m\n";
        }
    }

    if(!$check){
        foreach($returns as $return){
            echo $return;
        }
        exit;
    }
}


function opcache_clean(){
    if (function_exists('opcache_get_status')) {
        if ($status = opcache_get_status()) {
            if (isset($status['scripts']) && $scripts = $status['scripts']) {
                foreach (array_keys($scripts) as $file) {
                    opcache_invalidate($file);
                }
            }
        }
    }
}


function stopMaster(){
    Worker::stopMaster();
}

function stopwatch($eventname='__controller__'){
     if($eventname=='__frame__'){
        if(Stopwatch::$_framework){
            return Stopwatch::$_framework;
        }
     }
     if(!Stopwatch::isStarted($eventname)){
        return ['time'=>0,'memory'=>0];
     }
     $event = Stopwatch::stop($eventname);
     Stopwatch::reset();
     $getPeriods = $event->getPeriods();
     $Period = $getPeriods[0];
     $endtime = $Period->getEndTime();
     $memory = $event->getMemory();
     return ['time'=>$endtime,'memory'=>$memory];
}

function runtime_path(){
    return BASE_PATH."/runtime";
}

function public_path(){
    return BASE_PATH."/public";
}

function view_path(){
    return BASE_PATH."/view";
}

function worker_bind($worker, $class, $type='workerman') {
    $callback_map = [
        'onConnect',
        'onMessage',
        'onReceive',
        'onClose',
        'onError',
        'onBufferFull',
        'onBufferDrain',
        'onWorkerStop',
        'onWebSocketConnect',
        'onOpen',
        'onHandshake',
        'onStart',
        'onShutdown',
        'onWorkerExit',
        'onPacket',
        'nWorkerError',
        'onRequest'
    ];
    foreach ($callback_map as $name) {
        if (method_exists($class, $name)) {
            if($type=='workerman'){
               $worker->$name = [$class, $name]; 
            }
            if($type=='swoole'){
                $worker->on(substr($name,2),[$class, $name]);
            }
            
        }
    }
    if (method_exists($class, 'onWorkerStart')) {
        call_user_func([$class, 'onWorkerStart'], $worker);
    }
}

function cpu_count() {
    if (strtolower(PHP_OS) === 'darwin') {
        $count = shell_exec('sysctl -n machdep.cpu.core_count');
    } else {
        $count = shell_exec('nproc');
    }
    $count = (int)$count > 0 ? (int)$count : 4;
    return $count;
}

function getFilesize($size,$decimals=2){
   $p = 0;
   $format='bytes';
   if($size>0 && $size<1024){
     $p = 0;
     return number_format($size,$decimals).' '.$format;
   }
  if($size>=1024 && $size<pow(1024, 2)){
     $p = 1;
     $format = 'KB';
  }
  if ($size>=pow(1024, 2) && $size<pow(1024, 3)) {
    $p = 2;
    $format = 'MB';
  }
  if ($size>=pow(1024, 3) && $size<pow(1024, 4)) {
    $p = 3;
    $format = 'GB';
  }
  if ($size>=pow(1024, 4) && $size<pow(1024, 5)) {
    $p = 4;
    $format = 'TB';
  }
  $size /= pow(1024, $p);
  return number_format($size, $decimals).' '.$format;
}

if(Config::get('app','count')===true){
    Stopwatch::start('__frame__');
}

Rcmaker::start();
?>