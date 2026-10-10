<?php
/**
 * IPbx Prisma - Sidebar Navigation (Menus e Submenus Modular)
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
?>

<aside id="sidebar-panel" class="w-64 bg-slate-900/95 border-r border-slate-800 flex flex-col shrink-0 min-h-screen transition-all duration-300 z-30 select-none">
    <!-- Logo Header -->
    <div class="p-4 border-b border-slate-800 flex items-center justify-between">
        <div class="flex items-center gap-3">
            <div class="w-9 h-9 rounded-xl bg-gradient-to-tr from-brand-600 to-indigo-500 flex items-center justify-center text-white shadow-lg shadow-brand-500/20 font-black text-lg">
                P
            </div>
            <div>
                <h1 class="text-sm font-black tracking-wider text-white flex items-center gap-1.5">
                    IPBX PRISMA
                    <span class="px-1.5 py-0.5 text-[9px] bg-brand-500/20 text-brand-400 border border-brand-500/30 rounded font-bold uppercase">PRO</span>
                </h1>
                <span class="text-[10px] text-slate-400 block font-mono">v4.5 • Issabel PBX</span>
            </div>
        </div>
    </div>

    <!-- Navigation List -->
    <nav class="flex-1 p-3 space-y-1.5 overflow-y-auto custom-scrollbar text-xs font-semibold">
        
        <!-- MENU: DASHBOARD -->
        <div>
            <a href="index.php?module=dashboard&action=view" class="w-full flex items-center justify-between px-3 py-2.5 rounded-xl transition-all <?php echo isNavActive('dashboard') ? 'bg-brand-600 text-white shadow-lg shadow-brand-600/25' : 'text-slate-300 hover:bg-slate-800/60 hover:text-white'; ?>">
                <div class="flex items-center gap-2.5">
                    <i class="fa-solid fa-chart-pie text-sm"></i>
                    <span>Dashboard</span>
                </div>
            </a>
        </div>

        <!-- MENU: PABX & RAMAIS -->
        <div class="pt-2">
            <div class="px-3 pb-1.5 text-[10px] uppercase font-extrabold text-slate-400 tracking-wider">
                PABX & Ramais
            </div>
            <div class="space-y-1 pl-1">
                <a href="index.php?module=ramais&action=fop" class="flex items-center gap-2.5 px-3 py-2 rounded-lg transition-all <?php echo isNavActive('ramais', 'fop') ? 'bg-brand-500/20 text-brand-400 border border-brand-500/30 font-bold' : 'text-slate-300 hover:bg-slate-800/60 hover:text-white'; ?>">
                    <i class="fa-solid fa-desktop w-4 text-center"></i>
                    <span>Painel FOP Operador</span>
                </a>
                <a href="index.php?module=ramais&action=editar_nome" class="flex items-center gap-2.5 px-3 py-2 rounded-lg transition-all <?php echo isNavActive('ramais', 'editar_nome') ? 'bg-brand-500/20 text-brand-400 border border-brand-500/30 font-bold' : 'text-slate-300 hover:bg-slate-800/60 hover:text-white'; ?>">
                    <i class="fa-solid fa-id-card w-4 text-center"></i>
                    <span>Nomes & Tecnologia</span>
                    <span class="ml-auto text-[9px] px-1.5 py-0.2 bg-emerald-500/20 text-emerald-400 rounded">Auto</span>
                </a>
                <a href="index.php?module=ramais&action=gestao" class="flex items-center gap-2.5 px-3 py-2 rounded-lg transition-all <?php echo isNavActive('ramais', 'gestao') ? 'bg-brand-500/20 text-brand-400 border border-brand-500/30 font-bold' : 'text-slate-300 hover:bg-slate-800/60 hover:text-white'; ?>">
                    <i class="fa-solid fa-sliders w-4 text-center"></i>
                    <span>Permissões de Ramais</span>
                </a>
                <a href="index.php?module=troncos&action=view" class="flex items-center gap-2.5 px-3 py-2 rounded-lg transition-all <?php echo isNavActive('troncos') ? 'bg-brand-500/20 text-brand-400 border border-brand-500/30 font-bold' : 'text-slate-300 hover:bg-slate-800/60 hover:text-white'; ?>">
                    <i class="fa-solid fa-network-wired w-4 text-center"></i>
                    <span>Linhas & Troncos</span>
                </a>
            </div>
        </div>

        <!-- MENU: ATENDIMENTO & FILAS -->
        <div class="pt-2">
            <div class="px-3 pb-1.5 text-[10px] uppercase font-extrabold text-slate-400 tracking-wider">
                Atendimento & Filas
            </div>
            <div class="space-y-1 pl-1">
                <a href="index.php?module=filas&action=realtime" class="flex items-center gap-2.5 px-3 py-2 rounded-lg transition-all <?php echo isNavActive('filas', 'realtime') ? 'bg-brand-500/20 text-brand-400 border border-brand-500/30 font-bold' : 'text-slate-300 hover:bg-slate-800/60 hover:text-white'; ?>">
                    <i class="fa-solid fa-users-rays w-4 text-center"></i>
                    <span>Filas Ao Vivo</span>
                    <span class="ml-auto text-[9px] px-1.5 py-0.2 bg-purple-500/20 text-purple-300 rounded font-bold">Novo</span>
                </a>
                <a href="index.php?module=filas&action=flowchart" class="flex items-center gap-2.5 px-3 py-2 rounded-lg transition-all <?php echo isNavActive('filas', 'flowchart') ? 'bg-brand-500/20 text-brand-400 border border-brand-500/30 font-bold' : 'text-slate-300 hover:bg-slate-800/60 hover:text-white'; ?>">
                    <i class="fa-solid fa-diagram-project w-4 text-center"></i>
                    <span>Fluxograma de Ligações</span>
                    <span class="ml-auto text-[9px] px-1.5 py-0.2 bg-cyan-500/20 text-cyan-400 rounded font-bold">Visual</span>
                </a>
                <a href="index.php?module=filas&action=agentes" class="flex items-center gap-2.5 px-3 py-2 rounded-lg transition-all <?php echo isNavActive('filas', 'agentes') ? 'bg-brand-500/20 text-brand-400 border border-brand-500/30 font-bold' : 'text-slate-300 hover:bg-slate-800/60 hover:text-white'; ?>">
                    <i class="fa-solid fa-user-clock w-4 text-center"></i>
                    <span>Pausa de Agentes</span>
                </a>
            </div>
        </div>

        <!-- MENU: RELATÓRIOS & GRAVAÇÕES -->
        <div class="pt-2">
            <div class="px-3 pb-1.5 text-[10px] uppercase font-extrabold text-slate-400 tracking-wider">
                Relatórios & Gravações
            </div>
            <div class="space-y-1 pl-1">
                <a href="index.php?module=relatorios&action=filas_stats" class="flex items-center gap-2.5 px-3 py-2 rounded-lg transition-all <?php echo isNavActive('relatorios', 'filas_stats') ? 'bg-brand-500/20 text-brand-400 border border-brand-500/30 font-bold' : 'text-slate-300 hover:bg-slate-800/60 hover:text-white'; ?>">
                    <i class="fa-solid fa-chart-line w-4 text-center"></i>
                    <span>Relatório de Filas</span>
                </a>
                <a href="index.php?module=relatorios&action=cdr_gravacoes" class="flex items-center gap-2.5 px-3 py-2 rounded-lg transition-all <?php echo isNavActive('relatorios', 'cdr_gravacoes') ? 'bg-brand-500/20 text-brand-400 border border-brand-500/30 font-bold' : 'text-slate-300 hover:bg-slate-800/60 hover:text-white'; ?>">
                    <i class="fa-solid fa-headset w-4 text-center"></i>
                    <span>Gravações & CDR</span>
                </a>
            </div>
        </div>

        <!-- MENU: INTEGRAÇÃO WHATSAPP -->
        <div class="pt-2">
            <div class="px-3 pb-1.5 text-[10px] uppercase font-extrabold text-slate-400 tracking-wider">
                Integração WhatsApp
            </div>
            <div class="space-y-1 pl-1">
                <a href="index.php?module=whatsapp&action=regras" class="flex items-center gap-2.5 px-3 py-2 rounded-lg transition-all <?php echo isNavActive('whatsapp', 'regras') ? 'bg-brand-500/20 text-brand-400 border border-brand-500/30 font-bold' : 'text-slate-300 hover:bg-slate-800/60 hover:text-white'; ?>">
                    <i class="fa-solid fa-wand-magic-sparkles w-4 text-center"></i>
                    <span>Regras URA & NPS</span>
                </a>
                <a href="index.php?module=whatsapp&action=historico" class="flex items-center gap-2.5 px-3 py-2 rounded-lg transition-all <?php echo isNavActive('whatsapp', 'historico') ? 'bg-brand-500/20 text-brand-400 border border-brand-500/30 font-bold' : 'text-slate-300 hover:bg-slate-800/60 hover:text-white'; ?>">
                    <i class="fa-solid fa-clock-rotate-left w-4 text-center"></i>
                    <span>Histórico de Disparos</span>
                </a>
                <a href="index.php?module=whatsapp&action=disparos" class="flex items-center gap-2.5 px-3 py-2 rounded-lg transition-all <?php echo isNavActive('whatsapp', 'disparos') ? 'bg-brand-500/20 text-brand-400 border border-brand-500/30 font-bold' : 'text-slate-300 hover:bg-slate-800/60 hover:text-white'; ?>">
                    <i class="fa-solid fa-paper-plane w-4 text-center"></i>
                    <span>Disparo Rápido Teste</span>
                </a>
            </div>
        </div>

        <!-- MENU: CONFIGURAÇÕES (ISOLADO) -->
        <div class="pt-4 border-t border-slate-800">
            <div class="px-3 pb-1.5 text-[10px] uppercase font-extrabold text-slate-400 tracking-wider">
                Configurações
            </div>
            <div class="space-y-1 pl-1">
                <a href="index.php?module=configuracoes&action=ami" class="flex items-center gap-2.5 px-3 py-2 rounded-lg transition-all <?php echo isNavActive('configuracoes', 'ami') ? 'bg-brand-500/20 text-brand-400 border border-brand-500/30 font-bold' : 'text-slate-300 hover:bg-slate-800/60 hover:text-white'; ?>">
                    <i class="fa-solid fa-plug w-4 text-center"></i>
                    <span>Asterisk AMI (Click2Call)</span>
                </a>
                <a href="index.php?module=configuracoes&action=ia" class="flex items-center gap-2.5 px-3 py-2 rounded-lg transition-all <?php echo isNavActive('configuracoes', 'ia') ? 'bg-brand-500/20 text-brand-400 border border-brand-500/30 font-bold' : 'text-slate-300 hover:bg-slate-800/60 hover:text-white'; ?>">
                    <i class="fa-solid fa-robot w-4 text-center"></i>
                    <span>Inteligência Artificial (IA)</span>
                </a>
                <a href="index.php?module=configuracoes&action=api" class="flex items-center gap-2.5 px-3 py-2 rounded-lg transition-all <?php echo isNavActive('configuracoes', 'api') ? 'bg-brand-500/20 text-brand-400 border border-brand-500/30 font-bold' : 'text-slate-300 hover:bg-slate-800/60 hover:text-white'; ?>">
                    <i class="fa-solid fa-key w-4 text-center"></i>
                    <span>API Prismabot & Token</span>
                </a>
            </div>
        </div>

    </nav>

    <!-- Sidebar Footer -->
    <div class="p-3 border-t border-slate-800 bg-slate-950/60 text-[11px] text-slate-400 flex items-center justify-between">
        <div class="flex items-center gap-2">
            <span class="w-2 h-2 rounded-full bg-emerald-400 animate-pulse"></span>
            <span>Sistema Ativo</span>
        </div>
        <span class="font-mono text-[10px]">PHP 7.4+</span>
    </div>
</aside>
