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
