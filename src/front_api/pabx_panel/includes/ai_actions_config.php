<?php
/**
 * IPbx Prisma - Chat IA: configuração por conversa (contatos, mensagens de aviso/URA e abandono de fila).
 * Cada ação só PROPÕE; quem aplica é aiApplyAction() após o Confirmar do usuário. Usa as mesmas tabelas e
 * o mesmo caminho das telas Agenda & Contatos e Regras URA & Transbordo, então tudo reflete nelas em tempo real.
 */

/** Mensagens de aviso editáveis (mesmas chaves da tela Regras URA & Transbordo). */
function aiRuleCatalog() {
    return [
        'queue_abandon_msg_default' => 'Aviso ao cliente que desistiu na fila',
        'queue_exit_whatsapp_msg'   => 'Mensagem de transbordo para WhatsApp (cliente saiu da fila para o WhatsApp)',
        'missed_agent_msg'          => 'Aviso ao atendente de chamada perdida',
        'missed_client_msg'         => 'Mensagem ao cliente após a chamada',
        'audio_msg'                 => 'Envio da gravação ao cliente',
    ];
}
function aiRuleVars() { return ['{ATENDENTE}', '{CLIENTE}', '{DATA_HORA}', '{NOME_FILA}', '{RAMAL}', '{LINK_GRAVACAO}']; }

function aiContactsEnsure() {
    global $db;
    $db->exec("CREATE TABLE IF NOT EXISTS crm_contacts (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT NOT NULL, phone TEXT NOT NULL, email TEXT DEFAULT '', company TEXT DEFAULT '', tag TEXT DEFAULT '', source TEXT DEFAULT 'Manual', notes TEXT DEFAULT '', created_at DATETIME DEFAULT CURRENT_TIMESTAMP, updated_at DATETIME DEFAULT CURRENT_TIMESTAMP)");
}
function aiListContacts($a) {
    global $db;
    aiContactsEnsure();
    $q = trim((string)($a['query'] ?? ''));
    $lim = max(1, min(25, (int)($a['limit'] ?? 15)));
    $rows = $db->query("SELECT id, name, phone, email, company, tag FROM crm_contacts ORDER BY name COLLATE NOCASE")->fetchAll(PDO::FETCH_ASSOC);
    $tot = count($rows); $out = [];
    $qd = preg_replace('/\D/', '', $q);
    foreach ($rows as $r) {
        if ($q !== '') {
            $hit = mb_stripos($r['name'] . ' ' . $r['company'] . ' ' . $r['email'] . ' ' . $r['tag'], $q) !== false
                || ($qd !== '' && strlen($qd) >= 4 && strpos(preg_replace('/\D/', '', $r['phone']), $qd) !== false);
            if (!$hit) continue;
        }
        $out[] = $r;
    }
    return ['total_na_agenda' => $tot, 'encontrados' => count($out), 'mostrando' => min($lim, count($out)), 'contatos' => array_slice($out, 0, $lim)];
}

/** Cria ou atualiza contato (por id, ou pelo telefone se já existir). */
function aiProposeContactSave($a) {
    global $db;
    aiContactsEnsure();
    $name = trim(preg_replace('/[\x00-\x1F\x7F]+/u', ' ', (string)($a['name'] ?? '')));
    $ph = aiNormPhone($a['phone'] ?? '');
    $id = (int)($a['id'] ?? 0);
    $cur = null;
    if ($id) { $st = $db->prepare("SELECT * FROM crm_contacts WHERE id = ?"); $st->execute([$id]); $cur = $st->fetch(PDO::FETCH_ASSOC); if (!$cur) return ['error' => "Contato #$id não encontrado."]; }
    elseif ($ph !== '') {
        $tail = substr($ph, -9);
        foreach ($db->query("SELECT * FROM crm_contacts")->fetchAll(PDO::FETCH_ASSOC) as $c) if (substr(preg_replace('/\D/', '', $c['phone']), -9) === $tail) { $cur = $c; break; }
    }
    if (!$cur && ($name === '' || $ph === '')) return ['error' => 'Para criar um contato informe nome e celular com DDD.'];
    if (isset($a['phone']) && trim((string)$a['phone']) !== '' && $ph === '') return ['error' => 'Celular inválido. Informe DDD + número.'];
    $f = ['name' => $name !== '' ? mb_substr($name, 0, 80) : ($cur['name'] ?? ''), 'phone' => $ph !== '' ? $ph : ($cur['phone'] ?? '')];
    foreach (['email' => 120, 'company' => 80, 'tag' => 40, 'notes' => 500] as $k => $max) $f[$k] = array_key_exists($k, $a) ? mb_substr(trim((string)$a[$k]), 0, $max) : ($cur[$k] ?? '');
    if ($f['email'] !== '' && !filter_var($f['email'], FILTER_VALIDATE_EMAIL)) return ['error' => 'E-mail inválido.'];
    if ($cur) {
        $diff = [];
        foreach (['name' => 'nome', 'phone' => 'celular', 'email' => 'e-mail', 'company' => 'empresa', 'tag' => 'etiqueta', 'notes' => 'observações'] as $k => $l)
            if ((string)$f[$k] !== (string)($cur[$k] ?? '')) $diff[] = "$l \"" . ($cur[$k] ?: '—') . "\" → \"" . ($f[$k] ?: '—') . '"';
        if (!$diff) return ['error' => 'Nada a alterar: o contato já está assim.'];
        return aiNewProposal('contact_save', ['id' => (int)$cur['id']] + $f, 'Contato ' . $cur['name'] . ': ' . implode('; ', $diff));
    }
    return aiNewProposal('contact_save', ['id' => 0] + $f, "Cadastrar contato {$f['name']} ({$f['phone']})" . ($f['tag'] !== '' ? ", etiqueta {$f['tag']}" : '') . ($f['company'] !== '' ? ", empresa {$f['company']}" : ''));
}
function aiProposeContactDelete($a) {
    global $db;
    $id = (int)($a['id'] ?? 0);
    $st = $db->prepare("SELECT name, phone FROM crm_contacts WHERE id = ?"); $st->execute([$id]); $c = $st->fetch(PDO::FETCH_ASSOC);
    if (!$c) return ['error' => 'Contato não encontrado. Use list_contacts para achar o id.'];
    return aiNewProposal('contact_delete', ['id' => $id], "Excluir o contato {$c['name']} ({$c['phone']})");
}

function aiListRules() {
    global $db;
    $out = ['mensagens' => [], 'variaveis' => aiRuleVars(), 'filas' => []];
    foreach (aiRuleCatalog() as $k => $label) $out['mensagens'][] = ['chave' => $k, 'o_que_e' => $label, 'texto_atual' => getRule($k) ?: '(modelo padrão de fábrica)'];
    try { foreach ($db->query("SELECT queue_number, queue_name, abandon_enabled, supervisor_whatsapp, abandon_msg FROM queues_config ORDER BY queue_number")->fetchAll(PDO::FETCH_ASSOC) as $q)
        $out['filas'][] = ['fila' => $q['queue_number'], 'nome' => $q['queue_name'] ?? '', 'aviso_abandono' => (bool)$q['abandon_enabled'], 'supervisor_whatsapp' => $q['supervisor_whatsapp'], 'mensagem_propria' => $q['abandon_msg'] ?: '(usa a mensagem padrão)']; } catch (Throwable $e) {}
    return $out;
}
function aiProposeRuleSet($a) {
    if (!hasUserPermission('mod_whatsapp')) return ['error' => 'Este usuário não tem acesso à área WhatsApp & Automação.'];
    $cat = aiRuleCatalog(); $key = trim((string)($a['rule'] ?? ''));
    if (!isset($cat[$key])) return ['error' => 'Regra inválida. Opções: ' . implode(', ', array_keys($cat)) . '.'];
    $txt = trim((string)($a['text'] ?? ''));
    if ($txt === '') return ['error' => 'Informe o novo texto da mensagem.'];
    if (mb_strlen($txt) > 1000) return ['error' => 'Texto longo demais (máx. 1000 caracteres).'];
    if (preg_match_all('/\{[A-Za-z_]+\}/', $txt, $m)) foreach (array_unique($m[0]) as $v) if (!in_array($v, aiRuleVars(), true)) return ['error' => "Variável $v não existe. Use só: " . implode(' ', aiRuleVars()) . '.'];
    $cur = getRule($key);
    if ($cur === $txt) return ['error' => 'Nada a alterar: a mensagem já está assim.'];
    return aiNewProposal('rule_set', ['rule' => $key, 'text' => $txt], 'Alterar "' . $cat[$key] . '" para: "' . mb_substr($txt, 0, 160) . (mb_strlen($txt) > 160 ? '…' : '') . '"');
}
function aiProposeQueueAbandon($a) {
    global $db;
    if (!hasUserPermission('mod_whatsapp')) return ['error' => 'Este usuário não tem acesso à área WhatsApp & Automação.'];
    $qn = preg_replace('/\D/', '', (string)($a['queue'] ?? ''));
    $st = $db->prepare("SELECT * FROM queues_config WHERE queue_number = ?"); $st->execute([$qn]); $q = $st->fetch(PDO::FETCH_ASSOC);
    if (!$q) return ['error' => "Fila $qn não encontrada."];
    $ch = []; $lines = [];
    if (array_key_exists('enabled', $a)) {
        $on = filter_var($a['enabled'], FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE); if ($on === null) $on = in_array(strtolower(trim((string)$a['enabled'])), ['sim', 'ativar', 'on'], true);
        if ((int)$on !== (int)$q['abandon_enabled']) { $ch['enabled'] = (int)$on; $lines[] = ($on ? 'ativar' : 'desativar') . ' o aviso de abandono'; }
    }
    if (array_key_exists('supervisor_whatsapp', $a)) {
        $w = trim((string)$a['supervisor_whatsapp']) === '' ? '' : aiNormPhone($a['supervisor_whatsapp']);
        if (trim((string)$a['supervisor_whatsapp']) !== '' && $w === '') return ['error' => 'WhatsApp do supervisor inválido. Informe DDD + número.'];
        if ($w !== (string)$q['supervisor_whatsapp']) { $ch['supervisor'] = $w; $lines[] = $w === '' ? 'remover o supervisor' : "supervisor → $w"; }
    }
    if (array_key_exists('message', $a)) {
        $m = trim((string)$a['message']);
        if (mb_strlen($m) > 1000) return ['error' => 'Mensagem longa demais (máx. 1000).'];
        if (preg_match_all('/\{[A-Za-z_]+\}/', $m, $mm)) foreach (array_unique($mm[0]) as $v) if (!in_array($v, aiRuleVars(), true)) return ['error' => "Variável $v não existe."];
        if ($m !== (string)$q['abandon_msg']) { $ch['message'] = $m; $lines[] = $m === '' ? 'usar a mensagem padrão' : 'mensagem própria "' . mb_substr($m, 0, 100) . '"'; }
    }
    if (!$ch) return ['error' => "Nada a alterar na fila $qn."];
    return aiNewProposal('queue_abandon', ['queue' => $qn] + $ch, "Fila $qn" . (!empty($q['queue_name']) ? ' (' . $q['queue_name'] . ')' : '') . ': ' . implode('; ', $lines));
}

/** Aplica ações de configuração confirmadas. Devolve null se o campo não for desta família. */
/* ===== Resumo diário, bloqueio de números e leitura das rotas do Issabel ===== */
function aiProposeDailySummary($a) {
    $wa = trim((string)($a['whatsapp'] ?? '')); $em = trim((string)($a['email'] ?? ''));
    $ch = ($wa !== '' && $em !== '') ? 'ambos' : ($em !== '' ? 'email' : 'whatsapp');
    return aiProposeReportSchedule(['report_type' => 'Resumo Diário', 'frequency' => 'diario', 'send_time' => (string)($a['time'] ?? '08:00'), 'channel' => $ch, 'email_to' => $em, 'whatsapp_to' => $wa, 'title' => 'Resumo Diário']);
}
function aiProposeBlacklist($a) {
    if (!hasUserPermission('manage_settings')) return ['error' => 'Este usuário não tem permissão para bloquear números.'];
    $num = preg_replace('/\D/', '', (string)($a['number'] ?? ''));
    if (strlen($num) < 8) return ['error' => 'Informe o número completo com DDD.'];
    $act = ($a['action'] ?? 'add') === 'remove' ? 'remove' : 'add';
    $cur = asteriskBlacklistList();
    if (!$cur['success']) return ['error' => 'Não consegui ler a lista de bloqueio do PABX agora: ' . $cur['error']];
    $has = false; foreach ($cur['items'] as $it) if ($it['number'] === $num) $has = true;
    if ($act === 'add' && $has) return ['error' => "O número $num já está bloqueado."];
    if ($act === 'remove' && !$has) return ['error' => "O número $num não está na lista de bloqueio."];
    $desc = mb_substr(trim((string)($a['description'] ?? '')), 0, 60);
    return aiNewProposal('blacklist', ['number' => $num, 'action' => $act, 'description' => $desc], ($act === 'add' ? "Bloquear o número $num" . ($desc !== '' ? " ($desc)" : '') . ' (o PABX passa a rejeitar as chamadas dele)' : "Desbloquear o número $num"));
}
function aiListBlacklist() {
    $r = asteriskBlacklistList();
    if (!$r['success']) return ['error' => 'Não consegui ler a lista de bloqueio do PABX: ' . $r['error']];
    return ['total' => count($r['items']), 'numeros' => array_slice($r['items'], 0, 100)];
}
/** Leitura das rotas de entrada exatamente como estão no Issabel (o painel não altera nada ali). */
function aiInboundRoutes($a) {
    $flows = function_exists('getPABXInboundCallFlows') ? getPABXInboundCallFlows() : [];
    if (!$flows) return ['error' => $GLOBALS['__flows_status'] ?? 'Nenhuma rota de entrada encontrada no PABX.'];
    $walk = function ($n, $d = 0) use (&$walk) {
        if (!is_array($n) || $d > 5) return [];
        $line = ($n['title'] ?? '') . (empty($n['desc']) ? '' : ' | ' . $n['desc']);
        if (!empty($n['timegroup']['rules'])) $line .= ' | Horários: ' . implode('; ', $n['timegroup']['rules']);
        if (!empty($n['members'])) $line .= ' | Membros: ' . implode(', ', array_map(function ($m) { return $m['extension'] . ' (' . $m['name'] . ')'; }, $n['members']));
        $out = [$line];
        foreach (['true_node' => 'Dentro do horário', 'false_node' => 'Fora do horário', 'next_node' => 'Depois', 'failover_node' => 'Se ninguém atender', 'invalid_node' => 'Opção inválida', 'timeout_node' => 'Sem resposta'] as $k => $lbl)
            if (!empty($n[$k])) foreach ($walk($n[$k], $d + 1) as $l) $out[] = "$lbl → $l";
        if (!empty($n['entries'])) foreach ($n['entries'] as $opt => $e) foreach ($walk($e, $d + 1) as $l) $out[] = "Tecla $opt → $l";
        return $out;
    };
    $res = [];
    foreach ($flows as $f) $res[] = ['numero_de_entrada' => $f['did'], 'descricao' => $f['description'], 'caminho' => $walk($f['tree'] ?? null)];
    return ['fonte' => 'Configuração do Issabel (somente leitura; horários, URA, filas e grupos se alteram no Issabel)', 'rotas' => $res];
}

function aiApplyConfigAction($field, $v) {
    global $db;
    if ($field === 'contact_save') {
        if ((int)$v['id']) {
            $db->prepare("UPDATE crm_contacts SET name=:n, phone=:p, email=:e, company=:co, tag=:t, notes=:no, updated_at=CURRENT_TIMESTAMP WHERE id=:id")
               ->execute([':n' => $v['name'], ':p' => $v['phone'], ':e' => $v['email'], ':co' => $v['company'], ':t' => $v['tag'], ':no' => $v['notes'], ':id' => (int)$v['id']]);
        } else {
            $db->prepare("INSERT INTO crm_contacts (name, phone, email, company, tag, source, notes) VALUES (:n,:p,:e,:co,:t,'Chat IA',:no)")
               ->execute([':n' => $v['name'], ':p' => $v['phone'], ':e' => $v['email'], ':co' => $v['company'], ':t' => $v['tag'], ':no' => $v['notes']]);
        }
        try { AddressBookSync::reconcile($db, true); } catch (Throwable $e_abs) {}
        panelRevBump('contacts');
        return ['success' => true];
    }
    if ($field === 'contact_delete') {
        $db->prepare("DELETE FROM crm_contacts WHERE id = ?")->execute([(int)$v['id']]);
        try { AddressBookSync::reconcile($db, true); } catch (Throwable $e_abs) {}
        panelRevBump('contacts');
        return ['success' => true];
    }
    if ($field === 'rule_set') {
        if (!hasUserPermission('mod_whatsapp')) return ['success' => false, 'error' => 'Sem acesso à área WhatsApp & Automação.'];
        if (!isset(aiRuleCatalog()[$v['rule']])) return ['success' => false, 'error' => 'Regra inválida.'];
        $db->prepare("INSERT OR REPLACE INTO integration_rules (key_name, value_val) VALUES (:k, :v)")->execute([':k' => $v['rule'], ':v' => $v['text']]);
        $warn = '';
        if (function_exists('syncCustomDestinationsWithAsterisk')) { $r = syncCustomDestinationsWithAsterisk(); if (empty($r['success'])) $warn = ' (aviso ao sincronizar com a central: ' . ($r['message'] ?? '') . ')'; }
        if (function_exists('pabx_log')) pabx_log('whatsapp', 'INFO', 'Regra de mensagem alterada pelo Chat IA (confirmada pelo usuário)', ['regra' => $v['rule']]);
        panelRevBump('rules');
        return ['success' => true, 'note' => $warn];
    }
    if ($field === 'queue_abandon') {
        if (!hasUserPermission('mod_whatsapp')) return ['success' => false, 'error' => 'Sem acesso à área WhatsApp & Automação.'];
        $sets = []; $par = [':q' => $v['queue']];
        if (array_key_exists('enabled', $v))    { $sets[] = 'abandon_enabled = :en';      $par[':en'] = (int)$v['enabled']; }
        if (array_key_exists('supervisor', $v)) { $sets[] = 'supervisor_whatsapp = :sup'; $par[':sup'] = $v['supervisor']; }
        if (array_key_exists('message', $v))    { $sets[] = 'abandon_msg = :m';           $par[':m'] = $v['message']; }
        if (!$sets) return ['success' => false, 'error' => 'Nada a alterar.'];
        $db->prepare("UPDATE queues_config SET " . implode(', ', $sets) . ", updated_at = CURRENT_TIMESTAMP WHERE queue_number = :q")->execute($par);
        if (function_exists('pabx_log')) pabx_log('whatsapp', 'INFO', "Abandono da fila {$v['queue']} alterado pelo Chat IA (confirmado pelo usuário)");
        panelRevBump('rules');
        return ['success' => true];
    }
    if ($field === 'blacklist') {
        if (!hasUserPermission('manage_settings')) return ['success' => false, 'error' => 'Sem permissão para bloquear números.'];
        $r = $v['action'] === 'remove' ? asteriskBlacklistDelete($v['number']) : asteriskBlacklistAdd($v['number'], $v['description'] ?? '');
        if (empty($r['success'])) return ['success' => false, 'error' => $r['error'] ?? 'O PABX não aceitou a alteração.'];
        panelRevBump('blacklist');
        return ['success' => true];
    }
    return null;
}
