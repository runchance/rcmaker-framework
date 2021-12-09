<?php

namespace RC\Http;
use RC\File;
use RC\Exception\FileException;
class UploadFile extends File
{
    /**
     * @var string
     */
    protected $_uploadName = null;

    /**
     * @var string
     */
    protected $_uploadMimeType = null;

    /**
     * @var int
     */
    protected $_uploadErrorCode = null;

     protected $_fileName = null;

    /**
     * UploadFile constructor.
     * @param $file_name
     * @param $upload_name
     * @param $upload_mime_type
     * @param $upload_error_code
     */
    public function __construct($file_name, $upload_name, $upload_mime_type, $upload_error_code)
    {
        $this->_uploadName = $upload_name;
        $this->_uploadMimeType = $upload_mime_type;
        $this->_uploadErrorCode = $upload_error_code;
        $this->_fileName = $file_name;
        parent::__construct($this->_fileName,UPLOAD_ERR_OK === $this->_uploadErrorCode);
    }

    /**
     * @return bool
     */
    public function isValid()
    {
        return $this->_uploadErrorCode === 0;
    }

    public function move($destination)
    {
        if ($this->isValid()) {
            set_error_handler(function ($type, $msg) use (&$error) {
                $error = $msg;
            });
            $path = pathinfo($destination, PATHINFO_DIRNAME);
            if (!is_dir($path) && !mkdir($path, 0777, true)) {
                restore_error_handler();
                throw new \Exception(sprintf('Unable to create the "%s" directory (%s)', $path, strip_tags($error)));
            }
            if (!rename($this->_fileName, $destination)) {
                restore_error_handler();
                throw new \Exception(sprintf('Could not move the file "%s" to "%s" (%s)', $this->_fileName, $destination, strip_tags($error)));
            }
            restore_error_handler();
            @chmod($destination, 0666 & ~umask());
            return true;
        }
        throw new FileException($this->getErrorMessage());
    }


    protected function getErrorMessage(): string
    {
        switch ($this->_uploadErrorCode) {
            case 1:
            case 2:
                $message = 'upload File size exceeds the maximum value';
                break;
            case 3:
                $message = 'only the portion of file is uploaded';
                break;
            case 4:
                $message = '['.$this->_uploadName.']no file to uploaded';
                break;
            case 6:
                $message = 'upload temp dir not found';
                break;
            case 7:
                $message = 'file write error';
                break;
            default:
                $message = 'unknown upload error';
        }

        return $message;
    }


    /**
     * @return string
     */
    public function getUploadName()
    {
        return $this->_uploadName;
    }

    /**
     * @return string
     */
    public function getUploadMineType()
    {
        return $this->_uploadMimeType;
    }

    /**
     * @return mixed
     */
    public function getUploadExtension()
    {
        return pathinfo($this->_uploadName, PATHINFO_EXTENSION);
    }

    /**
     * @return int
     */
    public function getUploadErrorCode()
    {
        return $this->_uploadErrorCode;
    }

    /**
     * 获取文件扩展名
     * @return string
     */
    public function extension(): string
    {
        return $this->getUploadExtension();
    }

   

}