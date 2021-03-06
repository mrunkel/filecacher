<?php

namespace Dburiy;

class FileCacher
{
    private $dir = '';
    private $mode;

    /**
     * FileCacher constructor.
     *
     * @param $path
     * @param int $mode
     *
     * @throws \Exception
     */
    public function __construct($path, $mode = 0777)
    {
        $this->dir  = $path;
        $this->mode = $mode;
        if (!file_exists($this->dir) && !mkdir($this->dir, $this->mode, true)) {
            throw new \Exception("Can't create cache director: {$this->dir}");
        }
    }

    /**
     * Delete cache file by key
     *
     * @param $key
     *
     * @return bool
     */
    public function remove($key)
    {
        $filename = $this->getFilename($key);

        return file_exists($filename) ? unlink($filename) : true;
    }

    /**
     * Get value
     *
     * @param $key
     * @param null $default
     *
     * @return mixed|null
     */
    public function get($key, $default = null)
    {
        $result   = '';
        $filename = $this->getFilename($key);
        try {
            if (!file_exists($filename)) {
                throw new \Exception("file not found {$filename}");
            }
            $meta = $this->getMeta($key);
            if ($meta && $meta['expire'] != 0 && ($meta['expire'] < microtime(true))) {
                unlink($filename);
                throw new \Exception("file expire {$filename}");
            }
            $h = fopen($filename, "r");
            if ($h === false) {
                throw new \Exception("file not readable {$filename}");
            }
            fgets($h); // read first line with meta
            while (!feof($h)) {
                $str    = fgets($h);
                $result .= $str;
            }
            fclose($h);
            if ($meta && $meta['serialize']) {
                $result = unserialize($result);
            }
        } catch (\Exception $e) {
            // can't get cache from file. return default value
        }

        return $result ? : (is_callable($default) ? call_user_func($default) : $default);
    }

    /**
     * Set value
     *
     * @param $key
     * @param $value
     * @param int $lifetime
     *
     * @return $this
     * @throws \Exception
     */
    public function set($key, $value, $lifetime = 0)
    {
        $filename = $this->getFilename($key);
        $expire   = $lifetime ? microtime(true) + (int)$lifetime : 0;
        if (!file_exists($filename)) {
            $dir = dirname($filename);
            if (!is_dir($dir) && !mkdir($dir, $this->mode, true)) {
                throw new \Exception("Can't create cache director: {$dir}");
            }
        }

        $serialize = !is_string($value);
        if ($serialize) {
            $value = serialize($value);
        }

        $meta = json_encode(['expire' => $expire, 'time' => time(), 'serialize' => $serialize], 1);
        $h    = fopen($filename, 'w');
        fwrite($h, $meta . PHP_EOL . $value);
        fclose($h);

        return $this;
    }

    /**
     * @param $key
     * @param $lifetime
     * @param null $default
     *
     * @return mixed|null
     * @throws \Exception
     */
    public function cache($key, $lifetime, $default = null)
    {
        $data = $this->get($key);
        if (is_null($data)) {
            if (is_callable($default)) {
                $data = call_user_func($default);
            } else {
                $data = $default;
            }
            if (!is_null($data)) {
                $this->set($key, $data, $lifetime);
            }
        }

        return $data;
    }

    /**
     * Get meta by cache key
     *
     * @param $key
     *
     * @return bool|mixed
     */
    private function getMeta($key)
    {
        $filename = $this->getFilename($key);

        return $this->getMetaFromFile($filename);
    }

    /**
     * Get filename by key
     *
     * @param $key
     *
     * @return mixed
     */
    private function getFilename($key)
    {
        return str_replace('//', '/', $this->dir . '/' . str_replace('_', '/', $key));
    }

    /**
     * Get meta from file
     *
     * @param $filename
     *
     * @return array
     */
    private function getMetaFromFile($filename)
    {
        try {
            if (!file_exists($filename)) {
                throw new \Exception("cache file not exist {$filename}");
            }
            $fh = fopen($filename, 'r');
            if (!$fh) {
                throw new \Exception("can't open file {$filename}");
            }
            $line   = fgets($fh);
            $result = json_decode($line, true);
            fclose($fh);
        } catch (\Exception $e) {
            $result = [];
        }

        return $result;
    }

    /**
     * Delete old cache file and empty cache subfolder
     *
     * @param string $folder
     *
     * @return bool
     * @throws \Exception
     */
    public function clean($folder = '')
    {
        $folder = $folder ? $folder : $this->dir;
        $dirs   = scandir($folder, 1);
        $files  = count($dirs) - 2;
        foreach ($dirs as $name) {
            if (!in_array($name, ['.', '..'])) {
                if (is_dir($folder . '/' . $name)) {
                    if ($this->clean($folder . '/' . $name)) {
                        --$files;
                    }
                } else if (($meta = $this->getMetaFromFile($filename = $folder . '/' . $name))
                           && $meta['expire'] != 0
                           && ($meta['expire'] < microtime(true))) {

                    if (!@unlink($filename) && file_exists($filename)) {
                        throw new \Exception("Can't delete old cache file {$filename}");
                    }
                }
            }
        }
        if (!$files && ($this->dir != $folder)) {
            @rmdir($folder);
        }

        return !$files;
    }
}
