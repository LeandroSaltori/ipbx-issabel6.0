<?php
/** IPbx Prisma - Conversas do Assistente IA (somente leitura, por usuário). */
if (!hasUserPermission('manage_settings')) { echo '<div class="p-6 text-slate-400">Acesso restrito a administradores.</div>'; return; }
global $db;
aiHistoryEnsure();
$__users = $db->query("SELECT username, COUNT(*) n, MAX(created_at) ultimo FROM ai_chat_history GROUP BY username ORDER BY ultimo DESC")->fetchAll(PDO::FETCH_ASSOC);
$__sel = $_GET['usuario'] ?? ($__users[0]['username'] ?? '');
$__msgs = [];
if ($__sel !== '') {
    $st = $db->prepare("SELECT role, content, created_at, archived FROM ai_chat_history WHERE username = ? ORDER BY id DESC LIMIT 300");
    $st->execute([$__sel]);
    $__msgs = array_reverse($st->fetchAll(PDO::FETCH_ASSOC));
    logUserAction('Visualizou conversas do Assistente IA', $__sel, 'CONFIG');
}
$h = function($s) { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); };
?>
<div class="space-y-5">
    <div class="bg-slate-900/90 border border-slate-800 rounded-2xl p-5">
        <h2 class="text-xl font-bold text-white"><i class="fa-solid fa-robot text-brand-400 mr-2"></i>Conversas do Assistente IA</h2>
        <p class="text-slate-400 text-sm mt-1">Veja o que cada usuário perguntou ao assistente. Somente leitura.</p>
    </div>
    <div class="grid md:grid-cols-4 gap-4">
        <div class="bg-slate-900/90 border border-slate-800 rounded-2xl p-3 space-y-1">
            <?php if (!$__users) echo '<div class="text-slate-500 text-sm p-2">Nenhuma conversa registrada.</div>'; ?>
            <?php foreach ($__users as $u): $on = $u['username'] === $__sel; ?>
            <a href="index.php?module=configuracoes&action=chat_ia&usuario=<?php echo urlencode($u['username']); ?>" class="block px-3 py-2 rounded-lg <?php echo $on ? 'bg-brand-500/20 text-brand-400 font-bold' : 'text-slate-300 hover:bg-slate-800/60'; ?>">
                <?php echo $h($u['username']); ?>
                <span class="block text-xs text-slate-500"><?php echo (int)$u['n']; ?> mensagens · <?php echo $h($u['ultimo']); ?></span>
            </a>
            <?php endforeach; ?>
        </div>
        <div class="md:col-span-3 bg-slate-900/90 border border-slate-800 rounded-2xl p-4 space-y-3">
            <?php if (!$__msgs) echo '<div class="text-slate-500 text-sm">Selecione um usuário.</div>'; ?>
            <?php foreach ($__msgs as $m): $u = $m['role'] === 'user'; ?>
            <div class="rounded-xl p-3 text-sm <?php echo $u ? 'bg-slate-800 text-white' : 'bg-brand-500/10 text-slate-200 border border-brand-500/20'; ?>">
                <div class="text-xs text-slate-500 mb-1"><?php echo $u ? 'Usuário' : 'Assistente'; ?> · <?php echo $h($m['created_at']); ?><?php echo !empty($m['archived']) ? ' · conversa encerrada' : ''; ?></div>
                <div style="white-space:pre-wrap;word-break:break-word"><?php echo $h($m['content']); ?></div>
            </div>
            <?php endforeach; ?>
        </div>
    </div>
</div>
