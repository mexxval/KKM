<?php

class FPrint_HiLevel extends KKM {
    const FLAGS_SESSION_OPENED = 2;
    const FLAGS_MONEYBOX_CLOSED = 4;
    const FLAGS_PAPER_OUT = 8;
    const FLAGS_CASE_OPENED = 32;

    public function isReady() {
        # try 5 times
        for ($i = 0; $i < 5; $i++) {
            if ($this->isACK($this->enq())) {
                return true;
            }
            usleep(100000);
        }
        return false;
    }
    /**
     * input - binary string!
     */
    protected function escapeData($data) {
        #$data = pack("H*", $data);
        $dle = $this->packByte(self::DLE);
        $etx = $this->packByte(self::ETX);
        $find = array($dle, $etx);
        $replace = array($dle . $dle, $dle . $etx);
        return str_replace($find, $replace, $data);
    }
    protected function makeBinaryCmd($cmdcode, $data) {
        $passwd = sprintf("%04d", $this->getCashierPassword());
        $cmd = pack('H*', $passwd . $cmdcode) . $this->escapeData(pack('H*', $data)) . pack('C*', self::ETX);
        $cmdcrc = $this->makecrc($cmd);
        $cmd = pack('C*', self::ENQ) . $cmd . pack('C*', $cmdcrc);
        return $cmd;
    }
    
    protected function writecmd($cmdcode, $data = '', $anslength = 1) {
        if (strlen($data) > 66 * 2) {
            throw new Exception('Data length too big');
        }
        $cmd = $this->makeBinaryCmd($cmdcode, $data);
        $answer = false;
        try {
	    $answer = $this->sendBinary($cmd, 50000, $anslength);
	    if ($this->isACK($answer)) {
		$this->ack();
	    }
        }
        catch (Exception $e) {
            throw $e;
        }
        return $answer;
    }
    # page 54
    public function openNewCheck($type = 1) {
        # flags
        # check type: 1 - sell, 2 - return, 3 - cancel
        $args = '000' . $type;
        return $this->executeCommand('92', $args);
    }
    public function registration($price, $qty, $section, $simulate = 0) {
        $args = '0' . ($simulate ? '1' : '0');
        $args .= sprintf("%010d", $price * 100);
        $args .= sprintf("%010d", $qty * 1000); # кол-во в граммах
        $args .= sprintf("%02d", $section);
        return $this->executeCommand('52', $args);
    }
    public function returnMoney($price, $qty, $section, $simulate = 0) {
        $args = '' . ($simulate ? '1' : '0') . '0'; 
        $args .= sprintf("%010d", $price * 100);
        $args .= sprintf("%010d", $qty * 1000); # кол-во в граммах
//        $args .= sprintf("%02d", $section);
        return $this->executeCommand('57', $args);
    }
	
    public function printLine($line) {
        $hexstring = $this->textToHex($line);
        return $this->executeCommand('4C', $hexstring);
    }
    public function closeCheck($sum, $simulate = 0) {
        $args = '0' . ($simulate ? '1' : '0');
        $args .= '01'; # 01 - наличными
        $args .= sprintf("%010d", $sum * 100);
        $this->executeCommand('4A', $args);
        return $this->getCheckNumber() - 1;
    }
    public function isSessionOpened() {
        if (!($state = $this->getKKMState())) {
            return false;
        }
        return (bindec($state['flags']) & self::FLAGS_SESSION_OPENED) > 0; # бит 2 == 0 - открыт, иначе нет
    }
    public function isMoneyBoxClosed() {
        if (!($state = $this->getKKMState())) {
            return false;
        }
        return (bindec($state['flags']) & self::FLAGS_MONEYBOX_CLOSED) > 0; # бит 2 == 0 - открыт, иначе нет
    }
    public function openMoneyBox() {
        return $this->executeCommand('80');
    }
    public function getKKMSumm() {
        return $this->BCD2num($this->executeCommand('4D'));
    }
	
    public function putMoneyIntoKKM($summ) {	// Положить деньги в Кассу
		$this->enterRegistrationMode();
		$args = "00".str_pad($summ*100,10,"0",STR_PAD_LEFT);
        $this->executeCommand('49',$args);
    }
    public function getMoneyFromKKM($summ) {	// Взять деньги из Кассы
		$this->enterRegistrationMode();
		$args = "00".str_pad($summ*100,10,"0",STR_PAD_LEFT);
        $this->executeCommand('4F',$args);
    }
	
    # page 55, аннулирование всего чека
    public function cancelCheck() {
        return $this->executeCommand('59');
    }
	
    # открыть смену
    public function openSession($text = 'Hello workers') {
        $this->enterRegistrationMode();
        $hextext = $this->textToHex($text);
        return $this->executeCommand('9A', $hextext);
    }
    public function setTime($timestamp = null) {
        if ($timestamp == null or !($time = date('His', $timestamp))) {
            $time = date('His');
        }
        $cmd = '4B';
        return $this->executeCommand($cmd, $time);
    }
    public function quitCurrentMode() {
        return $this->executeCommand('48');
    }
    /**
     * Вход в режим
     * 1 - рег, 3 - с гашением, 2 - без, 4 - прог, 5 - фп, 6 - эклз
     */
    public function setMode($mode, $passwd) {
        $answer = $this->quitCurrentMode();
        $stateCode = $this->getKKMStateCode();
        if ($stateCode != 0x00) {
            $this->raiseCustomError('ККМ не может выйти из режима ' . $this->getByteStr($stateCode));
        }
        $args = sprintf('%02d%08d', $mode, $passwd);
        return $this->executeCommand('56', $args);
    }
    # page 37
    public function getKKMState() {
        $answer = $this->executeCommand('3F');
        # "D"<Кассир(1)> <Номер_в_зале(1)> <Дата_YMD(3)> <Время_HMS(3)> <Флаги(1)> 
        # <Заводской_номер(4)> <Модель(1)> <Версия_ККМ(2)> <Режим_работы(1)> <Номер_чека(2)> 
        # <Номер_смены(2)> <Состояние_чека(1)> <Сумма_чека(5)> <Десятичная_точка(1)> <Порт(1)>
        $scheme = array(
            'answer' => 1,
            'cashieridx' => 1,
            'idxinroom' => 1,
            'dateymd' => 3,
            'timehms' => 3,
            'flags' => 1,   # нумерация битов 76543210
            'plantno' => 4,
            'model' => 1,
            'kkmversion' => 2,
            'mode' => 1,
            'checkno' => 2,
            'turnno' => 2,
            'checkstate' => 1,
            'checksum' => 5,
            'decimalpoint' => 1,
            'port' => 1,
        );
        $parsed = $this->parseAnswerWithScheme($answer, $scheme);
        $parsed['flags'] = sprintf("%08b", hexdec($parsed['flags']));
        return $parsed;
    }
    public function isPaperOut() {
        if (!($state = $this->getKKMState())) {
            return false;
        }
        return (bindec($state['flags']) & self::FLAGS_PAPER_OUT) == 0;
    }
    public function isCaseOpened() {
        if (!($state = $this->getKKMState())) {
            return false;
        }
        return (bindec($state['flags']) & self::FLAGS_CASE_OPENED) > 0;
    }
    # page 47
    public function getKKMStateCode() {
        $data = null;
        $raiseError = false;
        $answer = $this->executeCommand('45', $data, $raiseError);
        return $this->byteCode($answer{1});
    }
    public function getDeviceType() {
        return $this->executeCommand('A5');
    }
    public function beep($count = 1) {
        $result = true;
        for ($i = 0; $i < $count; $i++) {
            $result = $result && $this->writecmd('47');
        }
        return $result;
    }
    # ф-ции получения данных из регистров
    public function getDocumentNumber() {
        $result = $this->getRegisterValue('13', '00', '00');
        $docNumber = substr($result, 4, 4);
        return intval($this->unpackBinaryString($docNumber));
    }
    # ф-ции получения данных из регистров
    public function getCheckNumber() {
        $result = $this->getRegisterValue('13', '00', '00');
        $checkNumber = substr($result, 2, 2);
        return intval($this->unpackBinaryString($checkNumber));
    }

    protected function textToHex($text) {
        $text = iconv(CFG_SYSTEM_INTERNAL_ENCODING, 'ibm866//IGNORE', $text);
        $packed = $this->packString($text);
        return $this->unpackBinaryString($packed);
    }
    # page 41
    protected function getRegisterValue($reg, $p1, $p2) {
        # reg = 1..30
        # param1,2 - 0..255
        # 0A - наличность в кассе
        # 13 - Режим работы, Состояние чека, Номер чека, Сквозной номер документа
        $data = $reg . $p1 . $p2;
        $answer = $this->executeCommand('91', $data);
        return substr($answer, 2);
    }
    protected function executeCommand($cmd, $data = null, $raiseError = true) {
        $result = $this->writecmd($cmd, $data);
        if (!$this->isACK($result)) {
            return false;
        }
        $answer = $this->readAnswer();
        if ($raiseError and $errorCode = $this->isError($answer)) {
            $this->raiseError($errorCode);
        }
        return $answer;
    }
    protected function readAnswer() {
        # для чтения ответа ждем запрос ENQ
        # timeout in MICROseconds
        #$enqwait = 5; # число ожиданий запроса на начало передачи
        $emptybytes = 25;
        $status = self::READ_ENQ_WAIT;
        $answer = ''; # чистые неэкранированные данные
        $crc = 0;
        while (true) {
            $binarybyte = $this->readBinaryAnswer(1, 400000);
            $bytecode = $this->byteCode($binarybyte);
            # первая проверка! если байт пустой, далее и проверять нечего
            if ($bytecode === null) {
                if ($emptybytes-- <= 0) {
                    break;
                }
            }
	    else if ($status == self::READ_ACK_WAIT and $this->isACK($binarybyte)) {
		$status = self::READ_STX_WAIT;
	    }
            else if ($status == self::READ_ENQ_WAIT and $this->isENQ($binarybyte)) {
                # attention! call this function modifies stream timeout
                $this->ack();
                $status = self::READ_STX_WAIT;
            }
            else if ($status == self::READ_STX_WAIT and $this->isSTX($binarybyte)) {
                $status = self::READ_GET_DATA;
            }
            else if ($status == self::READ_GET_DATA or $status == self::READ_GOT_ESCAPE) {
                $crc ^= $bytecode;
                # встретив экранирующий символ, включаем режим экрана и пропускаем байт
                if ($this->isDLE($binarybyte) and $status != self::READ_GOT_ESCAPE) {
                    $status = self::READ_GOT_ESCAPE;
                }
                # читаем следующий байт не глядя, он ведь экранирован
                else if ($status == self::READ_GOT_ESCAPE) {
                    $answer .= $binarybyte;
                    $status = self::READ_GET_DATA;
                }
                # вторая часть if для наглядности
                else if ($this->isETX($binarybyte) and $status != self::READ_GOT_ESCAPE) {
                    $status = self::READ_CRC_WAIT;
                }
                else {
                    $answer .= $binarybyte;
                }
            }
            else if ($status == self::READ_CRC_WAIT) {
                if ($crc == $bytecode) {
                    $eot = $this->ack(true);
                    # $eot дб равно self::EOT
                    $this->log("\n" . 'Got answer ' . $this->unpackBinaryString($answer));
                    return $answer;
                }
                # если crc не сошлось, полный сброс и ждем еще раз этот кадр
                $answer = '';
                $crc = 0;
                $status = self::READ_ENQ_WAIT;
	    } else {
		$this->log("\n" . 'Unknown byte ' . $this->unpackBinaryString($binarybyte));
	    }
        }
        throw new Exception('Error getting answer, too much empty bytes');
    }
    protected function enterRegistrationMode() {
        $passwd = '30';
        return $this->setMode(1, $passwd);
    }
    protected function getByteStr($int) {
        return sprintf("%02x", $int);
    }
    protected function raiseCustomError($errorStr) {
        throw new Exception($errorStr);
    }
    protected function raiseError($errorCode) {
        throw new Exception($this->getErrorMessagebyCode($errorCode), $errorCode);
    }
    protected function parseAnswerWithScheme($answer, $scheme) {
        $curstate = 0;
        $result = array();
        foreach ($scheme as $var => $len) {
            $part = substr($answer, $curstate, $len);
            $result[$var] = $this->unpackBinaryString($part);
            $curstate += $len;
        }
        return $result;
    }
    protected function isError($binaryAnswer) {
        if ($binaryAnswer{0} != "U") {
            return false;
        }
        return $this->byteCode($binaryAnswer{1});
    }
    # коды ошибок - page 82
    protected function getErrorMessagebyCode($errorCode) {
        $errorconst = 'KKM_ERROR_CODE_' . $errorCode;
        if (defined($errorconst)) {
            return constant($errorconst);
        }
        return 'unknown error';
    }

	protected function BCD2num($bcd) {					// Функция преобразования из BCD в человекочитаемый вид
	
		for($i=1,$res="";$i<strlen($bcd);++$i) {				// Перебираем побайтно
			$ln = sprintf("%08b",ord($bcd{$i}));				// Получаем строку 8 символов
			$resarr = str_split($ln, 4);						// Разбиваем на 2 части по 4
			$result .= bindec($resarr[0]).bindec($resarr[1]);	// Переводим в нормальный вид и склеиваем
		}
		return (intval($result))/100;				// Возвращаем float вроде 1234.56
	}
	
	protected function num2BCD($num) {					// Функция преобразования в BCD из человекочитаемого вида
		if (floatval(strlen($num)/2*2)!=(strlen($num)/2*2)) $num = "0".$num;

		for($i=0,$res="";$i<strlen($num);$i+=2) {				// Перебираем
			$result .= chr(bindec(decbin($num{$i}).decbin($num{$i+1})));
		}

		return $result;
	}
	
}
