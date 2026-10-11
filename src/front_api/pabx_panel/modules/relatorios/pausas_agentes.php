<?php
/**
 * IPbx Prisma - Pausas e Tempo Logado dos Agentes (inspirado no módulo Call Center do Issabel)
 * Fonte: tabela real queue_log do Asterisk (ADDMEMBER/REMOVEMEMBER/PAUSE/UNPAUSE). Sem dados fictícios.
 */
$preset = $_GET['preset'] ?? 'today';
if ($preset === 'yesterday') { $sd = $ed = date('Y-m-d', strtotime('-1 day')); }
elseif ($preset === 'week')  { $sd = date('Y-m-d', strtotime('-6 days')); $ed = date('Y-m-d'); }
elseif ($preset === 'month') { $sd = date('Y-m-01'); $ed = date('Y-m-d'); }
else { $preset = 'today'; $sd = $ed = date('Y-m-d'); }
$ts_s = strtotime("$sd 00:00:00");
$ts_e = min(time(), strtotime("$ed 23:59:59"));

$ext_names = [];
try { foreach ($db->query("SELECT extension, agent_name FROM extensions_config") as $r) $ext_names[$r['extension']] = $r['agent_name']; } catch (Exception $e) {}

$agents = [];      // ext => [login, pause, pauses, reasons[]]
$pause_rows = [];  // detalhe
$err = '';
$qdb = function_exists('getAsteriskDBConnection') ? getAsteriskDBConnection() : null;
if (!$qdb && function_exists('getAsteriskPdoConnection')) $qdb = getAsteriskPdoConnection('asteriskcdrdb');
if (!$qdb) {
    $err = 'Não foi possível conectar ao banco de dados do Asterisk (queue_log).';
} else {
    try {
        $st = $qdb->prepare("SELECT time, queuename, agent, event, data1 FROM queue_log
            WHERE event IN ('ADDMEMBER','REMOVEMEMBER','PAUSE','UNPAUSE','PAUSEALL','UNPAUSEALL')
              AND ((time BETWEEN :sd AND :ed) OR (CAST(time AS UNSIGNED) BETWEEN :ts AND :te))
            ORDER BY time ASC");
        $st->execute([':sd' => "$sd 00:00:00", ':ed' => "$ed 23:59:59", ':ts' => $ts_s, ':te' => $ts_e]);
        $open_login = []; $open_pause = [];
        $toTs = function ($t) { return ctype_digit((string)$t) ? (int)$t : (int)strtotime((string)$t); };
        $evs = $st->fetchAll(PDO::FETCH_ASSOC);
        usort($evs, function ($a, $b) use ($toTs) { return $toTs($a['time']) <=> $toTs($b['time']); });
        foreach ($evs as $e) {
            if (!preg_match('/(\d{2,6})/', (string)$e['agent'], $m)) continue;
            $ex = $m[1]; $t = $toTs($e['time']); $ev = $e['event'];
            if (!isset($agents[$ex])) $agents[$ex] = ['login' => 0, 'pause' => 0, 'pauses' => 0, 'reasons' => []];
            if ($ev === 'ADDMEMBER') { if (!isset($open_login[$ex])) $open_login[$ex] = $t; }
            elseif ($ev === 'REMOVEMEMBER') {
                if (isset($open_login[$ex])) { $agents[$ex]['login'] += max(0, $t - $open_login[$ex]); unset($open_login[$ex]); }
                if (isset($open_pause[$ex])) { $d = max(0, $t - $open_pause[$ex]['t']); $agents[$ex]['pause'] += $d; $pause_rows[] = ['ext' => $ex, 'start' => $open_pause[$ex]['t'], 'end' => $t, 'dur' => $d, 'reason' => $open_pause[$ex]['r']]; unset($open_pause[$ex]); }
            } elseif ($ev === 'PAUSE' || $ev === 'PAUSEALL') {
                if (!isset($open_pause[$ex])) { $open_pause[$ex] = ['t' => $t, 'r' => trim((string)$e['data1']) ?: 'Sem motivo']; $agents[$ex]['pauses']++; }
            } else {
                if (isset($open_pause[$ex])) {
                    $d = max(0, $t - $open_pause[$ex]['t']); $agents[$ex]['pause'] += $d;
                    $agents[$ex]['reasons'][$open_pause[$ex]['r']] = ($agents[$ex]['reasons'][$open_pause[$ex]['r']] ?? 0) + $d;
                    $pause_rows[] = ['ext' => $ex, 'start' => $open_pause[$ex]['t'], 'end' => $t, 'dur' => $d, 'reason' => $open_pause[$ex]['r']];
                    unset($open_pause[$ex]);
                }
            }
        }
        // sessões ainda abertas contam até agora (ou até o fim do período)
        foreach ($open_login as $ex => $t) $agents[$ex]['login'] += max(0, $ts_e - $t);
        foreach ($open_pause as $ex => $p) {
            $d = max(0, $ts_e - $p['t']); $agents[$ex]['pause'] += $d;
            $agents[$ex]['reasons'][$p['r']] = ($agents[$ex]['reasons'][$p['r']] ?? 0) + $d;
            $pause_rows[] = ['ext' => $ex, 'start' => $p['t'], 'end' => null, 'dur' => $d, 'reason' => $p['r']];
        }
    } catch (Exception $e) { $err = 'Erro ao ler queue_log: ' . $e->getMessage(); }
}
ksort($agents);
usort($pause_rows, function ($a, $b) { return $b['start'] <=> $a['start']; });
$fmt = function ($s) { $s = (int)$s; return sprintf('%02d:%02d:%02d', intdiv($s, 3600), intdiv($s % 3600, 60), $s % 60); };
$nameOf = function ($ex) use ($ext_names) { return $ex . (isset($ext_names[$ex]) && $ext_names[$ex] !== '' ? ' - ' . $ext_names[$ex] : ''); };

$__sh_txt = "⏸️ Pausas e Tempo Logado dos Agentes\nPeríodo: " . date('d/m/Y', $ts_s) . ($sd !== $ed ? ' a ' . date('d/m/Y', strtotime($ed)) : '') . "\n";
$__sh_csv = "Agente,Tempo Logado,Tempo em Pausa,% Pausa,Qtd Pausas,Principal Motivo\n";
foreach ($agents as $ex => $a) {
    $pct = $a['login'] > 0 ? round($a['pause'] / $a['login'] * 100, 1) : 0;
    arsort($a['reasons']); $top = $a['reasons'] ? array_key_first($a['reasons']) : '-';
    $__sh_txt .= "• " . $nameOf($ex) . ": logado " . $fmt($a['login']) . ", pausa " . $fmt($a['pause']) . " ($pct%), {$a['pauses']} pausa(s)\n";
    $__sh_csv .= '"' . $nameOf($ex) . '",' . $fmt($a['login']) . ',' . $fmt($a['pause']) . ",$pct%,{$a['pauses']},\"$top\"\n";
}
if (!$agents) $__sh_txt .= "Nenhum registro de login/pausa no período.\n";
?>
<div class="space-y-5">
    <div class="bg-slate-900/90 border border-slate-800 rounded-2xl p-5 flex flex-col md:flex-row md:items-center justify-between gap-3">
        <div>
            <h2 class="text-lg font-extrabold text-white flex items-center gap-2"><i class="fa-solid fa-pause-circle text-violet-400"></i> Pausas & Tempo Logado dos Agentes</h2>
            <p class="text-xs text-slate-400 mt-1">Dados reais do <span class="font-mono">queue_log</span> do Asterisk · <?php echo date('d/m/Y', $ts_s); ?><?php echo $sd !== $ed ? ' a ' . date('d/m/Y', strtotime($ed)) : ''; ?></p>
        </div>
        <div class="flex items-center gap-1.5 flex-wrap text-xs">
            <?php foreach (['today' => 'Hoje', 'yesterday' => 'Ontem', 'week' => '7 Dias', 'month' => 'Mês'] as $k => $l): ?>
            <a href="index.php?module=relatorios&action=pausas_agentes&preset=<?php echo $k; ?>" class="px-3 py-1.5 rounded-lg font-bold border <?php echo $preset === $k ? 'bg-brand-600 text-white border-brand-500' : 'bg-slate-950 text-slate-300 border-slate-700 hover:bg-slate-800'; ?>" style="white-space:nowrap;flex-shrink:0;width:auto;overflow:visible"><?php echo $l; ?></a>
            <?php endforeach; ?>
        </div>
    </div>

    <?php if ($err): ?><div class="p-4 rounded-xl bg-rose-500/10 text-rose-300 border border-rose-500/30 text-xs font-bold"><?php echo htmlspecialchars($err); ?></div><?php endif; ?>

    <div class="bg-slate-900/90 border border-slate-800 rounded-2xl overflow-hidden">
        <table class="w-full text-xs text-left">
            <thead class="bg-slate-950 text-slate-400 uppercase text-[10px] tracking-wider"><tr>
                <th class="px-4 py-3">Agente</th><th class="px-4 py-3">Tempo Logado</th><th class="px-4 py-3">Tempo em Pausa</th><th class="px-4 py-3">% Pausa</th><th class="px-4 py-3">Pausas</th><th class="px-4 py-3">Principais Motivos</th>
            </tr></thead>
            <tbody class="divide-y divide-slate-800 text-slate-200">
            <?php foreach ($agents as $ex => $a): $pct = $a['login'] > 0 ? round($a['pause'] / $a['login'] * 100, 1) : 0; arsort($a['reasons']); ?>
                <tr class="hover:bg-slate-800/40">
                    <td class="px-4 py-3 font-bold"><?php echo htmlspecialchars($nameOf($ex)); ?></td>
                    <td class="px-4 py-3 font-mono text-emerald-300"><?php echo $fmt($a['login']); ?></td>
                    <td class="px-4 py-3 font-mono text-violet-300"><?php echo $fmt($a['pause']); ?></td>
                    <td class="px-4 py-3"><span class="px-2 py-0.5 rounded font-bold <?php echo $pct >= 20 ? 'bg-rose-500/20 text-rose-300' : 'bg-slate-800 text-slate-300'; ?>"><?php echo $pct; ?>%</span></td>
                    <td class="px-4 py-3"><?php echo (int)$a['pauses']; ?></td>
                    <td class="px-4 py-3 text-slate-400"><?php $i = 0; foreach ($a['reasons'] as $r => $s) { if ($i++ >= 3) break; echo '<span class="inline-block mr-2">' . htmlspecialchars($r) . ' <b class="text-slate-300">' . $fmt($s) . '</b></span>'; } if (!$a['reasons']) echo '-'; ?></td>
                </tr>
            <?php endforeach; ?>
            <?php if (!$agents && !$err): ?><tr><td colspan="6" class="px-4 py-8 text-center text-slate-500">Nenhum login ou pausa de agente registrado no período.</td></tr><?php endif; ?>
            </tbody>
        </table>
    </div>

    <?php if ($pause_rows): ?>
    <div class="bg-slate-900/90 border border-slate-800 rounded-2xl overflow-hidden">
        <div class="px-4 py-3 text-xs font-extrabold text-slate-300 uppercase tracking-wider border-b border-slate-800">Detalhe das Pausas</div>
        <table class="w-full text-xs text-left">
            <thead class="bg-slate-950 text-slate-400 uppercase text-[10px]"><tr><th class="px-4 py-2">Agente</th><th class="px-4 py-2">Início</th><th class="px-4 py-2">Fim</th><th class="px-4 py-2">Duração</th><th class="px-4 py-2">Motivo</th></tr></thead>
            <tbody class="divide-y divide-slate-800 text-slate-200">
            <?php foreach (array_slice($pause_rows, 0, 200) as $p): ?>
                <tr><td class="px-4 py-2"><?php echo htmlspecialchars($nameOf($p['ext'])); ?></td>
                    <td class="px-4 py-2 font-mono"><?php echo date('d/m H:i:s', $p['start']); ?></td>
                    <td class="px-4 py-2 font-mono"><?php echo $p['end'] ? date('d/m H:i:s', $p['end']) : '<span class="text-amber-300">em pausa</span>'; ?></td>
                    <td class="px-4 py-2 font-mono"><?php echo $fmt($p['dur']); ?></td>
                    <td class="px-4 py-2"><?php echo htmlspecialchars($p['reason']); ?></td></tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php endif; ?>
</div>
<script>
window.__reportShare = function () {
    return { summary: <?php echo json_encode(rtrim($__sh_txt), JSON_UNESCAPED_UNICODE); ?>, csv: <?php echo json_encode($__sh_csv, JSON_UNESCAPED_UNICODE); ?>, filename: <?php echo json_encode('pausas_agentes_' . $sd . '_' . $ed . '.csv'); ?>, title: 'Pausas e Tempo Logado dos Agentes' };
};
</script>
