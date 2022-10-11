<?php
namespace RC\Helper\Queue\RedisQueue;
use RC\Config;
use RC\Helper\Queue\RedisQueue\RedisClient;
class Client
{
    /**
     * @var Client[]
     */
    protected static $_connections = null;
    protected static $_support_type = ['redis','redisCluster'];

    /**
     * @param string $name
     * @return RedisClient
     */
    public static function connection($name = 'default', $type = 'workerman') {
        if (!isset(static::$_connections[$name])) {
            $config = Config::get('queue','connection');
            if (!isset($config[$name])) {
                throw new \RuntimeException("RedisQueue connection $name not found");
            }

            if(!in_array($config[$name]['type'],static::$_support_type)){
                throw new \RuntimeException("RedisQueue connection [".$config[$name]['type']."] is not supported");
            }
            if($config[$name]['type']=='redisCluster'){
                $config[$name]['type'] = 'cluster';
            }
            $options = $config[$name]['queue'] ?? []; 
            $host = $config[$name];
            unset($host['queue']);
            $client = new RedisClient($host, $options, $type);
            static::$_connections[$name] = $client;
        }
        
        return static::$_connections[$name];
    }

    /**
     * @param $name
     * @param $arguments
     * @return mixed
     */
    public static function __callStatic($name, $arguments)
    {
        return static::connection('default')->{$name}(... $arguments);
    }
}
?>