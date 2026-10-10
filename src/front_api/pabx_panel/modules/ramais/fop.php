<?php
/**
 * IPbx Prisma - Módulo FOP (Painel de Operador Multi-Tech Avançado v7.0)
 * Parking minimizado por padrão, expande auto se houver chamadas estacionadas.
 * Painel integrado com dados reais do PABX (sem hardcode).
 */
?>

<div class="space-y-6">
    <!-- FOP Action & Filter Bar com Alternador de Visão -->
    <div class="bg-slate-900/90 border border-slate-800 rounded-xl p-4 shadow-xl space-y-4">
        <div class="flex flex-col md:flex-row items-center justify-between gap-4">
            <!-- Alternador de Modo de Visualização do Operador -->
            <div class="flex items-center bg-slate-950 p-1 rounded-xl border border-slate-800 text-xs font-bold w-full md:w-auto">
                <button id="btn-fop-mode-cards" onclick="setFopMode('cards')" class="px-3.5 py-1.5 rounded-lg transition bg-brand-600 text-white shadow-lg flex items-center gap-1.5">
                    <i class="fa-solid fa-border-all"></i> Visão Cards FOP
                </button>
                <button id="btn-fop-mode-integrated" onclick="setFopMode('integrated')" class="px-3.5 py-1.5 rounded-lg transition text-slate-400 hover:text-white flex items-center gap-1.5" title="Visualização Integrada — Ramais à Esquerda e Filas à Direita">
                    <i class="fa-solid fa-table-columns text-emerald-400"></i> Visão Ramais + Filas
                </button>
            </div>

            <!-- Search Input -->
            <div class="relative w-full md:w-72">
                <i class="fa-solid fa-magnifying-glass absolute left-3.5 top-3 text-slate-400 text-xs"></i>
                <input type="text" id="fop-search-input" onkeyup="updateFOP()" placeholder="Buscar por Ramal ou Atendente..."
                       class="w-full pl-9 pr-4 py-2 bg-slate-950 border border-slate-800 rounded-xl text-xs text-white placeholder-slate-500 focus:outline-none focus:border-brand-500 transition">
            </div>

            <!-- Technology Filter Bar -->
            <div class="flex items-center gap-1.5 bg-slate-950 p-1 rounded-xl border border-slate-800 overflow-x-auto w-full md:w-auto">
                <button id="fop-filter-tech-ALL" onclick="fopSetTechFilter('ALL')" class="fop-tech-filter-btn px-3 py-1.5 text-[11px] font-bold rounded-lg bg-brand-600 text-white transition">
                    Todos
                </button>
                <button id="fop-filter-tech-PJSIP" onclick="fopSetTechFilter('PJSIP')" class="fop-tech-filter-btn px-3 py-1.5 text-[11px] font-bold rounded-lg bg-slate-800 text-slate-300 hover:text-white transition flex items-center gap-1">
                    <span class="w-2 h-2 rounded-full bg-emerald-400"></span> PJSIP
                </button>
                <button id="fop-filter-tech-SIP" onclick="fopSetTechFilter('SIP')" class="fop-tech-filter-btn px-3 py-1.5 text-[11px] font-bold rounded-lg bg-slate-800 text-slate-300 hover:text-white transition flex items-center gap-1">
                    <span class="w-2 h-2 rounded-full bg-blue-400"></span> SIP
                </button>
                <button id="fop-filter-tech-WEBRTC" onclick="fopSetTechFilter('WEBRTC')" class="fop-tech-filter-btn px-3 py-1.5 text-[11px] font-bold rounded-lg bg-slate-800 text-slate-300 hover:text-white transition flex items-center gap-1">
                    <span class="w-2 h-2 rounded-full bg-purple-400"></span> WebRTC
                </button>
            </div>
        </div>
    <!-- Container Principal de Seções do FOP (Reordenável) -->
    <div id="fop-main-sections" class="space-y-6 flex flex-col">

        <!-- PAINEL AUXILIAR LADO A LADO: ESTACIONAMENTO DE CHAMADAS & SALAS DE CONFERÊNCIA -->
        <div id="auxiliary-panel-wrapper" class="bg-slate-900/90 border border-slate-800 rounded-2xl shadow-xl overflow-hidden transition-all duration-300">
            <!-- Header do Painel Auxiliar (Com botão de minimizar e mover posição) -->
            <div class="p-3.5 flex items-center justify-between select-none bg-slate-950/80 border-b border-slate-800" id="aux-header">
                <div class="flex items-center gap-3">
                    <div class="flex items-center gap-1.5 text-amber-400">
                        <i class="fa-solid fa-square-parking text-base"></i>
                        <i class="fa-solid fa-users text-cyan-400 text-sm ml-1"></i>
                    </div>
                    <h3 class="text-xs font-extrabold text-slate-200 flex items-center gap-2">
                        Estacionamento & Salas de Conferência
                        <span id="parking-ext-label" class="px-2 py-0.5 rounded-full bg-slate-800 text-slate-400 text-[10px] font-mono border border-slate-700">Disque 700</span>
                    </h3>
                    <span id="parking-count-badge" class="hidden px-2 py-0.5 rounded-full bg-amber-500/20 text-amber-300 text-[10px] font-bold border border-amber-500/30 animate-pulse">
                        <i class="fa-solid fa-phone-volume"></i> <span id="parking-count-num">0</span> em espera
                    </span>
                </div>

                <div class="flex items-center gap-2">
                    <button onclick="toggleFopSectionPosition()" title="Alternar posição do painel (Topo / Baixo)" class="px-2.5 py-1 bg-slate-800 hover:bg-slate-700 text-slate-300 hover:text-white border border-slate-700 rounded-xl text-[10px] font-bold transition flex items-center gap-1.5 shadow">
                        <i id="fop-pos-icon" class="fa-solid fa-arrow-down"></i> <span id="fop-pos-label">Mover para Baixo</span>
                    </button>
                    <button onclick="toggleParkingPanel()" class="px-2.5 py-1 bg-slate-800 hover:bg-slate-700 text-slate-300 hover:text-white border border-slate-700 rounded-xl text-[10px] font-bold transition flex items-center gap-1.5 shadow">
                        <i id="parking-toggle-icon" class="fa-solid fa-chevron-down transition-transform duration-300"></i> <span id="parking-toggle-label">Minimizar</span>
                    </button>
                </div>
            </div>

            <!-- Conteúdo do Painel Auxiliar Lado a Lado (Parking + Conferências) -->
            <div id="parking-container" class="hidden p-4">
                <div class="grid grid-cols-1 lg:grid-cols-12 gap-6">
                    
                    <!-- LADO ESQUERDO: ESTACIONAMENTO DE CHAMADAS (VAGAS REAIS DO PABX) -->
                    <div class="lg:col-span-6 bg-slate-950/60 p-4 rounded-2xl border border-slate-800/80 space-y-3">
                        <div class="flex items-center justify-between border-b border-slate-800 pb-2.5 text-[11px]">
                            <span class="font-extrabold text-amber-400 flex items-center gap-1.5">
                                <i class="fa-solid fa-square-parking"></i> Vagas Estacionadas (Configuração Real)
                            </span>
                            <span id="parking-status-badge" class="px-2 py-0.5 bg-slate-900 text-slate-400 rounded text-[9px] font-mono border border-slate-800">0 Estacionadas</span>
                        </div>
                        <div id="parking-grid" class="grid grid-cols-2 sm:grid-cols-4 gap-3">
                            <div class="col-span-full text-center py-4 text-slate-500 text-xs"><i class="fa-solid fa-spinner fa-spin"></i> Carregando vagas do PABX...</div>
                        </div>
                    </div>

                    <!-- LADO DIREITO: SALAS DE CONFERÊNCIA (DADOS REAIS DO PABX) -->
                    <div class="lg:col-span-6 bg-slate-950/60 p-4 rounded-2xl border border-slate-800/80 space-y-3">
                        <div class="flex items-center justify-between border-b border-slate-800 pb-2.5 text-[11px]">
                            <span class="font-extrabold text-cyan-400 flex items-center gap-1.5">
                                <i class="fa-solid fa-users text-cyan-400"></i> Salas de Conferência
                            </span>
                            <span id="conferences-count-badge" class="px-2 py-0.5 bg-slate-900 text-slate-400 rounded text-[9px] font-mono border border-slate-800">-- Salas</span>
                        </div>
                        <div id="conferences-list" class="grid grid-cols-2 sm:grid-cols-4 gap-3 max-h-[240px] overflow-y-auto custom-scrollbar p-1">
                            <div class="col-span-full text-center py-4 text-slate-500 text-xs"><i class="fa-solid fa-spinner fa-spin"></i> Carregando conferências...</div>
                        </div>
                    </div>

                </div>
            </div>
        </div>

        <!-- BLOCO DOS PAINÉIS DE RAMAIS -->
        <div id="fop-extensions-wrapper" class="space-y-6">
            <!-- FOP Cards Grid (Modo 1) -->
            <div id="fop-grid" class="grid grid-cols-1 sm:grid-cols-2 md:grid-cols-3 lg:grid-cols-4 xl:grid-cols-5 gap-4">
                <div class="col-span-full text-center py-10 text-slate-500">
                    <i class="fa-solid fa-spinner fa-spin text-2xl mb-2 block"></i>
                    Carregando status dos ramais via REST API...
                </div>
            </div>

            <!-- Painel Integrado Ramais + Filas (Modo 2 — dados reais) -->
            <div id="fop-integrated-panel" class="hidden grid grid-cols-1 lg:grid-cols-12 gap-6">
                <!-- Lado Esquerdo: Ramais Grid (dados reais do AMI) -->
                <div class="lg:col-span-7 bg-slate-900/90 border border-slate-800 rounded-3xl p-6 shadow-2xl space-y-4">
                    <div class="flex items-center justify-between border-b border-slate-800 pb-3">
                        <h4 class="text-sm font-extrabold text-white flex items-center gap-2">
                            <i class="fa-solid fa-phone text-emerald-400"></i> Ramais PABX ao Vivo
                            <span id="integrated-ext-count" class="px-2 py-0.5 bg-emerald-500/10 text-emerald-400 rounded text-[10px] font-mono border border-emerald-500/20">--</span>
                        </h4>
                        <span class="text-xs text-slate-300 font-mono"><span class="text-emerald-400 font-bold">🟢 Verde = Livre</span> | <span class="text-rose-400 font-bold">🔴 Vermelho = Em Uso</span> | <span class="text-slate-400 font-bold">⚪ Cinza = Indisponível</span></span>
                    </div>
                    <div id="integrated-ext-grid" class="grid grid-cols-2 sm:grid-cols-3 md:grid-cols-4 gap-2.5 max-h-[600px] overflow-y-auto custom-scrollbar p-1">
                        <div class="col-span-full text-center py-6 text-slate-500 text-xs"><i class="fa-solid fa-spinner fa-spin"></i> Carregando ramais...</div>
                    </div>
                </div>

                <!-- Lado Direito: Filas de Atendimento (dados reais) -->
                <div class="lg:col-span-5 bg-slate-900/90 border border-slate-800 rounded-3xl p-6 shadow-2xl space-y-4">
                    <div class="flex items-center justify-between border-b border-slate-800 pb-3">
                        <h4 class="text-sm font-extrabold text-white flex items-center gap-2">
                            <i class="fa-solid fa-headset text-purple-400"></i> Filas de Atendimento
                            <span id="integrated-queue-count" class="px-2 py-0.5 bg-purple-500/10 text-purple-400 rounded text-[10px] font-mono border border-purple-500/20">--</span>
                        </h4>
                        <button onclick="updateIntegratedPanel()" class="text-slate-400 hover:text-white text-xs transition"><i class="fa-solid fa-rotate"></i></button>
                    </div>
                    <div id="integrated-queue-list" class="space-y-3 max-h-[600px] overflow-y-auto custom-scrollbar p-1">
                        <div class="text-center py-6 text-slate-500 text-xs"><i class="fa-solid fa-spinner fa-spin"></i> Carregando filas...</div>
                </div>
            </div>
        </div>

    </div>
</div>

<script>
// ─── Posicionamento & Ordenação das Seções FOP ─────────────────────────────────
function setFopSectionOrder(pos) {
    const container = document.getElementById('fop-main-sections');
    const auxPanel  = document.getElementById('auxiliary-panel-wrapper');
    const extPanel  = document.getElementById('fop-extensions-wrapper');
    const posLabel  = document.getElementById('fop-pos-label');
    const posIcon   = document.getElementById('fop-pos-icon');
    if (!container || !auxPanel || !extPanel) return;

    if (pos === 'bottom') {
        container.appendChild(auxPanel);
        if (posLabel) posLabel.innerText = 'Mover para Topo';
        if (posIcon) posIcon.className = 'fa-solid fa-arrow-up';
        localStorage.setItem('fop_section_order', 'bottom');
    } else {
        container.insertBefore(auxPanel, extPanel);
        if (posLabel) posLabel.innerText = 'Mover para Baixo';
        if (posIcon) posIcon.className = 'fa-solid fa-arrow-down';
        localStorage.setItem('fop_section_order', 'top');
    }
}

function toggleFopSectionPosition() {
    const currentOrder = localStorage.getItem('fop_section_order') || 'top';
    const nextOrder = (currentOrder === 'top') ? 'bottom' : 'top';
    setFopSectionOrder(nextOrder);
}

// ─── Parking Panel ────────────────────────────────────────────────────────────
let _parkingExpanded = false;

function toggleParkingPanel(force) {
    const container = document.getElementById('parking-container');
    const icon      = document.getElementById('parking-toggle-icon');
    const label     = document.getElementById('parking-toggle-label');
    if (!container) return;

    const shouldExpand = (force !== undefined) ? force : !_parkingExpanded;

    if (shouldExpand) {
        container.classList.remove('hidden');
        if (icon) icon.style.transform = 'rotate(180deg)';
        if (label) label.innerText = 'Minimizar';
        _parkingExpanded = true;
    } else {
        container.classList.add('hidden');
        if (icon) icon.style.transform = 'rotate(0deg)';
        if (label) label.innerText = 'Expandir';
        _parkingExpanded = false;
    }
}

// ─── Atualizar Parking (com vagas reais configuradas no Issabel/FreePBX) ──────
async function updateParkingLots() {
    const grid  = document.getElementById('parking-grid');
    const badge = document.getElementById('parking-count-badge');
    const badgeNum  = document.getElementById('parking-count-num');
    const statusBdg = document.getElementById('parking-status-badge');
    const parkExtLabel = document.getElementById('parking-ext-label');
    if (!grid) return;

    try {
        const res  = await fetch('index.php?api_action=get_parking_lots');
        const data = await res.json();
        
        const config = (data && data.config) ? data.config : { parkext: '700', start_slot: 701, end_slot: 704, num_slots: 4 };
        const lots   = (data && data.lots) ? data.lots : [];

        if (parkExtLabel) parkExtLabel.innerText = 'Disque ' + (config.parkext || '700');

        const hasLots = lots && lots.length > 0;

        // Auto-expand se houver chamadas estacionadas
        if (hasLots && !_parkingExpanded) {
            toggleParkingPanel(true);
        }

        // Badge de contagem
        if (badge) {
            badge.classList.toggle('hidden', !hasLots);
            if (badgeNum) badgeNum.innerText = hasLots ? lots.length : 0;
        }
        if (statusBdg) statusBdg.innerText = (hasLots ? lots.length : 0) + ' Estacionadas';

        const occupiedSlots = {};
        lots.forEach(l => { occupiedSlots[l.slot] = l; });

        const startSlot = config.start_slot || 701;
        const numSlots  = config.num_slots || 4;
        const endSlot   = config.end_slot || (startSlot + numSlots - 1);

        let html = '';
        for (let slot = startSlot; slot <= endSlot; slot++) {
            const occ = occupiedSlots[slot];
            if (occ) {
                const parkedTs = occ.parked_at_ts || (Math.floor(Date.now()/1000) - (occ.duration_sec || 0));
                const callerLabel = occ.caller_label || occ.channel || 'Chamada Estacionada';
                html += `<div class="bg-amber-500/15 border-2 border-amber-500/50 rounded-xl p-2.5 text-center space-y-1.5 shadow-lg shadow-amber-500/10 transition hover:border-amber-400">
                    <div class="flex items-center justify-between">
                        <span class="text-[10px] font-black font-mono text-amber-400">VAGA ${occ.slot}</span>
                        <span class="px-1.5 py-0.5 rounded text-[8px] font-extrabold bg-amber-500/30 text-amber-200 border border-amber-500/40 animate-pulse">EM ESPERA</span>
                    </div>
                    <div class="text-left bg-slate-950/80 p-1.5 rounded-lg border border-amber-500/20">
                        <span class="text-[9px] text-slate-400 font-bold block">Chamador / Origem:</span>
                        <span class="text-[11px] font-extrabold text-white block truncate" title="${callerLabel}">
                            <i class="fa-solid fa-phone text-amber-400 text-[10px]"></i> ${callerLabel}
                        </span>
                    </div>
                    <div class="bg-amber-950/90 border border-amber-500/40 rounded-lg py-1 px-2 text-amber-300 font-mono text-xs font-black flex items-center justify-center gap-1.5 shadow-inner">
                        <i class="fa-solid fa-clock text-amber-400 animate-spin" style="animation-duration: 3s;"></i>
                        <span class="text-[10px] font-semibold text-amber-200">Espera:</span>
                        <span class="parking-timer-tick text-amber-300 font-bold" data-parked-ts="${parkedTs}">
                            ${occ.duration_fmt || '00:00'}
                        </span>
                    </div>
                    <button onclick="captureParkedCall('${occ.slot}')" title="Atender/Capturar chamada da vaga ${occ.slot}" class="w-full py-1.5 bg-amber-600 hover:bg-amber-500 text-white font-extrabold rounded-lg text-[10px] transition shadow-md shadow-amber-600/30 flex items-center justify-center gap-1">
                        <i class="fa-solid fa-phone-volume"></i> Capturar Chamada
                    </button>
                </div>`;
            } else {
                html += `<div class="bg-slate-950 p-2.5 rounded-xl border border-slate-800/80 text-center space-y-1.5 hover:border-slate-700 transition">
                    <span class="text-[10px] font-bold font-mono text-slate-400 block">Vaga ${slot}</span>
                    <span class="px-2 py-0.5 rounded text-[9px] font-bold bg-slate-900 text-slate-500 border border-slate-800 block">Livre</span>
                    <button onclick="captureParkedCall('${slot}')" class="w-full py-1 bg-slate-900 hover:bg-amber-600 text-slate-400 hover:text-white rounded text-[10px] font-bold transition flex items-center justify-center gap-1">
                        <i class="fa-solid fa-hand-holding-hand"></i> Capturar
                    </button>
                </div>`;
            }
        }
        grid.innerHTML = html;
    } catch(e) { console.error('Parking error:', e); }
}

// ─── Atualizar Salas de Conferência (Criadas no Issabel/FreePBX) ───────────────
async function updateConferences() {
    const list  = document.getElementById('conferences-list');
    const badge = document.getElementById('conferences-count-badge');
    if (!list) return;

    try {
        const res  = await fetch('index.php?api_action=get_conferences_status');
        const confs = await res.json();

        if (badge) badge.innerText = (confs ? confs.length : 0) + ' Salas';

        if (!confs || confs.length === 0) {
            list.innerHTML = '<div class="text-center py-3 text-slate-500 text-[11px] italic">Nenhuma sala de conferência cadastrada.</div>';
            return;
        }

        let html = '';
        confs.forEach(c => {
            const hasUsers = c.participants_count > 0;
            const badgeClass = hasUsers ? 'bg-cyan-500/20 text-cyan-300 border-cyan-500/40 animate-pulse font-extrabold' : 'bg-slate-900 text-slate-500 border-slate-800';
            const badgeLabel = hasUsers ? `${c.participants_count} no canal` : 'Livre';

            html += `<div class="bg-slate-950 p-2.5 rounded-xl border border-slate-800/80 text-center space-y-1.5 hover:border-cyan-500/40 transition">
                <span class="text-[10px] font-bold font-mono text-cyan-400 block truncate" title="${c.name}">Sala ${c.exten}</span>
                <span class="px-2 py-0.5 rounded text-[9px] font-bold ${badgeClass} block">${badgeLabel}</span>
                <button onclick="amiClickToCall('${c.exten}','')" title="Entrar na Sala de Conferência ${c.exten}" class="w-full py-1 bg-slate-900 hover:bg-cyan-600 text-slate-400 hover:text-white rounded text-[10px] font-bold transition flex items-center justify-center gap-1">
                    <i class="fa-solid fa-users text-[10px]"></i> Entrar
                </button>
            </div>`;
        });
        list.innerHTML = html;
    } catch(e) { console.error('Conferences error:', e); }
}

// Tick contínuo de 1 segundo para atualizar o tempo de espera do usuário em tempo real
setInterval(() => {
    document.querySelectorAll('.parking-timer-tick').forEach(el => {
        const ts = parseInt(el.getAttribute('data-parked-ts') || '0', 10);
        if (ts > 0) {
            const now = Math.floor(Date.now() / 1000);
            const elapsed = Math.max(0, now - ts);
            const m = Math.floor(elapsed / 60);
            const s = elapsed % 60;
            el.innerText = String(m).padStart(2, '0') + ':' + String(s).padStart(2, '0');
        }
    });
}, 1000);

// ─── Capturar chamada estacionada via AMI ────────────────────────────────────
function captureParkedCall(slot) {
    let savedExt = localStorage.getItem('prisma_user_extension') || '';
    const agentExt = prompt(`Capturar chamada da Vaga ${slot}.\nDigite o número do seu ramal para atender:`, savedExt);
    if (!agentExt) return;
    localStorage.setItem('prisma_user_extension', agentExt);

    fetch('index.php?api_action=fop_action&type=spy&ext=' + encodeURIComponent(slot) + '&sup_ext=' + encodeURIComponent(agentExt))
        .then(r => r.json())
        .then(d => {
            alert(d.message || (d.success ? `Ramal ${agentExt} receberá a chamada da vaga ${slot}!` : 'Erro: ' + d.error));
            updateParkingLots();
        })
        .catch(err => alert('Erro ao capturar: ' + err.message));
}

// ─── Toggle Modo Painel ───────────────────────────────────────────────────────
function setFopMode(mode) {
    const gridCards    = document.getElementById('fop-grid');
    const panelInt     = document.getElementById('fop-integrated-panel');
    const btnCards     = document.getElementById('btn-fop-mode-cards');
    const btnInt       = document.getElementById('btn-fop-mode-integrated');
    const activeClass  = 'px-3.5 py-1.5 rounded-lg transition bg-brand-600 text-white shadow-lg flex items-center gap-1.5';
    const inactiveClass= 'px-3.5 py-1.5 rounded-lg transition text-slate-400 hover:text-white flex items-center gap-1.5';

    if (mode === 'cards') {
        gridCards.classList.remove('hidden');
        panelInt.classList.add('hidden');
        btnCards.className = activeClass;
        btnInt.className   = inactiveClass;
    } else {
        gridCards.classList.add('hidden');
        panelInt.classList.remove('hidden');
        btnInt.className   = activeClass + ' text-white';
        btnCards.className = inactiveClass;
        updateIntegratedPanel();
    }
}

// ─── Painel Integrado: Ramais Reais ──────────────────────────────────────────
async function updateIntegratedPanel() {
    await Promise.all([updateIntegratedExtensions(), updateIntegratedQueues()]);
}

async function updateIntegratedExtensions(cachedExts) {
    const grid      = document.getElementById('integrated-ext-grid');
    const countBadge= document.getElementById('integrated-ext-count');
    if (!grid) return;
    try {
        let exts = cachedExts;
        if (!exts) {
            const res  = await fetch('index.php?api_action=get_fop_extensions');
            exts = await res.json();
        }

        // Aplicar filtros de tecnologia e busca
        const query = (document.getElementById('fop-search-input')?.value || '').toLowerCase().trim();
        if (typeof currentFopTechFilter !== 'undefined' && currentFopTechFilter !== 'ALL') {
            exts = exts.filter(e => (e.tech || 'PJSIP').toUpperCase() === currentFopTechFilter);
        }
        if (query) {
            exts = exts.filter(e => e.id.toLowerCase().includes(query) || (e.name || '').toLowerCase().includes(query));
        }

        if (countBadge) countBadge.innerText = exts.length + ' ramais';

        if (!exts || exts.length === 0) {
            grid.innerHTML = '<div class="col-span-full text-center py-6 text-slate-500 text-xs">Nenhum ramal encontrado com os filtros aplicados.</div>';
            return;
        }

        // Clear spinner if present
        if (grid.innerHTML.includes('fa-spinner') || grid.innerHTML.includes('Nenhum ramal')) {
            grid.innerHTML = '';
        }

        // Keep track of current elements to remove old ones
        const currentIds = new Set(exts.map(e => `ext-card-${e.id}`));
        Array.from(grid.children).forEach(child => {
            if (child.id && !currentIds.has(child.id)) {
                child.remove();
            }
        });

        // Atualização Suave In-Place (Sem piscar o DOM)
        exts.forEach(e => {
            const extCard = document.getElementById(`ext-card-${e.id}`);
            const busy = e.status === 'Em Chamada' || e.status === 'Ocupado';
            const tech = (e.tech || 'PJSIP').toUpperCase();
            
            let techClass = 'bg-emerald-500/10 text-emerald-400 border-emerald-500/20';
            if (tech === 'SIP') techClass = 'bg-blue-500/10 text-blue-400 border-blue-500/20';
            if (tech === 'WEBRTC') techClass = 'bg-cyan-500/20 text-cyan-300 border-cyan-500/30';
            if (tech === 'IAX2') techClass = 'bg-amber-500/10 text-amber-400 border-amber-500/20';

            const hasWa = !!(e.whatsapp && e.whatsapp.trim() !== '');
            const waBtnClass = hasWa ? 'bg-emerald-500/10 text-emerald-400 hover:bg-emerald-500 hover:text-white border border-emerald-500/20' : 'bg-slate-800/40 text-slate-600 opacity-40 cursor-not-allowed border border-slate-800';
            const waTitle = hasWa ? 'Enviar WhatsApp ao Atendente' : 'Sem WhatsApp';

            let stColor = 'bg-emerald-500/20 text-emerald-300 border-emerald-500/30';
            let stBg = 'bg-slate-900/90 border-slate-800';
            let stBorder = 'border-slate-800';
            let stIcon = 'fa-circle-check';

            if (busy) {
                stColor = 'bg-rose-500/20 text-rose-300 border-rose-500/30';
                stBg = 'bg-rose-950/20 border-rose-500/30';
                stBorder = 'border-rose-500/50';
                stIcon = 'fa-phone-volume';
            } else if (e.status === 'Tocando') {
                stColor = 'bg-amber-500/20 text-amber-300 border-amber-500/30';
                stBg = 'bg-amber-950/20 border-amber-500/30';
                stBorder = 'border-amber-500/50';
                stIcon = 'fa-bell';
            } else if (e.status === 'Indisponível' || e.status === 'Offline') {
                stColor = 'bg-slate-800 text-slate-500 border-slate-700';
                stBg = 'bg-slate-900/40 border-slate-800/60 opacity-75';
                stBorder = 'border-slate-800/60';
                stIcon = 'fa-circle-xmark';
            }

            const partnerTitle = e.partner ? `Em ligação com: ${e.partner}` : `${e.name}`;
            const partnerBadgeHtml = e.partner ? `<div class="ext-partner-box text-[10px] text-rose-300 font-mono bg-rose-500/10 px-2 py-0.5 rounded border border-rose-500/20 truncate">📞 ${e.partner}</div>` : '';

            if (extCard) {
                // Atualiza campos in-place sem recriar o card
                extCard.className = `p-3.5 rounded-2xl border ${stBorder} ${stBg} space-y-2 transition hover:scale-[1.01] shadow-md flex flex-col justify-between`;
                extCard.title = partnerTitle;

                const stBadge = extCard.querySelector('.ext-status-badge');
                if (stBadge) {
                    stBadge.className = `px-1.5 py-0.5 rounded text-[9px] font-bold ${stColor} uppercase tracking-wider flex items-center gap-1 ext-status-badge`;
                    stBadge.innerHTML = `<i class="fa-solid ${stIcon}"></i> ${e.status}`;
                }

                const partnerBox = extCard.querySelector('.ext-partner-box');
                if (partnerBox) {
                    if (e.partner) {
                        partnerBox.outerHTML = partnerBadgeHtml;
                    } else {
                        partnerBox.remove();
                    }
                } else if (e.partner) {
                    const nameBox = extCard.querySelector('.ext-name-container');
                    if (nameBox) nameBox.insertAdjacentHTML('afterend', partnerBadgeHtml);
                }
            } else {
                // Primeira renderização
                const cardHtml = `<div id="ext-card-${e.id}" title="${partnerTitle}" class="p-3.5 rounded-2xl border ${stBorder} ${stBg} space-y-2 transition hover:scale-[1.01] shadow-md flex flex-col justify-between">
                    <div class="flex items-center justify-between">
                        <div class="flex items-center gap-1.5">
                            <span class="px-1.5 py-0.5 rounded text-[8px] font-bold border ${techClass}">${tech}</span>
                            <span class="text-xs font-extrabold font-mono text-white">${e.id}</span>
                        </div>
                        <span class="px-1.5 py-0.5 rounded text-[9px] font-bold ${stColor} uppercase tracking-wider flex items-center gap-1 ext-status-badge">
                            <i class="fa-solid ${stIcon}"></i> ${e.status}
                        </span>
                    </div>

                    <div class="ext-name-container">
                        <span class="text-xs font-bold block truncate text-slate-100" title="${e.name}">${e.name}</span>
                    </div>

                    ${partnerBadgeHtml}

                    <div class="pt-2 border-t border-slate-800/80 flex items-center justify-between gap-1 flex-wrap">
                        <div class="flex items-center gap-1 shrink-0">
                            <button onclick="amiClickToCall('${e.id}','')" title="Discar para Ramal ${e.id}" class="w-6 h-6 rounded bg-indigo-500/10 text-indigo-400 hover:bg-indigo-500 hover:text-white transition flex items-center justify-center text-[10px] shrink-0">
                                <i class="fa-solid fa-phone"></i>
                            </button>
                            <button onclick="${hasWa ? `openFopWaModal('${e.id}', '${e.name}')` : `alert('Sem WhatsApp cadastrado para o ramal ${e.id}')`}" title="${waTitle}" class="w-6 h-6 rounded ${waBtnClass} transition flex items-center justify-center text-[10px] shrink-0">
                                <i class="fa-brands fa-whatsapp"></i>
                            </button>
                        </div>

                        <div class="flex items-center gap-0.5 shrink-0">
                            <button onclick="fopSpyCall('${e.id}', 'spy')" title="Escuta (Spy)" class="w-6 h-6 rounded ${busy ? 'bg-purple-500/20 text-purple-300 hover:bg-purple-500 hover:text-white border border-purple-500/30' : 'bg-slate-800/80 text-slate-400 hover:bg-slate-700 hover:text-white'} transition flex items-center justify-center text-[10px] shrink-0"><i class="fa-solid fa-ear-listen"></i></button>
                            <button onclick="fopSpyCall('${e.id}', 'whisper')" title="Sussurro" class="w-6 h-6 rounded ${busy ? 'bg-blue-500/20 text-blue-300 hover:bg-blue-500 hover:text-white border border-blue-500/30' : 'bg-slate-800/80 text-slate-400 hover:bg-slate-700 hover:text-white'} transition flex items-center justify-center text-[10px] shrink-0"><i class="fa-solid fa-comment-slash"></i></button>
                            <button onclick="fopSpyCall('${e.id}', 'barge')" title="Interpolação" class="w-6 h-6 rounded ${busy ? 'bg-amber-500/20 text-amber-300 hover:bg-amber-500 hover:text-white border border-amber-500/30' : 'bg-slate-800/80 text-slate-400 hover:bg-slate-700 hover:text-white'} transition flex items-center justify-center text-[10px] shrink-0"><i class="fa-solid fa-users"></i></button>
                            <button onclick="fopHangupCall('${e.channel}')" title="Desconectar" class="w-6 h-6 rounded ${busy ? 'bg-rose-500/20 text-rose-300 hover:bg-rose-500 hover:text-white border border-rose-500/30' : 'bg-slate-800/80 text-slate-400 hover:bg-slate-700 hover:text-white'} transition flex items-center justify-center text-[10px] shrink-0"><i class="fa-solid fa-phone-slash"></i></button>
                        </div>
                    </div>
                </div>`;
                grid.insertAdjacentHTML('beforeend', cardHtml);
            }
        });
    } catch(e) { console.error('Integrated ext error:', e); }
}

async function updateIntegratedQueues() {
    const list       = document.getElementById('integrated-queue-list');
    const countBadge = document.getElementById('integrated-queue-count');
    if (!list) return;
    try {
        const res    = await fetch('index.php?api_action=get_queues_realtime');
        const queues = await res.json();
        if (countBadge) countBadge.innerText = (queues ? queues.length : 0) + ' filas';

        if (!queues || queues.length === 0) {
            list.innerHTML = '<div class="text-center py-6 text-slate-500 text-xs">Nenhuma fila cadastrada ou ativa.</div>';
            return;
        }

        list.innerHTML = queues.map(q => {
            const hasCallers = q.callers_count > 0;
            const borderCls  = hasCallers ? 'border-rose-500/40' : 'border-purple-500/30';

            let callersHtml = '';
            if (hasCallers && q.callers && q.callers.length > 0) {
                callersHtml = `<div class="bg-rose-500/10 border border-rose-500/20 rounded-xl p-2 space-y-1">
                    <span class="text-[10px] font-bold text-rose-300 block">Clientes em Espera (${q.callers_count}):</span>
                    ${q.callers.map(c => {
                        const callerDisp = c.caller_label || c.caller_name || c.caller_num || c.channel;
                        return `
                        <div class="flex items-center justify-between text-[10px] font-mono text-rose-200 bg-slate-950/60 p-1.5 rounded border border-rose-500/20">
                            <span class="truncate mr-2 font-bold" title="${callerDisp}">Pos ${c.pos}: ${callerDisp}</span>
                            <span class="text-rose-400 shrink-0">⏳ ${c.wait_time}</span>
                        </div>
                    `;
                    }).join('')}
                </div>`;
            }

            const membersHtml = (q.members && q.members.length > 0) ? q.members.map(m => {
                let dotColor = 'bg-emerald-500 text-emerald-400';
                let stBadge = '<span class="text-[9px] font-bold px-1.5 py-0.5 rounded bg-emerald-500/20 text-emerald-300 border border-emerald-500/30">Livre</span>';
                if (m.status === 'Em Chamada') {
                    dotColor = 'bg-rose-500 text-rose-400 animate-pulse';
                    stBadge = '<span class="text-[9px] font-bold px-1.5 py-0.5 rounded bg-rose-500/20 text-rose-300 border border-rose-500/30">Em Chamada</span>';
                } else if (m.status === 'Tocando') {
                    dotColor = 'bg-amber-400 text-amber-300 animate-bounce';
                    stBadge = '<span class="text-[9px] font-bold px-1.5 py-0.5 rounded bg-amber-500/20 text-amber-300 border border-amber-500/30">Tocando</span>';
                } else if (m.status === 'Indisponível') {
                    dotColor = 'bg-slate-600 text-slate-500';
                    stBadge = '<span class="text-[9px] font-semibold text-slate-500">Off</span>';
                }

                const displayName = m.name ? m.name : `Ramal ${m.extension}`;

                return `<div class="flex items-center justify-between p-1.5 bg-slate-950/80 rounded-xl border border-slate-800 text-xs">
                    <div class="flex items-center gap-2 overflow-hidden mr-2">
                        <span class="w-2 h-2 rounded-full ${dotColor} shrink-0"></span>
                        <span class="font-bold text-slate-200 text-[11px] truncate" title="${displayName}">${displayName}</span>
                    </div>
                    <div>
                        ${stBadge}
                    </div>
                </div>`;
            }).join('') : '<div class="text-slate-500 text-xs italic py-1">Nenhum operador logado nesta fila.</div>';

            const qNameClean = q.queue_name ? q.queue_name : `Fila ${q.queue_number}`;
            const displayQueueTitle = qNameClean;
            return `<div class="p-4 bg-slate-950 border ${borderCls} rounded-2xl space-y-3 transition hover:border-purple-500/50 shadow-md">
                <div class="flex items-center justify-between font-mono text-xs font-bold border-b border-slate-800/80 pb-2">
                    <span class="text-purple-300 truncate text-sm flex items-center gap-1.5">
                        <i class="fa-solid fa-users text-purple-400"></i> ${displayQueueTitle}
                    </span>
                    <div class="flex items-center gap-1.5 shrink-0">
                        ${hasCallers ? `<span class="text-[10px] bg-rose-500/20 text-rose-300 px-2 py-0.5 rounded border border-rose-500/30 animate-pulse font-bold">${q.callers_count} em espera</span>` : `<span class="text-[10px] bg-emerald-500/20 text-emerald-300 px-2 py-0.5 rounded">0 espera</span>`}
                    </div>
                </div>

                <div class="text-[11px] font-mono text-slate-400 flex items-center justify-between bg-slate-900/60 p-2 rounded-xl border border-slate-800/60">
                    <span>✅ Atendidas: <strong class="text-emerald-400">${q.answered_count}</strong> &nbsp; ❌ Abandonos: <strong class="text-rose-400">${q.abandoned_count}</strong></span>
                    <span class="text-[10px] text-slate-400">⏱ T. Médio: <strong class="text-purple-300">${q.holdtime_avg}</strong></span>
                </div>

                ${callersHtml}

                <div class="space-y-1.5">
                    <span class="text-[10px] font-bold uppercase tracking-wider text-slate-400 block">Operadores / Agentes (${q.members ? q.members.length : 0})</span>
                    <div class="space-y-1 max-h-48 overflow-y-auto custom-scrollbar pr-1">
                        ${membersHtml}
                    </div>
                </div>
            </div>`;
        }).join('');
} catch(e) { console.error('Integrated queue error:', e); }
}

let currentFopTechFilter = 'ALL';

function fopSetTechFilter(tech) {
    currentFopTechFilter = tech.toUpperCase();
    document.querySelectorAll('.fop-tech-filter-btn').forEach(b => {
        if (b.id === `fop-filter-tech-${tech}`) {
            b.className = 'fop-tech-filter-btn px-3 py-1.5 text-[11px] font-bold rounded-lg bg-brand-600 text-white transition';
            if(tech !== 'ALL') b.classList.add('flex', 'items-center', 'gap-1');
        } else {
            b.className = 'fop-tech-filter-btn px-3 py-1.5 text-[11px] font-bold rounded-lg bg-slate-800 text-slate-300 hover:text-white transition';
            if(b.id !== 'fop-filter-tech-ALL') b.classList.add('flex', 'items-center', 'gap-1');
        }
    });
    updateFOP();
    updateIntegratedExtensions();
}

async function updateFOP() {
    const grid = document.getElementById('fop-grid');
    if (!grid || grid.classList.contains('hidden')) return;

    try {
        const res  = await fetch('index.php?api_action=get_fop_extensions');
        let exts = await res.json();

        // Aplicar filtros de tecnologia e busca
        const query = (document.getElementById('fop-search-input')?.value || '').toLowerCase().trim();
        if (typeof currentFopTechFilter !== 'undefined' && currentFopTechFilter !== 'ALL') {
            exts = exts.filter(e => (e.tech || 'PJSIP').toUpperCase() === currentFopTechFilter);
        }
        if (query) {
            exts = exts.filter(e => e.id.toLowerCase().includes(query) || (e.name || '').toLowerCase().includes(query));
        }

        if (!exts || exts.length === 0) {
            grid.innerHTML = '<div class="col-span-full text-center py-6 text-slate-500 text-xs">Nenhum ramal encontrado com os filtros aplicados.</div>';
            return;
        }

        if (grid.innerHTML.includes('fa-spinner') || grid.innerHTML.includes('Nenhum ramal')) {
            grid.innerHTML = '';
        }

        const currentIds = new Set(exts.map(e => `fop-card-${e.id}`));
        Array.from(grid.children).forEach(child => {
            if (child.id && !currentIds.has(child.id)) {
                child.remove();
            }
        });

        exts.forEach(e => {
            const extCard = document.getElementById(`fop-card-${e.id}`);
            const busy = e.status === 'Em Chamada' || e.status === 'Ocupado';
            const tech = (e.tech || 'PJSIP').toUpperCase();
            
            let techClass = 'bg-emerald-500/10 text-emerald-400 border-emerald-500/20';
            if (tech === 'SIP') techClass = 'bg-blue-500/10 text-blue-400 border-blue-500/20';
            if (tech === 'WEBRTC') techClass = 'bg-cyan-500/20 text-cyan-300 border-cyan-500/30';
            if (tech === 'IAX2') techClass = 'bg-amber-500/10 text-amber-400 border-amber-500/20';

            const hasWa = !!(e.whatsapp && e.whatsapp.trim() !== '');
            const waBtnClass = hasWa ? 'bg-emerald-500/10 text-emerald-400 hover:bg-emerald-500 hover:text-white border border-emerald-500/20' : 'bg-slate-800/40 text-slate-600 opacity-40 cursor-not-allowed border border-slate-800';
            const waTitle = hasWa ? 'Enviar WhatsApp ao Atendente' : 'Sem WhatsApp';

            let stColor = 'bg-emerald-500/20 text-emerald-300 border-emerald-500/30';
            let stBg = 'bg-slate-900/90 border-slate-800';
            let stBorder = 'border-slate-800';
            let stIcon = 'fa-circle-check';

            if (busy) {
                stColor = 'bg-rose-500/20 text-rose-300 border-rose-500/30';
                stBg = 'bg-rose-950/20 border-rose-500/30';
                stBorder = 'border-rose-500/50';
                stIcon = 'fa-phone-volume';
            } else if (e.status === 'Tocando') {
                stColor = 'bg-amber-500/20 text-amber-300 border-amber-500/30';
                stBg = 'bg-amber-950/20 border-amber-500/30';
                stBorder = 'border-amber-500/50';
                stIcon = 'fa-bell';
            } else if (e.status === 'Indisponível' || e.status === 'Offline') {
                stColor = 'bg-slate-800 text-slate-500 border-slate-700';
                stBg = 'bg-slate-900/40 border-slate-800/60 opacity-75';
                stBorder = 'border-slate-800/60';
                stIcon = 'fa-circle-xmark';
            }

            const partnerTitle = e.partner ? `Em ligação com: ${e.partner}` : `${e.id} - ${e.name}`;
            const partnerBadgeHtml = e.partner ? `<div class="fop-partner-box text-[10px] text-rose-300 font-mono bg-rose-500/10 px-2 py-0.5 rounded border border-rose-500/20 truncate mt-2">📞 ${e.partner}</div>` : '';

            if (extCard) {
                extCard.className = `p-4 rounded-2xl border ${stBorder} ${stBg} space-y-3 transition hover:scale-[1.02] shadow-xl flex flex-col justify-between`;
                extCard.title = partnerTitle;

                const stBadge = extCard.querySelector('.fop-status-badge');
                if (stBadge) {
                    stBadge.className = `px-2 py-1 rounded-md text-[10px] font-bold ${stColor} uppercase tracking-wider flex items-center gap-1.5 fop-status-badge`;
                    stBadge.innerHTML = `<i class="fa-solid ${stIcon}"></i> ${e.status}`;
                }

                const partnerBox = extCard.querySelector('.fop-partner-box');
                if (partnerBox) {
                    if (e.partner) {
                        partnerBox.outerHTML = partnerBadgeHtml;
                    } else {
                        partnerBox.remove();
                    }
                } else if (e.partner) {
                    const nameBox = extCard.querySelector('.fop-name-container');
                    if (nameBox) nameBox.insertAdjacentHTML('afterend', partnerBadgeHtml);
                }
            } else {
                const cardHtml = `<div id="fop-card-${e.id}" title="${partnerTitle}" class="p-4 rounded-2xl border ${stBorder} ${stBg} space-y-3 transition hover:scale-[1.02] shadow-xl flex flex-col justify-between">
                    <div class="flex items-center justify-between border-b border-slate-800/80 pb-2">
                        <div class="flex items-center gap-2">
                            <div class="w-8 h-8 rounded-full ${stColor} flex items-center justify-center border text-sm shrink-0">
                                <i class="fa-solid ${tech === 'WEBRTC' ? 'fa-headset' : 'fa-phone'}"></i>
                            </div>
                            <div>
                                <span class="px-1.5 py-0.5 rounded text-[8px] font-bold border ${techClass} uppercase">${tech}</span>
                                <span class="text-sm font-extrabold font-mono text-white block mt-0.5">${e.id}</span>
                            </div>
                        </div>
                        <span class="px-2 py-1 rounded-md text-[10px] font-bold ${stColor} uppercase tracking-wider flex items-center gap-1.5 fop-status-badge">
                            <i class="fa-solid ${stIcon}"></i> ${e.status}
                        </span>
                    </div>

                    <div class="fop-name-container">
                        <span class="text-sm font-bold block truncate text-slate-100" title="${e.name}">${e.name}</span>
                        <span class="text-[10px] text-slate-500 font-mono mt-0.5 block">SIP/PJSIP OK</span>
                    </div>

                    ${partnerBadgeHtml}

                    <div class="pt-3 border-t border-slate-800 flex items-center justify-between gap-1">
                        <div class="flex items-center gap-1.5">
                            <button onclick="amiClickToCall('${e.id}','')" title="Discar" class="w-8 h-8 rounded-lg bg-indigo-500/10 text-indigo-400 hover:bg-indigo-500 hover:text-white border border-indigo-500/20 transition flex items-center justify-center text-xs">
                                <i class="fa-solid fa-phone"></i>
                            </button>
                            <button onclick="${hasWa ? `openFopWaModal('${e.id}', '${e.name}')` : `alert('Sem WhatsApp cadastrado para o ramal ${e.id}')`}" title="${waTitle}" class="w-8 h-8 rounded-lg ${waBtnClass} transition flex items-center justify-center text-xs">
                                <i class="fa-brands fa-whatsapp"></i>
                            </button>
                        </div>
                        <div class="flex items-center gap-1.5">
                            <button onclick="fopSpyCall('${e.id}', 'spy')" title="Escuta (Spy)" class="w-7 h-7 rounded-lg ${busy ? 'bg-purple-500/20 text-purple-300 hover:bg-purple-500 hover:text-white border border-purple-500/30' : 'bg-slate-800/80 text-slate-400 hover:bg-slate-700 hover:text-white'} transition flex items-center justify-center text-[10px]"><i class="fa-solid fa-ear-listen"></i></button>
                            <button onclick="fopSpyCall('${e.id}', 'whisper')" title="Sussurro" class="w-7 h-7 rounded-lg ${busy ? 'bg-blue-500/20 text-blue-300 hover:bg-blue-500 hover:text-white border border-blue-500/30' : 'bg-slate-800/80 text-slate-400 hover:bg-slate-700 hover:text-white'} transition flex items-center justify-center text-[10px]"><i class="fa-solid fa-comment-slash"></i></button>
                            <button onclick="fopSpyCall('${e.id}', 'barge')" title="Interpolação" class="w-7 h-7 rounded-lg ${busy ? 'bg-amber-500/20 text-amber-300 hover:bg-amber-500 hover:text-white border border-amber-500/30' : 'bg-slate-800/80 text-slate-400 hover:bg-slate-700 hover:text-white'} transition flex items-center justify-center text-[10px]"><i class="fa-solid fa-users"></i></button>
                            <button onclick="fopHangupCall('${e.channel}')" title="Desconectar" class="w-7 h-7 rounded-lg ${busy ? 'bg-rose-500/20 text-rose-300 hover:bg-rose-500 hover:text-white border border-rose-500/30' : 'bg-slate-800/80 text-slate-400 hover:bg-slate-700 hover:text-white'} transition flex items-center justify-center text-[10px]"><i class="fa-solid fa-phone-slash"></i></button>
                        </div>
                    </div>
                </div>`;
                grid.insertAdjacentHTML('beforeend', cardHtml);
            }
        });

    } catch(e) { console.error('FOP error:', e); }
}

// Iniciar Loops e Posição Salva
const savedFopPos = localStorage.getItem('fop_section_order') || 'top';
setFopSectionOrder(savedFopPos);

updateParkingLots();
updateConferences();
updateFOP();
updateIntegratedPanel();

setInterval(() => {
    updateParkingLots();
    updateConferences();
    updateFOP();
    updateIntegratedPanel();
}, 2000);

// ─── Modal WhatsApp Atendente ─────────────────────────────────────────────────
function openFopWaModal(ext, name) {
    const modal    = document.getElementById('modal-fop-whatsapp');
    const subt     = document.getElementById('fop-wa-subtitle');
    const targetExt= document.getElementById('fop-wa-target-ext');
    const phoneInp = document.getElementById('fop-wa-target-phone');
    if (modal)     modal.classList.remove('hidden');
    if (subt)      subt.innerText = `Ramal ${ext} (${name})`;
    if (targetExt) targetExt.value = ext;
    if (phoneInp && !phoneInp.value) phoneInp.value = '55119';
}
function closeFopWaModal() {
    const modal = document.getElementById('modal-fop-whatsapp');
    if (modal) modal.classList.add('hidden');
}
async function submitFopWhatsappMsg(e) {
    e.preventDefault();
    const phone = document.getElementById('fop-wa-target-phone')?.value;
    const msg   = document.getElementById('fop-wa-msg-text')?.value;
    if (!phone || !msg) return;
    try {
        const res = await fetch('index.php?api_action=send_whatsapp_message', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ phone: phone, message: msg })
        });
        const data = await res.json();
        alert(data.success !== false ? `✅ Mensagem enviada para ${phone}!` : '❌ Erro: ' + (data.error || 'Tente novamente'));
        closeFopWaModal();
    } catch(err) { alert('Erro: ' + err.message); }
}

// ─── Ações FOP Card (Discar, Escuta, Sussurro, Interpolação, Desconectar) ────
// As chamadas de Click to Call utilizam a função global amiClickToCall(targetNum) do index.php

function fopSpyCall(targetExt, type) {
    const supExt = prompt(`Iniciar Escuta/Interpolação (${type}) no ramal ${targetExt}.\nDigite o número do seu ramal para ouvir:`, "");
    if (!supExt) return;
    fetch(`index.php?api_action=fop_action&type=${encodeURIComponent(type)}&ext=${encodeURIComponent(targetExt)}&sup_ext=${encodeURIComponent(supExt)}`)
        .then(r => r.json())
        .then(d => {
            alert(d.message || (d.success ? `Comando enviado com sucesso!` : 'Erro: ' + (d.error || d.message)));
        })
        .catch(err => alert('Erro: ' + err.message));
}

function fopHangupCall(channel) {
    if (!channel) return;
    if (!confirm(`Deseja realmente derrubar a chamada do canal ${channel}?`)) return;
    fetch(`index.php?api_action=fop_action&type=hangup&channel=${encodeURIComponent(channel)}`)
        .then(r => r.json())
        .then(d => {
            alert(d.message || (d.success ? `Chamada derrubada!` : 'Erro: ' + (d.error || d.message)));
        })
        .catch(err => alert('Erro: ' + err.message));
}

</script>

<!-- Modal para Enviar Mensagem de WhatsApp ao Ramal/Atendente -->
<div id="modal-fop-whatsapp" class="fixed inset-0 z-50 hidden bg-slate-950/80 backdrop-blur-md flex items-center justify-center p-4">
    <div class="bg-slate-900 border border-emerald-500/30 rounded-2xl max-w-md w-full p-6 shadow-2xl space-y-4">
        <div class="flex items-center justify-between border-b border-slate-800 pb-3">
            <div class="flex items-center gap-2">
                <div class="w-8 h-8 rounded-full bg-emerald-500/20 text-emerald-400 flex items-center justify-center text-sm font-bold border border-emerald-500/30">
                    <i class="fa-brands fa-whatsapp"></i>
                </div>
                <div>
                    <h4 class="text-sm font-extrabold text-white">Enviar Mensagem WhatsApp</h4>
                    <span id="fop-wa-subtitle" class="text-[11px] text-emerald-400 font-mono">Ramal ...</span>
                </div>
            </div>
            <button onclick="closeFopWaModal()" class="text-slate-400 hover:text-white"><i class="fa-solid fa-xmark"></i></button>
        </div>
        <form onsubmit="submitFopWhatsappMsg(event)" class="space-y-3 text-xs">
            <input type="hidden" id="fop-wa-target-ext" value="">
            <div>
                <label class="block font-bold text-slate-300 mb-1">Telefone do Atendente / Cliente</label>
                <input type="text" id="fop-wa-target-phone" placeholder="5511999998888" required
                       class="w-full px-3.5 py-2.5 bg-slate-950 border border-slate-800 rounded-xl text-white font-mono placeholder-slate-600 focus:border-emerald-500 focus:outline-none">
            </div>
            <div>
                <label class="block font-bold text-slate-300 mb-1">Mensagem</label>
                <textarea id="fop-wa-msg-text" rows="3" placeholder="Digite a mensagem..." required
                          class="w-full px-3.5 py-2.5 bg-slate-950 border border-slate-800 rounded-xl text-white placeholder-slate-600 focus:border-emerald-500 focus:outline-none"></textarea>
            </div>
            <button type="submit" class="w-full py-2.5 bg-emerald-600 hover:bg-emerald-500 text-white font-bold rounded-xl transition shadow-lg shadow-emerald-600/20 flex items-center justify-center gap-2">
                <i class="fa-brands fa-whatsapp"></i> Enviar Mensagem
            </button>
        </form>
    </div>
</div>
