<?php
/**
 * IPbx Prisma - Sidebar Navigation (Menus e Submenus Expansíveis & Dinâmicos)
 */
$current_module = isset($_GET['module']) ? $_GET['module'] : 'dashboard';
$current_action = isset($_GET['action']) ? $_GET['action'] : 'view';

function isNavActive($mod, $act = '') {
    global $current_module, $current_action;
    if (!empty($act)) {
        return ($current_module === $mod && $current_action === $act);
    }
    return ($current_module === $mod);
}

function isGroupActive($modules_array) {
    global $current_module;
    return in_array($current_module, $modules_array);
}
?>

<aside id="sidebar-panel" class="w-64 bg-slate-900/95 border-r border-slate-800 flex flex-col shrink-0 min-h-screen transition-all duration-300 z-30 select-none">
    <!-- Logo Header & Collapse Button -->
    <div class="p-4 border-b border-slate-800 flex items-center justify-between">
        <div class="flex items-center gap-3 sidebar-text">
            <div class="w-9 h-9 rounded-xl bg-gradient-to-tr from-brand-600 to-indigo-500 flex items-center justify-center text-white shadow-lg shadow-brand-500/20 font-black text-lg">
                P
            </div>
            <div>
                <h1 class="text-sm font-black tracking-wider text-white flex items-center gap-1.5">
                    PRISMA
                    <span class="px-1.5 py-0.5 text-[9px] bg-brand-500/20 text-brand-400 border border-brand-500/30 rounded font-bold uppercase">TELECOM</span>
                </h1>
                <span class="text-[10px] text-slate-400 block font-mono">v6.1 • PABX IP</span>
            </div>
        </div>

        <!-- Botão Ocultar / Collapse Sidebar -->
        <button onclick="toggleSidebarCollapse()" class="p-1.5 rounded-lg text-slate-400 hover:text-white hover:bg-slate-800 transition" title="Ocultar / Mostrar Barra Lateral">
            <i class="fa-solid fa-indent text-sm"></i>
        </button>
    </div>

    <!-- Navigation List (Expansível / Accordion) -->
    <nav class="flex-1 p-3 space-y-2 overflow-y-auto custom-scrollbar text-xs font-semibold">
        
        <?php if (hasUserPermission('mod_dashboard')): ?>
        <!-- MENU: DASHBOARD ÚNICO -->
        <a href="index.php?module=dashboard&action=view_v1" class="w-full flex items-center justify-between px-3 py-2.5 rounded-xl transition-all <?php echo isNavActive('dashboard') ? 'bg-brand-600/20 text-brand-400 border border-brand-500/30 shadow-lg' : 'text-slate-300 hover:bg-slate-800/60 hover:text-white'; ?>">
            <div class="flex items-center gap-2.5 font-bold">
                <i class="fa-solid fa-chart-pie text-sm"></i>
                <span>Dashboard</span>
            </div>
        </a>
        <?php endif; ?>

        <!-- GROUP: TELEFONIA & RAMAIS (EXPANSÍVEL) -->
        <?php if (hasUserPermission('mod_dashboard') || hasUserPermission('mod_filas')): ?>
        <?php $grp1_active = isGroupActive(['ramais', 'troncos', 'telefonia']); ?>
        <div class="border-t border-slate-800/60 pt-2">
            <button onclick="toggleSidebarGroup('grp-ramais')" class="w-full flex items-center justify-between px-3 py-2 text-slate-400 hover:text-white rounded-lg transition font-extrabold uppercase text-[10px] tracking-wider">
                <span class="flex items-center gap-2">
                    <i class="fa-solid fa-phone text-blue-400"></i>
                    <span>Telefonia & Ramais</span>
                </span>
                <i id="icon-grp-ramais" class="fa-solid fa-chevron-down transition-transform duration-200 <?php echo $grp1_active ? 'rotate-180' : ''; ?>"></i>
            </button>
            <div id="grp-ramais" class="space-y-1 pl-1 mt-1 <?php echo $grp1_active ? '' : 'hidden'; ?>">
                <a href="index.php?module=ramais&action=fop" class="flex items-center gap-2.5 px-3 py-2 rounded-lg transition-all <?php echo isNavActive('ramais', 'fop') ? 'bg-brand-500/20 text-brand-400 border border-brand-500/30 font-bold' : 'text-slate-300 hover:bg-slate-800/60 hover:text-white'; ?>">
                    <i class="fa-solid fa-desktop w-4 text-center"></i>
                    <span>Painel FOP Operador</span>
                </a>
                <?php if (hasUserPermission('manage_settings')): ?>
                <a href="index.php?module=ramais&action=editar_nome" class="flex items-center gap-2.5 px-3 py-2 rounded-lg transition-all <?php echo isNavActive('ramais', 'editar_nome') ? 'bg-brand-500/20 text-brand-400 border border-brand-500/30 font-bold' : 'text-slate-300 hover:bg-slate-800/60 hover:text-white'; ?>">
                    <i class="fa-solid fa-id-card w-4 text-center"></i>
                    <span>Nomes & Tecnologia</span>
                </a>
                <a href="index.php?module=telefonia&action=blacklist" class="flex items-center gap-2.5 px-3 py-2 rounded-lg transition-all <?php echo isNavActive('telefonia', 'blacklist') ? 'bg-brand-500/20 text-brand-400 border border-brand-500/30 font-bold' : 'text-slate-300 hover:bg-slate-800/60 hover:text-white'; ?>">
                    <i class="fa-solid fa-ban w-4 text-center text-rose-400"></i>
                    <span>Blacklist (Bloqueios)</span>
                </a>
                <a href="index.php?module=troncos&action=view" class="flex items-center gap-2.5 px-3 py-2 rounded-lg transition-all <?php echo isNavActive('troncos') ? 'bg-brand-500/20 text-brand-400 border border-brand-500/30 font-bold' : 'text-slate-300 hover:bg-slate-800/60 hover:text-white'; ?>">
                    <i class="fa-solid fa-network-wired w-4 text-center"></i>
                    <span>Linhas & Troncos</span>
                </a>
                <?php endif; ?>
            </div>
        </div>
        <?php endif; ?>

        <!-- GROUP: ATENDIMENTO & FILAS (EXPANSÍVEL) -->
        <?php if (hasUserPermission('mod_filas')): ?>
        <?php $grp2_active = isGroupActive(['filas']); ?>
        <div class="border-t border-slate-800/60 pt-2">
            <button onclick="toggleSidebarGroup('grp-filas')" class="w-full flex items-center justify-between px-3 py-2 text-slate-400 hover:text-white rounded-lg transition font-extrabold uppercase text-[10px] tracking-wider">
                <span class="flex items-center gap-2">
                    <i class="fa-solid fa-users-line text-purple-400"></i>
                    <span>Atendimento & Filas</span>
                </span>
                <i id="icon-grp-filas" class="fa-solid fa-chevron-down transition-transform duration-200 <?php echo $grp2_active ? 'rotate-180' : ''; ?>"></i>
            </button>
            <div id="grp-filas" class="space-y-1 pl-1 mt-1 <?php echo $grp2_active ? '' : 'hidden'; ?>">
                <a href="index.php?module=filas&action=realtime" class="flex items-center gap-2.5 px-3 py-2 rounded-lg transition-all <?php echo isNavActive('filas', 'realtime') ? 'bg-brand-500/20 text-brand-400 border border-brand-500/30 font-bold' : 'text-slate-300 hover:bg-slate-800/60 hover:text-white'; ?>">
                    <i class="fa-solid fa-users-rays w-4 text-center"></i>
                    <span>Filas Ao Vivo</span>
                </a>
                <a href="index.php?module=filas&action=flowchart" class="flex items-center gap-2.5 px-3 py-2 rounded-lg transition-all <?php echo isNavActive('filas', 'flowchart') ? 'bg-brand-500/20 text-brand-400 border border-brand-500/30 font-bold' : 'text-slate-300 hover:bg-slate-800/60 hover:text-white'; ?>">
                    <i class="fa-solid fa-diagram-project w-4 text-center"></i>
                    <span>Fluxograma de Ligações</span>
                    <span class="ml-auto text-[9px] px-1.5 py-0.2 bg-cyan-500/20 text-cyan-400 rounded font-bold">Visual</span>
                </a>
            </div>
        </div>
        <?php endif; ?>

        <!-- GROUP: WHATSAPP & AUTOMAÇÃO (EXPANSÍVEL) -->
        <?php if (hasUserPermission('mod_whatsapp')): ?>
        <?php $grp3_active = isGroupActive(['whatsapp']); ?>
        <div class="border-t border-slate-800/60 pt-2">
            <button onclick="toggleSidebarGroup('grp-whatsapp')" class="w-full flex items-center justify-between px-3 py-2 text-slate-400 hover:text-white rounded-lg transition font-extrabold uppercase text-[10px] tracking-wider">
                <span class="flex items-center gap-2">
                    <i class="fa-brands fa-whatsapp text-emerald-400"></i>
                    <span>WhatsApp & Automação</span>
                </span>
                <i id="icon-grp-whatsapp" class="fa-solid fa-chevron-down transition-transform duration-200 <?php echo $grp3_active ? 'rotate-180' : ''; ?>"></i>
            </button>
            <div id="grp-whatsapp" class="space-y-1 pl-1 mt-1 <?php echo $grp3_active ? '' : 'hidden'; ?>">
                <a href="index.php?module=whatsapp&action=regras" class="flex items-center gap-2.5 px-3 py-2 rounded-lg transition-all <?php echo isNavActive('whatsapp', 'regras') ? 'bg-brand-500/20 text-brand-400 border border-brand-500/30 font-bold' : 'text-slate-300 hover:bg-slate-800/60 hover:text-white'; ?>">
                    <i class="fa-solid fa-wand-magic-sparkles w-4 text-center"></i>
                    <span>Regras URA, Transbordo & NPS</span>
                </a>
                <a href="index.php?module=whatsapp&action=historico" class="flex items-center gap-2.5 px-3 py-2 rounded-lg transition-all <?php echo isNavActive('whatsapp', 'historico') ? 'bg-brand-500/20 text-brand-400 border border-brand-500/30 font-bold' : 'text-slate-300 hover:bg-slate-800/60 hover:text-white'; ?>">
                    <i class="fa-solid fa-clock-rotate-left w-4 text-center"></i>
                    <span>Histórico de Envio & Logs</span>
                </a>
                <a href="index.php?module=whatsapp&action=disparos" class="flex items-center gap-2.5 px-3 py-2 rounded-lg transition-all <?php echo isNavActive('whatsapp', 'disparos') ? 'bg-brand-500/20 text-brand-400 border border-brand-500/30 font-bold' : 'text-slate-300 hover:bg-slate-800/60 hover:text-white'; ?>">
                    <i class="fa-solid fa-paper-plane w-4 text-center"></i>
                    <span>Disparo Rápido / Teste</span>
                </a>
            </div>
        </div>
        <?php endif; ?>

        <!-- GROUP: COMUNICAÇÃO & AGENDA -->
        <div class="border-t border-slate-800/60 pt-2">
            <a href="index.php?module=whatsapp&action=contatos" class="w-full flex items-center justify-between px-3 py-2.5 rounded-xl transition-all <?php echo isNavActive('whatsapp', 'contatos') ? 'bg-emerald-600/20 text-emerald-400 border border-emerald-500/30 shadow-lg font-bold' : 'text-slate-300 hover:bg-slate-800/60 hover:text-white'; ?>">
                <div class="flex items-center gap-2.5 font-bold">
                    <i class="fa-solid fa-address-book text-sm text-emerald-400"></i>
                    <span>Agenda & Contatos</span>
                </div>
            </a>
        </div>

        <!-- GROUP: RELATÓRIOS & GRAVAÇÕES (EXPANSÍVEL) -->
        <?php if (hasUserPermission('mod_relatorios')): ?>
        <?php $grp4_active = isGroupActive(['relatorios']); ?>
        <div class="border-t border-slate-800/60 pt-2">
            <button onclick="toggleSidebarGroup('grp-relatorios')" class="w-full flex items-center justify-between px-3 py-2 text-slate-400 hover:text-white rounded-lg transition font-extrabold uppercase text-[10px] tracking-wider">
                <span class="flex items-center gap-2">
                    <i class="fa-solid fa-chart-line text-rose-400"></i>
                    <span>Relatórios & Gravações</span>
                </span>
                <i id="icon-grp-relatorios" class="fa-solid fa-chevron-down transition-transform duration-200 <?php echo $grp4_active ? 'rotate-180' : ''; ?>"></i>
            </button>
            <div id="grp-relatorios" class="space-y-1 pl-1 mt-1 <?php echo $grp4_active ? '' : 'hidden'; ?>">
                <a href="index.php?module=relatorios&action=relatorio_filas" class="flex items-center gap-2.5 px-3 py-2 rounded-lg transition-all <?php echo isNavActive('relatorios', 'relatorio_filas') ? 'bg-brand-500/20 text-brand-400 border border-brand-500/30 font-bold' : 'text-slate-300 hover:bg-slate-800/60 hover:text-white'; ?>">
                    <i class="fa-solid fa-list-check w-4 text-center text-purple-400"></i>
                    <span>Relatório Completo de Filas</span>
                </a>
                <a href="index.php?module=relatorios&action=cdr_gravacoes" class="flex items-center gap-2.5 px-3 py-2 rounded-lg transition-all <?php echo isNavActive('relatorios', 'cdr_gravacoes') ? 'bg-brand-500/20 text-brand-400 border border-brand-500/30 font-bold' : 'text-slate-300 hover:bg-slate-800/60 hover:text-white'; ?>">
                    <i class="fa-solid fa-headset w-4 text-center"></i>
                    <span>CDR & Gravações</span>
                </a>
                <a href="index.php?module=relatorios&action=graphic_reports" class="flex items-center gap-2.5 px-3 py-2 rounded-lg transition-all <?php echo isNavActive('relatorios', 'graphic_reports') ? 'bg-brand-500/20 text-brand-400 border border-brand-500/30 font-bold' : 'text-slate-300 hover:bg-slate-800/60 hover:text-white'; ?>">
                    <i class="fa-solid fa-chart-pie w-4 text-center text-cyan-400"></i>
                    <span>Relatórios Gráficos</span>
                </a>
                <a href="index.php?module=relatorios&action=relatorio_geral" class="flex items-center gap-2.5 px-3 py-2 rounded-lg transition-all <?php echo isNavActive('relatorios', 'relatorio_geral') ? 'bg-brand-500/20 text-brand-400 border border-brand-500/30 font-bold' : 'text-slate-300 hover:bg-slate-800/60 hover:text-white'; ?>">
                    <i class="fa-solid fa-fire-flame-curved w-4 text-center text-amber-400"></i>
                    <span>Relatório Geral & Heatmap</span>
                </a>
                <a href="index.php?module=relatorios&action=ia_analytics" class="flex items-center gap-2.5 px-3 py-2 rounded-lg transition-all <?php echo isNavActive('relatorios', 'ia_analytics') ? 'bg-purple-600/30 text-purple-300 border border-purple-500/40 font-bold shadow-lg shadow-purple-900/30' : 'text-purple-300/80 hover:bg-slate-800/60 hover:text-purple-200'; ?>">
                    <i class="fa-solid fa-sparkles w-4 text-center text-purple-400 animate-pulse"></i>
                    <span>Relatórios de IA</span>
                    <span class="ml-auto text-[9px] px-1.5 py-0.5 bg-purple-500/20 text-purple-300 border border-purple-500/30 rounded font-black uppercase">NOVO</span>
                </a>
            </div>
        </div>
        <?php endif; ?>

        <!-- GROUP: CONFIGURAÇÕES (EXPANSÍVEL) -->
        <?php if (hasUserPermission('mod_configuracoes')): ?>
        <?php $grp5_active = isGroupActive(['configuracoes']); ?>
        <div class="border-t border-slate-800/60 pt-2">
            <button onclick="toggleSidebarGroup('grp-config')" class="w-full flex items-center justify-between px-3 py-2 text-slate-400 hover:text-white rounded-lg transition font-extrabold uppercase text-[10px] tracking-wider">
                <span class="flex items-center gap-2">
                    <i class="fa-solid fa-gear text-slate-400"></i>
                    <span>Configurações</span>
                </span>
                <i id="icon-grp-config" class="fa-solid fa-chevron-down transition-transform duration-200 <?php echo $grp5_active ? 'rotate-180' : ''; ?>"></i>
            </button>
            <div id="grp-config" class="space-y-1 pl-1 mt-1 <?php echo $grp5_active ? '' : 'hidden'; ?>">
                <a href="index.php?module=configuracoes&action=api" class="flex items-center gap-2.5 px-3 py-2 rounded-lg transition-all <?php echo (isNavActive('configuracoes', 'api') || isNavActive('configuracoes', 'api_connection')) ? 'bg-brand-500/20 text-brand-400 border border-brand-500/30 font-bold' : 'text-slate-300 hover:bg-slate-800/60 hover:text-white'; ?>">
                    <i class="fa-solid fa-key w-4 text-center text-cyan-400"></i>
                    <span>APIs & Conexões (PABX / Prismabot)</span>
                    <span class="ml-auto text-[9px] px-1.5 py-0.5 bg-cyan-500/20 text-cyan-300 border border-cyan-500/30 rounded font-black uppercase">API</span>
                </a>
                <a href="index.php?module=configuracoes&action=ia" class="flex items-center gap-2.5 px-3 py-2 rounded-lg transition-all <?php echo isNavActive('configuracoes', 'ia') ? 'bg-brand-500/20 text-brand-400 border border-brand-500/30 font-bold' : 'text-slate-300 hover:bg-slate-800/60 hover:text-white'; ?>">
                    <i class="fa-solid fa-robot w-4 text-center text-purple-400"></i>
                    <span>Inteligência Artificial</span>
                </a>
                <a href="index.php?module=configuracoes&action=usuarios" class="flex items-center gap-2.5 px-3 py-2 rounded-lg transition-all <?php echo isNavActive('configuracoes', 'usuarios') ? 'bg-brand-500/20 text-brand-400 border border-brand-500/30 font-bold' : 'text-slate-300 hover:bg-slate-800/60 hover:text-white'; ?>">
                    <i class="fa-solid fa-user-gear w-4 text-center text-rose-400"></i>
                    <span>Usuários & Permissões</span>
                </a>
                <a href="index.php?module=configuracoes&action=smtp" class="flex items-center gap-2.5 px-3 py-2 rounded-lg transition-all <?php echo isNavActive('configuracoes', 'smtp') ? 'bg-brand-500/20 text-brand-400 border border-brand-500/30 font-bold' : 'text-slate-300 hover:bg-slate-800/60 hover:text-white'; ?>">
                    <i class="fa-solid fa-envelope-circle-check w-4 text-center text-amber-400"></i>
                    <span>Servidor SMTP & Disparos</span>
                </a>
                <a href="index.php?module=configuracoes&action=logs" class="flex items-center gap-2.5 px-3 py-2 rounded-lg transition-all <?php echo isNavActive('configuracoes', 'logs') ? 'bg-brand-500/20 text-brand-400 border border-brand-500/30 font-bold' : 'text-slate-300 hover:bg-slate-800/60 hover:text-white'; ?>">
                    <i class="fa-solid fa-terminal w-4 text-center text-emerald-400"></i>
                    <span>Logs & Diagnósticos</span>
                    <span class="ml-auto text-[9px] px-1.5 py-0.5 bg-emerald-500/20 text-emerald-300 border border-emerald-500/30 rounded font-black uppercase">LOGS</span>
                </a>
            </div>
        </div>
        <?php endif; ?>

    </nav>

    <!-- Footer System Status, Theme Toggle & User Profile -->
    <div class="p-3 bg-slate-950/80 border-t border-slate-800 space-y-2">
        <!-- Botão Modo Claro / Modo Escuro -->
        <button id="btn-theme-toggle" onclick="toggleThemeMode()" class="w-full px-3 py-2 rounded-xl bg-slate-900 hover:bg-slate-800 border border-slate-800 text-slate-300 hover:text-white text-xs font-bold transition flex items-center justify-between shadow">
            <span class="flex items-center gap-2">
                <i id="theme-icon" class="fa-solid fa-sun text-amber-400"></i>
                <span id="theme-label-text">Modo Claro</span>
            </span>
            <span class="text-[10px] text-slate-500 font-mono">Theme</span>
        </button>

        <?php $logged_user = getLoggedUser(); ?>
        <!-- Perfil do Usuário Logado Clicável -->
        <div onclick="openUserProfileModal()" class="flex items-center gap-2.5 p-2 rounded-xl bg-slate-900/60 border border-slate-800/60 hover:bg-slate-800/80 hover:border-slate-700 transition cursor-pointer sidebar-text" title="Clique para abrir as configurações do perfil">
            <div class="w-8 h-8 rounded-full bg-slate-800 border border-slate-700 flex items-center justify-center text-slate-300 font-bold text-xs">
                <i class="fa-solid fa-user text-brand-400"></i>
            </div>
            <div class="overflow-hidden flex-1">
                <span class="text-xs font-extrabold text-white block truncate"><?php echo htmlspecialchars($logged_user['name'] ?? 'Usuário'); ?></span>
                <span class="text-[10px] text-slate-400 block font-mono"><?php echo htmlspecialchars($logged_user['role'] ?? 'Administrador PABX'); ?></span>
            </div>
            <i class="fa-solid fa-gear text-xs text-slate-500"></i>
        </div>
        <a href="index.php?auth=logout" class="mt-2 flex items-center justify-center gap-2 px-3 py-2 rounded-xl text-xs font-bold text-rose-300 bg-rose-500/10 border border-rose-500/20 hover:bg-rose-500/20 transition sidebar-text" style="white-space:nowrap"><i class="fa-solid fa-right-from-bracket"></i> Sair</a>
    </div>
</aside>

<script>
    function toggleSidebarGroup(grpId) {
        const el = document.getElementById(grpId);
        const icon = document.getElementById('icon-' + grpId);
        if (el) {
            const isHidden = el.classList.toggle('hidden');
            if (icon) icon.classList.toggle('rotate-180');
            localStorage.setItem('sidebar_grp_' + grpId, isHidden ? 'closed' : 'open');
        }
    }

    function toggleSidebarCollapse() {
        const sidebar = document.getElementById('sidebar-panel');
        if (!sidebar) return;
        const isCollapsed = sidebar.classList.toggle('w-16');
        sidebar.classList.toggle('w-64');
        
        const texts = sidebar.querySelectorAll('.sidebar-text, nav span, #grp-ramais, #grp-filas, #grp-whatsapp, #grp-relatorios, #grp-config');
        texts.forEach(t => {
            if (isCollapsed) t.classList.add('hidden');
            else t.classList.remove('hidden');
        });
        localStorage.setItem('sidebar_collapsed', isCollapsed ? 'true' : 'false');
    }

    function toggleThemeMode() {
        const isLight = document.body.classList.toggle('theme-light');
        const themeIcon = document.getElementById('theme-icon');
        const themeLabel = document.getElementById('theme-label-text');
        
        if (isLight) {
            themeIcon.className = 'fa-solid fa-moon text-indigo-400';
            themeLabel.innerText = 'Modo Escuro';
            localStorage.setItem('prisma_theme', 'light');
        } else {
            themeIcon.className = 'fa-solid fa-sun text-amber-400';
            themeLabel.innerText = 'Modo Claro';
            localStorage.setItem('prisma_theme', 'dark');
        }
    }

    document.addEventListener('DOMContentLoaded', () => {
        // Carregar tema salvo
        if (localStorage.getItem('prisma_theme') === 'light') {
            document.body.classList.add('theme-light');
            const themeIcon = document.getElementById('theme-icon');
            const themeLabel = document.getElementById('theme-label-text');
            if (themeIcon) themeIcon.className = 'fa-solid fa-moon text-indigo-400';
            if (themeLabel) themeLabel.innerText = 'Modo Escuro';
        }

        // Carregar estado de sidebar recolhido
        if (localStorage.getItem('sidebar_collapsed') === 'true') {
            toggleSidebarCollapse();
        }

        ['grp-ramais', 'grp-filas', 'grp-whatsapp', 'grp-relatorios', 'grp-config'].forEach(grpId => {
            const savedState = localStorage.getItem('sidebar_grp_' + grpId);
            const el = document.getElementById(grpId);
            const icon = document.getElementById('icon-' + grpId);
            
            if (el) {
                const hasActiveLink = el.querySelector('.bg-brand-500\\/20') !== null;
                if (savedState === 'open' || hasActiveLink) {
                    el.classList.remove('hidden');
                    if (icon) icon.classList.add('rotate-180');
                } else if (savedState === 'closed' && !hasActiveLink) {
                    el.classList.add('hidden');
                    if (icon) icon.classList.remove('rotate-180');
                }
            }
        });
    });
</script>
