<?php
if (!defined('IPBX_PANEL')) { http_response_code(404); exit; } // acesso direto pela URL e proibido: so o index.php (com login) carrega esta tela
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

        <!-- Localização atual: Seção › Página (sempre igual ao nome do item do menu lateral) -->
        <div>
            <?php
            $mod = isset($_GET['module']) ? $_GET['module'] : 'dashboard';
            $act = isset($_GET['action']) ? $_GET['action'] : 'view';
            $TEL = 'Telefonia & Ramais'; $ATD = 'Atendimento & Filas'; $WAP = 'WhatsApp & Automação'; $REL = 'Relatórios & Gravações'; $CFG = 'Configurações';
            $navMap = [
                'dashboard:view_v1' => [null, 'Dashboard', 'fa-chart-pie'],
                'dashboard:view' => [null, 'Dashboard', 'fa-chart-pie'],
                'ramais:fop' => [$TEL, 'Painel FOP Operador', 'fa-desktop'],
                'ramais:editar_nome' => [$TEL, 'Ramais & Atendentes', 'fa-id-card'],
                'telefonia:blacklist' => [$TEL, 'Blacklist (Bloqueios)', 'fa-ban'],
                'troncos:view' => [$TEL, 'Linhas & Troncos', 'fa-network-wired'],
                'filas:realtime' => [$ATD, 'Filas Ao Vivo', 'fa-users-rays'],
                'filas:flowchart' => [$ATD, 'Fluxograma de Ligações', 'fa-diagram-project'],
                'whatsapp:regras' => [$WAP, 'Regras URA & Transbordo', 'fa-wand-magic-sparkles'],
                'whatsapp:historico' => [$WAP, 'Histórico de Envio & Logs', 'fa-clock-rotate-left'],
                'whatsapp:disparos' => [$WAP, 'Disparo Rápido / Teste', 'fa-paper-plane'],
                'whatsapp:contatos' => [null, 'Agenda & Contatos', 'fa-address-book'],
                'relatorios:relatorio_filas' => [$REL, 'Relatório Completo de Filas', 'fa-list-check'],
                'relatorios:filas_stats' => [$REL, 'Resumo de Filas', 'fa-chart-line'],
                'relatorios:cdr_gravacoes' => [$REL, 'CDR & Gravações', 'fa-headset'],
                'relatorios:graphic_reports' => [$REL, 'Relatórios Gráficos', 'fa-chart-pie'],
                'relatorios:relatorio_geral' => [$REL, 'Relatório Geral & Heatmap', 'fa-fire-flame-curved'],
                'relatorios:pesquisa' => [$REL, 'Pesquisa de Satisfação', 'fa-star'],
                'relatorios:ia_analytics' => [$REL, 'Relatórios de IA', 'fa-wand-magic-sparkles'],
                'configuracoes:api' => [$CFG, 'APIs & Conexões', 'fa-key'],
                'configuracoes:ia' => [$CFG, 'Inteligência Artificial', 'fa-robot'],
                'configuracoes:usuarios' => [$CFG, 'Usuários & Permissões', 'fa-user-gear'],
                'configuracoes:smtp' => [$CFG, 'Servidor SMTP & Disparos', 'fa-envelope-circle-check'],
                'configuracoes:status' => [$CFG, 'Status da Integração', 'fa-heart-pulse'],
                'configuracoes:seguranca' => [$CFG, 'Painel de Segurança', 'fa-shield-halved'],
                'configuracoes:assistente_whatsapp' => [$CFG, 'Assistente WhatsApp', 'fa-robot'],
                'configuracoes:mapa' => [$CFG, 'Mapa do Sistema', 'fa-city'],
                'configuracoes:chat_ia' => [$CFG, 'Conversas do Assistente IA', 'fa-robot'],
                'configuracoes:logs' => [$CFG, 'Logs & Diagnósticos', 'fa-terminal'],
                'configuracoes:console' => [$CFG, 'Console da central', 'fa-code'],
            ];
            $nav = $navMap[$mod . ':' . $act] ?? null;
            ?>
            <h2 class="text-sm font-bold text-white flex items-center gap-2">
                <?php if ($nav): ?>
                    <i class="fa-solid <?php echo $nav[2]; ?> text-brand-400"></i>
                    <?php if ($nav[0]): ?><span class="text-slate-400 font-semibold hidden sm:inline"><?php echo htmlspecialchars($nav[0]); ?></span><i class="fa-solid fa-chevron-right text-[9px] text-slate-600 hidden sm:inline"></i><?php endif; ?>
                    <span><?php echo htmlspecialchars($nav[1]); ?></span>
                <?php else: ?>
                    <i class="fa-solid fa-layer-group text-brand-400"></i> Painel IPbx Prisma
                <?php endif; ?>
            </h2>
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
        <?php else: ?>
        <!-- Webphone ainda não habilitado para este usuário: o ícone aparece e explica como ativar -->
        <button type="button" onclick="showToastNotification('Webphone', 'O Webphone ainda não está habilitado para o seu usuário. Peça ao administrador para vincular um ramal e ativar o Webphone em Configurações > Usuários.', 'info')" title="Webphone (não habilitado)" class="relative w-9 h-9 rounded-lg text-slate-500 hover:text-slate-300 hover:bg-slate-800 transition flex items-center justify-center">
            <i class="fa-solid fa-phone text-[15px]"></i>
        </button>
        <?php endif; ?>

        <!-- Manual: só o ícone (dica ao passar o mouse); abre em página própria numa nova aba -->
        <a href="index.php?module=manual&action=view" target="_blank" rel="opener" title="Manual" aria-label="Manual" class="w-9 h-9 rounded-lg text-slate-300 hover:text-white hover:bg-slate-800 transition flex items-center justify-center">
            <i class="fa-solid fa-book text-[15px]"></i>
        </a>
    </div>
</header>
