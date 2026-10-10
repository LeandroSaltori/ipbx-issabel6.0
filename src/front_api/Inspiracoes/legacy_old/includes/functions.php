<?php
/**
 * IPbx Prisma - Núcleo de Funções Auxiliares (AMI, Issabel DB, WhatsApp API)
 */

require_once __DIR__ . '/../config/database.php';

function getSetting($key) {
    global $db;
    $stmt = $db->prepare("SELECT value_val FROM settings WHERE key_name = :key");
    $stmt->execute([':key' => $key]);
    $res = $stmt->fetch(PDO::FETCH_ASSOC);
    return $res ? $res['value_val'] : '';
}

function getRule($key) {
    global $db;
    $stmt = $db->prepare("SELECT value_val FROM integration_rules WHERE key_name = :key");
    $stmt->execute([':key' => $key]);
    $res = $stmt->fetch(PDO::FETCH_ASSOC);
    return $res ? $res['value_val'] : '';
}

function getAsteriskAMICredentials() {
    $user = getSetting('ami_user');
    $secret = getSetting('ami_secret');

    if (!empty($user) && !empty($secret)) {
        return ['user' => $user, 'secret' => $secret];
    }

    if (file_exists('/etc/amportal.conf') && is_readable('/etc/amportal.conf')) {
        $lines = @file('/etc/amportal.conf', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        if (!empty($lines)) {
            $amp_user = '';
            $amp_pass = '';
            foreach ($lines as $line) {
                $line = trim($line);
                if (strpos($line, 'AMPMGRUSER=') === 0) {
                    $amp_user = trim(substr($line, strlen('AMPMGRUSER=')));
                } elseif (strpos($line, 'AMPMGRPASS=') === 0) {
                    $amp_pass = trim(substr($line, strlen('AMPMGRPASS=')));
                }
            }
            if (!empty($amp_user) && !empty($amp_pass)) {
                return ['user' => $amp_user, 'secret' => $amp_pass];
            }
        }
    }

    $files_to_check = ['/etc/asterisk/manager_custom.conf', '/etc/asterisk/manager.conf', '/etc/asterisk/manager_additional.conf'];
    foreach ($files_to_check as $file) {
        if (file_exists($file) && is_readable($file)) {
            $lines = @file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
            if (!empty($lines)) {
                $current_user = '';
                foreach ($lines as $line) {
                    $line = trim($line);
                    if (preg_match('/^\[(.*)\]$/', $line, $m)) {
                        $current_user = strtolower($m[1]);
                    } elseif (!empty($current_user) && ($current_user === 'admin' || $current_user === strtolower($user))) {
                        if (strpos($line, '=') !== false) {
                            list($k, $v) = explode('=', $line, 2);
                            if (strtolower(trim($k)) === 'secret') {
                                $parsed_secret = trim($v);
                                if (!empty($parsed_secret)) {
                                    return ['user' => $current_user, 'secret' => $parsed_secret];
                                }
                            }
                        }
                    }
                }
            }
        }
    }

    return ['user' => $user ?: 'admin', 'secret' => $secret ?: 'elaStix.aSterisk.pass'];
}

function executeClickToCall($from_ext, $destination_num) {
    global $db;
    $host = getSetting('ami_host') ?: '127.0.0.1';
    $port = (int)(getSetting('ami_port') ?: 5038);

    $credentials_to_try = [
        ['user' => getSetting('ami_user') ?: 'admin', 'secret' => getSetting('ami_secret') ?: 'elaStix.aSterisk.pass'],
        getAsteriskAMICredentials(),
        ['user' => 'admin', 'secret' => 'elaStix.aSterisk.pass'],
        ['user' => 'admin', 'secret' => 'amp111'],
        ['user' => 'admin', 'secret' => 'elastix']
    ];

    $socket = null;
    $authenticated = false;

    foreach ($credentials_to_try as $cred) {
        if (empty($cred['user']) || empty($cred['secret'])) continue;
        $s = @fsockopen($host, $port, $errno, $errstr, 3);
        if (!$s) continue;

        $login_msg = "Action: Login\r\nUsername: {$cred['user']}\r\nSecret: {$cred['secret']}\r\n\r\n";
        fwrite($s, $login_msg);
        
        $response = '';
        while (!feof($s)) {
            $line = fgets($s, 1024);
            $response .= $line;
            if (trim($line) == '') break;
        }

        if (strpos($response, 'Authentication accepted') !== false) {
            $socket = $s;
            $authenticated = true;
            if ($cred['secret'] !== getSetting('ami_secret')) {
                $stmt = $db->prepare("INSERT OR REPLACE INTO settings (key_name, value_val) VALUES ('ami_user', :u), ('ami_secret', :s)");
                $stmt->execute([':u' => $cred['user'], ':s' => $cred['secret']]);
            }
            break;
        }
        fclose($s);
    }

    if (!$authenticated || !$socket) {
        return ['success' => false, 'error' => "Falha de autenticação AMI Asterisk ($host:$port). Verifique as credenciais no menu Configurações."];
    }

    $channel = "Local/{$from_ext}@from-internal";
    if ($db) {
        $stmt_ext = $db->prepare("SELECT tech FROM extensions_config WHERE extension = :ext LIMIT 1");
        $stmt_ext->execute([':ext' => $from_ext]);
        $stored_tech = strtoupper($stmt_ext->fetchColumn() ?: '');
        if ($stored_tech === 'SIP') {
            $channel = "SIP/{$from_ext}";
        } elseif ($stored_tech === 'PJSIP') {
            $channel = "PJSIP/{$from_ext}";
        }
    }

    $originate_msg = "Action: Originate\r\n";
    $originate_msg .= "Channel: {$channel}\r\n";
    $originate_msg .= "Context: from-internal\r\n";
    $originate_msg .= "Exten: {$destination_num}\r\n";
    $originate_msg .= "Priority: 1\r\n";
    $originate_msg .= "CallerID: ClickToCall <{$from_ext}>\r\n";
    $originate_msg .= "Async: true\r\n\r\n";

    fwrite($socket, $originate_msg);
    
    $orig_response = '';
    while (!feof($socket)) {
        $line = fgets($socket, 1024);
        $orig_response .= $line;
        if (trim($line) == '') break;
    }

    fwrite($socket, "Action: Logoff\r\n\r\n");
    fclose($socket);

    return ['success' => true, 'raw' => $orig_response, 'channel' => $channel];
}

function getIssabelSystemConfig() {
    $conf = ['mysqlrootpwd' => '', 'ampdbuser' => 'asteriskuser', 'ampdbpass' => 'eLaStIx.AsTeRiSk.UsEr.1'];
    $files_to_check = ['/etc/issabel.conf', '/etc/elastix.conf', '/etc/amportal.conf'];
    foreach ($files_to_check as $file) {
        if (file_exists($file)) {
            $lines = file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
            foreach ($lines as $line) {
                if (strpos($line, '=') === false) continue;
                list($k, $v) = explode('=', $line, 2);
                $k = strtolower(trim($k));
                $v = trim($v);
                if ($k === 'mysqlrootpwd') $conf['mysqlrootpwd'] = $v;
                if ($k === 'ampdbuser') $conf['ampdbuser'] = $v;
                if ($k === 'ampdbpass') $conf['ampdbpass'] = $v;
            }
        }
    }
    return $conf;
}

function autoInjectIssabelDialplan() {
    $file = '/etc/asterisk/extensions_custom.conf';
    if (!file_exists($file) || !is_writable($file)) {
        return false;
    }
    $content = file_get_contents($file);
    $needs_reload = false;

    if (strpos($content, '[sub-prismabot-missed]') !== false) {
        $content = preg_replace('/; --- INTEGRACAO AUTOMATICA IPBX PRISMA X PRISMABOT ---.*?exten => s,n\(fim\),Return\(\)\n/s', '', $content);
        $needs_reload = true;
    }

    if (strpos($content, 'whatsapp_agi.php') === false) {
        $dialplan_block = "\n; --- HOOK SEGURO IPBX PRISMA X PRISMABOT ---\n" .
            "[macro-hangupcall-custom]\n" .
            "exten => s,1,NoOp(=== VERIFICANDO CHAMADA PERDIDA PRISMABOT ===)\n" .
            "exten => s,n,GotoIf($[\"\${DIALSTATUS}\" = \"ANSWER\"]?fim)\n" .
            "exten => s,n,GotoIf($[\"\${DIALSTATUS}\" = \"\"]?fim)\n" .
            "exten => s,n,AGI(/var/www/html/whatsapp_panel/whatsapp_agi.php,\${CALLERID(num)},missed_extension,\${MACRO_EXTEN},0,\${DIALEDTIME})\n" .
            "exten => s,n(fim),Return()\n";
        
        $content .= $dialplan_block;
        $needs_reload = true;
    }

    if ($needs_reload) {
        @file_put_contents($file, $content);
        if (function_exists('shell_exec')) {
            @shell_exec('asterisk -rx "dialplan reload" 2>/dev/null');
        }
        return true;
    }
    return true;
}

autoInjectIssabelDialplan();

function syncPrismaAssets() {
    global $db;
    $sys_conf = getIssabelSystemConfig();
    
    $hosts_to_try = [getSetting('asterisk_db_host') ?: 'localhost', '127.0.0.1'];
    $creds_to_try = [
        ['user' => 'root', 'pass' => $sys_conf['mysqlrootpwd']],
        ['user' => getSetting('asterisk_db_user') ?: 'asteriskuser', 'pass' => getSetting('asterisk_db_pass') ?: 'eLaStIx.AsTeRiSk.UsEr.1'],
        ['user' => $sys_conf['ampdbuser'], 'pass' => $sys_conf['ampdbpass']],
        ['user' => 'root', 'pass' => 'eLaStIx.AsTeRiSk.UsEr.1'],
        ['user' => 'root', 'pass' => '']
    ];

    $ast_db = null;
    $last_error = '';

    foreach ($hosts_to_try as $h) {
        foreach ($creds_to_try as $c) {
            if (empty($c['user']) && $c['user'] !== 'root') continue;
            try {
                $ast_db = new PDO("mysql:host={$h};dbname=asterisk;charset=utf8", $c['user'], $c['pass'], [
                    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_TIMEOUT => 3
                ]);
                if ($ast_db) break 2;
            } catch (Exception $e) {
                $last_error = $e->getMessage();
            }
        }
    }

    $ext_count = 0;
    $queue_count = 0;

    if ($ast_db) {
        $db->exec("DELETE FROM extensions_config WHERE extension IN ('1001','1002','1003','1004','1005') AND (agent_name LIKE '%Silva%' OR agent_name LIKE '%Oliveira%' OR agent_name LIKE '%Santos%' OR agent_name LIKE '%Lima%' OR agent_name LIKE '%Souza%')");

        $extensions_found = [];
        $webrtc_exts = [];
        try {
            $q_w1 = $ast_db->query("SELECT id FROM sip WHERE keyword = 'webrtc' AND value = 'yes'");
            if ($q_w1) while ($r = $q_w1->fetch(PDO::FETCH_ASSOC)) { $webrtc_exts[$r['id']] = true; }
        } catch (Exception $ew1) {}
        try {
            $q_w2 = $ast_db->query("SELECT id FROM sip WHERE keyword = 'transport' AND (value LIKE '%wss%' OR value LIKE '%ws%')");
            if ($q_w2) while ($r = $q_w2->fetch(PDO::FETCH_ASSOC)) { $webrtc_exts[$r['id']] = true; }
        } catch (Exception $ew2) {}
        try {
            $q_w3 = $ast_db->query("SELECT id FROM pjsip WHERE keyword = 'webrtc' AND value = 'yes'");
            if ($q_w3) while ($r = $q_w3->fetch(PDO::FETCH_ASSOC)) { $webrtc_exts[$r['id']] = true; }
        } catch (Exception $ew3) {}

        try {
            $q_dev = $ast_db->query("SELECT id as extension, description as name, tech FROM devices WHERE id REGEXP '^[0-9]+$' AND id != '' ORDER BY CAST(id AS UNSIGNED) ASC");
            while ($row = $q_dev->fetch(PDO::FETCH_ASSOC)) {
                $raw_tech = strtoupper(trim($row['tech'] ?: 'PJSIP'));
                if (isset($webrtc_exts[$row['extension']]) || strpos($raw_tech, 'WEBRTC') !== false || strpos($raw_tech, 'WSS') !== false) {
                    $tech = 'WEBRTC';
                } elseif (strpos($raw_tech, 'IAX') !== false) {
                    $tech = 'IAX2';
                } elseif (strpos($raw_tech, 'SIP') !== false && strpos($raw_tech, 'PJSIP') === false) {
                    $tech = 'SIP';
                } else {
                    $tech = 'PJSIP';
                }

                $extensions_found[$row['extension']] = [
                    'name' => $row['name'] ?: 'Ramal ' . $row['extension'],
                    'tech' => $tech
                ];
            }
        } catch (Exception $e1) {}

        if (empty($extensions_found)) {
            try {
                $q_usr = $ast_db->query("SELECT extension, name FROM users WHERE extension REGEXP '^[0-9]+$' ORDER BY CAST(extension AS UNSIGNED) ASC");
                while ($row = $q_usr->fetch(PDO::FETCH_ASSOC)) {
                    $ext_num = $row['extension'];
                    $tech = isset($webrtc_exts[$ext_num]) ? 'WEBRTC' : 'PJSIP';
                    $extensions_found[$ext_num] = [
                        'name' => $row['name'] ?: 'Ramal ' . $ext_num,
                        'tech' => $tech
                    ];
                }
            } catch (Exception $e2) {}
        }

        if (!empty($extensions_found)) {
            $stmt = $db->prepare("INSERT INTO extensions_config (extension, agent_name, tech, whatsapp_number, updated_at) 
                VALUES (:ext, :name, :tech, '', CURRENT_TIMESTAMP)
                ON CONFLICT(extension) DO UPDATE SET agent_name = :name, tech = :tech, updated_at = CURRENT_TIMESTAMP");

            foreach ($extensions_found as $ext => $data) {
                $stmt->execute([':ext' => $ext, ':name' => $data['name'], ':tech' => $data['tech']]);
                $ext_count++;
            }
        }

        $queues_found = [];
        try {
            $q_qc1 = $ast_db->query("SELECT extension, value FROM queues_config WHERE keyword IN ('description', 'displayname') AND extension != ''");
            while ($row = $q_qc1->fetch(PDO::FETCH_ASSOC)) {
                $queues_found[$row['extension']] = $row['value'] ?: 'Fila ' . $row['extension'];
            }
        } catch (Exception $eq1) {}

        if (empty($queues_found)) {
            try {
                $q_qc2 = $ast_db->query("SELECT DISTINCT extension FROM queues_config WHERE extension != '' AND extension REGEXP '^[0-9]+$'");
                while ($row = $q_qc2->fetch(PDO::FETCH_ASSOC)) {
                    $queues_found[$row['extension']] = 'Fila ' . $row['extension'];
                }
            } catch (Exception $eq2) {}
        }

        if (!empty($queues_found)) {
            $stmt_q = $db->prepare("INSERT INTO queues_config (queue_number, queue_name, abandon_enabled, abandon_msg, updated_at) 
                VALUES (:num, :name, 1, :default_msg, CURRENT_TIMESTAMP)
                ON CONFLICT(queue_number) DO UPDATE SET queue_name = :name, updated_at = CURRENT_TIMESTAMP");

            $default_q_msg = getRule('queue_abandon_msg_default');
            foreach ($queues_found as $qnum => $qname) {
                $stmt_q->execute([':num' => $qnum, ':name' => $qname, ':default_msg' => $default_q_msg]);
                $queue_count++;
            }
        }
        return ['success' => true, 'extensions' => $ext_count, 'queues' => $queue_count];
    } else {
        return ['success' => false, 'error' => 'Falha ao conectar no MySQL do Issabel: ' . $last_error];
    }
}

function getAsteriskRealtimeStatuses() {
    $statuses = [];
    if (function_exists('shell_exec')) {
        $output = @shell_exec('asterisk -rx "core show hints" 2>/dev/null');
        if (!empty($output)) {
            $lines = explode("\n", str_replace("\r", "", $output));
            foreach ($lines as $line) {
                if (preg_match('/^\s*(\d+)@[\w\-]+\s*:.*State:([A-Za-z0-9_]+)/i', $line, $m)) {
                    $ext = $m[1];
                    $raw_st = strtolower($m[2]);
                    
                    if (strpos($raw_st, 'idle') !== false) {
                        $statuses[$ext] = 'Livre';
                    } elseif (strpos($raw_st, 'inuse') !== false || strpos($raw_st, 'busy') !== false) {
                        $statuses[$ext] = 'Em Chamada';
                    } elseif (strpos($raw_st, 'ringing') !== false || strpos($raw_st, 'ring') !== false) {
                        $statuses[$ext] = 'Tocando';
                    } elseif (strpos($raw_st, 'unavail') !== false || strpos($raw_st, 'unknown') !== false) {
                        $statuses[$ext] = 'Indisponível';
                    } else {
                        $statuses[$ext] = 'Livre';
                    }
                }
            }
        }
    }
    return $statuses;
}

function getAsteriskTrunksStatus() {
    $trunks = [];
    $sys_conf = getIssabelSystemConfig();
    $ast_db = null;
    try {
        $ast_db = new PDO("mysql:host=localhost;dbname=asterisk;charset=utf8", 'root', $sys_conf['mysqlrootpwd'], [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_TIMEOUT => 2
        ]);
    } catch (Exception $e) {
        try {
            $ast_db = new PDO("mysql:host=localhost;dbname=asterisk;charset=utf8", getSetting('asterisk_db_user') ?: 'asteriskuser', getSetting('asterisk_db_pass') ?: 'eLaStIx.AsTeRiSk.UsEr.1', [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_TIMEOUT => 2
            ]);
        } catch (Exception $e2) {}
    }

    if ($ast_db) {
        try {
            $q_tr = $ast_db->query("SELECT trunkid, name, tech, channelid, disabled FROM trunks WHERE disabled != 'on'");
            while ($r = $q_tr->fetch(PDO::FETCH_ASSOC)) {
                $t_name = $r['name'] ?: ($r['channelid'] ?: ('Tronco ' . $r['trunkid']));
                $t_tech = strtoupper($r['tech'] ?: 'SIP');
                $trunks[$t_name] = [
                    'id' => $r['trunkid'],
                    'name' => $t_name,
                    'tech' => $t_tech,
                    'status' => 'Registrado',
                    'active_calls' => 0,
                    'channels' => []
                ];
            }
        } catch (Exception $e) {}
    }

    if (empty($trunks)) {
        $trunks['Tronco_Principal_PJSIP'] = [
            'id' => 1,
            'name' => 'Tronco Principal PJSIP',
            'tech' => 'PJSIP',
            'status' => 'Registrado',
            'active_calls' => 0,
            'channels' => []
        ];
    }

    if (function_exists('shell_exec')) {
        $sip_reg = @shell_exec('asterisk -rx "sip show registry" 2>/dev/null');
        if ($sip_reg) {
            foreach (explode("\n", $sip_reg) as $line) {
                if (preg_match('/^([\w\.\-]+:\d+|[\w\.\-]+)\s+.*?\s+(Registered|Request Sent|Auth\. Sent|Rejected|Unregistered)/i', $line, $m)) {
                    $host_or_user = $m[1];
                    $st_text = strtolower($m[2]);
                    $final_st = (strpos($st_text, 'registered') !== false && strpos($st_text, 'unregistered') === false) ? 'Registrado' : 'Desconectado';
                    foreach ($trunks as $tk => &$tdata) {
                        if (strpos($tk, $host_or_user) !== false || strpos($host_or_user, $tk) !== false) {
                            $tdata['status'] = $final_st;
                        }
                    }
                }
            }
        }

        $pjsip_reg = @shell_exec('asterisk -rx "pjsip show registrations" 2>/dev/null');
        if ($pjsip_reg) {
            foreach (explode("\n", $pjsip_reg) as $line) {
                if (preg_match('/^\s*([\w\-]+)\/.*?Registered/i', $line, $m)) {
                    $reg_name = $m[1];
                    foreach ($trunks as $tk => &$tdata) {
                        if (strpos($tk, $reg_name) !== false || strpos($reg_name, $tk) !== false) {
                            $tdata['status'] = 'Registrado';
                        }
                    }
                }
            }
        }

        $chans = @shell_exec('asterisk -rx "core show channels concise" 2>/dev/null');
        if ($chans) {
            foreach (explode("\n", $chans) as $line) {
                $parts = explode('!', $line);
                if (count($parts) >= 1) {
                    $chan_name = $parts[0];
                    foreach ($trunks as $tk => &$tdata) {
                        if (!empty($tk) && strpos($chan_name, $tk) !== false) {
                            $tdata['active_calls']++;
                            $tdata['channels'][] = $chan_name;
                        }
                    }
                }
            }
        }
    }

    return array_values($trunks);
}

function getAsteriskQueuesRealtimeStatus() {
    global $db;
    $queues_config = $db->query("SELECT * FROM queues_config ORDER BY queue_number ASC")->fetchAll(PDO::FETCH_ASSOC);
    $realtime_queues = [];

    $cli_output = function_exists('shell_exec') ? @shell_exec('asterisk -rx "queue show" 2>/dev/null') : '';

    foreach ($queues_config as $q) {
        $qnum = $q['queue_number'];
        $qname = $q['queue_name'];
        
        $callers_waiting = [];
        $members = [];
        $answered = 0;
        $abandoned = 0;
        $holdtime_avg = '0s';

        if (!empty($cli_output)) {
            if (preg_match('/^' . preg_quote($qnum, '/') . '\s+has\s+(\d+)\s+calls\s+\(max\s+\w+\)\s+in\s+\'(\w+)\'\s+strategy\s+\(holdtime:\s*([\w\s]+),\s*talktime:\s*[\w\s]+\),\s*W:(\d+),\s*C:(\d+),\s*A:(\d+)/m', $cli_output, $qm)) {
                $holdtime_avg = trim($qm[3]);
                $answered = intval($qm[5]);
                $abandoned = intval($qm[6]);
            }

            preg_match_all('/^\s*([A-Za-z0-9_\-\/]+)\s+\((.*?)\)\s+\((.*?)\)\s+has taken/m', $cli_output, $m_matches, PREG_SET_ORDER);
            foreach ($m_matches as $mm) {
                $iface = $mm[1];
                $st_raw = strtolower($mm[3]);
                $is_paused = (strpos(strtolower($mm[2]), 'paused') !== false);
                $agent_ext = preg_replace('/\D/', '', $iface);
                
                $st = 'Livre';
                if ($is_paused) $st = 'Pausado';
                elseif (strpos($st_raw, 'in use') !== false || strpos($st_raw, 'busy') !== false) $st = 'Em Chamada';
                elseif (strpos($st_raw, 'ringing') !== false) $st = 'Tocando';
                elseif (strpos($st_raw, 'unavailable') !== false || strpos($st_raw, 'invalid') !== false) $st = 'Indisponível';

                $members[] = [
                    'interface' => $iface,
                    'extension' => $agent_ext,
                    'status' => $st,
                    'paused' => $is_paused
                ];
            }

            preg_match_all('/^\s*(\d+)\.\s+([A-Za-z0-9_\-\/]+)\s+\(wait:\s*([\d:]+),\s*prio:\s*\d+\)/m', $cli_output, $c_matches, PREG_SET_ORDER);
            foreach ($c_matches as $cm) {
                $callers_waiting[] = [
                    'pos' => intval($cm[1]),
                    'channel' => $cm[2],
                    'wait_time' => $cm[3]
                ];
            }
        }

        $realtime_queues[] = [
            'id' => $q['id'],
            'queue_number' => $qnum,
            'queue_name' => $qname,
            'abandon_enabled' => $q['abandon_enabled'],
            'abandon_msg' => $q['abandon_msg'],
            'supervisor_whatsapp' => $q['supervisor_whatsapp'],
            'callers_count' => count($callers_waiting),
            'callers' => $callers_waiting,
            'members' => $members,
            'answered_count' => $answered,
            'abandoned_count' => $abandoned,
            'holdtime_avg' => $holdtime_avg
        ];
    }

    return $realtime_queues;
}

function getAsteriskParkingLots() {
    $parking_lots = [];
    if (function_exists('shell_exec')) {
        $output = @shell_exec('asterisk -rx "parkedcalls show" 2>/dev/null');
        if ($output) {
            $lines = explode("\n", $output);
            foreach ($lines as $line) {
                if (preg_match('/^\s*(\d+)\s+([A-Za-z0-9_\-\/]+)\s+.*?\s+(\d+:\d+|\d+s)/i', $line, $m)) {
                    $parking_lots[] = [
                        'slot' => $m[1],
                        'channel' => $m[2],
                        'duration' => $m[3]
                    ];
                }
            }
        }
    }
    return $parking_lots;
}

function sendWhatsAppAPI($phone, $message, $call_id = 'MANUAL', $extension = 'GERAL', $rule_type = 'DESCONHECIDO') {
    global $db;
    $api_url = getSetting('api_url');
    $api_token = getSetting('api_token');

    if (strlen($phone) >= 10 && substr($phone, 0, 2) !== '55') {
        $phone = '55' . $phone;
    }

    $status = 'ERROR';
    $response_log = '';
    $err_msg = '';
    $http_code = 0;

    if (empty($api_url) || empty($api_token)) {
        $err_msg = 'API Prismabot não configurada (URL ou Token ausente)';
        $response_log = json_encode(['error' => $err_msg]);
    } else {
        $payload = [
            'number' => $phone,
            'options' => ['delay' => 1200],
            'textMessage' => ['text' => $message]
        ];

        $ch = curl_init($api_url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Content-Type: application/json',
            'Authorization: Bearer ' . $api_token,
            'token: ' . $api_token
        ]);
        curl_setopt($ch, CURLOPT_TIMEOUT, 8);

        $result = curl_exec($ch);
        $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curl_err = curl_error($ch);
        curl_close($ch);

        if ($curl_err) {
            $err_msg = 'cURL Error: ' . $curl_err;
            $response_log = json_encode(['error' => $err_msg]);
        } else {
            $response_log = $result;
            if ($http_code >= 200 && $http_code < 300) {
                $status = 'SUCCESS';
            } else {
                $err_msg = 'HTTP Status ' . $http_code;
            }
        }
    }

    $stmt = $db->prepare("INSERT INTO sent_logs (phone, call_id, extension, rule_type, message, status, response_raw) VALUES (:phone, :call_id, :ext, :rule, :msg, :status, :resp)");
    $stmt->execute([
        ':phone' => $phone,
        ':call_id' => $call_id,
        ':ext' => $extension,
        ':rule' => $rule_type,
        ':msg' => $message,
        ':status' => $status,
        ':resp' => $response_log
    ]);

    return [
        'success' => ($status === 'SUCCESS'),
        'error' => $err_msg,
        'http_code' => $http_code,
        'raw' => $response_log
    ];
}

function getIssabelInboundCallFlows() {
    global $db;
    $flows = [];
    $sys_conf = getIssabelSystemConfig();

    $ast_db = null;
    try {
        $ast_db = new PDO("mysql:host=localhost;dbname=asterisk;charset=utf8", 'root', $sys_conf['mysqlrootpwd'], [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_TIMEOUT => 2
        ]);
    } catch (Exception $e1) {
        try {
            $ast_db = new PDO("mysql:host=localhost;dbname=asterisk;charset=utf8", getSetting('asterisk_db_user') ?: 'asteriskuser', getSetting('asterisk_db_pass') ?: 'eLaStIx.AsTeRiSk.UsEr.1', [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_TIMEOUT => 2
            ]);
        } catch (Exception $e2) {}
    }

    if (!$ast_db) {
        // Fallback gráfico de demonstração caso o MySQL esteja offline em desenvolvimento local
        return [[
            'id' => '1',
            'did' => 'Qualquer DID / Tronco Principal',
            'description' => 'Rota Geral de Entrada PABX',
            'steps' => [
                ['type' => 'trunk', 'title' => 'Linha / Tronco SIP Entrante', 'desc' => 'DID: Qualquer (s)', 'badge' => 'Tronco Ativo', 'color' => 'cyan'],
                ['type' => 'timecondition', 'title' => 'Condição de Horário', 'desc' => 'Seg-Sexta: 08:00 as 18:00 (Horário Comercial)', 'badge' => 'Checagem Ativa', 'color' => 'amber'],
                ['type' => 'queue', 'title' => 'Fila Atendimento 1001 (Comercial)', 'desc' => 'Toque Max: 30s | Gravação: Ativada', 'badge' => 'Fila Principal', 'color' => 'purple'],
                ['type' => 'extension', 'title' => 'Atendimento Ramais (1001..1005)', 'desc' => 'Atendentes logados', 'badge' => 'Ramal Operativo', 'color' => 'emerald'],
                ['type' => 'whatsapp', 'title' => 'WhatsApp & Pesquisa NPS', 'desc' => 'Envio automático pós-atendimento', 'badge' => 'Prismabot Integration', 'color' => 'green']
            ]
        ]];
    }

    try {
        $q_inc = $ast_db->query("SELECT extension, cidnum, destination, description FROM incoming ORDER BY extension ASC");
        while ($r_inc = $q_inc->fetch(PDO::FETCH_ASSOC)) {
            $did = $r_inc['extension'] ?: ($r_inc['cidnum'] ?: 'Qualquer DID (s)');
            $desc = $r_inc['description'] ?: "Rota Entrante $did";
            $dest = $r_inc['destination'];

            $steps = [];
            $steps[] = [
                'type' => 'trunk',
                'title' => 'Chamada Entrante (DID / Tronco)',
                'desc' => "DID: $did" . ($r_inc['cidnum'] ? " | CID: {$r_inc['cidnum']}" : ""),
                'badge' => 'Entrada PABX',
                'color' => 'cyan'
            ];

            // Helper de resolução recursiva do destino
            $curr_dest = $dest;
            $max_hops = 6;
            while ($curr_dest && $max_hops > 0) {
                $max_hops--;
                
                if (strpos($curr_dest, 'timeconditions') === 0) {
                    $tc_id = preg_replace('/\D/', '', explode(',', $curr_dest)[0] ?? '');
                    $tc_info = null;
                    if ($tc_id) {
                        $q_tc = $ast_db->prepare("SELECT displayname, time, truegoto, falsegoto FROM timeconditions WHERE timecondition_id = :id");
                        $q_tc->execute([':id' => $tc_id]);
                        $tc_info = $q_tc->fetch(PDO::FETCH_ASSOC);
                    }

                    $tc_name = $tc_info['displayname'] ?? "Condição de Horário #$tc_id";
                    $tg_id = $tc_info['time'] ?? '';
                    $schedule_text = "Horário Comercial / Feriados";
                    if ($tg_id) {
                        $q_tg = $ast_db->prepare("SELECT times FROM timegroups_details WHERE timegroupid = :id LIMIT 1");
                        $q_tg->execute([':id' => $tg_id]);
                        $tg_row = $q_tg->fetch(PDO::FETCH_ASSOC);
                        if (!empty($tg_row['times'])) $schedule_text = $tg_row['times'];
                    }

                    $steps[] = [
                        'type' => 'timecondition',
                        'title' => "Condição de Horário: $tc_name",
                        'desc' => "Validação: $schedule_text",
                        'badge' => 'Validação de Horário',
                        'color' => 'amber'
                    ];

                    $curr_dest = $tc_info['truegoto'] ?? '';
                } elseif (strpos($curr_dest, 'ext-queues') === 0) {
                    $q_num = preg_replace('/\D/', '', explode(',', $curr_dest)[1] ?? '');
                    $q_name = "Fila $q_num";
                    
                    $q_qc = $ast_db->prepare("SELECT value FROM queues_config WHERE extension = :ext AND keyword = 'description' LIMIT 1");
                    $q_qc->execute([':ext' => $q_num]);
                    $q_row = $q_qc->fetch(PDO::FETCH_ASSOC);
                    if (!empty($q_row['value'])) $q_name = $q_row['value'];

                    $steps[] = [
                        'type' => 'queue',
                        'title' => "Fila de Atendimento: $q_name ($q_num)",
                        'desc' => "Distribuição de chamadas | Gravação ativa",
                        'badge' => 'Fila de Espera',
                        'color' => 'purple'
                    ];

                    $steps[] = [
                        'type' => 'extension',
                        'title' => "Distribuição aos Atendentes do Sector",
                        'desc' => "Toque nos ramais configurados na fila $q_num",
                        'badge' => 'Atendimento Ramal',
                        'color' => 'emerald'
                    ];

                    $curr_dest = null;
                } elseif (strpos($curr_dest, 'ivr') === 0 || strpos($curr_dest, 'app-announcement') === 0) {
                    $steps[] = [
                        'type' => 'ivr',
                        'title' => 'Atendimento Automático URA / Anúncio',
                        'desc' => "Menu interativo de opções de voz",
                        'badge' => 'URA de Voz',
                        'color' => 'blue'
                    ];
                    $curr_dest = null;
                } else {
                    $steps[] = [
                        'type' => 'dest',
                        'title' => 'Destino da Chamada',
                        'desc' => "Direcionado para: $curr_dest",
                        'badge' => 'Destino Final',
                        'color' => 'indigo'
                    ];
                    $curr_dest = null;
                }
            }

            // Pós-atendimento com regras WhatsApp
            $nps_active = (getRule('nps_enabled') === '1');
            $steps[] = [
                'type' => 'whatsapp',
                'title' => 'Integração WhatsApp & NPS (Prismabot)',
                'desc' => $nps_active ? 'Disparo de Pesquisa NPS + Notificação de Chamada Perdida' : 'Notificação de Chamada Perdida ao Atendente/Cliente',
                'badge' => 'WhatsApp API',
                'color' => 'green'
            ];

            $flows[] = [
                'id' => $did,
                'did' => $did,
                'description' => $desc,
                'steps' => $steps
            ];
        }
    } catch (Exception $e) {}

    if (empty($flows)) {
        $flows[] = [
            'id' => 's',
            'did' => 'Qualquer DID / Tronco Principal',
            'description' => 'Rota Padrão do Sistema Issabel',
            'steps' => [
                ['type' => 'trunk', 'title' => 'Tronco Entrante SIP / PJSIP', 'desc' => 'DID: Qualquer (s)', 'badge' => 'Tronco Ativo', 'color' => 'cyan'],
                ['type' => 'timecondition', 'title' => 'Horário Comercial & Feriados', 'desc' => 'Seg-Sexta: 08:00 às 18:00', 'badge' => 'Checagem Ativa', 'color' => 'amber'],
                ['type' => 'queue', 'title' => 'Fila de Atendimento Geral', 'desc' => 'Distribuição uniforme com gravação', 'badge' => 'Fila Ativa', 'color' => 'purple'],
                ['type' => 'whatsapp', 'title' => 'Notificação WhatsApp & NPS', 'desc' => 'Automação via API Prismabot', 'badge' => 'Prismabot Integration', 'color' => 'green']
            ]
        ];
    }

    return $flows;
}
