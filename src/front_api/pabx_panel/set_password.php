#!/usr/bin/php -q
<?php
/**
 * IPbx Prisma - Define/redefine a senha de um usuário do painel (somente CLI).
 *
 * Uso:
 *   php set_password.php                      -> se ninguém tem senha, gera uma senha aleatória para o 1º Administrador
 *   php set_password.php email@dominio.com    -> gera senha aleatória para o usuário
 *   php set_password.php email@dominio.com 'NovaSenha123'
 */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/functions.php';

$email = $argv[1] ?? '';
$pass  = $argv[2] ?? '';
$init  = ($email === '' );

if ($init && authHasAnyPassword()) {
    echo "[*] Já existe senha definida; nada a fazer. Para redefinir: php set_password.php email [senha]\n";
    exit(0);
}

if ($email === '') {
    $row = $db->query("SELECT id, email FROM system_users WHERE role = 'Administrador' AND status = 'Ativo' ORDER BY id ASC LIMIT 1")->fetch(PDO::FETCH_ASSOC);
} else {
    $st = $db->prepare("SELECT id, email FROM system_users WHERE lower(email) = lower(:e) LIMIT 1");
    $st->execute([':e' => $email]);
    $row = $st->fetch(PDO::FETCH_ASSOC);
}
if (!$row) { fwrite(STDERR, "[-] Usuário não encontrado.\n"); exit(1); }

$generated = false;
if ($pass === '') { $pass = substr(strtr(base64_encode(random_bytes(12)), '+/=', 'xyz'), 0, 14); $generated = true; }

$res = authSetPassword($row['id'], $pass);
if (!$res['success']) { fwrite(STDERR, "[-] " . $res['error'] . "\n"); exit(1); }

echo "[+] Senha definida para {$row['email']}\n";
if ($generated) echo "    Senha gerada: {$pass}\n    (anote agora; ela não é exibida novamente)\n";
exit(0);
