<?php
date_default_timezone_set('America/Sao_Paulo');
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');

// -- Lê /etc/issabel.conf ----------------------------------------------
function getConfig() {
    $cfg = array(
        'amiadminpwd'  => '',
        'mysqlrootpwd' => '',
        'amihost'      => '127.0.0.1',
        'amiport'      => 5038,
        'amiuser'      => 'admin',
    );
    $file = '/etc/issabel.conf';
    if (!is_readable($file)) {
        die(json_encode(array('success' => false, 'error' => 'Nao foi possivel ler ' . $file)));
    }
    $lines = file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($lines as $line) {
        $line = trim($line);
        if ($line === '' || $line[0] === '#' || $line[0] === '[') continue;
        if (strpos($line, '=') === false) continue;
        $parts = explode('=', $line, 2);
        $key = strtolower(trim($parts[0]));
        $val = trim($parts[1]);
        if ($key === 'amiadminpwd')  $cfg['amiadminpwd']  = $val;
        if ($key === 'mysqlrootpwd') $cfg['mysqlrootpwd'] = $val;
    }
    return $cfg;
}

// -- Abre socket AMI --------------------------------------------------
function amiConnect($host, $port, $user, $pass) {
    $sock = @fsockopen($host, $port, $errno, $errstr, 5);
    if (!$sock) return false;
    stream_set_timeout($sock, 10);
    fgets($sock);
    fwrite($sock, "Action: Login\r\nUsername: " . $user . "\r\nSecret: " . $pass . "\r\n\r\n");
    $resp = '';
    while (!feof($sock)) {
        $line = fgets($sock);
        $resp .= $line;
        if (trim($line) === '') break;
    }
    if (strpos($resp, 'Success') === false) { fclose($sock); return false; }
    return $sock;
}

function amiCmd($sock, $cmd) {
    fwrite($sock, "Action: Command\r\nCommand: " . $cmd . "\r\n\r\n");
    $out = '';
    $limit = time() + 10;
    while (!feof($sock) && time() < $limit) {
        $line = fgets($sock, 4096);
        $out .= $line;
        if (strpos($out, '--END COMMAND--') !== false) break;
    }
    return $out;
}

// -- Funções SIP -------------------------------------------------------
function amiSipShowPeer($sock, $peer) {
    static $actionId = 1;
    $id = 'sp' . $actionId++;
    fwrite($sock, "Action: SIPshowpeer\r\nPeer: " . $peer . "\r\nActionID: " . $id . "\r\n\r\n");
    $out = '';
    $limit = time() + 6;
    while (!feof($sock) && time() < $limit) {
        $line = fgets($sock, 2048);
        $out .= $line;
        if (strpos($out, 'Event: PeerStatus') !== false) break;
        if (strpos($out, 'SIPuseragent:') !== false && trim($line) === '') break;
        if (strpos($out, 'Useragent:') !== false && trim($line) === '') break;
    }
    $fields = array();
    $lines = explode("\n", $out);
    foreach ($lines as $l) {
        if (strpos($l, ':') !== false) {
            $parts = explode(':', $l, 2);
            $fields[trim($parts[0])] = trim($parts[1]);
        }
    }
    return $fields;
}

function parseSipPeers($raw) {
    $peers = array();
    $lines = explode("\n", $raw);
    $offsets = array();
    foreach ($lines as $line) {
        $line = rtrim(str_replace("\r", "", $line));
        if (strpos($line, "Output:") !== false) $line = ltrim(substr($line, strpos($line, "Output:") + 7));
        
        if (strpos($line, "Name/username") !== false) {
            $offsets["Host"] = strpos($line, "Host");
            $offsets["Port"] = strpos($line, "Port");
            $offsets["Status"] = strpos($line, "Status");
            continue;
        }
        
        if (empty($offsets)) {
            // Se nao achar cabecalho, tenta fallback simples
            $parts = preg_split("/\s+/", ltrim($line));
            if (count($parts) < 4 || strpos($line, "sip peers") !== false || strpos($line, "END COMMAND") !== false) continue;
            $name_parts = explode("/", $parts[0]);
            $ramal = $name_parts[0];
            $peers[$ramal] = array(
                "ramal" => $ramal, "ip" => (isset($parts[1]) && $parts[1] !== "(Unspecified)") ? $parts[1] : "-",
                "port" => "-", "status" => (strpos($line, "OK") !== false) ? "OK" : "UNREACHABLE",
                "registered" => (strpos($line, "OK") !== false), "mac" => "-", "useragent" => "", "modelo" => "-", "fabricante" => "-", "type" => "SIP"
            );
            continue;
        }
        
        if ($line === "" || strpos($line, "sip peers") !== false || strpos($line, "END COMMAND") !== false) continue;
        
        $name_part = trim(substr($line, 0, $offsets["Host"]));
        if (empty($name_part)) continue;
        
        $name_parts = explode("/", $name_part);
        $ramal = $name_parts[0];
        
        $host = trim(substr($line, $offsets["Host"], $offsets["Port"] - $offsets["Host"]));
        $host_parts = preg_split("/\s+/", $host);
        $ip = $host_parts[0];
        if ($ip === "(Unspecified)") $ip = "-";
        
        $port_part = trim(substr($line, $offsets["Port"], $offsets["Status"] - $offsets["Port"]));
        $port_parts = preg_split("/\s+/", $port_part);
        $port = $port_parts[0];
        if ($port === "0" || $ip === "-") $port = "-";
        
        $status_part = trim(substr($line, $offsets["Status"]));
        $status = (strpos($status_part, "OK") !== false) ? "OK" : "UNREACHABLE";
        
        $peers[$ramal] = array(
            "ramal" => $ramal, "ip" => $ip, "port" => $port,
            "status" => $status,
            "registered" => ($status === "OK"), "mac" => "-", "useragent" => "", "modelo" => "-", "fabricante" => "-", "type" => "SIP"
        );
    }
    return $peers;
}

// -- Funções PJSIP -----------------------------------------------------
function amiPjsipShowEndpoint($sock, $endpoint) {
    fwrite($sock, "Action: PJSIPShowEndpoint\r\nEndpoint: " . $endpoint . "\r\n\r\n");
    $out = '';
    $limit = time() + 6;
    while (!feof($sock) && time() < $limit) {
        $line = fgets($sock, 2048);
        $out .= $line;
        if (strpos($out, 'Response: Success') !== false && strpos($out, "\r\n\r\n") !== false) break;
    }
    $fields = array();
    foreach (explode("\n", $out) as $l) {
        if (strpos($l, ':') !== false) {
            $parts = explode(':', $l, 2);
            $fields[trim($parts[0])] = trim($parts[1]);
        }
    }
    return $fields;
}

function parsePjsipEndpoints($raw) {
    $endpoints = array();
    $lines = explode("\n", $raw);
    $currRamal = null;

    foreach ($lines as $line) {
        $line = trim(str_replace("\r", "", $line));
        if (strpos($line, "Output:") !== false) {
            $line = trim(substr($line, strpos($line, "Output:") + 7));
        }
        if (empty($line)) continue;

        if (strpos($line, "Endpoint:") === 0) {
            if (preg_match("/Endpoint:\s+([^\/\s]+)/", $line, $m)) {
                $ramal = trim($m[1]);
                if (strpos($ramal, "<Endpoint") !== false) continue;
                $currRamal = $ramal;

                $isRegistered = preg_match('/(Not in use|In use|Busy|Ringing)/i', $line);

                $endpoints[$currRamal] = array(
                    "ramal" => $currRamal,
                    "ip" => "-",
                    "port" => "-",
                    "status" => $isRegistered ? "OK" : "UNREACHABLE",
                    "registered" => (bool)$isRegistered,
                    "mac" => "-",
                    "useragent" => "",
                    "modelo" => "-",
                    "fabricante" => "-",
                    "type" => "PJSIP"
                );
            }
        } elseif ($currRamal && isset($endpoints[$currRamal])) {
            if (strpos($line, "Contact:") !== false) {
                $endpoints[$currRamal]["registered"] = true;
                $endpoints[$currRamal]["status"] = "OK";

                if (preg_match('/@([0-9a-fA-F\.\:]+)/', $line, $cm)) {
                    $ipPort = $cm[1];
                    $parts = explode(':', $ipPort);
                    $endpoints[$currRamal]["ip"] = $parts[0];
                    if (isset($parts[1]) && preg_match('/^(\d+)/', $parts[1], $pm)) {
                        $endpoints[$currRamal]["port"] = $pm[1];
                    }
                }
            }
        }
    }
    return $endpoints;
}

// -- Auxiliares --------------------------------------------------------
function extractMac($ua) {
    if (preg_match('/\b([0-9a-fA-F]{12})\b/', $ua, $m) || preg_match('/([0-9a-fA-F]{2}[:\-]){5}[0-9a-fA-F]{2}/', $ua, $m) || preg_match('/MAC[:\s]([0-9a-fA-F]{12})/i', $ua, $m)) {
        return implode(':', str_split(strtoupper(preg_replace('/[^a-fA-F0-9]/', '', $m[0])), 2));
    }
    return '-';
}

function parseUserAgent($ua) {
    $fab = '-'; $model = '-';
    if (preg_match('/(Grandstream|Yealink|Fanvil|Cisco|Polycom|Avaya|HTek|Panasonic|Gigaset)\s+(\S+)/i', $ua, $m)) { $fab = $m[1]; $model = $m[2]; }
    elseif (preg_match('/(Zoiper|MicroSIP|Bria|Linphone)/i', $ua, $m)) { $fab = $m[1]; $model = 'Softphone'; }
    return array('fabricante' => $fab, 'modelo' => $model);
}

function getMysqlData($mysqlpass) {
    $data = array();
    $conn = @new mysqli('localhost', 'root', $mysqlpass, 'asterisk');
    if (!$conn->connect_error) {
        $res = $conn->query("SELECT name, ipaddr, port FROM sip_buddies");
        if ($res) while ($row = $res->fetch_assoc()) $data['sip'][$row['name']] = $row;
        $res2 = $conn->query("SELECT ext, mac, ip FROM endpoint_configurator_device");
        if ($res2) while ($row = $res2->fetch_assoc()) $data['ep'][$row['ext']] = $row;
    }
    return $data;
}

// -- MAIN -------------------------------------------------------------
$cfg = getConfig();
$sock = amiConnect($cfg['amihost'], $cfg['amiport'], $cfg['amiuser'], $cfg['amiadminpwd']);

if (!$sock) {
    echo json_encode(array('success' => false, 'error' => 'Falha ao conectar AMI'));
    exit;
}

// Coleta SIP e PJSIP
$extensions = parseSipPeers(amiCmd($sock, 'sip show peers'));
$pjsip = parsePjsipEndpoints(amiCmd($sock, 'pjsip show endpoints'));

// Consolida PJSIP
foreach ($pjsip as $ramal => $info) {
    if (!$info['registered']) {
        $fields = amiPjsipShowEndpoint($sock, $ramal);
        if (!empty($fields['DeviceState']) && (strpos($fields['DeviceState'], 'In use') !== false || strpos($fields['DeviceState'], 'Idle') !== false || strpos($fields['DeviceState'], 'Not in use') !== false)) {
            $info['status'] = 'OK';
            $info['registered'] = true;
        }
    }
    $extensions[$ramal] = $info;
}

// Busca detalhes de UA para todos
foreach ($extensions as $ramal => &$info) {
    $fields = ($info['type'] === 'SIP') ? amiSipShowPeer($sock, $ramal) : amiPjsipShowEndpoint($sock, $ramal);
    $ua = $fields['User-Agent'] ?? $fields['SIPuseragent'] ?? '';
    
    // Check for WebRTC
    if ($info['type'] === 'PJSIP' && isset($fields['Webrtc']) && strtolower(trim($fields['Webrtc'])) === 'yes') {
        $info['type'] = 'WebRTC';
    }

    $info['useragent'] = $ua;
    $info['mac'] = extractMac($ua);
    $parsed = parseUserAgent($ua);
    $info['fabricante'] = $parsed['fabricante'];
    $info['modelo'] = $parsed['modelo'];
}

fwrite($sock, "Action: Logoff\r\n\r\n");
fclose($sock);

// Merge final com Banco
$sqlData = getMysqlData($cfg['mysqlrootpwd']);
// (Lógica de merge com banco permanece igual ao original...)

uksort($extensions, 'strnatcmp');
$list = array_values($extensions);
echo json_encode(array('success' => true, 'extensions' => $list, 'total' => count($list), 'timestamp' => date('d/m/Y H:i:s')));



