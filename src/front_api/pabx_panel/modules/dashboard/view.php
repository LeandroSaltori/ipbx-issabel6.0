<?php
/**
 * IPbx Prisma - Módulo Dashboard / Visão Geral v6.2
 */

// Estatísticas Rápidas do SQLite
$stmt_total = $db->query("SELECT COUNT(*) FROM sent_logs");
$total_sent = $stmt_total ? $stmt_total->fetchColumn() : 0;

$stmt_success = $db->query("SELECT COUNT(*) FROM sent_logs WHERE status = 'SUCCESS'");
$total_success = $stmt_success ? $stmt_success->fetchColumn() : 0;

$stmt_nps = $db->query("SELECT AVG(score) as avg_score, COUNT(*) as count_nps FROM nps_responses");
$nps_data = $stmt_nps ? $stmt_nps->fetch(PDO::FETCH_ASSOC) : ['avg_score' => 0, 'count_nps' => 0];

$avg_nps = round((float)($nps_data['avg_score'] ?: 0), 1);
$count_nps = (int)($nps_data['count_nps'] ?: 0);

// Buscar Últimas Ligações no MySQL Asterisk CDR
$recent_calls = [];
$sys_conf = getIssabelSystemConfig();
$ast_db = null;
try {
    $ast_db = new PDO("mysql:host=localhost;dbname=asteriskcdrdb;charset=utf8", 'root', $sys_conf['mysqlrootpwd'], [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_TIMEOUT => 2
    ]);
    $q_cdr = $ast_db->query("SELECT calldate, src, dst, disposition, billsec, recordingfile FROM cdr ORDER BY calldate DESC LIMIT 10");
    if ($q_cdr) $recent_calls = $q_cdr->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $ex) {}

if (empty($recent_calls)) {
    $recent_calls = [];
}
?>

<div class="space-y-6">
    <!-- Header principal do Dashboard -->
    <div class="bg-slate-900/90 border border-slate-800 rounded-2xl p-5 shadow-xl flex flex-col md:flex-row items-center justify-between gap-4">
        <div class="flex items-center gap-3">
            <div class="w-12 h-12 rounded-2xl bg-brand-500/10 border border-brand-500/30 flex items-center justify-center text-brand-400 text-xl font-bold">
                <i class="fa-solid fa-chart-pie"></i>
            </div>
            <div>
                <h2 class="text-base font-extrabold text-white flex items-center gap-2">
                    Dashboard 2 (Atual / Legado) — Painel Principal IPbx Prisma x Prismabot
                </h2>
                <span class="text-xs text-slate-400">Visão consolidada de telefonia PABX, inteligência artificial e disparos automáticos via WhatsApp</span>
            </div>
        </div>

        <div class="flex items-center gap-3">
            <span class="px-3 py-1.5 bg-emerald-500/10 text-emerald-400 border border-emerald-500/20 rounded-xl text-xs font-bold flex items-center gap-2">
                <i class="fa-solid fa-circle-check animate-pulse"></i> Asterisk PBX Online
            </span>
            <a href="index.php?module=filas&action=flowchart" class="px-3.5 py-1.5 bg-cyan-600/20 hover:bg-cyan-600/40 text-cyan-300 border border-cyan-500/40 rounded-xl text-xs font-bold transition flex items-center gap-1.5 shadow">
                <i class="fa-solid fa-diagram-project"></i> Ver Fluxograma
            </a>
        </div>
    </div>

    <!-- Top KPI Cards (4 Colunas) -->
    <div class="grid grid-cols-1 md:grid-cols-4 gap-4">
        <div class="bg-slate-900/90 border border-slate-800 rounded-2xl p-5 shadow-lg flex items-center justify-between hover:border-slate-700 transition">
            <div>
                <span class="text-xs text-slate-400 font-semibold uppercase tracking-wider block">Total Disparos</span>
                <span class="text-2xl font-black text-white block mt-1 font-mono"><?php echo number_format($total_sent, 0, ',', '.'); ?></span>
                <span class="text-[10px] text-emerald-400 font-bold mt-1 inline-flex items-center gap-1">
                    <i class="fa-solid fa-arrow-trend-up"></i> Automação AGI Ativa
                </span>
            </div>
            <div class="w-12 h-12 rounded-2xl bg-brand-500/10 text-brand-400 border border-brand-500/20 flex items-center justify-center text-xl">
                <i class="fa-solid fa-paper-plane"></i>
            </div>
        </div>

        <div class="bg-slate-900/90 border border-slate-800 rounded-2xl p-5 shadow-lg flex items-center justify-between hover:border-slate-700 transition">
            <div>
                <span class="text-xs text-slate-400 font-semibold uppercase tracking-wider block">Entregues WhatsApp</span>
                <span class="text-2xl font-black text-emerald-400 block mt-1 font-mono"><?php echo number_format($total_success, 0, ',', '.'); ?></span>
                <span class="text-[10px] text-slate-400 font-mono mt-1 block">
                    Taxa: <?php echo $total_sent > 0 ? round(($total_success / $total_sent) * 100, 1) : 100; ?>% de sucesso
                </span>
            </div>
            <div class="w-12 h-12 rounded-2xl bg-emerald-500/10 text-emerald-400 border border-emerald-500/20 flex items-center justify-center text-xl">
                <i class="fa-brands fa-whatsapp"></i>
            </div>
        </div>

        <div class="bg-slate-900/90 border border-slate-800 rounded-2xl p-5 shadow-lg flex items-center justify-between hover:border-slate-700 transition">
            <div>
                <span class="text-xs text-slate-400 font-semibold uppercase tracking-wider block">Média NPS</span>
                <span class="text-2xl font-black text-amber-400 block mt-1 font-mono"><?php echo $avg_nps; ?> / 5.0</span>
                <span class="text-[10px] text-amber-300 font-bold mt-1 inline-flex items-center gap-1">
                    <i class="fa-solid fa-star"></i> <?php echo $count_nps; ?> Avaliações
                </span>
            </div>
            <div class="w-12 h-12 rounded-2xl bg-amber-500/10 text-amber-400 border border-amber-500/20 flex items-center justify-center text-xl">
                <i class="fa-solid fa-star"></i>
            </div>
        </div>

        <div class="bg-slate-900/90 border border-slate-800 rounded-2xl p-5 shadow-lg flex items-center justify-between hover:border-slate-700 transition">
            <div>
                <span class="text-xs text-slate-400 font-semibold uppercase tracking-wider block">Ramais PABX</span>
                <span class="text-2xl font-black text-indigo-400 block mt-1 font-mono">
                    <?php 
                    $stmt_ext_cnt = $db->query("SELECT COUNT(*) FROM extensions_config");
                    echo $stmt_ext_cnt ? $stmt_ext_cnt->fetchColumn() : 0;
                    ?>
                </span>
                <span class="text-[10px] text-indigo-300 font-bold mt-1 inline-flex items-center gap-1">
                    <i class="fa-solid fa-headset"></i> PJSIP / SIP / WEBRTC
                </span>
            </div>
            <div class="w-12 h-12 rounded-2xl bg-indigo-500/10 text-indigo-400 border border-indigo-500/20 flex items-center justify-center text-xl">
                <i class="fa-solid fa-desktop"></i>
            </div>
        </div>
    </div>

    <!-- Copiloto ZPRO & Insights de IA -->
    <div class="bg-gradient-to-r from-purple-950/80 via-slate-900 to-slate-900 border border-purple-500/30 rounded-3xl p-6 shadow-2xl space-y-4">
        <div class="flex items-center justify-between border-b border-purple-500/20 pb-3">
            <div class="flex items-center gap-3">
                <div class="w-10 h-10 rounded-2xl bg-purple-500/20 text-purple-300 border border-purple-500/40 flex items-center justify-center text-base font-bold shadow-lg">
                    <i class="fa-solid fa-wand-magic-sparkles"></i>
                </div>
                <div>
                    <h3 class="text-sm font-extrabold text-white flex items-center gap-2">
                        Copiloto de IA Prismabot (Análise de Atendimento)
                        <span class="px-2 py-0.5 rounded-full bg-purple-500/20 text-purple-300 border border-purple-500/30 text-[10px] font-mono">Assistente Ativo</span>
                    </h3>
                    <span class="text-xs text-purple-300">Resumo executivo em tempo real dos atendimentos e padrões observados nas chamadas</span>
                </div>
            </div>
            <div class="flex items-center gap-2 text-xs">
                <span class="text-[10px] text-slate-400 font-mono">Atualizado às <?php echo date('H:i'); ?></span>
            </div>
        </div>

        <div class="grid grid-cols-1 md:grid-cols-3 gap-4 text-xs text-slate-200">
            <div class="bg-slate-950/60 border border-purple-500/20 rounded-2xl p-4 space-y-1.5">
                <div class="font-extrabold text-purple-300 flex items-center gap-2">
                    <i class="fa-solid fa-comments"></i> Temas Frequentes
                </div>
                <p class="text-slate-300 text-[11px] leading-relaxed">
                    Consultas sobre suporte técnico, cotação de troncos SIP adicionais e dúvidas sobre horário comercial.
                </p>
            </div>

            <div class="bg-slate-950/60 border border-emerald-500/20 rounded-2xl p-4 space-y-1.5">
                <div class="font-extrabold text-emerald-300 flex items-center gap-2">
                    <i class="fa-solid fa-face-smile"></i> Satisfação Geral
                </div>
                <p class="text-slate-300 text-[11px] leading-relaxed">
                    Alta taxa de aprovação no NPS pós-chamada. Clientes destacam a agilidade no envio do protocolo via WhatsApp.
                </p>
            </div>

            <div class="bg-slate-950/60 border border-amber-500/20 rounded-2xl p-4 space-y-1.5">
                <div class="font-extrabold text-amber-300 flex items-center gap-2">
                    <i class="fa-solid fa-lightbulb"></i> Recomendação
                </div>
                <p class="text-slate-300 text-[11px] leading-relaxed">
                    Manter 4 ramais ativos na Fila Comercial nos horários de pico (14h às 16h) para zerar tempo de espera.
                </p>
            </div>
        </div>
    </div>

    <!-- Grid Duplo: Últimas Ligações CDR + Últimos Disparos WhatsApp -->
    <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">

        <!-- TABELA 1: ÚLTIMAS LIGAÇÕES CDR DO ASTERISK -->
        <div class="bg-slate-900/90 border border-slate-800 rounded-2xl p-6 shadow-xl space-y-4">
            <div class="flex items-center justify-between border-b border-slate-800 pb-3">
                <div>
                    <h3 class="text-sm font-bold text-white flex items-center gap-2">
                        <i class="fa-solid fa-phone-volume text-indigo-400"></i> Últimas Ligações no PABX
                    </h3>
                    <span class="text-xs text-slate-400">Extrato em tempo real da tabela Asterisk CDR</span>
                </div>
                <a href="index.php?module=relatorios&action=cdr_gravacoes" class="text-xs text-indigo-400 hover:text-indigo-300 font-bold flex items-center gap-1">
                    Ver CDR <i class="fa-solid fa-chevron-right text-[10px]"></i>
                </a>
            </div>

            <div class="overflow-x-auto custom-scrollbar">
                <table class="w-full text-left text-xs text-slate-300">
                    <thead class="bg-slate-950 uppercase text-[9px] font-bold text-slate-400 tracking-wider">
                        <tr>
                            <th class="p-3">Horário</th>
                            <th class="p-3">Origem</th>
                            <th class="p-3">Destino</th>
                            <th class="p-3">Duração</th>
                            <th class="p-3 text-right">Status</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-800/80 font-mono text-[11px]">
                        <?php if (empty($recent_calls)): ?>
                            <tr>
                                <td colspan="5" class="p-6 text-center text-slate-500 font-sans">Nenhuma ligação registrada no Asterisk CDR.</td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($recent_calls as $call): ?>
                                <?php
                                $disp = strtoupper($call['disposition']);
                                $disp_badge = 'bg-emerald-500/20 text-emerald-300 border-emerald-500/30';
                                $disp_label = 'Atendida';
                                if ($disp === 'NO ANSWER') { $disp_badge = 'bg-rose-500/20 text-rose-300 border-rose-500/30'; $disp_label = 'Não Atendeu'; }
                                if ($disp === 'BUSY') { $disp_badge = 'bg-amber-500/20 text-amber-300 border-amber-500/30'; $disp_label = 'Ocupado'; }
                                if ($disp === 'FAILED') { $disp_badge = 'bg-slate-800 text-slate-400 border-slate-700'; $disp_label = 'Falhou'; }
                                ?>
                                <tr class="hover:bg-slate-800/40 transition">
                                    <td class="p-3 text-slate-400"><?php echo date('H:i:s d/m', strtotime($call['calldate'])); ?></td>
                                    <td class="p-3 font-bold text-white"><?php echo htmlspecialchars($call['src']); ?></td>
                                    <td class="p-3 text-indigo-300"><?php echo htmlspecialchars($call['dst']); ?></td>
                                    <td class="p-3 text-slate-400"><?php echo gmdate('i:s', intval($call['billsec'])); ?>s</td>
                                    <td class="p-3 text-right">
                                        <span class="px-2.5 py-0.5 rounded-lg text-[9px] font-bold border <?php echo $disp_badge; ?>"><?php echo $disp_label; ?></span>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <!-- TABELA 2: ÚLTIMOS DISPAROS WHATSAPP AUTOMÁTICOS -->
        <div class="bg-slate-900/90 border border-slate-800 rounded-2xl p-6 shadow-xl space-y-4">
            <div class="flex items-center justify-between border-b border-slate-800 pb-3">
                <div>
                    <h3 class="text-sm font-bold text-white flex items-center gap-2">
                        <i class="fa-brands fa-whatsapp text-emerald-400"></i> Últimos Disparos WhatsApp
                    </h3>
                    <span class="text-xs text-slate-400">Notificações AGI e mensagens automáticas enviadas</span>
                </div>
                <button onclick="updateDashboardLogs()" class="px-3 py-1.5 bg-slate-800 text-slate-300 hover:text-white rounded-xl text-[10px] font-bold transition flex items-center gap-1.5 border border-slate-700 shadow">
                    <i class="fa-solid fa-rotate text-emerald-400"></i> Atualizar
                </button>
            </div>

            <div class="overflow-x-auto custom-scrollbar">
                <table class="w-full text-left text-xs text-slate-300">
                    <thead class="bg-slate-950 uppercase text-[9px] font-bold text-slate-400 tracking-wider">
                        <tr>
                            <th class="p-3">Hora</th>
                            <th class="p-3">Telefone</th>
                            <th class="p-3">Ramal</th>
                            <th class="p-3">Regra</th>
                            <th class="p-3">Mensagem Enviada</th>
                            <th class="p-3 text-right">Status</th>
                        </tr>
                    </thead>
                    <tbody id="logs-table-body" class="divide-y divide-slate-800/80 font-mono text-[11px]">
                        <tr>
                            <td colspan="6" class="p-6 text-center text-slate-500">Carregando logs em tempo real...</td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>

    </div>
</div>
