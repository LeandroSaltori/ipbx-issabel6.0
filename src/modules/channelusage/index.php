<?php
/* vim: set expandtab tabstop=4 softtabstop=4 shiftwidth=4:
  Codificación: UTF-8
  +----------------------------------------------------------------------+
  | Issabel version 5.0 - Módulo Executivo de Uso de Canais              |
  | http://www.issabel.org                                               |
  +----------------------------------------------------------------------+
  | Copyright (c) 2021 Issabel Foundation                                |
  +----------------------------------------------------------------------+
  $Id: index.php,v 20.0 2026-08-18 Prisma Telecom $ */

include_once "libs/paloSantoGraphImage.lib.php";
include_once "modules/$module_name/configs/default.conf.php";
include_once "modules/$module_name/libs/paloSantoChannelUsage.class.php";

function _moduleContent(&$smarty, $module_name)
{
    load_language_module($module_name);

    $chUsage = new paloSantoChannelUsage();
    
    // Fetch channels data
    // 2 - Total, 3 - DAHDI, 4 - SIP, 5 - IAX, 6 - H323, 7 - Local
    $dataTotal = $chUsage->channelsUsage(2);
    $dataDahdi = $chUsage->channelsUsage(3);
    $dataSIP   = $chUsage->channelsUsage(4);
    $dataIAX   = $chUsage->channelsUsage(5);
    $dataLocal = $chUsage->channelsUsage(7);

    $hoursLabels = array();
    $valuesTotal = array();
    $valuesDahdi = array();
    $valuesSIP   = array();
    $valuesIAX   = array();
    $valuesLocal = array();

    if (isset($dataTotal['DATA']['DAT_1']['VALUES']) && is_array($dataTotal['DATA']['DAT_1']['VALUES'])) {
        foreach ($dataTotal['DATA']['DAT_1']['VALUES'] as $ts => $val) {
            $hoursLabels[] = date('H:i', $ts);
            $valuesTotal[] = (int)$val;
        }
    }

    if (isset($dataSIP['DATA']['DAT_1']['VALUES']) && is_array($dataSIP['DATA']['DAT_1']['VALUES'])) {
        foreach ($dataSIP['DATA']['DAT_1']['VALUES'] as $ts => $val) {
            $valuesSIP[] = (int)$val;
        }
    }

    if (isset($dataDahdi['DATA']['DAT_1']['VALUES']) && is_array($dataDahdi['DATA']['DAT_1']['VALUES'])) {
        foreach ($dataDahdi['DATA']['DAT_1']['VALUES'] as $ts => $val) {
            $valuesDahdi[] = (int)$val;
        }
    }

    if (isset($dataIAX['DATA']['DAT_1']['VALUES']) && is_array($dataIAX['DATA']['DAT_1']['VALUES'])) {
        foreach ($dataIAX['DATA']['DAT_1']['VALUES'] as $ts => $val) {
            $valuesIAX[] = (int)$val;
        }
    }

    if (isset($dataLocal['DATA']['DAT_1']['VALUES']) && is_array($dataLocal['DATA']['DAT_1']['VALUES'])) {
        foreach ($dataLocal['DATA']['DAT_1']['VALUES'] as $ts => $val) {
            $valuesLocal[] = (int)$val;
        }
    }

    $maxTotal = !empty($valuesTotal) ? max($valuesTotal) : 0;
    $maxSIP   = !empty($valuesSIP) ? max($valuesSIP) : 0;
    $maxDahdi = !empty($valuesDahdi) ? max($valuesDahdi) : 0;
    $maxIAX   = !empty($valuesIAX) ? max($valuesIAX) : 0;
    $avgTotal = !empty($valuesTotal) ? round(array_sum($valuesTotal) / count($valuesTotal), 1) : 0;

    ob_start();
    ?>
    <script src="https://cdn.jsdelivr.net/npm/chart.js@3.9.1/dist/chart.min.js"></script>
    <style>
        .ch-root {
            font-family: 'Segoe UI', -apple-system, BlinkMacSystemFont, sans-serif;
            color: #1e293b;
            padding: 5px;
        }
        .ch-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 12px;
        }
        .ch-title h2 {
            margin: 0;
            font-size: 20px;
            font-weight: 800;
            color: #0f172a;
            letter-spacing: -0.3px;
        }
        .ch-title p {
            margin: 1px 0 0 0;
            font-size: 11px;
            color: #64748b;
        }
        .ch-top-btns { display: flex; gap: 8px; }
        .btn-top {
            padding: 6px 12px;
            border-radius: 6px;
            font-weight: 700;
            font-size: 12px;
            text-decoration: none;
            border: none;
            cursor: pointer;
            display: inline-flex;
            align-items: center;
            gap: 5px;
            box-shadow: 0 1px 2px rgba(0,0,0,0.05);
            transition: all 0.2s;
        }
        .btn-top:hover { transform: translateY(-1px); opacity: 0.95; }
        .btn-top-manual { background: #0284c7; color: #ffffff; }
        .btn-top-expand { background: #0d9488; color: #ffffff; }

        .kpi-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
            gap: 12px;
            margin-bottom: 15px;
        }
        .kpi-card-item {
            background: #ffffff;
            border-radius: 10px;
            padding: 14px 16px;
            box-shadow: 0 1px 4px rgba(0,0,0,0.04);
            border: 1px solid #f1f5f9;
            border-left: 5px solid #6366f1;
            display: flex;
            flex-direction: column;
            justify-content: space-between;
        }
        .kpi-card-item.purple { border-left-color: #8b5cf6; }
        .kpi-card-item.green { border-left-color: #10b981; }
        .kpi-card-item.blue { border-left-color: #3b82f6; }
        .kpi-card-item.amber { border-left-color: #f59e0b; }
        .kpi-card-item.slate { border-left-color: #64748b; }

        .kpi-card-title {
            font-size: 10px;
            font-weight: 800;
            color: #64748b;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            margin-bottom: 4px;
        }
        .kpi-card-num {
            font-size: 24px;
            font-weight: 800;
            color: #0f172a;
            line-height: 1;
        }
        .kpi-card-desc {
            font-size: 11px;
            color: #94a3b8;
            margin-top: 4px;
        }

        .chart-card-box {
            background: #ffffff;
            border-radius: 10px;
            padding: 16px 20px;
            box-shadow: 0 1px 4px rgba(0,0,0,0.04);
            border: 1px solid #e2e8f0;
            height: 360px;
            display: flex;
            flex-direction: column;
            margin-bottom: 15px;
        }
        .chart-card-box h4 {
            margin: 0 0 12px 0;
            font-size: 13px;
            font-weight: 800;
            color: #334155;
            text-transform: uppercase;
            border-bottom: 1px solid #f1f5f9;
            padding-bottom: 6px;
        }
        .chart-canvas-wrapper {
            position: relative;
            flex: 1;
            width: 100%;
            height: 100%;
        }
    </style>

    <div class="ch-root">
        <!-- Header Principal -->
        <div class="ch-header">
            <div class="ch-title">
                <h2>Uso de Canais de Atendimento - IPbx Prisma</h2>
                <p>Monitoramento de chamadas simultâneas e ocupação de canais por protocolo (Últimas 24 Horas)</p>
            </div>
            <div class="ch-top-btns">
                <a href="modules/channelusage/help/index.html" target="_blank" class="btn-top btn-top-manual">📖 Manual</a>
                <button onclick="window.open('?menu=<?php echo htmlspecialchars($module_name); ?>&rawmode=yes', '_blank')" class="btn-top btn-top-expand">↗ Expandir Aba</button>
            </div>
        </div>

        <!-- Grid de 5 Cards KPIs -->
        <div class="kpi-grid">
            <div class="kpi-card-item purple">
                <div class="kpi-card-title">📞 Pico Simultâneo</div>
                <div class="kpi-card-num"><?php echo $maxTotal; ?> <span style="font-size:13px; color:#64748b;">canais</span></div>
                <div class="kpi-card-desc">Máximo nas últimas 24h</div>
            </div>
            <div class="kpi-card-item green">
                <div class="kpi-card-title">🌐 Troncos SIP / PJSIP</div>
                <div class="kpi-card-num"><?php echo $maxSIP; ?> <span style="font-size:13px; color:#64748b;">pico</span></div>
                <div class="kpi-card-desc">Canais IP ativos</div>
            </div>
            <div class="kpi-card-item blue">
                <div class="kpi-card-title">📟 Canais DAHDI / E1</div>
                <div class="kpi-card-num"><?php echo $maxDahdi; ?> <span style="font-size:13px; color:#64748b;">pico</span></div>
                <div class="kpi-card-desc">Canais digitais/analógicos</div>
            </div>
            <div class="kpi-card-item amber">
                <div class="kpi-card-title">🔗 Troncos IAX</div>
                <div class="kpi-card-num"><?php echo $maxIAX; ?> <span style="font-size:13px; color:#64748b;">pico</span></div>
                <div class="kpi-card-desc">Interconexões Asterisk</div>
            </div>
            <div class="kpi-card-item slate">
                <div class="kpi-card-title">📊 Média de Uso</div>
                <div class="kpi-card-num"><?php echo $avgTotal; ?> <span style="font-size:13px; color:#64748b;">canais</span></div>
                <div class="kpi-card-desc">Ocupação média contínua</div>
            </div>
        </div>

        <!-- Gráfico Principal -->
        <div class="chart-card-box">
            <h4>📈 Evolução do Consumo de Canais Simultâneos (Últimas 24 Horas)</h4>
            <div class="chart-canvas-wrapper">
                <canvas id="chartChannelsUsage"></canvas>
            </div>
        </div>
    </div>

    <script>
    document.addEventListener("DOMContentLoaded", function() {
        if (typeof Chart !== 'undefined') {
            var ctx = document.getElementById('chartChannelsUsage').getContext('2d');
            new Chart(ctx, {
                type: 'line',
                data: {
                    labels: <?php echo json_encode($hoursLabels); ?>,
                    datasets: [
                        {
                            label: 'Total Simultâneas',
                            data: <?php echo json_encode($valuesTotal); ?>,
                            borderColor: '#6366f1',
                            backgroundColor: 'rgba(99, 102, 241, 0.1)',
                            fill: true,
                            tension: 0.3,
                            borderWidth: 3
                        },
                        {
                            label: 'SIP / PJSIP',
                            data: <?php echo json_encode($valuesSIP); ?>,
                            borderColor: '#10b981',
                            borderWidth: 2,
                            tension: 0.3
                        },
                        {
                            label: 'DAHDI / E1',
                            data: <?php echo json_encode($valuesDahdi); ?>,
                            borderColor: '#3b82f6',
                            borderWidth: 2,
                            tension: 0.3
                        },
                        {
                            label: 'IAX',
                            data: <?php echo json_encode($valuesIAX); ?>,
                            borderColor: '#f59e0b',
                            borderWidth: 2,
                            tension: 0.3
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
