<?php
namespace RC;
use RC\Exception\FileException;
class File extends \SplFileInfo{

	public function __construct(string $path, bool $checkPath = true){
        if ($checkPath && !is_file($path)) {
            throw new FileException(sprintf('The file "%s" does not exist', $path));
        }
        parent::__construct($path);
    }

    /**
     * 获取文件的哈希散列值
     * @access public
     * @param string $type
     * @return string
     */
    public function hash(string $type = 'sha1'): string
    {
        if (!isset($this->hash[$type])) {
            $this->hash[$type] = \hash_file($type, $this->getPathname());
        }

        return $this->hash[$type];
    }

     /**
     * 获取文件类型信息
     * @access public
     * @return string
     */
    public function getMime(): string
    {
        $finfo = \finfo_open(FILEINFO_MIME_TYPE);

        return \finfo_file($finfo, $this->getPathname());
    }


    /**
     * 文件扩展名
     * @return string
     */
    public function extension(): string
    {
        return $this->getExtension();
    }
}
?>