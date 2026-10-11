<?php
/**
 * IPbx Prisma - Gerenciamento de Ramais via REST API
 */

require_once __DIR__ . '/../../includes/pbxapi_client.php';
$apiClient = new PbxApiClient($db);

$msg = '';
$msg_type = 'success';

// -- 1. Atualizar Ramal via API REST
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action_update_ext_name'])) {
    $target_ext = trim($_POST['extension'] ?? '');
    $new_name   = trim($_POST['agent_name'] ?? '');
    $new_tech   = trim($_POST['tech'] ?? 'PJSIP');
    $whatsapp   = trim($_POST['whatsapp_number'] ?? '');
    $secret     = trim($_POST['secret'] ?? '');

    $notify_agent = isset($_POST['notify_agent_missed']) ? 1 : 0;
    $notify_agent_int = isset($_POST['notify_agent_internal']) ? 1 : 0;
    $notify_client = isset($_POST['notify_client_busy']) ? 1 : 0;
    $send_ai = isset($_POST['send_ai_summary']) ? 1 : 0;

    if (!empty($target_ext) && !empty($new_name)) {
        // Atualizar no banco SQLite local
        $stmt_l = $db->prepare("UPDATE extensions_config SET 
            agent_name = :n, 
            tech = :t, 
            whatsapp_number = :w,
            notify_agent_missed = :nam,
            notify_agent_internal = :nai,
            notify_client_busy = :ncb,
            send_ai_summary = :sai,
            updated_at = CURRENT_TIMESTAMP 
            WHERE extension = :e");
        $stmt_l->execute([
            ':n' => $new_name, 
            ':t' => $new_tech, 
            ':w' => $whatsapp, 
            ':nam' => $notify_agent,
            ':nai' => $notify_agent_int,
            ':ncb' => $notify_client,
            ':sai' => $send_ai,
            ':e' => $target_ext
        ]);

        // Sincronizar destino opcional no PABX Asterisk (No Answer, Busy, Chanunavail)
        if (function_exists('syncExtensionDestinationsWithAsterisk')) {
            syncExtensionDestinationsWithAsterisk($target_ext, $notify_agent);
        }

        if (function_exists('pabx_log')) {
            pabx_log('security', 'INFO', "Permissões do ramal {$target_ext} ({$new_name}) atualizadas pelo operador.", [
                'ext' => $target_ext,
                'notify_missed' => $notify_agent,
                'send_ai' => $send_ai
            ]);
        }

        // Enviar atualização via REST API do PABX
        $apiPayload = ['name' => $new_name, 'tech' => strtolower($new_tech)];
        if (!empty($secret)) {
            $apiPayload['secret'] = $secret;
        }
        if (pbxapiAvailable()) {
            $apiRes = $apiClient->updateExtension($target_ext, $apiPayload);
        } else {
            // Sem REST API: grava o nome direto no Issabel (aparece também na interface nativa)
            $okDirect = renameExtensionInIssabel($target_ext, $new_name, $new_tech);
            $apiRes = ['success' => $okDirect, 'message' => $okDirect ? '' : 'não foi possível gravar no Issabel (verifique a senha do MySQL).'];
            if ($okDirect && !empty($secret)) { $msg_extra = ' A senha do ramal só pode ser alterada pela REST API ou pelo Issabel.'; }
        }

        if ($apiRes['success']) {
            $msg = "Ramal {$target_ext} e suas permissões atualizados com SUCESSO no PABX!" . ($msg_extra ?? '');
            $msg_type = 'success';
        } else {
            $msg = "Atualizado no painel local, mas aviso da API: " . ($apiRes['message'] ?? 'Verifique credenciais da API');
            $msg_type = 'warning';
        }
    } else {
        $msg = "Preencha o número do ramal e o nome do atendente.";
        $msg_type = 'error';
    }
}

// -- 1c. Salvar Permissões da Grade em Lote (Checkbox Grids)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action_save_grid_permissions'])) {
    $perm_missed   = $_POST['perm_missed'] ?? [];
    $perm_internal = $_POST['perm_internal'] ?? [];
    $perm_busy     = $_POST['perm_busy'] ?? [];
    $perm_ai       = $_POST['perm_ai'] ?? [];

    $all_exts = $db->query("SELECT extension FROM extensions_config")->fetchAll(PDO::FETCH_COLUMN);

    $stmt_upd = $db->prepare("UPDATE extensions_config SET 
        notify_agent_missed = :nam,
        notify_agent_internal = :nai,
        notify_client_busy = :ncb,
        send_ai_summary = :sai,
        updated_at = CURRENT_TIMESTAMP
        WHERE extension = :ext");

    $updated_count = 0;
    foreach ($all_exts as $eNum) {
        $nam = isset($perm_missed[$eNum]) ? 1 : 0;
        $nai = isset($perm_internal[$eNum]) ? 1 : 0;
        $ncb = isset($perm_busy[$eNum]) ? 1 : 0;
        $sai = isset($perm_ai[$eNum]) ? 1 : 0;

        $stmt_upd->execute([':nam' => $nam, ':nai' => $nai, ':ncb' => $ncb, ':sai' => $sai, ':ext' => $eNum]);
        if (function_exists('syncExtensionDestinationsWithAsterisk')) {
            syncExtensionDestinationsWithAsterisk($eNum, $nam);
        }
        $updated_count++;
    }

    $msg = "✅ Permissões dos ramais salvas com SUCESSO no PABX!";
    $msg_type = 'success';
    if (function_exists('pabx_log')) pabx_log('security', 'INFO', "Salvas permissões da grade em LOTE para {$updated_count} ramais.");
}

// -- 1b. Ações de Permissão em Lote (Bulk Actions)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action_bulk_permissions'])) {
    $bulk_type = $_POST['bulk_type'] ?? '';
    if ($bulk_type === 'enable_all_missed') {
        $db->exec("UPDATE extensions_config SET notify_agent_missed = 1, notify_agent_internal = 1, updated_at = CURRENT_TIMESTAMP");
        if (function_exists('syncExtensionDestinationsWithAsterisk')) {
            $all_exts = $db->query("SELECT extension FROM extensions_config")->fetchAll(PDO::FETCH_COLUMN);
            foreach ($all_exts as $eNum) {
                syncExtensionDestinationsWithAsterisk($eNum, 1);
            }
        }
        $msg = "Notificações de chamadas perdidas ativadas em LOTE para TODOS os ramais!";
        if (function_exists('pabx_log')) pabx_log('security', 'INFO', 'Ativadas notificações de perdidas em LOTE para todos os ramais.');
    } elseif ($bulk_type === 'enable_all_ai') {
        $db->exec("UPDATE extensions_config SET send_ai_summary = 1, updated_at = CURRENT_TIMESTAMP");
        $msg = "Resumos de Inteligência Artificial ativados em LOTE para TODOS os ramais!";
        if (function_exists('pabx_log')) pabx_log('security', 'INFO', 'Ativada IA de áudio em LOTE para todos os ramais.');
    } elseif ($bulk_type === 'disable_all') {
        $db->exec("UPDATE extensions_config SET notify_agent_missed = 0, notify_agent_internal = 0, notify_client_busy = 0, send_ai_summary = 0, updated_at = CURRENT_TIMESTAMP");
        if (function_exists('syncExtensionDestinationsWithAsterisk')) {
            $all_exts = $db->query("SELECT extension FROM extensions_config")->fetchAll(PDO::FETCH_COLUMN);
            foreach ($all_exts as $eNum) {
                syncExtensionDestinationsWithAsterisk($eNum, 0);
            }
        }
        $msg = "Todas as permissões foram desativadas em LOTE para TODOS os ramais.";
        if (function_exists('pabx_log')) pabx_log('security', 'INFO', 'Desativadas permissões em LOTE para todos os ramais.');
    }
}

// -- 2. Sincronizar todos os ramais da REST API para o painel
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action_sync_api_extensions'])) {
    $apiRes = $apiClient->getExtensions();
    if ($apiRes['success'] && is_array($apiRes['data'])) {
        $extsData = $apiRes['data']['results'] ?? $apiRes['data'];
        $synced = 0;
        foreach ($extsData as $extRow) {
            $extNum  = trim($extRow['extension'] ?? $extRow['id'] ?? '');
            $extName = trim($extRow['name'] ?? '');
            $extTech = strtoupper(trim($extRow['tech'] ?? 'PJSIP'));

            if (!empty($extNum)) {
                $db->prepare("INSERT OR REPLACE INTO extensions_config (extension, agent_name, tech, updated_at) VALUES (:e, :n, :t, CURRENT_TIMESTAMP)")
                   ->execute([':e' => $extNum, ':n' => $extName ?: "Ramal $extNum", ':t' => $extTech]);
                $synced++;
            }
        }
        $msg = "✅ $synced ramais sincronizados em tempo real via REST API do PABX!";
        $msg_type = 'success';
    } else {
        $msg = "Erro ao buscar ramais da REST API: " . ($apiRes['message'] ?? 'Falha na conexão');
        $msg_type = 'error';
    }
}

// -- 3. Criar Novo Ramal via REST API
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action_create_ext_api'])) {
    $newExt  = trim($_POST['new_extension'] ?? '');
    $newName = trim($_POST['new_name'] ?? '');
    $newSecret= trim($_POST['new_secret'] ?? '');

    if (!empty($newName)) {
        $payload = ['name' => $newName];
        if (!empty($newSecret)) $payload['secret'] = $newSecret;

        $apiRes = !empty($newExt) ? $apiClient->updateExtension($newExt, $payload) : $apiClient->createExtension($payload);

        if ($apiRes['success']) {
            $msg = "Novo ramal criado e registrado no PABX via REST API!";
            $msg_type = 'success';
            if (!empty($newExt)) {
                $db->prepare("INSERT OR REPLACE INTO extensions_config (extension, agent_name, tech, updated_at) VALUES (:e, :n, 'PJSIP', CURRENT_TIMESTAMP)")
                   ->execute([':e' => $newExt, ':n' => $newName]);
            }
        } else {
            $msg = "Erro ao criar ramal na API: " . ($apiRes['message'] ?? 'Falha na requisição');
            $msg_type = 'error';
        }
    }
}

$exts_list = $db->query("SELECT * FROM extensions_config ORDER BY CAST(extension AS UNSIGNED) ASC")->fetchAll(PDO::FETCH_ASSOC);
?>

<div class="space-y-6">
    <!-- Header -->
    <div class="bg-slate-900/80 backdrop-blur border border-slate-800 rounded-2xl p-6 shadow-xl flex flex-col md:flex-row items-start md:items-center justify-between gap-4">
        <div>
            <h2 class="text-xl font-bold text-slate-100 flex items-center gap-2">
                <svg class="w-6 h-6 text-cyan-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4.354a4 4 0 110 5.292M15 21H3v-1a6 6 0 0112 0v1zm0 0h6v-1a6 6 0 00-9-5.197M13 7a4 4 0 11-8 0 4 4 0 018 0z"/></svg>
                Gestão de Ramais & Atendentes
            </h2>
            <p class="text-xs text-slate-400">Edite nomes, senhas e WhatsApp de notificação sincronizados diretamente no PABX</p>
        </div>
        
        <form method="POST" class="flex items-center gap-2 flex-wrap">
            <input type="hidden" name="action_bulk_permissions" value="1">
            <?php if (pbxapiAvailable()): ?>
            <button type="submit" name="action_sync_api_extensions" class="px-3.5 py-2 bg-gradient-to-r from-cyan-500 to-blue-600 hover:from-cyan-400 hover:to-blue-500 text-white text-xs font-bold rounded-xl shadow-lg shadow-cyan-500/20 transition-all flex items-center gap-1.5">
                <i class="fa-solid fa-rotate"></i> Sincronizar API
            </button>
            <?php endif; ?>
            <button type="submit" name="bulk_type" value="enable_all_missed" title="Ativar Notificações de Perdidas em Todos os Ramais" class="px-3 py-2 bg-brand-600 hover:bg-brand-500 text-white text-xs font-bold rounded-xl transition flex items-center gap-1.5 shadow">
                <i class="fa-solid fa-bell"></i> Ativar Perdidas (Todos)
            </button>
            <button type="submit" name="bulk_type" value="enable_all_ai" title="Ativar Resumo IA em Todos os Ramais" class="px-3 py-2 bg-purple-600 hover:bg-purple-500 text-white text-xs font-bold rounded-xl transition flex items-center gap-1.5 shadow">
                <i class="fa-solid fa-wand-magic-sparkles"></i> Ativar IA (Todos)
            </button>
            <button type="submit" name="bulk_type" value="disable_all" title="Desativar Todas as Permissões" class="px-3 py-2 bg-rose-600/20 hover:bg-rose-600/30 text-rose-300 border border-rose-500/30 text-xs font-bold rounded-xl transition flex items-center gap-1.5">
                <i class="fa-solid fa-ban"></i> Desativar Todos
            </button>
        </form>
    </div>

    <!-- Alertas -->
    <?php if (!empty($msg)): ?>
        <div class="p-4 rounded-xl text-xs font-bold flex items-center justify-between <?php echo $msg_type === 'success' ? 'bg-emerald-500/10 text-emerald-400 border border-emerald-500/20' : ($msg_type === 'warning' ? 'bg-amber-500/10 text-amber-400 border border-amber-500/20' : 'bg-rose-500/10 text-rose-400 border border-rose-500/20'); ?>">
            <div class="flex items-center gap-2">
                <i class="fa-solid <?php echo $msg_type === 'success' ? 'fa-circle-check' : 'fa-circle-exclamation'; ?>"></i>
                <span><?php echo htmlspecialchars($msg); ?></span>
            </div>
        </div>
    <?php endif; ?>

    <!-- Tabela de Ramais & Permissões -->
    <form method="POST" class="bg-slate-900/90 border border-slate-800 rounded-2xl p-6 shadow-2xl space-y-5">
        <input type="hidden" name="action_save_grid_permissions" value="1">
        <div class="flex flex-col sm:flex-row items-start sm:items-center justify-between gap-3 border-b border-slate-800 pb-4">
            <div>
                <h3 class="text-base font-extrabold text-slate-100 flex items-center gap-2">
                    <i class="fa-solid fa-users-gear text-cyan-400"></i> Lista de Ramais, Autonomias & Permissões Operacionais
                </h3>
                <p class="text-xs text-slate-400">Marque ou desmarque as permissões desejadas diretamente nas colunas ou selecione todas pelo cabeçalho</p>
            </div>
            
            <button type="submit" class="px-5 py-2.5 bg-gradient-to-r from-emerald-500 to-teal-600 hover:from-emerald-400 hover:to-teal-500 text-white text-xs font-black rounded-xl shadow-lg shadow-emerald-500/20 transition-all flex items-center gap-2">
                <i class="fa-solid fa-floppy-disk"></i> Salvar Permissões Alteradas
            </button>
        </div>

        <!-- Barra de Busca Rápida -->
        <div class="flex flex-col sm:flex-row items-center justify-between gap-3 bg-slate-950/80 p-3.5 rounded-2xl border border-slate-800/80">
            <div class="relative w-full sm:w-96">
                <i class="fa-solid fa-magnifying-glass absolute left-3.5 top-3 text-slate-500 text-xs"></i>
                <input type="text" id="ext-search-input" onkeyup="filterExtensionsTable()" placeholder="Buscar por número do ramal ou nome do atendente..."
                       class="w-full pl-9 pr-4 py-2 bg-slate-900 border border-slate-800 rounded-xl text-white text-xs placeholder-slate-500 focus:border-cyan-500 focus:outline-none transition shadow-inner">
            </div>
            
            <div class="text-xs text-slate-400 font-mono">
                Total de Ramais: <strong class="text-cyan-400"><?php echo count($exts_list); ?></strong>
            </div>
        </div>

        <div class="overflow-x-auto">
            <table class="w-full text-left text-xs text-slate-300">
                <thead class="bg-slate-950 uppercase text-[10px] text-slate-400 border-b border-slate-800">
                    <tr>
                        <th class="py-3 px-3">Ramal</th>
                        <th class="py-3 px-3">Nome do Atendente</th>
                        <th class="py-3 px-3" style="display:none">Tecnologia</th>
                        
                        <!-- Coluna 1: Perdidas Ext. com Checkbox (Todos) -->
                        <th class="py-3 px-3 text-center">
                            <div class="flex flex-col items-center justify-center gap-1">
                                <span title="Notificar chamadas perdidas externas no WhatsApp">Perdidas Ext.</span>
                                <label class="inline-flex items-center gap-1 cursor-pointer text-[9px] font-bold text-slate-400 normal-case select-none">
                                    <input type="checkbox" onchange="toggleColCheckboxes('col-perm-missed', this.checked)" class="w-3.5 h-3.5 rounded bg-slate-900 border-slate-700 text-emerald-500 focus:ring-0">
                                    <span>(Todos)</span>
                                </label>
                            </div>
                        </th>

                        <!-- Coluna 2: Perdidas Int. com Checkbox (Todos) -->
                        <th class="py-3 px-3 text-center">
                            <div class="flex flex-col items-center justify-center gap-1">
                                <span title="Notificar chamadas perdidas internas no WhatsApp">Perdidas Int.</span>
                                <label class="inline-flex items-center gap-1 cursor-pointer text-[9px] font-bold text-slate-400 normal-case select-none">
                                    <input type="checkbox" onchange="toggleColCheckboxes('col-perm-internal', this.checked)" class="w-3.5 h-3.5 rounded bg-slate-900 border-slate-700 text-emerald-500 focus:ring-0">
                                    <span>(Todos)</span>
                                </label>
                            </div>
                        </th>

                        <!-- Coluna 3: Notif. Ocupado com Checkbox (Todos) -->
                        <th class="py-3 px-3 text-center">
                            <div class="flex flex-col items-center justify-center gap-1">
                                <span title="Notificar cliente quando o ramal estiver ocupado">Notif. Ocupado</span>
                                <label class="inline-flex items-center gap-1 cursor-pointer text-[9px] font-bold text-slate-400 normal-case select-none">
                                    <input type="checkbox" onchange="toggleColCheckboxes('col-perm-busy', this.checked)" class="w-3.5 h-3.5 rounded bg-slate-900 border-slate-700 text-blue-500 focus:ring-0">
                                    <span>(Todos)</span>
                                </label>
                            </div>
                        </th>

                        <!-- Coluna 4: Resumo IA com Checkbox (Todos) -->
                        <th class="py-3 px-3 text-center">
                            <div class="flex flex-col items-center justify-center gap-1">
                                <span title="Enviar Resumo de IA das chamadas de áudio via WhatsApp para este atendente" class="text-purple-400 font-bold flex items-center gap-1"><i class="fa-solid fa-wand-magic-sparkles"></i> IA</span>
                                <label class="inline-flex items-center gap-1 cursor-pointer text-[9px] font-bold text-purple-300 normal-case select-none">
                                    <input type="checkbox" onchange="toggleColCheckboxes('col-perm-ai', this.checked)" class="w-3.5 h-3.5 rounded bg-slate-900 border-slate-700 text-purple-500 focus:ring-0">
                                    <span>(Todos)</span>
                                </label>
                            </div>
                        </th>

                        <th class="py-3 px-3">WhatsApp</th>
                        <th class="py-3 px-3 text-right">Ação</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-800/60" id="ext-table-body">
                    <?php foreach ($exts_list as $ext): ?>
                        <tr class="ext-row-item hover:bg-slate-800/30 transition-colors" data-ext="<?php echo htmlspecialchars($ext['extension']); ?>" data-name="<?php echo htmlspecialchars(strtolower($ext['agent_name'])); ?>">
                            <td class="py-3 px-3 font-mono text-cyan-400 font-bold"><?php echo htmlspecialchars($ext['extension']); ?></td>
                            <td class="py-3 px-3 font-bold text-white"><?php echo htmlspecialchars($ext['agent_name']); ?></td>
                            <td class="py-3 px-3" style="display:none"><span class="px-2 py-0.5 rounded bg-slate-800 text-slate-300 font-mono text-[10px]"><?php echo htmlspecialchars($ext['tech'] ?: 'PJSIP'); ?></span></td>
                            
                            <!-- Checkbox Interativo: Perdidas Ext. -->
                            <td class="py-3 px-3 text-center">
                                <label class="inline-flex items-center gap-1.5 cursor-pointer select-none">
                                    <input type="checkbox" name="perm_missed[<?php echo $ext['extension']; ?>]" value="1" <?php echo !empty($ext['notify_agent_missed']) ? 'checked' : ''; ?> onchange="updatePermBadge(this, 'Sim', 'Não')" class="col-perm-missed perm-checkbox w-4 h-4 rounded bg-slate-950 border-slate-700 text-emerald-500 focus:ring-0 cursor-pointer">
                                    <span class="perm-badge text-[10px] font-bold px-2 py-0.5 rounded <?php echo !empty($ext['notify_agent_missed']) ? 'bg-emerald-500/20 text-emerald-300 border border-emerald-500/30' : 'bg-slate-800 text-slate-500'; ?>">
                                        <?php echo !empty($ext['notify_agent_missed']) ? 'Sim' : 'Não'; ?>
                                    </span>
                                </label>
                            </td>

                            <!-- Checkbox Interativo: Perdidas Int. -->
                            <td class="py-3 px-3 text-center">
                                <label class="inline-flex items-center gap-1.5 cursor-pointer select-none">
                                    <input type="checkbox" name="perm_internal[<?php echo $ext['extension']; ?>]" value="1" <?php echo !empty($ext['notify_agent_internal']) ? 'checked' : ''; ?> onchange="updatePermBadge(this, 'Sim', 'Não')" class="col-perm-internal perm-checkbox w-4 h-4 rounded bg-slate-950 border-slate-700 text-emerald-500 focus:ring-0 cursor-pointer">
                                    <span class="perm-badge text-[10px] font-bold px-2 py-0.5 rounded <?php echo !empty($ext['notify_agent_internal']) ? 'bg-emerald-500/20 text-emerald-300 border border-emerald-500/30' : 'bg-slate-800 text-slate-500'; ?>">
                                        <?php echo !empty($ext['notify_agent_internal']) ? 'Sim' : 'Não'; ?>
                                    </span>
                                </label>
                            </td>

                            <!-- Checkbox Interativo: Notif. Ocupado -->
                            <td class="py-3 px-3 text-center">
                                <label class="inline-flex items-center gap-1.5 cursor-pointer select-none">
                                    <input type="checkbox" name="perm_busy[<?php echo $ext['extension']; ?>]" value="1" <?php echo !empty($ext['notify_client_busy']) ? 'checked' : ''; ?> onchange="updatePermBadge(this, 'Sim', 'Não', 'blue')" class="col-perm-busy perm-checkbox w-4 h-4 rounded bg-slate-950 border-slate-700 text-blue-500 focus:ring-0 cursor-pointer">
                                    <span class="perm-badge text-[10px] font-bold px-2 py-0.5 rounded <?php echo !empty($ext['notify_client_busy']) ? 'bg-blue-500/20 text-blue-300 border border-blue-500/30' : 'bg-slate-800 text-slate-500'; ?>">
                                        <?php echo !empty($ext['notify_client_busy']) ? 'Sim' : 'Não'; ?>
                                    </span>
                                </label>
                            </td>

                            <!-- Checkbox Interativo: Resumo IA -->
                            <td class="py-3 px-3 text-center">
                                <label class="inline-flex items-center gap-1.5 cursor-pointer select-none">
                                    <input type="checkbox" name="perm_ai[<?php echo $ext['extension']; ?>]" value="1" <?php echo !empty($ext['send_ai_summary']) ? 'checked' : ''; ?> onchange="updatePermBadge(this, 'Sim', 'Não', 'purple')" class="col-perm-ai perm-checkbox w-4 h-4 rounded bg-slate-950 border-slate-700 text-purple-500 focus:ring-0 cursor-pointer">
                                    <span class="perm-badge text-[10px] font-bold px-2 py-0.5 rounded <?php echo !empty($ext['send_ai_summary']) ? 'bg-purple-500/20 text-purple-300 border border-purple-500/30' : 'bg-slate-800 text-slate-500'; ?>">
                                        <?php echo !empty($ext['send_ai_summary']) ? 'Sim' : 'Não'; ?>
                                    </span>
                                </label>
                            </td>

                            <td class="py-3 px-3 text-slate-400 font-mono text-[11px]"><?php echo htmlspecialchars($ext['whatsapp_number'] ?: '-'); ?></td>
                            <td class="py-3 px-3 text-right whitespace-nowrap">
                                <button type="button" onclick="openExtHistory('<?php echo htmlspecialchars($ext['extension'], ENT_QUOTES); ?>', '<?php echo htmlspecialchars($ext['agent_name'], ENT_QUOTES); ?>')" style="white-space:nowrap !important;flex-shrink:0 !important;width:auto !important;overflow:visible !important;text-overflow:clip !important;" class="px-2.5 py-1 mr-1 rounded-lg bg-slate-800 hover:bg-slate-700 text-slate-200 text-[11px] font-bold border border-slate-700">
                                    <i class="fa-solid fa-clock-rotate-left mr-1"></i> Histórico
                                </button>
                                <button type="button" onclick="openEditModal('<?php echo $ext['extension']; ?>', '<?php echo htmlspecialchars($ext['agent_name'], ENT_QUOTES); ?>', '<?php echo $ext['tech']; ?>', '<?php echo htmlspecialchars($ext['whatsapp_number'] ?? '', ENT_QUOTES); ?>', <?php echo !empty($ext['notify_agent_missed'])?1:0; ?>, <?php echo !empty($ext['notify_agent_internal'])?1:0; ?>, <?php echo !empty($ext['notify_client_busy'])?1:0; ?>, <?php echo !empty($ext['send_ai_summary'])?1:0; ?>)" class="px-3 py-1 bg-slate-800 hover:bg-slate-700 text-slate-200 border border-slate-700 rounded-lg text-xs transition font-bold">
                                    <i class="fa-solid fa-pen-to-square mr-1"></i> Editar
                                </button>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>

        <div class="pt-3 border-t border-slate-800 flex justify-end">
            <button type="submit" class="px-6 py-2.5 bg-gradient-to-r from-emerald-500 to-teal-600 hover:from-emerald-400 hover:to-teal-500 text-white text-xs font-black rounded-xl shadow-lg shadow-emerald-500/20 transition-all flex items-center gap-2">
                <i class="fa-solid fa-floppy-disk"></i> Salvar Permissões Alteradas
            </button>
        </div>
    </form>
    </div>
</div>

<!-- Modal de Edição de Ramal & Permissões -->
<div id="modal-edit-extension" class="fixed inset-0 z-50 hidden bg-slate-950/80 backdrop-blur-sm flex items-center justify-center p-4">
    <div class="bg-slate-900 border border-slate-800 rounded-2xl w-full max-w-lg p-6 shadow-2xl space-y-4">
        <div class="flex items-center justify-between border-b border-slate-800 pb-3">
            <h3 class="text-base font-bold text-white">Editar Ramal & Permissões Operacionais</h3>
            <button onclick="closeModal('modal-edit-extension')" class="text-slate-400 hover:text-white"><i class="fa-solid fa-xmark"></i></button>
        </div>

        <form method="POST" class="space-y-3 text-xs">
            <input type="hidden" name="action_update_ext_name" value="1">
            <div class="grid grid-cols-2 gap-3">
                <div>
                    <label class="block text-slate-300 font-bold mb-1">Ramal</label>
                    <input type="text" id="modal_extension" name="extension" readonly class="w-full bg-slate-950 border border-slate-800 rounded-xl px-3 py-2 text-cyan-400 font-mono font-bold">
                </div>
                <div style="display:none">
                    <label class="block text-slate-300 font-bold mb-1">Tecnologia</label>
                    <select id="modal_tech" name="tech" tabindex="-1" onmousedown="return false" onkeydown="return false" title="Definida no Issabel ao criar o ramal" class="w-full bg-slate-950 border border-slate-800 rounded-xl px-3 py-2 text-slate-400 opacity-70 cursor-not-allowed">
                        <option value="PJSIP">PJSIP</option>
                        <option value="SIP">SIP (Chan_SIP)</option>
                        <option value="IAX2">IAX2</option>
                    </select>
                    <p class="text-[10px] text-slate-500 mt-1">Definida no Issabel ao criar o ramal (somente leitura).</p>
                </div>
            </div>

            <div>
                <label class="block text-slate-300 font-bold mb-1">Nome do Atendente</label>
                <input type="text" id="modal_agent_name" name="agent_name" required class="w-full bg-slate-950 border border-slate-800 rounded-xl px-3 py-2 text-white">
            </div>

            <div>
                <label class="block text-slate-300 font-bold mb-1">Celular / WhatsApp (Notificações)</label>
                <input type="text" id="modal_whatsapp" name="whatsapp_number" placeholder="Ex: 5511999998888" class="w-full bg-slate-950 border border-slate-800 rounded-xl px-3 py-2 text-white font-mono">
            </div>

            <div>
                <label class="block text-slate-300 font-bold mb-1">Nova Senha SIP (Opcional)</label>
                <input type="password" name="secret" placeholder="Manter senha atual se vazio" class="w-full bg-slate-950 border border-slate-800 rounded-xl px-3 py-2 text-white">
            </div>

            <!-- Permissões de Notificação em Checkbox -->
            <div class="p-3 bg-slate-950 rounded-xl border border-slate-800 space-y-2">
                <span class="text-[10px] uppercase font-bold text-cyan-400 block tracking-wider">Permissões de Notificação WhatsApp</span>
                <div class="grid grid-cols-2 gap-2 text-slate-300">
                    <label class="flex items-center gap-2 cursor-pointer">
                        <input type="checkbox" id="modal_notify_missed" name="notify_agent_missed" value="1" class="rounded accent-cyan-500">
                        <span>Perdidas Externa</span>
                    </label>
                    <label class="flex items-center gap-2 cursor-pointer">
                        <input type="checkbox" id="modal_notify_internal" name="notify_agent_internal" value="1" class="rounded accent-cyan-500">
                        <span>Perdidas Interna</span>
                    </label>
                    <label class="flex items-center gap-2 cursor-pointer">
                        <input type="checkbox" id="modal_notify_busy" name="notify_client_busy" value="1" class="rounded accent-cyan-500">
                        <span>Notificar Ocupado</span>
                    </label>
                    <label class="flex items-center gap-2 cursor-pointer">
                        <input type="checkbox" id="modal_send_ai" name="send_ai_summary" value="1" class="rounded accent-purple-500">
                        <span>Enviar Resumo IA</span>
                    </label>
                </div>
            </div>

            <div class="pt-3 flex justify-end gap-2">
                <button type="button" onclick="closeModal('modal-edit-extension')" class="px-4 py-2 bg-slate-800 text-slate-300 rounded-xl font-bold">Cancelar</button>
                <button type="submit" class="px-5 py-2 bg-cyan-600 hover:bg-cyan-500 text-white font-bold rounded-xl shadow">Salvar no PABX (API)</button>
            </div>
        </form>
    </div>
</div>

<script>
function openEditModal(ext, name, tech, wa, missed, internal, busy, ai) {
    document.getElementById('modal_extension').value = ext;
    document.getElementById('modal_agent_name').value = name;
    document.getElementById('modal_tech').value = tech || 'PJSIP';
    document.getElementById('modal_whatsapp').value = wa || '';
    
    document.getElementById('modal_notify_missed').checked = !!missed;
    document.getElementById('modal_notify_internal').checked = !!internal;
    document.getElementById('modal_notify_busy').checked = !!busy;
    document.getElementById('modal_send_ai').checked = !!ai;

    document.getElementById('modal-edit-extension').classList.remove('hidden');
}

function toggleColCheckboxes(colClass, isChecked) {
    const checkboxes = document.querySelectorAll('.' + colClass);
    checkboxes.forEach(cb => {
        cb.checked = isChecked;
        let colorType = 'emerald';
        if (colClass.includes('busy')) colorType = 'blue';
        if (colClass.includes('ai')) colorType = 'purple';
        updatePermBadge(cb, 'Sim', 'Não', colorType);
    });
}

function updatePermBadge(checkbox, textOn, textOff, colorType = 'emerald') {
    const labelContainer = checkbox.parentElement;
    if (!labelContainer) return;
    const badge = labelContainer.querySelector('.perm-badge');
    if (!badge) return;

    if (checkbox.checked) {
        badge.innerText = textOn;
        if (colorType === 'blue') {
            badge.className = 'perm-badge text-[10px] font-bold px-2 py-0.5 rounded bg-blue-500/20 text-blue-300 border border-blue-500/30';
        } else if (colorType === 'purple') {
            badge.className = 'perm-badge text-[10px] font-bold px-2 py-0.5 rounded bg-purple-500/20 text-purple-300 border border-purple-500/30';
        } else {
            badge.className = 'perm-badge text-[10px] font-bold px-2 py-0.5 rounded bg-emerald-500/20 text-emerald-300 border border-emerald-500/30';
        }
    } else {
        badge.innerText = textOff;
        badge.className = 'perm-badge text-[10px] font-bold px-2 py-0.5 rounded bg-slate-800 text-slate-500';
    }
}

function filterExtensionsTable() {
    const q = (document.getElementById('ext-search-input')?.value || '').toLowerCase().trim();
    const rows = document.querySelectorAll('.ext-row-item');
    rows.forEach(r => {
        const ext = (r.getAttribute('data-ext') || '').toLowerCase();
        const name = (r.getAttribute('data-name') || '').toLowerCase();
        if (!q || ext.includes(q) || name.includes(q)) {
            r.style.display = '';
        } else {
            r.style.display = 'none';
        }
    });
}
</script>

<style>
dialog#modal-hist-ramal { background:#1c1b2f; color:#e2e8f0; border:1px solid #3b3a5c; border-radius:16px; padding:0; width:min(900px,94vw); max-height:88vh; }
dialog#modal-hist-ramal::backdrop { background: rgba(0,0,0,0.65); backdrop-filter: blur(4px); }
</style>
<script>
function openExtHistory(ext, name) {
    let d = document.getElementById('modal-hist-ramal');
    if (!d) {
        d = document.createElement('dialog');
        d.id = 'modal-hist-ramal';
        d.innerHTML = '<div style="display:flex;justify-content:space-between;align-items:center;padding:14px 18px;border-bottom:1px solid #3b3a5c;background:linear-gradient(90deg,#7c3aed,#6366f1);border-radius:16px 16px 0 0"><b id="hist-ramal-title" style="color:#fff;font-size:14px"></b><button type="button" id="hist-ramal-close" style="white-space:nowrap !important;flex-shrink:0 !important;width:auto !important;overflow:visible !important;text-overflow:clip !important;background:rgba(255,255,255,.15);color:#fff;border-radius:8px;padding:4px 12px;font-size:12px;font-weight:700">Fechar</button></div><div id="hist-ramal-body" style="padding:16px 18px;overflow:auto;max-height:72vh;font-size:12px"></div>';
        document.body.appendChild(d);
        d.querySelector('#hist-ramal-close').addEventListener('click', () => d.close());
        d.addEventListener('click', e => { if (e.target === d) d.close(); });
    }
    const esc = t => String(t == null ? '' : t).replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
    d.querySelector('#hist-ramal-title').textContent = 'Histórico de envios — Ramal ' + ext + (name ? ' · ' + name : '');
    const body = d.querySelector('#hist-ramal-body');
    body.innerHTML = '<span style="color:#94a3b8">Carregando...</span>';
    d.showModal();
    fetch('index.php?api_action=get_ext_history&ext=' + encodeURIComponent(ext)).then(r => r.json()).then(j => {
        if (!j.success) { body.innerHTML = '<span style="color:#fb7185">' + esc(j.error || 'Falha ao carregar') + '</span>'; return; }
        if (!j.logs.length) { body.innerHTML = '<span style="color:#94a3b8">Nenhuma mensagem enviada por este ramal ainda.</span>'; return; }
        let h = '<div style="display:flex;gap:8px;margin-bottom:12px"><span style="background:#10b98133;color:#6ee7b7;padding:3px 10px;border-radius:8px;font-weight:700">Sucesso: ' + j.totals.SUCCESS + '</span><span style="background:#f43f5e33;color:#fda4af;padding:3px 10px;border-radius:8px;font-weight:700">Erros: ' + j.totals.ERROR + '</span><span style="background:#33415566;color:#cbd5e1;padding:3px 10px;border-radius:8px;font-weight:700">Outros: ' + j.totals.OUTROS + '</span></div>';
        j.logs.forEach(l => {
            const ok = l.status === 'SUCCESS', bad = (l.status === 'ERROR' || l.status === 'FAILED');
            const col = ok ? '#6ee7b7' : (bad ? '#fda4af' : '#cbd5e1');
            h += '<div style="background:#252440;border:1px solid #3b3a5c;border-radius:12px;padding:10px 12px;margin-bottom:8px">'
              + '<div style="display:flex;justify-content:space-between;gap:8px;flex-wrap:wrap;margin-bottom:6px"><span style="color:#a5b4fc;font-weight:700">' + esc(l.rule_type || '-') + '</span><span style="color:' + col + ';font-weight:700">' + esc(l.status) + '</span><span style="color:#94a3b8">' + esc(l.created_at) + '</span></div>'
              + '<div style="color:#94a3b8;margin-bottom:4px">Para: <span style="font-family:monospace;color:#e2e8f0">' + esc(l.phone || '-') + '</span></div>'
              + '<div style="white-space:pre-wrap;color:#e2e8f0">' + esc(l.message) + '</div>'
              + (bad && l.response_raw ? '<div style="margin-top:6px;padding:6px 8px;background:#f43f5e1a;border-radius:8px;color:#fda4af;font-family:monospace;word-break:break-all">Retorno da API: ' + esc(l.response_raw) + '</div>' : '')
              + '</div>';
        });
        body.innerHTML = h;
    }).catch(e => { body.innerHTML = '<span style="color:#fb7185">Erro de comunicação: ' + esc(e.message) + '</span>'; });
}
</script>
