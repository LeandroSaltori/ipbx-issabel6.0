<?php
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
];

// ─── PROCESSAMENTO DE FORMULÁRIOS ─────────────────────────────────────────────
$req = $_SERVER['REQUEST_METHOD'] === 'POST';

// -- Criar Etiqueta
if ($req && isset($_POST['action_create_tag'])) {
    $tname = trim($_POST['new_tag_name'] ?? '');
    $tclr  = trim($_POST['new_tag_color'] ?? 'emerald');
    if (!empty($tname) && isset($color_palette[$tclr])) {
        try {
            $db->prepare("INSERT OR REPLACE INTO contact_tags (name, color_key) VALUES (:n, :c)")
               ->execute([':n' => $tname, ':c' => $tclr]);
            $msg_status = "Etiqueta '$tname' criada com sucesso!";
        } catch (Exception $e) { $msg_error = "Erro ao criar etiqueta: " . $e->getMessage(); }
    }
}

// -- Criar Contato
if ($req && isset($_POST['action_add_contact'])) {
    $n = trim($_POST['c_name'] ?? ''); $p = trim($_POST['c_phone'] ?? '');
    $e = trim($_POST['c_email'] ?? ''); $co = trim($_POST['c_company'] ?? '');
    $t = trim($_POST['c_tag'] ?? ''); $no = trim($_POST['c_notes'] ?? '');
    if ($n && $p) {
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
    $api_url   = getSetting('api_url');
    $api_token = getSetting('api_token');
    if (!$api_url || !$api_token) {
        $msg_error = "Configure a URL e Token da API Prismabot em Configurações > API antes de importar.";
    } else {
        // Tentar buscar contatos da instância Prismabot
        $contacts_url = rtrim($api_url, '/');
        // Detectar padrão de URL da API e adaptar endpoint
        if (strpos($contacts_url, '/message/') !== false) {
            $contacts_url = preg_replace('/\/message\/[^\/]+.*$/', '/contact/findContacts/' . basename(dirname($contacts_url)), $contacts_url);
        } else {
            $contacts_url = $contacts_url . '/contact/findContacts';
        }
        $ch = curl_init($contacts_url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 10,
            CURLOPT_HTTPHEADER     => ['Content-Type: application/json', 'apikey: ' . $api_token],
            CURLOPT_SSL_VERIFYPEER => false,
        ]);
        $result  = curl_exec($ch);
        $http_c  = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curl_err= curl_error($ch);
        curl_close($ch);

        if ($curl_err) {
            $msg_error = "Erro de conexão: $curl_err";
        } elseif ($http_c !== 200) {
            $msg_error = "API retornou HTTP $http_c. Verifique as credenciais e URL.";
        } else {
            $data = json_decode($result, true);
            $contacts_arr = [];
            if (is_array($data)) {
                $contacts_arr = isset($data['contacts']) ? $data['contacts'] : (isset($data[0]) ? $data : []);
            }
            $imported = 0;
            foreach ($contacts_arr as $ci) {
                $cn = trim($ci['name'] ?? $ci['pushName'] ?? '');
                $cp = trim($ci['phone'] ?? $ci['id'] ?? '');
                $cp = preg_replace('/\D/', '', $cp);
                if ($cn && strlen($cp) >= 10) {
                    try {
                        $db->prepare("INSERT OR IGNORE INTO crm_contacts (name, phone, source) VALUES (:n,:p,'Prismabot')")
                           ->execute([':n' => $cn, ':p' => $cp]);
                        $imported++;
                    } catch (Exception $e) {}
                }
            }
            $msg_status = $imported > 0 ? "✅ $imported contatos importados da API Prismabot!" : "Nenhum contato novo encontrado na resposta da API.";
        }
    }
}

// -- Sincronizar Chamadas do PABX (apenas quando solicitado via botão)
if ($req && isset($_POST['action_sync_pabx_calls'])) {
    $added = syncPabxCallsToContacts();
    $msg_status = $added > 0 ? "✅ $added novas chamadas do PABX cadastradas como contatos para você nomear!" : "Todas as chamadas do PABX já estão salvas na agenda de contatos.";
}

// ─── Carregar dados do SQLite ──────────────────────────────────────────────────
$tags_list    = $db->query("SELECT * FROM contact_tags ORDER BY name ASC")->fetchAll(PDO::FETCH_ASSOC);
$contacts_list= $db->query("SELECT * FROM crm_contacts ORDER BY name ASC")->fetchAll(PDO::FETCH_ASSOC);
$quick_msgs   = $db->query("SELECT * FROM quick_messages ORDER BY category ASC, title ASC")->fetchAll(PDO::FETCH_ASSOC);

// Garantir etiquetas padrão
$default_tags = ['VIP'=>'emerald','Comercial'=>'cyan','Financeiro'=>'amber','Suporte'=>'purple','Inadimplente'=>'rose','Novo Lead'=>'indigo'];
foreach ($default_tags as $dn => $dc) {
    $exists = false;
    foreach ($tags_list as $tl) { if ($tl['name'] === $dn) { $exists = true; break; } }
    if (!$exists) {
        try {
            $db->prepare("INSERT OR IGNORE INTO contact_tags (name, color_key) VALUES (:n,:c)")->execute([':n'=>$dn,':c'=>$dc]);
        } catch (Exception $e) {}
    }
}
$tags_list = $db->query("SELECT * FROM contact_tags ORDER BY name ASC")->fetchAll(PDO::FETCH_ASSOC);

// Mapa de cores por nome de etiqueta
$tag_color_map = [];
foreach ($tags_list as $tl) {
    $ck = $tl['color_key'];
    $tag_color_map[$tl['name']] = isset($color_palette[$ck]) ? $color_palette[$ck]['classes'] : 'bg-slate-800 text-slate-300 border-slate-700';
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

                <!-- Sincronizar Chamadas PABX -->
                <form method="POST" action="index.php?module=whatsapp&action=contatos">
                    <input type="hidden" name="action_sync_pabx_calls" value="1">
                    <button type="submit" class="px-3.5 py-2 bg-indigo-600 hover:bg-indigo-500 text-white rounded-xl text-xs font-bold transition shadow-lg shadow-indigo-600/20 flex items-center gap-1.5" title="Sincronizar novos números de chamadas recebidas para a lista de contatos">
                        <i class="fa-solid fa-phone-volume"></i> Sincronizar Chamadas PABX
                    </button>
                </form>

                <!-- Sincronizar Prismabot -->
                <form method="POST" action="index.php?module=whatsapp&action=contatos">
                    <input type="hidden" name="action_sync_prismabot" value="1">
                    <button type="submit" class="px-3.5 py-2 bg-emerald-600 hover:bg-emerald-500 text-white rounded-xl text-xs font-bold transition shadow-lg shadow-emerald-600/20 flex items-center gap-1.5">
                        <i class="fa-brands fa-whatsapp"></i> Importar Prismabot
                    </button>
                </form>

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
                    $cls = isset($color_palette[$ck]) ? $color_palette[$ck]['classes'] : 'bg-slate-800 text-slate-400 border-slate-700';
                ?>
                <button onclick="filterByTag('<?php echo htmlspecialchars($tl['name']); ?>')"
                        class="px-2.5 py-1 rounded-lg border <?php echo $cls; ?> hover:opacity-80 transition">
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
    <div class="bg-slate-900/90 border border-slate-800 rounded-3xl shadow-2xl overflow-hidden">
        <div class="overflow-x-auto custom-scrollbar">
            <table class="w-full text-left text-xs text-slate-300">
                <thead class="bg-slate-950 uppercase text-[9px] font-bold text-slate-400 tracking-wider">
                    <tr>
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
                        <tr><td colspan="7" class="p-8 text-center text-slate-500">
                            <i class="fa-solid fa-address-book text-2xl block mb-2 opacity-30"></i>
                            Nenhum contato cadastrado. Clique em <strong class="text-white">Novo Contato</strong> para adicionar.
                        </td></tr>
                    <?php else: ?>
                    <?php foreach ($contacts_list as $c):
                        $tclss = isset($tag_color_map[$c['tag']]) ? $tag_color_map[$c['tag']] : 'bg-slate-800 text-slate-400 border-slate-700';
                        $srcCls = ($c['source'] === 'Prismabot' || $c['source'] === 'API Z-PRO') ? 'bg-emerald-500/20 text-emerald-300 border border-emerald-500/30' : 'bg-slate-800 text-slate-400';
                    ?>
                    <tr class="contact-row hover:bg-slate-800/40 transition" data-tag="<?php echo htmlspecialchars($c['tag']); ?>"
                        data-name="<?php echo htmlspecialchars(strtolower($c['name'])); ?>"
                        data-phone="<?php echo htmlspecialchars($c['phone']); ?>"
                        data-company="<?php echo htmlspecialchars(strtolower($c['company'])); ?>">
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
                                <span class="px-2.5 py-0.5 rounded-md text-[10px] font-bold border <?php echo $tclss; ?>">🏷️ <?php echo htmlspecialchars($c['tag']); ?></span>
                            <?php else: ?>
                                <span class="text-slate-600">—</span>
                            <?php endif; ?>
                        </td>
                        <td class="p-3">
                            <span class="px-2 py-0.5 rounded text-[9px] font-bold <?php echo $srcCls; ?>">
                                <?php echo htmlspecialchars($c['source']); ?>
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
<div id="modal-create-tag" class="fixed inset-0 z-50 hidden bg-slate-950/80 backdrop-blur-md flex items-center justify-center p-4">
    <div class="bg-slate-900 border border-slate-700 rounded-2xl w-full max-w-md p-6 shadow-2xl space-y-4">
        <div class="flex items-center justify-between border-b border-slate-800 pb-3">
            <h4 class="text-sm font-extrabold text-white flex items-center gap-2"><i class="fa-solid fa-tags text-amber-400"></i> Criar Nova Etiqueta</h4>
            <button onclick="closeModal('modal-create-tag')" class="text-slate-400 hover:text-white"><i class="fa-solid fa-xmark"></i></button>
        </div>
        <form method="POST" action="index.php?module=whatsapp&action=contatos" class="space-y-4 text-xs">
            <input type="hidden" name="action_create_tag" value="1">
            <div>
                <label class="font-bold text-slate-300 block mb-1">Nome da Etiqueta</label>
                <input name="new_tag_name" required placeholder="Ex: Lead Quente, Cliente VIP..." class="w-full px-3 py-2.5 bg-slate-950 border border-slate-800 rounded-xl text-white focus:border-amber-500 focus:outline-none">
            </div>
            <div>
                <label class="font-bold text-slate-300 block mb-2">Cor da Etiqueta</label>
                <div class="grid grid-cols-4 gap-2">
                    <?php foreach ($color_palette as $ck => $cv): ?>
                    <label class="cursor-pointer">
                        <input type="radio" name="new_tag_color" value="<?php echo $ck; ?>" class="hidden peer" <?php echo $ck === 'emerald' ? 'checked' : ''; ?>>
                        <div class="p-2 rounded-xl border-2 border-transparent peer-checked:border-white text-center <?php echo $cv['classes']; ?> flex items-center justify-center gap-1 font-bold text-[10px] transition hover:opacity-80">
                            <span class="w-2.5 h-2.5 rounded-full <?php echo $cv['dot']; ?>"></span>
                            <?php echo $cv['label']; ?>
                        </div>
                    </label>
                    <?php endforeach; ?>
                </div>
            </div>
            <button type="submit" class="w-full py-2.5 bg-amber-600 hover:bg-amber-500 text-white font-bold rounded-xl transition">
                <i class="fa-solid fa-check"></i> Criar Etiqueta
            </button>
        </form>

        <!-- Lista de etiquetas existentes -->
        <?php if (!empty($tags_list)): ?>
        <div class="border-t border-slate-800 pt-3">
            <span class="text-[10px] text-slate-400 font-bold block mb-2">Etiquetas Existentes:</span>
            <div class="flex flex-wrap gap-2">
                <?php foreach ($tags_list as $tl):
                    $ck = $tl['color_key'];
                    $cls = isset($color_palette[$ck]) ? $color_palette[$ck]['classes'] : 'bg-slate-800 text-slate-400';
                ?>
                <span class="px-2 py-0.5 rounded text-[10px] font-bold border <?php echo $cls; ?>">🏷️ <?php echo htmlspecialchars($tl['name']); ?></span>
                <?php endforeach; ?>
            </div>
        </div>
        <?php endif; ?>
    </div>
</div>

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
    });
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
    let csv = "Nome,Telefone,Email,Empresa,Etiqueta,Origem\n";
    document.querySelectorAll('#contacts-table-body .contact-row').forEach(row => {
        const cells = row.querySelectorAll('td');
        if (cells.length < 6) return;
        const name    = row.dataset.name || '';
        const phone   = cells[1]?.innerText?.trim() || '';
        const email   = cells[2]?.innerText?.trim() || '';
        const company = cells[3]?.innerText?.trim() || '';
        const tag     = row.dataset.tag || '';
        const source  = cells[5]?.innerText?.trim() || '';
        csv += `"${name}","${phone}","${email}","${company}","${tag}","${source}"\n`;
    });
    const blob = new Blob(["\uFEFF" + csv], {type: 'text/csv;charset=utf-8;'});
    const a = document.createElement('a');
    a.href = URL.createObjectURL(blob);
    a.download = 'contatos_prismabot.csv';
    document.body.appendChild(a);
    a.click();
    document.body.removeChild(a);
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
