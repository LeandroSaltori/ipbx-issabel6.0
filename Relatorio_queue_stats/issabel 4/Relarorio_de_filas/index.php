<?php
/**
 * Relatório de Fila - IPbx Prisma
 * Banco: qstatslite, asterisk e asteriskcdrdb
 * PHP 7.4+ / PHP 5.6+ | MySQL 5.5+ (mysqli)
 */

date_default_timezone_set('America/Sao_Paulo');
ini_set('memory_limit', '512M');
ini_set('max_execution_time', 300);

// Garantir UTF-8 nos cabeçalhos HTTP e ambiente PHP
ini_set('default_charset', 'UTF-8');
if (function_exists('mb_internal_encoding')) {
    mb_internal_encoding('UTF-8');
}
header('Content-Type: text/html; charset=utf-8');

error_reporting(0);
ini_set('display_errors', 0);

// --- Leitura de credenciais Issabel -------------------------------------------------------------
function getIssabelConf() {
    $conf = array('mysqlrootpwd' => '');
    if (!file_exists('/etc/issabel.conf')) return $conf;
    $lines = file('/etc/issabel.conf', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($lines as $line) {
        if (strpos($line, '=') === false) continue;
        list($key, $val) = explode('=', $line, 2);
        $conf[trim($key)] = trim($val);
    }
    return $conf;
}

$issabelConf = getIssabelConf();
$dbHost   = 'localhost';
$dbUser   = 'root';
$dbPass   = isset($issabelConf['mysqlrootpwd']) ? $issabelConf['mysqlrootpwd'] : '';
$dbName   = 'qstatslite';

// --- Conexão MySQL (mysqli) ------------------------------------------------------
mysqli_report(MYSQLI_REPORT_OFF);
$conn = @mysqli_connect($dbHost, $dbUser, $dbPass);
if (!$conn) {
    die('<div style="padding:40px;font-family:monospace;color:red;">Erro ao conectar ao MySQL: ' . mysqli_connect_error() . '</div>');
}
mysqli_select_db($conn, $dbName) or die('<div style="padding:40px;font-family:monospace;color:red;">Banco qstatslite n&atilde;o encontrado.</div>');
mysqli_set_charset($conn, 'utf8');
mysqli_query($conn, "SET NAMES 'utf8'");

// --- Endpoint de Áudio: Stream / Download --------------------------------------------------
if (isset($_GET['action']) && in_array($_GET['action'], array('stream_audio', 'download_audio'))) {
    $uid = isset($_GET['uid']) ? preg_replace('/[^a-zA-Z0-9.\-_]/', '', $_GET['uid']) : '';
    if (empty($uid)) {
        http_response_code(400);
        die('ID de chamada inv&aacute;lido.');
    }

    $possiblePaths = array();

    // 1. Consulta ao banco asteriskcdrdb.cdr
    $rCdr = @mysqli_query($conn, "SELECT recordingfile FROM asteriskcdrdb.cdr WHERE uniqueid = '" . mysqli_real_escape_string($conn, $uid) . "' AND recordingfile != '' LIMIT 1");
    if ($rCdr && $rowCdr = mysqli_fetch_assoc($rCdr)) {
        $recFile = $rowCdr['recordingfile'];
        if ($recFile != '') {
            if (file_exists($recFile)) $possiblePaths[] = $recFile;
            if (file_exists('/var/spool/asterisk/monitor/' . $recFile)) $possiblePaths[] = '/var/spool/asterisk/monitor/' . $recFile;
        }
    }

    // 2. Busca dinamica por glob no diretorio monitor do Asterisk
    if (empty($possiblePaths)) {
        $monitorDir = '/var/spool/asterisk/monitor';
        if (is_dir($monitorDir)) {
            $files = glob($monitorDir . "/*/*/*/*" . $uid . "*.*");
            if (empty($files)) {
                $files = glob($monitorDir . "/*" . $uid . "*.*");
            }
            if (!empty($files)) {
                $possiblePaths = $files;
            }
        }
    }

    $filePath = !empty($possiblePaths) ? $possiblePaths[0] : '';

    if (empty($filePath) || !file_exists($filePath)) {
        http_response_code(404);
        die('Arquivo de grava&ccedil;&atilde;o n&atilde;o encontrado para esta chamada.');
    }

    $realPath = realpath($filePath);
    if ($realPath === false) {
        http_response_code(403);
        die('Acesso negado.');
    }

    // Limpar buffers de saida PHP antes do streaming binario
    while (ob_get_level()) {
        ob_end_clean();
    }

    if ($_GET['action'] === 'download_audio') {
        header('Content-Description: File Transfer');
        header('Content-Type: application/octet-stream');
        header('Content-Disposition: attachment; filename="' . basename($realPath) . '"');
        header('Content-Length: ' . filesize($realPath));
        header('Pragma: public');
        readfile($realPath);
        exit;
    }

    // Streaming de Audio com Transcoding de Alta Compatibilidade (Chrome/Edge/Firefox)
    $ext = strtolower(pathinfo($realPath, PATHINFO_EXTENSION));

    // 1. Tentar SoX forcao conversao para PCM 16-bit WAV (8000Hz, Mono, Signed Integer)
    $sox = trim(@shell_exec('which sox 2>/dev/null'));
    if (empty($sox) && file_exists('/usr/bin/sox')) $sox = '/usr/bin/sox';
    if (!empty($sox) && is_executable($sox)) {
        header('Content-Type: audio/wav');
        header('Content-Disposition: inline; filename="call_audio.wav"');
        header('Cache-Control: no-cache, must-revalidate');
        header('Pragma: no-cache');
        passthru(escapeshellcmd($sox) . ' ' . escapeshellarg($realPath) . ' -t wav -e signed-integer -b 16 -r 8000 -c 1 - 2>/dev/null');
        exit;
    }

    // 2. Tentar FFmpeg para conversao em tempo real para PCM WAV
    $ffmpeg = trim(@shell_exec('which ffmpeg 2>/dev/null'));
    if (!empty($ffmpeg) && is_executable($ffmpeg)) {
        header('Content-Type: audio/wav');
        header('Content-Disposition: inline; filename="call_audio.wav"');
        header('Cache-Control: no-cache, must-revalidate');
        header('Pragma: no-cache');
        passthru(escapeshellcmd($ffmpeg) . ' -i ' . escapeshellarg($realPath) . ' -f wav -acodec pcm_s16le -ar 8000 -ac 1 - 2>/dev/null');
        exit;
    }

    // 3. Tentar LAME para MP3
    $lame = trim(@shell_exec('which lame 2>/dev/null'));
    if (!empty($lame) && is_executable($lame)) {
        header('Content-Type: audio/mpeg');
        header('Content-Disposition: inline; filename="call_audio.mp3"');
        header('Cache-Control: no-cache, must-revalidate');
        header('Pragma: no-cache');
        passthru(escapeshellcmd($lame) . ' -b 64 ' . escapeshellarg($realPath) . ' - 2>/dev/null');
        exit;
    }

    // 4. Streaming Direto com HTTP Byte-Ranges (Fallback)
    $fileSize = filesize($realPath);
    $mime = ($ext === 'mp3') ? 'audio/mpeg' : (($ext === 'ogg') ? 'audio/ogg' : 'audio/wav');
    $offset = 0;
    $length = $fileSize;

    if (isset($_SERVER['HTTP_RANGE'])) {
        preg_match('/bytes=(\d+)-(\d+)?/', $_SERVER['HTTP_RANGE'], $matches);
        $offset = intval($matches[1]);
        $end = (isset($matches[2]) && $matches[2] !== '') ? intval($matches[2]) : ($fileSize - 1);
        $length = $end - $offset + 1;
        http_response_code(206);
        header('HTTP/1.1 206 Partial Content');
        header("Content-Range: bytes $offset-$end/$fileSize");
    } else {
        http_response_code(200);
    }

    header('Content-Type: ' . $mime);
    header('Content-Length: ' . $length);
    header('Content-Disposition: inline; filename="' . basename($realPath) . '"');
    header('Accept-Ranges: bytes');
    header('Cache-Control: no-cache, must-revalidate');

    $fp = fopen($realPath, 'rb');
    fseek($fp, $offset);
    $bufferSize = 8192;
    $bytesSent = 0;
    while (!feof($fp) && $bytesSent < $length) {
        $read = min($bufferSize, $length - $bytesSent);
        echo fread($fp, $read);
        flush();
        $bytesSent += $read;
    }
    fclose($fp);
    exit;
}


$hoje               = date('Y-m-d');
$dataInicioDefault  = date('Y-m-01');
$dataInicio   = isset($_GET['data_inicio'])   ? $_GET['data_inicio']   : $dataInicioDefault;
$dataFim      = isset($_GET['data_fim'])      ? $_GET['data_fim']      : $hoje;
$export       = isset($_GET['export'])        ? $_GET['export']        : '';
$agenteFiltro = isset($_GET['agente_filtro']) ? trim($_GET['agente_filtro']) : '';
$statusFiltro = isset($_GET['status_filtro']) ? trim($_GET['status_filtro']) : '';
$numeroFiltro = isset($_GET['numero_filtro']) ? trim($_GET['numero_filtro']) : '';

$filaFiltro   = isset($_GET['fila']) ? (is_array($_GET['fila']) ? $_GET['fila'] : array($_GET['fila'])) : array();

$dataInicioEsc = mysqli_real_escape_string($conn, $dataInicio);
$dataFimEsc    = mysqli_real_escape_string($conn, $dataFim);

// --- Mapeamentos das tabelas de dimensão -------------------------------------------
function buildMap($query, $keyCol, $valCol, $conn) {
    $map = array();
    $r = mysqli_query($conn, $query);
    if ($r) while ($row = mysqli_fetch_assoc($r)) $map[$row[$keyCol]] = $row[$valCol];
    return $map;
}
$agentMap = buildMap("SELECT agent_id, agent FROM qagent", 'agent_id', 'agent', $conn);
$eventMap = buildMap("SELECT event_id, event FROM qevent", 'event_id', 'event', $conn);
$qnameMapRaw = buildMap("SELECT qname_id, queue FROM qname", 'qname_id', 'queue', $conn);

// --- Mapeamento de Descrição REAL das Filas do Sistema --------------------------
$queueDescrMap = array();

// 1. Estrutura Chave-Valor do Issabel/FreePBX (asterisk.queues_config com keyword = 'description' ou 'displayname')
$rQConfigKV = @mysqli_query($conn, "SELECT extension, value FROM asterisk.queues_config WHERE keyword IN ('description', 'displayname')");
if ($rQConfigKV) {
    while ($row = mysqli_fetch_assoc($rQConfigKV)) {
        $ext = trim($row['extension']);
        $desc = trim($row['value']);
        if ($ext != '' && $desc != '' && !isset($queueDescrMap[$ext])) {
            $queueDescrMap[$ext] = $desc;
        }
    }
}

// 2. Estrutura tradicional (asterisk.queues_config com coluna descr)
$rQConfig = @mysqli_query($conn, "SELECT extension, descr FROM asterisk.queues_config");
if ($rQConfig) {
    while ($row = mysqli_fetch_assoc($rQConfig)) {
        $ext = trim($row['extension']);
        $desc = trim($row['descr']);
        if ($ext != '' && $desc != '' && !isset($queueDescrMap[$ext])) {
            $queueDescrMap[$ext] = $desc;
        }
    }
}

// 3. Tabela asterisk.queues
$rQueues = @mysqli_query($conn, "SELECT queue, description FROM asterisk.queues");
if ($rQueues) {
    while ($row = mysqli_fetch_assoc($rQueues)) {
        $ext = trim($row['queue']);
        $desc = trim($row['description']);
        if ($ext != '' && $desc != '' && !isset($queueDescrMap[$ext])) {
            $queueDescrMap[$ext] = $desc;
        }
    }
}

// 4. Tabela asterisk.incoming
$rQInc = @mysqli_query($conn, "SELECT destination, description FROM asterisk.incoming WHERE destination LIKE 'ext-queues%'");
if ($rQInc) {
    while ($row = mysqli_fetch_assoc($rQInc)) {
        if (preg_match('/ext-queues,(\d+),/', $row['destination'], $m)) {
            $ext = $m[1];
            $desc = trim($row['description']);
            if ($ext != '' && $desc != '' && !isset($queueDescrMap[$ext])) {
                $queueDescrMap[$ext] = $desc;
            }
        }
    }
}

// Função para retornar a descrição amigável ao passar o mouse (tooltip)
function getQueueDescription($qRaw, $queueDescrMap) {
    $qTrim = trim($qRaw);
    if ($qTrim === 'NONE' || $qTrim === '') return 'Sem Fila (NONE)';
    if (isset($queueDescrMap[$qTrim]) && $queueDescrMap[$qTrim] != '' && strcasecmp($queueDescrMap[$qTrim], $qTrim) !== 0) {
        return $queueDescrMap[$qTrim] . ' (' . $qTrim . ')';
    }
    return 'Fila ' . $qTrim;
}

// --- Mapeamento de Ramais (Banco Asterisk.devices) ---------------------------------------------
$ramalMap = array();
$rDevices = @mysqli_query($conn, "SELECT user, description FROM asterisk.devices");
if ($rDevices) {
    while ($row = mysqli_fetch_assoc($rDevices)) {
        $descKey = strtoupper(trim($row['description']));
        if ($descKey != '') {
            $ramalMap[$descKey] = $row['user'];
        }
    }
}

// --- Lista de filas e agentes para o formulário --------------------------------------
$filas = array();
$knownQueueNums = array();

$rFilas = mysqli_query($conn, "SELECT qname_id, queue FROM qname WHERE queue != 'NONE' ORDER BY queue");
if ($rFilas) {
    while ($row = mysqli_fetch_assoc($rFilas)) {
        $qRaw = $row['queue'];
        $row['queue_num'] = $qRaw;
        $row['queue_descr'] = getQueueDescription($qRaw, $queueDescrMap);
        $filas[] = $row;
        $knownQueueNums[$qRaw] = true;
    }
}

foreach ($queueDescrMap as $ext => $descr) {
    if (!isset($knownQueueNums[$ext]) && $ext != '' && $ext != 'NONE') {
        $filas[] = array(
            'qname_id' => $ext,
            'queue' => $ext,
            'queue_num' => $ext,
            'queue_descr' => getQueueDescription($ext, $queueDescrMap)
        );
        $knownQueueNums[$ext] = true;
    }
}

$agentesLista = array();
$rAgentes = mysqli_query($conn, "SELECT DISTINCT agent FROM qagent WHERE agent != 'NONE' AND agent != '' ORDER BY agent");
if ($rAgentes) while ($row = mysqli_fetch_assoc($rAgentes)) $agentesLista[] = $row['agent'];

// --- Condição WHERE base ------------------------------------------------------------
$whereCond  = "WHERE DATE(qs.datetime) BETWEEN '$dataInicioEsc' AND '$dataFimEsc'";

$selectedQnameIds = array();
if (count($filaFiltro) > 0) {
    foreach ($filaFiltro as $f) {
        $fClean = trim($f);
        if ($fClean === '') continue;
        foreach ($qnameMapRaw as $qId => $qNum) {
            if (strval($qId) === $fClean || strval($qNum) === $fClean) {
                $selectedQnameIds[] = intval($qId);
            }
        }
    }
}
$selectedQnameIds = array_unique($selectedQnameIds);

if (count($selectedQnameIds) > 0) {
    $whereCond .= " AND qs.qname IN (" . implode(',', $selectedQnameIds) . ")";
}

// --- Nomes das filas para exibição no topo ----------------------------------------------------
$numsFilasSelecionadas = array();
$descrsFilasSelecionadas = array();
foreach($filaFiltro as $f) {
    if($f != '') {
        $qRaw = isset($qnameMapRaw[$f]) ? $qnameMapRaw[$f] : $f;
        $numsFilasSelecionadas[] = $qRaw;
        $descrsFilasSelecionadas[] = getQueueDescription($qRaw, $queueDescrMap);
    }
}
$textoFilasExibicao = count($numsFilasSelecionadas) > 0 ? implode(', ', $numsFilasSelecionadas) : 'Todas as Filas';
$textoFilasTooltip  = count($descrsFilasSelecionadas) > 0 ? implode(' | ', $descrsFilasSelecionadas) : 'Todas as Filas de Atendimento';

// --- QUERY PRINCIPAL - Detalhes de chamadas ----------------------------------------------
$sqlDetalhe = "
SELECT
    qs.uniqueid,
    qs.datetime,
    qs.qname   AS qname_id,
    qs.qagent  AS agent_id,
    qs.qevent  AS event_id,
    qs.info1,
    qs.info2,
    qs.info3
FROM queue_stats qs
$whereCond
ORDER BY qs.datetime DESC, qs.uniqueid DESC
LIMIT 5000
";

$rDetalhe = mysqli_query($conn, $sqlDetalhe);

$chamadas = array();
$agentRingNoAnswer = array(); 

if ($rDetalhe) {
    while ($row = mysqli_fetch_assoc($rDetalhe)) {
        $uid   = $row['uniqueid'];
        $ev    = isset($eventMap[$row['event_id']]) ? $eventMap[$row['event_id']] : $row['event_id'];
        $agent = isset($agentMap[$row['agent_id']]) ? $agentMap[$row['agent_id']] : $row['agent_id'];
        $rawQueue = isset($qnameMapRaw[$row['qname_id']]) ? $qnameMapRaw[$row['qname_id']] : $row['qname_id'];
        $descrQueue = getQueueDescription($rawQueue, $queueDescrMap);

        if ($ev == 'RINGNOANSWER' && $agent != 'NONE' && $agent != '') {
            if (!isset($agentRingNoAnswer[$agent])) $agentRingNoAnswer[$agent] = 0;
            $agentRingNoAnswer[$agent]++;
        }

        if (!isset($chamadas[$uid])) {
            $chamadas[$uid] = array(
                'uniqueid'       => $uid,
                'datetime'       => $row['datetime'],
                'fila_num'       => $rawQueue,
                'fila_descr'     => $descrQueue,
                'numero'         => '',
                'agente'         => '',
                'ramal'          => '',
                'status'         => 'ABANDONADA',
                'tempo_espera'   => 0,
                'tempo_falando'  => 0,
                'quem_desligou'  => '',
                'eventos'        => array()
            );
        }

        $chamadas[$uid]['eventos'][] = array('event' => $ev, 'agent' => $agent, 'info1' => $row['info1'], 'info2' => $row['info2'], 'info3' => $row['info3'], 'dt' => $row['datetime']);

        switch ($ev) {
            case 'ENTERQUEUE':
                if ($row['info2'] != '' && $row['info2'] != 'NONE') $chamadas[$uid]['numero'] = $row['info2'];
                if ($row['info1'] != '' && $row['info1'] != 'NONE') $chamadas[$uid]['numero'] = $row['info1']; 
                $chamadas[$uid]['datetime'] = $row['datetime'];
                break;
            case 'CONNECT':
                $chamadas[$uid]['agente']       = $agent;
                $descKey = strtoupper(trim($agent));
                $chamadas[$uid]['ramal']        = isset($ramalMap[$descKey]) ? $ramalMap[$descKey] : '-';
                $chamadas[$uid]['status']       = 'ATENDIDA';
                $chamadas[$uid]['tempo_espera'] = intval($row['info1']);
                break;
            case 'COMPLETECALLER':
                $chamadas[$uid]['status']       = 'ATENDIDA';
                $chamadas[$uid]['quem_desligou'] = 'CLIENTE';
                $chamadas[$uid]['tempo_falando'] = intval($row['info2']);
                if ($chamadas[$uid]['tempo_espera'] == 0) $chamadas[$uid]['tempo_espera'] = intval($row['info1']);
                break;
            case 'COMPLETEAGENT':
                $chamadas[$uid]['status']       = 'ATENDIDA';
                $chamadas[$uid]['quem_desligou'] = 'AGENTE';
                $chamadas[$uid]['tempo_falando'] = intval($row['info2']);
                if ($chamadas[$uid]['tempo_espera'] == 0) $chamadas[$uid]['tempo_espera'] = intval($row['info1']);
                break;
            case 'ABANDON':
                $chamadas[$uid]['status']       = 'ABANDONADA';
                $chamadas[$uid]['quem_desligou'] = 'CLIENTE';
                $chamadas[$uid]['tempo_espera'] = intval($row['info3']);
                break;
            case 'EXITWITHTIMEOUT':
                $chamadas[$uid]['status']       = 'TIMEOUT';
                $chamadas[$uid]['tempo_espera'] = intval($row['info3']);
                break;
        }
    }
}
$chamadas = array_values($chamadas);

// --- Complemento Inteligente via CDR (Resgatar BINA e Ramal de chamadas transferidas/capturadas) ---
$uidsMissing = array();
foreach ($chamadas as $idx => $c) {
    if (empty($c['numero']) || $c['numero'] == 'NONE' || empty($c['agente']) || $c['agente'] == 'NONE') {
        $uidsMissing[$c['uniqueid']] = $idx;
    }
}

if (!empty($uidsMissing)) {
    $uidsEsc = array_map(function($u) use ($conn) { return "'" . mysqli_real_escape_string($conn, $u) . "'"; }, array_keys($uidsMissing));
    $sqlCdr = "SELECT uniqueid, src, clid, dst, dstchannel FROM asteriskcdrdb.cdr WHERE uniqueid IN (" . implode(',', $uidsEsc) . ")";
    $rCdr = @mysqli_query($conn, $sqlCdr);
    if ($rCdr) {
        while ($rowCdr = mysqli_fetch_assoc($rCdr)) {
            $uid = $rowCdr['uniqueid'];
            if (isset($uidsMissing[$uid])) {
                $idx = $uidsMissing[$uid];
                
                // Resgatar Numero/BINA se estiver vazio no log da fila
                if (empty($chamadas[$idx]['numero']) || $chamadas[$idx]['numero'] == 'NONE') {
                    $srcClean = trim($rowCdr['src']);
                    if ($srcClean != '' && $srcClean != 's' && $srcClean != 'NONE') {
                        $chamadas[$idx]['numero'] = $srcClean;
                    } elseif (preg_match('/<(\d+)>/', $rowCdr['clid'], $m)) {
                        $chamadas[$idx]['numero'] = $m[1];
                    }
                }
                
                // Resgatar Ramal/Agente se estiver vazio no log da fila
                if (empty($chamadas[$idx]['agente']) || $chamadas[$idx]['agente'] == 'NONE') {
                    if (preg_match('/(?:SIP|PJSIP|IAX2|Local)\/(\d+)/i', $rowCdr['dstchannel'], $m)) {
                        $ramalFound = $m[1];
                        $chamadas[$idx]['agente'] = 'Ramal ' . $ramalFound;
                        $chamadas[$idx]['ramal']  = $ramalFound;
                    }
                }
            }
        }
    }
}

// --- Aplicar Filtros Adicionais PHP (Agente, Status, Numero) ----------------------------
if ($agenteFiltro != '' || $statusFiltro != '' || $numeroFiltro != '') {
    $chamadas = array_filter($chamadas, function($c) use ($agenteFiltro, $statusFiltro, $numeroFiltro) {
        if ($agenteFiltro != '' && strcasecmp($c['agente'], $agenteFiltro) !== 0) return false;
        if ($statusFiltro != '' && strcasecmp($c['status'], $statusFiltro) !== 0) return false;
        if ($numeroFiltro != '' && strpos($c['numero'], $numeroFiltro) === false) return false;
        return true;
    });
    $chamadas = array_values($chamadas);
}

// --- MÉTRICAS RESUMO E SLA -----------------------------------------------------------
$totalChamadas    = count($chamadas);
$totalAtendidas   = 0;
$totalAbandonadas = 0;
$totalTimeout     = 0;
$totalSla20       = 0;
$somaEspera       = 0;
$somaFalando      = 0;
$agentStats       = array(); 
$queueStats       = array();

foreach ($chamadas as $c) {
    $qKey = $c['fila_num'] ?: 'NONE';
    if (!isset($queueStats[$qKey])) {
        $queueStats[$qKey] = array('num' => $qKey, 'descr' => $c['fila_descr'], 'total' => 0, 'atendidas' => 0, 'abandonadas' => 0, 'timeout' => 0, 'sla20' => 0, 'soma_espera' => 0, 'soma_fala' => 0);
    }
    $queueStats[$qKey]['total']++;

    if ($c['status'] == 'ATENDIDA') {
        $totalAtendidas++;
        $queueStats[$qKey]['atendidas']++;
        if ($c['tempo_espera'] <= 20) {
            $totalSla20++;
            $queueStats[$qKey]['sla20']++;
        }
    } elseif ($c['status'] == 'TIMEOUT') {
        $totalTimeout++;
        $queueStats[$qKey]['timeout']++;
    } else {
        $totalAbandonadas++;
        $queueStats[$qKey]['abandonadas']++;
    }

    $somaEspera   += $c['tempo_espera'];
    $somaFalando  += $c['tempo_falando'];
    $queueStats[$qKey]['soma_espera'] += $c['tempo_espera'];
    $queueStats[$qKey]['soma_fala']   += $c['tempo_falando'];

    if ($c['status'] == 'ATENDIDA') {
        $ag = $c['agente'];
        if (!isset($agentStats[$ag])) $agentStats[$ag] = array('atendidas' => 0, 'nao_atendidas' => 0, 'tempo' => 0);
        $agentStats[$ag]['atendidas']++;
        $agentStats[$ag]['tempo'] += $c['tempo_falando'];
    }
}

foreach ($agentRingNoAnswer as $ag => $perdidas) {
    if (!isset($agentStats[$ag])) {
        $agentStats[$ag] = array('atendidas' => 0, 'nao_atendidas' => 0, 'tempo' => 0);
    }
    $agentStats[$ag]['nao_atendidas'] += $perdidas;
}

$pctAtendimento = $totalChamadas > 0 ? round(($totalAtendidas / $totalChamadas) * 100, 1) : 0;
$pctAbandono    = $totalChamadas > 0 ? round(($totalAbandonadas / $totalChamadas) * 100, 1) : 0;
$pctSla20       = $totalAtendidas > 0 ? round(($totalSla20 / $totalAtendidas) * 100, 1) : 0;
$mediaEspera    = $totalChamadas > 0 ? round($somaEspera / $totalChamadas) : 0;
$mediaFalando   = $totalAtendidas > 0 ? round($somaFalando / $totalAtendidas) : 0;

// --- EXPORTAÇÃO EXCEL --------------------------------------------------------------------
if ($export == 'excel') {
    header('Content-Type: application/vnd.ms-excel; charset=UTF-8');
    header('Content-Disposition: attachment; filename="relatorio_fila_' . $dataInicio . '_' . $dataFim . '.xls"');
    header('Pragma: no-cache');
    echo "\xEF\xBB\xBF"; 

    echo "UniqueID\tData/Hora\tFila\tNome da Fila\tNumero\tAgente\tRamal\tStatus\tEspera(s)\tDuracao(s)\tQuem Desligou\tGravacao Disponivel\n";

    foreach ($chamadas as $c) {
        $temAudio = ($c['status'] == 'ATENDIDA') ? 'SIM' : 'NAO';
        echo implode("\t", array(
            $c['uniqueid'],
            $c['datetime'],
            $c['fila_num'],
            $c['fila_descr'],
            $c['numero'],
            $c['agente'],
            $c['ramal'],
            $c['status'],
            $c['tempo_espera'],
            $c['tempo_falando'],
            $c['quem_desligou'],
            $temAudio
        )) . "\n";
    }
    echo "\n"; 
    echo "RESUMO GERAL IPBX PRISMA\n";
    echo "Total de Chamadas:\t" . $totalChamadas . "\n";
    echo "Atendidas:\t" . $totalAtendidas . "\t" . $pctAtendimento . "%\n";
    echo "Abandonadas:\t" . $totalAbandonadas . "\t" . $pctAbandono . "%\n";
    echo "Timeout:\t" . $totalTimeout . "\n";
    echo "SLA (<=20s):\t" . $pctSla20 . "%\n";
    echo "Media Espera:\t" . gmdate('i:s', $mediaEspera) . "\n";
    echo "Media Duracao:\t" . gmdate('i:s', $mediaFalando) . "\n";

    exit;
}

// --- EXPORTAÇÃO PDF -------------------------------------------------------------------
if ($export == 'pdf') {
    header('Content-Type: text/html; charset=UTF-8');
    ?>
<!DOCTYPE html>
<html>
<head>
<meta charset="UTF-8">
<title>Relat&oacute;rio PDF - IPbx Prisma</title>
<style>
    body{font-family:Arial,sans-serif;font-size:10px;margin:10px;}
    h1{font-size:14px;text-align:center;color:#1a3a5c;}
    h2{font-size:12px;margin-top:15px;color:#2e86c1;}
    table{width:100%;border-collapse:collapse;margin-bottom:10px;}
    th{background:#f1f2f6;color:#333;padding:4px;text-align:left;font-size:9px;border-bottom:2px solid #ccc;}
    td{padding:3px 4px;border-bottom:1px solid #ddd;font-size:9px;}
    tr:nth-child(even){background:#f9f9f9;}
    .kpi{display:inline-block;width:95px;border:1px solid #ccc;padding:5px;margin:2px;text-align:center;border-radius:4px;}
    .kpi-val{font-size:15px;font-weight:bold;color:#1a3a5c;}
    .kpi-lbl{font-size:8px;color:#666;}
    @media print{.no-print{display:none;}}
    .badge-atendida{color:green;font-weight:bold;}
    .badge-abandonada{color:red;font-weight:bold;}
    .badge-timeout{color:orange;font-weight:bold;}
</style>
<script>window.onload=function(){window.print();}</script>
</head>
<body>
<div class="no-print" style="padding:10px;background:#eee;margin-bottom:15px;">
    <button onclick="window.print()">Imprimir / Salvar PDF</button>
    <button onclick="window.history.back()">&larr; Voltar</button>
</div>
<h1>Relat&oacute;rio de Fila - IPbx Prisma</h1>
<p style="text-align:center;color:#666;">Per&iacute;odo: <?php echo $dataInicio; ?> at&eacute; <?php echo $dataFim; ?> | Fila(s): <?php echo htmlspecialchars($textoFilasExibicao); ?></p>

<h2>Resumo Geral</h2>
<div>
    <div class="kpi" title="Total chamadas per&iacute;odo: Volume total de liga&ccedil;&otilde;es que entraram nas filas selecionadas no per&iacute;odo."><div class="kpi-val"><?php echo $totalChamadas; ?></div><div class="kpi-lbl">Total Chamadas</div></div>
    <div class="kpi" title="Chamadas Atendidas: Quantidade e porcentagem de chamadas conversadas com atendentes."><div class="kpi-val" style="color:green;"><?php echo $totalAtendidas; ?></div><div class="kpi-lbl">Atendidas (<?php echo $pctAtendimento; ?>%)</div></div>
    <div class="kpi" title="Chamadas Abandonadas: Liga&ccedil;&otilde;es em que o cliente desligou enquanto aguardava na fila."><div class="kpi-val" style="color:red;"><?php echo $totalAbandonadas; ?></div><div class="kpi-lbl">Abandonadas (<?php echo $pctAbandono; ?>%)</div></div>
    <div class="kpi" title="Chamadas Timeout: Liga&ccedil;&otilde;es que atingiram o tempo limite m&aacute;ximo da fila."><div class="kpi-val" style="color:orange;"><?php echo $totalTimeout; ?></div><div class="kpi-lbl">Timeout</div></div>
    <div class="kpi" title="N&iacute;vel de Servi&ccedil;o (SLA &lt;=20s): Chamadas atendidas em at&eacute; 20 segundos de espera."><div class="kpi-val" style="color:#2980b9;"><?php echo $pctSla20; ?>%</div><div class="kpi-lbl">SLA (&lt;=20s)</div></div>
    <div class="kpi" title="M&eacute;dia Espera: Tempo m&eacute;dio que os clientes aguardaram na fila."><div class="kpi-val"><?php echo gmdate('i:s',$mediaEspera); ?></div><div class="kpi-lbl">M&eacute;dia Espera</div></div>
    <div class="kpi" title="M&eacute;dia Fala (TMA): Tempo M&eacute;dio de Atendimento entre atendentes e clientes."><div class="kpi-val"><?php echo gmdate('i:s',$mediaFalando); ?></div><div class="kpi-lbl">M&eacute;dia Fala</div></div>
</div>

<h2>Detalhamento de Chamadas</h2>
<table>
    <thead><tr><th>#</th><th>Data/Hora</th><th>Fila</th><th>N&uacute;mero</th><th>Agente</th><th>Ramal</th><th>Status</th><th>Espera</th><th>Fala</th><th>Desligou</th></tr></thead>
    <tbody>
    <?php $i=1; foreach ($chamadas as $c): ?>
    <tr>
        <td><?php echo $i++; ?></td>
        <td><?php echo $c['datetime']; ?></td>
        <td title="<?php echo htmlspecialchars($c['fila_descr']); ?>"><?php echo htmlspecialchars($c['fila_num']); ?></td>
        <td><?php echo htmlspecialchars($c['numero']); ?></td>
        <td><?php echo htmlspecialchars($c['agente'] ?: '-'); ?></td>
        <td><?php echo htmlspecialchars($c['ramal'] ?: '-'); ?></td>
        <td class="badge-<?php echo strtolower($c['status']); ?>"><?php echo $c['status']; ?></td>
        <td><?php echo gmdate('i:s',$c['tempo_espera']); ?></td>
        <td><?php echo $c['tempo_falando']>0 ? gmdate('i:s',$c['tempo_falando']) : '-'; ?></td>
        <td><?php echo htmlspecialchars($c['quem_desligou']); ?></td>
    </tr>
    <?php endforeach; ?>
    </tbody>
</table>
</body>
</html>
    <?php
    exit;
}

// --- Dados para Gráficos (JSON) ---------------------------------------------------------
$porHora = array();
for ($h = 0; $h < 24; $h++) $porHora[$h] = array('atendidas' => 0, 'abandonadas' => 0, 'timeout' => 0);
foreach ($chamadas as $c) {
    $hora = intval(date('G', strtotime($c['datetime'])));
    if ($c['status'] == 'ATENDIDA')    $porHora[$hora]['atendidas']++;
    elseif ($c['status'] == 'TIMEOUT') $porHora[$hora]['timeout']++;
    else                               $porHora[$hora]['abandonadas']++;
}
$horasLabels    = json_encode(array_map(function($h){ return str_pad($h,2,'0',STR_PAD_LEFT).':00'; }, array_keys($porHora)));
$horasAtendidas = json_encode(array_map(function($v){ return $v['atendidas']; }, $porHora));
$horasAbandono  = json_encode(array_map(function($v){ return $v['abandonadas']; }, $porHora));
$horasTimeout   = json_encode(array_map(function($v){ return $v['timeout']; }, $porHora));

$agentChartData = array();
foreach ($agentStats as $ag => $st) {
    if ($ag == 'SEM AGENTE' || $ag == 'NONE') continue;
    $agentChartData[$ag] = $st;
}
arsort($agentChartData);
$agLabels = array(); $agAtend = array(); $agNaoAtend = array(); $cnt = 0;
foreach ($agentChartData as $ag => $st) {
    $agLabels[] = $ag; $agAtend[] = $st['atendidas']; $agNaoAtend[] = $st['nao_atendidas'];
    if (++$cnt >= 15) break;
}
$agLabelsJson   = json_encode($agLabels);
$agAtendJson    = json_encode($agAtend);
$agNaoAtendJson = json_encode($agNaoAtend);
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Relat&oacute;rio de Fila - IPbx Prisma</title>
<script src="https://cdn.jsdelivr.net/npm/chart.js@2.9.4/dist/Chart.min.js"></script>
<style>
:root {
    --bg-body: #f4f6f9;
    --bg-card: #ffffff;
    --text-color: #333333;
    --text-muted: #666666;
    --border-color: #e0e0e0;
    --header-bg: #ffffff;
    --table-header-bg: #f1f2f6;
    --table-stripe: #fafbfc;
    --table-hover: #f1f8ff;
    --primary-blue: #2e86c1;
    --accent-orange: #e67e22;
}

body.dark-mode {
    --bg-body: #0f172a;
    --bg-card: #1e293b;
    --text-color: #f1f5f9;
    --text-muted: #94a3b8;
    --border-color: #334155;
    --header-bg: #0f172a;
    --table-header-bg: #334155;
    --table-stripe: #1e293b;
    --table-hover: #334155;
    --primary-blue: #38bdf8;
    --accent-orange: #f97316;
}

*{box-sizing:border-box;margin:0;padding:0;}
body{
    font-family:'Segoe UI',Tahoma,Geneva,Verdana,sans-serif;
    background:var(--bg-body); 
    color:var(--text-color); 
    min-height:100vh;
    transition:background .3s, color .3s;
}

/* --- Header ---------------------------------------------------- */
.header{
    background:var(--header-bg);
    padding:15px 30px;
    display:flex;
    align-items:center;
    justify-content:space-between;
    border-bottom:3px solid var(--primary-blue);
    box-shadow:0 2px 10px rgba(0,0,0,0.05);
}
.header h1{
    font-size:22px;
    font-weight:700;
    color:var(--text-color);
    letter-spacing:1px;
    display:flex;
    align-items:center;
}
.header h1 span{color:var(--accent-orange);}
.header-tools{display:flex;align-items:center;gap:8px;}

/* --- Filter Bar -------------------------------------------------- */
.filter-bar{
    background:var(--bg-card);
    padding:15px 30px;
    display:flex;
    flex-wrap:wrap;
    gap:15px;
    align-items:flex-end;
    border-bottom:1px solid var(--border-color);
    box-shadow:0 1px 3px rgba(0,0,0,0.03);
}
.filter-group{display:flex;flex-direction:column;gap:4px;}
.filter-group label{font-size:11px;color:var(--text-muted);text-transform:uppercase;font-weight:600;letter-spacing:.5px;}
.filter-group input,
.filter-group select{
    background:var(--bg-card);
    border:1px solid var(--border-color);
    color:var(--text-color);
    padding:6px 10px;
    border-radius:5px;
    font-size:13px;
    min-width:130px;
}
.filter-group input:focus,
.filter-group select:focus{outline:none;border-color:var(--primary-blue);}

.btn{
    padding:7px 14px;
    border:none;
    border-radius:5px;
    cursor:pointer;
    font-size:13px;
    font-weight:600;
    transition:all .2s;
    height:34px;
    display:inline-flex;
    align-items:center;
    justify-content:center;
    gap:5px;
    text-decoration:none;
}
.btn-primary{background:#2e86c1;color:#fff;}
.btn-primary:hover{background:#1a6fa0;}
.btn-excel{background:#1e8449;color:#fff;}
.btn-excel:hover{background:#196f3d;}
.btn-pdf{background:#922b21;color:#fff;}
.btn-pdf:hover{background:#7b241c;}
.btn-outline{background:transparent;border:1px solid var(--border-color);color:var(--text-color);}
.btn-outline:hover{background:var(--table-hover);}

/* --- Main -------------------------------------------------- */
.main{padding:25px 30px;}
.section-title{
    font-size:16px;
    font-weight:700;
    color:var(--text-color);
    margin-bottom:15px;
    padding-bottom:8px;
    border-bottom:2px solid var(--border-color);
    display:flex;
    align-items:center;
    gap:8px;
}
.section-title::before{content:'';display:block;width:4px;height:18px;background:var(--accent-orange);border-radius:2px;}

/* --- KPI Cards ------------------------------------------------ */
.kpi-grid{
    display:grid;
    grid-template-columns:repeat(auto-fill,minmax(140px,1fr));
    gap:15px;
    margin-bottom:30px;
}
.kpi-card{
    background:var(--bg-card);
    border:1px solid var(--border-color);
    border-radius:10px;
    padding:16px 12px;
    text-align:center;
    transition:transform .2s,box-shadow .2s;
    position:relative;
    overflow:hidden;
    box-shadow:0 2px 5px rgba(0,0,0,0.02);
    cursor:help;
}
.kpi-card::before{
    content:'';
    position:absolute;
    top:0;left:0;right:0;
    height:3px;
    background:var(--accent,#2e86c1);
}
.kpi-card:hover{transform:translateY(-2px);box-shadow:0 8px 15px rgba(0,0,0,0.1);}
.kpi-val{font-size:26px;font-weight:800;color:var(--accent,#1a3a5c);line-height:1;}
.kpi-lbl{font-size:10px;color:var(--text-muted);margin-top:6px;text-transform:uppercase;letter-spacing:.5px;font-weight:600;}
.kpi-sub{font-size:10px;color:var(--text-muted);margin-top:3px;}

/* --- Charts Grid ---------------------------------------------- */
.charts-grid{
    display:grid;
    grid-template-columns:1fr 1fr;
    gap:20px;
    margin-bottom:30px;
}
.chart-card{
    background:var(--bg-card);
    border:1px solid var(--border-color);
    border-radius:10px;
    padding:20px;
    box-shadow:0 2px 5px rgba(0,0,0,0.02);
}
.chart-card.wide{grid-column:1/-1;}
.chart-title{font-size:13px;font-weight:700;color:var(--text-muted);margin-bottom:15px;text-transform:uppercase;letter-spacing:.5px;}
.chart-wrap{position:relative;height:220px;}
.chart-wrap-tall{position:relative;height:300px;}

/* --- Progress Ring ----------------------------------------- */
.ring-wrap{display:flex;gap:20px;justify-content:center;align-items:center;flex-wrap:wrap;}
.ring-item{text-align:center;}
.ring-item svg{display:block;margin:auto;}
.ring-lbl{font-size:11px;font-weight:700;margin-top:6px;text-transform:uppercase;}

/* --- Table -------------------------------------------------- */
.table-card{
    background:var(--bg-card);
    border:1px solid var(--border-color);
    border-radius:10px;
    overflow:hidden;
    margin-bottom:30px;
    box-shadow:0 2px 5px rgba(0,0,0,0.02);
}
.table-header{
    display:flex;
    justify-content:space-between;
    align-items:center;
    padding:15px 20px;
    background:var(--bg-card);
    border-bottom:1px solid var(--border-color);
}
.table-search{
    background:var(--bg-card);
    border:1px solid var(--border-color);
    color:var(--text-color);
    padding:6px 12px;
    border-radius:5px;
    font-size:12px;
    width:220px;
}
.table-scroll{overflow-x:auto;}
table.data-table{width:100%;border-collapse:collapse;}
table.data-table thead tr{background:var(--table-header-bg);}
table.data-table th{
    padding:10px 14px;
    font-size:11px;
    text-align:left;
    color:var(--text-color);
    text-transform:uppercase;
    font-weight:700;
    letter-spacing:.4px;
    white-space:nowrap;
    border-bottom:2px solid var(--border-color);
    cursor:pointer;
    user-select:none;
}
table.data-table th:hover{color:var(--primary-blue);}
table.data-table td{
    padding:9px 14px;
    font-size:12px;
    border-bottom:1px solid var(--border-color);
    white-space:nowrap;
    color:var(--text-color);
}
table.data-table tr:hover td{background:var(--table-hover);}
table.data-table tr:nth-child(even) td{background:var(--table-stripe);}

.badge{
    display:inline-block;
    padding:2px 8px;
    border-radius:12px;
    font-size:10px;
    font-weight:700;
    text-transform:uppercase;
    letter-spacing:.3px;
}
.badge-atendida{background:rgba(30,132,73,.15);color:#27ae60;border:1px solid #27ae60;}
.badge-abandonada{background:rgba(169,50,38,.15);color:#e74c3c;border:1px solid #e74c3c;}
.badge-timeout{background:rgba(175,122,25,.15);color:#f39c12;border:1px solid #f39c12;}
.badge-agente{background:rgba(46,134,193,.15);color:#3498db;border:1px solid #3498db;}
.badge-cliente{background:rgba(142,68,173,.15);color:#9b59b6;border:1px solid #9b59b6;}

.btn-audio{
    padding:3px 8px;
    font-size:11px;
    font-weight:600;
    border-radius:4px;
    cursor:pointer;
    border:none;
    display:inline-flex;
    align-items:center;
    gap:4px;
    text-decoration:none;
}
.btn-play{background:#2e86c1;color:#fff;}
.btn-play:hover{background:#1a6fa0;}
.btn-dl{background:#27ae60;color:#fff;}
.btn-dl:hover{background:#1e8449;}

/* --- Modais -------------------------------------------------- */
.modal-overlay{
    position:fixed;
    top:0;left:0;right:0;bottom:0;
    background:rgba(0,0,0,0.6);
    display:flex;
    align-items:center;
    justify-content:center;
    z-index:9999;
    backdrop-filter:blur(3px);
}
.modal-card{
    background:var(--bg-card);
    border:1px solid var(--border-color);
    border-radius:10px;
    width:90%;
    max-width:480px;
    padding:20px;
    box-shadow:0 10px 25px rgba(0,0,0,0.3);
}
.modal-card.modal-large{max-width:820px;}
.modal-header{
    display:flex;
    justify-content:space-between;
    align-items:center;
    padding-bottom:12px;
    border-bottom:1px solid var(--border-color);
    margin-bottom:15px;
}
.modal-header h3{font-size:16px;color:var(--text-color);}
.modal-close{
    background:none;
    border:none;
    font-size:22px;
    color:var(--text-muted);
    cursor:pointer;
}

.table-footer{
    display:flex;
    justify-content:space-between;
    align-items:center;
    padding:10px 20px;
    background:var(--bg-card);
    border-top:1px solid var(--border-color);
    font-size:12px;
    color:var(--text-muted);
}
.pagination{display:flex;align-items:center;gap:6px;}
.pag-btn{
    padding:5px 12px;
    background:var(--bg-card);
    border:1px solid var(--border-color);
    color:var(--text-color);
    border-radius:4px;
    cursor:pointer;
    font-size:12px;
    font-weight:600;
}
.pag-btn:disabled{opacity:0.5;cursor:not-allowed;}
.pag-btn:not(:disabled):hover{background:var(--primary-blue);color:#fff;border-color:var(--primary-blue);}

@media(max-width:768px){
    .charts-grid{grid-template-columns:1fr;}
    .chart-card.wide{grid-column:1;}
    .filter-bar{flex-direction:column;align-items:stretch;}
}
</style>
</head>
<body>

<div class="header">
    <div>
        <h1>
            Relat&oacute;rio de <span>&nbsp;Fila</span>&nbsp;- IPbx Prisma
        </h1>
        <div style="font-size:12px;color:var(--text-muted);margin-top:4px;">
            qstatslite &middot; Per&iacute;odo: <strong><?php echo $dataInicio; ?></strong> at&eacute; <strong><?php echo $dataFim; ?></strong>
             &middot; Fila(s): <strong style="color:var(--accent-orange);cursor:help;" title="<?php echo htmlspecialchars($textoFilasTooltip); ?>"><?php echo htmlspecialchars($textoFilasExibicao); ?></strong>
        </div>
    </div>
    <div class="header-tools">
        <button type="button" class="btn btn-outline" onclick="toggleAutoRefresh()" id="btnAutoRefresh" title="Alternar atualiza&ccedil;&atilde;o autom&aacute;tica a cada 30s ou 60s (ideal para TV/Monitores de Opera&ccedil;&atilde;o)">
            &#9889; Auto: <span id="lblAutoRefresh">OFF</span>
        </button>
        <button type="button" class="btn btn-outline" onclick="toggleDarkMode()" id="btnThemeToggle" title="Alternar entre o Tema Claro e o Tema Escuro (Dark Mode)">
            &#127767; Tema
        </button>
        <button type="button" class="btn btn-primary" onclick="openManualModal()" title="Abrir o Manual do Usu&aacute;rio interativo do IPbx Prisma">
            &#128214; Manual
        </button>
        <a href="javascript:void(0)" onclick="window.open(window.location.href, '_blank')" class="btn btn-primary" style="background:#16a085;" title="Abrir o relat&oacute;rio em janela inteira e separada da moldura do PABX Issabel">
             &#8599; Expandir Aba
        </a>
    </div>
</div>

<form method="GET" action="">
<div class="filter-bar">
    <div class="filter-group">
        <label title="Data inicial para filtragem das chamadas">Data In&iacute;cio</label>
        <input type="date" name="data_inicio" value="<?php echo htmlspecialchars($dataInicio); ?>">
    </div>
    <div class="filter-group">
        <label title="Data final para filtragem das chamadas">Data Fim</label>
        <input type="date" name="data_fim" value="<?php echo htmlspecialchars($dataFim); ?>">
    </div>
    <div class="filter-group">
        <label title="Selecione uma ou mais filas de atendimento. Passe o mouse sobre cada n&uacute;mero para ver o nome completo do PABX">Filas <span style="font-weight:normal;font-size:9px;color:#888;">(CTRL p/ v&aacute;rias)</span></label>
        <select name="fila[]" multiple size="3" style="height:55px;">
            <?php foreach ($filas as $f): ?>
            <option value="<?php echo $f['qname_id']; ?>" <?php echo in_array($f['qname_id'], $filaFiltro) ? 'selected' : ''; ?> title="<?php echo htmlspecialchars($f['queue_descr']); ?>">
                <?php echo htmlspecialchars($f['queue_num']); ?>
            </option>
            <?php endforeach; ?>
        </select>
    </div>
    <div class="filter-group">
        <label title="Filtrar por atendente da equipe">Agente</label>
        <select name="agente_filtro">
            <option value="">-- Todos --</option>
            <?php foreach ($agentesLista as $ag): ?>
            <option value="<?php echo htmlspecialchars($ag); ?>" <?php echo $agenteFiltro === $ag ? 'selected' : ''; ?>>
                <?php echo htmlspecialchars($ag); ?>
            </option>
            <?php endforeach; ?>
        </select>
    </div>
    <div class="filter-group">
        <label title="Filtrar pelo destino da chamada (Atendida, Abandonada ou Timeout)">Status</label>
        <select name="status_filtro">
            <option value="">-- Todos --</option>
            <option value="ATENDIDA" <?php echo $statusFiltro === 'ATENDIDA' ? 'selected' : ''; ?>>ATENDIDA</option>
            <option value="ABANDONADA" <?php echo $statusFiltro === 'ABANDONADA' ? 'selected' : ''; ?>>ABANDONADA</option>
            <option value="TIMEOUT" <?php echo $statusFiltro === 'TIMEOUT' ? 'selected' : ''; ?>>TIMEOUT</option>
        </select>
    </div>
    <div class="filter-group">
        <label title="Filtrar por n&uacute;mero de telefone de origem/cliente">N&uacute;mero / Telefone</label>
        <input type="text" name="numero_filtro" placeholder="Ex: 11999998888" value="<?php echo htmlspecialchars($numeroFiltro); ?>">
    </div>
    <div style="display:flex;gap:8px;height:55px;align-items:flex-end;">
        <button type="submit" class="btn btn-primary" title="Aplicar os par&acirc;metros e atualizar o relat&oacute;rio">&#128269; Filtrar</button>
        <button type="submit" name="export" value="excel" class="btn btn-excel" title="Exportar tabela detalhada em planilha Excel (.xls)">&#128202; Excel</button>
        <button type="submit" name="export" value="pdf" class="btn btn-pdf" title="Gerar vers&atilde;o otimizada para impress&atilde;o ou salvamento em PDF">&#128196; PDF</button>
    </div>
</div>
</form>

<div class="main">

<div class="section-title">Indicadores Gerais &amp; N&iacute;vel de Servi&ccedil;o (SLA)</div>
<div class="kpi-grid">
    <div class="kpi-card" style="--accent:#3498db;" title="Total chamadas per&iacute;odo: Volume total de liga&ccedil;&otilde;es que entraram nas filas selecionadas no per&iacute;odo consultado.">
        <div class="kpi-val"><?php echo $totalChamadas; ?></div>
        <div class="kpi-lbl">Total Chamadas</div>
    </div>
    <div class="kpi-card" style="--accent:#27ae60;" title="Chamadas Atendidas: Quantidade e porcentagem de chamadas que foram efetivamente conversadas com um atendente da equipe.">
        <div class="kpi-val"><?php echo $totalAtendidas; ?></div>
        <div class="kpi-lbl">Atendidas</div>
        <div class="kpi-sub"><?php echo $pctAtendimento; ?>% do total</div>
    </div>
    <div class="kpi-card" style="--accent:#c0392b;" title="Chamadas Abandonadas: Liga&ccedil;&otilde;es em que o cliente desligou enquanto aguardava atendimento na fila de espera.">
        <div class="kpi-val"><?php echo $totalAbandonadas; ?></div>
        <div class="kpi-lbl">Abandonadas</div>
        <div class="kpi-sub"><?php echo $pctAbandono; ?>% do total</div>
    </div>
    <div class="kpi-card" style="--accent:#f39c12;" title="Chamadas Timeout: Liga&ccedil;&otilde;es que atingiram o tempo limite m&aacute;ximo configurado para a fila e foram encerradas ou transbordadas.">
        <div class="kpi-val"><?php echo $totalTimeout; ?></div>
        <div class="kpi-lbl">Timeout</div>
        <div class="kpi-sub"><?php echo $totalChamadas>0?round(($totalTimeout/$totalChamadas)*100,1):0; ?>%</div>
    </div>
    <div class="kpi-card" style="--accent:#2980b9;" title="N&iacute;vel de Servi&ccedil;o (SLA &lt;=20s): Porcentagem de liga&ccedil;&otilde;es atendidas em at&eacute; 20 segundos de espera na fila. Meta principal de velocidade de resposta.">
        <div class="kpi-val"><?php echo $pctSla20; ?>%</div>
        <div class="kpi-lbl">SLA (&lt;=20s)</div>
        <div class="kpi-sub"><?php echo $totalSla20; ?> chamadas</div>
    </div>
    <div class="kpi-card" style="--accent:#8e44ad;" title="M&eacute;dia Espera: Tempo m&eacute;dio (formatado em mm:ss) que os clientes aguardaram na fila antes de serem atendidos ou desistirem.">
        <div class="kpi-val"><?php echo gmdate('i:s',$mediaEspera); ?></div>
        <div class="kpi-lbl">M&eacute;dia Espera</div>
        <div class="kpi-sub">mm:ss</div>
    </div>
    <div class="kpi-card" style="--accent:#16a085;" title="M&eacute;dia Fala (TMA): Tempo M&eacute;dio de Atendimento. Dura&ccedil;&atilde;o m&eacute;dia (formatada em mm:ss) das conversas entre atendentes e clientes.">
        <div class="kpi-val"><?php echo gmdate('i:s',$mediaFalando); ?></div>
        <div class="kpi-lbl">M&eacute;dia Fala</div>
        <div class="kpi-sub">mm:ss (TMA)</div>
    </div>
    <div class="kpi-card" style="--accent:#2c3e50;" title="Agentes Ativos: N&uacute;mero total de operadores/atendentes que registraram atendimento no per&iacute;odo.">
        <div class="kpi-val"><?php echo count($agentStats); ?></div>
        <div class="kpi-lbl">Agentes Ativos</div>
    </div>
</div>

<div class="charts-grid">
    <div class="chart-card">
        <div class="chart-title">Distribu&ccedil;&atilde;o de Chamadas</div>
        <div class="chart-wrap"><canvas id="chartDonut"></canvas></div>
    </div>
    <div class="chart-card">
        <div class="chart-title">An&eacute;is de N&iacute;vel de Servi&ccedil;o &amp; Atendimento</div>
        <div class="ring-wrap" style="height:220px;display:flex;align-items:center;justify-content:center;gap:30px;">
            <?php
            function svgRing($pct, $color, $label) {
                $r = 40; $cx = 50; $cy = 50; $circ = 2 * M_PI * $r;
                $dash = ($pct / 100) * $circ; $gap = $circ - $dash;
                echo '<div class="ring-item">';
                echo '<svg width="100" height="100" viewBox="0 0 100 100">';
                echo '<circle cx="'.$cx.'" cy="'.$cy.'" r="'.$r.'" fill="none" stroke="var(--border-color)" stroke-width="10"/>';
                echo '<circle cx="'.$cx.'" cy="'.$cy.'" r="'.$r.'" fill="none" stroke="'.$color.'" stroke-width="10" stroke-dasharray="'.$dash.' '.$gap.'" stroke-dashoffset="'.($circ/4).'" stroke-linecap="round"/>';
                echo '<text x="'.$cx.'" y="'.($cy+5).'" text-anchor="middle" font-size="14" font-weight="800" fill="'.$color.'">'.$pct.'%</text>';
                echo '</svg><div class="ring-lbl" style="color:'.$color.';">'.$label.'</div></div>';
            }
            svgRing($pctAtendimento, '#27ae60', 'Atendimento');
            svgRing($pctSla20,       '#2980b9', 'SLA <=20s');
            svgRing($pctAbandono,    '#c0392b', 'Abandono');
            ?>
        </div>
    </div>
    <div class="chart-card wide">
        <div class="chart-title">Chamadas por Hora do Dia</div>
        <div class="chart-wrap-tall"><canvas id="chartHoras"></canvas></div>
    </div>
    <div class="chart-card wide">
        <div class="chart-title">Performance por Agente (Atendidas vs N&atilde;o Atendidas)</div>
        <div class="chart-wrap-tall"><canvas id="chartAgentes"></canvas></div>
    </div>
</div>

<?php if (count($queueStats) > 1): ?>
<div class="section-title">Resumo Comparativo por Fila</div>
<div class="table-card" style="margin-bottom:30px;">
    <div class="table-scroll">
    <table class="data-table">
        <thead>
            <tr>
                <th title="Fila: Passe o mouse sobre o n&uacute;mero de cada fila para visualizar a descri&ccedil;&atilde;o completa cadastrada no PABX">Fila</th>
                <th title="Volume total de chamadas recebidas nesta fila no per&iacute;odo">Total</th>
                <th title="Quantidade de chamadas atendidas por operadores nesta fila">Atendidas</th>
                <th title="% Atend.: Porcentagem de chamadas atendidas sobre o total recebido nesta fila">% Atend.</th>
                <th title="Quantidade de chamadas em que o cliente desligou aguardando nesta fila">Abandonadas</th>
                <th title="% Aband.: Porcentagem de chamadas abandonadas sobre o total recebido nesta fila">% Aband.</th>
                <th title="Quantidade de chamadas que estouraram o tempo limite de espera configurado para a fila">Timeout</th>
                <th title="SLA (&lt;=20s): Porcentagem de chamadas desta fila atendidas em at&eacute; 20 segundos de espera">SLA (&lt;=20s)</th>
                <th title="Tempo m&eacute;dio (formatado em mm:ss) que o cliente aguardou em fila antes do atendimento ou desist&ecirc;ncia">M&eacute;dia Espera</th>
                <th title="Tempo M&eacute;dio de Atendimento (TMA): Dura&ccedil;&atilde;o m&eacute;dia (formatada em mm:ss) das conversas nesta fila">M&eacute;dia Fala</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($queueStats as $qKey => $qData): 
                $qPctAt = $qData['total'] > 0 ? round(($qData['atendidas'] / $qData['total']) * 100, 1) : 0;
                $qPctAb = $qData['total'] > 0 ? round(($qData['abandonadas'] / $qData['total']) * 100, 1) : 0;
                $qPctSla = $qData['atendidas'] > 0 ? round(($qData['sla20'] / $qData['atendidas']) * 100, 1) : 0;
                $qMedEsp = $qData['total'] > 0 ? round($qData['soma_espera'] / $qData['total']) : 0;
                $qMedFal = $qData['atendidas'] > 0 ? round($qData['soma_fala'] / $qData['atendidas']) : 0;
            ?>
            <tr>
                <td style="font-weight:700;color:var(--accent-orange);cursor:help;" title="<?php echo htmlspecialchars($qData['descr']); ?>"><?php echo htmlspecialchars($qData['num']); ?></td>
                <td style="font-weight:700;"><?php echo $qData['total']; ?></td>
                <td style="color:#27ae60;font-weight:600;"><?php echo $qData['atendidas']; ?></td>
                <td><?php echo $qPctAt; ?>%</td>
                <td style="color:#e74c3c;font-weight:600;"><?php echo $qData['abandonadas']; ?></td>
                <td><?php echo $qPctAb; ?>%</td>
                <td><?php echo $qData['timeout']; ?></td>
                <td style="color:#2980b9;font-weight:700;"><?php echo $qPctSla; ?>%</td>
                <td><?php echo gmdate('i:s', $qMedEsp); ?></td>
                <td><?php echo gmdate('i:s', $qMedFal); ?></td>
            </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
    </div>
</div>
<?php endif; ?>

<div class="section-title">Detalhamento de Chamadas</div>
<div class="table-card">
    <div class="table-header">
        <span style="font-size:13px;color:var(--text-muted);font-weight:600;">
            Total: <?php echo $totalChamadas; ?> registros
        </span>
        <input type="text" class="table-search" id="tblSearch" placeholder="Buscar chamada..." onkeyup="processTable()" title="Pesquisar por n&uacute;mero de telefone, nome da fila, agente ou ramal">
    </div>
    <div class="table-scroll">
    <table class="data-table" id="detailTable">
        <thead>
            <tr>
                <th onclick="sortTable(0)">#</th>
                <th onclick="sortTable(1)">Data/Hora</th>
                <th onclick="sortTable(2)" title="Fila: Passe o mouse sobre o n&uacute;mero para ver o nome cadastrado no PABX">Fila</th>
                <th onclick="sortTable(3)">N&uacute;mero</th>
                <th onclick="sortTable(4)">Agente</th>
                <th onclick="sortTable(5)">Ramal</th>
                <th onclick="sortTable(6)">Status</th>
                <th onclick="sortTable(7)">Espera</th>
                <th onclick="sortTable(8)">Fala</th>
                <th onclick="sortTable(9)">Desligou</th>
                <th>Grava&ccedil;&atilde;o</th>
            </tr>
        </thead>
        <tbody id="detailTbody">
        <?php $i = 1; foreach ($chamadas as $c): ?>
        <tr>
            <td style="font-weight:600;"><?php echo $i++; ?></td>
            <td><?php echo htmlspecialchars($c['datetime']); ?></td>
            <td style="font-weight:600;color:var(--accent-orange);cursor:help;" title="<?php echo htmlspecialchars($c['fila_descr']); ?>"><?php echo htmlspecialchars($c['fila_num']); ?></td>
            <td><?php echo htmlspecialchars($c['numero'] ? $c['numero'] : '-'); ?></td>
            <td><?php echo htmlspecialchars($c['agente'] ? $c['agente'] : '-'); ?></td>
            <td style="font-weight:600;color:var(--primary-blue);"><?php echo htmlspecialchars($c['ramal'] ? $c['ramal'] : '-'); ?></td>
            <td>
                <?php if($c['status']=='ATENDIDA'): ?>
                    <span class="badge badge-atendida">ATENDIDA</span>
                <?php elseif($c['status']=='TIMEOUT'): ?>
                    <span class="badge badge-timeout">TIMEOUT</span>
                <?php else: ?>
                    <span class="badge badge-abandonada">ABANDONO</span>
                <?php endif; ?>
            </td>
            <td><?php echo $c['tempo_espera']>0 ? gmdate('i:s',$c['tempo_espera']) : '00:00'; ?></td>
            <td><?php echo $c['tempo_falando']>0 ? gmdate('i:s',$c['tempo_falando']) : '00:00'; ?></td>
            <td>
                <?php if($c['quem_desligou']=='AGENTE'): ?>
                    <span class="badge badge-agente">AGENTE</span>
                <?php elseif($c['quem_desligou']=='CLIENTE'): ?>
                    <span class="badge badge-cliente">CLIENTE</span>
                <?php else: ?>
                    <span style="color:#999;">-</span>
                <?php endif; ?>
            </td>
            <td>
                <?php if($c['status']=='ATENDIDA'): ?>
                    <button type="button" class="btn-audio btn-play" onclick="playAudio('<?php echo $c['uniqueid']; ?>', '<?php echo htmlspecialchars($c['numero']); ?>', '<?php echo htmlspecialchars($c['agente']); ?>')" title="Ouvir a grava&ccedil;&atilde;o desta chamada no player web">Play</button>
                    <a href="index.php?action=download_audio&uid=<?php echo $c['uniqueid']; ?>" class="btn-audio btn-dl" title="Fazer o download do arquivo de &aacute;udio da chamada">Baixar</a>
                <?php else: ?>
                    <span style="color:#aaa;font-size:11px;">-</span>
                <?php endif; ?>
            </td>
        </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    </div>
    <div class="table-footer">
        <span id="tableInfo">Calculando...</span>
        <div class="pagination" id="paginationControls"></div>
    </div>
</div>

</div>

<!-- Modal Audio Player -->
<div id="audioModal" class="modal-overlay" style="display:none;">
    <div class="modal-card">
        <div class="modal-header">
            <h3>Player de Grava&ccedil;&atilde;o da Chamada</h3>
            <button class="modal-close" onclick="closeAudioModal()">&times;</button>
        </div>
        <div class="modal-body">
            <div id="audioInfo" style="margin-bottom:12px;font-weight:600;font-size:13px;color:var(--text-color);"></div>
            <audio id="audioPlayer" controls style="width:100%;margin-top:10px;" preload="auto"></audio>
            <div id="audioError" style="display:none;color:#e74c3c;font-size:12px;margin-top:8px;">
                O navegador n&atilde;o conseguiu decodificar este &aacute;udio diretamente. Clique no bot&atilde;o abaixo para baixar e ouvir no computador.
            </div>
            <div style="margin-top:15px;text-align:right;">
                <a id="audioModalDownload" href="#" class="btn btn-excel" style="text-decoration:none;">&#128190; Baixar Arquivo de &Aacute;udio</a>
            </div>
        </div>
    </div>
</div>

<!-- Modal Manual do Usuario -->
<div id="manualModal" class="modal-overlay" style="display:none;">
    <div class="modal-card modal-large">
        <div class="modal-header">
            <h3>Manual do Usu&aacute;rio - IPbx Prisma</h3>
            <button class="modal-close" onclick="closeManualModal()">&times;</button>
        </div>
        <div class="modal-body" style="max-height:75vh;overflow-y:auto;text-align:left;padding:15px;line-height:1.6;">
            <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:15px;background:var(--table-hover);padding:12px 16px;border-radius:6px;border-left:4px solid var(--primary-blue);">
                <div>
                    <strong style="color:var(--primary-blue);font-size:14px;">Guia Pr&aacute;tico de Leitura e Opera&ccedil;&atilde;o</strong>
                    <div style="font-size:12px;color:var(--text-muted);">Relat&oacute;rio de Filas de Atendimento - IPbx Prisma v2.0</div>
                </div>
                <a href="Manual_Usuario_IPbx_Prisma.pdf" download class="btn btn-pdf" style="text-decoration:none;">
                    Baixar PDF do Manual
                </a>
            </div>

            <h4 style="color:var(--primary-blue);margin-top:15px;">&#128204; 1. Vis&atilde;o Geral</h4>
            <p style="font-size:13px;color:var(--text-color);">
                O m&oacute;dulo de Relat&oacute;rio de Filas do IPbx Prisma permite acompanhar o volume de atendimento, tempos de espera, taxa de reten&ccedil;&atilde;o, n&iacute;vel de servi&ccedil;o (SLA), escuta de grava&ccedil;&otilde;es e performance por operador em tempo real.
            </p>

            <h4 style="color:var(--primary-blue);margin-top:15px;">&#128200; 2. Indicadores Gerais &amp; N&iacute;vel de Servi&ccedil;o (SLA)</h4>
            <ul style="font-size:12px;color:var(--text-color);margin-left:20px;">
                <li><strong>Total Chamadas:</strong> Volume total de liga&ccedil;&otilde;es recebidas no per&iacute;odo.</li>
                <li><strong>Atendidas:</strong> Chamadas conversadas com a equipe.</li>
                <li><strong>Abandonadas:</strong> Cliente desligou enquanto aguardava na fila.</li>
                <li><strong>Timeout:</strong> Chamada estourou o tempo m&aacute;ximo limite configurado para a fila.</li>
                <li><strong>SLA % (&lt;=20s):</strong> Porcentagem de chamadas atendidas em at&eacute; 20 segundos. Meta principal de velocidade de atendimento.</li>
                <li><strong>M&eacute;dia Espera / Fala:</strong> Tempos m&eacute;dios formatados em mm:ss.</li>
            </ul>

            <h4 style="color:var(--primary-blue);margin-top:15px;">&#128269; 3. Nomes Reais das Filas &amp; Tooltips de Explica&ccedil;&atilde;o</h4>
<p style="font-size:13px;color:var(--text-color);">
    Para garantir clareza e agilidade na leitura, o sistema exibe o n&uacute;mero oficial da fila de atendimento (ex: <code>5001</code>). Ao <strong>passar o mouse sobre o n&uacute;mero</strong>, o sistema exibe a descri&ccedil;&atilde;o completa cadastrada no PABX (ex: <code>Tecle 1 - Consulta Processual (5001)</code>).
</p>
<h4 style="color:var(--primary-blue);margin-top:15px;">&#127911; 4. Grava&ccedil;&otilde;es de &Aacute;udio (Ouvir e Baixar)</h4>
            <p style="font-size:13px;color:var(--text-color);">
                Na coluna <strong>Grava&ccedil;&atilde;o</strong> da tabela, utilize <code>Play</code> para abrir o player inline no navegador ou <code>Baixar</code> para fazer o download direto do arquivo (.wav / .mp3) no computador.
            </p>

            <h4 style="color:var(--primary-blue);margin-top:15px;">&#9889; 5. Recursos Interativos da Barra Superior</h4>
            <ul style="font-size:12px;color:var(--text-color);margin-left:20px;">
                <li><strong>Auto-Refresh:</strong> No topo da tela, ative a atualiza&ccedil;&atilde;o autom&aacute;tica (30s ou 60s) para monitores/TVs de opera&ccedil;&atilde;o.</li>
                <li><strong>Tema Escuro / Claro:</strong> Alterne o visual para melhor conforto de leitura.</li>
                <li><strong>Expandir Aba:</strong> Abra o relat&oacute;rio fora da janela emoldurada do Issabel.</li>
                <li><strong>Filtros Avan&ccedil;ados:</strong> Combine filtros de Data, Filas, Agente, Status e N&uacute;mero.</li>
            </ul>
        </div>
    </div>
</div>

<script>
Chart.defaults.global.defaultFontColor = '#666';
var gridColor = '#eaeaea';

(function(){
    var ctx = document.getElementById('chartDonut').getContext('2d');
    new Chart(ctx, {
        type: 'doughnut',
        data: {
            labels: ['Atendidas', 'Abandonadas', 'Timeout'],
            datasets: [{
                data: [<?php echo $totalAtendidas; ?>, <?php echo $totalAbandonadas; ?>, <?php echo $totalTimeout; ?>],
                backgroundColor: ['#2ecc71', '#e74c3c', '#f1c40f'],
                borderColor: ['#fff','#fff','#fff'],
                borderWidth: 2
            }]
        },
        options: {
            responsive: true, maintainAspectRatio: false,
            legend: { position: 'bottom', labels: { fontSize: 11, padding: 15 } },
            cutoutPercentage: 65
        }
    });
})();

(function(){
    var ctx = document.getElementById('chartHoras').getContext('2d');
    new Chart(ctx, {
        type: 'bar',
        data: {
            labels: <?php echo $horasLabels; ?>,
            datasets: [
                { label: 'Atendidas',   data: <?php echo $horasAtendidas; ?>, backgroundColor: '#2ecc71' },
                { label: 'Abandonadas', data: <?php echo $horasAbandono; ?>,  backgroundColor: '#e74c3c' },
                { label: 'Timeout',     data: <?php echo $horasTimeout; ?>,   backgroundColor: '#f1c40f' }
            ]
        },
        options: {
            responsive: true, maintainAspectRatio: false,
            scales: {
                xAxes: [{ gridLines: { color: gridColor } }],
                yAxes: [{ gridLines: { color: gridColor }, ticks: { beginAtZero: true } }]
            }
        }
    });
})();

(function(){
    var ctx = document.getElementById('chartAgentes').getContext('2d');
    new Chart(ctx, {
        type: 'bar',
        data: {
            labels: <?php echo $agLabelsJson; ?>,
            datasets: [
                { label: 'Atendidas',     data: <?php echo $agAtendJson; ?>,    backgroundColor: '#3498db' },
                { label: 'N\u00e3o Atendidas', data: <?php echo $agNaoAtendJson; ?>, backgroundColor: '#e74c3c' }
            ]
        },
        options: {
            responsive: true, maintainAspectRatio: false,
            scales: {
                xAxes: [{ gridLines: { color: gridColor } }],
                yAxes: [{ gridLines: { color: gridColor }, ticks: { beginAtZero: true } }]
            }
        }
    });
})();

// --- Player Modal JS -------------------------------------------------------------
function playAudio(uid, numero, agente) {
    var modal = document.getElementById('audioModal');
    var player = document.getElementById('audioPlayer');
    var info = document.getElementById('audioInfo');
    var dlBtn = document.getElementById('audioModalDownload');
    var errDiv = document.getElementById('audioError');

    errDiv.style.display = 'none';
    info.innerHTML = 'Chamada: <strong>' + (numero || 'N/A') + '</strong> | Agente: <strong>' + (agente || 'N/A') + '</strong>';
    dlBtn.href = 'index.php?action=download_audio&uid=' + encodeURIComponent(uid);
    
    player.src = 'index.php?action=stream_audio&uid=' + encodeURIComponent(uid);
    modal.style.display = 'flex';
    player.load();
    player.play().catch(function(e) {
        console.log("Erro de reproducao automatica:", e);
    });

    player.onerror = function() {
        errDiv.style.display = 'block';
    };
}

function closeAudioModal() {
    var modal = document.getElementById('audioModal');
    var player = document.getElementById('audioPlayer');
    player.pause();
    player.src = '';
    modal.style.display = 'none';
}

function openManualModal() {
    document.getElementById('manualModal').style.display = 'flex';
}

function closeManualModal() {
    document.getElementById('manualModal').style.display = 'none';
}

// --- Dark Mode JS -------------------------------------------------------------
function toggleDarkMode() {
    document.body.classList.toggle('dark-mode');
    var isDark = document.body.classList.contains('dark-mode');
    localStorage.setItem('theme', isDark ? 'dark' : 'light');
    document.getElementById('btnThemeToggle').innerHTML = isDark ? 'Tema' : 'Tema';
}

if (localStorage.getItem('theme') === 'dark') {
    document.body.classList.add('dark-mode');
}

// --- Auto-Refresh JS ---------------------------------------------------------
var autoRefreshInterval = null;
var refreshSeconds = 0;

function toggleAutoRefresh() {
    var lbl = document.getElementById('lblAutoRefresh');
    if (refreshSeconds === 0) {
        refreshSeconds = 30;
        lbl.textContent = '30s';
    } else if (refreshSeconds === 30) {
        refreshSeconds = 60;
        lbl.textContent = '60s';
    } else {
        refreshSeconds = 0;
        lbl.textContent = 'OFF';
    }

    if (autoRefreshInterval) clearInterval(autoRefreshInterval);

    if (refreshSeconds > 0) {
        autoRefreshInterval = setInterval(function() {
            window.location.reload();
        }, refreshSeconds * 1000);
    }
}

// --- Tabela JS ---------------------------------------------------------
var currentPage = 1;
var rowsPerPage = 15;
var allRows = [];
var filteredRows = [];
var sortCol = -1;
var sortAsc = true;

document.addEventListener("DOMContentLoaded", function() {
    var tbody = document.getElementById('detailTbody');
    allRows = Array.prototype.slice.call(tbody.getElementsByTagName('tr'));

    allRows.forEach(function(r) {
        r.setAttribute('data-search', r.textContent.toLowerCase());
    });

    processTable(); 
});

function processTable() {
    var q = document.getElementById('tblSearch').value.toLowerCase();

    filteredRows = allRows.filter(function(r) {
        return r.getAttribute('data-search').indexOf(q) > -1;
    });

    if (sortCol > -1) {
        filteredRows.sort(function(a, b) {
            var av = a.cells[sortCol].textContent.trim();
            var bv = b.cells[sortCol].textContent.trim();
            var an = parseFloat(av.replace(/[^0-9.\-]/g,''));
            var bn = parseFloat(bv.replace(/[^0-9.\-]/g,''));
            if (!isNaN(an) && !isNaN(bn) && av.indexOf(':') === -1 && bv.indexOf(':') === -1) {
                return sortAsc ? an - bn : bn - an;
            }
            return sortAsc ? av.localeCompare(bv) : bv.localeCompare(av);
        });
    }

    var totalPages = Math.ceil(filteredRows.length / rowsPerPage);
    if(currentPage > totalPages) currentPage = totalPages || 1;

    var start = (currentPage - 1) * rowsPerPage;
    var end = start + rowsPerPage;

    allRows.forEach(function(r) { r.style.display = 'none'; });

    var tbody = document.getElementById('detailTbody');
    filteredRows.forEach(function(r, index) {
        tbody.appendChild(r);
        if (index >= start && index < end) {
            r.style.display = '';
        }
    });

    var showingStart = filteredRows.length > 0 ? start + 1 : 0;
    var showingEnd = Math.min(end, filteredRows.length);
    document.getElementById('tableInfo').innerHTML = 'Exibindo <strong>' + showingStart + ' at&eacute; ' + showingEnd + '</strong> de ' + filteredRows.length + ' registros';

    renderPaginationControls(totalPages);
}

function renderPaginationControls(totalPages) {
    var pagDiv = document.getElementById('paginationControls');
    pagDiv.innerHTML = '';

    if (totalPages <= 1) return;

    var btnPrev = document.createElement('button');
    btnPrev.className = 'pag-btn';
    btnPrev.innerHTML = '&laquo; Anterior';
    btnPrev.disabled = currentPage === 1;
    btnPrev.onclick = function() { currentPage--; processTable(); };
    pagDiv.appendChild(btnPrev);

    var span = document.createElement('span');
    span.style.fontWeight = '600';
    span.innerHTML = ' P&aacute;g ' + currentPage + ' de ' + totalPages + ' ';
    pagDiv.appendChild(span);

    var btnNext = document.createElement('button');
    btnNext.className = 'pag-btn';
    btnNext.innerHTML = 'Pr&oacute;xima &raquo;';
    btnNext.disabled = currentPage === totalPages || totalPages === 0;
    btnNext.onclick = function() { currentPage++; processTable(); };
    pagDiv.appendChild(btnNext);
}

function sortTable(colIndex) {
    if (sortCol === colIndex) {
        sortAsc = !sortAsc; 
    } else {
        sortCol = colIndex;
        sortAsc = true; 
    }
    currentPage = 1;
    processTable();
}
</script>

</body>
</html>
