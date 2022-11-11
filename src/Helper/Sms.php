<?php
namespace RC\Helper;
class Sms{

	protected static $methods = ['post','get'];
	protected static $msg = array(
		"method_error"=>"参数传递方法不被支持",
		"no_mobile"=>"未传入手机号",
		'no_code'=>'未传入验证码',
		'not_support_type'=>'不支持的验证码类型',
		'invalid_code'=>'验证码不正确',
		'expired_code'=>'验证码过期'
	);
	protected static $cache = null;
	protected $config = [];

    protected $cachePrefix = 'rcsms';
    // 验证码字符池
    protected $character = '0123456789abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ';
    // 验证码过期时间（s），默认 3 分钟
    protected $expire = 180;
    // 验证码位数
    protected $length = 6;
    // 验证码类型
    protected $type = 1;
    // 验证码
    protected $code = '';
    // 场景
    protected $scene = '';
    // 错误信息
    protected $error = '';
    // 手机号字段名
    protected $mobileKey = 'mobile';
    // 验证码字段名
    protected $codeKey = 'code';
    // 传递参数方法
    protected $method = 'post';
     //ip验证
    protected $ipCheck = true;
    //验证成功后自动删除缓存
    protected $autoDelte = true;
    // 手动传入手机号
    protected $_mobile;
    // 手动传入验证码
    protected $_code;




    protected $request = null;

    /**
     * 架构方法，动态配置
     * TpSms constructor.
     * @param array $config
     */
    public function __construct($request, array $config = [], $method = 'get', $cache = null)
    {
    	$this->request = $request;
    	$this->cache = $cache;
        $this->config = $config;
        $this->method = $method;
        if(!in_array($this->method,static::$methods)){
			throw new \Exception((string)static::$msg['method_error'] ?? null);
		}
        foreach ($config as $key => $val) {
            if (property_exists($this, $key)) {
                $this->{$key} = $val;
            }
        }
    }

    /**
     * 设置场景值
     * @param string $scene
     * @return $this
     */
    public function scene(string $scene)
    {
        $this->scene = $scene;
        return $this;
    }

    /**
     * 手动传入手机号
     * @param string $mobile
     * @return $this
     */
    public function mobile(string $mobile)
    {
        $this->_mobile = $mobile;
        return $this;
    }

    /**
     * 手动传入验证码
     * @param string $code
     * @return $this
     */
    public function code(string $code)
    {
        $this->_code = $code;
        return $this;
    }

    /**
     * 指定有效时间，单位秒
     * @param int $code
     * @return $this
     */
    public function exp(int $second)
    {
        $this->expire = $second;
        return $this;
    }

    /**
     * 生成验证码
     * @return string
     * @throws \Exception
     */
    public function create(): string
    {
        $mobile = $this->_mobile ?? $this->request->{$this->method}($this->mobileKey);

        if (!$mobile) {
        	throw new \Exception((string)static::$msg['no_mobile'] ?? null);
        }

        switch ($this->type){
            case 1:
            // 纯数字型验证码
                $range = [0,9];
                break;
            case 2:
            // 纯小写字母型验证码
                $range = [10,35];
                break;
            case 3:
            // 纯大写字母型验证码
                $range = [36,61];
                break;
            case 4:
            // 数字与小写字母混合型验证码
                $range = [0,35];
                break;
            case 5:
            // 数字与大写字母混合型验证码
                $this->character = strtoupper($this->character);
                $range = [0,35];
                break;
            case 6:
            // 小写字母与大写字母混合型验证码
                $range = [10,61];
                break;
            case 7:
            // 数字、小写字母和大写字母混合型验证码
                $range = [0,61];
                break;
            default:
            // 报错：不支持的验证码类型
                throw new \Exception((string)static::$msg['not_support_type'] ?? null);
        }
            // 拼接验证码
        for ($i = 0; $i < $this->length; $i++){
            $this->code .= $this->character[random_int($range[0],$range[1])];
        }
            // 缓存
        $cacheKey = $this->cachePrefix.$this->scene.$mobile;
            // 增加ip验证
        $cacheVal = $this->code.($this->ipCheck ? ip2long($this->request->ip()) : '');
        $this->cache->set($cacheKey,$cacheVal,$this->expire);
        return $this->code;
    }

    /**
     * 获取错误信息
     * @return string
     */
    public function getErrorMsg(): string
    {
        return $this->error;
    }

    /**
     * 验证码验证
     * @return bool
     */
    public function check(): bool
    {
        $mobile = $this->_mobile ?? $this->request->{$this->method}($this->mobileKey);
        if (!$mobile) {
            throw new \Exception((string)static::$msg['no_mobile'] ?? null);
        }
        $code = $this->_code ?? $this->request->{$this->method}($this->codeKey);
        if (!$code) {
            throw new \Exception((string)static::$msg['no_code'] ?? null);
        }
            // 缓存
        $cacheKey = $this->cachePrefix.$this->scene.$mobile;
            // 获取缓存验证码
        $cacheCode = $this->autoDelte ? $this->cache->pull($cacheKey) : $this->cache->get($cacheKey);
        if($cacheCode){
            // 增加ip验证
            if ($cacheCode === ($this->ipCheck ? $code.ip2long($this->request->ip()) : $code)){

                return true;
            }
            $this->error = (string)static::$msg['invalid_code'] ?? null;
            return false;
        } else {
            $this->error = (string)static::$msg['expired_code'] ?? null;
            return false;
        }
    }
}
?>