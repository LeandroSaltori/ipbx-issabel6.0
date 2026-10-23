<?php
/**
 * IPbx Prisma - Assistente por WhatsApp.
 * O gestor conversa com o número conectado do painel e o mesmo assistente do Chat IA responde com dados reais.
 * Segurança: só responde a números da LISTA PERMITIDA, URL do webhook com token secreto, ações só com resposta SIM
 * (válida por 10 minutos), limite de mensagens por hora, chave liga/desliga e registro de tudo.
 */

function waAsstEnsure() {
    global $db;
    $db->exec("CREATE TABLE IF NOT EXISTS wa_asst_log (id INTEGER PRIMARY KEY AUTOINCREMENT, at DATETIME DEFAULT CURRENT_TIMESTAMP, phone TEXT, dir TEXT, text TEXT, status TEXT, msg_id TEXT)");
    $db->exec("CREATE TABLE IF NOT EXISTS wa_asst_raw (id INTEGER PRIMARY KEY AUTOINCREMENT, at DATETIME DEFAULT CURRENT_TIMESTAMP, body TEXT)");
}
function waAsstSecret() {
    $s = trim((string)getSetting('wa_asst_secret'));
    if ($s === '') { $s = bin2hex(random_bytes(16)); saveSetting('wa_asst_secret', $s); }
    return $s;
}
function waAsstEnabled() { return getSetting('wa_asst_enabled') === '1'; }
function waAsstBase() { return rtrim((string)getSetting('public_base_url'), '/'); }
/** Endereço que a Prisma/UAZAPI chama quando chega mensagem. '' se a URL pública do painel não estiver cadastrada. */
function waAsstWebhookUrl() {
    $b = waAsstBase();
    return $b === '' ? '' : $b . '/pabx_panel/wa_inbound.php?t=' . waAsstSecret();
}
function waAsstNorm($p) {
    $d = preg_replace('/\D/', '', (string)$p);
    if (strlen($d) >= 12 && substr($d, 0, 2) === '55') $d = substr($d, 2);
    return $d;
}
/** Compara ignorando DDI e o 9º dígito (celulares antigos e novos). */
function waAsstSameNumber($a, $b) {
    $a = waAsstNorm($a); $b = waAsstNorm($b);
    if ($a === '' || $b === '') return false;
    $strip9 = function ($n) { return (strlen($n) === 11 && $n[2] === '9') ? substr($n, 0, 2) . substr($n, 3) : $n; };
    return $strip9($a) === $strip9($b);
}
function waAsstAllowed($phone) {
    foreach (preg_split('/[,;\n]+/', (string)getSetting('wa_asst_allowed'), -1, PREG_SPLIT_NO_EMPTY) as $n) if (waAsstSameNumber($n, $phone)) return true;
    return false;
}
function waAsstLog($phone, $dir, $text, $status, $msgId = '') {
    global $db; waAsstEnsure();
    $db->prepare("INSERT INTO wa_asst_log (phone, dir, text, status, msg_id) VALUES (?,?,?,?,?)")->execute([$phone, $dir, mb_substr((string)$text, 0, 2000), $status, $msgId]);
    $db->exec("DELETE FROM wa_asst_log WHERE id < (SELECT MAX(id) FROM wa_asst_log) - 500");
    panelRevBump('wa_asst');
}

/** Extrai o essencial do aviso de mensagem, aceitando os formatos conhecidos do servidor de WhatsApp. */
function waAsstParse($p) {
    $m = is_array($p['message'] ?? null) ? $p['message'] : (is_array($p['data'] ?? null) ? $p['data'] : $p);
    $type = strtolower((string)($m['messageType'] ?? $m['type'] ?? $m['mediaType'] ?? ''));
    $text = '';
    foreach (['text', 'body', 'conversation'] as $k) if (is_string($m[$k] ?? null) && $m[$k] !== '') { $text = $m[$k]; break; }
    if ($text === '' && is_string($m['content'] ?? null)) $text = $m['content'];
    if ($text === '' && is_array($m['content'] ?? null)) $text = (string)($m['content']['text'] ?? $m['content']['conversation'] ?? '');
    $chat = (string)($m['chatid'] ?? $m['chatId'] ?? $p['chat']['wa_chatid'] ?? $p['chat']['id'] ?? $m['from'] ?? $m['sender'] ?? $m['sender_pn'] ?? '');
    $sender = (string)($m['sender_pn'] ?? $m['sender'] ?? $m['from'] ?? $chat);
    $isAudio = (strpos($type, 'audio') !== false || strpos($type, 'ptt') !== false || strpos($type, 'voice') !== false);
    return [
        'id'      => (string)($m['messageid'] ?? $m['id'] ?? $m['key']['id'] ?? ''),
        'phone'   => preg_replace('/@.*/', '', $sender !== '' ? $sender : $chat),
        'chat'    => $chat,
        'text'    => trim($text),
        'audio'   => $isAudio,
        'fromMe'  => !empty($m['fromMe']) || !empty($m['key']['fromMe']) || !empty($m['wasSentByApi']),
        'group'   => !empty($m['isGroup']) || (strpos($chat, '@g.us') !== false),
        'event'   => (string)($p['EventType'] ?? $p['event'] ?? $p['type'] ?? ''),
        'fileUrl' => (string)($m['fileURL'] ?? $m['fileUrl'] ?? $m['mediaUrl'] ?? $m['url'] ?? ''),
    ];
}

/** Baixa o áudio recebido e transcreve em português. Retorna ['ok','text','error']. */
function waAsstTranscribe($msg) {
    $tmp = sys_get_temp_dir() . '/wa_asst_' . bin2hex(random_bytes(6)) . '.ogg';
    $got = false;
    if ($msg['fileUrl'] !== '' && preg_match('#^https?://#i', $msg['fileUrl'])) {
        $ch = curl_init($msg['fileUrl']); curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 30, CURLOPT_FOLLOWLOCATION => true, CURLOPT_SSL_VERIFYPEER => false]);
        $bin = curl_exec($ch); $http = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE); curl_close($ch);
        if ($bin !== false && $http >= 200 && $http < 300 && strlen($bin) > 200) { file_put_contents($tmp, $bin); $got = true; }
    }
    if (!$got && $msg['id'] !== '') {
        $r = uazapiRequest('POST', '/message/download', ['id' => $msg['id'], 'return_base64' => true, 'return_link' => true], 30);
        $d = json_decode($r['resp'], true);
        if (is_array($d)) {
            $b64 = (string)($d['base64Data'] ?? $d['base64'] ?? '');
            $lnk = (string)($d['fileURL'] ?? $d['fileUrl'] ?? '');
            if ($b64 !== '') { $bin = base64_decode(preg_replace('#^data:[^,]+,#', '', $b64), true); if ($bin) { file_put_contents($tmp, $bin); $got = true; } }
            elseif ($lnk !== '') { $bin = @file_get_contents($lnk); if ($bin) { file_put_contents($tmp, $bin); $got = true; } }
        }
    }
    if (!$got) return ['ok' => false, 'text' => '', 'error' => 'não consegui baixar o áudio'];
    $r = aiTranscribeFile($tmp);
    @unlink($tmp);
    return ['ok' => !empty($r['ok']) && trim((string)$r['text']) !== '', 'text' => trim((string)($r['text'] ?? '')), 'error' => $r['error'] ?? 'transcrição vazia'];
}

function waAsstReply($phone, $text) {
    $cap = max(10, (int)(getSetting('wa_asst_cap') ?: 100));
    global $db; waAsstEnsure();
    $n = (int)$db->query("SELECT COUNT(*) FROM wa_asst_log WHERE dir = 'out' AND status = 'ok' AND at >= datetime('now','-1 day')")->fetchColumn();
    if ($n >= $cap) { waAsstLog($phone, 'out', $text, 'limite diário', ''); return false; }
    $r = sendWhatsAppAPI($phone, $text, 'ASSISTENTE_WA', 'GERAL', 'ASSISTENTE_WA');
    waAsstLog($phone, 'out', $text, !empty($r['success']) ? 'ok' : 'falhou');
    return !empty($r['success']);
}

/** Processa uma mensagem recebida. Devolve o motivo (para teste/diagnóstico). */
function waAsstHandle($payload) {
    global $db; waAsstEnsure();
    $m = waAsstParse($payload);
    if (!waAsstEnabled()) return 'desligado';
    if ($m['fromMe'] || $m['group'] || $m['phone'] === '') return 'ignorado';
    if ($m['event'] !== '' && stripos($m['event'], 'message') === false) return 'evento ignorado';
    if (!waAsstAllowed($m['phone'])) { waAsstLog($m['phone'], 'in', '(mensagem de número fora da lista permitida; sem resposta)', 'ignorado', $m['id']); return 'número não permitido'; }
    if ($m['id'] !== '') {
        $d = $db->prepare("SELECT 1 FROM wa_asst_log WHERE msg_id = ? AND dir = 'in' LIMIT 1"); $d->execute([$m['id']]);
        if ($d->fetchColumn()) return 'duplicada';
    }
    // Limite por hora: protege contra loop e abuso
    $hr = (int)$db->query("SELECT COUNT(*) FROM wa_asst_log WHERE dir = 'in' AND status <> 'ignorado' AND at >= datetime('now','-1 hour')")->fetchColumn();
    if ($hr >= 40) { waAsstLog($m['phone'], 'in', $m['text'], 'limite por hora', $m['id']); return 'limite por hora'; }

    $text = $m['text'];
    if ($m['audio']) {
        $t = waAsstTranscribe($m);
        if (!$t['ok']) { waAsstLog($m['phone'], 'in', '(áudio)', 'áudio falhou: ' . $t['error'], $m['id']); waAsstReply($m['phone'], 'Não consegui entender o áudio (' . $t['error'] . '). Pode escrever a pergunta?'); return 'áudio falhou'; }
        $text = $t['text'];
    }
    if ($text === '') { waAsstLog($m['phone'], 'in', '(sem texto)', 'ignorado', $m['id']); return 'sem texto'; }
    waAsstLog($m['phone'], 'in', ($m['audio'] ? '🎙️ ' : '') . $text, 'recebida', $m['id']);

    // Contexto do assistente: este número, com permissão de gestor (a lista permitida é o controle de acesso)
    $GLOBALS['__wa_assistant_user'] = ['id' => 0, 'name' => 'WhatsApp ' . $m['phone'], 'username' => 'wa:' . waAsstNorm($m['phone']), 'email' => '', 'role' => 'Administrador', 'extension' => '', 'whatsapp' => $m['phone'], 'permissions_list' => [], 'status' => 'Ativo'];
    $user = $GLOBALS['__wa_assistant_user']['username'];
    $low = mb_strtolower(trim(preg_replace('/[^\p{L}\p{N} ]/u', '', $text)));

    // Confirmação de ações
    $pend = $db->prepare("SELECT id, resumo FROM ai_pending_actions WHERE username = ? AND status = 'pending' AND created_at >= ? ORDER BY created_at ASC");
    $pend->execute([$user, time() - 600]); $pending = $pend->fetchAll(PDO::FETCH_ASSOC);
    $yes = preg_match('/^(sim|s|confirmo|confirmar|pode|ok)( (\d+))?$/u', $low, $my);
    $no  = preg_match('/^(nao|não|n|cancelar|cancela)( (\d+))?$/u', $low, $mn);
    if (($yes || $no) && $pending) {
        $idx = $yes ? ($my[3] ?? '') : ($mn[3] ?? '');
        if (count($pending) > 1 && $idx === '') { $l = []; foreach ($pending as $i => $p) $l[] = ($i + 1) . ') ' . $p['resumo']; waAsstReply($m['phone'], "Há mais de uma ação aguardando:\n" . implode("\n", $l) . "\nResponda SIM 1 (ou NÃO 1), SIM 2…"); return 'várias pendentes'; }
        $pick = $idx === '' ? $pending[0] : ($pending[(int)$idx - 1] ?? null);
        if (!$pick) { waAsstReply($m['phone'], 'Não encontrei essa ação. Peça novamente.'); return 'ação inexistente'; }
        $r = aiProposalResolve($pick['id'], (bool)$yes);
        waAsstReply($m['phone'], !empty($r['success']) ? ($r['message'] ?? 'Feito.') : ('Não foi possível: ' . ($r['error'] ?? 'erro')));
        return $yes ? 'confirmada' : 'cancelada';
    }

    // Conversa com o assistente (histórico curto deste número)
    $h = $db->prepare("SELECT dir, text FROM wa_asst_log WHERE phone = ? AND status IN ('recebida','ok') AND at >= datetime('now','-30 minutes') ORDER BY id DESC LIMIT 8");
    $h->execute([$m['phone']]); $rows = array_reverse($h->fetchAll(PDO::FETCH_ASSOC));
    $hist = []; foreach ($rows as $r) $hist[] = ['role' => $r['dir'] === 'in' ? 'user' : 'assistant', 'content' => preg_replace('/^🎙️ /u', '', $r['text'])];
    if (!$hist || end($hist)['role'] !== 'user') $hist[] = ['role' => 'user', 'content' => $text];
    $GLOBALS['__ai_proposals'] = [];
    $res = aiChatRun($hist);
    if (empty($res['ok'])) { waAsstReply($m['phone'], 'Não consegui responder agora: ' . ($res['error'] ?? 'erro')); return 'ia falhou'; }
    $out = $res['text'];
    if (!empty($GLOBALS['__ai_proposals'])) {
        $n = count($GLOBALS['__ai_proposals']);
        $out .= "\n\n" . ($n === 1 ? "Para confirmar: *" . $GLOBALS['__ai_proposals'][0]['resumo'] . "*\nResponda *SIM* para aplicar ou *NÃO* para cancelar (vale por 10 minutos)."
                                  : "Ações aguardando confirmação:\n" . implode("\n", array_map(function ($p, $i) { return ($i + 1) . ') ' . $p['resumo']; }, $GLOBALS['__ai_proposals'], array_keys($GLOBALS['__ai_proposals']))) . "\nResponda *SIM 1*, *SIM 2*… ou *NÃO 1* (vale por 10 minutos).");
    }
    waAsstReply($m['phone'], $out);
    aiChatLog($text, $res['tools'] ?? [], true);
    return 'respondida';
}

/** Cadastra o aviso de mensagem recebida no servidor de WhatsApp, sem apagar outro webhook já existente. */
function waAsstRegister($force = false) {
    $url = waAsstWebhookUrl();
    if ($url === '') return ['success' => false, 'error' => 'Cadastre a "URL pública do painel" em Configurações > API antes de ativar o assistente.'];
    if (waProvider() !== 'uazapi') return ['success' => false, 'error' => 'O assistente por WhatsApp precisa do WhatsApp direto (QR Code) conectado em Configurações > API.'];
    $cur = uazapiRequest('GET', '/webhook', null, 10);
    $d = json_decode($cur['resp'], true);
    $list = is_array($d) ? (isset($d[0]) || empty($d) ? $d : [$d]) : [];
    foreach ($list as $w) {
        $u = is_array($w) ? (string)($w['url'] ?? '') : '';
        if ($u !== '' && strpos($u, 'wa_inbound.php') === false && !$force) {
            return ['success' => false, 'conflict' => true, 'error' => 'Já existe outro aviso de mensagens configurado neste WhatsApp (' . parse_url($u, PHP_URL_HOST) . '). Para não derrubar essa integração, o painel não substituiu. Se quiser usar este assistente no lugar dela, confirme em "Substituir".'];
        }
    }
    $r = uazapiRequest('POST', '/webhook', ['enabled' => true, 'url' => $url, 'events' => ['messages'], 'excludeMessages' => ['wasSentByApi']], 15);
    $ok = $r['error'] === '' && $r['http'] >= 200 && $r['http'] < 300;
    if ($ok) saveSetting('wa_asst_registered', date('Y-m-d H:i:s'));
    return ['success' => $ok, 'error' => $ok ? '' : ($r['error'] ?: ('O servidor de WhatsApp respondeu HTTP ' . $r['http'] . '.'))];
}
function waAsstUnregister() {
    $r = uazapiRequest('POST', '/webhook', ['enabled' => false, 'url' => waAsstWebhookUrl(), 'events' => ['messages']], 15);
    saveSetting('wa_asst_registered', '');
    return ['success' => $r['error'] === '' && $r['http'] >= 200 && $r['http'] < 300, 'error' => $r['error']];
}
