<?php
/**
 * IPbx Prisma - Abstração de Banco de Dados
 * SQLite Local (.ht_whatsapp_config.sqlite) + Conexão MySQL Issabel/Asterisk
 */

if (session_status() === PHP_SESSION_NONE) {
    if (PHP_SAPI !== 'cli') {
        session_set_cookie_params([
            'lifetime' => 0,
            'path'     => '/',
            'secure'   => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
            'httponly' => true,
            'samesite' => 'Strict',
        ]);
    }
    // Nome de cookie próprio: o painel clássico e o IssabelPBX (/admin) usam PHPSESSID no mesmo endereço e,
    // ao entrar/sair/renovar a sessão numa aba deles, apagavam o cookie do painel novo (login pedido do nada).
    if (PHP_SAPI !== 'cli') session_name('IPBXPRISMASESS');
    // Sessão própria do painel: pasta privada e validade longa (8 h). Evita que a limpeza
    // automática de sessões do painel clássico (24 min) derrube o login do painel novo.
    $__sessDir = __DIR__ . '/../logs/sessions';
    $GLOBALS['__sess_default_path'] = session_save_path();
    if (PHP_SAPI !== 'cli' && !is_dir($__sessDir)) @mkdir($__sessDir, 0700, true);
    if (PHP_SAPI !== 'cli' && is_dir($__sessDir) && is_writable($__sessDir)) {
        @file_put_contents($__sessDir . '/.htaccess', "Require all denied\n");
        session_save_path($__sessDir);
        ini_set('session.gc_maxlifetime', '28800');
        ini_set('session.gc_probability', '1'); ini_set('session.gc_divisor', '100');
    }
    session_start();
}

$db_file = __DIR__ . '/../.ht_whatsapp_config.sqlite';
$first_run = !file_exists($db_file);

try {
    $db = new PDO("sqlite:" . $db_file);
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    try { $db->exec('PRAGMA busy_timeout = 15000'); } catch (Exception $e) {}
    // WAL: leitores (páginas, FOP, crons) não bloqueiam quem grava; evita "database is locked" com acessos simultâneos
    // A conversão exige que nenhuma outra conexão esteja aberta: tenta SEM esperar (espera 0) e só quando o banco ainda não está em WAL,
    // senão cada acesso ficaria parado no bloqueio. Se não der agora, tenta de novo no próximo acesso.
    try {
        if (strtolower((string)$db->query('PRAGMA journal_mode')->fetchColumn()) !== 'wal' && is_writable($db_file)) {
            $db->exec('PRAGMA busy_timeout = 0');
            try { $db->exec('PRAGMA journal_mode = WAL'); } catch (Exception $e) {}
            $db->exec('PRAGMA busy_timeout = 15000');
        }
        $db->exec('PRAGMA synchronous = NORMAL');
    } catch (Exception $e) { try { $db->exec('PRAGMA busy_timeout = 15000'); } catch (Exception $e2) {} }
    if (isset($__perf_t0, $__perf_marks)) $__perf_marks['sqlite (abrir/modo WAL)'] = microtime(true) - $__perf_t0;
    $db->exec("PRAGMA encoding = 'UTF-8';");
    // DeepSeek foi removido do painel: apaga chave/estado salvos e volta funções que o usavam para Automático
    // (só grava quando ainda existe algo de DeepSeek: evita escrita a cada acesso, que causava "database is locked")
    try {
        if ($db->query("SELECT 1 FROM settings WHERE key_name IN ('ai_key_deepseek','ai_key_status_deepseek') OR (key_name='ai_provider' AND value_val='deepseek') LIMIT 1")->fetchColumn()) {
            $db->exec("DELETE FROM settings WHERE key_name IN ('ai_key_deepseek','ai_key_status_deepseek') OR (key_name='ai_provider' AND value_val='deepseek')");
        }
        if ($db->query("SELECT 1 FROM settings WHERE key_name LIKE 'ai_role_%_provider' AND value_val='deepseek' LIMIT 1")->fetchColumn()) {
            $db->exec("UPDATE settings SET value_val='' WHERE key_name LIKE 'ai_role_%_provider' AND value_val='deepseek'");
        }
    } catch (Exception $e) {}


    // Estrutura do banco: só roda quando a versão mudou (PRAGMA user_version). No dia a dia o acesso NÃO grava nada aqui,
    // então páginas, FOP, crons e AGI nunca disputam o bloqueio de escrita na abertura (causa do "database is locked").
    // Ao criar/alterar tabelas ou colunas abaixo, aumente $__schema_ver para o banco ser atualizado uma única vez.
    $__schema_ver = 4;
    if ((int)$db->query('PRAGMA user_version')->fetchColumn() < $__schema_ver) {
    // Criar tabelas se não existirem
    $db->exec("CREATE TABLE IF NOT EXISTS settings (key_name TEXT PRIMARY KEY, value_val TEXT)");
    $db->exec("CREATE TABLE IF NOT EXISTS sent_logs (id INTEGER PRIMARY KEY AUTOINCREMENT, phone TEXT, call_id TEXT, extension TEXT, rule_type TEXT, message TEXT, status TEXT, response_raw TEXT, created_at DATETIME DEFAULT CURRENT_TIMESTAMP)");
    $db->exec("CREATE TABLE IF NOT EXISTS extensions_config (id INTEGER PRIMARY KEY AUTOINCREMENT, extension VARCHAR(20) UNIQUE NOT NULL, agent_name VARCHAR(100) NOT NULL, tech VARCHAR(20) DEFAULT 'PJSIP', whatsapp_number VARCHAR(30) DEFAULT '', notify_agent_missed INTEGER DEFAULT 1, notify_agent_internal INTEGER DEFAULT 1, notify_client_busy INTEGER DEFAULT 1, send_call_recording INTEGER DEFAULT 0, send_ai_summary INTEGER DEFAULT 0, ringtime_limit INTEGER DEFAULT 0, created_at DATETIME DEFAULT CURRENT_TIMESTAMP, updated_at DATETIME DEFAULT CURRENT_TIMESTAMP)");
    $db->exec("CREATE TABLE IF NOT EXISTS queues_config (id INTEGER PRIMARY KEY AUTOINCREMENT, queue_number VARCHAR(20) UNIQUE NOT NULL, queue_name VARCHAR(100) NOT NULL, abandon_enabled INTEGER DEFAULT 1, abandon_msg TEXT, supervisor_whatsapp VARCHAR(30) DEFAULT '', created_at DATETIME DEFAULT CURRENT_TIMESTAMP, updated_at DATETIME DEFAULT CURRENT_TIMESTAMP)");
    $db->exec("CREATE TABLE IF NOT EXISTS nps_responses (id INTEGER PRIMARY KEY AUTOINCREMENT, phone VARCHAR(30), extension VARCHAR(20), agent_name VARCHAR(100), score INTEGER, feedback TEXT, created_at DATETIME DEFAULT CURRENT_TIMESTAMP)");
    $db->exec("CREATE TABLE IF NOT EXISTS integration_rules (key_name VARCHAR(100) PRIMARY KEY, value_val TEXT)");
    $db->exec("CREATE TABLE IF NOT EXISTS contacts_cache (phone VARCHAR(30) PRIMARY KEY, name VARCHAR(150), email VARCHAR(150), updated_at DATETIME DEFAULT CURRENT_TIMESTAMP)");
    $db->exec("CREATE TABLE IF NOT EXISTS system_users (id INTEGER PRIMARY KEY AUTOINCREMENT, name VARCHAR(150) NOT NULL, email VARCHAR(150) UNIQUE NOT NULL, role VARCHAR(50) NOT NULL DEFAULT 'Usuário', extension VARCHAR(20) DEFAULT '', whatsapp VARCHAR(30) DEFAULT '', permissions TEXT DEFAULT '', status VARCHAR(20) DEFAULT 'Ativo', created_at DATETIME DEFAULT CURRENT_TIMESTAMP, updated_at DATETIME DEFAULT CURRENT_TIMESTAMP)");

    // Migrações automáticas de colunas
    try { $db->exec("ALTER TABLE extensions_config ADD COLUMN tech VARCHAR(20) DEFAULT 'PJSIP'"); } catch (Exception $e) {}
    try { $db->exec("ALTER TABLE extensions_config ADD COLUMN whatsapp_number VARCHAR(30) DEFAULT ''"); } catch (Exception $e) {}
    try { $db->exec("ALTER TABLE extensions_config ADD COLUMN send_ai_summary INTEGER DEFAULT 0"); } catch (Exception $e) {}
    try { $db->exec("ALTER TABLE extensions_config ADD COLUMN notify_agent_internal INTEGER DEFAULT 1"); } catch (Exception $e) {}
    try { $db->exec("ALTER TABLE extensions_config ADD COLUMN ringtime_limit INTEGER DEFAULT 0"); } catch (Exception $e) {}
    try { $db->exec("ALTER TABLE extensions_config ADD COLUMN send_call_recording INTEGER DEFAULT 0"); } catch (Exception $e) {}
    try { $db->exec("ALTER TABLE sent_logs ADD COLUMN call_id TEXT"); } catch (Exception $e) {}
    try { $db->exec("ALTER TABLE sent_logs ADD COLUMN extension TEXT"); } catch (Exception $e) {}
    try { $db->exec("ALTER TABLE sent_logs ADD COLUMN rule_type TEXT"); } catch (Exception $e) {}
    try { $db->exec("ALTER TABLE sent_logs ADD COLUMN response_raw TEXT"); } catch (Exception $e) {}

    try { $db->exec("ALTER TABLE system_users ADD COLUMN password_hash TEXT DEFAULT ''"); } catch (Exception $e) {}
    try { $db->exec("ALTER TABLE system_users ADD COLUMN webphone INTEGER DEFAULT 0"); } catch (Exception $e) {}
    // Webphone com conta personalizada (outro servidor): domínio, porta WSS, usuário e senha (criptografada)
    foreach (['wp_custom INTEGER DEFAULT 0', "wp_host TEXT DEFAULT ''", 'wp_port INTEGER DEFAULT 8089', "wp_user TEXT DEFAULT ''", "wp_pass TEXT DEFAULT ''"] as $__c) { try { $db->exec("ALTER TABLE system_users ADD COLUMN $__c"); } catch (Exception $e) {} }

    // Carga inicial: apenas o administrador; os demais usuários são cadastrados em Configurações > Usuários
    $u_cnt = $db->query("SELECT COUNT(*) as total FROM system_users")->fetchColumn();
    if ($u_cnt == 0) {
        $st_ins = $db->prepare("INSERT INTO system_users (name, email, role, extension, whatsapp, permissions, status) VALUES (:name, :email, :role, :ext, :wa, :perm, 'Ativo')");
        $st_ins->execute([
            ':name' => 'Leandro',
            ':email' => 'leandro@prismatelecom.com.br',
            ':role' => 'Administrador',
            ':ext' => '201',
            ':wa' => '',
            ':perm' => json_encode(['mod_dashboard','mod_filas','mod_whatsapp','mod_relatorios','mod_configuracoes','listen_recordings','send_whatsapp','manage_settings','schedule_reports','click_to_call'])
        ]);
    }

    // Carga de configurações padrão na primeira execução
    if ($first_run) {
        $defaults = [
            'api_url' => 'https://api.prismabot.com.br/v2/api/external/588c0e57-09f5-4b8a-8c64-7b12980cb1b3',
            'api_token' => '',
            'ai_api_key' => '',
            'ai_provider' => 'groq',
            'ai_model_chat' => 'llama3-8b-8192',
            'ai_model_audio' => 'whisper-large-v3',
            'msg_opt1' => "Olá! Identificamos o seu contato via URA. Aqui está o nosso atendimento oficial pelo WhatsApp!",
            'msg_opt2' => "Olá! Recebemos sua solicitação de suporte via URA e já iniciamos seu atendimento por aqui.",
            'asterisk_db_host' => 'localhost',
            'asterisk_db_user' => 'asteriskuser',
            'asterisk_db_pass' => 'eLaStIx.AsTeRiSk.UsEr.1',
            'asterisk_db_name' => 'asteriskcdrdb',
            'ami_host' => '127.0.0.1',
            'ami_port' => '5038',
            'ami_user' => 'admin',
            'ami_secret' => 'elaStix.aSterisk.pass'
        ];
        $stmt = $db->prepare("INSERT OR REPLACE INTO settings (key_name, value_val) VALUES (:key, :val)");
        foreach ($defaults as $k => $v) {
            $stmt->execute([':key' => $k, ':val' => $v]);
        }
    }

    // Inicializa regras padrão de integração
    $default_rules = [
        'queue_abandon_msg_default' => 'Olá! Vimos que você tentou ligar para nosso setor {NOME_FILA} no número {CLIENTE} às {DATA_HORA} e não conseguiu aguardar. Como podemos te ajudar por aqui?',
        'queue_exit_whatsapp_msg' => 'Olá! Vimos que você optou por não aguardar na linha da fila {NOME_FILA} no número {CLIENTE} às {DATA_HORA}. Já iniciamos seu atendimento VIP por aqui!',
        'missed_agent_msg' => 'Atenção {ATENDENTE}: Você teve uma chamada não atendida vinda do número {CLIENTE} às {DATA_HORA} no Ramal {RAMAL}.',
        'missed_client_msg' => 'Olá! O atendente {ATENDENTE} (Ramal {RAMAL}) está temporariamente indisponível. Recebemos sua chamada do número {CLIENTE} às {DATA_HORA}. Deixe sua mensagem por aqui!',
        'nps_enabled' => '1',
        'nps_msg' => 'Olá! Obrigado por seu contato com o atendente {ATENDENTE} no número {CLIENTE} às {DATA_HORA}. De 1 a 5, qual nota você dá para o atendimento recebido?'
    ];
    $rule_stmt = $db->prepare("INSERT OR IGNORE INTO integration_rules (key_name, value_val) VALUES (:key, :val)");
    foreach ($default_rules as $rk => $rv) {
        $rule_stmt->execute([':key' => $rk, ':val' => $rv]);
    }
        $db->exec('PRAGMA user_version = ' . $__schema_ver);
    }
} catch (PDOException $e) {
    if (strpos($e->getMessage(), 'readonly') !== false || strpos($e->getMessage(), 'PERM') !== false || strpos($e->getMessage(), 'attempt to write') !== false) {
        die('<div style="font-family:sans-serif; background:#0f172a; color:#f8fafc; padding:30px; border-radius:12px; max-width:650px; margin:50px auto; border:1px solid #334155;">
            <h2 style="color:#ef4444; margin-top:0;">⚠️ Permissão de Escrita Necessária no Linux PABX</h2>
            <p>O servidor Apache/PABX não tem permissão para escrever na pasta do painel.</p>
            <pre style="background:#020617; padding:15px; border-radius:8px; color:#38bdf8; font-size:14px;">chown -R asterisk:asterisk /var/www/html/front_api
chmod -R 775 /var/www/html/front_api</pre>
        </div>');
    }
    // Banco ocupado na abertura: se o arquivo já existia (estrutura pronta), segue em frente em vez de derrubar a tela
    if (!$first_run && isset($db) && $db instanceof PDO && (stripos($e->getMessage(), 'locked') !== false || stripos($e->getMessage(), 'busy') !== false)) {
        @error_log('[IPbx Prisma] SQLite ocupado na abertura (seguindo sem rodar a verificação de estrutura): ' . $e->getMessage());
    } else {
        die("Erro ao iniciar banco de dados interno (SQLite): " . $e->getMessage());
    }
}
