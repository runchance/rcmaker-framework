<?php
namespace RC\Helper;
use RC\Helper\Mailer\PHPMailer;
use RC\Helper\Mailer\SMTP;
use RC\Helper\Mailer\OAuth;
use RC\Helper\Mailer\Exception;
class Mailer{
	public static $_version = '6.6.3';
	protected $config = null;
	protected $PHPMailer = null;
	protected $from = null;
	protected $isHTML = true;
	protected $lastError = '';
	public function __construct($config = array()){
		$this->config = $config;
		$this->initMailer($config);
	}

	public function initMailer($config){
		$mailer = $this->PHPMailer = new PHPMailer($config['Exception'] ?? false);
		switch($config['Mailer']){
			case 'mail':
			default:
				$mailer->isMail();
			break;
			case 'smtp':
				$mailer->isSMTP();
			break;
			case 'Sendmail':
				$mailer->isSendmail();
			break;
		}
		$language = $config['Language'] ?? 'zh_cn';
		$mailer->setLanguage($language);
		$mailer->Debugoutput = $config['Debugoutput'] ?? 'error_log';
		$exclude = ['Mailer','Exception','Language'];
		$this->PHPMailer->isHTML($this->isHTML);
		$this->PHPMailer->CharSet = $config['CharSet'] ?? 'utf-8';
		foreach($config as $key=>$value){
			if(!in_array($key,$exclude)){
				$mailer->{$key} = $value;
			}
		}
	}

	public function a($path,$attachmentName = ''){
		return $this->addAttachment($path,$attachmentName);
	}
	public function addAttachment($path,$attachmentName = ''){
		if($this->PHPMailer){
			if(is_array($path)){
				foreach($path as $name=>$p){
					if(is_int($name)){
						$this->PHPMailer->addAttachment($p,$attachmentName);
					}else{
						$this->PHPMailer->addAttachment($p,$name);
					}
				}
			}else{
				$this->PHPMailer->addAttachment($path,$attachmentName);
			}
		}
		return $this;
	}

	public function t($email,$toName = ''){
		return $this->to($email,$toName);
	}

	public function to($email,$toName = ''){
		if($this->PHPMailer){
			if(is_array($email)){
				foreach($email as $name=>$mail){
					if(is_int($name)){
						$this->PHPMailer->addAddress($mail,$toName);
					}else{
						$this->PHPMailer->addAddress($mail,$name);
					}
				}
			}else{
				$this->PHPMailer->addAddress($email,$toName);
			}
		}
		return $this;
	}

	public function f($email,$fromName = ''){
		return $this->from($email,$fromName);
	}

	public function from($email,$fromName = ''){
		if($this->PHPMailer){
			$this->from = [$email,$fromName];
			$this->PHPMailer->setFrom($email, $fromName);
		}
		return $this;
	}
	public function sb($subject){
		return $this->subject($subject);
	}
	public function s($subject = null){
		return func_num_args() > 0 ? $this->subject($subject) : $this->send();
	}
	public function subject($subject){
		if($this->PHPMailer){
			$this->PHPMailer->Subject = $subject;
		}
		return $this;
	}
	public function b($body){
		return $this->body($body);
	}
	public function body($body){
		if($this->PHPMailer){
			$this->PHPMailer->Body = $body; // 邮件信息
		}
		return $this;
	}
	public function ih($bool = true){
		return $this->isHtml($bool);
	}
	public function isHtml($bool = true){
		if($this->PHPMailer){
			$this->PHPMailer->isHTML($bool);
		}
		return $this;
	}
	public function cc($email){
		if($this->PHPMailer){
			$this->PHPMailer->addCC($email);
		}
		return $this;
	}
	public function bcc($email){
		if($this->PHPMailer){
			$this->PHPMailer->addBCC($email);
		}
		return $this;
	}
	public function mh($message, $basedir = ''){
		return $this->msgHTML($message, $basedir);
	}
	public function msgHTML($message, $basedir = ''){
		if($this->PHPMailer){
			$this->PHPMailer->msgHTML($message,$basedir);
		}
		return $this;
	}
	public function e(){
		return $this->getError();
	}
	public function getError(){
		if($this->lastError !== ''){
			return $this->lastError;
		}
		if($this->PHPMailer){
			return $this->PHPMailer->ErrorInfo;
		}
		return null;
	}

	protected function resetMailer(){
		$this->from = null;
		$this->isHTML = true;
		$this->initMailer($this->config);
	}

	public function send(){
		if($this->PHPMailer){
			try {
				$result = (bool)$this->PHPMailer->send();
				$this->lastError = $this->PHPMailer->ErrorInfo ?? '';
				return $result;
			} catch (\Throwable $e) {
				$this->lastError = $e->getMessage();
				return false;
			} finally {
				$this->resetMailer();
			}
		}
		return false;
	}

	public function instance(){
		return new Mailer($this->config);
	}
}
?>