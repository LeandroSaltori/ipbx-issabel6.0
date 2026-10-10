<?php
/**
 * IPbx Prisma - Módulo de Bloqueio de Chamadas (Blacklist via REST API)
 */

require_once __DIR__ . '/../../includes/pbxapi_client.php';
$apiClient = new PbxApiClient($db);

$msg_status = '';
$msg_error  = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['action_add_blacklist'])) {
        $num  = trim($_POST['block_number'] ?? '');
        $desc = trim($_POST['block_desc'] ?? '');
        if (!empty($num)) {
            $res = $apiClient->addToBlacklist($num, $desc);
            if ($res['success']) {
                $msg_status = "Número '$num' adicionado à Blacklist do PABX com sucesso!";
            } else {
                $msg_error = "Erro ao adicionar à Blacklist: " . ($res['message'] ?? 'Falha na requisição API');
            }
        }
    }

    if (isset($_POST['action_delete_blacklist'])) {
        $num = trim($_POST['delete_number'] ?? '');
        if (!empty($num)) {
            $res = $apiClient->deleteFromBlacklist($num);
            if ($res['success']) {
                $msg_status = "Número '$num' removido da Blacklist do PABX.";
            } else {
                $msg_error = "Erro ao remover da Blacklist: " . ($res['message'] ?? 'Falha na requisição API');
            }
        }
    }
}

// Obter lista atual via API
$apiRes = $apiClient->getBlacklist();
$blacklistItems = [];
if ($apiRes['success'] && is_array($apiRes['data'])) {
    $blacklistItems = $apiRes['data']['results'] ?? $apiRes['data'];
}
?>

<div class="space-y-6">
    <!-- Header -->
    <div class="bg-slate-900/80 backdrop-blur border border-slate-800 rounded-2xl p-6 shadow-xl flex flex-col sm:flex-row items-start sm:items-center justify-between gap-4">
        <div class="flex items-center gap-3">
            <div class="p-3 bg-rose-500/10 rounded-xl border border-rose-500/20 text-rose-400">
                <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M18.364 18.364A9 9 0 005.636 5.636m12.728 12.728A9 9 0 015.636 5.636m12.728 12.728L5.636 5.636"/></svg>
            </div>
            <div>
                <h2 class="text-xl font-bold text-slate-100">Blacklist - Bloqueio de Chamadas Indesejadas</h2>
                <p class="text-xs text-slate-400">Números cadastrados aqui são rejeitados automaticamente pelo PABX IP</p>
            </div>
        </div>
    </div>

    <!-- Alertas -->
    <?php if ($msg_status): ?>
        <div class="p-4 bg-emerald-500/10 border border-emerald-500/30 rounded-xl text-emerald-300 text-sm flex items-center gap-3">
            <svg class="w-5 h-5 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/></svg>
            <div><?php echo  htmlspecialchars($msg_status) ?></div>
        </div>
    <?php endif; ?>

    <?php if ($msg_error): ?>
        <div class="p-4 bg-rose-500/10 border border-rose-500/30 rounded-xl text-rose-300 text-sm flex items-center gap-3">
            <svg class="w-5 h-5 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4m0 4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
            <div><?php echo  htmlspecialchars($msg_error) ?></div>
        </div>
    <?php endif; ?>

    <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
        <!-- Formulário para Bloquear Novo Número -->
        <div class="bg-slate-900/60 border border-slate-800 rounded-2xl p-6 shadow-xl h-fit">
            <h3 class="text-base font-bold text-slate-100 mb-4 flex items-center gap-2">
                <svg class="w-5 h-5 text-rose-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v3m0 0v3m0-3h3m-3 0H9m12 0a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                Bloquear Número
            </h3>
            <form method="POST" class="space-y-4">
                <div>
                    <label class="block text-xs font-semibold text-slate-300 uppercase tracking-wider mb-2">Número de Telefone</label>
                    <input type="text" name="block_number" required placeholder="Ex: 11999998888 ou 01133334444"
                           class="w-full bg-slate-950 border border-slate-800 rounded-xl px-4 py-2.5 text-sm text-slate-200 focus:border-rose-500 focus:outline-none transition-colors">
                </div>
                <div>
                    <label class="block text-xs font-semibold text-slate-300 uppercase tracking-wider mb-2">Motivo / Descrição</label>
                    <input type="text" name="block_desc" placeholder="Ex: Telemarketing Spam, Cobrança indevida"
                           class="w-full bg-slate-950 border border-slate-800 rounded-xl px-4 py-2.5 text-sm text-slate-200 focus:border-rose-500 focus:outline-none transition-colors">
                </div>
                <button type="submit" name="action_add_blacklist" class="w-full py-2.5 bg-rose-600 hover:bg-rose-500 text-white text-sm font-semibold rounded-xl shadow-lg shadow-rose-600/20 transition-all flex items-center justify-center gap-2">
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M18.364 18.364A9 9 0 005.636 5.636m12.728 12.728A9 9 0 015.636 5.636m12.728 12.728L5.636 5.636"/></svg>
                    Bloquear Número no PABX
                </button>
            </form>
        </div>

        <!-- Tabela de Números Bloqueados -->
        <div class="lg:col-span-2 bg-slate-900/60 border border-slate-800 rounded-2xl p-6 shadow-xl">
            <h3 class="text-base font-bold text-slate-100 mb-4 flex items-center justify-between">
                <span>Números Bloqueados na Blacklist</span>
                <span class="text-xs px-2.5 py-1 rounded-full bg-slate-800 text-slate-400 font-normal"><?php echo  count($blacklistItems) ?> números</span>
            </h3>

            <?php if (empty($blacklistItems)): ?>
                <div class="p-8 text-center text-slate-500 text-sm bg-slate-950/40 rounded-xl border border-slate-800/60">
                    Nenhum número bloqueado na Blacklist até o momento.
                </div>
            <?php else: ?>
                <div class="overflow-x-auto">
                    <table class="w-full text-left text-sm text-slate-300">
                        <thead class="bg-slate-950/60 text-xs uppercase text-slate-400 border-b border-slate-800">
                            <tr>
                                <th class="py-3 px-4">Número</th>
                                <th class="py-3 px-4">Descrição</th>
                                <th class="py-3 px-4 text-right">Ação</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-800/60">
                            <?php foreach ($blacklistItems as $item): 
                                $num  = is_array($item) ? ($item['number'] ?? $item['number_blocked'] ?? '') : $item;
                                $desc = is_array($item) ? ($item['description'] ?? 'Bloqueado') : 'Bloqueado';
                            ?>
                                <tr class="hover:bg-slate-800/30 transition-colors">
                                    <td class="py-3 px-4 font-mono text-rose-300 font-semibold"><?php echo  htmlspecialchars($num) ?></td>
                                    <td class="py-3 px-4 text-slate-400 text-xs"><?php echo  htmlspecialchars($desc) ?></td>
                                    <td class="py-3 px-4 text-right">
                                        <form method="POST" onsubmit="return confirm('Deseja remover este número da blacklist?');">
                                            <input type="hidden" name="delete_number" value="<?php echo  htmlspecialchars($num) ?>">
                                            <button type="submit" name="action_delete_blacklist" class="px-3 py-1 bg-slate-800 hover:bg-rose-500/20 text-rose-400 border border-slate-700 hover:border-rose-500/40 rounded-lg text-xs transition-all">
                                                Desbloquear
                                            </button>
                                        </form>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>
    </div>
</div>
