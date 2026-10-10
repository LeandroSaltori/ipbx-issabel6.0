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
$active_tab = isset($_POST['active_tab']) ? $_POST['active_tab'] : 'pbxapi';

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

    // 2. API Prismabot & Sincronização de Custom Destinations
    if (isset($_POST['action_save_prismabot_api']) || isset($_POST['action_test_prismabot_api']) || isset($_POST['action_sync_custom_destinations'])) {
        $active_tab = 'prismabot';
        $bot_url        = trim($_POST['prismabot_url'] ?? '');
        $bot_token      = trim($_POST['prismabot_token'] ?? '');
        $msg_no_answer  = trim($_POST['prismabot_msg_no_answer'] ?? '');
        $msg_nps        = trim($_POST['prismabot_msg_nps'] ?? '');
        $msg_transbordo = trim($_POST['prismabot_msg_transbordo'] ?? '');
        $msg_audio      = trim($_POST['prismabot_msg_audio'] ?? '');

        try {
            if (!empty($bot_url))   saveSetting('api_url', $bot_url);
            if (!empty($bot_token)) saveSetting('api_token', $bot_token);
            if ($msg_no_answer !== '')  saveSetting('prismabot_msg_no_answer', $msg_no_answer);
            if ($msg_nps !== '')        saveSetting('prismabot_msg_nps', $msg_nps);
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
                    $msg_status = "⚡ Conexão com API Prismabot / Z-PRO realizada com SUCESSO! (HTTP $httpCode)";
                    logUserAction("Teste Prismabot API", "Conexão bem sucedida HTTP $httpCode", "CONFIG");
                } elseif (strpos((string)$out, 'ERR_API_REQUIRES_SESSION') !== false || ($httpCode >= 400 && $httpCode < 500 && $httpCode !== 403 && $httpCode !== 404)) {
                    $msg_status = "⚡ API Z-PRO / Prismabot ALCANÇADA com sucesso! A URL do Endpoint é válida e responde a requisições POST (HTTP $httpCode).";
                    logUserAction("Teste Prismabot API", "API Z-PRO ativa HTTP $httpCode", "CONFIG");
                } elseif ($httpCode === 403) {
                    $msg_error = "⚠️ Falha de Autenticação (HTTP 403: Invalid Token). O token fornecido não foi aceito pela API Z-PRO / Prismabot.";
                    logSystemError("Erro Auth 403 Teste Prismabot", ['response' => $out, 'url' => $bot_url]);
                } elseif ($httpCode === 404) {
                    $msg_error = "⚠️ Endpoint não encontrado (HTTP 404). Verifique se a URL da API Z-PRO foi colada corretamente.";
                    logSystemError("Erro HTTP 404 Teste Prismabot", ['url' => $bot_url]);
                } else {
                    $msg_status = "⚡ API Prismabot/Z-PRO alcançada com código HTTP $httpCode.";
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
        $active_tab = 'prismabot';
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
                    $msg_error = "⚠️ Falha no envio (HTTP 400: ERR_API_REQUIRES_SESSION). A instância do WhatsApp no painel Z-PRO está desconectada (necessita escanear o QR Code no Z-PRO) ou requer a inclusão do ID da Sessão (WhatsApp ID).";
                } elseif (strpos($err_detail, '403') !== false) {
                    $msg_error = "⚠️ Falha de Autenticação (HTTP 403: Invalid Token). Verifique se o Token colado em Configurações é o token ativo no Z-PRO.";
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
                    $msg_status = "🗄️ Conexão com o Banco de Dados MySQL (asteriskcdrdb) realizada com SUCESSO!";
                } catch (Exception $em) {
                    logSystemError("Falha conexao MySQL Asterisk", ['error' => $em->getMessage(), 'host' => $m_host]);
                    $msg_error = "Falha ao conectar no MySQL: " . $em->getMessage();
                }
            } else {
                $msg_status = "Configurações do Banco MySQL salvas com sucesso!";
            }
        } catch (Exception $e) {
            logSystemError("Erro ao salvar dados MySQL Asterisk", ['error' => $e->getMessage()]);
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
$currentMsgNps       = !empty($settings['prismabot_msg_nps'])        ? $settings['prismabot_msg_nps']        : 'Olá {NOME}, obrigado por falar conosco! Em uma escala de 0 a 10, como você avalia nosso atendimento?';
$currentMsgTransbordo= !empty($settings['prismabot_msg_transbordo']) ? $settings['prismabot_msg_transbordo'] : 'Olá {NOME}, seu atendimento superou o tempo limite de espera. Em instantes um consultor entrará em contato!';
$currentMsgAudio     = !empty($settings['prismabot_msg_audio'])      ? $settings['prismabot_msg_audio']      : 'Olá {NOME}, segue a gravação da sua chamada realizada em {DATA}.';

$currentMyHost   = !empty($settings['asterisk_db_host']) ? $settings['asterisk_db_host'] : 'localhost';
$currentMyPort   = !empty($settings['asterisk_db_port']) ? $settings['asterisk_db_port'] : '3306';
$currentMyUser   = !empty($settings['asterisk_db_user']) ? $settings['asterisk_db_user'] : 'asteriskuser';
$currentMyPass   = !empty($settings['asterisk_db_pass']) ? $settings['asterisk_db_pass'] : $auto_pass;

// Tentar autenticar silenciosamente na REST API se token zerado
if (empty($currentPbxToken) && !empty($currentPbxPass) && $_SERVER['REQUEST_METHOD'] !== 'POST') {
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
            <span>1. REST API PABX (`pbxapi`)</span>
        </button>
        <button type="button" onclick="switchApiTab('prismabot')" id="btn-tab-prismabot" 
                class="px-4 py-2.5 rounded-xl transition flex items-center gap-2 text-slate-400 hover:text-white">
            <i class="fa-solid fa-key text-amber-400"></i>
            <span>2. API Prismabot WhatsApp</span>
        </button>
        <button type="button" onclick="switchApiTab('mysql')" id="btn-tab-mysql" 
                class="px-4 py-2.5 rounded-xl transition flex items-center gap-2 text-slate-400 hover:text-white">
            <i class="fa-solid fa-database text-purple-400"></i>
            <span>3. Banco MySQL CDR</span>
        </button>
        <button type="button" onclick="switchApiTab('reset')" id="btn-tab-reset" 
                class="px-4 py-2.5 rounded-xl transition flex items-center gap-2 text-slate-400 hover:text-white">
            <i class="fa-solid fa-triangle-exclamation text-rose-400"></i>
            <span>4. Restauração Global</span>
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
        <div class="bg-slate-900/90 border border-slate-800 rounded-2xl p-6 shadow-xl space-y-4">
            <div class="border-b border-slate-800 pb-3 flex items-center gap-3">
                <div class="p-3 bg-amber-500/10 rounded-xl border border-amber-500/20 text-amber-400">
                    <i class="fa-solid fa-key text-xl"></i>
                </div>
                <div>
                    <h3 class="text-base font-bold text-slate-100">
                        API Prismabot (Notificações WhatsApp & NPS)
                    </h3>
                    <p class="text-xs text-slate-400">Integração externa para envio de pesquisas NPS e notificações de chamadas</p>
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
                                <h4 class="text-sm font-bold text-cyan-200">Custom Destinations do PABX Asterisk (Sincronização Ativa)</h4>
                                <p class="text-[11px] text-slate-400">Estes 4 Custom Destinations são gravados na tabela <code class="text-cyan-300">asterisk.custom_destinations</code> do PABX Asterisk com os modelos de mensagem em tempo real no campo Notes!</p>
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

                        <!-- Custom Destination 2: Pesquisa NPS -->
                        <div class="bg-slate-950/70 border border-slate-800 rounded-xl p-4 space-y-2">
                            <div class="flex items-center justify-between border-b border-slate-800 pb-2">
                                <span class="font-bold text-slate-200 text-xs flex items-center gap-2">
                                    <span class="px-2 py-0.5 bg-indigo-500/10 text-indigo-400 border border-indigo-500/20 rounded font-mono text-[10px]">ext-prisma-nps,s,1</span>
                                    Pesquisa de Satisfação NPS
                                </span>
                            </div>
                            <div class="text-[11px] text-slate-300 bg-slate-900/90 p-2.5 rounded-lg border border-slate-800/80 font-mono italic">
                                "<?php echo htmlspecialchars($currentMsgNps); ?>"
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

            <!-- WEBHOOK DE RESPOSTAS NPS (Z-PRO -> PABX) -->
            <?php
                $wh_token = getSetting('webhook_token');
                if (empty($wh_token)) { $wh_token = bin2hex(random_bytes(16)); saveSetting('webhook_token', $wh_token); }
                $wh_scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
                $wh_dir    = rtrim(dirname($_SERVER['SCRIPT_NAME'] ?? '/front_api/index.php'), '/');
                $wh_url    = $wh_scheme . '://' . ($_SERVER['HTTP_HOST'] ?? 'IP_DO_PABX') . $wh_dir . '/index.php?api_action=whatsapp_webhook&token=' . $wh_token;
            ?>
            <div class="pt-4 border-t border-slate-800/80">
                <div class="bg-slate-950/80 p-4 rounded-2xl border border-amber-500/30 space-y-2">
                    <div class="flex items-center gap-2 border-b border-slate-800 pb-2">
                        <i class="fa-solid fa-star text-amber-400 text-base"></i>
                        <strong class="text-white text-xs">Webhook de respostas da Pesquisa NPS</strong>
                    </div>
                    <p class="text-[11px] text-slate-400">Cadastre esta URL como webhook de mensagens recebidas no Z-PRO. Respostas de 1 a 5 a uma pesquisa NPS enviada nas últimas 48h são gravadas no painel.</p>
                    <input type="text" readonly value="<?php echo htmlspecialchars($wh_url); ?>" onclick="this.select()" class="w-full bg-slate-900 border border-slate-700 rounded-lg px-3 py-2 text-[11px] font-mono text-amber-200">
                </div>
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
                            <input type="text" name="test_wa_msg" value="Olá! Teste de disparo instantâneo IPbx Prisma x Z-PRO / Prismabot WhatsApp API." required class="w-full px-3 py-2 bg-slate-900 border border-slate-700 rounded-xl text-white font-mono">
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
                        Banco de Dados MySQL CDR (`asteriskcdrdb`)
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

    ['pbxapi', 'prismabot', 'mysql', 'reset'].forEach(function(t) {
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
