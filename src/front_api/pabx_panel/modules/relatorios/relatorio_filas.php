<?php
/**
 * IPbx Prisma - Relatório Completo de Filas v7.7
 * Processa métricas reais de filas, SLA (<=20s), TMA/TME, performance por agente,
 * motivos de desconexão e gravações no padrão de design do painel.
 */

// Conexão MySQL para qstatslite e asterisk
$dbHost = 'localhost';
$dbName = 'qstatslite';

$issabelConf = [];
if (file_exists('/etc/issabel.conf')) {
    $lines = @file('/etc/issabel.conf', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    if ($lines) {
        foreach ($lines as $line) {
            if (strpos($line, '=') !== false) {
                list($k, $v) = explode('=', $line, 2);
                $issabelConf[trim($k)] = trim($v);
            }
        }
    }
}

$credsToTry = [
    ['user' => 'root', 'pass' => isset($issabelConf['mysqlrootpwd']) ? $issabelConf['mysqlrootpwd'] : ''],
    ['user' => 'asteriskuser', 'pass' => 'eLaStIx.AsTeRiSk.UsEr.1'],
    ['user' => 'root', 'pass' => 'eLaStIx.AsTeRiSk.UsEr.1'],
    ['user' => 'root', 'pass' => '']
];

$conn = null;
foreach ($credsToTry as $c) {
    $tmpConn = @mysqli_connect($dbHost, $c['user'], $c['pass']);
    if ($tmpConn) {
        $conn = $tmpConn;
        break;
    }
}

if (!$conn) {
    $conn = @mysqli_connect($dbHost, 'root', '');
}

if ($conn) {
    @mysqli_select_db($conn, $dbName);
    @mysqli_set_charset($conn, 'utf8');
}

// Presets de Data (Hoje, Ontem, 7 Dias/Semana, Mês, Personalizado)
$hoje          = date('Y-m-d');
$filter_preset = isset($_GET['preset']) ? $_GET['preset'] : '';

if ($filter_preset === 'hoje') {
    $dataInicio = $hoje;
    $dataFim    = $hoje;
} elseif ($filter_preset === 'ontem') {
    $dataInicio = date('Y-m-d', strtotime('-1 day'));
    $dataFim    = date('Y-m-d', strtotime('-1 day'));
} elseif ($filter_preset === 'mes') {
    $dataInicio = date('Y-m-01');
    $dataFim    = $hoje;
} elseif ($filter_preset === 'personalizado') {
    $dataInicio  = isset($_GET['data_inicio']) && !empty($_GET['data_inicio']) ? $_GET['data_inicio'] : date('Y-m-d', strtotime('-7 days'));
    $dataFim     = isset($_GET['data_fim'])    && !empty($_GET['data_fim'])    ? $_GET['data_fim']    : $hoje;
} else {
    // PADRÃO DEFAULT: 7 DIAS (semana) quando nada for selecionado
    $filter_preset = 'semana';
    $dataInicio = date('Y-m-d', strtotime('-7 days'));
    $dataFim    = $hoje;
}

$agenteFiltro = isset($_GET['agente_filtro']) ? trim($_GET['agente_filtro']) : '';
$statusFiltro = isset($_GET['status_filtro']) ? trim($_GET['status_filtro']) : '';
$numeroFiltro = isset($_GET['numero_filtro']) ? trim($_GET['numero_filtro']) : '';
$filaFiltro   = isset($_GET['fila']) ? (is_array($_GET['fila']) ? $_GET['fila'] : [$_GET['fila']]) : [];

$dataInicioEsc = $conn ? mysqli_real_escape_string($conn, $dataInicio) : $dataInicio;
$dataFimEsc    = $conn ? mysqli_real_escape_string($conn, $dataFim) : $dataFim;

// Mapeamentos das tabelas de dimensão
function buildQMap($query, $keyCol, $valCol, $conn) {
    $map = [];
    if (!$conn) return $map;
    $r = @mysqli_query($conn, $query);
    if ($r) while ($row = mysqli_fetch_assoc($r)) $map[$row[$keyCol]] = $row[$valCol];
    return $map;
}

$agentMap    = buildQMap("SELECT agent_id, agent FROM qagent", 'agent_id', 'agent', $conn);
$eventMap    = buildQMap("SELECT event_id, event FROM qevent", 'event_id', 'event', $conn);
$qnameMapRaw = buildQMap("SELECT qname_id, queue FROM qname", 'qname_id', 'queue', $conn);

// Mapeamento de Descrição REAL e Nome Amigável das Filas do PABX
$queueDescrMap = [];

// 1. Buscar da tabela queues_config via $db (PDO do Painel)
if (isset($db) && $db) {
    try {
        $qConfigStmt = $db->query("SELECT queue_number, queue_name FROM queues_config");
        if ($qConfigStmt) {
            while ($r = $qConfigStmt->fetch(PDO::FETCH_ASSOC)) {
                $num = trim($r['queue_number']);
                $name = trim($r['queue_name']);
                if ($num != '' && $name != '') {
                    $queueDescrMap[$num] = $name;
                }
            }
        }
    } catch (Exception $e) {}
}

// 2. Buscar do MySQL Asterisk (asterisk.queues_config)
if ($conn) {
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
}

// Função de formatação sem duplicação do número de fila
function getQDescr($qRaw, $queueDescrMap) {
    $qTrim = trim($qRaw);
    if ($qTrim === 'NONE' || $qTrim === '') return 'Sem Fila';
    
    $desc = isset($queueDescrMap[$qTrim]) ? trim($queueDescrMap[$qTrim]) : '';
    if ($desc === '' || strcasecmp($desc, $qTrim) === 0) {
        return "Fila {$qTrim}";
    }

    // Limpar repetição do número da fila no início do nome (ex: "5002 - 5002 - Porteiro" ou "5002 - Porteiro")
    $descClean = preg_replace('/^(?:Fila\s*)?' . preg_quote($qTrim, '/') . '[\s\-_:]*/i', '', $desc);
    $descClean = trim($descClean);

    if ($descClean === '' || strcasecmp($descClean, $qTrim) === 0) {
        return "Fila {$qTrim}";
    }

    return "Fila {$qTrim} - {$descClean}";
}

// Mapeamento de Ramais e Nomes (devices e users)
$ramalMap = [];
$extNameMap = [];
if ($conn) {
    $rDevices = @mysqli_query($conn, "SELECT user, description FROM asterisk.devices");
    if ($rDevices) {
        while ($row = mysqli_fetch_assoc($rDevices)) {
            $u = trim($row['user']);
            $d = trim($row['description']);
            if (strtoupper($d) != '') $ramalMap[strtoupper($d)] = $u;
            if ($u != '' && $d != '') $extNameMap[$u] = $d;
        }
    }
}

function fmtRamalDisplay($ramal, $extNameMap) {
    $rTrim = trim($ramal);
    if ($rTrim === '' || $rTrim === '-') return '-';
    if (isset($extNameMap[$rTrim]) && $extNameMap[$rTrim] != '' && strcasecmp($extNameMap[$rTrim], $rTrim) !== 0) {
        return $rTrim . ' - ' . $extNameMap[$rTrim];
    }
    return $rTrim;
}

// Lista de filas e agentes para o formulário
$filas = [];
if ($conn) {
    $rFilas = @mysqli_query($conn, "SELECT qname_id, queue FROM qname WHERE queue != 'NONE' ORDER BY queue");
    if ($rFilas && mysqli_num_rows($rFilas) > 0) {
        while ($row = mysqli_fetch_assoc($rFilas)) {
            $qRaw = $row['queue'];
            $row['queue_num'] = $qRaw;
            $row['queue_descr'] = getQDescr($qRaw, $queueDescrMap);
            $filas[] = $row;
        }
    }
}

// Fallback para buscar filas de queues_config se qname estiver vazio
if (empty($filas) && isset($db) && $db) {
    try {
        $q_res2 = $db->query("SELECT queue_number, queue_name FROM queues_config ORDER BY CAST(queue_number AS UNSIGNED) ASC");
        if ($q_res2) {
            while ($r = $q_res2->fetch(PDO::FETCH_ASSOC)) {
                $qRaw = trim($r['queue_number']);
                $qName = trim($r['queue_name']) ?: "Fila $qRaw";
                $filas[] = [
                    'qname_id' => $qRaw,
                    'queue' => $qRaw,
                    'queue_num' => $qRaw,
                    'queue_descr' => getQDescr($qRaw, [$qRaw => $qName])
                ];
            }
        }
    } catch (Exception $e2) {}
}

$agentesLista = [];
if ($conn) {
    $rAgentes = @mysqli_query($conn, "SELECT DISTINCT agent FROM qagent WHERE agent != 'NONE' AND agent != '' ORDER BY agent");
    if ($rAgentes) while ($row = mysqli_fetch_assoc($rAgentes)) $agentesLista[] = $row['agent'];
}

// Preparar formatos de data (String Datetime e Epoch Unix Timestamp)
$sd_str_full = "$dataInicioEsc 00:00:00";
$ed_str_full = "$dataFimEsc 23:59:59";
$sd_ts       = strtotime($sd_str_full);
$ed_ts       = strtotime($ed_str_full);

// Montar lista de filas para os filtros de SQL
$filasIn = [];
foreach($filaFiltro as $f) {
    $fClean = $conn ? mysqli_real_escape_string($conn, $f) : $f;
    if($fClean != '') $filasIn[] = "'$fClean'";
}

$whereCondStats = "WHERE (qs.datetime BETWEEN '$sd_str_full' AND '$ed_str_full' OR CAST(qs.datetime AS UNSIGNED) BETWEEN $sd_ts AND $ed_ts)";
$whereCondLog   = "WHERE ((time BETWEEN '$sd_str_full' AND '$ed_str_full') OR (CAST(time AS UNSIGNED) BETWEEN $sd_ts AND $ed_ts) OR (time BETWEEN '$sd_ts' AND '$ed_ts'))";

if (count($filasIn) > 0) {
    $filasCsv = implode(',', $filasIn);
    $whereCondStats .= " AND qs.qname IN ($filasCsv)";
    $whereCondLog   .= " AND queue IN ($filasCsv)";
}

// Consulta Principal com Fallback Multi-Banco (qstatslite -> queue_log -> cdr)
$chamadas = [];
$agentRingNoAnswer = [];

if ($conn) {
    $rDetalhe = null;

    // 1. Tentar qstatslite (queue_stats)
    $sqlDetalhe = "
    SELECT qs.queue_stats_id, qs.uniqueid, qs.datetime, qs.qname AS qname_id, qs.qagent AS agent_id, qs.qevent AS event_id, qs.info1, qs.info2, qs.info3
    FROM queue_stats qs
    $whereCondStats
    ORDER BY qs.datetime ASC, qs.uniqueid ASC, qs.queue_stats_id ASC
    ";
    $rDetalhe = @mysqli_query($conn, $sqlDetalhe);

    // 2. Fallback: Tentar asterisk.queue_log
    if (!$rDetalhe || mysqli_num_rows($rDetalhe) == 0) {
        $sqlQueueLog1 = "
        SELECT id AS queue_stats_id, uniqueid, time AS datetime, queue AS qname_id, agent AS agent_id, event AS event_id, data1 AS info1, data2 AS info2, data3 AS info3
        FROM asterisk.queue_log
        $whereCondLog
        ORDER BY time ASC, uniqueid ASC
        ";
        $rDetalhe = @mysqli_query($conn, $sqlQueueLog1);
    }

    // 3. Fallback: Tentar asteriskcdrdb.queue_log
    if (!$rDetalhe || mysqli_num_rows($rDetalhe) == 0) {
        $sqlQueueLog2 = "
        SELECT id AS queue_stats_id, uniqueid, time AS datetime, queue AS qname_id, agent AS agent_id, event AS event_id, data1 AS info1, data2 AS info2, data3 AS info3
        FROM asteriskcdrdb.queue_log
        $whereCondLog
        ORDER BY time ASC, uniqueid ASC
        ";
        $rDetalhe = @mysqli_query($conn, $sqlQueueLog2);
    }

    // Processar registros retornados das tabelas de eventos de fila
    if ($rDetalhe && mysqli_num_rows($rDetalhe) > 0) {
        while ($row = mysqli_fetch_assoc($rDetalhe)) {
            $uid   = $row['uniqueid'];
            $ev    = isset($eventMap[$row['event_id']]) ? $eventMap[$row['event_id']] : $row['event_id'];
            $agent = isset($agentMap[$row['agent_id']]) ? $agentMap[$row['agent_id']] : $row['agent_id'];
            $rawQueue = isset($qnameMapRaw[$row['qname_id']]) ? $qnameMapRaw[$row['qname_id']] : $row['qname_id'];
            $descrQueue = getQDescr($rawQueue, $queueDescrMap);

            $dtVal = $row['datetime'];
            if (is_numeric($dtVal)) {
                $dtVal = date('Y-m-d H:i:s', intval($dtVal));
            }

            if ($ev == 'RINGNOANSWER' && $agent != 'NONE' && $agent != '') {
                if (!isset($agentRingNoAnswer[$agent])) $agentRingNoAnswer[$agent] = 0;
                $agentRingNoAnswer[$agent]++;
            }

            if (!isset($chamadas[$uid])) {
                $chamadas[$uid] = [
                    'uniqueid'       => $uid,
                    'datetime'       => $dtVal,
                    'fila_num'       => $rawQueue,
                    'fila_descr'     => $descrQueue,
                    'numero'         => '',
                    'agente'         => '',
                    'ramal'          => '',
                    'status'         => 'ABANDONADA',
                    'tempo_espera'   => 0,
                    'tempo_falando'  => 0,
                    'quem_desligou'  => '',
                    'eventos'        => []
                ];
            }

            $chamadas[$uid]['eventos'][] = ['event' => $ev, 'agent' => $agent, 'info1' => $row['info1'], 'info2' => $row['info2'], 'info3' => $row['info3'], 'dt' => $dtVal];

            switch ($ev) {
                case 'ENTERQUEUE':
                    if ($row['info2'] != '' && $row['info2'] != 'NONE') $chamadas[$uid]['numero'] = $row['info2'];
                    if ($row['info1'] != '' && $row['info1'] != 'NONE') $chamadas[$uid]['numero'] = $row['info1']; 
                    $chamadas[$uid]['datetime'] = $dtVal;
                    break;
                case 'CONNECT':
                    $chamadas[$uid]['agente']       = $agent;
                    $descKey = strtoupper(trim($agent));
                    if (isset($ramalMap[$descKey])) {
                        $chamadas[$uid]['ramal'] = $ramalMap[$descKey];
                    } elseif (preg_match('/(?:SIP|PJSIP|IAX2|Local|Agent)\/(\d+)/i', $agent, $mRam)) {
                        $chamadas[$uid]['ramal'] = $mRam[1];
                    } elseif (preg_match('/^\d+$/', trim($agent))) {
                        $chamadas[$uid]['ramal'] = trim($agent);
                    } else {
                        $chamadas[$uid]['ramal'] = '-';
                    }
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
}
$chamadas = array_values($chamadas);

// 4. Fallback Camada 3: Leitura direta do CDR (asteriskcdrdb.cdr) se a tabela de eventos de filas retornar 0 resultados
if (empty($chamadas) && $conn) {
    $cdrQueueCond = "";
    if (count($filasIn) > 0) {
        $cdrQueueCond = " AND (dst IN (" . implode(',', $filasIn) . ")";
        foreach ($filaFiltro as $ff) {
            $ffEsc = mysqli_real_escape_string($conn, $ff);
            if (!empty($ffEsc)) {
                $cdrQueueCond .= " OR dcontext LIKE '%$ffEsc%'";
            }
        }
        $cdrQueueCond .= ")";
    } else {
        $cdrQueueCond = " AND (dcontext LIKE '%queue%' OR dcontext LIKE '%ext-queues%' OR dst REGEXP '^[5-9][0-9]{3}$' OR lastapp = 'Queue')";
    }

    $sqlCdrDirect = "
    SELECT uniqueid, calldate AS datetime, src, clid, dst, dstchannel, disposition, duration, billsec, recordingfile, dcontext
    FROM asteriskcdrdb.cdr
    WHERE calldate >= '$sd_str_full' AND calldate <= '$ed_str_full'
    $cdrQueueCond
    ORDER BY calldate DESC
    ";

    $rCdrDirect = @mysqli_query($conn, $sqlCdrDirect);
    if ($rCdrDirect) {
        while ($r = mysqli_fetch_assoc($rCdrDirect)) {
            $uid  = $r['uniqueid'];
            $qNum = trim($r['dst']);
            if (empty($qNum) || !is_numeric($qNum)) {
                if (preg_match('/(?:ext-queues|queue),(\d+),/i', $r['dcontext'], $mq)) {
                    $qNum = $mq[1];
                } else {
                    $qNum = '5000';
                }
            }

            $numBina = trim($r['src']);
            if (empty($numBina) || $numBina === 's') {
                if (preg_match('/<(\d+)>/', $r['clid'], $mc)) {
                    $numBina = $mc[1];
                }
            }

            $agentName = '';
            $ramalNum  = '-';
            if (preg_match('/(?:SIP|PJSIP|IAX2|Local)\/(\d+)/i', $r['dstchannel'], $ma)) {
                $ramalNum  = $ma[1];
                $agentName = isset($extNameMap[$ramalNum]) ? $ramalNum . ' - ' . $extNameMap[$ramalNum] : 'Ramal ' . $ramalNum;
            }

            $disp = strtoupper(trim($r['disposition']));
            $status = 'ABANDONADA';
            $quemDesligou = 'CLIENTE';
            if ($disp === 'ANSWERED') {
                $status = 'ATENDIDA';
                $quemDesligou = 'AGENTE';
            }

            $tempoEspera  = max(0, intval($r['duration']) - intval($r['billsec']));
            $tempoFalando = intval($r['billsec']);

            $chamadas[] = [
                'uniqueid'       => $uid,
                'datetime'       => $r['datetime'],
                'fila_num'       => $qNum,
                'fila_descr'     => getQDescr($qNum, $queueDescrMap),
                'numero'         => $numBina ?: '-',
                'agente'         => $agentName ?: 'SEM AGENTE',
                'ramal'          => $ramalNum,
                'status'         => $status,
                'tempo_espera'   => $tempoEspera,
                'tempo_falando'  => $tempoFalando,
                'quem_desligou'  => $quemDesligou,
                'recordingfile'  => $r['recordingfile'] ?? '',
                'eventos'        => []
            ];
        }
    }
}

// Exportação Excel/CSV com UTF-8 BOM diretamente dos dados extraídos ($chamadas)
if (isset($_GET['export']) && $_GET['export'] === 'excel') {
    while (ob_get_level()) { ob_end_clean(); }
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="relatorio_filas_' . $dataInicio . '_' . $dataFim . '.csv"');
    header('Cache-Control: no-cache, must-revalidate');
    header('Pragma: no-cache');
    echo "\xEF\xBB\xBF";
    echo "UniqueID;Data/Hora;Fila;Nome da Fila;Numero (BINA);Agente;Ramal;Status;Tempo Espera (s);Tempo Fala (s);Quem Desligou\n";
    foreach ($chamadas as $c) {
        echo implode(';', [
            '"' . $c['uniqueid'] . '"',
            '"' . $c['datetime'] . '"',
            '"' . $c['fila_num'] . '"',
            '"' . $c['fila_descr'] . '"',
            '"' . $c['numero'] . '"',
            '"' . $c['agente'] . '"',
            '"' . $c['ramal'] . '"',
            '"' . $c['status'] . '"',
            '"' . intval($c['tempo_espera']) . '"',
            '"' . intval($c['tempo_falando']) . '"',
            '"' . ($c['quem_desligou'] ?: '-') . '"'
        ]) . "\n";
    }
    exit;
}

// Complemento de dados via CDR para BINA/Ramal ausentes
$uidsMissing = [];
foreach ($chamadas as $idx => $c) {
    if (empty($c['numero']) || $c['numero'] == 'NONE' || empty($c['agente']) || $c['agente'] == 'NONE') {
        $uidsMissing[$c['uniqueid']] = $idx;
    }
}

if (!empty($uidsMissing) && $conn) {
    $uidsEsc = array_map(function($u) use ($conn) { return "'" . mysqli_real_escape_string($conn, $u) . "'"; }, array_keys($uidsMissing));
    $sqlCdr = "SELECT uniqueid, src, clid, dst, dstchannel FROM asteriskcdrdb.cdr WHERE uniqueid IN (" . implode(',', $uidsEsc) . ")";
    $rCdr = @mysqli_query($conn, $sqlCdr);
    if ($rCdr) {
        while ($rowCdr = mysqli_fetch_assoc($rCdr)) {
            $uid = $rowCdr['uniqueid'];
            if (isset($uidsMissing[$uid])) {
                $idx = $uidsMissing[$uid];
                if (empty($chamadas[$idx]['numero']) || $chamadas[$idx]['numero'] == 'NONE') {
                    $srcClean = trim($rowCdr['src']);
                    if ($srcClean != '' && $srcClean != 's' && $srcClean != 'NONE') {
                        $chamadas[$idx]['numero'] = $srcClean;
                    } elseif (preg_match('/<(\d+)>/', $rowCdr['clid'], $m)) {
                        $chamadas[$idx]['numero'] = $m[1];
                    }
                }
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

// Filtros adicionais em PHP (Agente, Status, Número)
if ($agenteFiltro != '' || $statusFiltro != '' || $numeroFiltro != '') {
    $chamadas = array_filter($chamadas, function($c) use ($agenteFiltro, $statusFiltro, $numeroFiltro) {
        if ($agenteFiltro != '' && strcasecmp($c['agente'], $agenteFiltro) !== 0) return false;
        if ($statusFiltro != '' && strcasecmp($c['status'], $statusFiltro) !== 0) return false;
        if ($numeroFiltro != '' && strpos($c['numero'], $numeroFiltro) === false) return false;
        return true;
    });
    $chamadas = array_values($chamadas);
}

// Cálculo das Métricas Gerais e SLA
$totalChamadas    = count($chamadas);
$totalAtendidas   = 0;
$totalAbandonadas = 0;
$totalTimeout     = 0;
$totalSla20       = 0;
$somaEspera       = 0;
$somaFalando      = 0;
$agentStats       = []; 
$queueStats       = [];

foreach ($chamadas as $c) {
    $qKey = $c['fila_num'] ?: 'NONE';
    if (!isset($queueStats[$qKey])) {
        $queueStats[$qKey] = ['num' => $qKey, 'descr' => $c['fila_descr'], 'total' => 0, 'atendidas' => 0, 'abandonadas' => 0, 'timeout' => 0, 'sla20' => 0, 'soma_espera' => 0, 'soma_fala' => 0, 'max_espera' => 0];
    }
    $queueStats[$qKey]['total']++;

    if ($c['tempo_espera'] > $queueStats[$qKey]['max_espera']) {
        $queueStats[$qKey]['max_espera'] = $c['tempo_espera'];
    }

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
        if (!isset($agentStats[$ag])) $agentStats[$ag] = ['atendidas' => 0, 'nao_atendidas' => 0, 'tempo' => 0];
        $agentStats[$ag]['atendidas']++;
        $agentStats[$ag]['tempo'] += $c['tempo_falando'];
    }
}

foreach ($agentRingNoAnswer as $ag => $perdidas) {
    if (!isset($agentStats[$ag])) {
        $agentStats[$ag] = ['atendidas' => 0, 'nao_atendidas' => 0, 'tempo' => 0];
    }
    $agentStats[$ag]['nao_atendidas'] += $perdidas;
}

$pctAtendimento = $totalChamadas > 0 ? round(($totalAtendidas / $totalChamadas) * 100, 1) : 0;
$pctAbandono    = $totalChamadas > 0 ? round(($totalAbandonadas / $totalChamadas) * 100, 1) : 0;
$pctSla20       = $totalAtendidas > 0 ? round(($totalSla20 / $totalAtendidas) * 100, 1) : 0;
$mediaEspera    = $totalChamadas > 0 ? round($somaEspera / $totalChamadas) : 0;
$mediaFalando   = $totalAtendidas > 0 ? round($somaFalando / $totalAtendidas) : 0;

// Preparação de dados JSON para Chart.js
$porHora = [];
for ($h = 0; $h < 24; $h++) $porHora[$h] = ['atendidas' => 0, 'abandonadas' => 0, 'timeout' => 0];
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

$agentChartData = [];
foreach ($agentStats as $ag => $st) {
    if ($ag == 'SEM AGENTE' || $ag == 'NONE') continue;
    $agentChartData[$ag] = $st;
}
arsort($agentChartData);
$agLabels = []; $agAtend = []; $agNaoAtend = []; $cnt = 0;
foreach ($agentChartData as $ag => $st) {
    $agLabels[] = $ag; $agAtend[] = $st['atendidas']; $agNaoAtend[] = $st['nao_atendidas'];
    if (++$cnt >= 15) break;
}
$agLabelsJson   = json_encode($agLabels);
$agAtendJson    = json_encode($agAtend);
$agNaoAtendJson = json_encode($agNaoAtend);
?>

<div id="qstats-printable-area" class="space-y-6">
    <!-- Header e Filtros -->
    <div class="bg-slate-900/90 border border-slate-800 rounded-3xl p-6 shadow-2xl space-y-5">
        <div class="flex flex-col lg:flex-row items-start lg:items-center justify-between gap-4 border-b border-slate-800 pb-4">
            <div class="flex items-center gap-3.5">
                <div class="w-12 h-12 rounded-2xl bg-purple-500/10 text-purple-400 border border-purple-500/30 flex items-center justify-center font-bold text-xl shadow-lg">
                    <i class="fa-solid fa-list-check"></i>
                </div>
                <div>
                    <h3 class="text-base font-black text-white flex items-center gap-2">
                        Relatório Completo de Filas
                    </h3>
                    <p class="text-xs text-slate-400 mt-0.5">
                        Métricas consolidadas de SLA (&le;20s), volume de atendimento, rejeições por agente e extrato analítico em tempo real.
                    </p>
                </div>
            </div>

            <!-- Botões Rápidos de Exportação, Impressão e Disparos (Somente Ícones: CSV, PDF, E-mail, WhatsApp) -->
            <div class="flex items-center gap-1.5 flex-wrap print:hidden">
                <a href="index.php?module=relatorios&action=relatorio_filas&export=excel&data_inicio=<?php echo urlencode($dataInicio); ?>&data_fim=<?php echo urlencode($dataFim); ?>" 
                   title="Exportar para CSV / Excel" 
                   class="p-2 bg-slate-950 hover:bg-slate-800 text-emerald-400 border border-slate-800 rounded-xl font-bold transition shadow">
                    <i class="fa-solid fa-file-csv text-base"></i>
                </a>
                <button onclick="window.print()" title="Imprimir ou Salvar em PDF" class="p-2 bg-slate-950 hover:bg-slate-800 text-rose-400 border border-slate-800 rounded-xl font-bold transition shadow">
                    <i class="fa-solid fa-file-pdf text-base"></i>
                </button>
                <button onclick="shareReportViaEmail('Relatório Completo de Filas')" title="Enviar Relatório por E-mail" class="p-2 bg-slate-950 hover:bg-slate-800 text-amber-400 border border-slate-800 rounded-xl font-bold transition shadow">
                    <i class="fa-solid fa-envelope text-base"></i>
                </button>
                <button onclick="shareReportViaWhatsApp('Relatório Completo de Filas')" title="Enviar Relatório via WhatsApp" class="p-2 bg-slate-950 hover:bg-slate-800 text-emerald-400 border border-slate-800 rounded-xl font-bold transition shadow">
                    <i class="fa-brands fa-whatsapp text-base"></i>
                </button>
            </div>
        </div>

        <!-- BARRA DE PRESETS RÁPIDOS DE DATA COM PERSONALIZADO E CALENDÁRIO PADRONIZADO -->
        <form method="GET" action="index.php" id="form-filtro-filas" class="space-y-4 print:hidden">
            <input type="hidden" name="module" value="relatorios">
            <input type="hidden" name="action" value="relatorio_filas">
            <input type="hidden" name="preset" id="input_preset" value="<?php echo htmlspecialchars($filter_preset); ?>">

            <div class="flex flex-wrap items-center justify-between gap-3 bg-slate-950/80 p-3 rounded-2xl border border-slate-800">
                <div class="flex items-center gap-2 text-xs font-bold text-slate-300">
                    <i class="fa-solid fa-calendar-range text-purple-400 text-sm"></i>
                    <span>Período Rápido:</span>
                </div>
                <div class="flex items-center bg-slate-900 border border-slate-800 rounded-xl p-1 gap-1 flex-wrap text-xs">
                    <button type="button" onclick="setPresetDate('hoje')" class="px-3 py-1.5 rounded-lg font-bold transition <?php echo $filter_preset === 'hoje' ? 'bg-purple-600 text-white shadow' : 'text-slate-300 hover:bg-slate-800 hover:text-white'; ?>">
                        Hoje
                    </button>
                    <button type="button" onclick="setPresetDate('ontem')" class="px-3 py-1.5 rounded-lg font-bold transition <?php echo $filter_preset === 'ontem' ? 'bg-purple-600 text-white shadow' : 'text-slate-300 hover:bg-slate-800 hover:text-white'; ?>">
                        Ontem
                    </button>
                    <button type="button" onclick="setPresetDate('semana')" class="px-3 py-1.5 rounded-lg font-bold transition <?php echo ($filter_preset === 'semana' || $filter_preset === '7dias' || !$filter_preset) ? 'bg-purple-600 text-white shadow font-black' : 'text-slate-300 hover:bg-slate-800 hover:text-white'; ?>">
                        7 Dias
                    </button>
                    <button type="button" onclick="setPresetDate('mes')" class="px-3 py-1.5 rounded-lg font-bold transition <?php echo $filter_preset === 'mes' ? 'bg-purple-600 text-white shadow' : 'text-slate-300 hover:bg-slate-800 hover:text-white'; ?>">
                        Mês
                    </button>
                    <button type="button" onclick="setPresetDate('personalizado')" class="px-3 py-1.5 rounded-lg font-bold transition <?php echo ($filter_preset === 'personalizado') ? 'bg-purple-600 text-white shadow' : 'text-slate-300 hover:bg-slate-800 hover:text-white'; ?>" title="Personalizado (Selecionar Datas)">
                        <i class="fa-solid fa-calendar-days text-sm"></i>
                    </button>
                </div>
            </div>

            <div class="grid grid-cols-1 sm:grid-cols-2 md:grid-cols-3 lg:grid-cols-6 gap-3 text-xs">
                <!-- Data Início Clicável com Ícone de Calendário -->
                <div class="space-y-1">
                    <label class="text-slate-400 font-bold block">Data Início:</label>
                    <div class="relative flex items-center">
                        <input type="date" id="input_data_inicio" name="data_inicio" value="<?php echo htmlspecialchars($dataInicio); ?>" 
                               onchange="document.getElementById('input_preset').value='personalizado';"
                               onclick="try{this.showPicker();}catch(e){}"
                               class="w-full pl-3 pr-8 py-2 bg-slate-950 border border-slate-800 rounded-xl text-white font-mono focus:border-purple-500 focus:outline-none cursor-pointer">
                        <button type="button" onclick="try{document.getElementById('input_data_inicio').showPicker();}catch(e){}" class="absolute right-2.5 text-purple-400 hover:text-purple-300">
                            <i class="fa-solid fa-calendar-days"></i>
                        </button>
                    </div>
                </div>

                <!-- Data Fim Clicável com Ícone de Calendário -->
                <div class="space-y-1">
                    <label class="text-slate-400 font-bold block">Data Fim:</label>
                    <div class="relative flex items-center">
                        <input type="date" id="input_data_fim" name="data_fim" value="<?php echo htmlspecialchars($dataFim); ?>" 
                               onchange="document.getElementById('input_preset').value='personalizado';"
                               onclick="try{this.showPicker();}catch(e){}"
                               class="w-full pl-3 pr-8 py-2 bg-slate-950 border border-slate-800 rounded-xl text-white font-mono focus:border-purple-500 focus:outline-none cursor-pointer">
                        <button type="button" onclick="try{document.getElementById('input_data_fim').showPicker();}catch(e){}" class="absolute right-2.5 text-purple-400 hover:text-purple-300">
                            <i class="fa-solid fa-calendar-days"></i>
                        </button>
                    </div>
                </div>

                <!-- Filas PABX com Nome Sem Duplicação -->
                <div class="space-y-1">
                    <label class="text-slate-400 font-bold block">Filas PABX:</label>
                    <select name="fila[]" multiple class="w-full px-3 py-1.5 bg-slate-950 border border-slate-800 rounded-xl text-white font-mono h-10 focus:border-purple-500 focus:outline-none custom-scrollbar">
                        <?php foreach ($filas as $f): ?>
                            <option value="<?php echo $f['qname_id']; ?>" <?php echo in_array($f['qname_id'], $filaFiltro) ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($f['queue_descr']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <!-- Agentes -->
                <div class="space-y-1">
                    <label class="text-slate-400 font-bold block">Agente / Atendente:</label>
                    <select name="agente_filtro" class="w-full px-3 py-2 bg-slate-950 border border-slate-800 rounded-xl text-white focus:border-purple-500 focus:outline-none">
                        <option value="">-- Todos os Agentes --</option>
                        <?php foreach ($agentesLista as $ag): ?>
                            <option value="<?php echo htmlspecialchars($ag); ?>" <?php echo $agenteFiltro === $ag ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($ag); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <!-- Status -->
                <div class="space-y-1">
                    <label class="text-slate-400 font-bold block">Status Chamada:</label>
                    <select name="status_filtro" class="w-full px-3 py-2 bg-slate-950 border border-slate-800 rounded-xl text-white focus:border-purple-500 focus:outline-none">
                        <option value="">-- Todos os Status --</option>
                        <option value="ATENDIDA" <?php echo $statusFiltro === 'ATENDIDA' ? 'selected' : ''; ?>>🟢 ATENDIDA</option>
                        <option value="ABANDONADA" <?php echo $statusFiltro === 'ABANDONADA' ? 'selected' : ''; ?>>🔴 ABANDONADA</option>
                        <option value="TIMEOUT" <?php echo $statusFiltro === 'TIMEOUT' ? 'selected' : ''; ?>>🟡 TIMEOUT</option>
                    </select>
                </div>

                <!-- Número BINA -->
                <div class="space-y-1">
                    <label class="text-slate-400 font-bold block">Telefone / BINA:</label>
                    <input type="text" name="numero_filtro" placeholder="Ex: 11999998888" value="<?php echo htmlspecialchars($numeroFiltro); ?>" class="w-full px-3 py-2 bg-slate-950 border border-slate-800 rounded-xl text-white font-mono focus:border-purple-500 focus:outline-none">
                </div>
            </div>

            <div class="flex items-center justify-end gap-2 pt-2 border-t border-slate-800/60">
                <button type="submit" class="px-5 py-2 bg-purple-600 hover:bg-purple-500 text-white rounded-xl text-xs font-extrabold transition shadow-lg shadow-purple-600/30 flex items-center gap-2">
                    <i class="fa-solid fa-filter"></i> Filtrar Relatório
                </button>
            </div>
        </form>
    </div>

    <!-- CARDS DE INDICADORES GERAIS E SLA -->
    <div class="grid grid-cols-2 md:grid-cols-4 lg:grid-cols-8 gap-4">
        <!-- 1. Total Chamadas -->
        <div class="bg-slate-900/90 p-4 rounded-2xl border border-slate-800 shadow-xl space-y-1">
            <span class="text-[10px] font-bold text-slate-400 uppercase tracking-wider block">Total Chamadas</span>
            <div class="text-2xl font-black text-white font-mono"><?php echo $totalChamadas; ?></div>
            <span class="text-[10px] text-cyan-400 block font-mono">Volume de Entrada</span>
        </div>

        <!-- 2. Atendidas -->
        <div class="bg-slate-900/90 p-4 rounded-2xl border border-emerald-500/30 shadow-xl space-y-1">
            <span class="text-[10px] font-bold text-emerald-400 uppercase tracking-wider block">Atendidas</span>
            <div class="text-2xl font-black text-emerald-400 font-mono"><?php echo $totalAtendidas; ?></div>
            <span class="text-[10px] text-emerald-300 block font-mono"><?php echo $pctAtendimento; ?>% do total</span>
        </div>

        <!-- 3. Abandonadas -->
        <div class="bg-slate-900/90 p-4 rounded-2xl border border-rose-500/30 shadow-xl space-y-1">
            <span class="text-[10px] font-bold text-rose-400 uppercase tracking-wider block">Abandonadas</span>
            <div class="text-2xl font-black text-rose-400 font-mono"><?php echo $totalAbandonadas; ?></div>
            <span class="text-[10px] text-rose-300 block font-mono"><?php echo $pctAbandono; ?>% do total</span>
        </div>

        <!-- 4. Timeout -->
        <div class="bg-slate-900/90 p-4 rounded-2xl border border-amber-500/30 shadow-xl space-y-1">
            <span class="text-[10px] font-bold text-amber-400 uppercase tracking-wider block">Timeout</span>
            <div class="text-2xl font-black text-amber-400 font-mono"><?php echo $totalTimeout; ?></div>
            <span class="text-[10px] text-amber-300 block font-mono"><?php echo $totalChamadas>0?round(($totalTimeout/$totalChamadas)*100,1):0; ?>%</span>
        </div>

        <!-- 5. SLA <=20s -->
        <div class="bg-slate-900/90 p-4 rounded-2xl border border-purple-500/30 shadow-xl space-y-1">
            <span class="text-[10px] font-bold text-purple-400 uppercase tracking-wider block">SLA (&le;20s)</span>
            <div class="text-2xl font-black text-purple-400 font-mono"><?php echo $pctSla20; ?>%</div>
            <span class="text-[10px] text-purple-300 block font-mono"><?php echo $totalSla20; ?> chamadas</span>
        </div>

        <!-- 6. Média Espera -->
        <div class="bg-slate-900/90 p-4 rounded-2xl border border-slate-800 shadow-xl space-y-1">
            <span class="text-[10px] font-bold text-slate-400 uppercase tracking-wider block">Média Espera</span>
            <div class="text-xl font-black text-white font-mono"><?php echo gmdate('i:s', $mediaEspera); ?></div>
            <span class="text-[10px] text-slate-400 block font-mono">TME (mm:ss)</span>
        </div>

        <!-- 7. Média Fala -->
        <div class="bg-slate-900/90 p-4 rounded-2xl border border-slate-800 shadow-xl space-y-1">
            <span class="text-[10px] font-bold text-slate-400 uppercase tracking-wider block">Média Fala</span>
            <div class="text-xl font-black text-white font-mono"><?php echo gmdate('i:s', $mediaFalando); ?></div>
            <span class="text-[10px] text-slate-400 block font-mono">TMA (mm:ss)</span>
        </div>

        <!-- 8. Agentes Ativos -->
        <div class="bg-slate-900/90 p-4 rounded-2xl border border-slate-800 shadow-xl space-y-1">
            <span class="text-[10px] font-bold text-slate-400 uppercase tracking-wider block">Agentes Ativos</span>
            <div class="text-2xl font-black text-white font-mono"><?php echo count($agentStats); ?></div>
            <span class="text-[10px] text-indigo-400 block font-mono">Operadores</span>
        </div>
    </div>

    <!-- GRÁFICOS DE PERFORMANCE -->
    <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
        <!-- Gráfico de Evolução por Hora -->
        <div class="bg-slate-900/90 border border-slate-800 rounded-3xl p-6 shadow-2xl space-y-4">
            <div class="flex items-center justify-between border-b border-slate-800 pb-3">
                <h4 class="text-xs font-extrabold text-white uppercase tracking-wider flex items-center gap-2">
                    <i class="fa-solid fa-clock text-purple-400"></i> Distribuição por Hora do Dia
                </h4>
            </div>
            <div class="h-64">
                <canvas id="chart-queue-hours"></canvas>
            </div>
        </div>

        <!-- Gráfico de Performance dos Agentes -->
        <div class="bg-slate-900/90 border border-slate-800 rounded-3xl p-6 shadow-2xl space-y-4">
            <div class="flex items-center justify-between border-b border-slate-800 pb-3">
                <h4 class="text-xs font-extrabold text-white uppercase tracking-wider flex items-center gap-2">
                    <i class="fa-solid fa-user-group text-emerald-400"></i> Atendimento por Agente (Top 15)
                </h4>
            </div>
            <div class="h-64">
                <canvas id="chart-queue-agents"></canvas>
            </div>
        </div>
    </div>

    <!-- TABELA COMPARATIVA DE FILAS -->
    <?php if (count($queueStats) > 0): ?>
        <div class="bg-slate-900/90 border border-slate-800 rounded-3xl p-6 shadow-2xl space-y-4">
            <div class="flex items-center justify-between border-b border-slate-800 pb-3">
                <h4 class="text-xs font-extrabold text-white uppercase tracking-wider flex items-center gap-2">
                    <i class="fa-solid fa-layer-group text-purple-400"></i> Resumo Comparativo por Fila de Atendimento
                </h4>
            </div>

            <div class="overflow-x-auto custom-scrollbar">
                <table class="w-full text-left text-xs">
                    <thead>
                        <tr class="bg-slate-950 text-slate-400 font-bold uppercase tracking-wider border-b border-slate-800">
                            <th class="py-3 px-4">Fila</th>
                            <th class="py-3 px-4">Total</th>
                            <th class="py-3 px-4">Atendidas</th>
                            <th class="py-3 px-4">% Atend.</th>
                            <th class="py-3 px-4">Abandonadas</th>
                            <th class="py-3 px-4">% Aband.</th>
                            <th class="py-3 px-4">Timeout</th>
                            <th class="py-3 px-4">SLA (&le;20s)</th>
                            <th class="py-3 px-4">TME (Espera)</th>
                            <th class="py-3 px-4">Maior Espera</th>
                            <th class="py-3 px-4">TMA (Fala)</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-800/60 font-mono">
                        <?php foreach ($queueStats as $qKey => $qData): 
                            $qPctAt = $qData['total'] > 0 ? round(($qData['atendidas'] / $qData['total']) * 100, 1) : 0;
                            $qPctAb = $qData['total'] > 0 ? round(($qData['abandonadas'] / $qData['total']) * 100, 1) : 0;
                            $qPctSla = $qData['atendidas'] > 0 ? round(($qData['sla20'] / $qData['atendidas']) * 100, 1) : 0;
                            $qMedEsp = $qData['total'] > 0 ? round($qData['soma_espera'] / $qData['total']) : 0;
                            $qMedFal = $qData['atendidas'] > 0 ? round($qData['soma_fala'] / $qData['atendidas']) : 0;
                        ?>
                            <tr class="hover:bg-slate-800/40 transition">
                                <td class="py-3 px-4 font-extrabold text-purple-300 font-sans" title="<?php echo htmlspecialchars($qData['descr']); ?>">
                                    <?php echo htmlspecialchars($qData['descr']); ?>
                                </td>
                                <td class="py-3 px-4 font-bold text-white"><?php echo $qData['total']; ?></td>
                                <td class="py-3 px-4 text-emerald-400 font-bold"><?php echo $qData['atendidas']; ?></td>
                                <td class="py-3 px-4 text-emerald-300"><?php echo $qPctAt; ?>%</td>
                                <td class="py-3 px-4 text-rose-400 font-bold"><?php echo $qData['abandonadas']; ?></td>
                                <td class="py-3 px-4 text-rose-300"><?php echo $qPctAb; ?>%</td>
                                <td class="py-3 px-4 text-amber-400"><?php echo $qData['timeout']; ?></td>
                                <td class="py-3 px-4 text-purple-300 font-bold"><?php echo $qPctSla; ?>%</td>
                                <td class="py-3 px-4 text-slate-300"><?php echo gmdate('i:s', $qMedEsp); ?></td>
                                <td class="py-3 px-4 text-amber-300 font-bold"><?php echo gmdate('i:s', $qData['max_espera']); ?></td>
                                <td class="py-3 px-4 text-slate-300"><?php echo gmdate('i:s', $qMedFal); ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    <?php endif; ?>

    <!-- EXTRATO ANALÍTICO COMPLETO DE CHAMADAS -->
    <div class="bg-slate-900/90 border border-slate-800 rounded-3xl p-6 shadow-2xl space-y-4">
        <div class="flex flex-col sm:flex-row items-start sm:items-center justify-between gap-3 border-b border-slate-800 pb-3">
            <h4 class="text-xs font-extrabold text-white uppercase tracking-wider flex items-center gap-2">
                <i class="fa-solid fa-list text-cyan-400"></i> Extrato Detalhado de Chamadas
            </h4>
            <span class="text-xs text-slate-400 font-mono">Total de <?php echo $totalChamadas; ?> chamadas encontradas</span>
        </div>

        <div class="overflow-x-auto custom-scrollbar">
            <table class="w-full text-left text-xs">
                <thead>
                    <tr class="bg-slate-950 text-slate-400 font-bold uppercase tracking-wider border-b border-slate-800">
                        <th class="py-3 px-4">#</th>
                        <th class="py-3 px-4">Data/Hora</th>
                        <th class="py-3 px-4">Fila</th>
                        <th class="py-3 px-4">Número (BINA)</th>
                        <th class="py-3 px-4">Agente</th>
                        <th class="py-3 px-4">Ramal</th>
                        <th class="py-3 px-4 text-center">Status</th>
                        <th class="py-3 px-4">Espera</th>
                        <th class="py-3 px-4">Duração</th>
                        <th class="py-3 px-4">Quem Desligou</th>
                        <th class="py-3 px-4 text-center print:hidden">Gravação</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-800/60 font-mono">
                    <?php if (empty($chamadas)): ?>
                        <tr>
                            <td colspan="11" class="py-8 text-center text-slate-500 italic">
                                Nenhuma chamada localizada para os filtros selecionados.
                            </td>
                        </tr>
                    <?php else: ?>
                        <?php $i = 1; foreach ($chamadas as $c): ?>
                            <tr class="hover:bg-slate-800/40 transition">
                                <td class="py-3 px-4 text-slate-500 font-bold"><?php echo $i++; ?></td>
                                <td class="py-3 px-4 text-slate-300"><?php echo htmlspecialchars($c['datetime']); ?></td>
                                <td class="py-3 px-4 font-extrabold text-purple-300 font-sans" title="<?php echo htmlspecialchars($c['fila_descr']); ?>">
                                    <?php echo htmlspecialchars($c['fila_descr']); ?>
                                </td>
                                <td class="py-3 px-4 text-cyan-300 font-bold"><?php echo htmlspecialchars($c['numero'] ?: '-'); ?></td>
                                <td class="py-3 px-4 text-slate-200 font-sans"><?php echo htmlspecialchars($c['agente'] ?: '-'); ?></td>
                                <td class="py-3 px-4 text-indigo-300 font-bold"><?php echo htmlspecialchars(fmtRamalDisplay($c['ramal'], $extNameMap)); ?></td>
                                <td class="py-3 px-4 text-center">
                                    <?php if ($c['status'] == 'ATENDIDA'): ?>
                                        <span class="px-2 py-0.5 rounded text-[10px] font-bold bg-emerald-500/20 text-emerald-300 border border-emerald-500/30">ATENDIDA</span>
                                    <?php elseif ($c['status'] == 'TIMEOUT'): ?>
                                        <span class="px-2 py-0.5 rounded text-[10px] font-bold bg-amber-500/20 text-amber-300 border border-amber-500/30">TIMEOUT</span>
                                    <?php else: ?>
                                        <span class="px-2 py-0.5 rounded text-[10px] font-bold bg-rose-500/20 text-rose-300 border border-rose-500/30">ABANDONO</span>
                                    <?php endif; ?>
                                </td>
                                <td class="py-3 px-4 text-slate-300"><?php echo $c['tempo_espera'] > 0 ? gmdate('i:s', $c['tempo_espera']) : '00:00'; ?></td>
                                <td class="py-3 px-4 text-slate-300"><?php echo $c['tempo_falando'] > 0 ? gmdate('i:s', $c['tempo_falando']) : '00:00'; ?></td>
                                <td class="py-3 px-4 text-slate-400 font-sans">
                                    <?php if ($c['quem_desligou'] == 'AGENTE'): ?>
                                        <span class="text-indigo-400 font-bold"><i class="fa-solid fa-user-tie"></i> Agente</span>
                                    <?php elseif ($c['quem_desligou'] == 'CLIENTE'): ?>
                                        <span class="text-rose-400 font-bold"><i class="fa-solid fa-user"></i> Cliente</span>
                                    <?php else: ?>
                                        -
                                    <?php endif; ?>
                                </td>
                                <td class="py-3 px-4 text-center print:hidden">
                                    <?php if ($c['status'] == 'ATENDIDA'): ?>
                                        <audio controls class="h-6 w-44 inline-block opacity-90">
                                            <source src="get_audio.php?uid=<?php echo urlencode($c['uniqueid']); ?>" type="audio/wav">
                                        </audio>
                                    <?php else: ?>
                                        <span class="text-slate-600">-</span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
<script>
function setPresetDate(preset) {
    document.getElementById('input_preset').value = preset;
    const form = document.getElementById('form-filtro-filas');
    
    const now = new Date();
    const formatDate = d => d.toISOString().split('T')[0];
    
    let start = new Date();
    let end = new Date();

    if (preset === 'hoje') {
        start = now;
        end = now;
    } else if (preset === 'ontem') {
        start.setDate(now.getDate() - 1);
        end.setDate(now.getDate() - 1);
    } else if (preset === 'semana') {
        start.setDate(now.getDate() - 7);
        end = now;
    } else if (preset === 'mes') {
        start = new Date(now.getFullYear(), now.getMonth(), 1);
        end = now;
    } else if (preset === '3meses') {
        start.setDate(now.getDate() - 90);
        end = now;
    } else if (preset === 'personalizado') {
        return; // Não altera datas
    }

    document.getElementById('input_data_inicio').value = formatDate(start);
    document.getElementById('input_data_fim').value = formatDate(end);
    form.submit();
}

document.addEventListener("DOMContentLoaded", function() {
    // Gráfico de Horas
    const ctxHours = document.getElementById('chart-queue-hours')?.getContext('2d');
    if (ctxHours) {
        new Chart(ctxHours, {
            type: 'bar',
            data: {
                labels: <?php echo $horasLabels; ?>,
                datasets: [
                    { label: 'Atendidas', data: <?php echo $horasAtendidas; ?>, backgroundColor: '#10b981', borderRadius: 4 },
                    { label: 'Abandonadas', data: <?php echo $horasAbandono; ?>, backgroundColor: '#f43f5e', borderRadius: 4 },
                    { label: 'Timeout', data: <?php echo $horasTimeout; ?>, backgroundColor: '#f59e0b', borderRadius: 4 }
                ]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                scales: {
                    x: { ticks: { color: '#cbd5e1' }, grid: { color: '#334155' } },
                    y: { ticks: { color: '#cbd5e1' }, grid: { color: '#334155' } }
                }
            }
        });
    }

    // Gráfico de Agentes
    const ctxAgents = document.getElementById('chart-queue-agents')?.getContext('2d');
    if (ctxAgents) {
        new Chart(ctxAgents, {
            type: 'bar',
            data: {
                labels: <?php echo $agLabelsJson; ?>,
                datasets: [
                    { label: 'Atendidas', data: <?php echo $agAtendJson; ?>, backgroundColor: '#8b5cf6', borderRadius: 4 },
                    { label: 'Não Atendidas / Recusadas', data: <?php echo $agNaoAtendJson; ?>, backgroundColor: '#ef4444', borderRadius: 4 }
                ]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                scales: {
                    x: { ticks: { color: '#cbd5e1' }, grid: { color: '#334155' } },
                    y: { ticks: { color: '#cbd5e1' }, grid: { color: '#334155' } }
                }
            }
        });
    }
});
</script>
