<?php
/**
 * IPbx Prisma - Autenticação do painel (login, sessão, permissões de rota)
 *
 * - Senhas em password_hash() na coluna system_users.password_hash
 * - Sessão com cookie HttpOnly + SameSite=Strict (ver config/database.php)
 * - Bloqueio por tentativas falhas (por IP e por e-mail) em login_attempts
 * - Execução via CLI (AGI, cron) não passa pelo portão de login
 */

if (!defined('AUTH_MAX_FAILS'))   define('AUTH_MAX_FAILS', 5);
if (!defined('AUTH_LOCK_MINUTES')) define('AUTH_LOCK_MINUTES', 15);

function authIsCli() {
    return PHP_SAPI === 'cli';
}

function authClientIp() {
    return $_SERVER['REMOTE_ADDR'] ?? 'cli';
}

/** Caminho do log que o fail2ban (jail ipbx-front-api) monitora. */
function authFail2banLogPath() {
    return '/var/log/ipbx-front-api/auth.log';
}

/**
 * O jail ipbx-front-api está instalado no fail2ban do Issabel?
 * Quando sim, o bloqueio de IP é gerido pelo fail2ban (telas nativas Security > Fail2ban),
 * e o limitador interno fica desativado para não haver duas gestões diferentes.
 */
function authFail2banActive() {
    static $active = null;
    if ($active !== null) return $active;
    $active = false;
    if (is_file('/etc/fail2ban/jail.d/ipbx-front-api.conf')) {
        $active = true;
    } else {
        $c = @file_get_contents('/etc/fail2ban/jail.d/issabel.conf');
        if ($c !== false && strpos($c, '[ipbx-front-api]') !== false) $active = true;
    }
    return $active;
}

/** Registra falha de login em formato lido pelo filtro do fail2ban (usuário em urlencode: sem injeção de IP/linhas). */
function authLogFail2ban($email) {
    $line = date('Y-m-d H:i:s') . ' ipbx-front-api LOGIN_FAIL ip=' . authClientIp() . ' user=' . rawurlencode(substr((string)$email, 0, 80)) . "\n";
    $path = authFail2banLogPath();
    if (!@file_put_contents($path, $line, FILE_APPEND | LOCK_EX)) {
        @file_put_contents(__DIR__ . '/../logs/auth.log', $line, FILE_APPEND | LOCK_EX);
    }
}

function authEnsureSchema() {
    global $db;
    static $done = false;
    if ($done || !$db) return;
    $done = true;
    try {
        $db->exec("CREATE TABLE IF NOT EXISTS login_attempts (id INTEGER PRIMARY KEY AUTOINCREMENT, ip TEXT, email TEXT, success INTEGER DEFAULT 0, created_at DATETIME DEFAULT CURRENT_TIMESTAMP)");
    } catch (Exception $e) {}
}

/** Existe pelo menos um usuário ativo com senha definida? */
function authHasAnyPassword() {
    global $db;
    try {
        return (int)$db->query("SELECT COUNT(*) FROM system_users WHERE password_hash IS NOT NULL AND password_hash <> ''")->fetchColumn() > 0;
    } catch (Exception $e) {
        return false;
    }
}

function authSetPassword($user_id, $password) {
    global $db;
    if (strlen((string)$password) < 8) {
        return ['success' => false, 'error' => 'A senha deve ter pelo menos 8 caracteres.'];
    }
    try {
        $st = $db->prepare("UPDATE system_users SET password_hash = :h, updated_at = CURRENT_TIMESTAMP WHERE id = :id");
        $st->execute([':h' => password_hash($password, PASSWORD_DEFAULT), ':id' => intval($user_id)]);
        return ['success' => $st->rowCount() > 0, 'error' => $st->rowCount() > 0 ? '' : 'Usuário não encontrado.'];
    } catch (Exception $e) {
        return ['success' => false, 'error' => 'Erro ao gravar senha: ' . $e->getMessage()];
    }
}

function authIsLocked($email) {
    global $db;
    authEnsureSchema();
    try {
        $st = $db->prepare("SELECT COUNT(*) FROM login_attempts WHERE success = 0 AND created_at >= :since AND (ip = :ip OR email = :em)");
        // created_at é gravado em UTC pelo SQLite; compara no mesmo fuso
        $st->execute([':since' => gmdate('Y-m-d H:i:s', time() - AUTH_LOCK_MINUTES * 60), ':ip' => authClientIp(), ':em' => strtolower($email)]);
        return (int)$st->fetchColumn() >= AUTH_MAX_FAILS;
    } catch (Exception $e) {
        return false;
    }
}

function authRecordAttempt($email, $success) {
    global $db;
    authEnsureSchema();
    try {
        $st = $db->prepare("INSERT INTO login_attempts (ip, email, success) VALUES (:ip, :em, :s)");
        $st->execute([':ip' => authClientIp(), ':em' => strtolower($email), ':s' => $success ? 1 : 0]);
        if ($success) {
            $db->prepare("DELETE FROM login_attempts WHERE success = 0 AND (ip = :ip OR email = :em)")->execute([':ip' => authClientIp(), ':em' => strtolower($email)]);
        }
        $db->exec("DELETE FROM login_attempts WHERE created_at < datetime('now', '-2 days')");
    } catch (Exception $e) {}
}

/** Valida credenciais e abre a sessão. Retorna ['success'=>bool,'error'=>string]. */
function authLogin($email, $password) {
    global $db;
    $email = trim((string)$email);
    if ($email === '' || (string)$password === '') {
        return ['success' => false, 'error' => 'Informe e-mail e senha.'];
    }
    if (!authFail2banActive() && authIsLocked($email)) {
        return ['success' => false, 'error' => 'Muitas tentativas. Aguarde ' . AUTH_LOCK_MINUTES . ' minutos e tente novamente.'];
    }
    $st = $db->prepare("SELECT * FROM system_users WHERE lower(email) = lower(:e) LIMIT 1");
    $st->execute([':e' => $email]);
    $u = $st->fetch(PDO::FETCH_ASSOC);

    // Sempre executa password_verify para não vazar existência do usuário pelo tempo de resposta
    $hash = $u['password_hash'] ?? '';
    $ok = password_verify((string)$password, $hash !== '' ? $hash : '$2y$10$usesomesillystringforsaltuQ5VhFmH0nQd0pB0m3x8cYb1fK1y2');
    if (!$u || $hash === '' || !$ok || ($u['status'] ?? 'Ativo') !== 'Ativo') {
        authRecordAttempt($email, false);
        authLogFail2ban($email);
        if (function_exists('pabx_log')) pabx_log('security', 'WARNING', 'Falha de login no painel', ['email' => $email, 'ip' => authClientIp()]);
        $hint = authFail2banActive()
            ? ' Após várias falhas seu IP é bloqueado pelo Fail2ban do Issabel.'
            : '';
        return ['success' => false, 'error' => 'E-mail ou senha inválidos.' . $hint];
    }
    authRecordAttempt($email, true);
    session_regenerate_id(true);
    $_SESSION['logged_user_id'] = (int)$u['id'];
    $_SESSION['auth_time'] = time();
    if (password_needs_rehash($hash, PASSWORD_DEFAULT)) {
        $db->prepare("UPDATE system_users SET password_hash = :h WHERE id = :id")->execute([':h' => password_hash($password, PASSWORD_DEFAULT), ':id' => $u['id']]);
    }
    if (function_exists('pabx_log')) pabx_log('security', 'INFO', 'Login no painel', ['email' => $u['email'], 'ip' => authClientIp()]);
    return ['success' => true, 'error' => ''];
}

function authLogout() {
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $p = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000, $p['path'], $p['domain'], $p['secure'], $p['httponly']);
    }
    @session_destroy();
}

function authIsLoggedIn() {
    if (empty($_SESSION['logged_user_id'])) return false;
    $u = getSystemUserById($_SESSION['logged_user_id']);
    if (!$u || ($u['status'] ?? 'Ativo') !== 'Ativo') {
        unset($_SESSION['logged_user_id']);
        return false;
    }
    return true;
}

/** Permissão exigida por módulo/ação (espelha as regras de exibição da sidebar). */
function authRoutePermission($module, $action) {
    switch ($module) {
        case 'dashboard':     return 'mod_dashboard';
        case 'filas':         return 'mod_filas';
        case 'relatorios':    return 'mod_relatorios';
        case 'configuracoes': return 'mod_configuracoes';
        case 'telefonia':
        case 'troncos':       return 'manage_settings';
        case 'ramais':        return ($action === 'fop') ? '' : 'manage_settings';
        case 'whatsapp':      return ($action === 'contatos') ? '' : 'mod_whatsapp';
    }
    return '';
}

function authRenderLogin($error = '', $notice = '') {
    $email = htmlspecialchars($_POST['email'] ?? '', ENT_QUOTES, 'UTF-8');
    $err   = $error  ? '<div class="msg err">' . htmlspecialchars($error, ENT_QUOTES, 'UTF-8') . '</div>' : '';
    $nt    = $notice ? '<div class="msg info">' . htmlspecialchars($notice, ENT_QUOTES, 'UTF-8') . '</div>' : '';
    $form  = $notice && !authHasAnyPassword() ? '' : '
        <form method="POST" action="index.php" autocomplete="on">
            <input type="hidden" name="auth_action" value="login">
            <label>E-mail</label>
            <input type="email" name="email" value="' . $email . '" required autofocus>
            <label>Senha</label>
            <input type="password" name="password" required>
            <button type="submit">Entrar</button>
        </form>';
    while (ob_get_level()) { @ob_end_clean(); }
    header('Content-Type: text/html; charset=utf-8');
    header('Cache-Control: no-store');
    echo '<!DOCTYPE html><html lang="pt-BR"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Entrar - IPbx Prisma</title><style>
*{box-sizing:border-box}body{margin:0;min-height:100vh;display:flex;align-items:center;justify-content:center;background:#0f172a;font-family:"Segoe UI",system-ui,sans-serif;color:#e2e8f0}
.card{width:100%;max-width:380px;background:#1c1b2f;border:1px solid #334155;border-radius:20px;padding:32px;box-shadow:0 20px 50px rgba(0,0,0,.45)}
h1{margin:0 0 4px;font-size:20px;background:linear-gradient(90deg,#7c3aed,#6366f1);-webkit-background-clip:text;color:transparent}
p.sub{margin:0 0 22px;font-size:12px;color:#94a3b8}label{display:block;font-size:11px;font-weight:700;color:#94a3b8;margin:14px 0 6px}
input{width:100%;padding:11px 12px;border-radius:12px;border:1px solid #334155;background:#0f172a;color:#f8fafc;font-size:14px}
input:focus{outline:2px solid #6366f1;border-color:transparent}
button{width:100%;margin-top:20px;padding:12px;border:0;border-radius:12px;background:linear-gradient(90deg,#7c3aed,#6366f1);color:#fff;font-weight:700;font-size:14px;cursor:pointer;white-space:nowrap}
.msg{margin-bottom:14px;padding:10px 12px;border-radius:10px;font-size:12px}.err{background:rgba(244,63,94,.12);border:1px solid rgba(244,63,94,.35);color:#fda4af}
.info{background:rgba(99,102,241,.12);border:1px solid rgba(99,102,241,.35);color:#c7d2fe}code{background:#0f172a;padding:2px 6px;border-radius:6px;font-size:11px;word-break:break-all}
</style></head><body><div class="card"><h1>IPbx Prisma</h1><p class="sub">Painel Integrado de Operações &amp; Telefonia</p>' . $err . $nt . $form . '</div></body></html>';
    exit;
}

/**
 * Portão de autenticação.
 * $mode: 'page' (HTML/redirect), 'json' (401 JSON) ou 'plain' (401 texto).
 * Retorna sem fazer nada quando já autenticado ou em execução CLI.
 */
/** Modo de teste: login desligado por `php set_auth.php off` (setting auth_disabled=1). */
function authDisabled() {
    return function_exists('getSetting') && getSetting('auth_disabled') === '1';
}

function authGate($mode = 'page') {
    if (authIsCli()) return;
    if (authDisabled()) return; // painel aberto (somente para testes em rede confiável)

    if ($mode === 'page') {
        if (($_POST['auth_action'] ?? '') === 'login' && $_SERVER['REQUEST_METHOD'] === 'POST') {
            $res = authLogin($_POST['email'] ?? '', $_POST['password'] ?? '');
            if ($res['success']) {
                header('Location: ' . strtok($_SERVER['REQUEST_URI'] ?? 'index.php', '?'));
                exit;
            }
            authRenderLogin($res['error']);
        }
        if (($_GET['auth'] ?? '') === 'logout') {
            authLogout();
            header('Location: index.php');
            exit;
        }
    }

    if (authIsLoggedIn()) return;

    if ($mode === 'json') {
        while (ob_get_level()) { @ob_end_clean(); }
        http_response_code(401);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['success' => false, 'error' => 'Sessão expirada. Faça login novamente.', 'auth_required' => true]);
        exit;
    }
    if ($mode === 'plain') {
        while (ob_get_level()) { @ob_end_clean(); }
        http_response_code(401);
        header('Content-Type: text/plain; charset=utf-8');
        echo 'Não autenticado.';
        exit;
    }

    if (!authHasAnyPassword()) {
        authRenderLogin('', 'Nenhuma senha de acesso foi definida ainda. No servidor, execute: php ' . realpath(__DIR__ . '/..') . '/set_password.php');
    }
    authRenderLogin();
}
