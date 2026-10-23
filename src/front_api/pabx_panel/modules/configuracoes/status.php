<?php
/**
 * IPbx Prisma - Status da Integração: mostra, em uma tela, se cada peça (AMI, bancos, WhatsApp, IA, SMTP, crons) está funcionando.
 * Somente leitura: nenhuma mensagem é enviada e nada é alterado.
 */
$__checks = integrationStatusChecks();
$__groups = [];
$__count = ['ok' => 0, 'warn' => 0, 'fail' => 0, 'off' => 0];
foreach ($__checks as $c) { $__groups[$c['grupo']][] = $c; $__count[$c['estado']]++; }
$__style = [
    'ok'   => ['bg-emerald-500/20 text-emerald-300 border-emerald-500/30', 'OK', 'fa-circle-check text-emerald-400'],
    'warn' => ['bg-amber-500/20 text-amber-300 border-amber-500/30', 'Atenção', 'fa-triangle-exclamation text-amber-400'],
    'fail' => ['bg-rose-500/20 text-rose-300 border-rose-500/30', 'Falha', 'fa-circle-xmark text-rose-400'],
    'off'  => ['bg-slate-700/40 text-slate-300 border-slate-600/40', 'Desativado', 'fa-circle-minus text-slate-400'],
];
?>
<div class="space-y-5">
    <div class="bg-slate-900/90 border border-slate-800 rounded-2xl p-5 flex flex-col md:flex-row md:items-center justify-between gap-3">
        <div>
            <h2 class="text-lg font-extrabold text-white flex items-center gap-2"><i class="fa-solid fa-heart-pulse text-rose-400"></i> Status da Integração</h2>
            <p class="text-xs text-slate-400 mt-1">Verificação em tempo real de cada peça do sistema. Nenhuma mensagem é enviada nesta tela.</p>
        </div>
        <div class="flex items-center gap-2 text-xs flex-wrap">
            <?php foreach (['ok', 'warn', 'fail', 'off'] as $k): if (!$__count[$k]) continue; ?>
            <span class="px-2.5 py-1 rounded-lg border font-bold <?php echo $__style[$k][0]; ?>"><?php echo $__count[$k] . ' ' . $__style[$k][1]; ?></span>
            <?php endforeach; ?>
            <a href="index.php?module=configuracoes&action=status" class="px-3 py-1.5 rounded-lg bg-brand-600 hover:bg-brand-500 text-white font-bold" style="white-space:nowrap;flex-shrink:0;width:auto;overflow:visible"><i class="fa-solid fa-rotate"></i> Verificar de novo</a>
        </div>
    </div>
    <?php if (hasUserPermission('manage_settings')): ?>
    <div class="bg-slate-900/90 border border-slate-800 rounded-2xl p-5 space-y-3">
        <div class="flex flex-col md:flex-row md:items-center justify-between gap-3">
            <div><h3 class="text-sm font-extrabold text-white flex items-center gap-2"><i class="fa-solid fa-screwdriver-wrench text-amber-400"></i> Algo não está funcionando?</h3>
            <p class="text-xs text-slate-400 mt-1">O painel tenta resolver sozinho os problemas de banco de dados e permissões. Se continuar com erro, copie o relatório e envie ao suporte (ele não contém senhas).</p></div>
            <div class="flex items-center gap-2 flex-wrap">
                <button type="button" id="btn-st-repair" onclick="stRepair()" style="white-space:nowrap !important;flex-shrink:0 !important;width:auto !important;overflow:visible !important;text-overflow:clip !important" class="px-4 py-2 rounded-xl bg-amber-600 hover:bg-amber-500 text-white text-xs font-extrabold flex items-center gap-2"><i class="fa-solid fa-wand-magic-sparkles"></i> Tentar consertar sozinho</button>
                <button type="button" id="btn-st-copy" onclick="stCopy()" style="white-space:nowrap !important;flex-shrink:0 !important;width:auto !important;overflow:visible !important;text-overflow:clip !important" class="px-4 py-2 rounded-xl bg-slate-800 hover:bg-slate-700 text-slate-100 text-xs font-bold border border-slate-700 flex items-center gap-2"><i class="fa-solid fa-copy"></i> Copiar relatório para o suporte</button>
            </div>
        </div>
        <pre id="st-out" class="hidden text-[11px] leading-relaxed bg-slate-950 border border-slate-800 rounded-xl p-3 text-slate-300 whitespace-pre-wrap"></pre>
    </div>
    <script>
    async function stRepair() {
        var b = document.getElementById('btn-st-repair'), o = document.getElementById('st-out'), t = b.innerHTML;
        b.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> Verificando...'; b.disabled = true; o.classList.remove('hidden'); o.textContent = 'Verificando...';
        try {
            var r = await (await fetch('index.php?api_action=status_repair', {method: 'POST'})).json();
            o.textContent = r.success ? (r.feito || []).concat([''], r.linhas || []).join('\n') : (r.error || 'Não foi possível verificar.');
            if (r.success && r.ok) setTimeout(function () { location.reload(); }, 1800);
        } catch (e) { o.textContent = 'Não foi possível verificar: ' + e.message; }
        b.innerHTML = t; b.disabled = false;
    }
    async function stCopy() {
        var b = document.getElementById('btn-st-copy'), o = document.getElementById('st-out'), t = b.innerHTML;
        try {
            var txt = await (await fetch('index.php?api_action=status_report')).text();
            o.classList.remove('hidden'); o.textContent = txt;
            try { await navigator.clipboard.writeText(txt); b.innerHTML = '<i class="fa-solid fa-check"></i> Copiado!'; }
            catch (e) { var r = document.createRange(); r.selectNodeContents(o); var s = window.getSelection(); s.removeAllRanges(); s.addRange(r); b.innerHTML = '<i class="fa-solid fa-check"></i> Selecionado: toque em copiar'; }
        } catch (e) { o.classList.remove('hidden'); o.textContent = 'Não foi possível gerar o relatório: ' + e.message; }
        setTimeout(function () { b.innerHTML = t; }, 2500);
    }
    </script>
    <?php endif; ?>
    <?php foreach ($__groups as $g => $items): ?>
    <div class="bg-slate-900/90 border border-slate-800 rounded-2xl overflow-hidden">
        <div class="px-4 py-3 text-xs font-extrabold text-slate-300 uppercase tracking-wider border-b border-slate-800"><?php echo htmlspecialchars($g); ?></div>
        <div class="divide-y divide-slate-800">
            <?php foreach ($items as $it): $st = $__style[$it['estado']]; ?>
            <div class="px-4 py-3 flex items-start gap-3 text-xs">
                <i class="fa-solid <?php echo $st[2]; ?> mt-0.5"></i>
                <div class="min-w-0 flex-1">
                    <div class="font-bold text-slate-100"><?php echo htmlspecialchars($it['nome']); ?></div>
                    <div class="text-slate-400 break-words"><?php echo htmlspecialchars($it['detalhe']); ?></div>
                </div>
                <span class="px-2 py-0.5 rounded border font-bold text-[10px] shrink-0 <?php echo $st[0]; ?>"><?php echo $st[1]; ?></span>
            </div>
            <?php endforeach; ?>
        </div>
    </div>
    <?php endforeach; ?>
</div>
