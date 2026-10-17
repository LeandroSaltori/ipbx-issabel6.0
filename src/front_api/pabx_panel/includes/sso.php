<?php
/**
 * IPbx Prisma - Entrada única (painel clássico -> painel novo).
 *
 * O painel clássico gera um "passe" assinado (HMAC-SHA256) válido por poucos segundos e redireciona para
 * index.php?sso=PASSE. Aqui o passe é conferido e o usuário entra sem digitar senha: nenhuma senha do painel
 * clássico é lida, copiada ou guardada. O segredo compartilhado fica em /etc/ipbx/sso.key (criado pelo instalador).
 *
 * Formato: base64url(JSON {u: usuário, g: [grupos], x: ramal, n: nome, e: expira (unix), j: nonce}) . "." . base64url(HMAC)
 */

if (!defined('SSO_KEY_FILE')) define('SSO_KEY_FILE', '/etc/ipbx/sso.key');
if (!defined('SSO_MAX_TTL'))  define('SSO_MAX_TTL', 60);

function ssoB64d($s) { return base64_decode(strtr((string)$s, '-_', '+/')); }

function ssoKey() {
    $k = @file_get_contents(defined('SSO_KEY_FILE') ? SSO_KEY_FILE : '/etc/ipbx/sso.key');
    $k = is_string($k) ? trim($k) : '';
    return strlen($k) >= 32 ? $k : '';
}

/** Equivalência padrão grupo do painel clássico -> perfil do painel novo (ajustável em integration_rules 'sso_group_map'). */
function ssoGroupMap() {
    $map = ['administrator' => 'Administrador', 'supervisor' => 'Supervisor', 'ramal' => 'Usuário'];
    $custom = function_exists('getSetting') ? json_decode((string)getSetting('sso_group_map'), true) : null;
    if (is_array($custom)) foreach ($custom as $g => $r) { if (in_array($r, ['Administrador', 'Supervisor', 'Usuário'], true)) $map[strtolower((string)$g)] = $r; }
    return $map;
}

/** Perfil para um conjunto de grupos: vale o de maior acesso; grupo desconhecido = menor acesso (Usuário). */
function ssoRoleForGroups($groups) {
    $map = ssoGroupMap(); $rank = ['Usuário' => 1, 'Supervisor' => 2, 'Administrador' => 3]; $best = 'Usuário';
    foreach ((array)$groups as $g) { $r = $map[strtolower((string)$g)] ?? 'Usuário'; if ($rank[$r] > $rank[$best]) $best = $r; }
    return $best;
}

function ssoPresetPermissions($role) {
    $p = [
        'Administrador' => ['mod_dashboard', 'mod_filas', 'mod_whatsapp', 'mod_relatorios', 'mod_configuracoes', 'listen_recordings', 'send_whatsapp', 'manage_settings', 'schedule_reports', 'click_to_call', 'ai_chat'],
        'Supervisor'    => ['mod_dashboard', 'mod_filas', 'mod_whatsapp', 'mod_relatorios', 'listen_recordings', 'send_whatsapp', 'schedule_reports', 'click_to_call', 'ai_chat'],
        'Usuário'       => ['mod_dashboard', 'mod_filas', 'click_to_call'],
    ];
    return $p[$role] ?? $p['Usuário'];
}

/** Confere assinatura/validade e devolve o conteúdo do passe, ou string com o motivo da recusa. */
function ssoVerify($token) {
    $key = ssoKey();
    if ($key === '') return 'Entrada única não configurada neste servidor.';
    $parts = explode('.', (string)$token);
    if (count($parts) !== 2) return 'Passe inválido.';
    $calc = hash_hmac('sha256', $parts[0], $key, true);
    if (!hash_equals($calc, (string)ssoB64d($parts[1]))) return 'Passe inválido.';
    $d = json_decode((string)ssoB64d($parts[0]), true);
    if (!is_array($d) || empty($d['u']) || empty($d['e']) || empty($d['j'])) return 'Passe inválido.';
    $now = time();
    if ($d['e'] < $now || $d['e'] > $now + SSO_MAX_TTL + 5) return 'Passe expirado. Volte ao painel clássico e escolha o painel novo novamente.';
    return $d;
}

/** Cada passe só vale uma vez. */
function ssoConsumeNonce($nonce) {
    global $db;
    try {
        $db->exec("CREATE TABLE IF NOT EXISTS sso_nonces (nonce TEXT PRIMARY KEY, created_at INTEGER)");
        $db->exec("DELETE FROM sso_nonces WHERE created_at < " . (time() - 600));
        $st = $db->prepare("INSERT INTO sso_nonces (nonce, created_at) VALUES (?, ?)");
        return $st->execute([substr((string)$nonce, 0, 64), time()]);
    } catch (Exception $e) { return false; }
}

function ssoEnsureSchema() {
    global $db;
    static $done = false;
    if ($done || !$db) return; $done = true;
    try { $db->exec("ALTER TABLE system_users ADD COLUMN issabel_user TEXT DEFAULT ''"); } catch (Exception $e) {}
}

/** Localiza o usuário vinculado ao login do painel clássico; cria (menor acesso do grupo) na primeira entrada. */
function ssoFindOrCreateUser($d) {
    global $db;
    ssoEnsureSchema();
    $iu = substr(trim((string)$d['u']), 0, 50);
    $st = $db->prepare("SELECT * FROM system_users WHERE issabel_user = :u LIMIT 1");
    $st->execute([':u' => $iu]);
    $u = $st->fetch(PDO::FETCH_ASSOC);
    if ($u) return $u;
    $role = ssoRoleForGroups($d['g'] ?? []);
    $email = preg_replace('/[^a-z0-9._-]/i', '', $iu) . '@painel-classico.local';
    $name = trim((string)($d['n'] ?? '')) !== '' ? substr((string)$d['n'], 0, 150) : $iu;
    $ins = $db->prepare("INSERT INTO system_users (name, email, role, extension, whatsapp, permissions, status, issabel_user) VALUES (:n, :e, :r, :x, '', :p, 'Ativo', :i)");
    $ins->execute([':n' => $name, ':e' => $email, ':r' => $role, ':x' => preg_match('/^\d{2,10}$/', (string)($d['x'] ?? '')) ? $d['x'] : '', ':p' => json_encode(ssoPresetPermissions($role)), ':i' => $iu]);
    if (function_exists('pabx_log')) pabx_log('security', 'INFO', 'Usuário criado por entrada única', ['issabel_user' => $iu, 'role' => $role]);
    $st->execute([':u' => $iu]);
    return $st->fetch(PDO::FETCH_ASSOC);
}

/** Tenta entrar com ?sso=PASSE. Em qualquer caso redireciona; em falha mostra o login normal com o motivo. */
function ssoHandle($token) {
    $d = ssoVerify($token);
    if (!is_array($d)) { return ['ok' => false, 'error' => $d]; }
    if (!ssoConsumeNonce($d['j'])) return ['ok' => false, 'error' => 'Passe já utilizado.'];
    $u = ssoFindOrCreateUser($d);
    if (!$u || ($u['status'] ?? 'Ativo') !== 'Ativo') return ['ok' => false, 'error' => 'Usuário inativo no painel novo.'];
    session_regenerate_id(true);
    $_SESSION['logged_user_id'] = (int)$u['id'];
    $_SESSION['auth_time'] = time();
    $_SESSION['via_sso'] = 1;
    if (function_exists('pabx_log')) pabx_log('security', 'INFO', 'Login por entrada única', ['issabel_user' => $d['u'], 'ip' => function_exists('authClientIp') ? authClientIp() : '']);
    return ['ok' => true, 'error' => ''];
}

/**
 * Importa em lote os usuários do painel clássico (nome, ramal e perfil pelo grupo). Senhas NÃO são lidas nem copiadas:
 * o acesso é feito pela entrada única. Usuários já vinculados são mantidos como estão (ajustes manuais preservados).
 */
function ssoImportAll($aclPath = '/var/www/db/acl.db') {
    global $db;
    if (!is_readable($aclPath)) return ['ok' => false, 'error' => 'Base de usuários do painel clássico não encontrada neste servidor.'];
    try {
        $acl = new PDO('sqlite:' . $aclPath, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $rows = $acl->query("SELECT u.name, u.description, u.extension, g.name AS grp FROM acl_user u LEFT JOIN acl_membership m ON m.id_user = u.id LEFT JOIN acl_group g ON g.id = m.id_group ORDER BY u.id")->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e) { return ['ok' => false, 'error' => 'Não foi possível ler os usuários do painel clássico.']; }
    $byUser = [];
    foreach ($rows as $r) { $u = (string)$r['name']; if ($u === '') continue; $byUser[$u]['n'] = $r['description'] ?: $u; $byUser[$u]['x'] = $r['extension']; if ($r['grp'] !== null) $byUser[$u]['g'][] = $r['grp']; }
    $novos = 0; $existentes = 0;
    ssoEnsureSchema();
    foreach ($byUser as $login => $i) {
        $st = $db->prepare("SELECT id FROM system_users WHERE issabel_user = :u");
        $st->execute([':u' => $login]);
        if ($st->fetchColumn()) { $existentes++; continue; }
        ssoFindOrCreateUser(['u' => $login, 'g' => $i['g'] ?? [], 'n' => $i['n'], 'x' => $i['x']]);
        $novos++;
    }
    return ['ok' => true, 'novos' => $novos, 'existentes' => $existentes];
}

/* ---------- Sincronia do cadastro com a base de usuários da central (entrada pelo login inicial) ---------- */

function aclOpenRW($aclPath = '/var/www/db/acl.db') {
    if (!is_file($aclPath) || !is_writable($aclPath)) return null;
    try { return new PDO('sqlite:' . $aclPath, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]); } catch (Exception $e) { return null; }
}

function aclGroupForRole($role) {
    foreach (ssoGroupMap() as $g => $r) { if ($r === $role) return $g; }
    return 'ramal';
}

/**
 * Cria/atualiza o usuário na central para ele conseguir entrar pelo login inicial (mesmo usuário e senha).
 * Devolve ['ok'=>bool,'login'=>string,'error'=>string]. Falha de gravação nunca derruba o salvamento do painel novo.
 */
function aclSyncUser($rowId, $password = '') {
    global $db;
    ssoEnsureSchema();
    $acl = aclOpenRW();
    if (!$acl) return ['ok' => false, 'login' => '', 'error' => 'sem permissão de gravação na base de usuários da central'];
    try {
        $st = $db->prepare("SELECT * FROM system_users WHERE id = :i"); $st->execute([':i' => (int)$rowId]);
        $u = $st->fetch(PDO::FETCH_ASSOC);
        if (!$u) return ['ok' => false, 'login' => '', 'error' => 'usuário não encontrado'];
        $login = trim((string)($u['issabel_user'] ?? ''));
        $isNew = false;
        if ($login === '') {
            $login = trim((string)$u['email']);
            if ($login === '' || preg_match('/[\s\x00-\x1f]/', $login)) return ['ok' => false, 'login' => '', 'error' => 'e-mail inválido para o login'];
            $q = $acl->prepare("SELECT COUNT(*) FROM acl_user WHERE name = :n"); $q->execute([':n' => $login]);
            if ($q->fetchColumn() > 0) return ['ok' => false, 'login' => $login, 'error' => "já existe na central um usuário '$login' que não está vinculado a este cadastro"];
            $isNew = true;
        }
        $q = $acl->prepare("SELECT id FROM acl_user WHERE name = :n"); $q->execute([':n' => $login]);
        $aid = (int)$q->fetchColumn();
        $ext = preg_match('/^\d{2,10}$/', (string)$u['extension']) ? $u['extension'] : '';
        $acl->beginTransaction();
        if (!$aid) {
            $acl->prepare("INSERT INTO acl_user (name, description, md5_password, extension) VALUES (:n, :d, :p, :x)")
                ->execute([':n' => $login, ':d' => $u['name'], ':p' => $password !== '' ? md5($password) : md5(bin2hex(random_bytes(16))), ':x' => $ext]);
            $aid = (int)$acl->lastInsertId();
        } else {
            $acl->prepare("UPDATE acl_user SET description = :d, extension = :x WHERE id = :i")->execute([':d' => $u['name'], ':x' => $ext, ':i' => $aid]);
            if ($password !== '') $acl->prepare("UPDATE acl_user SET md5_password = :p WHERE id = :i")->execute([':p' => md5($password), ':i' => $aid]);
        }
        if ($login !== 'admin') {
            $g = $acl->prepare("SELECT id FROM acl_group WHERE LOWER(name) = :g"); $g->execute([':g' => aclGroupForRole($u['role'])]);
            $gid = (int)$g->fetchColumn();
            if ($gid) {
                $acl->prepare("DELETE FROM acl_membership WHERE id_user = :i")->execute([':i' => $aid]);
                $acl->prepare("INSERT INTO acl_membership (id_user, id_group) VALUES (:i, :g)")->execute([':i' => $aid, ':g' => $gid]);
            }
        }
        $acl->commit();
        if ($isNew || ($u['issabel_user'] ?? '') === '') $db->prepare("UPDATE system_users SET issabel_user = :l WHERE id = :i")->execute([':l' => $login, ':i' => (int)$rowId]);
        return ['ok' => true, 'login' => $login, 'error' => ''];
    } catch (Exception $e) {
        try { $acl->rollBack(); } catch (Exception $e2) {}
        return ['ok' => false, 'login' => '', 'error' => 'falha ao gravar na central'];
    }
}

/** Remove da central o usuário vinculado (o 'admin' nunca é removido). */
function aclRemoveUser($login) {
    $login = trim((string)$login);
    if ($login === '' || $login === 'admin') return false;
    $acl = aclOpenRW();
    if (!$acl) return false;
    try {
        $q = $acl->prepare("SELECT id FROM acl_user WHERE name = :n"); $q->execute([':n' => $login]);
        $aid = (int)$q->fetchColumn();
        if (!$aid) return true;
        $acl->beginTransaction();
        $acl->prepare("DELETE FROM acl_membership WHERE id_user = :i")->execute([':i' => $aid]);
        $acl->prepare("DELETE FROM acl_user WHERE id = :i")->execute([':i' => $aid]);
        $acl->commit();
        return true;
    } catch (Exception $e) { return false; }
}
