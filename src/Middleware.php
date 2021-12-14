<?php
namespace RC;
use RC\Container;
class Middleware{
	protected static $_instances = [];


    public static function load($all_middlewares){
        foreach($all_middlewares as $type=>$middlewaretypes){
            if($type=='middleware'){
                foreach ($middlewaretypes as $app=>$middlewares) {
                    foreach ($middlewares as $item) {
                        if(is_array($item)){
                            $class_file = BASE_PATH.'/support/middleware/'.$item['handle'].'.php';
                            $class = "support\\middleware\\".$item['handle'];
                            if(!class_exists($class)){
                                if(!Container::loadClass($class_file ,$class)){
                                    stopMaster();
                                    throw new \Exception("Class '$class' not exists");
                                }
                            } 
                        }else{
                           $class =  $item;
                        }
                        if (\method_exists($class, 'handle')){
                            static::$_instances[$app][] = [Container::get($class),'handle'];
                        } 
                    }
                }
            }
            if($type=='static_middleware'){
                foreach ($middlewaretypes as $item) {
                    if(is_array($item)){
                        $class_file = BASE_PATH.'/support/middleware/'.$item['handle'].'.php';
                        $class = "support\\middleware\\".$item['handle'];
                        if(!class_exists($class)){
                            if(!Container::loadClass($class_file,$class)){
                                stopMaster();
                                throw new \Exception("Class '$class' not exists");
                            }
                        }
                    }else{
                        $class =  $item;
                    }
                    if (\method_exists($class, 'handle')){
                        static::$_instances['__static__'][] = [Container::get($class),'handle'];
                    }
                }
            }
        }
    }

    public static function getMiddleware($app) {
        $global_middleware = static::$_instances[''] ?? [];
        if ($app === '') {
            return \array_reverse($global_middleware);
        }
        $app_middleware = static::$_instances[$app] ?? [];
        return \array_reverse(\array_merge($global_middleware, $app_middleware));
    }

    public static function hasMiddleware($app){
        return isset(static::$_instances[$app]);
    }

}
?>