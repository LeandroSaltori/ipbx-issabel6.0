<?php
/**
 * IPbx Prisma - API Endpoint para Sincronização em Tempo Real da Agenda
 */
header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/address_book_sync.php';
authGate('json');

$action = $_REQUEST['action'] ?? 'sync';

if ($action === 'list' || $action === 'sync' || $action === 'webhook_sync') {
    [$fw, $fp] = crmContactsFilter($_REQUEST['q'] ?? '', $_REQUEST['tag'] ?? '');
    $stmt = $db->prepare("SELECT * FROM crm_contacts" . $fw . " ORDER BY name COLLATE NOCASE ASC");
    $stmt->execute($fp);
    $contacts = $stmt->fetchAll(PDO::FETCH_ASSOC);
    echo json_encode([
        'status' => 'success',
        'count' => count($contacts),
        'contacts' => $contacts
    ]);
    exit;
}

echo json_encode(['status' => 'success', 'message' => 'API Ativa']);
exit;
