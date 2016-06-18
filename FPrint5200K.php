<?php

class FPrint5200K extends FPrint_HiLevel {
    const MODE_WITH_DUMP = 3;
    const MODE_WITHOUT_DUMP = 2;
    /**
     * например, снять x-отчет
     * manual, page 14, 5Ah, timeout 40s
     * отчеты без гашения стр.64
     */
    public function XReport() {
        # последовательность из мануала
        # цикл Запрос кода состояния ККМ пока Состояние == 2.2
        # Если Состояние == 2.0 
        #   Если бит 0 поля Флаги == 1 - нет бумаги
        #   Если бит 1 поля Флаги == 1 - нет связи с принтером, иначе удачное завершение
        #   Если бит 2 поля Флаги == 1 - мех.ошибка печат.устройства
        # Если Состояние != 2.0 - ошибка "Снятие отчета прервалось"
        $passwd = '30';
        $this->setMode(self::MODE_WITHOUT_DUMP, $passwd);
        $report = sprintf('%02d', 1);
        return $this->executeCommand('67', $report);
    }
    /**
     * суточный с гашением
     * 5Ah, стр.67
     */
    public function ZReport() {
    /*
        Цикл Запрос кода состояния ККМ, пока Состояние = 3.2
        Если Состояние ≠ 7.1,
            то если бит 0 поля Флаги = 1,
                то ошибка «Нет бумаги» (на остатке ленты ККМ автоматически печатается «Чек аннулирован» и отчет прерывается),
            иначе если бит 1 поля Флаги = 1
                то ошибка «Нет связи с принтером чека»,
            иначе (биты 0 и 1 поля Флаги = 0) ошибка «Снятие отчета прервалось»,
            иначе если бит 2 поля Флаги = 1
                то ошибка «Механическая ошибка печатающего устройства»,
            иначе (биты 0, 1 и 2 поля Флаги = 0) ошибка «Снятие отчета прервалось».
            Цикл Запрос кода состояния ККМ, пока Состояние = 7.1
            После изменения состояния с 7.1 на любое другое – удачное завершение.
    */
        $passwd = '30';
        $this->setMode(self::MODE_WITH_DUMP, $passwd);
        return $this->executeCommand('5A');
    }
    public function printCheck($dataarray, $inputsum = 0) {
        # Последовательность формирования позиции с названием товаров:
        # 1. Продажа (52h) с параметрами: Флаг = 1, Цена, Количество, Секция.
        # 2. Если код ошибки ≠ 0, то Ошибка = код ошибки (прервать формирование позиции).
        # 3. Печать строки (4Ch), Строка = название товара.
        # 4. Если код ошибки ≠ 0, то Ошибка = код ошибки (прервать формирование позиции).
        # 5. Продажа (52h) с параметрами: Флаг = 0, Цена, Количество, Секция.
        # 6. Если код ошибки ≠ 0, то Ошибка = код ошибки (формирование позиции не удалось)
        # В строке 2 проверяется возможность регистрации продажи. Если Зарегистрировать продажу 
        #    можно (нет ошибок), то печатаем название товара, а затем уже реально регистрируем 
        #    продажу. Это исключает такие ошибки, как «Смена превысила 24 часа», 
        #    «Переполнение ...» и т.д. Эта проверка делается для того, чтобы не возникало 
        #    ситуации, когда на чеке уже напечатано название товара, а потом выяснилось, что 
        #    регистрация не может быть выполнена
        
        # на ошибки проверять необязательно, 
        # ибо все идет через executeCommand, где в случае ошибки бросается Exception
        if (!is_array($dataarray) or empty($dataarray)) {
            $this->raiseCustomError('Data array parameter error');
        }
        try {
            $this->enterRegistrationMode();
            if (!$this->isMoneyBoxClosed()) {
                $this->raiseCustomError('Спрячьте деньги! :-)');
            }
        }
        catch (Exception $e) {
            throw $e;
        }
        try {
            //$this->openNewCheck(); $title = array();
            foreach ($dataarray as $datarow) {
                $section = $datarow['section'];
                $qty = $datarow['qty'];
                $price = $datarow['price'];
		$title = $datarow['title'];
				
                if (!$title or !$price or !$qty or !$section) {
                    $this->raiseCustomError('Неверные параметры в чеке. Need: section, qty, price, title. Got: ' . serialize($datarow));
                }
                $this->sell($title, $price, $qty, $section);
            }
            # закомменчено, открывается сам
            #$this->openMoneyBox();
            $checkNumber = $this->closeCheck($inputsum);
        }
        catch (Exception $e) {
            $this->cancelCheck();
            throw $e;
        }
        return $checkNumber;
    }
    protected function sell($title, $price, $qty, $section) {
        $simulate = 1;
        $this->registration($price, $qty, $section, $simulate);  # simulate
        # if ok - print and sell really
		$simulate = 0;
		foreach($title as $k=>$v) {
			$this->printLine($v);
		}
        $this->registration($price, $qty, $section, $simulate);
    }

    public function returnCheck($dataarray, $inputsum = 0) { // Чек возврата!
        if (!is_array($dataarray) or empty($dataarray)) {
            $this->raiseCustomError('Data array parameter error');
        }
        try {
            $this->enterRegistrationMode();
            if (!$this->isMoneyBoxClosed()) {
                $this->raiseCustomError('Спрячьте деньги! :-)');
            }
        }
        catch (Exception $e) {
            throw $e;
        }
        try {
            $this->openNewCheck(2); $title = array();
            foreach ($dataarray as $datarow) {
                $section = $datarow['section'];;
                $qty = $datarow['qty'];
                $price = $datarow['price'];
		$title = $datarow['title'];
				
                if (!$title or !$price or !$qty or !$section) {
                    $this->raiseCustomError('Неверные параметры в чеке. Need: section, qty, price, title. Got: ' . serialize($datarow));
                }
				//die("$title, $price, $qty, $section");
                $this->sellback($title, $price, $qty, $section);
            }
            # закомменчено, открывается сам
            #$this->openMoneyBox();
            $checkNumber = $this->closeCheck($inputsum);
        }
        catch (Exception $e) {
            $this->cancelCheck();
            throw $e;
        }
        return $checkNumber;
    }
    protected function sellback($title, $price, $qty, $section) {
		foreach($title as $k=>$v) {
			$this->printLine($v);
		}
        $this->returnMoney($price, $qty, $section, 0);		
    }
	
}

