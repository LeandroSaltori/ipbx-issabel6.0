<?php
/** IPbx Prisma - Painel de Segurança (somente leitura): tentativas de invasão e bloqueios do fail2ban. */
$__s = securityOverview();
?>
<div class="space-y-5">
    <div class="bg-slate-900/90 border border-slate-800 rounded-2xl p-5 flex flex-col md:flex-row md:items-center justify-between gap-3">
        <div>
            <h2 class="text-lg font-extrabold text-white flex items-center gap-2"><i class="fa-solid fa-shield-halved text-rose-400"></i> Painel de Segurança</h2>
            <p class="text-xs text-slate-400 mt-1">Tentativas de acesso indevido registradas pelo Asterisk e IPs bloqueados pelo fail2ban. Somente leitura.</p>
        </div>
        <div class="flex items-center gap-2 text-xs flex-wrap">
            <span class="px-2.5 py-1 rounded-lg border font-bold bg-amber-500/20 text-amber-300 border-amber-500/30"><?php echo count($__s['tentativas']); ?> tentativas recentes</span>
            <span class="px-2.5 py-1 rounded-lg border font-bold bg-rose-500/20 text-rose-300 border-rose-500/30"><?php echo count($__s['banidos_ativos']); ?> IPs bloqueados</span>
            <a href="index.php?module=configuracoes&action=seguranca" class="px-3 py-1.5 rounded-lg bg-brand-600 hover:bg-brand-500 text-white font-bold" style="white-space:nowrap;flex-shrink:0;width:auto;overflow:visible"><i class="fa-solid fa-rotate"></i> Atualizar</a>
        </div>
    </div>
    <?php foreach ($__s['avisos'] as $av): ?>
    <div class="bg-amber-500/10 border border-amber-500/30 text-amber-200 text-xs rounded-xl px-4 py-3"><i class="fa-solid fa-triangle-exclamation"></i> <?php echo htmlspecialchars($av); ?></div>
    <?php endforeach; ?>
    <div class="grid grid-cols-1 lg:grid-cols-2 gap-5">
        <div class="bg-slate-900/90 border border-slate-800 rounded-2xl overflow-hidden">
            <div class="px-4 py-3 text-xs font-extrabold text-slate-300 uppercase tracking-wider border-b border-slate-800">IPs que mais tentaram acessar</div>
            <?php if (!$__s['por_ip']): ?><div class="px-4 py-4 text-xs text-slate-500">Nenhuma tentativa registrada.</div><?php endif; ?>
            <div class="divide-y divide-slate-800">
            <?php foreach ($__s['por_ip'] as $ip => $n): ?>
                <div class="px-4 py-2 flex justify-between text-xs"><span class="font-mono text-slate-100"><?php echo htmlspecialchars($ip); ?></span><span class="text-amber-300 font-bold"><?php echo (int)$n; ?> tentativas</span></div>
            <?php endforeach; ?>
            </div>
        </div>
        <div class="bg-slate-900/90 border border-slate-800 rounded-2xl overflow-hidden">
            <div class="px-4 py-3 text-xs font-extrabold text-slate-300 uppercase tracking-wider border-b border-slate-800">Bloqueados agora (fail2ban)</div>
            <?php if (!$__s['banidos_ativos']): ?><div class="px-4 py-4 text-xs text-slate-500">Nenhum IP bloqueado.</div><?php endif; ?>
            <div class="divide-y divide-slate-800">
            <?php foreach ($__s['banidos_ativos'] as $b): ?>
                <div class="px-4 py-2 flex justify-between text-xs"><span class="font-mono text-slate-100"><?php echo htmlspecialchars($b['ip']); ?></span><span class="text-slate-400"><?php echo htmlspecialchars($b['jail'] . ' · ' . $b['quando']); ?></span></div>
            <?php endforeach; ?>
            </div>
        </div>
    </div>
    <div class="bg-slate-900/90 border border-slate-800 rounded-2xl overflow-hidden">
        <div class="px-4 py-3 text-xs font-extrabold text-slate-300 uppercase tracking-wider border-b border-slate-800">Últimas tentativas</div>
        <div class="overflow-x-auto"><table class="w-full text-xs">
            <thead><tr class="text-slate-400 text-left"><th class="px-4 py-2">Quando</th><th class="px-4 py-2">Evento</th><th class="px-4 py-2">IP</th><th class="px-4 py-2">Conta</th></tr></thead>
            <tbody class="divide-y divide-slate-800">
            <?php foreach ($__s['tentativas'] as $t): ?>
                <tr class="text-slate-200"><td class="px-4 py-2"><?php echo htmlspecialchars($t['quando']); ?></td><td class="px-4 py-2"><?php echo htmlspecialchars($t['evento']); ?></td><td class="px-4 py-2 font-mono"><?php echo htmlspecialchars($t['ip']); ?></td><td class="px-4 py-2"><?php echo htmlspecialchars($t['conta']); ?></td></tr>
            <?php endforeach; ?>
            </tbody></table></div>
    </div>
</div>
