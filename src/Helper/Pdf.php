<?php
namespace RC\Helper;
use RC\Container;
class Pdf{
	use Container;
	private $config = [];
	public function __construct($config = array()){
		$this->config = $config ;
	}

	public function get_Instance(){
		$class = FRAME_PATH . '/Helper/Tcpdf/tcpdf.php';
		$class_name = 'TCPDF';
		if(!Container::loadClass($class,'TCPDF')){
            throw new \Exception("Class '$smartyclass' not found");
        }
		return Container::make('TCPDF',$this->config);
	}
	
	public function get_Barcode(){

	}
}
?>