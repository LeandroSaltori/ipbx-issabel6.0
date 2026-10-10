<?php
/**
 * IPbx Prisma - Módulo de Configuração SMTP & Agendamento de Disparos Recorrentes v2.1
 * Suporte a criptografia forte de senhas (AES-256 / LGPD), proteção da senha padrão Prisma Telecom,
 * caixa de seleção de provedores e agendamento de relatórios via E-mail e WhatsApp.
 */

$jsonSmtpFile     = __DIR__ . '/../../config/smtp_settings.json';
$jsonScheduleFile = __DIR__ . '/../../config/scheduled_reports.json';
$jsonLogsFile     = __DIR__ . '/../../config/smtp_logs.json';

// Criar diretório config se não existir
if (!is_dir(__DIR__ . '/../../config')) {
    @mkdir(__DIR__ . '/../../config', 0755, true);
}

// Configurações Padrão de Fábrica (Prisma Telecom)
$defaultSmtpConfig = [
    'host'       => 'mail.prismatelecom.com',
    'port'       => '465',
    'user'       => 'suporte@prismatelecom.com',
    'password'   => encryptSmtpPassword('ls251289@'),
    'security'   => 'ssl', // ssl, tls, none
    'from_email' => 'suporte@prismatelecom.com',
    'from_name'  => 'IPbx Prisma - Suporte',
    'is_active'  => true
];

$smtpConfig = $defaultSmtpConfig;

if (file_exists($jsonSmtpFile)) {
    $rawSmtp = @file_get_contents($jsonSmtpFile);
    if ($rawSmtp) {
        $decoded = @json_decode($rawSmtp, true);
        if (is_array($decoded) && !empty($decoded)) {
            $smtpConfig = array_merge($smtpConfig, $decoded);
        }
    }
} else {
    // Se o arquivo ainda não existir, salvar o padrão Prisma Telecom com senha criptografada
    @file_put_contents($jsonSmtpFile, json_encode($smtpConfig, JSON_PRETTY_PRINT));
}

// Garantir que a senha esteja no formato criptografado se porventura estiver em texto puro antigo
if (!empty($smtpConfig['password']) && strpos($smtpConfig['password'], 'enc:') !== 0) {
    $smtpConfig['password'] = encryptSmtpPassword($smtpConfig['password']);
    @file_put_contents($jsonSmtpFile, json_encode($smtpConfig, JSON_PRETTY_PRINT));
}

// Identificar provedor atual
$currentHost = $smtpConfig['host'] ?? '';
$initialProvider = 'custom';
if (strpos($currentHost, 'prismatelecom.com') !== false || ($smtpConfig['user'] ?? '') === 'suporte@prismatelecom.com') {
    $initialProvider = 'prisma';
} elseif (strpos($currentHost, 'gmail.com') !== false) {
    $initialProvider = 'gmail';
} elseif (strpos($currentHost, 'office365.com') !== false || strpos($currentHost, 'outlook.com') !== false) {
    $initialProvider = 'outlook';
}

$isPrismaProvider = ($initialProvider === 'prisma');

// Carregar Agendamentos salvos
$schedules = [];
if (file_exists($jsonScheduleFile)) {
    $rawSched = @file_get_contents($jsonScheduleFile);
    if ($rawSched) {
        $decodedSched = @json_decode($rawSched, true);
        if (is_array($decodedSched)) {
            $schedules = $decodedSched;
        }
    }
}

// Carregar Logs de Envio
$dispatchLogs = [];
if (file_exists($jsonLogsFile)) {
    $rawLogs = @file_get_contents($jsonLogsFile);
    if ($rawLogs) {
        $decodedLogs = @json_decode($rawLogs, true);
        if (is_array($decodedLogs)) {
            $dispatchLogs = $decodedLogs;
        }
    }
}

function addDispatchLogRecord($from, $to, $report, $channel, $status, $jsonLogsFile, &$dispatchLogs) {
    array_unshift($dispatchLogs, [
        'id'        => uniqid('log_'),
        'timestamp' => date('Y-m-d H:i:s'),
        'from'      => $from,
        'to'        => $to,
        'report'    => $report,
        'channel'   => $channel,
        'status'    => $status
    ]);
    $dispatchLogs = array_slice($dispatchLogs, 0, 50); // Manter os últimos 50 logs
    @file_put_contents($jsonLogsFile, json_encode($dispatchLogs, JSON_PRETTY_PRINT));
}

$msgSuccess = '';
$msgError   = '';

// 1. Processar Salvamento de Configuração SMTP (com Criptografia AES-256)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action_type']) && $_POST['action_type'] === 'save_smtp') {
    $smtpConfig['host']       = trim($_POST['host'] ?? 'mail.prismatelecom.com');
    $smtpConfig['port']       = trim($_POST['port'] ?? '465');
    $smtpConfig['user']       = trim($_POST['user'] ?? 'suporte@prismatelecom.com');
    $smtpConfig['security']   = trim($_POST['security'] ?? 'ssl');
    $smtpConfig['from_email'] = trim($_POST['from_email'] ?? 'suporte@prismatelecom.com');
    $smtpConfig['from_name']  = trim($_POST['from_name'] ?? 'IPbx Prisma - Suporte');
    $smtpConfig['is_active']  = isset($_POST['is_active']);

    $inputPass = trim($_POST['password'] ?? '');
    if (!empty($inputPass)) {
        // Se informou nova senha, criptografa antes de gravar
        $smtpConfig['password'] = encryptSmtpPassword($inputPass);
    } elseif ($smtpConfig['host'] === 'mail.prismatelecom.com' && empty($smtpConfig['password'])) {
        // Mantém a senha padrão Prisma protegida se não for alterada
        $smtpConfig['password'] = encryptSmtpPassword('ls251289@');
    }

    @file_put_contents($jsonSmtpFile, json_encode($smtpConfig, JSON_PRETTY_PRINT));
    
    // Atualizar estado de provedor pós-salvamento
    if (strpos($smtpConfig['host'], 'prismatelecom.com') !== false || $smtpConfig['user'] === 'suporte@prismatelecom.com') {
        $initialProvider = 'prisma';
    } elseif (strpos($smtpConfig['host'], 'gmail.com') !== false) {
        $initialProvider = 'gmail';
    } elseif (strpos($smtpConfig['host'], 'office365.com') !== false || strpos($smtpConfig['host'], 'outlook.com') !== false) {
        $initialProvider = 'outlook';
    } else {
        $initialProvider = 'custom';
    }
    $isPrismaProvider = ($initialProvider === 'prisma');

    $msgSuccess = "Configurações do servidor SMTP salvas com sucesso (Senha protegida por Criptografia AES-256 / LGPD)!";
    if (function_exists('pabx_log')) pabx_log('smtp', 'INFO', "Configurações do servidor SMTP salvas pelo operador", ['host' => $smtpConfig['host'], 'port' => $smtpConfig['port'], 'user' => $smtpConfig['user']]);
}

// 1b. Processar Restauração para o Padrão Oficial Prisma Telecom
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action_type']) && $_POST['action_type'] === 'reset_smtp') {
    $smtpConfig = $defaultSmtpConfig;
    @file_put_contents($jsonSmtpFile, json_encode($smtpConfig, JSON_PRETTY_PRINT));
    $initialProvider = 'prisma';
    $isPrismaProvider = true;
    $msgSuccess = "Servidor SMTP restaurado com Sucesso para a conta padrão oficial Prisma Telecom!";
    if (function_exists('pabx_log')) pabx_log('smtp', 'INFO', 'Restauradas configurações SMTP padrão da Prisma Telecom.');
}

// 2. Processar Teste de Envio SMTP Real
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action_type']) && $_POST['action_type'] === 'test_smtp') {
    $testTo = trim($_POST['test_email'] ?? '');
    if (empty($testTo)) {
        $msgError = "Informe um e-mail de destino para realizar o teste.";
        if (function_exists('pabx_log')) pabx_log('smtp', 'WARNING', 'Tentativa de teste de e-mail cancelada: Destinatário não informado');
    } else {
        $subject = "🧪 Teste de Conexão SMTP - IPbx Prisma";
        $body = "Olá!\n\nEste é um e-mail de teste enviado pelo IPbx Prisma para confirmar que a conexão SMTP está funcionando perfeitamente.\n\nServidor: {$smtpConfig['host']}:{$smtpConfig['port']}\nUsuário: {$smtpConfig['user']}\nCriptografia: " . strtoupper($smtpConfig['security']) . "\nData/Hora: " . date('d/m/Y H:i:s');

        $res = sendSmtpEmailNative($smtpConfig, $testTo, $subject, $body);

        if ($res['success']) {
            $msgSuccess = "E-mail de teste entregue com SUCESSO via SMTP para {$testTo}!";
            addDispatchLogRecord($smtpConfig['from_email'] ?: $smtpConfig['user'], $testTo, 'Teste de Conexão SMTP', 'E-mail', 'Sucesso', $jsonLogsFile, $dispatchLogs);
            if (function_exists('pabx_log')) pabx_log('smtp', 'INFO', "Teste de conexão SMTP bem-sucedido para {$testTo}", ['host' => $smtpConfig['host'], 'user' => $smtpConfig['user']]);
        } else {
            $msgError = "FALHA no envio do e-mail: " . $res['error'];
            addDispatchLogRecord($smtpConfig['from_email'] ?: $smtpConfig['user'], $testTo, 'Teste de Conexão SMTP', 'E-mail', 'Falha: ' . substr($res['error'], 0, 40), $jsonLogsFile, $dispatchLogs);
            if (function_exists('pabx_log')) pabx_log('smtp', 'ERROR', "FALHA no envio de e-mail de teste SMTP para {$testTo}: " . $res['error'], ['host' => $smtpConfig['host'], 'user' => $smtpConfig['user'], 'port' => $smtpConfig['port']]);
        }
    }
}

// 3. Processar Novo Agendamento de Relatório
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action_type']) && $_POST['action_type'] === 'add_schedule') {
    $newSched = [
        'id'          => uniqid('sch_'),
        'title'       => trim($_POST['title'] ?? 'Relatório de Filas'),
        'report_type' => trim($_POST['report_type'] ?? 'relatorio_filas'),
        'frequency'   => trim($_POST['frequency'] ?? 'diario'),
        'send_time'   => trim($_POST['send_time'] ?? '08:00'),
        'channel'     => trim($_POST['channel'] ?? 'email'),
        'email_to'    => trim($_POST['email_to'] ?? ''),
        'whatsapp_to' => trim($_POST['whatsapp_to'] ?? ''),
        'status'      => 'active',
        'created_at'  => date('Y-m-d H:i:s'),
        'last_sent'   => '-'
    ];

    $schedules[] = $newSched;
    @file_put_contents($jsonScheduleFile, json_encode($schedules, JSON_PRETTY_PRINT));
    $msgSuccess = "Agendamento de relatório cadastrado com sucesso!";
}

// 4. Excluir Agendamento
if (isset($_GET['delete_schedule'])) {
    $idDel = $_GET['delete_schedule'];
    $schedules = array_filter($schedules, function($s) use ($idDel) { return $s['id'] !== $idDel; });
    $schedules = array_values($schedules);
    @file_put_contents($jsonScheduleFile, json_encode($schedules, JSON_PRETTY_PRINT));
    $msgSuccess = "Agendamento removido com sucesso.";
}

// 5. Disparar Teste Imediato de Agendamento (Com Relatório HTML e Anexo CSV)
if (isset($_GET['trigger_now'])) {
    $idTrig = $_GET['trigger_now'];
    $schedFound = null;
    foreach ($schedules as &$s) {
        if ($s['id'] === $idTrig) {
            $s['last_sent'] = date('Y-m-d H:i:s');
            $schedFound = $s;
            break;
        }
    }
    @file_put_contents($jsonScheduleFile, json_encode($schedules, JSON_PRETTY_PRINT));

    if ($schedFound) {
        $dest = $schedFound['email_to'] ?: $schedFound['whatsapp_to'];
        $subject = "📊 [Agendado] " . $schedFound['title'];
        
        // Gerar resumo em HTML e anexo CSV com dados reais da operação
        $payload = buildScheduledReportPayload($schedFound['title'], $schedFound['report_type']);

        $sendRes = sendSmtpEmailNative($smtpConfig, $dest, $subject, $payload['body_html'], $payload['attachments']);

        if ($sendRes['success']) {
            $msgSuccess = "Disparo de teste executado com SUCESSO para '{$dest}' contendo o relatório em HTML e anexo CSV!";
            addDispatchLogRecord($smtpConfig['from_email'] ?: $smtpConfig['user'], $dest, $schedFound['title'], strtoupper($schedFound['channel']), 'Sucesso', $jsonLogsFile, $dispatchLogs);
        } else {
            $msgError = "FALHA ao disparar relatório para '{$dest}': " . $sendRes['error'];
            addDispatchLogRecord($smtpConfig['from_email'] ?: $smtpConfig['user'], $dest, $schedFound['title'], strtoupper($schedFound['channel']), 'Falha', $jsonLogsFile, $dispatchLogs);
        }
    }
}

// Para a conta Prisma padrão do sistema, a senha NUNCA é enviada/exibida no HTML
$displayPassword = $isPrismaProvider ? '' : decryptSmtpPassword($smtpConfig['password'] ?? '');
?>

<div class="space-y-6">
    <!-- Banner de Título -->
    <div class="bg-slate-900/90 border border-slate-800 rounded-3xl p-6 shadow-2xl space-y-4">
        <div class="flex flex-col sm:flex-row items-start sm:items-center justify-between gap-4 border-b border-slate-800 pb-4">
            <div class="flex items-center gap-3.5">
                <div class="w-12 h-12 rounded-2xl bg-amber-500/10 text-amber-400 border border-amber-500/30 flex items-center justify-center font-bold text-xl shadow-lg">
                    <i class="fa-solid fa-envelope-circle-check"></i>
                </div>
                <div>
                    <h3 class="text-base font-black text-white flex items-center gap-2">
                        Servidor SMTP & Disparos Automáticos de Relatórios <span class="px-2 py-0.5 bg-amber-500/20 text-amber-300 rounded text-[10px] font-mono font-bold">LGPD SECURE</span>
                    </h3>
                    <p class="text-xs text-slate-400 mt-0.5">
                        Configure seu servidor de e-mail com segurança avançada e agende envios automáticos de relatórios de telefonia, filas e IA.
                    </p>
                </div>
            </div>
            
            <div class="flex items-center gap-2 bg-slate-950 px-3.5 py-1.5 rounded-xl border border-slate-800 text-[11px] font-mono text-emerald-400 font-bold">
                <i class="fa-solid fa-shield-halved text-emerald-400"></i> Criptografia AES-256 Ativa
            </div>
        </div>

        <?php if ($msgSuccess): ?>
            <div class="p-4 bg-emerald-500/10 border border-emerald-500/30 text-emerald-300 text-xs font-bold rounded-2xl flex items-center gap-2">
                <i class="fa-solid fa-circle-check text-base"></i> <?php echo htmlspecialchars($msgSuccess); ?>
            </div>
        <?php endif; ?>

        <?php if ($msgError): ?>
            <div class="p-4 bg-rose-500/10 border border-rose-500/30 text-rose-300 text-xs font-bold rounded-2xl flex items-center gap-2">
                <i class="fa-solid fa-circle-exclamation text-base"></i> <?php echo htmlspecialchars($msgError); ?>
            </div>
        <?php endif; ?>
    </div>

    <!-- ABAS E FORMULÁRIO SMTP -->
    <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
        <!-- 1. Configurações do Servidor SMTP -->
        <div class="bg-slate-900/90 border border-slate-800 rounded-3xl p-6 shadow-2xl space-y-5">
            <div class="flex items-center justify-between border-b border-slate-800 pb-3 flex-wrap gap-2">
                <h4 class="text-xs font-extrabold text-white uppercase tracking-wider flex items-center gap-2">
                    <i class="fa-solid fa-server text-amber-400"></i> Configurações de Saída SMTP
                </h4>
                <span class="text-[10px] text-slate-400 font-mono">Padrão Sistema: Prisma Telecom</span>
            </div>

            <!-- Caixa de Seleção do Provedor de E-mail -->
            <div class="space-y-1.5 bg-slate-950/80 p-3.5 rounded-2xl border border-slate-800">
                <label class="text-slate-300 font-extrabold block text-[11px] flex items-center justify-between">
                    <span><i class="fa-solid fa-sliders text-amber-400"></i> Selecionar Provedor de E-mail:</span>
                    <span class="text-[10px] text-slate-500 font-normal">Preenchimento automático</span>
                </label>
                <select id="smtp_provider_select" onchange="onSmtpProviderChange(this.value)" class="w-full px-3.5 py-2.5 bg-slate-900 border border-amber-500/40 rounded-xl text-white font-bold text-xs focus:border-amber-500 focus:outline-none shadow">
                    <option value="prisma" <?php echo $initialProvider === 'prisma' ? 'selected' : ''; ?>>🚀 Prisma Telecom (Servidor Padrão - Protegido)</option>
                    <option value="gmail" <?php echo $initialProvider === 'gmail' ? 'selected' : ''; ?>>📧 Gmail (smtp.gmail.com)</option>
                    <option value="outlook" <?php echo $initialProvider === 'outlook' ? 'selected' : ''; ?>>✉️ Outlook / Microsoft 365 (smtp.office365.com)</option>
                    <option value="custom" <?php echo $initialProvider === 'custom' ? 'selected' : ''; ?>>⚙️ Servidor Personalizado (SMTP Próprio)</option>
                </select>
            </div>

            <form method="POST" action="" class="space-y-4 text-xs">
                <input type="hidden" name="action_type" value="save_smtp">

                <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                    <div class="space-y-1">
                        <label class="text-slate-400 font-bold block">Servidor Host (SMTP):</label>
                        <input type="text" name="host" value="<?php echo htmlspecialchars($smtpConfig['host']); ?>" placeholder="mail.prismatelecom.com" required class="w-full px-3 py-2 bg-slate-950 border border-slate-800 rounded-xl text-white font-mono focus:border-amber-500 focus:outline-none">
                    </div>
                    <div class="space-y-1">
                        <label class="text-slate-400 font-bold block">Porta SMTP:</label>
                        <input type="number" name="port" value="<?php echo htmlspecialchars($smtpConfig['port']); ?>" placeholder="465" required class="w-full px-3 py-2 bg-slate-950 border border-slate-800 rounded-xl text-white font-mono focus:border-amber-500 focus:outline-none">
                    </div>
                </div>

                <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                    <div class="space-y-1">
                        <label class="text-slate-400 font-bold block">Usuário (E-mail Autenticação):</label>
                        <input type="email" name="user" value="<?php echo htmlspecialchars($smtpConfig['user']); ?>" placeholder="suporte@prismatelecom.com" required class="w-full px-3 py-2 bg-slate-950 border border-slate-800 rounded-xl text-white font-mono focus:border-amber-500 focus:outline-none">
                    </div>
                    
                    <!-- Senha SMTP com Proteção do Padrão Prisma e Olho de Exibição -->
                    <div class="space-y-1">
                        <label class="text-slate-400 font-bold block flex items-center justify-between">
                            <span>Senha SMTP:</span>
                            <span id="pass_security_badge" class="text-[10px] text-amber-400 font-bold flex items-center gap-1">
                                <?php if ($isPrismaProvider): ?>
                                    <i class="fa-solid fa-shield-halved text-amber-400"></i> Protegida (Prisma Default)
                                <?php else: ?>
                                    <i class="fa-solid fa-lock text-emerald-400"></i> Criptografada AES-256
                                <?php endif; ?>
                            </span>
                        </label>
                        <div class="relative">
                            <input type="password" id="smtp_password_input" name="password" value="<?php echo htmlspecialchars($displayPassword); ?>" placeholder="<?php echo $isPrismaProvider ? '•••••••••••• (Protegida pelo Sistema)' : '••••••••'; ?>" class="w-full px-3 py-2 pr-10 bg-slate-950 border border-slate-800 rounded-xl text-white font-mono focus:border-amber-500 focus:outline-none">
                            <button type="button" onclick="togglePasswordVisibility('smtp_password_input', this)" class="absolute right-3 top-2.5 text-slate-400 hover:text-white transition" title="Exibir / Ocultar Senha">
                                <i class="fa-solid fa-eye text-xs"></i>
                            </button>
                        </div>
                    </div>
                </div>

                <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                    <div class="space-y-1">
                        <label class="text-slate-400 font-bold block">Criptografia / Segurança:</label>
                        <select name="security" class="w-full px-3 py-2 bg-slate-950 border border-slate-800 rounded-xl text-white focus:border-amber-500 focus:outline-none">
                            <option value="ssl" <?php echo $smtpConfig['security'] === 'ssl' ? 'selected' : ''; ?>>SSL / TLS (Porta 465 - Recomendada)</option>
                            <option value="tls" <?php echo $smtpConfig['security'] === 'tls' ? 'selected' : ''; ?>>STARTTLS / TLS (Porta 587)</option>
                            <option value="none" <?php echo $smtpConfig['security'] === 'none' ? 'selected' : ''; ?>>Nenhuma (Porta 25)</option>
                        </select>
                    </div>
                    <div class="space-y-1">
                        <label class="text-slate-400 font-bold block">Nome do Remetente (From Name):</label>
                        <input type="text" name="from_name" value="<?php echo htmlspecialchars($smtpConfig['from_name']); ?>" placeholder="IPbx Prisma - Suporte" required class="w-full px-3 py-2 bg-slate-950 border border-slate-800 rounded-xl text-white focus:border-amber-500 focus:outline-none">
                    </div>
                </div>

                <div class="space-y-1">
                    <label class="text-slate-400 font-bold block">E-mail de Origem (From Email):</label>
                    <input type="email" name="from_email" value="<?php echo htmlspecialchars($smtpConfig['from_email']); ?>" placeholder="suporte@prismatelecom.com" required class="w-full px-3 py-2 bg-slate-950 border border-slate-800 rounded-xl text-white font-mono focus:border-amber-500 focus:outline-none">
                </div>

                <div class="flex items-center justify-between pt-2 border-t border-slate-800/60">
                    <label class="flex items-center gap-2 cursor-pointer">
                        <input type="checkbox" name="is_active" <?php echo $smtpConfig['is_active'] ? 'checked' : ''; ?> class="rounded text-amber-500 focus:ring-0">
                        <span class="text-slate-300 font-bold">Ativar Disparos de E-mail</span>
                    </label>
                    <button type="submit" class="px-5 py-2 bg-amber-600 hover:bg-amber-500 text-white rounded-xl text-xs font-extrabold transition shadow-lg shadow-amber-600/30 flex items-center gap-2">
                        <i class="fa-solid fa-floppy-disk"></i> Salvar Configuração
                    </button>
                </div>
            </form>

            <!-- Teste Rápido de Envio -->
            <div class="pt-4 border-t border-slate-800">
                <form method="POST" action="" class="flex items-center gap-2">
                    <input type="hidden" name="action_type" value="test_smtp">
                    <input type="email" name="test_email" placeholder="Digite seu e-mail para teste..." required class="flex-1 px-3 py-2 bg-slate-950 border border-slate-800 rounded-xl text-white text-xs font-mono focus:border-amber-500 focus:outline-none">
                    <button type="submit" class="px-4 py-2 bg-slate-800 hover:bg-slate-700 text-amber-300 border border-amber-500/30 rounded-xl text-xs font-bold transition flex items-center gap-1.5 shadow">
                        <i class="fa-solid fa-paper-plane"></i> Testar Envio Real
                    </button>
                </form>
            </div>
        </div>

        <!-- 2. Novo Agendamento de Relatórios -->
        <div class="bg-slate-900/90 border border-slate-800 rounded-3xl p-6 shadow-2xl space-y-5">
            <div class="flex items-center justify-between border-b border-slate-800 pb-3">
                <h4 class="text-xs font-extrabold text-white uppercase tracking-wider flex items-center gap-2">
                    <i class="fa-solid fa-calendar-plus text-purple-400"></i> Cadastrar Novo Agendamento de Disparo
                </h4>
                <span class="px-2 py-0.5 rounded text-[10px] font-mono font-bold bg-purple-500/20 text-purple-300 border border-purple-500/30">E-MAIL & WHATSAPP</span>
            </div>

            <form method="POST" action="" class="space-y-4 text-xs">
                <input type="hidden" name="action_type" value="add_schedule">

                <div class="space-y-1">
                    <label class="text-slate-400 font-bold block">Título do Agendamento:</label>
                    <input type="text" name="title" placeholder="Ex: Relatório Diário de Filas para a Diretoria" required class="w-full px-3 py-2 bg-slate-950 border border-slate-800 rounded-xl text-white focus:border-purple-500 focus:outline-none">
                </div>

                <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                    <div class="space-y-1">
                        <label class="text-slate-400 font-bold block">Relatório a Enviar:</label>
                        <select name="report_type" class="w-full px-3 py-2 bg-slate-950 border border-slate-800 rounded-xl text-white focus:border-purple-500 focus:outline-none">
                            <option value="Relatório Completo de Filas">📊 Relatório Completo de Filas</option>
                            <option value="Resumo de Filas PABX">📈 Resumo de Filas PABX</option>
                            <option value="Relatórios Gráficos">📉 Relatórios Gráficos (Ramais/Filas)</option>
                            <option value="Relatório Geral & Heatmap">🔥 Relatório Geral & Heatmap</option>
                            <option value="IA Analytics">✨ Analytics de Inteligência Artificial</option>
                        </select>
                    </div>
                    <div class="space-y-1">
                        <label class="text-slate-400 font-bold block">Frequência de Envio:</label>
                        <select name="frequency" class="w-full px-3 py-2 bg-slate-950 border border-slate-800 rounded-xl text-white focus:border-purple-500 focus:outline-none">
                            <option value="diario">📅 Diário (Todos os dias)</option>
                            <option value="semanal">📆 Semanal (Toda Segunda-feira)</option>
                            <option value="mensal">🗓️ Mensal (Todo dia 1º do mês)</option>
                        </select>
                    </div>
                </div>

<?php $isWaActive = function_exists('isWhatsappApiActive') ? isWhatsappApiActive() : false; ?>

                <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                    <div class="space-y-1">
                        <label class="text-slate-400 font-bold block">Horário do Disparo:</label>
                        <input type="time" name="send_time" value="08:00" required class="w-full px-3 py-2 bg-slate-950 border border-slate-800 rounded-xl text-white font-mono focus:border-purple-500 focus:outline-none">
                    </div>
                    <div class="space-y-1">
                        <label class="text-slate-400 font-bold block">Canal de Envio:</label>
                        <select name="channel" id="schedule-channel-select" onchange="toggleScheduleChannelFields()" class="w-full px-3 py-2 bg-slate-950 border border-slate-800 rounded-xl text-white focus:border-purple-500 focus:outline-none">
                            <option value="email">📧 Apenas E-mail</option>
                            <option value="whatsapp" <?php echo !$isWaActive ? 'disabled' : ''; ?>>💬 Apenas WhatsApp (Prismabot) <?php echo !$isWaActive ? '(API Inativa)' : ''; ?></option>
                            <option value="ambos" <?php echo !$isWaActive ? 'disabled' : ''; ?>>🚀 Ambos (E-mail + WhatsApp) <?php echo !$isWaActive ? '(API Inativa)' : ''; ?></option>
                        </select>
                    </div>
                </div>

                <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                    <div class="space-y-1" id="box-email-to">
                        <label class="text-slate-400 font-bold block">Destinatário E-mail:</label>
                        <input type="email" id="schedule-email-to" name="email_to" placeholder="diretoria@empresa.com.br" class="w-full px-3 py-2 bg-slate-950 border border-slate-800 rounded-xl text-white font-mono focus:border-purple-500 focus:outline-none transition">
                    </div>
                    <div class="space-y-1" id="box-whatsapp-to">
                        <label class="text-slate-400 font-bold block">Destinatário WhatsApp:</label>
                        <?php if ($isWaActive): ?>
                        <input type="text" id="schedule-whatsapp-to" name="whatsapp_to" placeholder="5511999998888" class="w-full px-3 py-2 bg-slate-950 border border-slate-800 rounded-xl text-white font-mono focus:border-purple-500 focus:outline-none transition">
                        <?php else: ?>
                        <input type="text" id="schedule-whatsapp-to" name="whatsapp_to" disabled placeholder="⚠️ API Prismabot WhatsApp Inativa" class="w-full px-3 py-2 bg-slate-900/60 border border-slate-800/80 rounded-xl text-slate-500 font-mono cursor-not-allowed select-none opacity-60">
                        <p class="text-[10px] text-amber-400 font-bold flex items-start gap-1.5 mt-1.5 bg-amber-500/10 border border-amber-500/20 p-2 rounded-lg leading-snug">
                            <i class="fa-solid fa-triangle-exclamation text-amber-400 shrink-0 mt-0.5"></i>
                            <span>Para enviar relatórios via WhatsApp, configure e ative a API Prismabot em <a href="index.php?module=configuracoes&action=api" class="text-amber-300 underline font-extrabold hover:text-amber-200">Configurações &gt; APIs &amp; Conexões</a>.</span>
                        </p>
                        <?php endif; ?>
                    </div>
                </div>

                <script>
                function toggleScheduleChannelFields() {
                    const ch = document.getElementById('schedule-channel-select')?.value;
                    const emailInput = document.getElementById('schedule-email-to');
                    const waInput    = document.getElementById('schedule-whatsapp-to');
                    const isWaActive = <?php echo $isWaActive ? 'true' : 'false'; ?>;

                    if (!emailInput || !waInput) return;

                    if (ch === 'email') {
                        emailInput.disabled = false;
                        emailInput.classList.remove('opacity-40', 'cursor-not-allowed');
                        if (isWaActive) {
                            waInput.disabled = true;
                            waInput.classList.add('opacity-40', 'cursor-not-allowed');
                        }
                    } else if (ch === 'whatsapp') {
                        emailInput.disabled = true;
                        emailInput.classList.add('opacity-40', 'cursor-not-allowed');
                        if (isWaActive) {
                            waInput.disabled = false;
                            waInput.classList.remove('opacity-40', 'cursor-not-allowed');
                        }
                    } else { // ambos
                        emailInput.disabled = false;
                        emailInput.classList.remove('opacity-40', 'cursor-not-allowed');
                        if (isWaActive) {
                            waInput.disabled = false;
                            waInput.classList.remove('opacity-40', 'cursor-not-allowed');
                        }
                    }
                }
                document.addEventListener('DOMContentLoaded', toggleScheduleChannelFields);
                </script>

                <div class="flex items-center justify-end pt-2 border-t border-slate-800/60">
                    <?php if (hasUserPermission('schedule_reports')): ?>
                    <button type="submit" class="px-5 py-2 bg-purple-600 hover:bg-purple-500 text-white rounded-xl text-xs font-extrabold transition shadow-lg shadow-purple-600/30 flex items-center gap-2">
                        <i class="fa-solid fa-clock"></i> Criar Agendamento
                    </button>
                    <?php else: ?>
                    <span class="text-slate-500 text-xs italic font-bold" title="Sem permissão para agendar relatórios"><i class="fa-solid fa-lock"></i> Sem Permissão para Agendar</span>
                    <?php endif; ?>
                </div>
            </form>
        </div>
    </div>

    <!-- 3. LISTAGEM DE AGENDAMENTOS ATIVOS -->
    <div class="bg-slate-900/90 border border-slate-800 rounded-3xl p-6 shadow-2xl space-y-4">
        <div class="flex items-center justify-between border-b border-slate-800 pb-3">
            <h4 class="text-xs font-extrabold text-white uppercase tracking-wider flex items-center gap-2">
                <i class="fa-solid fa-list-check text-cyan-400"></i> Agendamentos de Relatórios Ativos
            </h4>
            <span class="text-xs text-slate-400 font-mono"><?php echo count($schedules); ?> agendamentos configurados</span>
        </div>

        <div class="overflow-x-auto custom-scrollbar">
            <table class="w-full text-left text-xs">
                <thead>
                    <tr class="bg-slate-950 text-slate-400 font-bold uppercase tracking-wider border-b border-slate-800">
                        <th class="py-3 px-4">Título</th>
                        <th class="py-3 px-4">Relatório</th>
                        <th class="py-3 px-4">Frequência</th>
                        <th class="py-3 px-4">Horário</th>
                        <th class="py-3 px-4">Canal</th>
                        <th class="py-3 px-4">Destinatários</th>
                        <th class="py-3 px-4">Último Envio</th>
                        <th class="py-3 px-4 text-center">Ações</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-800/60 font-mono">
                    <?php if (empty($schedules)): ?>
                        <tr>
                            <td colspan="8" class="py-8 text-center text-slate-500 italic">
                                Nenhum agendamento cadastrado no momento. Preencha o formulário acima para agendar o envio automático de relatórios.
                            </td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($schedules as $s): ?>
                            <tr class="hover:bg-slate-800/40 transition">
                                <td class="py-3 px-4 font-bold text-white font-sans"><?php echo htmlspecialchars($s['title']); ?></td>
                                <td class="py-3 px-4 text-purple-300 font-sans"><?php echo htmlspecialchars($s['report_type']); ?></td>
                                <td class="py-3 px-4 text-slate-300 uppercase font-bold"><?php echo htmlspecialchars($s['frequency']); ?></td>
                                <td class="py-3 px-4 text-amber-300 font-bold"><?php echo htmlspecialchars($s['send_time']); ?></td>
                                <td class="py-3 px-4">
                                    <?php if ($s['channel'] === 'email'): ?>
                                        <span class="px-2 py-0.5 rounded text-[10px] font-bold bg-amber-500/20 text-amber-300 border border-amber-500/30">📧 E-MAIL</span>
                                    <?php elseif ($s['channel'] === 'whatsapp'): ?>
                                        <span class="px-2 py-0.5 rounded text-[10px] font-bold bg-emerald-500/20 text-emerald-300 border border-emerald-500/30">💬 WHATSAPP</span>
                                    <?php else: ?>
                                        <span class="px-2 py-0.5 rounded text-[10px] font-bold bg-purple-500/20 text-purple-300 border border-purple-500/30">🚀 E-MAIL + WHATSAPP</span>
                                    <?php endif; ?>
                                </td>
                                <td class="py-3 px-4 text-slate-300 font-sans">
                                    <?php echo htmlspecialchars($s['email_to'] ?: '-'); ?> 
                                    <?php echo $s['whatsapp_to'] ? " / " . htmlspecialchars($s['whatsapp_to']) : ''; ?>
                                </td>
                                <td class="py-3 px-4 text-slate-400"><?php echo htmlspecialchars($s['last_sent']); ?></td>
                                <td class="py-3 px-4 text-center">
                                    <div class="flex items-center justify-center gap-1.5">
                                        <a href="index.php?module=configuracoes&action=smtp&trigger_now=<?php echo $s['id']; ?>" class="px-2.5 py-1 bg-purple-600/20 hover:bg-purple-600/40 text-purple-300 border border-purple-500/30 rounded-lg text-[10px] font-bold transition flex items-center gap-1" title="Testar Envio Imediato">
                                            <i class="fa-solid fa-play"></i> Disparar Agora
                                        </a>
                                        <a href="index.php?module=configuracoes&action=smtp&delete_schedule=<?php echo $s['id']; ?>" onclick="return confirm('Deseja realmente remover este agendamento?')" class="px-2 py-1 bg-rose-600/20 hover:bg-rose-600/40 text-rose-300 border border-rose-500/30 rounded-lg text-[10px] font-bold transition" title="Excluir Agendamento">
                                            <i class="fa-solid fa-trash-can"></i>
                                        </a>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>

    <!-- 4. HISTÓRICO DE LOGS DE ENVIO DE RELATÓRIOS -->
    <div class="bg-slate-900/90 border border-slate-800 rounded-3xl p-6 shadow-2xl space-y-4">
        <div class="flex items-center justify-between border-b border-slate-800 pb-3">
            <h4 class="text-xs font-extrabold text-white uppercase tracking-wider flex items-center gap-2">
                <i class="fa-solid fa-clock-rotate-left text-emerald-400"></i> Histórico de Logs de Envio de Relatórios
            </h4>
            <span class="text-xs text-slate-400 font-mono"><?php echo count($dispatchLogs); ?> últimos registros</span>
        </div>

        <div class="overflow-x-auto custom-scrollbar">
            <table class="w-full text-left text-xs">
                <thead>
                    <tr class="bg-slate-950 text-slate-400 font-bold uppercase tracking-wider border-b border-slate-800">
                        <th class="py-3 px-4">Data/Hora Envio</th>
                        <th class="py-3 px-4">De (Remetente)</th>
                        <th class="py-3 px-4">Para (Destinatário)</th>
                        <th class="py-3 px-4">Relatório Enviado</th>
                        <th class="py-3 px-4">Canal</th>
                        <th class="py-3 px-4 text-center">Status</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-800/60 font-mono">
                    <?php if (empty($dispatchLogs)): ?>
                        <tr>
                            <td colspan="6" class="py-8 text-center text-slate-500 italic">
                                Nenhum log de envio registrado até o momento.
                            </td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($dispatchLogs as $log): ?>
                            <tr class="hover:bg-slate-800/40 transition">
                                <td class="py-3 px-4 text-slate-300"><?php echo htmlspecialchars($log['timestamp']); ?></td>
                                <td class="py-3 px-4 text-slate-400 font-sans"><?php echo htmlspecialchars($log['from']); ?></td>
                                <td class="py-3 px-4 text-cyan-300 font-sans font-bold"><?php echo htmlspecialchars($log['to']); ?></td>
                                <td class="py-3 px-4 text-purple-300 font-sans font-bold"><?php echo htmlspecialchars($log['report']); ?></td>
                                <td class="py-3 px-4 font-bold text-slate-300"><?php echo htmlspecialchars($log['channel']); ?></td>
                                <td class="py-3 px-4 text-center">
                                    <?php if (strcasecmp($log['status'], 'sucesso') === 0): ?>
                                        <span class="px-2 py-0.5 rounded text-[10px] font-bold bg-emerald-500/20 text-emerald-300 border border-emerald-500/30">🟢 SUCESSO</span>
                                    <?php else: ?>
                                        <span class="px-2 py-0.5 rounded text-[10px] font-bold bg-amber-500/20 text-amber-300 border border-amber-500/30">🟡 PROCESSADO</span>
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

<script>
function onSmtpProviderChange(preset) {
    const hostEl    = document.querySelector('input[name="host"]');
    const portEl    = document.querySelector('input[name="port"]');
    const userEl    = document.querySelector('input[name="user"]');
    const passEl    = document.querySelector('input[name="password"]');
    const secEl     = document.querySelector('select[name="security"]');
    const fromEl    = document.querySelector('input[name="from_email"]');
    const nameEl    = document.querySelector('input[name="from_name"]');
    const passBadge = document.getElementById('pass_security_badge');

    if (preset === 'prisma') {
        if (hostEl) hostEl.value = 'mail.prismatelecom.com';
        if (portEl) portEl.value = '465';
        if (secEl)  secEl.value  = 'ssl';
        if (userEl) userEl.value = 'suporte@prismatelecom.com';
        if (passEl) {
            passEl.value = '';
            passEl.placeholder = '•••••••••••• (Protegida pelo Sistema)';
            passEl.type = 'password';
        }
        if (fromEl) fromEl.value = 'suporte@prismatelecom.com';
        if (nameEl) nameEl.value = 'IPbx Prisma - Suporte';
        if (passBadge) passBadge.innerHTML = '<i class="fa-solid fa-shield-halved text-amber-400"></i> Protegida (Prisma Default)';
    } else if (preset === 'gmail') {
        if (hostEl) hostEl.value = 'smtp.gmail.com';
        if (portEl) portEl.value = '587';
        if (secEl)  secEl.value  = 'tls';
        if (userEl) userEl.value = '';
        if (passEl) {
            passEl.value = '';
            passEl.placeholder = 'Sua senha de aplicativo do Gmail';
            passEl.type = 'password';
        }
        if (fromEl) fromEl.value = '';
        if (nameEl) nameEl.value = 'IPbx Prisma - Relatórios';
        if (passBadge) passBadge.innerHTML = '<i class="fa-solid fa-lock text-emerald-400"></i> Criptografada AES-256';
    } else if (preset === 'outlook') {
        if (hostEl) hostEl.value = 'smtp.office365.com';
        if (portEl) portEl.value = '587';
        if (secEl)  secEl.value  = 'tls';
        if (userEl) userEl.value = '';
        if (passEl) {
            passEl.value = '';
            passEl.placeholder = 'Sua senha do Outlook / Office 365';
            passEl.type = 'password';
        }
        if (fromEl) fromEl.value = '';
        if (nameEl) nameEl.value = 'IPbx Prisma - Relatórios';
        if (passBadge) passBadge.innerHTML = '<i class="fa-solid fa-lock text-emerald-400"></i> Criptografada AES-256';
    } else if (preset === 'custom') {
        if (hostEl) { hostEl.value = ''; hostEl.focus(); }
        if (portEl) portEl.value = '587';
        if (secEl)  secEl.value  = 'tls';
        if (userEl) userEl.value = '';
        if (passEl) {
            passEl.value = '';
            passEl.placeholder = 'Senha do seu servidor SMTP';
            passEl.type = 'password';
        }
        if (fromEl) fromEl.value = '';
        if (nameEl) nameEl.value = 'IPbx Prisma - Relatórios';
        if (passBadge) passBadge.innerHTML = '<i class="fa-solid fa-lock text-emerald-400"></i> Criptografada AES-256';
    }
}

function togglePasswordVisibility(inputId, btn) {
    const providerSelect = document.getElementById('smtp_provider_select');
    const input = document.getElementById(inputId);
    if (!input) return;

    if (providerSelect && providerSelect.value === 'prisma') {
        alert('🔒 A senha do servidor padrão Prisma Telecom é mantida protegida pelo sistema e não pode ser exibida por motivos de segurança.');
        return;
    }

    const isPass = input.type === 'password';
    input.type = isPass ? 'text' : 'password';
    btn.innerHTML = isPass ? '<i class="fa-solid fa-eye-slash text-xs"></i>' : '<i class="fa-solid fa-eye text-xs"></i>';
}
</script>
