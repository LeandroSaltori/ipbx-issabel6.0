<?php
/**
 * IPbx Prisma - Webphone (WebRTC) do painel: widget flutuante
 *
 * GET  ?js=1       -> script do widget (webphone_widget.js); só é entregue a quem tem o Webphone habilitado
 * POST ?cred=1     -> JSON com o ramal e a senha do PRÓPRIO usuário logado (nunca de outro) para registrar no PABX.
 *                    A senha vem do IssabelPBX (não é guardada no painel) e só sai se o webphone estiver habilitado
 *                    para o usuário em Configurações > Usuários.
 */
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/functions.php';

// ---------- Script do widget ----------
if (isset($_GET['js'])) {
    authGate('plain');
    $u = getLoggedUser();
    header('Content-Type: application/javascript; charset=utf-8');
    header('Cache-Control: no-cache');
    if ($u && !empty($u['webphone']) && !empty($u['extension'])) readfile(__DIR__ . '/webphone_widget.js');
    exit;
}

// ---------- Credenciais (JSON) ----------
if (isset($_GET['cred'])) {
    authGate('json');
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    if ($_SERVER['REQUEST_METHOD'] !== 'POST' || ($_SERVER['HTTP_X_WEBPHONE'] ?? '') !== '1') {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Requisição inválida.']);
        exit;
    }
    $u = getLoggedUser();
    $ext = preg_replace('/[^0-9A-Za-z_\-]/', '', (string)($u['extension'] ?? ''));
    if (!$u || empty($u['webphone']) || $ext === '') {
        echo json_encode(['success' => false, 'error' => 'O Webphone não está habilitado para o seu usuário. Peça ao administrador em Configurações > Usuários.']);
        exit;
    }
    $secret = ''; $webrtc = false;
    try {
        $ast = getAsteriskDBConnection();
        if ($ast) {
            foreach (['pjsip', 'sip'] as $tbl) {
                try {
                    $st = $ast->prepare("SELECT keyword, value FROM $tbl WHERE id = :id AND keyword IN ('secret','webrtc','transport','media_encryption','dtlsenable')");
                    $st->execute([':id' => $ext]);
                    while ($r = $st->fetch(PDO::FETCH_ASSOC)) {
                        $k = strtolower($r['keyword']); $v = strtolower(trim((string)$r['value']));
                        if ($k === 'secret' && $secret === '') $secret = (string)$r['value'];
                        if (($k === 'webrtc' && $v === 'yes') || ($k === 'transport' && strpos($v, 'ws') !== false)
                            || ($k === 'media_encryption' && $v === 'dtls') || ($k === 'dtlsenable' && $v === 'yes')) $webrtc = true;
                    }
                } catch (Exception $e) { /* tabela inexistente nesta instalação */ }
            }
        }
    } catch (Exception $e) {}
    if ($secret === '') {
        echo json_encode(['success' => false, 'error' => "Não foi possível ler a senha do ramal $ext no PABX."]);
        exit;
    }
    $host = explode(':', (string)($_SERVER['HTTP_HOST'] ?? ''))[0];
    $stun = trim((string)getSetting('webphone_stun'));
    echo json_encode([
        'success' => true, 'ext' => $ext, 'secret' => $secret, 'name' => (string)($u['name'] ?? ''),
        'wss' => 'wss://' . $host . ':8089/ws', 'host' => $host, 'webrtc' => $webrtc, 'stun' => $stun,
    ]);
    exit;
}

// ---------- Qualquer outro acesso direto: volta ao painel (o Webphone é o widget flutuante) ----------
header('Location: index.php');
exit;
