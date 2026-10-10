<?php
/**
 * IPbx Prisma x Prismabot - Painel Integrado de Operações & Telefonia v6.1
 * Entrypoint Principal do PABX
 */

if (file_exists(__DIR__ . '/pabx_panel/index.php')) {
    require_once __DIR__ . '/pabx_panel/index.php';
} else {
    die("Erro: Painel PABX não encontrado no diretório pabx_panel/");
}
