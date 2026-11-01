<?php
/** Chamado pelo instalador: cria (uma vez) o índice que acelera os relatórios de chamadas. Nunca imprime senhas. */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/cdr_index.php';
$c = function_exists('getAsteriskPdoConnection') ? @getAsteriskPdoConnection('asteriskcdrdb') : null;
echo " [+] " . ensureCdrIndex($c) . "\n";
exit(0);
