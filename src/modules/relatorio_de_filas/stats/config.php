<?php
require_once("dblib.php");
require_once("misc.php");

// Credentials for MYSQL database
$dbhost = 'localhost';
$dbname = 'qstatslite';
$dbuser = 'root';
$dbpass = '';

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

// Credentials for AMI (for the realtime tab to work)
$manager_host   = "127.0.0.1";
$manager_user   = "admin";
$manager_secret = "amp111";

if (file_exists('/etc/asterisk/manager.conf')) {
    $mContent = file_get_contents('/etc/asterisk/manager.conf');
    if (preg_match('/secret\s*=\s*([^\s;]+)/i', $mContent, $mSec)) {
        $manager_secret = trim($mSec[1]);
    }
}

// Available languages "es", "en", "ru", "de", "fr", "pt_BR"
$language = file_exists(__DIR__ . "/lang/pt_BR.php") ? "pt_BR" : "es";

require_once("lang/$language.php");

$midb = new dbcon($dbhost, $dbuser, $dbpass, $dbname, true);

$self = isset($_SERVER['PHP_SELF']) ? $_SERVER['PHP_SELF'] : '';

$DB_DEBUG = false; 

session_start();
//session_register("QSTATS");
header('content-type: text/html; charset: utf-8'); 

?>
