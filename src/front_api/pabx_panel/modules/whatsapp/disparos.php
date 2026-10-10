<?php
/**
 * IPbx Prisma - Módulo Disparo Rápido de Teste WhatsApp
 */

$test_result = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action_send_manual_test'])) {
    $phone = trim($_POST['phone']);
    $msg = trim($_POST['message']);
    if (!empty($phone) && !empty($msg)) {
        $test_result = sendWhatsAppAPI($phone, $msg, 'MANUAL_TEST', '9999', 'DISPARO_MANUAL');
    }
}
?>

<div class="space-y-6">
    <div class="bg-slate-900/90 border border-slate-800 rounded-xl p-5 shadow-xl space-y-4 max-w-2xl">
        <div class="border-b border-slate-800 pb-3">
            <h3 class="text-sm font-bold text-white flex items-center gap-2">
                <i class="fa-solid fa-paper-plane text-indigo-400"></i> Disparo Rápido de Teste de WhatsApp
            </h3>
            <span class="text-xs text-slate-400">Envie uma mensagem instantânea de teste para validar sua conexão com a API Prismabot</span>
        </div>

        <?php if ($test_result !== null): ?>
            <div class="p-4 rounded-xl text-xs font-bold <?php echo $test_result['success'] ? 'bg-emerald-500/10 text-emerald-400 border border-emerald-500/20' : 'bg-rose-500/10 text-rose-400 border border-rose-500/20'; ?>">
                <div class="flex items-center gap-2">
                    <i class="fa-solid <?php echo $test_result['success'] ? 'fa-circle-check' : 'fa-circle-exclamation'; ?>"></i>
                    <span><?php echo $test_result['success'] ? 'Mensagem enviada com sucesso para o WhatsApp!' : 'Erro no envio: ' . htmlspecialchars($test_result['error']); ?></span>
                </div>
                <?php if (!empty($test_result['raw'])): ?>
                    <pre class="mt-2 p-2 bg-slate-950 rounded text-[10px] text-slate-400 font-mono overflow-x-auto"><?php echo htmlspecialchars($test_result['raw']); ?></pre>
                <?php endif; ?>
            </div>
        <?php endif; ?>

        <form method="POST" action="" class="space-y-4 text-xs">
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

            <button type="submit" class="px-6 py-2.5 bg-brand-600 hover:bg-brand-500 text-white font-bold rounded-lg transition shadow-lg shadow-brand-500/20">
                Disparar Mensagem de Teste
            </button>
        </form>
    </div>
</div>
