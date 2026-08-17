<?php
if(!isset($_REQUEST['file'])) {
    die("Nenhum arquivo especificado");
}

$filename2 = $_REQUEST['file'];
$filename2 = preg_replace("/\.\./","",$filename2);
$filename2 = preg_replace("/%2e/","",$filename2);
$filename2 = preg_replace("/\/\*/","/",$filename2);

// necessário para o IE, caso contrário Content-disposition é ignorado
if(ini_get('zlib.output_compression')) {
    ini_set('zlib.output_compression', 'Off');
}

if(!preg_match("/^\/var\/spool\/asterisk\/monitor/",$filename2)) {
    $filename2 = "/var/spool/asterisk/monitor/".$filename2;
}

if( $filename2 == "" ) {
    header("HTTP/1.0 404 Not Found");
    echo "ERRO: arquivo para download NÃO ESPECIFICADO. Use download.php?file=caminho_do_arquivo";
    die();
} elseif ( ! file_exists( $filename2 ) ) {
    header("HTTP/1.0 404 Not Found");
    echo "ERRO: Arquivo $filename2 não encontrado. Use download.php?file=caminho_do_arquivo";
    die();
}

header("Pragma: public"); // necessário
header("Expires: 0");
header("Cache-Control: must-revalidate, post-check=0, pre-check=0");
header("Cache-Control: private",false); // necessário para alguns navegadores
header("Content-Type: application/octet-stream");
header("Content-Disposition: attachment; filename=\"".basename($filename2)."\";" );
header("Content-Transfer-Encoding: binary");
header("Content-Length: ".filesize($filename2));
readfile("$filename2");
exit();
?>