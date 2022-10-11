<?php
/**
 * This file is part of webman.
 *
 * Licensed under The MIT License
 * For full copyright and license information, please see the MIT-LICENSE.txt
 * Redistributions of files must retain the above copyright notice.
 *
 * @author    walkor<walkor@workerman.net>
 * @copyright walkor<walkor@workerman.net>
 * @link      http://www.workerman.net/
 * @license   http://www.opensource.org/licenses/mit-license.php MIT License
 */
namespace RC\Helper\Queue\RedisQueue;
use RC\Config;
/**
 * Class RedisQueue
 * @package support
 *
 * Strings methods
 * @method static void send($queue, $data, $delay=0)
 */
class Redis
{
    /**
     * @var RedisConnection[]
     */
    protected static $_connections = [];

    /**
     * @param string $name
     * @return RedisConnection
     */
    public static function connection($name = 'default') {
         if (!isset(static::$_connections[$name])) {
            $configQueue = Config::get('queueRedis');
            if (!isset($configQueue['connection'][$name])) {
                throw new \RuntimeException("RedisQueue connection $name not found");
            }
            $redisConfig = $configQueue['connection'][$name];
            unset($redisConfig['queue']);
            $redisConfig['host'] = $redisConfig['host'] ?? '127.0.0.1';
            $redisConfig['port'] = $redisConfig['port'] ?? 6379;
            $redisConfig['expire'] = $redisConfig['expire'] ?? 0;
            $queue = $configQueue[$name]['queue'] ?? [];
            $conn = static::$_connections[$name] = redis('raw','redisQueue',1,null,null,$redisConfig);
            static::$_connections[$name]->execCommand = function($command, ...$args) use ($conn){
                return $conn->{$command}(...$args);
            };
            static::$_connections[$name]->send = function($queue, $data, $delay = 0) use ($conn){
                $queue_waiting = '{redis-queue}-waiting';
                $queue_delay = '{redis-queue}-delayed';
                $now = time();
                $package_str = json_encode([
                    'id'       => time().rand(),
                    'time'     => $now,
                    'delay'    => $delay,
                    'attempts' => 0,
                    'queue'    => $queue,
                    'data'     => $data
                ]);
                if ($delay) {
                    return (bool)$conn->execCommand('zAdd' ,$queue_delay, $now + $delay, $package_str);
                }
                return (bool)$conn->execCommand('lPush', $queue_waiting.$queue, $package_str);
            };
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