#!/usr/bin/php -q
<?php
/**
 * IPbx Prisma - Wrapper AGI (caminho estável usado pelo dialplan do Asterisk)
 *
 * O dialplan injetado em extensions_custom.conf chama
 * /var/www/html/front_api/whatsapp_agi.php. A lógica real fica em
 * pabx_panel/whatsapp_agi.php; este arquivo só a encaminha, mantendo
 * compatibilidade com instalações já existentes.
 */
$real_agi = __DIR__ . '/pabx_panel/whatsapp_agi.php';
if (!is_readable($real_agi)) {
    fwrite(STDOUT, "VERBOSE \"whatsapp_agi: pabx_panel/whatsapp_agi.php nao encontrado\" 1\n");
    exit(1);
}
// O arquivo real não deve imprimir nada no stdout do AGI (shebang etc.)
ob_start();
require $real_agi;
ob_end_clean();
