<?php
/**
 * IPbx Prisma - Atalho do recebimento de mensagens do Assistente WhatsApp (o arquivo real fica em pabx_panel/).
 */
if (file_exists(__DIR__ . '/pabx_panel/wa_inbound.php')) {
    require __DIR__ . '/pabx_panel/wa_inbound.php';
} else {
    http_response_code(404);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'Não encontrado.';
}
