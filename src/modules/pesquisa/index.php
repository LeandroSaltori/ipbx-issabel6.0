<?php
/* vim: set expandtab tabstop=4 softtabstop=4 shiftwidth=4:
  Codificación: UTF-8
  +----------------------------------------------------------------------+
  | Issabel version {ISSBEL_VERSION}                                     |
  | http://www.issabel.org                                               |
  +----------------------------------------------------------------------+
  | Copyright (c) 2017 Issabel Foundation                                |
  | Copyright (c) 2006 Palosanto Solutions S. A.                         |
  +----------------------------------------------------------------------+
  | The Initial Developer of the Original Code is PaloSanto Solutions    |
  +----------------------------------------------------------------------+
  $Id: index.php,v 1.3 2026-08-17 Prisma Telecom $ */

require_once "modules/agent_console/libs/issabel2.lib.php";

function _moduleContent(&$smarty, $module_name)
{
    // include issabel framework
    include_once "libs/paloSantoGrid.class.php";
    include_once "libs/paloSantoForm.class.php";

    // include module files
    include_once "modules/$module_name/configs/default.conf.php";
    include_once "modules/$module_name/libs/paloSantoPesquisa.class.php";

    load_language_module($module_name);

    global $arrConf;

    // folder path for custom templates
    $base_dir = dirname($_SERVER['SCRIPT_FILENAME']);
    $templates_dir = (isset($arrConf['templates_dir'])) ? $arrConf['templates_dir'] : 'themes';
    $local_templates_dir = "$base_dir/modules/$module_name/" . $templates_dir . '/' . $arrConf['theme'];

    // Conexão com banco SQLite pesquisa.db com fallback
    $dbPath = !empty($arrConf['issabel_dbdir']) ? "$arrConf[issabel_dbdir]/pesquisa.db" : "/var/www/db/pesquisa.db";
    $dsn = "sqlite3:///$dbPath";
    $pDB = new paloDB($dsn);
    if (!$pDB->connStatus) {
        $pDB = new paloDB("sqlite3:////var/www/db/pesquisa.db");
    }

    $action = getAction();
    $content = "";

    switch ($action) {
        default:
            $content = reportPesquisa($smarty, $module_name, $local_templates_dir, $pDB, $arrConf);
            break;
    }
    return $content;
}

function reportPesquisa($smarty, $module_name, $local_templates_dir, &$pDB, $arrConf)
{
    $pPesquisa = new paloSantoPesquisa($pDB);

    // Parâmetros de Filtro (somente aplica se o usuário explicitamente preencher)
    $date_start = isset($_POST['date_start']) ? trim($_POST['date_start']) : (isset($_GET['date_start']) ? trim($_GET['date_start']) : '');
    $date_end   = isset($_POST['date_end']) ? trim($_POST['date_end']) : (isset($_GET['date_end']) ? trim($_GET['date_end']) : '');
    $operador   = isset($_POST['operador']) ? trim($_POST['operador']) : (isset($_GET['operador']) ? trim($_GET['operador']) : '');
    $avaliacao  = isset($_POST['avaliacao']) ? trim($_POST['avaliacao']) : (isset($_GET['avaliacao']) ? trim($_GET['avaliacao']) : '');
    $solucao    = isset($_POST['solucao']) ? trim($_POST['solucao']) : (isset($_GET['solucao']) ? trim($_GET['solucao']) : '');

    // Estatísticas para os Cards Executivos e Gráficos
    $stats = $pPesquisa->getPesquisaStats($date_start, $date_end, $operador);

    // Configuração do Grid do Issabel
    $oGrid = new paloSantoGrid($smarty);
    $oGrid->setTitle("📊 Painel Executivo de Pesquisa de Satisfação");
    $oGrid->pagingShow(true);
    $oGrid->enableExport();
    $oGrid->setNameFile_Export("Pesquisa_Satisfacao_" . date('Ymd_His'));

    $url = array(
        "menu"        => $module_name,
        "date_start"  => $date_start,
        "date_end"    => $date_end,
        "operador"    => $operador,
        "avaliacao"   => $avaliacao,
        "solucao"     => $solucao
    );
    $oGrid->setURL($url);

    $arrColumns = array(
        "Data & Hora",
        "Operador / Ramal",
        "Fila",
        "Telefone Cliente",
        "Avaliação do Atendimento",
        "Problema Resolvido?"
    );
    $oGrid->setColumns($arrColumns);

    $total = $pPesquisa->getNumPesquisa($date_start, $date_end, $operador, $avaliacao, $solucao);
    $arrData = array();

    if ($oGrid->isExportAction()) {
        $limit  = $total;
        $offset = 0;
    } else {
        $limit  = 25;
        $oGrid->setLimit($limit);
        $oGrid->setTotal($total);
        $offset = $oGrid->calculateOffset();
    }

    $arrResult = $pPesquisa->getPesquisa($limit, $offset, $date_start, $date_end, $operador, $avaliacao, $solucao);

    if (is_array($arrResult) && count($arrResult) > 0) {
        foreach ($arrResult as $key => $value) {
            $arrTmp = array();

            // Mapeamento dinâmico de colunas para suportar qualquer versão de banco
            $val_data = isset($value['data']) ? $value['data'] : (isset($value['DATA']) ? $value['DATA'] : '-');
            $val_hora = isset($value['hora']) ? $value['hora'] : (isset($value['HORA']) ? $value['HORA'] : '');
            $val_operador = isset($value['operador']) ? $value['operador'] : (isset($value['OPERADOR']) ? $value['OPERADOR'] : (isset($value['ramal']) ? $value['ramal'] : (isset($value['RAMAL']) ? $value['RAMAL'] : 'Geral')));
            $val_fila = isset($value['fila']) ? $value['fila'] : (isset($value['FILA']) ? $value['FILA'] : 'Atendimento');
            $val_telefone = isset($value['telefone']) ? $value['telefone'] : (isset($value['TELEFONE']) ? $value['TELEFONE'] : (isset($value['numero']) ? $value['numero'] : (isset($value['NUMERO']) ? $value['NUMERO'] : 'Anônimo')));
            $val_avaliacao = isset($value['avaliacao']) ? $value['avaliacao'] : (isset($value['AVALIACAO']) ? $value['AVALIACAO'] : (isset($value['nota']) ? $value['nota'] : (isset($value['NOTA']) ? $value['NOTA'] : '')));
            $val_solucao = isset($value['solucao']) ? $value['solucao'] : (isset($value['SOLUCAO']) ? $value['SOLUCAO'] : (isset($value['resolvido']) ? $value['resolvido'] : (isset($value['RESOLVIDO']) ? $value['RESOLVIDO'] : '')));

            // 1. Data e Hora
            $arrTmp[0] = "<span style='font-size:12px; color:#475569;'><i class='fa fa-calendar'></i> $val_data " . (!empty($val_hora) ? "&nbsp; <i class='fa fa-clock-o'></i> $val_hora" : "") . "</span>";

            // 2. Operador / Ramal
            $arrTmp[1] = "<span style='background:#ede9fe; color:#6d28d9; padding:4px 10px; border-radius:6px; font-weight:600; font-size:12px;'>👤 $val_operador</span>";

            // 3. Fila
            $arrTmp[2] = "<span style='background:#f1f5f9; color:#475569; padding:3px 8px; border-radius:4px; font-size:11px;'>$val_fila</span>";

            // 4. Telefone
            $arrTmp[3] = "<span style='font-weight:600; color:#1e293b;'><i class='fa fa-phone'></i> $val_telefone</span>";

            // 5. Avaliação com Badges Modernas e Estrelas
            $avUpper = strtoupper(trim($val_avaliacao));
            switch ($avUpper) {
                case 'OTIMO':
                case 'ÓTIMO':
                case '5':
                    $arrTmp[4] = "<span style='background:#10b981; color:#ffffff; padding:4px 10px; border-radius:12px; font-weight:bold; font-size:11px; display:inline-block;'>⭐⭐⭐⭐⭐ ÓTIMO</span>";
                    break;
                case 'MUITO BOM':
                case '4':
                    $arrTmp[4] = "<span style='background:#3b82f6; color:#ffffff; padding:4px 10px; border-radius:12px; font-weight:bold; font-size:11px; display:inline-block;'>⭐⭐⭐⭐ MUITO BOM</span>";
                    break;
                case 'MEDIO':
                case 'MÉDIO':
                case '3':
                    $arrTmp[4] = "<span style='background:#f59e0b; color:#ffffff; padding:4px 10px; border-radius:12px; font-weight:bold; font-size:11px; display:inline-block;'>⭐⭐⭐ MÉDIO</span>";
                    break;
                case 'BOM':
                case '2':
                    $arrTmp[4] = "<span style='background:#f97316; color:#ffffff; padding:4px 10px; border-radius:12px; font-weight:bold; font-size:11px; display:inline-block;'>⭐⭐ BOM</span>";
                    break;
                case 'RUIM':
                case '1':
                    $arrTmp[4] = "<span style='background:#ef4444; color:#ffffff; padding:4px 10px; border-radius:12px; font-weight:bold; font-size:11px; display:inline-block;'>⭐ RUIM</span>";
                    break;
                default:
                    $arrTmp[4] = "<span style='background:#94a3b8; color:#ffffff; padding:4px 10px; border-radius:12px; font-weight:bold; font-size:11px; display:inline-block;'>" . (!empty($avUpper) ? $avUpper : '-') . "</span>";
                    break;
            }

            // 6. Solução
            $solUpper = strtoupper(trim($val_solucao));
            if ($solUpper == 'SIM' || $solUpper == '1') {
                $arrTmp[5] = "<span style='background:#dcfce7; color:#15803d; border:1px solid #86efac; padding:4px 10px; border-radius:8px; font-weight:bold; font-size:11px;'>✔ SIM</span>";
            } elseif ($solUpper == 'NAO' || $solUpper == 'NÃO' || $solUpper == '2') {
                $arrTmp[5] = "<span style='background:#fee2e2; color:#b91c1c; border:1px solid #fca5a5; padding:4px 10px; border-radius:8px; font-weight:bold; font-size:11px;'>✖ NÃO</span>";
            } else {
                $arrTmp[5] = "<span style='background:#f1f5f9; color:#64748b; padding:4px 10px; border-radius:8px; font-size:11px;'>" . (!empty($solUpper) ? $solUpper : '-') . "</span>";
            }

            $arrData[] = $arrTmp;
        }
    }
    $oGrid->setData($arrData);

    // Bloco Superior: Filtro Topo + Indicadores (Cards + Gráficos)
    $htmlHeader = renderTopFilterAndDashboard($stats, $date_start, $date_end, $operador, $avaliacao, $solucao);

    $content = $htmlHeader . $oGrid->fetchGrid();
    return $content;
}

function renderTopFilterAndDashboard($stats, $date_start, $date_end, $operador, $avaliacao, $solucao)
{
    $total     = isset($stats['total']) ? $stats['total'] : 0;
    $media     = isset($stats['media_estrelas']) ? $stats['media_estrelas'] : 0;
    $resolucao = isset($stats['taxa_resolucao']) ? $stats['taxa_resolucao'] : 0;
    $satisfacao = isset($stats['taxa_satisfacao']) ? $stats['taxa_satisfacao'] : 0;

    $otimo = isset($stats['otimo']) ? (int)$stats['otimo'] : 0;
    $muito_bom = isset($stats['muito_bom']) ? (int)$stats['muito_bom'] : 0;
    $medio = isset($stats['medio']) ? (int)$stats['medio'] : 0;
    $bom = isset($stats['bom']) ? (int)$stats['bom'] : 0;
    $ruim = isset($stats['ruim']) ? (int)$stats['ruim'] : 0;
    $sim = isset($stats['resolvido_sim']) ? (int)$stats['resolvido_sim'] : 0;
    $nao = isset($stats['resolvido_nao']) ? (int)$stats['resolvido_nao'] : 0;

    ob_start();
    ?>
    <script src="https://cdn.jsdelivr.net/npm/chart.js@3.9.1/dist/chart.min.js"></script>
    <style>
        .filter-top-bar {
            background: #ffffff;
            border-radius: 10px;
            padding: 16px 20px;
            margin: 10px 0 15px 0;
            box-shadow: 0 1px 4px rgba(0,0,0,0.06);
            border: 1px solid #e2e8f0;
        }
        .filter-form-row {
            display: flex;
            flex-wrap: wrap;
            gap: 12px;
            align-items: flex-end;
        }
        .filter-group {
            display: flex;
            flex-direction: column;
        }
        .filter-group label {
            font-size: 11px;
            font-weight: 700;
            color: #475569;
            text-transform: uppercase;
            letter-spacing: 0.3px;
            margin-bottom: 4px;
        }
        .filter-control {
            padding: 6px 10px;
            border: 1px solid #cbd5e1;
            border-radius: 6px;
            font-size: 13px;
            color: #1e293b;
            background: #fff;
            outline: none;
            transition: border-color 0.2s;
        }
        .filter-control:focus {
            border-color: #6366f1;
        }
        .btn-filter-submit {
            background: #4f46e5;
            color: #ffffff;
            border: none;
            padding: 7px 16px;
            border-radius: 6px;
            font-weight: 600;
            cursor: pointer;
            font-size: 13px;
            transition: background 0.2s;
        }
        .btn-filter-submit:hover {
            background: #4338ca;
        }
        .btn-filter-clear {
            background: #f1f5f9;
            color: #475569;
            text-decoration: none;
            padding: 7px 14px;
            border-radius: 6px;
            font-size: 13px;
            font-weight: 600;
            border: 1px solid #cbd5e1;
            display: inline-block;
            transition: background 0.2s;
        }
        .btn-filter-clear:hover {
            background: #e2e8f0;
        }

        .pesquisa-dashboard {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
            gap: 15px;
            margin: 15px 0 20px 0;
        }
        .kpi-card {
            background: #ffffff;
            border-radius: 10px;
            padding: 18px 20px;
            box-shadow: 0 2px 4px rgba(0,0,0,0.06), 0 1px 2px rgba(0,0,0,0.04);
            border-left: 5px solid #6366f1;
            display: flex;
            flex-direction: column;
            justify-content: space-between;
        }
        .kpi-card.green { border-left-color: #10b981; }
        .kpi-card.purple { border-left-color: #8b5cf6; }
        .kpi-card.amber { border-left-color: #f59e0b; }
        .kpi-card.blue { border-left-color: #3b82f6; }
        
        .kpi-title {
            font-size: 12px;
            font-weight: 700;
            color: #64748b;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            margin-bottom: 6px;
        }
        .kpi-value {
            font-size: 26px;
            font-weight: 800;
            color: #1e293b;
            line-height: 1;
        }
        .kpi-sub {
            font-size: 12px;
            color: #94a3b8;
            margin-top: 6px;
        }
        .charts-container {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 20px;
            margin-bottom: 25px;
        }
        @media (max-width: 900px) {
            .charts-container { grid-template-columns: 1fr; }
        }
        .chart-box {
            background: #ffffff;
            border-radius: 10px;
            padding: 20px;
            box-shadow: 0 2px 4px rgba(0,0,0,0.06);
            height: 280px;
            display: flex;
            flex-direction: column;
            border: 1px solid #f1f5f9;
        }
        .chart-box h4 {
            margin: 0 0 15px 0;
            font-size: 14px;
            color: #334155;
            font-weight: 700;
            border-bottom: 1px solid #f1f5f9;
            padding-bottom: 8px;
            text-transform: uppercase;
        }
        .chart-wrapper {
            position: relative;
            flex: 1;
            width: 100%;
            height: 100%;
        }
    </style>

    <form method="POST" action="?menu=pesquisa">
        <div class="filter-top-bar">
            <div class="filter-form-row">
                <div class="filter-group">
                    <label>📅 Data Inicial</label>
                    <input type="date" name="date_start" value="<?php echo htmlspecialchars($date_start); ?>" class="filter-control" />
                </div>
                <div class="filter-group">
                    <label>📅 Data Final</label>
                    <input type="date" name="date_end" value="<?php echo htmlspecialchars($date_end); ?>" class="filter-control" />
                </div>
                <div class="filter-group">
                    <label>👤 Operador / Ramal</label>
                    <input type="text" name="operador" placeholder="Ex: 1001" value="<?php echo htmlspecialchars($operador); ?>" class="filter-control" style="width:130px;" />
                </div>
                <div class="filter-group">
                    <label>⭐ Avaliação</label>
                    <select name="avaliacao" class="filter-control">
                        <option value="">-- Todas as Notas --</option>
                        <option value="OTIMO" <?php if ($avaliacao == 'OTIMO') echo 'selected'; ?>>⭐⭐⭐⭐⭐ ÓTIMO</option>
                        <option value="MUITO BOM" <?php if ($avaliacao == 'MUITO BOM') echo 'selected'; ?>>⭐⭐⭐⭐ MUITO BOM</option>
                        <option value="MEDIO" <?php if ($avaliacao == 'MEDIO') echo 'selected'; ?>>⭐⭐⭐ MÉDIO</option>
                        <option value="BOM" <?php if ($avaliacao == 'BOM') echo 'selected'; ?>>⭐⭐ BOM</option>
                        <option value="RUIM" <?php if ($avaliacao == 'RUIM') echo 'selected'; ?>>⭐ RUIM</option>
                    </select>
                </div>
                <div class="filter-group">
                    <label>🎯 Resolução</label>
                    <select name="solucao" class="filter-control">
                        <option value="">-- Todas --</option>
                        <option value="SIM" <?php if ($solucao == 'SIM') echo 'selected'; ?>>✔ SIM (Resolvido)</option>
                        <option value="NAO" <?php if ($solucao == 'NAO') echo 'selected'; ?>>✖ NÃO (Não Resolvido)</option>
                    </select>
                </div>
                <div>
                    <input type="submit" name="show" value="🔍 Filtrar" class="btn-filter-submit" />
                    <a href="?menu=pesquisa" class="btn-filter-clear">🔄 Ver Todos</a>
                </div>
            </div>
        </div>
    </form>

    <div class="pesquisa-dashboard">
        <div class="kpi-card purple">
            <div class="kpi-title">📋 Total de Avaliações</div>
            <div class="kpi-value"><?php echo number_format($total, 0, ',', '.'); ?></div>
            <div class="kpi-sub">Pesquisas registradas</div>
        </div>
        <div class="kpi-card green">
            <div class="kpi-title">⭐ Média de Satisfação</div>
            <div class="kpi-value"><?php echo $media; ?> <span style="font-size:16px; color:#f59e0b;">/ 5.0</span></div>
            <div class="kpi-sub">Índice Geral de Atendimento</div>
        </div>
        <div class="kpi-card blue">
            <div class="kpi-title">🎯 Taxa de Resolução</div>
            <div class="kpi-value"><?php echo $resolucao; ?>%</div>
            <div class="kpi-sub"><?php echo $sim; ?> resolvidos de <?php echo ($sim + $nao); ?></div>
        </div>
        <div class="kpi-card amber">
            <div class="kpi-title">🏆 Satisfação Positiva</div>
            <div class="kpi-value"><?php echo $satisfacao; ?>%</div>
            <div class="kpi-sub">Notas Ótimo & Muito Bom</div>
        </div>
    </div>

    <div class="charts-container">
        <div class="chart-box">
            <h4>📊 Distribuição das Notas</h4>
            <div class="chart-wrapper">
                <canvas id="chartNotas"></canvas>
            </div>
        </div>
        <div class="chart-box">
            <h4>🎯 Resolução de Problemas</h4>
            <div class="chart-wrapper">
                <canvas id="chartSolucao"></canvas>
            </div>
        </div>
    </div>

    <script>
    document.addEventListener("DOMContentLoaded", function() {
        if (typeof Chart !== 'undefined') {
            // Gráfico de Notas
            var ctxNotas = document.getElementById('chartNotas').getContext('2d');
            new Chart(ctxNotas, {
                type: 'doughnut',
                data: {
                    labels: ['Ótimo (5)', 'Muito Bom (4)', 'Médio (3)', 'Bom (2)', 'Ruim (1)'],
                    datasets: [{
                        data: [<?php echo "$otimo, $muito_bom, $medio, $bom, $ruim"; ?>],
                        backgroundColor: ['#10b981', '#3b82f6', '#f59e0b', '#f97316', '#ef4444'],
                        borderWidth: 2,
                        borderColor: '#ffffff'
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: {
                        legend: { position: 'right' }
                    }
                }
            });

            // Gráfico de Solução
            var ctxSolucao = document.getElementById('chartSolucao').getContext('2d');
            new Chart(ctxSolucao, {
                type: 'doughnut',
                data: {
                    labels: ['Sim (Resolvido)', 'Não (Não Resolvido)'],
                    datasets: [{
                        data: [<?php echo "$sim, $nao"; ?>],
                        backgroundColor: ['#10b981', '#ef4444'],
                        borderWidth: 2,
                        borderColor: '#ffffff'
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: {
                        legend: { position: 'right' }
                    }
                }
            });
        }
    });
    </script>
    <?php
    return ob_get_clean();
}

function getAction()
{
    if (getParameter("save_new")) return "save_new";
    else if (getParameter("save_edit")) return "save_edit";
    else if (getParameter("delete")) return "delete";
    else if (getParameter("new_open")) return "view_form";
    else if (getParameter("action") == "view") return "view_form";
    else if (getParameter("action") == "view_edit") return "view_form";
    else return "report";
}
?>