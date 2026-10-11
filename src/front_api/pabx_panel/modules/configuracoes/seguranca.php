<?php
/** IPbx Prisma - Painel de Segurança (somente leitura): tentativas de invasão e bloqueios do fail2ban. */
$__can = hasUserPermission('manage_settings');
$__msg = ''; $__err = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $__can) {
    $a = $_POST['sec_action'] ?? '';
    if ($a === 'wl_add' || $a === 'wl_del') {
        $ip = trim($_POST['ip'] ?? '');
        if (!f2bIpValid($ip)) { $__err = 'IP inválido.'; }
        else {
            $cur = f2bWhitelistGet();
            $cur = ($a === 'wl_add') ? array_unique(array_merge($cur, [$ip])) : array_values(array_diff($cur, [$ip]));
            $r = f2bWhitelistSave($cur);
            if ($r['ok']) $__msg = $r['msg']; else $__err = $r['msg'];
            logUserAction('Whitelist Fail2ban', ($a === 'wl_add' ? 'add ' : 'del ') . $ip, 'CONFIG');
        }
    } elseif ($a === 'unban') {
        if (f2bUnban($_POST['jail'] ?? '', $_POST['ip'] ?? '')) { $__msg = 'IP desbloqueado.'; logUserAction('Unban Fail2ban', ($_POST['ip'] ?? ''), 'CONFIG'); }
        else $__err = 'Não foi possível desbloquear.';
    }
}
$__s = securityOverview();
$__jails = f2bNativeJails();
$__wl = f2bWhitelistGet();
$__native = f2bBannedNative();
if (is_array($__native)) $__s['banidos_ativos'] = $__native;
$__btn = 'style="white-space:nowrap;flex-shrink:0;width:auto;overflow:visible"';
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
    <?php if ($__msg): ?><div class="bg-emerald-500/10 border border-emerald-500/30 text-emerald-300 text-xs rounded-xl px-4 py-3"><?php echo htmlspecialchars($__msg); ?></div><?php endif; ?>
    <?php if ($__err): ?><div class="bg-rose-500/10 border border-rose-500/30 text-rose-300 text-xs rounded-xl px-4 py-3"><?php echo htmlspecialchars($__err); ?></div><?php endif; ?>
    <div class="bg-slate-900/90 border border-slate-800 rounded-2xl p-5 text-xs space-y-3">
        <div class="font-extrabold text-slate-300 uppercase tracking-wider">Whitelist (IPs que nunca são bloqueados)</div>
        <p class="text-slate-400">Sincronizada com o Fail2ban nativo do Issabel (Security &gt; Fail2ban): o que for salvo aqui aparece lá, e vice-versa.</p>
        <div class="flex flex-wrap gap-2">
            <?php foreach ($__wl as $w): ?>
            <form method="post" class="inline-flex items-center gap-2 px-2.5 py-1 rounded-lg border border-slate-600 text-slate-100"><span class="font-mono"><?php echo htmlspecialchars($w); ?></span>
                <?php if ($__can): ?><input type="hidden" name="sec_action" value="wl_del"><input type="hidden" name="ip" value="<?php echo htmlspecialchars($w); ?>"><button class="text-rose-400 font-bold" title="Remover">&times;</button><?php endif; ?></form>
            <?php endforeach; ?>
            <?php if (!$__wl): ?><span class="text-slate-500">Nenhum IP na whitelist (além do localhost).</span><?php endif; ?>
        </div>
        <?php if ($__can): ?>
        <form method="post" class="flex gap-2 items-end"><input type="hidden" name="sec_action" value="wl_add">
            <input name="ip" placeholder="ex.: 200.10.20.30 ou 192.168.0.0/24" class="bg-slate-950 border border-slate-700 rounded-lg px-3 py-2 text-slate-100 w-72">
            <button class="px-4 py-2 rounded-lg bg-brand-600 hover:bg-brand-500 text-white font-bold" <?php echo $__btn; ?>><i class="fa-solid fa-plus"></i> Adicionar</button></form>
        <?php endif; ?>
        <?php if ($__jails): ?>
        <div class="pt-2 text-slate-400">Jails ativos no Issabel: <?php foreach ($__jails as $j) echo '<span class="inline-block mr-2 px-2 py-0.5 rounded border border-slate-700 ' . ($j['enabled'] ? 'text-emerald-300' : 'text-slate-500') . '">' . htmlspecialchars($j['name']) . ' (' . (int)$j['maxretry'] . ' falhas / ' . (int)$j['bantime'] . 'h)</span>'; ?></div>
        <?php else: ?><div class="pt-2 text-amber-300">Jails do Issabel não encontrados em /var/www/db/iptables.db: whitelist indisponível neste servidor.</div><?php endif; ?>
    </div>
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
                <div class="px-4 py-2 flex justify-between text-xs"><span class="font-mono text-slate-100"><?php echo htmlspecialchars($b['ip']); ?></span><span class="text-slate-400 flex items-center gap-2"><?php echo htmlspecialchars($b['jail'] . (isset($b['quando']) ? ' · ' . $b['quando'] : '')); ?>
                    <?php if ($__can && is_array($__native)): ?><form method="post" class="inline"><input type="hidden" name="sec_action" value="unban"><input type="hidden" name="jail" value="<?php echo htmlspecialchars($b['jail']); ?>"><input type="hidden" name="ip" value="<?php echo htmlspecialchars($b['ip']); ?>"><button class="px-2 py-0.5 rounded bg-slate-700 hover:bg-slate-600 text-white font-bold" <?php echo $__btn; ?>>Desbloquear</button></form><?php endif; ?></span></div>
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
