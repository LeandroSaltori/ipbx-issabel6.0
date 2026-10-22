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
                    'relatorios' => ($act === 'ia_analytics' ? '<i class="fa-solid fa-sparkles text-purple-400"></i> Relatório & Auditoria de Inteligência Artificial' : ($act === 'filas_stats' ? '<i class="fa-solid fa-chart-line text-rose-400"></i> Relatório Completo de Filas' : ($act === 'graphic_reports' ? '<i class="fa-solid fa-chart-pie text-cyan-400"></i> Relatórios Gráficos (Ramais, Filas e Troncos)' : ($act === 'pesquisa' ? '<i class="fa-solid fa-star text-amber-400"></i> Pesquisa de Satisfação (Notas dos Clientes)' : ($act === 'relatorio_geral' ? '<i class="fa-solid fa-fire-flame-curved text-amber-400"></i> Relatório Geral & Mapa de Calor' : '<i class="fa-solid fa-headset text-brand-400"></i> Gravações CDR & Player de Áudio'))))),
                    'whatsapp' => ($act === 'regras' ? '<i class="fa-solid fa-wand-magic-sparkles text-emerald-400"></i> Regras URA & Transbordo' : ($act === 'historico' ? '<i class="fa-solid fa-clock-rotate-left text-blue-400"></i> Histórico de Envio & Logs' : ($act === 'contatos' ? '<i class="fa-solid fa-address-book text-emerald-400"></i> Agenda de Contatos & Sincronização Prismabot' : '<i class="fa-solid fa-paper-plane text-indigo-400"></i> Disparo Rápido de Teste'))),
                    'configuracoes' => ($act === 'ia' ? '<i class="fa-solid fa-robot text-purple-400"></i> Configuração de Inteligência Artificial' : ($act === 'usuarios' ? '<i class="fa-solid fa-user-gear text-rose-400"></i> Gestão de Usuários & Permissões' : '<i class="fa-solid fa-key text-cyan-400"></i> APIs & Conexões (REST PABX + Prismabot)'))
                ];
                echo isset($titles[$mod]) ? $titles[$mod] : '<i class="fa-solid fa-layer-group text-brand-400"></i> Painel IPbx Prisma';
                ?>
                <span class="px-2 py-0.5 rounded-full bg-brand-500/20 text-brand-300 border border-brand-500/30 text-[10px] font-extrabold ml-1">v6.1</span>
            </h2>
            <span class="text-[11px] text-slate-400 block font-normal">Plataforma Integrada de Telefonia PABX Prisma & WhatsApp</span>
        </div>
    </div>

    <!-- Header Actions -->
    <div class="flex items-center gap-3">
        <?php if (function_exists('hasUserPermission') && hasUserPermission('send_whatsapp')): ?>
        <!-- Enviar mensagem de WhatsApp (Prisma API ou UAZAPI, conforme Configurações > API) -->
        <button type="button" id="btn-nova-conversa" onclick="openNovaConversa()" title="Enviar mensagem de WhatsApp" class="w-9 h-9 rounded-lg text-slate-300 hover:text-white hover:bg-slate-800 transition flex items-center justify-center">
            <i class="fa-regular fa-paper-plane text-[15px]"></i>
        </button>
        <?php endif; ?>

        <?php $__u_wp = function_exists('getLoggedUser') ? getLoggedUser() : null; if ($__u_wp && userHasWebphone($__u_wp)): ?>
        <!-- Webphone: ícone de telefone; a bolinha mostra o estado do ramal (verde = registrado) -->
        <button type="button" id="webphone-pill" onclick="toggleWebPhone()" title="Webphone" class="relative w-9 h-9 rounded-lg text-slate-300 hover:text-white hover:bg-slate-800 transition flex items-center justify-center">
            <i class="fa-solid fa-phone text-[15px]"></i>
            <span id="webphone-pill-dot" class="absolute top-1 right-1 w-2.5 h-2.5 rounded-full animate-pulse" style="background:#94a3b8;box-shadow:0 0 0 2px #0f172a"></span>
        </button>
        <?php endif; ?>

        <!-- Sincronizar Ramais do PABX -->
        <button onclick="syncAsteriskAssets()" title="Sincronizar Ramais e Filas do PABX" class="p-2 rounded-lg bg-slate-800 text-slate-300 hover:text-white hover:bg-slate-700 transition text-xs flex items-center gap-1.5 border border-slate-700">
            <i class="fa-solid fa-rotate text-brand-400"></i>
            <span class="hidden sm:inline font-bold">Sincronizar</span>
        </button>
    </div>
</header>
