<?php
namespace RC\Helper\Process;
class FileMonitor
{
    /**
     * @var array
     */
    protected $_paths = [];

    /**
     * @var array
     */
    protected $_extensions = [];

    protected $_timer = null;

    protected $_work = null;

    protected $_type = 'workerman';

    protected $_staticmode = true;

    protected static $_static = [];

    /**
     * FileMonitor constructor.
     * @param $monitor_dir
     * @param $monitor_extensions
     */
    public function __construct($type,$work,$timer,$monitor_dir,$monitor_extensions,$staticmode=true)
    {
        $this->_type = $type;
        $this->_paths = (array)$monitor_dir;
        $this->_extensions = $monitor_extensions;
        $this->_timer = $timer;
        $this->_work = $work;
        $this->_staticmode = $staticmode;
        $this->start();

        
        
    }

    public function start(){
        if($this->_type=='workerman'){
            $this->_timer::add(1, function () {
                foreach ($this->_paths as $path) {
                    $this->check_files_change($path);
                }
            });
        }
        if($this->_type=='swoole'){
            $this->_timer::tick(1000,function(){
                foreach ($this->_paths as $path) {
                    $this->check_files_change($path);
                }
            });

        }
    }

    /**
     * @param $monitor_dir
     */
    public function check_files_change($monitor_dir)
    {
        static $last_mtime;
        if (!$last_mtime) {
            $last_mtime = time();
        }
        clearstatcache();
        if (!is_dir($monitor_dir)) {
            if (!is_file($monitor_dir)) {
                return;
            }
            if($this->_staticmode){
                static::$_static[$monitor_dir]['iterator'] = static::$_static[$monitor_dir]['iterator'] ?? [new \SplFileInfo($monitor_dir)];
                $iterator = static::$_static[$monitor_dir]['iterator'];
            }else{
                $iterator = [new \SplFileInfo($monitor_dir)]; 
            }
        } else {
            // recursive traversal directory
            if($this->_staticmode){
                static::$_static[$monitor_dir]['dir_iterator'] = static::$_static[$monitor_dir]['dir_iterator'] ?? new \RecursiveDirectoryIterator($monitor_dir);
                $dir_iterator = static::$_static[$monitor_dir]['dir_iterator'];
                static::$_static[$monitor_dir]['iterator'] = static::$_static[$monitor_dir]['iterator'] ?? new \RecursiveIteratorIterator($dir_iterator);  
                
                $iterator = static::$_static[$monitor_dir]['iterator'];
            }else{
              $dir_iterator = new \RecursiveDirectoryIterator($monitor_dir);
              $iterator = new \RecursiveIteratorIterator($dir_iterator);  
            }
            
        }
        foreach ($iterator as $file) {
            /** var SplFileInfo $file */
            if (is_dir($file)) {
                continue;
            }
            // check mtime
            $ext = $file->getExtension();
            if ($last_mtime < $file->getMTime() && (in_array($ext, $this->_extensions) || $file->getBasename()==='.env')) {
                $var = 0;
                if($ext=='php'){
                    exec(PHP_BINDIR . "/php -l " . $file, $out, $var);
                    if ($var) {
                        $last_mtime = $file->getMTime();
                        continue;
                    }
                }
                $filePathName = $file->getPathname();
                echo $file . " update and reload\n";
                // send SIGUSR1 signal to master process for reload
                posix_kill(posix_getppid(), \SIGUSR1);
                if (function_exists('opcache_invalidate')) {
                    opcache_invalidate($file,true);
                }
                $last_mtime = $file->getMTime();
                break;
            }
        }
    }
}
?>