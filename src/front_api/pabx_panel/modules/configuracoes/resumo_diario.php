<?php
/** Configurações > Resumo Diário: relatório curto do dia anterior por WhatsApp e/ou e-mail (opcional, desligado por padrão). */
$__msg = ''; $__ok = true;
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action_type'] ?? '') === 'save_daily') {
    saveSetting('daily_summary_enabled', isset($_POST['enabled']) ? '1' : '0');
    $t = trim($_POST['send_time'] ?? '08:00'); saveSetting('daily_summary_time', preg_match('/^\d{2}:\d{2}$/', $t) ? $t : '08:00');
    saveSetting('daily_summary_wa', trim($_POST['wa'] ?? ''));
    saveSetting('daily_summary_email', trim($_POST['email'] ?? ''));
    $__msg = 'Configuração salva.';
}
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action_type'] ?? '') === 'test_daily') {
    $r = dsSend(date('Y-m-d', strtotime('-1 day')), true);
    $parts = [];
    foreach (($r['wa'] ?? []) as $n => $okk) $parts[] = "WhatsApp $n: " . ($okk ? 'enviado' : 'falhou');
    if ($r['email'] !== null) $parts[] = 'E-mail: ' . ($r['email'] ? 'enviado' : 'falhou');
    if (!$parts) { $__ok = false; $__msg = 'Preencha ao menos um número de WhatsApp ou um e-mail e salve antes de testar.'; }
    else { $__msg = 'Teste com os dados de ontem: ' . implode(' | ', $parts); $__ok = !in_array(false, array_merge(array_values($r['wa'] ?? []), $r['email'] === null ? [] : [$r['email']]), true); }
}
$__en = getSetting('daily_summary_enabled') === '1';
$__tm = getSetting('daily_summary_time') ?: '08:00';
$__wa = getSetting('daily_summary_wa'); $__em = getSetting('daily_summary_email');
$__pv = dsText(dsMetrics(date('Y-m-d', strtotime('-1 day'))));
?>
<div class="max-w-3xl space-y-5">
    <div class="bg-slate-900/90 border border-slate-800 rounded-2xl p-5">
        <h3 class="text-base font-extrabold text-white flex items-center gap-2.5"><i class="fa-solid fa-newspaper text-emerald-400"></i> Resumo Diário</h3>
        <p class="text-xs text-slate-400 mt-1">Todo dia, no horário escolhido, você recebe um resumo curto de ontem: ligações de clientes, atendidas, não atendidas, tempos e satisfação. É opcional e vem desligado.</p>
    </div>
    <?php if ($__msg): ?><div class="p-3 rounded-xl text-sm border <?php echo $__ok ? 'bg-emerald-500/10 border-emerald-500/30 text-emerald-300' : 'bg-rose-500/10 border-rose-500/30 text-rose-300'; ?>"><?php echo htmlspecialchars($__msg); ?></div><?php endif; ?>
    <form method="POST" class="bg-slate-900/90 border border-slate-800 rounded-2xl p-5 space-y-4">
        <input type="hidden" name="action_type" value="save_daily">
        <label class="flex items-center gap-3 text-sm text-white font-bold cursor-pointer"><input type="checkbox" name="enabled" value="1" <?php echo $__en ? 'checked' : ''; ?>> Enviar o resumo diário</label>
        <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
            <label class="text-xs text-slate-300 space-y-1 block">Horário de envio
                <input type="time" name="send_time" value="<?php echo htmlspecialchars($__tm); ?>" class="w-full px-3 py-2 bg-slate-950 border border-slate-800 rounded-xl text-white"></label>
            <label class="text-xs text-slate-300 space-y-1 block">E-mail (opcional)
                <input type="email" name="email" value="<?php echo htmlspecialchars($__em); ?>" placeholder="gestor@empresa.com.br" class="w-full px-3 py-2 bg-slate-950 border border-slate-800 rounded-xl text-white"></label>
        </div>
        <label class="text-xs text-slate-300 space-y-1 block">WhatsApp (um ou mais números, separados por vírgula)
            <input type="text" name="wa" value="<?php echo htmlspecialchars($__wa); ?>" placeholder="5516999999999, 5516988888888" class="w-full px-3 py-2 bg-slate-950 border border-slate-800 rounded-xl text-white"></label>
        <div class="flex flex-wrap gap-3">
            <button type="submit" style="white-space:nowrap !important;flex-shrink:0 !important;width:auto !important" class="px-4 py-2 bg-emerald-600 hover:bg-emerald-500 text-white font-extrabold rounded-xl text-sm"><i class="fa-solid fa-floppy-disk"></i> Salvar</button>
            <button type="submit" name="action_type" value="test_daily" style="white-space:nowrap !important;flex-shrink:0 !important;width:auto !important" class="px-4 py-2 bg-slate-800 hover:bg-slate-700 text-white font-bold rounded-xl text-sm"><i class="fa-solid fa-paper-plane"></i> Enviar teste agora</button>
        </div>
    </form>
    <div class="bg-slate-900/90 border border-slate-800 rounded-2xl p-5">
        <div class="text-xs font-bold uppercase tracking-wider text-slate-400 mb-2">Como o resumo de ontem ficaria</div>
        <pre class="whitespace-pre-wrap text-sm text-slate-200 font-sans"><?php echo htmlspecialchars(str_replace(['*', '_'], '', $__pv)); ?></pre>
    </div>
</div>
