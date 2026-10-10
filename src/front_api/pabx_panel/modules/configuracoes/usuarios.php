<?php
/**
 * IPbx Prisma - Módulo de Gestão de Usuários, Perfis & Permissões Granulares v8.0
 */

$msg_success = '';
$msg_error = '';

// Processar formulário de adicionar/editar usuário
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action_save_user'])) {
    $user_data = [
        'id'          => intval($_POST['user_id'] ?? 0),
        'name'        => trim($_POST['user_name'] ?? ''),
        'email'       => trim($_POST['user_email'] ?? ''),
        'role'        => trim($_POST['user_role'] ?? 'Usuário'),
        'extension'   => trim($_POST['user_extension'] ?? ''),
        'whatsapp'    => trim($_POST['user_whatsapp'] ?? ''),
        'password'    => (string)($_POST['user_password'] ?? ''),
        'permissions' => isset($_POST['permissions']) && is_array($_POST['permissions']) ? $_POST['permissions'] : []
    ];

    $res = saveSystemUser($user_data);
    if ($res['success']) {
        $msg_success = $res['message'];
    } else {
        $msg_error = $res['error'];
    }
}

// Processar exclusão de usuário
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action_delete_user'])) {
    $del_id = intval($_POST['delete_user_id'] ?? 0);
    if ($del_id > 0 && $del_id === (int)($_SESSION['logged_user_id'] ?? 0)) {
        $msg_error = "Você não pode excluir o próprio usuário enquanto estiver logado.";
    } elseif ($del_id > 0) {
        if (deleteSystemUser($del_id)) {
            $msg_success = "Usuário removido com sucesso.";
        } else {
            $msg_error = "Não foi possível remover este usuário.";
        }
    }
}

// Buscar usuários reais do SQLite
$system_users = getAllSystemUsers();

// Carregar lista real de ramais cadastrados
$available_extensions = [];
try {
    if (isset($db)) {
        $q_ext = $db->query("SELECT extension, agent_name, tech FROM extensions_config ORDER BY CAST(extension AS UNSIGNED) ASC");
        if ($q_ext) {
            while ($r = $q_ext->fetch(PDO::FETCH_ASSOC)) {
                $available_extensions[$r['extension']] = "{$r['agent_name']} ({$r['extension']} - " . strtoupper($r['tech'] ?: 'PJSIP') . ")";
            }
        }
    }
} catch (Exception $e) {}

?>

<div class="space-y-6">
    <!-- Header Módulo -->
    <div class="bg-slate-900/90 border border-slate-800 rounded-2xl p-5 shadow-xl flex flex-col md:flex-row items-center justify-between gap-4">
        <div>
            <h3 class="text-base font-extrabold text-white flex items-center gap-2.5">
                <i class="fa-solid fa-users-gear text-amber-400 text-lg"></i> Gestão de Usuários, Perfis & Permissões Granulares
            </h3>
            <span class="text-xs text-slate-400">Configuração individual de acessos por módulo, níveis de perfis pré-definidos (Admin, Supervisor, Usuário, Personalizado) e ações de áudio/WhatsApp</span>
        </div>

        <button onclick="openAddUserModal()" class="px-4 py-2 bg-brand-600 hover:bg-brand-500 text-white rounded-xl text-xs font-bold transition shadow-lg shadow-brand-600/20 flex items-center gap-1.5">
            <i class="fa-solid fa-user-plus"></i> Criar Novo Usuário
        </button>
    </div>

    <!-- Feedback Alerts -->
    <?php if (!empty($msg_success)): ?>
        <div class="p-4 bg-emerald-500/10 border border-emerald-500/30 text-emerald-300 rounded-2xl text-xs font-bold flex items-center gap-2">
            <i class="fa-solid fa-circle-check text-base"></i> <?php echo htmlspecialchars($msg_success); ?>
        </div>
    <?php endif; ?>
    <?php if (!empty($msg_error)): ?>
        <div class="p-4 bg-rose-500/10 border border-rose-500/30 text-rose-300 rounded-2xl text-xs font-bold flex items-center gap-2">
            <i class="fa-solid fa-triangle-exclamation text-base"></i> <?php echo htmlspecialchars($msg_error); ?>
        </div>
    <?php endif; ?>

    <!-- CARDS DOS PERFIS DE SISTEMA -->
    <div class="grid grid-cols-1 md:grid-cols-4 gap-4 text-xs">
        <div class="bg-slate-900/80 border border-purple-500/30 p-4 rounded-2xl space-y-1">
            <div class="flex items-center justify-between">
                <span class="font-extrabold text-purple-300">Administrador</span>
                <i class="fa-solid fa-shield-halved text-purple-400 text-sm"></i>
            </div>
            <p class="text-[11px] text-slate-400">Acesso Total irrestrito a todos os módulos, configurações e ações sensíveis.</p>
        </div>
        <div class="bg-slate-900/80 border border-brand-500/30 p-4 rounded-2xl space-y-1">
            <div class="flex items-center justify-between">
                <span class="font-extrabold text-brand-300">Supervisor</span>
                <i class="fa-solid fa-user-tie text-brand-400 text-sm"></i>
            </div>
            <p class="text-[11px] text-slate-400">Visualização de filas, CDR, relatórios, escuta de áudios e disparos de WhatsApp.</p>
        </div>
        <div class="bg-slate-900/80 border border-cyan-500/30 p-4 rounded-2xl space-y-1">
            <div class="flex items-center justify-between">
                <span class="font-extrabold text-cyan-300">Usuário / Atendente</span>
                <i class="fa-solid fa-headset text-cyan-400 text-sm"></i>
            </div>
            <p class="text-[11px] text-slate-400">Acesso ao Dashboard, Filas Ao Vivo e chamadas via Webphone / Click-to-Call.</p>
        </div>
        <div class="bg-slate-900/80 border border-amber-500/30 p-4 rounded-2xl space-y-1">
            <div class="flex items-center justify-between">
                <span class="font-extrabold text-amber-300">Personalizado</span>
                <i class="fa-solid fa-sliders text-amber-400 text-sm"></i>
            </div>
            <p class="text-[11px] text-slate-400">Seleção manual e sob medida de cada módulo e recurso granular.</p>
        </div>
    </div>

    <!-- TABELA DE USUÁRIOS E PERMISSÕES -->
    <div class="bg-slate-900/90 border border-slate-800 rounded-3xl p-6 shadow-2xl space-y-4">
        <div class="flex items-center justify-between border-b border-slate-800 pb-3">
            <h4 class="text-sm font-extrabold text-white flex items-center gap-2">
                <i class="fa-solid fa-users text-cyan-400"></i> Usuários Cadastrados no PABX
            </h4>
            <span class="text-xs text-slate-400 font-mono"><?php echo count($system_users); ?> Usuários Ativos</span>
        </div>

        <div class="overflow-x-auto custom-scrollbar">
            <table class="w-full text-left text-xs text-slate-300">
                <thead class="bg-slate-950 uppercase text-[9px] font-bold text-slate-400 tracking-wider">
                    <tr>
                        <th class="p-3">Usuário</th>
                        <th class="p-3">E-mail / Login</th>
                        <th class="p-3">Perfil / Nível</th>
                        <th class="p-3">Ramal PABX Vinculado</th>
                        <th class="p-3">WhatsApp (Notificações)</th>
                        <th class="p-3">Permissões Ativas</th>
                        <th class="p-3 text-right">Ações</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-800/80 font-mono text-[11px]">
                    <?php foreach ($system_users as $u): ?>
                        <?php 
                            $role_badge = 'bg-brand-500/20 text-brand-300 border-brand-500/30';
                            if ($u['role'] === 'Administrador') $role_badge = 'bg-purple-500/20 text-purple-300 border-purple-500/30';
                            if ($u['role'] === 'Supervisor')    $role_badge = 'bg-brand-500/20 text-brand-300 border-brand-500/30';
                            if ($u['role'] === 'Usuário')       $role_badge = 'bg-cyan-500/20 text-cyan-300 border-cyan-500/30';
                            if ($u['role'] === 'Personalizado') $role_badge = 'bg-amber-500/20 text-amber-300 border-amber-500/30';
                        ?>
                        <tr class="hover:bg-slate-800/40 transition">
                            <td class="p-3 font-extrabold text-white flex items-center gap-2">
                                <div class="w-7 h-7 rounded-full bg-slate-800 border border-slate-700 text-amber-400 flex items-center justify-center font-bold text-xs">
                                    <?php echo strtoupper(substr($u['name'], 0, 1)); ?>
                                </div>
                                <?php echo htmlspecialchars($u['name']); ?>
                            </td>
                            <td class="p-3 text-slate-300"><?php echo htmlspecialchars($u['email']); ?></td>
                            <td class="p-3">
                                <span class="px-2 py-0.5 rounded text-[10px] font-bold border <?php echo $role_badge; ?>">
                                    <?php echo htmlspecialchars($u['role']); ?>
                                </span>
                            </td>
                            <td class="p-3 text-emerald-400 font-bold">
                                <i class="fa-solid fa-headset mr-1"></i> Ramal <?php echo htmlspecialchars($u['extension'] ?: 'Sem Ramal'); ?>
                            </td>
                            <td class="p-3 text-cyan-400 font-bold">
                                <i class="fa-brands fa-whatsapp mr-1"></i> <?php echo htmlspecialchars($u['whatsapp'] ?: 'Sem WhatsApp'); ?>
                            </td>
                            <td class="p-3">
                                <div class="flex items-center gap-1 flex-wrap">
                                    <?php if ($u['role'] === 'Administrador'): ?>
                                        <span class="px-2 py-0.5 bg-purple-500/20 text-purple-300 border border-purple-500/30 rounded text-[9px] font-sans font-bold">
                                            🌟 Acesso Total (Master)
                                        </span>
                                    <?php else: ?>
                                        <?php 
                                            $perm_labels = [
                                                'mod_dashboard' => 'Dashboard',
                                                'mod_filas' => 'Filas',
                                                'mod_whatsapp' => 'WhatsApp',
                                                'mod_relatorios' => 'Relatórios',
                                                'mod_configuracoes' => 'Configurações',
                                                'listen_recordings' => '🎧 Gravações',
                                                'send_whatsapp' => '💬 Disparos WA',
                                                'manage_settings' => '⚙️ Alterar Configs',
                                                'schedule_reports' => '📅 Agendamentos',
                                                'click_to_call' => '📞 Click-to-Call'
                                            ];
                                            $plist = $u['permissions_list'] ?? [];
                                        ?>
                                        <?php foreach ($plist as $pk): ?>
                                            <span class="px-1.5 py-0.5 bg-slate-950 border border-slate-800 rounded text-[9px] text-slate-300 font-sans">
                                                <?php echo $perm_labels[$pk] ?? $pk; ?>
                                            </span>
                                        <?php endforeach; ?>
                                    <?php endif; ?>
                                </div>
                            </td>
                            <td class="p-3 text-right">
                                <div class="flex items-center justify-end gap-1.5">
                                    <button onclick='openEditUserModal(<?php echo json_encode($u); ?>)' class="px-2.5 py-1 bg-slate-800 hover:bg-slate-700 text-white rounded-lg font-bold text-[10px] transition border border-slate-700 flex items-center gap-1">
                                        <i class="fa-solid fa-pen"></i> Editar
                                    </button>
                                    <?php if ($u['id'] != 1): ?>
                                        <form method="POST" action="index.php?module=configuracoes&action=usuarios" onsubmit="return confirm('Deseja realmente remover o usuário <?php echo htmlspecialchars($u['name']); ?>?');">
                                            <input type="hidden" name="action_delete_user" value="1">
                                            <input type="hidden" name="delete_user_id" value="<?php echo $u['id']; ?>">
                                            <button type="submit" class="p-1 bg-rose-500/20 hover:bg-rose-500/40 text-rose-300 border border-rose-500/30 rounded-lg transition" title="Excluir Usuário">
                                                <i class="fa-solid fa-trash-can text-xs px-1"></i>
                                            </button>
                                        </form>
                                    <?php endif; ?>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<!-- MODAL POPUP DE CRIAR / EDITAR USUÁRIO (IDÊNTICO AO LAYOUT SOLICITADO) -->
<div id="modal-user-form" class="fixed inset-0 z-50 hidden bg-slate-950/80 backdrop-blur-md flex items-center justify-center p-4">
    <div class="bg-slate-900 border border-amber-500/30 rounded-3xl max-w-2xl w-full p-6 shadow-2xl space-y-5 animate-fadeIn max-h-[90vh] overflow-y-auto custom-scrollbar">
        <div class="flex items-center justify-between border-b border-slate-800 pb-3">
            <div class="flex items-center gap-3">
                <div class="w-10 h-10 rounded-2xl bg-amber-500/20 text-amber-400 flex items-center justify-center font-bold text-lg border border-amber-500/30 shadow">
                    <i class="fa-solid fa-user-gear"></i>
                </div>
                <div>
                    <h4 id="user-modal-title" class="text-base font-extrabold text-white">Criar Novo Usuário</h4>
                    <span class="text-xs text-slate-400 font-mono">Vínculo de Ramal, WhatsApp e Permissões de Acesso</span>
                </div>
            </div>
            <button onclick="closeUserModal()" class="text-slate-400 hover:text-white transition">
                <i class="fa-solid fa-xmark text-lg"></i>
            </button>
        </div>

        <form method="POST" action="index.php?module=configuracoes&action=usuarios" class="space-y-4">
            <input type="hidden" name="action_save_user" value="1">
            <input type="hidden" id="form-user-id" name="user_id" value="0">

            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                <!-- Nome Completo -->
                <div class="space-y-1">
                    <label class="text-xs font-bold text-slate-300 block">Nome Completo:</label>
                    <input type="text" id="form-user-name" name="user_name" required placeholder="Ex: Leandro Silva" class="w-full px-3.5 py-2.5 bg-slate-950 border border-slate-800 rounded-xl text-xs text-white focus:border-brand-500 focus:outline-none font-bold">
                </div>

                <!-- E-mail / Login -->
                <div class="space-y-1">
                    <label class="text-xs font-bold text-slate-300 block">E-mail / Login:</label>
                    <input type="email" id="form-user-email" name="user_email" required placeholder="leandro@prismatelecom.com.br" class="w-full px-3.5 py-2.5 bg-slate-950 border border-slate-800 rounded-xl text-xs text-white focus:border-brand-500 focus:outline-none font-bold">
                </div>

                <!-- Nível / Perfil -->
                <div class="space-y-1">
                    <label class="text-xs font-bold text-purple-300 block flex items-center justify-between">
                        <span>Nível / Perfil:</span>
                        <span id="role-badge-tip" class="text-[10px] text-slate-400 font-mono">Pré-definido</span>
                    </label>
                    <select id="form-user-role" name="user_role" onchange="updatePermissionsByRole(this.value)" class="w-full px-3.5 py-2.5 bg-slate-950 border border-slate-800 rounded-xl text-xs text-white focus:border-purple-500 focus:outline-none font-bold cursor-pointer">
                        <option value="Administrador">Administrador (Acesso Total)</option>
                        <option value="Supervisor">Supervisor (Gestão & Relatórios)</option>
                        <option value="Usuário">Usuário (Atendente / Operacional)</option>
                        <option value="Personalizado">PERSONALIZADO (Customizado sob Medida)</option>
                    </select>
                </div>

                <!-- Vincular Ramal PABX (WebRTC/PJSIP) -->
                <div class="space-y-1">
                    <label class="text-xs font-bold text-emerald-400 block">Vincular Ramal PABX (WebRTC/PJSIP):</label>
                    <select id="form-user-extension" name="user_extension" class="w-full px-3.5 py-2.5 bg-slate-950 border border-slate-800 rounded-xl text-xs text-emerald-300 focus:border-emerald-500 focus:outline-none font-mono font-bold cursor-pointer">
                        <option value="">-- Sem Ramal Vinculado --</option>
                        <?php foreach ($available_extensions as $e_code => $e_label): ?>
                            <option value="<?php echo $e_code; ?>"><?php echo $e_label; ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>

            <!-- WhatsApp do Usuário (Notificações) -->
            <div class="space-y-1">
                <label class="text-xs font-bold text-cyan-400 block">Número do WhatsApp do Usuário (Notificações):</label>
                <input type="text" id="form-user-whatsapp" name="user_whatsapp" required placeholder="5511999998888" class="w-full px-3.5 py-2.5 bg-slate-950 border border-slate-800 rounded-xl text-xs text-cyan-300 focus:border-cyan-500 focus:outline-none font-mono font-bold">
                <span class="text-[10px] text-slate-400 block">Número de WhatsApp do usuário com DDD para onde o sistema enviará relatórios em PDF, relatórios periódicos e notificações.</span>
            </div>

            <!-- Senha de acesso ao painel -->
            <div class="space-y-1">
                <label class="text-xs font-bold text-amber-400 block">Senha de acesso ao painel:</label>
                <input type="password" id="form-user-password" name="user_password" minlength="8" autocomplete="new-password" placeholder="Mínimo 8 caracteres (em branco mantém a atual)" class="w-full px-3.5 py-2.5 bg-slate-950 border border-slate-800 rounded-xl text-xs text-amber-200 focus:border-amber-500 focus:outline-none font-mono">
            </div>

            <!-- SEÇÃO 1: PERMISSÕES DE ACESSO AOS MÓDULOS -->
            <div class="space-y-2 pt-3 border-t border-slate-800">
                <div class="flex items-center justify-between">
                    <label class="text-xs font-extrabold text-white flex items-center gap-2">
                        <i class="fa-solid fa-layer-group text-brand-400"></i> Permissões de Acesso aos Módulos:
                    </label>
                    <span class="text-[10px] text-slate-400 font-mono">Visualização & Navegação</span>
                </div>
                
                <div class="grid grid-cols-1 sm:grid-cols-2 gap-2 text-xs text-slate-300">
                    <label class="flex items-center gap-2.5 p-2.5 bg-slate-950 rounded-xl border border-slate-800 cursor-pointer hover:bg-slate-800/40 transition">
                        <input type="checkbox" name="permissions[]" value="mod_dashboard" class="perm-check rounded border-slate-700 bg-slate-900 text-brand-600 focus:ring-brand-500 w-4 h-4 cursor-pointer">
                        <span class="font-bold text-white">Dashboard</span>
                    </label>
                    <label class="flex items-center gap-2.5 p-2.5 bg-slate-950 rounded-xl border border-slate-800 cursor-pointer hover:bg-slate-800/40 transition">
                        <input type="checkbox" name="permissions[]" value="mod_filas" class="perm-check rounded border-slate-700 bg-slate-900 text-brand-600 focus:ring-brand-500 w-4 h-4 cursor-pointer">
                        <span class="font-bold text-white">Atendimento & Filas</span>
                    </label>
                    <label class="flex items-center gap-2.5 p-2.5 bg-slate-950 rounded-xl border border-slate-800 cursor-pointer hover:bg-slate-800/40 transition">
                        <input type="checkbox" name="permissions[]" value="mod_whatsapp" class="perm-check rounded border-slate-700 bg-slate-900 text-brand-600 focus:ring-brand-500 w-4 h-4 cursor-pointer">
                        <span class="font-bold text-white">WhatsApp & Automação</span>
                    </label>
                    <label class="flex items-center gap-2.5 p-2.5 bg-slate-950 rounded-xl border border-slate-800 cursor-pointer hover:bg-slate-800/40 transition">
                        <input type="checkbox" name="permissions[]" value="mod_relatorios" class="perm-check rounded border-slate-700 bg-slate-900 text-brand-600 focus:ring-brand-500 w-4 h-4 cursor-pointer">
                        <span class="font-bold text-white">Relatórios & Gravações</span>
                    </label>
                    <label class="flex items-center gap-2.5 p-2.5 bg-slate-950 rounded-xl border border-slate-800 cursor-pointer hover:bg-slate-800/40 transition sm:col-span-2">
                        <input type="checkbox" name="permissions[]" value="mod_configuracoes" class="perm-check rounded border-slate-700 bg-slate-900 text-brand-600 focus:ring-brand-500 w-4 h-4 cursor-pointer">
                        <span class="font-bold text-white">Configurações & Gestão de Usuários</span>
                    </label>
                </div>
            </div>

            <!-- SEÇÃO 2: RECURSOS ESPECIAIS & AÇÕES GRANULARES -->
            <div class="space-y-2 pt-3 border-t border-slate-800">
                <div class="flex items-center justify-between">
                    <label class="text-xs font-extrabold text-amber-300 flex items-center gap-2">
                        <i class="fa-solid fa-key text-amber-400"></i> Recursos Especiais & Permissões Granulares:
                    </label>
                    <span class="text-[10px] text-slate-400 font-mono">Ações de Execução</span>
                </div>

                <div class="grid grid-cols-1 sm:grid-cols-2 gap-2 text-xs text-slate-300">
                    <label class="flex items-center gap-2.5 p-2.5 bg-slate-950 rounded-xl border border-slate-800 cursor-pointer hover:bg-slate-800/40 transition">
                        <input type="checkbox" name="permissions[]" value="listen_recordings" class="perm-check rounded border-slate-700 bg-slate-900 text-amber-500 focus:ring-amber-500 w-4 h-4 cursor-pointer">
                        <span class="font-bold text-slate-200">🎧 Ouvir & Baixar Gravações</span>
                    </label>
                    <label class="flex items-center gap-2.5 p-2.5 bg-slate-950 rounded-xl border border-slate-800 cursor-pointer hover:bg-slate-800/40 transition">
                        <input type="checkbox" name="permissions[]" value="send_whatsapp" class="perm-check rounded border-slate-700 bg-slate-900 text-emerald-500 focus:ring-emerald-500 w-4 h-4 cursor-pointer">
                        <span class="font-bold text-slate-200">💬 Disparar Mensagens WhatsApp</span>
                    </label>
                    <label class="flex items-center gap-2.5 p-2.5 bg-slate-950 rounded-xl border border-slate-800 cursor-pointer hover:bg-slate-800/40 transition">
                        <input type="checkbox" name="permissions[]" value="manage_settings" class="perm-check rounded border-slate-700 bg-slate-900 text-purple-500 focus:ring-purple-500 w-4 h-4 cursor-pointer">
                        <span class="font-bold text-slate-200">⚙️ Editar / Salvar Configurações</span>
                    </label>
                    <label class="flex items-center gap-2.5 p-2.5 bg-slate-950 rounded-xl border border-slate-800 cursor-pointer hover:bg-slate-800/40 transition">
                        <input type="checkbox" name="permissions[]" value="schedule_reports" class="perm-check rounded border-slate-700 bg-slate-900 text-cyan-500 focus:ring-cyan-500 w-4 h-4 cursor-pointer">
                        <span class="font-bold text-slate-200">📅 Agendar Disparos de Relatório</span>
                    </label>
                    <label class="flex items-center gap-2.5 p-2.5 bg-slate-950 rounded-xl border border-slate-800 cursor-pointer hover:bg-slate-800/40 transition sm:col-span-2">
                        <input type="checkbox" name="permissions[]" value="click_to_call" class="perm-check rounded border-slate-700 bg-slate-900 text-blue-500 focus:ring-blue-500 w-4 h-4 cursor-pointer">
                        <span class="font-bold text-slate-200">📞 Executar Webphone & Click-to-Call</span>
                    </label>
                </div>
            </div>

            <div class="pt-3 flex justify-end gap-2 border-t border-slate-800">
                <button type="button" onclick="closeUserModal()" class="px-4 py-2 bg-slate-800 text-slate-300 hover:text-white rounded-xl text-xs font-bold transition">Cancelar</button>
                <button type="submit" class="px-5 py-2.5 bg-brand-600 hover:bg-brand-500 text-white rounded-xl text-xs font-extrabold transition shadow-lg shadow-brand-600/30 flex items-center gap-2">
                    <i class="fa-solid fa-floppy-disk"></i> Salvar Usuário & Vínculos
                </button>
            </div>
        </form>
    </div>
</div>

<script>
const PERMISSION_PRESETS = {
    'Administrador': [
        'mod_dashboard', 'mod_filas', 'mod_whatsapp', 'mod_relatorios', 'mod_configuracoes',
        'listen_recordings', 'send_whatsapp', 'manage_settings', 'schedule_reports', 'click_to_call'
    ],
    'Supervisor': [
        'mod_dashboard', 'mod_filas', 'mod_whatsapp', 'mod_relatorios',
        'listen_recordings', 'send_whatsapp', 'schedule_reports', 'click_to_call'
    ],
    'Usuário': [
        'mod_dashboard', 'mod_filas', 'click_to_call'
    ]
};

function updatePermissionsByRole(roleName) {
    const checks = document.querySelectorAll('.perm-check');
    const badgeTip = document.getElementById('role-badge-tip');

    if (roleName === 'Personalizado') {
        if (badgeTip) badgeTip.innerText = 'Seleção Livre';
        checks.forEach(chk => {
            chk.disabled = false;
        });
        return;
    }

    const activeList = PERMISSION_PRESETS[roleName] || [];
    if (badgeTip) badgeTip.innerText = 'Pré-definido (' + activeList.length + ' permissões)';

    checks.forEach(chk => {
        const isChecked = activeList.includes(chk.value);
        chk.checked = isChecked;
        chk.disabled = (roleName === 'Administrador');
    });
}

function openAddUserModal() {
    document.getElementById('user-modal-title').innerText = 'Criar Novo Usuário';
    document.getElementById('form-user-id').value = '0';
    document.getElementById('form-user-password').value = '';
    document.getElementById('form-user-name').value = '';
    document.getElementById('form-user-email').value = '';
    document.getElementById('form-user-role').value = 'Administrador';
    document.getElementById('form-user-extension').value = '201';
    document.getElementById('form-user-whatsapp').value = '';
    
    updatePermissionsByRole('Administrador');
    document.getElementById('modal-user-form').classList.remove('hidden');
}

function openEditUserModal(u) {
    document.getElementById('user-modal-title').innerText = `Editar Usuário: ${u.name}`;
    document.getElementById('form-user-id').value = u.id;
    document.getElementById('form-user-password').value = '';
    document.getElementById('form-user-name').value = u.name || '';
    document.getElementById('form-user-email').value = u.email || '';
    document.getElementById('form-user-role').value = u.role || 'Administrador';
    document.getElementById('form-user-extension').value = u.extension || '';
    document.getElementById('form-user-whatsapp').value = u.whatsapp || '';

    // Marcar checkboxes salva no banco
    const checks = document.querySelectorAll('.perm-check');
    const plist = u.permissions_list || u.permissions || [];
    
    if (u.role === 'Administrador') {
        updatePermissionsByRole('Administrador');
    } else if (u.role === 'Personalizado') {
        checks.forEach(chk => {
            chk.disabled = false;
            chk.checked = plist.includes(chk.value);
        });
        if (document.getElementById('role-badge-tip')) document.getElementById('role-badge-tip').innerText = 'Seleção Customizada';
    } else {
        updatePermissionsByRole(u.role);
    }

    document.getElementById('modal-user-form').classList.remove('hidden');
}

function closeUserModal() {
    document.getElementById('modal-user-form').classList.add('hidden');
}
</script>
