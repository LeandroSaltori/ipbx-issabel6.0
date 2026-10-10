<?php
/**
 * IPbx Prisma - Permissões de Notificação de Ramais (Escalável para 10 a 1000 Ramais)
 */

$msg = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['action_save_ext_permissions'])) {
        $ext_id = (int)$_POST['ext_id'];
        $notify_agent = isset($_POST['notify_agent_missed']) ? 1 : 0;
        $notify_agent_int = isset($_POST['notify_agent_internal']) ? 1 : 0;
        $notify_client = isset($_POST['notify_client_busy']) ? 1 : 0;
        $send_ai = isset($_POST['send_ai_summary']) ? 1 : 0;

        $stmt_up = $db->prepare("UPDATE extensions_config SET 
            notify_agent_missed = :nam, 
            notify_agent_internal = :nai, 
            notify_client_busy = :ncb, 
            send_ai_summary = :sai, 
            updated_at = CURRENT_TIMESTAMP 
            WHERE id = :id");
        
        $stmt_up->execute([
            ':nam' => $notify_agent,
            ':nai' => $notify_agent_int,
            ':ncb' => $notify_client,
            ':sai' => $send_ai,
            ':id' => $ext_id
        ]);

        $msg = "Permissões do ramal atualizadas com sucesso!";
    } elseif (isset($_POST['action_bulk_permissions'])) {
        $bulk_type = $_POST['bulk_type'] ?? '';
        if ($bulk_type === 'enable_all_missed') {
            $db->exec("UPDATE extensions_config SET notify_agent_missed = 1, notify_agent_internal = 1, updated_at = CURRENT_TIMESTAMP");
            $msg = "Notificações de chamadas perdidas ativadas em LOTE para TODOS os ramais!";
        } elseif ($bulk_type === 'enable_all_ai') {
            $db->exec("UPDATE extensions_config SET send_ai_summary = 1, updated_at = CURRENT_TIMESTAMP");
            $msg = "Resumos de Inteligência Artificial ativados em LOTE para TODOS os ramais!";
        } elseif ($bulk_type === 'disable_all') {
            $db->exec("UPDATE extensions_config SET notify_agent_missed = 0, notify_agent_internal = 0, notify_client_busy = 0, send_ai_summary = 0, updated_at = CURRENT_TIMESTAMP");
            $msg = "Todas as permissões foram desativadas para TODOS os ramais.";
        }
    }
}

$exts_list = $db->query("SELECT * FROM extensions_config ORDER BY CAST(extension AS UNSIGNED) ASC")->fetchAll(PDO::FETCH_ASSOC);
?>

<div class="space-y-6">
    <?php if (!empty($msg)): ?>
        <div class="p-4 rounded-xl bg-emerald-500/10 text-emerald-400 border border-emerald-500/20 text-xs font-bold flex items-center gap-2">
            <i class="fa-solid fa-circle-check"></i>
            <span><?php echo htmlspecialchars($msg); ?></span>
        </div>
    <?php endif; ?>

    <div class="bg-slate-900/90 border border-slate-800 rounded-xl p-5 shadow-xl space-y-4">
        <!-- Header & Controles Avançados em Escala -->
        <div class="flex flex-col lg:flex-row lg:items-center justify-between gap-4 border-b border-slate-800 pb-4">
            <div>
                <h3 class="text-sm font-bold text-white flex items-center gap-2">
                    <i class="fa-solid fa-sliders text-indigo-400"></i> Permissões de Notificação dos Ramais
                </h3>
                <span class="text-xs text-slate-400">Gerencie regras de notificação via WhatsApp para 10 até 1.000+ ramais</span>
            </div>

            <!-- Botões de Ação em Lote (Bulk Actions) -->
            <form method="POST" action="" class="flex items-center gap-2 flex-wrap">
                <input type="hidden" name="action_bulk_permissions" value="1">
                <button type="submit" name="bulk_type" value="enable_all_missed" title="Ativar Notificação de Perdidas em Todos os Ramais"
                        class="px-3 py-1.5 bg-brand-600 hover:bg-brand-500 text-white rounded-lg text-xs font-bold transition flex items-center gap-1.5 shadow">
                    <i class="fa-solid fa-bell"></i> Ativar Perdidas (Todos)
                </button>
                <button type="submit" name="bulk_type" value="enable_all_ai" title="Ativar IA de Áudio em Todos os Ramais"
                        class="px-3 py-1.5 bg-purple-600 hover:bg-purple-500 text-white rounded-lg text-xs font-bold transition flex items-center gap-1.5 shadow">
                    <i class="fa-solid fa-wand-magic-sparkles"></i> Ativar IA (Todos)
                </button>
            </form>
        </div>

        <!-- Barra de Busca Rápida e Alternador de Layout -->
        <div class="flex flex-col sm:flex-row items-center justify-between gap-3 bg-slate-950 p-3 rounded-xl border border-slate-800">
            <div class="relative w-full sm:w-80">
                <i class="fa-solid fa-magnifying-glass absolute left-3 top-3 text-slate-500 text-xs"></i>
                <input type="text" id="ext-search-input" onkeyup="filterExtensionsList()" placeholder="Buscar por número do ramal ou atendente..."
                       class="w-full pl-9 pr-3 py-2 bg-slate-900 border border-slate-800 rounded-lg text-white text-xs placeholder-slate-500 focus:border-brand-500 focus:outline-none">
            </div>

            <div class="flex items-center gap-2 text-xs">
                <span class="text-slate-400 font-bold">Visualização:</span>
                <button onclick="setExtViewMode('grid')" id="btn-view-grid" class="px-3 py-1.5 rounded-lg font-bold bg-brand-600 text-white transition flex items-center gap-1.5">
                    <i class="fa-solid fa-border-all"></i> Cards
                </button>
                <button onclick="setExtViewMode('table')" id="btn-view-table" class="px-3 py-1.5 rounded-lg font-bold bg-slate-800 text-slate-300 hover:text-white transition flex items-center gap-1.5">
                    <i class="fa-solid fa-list-check"></i> Tabela Compacta (1000 Ramais)
                </button>
            </div>
        </div>

        <?php if (empty($exts_list)): ?>
            <div class="text-center py-10 text-slate-500 text-xs">Nenhum ramal cadastrado. Clique em Sincronizar na barra superior.</div>
        <?php else: ?>
            
            <!-- MODO 1: CARDS GRID -->
            <div id="ext-grid-container" class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-4">
                <?php foreach ($exts_list as $e): ?>
                    <div class="ext-card-item bg-slate-950 p-4 rounded-xl border border-slate-800 space-y-3" data-ext="<?php echo htmlspecialchars($e['extension']); ?>" data-name="<?php echo htmlspecialchars(strtolower($e['agent_name'])); ?>">
                        <div class="flex items-center justify-between border-b border-slate-800 pb-2">
                            <div>
                                <span class="text-xs font-bold text-white font-mono block">Ramal <?php echo htmlspecialchars($e['extension']); ?></span>
                                <span class="text-[11px] text-slate-400 block"><?php echo htmlspecialchars($e['agent_name']); ?></span>
                            </div>
                            <span class="px-2 py-0.5 rounded text-[9px] font-bold bg-slate-800 text-slate-300 border border-slate-700">
                                <?php echo strtoupper($e['tech'] ?: 'PJSIP'); ?>
                            </span>
                        </div>

                        <form method="POST" action="" class="space-y-2 text-xs">
                            <input type="hidden" name="action_save_ext_permissions" value="1">
                            <input type="hidden" name="ext_id" value="<?php echo $e['id']; ?>">

                            <label class="flex items-center gap-2 text-slate-300 cursor-pointer">
                                <input type="checkbox" name="notify_agent_missed" value="1" <?php echo $e['notify_agent_missed'] ? 'checked' : ''; ?> class="accent-brand-500 rounded">
                                <span>Notificar Atendente (Ext. Externa)</span>
                            </label>

                            <label class="flex items-center gap-2 text-slate-300 cursor-pointer">
                                <input type="checkbox" name="notify_agent_internal" value="1" <?php echo $e['notify_agent_internal'] ? 'checked' : ''; ?> class="accent-brand-500 rounded">
                                <span>Notificar Atendente (Ext. Interna)</span>
                            </label>

                            <label class="flex items-center gap-2 text-slate-300 cursor-pointer">
                                <input type="checkbox" name="notify_client_busy" value="1" <?php echo $e['notify_client_busy'] ? 'checked' : ''; ?> class="accent-brand-500 rounded">
                                <span>Notificar Cliente se Ocupado</span>
                            </label>

                            <label class="flex items-center gap-2 text-slate-300 cursor-pointer">
                                <input type="checkbox" name="send_ai_summary" value="1" <?php echo $e['send_ai_summary'] ? 'checked' : ''; ?> class="accent-brand-500 rounded">
                                <span>Enviar Resumo IA do Áudio</span>
                            </label>

                            <div class="pt-2">
                                <button type="submit" class="w-full py-1.5 bg-slate-800 hover:bg-brand-600 text-slate-300 hover:text-white font-bold rounded transition">
                                    Salvar Permissões
                                </button>
                            </div>
                        </form>
                    </div>
                <?php endforeach; ?>
            </div>

            <!-- MODO 2: TABELA COMPACTA (IDEAL PARA 100 A 1000 RAMAIS) -->
            <div id="ext-table-container" class="hidden overflow-x-auto bg-slate-950 rounded-xl border border-slate-800">
                <table class="w-full text-left text-xs text-slate-300">
                    <thead class="bg-slate-900 uppercase text-[10px] font-bold text-slate-400 tracking-wider">
                        <tr>
                            <th class="p-3">Ramal</th>
                            <th class="p-3">Atendente</th>
                            <th class="p-3">Ext. Externa</th>
                            <th class="p-3">Ext. Interna</th>
                            <th class="p-3">Notif. Ocupado</th>
                            <th class="p-3">Resumo IA</th>
                            <th class="p-3 text-right">Ação</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-800">
                        <?php foreach ($exts_list as $e): ?>
                            <tr class="ext-row-item hover:bg-slate-900/60 transition" data-ext="<?php echo htmlspecialchars($e['extension']); ?>" data-name="<?php echo htmlspecialchars(strtolower($e['agent_name'])); ?>">
                                <form method="POST" action="">
                                    <input type="hidden" name="action_save_ext_permissions" value="1">
                                    <input type="hidden" name="ext_id" value="<?php echo $e['id']; ?>">

                                    <td class="p-3 font-bold font-mono text-white">Ramal <?php echo htmlspecialchars($e['extension']); ?></td>
                                    <td class="p-3 text-slate-300"><?php echo htmlspecialchars($e['agent_name']); ?></td>
                                    <td class="p-3">
                                        <input type="checkbox" name="notify_agent_missed" value="1" <?php echo $e['notify_agent_missed'] ? 'checked' : ''; ?> class="accent-brand-500 rounded">
                                    </td>
                                    <td class="p-3">
                                        <input type="checkbox" name="notify_agent_internal" value="1" <?php echo $e['notify_agent_internal'] ? 'checked' : ''; ?> class="accent-brand-500 rounded">
                                    </td>
                                    <td class="p-3">
                                        <input type="checkbox" name="notify_client_busy" value="1" <?php echo $e['notify_client_busy'] ? 'checked' : ''; ?> class="accent-brand-500 rounded">
                                    </td>
                                    <td class="p-3">
                                        <input type="checkbox" name="send_ai_summary" value="1" <?php echo $e['send_ai_summary'] ? 'checked' : ''; ?> class="accent-brand-500 rounded">
                                    </td>
                                    <td class="p-3 text-right">
                                        <button type="submit" class="px-3 py-1 bg-brand-600 hover:bg-brand-500 text-white rounded text-[11px] font-bold transition">
                                            Salvar
                                        </button>
                                    </td>
                                </form>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>

        <?php endif; ?>
    </div>
</div>

<script>
    function setExtViewMode(mode) {
        const grid = document.getElementById('ext-grid-container');
        const tbl = document.getElementById('ext-table-container');
        const btnGrid = document.getElementById('btn-view-grid');
        const btnTbl = document.getElementById('btn-view-table');

        if (mode === 'table') {
            grid.classList.add('hidden');
            tbl.classList.remove('hidden');
            btnTbl.className = 'px-3 py-1.5 rounded-lg font-bold bg-brand-600 text-white transition flex items-center gap-1.5';
            btnGrid.className = 'px-3 py-1.5 rounded-lg font-bold bg-slate-800 text-slate-300 hover:text-white transition flex items-center gap-1.5';
        } else {
            tbl.classList.add('hidden');
            grid.classList.remove('hidden');
            btnGrid.className = 'px-3 py-1.5 rounded-lg font-bold bg-brand-600 text-white transition flex items-center gap-1.5';
            btnTbl.className = 'px-3 py-1.5 rounded-lg font-bold bg-slate-800 text-slate-300 hover:text-white transition flex items-center gap-1.5';
        }
    }

    function filterExtensionsList() {
        const q = (document.getElementById('ext-search-input')?.value || '').toLowerCase().trim();
        
        document.querySelectorAll('.ext-card-item, .ext-row-item').forEach(el => {
            const ext = el.getAttribute('data-ext') || '';
            const name = el.getAttribute('data-name') || '';
            if (ext.includes(q) || name.includes(q)) {
                el.style.display = '';
            } else {
                el.style.display = 'none';
            }
        });
    }
</script>
