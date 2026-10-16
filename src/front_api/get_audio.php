<?php
/**
 * IPbx Prisma - Atalho do servidor de áudio (gravações e anúncios)
 * Os relatórios chamam get_audio.php a partir de /front_api/; o arquivo real fica em pabx_panel/.
 */
if (file_exists(__DIR__ . '/pabx_panel/get_audio.php')) {
    require __DIR__ . '/pabx_panel/get_audio.php';
} else {
    http_response_code(404);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'Servidor de áudio não encontrado.';
}
