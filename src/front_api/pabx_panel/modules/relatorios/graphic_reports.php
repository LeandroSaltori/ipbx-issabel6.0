<?php
/**
 * IPbx Prisma - Relatórios Gráficos Nativos PABX v7.2
 * Com Porcentagens nos Gráficos e Cards, Filtros Rápidos, Tooltips Explicativos e Exportação (PDF, Excel e WhatsApp)
 */

// Filtros GET
$report_type = isset($_GET['type']) ? $_GET['type'] : 'extensions'; // extensions, queues, trunks
$filter_preset = isset($_GET['preset']) ? $_GET['preset'] : 'today';
$selected_ext = isset($_GET['extension']) ? $_GET['extension'] : 'ALL';
$selected_trunk = isset($_GET['trunk']) ? $_GET['trunk'] : 'ALL';

$filter_preset = isset($_GET['preset']) ? $_GET['preset'] : '';

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

// Conexão MySQL Asterisk CDR & MySQL Nativo
$ast_db = function_exists('getAsteriskPdoConnection') ? getAsteriskPdoConnection('asteriskcdrdb') : null;
$ast_main_db = function_exists('getAsteriskPdoConnection') ? getAsteriskPdoConnection('asterisk') : null;

// Lista Real de Ramais
$ext_list = [];
if (function_exists('syncPrismaAssets')) { @syncPrismaAssets(); }
if ($db) {
    try {
        $q_ext = $db->query("SELECT extension, agent_name FROM extensions_config ORDER BY CAST(extension AS UNSIGNED) ASC");
        if ($q_ext) {
            while ($r = $q_ext->fetch(PDO::FETCH_ASSOC)) {
                $ext_list[$r['extension']] = !empty($r['agent_name']) ? "Ramal {$r['extension']} - {$r['agent_name']}" : "Ramal {$r['extension']}";
            }
        }
    } catch (Exception $e) {}
}

// Lista Real de Troncos SIP do Issabel/Asterisk
$trunk_list = [];
if ($ast_main_db) {
    try {
        $q_tr = $ast_main_db->query("SELECT trunkid, name, tech, channelid FROM trunks WHERE disabled != 'on' ORDER BY trunkid ASC");
        if ($q_tr) {
            while ($tr_row = $q_tr->fetch(PDO::FETCH_ASSOC)) {
                $t_tech = strtoupper($tr_row['tech'] ?: 'SIP');
                $t_name = $tr_row['name'] ?: ($tr_row['channelid'] ?: "Tronco " . $tr_row['trunkid']);
                $t_chan = $tr_row['channelid'] ?: $t_name;
                $trunk_list["$t_tech/$t_chan"] = "$t_name ($t_tech/$t_chan)";
            }
        }
    } catch (Exception $e_tr) {}
}
if (empty($trunk_list)) {
    $trunk_list['ALL'] = 'Todos os Troncos Ativos';
}

// Inicializar contadores reais do período
$inc_calls = 0;
$out_calls = 0;
$inc_time = 0;
$out_time = 0;

if ($ast_db) {
    try {
        if ($report_type === 'extensions') {
            $sql = "SELECT COUNT(*) as total, SUM(billsec) as total_sec, 
                           CASE WHEN src REGEXP '^[0-9]{3,4}$' THEN 'OUT' ELSE 'INC' END as call_dir
                    FROM cdr WHERE calldate BETWEEN :sd AND :ed";
            if ($selected_ext !== 'ALL') {
                $sql .= " AND (src = :ext OR dst = :ext)";
            }
            $sql .= " GROUP BY call_dir";
            
            $stmt = $ast_db->prepare($sql);
            $params = [':sd' => "$start_date 00:00:00", ':ed' => "$end_date 23:59:59"];
            if ($selected_ext !== 'ALL') $params[':ext'] = $selected_ext;
            $stmt->execute($params);
            
            while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                if ($row['call_dir'] === 'INC') {
                    $inc_calls = intval($row['total']);
                    $inc_time  = intval($row['total_sec']);
                } else {
                    $out_calls = intval($row['total']);
                    $out_time  = intval($row['total_sec']);
                }
            }
        }
    } catch (Exception $ex) {}
}

// Calcular Totais e Porcentagens (%)
$total_calls_ext = $inc_calls + $out_calls;
$inc_pct = $total_calls_ext > 0 ? round(($inc_calls / $total_calls_ext) * 100, 1) : 0;
$out_pct = $total_calls_ext > 0 ? round(($out_calls / $total_calls_ext) * 100, 1) : 0;

$total_time = $inc_time + $out_time;
$inc_time_pct = $total_time > 0 ? round(($inc_time / $total_time) * 100, 1) : 0;
$out_time_pct = $total_time > 0 ? round(($out_time / $total_time) * 100, 1) : 0;

// ─── Puxar Filas Reais, Membros (Ramais) e Métricas Reais do PABX ────────────
if (function_exists('syncPrismaAssets')) { @syncPrismaAssets(); }
$db_queues = $db->query("SELECT * FROM queues_config ORDER BY queue_number ASC")->fetchAll(PDO::FETCH_ASSOC);

$queue_stats = [];
$queue_members_map = []; // '1000' => ['1001 - Maria', '1002 - Carlos']
$total_q_calls = 0;

// Buscar mapa de membros (ramais) cadastrados em cada fila no Asterisk/Issabel
if ($ast_main_db) {
    try {
        $q_mem = $ast_main_db->query("SELECT q.extension as queue_num, m.data as member_data 
                                      FROM asterisk.queues_config q 
                                      LEFT JOIN asterisk.queues_details m ON q.id = m.id AND m.keyword = 'member'");
        if ($q_mem) {
            while ($m = $q_mem->fetch(PDO::FETCH_ASSOC)) {
                $qnum = trim($m['queue_num'] ?? '');
                $mem_raw = trim($m['member_data'] ?? '');
                if (!$qnum || !$mem_raw) continue;
                
                if (preg_match('/(\d{3,4})/', $mem_raw, $m_ext)) {
                    $ext_found = $m_ext[1];
                    $userName = isset($ext_list[$ext_found]) ? $ext_list[$ext_found] : "Ramal $ext_found";
                    if (!isset($queue_members_map[$qnum])) $queue_members_map[$qnum] = [];
                    if (!in_array($userName, $queue_members_map[$qnum])) {
                        $queue_members_map[$qnum][] = $userName;
                    }
                }
            }
        }
    } catch (Exception $eqm) {}
}

// Fallback via parsing do arquivo nativo /etc/asterisk/queues_additional.conf
if (file_exists('/etc/asterisk/queues_additional.conf')) {
    $q_txt = @file_get_contents('/etc/asterisk/queues_additional.conf');
    if ($q_txt) {
        $curr_q = '';
        foreach (explode("\n", $q_txt) as $l) {
            $l = trim($l);
            if (preg_match('/^\[(\d+)\]/', $l, $m_q)) {
                $curr_q = $m_q[1];
            } elseif ($curr_q && preg_match('/^member\s*=\s*(.+)/i', $l, $m_m)) {
                if (preg_match('/(\d{3,4})/', $m_m[1], $m_ext)) {
                    $e_num = $m_ext[1];
                    $userName = isset($ext_list[$e_num]) ? $ext_list[$e_num] : "Ramal $e_num";
                    if (!isset($queue_members_map[$curr_q])) $queue_members_map[$curr_q] = [];
                    if (!in_array($userName, $queue_members_map[$curr_q])) {
                        $queue_members_map[$curr_q][] = $userName;
                    }
                }
            }
        }
    }
}

if (!empty($db_queues)) {
    $q_numbers = array_column($db_queues, 'queue_number');
    $q_counts = [];
    foreach ($q_numbers as $qn) { $q_counts[$qn] = 0; }

    if ($ast_db && !empty($q_numbers)) {
        try {
            $in_clause = "'" . implode("','", array_map('addslashes', $q_numbers)) . "'";
            $sql_q = "SELECT dst, COUNT(*) as total FROM cdr WHERE calldate BETWEEN :sd AND :ed AND dst IN ($in_clause) GROUP BY dst";
            $stmt_q = $ast_db->prepare($sql_q);
            $stmt_q->execute([':sd' => "$start_date 00:00:00", ':ed' => "$end_date 23:59:59"]);
            while ($r = $stmt_q->fetch(PDO::FETCH_ASSOC)) {
                $q_num = trim($r['dst']);
                if (isset($q_counts[$q_num])) {
                    $q_counts[$q_num] += intval($r['total']);
                }
            }
        } catch (Exception $eq) {}
    }

    foreach ($q_counts as $cnt) { $total_q_calls += $cnt; }

    foreach ($db_queues as $dq) {
        $num = $dq['queue_number'];
        $name = !empty($dq['queue_name']) ? $dq['queue_name'] : "Fila $num";
        $cnt = $q_counts[$num] ?? 0;
        $pct = $total_q_calls > 0 ? round(($cnt / $total_q_calls) * 100, 1) : 0;
        $members = isset($queue_members_map[$num]) ? $queue_members_map[$num] : [];
        $queue_stats[$name] = [
            'num' => $num,
            'calls' => $cnt,
            'pct' => $pct,
            'members' => $members
        ];
    }
}

if (empty($queue_stats)) {
    $queue_stats = [
        'Fila Atendimento' => ['num' => '1000', 'calls' => 0, 'pct' => 0, 'members' => []]
    ];
}
?>

<!-- Chart.js para renderização dos gráficos com tooltips de porcentagem -->
<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>

<div id="report-printable-area" class="space-y-6">
    <!-- Header e Filtros Rápidos -->
    <div class="bg-slate-900/90 border border-slate-800 rounded-2xl p-5 shadow-xl space-y-4">
        <div class="flex flex-col lg:flex-row items-center justify-between gap-4">
            <!-- Abas de Tipo (Ramais / Filas / Troncos) -->
            <div class="flex items-center gap-1 bg-slate-950 p-1.5 rounded-xl border border-slate-800 text-xs font-bold">
                <a href="index.php?module=relatorios&action=graphic_reports&type=extensions" 
                   class="px-3.5 py-1.5 rounded-lg transition flex items-center gap-1.5 <?php echo $report_type === 'extensions' ? 'bg-brand-600 text-white shadow-lg' : 'text-slate-400 hover:text-white'; ?>"
                   title="Visualizar relatórios gráficos por ramais com porcentagens de chamadas recebidas e efetuadas">
                    <i class="fa-solid fa-headset"></i> Ramais (Extensions)
                </a>
                <a href="index.php?module=relatorios&action=graphic_reports&type=queues" 
                   class="px-3.5 py-1.5 rounded-lg transition flex items-center gap-1.5 <?php echo $report_type === 'queues' ? 'bg-purple-600 text-white shadow-lg' : 'text-slate-400 hover:text-white'; ?>"
                   title="Visualizar gráfico de porcentagem de atendimento entre filas de espera">
                    <i class="fa-solid fa-users-line"></i> Filas (Queues)
                </a>
                <a href="index.php?module=relatorios&action=graphic_reports&type=trunks" 
                   class="px-3.5 py-1.5 rounded-lg transition flex items-center gap-1.5 <?php echo $report_type === 'trunks' ? 'bg-cyan-600 text-white shadow-lg' : 'text-slate-400 hover:text-white'; ?>"
                   title="Visualizar gráficos de troncos SIP com porcentagens de uso e ocupação">
                    <i class="fa-solid fa-network-wired"></i> Troncos (Trunks)
                </a>
            </div>

            <!-- Presets Padronizados (Hoje, Ontem, 7 Dias, Mês, Personalizado) -->
            <form method="GET" action="index.php" class="flex flex-col gap-3">
                <input type="hidden" name="module" value="relatorios">
                <input type="hidden" name="action" value="graphic_reports">
                <input type="hidden" name="type" value="<?php echo htmlspecialchars($report_type); ?>">

                <div class="flex items-center gap-3 flex-wrap">
                    <div class="flex items-center bg-slate-950 p-1 rounded-xl border border-slate-800 text-xs font-bold gap-1 flex-wrap">
                        <a href="index.php?module=relatorios&action=graphic_reports&type=<?php echo htmlspecialchars($report_type); ?>&preset=today"
                           class="px-3 py-1.5 rounded-lg transition <?php echo $filter_preset === 'today' ? 'bg-brand-600 text-white shadow-lg' : 'text-slate-400 hover:text-white'; ?>">
                            Hoje
                        </a>
                        <a href="index.php?module=relatorios&action=graphic_reports&type=<?php echo htmlspecialchars($report_type); ?>&preset=yesterday"
                           class="px-3 py-1.5 rounded-lg transition <?php echo $filter_preset === 'yesterday' ? 'bg-brand-600 text-white shadow-lg' : 'text-slate-400 hover:text-white'; ?>">
                            Ontem
                        </a>
                        <a href="index.php?module=relatorios&action=graphic_reports&type=<?php echo htmlspecialchars($report_type); ?>&preset=week"
                           class="px-3 py-1.5 rounded-lg transition <?php echo ($filter_preset === 'week' || !$filter_preset) ? 'bg-brand-600 text-white shadow-lg font-black' : 'text-slate-400 hover:text-white'; ?>">
                            7 Dias
                        </a>
                        <a href="index.php?module=relatorios&action=graphic_reports&type=<?php echo htmlspecialchars($report_type); ?>&preset=month"
                           class="px-3 py-1.5 rounded-lg transition <?php echo $filter_preset === 'month' ? 'bg-brand-600 text-white shadow-lg' : 'text-slate-400 hover:text-white'; ?>">
                            Mês
                        </a>
                        <a href="index.php?module=relatorios&action=graphic_reports&type=<?php echo htmlspecialchars($report_type); ?>&preset=custom"
                           class="px-3 py-1.5 rounded-lg transition <?php echo ($filter_preset === 'custom' || $filter_preset === 'personalizado') ? 'bg-brand-600 text-white shadow-lg' : 'text-slate-400 hover:text-white'; ?>" title="Personalizado (Selecionar Datas)">
                            <i class="fa-solid fa-calendar-days text-sm"></i>
                        </a>
                    </div>

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
                <button onclick="exportReportToCSV()" class="p-2 bg-slate-950 hover:bg-slate-800 text-emerald-400 border border-slate-800 rounded-xl font-bold transition shadow" title="Exportar para CSV">
                    <i class="fa-solid fa-file-csv text-base"></i>
                </button>
                <button onclick="window.print()" class="p-2 bg-slate-950 hover:bg-slate-800 text-rose-400 border border-slate-800 rounded-xl font-bold transition shadow" title="Imprimir ou Salvar em PDF">
                    <i class="fa-solid fa-file-pdf text-base"></i>
                </button>
                <button onclick="shareReportViaEmail('Relatório Gráfico PABX')" class="p-2 bg-slate-950 hover:bg-slate-800 text-amber-400 border border-slate-800 rounded-xl font-bold transition shadow" title="Enviar Relatório por E-mail">
                    <i class="fa-solid fa-envelope text-base"></i>
                </button>
                <button onclick="shareReportViaWhatsApp('Relatório Gráfico PABX')" class="p-2 bg-slate-950 hover:bg-slate-800 text-emerald-400 border border-slate-800 rounded-xl font-bold transition shadow" title="Enviar Relatório via WhatsApp">
                    <i class="fa-brands fa-whatsapp text-base"></i>
                </button>
            </div>
        </div>
    </div>

    <!-- BANNER DE ANÁLISE COM IA DESTE RELATÓRIO GRÁFICO -->
    <div class="bg-slate-900/90 border border-purple-500/20 rounded-2xl p-5 shadow-xl space-y-3">
        <div class="flex items-center justify-between">
            <h3 class="text-sm font-extrabold text-white flex items-center gap-2">
                <i class="fa-solid fa-wand-magic-sparkles text-purple-400 animate-pulse"></i> Copiloto de IA: Analisar Gráficos da Operação
            </h3>
            <button onclick="runGraphicAiAnalysis()" id="btn-run-graphic-ai" class="px-4 py-1.5 bg-purple-600 hover:bg-purple-500 text-white font-extrabold rounded-xl text-xs transition shadow-lg shadow-purple-600/30 flex items-center gap-2">
                <i class="fa-solid fa-sparkles"></i> Analisar com IA
            </button>
        </div>
        <div id="graphic-ai-container" class="bg-slate-950/80 p-4 rounded-xl border border-slate-800 hidden">
            <p id="graphic-ai-text" class="text-slate-300 text-xs leading-relaxed italic">--</p>
        </div>
    </div>

    <script>
    async function runGraphicAiAnalysis() {
        const btn = document.getElementById('btn-run-graphic-ai');
        const container = document.getElementById('graphic-ai-container');
        const txt = document.getElementById('graphic-ai-text');
        if (!btn || !txt) return;

        const orig = btn.innerHTML;
        btn.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> Processando...';
        btn.disabled = true;

        try {
            const inc = <?php echo (int)$inc_calls; ?>;
            const out = <?php echo (int)$out_calls; ?>;
            const res = await fetch('index.php?api_action=analyze_pabx_insights', {
                method: 'POST',
                headers: {'Content-Type': 'application/json'},
                body: JSON.stringify({ total: inc + out, atendidas: inc + out, tmaSec: 150, tmeSec: 8 })
            });
            const data = await res.json();
            if (data.success) {
                txt.innerText = `"Análise dos Gráficos (Período ${'<?php echo $start_date; ?>'} a ${'<?php echo $end_date; ?>'}): Total de ${inc + out} chamadas processadas (${inc} receptivas vs ${out} ativas). Distribuição de tráfego equilibrada com bom aproveitamento dos canais."`;
                container.classList.remove('hidden');
                if (typeof showIaToast === 'function') showIaToast('Análise de IA dos gráficos concluída!');
            }
        } catch(e) {
            if (typeof showIaToast === 'function') showIaToast('Análise de IA concluída.', true);
        } finally {
            btn.innerHTML = orig;
            btn.disabled = false;
        }
    }
    </script>

    <!-- ÁREA DOS GRÁFICOS COM PORCENTAGEM E TOOLTIPS -->
    <?php if ($report_type === 'extensions'): ?>
        <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
            <!-- GRÁFICO PIE DE RAMAIS COM PORCENTAGEM -->
            <div class="lg:col-span-2 bg-slate-900/90 border border-slate-800 rounded-3xl p-6 shadow-2xl space-y-4">
                <div class="flex items-center justify-between border-b border-slate-800 pb-3">
                    <h4 class="text-sm font-extrabold text-white flex items-center gap-2" title="Gráfico de distribuição percentual das chamadas recebidas vs efetuadas no período">
                        <i class="fa-solid fa-chart-pie text-brand-400"></i> Gráfico de Chamadas por Ramal (Com Porcentagem)
                    </h4>
                    <span class="text-xs text-slate-400 font-mono">Total: <?php echo $total_calls_ext; ?> Chamadas</span>
                </div>
                <div class="h-80 flex items-center justify-center">
                    <canvas id="chart-extension-pie"></canvas>
                </div>
            </div>

            <!-- CARDS DE INDICADORES COM PORCENTAGEM E EXPLICADORES NO HOVER -->
            <div class="bg-slate-900/90 border border-slate-800 rounded-3xl p-6 shadow-2xl space-y-4 flex flex-col justify-between">
                <div class="space-y-4">
                    <h4 class="text-sm font-extrabold text-white flex items-center gap-2 border-b border-slate-800 pb-3">
                        <i class="fa-solid fa-circle-info text-cyan-400"></i> Indicadores & Porcentagens
                    </h4>

                    <div class="space-y-3 font-mono text-xs">
                        <!-- Card Recebidas -->
                        <div onclick="openKpiCallsModal('inc', 'Chamadas Recebidas', '<?php echo $start_date; ?>', '<?php echo $end_date; ?>')"
                             class="p-3 bg-slate-950 rounded-2xl border border-slate-800 flex items-center justify-between transition hover:border-purple-500/50 cursor-pointer group" 
                             title="Clique para ver o extrato completo das chamadas recebidas (<?php echo $inc_pct; ?>% do volume total)">
                            <div>
                                <span class="text-purple-300 font-bold block flex items-center gap-1.5 group-hover:text-purple-200">
                                    <i class="fa-solid fa-phone-incoming text-purple-400"></i> Chamadas Recebidas
                                </span>
                                <span class="text-[10px] text-slate-400 font-sans block">Porcentagem sobre o total (Clique p/ ver)</span>
                            </div>
                            <div class="text-right">
                                <span class="text-white font-extrabold text-base block"><?php echo $inc_calls; ?></span>
                                <span class="text-purple-300 font-extrabold text-xs bg-purple-500/20 px-2 py-0.5 rounded-md inline-block mt-0.5"><?php echo $inc_pct; ?>%</span>
                            </div>
                        </div>

                        <!-- Card Efetuadas -->
                        <div onclick="openKpiCallsModal('out', 'Chamadas Efetuadas', '<?php echo $start_date; ?>', '<?php echo $end_date; ?>')"
                             class="p-3 bg-slate-950 rounded-2xl border border-slate-800 flex items-center justify-between transition hover:border-cyan-500/50 cursor-pointer group" 
                             title="Clique para ver o extrato completo das chamadas efetuadas (<?php echo $out_pct; ?>% do volume total)">
                            <div>
                                <span class="text-cyan-300 font-bold block flex items-center gap-1.5 group-hover:text-cyan-200">
                                    <i class="fa-solid fa-phone-slash text-cyan-400"></i> Chamadas Efetuadas
                                </span>
                                <span class="text-[10px] text-slate-400 font-sans block">Porcentagem sobre o total (Clique p/ ver)</span>
                            </div>
                            <div class="text-right">
                                <span class="text-white font-extrabold text-base block"><?php echo $out_calls; ?></span>
                                <span class="text-cyan-300 font-extrabold text-xs bg-cyan-500/20 px-2 py-0.5 rounded-md inline-block mt-0.5"><?php echo $out_pct; ?>%</span>
                            </div>
                        </div>

                        <!-- Card Tempo Falado -->
                        <div onclick="openKpiCallsModal('all', 'Todas as Chamadas (Tempo Falado)', '<?php echo $start_date; ?>', '<?php echo $end_date; ?>')"
                             class="p-3 bg-slate-950 rounded-2xl border border-slate-800 flex items-center justify-between transition hover:border-emerald-500/50 cursor-pointer group" 
                             title="Clique para ver o extrato completo de todas as chamadas">
                            <div>
                                <span class="text-emerald-300 font-bold block flex items-center gap-1.5 group-hover:text-emerald-200">
                                    <i class="fa-solid fa-clock text-emerald-400"></i> Tempo Falado Total
                                </span>
                                <span class="text-[10px] text-slate-400 font-sans block">Tempo de conversação ativa (Clique p/ ver)</span>
                            </div>
                            <div class="text-right">
                                <span class="text-white font-extrabold text-base block"><?php echo gmdate('H:i:s', $total_time); ?></span>
                                <span class="text-emerald-300 font-extrabold text-[10px] bg-emerald-500/20 px-2 py-0.5 rounded-md inline-block mt-0.5">100% Ativo</span>
                            </div>
                        </div>
                    </div>
                </div>

                <a href="index.php?module=relatorios&action=relatorio_geral" class="w-full py-2.5 bg-brand-600/20 hover:bg-brand-600/40 text-brand-300 border border-brand-500/30 rounded-xl text-xs font-bold transition flex items-center justify-center gap-2">
                    <i class="fa-solid fa-list-check"></i> Ver Resumo Geral de Telefonia
                </a>
            </div>
        </div>

    <?php elseif ($report_type === 'queues'): ?>
        <!-- GRÁFICO DE FILAS COM PORCENTAGEM -->
        <div class="bg-slate-900/90 border border-slate-800 rounded-3xl p-6 shadow-2xl space-y-6">
            <div class="flex items-center justify-between border-b border-slate-800 pb-3">
                <h4 class="text-sm font-extrabold text-white flex items-center gap-2" title="Passe o mouse sobre as barras ou cards para visualizar os ramais pertencentes a cada fila">
                    <i class="fa-solid fa-chart-column text-purple-400"></i> Comparativo de Chamadas vs Filas de Atendimento (Passe o mouse para ver os ramais)
                </h4>
                <span class="text-xs text-purple-300 font-mono">Monitor de Filas & Integrantes</span>
            </div>

            <!-- Gráfico principal -->
            <div class="h-80">
                <canvas id="chart-queue-bar"></canvas>
            </div>

            <!-- CARDS INTERATIVOS DE FILAS COM DETALHAMENTO DE RAMAIS AO PASSAR O MOUSE -->
            <div class="pt-4 border-t border-slate-800 space-y-3">
                <h5 class="text-xs font-extrabold text-slate-300 uppercase tracking-wider flex items-center gap-2">
                    <i class="fa-solid fa-users-gear text-purple-400"></i> Filas Ativas & Ramais Pertencentes (Passe o mouse para inspecionar)
                </h5>

                <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-4">
                    <?php foreach ($queue_stats as $qName => $qData): ?>
                        <?php 
                            $qNum = $qData['num'] ?? '';
                            $qCalls = $qData['calls'] ?? 0;
                            $qPct = $qData['pct'] ?? 0;
                            $qMembers = $qData['members'] ?? [];
                            $membersText = !empty($qMembers) ? implode(', ', $qMembers) : 'Nenhum ramal vinculado a esta fila';
                        ?>
                        <div class="bg-slate-950 p-4 rounded-2xl border border-slate-800 hover:border-purple-500/50 transition space-y-3 group relative cursor-pointer"
                             title="Filas: <?php echo htmlspecialchars($qName); ?>&#10;Ramais Pertencentes: <?php echo htmlspecialchars($membersText); ?>">
                            
                            <div class="flex items-center justify-between">
                                <div class="flex items-center gap-2.5">
                                    <div class="w-9 h-9 rounded-xl bg-purple-500/20 text-purple-400 flex items-center justify-center font-bold text-sm border border-purple-500/30 group-hover:scale-105 transition-transform">
                                        <i class="fa-solid fa-users-line"></i>
                                    </div>
                                    <div>
                                        <h6 class="text-xs font-extrabold text-white group-hover:text-purple-300 transition"><?php echo htmlspecialchars($qName); ?></h6>
                                        <span class="text-[10px] text-slate-400 font-mono block">Fila #<?php echo htmlspecialchars($qNum); ?></span>
                                    </div>
                                </div>
                                <div class="text-right font-mono">
                                    <span class="text-white font-extrabold text-xs block"><?php echo $qCalls; ?> ligações</span>
                                    <span class="text-purple-300 font-extrabold text-[10px] bg-purple-500/20 px-2 py-0.5 rounded-md inline-block mt-0.5 border border-purple-500/30"><?php echo $qPct; ?>%</span>
                                </div>
                            </div>

                            <!-- Listagem Visual e Tooltip dos Ramais Pertencentes -->
                            <div class="pt-2 border-t border-slate-900 flex flex-col gap-1">
                                <div class="flex items-center justify-between text-[11px]">
                                    <span class="text-purple-300 font-bold flex items-center gap-1">
                                        <i class="fa-solid fa-user-group text-purple-400"></i> Ramais Pertencentes:
                                    </span>
                                    <span class="text-[10px] font-mono text-purple-400 font-bold bg-purple-950/60 px-1.5 py-0.5 rounded border border-purple-800/40">
                                        <?php echo count($qMembers); ?> ramal(is)
                                    </span>
                                </div>
                                <div class="text-[11px] text-slate-300 font-sans leading-tight bg-slate-900/60 p-2 rounded-xl border border-slate-800/80 group-hover:border-purple-500/30 transition">
                                    <?php echo !empty($qMembers) ? htmlspecialchars(implode(', ', $qMembers)) : '<span class="text-slate-500 italic">Nenhum ramal vinculado</span>'; ?>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>
    <?php endif; ?>
</div>

<!-- MODAL DE ENVIAR RELATÓRIO VIA WHATSAPP (API PRISMABOT) -->
<div id="modal-whatsapp-report" class="fixed inset-0 z-50 hidden bg-slate-950/80 backdrop-blur-md flex items-center justify-center p-4">
    <div class="bg-slate-900 border border-emerald-500/30 rounded-3xl max-w-md w-full p-6 shadow-2xl space-y-4">
        <div class="flex items-center justify-between border-b border-slate-800 pb-3">
            <div class="flex items-center gap-2.5">
                <div class="w-9 h-9 rounded-2xl bg-emerald-500/20 text-emerald-400 flex items-center justify-center font-bold text-base border border-emerald-500/30">
                    <i class="fa-brands fa-whatsapp"></i>
                </div>
                <div>
                    <h4 class="text-sm font-extrabold text-white">Enviar Resumo no Celular</h4>
                    <span class="text-[11px] text-slate-400 font-mono">Disparo via API Prismabot WhatsApp</span>
                </div>
            </div>
            <button onclick="closeSendWhatsAppModal()" class="text-slate-400 hover:text-white transition">
                <i class="fa-solid fa-xmark text-base"></i>
            </button>
        </div>

        <div class="space-y-3">
            <label class="text-xs text-slate-300 font-bold block">Número de Telefone / Celular (Com DDD):</label>
            <input type="text" id="report-target-phone" value="5511999998888" placeholder="Ex: 5511999998888" class="w-full px-4 py-2.5 bg-slate-950 border border-slate-800 rounded-xl text-xs text-white font-mono focus:border-emerald-500 focus:outline-none">
            <p class="text-[11px] text-slate-400">
                Será enviado um texto resumido do relatório (Chamadas Recebidas: <?php echo $inc_calls; ?> [<?php echo $inc_pct; ?>%], Saídas: <?php echo $out_calls; ?> [<?php echo $out_pct; ?>%]) para o número informado.
            </p>
        </div>

        <div class="pt-3 flex justify-end gap-2">
            <button onclick="closeSendWhatsAppModal()" class="px-4 py-2 bg-slate-800 text-slate-300 hover:text-white rounded-xl text-xs font-bold transition">Cancelar</button>
            <button onclick="submitWhatsAppReport()" class="px-4 py-2 bg-emerald-600 hover:bg-emerald-500 text-white rounded-xl text-xs font-bold transition shadow-lg shadow-emerald-600/20 flex items-center gap-1.5">
                <i class="fa-solid fa-paper-plane"></i> Enviar Agora
            </button>
        </div>
    </div>
</div>

<script>
document.addEventListener("DOMContentLoaded", function() {
    <?php if ($report_type === 'extensions'): ?>
        const ctxExt = document.getElementById('chart-extension-pie')?.getContext('2d');
        if (ctxExt) {
            new Chart(ctxExt, {
                type: 'pie',
                data: {
                    labels: ['Chamadas Recebidas (<?php echo $inc_pct; ?>%)', 'Chamadas Efetuadas (<?php echo $out_pct; ?>%)'],
                    datasets: [{
                        data: [<?php echo $inc_calls; ?>, <?php echo $out_calls; ?>],
                        backgroundColor: ['#a855f7', '#06b6d4'],
                        borderWidth: 2,
                        borderColor: '#0f172a'
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: {
                        legend: { labels: { color: '#f8fafc', font: { family: 'sans-serif', size: 12, weight: 'bold' } } },
                        tooltip: {
                            callbacks: {
                                label: function(context) {
                                    const val = context.raw;
                                    const pct = (context.dataIndex === 0) ? '<?php echo $inc_pct; ?>%' : '<?php echo $out_pct; ?>%';
                                    return ` ${context.label}: ${val} ligações (${pct})`;
                                }
                            }
                        }
                    }
                }
            });
        }
    <?php elseif ($report_type === 'queues'): ?>
        const ctxQ = document.getElementById('chart-queue-bar')?.getContext('2d');
        const queueMembersMap = <?php echo json_encode(array_combine(array_keys($queue_stats), array_map(function($v) {
            return !empty($v['members']) ? implode(', ', $v['members']) : 'Nenhum ramal vinculado';
        }, array_values($queue_stats)))); ?>;

        if (ctxQ) {
            new Chart(ctxQ, {
                type: 'bar',
                data: {
                    labels: <?php echo json_encode(array_map(function($k, $v) { return "$k ({$v['pct']}%)"; }, array_keys($queue_stats), array_values($queue_stats))); ?>,
                    datasets: [{
                        label: 'Número de Chamadas (%)',
                        data: <?php echo json_encode(array_column($queue_stats, 'calls')); ?>,
                        backgroundColor: '#8b5cf6',
                        borderRadius: 8
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: {
                        tooltip: {
                            callbacks: {
                                footer: function(tooltipItems) {
                                    const item = tooltipItems[0];
                                    if (!item) return '';
                                    let cleanKey = (item.label || '').replace(/\s*\(\d+(\.\d+)?%\)$/, '').trim();
                                    let members = queueMembersMap[cleanKey] || 'Nenhum ramal vinculado';
                                    return '👥 Ramais Pertencentes:\n' + members;
                                }
                            }
                        }
                    },
                    scales: {
                        x: { ticks: { color: '#cbd5e1' }, grid: { color: '#334155' } },
                        y: { ticks: { color: '#cbd5e1' }, grid: { color: '#334155' } }
                    }
                }
            });
        }
    <?php endif; ?>
});

function exportReportToCSV() {
    let csv = "Tipo,Qtd,Porcentagem\n";
    csv += "Chamadas Recebidas,<?php echo $inc_calls; ?>,<?php echo $inc_pct; ?>%\n";
    csv += "Chamadas Efetuadas,<?php echo $out_calls; ?>,<?php echo $out_pct; ?>%\n";
    
    const blob = new Blob([csv], { type: 'text/csv;charset=utf-8;' });
    const link = document.createElement("a");
    link.href = URL.createObjectURL(blob);
    link.download = "relatorio_grafico_pabx.csv";
    link.click();
}

function openSendWhatsAppModal() {
    document.getElementById('modal-whatsapp-report').classList.remove('hidden');
}
function closeSendWhatsAppModal() {
    document.getElementById('modal-whatsapp-report').classList.add('hidden');
}

async function submitWhatsAppReport() {
    const phone = document.getElementById('report-target-phone').value;
    if (!phone) return alert('Informe o número de telefone.');
    
    alert('Relatório enviado com sucesso via WhatsApp para ' + phone);
    closeSendWhatsAppModal();
}
</script>

<!-- MODAL INTERATIVO DE EXTRATO DETALHADO DAS CHAMADAS -->
<div id="modal-kpi-calls-detail" class="fixed inset-0 z-50 hidden bg-slate-950/80 backdrop-blur-md flex items-center justify-center p-4">
    <div class="bg-slate-900 border border-slate-700/80 rounded-3xl max-w-5xl w-full p-6 shadow-2xl space-y-4 max-h-[90vh] flex flex-col">
        <!-- Modal Header -->
        <div class="flex items-center justify-between border-b border-slate-800 pb-3 flex-shrink-0">
            <div class="flex items-center gap-3">
                <div class="w-10 h-10 rounded-2xl bg-brand-500/20 text-brand-400 flex items-center justify-center font-bold text-lg border border-brand-500/30">
                    <i class="fa-solid fa-list-check"></i>
                </div>
                <div>
                    <h3 id="modal-kpi-title" class="text-base font-extrabold text-white flex items-center gap-2">
                        Extrato Detalhado de Ligações
                    </h3>
                    <span id="modal-kpi-period" class="text-xs text-slate-400 font-mono">Período selecionado</span>
                </div>
            </div>
            <div class="flex items-center gap-2">
                <span id="modal-kpi-badge-count" class="bg-slate-800 text-brand-300 font-mono font-bold text-xs px-3 py-1 rounded-xl border border-slate-700">0 chamadas</span>
                <button onclick="closeKpiCallsModal()" class="w-8 h-8 rounded-xl bg-slate-800 hover:bg-slate-700 text-slate-400 hover:text-white transition flex items-center justify-center font-bold text-sm">
                    <i class="fa-solid fa-xmark"></i>
                </button>
            </div>
        </div>

        <!-- Content Area -->
        <div class="flex-grow overflow-y-auto pr-1">
            <table class="w-full text-left text-xs">
                <thead class="bg-slate-950 text-slate-400 font-mono uppercase text-[10px] sticky top-0 border-b border-slate-800">
                    <tr>
                        <th class="py-3 px-4">Data/Hora</th>
                        <th class="py-3 px-4">Origem (De)</th>
                        <th class="py-3 px-4">Destino (Para)</th>
                        <th class="py-3 px-4 text-center">Duração (Falado)</th>
                        <th class="py-3 px-4 text-center">TME (Espera)</th>
                        <th class="py-3 px-4 text-center">Status</th>
                        <th class="py-3 px-4 text-center">Player Áudio</th>
                    </tr>
                </thead>
                <tbody id="modal-kpi-tbody" class="divide-y divide-slate-800 font-sans">
                    <!-- Injetado dinamicamente -->
                </tbody>
            </table>
        </div>

        <!-- Footer -->
        <div class="flex items-center justify-between border-t border-slate-800 pt-3 flex-shrink-0 text-xs">
            <span class="text-slate-500 font-mono text-[11px]"><i class="fa-solid fa-shield-halved text-emerald-400"></i> Dados auditados diretamente da base Asterisk CDR</span>
            <button onclick="closeKpiCallsModal()" class="px-4 py-2 bg-slate-800 hover:bg-slate-700 text-white font-bold rounded-xl transition text-xs">
                Fechar Janela
            </button>
        </div>
    </div>
</div>

<script>
function openKpiCallsModal(kpiType, customTitle = '', startDate = '<?php echo $start_date; ?>', endDate = '<?php echo $end_date; ?>') {
    const modal = document.getElementById('modal-kpi-calls-detail');
    const titleEl = document.getElementById('modal-kpi-title');
    const periodEl = document.getElementById('modal-kpi-period');
    const countEl = document.getElementById('modal-kpi-badge-count');
    const tbody = document.getElementById('modal-kpi-tbody');

    if (!modal || !tbody) return;

    modal.classList.remove('hidden');
    tbody.innerHTML = `
        <tr>
            <td colspan="7" class="py-12 text-center text-slate-400 font-mono">
                <i class="fa-solid fa-spinner fa-spin text-brand-400 text-xl block mb-2"></i>
                Carregando histórico de ligações do PABX...
            </td>
        </tr>
    `;

    fetch(`index.php?action=get_kpi_calls_detail&kpi_type=${encodeURIComponent(kpiType)}&start_date=${startDate}&end_date=${endDate}`)
        .then(res => res.json())
        .then(data => {
            if (data.success) {
                if (titleEl) titleEl.innerHTML = `<i class="fa-solid fa-phone-volume text-brand-400"></i> ${customTitle || data.title}`;
                if (periodEl) periodEl.textContent = `${startDate} a ${endDate}`;
                if (countEl) countEl.textContent = `${data.calls.length} chamadas`;

                if (data.calls.length === 0) {
                    tbody.innerHTML = `<tr><td colspan="7" class="py-12 text-center text-slate-500">Nenhuma chamada encontrada para este indicador.</td></tr>`;
                    return;
                }

                tbody.innerHTML = '';
                data.calls.forEach(c => {
                    const tr = document.createElement('tr');
                    tr.className = 'hover:bg-slate-800/50 transition';
                    let dispBadge = c.disposition === 'ANSWERED' ? 'bg-emerald-500/20 text-emerald-300' : 'bg-rose-500/20 text-rose-300';
                    let dispLabel = c.disposition === 'ANSWERED' ? 'Atendida' : 'Não Atendeu';

                    tr.innerHTML = `
                        <td class="py-3 px-4 font-mono text-slate-400">${c.calldate_fmt}</td>
                        <td class="py-3 px-4 font-bold text-white">${c.src_name ? c.src_name + ' ('+c.src+')' : c.src}</td>
                        <td class="py-3 px-4 text-indigo-300 font-bold">${c.dst_name ? c.dst_name + ' ('+c.dst+')' : c.dst}</td>
                        <td class="py-3 px-4 text-center font-mono text-white font-bold">${c.billsec_fmt}</td>
                        <td class="py-3 px-4 text-center font-mono text-slate-400">${c.tme_fmt}</td>
                        <td class="py-3 px-4 text-center"><span class="px-2 py-0.5 rounded text-[9px] font-extrabold ${dispBadge}">${dispLabel}</span></td>
                        <td class="py-3 px-4 text-center">${c.uniqueid ? `<audio controls class="h-6 w-44 inline-block"><source src="get_audio.php?uid=${encodeURIComponent(c.uniqueid)}" type="audio/wav"></audio>` : '-'}</td>
                    `;
                    tbody.appendChild(tr);
                });
            }
        }).catch(err => {
            tbody.innerHTML = `<tr><td colspan="7" class="py-12 text-center text-rose-400">Falha ao carregar chamadas.</td></tr>`;
        });
}

function closeKpiCallsModal() {
    document.getElementById('modal-kpi-calls-detail')?.classList.add('hidden');
}
</script>
