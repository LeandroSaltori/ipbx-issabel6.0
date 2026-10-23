<?php
/** Mapa do Sistema: cidade do código, conexões e histórico de entregas (gerado a cada atualização pelo instalador). */
$__mapa = __DIR__ . '/../../mapa/mapa-do-ipbx.html';
$__ok = is_file($__mapa);
?>
<div class="space-y-4">
    <div class="bg-slate-900/90 border border-slate-800 rounded-2xl p-5 flex flex-wrap items-center justify-between gap-3">
        <div>
            <h3 class="text-base font-extrabold text-white flex items-center gap-2.5"><i class="fa-solid fa-city text-amber-400"></i> Mapa do Sistema</h3>
            <span class="text-xs text-slate-400">O painel desenhado a partir do próprio código: arquivos como prédios, conexões entre eles e histórico das atualizações.</span>
        </div>
        <?php if ($__ok): ?><span class="text-xs text-slate-400">Atualizado em <?php echo date('d/m/Y H:i', filemtime($__mapa)); ?> (a cada atualização do sistema)</span><?php endif; ?>
    </div>
    <?php if ($__ok): ?>
    <iframe srcdoc="<?php echo htmlspecialchars((string)file_get_contents($__mapa), ENT_QUOTES); ?>" title="Mapa do Sistema" style="width:100%;height:78vh;border:1px solid #1e293b;border-radius:16px;background:#fff"></iframe>
    <?php else: ?>
    <div class="bg-amber-500/10 border border-amber-500/30 rounded-2xl p-5 text-sm text-amber-200">O mapa ainda não foi gerado neste servidor. Rode o comando de atualização do sistema e ele aparece aqui automaticamente.</div>
    <?php endif; ?>
</div>
