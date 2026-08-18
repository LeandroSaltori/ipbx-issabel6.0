<?php
/* vim: set expandtab tabstop=4 softtabstop=4 shiftwidth=4:
  Codificación: UTF-8
  +----------------------------------------------------------------------+
  | Issabel version 5.0 - Módulo Executivo de Ligações                   |
  | http://www.issabel.org                                               |
  +----------------------------------------------------------------------+
  | Copyright (c) 2017 Issabel Foundation                                |
  | Copyright (c) 2006 Palosanto Solutions S. A.                         |
  +----------------------------------------------------------------------+
  $Id: index.php,v 20.0 2026-08-18 Prisma Telecom $ */

include_once "libs/paloSantoGrid.class.php";
include_once "libs/paloSantoDB.class.php";
include_once "libs/paloSantoForm.class.php";
include_once "libs/paloSantoConfig.class.php";
include_once "libs/paloSantoCDR.class.php";
include_once "libs/paloSantoJSON.class.php";
require_once "libs/misc.lib.php";

function formatDateBrCdr($d) {
    if (empty($d) || $d == '-') return '-';
    $d = trim($d);
    if (preg_match('/^(\d{4})-(\d{2})-(\d{2})(.*)$/', $d, $m)) {
        $datePart = "{$m[3]}/{$m[2]}/{$m[1]}";
        $timePart = trim($m[4]);
        return !empty($timePart) ? "$datePart $timePart" : $datePart;
    }
    $ts = strtotime($d);
    return $ts ? date('d/m/Y H:i:s', $ts) : $d;
}

function formatPhoneBrCdr($num) {
    if (empty($num) || $num == '-') return '-';
    $clean = preg_replace('/[^0-9]/', '', $num);
    
    if (strlen($clean) >= 12 && substr($clean, 0, 2) === '00') {
        $clean = substr($clean, 2);
    } elseif (strlen($clean) >= 11 && substr($clean, 0, 1) === '0') {
        $clean = substr($clean, 1);
    }

    if (strlen($clean) == 11) {
        return sprintf('(%s) %s-%s', substr($clean, 0, 2), substr($clean, 2, 5), substr($clean, 7, 4));
    } elseif (strlen($clean) == 10) {
        return sprintf('(%s) %s-%s', substr($clean, 0, 2), substr($clean, 2, 4), substr($clean, 6, 4));
    }
    return $num;
}

function formatSecsCdr($sec) {
    $sec = (int)$sec;
    if ($sec <= 0) return '00:00';
    $m = floor($sec / 60);
    $s = $sec % 60;
    if ($m >= 60) {
        $h = floor($m / 60);
        $m = $m % 60;
        return sprintf('%02dh %02dm %02ds', $h, $m, $s);
    }
    return sprintf('%02d:%02d', $m, $s);
}

function _moduleContent(&$smarty, $module_name)
{
    require_once "modules/$module_name/libs/ringgroup.php";
    require_once "modules/$module_name/libs/queues.php";
    include_once "modules/$module_name/configs/default.conf.php";

    load_language_module($module_name);

    global $arrConf;
    global $arrConfModule;
    $arrConf = array_merge($arrConf, $arrConfModule);

    $filterLocalChannel = true;
    $dsn  = generarDSNSistema('asteriskuser', 'asteriskcdrdb');
    $pDB  = new paloDB($dsn);
    $oCDR = new paloSantoCDR($pDB);

    if (isset($_GET['action']) && ($_GET['action'] == 'stream_audio' || $_GET['action'] == 'download_audio')) {
        handleCdrAudioPlayback();
        exit;
    }

    if (isset($_GET['action']) && $_GET['action'] == 'export_excel') {
        handleCdrExportExcel($oCDR, $module_name);
        exit;
    }

    if (isset($_GET['action']) && $_GET['action'] == 'export_pdf') {
        handleCdrExportPdf($oCDR, $module_name);
        exit;
    }

    if (isset($_REQUEST['uniqueid'])) {
        return renderCelDetailsHtml($pDB, $_REQUEST['uniqueid']);
    }

    return renderFullCdrDashboard($oCDR, $pDB, $module_name, $smarty);
}

function handleCdrAudioPlayback()
{
    while (ob_get_level()) ob_end_clean();

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

function renderCelDetailsHtml($pDB, $uniqueid)
{
    while (ob_get_level()) ob_end_clean();
    $arrColumns = array('eventtime', 'eventtype', 'cid_name', 'cid_num', 'cid_dnid', 'exten', 'appname', 'uniqueid');
    $columnas = implode(",", $arrColumns);

    $sPeticionSQL = "SELECT linkedid FROM cel WHERE uniqueid=? LIMIT 1";
    $arrData = $pDB->fetchTable($sPeticionSQL, FALSE, array($uniqueid));
    $linkedId = !empty($arrData[0][0]) ? $arrData[0][0] : $uniqueid;

    $sPeticionSQL = "SELECT $columnas FROM cel WHERE linkedid=? ORDER BY eventtime ASC";
    $arrEvents = $pDB->fetchTable($sPeticionSQL, FALSE, array($linkedId));

    $evtMap = array(
        'CHAN_START'    => array('label' => '🚀 Início Canal', 'color' => '#dbeafe', 'text' => '#1e40af', 'desc' => '🚀 CHAN_START: A chamada iniciou o processamento na central PBX.'),
        'ANSWER'        => array('label' => '📞 Atendida', 'color' => '#dcfce7', 'text' => '#15803d', 'desc' => '📞 ANSWER: A ligação foi atendida com sucesso pelo destinatário.'),
        'HANGUP'        => array('label' => '📴 Desconectada', 'color' => '#fee2e2', 'text' => '#b91c1c', 'desc' => '📴 HANGUP: Uma das partes (origem ou destino) desligou a chamada.'),
        'CHAN_END'      => array('label' => '🏁 Fim Canal', 'color' => '#f1f5f9', 'text' => '#475569', 'desc' => '🏁 CHAN_END: O canal telefônico específico foi encerrado.'),
        'LINKEDID_END'  => array('label' => '🔚 Fim Ligação', 'color' => '#e0e7ff', 'text' => '#4338ca', 'desc' => '🔚 LINKEDID_END: Todos os canais vinculados a esta chamada foram finalizados.'),
        'BRIDGE_ENTER'  => array('label' => '🤝 Conversa Conectada', 'color' => '#fef3c7', 'text' => '#b45309', 'desc' => '🤝 BRIDGE_ENTER: Os canais de áudio foram conectados e a conversa começou.'),
        'BRIDGE_EXIT'   => array('label' => '🔌 Conversa Encerrada', 'color' => '#fee2e2', 'text' => '#991b1b', 'desc' => '🔌 BRIDGE_EXIT: Desconexão da ponte de áudio entre as partes.'),
        'APP_START'     => array('label' => '⚙️ Início App', 'color' => '#f3e8ff', 'text' => '#6b21a8', 'desc' => '⚙️ APP_START: Início da execução de uma aplicação do PBX (URA, Fila, Discagem).'),
        'APP_END'       => array('label' => '⚙️ Fim App', 'color' => '#f3e8ff', 'text' => '#581c87', 'desc' => '⚙️ APP_END: Término da execução da aplicação do PBX.')
    );

    ?>
    <!DOCTYPE html>
    <html lang="pt-BR">
    <head>
        <meta charset="UTF-8">
        <style>
            body { font-family:'Segoe UI', sans-serif; font-size:12px; color:#1e293b; padding:15px; margin:0; background:#f8fafc; }
            .cel-header { display:flex; justify-content:space-between; align-items:center; margin-bottom:10px; }
            h3 { margin:0; font-size:14px; color:#0f172a; font-weight:800; }
            .cel-info-banner { background:#eff6ff; border-left:4px solid #3b82f6; padding:10px 14px; border-radius:6px; font-size:11px; color:#1e3a8a; margin-bottom:12px; line-height:1.4; }
            table { width:100%; border-collapse:collapse; background:#ffffff; border-radius:8px; overflow:hidden; box-shadow:0 1px 3px rgba(0,0,0,0.05); border:1px solid #e2e8f0; }
            th { background:#334155; color:#ffffff; padding:8px 10px; font-size:10px; text-transform:uppercase; text-align:left; letter-spacing:0.5px; }
            td { padding:8px 10px; border-bottom:1px solid #f1f5f9; font-size:11px; vertical-align:middle; }
            tr:nth-child(even) { background:#f8fafc; }
            .badge-evt { padding:3px 8px; border-radius:6px; font-weight:bold; font-size:10px; display:inline-block; cursor:help; transition:all 0.2s; }
            .exten-badge { background:#f1f5f9; color:#334155; padding:2px 6px; border-radius:4px; font-family:monospace; font-weight:bold; font-size:11px; cursor:help; }
        </style>
    </head>
    <body>
        <div class="cel-header">
            <h3>📋 Log de Eventos Asterisk (CEL - LinkedID: <?php echo htmlspecialchars($linkedId); ?>)</h3>
        </div>
        <div class="cel-info-banner">
            💡 <strong>O que é o CEL?</strong> É o "raio-X" da ligação que registra cada evento da chamada. Passe o ponteiro do mouse sobre os nomes dos <strong>Eventos</strong> e <strong>Exten</strong> para ver a explicação detalhada de cada etapa.
        </div>
        <table>
            <thead>
                <tr>
                    <th>Data / Hora</th>
                    <th>Evento Asterisk</th>
                    <th>Nome Caller</th>
                    <th>Origem (Num)</th>
                    <th>DNID</th>
                    <th>Exten (Contexto)</th>
                    <th>Aplicação PBX</th>
                    <th>UniqueID</th>
                </tr>
            </thead>
            <tbody>
                <?php if (is_array($arrEvents) && count($arrEvents) > 0): ?>
                    <?php foreach ($arrEvents as $ev): ?>
                        <?php
                        $evtRaw = trim($ev[1]);
                        if (isset($evtMap[$evtRaw])) {
                            $eInfo = $evtMap[$evtRaw];
                            $evtHtml = "<span title='" . htmlspecialchars($eInfo['desc'], ENT_QUOTES) . "' class='badge-evt' style='background:{$eInfo['color']}; color:{$eInfo['text']};'>{$eInfo['label']}</span>";
                        } else {
                            $evtHtml = "<span title='Evento técnico Asterisk: $evtRaw' class='badge-evt' style='background:#e0e7ff; color:#4338ca;'>$evtRaw</span>";
                        }

                        $extRaw = trim($ev[5]);
                        if ($extRaw == 's') {
                            $extHtml = "<span title='Extensão s (Start): Ponto inicial do atendimento da URA ou rota de entrada.' class='exten-badge'>s (Start URA)</span>";
                        } elseif ($extRaw == 'h') {
                            $extHtml = "<span title='Extensão h (Hangup): Etapa de pós-atendimento realizada após o desligamento.' class='exten-badge'>h (Hangup)</span>";
                        } elseif ($extRaw == 't') {
                            $extHtml = "<span title='Extensão t (Timeout): Tempo limite de atendimento ou digitação esgotado.' class='exten-badge'>t (Timeout)</span>";
                        } elseif ($extRaw == 'i') {
                            $extHtml = "<span title='Extensão i (Invalid): Opção inválida digitada pelo cliente.' class='exten-badge'>i (Inválido)</span>";
                        } elseif (!empty($extRaw)) {
                            $extHtml = "<span title='Ramal, número de destino ou opção digitada: $extRaw' class='exten-badge'>$extRaw</span>";
                        } else {
                            $extHtml = "<span style='color:#cbd5e1;'>-</span>";
                        }
                        ?>
                        <tr>
                            <td><span style="color:#334155; font-weight:600; font-size:10px;">📅 <?php echo htmlspecialchars($ev[0]); ?></span></td>
                            <td><?php echo $evtHtml; ?></td>
                            <td><?php echo htmlspecialchars(!empty($ev[2]) ? $ev[2] : '-'); ?></td>
                            <td><span style="font-weight:600; color:#0f172a;">📞 <?php echo htmlspecialchars(!empty($ev[3]) ? $ev[3] : '-'); ?></span></td>
                            <td><?php echo htmlspecialchars(!empty($ev[4]) ? $ev[4] : '-'); ?></td>
                            <td><?php echo $extHtml; ?></td>
                            <td><code><?php echo htmlspecialchars(!empty($ev[6]) ? $ev[6] : '-'); ?></code></td>
                            <td><small style="color:#94a3b8; font-size:10px;"><?php echo htmlspecialchars($ev[7]); ?></small></td>
                        </tr>
                    <?php endforeach; ?>
                <?php else: ?>
                    <tr><td colspan="8" style="text-align:center; color:#64748b; padding:15px;">Nenhum evento registrado para esta chamada.</td></tr>
                <?php endif; ?>
            </tbody>
        </table>
    </body>
    </html>
    <?php
    exit;
}

function handleCdrExportExcel($oCDR, $module_name)
{
    while (ob_get_level()) ob_end_clean();

    $date_start    = isset($_REQUEST['date_start']) ? trim($_REQUEST['date_start']) : date("Y-m-d");
    $date_end      = isset($_REQUEST['date_end']) ? trim($_REQUEST['date_end']) : date("Y-m-d");
    $field_name    = isset($_REQUEST['field_name']) ? trim($_REQUEST['field_name']) : 'dst';
    $field_pattern = isset($_REQUEST['field_pattern']) ? trim($_REQUEST['field_pattern']) : '';
    $status        = isset($_REQUEST['status']) ? trim($_REQUEST['status']) : 'ALL';
    $ringgroup     = isset($_REQUEST['ringgroup']) ? trim($_REQUEST['ringgroup']) : '';

    $paramFiltro = array(
        'date_start'    => $date_start . ' 00:00:00',
        'date_end'      => $date_end . ' 23:59:59',
        'field_name'    => $field_name,
        'field_pattern' => $field_pattern,
        'status'        => $status,
        'ringgroup'     => $ringgroup,
        'limit'         => '100000',
        'timeInSecs'    => 'off'
    );

    $arrResult = $oCDR->listarCDRs($paramFiltro, 100000, 0, true);

    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename=Relatorio_Ligacoes_' . date('Ymd_His') . '.csv');

    $output = fopen('php://output', 'w');
    fprintf($output, chr(0xEF).chr(0xBB).chr(0xBF));
    fputcsv($output, array('Data/Hora', 'Origem', 'Grupo/Fila', 'Destino', 'Canal Origem', 'Canal Destino', 'Status', 'Duracao', 'DID', 'Gravacao'), ';');

    if (is_array($arrResult['cdrs'])) {
        foreach ($arrResult['cdrs'] as $r) {
            $dt     = formatDateBrCdr($r[0]);
            $src    = !empty($r[1]) ? $r[1] : '-';
            $rg     = !empty($r[11]) ? $r[11] : '-';
            $dst    = !empty($r[2]) ? $r[2] : '-';
            $cSrc   = !empty($r[3]) ? $r[3] : '-';
            $cDst   = !empty($r[4]) ? $r[4] : '-';
            $st     = !empty($r[5]) ? $r[5] : '-';
            $dur    = formatSecsCdr($r[8]);
            $did    = !empty($r[16]) ? $r[16] : '-';
            $rec    = !empty($r[9]) ? $r[9] : '-';

            fputcsv($output, array($dt, $src, $rg, $dst, $cSrc, $cDst, $st, $dur, $did, $rec), ';');
        }
    }
    fclose($output);
    exit;
}

function handleCdrExportPdf($oCDR, $module_name)
{
    while (ob_get_level()) ob_end_clean();

    $date_start    = isset($_REQUEST['date_start']) ? trim($_REQUEST['date_start']) : date("Y-m-d");
    $date_end      = isset($_REQUEST['date_end']) ? trim($_REQUEST['date_end']) : date("Y-m-d");
    $field_name    = isset($_REQUEST['field_name']) ? trim($_REQUEST['field_name']) : 'dst';
    $field_pattern = isset($_REQUEST['field_pattern']) ? trim($_REQUEST['field_pattern']) : '';
    $status        = isset($_REQUEST['status']) ? trim($_REQUEST['status']) : 'ALL';
    $ringgroup     = isset($_REQUEST['ringgroup']) ? trim($_REQUEST['ringgroup']) : '';

    $paramFiltro = array(
        'date_start'    => $date_start . ' 00:00:00',
        'date_end'      => $date_end . ' 23:59:59',
        'field_name'    => $field_name,
        'field_pattern' => $field_pattern,
        'status'        => $status,
        'ringgroup'     => $ringgroup,
        'limit'         => '100000',
        'timeInSecs'    => 'off'
    );

    $arrResult = $oCDR->listarCDRs($paramFiltro, 100000, 0, true);

    ?>
    <!DOCTYPE html>
    <html lang="pt-BR">
    <head>
        <meta charset="UTF-8">
        <title>Relatório de Ligações - IPbx Prisma</title>
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
        <h2>📞 Relatório de Ligações - IPbx Prisma</h2>
        <p>Gerado em <?php echo date('d/m/Y H:i:s'); ?> | Total: <?php echo is_array($arrResult['cdrs']) ? count($arrResult['cdrs']) : 0; ?> chamadas</p>
        
        <table>
            <thead>
                <tr>
                    <th>Data/Hora</th>
                    <th>Origem</th>
                    <th>Fila / Grupo</th>
                    <th>Destino</th>
                    <th>Status</th>
                    <th>Duração</th>
                    <th>DID</th>
                </tr>
            </thead>
            <tbody>
                <?php if (is_array($arrResult['cdrs'])): ?>
                    <?php foreach ($arrResult['cdrs'] as $r): ?>
                    <tr>
                        <td><?php echo formatDateBrCdr($r[0]); ?></td>
                        <td>📞 <?php echo htmlspecialchars($r[1]); ?></td>
                        <td>🏢 <?php echo htmlspecialchars(!empty($r[11]) ? $r[11] : '-'); ?></td>
                        <td>🎯 <?php echo htmlspecialchars($r[2]); ?></td>
                        <td><?php echo htmlspecialchars($r[5]); ?></td>
                        <td>⏱️ <?php echo formatSecsCdr($r[8]); ?></td>
                        <td><?php echo htmlspecialchars(!empty($r[16]) ? $r[16] : '-'); ?></td>
                    </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </body>
    </html>
    <?php
    exit;
}

function renderFullCdrDashboard($oCDR, $pDB, $module_name, $smarty)
{
    $date_start    = isset($_REQUEST['date_start']) ? trim($_REQUEST['date_start']) : date("Y-m-d");
    $date_end      = isset($_REQUEST['date_end']) ? trim($_REQUEST['date_end']) : date("Y-m-d");
    $field_name    = isset($_REQUEST['field_name']) ? trim($_REQUEST['field_name']) : 'dst';
    $field_pattern = isset($_REQUEST['field_pattern']) ? trim($_REQUEST['field_pattern']) : '';
    $status        = isset($_REQUEST['status']) ? trim($_REQUEST['status']) : 'ALL';
    $ringgroup     = isset($_REQUEST['ringgroup']) ? trim($_REQUEST['ringgroup']) : '';

    $page  = isset($_REQUEST['page']) ? max(1, (int)$_REQUEST['page']) : 1;
    $limit = 20;
    $offset = ($page - 1) * $limit;

    $oRG       = new RingGroup($pDB);
    $dataRG    = $oRG->getRingGroup();
    $oQueue    = new Queue($pDB);
    $dataQueue = $oQueue->getQueue();
    $groupsMap = $dataRG + $dataQueue;

    $paramFiltro = array(
        'date_start'    => $date_start . ' 00:00:00',
        'date_end'      => $date_end . ' 23:59:59',
        'field_name'    => $field_name,
        'field_pattern' => $field_pattern,
        'status'        => $status,
        'ringgroup'     => $ringgroup,
        'limit'         => '100000',
        'timeInSecs'    => 'off'
    );

    $arrResultAll = $oCDR->listarCDRs($paramFiltro, 100000, 0, true);
    $rawList      = is_array($arrResultAll['cdrs']) ? $arrResultAll['cdrs'] : array();
    $totalCount   = count($rawList);

    $pageList = array_slice($rawList, $offset, $limit);
    $totalPages = max(1, ceil($totalCount / $limit));

    // Stats Computation
    $answeredCount  = 0;
    $noAnswerCount  = 0;
    $busyCount      = 0;
    $failedCount    = 0;
    $totalDuration  = 0;
    $hourlyTraffic  = array_fill(0, 24, 0);

    foreach ($rawList as $r) {
        $st = strtoupper(trim($r[5]));
        $dur = (int)$r[8];
        $totalDuration += $dur;

        if ($st == 'ANSWERED') $answeredCount++;
        elseif ($st == 'NO ANSWER') $noAnswerCount++;
        elseif ($st == 'BUSY') $busyCount++;
        elseif ($st == 'FAILED') $failedCount++;
        else $noAnswerCount++;

        $timeStr = !empty($r[0]) ? $r[0] : '';
        if (preg_match('/(\d{2}):\d{2}:\d{2}/', $timeStr, $m)) {
            $h = (int)$m[1];
            if ($h >= 0 && $h <= 23) $hourlyTraffic[$h]++;
        }
    }

    $avgDuration = $answeredCount > 0 ? (int)round($totalDuration / $answeredCount) : 0;
    $ansPercent  = $totalCount > 0 ? round(($answeredCount / $totalCount) * 100, 1) : 0;
    $missPercent = $totalCount > 0 ? round((($noAnswerCount + $busyCount + $failedCount) / $totalCount) * 100, 1) : 0;

    $exportParams = http_build_query(array(
        'menu' => $module_name,
        'rawmode' => 'yes',
        'date_start' => $date_start,
        'date_end' => $date_end,
        'field_name' => $field_name,
        'field_pattern' => $field_pattern,
        'status' => $status,
        'ringgroup' => $ringgroup
    ));

    ob_start();
    ?>
    <script src="https://cdn.jsdelivr.net/npm/chart.js@3.9.1/dist/chart.min.js"></script>
    <style>
        .cdr-root {
            font-family: 'Segoe UI', -apple-system, BlinkMacSystemFont, sans-serif;
            color: #1e293b;
            padding: 5px;
        }
        .cdr-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 12px;
        }
        .cdr-title h2 {
            margin: 0;
            font-size: 20px;
            font-weight: 800;
            color: #0f172a;
            letter-spacing: -0.3px;
        }
        .cdr-title p {
            margin: 1px 0 0 0;
            font-size: 11px;
            color: #64748b;
        }
        .cdr-top-btns {
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
            min-width: 120px;
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

        .kpi-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(170px, 1fr));
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
        .kpi-card-item.red { border-left-color: #ef4444; }
        .kpi-card-item.blue { border-left-color: #3b82f6; }
        .kpi-card-item.amber { border-left-color: #f59e0b; }

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

        .charts-grid {
            display: grid;
            grid-template-columns: 2fr 1fr;
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

        .table-card-box {
            background: #ffffff;
            border-radius: 10px;
            box-shadow: 0 1px 4px rgba(0,0,0,0.04);
            border: 1px solid #e2e8f0;
            overflow: hidden;
        }
        .cdr-table {
            width: 100%;
            border-collapse: collapse;
            text-align: left;
        }
        .cdr-table thead {
            background: #334155;
            color: #ffffff;
        }
        .cdr-table th {
            padding: 10px 14px;
            font-size: 10px;
            font-weight: 800;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }
        .cdr-table td {
            padding: 10px 14px;
            border-bottom: 1px solid #f1f5f9;
            font-size: 12px;
            vertical-align: middle;
        }
        .cdr-table tbody tr:hover { background: #f8fafc; }

        .pagination-bar {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 12px 16px;
            background: #ffffff;
            border-top: 1px solid #f1f5f9;
        }
        .pagination-info { font-size: 12px; color: #64748b; font-weight: 600; }
        .pagination-btns { display: flex; gap: 6px; }
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

        .queue-badge-compact {
            background: #f1f5f9;
            color: #334155;
            padding: 3px 8px;
            border-radius: 6px;
            font-weight: 700;
            font-size: 11px;
            cursor: help;
            border: 1px solid #e2e8f0;
            display: inline-flex;
            align-items: center;
            gap: 4px;
            transition: all 0.2s;
        }
        .queue-badge-compact:hover {
            background: #e2e8f0;
            color: #0f172a;
            border-color: #cbd5e1;
        }

        .status-badge-ans { background:#dcfce7; color:#15803d; border:1px solid #86efac; padding:3px 8px; border-radius:6px; font-weight:bold; font-size:10px; display:inline-flex; align-items:center; gap:3px; }
        .status-badge-noans { background:#fee2e2; color:#b91c1c; border:1px solid #fca5a5; padding:3px 8px; border-radius:6px; font-weight:bold; font-size:10px; display:inline-flex; align-items:center; gap:3px; }
        .status-badge-busy { background:#fef3c7; color:#b45309; border:1px solid #fde68a; padding:3px 8px; border-radius:6px; font-weight:bold; font-size:10px; display:inline-flex; align-items:center; gap:3px; }
        .status-badge-fail { background:#fee2e2; color:#b91c1c; border:1px solid #fca5a5; padding:3px 8px; border-radius:6px; font-weight:bold; font-size:10px; display:inline-flex; align-items:center; gap:3px; }

        #audioModalCdr, #celModalCdr {
            display: none;
            position: fixed;
            top: 0; left: 0; width: 100%; height: 100%;
            background: rgba(15, 23, 42, 0.6);
            backdrop-filter: blur(4px);
            z-index: 99999;
            align-items: center;
            justify-content: center;
        }
        .modal-content-box {
            background: #ffffff;
            border-radius: 12px;
            padding: 24px;
            width: 420px;
            box-shadow: 0 20px 25px -5px rgba(0,0,0,0.2);
            text-align: center;
        }
    </style>

    <div class="cdr-root">
        <!-- Header Principal -->
        <div class="cdr-header">
            <div class="cdr-title">
                <h2>Relatório de Ligações (CDR) - IPbx Prisma</h2>
                <p>Histórico detalhado de chamadas recebidas, efetuadas e internas com gravação de áudio</p>
            </div>
            <div class="cdr-top-btns">
                <a href="modules/cdrreport/help/index.html" target="_blank" class="btn-top btn-top-manual">📖 Manual</a>
                <button onclick="window.open('?menu=<?php echo htmlspecialchars($module_name); ?>&rawmode=yes', '_blank')" class="btn-top btn-top-expand">↗ Expandir Aba</button>
            </div>
        </div>

        <!-- Card de Filtros Compacto -->
        <div class="filter-card-box">
            <form method="GET" action="index.php">
                <input type="hidden" name="menu" value="<?php echo htmlspecialchars($module_name); ?>" />
                <div class="filter-inline-row">
                    <div class="filter-field-group" title="📅 Data Inicial do Período&#10;Selecione a data de início da busca de chamadas no CDR.">
                        <label>📅 Data Inicial</label>
                        <input type="date" name="date_start" value="<?php echo htmlspecialchars($date_start); ?>" class="filter-input" />
                    </div>
                    <div class="filter-field-group" title="📅 Data Final do Período&#10;Selecione a data limite da busca de ligações.">
                        <label>📅 Data Final</label>
                        <input type="date" name="date_end" value="<?php echo htmlspecialchars($date_end); ?>" class="filter-input" />
                    </div>
                    <div class="filter-field-group" title="📞 Padrão ou Número&#10;Digite o ramal, número de telefone ou texto a ser pesquisado no campo selecionado.">
                        <label>📞 Padrão / Número</label>
                        <input type="text" name="field_pattern" value="<?php echo htmlspecialchars($field_pattern); ?>" placeholder="Ex: 5001 ou 99988..." class="filter-input" />
                    </div>
                    <div class="filter-field-group" title="📌 Campo de Busca SQL&#10;Escolha em qual coluna do CDR aplicar a pesquisa do texto digitado:&#10;• Destino (dst): Ramal ou número chamado&#10;• Origem (src): Número da Bina ou ramal que ligou&#10;• Canal Origem: Porta/tronco de entrada (ex: SIP/1001)&#10;• Fila/Accountcode: Código da fila de atendimento&#10;• DID: Linha direta de entrada no PBX.">
                        <label>📌 Campo Busca</label>
                        <select name="field_name" class="filter-input">
                            <option value="dst" <?php if ($field_name == 'dst') echo 'selected'; ?>>Destino (dst)</option>
                            <option value="src" <?php if ($field_name == 'src') echo 'selected'; ?>>Origem (src)</option>
                            <option value="channel" <?php if ($field_name == 'channel') echo 'selected'; ?>>Canal Origem</option>
                            <option value="accountcode" <?php if ($field_name == 'accountcode') echo 'selected'; ?>>Fila / Accountcode</option>
                            <option value="dstchannel" <?php if ($field_name == 'dstchannel') echo 'selected'; ?>>Canal Destino</option>
                            <option value="did" <?php if ($field_name == 'did') echo 'selected'; ?>>DID Entrante</option>
                        </select>
                    </div>
                    <div class="filter-field-group" title="🚦 Status da Ligação&#10;Filtre por:&#10;• Atendidas (ANSWERED): Houve diálogo&#10;• Não Atendidas (NO ANSWER): Tocou sem resposta&#10;• Ocupado (BUSY): Destino ocupado&#10;• Falhas (FAILED): Erro de rota ou rede.">
                        <label>🚦 Status</label>
                        <select name="status" class="filter-input">
                            <option value="ALL" <?php if ($status == 'ALL') echo 'selected'; ?>>-- Todos os Status --</option>
                            <option value="ANSWERED" <?php if ($status == 'ANSWERED') echo 'selected'; ?>>✅ Atendidas (ANSWERED)</option>
                            <option value="NO ANSWER" <?php if ($status == 'NO ANSWER') echo 'selected'; ?>>📵 Não Atendidas (NO ANSWER)</option>
                            <option value="BUSY" <?php if ($status == 'BUSY') echo 'selected'; ?>>🟡 Ocupado (BUSY)</option>
                            <option value="FAILED" <?php if ($status == 'FAILED') echo 'selected'; ?>>✖ Falhas (FAILED)</option>
                        </select>
                    </div>
                    <div class="filter-field-group" title="🏢 Fila ou Grupo de Atendimento&#10;Filtre pelas ligações direcionadas a uma fila de atendimento específica.">
                        <label>🏢 Fila / Grupo</label>
                        <select name="ringgroup" class="filter-input">
                            <option value="">-- Qualquer Fila/Grupo --</option>
                            <?php if (is_array($groupsMap)): ?>
                                <?php foreach ($groupsMap as $gKey => $gVal): ?>
                                    <?php if (!empty($gKey)): ?>
                                        <option value="<?php echo htmlspecialchars($gKey); ?>" <?php if ($ringgroup == $gKey) echo 'selected'; ?>>🏢 <?php echo htmlspecialchars("$gKey - $gVal"); ?></option>
                                    <?php endif; ?>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </select>
                    </div>
                    <div class="filter-btn-row">
                        <button type="submit" class="btn-action btn-search" title="🔍 Filtrar Resultados&#10;Buscar ligações no banco CDR com base nos critérios informados.">🔍 Filtrar</button>
                        <a href="?<?php echo $exportParams; ?>&action=export_excel" class="btn-action btn-excel" title="📊 Baixar Excel (.csv)&#10;Exportar a lista inteira de ligações em formato de planilha.">📊 Excel</a>
                        <a href="?<?php echo $exportParams; ?>&action=export_pdf" target="_blank" class="btn-action btn-pdf" title="📄 Gerar Relatório PDF&#10;Abrir visualização pronta para impressão ou salvar em documento PDF.">📄 PDF</a>
                        <a href="?menu=<?php echo htmlspecialchars($module_name); ?>" class="btn-action btn-reset" title="🔄 Limpar Filtros&#10;Restaurar o filtro para a data atual.">🔄 Reset</a>
                    </div>
                </div>
            </form>
        </div>

        <!-- Grid de 5 Cards KPIs Executivos -->
        <div class="kpi-grid">
            <div class="kpi-card-item purple" title="📋 Total de Ligações&#10;Soma de todas as chamadas registradas (recebidas, efetuadas e internas) no período selecionado.">
                <div class="kpi-card-title">📋 Total de Ligações</div>
                <div class="kpi-card-num"><?php echo number_format($totalCount, 0, ',', '.'); ?></div>
                <div class="kpi-card-desc">Chamadas no Período</div>
            </div>
            <div class="kpi-card-item green" title="✅ Ligações Atendidas&#10;Total e percentual de ligações onde o atendimento foi realizado e houve diálogo.">
                <div class="kpi-card-title">✅ Atendidas</div>
                <div class="kpi-card-num"><?php echo number_format($answeredCount, 0, ',', '.'); ?></div>
                <div class="kpi-card-desc"><?php echo $ansPercent; ?>% do total</div>
            </div>
            <div class="kpi-card-item red" title="📵 Ligações Não Atendidas / Ocupado&#10;Total e percentual de chamadas não completadas (chamadas sem resposta, ocupado ou com falha).">
                <div class="kpi-card-title">📵 Não Atendidas</div>
                <div class="kpi-card-num"><?php echo number_format($noAnswerCount + $busyCount + $failedCount, 0, ',', '.'); ?></div>
                <div class="kpi-card-desc"><?php echo $missPercent; ?>% não completaram</div>
            </div>
            <div class="kpi-card-item blue" title="⏱️ Duração Total Falada&#10;Soma acumulada do tempo de conversação de todas as ligações atendidas.">
                <div class="kpi-card-title">⏱️ Duração Total</div>
                <div class="kpi-card-num"><?php echo formatSecsCdr($totalDuration); ?></div>
                <div class="kpi-card-desc">Tempo acumulado de fala</div>
            </div>
            <div class="kpi-card-item amber" title="⏳ Tempo Médio por Chamada&#10;Tempo médio de conversa por cada ligação atendida no PBX.">
                <div class="kpi-card-title">⏳ Tempo Médio</div>
                <div class="kpi-card-num"><?php echo formatSecsCdr($avgDuration); ?></div>
                <div class="kpi-card-desc">Por ligação atendida</div>
            </div>
        </div>

        <!-- Grid de Gráficos -->
        <div class="charts-grid">
            <div class="chart-card-box" title="📊 Volume por Horário&#10;Gráfico de barras indicando a quantidade de chamadas recebidas hora a hora para identificação de picos.">
                <h4>📊 Distribuição de Chamadas por Horário (00h - 23h)</h4>
                <div class="chart-canvas-wrapper">
                    <canvas id="chartCdrHourly"></canvas>
                </div>
            </div>
            <div class="chart-card-box" title="🚦 Status das Chamadas&#10;Gráfico de rosca demonstrando o percentual de Atendidas, Não Atendidas, Ocupadas e Falhas.">
                <h4>🚦 Ocupação e Status das Chamadas</h4>
                <div class="chart-canvas-wrapper">
                    <canvas id="chartCdrStatus"></canvas>
                </div>
            </div>
        </div>

        <!-- Tabela Customizada Moderna -->
        <div class="table-card-box">
            <table class="cdr-table">
                <thead>
                    <tr>
                        <th title="📅 Data e Hora&#10;Momento exato da entrada da ligação no PBX.">Data / Hora</th>
                        <th title="📞 Número de Origem (Bina)&#10;Telefone da pessoa que realizou a ligação ou ramal interno que originou.">Origem (Bina)</th>
                        <th title="🏢 Fila ou Grupo&#10;Selo da fila por onde a ligação passou. Passe o mouse sobre o selo para ver o nome completo da fila.">Fila / Grupo</th>
                        <th title="🎯 Destino&#10;Ramal, número externo ou DID direcionado.">Destino</th>
                        <th title="🚦 Status da Chamada&#10;Classificação do resultado da ligação (Atendida, Não Atendeu, Ocupado ou Falha).">Status</th>
                        <th title="⏱️ Duração da Conversa&#10;Tempo total de fala formatado em minutos e segundos.">Duração</th>
                        <th title="📟 DID / Tronco&#10;Número de linha direta por onde a ligação entrou na central.">DID / Tronco</th>
                        <th title="🎧 Gravação de Áudio&#10;Clique em Play para escutar no player ou Baixar para obter o áudio.">Gravação</th>
                        <th title="📋 Histórico CEL&#10;Clique em CEL para abrir a janela de raio-X do histórico de eventos da chamada no Asterisk.">Eventos</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (is_array($pageList) && count($pageList) > 0): ?>
                        <?php foreach ($pageList as $r): ?>
                            <?php
                            $val_data  = formatDateBrCdr($r[0]);
                            $raw_src   = !empty($r[1]) ? $r[1] : '-';
                            $val_src   = formatPhoneBrCdr($raw_src);
                            $raw_rg    = !empty($r[11]) ? trim($r[11]) : '';
                            $val_dst   = !empty($r[2]) ? $r[2] : '-';
                            $raw_st    = strtoupper(trim($r[5]));
                            $val_dur   = formatSecsCdr($r[8]);
                            $recFile   = !empty($r[9]) ? $r[9] : '';
                            $uniqueId  = !empty($r[6]) ? $r[6] : '';
                            $did       = !empty($r[16]) ? $r[16] : '-';

                            if (!empty($raw_rg) && isset($groupsMap[$raw_rg])) {
                                $fullName = "$raw_rg - " . $groupsMap[$raw_rg];
                                $val_rg_html = "<span title='" . htmlspecialchars($fullName, ENT_QUOTES) . "' class='queue-badge-compact'>🏢 $raw_rg</span>";
                            } elseif (!empty($raw_rg) && is_numeric($raw_rg) && strlen($raw_rg) >= 3) {
                                $val_rg_html = "<span title='Fila / Grupo $raw_rg' class='queue-badge-compact'>🏢 $raw_rg</span>";
                            } else {
                                $val_rg_html = "<span style='color:#cbd5e1;'>-</span>";
                            }
                            ?>
                            <tr>
                                <td><span style='color:#334155; font-size:11px; font-weight:600;'>📅 <?php echo htmlspecialchars($val_data); ?></span></td>
                                <td><span style='font-weight:600; color:#1e293b;'>📞 <?php echo htmlspecialchars($val_src); ?></span></td>
                                <td><?php echo $val_rg_html; ?></td>
                                <td><span style='background:#ede9fe; color:#6d28d9; padding:3px 8px; border-radius:6px; font-weight:600; font-size:11px;'>🎯 <?php echo htmlspecialchars($val_dst); ?></span></td>
                                <td>
                                    <?php
                                    if ($raw_st == 'ANSWERED') {
                                        echo "<span class='status-badge-ans'>✅ Atendida</span>";
                                    } elseif ($raw_st == 'NO ANSWER') {
                                        echo "<span class='status-badge-noans'>📵 Não Atendeu</span>";
                                    } elseif ($raw_st == 'BUSY') {
                                        echo "<span class='status-badge-busy'>🟡 Ocupado</span>";
                                    } else {
                                        echo "<span class='status-badge-fail'>✖ $raw_st</span>";
                                    }
                                    ?>
                                </td>
                                <td><span style='color:#0f172a; font-weight:700; font-size:11px;'>⏱️ <?php echo htmlspecialchars($val_dur); ?></span></td>
                                <td><span style='color:#64748b; font-size:11px;'><code><?php echo htmlspecialchars($did); ?></code></span></td>
                                <td>
                                    <?php if (!empty($recFile)): ?>
                                        <?php $fileEnc = urlencode($recFile); ?>
                                        <div style="display:flex; gap:4px; align-items:center;">
                                            <button type="button" onclick="playCdrAudio('<?php echo $fileEnc; ?>')" style="background:#0284c7; color:#ffffff; border:none; padding:3px 10px; border-radius:12px; font-weight:700; font-size:10px; cursor:pointer;">▶ Play</button>
                                            <a href="?menu=<?php echo htmlspecialchars($module_name); ?>&rawmode=yes&action=download_audio&file=<?php echo $fileEnc; ?>" target="_blank" style="background:#16a34a; color:#ffffff; padding:3px 10px; border-radius:12px; font-weight:700; font-size:10px; text-decoration:none;">Baixar</a>
                                        </div>
                                    <?php else: ?>
                                        <span style="color:#cbd5e1; font-size:10px;">Sem áudio</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php if (!empty($uniqueId)): ?>
                                        <button type="button" onclick="openCelModal('<?php echo htmlspecialchars($uniqueId); ?>')" style="background:#6366f1; color:#fff; border:none; padding:3px 8px; border-radius:6px; font-weight:bold; font-size:10px; cursor:pointer;">📋 CEL</button>
                                    <?php else: ?>
                                        <span style="color:#cbd5e1;">-</span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <tr>
                            <td colspan="9" style="text-align:center; padding:25px; color:#64748b;">
                                🚀 Nenhuma ligação encontrada para os filtros selecionados.
                            </td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>

            <!-- Barra de Paginação -->
            <div class="pagination-bar">
                <div class="pagination-info">
                    Exibindo <?php echo count($pageList); ?> de <?php echo number_format($totalCount, 0, ',', '.'); ?> ligações (Página <?php echo $page; ?> de <?php echo $totalPages; ?>)
                </div>
                <div class="pagination-btns">
                    <?php
                    $navParams = array(
                        'menu' => $module_name,
                        'date_start' => $date_start,
                        'date_end' => $date_end,
                        'field_name' => $field_name,
                        'field_pattern' => $field_pattern,
                        'status' => $status,
                        'ringgroup' => $ringgroup
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
    <div id="audioModalCdr">
        <div class="modal-content-box">
            <h4 style="margin:0 0 12px 0; color:#1e293b; font-size:15px;">🎧 Reproduzindo Gravação</h4>
            <audio id="cdrAudioElement" controls style="width:100%; margin-bottom:15px;"></audio>
            <button onclick="closeCdrAudioModal()" style="background:#64748b; color:#fff; border:none; padding:6px 16px; border-radius:6px; font-weight:bold; cursor:pointer;">Fechar</button>
        </div>
    </div>

    <!-- Modal CEL Events -->
    <div id="celModalCdr">
        <div class="modal-content-box" style="width:780px; max-width:95%;">
            <iframe id="celIframeElement" style="width:100%; height:380px; border:none; border-radius:8px;"></iframe>
            <button onclick="closeCelModal()" style="background:#64748b; color:#fff; border:none; padding:6px 16px; border-radius:6px; font-weight:bold; cursor:pointer; margin-top:10px;">Fechar</button>
        </div>
    </div>

    <script>
    function playCdrAudio(fileEnc) {
        var modal = document.getElementById('audioModalCdr');
        var audio = document.getElementById('cdrAudioElement');
        audio.src = '?menu=<?php echo htmlspecialchars($module_name); ?>&rawmode=yes&action=stream_audio&file=' + fileEnc;
        modal.style.display = 'flex';
        audio.play();
    }
    function closeCdrAudioModal() {
        var modal = document.getElementById('audioModalCdr');
        var audio = document.getElementById('cdrAudioElement');
        audio.pause();
        modal.style.display = 'none';
    }

    function openCelModal(uniqueId) {
        var modal = document.getElementById('celModalCdr');
        var iframe = document.getElementById('celIframeElement');
        iframe.src = '?menu=<?php echo htmlspecialchars($module_name); ?>&rawmode=yes&uniqueid=' + uniqueId;
        modal.style.display = 'flex';
    }
    function closeCelModal() {
        var modal = document.getElementById('celModalCdr');
        var iframe = document.getElementById('celIframeElement');
        iframe.src = '';
        modal.style.display = 'none';
    }

    document.addEventListener("DOMContentLoaded", function() {
        if (typeof Chart !== 'undefined') {
            var ctxHourly = document.getElementById('chartCdrHourly').getContext('2d');
            new Chart(ctxHourly, {
                type: 'bar',
                data: {
                    labels: ['00h','01h','02h','03h','04h','05h','06h','07h','08h','09h','10h','11h','12h','13h','14h','15h','16h','17h','18h','19h','20h','21h','22h','23h'],
                    datasets: [{
                        label: 'Volume de Ligações',
                        data: [<?php echo implode(',', $hourlyTraffic); ?>],
                        backgroundColor: '#6366f1',
                        borderRadius: 4
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: { legend: { display: false } },
                    scales: {
                        y: { beginAtZero: true, grid: { color: '#f1f5f9' } },
                        x: { grid: { display: false } }
                    }
                }
            });

            var ctxStatus = document.getElementById('chartCdrStatus').getContext('2d');
            new Chart(ctxStatus, {
                type: 'doughnut',
                data: {
                    labels: ['Atendidas', 'Não Atendidas', 'Ocupado', 'Falhas'],
                    datasets: [{
                        data: [<?php echo "$answeredCount, $noAnswerCount, $busyCount, $failedCount"; ?>],
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
