<?php
/**
 * IPbx Prisma - Módulo Regras WhatsApp URA & NPS v2.1
 * Gerenciamento de templates editáveis com valores padrões de fábrica, variáveis interativas e Sandbox de Teste Real.
 */

// Definições de Fábrica (Templates Padrões Predefinidos)
$DEFAULT_TEMPLATES = [
    'queue_abandon_msg_default' => "⚠️ *Notificação IPbx Prisma*\n\nOlá! Vimos que você ligou para o setor *{NOME_FILA}* às *{DATA_HORA}* e a chamada não pôde ser atendida no momento. Em breve entraremos em contato!",
    'queue_exit_whatsapp_msg'  => "📲 *Atendimento Prisma WhatsApp*\n\nOlá! Recebemos sua solicitação via PABX às *{DATA_HORA}*. Em instantes um de nossos atendentes dará sequência ao seu atendimento por este canal.",
    'missed_agent_msg'          => "⚠️ *Notificação de Chamada Perdida*\n\nOlá *{ATENDENTE}*! O cliente número *{CLIENTE}* tentou ligar no seu ramal *{RAMAL}* às *{DATA_HORA}* e a chamada não foi atendida.",
    'missed_client_msg'         => "🎙️ *Notificação de Chamada - IPbx Prisma*\n\nInformações da ligação:\n• Cliente: *{CLIENTE}*\n• Atendente: *{ATENDENTE}* (Ramal *{RAMAL}*)\n• Data/Hora: *{DATA_HORA}*\n\nO registro de áudio/gravação da chamada está disponível no sistema.",
    'nps_msg'                   => "⭐ *Pesquisa de Satisfação - IPbx Prisma*\n\nOlá! Como você avalia o atendimento recebido pelo atendente *{ATENDENTE}* (Ramal *{RAMAL}*) às *{DATA_HORA}*?\n\nResponda com uma nota de 1 (Péssimo) a 5 (Excelente)."
];

$msg = '';
$msg_type = 'success';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['action_save_rules'])) {
        $rules_to_save = [
            'queue_abandon_msg_default' => trim($_POST['queue_abandon_msg_default'] ?? ''),
            'queue_exit_whatsapp_msg'  => trim($_POST['queue_exit_whatsapp_msg'] ?? ''),
            'missed_agent_msg'          => trim($_POST['missed_agent_msg'] ?? ''),
            'missed_client_msg'         => trim($_POST['missed_client_msg'] ?? ''),
            'nps_enabled'               => isset($_POST['nps_enabled']) ? '1' : '0',
            'nps_msg'                   => trim($_POST['nps_msg'] ?? '')
        ];

        $stmt_r = $db->prepare("INSERT OR REPLACE INTO integration_rules (key_name, value_val) VALUES (:key, :val)");
        foreach ($rules_to_save as $rk => $rv) {
            $stmt_r->execute([':key' => $rk, ':val' => $rv]);
        }

        if (function_exists('syncCustomDestinationsWithAsterisk')) {
            $syncRes = syncCustomDestinationsWithAsterisk();
            if (!empty($syncRes['success'])) {
                $msg = "Regras de mensagens salvas e sincronizadas automaticamente no PABX Asterisk!";
            } else {
                $msg = "Regras de mensagens salvas com sucesso! (Aviso PABX: " . $syncRes['message'] . ")";
            }
        } else {
            $msg = "Regras de mensagens do WhatsApp atualizadas com sucesso!";
        }

        if (function_exists('pabx_log')) {
            pabx_log('whatsapp', 'INFO', "Regras de Mensagens URA & NPS salvas com sucesso", $rules_to_save);
        }
    } elseif (isset($_POST['action_restore_defaults'])) {
        $stmt_r = $db->prepare("INSERT OR REPLACE INTO integration_rules (key_name, value_val) VALUES (:key, :val)");
        foreach ($DEFAULT_TEMPLATES as $rk => $rv) {
            $stmt_r->execute([':key' => $rk, ':val' => $rv]);
        }
        $msg = "Modelos de mensagens restaurados com sucesso para os padrões de fábrica do IPbx Prisma!";
        if (function_exists('pabx_log')) {
            pabx_log('whatsapp', 'INFO', "Restaurados modelos de mensagens de fábrica do WhatsApp");
        }
    } elseif (isset($_POST['action_save_queue_abandon'])) {
        $q_en  = $_POST['q_abandon'] ?? [];
        $q_sup = $_POST['q_supervisor'] ?? [];
        $q_msg = $_POST['q_msg'] ?? [];
        $upd = $db->prepare("UPDATE queues_config SET abandon_enabled = :en, supervisor_whatsapp = :sup, abandon_msg = :m, updated_at = CURRENT_TIMESTAMP WHERE queue_number = :q");
        $n = 0;
        foreach ($db->query("SELECT queue_number FROM queues_config")->fetchAll(PDO::FETCH_COLUMN) as $qn) {
            $upd->execute([
                ':en'  => isset($q_en[$qn]) ? 1 : 0,
                ':sup' => preg_replace('/\D/', '', (string)($q_sup[$qn] ?? '')),
                ':m'   => trim((string)($q_msg[$qn] ?? '')),
                ':q'   => $qn
            ]);
            $n++;
        }
        $msg = "Abandono de fila salvo para {$n} fila(s).";
        if (function_exists('pabx_log')) pabx_log('whatsapp', 'INFO', "Configuração de abandono por fila atualizada ({$n} filas)");
    } elseif (isset($_POST['action_test_rule_wa'])) {
        $test_phone = trim($_POST['test_phone'] ?? '');
        $test_rule  = trim($_POST['test_rule_type'] ?? 'queue_abandon_msg_default');

        if (empty($test_phone)) {
            $msg = "Por favor, informe o número de WhatsApp (com DDD) para realizar o disparo de teste.";
            $msg_type = 'error';
        } else {
            $clean_test_phone = preg_replace('/\D/', '', $test_phone);
            if (strlen($clean_test_phone) >= 10 && substr($clean_test_phone, 0, 2) !== '55') {
                $clean_test_phone = '55' . $clean_test_phone;
            }

            $tpl = getRule($test_rule) ?: ($DEFAULT_TEMPLATES[$test_rule] ?? $DEFAULT_TEMPLATES['queue_abandon_msg_default']);
            $data_hora = date('d/m/Y H:i:s');

            $msg_processed = str_replace(
                ['{CLIENTE}', '{NOME_FILA}', '{ATENDENTE}', '{RAMAL}', '{DATA_HORA}'],
                [$clean_test_phone, 'Suporte Técnico', 'Atendente Prisma', '201', $data_hora],
                $tpl
            );

            $res = sendWhatsAppAPI($clean_test_phone, $msg_processed, 'TESTE_MANUAL_' . time(), '201', 'TESTE_REGRAS');

            if (!empty($res['success'])) {
                $msg = "🚀 Mensagem de teste enviada com SUCESSO para o WhatsApp " . htmlspecialchars($clean_test_phone) . "! Verifique a mensagem no seu celular.";
                $msg_type = 'success';
            } else {
                $err_detail = !empty($res['error']) ? $res['error'] : 'Erro na comunicação cURL/API Z-PRO';
                $msg = "⚠️ FALHA no disparo de teste para {$clean_test_phone}: " . htmlspecialchars($err_detail);
                $msg_type = 'error';
            }

            if (function_exists('pabx_log')) {
                pabx_log('whatsapp', $msg_type === 'success' ? 'INFO' : 'ERROR', "Disparo de Teste Manual de Regra WhatsApp", [
                    'test_phone' => $clean_test_phone,
                    'rule_type'  => $test_rule,
                    'msg_sent'   => $msg_processed,
                    'response'   => $res
                ]);
            }
        }
    }
}

// Carregar regras do banco de dados (se estiver vazio, usar o padrão de fábrica)
$rule_abandon = getRule('queue_abandon_msg_default') ?: $DEFAULT_TEMPLATES['queue_abandon_msg_default'];
$rule_exit_wa = getRule('queue_exit_whatsapp_msg')  ?: $DEFAULT_TEMPLATES['queue_exit_whatsapp_msg'];
$rule_agent   = getRule('missed_agent_msg')          ?: $DEFAULT_TEMPLATES['missed_agent_msg'];
$rule_client  = getRule('missed_client_msg')         ?: $DEFAULT_TEMPLATES['missed_client_msg'];
$nps_enabled  = getRule('nps_enabled')               ?: '1';
$nps_msg      = getRule('nps_msg')                   ?: $DEFAULT_TEMPLATES['nps_msg'];
?>

<div class="space-y-6">
    <!-- Banner de Alerta / Sucesso / Erro -->
    <?php if (!empty($msg)): ?>
        <div class="p-4 rounded-xl <?php echo $msg_type === 'success' ? 'bg-emerald-500/10 text-emerald-400 border-emerald-500/20' : 'bg-rose-500/10 text-rose-400 border-rose-500/20'; ?> border text-xs font-bold flex items-center gap-2 animate-fade-in">
            <i class="fa-solid <?php echo $msg_type === 'success' ? 'fa-circle-check text-emerald-400' : 'fa-circle-exclamation text-rose-400'; ?> text-base"></i>
            <span><?php echo htmlspecialchars($msg); ?></span>
        </div>
    <?php endif; ?>

    <!-- Guia Rápido de Variáveis Globais -->
    <div class="bg-gradient-to-r from-slate-900 via-slate-900/90 to-brand-950/40 border border-slate-800 rounded-xl p-5 shadow-xl space-y-3">
        <div class="flex items-center justify-between border-b border-slate-800/80 pb-3">
            <h3 class="text-xs font-bold text-white uppercase tracking-wider flex items-center gap-2">
                <i class="fa-solid fa-code text-cyan-400"></i> Guia Interativo de Variáveis Dinâmicas
            </h3>
            <span class="text-[10px] text-slate-400 bg-slate-800 px-2 py-1 rounded-md">Clique em qualquer tag para inserir no texto</span>
        </div>
        <p class="text-xs text-slate-400">
            Você pode usar as variáveis abaixo nos seus modelos. Elas serão substituídas automaticamente em tempo real pelos dados da chamada efetuada no PABX:
        </p>

        <div class="grid grid-cols-2 md:grid-cols-5 gap-2 pt-1">
            <button type="button" onclick="insertVariable('{CLIENTE}')" class="p-2 bg-slate-950/80 hover:bg-slate-800 border border-slate-800 hover:border-cyan-500/50 rounded-lg text-left transition group">
                <div class="font-mono text-cyan-400 font-bold text-xs group-hover:scale-105 transition-transform">{CLIENTE}</div>
                <div class="text-[10px] text-slate-400 mt-0.5">Número do Telefone</div>
            </button>
            <button type="button" onclick="insertVariable('{NOME_FILA}')" class="p-2 bg-slate-950/80 hover:bg-slate-800 border border-slate-800 hover:border-emerald-500/50 rounded-lg text-left transition group">
                <div class="font-mono text-emerald-400 font-bold text-xs group-hover:scale-105 transition-transform">{NOME_FILA}</div>
                <div class="text-[10px] text-slate-400 mt-0.5">Nome da Fila / Setor</div>
            </button>
            <button type="button" onclick="insertVariable('{ATENDENTE}')" class="p-2 bg-slate-950/80 hover:bg-slate-800 border border-slate-800 hover:border-amber-400/50 rounded-lg text-left transition group">
                <div class="font-mono text-amber-400 font-bold text-xs group-hover:scale-105 transition-transform">{ATENDENTE}</div>
                <div class="text-[10px] text-slate-400 mt-0.5">Nome do Agente</div>
            </button>
            <button type="button" onclick="insertVariable('{RAMAL}')" class="p-2 bg-slate-950/80 hover:bg-slate-800 border border-slate-800 hover:border-purple-400/50 rounded-lg text-left transition group">
                <div class="font-mono text-purple-400 font-bold text-xs group-hover:scale-105 transition-transform">{RAMAL}</div>
                <div class="text-[10px] text-slate-400 mt-0.5">Número do Ramal</div>
            </button>
            <button type="button" onclick="insertVariable('{DATA_HORA}')" class="p-2 bg-slate-950/80 hover:bg-slate-800 border border-slate-800 hover:border-pink-400/50 rounded-lg text-left transition group">
                <div class="font-mono text-pink-400 font-bold text-xs group-hover:scale-105 transition-transform">{DATA_HORA}</div>
                <div class="text-[10px] text-slate-400 mt-0.5">Data e Hora Atual</div>
            </button>
        </div>
    </div>

    <!-- Formulário Principal de Edição de Modelos -->
    <form method="POST" action="" id="rules-form" class="space-y-6">
        <input type="hidden" name="action_save_rules" value="1">

        <div class="bg-slate-900/90 border border-slate-800 rounded-xl p-5 shadow-xl space-y-6">
            <div class="flex items-center justify-between border-b border-slate-800 pb-3">
                <div>
                    <h3 class="text-sm font-bold text-white flex items-center gap-2">
                        <i class="fa-solid fa-wand-magic-sparkles text-emerald-400"></i> Modelos de Notificação WhatsApp (URA, Filas & Transbordo)
                    </h3>
                    <span class="text-xs text-slate-400">Edite as mensagens disparadas pelo PABX Asterisk para clientes e atendentes</span>
                </div>
                <button type="submit" name="action_restore_defaults" onclick="return confirm('Deseja restaurar todas as mensagens para o padrão de fábrica da Prisma Telecom?')" class="px-3 py-1.5 bg-slate-800 hover:bg-slate-700 text-slate-300 text-[11px] font-bold rounded-lg border border-slate-700 transition flex items-center gap-1.5">
                    <i class="fa-solid fa-rotate-left text-amber-400"></i> Restaurar Padrões de Fábrica
                </button>
            </div>

            <div class="grid grid-cols-1 md:grid-cols-2 gap-6 text-xs">
                <!-- Abandon da Fila -->
                <div class="space-y-2">
                    <div class="flex items-center justify-between">
                        <label class="font-bold text-slate-300 flex items-center gap-1.5">
                            <i class="fa-solid fa-phone-slash text-rose-400"></i> Mensagem Abandono de Fila
                        </label>
                        <span class="text-[10px] text-slate-500 font-mono">ext-prisma-no-answer</span>
                    </div>
                    <textarea id="txt_queue_abandon_msg_default" name="queue_abandon_msg_default" rows="4" class="w-full px-3 py-2.5 bg-slate-950 border border-slate-800 rounded-xl text-white focus:border-brand-500 focus:outline-none font-sans leading-relaxed"><?php echo htmlspecialchars($rule_abandon); ?></textarea>
                </div>

                <!-- Saída de URA para WhatsApp -->
                <div class="space-y-2">
                    <div class="flex items-center justify-between">
                        <label class="font-bold text-slate-300 flex items-center gap-1.5">
                            <i class="fa-solid fa-route text-cyan-400"></i> Mensagem Transbordo URA / WhatsApp
                        </label>
                        <span class="text-[10px] text-slate-500 font-mono">ext-prisma-transbordo</span>
                    </div>
                    <textarea id="txt_queue_exit_whatsapp_msg" name="queue_exit_whatsapp_msg" rows="4" class="w-full px-3 py-2.5 bg-slate-950 border border-slate-800 rounded-xl text-white focus:border-brand-500 focus:outline-none font-sans leading-relaxed"><?php echo htmlspecialchars($rule_exit_wa); ?></textarea>
                </div>

                <!-- Notificação Atendente Chamada Perdida -->
                <div class="space-y-2">
                    <div class="flex items-center justify-between">
                        <label class="font-bold text-slate-300 flex items-center gap-1.5">
                            <i class="fa-solid fa-user-clock text-amber-400"></i> Notificação ao Atendente (Chamada Perdida)
                        </label>
                        <span class="text-[10px] text-slate-500 font-mono">Notificação Interna</span>
                    </div>
                    <textarea id="txt_missed_agent_msg" name="missed_agent_msg" rows="4" class="w-full px-3 py-2.5 bg-slate-950 border border-slate-800 rounded-xl text-white focus:border-brand-500 focus:outline-none font-sans leading-relaxed"><?php echo htmlspecialchars($rule_agent); ?></textarea>
                </div>

                <!-- Envio de Áudio de Gravação ao Cliente -->
                <div class="space-y-2">
                    <div class="flex items-center justify-between">
                        <label class="font-bold text-slate-300 flex items-center gap-1.5">
                            <i class="fa-solid fa-file-audio text-purple-400"></i> Envio de Informações & Gravação de Áudio
                        </label>
                        <span class="text-[10px] text-slate-500 font-mono">ext-prisma-audio</span>
                    </div>
                    <textarea id="txt_missed_client_msg" name="missed_client_msg" rows="4" class="w-full px-3 py-2.5 bg-slate-950 border border-slate-800 rounded-xl text-white focus:border-brand-500 focus:outline-none font-sans leading-relaxed"><?php echo htmlspecialchars($rule_client); ?></textarea>
                </div>
            </div>
        </div>

        <!-- Regras NPS -->
        <div class="bg-slate-900/90 border border-slate-800 rounded-xl p-5 shadow-xl space-y-4">
            <div class="flex items-center justify-between border-b border-slate-800 pb-3">
                <div>
                    <h3 class="text-sm font-bold text-white flex items-center gap-2">
                        <i class="fa-solid fa-star text-amber-400"></i> Pesquisa de Satisfação NPS Pós-Atendimento
                    </h3>
                    <span class="text-xs text-slate-400">Mensagem enviada no encerramento da chamada ou Custom Destination <code class="text-slate-300">ext-prisma-nps</code></span>
                </div>
                <label class="flex items-center gap-2 text-xs font-bold text-slate-300 cursor-pointer bg-slate-950 border border-slate-800 px-3 py-1.5 rounded-lg">
                    <input type="checkbox" name="nps_enabled" value="1" <?php echo $nps_enabled === '1' ? 'checked' : ''; ?> class="accent-brand-500 rounded w-4 h-4">
                    <span>Ativar Envio de NPS</span>
                </label>
            </div>

            <div class="space-y-2 text-xs">
                <textarea id="txt_nps_msg" name="nps_msg" rows="3" class="w-full px-3 py-2.5 bg-slate-950 border border-slate-800 rounded-xl text-white focus:border-brand-500 focus:outline-none font-sans leading-relaxed"><?php echo htmlspecialchars($nps_msg); ?></textarea>
            </div>
        </div>

        <div class="flex items-center justify-end gap-3">
            <button type="submit" class="px-6 py-3 bg-brand-600 hover:bg-brand-500 text-white font-bold rounded-xl transition shadow-lg shadow-brand-500/20 text-xs flex items-center gap-2">
                <i class="fa-solid fa-floppy-disk"></i>
                <span>Salvar Regras de Integração</span>
            </button>
        </div>
    </form>

    <!-- ABANDONO DE FILA POR FILA (cliente + supervisor) -->
    <?php $__queues_cfg = $db->query("SELECT queue_number, queue_name, abandon_enabled, abandon_msg, supervisor_whatsapp FROM queues_config ORDER BY CAST(queue_number AS UNSIGNED) ASC")->fetchAll(PDO::FETCH_ASSOC); ?>
    <form method="POST" action="" class="bg-slate-900/90 border border-purple-500/30 rounded-xl p-5 shadow-xl space-y-4">
        <div class="border-b border-slate-800/80 pb-3">
            <h3 class="text-sm font-bold text-white flex items-center gap-2"><i class="fa-solid fa-phone-slash text-purple-400"></i> Abandono de Fila</h3>
            <span class="text-xs text-slate-400">Quando o cliente desiste de aguardar na fila, ele recebe a mensagem abaixo e o supervisor é avisado. Em branco, usa o modelo "Abandono de fila" acima.</span>
        </div>
        <?php if (empty($__queues_cfg)): ?>
            <div class="text-xs text-slate-500 py-4 text-center">Nenhuma fila sincronizada. Use "Sincronizar" no topo do painel.</div>
        <?php else: ?>
        <div class="overflow-x-auto">
            <table class="w-full text-xs">
                <thead><tr class="text-slate-400 text-left border-b border-slate-800"><th class="py-2 pr-3">Fila</th><th class="py-2 pr-3">Ativo</th><th class="py-2 pr-3">WhatsApp do supervisor</th><th class="py-2">Mensagem própria (opcional)</th></tr></thead>
                <tbody>
                <?php foreach ($__queues_cfg as $__q): $__qn = htmlspecialchars($__q['queue_number'], ENT_QUOTES); ?>
                    <tr class="border-b border-slate-800/60">
                        <td class="py-2 pr-3 text-white font-bold whitespace-nowrap"><?php echo $__qn; ?> - <?php echo htmlspecialchars($__q['queue_name']); ?></td>
                        <td class="py-2 pr-3"><input type="checkbox" name="q_abandon[<?php echo $__qn; ?>]" value="1" <?php echo !empty($__q['abandon_enabled']) ? 'checked' : ''; ?> class="rounded accent-purple-500"></td>
                        <td class="py-2 pr-3"><input type="text" name="q_supervisor[<?php echo $__qn; ?>]" value="<?php echo htmlspecialchars($__q['supervisor_whatsapp'] ?? '', ENT_QUOTES); ?>" placeholder="5511999998888" class="w-44 px-2 py-1.5 bg-slate-950 border border-slate-800 rounded-lg text-cyan-300 font-mono"></td>
                        <td class="py-2"><input type="text" name="q_msg[<?php echo $__qn; ?>]" value="<?php echo htmlspecialchars($__q['abandon_msg'] ?? '', ENT_QUOTES); ?>" placeholder="Ex.: Olá! Vimos que você ligou para {NOME_FILA}..." class="w-full px-2 py-1.5 bg-slate-950 border border-slate-800 rounded-lg text-slate-200"></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <button type="submit" name="action_save_queue_abandon" style="white-space:nowrap;flex-shrink:0;width:auto" class="px-5 py-2.5 bg-purple-600 hover:bg-purple-500 text-white font-bold rounded-xl text-xs transition"><i class="fa-solid fa-floppy-disk"></i> Salvar abandono de fila</button>
        <?php endif; ?>
    </form>

    <!-- CARD DE TESTE MANUAL DE ENVIOS DE WHATSAPP -->
    <div class="bg-gradient-to-r from-slate-900 via-slate-900 to-emerald-950/40 border border-emerald-500/30 rounded-xl p-5 shadow-2xl space-y-4">
        <div class="border-b border-slate-800/80 pb-3 flex items-center justify-between">
            <div>
                <h3 class="text-sm font-bold text-white flex items-center gap-2">
                    <i class="fa-solid fa-paper-plane text-emerald-400"></i> Sandbox de Teste de Disparo Real de WhatsApp
                </h3>
                <span class="text-xs text-slate-400">Insira um número de celular abaixo para validar a entrega da mensagem via API Z-PRO em tempo real</span>
            </div>
            <span class="px-2.5 py-1 rounded-full bg-emerald-500/20 border border-emerald-500/40 text-emerald-300 text-[10px] font-bold uppercase tracking-wider">
                Validação em Tempo Real
            </span>
        </div>

        <form method="POST" action="" class="grid grid-cols-1 md:grid-cols-3 gap-4 items-end text-xs">
            <input type="hidden" name="action_test_rule_wa" value="1">

            <!-- Número de Celular de Teste -->
            <div class="space-y-1.5">
                <label class="block font-bold text-slate-300">Número de WhatsApp de Destino (com DDD)</label>
                <div class="relative">
                    <span class="absolute inset-y-0 left-0 pl-3 flex items-center text-slate-500">
                        <i class="fa-brands fa-whatsapp text-emerald-400 text-sm"></i>
                    </span>
                    <input type="text" name="test_phone" placeholder="Ex: 5516991637028" class="w-full pl-9 pr-3 py-2.5 bg-slate-950 border border-slate-800 rounded-xl text-white focus:border-emerald-500 focus:outline-none font-mono">
                </div>
            </div>

            <!-- Modelo de Regra a Testar -->
            <div class="space-y-1.5">
                <label class="block font-bold text-slate-300">Modelo de Mensagem a Testar</label>
                <select name="test_rule_type" class="w-full px-3 py-2.5 bg-slate-950 border border-slate-800 rounded-xl text-white focus:border-emerald-500 focus:outline-none">
                    <option value="queue_abandon_msg_default">⚠️ Abandono de Fila (ext-prisma-no-answer)</option>
                    <option value="queue_exit_whatsapp_msg">📲 Transbordo URA / WhatsApp (ext-prisma-transbordo)</option>
                    <option value="missed_agent_msg">⚠️ Notificação ao Atendente do Ramal</option>
                    <option value="missed_client_msg">🎙️ Envio de Gravação de Áudio (ext-prisma-audio)</option>
                    <option value="nps_msg">⭐ Pesquisa de Satisfação NPS (ext-prisma-nps)</option>
                </select>
            </div>

            <!-- Botão de Disparo -->
            <div>
                <button type="submit" class="w-full py-2.5 px-4 bg-emerald-600 hover:bg-emerald-500 text-white font-bold rounded-xl transition shadow-lg shadow-emerald-500/20 text-xs flex items-center justify-center gap-2">
                    <i class="fa-solid fa-paper-plane"></i>
                    <span>Disparar Mensagem de Teste</span>
                </button>
            </div>
        </form>
    </div>
</div>

<script>
let lastActiveTextarea = null;

document.querySelectorAll('textarea').forEach(ta => {
    ta.addEventListener('focus', function() {
        lastActiveTextarea = this;
    });
});

function insertVariable(varTag) {
    if (!lastActiveTextarea) {
        lastActiveTextarea = document.getElementById('txt_queue_abandon_msg_default');
    }
    if (!lastActiveTextarea) return;

    const startPos = lastActiveTextarea.selectionStart;
    const endPos   = lastActiveTextarea.selectionEnd;
    const oldVal   = lastActiveTextarea.value;

    lastActiveTextarea.value = oldVal.substring(0, startPos) + varTag + oldVal.substring(endPos, oldVal.length);
    lastActiveTextarea.focus();
    lastActiveTextarea.selectionStart = startPos + varTag.length;
    lastActiveTextarea.selectionEnd   = startPos + varTag.length;
}
</script>
