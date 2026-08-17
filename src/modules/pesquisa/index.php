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
  $Id: index.php,v 2.0 2026-08-17 Prisma Telecom $ */

require_once "modules/agent_console/libs/issabel2.lib.php";
include_once "libs/paloSantoDB.class.php";
include_once "libs/paloSantoConfig.class.php";
require_once "libs/misc.lib.php";

function _moduleContent(&$smarty, $module_name)
{
    include_once "libs/paloSantoGrid.class.php";
    include_once "libs/paloSantoForm.class.php";
    include_once "modules/$module_name/configs/default.conf.php";
    include_once "modules/$module_name/libs/paloSantoPesquisa.class.php";

    load_language_module($module_name);
    global $arrConf;

    $base_dir = dirname($_SERVER['SCRIPT_FILENAME']);
    $templates_dir = (isset($arrConf['templates_dir'])) ? $arrConf['templates_dir'] : 'themes';
    $local_templates_dir = "$base_dir/modules/$module_name/" . $templates_dir . '/' . $arrConf['theme'];

    $pPesquisaObj = new paloSantoPesquisa();
    $pDB = $pPesquisaObj->pdo;

    // Stream / Download de Áudio de Gravação
    if (isset($_GET['action']) && ($_GET['action'] == 'stream_audio' || $_GET['action'] == 'download_audio')) {
        handleAudioPlayback();
        exit;
    }

    // Exportação Excel
    if (isset($_GET['action']) && $_GET['action'] == 'export_excel') {
        handleExportExcel($pPesquisaObj);
        exit;
    }

    // Exportação PDF / Impressão
    if (isset($_GET['action']) && $_GET['action'] == 'export_pdf') {
        handleExportPdf($pPesquisaObj);
        exit;
    }

    $action = getAction();
    $content = reportPesquisa($smarty, $module_name, $local_templates_dir, $pPesquisaObj, $arrConf);
    return $content;
}

function handleAudioPlayback()
{
    $file = isset($_GET['file']) ? urldecode($_GET['file']) : '';
    $file = basename($file);
    if (empty($file)) {
        header("HTTP/1.1 404 Not Found");
        echo "Arquivo não especificado.";
        exit;
    }

    $possiblePaths = array(
        "/var/spool/asterisk/monitor/$file",
        "/var/spool/asterisk/monitor/" . date('Y/m/d/') . $file,
        "/var/spool/asterisk/monitor/" . date('Y/m/') . $file
    );

    $filePath = '';
    foreach ($possiblePaths as $p) {
        if (file_exists($p)) {
            $filePath = $p;
            break;
        }
    }

    if (empty($filePath)) {
        $find = shell_exec("find /var/spool/asterisk/monitor/ -name " . escapeshellarg($file) . " 2>/dev/null | head -n 1");
        $filePath = trim($find);
    }

    if (!empty($filePath) && file_exists($filePath)) {
        if ($_GET['action'] == 'download_audio') {
            header('Content-Description: File Transfer');
            header('Content-Type: application/octet-stream');
            header('Content-Disposition: attachment; filename="' . basename($filePath) . '"');
            header('Expires: 0');
            header('Cache-Control: must-revalidate');
            header('Pragma: public');
            header('Content-Length: ' . filesize($filePath));
            readfile($filePath);
            exit;
        } else {
            header('Content-Type: audio/wav');
            header('Content-Length: ' . filesize($filePath));
            readfile($filePath);
            exit;
        }
    } else {
        header("HTTP/1.1 404 Not Found");
        echo "Áudio não encontrado no servidor.";
        exit;
    }
}

function handleExportExcel($pPesquisa)
{
    $filter_field = isset($_GET['filter_field']) ? trim($_GET['filter_field']) : '';
    $filter_value = isset($_GET['filter_value']) ? trim($_GET['filter_value']) : '';
    $date_start   = isset($_GET['date_start']) ? trim($_GET['date_start']) : '';
    $date_end     = isset($_GET['date_end']) ? trim($_GET['date_end']) : '';
    $operador     = isset($_GET['operador']) ? trim($_GET['operador']) : '';
    $avaliacao    = isset($_GET['avaliacao']) ? trim($_GET['avaliacao']) : '';
    $solucao      = isset($_GET['solucao']) ? trim($_GET['solucao']) : '';

    $total = $pPesquisa->getNumPesquisa($filter_field, $filter_value, $date_start, $date_end, $operador, $avaliacao, $solucao);
    $arrResult = $pPesquisa->getPesquisa($total > 0 ? $total : 5000, 0, $filter_field, $filter_value, $date_start, $date_end, $operador, $avaliacao, $solucao);

    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename=Pesquisa_Satisfacao_' . date('Ymd_His') . '.csv');

    $output = fopen('php://output', 'w');
    // BOM UTF-8 para Excel abrir acentos perfeitamente
    fprintf($output, chr(0xEF).chr(0xBB).chr(0xBF));

    fputcsv($output, array('Operador / Ramal', 'Fila', 'Data', 'Hora', 'Telefone', 'Avaliacao', 'Problema Resolvido'), ';');

    if (is_array($arrResult)) {
        foreach ($arrResult as $row) {
            $val_operador  = !empty($row['operador']) ? $row['operador'] : (!empty($row['ramal']) ? $row['ramal'] : '-');
            $val_fila      = !empty($row['fila']) ? $row['fila'] : 'Atendimento';
            $val_data      = !empty($row['data']) ? $row['data'] : '-';
            $val_hora      = !empty($row['hora']) ? $row['hora'] : '-';
            $val_telefone  = !empty($row['telefone']) ? $row['telefone'] : (!empty($row['numero']) ? $row['numero'] : '-');
            $val_avaliacao = !empty($row['avaliacao']) ? $row['avaliacao'] : '-';
            $val_solucao   = !empty($row['solucao']) ? $row['solucao'] : '-';

            fputcsv($output, array($val_operador, $val_fila, $val_data, $val_hora, $val_telefone, $val_avaliacao, $val_solucao), ';');
        }
    }
    fclose($output);
    exit;
}

function handleExportPdf($pPesquisa)
{
    $filter_field = isset($_GET['filter_field']) ? trim($_GET['filter_field']) : '';
    $filter_value = isset($_GET['filter_value']) ? trim($_GET['filter_value']) : '';
    $date_start   = isset($_GET['date_start']) ? trim($_GET['date_start']) : '';
    $date_end     = isset($_GET['date_end']) ? trim($_GET['date_end']) : '';
    $operador     = isset($_GET['operador']) ? trim($_GET['operador']) : '';
    $avaliacao    = isset($_GET['avaliacao']) ? trim($_GET['avaliacao']) : '';
    $solucao      = isset($_GET['solucao']) ? trim($_GET['solucao']) : '';

    $total = $pPesquisa->getNumPesquisa($filter_field, $filter_value, $date_start, $date_end, $operador, $avaliacao, $solucao);
    $arrResult = $pPesquisa->getPesquisa($total > 0 ? $total : 5000, 0, $filter_field, $filter_value, $date_start, $date_end, $operador, $avaliacao, $solucao);

    ?>
    <!DOCTYPE html>
    <html lang="pt-BR">
    <head>
        <meta charset="UTF-8">
        <title>Relatório de Pesquisa de Satisfação - IPbx Prisma</title>
        <style>
            body { font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; font-size:12px; color:#1e293b; padding:20px; }
            h2 { margin:0 0 5px 0; color:#0f172a; }
            p { margin:0 0 15px 0; color:#64748b; font-size:11px; }
            table { width:100%; border-collapse:collapse; margin-top:15px; }
            th { background:#475569; color:#ffffff; padding:8px; text-align:left; font-size:11px; text-transform:uppercase; }
            td { padding:8px; border-bottom:1px solid #e2e8f0; }
            tr:nth-child(even) { background:#f8fafc; }
            .badge { padding:3px 8px; border-radius:10px; font-weight:bold; font-size:10px; color:#fff; display:inline-block; }
            .green { background:#10b981; }
            .blue { background:#3b82f6; }
            .amber { background:#f59e0b; }
            .red { background:#ef4444; }
            @media print {
                .no-print { display:none; }
            }
        </style>
    </head>
    <body onload="window.print();">
        <div class="no-print" style="margin-bottom:15px;">
            <button onclick="window.print();" style="background:#0284c7; color:#fff; border:none; padding:8px 16px; border-radius:6px; font-weight:bold; cursor:pointer;">🖨️ Imprimir / Salvar PDF</button>
        </div>
        <h2>📊 Relatório de Pesquisa de Satisfação - IPbx Prisma</h2>
        <p>Gerado em <?php echo date('d/m/Y H:i:s'); ?> | Total: <?php echo count($arrResult); ?> avaliações</p>
        
        <table>
            <thead>
                <tr>
                    <th>Operador / Ramal</th>
                    <th>Fila</th>
                    <th>Data</th>
                    <th>Hora</th>
                    <th>Telefone</th>
                    <th>Avaliação</th>
                    <th>Problema Resolvido?</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($arrResult as $row): ?>
                <tr>
                    <td><strong>👤 <?php echo htmlspecialchars(!empty($row['operador']) ? $row['operador'] : $row['ramal']); ?></strong></td>
                    <td><?php echo htmlspecialchars(!empty($row['fila']) ? $row['fila'] : 'Atendimento'); ?></td>
                    <td>📅 <?php echo htmlspecialchars($row['data']); ?></td>
                    <td>🕒 <?php echo htmlspecialchars($row['hora']); ?></td>
                    <td>📞 <?php echo htmlspecialchars(!empty($row['telefone']) ? $row['telefone'] : $row['numero']); ?></td>
                    <td><?php echo htmlspecialchars($row['avaliacao']); ?></td>
                    <td><?php echo htmlspecialchars($row['solucao']); ?></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </body>
    </html>
    <?php
    exit;
}

function reportPesquisa($smarty, $module_name, $local_templates_dir, $pPesquisa, $arrConf)
{
    // Parâmetros de Filtro
    $filter_field = isset($_POST['filter_field']) ? trim($_POST['filter_field']) : (isset($_GET['filter_field']) ? trim($_GET['filter_field']) : '');
    $filter_value = isset($_POST['filter_value']) ? trim($_POST['filter_value']) : (isset($_GET['filter_value']) ? trim($_GET['filter_value']) : '');
    $date_start   = isset($_POST['date_start']) ? trim($_POST['date_start']) : (isset($_GET['date_start']) ? trim($_GET['date_start']) : '');
    $date_end     = isset($_POST['date_end']) ? trim($_POST['date_end']) : (isset($_GET['date_end']) ? trim($_GET['date_end']) : '');
    $operador     = isset($_POST['operador']) ? trim($_POST['operador']) : (isset($_GET['operador']) ? trim($_GET['operador']) : '');
    $avaliacao    = isset($_POST['avaliacao']) ? trim($_POST['avaliacao']) : (isset($_GET['avaliacao']) ? trim($_GET['avaliacao']) : '');
    $solucao      = isset($_POST['solucao']) ? trim($_POST['solucao']) : (isset($_GET['solucao']) ? trim($_GET['solucao']) : '');

    // Estatísticas para os Cards Executivos e Gráficos
    $stats = $pPesquisa->getPesquisaStats($date_start, $date_end, $operador);

    // Configuração do Grid do Issabel
    $oGrid = new paloSantoGrid($smarty);
    $oGrid->setTitle("📊 Painel de Pesquisa de Satisfação");
    $oGrid->pagingShow(true);
    $oGrid->enableExport();
    $oGrid->setNameFile_Export("Pesquisa_Satisfacao_" . date('Ymd_His'));

    $url = array(
        "menu"         => $module_name,
        "filter_field" => $filter_field,
        "filter_value" => $filter_value,
        "date_start"   => $date_start,
        "date_end"     => $date_end,
        "operador"     => $operador,
        "avaliacao"    => $avaliacao,
        "solucao"      => $solucao
    );
    $oGrid->setURL($url);

    // Colunas (incluindo Gravação estilo Fila com Play e Baixar)
    $arrColumns = array(
        "Operador / Ramal",
        "Fila",
        "Data",
        "Hora",
        "Telefone",
        "Avaliação do Atendimento",
        "Problema Resolvido?",
        "Gravação"
    );
    $oGrid->setColumns($arrColumns);

    $total = $pPesquisa->getNumPesquisa($filter_field, $filter_value, $date_start, $date_end, $operador, $avaliacao, $solucao);
    
    // Se o filtro por data retornou 0, faz fallback automatico
    if ($total == 0 && (!empty($date_start) || !empty($filter_value))) {
        $total = $pPesquisa->getNumPesquisa('', '', '', '', '', '', '');
        $date_start = '';
        $date_end = '';
    }

    $arrData = array();

    if ($oGrid->isExportAction()) {
        $limit  = $total;
        $offset = 0;
    } else {
        $limit  = 20;
        $oGrid->setLimit($limit);
        $oGrid->setTotal($total);
        $offset = $oGrid->calculateOffset();
    }

    $arrResult = $pPesquisa->getPesquisa($limit, $offset, $filter_field, $filter_value, $date_start, $date_end, $operador, $avaliacao, $solucao);

    if (is_array($arrResult) && count($arrResult) > 0) {
        foreach ($arrResult as $key => $value) {
            $arrTmp = array();

            // Mapeamento dinâmico de colunas
            $val_operador  = !empty($value['operador']) ? $value['operador'] : (!empty($value['ramal']) ? $value['ramal'] : '-');
            $val_fila      = !empty($value['fila']) ? $value['fila'] : 'Atendimento';
            $val_data      = !empty($value['data']) ? $value['data'] : '-';
            $val_hora      = !empty($value['hora']) ? $value['hora'] : '-';
            $val_telefone  = !empty($value['telefone']) ? $value['telefone'] : (!empty($value['numero']) ? $value['numero'] : '-');
            $val_avaliacao = !empty($value['avaliacao']) ? $value['avaliacao'] : '-';
            $val_solucao   = !empty($value['solucao']) ? $value['solucao'] : '-';

            // 1. Operador
            $arrTmp[0] = "<span style='background:#ede9fe; color:#6d28d9; padding:4px 10px; border-radius:6px; font-weight:600; font-size:12px;'>👤 $val_operador</span>";

            // 2. Fila
            $arrTmp[1] = "<span style='background:#f1f5f9; color:#475569; padding:3px 8px; border-radius:4px; font-size:11px;'>$val_fila</span>";

            // 3. Data
            $arrTmp[2] = "<span style='color:#334155; font-size:12px;'>📅 $val_data</span>";

            // 4. Hora
            $arrTmp[3] = "<span style='color:#64748b; font-size:12px;'>🕒 $val_hora</span>";

            // 5. Telefone
            $arrTmp[4] = "<span style='font-weight:600; color:#1e293b;'>📞 $val_telefone</span>";

            // 6. Avaliação
            $avUpper = strtoupper(trim($val_avaliacao));
            switch ($avUpper) {
                case 'EXCELENTE':
                case 'OTIMO':
                case 'ÓTIMO':
                case '5':
                    $arrTmp[5] = "<span style='background:#10b981; color:#ffffff; padding:4px 10px; border-radius:12px; font-weight:bold; font-size:11px; display:inline-block;'>⭐⭐⭐⭐⭐ $avUpper</span>";
                    break;
                case 'MUITO BOM':
                case '4':
                    $arrTmp[5] = "<span style='background:#3b82f6; color:#ffffff; padding:4px 10px; border-radius:12px; font-weight:bold; font-size:11px; display:inline-block;'>⭐⭐⭐⭐ MUITO BOM</span>";
                    break;
                case 'MEDIO':
                case 'MÉDIO':
                case 'REGULAR':
                case 'BOM':
                case '3':
                    $arrTmp[5] = "<span style='background:#f59e0b; color:#ffffff; padding:4px 10px; border-radius:12px; font-weight:bold; font-size:11px; display:inline-block;'>⭐⭐⭐ $avUpper</span>";
                    break;
                case '2':
                    $arrTmp[5] = "<span style='background:#f97316; color:#ffffff; padding:4px 10px; border-radius:12px; font-weight:bold; font-size:11px; display:inline-block;'>⭐⭐ BOM</span>";
                    break;
                case 'RUIM':
                case 'PESSIMO':
                case 'PÉSSIMO':
                case '1':
                    $arrTmp[5] = "<span style='background:#ef4444; color:#ffffff; padding:4px 10px; border-radius:12px; font-weight:bold; font-size:11px; display:inline-block;'>⭐ $avUpper</span>";
                    break;
                default:
                    $arrTmp[5] = "<span style='background:#94a3b8; color:#ffffff; padding:4px 10px; border-radius:12px; font-weight:bold; font-size:11px; display:inline-block;'>$avUpper</span>";
                    break;
            }

            // 7. Solução
            $solUpper = strtoupper(trim($val_solucao));
            if ($solUpper == 'SIM' || $solUpper == '1') {
                $arrTmp[6] = "<span style='background:#dcfce7; color:#15803d; border:1px solid #86efac; padding:4px 10px; border-radius:8px; font-weight:bold; font-size:11px;'>✔ SIM</span>";
            } elseif ($solUpper == 'NAO' || $solUpper == 'NÃO' || $solUpper == '2') {
                $arrTmp[6] = "<span style='background:#fee2e2; color:#b91c1c; border:1px solid #fca5a5; padding:4px 10px; border-radius:8px; font-weight:bold; font-size:11px;'>✖ NÃO</span>";
            } else {
                $arrTmp[6] = "<span style='background:#f1f5f9; color:#64748b; padding:4px 10px; border-radius:8px; font-size:11px;'>$solUpper</span>";
            }

            // 8. Gravação (Busca no Asterisk CDR / Monitor)
            $recFile = $pPesquisa->findRecordingForCall($val_telefone, $val_data, $val_hora, $val_operador);
            if (!empty($recFile)) {
                $fileEnc = urlencode($recFile);
                $arrTmp[7] = "<div style='display:flex; gap:6px; align-items:center;'>
                    <button type='button' onclick=\"playPesquisaAudio('$fileEnc')\" style=\"background:#0284c7; color:#ffffff; border:none; padding:4px 10px; border-radius:14px; font-weight:700; font-size:11px; cursor:pointer;\">▶ Play</button>
                    <a href=\"?menu=pesquisa&action=download_audio&file=$fileEnc\" target=\"_blank\" style=\"background:#16a34a; color:#ffffff; padding:4px 10px; border-radius:14px; font-weight:700; font-size:11px; text-decoration:none;\">Baixar</a>
                </div>";
            } else {
                $arrTmp[7] = "<span style='color:#cbd5e1; font-size:11px;'>Sem áudio</span>";
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

    // URL com parâmetros de filtro atuais para exportação
    $queryParams = http_build_query(array(
        'menu' => 'pesquisa',
        'date_start' => $date_start,
        'date_end' => $date_end,
        'operador' => $operador,
        'avaliacao' => $avaliacao,
        'solucao' => $solucao
    ));

    ob_start();
    ?>
    <script src="https://cdn.jsdelivr.net/npm/chart.js@3.9.1/dist/chart.min.js"></script>
    <style>
        .header-action-bar {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin: 10px 0 15px 0;
        }
        .header-title-box h3 {
            margin: 0;
            font-size: 22px;
            font-weight: 800;
            color: #0f172a;
        }
        .header-title-box p {
            margin: 2px 0 0 0;
            font-size: 12px;
            color: #64748b;
        }
        .header-right-btns {
            display: flex;
            gap: 8px;
            align-items: center;
        }
        .btn-header-action {
            padding: 7px 14px;
            border-radius: 8px;
            font-weight: 700;
            font-size: 12px;
            text-decoration: none;
            border: none;
            cursor: pointer;
            display: inline-flex;
            align-items: center;
            gap: 6px;
            transition: transform 0.1s, opacity 0.2s;
        }
        .btn-header-action:hover {
            opacity: 0.9;
            transform: translateY(-1px);
        }
        .btn-expand { background: #0d9488; color: #ffffff; }
        .btn-manual { background: #0284c7; color: #ffffff; }

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
        .btn-excel {
            background: #15803d;
            color: #ffffff;
            padding: 7px 14px;
            border-radius: 6px;
            font-size: 13px;
            font-weight: 700;
            text-decoration: none;
            display: inline-block;
        }
        .btn-pdf {
            background: #b91c1c;
            color: #ffffff;
            padding: 7px 14px;
            border-radius: 6px;
            font-size: 13px;
            font-weight: 700;
            text-decoration: none;
            display: inline-block;
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

        /* Modal Player de Audio */
        #audioModalPesquisa {
            display: none;
            position: fixed;
            top: 0; left: 0; width: 100%; height: 100%;
            background: rgba(15, 23, 42, 0.6);
            backdrop-filter: blur(4px);
            z-index: 99999;
            align-items: center;
            justify-content: center;
        }
        .audio-modal-content {
            background: #ffffff;
            border-radius: 12px;
            padding: 24px;
            width: 380px;
            box-shadow: 0 20px 25px -5px rgba(0,0,0,0.2);
            text-align: center;
        }
    </style>

    <div class="header-action-bar">
        <div class="header-title-box">
            <h3>Relatório de Pesquisa - IPbx Prisma</h3>
            <p>Módulo Oficial de Satisfação pós-atendimento</p>
        </div>
        <div class="header-right-btns">
            <a href="modules/pesquisa/help/index.html" target="_blank" class="btn-header-action btn-manual">📖 Manual</a>
            <button onclick="window.open(window.location.href, '_blank')" class="btn-header-action btn-expand">↗ Expandir Aba</button>
        </div>
    </div>

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
                        <option value="EXCELENTE" <?php if ($avaliacao == 'EXCELENTE') echo 'selected'; ?>>⭐⭐⭐⭐⭐ EXCELENTE</option>
                        <option value="OTIMO" <?php if ($avaliacao == 'OTIMO') echo 'selected'; ?>>⭐⭐⭐⭐⭐ ÓTIMO</option>
                        <option value="MUITO BOM" <?php if ($avaliacao == 'MUITO BOM') echo 'selected'; ?>>⭐⭐⭐⭐ MUITO BOM</option>
                        <option value="BOM" <?php if ($avaliacao == 'BOM') echo 'selected'; ?>>⭐⭐⭐ BOM</option>
                        <option value="MEDIO" <?php if ($avaliacao == 'MEDIO') echo 'selected'; ?>>⭐⭐⭐ MÉDIO / REGULAR</option>
                        <option value="RUIM" <?php if ($avaliacao == 'RUIM') echo 'selected'; ?>>⭐ RUIM / PÉSSIMO</option>
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
                <div style="display:flex; gap:6px;">
                    <input type="submit" name="show" value="🔍 Filtrar" class="btn-filter-submit" />
                    <a href="?<?php echo $queryParams; ?>&action=export_excel" class="btn-excel">📊 Excel</a>
                    <a href="?<?php echo $queryParams; ?>&action=export_pdf" target="_blank" class="btn-pdf">📄 PDF</a>
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
            <div class="kpi-sub">Notas Excelente, Ótimo & Muito Bom</div>
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

    <!-- Modal Player de Audio -->
    <div id="audioModalPesquisa">
        <div class="audio-modal-content">
            <h4 style="margin:0 0 12px 0; color:#1e293b;">🎧 Reproduzindo Gravação</h4>
            <audio id="pesquisaAudioElement" controls style="width:100%; margin-bottom:15px;"></audio>
            <button onclick="closeAudioModal()" style="background:#64748b; color:#fff; border:none; padding:6px 16px; border-radius:6px; font-weight:bold; cursor:pointer;">Fechar</button>
        </div>
    </div>

    <script>
    function playPesquisaAudio(fileEnc) {
        var modal = document.getElementById('audioModalPesquisa');
        var audio = document.getElementById('pesquisaAudioElement');
        audio.src = '?menu=pesquisa&action=stream_audio&file=' + fileEnc;
        modal.style.display = 'flex';
        audio.play();
    }
    function closeAudioModal() {
        var modal = document.getElementById('audioModalPesquisa');
        var audio = document.getElementById('pesquisaAudioElement');
        audio.pause();
        modal.style.display = 'none';
    }

    document.addEventListener("DOMContentLoaded", function() {
        if (typeof Chart !== 'undefined') {
            // Gráfico de Notas
            var ctxNotas = document.getElementById('chartNotas').getContext('2d');
            new Chart(ctxNotas, {
                type: 'doughnut',
                data: {
                    labels: ['Excelente / Ótimo (5)', 'Muito Bom (4)', 'Bom / Regular (3)', 'Ruim (2)', 'Péssimo (1)'],
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