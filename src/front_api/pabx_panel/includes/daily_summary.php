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

/** Envia o resumo do dia indicado. $force ignora a trava de "já enviado hoje" (botão de teste). */
function dsSend($date, $force = false) {
    $out = ['wa' => null, 'email' => null];
    $m = dsMetrics($date); $txt = dsText($m);
    $wa = trim((string)(getSetting('daily_summary_wa') !== '' ? getSetting('daily_summary_wa') : ''));
    $em = trim((string)(getSetting('daily_summary_email') !== '' ? getSetting('daily_summary_email') : ''));
    foreach (preg_split('/[\s,;]+/', $wa, -1, PREG_SPLIT_NO_EMPTY) as $num) {
        $r = sendWhatsAppAPI($num, $txt, 'RESUMO_DIARIO', 'GERAL', 'RESUMO_DIARIO');
        $out['wa'][$num] = !empty($r['success']);
    }
    $cfgFile = __DIR__ . '/../config/smtp_settings.json';
    if ($em !== '' && file_exists($cfgFile)) {
        $smtp = json_decode((string)file_get_contents($cfgFile), true) ?: [];
        if (!empty($smtp)) {
            $html = '<div style="font-family:Arial,sans-serif;font-size:14px">' . nl2br(htmlspecialchars(str_replace(['*', '_'], '', $txt))) . '</div>';
            $r = sendSmtpEmailNative($smtp, $em, 'Resumo diário IPbx Prisma - ' . date('d/m/Y', strtotime($date)), $html, []);
            $out['email'] = !empty($r['success']);
        }
    }
    return $out;
}
