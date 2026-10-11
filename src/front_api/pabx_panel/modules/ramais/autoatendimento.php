<?php
/**
 * IPbx Prisma - Autoatendimento do Ramal: Não Perturbe (DND) e Desvios (Incondicional / Ocupado / Indisponível)
 * Grava no AstDB (DND, CF, CFB, CFU) - o mesmo local que a GUI nativa do Issabel lê, então os dois sempre mostram o mesmo estado.
 */
$ext_rows = [];
try { $ext_rows = $db->query("SELECT extension, agent_name FROM extensions_config ORDER BY CAST(extension AS INTEGER)")->fetchAll(PDO::FETCH_ASSOC); } catch (Exception $e) {}
$states = getExtensionFeatureStates();
?>
<div class="space-y-5">
    <div class="bg-slate-900/90 border border-slate-800 rounded-2xl p-5">
        <h2 class="text-lg font-extrabold text-white flex items-center gap-2"><i class="fa-solid fa-user-gear text-brand-400"></i> Autoatendimento do Ramal</h2>
        <p class="text-xs text-slate-400 mt-1">Não Perturbe e desvios de chamada por ramal. As alterações valem na hora e aparecem também na interface nativa do Issabel (mesmo registro do Asterisk).</p>
    </div>
    <div id="af-msg" class="hidden p-3 rounded-xl text-xs font-bold"></div>
    <div class="bg-slate-900/90 border border-slate-800 rounded-2xl overflow-x-auto">
        <table class="w-full text-xs text-left">
            <thead class="bg-slate-950 text-slate-400 uppercase text-[10px] tracking-wider"><tr>
                <th class="px-4 py-3">Ramal</th><th class="px-4 py-3">Não Perturbe</th>
                <th class="px-4 py-3">Desvio Incondicional</th><th class="px-4 py-3">Desvio se Ocupado</th><th class="px-4 py-3">Desvio se Indisponível</th><th class="px-4 py-3"></th>
            </tr></thead>
            <tbody class="divide-y divide-slate-800 text-slate-200">
            <?php foreach ($ext_rows as $r): $e = $r['extension']; $st = $states[$e] ?? ['dnd' => false, 'cf' => '', 'cfb' => '', 'cfu' => '']; ?>
                <tr data-ext="<?php echo htmlspecialchars($e); ?>">
                    <td class="px-4 py-3 font-bold"><?php echo htmlspecialchars($e . (trim((string)$r['agent_name']) !== '' ? ' - ' . $r['agent_name'] : '')); ?></td>
                    <td class="px-4 py-3"><input type="checkbox" class="af-dnd" <?php echo $st['dnd'] ? 'checked' : ''; ?>></td>
                    <?php foreach (['cf', 'cfb', 'cfu'] as $f): ?>
                    <td class="px-4 py-3"><input type="text" class="af-<?php echo $f; ?> w-36 px-2.5 py-1.5 bg-slate-950 border border-slate-800 rounded-lg text-white font-mono" value="<?php echo htmlspecialchars($st[$f]); ?>" placeholder="número"></td>
                    <?php endforeach; ?>
                    <td class="px-4 py-3"><button type="button" onclick="afSave(this)" class="px-3 py-1.5 bg-brand-600 hover:bg-brand-500 text-white rounded-lg font-bold" style="white-space:nowrap;flex-shrink:0;width:auto;overflow:visible"><i class="fa-solid fa-floppy-disk"></i> Salvar</button></td>
                </tr>
            <?php endforeach; ?>
            <?php if (!$ext_rows): ?><tr><td colspan="6" class="px-4 py-8 text-center text-slate-500">Nenhum ramal sincronizado.</td></tr><?php endif; ?>
            </tbody>
        </table>
    </div>
</div>
<script>
async function afSave(btn) {
    const tr = btn.closest('tr'), box = document.getElementById('af-msg');
    const body = { ext: tr.dataset.ext, dnd: tr.querySelector('.af-dnd').checked ? 1 : 0, cf: tr.querySelector('.af-cf').value.trim(), cfb: tr.querySelector('.af-cfb').value.trim(), cfu: tr.querySelector('.af-cfu').value.trim() };
    btn.disabled = true;
    try {
        const d = await (await fetch('index.php?api_action=ext_features_set', { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(body) })).json();
        box.className = 'p-3 rounded-xl text-xs font-bold ' + (d.success ? 'bg-emerald-500/10 text-emerald-300 border border-emerald-500/30' : 'bg-rose-500/10 text-rose-300 border border-rose-500/30');
        box.textContent = d.success ? ('Ramal ' + body.ext + ' atualizado.') : ('Erro: ' + (d.error || 'falha ao salvar'));
    } catch (e) { box.className = 'p-3 rounded-xl text-xs font-bold bg-rose-500/10 text-rose-300 border border-rose-500/30'; box.textContent = 'Erro: ' + e.message; }
    box.classList.remove('hidden'); btn.disabled = false;
}
</script>
