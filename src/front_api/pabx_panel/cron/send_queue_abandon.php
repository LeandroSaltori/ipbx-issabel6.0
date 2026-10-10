<?php
/**
 * IPbx Prisma - Cron: notificação WhatsApp de abandono de fila (cliente + supervisor)
 * Executar a cada minuto (instalado em /etc/cron.d/ipbx-front-api pelo instalar-front-api.sh):
 *   * * * * * asterisk php /var/www/html/front_api/pabx_panel/cron/send_queue_abandon.php
 */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

require_once __DIR__ . '/../includes/functions.php';

$ast = null;
foreach (['asterisk', 'asteriskcdrdb'] as $dbname) {
    $conn = getAsteriskPdoConnection($dbname);
    if (!$conn) continue;
    try {
        $conn->query("SELECT id FROM queue_log LIMIT 1");
        $ast = $conn;
        break;
    } catch (Exception $e) {}
}
if (!$ast) {
    fwrite(STDERR, "[-] queue_log não encontrado (asterisk/asteriskcdrdb) ou banco inacessível.\n");
    exit(1);
}

$r = processQueueAbandonNotifications($ast);
if (!empty($r['error'])) {
    pabx_log('whatsapp', 'ERROR', 'Cron abandono de fila: ' . $r['error']);
    fwrite(STDERR, "[-] " . $r['error'] . "\n");
    exit(1);
}
if ($r['processed'] > 0) {
    pabx_log('whatsapp', 'INFO', "Cron abandono de fila: {$r['processed']} evento(s), {$r['sent']} enviado(s), {$r['skipped']} ignorado(s)");
}
echo "OK processed={$r['processed']} sent={$r['sent']} skipped={$r['skipped']}\n";
