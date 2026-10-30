<?php
/** IPbx Prisma - Manual da plataforma (somente leitura, com busca). Conteúdo em includes/manual.php */
$__man = @include __DIR__ . '/../../includes/manual.php';
if (!is_array($__man)) $__man = [];
$h = function($s) { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); };
?>
<div class="space-y-5">
    <div class="bg-slate-900/90 border border-slate-800 rounded-2xl p-5 flex flex-col md:flex-row md:items-center justify-between gap-3">
        <div>
            <h2 class="text-xl font-bold text-white"><i class="fa-solid fa-book text-brand-400 mr-2"></i>Manual do IPbx Prisma</h2>
            <p class="text-slate-400 text-sm mt-1">Todas as funções do painel e como usá-las.</p>
        </div>
        <input id="man-busca" type="search" placeholder="Buscar no manual..." class="bg-slate-800 border border-slate-700 rounded-xl px-4 py-2 text-sm text-white w-full md:w-72">
    </div>
    <div class="flex flex-wrap gap-2">
        <?php foreach ($__man as $s): ?>
        <a href="#man-<?php echo $h($s['id']); ?>" class="px-3 py-1.5 rounded-lg bg-slate-800 text-slate-300 hover:text-white text-xs font-bold" style="white-space:nowrap;flex-shrink:0;width:auto;overflow:visible"><i class="fa-solid <?php echo $h($s['icon']); ?> mr-1"></i><?php echo $h($s['titulo']); ?></a>
        <?php endforeach; ?>
    </div>
    <?php foreach ($__man as $s): ?>
    <section id="man-<?php echo $h($s['id']); ?>" class="man-sec bg-slate-900/90 border border-slate-800 rounded-2xl p-5">
        <h3 class="text-lg font-bold text-white mb-3"><i class="fa-solid <?php echo $h($s['icon']); ?> text-brand-400 mr-2"></i><?php echo $h($s['titulo']); ?></h3>
        <div class="space-y-3">
            <?php foreach ($s['itens'] as $it): ?>
            <div class="man-it rounded-xl bg-slate-800/50 p-3">
                <div class="font-bold text-slate-100 text-sm"><?php echo $h($it['t']); ?></div>
                <div class="text-slate-400 text-sm mt-0.5"><?php echo $h($it['d']); ?></div>
                <?php if (!empty($it['img']) && preg_match('#^[A-Za-z0-9_./-]+$#', $it['img']) && strpos($it['img'], '..') === false): ?>
                <img src="img/<?php echo $h($it['img']); ?>" alt="<?php echo $h($it['t']); ?>" loading="lazy" class="mt-2 rounded-xl border border-slate-700 max-w-full">
                <?php endif; ?>
                <?php if (!empty($it['kw'])): ?><span class="hidden" aria-hidden="true"><?php echo $h($it['kw']); ?></span><?php endif; ?>
                <?php if (!empty($it['passos'])): ?>
                <ol class="list-decimal ml-5 mt-2 text-sm text-slate-300 space-y-1"><?php foreach ($it['passos'] as $p) echo '<li>' . $h($p) . '</li>'; ?></ol>
                <?php endif; ?>
                <?php if (!empty($it['imgs']) && is_array($it['imgs'])): ?>
                <div class="mt-3 space-y-3">
                <?php foreach ($it['imgs'] as $__i => $im): if (empty($im['f']) || !preg_match('#^[A-Za-z0-9_./-]+$#', $im['f']) || strpos($im['f'], '..') !== false) continue; ?>
                    <figure class="m-0">
                        <img src="img/<?php echo $h($im['f']); ?>" alt="<?php echo $h($im['c'] ?? $it['t']); ?>" loading="lazy" class="rounded-xl border border-slate-700 max-w-full" style="max-height:560px">
                        <?php if (!empty($im['c'])): ?><figcaption class="text-xs text-slate-400 mt-1"><b class="text-slate-300">Figura <?php echo (int)$__i + 1; ?>.</b> <?php echo $h($im['c']); ?></figcaption><?php endif; ?>
                    </figure>
                <?php endforeach; ?>
                </div>
                <?php endif; ?>
                <?php if (!empty($it['campos'])): ?>
                <div class="mt-2 text-sm"><div class="font-bold text-slate-200 mb-1">Campos</div>
                <?php foreach ($it['campos'] as $c => $x): if (is_array($x)) { $c = (string)($x[0] ?? ''); $x = (string)($x[1] ?? ''); } ?><div class="text-slate-400"><b class="text-slate-200"><?php echo $h($c); ?>:</b> <?php echo $h($x); ?></div><?php endforeach; ?></div>
                <?php endif; ?>
                <?php if (!empty($it['dica'])): ?><div class="mt-2 text-sm rounded-lg bg-emerald-500/10 border border-emerald-500/20 text-emerald-300 p-2"><i class="fa-solid fa-lightbulb mr-1"></i><?php echo $h($it['dica']); ?></div><?php endif; ?>
                <?php if (!empty($it['aviso'])): ?><div class="mt-2 text-sm rounded-lg bg-amber-500/10 border border-amber-500/20 text-amber-300 p-2"><i class="fa-solid fa-triangle-exclamation mr-1"></i><?php echo $h($it['aviso']); ?></div><?php endif; ?>
            </div>
            <?php endforeach; ?>
        </div>
    </section>
    <?php endforeach; ?>
</div>
<script>
(function(){
    var b = document.getElementById('man-busca'); if (!b) return;
    b.addEventListener('input', function(){
        var q = b.value.toLowerCase().trim();
        document.querySelectorAll('.man-sec').forEach(function(sec){
            var any = false;
            sec.querySelectorAll('.man-it').forEach(function(it){
                var ok = !q || it.textContent.toLowerCase().indexOf(q) >= 0 || sec.querySelector('h3').textContent.toLowerCase().indexOf(q) >= 0;
                it.style.display = ok ? '' : 'none'; if (ok) any = true;
            });
            sec.style.display = any ? '' : 'none';
        });
    });
})();
</script>
