<?php
/**
 * IPbx Prisma - API Endpoint para Sincronização em Tempo Real da Agenda
 */
header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/address_book_sync.php';

$action = $_REQUEST['action'] ?? 'sync';

if ($action === 'list' || $action === 'sync' || $action === 'webhook_sync') {
    $stmt = $db->query("SELECT * FROM crm_contacts ORDER BY name ASC");
    $contacts = $stmt ? $stmt->fetchAll(PDO::FETCH_ASSOC) : [];
    echo json_encode([
        'status' => 'success',
        'count' => count($contacts),
        'contacts' => $contacts
    ]);
    exit;
}

echo json_encode(['status' => 'success', 'message' => 'API Ativa']);
exit;
