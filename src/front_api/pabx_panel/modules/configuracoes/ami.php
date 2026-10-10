<?php
/**
 * IPbx Prisma - Módulo Configuração Asterisk AMI (ISOLADO NA TELA DE CONFIGURAÇÕES)
 */

$msg = '';
$test_ami_result = null;
$test_db_result = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['action_save_ami'])) {
        $ami_host = trim($_POST['ami_host']);
        $ami_port = trim($_POST['ami_port']);
        $ami_user = trim($_POST['ami_user']);
        $ami_secret = trim($_POST['ami_secret']);

        $db_host = trim($_POST['asterisk_db_host']);
        $db_port = trim($_POST['asterisk_db_port']);
        $db_user = trim($_POST['asterisk_db_user']);
        $db_pass = trim($_POST['asterisk_db_pass']);
        $audio_base_url = trim($_POST['audio_base_url']);

        $stmt = $db->prepare("INSERT OR REPLACE INTO settings (key_name, value_val) VALUES 
            ('ami_host', :host), ('ami_port', :port), ('ami_user', :user), ('ami_secret', :secret),
            ('asterisk_db_host', :db_host), ('asterisk_db_port', :db_port), ('asterisk_db_user', :db_user), ('asterisk_db_pass', :db_pass),
            ('audio_base_url', :audio_url)");
        
        $stmt->execute([
            ':host' => $ami_host, ':port' => $ami_port, ':user' => $ami_user, ':secret' => $ami_secret,
            ':db_host' => $db_host, ':db_port' => $db_port, ':db_user' => $db_user, ':db_pass' => $db_pass,
            ':audio_url' => $audio_base_url
        ]);
        $msg = "Configurações do Asterisk AMI, Banco MySQL e URL de Gravações salvas com sucesso!";
    } elseif (isset($_POST['action_test_ami'])) {
        $test_ami_result = executeClickToCall('1000', '1001');
    } elseif (isset($_POST['action_test_db'])) {
        $test_pdo = getAsteriskPdoConnection('asteriskcdrdb');
        if ($test_pdo) {
            try {
                $stmt = $test_pdo->query("SELECT COUNT(*) FROM cdr");
                $count = $stmt ? $stmt->fetchColumn() : 0;
                $test_db_result = ['success' => true, 'message' => "Conexão com o banco de dados MySQL 'asteriskcdrdb' realizada com sucesso! Total de $count chamadas encontradas no CDR."];
            } catch (Exception $ex_db) {
                $test_db_result = ['success' => true, 'message' => "Conexão com o servidor MySQL estabelecida com sucesso!"];
            }
        } else {
            $test_db_result = ['success' => false, 'error' => "Não foi possível conectar ao banco MySQL ($db_host:$db_port). Verifique o IP, porta, usuário e senha."];
        }
    }
}

$ami_host = getSetting('ami_host') ?: '127.0.0.1';
$ami_port = getSetting('ami_port') ?: '5038';

// Auto-detectar credenciais AMI locais dos arquivos do sistema se não salvas customizadamente
$detected_ami = getAsteriskAMICredentials();
$default_ami_user = !empty($detected_ami[0]['user']) ? $detected_ami[0]['user'] : 'admin';
$default_ami_secret = !empty($detected_ami[0]['secret']) ? $detected_ami[0]['secret'] : 'elaStix.aSterisk.pass';

$ami_user = getSetting('ami_user') ?: $default_ami_user;
$ami_secret = getSetting('ami_secret') ?: $default_ami_secret;

// Auto-detectar senha do MySQL Asterisk local do amportal.conf/issabel.conf
$sys_conf = getIssabelSystemConfig();
$db_host = getSetting('asterisk_db_host') ?: 'localhost';
$db_port = getSetting('asterisk_db_port') ?: '3306';
$db_user = getSetting('asterisk_db_user') ?: ($sys_conf['ampdbuser'] ?: 'root');
$db_pass = getSetting('asterisk_db_pass') ?: ($sys_conf['ampdbpass'] ?: $sys_conf['mysqlrootpwd']);
$audio_base_url = getSetting('audio_base_url') ?: '';
?>

<div class="space-y-6">
    <?php if (!empty($msg)): ?>
        <div class="p-4 rounded-xl bg-emerald-500/10 text-emerald-400 border border-emerald-500/20 text-xs font-bold flex items-center gap-2">
            <i class="fa-solid fa-circle-check"></i>
            <span><?php echo htmlspecialchars($msg); ?></span>
        </div>
    <?php endif; ?>

    <div class="bg-slate-900/90 border border-slate-800 rounded-xl p-5 shadow-xl space-y-4 max-w-2xl">
        <div class="border-b border-slate-800 pb-3">
            <h3 class="text-sm font-bold text-white flex items-center gap-2">
                <i class="fa-solid fa-plug text-cyan-400"></i> Conexão Asterisk AMI (Click-to-Call & FOP)
            </h3>
            <span class="text-xs text-slate-400">Configuração das credenciais do Manager Asterisk para execução de chamadas e escuta espiã</span>
        </div>

        <?php if ($test_ami_result !== null): ?>
            <div class="p-4 rounded-xl text-xs font-bold <?php echo $test_ami_result['success'] ? 'bg-emerald-500/10 text-emerald-400 border border-emerald-500/20' : 'bg-rose-500/10 text-rose-400 border border-rose-500/20'; ?>">
                <div class="flex items-center gap-2">
                    <i class="fa-solid <?php echo $test_ami_result['success'] ? 'fa-circle-check' : 'fa-circle-exclamation'; ?>"></i>
                    <span><?php echo $test_ami_result['success'] ? 'Conexão AMI estabelecida com sucesso com o Asterisk!' : 'Falha na conexão AMI: ' . htmlspecialchars($test_ami_result['error']); ?></span>
                </div>
            </div>
        <?php endif; ?>

        <form method="POST" action="" class="space-y-4 text-xs">
            <input type="hidden" name="action_save_ami" value="1">

            <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                <div>
                    <label class="block font-bold text-slate-300 mb-1">Host AMI Asterisk</label>
                    <input type="text" name="ami_host" value="<?php echo htmlspecialchars($ami_host); ?>" required
                           class="w-full px-3 py-2 bg-slate-950 border border-slate-800 rounded-lg text-white font-mono focus:border-cyan-500 focus:outline-none">
                </div>

                <div>
                    <label class="block font-bold text-slate-300 mb-1">Porta AMI Asterisk</label>
                    <input type="text" name="ami_port" value="<?php echo htmlspecialchars($ami_port); ?>" required
                           class="w-full px-3 py-2 bg-slate-950 border border-slate-800 rounded-lg text-white font-mono focus:border-cyan-500 focus:outline-none">
                </div>
            </div>

            <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                <div>
                    <label class="block font-bold text-slate-300 mb-1">Usuário Manager (AMI User)</label>
                    <input type="text" name="ami_user" value="<?php echo htmlspecialchars($ami_user); ?>" required
                           class="w-full px-3 py-2 bg-slate-950 border border-slate-800 rounded-lg text-white font-mono focus:border-cyan-500 focus:outline-none">
                </div>

                <div>
                    <label class="block font-bold text-slate-300 mb-1">Senha Manager (AMI Secret)</label>
                    <input type="password" name="ami_secret" value="<?php echo htmlspecialchars($ami_secret); ?>" required
                           class="w-full px-3 py-2 bg-slate-950 border border-slate-800 rounded-lg text-white font-mono focus:border-cyan-500 focus:outline-none">
                </div>
            </div>

            <!-- Seção de Banco de Dados MySQL (CDR & Histórico do PABX) -->
            <div class="border-t border-slate-800 pt-4 space-y-3">
                <div class="flex items-center gap-2">
                    <i class="fa-solid fa-database text-purple-400"></i>
                    <h4 class="font-extrabold text-white text-xs">Conexão Banco de Dados MySQL (asteriskcdrdb)</h4>
                </div>
                <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                    <div>
                        <label class="block font-bold text-slate-300 mb-1">Host MySQL PABX / IP Remoto</label>
                        <input type="text" name="asterisk_db_host" value="<?php echo htmlspecialchars($db_host); ?>" required placeholder="localhost ou IP do PABX"
                               class="w-full px-3 py-2 bg-slate-950 border border-slate-800 rounded-lg text-white font-mono focus:border-purple-500 focus:outline-none">
                    </div>
                    <div>
                        <label class="block font-bold text-slate-300 mb-1">Porta MySQL</label>
                        <input type="text" name="asterisk_db_port" value="<?php echo htmlspecialchars($db_port); ?>" required placeholder="3306"
                               class="w-full px-3 py-2 bg-slate-950 border border-slate-800 rounded-lg text-white font-mono focus:border-purple-500 focus:outline-none">
                    </div>
                </div>
                <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                    <div>
                        <label class="block font-bold text-slate-300 mb-1">Usuário MySQL DB</label>
                        <input type="text" name="asterisk_db_user" value="<?php echo htmlspecialchars($db_user); ?>" required placeholder="root ou asteriskuser"
                               class="w-full px-3 py-2 bg-slate-950 border border-slate-800 rounded-lg text-white font-mono focus:border-purple-500 focus:outline-none">
                    </div>
                    <div>
                        <label class="block font-bold text-slate-300 mb-1">Senha MySQL DB</label>
                        <input type="password" name="asterisk_db_pass" value="<?php echo htmlspecialchars($db_pass); ?>" placeholder="Senha do MySQL"
                               class="w-full px-3 py-2 bg-slate-950 border border-slate-800 rounded-lg text-white font-mono focus:border-purple-500 focus:outline-none">
                    </div>
                </div>
            </div>

            <!-- Seção de Áudios / Gravações Remotas -->
            <div class="border-t border-slate-800 pt-4 space-y-3">
                <div class="flex items-center gap-2">
                    <i class="fa-solid fa-volume-high text-emerald-400"></i>
                    <h4 class="font-extrabold text-white text-xs">URL Base do Servidor de Áudio / Gravações (Opcional)</h4>
                </div>
                <div>
                    <label class="block font-bold text-slate-300 mb-1">URL / Host das Gravações PABX Remoto</label>
                    <input type="text" name="audio_base_url" value="<?php echo htmlspecialchars($audio_base_url); ?>" placeholder="Ex: http://192.168.1.100 ou https://pabx.suaempresa.com.br"
                           class="w-full px-3 py-2 bg-slate-950 border border-slate-800 rounded-lg text-white font-mono focus:border-emerald-500 focus:outline-none">
                    <span class="text-[10px] text-slate-400 mt-1 block">Deixe em branco para PABX rodando no mesmo servidor local.</span>
                </div>
            </div>

            <div class="flex items-center gap-3 pt-2">
                <button type="submit" class="px-5 py-2.5 bg-brand-600 hover:bg-brand-500 text-white font-bold rounded-lg transition shadow-lg shadow-brand-500/20">
                    Salvar Conexões AMI, MySQL & Áudio
                </button>
            </div>
        </form>

        <?php if ($test_db_result !== null): ?>
            <div class="p-4 rounded-xl text-xs font-bold <?php echo $test_db_result['success'] ? 'bg-emerald-500/10 text-emerald-400 border border-emerald-500/20' : 'bg-rose-500/10 text-rose-400 border border-rose-500/20'; ?>">
                <div class="flex items-center gap-2">
                    <i class="fa-solid <?php echo $test_db_result['success'] ? 'fa-circle-check' : 'fa-circle-exclamation'; ?>"></i>
                    <span><?php echo $test_db_result['success'] ? htmlspecialchars($test_db_result['message']) : htmlspecialchars($test_db_result['error']); ?></span>
                </div>
            </div>
        <?php endif; ?>

        <div class="pt-2 border-t border-slate-800 flex items-center gap-3 flex-wrap">
            <form method="POST" action="">
                <input type="hidden" name="action_test_ami" value="1">
                <button type="submit" class="px-4 py-2 bg-slate-800 hover:bg-slate-700 text-slate-200 font-bold rounded-lg transition text-xs flex items-center gap-2 border border-slate-700">
                    <i class="fa-solid fa-plug text-cyan-400"></i> Testar Conexão AMI
                </button>
            </form>

            <form method="POST" action="">
                <input type="hidden" name="action_test_db" value="1">
                <button type="submit" class="px-4 py-2 bg-purple-600/20 hover:bg-purple-600 text-purple-300 hover:text-white font-bold rounded-lg transition text-xs flex items-center gap-2 border border-purple-500/40 shadow">
                    <i class="fa-solid fa-database text-purple-400"></i> Testar Conexão Banco MySQL (CDR)
                </button>
            </form>
        </div>
    </div>

    <!-- Guia de Instruções de Ativação do AMI no PABX (White-Label) -->
    <div class="bg-slate-900/90 border border-slate-800 rounded-xl p-5 shadow-xl space-y-3 max-w-2xl text-xs text-slate-300">
        <div class="flex items-center gap-2 font-bold text-white text-sm border-b border-slate-800 pb-2">
            <i class="fa-solid fa-circle-info text-cyan-400"></i>
            <span>Instruções para Ativação do AMI no Servidor PABX</span>
        </div>
        <p class="text-slate-400">
            O <strong>AMI (Asterisk Manager Interface)</strong> permite a execução de chamadas (Click-to-Call), escuta espiã (Spy) e desconexão de canais em tempo real.
        </p>
        <div class="space-y-2 bg-slate-950 p-4 rounded-xl border border-slate-800/80">
            <h4 class="font-bold text-cyan-300 flex items-center gap-1.5">
                <i class="fa-solid fa-gear"></i> Passos para Ativação na Interface de Administração do PABX:
            </h4>
            <ol class="list-decimal list-inside space-y-2 text-slate-300 pl-1">
                <li>Acesse a interface web de administração do seu <strong>Servidor PABX</strong>.</li>
                <li>Navegue até o menu <strong>PBX &gt; PBX Configuration &gt; Asterisk Manager Settings</strong>.</li>
                <li>Certifique-se de que a opção <strong>Web Enabled</strong> está definida como <strong>Yes</strong>.</li>
                <li>Verifique ou crie o usuário Manager (ex: <code class="bg-slate-900 px-1.5 py-0.5 rounded text-cyan-300 font-mono">admin</code>) e defina a senha do Manager.</li>
                <li>Garanta que as permissões de leitura e escrita (<code class="bg-slate-900 px-1 py-0.5 rounded text-slate-400">Read/Write</code>) incluam: <code class="bg-slate-900 px-1 py-0.5 rounded text-emerald-400 font-mono">system, call, log, verbose, command, agent, user, config, originate</code>.</li>
                <li>Insira o mesmo Usuário e Senha no formulário acima e clique em <strong>Salvar Credenciais AMI</strong>.</li>
            </ol>
        </div>
        <div class="p-3 bg-amber-500/10 border border-amber-500/20 rounded-xl text-amber-300 text-[11px] flex items-start gap-2">
            <i class="fa-solid fa-lightbulb text-amber-400 text-sm mt-0.5 shrink-0"></i>
            <span><strong>Dica de Conexão Local:</strong> Como este painel roda no mesmo servidor do PABX, mantenha o Host como <code class="font-mono text-white">127.0.0.1</code> e a Porta como <code class="font-mono text-white">5038</code>.</span>
        </div>
    </div>
</div>
