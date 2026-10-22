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

<aside id="sidebar-panel" class="w-64 bg-slate-900/95 border-r border-slate-800 flex flex-col shrink-0 h-screen sticky top-0 self-start transition-all duration-300 z-30 select-none">
    <!-- Logo Header & Collapse Button -->
    <div id="brand-header" class="px-4 py-3.5 border-b border-slate-800 flex items-center justify-between relative overflow-hidden" style="background:linear-gradient(135deg,#0b1020 0%,#1c1b2f 55%,#2a2250 100%)">
        <span aria-hidden="true" class="pointer-events-none absolute -right-8 -top-10 w-28 h-28 rounded-full" style="background:radial-gradient(circle,rgba(124,58,237,.35),transparent 70%)"></span>
        <!-- Marca completa (menu aberto) -->
        <a href="index.php?module=dashboard&action=view_v1" onclick="brandHome()" class="brand-full relative flex flex-col gap-1.5" title="Ir para o Dashboard e recolher os menus">
            <img src="img/logo-prisma.png" alt="Prisma Telecom" width="150" height="42" style="height:42px;width:auto;display:block" draggable="false">
            <span class="text-[10px] font-mono tracking-wide" style="color:#a5b4fc">PABX IP <span style="color:#64748b">•</span> v6.1</span>
        </a>
        <!-- Marca reduzida (menu recolhido): clique para abrir -->
        <button type="button" onclick="toggleSidebarCollapse()" class="brand-mini hidden relative mx-auto" title="Mostrar barra lateral" style="background:none;border:0;padding:0">
            <img src="img/triangulo-prisma.png" alt="Prisma" width="30" height="29" style="width:30px;height:auto;display:block" draggable="false">
        </button>

        <!-- Botão Ocultar / Collapse Sidebar -->
        <button onclick="toggleSidebarCollapse()" class="brand-full relative p-1.5 rounded-lg text-slate-300 hover:text-white hover:bg-white/10 transition" title="Ocultar / Mostrar Barra Lateral">
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
        <!-- MANUAL DA PLATAFORMA -->
        <a href="index.php?module=manual&action=view" class="w-full flex items-center px-3 py-2.5 rounded-xl transition-all <?php echo isNavActive('manual') ? 'bg-brand-600/20 text-brand-400 border border-brand-500/30 font-bold' : 'text-slate-300 hover:bg-slate-800/60 hover:text-white'; ?>">
            <div class="flex items-center gap-2.5 font-bold"><i class="fa-solid fa-book text-sm"></i><span>Manual</span></div>
        </a>

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
                    <span>Ramais & Atendentes</span>
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
                    <span>Regras URA & Transbordo</span>
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
                <a href="index.php?module=relatorios&action=pesquisa" class="flex items-center gap-2.5 px-3 py-2 rounded-lg transition-all <?php echo isNavActive('relatorios', 'pesquisa') ? 'bg-brand-500/20 text-brand-400 border border-brand-500/30 font-bold' : 'text-slate-300 hover:bg-slate-800/60 hover:text-white'; ?>">
                    <i class="fa-solid fa-star w-4 text-center text-amber-400"></i>
                    <span>Pesquisa de Satisfação</span>
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
                <a href="index.php?module=configuracoes&action=status" class="flex items-center gap-2.5 px-3 py-2 rounded-lg transition-all <?php echo isNavActive('configuracoes', 'status') ? 'bg-brand-500/20 text-brand-400 border border-brand-500/30 font-bold' : 'text-slate-300 hover:bg-slate-800/60 hover:text-white'; ?>">
                    <i class="fa-solid fa-heart-pulse w-4 text-center text-rose-400"></i>
                    <span>Status da Integração</span>
                </a>
                <a href="index.php?module=configuracoes&action=seguranca" class="flex items-center gap-2.5 px-3 py-2 rounded-lg transition-all <?php echo isNavActive('configuracoes', 'seguranca') ? 'bg-brand-500/20 text-brand-400 border border-brand-500/30 font-bold' : 'text-slate-300 hover:bg-slate-800/60 hover:text-white'; ?>">
                    <i class="fa-solid fa-shield-halved w-4 text-center text-rose-400"></i>
                    <span>Painel de Segurança</span>
                </a>
                <a href="index.php?module=configuracoes&action=chat_ia" class="flex items-center gap-2.5 px-3 py-2 rounded-lg transition-all <?php echo isNavActive('configuracoes', 'chat_ia') ? 'bg-brand-500/20 text-brand-400 border border-brand-500/30 font-bold' : 'text-slate-300 hover:bg-slate-800/60 hover:text-white'; ?>">
                    <i class="fa-solid fa-robot w-4 text-center text-violet-400"></i>
                    <span>Conversas do Assistente IA</span>
                </a>
                <a href="index.php?module=configuracoes&action=logs" class="flex items-center gap-2.5 px-3 py-2 rounded-lg transition-all <?php echo isNavActive('configuracoes', 'logs') ? 'bg-brand-500/20 text-brand-400 border border-brand-500/30 font-bold' : 'text-slate-300 hover:bg-slate-800/60 hover:text-white'; ?>">
                    <i class="fa-solid fa-terminal w-4 text-center text-emerald-400"></i>
                    <span>Logs & Diagnósticos</span>
                    <span class="ml-auto text-[9px] px-1.5 py-0.5 bg-emerald-500/20 text-emerald-300 border border-emerald-500/30 rounded font-black uppercase">LOGS</span>
                </a>
                <?php if (hasUserPermission('manage_settings')): ?>
                <a href="index.php?module=configuracoes&action=console" class="flex items-center gap-2.5 px-3 py-2 rounded-lg transition-all <?php echo isNavActive('configuracoes', 'console') ? 'bg-brand-500/20 text-brand-400 border border-brand-500/30 font-bold' : 'text-slate-300 hover:bg-slate-800/60 hover:text-white'; ?>">
                    <i class="fa-solid fa-code w-4 text-center text-violet-400"></i>
                    <span>Console da central</span>
                </a>
                <?php endif; ?>
            </div>
        </div>
        <?php endif; ?>

    <!-- Rodapé: Novidades, Tema e Conta (menu do usuário abre para cima) -->
    <div class="mt-4 pt-3 border-t border-slate-800/80 space-y-0.5">
        <!-- Novidades do sistema (histórico de entregas) -->
        <button type="button" onclick="openRoadmapModal()" class="w-full px-2.5 py-1.5 rounded-lg hover:bg-slate-800/70 text-slate-300 hover:text-white text-[12px] font-semibold transition flex items-center justify-between" style="white-space:nowrap">
            <span class="flex items-center gap-2.5"><i class="fa-solid fa-rocket w-4 text-center text-amber-400"></i><span class="sidebar-text">Novidades</span></span>
            <span class="w-1.5 h-1.5 rounded-full bg-amber-400 animate-pulse"></span>
        </button>

        <!-- Botão Modo Claro / Modo Escuro -->
        <button id="btn-theme-toggle" onclick="toggleThemeMode()" class="w-full px-2.5 py-1.5 rounded-lg hover:bg-slate-800/70 text-slate-300 hover:text-white text-[12px] font-semibold transition flex items-center" style="white-space:nowrap">
            <span class="flex items-center gap-2.5">
                <i id="theme-icon" class="fa-solid fa-sun w-4 text-center text-amber-400"></i>
                <span id="theme-label-text">Modo Claro</span>
            </span>
        </button>

        <?php $logged_user = getLoggedUser(); $__nome_rod = (string)($logged_user['name'] ?? 'Usuário'); ?>
        <!-- Conta do usuário logado: clique abre o menu (Perfil, Painel clássico, Sair) -->
        <div id="user-menu-wrap" class="relative pt-1.5">
            <div id="user-menu-pop" class="hidden absolute bottom-full left-0 right-0 mb-1 p-1 rounded-xl bg-slate-900 border border-slate-700 shadow-2xl z-30 space-y-0.5">
                <button type="button" onclick="toggleUserMenu(false); openUserProfileModal();" class="w-full px-2.5 py-2 rounded-lg hover:bg-slate-800 text-slate-200 text-[12px] font-semibold transition flex items-center gap-2.5" style="white-space:nowrap"><i class="fa-regular fa-user w-4 text-center text-brand-400"></i> Perfil</button>
                <?php if (!empty($_SESSION['via_sso'])): ?>
                <a href="../seletor.php?ir=classico" class="w-full px-2.5 py-2 rounded-lg hover:bg-slate-800 text-slate-200 text-[12px] font-semibold transition flex items-center gap-2.5" style="white-space:nowrap" title="Abrir o painel clássico"><i class="fa-solid fa-right-left w-4 text-center text-slate-400"></i> Painel clássico</a>
                <?php endif; ?>
                <button type="button" onclick="limparCookiesPainel();" class="w-full px-2.5 py-2 rounded-lg hover:bg-slate-800 text-slate-200 text-[12px] font-semibold transition flex items-center gap-2.5" style="white-space:nowrap;flex-shrink:0" title="Apaga os dados que o painel guarda neste navegador, sem encerrar o seu acesso"><i class="fa-solid fa-cookie-bite w-4 text-center text-slate-400"></i> Limpar cookies</button>
                <a href="index.php?auth=logout" class="w-full px-2.5 py-2 rounded-lg bg-rose-500/10 hover:bg-rose-500/20 text-rose-300 text-[12px] font-bold transition flex items-center gap-2.5" style="white-space:nowrap"><i class="fa-solid fa-right-from-bracket w-4 text-center"></i> Sair</a>
            </div>
            <button type="button" id="user-menu-btn" onclick="toggleUserMenu()" aria-haspopup="true" aria-expanded="false" class="w-full flex items-center gap-2.5 px-2 py-1.5 rounded-xl hover:bg-slate-800/70 transition text-left" title="Conta: perfil, painel clássico e sair">
                <span class="w-8 h-8 shrink-0 rounded-full bg-gradient-to-br from-violet-600 to-indigo-500 flex items-center justify-center text-white font-black text-[13px] shadow"><?php echo htmlspecialchars(mb_strtoupper(mb_substr($__nome_rod, 0, 1, 'UTF-8'), 'UTF-8')); ?></span>
                <span class="overflow-hidden flex-1 sidebar-text">
                    <span class="text-[12px] font-extrabold text-white block truncate leading-tight"><?php echo htmlspecialchars($__nome_rod); ?></span>
                    <span class="text-[10px] text-slate-400 block leading-tight truncate"><?php echo htmlspecialchars($logged_user['role'] ?? 'Administrador PABX'); ?></span>
                </span>
                <i class="fa-solid fa-chevron-up text-[10px] text-slate-500 sidebar-text"></i>
            </button>
        </div>
    </div>
    </nav>
</aside>

<script>
    // Apaga cookies comuns e dados guardados pelo painel neste navegador. O cookie de sessão (protegido) permanece: para encerrar o acesso use "Sair".
    function limparCookiesPainel() {
        try {
            var partes = location.pathname.split('/').filter(Boolean), caminhos = ['/'], acc = '';
            partes.forEach(function (p) { acc += '/' + p; caminhos.push(acc); });
            document.cookie.split(';').forEach(function (c) {
                var nome = c.split('=')[0].trim();
                if (!nome) return;
                caminhos.forEach(function (cam) { document.cookie = nome + '=; expires=Thu, 01 Jan 1970 00:00:00 GMT; path=' + cam; });
            });
        } catch (e) {}
        try { localStorage.clear(); } catch (e) {}
        try { sessionStorage.clear(); } catch (e) {}
        try { (window.top || window).location.reload(); } catch (e) { location.reload(); }
    }
    function toggleUserMenu(force) {
        const pop = document.getElementById('user-menu-pop');
        const btn = document.getElementById('user-menu-btn');
        if (!pop) return;
        const abrir = (typeof force === 'boolean') ? force : pop.classList.contains('hidden');
        pop.classList.toggle('hidden', !abrir);
        if (btn) btn.setAttribute('aria-expanded', abrir ? 'true' : 'false');
    }
    document.addEventListener('click', function (e) {
        const wrap = document.getElementById('user-menu-wrap');
        if (wrap && !wrap.contains(e.target)) toggleUserMenu(false);
    });
    document.addEventListener('keydown', function (e) { if (e.key === 'Escape') toggleUserMenu(false); });

    // Clique na marca: vai para o Dashboard com todos os grupos do menu recolhidos
    function brandHome() {
        try {
            ['grp-ramais', 'grp-filas', 'grp-whatsapp', 'grp-relatorios', 'grp-config'].forEach(function (g) {
                localStorage.setItem('sidebar_grp_' + g, 'closed');
            });
        } catch (e) {}
    }

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
        sidebar.querySelectorAll('.brand-full').forEach(t => t.classList.toggle('hidden', isCollapsed));
        sidebar.querySelectorAll('.brand-mini').forEach(t => t.classList.toggle('hidden', !isCollapsed));
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
