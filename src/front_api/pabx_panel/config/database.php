<?php
/**
 * IPbx Prisma - Abstração de Banco de Dados
 * SQLite Local (.ht_whatsapp_config.sqlite) + Conexão MySQL Issabel/Asterisk
 */

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$db_file = __DIR__ . '/../.ht_whatsapp_config.sqlite';
$first_run = !file_exists($db_file);

try {
    $db = new PDO("sqlite:" . $db_file);
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $db->exec("PRAGMA encoding = 'UTF-8';");

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
    try { $db->exec("ALTER TABLE sent_logs ADD COLUMN call_id TEXT"); } catch (Exception $e) {}
    try { $db->exec("ALTER TABLE sent_logs ADD COLUMN extension TEXT"); } catch (Exception $e) {}
    try { $db->exec("ALTER TABLE sent_logs ADD COLUMN rule_type TEXT"); } catch (Exception $e) {}
    try { $db->exec("ALTER TABLE sent_logs ADD COLUMN response_raw TEXT"); } catch (Exception $e) {}

    // Carga de usuários padrão se a tabela estiver vazia
    $u_cnt = $db->query("SELECT COUNT(*) as total FROM system_users")->fetchColumn();
    if ($u_cnt == 0) {
        $st_ins = $db->prepare("INSERT INTO system_users (name, email, role, extension, whatsapp, permissions, status) VALUES (:name, :email, :role, :ext, :wa, :perm, 'Ativo')");
        $st_ins->execute([
            ':name' => 'Leandro',
            ':email' => 'leandro@prismatelecom.com.br',
            ':role' => 'Administrador',
            ':ext' => '201',
            ':wa' => '5511999998888',
            ':perm' => json_encode(['mod_dashboard','mod_filas','mod_whatsapp','mod_relatorios','mod_configuracoes','listen_recordings','send_whatsapp','manage_settings','schedule_reports','click_to_call'])
        ]);
        $st_ins->execute([
            ':name' => 'Giovana',
            ':email' => 'giovana@prismatelecom.com.br',
            ':role' => 'Supervisor',
            ':ext' => '204',
            ':wa' => '5511988887777',
            ':perm' => json_encode(['mod_dashboard','mod_filas','mod_whatsapp','mod_relatorios','listen_recordings','send_whatsapp','schedule_reports','click_to_call'])
        ]);
        $st_ins->execute([
            ':name' => 'Stefani',
            ':email' => 'stefani@prismatelecom.com.br',
            ':role' => 'Usuário',
            ':ext' => '203',
            ':wa' => '551197776666',
            ':perm' => json_encode(['mod_dashboard','mod_filas','click_to_call'])
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
} catch (PDOException $e) {
    if (strpos($e->getMessage(), 'readonly') !== false || strpos($e->getMessage(), 'PERM') !== false || strpos($e->getMessage(), 'attempt to write') !== false) {
        die('<div style="font-family:sans-serif; background:#0f172a; color:#f8fafc; padding:30px; border-radius:12px; max-width:650px; margin:50px auto; border:1px solid #334155;">
            <h2 style="color:#ef4444; margin-top:0;">⚠️ Permissão de Escrita Necessária no Linux PABX</h2>
            <p>O servidor Apache/Asterisk não tem permissão para escrever na pasta do painel.</p>
            <pre style="background:#020617; padding:15px; border-radius:8px; color:#38bdf8; font-size:14px;">chown -R asterisk:asterisk /var/www/html/front_prisma_AMI
chmod -R 775 /var/www/html/front_prisma_AMI</pre>
        </div>');
    }
    die("Erro ao iniciar banco de dados interno (SQLite): " . $e->getMessage());
}
