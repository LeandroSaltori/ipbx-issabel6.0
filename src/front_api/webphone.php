<?php
/**
 * Compatibilidade: /front_api/webphone.php -> pabx_panel/webphone.php (com autenticação)
 * O painel é servido por /front_api/index.php; a janela do Webphone usa o mesmo nível de endereço.
 */
require __DIR__ . '/pabx_panel/webphone.php';
