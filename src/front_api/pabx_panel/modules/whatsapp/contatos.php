<?php
if (!defined('IPBX_PANEL')) { http_response_code(404); exit; } // acesso direto pela URL e proibido: so o index.php (com login) carrega esta tela
/**
 * IPbx Prisma - Agenda de Contatos & CRM Prismabot v8.0
 * - Z-PRO → Prismabot em todos os textos
 * - Edição de contatos funcional
 * - Importação real via API Prismabot
 * - Etiquetas com cores selecionáveis pelo cliente
 * - Mensagens rápidas (CRUD)
 */

require_once __DIR__ . '/../../includes/address_book_sync.php';

$msg_status  = '';
$msg_error   = '';

// ─── Criar tabela de etiquetas personalizadas ─────────────────────────────────
$db->exec("CREATE TABLE IF NOT EXISTS contact_tags (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    name TEXT NOT NULL UNIQUE,
    color_key TEXT NOT NULL DEFAULT 'emerald',
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP
)");

// ─── Criar tabela de contatos ─────────────────────────────────────────────────
$db->exec("CREATE TABLE IF NOT EXISTS crm_contacts (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    name TEXT NOT NULL,
    phone TEXT NOT NULL,
    email TEXT DEFAULT '',
    company TEXT DEFAULT '',
    tag TEXT DEFAULT '',
    source TEXT DEFAULT 'Manual',
    notes TEXT DEFAULT '',
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP
)");

// ─── Criar tabela de mensagens rápidas ───────────────────────────────────────
$db->exec("CREATE TABLE IF NOT EXISTS quick_messages (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    title TEXT NOT NULL,
    body TEXT NOT NULL,
    category TEXT DEFAULT 'Geral',
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP
)");

// ─── Paleta de cores disponíveis ─────────────────────────────────────────────
$color_palette = [
    'emerald' => ['label' => 'Verde',    'classes' => 'bg-emerald-500/20 text-emerald-300 border-emerald-500/40', 'dot' => 'bg-emerald-400'],
    'cyan'    => ['label' => 'Ciano',    'classes' => 'bg-cyan-500/20 text-cyan-300 border-cyan-500/40',           'dot' => 'bg-cyan-400'],
    'amber'   => ['label' => 'Laranja',  'classes' => 'bg-amber-500/20 text-amber-300 border-amber-500/40',         'dot' => 'bg-amber-400'],
    'purple'  => ['label' => 'Roxo',     'classes' => 'bg-purple-500/20 text-purple-300 border-purple-500/40',     'dot' => 'bg-purple-400'],
    'rose'    => ['label' => 'Vermelho', 'classes' => 'bg-rose-500/20 text-rose-300 border-rose-500/40',           'dot' => 'bg-rose-400'],
    'indigo'  => ['label' => 'Índigo',   'classes' => 'bg-indigo-500/20 text-indigo-300 border-indigo-500/40',     'dot' => 'bg-indigo-400'],
    'sky'     => ['label' => 'Azul',     'classes' => 'bg-sky-500/20 text-sky-300 border-sky-500/40',             'dot' => 'bg-sky-400'],
    'pink'    => ['label' => 'Rosa',     'classes' => 'bg-pink-500/20 text-pink-300 border-pink-500/40',           'dot' => 'bg-pink-400'],
    'red'      => ['label' => 'Vermelho escuro', 'classes' => 'bg-red-500/20 text-red-300 border-red-500/40', 'dot' => 'bg-red-400'],
    'orange'   => ['label' => 'Laranja escuro', 'classes' => 'bg-orange-500/20 text-orange-300 border-orange-500/40', 'dot' => 'bg-orange-400'],
    'yellow'   => ['label' => 'Amarelo',  'classes' => 'bg-yellow-500/20 text-yellow-300 border-yellow-500/40', 'dot' => 'bg-yellow-400'],
    'lime'     => ['label' => 'Lima',     'classes' => 'bg-lime-500/20 text-lime-300 border-lime-500/40', 'dot' => 'bg-lime-400'],
    'green'    => ['label' => 'Verde escuro', 'classes' => 'bg-green-500/20 text-green-300 border-green-500/40', 'dot' => 'bg-green-400'],
    'teal'     => ['label' => 'Turquesa', 'classes' => 'bg-teal-500/20 text-teal-300 border-teal-500/40', 'dot' => 'bg-teal-400'],
    'blue'     => ['label' => 'Azul escuro', 'classes' => 'bg-blue-500/20 text-blue-300 border-blue-500/40', 'dot' => 'bg-blue-400'],
    'violet'   => ['label' => 'Violeta',  'classes' => 'bg-violet-500/20 text-violet-300 border-violet-500/40', 'dot' => 'bg-violet-400'],
    'fuchsia'  => ['label' => 'Magenta',  'classes' => 'bg-fuchsia-500/20 text-fuchsia-300 border-fuchsia-500/40', 'dot' => 'bg-fuchsia-400'],
    'slate'    => ['label' => 'Cinza',    'classes' => 'bg-slate-500/20 text-slate-300 border-slate-500/40', 'dot' => 'bg-slate-400'],
];

// Cor da etiqueta: chave da paleta OU código livre #RRGGBB (escolhido no seletor de cor)
function tagToHex($ck) {   // etiquetas antigas (cor da paleta) -> código de cor, para abrir no seletor
    static $m = ['emerald'=>'#34D399','cyan'=>'#22D3EE','amber'=>'#FBBF24','purple'=>'#C084FC','rose'=>'#FB7185','indigo'=>'#818CF8','sky'=>'#38BDF8','pink'=>'#F472B6','red'=>'#F87171','orange'=>'#FB923C','yellow'=>'#FACC15','lime'=>'#A3E635','green'=>'#4ADE80','teal'=>'#2DD4BF','blue'=>'#60A5FA','violet'=>'#A78BFA','fuchsia'=>'#E879F9','slate'=>'#94A3B8'];
    return tagIsHex($ck) ? strtoupper($ck) : ($m[$ck] ?? '#10B981');
}
function tagIsHex($ck) { return (bool)preg_match('/^#[0-9A-Fa-f]{6}$/', (string)$ck); }
function tagCls($ck, $palette, $fallback = 'bg-slate-800 text-slate-400 border-slate-700') {
    if (tagIsHex($ck)) return '';
    return isset($palette[$ck]) ? $palette[$ck]['classes'] : $fallback;
}
function tagSty($ck) {
    if (!tagIsHex($ck)) return '';
    $r = hexdec(substr($ck, 1, 2)); $g = hexdec(substr($ck, 3, 2)); $b = hexdec(substr($ck, 5, 2));
    $tx = sprintf('#%02x%02x%02x', (int)($r + (255 - $r) * .6), (int)($g + (255 - $g) * .6), (int)($b + (255 - $b) * .6));
    return "background:rgba($r,$g,$b,.2);color:$tx;border-color:rgba($r,$g,$b,.4)";
}

// ─── PROCESSAMENTO DE FORMULÁRIOS ─────────────────────────────────────────────
$req = $_SERVER['REQUEST_METHOD'] === 'POST';

// -- Criar / Editar Etiqueta (com edit_tag_id = editar nome e cor)
if ($req && isset($_POST['action_create_tag'])) {
    $tname = trim($_POST['new_tag_name'] ?? '');
    $tclr  = trim($_POST['new_tag_color'] ?? 'emerald');
    $thex  = trim($_POST['new_tag_hex'] ?? '');
    if ($thex !== '') { if ($thex[0] !== '#') $thex = '#' . $thex; if (tagIsHex($thex)) $tclr = strtoupper($thex); else $tclr = ''; }
    $teid  = intval($_POST['edit_tag_id'] ?? 0);
    if ($tname === '' || (!isset($color_palette[$tclr]) && !tagIsHex($tclr))) {
        $msg_error = 'Informe o nome e escolha uma cor válida para a etiqueta (código no formato #RRGGBB).';
    } elseif ($teid) {
        try {
            $st = $db->prepare("SELECT name FROM contact_tags WHERE id = :id"); $st->execute([':id' => $teid]);
            $old = $st->fetchColumn();
            $dup = $db->prepare("SELECT COUNT(*) FROM contact_tags WHERE name = :n AND id <> :id"); $dup->execute([':n' => $tname, ':id' => $teid]);
            if ($old === false) { $msg_error = 'Etiqueta não encontrada.'; }
            elseif ((int)$dup->fetchColumn() > 0) { $msg_error = "Já existe outra etiqueta chamada '$tname'."; }
            else {
                $db->prepare("UPDATE contact_tags SET name = :n, color_key = :c WHERE id = :id")->execute([':n' => $tname, ':c' => $tclr, ':id' => $teid]);
                if ($old !== $tname) $db->prepare("UPDATE crm_contacts SET tag = :n WHERE tag = :o")->execute([':n' => $tname, ':o' => $old]);   // os contatos acompanham o novo nome
                if (function_exists('logUserAction')) logUserAction('Etiqueta de contato editada', "$old → $tname", 'CRM');
                $msg_status = "Etiqueta '$tname' atualizada.";
            }
        } catch (Exception $e) { $msg_error = "Erro ao editar etiqueta: " . $e->getMessage(); }
    } else {
        try {
            $db->prepare("INSERT OR REPLACE INTO contact_tags (name, color_key) VALUES (:n, :c)")
               ->execute([':n' => $tname, ':c' => $tclr]);
            if (function_exists('logUserAction')) logUserAction('Etiqueta de contato criada', $tname, 'CRM');
            $msg_status = "Etiqueta '$tname' criada com sucesso!";
        } catch (Exception $e) { $msg_error = "Erro ao criar etiqueta: " . $e->getMessage(); }
    }
}

// -- Apagar Etiqueta (os contatos que a usavam ficam sem etiqueta; nenhum contato é apagado)
if ($req && isset($_POST['action_delete_tag'])) {
    $tid = intval($_POST['delete_tag_id'] ?? 0);
    try {
        $st = $db->prepare("SELECT name FROM contact_tags WHERE id = :id"); $st->execute([':id' => $tid]);
        $tn = $st->fetchColumn();
        if ($tn !== false) {
            $db->prepare("DELETE FROM contact_tags WHERE id = :id")->execute([':id' => $tid]);
            $db->prepare("UPDATE crm_contacts SET tag = '' WHERE tag = :n")->execute([':n' => $tn]);
            if (function_exists('logUserAction')) logUserAction('Etiqueta de contato apagada', $tn, 'CRM');
            $msg_status = "Etiqueta '$tn' apagada. Os contatos que a usavam ficaram sem etiqueta.";
        }
    } catch (Exception $e) { $msg_error = "Erro ao apagar etiqueta: " . $e->getMessage(); }
}

// -- Criar Contato
if ($req && isset($_POST['action_add_contact'])) {
    $n = trim($_POST['c_name'] ?? ''); $p = trim($_POST['c_phone'] ?? '');
    $e = trim($_POST['c_email'] ?? ''); $co = trim($_POST['c_company'] ?? '');
    $t = trim($_POST['c_tag'] ?? ''); $no = trim($_POST['c_notes'] ?? '');
    if ($n && $p && ($dupId = crmFindDuplicate($db, $p))) {
        $msg_error = "Esse número já está cadastrado (contato #$dupId). Não foi criado outro.";
    } elseif ($n && $p) {
        try {
            $db->prepare("INSERT INTO crm_contacts (name, phone, email, company, tag, source, notes) VALUES (:n,:p,:e,:co,:t,'Manual',:no)")
               ->execute([':n'=>$n,':p'=>$p,':e'=>$e,':co'=>$co,':t'=>$t,':no'=>$no]);

            $msg_status = "Contato '$n' adicionado com sucesso!";
        } catch (Exception $e2) { $msg_error = "Erro: " . $e2->getMessage(); }
    }
}

// -- Editar Contato
if ($req && isset($_POST['action_edit_contact'])) {
    $cid = intval($_POST['edit_c_id'] ?? 0);
    $n = trim($_POST['edit_c_name'] ?? ''); $p = trim($_POST['edit_c_phone'] ?? '');
    $e = trim($_POST['edit_c_email'] ?? ''); $co = trim($_POST['edit_c_company'] ?? '');
    $t = trim($_POST['edit_c_tag'] ?? ''); $no = trim($_POST['edit_c_notes'] ?? '');
    if ($cid && $n && $p) {
        try {
            $db->prepare("UPDATE crm_contacts SET name=:n, phone=:p, email=:e, company=:co, tag=:t, notes=:no, updated_at=CURRENT_TIMESTAMP WHERE id=:id")
               ->execute([':n'=>$n,':p'=>$p,':e'=>$e,':co'=>$co,':t'=>$t,':no'=>$no,':id'=>$cid]);

            $msg_status = "Contato '$n' atualizado com sucesso!";
        } catch (Exception $e2) { $msg_error = "Erro ao atualizar: " . $e2->getMessage(); }
    }
}

// -- Excluir Contato
if ($req && isset($_POST['action_delete_contact'])) {
    $cid = intval($_POST['delete_c_id'] ?? 0);
    if ($cid) {
        $db->prepare("DELETE FROM crm_contacts WHERE id=:id")->execute([':id' => $cid]);
        $msg_status = "Contato removido.";
    }
}

// -- Criar/Editar Mensagem Rápida
if ($req && isset($_POST['action_save_qmsg'])) {
    $qid  = intval($_POST['qmsg_id'] ?? 0);
    $qt   = trim($_POST['qmsg_title'] ?? '');
    $qb   = trim($_POST['qmsg_body'] ?? '');
    $qcat = trim($_POST['qmsg_category'] ?? 'Geral');
    if ($qt && $qb) {
        if ($qid) {
            $db->prepare("UPDATE quick_messages SET title=:t, body=:b, category=:c, updated_at=CURRENT_TIMESTAMP WHERE id=:id")
               ->execute([':t'=>$qt,':b'=>$qb,':c'=>$qcat,':id'=>$qid]);
        } else {
            $db->prepare("INSERT INTO quick_messages (title, body, category) VALUES (:t,:b,:c)")
               ->execute([':t'=>$qt,':b'=>$qb,':c'=>$qcat]);
        }
        $msg_status = "Mensagem rápida salva!";
    }
}

// -- Excluir Mensagem Rápida
if ($req && isset($_POST['action_delete_qmsg'])) {
    $qid = intval($_POST['delete_qmsg_id'] ?? 0);
    if ($qid) {
        $db->prepare("DELETE FROM quick_messages WHERE id=:id")->execute([':id' => $qid]);
        $msg_status = "Mensagem rápida removida.";
    }
}

// -- Importar via API Prismabot (real)
if ($req && isset($_POST['action_sync_prismabot'])) {
    $api_url   = trim((string)getSetting('api_url'));
    $api_token = trim(preg_replace('/^bearer\s+/i', '', trim((string)getSetting('api_token'))));
    if (!$api_url || !$api_token) {
        $msg_error = "Configure a URL e o Token da API Prismabot em Configurações > API antes de importar.";
    } elseif (!preg_match('#^(https?://.+?/v2/api/external/[^/\s?]+)#i', $api_url, $mm)) {
        $msg_error = "A URL da API deve ter o formato https://seu-servidor/v2/api/external/ID. Ajuste em Configurações > API.";
    } else {
        $search_url = $mm[1] . '/contacts/search';
        $imported = 0; $seen = 0; $page = 1; $err = ''; $unnamed = 0; $withUnnamed = !empty($_POST['include_unnamed']);
        do {
            $ch = curl_init($search_url);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true, CURLOPT_POST => true, CURLOPT_TIMEOUT => 30,
                CURLOPT_POSTFIELDS => json_encode(['searchParam' => '', 'page' => $page, 'limit' => 200]),
                CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'Authorization: Bearer ' . $api_token],
                CURLOPT_SSL_VERIFYPEER => false,
            ]);
            $result = curl_exec($ch);
            $http_c = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $cerr   = curl_error($ch);
            curl_close($ch);
            if ($cerr) { $err = "Erro de conexão: $cerr"; break; }
            if ($http_c === 401 || $http_c === 403) { $err = "Token recusado (HTTP $http_c). Confira o token em Configurações > API."; break; }
            if ($http_c === 404) { $err = "Endereço não encontrado (HTTP 404). Confira a URL e o ID da API em Configurações > API."; break; }
            if ($http_c < 200 || $http_c >= 300) { $err = "A API respondeu HTTP $http_c."; break; }
            $data = json_decode($result, true);
            $list = is_array($data) ? ($data['data'] ?? $data['contacts'] ?? (isset($data[0]) ? $data : [])) : [];
            if (!is_array($list)) $list = [];
            foreach ($list as $ci) {
                $seen++;
                $cn = trim((string)($ci['name'] ?? $ci['pushName'] ?? ''));
                $cp = preg_replace('/\D/', '', (string)($ci['number'] ?? $ci['phone'] ?? ''));
                if (strlen($cp) >= 10 && strlen($cp) <= 15) {
                    $semNome = ($cn === '' || preg_replace('/\D/', '', $cn) === $cn || preg_replace('/\D/', '', $cn) === $cp);
                    try {
                        if (!crmFindDuplicate($db, $cp)) {
                            if ($semNome && !$withUnnamed) { $unnamed++; continue; }
                            $db->prepare("INSERT INTO crm_contacts (name, phone, source) VALUES (:n,:p,'Prismabot')")->execute([':n' => $semNome ? $cp : $cn, ':p' => $cp]);
                            $imported++;
                        }
                    } catch (Exception $e) {}
                }
            }
            $pages = (int)($data['pagination']['totalPages'] ?? $data['pagination']['pages'] ?? 1);
            $page++;
        } while (!$err && $page <= $pages && $page <= 100);
        if ($err) $msg_error = $err . ($imported ? " ($imported já importados.)" : '');
        else {
            if ($unnamed > 0) $ask_unnamed = $unnamed;
            $msg_status = ($unnamed > 0 ? "$unnamed contato(s) sem nome foram ignorados. " : '') . ($imported > 0 ? "✅ $imported contatos novos importados." : ($seen ? "Nenhum contato novo: os $seen retornados já existem ou não têm nome/telefone válidos." : "A API não retornou contatos."));
        }
    }
}

// -- Importar CSV (inteligente): upload -> prévia -> importar
$csv_preview = null;
if ($req && isset($_POST['action_csv'])) {
    $step = $_POST['csv_step'] ?? 'upload';
    $tokenOk = false; $tmpf = '';
    if ($step === 'upload') {
        $f = $_FILES['csv_file'] ?? null;
        if (!$f || ($f['error'] ?? 4) !== UPLOAD_ERR_OK) $msg_error = 'Escolha um arquivo CSV para importar.';
        elseif ($f['size'] > 5 * 1024 * 1024) $msg_error = 'Arquivo muito grande (máximo 5 MB).';
        elseif (!preg_match('/\.(csv|txt)$/i', $f['name'])) $msg_error = 'O arquivo precisa ser .csv (no Excel: Salvar como > CSV).';
        else {
            $token = bin2hex(random_bytes(8));
            $tmpf = sys_get_temp_dir() . '/ipbx_csv_' . $token . '.csv';
            if (move_uploaded_file($f['tmp_name'], $tmpf)) { $tokenOk = true; @chmod($tmpf, 0600); } else $msg_error = 'Não foi possível ler o arquivo enviado.';
        }
    } else {
        $token = (string)($_POST['csv_token'] ?? '');
        if (preg_match('/^[a-f0-9]{16}$/', $token) && is_file(sys_get_temp_dir() . '/ipbx_csv_' . $token . '.csv')) { $tmpf = sys_get_temp_dir() . '/ipbx_csv_' . $token . '.csv'; $tokenOk = true; }
        else $msg_error = 'A prévia expirou. Envie o arquivo novamente.';
    }
    if ($tokenOk) {
        $rows = csvContactsRead(file_get_contents($tmpf));
        if (count($rows) < 2) { $msg_error = 'O arquivo está vazio ou só tem o cabeçalho.'; @unlink($tmpf); }
        elseif (count($rows) > 20000) { $msg_error = 'O arquivo tem mais de 20.000 linhas. Divida em partes menores.'; @unlink($tmpf); }
        else {
            $headers = $rows[0];
            $map = csvContactsGuessMap($headers);
            if (isset($_POST['map'])) foreach (['name','phone','email','company','tag'] as $fld) if (isset($_POST['map'][$fld])) $map[$fld] = max(-1, min(count($headers) - 1, (int)$_POST['map'][$fld]));
            $cls = csvContactsClassify($db, $rows, $map, true);
            $withUnnamed = !empty($_POST['include_unnamed']);
            if ($step === 'import') {
                $toAdd = $cls['ok'] + [];
                if ($withUnnamed) $toAdd = array_merge($cls['ok'], $cls['sem_nome']);
                $added = 0;
                $db->beginTransaction();
                try {
                    $ins = $db->prepare("INSERT INTO crm_contacts (name, phone, email, company, tag, source) VALUES (:n,:p,:e,:co,:t,'Importação CSV')");
                    foreach ($toAdd as $c) {
                        if (crmFindDuplicate($db, $c['phone'])) continue;
                        if ($c['tag'] !== '') { $db->prepare("INSERT OR IGNORE INTO contact_tags (name, color_key) VALUES (:n,'emerald')")->execute([':n' => $c['tag']]); }
                        $ins->execute([':n' => $c['name'] !== '' ? $c['name'] : $c['phone'], ':p' => $c['phone'], ':e' => $c['email'], ':co' => $c['company'], ':t' => $c['tag']]);
                        $added++;
                    }
                    $db->commit();
                } catch (Exception $e3) { $db->rollBack(); $msg_error = 'Erro ao importar: ' . $e3->getMessage(); $added = -1; }
                @unlink($tmpf);
                if ($added >= 0) {
                    try { AddressBookSync::reconcile($db, true); } catch (Throwable $e_abs) {}
                    $skip = [];
                    if (!$withUnnamed && $cls['sem_nome']) $skip[] = count($cls['sem_nome']) . ' sem nome';
                    if ($cls['invalido']) $skip[] = count($cls['invalido']) . ' com número inválido';
                    if ($cls['ja_existe']) $skip[] = count($cls['ja_existe']) . ' já cadastrados';
                    if ($cls['repetido_arquivo']) $skip[] = count($cls['repetido_arquivo']) . ' repetidos no arquivo';
                    $msg_status = "✅ $added contatos importados do CSV." . ($skip ? ' Ignorados: ' . implode(', ', $skip) . '.' : '');
                }
            } else {
                $csv_preview = ['token' => $token, 'headers' => $headers, 'map' => $map, 'cls' => $cls, 'total' => count($rows) - 1, 'unnamed' => $withUnnamed];
            }
        }
    }
}

// -- Excluir contatos em lote (marcados na tabela)
if ($req && isset($_POST['action_bulk_delete_contacts'])) {
    $ids = array_values(array_unique(array_filter(array_map('intval', (array)($_POST['bulk_ids'] ?? [])))));
    if ($ids) {
        $del = $db->prepare("DELETE FROM crm_contacts WHERE id = :id");
        $n = 0;
        $db->beginTransaction();
        foreach ($ids as $i) { $del->execute([':id' => $i]); $n += $del->rowCount(); }
        $db->commit();
        $msg_status = $n . ($n === 1 ? " contato removido." : " contatos removidos.");
    }
}

// Mantém a agenda do painel clássico igual à deste painel (e vice-versa)
try { AddressBookSync::reconcile($db, true); } catch (Throwable $e_abs) {}

// ─── Carregar dados do SQLite ──────────────────────────────────────────────────
$tags_list    = $db->query("SELECT * FROM contact_tags ORDER BY name ASC")->fetchAll(PDO::FETCH_ASSOC);
$contacts_list= $db->query("SELECT * FROM crm_contacts ORDER BY name ASC")->fetchAll(PDO::FETCH_ASSOC);
$quick_msgs   = $db->query("SELECT * FROM quick_messages ORDER BY category ASC, title ASC")->fetchAll(PDO::FETCH_ASSOC);

// Etiquetas padrão: criadas só na primeira vez (se o cliente apagar uma, ela não volta sozinha)
if (getSetting('contact_tags_seeded') !== '1') {
    $default_tags = ['VIP'=>'emerald','Comercial'=>'cyan','Financeiro'=>'amber','Suporte'=>'purple','Inadimplente'=>'rose','Novo Lead'=>'indigo'];
    foreach ($default_tags as $dn => $dc) {
        try { $db->prepare("INSERT OR IGNORE INTO contact_tags (name, color_key) VALUES (:n,:c)")->execute([':n'=>$dn,':c'=>$dc]); } catch (Exception $e) {}
    }
    saveSetting('contact_tags_seeded', '1');
}
$tags_list = $db->query("SELECT * FROM contact_tags ORDER BY name ASC")->fetchAll(PDO::FETCH_ASSOC);

// Mapa de cores por nome de etiqueta
$tag_color_map = []; $tag_style_map = [];
foreach ($tags_list as $tl) {
    $ck = $tl['color_key'];
    $tag_color_map[$tl['name']] = tagCls($ck, $color_palette, 'bg-slate-800 text-slate-300 border-slate-700');
    $tag_style_map[$tl['name']] = tagSty($ck);
}

// Aba ativa
$active_tab = isset($_GET['crm_tab']) ? $_GET['crm_tab'] : 'contacts';
?>

<div class="space-y-6">

    <!-- Feedback Banner -->
    <?php if ($msg_status): ?>
        <div class="p-4 bg-emerald-500/10 border border-emerald-500/30 text-emerald-300 rounded-xl text-xs font-bold flex items-center gap-2">
            <i class="fa-solid fa-circle-check text-base"></i> <?php echo htmlspecialchars($msg_status); ?>
        </div>
    <?php endif; ?>
    <?php if ($msg_error): ?>
        <div class="p-4 bg-rose-500/10 border border-rose-500/30 text-rose-300 rounded-xl text-xs font-bold flex items-center gap-2">
            <i class="fa-solid fa-triangle-exclamation text-base"></i> <?php echo htmlspecialchars($msg_error); ?>
        </div>
    <?php endif; ?>

    <!-- Header + Ações -->
    <div class="bg-slate-900/90 border border-slate-800 rounded-2xl p-5 shadow-xl space-y-4">
        <div class="flex flex-col lg:flex-row items-center justify-between gap-4">
            <div>
                <h3 class="text-base font-extrabold text-white flex items-center gap-2.5">
                    <i class="fa-solid fa-address-book text-emerald-400 text-lg"></i> Agenda de Contatos & CRM Prismabot
                </h3>
                <span class="text-xs text-slate-400">Etiquetas personalizáveis, histórico, importação via API e mensagens rápidas</span>
            </div>

            <div class="flex items-center gap-2 flex-wrap">
                <!-- Criar Etiqueta -->
                <button onclick="openModal('modal-create-tag')" class="px-3 py-2 bg-slate-800 hover:bg-slate-700 text-amber-300 border border-slate-700 rounded-xl text-xs font-bold transition flex items-center gap-1.5">
                    <i class="fa-solid fa-tags text-amber-400"></i> Etiquetas
                </button>

                <!-- Importar Prismabot (abre a janela de opções) -->
                <button type="button" onclick="openModal('modal-import-prismabot')" class="px-3.5 py-2 bg-emerald-600 hover:bg-emerald-500 text-white rounded-xl text-xs font-bold transition shadow-lg shadow-emerald-600/20 flex items-center gap-1.5">
                    <i class="fa-brands fa-whatsapp"></i> Importar Prismabot
                </button>

                <button type="button" onclick="openModal('modal-import-csv')" class="px-3.5 py-2 bg-slate-800 hover:bg-slate-700 text-slate-200 border border-slate-700 rounded-xl text-xs font-bold transition flex items-center gap-1.5">
                    <i class="fa-solid fa-file-import text-sky-400"></i> Importar CSV
                </button>

                <!-- Exportar CSV -->
                <button onclick="exportContactsToCSV()" class="px-3 py-2 bg-slate-800 hover:bg-slate-700 text-slate-300 border border-slate-700 rounded-xl text-xs font-bold transition flex items-center gap-1.5">
                    <i class="fa-solid fa-file-excel text-emerald-400"></i> Exportar CSV
                </button>

                <!-- Novo Contato -->
                <button onclick="openModal('modal-add-contact')" class="px-3.5 py-2 bg-brand-600 hover:bg-brand-500 text-white rounded-xl text-xs font-bold transition flex items-center gap-1.5">
                    <i class="fa-solid fa-user-plus"></i> Novo Contato
                </button>
            </div>
        </div>

        <!-- Sub-tabs -->
        <div class="flex items-center gap-1 bg-slate-950 p-1 rounded-xl border border-slate-800 text-xs font-bold w-fit">
            <a href="index.php?module=whatsapp&action=contatos&crm_tab=contacts"
               class="px-3.5 py-1.5 rounded-lg transition <?php echo $active_tab === 'contacts' ? 'bg-brand-600 text-white' : 'text-slate-400 hover:text-white'; ?> flex items-center gap-1.5">
                <i class="fa-solid fa-users"></i> Contatos (<?php echo count($contacts_list); ?>)
            </a>
            <a href="index.php?module=whatsapp&action=contatos&crm_tab=quick_msgs"
               class="px-3.5 py-1.5 rounded-lg transition <?php echo $active_tab === 'quick_msgs' ? 'bg-brand-600 text-white' : 'text-slate-400 hover:text-white'; ?> flex items-center gap-1.5">
                <i class="fa-solid fa-bolt"></i> Mensagens Rápidas (<?php echo count($quick_msgs); ?>)
            </a>
        </div>

        <!-- Filtros (só na aba contatos) -->
        <?php if ($active_tab === 'contacts'): ?>
        <div class="flex flex-col sm:flex-row items-center justify-between gap-3 pt-3 border-t border-slate-800">
            <div class="flex items-center gap-1.5 flex-wrap text-xs font-bold">
                <span class="text-slate-400 mr-1">Etiqueta:</span>
                <button onclick="filterByTag('ALL')" class="px-2.5 py-1 rounded-lg bg-slate-800 text-white hover:bg-slate-700 transition">Todas</button>
                <?php foreach ($tags_list as $tl):
                    $ck  = $tl['color_key'];
                    $cls = tagCls($ck, $color_palette); $sty = tagSty($ck);
                ?>
                <button onclick="filterByTag('<?php echo htmlspecialchars($tl['name']); ?>')"
                        class="px-2.5 py-1 rounded-lg border <?php echo $cls; ?> hover:opacity-80 transition" style="<?php echo htmlspecialchars($sty); ?>">
                    🏷️ <?php echo htmlspecialchars($tl['name']); ?>
                </button>
                <?php endforeach; ?>
            </div>
            <div class="relative w-full sm:w-64">
                <i class="fa-solid fa-magnifying-glass absolute left-3.5 top-3 text-slate-400 text-xs"></i>
                <input type="text" id="contact-search" onkeyup="filterContacts()" placeholder="Buscar por nome, telefone ou empresa..."
                       class="w-full pl-9 pr-4 py-2 bg-slate-950 border border-slate-800 rounded-xl text-xs text-white focus:outline-none focus:border-emerald-500 font-mono">
            </div>
        </div>
        <?php endif; ?>
    </div>

    <!-- ──────────────────────────── TAB: CONTATOS ──────────────────────────── -->
    <?php if ($active_tab === 'contacts'): ?>
    <!-- Barra de exclusão em lote -->
    <form id="bulk-delete-form" method="POST" action="index.php?module=whatsapp&action=contatos" onsubmit="return confirmBulkDelete()" class="flex flex-wrap items-center gap-2 text-xs font-bold">
        <input type="hidden" name="action_bulk_delete_contacts" value="1">
        <span id="bulk-ids-holder"></span>
        <button type="button" onclick="selectAllContacts(false)" id="bulk-clear" class="px-3 py-1.5 bg-slate-800 hover:bg-slate-700 text-slate-300 border border-slate-700 rounded-lg transition hidden">Limpar seleção</button>
        <button type="submit" id="bulk-delete-btn" class="px-3 py-1.5 bg-rose-600 hover:bg-rose-500 text-white rounded-lg transition hidden"><i class="fa-solid fa-trash"></i> <span id="bulk-count-label">Apagar selecionados</span></button>
    </form>
    <div class="bg-slate-900/90 border border-slate-800 rounded-3xl shadow-2xl overflow-hidden">
        <div class="overflow-x-auto custom-scrollbar">
            <table class="w-full text-left text-xs text-slate-300">
                <thead class="bg-slate-950 uppercase text-[9px] font-bold text-slate-400 tracking-wider">
                    <tr>
                        <th class="p-3 w-8"><input type="checkbox" id="chk-all-contacts" onchange="toggleAllContacts(this.checked)" title="Selecionar todos os contatos visíveis" class="accent-rose-500 w-4 h-4 cursor-pointer"></th>
                        <th class="p-3">Cliente / Nome</th>
                        <th class="p-3">Telefone / WhatsApp</th>
                        <th class="p-3">E-mail</th>
                        <th class="p-3">Empresa</th>
                        <th class="p-3">Etiqueta</th>
                        <th class="p-3">Origem</th>
                        <th class="p-3 text-right">Ações</th>
                    </tr>
                </thead>
                <tbody id="contacts-table-body" class="divide-y divide-slate-800/80 font-mono text-[11px]">
                    <?php if (empty($contacts_list)): ?>
                        <tr><td colspan="8" class="p-8 text-center text-slate-500">
                            <i class="fa-solid fa-address-book text-2xl block mb-2 opacity-30"></i>
                            Nenhum contato cadastrado. Clique em <strong class="text-white">Novo Contato</strong> para adicionar.
                        </td></tr>
                    <?php else: ?>
                    <?php foreach ($contacts_list as $c):
                        $tclss = isset($tag_color_map[$c['tag']]) ? $tag_color_map[$c['tag']] : 'bg-slate-800 text-slate-400 border-slate-700'; $tstyl = $tag_style_map[$c['tag']] ?? '';
                        $srcCls = ($c['source'] === 'Prismabot' || $c['source'] === 'API Z-PRO') ? 'bg-emerald-500/20 text-emerald-300 border border-emerald-500/30' : 'bg-slate-800 text-slate-400';
                    ?>
                    <tr class="contact-row hover:bg-slate-800/40 transition" data-tag="<?php echo htmlspecialchars($c['tag']); ?>"
                        data-name="<?php echo htmlspecialchars(strtolower($c['name'])); ?>"
                        data-phone="<?php echo htmlspecialchars($c['phone']); ?>"
                        data-company="<?php echo htmlspecialchars(strtolower($c['company'])); ?>"
                        data-source="<?php echo htmlspecialchars((string)$c['source']); ?>">
                        <td class="p-3"><input type="checkbox" class="chk-contact accent-rose-500 w-4 h-4 cursor-pointer" value="<?php echo (int)$c['id']; ?>" onchange="updateBulkBar()"></td>
                        <td class="p-3 font-extrabold text-white">
                            <?php 
                            $tooltip = "Contato CRM\nTelefone: " . $c['phone'] . "\nEmpresa: " . ($c['company'] ?: 'N/D') . "\nE-mail: " . ($c['email'] ?: 'N/D') . "\nEtiqueta: " . ($c['tag'] ?: 'N/D') . "\nObs: " . ($c['notes'] ?: 'N/D');
                            ?>
                            <div class="group relative inline-block">
                                <a href="javascript:void(0)" onclick='openEditContactModal(<?php echo json_encode(['id'=>$c['id'],'name'=>$c['name'],'phone'=>$c['phone'],'email'=>$c['email'],'company'=>$c['company'],'tag'=>$c['tag'],'notes'=>$c['notes']]); ?>)'
                                   title="<?php echo htmlspecialchars($tooltip); ?>"
                                   class="flex items-center gap-2 hover:bg-slate-800/50 p-1.5 -ml-1.5 rounded-lg transition">
                                    <div class="w-7 h-7 rounded-full bg-slate-800 border border-slate-700 text-emerald-400 flex items-center justify-center font-bold text-xs shrink-0">
                                        <?php echo strtoupper(mb_substr($c['name'], 0, 1)); ?>
                                    </div>
                                    <span><?php echo htmlspecialchars($c['name']); ?></span>
                                    <i class="fa-solid fa-pen-to-square text-amber-400 opacity-0 group-hover:opacity-100 transition text-[10px] ml-1"></i>
                                </a>
                            </div>
                        </td>
                        <td class="p-3 font-mono font-bold text-emerald-400"><?php echo htmlspecialchars($c['phone']); ?></td>
                        <td class="p-3 text-slate-300 max-w-[120px] truncate"><?php echo htmlspecialchars($c['email'] ?: '—'); ?></td>
                        <td class="p-3 text-slate-200"><?php echo htmlspecialchars($c['company'] ?: '—'); ?></td>
                        <td class="p-3">
                            <?php if ($c['tag']): ?>
                                <span class="px-2.5 py-0.5 rounded-md text-[10px] font-bold border <?php echo $tclss; ?>" style="<?php echo htmlspecialchars($tstyl); ?>">🏷️ <?php echo htmlspecialchars($c['tag']); ?></span>
                            <?php else: ?>
                                <span class="text-slate-600">—</span>
                            <?php endif; ?>
                        </td>
                        <td class="p-3">
                            <span class="px-2 py-0.5 rounded text-[9px] font-bold <?php echo $srcCls; ?>">
                                <?php echo htmlspecialchars(aiBrandScrub((string)$c['source'])); ?>
                            </span>
                        </td>
                        <td class="p-3 text-right">
                            <div class="flex items-center justify-end gap-1.5">
                                <!-- Editar -->
                                <button onclick='openEditContactModal(<?php echo json_encode(['id'=>$c['id'],'name'=>$c['name'],'phone'=>$c['phone'],'email'=>$c['email'],'company'=>$c['company'],'tag'=>$c['tag'],'notes'=>$c['notes']]); ?>)'
                                        class="px-2.5 py-1 bg-amber-600/20 hover:bg-amber-600/40 text-amber-300 border border-amber-500/40 rounded-lg font-bold text-[10px] transition">
                                    <i class="fa-solid fa-pen-to-square"></i> Editar
                                </button>
                                <!-- Chamar -->
                                <button onclick="originateCall('<?php echo $c['phone']; ?>','<?php echo htmlspecialchars($c['name']); ?>')"
                                        class="px-2.5 py-1 bg-emerald-600/20 hover:bg-emerald-600/40 text-emerald-300 border border-emerald-500/40 rounded-lg font-bold text-[10px] transition">
                                    <i class="fa-solid fa-phone"></i>
                                </button>
                                <!-- WhatsApp Rápido -->
                                <button onclick="openFastWhatsAppModal('<?php echo $c['phone']; ?>','<?php echo htmlspecialchars($c['name']); ?>')"
                                        class="px-2.5 py-1 bg-cyan-600/20 hover:bg-cyan-600/40 text-cyan-300 border border-cyan-500/40 rounded-lg font-bold text-[10px] transition">
                                    <i class="fa-brands fa-whatsapp"></i>
                                </button>
                                <!-- Excluir -->
                                <form method="POST" action="index.php?module=whatsapp&action=contatos" onsubmit="return confirm('Excluir contato «<?php echo htmlspecialchars($c['name']); ?>»?');" class="inline">
                                    <input type="hidden" name="action_delete_contact" value="1">
                                    <input type="hidden" name="delete_c_id" value="<?php echo $c['id']; ?>">
                                    <button type="submit" class="px-2.5 py-1 bg-rose-600/20 hover:bg-rose-600/40 text-rose-400 border border-rose-500/40 rounded-lg font-bold text-[10px] transition">
                                        <i class="fa-solid fa-trash"></i>
                                    </button>
                                </form>
                            </div>
                        </td>
                    </tr>
                    <?php endforeach; endif; ?>
                </tbody>
            </table>
        </div>
    </div>
    <?php endif; ?>

    <!-- ──────────────────────────── TAB: MENSAGENS RÁPIDAS ──────────────────── -->
    <?php if ($active_tab === 'quick_msgs'): ?>
    <div class="space-y-4">
        <!-- Botão Adicionar -->
        <div class="flex justify-end">
            <button onclick="openModal('modal-qmsg')" class="px-4 py-2 bg-brand-600 hover:bg-brand-500 text-white rounded-xl text-xs font-bold transition flex items-center gap-2">
                <i class="fa-solid fa-plus"></i> Nova Mensagem Rápida
            </button>
        </div>

        <?php if (empty($quick_msgs)): ?>
            <div class="flex flex-col items-center justify-center py-16 bg-slate-900/90 border border-slate-800 rounded-2xl">
                <i class="fa-solid fa-bolt text-slate-600 text-4xl mb-3"></i>
                <p class="text-slate-400 font-bold">Nenhuma mensagem rápida cadastrada</p>
                <p class="text-slate-600 text-xs mt-1">Crie mensagens pré-definidas para envio rápido no WhatsApp.</p>
            </div>
        <?php else: ?>
        <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-4">
            <?php
            $cats = array_unique(array_column($quick_msgs, 'category'));
            foreach ($quick_msgs as $qm):
            ?>
            <div class="bg-slate-900/90 border border-slate-800 hover:border-slate-700 rounded-2xl p-4 space-y-3 transition group">
                <div class="flex items-start justify-between">
                    <div>
                        <span class="text-xs font-extrabold text-white block"><?php echo htmlspecialchars($qm['title']); ?></span>
                        <span class="text-[10px] font-mono text-slate-500 block"><?php echo htmlspecialchars($qm['category']); ?></span>
                    </div>
                    <div class="flex gap-1.5 opacity-0 group-hover:opacity-100 transition">
                        <button onclick='openEditQmsgModal(<?php echo json_encode(['id'=>$qm['id'],'title'=>$qm['title'],'body'=>$qm['body'],'category'=>$qm['category']]); ?>)'
                                class="p-1.5 bg-amber-600/20 hover:bg-amber-600 text-amber-300 hover:text-white rounded-lg text-xs transition">
                            <i class="fa-solid fa-pen-to-square"></i>
                        </button>
                        <form method="POST" action="index.php?module=whatsapp&action=contatos&crm_tab=quick_msgs" onsubmit="return confirm('Excluir?');" class="inline">
                            <input type="hidden" name="action_delete_qmsg" value="1">
                            <input type="hidden" name="delete_qmsg_id" value="<?php echo $qm['id']; ?>">
                            <button type="submit" class="p-1.5 bg-rose-600/20 hover:bg-rose-600 text-rose-400 hover:text-white rounded-lg text-xs transition">
                                <i class="fa-solid fa-trash"></i>
                            </button>
                        </form>
                    </div>
                </div>
                <p class="text-xs text-slate-300 leading-relaxed line-clamp-3"><?php echo nl2br(htmlspecialchars($qm['body'])); ?></p>
                <button onclick="copyQuickMsg('<?php echo htmlspecialchars(addslashes($qm['body'])); ?>')"
                        class="w-full py-1.5 bg-slate-800 hover:bg-slate-700 text-slate-300 hover:text-white rounded-xl text-[10px] font-bold transition flex items-center justify-center gap-1.5">
                    <i class="fa-solid fa-copy"></i> Copiar Mensagem
                </button>
            </div>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>
    </div>
    <?php endif; ?>
</div>

<!-- ──────────────────────────────── MODAIS ──────────────────────────────────── -->

<!-- Modal: Criar Contato -->
<div id="modal-add-contact" class="fixed inset-0 z-50 hidden bg-slate-950/80 backdrop-blur-md flex items-center justify-center p-4">
    <div class="bg-slate-900 border border-slate-700 rounded-2xl w-full max-w-lg p-6 shadow-2xl space-y-4">
        <div class="flex items-center justify-between border-b border-slate-800 pb-3">
            <h4 class="text-sm font-extrabold text-white flex items-center gap-2"><i class="fa-solid fa-user-plus text-emerald-400"></i> Novo Contato</h4>
            <button onclick="closeModal('modal-add-contact')" class="text-slate-400 hover:text-white"><i class="fa-solid fa-xmark"></i></button>
        </div>
        <form method="POST" action="index.php?module=whatsapp&action=contatos" class="space-y-3 text-xs">
            <input type="hidden" name="action_add_contact" value="1">
            <div class="grid grid-cols-2 gap-3">
                <div class="col-span-2">
                    <label class="font-bold text-slate-300 block mb-1">Nome *</label>
                    <input name="c_name" required placeholder="Nome completo" class="w-full px-3 py-2.5 bg-slate-950 border border-slate-800 rounded-xl text-white focus:border-emerald-500 focus:outline-none">
                </div>
                <div>
                    <label class="font-bold text-slate-300 block mb-1">Telefone / WhatsApp *</label>
                    <input name="c_phone" required placeholder="5511999998888" class="w-full px-3 py-2.5 bg-slate-950 border border-slate-800 rounded-xl text-white font-mono focus:border-emerald-500 focus:outline-none">
                </div>
                <div>
                    <label class="font-bold text-slate-300 block mb-1">E-mail</label>
                    <input name="c_email" placeholder="email@empresa.com" class="w-full px-3 py-2.5 bg-slate-950 border border-slate-800 rounded-xl text-white focus:border-emerald-500 focus:outline-none">
                </div>
                <div>
                    <label class="font-bold text-slate-300 block mb-1">Empresa</label>
                    <input name="c_company" placeholder="Nome da empresa" class="w-full px-3 py-2.5 bg-slate-950 border border-slate-800 rounded-xl text-white focus:border-emerald-500 focus:outline-none">
                </div>
                <div>
                    <label class="font-bold text-slate-300 block mb-1">Etiqueta</label>
                    <select name="c_tag" class="w-full px-3 py-2.5 bg-slate-950 border border-slate-800 rounded-xl text-white focus:border-emerald-500 focus:outline-none">
                        <option value="">— Sem Etiqueta —</option>
                        <?php foreach ($tags_list as $tl): ?>
                        <option value="<?php echo htmlspecialchars($tl['name']); ?>"><?php echo htmlspecialchars($tl['name']); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-span-2">
                    <label class="font-bold text-slate-300 block mb-1">Observações</label>
                    <textarea name="c_notes" rows="2" placeholder="Anotações sobre o contato..." class="w-full px-3 py-2.5 bg-slate-950 border border-slate-800 rounded-xl text-white focus:border-emerald-500 focus:outline-none"></textarea>
                </div>
            </div>
            <button type="submit" class="w-full py-2.5 bg-emerald-600 hover:bg-emerald-500 text-white font-bold rounded-xl transition flex items-center justify-center gap-2">
                <i class="fa-solid fa-plus"></i> Adicionar Contato
            </button>
        </form>
    </div>
</div>

<!-- Modal: Editar Contato -->
<div id="modal-edit-contact" class="fixed inset-0 z-50 hidden bg-slate-950/80 backdrop-blur-md flex items-center justify-center p-4">
    <div class="bg-slate-900 border border-slate-700 rounded-2xl w-full max-w-lg p-6 shadow-2xl space-y-4">
        <div class="flex items-center justify-between border-b border-slate-800 pb-3">
            <h4 class="text-sm font-extrabold text-white flex items-center gap-2"><i class="fa-solid fa-pen-to-square text-amber-400"></i> Editar Contato</h4>
            <button onclick="closeModal('modal-edit-contact')" class="text-slate-400 hover:text-white"><i class="fa-solid fa-xmark"></i></button>
        </div>
        <form method="POST" action="index.php?module=whatsapp&action=contatos" class="space-y-3 text-xs">
            <input type="hidden" name="action_edit_contact" value="1">
            <input type="hidden" name="edit_c_id" id="edit_c_id">
            <div class="grid grid-cols-2 gap-3">
                <div class="col-span-2">
                    <label class="font-bold text-slate-300 block mb-1">Nome *</label>
                    <input name="edit_c_name" id="edit_c_name" required class="w-full px-3 py-2.5 bg-slate-950 border border-slate-800 rounded-xl text-white focus:border-amber-500 focus:outline-none">
                </div>
                <div>
                    <label class="font-bold text-slate-300 block mb-1">Telefone / WhatsApp *</label>
                    <input name="edit_c_phone" id="edit_c_phone" required class="w-full px-3 py-2.5 bg-slate-950 border border-slate-800 rounded-xl text-white font-mono focus:border-amber-500 focus:outline-none">
                </div>
                <div>
                    <label class="font-bold text-slate-300 block mb-1">E-mail</label>
                    <input name="edit_c_email" id="edit_c_email" class="w-full px-3 py-2.5 bg-slate-950 border border-slate-800 rounded-xl text-white focus:border-amber-500 focus:outline-none">
                </div>
                <div>
                    <label class="font-bold text-slate-300 block mb-1">Empresa</label>
                    <input name="edit_c_company" id="edit_c_company" class="w-full px-3 py-2.5 bg-slate-950 border border-slate-800 rounded-xl text-white focus:border-amber-500 focus:outline-none">
                </div>
                <div>
                    <label class="font-bold text-slate-300 block mb-1">Etiqueta</label>
                    <select name="edit_c_tag" id="edit_c_tag" class="w-full px-3 py-2.5 bg-slate-950 border border-slate-800 rounded-xl text-white focus:border-amber-500 focus:outline-none">
                        <option value="">— Sem Etiqueta —</option>
                        <?php foreach ($tags_list as $tl): ?>
                        <option value="<?php echo htmlspecialchars($tl['name']); ?>"><?php echo htmlspecialchars($tl['name']); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-span-2">
                    <label class="font-bold text-slate-300 block mb-1">Observações</label>
                    <textarea name="edit_c_notes" id="edit_c_notes" rows="2" class="w-full px-3 py-2.5 bg-slate-950 border border-slate-800 rounded-xl text-white focus:border-amber-500 focus:outline-none"></textarea>
                </div>
            </div>
            <button type="submit" class="w-full py-2.5 bg-amber-600 hover:bg-amber-500 text-white font-bold rounded-xl transition flex items-center justify-center gap-2">
                <i class="fa-solid fa-floppy-disk"></i> Salvar Alterações
            </button>
        </form>
    </div>
</div>

<!-- Modal: Criar Etiqueta -->
<div id="modal-import-prismabot" class="fixed inset-0 z-50 hidden bg-slate-950/80 backdrop-blur-md flex items-center justify-center p-4">
    <form method="POST" action="index.php?module=whatsapp&action=contatos" class="bg-slate-900 border border-slate-700 rounded-2xl p-6 w-full max-w-md space-y-4">
        <input type="hidden" name="action_sync_prismabot" value="1">
        <h3 class="text-sm font-black text-white flex items-center gap-2"><i class="fa-brands fa-whatsapp text-emerald-400"></i> Importar contatos do Prismabot</h3>
        <p class="text-xs text-slate-400">Os contatos são buscados na API configurada agora. Números que já estão na agenda não são cadastrados de novo.</p>
        <label class="flex items-start gap-2 text-xs text-slate-200 font-bold cursor-pointer p-3 rounded-xl bg-slate-800/60 border border-slate-700">
            <input type="checkbox" name="include_unnamed" value="1" class="accent-emerald-500 mt-0.5">
            <span>Importar também os contatos sem nome<br><span class="font-normal text-slate-400">Entram com o número no lugar do nome. Desmarcado, eles são ignorados.</span></span>
        </label>
        <div class="flex justify-end gap-2">
            <button type="button" onclick="closeModal('modal-import-prismabot')" class="px-3.5 py-2 bg-slate-800 text-slate-300 border border-slate-700 rounded-xl text-xs font-bold">Cancelar</button>
            <button type="submit" class="px-3.5 py-2 bg-emerald-600 hover:bg-emerald-500 text-white rounded-xl text-xs font-bold">Importar</button>
        </div>
    </form>
</div>

<div id="modal-import-csv" class="fixed inset-0 z-50 hidden bg-slate-950/80 backdrop-blur-md flex items-center justify-center p-4">
    <form method="POST" enctype="multipart/form-data" action="index.php?module=whatsapp&action=contatos" class="bg-slate-900 border border-slate-700 rounded-2xl p-6 w-full max-w-md space-y-4">
        <input type="hidden" name="action_csv" value="1"><input type="hidden" name="csv_step" value="upload">
        <h3 class="text-sm font-black text-white flex items-center gap-2"><i class="fa-solid fa-file-import text-sky-400"></i> Importar contatos por CSV</h3>
        <p class="text-xs text-slate-400">Envie qualquer planilha salva como CSV. O painel reconhece as colunas (nome, telefone, e-mail, empresa, etiqueta), arruma os telefones e mostra uma prévia antes de gravar. Números repetidos não são cadastrados de novo.</p>
        <input type="file" name="csv_file" accept=".csv,.txt,text/csv" required class="block w-full text-xs text-slate-300 file:mr-3 file:px-3 file:py-2 file:rounded-lg file:border-0 file:bg-slate-700 file:text-slate-100 file:font-bold">
        <button type="button" onclick="downloadCsvTemplate()" class="text-xs text-sky-400 hover:text-sky-300 font-bold flex items-center gap-1.5"><i class="fa-solid fa-download"></i> Baixar modelo de planilha</button>
        <div class="flex justify-end gap-2">
            <button type="button" onclick="closeModal('modal-import-csv')" class="px-3.5 py-2 bg-slate-800 text-slate-300 border border-slate-700 rounded-xl text-xs font-bold">Cancelar</button>
            <button type="submit" class="px-3.5 py-2 bg-sky-600 hover:bg-sky-500 text-white rounded-xl text-xs font-bold">Ver prévia</button>
        </div>
    </form>
</div>

<?php if ($csv_preview): $cp = $csv_preview; $cl = $cp['cls']; $fl = ['name' => 'Nome', 'phone' => 'Telefone', 'email' => 'E-mail', 'company' => 'Empresa', 'tag' => 'Etiqueta']; ?>
<div id="modal-csv-preview" class="fixed inset-0 z-50 bg-slate-950/80 backdrop-blur-md flex items-center justify-center p-4 overflow-y-auto">
    <form method="POST" action="index.php?module=whatsapp&action=contatos" class="bg-slate-900 border border-slate-700 rounded-2xl p-6 w-full max-w-2xl space-y-4 my-6">
        <input type="hidden" name="action_csv" value="1"><input type="hidden" name="csv_token" value="<?php echo htmlspecialchars($cp['token']); ?>">
        <h3 class="text-sm font-black text-white flex items-center gap-2"><i class="fa-solid fa-list-check text-sky-400"></i> Prévia da importação (<?php echo (int)$cp['total']; ?> linhas)</h3>
        <div class="grid grid-cols-2 sm:grid-cols-5 gap-2 text-center text-[11px] font-bold">
            <div class="p-2 rounded-xl bg-emerald-500/10 border border-emerald-500/30 text-emerald-300"><div class="text-lg"><?php echo count($cl['ok']); ?></div>serão importados</div>
            <div class="p-2 rounded-xl bg-amber-500/10 border border-amber-500/30 text-amber-300"><div class="text-lg"><?php echo count($cl['sem_nome']); ?></div>sem nome</div>
            <div class="p-2 rounded-xl bg-slate-800 border border-slate-700 text-slate-300"><div class="text-lg"><?php echo count($cl['ja_existe']); ?></div>já cadastrados</div>
            <div class="p-2 rounded-xl bg-slate-800 border border-slate-700 text-slate-300"><div class="text-lg"><?php echo count($cl['repetido_arquivo']); ?></div>repetidos no arquivo</div>
            <div class="p-2 rounded-xl bg-rose-500/10 border border-rose-500/30 text-rose-300"><div class="text-lg"><?php echo count($cl['invalido']); ?></div>número inválido</div>
        </div>
        <div>
            <p class="text-[11px] text-slate-400 font-bold mb-1">Colunas do arquivo (ajuste se o painel não reconheceu):</p>
            <div class="grid grid-cols-2 sm:grid-cols-5 gap-2">
                <?php foreach ($fl as $k => $lab): ?>
                <label class="text-[10px] text-slate-400 font-bold"><?php echo $lab; ?>
                    <select name="map[<?php echo $k; ?>]" class="mt-1 w-full bg-slate-800 border border-slate-700 rounded-lg text-xs text-slate-200 p-1.5">
                        <option value="-1">— não usar —</option>
                        <?php foreach ($cp['headers'] as $hi => $hn): ?><option value="<?php echo $hi; ?>" <?php echo ($cp['map'][$k] === $hi) ? 'selected' : ''; ?>><?php echo htmlspecialchars($hn !== '' ? $hn : 'Coluna ' . ($hi + 1)); ?></option><?php endforeach; ?>
                    </select>
                </label>
                <?php endforeach; ?>
            </div>
            <button type="submit" name="csv_step" value="preview" class="mt-2 text-[11px] text-sky-400 font-bold">↻ Recalcular prévia</button>
        </div>
        <?php if ($cl['ok'] || $cl['sem_nome']): ?>
        <div class="max-h-40 overflow-y-auto rounded-xl border border-slate-800 text-[11px]">
            <table class="w-full text-left text-slate-300"><thead class="text-slate-500 sticky top-0 bg-slate-900"><tr><th class="p-1.5">Nome</th><th class="p-1.5">Telefone</th><th class="p-1.5">Etiqueta</th></tr></thead><tbody>
            <?php foreach (array_slice(array_merge($cl['ok'], $cl['sem_nome']), 0, 8) as $c): ?><tr class="border-t border-slate-800"><td class="p-1.5"><?php echo htmlspecialchars($c['name'] !== '' ? $c['name'] : '(sem nome)'); ?></td><td class="p-1.5"><?php echo htmlspecialchars($c['phone']); ?></td><td class="p-1.5"><?php echo htmlspecialchars($c['tag']); ?></td></tr><?php endforeach; ?>
            </tbody></table>
        </div>
        <?php endif; ?>
        <?php if ($cl['invalido']): ?><p class="text-[11px] text-rose-300">Linhas com número inválido (ignoradas): <?php echo htmlspecialchars(implode(', ', array_slice(array_column($cl['invalido'], 'linha'), 0, 15))); ?><?php echo count($cl['invalido']) > 15 ? '…' : ''; ?></p><?php endif; ?>
        <?php if ($cl['sem_nome']): ?>
        <label class="flex items-start gap-2 text-xs text-slate-200 font-bold cursor-pointer p-3 rounded-xl bg-slate-800/60 border border-slate-700">
            <input type="checkbox" name="include_unnamed" value="1" <?php echo $cp['unnamed'] ? 'checked' : ''; ?> class="accent-emerald-500 mt-0.5">
            <span>Importar também os <?php echo count($cl['sem_nome']); ?> contatos sem nome<br><span class="font-normal text-slate-400">Entram com o número no lugar do nome.</span></span>
        </label>
        <?php endif; ?>
        <div class="flex justify-end gap-2">
            <a href="index.php?module=whatsapp&action=contatos" class="px-3.5 py-2 bg-slate-800 text-slate-300 border border-slate-700 rounded-xl text-xs font-bold">Cancelar</a>
            <button type="submit" name="csv_step" value="import" class="px-3.5 py-2 bg-emerald-600 hover:bg-emerald-500 text-white rounded-xl text-xs font-bold" <?php echo (!$cl['ok'] && !$cl['sem_nome']) ? 'disabled style="opacity:.5"' : ''; ?>>Importar agora</button>
        </div>
    </form>
</div>
<?php endif; ?>

<div id="modal-create-tag" class="fixed inset-0 z-50 hidden bg-slate-950/80 backdrop-blur-md flex items-center justify-center p-4">
    <div class="bg-slate-900 border border-slate-700 rounded-2xl w-full max-w-md p-6 shadow-2xl space-y-4">
        <div class="flex items-center justify-between border-b border-slate-800 pb-3">
            <h4 class="text-sm font-extrabold text-white flex items-center gap-2"><i class="fa-solid fa-tags text-amber-400"></i> <span id="tag-modal-title">Criar Nova Etiqueta</span></h4>
            <button onclick="closeModal('modal-create-tag')" class="text-slate-400 hover:text-white"><i class="fa-solid fa-xmark"></i></button>
        </div>
        <form method="POST" action="index.php?module=whatsapp&action=contatos" class="space-y-4 text-xs">
            <input type="hidden" name="action_create_tag" value="1">
            <input type="hidden" name="edit_tag_id" id="edit_tag_id" value="0">
            <div>
                <label class="font-bold text-slate-300 block mb-1">Nome da Etiqueta</label>
                <input name="new_tag_name" id="new_tag_name" required placeholder="Ex: Lead Quente, Cliente VIP..." class="w-full px-3 py-2.5 bg-slate-950 border border-slate-800 rounded-xl text-white focus:border-amber-500 focus:outline-none">
            </div>
            <div>
                <label class="font-bold text-slate-300 block mb-2">Cor da Etiqueta</label>
                <div class="flex items-center gap-3 mb-3">
                    <input type="color" id="tag-picker" value="#10B981" class="w-12 h-9 rounded-lg bg-slate-950 border border-slate-700 cursor-pointer p-0.5" title="Clique e arraste para escolher qualquer cor" oninput="tagPickerChanged(this.value)">
                    <input type="text" name="new_tag_hex" id="tag-hex" maxlength="7" value="#10B981" placeholder="#10B981" autocomplete="off" class="w-28 px-3 py-2 bg-slate-950 border border-slate-800 rounded-xl text-white font-mono text-xs focus:border-amber-500 outline-none" oninput="tagHexTyped(this.value)">
                    <span class="text-[10px] text-slate-500">Clique na caixa de cor e arraste para escolher, ou digite o código da cor.</span>
                </div>
                                <div class="mt-3 flex items-center gap-2 text-[10px] text-slate-400 font-bold">Prévia: <span id="tag-preview" class="px-2.5 py-1 rounded-lg border text-[11px] font-bold " style="background:rgba(16,185,129,.2);color:#9fe3c9;border-color:rgba(16,185,129,.4)">Nome da etiqueta</span></div>
            </div>
            <div class="flex gap-2">
                <button type="button" id="tag-cancel-edit" onclick="cancelEditTag()" class="hidden px-4 py-2.5 bg-slate-800 hover:bg-slate-700 text-slate-300 font-bold rounded-xl transition whitespace-nowrap">Cancelar edição</button>
                <button type="submit" id="tag-submit" class="flex-1 py-2.5 bg-amber-600 hover:bg-amber-500 text-white font-bold rounded-xl transition">
                    <i class="fa-solid fa-check"></i> <span id="tag-submit-txt">Criar Etiqueta</span>
                </button>
            </div>
        </form>

        <!-- Lista de etiquetas existentes -->
        <?php if (!empty($tags_list)): ?>
        <div class="border-t border-slate-800 pt-3">
            <span class="text-[10px] text-slate-400 font-bold block mb-2">Etiquetas existentes (use o lápis para editar e a lixeira para apagar):</span>
            <div class="flex flex-col gap-1.5 max-h-52 overflow-y-auto">
                <?php foreach ($tags_list as $tl):
                    $ck = $tl['color_key'];
                    $cls = tagCls($ck, $color_palette, 'bg-slate-800 text-slate-400'); $sty = tagSty($ck);
                    $nuse = 0; foreach ($contacts_list as $cc) { if (($cc['tag'] ?? '') === $tl['name']) $nuse++; }
                ?>
                <div class="flex items-center justify-between gap-2">
                    <span class="px-2 py-0.5 rounded text-[10px] font-bold border <?php echo $cls; ?>" style="<?php echo htmlspecialchars($sty); ?>">🏷️ <?php echo htmlspecialchars($tl['name']); ?></span>
                    <span class="flex items-center gap-1.5">
                        <span class="text-[10px] text-slate-500"><?php echo $nuse; ?> contato(s)</span>
                        <button type="button" title="Editar etiqueta" onclick='editTag(<?php echo (int)$tl['id']; ?>, <?php echo json_encode($tl['name'], JSON_UNESCAPED_UNICODE | JSON_HEX_APOS | JSON_HEX_QUOT); ?>, <?php echo json_encode(tagToHex($ck)); ?>)' class="w-7 h-7 rounded-lg bg-slate-800 hover:bg-amber-600/30 text-amber-300 border border-slate-700 flex items-center justify-center"><i class="fa-solid fa-pen text-[10px]"></i></button>
                        <form method="POST" action="index.php?module=whatsapp&action=contatos" class="inline" onsubmit="return confirm('Apagar a etiqueta &quot;<?php echo htmlspecialchars($tl['name'], ENT_QUOTES); ?>&quot;? <?php echo $nuse; ?> contato(s) ficarão sem etiqueta (nenhum contato é apagado).');">
                            <input type="hidden" name="action_delete_tag" value="1"><input type="hidden" name="delete_tag_id" value="<?php echo (int)$tl['id']; ?>">
                            <button type="submit" title="Apagar etiqueta" class="w-7 h-7 rounded-lg bg-slate-800 hover:bg-rose-600/30 text-rose-300 border border-slate-700 flex items-center justify-center"><i class="fa-solid fa-trash text-[10px]"></i></button>
                        </form>
                    </span>
                </div>
                <?php endforeach; ?>
            </div>
        </div>
        <?php endif; ?>
    </div>
</div>

<script>
function editTag(id, name, color) {
    document.getElementById('edit_tag_id').value = id;
    document.getElementById('new_tag_name').value = name;
    document.getElementById('tag-hex').value = color; document.getElementById('tag-picker').value = color;
    tagPreviewUpdate();
    document.getElementById('tag-modal-title').textContent = 'Editar Etiqueta';
    document.getElementById('tag-submit-txt').textContent = 'Salvar alterações';
    document.getElementById('tag-cancel-edit').classList.remove('hidden');
    document.getElementById('new_tag_name').focus();
}
function cancelEditTag() {
    document.getElementById('edit_tag_id').value = 0;
    document.getElementById('new_tag_name').value = '';
    document.getElementById('tag-hex').value = '#10B981'; document.getElementById('tag-picker').value = '#10B981'; tagPreviewUpdate();
    document.getElementById('tag-modal-title').textContent = 'Criar Nova Etiqueta';
    document.getElementById('tag-submit-txt').textContent = 'Criar Etiqueta';
    document.getElementById('tag-cancel-edit').classList.add('hidden');
}
</script>
<!-- Modal: Nova/Editar Mensagem Rápida -->
<div id="modal-qmsg" class="fixed inset-0 z-50 hidden bg-slate-950/80 backdrop-blur-md flex items-center justify-center p-4">
    <div class="bg-slate-900 border border-slate-700 rounded-2xl w-full max-w-md p-6 shadow-2xl space-y-4">
        <div class="flex items-center justify-between border-b border-slate-800 pb-3">
            <h4 class="text-sm font-extrabold text-white flex items-center gap-2"><i class="fa-solid fa-bolt text-brand-400"></i> <span id="qmsg-modal-title">Nova Mensagem Rápida</span></h4>
            <button onclick="closeModal('modal-qmsg')" class="text-slate-400 hover:text-white"><i class="fa-solid fa-xmark"></i></button>
        </div>
        <form method="POST" action="index.php?module=whatsapp&action=contatos&crm_tab=quick_msgs" class="space-y-3 text-xs">
            <input type="hidden" name="action_save_qmsg" value="1">
            <input type="hidden" name="qmsg_id" id="qmsg_id" value="0">
            <div>
                <label class="font-bold text-slate-300 block mb-1">Título (referência interna)</label>
                <input name="qmsg_title" id="qmsg_title_input" required placeholder="Ex: Boas-vindas, Cobrança..." class="w-full px-3 py-2.5 bg-slate-950 border border-slate-800 rounded-xl text-white focus:border-brand-500 focus:outline-none">
            </div>
            <div>
                <label class="font-bold text-slate-300 block mb-1">Categoria</label>
                <input name="qmsg_category" id="qmsg_category_input" placeholder="Ex: Suporte, Comercial, Cobrança..." class="w-full px-3 py-2.5 bg-slate-950 border border-slate-800 rounded-xl text-white focus:border-brand-500 focus:outline-none" value="Geral">
            </div>
            <div>
                <label class="font-bold text-slate-300 block mb-1">Texto da Mensagem</label>
                <textarea name="qmsg_body" id="qmsg_body_input" rows="5" required placeholder="Olá {nome}! Sua mensagem aqui..." class="w-full px-3 py-2.5 bg-slate-950 border border-slate-800 rounded-xl text-white focus:border-brand-500 focus:outline-none"></textarea>
                <span class="text-[10px] text-slate-500">Use {nome} e {telefone} como variáveis dinâmicas.</span>
            </div>
            <button type="submit" class="w-full py-2.5 bg-brand-600 hover:bg-brand-500 text-white font-bold rounded-xl transition">
                <i class="fa-solid fa-floppy-disk"></i> Salvar Mensagem
            </button>
        </form>
    </div>
</div>

<!-- Modal: WhatsApp Rápido -->
<div id="modal-fast-wa" class="fixed inset-0 z-50 hidden bg-slate-950/80 backdrop-blur-md flex items-center justify-center p-4">
    <div class="bg-slate-900 border border-emerald-500/30 rounded-2xl w-full max-w-md p-6 shadow-2xl space-y-4">
        <div class="flex items-center justify-between border-b border-slate-800 pb-3">
            <h4 class="text-sm font-extrabold text-white flex items-center gap-2"><i class="fa-brands fa-whatsapp text-emerald-400 text-base"></i> WhatsApp Rápido</h4>
            <button onclick="closeModal('modal-fast-wa')" class="text-slate-400 hover:text-white"><i class="fa-solid fa-xmark"></i></button>
        </div>
        <p id="fast-wa-subtitle" class="text-xs text-emerald-400 font-mono"></p>

        <!-- Mensagens Rápidas -->
        <?php if (!empty($quick_msgs)): ?>
        <div>
            <span class="text-[10px] text-slate-400 font-bold block mb-2">⚡ Mensagens Rápidas:</span>
            <div class="flex flex-wrap gap-1.5">
                <?php foreach (array_slice($quick_msgs, 0, 8) as $qm): ?>
                <button type="button" onclick="applyQuickMsgToWA('<?php echo htmlspecialchars(addslashes($qm['body'])); ?>')"
                        class="px-2.5 py-1 bg-slate-800 hover:bg-slate-700 text-slate-300 hover:text-white rounded-lg text-[10px] font-bold transition border border-slate-700">
                    <?php echo htmlspecialchars($qm['title']); ?>
                </button>
                <?php endforeach; ?>
            </div>
        </div>
        <?php endif; ?>

        <form id="fast-wa-form" class="space-y-3 text-xs" onsubmit="sendFastWA(event)">
            <input type="hidden" id="fast-wa-phone" value="">
            <div>
                <label class="font-bold text-slate-300 block mb-1">Mensagem</label>
                <textarea id="fast-wa-msg" rows="4" required placeholder="Digite ou selecione uma mensagem rápida..." class="w-full px-3 py-2.5 bg-slate-950 border border-slate-800 rounded-xl text-white focus:border-emerald-500 focus:outline-none"></textarea>
            </div>
            <button type="submit" class="w-full py-2.5 bg-emerald-600 hover:bg-emerald-500 text-white font-bold rounded-xl transition flex items-center justify-center gap-2">
                <i class="fa-brands fa-whatsapp"></i> Enviar Mensagem
            </button>
        </form>
    </div>
</div>

<script>
// ─── Helpers de Modal ────────────────────────────────────────────────────────
function downloadCsvTemplate() {
    const csv = '\uFEFFNome;Telefone;E-mail;Empresa;Etiqueta\nMaria Silva;(16) 99999-0000;maria@exemplo.com.br;Empresa Exemplo;Cliente\n';
    const a = document.createElement('a');
    a.href = URL.createObjectURL(new Blob([csv], {type: 'text/csv;charset=utf-8'}));
    a.download = 'modelo_importacao_contatos.csv';
    document.body.appendChild(a); a.click(); a.remove();
}
function tagHexStyle(h) {
    const r = parseInt(h.substr(1,2),16), g = parseInt(h.substr(3,2),16), b = parseInt(h.substr(5,2),16);
    const m = c => Math.round(c + (255 - c) * 0.6);
    return 'background:rgba('+r+','+g+','+b+',.2);color:rgb('+m(r)+','+m(g)+','+m(b)+');border-color:rgba('+r+','+g+','+b+',.4)';
}
function tagPreviewUpdate() {
    const p = document.getElementById('tag-preview'); const n = document.getElementById('new_tag_name'); const hx = document.getElementById('tag-hex');
    if (!p) return;
    p.textContent = (n && n.value.trim()) ? n.value.trim() : 'Nome da etiqueta';
    p.className = 'px-2.5 py-1 rounded-lg border text-[11px] font-bold';
    if (hx && /^#[0-9a-fA-F]{6}$/.test(hx.value.trim())) p.setAttribute('style', tagHexStyle(hx.value.trim())); else p.removeAttribute('style');
}
function tagPickerChanged(v) { document.getElementById('tag-hex').value = v.toUpperCase(); tagPreviewUpdate(); }
function tagHexTyped(v) {
    v = v.trim(); if (v && v[0] !== '#') v = '#' + v;
    if (/^#[0-9a-fA-F]{6}$/.test(v)) document.getElementById('tag-picker').value = v;
    tagPreviewUpdate();
}
document.addEventListener('input', e => { if (e.target && e.target.id === 'new_tag_name') tagPreviewUpdate(); });
function openModal(id)  { const m = document.getElementById(id); if (m) m.classList.remove('hidden'); }
function closeModal(id) { const m = document.getElementById(id); if (m) m.classList.add('hidden');    }

// ─── Editar Contato ──────────────────────────────────────────────────────────
function openEditContactModal(c) {
    document.getElementById('edit_c_id').value      = c.id;
    document.getElementById('edit_c_name').value    = c.name;
    document.getElementById('edit_c_phone').value   = c.phone;
    document.getElementById('edit_c_email').value   = c.email || '';
    document.getElementById('edit_c_company').value = c.company || '';
    document.getElementById('edit_c_notes').value   = c.notes || '';
    const sel = document.getElementById('edit_c_tag');
    if (sel) { for (let opt of sel.options) { opt.selected = opt.value === c.tag; } }
    openModal('modal-edit-contact');
}

// ─── Editar Mensagem Rápida ──────────────────────────────────────────────────
function openEditQmsgModal(qm) {
    document.getElementById('qmsg_id').value            = qm.id;
    document.getElementById('qmsg_title_input').value   = qm.title;
    document.getElementById('qmsg_category_input').value= qm.category;
    document.getElementById('qmsg_body_input').value    = qm.body;
    document.getElementById('qmsg-modal-title').innerText = 'Editar Mensagem Rápida';
    openModal('modal-qmsg');
}

// ─── Filtros de Contato ──────────────────────────────────────────────────────
let _currentTagFilter = 'ALL';
function filterByTag(tag) {
    _currentTagFilter = tag;
    filterContacts();
}
function filterContacts() {
    const q = (document.getElementById('contact-search')?.value || '').toLowerCase();
    document.querySelectorAll('.contact-row').forEach(row => {
        const tag = row.dataset.tag || '';
        const matchTag  = _currentTagFilter === 'ALL' || tag === _currentTagFilter;
        const matchText = !q || (row.dataset.name?.includes(q) || row.dataset.phone?.includes(q) || row.dataset.company?.includes(q));
        row.style.display = matchTag && matchText ? '' : 'none';
        if (row.style.display === 'none') { const cc = row.querySelector('.chk-contact'); if (cc) cc.checked = false; }
    });
    updateBulkBar();
}

// ─── Seleção e exclusão em lote ───────────────────────────
function _visibleRows() { return Array.from(document.querySelectorAll('.contact-row')).filter(r => r.style.display !== 'none'); }
function toggleAllContacts(on) {
    _visibleRows().forEach(r => { const c = r.querySelector('.chk-contact'); if (c) c.checked = on; });
    updateBulkBar();
}
function selectAllContacts(on) {
    document.querySelectorAll('.chk-contact').forEach(c => c.checked = on);
    const all = document.getElementById('chk-all-contacts'); if (all) all.checked = on;
    updateBulkBar();
}
function selectBySource(src) {
    document.querySelectorAll('.chk-contact').forEach(c => c.checked = false);
    let n = 0;
    document.querySelectorAll('.contact-row').forEach(r => {
        if ((r.dataset.source || '') === src) { const c = r.querySelector('.chk-contact'); if (c) { c.checked = true; n++; } }
    });
    if (!n) alert('Nenhum contato com essa origem.');
    updateBulkBar();
}
function updateBulkBar() {
    const sel = document.querySelectorAll('.chk-contact:checked').length;
    const btn = document.getElementById('bulk-delete-btn'), clr = document.getElementById('bulk-clear');
    if (btn) { btn.classList.toggle('hidden', sel === 0); document.getElementById('bulk-count-label').innerText = 'Apagar ' + sel + (sel === 1 ? ' selecionado' : ' selecionados'); }
    if (clr) clr.classList.toggle('hidden', sel === 0);
}
function confirmBulkDelete() {
    const ids = Array.from(document.querySelectorAll('.chk-contact:checked')).map(c => c.value);
    if (!ids.length) return false;
    if (!confirm('Excluir ' + ids.length + (ids.length === 1 ? ' contato' : ' contatos') + '? Essa ação não pode ser desfeita.')) return false;
    document.getElementById('bulk-ids-holder').innerHTML = ids.map(i => '<input type="hidden" name="bulk_ids[]" value="' + i + '">').join('');
    return true;
}

// ─── WhatsApp Rápido ──────────────────────────────────────────────────────────
function openFastWhatsAppModal(phone, name) {
    document.getElementById('fast-wa-phone').value   = phone;
    document.getElementById('fast-wa-subtitle').innerText = `📱 ${name} — ${phone}`;
    document.getElementById('fast-wa-msg').value     = '';
    openModal('modal-fast-wa');
}
function applyQuickMsgToWA(body) {
    document.getElementById('fast-wa-msg').value = body;
}
async function sendFastWA(e) {
    e.preventDefault();
    const phone = document.getElementById('fast-wa-phone').value;
    const msg   = document.getElementById('fast-wa-msg').value;
    if (!phone || !msg) return;

    const btn = e.target.querySelector('button[type=submit]');
    btn.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> Enviando...';
    btn.disabled  = true;

    try {
                const res = await fetch('index.php?api_action=send_whatsapp_message', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ phone: phone, message: msg })
        });
        const data = await res.json();
        alert(data.success !== false ? `✅ Mensagem enviada para ${phone}!` : `❌ Erro: ${data.error || 'Verifique as configurações da API.'}`);
        closeModal('modal-fast-wa');
    } catch(err) {
        alert('Erro ao enviar: ' + err.message);
    } finally {
        btn.innerHTML = '<i class="fa-brands fa-whatsapp"></i> Enviar Mensagem';
        btn.disabled  = false;
    }
}

// ─── Copy Quick Msg ────────────────────────────────────────────────────────────
function copyQuickMsg(body) {
    navigator.clipboard.writeText(body).then(() => {
        const toast = document.createElement('div');
        toast.className = 'fixed bottom-4 right-4 bg-emerald-600 text-white px-4 py-2 rounded-xl text-xs font-bold z-[999] shadow-xl';
        toast.innerText = '✅ Mensagem copiada!';
        document.body.appendChild(toast);
        setTimeout(() => toast.remove(), 2000);
    });
}

// ─── Exportar CSV ────────────────────────────────────────────────────────────
function exportContactsToCSV() {
    const __who = <?php $__lu = getLoggedUser(); echo json_encode(trim((string)($__lu['name'] ?? $__lu['email'] ?? '')), JSON_UNESCAPED_UNICODE); ?>;
    const rows = [['Nome', 'Telefone', 'E-mail', 'Empresa', 'Etiqueta', 'Origem']];
    document.querySelectorAll('#contacts-table-body .contact-row').forEach(row => {
        const c = row.querySelectorAll('td');
        if (c.length < 6) return;
        rows.push([row.dataset.name || '', c[1]?.innerText || '', c[2]?.innerText || '', c[3]?.innerText || '', row.dataset.tag || '', c[5]?.innerText || '']);
    });
    ipbxCsv('contatos_' + new Date().toISOString().slice(0, 10) + '.csv', ['Agenda de contatos', 'Gerado em ' + new Date().toLocaleString('pt-BR') + (__who ? ' por ' + __who : '') + ' - IPbx Prisma'], rows);
}

// ─── Polling em Tempo Real da Agenda ──────────────────────────────────────────
let lastContactsVersion = null;
setInterval(async function() {
    try {
        const res = await fetch('api_contacts_sync.php?action=check_updates&version=' + (lastContactsVersion || ''));
        const data = await res.json();
        if (data.status === 'success') {
            if (lastContactsVersion === null) {
                lastContactsVersion = data.version;
            } else if (data.has_changed) {
                lastContactsVersion = data.version;
                window.location.reload();
            }
        }
    } catch (e) {}
}, 4000);
</script>
