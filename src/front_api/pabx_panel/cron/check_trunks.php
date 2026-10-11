<?php
/**
 * IPbx Prisma - Cron: alerta por WhatsApp quando um tronco cai (e quando volta).
 * Executar a cada minuto (instalado em /etc/cron.d/ipbx-front-api pelo instalar-front-api.sh).
 */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once __DIR__ . '/../includes/functions.php';
cronHeartbeat('check_trunks');
$r = checkTrunkAlerts();
if ($r['alerts'] > 0) pabx_log('whatsapp', 'INFO', "Alerta de tronco: {$r['alerts']} aviso(s) enviado(s), {$r['down']} tronco(s) fora do ar");
echo "OK checked={$r['checked']} down={$r['down']} alerts={$r['alerts']}" . ($r['error'] ? " ({$r['error']})" : '') . "\n";
