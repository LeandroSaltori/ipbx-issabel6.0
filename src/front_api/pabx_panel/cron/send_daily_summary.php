<?php
/**
 * IPbx Prisma - Cron: Resumo diário (opcional, só envia se o cliente ativou em Configurações > Resumo Diário).
 * Executado a cada minuto pelo cron do painel; envia uma vez por dia a partir do horário escolhido.
 */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once __DIR__ . '/../includes/functions.php';
cronHeartbeat('send_daily_summary');
if ((getSetting('daily_summary_enabled') !== '' ? getSetting('daily_summary_enabled') : '0') !== '1') exit("Resumo diário desativado.\n");
$at = preg_match('/^\d{2}:\d{2}$/', (string)(getSetting('daily_summary_time') !== '' ? getSetting('daily_summary_time') : '')) ? (getSetting('daily_summary_time') !== '' ? getSetting('daily_summary_time') : '08:00') : '08:00';
if (date('H:i') < $at) exit("Ainda não é a hora.\n");
if ((getSetting('daily_summary_last') !== '' ? getSetting('daily_summary_last') : '') === date('Y-m-d')) exit("Já enviado hoje.\n");
saveSetting('daily_summary_last', date('Y-m-d'));
$r = dsSend(date('Y-m-d', strtotime('-1 day')));
echo "Resumo enviado: " . json_encode($r) . "\n";
panelRevBump('wa_sends');
