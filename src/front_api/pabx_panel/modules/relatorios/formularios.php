<?php
/**
 * IPbx Prisma - Formulários de Atendimento: o atendente registra motivo e resultado da chamada.
 * Dados gravados na tabela service_forms (SQLite do painel); a lista de motivos/resultados é configurável.
 */
$db->exec("CREATE TABLE IF NOT EXISTS service_forms (
    id INTEGER PRIMARY KEY AUTOINCREMENT, created_at TEXT, agent_user TEXT, ext TEXT, numero TEXT,
    motivo TEXT, resultado TEXT, obs TEXT)");
$__user = $_SESSION['user_username'] ?? $_SESSION['username'] ?? 'painel';
$__def_motivos = "Dúvida\nSuporte técnico\nFinanceiro\nVenda\nReclamação\nOutro";
$__def_result  = "Resolvido\nPendente\nRetornar ligação\nEncaminhado\nSem sucesso";
$__motivos = array_values(array_filter(array_map('trim', preg_split('/\r?\n/', getSetting('form_motivos') ?: $__def_motivos))));
$__results = array_values(array_filter(array_map('trim', preg_split('/\r?\n/', getSetting('form_resultados') ?: $__def_result))));
$__msg = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (($_POST['form_action'] ?? '') === 'registrar') {
        $mot = trim($_POST['motivo'] ?? ''); $res = trim($_POST['resultado'] ?? '');
        if ($mot === '' || $res === '') { $__msg = 'Informe motivo e resultado.'; }
        else {
            $st = $db->prepare("INSERT INTO service_forms (created_at, agent_user, ext, numero, motivo, resultado, obs) VALUES (?,?,?,?,?,?,?)");
            $st->execute([date('Y-m-d H:i:s'), $__user, preg_replace('/\D/', '', $_POST['ext'] ?? ''), preg_replace('/[^0-9+]/', '', $_POST['numero'] ?? ''), mb_substr($mot, 0, 80), mb_substr($res, 0, 80), mb_substr(trim($_POST['obs'] ?? ''), 0, 1000)]);
            $__msg = 'Atendimento registrado.';
        }
    } elseif (($_POST['form_action'] ?? '') === 'config' && hasUserPermission('manage_settings')) {
        saveSetting('form_motivos', trim($_POST['motivos'] ?? ''));
        saveSetting('form_resultados', trim($_POST['resultados'] ?? ''));
        $__msg = 'Listas salvas.';
        $__motivos = array_values(array_filter(array_map('trim', preg_split('/\r?\n/', getSetting('form_motivos') ?: $__def_motivos))));
        $__results = array_values(array_filter(array_map('trim', preg_split('/\r?\n/', getSetting('form_resultados') ?: $__def_result))));
    }
}
$fd = preg_match('/^\d{4}-\d{2}-\d{2}$/', $_GET['de'] ?? '') ? $_GET['de'] : date('Y-m-d', strtotime('-6 days'));
$fa = preg_match('/^\d{4}-\d{2}-\d{2}$/', $_GET['ate'] ?? '') ? $_GET['ate'] : date('Y-m-d');
$st = $db->prepare("SELECT * FROM service_forms WHERE created_at BETWEEN ? AND ? ORDER BY id DESC LIMIT 300");
$st->execute(["$fd 00:00:00", "$fa 23:59:59"]);
$rows = $st->fetchAll(PDO::FETCH_ASSOC);
$tot = []; foreach ($rows as $r) $tot[$r['resultado']] = ($tot[$r['resultado']] ?? 0) + 1;
$btn = 'style="white-space:nowrap;flex-shrink:0;width:auto;overflow:visible"';
$inp = 'class="w-full bg-slate-950 border border-slate-700 rounded-lg px-3 py-2 text-xs text-slate-100"';
?>
<div class="space-y-5">
    <div class="bg-slate-900/90 border border-slate-800 rounded-2xl p-5">
        <h2 class="text-lg font-extrabold text-white flex items-center gap-2"><i class="fa-solid fa-clipboard-list text-violet-400"></i> Formulários de Atendimento</h2>
        <p class="text-xs text-slate-400 mt-1">Registre o motivo e o resultado de cada atendimento.</p>
        <?php if ($__msg): ?><div class="mt-3 text-xs px-3 py-2 rounded-lg bg-emerald-500/10 border border-emerald-500/30 text-emerald-300"><?php echo htmlspecialchars($__msg); ?></div><?php endif; ?>
    </div>
    <form method="post" class="bg-slate-900/90 border border-slate-800 rounded-2xl p-5 grid grid-cols-1 md:grid-cols-4 gap-3 text-xs">
        <input type="hidden" name="form_action" value="registrar">
        <label class="text-slate-300">Ramal<input name="ext" <?php echo $inp; ?> placeholder="ex.: 200"></label>
        <label class="text-slate-300">Número do cliente<input name="numero" <?php echo $inp; ?> placeholder="ex.: 11999998888"></label>
        <label class="text-slate-300">Motivo<select name="motivo" <?php echo $inp; ?>><?php foreach ($__motivos as $m) echo '<option>' . htmlspecialchars($m) . '</option>'; ?></select></label>
        <label class="text-slate-300">Resultado<select name="resultado" <?php echo $inp; ?>><?php foreach ($__results as $m) echo '<option>' . htmlspecialchars($m) . '</option>'; ?></select></label>
        <label class="text-slate-300 md:col-span-4">Observações<textarea name="obs" rows="2" <?php echo $inp; ?>></textarea></label>
        <div class="md:col-span-4"><button class="px-4 py-2 rounded-lg bg-brand-600 hover:bg-brand-500 text-white font-bold" <?php echo $btn; ?>><i class="fa-solid fa-floppy-disk"></i> Salvar</button></div>
    </form>
    <div class="bg-slate-900/90 border border-slate-800 rounded-2xl p-5 text-xs">
        <form method="get" class="flex flex-wrap items-end gap-3 mb-3">
            <input type="hidden" name="module" value="relatorios"><input type="hidden" name="action" value="formularios">
            <label class="text-slate-300">De<input type="date" name="de" value="<?php echo $fd; ?>" <?php echo $inp; ?>></label>
            <label class="text-slate-300">Até<input type="date" name="ate" value="<?php echo $fa; ?>" <?php echo $inp; ?>></label>
            <button class="px-4 py-2 rounded-lg bg-slate-700 hover:bg-slate-600 text-white font-bold" <?php echo $btn; ?>><i class="fa-solid fa-filter"></i> Filtrar</button>
            <?php foreach ($tot as $k => $v): ?><span class="px-2.5 py-1 rounded-lg border border-slate-600 text-slate-200 font-bold"><?php echo htmlspecialchars($k) . ': ' . $v; ?></span><?php endforeach; ?>
        </form>
        <div class="overflow-x-auto"><table class="w-full">
            <thead><tr class="text-slate-400 text-left"><th class="py-2 pr-3">Data</th><th class="pr-3">Atendente</th><th class="pr-3">Ramal</th><th class="pr-3">Número</th><th class="pr-3">Motivo</th><th class="pr-3">Resultado</th><th>Obs.</th></tr></thead>
            <tbody class="divide-y divide-slate-800 text-slate-200">
            <?php foreach ($rows as $r): ?><tr><td class="py-2 pr-3"><?php echo htmlspecialchars($r['created_at']); ?></td><td class="pr-3"><?php echo htmlspecialchars($r['agent_user']); ?></td><td class="pr-3"><?php echo htmlspecialchars($r['ext']); ?></td><td class="pr-3"><?php echo htmlspecialchars($r['numero']); ?></td><td class="pr-3"><?php echo htmlspecialchars($r['motivo']); ?></td><td class="pr-3"><?php echo htmlspecialchars($r['resultado']); ?></td><td><?php echo htmlspecialchars($r['obs']); ?></td></tr><?php endforeach; ?>
            <?php if (!$rows): ?><tr><td colspan="7" class="py-4 text-slate-500">Nenhum registro no período.</td></tr><?php endif; ?>
            </tbody></table></div>
    </div>
    <?php if (hasUserPermission('manage_settings')): ?>
    <form method="post" class="bg-slate-900/90 border border-slate-800 rounded-2xl p-5 grid grid-cols-1 md:grid-cols-2 gap-3 text-xs">
        <input type="hidden" name="form_action" value="config">
        <label class="text-slate-300">Motivos (um por linha)<textarea name="motivos" rows="6" <?php echo $inp; ?>><?php echo htmlspecialchars(implode("\n", $__motivos)); ?></textarea></label>
        <label class="text-slate-300">Resultados (um por linha)<textarea name="resultados" rows="6" <?php echo $inp; ?>><?php echo htmlspecialchars(implode("\n", $__results)); ?></textarea></label>
        <div class="md:col-span-2"><button class="px-4 py-2 rounded-lg bg-slate-700 hover:bg-slate-600 text-white font-bold" <?php echo $btn; ?>><i class="fa-solid fa-gear"></i> Salvar listas</button></div>
    </form>
    <?php endif; ?>
</div>
