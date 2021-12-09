<?php
namespace RC;
use RC\Config;
use RC\Helper\Stopwatch\Stopwatch as Stopwatchobj;
global $_framework;
class Stopwatch{
	private static $_stopwatch = null;
	public static $_framework = [];
	public static $_controller = [];
	public static function lap($eventname){
		if(isset(static::$_stopwatch)){
			static::$_stopwatch->lap($eventname);
		}
	}

	public static function isStarted($eventname){
		if(isset(static::$_stopwatch)){
			return static::$_stopwatch->isStarted($eventname);
		}
		return false;
	}

	public static function openSection($id = null){
		static::$_stopwatch->openSection($id);
	}

	public static function start($eventname){
		if(!isset(static::$_stopwatch)){
			static::$_stopwatch = new Stopwatchobj();
		}
		static::$_stopwatch->start($eventname);
	}

	public static function reset(){
		if(isset(static::$_stopwatch)){
			static::$_stopwatch->reset();
		}
	}
	public static function stop($eventname){
		if(isset(static::$_stopwatch)){
			return static::$_stopwatch->stop($eventname);
		}
		return null;
	}

	public static function stopSection($eventname,string $id){
		static::$_stopwatch->stopSection($id);

	}

	public static function getSectionEvents($eventname,string $id){
		if(static::$_stopwatch){
			return static::$_stopwatch->getSectionEvents($id);
		}
		
	}
}
?>