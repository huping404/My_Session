<?php

class My_Session implements ArrayAccess {

    private static $_handle;
    private $redis_conn;
    private $en64syshash = array(
        '0', '1', '2', '3', '4', '5', '6', '7', '8', '9',
        'a', 'b', 'c', 'd', 'e', 'f', 'g', 'h', 'i', 'j', 'k', 'l', 'm', 'n', 'o', 'p', 'q', 'r', 's', 't', 'u', 'v', 'w', 'x', 'y', 'z', '-',
        'A', 'B', 'C', 'D', 'E', 'F', 'G', 'H', 'I', 'J', 'K', 'L', 'M', 'N', 'O', 'P', 'Q', 'R', 'S', 'T', 'U', 'V', 'W', 'X', 'Y', 'Z', '_'
    );
    private $session_data = array();
    private $no_store = true;

    public static function getInstance() {
        if (!is_object(self::$_handle)) {
            self::$_handle = new self();
			
			$redisObject = new Redis();
            $redisObject->connect('127.0.0.1', '6379', 1);
            $redisObject->setOption(Redis::OPT_SERIALIZER, Redis::SERIALIZER_PHP);

            self::$_handle->redis_conn = $redisObject;
            self::$_handle->start();
        }
        return self::$_handle;
    }

    private function __construct() {
        
    }

    public function start() {
        if (!isset($_COOKIE['__YID']) || !$this->verify()) {
            $serv_ip = isset($_SERVER['SERVER_ADDR']) ? $_SERVER['SERVER_ADDR'] : '127.0.0.10';
            $hd = explode('.', $serv_ip);
            $t = time();
            $key = 's' . $t;
            $sec_id = $this->redis_conn->incr($key);
            if ($sec_id == 1)
                $this->redis_conn->setTimeout($key, 2);
            $keys = sprintf("%08x", $t) . sprintf("%02x", $hd[1]) . sprintf("%02x", $hd[2]) . sprintf("%02x", $hd[3]) . sprintf("%04x", getmypid()) . sprintf("%06x", $sec_id);
            $this->session_data['session_id'] = '';
            $keys = pack("H*", $keys);
            for ($i = 0; $i < 4; $i++) {
                $idx = 3 * $i;
                $this->session_data['session_id'] .= $this->en64syshash[ord($keys[$idx]) >> 2] .
                        $this->en64syshash[(ord($keys[$idx]) & 3) << 4 | ord($keys[$idx + 1]) >> 4] .
                        $this->en64syshash[(ord($keys[$idx + 1]) & 15) << 2 | ord($keys[$idx + 2]) >> 6] .
                        $this->en64syshash[ord($keys[$idx + 2]) & 63];
            }
            $this->session_data['session_id'] = str_shuffle($this->session_data['session_id']);
            setcookie('__YID', $this->session_data['session_id'], 0, '/', '/');
            $this->no_store = false;
        } else {
            $this->session_data = $this->redis_conn->hGetAll($_COOKIE['__YID']);
        }
    }

    private function verify() {
        if (strlen($_COOKIE['__YID']) != 16)
            return false;
        for ($i = 0; $i < 16; $i++) {
            if (!in_array($_COOKIE['__YID'][$i], $this->en64syshash))
                return false;
        }
        if ($this->redis_conn->hGet($_COOKIE['__YID'], 'session_id') != $_COOKIE['__YID'])
            return false;
        return true;
    }

    public function offsetSet($offset, $val) {
        if (!is_null($offset))
            $this->session_data[$offset] = $val;
        $this->no_store = false;
    }

    public function offsetUnset($offset) {
        unset($this->session_data[$offset]);
        $this->redis_conn->hDel($this->session_data['session_id'], $offset);
        $this->no_store = false;
    }

    public function offsetExists($offset) {
        return isset($this->session_data[$offset]);
    }

    public function offsetGet($offset) {
        return isset($this->session_data[$offset]) ? $this->session_data[$offset] : null;
    }

    public function __destruct() {
        if (!$this->no_store) {
            if (!$this->redis_conn->exists($this->session_data['session_id'])) {
                $this->redis_conn->hMset($this->session_data['session_id'], $this->session_data);
                $this->redis_conn->setTimeout($this->session_data['session_id'], 604800); // 7days
            }
            else
                $this->redis_conn->hMset($this->session_data['session_id'], $this->session_data);
        }
    }

}

?>
