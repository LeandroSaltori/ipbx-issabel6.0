<?php
/**
 * IPbx Prisma - Grupos de Chamada (Telefonia & Ramais > Grupos de Chamada).
 * ESPELHADO com a central: o painel le e grava na MESMA tabela (asterisk.ringgroups) que a tela nativa usa.
 * O que se cria/edita/exclui aqui aparece na central e vice-versa; nao existe copia no painel.
 * Seguranca: grupo em uso (rota de entrada, URA, fila, outro grupo...) nao pode ser excluido; toda alteracao guarda
 * uma copia do que existia antes (desfazer) e a central so e recarregada depois de gravar.
 */

function rgConn() {
    $c = function_exists('getAsteriskDBConnection') ? getAsteriskDBConnection() : null;
    if (!$c && function_exists('getAsteriskPdoConnection')) $c = getAsteriskPdoConnection('asterisk');
    return $c;
}

function rgColumns(PDO $c) {
    $cols = [];
    try {
        foreach ($c->query('SHOW COLUMNS FROM ringgroups') as $r) $cols[$r['Field']] = ['null' => strtoupper((string)($r['Null'] ?? 'YES')) === 'YES', 'default' => $r['Default'] ?? null, 'type' => strtolower((string)($r['Type'] ?? ''))];
    } catch (Throwable $e) {
        foreach ($c->query('PRAGMA table_info(ringgroups)') as $r) $cols[$r['name']] = ['null' => !$r['notnull'], 'default' => $r['dflt_value'], 'type' => strtolower((string)$r['type'])];
    }
    return $cols;
}

/** Estrategias nativas, com nome claro. */
function rgStrategies() {
    return [
        'ringall'         => 'Todos tocam juntos',
        'ringallv2'       => 'Todos tocam juntos (prefere ramais livres)',
        'hunt'            => 'Um de cada vez, na ordem da lista',
        'memoryhunt'      => 'Um de cada vez, acumulando (1, depois 1+2, 1+2+3...)',
        'firstavailable'  => 'Primeiro ramal disponível',
        'firstnotonphone' => 'Primeiro ramal que não está em ligação',
    ];
}

function rgSplitList($grplist) {
    $out = [];
    foreach (explode('-', (string)$grplist) as $p) { $p = trim($p); if ($p !== '') $out[] = $p; }
    return $out;
}

/** Lista os grupos da central. */
function rgList(PDO $c) {
    $rows = [];
    foreach ($c->query('SELECT * FROM ringgroups ORDER BY grpnum') as $r) {
        $r['membros'] = rgSplitList($r['grplist'] ?? '');
        $rows[] = $r;
    }
    usort($rows, function ($a, $b) { return (int)$a['grpnum'] <=> (int)$b['grpnum']; });
    return $rows;
}

/** Onde o grupo e usado (nao deixa excluir se estiver em uso). Cada item: texto legivel. */
function rgReferences(PDO $c, $grpnum) {
    $refs = [];
    $dest = 'ext-group,' . $grpnum . ',1';
    $try = function ($sql, $label, $params) use ($c, &$refs) {
        try {
            $st = $c->prepare($sql); $st->execute($params);
            foreach ($st->fetchAll(PDO::FETCH_NUM) as $row) $refs[] = sprintf($label, ...array_map('strval', $row));
        } catch (Throwable $e) {} // tabela inexistente nesta versao: ignora
    };
    $try('SELECT extension, cidnum FROM incoming WHERE destination = ?', 'Rota de entrada %s %s', [$dest]);
    $try('SELECT ivr_id, selected FROM ivr_entries WHERE dest = ?', 'URA %s (opção %s)', [$dest]);
    $try('SELECT id, name FROM ivr_details WHERE invalid_destination = ? OR timeout_destination = ?', 'URA %s (%s)', [$dest, $dest]);
    $try('SELECT extension, descr FROM queues_config WHERE dest = ?', 'Fila %s (%s)', [$dest]);
    $try('SELECT grpnum, description FROM ringgroups WHERE postdest = ? AND grpnum <> ?', 'Outro grupo: %s (%s)', [$dest, $grpnum]);
    $try('SELECT timeconditions_id, displayname FROM timeconditions WHERE truegoto = ? OR falsegoto = ?', 'Condição de horário %s (%s)', [$dest, $dest]);
    return $refs;
}

/** O numero do grupo nao pode colidir com ramal, fila ou outro grupo. */
function rgNumberInUse(PDO $c, $grpnum, $ignoreSelf = false) {
    $chk = [
        ['SELECT COUNT(*) FROM ringgroups WHERE grpnum = ?', !$ignoreSelf],
        ['SELECT COUNT(*) FROM users WHERE extension = ?', true],
        ['SELECT COUNT(*) FROM queues_config WHERE extension = ?', true],
    ];
    foreach ($chk as [$sql, $on]) {
        if (!$on) continue;
        try { $st = $c->prepare($sql); $st->execute([$grpnum]); if ((int)$st->fetchColumn() > 0) return true; } catch (Throwable $e) {}
    }
    return false;
}

function rgBackupDir() { return defined('IPBX_BACKUP_DIR') ? IPBX_BACKUP_DIR : '/var/backup/ipbx/grupos'; }

/** Guarda a linha anterior (ou "nao existia") para desfazer. */
function rgSnapshot($grpnum, $antes, $acao) {
    $d = rgBackupDir();
    if (!is_dir($d)) @mkdir($d, 0750, true);
    $f = $d . '/' . date('Ymd-His') . '-' . $grpnum . '-' . $acao . '.json';
    $ok = @file_put_contents($f, json_encode(['grpnum' => (string)$grpnum, 'acao' => $acao, 'antes' => $antes, 'quando' => date('c')], JSON_UNESCAPED_UNICODE));
    return $ok !== false ? $f : '';
}

/** Valida e normaliza o que veio do formulario. Devolve [dados, erro]. */
function rgValidate(array $in) {
    $grpnum = preg_replace('/\D/', '', (string)($in['grpnum'] ?? ''));
    if ($grpnum === '' || strlen($grpnum) > 8) return [null, 'Informe o número do grupo (só dígitos, até 8). Ex.: 600.'];
    $desc = trim((string)($in['description'] ?? ''));
    if ($desc === '' || mb_strlen($desc) > 35) return [null, 'Informe um nome para o grupo (até 35 letras). Ex.: Vendas.'];
    $strategies = rgStrategies();
    $strategy = (string)($in['strategy'] ?? 'ringall');
    if (!isset($strategies[$strategy])) return [null, 'Forma de tocar inválida.'];
    $time = (int)($in['grptime'] ?? 20);
    if ($time < 5 || $time > 300) return [null, 'O tempo de toque deve ficar entre 5 e 300 segundos.'];
    $members = [];
    foreach ((array)($in['membros'] ?? []) as $m) {
        $m = preg_replace('/[^0-9#]/', '', (string)$m);
        if ($m !== '' && !in_array($m, $members, true)) $members[] = $m;
    }
    foreach (preg_split('/[,;\r\n]+/', (string)($in['externos'] ?? ''), -1, PREG_SPLIT_NO_EMPTY) as $x) {
        $x = preg_replace('/\D/', '', $x);
        if (strlen($x) >= 8) { $x .= '#'; if (!in_array($x, $members, true)) $members[] = $x; }
    }
    if (!$members) return [null, 'Escolha pelo menos um ramal para o grupo.'];
    $post = trim((string)($in['postdest'] ?? ''));
    if ($post !== '' && !preg_match('/^[A-Za-z0-9_\-]+,[A-Za-z0-9_\-*#]+,\d+$/', $post)) return [null, 'Destino quando ninguém atende inválido.'];
    return [['grpnum' => $grpnum, 'description' => $desc, 'strategy' => $strategy, 'grptime' => $time, 'grplist' => implode('-', $members), 'postdest' => $post !== '' ? $post : 'app-blackhole,hangup,1'], ''];
}

/** Cria ou atualiza. $isNew true = criacao. */
function rgSave(PDO $c, array $in, $isNew) {
    if (!$isNew) $in['postdest'] = ''; // edicao nao mexe no destino "se ninguem atender" (pode ser URA, fila etc.)
    [$d, $err] = rgValidate($in);
    if ($err !== '') return ['success' => false, 'message' => $err];
    $grp = $d['grpnum'];
    $exists = $c->prepare('SELECT * FROM ringgroups WHERE grpnum = ?'); $exists->execute([$grp]); $antes = $exists->fetch(PDO::FETCH_ASSOC);
    if (!$isNew && $antes && ($antes['postdest'] ?? '') !== '') $d['postdest'] = $antes['postdest'];
    if ($isNew) {
        if ($antes) return ['success' => false, 'message' => "Já existe o grupo $grp."];
        if (rgNumberInUse($c, $grp)) return ['success' => false, 'message' => "O número $grp já é usado por um ramal, fila ou outro grupo da central. Escolha outro número."];
    } elseif (!$antes) {
        return ['success' => false, 'message' => "O grupo $grp não existe mais na central."];
    }
    $cols = rgColumns($c);
    $row = $isNew ? [] : $antes;
    $row = array_merge($row, ['grpnum' => $grp, 'description' => $d['description'], 'strategy' => $d['strategy'], 'grptime' => $d['grptime'], 'grplist' => $d['grplist'], 'postdest' => $d['postdest']]);
    if ($isNew) { // colunas NOT NULL sem padrao: preenche vazio/zero para nao falhar em versoes diferentes
        foreach ($cols as $name => $meta) {
            if (array_key_exists($name, $row) || $meta['null'] || $meta['default'] !== null) continue;
            $row[$name] = preg_match('/int|decimal|float|double/', $meta['type']) ? 0 : '';
        }
    }
    $row = array_intersect_key($row, $cols);
    $snap = rgSnapshot($grp, $antes ?: null, $isNew ? 'criar' : 'editar');
    if ($snap === '') return ['success' => false, 'message' => 'Não foi possível guardar a cópia de segurança; nada foi alterado.'];
    try {
        if ($isNew) {
            $c->prepare('INSERT INTO ringgroups (' . implode(',', array_keys($row)) . ') VALUES (' . implode(',', array_fill(0, count($row), '?')) . ')')->execute(array_values($row));
        } else {
            $set = []; foreach ($row as $k => $_) if ($k !== 'grpnum') $set[] = "$k = ?";
            $vals = []; foreach ($row as $k => $v) if ($k !== 'grpnum') $vals[] = $v; $vals[] = $grp;
            $c->prepare('UPDATE ringgroups SET ' . implode(', ', $set) . ' WHERE grpnum = ?')->execute($vals);
        }
    } catch (Throwable $e) {
        return ['success' => false, 'message' => 'A central recusou a gravação: ' . $e->getMessage()];
    }
    if (function_exists('applyFreepbxConfig')) applyFreepbxConfig($c);
    return ['success' => true, 'message' => ($isNew ? "Grupo $grp criado" : "Grupo $grp atualizado") . ' e aplicado na central.', 'backup' => $snap];
}

function rgDelete(PDO $c, $grpnum) {
    $grpnum = preg_replace('/\D/', '', (string)$grpnum);
    $st = $c->prepare('SELECT * FROM ringgroups WHERE grpnum = ?'); $st->execute([$grpnum]); $antes = $st->fetch(PDO::FETCH_ASSOC);
    if (!$antes) return ['success' => false, 'message' => 'Grupo não encontrado.'];
    $refs = rgReferences($c, $grpnum);
    if ($refs) return ['success' => false, 'message' => "O grupo $grpnum está em uso e não pode ser excluído: " . implode('; ', $refs) . '. Troque o destino desses itens primeiro.'];
    $snap = rgSnapshot($grpnum, $antes, 'excluir');
    if ($snap === '') return ['success' => false, 'message' => 'Não foi possível guardar a cópia de segurança; nada foi excluído.'];
    try { $c->prepare('DELETE FROM ringgroups WHERE grpnum = ?')->execute([$grpnum]); }
    catch (Throwable $e) { return ['success' => false, 'message' => 'A central recusou a exclusão: ' . $e->getMessage()]; }
    if (function_exists('applyFreepbxConfig')) applyFreepbxConfig($c);
    return ['success' => true, 'message' => "Grupo $grpnum excluído. Dá para desfazer logo abaixo.", 'backup' => $snap];
}

/** Ultima alteracao feita pelo painel (arquivo mais recente), ou null. */
function rgLastChange() {
    $fs = glob(rgBackupDir() . '/*.json') ?: [];
    if (!$fs) return null;
    sort($fs); $f = end($fs);
    $j = json_decode((string)@file_get_contents($f), true);
    return is_array($j) ? $j + ['arquivo' => $f] : null;
}

/** Desfaz a ultima alteracao: volta a linha anterior (ou apaga o grupo criado). */
function rgUndo(PDO $c) {
    $l = rgLastChange();
    if (!$l) return ['success' => false, 'message' => 'Não há alteração para desfazer.'];
    $grp = $l['grpnum'];
    try {
        if ($l['antes'] === null) {
            if (rgReferences($c, $grp)) return ['success' => false, 'message' => "O grupo $grp já está em uso; não é seguro apagar."];
            $c->prepare('DELETE FROM ringgroups WHERE grpnum = ?')->execute([$grp]);
        } else {
            $cols = rgColumns($c);
            $row = array_intersect_key($l['antes'], $cols);
            $c->prepare('DELETE FROM ringgroups WHERE grpnum = ?')->execute([$grp]);
            $c->prepare('INSERT INTO ringgroups (' . implode(',', array_keys($row)) . ') VALUES (' . implode(',', array_fill(0, count($row), '?')) . ')')->execute(array_values($row));
        }
    } catch (Throwable $e) { return ['success' => false, 'message' => 'Não foi possível desfazer: ' . $e->getMessage()]; }
    @rename($l['arquivo'], $l['arquivo'] . '.desfeito');
    if (function_exists('applyFreepbxConfig')) applyFreepbxConfig($c);
    return ['success' => true, 'message' => "Alteração do grupo $grp desfeita e aplicada na central."];
}
