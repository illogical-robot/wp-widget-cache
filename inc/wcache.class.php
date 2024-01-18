<?php

class WCache
{
    public $dir_mode = 0755;

    private $fld_output = 'output';

    private $fld_data = 'data';

    private $path = null;
    private $disable = false;
    public $disable_output = false;
    private $stack = [];

    public $cached = [];

    /**
     *
     * @param string $path ,
     *            the cache dir path
     * @param string $disable
     *            disable the cache or not
     * @param string $disable_output
     *            disable track the output(ob_start) or not
     */
    public function __construct($cache_dir, $disable = false, $disable_output = false)
    {
        //make cache dir
        if (!$disable && !is_dir($cache_dir)) {
            $this->__do_mkdir($cache_dir, $this->dir_mode);
            $disable = !is_dir($cache_dir);
        }

        //writable or not
        if (!$disable && !is_writable($cache_dir)) {
            @chmod($cache_dir, $this->dir_mode);
            $disable = !is_writable($cache_dir);
        }

        //make sure path ends with /
        if (!in_array(substr($cache_dir, -1), array(
            "\\",
            "/"
        ))) {
            $cache_dir .= "/";
        }

        $this->path = $cache_dir;
        $this->disable = $disable;
        $this->disable_output = $disable_output;
    }

    /**
     * Use like this:
     *
     * <code>
     * while ( save(key, 3000, &$data, $groupA) )
     * {
     * //echo something
     * //set $data, e.g. $data['k'] = 32142314;
     * }
     *
     * //here you got the $data
     * </code>
     *
     * @param string $key
     *            the cache key
     * @param int $expire_timespan
     *            how long will be expired, in seconds
     * @param &array $cdata，
     *            normally will be a reference
     * @param string $group
     *            cache group, so you can remove all caches in some group
     * @return boolean
     */
    public function save($key, $expire_timespan, $cdata = null, $group = false, $update = false)
    {
        if ($this->disable) {
            //nothing to do
            return false;
        }

        $keypath = $this->__get_key_path($key, $group);

        $expire_timespan = max(3, intval($expire_timespan));

        //here the real data created
        if (count($this->stack) && $keypath == $this->stack[count($this->stack) - 1]) {
            $ob_output = ob_get_contents();
            ob_end_clean();
            $this->__echo_output($ob_output);

            //create a cache pack
            $cpack = [];
            $cpack[$this->fld_output] = $ob_output;
            $cpack[$this->fld_data] = $cdata;

            $this->__save_cache($keypath, $cpack);

            unset($this->stack[count($this->stack) - 1]);

            return false;
        } elseif (count($this->stack) && in_array($keypath, $this->stack)) {
            trigger_error(
                "Cache stack problem: " . $this->stack[count($this->stack) - 1] . " not properly finished!",
                E_USER_ERROR
            );
            return false;
        } else {
            if ($update) {
                $this->stack[count($this->stack)] = $keypath;
                $res = count($this->stack);
            } else {
                $res = $this->__start_track($keypath, $expire_timespan);
            }
            //well no cache available
            if (is_int($res)) {
                ob_start();
                $this->cached[$key] = -1;

                return $res;
            } else {
                $res_output = false;

                $res_output = $res[$this->fld_output];
                $res_cdata = $res[$this->fld_data];
                $this->__echo_output($res_output);
                if ($this->cached[$key] === 0) {
                    $this->cached[$key] = 1;
                }

                if (is_array($cdata)) {
                    //copy the cdata
                    foreach ($res_cdata as $k => $v) {
                        $cdata[$k] = $res_cdata[$v];
                    }
                }

                return false;
            }
        }
    }

    /**
     * Remove all caches
     *
     * @param number $expire_timespan
     * @return number
     */
    public function clear($expire_timespan = 0)
    {
        return $this->__scan_dir($this->path, $expire_timespan);
    }

    /**
     * Remove a single cache
     *
     * @param string $key
     * @param string $group
     */
    public function remove($key, $group = false)
    {
        if (!$key) {
            return;
        }

        $keypath = $this->__get_key_path($key, $group);

        $this->__remove_cache($keypath);
    }

    /**
     * Remove caches in a group
     *
     * @param string $group
     */
    public function remove_group($group)
    {
        if (!$group) {
            return;
        }

        $subdir = $this->__encode_key($group);

        $this->__remove_cache($subdir);
    }

    /**
     * How many caches in the cache dir
     *
     * @return number
     */
    public function cachecount()
    {
        return $this->__scan_dir($this->path, -100);
    }

    private function __echo_output($output)
    {
        if (!$this->disable_output) {
            echo $output;
        }
    }

    private function __remove_cache($keypath)
    {
        $filename = $this->path . $keypath;

        if (is_file($filename)) {
            @unlink($filename);
            return true;
        } else {
            if (is_dir($filename)) {
                $this->__scan_dir($filename, 0);
            }
        }

        return false;
    }

    private function __save_cache($keypath, $data)
    {
        if ($this->disable) {
            return false;
        }

        $filename = $this->path . $keypath;

        if (file_exists($filename) && !is_writable($filename)) {
            trigger_error("Cache file not writeable!", E_USER_ERROR);
            return false;
        }

        $f = @fopen($filename, 'w');
        if ($f) {
            if (flock($f, LOCK_EX)) {
                fwrite($f, $this->__pack_data($data));
                flock($f, LOCK_UN);
            }
            fclose($f);
        } else {
            return false;
        }

        return true;
    }

    private function __load_cache($keypath, $expire_timespan)
    {
        if ($this->disable) {
            return false;
        }

        $filename = $this->path . $keypath;

        //Combines file exists and modification time in one call to prevent race conditions
        $filemtime = @filemtime($filename);

        if ($filemtime === false) {
            return false;
        }

        if (time() - $filemtime > $expire_timespan) {
            return false;
        }

        return @file_get_contents($filename);
    }

    private function __start_track($keypath, $time)
    {
        $data = $this->__load_cache($keypath, $time);

        if ($data !== false) {
            $data = $this->__unpack_data($data);
        }

        //no cache available
        if ($data === false) {
            //push it to stack
            $this->stack[count($this->stack)] = $keypath;

            return count($this->stack);
        }

        return $data;
    }

    /**
     * Unpack the serialized data.
     */
    private function __unpack_data($data)
    {
        // Suppress E_NOTICE in case unserialize fails - it will then return false
        return @unserialize($data);
    }

    /**
     * Pack the data for storage.
     */
    private function __pack_data($data)
    {
        return serialize($data);
    }

    private function __encode_key($name)
    {
        return md5($name);
    }

    private function __get_key_path($key, $group = false)
    {
        if (!is_string($key)) {
            $key = serialize($key);
        }

        $key = $this->__encode_key($key);

        if ($group) {
            if (!is_string($group)) {
                $group = serialize($group);
            }

            $subdir = $this->__encode_key($group);

            if (!is_dir($this->path . $subdir)) {
                $this->__do_mkdir($this->path . $subdir, $this->dir_mode);
            }

            $key = $subdir . "/" . $key;
        }

        return $key;
    }

    private function __do_mkdir($pathname, $mode)
    {
        @mkdir($pathname, $mode, true);
    }

    private function __scan_dir($dir, $expire_timespan = 0)
    {
        $n = 0;
        $dirstack = array();
        array_push($dirstack, $dir);
        do {
            $dir = array_pop($dirstack);
            if (!in_array(substr($dir, -1), array(
                "\\",
                "/"
            ))) {
                $dir .= "/";
            }

            $fs = @scandir($dir);
            if ($fs !== false) {
                foreach ($fs as $f) {
                    if (in_array($f, array(
                        ".",
                        ".."
                    ))) {
                        continue;
                    }

                    $fn = $dir . $f;
                    if (!is_readable($fn)) {
                        continue;
                    }

                    if (is_file($fn)) {
                        if ($expire_timespan > 0) {
                            $ts = time() - filemtime($fn);
                            if ($ts < $expire_timespan) {
                                continue;
                            }
                        }

                        if ($expire_timespan >= 0) {
                            @unlink($fn);
                        }

                        $n++;
                    } elseif (is_dir($fn)) {
                        array_push($dirstack, $fn);
                    }
                }
            }
            if ($expire_timespan == 0) {
                @rmdir($dir);
            }
        } while (sizeof($dirstack) > 0);

        return $n;
    }
}
