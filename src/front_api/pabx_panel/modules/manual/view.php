<?php
/** IPbx Prisma - Central de Ajuda (manual): menu em árvore, artigo com "Nesta página", busca (Ctrl+K), anterior/próximo e "Isto foi útil?".
 *  Conteúdo em includes/manual.php (REGRA 11 do AGENTS.md). Somente leitura; só grava as respostas de "Isto foi útil?". */
$__man = @include __DIR__ . '/../../includes/manual.php';
if (!is_array($__man)) $__man = [];
$h = function($s) { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); };

// ---- "Isto foi útil?" (guarda só o artigo e a resposta; nada do usuário) ----
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && isset($_POST['man_fb'])) {
    while (ob_get_level()) ob_end_clean();
    header('Content-Type: application/json; charset=utf-8');
    global $db;
    $art = mb_substr(trim((string)($_POST['art'] ?? '')), 0, 120);
    $v = (string)($_POST['v'] ?? '');
    if ($art === '' || !in_array($v, ['sim', 'mais_ou_menos', 'nao'], true)) { echo json_encode(['success' => false]); exit; }
    try {
        $db->exec("CREATE TABLE IF NOT EXISTS manual_feedback (id INTEGER PRIMARY KEY AUTOINCREMENT, artigo TEXT, resposta TEXT, em DATETIME DEFAULT CURRENT_TIMESTAMP)");
        $db->prepare("INSERT INTO manual_feedback (artigo, resposta) VALUES (?, ?)")->execute([$art, $v]);
        echo json_encode(['success' => true]);
    } catch (Exception $e) { echo json_encode(['success' => false]); }
    exit;
}

$__flat = []; // lista linear de artigos (para anterior/próximo)
foreach ($__man as $si => $s) foreach ($s['itens'] as $ii => $it) $__flat[] = ['k' => "a{$si}_{$ii}", 's' => $si, 'i' => $ii, 't' => $it['t']];
$__img = function ($f) { return (!empty($f) && preg_match('#^[A-Za-z0-9_./-]+$#', $f) && strpos($f, '..') === false) ? 'img/' . $f : ''; };
?>
<style>
.mn-wrap{display:grid;grid-template-columns:270px minmax(0,1fr) 210px;gap:22px;align-items:start}
.mn-side{position:sticky;top:12px;max-height:calc(100vh - 40px);overflow:auto;background:rgba(15,23,42,.9);border:1px solid #1e293b;border-radius:18px;padding:12px}
.mn-grp{margin:14px 6px 4px;font-size:11px;font-weight:800;letter-spacing:.6px;color:#e2e8f0;text-transform:uppercase}
.mn-lnk{display:block;padding:7px 10px;border-radius:10px;color:#94a3b8;font-size:13px;cursor:pointer;text-decoration:none}
.mn-lnk:hover{background:#1e293b;color:#fff}.mn-lnk.on{background:rgba(99,102,241,.15);color:#a5b4fc;font-weight:700}
.mn-art{display:none}.mn-art.on{display:block}
.mn-art h1{font-size:28px;font-weight:800;color:#fff;margin:0 0 6px}.mn-art h2{font-size:18px;font-weight:800;color:#fff;margin:26px 0 8px;padding-top:6px}
.mn-lead{color:#cbd5e1;font-size:15px;line-height:1.6}
.mn-fig{margin:12px 0}.mn-fig img{border-radius:12px;border:1px solid #334155;max-width:100%}.mn-fig figcaption{font-size:12px;color:#94a3b8;margin-top:5px}
.mn-st{counter-reset:p;list-style:none;padding:0;margin:0}.mn-st li{counter-increment:p;position:relative;padding:8px 0 8px 38px;color:#cbd5e1;font-size:14px;line-height:1.55}
.mn-st li:before{content:counter(p);position:absolute;left:0;top:7px;width:26px;height:26px;border-radius:50%;background:#4f46e5;color:#fff;font-weight:800;font-size:12px;display:flex;align-items:center;justify-content:center}
.mn-tb{width:100%;border-collapse:collapse;font-size:13px}.mn-tb td{padding:8px 10px;border-top:1px solid #1e293b;vertical-align:top;color:#94a3b8}.mn-tb td:first-child{color:#e2e8f0;font-weight:700;width:32%}
.mn-box{border-radius:12px;padding:10px 12px;font-size:14px;margin:10px 0}.mn-dica{background:rgba(16,185,129,.1);border:1px solid rgba(16,185,129,.25);color:#6ee7b7}.mn-aviso{background:rgba(245,158,11,.1);border:1px solid rgba(245,158,11,.25);color:#fcd34d}
.mn-toc{position:sticky;top:12px;font-size:13px}.mn-toc b{display:block;font-size:11px;letter-spacing:.6px;color:#94a3b8;text-transform:uppercase;margin-bottom:8px}
.mn-toc a{display:block;padding:5px 0 5px 12px;border-left:2px solid #1e293b;color:#94a3b8;text-decoration:none}.mn-toc a:hover{color:#fff;border-color:#6366f1}
.mn-nav{display:flex;justify-content:space-between;gap:10px;margin-top:30px;padding-top:16px;border-top:1px solid #1e293b}
.mn-nav a{flex:1;padding:12px 14px;border:1px solid #1e293b;border-radius:12px;color:#cbd5e1;text-decoration:none;font-size:13px;min-width:0}.mn-nav a:hover{border-color:#6366f1;color:#fff}.mn-nav small{display:block;color:#64748b;font-size:11px}
.mn-fb{margin-top:22px;display:flex;align-items:center;gap:10px;color:#94a3b8;font-size:13px}
.mn-fb button{white-space:nowrap!important;flex-shrink:0!important;width:auto!important;overflow:visible!important;text-overflow:clip!important;background:#1e293b;border:1px solid #334155;color:#e2e8f0;border-radius:999px;padding:6px 12px;font-size:12px;font-weight:700;cursor:pointer}.mn-fb button:hover{border-color:#6366f1}
.mn-card{display:block;padding:14px;border:1px solid #1e293b;border-radius:14px;background:rgba(15,23,42,.9);text-decoration:none;color:#e2e8f0;font-weight:700;cursor:pointer}.mn-card:hover{border-color:#6366f1}.mn-card small{display:block;color:#64748b;font-weight:500;margin-top:3px}
.mn-res{display:none;position:absolute;z-index:30;left:0;right:0;top:46px;max-height:340px;overflow:auto;background:#0f172a;border:1px solid #334155;border-radius:14px;padding:6px}
@media(max-width:1100px){.mn-wrap{grid-template-columns:240px minmax(0,1fr)}.mn-toc{display:none}}
@media(max-width:760px){.mn-wrap{grid-template-columns:1fr}.mn-side{position:static;max-height:none}}
</style>
<div class="space-y-4" id="mn-root">
    <div class="bg-slate-900/90 border border-slate-800 rounded-2xl p-4 flex flex-col md:flex-row md:items-center justify-between gap-3">
        <a href="#" id="mn-home" class="text-xl font-bold text-white no-underline"><i class="fa-solid fa-book text-brand-400 mr-2"></i>Central de Ajuda IPbx Prisma</a>
        <div class="relative w-full md:w-96">
            <input id="man-busca" type="search" autocomplete="off" placeholder="Buscar no manual... (Ctrl+K)" class="bg-slate-800 border border-slate-700 rounded-xl px-4 py-2 text-sm text-white w-full">
            <div class="mn-res" id="mn-res"></div>
        </div>
    </div>
    <div class="mn-wrap">
        <nav class="mn-side" aria-label="Menu do manual">
            <?php foreach ($__man as $si => $s): ?>
            <div class="mn-grp"><i class="fa-solid <?php echo $h($s['icon'] ?? 'fa-book'); ?> mr-1"></i><?php echo $h($s['titulo']); ?></div>
            <?php foreach ($s['itens'] as $ii => $it): ?><a class="mn-lnk" data-k="a<?php echo $si . '_' . $ii; ?>" href="#a<?php echo $si . '_' . $ii; ?>"><?php echo $h($it['t']); ?></a><?php endforeach; ?>
            <?php endforeach; ?>
        </nav>
        <main>
            <article class="mn-art on" id="home">
                <h1>Central de Ajuda IPbx Prisma</h1>
                <p class="mn-lead">Tudo o que você pode fazer no painel, explicado passo a passo. Escolha um assunto ou use a busca.</p>
                <?php foreach ($__man as $si => $s): ?>
                <h2><i class="fa-solid <?php echo $h($s['icon'] ?? 'fa-book'); ?> text-brand-400 mr-2"></i><?php echo $h($s['titulo']); ?></h2>
                <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(220px,1fr));gap:10px">
                    <?php foreach ($s['itens'] as $ii => $it): ?><a class="mn-card" href="#a<?php echo $si . '_' . $ii; ?>"><?php echo $h($it['t']); ?><small><?php echo $h(mb_substr((string)($it['d'] ?? ''), 0, 70)); ?>…</small></a><?php endforeach; ?>
                </div>
                <?php endforeach; ?>
            </article>
            <?php foreach ($__flat as $n => $f): $s = $__man[$f['s']]; $it = $s['itens'][$f['i']]; $toc = []; ?>
            <article class="mn-art" id="<?php echo $f['k']; ?>" data-t="<?php echo $h($it['t']); ?>">
                <div class="text-xs text-slate-500 mb-2"><?php echo $h($s['titulo']); ?></div>
                <h1><?php echo $h($it['t']); ?></h1>
                <p class="mn-lead"><?php echo $h($it['d'] ?? ''); ?></p>
                <?php if (!empty($it['aviso'])): $toc[] = ['Aviso', 'av']; ?><div class="mn-box mn-aviso" id="<?php echo $f['k']; ?>av"><i class="fa-solid fa-triangle-exclamation mr-1"></i><?php echo $h($it['aviso']); ?></div><?php endif; ?>
                <?php $__im = []; if ($__img($it['img'] ?? '')) $__im[] = ['f' => $it['img'], 'c' => '']; foreach (($it['imgs'] ?? []) as $im) if (is_array($im) && $__img($im['f'] ?? '')) $__im[] = $im;
                if ($__im): $toc[] = ['Veja na tela', 'im']; ?><h2 id="<?php echo $f['k']; ?>im">Veja na tela</h2>
                <?php foreach ($__im as $ix => $im): ?><figure class="mn-fig"><img src="<?php echo $h($__img($im['f'])); ?>" alt="<?php echo $h($im['c'] ?: $it['t']); ?>" loading="lazy"><?php if (!empty($im['c'])): ?><figcaption><b class="text-slate-300">Figura <?php echo $ix + 1; ?>.</b> <?php echo $h($im['c']); ?></figcaption><?php endif; ?></figure><?php endforeach; endif; ?>
                <?php if (!empty($it['passos'])): $toc[] = ['Passo a passo', 'ps']; ?><h2 id="<?php echo $f['k']; ?>ps">Passo a passo</h2><ol class="mn-st"><?php foreach ($it['passos'] as $p) echo '<li>' . $h($p) . '</li>'; ?></ol><?php endif; ?>
                <?php if (!empty($it['campos'])): $toc[] = ['Campos', 'cp']; ?><h2 id="<?php echo $f['k']; ?>cp">Campos</h2><table class="mn-tb"><?php foreach ($it['campos'] as $c => $x): if (is_array($x)) { $c = (string)($x[0] ?? ''); $x = (string)($x[1] ?? ''); } ?><tr><td><?php echo $h($c); ?></td><td><?php echo $h($x); ?></td></tr><?php endforeach; ?></table><?php endif; ?>
                <?php if (!empty($it['dica'])): $toc[] = ['Dica', 'dc']; ?><div class="mn-box mn-dica" id="<?php echo $f['k']; ?>dc"><i class="fa-solid fa-lightbulb mr-1"></i><?php echo $h($it['dica']); ?></div><?php endif; ?>
                <?php if (!empty($it['kw'])): ?><span class="hidden" aria-hidden="true"><?php echo $h($it['kw']); ?></span><?php endif; ?>
                <div class="mn-fb" data-art="<?php echo $h($it['t']); ?>">Isto foi útil?
                    <button data-v="sim">😊 Sim</button><button data-v="mais_ou_menos">😐 Mais ou menos</button><button data-v="nao">🙁 Não</button><span class="fbm"></span></div>
                <div class="mn-nav">
                    <?php if ($n > 0): ?><a href="#<?php echo $__flat[$n - 1]['k']; ?>"><small>← Anterior</small><?php echo $h($__flat[$n - 1]['t']); ?></a><?php else: ?><span style="flex:1"></span><?php endif; ?>
                    <?php if ($n < count($__flat) - 1): ?><a href="#<?php echo $__flat[$n + 1]['k']; ?>" style="text-align:right"><small>Próximo →</small><?php echo $h($__flat[$n + 1]['t']); ?></a><?php endif; ?>
                </div>
                <script type="application/json" class="mn-toc-data"><?php echo json_encode($toc, JSON_UNESCAPED_UNICODE); ?></script>
            </article>
            <?php endforeach; ?>
        </main>
        <aside class="mn-toc" id="mn-toc"></aside>
    </div>
</div>
<script>
(function(){
    var arts = document.querySelectorAll('.mn-art'), lnks = document.querySelectorAll('.mn-lnk'), toc = document.getElementById('mn-toc');
    function show(id){
        var el = document.getElementById(id) || document.getElementById('home'); id = el.id;
        arts.forEach(function(a){ a.classList.toggle('on', a === el); });
        lnks.forEach(function(l){ l.classList.toggle('on', l.dataset.k === id); });
        toc.textContent = '';
        var d = el.querySelector('.mn-toc-data');
        if (d) { var t = []; try { t = JSON.parse(d.textContent); } catch(e){}
            if (t.length) { var b = document.createElement('b'); b.textContent = 'Nesta página'; toc.appendChild(b);
                t.forEach(function(x){ var a = document.createElement('a'); a.href = 'javascript:void(0)'; a.textContent = x[0]; a.addEventListener('click', function(){ var e = document.getElementById(id + x[1]); if (e) e.scrollIntoView({behavior:'smooth', block:'start'}); }); toc.appendChild(a); }); } }
        var act = document.querySelector('.mn-lnk.on'); if (act && act.scrollIntoView) act.scrollIntoView({block:'nearest'});
        window.scrollTo({top:0});
    }
    function route(){ show((location.hash || '').replace('#','')); }
    window.addEventListener('hashchange', route); route();
    document.getElementById('mn-home').addEventListener('click', function(e){ e.preventDefault(); history.pushState(null,'',location.pathname+location.search); show('home'); });

    // busca (Ctrl+K): títulos e todo o texto dos artigos
    var b = document.getElementById('man-busca'), res = document.getElementById('mn-res');
    function busca(){
        var q = b.value.toLowerCase().trim(); res.textContent = '';
        if (q.length < 2) { res.style.display = 'none'; return; }
        var n = 0;
        document.querySelectorAll('.mn-art[data-t]').forEach(function(a){
            if (n >= 12) return;
            var txt = a.textContent.toLowerCase(); if (txt.indexOf(q) < 0) return; n++;
            var r = document.createElement('a'); r.className = 'mn-lnk'; r.href = '#' + a.id; r.textContent = a.dataset.t;
            r.addEventListener('click', function(){ res.style.display = 'none'; b.value = ''; }); res.appendChild(r);
        });
        if (!n) { var s = document.createElement('div'); s.className = 'mn-lnk'; s.textContent = 'Nada encontrado para "' + b.value + '".'; res.appendChild(s); }
        res.style.display = 'block';
    }
    b.addEventListener('input', busca);
    document.addEventListener('keydown', function(e){ if ((e.ctrlKey || e.metaKey) && e.key.toLowerCase() === 'k') { e.preventDefault(); b.focus(); b.select(); } if (e.key === 'Escape') { res.style.display = 'none'; } });
    document.addEventListener('click', function(e){ if (!res.contains(e.target) && e.target !== b) res.style.display = 'none'; });

    // "Isto foi útil?"
    document.querySelectorAll('.mn-fb').forEach(function(box){
        box.addEventListener('click', function(e){
            var bt = e.target.closest('button'); if (!bt) return;
            var fd = new FormData(); fd.append('man_fb', '1'); fd.append('art', box.dataset.art); fd.append('v', bt.dataset.v);
            fetch(location.href, {method:'POST', body: fd, credentials:'same-origin'}).then(function(r){ return r.json(); }).then(function(j){
                box.querySelectorAll('button').forEach(function(x){ x.style.display = 'none'; });
                box.querySelector('.fbm').textContent = j && j.success ? 'Obrigado pela sua resposta!' : 'Não consegui registrar agora.';
            }).catch(function(){ box.querySelector('.fbm').textContent = 'Não consegui registrar agora.'; });
        });
    });
})();
</script>
