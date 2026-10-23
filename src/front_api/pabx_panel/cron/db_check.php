<?php
/** Teste rápido do MySQL para o instalador: imprime "MySQL OK" ou "Atenção" com o motivo. Nunca imprime senhas. */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once __DIR__ . '/../includes/functions.php';
$d = dbDiagnose();
foreach ($d['linhas'] as $l) echo "    $l\n";
echo $d['ok'] ? " [✓] MySQL OK: o painel acessa os bancos da central sozinho.\n" : " [!] Atenção: " . $d['dica'] . "\n";
exit(0);
