<?php
namespace RC;
trait FileOperator{
    public static function write($path=null,$data=null,$mkdir=false)
    {	
    	if($path!==null && $data!==null){
    		$dir = dirname($path);
    		if(is_dir($dir)){
    			return file_put_contents($path,$data);
    		}
    		if($mkdir){
				self::mkdir($dir);
				return file_put_contents($path,$data);
			}
    	}        
    }
    public static function mkdir($path, int $mode = 0755, bool $recursive = true){
       if(is_array($path)){
            $maked = true;
            foreach($path as $dir){
                if(!is_dir($dir)){
                    if(!mkdir($dir,$mode,$recursive)){
                        $maked = false;
                    }
                } 
            }
            return $maked;
       }
       return \mkdir($path,$mode,$recursive);
    }
    public static function read($filename,$use_include_path = null,$context = null,$offset = 0,$maxlen = 0){
        if(\is_file($filename)){
            return ($maxlen > 0) ? file_get_contents($filename,$use_include_path,$context,$offset,$maxlen)  : file_get_contents($filename,$use_include_path,$context,$offset);
        }
        return false;
    }
    public static function move($data)
    {

    }
    public static function delete($data)
    {

    }
    public static function copy($data)
    {

    }
}
?>