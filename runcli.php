<?php
require_once dirname(__FILE__) . '/_lib.php';

if (!($inputhdl = fopen('php://stdin' ,'r'))) {
    die ('php stdin open error' . "\n");
}

$host = '127.0.0.1';
$port = '3000';
$port = new RemotePort($host, $port);
#$port = new SshComPort($host, '/dev/ttyUSB0');
#$port = new LocalComPort('/home/mex/vttyUSB0');
$kkm = new Shtrih_Light_100($port, 'printf');

while (1) {
    $inputline = trim(fgets($inputhdl));
    $m = explode(' ', $inputline, 2);
    $command = $m[0];
    if (!$command) {
	continue;
    }
    $params = isset($m[1]) ? $m[1] : null;
    $callback = array($kkm, $command);
    $parameters = (empty($params) ? array() : explode(' ', $params));
    
    if (is_callable($callback)) {
        try {
            $kkm->openConnection();
            if ($kkm->isReady()) {
                echo 'Ready. Got command [' . $command . ($params ? ' with parameters ' . $params : '') . ']' . "\n";
                $answer = call_user_func_array($callback, $parameters);
		// $ans = unpack('H*', $answer); $ans = reset($ans);
                echo 'Result: ' . var_export($answer, 1) . PHP_EOL;
            }
            else {
                echo 'Not ready' . "\n";
            }
        }
        catch (Exception $e) {
            echo 'E: ' . $e->getMessage() . ' [' . $e->getCode() . ']' . "\n";
        }
        $kkm->closeConnection();
    } else {
	    echo 'Invalid callback' . PHP_EOL;
    }
    if ($inputline == 'exit') {
        break;
    }
}
echo 'Exit' . "\n";
fclose($inputhdl);

