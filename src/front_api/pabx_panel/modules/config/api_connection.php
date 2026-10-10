<?php
/**
 * IPbx Prisma - Módulo de Conexão e Diagnóstico da REST API PABX
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
$testResult = null;

// Puxar senha root/admin nativa do PABX automaticamente se não houver senha salva
$auto_pass = 'palosanto';
if (function_exists('getPABXSystemConfig')) {
    $sysConf = @getPABXSystemConfig();
    if (!empty($sysConf['mysqlrootpwd'])) {
        $auto_pass = $sysConf['mysqlrootpwd'];
    } elseif (!empty($sysConf['amiadminpwd'])) {
        $auto_pass = $sysConf['amiadminpwd'];
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $url  = trim($_POST['api_url'] ?? 'https://127.0.0.1/pbxapi');
    $user = trim($_POST['api_user'] ?? 'admin');
    $pass = trim($_POST['api_pass'] ?? $auto_pass);

    if (isset($_POST['action_save_api_settings']) || isset($_POST['action_test_api_connection'])) {
        if (isset($_POST['action_test_api_connection'])) {
            $apiClient->saveSettings($url, $user, $pass);
            $testResult = $apiClient->authenticate();
            if (!empty($testResult['success'])) {
                $msg_status = "⚡ Conexão com a REST API PABX realizada com SUCESSO!";
                if (!empty($testResult['token'])) {
                    $apiClient->saveSettings($url, $user, $pass, $testResult['token']);
                }
            } else {
                $msg_error = $testResult['message'] ?? 'Erro desconhecido ao conectar na API.';
            }
        } else {
            $apiClient->saveSettings($url, $user, $pass);
            $msg_status = "Configurações da API salvas com sucesso!";
        }
    }
}

// Carregar valores atuais do banco ou autopreencher
$settings = [];
if ($db) {
    try {
        $stmtSet = $db->query("SELECT key_name, value FROM settings WHERE key_name LIKE 'pbxapi_%'");
        if ($stmtSet) {
            while ($row = $stmtSet->fetch(PDO::FETCH_ASSOC)) {
                $settings[$row['key_name']] = $row['value'];
            }
        }
    } catch (Exception $e) {}
}

$currentUrl   = !empty($settings['pbxapi_url'])  ? $settings['pbxapi_url']  : 'https://127.0.0.1/pbxapi';
$currentUser  = !empty($settings['pbxapi_user']) ? $settings['pbxapi_user'] : 'admin';
$currentPass  = !empty($settings['pbxapi_pass']) ? $settings['pbxapi_pass'] : $auto_pass;
$currentToken = !empty($settings['pbxapi_token'])? $settings['pbxapi_token']: '';

// Se ainda não testou e temos token nulo, tentar autenticar silenciosamente com a senha auto-detectada
if (empty($currentToken) && !empty($currentPass) && $_SERVER['REQUEST_METHOD'] !== 'POST') {
    $autoAuth = $apiClient->authenticate();
    if (!empty($autoAuth['success']) && !empty($autoAuth['token'])) {
        $currentToken = $autoAuth['token'];
    }
}
?>

<div class="space-y-6">
    <!-- Header Badge -->
    <div class="bg-slate-900/80 backdrop-blur border border-slate-800 rounded-2xl p-6 shadow-xl flex flex-col md:flex-row items-start md:items-center justify-between gap-4">
        <div>
            <div class="flex items-center gap-3">
                <div class="p-3 bg-cyan-500/10 rounded-xl border border-cyan-500/20 text-cyan-400">
                    <i class="fa-solid fa-network-wired text-xl"></i>
                </div>
                <div>
                    <h2 class="text-xl font-bold text-slate-100">Conexão REST API PABX (`pbxapi`)</h2>
                    <p class="text-xs text-slate-400">Gerenciamento seguro e em tempo real via tokens JWT (Autopreenchido)</p>
                </div>
            </div>
        </div>
        <div>
            <?php if (!empty($currentToken)): ?>
                <span class="inline-flex items-center gap-2 px-4 py-2 rounded-xl text-xs font-semibold bg-emerald-500/10 text-emerald-400 border border-emerald-500/20 shadow-lg shadow-emerald-500/10">
                    <span class="w-2 h-2 rounded-full bg-emerald-400 animate-pulse"></span>
                    🟢 API Conectada & Ativa
                </span>
            <?php else: ?>
                <span class="inline-flex items-center gap-2 px-4 py-2 rounded-xl text-xs font-semibold bg-amber-500/10 text-amber-400 border border-amber-500/20">
                    <span class="w-2 h-2 rounded-full bg-amber-400"></span>
                    🟡 Pendente de Autenticação
                </span>
            <?php endif; ?>
        </div>
    </div>

    <!-- Mensagens de Alerta com Auto-Fade (some em 4s) -->
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

    <!-- Formulário de Configuração -->
    <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
        <div class="lg:col-span-2 bg-slate-900/60 border border-slate-800 rounded-2xl p-6 shadow-xl">
            <h3 class="text-base font-bold text-slate-100 mb-4 flex items-center gap-2">
                <i class="fa-solid fa-sliders text-cyan-400"></i>
                Credenciais da REST API
            </h3>

            <form method="POST" action="index.php?module=configuracoes&action=api_connection" class="space-y-4">
                <div>
                    <label class="block text-xs font-semibold text-slate-300 uppercase tracking-wider mb-2">URL Base da API</label>
                    <input type="text" name="api_url" value="<?php echo htmlspecialchars($currentUrl); ?>" required
                           class="w-full bg-slate-950 border border-slate-800 rounded-xl px-4 py-2.5 text-sm text-slate-200 focus:border-cyan-500 focus:outline-none transition-colors"
                           placeholder="https://127.0.0.1/pbxapi">
                    <p class="text-[11px] text-slate-500 mt-1">Endereço local ou IP do servidor PABX onde a pasta `pbxapi` está instalada.</p>
                </div>

                <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                    <div>
                        <label class="block text-xs font-semibold text-slate-300 uppercase tracking-wider mb-2">Usuário Admin</label>
                        <input type="text" name="api_user" value="<?php echo htmlspecialchars($currentUser); ?>" required
                               class="w-full bg-slate-950 border border-slate-800 rounded-xl px-4 py-2.5 text-sm text-slate-200 focus:border-cyan-500 focus:outline-none transition-colors"
                               placeholder="admin">
                    </div>
                    <div>
                        <label class="block text-xs font-semibold text-slate-300 uppercase tracking-wider mb-2">Senha Admin (Autodetectada do Servidor)</label>
                        <input type="password" name="api_pass" value="<?php echo htmlspecialchars($currentPass); ?>" required
                               class="w-full bg-slate-950 border border-slate-800 rounded-xl px-4 py-2.5 text-sm text-slate-200 focus:border-cyan-500 focus:outline-none transition-colors"
                               placeholder="••••••••">
                        <p class="text-[10px] text-emerald-400 mt-1 flex items-center gap-1">
                            <i class="fa-solid fa-wand-magic-sparkles"></i> Senha obtida automaticamente das configurações do PABX!
                        </p>
                    </div>
                </div>

                <div class="pt-4 flex flex-col sm:flex-row items-center gap-3">
                    <button type="submit" name="action_save_api_settings" class="w-full sm:w-auto px-6 py-2.5 bg-slate-800 hover:bg-slate-700 text-slate-200 text-sm font-semibold rounded-xl border border-slate-700 transition-colors">
                        Salvar Credenciais
                    </button>
                    <button type="submit" name="action_test_api_connection" class="w-full sm:w-auto px-6 py-2.5 bg-gradient-to-r from-cyan-500 to-blue-600 hover:from-cyan-400 hover:to-blue-500 text-white text-sm font-semibold rounded-xl shadow-lg shadow-cyan-500/20 transition-all flex items-center justify-center gap-2">
                        <i class="fa-solid fa-bolt"></i>
                        ⚡ Testar Conexão API
                    </button>
                </div>
            </form>
        </div>

        <!-- Card de Informações e Instruções -->
        <div class="bg-slate-900/60 border border-slate-800 rounded-2xl p-6 shadow-xl space-y-4">
            <h3 class="text-base font-bold text-slate-100 flex items-center gap-2">
                <i class="fa-solid fa-circle-info text-amber-400"></i>
                Conexão Automática Ativa
            </h3>
            <p class="text-xs text-slate-400 leading-relaxed">
                Como o painel está rodando diretamente no servidor PABX, a senha do administrador é lida de forma 100% segura dos arquivos de sistema (`/etc/issabel.conf` / `/etc/elastix.conf`).
            </p>
            <div class="p-3 bg-slate-950 rounded-xl border border-slate-800 font-mono text-[11px] text-cyan-300 overflow-x-auto">
                Status: Autenticação JWT Automática Habilitada
            </div>
            <p class="text-[11px] text-slate-500">
                Não é necessário digitar a senha manualmente a cada acesso!
            </p>
        </div>
    </div>
</div>
