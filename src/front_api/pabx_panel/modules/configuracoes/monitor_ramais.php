<?php
if (!defined('IPBX_PANEL')) { http_response_code(404); exit; } // acesso direto pela URL e proibido: so o index.php (com login) carrega esta tela
/** IPbx Prisma - Monitor de Ramais e Conexões (somente leitura). Tela técnica: online/offline, IP, aparelho, MAC, oscilação e senha errada. */
$__nomes = [];
try { foreach ($db->query("SELECT extension, agent_name FROM extensions_config") as $__r) $__nomes[(string)$__r['extension']] = (string)$__r['agent_name']; } catch (Throwable $e) {}
$__m = monitorRamaisColetar($__nomes);
$__rows = $__m['ramais'];
$__on = $__off = $__osc = $__pw = 0;
foreach ($__rows as $r) { if ($r['online']) $__on++; else $__off++; if ($r['oscila']) $__osc++; if ($r['senha_errada'] >= 3) $__pw++; }
$__filtro = $_GET['f'] ?? '';
if (!in_array($__filtro, ['', 'online', 'offline', 'oscila', 'senha'], true)) $__filtro = '';
$__mostra = array_values(array_filter($__rows, function ($r) use ($__filtro) {
    switch ($__filtro) {
        case 'online': return $r['online'];
        case 'offline': return !$r['online'];
        case 'oscila': return $r['oscila'];
        case 'senha': return $r['senha_errada'] >= 3;
    }
    return true;
}));
$__chip = function ($f, $label, $n, $cls) use ($__filtro) {
    $act = $__filtro === $f ? ' ring-2 ring-brand-500' : '';
    return '<a href="index.php?module=configuracoes&action=monitor_ramais' . ($f ? '&f=' . $f : '') . '" class="px-2.5 py-1 rounded-lg border font-bold ' . $cls . $act . '" style="white-space:nowrap">' . htmlspecialchars($label) . ': ' . (int)$n . '</a>';
};
?>
<div class="space-y-5">
    <div class="bg-slate-900/90 border border-slate-800 rounded-2xl p-5 flex flex-col md:flex-row md:items-center justify-between gap-3">
        <div>
            <h2 class="text-lg font-extrabold text-white flex items-center gap-2"><i class="fa-solid fa-tower-broadcast text-sky-400"></i> Monitor de Ramais e Conexões</h2>
            <p class="text-xs text-slate-400 mt-1">Quem está online, de qual IP e aparelho, quem fica caindo e quem está errando a senha. Somente leitura: nada é alterado na central.</p>
        </div>
        <div class="flex items-center gap-2 text-xs flex-wrap">
            <?php echo $__chip('', 'Todos', count($__rows), 'bg-slate-700/40 text-slate-200 border-slate-600/40'); ?>
            <?php echo $__chip('online', 'Online', $__on, 'bg-emerald-500/20 text-emerald-300 border-emerald-500/30'); ?>
            <?php echo $__chip('offline', 'Offline', $__off, 'bg-slate-700/40 text-slate-300 border-slate-600/40'); ?>
            <?php echo $__chip('oscila', 'Oscilando', $__osc, 'bg-amber-500/20 text-amber-300 border-amber-500/30'); ?>
            <?php echo $__chip('senha', 'Senha errada', $__pw, 'bg-rose-500/20 text-rose-300 border-rose-500/30'); ?>
        </div>
    </div>
    <?php foreach ($__m['avisos'] as $av): ?>
    <div class="p-3 rounded-xl bg-amber-500/10 text-amber-300 border border-amber-500/20 text-xs font-bold"><i class="fa-solid fa-triangle-exclamation mr-1"></i> <?php echo htmlspecialchars($av); ?></div>
    <?php endforeach; ?>
    <div class="bg-slate-900/90 border border-slate-800 rounded-2xl overflow-hidden">
        <div class="overflow-x-auto">
        <table class="w-full text-xs">
            <thead class="text-[10px] uppercase tracking-wider text-slate-400 border-b border-slate-800">
                <tr>
                    <th class="text-left px-4 py-3">Ramal</th>
                    <th class="text-left px-3 py-3">Estado</th>
                    <th class="text-left px-3 py-3">IP</th>
                    <th class="text-left px-3 py-3">Aparelho</th>
                    <th class="text-left px-3 py-3">MAC</th>
                    <th class="text-left px-3 py-3">Resposta</th>
                    <th class="text-left px-3 py-3">Quedas (24h)</th>
                    <th class="text-left px-3 py-3">Senha errada</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-slate-800">
            <?php foreach ($__mostra as $r): ?>
                <tr class="hover:bg-slate-800/40">
                    <td class="px-4 py-2.5"><div class="font-bold text-slate-100"><?php echo htmlspecialchars($r['ramal']); ?></div><div class="text-slate-400"><?php echo htmlspecialchars($r['nome'] !== '' ? $r['nome'] : 'Sem nome'); ?></div></td>
                    <td class="px-3 py-2.5">
                        <?php if ($r['oscila']): ?><span class="px-2 py-0.5 rounded border font-bold text-[10px] bg-amber-500/20 text-amber-300 border-amber-500/30">Oscilando</span>
                        <?php elseif ($r['online']): ?><span class="px-2 py-0.5 rounded border font-bold text-[10px] bg-emerald-500/20 text-emerald-300 border-emerald-500/30">Online</span>
                        <?php else: ?><span class="px-2 py-0.5 rounded border font-bold text-[10px] bg-slate-700/40 text-slate-300 border-slate-600/40"><?php echo $r['registrado'] ? 'Sem resposta' : 'Offline'; ?></span><?php endif; ?>
                    </td>
                    <td class="px-3 py-2.5 text-slate-300"><?php echo $r['ip'] !== '' ? htmlspecialchars($r['ip'] . ($r['porta'] !== '' ? ':' . $r['porta'] : '')) : '<span class="text-slate-500">Não registrado</span>'; ?></td>
                    <td class="px-3 py-2.5 text-slate-300 max-w-[220px] truncate" title="<?php echo htmlspecialchars($r['aparelho']); ?>"><?php echo $r['aparelho'] !== '' ? htmlspecialchars($r['aparelho']) : '<span class="text-slate-500">Não informado</span>'; ?></td>
                    <td class="px-3 py-2.5 text-slate-300"><?php echo $r['mac'] !== '' ? htmlspecialchars($r['mac']) : '<span class="text-slate-500" title="Só aparece quando o próprio aparelho informa o MAC à central">Não informado</span>'; ?></td>
                    <td class="px-3 py-2.5 text-slate-300"><?php echo $r['rtt'] !== null ? htmlspecialchars(number_format($r['rtt'], 0)) . ' ms' : '<span class="text-slate-500">—</span>'; ?></td>
                    <td class="px-3 py-2.5"><?php if ($r['quedas'] > 0): ?><span class="<?php echo $r['oscila'] ? 'text-amber-300 font-bold' : 'text-slate-300'; ?>"><?php echo (int)$r['quedas']; ?>x</span><?php if ($r['ultima_queda'] !== ''): ?><span class="text-slate-500"> (última <?php echo htmlspecialchars($r['ultima_queda']); ?>)</span><?php endif; ?><?php else: ?><span class="text-slate-500">Nenhuma</span><?php endif; ?></td>
                    <td class="px-3 py-2.5"><?php echo $r['senha_errada'] > 0 ? '<span class="' . ($r['senha_errada'] >= 3 ? 'text-rose-300 font-bold' : 'text-slate-300') . '">' . (int)$r['senha_errada'] . ' tentativa(s)</span>' : '<span class="text-slate-500">Nenhuma</span>'; ?></td>
                </tr>
            <?php endforeach; ?>
            <?php if (!$__mostra): ?>
                <tr><td colspan="8" class="px-4 py-8 text-center text-slate-400">Nenhum ramal neste filtro.</td></tr>
            <?php endif; ?>
            </tbody>
        </table>
        </div>
    </div>
    <p class="text-[11px] text-slate-500">"Oscilando" = ramal que caiu e voltou 3 vezes ou mais nas últimas 24 h (costuma ser rede instável, roteador ou aparelho). Muitas tentativas de senha errada em um ramal podem indicar ataque: veja o Painel de Segurança.<?php if ($__m['desde'] !== ''): ?> Registro de eventos lido a partir de <?php echo htmlspecialchars($__m['desde']); ?>.<?php endif; ?></p>
</div>
