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
