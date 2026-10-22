<?php
/**
 * IPbx Prisma - Painel de APIs & Conexões (Com Navegação por Abas e Restauração Específica)
 */
global $db;

if (!isset($db) || !$db) {
    try {
        $db = new PDO("sqlite:" . __DIR__ . '/../../config/database.sqlite');
    } catch (Exception $e) {}
}

if ($db) {
    try {
        $db->exec("CREATE TABLE IF NOT EXISTS settings (key_name TEXT PRIMARY KEY, value TEXT)");
    } catch (Exception $e) {}
}

require_once __DIR__ . '/../../includes/pbxapi_client.php';

$apiClient = new PbxApiClient($db);

$msg_status = '';
$msg_error  = '';
$active_tab = isset($_POST['active_tab']) ? $_POST['active_tab'] : (pbxapiAvailable() ? 'pbxapi' : 'prismabot');

// Auto-detectar credenciais nativas
$sysConf = function_exists('getPABXSystemConfig') ? @getPABXSystemConfig() : [];
$auto_pass = !empty($sysConf['mysqlrootpwd']) ? $sysConf['mysqlrootpwd'] : (!empty($sysConf['amiadminpwd']) ? $sysConf['amiadminpwd'] : 'palosanto');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // 1. REST API PABX
    if (isset($_POST['action_save_pbx_api']) || isset($_POST['action_test_pbx_api'])) {
        $active_tab = 'pbxapi';
        $url  = trim($_POST['pbx_api_url'] ?? 'https://127.0.0.1/pbxapi');
        $user = trim($_POST['pbx_api_user'] ?? 'admin');
        $pass = trim($_POST['pbx_api_pass'] ?? $auto_pass);

        if (isset($_POST['action_test_pbx_api'])) {
            $apiClient->saveSettings($url, $user, $pass);
            $testResult = $apiClient->authenticate();
            if (!empty($testResult['success'])) {
                $msg_status = "⚡ Conexão com a REST API PABX realizada com SUCESSO!";
                if (!empty($testResult['token'])) {
                    $apiClient->saveSettings($url, $user, $pass, $testResult['token']);
                }
            } else {
                $msg_error = $testResult['message'] ?? 'Erro ao conectar na API PABX.';
            }
        } else {
            $apiClient->saveSettings($url, $user, $pass);
            $msg_status = "Configurações da REST API PABX salvas com sucesso!";
        }
    } elseif (isset($_POST['action_reset_pbx_api'])) {
        $active_tab = 'pbxapi';
        try {
            $db->exec("DELETE FROM settings WHERE key_name LIKE 'pbxapi_%'");
            $msg_status = "Configurações da REST API PABX redefinidas para os padrões!";
        } catch (Exception $e) {}
    }

    // URL pública do painel (usada nos links assinados de gravação enviados por WhatsApp)
    if (isset($_POST['action_save_public_url'])) {
        $active_tab = 'avisos';
        $pub = rtrim(trim($_POST['public_base_url'] ?? ''), '/');
        if ($pub !== '' && !preg_match('#^https?://[A-Za-z0-9.\-:\[\]]+(/[A-Za-z0-9._~/\-]*)?$#', $pub)) {
            $msg_error = "⚠️ URL pública inválida. Use o formato https://pbx.seudominio.com.br/front_api";
        } else {
            saveSetting('public_base_url', $pub);
            $msg_status = $pub === '' ? "URL pública removida: links de gravação não serão enviados." : "URL pública do painel salva.";
            logUserAction("Salvar URL pública", $pub, "CONFIG");
        }
    }

    // Alerta de tronco fora do ar (WhatsApp ao supervisor)
    if (isset($_POST['action_save_trunk_alert']) || isset($_POST['action_test_trunk_alert'])) {
        $active_tab = 'avisos';
        saveSetting('trunk_alert_enabled', isset($_POST['trunk_alert_enabled']) ? '1' : '0');
        saveSetting('trunk_alert_numbers', trim($_POST['trunk_alert_numbers'] ?? ''));
        $msg_status = "Alerta de tronco salvo.";
        if (isset($_POST['action_test_trunk_alert'])) {
            $nums = trunkAlertNumbers();
            if (!$nums) { $msg_error = "⚠️ Informe ao menos um número (DDD + telefone)."; $msg_status = ''; }
            else {
                $okc = 0; foreach ($nums as $n) { $r = sendWhatsAppAPI($n, "✅ Teste do alerta de tronco do PABX Prisma. Você será avisado aqui se uma linha cair.", 'TESTE_TRONCO_' . date('YmdHis'), 'TRONCO', 'ALERTA_TRONCO'); if (!empty($r['success'])) $okc++; }
                $msg_status = $okc ? "Teste enviado para $okc número(s)." : ''; if (!$okc) $msg_error = "⚠️ Não foi possível enviar o teste. Confira o servidor de envio WhatsApp.";
            }
        }
        logUserAction("Alerta de tronco", getSetting('trunk_alert_enabled') === '1' ? 'ativado' : 'desativado', "CONFIG");
    }

    // Chamadas perdidas: regras de inteligência
    if (isset($_POST['action_save_missed_rules'])) {
        $active_tab = 'avisos';
        $mr = max(0, min(60, (int)($_POST['missed_min_ring_seconds'] ?? 10)));
        saveSetting('missed_min_ring_seconds', (string)$mr);
        saveSetting('missed_ignore_answered_elsewhere', isset($_POST['missed_ignore_answered_elsewhere']) ? '1' : '0');
        $msg_status = "Regras de chamada perdida salvas (mínimo de toque: {$mr}s).";
        logUserAction("Regras de chamada perdida", "min_toque={$mr}s", "CONFIG");
    }

    // 1b. Qual servidor de envio WhatsApp está em uso (Prismabot ou WhatsApp direto)
    if (isset($_POST['action_set_wa_provider'])) {
        $prov = (($_POST['wa_provider'] ?? 'zpro') === 'uazapi') ? 'uazapi' : 'zpro';
        $active_tab = $prov === 'uazapi' ? 'uazapi' : 'prismabot';
        if ($prov === 'uazapi' && getSetting('uazapi_token') === '' && !uazapiFileConf()) {
            $msg_error = "⚠️ O WhatsApp direto ainda não foi conectado. Clique em \"Conectar WhatsApp\" e leia o QR Code antes de usá-lo.";
        } else {
            saveSetting('wa_provider', $prov);
            $msg_status = $prov === 'uazapi' ? "Pronto: avisos, pesquisas e gravações agora saem pelo WhatsApp direto (QR Code)." : "Pronto: avisos, pesquisas e gravações agora saem pela API Prismabot.";
            logUserAction("Servidor de envio WhatsApp", $prov, "CONFIG");
        }
    }

    // 1c. WhatsApp direto (QR Code): salvar dados do servidor próprio / testar conexão
    $uaz_qr = '';
    if (isset($_POST['action_save_uazapi']) || isset($_POST['action_test_uazapi'])) {
        $active_tab = 'uazapi';
        $u_url = rtrim(trim($_POST['uazapi_url'] ?? ''), '/');
        $u_tok = trim($_POST['uazapi_token'] ?? '');
        if ($u_url !== '' && !preg_match('#^https?://#i', $u_url)) $u_url = 'https://' . $u_url;
        $u_mode = uazapiSaveSettings(waProvider(), $_POST['uazapi_mode'] ?? '', $u_url, $_POST['uazapi_admin_token'] ?? '', $u_tok);
        $findKey = function ($arr, $key) use (&$findKey) {
            if (!is_array($arr)) return '';
            foreach ($arr as $k => $v) {
                if ($k === $key && is_scalar($v) && $v !== '') return (string)$v;
                if (is_array($v)) { $r = $findKey($v, $key); if ($r !== '') return $r; }
            }
            return '';
        };
        if (isset($_POST['action_save_uazapi'])) {
            $msg_status = "Dados do servidor salvos. Agora clique em \"Conectar WhatsApp\".";
            logUserAction("Servidor WhatsApp direto", "salvo", "CONFIG");
        } else {
            $r = uazapiRequest('GET', '/instance/status', null, 8);
            $d = json_decode($r['resp'], true);
            $st = is_array($d) ? strtolower($findKey($d, 'status')) : '';
            if ($r['error'] !== '') { $msg_error = "⚠️ " . $r['error']; }
            elseif ($r['http'] === 401 || $r['http'] === 403) { $msg_error = "⚠️ Token da instância recusado (HTTP {$r['http']}). Confira o token."; }
            elseif ($r['http'] >= 200 && $r['http'] < 300) {
                if ($st === 'connected') $msg_status = "✅ WhatsApp direto conectado e pareado.";
                else $msg_error = "⚠️ Servidor alcançado, mas o WhatsApp não está conectado (estado: " . ($st ?: 'desconhecido') . "). Clique em \"Conectar WhatsApp\" e leia o QR Code com o celular.";
            } else { $msg_error = "⚠️ O servidor respondeu HTTP {$r['http']}. Confira o endereço do servidor."; }
            logUserAction("Teste UAZAPI", "HTTP " . $r['http'] . " status=" . $st, "CONFIG");
        }
    }

    // 2. API Prismabot & Sincronização de Custom Destinations
    if (isset($_POST['action_save_prismabot_api']) || isset($_POST['action_test_prismabot_api']) || isset($_POST['action_sync_custom_destinations'])) {
        $active_tab = 'prismabot';
        $bot_url        = trim($_POST['prismabot_url'] ?? '');
        $bot_token      = trim($_POST['prismabot_token'] ?? '');
        $msg_no_answer  = trim($_POST['prismabot_msg_no_answer'] ?? '');
        $msg_transbordo = trim($_POST['prismabot_msg_transbordo'] ?? '');
        $msg_audio      = trim($_POST['prismabot_msg_audio'] ?? '');

        try {
            if (!empty($bot_url))   saveSetting('api_url', $bot_url);
            if (!empty($bot_token)) saveSetting('api_token', $bot_token);
            if ($msg_no_answer !== '')  saveSetting('prismabot_msg_no_answer', $msg_no_answer);
            if ($msg_transbordo !== '') saveSetting('prismabot_msg_transbordo', $msg_transbordo);
            if ($msg_audio !== '')      saveSetting('prismabot_msg_audio', $msg_audio);
            
            // Sincronizar automaticamente os 4 Custom Destinations no MySQL do Asterisk
            $syncRes = syncCustomDestinationsWithAsterisk();

            if (isset($_POST['action_sync_custom_destinations'])) {
                if (!empty($syncRes['success'])) {
                    $msg_status = "⚡ " . $syncRes['message'];
                    logUserAction("Sync Custom Destinations", $syncRes['message'], "CONFIG");
                } else {
                    $msg_error = "⚠️ " . $syncRes['message'];
                }
            } elseif (isset($_POST['action_test_prismabot_api'])) {
                $cleanToken = trim(preg_replace('/^bearer\s+/i', '', trim($bot_token)));
                $__lu = function_exists('getLoggedUser') ? getLoggedUser() : null;
                $__testNumber = preg_replace('/\D+/', '', (string)($__lu['whatsapp'] ?? ''));
                if ($__testNumber === '') {
                    $msg_error = "⚠️ Cadastre seu WhatsApp em Configurações > Usuários: o teste envia o ping para o número do usuário logado.";
                } else {
                $testPayload = [
                    'number'         => $__testNumber,
                    'body'           => 'Teste de conexão IPBX Prisma',
                    'externalKey'    => 'PRISMA_TEST_' . time(),
                    'isClosed'       => false,
                    'validateNumber' => false
                ];
                $ch = curl_init($bot_url);
                curl_setopt_array($ch, [
                    CURLOPT_RETURNTRANSFER => true,
                    CURLOPT_POST => true,
                    CURLOPT_POSTFIELDS => json_encode($testPayload),
                    CURLOPT_TIMEOUT => 8,
                    CURLOPT_SSL_VERIFYPEER => false,
                    CURLOPT_HTTPHEADER => [
                        'Content-Type: application/json',
                        'Authorization: Bearer ' . $cleanToken,
                        'token: ' . $cleanToken,
                        'apikey: ' . $cleanToken,
                        'x-api-token: ' . $cleanToken
                    ]
                ]);
                $out = curl_exec($ch);
                $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
                $curlErr = curl_error($ch);
                curl_close($ch);

                if ($curlErr) {
                    $msg_error = "⚠️ Erro de Conexão cURL com Prismabot: " . $curlErr;
                    logSystemError("Erro cURL Teste Prismabot", ['error' => $curlErr, 'url' => $bot_url]);
                } elseif ($httpCode >= 200 && $httpCode < 300) {
                    $msg_status = "⚡ Conexão com API Prismabot realizada com SUCESSO! (HTTP $httpCode)";
                    logUserAction("Teste Prismabot API", "Conexão bem sucedida HTTP $httpCode", "CONFIG");
                } elseif (strpos((string)$out, 'ERR_API_REQUIRES_SESSION') !== false || ($httpCode >= 400 && $httpCode < 500 && $httpCode !== 403 && $httpCode !== 404)) {
                    $msg_status = "⚡ API Prismabot ALCANÇADA com sucesso! A URL do Endpoint é válida e responde a requisições POST (HTTP $httpCode).";
                    logUserAction("Teste Prismabot API", "API Prismabot ativa HTTP $httpCode", "CONFIG");
                } elseif ($httpCode === 403) {
                    $msg_error = "⚠️ Falha de Autenticação (HTTP 403: Invalid Token). O token fornecido não foi aceito pela API Prismabot.";
                    logSystemError("Erro Auth 403 Teste Prismabot", ['response' => $out, 'url' => $bot_url]);
                } elseif ($httpCode === 404) {
                    $msg_error = "⚠️ Endpoint não encontrado (HTTP 404). Verifique se a URL da API Prismabot foi colada corretamente.";
                    logSystemError("Erro HTTP 404 Teste Prismabot", ['url' => $bot_url]);
                } else {
                    $msg_status = "⚡ API Prismabot alcançada com código HTTP $httpCode.";
                    logUserAction("Teste Prismabot API", "Status HTTP $httpCode", "CONFIG");
                }
                }
            } else {
                $msg_status = "Credenciais e Custom Destinations salvas e sincronizadas com sucesso no PABX!";
                logUserAction("Salvar Prismabot API", "URL: $bot_url", "CONFIG");
            }
        } catch (Exception $e) {
            logSystemError("Erro ao salvar credenciais do Prismabot", ['error' => $e->getMessage(), 'url' => $bot_url]);
            $msg_error = "Erro ao salvar credenciais do Prismabot: " . $e->getMessage();
        }
    } elseif (isset($_POST['action_send_direct_test_wa'])) {
        $active_tab = 'avisos';
        $test_phone = trim($_POST['test_wa_phone'] ?? '');
        $test_msg   = trim($_POST['test_wa_msg'] ?? '');

        if (empty($test_phone) || empty($test_msg)) {
            $msg_error = "Por favor, preencha o número de telefone e a mensagem de teste.";
        } else {
            $res = sendWhatsAppAPI($test_phone, $test_msg, 'TESTE_DIRETO_API', 'CONFIG_API', 'TESTE_SISTEMA');
            if (!empty($res['success'])) {
                $msg_status = "🚀 Mensagem de teste enviada com SUCESSO via WhatsApp para " . htmlspecialchars($test_phone) . "!";
                logUserAction("Disparo Teste WhatsApp Direct", "Telefone: $test_phone", "CONFIG");
            } else {
                $err_detail = $res['error'] ?? 'Erro desconhecido no envio';
                if (strpos($err_detail, 'ERR_API_REQUIRES_SESSION') !== false) {
                    $msg_error = "⚠️ Falha no envio (HTTP 400: ERR_API_REQUIRES_SESSION). A instância do WhatsApp no painel Prismabot está desconectada (necessita escanear o QR Code no Prismabot) ou requer a inclusão do ID da Sessão (WhatsApp ID).";
                } elseif (strpos($err_detail, '403') !== false) {
                    $msg_error = "⚠️ Falha de Autenticação (HTTP 403: Invalid Token). Verifique se o Token colado em Configurações é o token ativo no Prismabot.";
                } else {
                    $msg_error = "⚠️ Falha ao disparar mensagem de teste: " . htmlspecialchars($err_detail);
                }
                logSystemError("Falha Disparo Direto Teste WhatsApp", ['phone' => $test_phone, 'error' => $err_detail]);
            }
        }
    } elseif (isset($_POST['action_reset_prismabot_api'])) {
        $active_tab = 'prismabot';
        try {
            $db->exec("DELETE FROM settings WHERE key_name IN ('api_url', 'api_token', 'prismabot_whatsapp_id')");
            $msg_status = "Configurações da API Prismabot redefinidas para os padrões!";
            logUserAction("Redefinir Prismabot API", "Credenciais resetadas", "CONFIG");
        } catch (Exception $e) {
            logSystemError("Erro ao redefinir Prismabot API", ['error' => $e->getMessage()]);
        }
    }

    // 3. MySQL CDR Database
    if (isset($_POST['action_save_mysql']) || isset($_POST['action_test_mysql'])) {
        $active_tab = 'mysql';
        $m_host = trim($_POST['mysql_host'] ?? 'localhost');
        $m_port = trim($_POST['mysql_port'] ?? '3306');
        $m_user = trim($_POST['mysql_user'] ?? 'asteriskuser');
        $m_pass = trim($_POST['mysql_pass'] ?? $auto_pass);

        try {
            saveSetting('asterisk_db_host', $m_host);
            saveSetting('asterisk_db_port', $m_port);
            saveSetting('asterisk_db_user', $m_user);
            saveSetting('asterisk_db_pass', $m_pass);

            if (isset($_POST['action_test_mysql'])) {
                try {
                    $test_db = new PDO("mysql:host=$m_host;port=$m_port;dbname=asteriskcdrdb;charset=utf8", $m_user, $m_pass, [
                        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                        PDO::ATTR_TIMEOUT => 3
                    ]);
                    $msg_status = "🗄️ Conexão com o Banco de Dados MySQL (CDR) realizada com SUCESSO!";
                } catch (Exception $em) {
                    logSystemError("Falha conexao MySQL PABX", ['error' => $em->getMessage(), 'host' => $m_host]);
                    $msg_error = "Falha ao conectar no MySQL: " . $em->getMessage();
                }
            } else {
                $msg_status = "Configurações do Banco MySQL salvas com sucesso!";
            }
        } catch (Exception $e) {
            logSystemError("Erro ao salvar dados MySQL PABX", ['error' => $e->getMessage()]);
            $msg_error = "Erro ao salvar dados do MySQL: " . $e->getMessage();
        }
    } elseif (isset($_POST['action_reset_mysql'])) {
        $active_tab = 'mysql';
        try {
            $db->exec("DELETE FROM settings WHERE key_name LIKE 'asterisk_db_%'");
            $msg_status = "Configurações do Banco MySQL redefinidas para os padrões!";
        } catch (Exception $e) {}
    }

    // 4. Reset Defaults Global
    if (isset($_POST['action_reset_defaults'])) {
        $active_tab = 'reset';
        try {
            $db->exec("DELETE FROM settings");
            $db->exec("DELETE FROM integration_rules");
            $db->exec("INSERT INTO integration_rules (rule_type, trigger_event, enabled, target_number, message_template, updated_at) VALUES 
                ('NPS', 'call_finished', 1, 'CALLER_ID', 'Olá! Como você avalia nosso atendimento de 1 a 5 estrelas?', CURRENT_TIMESTAMP),
                ('ATENDENTE_NAO_ATENDEU', 'no_answer', 1, 'CALLER_ID', 'Olá! Desculpe a demora, vimos que você tentou falar conosco. Em breve um atendente entrará em contato.', CURRENT_TIMESTAMP)
            ");
            syncPrismaAssets();
            $msg_status = "Todas as configurações do sistema foram restauradas para os padrões de fábrica!";
        } catch (Exception $e) {}
    }
}

// Carregar configurações atuais
$settings = [];
if ($db) {
    try {
        $stmtSet = $db->query("SELECT key_name, value_val FROM settings");
        if ($stmtSet) {
            while ($row = $stmtSet->fetch(PDO::FETCH_ASSOC)) {
                $settings[$row['key_name']] = $row['value_val'];
            }
        }
    } catch (Exception $e) {}
}

$currentPbxUrl   = !empty($settings['pbxapi_url'])   ? $settings['pbxapi_url']   : 'https://127.0.0.1/pbxapi';
$currentPbxUser  = !empty($settings['pbxapi_user'])  ? $settings['pbxapi_user']  : 'admin';
$currentPbxPass  = !empty($settings['pbxapi_pass'])  ? $settings['pbxapi_pass']  : $auto_pass;
$currentPbxToken = !empty($settings['pbxapi_token']) ? $settings['pbxapi_token'] : '';

$currentBotUrl   = !empty($settings['api_url'])      ? $settings['api_url']      : 'https://api.prismabot.com.br/v2/api/external/588c0e57-09f5-4b8a-8c64-7b12980cb1b3';
$currentBotToken = !empty($settings['api_token'])    ? $settings['api_token']    : '';

$currentMsgNoAnswer  = !empty($settings['prismabot_msg_no_answer'])  ? $settings['prismabot_msg_no_answer']  : 'Olá {NOME}, identificamos uma chamada não atendida para a empresa no número {NUMERO}. Como podemos te ajudar?';
$currentMsgTransbordo= !empty($settings['prismabot_msg_transbordo']) ? $settings['prismabot_msg_transbordo'] : 'Olá {NOME}, seu atendimento superou o tempo limite de espera. Em instantes um consultor entrará em contato!';
$currentMsgAudio     = !empty($settings['prismabot_msg_audio'])      ? $settings['prismabot_msg_audio']      : 'Olá {NOME}, segue a gravação da sua chamada realizada em {DATA}.';

$currentMyHost   = !empty($settings['asterisk_db_host']) ? $settings['asterisk_db_host'] : 'localhost';
$currentMyPort   = !empty($settings['asterisk_db_port']) ? $settings['asterisk_db_port'] : '3306';
$currentMyUser   = !empty($settings['asterisk_db_user']) ? $settings['asterisk_db_user'] : 'asteriskuser';
$currentMyPass   = !empty($settings['asterisk_db_pass']) ? $settings['asterisk_db_pass'] : $auto_pass;

// Tentar autenticar silenciosamente na REST API se token zerado
if (empty($currentPbxToken) && !empty($currentPbxPass) && $_SERVER['REQUEST_METHOD'] !== 'POST' && pbxapiAvailable()) {
    $autoAuth = $apiClient->authenticate();
    if (!empty($autoAuth['success']) && !empty($autoAuth['token'])) {
        $currentPbxToken = $autoAuth['token'];
    }
}
?>

<div class="space-y-6">
    <!-- BARRA DE ABAS DE NAVEGAÇÃO SUPERIOR (MENU EM CIMA) -->
    <div class="bg-slate-900/90 border border-slate-800 rounded-2xl p-2 shadow-xl flex items-center gap-2 overflow-x-auto text-xs font-bold">
        <button type="button" onclick="switchApiTab('pbxapi')" id="btn-tab-pbxapi" 
                class="px-4 py-2.5 rounded-xl transition flex items-center gap-2 text-slate-400 hover:text-white">
            <i class="fa-solid fa-bolt text-cyan-400"></i>
            <span>1. REST API PABX (`pbxapi`) <small class="font-normal opacity-70">— opcional</small></span>
        </button>
        <button type="button" onclick="switchApiTab('prismabot')" id="btn-tab-prismabot" 
                class="px-4 py-2.5 rounded-xl transition flex items-center gap-2 text-slate-400 hover:text-white">
            <i class="fa-solid fa-key text-amber-400"></i>
            <span>2. API Prismabot</span>
        </button>
        <button type="button" onclick="switchApiTab('uazapi')" id="btn-tab-uazapi" 
                class="px-4 py-2.5 rounded-xl transition flex items-center gap-2 text-slate-400 hover:text-white">
            <i class="fa-brands fa-whatsapp text-emerald-400"></i>
            <span>3. WhatsApp direto (QR Code)</span>
        </button>
        <button type="button" onclick="switchApiTab('avisos')" id="btn-tab-avisos" 
                class="px-4 py-2.5 rounded-xl transition flex items-center gap-2 text-slate-400 hover:text-white">
            <i class="fa-solid fa-bell text-indigo-400"></i>
            <span>4. Avisos e teste de envio</span>
        </button>
        <button type="button" onclick="switchApiTab('mysql')" id="btn-tab-mysql" 
                class="px-4 py-2.5 rounded-xl transition flex items-center gap-2 text-slate-400 hover:text-white">
            <i class="fa-solid fa-database text-purple-400"></i>
            <span>5. Banco MySQL CDR</span>
        </button>
        <button type="button" onclick="switchApiTab('reset')" id="btn-tab-reset" 
                class="px-4 py-2.5 rounded-xl transition flex items-center gap-2 text-slate-400 hover:text-white">
            <i class="fa-solid fa-triangle-exclamation text-rose-400"></i>
            <span>6. Restauração Global</span>
        </button>
    </div>

    <!-- NOTIFICAÇÃO TOAST COM AUTO-FADE -->
    <?php if ($msg_status): ?>
        <div id="api-status-toast" class="p-4 bg-emerald-500/10 border border-emerald-500/30 rounded-xl text-emerald-300 text-sm flex items-center justify-between gap-3 transition-opacity duration-500">
            <div class="flex items-center gap-3">
                <i class="fa-solid fa-circle-check text-emerald-400 text-base"></i>
                <div><?php echo htmlspecialchars($msg_status); ?></div>
            </div>
            <button onclick="document.getElementById('api-status-toast').style.display='none'" class="text-emerald-400 hover:text-white transition text-xs font-bold px-2 py-1">✕</button>
        </div>
        <script>
            setTimeout(function() {
                var toast = document.getElementById('api-status-toast');
                if (toast) {
                    toast.style.opacity = '0';
                    setTimeout(function() { toast.style.display = 'none'; }, 500);
                }
            }, 4000);
        </script>
    <?php endif; ?>

    <?php if ($msg_error): ?>
        <div id="api-error-toast" class="p-4 bg-rose-500/10 border border-rose-500/30 rounded-xl text-rose-300 text-sm flex items-center justify-between gap-3 transition-opacity duration-500">
            <div class="flex items-center gap-3">
                <i class="fa-solid fa-triangle-exclamation text-rose-400 text-base"></i>
                <div><?php echo htmlspecialchars($msg_error); ?></div>
            </div>
            <button onclick="document.getElementById('api-error-toast').style.display='none'" class="text-rose-400 hover:text-white transition text-xs font-bold px-2 py-1">✕</button>
        </div>
    <?php endif; ?>

    <!-- CONTEÚDO ABA 1: REST API PABX -->
    <div id="tab-content-pbxapi" class="space-y-4 tab-pane hidden">
        <div class="bg-slate-900/90 border border-slate-800 rounded-2xl p-6 shadow-xl space-y-4">
            <div class="flex flex-col md:flex-row items-start md:items-center justify-between gap-4 border-b border-slate-800 pb-4">
                <div class="flex items-center gap-3">
                    <div class="p-3 bg-cyan-500/10 rounded-xl border border-cyan-500/20 text-cyan-400">
                        <i class="fa-solid fa-network-wired text-xl"></i>
                    </div>
                    <div>
                        <h3 class="text-base font-bold text-slate-100 flex items-center gap-2">
                            Conexão REST API PABX (`pbxapi`)
                        </h3>
                        <p class="text-xs text-slate-400">Comunicação local via HTTPS/JWT (Click-to-Call, FOP, Blacklist e Pausas)</p>
                    </div>
                </div>
                <div>
                    <?php if (!empty($currentPbxToken)): ?>
                        <span class="inline-flex items-center gap-2 px-3.5 py-1.5 rounded-xl text-xs font-semibold bg-emerald-500/10 text-emerald-400 border border-emerald-500/20">
                            <span class="w-2 h-2 rounded-full bg-emerald-400 animate-pulse"></span>
                            🟢 API Conectada & Ativa
                        </span>
                    <?php else: ?>
                        <span class="inline-flex items-center gap-2 px-3.5 py-1.5 rounded-xl text-xs font-semibold bg-amber-500/10 text-amber-400 border border-amber-500/20">
                            <span class="w-2 h-2 rounded-full bg-amber-400"></span>
                            🟡 Pendente de Autenticação
                        </span>
                    <?php endif; ?>
                </div>
            </div>

            <form method="POST" action="index.php?module=configuracoes&action=api" class="space-y-4 text-xs">
                <input type="hidden" name="active_tab" value="pbxapi">
                <div>
                    <label class="block font-bold text-slate-300 mb-1.5 uppercase tracking-wider text-[11px]">URL Base da API PABX</label>
                    <input type="text" name="pbx_api_url" value="<?php echo htmlspecialchars($currentPbxUrl); ?>" required
                           class="w-full px-4 py-2.5 bg-slate-950 border border-slate-800 rounded-xl text-white font-mono focus:border-cyan-500 focus:outline-none">
                </div>

                <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                    <div>
                        <label class="block font-bold text-slate-300 mb-1.5 uppercase tracking-wider text-[11px]">Usuário Admin</label>
                        <input type="text" name="pbx_api_user" value="<?php echo htmlspecialchars($currentPbxUser); ?>" required
                               class="w-full px-4 py-2.5 bg-slate-950 border border-slate-800 rounded-xl text-white font-mono focus:border-cyan-500 focus:outline-none">
                    </div>
                    <div>
                        <label class="block font-bold text-slate-300 mb-1.5 uppercase tracking-wider text-[11px]">Senha Admin (Autodetectada)</label>
                        <input type="password" name="pbx_api_pass" value="<?php echo htmlspecialchars($currentPbxPass); ?>" required
                               class="w-full px-4 py-2.5 bg-slate-950 border border-slate-800 rounded-xl text-white font-mono focus:border-cyan-500 focus:outline-none">
                        <p class="text-[10px] text-emerald-400 mt-1 flex items-center gap-1">
                            <i class="fa-solid fa-wand-magic-sparkles"></i> Senha obtida automaticamente do servidor PABX!
                        </p>
                    </div>
                </div>

                <div class="pt-3 border-t border-slate-800 flex flex-wrap items-center justify-between gap-3">
                    <div class="flex items-center gap-3">
                        <button type="submit" name="action_save_pbx_api" class="px-5 py-2 bg-slate-800 hover:bg-slate-700 text-slate-200 font-bold rounded-xl border border-slate-700 transition">
                            Salvar Credenciais PABX
                        </button>
                        <button type="submit" name="action_test_pbx_api" class="px-5 py-2 bg-cyan-600 hover:bg-cyan-500 text-white font-bold rounded-xl shadow-lg shadow-cyan-600/20 transition flex items-center gap-1.5">
                            <i class="fa-solid fa-bolt"></i> ⚡ Testar Conexão API PABX
                        </button>
                    </div>
                    <button type="submit" name="action_reset_pbx_api" onclick="return confirm('Deseja redefinir as credenciais da REST API PABX para os padrões?')" class="px-4 py-2 bg-rose-600/10 hover:bg-rose-600 text-rose-300 hover:text-white border border-rose-500/30 rounded-xl font-bold transition flex items-center gap-1.5">
                        <i class="fa-solid fa-rotate-left"></i> Redefinir Padrões REST API PABX
                    </button>
                </div>
            </form>
        </div>
    </div>

    <!-- CONTEÚDO ABA 2: API PRISMABOT -->
    <div id="tab-content-prismabot" class="space-y-4 tab-pane hidden">
        <?php
            $__prov = waProvider();
            $__btn = 'white-space:nowrap !important;flex-shrink:0 !important;width:auto !important;overflow:visible !important;text-overflow:clip !important;';
        ?>
        <div class="bg-slate-900/90 border border-slate-800 rounded-2xl p-4 shadow-xl flex flex-col md:flex-row md:items-center justify-between gap-3 text-xs">
            <p class="text-slate-400">Se você contratou o Prismabot, preencha a URL e o token abaixo. Se não tem Prismabot, use a aba <b class="text-slate-200">3. WhatsApp direto (QR Code)</b>. Só um dos dois fica em uso por vez.</p>
            <form method="POST" action="index.php?module=configuracoes&action=api" class="flex items-center gap-2">
                <input type="hidden" name="active_tab" value="prismabot">
                <input type="hidden" name="wa_provider" value="zpro">
                <?php if ($__prov === 'zpro'): ?>
                    <span style="white-space:nowrap" class="px-3 py-1.5 rounded-lg bg-emerald-500/15 text-emerald-400 font-bold"><i class="fa-solid fa-circle-check mr-1"></i> Em uso para os envios</span>
                <?php else: ?>
                    <span style="white-space:nowrap" class="px-3 py-1.5 rounded-lg bg-slate-800 text-slate-400 font-bold">Não está em uso</span>
                    <button type="submit" name="action_set_wa_provider" style="<?php echo $__btn; ?>" class="px-4 py-2 bg-amber-600 hover:bg-amber-500 text-white font-bold rounded-xl">Usar o Prismabot nos envios</button>
                <?php endif; ?>
            </form>
        </div>

        <div class="bg-slate-900/90 border border-slate-800 rounded-2xl p-6 shadow-xl space-y-4">
            <div class="border-b border-slate-800 pb-3 flex items-center gap-3">
                <div class="p-3 bg-amber-500/10 rounded-xl border border-amber-500/20 text-amber-400">
                    <i class="fa-solid fa-key text-xl"></i>
                </div>
                <div>
                    <h3 class="text-base font-bold text-slate-100">
                        API Prismabot (Notificações WhatsApp)
                    </h3>
                    <p class="text-xs text-slate-400">Integração externa para envio das notificações de chamadas</p>
                </div>
            </div>

            <form method="POST" action="index.php?module=configuracoes&action=api" class="space-y-4 text-xs">
                <input type="hidden" name="active_tab" value="prismabot">
                <div>
                    <label class="block font-bold text-slate-300 mb-1.5 uppercase tracking-wider text-[11px]">URL da API Externa Prismabot</label>
                    <input type="url" name="prismabot_url" value="<?php echo htmlspecialchars($currentBotUrl); ?>" required
                           class="w-full px-4 py-2.5 bg-slate-950 border border-slate-800 rounded-xl text-white font-mono focus:border-amber-500 focus:outline-none">
                </div>

                <div>
                    <label class="block font-bold text-slate-300 mb-1.5 uppercase tracking-wider text-[11px]">Token de Autenticação (Bearer / Key Token)</label>
                    <input type="password" name="prismabot_token" value="<?php echo htmlspecialchars($currentBotToken); ?>" placeholder="Cole seu token de autenticação..." required
                           class="w-full px-4 py-2.5 bg-slate-950 border border-slate-800 rounded-xl text-white font-mono focus:border-amber-500 focus:outline-none">
                </div>

                <!-- SEÇÃO DE ESPELHAMENTO DOS CUSTOM DESTINATIONS DO PABX -->
                <div class="pt-5 border-t border-slate-800 space-y-4">
                    <div class="flex flex-col md:flex-row items-start md:items-center justify-between gap-3 bg-cyan-950/30 p-4 rounded-xl border border-cyan-500/20">
                        <div class="flex items-center gap-3">
                            <div class="p-2.5 bg-cyan-500/10 rounded-lg border border-cyan-500/30 text-cyan-400">
                                <i class="fa-solid fa-diagram-project text-lg"></i>
                            </div>
                            <div>
                                <h4 class="text-sm font-bold text-cyan-200">Custom Destinations do PABX (Sincronização Ativa)</h4>
                                <p class="text-[11px] text-slate-400">Estes 3 Custom Destinations são gravados na tabela de destinos personalizados do PABX com os modelos de mensagem em tempo real no campo Notes!</p>
                            </div>
                        </div>
                        <div class="flex items-center gap-2">
                            <a href="index.php?module=whatsapp&action=regras" class="px-3.5 py-2 bg-slate-800 hover:bg-slate-700 text-slate-200 text-xs font-bold rounded-xl border border-slate-700 transition flex items-center gap-1.5 whitespace-nowrap">
                                <i class="fa-solid fa-sliders text-amber-400"></i> Editar Mensagens
                            </a>
                            <button type="submit" name="action_sync_custom_destinations" class="px-4 py-2 bg-cyan-600 hover:bg-cyan-500 text-white font-bold rounded-xl shadow-md transition flex items-center gap-2 whitespace-nowrap">
                                <i class="fa-solid fa-arrows-rotate"></i> ⚡ Sincronizar PABX
                            </button>
                        </div>
                    </div>

                    <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                        <!-- Custom Destination 1: Chamada Perdida -->
                        <div class="bg-slate-950/70 border border-slate-800 rounded-xl p-4 space-y-2">
                            <div class="flex items-center justify-between border-b border-slate-800 pb-2">
                                <span class="font-bold text-slate-200 text-xs flex items-center gap-2">
                                    <span class="px-2 py-0.5 bg-amber-500/10 text-amber-400 border border-amber-500/20 rounded font-mono text-[10px]">ext-prisma-no-answer,s,1</span>
                                    Notificação Chamada Perdida
                                </span>
                            </div>
                            <div class="text-[11px] text-slate-300 bg-slate-900/90 p-2.5 rounded-lg border border-slate-800/80 font-mono italic">
                                "<?php echo htmlspecialchars($currentMsgNoAnswer); ?>"
                            </div>
                        </div>

                        <!-- Custom Destination 3: Transbordo URA -->
                        <div class="bg-slate-950/70 border border-slate-800 rounded-xl p-4 space-y-2">
                            <div class="flex items-center justify-between border-b border-slate-800 pb-2">
                                <span class="font-bold text-slate-200 text-xs flex items-center gap-2">
                                    <span class="px-2 py-0.5 bg-purple-500/10 text-purple-400 border border-purple-500/20 rounded font-mono text-[10px]">ext-prisma-transbordo,s,1</span>
                                    Transbordo de Atendimento URA/Fila
                                </span>
                            </div>
                            <div class="text-[11px] text-slate-300 bg-slate-900/90 p-2.5 rounded-lg border border-slate-800/80 font-mono italic">
                                "<?php echo htmlspecialchars($currentMsgTransbordo); ?>"
                            </div>
                        </div>

                        <!-- Custom Destination 4: Áudio de Gravação -->
                        <div class="bg-slate-950/70 border border-slate-800 rounded-xl p-4 space-y-2">
                            <div class="flex items-center justify-between border-b border-slate-800 pb-2">
                                <span class="font-bold text-slate-200 text-xs flex items-center gap-2">
                                    <span class="px-2 py-0.5 bg-emerald-500/10 text-emerald-400 border border-emerald-500/20 rounded font-mono text-[10px]">ext-prisma-audio,s,1</span>
                                    Envio de Áudio de Gravação
                                </span>
                            </div>
                            <div class="text-[11px] text-slate-300 bg-slate-900/90 p-2.5 rounded-lg border border-slate-800/80 font-mono italic">
                                "<?php echo htmlspecialchars($currentMsgAudio); ?>"
                            </div>
                        </div>
                    </div>
                </div>

                <div class="pt-3 border-t border-slate-800 flex flex-wrap items-center justify-between gap-3">
                    <div class="flex flex-wrap items-center gap-2">
                        <button type="submit" name="action_save_prismabot_api" class="px-6 py-2.5 bg-amber-600 hover:bg-amber-500 text-white font-bold rounded-xl transition shadow-lg shadow-amber-600/20 flex items-center gap-1.5">
                            <i class="fa-solid fa-floppy-disk"></i> Salvar Credenciais Prismabot
                        </button>
                        <button type="submit" name="action_test_prismabot_api" class="px-5 py-2.5 bg-indigo-600 hover:bg-indigo-500 text-white font-bold rounded-xl transition shadow-lg shadow-indigo-600/20 flex items-center gap-1.5">
                            <i class="fa-solid fa-vial-circle-check"></i> ⚡ Testar Conexão API
                        </button>
                    </div>
                    <button type="submit" name="action_reset_prismabot_api" onclick="return confirm('Deseja redefinir as credenciais do Prismabot para os padrões?')" class="px-4 py-2 bg-rose-600/10 hover:bg-rose-600 text-rose-300 hover:text-white border border-rose-500/30 rounded-xl font-bold transition flex items-center gap-1.5">
                        <i class="fa-solid fa-rotate-left"></i> Redefinir Padrões Prismabot
                    </button>
                </div>
            </form>

        </div>
    </div>

    <!-- CONTEÚDO ABA 3: WHATSAPP DIRETO (QR CODE) -->
    <div id="tab-content-uazapi" class="space-y-4 tab-pane hidden">
        <?php
            $__prov  = waProvider();
            $__admin = uazapiFileConf() ? true : false;
            $__mode  = uazapiMode();
            $__btn = 'white-space:nowrap !important;flex-shrink:0 !important;width:auto !important;overflow:visible !important;text-overflow:clip !important;';
            $__needServer = !$__admin && getSetting('uazapi_token') === '';
        ?>
        <div class="bg-slate-900/90 border border-slate-800 rounded-2xl p-6 shadow-xl space-y-5">
            <div class="border-b border-slate-800 pb-4 flex flex-col md:flex-row md:items-center justify-between gap-3">
                <div class="flex items-center gap-3">
                    <div class="p-3 bg-emerald-500/10 rounded-xl border border-emerald-500/20 text-emerald-400"><i class="fa-brands fa-whatsapp text-xl"></i></div>
                    <div>
                        <h3 class="text-base font-bold text-slate-100">WhatsApp direto (QR Code)</h3>
                        <p class="text-xs text-slate-400">Envie avisos, pesquisas e gravações pelo seu próprio WhatsApp, sem precisar do Prismabot. É só ler um QR Code.</p>
                    </div>
                </div>
                <form method="POST" action="index.php?module=configuracoes&action=api" class="flex items-center gap-2 text-xs">
                    <input type="hidden" name="active_tab" value="uazapi">
                    <input type="hidden" name="wa_provider" value="uazapi">
                    <?php if ($__prov === 'uazapi'): ?>
                        <span style="white-space:nowrap" class="px-3 py-1.5 rounded-lg bg-emerald-500/15 text-emerald-400 font-bold"><i class="fa-solid fa-circle-check mr-1"></i> Em uso para os envios</span>
                    <?php else: ?>
                        <span style="white-space:nowrap" class="px-3 py-1.5 rounded-lg bg-slate-800 text-slate-400 font-bold">Não está em uso</span>
                        <button type="submit" name="action_set_wa_provider" style="<?php echo $__btn; ?>" class="px-4 py-2 bg-emerald-600 hover:bg-emerald-500 text-white font-bold rounded-xl">Usar este WhatsApp nos envios</button>
                    <?php endif; ?>
                </form>
            </div>

            <form method="POST" action="index.php?module=configuracoes&action=api" class="space-y-5 text-xs">
                <input type="hidden" name="active_tab" value="uazapi">

                <?php if ($__needServer): ?>
                <div id="wa-need-server" class="space-y-3 p-4 rounded-xl border border-amber-500/30 bg-amber-500/5">
                    <div class="font-bold text-amber-300 text-sm">Antes de conectar: dados do seu servidor</div>
                    <p class="text-slate-400">Informe o endereço e o token de administração do servidor de WhatsApp. O painel cria a conexão sozinho, você não precisa copiar mais nenhum token.</p>
                </div>
                <?php endif; ?>

                <details id="wa-server-box" <?php echo $__needServer ? 'open' : ''; ?> class="rounded-xl border border-slate-800 bg-slate-950/60">
                    <summary class="cursor-pointer select-none px-4 py-3 font-bold text-slate-200"><?php echo $__admin ? 'Opções avançadas (servidor próprio)' : 'Dados do servidor'; ?></summary>
                    <div class="px-4 pb-4 space-y-3">
                        <?php if ($__admin): ?>
                        <div class="grid grid-cols-1 md:grid-cols-2 gap-3">
                            <label class="flex items-start gap-3 p-3 rounded-xl border border-slate-800 bg-slate-950 cursor-pointer">
                                <input type="radio" name="uazapi_mode" value="prisma" <?php echo $__mode === 'prisma' ? 'checked' : ''; ?> onchange="toggleWaMode()" class="mt-1 accent-emerald-500">
                                <span><b class="text-slate-100">Servidor da Prisma</b> <span class="text-emerald-400">(recomendado)</span><br><span class="text-slate-400">Nada a configurar: é só conectar e ler o QR Code.</span></span>
                            </label>
                            <label class="flex items-start gap-3 p-3 rounded-xl border border-slate-800 bg-slate-950 cursor-pointer">
                                <input type="radio" name="uazapi_mode" value="own" <?php echo $__mode === 'own' ? 'checked' : ''; ?> onchange="toggleWaMode()" class="mt-1 accent-emerald-500">
                                <span><b class="text-slate-100">Servidor próprio</b><br><span class="text-slate-400">Informe o endereço e o token do seu servidor.</span></span>
                            </label>
                        </div>
                        <?php endif; ?>
                        <div id="wa-own-fields" class="space-y-3 <?php echo $__mode === 'own' ? '' : 'hidden'; ?>">
                            <div>
                                <label class="block font-bold text-slate-300 mb-1.5 uppercase tracking-wider text-[11px]">Endereço do servidor</label>
                                <input type="text" name="uazapi_url" value="<?php echo htmlspecialchars((string)($settings['uazapi_url'] ?? '')); ?>" placeholder="https://seu-servidor.exemplo.com" class="w-full px-3 py-2.5 bg-slate-950 border border-slate-800 rounded-xl text-white font-mono focus:border-emerald-500 focus:outline-none">
                            </div>
                            <div>
                                <label class="block font-bold text-slate-300 mb-1.5 uppercase tracking-wider text-[11px]">Token de administração do servidor</label>
                                <input type="password" name="uazapi_admin_token" value="" placeholder="<?php echo !empty($settings['uazapi_admin_token']) ? '•••••••• (salvo — deixe em branco para manter)' : 'Cole o token de administração'; ?>" autocomplete="off" class="w-full px-3 py-2.5 bg-slate-950 border border-slate-800 rounded-xl text-white font-mono focus:border-emerald-500 focus:outline-none">
                                <p class="text-[11px] text-slate-500 mt-1">Com o endereço e este token, o painel cria a conexão sozinho ao clicar em "Conectar WhatsApp".</p>
                            </div>
                            <div>
                                <button type="submit" name="action_save_uazapi" style="<?php echo $__btn; ?>" class="px-4 py-2 bg-slate-700 hover:bg-slate-600 text-white font-bold rounded-xl"><i class="fa-solid fa-floppy-disk mr-1"></i> Salvar dados do servidor</button>
                            </div>
                        </div>
                    </div>
                </details>

                <!-- Estado e ação principal -->
                <div class="rounded-2xl border border-slate-800 bg-slate-950 p-5 flex flex-col md:flex-row md:items-center justify-between gap-4">
                    <div class="flex items-center gap-3">
                        <span id="wa-state-badge" class="px-3 py-1.5 rounded-lg bg-slate-800 text-slate-300 font-bold text-sm" style="white-space:nowrap">Verificando…</span>
                        <span id="wa-state-detail" class="text-slate-400 text-sm"></span>
                    </div>
                    <div class="flex flex-wrap gap-2">
                        <button type="button" id="wa-btn-connect" onclick="waOpenQr()" style="<?php echo $__btn; ?>" class="px-6 py-3 bg-indigo-600 hover:bg-indigo-500 text-white font-bold rounded-xl text-sm"><i class="fa-solid fa-qrcode mr-1"></i> Conectar WhatsApp</button>
                        <button type="button" id="wa-btn-disc" onclick="waDisconnect()" style="<?php echo $__btn; ?>display:none;" class="px-5 py-3 bg-rose-700 hover:bg-rose-600 text-white font-bold rounded-xl text-sm"><i class="fa-solid fa-link-slash mr-1"></i> Desconectar / trocar número</button>
                        <button type="button" onclick="waTest(this)" style="<?php echo $__btn; ?>" class="px-5 py-3 bg-slate-700 hover:bg-slate-600 text-white font-bold rounded-xl text-sm"><i class="fa-solid fa-plug mr-1"></i> Testar conexão</button>
                    </div>
                </div>

                <ol class="grid grid-cols-1 md:grid-cols-3 gap-3 text-slate-300">
                    <li class="flex gap-3 p-4 rounded-xl border border-slate-800 bg-slate-950/60"><span class="w-6 h-6 shrink-0 rounded-full bg-indigo-600 text-white font-bold flex items-center justify-center">1</span><span>Clique em <b>Conectar WhatsApp</b>. Um QR Code aparece na tela.</span></li>
                    <li class="flex gap-3 p-4 rounded-xl border border-slate-800 bg-slate-950/60"><span class="w-6 h-6 shrink-0 rounded-full bg-indigo-600 text-white font-bold flex items-center justify-center">2</span><span>No celular, abra o WhatsApp e vá em <b>Aparelhos conectados</b> › <b>Conectar um aparelho</b>.</span></li>
                    <li class="flex gap-3 p-4 rounded-xl border border-slate-800 bg-slate-950/60"><span class="w-6 h-6 shrink-0 rounded-full bg-indigo-600 text-white font-bold flex items-center justify-center">3</span><span>Aponte a câmera para o QR Code. Quando aparecer <b>Conectado</b>, está pronto.</span></li>
                </ol>
            </form>

            <script>
            function toggleWaMode() {
                var o = document.querySelector('input[name="uazapi_mode"][value="own"]');
                document.getElementById('wa-own-fields').classList.toggle('hidden', !(o && o.checked));
            }
            var __waTimer = null, __waDlg = null, __waState = 'unknown', __waBusy = 0;
            function waJson(action, body) {
                return fetch('index.php?api_action=' + action, body === undefined ? {credentials: 'same-origin'} : {method: 'POST', credentials: 'same-origin', headers: {'Content-Type': 'application/json'}, body: JSON.stringify(body)})
                    .then(function (r) { return r.json(); });
            }
            function waSetBadge(state, detail) {
                var b = document.getElementById('wa-state-badge'), d = document.getElementById('wa-state-detail'); if (!b) return;
                var map = {connected: ['Conectado', 'bg-emerald-500/15 text-emerald-400'], connecting: ['Aguardando leitura do QR Code…', 'bg-amber-500/15 text-amber-400'], disconnected: ['Desconectado', 'bg-rose-500/15 text-rose-400'], not_configured: ['Ainda não conectado', 'bg-slate-800 text-slate-300'], error: ['Sem resposta do servidor', 'bg-rose-500/15 text-rose-400']};
                var m = map[state] || map.error;
                b.className = 'px-3 py-1.5 rounded-lg font-bold text-sm ' + m[1]; b.textContent = m[0]; d.textContent = detail || '';
                __waState = state;
                var ns = document.getElementById('wa-need-server'); if (ns && state === 'connected') ns.style.display = 'none';
                var disc = document.getElementById('wa-btn-disc'); if (disc) disc.style.display = (state === 'connected' || state === 'connecting') ? '' : 'none';
                var cb = document.getElementById('wa-btn-connect'); if (cb) cb.style.display = (state === 'connected') ? 'none' : '';
                if (state === 'not_configured' && !detail) d.textContent = 'Clique em "Conectar WhatsApp" para começar.';
            }
            function waPollStatus() {
                return waJson('uazapi_status').then(function (j) {
                    var det = '';
                    if (j.state === 'connected') det = (j.name ? j.name + ' · ' : '') + (j.phone ? '+' + j.phone : '');
                    else if (j.error) det = j.error;
                    waSetBadge(j.state || 'error', det);
                    return j;
                }).catch(function () { waSetBadge('error', 'Não foi possível consultar o servidor.'); return {}; });
            }
            function waTest(btn) {
                var d = document.getElementById('wa-state-detail'); if (d) d.textContent = 'Testando…';
                waJson('uazapi_status').then(function (j) {
                    waPollStatus().then(function () {
                        var d2 = document.getElementById('wa-state-detail');
                        if (j.state === 'connected') d2.textContent = '✅ Teste ok: WhatsApp conectado e respondendo.';
                        else if (j.state === 'error') d2.textContent = '⚠️ O servidor não respondeu. Confira o endereço e o token.';
                        else d2.textContent = '⚠️ Servidor ok, mas o WhatsApp não está conectado. Clique em "Conectar WhatsApp".';
                    });
                });
            }
            function waForm() {
                var g = function (n) { var e = document.querySelector('[name="' + n + '"]'); return e ? e.value.trim() : ''; };
                var m = document.querySelector('input[name="uazapi_mode"]:checked');
                return {mode: m ? m.value : 'own', url: g('uazapi_url'), admin_token: g('uazapi_admin_token'), token: g('uazapi_token')};
            }
            function waDialog() {
                if (__waDlg) return __waDlg;
                var d = document.createElement('dialog');
                d.id = 'modal-wa-qr';
                d.style.cssText = 'border:1px solid #334155;border-radius:18px;background:#0f172a;color:#e2e8f0;padding:26px;max-width:620px;width:94%;';
                var btn = 'white-space:nowrap !important;flex-shrink:0 !important;width:auto !important;overflow:visible !important;text-overflow:clip !important;padding:10px 16px;border-radius:10px;color:#fff;font-weight:700;font-size:12px;';
                var inp = 'width:100%;padding:10px 12px;border-radius:10px;background:#020617;border:1px solid #1e293b;color:#fff;font-size:13px;';
                var num = 'width:22px;height:22px;flex-shrink:0;border-radius:50%;background:#4f46e5;color:#fff;font-weight:700;font-size:12px;display:flex;align-items:center;justify-content:center;';
                d.innerHTML = '<style>#modal-wa-qr::backdrop{background:rgba(0,0,0,.65);backdrop-filter:blur(4px)}</style>' +
                    '<div style="font-weight:800;font-size:17px">Conectar WhatsApp</div>' +
                    '<div id="wa-warn" style="display:none;margin-top:10px;padding:10px 12px;border-radius:10px;background:rgba(244,63,94,.12);border:1px solid rgba(244,63,94,.35);color:#fda4af;font-size:12px"></div>' +
                    '<div style="display:flex;flex-wrap:wrap;gap:22px;margin-top:16px;align-items:flex-start">' +
                      '<div style="flex:0 0 250px;max-width:100%">' +
                        '<div id="wa-qr-box" style="background:#fff;border-radius:14px;width:250px;height:250px;max-width:100%;display:flex;align-items:center;justify-content:center;text-align:center;color:#475569;font-size:12px;padding:8px">Gerando o QR Code…</div>' +
                        '<div id="wa-live" style="margin-top:10px;font-size:12px;color:#94a3b8;display:flex;align-items:center;gap:8px"><span style="width:8px;height:8px;border-radius:50%;background:#f59e0b;display:inline-block"></span><span id="wa-live-txt">Aguardando…</span></div>' +
                      '</div>' +
                      '<div style="flex:1 1 220px;min-width:210px">' +
                        '<div style="font-weight:700;font-size:13px;margin-bottom:10px;color:#cbd5e1">Faça assim no celular:</div>' +
                        '<div style="display:flex;gap:10px;margin-bottom:10px;font-size:13px"><span style="' + num + '">1</span><span>Abra o <b>WhatsApp</b>.</span></div>' +
                        '<div style="display:flex;gap:10px;margin-bottom:10px;font-size:13px"><span style="' + num + '">2</span><span>Toque em <b>Mais opções (⋮)</b> no Android ou em <b>Configurações</b> no iPhone, depois em <b>Aparelhos conectados</b>.</span></div>' +
                        '<div style="display:flex;gap:10px;margin-bottom:10px;font-size:13px"><span style="' + num + '">3</span><span>Toque em <b>Conectar um aparelho</b> e aponte a câmera para o QR Code ao lado.</span></div>' +
                        '<div style="font-size:11px;color:#64748b">O QR Code é renovado sozinho. Se a leitura falhar, gere um novo.</div>' +
                      '</div>' +
                    '</div>' +
                    '<div id="wa-pair-panel" style="display:none;margin-top:16px;padding:14px;border-radius:12px;border:1px solid #1e293b;background:#020617">' +
                      '<div style="font-weight:700;font-size:13px;margin-bottom:6px">Sem câmera? Conecte com um código</div>' +
                      '<div style="font-size:12px;color:#94a3b8;margin-bottom:8px">Informe o número do WhatsApp que será conectado (DDD + número). No celular, em <b>Conectar um aparelho</b>, escolha <b>Conectar com número de telefone</b> e digite o código que aparecer aqui.</div>' +
                      '<div style="display:flex;gap:8px;flex-wrap:wrap"><input id="wa-in-phone" type="text" placeholder="Ex.: 11999998888" style="' + inp + 'flex:1 1 180px;width:auto"><button type="button" id="wa-pair-go" style="' + btn + 'background:#4f46e5">Gerar código</button></div>' +
                      '<div id="wa-pair-code" style="margin-top:10px;font-size:22px;font-weight:800;letter-spacing:3px;color:#a5b4fc"></div>' +
                    '</div>' +
                    '<div id="wa-note" style="margin-top:12px;font-size:12px;color:#94a3b8"></div>' +
                    '<div style="margin-top:16px;display:flex;gap:8px;flex-wrap:wrap">' +
                      '<button type="button" id="wa-regen" style="' + btn + 'background:#4f46e5">Gerar novo QR Code</button>' +
                      '<button type="button" id="wa-pair-toggle" style="' + btn + 'background:#334155">Conectar por código</button>' +
                      '<button type="button" id="wa-qr-close" style="' + btn + 'background:#475569">Fechar</button>' +
                    '</div>';
                document.body.appendChild(d);
                d.querySelector('#wa-qr-close').onclick = function () { d.close(); };
                d.querySelector('#wa-regen').onclick = function () { d.querySelector('#wa-pair-code').textContent = ''; waStart(''); };
                d.querySelector('#wa-pair-toggle').onclick = function () { var p = d.querySelector('#wa-pair-panel'); p.style.display = (p.style.display === 'none') ? '' : 'none'; };
                d.querySelector('#wa-pair-go').onclick = function () {
                    var ph = d.querySelector('#wa-in-phone').value.replace(/\D/g, '');
                    if (ph.length < 10) { d.querySelector('#wa-note').textContent = 'Número inválido. Informe DDD + número.'; return; }
                    if (ph.length <= 11) ph = '55' + ph;
                    waStart(ph);
                };
                d.addEventListener('close', function () { if (__waTimer) { clearInterval(__waTimer); __waTimer = null; } waPollStatus(); });
                __waDlg = d; return d;
            }
            function waLive(color, txt) {
                var d = waDialog(); d.querySelector('#wa-live span').style.background = color; d.querySelector('#wa-live-txt').textContent = txt;
            }
            function waStart(phone) {
                var d = waDialog(), box = d.querySelector('#wa-qr-box'), note = d.querySelector('#wa-note'), warn = d.querySelector('#wa-warn');
                warn.style.display = 'none'; note.textContent = '';
                box.innerHTML = 'Criando o canal e gerando o QR Code…'; waLive('#f59e0b', 'Preparando…');
                if (__waTimer) { clearInterval(__waTimer); __waTimer = null; }
                var token = ++__waBusy;
                var body = waForm(); body.name = 'Atendimento'; if (phone) body.phone = phone;
                waJson('uazapi_connect', body).then(function (j) {
                    if (token !== __waBusy) return;
                    if (!j.success) { box.innerHTML = '—'; waLive('#f43f5e', 'Não conectou'); warn.textContent = '⚠️ ' + (j.error || 'Não foi possível conectar.'); warn.style.display = ''; return; }
                    var tick = function () {
                        waPollStatus().then(function (s) {
                            if (token !== __waBusy) return;
                            if (s.state === 'connected') {
                                clearInterval(__waTimer); __waTimer = null;
                                box.innerHTML = '<div style="color:#059669;font-weight:800;font-size:16px">✅ WhatsApp conectado<div style="font-weight:400;font-size:12px;color:#475569;margin-top:6px">' + (s.name || '') + '</div></div>';
                                waLive('#10b981', 'Conectado! As mensagens do painel já saem por este WhatsApp.');
                                setTimeout(function () { if (d.open) d.close(); }, 2500); return;
                            }
                            if (s.qr) { var im = box.querySelector('img'); if (!im) { box.innerHTML = ''; im = document.createElement('img'); im.alt = 'QR Code do WhatsApp'; im.style.cssText = 'width:100%;height:100%;object-fit:contain'; box.appendChild(im); } im.src = s.qr; waLive('#f59e0b', 'Aguardando você ler o QR Code…'); }
                            if (s.paircode) { d.querySelector('#wa-pair-panel').style.display = ''; d.querySelector('#wa-pair-code').textContent = s.paircode; waLive('#f59e0b', 'Aguardando você digitar o código no celular…'); }
                        });
                    };
                    tick(); __waTimer = setInterval(tick, 3000);
                }).catch(function () { if (token !== __waBusy) return; box.innerHTML = '—'; waLive('#f43f5e', 'Falha de comunicação'); warn.textContent = '⚠️ Falha de comunicação com o painel.'; warn.style.display = ''; });
            }
            function waOpenQr() {
                var f = waForm(), d = waDialog();
                var needs = (__waState === 'not_configured' || __waState === 'unknown') && f.token === '' && f.mode === 'own' && f.url === '';
                if (needs) {
                    var box = document.getElementById('wa-server-box'); if (box) { box.open = true; var u = document.querySelector('[name="uazapi_url"]'); if (u) u.focus(); }
                    alert('Antes de conectar, informe o endereço do servidor e o token de administração em "Dados do servidor".');
                    return;
                }
                if (!d.open) d.showModal();
                d.querySelector('#wa-pair-panel').style.display = 'none'; d.querySelector('#wa-pair-code').textContent = '';
                waStart('');
            }
            function waDisconnect() {
                var d = document.createElement('dialog');
                d.style.cssText = 'border:1px solid #334155;border-radius:16px;background:#0f172a;color:#e2e8f0;padding:22px;max-width:380px;width:92%;';
                d.innerHTML = '<style>dialog::backdrop{background:rgba(0,0,0,.65);backdrop-filter:blur(4px)}</style><div style="font-weight:700;margin-bottom:8px">Desconectar este WhatsApp?</div><div style="font-size:12px;color:#94a3b8;margin-bottom:14px">Os avisos e pesquisas deixam de sair até você conectar de novo (você poderá ler o QR Code com outro número).</div><div style="display:flex;gap:8px"><button type="button" id="wd-ok" style="white-space:nowrap;flex-shrink:0;padding:8px 14px;border-radius:10px;background:#be123c;color:#fff;font-weight:700;font-size:12px">Desconectar</button><button type="button" id="wd-no" style="white-space:nowrap;flex-shrink:0;padding:8px 14px;border-radius:10px;background:#475569;color:#fff;font-weight:700;font-size:12px">Cancelar</button></div>';
                document.body.appendChild(d); d.showModal();
                d.querySelector('#wd-no').onclick = function () { d.close(); d.remove(); };
                d.querySelector('#wd-ok').onclick = function () {
                    waJson('uazapi_disconnect', {}).then(function (j) { d.close(); d.remove(); if (!j.success) { alert('Não foi possível desconectar: ' + (j.error || '')); } waPollStatus(); });
                };
            }
            </script>
        </div>
    </div>

    <!-- CONTEÚDO ABA 4: AVISOS E TESTE DE ENVIO (valem para o servidor em uso) -->
    <div id="tab-content-avisos" class="space-y-4 tab-pane hidden">
        <div class="bg-slate-900/90 border border-slate-800 rounded-2xl p-6 shadow-xl space-y-4">
            <div class="border-b border-slate-800 pb-3 flex flex-col md:flex-row md:items-center justify-between gap-3">
                <div class="flex items-center gap-3">
                    <div class="p-3 bg-indigo-500/10 rounded-xl border border-indigo-500/20 text-indigo-400"><i class="fa-solid fa-bell text-xl"></i></div>
                    <div>
                        <h3 class="text-base font-bold text-slate-100">Avisos e teste de envio</h3>
                        <p class="text-xs text-slate-400">Valem para qualquer servidor de envio: chamadas perdidas, tronco fora do ar, links de gravação e teste de disparo.</p>
                    </div>
                </div>
                <span style="white-space:nowrap" class="px-3 py-1.5 rounded-lg bg-slate-800 text-slate-200 text-xs font-bold">Enviando por: <?php echo waProvider() === 'uazapi' ? 'WhatsApp direto (QR Code)' : 'API Prismabot'; ?></span>
            </div>
            <!-- URL PÚBLICA DO PAINEL (links assinados de gravação) -->
            <div class="pt-4 border-t border-slate-800/80">
                <form method="POST" action="" class="bg-slate-950/80 p-4 rounded-2xl border border-cyan-500/30 space-y-2">
                    <div class="flex items-center gap-2 border-b border-slate-800 pb-2">
                        <i class="fa-solid fa-link text-cyan-400 text-base"></i>
                        <strong class="text-white text-xs">URL pública do painel (links de gravação)</strong>
                    </div>
                    <p class="text-[11px] text-slate-400">Endereço pelo qual o atendente alcança o painel fora da rede interna. Usado para montar o link temporário (7 dias, assinado) da gravação enviado por WhatsApp aos ramais com "Enviar Link da Gravação". É opcional: por padrão a gravação segue como arquivo anexo (até 15 MB), que funciona com IP interno, IP externo ou DDNS. O link só é usado em gravações maiores.</p>
                    <input type="hidden" name="action_save_public_url" value="1">
                    <input type="text" name="public_base_url" value="<?php echo htmlspecialchars((string)getSetting('public_base_url')); ?>" placeholder="https://pbx.seudominio.com.br/front_api" class="w-full bg-slate-900 border border-slate-700 rounded-lg px-3 py-2 text-[11px] text-cyan-200 font-mono focus:border-cyan-500 focus:outline-none">
                    <button type="submit" style="white-space:nowrap !important;flex-shrink:0 !important;width:auto !important;overflow:visible !important;text-overflow:clip !important" class="px-4 py-2 bg-cyan-600 hover:bg-cyan-500 text-white font-bold rounded-xl text-xs transition"><i class="fa-solid fa-floppy-disk"></i> Salvar URL</button>
                </form>
            </div>

            <!-- CHAMADAS PERDIDAS: REGRAS INTELIGENTES -->
            <?php $__mr = getSetting('missed_min_ring_seconds'); $__mr = ($__mr === '' || !is_numeric($__mr)) ? 10 : (int)$__mr; ?>
            <div class="pt-4 border-t border-slate-800/80">
                <form method="POST" action="" class="bg-slate-950/80 p-4 rounded-2xl border border-amber-500/30 space-y-2">
                    <input type="hidden" name="active_tab" value="prismabot">
                    <div class="flex items-center gap-2 border-b border-slate-800 pb-2">
                        <i class="fa-solid fa-phone-slash text-amber-400 text-base"></i>
                        <strong class="text-white text-xs">Chamadas perdidas: quando avisar</strong>
                    </div>
                    <p class="text-[11px] text-slate-400">Evita avisos falsos: não avisa se a chamada tocou menos que o tempo mínimo (o cliente desistiu antes de dar tempo de atender) nem se outro ramal atendeu a mesma chamada (grupo de toque, fila ou captura).</p>
                    <label class="flex items-center gap-2 text-xs text-slate-300">Tempo mínimo de toque (segundos)
                        <input type="number" min="0" max="60" name="missed_min_ring_seconds" value="<?php echo $__mr; ?>" class="w-20 px-2 py-1 bg-slate-900 border border-slate-700 rounded-lg text-white"> <span class="text-slate-500">0 = avisar sempre</span></label>
                    <label class="flex items-center gap-2 text-xs text-slate-300"><input type="checkbox" name="missed_ignore_answered_elsewhere" value="1" <?php echo getSetting('missed_ignore_answered_elsewhere') !== '0' ? 'checked' : ''; ?>> Não avisar se a chamada foi atendida por outro ramal</label>
                    <div class="flex justify-end">
                        <button type="submit" name="action_save_missed_rules" value="1" style="white-space:nowrap !important;flex-shrink:0 !important;width:auto !important;overflow:visible !important;text-overflow:clip !important" class="px-4 py-2 bg-amber-600 hover:bg-amber-500 text-white font-bold rounded-xl text-xs"><i class="fa-solid fa-floppy-disk mr-1"></i> Salvar</button>
                    </div>
                </form>
            </div>

            <!-- ALERTA DE TRONCO FORA DO AR -->
            <div class="pt-4 border-t border-slate-800/80">
                <form method="POST" action="" class="bg-slate-950/80 p-4 rounded-2xl border border-rose-500/30 space-y-2">
                    <div class="flex items-center gap-2 border-b border-slate-800 pb-2">
                        <i class="fa-solid fa-triangle-exclamation text-rose-400 text-base"></i>
                        <strong class="text-white text-xs">Alerta de tronco fora do ar (WhatsApp)</strong>
                    </div>
                    <p class="text-[11px] text-slate-400">Avisa uma única vez quando uma linha ficar desconectada por mais de 1 minuto e avisa de novo quando ela voltar. Usa o servidor de envio que estiver em uso (indicado no topo desta aba).</p>
                    <label class="flex items-center gap-2 text-xs text-slate-300"><input type="checkbox" name="trunk_alert_enabled" value="1" <?php echo getSetting('trunk_alert_enabled') === '1' ? 'checked' : ''; ?>> Ativar alerta de tronco</label>
                    <input type="text" name="trunk_alert_numbers" value="<?php echo htmlspecialchars((string)getSetting('trunk_alert_numbers')); ?>" placeholder="Números que recebem o alerta (DDD + telefone), separados por vírgula" class="w-full bg-slate-900 border border-slate-700 rounded-lg px-3 py-2 text-xs text-white font-mono">
                    <div class="flex items-center gap-2 justify-end">
                        <button type="submit" name="action_test_trunk_alert" value="1" style="white-space:nowrap !important;flex-shrink:0 !important;width:auto !important;overflow:visible !important;text-overflow:clip !important" class="px-4 py-2 bg-slate-800 hover:bg-slate-700 text-slate-200 font-bold rounded-xl border border-slate-700 text-xs">Salvar e Testar</button>
                        <button type="submit" name="action_save_trunk_alert" value="1" style="white-space:nowrap !important;flex-shrink:0 !important;width:auto !important;overflow:visible !important;text-overflow:clip !important" class="px-4 py-2 bg-rose-600 hover:bg-rose-500 text-white font-bold rounded-xl text-xs"><i class="fa-solid fa-floppy-disk mr-1"></i> Salvar</button>
                    </div>
                </form>
            </div>

            <!-- CAIXA DE TESTE DE DISPARO DIRETO NA PRÓPRIA TELA -->
            <div class="pt-4 border-t border-slate-800/80">
                <form method="POST" action="index.php?module=configuracoes&action=api" class="bg-slate-950/80 p-4 rounded-2xl border border-indigo-500/30 space-y-3">
                    <input type="hidden" name="active_tab" value="prismabot">
                    <div class="flex items-center gap-2 border-b border-slate-800 pb-2">
                        <i class="fa-brands fa-whatsapp text-emerald-400 text-base"></i>
                        <strong class="text-white text-xs">💬 Teste de Disparo Direto de Mensagem (Sem sair desta página)</strong>
                    </div>
                    <div class="grid grid-cols-1 md:grid-cols-12 gap-3 text-xs">
                        <div class="md:col-span-4">
                            <label class="block font-bold text-slate-400 mb-1 text-[10px]">Telefone Destino (DDD + Número)</label>
                            <input type="text" name="test_wa_phone" value="1699637020" placeholder="Ex: 1699637020" required class="w-full px-3 py-2 bg-slate-900 border border-slate-700 rounded-xl text-white font-mono">
                        </div>
                        <div class="md:col-span-8">
                            <label class="block font-bold text-slate-400 mb-1 text-[10px]">Mensagem de Teste</label>
                            <input type="text" name="test_wa_msg" value="Olá! Teste de disparo instantâneo IPbx Prisma x Prismabot WhatsApp API." required class="w-full px-3 py-2 bg-slate-900 border border-slate-700 rounded-xl text-white font-mono">
                        </div>
                    </div>
                    <div class="flex justify-end pt-1">
                        <button type="submit" name="action_send_direct_test_wa" class="px-5 py-2 bg-emerald-600 hover:bg-emerald-500 text-white font-bold rounded-xl text-xs transition shadow-md shadow-emerald-600/30 flex items-center gap-1.5">
                            <i class="fa-solid fa-paper-plane"></i> 🚀 Disparar Mensagem de Teste Direto
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- CONTEÚDO ABA 3: MYSQL CDR DATABASE -->
    <div id="tab-content-mysql" class="space-y-4 tab-pane hidden">
        <div class="bg-slate-900/90 border border-slate-800 rounded-2xl p-6 shadow-xl space-y-4">
            <div class="border-b border-slate-800 pb-3 flex items-center gap-3">
                <div class="p-3 bg-purple-500/10 rounded-xl border border-purple-500/20 text-purple-400">
                    <i class="fa-solid fa-database text-xl"></i>
                </div>
                <div>
                    <h3 class="text-base font-bold text-slate-100">
                        Banco de Dados MySQL CDR
                    </h3>
                    <p class="text-xs text-slate-400">Leitura direta do histórico de gravações, relatórios e métricas do PABX (Autodetectado)</p>
                </div>
            </div>

            <form method="POST" action="index.php?module=configuracoes&action=api" class="space-y-4 text-xs">
                <input type="hidden" name="active_tab" value="mysql">
                <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                    <div>
                        <label class="block font-bold text-slate-300 mb-1.5 uppercase tracking-wider text-[11px]">Host MySQL / IP Remoto</label>
                        <input type="text" name="mysql_host" value="<?php echo htmlspecialchars($currentMyHost); ?>" required
                               class="w-full px-4 py-2.5 bg-slate-950 border border-slate-800 rounded-xl text-white font-mono focus:border-purple-500 focus:outline-none">
                    </div>
                    <div>
                        <label class="block font-bold text-slate-300 mb-1.5 uppercase tracking-wider text-[11px]">Porta MySQL</label>
                        <input type="text" name="mysql_port" value="<?php echo htmlspecialchars($currentMyPort); ?>" required
                               class="w-full px-4 py-2.5 bg-slate-950 border border-slate-800 rounded-xl text-white font-mono focus:border-purple-500 focus:outline-none">
                    </div>
                </div>

                <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                    <div>
                        <label class="block font-bold text-slate-300 mb-1.5 uppercase tracking-wider text-[11px]">Usuário MySQL DB</label>
                        <input type="text" name="mysql_user" value="<?php echo htmlspecialchars($currentMyUser); ?>" required
                               class="w-full px-4 py-2.5 bg-slate-950 border border-slate-800 rounded-xl text-white font-mono focus:border-purple-500 focus:outline-none">
                    </div>
                    <div>
                        <label class="block font-bold text-slate-300 mb-1.5 uppercase tracking-wider text-[11px]">Senha MySQL DB (Autodetectada)</label>
                        <input type="password" name="mysql_pass" value="<?php echo htmlspecialchars($currentMyPass); ?>" required
                               class="w-full px-4 py-2.5 bg-slate-950 border border-slate-800 rounded-xl text-white font-mono focus:border-purple-500 focus:outline-none">
                    </div>
                </div>

                <div class="pt-3 border-t border-slate-800 flex flex-wrap items-center justify-between gap-3">
                    <div class="flex items-center gap-3">
                        <button type="submit" name="action_save_mysql" class="px-5 py-2 bg-slate-800 hover:bg-slate-700 text-slate-200 font-bold rounded-xl border border-slate-700 transition">
                            Salvar Credenciais MySQL
                        </button>
                        <button type="submit" name="action_test_mysql" class="px-5 py-2 bg-purple-600 hover:bg-purple-500 text-white font-bold rounded-xl shadow-lg shadow-purple-600/20 transition flex items-center gap-1.5">
                            <i class="fa-solid fa-database"></i> 🗄️ Testar Conexão Banco MySQL (CDR)
                        </button>
                    </div>
                    <button type="submit" name="action_reset_mysql" onclick="return confirm('Deseja redefinir as credenciais do Banco MySQL para os padrões?')" class="px-4 py-2 bg-rose-600/10 hover:bg-rose-600 text-rose-300 hover:text-white border border-rose-500/30 rounded-xl font-bold transition flex items-center gap-1.5">
                        <i class="fa-solid fa-rotate-left"></i> Redefinir Padrões MySQL
                    </button>
                </div>
            </form>
        </div>
    </div>

    <!-- CONTEÚDO ABA 4: RESTAURAÇÃO GLOBAL DE FÁBRICA -->
    <div id="tab-content-reset" class="space-y-4 tab-pane hidden">
        <div class="bg-slate-900/90 border border-rose-500/30 rounded-2xl p-5 shadow-xl space-y-3">
            <div class="border-b border-slate-800 pb-2">
                <h4 class="text-xs font-extrabold text-rose-400 flex items-center gap-2">
                    <i class="fa-solid fa-triangle-exclamation"></i> Zona de Restauração Global do Sistema
                </h4>
                <span class="text-[11px] text-slate-400">Restaura todas as tabelas de integrações, regras e credenciais para os padrões originais de fábrica.</span>
            </div>

            <form method="POST" action="index.php?module=configuracoes&action=api" onsubmit="return confirm('Deseja realmente restaurar TODAS as configurações do sistema para o padrão de fábrica?')">
                <input type="hidden" name="active_tab" value="reset">
                <input type="hidden" name="action_reset_defaults" value="1">
                <button type="submit" class="px-4 py-2 bg-rose-600/20 hover:bg-rose-600 text-rose-300 hover:text-white border border-rose-500/40 rounded-xl font-bold text-xs transition flex items-center gap-2">
                    <i class="fa-solid fa-rotate-left"></i> Restaurar Padrões de Fábrica de Todo o Sistema
                </button>
            </form>
        </div>
    </div>
</div>

<script>
function switchApiTab(tabName) {
    // Esconder todas as abas
    document.querySelectorAll('.tab-pane').forEach(function(pane) {
        pane.classList.add('hidden');
    });

    // Resetar estilos dos botões
    const btnClassInactive = "px-4 py-2.5 rounded-xl transition flex items-center gap-2 text-slate-400 hover:text-white hover:bg-slate-800/50";
    const btnClassActive   = "px-4 py-2.5 rounded-xl transition flex items-center gap-2 bg-brand-600 text-white font-extrabold shadow-lg shadow-brand-600/30 border border-brand-500/40";

    ['pbxapi', 'prismabot', 'uazapi', 'avisos', 'mysql', 'reset'].forEach(function(t) {
        var btn = document.getElementById('btn-tab-' + t);
        if (btn) {
            btn.className = (t === tabName) ? btnClassActive : btnClassInactive;
        }
    });

    // Exibir conteúdo da aba selecionada
    var targetPane = document.getElementById('tab-content-' + tabName);
    if (targetPane) {
        targetPane.classList.remove('hidden');
    }
    if (tabName === 'uazapi' && typeof waPollStatus === 'function') waPollStatus();
}

// Inicializar aba ativa
document.addEventListener("DOMContentLoaded", function() {
    switchApiTab('<?php echo htmlspecialchars($active_tab); ?>');
});
</script>

<script>
async function testConnection(action, btnId) {
    const btn = document.getElementById(btnId);
    if (!btn) return;
    const origHTML = btn.innerHTML;
    btn.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> Testando...';
    btn.disabled = true;

    try {
        const res = await fetch('index.php?api_action=' + action);
        const data = await res.json();
        alert(data.message || (data.success ? 'Conexão estabelecida com SUCESSO!' : '❌ Falha: ' + data.error));
    } catch(err) {
        alert('❌ Erro de requisição: ' + err.message);
    } finally {
        btn.innerHTML = origHTML;
        btn.disabled = false;
    }
}
</script>
