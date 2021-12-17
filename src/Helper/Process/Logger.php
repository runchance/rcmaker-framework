<?php
namespace RC\Helper\Process;
class Logger
{
    protected $_timer = null;
    protected $_work = null;
    protected $_type = 'workerman';
    protected $_table = null;
    protected static $_data = [];
    protected static $_logDir = null;

    public function __construct($type,$work,$timer,$table=null){
        $this->_type = $type;
        $this->_timer = $timer;
        $this->_work = $work;
        $this->_table = $table;
        $this->start();
    }

    public function onMessage($connection, $data){
        $json_data = json_decode($data,true);
        $connection->send('ok');
        if(isset($json_data['type']) && $json_data['type']=='log'){
            static::$_data[] = $json_data['data'] ?? [];
        }
    }

    public function start(){
        if(!static::$_logDir){
            static::$_logDir = runtime_path() . '/logs';
            if (!is_dir(static::$_logDir)) {
                   mkdir(static::$_logDir, 0755, true);
            }
        }
        if($this->_type=='workerman'){
            $this->_timer::add(3, function () {
                $this->log();
            });

        }
        if($this->_type=='swoole'){
            $this->_timer::tick(3000, function () {
                  $table = $this->_table;
                  $format = [];
                  foreach($table as $key=> $res)
                  {
                    $log_file = static::$_logDir . '/rcmaker_access_['.$res['app'].']_'.date('Y-m-d').'.log';
                    $format[$log_file] = $format[$log_file] ?? '';
                    $format[$log_file].= date('Y-m-d H:i:s',$res['time']) . (IS_CLI ? '[CLI][swoole]' : '[fpm]') . " - ";
                    $format[$log_file].= '['.$res['ip'].']' . " - ";
                    $format[$log_file].= '"'.$res['method'].' '.$res['path'].' '.$res['protocol'].'"' . " - ";
                    $format[$log_file].= $res['status'].' ';
                    $format[$log_file].='"'.$res['referer'].'" ';
                    $format[$log_file].='"'.$res['agent'].'"';
                    $format[$log_file].="\n";
                    $table->del($key);
                  }
                  
                  foreach($format as $fileDir=>$log){
                      file_put_contents($fileDir, $log, FILE_APPEND|LOCK_EX); 
                  }
                  return;
            });
        }
      
    }

    private function log(){
        if(!static::$_data){
          return;
        }
        static::$_data = $res ?? static::$_data;
        $format = [];
        foreach(static::$_data as $key=>$res){
            $log_file = static::$_logDir . '/rcmaker_access_['.$res['app'].']_'.date('Y-m-d').'.log';
            $format[$log_file] = $format[$log_file] ?? '';
            $format[$log_file].= date('Y-m-d H:i:s',$res['time']) . (IS_CLI ? '[CLI][workerman]' : '[fpm]') . " - ";
            $format[$log_file].= '['.$res['ip'].']' . " - ";
            $format[$log_file].= '"'.$res['method'].' '.$res['path'].' '.$res['protocol'].'"' . " - ";
            $format[$log_file].= $res['status'].' ';
            $format[$log_file].='"'.$res['referer'].'" ';
            $format[$log_file].='"'.$res['agent'].'"';
            $format[$log_file].="\n";
            unset(static::$_data[$key]);
        }
        foreach($format as $fileDir=>$log){
           file_put_contents($fileDir, $log, FILE_APPEND|LOCK_EX); 
        }
        
        return ;
    }
}
?>