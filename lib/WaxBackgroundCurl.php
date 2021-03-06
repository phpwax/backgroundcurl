<?php

class WaxBackgroundCurl
{
    public $headers = false;
    public $url = false;
    public $post_string = false;
    public $username = false;
    public $password = false;
    public $return_info_on_error = false;

    public $cache = true;
    public $cache_age = 300; //60 * 5 - seconds in 5 minutes
    public $cache_dir = "curl/";
    public $key = false;
    public $path = false;
    public $meta_suffix = "-meta";
    public $lock_suffix = '--LOCK--';
    public $curl_opts = array();

    public function __construct($data = [])
    {
        foreach ((array)$data as $k => $v) {
            $this->$k = $v;
        }

        //setup cache path
        if (defined("CACHE_DIR")) {
            $this->cache_dir = CACHE_DIR . $this->cache_dir;
        }
        $this->key = md5($this->url . $this->headers . $this->post_string);
        $this->path = $this->cache_dir . $this->key;
    }

    public function fetch($url = false)
    {
        if ($url) {
            $this->url = $url;
            $this->key = md5($this->url . $this->headers . $this->post_string);
            $this->path = $this->cache_dir . $this->key;
        }
        if ($this->cache) {
            $valid = $this->cache_valid();
            $cache_content = $this->cache();

            //if we need to update the cache, do so in the background
            if (!$valid && $cache_content) {
                $this->async_curl();
            }
            //present the cache data if it exists, next request will have the updated data after
            if ($cache_content) {
                return $cache_content;
            }
        }
        //only try an actual sync curl request if the cache returns nothing at all, this should only ever happen once first time the request happens
        return $this->sync_curl();
    }

    public function cache_valid()
    {
        return (is_readable($this->path) && is_file($this->path) && ($age = filemtime($this->path)) && ((time() - $age) < $this->cache_age));
    }

    public function cache()
    {
        if (is_readable($this->path) && is_file($this->path)) {
            return file_get_contents($this->path);
        }
    }

    public function async_curl()
    {
        //write meta data to a file
        if (!is_dir($this->cache_dir)) {
            mkdir($this->cache_dir, 0777, true);
        }
        file_put_contents($this->path . $this->meta_suffix, serialize((array)$this));
        chmod($this->path . $this->meta_suffix, 0777);
        //exec async call, with meta data file as an argument
        if (!is_readable($this->path . $this->lock_suffix)) {
            touch($this->path . $this->lock_suffix);
            $cmd = "php " . __DIR__ . "/WaxBackgroundCurl.php " . $this->path . $this->meta_suffix . " > /dev/null &";
            exec($cmd);
            unlink($this->path . $this->lock_suffix);
        }
    }

    public function sync_curl()
    {
        $session = curl_init($this->url);
        if ($this->headers) {
            curl_setopt($session, CURLOPT_HTTPHEADER, $this->headers);
        }
        curl_setopt($session, CURLOPT_TIMEOUT, 60);
        curl_setopt($session, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($session, CURLOPT_FOLLOWLOCATION, 1);
        if ($this->post_string) {
            curl_setopt($session, CURLOPT_POST, 1);
            curl_setopt($session, CURLOPT_POSTFIELDS, $this->post_string);
        }

        foreach ($this->curl_opts as $opt => $value) {
            curl_setopt($session, constant($opt), $value);
        }

        if ($this->username && $this->password) {
            curl_setopt($session, CURLOPT_USERPWD, $this->username . ':' . $this->password);
        }
        if ($this->cookies) {
            foreach ($this->cookies as $k => $v) $cookies[] = "$k=$v";
            curl_setopt($session, CURLOPT_COOKIE, implode("; ", $cookies));
        }
        if ($this->useragent) {
            curl_setopt($session, CURLOPT_USERAGENT, $this->useragent);
        }

        $this->return_value = curl_exec($session);
        $this->return_info = curl_getinfo($session);

        curl_close($session);

        if ($this->return_info['http_code'] == 200) {
            if ($this->cache) {
                $this->set_cache($this->return_value);
            }
            return $this->return_value;
        }

        if ($this->return_info_on_error) {
            return ["response" => $this->return_value, "info" => $this->return_info];
        }
    }

    public function set_cache($content)
    {
        if (!is_dir($this->cache_dir)) {
            mkdir($this->cache_dir, 0777, true);
        }
        file_put_contents($this->path, $content);
    }

}

if (isset($argv)) {
    foreach ($argv as $file) {
        if (is_readable($file) && !strpos($file, ".php")) {
            $cache = new WaxBackgroundCurl(unserialize(file_get_contents($file)));
        }
    }
    if ($cache) {
        $cache->sync_curl();
    }
}
