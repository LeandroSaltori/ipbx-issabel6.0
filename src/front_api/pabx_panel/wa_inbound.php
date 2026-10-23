<?php
/**
 * IPbx Prisma - Recebe as mensagens do WhatsApp para o Assistente por WhatsApp.
 * Endereço protegido por token secreto (?t=...). Responde 200 na hora e processa em seguida.
 */
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/functions.php';
header('Content-Type: application/json; charset=utf-8');
$t = (string)($_GET['t'] ?? '');
if ($t === '' || !hash_equals(waAsstSecret(), $t)) { http_response_code(403); echo json_encode(['ok' => false]); exit; }
$raw = file_get_contents('php://input');
$p = json_decode((string)$raw, true);
if (!is_array($p)) { http_response_code(400); echo json_encode(['ok' => false]); exit; }
// Guarda os últimos avisos recebidos para diagnóstico (aparecem na tela do assistente)
try { waAsstEnsure(); global $db; $db->prepare("INSERT INTO wa_asst_raw (body) VALUES (?)")->execute([mb_substr($raw, 0, 3000)]); $db->exec("DELETE FROM wa_asst_raw WHERE id < (SELECT MAX(id) FROM wa_asst_raw) - 10"); } catch (Throwable $e) {}
echo json_encode(['ok' => true]);
if (function_exists('session_status') && session_status() === PHP_SESSION_ACTIVE) @session_write_close();
if (function_exists('fastcgi_finish_request')) fastcgi_finish_request(); else { @ob_end_flush(); @flush(); }
@ignore_user_abort(true); @set_time_limit(120);
try { waAsstHandle($p); } catch (Throwable $e) { if (function_exists('pabx_log')) pabx_log('whatsapp', 'ERROR', 'Assistente WhatsApp: ' . $e->getMessage()); }
