<?php
/**
 * @author	mexxval
 * @link	http://blog.sci-smart.ru
 */
 
abstract class KKM {
    const EOT = 0x04;
    const ENQ = 0x05;
    const ACK = 0x06;
    const NAK = 0x15;
    const DLE = 0x10;
    const STX = 0x02;
    const ETX = 0x03;

    const READ_ACK_WAIT   = 0x01;
    const READ_ENQ_WAIT   = 0x01;
    const READ_STX_WAIT   = 0x02;
    const READ_GET_DATA   = 0x03;
    const READ_GOT_ESCAPE = 0x04;
    const READ_CRC_WAIT   = 0x05;

    protected $cashierpasswd = 0;
    protected $port; // объект связи с ККМ
    protected $debug;

    public function __construct($port, $debug = false) {
		$this->setPort($port);
		$this->debug = $debug;
		$this->init();
        #$this->setLocation($address, $port);
    }
    public function setPort($port) {
        $this->port = $port;
    }

    # integer
    public function setCashierPassword($passwd) {
        $this->cashierpasswd = $passwd;
    }
    protected function getCashierPassword() {
        return $this->cashierpasswd;
    }

    public function closeConnection() {
        return $this->port->close();
    }
    public function openConnection($timeout = 3) {
	return $this->port->open($timeout);
    }

    abstract public function isReady();
    abstract protected function escapeData($data);
    abstract protected function readAnswer();
    
    protected function init() {
	$this->openConnection();
    }
    protected function ack($readanswer = false) {
        return $this->sendBinary($this->packByte(self::ACK), 500000, $readanswer ? 1 : 0);
    }
    protected function nak() {
        return $this->sendBinary($this->packByte(self::NAK), 500000, 0);
    }
    protected function eot() {
        return $this->sendBinary($this->packByte(self::EOT), 100000, 0);
    }
    protected function enq() {
        return $this->sendBinary($this->packByte(self::ENQ), 500000, 1);
    }
    protected function isACK($binarybyte) {
        return $this->byteCode($binarybyte) == self::ACK;
    }
    protected function isENQ($binarybyte) {
        return $this->byteCode($binarybyte) == self::ENQ;
    }
    protected function isEOT($binarybyte) {
        return $this->byteCode($binarybyte) == self::EOT;
    }
    protected function isNAK($binarybyte) {
        return $this->byteCode($binarybyte) == self::NAK;
    }
    protected function isSTX($binarybyte) {
        return $this->byteCode($binarybyte) == self::STX;
    }
    protected function isDLE($binarybyte) {
        return $this->byteCode($binarybyte) == self::DLE;
    }
    protected function isETX($binarybyte) {
        return $this->byteCode($binarybyte) == self::ETX;
    }

    /**
     * input - string!
     */
    protected function packString($string) {
        $len = strlen($string);
        $bin = null;
        for ($i = 0; $i < $len; $i++) {
            $code = ord($string{$i});
            $bin .= $this->packByte($code);
        }
        return $bin;
    }
    /**
     * input - integer! 0x3F, 10, etc.
     */
    protected function packByte($byte) {
        return pack('C*', $byte);
    }
    protected function byteCode($byte) {
        $unp = unpack('C*', $byte);
        return isset($unp[1]) ? $unp[1] : null;
    }
    protected function unpackBinaryString($binarycmd) {
        $unp = unpack('H*', $binarycmd);
        return isset($unp[1]) ? $unp[1] : null;
    }
    /**
     * input - packed binary cmd line!
     */
    protected function makecrc($binaryCmd) {
        $n = strlen($binaryCmd);
        $crc = 0;
        for ($i = 0; $i < $n; $i++) {
            $dec = ord($binaryCmd{$i});
            $crc ^= $dec;
        }
        return $crc;
    }
    protected function log($msg, $nl = "\n") {
        if ($this->debug) {
			if (is_callable($this->debug)) {
				call_user_func_array($this->debug, array($msg . $nl));
			} else {
				error_log($msg . $nl);
			}
		}
    }
    protected function sendBinary($packedcmd, $timeout = 0, $anslength = 2) {
		$result = $this->port->write($packedcmd, $anslength);
        $this->log("\n" . '> ' . $this->unpackBinaryString($packedcmd) . ' (' . strlen($packedcmd) . ' bytes, ' . $result . ' written)');
        return $this->readBinaryAnswer($anslength, $timeout);
    }
    protected function readBinaryAnswer($anslength = 1, $timeout = 100000) {
        if ($anslength <= 0) {
            return;
        }
        $answer = null;
        for ($i = 0; $i < $anslength; $i++) {
            $binarybyte = $this->port->read(1, $timeout);
            if ($this->isACK($binarybyte)) {
                $this->log('ACK', '');
                $answer .= $binarybyte;
            }
            else if ($this->isENQ($binarybyte)) {
                $this->log('ENQ', '');
                $answer .= $binarybyte;
            }
            else if ($this->isNAK($binarybyte)) {
                $this->log('NAK', '');
                $answer .= $binarybyte;
            }
            else if ($binarybyte !== false) {
                if (strlen($binarybyte) == 0) {
                    $this->log('T', '');
                }
                else {
					$byte = unpack('H*', $binarybyte);
                    $this->log('.' . reset($byte), '');
                    $answer .= $binarybyte;
                }
            }
            else {
                $this->log('-', '');
            }
        }
        return $answer;
    }

}
