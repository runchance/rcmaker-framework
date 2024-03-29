<?php

namespace RC\Helper\Cache\think\exception;

use RuntimeException;
use Throwable;

class FuncNotFoundException extends RuntimeException
{
    protected $func;

    public function __construct(string $message, string $func = '', Throwable $previous = null)
    {
        $this->message = $message;
        $this->func   = $func;

        parent::__construct($message, 0, $previous);
    }

    /**
     * 获取方法名
     * @access public
     * @return string
     */
    public function getFunc()
    {
        return $this->func;
    }
}