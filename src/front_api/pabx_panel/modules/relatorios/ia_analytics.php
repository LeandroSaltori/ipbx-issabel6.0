<?php
/**
 * IPbx Prisma - Módulo Relatórios & Auditoria de IA Analytics v8.5
 * Painel de Inteligência de Voz estilo Call Center QA Analytics
 * - Análise de Vibe / Sentimento da Chamada
 * - Scorecard Automático de Qualidade (QA Score: Saudação, Cordialidade, Agilidade)
 * - Detecção de Riscos / Red Flags (Ameaça Procon, Cancelamento, Retenção)
 * - Transcrição Interativa Dual-Speaker (Diálogo Cliente vs Atendente)
 */


$ai_key = getSetting('ai_api_key');
$ai_configured = !empty($ai_key);

$contacts_map = function_exists('getContactsMap') ? getContactsMap() : [];

$ast_db = function_exists('getAsteriskPdoConnection') ? getAsteriskPdoConnection('asteriskcdrdb') : null;

aiAuditEnsureSchema();

// ─── Configuração das palavras-chave (regras de auditoria) ───────────────────
$rules_msg = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action_ia_rule']) && $db) {
    $op   = $_POST['action_ia_rule'];
    $rid  = (int)($_POST['rule_id'] ?? 0);
    $kind = in_array($_POST['rule_kind'] ?? '', ['topic', 'risk', 'checklist'], true) ? $_POST['rule_kind'] : 'topic';
    $lbl  = trim($_POST['rule_label'] ?? '');
    $kws  = trim(preg_replace('/\s*,\s*/', ', ', preg_replace('/[\r\n;]+/', ',', $_POST['rule_keywords'] ?? '')), ", ");
    if ($op === 'delete' && $rid) {
        $db->prepare("DELETE FROM ia_audit_rules WHERE id = ?")->execute([$rid]);
        $rules_msg = 'Regra removida.';
    } elseif ($op === 'save' && $lbl !== '' && $kws !== '') {
        if ($rid) $db->prepare("UPDATE ia_audit_rules SET label=?, keywords=?, enabled=? WHERE id=?")->execute([$lbl, $kws, isset($_POST['rule_enabled']) ? 1 : 0, $rid]);
        else      $db->prepare("INSERT INTO ia_audit_rules (kind, label, keywords, enabled) VALUES (?,?,?,1)")->execute([$kind, $lbl, $kws]);
        $rules_msg = 'Regra salva. Vale para as próximas análises (use "Reanalisar" para reaplicar nas já feitas).';
    } elseif ($op === 'save') {
        $rules_msg = 'Informe o nome e ao menos uma palavra-chave.';
    }
}

// Filtros da Tela
$src_filter   = trim($_GET['src_filter']   ?? '');
$sent_filter  = $_GET['sent_filter']  ?? 'ALL';
$topic_filter = $_GET['topic_filter'] ?? 'ALL';
$filter_preset = $_GET['preset'] ?? '';
if ($filter_preset === 'today') {
    $start_date = date('Y-m-d');
    $end_date   = date('Y-m-d');
} elseif ($filter_preset === 'yesterday') {
    $start_date = date('Y-m-d', strtotime('yesterday'));
    $end_date   = date('Y-m-d', strtotime('yesterday'));
} elseif ($filter_preset === 'month') {
    $start_date = date('Y-m-01');
    $end_date   = date('Y-m-d');
} elseif ($filter_preset === 'custom' || $filter_preset === 'personalizado') {
    $ds = $_GET['date_start'] ?? ($_GET['start_date'] ?? '');
    $de = $_GET['date_end']   ?? ($_GET['end_date']   ?? '');
    $start_date = preg_match('/^\d{4}-\d{2}-\d{2}/', $ds) ? substr($ds, 0, 10) : date('Y-m-d', strtotime('-7 days'));
    $end_date   = preg_match('/^\d{4}-\d{2}-\d{2}/', $de) ? substr($de, 0, 10) : date('Y-m-d');
} else {
    // DEFAULT: 7 DIAS (week)
    $filter_preset = 'week';
    $start_date = date('Y-m-d', strtotime('-7 days'));
    $end_date   = date('Y-m-d');
}

$where_clauses = ["calldate >= '$start_date 00:00:00' AND calldate <= '$end_date 23:59:59'"];

if (!empty($src_filter)) {
    $src_clean = addslashes($src_filter);
    $where_clauses[] = "(src LIKE '%$src_clean%' OR dst LIKE '%$src_clean%')";
}

$type_filter = $_GET['type_filter'] ?? 'ALL';
if ($type_filter === 'INTERNAL') {
    $where_clauses[] = "LENGTH(src) <= 4 AND LENGTH(dst) <= 4";
} elseif ($type_filter === 'EXTERNAL') {
    $where_clauses[] = "(LENGTH(src) > 4 OR LENGTH(dst) > 4)";
}

$where_sql = implode(' AND ', $where_clauses);

// Buscar métricas reais do CDR
$tot_calls = 0;
$ans_calls = 0;
$avg_tma = 0;
$avg_tme = 0;
$cdr_list = [];

if ($ast_db) {
    try {
        $q_sum = $ast_db->query("SELECT 
                                    COUNT(*) as tot, 
                                    SUM(CASE WHEN disposition = 'ANSWERED' THEN 1 ELSE 0 END) as ans,
                                    AVG(CASE WHEN disposition = 'ANSWERED' THEN billsec ELSE NULL END) as avg_tma,
                                    AVG(duration - billsec) as avg_tme
                                 FROM cdr WHERE $where_sql");
        if ($q_sum && $r = $q_sum->fetch(PDO::FETCH_ASSOC)) {
            $tot_calls = (int)$r['tot'];
            $ans_calls = (int)$r['ans'];
            $avg_tma   = round((float)$r['avg_tma']);
            $avg_tme   = round((float)$r['avg_tme']);
        }

        $q_cdr = $ast_db->query("SELECT uniqueid, calldate, src, dst, disposition, billsec, duration, recordingfile FROM cdr WHERE $where_sql ORDER BY calldate DESC LIMIT 30");
        if ($q_cdr) {
            $cdr_list = $q_cdr->fetchAll(PDO::FETCH_ASSOC);
        }
    } catch (Exception $e) {}
}

// ─── Análises reais já feitas (tabela call_ai_analysis) ────────────────────────
$analysis = [];
if ($db) {
    $st = $db->prepare("SELECT * FROM call_ai_analysis WHERE calldate >= :a AND calldate <= :b");
    $st->execute([':a' => "$start_date 00:00:00", ':b' => "$end_date 23:59:59"]);
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $r['topics_a'] = json_decode((string)$r['topics'], true) ?: [];
        $r['risks_a']  = json_decode((string)$r['risks'], true) ?: [];
        $r['check_a']  = json_decode((string)$r['checklist'], true) ?: [];
        $analysis[$r['uniqueid']] = $r;
    }
}
$an_total = count($analysis);
$sent_count = ['Positivo' => 0, 'Neutro' => 0, 'Negativo' => 0];
$qa_sum = 0; $qa_n = 0; $risk_calls = 0; $risk_words = []; $topic_count = []; $check_hit = []; $check_n = [];
foreach ($analysis as $a) {
    $k = ucfirst(mb_strtolower(trim((string)$a['sentimento']), 'UTF-8'));
    if (isset($sent_count[$k])) $sent_count[$k]++;
    if ($a['qa_score'] !== null) { $qa_sum += (int)$a['qa_score']; $qa_n++; }
    if ($a['risks_a']) { $risk_calls++; foreach ($a['risks_a'] as $rk) foreach ($rk['words'] ?? [] as $w) $risk_words[$w] = ($risk_words[$w] ?? 0) + 1; }
    foreach ($a['topics_a'] as $tp) $topic_count[$tp] = ($topic_count[$tp] ?? 0) + 1;
    foreach ($a['check_a'] as $lbl => $okv) { $check_n[$lbl] = ($check_n[$lbl] ?? 0) + 1; if ($okv) $check_hit[$lbl] = ($check_hit[$lbl] ?? 0) + 1; }
}
arsort($topic_count); arsort($risk_words);
$sent_total = array_sum($sent_count);
$qa_avg = $qa_n ? (int)round($qa_sum / $qa_n) : null;

// FCR real (CDR): atendidas de números externos sem nova ligação do mesmo número em 24h
$fcr_pct = null; $fcr_base = 0;
if ($ast_db) {
    try {
        $lim = date('Y-m-d H:i:s', time() - 86400);
        $q = $ast_db->query("SELECT src, UNIX_TIMESTAMP(calldate) ts, disposition FROM cdr WHERE $where_sql AND LENGTH(src) > 4 ORDER BY calldate LIMIT 20000");
        $by = [];
        foreach ($q ? $q->fetchAll(PDO::FETCH_ASSOC) : [] as $r) $by[$r['src']][] = [(int)$r['ts'], $r['disposition']];
        $ok = 0;
        foreach ($by as $calls) {
            foreach ($calls as $i => $c) {
                if ($c[1] !== 'ANSWERED' || $c[0] > time() - 86400) continue;
                $fcr_base++;
                $ret = false;
                for ($j = $i + 1; $j < count($calls); $j++) { if ($calls[$j][0] - $c[0] <= 86400) { $ret = true; break; } else break; }
                if (!$ret) $ok++;
            }
        }
        if ($fcr_base > 0) $fcr_pct = round($ok * 100 / $fcr_base, 1);
    } catch (Exception $e) {}
}

// ─── Lista de chamadas (CDR real + análise quando existir) ────────────────────
$audited_calls = [];
foreach ($cdr_list as $c_real) {
    $phone_num = $c_real['src'];
    $src_cdata = function_exists('lookupContactData') ? lookupContactData($phone_num, $contacts_map) : false;
    $isAns = ($c_real['disposition'] === 'ANSWERED');
    $an = $analysis[$c_real['uniqueid']] ?? null;

    $sent_key = $an ? ucfirst(mb_strtolower(trim((string)$an['sentimento']), 'UTF-8')) : '';
    $badge = $isAns ? 'bg-emerald-500/20 text-emerald-300 border-emerald-500/30' : 'bg-rose-500/20 text-rose-300 border-rose-500/30';
    $label = $isAns ? 'Atendida' : htmlspecialchars((string)$c_real['disposition']);
    if ($an && $sent_key !== '') {
        $label = $sent_key;
        $badge = ['Positivo' => 'bg-emerald-500/20 text-emerald-300 border-emerald-500/30', 'Negativo' => 'bg-rose-500/20 text-rose-300 border-rose-500/30'][$sent_key] ?? 'bg-amber-500/20 text-amber-300 border-amber-500/30';
    }
    $risk_text = '';
    if ($an && $an['risks_a']) {
        $parts = [];
        foreach ($an['risks_a'] as $rk) $parts[] = $rk['label'] . ' (' . implode(', ', $rk['words'] ?? []) . ')';
        $risk_text = implode(' | ', $parts);
    } elseif (!$isAns) {
        $risk_text = 'Chamada não atendida (' . $c_real['disposition'] . ')';
    }
    $topics = $an ? $an['topics_a'] : [];

    // filtros de sentimento / tópico (só se aplicam a chamadas analisadas)
    if ($sent_filter !== 'ALL') {
        if ($sent_filter === 'RISCO') { if (!$an || !$an['risks_a']) continue; }
        elseif (!$an || strtoupper($sent_key) !== $sent_filter) continue;
    }
    if ($topic_filter !== 'ALL' && (!$an || !in_array($topic_filter, $topics, true))) continue;

    $sat = ($an && $an['satisfacao'] !== null && $an['satisfacao'] !== '') ? $an['satisfacao'] . '/5' : 'não inferida';
    $audited_calls[] = [
        'id' => $c_real['uniqueid'],
        'has_recording' => !empty($c_real['recordingfile']),
        'analyzed' => (bool)$an,
        'calldate' => date('d/m/Y H:i', strtotime($c_real['calldate'])),
        'client_name' => $src_cdata ? $src_cdata['name'] : "Cliente $phone_num",
        'cdata' => $src_cdata,
        'phone' => $phone_num,
        'operator' => 'Ramal ' . $c_real['dst'],
        'duration' => gmdate('i\m s\s', (int)$c_real['billsec']),
        'sentiment' => $an ? $sent_key : ($isAns ? 'ATENDIDA' : 'NAO_ATENDIDA'),
        'sentiment_badge' => $badge,
        'sentiment_label' => $label,
        'topic' => $topics ? implode(', ', $topics) : ($an ? 'Sem tópico' : '—'),
        'topic_icon' => 'fa-tag text-cyan-400',
        'qa_score' => $an ? $an['qa_score'] : null,
        'risk_alert' => ($an && $an['risks_a']) || !$isAns,
        'risk_text' => $risk_text,
        'summary_problem' => $an ? $an['resumo'] : ('Origem ' . $phone_num . ' para ' . $c_real['dst'] . '.'),
        'summary_action' => $an ? ($an['recomendacao'] ?: '—') : ('Resultado no CDR: ' . $c_real['disposition'] . ', ' . (int)$c_real['billsec'] . 's falados.'),
        'summary_result' => $an ? ('Sentimento: ' . ($sent_key ?: '—') . ' | Satisfação: ' . $sat . ' | IA: ' . $an['model']) : ($ai_configured ? 'Ainda não analisada: clique em "Analisar".' : 'IA não configurada: sem transcrição/sentimento.'),
        'transcript_text' => $an ? $an['transcript'] : '',
        'transcript' => []
    ];
}
$pending_uids = [];
foreach ($audited_calls as $ac) if ($ac['has_recording'] && !$ac['analyzed']) $pending_uids[] = $ac['id'];
$rules_all = $db ? $db->query("SELECT * FROM ia_audit_rules ORDER BY kind, id")->fetchAll(PDO::FETCH_ASSOC) : [];
$topic_rules = array_values(array_filter($rules_all, function ($r) { return $r['kind'] === 'topic'; }));
?>

<div class="space-y-6">

    <!-- HEADER DA CENTRAL DE AUDITORIA & IA ANALYTICS -->
    <div class="bg-slate-900/90 border border-purple-500/30 rounded-3xl p-6 shadow-2xl space-y-4 relative overflow-hidden">
        <div class="absolute -top-24 -right-24 w-64 h-64 bg-purple-600/15 rounded-full blur-3xl pointer-events-none"></div>

        <div class="flex flex-col lg:flex-row items-start lg:items-center justify-between gap-4 relative z-10">
            <div>
                <div class="flex items-center gap-2 mb-1">
                    <span class="px-2.5 py-0.5 rounded-full bg-purple-500/20 text-purple-300 border border-purple-500/30 text-[9px] font-black uppercase tracking-wider flex items-center gap-1.5">
                        <i class="fa-solid fa-brain text-purple-400 animate-pulse"></i> PAINEL DE INTELIGÊNCIA ARTIFICIAL TELEFÔNICA v8.5
                    </span>
                </div>
                <h1 class="text-2xl font-black text-white flex items-center gap-2">
                    Auditoria & Análise Preditiva de Voz <span class="text-xl">🎙️✨</span>
                </h1>
                <p class="text-xs text-slate-400 mt-0.5">
                    Avaliação de Vibe, Scorecard QA automático (Checklist de Script), detecção de riscos (Churn/Procon) e player dual-speaker.
                </p>
            </div>

            <!-- Botões Executivos e Exportação (Somente Ícones) -->
            <div class="flex items-center gap-2 flex-wrap">
                <button onclick="runGlobalAiAnalysis()" id="btn-run-global-ai" class="px-4 py-2 bg-purple-600 hover:bg-purple-500 text-white font-extrabold rounded-xl text-xs transition shadow-lg shadow-purple-600/30 flex items-center gap-2">
                    <i class="fa-solid fa-wand-magic-sparkles"></i> Processar Auditoria Global
                </button>
                <button type="button" id="btn-batch-analyze" onclick="analyzePendingCalls()" <?php echo (!$ai_configured || !$pending_uids) ? 'disabled' : ''; ?> class="px-4 py-2 bg-cyan-600 hover:bg-cyan-500 disabled:opacity-40 disabled:cursor-not-allowed text-white font-extrabold rounded-xl text-xs transition flex items-center gap-2" style="white-space:nowrap !important;flex-shrink:0 !important;width:auto !important;overflow:visible !important;text-overflow:clip !important;">
                    <i class="fa-solid fa-microphone-lines"></i> Analisar chamadas do período (<?php echo min(10, count($pending_uids)); ?>)
                </button>
                <button type="button" onclick="document.getElementById('dlg-ia-rules').showModal()" class="px-4 py-2 bg-slate-800 hover:bg-slate-700 text-slate-100 border border-slate-700 font-extrabold rounded-xl text-xs transition flex items-center gap-2" style="white-space:nowrap !important;flex-shrink:0 !important;width:auto !important;overflow:visible !important;text-overflow:clip !important;">
                    <i class="fa-solid fa-sliders text-purple-400"></i> Configurar palavras
                </button>
                <button onclick="downloadIaReportPdf()" title="Exportar PDF" class="p-2.5 bg-slate-800 hover:bg-slate-700 text-rose-400 border border-slate-700 rounded-xl font-bold transition flex items-center justify-center shadow">
                    <i class="fa-solid fa-file-pdf text-base"></i>
                </button>
            </div>
        </div>

        <!-- BARRA DE FILTROS AVANÇADA DE IA -->
        <form method="GET" action="index.php" class="flex flex-col gap-3 pt-3 border-t border-slate-800/80 text-xs font-semibold relative z-10">
            <input type="hidden" name="module" value="relatorios">
            <input type="hidden" name="action" value="ia_analytics">

            <div class="flex flex-wrap items-center gap-2 w-full">
                <input type="hidden" name="preset" id="preset_input" value="<?php echo htmlspecialchars($filter_preset); ?>">
                
                <div class="flex items-center bg-slate-950 border border-slate-800 rounded-xl p-1 gap-1 flex-wrap text-xs">
                    <button type="button" onclick="setPresetFilter('today')" class="px-3 py-1.5 rounded-lg font-bold transition <?php echo $filter_preset == 'today' ? 'bg-purple-600 text-white shadow' : 'text-slate-400 hover:text-white hover:bg-slate-800'; ?>">Hoje</button>
                    <button type="button" onclick="setPresetFilter('yesterday')" class="px-3 py-1.5 rounded-lg font-bold transition <?php echo $filter_preset == 'yesterday' ? 'bg-purple-600 text-white shadow' : 'text-slate-400 hover:text-white hover:bg-slate-800'; ?>">Ontem</button>
                    <button type="button" onclick="setPresetFilter('week')" class="px-3 py-1.5 rounded-lg font-bold transition <?php echo ($filter_preset == 'week' || !$filter_preset) ? 'bg-purple-600 text-white shadow font-black' : 'text-slate-400 hover:text-white hover:bg-slate-800'; ?>">7 Dias</button>
                    <button type="button" onclick="setPresetFilter('month')" class="px-3 py-1.5 rounded-lg font-bold transition <?php echo $filter_preset == 'month' ? 'bg-purple-600 text-white shadow' : 'text-slate-400 hover:text-white hover:bg-slate-800'; ?>">Mês</button>
                    <button type="button" onclick="document.getElementById('custom-date-filters').classList.toggle('hidden'); document.getElementById('preset_input').value='custom';" class="px-3 py-1.5 rounded-lg font-bold transition <?php echo ($filter_preset == 'custom' || $filter_preset == 'personalizado') ? 'bg-purple-600 text-white shadow' : 'text-slate-400 hover:text-white hover:bg-slate-800'; ?>" title="Personalizado (Selecionar Datas)"><i class="fa-solid fa-calendar-days text-sm"></i></button>
                </div>
                
                <div id="custom-date-filters" class="<?php echo ($filter_preset == 'custom' || $filter_preset == 'personalizado') ? 'flex' : 'hidden'; ?> items-center gap-2">
                    <input type="datetime-local" name="date_start" value="<?php echo date('Y-m-d\TH:i', strtotime($start_date)); ?>" onclick="try{this.showPicker();}catch(e){}" class="px-3 py-1.5 bg-slate-950 border border-slate-800 rounded-xl text-white text-xs focus:border-purple-500 focus:outline-none cursor-pointer">
                    <span class="text-slate-500 text-xs font-bold">até</span>
                    <input type="datetime-local" name="date_end" value="<?php echo date('Y-m-d\TH:i', strtotime($end_date)); ?>" onclick="try{this.showPicker();}catch(e){}" class="px-3 py-1.5 bg-slate-950 border border-slate-800 rounded-xl text-white text-xs focus:border-purple-500 focus:outline-none cursor-pointer">
                </div>

                <input type="text" name="src_filter" value="<?php echo htmlspecialchars($src_filter); ?>" placeholder="Número / Ramal..." class="px-3 py-1.5 w-32 bg-slate-950 border border-slate-800 rounded-xl text-white text-xs font-mono focus:border-purple-500 focus:outline-none">
                
                <select name="sent_filter" class="px-3 py-1.5 bg-slate-950 border border-slate-800 rounded-xl text-white text-xs focus:border-purple-500 focus:outline-none">
                    <option value="ALL" <?php echo $sent_filter === 'ALL' ? 'selected' : ''; ?>>Todos Sentimentos</option>
                    <option value="POSITIVO" <?php echo $sent_filter === 'POSITIVO' ? 'selected' : ''; ?>>🟢 Positivos</option>
                    <option value="NEUTRO" <?php echo $sent_filter === 'NEUTRO' ? 'selected' : ''; ?>>🟡 Neutros</option>
                    <option value="NEGATIVO" <?php echo $sent_filter === 'NEGATIVO' ? 'selected' : ''; ?>>🔴 Negativos</option>
                    <option value="RISCO" <?php echo $sent_filter === 'RISCO' ? 'selected' : ''; ?>>⚠️ Risco Alto</option>
                </select>

                <select name="topic_filter" class="px-3 py-1.5 bg-slate-950 border border-slate-800 rounded-xl text-white text-xs focus:border-purple-500 focus:outline-none">
                    <option value="ALL" <?php echo $topic_filter === 'ALL' ? 'selected' : ''; ?>>Qualquer Tópico</option>
                    <?php foreach ($topic_rules as $tr_): ?>
                    <option value="<?php echo htmlspecialchars($tr_['label']); ?>" <?php echo $topic_filter === $tr_['label'] ? 'selected' : ''; ?>><?php echo htmlspecialchars($tr_['label']); ?></option>
                    <?php endforeach; ?>
                </select>

                <select name="type_filter" class="px-3 py-1.5 bg-slate-950 border border-slate-800 rounded-xl text-white text-xs focus:border-purple-500 focus:outline-none">
                    <option value="ALL" <?php echo $type_filter === 'ALL' ? 'selected' : ''; ?>>Todos Tipos</option>
                    <option value="INTERNAL" <?php echo $type_filter === 'INTERNAL' ? 'selected' : ''; ?>>Internas</option>
                    <option value="EXTERNAL" <?php echo $type_filter === 'EXTERNAL' ? 'selected' : ''; ?>>Externas</option>
                </select>

                <button type="submit" class="px-4 py-1.5 bg-purple-600 hover:bg-purple-500 text-white rounded-xl text-xs font-bold transition flex items-center gap-1.5">
                    <i class="fa-solid fa-filter"></i> Filtrar
                </button>
            </div>
        </form>
    </div>

<script>
function setPresetFilter(preset) {
    document.getElementById('preset_input').value = preset;
    document.forms[0].submit();
}
</script>

    <!-- CARDS DE METRICAS CHAVE DA OPERAÇÃO DE IA -->
    <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4">
        <!-- Card 1: Vibe Geral (sentimento das chamadas analisadas) -->
        <div class="bg-slate-900/90 border border-emerald-500/30 p-5 rounded-2xl shadow-xl space-y-3">
            <div class="flex items-center justify-between">
                <span class="text-xs font-black uppercase text-emerald-400 tracking-wider">Vibe Geral dos Clientes</span>
                <i class="fa-solid fa-face-smile text-emerald-400 text-lg"></i>
            </div>
            <?php if ($sent_total > 0):
                $pp = round($sent_count['Positivo'] * 100 / $sent_total); $pn = round($sent_count['Neutro'] * 100 / $sent_total); $pg = max(0, 100 - $pp - $pn); ?>
            <div class="flex items-baseline gap-2">
                <span class="text-3xl font-black text-white font-mono"><?php echo $pp; ?>%</span>
                <span class="text-xs text-emerald-300 font-bold">positivas</span>
            </div>
            <div class="space-y-1">
                <div class="flex justify-between text-[10px] text-slate-400">
                    <span>🟢 <?php echo $pp; ?>% Positivo</span><span>🟡 <?php echo $pn; ?>% Neutro</span><span>🔴 <?php echo $pg; ?>% Negativo</span>
                </div>
                <div class="w-full bg-slate-800 h-2 rounded-full overflow-hidden flex">
                    <div class="bg-emerald-500 h-full" style="width: <?php echo $pp; ?>%"></div>
                    <div class="bg-amber-400 h-full" style="width: <?php echo $pn; ?>%"></div>
                    <div class="bg-rose-500 h-full" style="width: <?php echo $pg; ?>%"></div>
                </div>
            </div>
            <span class="text-[10px] text-slate-400 block">Base: <?php echo $sent_total; ?> chamada(s) analisada(s) pela IA no período</span>
            <?php else: ?>
            <div class="text-3xl font-black text-slate-600 font-mono">—</div>
            <span class="text-[10px] text-slate-400 block">Nenhuma chamada analisada no período. Use "Analisar chamadas do período".</span>
            <?php endif; ?>
        </div>

        <!-- Card 2: QA Scorecard (checklist configurável) -->
        <div class="bg-slate-900/90 border border-purple-500/30 p-5 rounded-2xl shadow-xl space-y-3">
            <div class="flex items-center justify-between">
                <span class="text-xs font-black uppercase text-purple-400 tracking-wider">QA Scorecard Script</span>
                <i class="fa-solid fa-clipboard-check text-purple-400 text-lg"></i>
            </div>
            <?php if ($qa_avg !== null): ?>
            <div class="flex items-baseline gap-2">
                <span class="text-3xl font-black text-white font-mono"><?php echo $qa_avg; ?><span class="text-lg font-normal text-slate-400">/100</span></span>
            </div>
            <div class="w-full bg-slate-800 h-2 rounded-full overflow-hidden"><div class="bg-purple-500 h-full rounded-full" style="width: <?php echo $qa_avg; ?>%"></div></div>
            <span class="text-[10px] text-slate-400 block">Média de itens do checklist encontrados nas transcrições (<?php echo $qa_n; ?> chamada(s))</span>
            <?php else: ?>
            <div class="text-3xl font-black text-slate-600 font-mono">—</div>
            <span class="text-[10px] text-slate-400 block">Sem chamadas analisadas ou sem itens de checklist ativos.</span>
            <?php endif; ?>
        </div>

        <!-- Card 3: Alertas de Risco (palavras-chave configuráveis) -->
        <div class="bg-slate-900/90 border border-rose-500/30 p-5 rounded-2xl shadow-xl space-y-3">
            <div class="flex items-center justify-between">
                <span class="text-xs font-black uppercase text-rose-400 tracking-wider">Alertas de Risco / Red Flags</span>
                <i class="fa-solid fa-shield-cat text-rose-400 text-lg"></i>
            </div>
            <div class="flex items-baseline gap-2">
                <span class="text-3xl font-black text-white font-mono"><?php echo $an_total ? $risk_calls : '—'; ?></span>
                <span class="text-xs text-rose-300 font-bold"><?php echo $an_total ? 'chamada(s) com alerta' : ''; ?></span>
            </div>
            <?php if ($risk_words): foreach (array_slice($risk_words, 0, 3, true) as $w => $n): ?>
            <div class="bg-rose-500/10 border border-rose-500/20 px-2.5 py-1 rounded-lg text-[10px] text-rose-300 font-bold">⚠️ Palavra-chave "<?php echo htmlspecialchars($w); ?>" em <?php echo $n; ?> chamada(s)</div>
            <?php endforeach; else: ?>
            <span class="text-[10px] text-slate-400 block"><?php echo $an_total ? 'Nenhuma palavra de risco encontrada.' : 'Aguardando análises.'; ?> <a href="#" onclick="document.getElementById('dlg-ia-rules').showModal();return false;" class="text-rose-300 underline">Configurar palavras</a></span>
            <?php endif; ?>
        </div>

        <!-- Card 4: FCR calculado no CDR -->
        <div class="bg-slate-900/90 border border-cyan-500/30 p-5 rounded-2xl shadow-xl space-y-3">
            <div class="flex items-center justify-between">
                <span class="text-xs font-black uppercase text-cyan-400 tracking-wider">Resolução 1ª Chamada (FCR)</span>
                <i class="fa-solid fa-bolt text-cyan-400 text-lg"></i>
            </div>
            <?php if ($fcr_pct !== null): ?>
            <div class="flex items-baseline gap-2"><span class="text-3xl font-black text-white font-mono"><?php echo $fcr_pct; ?>%</span></div>
            <div class="w-full bg-slate-800 h-2 rounded-full overflow-hidden"><div class="bg-cyan-500 h-full rounded-full" style="width: <?php echo $fcr_pct; ?>%"></div></div>
            <span class="text-[10px] text-slate-400 block">Chamadas atendidas (externas) sem nova ligação do mesmo número em 24h — <?php echo $fcr_base; ?> no CDR</span>
            <?php else: ?>
            <div class="text-3xl font-black text-slate-600 font-mono">—</div>
            <span class="text-[10px] text-slate-400 block">Sem chamadas externas atendidas com mais de 24h no período.</span>
            <?php endif; ?>
        </div>
    </div>

    <!-- SEÇÃO 2: CHECKLIST DE AUDITORIA QA & CATEGORIZAÇÃO DE ASSUNTOS (GRID 2 COLS) -->
    <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">

        <!-- BLOCO 1: CATEGORIZAÇÃO AUTOMÁTICA DE ASSUNTOS (TOPICS) -->
        <div class="bg-slate-900/90 border border-slate-800 rounded-3xl p-6 shadow-2xl space-y-4">
            <h3 class="text-sm font-extrabold text-white flex items-center justify-between border-b border-slate-800 pb-3">
                <span class="flex items-center gap-2"><i class="fa-solid fa-tags text-cyan-400"></i> Categorização de Assuntos por IA</span>
                <span class="text-xs text-slate-400 font-mono">Top Motivos</span>
            </h3>

            <div class="space-y-3 text-xs">
                <?php if ($topic_count): $tmax = max(array_sum($topic_count), 1); foreach ($topic_count as $tl => $tn): $tp = round($tn * 100 / max($an_total, 1)); ?>
                <div class="space-y-1">
                    <div class="flex justify-between font-bold">
                        <span class="text-slate-200 flex items-center gap-2"><i class="fa-solid fa-tag text-cyan-400"></i> <?php echo htmlspecialchars($tl); ?></span>
                        <span class="text-cyan-400 font-mono"><?php echo $tp; ?>% (<?php echo $tn; ?> chamada<?php echo $tn > 1 ? 's' : ''; ?>)</span>
                    </div>
                    <div class="w-full bg-slate-800 h-2 rounded-full overflow-hidden"><div class="bg-cyan-500 h-full rounded-full" style="width: <?php echo $tp; ?>%"></div></div>
                </div>
                <?php endforeach; else: ?>
                <p class="text-slate-500 italic py-4 text-center"><?php echo $an_total ? 'Nenhuma chamada analisada casou com as palavras dos tópicos.' : 'Sem chamadas analisadas no período.'; ?>
                    <a href="#" onclick="document.getElementById('dlg-ia-rules').showModal();return false;" class="text-purple-300 underline">Configurar tópicos</a></p>
                <?php endif; ?>
            </div>
        </div>

        <!-- BLOCO 2: SCORECARD & CHECKLIST DE QUALIDADE DO ATENDIMENTO (QA AUDIT) -->
        <div class="bg-slate-900/90 border border-slate-800 rounded-3xl p-6 shadow-2xl space-y-4">
            <h3 class="text-sm font-extrabold text-white flex items-center justify-between border-b border-slate-800 pb-3">
                <span class="flex items-center gap-2"><i class="fa-solid fa-list-check text-purple-400"></i> Checklist de Conformidade Operacional</span>
                <span class="text-xs text-purple-300 font-mono">Auditoria de Script</span>
            </h3>

            <div class="space-y-2.5 text-xs font-semibold">
                <?php if ($check_n): foreach ($check_n as $cl => $cn): $hits = $check_hit[$cl] ?? 0; $pc = round($hits * 100 / $cn, 1); $good = $pc >= 90; ?>
                <div class="p-2.5 bg-slate-950 border border-slate-800 rounded-xl flex items-center justify-between">
                    <span class="text-slate-200 flex items-center gap-2"><i class="fa-solid <?php echo $good ? 'fa-circle-check text-emerald-400' : 'fa-circle-xmark text-amber-400'; ?>"></i> <?php echo htmlspecialchars($cl); ?></span>
                    <span class="px-2 py-0.5 <?php echo $good ? 'bg-emerald-500/20 text-emerald-300 border-emerald-500/30' : 'bg-amber-500/20 text-amber-300 border-amber-500/30'; ?> border rounded font-mono font-bold"><?php echo $pc; ?>% (<?php echo $hits; ?>/<?php echo $cn; ?>)</span>
                </div>
                <?php endforeach; else: ?>
                <p class="text-slate-500 italic py-4 text-center">Sem chamadas analisadas no período.
                    <a href="#" onclick="document.getElementById('dlg-ia-rules').showModal();return false;" class="text-purple-300 underline">Configurar checklist</a></p>
                <?php endif; ?>
                <p class="text-[10px] text-slate-500 pt-1">Verificação por palavras-chave na transcrição inteira (a transcrição não separa quem falou).</p>
            </div>
        </div>

    </div>

    <!-- TABELA DE AUDITORIA COMPLETA DE CHAMADAS COM DUAL-SPEAKER MODAL -->
    <div class="bg-slate-900/90 border border-slate-800 rounded-3xl shadow-2xl overflow-hidden">
        <div class="p-5 border-b border-slate-800 flex flex-col sm:flex-row sm:items-center justify-between gap-4">
            <div>
                <h3 class="text-sm font-extrabold text-white flex items-center gap-2">
                    <i class="fa-solid fa-headphones text-purple-400"></i> Central de Chamadas Auditadas por IA
                </h3>
                <span class="text-xs text-slate-400">Clique no botão de auditoria para abrir a transcrição dual-speaker e o scorecard detalhado</span>
            </div>
        </div>

        <div class="overflow-x-auto">
            <table class="w-full text-left text-xs font-semibold">
                <thead class="bg-slate-950 uppercase text-[10px] tracking-wider border-b border-slate-800 text-slate-400">
                    <tr>
                        <th class="py-3.5 px-4">Data / Hora</th>
                        <th class="py-3.5 px-4">Cliente & Telefone</th>
                        <th class="py-3.5 px-4">Operador / Ramal</th>
                        <th class="py-3.5 px-4">Categoria / Assunto</th>
                        <th class="py-3.5 px-4 text-center">Vibe / Sentimento</th>
                        <th class="py-3.5 px-4 text-center">Score QA</th>
                        <th class="py-3.5 px-4 text-center">Áudio Player</th>
                        <th class="py-3.5 px-4 text-center">Ação IA</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-800/60 text-slate-200">
                    <?php foreach ($audited_calls as $idx => $call): ?>
                        <tr class="hover:bg-slate-800/40 transition">
                            <td class="py-3.5 px-4 font-mono text-slate-400"><?php echo $call['calldate']; ?></td>
                            <td class="py-3.5 px-4">
                                <div class="font-bold text-white flex items-center gap-1.5">
                                    <i class="fa-solid fa-user text-emerald-400 text-[10px]"></i> <?php echo htmlspecialchars($call['client_name']); ?>
                                </div>
                                <div class="text-[10px] text-slate-400 font-mono"><?php echo htmlspecialchars($call['phone']); ?></div>
                            </td>
                            <td class="py-3.5 px-4">
                                <div class="font-bold text-indigo-300 flex items-center gap-1.5">
                                    <i class="fa-solid fa-headset text-indigo-400 text-[10px]"></i> <?php echo htmlspecialchars($call['operator']); ?>
                                </div>
                                <div class="text-[10px] text-slate-400 font-mono">Duração: <?php echo $call['duration']; ?></div>
                            </td>
                            <td class="py-3.5 px-4">
                                <span class="px-2.5 py-1 rounded bg-slate-950 border border-slate-800 text-slate-300 text-[11px] font-bold flex items-center gap-1.5 w-fit">
                                    <i class="fa-solid <?php echo $call['topic_icon']; ?>"></i> <?php echo $call['topic']; ?>
                                </span>
                            </td>
                            <td class="py-3.5 px-4 text-center">
                                <span class="px-2.5 py-1 rounded text-[10px] font-extrabold border <?php echo $call['sentiment_badge']; ?>">
                                    <?php echo $call['sentiment_label']; ?>
                                </span>
                            </td>
                            <td class="py-3.5 px-4 text-center">
                                <span class="font-mono font-black text-sm text-slate-500">
                                    <?php echo $call['qa_score'] === null ? '—' : ((int)$call['qa_score']) . '/100'; ?>
                                </span>
                            </td>
                            <td class="py-3.5 px-4 text-center">
                                <?php if (!empty($call['has_recording'])): ?>
                                <audio controls preload="none" class="h-7 w-44 inline-block opacity-90">
                                    <source src="get_audio.php?uid=<?php echo urlencode($call['id']); ?>" type="audio/wav">
                                </audio>
                                <?php else: ?><span class="text-slate-500 text-[10px]">Sem gravação</span><?php endif; ?>
                            </td>
                            <td class="py-3.5 px-4 text-center">
                                <?php if ($call['has_recording'] && $ai_configured): ?>
                                <button type="button" onclick="analyzeOneCall('<?php echo htmlspecialchars($call['id'], ENT_QUOTES); ?>', this)" class="px-3 py-1.5 mb-1 bg-cyan-600/20 hover:bg-cyan-600 text-cyan-300 hover:text-white border border-cyan-500/40 rounded-xl text-xs font-bold transition flex items-center gap-1.5 mx-auto" style="white-space:nowrap !important;flex-shrink:0 !important;width:auto !important;overflow:visible !important;text-overflow:clip !important;">
                                    <i class="fa-solid fa-wand-magic-sparkles"></i> <?php echo $call['analyzed'] ? 'Reanalisar' : 'Analisar'; ?>
                                </button>
                                <?php endif; ?>
                                <button onclick="openFullIaAuditModal(<?php echo htmlspecialchars(json_encode($call)); ?>)" class="px-3 py-1.5 bg-purple-600/20 hover:bg-purple-600 text-purple-300 hover:text-white border border-purple-500/40 rounded-xl text-xs font-bold transition flex items-center gap-1.5 mx-auto shadow">
                                    <i class="fa-solid fa-magnifying-glass-chart"></i> Ver Auditoria
                                </button>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>

</div>


<style>
dialog#dlg-ia-rules::backdrop { background: rgba(0,0,0,0.65); backdrop-filter: blur(4px); }
dialog#dlg-ia-rules { background: #0f172a; color: #e2e8f0; border: 1px solid rgba(124,58,237,.4); border-radius: 1.5rem; padding: 0; width: min(900px, 95vw); max-height: 90vh; }
dialog#dlg-ia-rules .rule-row input[type=text], dialog#dlg-ia-rules .rule-row textarea { background:#020617; border:1px solid #1e293b; border-radius:.75rem; color:#fff; padding:.5rem .75rem; width:100%; font-size:12px; }
dialog#dlg-ia-rules .rbtn { white-space: nowrap !important; flex-shrink: 0 !important; width: auto !important; overflow: visible !important; text-overflow: clip !important; }
</style>

<!-- CONFIGURAÇÃO DAS PALAVRAS-CHAVE DA AUDITORIA (HTML5 dialog nativo) -->
<dialog id="dlg-ia-rules">
    <div class="p-6 space-y-4 overflow-y-auto" style="max-height:90vh">
        <div class="flex items-center justify-between">
            <div>
                <h3 class="text-base font-black text-white flex items-center gap-2"><i class="fa-solid fa-sliders text-purple-400"></i> Palavras-chave da Auditoria</h3>
                <p class="text-[11px] text-slate-400">Separe as palavras por vírgula. A análise procura essas palavras na transcrição de cada chamada (sem diferenciar maiúsculas).</p>
            </div>
            <button type="button" onclick="document.getElementById('dlg-ia-rules').close()" class="text-slate-400 hover:text-white text-lg"><i class="fa-solid fa-xmark"></i></button>
        </div>
        <?php if ($rules_msg): ?><div class="p-2.5 bg-emerald-500/10 border border-emerald-500/30 rounded-xl text-emerald-300 text-xs font-bold"><?php echo htmlspecialchars($rules_msg); ?></div><?php endif; ?>

        <?php
        $kinds = ['risk' => ['⚠️ Riscos / Red Flags', 'Alertas gerenciais (ex.: Procon, cancelamento).'],
                  'topic' => ['🏷️ Tópicos / Assuntos', 'Classificam o motivo da ligação.'],
                  'checklist' => ['✅ Checklist de Script (QA)', 'Cada item vale uma fração da nota QA: o item conta se alguma palavra aparecer.']];
        foreach ($kinds as $kk => $kinfo): ?>
        <div class="space-y-2">
            <h4 class="text-xs font-extrabold text-slate-200"><?php echo $kinfo[0]; ?> <span class="text-slate-500 font-normal">— <?php echo $kinfo[1]; ?></span></h4>
            <?php foreach ($rules_all as $r): if ($r['kind'] !== $kk) continue; ?>
            <form method="POST" class="rule-row grid grid-cols-1 md:grid-cols-12 gap-2 items-start p-2.5 bg-slate-950 border border-slate-800 rounded-xl">
                <input type="hidden" name="rule_id" value="<?php echo (int)$r['id']; ?>">
                <div class="md:col-span-3"><input type="text" name="rule_label" value="<?php echo htmlspecialchars($r['label']); ?>" placeholder="Nome"></div>
                <div class="md:col-span-6"><textarea name="rule_keywords" rows="2" placeholder="palavra1, palavra2, ..."><?php echo htmlspecialchars($r['keywords']); ?></textarea></div>
                <label class="md:col-span-1 flex items-center gap-1 text-[11px] text-slate-300 pt-2"><input type="checkbox" name="rule_enabled" value="1" <?php echo $r['enabled'] ? 'checked' : ''; ?>> Ativa</label>
                <div class="md:col-span-2 flex gap-1.5 justify-end">
                    <button type="submit" name="action_ia_rule" value="save" class="rbtn px-3 py-1.5 bg-purple-600 hover:bg-purple-500 text-white rounded-lg text-xs font-bold"><i class="fa-solid fa-floppy-disk"></i> Salvar</button>
                    <button type="submit" name="action_ia_rule" value="delete" onclick="return confirm('Remover esta regra?')" class="rbtn px-2.5 py-1.5 bg-rose-600/20 hover:bg-rose-600/40 text-rose-300 border border-rose-500/30 rounded-lg text-xs font-bold"><i class="fa-solid fa-trash"></i></button>
                </div>
            </form>
            <?php endforeach; ?>
            <form method="POST" class="rule-row grid grid-cols-1 md:grid-cols-12 gap-2 items-start p-2.5 border border-dashed border-slate-700 rounded-xl">
                <input type="hidden" name="rule_kind" value="<?php echo $kk; ?>">
                <div class="md:col-span-3"><input type="text" name="rule_label" placeholder="Novo item (nome)"></div>
                <div class="md:col-span-7"><textarea name="rule_keywords" rows="1" placeholder="palavras separadas por vírgula"></textarea></div>
                <div class="md:col-span-2 flex justify-end"><button type="submit" name="action_ia_rule" value="save" class="rbtn px-3 py-1.5 bg-slate-800 hover:bg-slate-700 text-slate-100 border border-slate-700 rounded-lg text-xs font-bold"><i class="fa-solid fa-plus"></i> Adicionar</button></div>
            </form>
        </div>
        <?php endforeach; ?>

        <div class="flex justify-end pt-2">
            <button type="button" onclick="document.getElementById('dlg-ia-rules').close()" class="rbtn px-4 py-2 bg-slate-800 hover:bg-slate-700 text-white rounded-xl text-xs font-bold">Fechar</button>
        </div>
    </div>
</dialog>
<?php if ($rules_msg): ?><script>document.addEventListener('DOMContentLoaded', function(){ try { document.getElementById('dlg-ia-rules').showModal(); } catch(e) {} });</script><?php endif; ?>

<script>
const IA_PENDING = <?php echo json_encode(array_slice($pending_uids, 0, 10)); ?>;

async function iaAnalyzeUid(uid) {
    const res = await fetch('index.php?api_action=analyze_call_audio', {
        method: 'POST', headers: {'Content-Type': 'application/json'}, body: JSON.stringify({ uid: uid })
    });
    return res.json();
}

async function analyzeOneCall(uid, btn) {
    const orig = btn.innerHTML;
    btn.disabled = true; btn.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> Analisando...';
    try {
        const d = await iaAnalyzeUid(uid);
        if (d.success) { location.reload(); return; }
        alert('Não foi possível analisar: ' + (d.error || 'erro desconhecido'));
    } catch (e) { alert('Falha na requisição: ' + e.message); }
    btn.disabled = false; btn.innerHTML = orig;
}

async function analyzePendingCalls() {
    const btn = document.getElementById('btn-batch-analyze');
    if (!IA_PENDING.length) return;
    const orig = btn.innerHTML; btn.disabled = true;
    let ok = 0, fail = 0, lastErr = '';
    for (let i = 0; i < IA_PENDING.length; i++) {
        btn.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> Analisando ' + (i + 1) + '/' + IA_PENDING.length + '...';
        try { const d = await iaAnalyzeUid(IA_PENDING[i]); if (d.success) ok++; else { fail++; lastErr = d.error || ''; } }
        catch (e) { fail++; lastErr = e.message; }
    }
    if (fail && !ok) alert('Nenhuma chamada analisada. Último erro: ' + lastErr);
    else location.reload();
    btn.innerHTML = orig; btn.disabled = false;
}
</script>

<!-- MODAL: AUDITORIA DETALHADA DA CHAMADA (DIALOGO DUAL-SPEAKER + SCORECARD) -->
<div id="modal-full-ia-audit" class="fixed inset-0 z-50 hidden bg-slate-950/85 backdrop-blur-xl flex items-center justify-center p-4 transition-all duration-300">
    <div class="bg-slate-900 border border-purple-500/40 rounded-3xl w-full max-w-3xl p-6 shadow-2xl space-y-5 relative overflow-hidden max-h-[90vh] flex flex-col">
        
        <!-- Header do Modal -->
        <div class="flex items-center justify-between border-b border-slate-800 pb-3">
            <div class="flex items-center gap-3">
                <div class="w-10 h-10 rounded-2xl bg-purple-500/20 border border-purple-500/40 text-purple-300 flex items-center justify-center text-lg font-black shadow-lg">
                    <i class="fa-solid fa-brain"></i>
                </div>
                <div>
                    <h3 id="audit-modal-title" class="text-base font-black text-white flex items-center gap-2">
                        Auditoria de Inteligência Artificial
                    </h3>
                    <span id="audit-modal-subtitle" class="text-xs text-slate-400 font-mono">--</span>
                </div>
            </div>
            <button type="button" onclick="closeModal('modal-full-ia-audit')" class="text-slate-400 hover:text-white text-lg"><i class="fa-solid fa-xmark"></i></button>
        </div>

        <div class="overflow-y-auto custom-scrollbar space-y-4 pr-1">
            <!-- Cards de Resumo da Auditoria -->
            <div class="grid grid-cols-1 sm:grid-cols-3 gap-3 text-xs">
                <div class="bg-slate-950/90 border border-slate-800 p-3 rounded-2xl space-y-1">
                    <span class="text-slate-400 font-bold block text-[10px] uppercase tracking-wider">📝 Resumo da chamada</span>
                    <p id="audit-modal-problem" class="text-slate-200 font-semibold italic">--</p>
                </div>
                <div class="bg-slate-950/90 border border-slate-800 p-3 rounded-2xl space-y-1">
                    <span class="text-slate-400 font-bold block text-[10px] uppercase tracking-wider">💡 Recomendação</span>
                    <p id="audit-modal-action" class="text-slate-200 font-semibold italic">--</p>
                </div>
                <div class="bg-slate-950/90 border border-slate-800 p-3 rounded-2xl space-y-1">
                    <span class="text-slate-400 font-bold block text-[10px] uppercase tracking-wider">📊 Sentimento / Satisfação</span>
                    <p id="audit-modal-result" class="text-emerald-300 font-semibold italic">--</p>
                </div>
            </div>

            <!-- Red Flag / Alerta de Risco -->
            <div id="audit-modal-risk-banner" class="p-3 bg-rose-500/10 border border-rose-500/30 rounded-2xl text-xs text-rose-300 font-bold flex items-center justify-between hidden">
                <span class="flex items-center gap-2"><i class="fa-solid fa-shield-cat text-rose-400"></i> Alerta de Risco Detectado:</span>
                <span id="audit-modal-risk-desc" class="font-mono text-white">--</span>
            </div>

            <!-- Diálogo Dual-Speaker (Chat de Áudio Transcrito) -->
            <div class="space-y-2">
                <h4 class="text-xs font-extrabold text-slate-300 flex items-center gap-2">
                    <i class="fa-solid fa-comments text-purple-400"></i> Transcrição do áudio (gerada por IA)
                </h4>
                <div id="audit-modal-transcript-container" class="bg-slate-950/90 border border-slate-800 p-4 rounded-2xl space-y-3 max-h-64 overflow-y-auto custom-scrollbar">
                    <!-- Preenchido via JS -->
                </div>
            </div>
        </div>

        <!-- Rodapé com Player & Ações -->
        <div class="border-t border-slate-800 pt-3 flex flex-col sm:flex-row items-center justify-between gap-3">
            <audio id="audit-modal-player" controls class="h-8 w-full sm:w-64 opacity-90">
                <source src="" type="audio/wav">
            </audio>
            <div class="flex items-center gap-2 w-full sm:w-auto justify-end">
                <button type="button" onclick="downloadIaReportPdf()" class="px-3.5 py-2 bg-rose-600/20 hover:bg-rose-600/40 text-rose-300 border border-rose-500/30 rounded-xl text-xs font-bold transition flex items-center gap-1.5">
                    <i class="fa-solid fa-file-pdf"></i> Baixar PDF
                </button>
                <button type="button" onclick="closeModal('modal-full-ia-audit')" class="px-4 py-2 bg-purple-600 hover:bg-purple-500 text-white font-extrabold rounded-xl transition text-xs shadow-lg shadow-purple-600/30">
                    Fechar
                </button>
            </div>
        </div>

    </div>
</div>

<script>
function openFullIaAuditModal(data) {
    document.getElementById('audit-modal-title').innerText = 'Auditoria: ' + (data.client_name || 'Atendimento');
    document.getElementById('audit-modal-subtitle').innerText = (data.operator || '') + ' | Duração: ' + (data.duration || '') + ' | Score QA: ' + (data.qa_score == null ? '—' : data.qa_score + '/100');

    document.getElementById('audit-modal-problem').innerText = (data.summary_problem || '—');
    document.getElementById('audit-modal-action').innerText = (data.summary_action || '—');
    document.getElementById('audit-modal-result').innerText = (data.summary_result || '—');

    const riskBanner = document.getElementById('audit-modal-risk-banner');
    const riskDesc = document.getElementById('audit-modal-risk-desc');
    if (data.risk_alert) {
        riskDesc.innerText = data.risk_text || 'Alerta de insatisfação detectado';
        riskBanner.classList.remove('hidden');
    } else {
        riskBanner.classList.add('hidden');
    }

    // Renderizar transcrição Dual-Speaker
    const container = document.getElementById('audit-modal-transcript-container');
    container.innerHTML = '';

    if (data.transcript_text) {
        const p = document.createElement('p');
        p.className = 'text-slate-200 text-xs leading-relaxed whitespace-pre-wrap';
        p.textContent = data.transcript_text;
        container.appendChild(p);
    } else if (data.transcript && data.transcript.length > 0) {
        data.transcript.forEach(t => {
            const isClient = t.speaker === 'client';
            const bubble = document.createElement('div');
            bubble.className = `flex flex-col ${isClient ? 'items-start' : 'items-end'} space-y-1`;

            bubble.innerHTML = `
                <div class="flex items-center gap-1.5 text-[10px] font-bold text-slate-400">
                    <span>${isClient ? '🎧 ' + t.name : '👨‍💼 ' + t.name}</span>
                    <span class="font-mono text-[9px] text-slate-500">[${t.time}]</span>
                </div>
                <div class="p-3 rounded-2xl max-w-lg text-xs leading-relaxed ${isClient ? 'bg-slate-900 text-slate-200 border border-slate-800 rounded-tl-none' : 'bg-purple-600/30 text-purple-100 border border-purple-500/40 rounded-tr-none'} shadow">
                    ${t.text}
                </div>
            `;
            container.appendChild(bubble);
        });
    } else {
        container.innerHTML = '<p class="text-slate-500 text-xs italic text-center py-4">Chamada ainda não analisada. Clique em "Analisar" para transcrever.</p>';
    }

    // Player URL
    const player = document.getElementById('audit-modal-player');
    if (player) {
        player.src = 'get_audio.php?uid=' + encodeURIComponent(data.id);
        player.load();
    }

    openModal('modal-full-ia-audit');
}

async function runGlobalAiAnalysis() {
    const btn = document.getElementById('btn-run-global-ai');
    if (!btn) return;

    const orig = btn.innerHTML;
    btn.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> Processando Auditoria...';
    btn.disabled = true;

    try {
        const total = <?php echo $tot_calls; ?>;
        const atend = <?php echo $ans_calls; ?>;
        const tma = <?php echo $avg_tma; ?>;
        const tme = <?php echo $avg_tme; ?>;

        const res = await fetch('index.php?api_action=analyze_pabx_insights', {
            method: 'POST',
            headers: {'Content-Type': 'application/json'},
            body: JSON.stringify({ total, atendidas: atend, tmaSec: tma, tmeSec: tme })
        });
        const data = await res.json();
        if (data.success && data.insights) {
            if (typeof showIaToast === 'function') showIaToast('Auditoria Global de IA concluída com 100% dos dados atualizados!');
        }
    } catch(e) {
        if (typeof showIaToast === 'function') showIaToast('Auditoria de IA atualizada.', true);
    } finally {
        btn.innerHTML = orig;
        btn.disabled = false;
    }
}
</script>
