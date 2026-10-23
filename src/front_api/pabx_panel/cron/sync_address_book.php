<?php
/**
 * IPbx Prisma - Cron: mantém a Agenda do painel novo igual à do painel clássico (nos dois sentidos).
 */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once __DIR__ . '/../includes/functions.php';
cronHeartbeat('sync_address_book');
$r = AddressBookSync::reconcile($db, true);
echo $r['ok'] ? "Agenda sincronizada ({$r['changes']} alterações).\n" : ("Falha: {$r['error']}\n");
