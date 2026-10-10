<?php
/**
 * IPbx Prisma - Monitoramento Exclusivo de Filas Ao Vivo v8.5
 * Dados reais via API dinâmica com visualização limpa dos ramais.
 * Exibe nome + ramal com cor dinâmica do próprio card.
 */

$period_preset = isset($_GET['period']) ? $_GET['period'] : 'week';
$date_label = "Esta Semana (Últimos 7 Dias)";
if ($period_preset === 'today')     $date_label = "Hoje (" . date('d/m/Y') . ")";
if ($period_preset === 'yesterday') $date_label = "Ontem (" . date('d/m/Y', strtotime('-1 day')) . ")";
if ($period_preset === 'month')     $date_label = "Este Mês (" . date('m/Y') . ")";
if ($period_preset === 'quarter')   $date_label = "Últimos 3 Meses";
?>

<div class="space-y-6">
    <!-- Top Header -->
    <div class="bg-slate-900/90 border border-slate-800 rounded-2xl p-5 shadow-xl flex flex-col md:flex-row items-center justify-between gap-4">
        <div>
            <h3 class="text-base font-extrabold text-white flex items-center gap-2.5">
                <i class="fa-solid fa-users-rays text-purple-400 text-lg"></i> Monitoramento Exclusivo de Filas Ao Vivo
            </h3>
            <div class="flex items-center gap-2 text-xs text-slate-400 mt-0.5">
                <span class="font-bold text-emerald-400 flex items-center gap-1"><i class="fa-solid fa-calendar-check"></i> Período:</span>
                <span class="font-mono text-white font-bold bg-slate-950 px-2 py-0.5 rounded border border-slate-800"><?php echo $date_label; ?></span>
                <span id="realtime-last-update" class="text-slate-600 font-mono text-[10px]"></span>
            </div>
        </div>

        <div class="flex items-center gap-3 flex-wrap">
            <!-- Seletor de Período Padronizado -->
            <div class="flex items-center bg-slate-950 p-1 rounded-xl border border-slate-800 text-xs font-bold gap-1 flex-wrap">
                <a href="index.php?module=filas&action=realtime&period=today"
                   class="px-3 py-1.5 rounded-lg transition <?php echo $period_preset === 'today' ? 'bg-purple-600 text-white shadow-lg' : 'text-slate-400 hover:text-white'; ?>">
                    Hoje
                </a>
                <a href="index.php?module=filas&action=realtime&period=yesterday"
                   class="px-3 py-1.5 rounded-lg transition <?php echo $period_preset === 'yesterday' ? 'bg-purple-600 text-white shadow-lg' : 'text-slate-400 hover:text-white'; ?>">
                    Ontem
                </a>
                <a href="index.php?module=filas&action=realtime&period=week"
                   class="px-3 py-1.5 rounded-lg transition <?php echo ($period_preset === 'week' || !$period_preset) ? 'bg-purple-600 text-white shadow-lg font-black' : 'text-slate-400 hover:text-white'; ?>">
                    7 Dias
                </a>
                <a href="index.php?module=filas&action=realtime&period=month"
                   class="px-3 py-1.5 rounded-lg transition <?php echo $period_preset === 'month' ? 'bg-purple-600 text-white shadow-lg' : 'text-slate-400 hover:text-white'; ?>">
                    Mês
                </a>
            </div>

            <!-- Pesquisa -->
            <div class="relative w-52">
                <i class="fa-solid fa-magnifying-glass absolute left-3.5 top-3 text-slate-400 text-xs"></i>
                <input type="text" id="queue-search-input" onkeyup="filterQueuesDisplay()" placeholder="Filtrar por fila..."
                       class="w-full pl-9 pr-4 py-2 bg-slate-950 border border-slate-800 rounded-xl text-xs text-white focus:outline-none focus:border-purple-500 font-mono">
            </div>

            <!-- Botões de Visualização -->
            <div class="flex items-center gap-1 bg-slate-950 p-1 rounded-xl border border-slate-800 text-xs font-bold">
                <button id="view-btn-cards" onclick="setQueueViewMode('cards')" class="px-3 py-1.5 rounded-lg transition bg-purple-600 text-white flex items-center gap-1">
                    <i class="fa-solid fa-border-all"></i> Cards
                </button>
                <button id="view-btn-table" onclick="setQueueViewMode('table')" class="px-3 py-1.5 rounded-lg transition text-slate-400 hover:text-white flex items-center gap-1">
                    <i class="fa-solid fa-table"></i> Tabela
                </button>
            </div>

            <button onclick="loadQueuesRealtime()" class="px-3.5 py-2 bg-slate-800 hover:bg-slate-700 text-white rounded-xl text-xs font-bold transition border border-slate-700 flex items-center gap-1.5">
                <i class="fa-solid fa-rotate text-purple-400"></i> Atualizar
            </button>
        </div>
    </div>

    <!-- Card Guia & Menu Explicativo de Métricas e Status das Filas -->
    <div class="bg-slate-900/90 border border-slate-800 rounded-2xl p-4 shadow-lg space-y-3">
        <div class="flex items-center gap-2 font-bold text-slate-200 text-xs border-b border-slate-800 pb-2">
            <i class="fa-solid fa-circle-info text-purple-400 text-sm"></i>
            <span>Guia Explicativo das Métricas & Status em Tempo Real:</span>
        </div>
        <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-3 text-[11px]">
            <div class="p-2 bg-slate-950/80 rounded-xl border border-slate-800 space-y-0.5">
                <span class="font-extrabold text-purple-300 block">🎯 SLA (% de Nível de Serviço)</span>
                <p class="text-slate-400 leading-tight">Porcentagem de chamadas atendidas em relação ao total recebido na fila.</p>
            </div>
            <div class="p-2 bg-slate-950/80 rounded-xl border border-slate-800 space-y-0.5">
                <span class="font-extrabold text-emerald-400 block">📞 Atendidas / 🛑 Perdidas</span>
                <p class="text-slate-400 leading-tight">Volume de ligações concluídas pelos atendentes vs desistências de clientes.</p>
            </div>
            <div class="p-2 bg-slate-950/80 rounded-xl border border-slate-800 space-y-0.5">
                <span class="font-extrabold text-rose-400 block">⏳ Espera RTC</span>
                <p class="text-slate-400 leading-tight">Clientes aguardando atendimento agora no canal de áudio da fila.</p>
            </div>
            <div class="p-2 bg-slate-950/80 rounded-xl border border-slate-800 space-y-0.5">
                <span class="font-extrabold text-slate-200 block mb-1">Status do Ramal</span>
                <div class="flex items-center gap-2 font-semibold text-[10px] flex-wrap">
                    <span class="flex items-center gap-1 text-emerald-400"><span class="w-2 h-2 rounded-full bg-emerald-400 animate-pulse"></span> Livre</span>
                    <span class="flex items-center gap-1 text-rose-400"><span class="w-2 h-2 rounded-full bg-rose-500 animate-pulse"></span> Em Chamada</span>
                    <span class="flex items-center gap-1 text-slate-500"><span class="w-2 h-2 rounded-full bg-slate-500"></span> Off</span>
                </div>
            </div>
        </div>
    </div>

    <!-- Summary Bar -->
    <div id="queues-summary-bar" class="grid grid-cols-2 sm:grid-cols-4 gap-4 hidden">
        <div class="bg-slate-900/90 border border-slate-800 rounded-2xl p-4 text-center space-y-1">
            <span class="text-[10px] font-bold text-slate-400 uppercase tracking-wider block">Total de Filas</span>
            <span id="summary-total-queues" class="text-2xl font-extrabold text-white">0</span>
        </div>
        <div class="bg-slate-900/90 border border-rose-500/20 rounded-2xl p-4 text-center space-y-1">
            <span class="text-[10px] font-bold text-slate-400 uppercase tracking-wider block">Em Espera Agora</span>
            <span id="summary-waiting" class="text-2xl font-extrabold text-rose-400">0</span>
        </div>
        <div class="bg-slate-900/90 border border-emerald-500/20 rounded-2xl p-4 text-center space-y-1">
            <span class="text-[10px] font-bold text-slate-400 uppercase tracking-wider block">Atendidas (sessão)</span>
            <span id="summary-answered" class="text-2xl font-extrabold text-emerald-400">0</span>
        </div>
        <div class="bg-slate-900/90 border border-amber-500/20 rounded-2xl p-4 text-center space-y-1">
            <span class="text-[10px] font-bold text-slate-400 uppercase tracking-wider block">Abandonadas (sessão)</span>
            <span id="summary-abandoned" class="text-2xl font-extrabold text-amber-400">0</span>
        </div>
    </div>

    <!-- Loading State -->
    <div id="queues-loading" class="flex items-center justify-center py-16">
        <div class="text-center space-y-3">
            <i class="fa-solid fa-spinner fa-spin text-purple-400 text-3xl block"></i>
            <span class="text-slate-400 text-sm font-bold">Conectando via REST API...</span>
            <span class="text-slate-600 text-xs block">Carregando filas em tempo real do PABX</span>
        </div>
    </div>

    <!-- Error State -->
    <div id="queues-error" class="hidden p-6 bg-rose-500/10 border border-rose-500/30 rounded-2xl text-center space-y-2">
        <i class="fa-solid fa-triangle-exclamation text-rose-400 text-2xl block"></i>
        <p class="text-rose-300 font-bold text-sm">Erro ao carregar filas</p>
        <p id="queues-error-msg" class="text-rose-400/70 text-xs"></p>
        <button onclick="loadQueuesRealtime()" class="px-4 py-2 bg-rose-600/20 hover:bg-rose-600 text-rose-300 hover:text-white border border-rose-500/40 rounded-xl text-xs font-bold transition mt-2">
            <i class="fa-solid fa-rotate"></i> Tentar Novamente
        </button>
    </div>

    <!-- Grid de Filas (Cards) -->
    <div id="queues-cards-grid" class="hidden grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4 gap-5"></div>

    <!-- Grid de Filas (Tabela) -->
    <div id="queues-table-view" class="hidden bg-slate-900 border border-slate-800 rounded-2xl overflow-hidden shadow-xl">
        <table class="w-full text-left text-xs text-slate-300">
            <thead class="bg-slate-950 uppercase text-[10px] font-bold text-slate-400 tracking-wider">
                <tr>
                    <th class="p-3">Fila / Setor</th>
                    <th class="p-3 text-center">Em Espera</th>
                    <th class="p-3 text-center">Atendidas</th>
                    <th class="p-3 text-center">Abandonadas</th>
                    <th class="p-3 text-center">Tempo Médio</th>
                    <th class="p-3 text-right">Agentes</th>
                </tr>
            </thead>
            <tbody id="queues-table-body" class="divide-y divide-slate-800"></tbody>
        </table>
    </div>

    <!-- Empty State -->
    <div id="queues-empty" class="hidden flex items-center justify-center py-16">
        <div class="text-center space-y-3">
            <i class="fa-solid fa-inbox text-slate-600 text-4xl block"></i>
            <p class="text-slate-400 font-bold">Nenhuma fila cadastrada ou ativa</p>
            <p class="text-slate-600 text-xs">Configure filas no Asterisk e sincronize em Configurações &gt; API.</p>
        </div>
    </div>
</div>

<script>
let _queueViewMode = 'cards';
let _queueRefreshInterval = null;

function setQueueViewMode(mode) {
    _queueViewMode = mode;
    const btnCards = document.getElementById('view-btn-cards');
    const btnTable = document.getElementById('view-btn-table');
    const gridCards= document.getElementById('queues-cards-grid');
    const tableView= document.getElementById('queues-table-view');

    const activeClass  = 'px-3 py-1.5 rounded-lg transition bg-purple-600 text-white flex items-center gap-1';
    const inactiveClass= 'px-3 py-1.5 rounded-lg transition text-slate-400 hover:text-white flex items-center gap-1';

    if (mode === 'cards') {
        btnCards.className = activeClass;
        btnTable.className = inactiveClass;
        gridCards.classList.remove('hidden');
        tableView.classList.add('hidden');
    } else {
        btnTable.className = activeClass;
        btnCards.className = inactiveClass;
        tableView.classList.remove('hidden');
        gridCards.classList.add('hidden');
    }
}

async function loadQueuesRealtime(isSilent = false) {
    const loading   = document.getElementById('queues-loading');
    const errorDiv  = document.getElementById('queues-error');
    const emptyDiv  = document.getElementById('queues-empty');
    const cardsGrid = document.getElementById('queues-cards-grid');
    const tableBody = document.getElementById('queues-table-body');
    const summaryBar= document.getElementById('queues-summary-bar');
    const tableView = document.getElementById('queues-table-view');

    // Apenas exibe o spinner na primeira carga quando a tela ainda está vazia
    const isFirstTime = cardsGrid.classList.contains('hidden') && tableView.classList.contains('hidden');
    if (!isSilent && isFirstTime) {
        loading.classList.remove('hidden');
        errorDiv.classList.add('hidden');
        emptyDiv.classList.add('hidden');
    }

    try {
        const urlParams = new URLSearchParams(window.location.search);
        const activePeriod = urlParams.get('period') || 'today';
        const res    = await fetch('index.php?api_action=get_queues_realtime&period=' + encodeURIComponent(activePeriod));
        if (!res.ok) throw new Error('HTTP ' + res.status);
        const queues = await res.json();

        loading.classList.add('hidden');
        errorDiv.classList.add('hidden');

        if (!queues || queues.length === 0) {
            if (isFirstTime) emptyDiv.classList.remove('hidden');
            return;
        }

        emptyDiv.classList.add('hidden');

        // Atualizar summary
        let totalWaiting = 0, totalAnswered = 0, totalAbandoned = 0;
        queues.forEach(q => {
            totalWaiting   += (q.callers_count || 0);
            totalAnswered  += (q.answered_count || 0);
            totalAbandoned += (q.abandoned_count || 0);
        });
        document.getElementById('summary-total-queues').innerText = queues.length;
        document.getElementById('summary-waiting').innerText   = totalWaiting;
        document.getElementById('summary-answered').innerText  = totalAnswered;
        document.getElementById('summary-abandoned').innerText = totalAbandoned;
        summaryBar.classList.remove('hidden');

        // Atualizar timestamp
        const tsEl = document.getElementById('realtime-last-update');
        if (tsEl) tsEl.innerText = '· Atualizado ' + new Date().toLocaleTimeString('pt-BR', {hour:'2-digit',minute:'2-digit',second:'2-digit'});

        // Renderizar Cards
        cardsGrid.innerHTML = queues.map(q => {
            const hasCallers  = q.callers_count > 0;
            const borderCls   = hasCallers ? 'border-rose-500/40 hover:border-rose-500/70' : 'border-purple-500/30 hover:border-purple-500/60';
            const slaNum      = q.answered_count > 0 ? Math.round((q.answered_count / (q.answered_count + q.abandoned_count)) * 100) : 100;
            const slaCls      = slaNum >= 85 ? 'text-emerald-400 bg-emerald-500/10 border-emerald-500/30' : (slaNum >= 70 ? 'text-amber-400 bg-amber-500/10 border-amber-500/30' : 'text-rose-400 bg-rose-500/10 border-rose-500/30');

            const callersHtml = (q.callers && q.callers.length > 0) ? q.callers.map(c => {
                const callerDisp = c.caller_label || c.caller_name || c.caller_num || c.channel;
                return `
                <div class="flex items-center justify-between bg-slate-900 p-2 rounded-lg border border-rose-500/20 text-xs">
                    <div class="flex items-center gap-2 overflow-hidden mr-2">
                        <span class="w-5 h-5 rounded-full bg-rose-500/20 text-rose-300 font-bold flex items-center justify-center text-[10px] shrink-0">${c.pos}</span>
                        <div class="overflow-hidden">
                            <span class="font-bold text-white block text-[11px] truncate" title="${callerDisp}">${callerDisp}</span>
                            <span class="text-[10px] text-rose-400 font-mono">Espera: ${c.wait_time}</span>
                        </div>
                    </div>
                    <button onclick="fopPauseAgent('${q.queue_number}','${c.channel}',0)" class="px-2 py-1 bg-brand-600 hover:bg-brand-500 text-white rounded text-[9px] font-bold transition shrink-0">Capturar</button>
                </div>
            `;
            }).join('') : '<div class="text-slate-500 text-xs italic py-1">Nenhum cliente aguardando.</div>';

            const membersHtml = (q.members && q.members.length > 0) ? q.members.map(m => {
                let dotColor = 'bg-emerald-500 text-emerald-400 shadow-emerald-500/50';
                let iconClass = 'fa-solid fa-phone text-[10px] text-emerald-400';
                let statusBadge = '<span class="text-[9px] font-extrabold uppercase px-1.5 py-0.5 rounded bg-emerald-500/20 text-emerald-300 border border-emerald-500/30 shrink-0">Livre</span>';

                if (m.status === 'Em Chamada') {
                    dotColor = 'bg-rose-500 text-rose-400 animate-pulse';
                    iconClass = 'fa-solid fa-phone-flip text-[10px] text-rose-400 animate-bounce';
                    statusBadge = '<span class="text-[9px] font-extrabold uppercase px-1.5 py-0.5 rounded bg-rose-500/20 text-rose-300 border border-rose-500/30 shrink-0">Em Chamada</span>';
                } else if (m.status === 'Tocando') {
                    dotColor = 'bg-amber-400 text-amber-300 animate-bounce';
                    iconClass = 'fa-solid fa-bell text-[10px] text-amber-300 animate-pulse';
                    statusBadge = '<span class="text-[9px] font-extrabold uppercase px-1.5 py-0.5 rounded bg-amber-500/20 text-amber-300 border border-amber-500/30 shrink-0">Tocando</span>';
                } else if (m.status === 'Indisponível') {
                    dotColor = 'bg-slate-600 text-slate-400';
                    iconClass = 'fa-solid fa-phone-slash text-[10px] text-slate-500';
                    statusBadge = '<span class="text-[9px] font-semibold text-slate-500 shrink-0">Off</span>';
                }

                const displayName = m.name ? m.name : `Ramal ${m.extension}`;

                return `<div class="flex items-center justify-between py-1.5 px-2.5 bg-slate-950/80 hover:bg-slate-800/80 rounded-lg border border-slate-800/90 text-xs transition">
                    <div class="flex items-center gap-2 overflow-hidden mr-2">
                        <span class="w-2.5 h-2.5 rounded-full ${dotColor} shrink-0"></span>
                        <i class="${iconClass} shrink-0"></i>
                        <span class="font-bold text-slate-200 text-xs truncate" title="${displayName}">${displayName}</span>
                    </div>
                    ${statusBadge}
                </div>`;
            }).join('') : '<div class="text-slate-500 text-xs italic py-1">Nenhum agente logado.</div>';

            const qStr = q.queue_name || q.queue_number;
            const displayQueueTitle = qStr.includes(String(q.queue_number)) ? (qStr.toLowerCase().startsWith('fila') ? qStr : `Fila ${qStr}`) : `Fila ${q.queue_number} - ${qStr}`;

            return `<div class="queue-item-card bg-slate-900/90 border ${borderCls} rounded-3xl p-5 shadow-xl space-y-4 transition">
                <!-- Header -->
                <div class="flex items-center justify-between border-b border-slate-800 pb-3">
                    <span class="text-xs font-extrabold text-purple-300 font-mono flex items-center gap-1.5 truncate max-w-[220px]" title="${displayQueueTitle}">
                        <i class="fa-solid fa-users text-purple-400"></i> ${displayQueueTitle}
                    </span>
                    <span class="text-[10px] font-mono font-bold px-2 py-0.5 rounded border ${slaCls}">SLA: ${slaNum}%</span>
                </div>

                <!-- Métricas -->
                <div class="grid grid-cols-3 gap-2 text-center text-xs font-mono">
                    <div class="p-2.5 bg-slate-950 rounded-2xl border border-slate-800/80">
                        <span class="text-[10px] text-slate-400 block">Atendidas</span>
                        <span class="text-emerald-400 font-extrabold text-base">${q.answered_count}</span>
                    </div>
                    <div class="p-2.5 bg-slate-950 rounded-2xl border border-slate-800/80">
                        <span class="text-[10px] text-slate-400 block">Perdidas</span>
                        <span class="text-rose-400 font-extrabold text-base">${q.abandoned_count}</span>
                    </div>
                    <div class="p-2.5 bg-slate-950 rounded-2xl border ${hasCallers ? 'border-rose-500/40' : 'border-slate-800/80'}">
                        <span class="text-[10px] text-slate-400 block">Espera</span>
                        <span class="${hasCallers ? 'text-rose-400 animate-pulse' : 'text-cyan-400'} font-extrabold text-base">${q.callers_count}</span>
                    </div>
                </div>

                <!-- Chamadas em Espera -->
                ${hasCallers ? `<div class="space-y-1.5"><span class="text-[11px] font-bold text-rose-300 block flex items-center gap-1"><i class="fa-solid fa-phone-volume text-xs"></i> Aguardando Atendimento:</span><div class="space-y-1 max-h-24 overflow-y-auto custom-scrollbar">${callersHtml}</div></div>` : ''}

                <!-- Agentes -->
                <div class="space-y-1.5">
                    <span class="text-[11px] font-bold text-slate-300 block">Agentes da Fila (${(q.members || []).length}):</span>
                    <div class="space-y-2">${membersHtml}</div>
                </div>
            </div>`;
        }).join('');

        // Renderizar Tabela
        if (tableBody) {
            tableBody.innerHTML = queues.map(q => {
                const qStr = q.queue_name || q.queue_number;
                const displayQueueTitle = qStr.includes(String(q.queue_number)) ? (qStr.toLowerCase().startsWith('fila') ? qStr : `Fila ${qStr}`) : `Fila ${q.queue_number} - ${qStr}`;
                return `
                <tr class="queue-item-card hover:bg-slate-800/40 transition">
                    <td class="p-3 font-bold text-white">${displayQueueTitle}</td>
                    <td class="p-3 text-center"><span class="px-2.5 py-0.5 rounded text-[10px] font-bold ${q.callers_count > 0 ? 'bg-rose-500/20 text-rose-300 animate-pulse' : 'bg-slate-800 text-slate-400'}">${q.callers_count}</span></td>
                    <td class="p-3 text-center font-mono text-emerald-400">${q.answered_count}</td>
                    <td class="p-3 text-center font-mono text-rose-400">${q.abandoned_count}</td>
                    <td class="p-3 text-center font-mono text-purple-300">${q.holdtime_avg}</td>
                    <td class="p-3 text-right font-mono font-bold text-slate-300">${(q.members || []).length}</td>
                </tr>
            `}).join('');
        }

        // Mostrar view atual
        if (_queueViewMode === 'cards') {
            cardsGrid.classList.remove('hidden');
        } else {
            document.getElementById('queues-table-view').classList.remove('hidden');
        }

    } catch(err) {
        loading.classList.add('hidden');
        errorDiv.classList.remove('hidden');
        document.getElementById('queues-error-msg').innerText = err.message;
        console.error('Queues realtime error:', err);
    }
}

function filterQueuesDisplay() {
    const query = (document.getElementById('queue-search-input')?.value || '').toLowerCase();
    document.querySelectorAll('.queue-item-card').forEach(item => {
        item.style.display = item.innerText.toLowerCase().includes(query) ? '' : 'none';
    });
}

// Inicializar primeira carga com indicação visual
loadQueuesRealtime(false);

// Auto-refresh silencioso em segundo plano a cada 3 segundos (sem piscar a tela)
if (_queueRefreshInterval) clearInterval(_queueRefreshInterval);
_queueRefreshInterval = setInterval(() => loadQueuesRealtime(true), 3000);
</script>

