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
  $Id: index.php,v 6.0 2026-08-17 Prisma Telecom $ */

require_once "modules/agent_console/libs/issabel2.lib.php";
include_once "libs/paloSantoDB.class.php";
include_once "libs/paloSantoConfig.class.php";
require_once "libs/misc.lib.php";

function _moduleContent(&$smarty, $module_name)
{
    include_once "modules/$module_name/configs/default.conf.php";
    include_once "modules/$module_name/libs/paloSantoPesquisa.class.php";

    load_language_module($module_name);
    global $arrConf;

    $pPesquisaObj = new paloSantoPesquisa();

    // Handler de Áudio (Stream / Download)
    if (isset($_GET['action']) && ($_GET['action'] == 'stream_audio' || $_GET['action'] == 'download_audio')) {
        handleAudioPlayback();
        exit;
    }

    // Handler de Exportação Excel
    if (isset($_GET['action']) && $_GET['action'] == 'export_excel') {
        handleExportExcel($pPesquisaObj);
        exit;
    }

    // Handler de Exportação PDF
    if (isset($_GET['action']) && $_GET['action'] == 'export_pdf') {
        handleExportPdf($pPesquisaObj);
        exit;
    }

    return renderFullExecutiveDashboard($pPesquisaObj, $module_name);
}

function handleAudioPlayback()
{
    while (ob_get_level()) {
        ob_end_clean();
    }

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
        $ext = strtolower(pathinfo($filePath, PATHINFO_EXTENSION));
        $mime = 'audio/wav';
        if ($ext == 'mp3') $mime = 'audio/mpeg';
        if ($ext == 'gsm') $mime = 'audio/x-gsm';

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
            header('Content-Type: ' . $mime);
            header('Content-Length: ' . filesize($filePath));
            header('Accept-Ranges: bytes');
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
    while (ob_get_level()) {
        ob_end_clean();
    }

    $date_start = isset($_REQUEST['date_start']) ? trim($_REQUEST['date_start']) : '';
    $date_end   = isset($_REQUEST['date_end']) ? trim($_REQUEST['date_end']) : '';
    $operador   = isset($_REQUEST['operador']) ? trim($_REQUEST['operador']) : '';
    $avaliacao  = isset($_REQUEST['avaliacao']) ? trim($_REQUEST['avaliacao']) : '';
    $solucao    = isset($_REQUEST['solucao']) ? trim($_REQUEST['solucao']) : '';

    $total = $pPesquisa->getNumPesquisa('', '', $date_start, $date_end, $operador, $avaliacao, $solucao);
    $arrResult = $pPesquisa->getPesquisa($total > 0 ? $total : 5000, 0, '', '', $date_start, $date_end, $operador, $avaliacao, $solucao);

    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename=Pesquisa_Satisfacao_' . date('Ymd_His') . '.csv');

    $output = fopen('php://output', 'w');
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
    while (ob_get_level()) {
        ob_end_clean();
    }

    $date_start = isset($_REQUEST['date_start']) ? trim($_REQUEST['date_start']) : '';
    $date_end   = isset($_REQUEST['date_end']) ? trim($_REQUEST['date_end']) : '';
    $operador   = isset($_REQUEST['operador']) ? trim($_REQUEST['operador']) : '';
    $avaliacao  = isset($_REQUEST['avaliacao']) ? trim($_REQUEST['avaliacao']) : '';
    $solucao    = isset($_REQUEST['solucao']) ? trim($_REQUEST['solucao']) : '';

    $total = $pPesquisa->getNumPesquisa('', '', $date_start, $date_end, $operador, $avaliacao, $solucao);
    $arrResult = $pPesquisa->getPesquisa($total > 0 ? $total : 5000, 0, '', '', $date_start, $date_end, $operador, $avaliacao, $solucao);

    ?>
    <!DOCTYPE html>
    <html lang="pt-BR">
    <head>
        <meta charset="UTF-8">
        <title>Relatório de Pesquisa de Satisfação - IPbx Prisma</title>
        <style>
            body { font-family: 'Segoe UI', sans-serif; font-size:12px; color:#1e293b; padding:20px; }
            h2 { margin:0 0 5px 0; color:#0f172a; }
            p { margin:0 0 15px 0; color:#64748b; font-size:11px; }
            table { width:100%; border-collapse:collapse; margin-top:15px; }
            th { background:#334155; color:#ffffff; padding:8px; text-align:left; font-size:11px; text-transform:uppercase; }
            td { padding:8px; border-bottom:1px solid #e2e8f0; }
            tr:nth-child(even) { background:#f8fafc; }
            @media print { .no-print { display:none; } }
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

function renderFullExecutiveDashboard($pPesquisa, $module_name)
{
    // Captura Parâmetros de Filtro
    $date_start = isset($_REQUEST['date_start']) ? trim($_REQUEST['date_start']) : '';
    $date_end   = isset($_REQUEST['date_end']) ? trim($_REQUEST['date_end']) : '';
    $operador   = isset($_REQUEST['operador']) ? trim($_REQUEST['operador']) : '';
    $avaliacao  = isset($_REQUEST['avaliacao']) ? trim($_REQUEST['avaliacao']) : '';
    $solucao    = isset($_REQUEST['solucao']) ? trim($_REQUEST['solucao']) : '';

    // Paginação
    $page  = isset($_REQUEST['page']) ? max(1, (int)$_REQUEST['page']) : 1;
    $limit = 20;
    $offset = ($page - 1) * $limit;

    // Estatísticas dos Cards e Gráficos
    $stats = $pPesquisa->getPesquisaStats($date_start, $date_end, $operador);

    // Lista de Operadores Únicos
    $operadoresList = $pPesquisa->getOperadoresList();

    // Registros Filtrados
    $total = $pPesquisa->getNumPesquisa('', '', $date_start, $date_end, $operador, $avaliacao, $solucao);
    $arrResult = $pPesquisa->getPesquisa($limit, $offset, '', '', $date_start, $date_end, $operador, $avaliacao, $solucao);

    $totalPages = max(1, ceil($total / $limit));

    // URLs de Exportação
    $exportParams = http_build_query(array(
        'menu' => $module_name,
        'rawmode' => 'yes',
        'date_start' => $date_start,
        'date_end' => $date_end,
        'operador' => $operador,
        'avaliacao' => $avaliacao,
        'solucao' => $solucao
    ));

    $totalCount  = isset($stats['total']) ? $stats['total'] : 0;
    $media       = isset($stats['media_estrelas']) ? $stats['media_estrelas'] : 0;
    $resolucao   = isset($stats['taxa_resolucao']) ? $stats['taxa_resolucao'] : 0;
    $satisfacao  = isset($stats['taxa_satisfacao']) ? $stats['taxa_satisfacao'] : 0;
    $nao_avaliou = isset($stats['nao_avaliou']) ? (int)$stats['nao_avaliou'] : 0;

    $otimo     = isset($stats['otimo']) ? (int)$stats['otimo'] : 0;
    $muito_bom = isset($stats['muito_bom']) ? (int)$stats['muito_bom'] : 0;
    $medio     = isset($stats['medio']) ? (int)$stats['medio'] : 0;
    $bom       = isset($stats['bom']) ? (int)$stats['bom'] : 0;
    $ruim      = isset($stats['ruim']) ? (int)$stats['ruim'] : 0;
    $sim       = isset($stats['resolvido_sim']) ? (int)$stats['resolvido_sim'] : 0;
    $nao       = isset($stats['resolvido_nao']) ? (int)$stats['resolvido_nao'] : 0;

    ob_start();
    ?>
    <script src="https://cdn.jsdelivr.net/npm/chart.js@3.9.1/dist/chart.min.js"></script>
    <style>
        .pesquisa-root {
            font-family: 'Segoe UI', -apple-system, BlinkMacSystemFont, sans-serif;
            color: #1e293b;
            padding: 5px;
        }

        /* Compact Header */
        .pesquisa-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 12px;
        }
        .pesquisa-title h2 {
            margin: 0;
            font-size: 20px;
            font-weight: 800;
            color: #0f172a;
            letter-spacing: -0.3px;
        }
        .pesquisa-title p {
            margin: 1px 0 0 0;
            font-size: 11px;
            color: #64748b;
        }
        .pesquisa-top-btns {
            display: flex;
            gap: 8px;
        }
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

        /* Single Line Compact Filter Box */
        .filter-card-box {
            background: #ffffff;
            border-radius: 10px;
            padding: 12px 16px;
            margin-bottom: 15px;
            box-shadow: 0 1px 4px rgba(0,0,0,0.04);
            border: 1px solid #e2e8f0;
        }
        .filter-inline-row {
            display: flex;
            flex-wrap: wrap;
            gap: 10px;
            align-items: flex-end;
        }
        .filter-field-group {
            display: flex;
            flex-direction: column;
            flex: 1;
            min-width: 130px;
        }
        .filter-field-group label {
            font-size: 10px;
            font-weight: 700;
            color: #475569;
            text-transform: uppercase;
            letter-spacing: 0.4px;
            margin-bottom: 3px;
        }
        .filter-input {
            padding: 6px 10px;
            border: 1px solid #cbd5e1;
            border-radius: 6px;
            font-size: 12px;
            color: #0f172a;
            background: #ffffff;
            outline: none;
            height: 32px;
            box-sizing: border-box;
            transition: border-color 0.2s;
        }
        .filter-input:focus { border-color: #6366f1; }
        .filter-btn-row {
            display: flex;
            gap: 6px;
            align-items: center;
        }
        .btn-action {
            height: 32px;
            padding: 0 14px;
            border-radius: 6px;
            font-weight: 700;
            font-size: 12px;
            border: none;
            cursor: pointer;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 5px;
            box-sizing: border-box;
            transition: all 0.2s;
        }
        .btn-action:hover { opacity: 0.9; }
        .btn-search { background: #4f46e5; color: #ffffff; }
        .btn-excel { background: #16a34a; color: #ffffff; }
        .btn-pdf { background: #dc2626; color: #ffffff; }
        .btn-reset { background: #f1f5f9; color: #475569; border: 1px solid #cbd5e1; }

        /* KPI Cards Grid (5 Cards) */
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

        /* Compact Charts Grid */
        .charts-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 15px;
            margin-bottom: 15px;
        }
        @media (max-width: 900px) { .charts-grid { grid-template-columns: 1fr; } }

        .chart-card-box {
            background: #ffffff;
            border-radius: 10px;
            padding: 14px 16px;
            box-shadow: 0 1px 4px rgba(0,0,0,0.04);
            border: 1px solid #f1f5f9;
            height: 220px;
            display: flex;
            flex-direction: column;
        }
        .chart-card-box h4 {
            margin: 0 0 8px 0;
            font-size: 12px;
            font-weight: 800;
            color: #334155;
            text-transform: uppercase;
            border-bottom: 1px solid #f1f5f9;
            padding-bottom: 4px;
        }
        .chart-canvas-wrapper {
            position: relative;
            flex: 1;
            width: 100%;
            height: 100%;
        }

        /* Custom Modern Table */
        .table-card-box {
            background: #ffffff;
            border-radius: 10px;
            box-shadow: 0 1px 4px rgba(0,0,0,0.04);
            border: 1px solid #e2e8f0;
            overflow: hidden;
        }
        .pesquisa-table {
            width: 100%;
            border-collapse: collapse;
            text-align: left;
        }
        .pesquisa-table thead {
            background: #334155;
            color: #ffffff;
        }
        .pesquisa-table th {
            padding: 10px 14px;
            font-size: 10px;
            font-weight: 800;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }
        .pesquisa-table td {
            padding: 10px 14px;
            border-bottom: 1px solid #f1f5f9;
            font-size: 12px;
            vertical-align: middle;
        }
        .pesquisa-table tbody tr:hover {
            background: #f8fafc;
        }

        /* Pagination Bar */
        .pagination-bar {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 12px 16px;
            background: #ffffff;
            border-top: 1px solid #f1f5f9;
        }
        .pagination-info {
            font-size: 12px;
            color: #64748b;
            font-weight: 600;
        }
        .pagination-btns {
            display: flex;
            gap: 6px;
        }
        .page-link-btn {
            padding: 5px 12px;
            border-radius: 6px;
            background: #f1f5f9;
            color: #334155;
            font-size: 12px;
            font-weight: 700;
            text-decoration: none;
            border: 1px solid #cbd5e1;
            transition: background 0.2s;
        }
        .page-link-btn:hover { background: #e2e8f0; }
        .page-link-btn.disabled { opacity: 0.5; pointer-events: none; }

        /* Audio Modal */
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

    <div class="pesquisa-root">
        <!-- Header Principal -->
        <div class="pesquisa-header">
            <div class="pesquisa-title">
                <h2>Relatório de Pesquisa de Satisfação - IPbx Prisma</h2>
                <p>Módulo Executivo Oficial de Pesquisa pós-atendimento (Disque / Transfira para <strong>8996</strong>)</p>
            </div>
            <div class="pesquisa-top-btns">
                <a href="modules/pesquisa/help/index.html" target="_blank" class="btn-top btn-top-manual">📖 Manual</a>
                <button onclick="window.open('?menu=<?php echo htmlspecialchars($module_name); ?>&rawmode=yes', '_blank')" class="btn-top btn-top-expand">↗ Expandir Aba</button>
            </div>
        </div>

        <!-- Card de Filtros Compacto de 1 Linha -->
        <div class="filter-card-box">
            <form method="GET" action="index.php">
                <input type="hidden" name="menu" value="<?php echo htmlspecialchars($module_name); ?>" />
                <div class="filter-inline-row">
                    <div class="filter-field-group">
                        <label>📅 Data Inicial</label>
                        <input type="date" name="date_start" value="<?php echo htmlspecialchars($date_start); ?>" class="filter-input" />
                    </div>
                    <div class="filter-field-group">
                        <label>📅 Data Final</label>
                        <input type="date" name="date_end" value="<?php echo htmlspecialchars($date_end); ?>" class="filter-input" />
                    </div>
                    <div class="filter-field-group">
                        <label>👤 Operador / Ramal</label>
                        <select name="operador" class="filter-input">
                            <option value="">-- Todos os Operadores --</option>
                            <?php if (is_array($operadoresList)): ?>
                                <?php foreach ($operadoresList as $op): ?>
                                    <option value="<?php echo htmlspecialchars($op); ?>" <?php if ($operador == $op) echo 'selected'; ?>>👤 Ramal / Agent <?php echo htmlspecialchars($op); ?></option>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </select>
                    </div>
                    <div class="filter-field-group">
                        <label>⭐ Avaliação</label>
                        <select name="avaliacao" class="filter-input">
                            <option value="">-- Todas as Notas --</option>
                            <option value="EXCELENTE" <?php if ($avaliacao == 'EXCELENTE') echo 'selected'; ?>>⭐⭐⭐⭐⭐ EXCELENTE</option>
                            <option value="OTIMO" <?php if ($avaliacao == 'OTIMO') echo 'selected'; ?>>⭐⭐⭐⭐⭐ ÓTIMO</option>
                            <option value="MUITO BOM" <?php if ($avaliacao == 'MUITO BOM') echo 'selected'; ?>>⭐⭐⭐⭐ MUITO BOM</option>
                            <option value="BOM" <?php if ($avaliacao == 'BOM') echo 'selected'; ?>>⭐⭐⭐ BOM</option>
                            <option value="MEDIO" <?php if ($avaliacao == 'MEDIO') echo 'selected'; ?>>⭐⭐⭐ MÉDIO / REGULAR</option>
                            <option value="RUIM" <?php if ($avaliacao == 'RUIM') echo 'selected'; ?>>⭐ RUIM / PÉSSIMO</option>
                            <option value="NAO AVALIOU" <?php if ($avaliacao == 'NAO AVALIOU') echo 'selected'; ?>>📵 NÃO AVALIOU / DESISTIU</option>
                        </select>
                    </div>
                    <div class="filter-field-group">
                        <label>🎯 Resolução</label>
                        <select name="solucao" class="filter-input">
                            <option value="">-- Todas --</option>
                            <option value="SIM" <?php if ($solucao == 'SIM') echo 'selected'; ?>>✔ SIM (Resolvido)</option>
                            <option value="NAO" <?php if ($solucao == 'NAO') echo 'selected'; ?>>✖ NÃO (Não Resolvido)</option>
                        </select>
                    </div>
                    <div class="filter-btn-row">
                        <button type="submit" class="btn-action btn-search">🔍 Filtrar</button>
                        <a href="?<?php echo $exportParams; ?>&action=export_excel" class="btn-action btn-excel">📊 Excel</a>
                        <a href="?<?php echo $exportParams; ?>&action=export_pdf" target="_blank" class="btn-action btn-pdf">📄 PDF</a>
                        <a href="?menu=<?php echo htmlspecialchars($module_name); ?>" class="btn-action btn-reset">🔄 Ver Todos</a>
                    </div>
                </div>
            </form>
        </div>

        <!-- Grid de 5 Cards KPIs Executivos -->
        <div class="kpi-grid">
            <div class="kpi-card-item purple">
                <div class="kpi-card-title">📋 Total de Chamadas</div>
                <div class="kpi-card-num"><?php echo number_format($totalCount, 0, ',', '.'); ?></div>
                <div class="kpi-card-desc">Transferidas para Pesquisa</div>
            </div>
            <div class="kpi-card-item green">
                <div class="kpi-card-title">⭐ Média de Satisfação</div>
                <div class="kpi-card-num"><?php echo $media; ?> <span style="font-size:14px; color:#f59e0b;">/ 5.0</span></div>
                <div class="kpi-card-desc">Índice dos Avaliados</div>
            </div>
            <div class="kpi-card-item blue">
                <div class="kpi-card-title">🎯 Taxa de Resolução</div>
                <div class="kpi-card-num"><?php echo $resolucao; ?>%</div>
                <div class="kpi-card-desc"><?php echo $sim; ?> resolvidos de <?php echo ($sim + $nao); ?></div>
            </div>
            <div class="kpi-card-item amber">
                <div class="kpi-card-title">🏆 Satisfação Positiva</div>
                <div class="kpi-card-num"><?php echo $satisfacao; ?>%</div>
                <div class="kpi-card-desc">Notas Excelente, Ótimo & Muito Bom</div>
            </div>
            <div class="kpi-card-item slate">
                <div class="kpi-card-title">📵 Desligou sem Avaliar</div>
                <div class="kpi-card-num"><?php echo number_format($nao_avaliou, 0, ',', '.'); ?></div>
                <div class="kpi-card-desc"><?php echo $totalCount > 0 ? round(($nao_avaliou / $totalCount) * 100, 1) : 0; ?>% das chamadas</div>
            </div>
        </div>

        <!-- Grid de Gráficos -->
        <div class="charts-grid">
            <div class="chart-card-box">
                <h4>📊 Distribuição das Notas</h4>
                <div class="chart-canvas-wrapper">
                    <canvas id="chartNotasCustom"></canvas>
                </div>
            </div>
            <div class="chart-card-box">
                <h4>🎯 Resolução de Problemas</h4>
                <div class="chart-canvas-wrapper">
                    <canvas id="chartSolucaoCustom"></canvas>
                </div>
            </div>
        </div>

        <!-- Tabela Customizada Moderna -->
        <div class="table-card-box">
            <table class="pesquisa-table">
                <thead>
                    <tr>
                        <th>Operador / Ramal</th>
                        <th>Fila</th>
                        <th>Data</th>
                        <th>Hora</th>
                        <th>Telefone</th>
                        <th>Avaliação do Atendimento</th>
                        <th>Problema Resolvido?</th>
                        <th>Gravação</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (is_array($arrResult) && count($arrResult) > 0): ?>
                        <?php foreach ($arrResult as $row): ?>
                            <?php
                            $val_operador  = !empty($row['operador']) ? $row['operador'] : (!empty($row['ramal']) ? $row['ramal'] : '-');
                            $val_fila      = !empty($row['fila']) ? $row['fila'] : 'Atendimento';
                            $val_data      = !empty($row['data']) ? $row['data'] : '-';
                            $val_hora      = !empty($row['hora']) ? $row['hora'] : '-';
                            $val_telefone  = !empty($row['telefone']) ? $row['telefone'] : (!empty($row['numero']) ? $row['numero'] : '-');
                            $val_avaliacao = !empty($row['avaliacao']) ? $row['avaliacao'] : '-';
                            $val_solucao   = !empty($row['solucao']) ? $row['solucao'] : '-';
                            $recFile       = $pPesquisa->findRecordingForCall($val_telefone, $val_data, $val_hora, $val_operador);
                            ?>
                            <tr>
                                <td><span style='background:#ede9fe; color:#6d28d9; padding:3px 8px; border-radius:6px; font-weight:600; font-size:11px;'>👤 <?php echo htmlspecialchars($val_operador); ?></span></td>
                                <td><span style='background:#f1f5f9; color:#475569; padding:2px 6px; border-radius:4px; font-size:11px;'><?php echo htmlspecialchars($val_fila); ?></span></td>
                                <td><span style='color:#334155; font-size:11px;'>📅 <?php echo htmlspecialchars($val_data); ?></span></td>
                                <td><span style='color:#64748b; font-size:11px;'>🕒 <?php echo htmlspecialchars($val_hora); ?></span></td>
                                <td><span style='font-weight:600; color:#1e293b; font-size:12px;'>📞 <?php echo htmlspecialchars($val_telefone); ?></span></td>
                                <td>
                                    <?php
                                    $avUpper = strtoupper(trim($val_avaliacao));
                                    switch ($avUpper) {
                                        case 'EXCELENTE':
                                        case 'OTIMO':
                                        case 'ÓTIMO':
                                        case '5':
                                            echo "<span style='background:#10b981; color:#ffffff; padding:3px 8px; border-radius:10px; font-weight:bold; font-size:10px; display:inline-block;'>⭐⭐⭐⭐⭐ $avUpper</span>";
                                            break;
                                        case 'MUITO BOM':
                                        case '4':
                                            echo "<span style='background:#3b82f6; color:#ffffff; padding:3px 8px; border-radius:10px; font-weight:bold; font-size:10px; display:inline-block;'>⭐⭐⭐⭐ MUITO BOM</span>";
                                            break;
                                        case 'MEDIO':
                                        case 'MÉDIO':
                                        case 'REGULAR':
                                        case 'BOM':
                                        case '3':
                                            echo "<span style='background:#f59e0b; color:#ffffff; padding:3px 8px; border-radius:10px; font-weight:bold; font-size:10px; display:inline-block;'>⭐⭐⭐ $avUpper</span>";
                                            break;
                                        case '2':
                                            echo "<span style='background:#f97316; color:#ffffff; padding:3px 8px; border-radius:10px; font-weight:bold; font-size:10px; display:inline-block;'>⭐⭐ BOM</span>";
                                            break;
                                        case 'RUIM':
                                        case 'PESSIMO':
                                        case 'PÉSSIMO':
                                        case '1':
                                            echo "<span style='background:#ef4444; color:#ffffff; padding:3px 8px; border-radius:10px; font-weight:bold; font-size:10px; display:inline-block;'>⭐ $avUpper</span>";
                                            break;
                                        case 'NAO AVALIOU':
                                        case 'NÃO AVALIOU':
                                        case 'ABANDONOU':
                                        case 'SEM RESPOSTA':
                                        case 'DESISTIU':
                                        case '0':
                                            echo "<span style='background:#64748b; color:#ffffff; padding:3px 8px; border-radius:10px; font-weight:bold; font-size:10px; display:inline-block;'>📵 NÃO AVALIOU</span>";
                                            break;
                                        default:
                                            echo "<span style='background:#94a3b8; color:#ffffff; padding:3px 8px; border-radius:10px; font-weight:bold; font-size:10px; display:inline-block;'>$avUpper</span>";
                                            break;
                                    }
                                    ?>
                                </td>
                                <td>
                                    <?php
                                    $solUpper = strtoupper(trim($val_solucao));
                                    if ($solUpper == 'SIM' || $solUpper == '1') {
                                        echo "<span style='background:#dcfce7; color:#15803d; border:1px solid #86efac; padding:3px 8px; border-radius:6px; font-weight:bold; font-size:10px;'>✔ SIM</span>";
                                    } elseif ($solUpper == 'NAO' || $solUpper == 'NÃO' || $solUpper == '2') {
                                        echo "<span style='background:#fee2e2; color:#b91c1c; border:1px solid #fca5a5; padding:3px 8px; border-radius:6px; font-weight:bold; font-size:10px;'>✖ NÃO</span>";
                                    } else {
                                        echo "<span style='background:#f1f5f9; color:#64748b; padding:3px 8px; border-radius:6px; font-size:10px;'>$solUpper</span>";
                                    }
                                    ?>
                                </td>
                                <td>
                                    <?php if (!empty($recFile)): ?>
                                        <?php $fileEnc = urlencode($recFile); ?>
                                        <div style="display:flex; gap:4px; align-items:center;">
                                            <button type="button" onclick="playPesquisaAudio('<?php echo $fileEnc; ?>')" style="background:#0284c7; color:#ffffff; border:none; padding:3px 10px; border-radius:12px; font-weight:700; font-size:10px; cursor:pointer;">▶ Play</button>
                                            <a href="?menu=<?php echo htmlspecialchars($module_name); ?>&rawmode=yes&action=download_audio&file=<?php echo $fileEnc; ?>" target="_blank" style="background:#16a34a; color:#ffffff; padding:3px 10px; border-radius:12px; font-weight:700; font-size:10px; text-decoration:none;">Baixar</a>
                                        </div>
                                    <?php else: ?>
                                        <span style="color:#cbd5e1; font-size:10px;">Sem áudio</span>
                                    <?php endif; ?>
                                </td>
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

            <!-- Barra de Paginação -->
            <div class="pagination-bar">
                <div class="pagination-info">
                    Exibindo <?php echo count($arrResult); ?> de <?php echo number_format($total, 0, ',', '.'); ?> avaliações (Página <?php echo $page; ?> de <?php echo $totalPages; ?>)
                </div>
                <div class="pagination-btns">
                    <?php
                    $navParams = array(
                        'menu' => $module_name,
                        'date_start' => $date_start,
                        'date_end' => $date_end,
                        'operador' => $operador,
                        'avaliacao' => $avaliacao,
                        'solucao' => $solucao
                    );
                    $prevPage = max(1, $page - 1);
                    $nextPage = min($totalPages, $page + 1);

                    $prevUrl = '?' . http_build_query(array_merge($navParams, array('page' => $prevPage)));
                    $nextUrl = '?' . http_build_query(array_merge($navParams, array('page' => $nextPage)));
                    ?>
                    <a href="<?php echo $prevUrl; ?>" class="page-link-btn <?php if ($page <= 1) echo 'disabled'; ?>">◀ Anterior</a>
                    <a href="<?php echo $nextUrl; ?>" class="page-link-btn <?php if ($page >= $totalPages) echo 'disabled'; ?>">Próxima ▶</a>
                </div>
            </div>
        </div>
    </div>

    <!-- Modal Player de Audio -->
    <div id="audioModalPesquisa">
        <div class="audio-modal-content">
            <h4 style="margin:0 0 12px 0; color:#1e293b; font-size:15px;">🎧 Reproduzindo Gravação</h4>
            <audio id="pesquisaAudioElement" controls style="width:100%; margin-bottom:15px;"></audio>
            <button onclick="closeAudioModal()" style="background:#64748b; color:#fff; border:none; padding:6px 16px; border-radius:6px; font-weight:bold; cursor:pointer;">Fechar</button>
        </div>
    </div>

    <script>
    function playPesquisaAudio(fileEnc) {
        var modal = document.getElementById('audioModalPesquisa');
        var audio = document.getElementById('pesquisaAudioElement');
        audio.src = '?menu=<?php echo htmlspecialchars($module_name); ?>&rawmode=yes&action=stream_audio&file=' + fileEnc;
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
            var ctxNotas = document.getElementById('chartNotasCustom').getContext('2d');
            new Chart(ctxNotas, {
                type: 'doughnut',
                data: {
                    labels: ['Excelente / Ótimo (5)', 'Muito Bom (4)', 'Bom / Regular (3)', 'Ruim (2)', 'Péssimo (1)', 'Não Avaliou (0)'],
                    datasets: [{
                        data: [<?php echo "$otimo, $muito_bom, $medio, $bom, $ruim, $nao_avaliou"; ?>],
                        backgroundColor: ['#10b981', '#3b82f6', '#f59e0b', '#f97316', '#ef4444', '#64748b'],
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

            var ctxSolucao = document.getElementById('chartSolucaoCustom').getContext('2d');
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
                    plugins: { legend: { position: 'right' } }
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