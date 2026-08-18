<?php
/* vim: set expandtab tabstop=4 softtabstop=4 shiftwidth=4:
  Codificación: UTF-8
  +----------------------------------------------------------------------+
  | Issabel version 5.0 - Módulo Executivo de Resumo por Ramal           |
  | http://www.issabel.org                                               |
  +----------------------------------------------------------------------+
  | Copyright (c) 2006 Palosanto Solutions S. A.                         |
  +----------------------------------------------------------------------+
  $Id: index.php,v 20.0 2026-08-18 Prisma Telecom $ */

include_once "libs/paloSantoDB.class.php";
include_once "libs/paloSantoConfig.class.php";
require_once "libs/misc.lib.php";

function formatSecsSum($sec) {
    $sec = (int)$sec;
    if ($sec <= 0) return '00:00';
    $m = floor($sec / 60);
    $s = $sec % 60;
    if ($m >= 60) {
        $h = floor($m / 60);
        $m = $m % 60;
        return sprintf('%02dh %02dm', $h, $m);
    }
    return sprintf('%02d:%02d', $m, $s);
}

function _moduleContent(&$smarty, $module_name)
{
    load_language_module($module_name);

    include_once "modules/$module_name/configs/default.conf.php";
    include_once "modules/$module_name/libs/paloSantoReportCall.class.php";

    $dsn  = generarDSNSistema('asteriskuser', 'asteriskcdrdb');
    $pDB  = new paloDB($dsn);
    $pReportCall = new paloSantoReportCall($pDB);

    $date_from = isset($_REQUEST['date_from']) ? trim($_REQUEST['date_from']) : date("Y-m-d");
    $date_to   = isset($_REQUEST['date_to']) ? trim($_REQUEST['date_to']) : date("Y-m-d");
    $search    = isset($_REQUEST['search']) ? trim($_REQUEST['search']) : '';

    $date_ini = $date_from . " 00:00:00";
    $date_end = $date_to . " 23:59:59";

    // Fetch summary per extension
    $type = !empty($search) ? (is_numeric($search) ? 'Ext' : 'User') : NULL;
    $rawReport = $pReportCall->ObtainReportCall(1000, 0, $date_ini, $date_end, $type, $search, 1, "asc");
    if (!is_array($rawReport)) $rawReport = array();

    $totalDevices   = count($rawReport);
    $totalInCalls   = 0;
    $totalOutCalls  = 0;
    $totalInDur     = 0;
    $totalOutDur    = 0;

    $chartLabels    = array();
    $chartTotal     = array();
    $chartDur       = array();

    foreach ($rawReport as $k => $r) {
        $inC  = (int)$r['num_incoming_call'];
        $outC = (int)$r['num_outgoing_call'];
        $inD  = (int)$r['duration_incoming_call'];
        $outD = (int)$r['duration_outgoing_call'];

        $rawReport[$k]['total_calls'] = $inC + $outC;
        $rawReport[$k]['total_dur']   = $inD + $outD;

        $totalInCalls  += $inC;
        $totalOutCalls += $outC;
        $totalInDur    += $inD;
        $totalOutDur   += $outD;
    }

    // Sort by total calls descending for top charts
    $sortedByCalls = $rawReport;
    usort($sortedByCalls, function($a, $b) { return $b['total_calls'] - $a['total_calls']; });

    $top10Calls = array_slice($sortedByCalls, 0, 10);
    $top10Labels = array();
    $top10In     = array();
    $top10Out    = array();

    foreach ($top10Calls as $tc) {
        $name = !empty($tc['user_name']) ? "{$tc['extension']} ({$tc['user_name']})" : "Ramal {$tc['extension']}";
        $top10Labels[] = $name;
        $top10In[]     = (int)$tc['num_incoming_call'];
        $top10Out[]    = (int)$tc['num_outgoing_call'];
    }

    ob_start();
    ?>
    <script src="https://cdn.jsdelivr.net/npm/chart.js@3.9.1/dist/chart.min.js"></script>
    <style>
        .sum-root { font-family: 'Segoe UI', -apple-system, BlinkMacSystemFont, sans-serif; color: #1e293b; padding: 5px; }
        .sum-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 12px; }
        .sum-title h2 { margin: 0; font-size: 20px; font-weight: 800; color: #0f172a; letter-spacing: -0.3px; }
        .sum-title p { margin: 1px 0 0 0; font-size: 11px; color: #64748b; }
        .sum-top-btns { display: flex; gap: 8px; }
        .btn-top { padding: 6px 12px; border-radius: 6px; font-weight: 700; font-size: 12px; text-decoration: none; border: none; cursor: pointer; display: inline-flex; align-items: center; gap: 5px; box-shadow: 0 1px 2px rgba(0,0,0,0.05); transition: all 0.2s; }
        .btn-top:hover { transform: translateY(-1px); opacity: 0.95; }
        .btn-top-manual { background: #0284c7; color: #ffffff; }
        .btn-top-expand { background: #0d9488; color: #ffffff; }

        .filter-card-box { background: #ffffff; border-radius: 10px; padding: 12px 16px; margin-bottom: 15px; box-shadow: 0 1px 4px rgba(0,0,0,0.04); border: 1px solid #e2e8f0; }
        .filter-inline-row { display: flex; flex-wrap: wrap; gap: 10px; align-items: flex-end; }
        .filter-field-group { display: flex; flex-direction: column; flex: 1; min-width: 140px; }
        .filter-field-group label { font-size: 10px; font-weight: 700; color: #475569; text-transform: uppercase; letter-spacing: 0.4px; margin-bottom: 3px; }
        .filter-input { padding: 6px 10px; border: 1px solid #cbd5e1; border-radius: 6px; font-size: 12px; color: #0f172a; background: #ffffff; outline: none; height: 32px; box-sizing: border-box; transition: border-color 0.2s; }
        .filter-input:focus { border-color: #6366f1; }
        .filter-btn-row { display: flex; gap: 6px; align-items: center; }
        .btn-action { height: 32px; padding: 0 14px; border-radius: 6px; font-weight: 700; font-size: 12px; border: none; cursor: pointer; text-decoration: none; display: inline-flex; align-items: center; justify-content: center; gap: 5px; box-sizing: border-box; transition: all 0.2s; }
        .btn-action:hover { opacity: 0.9; }
        .btn-search { background: #4f46e5; color: #ffffff; }
        .btn-reset { background: #f1f5f9; color: #475569; border: 1px solid #cbd5e1; }

        .kpi-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(170px, 1fr)); gap: 12px; margin-bottom: 15px; }
        .kpi-card-item { background: #ffffff; border-radius: 10px; padding: 14px 16px; box-shadow: 0 1px 4px rgba(0,0,0,0.04); border: 1px solid #f1f5f9; border-left: 5px solid #6366f1; display: flex; flex-direction: column; justify-content: space-between; }
        .kpi-card-item.purple { border-left-color: #8b5cf6; }
        .kpi-card-item.green { border-left-color: #10b981; }
        .kpi-card-item.blue { border-left-color: #3b82f6; }
        .kpi-card-item.amber { border-left-color: #f59e0b; }
        .kpi-card-item.slate { border-left-color: #64748b; }
        .kpi-card-title { font-size: 10px; font-weight: 800; color: #64748b; text-transform: uppercase; letter-spacing: 0.5px; margin-bottom: 4px; }
        .kpi-card-num { font-size: 24px; font-weight: 800; color: #0f172a; line-height: 1; }
        .kpi-card-desc { font-size: 11px; color: #94a3b8; margin-top: 4px; }

        .chart-card-box { background: #ffffff; border-radius: 10px; padding: 16px 20px; box-shadow: 0 1px 4px rgba(0,0,0,0.04); border: 1px solid #e2e8f0; height: 260px; display: flex; flex-direction: column; margin-bottom: 15px; }
        .chart-card-box h4 { margin: 0 0 10px 0; font-size: 12px; font-weight: 800; color: #334155; text-transform: uppercase; border-bottom: 1px solid #f1f5f9; padding-bottom: 4px; }
        .chart-canvas-wrapper { position: relative; flex: 1; width: 100%; height: 100%; }

        .table-card-box { background: #ffffff; border-radius: 10px; box-shadow: 0 1px 4px rgba(0,0,0,0.04); border: 1px solid #e2e8f0; overflow: hidden; }
        .sum-table { width: 100%; border-collapse: collapse; text-align: left; }
        .sum-table thead { background: #334155; color: #ffffff; }
        .sum-table th { padding: 10px 14px; font-size: 10px; font-weight: 800; text-transform: uppercase; letter-spacing: 0.5px; }
        .sum-table td { padding: 10px 14px; border-bottom: 1px solid #f1f5f9; font-size: 12px; vertical-align: middle; }
        .sum-table tbody tr:hover { background: #f8fafc; }
    </style>

    <div class="sum-root">
        <!-- Header Principal -->
        <div class="sum-header">
            <div class="sum-title">
                <h2>Resumo de Chamadas por Ramal - IPbx Prisma</h2>
                <p>Consolidado de tráfego, volume de ligações entrantes/efetuadas e tempo de conversa por ramal</p>
            </div>
            <div class="sum-top-btns">
                <a href="modules/summary_by_extension/help/index.html" target="_blank" class="btn-top btn-top-manual">📖 Manual</a>
                <button onclick="window.open('?menu=<?php echo htmlspecialchars($module_name); ?>&rawmode=yes', '_blank')" class="btn-top btn-top-expand">↗ Expandir Aba</button>
            </div>
        </div>

        <!-- Filtro Compacto -->
        <div class="filter-card-box">
            <form method="GET" action="index.php">
                <input type="hidden" name="menu" value="<?php echo htmlspecialchars($module_name); ?>" />
                <div class="filter-inline-row">
                    <div class="filter-field-group">
                        <label>📅 Data Inicial</label>
                        <input type="date" name="date_from" value="<?php echo htmlspecialchars($date_from); ?>" class="filter-input" />
                    </div>
                    <div class="filter-field-group">
                        <label>📅 Data Final</label>
                        <input type="date" name="date_to" value="<?php echo htmlspecialchars($date_to); ?>" class="filter-input" />
                    </div>
                    <div class="filter-field-group">
                        <label>🔍 Buscar Ramal / Nome</label>
                        <input type="text" name="search" value="<?php echo htmlspecialchars($search); ?>" placeholder="Ex: 1001 ou Fulano..." class="filter-input" />
                    </div>
                    <div class="filter-btn-row">
                        <button type="submit" class="btn-action btn-search">🔍 Filtrar Resumo</button>
                        <a href="?menu=<?php echo htmlspecialchars($module_name); ?>" class="btn-action btn-reset">🔄 Reset</a>
                    </div>
                </div>
            </form>
        </div>

        <!-- Grid de 5 Cards KPIs -->
        <div class="kpi-grid">
            <div class="kpi-card-item purple">
                <div class="kpi-card-title">👥 Total de Ramais</div>
                <div class="kpi-card-num"><?php echo $totalDevices; ?></div>
                <div class="kpi-card-desc">Ramais ativos com atividade</div>
            </div>
            <div class="kpi-card-item green">
                <div class="kpi-card-title">📥 Chamadas Recebidas</div>
                <div class="kpi-card-num"><?php echo number_format($totalInCalls, 0, ',', '.'); ?></div>
                <div class="kpi-card-desc">Tempo: <?php echo formatSecsSum($totalInDur); ?></div>
            </div>
            <div class="kpi-card-item blue">
                <div class="kpi-card-title">📤 Chamadas Efetuadas</div>
                <div class="kpi-card-num"><?php echo number_format($totalOutCalls, 0, ',', '.'); ?></div>
                <div class="kpi-card-desc">Tempo: <?php echo formatSecsSum($totalOutDur); ?></div>
            </div>
            <div class="kpi-card-item amber">
                <div class="kpi-card-title">📋 Total de Ligações</div>
                <div class="kpi-card-num"><?php echo number_format($totalInCalls + $totalOutCalls, 0, ',', '.'); ?></div>
                <div class="kpi-card-desc">No período selecionado</div>
            </div>
            <div class="kpi-card-item slate">
                <div class="kpi-card-title">⏱️ Duração Total</div>
                <div class="kpi-card-num"><?php echo formatSecsSum($totalInDur + $totalOutDur); ?></div>
                <div class="kpi-card-desc">Tempo acumulado geral</div>
            </div>
        </div>

        <!-- Gráfico Top 10 -->
        <div class="chart-card-box">
            <h4>📊 Top 10 Ramais com Maior Volume de Ligações (Recebidas vs Efetuadas)</h4>
            <div class="chart-canvas-wrapper">
                <canvas id="chartSumTop10"></canvas>
            </div>
        </div>

        <!-- Tabela Resumo -->
        <div class="table-card-box">
            <table class="sum-table">
                <thead>
                    <tr>
                        <th>Ramal</th>
                        <th>Atendente / Nome</th>
                        <th>Recebidas (Qtd)</th>
                        <th>Tempo Recebidas</th>
                        <th>Efetuadas (Qtd)</th>
                        <th>Tempo Efetuadas</th>
                        <th>Total Ligações</th>
                        <th>Tempo Total</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (count($rawReport) > 0): ?>
                        <?php foreach ($rawReport as $r): ?>
                            <tr>
                                <td><span style="background:#e0e7ff; color:#4338ca; padding:3px 8px; border-radius:6px; font-weight:700; font-size:11px;">👤 <?php echo htmlspecialchars($r['extension']); ?></span></td>
                                <td><span style="font-weight:600; color:#0f172a;"><?php echo htmlspecialchars(!empty($r['user_name']) ? $r['user_name'] : '-'); ?></span></td>
                                <td><span style="color:#166534; font-weight:700;"><?php echo number_format($r['num_incoming_call'], 0, ',', '.'); ?></span></td>
                                <td><span style="color:#64748b; font-size:11px;">⏱️ <?php echo formatSecsSum($r['duration_incoming_call']); ?></span></td>
                                <td><span style="color:#1e40af; font-weight:700;"><?php echo number_format($r['num_outgoing_call'], 0, ',', '.'); ?></span></td>
                                <td><span style="color:#64748b; font-size:11px;">⏱️ <?php echo formatSecsSum($r['duration_outgoing_call']); ?></span></td>
                                <td><span style="color:#0f172a; font-weight:800;"><?php echo number_format($r['total_calls'], 0, ',', '.'); ?></span></td>
                                <td><span style="color:#334155; font-weight:700; font-size:11px;">⏱️ <?php echo formatSecsSum($r['total_dur']); ?></span></td>
                            </tr>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <tr>
                            <td colspan="8" style="text-align:center; padding:25px; color:#64748b;">
                                🚀 Nenhum registro encontrado para os filtros selecionados.
                            </td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>

    <script>
    document.addEventListener("DOMContentLoaded", function() {
        if (typeof Chart !== 'undefined') {
            var ctx = document.getElementById('chartSumTop10').getContext('2d');
            new Chart(ctx, {
                type: 'bar',
                data: {
                    labels: <?php echo json_encode($top10Labels); ?>,
                    datasets: [
                        {
                            label: 'Recebidas',
                            data: <?php echo json_encode($top10In); ?>,
                            backgroundColor: '#10b981',
                            borderRadius: 4
                        },
                        {
                            label: 'Efetuadas',
                            data: <?php echo json_encode($top10Out); ?>,
                            backgroundColor: '#3b82f6',
                            borderRadius: 4
                        }
                    ]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: { legend: { position: 'top' } },
                    scales: {
                        y: { beginAtZero: true, grid: { color: '#f1f5f9' } },
                        x: { grid: { display: false } }
                    }
                }
            });
        }
    });
    </script>
    <?php
    return ob_get_clean();
}
?>
