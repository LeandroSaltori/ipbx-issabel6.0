<?php
/**
 * IPbx Prisma - Cron: Relatórios Agendados (E-mail e/ou WhatsApp)
 * Executar a cada minuto (instalado em /etc/cron.d/ipbx-front-api pelo instalar-front-api.sh):
 *   * * * * * asterisk php /var/www/html/front_api/pabx_panel/cron/send_scheduled_reports.php
 * Cada agendamento dispara UMA vez por dia, a partir do horário escolhido (diário; semanal = segunda; mensal = dia 1º).
 */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

require_once __DIR__ . '/../includes/functions.php';
cronHeartbeat('send_scheduled_reports');
if (function_exists('dsMigrateToSchedule')) dsMigrateToSchedule();

$jsonSmtpFile     = __DIR__ . '/../config/smtp_settings.json';
$jsonScheduleFile = __DIR__ . '/../config/scheduled_reports.json';
$jsonLogsFile     = __DIR__ . '/../config/smtp_logs.json';

if (!file_exists($jsonScheduleFile)) exit("Nenhum agendamento encontrado.\n");
$schedules = json_decode((string)file_get_contents($jsonScheduleFile), true);
if (empty($schedules) || !is_array($schedules)) exit("Nenhum agendamento ativo.\n");

$smtpConfig = file_exists($jsonSmtpFile) ? (json_decode((string)file_get_contents($jsonSmtpFile), true) ?: []) : [];
$dispatchLogs = file_exists($jsonLogsFile) ? (json_decode((string)file_get_contents($jsonLogsFile), true) ?: []) : [];

$now = date('H:i'); $today = date('Y-m-d'); $wday = (int)date('N'); $mday = (int)date('j');
$changed = false;

foreach ($schedules as &$s) {
    if (isset($s['status']) && $s['status'] !== 'active') continue;
    $freq = strtolower($s['frequency'] ?? 'diario');
    $dayOk = $freq === 'diario' || ($freq === 'semanal' && $wday === 1) || ($freq === 'mensal' && $mday === 1);
    if (!$dayOk) continue;
    $at = preg_match('/^\d{2}:\d{2}$/', (string)($s['send_time'] ?? '')) ? $s['send_time'] : '08:00';
    if ($now < $at) continue;                                              // ainda não chegou a hora
    if (substr((string)($s['last_sent'] ?? ''), 0, 10) === $today) continue; // já enviado hoje

    $s['last_sent'] = date('Y-m-d H:i:s'); $changed = true;
    $channel = $s['channel'] ?? 'email';
    echo " -> Executando '{$s['title']}' ({$channel})...\n";
    $payload = buildScheduledReportPayload($s['title'], $s['report_type']);

    if (in_array($channel, ['email', 'ambos'], true) && !empty($s['email_to']) && !empty($smtpConfig)) {
        $res = sendSmtpEmailNative($smtpConfig, $s['email_to'], "📊 [Agendado] " . $s['title'], $payload['body_html'], $payload['attachments']);
        echo "    E-mail: " . ($res['success'] ? 'OK' : 'FALHOU ' . $res['error']) . "\n";
        if (function_exists('addDispatchLogRecord')) addDispatchLogRecord($smtpConfig['from_email'] ?: ($smtpConfig['user'] ?? ''), $s['email_to'], $s['title'], 'E-MAIL', $res['success'] ? 'Sucesso' : 'Falha', $jsonLogsFile, $dispatchLogs);
    }
    if (in_array($channel, ['whatsapp', 'ambos'], true) && !empty($s['whatsapp_to'])) {
        if (!empty($payload['wa_text'])) { $r0 = sendWhatsAppAPI($s['whatsapp_to'], $payload['wa_text'], 'REL_AGENDADO', 'GERAL', 'RELATORIO_AGENDADO'); echo "    WhatsApp: " . (!empty($r0['success']) ? 'OK' : 'FALHOU') . "\n"; continue; }
        $sm = aiChatExecTool('period_summary', ['days' => 1]);
        $fmt = function ($v) { return sprintf('%d:%02d', intdiv((int)$v, 60), (int)$v % 60); };
        $txt = "📊 *{$s['title']}*\n" . (isset($sm['error']) ? '' :
              "Hoje (chamadas externas): {$sm['total']} | Atendidas: {$sm['atendidas']} | Não atendidas: {$sm['nao_atendidas']}\nTMA: " . $fmt($sm['tma_seg']) . " | TME: " . $fmt($sm['tme_seg']));
        $r = sendWhatsAppAPI($s['whatsapp_to'], $txt, 'REL_AGENDADO', 'GERAL', 'RELATORIO_AGENDADO');
        echo "    WhatsApp: " . (!empty($r['success']) ? 'OK' : 'FALHOU') . "\n";
        if (!empty($r['success']) && !empty($payload['attachments'][0]['content'])) {
            $a = $payload['attachments'][0];
            sendWhatsAppFile($s['whatsapp_to'], $a['name'], $a['content'], $a['type'] ?? 'text/csv', $s['title'], 'REL_AGENDADO', 'GERAL', 'RELATORIO_AGENDADO_ARQUIVO');
        }
    }
}
unset($s);
if ($changed) { file_put_contents($jsonScheduleFile, json_encode($schedules, JSON_PRETTY_PRINT)); panelRevBump('schedules'); panelRevBump('wa_sends'); }
echo "[IPbx Prisma Cron] Verificação concluída.\n";
