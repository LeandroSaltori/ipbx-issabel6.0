<?php
/**
 * IPbx Prisma - Cron: envia os WhatsApps agendados (pelo painel ou pelo Chat IA) quando chega a hora.
 * Executar a cada minuto (instalado em /etc/cron.d/ipbx-front-api pelo instalar-front-api.sh):
 *   * * * * * asterisk php /var/www/html/front_api/pabx_panel/cron/send_wa_scheduled.php
 */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once __DIR__ . '/../includes/functions.php';
cronHeartbeat('send_wa_scheduled');
$r = processWaScheduled();
if ($r['sent'] || $r['failed']) pabx_log('whatsapp', 'INFO', "Envios agendados: {$r['sent']} enviado(s), {$r['failed']} com falha");
echo "OK sent={$r['sent']} failed={$r['failed']}\n";
