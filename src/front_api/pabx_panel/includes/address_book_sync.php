<?php
/**
 * IPbx Prisma - Sincronização da Agenda entre o painel novo e o painel clássico (Agenda do Issabel).
 * O que é cadastrado, editado ou apagado num lado aparece no outro. Dados que só existem no painel novo
 * (etiqueta, origem) são descartados no clássico; dados que só existem no clássico (sobrenome, celular, endereço...)
 * são preservados lá e não são alterados. Só mexe em contatos externos ('external') da agenda clássica.
 */
class AddressBookSync {
    public static $lastError = '';

    public static function classicPath() {
        $o = function_exists('getSetting') ? trim((string)getSetting('ab_classic_path')) : '';
        if ($o !== '' && is_file($o)) return $o;
        foreach (['/var/www/db/address_book.db', '/var/www/html/db/address_book.db'] as $p) if (is_file($p)) return $p;
        return '';
    }
    private static function classicDb() {
        $p = self::classicPath();
        if ($p === '') { self::$lastError = 'Agenda do painel clássico não encontrada neste servidor.'; return null; }
        try {
            $c = new PDO('sqlite:' . $p);
            $c->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
            $c->exec('PRAGMA busy_timeout = 5000');
            $c->query("SELECT id, name, last_name, telefono, cell_phone, home_phone, email, company, notes FROM contact LIMIT 1");
            return $c;
        } catch (Throwable $e) { self::$lastError = 'Não foi possível abrir a agenda do painel clássico: ' . $e->getMessage(); return null; }
    }
    private static function digits($p) { return preg_replace('/\D/', '', (string)$p); }
    private static function hash($name, $phone, $email, $company, $notes) {
        $d = self::digits($phone);
        if (strlen($d) > 11 && substr($d, 0, 2) === '55') $d = substr($d, 2);
        return md5(mb_strtolower(trim($name)) . '|' . $d . '|' . mb_strtolower(trim($email)) . '|' . mb_strtolower(trim($company)) . '|' . trim($notes));
    }
    private static function fromClassic($r) {
        $phone = trim((string)$r['telefono']) !== '' ? $r['telefono'] : (trim((string)$r['cell_phone']) !== '' ? $r['cell_phone'] : (string)$r['home_phone']);
        return ['name' => trim(trim((string)$r['name']) . ' ' . trim((string)$r['last_name'])), 'phone' => trim((string)$phone),
                'email' => trim((string)$r['email']), 'company' => trim((string)$r['company']), 'notes' => trim((string)$r['notes'])];
    }
    private static function h($c) { return self::hash($c['name'], $c['phone'], $c['email'], $c['company'], $c['notes']); }

    /** Faz os dois lados ficarem iguais. Retorna ['ok','changes','error']. */
    public static function reconcile($db, $force = false) {
        self::$lastError = '';
        if (!$db) return ['ok' => false, 'changes' => 0, 'error' => 'sem banco'];
        $last = (int)(function_exists('getSetting') ? getSetting('ab_sync_last') : 0);
        if (!$force && time() - $last < 20) return ['ok' => true, 'changes' => 0, 'error' => ''];
        $cl = self::classicDb();
        if (!$cl) { if (function_exists('saveSetting')) saveSetting('ab_sync_status', self::$lastError); return ['ok' => false, 'changes' => 0, 'error' => self::$lastError]; }
        $changes = 0;
        try {
            $db->exec("CREATE TABLE IF NOT EXISTS contact_sync_map (crm_id INTEGER PRIMARY KEY, classic_id INTEGER UNIQUE, hash TEXT)");
            $crm = []; foreach ($db->query("SELECT * FROM crm_contacts")->fetchAll(PDO::FETCH_ASSOC) as $r) $crm[(int)$r['id']] = $r;
            $cls = []; foreach ($cl->query("SELECT * FROM contact WHERE directory = 'external'")->fetchAll(PDO::FETCH_ASSOC) as $r) $cls[(int)$r['id']] = $r;
            $map = []; foreach ($db->query("SELECT crm_id, classic_id, hash FROM contact_sync_map")->fetchAll(PDO::FETCH_ASSOC) as $m) $map[(int)$m['crm_id']] = $m;
            $linkedClassic = [];
            $setMap = $db->prepare("INSERT OR REPLACE INTO contact_sync_map (crm_id, classic_id, hash) VALUES (?,?,?)");
            $delMap = $db->prepare("DELETE FROM contact_sync_map WHERE crm_id = ?");
            $cl->beginTransaction();
            // 1) Vínculos existentes
            foreach ($map as $crmId => $m) {
                $cid = (int)$m['classic_id'];
                $hasN = isset($crm[$crmId]); $hasC = isset($cls[$cid]);
                if (!$hasN && !$hasC) { $delMap->execute([$crmId]); continue; }
                if (!$hasN) { // apagado no painel novo -> apaga no clássico
                    $cl->prepare("DELETE FROM contact WHERE id = ?")->execute([$cid]); $delMap->execute([$crmId]); unset($cls[$cid]); $changes++; continue; }
                if (!$hasC) { // apagado no clássico -> apaga no novo
                    $db->prepare("DELETE FROM crm_contacts WHERE id = ?")->execute([$crmId]); $delMap->execute([$crmId]); $changes++; unset($crm[$crmId]); continue; }
                $linkedClassic[$cid] = true;
                $c = self::fromClassic($cls[$cid]);
                $n = ['name' => trim((string)$crm[$crmId]['name']), 'phone' => trim((string)$crm[$crmId]['phone']), 'email' => trim((string)$crm[$crmId]['email']), 'company' => trim((string)$crm[$crmId]['company']), 'notes' => trim((string)$crm[$crmId]['notes'])];
                $hc = self::h($c); $hn = self::h($n); $hs = (string)$m['hash'];
                if ($hc === $hn) { if ($hs !== $hc) $setMap->execute([$crmId, $cid, $hc]); continue; }
                if ($hc !== $hs) { // alterado no clássico (o clássico vence em conflito)
                    $db->prepare("UPDATE crm_contacts SET name=?, phone=?, email=?, company=?, notes=?, updated_at=CURRENT_TIMESTAMP WHERE id=?")->execute([$c['name'], $c['phone'], $c['email'], $c['company'], $c['notes'], $crmId]);
                    $setMap->execute([$crmId, $cid, $hc]); $changes++;
                } else { // alterado no novo
                    if ($n['phone'] === '' ) { $setMap->execute([$crmId, $cid, $hc]); continue; }
                    $sets = ['email=?', 'company=?', 'notes=?']; $par = [$n['email'], $n['company'], $n['notes']];
                    if ($n['name'] !== $c['name']) { $sets[] = 'name=?'; $sets[] = "last_name=''"; $par[] = $n['name']; }
                    if (self::digits($n['phone']) !== self::digits($c['phone'])) { $sets[] = 'telefono=?'; $par[] = $n['phone']; }
                    $par[] = $cid;
                    $cl->prepare("UPDATE contact SET " . implode(', ', $sets) . " WHERE id=?")->execute($par);
                    $setMap->execute([$crmId, $cid, $hn]); $changes++;
                }
            }
            // 2) Contatos do clássico sem vínculo: liga por telefone ou cria no novo
            $unl = []; foreach ($crm as $id => $r) if (!isset($map[$id])) $unl[$id] = $r;
            foreach ($cls as $cid => $r) {
                if (isset($linkedClassic[$cid])) continue;
                $c = self::fromClassic($r);
                if (self::digits($c['phone']) === '' ) continue;
                $found = 0;
                foreach ($unl as $id => $u) if (self::digits($u['phone']) !== '' && self::hash('', $u['phone'], '', '', '') === self::hash('', $c['phone'], '', '', '')) { $found = $id; break; }
                if ($found) { // já existe dos dois lados: liga; o clássico vence
                    $db->prepare("UPDATE crm_contacts SET name=?, phone=?, email=?, company=?, notes=?, updated_at=CURRENT_TIMESTAMP WHERE id=?")->execute([$c['name'] !== '' ? $c['name'] : $unl[$found]['name'], $c['phone'], $c['email'], $c['company'], $c['notes'], $found]);
                    $setMap->execute([$found, $cid, self::h($c)]); unset($unl[$found]);
                } else {
                    $db->prepare("INSERT INTO crm_contacts (name, phone, email, company, tag, source, notes) VALUES (?,?,?,?, '', 'Painel clássico', ?)")->execute([$c['name'] !== '' ? $c['name'] : $c['phone'], $c['phone'], $c['email'], $c['company'], $c['notes']]);
                    $setMap->execute([(int)$db->lastInsertId(), $cid, self::h($c)]);
                }
                $changes++;
            }
            // 3) Contatos do novo sem vínculo: cria no clássico (etiqueta e origem não existem lá e são descartadas)
            $ins = $cl->prepare("INSERT INTO contact (name, last_name, telefono, cell_phone, home_phone, email, iduser, company, notes, directory, status) VALUES (?,?,?,?,?,?,?,?,?, 'external', 'isPublic')");
            foreach ($unl as $id => $u) {
                if (self::digits($u['phone']) === '') continue;
                // Números novos vindos das chamadas, ainda sem nome, não vão para a agenda clássica até serem nomeados
                if (($u['source'] ?? '') === 'Ligação PABX' && (trim($u['name']) === '' || self::digits($u['name']) === self::digits($u['phone']))) continue;
                $ins->execute([trim($u['name']) !== '' ? trim($u['name']) : trim($u['phone']), '', trim($u['phone']), '', '', trim((string)$u['email']), '1', trim((string)$u['company']), trim((string)$u['notes'])]);
                $setMap->execute([$id, (int)$cl->lastInsertId(), self::hash($u['name'], $u['phone'], $u['email'], $u['company'], $u['notes'])]); $changes++;
            }
            $cl->commit();
        } catch (Throwable $e) {
            try { if ($cl->inTransaction()) $cl->rollBack(); } catch (Throwable $e2) {}
            self::$lastError = 'Falha ao sincronizar a agenda: ' . $e->getMessage();
            if (function_exists('saveSetting')) saveSetting('ab_sync_status', self::$lastError);
            return ['ok' => false, 'changes' => $changes, 'error' => self::$lastError];
        }
        if (function_exists('saveSetting')) { saveSetting('ab_sync_last', (string)time()); saveSetting('ab_sync_status', ''); }
        if ($changes > 0 && function_exists('panelRevBump')) panelRevBump('contacts');
        return ['ok' => true, 'changes' => $changes, 'error' => ''];
    }

    // Compatibilidade com chamadas antigas: qualquer gravação dispara a sincronização imediata
    public static function syncToIssabel($name = '', $phone = '', $email = '', $company = '', $notes = '') { global $db; return self::reconcile($db, true)['ok']; }
    public static function syncToPABX($name = '', $phone = '', $email = '', $company = '', $notes = '') { return self::syncToIssabel(); }
    public static function deleteFromIssabel($phone = '') { return self::syncToIssabel(); }
    public static function syncIssabelToFront($db, $force = false) { return self::reconcile($db, $force)['changes']; }
    public static function syncFrontToIssabelAll($db) { return self::reconcile($db, true)['changes']; }

    public static function getVersionHash($db) {
        if (!$db) return '0';
        try {
            $row = $db->query("SELECT COUNT(*) as total, MAX(updated_at) as max_up FROM crm_contacts")->fetch(PDO::FETCH_ASSOC);
            return md5(($row['total'] ?? 0) . '-' . ($row['max_up'] ?? ''));
        } catch (Exception $e) { return '0'; }
    }
}
