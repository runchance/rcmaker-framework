<?php
namespace RC\Helper;
use RC\Config;
use RC\FileOperator as file;
use RC\Exception\AuthException;
use RC\Helper\Jwt\BeforeValidException;
use RC\Helper\Jwt\ExpiredException;
use RC\Helper\Jwt\JWT;
use RC\Helper\Jwt\Key;
use RC\Helper\Jwt\SignatureInvalidException;
use UnexpectedValueException;
class Token{
	use file;
	protected $request = null;

	protected $config = null;

	protected $cache = null;

    protected $guard = null;

    private static $_config = [
        'signer' => 'HS256',
        'type' => 'Bearer',
        'keyName' => 'token',
        'access_secret_key' => 'rcmaker2022authaccess5c6s7q',
        'access_expired' => 7200,
        'refresh_secret_key' => 'rcmaker2022authrefresh9z8s9w',
        'refresh_expired' => 604800,
        'refresh_disable' => false,
        'iss' => 'rcmaker.runchance.com',
        'leeway' => 60,
        'is_single_device' => false,
        'cache_token_time' => 604800,
        'cache_token_prefix' => 'RC:AUTH:TOKEN:',
        'access_private_key' => null,
        'access_public_key' => null,
        'refresh_private_key' => null,
        'refresh_public_key' => null
    ];

    private const ACCESS_TOKEN = 1;

    private const REFRESH_TOKEN = 2;

    protected static $msg = null;

    public function __construct($request,$guard,$cache){ //初始化参数
    	$this->request = $request;
    	$this->config = array_merge(static::$_config,Config::get('token',$guard));
        $this->guard = $guard;
    	$this->cache = $cache;
        static::$msg = static::$msg ?? (config::get('token','msg') ?? []);
    }

    private static function readFile($filename){
    	static $file;
    	$file[$filename] = $file[$filename] ?? file::read($filename);
    	return $file[$filename];
    }

    public function set(array $data): array{
        return $this->setToken($data);
    }

    public function setToken(array $data): array
    {
        if (!isset($data['key'])) {
            throw new AuthException(static::$msg['key_not_exist']);
        }
        $config = $this->config;
        $config['access_expired'] = $data['access_expired'] ?? $config['access_expired'];
        $config['refresh_expired'] = $data['refresh_expired'] ?? $config['refresh_expired'];
        $payload = $this->generatePayload($config, $data);
        $secretKey = $this->getPrivateKey();
        $token = [
            'guard' => $this->guard,
            'token_type' => $this->config['type'],
            'expires_in' => $config['access_expired'],
            'access_token' => $this->makeToken($payload['accessPayload'], $secretKey, $config['signer'])
        ];
        if (!isset($config['refresh_disable']) || (isset($config['refresh_disable']) && $config['refresh_disable'] === false)) {
            $refreshSecretKey = $this->getPrivateKey(self::REFRESH_TOKEN);
            $token['refresh_token'] = $this->makeToken($payload['refreshPayload'], $refreshSecretKey, $config['signer']);
        }
        $this->setClient($token['access_token']);
        return $token;
    }

    public function reSet(){
        return $this->refreshToken();
    }

    public function reSetToken(){
        return $this->refreshToken();
    }

    public function refreshToken(): array
    {
        $token = $this->getClientToken();
        $config = $this->config;
        try {
            $data = $this->verifyToken($token, self::REFRESH_TOKEN);
        } catch (SignatureInvalidException $signatureInvalidException) {
            throw new AuthException(static::$msg['refresh_token_valid'] ?? 'Invalid refresh token');
        } catch (BeforeValidException $beforeValidException) {
            throw new AuthException(static::$msg['refresh_token_invalid_yet'] ?? 'The refreshed token is not yet valid');
        } catch (ExpiredException $expiredException) {
            throw new AuthException(static::$msg['refresh_token_expired'] ?? 'Refreshed token is expired');
        } catch (UnexpectedValueException $unexpectedValueException) {
            throw new AuthException(static::$msg['refresh_token_format_error'] ?? 'Refreshed token format error');
        } catch (JwtCacheTokenException | \Exception $exception) {
            throw new AuthException($exception->getMessage());
        }
        $payload = $this->generatePayload($config, $data['data']);
        $secretKey = $this->getPrivateKey();
        $data['exp'] = time() + $config['access_expired'];
        $newToken['access_token'] = $this->makeToken($data, $secretKey, $config['signer']);
        if (!isset($config['refresh_disable']) || (isset($config['refresh_disable']) && $config['refresh_disable'] === false)) {
            $refreshSecretKey = $this->getPrivateKey(self::REFRESH_TOKEN);
            $payload['exp'] = time() + $config['refresh_expired'];
            $newToken['refresh_token'] = $this->makeToken($payload['refreshPayload'], $refreshSecretKey, $config['signer']);
        }
        $this->setClient($newToken['access_token']);
        return $newToken;
    }

    private function setClient($token){
        switch(strtolower($this->config['type'])){
            case 'cookie':
                $this->request->setcookies([$this->config['keyName']=>$token]);
            break;
            case 'session':
                $session = $this->request->session();
                $session->set($this->config['keyName'],$token);
            break;
        }
        return true;
    }

    private function getClientToken(): string
    {
        $withoutMsg = static::$msg['request_without_info'] ?? 'request without information';
        $illegalMsg = static::$msg['illegal_info'] ?? 'illegal information';
        switch(strtolower($this->config['type'])){
            case 'bearer':
                $authorization = $this->request->header('authorization');
                if (!$authorization || 'undefined' == $authorization) {
                    throw new AuthException($withoutMsg.' [HEADER] authorization');
                }

                if (self::REFRESH_TOKEN != substr_count($authorization, '.')) {
                    throw new AuthException($illegalMsg.' authorization');
                }

                if (2 != count(explode(' ', $authorization))) {
                    throw new AuthException($illegalMsg.' authorization Bearer');
                }

                [$type, $token] = explode(' ', $authorization);
                if ('Bearer' !== $type) {
                    throw new AuthException($illegalMsg.' authorization Bearer');
                }
                if (!$token || 'undefined' === $token) {
                    throw new AuthException($withoutMsg.' [HEADER] authorization');
                }
            break;
            case 'header':
                $token = $this->request->header($this->config['keyName'],null);
                if (!$token || $token===null) {
                    throw new AuthException($withoutMsg.' [HEADER] '.$this->config['keyName']);
                }
            break;
            case 'get':
                $token = $this->request->get($this->config['keyName'],null);
                if (!$token || $token===null) {
                    throw new AuthException($withoutMsg.' [GET] '.$this->config['keyName']);
                }
            break;
            case 'post':
                $token = $this->request->post($this->config['keyName'],null);
                if (!$token || $token===null) {
                    throw new AuthException($withoutMsg.' [POST] '.$this->config['keyName']);
                }
            break;
            case 'cookie':
                $token = $this->request->cookie($this->config['keyName'],null);
                if (!$token || $token===null) {
                    throw new AuthException($withoutMsg.' [COOKIE] '.$this->config['keyName']);
                }
            break;
            case 'session':
                $token = $this->request->sessions($this->config['keyName'],null);
                if (!$token || $token===null) {
                    throw new AuthException($withoutMsg.' [SESSION] '.$this->config['keyName']);
                }
            break;
        }
        
        return $token;
    }

    public function get($key = null){
        return $this->getData($key);
    }

    public function getData($key = null): array
    {
        return $key ? ($this->verify()['data'][$key] ?? null) : $this->verify()['data'];
    }

    public function getToken($key = null){
        return $key ? ($this->verify()[$key] ?? null) : $this->verify();
    }


    public function verify(int $tokenType = self::ACCESS_TOKEN, string $token = null): array
    {
        $token = $token ?? $this->getClientToken();
        try {
            return $this->verifyToken($token, $tokenType);
        } catch (SignatureInvalidException $signatureInvalidException) {
            throw new AuthException(static::$msg['signature_verification_failed'] ?? 'Signature verification failed');
        } catch (BeforeValidException $beforeValidException) {
            throw new AuthException(static::$msg['signature_verification_before_invalid'] ?? 'Signature verification is not valid yet');  
        } catch (ExpiredException $expiredException) {
            throw new AuthException(static::$msg['access_expired'] ?? 'Access token expired'); 
        } catch (UnexpectedValueException $unexpectedValueException) {
            throw new AuthException(static::$msg['token_format_error'] ?? 'Access token format error');
        } catch (JwtCacheTokenException | \Exception $exception) {
            throw new AuthException($exception->getMessage());
        }
    }

    private function verifyToken(string $token, int $tokenType): array
    {
        $config = $this->config;
        $publicKey = self::ACCESS_TOKEN == $tokenType ? $this->getPublicKey() : $this->getPublicKey(self::REFRESH_TOKEN);
        JWT::$leeway = $config['leeway'];
        $decoded = JWT::decode($token, new Key($publicKey, $config['signer']));
        $token = json_decode(json_encode($decoded), true);
        if ($config['is_single_device']) {
            $key = (string) $token['data']['key'].'_'.$this->guard;
        	$cacheKey = $config['cache_token_prefix'].$key.':'.$this->request->ip();
	        if (!$this->cache->has($cacheKey)) {
	            throw new AuthException(static::$msg['signed_in_on_another_device'] ?? 'Signed in on another device');
	        }
        }
        return $token;
    }

    private function getPublicKey(int $tokenType = self::ACCESS_TOKEN): string
    {

        $config = $this->config;
        switch (strtoupper($config['signer'])) {
            case 'HS256':
            case 'HS384':
            case 'HS512':
                $key = self::ACCESS_TOKEN == $tokenType ? $config['access_secret_key'] : $config['refresh_secret_key'];
                break;
            case 'RS256':
            case 'RS384':
            case 'RS512':
            case 'ES256':
            case 'ES384':
            case 'ES512':
            case 'EDDSA':
                $key = self::ACCESS_TOKEN == $tokenType ? static::readFile($config['access_public_key']) : static::readFile($config['refresh_public_key']);
                break;
            default:
                $key = $config['access_secret_key'];
        }

        return $key;
    }


    private static function makeToken(array $payload, string $secretKey, string $signer): string
    {
        return JWT::encode($payload, $secretKey, $signer);
    }

    
    private function getPrivateKey(int $tokenType = self::ACCESS_TOKEN): string
    {

        $config = $this->config;
        switch (strtoupper($config['signer'])) {
            case 'HS256':
            case 'HS384':
            case 'HS512':
                $key = self::ACCESS_TOKEN == $tokenType ? $config['access_secret_key'] : $config['refresh_secret_key'];
                break;
            case 'RS256':
            case 'RS384':
            case 'RS512':
            case 'ES256':
            case 'ES384':
            case 'ES512':
            case 'EDDSA':
                $key = self::ACCESS_TOKEN == $tokenType ? static::readFile($config['access_private_key']) : static::readFile($config['refresh_private_key']);
                break;
            default:
                $key = $config['access_secret_key'];
        }

        return $key;
    }

    private function generatePayload(array $config, array $data): array
    {
        if ($config['is_single_device']) {
            $this->cacheToken([
                'key' => $data['key'].'_'.$this->guard,
                'ip' => $this->request->ip(),
                'data' => json_encode($data),
                'cache_token_time' => $config['cache_token_time'],
                'cache_token_prefix' => $config['cache_token_prefix']
            ]);
        }
        $basePayload = [
            'iss' => $config['iss'],
            'iat' => time(),
            'exp' => time() + $config['access_expired'],
            'data' => $data
        ];
        $resPayLoad['accessPayload'] = $basePayload;
        $basePayload['exp'] = time() + $config['refresh_expired'];
        $resPayLoad['refreshPayload'] = $basePayload;

        return $resPayLoad;
    }


    public function cacheToken(array $arr): void
    {
        $cacheKey = $arr['cache_token_prefix'].$arr['key'];
        $items = $this->cache->getTagItems($cacheKey);
        if($items){
            $this->cache->tag($cacheKey)->clear();
        }
        $this->cache->tag($cacheKey)->set($cacheKey.':'.$arr['ip'], $arr['data'], $arr['cache_token_time']);
    }

}
?>