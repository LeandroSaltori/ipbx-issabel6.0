<?php
/**
 * IPbx Prisma - Núcleo de Funções Auxiliares (AMI, PABX DB, WhatsApp API)
 */

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/logger.php';
require_once __DIR__ . '/auth.php';

function getSetting($key) {
    global $db;
    try {
        $stmt = $db->prepare("SELECT value_val FROM settings WHERE key_name = :key");
        $stmt->execute([':key' => $key]);
        $res = $stmt->fetch(PDO::FETCH_ASSOC);
        return $res ? $res['value_val'] : '';
    } catch (Exception $e) {
        logSystemError("Erro getSetting($key)", ['error' => $e->getMessage()]);
        return '';
    }
}

function saveSetting($key, $val) {
    global $db;
    try {
        if (!$db) return false;
        $db->exec("CREATE TABLE IF NOT EXISTS settings (key_name TEXT PRIMARY KEY, value_val TEXT)");
        $stmt = $db->prepare("INSERT OR REPLACE INTO settings (key_name, value_val) VALUES (:k, :v)");
        $success = $stmt->execute([':k' => $key, ':v' => $val]);
        if ($success && function_exists('logUserAction')) {
            logUserAction("Alterar Configuração", "Configuração '$key' atualizada com sucesso.", "CONFIG");
        }
        return $success;
    } catch (Exception $e) {
        if (function_exists('logSystemError')) {
            logSystemError("Erro saveSetting($key)", ['error' => $e->getMessage(), 'key' => $key, 'val' => $val]);
        }
        return false;
    }
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

    $creds = [];
    if (!empty($user) && !empty($secret)) {
        $creds[] = ['user' => $user, 'secret' => $secret];
    }

    $files_to_check = [
        '/etc/amportal.conf',
        '/etc/asterisk/manager_custom.conf',
        '/etc/asterisk/manager.conf',
        '/etc/asterisk/manager_additional.conf'
    ];

    foreach ($files_to_check as $file) {
        if (file_exists($file) && is_readable($file)) {
            $lines = @file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
            if (!empty($lines)) {
                $user_found = '';
                $secret_found = '';
                $current_section = '';
                foreach ($lines as $line) {
                    $line = trim($line);
                    if (strpos($line, '=') !== false) {
                        list($k, $v) = explode('=', $line, 2);
                        $k = strtoupper(trim($k));
                        $v = trim($v);
                        if ($k === 'AMPMGRUSER' && !empty($v)) $user_found = $v;
                        if ($k === 'AMPMGRPASS' && !empty($v)) $secret_found = $v;
                    }
                    if (preg_match('/^\[(.*)\]$/', $line, $m)) {
                        $current_section = strtolower($m[1]);
                    } elseif (!empty($current_section) && $current_section !== 'general') {
                        if (strpos($line, '=') !== false) {
                            list($k, $v) = explode('=', $line, 2);
                            if (strtolower(trim($k)) === 'secret') {
                                $parsed_secret = trim($v);
                                if (!empty($parsed_secret)) {
                                    $creds[] = ['user' => $current_section, 'secret' => $parsed_secret];
                                }
                            }
                        }
                    }
                }
                if (!empty($user_found) && !empty($secret_found)) {
                    $creds[] = ['user' => $user_found, 'secret' => $secret_found];
                }
            }
        }
    }

    $creds[] = ['user' => 'admin', 'secret' => 'elaStix.aSterisk.pass'];
    $creds[] = ['user' => 'admin', 'secret' => 'amp111'];
    $creds[] = ['user' => 'admin', 'secret' => 'elastix'];
    return $creds;
}

function executeClickToCall($from_ext, $destination_num) {
    global $db;
    $host = getSetting('ami_host') ?: '127.0.0.1';
    $port = (int)(getSetting('ami_port') ?: 5038);

    $credentials_to_try = getAsteriskAMICredentials();

    $socket = null;
    $authenticated = false;
    $last_error_reason = '';

    // Tentar conectar via socket
    $s = @fsockopen($host, $port, $errno, $errstr, 4);
    if (!$s) {
        return [
            'success' => false,
            'error' => "Não foi possível abrir a porta TCP $port no Host '$host' ($errstr). Verifique se o roteador/firewall libera a porta 5038 e se o DDNS está ativo."
        ];
    }

    foreach ($credentials_to_try as $cred) {
        if (empty($cred['user']) || empty($cred['secret'])) continue;
        
        // Se o socket fechou, reconectar
        if (!is_resource($s) || feof($s)) {
            $s = @fsockopen($host, $port, $errno, $errstr, 4);
            if (!$s) break;
        }

        $login_msg = "Action: Login\r\nUsername: {$cred['user']}\r\nSecret: {$cred['secret']}\r\n\r\n";
        fwrite($s, $login_msg);
        
        $response = '';
        while (!feof($s)) {
            $line = fgets($s, 1024);
            $response .= $line;
            if (trim($line) == '') break;
        }

        if (strpos($response, 'Authentication accepted') !== false || strpos($response, 'Success') !== false) {
            $socket = $s;
            $authenticated = true;
            break;
        } else {
            $last_error_reason = trim(preg_replace('/\s+/', ' ', strip_tags($response)));
        }
        fclose($s);
    }

    if (!$authenticated || !$socket) {
        $detail = !empty($last_error_reason) ? "Resposta do PABX: '$last_error_reason'" : "Credenciais recusadas pelo PABX";
        return ['success' => false, 'error' => "Falha de Autenticação no AMI ($host:$port). $detail. Certifique-se de que 'Habilitado para Web' está SIM no PABX e o usuário/senha estão corretos."];
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

/**
 * Executa qualquer comando/Ação via REST API em socket TCP puro (Suporta comandos CLI via Command)
 */
function executeAsteriskAMICommand($action, $params = []) {
    // Mede o tempo gasto no AMI (aparece em Logs > Desempenho quando a requisição é lenta)
    $__t = microtime(true);
    try { return executeAsteriskAMICommandRaw($action, $params); }
    finally { $GLOBALS['__perf_ami'] = ($GLOBALS['__perf_ami'] ?? 0) + (microtime(true) - $__t); }
}

function executeAsteriskAMICommandRaw($action, $params = []) {
    $host = getSetting('ami_host') ?: '127.0.0.1';
    $port = (int)(getSetting('ami_port') ?: 5038);

    $credentials_to_try = getAsteriskAMICredentials();
    // Remove credenciais repetidas (mesmo usuário+senha)
    $seen = []; $uniq = [];
    foreach ($credentials_to_try as $c) {
        $k = ($c['user'] ?? '') . "\0" . ($c['secret'] ?? '');
        if (!isset($seen[$k])) { $seen[$k] = 1; $uniq[] = $c; }
    }
    $credentials_to_try = $uniq;
    // Testa primeiro o usuário que funcionou da última vez: uma credencial recusada custa ~1 s (o Asterisk atrasa a recusa),
    // e antes isso se repetia em TODA consulta ao AMI. Guarda só o NOME do usuário (nunca a senha).
    $__ami_ok_file = __DIR__ . '/../logs/.ami_user_ok';
    $__ami_ok = $GLOBALS['__ami_user_ok'] ?? (is_readable($__ami_ok_file) ? trim((string)@file_get_contents($__ami_ok_file)) : '');
    // Identifica a credencial por usuário + impressão curta (não reversível) da senha: o mesmo usuário (ex.: admin) pode aparecer
    // com senhas diferentes em vários arquivos, e só uma delas vale; guardar só o nome deixava as erradas na frente.
    $__ami_tag = function ($c) { return ($c['user'] ?? '') . '|' . substr(hash('sha256', ($c['user'] ?? '') . "\0" . ($c['secret'] ?? '')), 0, 16); };
    if ($__ami_ok !== '') {
        $__ami_ok_user = explode('|', $__ami_ok)[0];
        $__ami_score = function ($c) use ($__ami_ok, $__ami_ok_user, $__ami_tag) {
            return ($__ami_tag($c) === $__ami_ok ? 2 : 0) + ((($c['user'] ?? '') === $__ami_ok_user) ? 1 : 0);
        };
        usort($credentials_to_try, function ($x, $y) use ($__ami_score) { return $__ami_score($y) - $__ami_score($x); });
    }
    $socket = null;
    $authenticated = false;
    $last_error = '';
    $connected_once = false;

    // O Asterisk FECHA a conexão após um login recusado: cada tentativa usa um socket novo
    foreach ($credentials_to_try as $cred) {
        if (empty($cred['user']) || empty($cred['secret'])) continue;
        $s = @fsockopen($host, $port, $errno, $errstr, 4);
        if (!$s) {
            if (!$connected_once) return ['success' => false, 'error' => "Falha ao conectar no AMI $host:$port - $errstr"];
            $last_error = "Conexão AMI interrompida ($errstr)";
            break;
        }
        $connected_once = true;
        stream_set_timeout($s, 5);
        fgets($s, 1024); // banner

        fwrite($s, "Action: Login\r\nUsername: {$cred['user']}\r\nSecret: {$cred['secret']}\r\nEvents: off\r\n\r\n");
        $login_response = '';
        while (!feof($s)) {
            $line = fgets($s, 1024);
            if ($line === false) break;
            $login_response .= $line;
            if (trim($line) == '') break;
        }

        if (stripos($login_response, 'Response: Success') !== false) {
            $authenticated = true;
            $socket = $s;
            $__tag_now = $__ami_tag($cred);
            if ($__tag_now !== $__ami_ok) {
                $GLOBALS['__ami_user_ok'] = $__tag_now;
                @file_put_contents($__ami_ok_file, $__tag_now, LOCK_EX);
            }
            break;
        }
        $last_error = "Credencial AMI recusada para usuário " . $cred['user'];
        @fclose($s);
    }

    if (!$connected_once) {
        return ['success' => false, 'error' => "Falha ao conectar no AMI $host:$port - sem credenciais para testar. " . amiCredentialDiagnosis($credentials_to_try)];
    }
    if (!$authenticated || !$socket) {
        return ['success' => false, 'error' => "Falha de Autenticação AMI no Host $host. $last_error " . amiCredentialDiagnosis($credentials_to_try)];
    }

    // Construir mensagem da Ação AMI
    $action = str_replace(["\r", "\n"], '', (string)$action);
    $cmd_msg = "Action: {$action}\r\n";
    foreach ($params as $pk => $pv) {
        $pk = str_replace(["\r", "\n", ":"], '', (string)$pk);
        $pv = str_replace(["\r", "\n"], ' ', (string)$pv);
        $cmd_msg .= "{$pk}: {$pv}\r\n";
    }
    $cmd_msg .= "\r\n";

    fwrite($socket, $cmd_msg);

    $response = '';
    $start_time = time();
    while (!feof($socket)) {
        $line = fgets($socket, 1024);
        $response .= $line;
        if (trim($line) == '') {
            // Fim do bloco de resposta principal da ação
            break;
        }
        if (time() - $start_time > 5) break;
    }

    fwrite($socket, "Action: Logoff\r\n\r\n");
    fclose($socket);

    return ['success' => true, 'response' => $response];
}


/**
 * Pausa / despausa um membro (agente) de uma fila via AMI (QueuePause).
 * $paused = 1 pausa, 0 despausa. $pause é mantido apenas por compatibilidade.
 */
function pauseAsteriskQueueMember($queue, $iface, $pause = true, $paused = 1, $reason = 'Pausa Operacional') {
    $queue  = preg_replace('/[^0-9A-Za-z_\-]/', '', (string)$queue);
    $iface  = preg_replace('/[^0-9A-Za-z_\-\/@.]/', '', (string)$iface);
    $reason = trim(preg_replace('/[\r\n]+/', ' ', (string)$reason));
    if ($queue === '' || $iface === '') {
        return ['success' => false, 'error' => 'Fila ou interface inválida.'];
    }

    $params = [
        'Interface' => $iface,
        'Paused'    => ((int)$paused === 1) ? 'true' : 'false',
        'Queue'     => $queue,
    ];
    if ((int)$paused === 1 && $reason !== '') {
        $params['Reason'] = $reason;
    }

    $res = executeAsteriskAMICommand('QueuePause', $params);
    if (empty($res['success'])) {
        return ['success' => false, 'error' => $res['error'] ?? 'Falha ao comunicar com o AMI.'];
    }
    $raw = $res['response'] ?? '';
    if (stripos($raw, 'Response: Success') !== false) {
        return ['success' => true, 'error' => ''];
    }
    $msg = 'PABX recusou a ação.';
    if (preg_match('/Message:\s*(.+)/i', $raw, $m)) $msg = trim($m[1]);
    return ['success' => false, 'error' => $msg];
}


function getIssabelSystemConfig() {
    return getPABXSystemConfig();
}
function getPABXSystemConfig() {
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

function autoInjectPABXDialplan() {
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
            "exten => s,n,AGI(/var/www/html/front_api/whatsapp_agi.php,\${CALLERID(num)},missed_extension,\${MACRO_EXTEN},0,\${DIALEDTIME})\n" .
            "exten => s,n(fim),Return()\n";
        
        $content .= $dialplan_block;
        $needs_reload = true;
    }

    if (strpos($content, '[ext-prisma-no-answer]') === false) {
        $prisma_custom_contexts = "\n; --- CONTEXTOS DE DESTINO CUSTOMIZADO IPBX PRISMA ---\n" .
            "[ext-prisma-no-answer]\n" .
            "exten => s,1,NoOp(=== PRISMA - NOTIFICACAO CHAMADA PERDIDA ===)\n" .
            "exten => s,n,AGI(/var/www/html/front_api/whatsapp_agi.php,\${CALLERID(num)},no_answer,\${NODEST})\n" .
            "exten => s,n,Hangup()\n\n" .
            "[ext-prisma-nps]\n" .
            "exten => s,1,NoOp(=== PRISMA - PESQUISA NPS ===)\n" .
            "exten => s,n,AGI(/var/www/html/front_api/whatsapp_agi.php,\${CALLERID(num)},nps,\${NODEST})\n" .
            "exten => s,n,Hangup()\n\n" .
            "[ext-prisma-transbordo]\n" .
            "exten => s,1,NoOp(=== PRISMA - TRANSBORDO URA ===)\n" .
            "exten => s,n,AGI(/var/www/html/front_api/whatsapp_agi.php,\${CALLERID(num)},transbordo,\${NODEST})\n" .
            "exten => s,n,Hangup()\n\n" .
            "[ext-prisma-audio]\n" .
            "exten => s,1,NoOp(=== PRISMA - ENVIO DE AUDIO ===)\n" .
            "exten => s,n,AGI(/var/www/html/front_api/whatsapp_agi.php,\${CALLERID(num)},audio,\${NODEST})\n" .
            "exten => s,n,Hangup()\n";
        
        $content .= $prisma_custom_contexts;
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

try {
    autoInjectPABXDialplan();
} catch (Throwable $e_inj) {}

/** A REST API do PABX (pbxapi) é opcional: só vale usá-la se estiver configurada E respondendo (checagem rápida, em cache de 5 min). */
function pbxapiAvailable() {
    static $memo = null;
    if ($memo !== null) return $memo;
    $c = json_decode((string)getSetting('pbxapi_probe'), true);
    if (is_array($c) && time() - (int)($c['t'] ?? 0) < 300) return $memo = !empty($c['ok']);
    $ok = false;
    $url = trim((string)getSetting('pbxapi_url')) ?: 'https://127.0.0.1/pbxapi';
    $ch = curl_init($url);
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_NOBODY => true, CURLOPT_CONNECTTIMEOUT => 2, CURLOPT_TIMEOUT => 3, CURLOPT_SSL_VERIFYPEER => false, CURLOPT_SSL_VERIFYHOST => false]);
    curl_exec($ch); $http = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE); curl_close($ch);
    if ($http > 0 && $http < 500) {
        // Responde na rede: confirma que as credenciais do cliente REST realmente autenticam
        global $db;
        if (file_exists(__DIR__ . '/pbxapi_client.php')) {
            require_once __DIR__ . '/pbxapi_client.php';
            try { $auth = (new PbxApiClient($db))->authenticate(); $ok = !empty($auth['success']); } catch (Throwable $e) { $ok = false; }
        }
    }
    saveSetting('pbxapi_probe', json_encode(['t' => time(), 'ok' => $ok]));
    return $memo = $ok;
}

/** Renomeia o ramal direto no Issabel (users/devices) e recarrega o dialplan de forma suave. Retorna true se gravou. */
function renameExtensionInIssabel($ext, $name, $tech = 'PJSIP') {
    $ext = preg_replace('/\D/', '', (string)$ext);
    $name = trim((string)$name);
    if ($ext === '' || $name === '' || !function_exists('getPABXSystemConfig')) return false;
    $conf = getPABXSystemConfig();
    if (empty($conf['mysqlrootpwd'])) return false;
    try {
        $pdo = new PDO("mysql:host=localhost;dbname=asterisk;charset=utf8", 'root', $conf['mysqlrootpwd'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_TIMEOUT => 2]);
        $pdo->prepare("UPDATE users SET name = :n WHERE extension = :e")->execute([':n' => $name, ':e' => $ext]);
        $pdo->prepare("UPDATE devices SET description = :n WHERE id = :e")->execute([':n' => $name, ':e' => $ext]);
        executeAsteriskAMICommand('Command', ['Command' => 'dialplan reload']);
        return true;
    } catch (Exception $e) { return false; }
}

function syncPrismaAssets() {
    global $db;
    
    // 1. Tentar sincronizar via REST API PABX (pbxapi) primeiro
    if (pbxapiAvailable() && file_exists(__DIR__ . '/pbxapi_client.php')) {
        require_once __DIR__ . '/pbxapi_client.php';
        $api = new PbxApiClient($db);
        
        // Obter Ramais via API
        $extRes = $api->getExtensions();
        $extCount = 0;
        if ($extRes['success'] && is_array($extRes['data'])) {
            $extList = $extRes['data']['results'] ?? $extRes['data'];
            foreach ($extList as $eRow) {
                $enum = trim($eRow['extension'] ?? $eRow['id'] ?? '');
                $ename = trim($eRow['name'] ?? '');
                $etech = strtoupper(trim($eRow['tech'] ?? 'PJSIP'));
                if (!empty($enum)) {
                    $db->prepare("INSERT INTO extensions_config (extension, agent_name, tech, updated_at) VALUES (:e, :n, :t, CURRENT_TIMESTAMP) ON CONFLICT(extension) DO UPDATE SET agent_name = excluded.agent_name, tech = excluded.tech, updated_at = CURRENT_TIMESTAMP WHERE extensions_config.agent_name IS NOT excluded.agent_name OR extensions_config.tech IS NOT excluded.tech")
                       ->execute([':e' => $enum, ':n' => $ename ?: "Ramal $enum", ':t' => $etech]);
                    $extCount++;
                }
            }
        }

        // Obter Filas e Nomes via API
        $qRes = $api->getQueues();
        $qCount = 0;
        if ($qRes['success'] && is_array($qRes['data'])) {
            $qList = $qRes['data']['results'] ?? $qRes['data'];
            foreach ($qList as $qRow) {
                $qnum = trim($qRow['extension'] ?? $qRow['queue'] ?? $qRow['grpnum'] ?? '');
                $qname = trim($qRow['descr'] ?? $qRow['description'] ?? $qRow['name'] ?? '');
                if (!empty($qnum)) {
                    $displayName = !empty($qname) ? "Fila $qnum - $qname" : "Fila $qnum";
                    $db->prepare("INSERT INTO queues_config (queue_number, queue_name, abandon_enabled, abandon_msg, updated_at) VALUES (:num, :name, 1, 'Atendimento encerrado.', CURRENT_TIMESTAMP) ON CONFLICT(queue_number) DO UPDATE SET queue_name = excluded.queue_name, updated_at = CURRENT_TIMESTAMP WHERE queues_config.queue_name IS NOT excluded.queue_name")
                       ->execute([':num' => $qnum, ':name' => $displayName]);
                    $qCount++;
                }
            }
        }

        if ($extCount > 0 || $qCount > 0) {
            return ['success' => true, 'extensions' => $extCount, 'queues' => $qCount, 'method' => 'REST API PABX'];
        }
    }
    global $db;
    $sys_conf = getPABXSystemConfig();
    
    $hosts_to_try = array_unique([getSetting('asterisk_db_host') ?: '', 'localhost', '127.0.0.1']);
    $creds_to_try = [
        ['user' => getSetting('asterisk_db_user') ?: '', 'pass' => getSetting('asterisk_db_pass') ?: ''],
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
        // A coluna de valor chama-se 'data' (ou 'value', conforme a versão do IssabelPBX): detecta em vez de supor
        $__vc_sip = astOptionsValueCol($ast_db, 'sip'); $__vc_pj = astOptionsValueCol($ast_db, 'pjsip');
        try {
            if ($__vc_sip !== '') {
                $q_w1 = $ast_db->query("SELECT id FROM sip WHERE keyword = 'webrtc' AND $__vc_sip = 'yes'");
                if ($q_w1) while ($r = $q_w1->fetch(PDO::FETCH_ASSOC)) { $webrtc_exts[$r['id']] = true; }
            }
        } catch (Exception $ew1) {}
        try {
            if ($__vc_sip !== '') {
                $q_w2 = $ast_db->query("SELECT id FROM sip WHERE keyword = 'transport' AND ($__vc_sip LIKE '%wss%' OR $__vc_sip LIKE '%ws%')");
                if ($q_w2) while ($r = $q_w2->fetch(PDO::FETCH_ASSOC)) { $webrtc_exts[$r['id']] = true; }
            }
        } catch (Exception $ew2) {}
        try {
            if ($__vc_pj !== '') {
                $q_w3 = $ast_db->query("SELECT id FROM pjsip WHERE keyword = 'webrtc' AND $__vc_pj = 'yes'");
                if ($q_w3) while ($r = $q_w3->fetch(PDO::FETCH_ASSOC)) { $webrtc_exts[$r['id']] = true; }
            }
        } catch (Exception $ew3) {}

        try {
            $q_dev = $ast_db->query("SELECT id as extension, description as name, tech FROM devices WHERE id != '' ORDER BY CAST(id AS UNSIGNED) ASC");
            while ($row = $q_dev->fetch(PDO::FETCH_ASSOC)) {
                if (!ctype_digit($row['extension'])) continue;
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
                $q_usr = $ast_db->query("SELECT extension, name FROM users WHERE extension != '' ORDER BY CAST(extension AS UNSIGNED) ASC");
                while ($row = $q_usr->fetch(PDO::FETCH_ASSOC)) {
                    if (!ctype_digit($row['extension'])) continue;
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
                ON CONFLICT(extension) DO UPDATE SET agent_name = excluded.agent_name, tech = excluded.tech, updated_at = CURRENT_TIMESTAMP
                WHERE extensions_config.agent_name IS NOT excluded.agent_name OR extensions_config.tech IS NOT excluded.tech");

            foreach ($extensions_found as $ext => $data) {
                $stmt->execute([':ext' => $ext, ':name' => $data['name'], ':tech' => $data['tech']]);
                $ext_count++;
            }
        }

        $queues_found = [];
        // 1. Chave-Valor no PABX/FreePBX (queues_config com keyword = 'description', 'descr', 'name' ou 'displayname')
        try {
            $q_qc1 = $ast_db->query("SELECT extension, 
                CASE 
                    WHEN data IS NOT NULL AND data != '' THEN data 
                    WHEN value IS NOT NULL AND value != '' THEN value 
                    ELSE '' 
                END as queue_name 
                FROM queues_config 
                WHERE keyword IN ('description', 'descr', 'name', 'displayname') 
                AND extension != '' 
                ORDER BY CASE WHEN keyword IN ('description', 'descr') THEN 1 ELSE 2 END ASC");
            if ($q_qc1) {
                while ($row = $q_qc1->fetch(PDO::FETCH_ASSOC)) {
                    $ext = trim($row['extension']);
                    $val = trim($row['queue_name']);
                    if (!empty($ext) && !empty($val) && strcasecmp($val, $ext) !== 0) {
                        if (!isset($queues_found[$ext]) || empty($queues_found[$ext])) {
                            $queues_found[$ext] = $val;
                        }
                    }
                }
            }
        } catch (Exception $eq1) {}

        // 2. Tabela asterisk.queues (description)
        try {
            $q_queues = $ast_db->query("SELECT queue, description FROM queues WHERE queue != '' AND description != ''");
            if ($q_queues) {
                while ($row = $q_queues->fetch(PDO::FETCH_ASSOC)) {
                    $qext = trim($row['queue']);
                    $qdesc = trim($row['description']);
                    if (!empty($qext) && !empty($qdesc) && strcasecmp($qdesc, $qext) !== 0) {
                        if (!isset($queues_found[$qext]) || empty($queues_found[$qext])) {
                            $queues_found[$qext] = $qdesc;
                        }
                    }
                }
            }
        } catch (Exception $eqq) {}

        // 3. Tabela asterisk.incoming (comentário / descrição da rota para a fila)
        try {
            $q_inc = $ast_db->query("SELECT destination, description FROM incoming WHERE destination LIKE 'ext-queues%' AND description != ''");
            if ($q_inc) {
                while ($row = $q_inc->fetch(PDO::FETCH_ASSOC)) {
                    if (preg_match('/ext-queues,(\d+),/', $row['destination'], $m)) {
                        $qext = $m[1];
                        $qdesc = trim($row['description']);
                        if (!empty($qext) && !empty($qdesc) && strcasecmp($qdesc, $qext) !== 0) {
                            if (!isset($queues_found[$qext]) || empty($queues_found[$qext])) {
                                $queues_found[$qext] = $qdesc;
                            }
                        }
                    }
                }
            }
        } catch (Exception $eqinc) {}

        // 4. Fallback: DISTINCT extension de queues_config (Asterisk MySQL)
        try {
            $q_qc2 = $ast_db->query("SELECT DISTINCT extension FROM queues_config WHERE extension != '' AND extension REGEXP '^[0-9]+$'");
            if ($q_qc2) {
                while ($row = $q_qc2->fetch(PDO::FETCH_ASSOC)) {
                    $qext = trim($row['extension']);
                    if (!isset($queues_found[$qext])) {
                        $queues_found[$qext] = "Fila " . $qext;
                    }
                }
            }
        } catch (Exception $eq2) {}

        if (!empty($queues_found)) {
            $stmt_q = $db->prepare("INSERT INTO queues_config (queue_number, queue_name, abandon_enabled, abandon_msg, updated_at) 
                VALUES (:num, :name, 1, :default_msg, CURRENT_TIMESTAMP)
                ON CONFLICT(queue_number) DO UPDATE SET queue_name = excluded.queue_name, updated_at = CURRENT_TIMESTAMP
                WHERE queues_config.queue_name IS NOT excluded.queue_name");

            $default_q_msg = function_exists('getRule') ? getRule('queue_abandon_msg_default') : 'Atendimento encerrado.';
            foreach ($queues_found as $qnum => $qname) {
                $cleanName = trim(str_ireplace(["Fila {$qnum}", "Fila", $qnum], '', $qname));
                $cleanName = ltrim($cleanName, ' -:');
                $finalDisplayName = !empty($cleanName) ? "Fila {$qnum} - {$cleanName}" : "Fila {$qnum}";
                
                $stmt_q->execute([':num' => $qnum, ':name' => $finalDisplayName, ':default_msg' => $default_q_msg]);
                $queue_count++;
            }
        }
        return ['success' => true, 'extensions' => $ext_count, 'queues' => $queue_count];
    } else {
        // Fallback de leitura direta dos arquivos nativos de configuração do Issabel / Asterisk (/etc/asterisk/*.conf)
        if (file_exists('/etc/asterisk/sip_additional.conf') || file_exists('/etc/asterisk/pjsip.endpoint.conf') || file_exists('/etc/asterisk/users.conf')) {
            $conf_files = ['/etc/asterisk/sip_additional.conf', '/etc/asterisk/pjsip.endpoint.conf', '/etc/asterisk/users.conf'];
            $stmt_file_ext = $db->prepare("INSERT INTO extensions_config (extension, agent_name, tech, whatsapp_number, updated_at) 
                VALUES (:ext, :name, 'PJSIP', '', CURRENT_TIMESTAMP)
                ON CONFLICT(extension) DO NOTHING");
            foreach ($conf_files as $cf) {
                if (!file_exists($cf)) continue;
                $txt = @file_get_contents($cf);
                if ($txt && preg_match_all('/\[(\d{3,4})\]/i', $txt, $matches)) {
                    foreach (array_unique($matches[1]) as $ext_num) {
                        $stmt_file_ext->execute([':ext' => $ext_num, ':name' => "Ramal $ext_num"]);
                        $ext_count++;
                    }
                }
            }
        }
        if (file_exists('/etc/asterisk/queues_additional.conf')) {
            $q_txt = @file_get_contents('/etc/asterisk/queues_additional.conf');
            if ($q_txt && preg_match_all('/\[(\d{3,5})\]/i', $q_txt, $q_matches)) {
                $stmt_file_q = $db->prepare("INSERT INTO queues_config (queue_number, queue_name, abandon_enabled, abandon_msg, updated_at) 
                    VALUES (:num, :name, 1, 'Atendimento em fila.', CURRENT_TIMESTAMP)
                    ON CONFLICT(queue_number) DO NOTHING");
                foreach (array_unique($q_matches[1]) as $q_num) {
                    $stmt_file_q->execute([':num' => $q_num, ':name' => "Fila $q_num"]);
                    $queue_count++;
                }
            }
        }
        return ['success' => true, 'extensions' => $ext_count, 'queues' => $queue_count, 'note' => 'Sincronizado via arquivos de configuração nativos do PABX'];
    }
}

/**
 * Executa um comando CLI do Asterisk e devolve o texto limpo.
 * Tenta o AMI (remove "Output: ", cabeçalhos e --END COMMAND--) e cai para `asterisk -rx`.
 */
function asteriskCliOutput($cmd) {
    $out = '';
    $ami = executeAsteriskAMICommand('Command', ['Command' => $cmd]);
    if (!empty($ami['success']) && !empty($ami['response'])) {
        $lines = [];
        foreach (preg_split('/\r\n|\n|\r/', $ami['response']) as $ln) {
            if (preg_match('/^(Response|Message|Privilege|ActionID):/i', $ln) || trim($ln) === '--END COMMAND--') continue;
            $lines[] = preg_replace('/^Output:\s?/', '', $ln);
        }
        $out = trim(implode("\n", $lines));
    }
    if ($out === '' && function_exists('shell_exec')) {
        $out = (string)@shell_exec('asterisk -rx ' . escapeshellarg($cmd) . ' 2>/dev/null');
    }
    return $out;
}

function getAsteriskRealtimeStatuses() {
    $statuses = [];
    $output = asteriskCliOutput('core show hints');
    if ($output !== '') {
        foreach (explode("\n", str_replace("\r", "", $output)) as $line) {
            if (preg_match('/^\s*(\d+)@[\w\-]+\s*:.*State:\s*([A-Za-z0-9_&+\- ]+?)\s{2,}/i', $line . '  ', $m)) {
                $ext = $m[1];
                $raw_st = strtolower($m[2]);
                if (strpos($raw_st, 'unavail') !== false || strpos($raw_st, 'unknown') !== false || strpos($raw_st, 'invalid') !== false) {
                    $st = 'Indisponível';
                } elseif (strpos($raw_st, 'ring') !== false) {
                    $st = 'Tocando';
                } elseif (strpos($raw_st, 'inuse') !== false || strpos($raw_st, 'busy') !== false || strpos($raw_st, 'hold') !== false) {
                    $st = 'Em Chamada';
                } else {
                    $st = 'Livre';
                }
                // vários hints para o mesmo ramal (ext-local, ext-findmefollow): o mais "ativo" vence
                $rank = ['Livre' => 0, 'Indisponível' => 1, 'Em Chamada' => 2, 'Tocando' => 3];
                if (!isset($statuses[$ext]) || $rank[$st] > $rank[$statuses[$ext]]) $statuses[$ext] = $st;
            }
        }
    }
    return $statuses;
}

/**
 * Situação em tempo real de cada ramal a partir dos canais ativos do Asterisk.
 * Retorna ext => ['status' => Chamando|Tocando|Em Chamada, 'partner' => número, 'kind' => ..., 'secs' => int, 'channel' => canal]
 * (layout do `core show channels concise`: canal!ctx!exten!prio!estado!app!dados!callerid!conta!peer!amaflags!duração!bridge!uniqueid)
 */
function getAsteriskLiveExtensionCalls() {
    $raw = asteriskCliOutput('core show channels concise');
    if ($raw === '') return [];
    $chans = [];
    foreach (explode("\n", str_replace("\r", "", $raw)) as $line) {
        $p = explode('!', trim($line));
        if (count($p) < 8) continue;
        $c = [
            'name' => $p[0], 'exten' => $p[2], 'state' => strtolower($p[4]), 'app' => $p[5], 'data' => $p[6],
            'cid' => trim($p[7]), 'secs' => isset($p[11]) && ctype_digit($p[11]) ? (int)$p[11] : 0,
            'bridge' => isset($p[12]) ? trim($p[12]) : '', 'ext' => '', 'trunk' => false, 'local' => false,
        ];
        if (stripos($c['name'], 'Local/') === 0) { $c['local'] = true; }
        elseif (preg_match('/^(?:PJSIP|SIP|IAX2)\/([^\-\/@]+)-/i', $c['name'], $m)) {
            if (ctype_digit($m[1]) && strlen($m[1]) <= 6) $c['ext'] = $m[1]; else $c['trunk'] = true;
        }
        $chans[] = $c;
    }
    $isInternal = function ($n) { return ctype_digit((string)$n) && strlen((string)$n) <= 6; };
    $live = [];
    foreach ($chans as $c) {
        if ($c['ext'] === '') continue;
        $ext = $c['ext']; $partner = ''; $kind = ''; $status = '';
        if ($c['state'] === 'ringing') {
            $status = 'Tocando'; $kind = 'ringing_in';
            if ($c['cid'] !== '' && $c['cid'] !== $ext) $partner = $c['cid'];
            if ($partner === '') {
                foreach ($chans as $o) {
                    if ($o['app'] === 'Dial' && strpos($o['data'], '/' . $ext) !== false && $o['cid'] !== '' && $o['cid'] !== $ext) { $partner = $o['cid']; break; }
                }
            }
        } elseif ($c['state'] === 'ring' || $c['state'] === 'dialing' || $c['state'] === 'dialing offhook' || $c['state'] === 'pre-ring') {
            $status = 'Chamando'; $kind = 'ringing_out';
            if (preg_match('/(?:PJSIP|SIP|IAX2)\/(\d+)/i', $c['data'], $m)) $partner = $m[1];
            elseif ($c['exten'] !== '' && $c['exten'] !== 's') $partner = $c['exten'];
        } elseif ($c['state'] === 'up') {
            $status = 'Em Chamada'; $kind = 'talking';
            if ($c['bridge'] !== '') {
                foreach ($chans as $o) {
                    if ($o['bridge'] === $c['bridge'] && $o['name'] !== $c['name'] && !$o['local']) {
                        if ($o['ext'] !== '') { $partner = $o['ext']; }
                        elseif ($o['cid'] !== '' && !$isInternal($o['cid'])) { $partner = $o['cid']; }
                        elseif ($c['exten'] !== '' && $c['exten'] !== 's') { $partner = $c['exten']; }
                        if ($partner !== '') break;
                    }
                }
            }
            if ($partner === '' && preg_match('/(?:PJSIP|SIP|IAX2)\/(\d+)/i', $c['data'], $m)) $partner = $m[1];
            if ($partner === '' && $c['exten'] !== '' && $c['exten'] !== 's' && $c['exten'] !== $ext) $partner = $c['exten'];
        } else { continue; }
        // se o ramal já tem uma perna "Up" (conversa), ela vale mais que uma perna tocando
        $rank = ['Tocando' => 1, 'Chamando' => 2, 'Em Chamada' => 3];
        if (isset($live[$ext]) && $rank[$live[$ext]['status']] >= $rank[$status]) continue;
        $live[$ext] = ['status' => $status, 'partner' => $partner, 'kind' => $kind, 'secs' => $c['secs'], 'channel' => $c['name']];
    }
    return $live;
}

function getAsteriskTrunksStatus() {
    $trunks = [];
    $sys_conf = getPABXSystemConfig();
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
                    'channelid' => (string)$r['channelid'],
                    'status' => 'Sem verificação', // só vira Registrado/Desconectado se a central confirmar
                    'active_calls' => 0,
                    'channels' => []
                ];
            }
        } catch (Exception $e) {}
    }

    if (true) {
        $sip_reg = asteriskCliOutput('sip show registry');
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

        $pjsip_reg = asteriskCliOutput('pjsip show registrations');
        if ($pjsip_reg) {
            foreach (explode("\n", $pjsip_reg) as $line) {
                if (preg_match('/^\s*([\w\-\.]+)\/\S+.*?\b(Registered|Unregistered|Rejected|Stopped)\b/i', $line, $m)) {
                    $reg_name = preg_replace('/[-_]reg$/i', '', $m[1]);
                    $reg_ok = (strcasecmp($m[2], 'Registered') === 0);
                    foreach ($trunks as $tk => &$tdata) {
                        if ($reg_name !== '' && (stripos($tk, $reg_name) !== false || stripos($reg_name, $tk) !== false)) {
                            $tdata['status'] = $reg_ok ? 'Registrado' : 'Desconectado';
                        }
                    }
                    unset($tdata);
                }
            }
        }

        // Troncos sem registro (autenticação por IP/peer): estado pelo qualify do endpoint/peer
        $pending = array_filter($trunks, function ($t) { return $t['status'] === 'Sem verificação'; });
        if ($pending) {
            $mark = function ($name, $up) use (&$trunks, $pending) {
                foreach ($pending as $tk => $td) {
                    $ids = array_filter([$tk, $td['channelid'] ?? '']);
                    foreach ($ids as $id) {
                        if (strcasecmp($id, $name) === 0 || strcasecmp(preg_replace('/^(sip|pjsip)\//i', '', $id), $name) === 0) {
                            $trunks[$tk]['status'] = $up ? 'Registrado' : 'Desconectado';
                            return;
                        }
                    }
                }
            };
            $ep = asteriskCliOutput('pjsip show endpoints');
            if ($ep && preg_match_all('/^\s*Endpoint:\s+([^\s\/]+)(?:\/\S*)?\s+(Unavailable|Not in use|In use|Busy|Ringing|Invalid|Unreachable)/mi', $ep, $mm, PREG_SET_ORDER)) {
                foreach ($mm as $m) $mark($m[1], !preg_match('/^(Unavailable|Invalid|Unreachable)$/i', $m[2]));
            }
            $peers = asteriskCliOutput('sip show peers');
            if ($peers) {
                foreach (explode("\n", $peers) as $line) {
                    if (preg_match('/^([^\s\/]+)(?:\/\S*)?\s+\S+\s+.*?\b(OK|UNREACHABLE|LAGGED|UNKNOWN)\b/', $line, $m)) {
                        $mark($m[1], strtoupper($m[2]) === 'OK' || strtoupper($m[2]) === 'LAGGED');
                    }
                }
            }
        }

        $chans = asteriskCliOutput('core show channels concise');
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

function getAsteriskQueuesRealtimeStatus($period = 'today') {
    global $db;
    // Sincronizar em tempo real com o MySQL do PABX se necessário
    if (function_exists('syncPrismaAssets')) {
        static $__last_sync = 0;
        $__f = sys_get_temp_dir() . '/prisma_sync_assets.ts';
        $__last_sync = (int)@file_get_contents($__f);
        if (time() - $__last_sync > 60) {
            @file_put_contents($__f, (string)time());
            // Roda depois de entregar a resposta ao navegador (php-fpm): a tela não espera a sincronização com a central
            if (PHP_SAPI !== 'cli' && function_exists('fastcgi_finish_request')) {
                register_shutdown_function(function () { @fastcgi_finish_request(); @syncPrismaAssets(); });
            } else {
                @syncPrismaAssets();
            }
        }
    }
    $queues_config = $db ? $db->query("SELECT * FROM queues_config ORDER BY queue_number ASC")->fetchAll(PDO::FETCH_ASSOC) : [];
    
    // Obter nomes dos ramais e seus status realtime nos hints do Asterisk
    $ext_names = [];
    if ($db) {
        try {
            $ext_rows = $db->query("SELECT extension, agent_name FROM extensions_config")->fetchAll(PDO::FETCH_ASSOC);
            foreach ($ext_rows as $er) {
                $ext_names[$er['extension']] = $er['agent_name'];
            }
        } catch (Exception $e) {}
    }

    // 1. Determinar intervalo de datas conforme o filtro selecionado ($period)
    $start_date = date('Y-m-d');
    $end_date   = date('Y-m-d');

    if ($period === 'yesterday') {
        $start_date = date('Y-m-d', strtotime('-1 day'));
        $end_date   = date('Y-m-d', strtotime('-1 day'));
    } elseif ($period === 'week') {
        $start_date = date('Y-m-d', strtotime('-7 days'));
    } elseif ($period === 'month') {
        $start_date = date('Y-m-01');
    } elseif ($period === 'quarter') {
        $start_date = date('Y-m-d', strtotime('-90 days'));
    }

    $sd_ts  = strtotime("$start_date 00:00:00");
    $ed_ts  = strtotime("$end_date 23:59:59");
    $sd_str = "$start_date 00:00:00";
    $ed_str = "$end_date 23:59:59";

    // 2. Mapeamento de contadores reais por fila (Atendidas, Abandonadas, Tempo de Espera) para o período
    $period_stats = [];
    $ast_db = getAsteriskDBConnection();
    $target_db = $ast_db ? $ast_db : $db;

    if ($target_db) {
        // Tentar buscar na tabela queue_log do Asterisk
        try {
            $q_log = $target_db->prepare("SELECT queuename, event, data1, data2, time
                FROM queue_log
                WHERE (time BETWEEN :sd_str AND :ed_str) OR (CAST(time AS UNSIGNED) BETWEEN :sd_ts AND :ed_ts)");
            $q_log->execute([
                ':sd_str' => $sd_str,
                ':ed_str' => $ed_str,
                ':sd_ts'  => $sd_ts,
                ':ed_ts'  => $ed_ts
            ]);
            while ($row = $q_log->fetch(PDO::FETCH_ASSOC)) {
                $qnum = trim($row['queuename'] ?? '');
                if (!$qnum) continue;
                if (!isset($period_stats[$qnum])) {
                    $period_stats[$qnum] = ['answered' => 0, 'abandoned' => 0, 'wait_sec_total' => 0];
                }
                $evt = strtoupper(trim($row['event'] ?? ''));
                if ($evt === 'CONNECT') {
                    $period_stats[$qnum]['answered']++;
                    $period_stats[$qnum]['wait_sec_total'] += intval($row['data1'] ?? 0);
                } elseif (in_array($evt, ['ABANDON', 'EXITWITHTIMEOUT', 'EXITEMPTY'])) {
                    $period_stats[$qnum]['abandoned']++;
                }
            }
        } catch (Exception $eql) {}

        // Fallback via CDR apenas para filas SEM nenhum registro no queue_log (decidido antes de somar)
        try {
            $has_qlog = array_keys($period_stats);
            $q_cdr = $target_db->prepare("SELECT dst, disposition, duration, billsec FROM cdr WHERE calldate BETWEEN :sd AND :ed");
            $q_cdr->execute([':sd' => $sd_str, ':ed' => $ed_str]);
            $cdr_stats = [];
            while ($row = $q_cdr->fetch(PDO::FETCH_ASSOC)) {
                $qnum = trim($row['dst'] ?? '');
                if ($qnum === '' || in_array($qnum, $has_qlog, true)) continue;
                if (!isset($cdr_stats[$qnum])) $cdr_stats[$qnum] = ['answered' => 0, 'abandoned' => 0, 'wait_sec_total' => 0];
                $disp = strtoupper(trim($row['disposition'] ?? ''));
                if ($disp === 'ANSWERED') {
                    $cdr_stats[$qnum]['answered']++;
                    $cdr_stats[$qnum]['wait_sec_total'] += max(0, intval($row['duration']) - intval($row['billsec']));
                } elseif ($disp === 'NO ANSWER' || $disp === 'BUSY' || $disp === 'FAILED') {
                    $cdr_stats[$qnum]['abandoned']++;
                }
            }
            foreach ($cdr_stats as $k => $v) $period_stats[$k] = $v;
        } catch (Exception $eqc) {}
    }

    $contacts_map = function_exists('getContactsMap') ? getContactsMap() : [];
    $realtime_statuses = getAsteriskRealtimeStatuses();
    $realtime_queues = [];

    // Mapear canais ativos e seus CallerIDs do Asterisk
    $channel_caller_map = [];
    $concise_out = asteriskCliOutput('core show channels concise');
    if (!empty($concise_out)) {
        $clines = explode("\n", str_replace("\r", "", $concise_out));
        foreach ($clines as $cline) {
            $cparts = explode('!', trim($cline));
            if (count($cparts) >= 8) {
                $chan_key = trim($cparts[0]);
                $cid_num  = trim($cparts[7] ?? '');
                $cid_name = trim($cparts[8] ?? '');
                
                if (preg_match('/<(\d+)>/', $cid_num, $m_num)) {
                    $cid_num = $m_num[1];
                }
                
                if (!empty($cid_num)) {
                    $channel_caller_map[$chan_key] = [
                        'num'  => $cid_num,
                        'name' => $cid_name
                    ];
                }
            }
        }
    }

    $cli_output = asteriskCliOutput('queue show');

    // Dividir a saída do CLI por blocos de cada fila
    $queue_blocks = [];
    if (!empty($cli_output)) {
        $split_blocks = preg_split('/^(?=[A-Za-z0-9_\-]+\s+has\s+\d+\s+calls)/m', $cli_output);
        foreach ($split_blocks as $sb) {
            if (preg_match('/^([A-Za-z0-9_\-]+)\s+has\s+\d+\s+calls/i', trim($sb), $bm)) {
                $queue_blocks[$bm[1]] = $sb;
            }
        }
    }

    foreach ($queues_config as $q) {
        $qnum = $q['queue_number'];
        $qname = $q['queue_name'];
        
        $callers_waiting = [];
        $members = [];

        // Dados estatísticos reais extraídos do período selecionado
        $answered  = isset($period_stats[$qnum]) ? $period_stats[$qnum]['answered'] : 0;
        $abandoned = isset($period_stats[$qnum]) ? $period_stats[$qnum]['abandoned'] : 0;
        $tot_wait  = isset($period_stats[$qnum]) ? $period_stats[$qnum]['wait_sec_total'] : 0;
        $avg_sec   = $answered > 0 ? round($tot_wait / $answered) : 0;
        $holdtime_avg = $avg_sec . 's';

        // Localizar bloco correspondente à fila
        $qblock = '';
        if (isset($queue_blocks[$qnum])) {
            $qblock = $queue_blocks[$qnum];
        } elseif (!empty($qname) && isset($queue_blocks[$qname])) {
            $qblock = $queue_blocks[$qname];
        } else {
            foreach ($queue_blocks as $bk => $bv) {
                if ($bk == $qnum || strpos($bv, $qnum . ' has') === 0) {
                    $qblock = $bv;
                    break;
                }
            }
        }

        if (!empty($qblock)) {
            $lines = explode("\n", $qblock);
            $in_members = false;
            $in_callers = false;

            foreach ($lines as $line) {
                $line_trimmed = trim($line);
                if (stripos($line_trimmed, 'Members:') === 0) {
                    $in_members = true;
                    $in_callers = false;
                    continue;
                } elseif (stripos($line_trimmed, 'Callers:') === 0) {
                    $in_members = false;
                    $in_callers = true;
                    continue;
                }

                if ($in_members && strpos($line_trimmed, 'has taken') !== false) {
                    $iface = '';
                    if (preg_match('/^([^\s\(]+)/', $line_trimmed, $im)) {
                        $iface = $im[1];
                    }
                    // Interface COMPLETA do membro (ex.: Local/200@from-queue/n) - necessária para QueuePause
                    // Formato real: "Nome (Local/200@from-queue/n from hint:200@ext-local) (ringinuse enabled) ..."
                    if (preg_match('/\(((?:PJSIP|SIP|DAHDI|Local|IAX2)\/[^\s\)]+)/i', $line_trimmed, $pm)) {
                        $iface = $pm[1];
                    } elseif (preg_match('/^((?:PJSIP|SIP|DAHDI|Local|IAX2)\/[^\s\(]+)/i', $line_trimmed, $pm)) {
                        $iface = $pm[1];
                    }
                    $is_paused = (bool)preg_match('/\(paused(?::[^)]*)?\)/i', $line_trimmed);

                    $agent_ext = '';
                    if (preg_match('/(?:PJSIP|SIP|DAHDI|IAX2)\/(\d+)/i', $iface, $em) || preg_match('/Local\/(\d+)@/i', $iface, $em)) {
                        $agent_ext = $em[1];
                    } else {
                        $agent_ext = preg_replace('/\D/', '', $iface);
                    }
                    if (empty($agent_ext) && preg_match('/^(\d+)/', $line_trimmed, $numMatch)) {
                        $agent_ext = $numMatch[1];
                    }

                    $agent_name = isset($ext_names[$agent_ext]) ? $ext_names[$agent_ext] : '';
                    $line_lower = strtolower($line_trimmed);

                    $st = 'Livre';
                    if (strpos($line_lower, 'not in use') !== false) {
                        $st = 'Livre';
                    } elseif (strpos($line_lower, 'in use') !== false || strpos($line_lower, 'busy') !== false) {
                        $st = 'Em Chamada';
                    } elseif (strpos($line_lower, 'ringing') !== false || strpos($line_lower, 'ringinuse') !== false) {
                        $st = 'Tocando';
                    } elseif (strpos($line_lower, 'unavailable') !== false || strpos($line_lower, 'invalid') !== false || strpos($line_lower, 'unknown') !== false) {
                        $st = 'Indisponível';
                    }

                    // Sincronizar e validar rigorosamente com o status dos hints do ramal para eliminar Falsos Positivos de Tocando
                    if ($agent_ext && isset($realtime_statuses[$agent_ext])) {
                        $hint_st = $realtime_statuses[$agent_ext];
                        // Se o hint do ramal é Indisponível/Deslogado, forçar Indisponível e NUNCA Tocando
                        if ($hint_st === 'Indisponível' || strpos($line_lower, 'unavailable') !== false || strpos($line_lower, 'invalid') !== false) {
                            $st = 'Indisponível';
                        } elseif ($hint_st === 'Livre' && $st !== 'Em Chamada') {
                            $st = 'Livre';
                        } elseif ($hint_st === 'Em Chamada') {
                            $st = 'Em Chamada';
                        } elseif ($hint_st === 'Tocando') {
                            $st = 'Tocando';
                        }
                    } elseif (empty($agent_ext) || strpos($line_lower, 'unavailable') !== false || strpos($line_lower, 'invalid') !== false) {
                        $st = 'Indisponível';
                    }

                    if ($is_paused && $st !== 'Indisponível') $st = 'Pausado';
                    $members[] = [
                        'interface' => $iface ?: ('PJSIP/' . $agent_ext),
                        'extension' => $agent_ext ?: '?',
                        'name' => formatEndpointName($agent_ext),
                        'status' => $st,
                        'paused' => $is_paused
                    ];
                }

                if ($in_callers && preg_match('/^\s*(\d+)\.\s+([A-Za-z0-9_\-\/@\.;]+)\s+\(wait:\s*([\d:]+),\s*prio:\s*\d+\)/i', $line, $cm)) {
                    $pos       = intval($cm[1]);
                    $chan      = trim($cm[2]);
                    $wait_time = trim($cm[3]);

                    $caller_num  = '';
                    $caller_name = '';

                    if (isset($channel_caller_map[$chan])) {
                        $caller_num  = $channel_caller_map[$chan]['num'];
                        $caller_name = $channel_caller_map[$chan]['name'];
                    }

                    if (empty($caller_num)) {
                        if (preg_match('/(?:PJSIP|SIP|DAHDI|Local|IAX2)\/(\d{4,15})/i', $chan, $m_chan_num)) {
                            $caller_num = $m_chan_num[1];
                        } elseif (preg_match('/(\d{8,15})/', $chan, $m_chan_num)) {
                            $caller_num = $m_chan_num[1];
                        }
                    }

                    $agenda_name = false;
                    if (!empty($caller_num) && function_exists('lookupContactName')) {
                        $agenda_name = lookupContactName($caller_num, $contacts_map);
                    }

                    if (!empty($agenda_name)) {
                        $formatted_num = function_exists('formatPhoneNumber') ? formatPhoneNumber($caller_num) : $caller_num;
                        $caller_label  = "👤 $agenda_name ($formatted_num)";
                        $display_name  = "$agenda_name ($formatted_num)";
                    } elseif (!empty($caller_name) && strtolower($caller_name) !== strtolower($caller_num) && strtolower($caller_name) !== 'unknown') {
                        $formatted_num = function_exists('formatPhoneNumber') ? formatPhoneNumber($caller_num) : $caller_num;
                        $caller_label  = "📞 $caller_name ($formatted_num)";
                        $display_name  = "$caller_name ($formatted_num)";
                    } elseif (!empty($caller_num)) {
                        $formatted_num = function_exists('formatPhoneNumber') ? formatPhoneNumber($caller_num) : $caller_num;
                        $caller_label  = "📞 $formatted_num";
                        $display_name  = $formatted_num;
                    } else {
                        $caller_label  = "📞 $chan";
                        $display_name  = $chan;
                    }

                    $callers_waiting[] = [
                        'pos'          => $pos,
                        'channel'      => $chan,
                        'caller_num'   => $caller_num,
                        'caller_name'  => $display_name,
                        'caller_label' => $caller_label,
                        'wait_time'    => $wait_time
                    ];
                }
            }
        }

        // Ordenar os agentes da fila priorizando ONLINE (Em Chamada, Tocando, Livre, Pausado) e depois OFFLINE (Indisponível)
        usort($members, function($a, $b) {
            $priority = [
                'Em Chamada'   => 1,
                'Tocando'      => 2,
                'Livre'        => 3,
                'Pausado'      => 4,
                'Indisponível' => 5,
                'Offline'      => 5,
                'Desconectado' => 5
            ];

            $pA = isset($priority[$a['status']]) ? $priority[$a['status']] : 5;
            $pB = isset($priority[$b['status']]) ? $priority[$b['status']] : 5;

            if ($pA !== $pB) {
                return $pA <=> $pB;
            }

            $extA = intval(preg_replace('/\D/', '', $a['extension']));
            $extB = intval(preg_replace('/\D/', '', $b['extension']));
            return $extA <=> $extB;
        });

        $realtime_queues[] = [
            'id' => $q['id'],
            'queue_number' => $qnum,
            'queue_name' => formatEndpointName($qnum),
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


function getAsteriskActiveCallsDetailed() {
    global $db;
    $active_calls = [];

    $ext_names = [];
    if ($db) {
        try {
            $rows = $db->query("SELECT extension, agent_name FROM extensions_config")->fetchAll(PDO::FETCH_ASSOC);
            foreach ($rows as $r) {
                $ext_names[$r['extension']] = $r['agent_name'];
            }
        } catch (Exception $e) {}
    }

    $output = asteriskCliOutput('core show channels concise');
    if (!empty($output)) {
        $lines = explode("\n", str_replace("\r", "", $output));
        foreach ($lines as $line) {
            $parts = explode('!', trim($line));
            if (count($parts) >= 12) {
                $channel   = $parts[0];
                $context   = $parts[1];
                $exten     = $parts[2];
                $state     = $parts[4];
                $duration  = (int)($parts[11] ?? 0);

                $src_ext = '';
                if (preg_match('/(?:PJSIP|SIP|DAHDI|Local)\/(\d+)/i', $channel, $m)) {
                    $src_ext = $m[1];
                }

                if (!empty($src_ext) && !empty($exten) && $src_ext !== $exten) {
                    $src_label = isset($ext_names[$src_ext]) ? "{$src_ext} - {$ext_names[$src_ext]}" : "Ramal {$src_ext}";
                    $dst_label = isset($ext_names[$exten]) ? "{$exten} - {$ext_names[$exten]}" : "Ramal {$exten}";

                    $min = floor($duration / 60);
                    $sec = $duration % 60;
                    $dur_str = sprintf('%02d:%02d', $min, $sec);

                    $active_calls[] = [
                        'src_ext'   => $src_ext,
                        'src_label' => $src_label,
                        'dst_ext'   => $exten,
                        'dst_label' => $dst_label,
                        'state'     => (strtolower($state) === 'up') ? 'Em Conversação' : 'Chamando',
                        'duration'  => $dur_str,
                        'channel'   => $channel
                    ];
                }
            }
        }
    }
    return $active_calls;
}

function getAsteriskParkingConfig() {
    $config = [
        'parkext'     => '700',
        'start_slot'  => 701,
        'end_slot'    => 704,
        'num_slots'   => 4
    ];

    // 1. Tentar consultar no banco MySQL do Asterisk (FreePBX / Issabel)
    $ast_db = function_exists('getAsteriskPdoConnection') ? getAsteriskPdoConnection('asterisk') : null;
    if ($ast_db) {
        try {
            $tables = ['parkinglots', 'parkinglot'];
            foreach ($tables as $tbl) {
                $q = $ast_db->query("SELECT * FROM $tbl LIMIT 1");
                if ($q) {
                    $row = $q->fetch(PDO::FETCH_ASSOC);
                    if ($row) {
                        $parkext = $row['parkext'] ?? $row['ext'] ?? $row['exten'] ?? '700';
                        $start   = intval($row['parkpos'] ?? $row['start'] ?? $row['parkstart'] ?? 701);
                        $num     = intval($row['numslots'] ?? $row['slots'] ?? 4);
                        if ($start > 0 && $num > 0) {
                            $config['parkext']    = (string)$parkext;
                            $config['start_slot'] = $start;
                            $config['num_slots']  = $num;
                            $config['end_slot']   = ($start + $num) - 1;
                            return $config;
                        }
                    }
                }
            }
        } catch (Exception $e) {}
    }

    // 2. Fallback: Parse dos arquivos de configuração do Asterisk (/etc/asterisk/*.conf)
    $conf_files = [
        '/etc/asterisk/res_parking_additional.conf',
        '/etc/asterisk/res_parking.conf',
        '/etc/asterisk/features_additional.conf',
        '/etc/asterisk/features.conf'
    ];

    foreach ($conf_files as $cf) {
        if (!file_exists($cf)) continue;
        $txt = @file_get_contents($cf);
        if ($txt) {
            if (preg_match('/parkpos\s*=>\s*(\d+)-(\d+)/i', $txt, $m)) {
                $start = intval($m[1]);
                $end   = intval($m[2]);
                if ($start > 0 && $end >= $start) {
                    $config['start_slot'] = $start;
                    $config['end_slot']   = $end;
                    $config['num_slots']  = ($end - $start) + 1;
                }
            }
            if (preg_match('/parkext\s*=>\s*(\d+)/i', $txt, $m)) {
                $config['parkext'] = $m[1];
            }
        }
    }

    return $config;
}

function getAsteriskParkingLots() {
    $parking_lots = [];
    $found_slots  = [];
    
    // Configuração real do Parking no PABX
    $parking_config = getAsteriskParkingConfig();

    // Mapeamento de contatos para rotular nomes de chamadores
    $contacts_map = function_exists('getContactsMap') ? getContactsMap() : [];

    if (false) {
        return [
            'config' => $parking_config,
            'lots'   => $parking_lots
        ];
    }

    // Estratégia 1: 'parking show default' ou 'parking show' (Asterisk 12+ res_parking)
    $out1 = asteriskCliOutput('parking show default');
    if (empty($out1) || strpos(strtolower($out1), 'no such command') !== false) {
        $out1 = asteriskCliOutput('parking show');
    }

    // Estratégia 2: 'parkedcalls show' (Asterisk 11 e anteriores res_features)
    $out2 = asteriskCliOutput('parkedcalls show');

    // Processar saídas dos comandos CLI nativos de parking
    $raw_outputs = array_filter([$out1, $out2]);
    foreach ($raw_outputs as $output) {
        if (!$output) continue;
        $lines = explode("\n", str_replace("\r", "", $output));
        foreach ($lines as $line) {
            $line_trimmed = trim($line);
            if (empty($line_trimmed) || strpos($line_trimmed, 'Num Extension') !== false || strpos($line_trimmed, 'Parking Lot') !== false || strpos($line_trimmed, '----------------') !== false) {
                continue;
            }

            // Capturar linhas com slot, canal e tempo
            if (preg_match('/(?:^|\s)(\d{3,4})\s+([A-Za-z0-9_\-\/@\.]+)\s+.*?(?:(\d+:\d+|\d+s|\d+\s*sec|\d+))/i', $line_trimmed, $m)) {
                $slot     = trim($m[1]);
                $chan_raw = trim($m[2]);
                $time_raw = trim($m[3] ?? '');

                if (intval($slot) >= 701 && intval($slot) <= 799 && !isset($found_slots[$slot])) {
                    $caller_ext = '';
                    if (preg_match('/(?:PJSIP|SIP|DAHDI|Local|IAX2)\/([^\-@\s]+)/i', $chan_raw, $cm)) {
                        $caller_ext = $cm[1];
                    }

                    $sec = 0;
                    if (preg_match('/(\d+)s/i', $time_raw, $tm)) {
                        $sec = intval($tm[1]);
                    } elseif (preg_match('/(\d+):(\d+)/', $time_raw, $tm)) {
                        $sec = (intval($tm[1]) * 60) + intval($tm[2]);
                    } elseif (is_numeric($time_raw)) {
                        $sec = intval($time_raw);
                    }

                    $caller_label = !empty($caller_ext) ? (function_exists('lookupContactName') ? lookupContactName($caller_ext, $contacts_map) : $caller_ext) : $chan_raw;
                    if ($caller_label === $caller_ext && !empty($caller_ext)) {
                        $caller_label = "Ramal " . $caller_ext;
                    }

                    $found_slots[$slot] = true;
                    $parking_lots[] = [
                        'slot'         => $slot,
                        'channel'      => $chan_raw,
                        'caller_ext'   => $caller_ext,
                        'caller_label' => $caller_label,
                        'duration_sec' => $sec,
                        'parked_at_ts' => time() - $sec,
                        'duration_fmt' => sprintf('%02d:%02d', floor($sec / 60), $sec % 60)
                    ];
                }
            }
        }
    }

    // Estratégia 3: Fallback via 'core show channels concise'
    $out_chans = asteriskCliOutput('core show channels concise');
    if (!empty($out_chans)) {
        $lines = explode("\n", str_replace("\r", "", $out_chans));
        foreach ($lines as $line) {
            $parts = explode('!', trim($line));
            if (count($parts) >= 11) {
                $ch_name  = trim($parts[0]);
                $context  = trim($parts[1]);
                $exten    = trim($parts[2]);
                $app      = trim($parts[5] ?? '');
                $app_data = trim($parts[6] ?? '');
                $caller_id= trim($parts[7] ?? '');
                $dur_sec  = intval($parts[10] ?? 0);

                $detected_slot = '';
                if (preg_match('/^7[0-9]{2}$/', $exten)) {
                    $detected_slot = $exten;
                } elseif (preg_match('/7[0-9]{2}/', $app_data, $sm)) {
                    $detected_slot = $sm[0];
                }

                $is_parking_chan = (
                    strpos(strtolower($context), 'park') !== false || 
                    strpos(strtolower($app), 'park') !== false ||
                    !empty($detected_slot)
                );

                if ($is_parking_chan && !empty($detected_slot) && intval($detected_slot) >= 701 && intval($detected_slot) <= 799) {
                    if (!isset($found_slots[$detected_slot])) {
                        $caller_num = '';
                        if (preg_match('/<(\d+)>/', $caller_id, $cm)) {
                            $caller_num = $cm[1];
                        } elseif (preg_match('/^\d+$/', $caller_id)) {
                            $caller_num = $caller_id;
                        } elseif (preg_match('/(?:PJSIP|SIP|DAHDI|Local)\/(\d+)/i', $ch_name, $cm)) {
                            $caller_num = $cm[1];
                        }

                        $caller_label = !empty($caller_num) ? (function_exists('lookupContactName') ? lookupContactName($caller_num, $contacts_map) : "Ramal $caller_num") : $ch_name;

                        $found_slots[$detected_slot] = true;
                        $parking_lots[] = [
                            'slot'         => $detected_slot,
                            'channel'      => $ch_name,
                            'caller_ext'   => $caller_num,
                            'caller_label' => $caller_label,
                            'duration_sec' => $dur_sec,
                            'parked_at_ts' => time() - $dur_sec,
                            'duration_fmt' => sprintf('%02d:%02d', floor($dur_sec / 60), $dur_sec % 60)
                        ];
                    }
                }
            }
        }
    }

    // Estratégia 4: Fallback via 'core show hints'
    $out_hints = asteriskCliOutput('core show hints');
    if (!empty($out_hints)) {
        $lines = explode("\n", str_replace("\r", "", $out_hints));
        foreach ($lines as $line) {
            if (preg_match('/^\s*(70[1-8])@(?:park-hints|parkedcalls|default)\s*:\s*.*?State:(InUse|Ringing|Busy)/i', $line, $hm)) {
                $h_slot = $hm[1];
                if (!isset($found_slots[$h_slot])) {
                    $found_slots[$h_slot] = true;
                    $parking_lots[] = [
                        'slot'         => $h_slot,
                        'channel'      => 'Chamada Estacionada',
                        'caller_ext'   => '',
                        'caller_label' => 'Ligação em Espera',
                        'duration_sec' => 0,
                        'parked_at_ts' => time(),
                        'duration_fmt' => '00:00'
                    ];
                }
            }
        }
    }

    return [
        'config' => $parking_config,
        'lots'   => $parking_lots
    ];
}

function getAsteriskConferences() {
    $conf_map = [];

    // 1. Buscar salas no MySQL do Asterisk (FreePBX / Issabel)
    $ast_db = function_exists('getAsteriskPdoConnection') ? getAsteriskPdoConnection('asterisk') : null;
    if ($ast_db) {
        try {
            $tables = ['meetme', 'conferences'];
            foreach ($tables as $tbl) {
                $q = $ast_db->query("SELECT * FROM $tbl");
                if ($q) {
                    while ($row = $q->fetch(PDO::FETCH_ASSOC)) {
                        $exten = trim($row['exten'] ?? $row['ext'] ?? $row['room'] ?? '');
                        if (empty($exten)) continue;
                        $name = trim($row['description'] ?? $row['name'] ?? "Sala {$exten}");
                        if (empty($name)) $name = "Sala {$exten}";
                        
                        $conf_map[$exten] = [
                            'exten' => $exten,
                            'name'  => $name,
                            'userpin' => trim($row['userpin'] ?? ''),
                            'adminpin' => trim($row['adminpin'] ?? ''),
                            'participants_count' => 0
                        ];
                    }
                }
            }
        } catch (Exception $e) {}
    }

    // 2. Fallback: Parse dos arquivos de configuração se não achou no DB
    if (empty($conf_map)) {
        $files = [
            '/etc/asterisk/meetme_additional.conf',
            '/etc/asterisk/confbridge_additional.conf'
        ];
        foreach ($files as $cf) {
            if (!file_exists($cf)) continue;
            $txt = @file_get_contents($cf);
            if ($txt && preg_match_all('/(?:conf\s*=>\s*|\[)(\d{3,5})(?:\]|\s*,)/i', $txt, $m)) {
                foreach (array_unique($m[1]) as $exten) {
                    if (!isset($conf_map[$exten])) {
                        $conf_map[$exten] = [
                            'exten' => $exten,
                            'name'  => "Sala {$exten}",
                            'userpin' => '',
                            'adminpin' => '',
                            'participants_count' => 0
                        ];
                    }
                }
            }
        }
    }

    // 3. Checar participantes ativos via CLI (ConfBridge ou MeetMe)
    if (true && !empty($conf_map)) {
        // Testar ConfBridge
        $out_cb = asteriskCliOutput('confbridge show rooms');
        if (!empty($out_cb)) {
            $lines = explode("\n", $out_cb);
            foreach ($lines as $line) {
                if (preg_match('/^\s*(\d{3,5})\s+(\d+)/i', trim($line), $cm)) {
                    $c_ext = $cm[1];
                    $p_cnt = intval($cm[2]);
                    if (isset($conf_map[$c_ext])) {
                        $conf_map[$c_ext]['participants_count'] = $p_cnt;
                    }
                }
            }
        }

        // Testar MeetMe
        $out_mm = asteriskCliOutput('meetme list');
        if (!empty($out_mm)) {
            $lines = explode("\n", $out_mm);
            foreach ($lines as $line) {
                if (preg_match('/Conference\s+(\d{3,5})\s+has\s+(\d+)/i', trim($line), $mm)) {
                    $c_ext = $mm[1];
                    $p_cnt = intval($mm[2]);
                    if (isset($conf_map[$c_ext])) {
                        $conf_map[$c_ext]['participants_count'] = $p_cnt;
                    }
                }
            }
        }
    }

    return array_values($conf_map);
}

/**
 * Envia um ARQUIVO (documento/áudio) por WhatsApp pelo provedor ativo.
 * Z-PRO: POST {api_url}/base64 (base64Data, mimeType, fileName). UAZAPI: POST /send/media (type=document).
 */
function sendWhatsAppFile($phone, $fileName, $content, $mime = 'application/octet-stream', $caption = '', $call_id = 'MANUAL', $extension = 'PAINEL', $rule_type = 'ENVIO_MANUAL_ARQUIVO', $mediaType = 'document') {
    global $db;
    $num = preg_replace('/\D/', '', (string)$phone);
    if (strlen($num) === 10 || strlen($num) === 11) $num = '55' . $num;
    $fileName = preg_replace('/[^A-Za-z0-9._\- ]/', '_', basename((string)$fileName)) ?: 'arquivo';
    $http = 0; $resp = ''; $err = ''; $ok = false;

    if ($num === '' || $content === '' || $content === null) {
        $err = 'Número ou arquivo vazio';
    } elseif (waProvider() === 'uazapi') {
        $r = uazapiRequest('POST', '/send/media', ['number' => $num, 'type' => ($mediaType === 'auto' ? uazapiMediaType($mime) : 'document'), 'file' => base64_encode($content), 'docName' => $fileName, 'text' => (string)$caption], 60);
        $http = $r['http']; $resp = $r['resp']; $err = $r['error'];
        $ok = ($err === '' && $http >= 200 && $http < 300);
        if (!$ok && $err === '') $err = 'HTTP ' . $http . ($http === 401 ? ' (token da instância inválido)' : '');
    } else {
        $api_url = trim((string)getSetting('api_url'));
        $tok = trim(preg_replace('/^bearer\s+/i', '', trim((string)getSetting('api_token'))));
        if ($api_url === '' || $tok === '') {
            $err = 'API Prismabot não configurada (URL ou Token ausente).';
        } else {
            $body = ['body' => (string)$caption, 'number' => $num, 'base64Data' => base64_encode($content), 'mimeType' => $mime, 'fileName' => $fileName, 'isClosed' => false, 'validateNumber' => true];
            $wid = getSetting('prismabot_whatsapp_id');
            if (!empty($wid)) $body['whatsappId'] = is_numeric($wid) ? intval($wid) : $wid;
            $ch = curl_init(rtrim($api_url, '/') . '/base64');
            curl_setopt_array($ch, [
                CURLOPT_POST => true, CURLOPT_POSTFIELDS => json_encode($body, JSON_UNESCAPED_UNICODE), CURLOPT_RETURNTRANSFER => true,
                CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'Authorization: Bearer ' . $tok],
                CURLOPT_TIMEOUT => 60, CURLOPT_SSL_VERIFYPEER => false, CURLOPT_SSL_VERIFYHOST => false
            ]);
            $resp = (string)curl_exec($ch); $http = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE); $ce = curl_error($ch); curl_close($ch);
            if ($ce) { $err = 'cURL Error: ' . $ce; }
            else {
                $ok = ($http >= 200 && $http < 300);
                $d = json_decode($resp, true);
                if ($ok && is_array($d) && isset($d['success']) && $d['success'] === false) { $ok = false; $err = $d['error'] ?? 'API retornou success=false'; }
                if (!$ok && $err === '') $err = 'HTTP ' . $http . (is_array($d) && !empty($d['error']) ? ': ' . $d['error'] : '');
            }
        }
    }
    try {
        if ($db) {
            $db->prepare("INSERT INTO sent_logs (phone, call_id, extension, rule_type, message, status, response_raw) VALUES (:p, :c, :e, :r, :m, :s, :rr)")->execute([
                ':p' => $num, ':c' => $call_id, ':e' => $extension, ':r' => $rule_type,
                ':m' => '📎 Arquivo: ' . $fileName . ' (' . round(strlen((string)$content) / 1024) . ' KB)',
                ':s' => $ok ? 'SUCCESS' : 'ERROR', ':rr' => substr($resp !== '' ? $resp : $err, 0, 1000)
            ]);
        }
    } catch (Exception $e) {}
    if (function_exists('pabx_log')) pabx_log('whatsapp', $ok ? 'INFO' : 'ERROR', "Envio de arquivo {$fileName} para {$num} [HTTP {$http}]", ['error' => $err]);
    return ['success' => $ok, 'error' => $err];
}

/**
 * Envia ao navegador a exportação COMPLETA de um relatório (csv | xlsx | pdf) e encerra a requisição.
 * $rows = matriz de linhas (a 1ª é o cabeçalho). Aceita linhas em branco ([]) para separar tabelas.
 */
function reportExportSend($format, $baseName, $title, $summary, $rows) {
    while (ob_get_level()) { ob_end_clean(); }
    $base = preg_replace('/[^A-Za-z0-9._\-]/', '_', $baseName) ?: 'relatorio';
    $format = strtolower((string)$format);
    if ($format === 'pdf') {
        $body = shareBuildPdf($title, $summary, $rows); $mime = 'application/pdf'; $fn = $base . '.pdf';
    } else {
        $body = ''; $fn = $base . '.xlsx'; $mime = 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet';
        if ($format === 'xlsx') $body = shareBuildXlsx($rows, $title);
        if ($body === '') {   // csv pedido (ou Excel indisponível): CSV com BOM e ';' para abrir direto no Excel BR
            $fn = $base . '.csv'; $mime = 'text/csv; charset=UTF-8';
            $body = "\xEF\xBB\xBF";
            foreach ($rows as $r) {
                $body .= implode(';', array_map(function ($v) { return '"' . str_replace('"', '""', (string)$v) . '"'; }, $r)) . "\r\n";
            }
        }
    }
    header('Content-Type: ' . $mime);
    header('Content-Disposition: attachment; filename="' . $fn . '"');
    header('Content-Length: ' . strlen($body));
    header('Cache-Control: no-cache, must-revalidate');
    echo $body;
    exit;
}

/** Converte o CSV do relatório (',' ou ';') em matriz de linhas. Linhas em branco viram []. */
function shareParseCsv($csv) {
    $csv = preg_replace('/^\xEF\xBB\xBF/', '', (string)$csv);
    $lines = preg_split('/\r\n|\n|\r/', rtrim($csv));
    $first = $lines[0] ?? '';
    $delim = (substr_count($first, ';') > substr_count($first, ',')) ? ';' : ',';
    $rows = [];
    foreach ($lines as $ln) {
        if (trim($ln) === '') { $rows[] = []; continue; }
        $rows[] = str_getcsv($ln, $delim, '"', '');
    }
    return $rows;
}

/** Gera um .xlsx real (1 planilha, cabeçalhos em negrito). Retorna '' se ZipArchive indisponível. */
function shareBuildXlsx($rows, $sheetName = 'Relatório') {
    if (!class_exists('ZipArchive')) return '';
    $esc = function ($t) { return htmlspecialchars((string)$t, ENT_XML1 | ENT_QUOTES, 'UTF-8'); };
    $colName = function ($i) { $n = ''; $i++; while ($i > 0) { $m = ($i - 1) % 26; $n = chr(65 + $m) . $n; $i = intdiv($i - $m - 1, 26); } return $n; };
    $sheet = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><sheetViews><sheetView workbookViewId="0"/></sheetViews><cols><col min="1" max="30" width="22" customWidth="1"/></cols><sheetData>';
    $prevBlank = true; $r = 0;
    foreach ($rows as $row) {
        $r++;
        if (!$row) { $prevBlank = true; $sheet .= '<row r="' . $r . '"/>'; continue; }
        $isHead = $prevBlank; $prevBlank = false;
        $sheet .= '<row r="' . $r . '">';
        foreach ($row as $ci => $v) {
            $ref = $colName($ci) . $r; $v = (string)$v;
            $style = $isHead ? ' s="1"' : '';
            if (!$isHead && preg_match('/^-?\d{1,15}(\.\d+)?$/', $v) && !preg_match('/^0\d/', $v) && strlen($v) < 12) {
                $sheet .= '<c r="' . $ref . '"' . $style . '><v>' . $v . '</v></c>';
            } else {
                $sheet .= '<c r="' . $ref . '"' . $style . ' t="inlineStr"><is><t xml:space="preserve">' . $esc($v) . '</t></is></c>';
            }
        }
        $sheet .= '</row>';
    }
    $sheet .= '</sheetData></worksheet>';
    $tmp = tempnam(sys_get_temp_dir(), 'xlsx');
    $z = new ZipArchive();
    if ($z->open($tmp, ZipArchive::OVERWRITE) !== true) return '';
    $z->addFromString('[Content_Types].xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"><Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/><Default Extension="xml" ContentType="application/xml"/><Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/><Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/><Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/></Types>');
    $z->addFromString('_rels/.rels', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/></Relationships>');
    $z->addFromString('xl/workbook.xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"><sheets><sheet name="' . $esc(mb_substr(preg_replace('/[\\\\\/?*\[\]:]/', ' ', $sheetName), 0, 31)) . '" sheetId="1" r:id="rId1"/></sheets></workbook>');
    $z->addFromString('xl/_rels/workbook.xml.rels', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/><Relationship Id="rId2" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/></Relationships>');
    $z->addFromString('xl/styles.xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><fonts count="2"><font><sz val="11"/><name val="Calibri"/></font><font><b/><sz val="11"/><name val="Calibri"/></font></fonts><fills count="3"><fill><patternFill patternType="none"/></fill><fill><patternFill patternType="gray125"/></fill><fill><patternFill patternType="solid"><fgColor rgb="FFE2E8F0"/></patternFill></fill></fills><borders count="1"><border><left/><right/><top/><bottom/><diagonal/></border></borders><cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs><cellXfs count="2"><xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0"/><xf numFmtId="0" fontId="1" fillId="2" borderId="0" xfId="0" applyFont="1" applyFill="1"/></cellXfs><cellStyles count="1"><cellStyle name="Normal" xfId="0" builtinId="0"/></cellStyles></styleSheet>');
    $z->addFromString('xl/worksheets/sheet1.xml', $sheet);
    $z->close();
    $bin = (string)file_get_contents($tmp); @unlink($tmp);
    return $bin;
}

/** Gera um PDF (A4 paisagem, Helvetica) com título, resumo e tabela(s). Sem bibliotecas externas. */
function shareBuildPdf($title, $summary, $rows) {
    $enc = function ($t) {
        $t = (string)$t;
        $c = @iconv('UTF-8', 'Windows-1252//TRANSLIT//IGNORE', $t);
        if ($c === false) $c = preg_replace('/[^\x20-\x7E]/', '?', $t);
        return str_replace(['\\', '(', ')', "\r", "\n"], ['\\\\', '\\(', '\\)', ' ', ' '], $c);
    };
    $W = 842; $H = 595; $M = 30; $fs = 8; $cw = $fs * 0.58; $lh = 13;
    // larguras proporcionais ao conteúdo
    $ncol = 1; $maxlen = [];
    foreach ($rows as $r) { $ncol = max($ncol, count($r)); foreach ($r as $i => $v) $maxlen[$i] = max($maxlen[$i] ?? 4, min(34, mb_strlen((string)$v))); }
    $wcol = []; $tot = 0;
    for ($i = 0; $i < $ncol; $i++) { $wcol[$i] = (($maxlen[$i] ?? 4) + 2) * $cw; $tot += $wcol[$i]; }
    $avail = $W - 2 * $M;
    if ($tot > $avail) { $k = $avail / $tot; foreach ($wcol as &$w) $w *= $k; unset($w); }
    $fit = function ($t, $w) use ($cw) { $t = (string)$t; $max = max(1, (int)floor(($w - 4) / $cw)); return mb_strlen($t) > $max ? mb_substr($t, 0, max(1, $max - 1)) . '…' : $t; };

    $pages = []; $cur = ''; $y = $H - $M;
    $newPage = function () use (&$cur, &$y, &$pages, $H, $M) { if ($cur !== '') $pages[] = $cur; $cur = ''; $y = $H - $M; };
    $text = function ($x, $yy, $t, $bold = false, $size = 8) use (&$cur, $enc) { $cur .= 'BT /' . ($bold ? 'F2' : 'F1') . ' ' . $size . ' Tf ' . round($x, 2) . ' ' . round($yy, 2) . ' Td (' . $enc($t) . ") Tj ET\n"; };
    // cabeçalho do documento
    $cur .= "0.486 0.227 0.929 rg " . $M . ' ' . ($H - $M - 6) . ' ' . $avail . " 4 re f 0 g\n"; $y -= 22;
    $text($M, $y, $title, true, 15); $y -= 16;
    $text($M, $y, 'Gerado em ' . date('d/m/Y H:i') . ' - IPbx Prisma', false, 8); $y -= 16;
    foreach (preg_split('/\r\n|\n|\r/', trim((string)$summary)) as $ln) { if ($ln === '') { $y -= 5; continue; } $text($M, $y, $ln, false, 9); $y -= 12; }
    $y -= 8;
    $prevBlank = true; $lastHead = null;
    foreach ($rows as $row) {
        if (!$row) { $prevBlank = true; $y -= 8; continue; }
        $isHead = $prevBlank; $prevBlank = false;
        if ($isHead) $lastHead = $row;
        if ($y < $M + 30) { $newPage(); if ($lastHead && !$isHead) { $x = $M; $cur .= "0.886 0.910 0.941 rg " . $M . ' ' . ($y - 3) . ' ' . $avail . ' ' . $lh . " re f 0 g\n"; foreach ($lastHead as $i => $v) { $text($x + 2, $y, $fit($v, $wcol[$i]), true); $x += $wcol[$i]; } $y -= $lh; } }
        $x = $M;
        if ($isHead) $cur .= "0.886 0.910 0.941 rg " . $M . ' ' . ($y - 3) . ' ' . $avail . ' ' . $lh . " re f 0 g\n";
        foreach ($row as $i => $v) { $text($x + 2, $y, $fit($v, $wcol[$i] ?? 40), $isHead); $x += ($wcol[$i] ?? 40); }
        $y -= $lh;
    }
    if ($cur !== '') $pages[] = $cur;
    $n = count($pages);
    foreach ($pages as $i => &$pg) { $pg .= 'BT /F1 7 Tf ' . ($W / 2 - 25) . ' 16 Td (' . $enc('Página ' . ($i + 1) . '/' . $n) . ") Tj ET\n"; } unset($pg);
    $objs = [1 => '<< /Type /Catalog /Pages 2 0 R >>', 3 => '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica /Encoding /WinAnsiEncoding >>', 4 => '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica-Bold /Encoding /WinAnsiEncoding >>'];
    $kids = []; $id = 5;
    foreach ($pages as $pg) {
        $objs[$id] = '<< /Length ' . strlen($pg) . " >>\nstream\n" . $pg . "endstream";
        $objs[$id + 1] = '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 ' . $W . ' ' . $H . '] /Resources << /Font << /F1 3 0 R /F2 4 0 R >> >> /Contents ' . $id . ' 0 R >>';
        $kids[] = ($id + 1) . ' 0 R'; $id += 2;
    }
    $objs[2] = '<< /Type /Pages /Kids [' . implode(' ', $kids) . '] /Count ' . count($kids) . ' >>';
    ksort($objs);
    $out = "%PDF-1.4\n"; $off = [];
    foreach ($objs as $num => $body) { $off[$num] = strlen($out); $out .= $num . " 0 obj\n" . $body . "\nendobj\n"; }
    $xref = strlen($out);
    $out .= "xref\n0 " . (count($objs) + 1) . "\n0000000000 65535 f \n";
    foreach ($off as $o) $out .= sprintf("%010d 00000 n \n", $o);
    $out .= "trailer\n<< /Size " . (count($objs) + 1) . " /Root 1 0 R >>\nstartxref\n" . $xref . "\n%%EOF";
    return $out;
}

/**
 * Monta os anexos pedidos pelo modal de compartilhamento: CSV do relatório e/ou a gravação (uid).
 * Retorna [anexos[], avisos[]] onde anexo = ['name','type','content'].
 */
function buildShareAttachments($body) {
    $atts = []; $warns = [];
    $csv = (string)($body['csv'] ?? '');
    if ($csv !== '') {
        if (strlen($csv) > 3 * 1024 * 1024) { $warns[] = 'CSV maior que 3MB não foi anexado.'; }
        else {
            $fn = preg_replace('/[^A-Za-z0-9._\-]/', '_', basename((string)($body['filename'] ?? 'relatorio.csv')));
            $base = preg_replace('/\.(csv|xlsx|pdf)$/i', '', $fn) ?: 'relatorio';
            $fmt = strtolower((string)($body['format'] ?? 'xlsx'));
            $rows = shareParseCsv($csv);
            $title = trim((string)($body['title'] ?? 'Relatório')) ?: 'Relatório';
            if ($fmt === 'pdf') {
                $atts[] = ['name' => $base . '.pdf', 'type' => 'application/pdf', 'content' => shareBuildPdf($title, (string)($body['summary'] ?? ''), $rows)];
            } elseif ($fmt === 'xlsx') {
                $xl = shareBuildXlsx($rows, $title);
                if ($xl !== '') { $atts[] = ['name' => $base . '.xlsx', 'type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', 'content' => $xl]; }
                else { $warns[] = 'Excel indisponível no servidor (extensão zip do PHP): enviado em CSV.'; $fmt = 'csv'; }
            } else { $fmt = 'csv'; }
            if ($fmt === 'csv') {
                $atts[] = ['name' => $base . '.csv', 'type' => 'text/csv', 'content' => (strpos($csv, "\xEF\xBB\xBF") === 0 ? '' : "\xEF\xBB\xBF") . $csv];
            }
        }
    }
    $uid = trim((string)($body['uid'] ?? ''));
    if ($uid !== '' && hasUserPermission('listen_recordings')) {
        $cdr = getAsteriskPdoConnection('asteriskcdrdb'); $row = false;
        if ($cdr) {
            try { $st = $cdr->prepare("SELECT calldate, recordingfile FROM cdr WHERE uniqueid = :u AND recordingfile <> '' LIMIT 1"); $st->execute([':u' => $uid]); $row = $st->fetch(PDO::FETCH_ASSOC); } catch (Exception $e) {}
        }
        $path = $row ? findRecordingPath($row['recordingfile'], $row['calldate']) : '';
        if ($path === '') { $warns[] = 'Arquivo da gravação não encontrado no servidor; enviado apenas o link.'; }
        elseif (filesize($path) > 15 * 1024 * 1024) { $warns[] = 'Gravação maior que 15MB: enviado apenas o link.'; }
        else {
            $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
            $types = ['wav' => 'audio/wav', 'mp3' => 'audio/mpeg', 'ogg' => 'audio/ogg', 'gsm' => 'audio/x-gsm'];
            $atts[] = ['name' => 'gravacao_' . preg_replace('/[^0-9.]/', '', $uid) . '.' . $ext, 'type' => $types[$ext] ?? 'application/octet-stream', 'content' => file_get_contents($path)];
        }
    }
    return [$atts, $warns];
}

/** Provedor de envio WhatsApp ativo: 'zpro' (Prismabot/Z-PRO, padrão) ou 'uazapi'. */
function waProvider() {
    return getSetting('wa_provider') === 'uazapi' ? 'uazapi' : 'zpro';
}

/** Há credenciais completas para o provedor ativo? */
function whatsappProviderConfigured() {
    if (waProvider() === 'uazapi') {
        return trim((string)getSetting('uazapi_url')) !== '' && trim((string)getSetting('uazapi_token')) !== '';
    }
    return trim((string)getSetting('api_url')) !== '' && trim((string)getSetting('api_token')) !== '';
}

/** Chamada genérica à UAZAPI (header "token" = token da instância). */
function uazapiRequest($method, $path, $body = null, $timeout = 10) {
    $base  = rtrim(trim((string)getSetting('uazapi_url')), '/');
    $token = trim((string)getSetting('uazapi_token'));
    if ($base === '' || $token === '') {
        return ['http' => 0, 'resp' => '', 'error' => 'UAZAPI não configurada (URL do servidor ou token da instância ausente).'];
    }
    $ch = curl_init($base . $path);
    $opts = [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => $timeout,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_SSL_VERIFYHOST => false,
        CURLOPT_HTTPHEADER     => ['Content-Type: application/json', 'Accept: application/json', 'token: ' . $token],
    ];
    if (strtoupper($method) === 'POST') {
        $opts[CURLOPT_POST] = true;
        $opts[CURLOPT_POSTFIELDS] = json_encode($body ?? new stdClass(), JSON_UNESCAPED_UNICODE);
    }
    curl_setopt_array($ch, $opts);
    $resp = curl_exec($ch);
    $http = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err  = curl_error($ch);
    curl_close($ch);
    return ['http' => $http, 'resp' => (string)$resp, 'error' => $err ? ('cURL Error: ' . $err) : ''];
}

/** Envia texto pela UAZAPI. Retorna ['ok','http','resp','error']. */
function uazapiSendText($number, $text) {
    $num = preg_replace('/\D/', '', (string)$number);
    if (strlen($num) === 10 || strlen($num) === 11) $num = '55' . $num;
    if ($num === '' || trim((string)$text) === '') {
        return ['ok' => false, 'http' => 0, 'resp' => '', 'error' => 'Número ou mensagem vazios'];
    }
    $r = uazapiRequest('POST', '/send/text', ['number' => $num, 'text' => $text]);
    $ok = ($r['error'] === '' && $r['http'] >= 200 && $r['http'] < 300);
    $err = $r['error'];
    if (!$ok && $err === '') {
        $d = json_decode($r['resp'], true);
        $detail = is_array($d) ? ($d['error'] ?? $d['message'] ?? '') : '';
        $err = 'HTTP ' . $r['http'] . ($detail ? ': ' . (is_scalar($detail) ? $detail : json_encode($detail)) : '');
        if ($r['http'] === 401) $err .= ' (token da instância inválido)';
    }
    return ['ok' => $ok, 'http' => $r['http'], 'resp' => $r['resp'], 'error' => $err];
}

/**
 * Servidor de WhatsApp direto gerenciado pela Prisma: URL e token de administração ficam
 * FORA do código, em /etc/ipbx/uazapi.conf (linhas "url=..." e "admintoken=..."), legível só pelo servidor web.
 * Retorna ['url','admintoken'] ou null quando o arquivo não existe/está incompleto.
 */
function uazapiAdminConf() {
    static $c = false;
    if ($c !== false) return $c;
    $c = null;
    $f = '/etc/ipbx/uazapi.conf';
    if (is_readable($f)) {
        $kv = [];
        foreach ((array)@file($f, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $ln) {
            $ln = trim($ln);
            if ($ln === '' || $ln[0] === '#' || strpos($ln, '=') === false) continue;
            list($k, $v) = explode('=', $ln, 2);
            $kv[strtolower(trim($k))] = trim($v);
        }
        if (!empty($kv['url']) && !empty($kv['admintoken'])) {
            $c = ['url' => rtrim($kv['url'], '/'), 'admintoken' => $kv['admintoken']];
        }
    }
    return $c;
}

/** Modo de conexão do WhatsApp direto: 'prisma' (servidor da Prisma) ou 'own' (URL e token do próprio cliente). */
function uazapiMode() {
    if (getSetting('uazapi_mode') === 'own') return 'own';
    return uazapiAdminConf() ? 'prisma' : 'own';
}

/** Chamada com o token de administração do servidor da Prisma. */
function uazapiAdminRequest($method, $path, $body = null, $timeout = 20) {
    $c = uazapiAdminConf();
    if (!$c) return ['http' => 0, 'resp' => '', 'error' => 'Servidor da Prisma não disponível nesta central.'];
    $ch = curl_init($c['url'] . $path);
    $opts = [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => $timeout, CURLOPT_SSL_VERIFYPEER => false, CURLOPT_SSL_VERIFYHOST => false,
             CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'Accept: application/json', 'admintoken: ' . $c['admintoken']]];
    if (strtoupper($method) === 'POST') { $opts[CURLOPT_POST] = true; $opts[CURLOPT_POSTFIELDS] = json_encode($body ?? new stdClass(), JSON_UNESCAPED_UNICODE); }
    curl_setopt_array($ch, $opts);
    $resp = curl_exec($ch); $http = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE); $err = curl_error($ch); curl_close($ch);
    return ['http' => $http, 'resp' => (string)$resp, 'error' => $err ? ('cURL Error: ' . $err) : ''];
}

/** Garante a instância deste cliente no servidor da Prisma (cria uma vez e guarda o token). Retorna '' se ok ou a mensagem de erro. */
function uazapiEnsureInstance() {
    if (uazapiMode() !== 'prisma') return '';
    $c = uazapiAdminConf();
    if (!$c) return 'Servidor da Prisma não disponível nesta central.';
    if (trim((string)getSetting('uazapi_token')) !== '' && rtrim((string)getSetting('uazapi_url'), '/') === $c['url']) return '';
    $host = preg_replace('/[^a-z0-9]+/', '-', strtolower((string)(gethostname() ?: 'ipbx')));
    $name = 'ipbx-' . trim($host, '-') . '-' . substr(bin2hex(random_bytes(4)), 0, 6);
    $r = uazapiAdminRequest('POST', '/instance/create', ['name' => $name, 'systemName' => 'IPbx Prisma']);
    if ($r['error'] !== '') return $r['error'];
    $d = json_decode($r['resp'], true);
    $tok = is_array($d) ? (string)($d['token'] ?? ($d['instance']['token'] ?? '')) : '';
    if ($r['http'] < 200 || $r['http'] >= 300 || $tok === '') return 'Não foi possível criar a conexão (HTTP ' . $r['http'] . ').';
    saveSetting('uazapi_url', $c['url']);
    saveSetting('uazapi_token', $tok);
    saveSetting('uazapi_instance_name', $name);
    return '';
}

/** Endereço público deste painel (derivado da requisição; nada fixo no código). */
function panelPublicBaseUrl() {
    $https = !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off';
    $host  = (string)($_SERVER['HTTP_HOST'] ?? '');
    if ($host === '') return '';
    return ($https ? 'https://' : 'http://') . $host . rtrim(dirname($_SERVER['SCRIPT_NAME'] ?? '/front_api/index.php'), '/\\');
}

/** Registra no servidor o endereço que recebe as respostas dos clientes (NPS) e o estado da conexão. */
function uazapiConfigureWebhook() {
    $tok = getSetting('webhook_token');
    if (empty($tok)) { $tok = bin2hex(random_bytes(16)); saveSetting('webhook_token', $tok); }
    $base = panelPublicBaseUrl();
    if ($base === '') return ['ok' => false, 'error' => 'Não foi possível descobrir o endereço do painel.', 'url' => ''];
    $url = $base . '/index.php?api_action=whatsapp_webhook&token=' . $tok;
    $r = uazapiRequest('POST', '/webhook', ['enabled' => true, 'url' => $url, 'events' => ['messages', 'connection'], 'excludeMessages' => ['wasSentByApi', 'fromMeYes'], 'addUrlEvents' => false, 'addUrlTypesMessages' => false]);
    $ok = ($r['error'] === '' && $r['http'] >= 200 && $r['http'] < 300);
    // Endereço interno (rede privada/localhost): o servidor de WhatsApp na internet não consegue entregar as respostas
    $h = (string)parse_url($url, PHP_URL_HOST);
    $private = ($h === 'localhost' || strpos($h, '.') === false)
        || (filter_var($h, FILTER_VALIDATE_IP) && !filter_var($h, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE));
    return ['ok' => $ok, 'error' => $ok ? '' : ($r['error'] ?: 'HTTP ' . $r['http']), 'url' => $url, 'private' => $private];
}

/** Estado da conexão: ['state'=>connected|connecting|disconnected|error, 'qr','paircode','name','phone','error']. */
function uazapiConnectionInfo() {
    $r = uazapiRequest('GET', '/instance/status', null, 8);
    $out = ['state' => 'error', 'qr' => '', 'paircode' => '', 'name' => '', 'phone' => '', 'error' => '', 'http' => $r['http']];
    if ($r['error'] !== '') { $out['error'] = $r['error']; return $out; }
    if ($r['http'] === 401 || $r['http'] === 403) { $out['error'] = 'Token da conexão recusado.'; return $out; }
    $d = json_decode($r['resp'], true);
    if ($r['http'] < 200 || $r['http'] >= 300 || !is_array($d)) { $out['error'] = 'Servidor respondeu HTTP ' . $r['http'] . '.'; return $out; }
    $inst = is_array($d['instance'] ?? null) ? $d['instance'] : [];
    $st = strtolower((string)($inst['status'] ?? ''));
    if ($st === '' && is_array($d['status'] ?? null)) $st = !empty($d['status']['connected']) ? 'connected' : 'disconnected';
    if ($st === '' && is_string($d['status'] ?? null)) $st = strtolower($d['status']);
    $out['state']    = in_array($st, ['connected', 'connecting', 'disconnected'], true) ? $st : ($st === 'hibernated' ? 'disconnected' : 'error');
    $qr = (string)($inst['qrcode'] ?? '');
    if ($qr !== '') $out['qr'] = (strpos($qr, 'data:image') === 0) ? $qr : ('data:image/png;base64,' . $qr);
    $out['paircode'] = (string)($inst['paircode'] ?? '');
    $out['name']     = (string)($inst['profileName'] ?? '');
    $out['phone']    = preg_replace('/\D/', '', (string)($inst['owner'] ?? ''));
    if ($out['state'] === 'error') $out['error'] = 'Estado desconhecido (' . ($st ?: 'vazio') . ').';
    return $out;
}

/** Tipo de mídia da UAZAPI a partir do MIME: image, video, audio ou document. */
function uazapiMediaType($mime) {
    $m = strtolower((string)$mime);
    if (strpos($m, 'image/') === 0 && strpos($m, 'svg') === false) return 'image';
    if (strpos($m, 'video/') === 0) return 'video';
    if (strpos($m, 'audio/') === 0) return 'audio';
    return 'document';
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

    if (waProvider() === 'uazapi') {
        $u = uazapiSendText($phone, $message);
        $http_code    = $u['http'];
        $response_log = $u['resp'] !== '' ? $u['resp'] : json_encode(['error' => $u['error']]);
        $err_msg      = $u['error'];
        $status       = $u['ok'] ? 'SUCCESS' : 'ERROR';
    } elseif (empty($api_url) || empty($api_token)) {
        $err_msg = 'API Prismabot não configurada (URL ou Token ausente). Configure em Configurações > API.';
        $response_log = json_encode(['error' => $err_msg]);
    } else {
        $cleanToken = trim(preg_replace('/^bearer\s+/i', '', trim($api_token)));
        $cleanPhone = preg_replace('/\D/', '', $phone);
        if (strlen($cleanPhone) >= 10 && substr($cleanPhone, 0, 2) !== '55') {
            $cleanPhone = '55' . $cleanPhone;
        }

        $whatsappId = getSetting('prismabot_whatsapp_id');

        // Payload Z-PRO / ZDG / Prismabot
        $payload = [
            'number'         => $cleanPhone,
            'body'           => $message,
            'externalKey'    => 'PRISMA_' . time(),
            'isClosed'       => false,
            'validateNumber' => true,
            'options'        => ['delay' => 1200],
            'textMessage'    => ['text' => $message]
        ];

        if (!empty($whatsappId)) {
            $payload['whatsappId'] = is_numeric($whatsappId) ? intval($whatsappId) : $whatsappId;
            $payload['session']    = $whatsappId;
        }

        $ch = curl_init($api_url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Content-Type: application/json',
            'Authorization: Bearer ' . $cleanToken,
            'token: ' . $cleanToken,
            'apikey: ' . $cleanToken,
            'x-api-token: ' . $cleanToken
        ]);
        curl_setopt($ch, CURLOPT_TIMEOUT, 10);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);

        $result   = curl_exec($ch);
        $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curl_err  = curl_error($ch);
        curl_close($ch);

        if ($curl_err) {
            $err_msg      = 'cURL Error: ' . $curl_err;
            $response_log = json_encode(['error' => $err_msg]);
        } else {
            $response_log = $result;
            $decoded      = json_decode($result, true);

            // Verificar sucesso por status HTTP OU por campo "success" no JSON
            if ($http_code >= 200 && $http_code < 300) {
                if (isset($decoded['success']) && $decoded['success'] === false) {
                    $err_msg = isset($decoded['error']) ? $decoded['error'] : 'API retornou success=false';
                } else {
                    $status = 'SUCCESS';
                }
            } else {
                $api_err  = (isset($decoded['error']) ? $decoded['error'] : '');
                $err_msg  = 'HTTP ' . $http_code . ($api_err ? ': ' . $api_err : '');
            }
        }
    }

    $stmt = $db->prepare("INSERT INTO sent_logs (phone, call_id, extension, rule_type, message, status, response_raw) VALUES (:phone, :call_id, :ext, :rule, :msg, :status, :resp)");
    $stmt->execute([
        ':phone'   => $phone,
        ':call_id' => $call_id,
        ':ext'     => $extension,
        ':rule'    => $rule_type,
        ':msg'     => $message,
        ':status'  => $status,
        ':resp'    => $response_log
    ]);

    if (function_exists('pabx_log')) {
        pabx_log('whatsapp', $status === 'SUCCESS' ? 'INFO' : 'ERROR', "Envio API WhatsApp para {$phone} [Status: {$status}, HTTP {$http_code}]", [
            'rule_type' => $rule_type,
            'extension' => $extension,
            'call_id'   => $call_id,
            'error'     => $err_msg,
            'response'  => substr((string)$response_log, 0, 300)
        ]);
    }
    return [
        'success'   => ($status === 'SUCCESS'),
        'error'     => $err_msg,
        'http_code' => $http_code,
        'raw'       => $response_log
    ];
}

function resolveAsteriskDestLabel($dest, $ast_db = null) {
    if (empty($dest)) return 'Desconectar / Fim';

    if (!$ast_db) {
        $ast_db = getAsteriskDBConnection();
        if (!$ast_db) $ast_db = getAsteriskPdoConnection('asterisk');
    }

    if (strpos($dest, 'timeconditions') === 0 || strpos($dest, 'timecondition') !== false) {
        preg_match('/(?:timeconditions|timecondition)[,\s_-]*(\d+)/i', $dest, $m);
        $tc_id = $m[1] ?? '';
        if (!$tc_id) {
            $parts = explode(',', $dest);
            foreach ($parts as $p) {
                $p_clean = trim(preg_replace('/[^0-9]/', '', $p));
                if ($p_clean !== '') { $tc_id = $p_clean; break; }
            }
        }
        if ($tc_id && $ast_db) {
            try {
                $st = $ast_db->prepare("SELECT * FROM timeconditions WHERE timecondition_id = :id OR id = :id LIMIT 1");
                $st->execute([':id' => $tc_id]);
                $r = $st->fetch(PDO::FETCH_ASSOC);
                $name = $r['displayname'] ?? ($r['name'] ?? '');
                if (!empty($name)) return "Condição de Horário: $name";
            } catch (Exception $e) {}
        }
        return "Condição de Horário" . ($tc_id ? " #$tc_id" : "");
    }

    if (strpos($dest, 'ext-queues') === 0) {
        preg_match('/ext-queues[,\s_-]*(\d+)/i', $dest, $m);
        $q_num = $m[1] ?? '';
        if (!$q_num) {
            $parts = explode(',', $dest);
            foreach ($parts as $p) {
                $p_clean = trim(preg_replace('/[^0-9]/', '', $p));
                if ($p_clean !== '') { $q_num = $p_clean; break; }
            }
        }
        if ($q_num && $ast_db) {
            try {
                $st = $ast_db->prepare("SELECT descr FROM queues_config WHERE extension = :ext LIMIT 1");
                $st->execute([':ext' => $q_num]);
                $r = $st->fetch(PDO::FETCH_ASSOC);
                $q_name = trim((string)($r['descr'] ?? ''));
                if ($q_name) return "Fila #$q_num ($q_name)";
            } catch (Exception $e) {}
        }
        return "Fila de Atendimento" . ($q_num ? " #$q_num" : "");
    }

    if (strpos($dest, 'app-announcement') === 0 || strpos($dest, 'announcements') === 0 || strpos($dest, 'announcement') !== false) {
        preg_match('/(?:announcement|announcements)[,\s_-]*(\d+)/i', $dest, $m);
        $ann_id = $m[1] ?? '';
        if (!$ann_id) {
            $parts = explode(',', $dest);
            foreach ($parts as $p) {
                $p_clean = trim(preg_replace('/[^0-9]/', '', $p));
                if ($p_clean !== '') { $ann_id = $p_clean; break; }
            }
        }
        if ($ann_id && $ast_db) {
            try {
                $st = $ast_db->prepare("SELECT * FROM announcement WHERE announcement_id = :id LIMIT 1");
                $st->execute([':id' => $ann_id]);
                $r = $st->fetch(PDO::FETCH_ASSOC);
                $ann_desc = $r['description'] ?? ($r['name'] ?? '');
                if (!empty($ann_desc)) return "Anúncio: $ann_desc";
            } catch (Exception $e) {}
        }
        return "Anúncio de Voz" . ($ann_id ? " #$ann_id" : "");
    }

    if (strpos($dest, 'ext-group') === 0 || strpos($dest, 'ringgroups') === 0) {
        preg_match('/(?:ext-group|ringgroups)[,\s_-]*(\d+)/i', $dest, $m);
        $rg_num = $m[1] ?? '';
        if (!$rg_num) {
            $parts = explode(',', $dest);
            foreach ($parts as $p) {
                $p_clean = trim(preg_replace('/[^0-9]/', '', $p));
                if ($p_clean !== '') { $rg_num = $p_clean; break; }
            }
        }
        if ($rg_num && $ast_db) {
            try {
                $st = $ast_db->prepare("SELECT * FROM ringgroups WHERE grpnum = :num LIMIT 1");
                $st->execute([':num' => $rg_num]);
                $r = $st->fetch(PDO::FETCH_ASSOC);
                $rg_desc = $r['description'] ?? ($r['name'] ?? '');
                if (!empty($rg_desc)) return "Grupo de Ramais: $rg_desc (#$rg_num)";
            } catch (Exception $e) {}
        }
        return "Grupo de Ramais" . ($rg_num ? " #$rg_num" : "");
    }

    if (strpos($dest, 'ivr') === 0) {
        preg_match('/ivr[,\s_-]*(\d+)/i', $dest, $m);
        $ivr_id = $m[1] ?? '';
        if (!$ivr_id) {
            $parts = explode(',', $dest);
            foreach ($parts as $p) {
                $p_clean = trim(preg_replace('/[^0-9]/', '', $p));
                if ($p_clean !== '') { $ivr_id = $p_clean; break; }
            }
        }
        if ($ivr_id && $ast_db) {
            try {
                $st = $ast_db->prepare("SELECT * FROM ivr_details WHERE ivr_id = :id OR id = :id LIMIT 1");
                $st->execute([':id' => $ivr_id]);
                $r = $st->fetch(PDO::FETCH_ASSOC);
                $ivr_name = $r['name'] ?? ($r['description'] ?? '');
                if (!empty($ivr_name)) return "Menu URA: $ivr_name";
            } catch (Exception $e) {}
        }
        return "Menu URA" . ($ivr_id ? " #$ivr_id" : "");
    }

    if (preg_match('/^ext-local,vm[bus]?(\d+)/i', $dest, $m_vml)) {
        return "Caixa Postal (Voicemail) #" . $m_vml[1];
    }

    if (strpos($dest, 'from-did-direct') === 0 || strpos($dest, 'ext-local') === 0) {
        $ext = preg_replace('/\D/', '', explode(',', $dest)[1] ?? '');
        return "Ramal Direto #$ext";
    }

    if (strpos($dest, 'ext-voicemail') === 0 || strpos($dest, 'voicemail') !== false) {
        $ext = preg_replace('/\D/', '', explode(',', $dest)[1] ?? '');
        return "Caixa Postal (Voicemail)" . ($ext ? " #$ext" : "");
    }

    if (strpos($dest, 'app-blackhole') === 0 || strpos($dest, 'hangup') !== false) {
        $bh = strtolower(trim(explode(',', $dest)[1] ?? 'hangup'));
        $bh_map = ['hangup' => 'Desconectar / Fim', 'busy' => 'Sinal de Ocupado', 'congestion' => 'Sinal de Congestionamento', 'ring' => 'Tocar até desistir', 'musiconhold' => 'Música de Espera'];
        return $bh_map[$bh] ?? "Terminar chamada ($bh)";
    }

    $dp = explode(',', $dest);
    $dnum = preg_replace('/\D/', '', $dp[1] ?? '');
    if (strpos($dest, 'app-daynight') === 0) {
        $dn_name = '';
        if ($ast_db && $dnum !== '') {
            try {
                $st = $ast_db->prepare("SELECT dest FROM daynight WHERE ext = :e AND dmode = 'fc_description' LIMIT 1");
                $st->execute([':e' => $dnum]);
                $dn_name = trim((string)$st->fetchColumn());
            } catch (Exception $e) {}
        }
        return "Dia/Noite (Night Mode) #$dnum" . ($dn_name !== '' ? " ($dn_name)" : '');
    }
    if (strpos($dest, 'ext-miscdests') === 0) {
        $md_name = '';
        if ($ast_db && $dnum !== '') {
            try {
                $st = $ast_db->prepare("SELECT description FROM miscdests WHERE id = :i LIMIT 1");
                $st->execute([':i' => $dnum]);
                $md_name = trim((string)$st->fetchColumn());
            } catch (Exception $e) {}
        }
        return "Destino Personalizado" . ($md_name !== '' ? ": $md_name" : " #$dnum");
    }
    if (strpos($dest, 'ext-meetme') === 0 || strpos($dest, 'conferences') === 0) return "Sala de Conferência #$dnum";
    if (strpos($dest, 'app-pbdirectory') === 0) return "Diretório por Nome";
    if (strpos($dest, 'ext-trunk') === 0) return "Saída por Tronco #$dnum";

    return "Destino do dialplan: " . $dest;
}

/**
 * Obtém todos os detalhes e parâmetros de configuração de uma Fila de Atendimento do Asterisk
 */
function getAsteriskQueueFullDetails($q_num, $ast_db = null) {
    if (!$ast_db) {
        $ast_db = getAsteriskDBConnection();
        if (!$ast_db) {
            $ast_db = getAsteriskPdoConnection('asterisk');
        }
    }

    $details = [
        'queue_num' => $q_num,
        'queue_name' => "Fila $q_num",
        'strategy' => 'ringall',
        'strategy_label' => '—',
        'max_wait_time' => '120',
        'max_wait_time_label' => '—',
        'max_wait_mode' => '—',
        'agent_timeout' => '—',
        'agent_timeout_restart' => 'Não',
        'retry' => '—',
        'wrap_up_time' => '—',
        'member_delay' => '0 segundos',
        'agent_announcement' => 'Nenhum',
        'report_hold_time' => 'Não',
        'auto_pause' => 'Não',
        'auto_pause_busy' => 'Sim',
        'auto_pause_unavail' => 'Sim',
        'auto_pause_delay' => '0s',
        'max_callers' => '—',
        'join_empty' => '—',
        'leave_empty' => '—',
        'penalty_members_limit' => 'Honor Penalties',
        'announce_frequency' => '—',
        'announce_position' => 'Sim',
        'announce_hold_time' => 'Sim',
        'ivr_breakout' => 'Nenhum',
        'repeat_frequency' => '0s',
        'event_when_called' => 'Sim',
        'member_status_event' => 'Sim',
        'service_level' => '1 minute (60s)',
        'recording' => '—',
        'recording_mode' => '—',
        'caller_vol' => 'Sem Ajuste',
        'agent_vol' => 'Sem Ajuste',
        'mark_answered_elsewhere' => 'Sim',
        'members' => [],
        'failover_dest' => '',
        'failover_label' => 'Não configurado'
    ];

    $ext_names = getExtensionsMap();
    $raw_kv = [];
    $cfg_found = false;

    if ($ast_db) {
        // queues_config: UMA linha por fila (descr, maxwait, dest = destino de transbordo)
        try {
            $stmt = $ast_db->prepare("SELECT * FROM queues_config WHERE extension = :ext LIMIT 1");
            $stmt->execute([':ext' => $q_num]);
            $cfg = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($cfg) {
                $cfg_found = true;
                if (!empty($cfg['descr'])) $raw_kv['description'] = trim($cfg['descr']);
                $raw_kv['maxwait'] = trim((string)($cfg['maxwait'] ?? ''));
                if (!empty($cfg['dest'])) $raw_kv['goto'] = trim($cfg['dest']);
                if (!empty($cfg['monitor_type'])) $raw_kv['__monitor_type'] = trim($cfg['monitor_type']);
            }
        } catch (Exception $e) {}

        // Query queues_details fallback
        try {
            $stmt2 = $ast_db->prepare("SELECT keyword, data FROM queues_details WHERE (id = :ext OR id = :ext_q)");
            $stmt2->execute([':ext' => $q_num, ':ext_q' => "queue_$q_num"]);
            while ($row = $stmt2->fetch(PDO::FETCH_ASSOC)) {
                $kw = strtolower(trim($row['keyword']));
                $val = trim($row['data']);
                if (in_array($kw, ['member', 'members', 'static'])) {
                    if (!isset($raw_kv['members'])) $raw_kv['members'] = [];
                    $raw_kv['members'][] = $val;
                } elseif (!isset($raw_kv[$kw]) || $raw_kv[$kw] === '') {
                    $raw_kv[$kw] = $val;
                }
            }
        } catch (Exception $e) {}
    }

    // Name
    if (!empty($raw_kv['description'])) $details['queue_name'] = $raw_kv['description'];
    elseif (!empty($raw_kv['displayname'])) $details['queue_name'] = $raw_kv['displayname'];
    elseif (!empty($raw_kv['descr'])) $details['queue_name'] = $raw_kv['descr'];

    // Strategy
    $strat_map = [
        'ringall' => 'Ringall (Tocar Todos Simultaneamente)',
        'leastrecent' => 'Least Recent (Menos Recente)',
        'fewestcalls' => 'Fewest Calls (Menos Chamadas Atendidas)',
        'random' => 'Random (Aleatório)',
        'rrmemory' => 'Round Robin Memory (Cíclica com Memória)',
        'rr-ordered' => 'Round Robin Ordered (Cíclica Ordenada)',
        'linear' => 'Linear (Sequencial Fixa)',
        'wrandom' => 'Weighted Random (Ponderado por Peso)'
    ];
    if (!empty($raw_kv['strategy'])) {
        $st = strtolower($raw_kv['strategy']);
        $details['strategy'] = $st;
        $details['strategy_label'] = $strat_map[$st] ?? ucfirst($st);
    }

    // Max wait time
    $maxwait = $raw_kv['maxwait'] ?? null;
    if ($cfg_found && $maxwait === '') $maxwait = '0'; // vazio = sem limite
    if ($maxwait !== null && is_numeric($maxwait)) {
        $sec = intval($maxwait);
        if ($sec == 0) $details['max_wait_time_label'] = 'Sem Limite (Ilimitado)';
        elseif ($sec < 60) $details['max_wait_time_label'] = "{$sec} segundos";
        else {
            $m = floor($sec / 60);
            $s = $sec % 60;
            $details['max_wait_time_label'] = "{$m} minuto" . ($m > 1 ? 's' : '') . ($s > 0 ? " e {$s}s" : '');
        }
        $details['max_wait_time'] = $maxwait;
    }

    if (!empty($raw_kv['maxwaitmode'])) $details['max_wait_mode'] = ucfirst($raw_kv['maxwaitmode']);
    if (!empty($raw_kv['monitor-format'])) {
        $details['recording'] = $raw_kv['monitor-format'];
    } elseif (!empty($raw_kv['__monitor_type'])) {
        $details['recording'] = $raw_kv['__monitor_type'];
    }
    if (!empty($raw_kv['monitor-join'])) $details['recording_mode'] = (strtolower($raw_kv['monitor-join']) === 'yes') ? 'Junta as pernas da chamada' : 'Pernas separadas';

    // Agent timeout & retry & wrapup
    if (isset($raw_kv['agenttimeout']) || isset($raw_kv['timeout'])) {
        $t = $raw_kv['agenttimeout'] ?? $raw_kv['timeout'];
        $details['agent_timeout'] = ($t == '0' || $t == '') ? 'Ilimitado' : "{$t} segundos";
    }
    if (isset($raw_kv['retry'])) {
        $r = $raw_kv['retry'];
        $details['retry'] = ($r == '0' || $r == '') ? '0 segundos' : "{$r} segundos";
    }
    if (isset($raw_kv['wrapuptime'])) {
        $w = $raw_kv['wrapuptime'];
        $details['wrap_up_time'] = ($w == '0' || $w == '') ? '0 segundos' : "{$w} segundos";
    }

    // Capacity & Announcements
    if (isset($raw_kv['maxlen'])) {
        $ml = $raw_kv['maxlen'];
        $details['max_callers'] = ($ml == '0' || $ml == '') ? 'Sem Limite (No Max)' : "{$ml} chamadores";
    }
    if (isset($raw_kv['joinempty'])) $details['join_empty'] = ucfirst($raw_kv['joinempty']);
    if (isset($raw_kv['leaveempty'])) $details['leave_empty'] = ucfirst($raw_kv['leaveempty']);

    if (isset($raw_kv['announce-frequency'])) {
        $af = $raw_kv['announce-frequency'];
        $details['announce_frequency'] = ($af == '0' || $af == '') ? 'Desativado' : "A cada {$af} segundos";
    }
    if (isset($raw_kv['announce-position'])) $details['announce_position'] = ucfirst($raw_kv['announce-position']);
    if (isset($raw_kv['announce-holdtime'])) $details['announce_hold_time'] = ucfirst($raw_kv['announce-holdtime']);
    if (isset($raw_kv['servicelevel'])) $details['service_level'] = "{$raw_kv['servicelevel']} segundos";

    // Auto pause
    if (isset($raw_kv['autopause'])) $details['auto_pause'] = ucfirst($raw_kv['autopause']);
    if (isset($raw_kv['autopausebusy'])) $details['auto_pause_busy'] = ucfirst($raw_kv['autopausebusy']);
    if (isset($raw_kv['autopauseunavail'])) $details['auto_pause_unavail'] = ucfirst($raw_kv['autopauseunavail']);

    // Members list with penalty resolution (ex: 200,0; 204,0; 201,1)
    $members_parsed = [];
    if (!empty($raw_kv['members'])) {
        foreach (array_unique($raw_kv['members']) as $m_str) {
            $lines = preg_split('/[\r\n]+/', $m_str);
            foreach ($lines as $line) {
                $line = trim($line);
                if (empty($line)) continue;

                $penalty = 0;
                $ext = '';
                if (preg_match('/(?:Local\/|SIP\/|PJSIP\/)?(\d{3,4})[^,]*,\s*(\d+)/i', $line, $m_match)) {
                    $ext = $m_match[1];
                    $penalty = intval($m_match[2]);
                } elseif (preg_match('/(\d{3,4})/', $line, $m_match)) {
                    $ext = $m_match[1];
                }

                if ($ext && !isset($members_parsed[$ext])) {
                    $name = $ext_names[$ext] ?? "Ramal $ext";
                    $members_parsed[$ext] = [
                        'extension' => $ext,
                        'name' => $name,
                        'penalty' => $penalty,
                        'penalty_label' => ($penalty === 0 ? 'Principal (Penalidade 0)' : "Transbordo (Penalidade $penalty)")
                    ];
                }
            }
        }
    }

    $details['members'] = array_values($members_parsed);

    // Failover Destination (goto / dest / failover)
    $failover = $raw_kv['goto'] ?? ($raw_kv['dest'] ?? ($raw_kv['failover'] ?? ''));
    if ($failover) {
        $details['failover_dest'] = $failover;
        $details['failover_label'] = resolveAsteriskDestLabel($failover, $ast_db);
    }
    $details['config_found'] = $cfg_found;

    return $details;
}

/**
 * Busca uma linha de configuração no MySQL do Asterisk de forma segura sem cláusula WHERE engessada
 */
function fetchAsteriskConfigRow($table, $id_value, $possible_id_cols = [], $ast_db = null) {
    if (empty($id_value)) return null;
    if (!$ast_db) {
        $ast_db = getAsteriskDBConnection();
        if (!$ast_db) $ast_db = getAsteriskPdoConnection('asterisk');
    }
    if (!$ast_db) return null;

    if (empty($possible_id_cols)) {
        $possible_id_cols = ['id', 'timecondition_id', 'timegroupid', 'announcement_id', 'grpnum', 'ivr_id', 'extension'];
    }

    try {
        $stmt = $ast_db->query("SELECT * FROM `$table`");
        if ($stmt) {
            while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                foreach ($possible_id_cols as $col) {
                    if (isset($row[$col]) && (string)$row[$col] === (string)$id_value) {
                        return $row;
                    }
                }
            }
        }
    } catch (Exception $e) {}

    return null;
}

/**
 * Busca múltiplas linhas de configuração no MySQL do Asterisk filtrando em PHP
 */
function fetchAsteriskConfigRows($table, $id_value = null, $possible_id_cols = [], $ast_db = null) {
    if (!$ast_db) {
        $ast_db = getAsteriskDBConnection();
        if (!$ast_db) $ast_db = getAsteriskPdoConnection('asterisk');
    }
    $results = [];
    if (!$ast_db) return $results;

    if (empty($possible_id_cols)) {
        $possible_id_cols = ['id', 'timegroupid', 'ivr_id', 'grpnum', 'extension'];
    }

    try {
        $stmt = $ast_db->query("SELECT * FROM `$table`");
        if ($stmt) {
            while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                if ($id_value === null || $id_value === '') {
                    $results[] = $row;
                } else {
                    foreach ($possible_id_cols as $col) {
                        if (isset($row[$col]) && (string)$row[$col] === (string)$id_value) {
                            $results[] = $row;
                            break;
                        }
                    }
                }
            }
        }
    } catch (Exception $e) {}

    return $results;
}



/**
 * Formata os detalhes e horários de um Time Group do Asterisk MySQL
 */
function formatAsteriskTimeGroupDetails($tg_id, $ast_db = null) {
    if (!$tg_id) {
        return [
            'id' => '',
            'name' => 'Horário PABX',
            'rules' => ['Horário Padrão do Sistema'],
            'summary' => 'Horário Padrão'
        ];
    }

    if (!$ast_db) {
        $ast_db = getAsteriskDBConnection();
        if (!$ast_db) $ast_db = getAsteriskPdoConnection('asterisk');
    }

    $group_name = "Time Group #$tg_id";
    $raw_rules = [];

    if ($ast_db) {
        // Buscar nome do grupo
        $tg_row = fetchAsteriskConfigRow('timegroups_groups', $tg_id, ['timegroupid', 'id', 'timegroups_id'], $ast_db);
        if ($tg_row) {
            if (!empty($tg_row['description'])) {
                $group_name = $tg_row['description'];
            } elseif (!empty($tg_row['displayname'])) {
                $group_name = $tg_row['displayname'];
            } elseif (!empty($tg_row['name'])) {
                $group_name = $tg_row['name'];
            }
        }

        // Buscar regras de horário na tabela timegroups_details
        $det_rows = fetchAsteriskConfigRows('timegroups_details', $tg_id, ['timegroupid', 'id', 'timegroups_id'], $ast_db);
        foreach ($det_rows as $r_det) {
            $rule_str = $r_det['time'] ?? ($r_det['times'] ?? ($r_det['detail'] ?? ($r_det['rule'] ?? '')));
            if (!empty($rule_str)) {
                $raw_rules[] = trim($rule_str);
            }
        }
    }

    $formatted_rules = [];
    $days_map = [
        'mon' => 'Segunda', 'tue' => 'Terça', 'wed' => 'Quarta',
        'thu' => 'Quinta', 'fri' => 'Sexta', 'sat' => 'Sábado', 'sun' => 'Domingo'
    ];
    $months_map = [
        'jan' => 'Janeiro', 'feb' => 'Fevereiro', 'mar' => 'Março', 'apr' => 'Abril',
        'may' => 'Maio', 'jun' => 'Junho', 'jul' => 'Julho', 'aug' => 'Agosto',
        'sep' => 'Setembro', 'oct' => 'Outubro', 'nov' => 'Novembro', 'dec' => 'Dezembro'
    ];

    foreach ($raw_rules as $rule) {
        $parts = explode('|', $rule);
        $t_range = trim($parts[0] ?? '*');
        $w_days  = trim($parts[1] ?? '*');
        $m_days  = trim($parts[2] ?? '*');
        $months  = trim($parts[3] ?? '*');

        $time_str = ($t_range === '*' || empty($t_range)) ? "Dia Inteiro (24h)" : "das " . str_replace('-', ' às ', $t_range);

        $days_str = "Todos os dias";
        if ($w_days !== '*' && !empty($w_days)) {
            if (strpos($w_days, '-') !== false) {
                list($d1, $d2) = explode('-', $w_days);
                $days_str = ($days_map[$d1] ?? $d1) . " a " . ($days_map[$d2] ?? $d2);
            } else {
                $days_arr = explode(',', $w_days);
                $translated = array_map(function($d) use ($days_map) { return $days_map[$d] ?? $d; }, $days_arr);
                $days_str = implode(', ', $translated);
            }
        }

        $date_str = "";
        if ($m_days !== '*' && !empty($m_days)) {
            $date_str .= "Dia $m_days";
        }
        if ($months !== '*' && !empty($months)) {
            $m_translated = $months_map[$months] ?? $months;
            $date_str .= ($date_str ? " de " : "") . $m_translated;
        }

        if ($date_str) {
            $formatted_rules[] = "📅 Data Específica: $date_str ($time_str)";
        } else {
            $formatted_rules[] = "⏰ $days_str: $time_str";
        }
    }

    if (empty($formatted_rules)) {
        $formatted_rules[] = "Nenhuma regra de horário cadastrada neste grupo (a condição nunca é verdadeira)";
    }

    return [
        'id' => $tg_id,
        'name' => $group_name,
        'rules' => $formatted_rules,
        'summary' => implode(' | ', $formatted_rules)
    ];
}

/**
 * Resolve recursivamente a árvore dinâmica de destinos do PABX a partir da string 'set destination'
 */
function resolveAsteriskDestinationTree($dest, $ast_db = null, $ext_names = [], $depth = 0) {
    if (!$dest || $depth > 6) {
        return [
            'type' => 'hangup',
            'title' => 'Encerrar Chamada (Hangup / Desconectar)',
            'desc' => 'Conexão encerrada pelo PABX',
            'badge' => 'Fim de Chamada',
            'color' => 'rose'
        ];
    }

    if (!$ast_db) {
        $ast_db = getAsteriskDBConnection();
        if (!$ast_db) {
            $ast_db = getAsteriskPdoConnection('asterisk');
        }
    }
    if (empty($ext_names)) {
        $ext_names = getExtensionsMap();
    }

    // 1. Condição de Horário (Time Condition)
    if (strpos($dest, 'timeconditions') === 0 || strpos($dest, 'timecondition') !== false) {
        preg_match('/(?:timeconditions|timecondition)[,\s_-]*(\d+)/i', $dest, $m_tc);
        $tc_id = $m_tc[1] ?? '';
        if (!$tc_id) {
            $parts = explode(',', $dest);
            foreach ($parts as $p) {
                $p_clean = trim(preg_replace('/[^0-9]/', '', $p));
                if ($p_clean !== '') { $tc_id = $p_clean; break; }
            }
        }

        $tc_info = fetchAsteriskConfigRow('timeconditions', $tc_id, ['timecondition_id', 'id', 'timeconditions_id'], $ast_db);

        $tc_name = (!empty($tc_info['displayname'])) ? $tc_info['displayname'] : ((!empty($tc_info['name'])) ? $tc_info['name'] : ((!empty($tc_info['description'])) ? $tc_info['description'] : ($tc_id ? "Time Condition #$tc_id" : "Validação de Horário")));
        
        $tg_id = $tc_info['time'] ?? ($tc_info['timegroupid'] ?? ($tc_info['time_group_id'] ?? ''));
        
        $timegroup_details = formatAsteriskTimeGroupDetails($tg_id, $ast_db);

        $truegoto = $tc_info['truegoto'] ?? ($tc_info['true_goto'] ?? ($tc_info['truedestination'] ?? ''));
        $falsegoto = $tc_info['falsegoto'] ?? ($tc_info['false_goto'] ?? ($tc_info['falsedestination'] ?? ''));

        return [
            'type' => 'timecondition',
            'id' => $tc_id,
            'title' => "Condição de Horário: $tc_name",
            'desc' => "Time Group: {$timegroup_details['name']}",
            'badge' => 'Decisão Condicional (IF/ELSE)',
            'color' => 'amber',
            'timegroup' => $timegroup_details,
            'truegoto' => $truegoto,
            'true_label' => resolveAsteriskDestLabel($truegoto, $ast_db),
            'true_node' => resolveAsteriskDestinationTree($truegoto, $ast_db, $ext_names, $depth + 1),
            'falsegoto' => $falsegoto,
            'false_label' => resolveAsteriskDestLabel($falsegoto, $ast_db),
            'false_node' => resolveAsteriskDestinationTree($falsegoto, $ast_db, $ext_names, $depth + 1)
        ];
    }

    // 1.5. Grupo de Chamada (Ring Group)
    if (strpos($dest, 'ext-group') === 0 || strpos($dest, 'ext-findmefollow') === 0 || strpos($dest, 'ringgroups') === 0) {
        preg_match('/(?:ext-group|ext-findmefollow|ringgroups)[,\s_-]*(\d+)/i', $dest, $m_rg);
        $rg_num = $m_rg[1] ?? '';
        if (!$rg_num) {
            $parts = explode(',', $dest);
            $rg_num = isset($parts[1]) ? preg_replace('/\D/', '', $parts[1]) : preg_replace('/\D/', '', $parts[0]);
        }

        $rg_title = "Grupo de Ramais #$rg_num";
        $rg_list = [];
        $rg_strategy = 'ringall';
        $rg_time = '20';
        $postdest = '';

        if ($ast_db && $rg_num) {
            $rg_row = fetchAsteriskConfigRow('ringgroups', $rg_num, ['grpnum', 'id', 'ringgroup_id'], $ast_db);
            if ($rg_row) {
                if (!empty($rg_row['description'])) $rg_title = $rg_row['description'];
                elseif (!empty($rg_row['name'])) $rg_title = $rg_row['name'];
                if (!empty($rg_row['strategy'])) $rg_strategy = $rg_row['strategy'];
                if (!empty($rg_row['grptime'])) $rg_time = $rg_row['grptime'];
                if (!empty($rg_row['postdest'])) $postdest = $rg_row['postdest'];

                if (!empty($rg_row['grplist'])) {
                    $raw_exts = preg_split('/[-,\s]+/', trim($rg_row['grplist']));
                    foreach ($raw_exts as $e_item) {
                        $e_item = trim($e_item);
                        if (!empty($e_item)) {
                            $rg_list[] = [
                                'extension' => $e_item,
                                'name' => $ext_names[$e_item] ?? "Ramal $e_item"
                            ];
                        }
                    }
                }
            }
        }

        $strategy_labels = [
            'ringall' => 'Tocar Todos os Ramais Simultaneamente',
            'hunt' => 'Tocar Sequencialmente (Caça)',
            'memoryhunt' => 'Tocar Sequencialmente com Memória',
            'firstavailable' => 'Tocar no Primeiro Ramal Disponível',
            'firstnotonphone' => 'Tocar no Primeiro Ramal Desocupado'
        ];

        return [
            'type' => 'ringgroup',
            'group_num' => $rg_num,
            'title' => "Grupo de Ramais (Ring Group): $rg_title (#$rg_num)",
            'desc' => "Estratégia: " . ($strategy_labels[$rg_strategy] ?? $rg_strategy) . " | Tempo de Toque: {$rg_time}s",
            'badge' => 'Grupo de Chamada',
            'color' => 'indigo',
            'strategy_label' => $strategy_labels[$rg_strategy] ?? $rg_strategy,
            'ring_time' => $rg_time,
            'members' => $rg_list,
            'postdest' => $postdest,
            'failover_node' => !empty($postdest) ? resolveAsteriskDestinationTree($postdest, $ast_db, $ext_names, $depth + 1) : null
        ];
    }

    // 2. Fila de Atendimento (Queue)
    if (strpos($dest, 'ext-queues') === 0) {
        preg_match('/ext-queues[,\s_-]*(\d+)/i', $dest, $m_q);
        $q_num = $m_q[1] ?? preg_replace('/\D/', '', explode(',', $dest)[1] ?? '');
        
        $q_details = getAsteriskQueueFullDetails($q_num, $ast_db);
        $failover_dest = $q_details['failover_dest'] ?? '';

        return [
            'type' => 'queue',
            'queue_num' => $q_num,
            'title' => "Fila de Atendimento: {$q_details['queue_name']} (#$q_num)",
            'desc' => "Distribuição de chamadas | Gravação: " . ($q_details['recording'] ?? 'Ativa'),
            'badge' => 'Fila de Espera',
            'color' => 'purple',
            'details' => $q_details,
            'failover_dest' => $failover_dest,
            'failover_node' => !empty($failover_dest) ? resolveAsteriskDestinationTree($failover_dest, $ast_db, $ext_names, $depth + 1) : null
        ];
    }

    // 3. Anúncio de Voz (Announcement)
    if (strpos($dest, 'app-announcement') === 0 || strpos($dest, 'announcements') === 0 || strpos($dest, 'announcement') !== false) {
        preg_match('/(?:announcement|announcements)[,\s_-]*(\d+)/i', $dest, $m_ann);
        $ann_id = $m_ann[1] ?? '';
        if (!$ann_id) {
            $parts = explode(',', $dest);
            $ann_id = isset($parts[1]) ? preg_replace('/\D/', '', $parts[1]) : preg_replace('/\D/', '', $parts[0]);
        }

        $ann_name = $ann_id ? "Anúncio #$ann_id" : "Anúncio de Voz";
        $audio_file = "";
        $next_dest = "";

        if ($ast_db && $ann_id) {
            $ann_row = fetchAsteriskConfigRow('announcement', $ann_id, ['announcement_id'], $ast_db);
            if ($ann_row) {
                if (!empty($ann_row['description'])) $ann_name = $ann_row['description'];
                elseif (!empty($ann_row['name'])) $ann_name = $ann_row['name'];
                if (!empty($ann_row['post_dest'])) $next_dest = $ann_row['post_dest'];
                elseif (!empty($ann_row['destination'])) $next_dest = $ann_row['destination'];
                
                $rec_id = $ann_row['recording_id'] ?? ($ann_row['recording'] ?? '');
                if (!empty($rec_id)) {
                    $rec_row = fetchAsteriskConfigRow('recordings', $rec_id, ['id', 'recording_id'], $ast_db);
                    if ($rec_row) {
                        if (!empty($rec_row['filename'])) $audio_file = $rec_row['filename'];
                        elseif (!empty($rec_row['displayname'])) $audio_file = $rec_row['displayname'];
                    }
                }
            }
        }

        return [
            'type' => 'announcement',
            'id' => $ann_id,
            'title' => "Anúncio de Voz: $ann_name",
            'desc' => 'Áudio reprodutor automático PABX',
            'badge' => 'Mensagem de Áudio',
            'color' => 'emerald',
            'audio_file' => $audio_file,
            'next_dest' => $next_dest,
            'next_node' => !empty($next_dest) ? resolveAsteriskDestinationTree($next_dest, $ast_db, $ext_names, $depth + 1) : null
        ];
    }

    // 4. URA / IVR
    if (strpos($dest, 'ivr') === 0) {
        preg_match('/ivr[,\s_-]*(\d+)/i', $dest, $m_ivr);
        $ivr_id = $m_ivr[1] ?? '';
        if (!$ivr_id) {
            $parts = explode(',', $dest);
            $ivr_id = isset($parts[1]) ? preg_replace('/\D/', '', $parts[1]) : preg_replace('/\D/', '', $parts[0]);
        }

        $ivr_title = $ivr_id ? "URA #$ivr_id" : "Menu de Opções URA";
        $entries = [];
        $audio_file = "";
        $timeout = "5s";
        $invalid_loops = "3";
        $directdial = "Desativada";
        $invalid_dest = "";
        $timeout_dest = "";

        if ($ast_db && $ivr_id) {
            $ivr_row = fetchAsteriskConfigRow('ivr_details', $ivr_id, ['ivr_id', 'id'], $ast_db);
            if ($ivr_row) {
                if (!empty($ivr_row['name'])) $ivr_title = $ivr_row['name'];
                elseif (!empty($ivr_row['description'])) $ivr_title = $ivr_row['description'];

                $ivr_to = $ivr_row['timeout_time'] ?? ($ivr_row['timeout'] ?? '');
                if ($ivr_to !== '' && $ivr_to !== null) {
                    $timeout = $ivr_to . "s";
                } else {
                    $timeout = '—';
                }
                if (isset($ivr_row['invalid_loops']) && $ivr_row['invalid_loops'] !== '') {
                    $invalid_loops = $ivr_row['invalid_loops'] . "x";
                }
                if (!empty($ivr_row['directdial']) && strtolower($ivr_row['directdial']) !== 'disabled') {
                    $directdial = "Permitida (" . strtoupper($ivr_row['directdial']) . ")";
                }

                if (!empty($ivr_row['invalid_destination'])) $invalid_dest = $ivr_row['invalid_destination'];
                if (!empty($ivr_row['timeout_destination'])) $timeout_dest = $ivr_row['timeout_destination'];

                // Gravação de áudio de saudações / mensagem da URA
                $ann_rec = $ivr_row['announcement'] ?? ($ivr_row['announcement_id'] ?? ($ivr_row['recording_id'] ?? ''));
                if (!empty($ann_rec)) {
                    $rec_row = fetchAsteriskConfigRow('recordings', $ann_rec, ['id', 'recording_id'], $ast_db);
                    if ($rec_row) {
                        if (!empty($rec_row['filename'])) $audio_file = $rec_row['filename'];
                        elseif (!empty($rec_row['displayname'])) $audio_file = $rec_row['displayname'];
                    } else {
                        $audio_file = $ann_rec;
                    }
                }
            }

            $ent_rows = fetchAsteriskConfigRows('ivr_entries', $ivr_id, ['ivr_id', 'id'], $ast_db);
            foreach ($ent_rows as $er) {
                $opt = trim($er['selection'] ?? ($er['option'] ?? ''));
                $opt_dest = trim($er['dest'] ?? ($er['destination'] ?? ''));
                if ($opt !== '' && $opt_dest !== '') {
                    $entries[$opt] = resolveAsteriskDestinationTree($opt_dest, $ast_db, $ext_names, $depth + 1);
                }
            }
        }

        return [
            'type' => 'ivr',
            'id' => $ivr_id,
            'title' => "URA / Menu de Atendimento: $ivr_title",
            'desc' => 'Atendimento Interativo (Pressione a opção desejada)',
            'badge' => 'Menu URA',
            'color' => 'cyan',
            'audio_file' => $audio_file,
            'timeout' => $timeout,
            'invalid_loops' => $invalid_loops,
            'directdial' => $directdial,
            'invalid_dest' => $invalid_dest,
            'invalid_node' => !empty($invalid_dest) ? resolveAsteriskDestinationTree($invalid_dest, $ast_db, $ext_names, $depth + 1) : null,
            'timeout_dest' => $timeout_dest,
            'timeout_node' => !empty($timeout_dest) ? resolveAsteriskDestinationTree($timeout_dest, $ast_db, $ext_names, $depth + 1) : null,
            'entries' => $entries
        ];
    }

    // 4.9 Caixa postal via ext-local,vmb200,1 (vmb=ocupado, vmu=indisponível, vms=sem saudação)
    if (preg_match('/^ext-local,(vm[bus]?)(\d+)/i', $dest, $m_vm)) {
        $vm_kinds = ['vmb' => 'mensagem de ocupado', 'vmu' => 'mensagem de indisponível', 'vms' => 'sem saudação', 'vm' => 'padrão'];
        $vm_ext = $m_vm[2];
        $vm_kind = $vm_kinds[strtolower($m_vm[1])] ?? 'padrão';
        return [
            'type' => 'voicemail',
            'extension' => $vm_ext,
            'title' => "Caixa Postal (Voicemail): Ramal #$vm_ext (" . ($ext_names[$vm_ext] ?? "Ramal $vm_ext") . ")",
            'desc' => "Gravador de mensagens de voz — $vm_kind",
            'badge' => 'Caixa Postal',
            'color' => 'amber'
        ];
    }

    // 5. Ramal Direto (Extension)
    if (strpos($dest, 'from-did-direct') === 0 || strpos($dest, 'ext-local') === 0) {
        $ext = preg_replace('/\D/', '', explode(',', $dest)[1] ?? '');
        $ext_name = $ext_names[$ext] ?? "Ramal $ext";
        return [
            'type' => 'extension',
            'extension' => $ext,
            'title' => "Ramal Direto: Ramal $ext ($ext_name)",
            'desc' => 'Encaminhamento direto para o ramal do usuário',
            'badge' => 'Ramal PABX',
            'color' => 'emerald'
        ];
    }

    // 5.5. Caixa Postal (Voicemail)
    if (strpos($dest, 'ext-voicemail') === 0 || strpos($dest, 'voicemail') !== false) {
        $ext = preg_replace('/\D/', '', explode(',', $dest)[1] ?? '');
        return [
            'type' => 'voicemail',
            'extension' => $ext,
            'title' => "Caixa Postal (Voicemail)" . ($ext ? ": Ramal #$ext" : ""),
            'desc' => 'Gravador de mensagens de voz',
            'badge' => 'Caixa Postal',
            'color' => 'amber'
        ];
    }

    // 6. Terminar chamada (app-blackhole: hangup, busy, congestion, ...)
    if (strpos($dest, 'app-blackhole') === 0 || strpos($dest, 'hangup') !== false) {
        $bh = strtolower(trim(explode(',', $dest)[1] ?? 'hangup'));
        $bh_map = ['hangup' => 'Encerrar Chamada (Hangup)', 'busy' => 'Sinal de Ocupado', 'congestion' => 'Sinal de Congestionamento',
                   'ring' => 'Tocar até desistir (Ring)', 'zapateller' => 'Tom de Informação (Zapateller)', 'musiconhold' => 'Música de Espera', 'playtones' => 'Reproduzir Tom'];
        $bh_title = $bh_map[$bh] ?? "Terminar Chamada ($bh)";
        return [
            'type' => 'hangup',
            'title' => $bh_title,
            'desc' => 'Finalizado diretamente pelo PABX',
            'badge' => 'Fim de Chamada',
            'color' => 'rose'
        ];
    }

    // Outros Destinos
    return [
        'type' => 'dest',
        'title' => 'Destino da Chamada',
        'desc' => "Direcionado para: " . resolveAsteriskDestLabel($dest, $ast_db),
        'badge' => 'Destino Final',
        'color' => 'indigo'
    ];
}

function getIssabelInboundCallFlows() {
    return getPABXInboundCallFlows();
}

/**
 * Constrói a árvore de passos do fluxograma a partir de um destino inicial do Asterisk
 */
function buildFlowStepsFromDestination($dest, $ast_db, $ext_names, $did_label) {
    $steps = [];
    $steps[] = [
        'type' => 'trunk',
        'title' => 'Chamada Entrante (DID / Tronco)',
        'desc' => "DID: $did_label",
        'badge' => 'Entrada PABX',
        'color' => 'cyan'
    ];

    $tree = resolveAsteriskDestinationTree($dest, $ast_db, $ext_names);
    $steps[] = $tree;

    return $steps;
}

function getPABXInboundCallFlows() {
    global $db;
    $flows = [];

    $ast_db = getAsteriskDBConnection();
    if (!$ast_db) {
        $ast_db = getAsteriskPdoConnection('asterisk');
    }

    $ext_names = getExtensionsMap();

    // 1. Tentar buscar TODAS as rotas de entrada reais cadastradas na tabela 'incoming' do Asterisk MySQL
    if ($ast_db) {
        try {
            $q_inc = $ast_db->query("SELECT extension, cidnum, destination, description FROM incoming ORDER BY extension ASC");
            if ($q_inc) {
                while ($r_inc = $q_inc->fetch(PDO::FETCH_ASSOC)) {
                    $did_num = trim($r_inc['extension']);
                    $cid_num = trim($r_inc['cidnum']);
                    $dest = trim($r_inc['destination']);
                    $desc = trim($r_inc['description']);

                    $did_label = $did_num !== '' ? $did_num : ($cid_num !== '' ? "CID: $cid_num" : 'Qualquer DID (Entrada Geral)');
                    $route_title = $desc !== '' ? $desc : "Rota Entrante $did_label";

                    $tree = resolveAsteriskDestinationTree($dest, $ast_db, $ext_names);
                    $steps = [[
                        'type' => 'trunk',
                        'title' => 'Chamada Entrante (DID / Tronco)',
                        'desc' => "DID: $did_label",
                        'badge' => 'Entrada PABX',
                        'color' => 'cyan'
                    ], $tree];

                    if (!empty($steps)) {
                        $flows[] = [
                            'id' => $did_num !== '' ? $did_num : ($cid_num ?: 'default_' . count($flows)),
                            'did' => $did_label,
                            'description' => $route_title,
                            'destination' => $dest,
                            'tree' => $tree,
                            'steps' => $steps
                        ];
                    }
                }
            }
        } catch (Exception $e) { $GLOBALS['__flows_error'] = $e->getMessage(); }
    }

    // Sem rotas reais: NÃO inventa fluxo. A tela mostra o motivo.
    if (empty($flows)) {
        $GLOBALS['__flows_status'] = !$ast_db
            ? 'Não foi possível conectar ao banco de dados do PABX (verifique as credenciais em Configurações e o MySQL).'
            : (!empty($GLOBALS['__flows_error']) ? 'Erro ao ler as rotas de entrada: ' . $GLOBALS['__flows_error'] : 'Nenhuma rota de entrada (DID) cadastrada em Conectividade > Rotas de Entrada.');
    }

    return $flows;
}

/**
 * Obtém conexão MySQL autenticada com o banco 'asterisk' extraindo credenciais de /etc/amportal.conf
 */
/**
 * Nome da coluna de valor nas tabelas de opções dos ramais do IssabelPBX/FreePBX (sip, pjsip): 'data' na maioria das
 * versões e 'value' em outras. Retorna '' se a tabela não existir.
 */
function astOptionsValueCol($ast, $table) {
    static $cache = [];
    if (isset($cache[$table])) return $cache[$table];
    $col = '';
    try {
        $names = [];
        $st = $ast->query("SHOW COLUMNS FROM `" . str_replace('`', '', $table) . "`");
        if ($st) while ($r = $st->fetch(PDO::FETCH_ASSOC)) $names[] = strtolower($r['Field']);
        if (in_array('data', $names, true)) $col = 'data'; elseif (in_array('value', $names, true)) $col = 'value';
    } catch (Exception $e) { $col = ''; }
    return $cache[$table] = $col;
}

/** Conjunto (ramal => true) dos ramais configurados como WebRTC no PABX (lê as tabelas sip/pjsip do IssabelPBX). */
function getWebrtcExtensionSet() {
    $set = [];
    $ast = getAsteriskDBConnection();
    if (!$ast) return $set;
    foreach (['sip', 'pjsip'] as $tbl) {
        $col = astOptionsValueCol($ast, $tbl);
        if ($col === '') continue;
        try {
            $st = $ast->query("SELECT id, keyword, LOWER(TRIM(`$col`)) AS v FROM `$tbl` WHERE keyword IN ('webrtc','transport','media_encryption','dtlsenable')");
            if ($st) while ($r = $st->fetch(PDO::FETCH_ASSOC)) {
                $k = $r['keyword']; $v = (string)$r['v'];
                if (($k === 'webrtc' && $v === 'yes') || ($k === 'transport' && strpos($v, 'ws') !== false)
                    || ($k === 'media_encryption' && $v === 'dtls') || ($k === 'dtlsenable' && $v === 'yes')) $set[(string)$r['id']] = true;
            }
        } catch (Exception $e) {}
    }
    return $set;
}

function getAsteriskDBConnection() {
    static $conn = null;
    if ($conn !== null) return $conn;

    $dbuser = 'root';
    $dbpass = '';
    $dbhost = 'localhost';

    // Parse automático das credenciais nativas do PABX/FreePBX
    if (file_exists('/etc/amportal.conf')) {
        $conf = @parse_ini_file('/etc/amportal.conf');
        if (is_array($conf)) {
            if (!empty($conf['AMPDBUSER'])) $dbuser = $conf['AMPDBUSER'];
            if (!empty($conf['AMPDBPASS'])) $dbpass = $conf['AMPDBPASS'];
            if (!empty($conf['AMPDBHOST'])) $dbhost = $conf['AMPDBHOST'];
        }
    }

    $sys_conf = function_exists('getPABXSystemConfig') ? getPABXSystemConfig() : ['mysqlrootpwd' => ''];
    if (empty($dbpass) && !empty($sys_conf['mysqlrootpwd'])) {
        $dbpass = $sys_conf['mysqlrootpwd'];
    }

    try {
        $pdo = new PDO("mysql:host={$dbhost};dbname=asterisk;charset=utf8", $dbuser, $dbpass, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_TIMEOUT => 2
        ]);
        $conn = $pdo;
    } catch (Exception $e) {
        try {
            $user_setting = function_exists('getSetting') ? getSetting('asterisk_db_user') : '';
            $pass_setting = function_exists('getSetting') ? getSetting('asterisk_db_pass') : '';
            $pdo = new PDO("mysql:host=localhost;dbname=asterisk;charset=utf8", $user_setting ?: 'asteriskuser', $pass_setting ?: 'eLaStIx.AsTeRiSk.UsEr.1', [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_TIMEOUT => 2
            ]);
            $conn = $pdo;
        } catch (Exception $e2) {
            $conn = false;
        }
    }

    return $conn;
}

/**
 * Helper global para obter mapeamento de Filas (Número => Nome da Fila)
 */
function getQueuesMap() {
    global $db;
    $map = [];
    
    // 1. SQLite Local
    if ($db) {
        try {
            $stmt = $db->query("SELECT queue_number, queue_name FROM queues_config");
            if ($stmt) {
                while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                    $qnum = trim($row['queue_number']);
                    $qname = trim($row['queue_name']);
                    if (!empty($qnum) && !empty($qname) && strcasecmp($qname, $qnum) !== 0) {
                        $map[$qnum] = $qname;
                    }
                }
            }
        } catch (Exception $e) {}
    }

    // 2. MySQL Asterisk (PABX / FreePBX)
    $ast_db = getAsteriskDBConnection();
    if ($ast_db) {
        // Query 1: queues_config (coluna descr)
        try {
            $q_qc1 = $ast_db->query("SELECT extension, descr as queue_name FROM queues_config WHERE extension != '' AND descr != ''");
            if ($q_qc1) {
                while ($row = $q_qc1->fetch(PDO::FETCH_ASSOC)) {
                    $ext = trim($row['extension']);
                    $val = trim($row['queue_name']);
                    if ($ext != '' && !empty($val) && strcasecmp($val, $ext) !== 0 && !isset($map[$ext])) {
                        $map[$ext] = $val;
                    }
                }
            }
        } catch (Exception $eq1) {}

        // Query 2: Tabela asterisk.queues_details (fallback legado)
        try {
            $q_qd = $ast_db->query("SELECT id as extension, data as queue_name FROM queues_details WHERE keyword IN ('description', 'descr', 'queue-name') AND data != ''");
            if ($q_qd) {
                while ($row = $q_qd->fetch(PDO::FETCH_ASSOC)) {
                    $ext = trim($row['extension']);
                    $val = trim($row['queue_name']);
                    if ($ext != '' && !empty($val) && strcasecmp($val, $ext) !== 0 && !isset($map[$ext])) {
                        $map[$ext] = $val;
                    }
                }
            }
        } catch (Exception $eqd) {}

        // Query 3: Tabela asterisk.queues (description)
        try {
            $q_queues = $ast_db->query("SELECT queue, description FROM queues WHERE queue != '' AND description != ''");
            if ($q_queues) {
                while ($row = $q_queues->fetch(PDO::FETCH_ASSOC)) {
                    $ext = trim($row['queue']);
                    $val = trim($row['description']);
                    if ($ext != '' && !empty($val) && strcasecmp($val, $ext) !== 0 && !isset($map[$ext])) {
                        $map[$ext] = $val;
                    }
                }
            }
        } catch (Exception $eq2) {}
    }

    return $map;
}

/**
 * Helper global para obter mapeamento de Ramais (Número => Nome do Atendente)
 */
function getExtensionsMap() {
    global $db;
    $map = [];

    if ($db) {
        try {
            $stmt = $db->query("SELECT extension, agent_name FROM extensions_config");
            if ($stmt) {
                while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                    $ext = trim($row['extension']);
                    $name = trim($row['agent_name']);
                    if (!empty($ext) && !empty($name) && strcasecmp($name, $ext) !== 0) {
                        $map[$ext] = $name;
                    }
                }
            }
        } catch (Exception $e) {}
    }

    $ast_db = getAsteriskDBConnection();
    if ($ast_db) {
        try {
            $q_usr = $ast_db->query("SELECT extension, name FROM users WHERE extension != '' AND name != ''");
            if ($q_usr) {
                while ($row = $q_usr->fetch(PDO::FETCH_ASSOC)) {
                    $u = trim($row['extension']);
                    $n = trim($row['name']);
                    if ($u != '' && $n != '' && strcasecmp($n, $u) !== 0 && !isset($map[$u])) {
                        $map[$u] = $n;
                    }
                }
            }
        } catch (Exception $ex) {}
    }

    return $map;
}

/**
 * Helper centralizado para padronização de retorno de nomes (Filas e Ramais)
 */
function resolveEndpointDisplayName($id, $queuesMap = [], $extensionsMap = []) {
    return formatEndpointName($id, $queuesMap, $extensionsMap);
}

/**
 * Helper centralizado para formatar qualquer número (Fila, Ramal ou Externo)
 */
function formatEndpointName($id, $queuesMap = null, $extensionsMap = null) {
    if (empty($id)) return '-';

    if ($queuesMap === null) $queuesMap = getQueuesMap();
    if ($extensionsMap === null) $extensionsMap = getExtensionsMap();

    // Limpa prefixos
    $cleanId = trim(str_ireplace('Fila', '', $id));

    // 1. Se for FILA -> Retorna "5002 - Porteiro"
    if (isset($queuesMap[$cleanId]) && !empty($queuesMap[$cleanId])) {
        $qName = trim(str_ireplace(["Fila {$cleanId}", "Fila", $cleanId], '', $queuesMap[$cleanId]));
        $qName = ltrim($qName, ' -:()');
        $qName = rtrim($qName, ')');
        return !empty($qName) ? "{$cleanId} - {$qName}" : $cleanId;
    }

    // 2. Se for RAMAL -> Retorna "201 - Leandro"
    if (isset($extensionsMap[$cleanId]) && !empty($extensionsMap[$cleanId])) {
        $eName = trim(str_ireplace($cleanId, '', $extensionsMap[$cleanId]));
        $eName = ltrim($eName, ' -:');
        return !empty($eName) ? "{$cleanId} - {$eName}" : $cleanId;
    }

    // Fallback para número puro
    return (is_numeric($cleanId) && strlen($cleanId) >= 4 && (strpos($cleanId, '5') === 0 || strpos($cleanId, '1') === 0)) ? $cleanId : $cleanId;
}

/**
 * Retorna mapa de contatos salvos no SQLite [digitos_telefone => array_dados]
 */
function getContactsMap() {
    global $db;
    $map = [];
    if (!$db) return $map;

    // Mapear Ramais (retornando NÚMERO - NOME)
    $extMap = getExtensionsMap();
    foreach ($extMap as $ext => $title) {
        $cleanName = trim(str_ireplace($ext, '', $title));
        $cleanName = ltrim($cleanName, ' -:');
        $map[$ext] = ['name' => !empty($cleanName) ? "{$ext} - {$cleanName}" : $ext];
    }

    // Mapear Filas (retornando NÚMERO - NOME)
    $queueMap = getQueuesMap();
    foreach ($queueMap as $qnum => $title) {
        $cleanName = trim(str_ireplace(["Fila {$qnum}", "Fila", $qnum], '', $title));
        $cleanName = ltrim($cleanName, ' -:');
        $map[$qnum] = ['name' => !empty($cleanName) ? "{$qnum} - {$cleanName}" : $qnum];
    }

    // 3. Mapear Contatos CRM
    try {
        $db->exec("CREATE TABLE IF NOT EXISTS crm_contacts (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            name TEXT NOT NULL,
            phone TEXT NOT NULL,
            email TEXT DEFAULT '',
            company TEXT DEFAULT '',
            tag TEXT DEFAULT '',
            source TEXT DEFAULT 'Manual',
            notes TEXT DEFAULT '',
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME DEFAULT CURRENT_TIMESTAMP
        )");

        $stmt = $db->query("SELECT * FROM crm_contacts ORDER BY id DESC");
        if ($stmt) {
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
            foreach ($rows as $r) {
                $clean = preg_replace('/\D/', '', $r['phone']);
                if (!empty($clean)) {
                    $map[$clean] = $r;
                    if (strlen($clean) >= 8) {
                        $map[substr($clean, -8)] = $r;
                    }
                    if (strlen($clean) >= 9) {
                        $map[substr($clean, -9)] = $r;
                    }
                }
            }
        }
    } catch (Exception $e) {}

    // 4. Mapear Contatos do PABX (address_book.db)
    $issabel_db_path = '/var/www/db/address_book.db';
    if (file_exists($issabel_db_path)) {
        try {
            $issabel_db = new PDO("sqlite:" . $issabel_db_path);
            $issabel_db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
            $stmt = $issabel_db->query("SELECT name, last_name, telefono FROM contact");
            if ($stmt) {
                while ($r = $stmt->fetch(PDO::FETCH_ASSOC)) {
                    $clean = preg_replace('/\D/', '', $r['telefono']);
                    if (!empty($clean)) {
                        $fullName = trim($r['name'] . ' ' . $r['last_name']);
                        // Only add if not already in map (prefer our CRM)
                        if (!isset($map[$clean])) {
                            $contactData = ['name' => $fullName, 'phone' => $r['telefono'], 'source' => 'PABX'];
                            $map[$clean] = $contactData;
                            if (strlen($clean) >= 8 && !isset($map[substr($clean, -8)])) $map[substr($clean, -8)] = $contactData;
                            if (strlen($clean) >= 9 && !isset($map[substr($clean, -9)])) $map[substr($clean, -9)] = $contactData;
                        }
                    }
                }
            }
        } catch (Exception $eSync) {}
    }

    return $map;
}

/**
 * Formata um número de telefone no padrão brasileiro (XX) XXXXX-XXXX
 */
function formatPhoneNumber($phone) {
    if (empty($phone)) return $phone;
    $clean = preg_replace('/\D/', '', $phone);
    if (strlen($clean) === 11) {
        return '(' . substr($clean, 0, 2) . ') ' . substr($clean, 2, 5) . '-' . substr($clean, 7);
    } elseif (strlen($clean) === 10) {
        return '(' . substr($clean, 0, 2) . ') ' . substr($clean, 2, 4) . '-' . substr($clean, 6);
    } elseif (strlen($clean) === 13 && strpos($clean, '55') === 0) {
        return '(' . substr($clean, 2, 2) . ') ' . substr($clean, 4, 5) . '-' . substr($clean, 9);
    } elseif (strlen($clean) === 12 && strpos($clean, '55') === 0) {
        return '(' . substr($clean, 2, 2) . ') ' . substr($clean, 4, 4) . '-' . substr($clean, 8);
    }
    return $phone;
}

/**
 * Procura um nome de contato salvo para determinado número de telefone
 */
function lookupContactName($phone, &$contacts_map) {
    if (empty($phone)) return false;
    $clean = preg_replace('/\D/', '', $phone);
    if (empty($clean)) return false;

    if (isset($contacts_map[$clean])) {
        return $contacts_map[$clean]['name'];
    }
    if (strlen($clean) >= 9 && isset($contacts_map[substr($clean, -9)])) {
        return $contacts_map[substr($clean, -9)]['name'];
    }
    if (strlen($clean) >= 8 && isset($contacts_map[substr($clean, -8)])) {
        return $contacts_map[substr($clean, -8)]['name'];
    }

    return false;
}

/**
 * Procura os dados completos de um contato salvo
 */
function lookupContactData($phone, &$contacts_map) {
    if (empty($phone)) return false;
    $clean = preg_replace('/\D/', '', $phone);
    if (empty($clean)) return false;

    if (isset($contacts_map[$clean])) {
        return $contacts_map[$clean];
    }
    if (strlen($clean) >= 9 && isset($contacts_map[substr($clean, -9)])) {
        return $contacts_map[substr($clean, -9)];
    }
    if (strlen($clean) >= 8 && isset($contacts_map[substr($clean, -8)])) {
        return $contacts_map[substr($clean, -8)];
    }

    return false;
}

/**
 * Sincroniza automaticamente chamadas do Asterisk CDR para a tabela de Contatos CRM
 */
function syncPabxCallsToContacts() {
    global $db;
    if (!$db) return 0;

    $sys_conf = function_exists('getPABXSystemConfig') ? getPABXSystemConfig() : ['mysqlrootpwd' => ''];
    $ast_db = null;
    try {
        $ast_db = new PDO("mysql:host=localhost;dbname=asteriskcdrdb;charset=utf8", 'root', $sys_conf['mysqlrootpwd'], [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_TIMEOUT => 2
        ]);
    } catch (Exception $e1) {
        try {
            $ast_db = new PDO("mysql:host=localhost;dbname=asteriskcdrdb;charset=utf8", getSetting('asterisk_db_user') ?: 'asteriskuser', getSetting('asterisk_db_pass') ?: 'eLaStIx.AsTeRiSk.UsEr.1', [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_TIMEOUT => 2
            ]);
        } catch (Exception $e2) {}
    }

    if (!$ast_db) return 0;

    $existing = getContactsMap();
    $added = 0;

    try {
        $q_numbers = $ast_db->query("
            SELECT DISTINCT src as num FROM cdr WHERE LENGTH(src) >= 8 AND src REGEXP '^[0-9]+$'
            UNION
            SELECT DISTINCT dst as num FROM cdr WHERE LENGTH(dst) >= 8 AND dst REGEXP '^[0-9]+$'
            LIMIT 200
        ");

        if ($q_numbers) {
            $stmt_ins = $db->prepare("INSERT INTO crm_contacts (name, phone, source, tag) VALUES (:n, :p, 'Ligação PABX', 'Novo Lead')");
            while ($row = $q_numbers->fetch(PDO::FETCH_ASSOC)) {
                $num = trim($row['num']);
                if (empty($num)) continue;
                $clean = preg_replace('/\D/', '', $num);

                if (lookupContactName($num, $existing) !== false) continue;

                $formatted_name = "Chamada $num";
                if (strlen($clean) == 11) {
                    $formatted_name = "Cliente (" . substr($clean, 0, 2) . ") " . substr($clean, 2, 5) . "-" . substr($clean, 7);
                } elseif (strlen($clean) == 10) {
                    $formatted_name = "Cliente (" . substr($clean, 0, 2) . ") " . substr($clean, 2, 4) . "-" . substr($clean, 6);
                }

                $stmt_ins->execute([':n' => $formatted_name, ':p' => $num]);
                $added++;
                $existing[$clean] = ['name' => $formatted_name, 'phone' => $num];
            }
        }
    } catch (Exception $e_sync) {}

    return $added;
}

/**
 * Retorna uma conexão PDO ativa com o MySQL do Asterisk (Local ou Remoto)
 */
function getAsteriskPdoConnection($dbname = 'asteriskcdrdb') {
    $db_host = getSetting('asterisk_db_host') ?: '127.0.0.1';
    $db_port = getSetting('asterisk_db_port') ?: '3306';
    $db_user = getSetting('asterisk_db_user') ?: 'root';
    $db_pass = getSetting('asterisk_db_pass') ?: '';

    $sys_conf = function_exists('getPABXSystemConfig') ? getPABXSystemConfig() : ['mysqlrootpwd' => ''];

    $creds_to_try = [
        ['host' => $db_host, 'port' => $db_port, 'user' => $db_user, 'pass' => $db_pass],
        ['host' => '127.0.0.1', 'port' => '3306', 'user' => 'root', 'pass' => $sys_conf['mysqlrootpwd'] ?? ''],
        ['host' => 'localhost', 'port' => '3306', 'user' => 'root', 'pass' => $sys_conf['mysqlrootpwd'] ?? ''],
        ['host' => '127.0.0.1', 'port' => '3306', 'user' => 'asteriskuser', 'pass' => 'eLaStIx.AsTeRiSk.UsEr.1'],
        ['host' => 'localhost', 'port' => '3306', 'user' => 'asteriskuser', 'pass' => 'eLaStIx.AsTeRiSk.UsEr.1'],
        ['host' => '127.0.0.1', 'port' => '3306', 'user' => 'root', 'pass' => ''],
        ['host' => 'localhost', 'port' => '3306', 'user' => 'root', 'pass' => '']
    ];

    foreach ($creds_to_try as $c) {
        if (empty($c['host']) || empty($c['user'])) continue;
        try {
            $dsn = "mysql:host={$c['host']};port={$c['port']};dbname={$dbname};charset=utf8";
            $pdo = new PDO($dsn, $c['user'], $c['pass'], [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_TIMEOUT => 3
            ]);
            return $pdo;
        } catch (Exception $e) {}
    }

    return null;
}

/**
 * IPbx Prisma - Núcleo de Gestão de Usuários & Permissões Granulares
 */
function getAllSystemUsers() {
    global $db;
    if (!$db) return [];
    try {
        $stmt = $db->query("SELECT * FROM system_users ORDER BY id ASC");
        $users = $stmt->fetchAll(PDO::FETCH_ASSOC);
        foreach ($users as &$u) {
            $u['permissions_list'] = !empty($u['permissions']) ? json_decode($u['permissions'], true) : [];
            if (!is_array($u['permissions_list'])) $u['permissions_list'] = [];
        }
        return $users;
    } catch (Exception $e) {
        return [];
    }
}

function getSystemUserById($id) {
    global $db;
    if (!$db || intval($id) <= 0) return null;
    try {
        $stmt = $db->prepare("SELECT * FROM system_users WHERE id = :id");
        $stmt->execute([':id' => intval($id)]);
        $u = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($u) {
            $u['permissions_list'] = !empty($u['permissions']) ? json_decode($u['permissions'], true) : [];
            if (!is_array($u['permissions_list'])) $u['permissions_list'] = [];
        }
        return $u;
    } catch (Exception $e) {
        return null;
    }
}

/** O usuário tem Webphone? Habilitado + (ramal vinculado do PABX ou conta personalizada de outro servidor). */
function userHasWebphone($u) {
    return !empty($u) && !empty($u['webphone']) && (!empty($u['extension']) || !empty($u['wp_custom']));
}

function saveSystemUser($data) {
    global $db;
    require_once __DIR__ . '/sso.php';
    if (!$db) return ['success' => false, 'error' => 'Banco de dados inacessível'];

    $id = isset($data['id']) ? intval($data['id']) : 0;
    $name = trim($data['name'] ?? '');
    $email = trim($data['email'] ?? '');
    $role = trim($data['role'] ?? 'Usuário');
    $extension = trim($data['extension'] ?? '');
    $whatsapp = trim($data['whatsapp'] ?? '');
    $wpCustom = !empty($data['wp_custom']) ? 1 : 0;
    $webphone = (!empty($data['webphone']) && ($extension !== '' || $wpCustom)) ? 1 : 0; // webphone só existe com ramal vinculado ou conta personalizada
    $wpHost = strtolower(trim((string)($data['wp_host'] ?? ''))); $wpPort = (int)($data['wp_port'] ?? 8089); $wpUser = trim((string)($data['wp_user'] ?? '')); $wpPassIn = (string)($data['wp_pass'] ?? '');
    if ($wpCustom) {
        if (!preg_match('/^[a-z0-9]([a-z0-9.\-]{0,251}[a-z0-9])?$/', $wpHost)) return ['success' => false, 'error' => 'Conta personalizada: informe o domínio ou IP do servidor (somente letras, números, ponto e hífen).'];
        if ($wpPort < 1 || $wpPort > 65535) return ['success' => false, 'error' => 'Conta personalizada: porta inválida (1 a 65535).'];
        if (!preg_match('/^[A-Za-z0-9_.+\-]{1,64}$/', $wpUser)) return ['success' => false, 'error' => 'Conta personalizada: informe o usuário (ramal) do outro servidor.'];
        if (strlen($wpPassIn) > 128 || preg_match('/[\x00-\x1f]/', $wpPassIn)) return ['success' => false, 'error' => 'Conta personalizada: senha inválida.'];
        $extension = ''; // a conta personalizada substitui o ramal do PABX só no Webphone
    }
    $permissions = isset($data['permissions']) && is_array($data['permissions']) ? json_encode(array_values($data['permissions'])) : '[]';

    if (empty($name) || empty($email)) {
        return ['success' => false, 'error' => 'Nome e E-mail são obrigatórios.'];
    }

    try {
        if ($id > 0) {
            $stmt = $db->prepare("UPDATE system_users SET name = :name, email = :email, role = :role, extension = :ext, whatsapp = :wa, webphone = :wp, permissions = :perm, updated_at = CURRENT_TIMESTAMP WHERE id = :id");
            $stmt->execute([':name' => $name, ':email' => $email, ':role' => $role, ':ext' => $extension, ':wa' => $whatsapp, ':wp' => $webphone, ':perm' => $permissions, ':id' => $id]);
            $db->prepare("UPDATE system_users SET wp_custom = :c, wp_host = :h, wp_port = :p, wp_user = :u WHERE id = :id")->execute([':c' => $wpCustom, ':h' => $wpCustom ? $wpHost : '', ':p' => $wpCustom ? $wpPort : 8089, ':u' => $wpCustom ? $wpUser : '', ':id' => $id]);
            if (!$wpCustom) $db->prepare("UPDATE system_users SET wp_pass = '' WHERE id = :id")->execute([':id' => $id]);
            elseif ($wpPassIn !== '') $db->prepare("UPDATE system_users SET wp_pass = :s WHERE id = :id")->execute([':s' => encryptSmtpPassword($wpPassIn), ':id' => $id]);
            if (!empty($data['password'])) {
                $pw = authSetPassword($id, $data['password']);
                if (!$pw['success']) return ['success' => false, 'error' => $pw['error']];
            }
            $sy = aclSyncUser($id, (string)($data['password'] ?? ''));
            return ['success' => true, 'message' => "Usuário '$name' atualizado com sucesso!" . ($sy['ok'] ? " Login da central: {$sy['login']}." : " Atenção: não foi possível atualizar o login da central ({$sy['error']}).")];
        } else {
            // Verificar duplicidade de e-mail
            $ch = $db->prepare("SELECT id FROM system_users WHERE email = :email");
            $ch->execute([':email' => $email]);
            if ($ch->fetch()) {
                return ['success' => false, 'error' => "O e-mail '$email' já está cadastrado."];
            }

            $stmt = $db->prepare("INSERT INTO system_users (name, email, role, extension, whatsapp, webphone, permissions, status) VALUES (:name, :email, :role, :ext, :wa, :wp, :perm, 'Ativo')");
            if (empty($data['password'])) {
                return ['success' => false, 'error' => 'Defina uma senha (mínimo 8 caracteres) para o novo usuário.'];
            }
            $stmt->execute([':name' => $name, ':email' => $email, ':role' => $role, ':ext' => $extension, ':wa' => $whatsapp, ':wp' => $webphone, ':perm' => $permissions]);
            $newId = (int)$db->lastInsertId();
            if ($wpCustom) $db->prepare("UPDATE system_users SET wp_custom = 1, wp_host = :h, wp_port = :p, wp_user = :u, wp_pass = :s WHERE id = :id")->execute([':h' => $wpHost, ':p' => $wpPort, ':u' => $wpUser, ':s' => encryptSmtpPassword($wpPassIn), ':id' => $newId]);
            $pw = authSetPassword($newId, $data['password']);
            if (!$pw['success']) {
                $db->prepare("DELETE FROM system_users WHERE email = :e")->execute([':e' => $email]);
                return ['success' => false, 'error' => $pw['error']];
            }
            $sy = aclSyncUser($newId, (string)$data['password']);
            return ['success' => true, 'message' => "Usuário '$name' cadastrado com sucesso!" . ($sy['ok'] ? " Já pode entrar no login inicial com o usuário '{$sy['login']}' e a mesma senha." : " Atenção: não foi possível criar o login da central ({$sy['error']}); ele entra pelo login com e-mail e senha.")];
        }
    } catch (Exception $e) {
        return ['success' => false, 'error' => "Erro no banco de dados: " . $e->getMessage()];
    }
}

function deleteSystemUser($id) {
    global $db;
    require_once __DIR__ . '/sso.php';
    if (!$db || intval($id) <= 0) return false;
    try {
        $q = $db->prepare("SELECT issabel_user FROM system_users WHERE id = :id"); $q->execute([':id' => intval($id)]);
        $il = (string)$q->fetchColumn();
        $stmt = $db->prepare("DELETE FROM system_users WHERE id = :id");
        $ok = $stmt->execute([':id' => intval($id)]);
        if ($ok && $il !== '') aclRemoveUser($il);
        return $ok;
    } catch (Exception $e) {
        return false;
    }
}

function getLoggedUser() {
    // Na web só vale o usuário da sessão; o padrão administrador é apenas para CLI (AGI/cron)
    $user_id = $_SESSION['logged_user_id'] ?? (PHP_SAPI === 'cli' ? 1 : 0);
    // Login desligado (set_auth.php off): atua como o primeiro Administrador ativo
    if (empty($_SESSION['logged_user_id']) && PHP_SAPI !== 'cli' && function_exists('authDisabled') && authDisabled()) {
        global $db;
        try {
            $aid = $db->query("SELECT id FROM system_users WHERE role = 'Administrador' AND status = 'Ativo' ORDER BY id ASC LIMIT 1")->fetchColumn();
            if ($aid) $user_id = (int)$aid;
        } catch (Exception $e) {}
    }
    $u = getSystemUserById($user_id);
    if (!$u && PHP_SAPI !== 'cli') {
        return null;
    }
    if (!$u) {
        $u = [
            'id' => 1,
            'name' => 'Administrador (CLI)',
            'email' => '',
            'role' => 'Administrador',
            'extension' => '',
            'whatsapp' => '',
            'permissions_list' => ['mod_dashboard','mod_filas','mod_whatsapp','mod_relatorios','mod_configuracoes','listen_recordings','send_whatsapp','manage_settings','schedule_reports','click_to_call']
        ];
    }
    return $u;
}

function hasUserPermission($permission_key) {
    $u = getLoggedUser();
    if (!$u) return false;

    // Administrador possui acesso total a tudo
    if (strtolower($u['role']) === 'administrador') {
        return true;
    }

    $perms = $u['permissions_list'] ?? [];
    return in_array($permission_key, $perms);
}

/**
 * Criptografia Simétrica AES-256-CBC para senhas SMTP / Credenciais (Segurança & LGPD)
 */
if (!function_exists('encryptSmtpPassword')) {
    function encryptSmtpPassword($plainPassword) {
        if (empty($plainPassword)) return '';
        if (strpos($plainPassword, 'enc:') === 0) return $plainPassword; // Já criptografado
        
        $secretKey = hash('sha256', 'IPbxPrisma_LGPD_Secret_Key_v1_2026');
        $iv = openssl_random_pseudo_bytes(16);
        $encrypted = openssl_encrypt($plainPassword, 'AES-256-CBC', $secretKey, 0, $iv);
        return 'enc:' . base64_encode($encrypted . '::' . $iv);
    }
}

if (!function_exists('decryptSmtpPassword')) {
    function decryptSmtpPassword($cipherPassword) {
        if (empty($cipherPassword)) return '';
        if (strpos($cipherPassword, 'enc:') !== 0) return $cipherPassword; // Texto legado plano
        
        $data = substr($cipherPassword, 4);
        $decoded = base64_decode($data);
        $parts = explode('::', $decoded, 2);
        if (count($parts) !== 2) return $cipherPassword;
        
        $secretKey = hash('sha256', 'IPbxPrisma_LGPD_Secret_Key_v1_2026');
        $decrypted = openssl_decrypt($parts[0], 'AES-256-CBC', $secretKey, 0, $parts[1]);
        return ($decrypted !== false) ? $decrypted : '';
    }
}

/**
 * Gera o corpo de e-mail HTML estilizado e o arquivo CSV em anexo com dados reais do PABX
 */
function buildScheduledReportPayload($reportTitle, $reportType) {
    $todayStr = date('d/m/Y H:i:s');
    $dateFilter = date('Y-m-d');
    
    // Buscar estatísticas reais do banco Asterisk
    $sysConfig = function_exists('getIssabelSystemConfig') ? getIssabelSystemConfig() : ['mysqlrootpwd' => ''];
    $dbAst = null;
    $calls = [];
    $totalCalls = 0;
    $answeredCalls = 0;
    $failedCalls = 0;
    $totalDurationSec = 0;

    try {
        $dbAst = new PDO("mysql:host=localhost;dbname=asterisk;charset=utf8", 'root', $sysConfig['mysqlrootpwd'], [
            PDO::ATTR_TIMEOUT => 3,
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION
        ]);
    } catch (Exception $e) {}

    if ($dbAst) {
        try {
            $stmt = $dbAst->prepare("SELECT uniqueid, calldate, src, dst, disposition, duration, billsec FROM cdr WHERE calldate >= :sd ORDER BY calldate DESC LIMIT 100");
            $stmt->execute([':sd' => "$dateFilter 00:00:00"]);
            $calls = $stmt->fetchAll(PDO::FETCH_ASSOC);

            foreach ($calls as $c) {
                $totalCalls++;
                $totalDurationSec += intval($c['billsec']);
                if ($c['disposition'] === 'ANSWERED') {
                    $answeredCalls++;
                } else {
                    $failedCalls++;
                }
            }
        } catch (Exception $ex) {}
    }

    $totalMin = round($totalDurationSec / 60, 1);
    $taxaAtendimento = $totalCalls > 0 ? round(($answeredCalls / $totalCalls) * 100, 1) : 100;

    // 1. Gerar Anexo CSV com BOM UTF-8
    $csvData = "\xEF\xBB\xBF"; // UTF-8 BOM para Excel
    $csvData .= "Data/Hora,Origem,Destino,Duracao (seg),Status,ID Unico\n";

    if (!empty($calls)) {
        foreach ($calls as $c) {
            $csvData .= sprintf(
                "\"%s\",\"%s\",\"%s\",\"%s\",\"%s\",\"%s\"\n",
                $c['calldate'],
                $c['src'],
                $c['dst'],
                $c['billsec'],
                $c['disposition'],
                $c['uniqueid']
            );
        }
    } else {
        $csvData .= "\"{$todayStr}\",\"N/A\",\"N/A\",\"0\",\"SEM_REGISTRO\",\"-\"\n";
    }

    $cleanTitle = preg_replace('/[^a-zA-Z0-9_]/', '_', $reportTitle ?: 'Relatorio');
    $filename = "Relatorio_{$cleanTitle}_" . date('Y-m-d') . ".csv";

    // 2. Gerar Corpo do E-mail em HTML de Alta Qualidade
    $html = '<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <title>' . htmlspecialchars($reportTitle) . '</title>
    <style>
        body { font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Helvetica, Arial, sans-serif; background-color: #0f172a; color: #f8fafc; margin: 0; padding: 20px; }
        .container { max-width: 650px; margin: 0 auto; background-color: #1e293b; border: 1px solid #334155; border-radius: 16px; overflow: hidden; box-shadow: 0 10px 25px rgba(0,0,0,0.3); }
        .header { background: linear-gradient(135deg, #4f46e5 0%, #06b6d4 100%); padding: 24px; text-align: center; }
        .header h1 { color: #ffffff; margin: 0; font-size: 20px; font-weight: 800; text-transform: uppercase; tracking-spacing: 1px; }
        .header p { color: #e0e7ff; margin: 6px 0 0 0; font-size: 12px; }
        .content { padding: 24px; }
        .stats-grid { display: grid; grid-template-columns: repeat(3, 1fr); gap: 12px; margin-bottom: 20px; }
        .stat-card { background-color: #0f172a; border: 1px solid #334155; padding: 14px; border-radius: 12px; text-align: center; }
        .stat-value { font-size: 18px; font-weight: 800; color: #38bdf8; display: block; }
        .stat-label { font-size: 10px; color: #94a3b8; text-transform: uppercase; margin-top: 4px; display: block; }
        table { width: 100%; border-collapse: collapse; margin-top: 16px; font-size: 11px; }
        th { background-color: #0f172a; color: #94a3b8; text-align: left; padding: 10px; text-transform: uppercase; font-size: 10px; border-bottom: 1px solid #334155; }
        td { padding: 10px; border-bottom: 1px solid #334155; color: #cbd5e1; }
        tr:hover { background-color: #334155; }
        .badge-success { background-color: rgba(16, 185, 129, 0.2); color: #34d399; padding: 3px 8px; border-radius: 6px; font-weight: bold; font-size: 10px; }
        .badge-failed { background-color: rgba(244, 63, 94, 0.2); color: #fb7185; padding: 3px 8px; border-radius: 6px; font-weight: bold; font-size: 10px; }
        .attachment-note { background-color: #0f172a; border: 1px border-dashed #38bdf8; padding: 14px; border-radius: 12px; margin-top: 20px; font-size: 11px; color: #38bdf8; text-align: center; }
        .footer { background-color: #0f172a; padding: 16px; text-align: center; font-size: 11px; color: #64748b; border-top: 1px solid #334155; }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <h1>📞 IPbx Prisma - Relatório Operacional</h1>
            <p>' . htmlspecialchars($reportTitle) . ' • Gerado em ' . $todayStr . '</p>
        </div>
        <div class="content">
            <p style="font-size: 13px; color: #e2e8f0; margin-top: 0;">Olá,</p>
            <p style="font-size: 12px; color: #94a3b8;">Segue abaixo o resumo do relatório operacional gerado pelo PABX IPbx Prisma com os dados consolidados da operação de telefonia:</p>
            
            <div class="stats-grid">
                <div class="stat-card">
                    <span class="stat-value">' . $totalCalls . '</span>
                    <span class="stat-label">Total de Chamadas</span>
                </div>
                <div class="stat-card">
                    <span class="stat-value" style="color:#34d399;">' . $answeredCalls . ' (' . $taxaAtendimento . '%)</span>
                    <span class="stat-label">Atendidas</span>
                </div>
                <div class="stat-card">
                    <span class="stat-value" style="color:#a78bfa;">' . $totalMin . ' min</span>
                    <span class="stat-label">Tempo Falado</span>
                </div>
            </div>

            <h3 style="font-size: 12px; color: #f8fafc; margin-bottom: 8px;">Últimas Chamadas da Operação:</h3>
            <table>
                <thead>
                    <tr>
                        <th>Data / Hora</th>
                        <th>Origem</th>
                        <th>Destino</th>
                        <th>Duração</th>
                        <th>Status</th>
                    </tr>
                </thead>
                <tbody>';

    if (!empty($calls)) {
        $sliceCalls = array_slice($calls, 0, 10);
        foreach ($sliceCalls as $sc) {
            $stBadge = ($sc['disposition'] === 'ANSWERED') ? '<span class="badge-success">Atendida</span>' : '<span class="badge-failed">' . htmlspecialchars($sc['disposition']) . '</span>';
            $html .= sprintf(
                '<tr><td>%s</td><td><strong>%s</strong></td><td>%s</td><td>%s seg</td><td>%s</td></tr>',
                htmlspecialchars($sc['calldate']),
                htmlspecialchars($sc['src']),
                htmlspecialchars($sc['dst']),
                htmlspecialchars($sc['billsec']),
                $stBadge
            );
        }
    } else {
        $html .= '<tr><td colspan="5" style="text-align:center; color:#64748b;">Nenhuma chamada registrada no período selecionado.</td></tr>';
    }

    $html .= '</tbody>
            </table>

            <div class="attachment-note">
                📎 <strong>Arquivo Anexo Disponível:</strong> O relatório detalhado completo foi exportado e está anexado a este e-mail no formato <code>' . htmlspecialchars($filename) . '</code> para abertura no Excel ou Google Sheets.
            </div>
        </div>
        <div class="footer">
            IPbx Prisma Telecom v6.1 • Sistema de Telefonia IP &amp; Inteligência Operacional
        </div>
    </div>
</body>
</html>';

    return [
        'body_html' => $html,
        'attachments' => [
            [
                'name'    => $filename,
                'content' => $csvData,
                'type'    => 'text/csv'
            ]
        ]
    ];
}

/**
 * Função NATIVA para Envio de E-mails via Socket SMTP Real (TLS/SSL/AUTH)
 * Retorna array ['success' => bool, 'error' => string] com diagnósticos precisos do servidor SMTP.
 */
function sendSmtpEmailNative($config, $to, $subject, $body, $attachments = []) {
    $host      = trim($config['host'] ?? '127.0.0.1');
    $port      = intval($config['port'] ?? 587);
    $user      = trim($config['user'] ?? '');
    $passRaw   = trim($config['password'] ?? '');
    $pass      = decryptSmtpPassword($passRaw);
    $sec       = strtolower(trim($config['security'] ?? 'tls')); // tls, ssl, none
    $fromEmail = trim($config['from_email'] ?? '');
    $fromName  = trim($config['from_name'] ?? 'IPbx Prisma');

    // Validar e-mail de origem (From)
    if (empty($fromEmail) || !filter_var($fromEmail, FILTER_VALIDATE_EMAIL)) {
        if (!empty($user) && filter_var($user, FILTER_VALIDATE_EMAIL)) {
            $fromEmail = $user;
        } else {
            return [
                'success' => false,
                'error'   => "E-mail de Origem (From) '{$fromEmail}' é inválido. Informe um e-mail válido (ex: suporte@prismatelecom.com)."
            ];
        }
    }

    $transport = ($sec === 'ssl') ? 'ssl://' : 'tcp://';
    $timeout   = 12;

    $socket = @fsockopen($transport . $host, $port, $errno, $errstr, $timeout);
    $smtpLogError = function($errMessage) use ($to, $subject, $host) {
        if (function_exists('pabx_log')) {
            pabx_log('smtp', 'ERROR', $errMessage, ['to' => $to, 'subject' => $subject, 'host' => $host]);
        }
        return ['success' => false, 'error' => $errMessage];
    };

    if (!$socket) {
        return $smtpLogError("Não foi possível conectar ao servidor SMTP {$host}:{$port} - Erro #{$errno}: {$errstr}");
    }

    stream_set_timeout($socket, $timeout);

    $readResponse = function($sock) {
        $resp = '';
        while ($str = fgets($sock, 512)) {
            $resp .= $str;
            if (substr($str, 3, 1) === ' ') break;
        }
        return $resp;
    };

    $greeting = $readResponse($socket);
    if (substr($greeting, 0, 3) !== '220') {
        fclose($socket);
        return $smtpLogError("Resposta inicial do servidor SMTP inválida: " . trim($greeting));
    }

    $clientHost = gethostname() ?: 'localhost';
    fputs($socket, "EHLO {$clientHost}\r\n");
    $ehloResp = $readResponse($socket);

    if ($sec === 'tls') {
        fputs($socket, "STARTTLS\r\n");
        $tlsResp = $readResponse($socket);
        if (substr($tlsResp, 0, 3) !== '220') {
            fclose($socket);
            return $smtpLogError("Servidor SMTP recusou comando STARTTLS: " . trim($tlsResp));
        }

        $cryptoMethod = STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT;
        if (defined('STREAM_CRYPTO_METHOD_TLSv1_3_CLIENT')) {
            $cryptoMethod |= STREAM_CRYPTO_METHOD_TLSv1_3_CLIENT;
        }

        if (!@stream_socket_enable_crypto($socket, true, $cryptoMethod)) {
            fclose($socket);
            return $smtpLogError("Falha no handshake de criptografia TLS com o servidor SMTP {$host}.");
        }

        fputs($socket, "EHLO {$clientHost}\r\n");
        $readResponse($socket);
    }

    // Autenticação SMTP (AUTH LOGIN)
    if (!empty($user) && !empty($pass)) {
        fputs($socket, "AUTH LOGIN\r\n");
        $authResp = $readResponse($socket);
        if (substr($authResp, 0, 3) !== '334') {
            fclose($socket);
            return $smtpLogError("Servidor recusou AUTH LOGIN: " . trim($authResp));
        }

        fputs($socket, base64_encode($user) . "\r\n");
        $userResp = $readResponse($socket);
        if (substr($userResp, 0, 3) !== '334') {
            fclose($socket);
            return $smtpLogError("Usuário SMTP não aceito pelo servidor ({$user}): " . trim($userResp));
        }

        fputs($socket, base64_encode($pass) . "\r\n");
        $passResp = $readResponse($socket);
        if (substr($passResp, 0, 3) !== '235') {
            fclose($socket);
            return $smtpLogError("Falha de autenticação (Senha incorreta para {$user}): " . trim($passResp));
        }
    }

    // MAIL FROM
    fputs($socket, "MAIL FROM: <{$fromEmail}>\r\n");
    $mailFromResp = $readResponse($socket);
    if (substr($mailFromResp, 0, 3) !== '250') {
        fclose($socket);
        return $smtpLogError("E-mail de remetente recusado (MAIL FROM <{$fromEmail}>): " . trim($mailFromResp));
    }

    // RCPT TO
    fputs($socket, "RCPT TO: <{$to}>\r\n");
    $rcptResp = $readResponse($socket);
    if (substr($rcptResp, 0, 3) !== '250' && substr($rcptResp, 0, 3) !== '251') {
        fclose($socket);
        return $smtpLogError("E-mail de destinatário recusado (RCPT TO <{$to}>): " . trim($rcptResp));
    }

    // DATA
    fputs($socket, "DATA\r\n");
    $dataResp = $readResponse($socket);
    if (substr($dataResp, 0, 3) !== '354') {
        fclose($socket);
        return $smtpLogError("Servidor recusou início do corpo de e-mail (DATA): " . trim($dataResp));
    }

    // Construção dos Cabeçalhos e Mensagem MIME (Suporte HTML e Anexos)
    $isHtml = (strpos($body, '<html') !== false || strpos($body, '<div') !== false || strpos($body, '<table') !== false || strpos($body, '<!DOCTYPE') !== false);
    $bodyContentType = $isHtml ? "text/html; charset=UTF-8" : "text/plain; charset=UTF-8";

    $boundary = "----=_NextPart_" . md5(time());
    $encodedSubject = "=?UTF-8?B?" . base64_encode($subject) . "?=";
    $headers  = "From: {$fromName} <{$fromEmail}>\r\n";
    $headers .= "To: <{$to}>\r\n";
    $headers .= "Subject: {$encodedSubject}\r\n";
    $headers .= "Date: " . date('r') . "\r\n";
    $headers .= "Message-ID: <" . time() . '.' . md5($to) . "@" . ($host ?: 'local') . ">\r\n";
    $headers .= "MIME-Version: 1.0\r\n";

    if (!empty($attachments)) {
        $headers .= "Content-Type: multipart/mixed; boundary=\"{$boundary}\"\r\n\r\n";
        $emailContent  = "--{$boundary}\r\n";
        $emailContent .= "Content-Type: {$bodyContentType}\r\n";
        $emailContent .= "Content-Transfer-Encoding: 8bit\r\n\r\n";
        $emailContent .= $body . "\r\n\r\n";

        foreach ($attachments as $att) {
            $filename = basename($att['name']);
            $mimeType = $att['type'] ?? 'application/octet-stream';
            $filedata = chunk_split(base64_encode($att['content']));
            $emailContent .= "--{$boundary}\r\n";
            $emailContent .= "Content-Type: {$mimeType}; name=\"{$filename}\"\r\n";
            $emailContent .= "Content-Transfer-Encoding: base64\r\n";
            $emailContent .= "Content-Disposition: attachment; filename=\"{$filename}\"\r\n\r\n";
            $emailContent .= $filedata . "\r\n\r\n";
        }
        $emailContent .= "--{$boundary}--\r\n";
    } else {
        $headers .= "Content-Type: {$bodyContentType}\r\n";
        $headers .= "Content-Transfer-Encoding: 8bit\r\n\r\n";
        $emailContent = $body . "\r\n";
    }

    fputs($socket, $headers . $emailContent . "\r\n.\r\n");
    $sendResp = $readResponse($socket);

    fputs($socket, "QUIT\r\n");
    fclose($socket);

    if (substr($sendResp, 0, 3) !== '250') {
        $err = "Servidor SMTP não confirmou entrega da mensagem: " . trim($sendResp);
        if (function_exists('pabx_log')) {
            pabx_log('smtp', 'ERROR', $err, ['to' => $to, 'subject' => $subject, 'host' => $host]);
        }
        return ['success' => false, 'error' => $err];
    }

    if (function_exists('pabx_log')) {
        pabx_log('smtp', 'INFO', "E-mail enviado com sucesso para {$to}", ['subject' => $subject, 'host' => $host, 'attachments' => count($attachments)]);
    }

    return ['success' => true, 'error' => ''];
}

/**
 * Verifica se a API Prismabot WhatsApp está ativa e configurada no sistema
 */
function isWhatsappApiActive() {
    global $db;
    if (!isset($db) || !$db) {
        try {
            $sqlitePath = __DIR__ . '/../config/database.sqlite';
            if (file_exists($sqlitePath)) {
                $db = new PDO("sqlite:" . $sqlitePath);
            }
        } catch (Exception $e) {
            return false;
        }
    }
    if (!$db) return false;

    if (function_exists('whatsappProviderConfigured') && function_exists('getSetting')) {
        try { if (waProvider() === 'uazapi') return whatsappProviderConfigured(); } catch (Exception $e) {}
    }
    try {
        $st = $db->query("SELECT value_val AS value FROM settings WHERE key_name = 'api_token'");
        if ($st) {
            $row = $st->fetch(PDO::FETCH_ASSOC);
            if ($row && !empty(trim($row['value']))) {
                return true;
            }
        }
    } catch (Exception $e) {}

    return false;
}

/**
 * Converte string de regra do Asterisk (ex: '00:00-23:59|*|25|dec') para rótulo em Português
 */
function parseAsteriskTimeRuleToLabel($rule) {
    $parts = explode('|', $rule);
    $t_range = trim($parts[0] ?? '*');
    $w_days  = trim($parts[1] ?? '*');
    $m_days  = trim($parts[2] ?? '*');
    $months  = trim($parts[3] ?? '*');

    $days_map = [
        'mon' => 'Segunda', 'tue' => 'Terça', 'wed' => 'Quarta',
        'thu' => 'Quinta', 'fri' => 'Sexta', 'sat' => 'Sábado', 'sun' => 'Domingo'
    ];
    $months_map = [
        'jan' => 'Janeiro', 'feb' => 'Fevereiro', 'mar' => 'Março', 'apr' => 'Abril',
        'may' => 'Maio', 'jun' => 'Junho', 'jul' => 'Julho', 'aug' => 'Agosto',
        'sep' => 'Setembro', 'oct' => 'Outubro', 'nov' => 'Novembro', 'dec' => 'Dezembro'
    ];

    $time_str = ($t_range === '*' || empty($t_range)) ? "Dia Inteiro (24h)" : "das " . str_replace('-', ' às ', $t_range);

    $date_str = "";
    if ($m_days !== '*' && !empty($m_days)) {
        $date_str .= "Dia $m_days";
    }
    if ($months !== '*' && !empty($months)) {
        $m_translated = $months_map[$months] ?? $months;
        $date_str .= ($date_str ? " de " : "") . $m_translated;
    }

    if ($date_str) {
        return "📅 Data Específica: $date_str ($time_str)";
    }

    $days_str = "Todos os dias";
    if ($w_days !== '*' && !empty($w_days)) {
        if (strpos($w_days, '-') !== false) {
            list($d1, $d2) = explode('-', $w_days);
            $days_str = ($days_map[$d1] ?? $d1) . " a " . ($days_map[$d2] ?? $d2);
        } else {
            $days_arr = explode(',', $w_days);
            $translated = array_map(function($d) use ($days_map) { return $days_map[$d] ?? $d; }, $days_arr);
            $days_str = implode(', ', $translated);
        }
    }

    return "⏰ $days_str: $time_str";
}

/**
 * Lista todos os Grupos de Horários (Time Groups) cadastrados no Asterisk MySQL
 */
function getAsteriskTimeGroupsList($ast_db = null) {
    if (!$ast_db) {
        $ast_db = getAsteriskDBConnection();
        if (!$ast_db) $ast_db = getAsteriskPdoConnection('asterisk');
    }
    $timegroups = [];
    if (!$ast_db) return $timegroups;

    try {
        $rows = fetchAsteriskConfigRows('timegroups_groups', null, [], $ast_db);
        foreach ($rows as $rg) {
            $g_id = $rg['timegroupid'] ?? ($rg['id'] ?? ($rg['timegroups_id'] ?? ''));
            $g_desc = $rg['description'] ?? ($rg['displayname'] ?? ($rg['name'] ?? "Grupo #$g_id"));
            
            if ($g_id !== '') {
                $det_rows = fetchAsteriskConfigRows('timegroups_details', $g_id, ['timegroupid', 'id', 'timegroups_id'], $ast_db);
                $count = count($det_rows);

                $timegroups[] = [
                    'id' => (int)$g_id,
                    'name' => $g_desc,
                    'rules_count' => $count
                ];
            }
        }
    } catch (Exception $e) {}

    return $timegroups;
}

/**
 * Lista as regras de um Time Group específico (filtrando estritamente por timegroupid)
 */
function getAsteriskTimeGroupRules($tg_id, $ast_db = null) {
    if (!$ast_db) {
        $ast_db = getAsteriskDBConnection();
        if (!$ast_db) $ast_db = getAsteriskPdoConnection('asterisk');
    }
    $rules = [];
    if (!$ast_db || $tg_id === null || $tg_id === '') return $rules;

    try {
        $det_rows = fetchAsteriskConfigRows('timegroups_details', $tg_id, ['timegroupid', 'id', 'timegroups_id'], $ast_db);
        foreach ($det_rows as $rd) {
            $det_id = $rd['id'] ?? ($rd['timegroupdetailsid'] ?? '');
            $time_rule = $rd['time'] ?? ($rd['times'] ?? ($rd['detail'] ?? ''));

            if (!empty($time_rule)) {
                $rules[] = [
                    'id' => $det_id,
                    'timegroupid' => $tg_id,
                    'rule_raw' => $time_rule,
                    'parsed_label' => parseAsteriskTimeRuleToLabel($time_rule)
                ];
            }
        }
    } catch (Exception $e) {}

    return $rules;
}

/**
 * Salva uma nova regra de horário personalizada para um Time Group específico no Asterisk MySQL
 */
/**
 * Aplica ao Asterisk o que foi gravado no banco do FreePBX (retrieve_conf + dialplan reload, sem reiniciar)
 * e limpa a flag "Apply Config" para o painel do Issabel não ficar pendente.
 */
function applyFreepbxConfig($ast_db = null) {
    if (!function_exists('shell_exec')) return false;
    @shell_exec("timeout 90 php /var/lib/asterisk/bin/retrieve_conf 2>/dev/null");
    @shell_exec("asterisk -rx 'dialplan reload' 2>/dev/null");
    try {
        if (!$ast_db) $ast_db = getAsteriskDBConnection();
        if ($ast_db) $ast_db->exec("UPDATE admin SET value = 'false' WHERE variable = 'need_reload'");
    } catch (Exception $e) {}
    return true;
}

function saveAsteriskTimeGroupRule($tg_id, $tg_name, $time_start, $time_end, $w_days, $m_days, $month, $ast_db = null) {
    if (!$ast_db) {
        $ast_db = getAsteriskDBConnection();
        if (!$ast_db) $ast_db = getAsteriskPdoConnection('asterisk');
    }
    if (!$ast_db) return ['success' => false, 'message' => 'Sem conexão com banco PABX'];

    try {
        if ($tg_id === 'NEW' || empty($tg_id)) {
            $name_clean = trim($tg_name ?: 'Novo Grupo de Horários');
            $stmt_new_g = $ast_db->prepare("INSERT INTO timegroups_groups (description) VALUES (:desc)");
            $stmt_new_g->execute([':desc' => $name_clean]);
            $tg_id = $ast_db->lastInsertId();
        }

        $time_start = trim($time_start ?: '00:00');
        $time_end   = trim($time_end ?: '23:59');
        $t_range    = "$time_start-$time_end";
        if ($time_start === '00:00' && $time_end === '23:59') {
            $t_range = '00:00-23:59';
        }

        $w_days = trim($w_days ?: '*');
        $m_days = trim($m_days ?: '*');
        $month  = trim($month ?: '*');

        $rule_str = "$t_range|$w_days|$m_days|$month";

        $stmt_ins = $ast_db->prepare("INSERT INTO timegroups_details (timegroupid, time) VALUES (:tgid, :rule)");
        $stmt_ins->execute([':tgid' => $tg_id, ':rule' => $rule_str]);

        applyFreepbxConfig($ast_db);

        return [
            'success' => true,
            'message' => "Regra de horário ('$rule_str') adicionada com sucesso ao PABX!",
            'timegroupid' => $tg_id,
            'rule' => $rule_str
        ];
    } catch (Exception $e) {
        return ['success' => false, 'message' => 'Erro ao salvar regra no PABX: ' . $e->getMessage()];
    }
}

/**
 * Remove uma regra de horário específica do Asterisk MySQL
 */
function deleteAsteriskTimeGroupRule($detail_id, $ast_db = null) {
    if (!$ast_db) {
        $ast_db = getAsteriskDBConnection();
        if (!$ast_db) $ast_db = getAsteriskPdoConnection('asterisk');
    }
    if (!$ast_db || !$detail_id) return ['success' => false, 'message' => 'Dados inválidos para remoção'];

    try {
        $stmt_del = $ast_db->prepare("DELETE FROM timegroups_details WHERE id = :id");
        $stmt_del->execute([':id' => $detail_id]);

        applyFreepbxConfig($ast_db);

        return ['success' => true, 'message' => 'Regra removida com sucesso do PABX!'];
    } catch (Exception $e) {
        return ['success' => false, 'message' => 'Erro ao deletar regra: ' . $e->getMessage()];
    }
}

/**
 * Lista gravações de áudio e anúncios cadastrados no PABX
 */
function getAsteriskAnnouncementsList($ast_db = null) {
    if (!$ast_db) {
        $ast_db = getAsteriskDBConnection();
        if (!$ast_db) $ast_db = getAsteriskPdoConnection('asterisk');
    }
    $announcements = [];
    if (!$ast_db) return $announcements;

    try {
        $stmt = $ast_db->query("SELECT * FROM announcement");
        if ($stmt) {
            while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                $ann_id = $row['announcement_id'] ?? ($row['id'] ?? '');
                $desc = $row['description'] ?? ($row['name'] ?? "Anúncio #$ann_id");
                $rec_id = $row['recording_id'] ?? ($row['recording'] ?? '');

                $audio_file = "";
                if ($rec_id) {
                    $rec_row = fetchAsteriskConfigRow('recordings', $rec_id, ['id', 'recording_id'], $ast_db);
                    if ($rec_row) {
                        $audio_file = $rec_row['filename'] ?? ($rec_row['displayname'] ?? '');
                    }
                }

                $announcements[] = [
                    'id' => $ann_id,
                    'name' => $desc,
                    'recording_id' => $rec_id,
                    'audio_file' => $audio_file
                ];
            }
        }
    } catch (Exception $e) {}

    return $announcements;
}

/**
 * Lista todas as gravações de voz cadastradas no PABX
 */
function getAsteriskRecordingsList($ast_db = null) {
    if (!$ast_db) {
        $ast_db = getAsteriskDBConnection();
        if (!$ast_db) $ast_db = getAsteriskPdoConnection('asterisk');
    }
    $recordings = [];
    if (!$ast_db) return $recordings;

    try {
        $stmt = $ast_db->query("SELECT * FROM recordings");
        if ($stmt) {
            while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                $rec_id = $row['id'] ?? ($row['recording_id'] ?? '');
                $name   = $row['displayname'] ?? ($row['filename'] ?? "Gravação #$rec_id");
                $file   = $row['filename'] ?? '';
                if ($rec_id) {
                    $recordings[] = [
                        'id' => $rec_id,
                        'name' => $name,
                        'filename' => $file
                    ];
                }
            }
        }
    } catch (Exception $e) {}

    return $recordings;
}

/**
 * Atualiza o áudio da mensagem de um Anúncio no PABX
 */
function updateAsteriskAnnouncementAudio($ann_id, $rec_id, $ast_db = null) {
    if (!$ast_db) {
        $ast_db = getAsteriskDBConnection();
        if (!$ast_db) $ast_db = getAsteriskPdoConnection('asterisk');
    }
    if (!$ast_db || !$ann_id || !$rec_id) return ['success' => false, 'message' => 'Parâmetros inválidos'];

    try {
        $stmt = $ast_db->prepare("UPDATE announcement SET recording_id = :recid WHERE announcement_id = :annid");
        $stmt->execute([':recid' => $rec_id, ':annid' => $ann_id]);

        applyFreepbxConfig($ast_db);

        return ['success' => true, 'message' => 'Mensagem de áudio atualizada com sucesso no PABX!'];
    } catch (Exception $e) {
        return ['success' => false, 'message' => 'Erro ao atualizar áudio: ' . $e->getMessage()];
    }
}

/**
 * Sincroniza e cria/atualiza os Custom Destinations no MySQL do Asterisk (asterisk.custom_destinations)
 * Espelhando 100% as regras e templates de mensagens do IPbx Prisma com o PABX Asterisk.
 */
function syncCustomDestinationsWithAsterisk() {
    $ast_db = function_exists('getAsteriskPdoConnection') ? getAsteriskPdoConnection('asterisk') : null;
    if (!$ast_db) {
        return ['success' => false, 'message' => 'Não foi possível conectar ao banco de dados MySQL do PABX.'];
    }

    // 1. Inspecionar colunas reais da tabela custom_destinations no MySQL do PABX
    $cols = [];
    try {
        $stmt_cols = $ast_db->query("DESCRIBE custom_destinations");
        while ($r = $stmt_cols->fetch(PDO::FETCH_ASSOC)) {
            $f = $r['Field'] ?? ($r['field'] ?? reset($r));
            if ($f) $cols[] = strtolower($f);
        }
    } catch (Exception $e1) {
        try {
            $stmt_cols = $ast_db->query("SELECT * FROM custom_destinations LIMIT 1");
            for ($i = 0; $i < $stmt_cols->columnCount(); $i++) {
                $meta = $stmt_cols->getColumnMeta($i);
                if (!empty($meta['name'])) $cols[] = strtolower($meta['name']);
            }
        } catch (Exception $e2) {}
    }

    if (empty($cols)) {
        return ['success' => false, 'message' => 'Tabela de destinos personalizados não encontrada no MySQL do PABX.'];
    }

    // Identificar a coluna chave de destino (custom_dest, custom_destination, destination, target, dest, id)
    $pk_col = null;
    foreach (['custom_dest', 'custom_destination', 'destination', 'target', 'dest', 'id'] as $c) {
        if (in_array($c, $cols)) { $pk_col = $c; break; }
    }

    // Identificar a coluna de descrição (description, desc, name, displayname)
    $desc_col = null;
    foreach (['description', 'desc', 'name', 'displayname'] as $c) {
        if (in_array($c, $cols)) { $desc_col = $c; break; }
    }

    // Identificar a coluna de notas (notes, note, comment)
    $notes_col = null;
    foreach (['notes', 'note', 'comment'] as $c) {
        if (in_array($c, $cols)) { $notes_col = $c; break; }
    }

    if (!$pk_col || !$desc_col) {
        return ['success' => false, 'message' => 'Estrutura incompatível na tabela custom_destinations. Colunas encontradas no PABX: [' . implode(', ', $cols) . ']'];
    }

    $msg_no_answer  = (function_exists('getRule') && getRule('queue_abandon_msg_default')) ? getRule('queue_abandon_msg_default') : (getSetting('prismabot_msg_no_answer') ?: 'Olá! Vimos que você tentou ligar para nosso setor {NOME_FILA} no número {CLIENTE} às {DATA_HORA} e não conseguiu aguardar. Como podemos te ajudar por aqui?');
    $msg_nps        = (function_exists('getRule') && getRule('nps_msg')) ? getRule('nps_msg') : (getSetting('prismabot_msg_nps') ?: 'Olá! Obrigado por seu contato com o atendente {ATENDENTE} no número {CLIENTE} às {DATA_HORA}. De 1 a 5, qual nota você dá para o atendimento recebido?');
    $msg_transbordo = (function_exists('getRule') && getRule('queue_exit_whatsapp_msg')) ? getRule('queue_exit_whatsapp_msg') : (getSetting('prismabot_msg_transbordo') ?: 'Olá! Vimos que você optou por não aguardar na linha da fila {NOME_FILA} no número {CLIENTE} às {DATA_HORA}. Já iniciamos seu atendimento VIP por aqui!');
    $msg_audio      = (function_exists('getRule') && getRule('audio_msg')) ? getRule('audio_msg') : (getSetting('prismabot_msg_audio') ?: 'Olá! O atendente {ATENDENTE} (Ramal {RAMAL}) está temporariamente indisponível. Recebemos sua chamada do número {CLIENTE} às {DATA_HORA}. Deixe sua mensagem por aqui!');

    $destinations = [
        [
            'custom_destination' => 'ext-prisma-no-answer,s,1',
            'description'        => 'Prisma - Notificação Chamada Perdida',
            'notes'              => $msg_no_answer
        ],
        [
            'custom_destination' => 'ext-prisma-nps,s,1',
            'description'        => 'Prisma - Pesquisa de Satisfação NPS',
            'notes'              => $msg_nps
        ],
        [
            'custom_destination' => 'ext-prisma-transbordo,s,1',
            'description'        => 'Prisma - Transbordo de Atendimento',
            'notes'              => $msg_transbordo
        ],
        [
            'custom_destination' => 'ext-prisma-audio,s,1',
            'description'        => 'Prisma - Envio de Áudio de Gravação',
            'notes'              => $msg_audio
        ]
    ];

    $updated = 0;
    try {
        if ($notes_col) {
            $sql = "INSERT INTO custom_destinations ({$pk_col}, {$desc_col}, {$notes_col})
                    VALUES (:cd, :desc, :notes)
                    ON DUPLICATE KEY UPDATE {$desc_col} = VALUES({$desc_col}), {$notes_col} = VALUES({$notes_col})";
        } else {
            $sql = "INSERT INTO custom_destinations ({$pk_col}, {$desc_col})
                    VALUES (:cd, :desc)
                    ON DUPLICATE KEY UPDATE {$desc_col} = VALUES({$desc_col})";
        }

        $stmt = $ast_db->prepare($sql);
        foreach ($destinations as $dest) {
            $params = [
                ':cd'   => $dest['custom_destination'],
                ':desc' => $dest['description']
            ];
            if ($notes_col) {
                $params[':notes'] = $dest['notes'];
            }
            $stmt->execute($params);
            $updated++;
        }

        if (function_exists('shell_exec')) {
            @shell_exec("php /var/lib/asterisk/bin/retrieve_conf 2>/dev/null");
            @shell_exec("asterisk -rx 'dialplan reload' 2>/dev/null");
            @shell_exec("asterisk -rx 'module reload' 2>/dev/null");
        }
        try {
            $ast_db->exec("UPDATE admin SET value = 'false' WHERE variable = 'need_reload'");
        } catch (Exception $e_flag) {}

        try {
            $ast_db->exec("ALTER TABLE featurecodes MODIFY defaultcode VARCHAR(150)");
            $ast_db->exec("ALTER TABLE featurecodes MODIFY customcode VARCHAR(150)");
        } catch (Exception $e_fc) {}

        return [
            'success' => true,
            'message' => "{$updated} Custom Destinations sincronizados com sucesso no PABX!",
            'count'   => $updated
        ];
    } catch (Exception $e) {
        return [
            'success' => false,
            'message' => 'Erro ao gravar em custom_destinations: ' . $e->getMessage() . ' | Colunas no PABX: [' . implode(', ', $cols) . ']'
        ];
    }
}

/**
 * Busca dados customizados do ramal na tabela local extensions_config (Ex: whatsapp_number, notify_agent_missed, agent_name)
 */
function getExtensionCustomData($ext) {
    global $db;
    if (!$db && function_exists('getDBConnection')) {
        $db = getDBConnection();
    }
    if (!$db) return null;
    try {
        $stmt = $db->prepare("SELECT * FROM extensions_config WHERE extension = :e LIMIT 1");
        $stmt->execute([':e' => (string)$ext]);
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    } catch (Exception $e) {
        return null;
    }
}

/**
 * Envia uma mensagem via REST API Z-PRO (Prismabot) com cURL seguro
 */
function sendWhatsAppMessageViaZPro($api_url, $api_token, $number, $message, $call_id = '', $extension = 'AGI', $rule_type = 'AGI_ZPRO') {
    if (waProvider() === 'uazapi') {
        $u = uazapiSendText($number, $message);
        if (function_exists('pabx_log')) {
            pabx_log('whatsapp', $u['ok'] ? 'INFO' : 'ERROR', "Envio AGI UAZAPI para " . preg_replace('/\D/', '', (string)$number) . " [HTTP {$u['http']}]", ['error' => $u['error'], 'response' => substr($u['resp'], 0, 300)]);
        }
        try {
            global $db;
            if ($db) {
                $db->prepare("INSERT INTO sent_logs (phone, message, status, call_id, extension, rule_type, response_raw, created_at) VALUES (:p, :m, :s, :c, :e, :r, :rr, CURRENT_TIMESTAMP)")->execute([
                    ':p' => preg_replace('/\D/', '', (string)$number), ':m' => $message, ':s' => $u['ok'] ? 'SUCCESS' : 'ERROR',
                    ':c' => $call_id !== '' ? $call_id : ('AGI_' . time()), ':e' => $extension, ':r' => $rule_type,
                    ':rr' => substr($u['resp'] !== '' ? $u['resp'] : $u['error'], 0, 1000)
                ]);
            }
        } catch (Exception $e_log) {}
        return $u['ok'];
    }
    if (empty($api_url) || empty($api_token) || empty($number) || empty($message)) {
        return false;
    }

    $clean_num = preg_replace('/\D/', '', $number);
    if (empty($clean_num)) return false;

    if (strlen($clean_num) === 10 || strlen($clean_num) === 11) {
        $clean_num = '55' . $clean_num;
    }

    $clean_token = trim(preg_replace('/^bearer\s+/i', '', trim($api_token)));

    // Payload Z-PRO / ZDG (campo "body" conforme docs/ZPRO_API_REFERENCE.md)
    $body = [
        'number'         => $clean_num,
        'body'           => $message,
        'externalKey'    => 'PRISMA_' . time(),
        'isClosed'       => false,
        'validateNumber' => true,
        'options'        => ['delay' => 1200],
    ];
    $whatsapp_id = getSetting('prismabot_whatsapp_id');
    if (!empty($whatsapp_id)) {
        $body['whatsappId'] = is_numeric($whatsapp_id) ? intval($whatsapp_id) : $whatsapp_id;
    }
    $payload = json_encode($body, JSON_UNESCAPED_UNICODE);

    $ch = curl_init($api_url);
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => $payload,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER     => [
            'Content-Type: application/json',
            'Authorization: Bearer ' . $clean_token
        ],
        CURLOPT_TIMEOUT        => 10,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_SSL_VERIFYHOST => false
    ]);

    $resp = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    $ok = ($http_code >= 200 && $http_code < 300);
    if ($ok) {
        $decoded = json_decode((string)$resp, true);
        if (is_array($decoded) && isset($decoded['success']) && $decoded['success'] === false) {
            $ok = false;
        }
    }

    if (function_exists('pabx_log')) {
        pabx_log('whatsapp', $ok ? 'INFO' : 'ERROR', "Envio AGI Prismabot para {$clean_num} [HTTP {$http_code}]", [
            'payload' => $payload,
            'response' => $resp
        ]);
    }

    try {
        global $db;
        if ($db) {
            $stmt_log = $db->prepare("INSERT INTO sent_logs (phone, message, status, call_id, extension, rule_type, response_raw, created_at) VALUES (:p, :m, :s, :c, :e, :r, :rr, CURRENT_TIMESTAMP)");
            $stmt_log->execute([
                ':p' => $clean_num,
                ':m' => $message,
                ':s' => $ok ? 'SUCCESS' : 'ERROR',
                ':c' => $call_id !== '' ? $call_id : ('AGI_' . time()),
                ':e' => $extension,
                ':r' => $rule_type,
                ':rr' => substr((string)$resp, 0, 1000)
            ]);
        }
    } catch (Exception $e_log) {
        if (function_exists('pabx_log')) pabx_log('whatsapp', 'ERROR', 'Falha ao gravar o envio no histórico (sent_logs): ' . $e_log->getMessage());
    }

    return $ok;
}

/**
 * Sincroniza o destino opcional do Ramal no MySQL do PABX Asterisk (asterisk.users e findmefollow)
 * @param string $extension Número do ramal (ex: '208')
 * @param int $enable_custom_dest 1 para ativar Custom Destination do Prisma, 0 para restaurar padrão do PABX
 */
function syncExtensionDestinationsWithAsterisk($extension, $enable_custom_dest) {
    $ast_db = function_exists('getAsteriskPdoConnection') ? getAsteriskPdoConnection('asterisk') : null;
    if (!$ast_db) return false;

    $ext_clean = trim($extension);
    if (empty($ext_clean)) return false;

    $dest_target = $enable_custom_dest ? 'ext-prisma-no-answer,s,1' : '';

    try {
        // 1. Tabela asterisk.users (noanswer, busy, chanunavail)
        try {
            $stmt = $ast_db->prepare("UPDATE users SET noanswer = :dest, busy = :dest, chanunavail = :dest WHERE extension = :ext");
            $stmt->execute([':dest' => $dest_target, ':ext' => $ext_clean]);
        } catch (Exception $e1) {}

        // 2. Tabela asterisk.findmefollow (Se o módulo de findmefollow existir para o ramal)
        try {
            $stmt_fmf = $ast_db->prepare("UPDATE findmefollow SET postdest = :dest WHERE extension = :ext");
            $stmt_fmf->execute([':dest' => $enable_custom_dest ? 'ext-prisma-no-answer,s,1' : "ext-local,{$ext_clean},1", ':ext' => $ext_clean]);
        } catch (Exception $e2) {}

        // 3. Recarregar Asterisk e aplicar configurações
        if (function_exists('shell_exec')) {
            @shell_exec("php /var/lib/asterisk/bin/retrieve_conf 2>/dev/null");
            @shell_exec("asterisk -rx 'dialplan reload' 2>/dev/null");
        }
        try {
            $ast_db->exec("UPDATE admin SET value = 'false' WHERE variable = 'need_reload'");
        } catch (Exception $e_flag) {}

        return true;
    } catch (Exception $e) {
        return false;
    }
}

/**
 * Avisa por WhatsApp o cliente (e o supervisor) quando uma chamada é ABANDONADA em fila.
 * Lê asterisk(cdrdb).queue_log a partir do último id processado; na primeira execução só
 * marca o ponto de partida (não envia histórico). Requer queues_config.abandon_enabled = 1.
 * $ast = PDO do banco que contém queue_log. Retorna ['processed'=>n,'sent'=>n,'skipped'=>n,'error'=>string].
 */
function processQueueAbandonNotifications($ast) {
    global $db;
    $out = ['processed' => 0, 'sent' => 0, 'skipped' => 0, 'error' => ''];
    $api_url   = getSetting('api_url');
    $api_token = getSetting('api_token');
    if (empty($api_url) || empty($api_token)) {
        $out['error'] = 'API WhatsApp não configurada.';
        return $out;
    }
    try {
        $last = getSetting('abandon_last_queue_log_id');
        if ($last === '' || $last === null || $last === false) {
            $max = (int)$ast->query("SELECT COALESCE(MAX(id), 0) FROM queue_log")->fetchColumn();
            saveSetting('abandon_last_queue_log_id', (string)$max);
            return $out; // primeira execução: não dispara histórico
        }
        $last = (int)$last;

        $st = $ast->prepare("SELECT id, callid, queuename FROM queue_log WHERE id > :last AND event = 'ABANDON' ORDER BY id ASC LIMIT 200");
        $st->execute([':last' => $last]);
        $events = $st->fetchAll(PDO::FETCH_ASSOC);

        $max_id = $last;
        $blocked = false;   // um envio que falhou segura o cursor para ser tentado de novo (até 3 vezes)
        $advance = function ($id) use (&$max_id, &$blocked) { if (!$blocked) $max_id = max($max_id, (int)$id); };
        $q_enter = $ast->prepare("SELECT data2 FROM queue_log WHERE callid = :c AND event = 'ENTERQUEUE' ORDER BY id ASC LIMIT 1");
        $q_cfg = $db->prepare("SELECT queue_name, abandon_enabled, abandon_msg, supervisor_whatsapp FROM queues_config WHERE queue_number = :q LIMIT 1");
        $now = date('d/m/Y H:i:s');

        foreach ($events as $ev) {
            $out['processed']++;
            $q = (string)$ev['queuename'];
            $q_cfg->execute([':q' => $q]);
            $cfg = $q_cfg->fetch(PDO::FETCH_ASSOC);
            if (!$cfg || empty($cfg['abandon_enabled'])) { $out['skipped']++; $advance($ev['id']); continue; }

            $q_enter->execute([':c' => $ev['callid']]);
            $caller = preg_replace('/\D/', '', (string)$q_enter->fetchColumn());
            if ($caller === '' || strlen($caller) < 8) { $out['skipped']++; $advance($ev['id']); continue; } // sem número externo identificável

            $q_name = !empty($cfg['queue_name']) ? $cfg['queue_name'] : $q;
            $tpl = !empty($cfg['abandon_msg']) ? $cfg['abandon_msg'] : getRule('queue_abandon_msg_default');
            if (empty($tpl)) { $tpl = 'Olá! Vimos que você ligou para o setor {NOME_FILA} às {DATA_HORA} e a chamada não pôde ser atendida. Em breve entraremos em contato!'; }
            $msg = str_replace(['{NOME_FILA}', '{CLIENTE}', '{DATA_HORA}'], [$q_name, $caller, $now], $tpl);

            // Envio ÚNICO por chamada e destinatário; só repete se o envio anterior FALHOU (máx. 3 tentativas)
            $ckey = 'ABAN_' . md5($ev['callid'] . '|' . $q);
            $failed = false;
            if (missedGuardAcquire($ckey, $caller, $q, (string)$ev['callid'], substr($caller, -8))) {
                $ok = sendWhatsAppMessageViaZPro($api_url, $api_token, $caller, $msg, (string)$ev['callid'], $q, 'FILA_ABANDONO');
                missedGuardFinish($ckey, $caller, $ok);
                if ($ok) { $out['sent']++; } else { $out['skipped']++; $failed = true; }
            } else { $out['skipped']++; }

            $sup = preg_replace('/\D/', '', (string)($cfg['supervisor_whatsapp'] ?? ''));
            if ($sup !== '' && missedGuardAcquire($ckey . '_SUP', $sup, $q, (string)$ev['callid'] . '_SUP', substr($sup, -8))) {
                $sup_msg = "⚠️ *Abandono na fila {$q_name}*\n\nCliente *{$caller}* desistiu de aguardar às *{$now}*.";
                $okS = sendWhatsAppMessageViaZPro($api_url, $api_token, $sup, $sup_msg, (string)$ev['callid'] . '_SUP', $q, 'FILA_ABANDONO_SUP');
                missedGuardFinish($ckey . '_SUP', $sup, $okS);
                if (!$okS) $failed = true;
            }
            if ($failed) $blocked = true; else $advance($ev['id']);
        }
        saveSetting('abandon_last_queue_log_id', (string)$max_id);
    } catch (Exception $e) {
        $out['error'] = $e->getMessage();
    }
    return $out;
}


// =========================================================================
// INTEGRAÇÃO COM IA (LLM / STT) - configuração única para todos os módulos
// =========================================================================
function aiProviderDefaults() {
    return [
        'openai'   => ['url' => 'https://api.openai.com/v1',                            'chat' => 'gpt-4o-mini',            'audio' => 'whisper-1'],
        'groq'     => ['url' => 'https://api.groq.com/openai/v1',                       'chat' => 'llama-3.3-70b-versatile', 'audio' => 'whisper-large-v3'],
        'gemini'   => ['url' => 'https://generativelanguage.googleapis.com/v1beta/openai', 'chat' => 'gemini-1.5-flash',     'audio' => ''],
    ];
}

/** Lista os modelos que a chave realmente enxerga (GET /models), com cache de 6h. */
function aiAvailableModels($provider, $key, $baseUrl, $force = false) {
    if ($key === '' || $baseUrl === '') return [];
    $tag = md5($provider . '|' . $baseUrl . '|' . $key);
    $cache = json_decode((string)getSetting('ai_models_cache'), true);
    if (!$force && is_array($cache) && ($cache['tag'] ?? '') === $tag && time() - (int)($cache['t'] ?? 0) < 6 * 3600 && !empty($cache['ids'])) {
        return $cache['ids'];
    }
    $ch = curl_init(rtrim($baseUrl, '/') . '/models');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 8,
        CURLOPT_HTTPHEADER     => ['Authorization: Bearer ' . $key],
        CURLOPT_SSL_VERIFYPEER => getSetting('ai_ssl_insecure') !== '1',
        CURLOPT_SSL_VERIFYHOST => getSetting('ai_ssl_insecure') !== '1' ? 2 : 0,
    ]);
    $resp = curl_exec($ch); $http = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE); curl_close($ch);
    $j = json_decode((string)$resp, true);
    $ids = [];
    if ($http === 200 && !empty($j['data']) && is_array($j['data'])) {
        foreach ($j['data'] as $m) if (!empty($m['id'])) $ids[] = preg_replace('#^models/#', '', $m['id']);
    }
    if ($ids) saveSetting('ai_models_cache', json_encode(['tag' => $tag, 't' => time(), 'ids' => $ids]));
    return $ids;
}

/** Escolhe o melhor modelo disponível ('chat' ou 'audio'); '' se nada servir. */
function aiPickModel($ids, $kind, $preferred = '') {
    if ($preferred !== '' && in_array($preferred, $ids, true)) return $preferred;
    if ($kind === 'audio') {
        foreach (['whisper-large-v3-turbo', 'whisper-large-v3', 'whisper-1'] as $m) if (in_array($m, $ids, true)) return $m;
        foreach ($ids as $id) if (stripos($id, 'whisper') !== false) return $id;
        return '';
    }
    foreach (['llama-3.3-70b-versatile', 'llama-3.1-8b-instant', 'openai/gpt-oss-120b', 'openai/gpt-oss-20b', 'gpt-4o-mini', 'gpt-4.1-mini', 'gpt-4o', 'gemini-2.0-flash', 'gemini-1.5-flash'] as $m) {
        if (in_array($m, $ids, true)) return $m;
    }
    foreach ($ids as $id) {
        if (preg_match('/whisper|guard|tts|orpheus|playai|embed|moderation|dall|image|audio|realtime|transcribe|vision-preview|davinci|babbage/i', $id)) continue;
        return $id;
    }
    return '';
}

/** Funções (papéis) da IA que podem ter provedor/modelo próprios. */
function aiRoles() {
    return [
        'audio'    => 'Transcrição de áudio (gravações)',
        'insights' => 'Copiloto, resumos de chamada e auditoria',
        'chat'     => 'Chat assistente do painel',
    ];
}

/** Chave de API guardada para um provedor (cofre por provedor; cai na chave legada se for do mesmo provedor). */
function aiKeyFor($provider) {
    $k = trim((string)getSetting('ai_key_' . $provider));
    if ($k !== '') return $k;
    $legacy = trim((string)getSetting('ai_api_key'));
    if ($legacy === '') return '';
    $lp = getSetting('ai_provider') ?: 'openai';
    if (strpos($legacy, 'gsk_') === 0) $lp = 'groq';
    elseif (strpos($legacy, 'AIza') === 0) $lp = 'gemini';
    return $lp === $provider ? $legacy : '';
}

/** Provedor/modelo escolhidos para uma função, ou null se a função usa a configuração geral. */
/** Instrução de tom padrão do assistente (usada quando o cliente não define a sua). */
function aiDefaultTone() {
    return 'Você é o assistente do IPbx Prisma, sistema de telefonia e atendimento. Responda sempre em português do Brasil, de forma cordial, clara e objetiva, sem jargão técnico. Baseie-se apenas nos dados reais do sistema; se não souber ou não tiver o dado, diga isso em vez de inventar.';
}

/** Chave mascarada para exibir (início e fim visíveis, resto oculto). '' se não houver chave. */
function aiMaskKey($key) {
    $key = trim((string)$key);
    if ($key === '') return '';
    $n = strlen($key);
    if ($n <= 10) return str_repeat('•', $n);
    return substr($key, 0, 4) . str_repeat('•', 12) . substr($key, -4);
}

/** Estado da última verificação da chave de um provedor: ['ok'=>bool,'t'=>ts,'msg'=>string] ou null. */
function aiKeyStatus($provider) {
    $j = json_decode((string)getSetting('ai_key_status_' . $provider), true);
    return is_array($j) ? $j : null;
}

/** Verifica a chave de um provedor consultando a lista de modelos dele (não gasta tokens) e guarda o resultado. */
function aiTestProviderKey($provider) {
    $defs = aiProviderDefaults();
    if (!isset($defs[$provider])) return ['ok' => false, 'msg' => 'Provedor desconhecido'];
    $key = aiKeyFor($provider);
    if ($key === '') return ['ok' => false, 'msg' => 'Sem chave'];
    $ch = curl_init(rtrim($defs[$provider]['url'], '/') . '/models');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 10,
        CURLOPT_HTTPHEADER     => ['Authorization: Bearer ' . $key],
        CURLOPT_SSL_VERIFYPEER => getSetting('ai_ssl_insecure') !== '1',
        CURLOPT_SSL_VERIFYHOST => getSetting('ai_ssl_insecure') !== '1' ? 2 : 0,
    ]);
    $resp = curl_exec($ch); $http = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE); $err = curl_error($ch); curl_close($ch);
    if ($http === 200) { $r = ['ok' => true, 'msg' => 'Chave aprovada']; }
    elseif ($http === 401 || $http === 403) { $r = ['ok' => false, 'msg' => 'Chave recusada pelo provedor (confira se foi colada inteira)']; }
    elseif ($http === 0) { $r = ['ok' => false, 'msg' => 'Sem conexão com o provedor' . ($err !== '' ? ': ' . $err : '')]; }
    else { $r = ['ok' => false, 'msg' => 'Provedor respondeu HTTP ' . $http]; }
    saveSetting('ai_key_status_' . $provider, json_encode(['ok' => $r['ok'], 't' => time(), 'msg' => $r['msg']]));
    return $r;
}

/** Provedor automático de uma função: o primeiro que tiver chave guardada (transcrição só com Groq/OpenAI). */
function aiAutoProvider($role) {
    $order = $role === 'audio' ? ['groq', 'openai'] : ['groq', 'openai', 'gemini'];
    foreach ($order as $p) if (aiKeyFor($p) !== '') return $p;
    return '';
}

function aiRoleChoice($role) {
    if ($role === '' || !isset(aiRoles()[$role])) return null;
    $p = trim((string)getSetting("ai_role_{$role}_provider"));
    if ($p === '' || !isset(aiProviderDefaults()[$p])) {
        // "Automático": mantém a configuração geral antiga se existir; senão usa a primeira chave guardada
        if (trim((string)getSetting('ai_api_key')) !== '') return null;
        $p = aiAutoProvider($role);
        if ($p === '') return null;
    }
    $k = aiKeyFor($p);
    if ($k === '') return null;
    return ['provider' => $p, 'key' => $k, 'model' => trim((string)getSetting("ai_role_{$role}_model"))];
}

function aiGetConfig($role = '') {
    $defs     = aiProviderDefaults();
    $provider = getSetting('ai_provider') ?: 'openai';
    if (!isset($defs[$provider])) $provider = 'openai';   // provedor que não existe mais
    $key      = trim((string)getSetting('ai_api_key'));
    $mChat    = getSetting('ai_model_chat');
    $mAudio   = getSetting('ai_model_audio');
    $roleSel  = aiRoleChoice($role);
    if ($roleSel) {
        // Função com provedor próprio: usa a chave desse provedor e o modelo escolhido para a função
        $legacyProv = $provider;
        $provider = $roleSel['provider'];
        $key      = $roleSel['key'];
        $mChat    = ($role === 'audio') ? '' : $roleSel['model'];
        $mAudio   = ($role === 'audio') ? $roleSel['model'] : '';
        $legacyBase = ($legacyProv === $provider) ? trim((string)getSetting('ai_base_url')) : '';
    }
    // A chave identifica o provedor: evita enviar chave Groq/Gemini ao endpoint da OpenAI
    $byKey = null;
    if (strpos($key, 'gsk_') === 0)      $byKey = 'groq';
    elseif (strpos($key, 'AIza') === 0)  $byKey = 'gemini';
    if ($byKey && $byKey !== $provider) {
        $provider = $byKey;
        $mChat = $mAudio = '';   // modelos salvos eram de outro provedor
    }
    $d        = $defs[$provider] ?? $defs['openai'];
    // Modelo salvo de outro provedor (ex.: whisper-1/gpt-* com chave Groq) é ignorado
    if ($provider === 'groq') {
        if ($mAudio !== '' && !preg_match('/whisper/i', $mAudio) || $mAudio === 'whisper-1') $mAudio = '';
        if ($mChat !== '' && preg_match('/^(gpt-|o1|o3|gemini|claude)/i', $mChat)) $mChat = '';
    } elseif ($provider === 'openai') {
        if ($mChat !== '' && preg_match('/^(llama|mixtral|gemma|gemini|qwen)/i', $mChat)) $mChat = '';
        if ($mAudio !== '' && preg_match('/large-v3/i', $mAudio)) $mAudio = '';
    }
    $limit    = (int)(getSetting('ai_token_limit') ?: 1024);
    $baseUrl  = rtrim(($byKey ? '' : ($roleSel ? $legacyBase : trim((string)getSetting('ai_base_url')))) ?: $d['url'], '/');
    $mChat    = $mChat ?: $d['chat'];
    $mAudio   = $mAudio ?: $d['audio'];
    // Se o modelo escolhido não existe para esta chave, usa o melhor disponível (a API informa a lista)
    if ($key !== '') {
        $ids = aiAvailableModels($provider, $key, $baseUrl);
        if ($ids) {
            if (!in_array($mChat, $ids, true))  $mChat  = aiPickModel($ids, 'chat', '') ?: $mChat;
            if ($mAudio !== '' && !in_array($mAudio, $ids, true)) $mAudio = aiPickModel($ids, 'audio', '') ?: $mAudio;
            if ($mAudio === '' && in_array($provider, ['openai', 'groq'], true)) $mAudio = aiPickModel($ids, 'audio', '');
        }
    }
    return [
        'provider' => $provider,
        'key'      => $key,
        'base_url' => $baseUrl,
        'model'    => $mChat,
        'audio'    => $mAudio,
        'tokens'   => max(64, min(4000, $limit ?: 1024)),
        'prompt'   => (trim((string)getSetting('ai_custom_prompt')) !== '' ? trim((string)getSetting('ai_custom_prompt')) : aiDefaultTone()),
        'enabled'  => getSetting('enable_copilot') !== '0',
    ];
}

/** Chat completion compatível com OpenAI. Retorna ['ok','text','error','http']. */
function aiChatCompletion($system, $user, $timeout = 25, $cfg = null) {
    $cfg = $cfg ?: aiGetConfig('insights');
    if ($cfg['key'] === '') return ['ok' => false, 'text' => '', 'error' => 'API Key de IA não configurada', 'http' => 0];
    if ($cfg['prompt'] !== '') $system = $cfg['prompt'] . "\n\n" . $system;
    $send = function ($tokenField) use ($cfg, $system, $user, $timeout) {
        $ch = curl_init($cfg['base_url'] . '/chat/completions');
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => json_encode([
                'model'      => $cfg['model'],
                'messages'   => [['role' => 'system', 'content' => $system], ['role' => 'user', 'content' => $user]],
                $tokenField  => $cfg['tokens'],
            ]),
            CURLOPT_HTTPHEADER     => ['Content-Type: application/json', 'Authorization: Bearer ' . $cfg['key']],
            CURLOPT_TIMEOUT        => $timeout,
            CURLOPT_SSL_VERIFYPEER => getSetting('ai_ssl_insecure') !== '1',
            CURLOPT_SSL_VERIFYHOST => getSetting('ai_ssl_insecure') !== '1' ? 2 : 0,
        ]);
        $resp = curl_exec($ch);
        $out  = ['body' => $resp, 'http' => (int)curl_getinfo($ch, CURLINFO_HTTP_CODE), 'err' => curl_error($ch)];
        curl_close($ch);
        return $out;
    };
    $r = $send('max_tokens');
    $j = json_decode((string)$r['body'], true);
    // Modelos novos (o-series/gpt-5) exigem max_completion_tokens
    if ($r['http'] === 400 && stripos((string)($j['error']['message'] ?? ''), 'max_completion_tokens') !== false) {
        $r = $send('max_completion_tokens');
        $j = json_decode((string)$r['body'], true);
    }
    if ($r['err'] !== '') {
        return ['ok' => false, 'text' => '', 'error' => 'Erro de conexão: ' . $r['err'], 'http' => 0];
    }
    if ($r['http'] === 200 && isset($j['choices'][0]['message']['content'])) {
        return ['ok' => true, 'text' => trim($j['choices'][0]['message']['content']), 'error' => '', 'http' => 200];
    }
    return ['ok' => false, 'text' => '', 'error' => $j['error']['message'] ?? ('HTTP ' . $r['http']), 'http' => $r['http']];
}

/** Extrai a lista "insights" de uma resposta do LLM (aceita cercas ```json e texto em volta). */
function aiParseInsights($txt) {
    $txt = trim((string)$txt);
    $txt = preg_replace('/^```(?:json)?\s*/i', '', $txt);
    $txt = preg_replace('/\s*```$/', '', $txt);
    $p = json_decode($txt, true);
    if (!is_array($p) && preg_match('/\{.*\}/s', $txt, $m)) $p = json_decode($m[0], true);
    if (!is_array($p)) return [];
    $list = $p['insights'] ?? (array_values($p) === $p ? $p : []);
    $out = [];
    foreach ((array)$list as $i) {
        if (is_scalar($i) && trim((string)$i) !== '') $out[] = trim((string)$i);
    }
    return array_slice($out, 0, 5);
}

/** Métricas reais de hoje no CDR do Asterisk. */
function aiTodayMetrics() {
    $m = ['total' => 0, 'atendidas' => 0, 'tma' => 0, 'tme' => 0, 'ok' => false];
    $pdo = function_exists('getAsteriskPdoConnection') ? getAsteriskPdoConnection('asteriskcdrdb') : null;
    if (!$pdo) return $m;
    try {
        $r = $pdo->query("SELECT COUNT(*) t, SUM(disposition='ANSWERED') a, AVG(CASE WHEN disposition='ANSWERED' THEN billsec END) tma, AVG(GREATEST(duration-billsec,0)) tme FROM cdr WHERE calldate >= CURRENT_DATE()")->fetch(PDO::FETCH_ASSOC);
        $m = ['total' => (int)$r['t'], 'atendidas' => (int)$r['a'], 'tma' => (int)round((float)$r['tma']), 'tme' => (int)round((float)$r['tme']), 'ok' => true];
    } catch (Exception $e) {}
    return $m;
}

/** Insights factuais (sem IA) calculados somente a partir das métricas. */
function aiFactInsights($tot, $atend = null, $tma = null, $tme = null) {
    if ($tot <= 0) return ['Nenhuma chamada registrada no período.'];
    if ($atend === null) $out = ["Volume: $tot chamadas no período."];
    else { $pct = round(($atend / $tot) * 100, 1); $out = ["Volume: $tot chamadas, $atend atendidas ($pct%) e " . ($tot - $atend) . " não atendidas."]; }
    if ($tma !== null || $tme !== null) {
        $parts = [];
        if ($tma !== null) $parts[] = 'TMA (conversa): ' . ($tma > 0 ? gmdate('i\m s\s', $tma) : '0s');
        if ($tme !== null) $parts[] = 'TME (espera): ' . ($tme > 0 ? gmdate('i\m s\s', $tme) : '0s');
        $out[] = implode(' | ', $parts) . '.';
    }
    return $out;
}

function aiSaveHistory($provider, $model, $insights, $module = 'Dashboard', $period = '') {
    global $db;
    if (!$db) return;
    try {
        $db->prepare("INSERT INTO ai_insights_history (provider, model, insights_json, module, period) VALUES (:p, :m, :j, :mo, :pe)")
           ->execute([':p' => $provider, ':m' => $model, ':j' => json_encode($insights, JSON_UNESCAPED_UNICODE), ':mo' => $module, ':pe' => $period]);
    } catch (Exception $e) {}
}

// =========================================================================
// LIMITE DE TOQUE POR RAMAL (FreePBX: AMPUSER/<ramal>/ringtimer no AstDB)
// =========================================================================
function applyExtensionRingtime($ext, $secs) {
    $ext  = preg_replace('/\D/', '', (string)$ext);
    $secs = max(0, min(300, (int)$secs));
    if ($ext === '') return ['success' => false, 'error' => 'Ramal inválido'];
    $r = executeAsteriskAMICommand('DBPut', ['Family' => 'AMPUSER', 'Key' => "$ext/ringtimer", 'Val' => (string)$secs]);
    if (!empty($r['success']) && stripos($r['response'] ?? '', 'Response: Success') !== false) {
        return ['success' => true];
    }
    return ['success' => false, 'error' => $r['error'] ?? trim(preg_replace('/\s+/', ' ', (string)($r['response'] ?? 'Falha no AMI')))];
}

// =========================================================================
// LINK ASSINADO TEMPORÁRIO PARA GRAVAÇÕES (HMAC; não exige sessão do painel)
// =========================================================================
function audioLinkSecret() {
    $s = getSetting('audio_link_secret');
    if (empty($s)) { $s = bin2hex(random_bytes(32)); saveSetting('audio_link_secret', $s); }
    return $s;
}

function audioSigValid($uid, $exp, $sig) {
    $exp = (int)$exp;
    if ($exp < time() || $uid === '' || $sig === '') return false;
    return hash_equals(hash_hmac('sha256', $uid . '|' . $exp, audioLinkSecret()), (string)$sig);
}

/** Retorna '' se "URL pública do painel" não estiver configurada. */
function audioSignedUrl($uid, $ttl = 604800, $onlyConfigured = false) {
    $base = rtrim((string)getSetting('public_base_url'), '/');
    if ($base === '' && !$onlyConfigured && PHP_SAPI !== 'cli' && !empty($_SERVER['HTTP_HOST'])) {
        // Sem URL pública cadastrada: usa o endereço pelo qual o usuário acessou o painel agora
        $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
        $dir = rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '/front_api/index.php')), '/');
        if (preg_match('/^[A-Za-z0-9.\-:\[\]]+$/', $_SERVER['HTTP_HOST'])) $base = $scheme . '://' . $_SERVER['HTTP_HOST'] . $dir;
    }
    if ($base === '') return '';
    $exp = time() + max(60, (int)$ttl);
    $sig = hash_hmac('sha256', $uid . '|' . $exp, audioLinkSecret());
    return $base . '/pabx_panel/get_audio.php?uid=' . rawurlencode($uid) . '&exp=' . $exp . '&sig=' . $sig;
}

/** Localiza o arquivo de gravação (somente dentro de /var/spool/asterisk/monitor). */
function findRecordingPath($recordingfile, $calldate) {
    $rec = basename((string)$recordingfile);
    if ($rec === '' || $rec === '.' || $rec === '..') return '';
    $t = strtotime($calldate) ?: time();
    $root = '/var/spool/asterisk/monitor';
    $cands = ["$root/$rec", $root . '/' . date('Y/m/d', $t) . "/$rec", $root . '/' . date('Y/m', $t) . "/$rec"];
    foreach (glob("$root/*/*/*/$rec") ?: [] as $g) $cands[] = $g;
    foreach ($cands as $c) {
        $rp = realpath($c);
        if ($rp !== false && is_file($rp) && filesize($rp) > 0 && strpos($rp, $root . '/') === 0) return $rp;
    }
    return '';
}

// =========================================================================
// TRANSCRIÇÃO (Whisper OpenAI/Groq) E RESUMO DE CHAMADA
// =========================================================================
function aiTranscribeFile($path, $cfg = null) {
    $cfg = $cfg ?: aiGetConfig('audio');
    if (!$cfg['enabled'])  return ['ok' => false, 'text' => '', 'error' => 'Copiloto de IA desativado'];
    if ($cfg['key'] === '') return ['ok' => false, 'text' => '', 'error' => 'API Key de IA não configurada'];
    if (!in_array($cfg['provider'], ['openai', 'groq'], true) || empty($cfg['audio'])) {
        return ['ok' => false, 'text' => '', 'error' => "O provedor '{$cfg['provider']}' não oferece transcrição (use OpenAI ou Groq)"];
    }
    if (!is_file($path) || filesize($path) > 24 * 1024 * 1024) {
        return ['ok' => false, 'text' => '', 'error' => 'Arquivo ausente ou maior que 24MB'];
    }
    // Whisper só aceita estas extensões; gravações do Asterisk podem ser .gsm/.wav49/.sln/.WAV etc.
    $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
    $okExt = ['flac','mp3','mp4','mpeg','mpga','m4a','ogg','opus','wav','webm'];
    $sendPath = $path; $tmp = '';
    $needConv = !in_array($ext, $okExt, true);
    if (!$needConv && $ext === 'wav') {
        // WAV em GSM/formato exótico: valida o cabeçalho (PCM=1); senão reconverte
        $h = (string)@file_get_contents($path, false, null, 0, 24);
        if (strlen($h) < 24 || substr($h, 0, 4) !== 'RIFF' || unpack('v', substr($h, 20, 2))[1] !== 1) $needConv = true;
    }
    if ($needConv || $path !== strtolower($path) && $ext !== pathinfo($path, PATHINFO_EXTENSION)) {
        $tmp = sys_get_temp_dir() . '/ai_rec_' . bin2hex(random_bytes(6)) . '.wav';
        $in = escapeshellarg($path); $o = escapeshellarg($tmp);
        // Formatos de gravação do Asterisk/Issabel. Os "raw" (sem cabeçalho) exigem parâmetros explícitos.
        $raw = [
            'ulaw' => '-f mulaw -ar 8000 -ac 1', 'pcm' => '-f mulaw -ar 8000 -ac 1', 'ul' => '-f mulaw -ar 8000 -ac 1',
            'mu' => '-f mulaw -ar 8000 -ac 1', 'alaw' => '-f alaw -ar 8000 -ac 1', 'al' => '-f alaw -ar 8000 -ac 1',
            'sln' => '-f s16le -ar 8000 -ac 1', 'raw' => '-f s16le -ar 8000 -ac 1',
            'sln12' => '-f s16le -ar 12000 -ac 1', 'sln16' => '-f s16le -ar 16000 -ac 1', 'sln24' => '-f s16le -ar 24000 -ac 1',
            'sln32' => '-f s16le -ar 32000 -ac 1', 'sln44' => '-f s16le -ar 44100 -ac 1', 'sln48' => '-f s16le -ar 48000 -ac 1',
            'sln96' => '-f s16le -ar 96000 -ac 1', 'sln192' => '-f s16le -ar 192000 -ac 1',
            'g722' => '-f g722', 'gsm' => '-f gsm -ar 8000 -ac 1',
            'vox' => '-f oki_adpcm -ar 8000 -ac 1', 'ilbc' => '-f ilbc', 'g726' => '-f g726 -ar 8000 -ac 1',
            'g729' => '-f g729', 'au' => '', 'snd' => '', 'wav49' => '-f gsm -ar 8000 -ac 1',
        ];
        $sx = ['ulaw' => '-t ul -r 8000 -c 1', 'pcm' => '-t ul -r 8000 -c 1', 'alaw' => '-t al -r 8000 -c 1',
               'sln' => '-t raw -r 8000 -e signed -b 16 -c 1', 'sln16' => '-t raw -r 16000 -e signed -b 16 -c 1',
               'gsm' => '-t gsm -r 8000 -c 1', 'wav49' => '-t gsm -r 8000 -c 1'];
        $cmds = [];
        $fi = $raw[$ext] ?? '';
        $cmds[] = "ffmpeg -nostdin -y -loglevel error $fi -i $in -ar 16000 -ac 1 -c:a pcm_s16le $o 2>&1";
        if (isset($sx[$ext])) $cmds[] = "sox {$sx[$ext]} $in -r 16000 -c 1 -b 16 $o 2>&1";
        $cmds[] = "sox $in -r 16000 -c 1 -b 16 $o 2>&1";
        foreach ($cmds as $c) {
            @shell_exec($c);
            if (is_file($tmp) && filesize($tmp) > 1000) break;
        }
        if (!is_file($tmp) || filesize($tmp) <= 1000) {
            if (is_file($tmp)) @unlink($tmp);
            if ($needConv) return ['ok' => false, 'text' => '', 'error' => "Formato .$ext não suportado e sem sox/ffmpeg para converter no servidor"];
            $tmp = '';   // só extensão em maiúsculas: envia com nome normalizado
        } else { $sendPath = $tmp; }
    }
    if ($filesizeTmp = (is_file($sendPath) ? filesize($sendPath) : 0) and $filesizeTmp > 24 * 1024 * 1024) {
        if ($tmp) @unlink($tmp);
        return ['ok' => false, 'text' => '', 'error' => 'Áudio maior que 24MB após conversão'];
    }
    $sendExt  = pathinfo($sendPath, PATHINFO_EXTENSION);
    $sendName = preg_replace('/[^A-Za-z0-9_-]/', '_', pathinfo($path, PATHINFO_FILENAME)) . '.' . strtolower($sendExt);
    $mime = (strtolower($sendExt) === 'mp3') ? 'audio/mpeg' : 'audio/wav';
    $path = $sendPath;
    $ch = curl_init($cfg['base_url'] . '/audio/transcriptions');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => ['file' => new CURLFile($path, $mime, $sendName), 'model' => $cfg['audio']],
        CURLOPT_HTTPHEADER     => ['Authorization: Bearer ' . $cfg['key']],
        CURLOPT_TIMEOUT        => 90,
        CURLOPT_SSL_VERIFYPEER => (getSetting('ai_ssl_insecure') !== '1'),
    ]);
    $resp = curl_exec($ch);
    $http = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err  = curl_error($ch);
    curl_close($ch);
    if ($tmp !== '' && is_file($tmp)) @unlink($tmp);
    if ($err !== '') return ['ok' => false, 'text' => '', 'error' => 'Erro cURL: ' . $err];
    $j = json_decode((string)$resp, true);
    if ($http === 200 && isset($j['text'])) return ['ok' => true, 'text' => trim($j['text']), 'error' => ''];
    return ['ok' => false, 'text' => '', 'error' => $j['error']['message'] ?? "HTTP $http"];
}

/**
 * Cron: ao fim de chamadas atendidas e gravadas dos ramais com "Resumo IA" e/ou "Gravação",
 * envia WhatsApp ao atendente (whatsapp_number do ramal) com o resumo e/ou link assinado.
 */
function processCallSummaryNotifications($cdr) {
    global $db;
    $out = ['processed' => 0, 'sent' => 0, 'skipped' => 0, 'error' => ''];
    $api_url = getSetting('api_url'); $api_token = getSetting('api_token');
    if (empty($api_url) || empty($api_token)) { $out['error'] = 'API WhatsApp não configurada.'; return $out; }

    // Primeira execução: só passa a valer para chamadas dali em diante
    $since = getSetting('summary_since');
    if (empty($since)) { saveSetting('summary_since', date('Y-m-d H:i:s')); return $out; }

    $cfgs = [];
    foreach ($db->query("SELECT extension, agent_name, whatsapp_number, send_ai_summary, send_call_recording FROM extensions_config WHERE (send_ai_summary = 1 OR send_call_recording = 1) AND whatsapp_number <> ''")->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $cfgs[$r['extension']] = $r;
    }
    if (!$cfgs) return $out;

    try {
        $st = $cdr->prepare("SELECT uniqueid, calldate, src, dst, billsec, duration, recordingfile FROM cdr
            WHERE calldate >= :since AND calldate >= (NOW() - INTERVAL 3 HOUR) AND disposition = 'ANSWERED' AND billsec >= 15
              AND recordingfile <> '' AND (calldate + INTERVAL duration SECOND) <= (NOW() - INTERVAL 30 SECOND)
            ORDER BY calldate ASC LIMIT 100");
        $st->execute([':since' => $since]);
        $calls = $st->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e) { $out['error'] = $e->getMessage(); return $out; }

    $dup = $db->prepare("SELECT COUNT(*) FROM sent_logs WHERE call_id = :c AND extension = :e AND rule_type = 'RESUMO_CHAMADA' AND status IN ('SUCCESS','IGNORADO')");
    $ins = $db->prepare("INSERT INTO sent_logs (phone, call_id, extension, rule_type, message, status) VALUES (:p, :c, :e, 'RESUMO_CHAMADA', :m, :s)");
    $cfg = aiGetConfig('insights');
    $cfgAudio = aiGetConfig('audio');

    foreach ($calls as $c) {
        foreach (array_unique([$c['dst'], $c['src']]) as $ext) {
            if (!isset($cfgs[$ext])) continue;
            // Envio ÚNICO por chamada/ramal; só repete se o anterior falhou (máx. 3 tentativas)
            $skey = 'SUMM_' . md5($c['uniqueid'] . '|' . $ext);
            $dup->execute([':c' => $c['uniqueid'], ':e' => $ext]);
            if ((int)$dup->fetchColumn() > 0) continue;   // já enviado (ou ignorado) pelo histórico
            if (!missedGuardAcquire($skey, (string)$cfgs[$ext]['whatsapp_number'], $ext)) continue;
            $out['processed']++;
            $e = $cfgs[$ext];
            $parts = []; $why = [];

            $other = ($ext === $c['dst']) ? $c['src'] : $c['dst'];
            $head = "📞 Chamada " . date('d/m H:i', strtotime($c['calldate'])) . " com $other (" . gmdate('i\m s\s', (int)$c['billsec']) . ")";

            $recFile = null;   // por padrão a gravação vai como ARQUIVO (funciona com IP interno, IP externo, DDNS...)
            if (!empty($e['send_call_recording'])) {
                $rp = findRecordingPath($c['recordingfile'], $c['calldate']);
                if ($rp !== '' && filesize($rp) <= 15 * 1024 * 1024) {
                    $x = strtolower(pathinfo($rp, PATHINFO_EXTENSION));
                    $types = ['wav' => 'audio/wav', 'mp3' => 'audio/mpeg', 'ogg' => 'audio/ogg', 'gsm' => 'audio/x-gsm'];
                    $recFile = ['name' => 'gravacao_' . preg_replace('/[^0-9.]/', '', (string)$c['uniqueid']) . '.' . $x, 'type' => $types[$x] ?? 'application/octet-stream', 'content' => file_get_contents($rp)];
                    $parts[] = "🎧 Gravação da chamada em anexo.";
                } else {
                    // arquivo ausente ou grande demais: tenta o link, só se a URL pública estiver cadastrada
                    $url = audioSignedUrl($c['uniqueid'], 604800, true);
                    if ($url !== '') $parts[] = "🎧 Gravação (link válido por 7 dias): $url";
                    else $why[] = $rp === '' ? 'arquivo de gravação não encontrado' : 'gravação maior que 15MB e URL pública não configurada (Configurações > API)';
                }
            }
            if (!empty($e['send_ai_summary'])) {
                $path = findRecordingPath($c['recordingfile'], $c['calldate']);
                if ($path === '') { $why[] = 'arquivo de gravação não encontrado'; }
                else {
                    $tr = aiTranscribeFile($path, $cfgAudio);
                    if (!$tr['ok'] || $tr['text'] === '') { $why[] = 'transcrição: ' . ($tr['error'] ?: 'vazia'); }
                    else {
                        $sm = aiChatCompletion('Resuma a ligação telefônica em português, em no máximo 5 linhas: motivo, o que foi combinado e próximos passos. Use somente o que consta na transcrição; se algo não estiver claro, diga que não ficou claro.', mb_substr($tr['text'], 0, 12000), 40, $cfg);
                        if ($sm['ok'] && $sm['text'] !== '') $parts[] = "🧠 Resumo IA:\n" . $sm['text'];
                        else $why[] = 'resumo: ' . $sm['error'];
                    }
                }
            }

            if (!$parts) {
                $ins->execute([':p' => $e['whatsapp_number'], ':c' => $c['uniqueid'], ':e' => $ext, ':m' => 'Ignorado: ' . implode('; ', $why), ':s' => 'IGNORADO']);
                $out['skipped']++;
                missedGuardFinish($skey, (string)$e['whatsapp_number'], true);
                continue;
            }
            $msg = $head . "\n\n" . implode("\n\n", $parts);
            $ok = sendWhatsAppMessageViaZPro($api_url, $api_token, $e['whatsapp_number'], $msg, $c['uniqueid'], $ext, 'RESUMO_CHAMADA');
            if ($ok && $recFile) { $fr = sendWhatsAppFile($e['whatsapp_number'], $recFile['name'], $recFile['content'], $recFile['type'], '', $c['uniqueid'], $ext, 'RESUMO_CHAMADA_ARQUIVO'); }
            missedGuardFinish($skey, (string)$e['whatsapp_number'], $ok);
            $ok ? $out['sent']++ : $out['skipped']++;
        }
    }
    return $out;
}

// =========================================================================
// AUDITORIA DE VOZ: regras (palavras-chave) configuráveis + análises persistidas
// =========================================================================
function aiAuditEnsureSchema() {
    global $db;
    static $done = false;
    if ($done || !$db) return;
    $db->exec("CREATE TABLE IF NOT EXISTS ia_audit_rules (
        id INTEGER PRIMARY KEY AUTOINCREMENT, kind TEXT NOT NULL, label TEXT NOT NULL,
        keywords TEXT NOT NULL DEFAULT '', enabled INTEGER NOT NULL DEFAULT 1)");
    $db->exec("CREATE TABLE IF NOT EXISTS call_ai_analysis (
        uniqueid TEXT PRIMARY KEY, calldate TEXT, src TEXT, dst TEXT, billsec INTEGER,
        transcript TEXT, resumo TEXT, sentimento TEXT, satisfacao REAL, recomendacao TEXT,
        topics TEXT, risks TEXT, checklist TEXT, qa_score INTEGER, provider TEXT, model TEXT, analyzed_at TEXT)");
    $n = (int)$db->query("SELECT COUNT(*) FROM ia_audit_rules")->fetchColumn();
    if ($n === 0 && getSetting('ia_audit_rules_seeded') !== '1') {
        // Ponto de partida editável (não são dados de chamadas): ajuste na tela "Configurar palavras"
        $seed = [
            ['risk', 'Órgãos de defesa / Jurídico', 'procon, reclame aqui, anatel, advogado, processo, justiça, denunciar, indenização'],
            ['risk', 'Cancelamento / Churn', 'cancelar, cancelamento, encerrar contrato, trocar de operadora, mudar de operadora, concorrente, insatisfeito'],
            ['topic', 'Suporte Técnico', 'suporte, não funciona, nao funciona, problema, erro, configurar, instalar, caiu, sem sinal, lento'],
            ['topic', 'Financeiro', 'fatura, boleto, pagamento, cobrança, cobranca, segunda via, nota fiscal, vencimento'],
            ['topic', 'Vendas', 'contratar, plano, orçamento, orcamento, proposta, comprar, preço, preco, valor'],
            ['checklist', 'Saudação & identificação do atendente', 'bom dia, boa tarde, boa noite, meu nome, falando com'],
            ['checklist', 'Confirmação de dados do cliente', 'cpf, cnpj, confirmar, confirma, nome completo, endereço, endereco, titular'],
            ['checklist', 'Cordialidade', 'por favor, obrigado, obrigada, com licença, disponha, será um prazer, sera um prazer'],
            ['checklist', 'Encerramento & protocolo', 'algo mais, mais alguma, posso ajudar, tenha um bom, protocolo'],
        ];
        $st = $db->prepare("INSERT INTO ia_audit_rules (kind, label, keywords, enabled) VALUES (?,?,?,1)");
        foreach ($seed as $r) $st->execute($r);
        saveSetting('ia_audit_rules_seeded', '1');
    }
    $done = true;
}

function aiAuditRules($kind = null) {
    global $db;
    aiAuditEnsureSchema();
    if (!$db) return [];
    $sql = "SELECT * FROM ia_audit_rules WHERE enabled = 1" . ($kind ? " AND kind = " . $db->quote($kind) : "") . " ORDER BY id";
    return $db->query($sql)->fetchAll(PDO::FETCH_ASSOC);
}

function aiAuditNormalize($t) {
    $t = mb_strtolower((string)$t, 'UTF-8');
    return preg_replace('/\s+/u', ' ', $t);
}

/** Aplica as regras salvas à transcrição. Verificação por palavra-chave no texto inteiro (sem separar quem falou). */
function aiAuditApplyRules($transcript) {
    $t = aiAuditNormalize($transcript);
    $match = function ($rule) use ($t) {
        $hits = [];
        foreach (explode(',', (string)$rule['keywords']) as $kw) {
            $kw = aiAuditNormalize(trim($kw));
            if ($kw !== '' && mb_strpos($t, $kw) !== false) $hits[] = $kw;
        }
        return $hits;
    };
    $out = ['topics' => [], 'risks' => [], 'checklist' => [], 'qa_score' => null];
    foreach (aiAuditRules('topic') as $r)  { $h = $match($r); if ($h) $out['topics'][] = $r['label']; }
    foreach (aiAuditRules('risk') as $r)   { $h = $match($r); if ($h) $out['risks'][]  = ['label' => $r['label'], 'words' => $h]; }
    $cl = aiAuditRules('checklist');
    $ok = 0;
    foreach ($cl as $r) { $h = $match($r); $out['checklist'][$r['label']] = !empty($h); if ($h) $ok++; }
    if ($cl) $out['qa_score'] = (int)round($ok * 100 / count($cl));
    return $out;
}

function aiAuditSaveAnalysis($row, $a, $res, $cfg) {
    global $db;
    aiAuditEnsureSchema();
    if (!$db) return;
    $db->prepare("INSERT OR REPLACE INTO call_ai_analysis
        (uniqueid, calldate, src, dst, billsec, transcript, resumo, sentimento, satisfacao, recomendacao, topics, risks, checklist, qa_score, provider, model, analyzed_at)
        VALUES (:u,:cd,:s,:d,:b,:t,:r,:se,:sa,:re,:to,:ri,:ch,:qa,:p,:m,:at)")->execute([
        ':u' => $res['uid'], ':cd' => $row['calldate'] ?? '', ':s' => $row['src'] ?? '', ':d' => $row['dst'] ?? '', ':b' => (int)($row['billsec'] ?? 0),
        ':t' => $res['transcript'], ':r' => $res['resumo'], ':se' => $res['sentimento'], ':sa' => $res['satisfacao'], ':re' => $res['recomendacao'],
        ':to' => json_encode($a['topics'], JSON_UNESCAPED_UNICODE), ':ri' => json_encode($a['risks'], JSON_UNESCAPED_UNICODE),
        ':ch' => json_encode($a['checklist'], JSON_UNESCAPED_UNICODE), ':qa' => $a['qa_score'],
        ':p' => $cfg['provider'], ':m' => $cfg['model'], ':at' => date('Y-m-d H:i:s'),
    ]);
}


/**
 * Localiza no disco os arquivos de som do Asterisk de uma gravação cadastrada (ex.: "custom/boas-vindas",
 * sem extensão, ou várias partes separadas por "&"). Só devolve arquivos dentro de /var/lib/asterisk/sounds.
 * @return string[] um caminho por parte; [] se alguma parte não existir.
 */
function asteriskSoundResolve($name) {
    $root = @realpath('/var/lib/asterisk/sounds');
    if (!$root || !is_string($name) || $name === '') return [];
    $exts = ['wav', 'WAV', 'sln16', 'g722', 'sln', 'ulaw', 'alaw', 'gsm', 'mp3', 'ogg'];
    $dirs = [$root];
    foreach ((array)@glob($root . '/*', GLOB_ONLYDIR) as $d) $dirs[] = $d;
    $out = [];
    foreach (explode('&', $name) as $part) {
        $part = trim($part);
        if ($part === '' || strpos($part, "\0") !== false || strpos($part, '..') !== false) return [];
        $part = ltrim($part, '/');
        if (strpos($part, 'sounds/') === 0) $part = substr($part, 7);
        $noext = preg_replace('/\.(wav|sln16|sln|g722|ulaw|alaw|gsm|mp3|ogg)$/i', '', $part);
        $found = null;
        foreach ($dirs as $d) {
            foreach ($exts as $e) {
                $c = "$d/$noext.$e";
                if (is_file($c) && is_readable($c) && filesize($c) > 0) {
                    $rp = realpath($c);
                    if ($rp && strpos($rp, $root . '/') === 0) { $found = $rp; break 2; }
                }
            }
        }
        if (!$found) return [];
        $out[] = $found;
    }
    return $out;
}


/**
 * Situação do arquivo de áudio de uma gravação do PABX no servidor (para exibir ao suporte/cliente).
 * @return array ['ok'=>bool, 'path'=>string, 'msg'=>string]
 */
function asteriskSoundStatus($name) {
    $found = asteriskSoundResolve((string)$name);
    if (!empty($found)) return ['ok' => true, 'path' => implode(' + ', $found), 'msg' => ''];
    $root = '/var/lib/asterisk/sounds';
    $user = function_exists('posix_getpwuid') ? (posix_getpwuid(posix_geteuid())['name'] ?? '') : '';
    if (!is_dir($root)) return ['ok' => false, 'path' => '', 'msg' => "Pasta de sons $root não existe neste servidor."];
    $first = trim(explode('&', (string)$name)[0]);
    $noext = preg_replace('/\.(wav|sln16|sln|g722|ulaw|alaw|gsm|mp3|ogg)$/i', '', ltrim($first, '/'));
    foreach (['wav', 'WAV', 'sln16', 'g722', 'sln', 'ulaw', 'alaw', 'gsm', 'mp3', 'ogg'] as $e) {
        $c = "$root/$noext.$e";
        if (file_exists($c) && !is_readable($c)) {
            return ['ok' => false, 'path' => $c, 'msg' => "O arquivo existe ($c), mas o usuário do painel" . ($user ? " ($user)" : '') . " não tem permissão de leitura."];
        }
    }
    $dir = dirname("$root/$noext");
    $hint = '';
    if (is_dir($dir)) {
        $base = strtolower(basename($noext));
        $sim = [];
        foreach ((array)@scandir($dir) as $f) {
            if ($f === '.' || $f === '..') continue;
            $nf = strtolower(pathinfo($f, PATHINFO_FILENAME));
            if ($base !== '' && (strpos($nf, $base) !== false || strpos($base, $nf) !== false)) $sim[] = $f;
        }
        if ($sim) $hint = ' Arquivos parecidos na pasta: ' . implode(', ', array_slice($sim, 0, 5)) . '.';
    } else {
        $hint = " A pasta $dir não existe.";
    }
    return ['ok' => false, 'path' => "$root/$noext.*", 'msg' => "O PABX cadastra a gravação \"$first\", mas o arquivo não foi encontrado em $root/$noext.*." . $hint];
}


/**
 * Blacklist NATIVA do Issabel/FreePBX: família "blacklist" do banco interno do Asterisk (AstDB).
 * É a mesma lista da tela Aplicações > Blacklist do Issabel e a consultada pelo dialplan.
 */
function asteriskBlacklistNormalize($num) {
    $num = trim((string)$num);
    if (strtolower($num) === 'blocked') return 'blocked'; // chamadas sem identificação
    $num = preg_replace('/[^0-9+*#]/', '', $num);
    return (strlen($num) >= 1 && strlen($num) <= 25) ? $num : '';
}

function asteriskBlacklistList() {
    $res = executeAsteriskAMICommand('Command', ['Command' => 'database show blacklist']);
    if (empty($res['success'])) return ['success' => false, 'items' => [], 'error' => $res['error'] ?? 'Falha ao consultar o AMI.'];
    $items = [];
    foreach (preg_split('/\r?\n/', (string)($res['response'] ?? '')) as $line) {
        $line = preg_replace('/^Output:\s*/i', '', trim($line)); // versões recentes prefixam cada linha com "Output:"
        if (preg_match('#^/blacklist/(\S+)\s*:\s*(.*)$#', $line, $m)) {
            $val = trim($m[2]);
            $items[] = ['number' => $m[1], 'description' => ($val === '' || $val === '1') ? 'Bloqueado' : $val];
        }
    }
    return ['success' => true, 'items' => $items, 'error' => ''];
}

function asteriskBlacklistAdd($num, $desc = '') {
    $n = asteriskBlacklistNormalize($num);
    if ($n === '') return ['success' => false, 'error' => 'Número inválido (use apenas dígitos, +, * ou #).'];
    $desc = trim(preg_replace('/[\r\n"]+/', ' ', (string)$desc));
    $res = executeAsteriskAMICommand('DBPut', ['Family' => 'blacklist', 'Key' => $n, 'Val' => ($desc !== '' ? mb_substr($desc, 0, 60) : '1')]);
    if (empty($res['success']) || stripos((string)($res['response'] ?? ''), 'Response: Success') === false) {
        return ['success' => false, 'error' => $res['error'] ?? 'O PABX não confirmou a gravação.'];
    }
    if (function_exists('pabx_log')) pabx_log('user_actions', 'INFO', '[TELEFONIA] Número adicionado à blacklist do PABX', ['numero' => $n]);
    return ['success' => true, 'error' => ''];
}

function asteriskBlacklistDelete($num) {
    $n = asteriskBlacklistNormalize($num);
    if ($n === '') return ['success' => false, 'error' => 'Número inválido.'];
    $res = executeAsteriskAMICommand('DBDel', ['Family' => 'blacklist', 'Key' => $n]);
    if (empty($res['success']) || stripos((string)($res['response'] ?? ''), 'Response: Success') === false) {
        return ['success' => false, 'error' => $res['error'] ?? 'O PABX não confirmou a remoção.'];
    }
    if (function_exists('pabx_log')) pabx_log('user_actions', 'INFO', '[TELEFONIA] Número removido da blacklist do PABX', ['numero' => $n]);
    return ['success' => true, 'error' => ''];
}


/** Diagnóstico (sem senhas) de por que o AMI recusou: usuários testados e leitura dos arquivos de credencial. */
function amiCredentialDiagnosis($creds) {
    $users = [];
    foreach ((array)$creds as $c) { if (!empty($c['user'])) $users[$c['user']] = true; }
    $files = [];
    foreach (['/etc/amportal.conf', '/etc/asterisk/manager_custom.conf', '/etc/asterisk/manager.conf', '/etc/asterisk/manager_additional.conf'] as $f) {
        $files[] = basename($f) . '=' . (!file_exists($f) ? 'não existe' : (is_readable($f) ? 'legível' : 'SEM permissão de leitura'));
    }
    $web = function_exists('posix_getpwuid') ? (posix_getpwuid(posix_geteuid())['name'] ?? '?') : '?';
    return '[Diagnóstico: usuários testados: ' . implode(', ', array_keys($users)) . '. Arquivos: ' . implode('; ', $files) . ". Usuário do painel: $web. Confira em Configurações > AMI.]";
}


/**
 * Cron: avisa por WhatsApp o atendente do ramal quando uma chamada para ele NÃO foi atendida (lida do CDR do Issabel).
 * Respeita as opções do ramal: Perdidas Ext./Int. e Notif. Ocupado. Ao cliente só envia se a regra
 * "missed_client_msg" estiver cadastrada. Não depende de gancho no dialplan do Asterisk.
 */
/**
 * Trava anti-duplicidade para avisos automáticos: garante UM envio por (chamada, destinatário).
 * Retorna true se este processo pode enviar agora. Só libera novo envio se o anterior deu ERRO (máx. 3 tentativas).
 * Se a trava não puder ser gravada, retorna false (prefere não enviar a enviar em duplicidade).
 */
function missedGuardAcquire($key, $recipient, $ext, $legacyCid = '', $phoneTail = '') {
    global $db;
    try {
        if ($legacyCid !== '' && $phoneTail !== '') {
            $lg = $db->prepare("SELECT COUNT(*) FROM sent_logs WHERE call_id = :c AND status = 'SUCCESS' AND phone LIKE :p");
            $lg->execute([':c' => $legacyCid, ':p' => '%' . $phoneTail]);
            if ((int)$lg->fetchColumn() > 0) {
                $db->exec("CREATE TABLE IF NOT EXISTS missed_guard (call_key TEXT NOT NULL, recipient TEXT NOT NULL, ext TEXT, status TEXT DEFAULT 'PENDING', attempts INTEGER DEFAULT 1, updated_at DATETIME DEFAULT CURRENT_TIMESTAMP, PRIMARY KEY (call_key, recipient))");
                $db->prepare("INSERT OR IGNORE INTO missed_guard (call_key, recipient, ext, status) VALUES (:k, :r, :e, 'SUCCESS')")->execute([':k' => $key, ':r' => $recipient, ':e' => $ext]);
                return false;
            }
        }
        $db->exec("CREATE TABLE IF NOT EXISTS missed_guard (call_key TEXT NOT NULL, recipient TEXT NOT NULL, ext TEXT, status TEXT DEFAULT 'PENDING', attempts INTEGER DEFAULT 1, updated_at DATETIME DEFAULT CURRENT_TIMESTAMP, PRIMARY KEY (call_key, recipient))");
        $ins = $db->prepare("INSERT OR IGNORE INTO missed_guard (call_key, recipient, ext, status, attempts) VALUES (:k, :r, :e, 'PENDING', 1)");
        $ins->execute([':k' => $key, ':r' => $recipient, ':e' => $ext]);
        if ($ins->rowCount() === 1) return true;
        // já existe: só tenta de novo se falhou e ainda há tentativas
        $upd = $db->prepare("UPDATE missed_guard SET status = 'PENDING', attempts = attempts + 1, updated_at = CURRENT_TIMESTAMP WHERE call_key = :k AND recipient = :r AND status = 'ERROR' AND attempts < 3");
        $upd->execute([':k' => $key, ':r' => $recipient]);
        return $upd->rowCount() === 1;
    } catch (Exception $e) {
        if (function_exists('pabx_log')) pabx_log('whatsapp', 'ERROR', 'Trava anti-duplicidade indisponível (banco sem escrita?): nada foi enviado. ' . $e->getMessage());
        return false;
    }
}

function missedGuardFinish($key, $recipient, $ok) {
    global $db;
    try {
        $db->prepare("UPDATE missed_guard SET status = :s, updated_at = CURRENT_TIMESTAMP WHERE call_key = :k AND recipient = :r")
           ->execute([':s' => $ok ? 'SUCCESS' : 'ERROR', ':k' => $key, ':r' => $recipient]);
    } catch (Exception $e) {}
}

/**
 * Decide se uma linha de CDR "não atendida" NÃO deve gerar aviso de chamada perdida.
 * - tocou menos que o mínimo configurado (cliente desistiu / nem deu tempo de atender);
 * - a mesma chamada (linkedid) foi atendida por outro ramal (grupo de toque, fila, captura, transferência).
 * Retorna o motivo (texto) ou '' se deve avisar.
 */
function missedCallIgnoreReason($cdr, array $c, $minRing, $checkElsewhere) {
    if (($c['disposition'] ?? '') === 'NO ANSWER' && $minRing > 0 && (int)($c['duration'] ?? 0) < $minRing) {
        return 'Tocou apenas ' . (int)$c['duration'] . 's (mínimo ' . (int)$minRing . 's)';
    }
    if ($checkElsewhere && !empty($c['linkedid'])) {
        try {
            $q = $cdr->prepare("SELECT COUNT(*) FROM cdr WHERE linkedid = :l AND uniqueid <> :u AND disposition = 'ANSWERED' AND dst REGEXP '^[0-9]{2,5}$'");
            $q->execute([':l' => $c['linkedid'], ':u' => $c['uniqueid']]);
            if ((int)$q->fetchColumn() > 0) return 'Chamada atendida por outro ramal';
        } catch (Exception $e) {}
    }
    return '';
}

function processMissedCallNotifications($cdr) {
    global $db;
    $out = ['processed' => 0, 'sent' => 0, 'skipped' => 0, 'error' => ''];
    $api_url = getSetting('api_url') ?: getSetting('prismabot_api_url');
    $api_token = getSetting('api_token') ?: getSetting('prismabot_api_token');
    if (empty($api_url) || empty($api_token)) { $out['error'] = 'API WhatsApp não configurada.'; return $out; }

    // Primeira execução: só vale para chamadas dali em diante
    $since = getSetting('missed_since');
    if (empty($since)) { saveSetting('missed_since', date('Y-m-d H:i:s')); return $out; }

    $cfgs = [];
    foreach ($db->query("SELECT * FROM extensions_config")->fetchAll(PDO::FETCH_ASSOC) as $r) $cfgs[(string)$r['extension']] = $r;
    if (!$cfgs) return $out;

    try {
        $hasLinked = false;
        try { $hasLinked = (bool)$cdr->query("SHOW COLUMNS FROM cdr LIKE 'linkedid'")->fetch(); } catch (Exception $e) {}
        $st = $cdr->prepare("SELECT uniqueid, calldate, src, dst, disposition, duration" . ($hasLinked ? ", linkedid" : "") . " FROM cdr
            WHERE calldate >= :since AND calldate >= (NOW() - INTERVAL 30 MINUTE)
              AND disposition IN ('NO ANSWER', 'BUSY')
              AND dst REGEXP '^[0-9]{2,5}$'
              AND (calldate + INTERVAL duration SECOND) <= (NOW() - INTERVAL 5 SECOND)
            ORDER BY calldate ASC LIMIT 200");
        $st->execute([':since' => $since]);
        $rows = $st->fetchAll(PDO::FETCH_ASSOC);
        $ans = $cdr->prepare("SELECT COUNT(*) FROM cdr WHERE src = :s AND disposition = 'ANSWERED' AND dst <> :d
            AND calldate >= (:c - INTERVAL 2 SECOND) AND calldate <= (:c2 + INTERVAL :dur SECOND + INTERVAL 30 SECOND)");
    } catch (Exception $e) { $out['error'] = $e->getMessage(); return $out; }

    $minRing = getSetting('missed_min_ring_seconds');
    $minRing = ($minRing === '' || !is_numeric($minRing)) ? 10 : max(0, min(60, (int)$minRing));
    $checkElsewhere = getSetting('missed_ignore_answered_elsewhere') !== '0';
    $dup = $db->prepare("SELECT COUNT(*) FROM sent_logs WHERE call_id = :c AND extension = :e AND rule_type = 'PERDIDA_IGNORADA'");
    $data_fmt = 'd/m/Y H:i:s';
    $seen_keys = [];

    foreach ($rows as $c) {
        $ext = (string)$c['dst'];
        if (!isset($cfgs[$ext])) continue;
        $cid = 'MISS_' . $c['uniqueid'];
        // chave do conteúdo (ramal + quem ligou + horário): várias linhas de CDR da mesma chamada contam como UMA
        $ckey = 'MISS_' . md5($ext . '|' . preg_replace('/\D/', '', (string)$c['src']) . '|' . $c['calldate']);
        if (isset($seen_keys[$ckey])) continue;
        $seen_keys[$ckey] = true;
        $dup->execute([':c' => $cid, ':e' => $ext]);
        if ((int)$dup->fetchColumn() > 0) continue;

        // Regras de inteligência: tempo mínimo de toque e atendimento por outro ramal
        $why = missedCallIgnoreReason($cdr, $c, $minRing, $checkElsewhere);
        if ($why !== '') {
            try { $db->prepare("INSERT INTO sent_logs (phone, call_id, extension, rule_type, message, status) VALUES ('', :c, :e, 'PERDIDA_IGNORADA', :m, 'SKIPPED')")
                ->execute([':c' => $cid, ':e' => $ext, ':m' => $why]); } catch (Exception $ex) {}
            $out['skipped']++;
            continue;
        }
        // Chamada atendida por outro ramal (grupo de toque/fila): não é perdida (heurística para CDR sem linkedid)
        try {
            $ans->execute([':s' => $c['src'], ':d' => $ext, ':c' => $c['calldate'], ':c2' => $c['calldate'], ':dur' => (int)$c['duration']]);
            if ((int)$ans->fetchColumn() > 0) continue;
        } catch (Exception $e) {}

        $out['processed']++;
        $e = $cfgs[$ext];
        $caller = preg_replace('/\D/', '', (string)$c['src']);
        if ($caller === '') { $out['skipped']++; continue; }
        $internal = (strlen($caller) <= 5);
        $flag = $internal ? 'notify_agent_internal' : 'notify_agent_missed';
        $when = date($data_fmt, strtotime($c['calldate']));
        $agent_name = !empty($e['agent_name']) ? $e['agent_name'] : "Ramal $ext";
        $did = false;

        if (!empty($e['whatsapp_number']) && !empty($e[$flag])) {
            $tpl = function_exists('getRule') ? getRule('missed_agent_msg') : '';
            if (empty($tpl)) $tpl = "⚠️ *Notificação de Chamada Perdida*\n\nOlá *{ATENDENTE}*! O cliente número *{CLIENTE}* tentou ligar no seu ramal *{RAMAL}* às *{DATA_HORA}* e a chamada não foi atendida.";
            $msg = str_replace(['{CLIENTE}', '{ATENDENTE}', '{RAMAL}', '{DATA_HORA}', '{NOME_FILA}'], [$caller, $agent_name, $ext, $when, 'Atendimento'], $tpl);
            $rcp = substr(preg_replace('/\D/', '', (string)$e['whatsapp_number']), -11);
            if (missedGuardAcquire($ckey, 'A' . $rcp, $ext, $cid, substr($rcp, -9))) {
                $okA = sendWhatsAppMessageViaZPro($api_url, $api_token, $e['whatsapp_number'], $msg, $cid, $ext, 'ATENDENTE_NAO_ATENDEU');
                missedGuardFinish($ckey, 'A' . $rcp, $okA);
                if ($okA) $out['sent']++;
            }
            $did = true;
        }

        // Cliente: só com regra cadastrada; ocupado só se "Notif. Ocupado" estiver ativo no ramal
        if (!$internal && function_exists('getRule') && getRule('missed_client_msg') && ($c['disposition'] !== 'BUSY' || !empty($e['notify_client_busy']))) {
            $msgc = str_replace(['{CLIENTE}', '{NOME_FILA}', '{ATENDENTE}', '{RAMAL}', '{DATA_HORA}'], [$caller, 'Atendimento', $agent_name, $ext, $when], getRule('missed_client_msg'));
            $rcp = substr($caller, -11);
            if (missedGuardAcquire($ckey, 'C' . $rcp, $ext, $cid, substr($rcp, -9))) {
                $okC = sendWhatsAppMessageViaZPro($api_url, $api_token, $caller, $msgc, $cid, $ext, 'ATENDENTE_NAO_ATENDEU');
                missedGuardFinish($ckey, 'C' . $rcp, $okC);
                if ($okC) $out['sent']++;
            }
            $did = true;
        }
        if (!$did) {
            // Nada a enviar (sem WhatsApp no ramal ou opção desligada): registra para não reavaliar
            try { $db->prepare("INSERT INTO sent_logs (phone, call_id, extension, rule_type, message, status) VALUES ('', :c, :e, 'PERDIDA_IGNORADA', :m, 'SKIPPED')")
                ->execute([':c' => $cid, ':e' => $ext, ':m' => !empty($e['whatsapp_number']) ? 'Opção desligada no ramal' : 'Ramal sem WhatsApp cadastrado']); } catch (Exception $ex) {}
            $out['skipped']++;
        }
    }
    return $out;
}

// =========================================================================
// CHAT ASSISTENTE COM IA (ferramentas SOMENTE LEITURA sobre dados reais do PABX)
// =========================================================================
function aiChatAvailable() {
    $c = aiGetConfig('chat');
    return $c['key'] !== '' && $c['enabled'];
}

function aiChatToolDefs() {
    $fn = function ($name, $desc, $props, $req = []) {
        return ['type' => 'function', 'function' => ['name' => $name, 'description' => $desc,
            'parameters' => ['type' => 'object', 'properties' => (object)$props, 'required' => $req]]];
    };
    return [
        $fn('search_calls', 'Lista ligações reais do CDR. Filtre por ramal (extension), número externo (number), status e período.', [
            'extension'   => ['type' => 'string', 'description' => 'Ramal (ex.: 201) que originou ou recebeu'],
            'number'      => ['type' => 'string', 'description' => 'Número de telefone (qualquer parte final) em origem ou destino'],
            'days'        => ['type' => 'integer', 'description' => 'Últimos N dias (1 = hoje). Padrão 1, máx. 90'],
            'disposition' => ['type' => 'string', 'enum' => ['ANSWERED', 'NO ANSWER', 'BUSY', 'FAILED'], 'description' => 'Filtro de status'],
            'limit'       => ['type' => 'integer', 'description' => 'Máx. de linhas (padrão 15, máx. 30)'],
        ]),
        $fn('count_calls_by_number', 'Conta quantas vezes um número ligou/foi chamado nos últimos dias, com total por dia.', [
            'number' => ['type' => 'string', 'description' => 'Número de telefone'],
            'days'   => ['type' => 'integer', 'description' => 'Últimos N dias (padrão 7, máx. 90)'],
        ], ['number']),
        $fn('period_summary', 'Totais do período separados em externas (clientes), internas (ramal a ramal) e todas, com atendidas, não atendidas, TMA (conversa) e TME (espera).', [
            'days' => ['type' => 'integer', 'description' => 'Últimos N dias (1 = hoje). Padrão 1'],
        ]),
        $fn('extensions_status', 'Status em tempo real dos ramais (Livre, Em Chamada, Tocando, Indisponível) com nomes.', []),
        $fn('queues_status', 'Status em tempo real das filas: espera, atendidas, abandonadas e agentes (inclui pausados).', []),
        $fn('recording_link', 'Gera link temporário (7 dias) da gravação de uma chamada pelo uniqueid retornado em search_calls.', [
            'uniqueid' => ['type' => 'string'],
        ], ['uniqueid']),
        $fn('propose_extension_feature', 'Propõe ligar/desligar Não Perturbe (dnd) ou desvio (cf incondicional, cfb ocupado, cfu indisponível) de um ramal. NÃO executa: o usuário precisa clicar em Confirmar. value: para dnd use on/off; para desvios, o número de destino (vazio = desativar).', [
            'extension' => ['type' => 'string', 'description' => 'Ramal (ex.: 201)'],
            'feature'   => ['type' => 'string', 'enum' => ['dnd', 'cf', 'cfb', 'cfu']],
            'value'     => ['type' => 'string', 'description' => 'on/off para dnd; número de destino para desvios; vazio desativa'],
        ], ['extension', 'feature']),
        $fn('send_report', 'Envia por WhatsApp o resumo do período (chamadas, atendidas, TMA, TME) para um número. SÓ chame depois de o usuário confirmar explicitamente o número de destino.', [
            'phone' => ['type' => 'string', 'description' => 'Número com DDD (ex.: 11999998888)'],
            'days'  => ['type' => 'integer', 'description' => 'Últimos N dias (1 = hoje). Padrão 1'],
            'note'  => ['type' => 'string', 'description' => 'Texto opcional a acrescentar ao relatório'],
        ], ['phone']),
    ];
}

function aiChatDays($v, $def) { return max(1, min(90, (int)($v ?: $def))); }

function aiChatExecTool($name, $a) {
    global $db;
    $cdr = getAsteriskPdoConnection('asteriskcdrdb');
    $need = ['search_calls', 'count_calls_by_number', 'period_summary', 'recording_link'];
    if (in_array($name, $need, true) && !$cdr) return ['error' => 'Banco de chamadas (CDR) indisponível.'];
    $since = function ($days) { return date('Y-m-d 00:00:00', strtotime('-' . ($days - 1) . ' days')); };
    $ext2name = [];
    try { foreach ($db->query("SELECT extension, agent_name FROM extensions_config") as $r) $ext2name[$r['extension']] = $r['agent_name']; } catch (Exception $e) {}

    try {
        if ($name === 'search_calls') {
            $days = aiChatDays($a['days'] ?? 1, 1);
            $lim  = max(1, min(30, (int)($a['limit'] ?? 15)));
            $w = ['calldate >= :since']; $p = [':since' => $since($days)];
            if (!empty($a['extension'])) { $w[] = '(src = :e OR dst = :e)'; $p[':e'] = preg_replace('/\D/', '', (string)$a['extension']); }
            if (!empty($a['number']))    { $w[] = '(src LIKE :n OR dst LIKE :n)'; $p[':n'] = '%' . substr(preg_replace('/\D/', '', (string)$a['number']), -9); }
            if (!empty($a['disposition']) && in_array($a['disposition'], ['ANSWERED', 'NO ANSWER', 'BUSY', 'FAILED'], true)) { $w[] = 'disposition = :d'; $p[':d'] = $a['disposition']; }
            $where = implode(' AND ', $w);
            $st = $cdr->prepare("SELECT COUNT(*) t, SUM(disposition='ANSWERED') a FROM cdr WHERE $where"); $st->execute($p); $tot = $st->fetch(PDO::FETCH_ASSOC);
            $st = $cdr->prepare("SELECT calldate, src, dst, disposition, billsec, uniqueid, (recordingfile <> '') AS rec FROM cdr WHERE $where ORDER BY calldate DESC LIMIT $lim");
            $st->execute($p);
            $rows = [];
            foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
                $rows[] = ['data' => $r['calldate'], 'origem' => $r['src'] . (isset($ext2name[$r['src']]) ? ' (' . $ext2name[$r['src']] . ')' : ''),
                    'destino' => $r['dst'] . (isset($ext2name[$r['dst']]) ? ' (' . $ext2name[$r['dst']] . ')' : ''),
                    'status' => $r['disposition'], 'duracao_seg' => (int)$r['billsec'], 'uniqueid' => $r['uniqueid'], 'tem_gravacao' => (bool)$r['rec']];
            }
            return ['periodo_dias' => $days, 'total_encontrado' => (int)$tot['t'], 'atendidas' => (int)$tot['a'], 'mostrando' => count($rows), 'ligacoes' => $rows];
        }
        if ($name === 'count_calls_by_number') {
            $days = aiChatDays($a['days'] ?? 7, 7);
            $n = '%' . substr(preg_replace('/\D/', '', (string)($a['number'] ?? '')), -9);
            if (strlen($n) < 5) return ['error' => 'Número inválido.'];
            $st = $cdr->prepare("SELECT DATE(calldate) d, COUNT(*) t, SUM(disposition='ANSWERED') a FROM cdr WHERE calldate >= :s AND (src LIKE :n OR dst LIKE :n) GROUP BY DATE(calldate) ORDER BY d DESC");
            $st->execute([':s' => $since($days), ':n' => $n]);
            $per = []; $tot = 0; $ans = 0;
            foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) { $per[] = ['dia' => $r['d'], 'chamadas' => (int)$r['t'], 'atendidas' => (int)$r['a']]; $tot += (int)$r['t']; $ans += (int)$r['a']; }
            return ['periodo_dias' => $days, 'total' => $tot, 'atendidas' => $ans, 'por_dia' => $per];
        }
        if ($name === 'period_summary') {
            $days = aiChatDays($a['days'] ?? 1, 1);
            $ext  = "NOT (LENGTH(src) <= 4 AND LENGTH(dst) <= 4)"; // mesma regra do Dashboard: externa = envolve número que não é ramal
            $q = function ($cond) use ($cdr, $since, $days) {
                $st = $cdr->prepare("SELECT COUNT(*) t, SUM(disposition='ANSWERED') a, AVG(CASE WHEN disposition='ANSWERED' THEN billsec END) tma, AVG(GREATEST(duration-billsec,0)) tme FROM cdr WHERE calldate >= :s AND $cond");
                $st->execute([':s' => $since($days)]); $r = $st->fetch(PDO::FETCH_ASSOC);
                return ['total' => (int)$r['t'], 'atendidas' => (int)$r['a'], 'nao_atendidas' => (int)$r['t'] - (int)$r['a'], 'tma_seg' => (int)round((float)$r['tma']), 'tme_seg' => (int)round((float)$r['tme'])];
            };
            $e = $q($ext); $i = $q("NOT ($ext)"); $t = $q('1=1');
            return ['periodo_dias' => $days, 'externas_clientes' => $e, 'internas_ramal_a_ramal' => $i, 'todas' => $t,
                    'total' => $e['total'], 'atendidas' => $e['atendidas'], 'nao_atendidas' => $e['nao_atendidas'], 'tma_seg' => $e['tma_seg'], 'tme_seg' => $e['tme_seg'],
                    'observacao' => 'Os campos total/atendidas/nao_atendidas/tma/tme no nível raiz são das chamadas EXTERNAS (mesmo critério do Dashboard). Sempre diga ao usuário se o número é de externas, internas ou todas.'];
        }
        if ($name === 'extensions_status') {
            $out = []; $live = getAsteriskRealtimeStatuses();
            foreach ($live as $ext => $stt) $out[] = ['ramal' => (string)$ext, 'nome' => $ext2name[$ext] ?? '', 'status' => $stt];
            return ['ramais' => array_slice($out, 0, 80)];
        }
        if ($name === 'queues_status') {
            $out = [];
            foreach (getAsteriskQueuesRealtimeStatus('today') as $q) {
                $mem = [];
                foreach ($q['members'] as $m) $mem[] = ['ramal' => $m['extension'], 'nome' => $m['name'], 'status' => $m['status']];
                $out[] = ['fila' => $q['queue_name'], 'em_espera' => $q['callers_count'], 'atendidas_hoje' => $q['answered_count'], 'abandonadas_hoje' => $q['abandoned_count'], 'agentes' => $mem];
            }
            return ['filas' => $out];
        }
        if ($name === 'propose_extension_feature') {
            return aiProposeFeature($a['extension'] ?? '', strtolower((string)($a['feature'] ?? '')), $a['value'] ?? '');
        }
        if ($name === 'send_report') {
            if (!hasUserPermission('send_whatsapp')) return ['error' => 'Este usuário não tem permissão para enviar WhatsApp.'];
            $ph = preg_replace('/\D/', '', (string)($a['phone'] ?? ''));
            if (strlen($ph) < 10 || strlen($ph) > 13) return ['error' => 'Número inválido. Informe DDD + número.'];
            if (strlen($ph) <= 11) $ph = '55' . $ph;
            $hk = 'ai_report_hour_' . date('YmdH');
            $cnt = (int)getSetting($hk);
            if ($cnt >= 10) return ['error' => 'Limite de 10 envios por hora atingido.'];
            $sm = aiChatExecTool('period_summary', ['days' => $a['days'] ?? 1]);
            if (isset($sm['error'])) return $sm;
            $fmt = function ($s) { return sprintf('%d:%02d', intdiv((int)$s, 60), (int)$s % 60); };
            $msg = "📊 *Relatório PABX* (" . ($sm['periodo_dias'] == 1 ? 'hoje' : 'últimos ' . $sm['periodo_dias'] . ' dias') . ")\n"
                 . "Chamadas: {$sm['total']}\nAtendidas: {$sm['atendidas']}\nNão atendidas: {$sm['nao_atendidas']}\n"
                 . "TMA: " . $fmt($sm['tma_seg']) . " | TME: " . $fmt($sm['tme_seg']);
            $note = trim((string)($a['note'] ?? ''));
            if ($note !== '') $msg .= "\n\n" . mb_substr($note, 0, 500);
            $ok = sendWhatsAppAPI($ph, $msg, 'AI_CHAT', 'GERAL', 'RELATORIO_CHAT');
            if (!$ok) return ['error' => 'Falha ao enviar pelo WhatsApp. Verifique a integração em Status da Integração.'];
            saveSetting($hk, (string)($cnt + 1));
            return ['enviado' => true, 'para' => $ph];
        }
        if ($name === 'recording_link') {
            if (!hasUserPermission('listen_recordings')) return ['error' => 'Este usuário não tem permissão para gravações.'];
            $uid = trim((string)($a['uniqueid'] ?? ''));
            $st = $cdr->prepare("SELECT recordingfile, calldate FROM cdr WHERE uniqueid = :u LIMIT 1"); $st->execute([':u' => $uid]); $r = $st->fetch(PDO::FETCH_ASSOC);
            if (!$r || $r['recordingfile'] === '') return ['error' => 'Chamada sem gravação.'];
            $url = audioSignedUrl($uid);
            return $url === '' ? ['error' => 'URL pública do painel não configurada (Configurações > API).'] : ['link' => $url, 'validade' => '7 dias'];
        }
    } catch (Exception $e) {
        return ['error' => 'Falha na consulta: ' . $e->getMessage()];
    }
    return ['error' => 'Ferramenta desconhecida.'];
}

/** Uma rodada de chat com ferramentas (API compatível com OpenAI). Retorna a mensagem do assistente ou erro. */
function aiChatRaw($messages, $cfg) {
    $send = function ($tokField) use ($messages, $cfg) {
        $ch = curl_init($cfg['base_url'] . '/chat/completions');
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true, CURLOPT_POST => true, CURLOPT_TIMEOUT => 45,
            CURLOPT_POSTFIELDS => json_encode(['model' => $cfg['model'], 'messages' => $messages, 'tools' => aiChatToolDefs(), 'tool_choice' => 'auto', $tokField => max(600, $cfg['tokens'])]),
            CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'Authorization: Bearer ' . $cfg['key']],
            CURLOPT_SSL_VERIFYPEER => getSetting('ai_ssl_insecure') !== '1',
            CURLOPT_SSL_VERIFYHOST => getSetting('ai_ssl_insecure') !== '1' ? 2 : 0,
        ]);
        $resp = curl_exec($ch); $o = ['body' => $resp, 'http' => (int)curl_getinfo($ch, CURLINFO_HTTP_CODE), 'err' => curl_error($ch)];
        curl_close($ch);
        return $o;
    };
    $r = $send('max_tokens'); $j = json_decode((string)$r['body'], true);
    if ($r['http'] === 400 && stripos((string)($j['error']['message'] ?? ''), 'max_completion_tokens') !== false) { $r = $send('max_completion_tokens'); $j = json_decode((string)$r['body'], true); }
    // Limite de uso do provedor (429): espera o tempo indicado (até 15s) e tenta de novo uma vez
    if ($r['http'] === 429) {
        $wait = 8;
        if (preg_match('/try again in\s+([\d.]+)\s*(ms|s)/i', (string)($j['error']['message'] ?? ''), $__w)) $wait = (float)$__w[1] / (strtolower($__w[2]) === 'ms' ? 1000 : 1);
        if ($wait <= 15) {
            usleep((int)(($wait + 1) * 1000000));
            $r = $send('max_tokens'); $j = json_decode((string)$r['body'], true);
        }
    }
    if ($r['err'] !== '') return ['ok' => false, 'error' => 'Erro de conexão: ' . $r['err']];
    if ($r['http'] === 200 && isset($j['choices'][0]['message'])) return ['ok' => true, 'message' => $j['choices'][0]['message']];
    if ($r['http'] === 429) return ['ok' => false, 'error' => 'O provedor de IA atingiu o limite de uso por minuto. Aguarde alguns segundos e tente de novo (planos gratuitos têm limite menor).'];
    return ['ok' => false, 'error' => $j['error']['message'] ?? ('HTTP ' . $r['http'])];
}

function aiChatLog($question, $tools, $ok) {
    global $db;
    if (!$db) return;
    try {
        $db->exec("CREATE TABLE IF NOT EXISTS ai_chat_log (id INTEGER PRIMARY KEY AUTOINCREMENT, username TEXT, question TEXT, tools TEXT, ok INTEGER, created_at DATETIME DEFAULT CURRENT_TIMESTAMP)");
        $u = function_exists('getLoggedUser') ? getLoggedUser() : null;
        $db->prepare("INSERT INTO ai_chat_log (username, question, tools, ok) VALUES (:u,:q,:t,:o)")
           ->execute([':u' => $u['username'] ?? '', ':q' => mb_substr($question, 0, 500), ':t' => implode(',', $tools), ':o' => $ok ? 1 : 0]);
    } catch (Exception $e) {}
}

/** Rede de segurança: o chat nunca exibe nomes de terceiros; usa os nomes comerciais (IPbx Prisma / Prismabot). */
function aiBrandScrub($t) {
    $t = preg_replace('/\b(issabel|elastix|freepbx)\b/iu', 'IPbx Prisma', $t);
    $t = preg_replace('/\bz-?pro\b/iu', 'Prismabot', $t);
    $t = preg_replace('/\basterisk\b/iu', 'sistema', $t);
    return $t;
}

/** Remove links que a IA não recebeu de uma ferramenta (evita endereços inventados). */
function aiLinkScrub($text, array $allowed) {
    $ok = function ($u) use ($allowed) { return in_array(rtrim($u, '.,;)'), $allowed, true); };
    $text = preg_replace_callback('/\[([^\]]*)\]\((https?:\/\/[^\s)]+)\)/i', function ($m) use ($ok) { return $ok($m[2]) ? $m[0] : $m[1]; }, $text);
    $text = preg_replace_callback('/(?<![\(\"\'])\bhttps?:\/\/[^\s)<>\"\']+/i', function ($m) use ($ok) { return $ok($m[0]) ? $m[0] : '(link indisponível)'; }, $text);
    return $text;
}

function aiChatUser() {
    $u = function_exists('getLoggedUser') ? getLoggedUser() : null;
    $n = trim((string)($u['username'] ?? ''));
    return $n !== '' ? $n : 'painel';
}
function aiHistoryEnsure() {
    global $db;
    $db->exec("CREATE TABLE IF NOT EXISTS ai_chat_history (id INTEGER PRIMARY KEY AUTOINCREMENT, username TEXT, role TEXT, content TEXT, created_at DATETIME DEFAULT CURRENT_TIMESTAMP, archived INTEGER DEFAULT 0)");
    try { $db->exec("ALTER TABLE ai_chat_history ADD COLUMN archived INTEGER DEFAULT 0"); } catch (Exception $e) {}
    $db->exec("CREATE TABLE IF NOT EXISTS ai_pending_actions (id TEXT PRIMARY KEY, username TEXT, ext TEXT, field TEXT, value TEXT, resumo TEXT, status TEXT, created_at INTEGER)");
}
function aiHistoryLoad($limit = 30) {
    global $db;
    try {
        aiHistoryEnsure();
        $st = $db->prepare("SELECT role, content FROM ai_chat_history WHERE username = ? AND archived = 0 ORDER BY id DESC LIMIT " . (int)$limit);
        $st->execute([aiChatUser()]);
        return array_reverse($st->fetchAll(PDO::FETCH_ASSOC));
    } catch (Exception $e) { return []; }
}
function aiHistorySave($role, $content) {
    global $db;
    if (!in_array($role, ['user', 'assistant'], true) || trim((string)$content) === '') return;
    try {
        aiHistoryEnsure();
        $u = aiChatUser();
        $db->prepare("INSERT INTO ai_chat_history (username, role, content) VALUES (?,?,?)")->execute([$u, $role, mb_substr((string)$content, 0, 6000)]);
        // mantém as últimas 2000 mensagens do usuário
        $db->prepare("DELETE FROM ai_chat_history WHERE username = ? AND id NOT IN (SELECT id FROM ai_chat_history WHERE username = ? ORDER BY id DESC LIMIT 2000)")->execute([$u, $u]);
    } catch (Exception $e) {}
}
function aiHistoryClear() {
    global $db;
    // "Nova conversa" só esconde para o usuário; o registro continua disponível para o administrador
    try { aiHistoryEnsure(); $db->prepare("UPDATE ai_chat_history SET archived = 1 WHERE username = ?")->execute([aiChatUser()]); } catch (Exception $e) {}
}
/** Cria uma proposta pendente (NÃO executa). Retorna ['id'=>..., 'resumo'=>...] ou ['error'=>...]. */
function aiProposeFeature($ext, $field, $value) {
    global $db;
    if (!hasUserPermission('manage_settings')) return ['error' => 'Este usuário não tem permissão para alterar ramais.'];
    $ext = preg_replace('/\D/', '', (string)$ext);
    $names = ['dnd' => 'Não Perturbe', 'cf' => 'Desvio incondicional', 'cfb' => 'Desvio quando ocupado', 'cfu' => 'Desvio quando indisponível'];
    if (!isset($names[$field])) return ['error' => 'Recurso inválido. Use dnd, cf, cfb ou cfu.'];
    aiHistoryEnsure();
    $known = $db->prepare("SELECT agent_name FROM extensions_config WHERE extension = ?");
    $known->execute([$ext]);
    $row = $known->fetch(PDO::FETCH_ASSOC);
    if ($ext === '' || !$row) return ['error' => "Ramal $ext não encontrado."];
    $v = trim((string)$value);
    if ($field === 'dnd') {
        $on = in_array(strtolower($v), ['1', 'on', 'sim', 'ativar', 'yes', 'true'], true);
        $v = $on ? '1' : '';
        $resumo = ($on ? 'Ativar' : 'Desativar') . " Não Perturbe no ramal $ext";
    } else {
        $v = preg_replace('/[^0-9*#+]/', '', $v);
        $resumo = $v === '' ? "Desativar {$names[$field]} do ramal $ext" : "Ativar {$names[$field]} do ramal $ext para $v";
    }
    if (!empty($row['agent_name'])) $resumo .= ' (' . $row['agent_name'] . ')';
    $id = bin2hex(random_bytes(8));
    $db->prepare("INSERT INTO ai_pending_actions (id, username, ext, field, value, resumo, status, created_at) VALUES (?,?,?,?,?,?,'pending',?)")
       ->execute([$id, aiChatUser(), $ext, $field, $v, $resumo, time()]);
    $GLOBALS['__ai_proposals'][] = ['id' => $id, 'resumo' => $resumo];
    return ['proposta_criada' => true, 'resumo' => $resumo, 'instrucao' => 'NADA foi alterado ainda. Diga ao usuário para clicar em Confirmar no cartão exibido abaixo da sua resposta.'];
}
/** Confirma ou cancela uma proposta do próprio usuário (válida por 10 min). */
function aiProposalResolve($id, $accept) {
    global $db;
    if (!hasUserPermission('manage_settings')) return ['success' => false, 'error' => 'Sem permissão.'];
    aiHistoryEnsure();
    $st = $db->prepare("SELECT * FROM ai_pending_actions WHERE id = ? AND username = ? AND status = 'pending'");
    $st->execute([(string)$id, aiChatUser()]);
    $p = $st->fetch(PDO::FETCH_ASSOC);
    if (!$p) return ['success' => false, 'error' => 'Proposta não encontrada ou já resolvida.'];
    if (time() - (int)$p['created_at'] > 600) {
        $db->prepare("UPDATE ai_pending_actions SET status = 'expired' WHERE id = ?")->execute([$p['id']]);
        return ['success' => false, 'error' => 'Proposta expirada. Peça novamente.'];
    }
    if (!$accept) {
        $db->prepare("UPDATE ai_pending_actions SET status = 'cancelled' WHERE id = ?")->execute([$p['id']]);
        return ['success' => true, 'message' => 'Cancelado. Nada foi alterado.'];
    }
    $r = setExtensionFeature($p['ext'], $p['field'], $p['field'] === 'dnd' ? (bool)$p['value'] : $p['value']);
    $db->prepare("UPDATE ai_pending_actions SET status = ? WHERE id = ?")->execute([!empty($r['success']) ? 'done' : 'failed', $p['id']]);
    if (function_exists('logUserAction')) logUserAction('Chat IA: ' . $p['resumo'], !empty($r['success']) ? 'confirmado' : ('falhou: ' . ($r['error'] ?? '')), 'CONFIG');
    return !empty($r['success']) ? ['success' => true, 'message' => '✅ ' . $p['resumo'] . ' — feito.'] : ['success' => false, 'error' => $r['error'] ?? 'Falha ao aplicar.'];
}

/** Executa a conversa: $history = [['role'=>'user|assistant','content'=>...], ...]. */
function aiChatRun($history) {
    $cfg = aiGetConfig('chat');
    if ($cfg['key'] === '' || !$cfg['enabled']) return ['ok' => false, 'error' => 'IA não configurada. Configure em Configurações > Inteligência Artificial.'];
    $sys = "Você é o assistente do IPbx Prisma, a central telefônica da Prisma Telecom. Hoje é " . date('d/m/Y H:i') . ". "
         . "Responda em português, curto e objetivo. Para QUALQUER pergunta sobre chamadas, ramais, filas, números ou gravações, consulte SEMPRE uma ferramenta antes de responder; nunca responda de memória nem estime. Use SOMENTE dados retornados pelas ferramentas; nunca invente números, ramais ou ligações. Quando falar de quantidade de chamadas, deixe claro se são externas (clientes), internas (ramal a ramal) ou todas: por padrão fale das externas, como o Dashboard, e cite as internas à parte. Se nenhuma ferramenta responder, diga que não encontrou o dado. "
         . "Se faltar informação (ex.: qual ramal), pergunte. Você só consulta; as únicas ações são: send_report (resumo por WhatsApp, só após o usuário confirmar o número) e propose_extension_feature (apenas PROPÕE; o usuário confirma por um botão e você nunca diz que foi feito antes disso). Para tabelas use markdown. Para gravações use recording_link e entregue o link em markdown [Ouvir gravação](url). "
         . "Nunca cite nomes de softwares de terceiros da central telefônica nem do serviço de WhatsApp: o produto se chama IPbx Prisma e o serviço de WhatsApp se chama Prismabot. Se precisar falar da tecnologia, diga apenas \"o sistema\" ou \"a central\". "
         . "NUNCA invente links ou endereços: use somente links devolvidos pelas ferramentas (ex.: recording_link). Se a ferramenta devolver erro, explique o erro ao usuário. "
         . "Se o usuário pedir algo fora do escopo do PABX, explique educadamente o que você pode fazer.";
    if ($cfg['prompt'] !== '') $sys = $cfg['prompt'] . "\n\n" . $sys;
    $msgs = [['role' => 'system', 'content' => $sys]];
    foreach (array_slice($history, -12) as $h) {
        if (in_array($h['role'] ?? '', ['user', 'assistant'], true) && is_string($h['content'] ?? null)) $msgs[] = ['role' => $h['role'], 'content' => mb_substr($h['content'], 0, 2000)];
    }
    $used = [];
    $allowed = [];
    foreach ($history as $h) {
        if (($h['role'] ?? '') === 'assistant' && preg_match_all('/https?:\/\/[^\s)<>"\']+/i', (string)($h['content'] ?? ''), $um)) foreach ($um[0] as $u) $allowed[] = rtrim($u, '.,;)');
    }
    for ($i = 0; $i < 5; $i++) {
        $r = aiChatRaw($msgs, $cfg);
        if (!$r['ok']) return ['ok' => false, 'error' => $r['error'], 'tools' => $used];
        $m = $r['message'];
        if (empty($m['tool_calls'])) return ['ok' => true, 'text' => aiLinkScrub(aiBrandScrub(trim(preg_replace('#<think>.*?</think>#is', '', (string)($m['content'] ?? '')))), $allowed), 'tools' => $used];
        $msgs[] = ['role' => 'assistant', 'content' => $m['content'] ?? '', 'tool_calls' => $m['tool_calls']];
        foreach ($m['tool_calls'] as $tc) {
            $nm = $tc['function']['name'] ?? ''; $args = json_decode($tc['function']['arguments'] ?? '{}', true) ?: [];
            $used[] = $nm;
            $res = aiChatExecTool($nm, $args);
            if (is_array($res) && !empty($res['link'])) $allowed[] = $res['link'];
            $msgs[] = ['role' => 'tool', 'tool_call_id' => $tc['id'] ?? '', 'content' => mb_substr(json_encode($res, JSON_UNESCAPED_UNICODE), 0, 6000)];
        }
    }
    return ['ok' => false, 'error' => 'A consulta ficou longa demais; tente reformular a pergunta.', 'tools' => $used];
}

// =========================================================================
// AUTOATENDIMENTO DO RAMAL: Não Perturbe e Desvios (AstDB - o mesmo que a GUI nativa do Issabel/FreePBX lê)
// =========================================================================
/** Retorna ext => ['dnd'=>bool,'cf'=>'','cfb'=>'','cfu'=>''] lendo 'database show' do Asterisk. */
function getExtensionFeatureStates() {
    $out = [];
    foreach (['DND' => 'dnd', 'CF' => 'cf', 'CFB' => 'cfb', 'CFU' => 'cfu'] as $fam => $field) {
        $raw = asteriskCliOutput('database show ' . $fam);
        foreach (preg_split('/\r?\n/', $raw) as $ln) {
            if (preg_match('#^/' . $fam . '/(\d+)\s*:\s*(.*)$#', trim($ln), $m)) {
                $v = trim($m[2]);
                if (!isset($out[$m[1]])) $out[$m[1]] = ['dnd' => false, 'cf' => '', 'cfb' => '', 'cfu' => ''];
                $out[$m[1]][$field] = ($field === 'dnd') ? (strtoupper($v) === 'YES') : $v;
            }
        }
    }
    return $out;
}

/** Aplica DND/CF/CFB/CFU de um ramal no AstDB via AMI (valor vazio remove a chave). */
function setExtensionFeature($ext, $field, $value) {
    $ext = preg_replace('/\D/', '', (string)$ext);
    $map = ['dnd' => 'DND', 'cf' => 'CF', 'cfb' => 'CFB', 'cfu' => 'CFU'];
    if ($ext === '' || !isset($map[$field])) return ['success' => false, 'error' => 'Parâmetro inválido.'];
    if ($field === 'dnd') $value = $value ? 'YES' : '';
    else $value = preg_replace('/[^0-9*#+]/', '', (string)$value);
    if ($value === '') $r = executeAsteriskAMICommand('DBDel', ['Family' => $map[$field], 'Key' => $ext]);
    else               $r = executeAsteriskAMICommand('DBPut', ['Family' => $map[$field], 'Key' => $ext, 'Val' => $value]);
    $resp = (string)($r['response'] ?? '');
    // DBDel de chave inexistente também é aceitável (já estava limpo)
    if (!empty($r['success']) && (stripos($resp, 'Response: Success') !== false || ($value === '' && stripos($resp, 'Response:') !== false))) return ['success' => true];
    return ['success' => false, 'error' => $r['error'] ?? (preg_match('/Message:\s*(.+)/i', $resp, $m) ? trim($m[1]) : 'Falha no AMI')];
}

// =========================================================================
// ALERTA DE TRONCO FORA DO AR (WhatsApp ao supervisor) - roda no cron a cada minuto
// =========================================================================
function trunkAlertNumbers() {
    $out = [];
    foreach (preg_split('/[,;\r\n]+/', (string)getSetting('trunk_alert_numbers')) as $n) {
        $n = preg_replace('/\D/', '', $n);
        if (strlen($n) >= 10) $out[] = $n;
    }
    return array_values(array_unique($out));
}

/** Verifica os troncos e avisa UMA vez quando cair (após 2 verificações seguidas) e UMA vez quando voltar. */
function checkTrunkAlerts() {
    global $db;
    $res = ['checked' => 0, 'down' => 0, 'alerts' => 0, 'error' => ''];
    if (getSetting('trunk_alert_enabled') !== '1') { $res['error'] = 'desativado'; return $res; }
    $nums = trunkAlertNumbers();
    if (!$nums) { $res['error'] = 'sem números cadastrados'; return $res; }
    $db->exec("CREATE TABLE IF NOT EXISTS trunk_alert_state (trunk TEXT PRIMARY KEY, status TEXT, bad_count INTEGER DEFAULT 0, down_notified INTEGER DEFAULT 0, since DATETIME)");
    try { $db->exec("ALTER TABLE trunk_alert_state ADD COLUMN last_alert INTEGER DEFAULT 0"); } catch (Exception $e) {}
    $cooldown = 900;   // mesmo tronco: no máximo 1 aviso de queda a cada 15 min (linha instável não gera enxurrada)
    $trunks = getAsteriskTrunksStatus();
    $send = function ($msg, $tag) use ($nums) {
        $ok = false;
        foreach ($nums as $n) { $r = sendWhatsAppAPI($n, $msg, 'TRUNK_' . $tag . '_' . date('YmdHis'), 'TRONCO', 'ALERTA_TRONCO'); if (!empty($r['success'])) $ok = true; }
        return $ok;
    };
    foreach ($trunks as $t) {
        $name = (string)$t['name']; $st = (string)$t['status'];
        if ($st !== 'Registrado' && $st !== 'Desconectado') continue;   // "Sem verificação": não alerta
        $res['checked']++;
        $q = $db->prepare("SELECT * FROM trunk_alert_state WHERE trunk = :t"); $q->execute([':t' => $name]); $row = $q->fetch(PDO::FETCH_ASSOC);
        if (!$row) { $db->prepare("INSERT INTO trunk_alert_state (trunk, status, bad_count, down_notified, since) VALUES (:t,:s,0,0,CURRENT_TIMESTAMP)")->execute([':t' => $name, ':s' => $st]); $row = ['bad_count' => 0, 'down_notified' => 0, 'since' => date('Y-m-d H:i:s')]; }
        if ($st === 'Desconectado') {
            $res['down']++;
            $bad = (int)$row['bad_count'] + 1;
            $notified = (int)$row['down_notified'];
            if ($bad >= 2 && !$notified && (time() - (int)($row['last_alert'] ?? 0)) >= $cooldown) {
                if ($send("🚨 *Tronco fora do ar*\nLinha: {$name} ({$t['tech']})\nDesde: " . date('d/m H:i') . "\nVerifique o provedor ou a rede.", 'DOWN')) { $notified = 1; $res['alerts']++; $db->prepare("UPDATE trunk_alert_state SET last_alert=:l WHERE trunk=:t")->execute([':l' => time(), ':t' => $name]); }
                // se o envio falhar, tenta de novo na próxima verificação
            }
            $db->prepare("UPDATE trunk_alert_state SET status='Desconectado', bad_count=:b, down_notified=:n WHERE trunk=:t")->execute([':b' => $bad, ':n' => $notified, ':t' => $name]);
        } else {
            if ((int)$row['down_notified'] === 1) {
                if ($send("✅ *Tronco restabelecido*\nLinha: {$name} ({$t['tech']})\nVoltou às " . date('d/m H:i') . '.', 'UP')) $res['alerts']++;
                else continue;   // reenvia o aviso de volta na próxima verificação
            }
            $db->prepare("UPDATE trunk_alert_state SET status='Registrado', bad_count=0, down_notified=0, since=CURRENT_TIMESTAMP WHERE trunk=:t")->execute([':t' => $name]);
        }
    }
    return $res;
}

// =========================================================================
// STATUS DA INTEGRAÇÃO (somente leitura; nunca envia mensagens nem altera dados)
// =========================================================================
/** Marca que um cron rodou agora (lido pela tela "Status da integração"). */
function cronHeartbeat($name) {
    try { saveSetting('cron_last_' . preg_replace('/[^a-z_]/', '', $name), (string)time()); } catch (Exception $e) {}
}

/** Retorna lista de ['grupo','nome','estado'(ok|warn|fail|off),'detalhe']. */
function integrationStatusChecks() {
    global $db;
    $r = [];
    $add = function ($g, $n, $s, $d) use (&$r) { $r[] = ['grupo' => $g, 'nome' => $n, 'estado' => $s, 'detalhe' => $d]; };

    // --- Asterisco / AMI
    $ami = executeAsteriskAMICommand('Ping');
    $add('Central (PABX)', 'AMI (porta 5038)', !empty($ami['success']) ? 'ok' : 'fail', !empty($ami['success']) ? 'Autenticado e respondendo.' : ($ami['error'] ?? 'Sem resposta.'));
    $up = trim(asteriskCliOutput('core show uptime'));
    $add('Central (PABX)', 'Console do PABX', $up !== '' ? 'ok' : 'warn', $up !== '' ? preg_replace('/\s+/', ' ', substr($up, 0, 110)) : 'Sem resposta do console do PABX.');

    // --- Bancos
    $cdr = getAsteriskPdoConnection('asteriskcdrdb');
    if ($cdr) {
        try {
            $last = $cdr->query("SELECT MAX(calldate) FROM cdr")->fetchColumn();
            $add('Bancos de dados', 'CDR (registro de chamadas)', 'ok', $last ? 'Última chamada registrada: ' . $last : 'Conectado, sem chamadas ainda.');
        } catch (Exception $e) { $add('Bancos de dados', 'CDR (registro de chamadas)', 'warn', 'Conectado, mas a tabela cdr não respondeu.'); }
    } else { $add('Bancos de dados', 'CDR (registro de chamadas)', 'fail', 'Não foi possível conectar ao MySQL.'); }
    try { queueLogImportFromFile(); } catch (Throwable $e) {}
    $qdb = function_exists('getAsteriskDBConnection') ? getAsteriskDBConnection() : null;
    if ($qdb) {
        try {
            $n = (int)$qdb->query("SELECT COUNT(*) FROM queue_log")->fetchColumn();
            $add('Bancos de dados', 'queue_log (filas)', 'ok', number_format($n, 0, ',', '.') . ' eventos registrados.');
        } catch (Exception $e) { $add('Bancos de dados', 'queue_log (filas)', 'warn', 'Conectado, mas queue_log não encontrada.'); }
    } else { $add('Bancos de dados', 'queue_log (filas)', 'fail', 'Não foi possível conectar ao banco "asterisk".'); }
    $nx = 0; $nq = 0;
    try { $nx = (int)$db->query("SELECT COUNT(*) FROM extensions_config")->fetchColumn(); $nq = (int)$db->query("SELECT COUNT(*) FROM queues_config")->fetchColumn(); } catch (Exception $e) {}
    $add('Bancos de dados', 'Ramais e filas sincronizados', ($nx > 0 ? 'ok' : 'warn'), "$nx ramais e $nq filas lidos do PABX.");
    try { saveSetting('status_probe', (string)time()); $w2 = true; } catch (Exception $e) { $w2 = false; }
    $add('Bancos de dados', 'Configurações do painel (SQLite)', $w2 ? 'ok' : 'fail', $w2 ? 'Leitura e escrita funcionando.' : 'Sem permissão de escrita no banco do painel.');

    $pa = pbxapiAvailable();
    $add('Central (PABX)', 'REST API do PABX (opcional)', $pa ? 'ok' : 'off', $pa ? 'Respondendo e autenticada.' : 'Não utilizada: o painel lê direto do banco e do gerenciador (AMI) do PABX.');

    // --- Gravações
    $mon = '/var/spool/asterisk/monitor';
    $add('Gravações', 'Pasta de gravações', is_readable($mon) ? 'ok' : 'warn', is_readable($mon) ? 'Legível pelo painel.' : 'Pasta ausente ou sem permissão de leitura.');
    $pub = trim((string)getSetting('public_base_url'));
    $add('Gravações', 'URL pública (links de gravação)', $pub !== '' ? 'ok' : 'warn', $pub !== '' ? $pub : 'Não configurada (opcional): a gravação segue como arquivo anexo; o link só é usado para gravações acima de 15 MB.');

    // --- WhatsApp
    $prov = waProvider();
    $conf = whatsappProviderConfigured();
    $add('WhatsApp', 'Servidor de envio', $conf ? 'ok' : 'fail', ($prov === 'uazapi' ? 'WhatsApp direto' : 'Prismabot') . ($conf ? ': credenciais preenchidas.' : ': credenciais incompletas.'));
    if ($prov === 'uazapi' && $conf) {
        $u = uazapiRequest('GET', '/instance/status', null, 6);
        $d = json_decode($u['resp'], true);
        $stt = is_array($d) ? (string)($d['instance']['status'] ?? $d['status'] ?? '') : '';
        $add('WhatsApp', 'Conexão WhatsApp direto', ($u['error'] === '' && $u['http'] === 200 && stripos($stt, 'connected') !== false) ? 'ok' : 'warn', $u['error'] !== '' ? $u['error'] : ('Estado: ' . ($stt !== '' ? $stt : 'HTTP ' . $u['http'])));
    }
    $add('WhatsApp', 'Respostas de pesquisa (NPS)', getSetting('webhook_token') ? 'ok' : 'warn', getSetting('webhook_token') ? 'Webhook com token gerado.' : 'Webhook ainda não gerado.');
    $tr = getSetting('trunk_alert_enabled') === '1';
    $add('WhatsApp', 'Alerta de tronco', $tr ? (count(trunkAlertNumbers()) ? 'ok' : 'warn') : 'off', $tr ? (count(trunkAlertNumbers()) . ' número(s) cadastrado(s).') : 'Desativado.');

    // --- IA
    foreach (aiRoles() as $role => $lbl) {
        $c = aiGetConfig($role);
        $sel = aiRoleChoice($role);
        if ($c['key'] === '') $add('Inteligência Artificial', $lbl, 'off', 'Sem chave de API configurada.');
        elseif (!$c['enabled']) $add('Inteligência Artificial', $lbl, 'off', 'Copiloto desativado.');
        else $add('Inteligência Artificial', $lbl, 'ok', ($sel ? 'Função própria: ' : 'Configuração geral: ') . $c['provider'] . ' / ' . ($role === 'audio' ? ($c['audio'] ?: '-') : ($c['model'] ?: '-')));
    }

    // --- SMTP
    $smtpOk = false; $f = __DIR__ . '/../config/smtp_settings.json';
    if (is_file($f)) { $j = @json_decode((string)@file_get_contents($f), true); $smtpOk = is_array($j) && !empty($j['host']) && !empty($j['user']) && !empty($j['password']); }
    $add('E-mail', 'SMTP', $smtpOk ? 'ok' : 'warn', $smtpOk ? 'Servidor, usuário e senha preenchidos.' : 'SMTP sem senha/usuário: envio de relatórios por e-mail não funcionará.');

    // --- Troncos
    try {
        $tk = getAsteriskTrunksStatus(); $up = 0; $dn = 0;
        foreach ($tk as $t) { if ($t['status'] === 'Registrado') $up++; elseif ($t['status'] === 'Desconectado') $dn++; }
        $add('Telefonia', 'Troncos', $dn > 0 ? 'warn' : ($tk ? 'ok' : 'warn'), count($tk) . " troncos: $up registrados, $dn desconectados.");
    } catch (Exception $e) { $add('Telefonia', 'Troncos', 'warn', 'Não foi possível consultar.'); }

    // --- Crons
    foreach (['send_missed_calls' => 'Aviso de chamada perdida', 'send_queue_abandon' => 'Aviso de abandono de fila', 'send_call_summaries' => 'Resumo/gravação ao atendente', 'check_trunks' => 'Alerta de tronco'] as $k => $lbl) {
        $t = (int)getSetting('cron_last_' . $k);
        if ($t <= 0) $add('Tarefas automáticas', $lbl, 'warn', 'Ainda não registrou execução (aguarde 1-2 minutos após atualizar).');
        else { $age = time() - $t; $add('Tarefas automáticas', $lbl, $age <= 180 ? 'ok' : 'fail', 'Última execução há ' . ($age < 90 ? $age . 's' : round($age / 60) . ' min') . ($age <= 180 ? '.' : ' — cron parado? verifique /etc/cron.d/ipbx-front-api.')); }
    }
    return $r;
}

/**
 * Painel de segurança (somente leitura): tentativas de invasão registradas pelo Asterisk e bloqueios do fail2ban.
 * Lê só o final dos logs; se um arquivo não for legível pelo usuário do Apache, informa em 'avisos'.
 */
function securityTailFile($path, $bytes = 400000) {
    if (!@is_file($path) || !@is_readable($path)) return null;
    $size = @filesize($path);
    $fh = @fopen($path, 'r');
    if (!$fh) return null;
    if ($size > $bytes) fseek($fh, -$bytes, SEEK_END);
    $data = (string)stream_get_contents($fh);
    fclose($fh);
    return $data;
}

function securityOverview() {
    $res = ['tentativas' => [], 'por_ip' => [], 'bans' => [], 'banidos_ativos' => [], 'avisos' => []];

    // 1) Eventos de segurança do Asterisk (security log; fallback: full)
    $txt = null; $origem = '';
    foreach (['/var/log/asterisk/security_log', '/var/log/asterisk/security', '/var/log/asterisk/full'] as $f) {
        $t = securityTailFile($f);
        if ($t !== null && stripos($t, 'SecurityEvent=') !== false) { $txt = $t; $origem = $f; break; }
    }
    if ($txt === null) {
        $res['avisos'][] = 'Registro de segurança do PABX não encontrado ou sem permissão de leitura. Peça ao suporte para habilitar o registro de segurança.';
    } else {
        $bad = ['InvalidAccountID', 'InvalidPassword', 'ChallengeResponseFailed', 'FailedACL', 'InvalidTransport', 'AuthMethodNotAllowed', 'ChallengeSentFailed'];
        $lines = array_slice(preg_split('/\r?\n/', $txt), -6000);
        foreach ($lines as $ln) {
            if (!preg_match('/SecurityEvent="?([A-Za-z]+)"?/', $ln, $m) || !in_array($m[1], $bad, true)) continue;
            $ip = '';
            if (preg_match('/RemoteAddress="[^\/]*\/[^\/]*\/([0-9a-fA-F\.:]+)\//', $ln, $a)) $ip = $a[1];
            $when = preg_match('/EventTV="?(\d{4}-\d{2}-\d{2})[T ](\d{2}:\d{2}:\d{2})/', $ln, $w) ? ($w[1] . ' ' . $w[2]) : (preg_match('/^\[([^\]]+)\]/', $ln, $w2) ? $w2[1] : '');
            $acct = preg_match('/AccountID="?([^",]+)/', $ln, $ac) ? $ac[1] : '';
            $res['tentativas'][] = ['quando' => $when, 'evento' => $m[1], 'ip' => $ip, 'conta' => $acct];
            if ($ip !== '') $res['por_ip'][$ip] = ($res['por_ip'][$ip] ?? 0) + 1;
        }
        arsort($res['por_ip']);
        $res['por_ip'] = array_slice($res['por_ip'], 0, 15, true);
        $res['tentativas'] = array_slice(array_reverse($res['tentativas']), 0, 50);
        $res['origem'] = $origem;
    }

    // 2) Bloqueios do fail2ban (log)
    $f2b = securityTailFile('/var/log/fail2ban.log');
    if ($f2b === null) {
        $res['avisos'][] = 'Registro de bloqueios do fail2ban não encontrado ou sem permissão de leitura.';
    } else {
        $state = [];
        foreach (preg_split('/\r?\n/', $f2b) as $ln) {
            if (preg_match('/^(\S+ \S+).*\[([^\]]+)\]\s+(Ban|Unban)\s+([0-9a-fA-F\.:]+)/', $ln, $m)) {
                $state[$m[4]] = $m[3] === 'Ban' ? ['quando' => $m[1], 'jail' => $m[2]] : null;
                if ($m[3] === 'Ban') $res['bans'][] = ['quando' => $m[1], 'jail' => $m[2], 'ip' => $m[4]];
            }
        }
        foreach ($state as $ip => $v) if ($v) $res['banidos_ativos'][] = ['ip' => $ip] + $v;
        $res['bans'] = array_slice(array_reverse($res['bans']), 0, 30);
    }
    return $res;
}

/** Fail2ban HÍBRIDO: usa os jails/whitelist nativos do Issabel (Security > Fail2ban, /var/www/db/iptables.db). */
function f2bNativeDbPath() { return defined('IPBX_F2B_DB') ? IPBX_F2B_DB : '/var/www/db/iptables.db'; }
function f2bNativeJails() {
    $path = f2bNativeDbPath();
    if (!@is_readable($path)) return [];
    try {
        $p = new PDO('sqlite:' . $path);
        $p->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        return $p->query("SELECT id, name, maxretry, bantime, ignoreip, enabled FROM jails ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e) { return []; }
}
function f2bIpValid($ip) {
    $ip = trim($ip);
    if (!preg_match('/^[0-9a-fA-F\.:]+(\/\d{1,3})?$/', $ip)) return false;
    $base = explode('/', $ip)[0];
    return filter_var($base, FILTER_VALIDATE_IP) !== false;
}
/** IPs da whitelist (ignoreip) em comum nos jails nativos, sem o localhost. */
function f2bWhitelistGet() {
    $all = [];
    foreach (f2bNativeJails() as $j) {
        foreach (preg_split('/[\s,]+/', (string)$j['ignoreip'], -1, PREG_SPLIT_NO_EMPTY) as $ip) {
            if ($ip !== '127.0.0.1' && $ip !== '::1' && f2bIpValid($ip)) $all[$ip] = true;
        }
    }
    return array_keys($all);
}
/** Grava a whitelist em TODOS os jails nativos e aplica pelo mecanismo do Issabel. Retorna ['ok'=>bool,'msg'=>string]. */
function f2bWhitelistSave(array $ips) {
    $clean = [];
    foreach ($ips as $ip) { $ip = trim($ip); if ($ip !== '' && f2bIpValid($ip)) $clean[$ip] = true; }
    $value = trim('127.0.0.1 ' . implode(' ', array_keys($clean)));
    $path = f2bNativeDbPath();
    if (!@is_writable($path)) return ['ok' => false, 'msg' => 'Banco de jails do PABX sem permissão de escrita.'];
    try {
        $p = new PDO('sqlite:' . $path);
        $p->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $p->prepare("UPDATE jails SET ignoreip = ?")->execute([$value]);
    } catch (Exception $e) { return ['ok' => false, 'msg' => 'Falha ao gravar: ' . $e->getMessage()]; }
    $applied = false;
    $lib = '/var/www/html/libs/paloSantoDB.class.php';
    $cls = '/var/www/html/modules/sec_fb_admin/libs/IssabelF2Bservice.class.php';
    if (is_readable($lib) && is_readable($cls)) {
        try {
            include_once $lib; include_once $cls;
            $dsn = 'sqlite3:///' . $path;
            $svc = new IssabelF2BService($dsn);
            $svc->F2BUpdateJails($dsn);
            $applied = true;
        } catch (Throwable $e) { $applied = false; }
    }
    if (!$applied && is_executable('/usr/bin/issabel-helper')) {
        @exec('/usr/bin/issabel-helper fb_client reload 2>&1', $o, $rc);
        $applied = ($rc === 0);
    }
    return ['ok' => $applied, 'msg' => $applied ? 'Whitelist salva e aplicada no Fail2ban do PABX.' : 'Whitelist salva no PABX, mas não foi possível recarregar o Fail2ban agora (será aplicada ao recarregar em Security > Fail2ban).'];
}
/** IPs banidos agora, pelo mesmo comando da tela nativa (Security > Fail2ban Banned IPs). */
function f2bBannedNative() {
    if (!is_executable('/usr/bin/issabel-helper')) return null;
    $out = [];
    foreach (['sshd', 'asterisk', 'apache-auth', 'issabel-gui', 'ipbx-front-api'] as $jail) {
        $r = [];
        @exec('/usr/bin/issabel-helper fb_client status ' . escapeshellarg($jail) . ' 2>/dev/null', $r, $rc);
        foreach (preg_split('/\s+/', trim(implode(' ', $r)), -1, PREG_SPLIT_NO_EMPTY) as $ip) {
            if (f2bIpValid($ip)) $out[] = ['jail' => $jail, 'ip' => $ip];
        }
    }
    return $out;
}
function f2bUnban($jail, $ip) {
    if (!in_array($jail, ['sshd', 'asterisk', 'apache-auth', 'issabel-gui', 'ipbx-front-api'], true) || !f2bIpValid($ip) || !is_executable('/usr/bin/issabel-helper')) return false;
    @exec('/usr/bin/issabel-helper fb_client unban ' . escapeshellarg($jail) . ' ' . escapeshellarg($ip) . ' 2>&1', $o, $rc);
    return $rc === 0;
}

/**
 * O Asterisk padrão grava /var/log/asterisk/queue_log em ARQUIVO. Se não existir tabela queue_log em nenhum banco
 * (asterisk / asteriskcdrdb), importa o arquivo de forma incremental para asterisk.queue_log, que é o que os relatórios leem.
 * Se a tabela já existir por outro meio (realtime), não faz nada. Retorna ['imported'=>n,'note'=>string].
 */
function queueLogImportFromFile($path = '/var/log/asterisk/queue_log') {
    $r = ['imported' => 0, 'note' => ''];
    if (!@is_readable($path)) { $r['note'] = 'arquivo ilegível'; return $r; }
    $pdo = getAsteriskPdoConnection('asterisk');
    if (!$pdo) { $r['note'] = 'sem conexão com o banco asterisk'; return $r; }
    $managed = getSetting('queue_log_import_enabled') === '1';
    if (!$managed) {
        // só assume a tabela se ela não existir em nenhum dos dois bancos
        foreach (['asterisk', 'asteriskcdrdb'] as $dbn) {
            $c = ($dbn === 'asterisk') ? $pdo : getAsteriskPdoConnection($dbn);
            if (!$c) continue;
            try { $c->query("SELECT 1 FROM queue_log LIMIT 1"); $r['note'] = 'tabela queue_log já existe (não gerenciada)'; return $r; } catch (Exception $e) {}
        }
        try {
            $pdo->exec("CREATE TABLE IF NOT EXISTS queue_log (id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY, time DATETIME NOT NULL, callid VARCHAR(64), queuename VARCHAR(64), agent VARCHAR(64), event VARCHAR(32), data1 VARCHAR(255), data2 VARCHAR(255), data3 VARCHAR(255), data4 VARCHAR(255), data5 VARCHAR(255), KEY idx_time (time), KEY idx_event (event), KEY idx_callid (callid))");
            saveSetting('queue_log_import_enabled', '1');
            saveSetting('queue_log_import_offset', '0');
        } catch (Exception $e) { $r['note'] = 'não foi possível criar a tabela: ' . $e->getMessage(); return $r; }
    }
    clearstatcache(true, $path);
    $size = (int)@filesize($path);
    $off = (int)getSetting('queue_log_import_offset');
    if ($size < $off) $off = 0; // arquivo rotacionado
    if ($size === $off) return $r;
    $fh = @fopen($path, 'r');
    if (!$fh) return $r;
    fseek($fh, $off);
    $ins = $pdo->prepare("INSERT INTO queue_log (time, callid, queuename, agent, event, data1, data2, data3, data4, data5) VALUES (?,?,?,?,?,?,?,?,?,?)");
    $n = 0; $pos = $off;
    $pdo->beginTransaction();
    while ($n < 20000 && ($line = fgets($fh)) !== false) {
        if (substr($line, -1) !== "\n") break; // linha incompleta: lê na próxima rodada
        $pos += strlen($line);
        $f = explode('|', rtrim($line, "\r\n"));
        if (count($f) < 5 || !ctype_digit($f[0]) || $f[4] === 'CONFIGRELOAD') continue;
        $f = array_pad($f, 10, '');
        $ins->execute([date('Y-m-d H:i:s', (int)$f[0]), $f[1], $f[2], $f[3], $f[4], $f[5], $f[6], $f[7], $f[8], $f[9]]);
        $n++;
    }
    $pdo->commit();
    fclose($fh);
    saveSetting('queue_log_import_offset', (string)$pos);
    $r['imported'] = $n;
    return $r;
}
