<?php
/** IPbx Prisma - Provisionamento de telefones IP por MAC: cadastro (MAC -> ramal) e URL para colocar no aparelho. */
provEnsureTable();
$__msg = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && hasUserPermission('manage_settings')) {
    $a = $_POST['prov_action'] ?? '';
    if ($a === 'add') {
        $mac = strtolower(preg_replace('/[^0-9a-fA-F]/', '', $_POST['mac'] ?? ''));
        $ext = preg_replace('/\D/', '', $_POST['ext'] ?? '');
        $ven = ($_POST['vendor'] ?? '') === 'grandstream' ? 'grandstream' : 'yealink';
        if (strlen($mac) !== 12 || $ext === '') $__msg = 'Informe um MAC válido (12 caracteres) e o ramal.';
        else {
            $db->prepare("INSERT OR REPLACE INTO prov_devices (mac, ext, vendor, label, created_at) VALUES (?,?,?,?,?)")
               ->execute([$mac, $ext, $ven, mb_substr(trim($_POST['label'] ?? ''), 0, 60), date('Y-m-d H:i:s')]);
            $__msg = 'Telefone cadastrado.';
        }
    } elseif ($a === 'del') {
        $db->prepare("DELETE FROM prov_devices WHERE mac = ?")->execute([strtolower(preg_replace('/[^0-9a-f]/i', '', $_POST['mac'] ?? ''))]);
        $__msg = 'Telefone removido.';
    } elseif ($a === 'server') {
        saveSetting('prov_server', trim($_POST['prov_server'] ?? ''));
        $__msg = 'Servidor salvo.';
    } elseif ($a === 'newtoken') {
        saveSetting('prov_token', bin2hex(random_bytes(12)));
        $__msg = 'Novo token gerado. Atualize a URL nos telefones.';
    }
}
$rows = $db->query("SELECT * FROM prov_devices ORDER BY ext")->fetchAll(PDO::FETCH_ASSOC);
$https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
$url = $https . '://' . ($_SERVER['HTTP_HOST'] ?? 'SERVIDOR') . dirname($_SERVER['SCRIPT_NAME']) . '/provisioning.php/' . provToken() . '/';
$btn = 'style="white-space:nowrap;flex-shrink:0;width:auto;overflow:visible"';
$inp = 'class="w-full bg-slate-950 border border-slate-700 rounded-lg px-3 py-2 text-xs text-slate-100"';
$canEdit = hasUserPermission('manage_settings');
?>
<div class="space-y-5">
    <div class="bg-slate-900/90 border border-slate-800 rounded-2xl p-5">
        <h2 class="text-lg font-extrabold text-white flex items-center gap-2"><i class="fa-solid fa-phone-volume text-emerald-400"></i> Provisionamento de Telefones IP</h2>
        <p class="text-xs text-slate-400 mt-1">Cadastre o MAC do aparelho e o ramal. O telefone busca a configuração sozinho (Yealink e Grandstream).</p>
        <?php if ($__msg): ?><div class="mt-3 text-xs px-3 py-2 rounded-lg bg-emerald-500/10 border border-emerald-500/30 text-emerald-300"><?php echo htmlspecialchars($__msg); ?></div><?php endif; ?>
        <?php if ($canEdit): ?>
        <div class="mt-3 text-xs text-slate-300">URL de provisionamento (coloque no campo "Auto Provision / Config Server" do telefone):
            <div class="mt-1 font-mono bg-slate-950 border border-slate-700 rounded-lg px-3 py-2 break-all text-emerald-300"><?php echo htmlspecialchars($url); ?></div>
            <div class="mt-1 text-amber-300">Esta URL contém o token de acesso e entrega a senha dos ramais cadastrados: use somente na rede interna.</div>
        </div>
        <?php endif; ?>
    </div>
    <?php if ($canEdit): ?>
    <form method="post" class="bg-slate-900/90 border border-slate-800 rounded-2xl p-5 grid grid-cols-1 md:grid-cols-5 gap-3 text-xs items-end">
        <input type="hidden" name="prov_action" value="add">
        <label class="text-slate-300">MAC<input name="mac" <?php echo $inp; ?> placeholder="00:15:65:AA:BB:CC"></label>
        <label class="text-slate-300">Ramal<input name="ext" <?php echo $inp; ?> placeholder="200"></label>
        <label class="text-slate-300">Fabricante<select name="vendor" <?php echo $inp; ?>><option value="yealink">Yealink</option><option value="grandstream">Grandstream</option></select></label>
        <label class="text-slate-300">Nome exibido<input name="label" <?php echo $inp; ?>></label>
        <button class="px-4 py-2 rounded-lg bg-brand-600 hover:bg-brand-500 text-white font-bold" <?php echo $btn; ?>><i class="fa-solid fa-plus"></i> Cadastrar</button>
    </form>
    <?php endif; ?>
    <div class="bg-slate-900/90 border border-slate-800 rounded-2xl p-5 text-xs overflow-x-auto">
        <table class="w-full"><thead><tr class="text-slate-400 text-left"><th class="py-2 pr-3">MAC</th><th class="pr-3">Ramal</th><th class="pr-3">Fabricante</th><th class="pr-3">Nome</th><th class="pr-3">Última busca</th><th></th></tr></thead>
        <tbody class="divide-y divide-slate-800 text-slate-200">
        <?php foreach ($rows as $r): ?><tr><td class="py-2 pr-3 font-mono"><?php echo htmlspecialchars(implode(':', str_split($r['mac'], 2))); ?></td><td class="pr-3"><?php echo htmlspecialchars($r['ext']); ?></td><td class="pr-3"><?php echo htmlspecialchars(ucfirst($r['vendor'])); ?></td><td class="pr-3"><?php echo htmlspecialchars($r['label']); ?></td><td class="pr-3"><?php echo htmlspecialchars($r['last_fetch'] ?: 'nunca'); ?></td>
            <td><?php if ($canEdit): ?><form method="post" onsubmit="return confirm('Remover este telefone?')"><input type="hidden" name="prov_action" value="del"><input type="hidden" name="mac" value="<?php echo htmlspecialchars($r['mac']); ?>"><button class="px-3 py-1 rounded bg-rose-600/80 hover:bg-rose-500 text-white font-bold" <?php echo $btn; ?>><i class="fa-solid fa-trash"></i> Remover</button></form><?php endif; ?></td></tr><?php endforeach; ?>
        <?php if (!$rows): ?><tr><td colspan="6" class="py-4 text-slate-500">Nenhum telefone cadastrado.</td></tr><?php endif; ?>
        </tbody></table>
    </div>
    <?php if ($canEdit): ?>
    <div class="bg-slate-900/90 border border-slate-800 rounded-2xl p-5 flex flex-wrap gap-4 items-end text-xs">
        <form method="post" class="flex gap-2 items-end"><input type="hidden" name="prov_action" value="server">
            <label class="text-slate-300">Endereço SIP entregue ao telefone (opcional, padrão: IP do servidor)<input name="prov_server" value="<?php echo htmlspecialchars(getSetting('prov_server')); ?>" <?php echo $inp; ?>></label>
            <button class="px-4 py-2 rounded-lg bg-slate-700 hover:bg-slate-600 text-white font-bold" <?php echo $btn; ?>>Salvar</button></form>
        <form method="post" onsubmit="return confirm('Gerar novo token? Os telefones precisarão da nova URL.')"><input type="hidden" name="prov_action" value="newtoken">
            <button class="px-4 py-2 rounded-lg bg-amber-600/80 hover:bg-amber-500 text-white font-bold" <?php echo $btn; ?>><i class="fa-solid fa-key"></i> Gerar novo token</button></form>
    </div>
    <?php endif; ?>
</div>
