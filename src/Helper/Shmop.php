<?php
namespace RC\Helper;


class Shmop{

    protected static $id;

    protected static $shmId = [];

    protected static $dataSize;

    protected static $size = 1024;

    protected static $permission = 0755;

    protected static $config = [];

    public function __construct($config = []){
        if($config){
            static::$config = array_merge(static::$config, $config);
        }

        static::init();
    }

    /**
     * init of shmop
     * check used with id
     */
    public static function init(){
        if(isset(static::$config['id'])){
            static::$id = static::$config['id'];
        }else{
            static::generateId();
        }

        if(isset(static::$config['permission'])){
            static::$permission = static::$config['permission'];
        }

        if(isset(static::$config['size'])){
            static::$size = static::$config['size'];
        }
        if(static::exists(static::$id)) {
            static::$shmId[static::$id] = shmop_open(static::$id, "w", 0, 0);
        }
    }

     /**
     * $this->shmId
     * if used  shmId != false
     */
    public static function checkShmopArea(){
        static::$shmId[static::$id] = shmop_open(static::$id, 'w', 0, 0);
    }


    public static function exists($id){
        $status = @shmop_open($id, "a", 0, 0);
        return $status;
    }


    /**
     * write date to shmop
     * @param $data
     * @return bool
     * @throws \Exception
     */
    public static function write($data){
        if(!$data){
            return false;
        }

        if(is_array($data)){
            $data = json_encode($data);
        }

        $data = gzcompress($data, 9);

        $dataSize = mb_strlen($data, 'utf-8');

        // if($this->shmId){
        //     // this memory area used
        //     $this->clean();
        //     $this->close();
        // }
        if(!isset(static::$shmId[static::$id])){
            static::$shmId[static::$id] = shmop_open(static::$id, "c", static::$permission, static::$size);
        }
        shmop_write(static::$shmId[static::$id], $data, 0);
        return true;
    }

    /**
     * get data
     * @return string
     * @throws \Exception
     */
    public static function read(){
        ini_set('memory_limit','2048M');
        if(!isset(static::$shmId[static::$id])){
            return null;
        }
        $size = shmop_size(static::$shmId[static::$id]);

        $data = shmop_read(static::$shmId[static::$id], 0, $size);

        return trim(gzuncompress($data));
    }

    /**
     * clean data
     */
    public static function delete(){
        if(isset(static::$shmId[static::$id])){
            
            shmop_delete(static::$shmId[static::$id]);
            static::$shmId[static::$id] = null;
        }
        
    }

    /**
     * close shmop
     */
    public static function close(){
        shmop_close(static::$shmId[static::$id]);
    }

    /**
     * @return mixed
     */
    public static function getId(){
        return static::$id;
    }

    /**
     * use ftok get a number
     */
    protected static function generateId(){
        $id = ftok(__FILE__, 'b');
        static::$id = $id;
    }

    public function __destruct(){
        // TODO...
    }
}