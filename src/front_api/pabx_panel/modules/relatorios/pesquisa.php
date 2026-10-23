<?php
/**
 * IPbx Prisma - Relatório de Pesquisa de Satisfação (pós-atendimento por ligação)
 *
 * Portado do módulo clássico "Pesquisa de Satisfação" (src/modules/pesquisa), mesma fonte de dados:
 * tabela `pesquisa` do banco asteriskcdrdb (ou /var/www/db/pesquisa.db, como no painel clássico) e
 * chamadas transferidas para a pesquisa no CDR. Só números reais: nada é estimado ou preenchido.
 */

// ---------------------------------------------------------------------------
// Conexões: CDR (MySQL) e tabela da pesquisa (MySQL ou SQLite legado)
// ---------------------------------------------------------------------------
$cdr = function_exists('getAsteriskPdoConnection') ? getAsteriskPdoConnection('asteriskcdrdb') : null;
$pq = null; $pq_src = '';
if ($cdr) {
    try { if ($cdr->query("SELECT 1 FROM pesquisa LIMIT 1") !== false) { $pq = $cdr; $pq_src = 'mysql'; } } catch (Throwable $e) {}
}
if (!$pq && is_file('/var/www/db/pesquisa.db')) {
    try { $pq = new PDO('sqlite:/var/www/db/pesquisa.db'); $pq->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_SILENT); $pq_src = 'sqlite'; } catch (Throwable $e) { $pq = null; }
}

// Faixas de avaliação (mesmos textos aceitos pelo painel clássico)
$PQ_LVL = [
    5 => ['label' => 'Excelente / Ótimo', 'in' => ['EXCELENTE', 'OTIMO', 'ÓTIMO', '5'],            'badge' => 'bg-emerald-500/15 text-emerald-300 border-emerald-500/30', 'color' => '#10b981'],
    4 => ['label' => 'Muito Bom',         'in' => ['MUITO BOM', '4'],                                'badge' => 'bg-blue-500/15 text-blue-300 border-blue-500/30',          'color' => '#3b82f6'],
    3 => ['label' => 'Bom / Regular',     'in' => ['BOM', 'MEDIO', 'MÉDIO', 'REGULAR', '3'],         'badge' => 'bg-amber-500/15 text-amber-300 border-amber-500/30',       'color' => '#f59e0b'],
    2 => ['label' => 'Ruim',              'in' => ['RUIM', '2'],                                     'badge' => 'bg-orange-500/15 text-orange-300 border-orange-500/30',    'color' => '#f97316'],
    1 => ['label' => 'Péssimo',           'in' => ['PESSIMO', 'PÉSSIMO', '1'],                       'badge' => 'bg-rose-500/15 text-rose-300 border-rose-500/30',          'color' => '#ef4444'],
];
$PQ_NONE = ['NAO AVALIOU', 'NÃO AVALIOU', 'ABANDONOU', 'SEM RESPOSTA', 'DESISTIU', '0', ''];
$pq_in = function (array $l) { return "'" . implode("','", array_map(function ($v) { return str_replace("'", "''", $v); }, $l)) . "'"; };
$pq_lvl_of = function ($v) use ($PQ_LVL) {
    $u = mb_strtoupper(trim((string)$v));
    foreach ($PQ_LVL as $n => $d) { if (in_array($u, $d['in'], true)) return $n; }
    return 0;
};
$pq_sol_of = function ($v) {
    $u = mb_strtoupper(trim((string)$v));
    if (in_array($u, ['SIM', '1'], true)) return 'SIM';
    if (in_array($u, ['NAO', 'NÃO', '2'], true)) return 'NAO';
    return '';
};

// Chamadas transferidas para a pesquisa (mesmo critério do painel clássico)
$PQ_CDR_WHERE = "(dst IN ('9000','8996','9999','8888') OR dst LIKE '%9000%' OR dst LIKE '%8996%' OR dcontext LIKE '%pesquisa%' OR dstchannel LIKE '%pesquisa%' OR channel LIKE '%pesquisa%' OR lastdata LIKE '%pesquisa%')";

// ---------------------------------------------------------------------------
// Filtros
// ---------------------------------------------------------------------------
$preset = $_GET['preset'] ?? 'month';
if (!in_array($preset, ['today', 'yesterday', 'week', 'month', 'all', 'custom'], true)) $preset = 'month';
if ($preset === 'today')          { $ds = $de = date('Y-m-d'); }
elseif ($preset === 'yesterday')  { $ds = $de = date('Y-m-d', strtotime('yesterday')); }
elseif ($preset === 'week')       { $ds = date('Y-m-d', strtotime('-7 days')); $de = date('Y-m-d'); }
elseif ($preset === 'all')        { $ds = $de = ''; }
elseif ($preset === 'custom')     {
    $ds = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)($_GET['date_start'] ?? '')) ? $_GET['date_start'] : date('Y-m-01');
    $de = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)($_GET['date_end'] ?? '')) ? $_GET['date_end'] : date('Y-m-d');
}
else { $ds = date('Y-m-01'); $de = date('Y-m-d'); }
$f_op  = preg_replace('/[^0-9A-Za-z_\-]/', '', (string)($_GET['operador'] ?? ''));
$f_av  = $_GET['avaliacao'] ?? '';
if (!in_array($f_av, ['', '5', '4', '3', '2', '1', '0'], true)) $f_av = '';
$f_sol = $_GET['solucao'] ?? '';
if (!in_array($f_sol, ['', 'SIM', 'NAO'], true)) $f_sol = '';
$per_page = (int)($_GET['per_page'] ?? 20); if (!in_array($per_page, [20, 50, 100], true)) $per_page = 20;
$page = max(1, (int)($_GET['page'] ?? 1));

$w = []; $p = [];
if ($ds !== '') { $w[] = "data >= ?"; $p[] = $ds; }
if ($de !== '') { $w[] = "data <= ?"; $p[] = $de; }
if ($f_op !== '') { $w[] = "operador = ?"; $p[] = $f_op; }
$w_base = $w; $p_base = $p;     // sem filtros de nota/resolução (usado nos cartões)
if ($f_av !== '') {
    if ($f_av === '0') $w[] = "(UPPER(avaliacao) IN (" . $pq_in($PQ_NONE) . ") OR avaliacao IS NULL)";
    else $w[] = "UPPER(avaliacao) IN (" . $pq_in($PQ_LVL[(int)$f_av]['in']) . ")";
}
if ($f_sol === 'SIM') $w[] = "UPPER(solucao) IN ('SIM','1')";
if ($f_sol === 'NAO') $w[] = "UPPER(solucao) IN ('NAO','NÃO','2')";
$where_sql = $w ? 'WHERE ' . implode(' AND ', $w) : '';
$where_base_sql = $w_base ? 'WHERE ' . implode(' AND ', $w_base) : '';

$cdr_where = [$PQ_CDR_WHERE]; $cdr_p = [];
if ($ds !== '') { $cdr_where[] = "calldate >= ?"; $cdr_p[] = $ds . ' 00:00:00'; }
if ($de !== '') { $cdr_where[] = "calldate <= ?"; $cdr_p[] = $de . ' 23:59:59'; }

// Lista de quem desligou sem avaliar: transferências do CDR que não geraram nenhuma avaliação
$cdr_list_where = $cdr_where;
if ($pq_src === 'mysql') {
    $cdr_list_where[] = "NOT EXISTS (SELECT 1 FROM pesquisa pq WHERE pq.data = DATE(cdr.calldate) AND RIGHT(pq.telefone, 8) = RIGHT(COALESCE(NULLIF(cdr.cnum, ''), cdr.src), 8) AND ABS(TIMESTAMPDIFF(SECOND, CONCAT(pq.data, ' ', pq.hora), cdr.calldate)) <= 300)";
}

// ---------------------------------------------------------------------------
// Cartões e gráficos (somente dados reais)
// ---------------------------------------------------------------------------
$stats = ['total' => 0, 'lvl' => [5 => 0, 4 => 0, 3 => 0, 2 => 0, 1 => 0], 'nao' => 0, 'sim' => 0, 'nao_res' => 0, 'media' => 0, 'taxa_res' => 0, 'taxa_sat' => 0, 'transf' => 0];
if ($pq) {
    try {
        $case = function ($l) use ($pq_in) { return "SUM(CASE WHEN UPPER(avaliacao) IN (" . $pq_in($l) . ") THEN 1 ELSE 0 END)"; };
        $sql = "SELECT COUNT(*) AS total";
        foreach ($PQ_LVL as $n => $d) $sql .= ", " . $case($d['in']) . " AS l$n";
        $sql .= ", SUM(CASE WHEN UPPER(avaliacao) IN (" . $pq_in($PQ_NONE) . ") OR avaliacao IS NULL THEN 1 ELSE 0 END) AS nao";
        $sql .= ", SUM(CASE WHEN UPPER(solucao) IN ('SIM','1') THEN 1 ELSE 0 END) AS sim, SUM(CASE WHEN UPPER(solucao) IN ('NAO','NÃO','2') THEN 1 ELSE 0 END) AS nres FROM pesquisa " . $where_base_sql;
        $st = $pq->prepare($sql); $st->execute($p_base); $r = $st->fetch(PDO::FETCH_ASSOC) ?: [];
        foreach ([5, 4, 3, 2, 1] as $n) $stats['lvl'][$n] = (int)($r["l$n"] ?? 0);
        $stats['nao'] = (int)($r['nao'] ?? 0); $stats['sim'] = (int)($r['sim'] ?? 0); $stats['nao_res'] = (int)($r['nres'] ?? 0);
        $stats['total'] = (int)($r['total'] ?? 0);
    } catch (Throwable $e) {}
}
if ($cdr) {
    try { $st = $cdr->prepare("SELECT COUNT(*) FROM cdr WHERE " . implode(' AND ', $cdr_where)); $st->execute($cdr_p); $stats['transf'] = (int)$st->fetchColumn(); } catch (Throwable $e) {}
}
$avaliados = array_sum($stats['lvl']);
// Total = chamadas transferidas para a pesquisa; quem desligou sem avaliar = transferidas que não geraram avaliação.
if ($stats['nao'] > 0)                    { $total = $stats['total']; $nao_av = $stats['nao']; }
elseif ($stats['transf'] > $avaliados)    { $total = $stats['transf']; $nao_av = $total - $avaliados; }
else                                      { $total = $avaliados; $nao_av = 0; }
$pts = 0; foreach ($stats['lvl'] as $n => $c) $pts += $n * $c;
$media    = $avaliados > 0 ? round($pts / $avaliados, 1) : 0;
$taxa_res = ($stats['sim'] + $stats['nao_res']) > 0 ? round($stats['sim'] / ($stats['sim'] + $stats['nao_res']) * 100, 1) : 0;
$taxa_sat = $avaliados > 0 ? round(($stats['lvl'][5] + $stats['lvl'][4]) / $avaliados * 100, 1) : 0;

// Operadores para o filtro
$operadores = [];
if ($pq) { try { foreach ($pq->query("SELECT DISTINCT operador FROM pesquisa WHERE operador IS NOT NULL AND operador <> '' AND operador <> '-' ORDER BY operador") as $__o) $operadores[] = $__o['operador']; } catch (Throwable $e) {} }
$__qm = function_exists('getQueuesMap') ? getQueuesMap() : [];
$__em = function_exists('getExtensionsMap') ? getExtensionsMap() : [];
$op_name = function ($id) use ($__qm, $__em) { return function_exists('formatEndpointName') ? formatEndpointName($id, $__qm, $__em) : $id; };

// ---------------------------------------------------------------------------
// Lista (avaliações; sem avaliação cai nas chamadas transferidas do CDR, como no painel clássico)
// ---------------------------------------------------------------------------
$rows = []; $list_total = 0; $list_from_cdr = false;
$fetch_list = function ($limit, $offset) use ($pq, $cdr, $where_sql, $p, $f_av, $PQ_NONE, $pq_in, &$list_from_cdr, $cdr_list_where, $cdr_p, $f_op) {
    $out = [];
    if ($pq) {
        try {
            $st = $pq->prepare("SELECT * FROM pesquisa $where_sql ORDER BY data DESC, hora DESC LIMIT " . (int)$limit . " OFFSET " . (int)$offset);
            $st->execute($p); $out = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
        } catch (Throwable $e) {}
    }
    if (!$out && $f_av === '0' && $cdr && $f_op === '') {
        $list_from_cdr = true;
        try {
            $st = $cdr->prepare("SELECT calldate, src, dst, channel, dstchannel, accountcode, cnum, uniqueid, recordingfile, billsec FROM cdr WHERE " . implode(' AND ', $cdr_list_where) . " ORDER BY calldate DESC LIMIT " . (int)$limit . " OFFSET " . (int)$offset);
            $st->execute($cdr_p);
            foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
                $ts = strtotime($r['calldate']);
                $op = !empty($r['src']) ? $r['src'] : '-';
                if (preg_match('/(?:SIP|PJSIP|IAX2)\/(\d{3,5})/', $r['channel'] . ' ' . $r['dstchannel'], $m)) $op = $m[1];
                $out[] = ['operador' => $op, 'fila' => $r['accountcode'], 'data' => date('Y-m-d', $ts), 'hora' => date('H:i:s', $ts), 'telefone' => !empty($r['cnum']) ? $r['cnum'] : ($r['src'] ?: '-'),
                          'avaliacao' => 'NÃO AVALIOU', 'solucao' => '', '_uid' => $r['uniqueid'], '_rec' => $r['recordingfile'], '_bill' => (int)$r['billsec']];
            }
        } catch (Throwable $e) {}
    }
    return $out;
};
if ($pq) {
    try {
        $st = $pq->prepare("SELECT COUNT(*) FROM pesquisa $where_sql"); $st->execute($p); $list_total = (int)$st->fetchColumn();
    } catch (Throwable $e) {}
    if ($list_total === 0 && $f_av === '0' && $cdr && $f_op === '') {
        try { $st = $cdr->prepare("SELECT COUNT(*) FROM cdr WHERE " . implode(' AND ', $cdr_list_where)); $st->execute($cdr_p); $list_total = (int)$st->fetchColumn(); } catch (Throwable $e) {}
    }
} elseif ($f_av === '0' && $cdr) { $list_total = $stats['transf']; }

// ---------------------------------------------------------------------------
// Exportação (todo o período/filtros; não depende da página)
// ---------------------------------------------------------------------------
if (isset($_GET['export']) && in_array($_GET['export'], ['csv', 'xlsx', 'pdf'], true)) {
    $cap = ($_GET['export'] === 'pdf') ? 5000 : 50000;
    $all = $fetch_list($cap, 0);
    $lbl = function ($v) use ($pq_lvl_of, $PQ_LVL) { $n = $pq_lvl_of($v); return $n ? $PQ_LVL[$n]['label'] : 'Não avaliou'; };
    $out = [['Data', 'Hora', 'Operador / Ramal', 'Fila', 'Telefone', 'Avaliação', 'Problema resolvido?']];
    foreach ($all as $r) {
        $sol = $pq_sol_of($r['solucao'] ?? '');
        $out[] = [date('d/m/Y', strtotime($r['data'])), $r['hora'], $op_name($r['operador']), !empty($r['fila']) ? $op_name($r['fila']) : '-', function_exists('formatPhoneNumber') ? formatPhoneNumber($r['telefone']) : $r['telefone'], $lbl($r['avaliacao']), $sol === 'SIM' ? 'Sim' : ($sol === 'NAO' ? 'Não' : '-')];
    }
    $sum = "Período: " . ($ds !== '' ? date('d/m/Y', strtotime($ds)) . ' a ' . date('d/m/Y', strtotime($de)) : 'todo o histórico') . "\n"
         . "Transferidas para a pesquisa: {$total} | Avaliadas: {$avaliados} | Média: " . ($avaliados ? number_format($media, 1, ',', '') . ' / 5' : '-')
         . " | Resolução: " . ($taxa_res ? number_format($taxa_res, 1, ',', '') . '%' : '-') . " | Desligou sem avaliar: {$nao_av}";
    reportExportSend($_GET['export'], 'pesquisa_satisfacao_' . date('Ymd_His'), 'Pesquisa de Satisfação - IPbx Prisma', $sum, $out);
}

// Página atual + dados do CDR (gravação e duração) para cada linha
$total_pages = max(1, (int)ceil($list_total / $per_page));
if ($page > $total_pages) $page = $total_pages;
$rows = $fetch_list($per_page, ($page - 1) * $per_page);
if ($cdr) {
    foreach ($rows as &$__r) {
        if (!empty($__r['_uid']) || empty($__r['data'])) continue;
        $__ts = strtotime(trim($__r['data']) . ' ' . trim($__r['hora']));
        $__tel = preg_replace('/\D/', '', (string)$__r['telefone']); if (strlen($__tel) > 4) $__tel = substr($__tel, -8);
        if (!$__ts || $__tel === '') continue;
        try {
            $st = $cdr->prepare("SELECT uniqueid, recordingfile, billsec, accountcode FROM cdr WHERE calldate BETWEEN ? AND ? AND (src LIKE ? OR dst LIKE ? OR channel LIKE ? OR dstchannel LIKE ?)
                                 ORDER BY (CASE WHEN recordingfile IS NOT NULL AND recordingfile <> '' THEN 1 ELSE 0 END) DESC, ABS(TIMESTAMPDIFF(SECOND, calldate, ?)) ASC LIMIT 1");
            $st->execute([date('Y-m-d H:i:s', $__ts - 300), date('Y-m-d H:i:s', $__ts + 120), "%$__tel%", "%$__tel%", "%$__tel%", "%$__tel%", date('Y-m-d H:i:s', $__ts)]);
            if ($c = $st->fetch(PDO::FETCH_ASSOC)) {
                $__r['_uid'] = $c['uniqueid']; $__r['_rec'] = $c['recordingfile']; $__r['_bill'] = (int)$c['billsec'];
                if (empty($__r['fila']) && !empty($c['accountcode'])) $__r['fila'] = $c['accountcode'];
            }
        } catch (Throwable $e) {}
    }
    unset($__r);
}

$q = $_GET; unset($q['page'], $q['export']); $q['module'] = 'relatorios'; $q['action'] = 'pesquisa';
$link = function ($pg) use ($q) { $q['page'] = $pg; return 'index.php?' . htmlspecialchars(http_build_query($q)); };
$fmt_n = function ($n) { return number_format((int)$n, 0, ',', '.'); };
$fmt_dur = function ($s) { $s = (int)$s; return sprintf('%02d:%02d', intdiv($s, 60), $s % 60); };
$can_listen = function_exists('hasUserPermission') ? hasUserPermission('listen_recordings') : false;
$btn_preset = function ($k, $lbl) use ($preset) {
    return '<button type="button" onclick="pqPreset(\'' . $k . '\')" class="px-3 py-1.5 rounded-lg font-bold transition ' . ($preset === $k ? 'bg-brand-600 text-white shadow' : 'text-slate-400 hover:text-white hover:bg-slate-800') . '">' . $lbl . '</button>';
};
require_once dirname(__DIR__, 2) . '/includes/audio_player.php';
?>
<div class="space-y-6">
    <div class="bg-slate-900/90 border border-slate-800 rounded-xl p-5 shadow-xl space-y-4">
        <div class="flex flex-col md:flex-row md:items-center justify-between gap-3 border-b border-slate-800 pb-3">
            <div>
                <h3 class="text-sm font-bold text-white flex items-center gap-2"><i class="fa-solid fa-star text-amber-400"></i> Pesquisa de Satisfação</h3>
                <span class="text-xs text-slate-400">Nota dada pelo cliente ao final do atendimento, por ligação (transferência para a pesquisa, discando 9000 ou 8996).</span>
            </div>
            <div class="flex items-center gap-2 flex-wrap print:hidden">
                <button type="button" onclick="pqExport('csv')" title="Exportar CSV" class="p-2.5 bg-slate-800 hover:bg-slate-700 text-emerald-400 border border-slate-700 rounded-xl transition"><i class="fa-solid fa-file-csv text-base"></i></button>
                <button type="button" onclick="pqExport('xlsx')" title="Exportar Excel" class="p-2.5 bg-slate-800 hover:bg-slate-700 text-green-400 border border-slate-700 rounded-xl transition"><i class="fa-solid fa-file-excel text-base"></i></button>
                <button type="button" onclick="pqExport('pdf')" title="Exportar PDF" class="p-2.5 bg-slate-800 hover:bg-slate-700 text-rose-400 border border-slate-700 rounded-xl transition"><i class="fa-solid fa-file-pdf text-base"></i></button>
            </div>
        </div>

        <form method="GET" action="index.php" id="pq-form" class="flex flex-wrap items-center gap-2 text-xs">
            <input type="hidden" name="module" value="relatorios"><input type="hidden" name="action" value="pesquisa">
            <input type="hidden" name="per_page" value="<?php echo $per_page; ?>"><input type="hidden" name="preset" id="pq-preset" value="<?php echo htmlspecialchars($preset); ?>">
            <div class="flex items-center bg-slate-950 border border-slate-800 rounded-xl p-1 gap-1 flex-wrap">
                <?php echo $btn_preset('today', 'Hoje') . $btn_preset('yesterday', 'Ontem') . $btn_preset('week', '7 dias') . $btn_preset('month', 'Este mês') . $btn_preset('all', 'Tudo'); ?>
                <button type="button" onclick="document.getElementById('pq-custom').classList.toggle('hidden'); document.getElementById('pq-preset').value='custom';" class="px-3 py-1.5 rounded-lg font-bold transition <?php echo $preset === 'custom' ? 'bg-brand-600 text-white shadow' : 'text-slate-400 hover:text-white hover:bg-slate-800'; ?>">Personalizado</button>
            </div>
            <div id="pq-custom" class="<?php echo $preset === 'custom' ? 'flex' : 'hidden'; ?> items-center gap-2">
                <input type="date" name="date_start" value="<?php echo htmlspecialchars($ds); ?>" class="px-3 py-1.5 bg-slate-950 border border-slate-800 rounded-xl text-white text-xs">
                <span class="text-slate-500 font-bold">até</span>
                <input type="date" name="date_end" value="<?php echo htmlspecialchars($de); ?>" class="px-3 py-1.5 bg-slate-950 border border-slate-800 rounded-xl text-white text-xs">
            </div>
            <select name="operador" class="px-3 py-1.5 bg-slate-950 border border-slate-800 rounded-xl text-white text-xs">
                <option value="">Todos os operadores</option>
                <?php foreach ($operadores as $o): ?><option value="<?php echo htmlspecialchars($o); ?>" <?php echo $f_op === $o ? 'selected' : ''; ?>><?php echo htmlspecialchars($op_name($o)); ?></option><?php endforeach; ?>
            </select>
            <select name="avaliacao" class="px-3 py-1.5 bg-slate-950 border border-slate-800 rounded-xl text-white text-xs">
                <option value="">Todas as notas</option>
                <?php foreach ($PQ_LVL as $n => $d): ?><option value="<?php echo $n; ?>" <?php echo $f_av === (string)$n ? 'selected' : ''; ?>><?php echo $d['label'] . ' (' . $n . ')'; ?></option><?php endforeach; ?>
                <option value="0" <?php echo $f_av === '0' ? 'selected' : ''; ?>>Desligou sem avaliar</option>
            </select>
            <select name="solucao" class="px-3 py-1.5 bg-slate-950 border border-slate-800 rounded-xl text-white text-xs">
                <option value="">Resolução: todas</option>
                <option value="SIM" <?php echo $f_sol === 'SIM' ? 'selected' : ''; ?>>Problema resolvido</option>
                <option value="NAO" <?php echo $f_sol === 'NAO' ? 'selected' : ''; ?>>Não resolvido</option>
            </select>
            <button type="submit" class="px-3.5 py-1.5 bg-brand-600 hover:bg-brand-500 text-white rounded-xl font-bold transition flex items-center gap-1" style="white-space:nowrap !important;flex-shrink:0 !important;width:auto !important;overflow:visible !important;text-overflow:clip !important"><i class="fa-solid fa-filter"></i> Filtrar</button>
        </form>
    </div>

<?php if (!$pq && !$cdr): ?>
    <div class="p-4 bg-amber-500/10 border border-amber-500/30 rounded-xl text-amber-300 text-sm">Não foi possível acessar os dados da pesquisa. Confira a conexão com o banco de chamadas em Configurações &gt; APIs &amp; Conexões.</div>
<?php else: ?>
    <div class="grid grid-cols-2 lg:grid-cols-5 gap-3">
        <div class="bg-slate-900/90 border border-slate-800 rounded-2xl p-4"><span class="text-[10px] text-slate-400 font-bold uppercase tracking-wider block">Transferidas p/ pesquisa</span><span class="text-2xl font-black text-white block mt-1"><?php echo $fmt_n($total); ?></span><span class="text-[10px] text-slate-500">Chamadas que foram à pesquisa</span></div>
        <div class="bg-slate-900/90 border border-slate-800 rounded-2xl p-4"><span class="text-[10px] text-amber-400 font-bold uppercase tracking-wider block">Média de satisfação</span><span class="text-2xl font-black text-white block mt-1"><?php echo $avaliados ? number_format($media, 1, ',', '') : '-'; ?><span class="text-xs text-amber-400"> / 5,0</span></span><span class="text-[10px] text-slate-500"><?php echo $fmt_n($avaliados); ?> avaliadas</span></div>
        <div class="bg-slate-900/90 border border-slate-800 rounded-2xl p-4"><span class="text-[10px] text-blue-400 font-bold uppercase tracking-wider block">Taxa de resolução</span><span class="text-2xl font-black text-white block mt-1"><?php echo ($stats['sim'] + $stats['nao_res']) ? number_format($taxa_res, 1, ',', '') . '%' : '-'; ?></span><span class="text-[10px] text-slate-500"><?php echo $fmt_n($stats['sim']); ?> resolvidos de <?php echo $fmt_n($stats['sim'] + $stats['nao_res']); ?></span></div>
        <div class="bg-slate-900/90 border border-slate-800 rounded-2xl p-4"><span class="text-[10px] text-emerald-400 font-bold uppercase tracking-wider block">Satisfação positiva</span><span class="text-2xl font-black text-white block mt-1"><?php echo $avaliados ? number_format($taxa_sat, 1, ',', '') . '%' : '-'; ?></span><span class="text-[10px] text-slate-500">Notas Excelente, Ótimo e Muito Bom</span></div>
        <div class="bg-slate-900/90 border border-slate-800 rounded-2xl p-4"><span class="text-[10px] text-rose-400 font-bold uppercase tracking-wider block">Desligou sem avaliar</span><span class="text-2xl font-black text-white block mt-1"><?php echo $fmt_n($nao_av); ?></span><span class="text-[10px] text-slate-500"><?php echo $total ? number_format($nao_av / $total * 100, 1, ',', '') . '% das chamadas' : '—'; ?></span></div>
    </div>

    <!-- COPILOTO DE IA: análise dos números reais da pesquisa -->
    <div class="bg-slate-900/90 border border-purple-500/20 rounded-2xl p-5 shadow-xl space-y-3">
        <div class="flex items-center justify-between flex-wrap gap-2">
            <h3 class="text-sm font-extrabold text-white flex items-center gap-2"><i class="fa-solid fa-wand-magic-sparkles text-purple-400"></i> Copiloto de IA: Analisar Pesquisa de Satisfação</h3>
            <div class="flex items-center gap-2">
                <button type="button" onclick="ipbxIaHistory('Pesquisa de Satisfação','Pesquisa de Satisfação')" style="white-space:nowrap !important;flex-shrink:0 !important;width:auto !important;overflow:visible !important;text-overflow:clip !important" class="px-4 py-1.5 bg-slate-800 hover:bg-slate-700 text-slate-200 font-bold rounded-xl text-xs border border-slate-700 flex items-center gap-2"><i class="fa-solid fa-clock-rotate-left"></i> Histórico</button>
                <button type="button" onclick="runPqAi()" id="btn-run-pq-ai" style="white-space:nowrap !important;flex-shrink:0 !important;width:auto !important;overflow:visible !important;text-overflow:clip !important" class="px-4 py-1.5 bg-purple-600 hover:bg-purple-500 text-white font-extrabold rounded-xl text-xs transition shadow-lg shadow-purple-600/30 flex items-center gap-2"><i class="fa-solid fa-sparkles"></i> Analisar com IA</button>
            </div>
        </div>
        <div id="pq-ai-container" class="bg-slate-950/80 p-4 rounded-xl border border-slate-800 hidden"><div id="pq-ai-text" class="text-slate-300 text-xs leading-relaxed">--</div></div>
    </div>

    <div class="grid grid-cols-1 lg:grid-cols-2 gap-4">
        <div class="bg-slate-900/90 border border-slate-800 rounded-2xl p-5"><h4 class="text-xs font-bold text-slate-300 uppercase tracking-wider mb-3"><i class="fa-solid fa-chart-pie text-brand-400 mr-1"></i> Distribuição das notas</h4><div class="relative" style="height:240px"><canvas id="pq-chart-notas"></canvas></div></div>
        <div class="bg-slate-900/90 border border-slate-800 rounded-2xl p-5"><h4 class="text-xs font-bold text-slate-300 uppercase tracking-wider mb-3"><i class="fa-solid fa-circle-check text-emerald-400 mr-1"></i> Resolução de problemas</h4><div class="relative" style="height:240px"><canvas id="pq-chart-res"></canvas></div></div>
    </div>

    <div class="bg-slate-900/90 border border-slate-800 rounded-xl p-5 shadow-xl">
        <div class="overflow-x-auto">
            <table class="w-full text-left text-xs">
                <thead><tr class="text-[10px] uppercase tracking-wider text-slate-400 border-b border-slate-800">
                    <th class="p-3">Operador / Ramal</th><th class="p-3">Fila</th><th class="p-3">Data</th><th class="p-3">Hora</th><th class="p-3">Duração</th><th class="p-3">Telefone</th><th class="p-3">Avaliação</th><th class="p-3">Resolvido?</th><th class="p-3 text-right">Gravação</th>
                </tr></thead>
                <tbody>
                <?php if (!$rows): ?>
                    <tr><td colspan="9" class="p-8 text-center text-slate-500">Nenhuma avaliação encontrada para os filtros escolhidos.</td></tr>
                <?php endif; ?>
                <?php foreach ($rows as $r):
                    $n = $pq_lvl_of($r['avaliacao']); $sol = $pq_sol_of($r['solucao'] ?? '');
                    $stars = $n ? str_repeat('★', $n) . str_repeat('☆', 5 - $n) : '';
                ?>
                    <tr class="border-b border-slate-800/60 hover:bg-slate-800/30">
                        <td class="p-3 text-slate-200 font-semibold"><?php echo htmlspecialchars($op_name($r['operador'])); ?></td>
                        <td class="p-3 text-slate-400"><?php echo !empty($r['fila']) ? htmlspecialchars($op_name($r['fila'])) : '-'; ?></td>
                        <td class="p-3 text-slate-300"><?php echo date('d/m/Y', strtotime($r['data'])); ?></td>
                        <td class="p-3 text-slate-300"><?php echo htmlspecialchars($r['hora']); ?></td>
                        <td class="p-3 text-slate-300"><?php echo isset($r['_bill']) ? $fmt_dur($r['_bill']) : '-'; ?></td>
                        <td class="p-3 text-slate-200 font-mono"><?php echo htmlspecialchars(function_exists('formatPhoneNumber') ? formatPhoneNumber($r['telefone']) : $r['telefone']); ?></td>
                        <td class="p-3"><?php if ($n): ?><span class="px-2 py-0.5 rounded border text-[10px] font-bold whitespace-nowrap <?php echo $PQ_LVL[$n]['badge']; ?>"><?php echo $stars . ' ' . mb_strtoupper($PQ_LVL[$n]['label']); ?></span><?php else: ?><span class="px-2 py-0.5 rounded border text-[10px] font-bold whitespace-nowrap bg-slate-700/30 text-slate-400 border-slate-600/40">NÃO AVALIOU</span><?php endif; ?></td>
                        <td class="p-3"><?php echo $sol === 'SIM' ? '<span class="px-2 py-0.5 rounded border text-[10px] font-bold bg-emerald-500/15 text-emerald-300 border-emerald-500/30">✓ SIM</span>' : ($sol === 'NAO' ? '<span class="px-2 py-0.5 rounded border text-[10px] font-bold bg-rose-500/15 text-rose-300 border-rose-500/30">✗ NÃO</span>' : '<span class="text-slate-600">-</span>'); ?></td>
                        <td class="p-3 text-right">
                            <?php if (!empty($r['_rec']) && !empty($r['_uid']) && $can_listen): ?>
                                <div class="flex items-center justify-end gap-1.5">
                                    <button type="button" onclick="ipbxOpenPlayer('<?php echo htmlspecialchars(rawurlencode($r['_uid']), ENT_QUOTES); ?>','<?php echo htmlspecialchars(addslashes($r['telefone']), ENT_QUOTES); ?>','<?php echo htmlspecialchars(addslashes($r['operador']), ENT_QUOTES); ?>')" title="Ouvir gravação" class="px-2.5 py-1 rounded-lg bg-brand-600 hover:bg-brand-500 text-white font-bold text-[11px]" style="white-space:nowrap !important;flex-shrink:0 !important;width:auto !important"><i class="fa-solid fa-play"></i> Ouvir</button>
                                    <a href="get_audio.php?uid=<?php echo urlencode($r['_uid']); ?>" download title="Baixar gravação" class="px-2.5 py-1 rounded-lg bg-slate-800 hover:bg-slate-700 text-slate-200 font-bold text-[11px] border border-slate-700" style="white-space:nowrap !important;flex-shrink:0 !important;width:auto !important"><i class="fa-solid fa-download"></i> Baixar</a>
                                </div>
                            <?php else: ?><span class="text-slate-600">-</span><?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php
            $__from = $list_total ? (($page - 1) * $per_page + 1) : 0; $__to = min($list_total, $page * $per_page);
            $__btn = 'min-w-[2rem] px-2.5 py-1.5 rounded-lg text-xs font-bold border transition text-center';
        ?>
        <div class="mt-4 pt-4 border-t border-slate-800 flex flex-col md:flex-row items-center justify-between gap-3 text-xs">
            <div class="text-slate-400">Mostrando <b class="text-white"><?php echo $__from; ?>–<?php echo $__to; ?></b> de <b class="text-white"><?php echo $fmt_n($list_total); ?></b> registros</div>
            <div class="flex items-center gap-1 flex-wrap justify-center">
                <?php if ($page > 1): ?><a href="<?php echo $link(1); ?>" class="<?php echo $__btn; ?> bg-slate-950 border-slate-800 text-slate-300 hover:bg-slate-800">«</a><a href="<?php echo $link($page - 1); ?>" class="<?php echo $__btn; ?> bg-slate-950 border-slate-800 text-slate-300 hover:bg-slate-800">‹ Anterior</a><?php endif; ?>
                <?php for ($__p = max(1, $page - 2); $__p <= min($total_pages, $page + 2); $__p++): ?><a href="<?php echo $link($__p); ?>" class="<?php echo $__btn; ?> <?php echo $__p === $page ? 'bg-brand-600 border-brand-500 text-white' : 'bg-slate-950 border-slate-800 text-slate-300 hover:bg-slate-800'; ?>"><?php echo $__p; ?></a><?php endfor; ?>
                <?php if ($page < $total_pages): ?><a href="<?php echo $link($page + 1); ?>" class="<?php echo $__btn; ?> bg-slate-950 border-slate-800 text-slate-300 hover:bg-slate-800">Próxima ›</a><a href="<?php echo $link($total_pages); ?>" class="<?php echo $__btn; ?> bg-slate-950 border-slate-800 text-slate-300 hover:bg-slate-800">»</a><?php endif; ?>
                <span class="text-slate-500 ml-2">Página <?php echo $page; ?> de <?php echo $total_pages; ?></span>
            </div>
            <label class="flex items-center gap-2 text-slate-400">Por página
                <select onchange="var u=new URL(location.href);u.searchParams.set('per_page',this.value);u.searchParams.delete('page');location.href=u" class="px-2 py-1 bg-slate-950 border border-slate-800 rounded-lg text-white text-xs"><?php foreach ([20, 50, 100] as $__pp): ?><option value="<?php echo $__pp; ?>" <?php echo $per_page === $__pp ? 'selected' : ''; ?>><?php echo $__pp; ?></option><?php endforeach; ?></select>
            </label>
        </div>
    </div>

<?php endif; ?>
</div>

<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js"></script>
<script>
async function runPqAi() {
    var btn = document.getElementById('btn-run-pq-ai'), box = document.getElementById('pq-ai-container'), txt = document.getElementById('pq-ai-text');
    var orig = btn.innerHTML; btn.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> Processando...'; btn.disabled = true;
    var esc = function (t) { var d = document.createElement('div'); d.textContent = t; return d.innerHTML; };
    try {
        var res = await fetch('index.php?api_action=analyze_pabx_insights'+(window.__ipbxDedup===undefined?'':'&dedup='+window.__ipbxDedup)+(window.__ipbxCallType===undefined?'':'&call_type='+window.__ipbxCallType)+'', {method: 'POST', headers: {'Content-Type': 'application/json'}, body: JSON.stringify({
            total: <?php echo (int)$total; ?>, atendidas: <?php echo (int)$avaliados; ?>, module: 'Pesquisa de Satisfação',
            periodo: <?php echo json_encode($ds !== '' ? date('d/m/Y', strtotime($ds)) . ' a ' . date('d/m/Y', strtotime($de)) : 'todo o histórico'); ?>,
            extras: {'Média de satisfação (1 a 5)': '<?php echo $avaliados ? number_format($media, 1, '.', '') : 'sem avaliações'; ?>', 'Satisfação positiva': '<?php echo $avaliados ? $taxa_sat . '%' : 'sem avaliações'; ?>', 'Taxa de resolução': '<?php echo ($stats['sim'] + $stats['nao_res']) ? $taxa_res . '%' : 'sem respostas'; ?>', 'Desligaram sem avaliar': '<?php echo (int)$nao_av; ?>', 'Notas 1 e 2': '<?php echo (int)($stats['lvl'][1] + $stats['lvl'][2]); ?>'}
        })});
        var data = await res.json();
        box.classList.remove('hidden');
        if (data.ai_configured === false) {
            txt.innerHTML = '<div class="p-3 bg-amber-500/10 border border-amber-500/30 rounded-xl text-amber-300"><i class="fa-solid fa-triangle-exclamation"></i> IA não configurada. Configure em <strong>Configurações &gt; Inteligência Artificial</strong>.</div>';
        } else if (data.success && data.insights) {
            txt.innerHTML = '<ul class="space-y-1.5">' + data.insights.map(function (i) { return '<li class="flex items-start gap-2"><span class="text-purple-400">•</span> ' + esc(i) + '</li>'; }).join('') + '</ul>' + (data.notice ? '<p class="text-[10px] text-slate-500 mt-2">' + esc(data.notice) + '</p>' : '');
        }
    } catch (e) { box.classList.remove('hidden'); txt.textContent = 'Erro ao processar análise: ' + e.message; }
    finally { btn.innerHTML = orig; btn.disabled = false; }
}
function pqPreset(p) { document.getElementById('pq-preset').value = p; document.getElementById('pq-form').submit(); }
function pqExport(fmt) { var u = new URL(window.location.href); u.searchParams.set('export', fmt); u.searchParams.delete('page'); window.location.href = u.toString(); }
(function () {
    if (typeof Chart === 'undefined') return;
    var notas = <?php echo json_encode(array_merge(array_map(function ($n) use ($PQ_LVL, $stats) { return ['l' => $PQ_LVL[$n]['label'] . ' (' . $n . ')', 'v' => $stats['lvl'][$n], 'c' => $PQ_LVL[$n]['color']]; }, [5, 4, 3, 2, 1]), [['l' => 'Desligou sem avaliar', 'v' => $nao_av, 'c' => '#64748b']]), JSON_UNESCAPED_UNICODE); ?>;
    var res = [{l: 'Sim (resolvido)', v: <?php echo (int)$stats['sim']; ?>, c: '#10b981'}, {l: 'Não (não resolvido)', v: <?php echo (int)$stats['nao_res']; ?>, c: '#ef4444'}];
    function donut(id, d) {
        var el = document.getElementById(id); if (!el) return;
        new Chart(el, {type: 'doughnut', data: {labels: d.map(function (x) { return x.l; }), datasets: [{data: d.map(function (x) { return x.v; }), backgroundColor: d.map(function (x) { return x.c; }), borderWidth: 0}]},
            options: {maintainAspectRatio: false, cutout: '62%', plugins: {legend: {position: 'right', labels: {color: '#cbd5e1', boxWidth: 12, font: {size: 11}}}}}});
    }
    donut('pq-chart-notas', notas); donut('pq-chart-res', res);
})();
</script>
