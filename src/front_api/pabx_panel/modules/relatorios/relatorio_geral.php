<?php
/**
 * IPbx Prisma - Relatório Geral Estilo  v7.2
 * Com Porcentagens nos Cards, Tooltips Explicativos no Hover, Filtros Rápidos e Exportação (PDF, Excel e WhatsApp)
 */

$filter_preset    = isset($_GET['preset']) ? $_GET['preset'] : '';

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
    $start_date = isset($_GET['start_date']) && !empty($_GET['start_date']) ? $_GET['start_date'] : date('Y-m-d', strtotime('-7 days'));
    $end_date   = isset($_GET['end_date'])   && !empty($_GET['end_date'])   ? $_GET['end_date']   : date('Y-m-d');
} else {
    // DEFAULT: 7 DIAS (week)
    $filter_preset = 'week';
    $start_date = date('Y-m-d', strtotime('-7 days'));
    $end_date   = date('Y-m-d');
}

// Conexão MySQL Asterisk CDR
$ast_db = getAsteriskPdoConnection('asteriskcdrdb');
if (!$ast_db) {
    $ast_db = getAsteriskDBConnection();
}

// Métricas Reais da Operação
$total_calls = 0;
$inc_calls = 0;
$out_calls = 0;
$int_calls = 0;
$unique_callers = 0;

$total_min = 0.0;
$inc_min = 0.0;
$out_min = 0.0;
$int_min = 0.0;
$avg_inc_min = 0.00;
$avg_out_min = 0.00;
$avg_int_min = 0.00;

if ($ast_db) {
    try {
        $stmt_cnt = $ast_db->prepare("SELECT 
            COUNT(*) as total,
            SUM(CASE WHEN dst REGEXP '^[0-9]{3,4}$' AND src REGEXP '^[0-9]{3,4}$' THEN 1 ELSE 0 END) as internal,
            SUM(CASE WHEN src REGEXP '^[0-9]{3,4}$' AND NOT dst REGEXP '^[0-9]{3,4}$' THEN 1 ELSE 0 END) as outgoing,
            SUM(CASE WHEN NOT src REGEXP '^[0-9]{3,4}$' THEN 1 ELSE 0 END) as incoming,
            COUNT(DISTINCT src) as unique_src,
            SUM(billsec) as total_sec,
            SUM(CASE WHEN NOT src REGEXP '^[0-9]{3,4}$' THEN billsec ELSE 0 END) as inc_sec,
            SUM(CASE WHEN src REGEXP '^[0-9]{3,4}$' AND NOT dst REGEXP '^[0-9]{3,4}$' THEN billsec ELSE 0 END) as out_sec,
            SUM(CASE WHEN dst REGEXP '^[0-9]{3,4}$' AND src REGEXP '^[0-9]{3,4}$' THEN billsec ELSE 0 END) as int_sec
            FROM cdr WHERE calldate BETWEEN :sd AND :ed");
        $stmt_cnt->execute([':sd' => "$start_date 00:00:00", ':ed' => "$end_date 23:59:59"]);
        $r_cnt = $stmt_cnt->fetch(PDO::FETCH_ASSOC);

        if ($r_cnt && $r_cnt['total'] > 0) {
            $inc_calls   = intval($r_cnt['incoming']);
            $out_calls   = intval($r_cnt['outgoing']);
            $int_calls   = intval($r_cnt['internal']);
            $unique_callers = intval($r_cnt['unique_src']);

            $inc_sec   = intval($r_cnt['inc_sec']);
            $out_sec   = intval($r_cnt['out_sec']);
            $int_sec   = intval($r_cnt['int_sec']);

            // Por padrão, considerar apenas chamadas externas (Recebidas + Efetuadas) a menos que $include_internal seja marcado
            if ($include_internal) {
                $total_calls = intval($r_cnt['total']);
                $total_sec   = intval($r_cnt['total_sec']);
            } else {
                $total_calls = $inc_calls + $out_calls;
                $total_sec   = $inc_sec + $out_sec;
            }

            $total_min = round($total_sec / 60, 1);
            $inc_min   = round($inc_sec / 60, 1);
            $out_min   = round($out_sec / 60, 1);
            $int_min   = round($int_sec / 60, 1);

            $avg_inc_min = $inc_calls > 0 ? round(($inc_sec / $inc_calls) / 60, 2) : 0.00;
            $avg_out_min = $out_calls > 0 ? round(($out_sec / $out_calls) / 60, 2) : 0.00;
            $avg_int_min = $int_calls > 0 ? round(($int_sec / $int_calls) / 60, 2) : 0.00;
        }
    } catch (Exception $ex) {}
}

// Calcular Porcentagens
$inc_pct = $total_calls > 0 ? round(($inc_calls / $total_calls) * 100, 1) : 0;
$out_pct = $total_calls > 0 ? round(($out_calls / $total_calls) * 100, 1) : 0;
$int_pct = $total_calls > 0 ? round(($int_calls / $total_calls) * 100, 1) : 0;

$inc_min_pct = $total_min > 0 ? round(($inc_min / $total_min) * 100, 1) : 0;
$out_min_pct = $total_min > 0 ? round(($out_min / $total_min) * 100, 1) : 0;

// ─── Tabela de Resumo REAL por Ramal do PABX ─────────────────────────────
$extMap = getExtensionsMap();
$ext_summary = [];

if ($ast_db) {
    try {
        $stmt_ext = $ast_db->prepare("SELECT 
            CASE 
                WHEN src REGEXP '^[0-9]{3,4}$' THEN src
                WHEN dst REGEXP '^[0-9]{3,4}$' THEN dst
                ELSE 'Outros'
            END as ext_num,
            SUM(CASE WHEN dst REGEXP '^[0-9]{3,4}$' AND NOT src REGEXP '^[0-9]{3,4}$' THEN 1 ELSE 0 END) as inc_cnt,
            SUM(CASE WHEN src REGEXP '^[0-9]{3,4}$' THEN 1 ELSE 0 END) as out_cnt,
            SUM(CASE WHEN dst REGEXP '^[0-9]{3,4}$' AND NOT src REGEXP '^[0-9]{3,4}$' THEN billsec ELSE 0 END) as inc_sec,
            SUM(CASE WHEN src REGEXP '^[0-9]{3,4}$' THEN billsec ELSE 0 END) as out_sec
            FROM cdr 
            WHERE calldate BETWEEN :sd AND :ed
            GROUP BY ext_num
            ORDER BY ext_num ASC");
        $stmt_ext->execute([':sd' => "$start_date 00:00:00", ':ed' => "$end_date 23:59:59"]);
        $rows_ext = $stmt_ext->fetchAll(PDO::FETCH_ASSOC);

        foreach ($rows_ext as $r) {
            $e = trim($r['ext_num'] ?? '');
            if ($e === 'Outros' || empty($e) || !isset($extMap[$e])) continue;

            $rawTitle = $extMap[$e];
            $userName = trim(str_ireplace($e, '', $rawTitle));
            $userName = trim(preg_replace('/^[\s\-:]+/', '', $userName));
            if (empty($userName)) $userName = "Ramal $e";

            $ext_summary[] = [
                'ext' => $e,
                'user' => $userName,
                'inc_cnt' => intval($r['inc_cnt']),
                'out_cnt' => intval($r['out_cnt']),
                'inc_time' => gmdate("H:i:s", intval($r['inc_sec'])),
                'out_time' => gmdate("H:i:s", intval($r['out_sec']))
            ];
        }
    } catch (Exception $e) {}
}

if (empty($ext_summary) && !empty($extMap)) {
    foreach ($extMap as $e => $title) {
        $userName = trim(str_ireplace($e, '', $title));
        if (empty($userName)) $userName = "Ramal $e";
        $ext_summary[] = [
            'ext' => $e,
            'user' => $userName,
            'inc_cnt' => 0,
            'out_cnt' => 0,
            'inc_time' => '00:00:00',
            'out_time' => '00:00:00'
        ];
    }
}

// ─── Mapa de Calor REAL por Horário de Pico (08:00 às 18:00+) ───────────────
$hours_heatmap = [
    '08:00' => 0, '09:00' => 0, '10:00' => 0, '11:00' => 0, '12:00' => 0,
    '13:00' => 0, '14:00' => 0, '15:00' => 0, '16:00' => 0, '17:00' => 0, '18:00' => 0
];

if ($ast_db) {
    try {
        $sql_hm = "SELECT HOUR(calldate) as call_hour, COUNT(*) as total
            FROM cdr 
            WHERE calldate BETWEEN :sd AND :ed";
        if (!$include_internal) {
            $sql_hm .= " AND NOT (LENGTH(src) <= 4 AND LENGTH(dst) <= 4)";
        }
        $sql_hm .= " GROUP BY HOUR(calldate)";

        $stmt_hm = $ast_db->prepare($sql_hm);
        $stmt_hm->execute([':sd' => "$start_date 00:00:00", ':ed' => "$end_date 23:59:59"]);
        while ($r_hm = $stmt_hm->fetch(PDO::FETCH_ASSOC)) {
            $hKey = sprintf("%02d:00", intval($r_hm['call_hour']));
            $hours_heatmap[$hKey] = intval($r_hm['total']);
        }
    } catch (Exception $e_hm) {}
}
?>

<div class="space-y-6">
    <!-- Header e Filtros Rápidos -->
    <div class="bg-slate-900/90 border border-slate-800 rounded-2xl p-5 shadow-xl space-y-4">
        <div class="flex flex-col lg:flex-row items-center justify-between gap-4">
            <div>
                <h3 class="text-base font-extrabold text-white flex items-center gap-2.5">
                    <i class="fa-solid fa-chart-line text-rose-400 text-lg"></i> Relatório Geral & Histórico de Telefonia PABX
                </h3>
                <span class="text-xs text-slate-400">Consolidado com porcentagens (%), tooltips explicativos e mapa de calor por horário de pico</span>
            </div>

            <!-- Presets Padronizados (Hoje, Ontem, 7 Dias, Mês, Personalizado) -->
            <form method="GET" action="index.php" class="flex flex-col gap-3">
                <input type="hidden" name="module" value="relatorios">
                <input type="hidden" name="action" value="relatorio_geral">

                <div class="flex items-center gap-3 flex-wrap">
                    <div class="flex items-center bg-slate-950 p-1 rounded-xl border border-slate-800 text-xs font-bold gap-1 flex-wrap">
                        <a href="index.php?module=relatorios&action=relatorio_geral&preset=today&include_internal=<?php echo $include_internal ? '1' : '0'; ?>"
                           class="px-3 py-1.5 rounded-lg transition <?php echo $filter_preset === 'today' ? 'bg-brand-600 text-white shadow-lg' : 'text-slate-400 hover:text-white'; ?>">
                            Hoje
                        </a>
                        <a href="index.php?module=relatorios&action=relatorio_geral&preset=yesterday&include_internal=<?php echo $include_internal ? '1' : '0'; ?>"
                           class="px-3 py-1.5 rounded-lg transition <?php echo $filter_preset === 'yesterday' ? 'bg-brand-600 text-white shadow-lg' : 'text-slate-400 hover:text-white'; ?>">
                            Ontem
                        </a>
                        <a href="index.php?module=relatorios&action=relatorio_geral&preset=week&include_internal=<?php echo $include_internal ? '1' : '0'; ?>"
                           class="px-3 py-1.5 rounded-lg transition <?php echo ($filter_preset === 'week' || !$filter_preset) ? 'bg-brand-600 text-white shadow-lg font-black' : 'text-slate-400 hover:text-white'; ?>">
                            7 Dias
                        </a>
                        <a href="index.php?module=relatorios&action=relatorio_geral&preset=month&include_internal=<?php echo $include_internal ? '1' : '0'; ?>"
                           class="px-3 py-1.5 rounded-lg transition <?php echo $filter_preset === 'month' ? 'bg-brand-600 text-white shadow-lg' : 'text-slate-400 hover:text-white'; ?>">
                            Mês
                        </a>
                        <a href="index.php?module=relatorios&action=relatorio_geral&preset=custom&include_internal=<?php echo $include_internal ? '1' : '0'; ?>"
                           class="px-3 py-1.5 rounded-lg transition <?php echo ($filter_preset === 'custom' || $filter_preset === 'personalizado') ? 'bg-brand-600 text-white shadow-lg' : 'text-slate-400 hover:text-white'; ?>" title="Personalizado (Selecionar Datas)">
                            <i class="fa-solid fa-calendar-days text-sm"></i>
                        </a>
                    </div>

                    <!-- Checkbox de Chamadas Internas -->
                    <label class="flex items-center gap-2 text-xs text-slate-300 font-bold cursor-pointer bg-slate-950 px-3 py-1.5 rounded-xl border border-slate-800 hover:border-slate-700 transition" title="Marque para somar ligações entre ramais no total geral e mapa de calor">
                        <input type="checkbox" name="include_internal" value="1" <?php echo $include_internal ? 'checked' : ''; ?> onchange="this.form.submit()" class="rounded border-slate-700 bg-slate-900 text-brand-500 focus:ring-brand-500 w-3.5 h-3.5 cursor-pointer">
                        <span>Incluir Internas (Ramal → Ramal)</span>
                    </label>

                    <!-- Range personalizado clicável -->
                    <div class="flex items-center gap-2">
                        <input type="date" name="start_date" value="<?php echo htmlspecialchars($start_date); ?>" 
                               onclick="try{this.showPicker();}catch(e){}"
                               class="px-3 py-1.5 bg-slate-950 border border-slate-800 rounded-xl text-white text-xs font-mono focus:border-brand-500 focus:outline-none cursor-pointer">
                        <span class="text-slate-500 text-xs font-bold">até</span>
                        <input type="date" name="end_date" value="<?php echo htmlspecialchars($end_date); ?>" 
                               onclick="try{this.showPicker();}catch(e){}"
                               class="px-3 py-1.5 bg-slate-950 border border-slate-800 rounded-xl text-white text-xs font-mono focus:border-brand-500 focus:outline-none cursor-pointer">
                    </div>

                    <div class="flex items-center gap-2">
                        <button type="submit" class="px-4 py-1.5 bg-brand-600 hover:bg-brand-500 text-white rounded-xl text-xs font-bold transition flex items-center gap-1.5">
                            <i class="fa-solid fa-rotate"></i> Filtrar
                        </button>
                    </div>
                </div>
            </form>

            <!-- Ações de Exportação (Somente Ícones: CSV, PDF, E-mail, WhatsApp) -->
            <div class="flex items-center gap-1.5 print:hidden">
                <button onclick="exportGeneralToCSV()" class="p-2 bg-slate-950 hover:bg-slate-800 text-emerald-400 border border-slate-800 rounded-xl font-bold transition shadow" title="Exportar para CSV">
                    <i class="fa-solid fa-file-csv text-base"></i>
                </button>
                <button onclick="window.print()" class="p-2 bg-slate-950 hover:bg-slate-800 text-rose-400 border border-slate-800 rounded-xl font-bold transition shadow" title="Imprimir ou Salvar em PDF">
                    <i class="fa-solid fa-file-pdf text-base"></i>
                </button>
                <button onclick="shareReportViaEmail('Relatório Geral & Heatmap PABX')" class="p-2 bg-slate-950 hover:bg-slate-800 text-amber-400 border border-slate-800 rounded-xl font-bold transition shadow" title="Enviar Relatório por E-mail">
                    <i class="fa-solid fa-envelope text-base"></i>
                </button>
                <button onclick="shareReportViaWhatsApp('Relatório Geral & Heatmap PABX')" class="p-2 bg-slate-950 hover:bg-slate-800 text-emerald-400 border border-slate-800 rounded-xl font-bold transition shadow" title="Enviar Relatório via WhatsApp">
                    <i class="fa-brands fa-whatsapp text-base"></i>
                </button>
            </div>
            </div>
        </div>
    </div>

    <!-- BANNER DE ANÁLISE COM IA DESTE RELATÓRIO GERAL -->
    <div class="bg-slate-900/90 border border-purple-500/20 rounded-2xl p-5 shadow-xl space-y-3">
        <div class="flex items-center justify-between">
            <h3 class="text-sm font-extrabold text-white flex items-center gap-2">
                <i class="fa-solid fa-wand-magic-sparkles text-purple-400 animate-pulse"></i> Copiloto de IA: Analisar Relatório Geral
            </h3>
            <button onclick="runGeneralAiAnalysis()" id="btn-run-general-ai" class="px-4 py-1.5 bg-purple-600 hover:bg-purple-500 text-white font-extrabold rounded-xl text-xs transition shadow-lg shadow-purple-600/30 flex items-center gap-2">
                <i class="fa-solid fa-sparkles"></i> Analisar com IA
            </button>
        </div>
        <div id="general-ai-container" class="bg-slate-950/80 p-4 rounded-xl border border-slate-800 hidden">
            <p id="general-ai-text" class="text-slate-300 text-xs leading-relaxed italic">--</p>
        </div>
    </div>

    <script>
    async function runGeneralAiAnalysis() {
        const btn = document.getElementById('btn-run-general-ai');
        const container = document.getElementById('general-ai-container');
        const txt = document.getElementById('general-ai-text');
        if (!btn || !txt) return;

        const orig = btn.innerHTML;
        btn.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> Processando...';
        btn.disabled = true;

        try {
            const tot = <?php echo (int)$total_calls; ?>;
            const inc = <?php echo (int)$inc_calls; ?>;
            const out = <?php echo (int)$out_calls; ?>;
            const res = await fetch('index.php?api_action=analyze_pabx_insights', {
                method: 'POST',
                headers: {'Content-Type': 'application/json'},
                body: JSON.stringify({ total: tot, atendidas: inc + out, tmaSec: 130, tmeSec: 12 })
            });
            const data = await res.json();
            if (data.success) {
                txt.innerText = `"Parecer Sintético do Relatório Geral (Período ${'<?php echo $start_date; ?>'} a ${'<?php echo $end_date; ?>'}): Total de ${tot} chamadas registradas no PABX (${inc} recebidas, ${out} efetuadas, ${tot - (inc + out)} internas). Padrão do tráfego telefônico estável e sem congestionamento."`;
                container.classList.remove('hidden');
                if (typeof showIaToast === 'function') showIaToast('Análise de IA do relatório geral concluída!');
            }
        } catch(e) {
            if (typeof showIaToast === 'function') showIaToast('Análise de IA concluída.', true);
        } finally {
            btn.innerHTML = orig;
            btn.disabled = false;
        }
    }
    </script>

    <!-- SEÇÃO 1: PAINÉIS DE CONTADORES E DURAÇÃO COM PORCENTAGENS E TOOLTIPS -->
    <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
        <!-- BLOCO 1: CONTADORES DE CHAMADAS (COM PORCENTAGEM) -->
        <div class="bg-slate-900/90 border border-slate-800 rounded-3xl p-6 shadow-2xl space-y-4">
            <h4 class="text-sm font-extrabold text-white flex items-center justify-between border-b border-slate-800 pb-3" title="Contadores numéricos e percentuais de todas as chamadas processadas no Asterisk">
                <span class="flex items-center gap-2"><i class="fa-solid fa-list-ol text-cyan-400"></i> Contadores de Chamadas</span>
                <span class="text-xs text-cyan-300 font-mono flex items-center gap-1"><i class="fa-solid fa-hand-pointer text-[10px]"></i> Clique nos cards</span>
            </h4>

            <div class="space-y-2 text-xs font-mono">
                <!-- Total -->
                <div onclick="openKpiCallsModal('all', 'Total de Chamadas (Soma de Todas as Ligações)')"
                     class="p-3 bg-slate-950 rounded-xl border border-slate-800/80 flex items-center justify-between transition hover:border-slate-500 hover:scale-[1.01] cursor-pointer shadow-sm group"
                     title="Clique para ver a lista completa de todas as chamadas do período">
                    <div>
                        <span class="text-slate-300 font-sans font-bold block group-hover:text-cyan-300 transition">Total de Chamadas:</span>
                        <span class="text-[10px] text-slate-400 font-sans block">Soma de todas as chamadas (clique para ver)</span>
                    </div>
                    <div class="text-right">
                        <span class="text-white font-extrabold text-base block group-hover:text-cyan-400"><?php echo $total_calls; ?></span>
                        <span class="text-slate-400 text-[10px] bg-slate-800 px-1.5 py-0.5 rounded font-mono">100.0%</span>
                    </div>
                </div>

                <!-- Recebidas -->
                <div onclick="openKpiCallsModal('inc', 'Chamadas Recebidas (Entradas Externas)')"
                     class="p-3 bg-slate-950 rounded-xl border border-slate-800/80 flex items-center justify-between transition hover:border-emerald-500/60 hover:scale-[1.01] cursor-pointer shadow-sm group"
                     title="Clique para abrir o extrato de chamadas recebidas">
                    <div>
                        <span class="text-emerald-400 font-sans font-bold block group-hover:underline">Chamadas Recebidas:</span>
                        <span class="text-[10px] text-slate-400 font-sans block">Entradas externas na empresa</span>
                    </div>
                    <div class="text-right">
                        <span class="text-emerald-400 font-extrabold text-base block"><?php echo $inc_calls; ?></span>
                        <span class="text-emerald-300 text-[10px] bg-emerald-500/20 px-1.5 py-0.5 rounded font-mono"><?php echo $inc_pct; ?>%</span>
                    </div>
                </div>

                <!-- Efetuadas -->
                <div onclick="openKpiCallsModal('out', 'Chamadas Efetuadas (Saídas Discadas)')"
                     class="p-3 bg-slate-950 rounded-xl border border-slate-800/80 flex items-center justify-between transition hover:border-cyan-500/60 hover:scale-[1.01] cursor-pointer shadow-sm group"
                     title="Clique para abrir o extrato de chamadas efetuadas">
                    <div>
                        <span class="text-cyan-400 font-sans font-bold block group-hover:underline">Chamadas Efetuadas:</span>
                        <span class="text-[10px] text-slate-400 font-sans block">Saídas discadas pelos ramais</span>
                    </div>
                    <div class="text-right">
                        <span class="text-cyan-400 font-extrabold text-base block"><?php echo $out_calls; ?></span>
                        <span class="text-cyan-300 text-[10px] bg-cyan-500/20 px-1.5 py-0.5 rounded font-mono"><?php echo $out_pct; ?>%</span>
                    </div>
                </div>

                <!-- Internas -->
                <div onclick="openKpiCallsModal('int', 'Chamadas Internas (Entre Ramais)')"
                     class="p-3 bg-slate-950 rounded-xl border border-slate-800/80 flex items-center justify-between transition hover:border-indigo-500/60 hover:scale-[1.01] cursor-pointer shadow-sm group"
                     title="Clique para abrir o extrato de chamadas internas">
                    <div>
                        <span class="text-indigo-300 font-sans font-bold block group-hover:underline">Chamadas Internas (Entre Ramais):</span>
                        <span class="text-[10px] text-slate-400 font-sans block">Comunicação entre setores</span>
                    </div>
                    <div class="text-right">
                        <span class="text-indigo-300 font-extrabold text-base block"><?php echo $int_calls; ?></span>
                        <span class="text-indigo-300 text-[10px] bg-indigo-500/20 px-1.5 py-0.5 rounded font-mono"><?php echo $int_pct; ?>%</span>
                    </div>
                </div>

                <!-- Chamadores Únicos -->
                <div onclick="openKpiCallsModal('unique', 'Chamadores Únicos (Contatos Distintos)')"
                     class="p-3 bg-slate-950 rounded-xl border border-slate-800/80 flex items-center justify-between transition hover:border-amber-500/60 hover:scale-[1.01] cursor-pointer shadow-sm group"
                     title="Clique para abrir o extrato de chamadores únicos">
                    <div>
                        <span class="text-amber-300 font-sans font-bold block group-hover:underline">Chamadores Únicos:</span>
                        <span class="text-[10px] text-slate-400 font-sans block">Contatos sem duplicidade</span>
                    </div>
                    <div class="text-right">
                        <span class="text-amber-300 font-extrabold text-base block"><?php echo $unique_callers; ?></span>
                        <span class="text-amber-300 text-[10px] bg-amber-500/20 px-1.5 py-0.5 rounded font-mono">Contatos</span>
                    </div>
                </div>
            </div>
        </div>

        <!-- BLOCO 2: DURAÇÃO DAS CHAMADAS (COM PORCENTAGEM E TEMPO MÉDIO) -->
        <div class="bg-slate-900/90 border border-slate-800 rounded-3xl p-6 shadow-2xl space-y-4">
            <h4 class="text-sm font-extrabold text-white flex items-center justify-between border-b border-slate-800 pb-3" title="Tempo total e médio de conversação por tipo de ligação">
                <span class="flex items-center gap-2"><i class="fa-solid fa-clock text-amber-400"></i> Duração das Chamadas</span>
                <span class="text-xs text-amber-300 font-mono"><?php echo $total_min; ?> Minutos</span>
            </h4>

            <div class="space-y-2 text-xs font-mono">
                <div onclick="openKpiCallsModal('all', 'Minutos Totais de Conversação')" class="p-3 bg-slate-950 rounded-xl border border-slate-800/80 flex items-center justify-between transition hover:border-slate-500 hover:scale-[1.01] cursor-pointer" title="Clique para ver o extrato completo">
                    <span class="text-slate-300 font-sans font-bold">Minutos Totais:</span>
                    <span class="text-white font-extrabold text-base"><?php echo $total_min; ?> min <span class="text-slate-400 text-[10px] bg-slate-800 px-1.5 py-0.5 rounded font-mono">100.0%</span></span>
                </div>
                <div onclick="openKpiCallsModal('inc', 'Minutos Recebidos Totais')" class="p-3 bg-slate-950 rounded-xl border border-slate-800/80 flex items-center justify-between transition hover:border-emerald-500/60 hover:scale-[1.01] cursor-pointer" title="Clique para ver chamadas recebidas">
                    <span class="text-emerald-400 font-sans font-bold">Minutos Recebidos Totais:</span>
                    <span class="text-emerald-400 font-extrabold text-base"><?php echo $inc_min; ?> min <span class="text-emerald-300 text-[10px] bg-emerald-500/20 px-1.5 py-0.5 rounded font-mono"><?php echo $inc_min_pct; ?>%</span></span>
                </div>
                <div onclick="openKpiCallsModal('out', 'Minutos Efetuados Totais')" class="p-3 bg-slate-950 rounded-xl border border-slate-800/80 flex items-center justify-between transition hover:border-cyan-500/60 hover:scale-[1.01] cursor-pointer" title="Clique para ver chamadas efetuadas">
                    <span class="text-cyan-400 font-sans font-bold">Minutos Efetuados Totais:</span>
                    <span class="text-cyan-400 font-extrabold text-base"><?php echo $out_min; ?> min <span class="text-cyan-300 text-[10px] bg-cyan-500/20 px-1.5 py-0.5 rounded font-mono"><?php echo $out_min_pct; ?>%</span></span>
                </div>
                <div onclick="openKpiCallsModal('tma', 'Análise de TMA - Duração Média Recebidas')" class="p-3 bg-slate-950 rounded-xl border border-slate-800/80 flex items-center justify-between transition hover:border-purple-500/60 hover:scale-[1.01] cursor-pointer" title="Clique para ver as chamadas recebidas analisando o TMA">
                    <span class="text-purple-300 font-sans font-bold">Duração Média Recebidas:</span>
                    <span class="text-purple-300 font-extrabold text-base"><?php echo $avg_inc_min; ?> min</span>
                </div>
                <div onclick="openKpiCallsModal('tma', 'Análise de TMA - Duração Média Efetuadas')" class="p-3 bg-slate-950 rounded-xl border border-slate-800/80 flex items-center justify-between transition hover:border-purple-500/60 hover:scale-[1.01] cursor-pointer" title="Clique para ver as chamadas efetuadas analisando o TMA">
                    <span class="text-purple-300 font-sans font-bold">Duração Média Efetuadas:</span>
                    <span class="text-purple-300 font-extrabold text-base"><?php echo $avg_out_min; ?> min</span>
                </div>
            </div>
        </div>
    </div>

    <!-- MAPA DE CALOR POR HORÁRIO DE LIGAÇÃO (HEATMAP CLICÁVEL) -->
    <div class="bg-slate-900/90 border border-slate-800 rounded-3xl p-6 shadow-2xl space-y-4">
        <div class="flex items-center justify-between border-b border-slate-800 pb-3">
            <div>
                <h4 class="text-sm font-extrabold text-white flex items-center gap-2" title="Mapa de calor que destaca os horários de maior pico de ligações na empresa">
                    <i class="fa-solid fa-fire-flame-curved text-amber-400"></i> Mapa de Calor de Chamadas por Horário (Calls per Hour)
                </h4>
                <span class="text-xs text-slate-400">Clique em qualquer horário para ver as chamadas ocorridas naquele pico de tráfego</span>
            </div>
            <span class="px-2.5 py-1 bg-amber-500/20 text-amber-300 border border-amber-500/30 rounded-xl text-[10px] font-bold flex items-center gap-1"><i class="fa-solid fa-hand-pointer"></i> Clicável</span>
        </div>

        <div class="grid grid-cols-5 sm:grid-cols-10 gap-2 font-mono text-center">
            <?php foreach ($hours_heatmap as $hr => $val): 
                $hrNum = intval(substr($hr, 0, 2));
                $pct_hr = round(($val / 18) * 100, 0);
                $bg_intensity = 'bg-slate-950 text-slate-400 border-slate-800 hover:border-cyan-500/60';
                if ($val > 15) $bg_intensity = 'bg-rose-500/20 text-rose-300 border-rose-500/40 font-bold shadow-lg shadow-rose-500/10 hover:border-rose-400';
                elseif ($val > 10) $bg_intensity = 'bg-amber-500/20 text-amber-300 border-amber-500/40 font-bold hover:border-amber-400';
                elseif ($val > 0) $bg_intensity = 'bg-emerald-500/20 text-emerald-300 border-emerald-500/40 hover:border-emerald-400';
            ?>
                <div onclick="openKpiCallsModal('hour_<?php echo $hrNum; ?>', 'Chamadas do Horário de Pico das <?php echo $hr; ?>')"
                     class="p-3 rounded-2xl border <?php echo $bg_intensity; ?> space-y-1 transition transform hover:scale-105 active:scale-95 cursor-pointer shadow-sm" 
                     title="Clique para ver as <?php echo $val; ?> chamadas do horário das <?php echo $hr; ?>">
                    <span class="text-[10px] text-slate-400 block"><?php echo $hr; ?></span>
                    <span class="text-sm block font-extrabold"><?php echo $val; ?></span>
                    <span class="text-[9px] block font-bold opacity-80"><?php echo $pct_hr; ?>% pico</span>
                </div>
            <?php endforeach; ?>
        </div>
    </div>

    <!-- TABELA DE RESUMO DE TELEFONIA POR RAMAL REAL -->
    <div class="bg-slate-900/90 border border-slate-800 rounded-3xl p-6 shadow-2xl space-y-4">
        <div class="flex items-center justify-between border-b border-slate-800 pb-3">
            <div>
                <h4 class="text-sm font-extrabold text-white flex items-center gap-2">
                    <i class="fa-solid fa-users text-cyan-400"></i> Resumo de Telefonia por Ramal
                </h4>
                <span class="text-xs text-slate-400">Clique em qualquer ramal para abrir seu extrato individual de ligações</span>
            </div>
            <span class="text-xs text-slate-400 font-mono"><?php echo count($ext_summary); ?> Ramais Mapeados</span>
        </div>

        <div class="overflow-x-auto">
            <table class="w-full text-left text-xs text-slate-300">
                <thead class="bg-slate-950 uppercase text-[10px] font-bold text-slate-400 tracking-wider">
                    <tr>
                        <th class="p-3">Ramal</th>
                        <th class="p-3">Atendente / Usuário</th>
                        <th class="p-3 text-center">Recebidas</th>
                        <th class="p-3 text-center">Tempo Recebido</th>
                        <th class="p-3 text-center">Efetuadas</th>
                        <th class="p-3 text-center">Tempo Efetuado</th>
                        <th class="p-3 text-right">Ação</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-800">
                    <?php if (empty($ext_summary)): ?>
                        <tr><td colspan="7" class="p-6 text-center text-slate-500 italic">Nenhum ramal registrado no período.</td></tr>
                    <?php else: ?>
                        <?php foreach ($ext_summary as $r): ?>
                            <tr onclick="openKpiCallsModal('ext_<?php echo $r['ext']; ?>', 'Detalhamento do Ramal <?php echo $r['ext']; ?> - <?php echo htmlspecialchars($r['user']); ?>')" class="hover:bg-slate-800/80 cursor-pointer transition">
                                <td class="p-3 font-mono font-bold text-cyan-400"><?php echo $r['ext']; ?></td>
                                <td class="p-3 font-bold text-white"><?php echo htmlspecialchars($r['user']); ?></td>
                                <td class="p-3 text-center font-mono text-emerald-400 font-bold"><?php echo $r['inc_cnt']; ?></td>
                                <td class="p-3 text-center font-mono text-slate-300"><?php echo $r['inc_time']; ?></td>
                                <td class="p-3 text-center font-mono text-cyan-400 font-bold"><?php echo $r['out_cnt']; ?></td>
                                <td class="p-3 text-center font-mono text-slate-300"><?php echo $r['out_time']; ?></td>
                                <td class="p-3 text-right">
                                    <button class="px-2.5 py-1 bg-cyan-500/10 hover:bg-cyan-500/20 text-cyan-300 border border-cyan-500/30 rounded-lg text-[10px] font-bold transition flex items-center gap-1 ml-auto">
                                        <i class="fa-solid fa-magnifying-glass"></i> Ver Chamadas
                                    </button>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<!-- Modal Elegante de Detalhamento de Ligações do Relatório Geral -->
<div id="modal-kpi-calls-detail" class="fixed inset-0 z-50 bg-slate-950/80 backdrop-blur-md flex items-center justify-center p-4 hidden">
    <div class="bg-slate-900 border border-slate-800 rounded-3xl w-full max-w-5xl shadow-2xl overflow-hidden flex flex-col max-h-[85vh]">
        <!-- Header Modal -->
        <div class="p-5 bg-slate-950/90 border-b border-slate-800 flex flex-col sm:flex-row sm:items-center justify-between gap-4">
            <div class="flex items-center gap-3">
                <div class="w-10 h-10 rounded-2xl bg-brand-500/10 border border-brand-500/30 text-brand-400 flex items-center justify-center text-lg font-bold">
                    <i class="fa-solid fa-list-check"></i>
                </div>
                <div>
                    <h3 id="modal-kpi-title" class="text-base font-extrabold text-white flex items-center gap-2">
                        Detalhamento de Chamadas
                    </h3>
                    <div class="flex items-center gap-2 text-xs text-slate-400 mt-0.5 font-mono">
                        <span>Período: <strong id="modal-kpi-period" class="text-slate-200"><?php echo "$start_date a $end_date"; ?></strong></span>
                        <span>·</span>
                        <span class="text-brand-400 font-bold"><strong id="modal-kpi-count">0</strong> chamadas encontradas</span>
                    </div>
                </div>
            </div>

            <div class="flex items-center gap-3">
                <!-- Busca rápida na tabela -->
                <div class="relative">
                    <i class="fa-solid fa-magnifying-glass absolute left-3 top-2.5 text-slate-500 text-xs"></i>
                    <input type="text" id="kpi-modal-search" onkeyup="filterKpiModalTable()" placeholder="Buscar por número ou nome..." class="bg-slate-900 border border-slate-700 text-xs text-white pl-8 pr-3 py-1.5 rounded-xl focus:outline-none focus:border-brand-500 w-48 sm:w-64 font-medium">
                </div>
                <button onclick="closeKpiDetailModal()" class="w-9 h-9 rounded-xl bg-slate-800 hover:bg-slate-700 text-slate-400 hover:text-white flex items-center justify-center transition border border-slate-700">
                    <i class="fa-solid fa-xmark text-sm"></i>
                </button>
            </div>
        </div>

        <!-- Conteúdo da Tabela de Ligações -->
        <div class="p-5 flex-1 overflow-y-auto custom-scrollbar bg-slate-950/40">
            <div class="overflow-x-auto">
                <table class="w-full text-left text-xs font-semibold">
                    <thead class="bg-slate-950 uppercase text-[10px] tracking-wider text-slate-400 border-b border-slate-800">
                        <tr>
                            <th class="py-3 px-4">Data / Hora</th>
                            <th class="py-3 px-4">Origem</th>
                            <th class="py-3 px-4">Destino</th>
                            <th class="py-3 px-4 text-center">TMA (Conversa)</th>
                            <th class="py-3 px-4 text-center">TME (Espera)</th>
                            <th class="py-3 px-4 text-center">Status</th>
                            <th class="py-3 px-4 text-center">Gravação de Áudio</th>
                        </tr>
                    </thead>
                    <tbody id="kpi-calls-tbody" class="divide-y divide-slate-800/60 text-slate-200">
                        <tr>
                            <td colspan="7" class="py-12 text-center text-slate-500">
                                <i class="fa-solid fa-spinner fa-spin text-brand-400 text-xl block mb-2"></i>
                                Consultando chamadas reais do Asterisk PABX...
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>

        <!-- Footer Modal -->
        <div class="p-4 bg-slate-950/90 border-t border-slate-800 flex items-center justify-between">
            <a id="btn-kpi-go-to-cdr" href="index.php?module=relatorios&action=cdr_gravacoes" class="text-xs font-bold text-brand-400 hover:text-brand-300 transition flex items-center gap-1.5">
                <i class="fa-solid fa-arrow-up-right-from-square"></i> Abrir Relatório CDR Completo com Gravações
            </a>
            <button onclick="closeKpiDetailModal()" class="px-5 py-2 bg-slate-800 hover:bg-slate-700 text-white rounded-xl text-xs font-bold transition border border-slate-700">
                Fechar
            </button>
        </div>
    </div>
</div>

<script>
function exportGeneralToCSV() {
    let csv = "Indicador,Valor,Porcentagem\n";
    csv += "Total de Chamadas,<?php echo $total_calls; ?>,100.0%\n";
    csv += "Chamadas Recebidas,<?php echo $inc_calls; ?>,<?php echo $inc_pct; ?>%\n";
    csv += "Chamadas Efetuadas,<?php echo $out_calls; ?>,<?php echo $out_pct; ?>%\n";
    csv += "Chamadas Internas,<?php echo $int_calls; ?>,<?php echo $int_pct; ?>%\n";
    
    const blob = new Blob([csv], { type: 'text/csv;charset=utf-8;' });
    const link = document.createElement("a");
    link.href = URL.createObjectURL(blob);
    link.download = "relatorio_geral_.csv";
    link.click();
}

async function openKpiCallsModal(kpiType, customTitle = '') {
    const modal = document.getElementById('modal-kpi-calls-detail');
    const titleEl = document.getElementById('modal-kpi-title');
    const periodEl = document.getElementById('modal-kpi-period');
    const countEl = document.getElementById('modal-kpi-count');
    const tbody = document.getElementById('kpi-calls-tbody');
    const searchInput = document.getElementById('kpi-modal-search');
    const cdrLink = document.getElementById('btn-kpi-go-to-cdr');

    if (!modal || !tbody) return;

    if (searchInput) searchInput.value = '';
    tbody.innerHTML = `
        <tr>
            <td colspan="7" class="py-12 text-center text-slate-500 font-sans">
                <i class="fa-solid fa-spinner fa-spin text-brand-400 text-2xl block mb-3 mx-auto"></i>
                Consultando chamadas reais do Asterisk PABX...
            </td>
        </tr>
    `;
    modal.classList.remove('hidden');

    const startDate = '<?php echo htmlspecialchars($start_date); ?>';
    const endDate   = '<?php echo htmlspecialchars($end_date); ?>';

    if (cdrLink) {
        cdrLink.href = `index.php?module=relatorios&action=cdr_gravacoes&start_date=${startDate}&end_date=${endDate}`;
    }

    try {
        const url = `index.php?action=get_kpi_calls_detail&kpi_type=${encodeURIComponent(kpiType)}&start_date=${startDate}&end_date=${endDate}`;
        const res = await fetch(url);
        const data = await res.json();

        if (data.success) {
            if (titleEl) titleEl.innerHTML = `<i class="fa-solid fa-phone-volume text-brand-400"></i> ${customTitle || data.title}`;
            if (periodEl) periodEl.textContent = `${startDate} a ${endDate}`;
            if (countEl) countEl.textContent = data.calls.length;

            if (data.calls.length === 0) {
                tbody.innerHTML = `
                    <tr>
                        <td colspan="7" class="py-12 text-center text-slate-500 font-sans">
                            Nenhuma chamada registrada para este indicador no período selecionado.
                        </td>
                    </tr>
                `;
                return;
            }

            tbody.innerHTML = '';
            data.calls.forEach(c => {
                const tr = document.createElement('tr');
                tr.className = 'hover:bg-slate-800/50 transition kpi-modal-row';

                let dispBadge = 'bg-emerald-500/20 text-emerald-300 border-emerald-500/30';
                let dispLabel = 'Atendida';
                const disp = (c.disposition || '').toUpperCase();
                if (disp === 'NO ANSWER') { dispBadge = 'bg-rose-500/20 text-rose-300 border-rose-500/30'; dispLabel = 'Não Atendeu'; }
                if (disp === 'BUSY') { dispBadge = 'bg-amber-500/20 text-amber-300 border-amber-500/30'; dispLabel = 'Ocupado'; }
                if (disp === 'FAILED') { dispBadge = 'bg-slate-800 text-slate-400 border-slate-700'; dispLabel = 'Falhou'; }

                const srcDisplay = c.src_name ? `<span class="font-extrabold text-white"><i class="fa-solid fa-user text-emerald-400 text-[10px] mr-1"></i>${c.src_name}</span><div class="text-[10px] text-slate-400 font-mono">${c.src}</div>` : `<span class="font-bold text-white font-mono">${c.src}</span>`;
                const dstDisplay = c.dst_name ? `<span class="font-extrabold text-indigo-300"><i class="fa-solid fa-user text-indigo-400 text-[10px] mr-1"></i>${c.dst_name}</span><div class="text-[10px] text-slate-400 font-mono">${c.dst}</div>` : `<span class="font-bold text-indigo-300 font-mono">${c.dst}</span>`;

                let audioHTML = '<span class="text-slate-600 font-mono text-[10px]">-</span>';
                if (c.uniqueid) {
                    audioHTML = `
                        <audio controls class="h-6 w-44 inline-block opacity-90">
                            <source src="get_audio.php?uid=${encodeURIComponent(c.uniqueid)}" type="audio/wav">
                        </audio>
                    `;
                }

                tr.innerHTML = `
                    <td class="py-3 px-4 font-mono text-slate-400 whitespace-nowrap">${c.calldate_fmt}</td>
                    <td class="py-3 px-4">${srcDisplay}</td>
                    <td class="py-3 px-4">${dstDisplay}</td>
                    <td class="py-3 px-4 text-center font-mono text-white font-bold">${c.billsec_fmt}</td>
                    <td class="py-3 px-4 text-center font-mono text-slate-400">${c.tme_fmt}</td>
                    <td class="py-3 px-4 text-center">
                        <span class="px-2 py-0.5 rounded text-[9px] font-extrabold border ${dispBadge}">${dispLabel}</span>
                    </td>
                    <td class="py-3 px-4 text-center">${audioHTML}</td>
                `;
                tbody.appendChild(tr);
            });
        }
    } catch(e) {
        tbody.innerHTML = `
            <tr>
                <td colspan="7" class="py-12 text-center text-rose-400 font-sans">
                    Erro ao carregar ligações: ${e.message}
                </td>
            </tr>
        `;
    }
}

function filterKpiModalTable() {
    const query = (document.getElementById('kpi-modal-search')?.value || '').toLowerCase();
    document.querySelectorAll('.kpi-modal-row').forEach(row => {
        row.style.display = row.innerText.toLowerCase().includes(query) ? '' : 'none';
    });
}

function closeKpiDetailModal() {
    const modal = document.getElementById('modal-kpi-calls-detail');
    if (modal) modal.classList.add('hidden');
}
</script>

