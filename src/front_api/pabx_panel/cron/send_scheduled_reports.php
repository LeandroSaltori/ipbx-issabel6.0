<?php
/**
 * IPbx Prisma - Cron Job / Runner para envio de Relatórios Agendados (E-mail / WhatsApp)
 * Pode ser chamado no crontab do Linux ou via tarefas agendadas do sistema.
 * Exemplo de Crontab: 0 * * * * php /var/www/html/pabx_panel/cron/send_scheduled_reports.php
 */

require_once __DIR__ . '/../includes/functions.php';

$jsonSmtpFile     = __DIR__ . '/../config/smtp_settings.json';
$jsonScheduleFile = __DIR__ . '/../config/scheduled_reports.json';
$jsonLogsFile     = __DIR__ . '/../config/smtp_logs.json';

if (!file_exists($jsonScheduleFile)) {
    exit("Nenhum agendamento encontrado.\n");
}

$schedules = json_decode(file_get_contents($jsonScheduleFile), true);
if (empty($schedules) || !is_array($schedules)) {
    exit("Nenhum agendamento ativo.\n");
}

$smtpConfig = [];
if (file_exists($jsonSmtpFile)) {
    $rawSmtp = file_get_contents($jsonSmtpFile);
    if ($rawSmtp) {
        $smtpConfig = json_decode($rawSmtp, true) ?: [];
    }
}

$dispatchLogs = [];
if (file_exists($jsonLogsFile)) {
    $rawLogs = file_get_contents($jsonLogsFile);
    if ($rawLogs) {
        $dispatchLogs = json_decode($rawLogs, true) ?: [];
    }
}

$currentTime = date('H:i');
$currentDay  = date('Y-m-d');
$currentWDay = date('N'); // 1 = Segunda, 7 = Domingo
$currentMDay = date('j'); // 1 a 31

echo "[IPbx Prisma Cron] Iniciando verificação de disparos agendados em {$currentDay} {$currentTime}...\n";

foreach ($schedules as &$s) {
    if (isset($s['status']) && $s['status'] !== 'active') continue;

    $shouldSend = false;
    $freq = strtolower($s['frequency'] ?? 'diario');

    if ($freq === 'diario') {
        $shouldSend = true;
    } elseif ($freq === 'semanal' && $currentWDay == 1) { // Toda Segunda-feira
        $shouldSend = true;
    } elseif ($freq === 'mensal' && $currentMDay == 1) { // Todo dia 1º
        $shouldSend = true;
    }

    if ($shouldSend) {
        echo " -> Executando disparo do agendamento '{$s['title']}'...\n";
        $s['last_sent'] = date('Y-m-d H:i:s');

        // Disparar via E-mail se houver destinatário e configuração SMTP
        if (!empty($s['email_to']) && !empty($smtpConfig)) {
            $subject = "📊 [Agendado] " . $s['title'];
            $payload = buildScheduledReportPayload($s['title'], $s['report_type']);
            
            $sendRes = sendSmtpEmailNative($smtpConfig, $s['email_to'], $subject, $payload['body_html'], $payload['attachments']);
            
            $statusStr = $sendRes['success'] ? 'Sucesso' : 'Falha';
            echo "    -> E-mail " . ($sendRes['success'] ? "entregue com SUCESSO e anexo CSV para {$s['email_to']}" : "FALHOU: {$sendRes['error']}") . "\n";
            
            if (function_exists('addDispatchLogRecord')) {
                addDispatchLogRecord($smtpConfig['from_email'] ?: $smtpConfig['user'], $s['email_to'], $s['title'], 'E-MAIL', $statusStr, $jsonLogsFile, $dispatchLogs);
            }
        }
    }
}

file_put_contents($jsonScheduleFile, json_encode($schedules, JSON_PRETTY_PRINT));
echo "[IPbx Prisma Cron] Verificação concluída com sucesso.\n";
