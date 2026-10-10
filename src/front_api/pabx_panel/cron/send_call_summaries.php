<?php
/**
 * IPbx Prisma - Cron: resumo por IA e link assinado da gravação, enviados ao atendente por WhatsApp
 * Executar a cada minuto (instalado em /etc/cron.d/ipbx-front-api pelo instalar-front-api.sh):
 *   * * * * * asterisk php /var/www/html/front_api/pabx_panel/cron/send_call_summaries.php
 */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

require_once __DIR__ . '/../includes/functions.php';

$cdr = getAsteriskPdoConnection('asteriskcdrdb');
if (!$cdr) {
    fwrite(STDERR, "[-] asteriskcdrdb inacessível.\n");
    exit(1);
}
$r = processCallSummaryNotifications($cdr);
if (!empty($r['error'])) {
    pabx_log('whatsapp', 'ERROR', 'Cron resumo de chamadas: ' . $r['error']);
    fwrite(STDERR, "[-] " . $r['error'] . "\n");
    exit(1);
}
if ($r['processed'] > 0) {
    pabx_log('whatsapp', 'INFO', "Cron resumo de chamadas: {$r['processed']} chamada(s), {$r['sent']} enviada(s), {$r['skipped']} ignorada(s)");
}
echo "OK processed={$r['processed']} sent={$r['sent']} skipped={$r['skipped']}\n";
