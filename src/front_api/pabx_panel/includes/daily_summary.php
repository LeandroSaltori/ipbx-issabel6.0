<?php
/** Resumo diário opcional: números reais do dia anterior (externas, repetidas ocultas), enviado por WhatsApp e/ou e-mail. */

function dsMetrics($date) {
    $m = ['date' => $date, 'ok' => false, 'total' => 0, 'atendidas' => 0, 'perdidas' => 0, 'tma' => 0, 'tme' => 0, 'pq_n' => 0, 'pq_media' => null];
    $pdo = function_exists('getAsteriskPdoConnection') ? getAsteriskPdoConnection('asteriskcdrdb') : null;
    if (!$pdo) return $m;
    try {
        $st = $pdo->prepare("SELECT COUNT(*) t, SUM(disposition='ANSWERED') a, AVG(CASE WHEN disposition='ANSWERED' THEN billsec END) tma, AVG(CASE WHEN disposition='ANSWERED' THEN GREATEST(duration-billsec,0) END) tme FROM cdr WHERE calldate BETWEEN :s AND :e AND " . cdrDedupCond(60, true) . " AND " . cdrTypeCond('external'));
        $st->execute([':s' => "$date 00:00:00", ':e' => "$date 23:59:59"]);
        $r = $st->fetch(PDO::FETCH_ASSOC);
        $m['total'] = (int)$r['t']; $m['atendidas'] = (int)$r['a']; $m['perdidas'] = $m['total'] - $m['atendidas'];
        $m['tma'] = (int)round((float)$r['tma']); $m['tme'] = (int)round((float)$r['tme']); $m['ok'] = true;
    } catch (Throwable $e) { return $m; }
    // Satisfação do dia (mesma tabela da tela Pesquisa de Satisfação)
    try {
        $pq = null;
        try { if ($pdo->query("SELECT 1 FROM pesquisa LIMIT 1") !== false) $pq = $pdo; } catch (Throwable $e) {}
        if (!$pq && is_file('/var/www/db/pesquisa.db')) $pq = new PDO('sqlite:/var/www/db/pesquisa.db');
        if ($pq) {
            $map = ['EXCELENTE' => 5, 'OTIMO' => 5, 'ÓTIMO' => 5, '5' => 5, 'MUITO BOM' => 4, '4' => 4, 'BOM' => 3, 'MEDIO' => 3, 'MÉDIO' => 3, 'REGULAR' => 3, '3' => 3, 'RUIM' => 2, '2' => 2, 'PESSIMO' => 1, 'PÉSSIMO' => 1, '1' => 1];
            $s = $pq->prepare("SELECT avaliacao FROM pesquisa WHERE data = ?"); $s->execute([$date]);
            $n = 0; $sum = 0;
            foreach ($s->fetchAll(PDO::FETCH_COLUMN) as $a) { $v = $map[mb_strtoupper(trim((string)$a))] ?? 0; if ($v) { $n++; $sum += $v; } }
            if ($n > 0) { $m['pq_n'] = $n; $m['pq_media'] = round($sum / $n, 1); }
        }
    } catch (Throwable $e) {}
    return $m;
}

function dsText($m) {
    $fmt = function ($v) { return sprintf('%d:%02d', intdiv((int)$v, 60), (int)$v % 60); };
    $d = date('d/m/Y', strtotime($m['date']));
    if (!$m['ok']) return "📊 *Resumo diário IPbx Prisma ($d)*\nNão foi possível ler as chamadas do dia (banco de chamadas indisponível).";
    if ($m['total'] === 0) return "📊 *Resumo diário IPbx Prisma ($d)*\nSem ligações de clientes neste dia.";
    $t = "📊 *Resumo diário IPbx Prisma ($d)*\nLigações de clientes (externas): {$m['total']}\nAtendidas: {$m['atendidas']} | Não atendidas: {$m['perdidas']}\nTMA (conversa): " . $fmt($m['tma']) . " | TME (espera): " . $fmt($m['tme']);
    $t .= $m['pq_n'] > 0 ? "\nSatisfação: nota média {$m['pq_media']} ({$m['pq_n']} avaliações)" : "\nSatisfação: Sem avaliação neste dia";
    return $t . "\n_Chamadas repetidas (que tocaram em vários ramais) contadas uma vez._";
}

/** Conteúdo do Resumo Diário (ontem) para os Relatórios Agendados: texto de WhatsApp e corpo de e-mail. */
function dsSchedulePayload() {
    $txt = dsText(dsMetrics(date('Y-m-d', strtotime('-1 day'))));
    $html = '<div style="font-family:Arial,sans-serif;font-size:14px">' . nl2br(htmlspecialchars(str_replace(['*', '_'], '', $txt))) . '</div>';
    return ['body_html' => $html, 'attachments' => [], 'wa_text' => $txt];
}

/** Quem tinha ligado o Resumo Diário na versão anterior passa a ter um agendamento comum em Relatórios Agendados (uma vez só). */
function dsMigrateToSchedule() {
    if (getSetting('daily_summary_enabled') !== '1' || getSetting('daily_summary_migrated') === '1') return;
    $f = __DIR__ . '/../config/scheduled_reports.json';
    $sch = is_file($f) ? (json_decode((string)file_get_contents($f), true) ?: []) : [];
    $wa = trim((string)getSetting('daily_summary_wa')); $em = trim((string)getSetting('daily_summary_email'));
    $nums = preg_split('/[\s,;]+/', $wa, -1, PREG_SPLIT_NO_EMPTY) ?: [];
    $ch = ($nums && $em !== '') ? 'ambos' : ($em !== '' ? 'email' : 'whatsapp');
    if (!$nums && $em === '') { saveSetting('daily_summary_migrated', '1'); return; }
    $time = getSetting('daily_summary_time') ?: '08:00';
    $last = getSetting('daily_summary_last') === date('Y-m-d') ? date('Y-m-d H:i:s') : '-';
    foreach ($nums ?: [''] as $k => $n) {
        $sch[] = ['id' => uniqid('sch_'), 'title' => 'Resumo Diário', 'report_type' => 'Resumo Diário', 'frequency' => 'diario', 'send_time' => $time, 'channel' => $k === 0 ? $ch : 'whatsapp',
                  'email_to' => $k === 0 ? $em : '', 'whatsapp_to' => $n, 'status' => 'active', 'created_at' => date('Y-m-d H:i:s'), 'last_sent' => $last, 'source' => 'Migrado'];
    }
    if (@file_put_contents($f, json_encode($sch, JSON_PRETTY_PRINT)) !== false) { saveSetting('daily_summary_migrated', '1'); saveSetting('daily_summary_enabled', '0'); }
}
