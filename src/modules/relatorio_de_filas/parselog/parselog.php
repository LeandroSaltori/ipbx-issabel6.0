#!/usr/bin/php -q
<?php
/**
 * IPbx Prisma - Parser de Logs de Filas (Asterisk queue_log -> qstatslite)
 * Compatível com PHP 5.4, 7.0, 7.4, 8.0, 8.1, 8.2, 8.3+
 * Zero dependências externas / 100% nativo com mysqli
 */

error_reporting(E_ALL & ~E_NOTICE & ~E_WARNING);
ini_set('display_errors', 0);
date_default_timezone_set('America/Sao_Paulo');

// --- 1. Resolução de Credenciais do Banco -------------------------------------
function getMysqlPassword() {
    $candidates = array('/etc/issabel.conf', '/etc/elastix.conf', '/etc/amportal.conf');
    foreach ($candidates as $f) {
        if (file_exists($f)) {
            $lines = file($f, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
            foreach ($lines as $line) {
                if (preg_match('/^\s*(?:mysqlrootpwd|AMPDBPASS)\s*=\s*(.*)$/i', $line, $m)) {
                    return trim(trim($m[1]), '"\'');
                }
            }
        }
    }
    return '';
}

$dbHost = 'localhost';
$dbUser = 'root';
$dbPass = getMysqlPassword();
$dbName = 'qstatslite';

// Conexão MySQL
mysqli_report(MYSQLI_REPORT_OFF);
$conn = @mysqli_connect($dbHost, $dbUser, $dbPass);
if (!$conn) {
    // Tenta sem senha caso tenha falhado
    $conn = @mysqli_connect($dbHost, $dbUser, '');
    if (!$conn) {
        echo "[-] Erro de conexao MySQL: " . mysqli_connect_error() . "\n";
        exit(1);
    }
}

mysqli_set_charset($conn, 'utf8');

// --- 2. Criação e Saneamento do Banco e Tabelas -------------------------------
@mysqli_query($conn, "CREATE DATABASE IF NOT EXISTS `$dbName` DEFAULT CHARACTER SET utf8 COLLATE utf8_general_ci");
if (!@mysqli_select_db($conn, $dbName)) {
    echo "[-] Erro ao selecionar o banco $dbName: " . mysqli_error($conn) . "\n";
    exit(1);
}

// Tabela qname
@mysqli_query($conn, "
CREATE TABLE IF NOT EXISTS `qname` (
  `qname_id` int(11) NOT NULL AUTO_INCREMENT,
  `queue` varchar(50) NOT NULL DEFAULT '',
  PRIMARY KEY (`qname_id`),
  UNIQUE KEY `idx_queue` (`queue`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8;
");

// Tabela qagent
@mysqli_query($conn, "
CREATE TABLE IF NOT EXISTS `qagent` (
  `agent_id` int(11) NOT NULL AUTO_INCREMENT,
  `agent` varchar(50) NOT NULL DEFAULT '',
  PRIMARY KEY (`agent_id`),
  UNIQUE KEY `idx_agent` (`agent`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8;
");

// Tabela qevent
@mysqli_query($conn, "
CREATE TABLE IF NOT EXISTS `qevent` (
  `event_id` int(11) NOT NULL,
  `event` varchar(50) NOT NULL DEFAULT '',
  PRIMARY KEY (`event_id`),
  UNIQUE KEY `idx_event` (`event`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8;
");

// Tabela queue_stats (com queue_stats_id AUTO_INCREMENT PRIMARY KEY mandatória)
@mysqli_query($conn, "
CREATE TABLE IF NOT EXISTS `queue_stats` (
  `queue_stats_id` int(12) NOT NULL AUTO_INCREMENT,
  `uniqueid` varchar(40) NOT NULL DEFAULT '',
  `datetime` datetime NOT NULL DEFAULT '0000-00-00 00:00:00',
  `qname` int(11) NOT NULL DEFAULT '0',
  `qagent` int(11) NOT NULL DEFAULT '0',
  `qevent` int(11) NOT NULL DEFAULT '0',
  `info1` varchar(100) NOT NULL DEFAULT '',
  `info2` varchar(100) NOT NULL DEFAULT '',
  `info3` varchar(100) NOT NULL DEFAULT '',
  `info4` varchar(100) NOT NULL DEFAULT '',
  `info5` varchar(100) NOT NULL DEFAULT '',
  PRIMARY KEY (`queue_stats_id`),
  KEY `idx_dt` (`datetime`),
  KEY `idx_uid` (`uniqueid`),
  KEY `idx_qn` (`qname`),
  KEY `idx_qa` (`qagent`),
  KEY `idx_qe` (`qevent`),
  UNIQUE KEY `unico` (`uniqueid`, `datetime`, `qname`, `qagent`, `qevent`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8;
");

// Verificação de autocura: se a tabela já existia sem queue_stats_id, altera agora
$colRes = @mysqli_query($conn, "SHOW COLUMNS FROM `queue_stats` LIKE 'queue_stats_id'");
if ($colRes && mysqli_num_rows($colRes) == 0) {
    @mysqli_query($conn, "ALTER TABLE `queue_stats` ADD COLUMN `queue_stats_id` int(12) NOT NULL AUTO_INCREMENT PRIMARY KEY FIRST");
}

// Inserir eventos padrão em qevent se não existirem
$eventSeed = array(
    1  => 'ABANDON',
    2  => 'AGENTDUMP',
    3  => 'AGENTLOGIN',
    4  => 'AGENTCALLBACKLOGIN',
    5  => 'AGENTLOGOFF',
    6  => 'AGENTCALLBACKLOGOFF',
    7  => 'COMPLETEAGENT',
    8  => 'COMPLETECALLER',
    9  => 'CONFIGRELOAD',
    10 => 'CONNECT',
    11 => 'ENTERQUEUE',
    12 => 'EXITWITHKEY',
    13 => 'EXITWITHTIMEOUT',
    14 => 'QUEUESTART',
    15 => 'SYSCOMPAT',
    16 => 'TRANSFER',
    17 => 'PAUSE',
    18 => 'UNPAUSE',
    19 => 'RINGNOANSWER',
    20 => 'EXITEMPTY',
    21 => 'PAUSEALL',
    22 => 'UNPAUSEALL'
);

foreach ($eventSeed as $eid => $ename) {
    @mysqli_query($conn, "INSERT IGNORE INTO `qevent` (`event_id`, `event`) VALUES ($eid, '$ename')");
}

// --- 3. Sincronização de Filas do FreePBX / Asterisk para qname ----------------
// Garante que todas as filas criadas no sistema apareçam no dropdown de filtros
$rAstQ1 = @mysqli_query($conn, "SELECT DISTINCT `extension` FROM `asterisk`.`queues_config` WHERE `extension` != ''");
if ($rAstQ1) {
    while ($row = mysqli_fetch_assoc($rAstQ1)) {
        $q = trim($row['extension']);
        if ($q != '' && $q != 'NONE') {
            @mysqli_query($conn, "INSERT IGNORE INTO `qname` (`queue`) VALUES ('" . mysqli_real_escape_string($conn, $q) . "')");
        }
    }
}

$rAstQ2 = @mysqli_query($conn, "SELECT DISTINCT `queue` FROM `asterisk`.`queues` WHERE `queue` != ''");
if ($rAstQ2) {
    while ($row = mysqli_fetch_assoc($rAstQ2)) {
        $q = trim($row['queue']);
        if ($q != '' && $q != 'NONE') {
            @mysqli_query($conn, "INSERT IGNORE INTO `qname` (`queue`) VALUES ('" . mysqli_real_escape_string($conn, $q) . "')");
        }
    }
}

if (file_exists('/etc/asterisk/queues_additional.conf')) {
    $qLines = file('/etc/asterisk/queues_additional.conf', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($qLines as $ql) {
        if (preg_match('/^\[(\d+)\]/', trim($ql), $qm)) {
            $q = $qm[1];
            @mysqli_query($conn, "INSERT IGNORE INTO `qname` (`queue`) VALUES ('" . mysqli_real_escape_string($conn, $q) . "')");
        }
    }
}

// Inserir NONE padrão
@mysqli_query($conn, "INSERT IGNORE INTO `qname` (`queue`) VALUES ('NONE')");
@mysqli_query($conn, "INSERT IGNORE INTO `qagent` (`agent`) VALUES ('NONE')");

// --- 4. Caches em Memória para Performance ------------------------------------
$qnameCache = array();
$rQn = @mysqli_query($conn, "SELECT `qname_id`, `queue` FROM `qname`");
if ($rQn) {
    while ($row = mysqli_fetch_assoc($rQn)) {
        $qnameCache[$row['queue']] = (int)$row['qname_id'];
    }
}

$agentCache = array();
$rQa = @mysqli_query($conn, "SELECT `agent_id`, `agent` FROM `qagent`");
if ($rQa) {
    while ($row = mysqli_fetch_assoc($rQa)) {
        $agentCache[$row['agent']] = (int)$row['agent_id'];
    }
}

$eventCache = array();
$rQe = @mysqli_query($conn, "SELECT `event_id`, `event` FROM `qevent`");
if ($rQe) {
    while ($row = mysqli_fetch_assoc($rQe)) {
        $eventCache[$row['event']] = (int)$row['event_id'];
    }
}

function getIdForQueue($conn, $queue, &$qnameCache) {
    $queue = trim($queue);
    if ($queue === '') $queue = 'NONE';
    if (isset($qnameCache[$queue])) {
        return $qnameCache[$queue];
    }
    $esc = mysqli_real_escape_string($conn, $queue);
    @mysqli_query($conn, "INSERT IGNORE INTO `qname` (`queue`) VALUES ('$esc')");
    $id = (int)mysqli_insert_id($conn);
    if ($id <= 0) {
        $r = @mysqli_query($conn, "SELECT `qname_id` FROM `qname` WHERE `queue` = '$esc' LIMIT 1");
        if ($r && $row = mysqli_fetch_assoc($r)) {
            $id = (int)$row['qname_id'];
        }
    }
    $qnameCache[$queue] = $id;
    return $id;
}

function getIdForAgent($conn, $agent, &$agentCache) {
    $agent = trim($agent);
    if ($agent === '') $agent = 'NONE';
    if (isset($agentCache[$agent])) {
        return $agentCache[$agent];
    }
    $esc = mysqli_real_escape_string($conn, $agent);
    @mysqli_query($conn, "INSERT IGNORE INTO `qagent` (`agent`) VALUES ('$esc')");
    $id = (int)mysqli_insert_id($conn);
    if ($id <= 0) {
        $r = @mysqli_query($conn, "SELECT `agent_id` FROM `qagent` WHERE `agent` = '$esc' LIMIT 1");
        if ($r && $row = mysqli_fetch_assoc($r)) {
            $id = (int)$row['agent_id'];
        }
    }
    $agentCache[$agent] = $id;
    return $id;
}

function getIdForEvent($conn, $event, &$eventCache) {
    $event = trim($event);
    if (isset($eventCache[$event])) {
        return $eventCache[$event];
    }
    // Novo evento não catalogado
    $esc = mysqli_real_escape_string($conn, $event);
    $newId = count($eventCache) + 1;
    @mysqli_query($conn, "INSERT IGNORE INTO `qevent` (`event_id`, `event`) VALUES ($newId, '$esc')");
    $eventCache[$event] = $newId;
    return $newId;
}

// --- 5. Determinação do Ponto de Leitura no queue_log -------------------------
$parseAll = (isset($argv[1]) && in_array(strtolower($argv[1]), array('all', '--all', 'purge', 'full')));

if (isset($argv[1]) && strtolower($argv[1]) === 'purge') {
    echo "[!] Limpando dados do qstatslite...\n";
    @mysqli_query($conn, "TRUNCATE TABLE `queue_stats`");
    @mysqli_query($conn, "TRUNCATE TABLE `qname`");
    @mysqli_query($conn, "TRUNCATE TABLE `qagent`");
    echo "[✓] Tabelas limpas.\n";
    $parseAll = true;
}

$lastTs = 0;
if (!$parseAll) {
    $rMax = @mysqli_query($conn, "SELECT UNIX_TIMESTAMP(MAX(`datetime`)) AS last_ts FROM `queue_stats`");
    if ($rMax && $row = mysqli_fetch_assoc($rMax)) {
        if (!empty($row['last_ts'])) {
            $lastTs = (int)$row['last_ts'] - 15; // 15 segundos de overlap de segurança
            if ($lastTs < 0) $lastTs = 0;
        }
    }
}

// Arquivos a processar
$logFiles = array();
$queueLogDir = '/var/log/asterisk';

if ($parseAll || $lastTs === 0) {
    // Busca logs rotacionados para carga completa
    $rotFiles = glob($queueLogDir . '/queue_log-*');
    if (!empty($rotFiles)) {
        sort($rotFiles);
        foreach ($rotFiles as $rf) {
            $logFiles[] = $rf;
        }
    }
}

if (file_exists($queueLogDir . '/queue_log')) {
    $logFiles[] = $queueLogDir . '/queue_log';
}

if (empty($logFiles)) {
    echo "[-] Nenhum arquivo queue_log encontrado em $queueLogDir\n";
    exit(0);
}

// --- 6. Processamento dos Logs ------------------------------------------------
$totalProcessed = 0;
$totalInserted = 0;
$batch = array();
$batchLimit = 300;

function flushBatch($conn, &$batch, &$totalInserted) {
    if (empty($batch)) return;
    $sql = "INSERT IGNORE INTO `queue_stats` (`uniqueid`, `datetime`, `qname`, `qagent`, `qevent`, `info1`, `info2`, `info3`, `info4`, `info5`) VALUES " . implode(',', $batch);
    if (@mysqli_query($conn, $sql)) {
        $totalInserted += mysqli_affected_rows($conn);
    }
    $batch = array();
}

foreach ($logFiles as $logFile) {
    $fp = @fopen($logFile, 'r');
    if (!$fp) continue;

    while (($line = fgets($fp, 4096)) !== false) {
        $line = trim($line);
        if ($line === '' || $line[0] === '#') continue;

        $parts = explode('|', $line);
        if (count($parts) < 5) continue;

        $tsRaw    = trim($parts[0]);
        $uniqueid = trim($parts[1]);
        $queue    = trim($parts[2]);
        $agent    = trim($parts[3]);
        $event    = trim($parts[4]);
        $info1    = isset($parts[5]) ? trim($parts[5]) : '';
        $info2    = isset($parts[6]) ? trim($parts[6]) : '';
        $info3    = isset($parts[7]) ? trim($parts[7]) : '';
        $info4    = isset($parts[8]) ? trim($parts[8]) : '';
        $info5    = isset($parts[9]) ? trim($parts[9]) : '';

        if (!is_numeric($tsRaw)) continue;
        $ts = (int)$tsRaw;
        if ($ts <= 0 || $ts < $lastTs) continue;

        $totalProcessed++;
        $dtStr = date('Y-m-d H:i:s', $ts);

        // Normalização amigável de canal do agente (Local/207@from-queue -> SIP/207 ou 207)
        $cleanAgent = $agent;
        if (preg_match('/^Local\/([0-9a-zA-Z_\-]+)@/i', $cleanAgent, $am)) {
            $cleanAgent = 'SIP/' . $am[1];
        } elseif (preg_match('/^([^\-]+)\-[0-9a-fA-F]+/i', $cleanAgent, $am)) {
            $cleanAgent = $am[1];
        }

        $qnId = getIdForQueue($conn, $queue, $qnameCache);
        $qaId = getIdForAgent($conn, $cleanAgent, $agentCache);
        $qeId = getIdForEvent($conn, $event, $eventCache);

        $uidEsc = mysqli_real_escape_string($conn, substr($uniqueid, 0, 40));
        $i1Esc  = mysqli_real_escape_string($conn, substr($info1, 0, 100));
        $i2Esc  = mysqli_real_escape_string($conn, substr($info2, 0, 100));
        $i3Esc  = mysqli_real_escape_string($conn, substr($info3, 0, 100));
        $i4Esc  = mysqli_real_escape_string($conn, substr($info4, 0, 100));
        $i5Esc  = mysqli_real_escape_string($conn, substr($info5, 0, 100));

        $batch[] = "('$uidEsc', '$dtStr', $qnId, $qaId, $qeId, '$i1Esc', '$i2Esc', '$i3Esc', '$i4Esc', '$i5Esc')";

        if (count($batch) >= $batchLimit) {
            flushBatch($conn, $batch, $totalInserted);
        }
    }
    fclose($fp);
}

flushBatch($conn, $batch, $totalInserted);

if (php_sapi_name() === 'cli' && isset($argv[1])) {
    echo "[✓] Processamento concluido: $totalProcessed eventos lidos, $totalInserted novos registros gravados.\n";
}
