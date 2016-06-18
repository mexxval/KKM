<?php
/**
 * @author	mexxval
 * @link	http://blog.sci-smart.ru
 */
 

interface ComPort {
	function open();
	function close();
	function write($data);
	function read($len);
}

class LocalComPort implements ComPort {
	protected $fp;
	protected $tty;
	protected $timeout = 5;

	public function __construct($tty) {
		$this->tty = $tty;
	}
	public function close() {
	    if ($this->fp !== null) {
		fclose($this->fp);
	    }
	    $this->fp = null;
	    return true;
	}
	public function open($timeout = 5) {
		$this->timeout = $timeout;
		return $this->fp = fopen($this->tty, 'w+b');
	}
	public function write($binary, $anslength = 2) {
	    if (!$this->fp) {
		throw new Exception('касса выключена!');
	    }
	    # timeout in MICROseconds
	    # $this->setSocketTimeout($timeout);
	    return fwrite($this->fp, $binary, strlen($binary));
	}
	public function read($length, $timeout = 500000) {
	    $this->setSocketTimeout($timeout);
	    return fread($this->fp, $length);
	}
	protected function setSocketTimeout($usec) {
	    $sec = 0;
	    return stream_set_timeout($this->fp, $sec, $usec);
	}
}

class RemotePort extends LocalComPort implements ComPort {
	protected $host;
	protected $port;
	public function __construct($host, $port) {
		$this->host = $host;
		$this->port = $port;
	}
	public function open($tmout = 3) {
		if (!$this->fp) {
			$this->fp = fsockopen($this->host, $this->port, $errno, $errstr, $tmout);
		}
		return $this->fp ;
	}
	/*
        if (!($fp = fsockopen($this->getAddress(), $this->getPort(), $errno, $errstr, $timeout))) {
            throw new Exception('Socket open error');
        }
        return $this->setConnection($fp);
    protected function setSocketTimeout($fp, $usec) {
        $sec = 0;
        return stream_set_timeout($fp, $sec, $usec);
    }
    */
}

class SshComPort implements ComPort {
	public function __construct($host, $tty) {
	}
	public function open(){}
	public function close(){}
	public function write($bin){}
	public function read($len){}
}


