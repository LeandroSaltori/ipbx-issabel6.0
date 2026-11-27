<?php
if (!defined('IPBX_PANEL')) { http_response_code(404); exit; } // acesso direto pela URL e proibido: so o index.php (com login) carrega esta tela
/** IPbx Prisma - Grupos de Chamada: espelhado com a central (mesma tabela da tela nativa). Criar, editar, excluir e desfazer. */
$__msg = ''; $__err = '';
$__c = rgConn();
$__nomes = [];
try { foreach ($db->query("SELECT extension, agent_name FROM extensions_config ORDER BY CAST(extension AS UNSIGNED) ASC") as $__r) $__nomes[(string)$__r['extension']] = (string)$__r['agent_name']; } catch (Throwable $e) {}
$__can = hasUserPermission('manage_settings');
if ($__c && $__can && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $a = $_POST['rg_action'] ?? '';
    $r = null;
    if ($a === 'criar' || $a === 'editar') { $r = rgSave($__c, $_POST, $a === 'criar'); logUserAction('Grupo de chamada', ($a === 'criar' ? 'criar ' : 'editar ') . preg_replace('/\D/', '', (string)($_POST['grpnum'] ?? '')), 'CONFIG'); }
    elseif ($a === 'excluir') { $r = rgDelete($__c, $_POST['grpnum'] ?? ''); logUserAction('Grupo de chamada', 'excluir ' . preg_replace('/\D/', '', (string)($_POST['grpnum'] ?? '')), 'CONFIG'); }
    elseif ($a === 'desfazer') { $r = rgUndo($__c); logUserAction('Grupo de chamada', 'desfazer', 'CONFIG'); }
    if ($r) { if ($r['success']) $__msg = $r['message']; else $__err = $r['message']; }
}
$__groups = $__c ? rgList($__c) : [];
$__edit = null;
$__editNum = preg_replace('/\D/', '', (string)($_GET['edit'] ?? ''));
foreach ($__groups as $g) if ($__editNum !== '' && (string)$g['grpnum'] === $__editNum) $__edit = $g;
$__novo = isset($_GET['novo']);
$__last = $__c ? rgLastChange() : null;
$__strat = rgStrategies();
$__f = $__edit ?: ['grpnum' => '', 'description' => '', 'strategy' => 'ringall', 'grptime' => 20, 'membros' => []];
$__ext_membros = array_values(array_filter($__f['membros'], function ($m) { return substr($m, -1) !== '#'; }));
$__externos = array_map(function ($m) { return rtrim($m, '#'); }, array_values(array_filter($__f['membros'], function ($m) { return substr($m, -1) === '#'; })));
$__base = 'index.php?module=ramais&action=grupos';
$__btn = 'style="white-space:nowrap;flex-shrink:0;width:auto;overflow:visible"';
?>
<div class="space-y-5">
    <div class="bg-slate-900/90 border border-slate-800 rounded-2xl p-5 flex flex-col md:flex-row md:items-center justify-between gap-3">
        <div>
            <h2 class="text-lg font-extrabold text-white flex items-center gap-2"><i class="fa-solid fa-people-group text-indigo-400"></i> Grupos de Chamada</h2>
            <p class="text-xs text-slate-400 mt-1">Vários ramais tocando juntos (ou em ordem) para o mesmo número, como Vendas ou Suporte. Sincronizado com a central: o que for feito aqui vale lá, e o que for feito lá aparece aqui.</p>
        </div>
        <?php if ($__can && $__c): ?><a href="<?php echo $__base; ?>&novo=1" class="px-3 py-1.5 rounded-lg bg-brand-600 hover:bg-brand-500 text-white font-bold text-xs" <?php echo $__btn; ?>><i class="fa-solid fa-plus"></i> Novo grupo</a><?php endif; ?>
    </div>
    <?php if (!$__c): ?><div class="bg-rose-500/10 border border-rose-500/30 text-rose-300 text-xs rounded-xl px-4 py-3">Não foi possível acessar o banco da central. Veja Configurações &gt; Status da Integração.</div><?php endif; ?>
    <?php if ($__msg): ?><div class="bg-emerald-500/10 border border-emerald-500/30 text-emerald-300 text-xs rounded-xl px-4 py-3"><?php echo htmlspecialchars($__msg); ?></div><?php endif; ?>
    <?php if ($__err): ?><div class="bg-rose-500/10 border border-rose-500/30 text-rose-300 text-xs rounded-xl px-4 py-3"><?php echo htmlspecialchars($__err); ?></div><?php endif; ?>

    <?php if ($__can && $__c && ($__edit || $__novo)): ?>
    <form method="POST" action="<?php echo $__base; ?>" class="bg-slate-900/90 border border-slate-800 rounded-2xl p-5 space-y-4 text-xs">
        <input type="hidden" name="rg_action" value="<?php echo $__edit ? 'editar' : 'criar'; ?>">
        <div class="font-extrabold text-slate-300 uppercase tracking-wider"><?php echo $__edit ? 'Editar grupo ' . htmlspecialchars($__edit['grpnum']) : 'Novo grupo'; ?></div>
        <div class="grid grid-cols-1 md:grid-cols-4 gap-3">
            <label class="block"><span class="text-slate-400">Número do grupo</span>
                <input name="grpnum" value="<?php echo htmlspecialchars($__f['grpnum']); ?>" <?php echo $__edit ? 'readonly' : 'required'; ?> inputmode="numeric" placeholder="600" class="mt-1 w-full bg-slate-950 border border-slate-700 rounded-lg px-3 py-2 text-slate-100"></label>
            <label class="block md:col-span-2"><span class="text-slate-400">Nome</span>
                <input name="description" value="<?php echo htmlspecialchars($__f['description']); ?>" required maxlength="35" placeholder="Vendas" class="mt-1 w-full bg-slate-950 border border-slate-700 rounded-lg px-3 py-2 text-slate-100"></label>
            <label class="block"><span class="text-slate-400">Tempo de toque (segundos)</span>
                <input name="grptime" type="number" min="5" max="300" value="<?php echo (int)$__f['grptime']; ?>" class="mt-1 w-full bg-slate-950 border border-slate-700 rounded-lg px-3 py-2 text-slate-100"></label>
        </div>
        <label class="block"><span class="text-slate-400">Como tocar</span>
            <select name="strategy" class="mt-1 w-full bg-slate-950 border border-slate-700 rounded-lg px-3 py-2 text-slate-100">
                <?php foreach ($__strat as $k => $v): ?><option value="<?php echo $k; ?>" <?php echo $__f['strategy'] === $k ? 'selected' : ''; ?>><?php echo htmlspecialchars($v); ?></option><?php endforeach; ?>
            </select></label>
        <div>
            <span class="text-slate-400">Ramais do grupo</span>
            <div class="mt-1 grid grid-cols-2 md:grid-cols-4 gap-2 max-h-56 overflow-y-auto bg-slate-950 border border-slate-700 rounded-lg p-3">
                <?php $__lista = $__nomes; foreach ($__ext_membros as $m) if (!isset($__lista[$m])) $__lista[$m] = '';
                foreach ($__lista as $num => $nm): ?>
                <label class="flex items-center gap-2 text-slate-200"><input type="checkbox" name="membros[]" value="<?php echo htmlspecialchars($num); ?>" <?php echo in_array((string)$num, $__ext_membros, true) ? 'checked' : ''; ?>> <span><b><?php echo htmlspecialchars($num); ?></b> <?php echo htmlspecialchars($nm); ?></span></label>
                <?php endforeach; ?>
                <?php if (!$__lista): ?><span class="text-slate-500">Nenhum ramal cadastrado ainda.</span><?php endif; ?>
            </div>
        </div>
        <label class="block"><span class="text-slate-400">Números externos (celular ou outra linha), um por linha ou separados por vírgula. Opcional.</span>
            <textarea name="externos" rows="2" placeholder="(16) 99999-1234" class="mt-1 w-full bg-slate-950 border border-slate-700 rounded-lg px-3 py-2 text-slate-100"><?php echo htmlspecialchars(implode("\n", $__externos)); ?></textarea></label>
        <input type="hidden" name="postdest" value="<?php echo htmlspecialchars($__edit['postdest'] ?? ''); ?>">
        <div class="flex gap-2">
            <button type="submit" class="px-4 py-2 rounded-lg bg-brand-600 hover:bg-brand-500 text-white font-bold" <?php echo $__btn; ?>><i class="fa-solid fa-floppy-disk"></i> Salvar e aplicar na central</button>
            <a href="<?php echo $__base; ?>" class="px-4 py-2 rounded-lg bg-slate-700 hover:bg-slate-600 text-white font-bold" <?php echo $__btn; ?>>Cancelar</a>
        </div>
        <p class="text-[11px] text-slate-500">Ao salvar, a central é atualizada na hora, sem derrubar chamadas. Uma cópia do que existia antes fica guardada e dá para desfazer.</p>
    </form>
    <?php endif; ?>

    <div class="bg-slate-900/90 border border-slate-800 rounded-2xl overflow-hidden">
        <div class="overflow-x-auto"><table class="w-full text-xs">
            <thead class="text-[10px] uppercase tracking-wider text-slate-400 border-b border-slate-800"><tr>
                <th class="text-left px-4 py-3">Grupo</th><th class="text-left px-3 py-3">Como toca</th><th class="text-left px-3 py-3">Toque</th><th class="text-left px-3 py-3">Membros</th><th class="px-3 py-3"></th></tr></thead>
            <tbody class="divide-y divide-slate-800">
            <?php foreach ($__groups as $g): ?>
                <tr class="hover:bg-slate-800/40">
                    <td class="px-4 py-2.5"><div class="font-bold text-slate-100"><?php echo htmlspecialchars($g['grpnum']); ?></div><div class="text-slate-400"><?php echo htmlspecialchars($g['description']); ?></div></td>
                    <td class="px-3 py-2.5 text-slate-300"><?php echo htmlspecialchars($__strat[$g['strategy']] ?? $g['strategy']); ?></td>
                    <td class="px-3 py-2.5 text-slate-300"><?php echo (int)$g['grptime']; ?>s</td>
                    <td class="px-3 py-2.5 text-slate-300"><?php $__t = []; foreach ($g['membros'] as $m) { $ext = substr($m, -1) === '#'; $n = rtrim($m, '#'); $__t[] = htmlspecialchars($n) . ($ext ? ' (externo)' : (isset($__nomes[$n]) && $__nomes[$n] !== '' ? ' ' . htmlspecialchars($__nomes[$n]) : '')); } echo $__t ? implode(', ', $__t) : '<span class="text-slate-500">Sem membros</span>'; ?></td>
                    <td class="px-3 py-2.5 text-right whitespace-nowrap">
                        <?php if ($__can): ?>
                        <a href="<?php echo $__base; ?>&edit=<?php echo urlencode($g['grpnum']); ?>" class="px-2.5 py-1 rounded-lg bg-slate-700 hover:bg-slate-600 text-white font-bold"><i class="fa-solid fa-pen"></i> Editar</a>
                        <form method="POST" action="<?php echo $__base; ?>" class="inline" onsubmit="return confirm('Excluir o grupo <?php echo htmlspecialchars($g['grpnum']); ?>? Se estiver em uso, a central não deixa excluir. Dá para desfazer depois.');">
                            <input type="hidden" name="rg_action" value="excluir"><input type="hidden" name="grpnum" value="<?php echo htmlspecialchars($g['grpnum']); ?>">
                            <button class="px-2.5 py-1 rounded-lg bg-rose-600/80 hover:bg-rose-500 text-white font-bold"><i class="fa-solid fa-trash"></i> Excluir</button></form>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            <?php if (!$__groups): ?><tr><td colspan="5" class="px-4 py-8 text-center text-slate-400">Nenhum grupo de chamada cadastrado.</td></tr><?php endif; ?>
            </tbody></table></div>
    </div>
    <?php if ($__can && $__last): ?>
    <form method="POST" action="<?php echo $__base; ?>" class="text-xs text-slate-400 flex items-center gap-3 flex-wrap">
        <input type="hidden" name="rg_action" value="desfazer">
        <span>Última alteração feita pelo painel: <b class="text-slate-200"><?php echo htmlspecialchars($__last['acao'] . ' grupo ' . $__last['grpnum']); ?></b> em <?php echo htmlspecialchars(date('d/m H:i', strtotime((string)$__last['quando']))); ?>.</span>
        <button class="px-3 py-1.5 rounded-lg bg-amber-600/80 hover:bg-amber-500 text-white font-bold" <?php echo $__btn; ?>><i class="fa-solid fa-rotate-left"></i> Desfazer</button>
    </form>
    <?php endif; ?>
</div>
