<?php
/**
 * IPbx Prisma - Módulo Gravações & CDR v6.1 (Player Moderno, Filtros & Resumo IA)
 */

$ast_db = getAsteriskDBConnection();
if ($ast_db) {
    try {
        $ast_db->exec("USE asteriskcdrdb");
    } catch (Exception $e_use) {}
}

$filter_preset  = $_GET['preset'] ?? '';

if ($filter_preset === 'today') {
    $date_start_raw = date('Y-m-d 00:00:00');
    $date_end_raw   = date('Y-m-d 23:59:59');
} elseif ($filter_preset === 'yesterday') {
    $date_start_raw = date('Y-m-d 00:00:00', strtotime('yesterday'));
    $date_end_raw   = date('Y-m-d 23:59:59', strtotime('yesterday'));
} elseif ($filter_preset === 'month') {
    $date_start_raw = date('Y-m-01 00:00:00');
    $date_end_raw   = date('Y-m-d 23:59:59');
} elseif ($filter_preset === 'custom' || $filter_preset === 'personalizado') {
    $date_start_raw = $_GET['date_start'] ?? date('Y-m-d 00:00:00', strtotime('-7 days'));
    $date_end_raw   = $_GET['date_end']   ?? date('Y-m-d 23:59:59');
} else {
    // DEFAULT: 7 DIAS (week)
    $filter_preset  = 'week';
    $date_start_raw = date('Y-m-d 00:00:00', strtotime('-7 days'));
    $date_end_raw   = date('Y-m-d 23:59:59');
}
$date_start     = date('Y-m-d H:i:s', strtotime($date_start_raw));
$date_end       = date('Y-m-d H:i:s', strtotime($date_end_raw));
$src_filter     = trim($_GET['src_filter'] ?? '');
$status_filter  = $_GET['status_filter'] ?? 'ALL';
$type_filter    = $_GET['type_filter'] ?? 'ALL';

// Conexão Nativa com o banco de dados Asterisk CDR
$ast_db = function_exists('getAsteriskPdoConnection') ? getAsteriskPdoConnection('asteriskcdrdb') : null;
$cdr_list = [];

if ($ast_db) {
    try {
        $where_clauses = ["calldate BETWEEN :ds AND :de"];
        $params = [
            ':ds' => date('Y-m-d H:i:s', strtotime($date_start)),
            ':de' => date('Y-m-d H:i:s', strtotime($date_end))
        ];

        if ($src_filter !== '') {
            $where_clauses[] = "(src LIKE :src OR dst LIKE :src)";
            $params[':src'] = "%$src_filter%";
        }

        if ($status_filter !== 'ALL') {
            $where_clauses[] = "disposition = :st";
            $params[':st'] = $status_filter;
        }

        if ($type_filter === 'INTERNAL') {
            $where_clauses[] = "LENGTH(src) <= 4 AND LENGTH(dst) <= 4";
        } elseif ($type_filter === 'EXTERNAL') {
            $where_clauses[] = "(LENGTH(src) > 4 OR LENGTH(dst) > 4)";
        }

        $sql = "SELECT calldate, src, dst, disposition, duration, billsec, uniqueid, recordingfile, lastapp, dcontext FROM cdr WHERE " . implode(' AND ', $where_clauses) . " ORDER BY calldate DESC LIMIT 100";
        $q_cdr = $ast_db->prepare($sql);
        $q_cdr->execute($params);
        $cdr_list = $q_cdr->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e) {}
}
?>

<div class="space-y-6">
    <!-- Header & Filtros no Padrão Brasil -->
    <div class="bg-slate-900/90 border border-slate-800 rounded-xl p-5 shadow-xl space-y-4">
        <div class="flex items-center justify-between border-b border-slate-800 pb-3">
            <div>
                <h3 class="text-sm font-bold text-white flex items-center gap-2">
                    <i class="fa-solid fa-headset text-brand-400"></i> Gravações & CDR do PABX
                </h3>
                <span class="text-xs text-slate-400">Filtre por datas no padrão Brasil, escute gravações ao vivo e gere resumos com Inteligência Artificial</span>
            </div>
            
            <!-- Botões Rápidos de Exportação e Impressão (Somente Ícones) -->
            <div class="flex items-center gap-2 flex-wrap print:hidden">
                <button type="button" onclick="exportToCSV()" title="Exportar CSV" class="p-2.5 bg-slate-800 hover:bg-slate-700 text-emerald-400 border border-slate-700 rounded-xl font-bold transition flex items-center justify-center shadow">
                    <i class="fa-solid fa-file-csv text-base"></i>
                </button>
                <button type="button" onclick="window.print()" title="Imprimir / Gerar PDF" class="p-2.5 bg-slate-800 hover:bg-slate-700 text-rose-400 border border-slate-700 rounded-xl font-bold transition flex items-center justify-center shadow">
                    <i class="fa-solid fa-file-pdf text-base"></i>
                </button>
            </div>
        </div>

        <form method="GET" action="index.php" class="flex flex-col gap-3">
            <input type="hidden" name="module" value="relatorios">
            <input type="hidden" name="action" value="cdr_gravacoes">

            <!-- Filtro Unificado -->
            <div class="flex flex-wrap items-center gap-2 w-full">
                <input type="hidden" name="preset" id="preset_input" value="<?php echo htmlspecialchars($filter_preset); ?>">
                
                <div class="flex items-center bg-slate-950 border border-slate-800 rounded-xl p-1 gap-1 flex-wrap text-xs">
                    <button type="button" onclick="setPresetFilter('today')" class="px-3 py-1.5 rounded-lg font-bold transition <?php echo $filter_preset == 'today' ? 'bg-brand-600 text-white shadow' : 'text-slate-400 hover:text-white hover:bg-slate-800'; ?>">Hoje</button>
                    <button type="button" onclick="setPresetFilter('yesterday')" class="px-3 py-1.5 rounded-lg font-bold transition <?php echo $filter_preset == 'yesterday' ? 'bg-brand-600 text-white shadow' : 'text-slate-400 hover:text-white hover:bg-slate-800'; ?>">Ontem</button>
                    <button type="button" onclick="setPresetFilter('week')" class="px-3 py-1.5 rounded-lg font-bold transition <?php echo ($filter_preset == 'week' || !$filter_preset) ? 'bg-brand-600 text-white shadow font-black' : 'text-slate-400 hover:text-white hover:bg-slate-800'; ?>">7 Dias</button>
                    <button type="button" onclick="setPresetFilter('month')" class="px-3 py-1.5 rounded-lg font-bold transition <?php echo $filter_preset == 'month' ? 'bg-brand-600 text-white shadow' : 'text-slate-400 hover:text-white hover:bg-slate-800'; ?>">Mês</button>
                    <button type="button" onclick="document.getElementById('custom-date-filters').classList.toggle('hidden'); document.getElementById('preset_input').value='custom';" class="px-3 py-1.5 rounded-lg font-bold transition <?php echo ($filter_preset == 'custom' || $filter_preset == 'personalizado') ? 'bg-brand-600 text-white shadow' : 'text-slate-400 hover:text-white hover:bg-slate-800'; ?>" title="Personalizado (Selecionar Datas)"><i class="fa-solid fa-calendar-days text-sm"></i></button>
                </div>
                
                <div id="custom-date-filters" class="<?php echo ($filter_preset == 'custom' || $filter_preset == 'personalizado') ? 'flex' : 'hidden'; ?> items-center gap-2">
                    <input type="datetime-local" name="date_start" value="<?php echo date('Y-m-d\TH:i', strtotime($date_start)); ?>" onclick="try{this.showPicker();}catch(e){}" class="px-3 py-1.5 bg-slate-950 border border-slate-800 rounded-xl text-white text-xs focus:border-brand-500 focus:outline-none cursor-pointer">
                    <span class="text-slate-500 text-xs font-bold">até</span>
                    <input type="datetime-local" name="date_end" value="<?php echo date('Y-m-d\TH:i', strtotime($date_end)); ?>" onclick="try{this.showPicker();}catch(e){}" class="px-3 py-1.5 bg-slate-950 border border-slate-800 rounded-xl text-white text-xs focus:border-brand-500 focus:outline-none cursor-pointer">
                </div>

                <input type="text" name="src_filter" value="<?php echo htmlspecialchars($src_filter); ?>" placeholder="Ramal/Número..." class="px-3 py-1.5 bg-slate-950 border border-slate-800 rounded-xl text-white text-xs focus:border-brand-500 focus:outline-none w-full sm:w-48">
                
                <select name="status_filter" class="px-3 py-1.5 bg-slate-950 border border-slate-800 rounded-xl text-white text-xs focus:border-brand-500 focus:outline-none">
                    <option value="ALL" <?php echo $status_filter === 'ALL' ? 'selected' : ''; ?>>Todos Status</option>
                    <option value="ANSWERED" <?php echo $status_filter === 'ANSWERED' ? 'selected' : ''; ?>>Atendidas</option>
                    <option value="NO ANSWER" <?php echo $status_filter === 'NO ANSWER' ? 'selected' : ''; ?>>Não Atendidas</option>
                    <option value="BUSY" <?php echo $status_filter === 'BUSY' ? 'selected' : ''; ?>>Ocupadas</option>
                    <option value="FAILED" <?php echo $status_filter === 'FAILED' ? 'selected' : ''; ?>>Falhas</option>
                </select>

                <select name="type_filter" class="px-3 py-1.5 bg-slate-950 border border-slate-800 rounded-xl text-white text-xs focus:border-brand-500 focus:outline-none">
                    <option value="ALL" <?php echo $type_filter === 'ALL' ? 'selected' : ''; ?>>Todos Tipos</option>
                    <option value="INTERNAL" <?php echo $type_filter === 'INTERNAL' ? 'selected' : ''; ?>>Internas</option>
                    <option value="EXTERNAL" <?php echo $type_filter === 'EXTERNAL' ? 'selected' : ''; ?>>Externas</option>
                </select>
                
                <button type="submit" class="px-3.5 py-1.5 bg-brand-600 hover:bg-brand-500 text-white rounded-xl text-xs font-bold transition flex items-center gap-1">
                    <i class="fa-solid fa-filter"></i> Filtrar
                </button>
            </div>
        </form>
    </div>

<script>
function setPresetFilter(preset) {
    document.getElementById('preset_input').value = preset;
    document.forms[0].submit();
}
function exportToCSV() {
    let csv = "Data/Hora,Origem,Destino,Duracao_Faturada,Status\n";
    document.querySelectorAll(".cdr-row").forEach(tr => {
        let cols = tr.querySelectorAll("td");
        if (cols.length >= 6) {
            let dt = cols[0].innerText.trim().replace(/\n/g, ' ');
            let src = cols[1].innerText.trim().replace(/\n/g, ' ');
            let dst = cols[2].innerText.trim().replace(/\n/g, ' ');
            let dur = cols[3].innerText.trim().replace(/\n/g, ' ');
            let st = cols[5].innerText.trim().replace(/\n/g, ' ');
            csv += `"${dt}","${src}","${dst}","${dur}","${st}"\n`;
        }
    });
    const blob = new Blob([csv], { type: 'text/csv;charset=utf-8;' });
    const link = document.createElement("a");
    link.href = URL.createObjectURL(blob);
    link.download = "extrato_cdr_pabx.csv";
    link.click();
}
</script>

    <!-- Tabela de Gravações & CDR -->
    <div class="bg-slate-900/90 border border-slate-800 rounded-xl p-5 shadow-xl">
        <div class="overflow-x-auto">
            <table class="w-full text-left text-xs text-slate-300">
                <thead class="bg-slate-950 uppercase text-[10px] font-bold text-slate-400 tracking-wider">
                    <tr>
                        <th class="p-3">Data/Hora (BR)</th>
                        <th class="p-3">Origem</th>
                        <th class="p-3">Destino</th>
                        <th class="p-3">Duração Faturada</th>
                        <th class="p-3">Resultado / Encerramento</th>
                        <th class="p-3">Status</th>
                        <th class="p-3 text-right">Player & Copiloto IA</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-800 font-mono">
                    <?php if (empty($cdr_list)): ?>
                        <tr>
                            <td colspan="7" class="p-6 text-center text-slate-500">Nenhum registro CDR encontrado para os filtros selecionados.</td>
                        </tr>
                    <?php else: ?>
                        <?php 
                        $contacts_map = getContactsMap();

                        $isWaActive = function_exists('isWhatsappApiActive') ? isWhatsappApiActive() : !empty(getSetting('whatsapp_api_url'));
                        $isSmtpActive = !empty(getSetting('smtp_host'));
                        $jsonSmtpFile = __DIR__ . '/../../config/smtp_settings.json';
                        if (file_exists($jsonSmtpFile)) {
                            $smtpData = @json_decode(file_get_contents($jsonSmtpFile), true);
                            if (!empty($smtpData['is_active']) || !empty($smtpData['host'])) $isSmtpActive = true;
                        }
                        $isAiActive = !empty(getSetting('ai_api_key')) || !empty(getSetting('openai_api_key')) || !empty(getSetting('gemini_api_key')) || !empty(getSetting('groq_api_key'));

                        foreach ($cdr_list as $c): 
                            $st_raw = strtoupper($c['disposition']);
                            $st_badge = 'bg-slate-800 text-slate-400 border border-slate-700';
                            $st_label = 'Falhou';

                            if ($st_raw === 'ANSWERED') {
                                $st_badge = 'bg-emerald-500/20 text-emerald-300 border border-emerald-500/30';
                                $st_label = 'Atendida';
                            } elseif ($st_raw === 'NO ANSWER') {
                                $st_badge = 'bg-rose-500/20 text-rose-300 border border-rose-500/30';
                                $st_label = 'Não Atendeu';
                            } elseif ($st_raw === 'BUSY') {
                                $st_badge = 'bg-amber-500/20 text-amber-300 border border-amber-500/30';
                                $st_label = 'Ocupado';
                            }

                            // Encerramento amigável
                            $hung_by = 'Atendimento Concluído';
                            if ($st_raw === 'NO ANSWER') {
                                $hung_by = (strpos(strtolower($c['dcontext'] ?? ''), 'queue') !== false) ? 'Abandono na Fila' : 'Sem Resposta';
                            } elseif ($st_raw === 'BUSY') {
                                $hung_by = 'Ramal Ocupado';
                            } elseif ($st_raw === 'FAILED') {
                                $hung_by = 'Falha de Conexão';
                            }

                            $calldate_br = date('d/m/Y H:i:s', strtotime($c['calldate']));
                            $dur_br = gmdate('i:s', intval($c['billsec']));

                            $src_name = lookupContactName($c['src'], $contacts_map);
                            $dst_name = lookupContactName($c['dst'], $contacts_map);
                            
                            $src_contact = function_exists('lookupContactData') ? lookupContactData($c['src'], $contacts_map) : false;
                            $dst_contact = function_exists('lookupContactData') ? lookupContactData($c['dst'], $contacts_map) : false;
                            ?>
                            <tr class="cdr-row hover:bg-slate-800/40 transition">
                                <td class="p-3 text-slate-300 font-bold"><?php echo $calldate_br; ?></td>
                                <td class="p-3">
                                    <?php 
                                        $src_display = $src_name ?: formatEndpointName($c['src']);
                                    ?>
                                    <div class="font-bold text-white flex items-center gap-1 group">
                                        <i class="fa-solid fa-phone text-emerald-400 text-[10px]"></i>
                                        <?php if ($src_contact && isset($src_contact['id'])): ?>
                                            <?php 
                                            $c_data = $src_contact;
                                            $tooltip = "Contato CRM\nEmpresa: " . ($c_data['company'] ?: 'N/D') . "\nE-mail: " . ($c_data['email'] ?: 'N/D') . "\nEtiqueta: " . ($c_data['tag'] ?: 'N/D') . "\nObs: " . ($c_data['notes'] ?: 'N/D');
                                            ?>
                                            <button type="button" onclick="openGlobalAddContactModal('<?php echo htmlspecialchars($c_data['phone']); ?>', '<?php echo $c_data['id']; ?>', '<?php echo htmlspecialchars($c_data['name'], ENT_QUOTES); ?>', '<?php echo htmlspecialchars($c_data['email'], ENT_QUOTES); ?>', '<?php echo htmlspecialchars($c_data['company'], ENT_QUOTES); ?>', '<?php echo htmlspecialchars($c_data['tag'], ENT_QUOTES); ?>', '<?php echo htmlspecialchars(preg_replace('/\r|\n/', ' ', $c_data['notes']), ENT_QUOTES); ?>')" title="<?php echo htmlspecialchars($tooltip); ?>" class="hover:underline hover:text-emerald-300 transition text-left cursor-pointer">
                                                <?php echo htmlspecialchars($src_display); ?>
                                            </button>
                                        <?php elseif ($src_contact && isset($src_contact['source']) && $src_contact['source'] == 'Issabel'): ?>
                                            <?php $tooltip = "Agenda Issabel\nNome: " . $src_contact['name'] . "\nTelefone: " . $src_contact['phone']; ?>
                                            <button type="button" onclick="openGlobalAddContactModal('<?php echo htmlspecialchars($src_contact['phone']); ?>', '', '<?php echo htmlspecialchars($src_contact['name'], ENT_QUOTES); ?>', '', '', '', '')" title="<?php echo htmlspecialchars($tooltip); ?>" class="hover:underline hover:text-emerald-300 transition text-left cursor-pointer">
                                                <?php echo htmlspecialchars($src_display); ?>
                                            </button>
                                        <?php else: ?>
                                            <span><?php echo htmlspecialchars($src_display); ?></span>
                                            <?php if (!$src_name && strlen(preg_replace('/\D/', '', $c['src'])) >= 8): ?>
                                                <button type="button" onclick="openGlobalAddContactModal('<?php echo htmlspecialchars(preg_replace('/\D/', '', $c['src'])); ?>')" title="Nomear este Contato na Agenda" class="text-[10px] text-brand-400 hover:underline transition ml-1"><i class="fa-solid fa-user-plus"></i></button>
                                            <?php endif; ?>
                                        <?php endif; ?>
                                        <?php if (strlen(preg_replace('/\\D/', '', $c['src'])) >= 8): ?><?php if (strlen(preg_replace('/\\D/', '', $c['dst'])) >= 8): ?><button type="button" onclick="openCdrBlacklistModal('<?php echo htmlspecialchars(preg_replace('/\\D/', '', $c['dst'])); ?>')" title="Bloquear Número na Blacklist do PABX" class="text-[10px] text-rose-400 hover:text-rose-300 transition ml-1"><i class="fa-solid fa-ban"></i></button><?php endif; ?><?php endif; ?>
                                        <button type="button" onclick="originateCall('<?php echo htmlspecialchars(preg_replace('/\D/', '', $c['src'])); ?>', '<?php echo htmlspecialchars($src_display, ENT_QUOTES); ?>')" title="Ligar para <?php echo htmlspecialchars($src_display); ?>" class="text-[10px] text-emerald-500 hover:text-emerald-400 transition ml-1 opacity-50 group-hover:opacity-100"><i class="fa-solid fa-phone-volume"></i></button>
                                    </div>
                                </td>
                                <td class="p-3">
                                    <?php 
                                        $dst_display = $dst_name ?: formatEndpointName($c['dst']);
                                    ?>
                                    <div class="font-bold text-indigo-300 flex items-center gap-1 group">
                                        <i class="fa-solid fa-headset text-indigo-400 text-[10px]"></i>
                                        <?php if ($dst_contact && isset($dst_contact['id'])): ?>
                                            <?php 
                                            $c_data = $dst_contact;
                                            $tooltip = "Contato CRM\nEmpresa: " . ($c_data['company'] ?: 'N/D') . "\nE-mail: " . ($c_data['email'] ?: 'N/D') . "\nEtiqueta: " . ($c_data['tag'] ?: 'N/D') . "\nObs: " . ($c_data['notes'] ?: 'N/D');
                                            ?>
                                            <button type="button" onclick="openGlobalAddContactModal('<?php echo htmlspecialchars($c_data['phone']); ?>', '<?php echo $c_data['id']; ?>', '<?php echo htmlspecialchars($c_data['name'], ENT_QUOTES); ?>', '<?php echo htmlspecialchars($c_data['email'], ENT_QUOTES); ?>', '<?php echo htmlspecialchars($c_data['company'], ENT_QUOTES); ?>', '<?php echo htmlspecialchars($c_data['tag'], ENT_QUOTES); ?>', '<?php echo htmlspecialchars(preg_replace('/\r|\n/', ' ', $c_data['notes']), ENT_QUOTES); ?>')" title="<?php echo htmlspecialchars($tooltip); ?>" class="hover:underline hover:text-indigo-200 transition text-left cursor-pointer">
                                                <?php echo htmlspecialchars($dst_display); ?>
                                            </button>
                                        <?php elseif ($dst_contact && isset($dst_contact['source'])): ?>
                                            <?php $tooltip = "Agenda PABX\nNome: " . $dst_contact['name'] . "\nTelefone: " . $dst_contact['phone']; ?>
                                            <button type="button" onclick="openGlobalAddContactModal('<?php echo htmlspecialchars($dst_contact['phone']); ?>', '', '<?php echo htmlspecialchars($dst_contact['name'], ENT_QUOTES); ?>', '', '', '', '')" title="<?php echo htmlspecialchars($tooltip); ?>" class="hover:underline hover:text-indigo-200 transition text-left cursor-pointer">
                                                <?php echo htmlspecialchars($dst_display); ?>
                                            </button>
                                        <?php else: ?>
                                            <span><?php echo htmlspecialchars($dst_display); ?></span>
                                            <?php if (!$dst_name && strlen(preg_replace('/\D/', '', $c['dst'])) >= 8): ?>
                                                <button type="button" onclick="openGlobalAddContactModal('<?php echo htmlspecialchars(preg_replace('/\D/', '', $c['dst'])); ?>')" title="Nomear este Contato na Agenda" class="text-[10px] text-brand-400 hover:underline transition ml-1"><i class="fa-solid fa-user-plus"></i></button>
                                            <?php endif; ?>
                                        <?php endif; ?>
                                        <?php if (strlen(preg_replace('/\\D/', '', $c['dst'])) >= 8): ?><button type="button" onclick="openCdrBlacklistModal('<?php echo htmlspecialchars(preg_replace('/\\D/', '', $c['dst'])); ?>')" title="Bloquear Número na Blacklist do PABX" class="text-[10px] text-rose-400 hover:text-rose-300 transition ml-1"><i class="fa-solid fa-ban"></i></button><?php endif; ?>
                                        <button type="button" onclick="originateCall('<?php echo htmlspecialchars(preg_replace('/\D/', '', $c['dst'])); ?>', '<?php echo htmlspecialchars($dst_display, ENT_QUOTES); ?>')" title="Ligar para <?php echo htmlspecialchars($dst_display); ?>" class="text-[10px] text-emerald-500 hover:text-emerald-400 transition ml-1 opacity-50 group-hover:opacity-100"><i class="fa-solid fa-phone-volume"></i></button>
                                    </div>
                                </td>
                                <td class="p-3 text-slate-300 font-bold"><?php echo $dur_br; ?>s</td>
                                <td class="p-3 text-slate-300 text-xs font-semibold"><?php echo $hung_by; ?></td>
                                <td class="p-3">
                                    <span class="px-2 py-0.5 rounded text-[10px] font-bold <?php echo $st_badge; ?>"><?php echo $st_label; ?></span>
                                </td>
                                <td class="p-3 text-right">
                                     <div class="flex items-center justify-end gap-1.5">
                                         <?php if (!empty($c['recordingfile'])): ?>
                                             <?php if (hasUserPermission('listen_recordings')): ?>
                                                 <audio controls class="h-7 max-w-[180px] inline-block">
                                                     <source src="relatorio_filas.php?action=stream_audio&uid=<?php echo urlencode($c['uniqueid']); ?>" type="audio/wav">
                                                 </audio>
                                                 <a href="relatorio_filas.php?action=download_audio&uid=<?php echo urlencode($c['uniqueid']); ?>" title="📥 Download Áudio .WAV da Chamada" class="p-1.5 rounded bg-slate-800 hover:bg-slate-700 text-slate-300 hover:text-white transition">
                                                     <i class="fa-solid fa-download"></i>
                                                 </a>

                                                 <!-- Ícone 1: Copiloto IA (✨) -->
                                                 <?php if ($isAiActive): ?>
                                                     <button onclick="analyzeAudioAI('<?php echo htmlspecialchars($c['uniqueid']); ?>', '<?php echo htmlspecialchars($c['src']); ?>')" 
                                                             title="✨ Análise Inteligente & Transcrição por IA (Resumo do Atendimento)" 
                                                             class="p-1.5 rounded bg-purple-600 hover:bg-purple-500 text-white font-bold transition text-xs flex items-center justify-center shadow shadow-purple-600/30">
                                                         <i class="fa-solid fa-wand-magic-sparkles"></i>
                                                     </button>
                                                 <?php else: ?>
                                                     <button onclick="showToastNotification('Chave IA Necessária', 'Cadastre a API Key em Configurações > IA & Copiloto para ativar o resumo do atendimento.', 'warning')" 
                                                             title="⚠️ Necessário Chave de API de IA - Transcrição de áudio e análise de sentimento do atendimento. Configure em Configurações > IA & Copiloto." 
                                                             class="p-1.5 rounded bg-slate-800/80 text-slate-500 hover:text-purple-300 border border-slate-700/80 transition text-xs flex items-center justify-center opacity-60">
                                                         <i class="fa-solid fa-wand-magic-sparkles"></i>
                                                     </button>
                                                 <?php endif; ?>

                                                 <!-- Ícone 2: WhatsApp (🟢) -->
                                                 <?php if ($isWaActive): ?>
                                                     <button onclick="sendSingleCallWa('<?php echo htmlspecialchars($c['uniqueid']); ?>', '<?php echo htmlspecialchars($c['src']); ?>', '<?php echo htmlspecialchars($c['dst']); ?>', '<?php echo htmlspecialchars($c['calldate']); ?>', '<?php echo htmlspecialchars($c['duration']); ?>')" 
                                                             title="🟢 Enviar Áudio & Detalhes da Chamada via WhatsApp" 
                                                             class="p-1.5 rounded bg-emerald-600/20 hover:bg-emerald-500 text-emerald-400 hover:text-white border border-emerald-500/30 transition text-xs flex items-center justify-center">
                                                         <i class="fa-brands fa-whatsapp"></i>
                                                     </button>
                                                 <?php else: ?>
                                                     <button onclick="shareReportViaWhatsApp('Gravação #<?php echo $c['uniqueid']; ?> (<?php echo $c['src']; ?> ➔ <?php echo $c['dst']; ?>)')" 
                                                             title="⚠️ Necessário Configurar API do WhatsApp - Envio automático de gravações e dados da chamada. Configure em Configurações > WhatsApp & Automação." 
                                                             class="p-1.5 rounded bg-slate-800/80 text-slate-500 hover:text-emerald-400 border border-slate-700/80 transition text-xs flex items-center justify-center opacity-60">
                                                         <i class="fa-brands fa-whatsapp"></i>
                                                     </button>
                                                 <?php endif; ?>

                                                 <!-- Ícone 3: E-mail (📧) -->
                                                 <?php if ($isSmtpActive): ?>
                                                     <button onclick="sendSingleCallEmail('<?php echo htmlspecialchars($c['uniqueid']); ?>', '<?php echo htmlspecialchars($c['src']); ?>', '<?php echo htmlspecialchars($c['dst']); ?>', '<?php echo htmlspecialchars($c['calldate']); ?>', '<?php echo htmlspecialchars($c['duration']); ?>')" 
                                                             title="📧 Enviar Áudio & Detalhes da Chamada por E-mail" 
                                                             class="p-1.5 rounded bg-amber-600/20 hover:bg-amber-500 text-amber-400 hover:text-white border border-amber-500/30 transition text-xs flex items-center justify-center">
                                                         <i class="fa-solid fa-envelope"></i>
                                                     </button>
                                                 <?php else: ?>
                                                     <button onclick="shareReportViaEmail('Gravação #<?php echo $c['uniqueid']; ?> (<?php echo $c['src']; ?> ➔ <?php echo $c['dst']; ?>)')" 
                                                             title="⚠️ Necessário Configurar Servidor SMTP - Envio de relatórios e gravações por e-mail. Configure em Configurações > E-mail / SMTP." 
                                                             class="p-1.5 rounded bg-slate-800/80 text-slate-500 hover:text-amber-400 border border-slate-700/80 transition text-xs flex items-center justify-center opacity-60">
                                                         <i class="fa-solid fa-envelope"></i>
                                                     </button>
                                                 <?php endif; ?>
                                             <?php else: ?>
                                                 <span class="text-slate-500 italic text-[10px]" title="Sem permissão para ouvir gravações"><i class="fa-solid fa-lock"></i> Áudio Restrito</span>
                                             <?php endif; ?>
                                         <?php else: ?>
                                             <span class="text-slate-600 italic text-[11px]">Sem Gravação</span>
                                         <?php endif; ?>
                                     </div>
                                 </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <!-- BANNER DE ANÁLISE COM IA DESTE RELATÓRIO DE GRAVAÇÕES -->
    <div class="bg-slate-900/90 border border-purple-500/20 rounded-2xl p-5 shadow-xl space-y-3">
        <div class="flex items-center justify-between">
            <h3 class="text-sm font-extrabold text-white flex items-center gap-2">
                <i class="fa-solid fa-wand-magic-sparkles text-purple-400 animate-pulse"></i> Copiloto de IA: Analisar Gravações & CDR
            </h3>
            <button onclick="runCdrAiAnalysis()" id="btn-run-cdr-ai" class="px-4 py-1.5 bg-purple-600 hover:bg-purple-500 text-white font-extrabold rounded-xl text-xs transition shadow-lg shadow-purple-600/30 flex items-center gap-2">
                <i class="fa-solid fa-sparkles"></i> Analisar com IA
            </button>
        </div>
        <div id="cdr-ai-container" class="bg-slate-950/80 p-4 rounded-xl border border-slate-800 hidden">
            <p id="cdr-ai-text" class="text-slate-300 text-xs leading-relaxed italic">--</p>
        </div>
    </div>

    <script>
    async function runCdrAiAnalysis(isDemo = false) {
        const btn = document.getElementById('btn-run-cdr-ai');
        const container = document.getElementById('cdr-ai-container');
        const txt = document.getElementById('cdr-ai-text');
        if (!btn || !txt) return;

        const orig = btn.innerHTML;
        btn.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> Processando...';
        btn.disabled = true;

        try {
<?php
            $__ans = array_filter($cdr_list, function($r){ return $r['disposition'] === 'ANSWERED'; });
            $cdr_tma = count($__ans) ? array_sum(array_column($__ans, 'billsec')) / count($__ans) : 0;
            $cdr_tme = count($cdr_list) ? array_sum(array_map(function($r){ return max(0, $r['duration'] - $r['billsec']); }, $cdr_list)) / count($cdr_list) : 0;
?>
            const count = <?php echo count($cdr_list); ?>;
            const res = await fetch('index.php?api_action=analyze_pabx_insights', {
                method: 'POST',
                headers: {'Content-Type': 'application/json'},
                body: JSON.stringify({ total: count, atendidas: <?php echo count($__ans); ?>, tmaSec: <?php echo (int)$cdr_tma; ?>, tmeSec: <?php echo (int)$cdr_tme; ?>, module: 'CDR' })
            });
            const data = await res.json();

            container.classList.remove('hidden');
            if (data.ai_configured === false && !isDemo) {
                txt.innerHTML = `
                    <div class="p-3 bg-amber-500/10 border border-amber-500/30 rounded-xl space-y-2">
                        <div class="font-bold text-amber-400 flex items-center gap-1.5">
                            <i class="fa-solid fa-triangle-exclamation"></i> IA Não Configurada no Sistema
                        </div>
                        <p class="text-slate-300 text-xs">
                            Nenhuma API Key de IA (GroqCloud ou OpenAI) foi configurada em <strong>Configurações > Inteligência Artificial</strong>.
                        </p>
                    </div>
                `;
            } else if (data.success && data.insights) {
                const demoBadge = '';
                txt.innerHTML = `
                    <div class="space-y-2">
                        <div class="font-extrabold text-purple-300 flex items-center gap-1.5">
                            <i class="fa-solid fa-brain"></i> Análise de IA do CDR ${demoBadge}
                        </div>
                        <ul class="space-y-1.5 pl-1">
                            ${data.insights.map(i => `<li class="flex items-start gap-2 text-slate-300"><span class="text-purple-400">•</span> ${i}</li>`).join('')}
                        </ul>
                    </div>
                `;
                if (typeof showIaToast === 'function') showIaToast('Análise de IA do CDR atualizada!');
            }
        } catch(e) {
            alert('Erro ao processar análise: ' + e.message);
        } finally {
            btn.innerHTML = orig;
            btn.disabled = false;
        }
    }
    </script>
</div>

<!-- Modal Copiloto IA ZPRO para Análise de Chamadas -->
<div id="modal-ai-call-summary" class="fixed inset-0 z-50 hidden bg-slate-950/80 backdrop-blur-md flex items-center justify-center p-4">
    <div class="bg-slate-900 border border-purple-500/30 rounded-2xl max-w-lg w-full p-6 shadow-2xl space-y-4">
        <div class="flex items-center justify-between border-b border-slate-800 pb-3">
            <div class="flex items-center gap-2">
                <div class="w-8 h-8 rounded-full bg-purple-500/20 text-purple-300 flex items-center justify-center text-sm font-bold border border-purple-500/30">
                    <i class="fa-solid fa-robot"></i>
                </div>
                <div>
                    <h4 class="text-sm font-extrabold text-white">Copiloto IA - Análise da Chamada</h4>
                    <span id="ai-modal-subtitle" class="text-[11px] text-purple-300 font-mono">Processando áudio com Groq / Gemini / OpenAI...</span>
                </div>
            </div>
            <button onclick="closeAiModal()" class="text-slate-400 hover:text-white"><i class="fa-solid fa-xmark"></i></button>
        </div>

        <div id="ai-modal-content" class="space-y-3 text-xs text-slate-300 leading-relaxed font-sans">
            <div class="animate-pulse py-6 text-center text-purple-400">
                <i class="fa-solid fa-spinner fa-spin text-2xl mb-2 block"></i>
                <span>Transcrevendo áudio e gerando análise de sentimento...</span>
            </div>
        </div>

<?php
$isWaActive = function_exists('isWhatsappApiActive') ? isWhatsappApiActive() : false;
$jsonSmtpFile = __DIR__ . '/../../config/smtp_settings.json';
$isSmtpActive = false;
if (file_exists($jsonSmtpFile)) {
    $smtpData = @json_decode(file_get_contents($jsonSmtpFile), true);
    if (!empty($smtpData['is_active'])) $isSmtpActive = true;
}
?>

        <div class="pt-3 border-t border-slate-800 flex flex-wrap items-center justify-between gap-2">
            <div class="flex items-center gap-2">
                <button onclick="shareSummaryWhatsApp(<?php echo $isWaActive ? 'true' : 'false'; ?>)" class="px-3 py-1.5 rounded-xl text-xs font-bold transition flex items-center gap-1.5 <?php echo $isWaActive ? 'bg-emerald-600 hover:bg-emerald-500 text-white shadow-lg shadow-emerald-600/30' : 'bg-slate-800 text-slate-500 opacity-60 border border-slate-700 cursor-not-allowed'; ?>" title="<?php echo $isWaActive ? 'Enviar Resumo IA via WhatsApp' : 'API Prismabot Inativa'; ?>">
                    <i class="fa-brands fa-whatsapp"></i> Enviar WhatsApp
                </button>
                <button onclick="shareSummaryEmail(<?php echo $isSmtpActive ? 'true' : 'false'; ?>)" class="px-3 py-1.5 rounded-xl text-xs font-bold transition flex items-center gap-1.5 <?php echo $isSmtpActive ? 'bg-amber-600 hover:bg-amber-500 text-white shadow-lg shadow-amber-600/30' : 'bg-slate-800 text-slate-500 opacity-60 border border-slate-700 cursor-not-allowed'; ?>" title="<?php echo $isSmtpActive ? 'Enviar Resumo IA por E-mail' : 'Servidor SMTP Inativo'; ?>">
                    <i class="fa-solid fa-envelope"></i> Enviar E-mail
                </button>
            </div>
            <button onclick="closeAiModal()" class="px-4 py-1.5 bg-slate-800 hover:bg-slate-700 text-white font-bold rounded-xl text-xs transition">
                Fechar Análise
            </button>
        </div>
    </div>
</div>

<script>
    let __aiCall = null;

    function aiEsc(v) {
        return String(v == null ? '' : v).replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
    }

    async function aiPostJson(action, payload) {
        const res = await fetch('index.php?api_action=' + action, {
            method: 'POST',
            headers: {'Content-Type': 'application/json'},
            body: JSON.stringify(payload)
        });
        return res.json();
    }

    async function shareSummaryWhatsApp(active) {
        if (!active) {
            showToastNotification('Integração inativa', 'Configure a API do WhatsApp em Configurações > APIs & Conexões.', 'warning');
            return;
        }
        if (!__aiCall || !__aiCall.text) {
            showToastNotification('Sem análise', 'Rode a análise de IA da chamada antes de enviar.', 'warning');
            return;
        }
        const phone = prompt('Digite o número do WhatsApp com DDD (ex: 5511999998888):');
        if (!phone) return;
        try {
            const data = await aiPostJson('send_whatsapp_message', { phone: phone, message: __aiCall.text, uid: __aiCall.uid });
            if (data.success) showToastNotification('WhatsApp enviado', 'Resumo enviado para ' + phone + '.' + (data.warning ? ' ' + data.warning : ''), data.warning ? 'warning' : 'success');
            else showToastNotification('Falha no envio de WhatsApp', data.error || 'Erro desconhecido', 'error');
        } catch (e) { showToastNotification('Falha no envio de WhatsApp', e.message, 'error'); }
    }

    async function shareSummaryEmail(active) {
        if (!active) {
            showToastNotification('SMTP inativo', 'Configure o servidor em Configurações > Servidor SMTP.', 'warning');
            return;
        }
        if (!__aiCall || !__aiCall.text) {
            showToastNotification('Sem análise', 'Rode a análise de IA da chamada antes de enviar.', 'warning');
            return;
        }
        const email = prompt('Digite o e-mail de destino:');
        if (!email) return;
        try {
            const data = await aiPostJson('send_email_report', { to: email, subject: 'Resumo IA da chamada ' + __aiCall.uid, message: __aiCall.text, uid: __aiCall.uid });
            if (data.success) showToastNotification('E-mail enviado', 'Resumo enviado para ' + email + '.' + (data.warning ? ' ' + data.warning : ''), data.warning ? 'warning' : 'success');
            else showToastNotification('Falha no envio de e-mail', data.error || 'Erro desconhecido', 'error');
        } catch (e) { showToastNotification('Falha no envio de e-mail', e.message, 'error'); }
    }

    async function analyzeAudioAI(uid, src) {
        const modal = document.getElementById('modal-ai-call-summary');
        const content = document.getElementById('ai-modal-content');
        const subtitle = document.getElementById('ai-modal-subtitle');
        __aiCall = null;

        if (modal) modal.classList.remove('hidden');
        if (subtitle) subtitle.innerText = 'Analisando chamada de ' + src;
        if (!content) return;

        content.innerHTML = `
            <div class="animate-pulse py-6 text-center text-purple-400 space-y-2">
                <i class="fa-solid fa-robot fa-spin text-3xl block"></i>
                <span class="block font-bold">Transcrevendo o áudio e gerando a análise (pode levar alguns segundos)...</span>
            </div>`;

        try {
            const d = await aiPostJson('analyze_call_audio', { uid: uid });
            if (!d.success) {
                content.innerHTML = `<div class="p-3 bg-rose-500/10 border border-rose-500/30 rounded-xl text-rose-300">
                    <strong><i class="fa-solid fa-circle-xmark"></i> Não foi possível analisar a chamada</strong>
                    <p class="mt-1">${aiEsc(d.error || 'Erro desconhecido')}</p>
                    ${d.transcript ? `<details class="mt-2"><summary class="cursor-pointer text-[11px] font-bold text-slate-400">Ver transcrição obtida</summary><p class="mt-1 text-slate-300 whitespace-pre-wrap">${aiEsc(d.transcript)}</p></details>` : ''}</div>`;
                return;
            }
            const sat = (d.satisfacao == null) ? 'não inferida' : d.satisfacao + ' / 5';
            if (subtitle) subtitle.innerText = 'Análise por ' + d.provider + ' / ' + d.model;
            __aiCall = {
                uid: uid,
                text: '🧠 Resumo IA da chamada ' + uid + '\n\n' + d.resumo + '\n\nSentimento: ' + (d.sentimento || '-') + ' | Satisfação: ' + sat + '\nRecomendação: ' + (d.recomendacao || '-')
            };
            content.innerHTML = `
                <div class="space-y-3">
                    <div class="p-3 bg-slate-950 rounded-xl border border-purple-500/30 space-y-1">
                        <span class="text-[10px] font-bold text-purple-400 uppercase tracking-wider block">Resumo do atendimento</span>
                        <p class="text-white font-medium">${aiEsc(d.resumo)}</p>
                    </div>
                    <div class="grid grid-cols-2 gap-2 text-[11px]">
                        <div class="p-2.5 bg-emerald-500/10 border border-emerald-500/20 rounded-xl text-emerald-300"><strong>Sentimento:</strong> ${aiEsc(d.sentimento || '-')}</div>
                        <div class="p-2.5 bg-blue-500/10 border border-blue-500/20 rounded-xl text-blue-300"><strong>Satisfação:</strong> ${aiEsc(sat)}</div>
                    </div>
                    <div class="p-3 bg-slate-950 rounded-xl border border-slate-800 space-y-1">
                        <span class="text-[10px] font-bold text-slate-400 uppercase tracking-wider block">Recomendação</span>
                        <p class="text-slate-300">${aiEsc(d.recomendacao || '-')}</p>
                    </div>
                    <details class="p-3 bg-slate-950 rounded-xl border border-slate-800">
                        <summary class="cursor-pointer text-[11px] font-bold text-slate-400">Transcrição completa</summary>
                        <p class="mt-2 text-slate-300 whitespace-pre-wrap">${aiEsc(d.transcript)}</p>
                    </details>
                </div>`;
        } catch (e) {
            content.innerHTML = `<div class="p-3 bg-rose-500/10 border border-rose-500/30 rounded-xl text-rose-300">Falha na requisição: ${aiEsc(e.message)}</div>`;
        }
    }

    function closeAiModal() {
        const modal = document.getElementById('modal-ai-call-summary');
        if (modal) modal.classList.add('hidden');
    }
</script>


<!-- Modal de Bloqueio Rápido na Blacklist via CDR -->
<div id="modal-cdr-blacklist" class="fixed inset-0 z-50 hidden bg-slate-950/80 backdrop-blur-md flex items-center justify-center p-4">
    <div class="bg-slate-900 border border-rose-500/30 rounded-2xl max-w-md w-full p-6 shadow-2xl space-y-4">
        <div class="flex items-center justify-between border-b border-slate-800 pb-3">
            <div class="flex items-center gap-2">
                <div class="w-8 h-8 rounded-full bg-rose-500/20 text-rose-400 flex items-center justify-center text-sm font-bold border border-rose-500/30">
                    <i class="fa-solid fa-ban"></i>
                </div>
                <div>
                    <h4 class="text-sm font-extrabold text-white">Bloquear Número no PABX</h4>
                    <span class="text-[11px] text-rose-400 font-mono">Blacklist Inteligente</span>
                </div>
            </div>
            <button onclick="closeCdrBlacklistModal()" class="text-slate-400 hover:text-white"><i class="fa-solid fa-xmark"></i></button>
        </div>
        <form method="POST" action="index.php?module=telefonia&action=blacklist" class="space-y-3 text-xs">
            <input type="hidden" name="action_add_blacklist" value="1">
            <div>
                <label class="block font-bold text-slate-300 mb-1">Número a Bloquear</label>
                <input type="text" id="cdr_block_number" name="block_number" required readonly
                       class="w-full px-3.5 py-2.5 bg-slate-950 border border-slate-800 rounded-xl text-rose-400 font-mono font-bold">
            </div>
            <div>
                <label class="block font-bold text-slate-300 mb-1">Motivo / Descrição</label>
                <input type="text" name="block_desc" value="Bloqueado via Relatório CDR" required
                       class="w-full px-3.5 py-2.5 bg-slate-950 border border-slate-800 rounded-xl text-white placeholder-slate-600 focus:border-rose-500 focus:outline-none">
            </div>
            <div class="pt-2 flex justify-end gap-2">
                <button type="button" onclick="closeCdrBlacklistModal()" class="px-4 py-2 bg-slate-800 text-slate-300 rounded-xl">Cancelar</button>
                <button type="submit" class="px-4 py-2 bg-rose-600 hover:bg-rose-500 text-white font-bold rounded-xl shadow-lg shadow-rose-600/20">🚫 Bloquear Número</button>
            </div>
        </form>
    </div>
</div>

<script>
function openCdrBlacklistModal(num) {
    if (!num) return;
    document.getElementById('cdr_block_number').value = num;
    document.getElementById('modal-cdr-blacklist').classList.remove('hidden');
}
function closeCdrBlacklistModal() {
    document.getElementById('modal-cdr-blacklist').classList.add('hidden');
}
</script>
