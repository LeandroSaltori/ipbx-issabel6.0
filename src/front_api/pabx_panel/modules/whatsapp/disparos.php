<?php
/**
 * IPbx Prisma - Módulo Disparo Rápido de Teste WhatsApp
 */

$test_result = null; $sched_note = '';
waSchedEnsure();
// Cancelar um envio agendado (feito pelo painel ou pelo Chat IA)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['cancel_wa_sched']) && hasUserPermission('send_whatsapp')) {
    $c = $db->prepare("UPDATE wa_scheduled SET status = 'cancelled' WHERE id = ? AND status = 'pending'"); $c->execute([(int)$_POST['cancel_wa_sched']]);
    if ($c->rowCount()) { panelRevBump('wa_sends'); if (function_exists('logUserAction')) logUserAction('Cancelou WhatsApp agendado #' . (int)$_POST['cancel_wa_sched'], '', 'WHATSAPP'); }
}
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action_send_manual_test'])) {
    $phone = trim($_POST['phone']);
    $msg = trim($_POST['message']);
    $when = trim((string)($_POST['agendar_para'] ?? ''));
    if ($when !== '' && !empty($phone) && !empty($msg) && hasUserPermission('send_whatsapp')) {
        $ts = strtotime(str_replace('T', ' ', $when));
        $ph = aiNormPhone($phone);
        if (!$ts || $ts < time() - 60) { $test_result = ['success' => false, 'error' => 'Escolha uma data e hora no futuro para agendar.']; }
        elseif ($ph === '') { $test_result = ['success' => false, 'error' => 'Número inválido. Informe DDD + número.']; }
        elseif (!empty($_FILES['anexo']['tmp_name'])) { $test_result = ['success' => false, 'error' => 'Envios com anexo não podem ser agendados. Envie agora ou retire o anexo.']; }
        else {
            $u = function_exists('getLoggedUser') ? getLoggedUser() : null;
            $db->prepare("INSERT INTO wa_scheduled (phone, contact_name, message, send_at, source, created_by) VALUES (?,?,?,?,?,?)")
               ->execute([$ph, aiRegisteredContact($ph), $msg, date('Y-m-d H:i:00', $ts), 'Painel', (string)($u['name'] ?? $u['username'] ?? '')]);
            panelRevBump('wa_sends');
            $test_result = ['success' => true]; $sched_note = 'Mensagem agendada para ' . date('d/m/Y H:i', $ts) . '. Ela aparece na lista abaixo.';
        }
    } elseif (!empty($phone) && !empty($msg)) {
        $test_result = sendWhatsAppAPI($phone, $msg, 'MANUAL_TEST', '9999', 'DISPARO_MANUAL');
        // Anexo opcional (foto, áudio, vídeo ou documento): vai no tipo certo quando o envio é pelo WhatsApp direto
        if (!empty($test_result['success']) && !empty($_FILES['anexo']['tmp_name']) && is_uploaded_file($_FILES['anexo']['tmp_name'])) {
            $f = $_FILES['anexo'];
            if ($f['size'] > 16 * 1024 * 1024) {
                $test_result = ['success' => false, 'error' => 'Mensagem enviada, mas o anexo passa de 16 MB e não foi enviado.'];
            } else {
                $mime = (function_exists('mime_content_type') ? (string)@mime_content_type($f['tmp_name']) : '') ?: 'application/octet-stream';
                $fr = sendWhatsAppFile($phone, (string)$f['name'], (string)file_get_contents($f['tmp_name']), $mime, '', 'MANUAL_TEST', '9999', 'DISPARO_MANUAL_ARQUIVO', 'auto');
                if (empty($fr['success'])) $test_result = ['success' => false, 'error' => 'Mensagem enviada, mas o anexo falhou: ' . ($fr['error'] ?: 'erro desconhecido')];
            }
        }
    }
}
?>

<div class="space-y-6">
    <div class="bg-slate-900/90 border border-slate-800 rounded-xl p-5 shadow-xl space-y-4 max-w-2xl">
        <div class="border-b border-slate-800 pb-3">
            <h3 class="text-sm font-bold text-white flex items-center gap-2">
                <i class="fa-solid fa-paper-plane text-indigo-400"></i> Disparo Rápido de Teste de WhatsApp
            </h3>
            <span class="text-xs text-slate-400">Envie uma mensagem instantânea de teste para validar sua conexão de envio de WhatsApp</span>
        </div>

        <?php if ($test_result !== null): ?>
            <div class="p-4 rounded-xl text-xs font-bold <?php echo $test_result['success'] ? 'bg-emerald-500/10 text-emerald-400 border border-emerald-500/20' : 'bg-rose-500/10 text-rose-400 border border-rose-500/20'; ?>">
                <div class="flex items-center gap-2">
                    <i class="fa-solid <?php echo $test_result['success'] ? 'fa-circle-check' : 'fa-circle-exclamation'; ?>"></i>
                    <span><?php echo $test_result['success'] ? ($sched_note !== '' ? htmlspecialchars($sched_note) : 'Mensagem enviada com sucesso para o WhatsApp!') : 'Erro no envio: ' . htmlspecialchars($test_result['error']); ?></span>
                </div>
                <?php if (!empty($test_result['raw'])): ?>
                    <pre class="mt-2 p-2 bg-slate-950 rounded text-[10px] text-slate-400 font-mono overflow-x-auto"><?php echo htmlspecialchars($test_result['raw']); ?></pre>
                <?php endif; ?>
            </div>
        <?php endif; ?>

        <form method="POST" action="" enctype="multipart/form-data" class="space-y-4 text-xs">
            <input type="hidden" name="action_send_manual_test" value="1">

            <div>
                <label class="block font-bold text-slate-300 mb-1">Telefone WhatsApp Destino (Com DDD)</label>
                <input type="text" name="phone" placeholder="Ex: 11999998888" required
                       class="w-full px-3 py-2 bg-slate-950 border border-slate-800 rounded-lg text-white font-mono focus:border-brand-500 focus:outline-none">
            </div>

            <div>
                <label class="block font-bold text-slate-300 mb-1">Conteúdo da Mensagem de Teste</label>
                <textarea name="message" rows="4" required class="w-full px-3 py-2 bg-slate-950 border border-slate-800 rounded-lg text-white focus:border-brand-500 focus:outline-none">Olá! Esta é uma mensagem de teste enviada diretamente pelo Painel IPbx Prisma x Prismabot para validação da API.</textarea>
            </div>

            <div>
                <label class="block font-bold text-slate-300 mb-1">Anexo (opcional: foto, áudio, vídeo ou documento, até 16 MB)</label>
                <input type="file" name="anexo" class="w-full text-slate-300 file:mr-3 file:px-3 file:py-2 file:rounded-lg file:border-0 file:bg-slate-700 file:text-white">
            </div>

            <div>
                <label class="block font-bold text-slate-300 mb-1">Agendar para (opcional)</label>
                <input type="datetime-local" name="agendar_para" class="px-3 py-2 bg-slate-950 border border-slate-800 rounded-lg text-white font-mono focus:border-brand-500 focus:outline-none">
                <span class="block text-[10px] text-slate-500 mt-1">Deixe em branco para enviar agora. Mensagens com anexo não podem ser agendadas.</span>
            </div>

            <button type="submit" class="px-6 py-2.5 bg-brand-600 hover:bg-brand-500 text-white font-bold rounded-lg transition shadow-lg shadow-brand-500/20" style="white-space:nowrap !important;flex-shrink:0 !important;width:auto !important;overflow:visible !important;text-overflow:clip !important">
                Disparar ou Agendar Mensagem
            </button>
        </form>
    </div>

    <!-- Envios agendados (painel e Chat IA): atualiza sozinho quando algo muda -->
    <div class="bg-slate-900/90 border border-slate-800 rounded-xl p-5 shadow-xl space-y-3">
        <div class="border-b border-slate-800 pb-3">
            <h3 class="text-sm font-bold text-white flex items-center gap-2"><i class="fa-solid fa-clock text-amber-400"></i> Envios Agendados</h3>
            <span class="text-xs text-slate-400">Mensagens programadas pelo painel ou pelo Chat IA. As já enviadas ficam registradas também no Histórico de Envios.</span>
        </div>
        <?php $__sched = $db->query("SELECT * FROM wa_scheduled ORDER BY CASE status WHEN 'pending' THEN 0 ELSE 1 END, CASE status WHEN 'pending' THEN send_at END ASC, id DESC LIMIT 40")->fetchAll(PDO::FETCH_ASSOC); ?>
        <?php if (!$__sched): ?>
            <div class="text-xs text-slate-400 italic">Nenhum envio agendado.</div>
        <?php else: ?>
        <div class="overflow-x-auto"><table class="w-full text-xs text-left">
            <thead><tr class="text-slate-400 border-b border-slate-800"><th class="py-2 pr-3">Quando</th><th class="py-2 pr-3">Para</th><th class="py-2 pr-3">Mensagem</th><th class="py-2 pr-3">Origem</th><th class="py-2 pr-3">Situação</th><th class="py-2"></th></tr></thead>
            <tbody>
            <?php $__lbl = ['pending' => ['Aguardando', 'text-amber-300'], 'sending' => ['Enviando', 'text-blue-300'], 'sent' => ['Enviado', 'text-emerald-300'], 'failed' => ['Falhou', 'text-rose-300'], 'cancelled' => ['Cancelado', 'text-slate-400']]; ?>
            <?php foreach ($__sched as $__r): $__l = $__lbl[$__r['status']] ?? [$__r['status'], 'text-slate-300']; ?>
                <tr class="border-b border-slate-800/60">
                    <td class="py-2 pr-3 font-mono text-slate-300 whitespace-nowrap"><?php echo date('d/m/Y H:i', strtotime($__r['send_at'])); ?></td>
                    <td class="py-2 pr-3 text-white"><?php echo htmlspecialchars(trim($__r['contact_name'] . ' ' . formatPhoneNumber($__r['phone']))); ?></td>
                    <td class="py-2 pr-3 text-slate-300 max-w-xs truncate" title="<?php echo htmlspecialchars($__r['message']); ?>"><?php echo htmlspecialchars(mb_substr($__r['message'], 0, 70)); ?></td>
                    <td class="py-2 pr-3"><span class="px-2 py-0.5 rounded <?php echo $__r['source'] === 'Chat IA' ? 'bg-purple-500/15 text-purple-300' : 'bg-slate-800 text-slate-300'; ?>"><?php echo htmlspecialchars($__r['source']); ?></span></td>
                    <td class="py-2 pr-3 font-bold <?php echo $__l[1]; ?>"><?php echo $__l[0]; ?><?php if ($__r['status'] === 'failed' && $__r['error'] !== ''): ?><span class="block font-normal text-[10px] text-rose-300/80"><?php echo htmlspecialchars($__r['error']); ?></span><?php endif; ?></td>
                    <td class="py-2 text-right"><?php if ($__r['status'] === 'pending' && hasUserPermission('send_whatsapp')): ?>
                        <form method="POST" style="display:inline"><input type="hidden" name="cancel_wa_sched" value="<?php echo (int)$__r['id']; ?>"><button type="submit" style="white-space:nowrap !important;flex-shrink:0 !important;width:auto !important;overflow:visible !important;text-overflow:clip !important" class="px-3 py-1 bg-slate-800 hover:bg-rose-600/80 text-slate-200 rounded-lg border border-slate-700 font-bold">Cancelar</button></form>
                    <?php endif; ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table></div>
        <?php endif; ?>
    </div>
</div>
