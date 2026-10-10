#!/usr/bin/php -q
<?php
/**
 * Script de Integração AGI - IPbx Prisma x Prismabot (Com Suporte a Transcrição IA & NPS)
 */

ob_implicit_flush(true);
set_time_limit(30);

$in = fopen("php://stdin", "r");
$out = fopen("php://stdout", "w");

function verbose($msg, $level = 1) {
    global $out;
    fwrite($out, "VERBOSE \"$msg\" $level\n");
    fflush($out);
}

$agi_vars = [];
while ($env = fgets($in)) {
    $env = trim($env);
    if (empty($env)) break;
    if (preg_match('/^agi_([a-z0-9_]+)\:\s+(.*)$/i', $env, $match)) {
        $agi_vars[$match[1]] = $match[2];
    }
}

$target_phone = isset($argv[1]) ? preg_replace('/\D/', '', $argv[1]) : '';
$selected_option = isset($argv[2]) ? trim($argv[2]) : '1';
$extension_num = isset($argv[3]) ? trim($argv[3]) : '';
$queue_num = isset($argv[4]) ? trim($argv[4]) : '8000';
$ring_time = isset($argv[5]) ? trim($argv[5]) : '';
$unique_id = isset($agi_vars['uniqueid']) ? $agi_vars['uniqueid'] : 'AGI_CALL_' . time();

verbose("Iniciando AGI Prismabot - Opcao: " . $selected_option);

if (empty($target_phone)) {
    verbose("Erro: Telefone de destino vazio. Abortando.");
    fclose($in);
    fclose($out);
    exit(1);
}

$script_path = "/var/www/html/front_prisma/index.php";
if (!file_exists($script_path)) $script_path = __DIR__ . '/index.php';

if (!file_exists($script_path)) {
    verbose("Erro: index.php nao encontrado.");
    fclose($in);
    fclose($out);
    exit(1);
}

$_SERVER['REQUEST_METHOD'] = 'GET';
$_GET['api_action'] = 'trigger_send';
$_GET['phone'] = $target_phone;
$_GET['option'] = $selected_option;
$_GET['call_id'] = $unique_id;
$_GET['extension'] = $extension_num;
$_GET['queue'] = $queue_num;
$_GET['ringtime'] = $ring_time;

$old_error_level = error_reporting(0);
ob_start();
try {
    include($script_path);
} catch (Exception $e) {
    verbose("Excecao AGI: " . $e->getMessage());
}
$output_response = ob_get_clean();
error_reporting($old_error_level);

verbose("Resposta Prismabot: " . trim(strip_tags($output_response)));
fclose($in);
fclose($out);
exit(0);
?>
