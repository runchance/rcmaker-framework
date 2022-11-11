<?php
namespace RC\Helper;
class Validator{
	 protected $length = [];
	 protected $range = [];
	 protected $fail = [];
	 protected $attach = [];
	 protected $fields = [];
	 protected $field = null;
	 protected $rule = 'string';
	 protected $value = null;
	 protected $fieldtype = null;
     protected $size = null;
     protected $ext = [];
     protected $mime = [];
     protected $image = [];
	 protected static $validators = [
        'int' => ['isInteger','整数'], //验证整数
        'float' => ['isFloat','浮点数'], //浮点数
        'pint' => ['isPint','正整数'],//正整数
        'npint'=> ['isNpint','非正整数'],//负整数和0
        'nint'=> ['isNint','负整数'],//负整数
        'nnint'=> ['isNnint','非负整数'],//非负整数,正整数和0
        'pfloat'=> ['isPfloat','正浮点数'],//正浮点数
        'npfloat'=> ['isNpfloat','非正浮点数'],//负浮点数和0
        'nfloat'=> ['isNfloat','负浮点数'],//负浮点数
        'nnfloat'=> ['isNnfloat','非负浮点数'],//正浮点数和0
        'alpha' => ['isAlpha','纯字母'], //字母
        'alnum' => ['isAlnum','字母数字组合'], //字母和数字
        'chinese' => ['isChinese','中文'], //中文
        'ip' => ['isIp','ip地址'], //ip地址
        'ipv6' => ['isIpv6','ipv6地址'], //ipv6地址
        'domain' => ['isDomain','域名'], //域名地址,
        'email' => ['isEmail','邮件地址'], //邮箱地址,
        'url' => ['isUrl','url地址'], //url地址,
        'mac' => ['isMac','mac地址'], //mac地址,
        'string' => ['isString','字符串'], //验证是否是字符串
        'date' => ['isDate','日期'], //验证日期
        'phone' => ['isPhone','手机号'], //验证手机号
        'file' => ['isFile','文件'], //验证文件
    ];
    protected static $imageType = [
        'gif'=>1,
        'jpg'=>2,
        'png'=>3,
        'swf'=>4,
        'psd'=>5,
        'bmp'=>6,
        'tiff(intel byte order)'=>7,
        'tiff(motorola byte order)'=>8,
        'jpc'=>9,
        'jp2'=>10,
        'jpx'=>11,
        'jb2'=>12,
        'swc'=>13,
        'iff'=>14,
        'wbmp'=>15,
        'xbm'=>16,
        'webp'=>17
    ];

   

    private function initialize(){
    	$this->length = [];
    	$this->range = [];
    	$this->fail = [];
    	$this->attach = [];
    	$this->fields = [];
    	$this->field = null;
    	$this->value = null;
    	$this->fieldtype = null;
    	$this->rule = 'string';
        $this->size = null;
        $this->ext = [];
        $this->mime = [];
        $this->image = [];
    }
    private function length($min = null, $max = null){
    	$this->length = [$min,$max];
    	return $this;
    }

    private function range($min = null, $max = null){
    	$this->range = [$min,$max];
    	return $this;
    }



    public function rule($rule=null){
    	
    	$this->rule = array_key_exists($rule,static::$validators) ? $rule : 'string';
    	$this->fail[$this->field]['rule'] = $this->rule;
    	$this->fieldtype = static::$validators[$this->rule][1];
    	return $this;
    }

    public function name($name=null){
    	$this->fields[$this->field] = $name ?? $this->field;
    	return $this;
    }

    public function fail($field=null){
    	return isset($field) ? $this->fail[$field] : (isset($this->fail['']) ? $this->fail[''] : $this->fail);
    }

    private function compare($value,$type='string'){
    	$minlen = $this->length[0] ?? null;
    	$maxlen = $this->length[1] ?? null;
    	$field = $this->field;
    	$field_name = $this->fields[$field] ?? null;
    	if($minlen==$maxlen && $minlen!==null){
    		if(mb_strlen((string)$value)!==$minlen){
    			$this->fail[$field]['msg'] ='['.$field_name.']的长度只能为'.$minlen.'位';
	    		$this->fail[$field]['type'] = 'length equal';
	    		$this->fail[$field]['exp'] = $minlen;
	    		return false;
    		}
    	}else{
    		if($minlen!==null){
    			if(mb_strlen((string)$value) < $minlen){
    				if($value===null){
    					$this->fail[$field]['msg'] ='['.$field_name.'] 不能为空';
	    				$this->fail[$field]['type'] = 'empty';
	    				$this->fail[$field]['exp'] = $minlen;
	    			}else{
	    				$this->fail[$field]['msg'] ='['.$field_name.']的长度不能小于'.$minlen.'位.';
		    			$this->fail[$field]['type'] = 'length less';
		    			$this->fail[$field]['exp'] = $minlen;
	    			}
		    		return false;
    			}
    		}
    		if($maxlen!==null){
    			if(mb_strlen((string)$value) > $maxlen){
    				$this->fail[$field]['msg'] ='['.$field_name.']的长度不能大于'.$maxlen.'位.';
		    		$this->fail[$field]['type'] = 'length greater';
		    		$this->fail[$field]['exp'] = $minlen;
		    		return false;
    			}
    		}
    	}

    	if($type=='numeric'){

    		$min = $this->range[0] ?? null;
    		$max = $this->range[1] ?? null;
    		if($min==$max && $min!==null){
    			if($value!==$min){
    				$this->fail[$field]['msg'] ='['.$field_name.']的值只能为'.$min.'';
		    		$this->fail[$field]['type'] = 'range equal';
		    		$this->fail[$field]['exp'] = $min;
		    		return false;
    			}
    		}else{
    			if($min!==null){
    				if($value < $min){

    					$this->fail[$field]['msg'] ='['.$field_name.']的值不能小于'.$min.'';
			    		$this->fail[$field]['type'] = 'range less';
			    		$this->fail[$field]['exp'] = $min;
			    		return false;
    				}
    			}
    			if($max!==null){
    				if($value > $max){
    					$this->fail[$field]['msg'] ='['.$field_name.']的值不能大于'.$max.'';
			    		$this->fail[$field]['type'] = 'range greater';
			    		$this->fail[$field]['exp'] = $max;
			    		return false;
    				}
    			}
    		}
    	}
    	return true;
    }

    private function stringOption($options){
    	if(!$this->value){
    		return null;
    	}
    	if(isset($options['filter'])){
    		if(in_array($options['filter'],['htmlspecialchars','addslashes','trim','strip_tags'])){
    			$call = $options['filter'];
    			$this->value = $call($this->value);
    		}
    	}
    	if(isset($options['attach'])){
    		$this->attach = $options['attach'];
    	}
        if(isset($options['size'])){
            $this->size = $options['size'];
        }
        if(isset($options['ext'])){
            $this->ext = $options['ext'];
        }
        if(isset($options['mime'])){
            $this->mime = $options['mime'];
        }
        if(isset($options['image'])){
            $this->image = $options['image'];
        }

    }

    protected function checkSize($file, $size): bool
    {
        return $file->getSize() <= (int) $size;
    }

    protected function checkExt($file, $ext): bool
    {
        return in_array(strtolower($file->extension()), $ext);
    }
    protected function checkMime($file, $mime): bool
    {
        return in_array(strtolower(function_exists('finfo_open') ? $file->getMime() : $file->getUploadMineType()), $mime);
    }


    public function isFile($value,$options){
        $field = $this->field;
        $field_name = $this->fields[$field] ?? null;
        $this->value = $value;
        $this->stringOption($options);
        $files_array = [];
        $files = $value;
        if(is_array($files)){
            foreach($files as $name=>$file){
                if(is_array($file)){
                    foreach($file as $n=>$f){
                        $files_array[] = $f;
                    }
                }else{
                    $files_array[] = $file;
                }
            }
        }else{
            $files_array[] = $files;
        }

        foreach($files_array as $file){
            if(!$file instanceof \RC\File){
                 $this->setFail('type','file',''.($field_name ? '['.$field_name.']' : '').'[null] 不是有效的文件形式');
                 return false;
            }
            if($this->size && !$this->checkSize($file,$this->size)){
                $filesize = getFilesize($this->size);
                $this->setFail('size',$filesize,''.($field_name ? '['.$field_name.']' : '').'['.$file->getUploadName().'] 文件尺寸不匹配, 文件尺寸不能大于'.$filesize);
                return false;
            }
            if($this->ext && !$this->checkExt($file,$this->ext)){
                $this->setFail('ext',implode(',',$this->ext),''.($field_name ? '['.$field_name.']' : '').'['.$file->getUploadName().'] 扩展名不被允许');
                return false;
            }
            if($this->mime && !$this->checkMime($file,$this->mime)){
                $this->setFail('mime',implode(',',$this->mime),''.($field_name ? '['.$field_name.']' : '').'['.$file->getUploadName().'] mimetype不被允许');
                return false;
            }
            if($this->image){
                try {
                    if(isset($this->image[2]) && strtolower($this->image[2])=='webp' && strtolower($file->extension())=='webp'){
                        $webp = @\imagecreatefromwebp($file->getRealPath());
                        if(!$webp){
                            $this->setFail('image','image',''.($field_name ? '['.$field_name.']' : '').'['.$file->getUploadName().'] 不是有效的图片');
                            return false;
                        }
                        $width = \imagesx($img);
                        $height = \imagesy($img);
                        $type = 17;
                        \imagedestroy($webp);
                    }else{
                       $info = list($width, $height, $type, $attr) = \getimagesize($file->getRealPath()); 
                    }
                    if(isset($this->image[0]) || isset($this->image[1])){
                        $check = true;
                        if(isset($this->image[0]) && $this->image[0]<>$width){
                            $check = false;
                        }
                        if(isset($this->image[1]) && $this->image[1]<>$height){
                            $check = false;
                        }
                        if($check===false){
                            $this->setFail('image pixel',implode(',',[$width,$height]),''.($field_name ? '['.$field_name.']' : '').'['.$file->getUploadName().'] 图片宽高不允许');
                            return false;
                        }
                    }
                    if(isset($this->image[2])){
                        if(isset(static::$imageType[strtolower($this->image[2])]) && static::$imageType[strtolower($this->image[2])]!==$type){
                            $this->setFail('imageType',implode(',',[$width,$height]),''.($field_name ? '['.$field_name.']' : '').'['.$file->getUploadName().'] 图片类型不允许');
                            return false;
                        }
                    }
                    if(!$info){
                        $this->setFail('image','image',''.($field_name ? '['.$field_name.']' : '').'['.$file->getUploadName().'] 不是有效的图片');
                        return false;
                    }
                } catch (\Exception $e) {
                    $this->setFail('image','image',''.($field_name ? '['.$field_name.']' : '').'['.$file->getUploadName().'] 不是有效的图片');
                    return false;
                }
            }
        }

        return true;
    }

    public function isString($value,$options){
    	$this->value = (string)$value;
    	$this->stringOption($options);
    	if(!is_string($value)){
    		$this->setFail('type','String');
    		return false;
    	}
    	if(!$this->compare($this->value,'string')){
    		return false;
    	}
    	return true;
    }

    public function isPhone($value,$options=[]){
    	$this->value = (string)$value;
    	$this->stringOption($options);
    	$match='/^1[3456789]\d{9}$/i';
    	if(!preg_match($match, $value)){
    		$this->setFail('type','Phone');
    		return false;
    	}
    	
    	if(!$this->compare($this->value)){
    		return false;
    	}
    	return true;
    }


    public function isDate($value,$options=[]){
    	$this->value = $value;
    	$this->stringOption($options);
    	
    	if($this->attach){
    		$value = str_replace($this->attach,'',$value);
    	}
    	$date = date_create($value);
    	if(!$date || (isset($options['format']) && $value != date_format($date, $options['format']))){
    		$this->setFail('type','Date');
    		return false;
    	}
    	
    	if(!$this->compare($this->value)){
    		return false;
    	}
    	return true;
    }

    public function isMac($value,$options=[]){
    	$this->value = (string)$value;
    	$this->stringOption($options);
    	
    	if($this->attach){
    		$value = str_replace($this->attach,'',$value);
    	}
    	if(!filter_var($value, FILTER_VALIDATE_MAC)){
    		$this->setFail('type','Mac');
    		return false;
    	}
    	
    	if(!$this->compare($this->value)){
    		return false;
    	}
    	return true;
    }

    public function isUrl($value,$options=[]){
    	$this->value = (string)$value;
    	$this->stringOption($options);
    	
    	if($this->attach){
    		$value = str_replace($this->attach,'',$value);
    	}
    	if(!filter_var($value, FILTER_VALIDATE_URL)){
    		$this->setFail('type','Url');
    		return false;
    	}
    	
    	if(!$this->compare($this->value)){
    		return false;
    	}
    	return true;
    }

    public function isDomain($value,$options=[]){
    	$this->value = (string)$value;
    	$this->stringOption($options);
    	
    	if($this->attach){
    		$value = str_replace($this->attach,'',$value);
    	}
    	if(!filter_var($value, FILTER_VALIDATE_DOMAIN)){
    		$this->setFail('type','Domain');
    		return false;
    	}
    	
    	if(!$this->compare($this->value)){
    		return false;
    	}
    	return true;
    }

    public function isIpv6($value,$options=[]){
    	$this->value = (string)$value;
    	$this->stringOption($options);
    	
    	if($this->attach){
    		$value = str_replace($this->attach,'',$value);
    	}
    	if(!filter_var($value, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)){
    		$this->setFail('type','Ip');
    		return false;
    	}
    	
    	if(!$this->compare($this->value)){
    		return false;
    	}
    	return true;
    }

    public function isIp($value,$options=[]){
    	$this->value = (string)$value;
    	$this->stringOption($options);
    	
    	if($this->attach){
    		$value = str_replace($this->attach,'',$value);
    	}
    	if(!filter_var($value, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)){
    		$this->setFail('type','Ip');
    		return false;
    	}
    	
    	if(!$this->compare($this->value)){
    		return false;
    	}
    	return true;
    }

    public function isChinese($value,$options=[]){
    	$this->value = (string)$value;
    	$this->stringOption($options);
    	$match='/^[\x{4e00}-\x{9fa5}]+$/iu';
    	if($this->attach){
    		$value = str_replace($this->attach,'',$value);
    	}
    	if(!preg_match($match, $value)){
    		$this->setFail('type','Chinese');
    		return false;
    	}
    	
    	if(!$this->compare($this->value)){
    		return false;
    	}
    	return true;
    }

    public function isAlnum($value,$options=[]){
    	$this->value = (string)$value;
    	$this->stringOption($options);
    	$match='/^[a-zA-Z0-9]+$/i';
    	if($this->attach){
    		$value = str_replace($this->attach,'',$value);
    	}
    	if(!preg_match($match, $value)){
    		$this->setFail('type','Alnum');
    		return false;
    	}
    	
    	if(!$this->compare($this->value)){
    		return false;
    	}
    	return true;
    }

    public function isAlpha($value,$options=[]){
    	$match='/^[a-zA-Z]+$/i';
    	if(!preg_match($match, $value)){
    		$this->setFail('type','Alpha');
    		return false;
    	}
    	$this->value = (string)$value;
    	if(!$this->compare($this->value)){
    		return false;
    	}
    	return true;
    }


    public function isEmail($value,$options=[]){
    	$match='/^[\.a-zA-Z0-9_-]+@[a-zA-Z0-9_-]+(\.[a-zA-Z0-9_-]+)+$/i';
    	if(!preg_match($match, $value)){
    		$this->setFail('type','Email');
    		return false;
    	}
    	$this->value = (string)$value;
    	if(!$this->compare($this->value)){
    		return false;
    	}
    	return true;
    }


    public function isPfloat($value,$options=[]){
    	$match='/^(([0-9]+\.[0-9]*[1-9][0-9]*)|([0-9]*[1-9][0-9]*\.[0-9]+)|([0-9]*[1-9][0-9]*))$/i';
    	if(!preg_match($match, $value)){
    		$this->setFail('type','Non-positive floating number');
    		return false;
    	}
    	$this->value = (float)$value;
    	if(!$this->compare($this->value,'numeric')){
    		return false;
    	}
    	return true;
    }

    public function isNpfloat($value,$options=[]){
    	$match='/^((-\d+(\.\d+)?)|(0+(\.0+)?))$/i';
    	if(!preg_match($match, $value)){
    		$this->setFail('type','Non-positive floating number');
    		return false;
    	}
    	$this->value = (float)$value;
    	if(!$this->compare($this->value,'numeric')){
    		return false;
    	}
    	return true;
    }

    public function isNfloat($value,$options=[]){
    	$match='/^(-(([0-9]+\.[0-9]*[1-9][0-9]*)|([0-9]*[1-9][0-9]*\.[0-9]+)|([0-9]*[1-9][0-9]*)))$/i';
    	if(!preg_match($match, $value)){
    		$this->setFail('type','Non-negative floating number');
    		return false;
    	}
    	$this->value = (float)$value;
    	if(!$this->compare($this->value,'numeric')){
    		return false;
    	}
    	return true;
    }

    public function isNnfloat($value,$options=[]){
    	$match='/^\d+(\.\d+)?$/i';
    	if(!preg_match($match, $value)){
    		$this->setFail('type','Non-negative floating number');
    		return false;
    	}
    	$this->value = (float)$value;
    	if(!$this->compare($this->value,'numeric')){
    		return false;
    	}
    	return true;
    }

    public function isFloat($value,$options=[]){
    	$match='/^(-?\d+)(\.\d+)?$/i';
    	if(!preg_match($match, $value)){
    		$this->setFail('type','floating number');
    		return false;
    	}
    	$this->value = (float)$value;
    	if(!$this->compare($this->value,'numeric')){
    		return false;
    	}
    	return true;
    }
    
    public function isNnint($value,$options=[]){
    	$match='/^\d+$/i';
    	if(!preg_match($match, $value)){
    		$this->setFail('type','Non-negative integer');
    		return false;
    	}
    	$this->value = (integer)$value;
    	if(!$this->compare($this->value,'numeric')){
    		return false;
    	}
    	return true;
    }
    public function isNint($value,$options=[]){
    	$match='/^-[0-9]*[1-9][0-9]*$/i';
    	if(!preg_match($match, $value)){
    		$this->setFail('type','Negative integer');
    		return false;
    	}
    	$this->value = (integer)$value;
    	if(!$this->compare($this->value,'numeric')){
    		return false;
    	}
    	return true;
    }

    public function isNpint($value,$options=[]){
    	$match='/^((-\d+)|(0+))$/i';
    	if(!preg_match($match, $value)){
    		$this->setFail('type','Non-positive integer');
    		return false;
    	}
    	$this->value = (integer)$value;
    	if(!$this->compare($this->value,'numeric')){
    		return false;
    	}
    	return true;
    }

    public function isPint($value,$options=[]){
    	$match='/^[0-9]*[1-9][0-9]*$/i';
    	if(!preg_match($match, $value)){
    		$this->setFail('type','Positive integer');
    		return false;
    	}
    	$this->value = (integer)$value;
    	if(!$this->compare($this->value,'numeric')){
    		return false;
    	}
    	return true;
    }


    

    public function isInteger($value,$options=[]){
    	
    	$match='/^[-]{0,1}[0-9]+$/i';
    	if(!preg_match($match, $value)){
    		$this->setFail('type','Integer');
    		return false;
    	}
    	$this->value = (integer)$value;
    	if(!$this->compare($this->value,'numeric')){
    		return false;
    	}
    	return true;
    }

    

    private function checkfield($value,$options=[]){
    	$call = [$this,static::$validators[$this->rule][0]];
    	return $call($value,$options);
    }

    private function setFail($type,$exp,$msg = null){
    	$field = $this->field;
    	$field_name = $this->fields[$field] ?? null;
    	$this->fail[$field]['msg'] = $msg ?? '['.$field_name.'] 校验失败, 不是合法的'.$this->fieldtype;
    	$this->fail[$field]['type'] = $type;
    	$this->fail[$field]['exp'] = $exp;
    }

    public function check($value,$rule,callable $callable=null){
    	$rules = [''=>$rule];
    	$values = [''=>$value];
    	$checkvalue = $this->input($values,$rules,$callable);
    	return $checkvalue ? $checkvalue[''] : false;
    }

	public function input(array $input, array $rules,callable $callable=null){
		$this->initialize();
		$values = [];
		$check = true;
		foreach ($rules as $field => $rule) {
			$this->length = $this->range = $this->attach = [];
			$this->field = $field;
			$this->rule($rule['rule'] ?? null);
			$this->name($rule['name'] ?? null);
			$rule['len'] = $rule['len'] ?? ($rule['length'] ?? null);
			$rule['required'] = $rule['required'] ?? true;
			if(isset($rule['len'])){
				if(is_array($rule['len'])){
					$minlen = $rule['len'][0] ?? null;
					$maxlen = $rule['len'][1] ?? null;
				}else{
					$minlen = $maxlen = intval($rule['len']);
				}
				$this->length($minlen,$maxlen);
			}
			if(isset($rule['range'])){
				if(is_array($rule['range'])){
					$minrange = $rule['range'][0] ?? null;
					$maxrange = $rule['range'][1] ?? null;
				}else{
					$minrange = $maxrange = floatval($rule['range']);
				}
				$this->range($minrange,$maxrange);
			}
			$value = $input[$field] ?? null;
			$options = $rule['options'] ?? null;

			if(($rule['required']===false || (isset($this->length[0]) && $this->length[0]===0)) && ($value=='' || $value==null)){
				unset($this->fail[$field]);
                if($value===''){
                    $values[$field] = '';
                }
			}else{
				if(!$this->checkfield($value,$options)){
	            	if(is_callable($callable)){
	            		$callable($this->fields[$field] ?? $field,($this->fail($field) ?? null));
	            		$check = false;
	            	}
	            }else{
	            	unset($this->fail[$field]);
	            	$values[$field] = $this->value;
	            }
			}
            
        }
        if($check===false){
        	return $check;
        }
        $fails = $this->fail();
        if(isset($fails['msg'])){
            throw new \Exception((string)$fails['msg'] ?? null);
        }
        if($fails){
            $msg = "";
            foreach($fails as $field=>$fail){
                $msg.=$fail['msg']."\r\n";
            }
            throw new \Exception((string)$msg ?? null);
        }
        return $values;
	}
}
?>