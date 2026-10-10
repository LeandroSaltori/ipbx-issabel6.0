<?php
/**
 * IPbx Prisma - Top Header Bar
 */
?>

<header class="h-16 bg-slate-900/90 border-b border-slate-800 px-6 flex items-center justify-between shrink-0 z-20">
    <div class="flex items-center gap-4">
        <!-- Toggle Mobile Sidebar -->
        <button onclick="toggleMobileSidebar()" class="lg:hidden p-2 text-slate-400 hover:text-white rounded-lg hover:bg-slate-800 transition">
            <i class="fa-solid fa-bars text-lg"></i>
        </button>

        <!-- Dynamic Breadcrumb / Page Title -->
        <div>
            <h2 class="text-sm font-bold text-white flex items-center gap-2">
                <?php
                $mod = isset($_GET['module']) ? $_GET['module'] : 'dashboard';
                $act = isset($_GET['action']) ? $_GET['action'] : 'view';

                $titles = [
                    'dashboard' => '<i class="fa-solid fa-chart-pie text-brand-400"></i> Dashboard & Visão Geral',
                    'ramais' => ($act === 'fop' ? '<i class="fa-solid fa-desktop text-emerald-400"></i> Painel de Operador FOP' : ($act === 'editar_nome' ? '<i class="fa-solid fa-id-card text-blue-400"></i> Autonomia de Nomes & Tecnologia' : '<i class="fa-solid fa-sliders text-indigo-400"></i> Permissões de Ramais')),
                    'troncos' => '<i class="fa-solid fa-network-wired text-cyan-400"></i> Linhas & Troncos de Saída/Entrada',
                    'filas' => ($act === 'realtime' ? '<i class="fa-solid fa-users-rays text-purple-400"></i> Monitoramento de Filas Ao Vivo' : ($act === 'flowchart' ? '<i class="fa-solid fa-diagram-project text-cyan-400"></i> Fluxograma Dinâmico de Ligações' : '<i class="fa-solid fa-user-clock text-amber-400"></i> Gestão & Pausa de Agentes')),
                    'relatorios' => ($act === 'filas_stats' ? '<i class="fa-solid fa-chart-line text-rose-400"></i> Relatório Completo de Filas' : '<i class="fa-solid fa-headset text-brand-400"></i> Gravações CDR & Player de Áudio'),
                    'whatsapp' => ($act === 'regras' ? '<i class="fa-solid fa-wand-magic-sparkles text-emerald-400"></i> Regras URA, Transbordo & NPS' : ($act === 'historico' ? '<i class="fa-solid fa-clock-rotate-left text-blue-400"></i> Histórico de Envio & Logs' : '<i class="fa-solid fa-paper-plane text-indigo-400"></i> Disparo Rápido de Teste')),
                    'configuracoes' => ($act === 'ami' ? '<i class="fa-solid fa-plug text-cyan-400"></i> Configuração Asterisk AMI (Click-to-Call)' : ($act === 'ia' ? '<i class="fa-solid fa-robot text-purple-400"></i> Configuração de Inteligência Artificial' : '<i class="fa-solid fa-key text-amber-400"></i> Credenciais API Prismabot & Token'))
                ];
                echo isset($titles[$mod]) ? $titles[$mod] : '<i class="fa-solid fa-layer-group text-brand-400"></i> Painel IPbx Prisma';
                ?>
            </h2>
            <span class="text-[11px] text-slate-400 block font-normal">Plataforma Integrada de Telefonia & WhatsApp</span>
        </div>
    </div>

    <!-- Header Actions -->
    <div class="flex items-center gap-3">
        <!-- Floating WebPhone Trigger (Estilo Pílula Verde do Print) -->
        <button onclick="toggleWebPhone()" class="px-4 py-2 rounded-full bg-emerald-600 hover:bg-emerald-500 text-white font-extrabold text-xs flex items-center gap-2 shadow-lg shadow-emerald-600/30 transition-all hover:scale-105">
            <span class="w-2.5 h-2.5 rounded-full bg-white animate-pulse"></span>
            <i class="fa-solid fa-phone"></i>
            <span>WebPhone</span>
        </button>

        <!-- Sincronizar Ramais do Issabel -->
        <button onclick="syncAsteriskAssets()" title="Sincronizar Ramais e Filas do Issabel" class="p-2 rounded-lg bg-slate-800 text-slate-300 hover:text-white hover:bg-slate-700 transition text-xs flex items-center gap-1.5 border border-slate-700">
            <i class="fa-solid fa-rotate text-brand-400"></i>
            <span class="hidden sm:inline font-bold">Sincronizar</span>
        </button>
    </div>
</header>
