<?php
/**
 * IPbx Prisma - Módulo Histórico de Envio WhatsApp & Análise IA
 */

$search_phone = isset($_GET['search_phone']) ? trim($_GET['search_phone']) : '';

// 1. Filtro unificado de data
$preset = isset($_GET['preset']) ? $_GET['preset'] : 'today';
$start_date = isset($_GET['start_date']) ? $_GET['start_date'] : date('Y-m-d 00:00:00');
$end_date = isset($_GET['end_date']) ? $_GET['end_date'] : date('Y-m-d 23:59:59');

if ($preset === 'today') {
    $start_date = date('Y-m-d 00:00:00');
    $end_date = date('Y-m-d 23:59:59');
} elseif ($preset === 'yesterday') {
    $start_date = date('Y-m-d 00:00:00', strtotime('-1 day'));
    $end_date = date('Y-m-d 23:59:59', strtotime('-1 day'));
} elseif ($preset === 'week') {
    $start_date = date('Y-m-d 00:00:00', strtotime('-7 days'));
    $end_date = date('Y-m-d 23:59:59');
} elseif ($preset === 'month') {
    $start_date = date('Y-m-01 00:00:00');
    $end_date = date('Y-m-t 23:59:59');
}

$where_conds = ["created_at >= '$start_date'", "created_at <= '$end_date'"];

if (!empty($search_phone)) {
    $search_clean = addslashes($search_phone);
    $where_conds[] = "(phone LIKE '%$search_clean%' OR extension LIKE '%$search_clean%' OR message LIKE '%$search_clean%')";
}

$where_hist = 'WHERE ' . implode(' AND ', $where_conds);
$logs = $db->query("SELECT * FROM sent_logs $where_hist ORDER BY id DESC LIMIT 500")->fetchAll(PDO::FETCH_ASSOC);

$contacts_map = getContactsMap();

// Exportação COMPLETA do período/filtros selecionados (a tela mostra no máximo 500 linhas)
if (isset($_GET['export']) && in_array($_GET['export'], ['csv', 'xlsx', 'pdf'], true)) {
    $__fmt = $_GET['export'];
    $__cap = ($__fmt === 'pdf') ? 20000 : 200000;
    $__all = $db->query("SELECT id, created_at, phone, extension, rule_type, status, message, response_raw FROM sent_logs $where_hist ORDER BY id DESC LIMIT " . (int)$__cap)->fetchAll(PDO::FETCH_ASSOC);
    $__cm = getContactsMap();
    $__rows = [['ID', 'Data/Hora', 'Telefone', 'Contato', 'Ramal', 'Tipo', 'Status', 'Mensagem', 'Retorno da API']];
    $__ok = 0; $__err = 0;
    foreach ($__all as $__l) {
        $__nm = function_exists('lookupContactName') ? lookupContactName($__l['phone'], $__cm) : '';
        if ($__l['status'] === 'SUCCESS') $__ok++; elseif ($__l['status'] === 'ERROR') $__err++;
        $__rows[] = [$__l['id'], $__l['created_at'], $__l['phone'], $__nm, $__l['extension'], $__l['rule_type'], $__l['status'], $__l['message'], $__l['status'] === 'SUCCESS' ? '' : mb_substr((string)$__l['response_raw'], 0, 300)];
    }
    $__sum = "Período: " . date('d/m/Y H:i', strtotime($start_date)) . " a " . date('d/m/Y H:i', strtotime($end_date)) . ($search_phone !== '' ? " | Busca: {$search_phone}" : '') . "\n"
        . "Mensagens no período: " . count($__all) . " | Sucesso: {$__ok} | Erros: {$__err}";
    reportExportSend($__fmt, 'historico_whatsapp_' . date('Ymd_His'), 'Histórico de Envios WhatsApp', $__sum, $__rows);
}
?>

<div class="space-y-6">
    <div class="bg-slate-900/90 border border-slate-800 rounded-xl p-5 shadow-xl">
        <div class="flex flex-col sm:flex-row items-start sm:items-center justify-between border-b border-slate-800 pb-4 mb-4 gap-3">
            <div>
                <h3 class="text-sm font-bold text-white flex items-center gap-2">
                    <i class="fa-solid fa-clock-rotate-left text-blue-400"></i> Histórico Completo de Disparos WhatsApp
                </h3>
                <span class="text-xs text-slate-400">Registros auditáveis de mensagens enviadas via API Prismabot com análises de IA</span>
            </div>

            <!-- Filtro de Busca -->
            <!-- Filtro Unificado -->
            <form method="GET" action="index.php" class="flex flex-wrap items-center gap-2 w-full sm:w-auto">
                <input type="hidden" name="module" value="whatsapp">
                <input type="hidden" name="action" value="historico">
                
                <div class="flex items-center bg-slate-950 border border-slate-800 rounded-xl overflow-hidden p-1">
                    <button type="button" onclick="setPresetFilter('today')" class="<?php echo $preset == 'today' ? 'bg-brand-600 text-white' : 'text-slate-400 hover:text-white hover:bg-slate-800'; ?> px-3 py-1 rounded-lg text-[10px] font-bold transition">Hoje</button>
                    <button type="button" onclick="setPresetFilter('yesterday')" class="<?php echo $preset == 'yesterday' ? 'bg-brand-600 text-white' : 'text-slate-400 hover:text-white hover:bg-slate-800'; ?> px-3 py-1 rounded-lg text-[10px] font-bold transition">Ontem</button>
                    <button type="button" onclick="setPresetFilter('week')" class="<?php echo $preset == 'week' ? 'bg-brand-600 text-white' : 'text-slate-400 hover:text-white hover:bg-slate-800'; ?> px-3 py-1 rounded-lg text-[10px] font-bold transition">7 Dias</button>
                    <button type="button" onclick="setPresetFilter('month')" class="<?php echo $preset == 'month' ? 'bg-brand-600 text-white' : 'text-slate-400 hover:text-white hover:bg-slate-800'; ?> px-3 py-1 rounded-lg text-[10px] font-bold transition">Mês</button>
                    <button type="button" onclick="document.getElementById('custom-date-filters').classList.toggle('hidden'); document.getElementById('preset_input').value='custom';" class="<?php echo $preset == 'custom' ? 'bg-brand-600 text-white' : 'text-slate-400 hover:text-white hover:bg-slate-800'; ?> px-3 py-1 rounded-lg text-[10px] font-bold transition"><i class="fa-solid fa-calendar text-brand-300"></i></button>
                </div>
                <input type="hidden" name="preset" id="preset_input" value="<?php echo htmlspecialchars($preset); ?>">
                
                <div id="custom-date-filters" class="<?php echo $preset == 'custom' ? 'flex' : 'hidden'; ?> items-center gap-2">
                    <input type="datetime-local" name="start_date" value="<?php echo date('Y-m-d\TH:i', strtotime($start_date)); ?>" class="px-3 py-1.5 bg-slate-950 border border-slate-800 rounded-xl text-white text-xs focus:border-brand-500 focus:outline-none">
                    <span class="text-slate-500 text-xs font-bold">até</span>
                    <input type="datetime-local" name="end_date" value="<?php echo date('Y-m-d\TH:i', strtotime($end_date)); ?>" class="px-3 py-1.5 bg-slate-950 border border-slate-800 rounded-xl text-white text-xs focus:border-brand-500 focus:outline-none">
                </div>

                <input type="text" name="search_phone" value="<?php echo htmlspecialchars($search_phone); ?>" placeholder="Buscar número/ramal..." class="px-3 py-1.5 bg-slate-950 border border-slate-800 rounded-xl text-white text-xs focus:border-brand-500 focus:outline-none w-full sm:w-48">
                
                <button type="submit" class="px-3.5 py-1.5 bg-brand-600 hover:bg-brand-500 text-white rounded-xl text-xs font-bold transition flex items-center gap-1">
                    <i class="fa-solid fa-filter"></i> Filtrar
                </button>
                
                <button type="button" onclick="exportHistoricoFull('csv')" class="px-3 py-1.5 bg-slate-800 hover:bg-slate-700 text-emerald-400 border border-slate-700 rounded-xl text-xs font-bold transition flex items-center gap-1" title="Exportar CSV (todo o período selecionado)">
                    <i class="fa-solid fa-file-csv"></i>
                </button>
                <button type="button" onclick="exportHistoricoFull('xlsx')" class="px-3 py-1.5 bg-slate-800 hover:bg-slate-700 text-green-400 border border-slate-700 rounded-xl text-xs font-bold transition flex items-center gap-1" title="Exportar Excel (todo o período selecionado)">
                    <i class="fa-solid fa-file-excel"></i>
                </button>
                <button type="button" onclick="exportHistoricoFull('pdf')" class="px-3 py-1.5 bg-slate-800 hover:bg-slate-700 text-rose-400 border border-slate-700 rounded-xl text-xs font-bold transition flex items-center gap-1" title="Exportar PDF (todo o período selecionado)">
                    <i class="fa-solid fa-file-pdf"></i>
                </button>
            </form>
        </div>

        <div class="overflow-x-auto">
            <table class="w-full text-left text-xs text-slate-300">
                <thead class="bg-slate-950 uppercase text-[10px] font-bold text-slate-400 tracking-wider">
                    <tr>
                        <th class="p-3">ID</th>
                        <th class="p-3">Data/Hora</th>
                        <th class="p-3">Telefone Destino</th>
                        <th class="p-3">Ramal / Fila</th>
                        <th class="p-3">Regra</th>
                        <th class="p-3">Mensagem</th>
                        <th class="p-3">Status</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-800 font-mono">
                    <?php if (empty($logs)): ?>
                        <tr>
                            <td colspan="7" class="p-6 text-center text-slate-500">Nenhum histórico de disparo registrado no sistema.</td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($logs as $l): ?>
                            <tr class="hover:bg-slate-800/40 transition">
                                <td class="p-3 text-slate-500">#<?php echo $l['id']; ?></td>
                                <td class="p-3 text-slate-400"><?php echo htmlspecialchars($l['created_at']); ?></td>
                                <?php 
                                    $dst_contact = function_exists('lookupContactData') ? lookupContactData($l['phone'], $contacts_map) : false;
                                    $dst_name = lookupContactName($l['phone'], $contacts_map);
                                    $dst_display = $dst_name ?: formatEndpointName($l['phone']);
                                ?>
                                <td class="p-3">
                                    <div class="font-bold text-white flex items-center gap-1 group">
                                        <i class="fa-brands fa-whatsapp text-emerald-400 text-[10px]"></i>
                                        <?php if ($dst_contact && isset($dst_contact['id'])): ?>
                                            <?php 
                                            $c_data = $dst_contact;
                                            $tooltip = "Contato CRM\nEmpresa: " . ($c_data['company'] ?: 'N/D') . "\nE-mail: " . ($c_data['email'] ?: 'N/D') . "\nEtiqueta: " . ($c_data['tag'] ?: 'N/D') . "\nObs: " . ($c_data['notes'] ?: 'N/D');
                                            ?>
                                            <button type="button" onclick="openGlobalAddContactModal('<?php echo htmlspecialchars($c_data['phone']); ?>', '<?php echo $c_data['id']; ?>', '<?php echo htmlspecialchars($c_data['name'], ENT_QUOTES); ?>', '<?php echo htmlspecialchars($c_data['email'], ENT_QUOTES); ?>', '<?php echo htmlspecialchars($c_data['company'], ENT_QUOTES); ?>', '<?php echo htmlspecialchars($c_data['tag'], ENT_QUOTES); ?>', '<?php echo htmlspecialchars(preg_replace('/\r|\n/', ' ', $c_data['notes']), ENT_QUOTES); ?>')" title="<?php echo htmlspecialchars($tooltip); ?>" class="hover:underline hover:text-emerald-300 transition text-left cursor-pointer">
                                                <?php echo htmlspecialchars($dst_display); ?>
                                            </button>
                                        <?php elseif ($dst_contact && isset($dst_contact['source']) && $dst_contact['source'] == 'Issabel'): ?>
                                            <?php $tooltip = "Agenda PABX\nNome: " . $dst_contact['name'] . "\nTelefone: " . $dst_contact['phone']; ?>
                                            <button type="button" onclick="openGlobalAddContactModal('<?php echo htmlspecialchars($dst_contact['phone']); ?>', '', '<?php echo htmlspecialchars($dst_contact['name'], ENT_QUOTES); ?>', '', '', '', '')" title="<?php echo htmlspecialchars($tooltip); ?>" class="hover:underline hover:text-emerald-300 transition text-left cursor-pointer">
                                                <?php echo htmlspecialchars($dst_display); ?>
                                            </button>
                                        <?php else: ?>
                                            <span><?php echo htmlspecialchars($dst_display); ?></span>
                                            <?php if (!$dst_name && strlen(preg_replace('/\D/', '', $l['phone'])) >= 8): ?>
                                                <button type="button" onclick="openGlobalAddContactModal('<?php echo htmlspecialchars(preg_replace('/\D/', '', $l['phone'])); ?>')" title="Nomear este Contato na Agenda" class="text-[10px] text-brand-400 hover:underline transition"><i class="fa-solid fa-user-plus"></i></button>
                                            <?php endif; ?>
                                        <?php endif; ?>
                                    </div>
                                </td>
                                <td class="p-3 text-indigo-300"><?php echo htmlspecialchars(formatEndpointName($l['extension'] ?: '-')); ?></td>
                                <td class="p-3">
                                    <span class="px-2 py-0.5 rounded text-[10px] font-bold bg-slate-800 text-slate-300 border border-slate-700">
                                        <?php echo htmlspecialchars($l['rule_type'] ?: 'GERAL'); ?>
                                    </span>
                                </td>
                                <td class="p-3 font-sans truncate max-w-xs text-slate-300" title="<?php echo htmlspecialchars($l['message']); ?>">
                                    <?php echo htmlspecialchars($l['message']); ?>
                                </td>
                                <td class="p-3">
                                    <?php
                                    $st = strtoupper($l['status']);
                                    $st_badge = ($st === 'SUCCESS') ? 'bg-emerald-500/10 text-emerald-400 border border-emerald-500/20' : 'bg-rose-500/10 text-rose-400 border border-rose-500/20';
                                    ?>
                                    <span class="px-2 py-0.5 rounded text-[10px] font-bold <?php echo $st_badge; ?>"><?php echo $st; ?></span>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<script>
function setPresetFilter(preset) {
    document.getElementById('preset_input').value = preset;
    document.forms[0].submit();
}

function exportHistoricoFull(fmt) {
    const u = new URL(window.location.href);
    u.searchParams.set('export', fmt);
    window.location.href = u.toString();
}
</script>
