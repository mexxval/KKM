<?php
/**
 * для штрих алгоритм такой:
 * - отправляем пакет с командой: STX, <LEN>, <PASS>, <MSG>, <CRC>
 * - ждем ответа ФР, должен быть ACK
 * - пауза, возможно очень маленькая, возможно большая, пока ФР готовит ответ
 * - читаем ответ ФР
 * - отвечаем ACK
 * 
 * формат запроса, например гудок: 0x13
 * STX, 05, 13, 1E 00 00 00, 08
 * формат ответа такой же:
 * ACK, STX, 03 13 00 1E 0E
 * 
 * Если в процессе печати документа произошёл обрыв ленты, то на ней, печатается строчка
«**ОБРЫВ БУМАГИ ДОКУМЕНТ НЕЗАВЕРШЕН**» и печать приостанавливается. АСПД
переходит в подрежим 2 «Активное отсутствие бумаги». Оператору требуется установить
новый рулон в АСПД согласно инструкции по заправке бумаги (см. соответствующий раздел
выше). При этом АСПД переходит в подрежим 3 «После активного отсутствия бумаги». 
Затем оператор должен подать команду B0h «Продолжение печати» (все другие команды, 
связанные с печатью, блокируются в подрежиме 3). После подачи команды продолжения
печати прерванный документ повторяется
 * 
 * 25h - отрезка чека
 * 
 */
class ShtrihM_HiLevel extends KKM {
    // номера битов в битовых полях состояния ККМ
    const FLAGS2_SESSION_OPENED = 6; // 0 - closed, 1 - opened
    const FLAGS2_24HOUR_PASS = 7; // 0 - no, 1 - yes
    const FLAGS_MONEYBOX_OPENED = 11; // 0 - closed, 1 - opened
    const FLAGS_CASE_OPENED = 10; // 0 - опущена, 1 - поднята
    const FLAGS_PAPER_OUT = 7; // 0 - yes, 1 - no

    public function isReady () {
		$ans = $this->enq();
		if ($this->isNAK($ans)) {
			return true;
		} else if ($this->isACK($ans)) {
			// это может понадобиться, чтобы прочитать непереданных от ФР пакет.
			// такое может быть если наш ACK не успел дойти до ФР или еще какая беда приключилась со связью
			$this->readAnswer(); // читаем, освобождаем буфер ФР
			return true;
		}
		return false;
    }

    protected function init() {
		// оператор1 - пароль 1 
		// оператор2 - пароль 2 [...]
		// оператор28 - пароль 28 
		// администратор - пароль 29 
		// системный администратор - пароль 30
		$this->setCashierPassword(30);
		parent::init();
    }

    public function beep() {
        return $this->writecmd('13');
    }

    public function getKKMState() {
        $answer = $this->writecmd('11');
        $scheme = array(
            'lastcmd' => 1,
            'errcode' => 1,
            'cashieridx' => 1,
            'fr_soft_ver' => 2,
            'fr_soft_build' => 2,
            'fr_soft_date' => 3, // dd-mm-yy
            'idxinroom' => 1,
            'docno' => 2,
            'fr_flags' => 2,
            'fr_mode' => 1,
            'fr_submode' => 1,
            'fr_port' => 1,
            'fp_soft_ver' => 2,
            'fp_soft_build' => 2,
            'fp_soft_date' => 3,
            'date' => 3,
            'time' => 3,
            'fp_flags' => 1,
            'plant_no' => 4,
            'last_smena_no' => 2,
            'fp_free_rec' => 2,
            'rereg' => 1,
            'rereg_left' => 1,
            'inn' => 6,
        );
        $curstate = 0;
        $result = array();
        foreach ($scheme as $var => $len) {
            $part = substr($answer, $curstate, $len);
            $result[$var] = $this->unpackBinaryString($part);
            $curstate += $len;
        }
        $result['fr_flags'] = sprintf("%08b", hexdec($result['fr_flags']));
        $result['fp_flags'] = sprintf("%08b", hexdec($result['fp_flags']));
		$result['fr_mode_text'] = $this->getModeTitleByCode($result['fr_mode']);
		$result['fr_submode_text'] = $this->getSubModeTitleByCode($result['fr_submode']);
        return $result;
    }
    public function is24HourPass() {
		return $this->getStateFlag(self::FLAGS2_24HOUR_PASS, 2) == 1;
    }
    public function isSessionOpened() {
		return $this->getStateFlag(self::FLAGS2_SESSION_OPENED, 2) == 1;
    }
    public function isMoneyBoxOpened() {
		return $this->getStateFlag(self::FLAGS_MONEYBOX_OPENED) == 1;
    }
    public function isPaperOut() {
		return $this->getStateFlag(self::FLAGS_PAPER_OUT) == 0;
    }
    public function isCaseOpened() {
		return $this->getStateFlag(self::FLAGS_CASE_OPENED) == 1;
    }
    public function openMoneyBox($num = 0) {
        return $this->writecmd('28', sprintf('%02d', $num));
    }
	public function setDateConfirm($ts = null) {
		return $this->setDate($ts, '23');
	}
	// печать отчета или чего еще
	public function isPrintInProgress() {
		$state = $this->getKKMState();
		// контролируем submode
		return $state['fr_submode'] == '05';
	}
    public function setDate($ts = null, $cmd = '22') {
        if ($ts == null or !date('Ymd', $ts)) {
            $ts = time();
        }
		if (!in_array($cmd, array('22', '23'))) {
			$cmd = '22';
		}
		$time = sprintf('%02x%02x%02x', date('d', $ts), date('m', $ts), date('y', $ts));
        return $this->writecmd($cmd, $time);
    }
    public function setTime($ts = null) {
        if ($ts == null or !date('His', $ts)) {
            $ts = time();
        }
		$time = sprintf('%02x%02x%02x', date('H', $ts), date('i', $ts), date('s', $ts));
        return $this->writecmd('21', $time);
    }
    # аннулирование всего чека
    public function cancelCheck() {
        return $this->writecmd('88');
    }
	public function cutCheck($cut = 1) {
		$cycles = 10;
		do {
			usleep(100000);
			$state = $this->isPrintInProgress();
		} while ($state and $cycles-- > 0);
		$cut = (bool) $cut; // 1 - неполная, 0 - полная
		return $this->writecmd('25', sprintf('%02d', $cut));
	}
    /*
     * Тип документа (1 байт): 0 – продажа; 
	1 – покупка; 
	2 – возврат продажи; 
	3 – возврат покупки
     */
    public function openNewCheck($type = 1) {
		return $this->writecmd('8D', sprintf('%02d', $type));
    }
    # открыть смену
    public function openSession() {
        return $this->writecmd('E0');
    }


    protected function getStateFlag($bit, $reg = 1) {
        if (!($state = $this->getKKMState())) {
            throw new Exception('Failed to get KKM state');
        }
		$reg = $reg == 2 ? 'fp_flags' : 'fr_flags';
		$tmp = strrev($state[$reg]);
		return $tmp{$bit};
    }
    protected function makeBinaryCmd($cmdcode, $data, $wPass = true) {
        $passwd = $wPass ? sprintf("%02x000000", $this->getCashierPassword()) : '';
		error_log($data);
        $cmd = pack('H*', $cmdcode . $passwd) . $this->escapeData(pack('H*', $data));
		$msg_len = pack('C', strlen($cmd));
        $cmdcrc = $this->makecrc($msg_len . $cmd);
        $cmd = pack('C*', self::STX) . $msg_len . $cmd . pack('C*', $cmdcrc);
        return $cmd;
    }
    protected function escapeData($data) {
		return $data;
    }
    public function writecmd($cmdcode, $data = '') {
		$wPass = !in_array($cmdcode, array('16'));
        $cmd = $this->makeBinaryCmd($cmdcode, $data, $wPass);
        $answer = false;
        try {
			$ack = $this->sendBinary($cmd, 5e5, 1); // таймаут 500мс на ожидание ACK
			if (is_null($ack)) { // timeout?
				throw new Exception('Response wait timeout. Link not ready');
			} else if ($this->isACK($ack)) {
				$answer = $this->readAnswer();
			} else {
				$this->log('NOT ACK'); // что делать в этом случае?
				// Если в ответ на сообщение ФР получен NAK, сообщение не повторяется, ФР ждет уведомления ENQ для повторения ответа
			}
        }
        catch (Exception $e) {
            throw $e;
        }
        return $answer;
    }
    protected function readAnswer() {
		// пингуем enq-ами, если отвечает ack-ами, значит готовит данные, ждем
		$cycles = 100;
		// читаем первый байт ответа
		$ans = $this->readBinaryAnswer(1, 5e4); // таймаут начала передачи от ФР. может быть большим
		do {
			if ($this->isSTX($ans)) {
				break;
			}
			else if ($this->isACK($ans)) { // пытаемся читать ответ
				$this->log('<B');
				$ans = $this->enq();
				continue;
			}
			else if (is_null($ans)) { // timeout
				$ans = $this->enq();
				continue;
			}
			else {
				$this->log('Expected STX in the beginning of answer. Got ' . $this->byteCode($ans));
				return false;
			}
		} while ($cycles-- > 0);

		$len_b = $this->readBinaryAnswer(1, 5e4);
		$len = $this->byteCode($len_b);
		$ans = $this->readBinaryAnswer($len + 1, 5e4);
		if ($this->checkCRC($len_b . $ans)) {
			$this->log('CRC MATCH');
			$this->ack();
			// проверим код ошибки
			$errcode = $this->byteCode($ans{1}); // длина пропущена, после нее код команды, а затем байт ошибки
			if ($errcode != 0) {
				$this->raiseError($errcode);
			}
		} else {
			$this->nak();
		}
		return $ans;
    }
    // полное сообщение в бинарном виде без ведущего STX
    protected function checkCRC($answer) {
		$crc = 0;
		for ($i = 0, $len = strlen($answer); $i < $len; $i++) {
			$byte = $this->byteCode(substr($answer, $i, 1));
			if ($i == ($len - 1)) {
				break;
			}
			$crc ^= $byte;
		}
		return ($crc == $byte);
    }
    protected function raiseError($errorCode) {
        throw new Exception($this->getErrorMessagebyCode($errorCode), $errorCode);
    }
	protected function getSubModeTitleByCode($mode) {
		$const = 'SHTRIH_KKMSUBMODE_' . hexdec($mode);
        if (defined($const)) {
            return constant($const);
        }
		return 'Неизвестный подрежим';
	}
	protected function getModeTitleByCode($mode) {
		$mode = hexdec($mode);
		$submode = ($mode & 0xF0) >> 4;
		$mode = ($mode & 0x0F);
		$const = 'SHTRIH_KKMMODE_' . $mode . '_' . $submode;
        if (defined($const)) {
            return constant($const);
        }
		return 'Неизвестный режим';
	}
    protected function getErrorMessagebyCode($errorCode) {
        $errorconst = 'SHTRIH_ERROR_CODE_' . $errorCode;
        if (defined($errorconst)) {
            return constant($errorconst);
        }
        return 'unknown error';
    }
	// переводит 1000 (5 байт) в e803000000
	protected function prepareArg($num, $bytes) {
		return implode('', array_reverse(str_split(sprintf('%0' . ($bytes * 2) . 'x', $num), 2)));
	}
	protected function prepareText($text, $bytes = 40) {
		if (preg_match('//u', $text)) { // is UTF
			$text = iconv('UTF-8', 'cp1251//IGNORE', $text);
		}
		$text_code = '';
		for ($i = 0, $n = strlen($text); $i < $n; $i++) {
			$byte = sprintf('%x', ord($text{$i}));
			$text_code .= $byte;
		}
		$maxlen = $bytes * 2;
		return str_pad(substr($text_code, 0, $maxlen), $maxlen, '0', STR_PAD_RIGHT); // 40 bytes max!
	}
	
	// цена передается как дробное. приводится внутри к копейкам
	// $qty передается как дробное, округлется до 3 знаков после запятой
    public function sell($price, $qty, $section, $text = 'sell') {
		$qty = $this->prepareArg($qty * 1000, 5);
		$price = $this->prepareArg(round($price * 100), 5);
		$args = sprintf('%10s%10s%02d00000000%80s', $qty, $price, $section, $this->prepareText($text, 40));
		return $this->writecmd('80', $args);
    }
	// сумма - дробная в рублях.
    public function closeCheck($sum, $print_line = '') {
		$args = $this->prepareArg($sum * 100, 5); // сумма наличных
		$args .= $this->prepareArg(0, 5);
		$args .= $this->prepareArg(0, 5);
		$args .= $this->prepareArg(0, 5); // сумма типа оплаты 4
		$args .= $this->prepareArg(0, 2); // скидка / надбавка
		$args .= '00000000'; // налоги
		$args .= $this->prepareText($print_line, 40);
        return $this->writecmd('85', $args);
    }
	public function getCheckSubtotal() {
		$ans = $this->writecmd('89');
		$ans = $this->unpackDigits(substr($ans, 3, 5));
		return ($ans / 100);
	}
    public function getKKMSumm() {
		return $this->getKKMMoneyRegister(241) / 100;
	}
    protected function getKKMMoneyRegister($no) {
        $ans = $this->writecmd('1A', sprintf('%x', $no));
		$ans = $this->unpackDigits(substr($ans, 3, 6));
		return $ans;
    }
    protected function getKKMOperRegister($no) {
        $ans = $this->writecmd('1B', sprintf('%x', $no));
		$ans = $this->unpackDigits(substr($ans, 3, 2));
		return $ans;
    }
	protected function unpackDigits($ans) {
		$ans = unpack('H*', implode('', array_reverse(str_split($ans))));
		$ans = reset($ans);
		return hexdec($ans);
	}
	public function getLastDocumentNumber() {
		return $this->getKKMOperRegister(152);
	}






    public function incomingMoney($summ) {  // Положить деньги в Кассу
		$summ *= 100; // into MDE
		$summ = dechex($summ); // ?? OR DEC OR BCD
        return $this->writecmd('50', str_pad($summ, 10, '0', STR_PAD_LEFT));
    }
    public function outcomingMoney($summ) { // Взять деньги из Кассы
		$summ *= 100; // into MDE
		$summ = dechex($summ);
        return $this->writecmd('51', str_pad($summ, 10, '0', STR_PAD_LEFT));
    }



    public function returnMoney($price, $qty, $section, $simulate = 0) {
    }

	
    
    protected function textToHex($text) {
        $text = iconv(CFG_SYSTEM_INTERNAL_ENCODING, 'ibm866//IGNORE', $text);
        $packed = $this->packString($text);
        return $this->unpackBinaryString($packed);
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
