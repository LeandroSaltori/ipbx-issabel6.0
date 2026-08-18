<?php
/* vim: set expandtab tabstop=4 softtabstop=4 shiftwidth=4:
  Codificación: UTF-8
  +----------------------------------------------------------------------+
  | Issabel version 5.0 - Módulo Executivo de Relatório Gráfico          |
  | http://www.issabel.org                                               |
  +----------------------------------------------------------------------+
  | Copyright (c) 2006 Palosanto Solutions S. A.                         |
  +----------------------------------------------------------------------+
  $Id: index.php,v 20.0 2026-08-18 Prisma Telecom $ */

include_once "libs/paloSantoDB.class.php";
include_once "libs/paloSantoConfig.class.php";
require_once "libs/misc.lib.php";

function _moduleContent(&$smarty, $module_name)
{
    load_language_module($module_name);

    $date_start = isset($_REQUEST['date_start']) ? trim($_REQUEST['date_start']) : date("Y-m-d");
    $date_end   = isset($_REQUEST['date_end']) ? trim($_REQUEST['date_end']) : date("Y-m-d");
    $exten      = isset($_REQUEST['exten']) ? trim($_REQUEST['exten']) : '';
    $queue      = isset($_REQUEST['queue']) ? trim($_REQUEST['queue']) : '';

    $dsn  = generarDSNSistema('asteriskuser', 'asteriskcdrdb');
    $pDB  = new paloDB($dsn);

    // Fetch extensions list
    $extList = array();
    $rExt = $pDB->fetchTable("SELECT DISTINCT src FROM cdr WHERE CHAR_LENGTH(src) <= 5 AND src REGEXP '^[0-9]+$' ORDER BY src ASC");
    if (is_array($rExt)) {
        foreach ($rExt as $eRow) {
            if (!empty($eRow[0])) $extList[] = $eRow[0];
        }
    }

    // Fetch queues list
    $queueList = array();
    $rQ = $pDB->fetchTable("SELECT extension, descr FROM asterisk.queues_config ORDER BY extension ASC");
    if (is_array($rQ)) {
        foreach ($rQ as $qRow) {
            if (!empty($qRow[0])) $queueList[$qRow[0]] = !empty($qRow[1]) ? $qRow[1] : "Fila {$qRow[0]}";
        }
    }

    $where = array("calldate BETWEEN ? AND ?");
    $params = array($date_start . " 00:00:00", $date_end . " 23:59:59");

    if (!empty($exten)) {
        $where[] = "(src = ? OR dst = ?)";
        $params[] = $exten;
        $params[] = $exten;
    }
    if (!empty($queue)) {
        $where[] = "(accountcode = ? OR dst = ? OR dstchannel LIKE ?)";
        $params[] = $queue;
        $params[] = $queue;
        $params[] = "%/$queue-%";
    }

    $sqlWhere = "WHERE " . implode(" AND ", $where);
    $sql = "SELECT calldate, src, dst, disposition, duration, billsec, accountcode FROM cdr $sqlWhere ORDER BY calldate DESC LIMIT 10000";
    $rows = $pDB->fetchTable($sql, TRUE, $params);
    if (!is_array($rows)) $rows = array();

    $totCalls   = count($rows);
    $totIn      = 0;
    $totOut     = 0;
    $ansCount   = 0;
    $noAnsCount = 0;
    $busyCount  = 0;
    $failCount  = 0;
    $totDuration = 0;

    $hourlyIn   = array_fill(0, 24, 0);
    $hourlyOut  = array_fill(0, 24, 0);

    foreach ($rows as $r) {
        $st = strtoupper(trim($r['disposition']));
        $dur = (int)$r['duration'];
        $totDuration += $dur;

        $isOutbound = strlen(preg_replace('/[^0-9]/', '', $r['src'])) <= 5 && strlen(preg_replace('/[^0-9]/', '', $r['dst'])) > 5;
        if ($isOutbound) $totOut++;
        else $totIn++;

        if ($st == 'ANSWERED') $ansCount++;
        elseif ($st == 'NO ANSWER') $noAnsCount++;
        elseif ($st == 'BUSY') $busyCount++;
        elseif ($st == 'FAILED') $failCount++;
        else $noAnsCount++;

        if (preg_match('/(\d{2}):\d{2}:\d{2}/', $r['calldate'], $m)) {
            $h = (int)$m[1];
            if ($h >= 0 && $h <= 23) {
                if ($isOutbound) $hourlyOut[$h]++;
                else $hourlyIn[$h]++;
            }
        }
    }

    $ansPercent = $totCalls > 0 ? round(($ansCount / $totCalls) * 100, 1) : 0;
    $avgDur = $ansCount > 0 ? (int)round($totDuration / $ansCount) : 0;

    ob_start();
    ?>
    <script src="https://cdn.jsdelivr.net/npm/chart.js@3.9.1/dist/chart.min.js"></script>
    <style>
        .gr-root { font-family: 'Segoe UI', -apple-system, BlinkMacSystemFont, sans-serif; color: #1e293b; padding: 5px; }
        .gr-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 12px; }
        .gr-title h2 { margin: 0; font-size: 20px; font-weight: 800; color: #0f172a; letter-spacing: -0.3px; }
        .gr-title p { margin: 1px 0 0 0; font-size: 11px; color: #64748b; }
        .gr-top-btns { display: flex; gap: 8px; }
        .btn-top { padding: 6px 12px; border-radius: 6px; font-weight: 700; font-size: 12px; text-decoration: none; border: none; cursor: pointer; display: inline-flex; align-items: center; gap: 5px; box-shadow: 0 1px 2px rgba(0,0,0,0.05); transition: all 0.2s; }
        .btn-top:hover { transform: translateY(-1px); opacity: 0.95; }
        .btn-top-manual { background: #0284c7; color: #ffffff; }
        .btn-top-expand { background: #0d9488; color: #ffffff; }

        .filter-card-box { background: #ffffff; border-radius: 10px; padding: 12px 16px; margin-bottom: 15px; box-shadow: 0 1px 4px rgba(0,0,0,0.04); border: 1px solid #e2e8f0; }
        .filter-inline-row { display: flex; flex-wrap: wrap; gap: 10px; align-items: flex-end; }
        .filter-field-group { display: flex; flex-direction: column; flex: 1; min-width: 130px; }
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

        .charts-grid { display: grid; grid-template-columns: 2fr 1fr; gap: 15px; margin-bottom: 15px; }
        @media (max-width: 900px) { .charts-grid { grid-template-columns: 1fr; } }
        .chart-card-box { background: #ffffff; border-radius: 10px; padding: 16px 20px; box-shadow: 0 1px 4px rgba(0,0,0,0.04); border: 1px solid #e2e8f0; height: 280px; display: flex; flex-direction: column; }
        .chart-card-box h4 { margin: 0 0 10px 0; font-size: 12px; font-weight: 800; color: #334155; text-transform: uppercase; border-bottom: 1px solid #f1f5f9; padding-bottom: 4px; }
        .chart-canvas-wrapper { position: relative; flex: 1; width: 100%; height: 100%; }
    </style>

    <div class="gr-root">
        <!-- Header Principal -->
        <div class="gr-header">
            <div class="gr-title">
                <h2>Relatório Gráfico de Atendimento - IPbx Prisma</h2>
                <p>Análise estatística e visual de tráfego de ligações por período, ramal e fila</p>
            </div>
            <div class="gr-top-btns">
                <a href="modules/graphic_report/help/index.html" target="_blank" class="btn-top btn-top-manual">📖 Manual</a>
                <button onclick="window.open('?menu=<?php echo htmlspecialchars($module_name); ?>&rawmode=yes', '_blank')" class="btn-top btn-top-expand">↗ Expandir Aba</button>
            </div>
        </div>

        <!-- Filtro Compacto -->
        <div class="filter-card-box">
            <form method="GET" action="index.php">
                <input type="hidden" name="menu" value="<?php echo htmlspecialchars($module_name); ?>" />
                <div class="filter-inline-row">
                    <div class="filter-field-group" title="📅 Data Inicial do Período&#10;Selecione a data de início da análise gráfica.">
                        <label>📅 Data Inicial</label>
                        <input type="date" name="date_start" value="<?php echo htmlspecialchars($date_start); ?>" class="filter-input" />
                    </div>
                    <div class="filter-field-group" title="📅 Data Final do Período&#10;Selecione a data limite da análise.">
                        <label>📅 Data Final</label>
                        <input type="date" name="date_end" value="<?php echo htmlspecialchars($date_end); ?>" class="filter-input" />
                    </div>
                    <div class="filter-field-group" title="👤 Filtro por Ramal Específico&#10;Selecione um ramal para visualizar apenas o gráfico individual daquela extensão.">
                        <label>👤 Ramal</label>
                        <select name="exten" class="filter-input">
                            <option value="">-- Todos os Ramais --</option>
                            <?php foreach ($extList as $e): ?>
                                <option value="<?php echo htmlspecialchars($e); ?>" <?php if ($exten == $e) echo 'selected'; ?>>👤 Ramal <?php echo htmlspecialchars($e); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="filter-field-group" title="🏢 Filtro por Fila de Atendimento&#10;Selecione uma fila para analisar graficamente a distribuição de chamadas daquela fila.">
                        <label>🏢 Fila</label>
                        <select name="queue" class="filter-input">
                            <option value="">-- Todas as Filas --</option>
                            <?php foreach ($queueList as $qNum => $qName): ?>
                                <option value="<?php echo htmlspecialchars($qNum); ?>" <?php if ($queue == $qNum) echo 'selected'; ?>>🏢 <?php echo htmlspecialchars("$qNum - $qName"); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="filter-btn-row">
                        <button type="submit" class="btn-action btn-search" title="🔍 Atualizar Gráficos&#10;Recarregar os gráficos estatísticos com os filtros selecionados.">🔍 Filtrar Gráficos</button>
                        <a href="?menu=<?php echo htmlspecialchars($module_name); ?>" class="btn-action btn-reset" title="🔄 Restaurar Filtro&#10;Limpar filtros e exibir a visão geral.">🔄 Reset</a>
                    </div>
                </div>
            </form>
        </div>

        <!-- Grid de 5 Cards KPIs -->
        <div class="kpi-grid">
            <div class="kpi-card-item purple" title="📋 Total de Chamadas no Período&#10;Quantidade total de ligações processadas na consulta gráfica.">
                <div class="kpi-card-title">📋 Total de Chamadas</div>
                <div class="kpi-card-num"><?php echo number_format($totCalls, 0, ',', '.'); ?></div>
                <div class="kpi-card-desc">No período selecionado</div>
            </div>
            <div class="kpi-card-item green" title="📥 Chamadas Recebidas (Entrantes)&#10;Volume de ligações externas ou de clientes que entraram na central PBX.">
                <div class="kpi-card-title">📥 Recebidas</div>
                <div class="kpi-card-num"><?php echo number_format($totIn, 0, ',', '.'); ?></div>
                <div class="kpi-card-desc">Chamadas entrantes</div>
            </div>
            <div class="kpi-card-item blue" title="📤 Chamadas Efetuadas (Saindo)&#10;Volume de ligações realizadas para fora da central pelos atendentes.">
                <div class="kpi-card-title">📤 Efetuadas</div>
                <div class="kpi-card-num"><?php echo number_format($totOut, 0, ',', '.'); ?></div>
                <div class="kpi-card-desc">Chamadas saindo</div>
            </div>
            <div class="kpi-card-item amber" title="🎯 Taxa de Atendimento (%)&#10;Percentual de chamadas que foram atendidas com sucesso sobre o total de ligações.">
                <div class="kpi-card-title">🎯 Taxa de Atendimento</div>
                <div class="kpi-card-num"><?php echo $ansPercent; ?>%</div>
                <div class="kpi-card-desc"><?php echo $ansCount; ?> atendidas de <?php echo $totCalls; ?></div>
            </div>
            <div class="kpi-card-item slate" title="⏳ Tempo Médio de Conversa&#10;Tempo médio em minutos e segundos por ligação atendida no período.">
                <div class="kpi-card-title">⏳ Tempo Médio</div>
                <div class="kpi-card-num"><?php echo sprintf('%02d:%02d', floor($avgDur / 60), $avgDur % 60); ?></div>
                <div class="kpi-card-desc">Por chamada atendida</div>
            </div>
        </div>

        <!-- Gráficos -->
        <div class="charts-grid">
            <div class="chart-card-box" title="📊 Volume por Horário (Recebidas vs Efetuadas)&#10;Gráfico comparativo em colunas exibindo o fluxo de entrada e saída hora a hora.">
                <h4>📊 Volume de Chamadas Recebidas vs Efetuadas por Hora</h4>
                <div class="chart-canvas-wrapper">
                    <canvas id="chartGrHourly"></canvas>
                </div>
            </div>
            <div class="chart-card-box" title="🚦 Proporção de Status das Ligações&#10;Gráfico de rosca demonstrando a divisão entre Atendidas, Não Atendidas, Ocupado e Falhas.">
                <h4>🚦 Distribuição de Status das Ligações</h4>
                <div class="chart-canvas-wrapper">
                    <canvas id="chartGrStatus"></canvas>
                </div>
            </div>
        </div>
    </div>

    <script>
    document.addEventListener("DOMContentLoaded", function() {
        if (typeof Chart !== 'undefined') {
            var ctxHourly = document.getElementById('chartGrHourly').getContext('2d');
            new Chart(ctxHourly, {
                type: 'bar',
                data: {
                    labels: ['00h','01h','02h','03h','04h','05h','06h','07h','08h','09h','10h','11h','12h','13h','14h','15h','16h','17h','18h','19h','20h','21h','22h','23h'],
                    datasets: [
                        {
                            label: 'Recebidas (Entrantes)',
                            data: <?php echo json_encode($hourlyIn); ?>,
                            backgroundColor: '#10b981',
                            borderRadius: 4
                        },
                        {
                            label: 'Efetudas (Saindo)',
                            data: <?php echo json_encode($hourlyOut); ?>,
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

            var ctxStatus = document.getElementById('chartGrStatus').getContext('2d');
            new Chart(ctxStatus, {
                type: 'doughnut',
                data: {
                    labels: ['Atendidas', 'Não Atendidas', 'Ocupado', 'Falhas'],
                    datasets: [{
                        data: [<?php echo "$ansCount, $noAnsCount, $busyCount, $failCount"; ?>],
                        backgroundColor: ['#10b981', '#ef4444', '#f59e0b', '#dc2626'],
                        borderWidth: 2,
                        borderColor: '#ffffff'
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: { legend: { position: 'right' } }
                }
            });
        }
    });
    </script>
    <?php
    return ob_get_clean();
}
?>
