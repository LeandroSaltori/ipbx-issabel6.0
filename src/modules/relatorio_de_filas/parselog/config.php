<?php
require_once("dblib.php");
require_once("misc.php");

$queue_log_dir  = '/var/log/asterisk/';
$queue_log_file = 'queue_log';

$dbhost = 'localhost';
$dbname = 'qstatslite';
$dbuser = 'root';
$dbpass = '';

// Deteccao automatica da senha root do MySQL no /etc/issabel.conf
if (empty($dbpass) && file_exists('/etc/issabel.conf')) {
    $lines = file('/etc/issabel.conf', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($lines as $line) {
        if (strpos($line, '=') === false) continue;
        list($k, $v) = explode('=', $line, 2);
        if (trim($k) === 'mysqlrootpwd') {
            $dbpass = trim($v);
            break;
        }
    }
}

$midb = new dbcon($dbhost, $dbuser, $dbpass, $dbname, true);
$self = isset($_SERVER['PHP_SELF']) ? $_SERVER['PHP_SELF'] : '';

$DB_DEBUG = false;

?>
