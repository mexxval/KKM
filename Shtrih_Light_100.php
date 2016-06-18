<?php

class Shtrih_Light_100 extends ShtrihM_HiLevel {
    public function XReport() {
		$this->writecmd('40');
		$this->cutCheck();
    }
    public function ZReport() {
		$this->writecmd('41');
		$this->cutCheck();
    }
	
	public function closeCheck($sum, $print_line = '') {
		parent::closeCheck($sum, $print_line);
		return $this->getLastDocumentNumber();
	}
    
	public function nullify() {
		$this->writecmd('16');
		$this->setDate();
		$this->setDateConfirm();
		$this->setTime();
		return true;
	}
}

