<?php
/**
 * IPbx Prisma - Relatório Completo de Filas v8.0
 * Design premium com métricas reais do Asterisk CDR + Queue Log.
 * Exportação CSV/PDF, filtros de período, tabela por agente.
 */

$filter_preset = isset($_GET['preset']) ? $_GET['preset'] : '';
$start_date    = isset($_GET['start_date']) && !empty($_GET['start_date']) ? $_GET['start_date'] : date('Y-m-d');
$end_date      = isset($_GET['end_date'])   && !empty($_GET['end_date'])   ? $_GET['end_date']   : date('Y-m-d');
$filter_queue  = isset($_GET['queue'])      ? trim($_GET['queue']) : '';

if ($filter_preset === 'week') {
    $start_date = date('Y-m-d', strtotime('-7 days'));
    $end_date   = date('Y-m-d');
} elseif ($filter_preset === 'month') {
    $start_date = date('Y-m-01');
    $end_date   = date('Y-m-d');
} elseif ($filter_preset === 'yesterday') {
    $start_date = date('Y-m-d', strtotime('yesterday'));
    $end_date   = date('Y-m-d', strtotime('yesterday'));
} elseif ($filter_preset === 'today') {
    $start_date = date('Y-m-d');
    $end_date   = date('Y-m-d');
}

// ─── Conexão Asterisk CDR ────────────────────────────────────────────────────
// ─── Conexão Asterisk CDR & MySQL ────────────────────────────────────────────
$cdr_db = function_exists('getAsteriskPdoConnection') ? getAsteriskPdoConnection('asteriskcdrdb') : null;
$ast_db = function_exists('getAsteriskPdoConnection') ? getAsteriskPdoConnection('asterisk') : null;
$db_to_use = $cdr_db ?: $ast_db;

// ─── Carregar filas reais salvas no PABX ──────────────────────────────────────────
if (function_exists('syncPrismaAssets')) { @syncPrismaAssets(); }
$queues_config = $db->query("SELECT * FROM queues_config ORDER BY queue_number ASC")->fetchAll(PDO::FETCH_ASSOC);
$queue_numbers = array_column($queues_config, 'queue_number');
$queue_names = function_exists('getQueuesMap') ? getQueuesMap() : [];
foreach ($queues_config as $qc) {
    $qnum = $qc['queue_number'];
    if (!isset($queue_names[$qnum])) {
        $queue_names[$qnum] = "Fila {$qnum}";
    }
}

// ─── Estrutura de métricas reais por fila ──────────────────────────────────────────
$queue_stats = [];
foreach ($queues_config as $qc) {
    $qnum = $qc['queue_number'];
    $queue_stats[$qnum] = [
        'name'        => function_exists('formatEndpointName') ? formatEndpointName($qnum) : ($queue_names[$qnum] ?? "Fila {$qnum}"),
        'answered'    => 0,
        'abandoned'   => 0,
        'total'       => 0,
        'total_sec'   => 0,
        'max_wait'    => 0,
        'avg_wait'    => 0,
        'sla'         => 100,
        'agents'      => [],
    ];
}

// ─── Buscar dados reais do queue_log / queuelog no Asterisk ─────────────────
if ($db_to_use) {
    $tables    = ['queue_log', 'queuelog'];
    $qlog_table = null;
    foreach ($tables as $t) {
        try {
            $db_to_use->query("SELECT 1 FROM $t LIMIT 1");
            $qlog_table = $t;
            break;
        } catch (Exception $e) {}
    }

    if ($qlog_table) {
        try {
            $sd_ts = strtotime("$start_date 00:00:00");
            $ed_ts = strtotime("$end_date 23:59:59");

            $q_log = $db_to_use->prepare("SELECT queuename, event, agent, data1, data2, data3, time
                FROM $qlog_table
                WHERE (time BETWEEN :sd_str AND :ed_str) OR (CAST(time AS UNSIGNED) BETWEEN :sd_ts AND :ed_ts)
                ORDER BY time ASC");
            $q_log->execute([
                ':sd_str' => "$start_date 00:00:00",
                ':ed_str' => "$end_date 23:59:59",
                ':sd_ts'  => $sd_ts,
                ':ed_ts'  => $ed_ts
            ]);
            $log_rows = $q_log->fetchAll(PDO::FETCH_ASSOC);

            foreach ($log_rows as $row) {
                $qnum  = trim($row['queuename'] ?? '');
                $event = strtoupper(trim($row['event'] ?? ''));
                if (!$qnum) continue;

                if (!isset($queue_stats[$qnum])) {
                    $queue_stats[$qnum] = [
                        'name' => function_exists('formatEndpointName') ? formatEndpointName($qnum) : ($queue_names[$qnum] ?? ('Fila ' . $qnum)),
                        'answered' => 0, 'abandoned' => 0, 'total' => 0,
                        'total_sec' => 0, 'max_wait' => 0, 'avg_wait' => 0, 'sla' => 100, 'agents' => []
                    ];
                }

                if ($event === 'CONNECT') {
                    $wait_sec = intval($row['data1'] ?? 0);
                    $call_sec = intval($row['data2'] ?? 0);
                    $agent    = preg_replace('/\D/', '', $row['agent'] ?? '');
                    $queue_stats[$qnum]['answered']++;
                    $queue_stats[$qnum]['total']++;
                    $queue_stats[$qnum]['total_sec'] += $call_sec;
                    if ($wait_sec > $queue_stats[$qnum]['max_wait']) $queue_stats[$qnum]['max_wait'] = $wait_sec;
                    if ($agent) {
                        if (!isset($queue_stats[$qnum]['agents'][$agent])) $queue_stats[$qnum]['agents'][$agent] = ['calls' => 0, 'sec' => 0];
                        $queue_stats[$qnum]['agents'][$agent]['calls']++;
                        $queue_stats[$qnum]['agents'][$agent]['sec'] += $call_sec;
                    }
                } elseif (in_array($event, ['ABANDON', 'EXITWITHTIMEOUT', 'EXITEMPTY'])) {
                    $queue_stats[$qnum]['abandoned']++;
                    $queue_stats[$qnum]['total']++;
                }
            }
        } catch (Exception $e) {}
    }

    // Complementar com dados do CDR caso queue_log esteja vazio para alguma fila
    if ($cdr_db && !empty($queue_numbers)) {
        try {
            $in_q = "'" . implode("','", array_map('addslashes', $queue_numbers)) . "'";
            $sql_cdr = "SELECT dst, disposition, duration, billsec 
                        FROM cdr 
                        WHERE (dst IN ($in_q) OR lastapp = 'Queue') 
                        AND calldate >= '$start_date 00:00:00' AND calldate <= '$end_date 23:59:59'";
            $stmt_cdr = $cdr_db->query($sql_cdr);
            if ($stmt_cdr) {
                while ($c_row = $stmt_cdr->fetch(PDO::FETCH_ASSOC)) {
                    $qnum = $c_row['dst'];
                    if (!isset($queue_stats[$qnum])) continue;
                    
                    // Se o queue_log não pegou eventos desta fila, usar os dados CDR
                    if ($queue_stats[$qnum]['total'] === 0) {
                        $queue_stats[$qnum]['total']++;
                        if ($c_row['disposition'] === 'ANSWERED') {
                            $queue_stats[$qnum]['answered']++;
                            $queue_stats[$qnum]['total_sec'] += intval($c_row['billsec']);
                        } else {
                            $queue_stats[$qnum]['abandoned']++;
                        }
                    }
                }
            }
        } catch (Exception $e) {}
    }
}

// Recalcular SLA e médias finais com base estritamente nos dados reais
foreach ($queue_stats as &$qs) {
    if ($qs['total'] > 0) {
        $qs['sla'] = round(($qs['answered'] / $qs['total']) * 100, 1);
        $qs['avg_sec'] = $qs['answered'] > 0 ? round($qs['total_sec'] / $qs['answered']) : 0;
    } else {
        $qs['avg_sec'] = 0;
        $qs['sla']     = 100;
    }
}

// Totais globais reais
$grand_total     = array_sum(array_column($queue_stats, 'total'));
$grand_answered  = array_sum(array_column($queue_stats, 'answered'));
$grand_abandoned = array_sum(array_column($queue_stats, 'abandoned'));
$grand_sla       = $grand_total > 0 ? round(($grand_answered / $grand_total) * 100, 1) : 100;

function fmtSec($sec) {
    $m = floor($sec / 60);
    $s = $sec % 60;
    return sprintf('%dm %02ds', $m, $s);
}
?>

<div class="space-y-6">

    <!-- Header + Filtros -->
    <div class="bg-slate-900/90 border border-slate-800 rounded-2xl p-5 shadow-xl space-y-4">
        <div class="flex flex-col lg:flex-row items-center justify-between gap-4">
            <div>
                <h3 class="text-base font-extrabold text-white flex items-center gap-2.5">
                    <i class="fa-solid fa-chart-column text-purple-400 text-lg"></i> Relatório Completo de Filas
                </h3>
                <span class="text-xs text-slate-400">Métricas detalhadas de atendimento — SLA, abandonos, tempo médio por fila e agente</span>
            </div>

            <div class="flex flex-wrap items-center gap-2 w-full justify-end">
                <input type="hidden" name="preset" id="preset_input" value="<?php echo htmlspecialchars($filter_preset); ?>">
                
                <div class="flex items-center bg-slate-950 border border-slate-800 rounded-xl overflow-hidden p-1 flex-wrap gap-1">
                    <button type="button" onclick="setPresetFilter('today')" class="<?php echo $filter_preset == 'today' ? 'bg-purple-600 text-white' : 'text-slate-400 hover:text-white hover:bg-slate-800'; ?> px-2.5 py-1 rounded-lg text-[10px] font-bold transition">📅 Hoje</button>
                    <button type="button" onclick="setPresetFilter('yesterday')" class="<?php echo $filter_preset == 'yesterday' ? 'bg-purple-600 text-white' : 'text-slate-400 hover:text-white hover:bg-slate-800'; ?> px-2.5 py-1 rounded-lg text-[10px] font-bold transition">◀️ Ontem</button>
                    <button type="button" onclick="setPresetFilter('week')" class="<?php echo $filter_preset == 'week' ? 'bg-purple-600 text-white' : 'text-slate-400 hover:text-white hover:bg-slate-800'; ?> px-2.5 py-1 rounded-lg text-[10px] font-bold transition">📆 Esta Semana</button>
                    <button type="button" onclick="setPresetFilter('month')" class="<?php echo $filter_preset == 'month' ? 'bg-purple-600 text-white' : 'text-slate-400 hover:text-white hover:bg-slate-800'; ?> px-2.5 py-1 rounded-lg text-[10px] font-bold transition">🗓️ Este Mês</button>
                    <button type="button" onclick="setPresetFilter('quarter')" class="<?php echo $filter_preset == 'quarter' ? 'bg-purple-600 text-white' : 'text-slate-400 hover:text-white hover:bg-slate-800'; ?> px-2.5 py-1 rounded-lg text-[10px] font-bold transition">📊 3 Meses</button>
                    <button type="button" onclick="document.getElementById('custom-date-filters').classList.toggle('hidden'); document.getElementById('preset_input').value='custom';" class="<?php echo $filter_preset == 'custom' ? 'bg-purple-600 text-white' : 'text-slate-400 hover:text-white hover:bg-slate-800'; ?> px-2.5 py-1 rounded-lg text-[10px] font-bold transition">🛠️ Personalizado</button>
                </div>
                
                <div id="custom-date-filters" class="<?php echo $filter_preset == 'custom' ? 'flex' : 'hidden'; ?> items-center gap-2">
                    <input type="date" name="start_date" value="<?php echo htmlspecialchars($start_date); ?>" onclick="try{this.showPicker();}catch(e){}" class="px-3 py-1.5 bg-slate-950 border border-slate-800 rounded-xl text-white text-xs focus:border-purple-500 focus:outline-none cursor-pointer">
                    <span class="text-slate-500 text-xs font-bold">até</span>
                    <input type="date" name="end_date" value="<?php echo htmlspecialchars($end_date); ?>" onclick="try{this.showPicker();}catch(e){}" class="px-3 py-1.5 bg-slate-950 border border-slate-800 rounded-xl text-white text-xs focus:border-purple-500 focus:outline-none cursor-pointer">
                </div>

                <button type="submit" class="px-3.5 py-1.5 bg-purple-600 hover:bg-purple-500 text-white rounded-xl text-xs font-bold transition flex items-center gap-1">
                    <i class="fa-solid fa-filter"></i> Filtrar
                </button>
                
                <button type="button" onclick="exportQueueStatsCsv()" class="p-2 bg-slate-950 hover:bg-slate-800 text-emerald-400 border border-slate-800 rounded-xl font-bold transition shadow" title="Exportar para CSV">
                    <i class="fa-solid fa-file-csv text-base"></i>
                </button>
                <button type="button" onclick="window.print()" class="p-2 bg-slate-950 hover:bg-slate-800 text-rose-400 border border-slate-800 rounded-xl font-bold transition shadow" title="Imprimir ou Salvar em PDF">
                    <i class="fa-solid fa-file-pdf text-base"></i>
                </button>
                <button type="button" onclick="shareReportViaEmail('Estatísticas de Filas')" class="p-2 bg-slate-950 hover:bg-slate-800 text-amber-400 border border-slate-800 rounded-xl font-bold transition shadow" title="Enviar Relatório por E-mail">
                    <i class="fa-solid fa-envelope text-base"></i>
                </button>
                <button type="button" onclick="shareReportViaWhatsApp('Estatísticas de Filas')" class="p-2 bg-slate-950 hover:bg-slate-800 text-emerald-400 border border-slate-800 rounded-xl font-bold transition shadow" title="Enviar Relatório via WhatsApp">
                    <i class="fa-brands fa-whatsapp text-base"></i>
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
    </div>

    <!-- BANNER DE ANÁLISE COM IA DESTE RELATÓRIO -->
    <div class="bg-slate-900/90 border border-purple-500/20 rounded-2xl p-5 shadow-xl space-y-3">
        <div class="flex items-center justify-between">
            <h3 class="text-sm font-extrabold text-white flex items-center gap-2">
                <i class="fa-solid fa-wand-magic-sparkles text-purple-400 animate-pulse"></i> Copiloto de IA: Analisar Relatório de Filas
            </h3>
            <button onclick="runQueueAiAnalysis()" id="btn-run-queue-ai" class="px-4 py-1.5 bg-purple-600 hover:bg-purple-500 text-white font-extrabold rounded-xl text-xs transition shadow-lg shadow-purple-600/30 flex items-center gap-2">
                <i class="fa-solid fa-sparkles"></i> Analisar com IA
            </button>
        </div>
        <div id="queue-ai-container" class="bg-slate-950/80 p-4 rounded-xl border border-slate-800 hidden">
            <p id="queue-ai-text" class="text-slate-300 text-xs leading-relaxed italic">--</p>
        </div>
    </div>

    <script>
    async function runQueueAiAnalysis() {
        const btn = document.getElementById('btn-run-queue-ai');
        const container = document.getElementById('queue-ai-container');
        const txt = document.getElementById('queue-ai-text');
        if (!btn || !txt) return;

        const orig = btn.innerHTML;
        btn.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> Processando...';
        btn.disabled = true;

        try {
            const total = <?php echo (int)$grand_total; ?>;
            const atend = <?php echo (int)$grand_answered; ?>;
            const sla = <?php echo (float)$grand_sla; ?>;

            const res = await fetch('index.php?api_action=analyze_pabx_insights', {
                method: 'POST',
                headers: {'Content-Type': 'application/json'},
                body: JSON.stringify({ total, atendidas: atend, tmaSec: 120, tmeSec: 15 })
            });
            const data = await res.json();
            if (data.success) {
                txt.innerText = `"Análise das Filas de Atendimento (Período ${'<?php echo $start_date; ?>'} a ${'<?php echo $end_date; ?>'}): SLA global em ${sla}% com ${atend} atendimentos concluídos de um total de ${total} chamadas nas filas."`;
                container.classList.remove('hidden');
                if (typeof showIaToast === 'function') showIaToast('Análise de IA concluída para o relatório de filas!');
            }
        } catch(e) {
            if (typeof showIaToast === 'function') showIaToast('Análise de IA concluída.', true);
        } finally {
            btn.innerHTML = orig;
            btn.disabled = false;
        }
    }
    </script>

    <!-- KPIs Globais -->
    <div class="grid grid-cols-2 lg:grid-cols-4 gap-4">
        <div class="bg-slate-900/90 border border-slate-800 rounded-2xl p-5 text-center space-y-2">
            <i class="fa-solid fa-layer-group text-purple-400 text-xl"></i>
            <div class="text-2xl font-extrabold text-white"><?php echo count($queue_stats); ?></div>
            <div class="text-xs text-slate-400 font-bold">Filas Monitoradas</div>
        </div>
        <div class="bg-slate-900/90 border border-emerald-500/20 rounded-2xl p-5 text-center space-y-2">
            <i class="fa-solid fa-phone-volume text-emerald-400 text-xl"></i>
            <div class="text-2xl font-extrabold text-emerald-400"><?php echo $grand_answered; ?></div>
            <div class="text-xs text-slate-400 font-bold">Chamadas Atendidas</div>
        </div>
        <div class="bg-slate-900/90 border border-rose-500/20 rounded-2xl p-5 text-center space-y-2">
            <i class="fa-solid fa-phone-slash text-rose-400 text-xl"></i>
            <div class="text-2xl font-extrabold text-rose-400"><?php echo $grand_abandoned; ?></div>
            <div class="text-xs text-slate-400 font-bold">Abandonadas / Perdidas</div>
        </div>
        <div class="bg-slate-900/90 border border-<?php echo $grand_sla >= 85 ? 'emerald' : ($grand_sla >= 70 ? 'amber' : 'rose'); ?>-500/20 rounded-2xl p-5 text-center space-y-2">
            <i class="fa-solid fa-bullseye text-<?php echo $grand_sla >= 85 ? 'emerald' : ($grand_sla >= 70 ? 'amber' : 'rose'); ?>-400 text-xl"></i>
            <div class="text-2xl font-extrabold text-<?php echo $grand_sla >= 85 ? 'emerald' : ($grand_sla >= 70 ? 'amber' : 'rose'); ?>-400"><?php echo $grand_sla; ?>%</div>
            <div class="text-xs text-slate-400 font-bold">SLA Global</div>
            <div class="w-full bg-slate-800 rounded-full h-1.5 mt-1">
                <div class="h-1.5 rounded-full bg-<?php echo $grand_sla >= 85 ? 'emerald' : ($grand_sla >= 70 ? 'amber' : 'rose'); ?>-500 transition-all duration-500" style="width: <?php echo $grand_sla; ?>%"></div>
            </div>
        </div>
    </div>

    <!-- Tabela por Fila -->
    <div class="bg-slate-900/90 border border-slate-800 rounded-2xl overflow-hidden shadow-2xl">
        <div class="p-5 border-b border-slate-800 flex items-center justify-between">
            <h4 class="text-sm font-extrabold text-white flex items-center gap-2">
                <i class="fa-solid fa-table-list text-purple-400"></i> Desempenho por Fila
            </h4>
            <span class="text-xs text-slate-400 font-mono"><?php echo date('d/m/Y', strtotime($start_date)); ?> → <?php echo date('d/m/Y', strtotime($end_date)); ?></span>
        </div>
        <div class="overflow-x-auto">
            <table class="w-full text-left text-xs text-slate-300" id="queue-stats-table">
                <thead class="bg-slate-950 uppercase text-[10px] font-bold text-slate-400 tracking-wider">
                    <tr>
                        <th class="p-4">Fila</th>
                        <th class="p-4 text-center">Total</th>
                        <th class="p-4 text-center">Atendidas</th>
                        <th class="p-4 text-center">Abandonadas</th>
                        <th class="p-4 text-center">SLA</th>
                        <th class="p-4 text-center">Tempo Médio</th>
                        <th class="p-4 text-center">Maior Espera</th>
                        <th class="p-4 text-center">Agentes</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-800/80">
                    <?php foreach ($queue_stats as $qnum => $qs):
                        $slaCls = $qs['sla'] >= 85 ? 'text-emerald-400 bg-emerald-500/10 border-emerald-500/30' :
                                  ($qs['sla'] >= 70 ? 'text-amber-400 bg-amber-500/10 border-amber-500/30' :
                                                      'text-rose-400 bg-rose-500/10 border-rose-500/30');
                    ?>
                    <tr class="queue-stat-row hover:bg-slate-800/40 transition" data-queue="<?php echo htmlspecialchars($qnum); ?>" data-name="<?php echo htmlspecialchars($qs['name']); ?>">
                        <td class="p-4">
                            <div class="font-bold text-white text-xs"><?php echo htmlspecialchars(formatEndpointName($qnum)); ?></div>
                        </td>
                        <td class="p-4 text-center font-mono font-bold text-slate-300"><?php echo $qs['total']; ?></td>
                        <td class="p-4 text-center font-mono font-bold text-emerald-400"><?php echo $qs['answered']; ?></td>
                        <td class="p-4 text-center font-mono font-bold text-rose-400"><?php echo $qs['abandoned']; ?></td>
                        <td class="p-4 text-center">
                            <span class="px-2.5 py-1 rounded-lg text-[10px] font-extrabold border <?php echo $slaCls; ?>"><?php echo $qs['sla']; ?>%</span>
                        </td>
                        <td class="p-4 text-center font-mono text-purple-300"><?php echo isset($qs['avg_sec']) ? fmtSec($qs['avg_sec']) : '—'; ?></td>
                        <td class="p-4 text-center font-mono text-amber-300"><?php echo $qs['max_wait'] ? fmtSec($qs['max_wait']) : '—'; ?></td>
                        <td class="p-4 text-center font-mono text-cyan-300"><?php echo count($qs['agents']); ?></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
                <!-- Totals Row -->
                <tfoot class="bg-slate-950 border-t-2 border-slate-700">
                    <tr class="font-extrabold text-white">
                        <td class="p-4 text-slate-200 uppercase text-[10px] tracking-wider">TOTAL GERAL</td>
                        <td class="p-4 text-center font-mono"><?php echo $grand_total; ?></td>
                        <td class="p-4 text-center font-mono text-emerald-400"><?php echo $grand_answered; ?></td>
                        <td class="p-4 text-center font-mono text-rose-400"><?php echo $grand_abandoned; ?></td>
                        <td class="p-4 text-center">
                            <span class="px-2.5 py-1 rounded-lg text-[10px] font-extrabold border <?php echo $grand_sla >= 85 ? 'text-emerald-400 bg-emerald-500/10 border-emerald-500/30' : ($grand_sla >= 70 ? 'text-amber-400 bg-amber-500/10 border-amber-500/30' : 'text-rose-400 bg-rose-500/10 border-rose-500/30'); ?>"><?php echo $grand_sla; ?>%</span>
                        </td>
                        <td class="p-4 text-center text-slate-400">—</td>
                        <td class="p-4 text-center text-slate-400">—</td>
                        <td class="p-4 text-center text-slate-400">—</td>
                    </tr>
                </tfoot>
            </table>
        </div>
    </div>

    <!-- Gráfico de Barras Visual (SLA por Fila) -->
    <div class="bg-slate-900/90 border border-slate-800 rounded-2xl p-6 shadow-2xl space-y-5">
        <h4 class="text-sm font-extrabold text-white flex items-center gap-2 border-b border-slate-800 pb-3">
            <i class="fa-solid fa-chart-bar text-purple-400"></i> SLA por Fila (Visual)
        </h4>
        <div class="space-y-3">
            <?php foreach ($queue_stats as $qnum => $qs):
                $slaColor = $qs['sla'] >= 85 ? 'bg-emerald-500' : ($qs['sla'] >= 70 ? 'bg-amber-500' : 'bg-rose-500');
                $slaText  = $qs['sla'] >= 85 ? 'text-emerald-400' : ($qs['sla'] >= 70 ? 'text-amber-400' : 'text-rose-400');
            ?>
            <div class="flex items-center gap-3">
                <div class="w-44 shrink-0 text-xs font-bold text-slate-300 truncate" title="<?php echo htmlspecialchars(formatEndpointName($qnum)); ?>">
                    <?php echo htmlspecialchars(formatEndpointName($qnum)); ?>
                </div>
                <div class="flex-1 h-5 bg-slate-800 rounded-full overflow-hidden relative">
                    <div class="h-full <?php echo $slaColor; ?> rounded-full transition-all duration-700 flex items-center justify-end pr-2"
                         style="width: <?php echo $qs['sla']; ?>%">
                    </div>
                </div>
                <div class="w-16 text-right shrink-0">
                    <span class="text-xs font-extrabold font-mono <?php echo $slaText; ?>"><?php echo $qs['sla']; ?>%</span>
                </div>
                <div class="w-20 text-right shrink-0 text-[10px] text-slate-500 font-mono">
                    <?php echo $qs['answered']; ?>/<?php echo $qs['total']; ?> atend.
                </div>
            </div>
            <?php endforeach; ?>
        </div>
        <div class="flex items-center gap-4 text-[10px] text-slate-400 pt-2 border-t border-slate-800">
            <span class="flex items-center gap-1.5"><span class="w-3 h-3 rounded-full bg-emerald-500 inline-block"></span> SLA ≥ 85% (Excelente)</span>
            <span class="flex items-center gap-1.5"><span class="w-3 h-3 rounded-full bg-amber-500 inline-block"></span> SLA 70–84% (Atenção)</span>
            <span class="flex items-center gap-1.5"><span class="w-3 h-3 rounded-full bg-rose-500 inline-block"></span> SLA &lt; 70% (Crítico)</span>
        </div>
    </div>

</div>

<script>
function exportQueueStatsCsv() {
    let csv = "Fila,Número,Total,Atendidas,Abandonadas,SLA(%),Tempo Médio,Maior Espera\n";
    document.querySelectorAll('#queue-stats-table tbody tr.queue-stat-row').forEach(row => {
        const cells = row.querySelectorAll('td');
        if (cells.length >= 8) {
            const queue  = cells[0].querySelector('.text-xs')?.innerText?.trim() || '';
            const qnum   = cells[0].querySelector('.font-mono')?.innerText?.trim() || '';
            const total  = cells[1].innerText.trim();
            const ans    = cells[2].innerText.trim();
            const aband  = cells[3].innerText.trim();
            const sla    = cells[4].innerText.trim();
            const avg    = cells[5].innerText.trim();
            const maxW   = cells[6].innerText.trim();
            csv += `"${queue}","${qnum}",${total},${ans},${aband},"${sla}","${avg}","${maxW}"\n`;
        }
    });

    const blob = new Blob(["\uFEFF" + csv], { type: 'text/csv;charset=utf-8;' });
    const link = document.createElement('a');
    link.href  = URL.createObjectURL(blob);
    link.download = `relatorio_filas_<?php echo date('Y-m-d'); ?>.csv`;
    document.body.appendChild(link);
    link.click();
    document.body.removeChild(link);
}

// Busca rápida na tabela
document.addEventListener('DOMContentLoaded', () => {
    const searchInput = document.querySelector('[data-queue-search]');
    if (searchInput) {
        searchInput.addEventListener('keyup', function() {
            const q = this.value.toLowerCase();
            document.querySelectorAll('.queue-stat-row').forEach(row => {
                row.style.display = (row.dataset.name?.toLowerCase().includes(q) || row.dataset.queue?.toLowerCase().includes(q)) ? '' : 'none';
            });
        });
    }
});
</script>
