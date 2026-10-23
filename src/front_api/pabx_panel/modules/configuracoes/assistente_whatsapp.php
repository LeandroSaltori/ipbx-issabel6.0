<?php
/** Configurações > Assistente WhatsApp: converse com o IPbx Prisma pelo WhatsApp (só números autorizados). Desligado por padrão. */
waAsstEnsure();
$__msg = ''; $__ok = true;
$act = $_POST['action_type'] ?? '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $act === 'save_asst') {
    $nums = [];
    foreach (preg_split('/[,;\n]+/', (string)($_POST['allowed'] ?? ''), -1, PREG_SPLIT_NO_EMPTY) as $n) { $d = preg_replace('/\D/', '', $n); if (strlen($d) >= 10) $nums[] = $d; }
    saveSetting('wa_asst_allowed', implode(', ', array_unique($nums)));
    saveSetting('wa_asst_cap', (string)max(10, min(1000, (int)($_POST['cap'] ?? 100))));
    $want = isset($_POST['enabled']);
    if ($want && !$nums) { $want = false; $__ok = false; $__msg = 'Informe ao menos um número autorizado antes de ligar o assistente.'; }
    if ($want && !waAsstEnabled()) {
        $r = waAsstRegister(!empty($_POST['replace']));
        if (!$r['success']) { $want = false; $__ok = false; $__msg = $r['error']; $__conflict = !empty($r['conflict']); }
    }
    if (!$want && waAsstEnabled()) waAsstUnregister();
    saveSetting('wa_asst_enabled', $want ? '1' : '0');
    if ($__ok) $__msg = $want ? 'Assistente ligado. Mande uma mensagem do seu WhatsApp para o número conectado.' : 'Configuração salva (assistente desligado).';
}
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $act === 'test_asst') {
    $first = trim(explode(',', (string)getSetting('wa_asst_allowed'))[0] ?? '');
    if ($first === '') { $__ok = false; $__msg = 'Salve ao menos um número autorizado antes de testar.'; }
    else { $okk = waAsstReply($first, "Olá! Aqui é o assistente do IPbx Prisma. Pode me perguntar, por exemplo: \"quantas ligações recebemos hoje?\""); $__ok = $okk; $__msg = $okk ? "Mensagem de teste enviada para $first." : "Não foi possível enviar para $first. Verifique o WhatsApp conectado em Configurações > API."; }
}
$__en = waAsstEnabled(); $__al = (string)getSetting('wa_asst_allowed'); $__cap = (int)(getSetting('wa_asst_cap') ?: 100);
$__base = waAsstBase();
$__log = $db->query("SELECT at, phone, dir, text, status FROM wa_asst_log ORDER BY id DESC LIMIT 30")->fetchAll(PDO::FETCH_ASSOC);
$__raw = $db->query("SELECT at, body FROM wa_asst_raw ORDER BY id DESC LIMIT 3")->fetchAll(PDO::FETCH_ASSOC);
$__prov = waProvider() === 'uazapi';
$__last = (string)$db->query("SELECT at FROM wa_asst_raw ORDER BY id DESC LIMIT 1")->fetchColumn();
?>
<div class="max-w-3xl space-y-5">
    <div class="bg-slate-900/90 border border-slate-800 rounded-2xl p-5">
        <h3 class="text-base font-extrabold text-white flex items-center gap-2.5"><i class="fa-brands fa-whatsapp text-emerald-400"></i> Assistente WhatsApp</h3>
        <p class="text-xs text-slate-400 mt-1">Converse com o IPbx Prisma pelo WhatsApp: pergunte sobre ligações, ramais e filas e receba respostas com dados reais. O assistente só responde aos números da lista abaixo. Qualquer alteração pede a sua confirmação com <b>SIM</b>. É opcional e vem desligado.</p>
    </div>
    <?php if (!$__prov): ?><div class="p-3 rounded-xl text-sm border bg-amber-500/10 border-amber-500/30 text-amber-300">Para usar o assistente, conecte o WhatsApp por QR Code em Configurações &gt; API.</div><?php endif; ?>
    <?php if ($__base === ''): ?><div class="p-3 rounded-xl text-sm border bg-amber-500/10 border-amber-500/30 text-amber-300">Para o WhatsApp avisar o painel de cada mensagem, abra o painel pelo seu endereço com domínio (ex.: https://pbx.suaempresa.com.br) e ligue o assistente. O painel descobre o endereço sozinho; você não precisa digitar nada. Acessando por IP interno (como 192.168...), o WhatsApp não consegue chegar até o painel.</div><?php endif; ?>
    <?php if ($__msg): ?><div class="p-3 rounded-xl text-sm border <?php echo $__ok ? 'bg-emerald-500/10 border-emerald-500/30 text-emerald-300' : 'bg-rose-500/10 border-rose-500/30 text-rose-300'; ?>"><?php echo htmlspecialchars($__msg); ?></div><?php endif; ?>
    <form method="POST" class="bg-slate-900/90 border border-slate-800 rounded-2xl p-5 space-y-4">
        <input type="hidden" name="action_type" value="save_asst">
        <label class="flex items-center gap-3 text-sm text-white font-bold cursor-pointer"><input type="checkbox" name="enabled" value="1" <?php echo $__en ? 'checked' : ''; ?>> Ligar o assistente</label>
        <label class="text-xs text-slate-300 space-y-1 block">Números autorizados (com DDD, separados por vírgula)
            <input type="text" name="allowed" value="<?php echo htmlspecialchars($__al); ?>" placeholder="16999990000, 16988880000" class="w-full px-3 py-2 bg-slate-950 border border-slate-800 rounded-xl text-white"></label>
        <label class="text-xs text-slate-300 space-y-1 block">Máximo de respostas por dia
            <input type="number" name="cap" min="10" max="1000" value="<?php echo $__cap; ?>" class="w-full sm:w-40 px-3 py-2 bg-slate-950 border border-slate-800 rounded-xl text-white"></label>
        <?php if (!empty($__conflict)): ?><label class="flex items-start gap-3 text-xs text-amber-300 cursor-pointer"><input type="checkbox" name="replace" value="1" class="mt-0.5"> Substituir o aviso de mensagens que já existe neste WhatsApp (a outra integração deixará de receber mensagens).</label><?php endif; ?>
        <div class="flex flex-wrap gap-3">
            <button type="submit" style="white-space:nowrap !important;flex-shrink:0 !important;width:auto !important" class="px-4 py-2 bg-emerald-600 hover:bg-emerald-500 text-white font-extrabold rounded-xl text-sm"><i class="fa-solid fa-floppy-disk"></i> Salvar</button>
            <button type="submit" name="action_type" value="test_asst" style="white-space:nowrap !important;flex-shrink:0 !important;width:auto !important" class="px-4 py-2 bg-slate-800 hover:bg-slate-700 text-white font-bold rounded-xl text-sm"><i class="fa-solid fa-paper-plane"></i> Enviar mensagem de teste</button>
        </div>
    </form>
    <div class="bg-slate-900/90 border border-slate-800 rounded-2xl p-5">
        <div class="flex flex-wrap items-center justify-between gap-2 mb-3">
            <div class="text-xs font-bold uppercase tracking-wider text-slate-400">Conversa ao vivo</div>
            <span class="text-[11px] text-slate-500"><?php echo $__last ? 'Último aviso do WhatsApp recebido em ' . htmlspecialchars(date('d/m H:i', strtotime($__last))) : 'Nenhum aviso do WhatsApp recebido ainda'; ?></span>
        </div>
        <?php if (!$__log): ?><p class="text-sm text-slate-400">Nenhuma mensagem ainda. Quando um número autorizado escrever para o WhatsApp conectado, a conversa aparece aqui.</p><?php else: ?>
        <div class="space-y-2 max-h-[28rem] overflow-auto pr-1">
        <?php foreach (array_reverse($__log) as $l): $in = $l['dir'] === 'in'; ?>
            <div class="flex <?php echo $in ? 'justify-start' : 'justify-end'; ?>">
                <div class="max-w-[85%] rounded-2xl px-3 py-2 text-sm <?php echo $in ? 'bg-slate-800 text-slate-100 rounded-bl-sm' : 'bg-emerald-600/90 text-white rounded-br-sm'; ?>">
                    <div class="text-[10px] opacity-70 mb-0.5"><?php echo $in ? htmlspecialchars($l['phone']) : 'Assistente'; ?> · <?php echo htmlspecialchars(date('d/m H:i', strtotime($l['at']))); ?><?php echo in_array($l['status'], ['recebida', 'ok'], true) ? '' : ' · ' . htmlspecialchars($l['status']); ?></div>
                    <?php echo nl2br(htmlspecialchars(mb_substr($l['text'], 0, 600))); ?>
                </div>
            </div>
        <?php endforeach; ?></div><?php endif; ?>
    </div>
    <?php if ($__raw): ?><details class="bg-slate-900/90 border border-slate-800 rounded-2xl p-5"><summary class="text-xs font-bold uppercase tracking-wider text-slate-400 cursor-pointer">Diagnóstico: últimos avisos recebidos do WhatsApp</summary>
        <?php foreach ($__raw as $r): ?><pre class="mt-2 text-[11px] text-slate-400 whitespace-pre-wrap break-all"><?php echo htmlspecialchars($r['at'] . "\n" . $r['body']); ?></pre><?php endforeach; ?></details><?php endif; ?>
</div>
