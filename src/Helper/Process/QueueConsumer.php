<?php
namespace RC\Helper\Process;
use RC\Container;
use RC\Helper\Queue\RedisQueue\Client;
class QueueConsumer
{
    /**
     * @var string
     */
    protected $_consumerDir = '';

    protected $_timer = null;

    protected $_work = null;
    /**
     * StompConsumer constructor.
     * @param string $consumer_dir
     */
    public function __construct($type,$work,$timer,$consumer_dir = '')
    {
        $this->_consumerDir = $consumer_dir;
        $this->_type = $type;
        $this->_timer = $timer;
        $this->work = $work;
        $this->start();
    }

    /**
     * onWorkerStart.
     */
    public function start()
    {
        if (!is_dir($this->_consumerDir)) {
            echo "Consumer directory {$this->_consumerDir} not exists\r\n";
            return;
        }
        $dir_iterator = new \RecursiveDirectoryIterator($this->_consumerDir);
        $iterator = new \RecursiveIteratorIterator($dir_iterator);
        foreach ($iterator as $file) {
            if (is_dir($file)) {
                continue;
            }
            $fileinfo = new \SplFileInfo($file);
            $ext = $fileinfo->getExtension();
            if ($ext === 'php') {
                $class = str_replace('/', "\\", substr(substr($file, strlen(base_path())), 0, -4));
                $consumer = Container::get($class);
                $connection_name = $consumer->connection ?? 'default';
                $consumer->worker_id = $this->work->id;
                $queue = $consumer->queue;
                $connection = Client::connection($connection_name,$this->_type);
                $connection->subscribe($queue,[$consumer, 'handle']);
            }
        }

    }
}