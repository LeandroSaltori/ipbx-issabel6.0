<?php
/**
 * IPbx Prisma - Provisionamento automático de telefones IP por MAC (Yealink / Grandstream).
 * URL no telefone: http://SERVIDOR/front_api/pabx_panel/provisioning.php/TOKEN/   (o aparelho pede <mac>.cfg ou cfg<mac>.xml)
 * Protegido por token; só entrega configuração de MACs cadastrados. Não altera nada no Issabel.
 */
while (ob_get_level()) { @ob_end_clean(); }
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/functions.php';
header('Content-Type: text/plain; charset=utf-8');
$pi = trim($_SERVER['PATH_INFO'] ?? '', '/');
$parts = explode('/', $pi);
$tok = $parts[0] ?? '';
$file = strtolower(end($parts));
if (!hash_equals(provToken(), $tok)) { http_response_code(403); echo "Forbidden"; exit; }
if (!preg_match('/([0-9a-f]{12})/', str_replace([':', '-'], '', $file), $m)) { http_response_code(404); echo "Not found"; exit; }
provEnsureTable();
$st = $db->prepare("SELECT * FROM prov_devices WHERE mac = ?");
$st->execute([$m[1]]);
$d = $st->fetch(PDO::FETCH_ASSOC);
if (!$d) { http_response_code(404); echo "Not found"; exit; }
$secret = provExtensionSecret($d['ext']);
if ($secret === '') { http_response_code(500); echo "Ramal sem senha"; exit; }
$host = getSetting('prov_server') ?: ($_SERVER['SERVER_ADDR'] ?? $_SERVER['HTTP_HOST'] ?? '');
$db->prepare("UPDATE prov_devices SET last_fetch = ? WHERE mac = ?")->execute([date('Y-m-d H:i:s'), $d['mac']]);
echo provRenderConfig($d['vendor'], $d['ext'], $d['label'], $secret, $host);
