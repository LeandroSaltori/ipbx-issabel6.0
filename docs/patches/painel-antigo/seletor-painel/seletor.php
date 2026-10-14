<?php
/**
 * IPbx Prisma - Seletor de painel (Painel Novo 6.0 / Painel Clássico).
 * Arquivo NOVO: copiar para /var/www/html/seletor.php. Não altera nenhum arquivo existente.
 * Usa a sessão e a base de acessos do painel clássico; não lê nem copia senhas.
 */
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
ini_set('include_path', dirname($_SERVER['SCRIPT_FILENAME']) . "/libs:" . ini_get('include_path'));
include_once "libs/misc.lib.php";
include_once "configs/default.conf.php";
include_once "libs/paloSantoDB.class.php";
include_once "libs/paloSantoACL.class.php";
session_name("issabelSession");
session_start();

$pdbACL = new paloDB($arrConf['issabel_dsn']['acl']);
$pACL = new paloACL($pdbACL);
if (empty($_SESSION['issabel_user']) || empty($_SESSION['issabel_pass']) || !$pACL->authenticateUser($_SESSION['issabel_user'], $_SESSION['issabel_pass'])) {
    header('Location: index.php'); exit;
}
$user = $_SESSION['issabel_user'];

function seletorKey() { $k = @file_get_contents('/etc/ipbx/sso.key'); $k = is_string($k) ? trim($k) : ''; return strlen($k) >= 32 ? $k : ''; }
function b64u($s) { return rtrim(strtr(base64_encode($s), '+/', '-_'), '='); }

if (($_GET['ir'] ?? '') === 'novo' || ($_GET['ir'] ?? '') === 'classico') {
    // vale só enquanto este login estiver ativo (nada fica gravado no navegador)
    $_SESSION['ipbx_painel'] = $_GET['ir'];
    if (isset($_COOKIE['ipbx_painel'])) setcookie('ipbx_painel', '', time() - 3600, '/');
    if ($_GET['ir'] === 'classico') { header('Location: index.php?classico=1'); exit; }
    $key = seletorKey();
    if ($key === '') { $erro = 'Painel novo indisponível: entrada única não configurada neste servidor.'; }
    else {
        $idUser = $pACL->getIdUser($user);
        $groups = [];
        if ($idUser !== FALSE) { $m = $pACL->getMembership($idUser); if (is_array($m)) $groups = array_keys($m); }
        $info = ($idUser !== FALSE) ? $pACL->getUsers($idUser) : null;
        $row = (is_array($info) && isset($info[0])) ? $info[0] : [];
        $payload = ['u' => $user, 'g' => $groups, 'n' => (!empty($row[2]) ? $row[2] : $user), 'x' => $row[3] ?? '', 'e' => time() + 45, 'j' => bin2hex(random_bytes(12))];
        $p = b64u(json_encode($payload));
        header('Location: front_api/index.php?sso=' . $p . '.' . b64u(hash_hmac('sha256', $p, $key, true))); exit;
    }
}
$h = function ($s) { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); };
?><!doctype html>
<html lang="pt-BR"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>Escolha o painel</title>
<style>
body{margin:0;min-height:100vh;display:flex;align-items:center;justify-content:center;background:#0f172a;color:#e2e8f0;font-family:'Segoe UI',system-ui,sans-serif}
.w{max-width:760px;width:100%;padding:24px;text-align:center}
h1{font-size:22px;margin:0 0 6px}p{color:#94a3b8;margin:0 0 24px}
.g{display:grid;grid-template-columns:repeat(auto-fit,minmax(260px,1fr));gap:16px}
a.c{display:block;text-decoration:none;color:inherit;background:#1c1b2f;border:1px solid #334155;border-radius:20px;padding:28px 20px;transition:.2s}
a.c:hover{border-color:#7c3aed;transform:translateY(-2px);box-shadow:0 10px 30px rgba(99,102,241,.25)}
a.n{background:linear-gradient(135deg,#252440,#1c1b2f)}
.t{font-weight:800;font-size:18px;margin-bottom:6px}.d{color:#94a3b8;font-size:13px}
label{display:block;margin-top:20px;color:#94a3b8;font-size:13px}.e{color:#fca5a5;margin-bottom:14px}
</style></head><body><div class="w">
<h1>Olá, <?php echo $h($user); ?></h1><p>Escolha qual painel deseja abrir.</p>
<?php if (!empty($erro)) echo '<div class="e">' . $h($erro) . '</div>'; ?>
<div class="g">
<a class="c n" id="novo" href="?ir=novo"><div class="t">Painel Novo 6.0</div><div class="d">Dashboard, filas ao vivo, relatórios, WhatsApp e assistente inteligente.</div></a>
<a class="c" id="classico" href="?ir=classico"><div class="t">Painel Clássico</div><div class="d">Todas as configurações da central, como sempre.</div></a>
</div>
</div>
</body></html>
