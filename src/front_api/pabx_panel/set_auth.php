#!/usr/bin/php -q
<?php
/**
 * IPbx Prisma - Liga/desliga o login do painel (somente CLI).
 *   php set_auth.php off      -> painel abre direto, sem senha (apenas para testes em rede confiável)
 *   php set_auth.php on       -> volta a exigir login
 *   php set_auth.php status
 */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/functions.php';

$cmd = strtolower($argv[1] ?? 'status');
if ($cmd === 'off') {
    saveSetting('auth_disabled', '1');
    echo "[!] Login DESLIGADO: qualquer pessoa com acesso à rede abre o painel como Administrador.\n    Para religar: php set_auth.php on\n";
} elseif ($cmd === 'on') {
    saveSetting('auth_disabled', '0');
    echo "[+] Login LIGADO. Se não lembrar a senha: php set_password.php email@dominio.com 'NovaSenha'\n";
} else {
    echo 'Login: ' . (authDisabled() ? "DESLIGADO (painel aberto)\n" : "ligado\n");
}
