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
namespace RC\Http\Workerman;

use RC\Request;
use RC\Controller;
/**
 * Class Response
 * @package Webman\Http
 */
class Response extends \Workerman\Protocols\Http\Response
{
    protected $response = null;
    protected $request = null;
    /**
     * @param $file
     * @return $this
     */
    
    public function __construct(
        $request=null,
        $status = 200,
        $headers = array(),
        $body = '')
    {
        
        parent::__construct($status,$headers,$body);
        $this->request = $request;
        $this->response = $request ? $request->RCresponse : null;
    }
    public function file($file)
    {
        if($this->response){
            $this->response->findStaticFile = true;
            $this->response->staticFile = $file;
        }
        if ($this->notModifiedSince($file)) {
            return $this->withStatus(304);
        }
        return $this->withFile($file);
    }

    /**
     * @param $file
     * @param string $download_name
     * @return $this
     */
    public function download($file, $download_name = '')
    {
        if($this->response){
           $this->response->findStaticFile = true;
            $this->response->staticFile = $file; 
        }
        $this->withFile($file);
        if ($download_name) {
            $this->header('Content-Disposition', "attachment; filename=\"$download_name\"");
        }
        return $this;
    }

    /**
     * @param $file
     * @return bool
     */
    protected function notModifiedSince($file)
    {
        $if_modified_since = $this->request ? $this->request->header('if-modified-since') : null;
        if ($if_modified_since === null) {
            return false;
        }
        if(!($mtime = \filemtime($file))){
            return false;
        }
        return $if_modified_since === \date('D, d M Y H:i:s', $mtime) . ' ' . \date_default_timezone_get();
    }
}