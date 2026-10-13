<?php
/**
 * IPbx Prisma - Configurações de IA & Integrações v8.0 (Redesign Dashboard de IA Universal)
 * Seções organizadas: Copiloto Prismabot, ElevenLabs TTS, Status de Integrações Globais
 */

$msg      = '';
$msg_type = 'success';
$__provLabelsTop = ['openai' => 'OpenAI (GPT)', 'groq' => 'Groq', 'gemini' => 'Google Gemini', 'deepseek' => 'DeepSeek'];

// ─── Salvar Copiloto IA ──────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['action_save_ia'])) {
        $m_chat  = trim($_POST['ai_model_chat']  ?? 'gpt-4o-mini');
        $m_audio = trim($_POST['ai_model_audio'] ?? 'whisper-1');

        if ($m_chat === 'custom' && !empty($_POST['ai_model_chat_custom'])) {
            $m_chat = trim($_POST['ai_model_chat_custom']);
        }
        if ($m_audio === 'custom' && !empty($_POST['ai_model_audio_custom'])) {
            $m_audio = trim($_POST['ai_model_audio_custom']);
        }

        $k_in = trim($_POST['ai_api_key'] ?? '');
        $p_in = trim($_POST['ai_provider'] ?? 'openai');
        // A chave identifica o provedor (gsk_ = Groq, AIza = Gemini)
        if (strpos($k_in, 'gsk_') === 0)     $p_in = 'groq';
        elseif (strpos($k_in, 'AIza') === 0) $p_in = 'gemini';
        $adv = ($_POST['ai_advanced'] ?? '') === '1';   // só grava ajustes se o usuário abriu "Opções avançadas"
        $vals = [
            ':p'  => $p_in,
            ':k'  => $k_in,
            ':b'  => $adv ? trim($_POST['ai_base_url'] ?? '') : '',
            ':mc' => $adv ? $m_chat : '',
            ':ma' => $adv ? $m_audio : '',
            ':ec' => isset($_POST['enable_copilot']) ? '1' : '0',
            ':pr' => $adv ? trim($_POST['ai_custom_prompt'] ?? '') : (string)getSetting('ai_custom_prompt'),
            ':tl' => $adv ? trim($_POST['ai_token_limit'] ?? '1024') : (string)(getSetting('ai_token_limit') ?: '1024'),
        ];
        $db->prepare("INSERT OR REPLACE INTO settings (key_name, value_val) VALUES
            ('ai_provider',:p),('ai_api_key',:k),('ai_base_url',:b),('ai_model_chat',:mc),('ai_model_audio',:ma),('enable_copilot',:ec),('ai_custom_prompt',:pr),('ai_token_limit',:tl)")
           ->execute($vals);
        $msg = "Configurações Universais do Copiloto de IA salvas com sucesso!";
    }

    // ─── Salvar / testar "Quem faz o quê" (provedor e modelo por função da IA) ───
    if (isset($_POST['action_save_ai_roles']) || isset($_POST['action_test_ai_roles']) || isset($_POST['action_test_ai_key'])) {
        $__save = $db->prepare("INSERT OR REPLACE INTO settings (key_name, value_val) VALUES (:k, :v)");
        foreach (array_keys(aiProviderDefaults()) as $__p) {
            $__kv = trim($_POST['ai_key_' . $__p] ?? '');
            if (!empty($_POST['ai_key_clear_' . $__p])) { $__save->execute([':k' => 'ai_key_' . $__p, ':v' => '']); $__save->execute([':k' => 'ai_key_status_' . $__p, ':v' => '']); }
            elseif ($__kv !== '') { $__save->execute([':k' => 'ai_key_' . $__p, ':v' => $__kv]); $__save->execute([':k' => 'ai_key_status_' . $__p, ':v' => '']); }
        }
        foreach (array_keys(aiRoles()) as $__r) {
            $__prov = trim($_POST["ai_role_{$__r}_provider"] ?? '');
            if ($__prov !== '' && !isset(aiProviderDefaults()[$__prov])) $__prov = '';
            $__save->execute([':k' => "ai_role_{$__r}_provider", ':v' => $__prov]);
            $__save->execute([':k' => "ai_role_{$__r}_model", ':v' => trim($_POST["ai_role_{$__r}_model"] ?? '')]);
        }
        if (isset($_POST['action_test_ai_key'])) {
            $__tp = (string)$_POST['action_test_ai_key'];
            $__tr = aiTestProviderKey($__tp);
            $msg = ($__provLabelsTop[$__tp] ?? $__tp) . ': ' . ($__tr['ok'] ? '✅ chave aprovada.' : '❌ ' . $__tr['msg'] . '.');
            if (!$__tr['ok']) $msg_type = 'info';
        }
        if (isset($_POST['ai_adv_present'])) {
            $__save->execute([':k' => 'enable_copilot',    ':v' => isset($_POST['enable_copilot']) ? '1' : '0']);
            $__save->execute([':k' => 'ai_custom_prompt',  ':v' => trim($_POST['ai_custom_prompt'] ?? '')]);
            $__save->execute([':k' => 'ai_token_limit',    ':v' => (string)max(64, min(4000, (int)($_POST['ai_token_limit'] ?? 1024) ?: 1024))]);
        }
        if (!isset($_POST['action_test_ai_key'])) $msg = "Configuração de IA salva! Cada função passa a usar o provedor escolhido (ou o automático).";
        if (isset($_POST['action_test_ai_roles'])) {
            $__res = [];
            foreach (aiRoles() as $__r => $__lbl) {
                $__sel = aiRoleChoice($__r);
                if (!$__sel) { $__res[] = "$__lbl: usa a configuração geral"; continue; }
                $__cfg = aiGetConfig($__r);
                if ($__r === 'audio') {
                    if (!in_array($__cfg['provider'], ['openai', 'groq'], true)) { $__res[] = "$__lbl: ❌ {$__cfg['provider']} não oferece transcrição"; continue; }
                    $__ids = aiAvailableModels($__cfg['provider'], $__cfg['key'], $__cfg['base_url'], true);
                    $__res[] = "$__lbl: " . (($__ids && in_array($__cfg['audio'], $__ids, true)) ? "✅ {$__cfg['provider']} / {$__cfg['audio']}" : "❌ modelo '{$__cfg['audio']}' não disponível para esta chave");
                } else {
                    $__t = aiChatCompletion('Responda apenas: OK', 'ping', 15, $__cfg);
                    $__res[] = "$__lbl: " . ($__t['ok'] ? "✅ {$__cfg['provider']} / {$__cfg['model']}" : "❌ " . $__t['error']);
                }
            }
            $msg = implode(' | ', $__res);
        }
    }

    // ─── Salvar ElevenLabs ────────────────────────────────────────────────────
    if (isset($_POST['action_save_elevenlabs'])) {
        $db->prepare("INSERT OR REPLACE INTO settings (key_name, value_val) VALUES
            ('elevenlabs_api_key',:k),('elevenlabs_voice_id',:v),('elevenlabs_model',:m),('elevenlabs_enabled',:e)")
           ->execute([
               ':k' => trim($_POST['elevenlabs_api_key'] ?? ''),
               ':v' => trim($_POST['elevenlabs_voice_id'] ?? ''),
               ':m' => trim($_POST['elevenlabs_model'] ?? 'eleven_multilingual_v2'),
               ':e' => isset($_POST['elevenlabs_enabled']) ? '1' : '0',
           ]);
        $msg = "Configurações da ElevenLabs salvas com sucesso!";
    }

    // ─── Reset Geral ─────────────────────────────────────────────────────────
    if (isset($_POST['action_reset_defaults'])) {
        $db->exec("DELETE FROM settings");
        $db->exec("DELETE FROM integration_rules");
        $db->exec("INSERT INTO integration_rules (rule_type, trigger_event, enabled, target_number, message_template, updated_at) VALUES
            ('NPS','call_finished',1,'CALLER_ID','Olá! Como você avalia nosso atendimento de 1 a 5 estrelas?',CURRENT_TIMESTAMP),
            ('ATENDENTE_NAO_ATENDEU','no_answer',1,'CALLER_ID','Olá! Desculpe a demora, vimos que você tentou falar conosco. Em breve um atendente entrará em contato.',CURRENT_TIMESTAMP)");
        syncPrismaAssets();
        $msg      = "Todas as configurações foram restauradas para os padrões originais!";
        $msg_type = 'info';
    }
}

// ─── Carregar Valores ─────────────────────────────────────────────────────────
$ai_provider      = getSetting('ai_provider')       ?: 'openai';
$ai_api_key       = getSetting('ai_api_key')        ?: '';
$ai_base_url      = getSetting('ai_base_url')       ?: 'https://api.openai.com/v1';
$ai_model_chat    = getSetting('ai_model_chat')     ?: 'gpt-4o-mini';
$ai_model_audio   = getSetting('ai_model_audio')    ?: 'whisper-1';
$enable_copilot   = getSetting('enable_copilot')    ?: '1';
$ai_custom_prompt = getSetting('ai_custom_prompt') ?: '';
$ai_token_limit   = getSetting('ai_token_limit')   ?: '1024';

$el_api_key       = getSetting('elevenlabs_api_key')  ?: '';
$el_voice_id      = getSetting('elevenlabs_voice_id') ?: '';
$el_model         = getSetting('elevenlabs_model')    ?: 'eleven_multilingual_v2';
$el_enabled       = getSetting('elevenlabs_enabled')  ?: '0';

$ia_tab = isset($_GET['ia_tab']) ? $_GET['ia_tab'] : 'copiloto';

$__provLabels = ['openai' => 'OpenAI (GPT)', 'groq' => 'Groq', 'gemini' => 'Google Gemini', 'deepseek' => 'DeepSeek'];
/** O que cada função está usando de fato agora (sem chamar a internet). */
$__roleSummary = function ($role) use ($__provLabels) {
    $sel = aiRoleChoice($role);
    $defs = aiProviderDefaults();
    if ($sel) { $prov = $sel['provider']; $key = true; $model = $sel['model']; }
    else {
        $k = trim((string)getSetting('ai_api_key')); $prov = getSetting('ai_provider') ?: 'openai';
        if (strpos($k, 'gsk_') === 0) $prov = 'groq'; elseif (strpos($k, 'AIza') === 0) $prov = 'gemini';
        $key = $k !== ''; $model = '';
    }
    if ($model === '') $model = $role === 'audio' ? ($defs[$prov]['audio'] ?? '') : ($defs[$prov]['chat'] ?? '');
    return ['prov' => $__provLabels[$prov] ?? $prov, 'model' => $model, 'ok' => $key];
};
$__anyKey = false;
foreach (array_keys(aiProviderDefaults()) as $__pp) if (aiKeyFor($__pp) !== '') { $__anyKey = true; break; }
?>

<div class="space-y-6">
    <?php if (!empty($msg)): ?>
    <div class="p-4 rounded-xl <?php echo $msg_type === 'info' ? 'bg-blue-500/10 text-blue-300 border-blue-500/30' : 'bg-emerald-500/10 text-emerald-300 border-emerald-500/30'; ?> border text-xs font-bold flex items-center gap-2">
        <i class="fa-solid fa-circle-check"></i> <?php echo htmlspecialchars($msg); ?>
    </div>
    <?php endif; ?>

    <!-- Header Principal + Reset -->
    <div class="bg-slate-900/90 border border-slate-800 rounded-2xl p-6 shadow-xl space-y-4">
        <div class="flex flex-col md:flex-row items-start md:items-center justify-between gap-4">
            <div>
                <h3 class="text-lg font-extrabold text-white flex items-center gap-2.5">
                    <i class="fa-solid fa-microchip text-purple-400 text-xl"></i> Central Universais de Inteligência Artificial & LLMs
                </h3>
                <p class="text-xs text-slate-400 mt-1">Configurações globais para transcrição de áudio, resumos automáticos, análise de qualidade e síntese de voz (ElevenLabs)</p>
            </div>
            
            <form method="POST" onsubmit="return confirm('Resetar TODAS as configurações de IA? Esta ação é irreversível.');">
                <input type="hidden" name="action_reset_defaults" value="1">
                <button type="submit" class="px-3.5 py-2 bg-rose-600/10 hover:bg-rose-600/20 text-rose-400 border border-rose-500/30 rounded-xl text-xs font-bold transition flex items-center gap-1.5 shadow">
                    <i class="fa-solid fa-rotate-left"></i> Restaurar Padrões
                </button>
            </form>
        </div>

        <!-- O que cada função da IA está usando agora -->
        <div class="grid grid-cols-1 sm:grid-cols-2 xl:grid-cols-4 gap-3 pt-2">
            <?php foreach ([['audio','Transcrição de áudio','fa-wave-square','text-cyan-400'], ['insights','Copiloto e resumos','fa-brain','text-purple-400'], ['chat','Chat assistente','fa-comments','text-emerald-400']] as $__c):
                $__sm = $__roleSummary($__c[0]); ?>
            <div class="p-3.5 rounded-xl bg-slate-950 border border-slate-800 flex items-center justify-between gap-2">
                <div class="min-w-0">
                    <span class="text-[10px] uppercase font-mono text-slate-500 font-bold block"><?php echo $__c[1]; ?></span>
                    <strong class="text-xs text-white flex items-center gap-1.5 mt-0.5"><i class="fa-solid <?php echo $__c[2] . ' ' . $__c[3]; ?>"></i> <?php echo $__sm['ok'] ? htmlspecialchars($__sm['prov']) : 'Sem chave'; ?></strong>
                    <?php if ($__sm['ok'] && $__sm['model'] !== ''): ?><span class="text-[10px] text-slate-500 font-mono block truncate"><?php echo htmlspecialchars($__sm['model']); ?></span><?php endif; ?>
                </div>
                <span class="px-2 py-0.5 rounded text-[9px] font-bold font-mono border whitespace-nowrap <?php echo $__sm['ok'] ? 'bg-emerald-500/10 text-emerald-400 border-emerald-500/20' : 'bg-rose-500/10 text-rose-400 border-rose-500/20'; ?>"><?php echo $__sm['ok'] ? '● Pronto' : '○ Falta chave'; ?></span>
            </div>
            <?php endforeach; ?>
            <div class="p-3.5 rounded-xl bg-slate-950 border border-slate-800 flex items-center justify-between gap-2">
                <div>
                    <span class="text-[10px] uppercase font-mono text-slate-500 font-bold block">Voz (ElevenLabs)</span>
                    <strong class="text-xs text-white flex items-center gap-1.5 mt-0.5"><i class="fa-solid fa-microphone text-amber-400"></i> <?php echo $el_enabled === '1' ? 'Habilitada' : 'Desabilitada'; ?></strong>
                </div>
                <span class="px-2 py-0.5 rounded text-[9px] font-bold font-mono border whitespace-nowrap <?php echo ($el_enabled === '1' && !empty($el_api_key)) ? 'bg-amber-500/10 text-amber-300 border-amber-500/20' : 'bg-slate-800 text-slate-500 border-slate-700'; ?>"><?php echo ($el_enabled === '1' && !empty($el_api_key)) ? '● Ativa' : '○ Inativa'; ?></span>
            </div>
        </div>

        <!-- Seletor de Tabs -->
        <div class="flex items-center gap-1 bg-slate-950 p-1 rounded-xl border border-slate-800 text-xs font-bold w-fit mt-2">
            <a href="index.php?module=configuracoes&action=ia&ia_tab=copiloto"
               class="px-4 py-2 rounded-lg transition flex items-center gap-1.5 <?php echo $ia_tab === 'copiloto' ? 'bg-brand-600 text-white shadow-lg' : 'text-slate-400 hover:text-white'; ?>">
                <i class="fa-solid fa-robot"></i> Copiloto & Análise LLM
            </a>
            <a href="index.php?module=configuracoes&action=ia&ia_tab=elevenlabs"
               class="px-4 py-2 rounded-lg transition flex items-center gap-1.5 <?php echo $ia_tab === 'elevenlabs' ? 'bg-purple-600 text-white shadow-lg' : 'text-slate-400 hover:text-white'; ?>">
                <i class="fa-solid fa-waveform-lines"></i> ElevenLabs TTS (Vozes)
            </a>
        </div>
    </div>

    <!-- ─── TAB: COPILOTO IA ─────────────────────────────────────────────── -->
    <?php if ($ia_tab === 'copiloto'): ?>
    <div class="space-y-5">
        <?php if (!$__anyKey): ?>
        <div class="p-4 rounded-xl bg-amber-500/10 text-amber-300 border border-amber-500/20 text-xs font-bold flex items-center gap-2">
            <i class="fa-solid fa-triangle-exclamation text-amber-400"></i>
            Nenhuma chave de IA guardada. Cole pelo menos uma chave no Passo 1 abaixo; sem ela o Copiloto mostra apenas os números reais do CDR, sem análise.
        </div>
        <?php endif; ?>

        <div class="bg-slate-900/90 border border-slate-800 rounded-2xl p-6 shadow-xl max-w-4xl space-y-6">
            <?php
                $__defs = aiProviderDefaults();
                $__hints = [
                    'audio'    => 'Converte a gravação em texto. Só OpenAI ou Groq fazem isso.',
                    'insights' => 'Resumos de chamada, auditoria e análises do Copiloto. Textos curtos: vale um modelo rápido e barato.',
                    'chat'     => 'Conversa do assistente dentro do painel. Precisa de um modelo que use ferramentas.',
                ];
            ?>
            <form method="POST" action="index.php?module=configuracoes&action=ia&ia_tab=copiloto" class="space-y-6 text-xs">
                <input type="hidden" name="ai_adv_present" value="1">
                <button type="submit" name="action_save_ai_roles" value="1" class="hidden" tabindex="-1" aria-hidden="true"></button><!-- Enter nos campos = Salvar -->

                <div class="flex items-center justify-between p-4 bg-slate-950 rounded-2xl border border-slate-800">
                    <div>
                        <span class="font-extrabold text-white text-sm block">Inteligência Artificial ativa</span>
                        <span class="text-slate-400">Liga o assistente do painel e os resumos automáticos. Desligada, nada é enviado a provedores de IA.</span>
                    </div>
                    <label class="relative inline-flex items-center cursor-pointer">
                        <input type="checkbox" name="enable_copilot" value="1" class="sr-only peer" <?php echo $enable_copilot === '1' ? 'checked' : ''; ?>>
                        <div class="w-11 h-6 bg-slate-700 peer-focus:outline-none rounded-full peer peer-checked:after:translate-x-full peer-checked:after:border-white after:content-[''] after:absolute after:top-[2px] after:left-[2px] after:bg-white after:border-gray-300 after:border after:rounded-full after:h-5 after:w-5 after:transition-all peer-checked:bg-brand-600"></div>
                    </label>
                </div>

                <!-- PASSO 1 -->
                <section class="space-y-3">
                    <div class="flex items-center gap-2"><span class="w-6 h-6 rounded-full bg-brand-600 text-white font-extrabold flex items-center justify-center text-[11px]">1</span>
                        <h4 class="text-sm font-extrabold text-white">Cole a chave do provedor e clique em Testar</h4></div>
                    <p class="text-slate-400">Basta uma. Se tiver mais de uma, o painel usa cada uma onde ela é melhor. A chave fica guardada só neste servidor.</p>
                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                        <?php foreach ($__defs as $__p => $__d):
                            $__has = aiKeyFor($__p) !== ''; $__st = $__has ? aiKeyStatus($__p) : null;
                            if (!$__has) { $__bd = 'border-slate-800'; $__bg = 'text-slate-500'; $__tx = '○ Sem chave'; }
                            elseif ($__st && !empty($__st['ok'])) { $__bd = 'border-emerald-500/40'; $__bg = 'text-emerald-400'; $__tx = '✓ Aprovada'; }
                            elseif ($__st) { $__bd = 'border-rose-500/40'; $__bg = 'text-rose-400'; $__tx = '✗ Falhou'; }
                            else { $__bd = 'border-amber-500/30'; $__bg = 'text-amber-400'; $__tx = '● Salva, ainda não testada'; }
                        ?>
                        <div class="p-3 rounded-xl bg-slate-950 border <?php echo $__bd; ?> space-y-1.5">
                            <label class="font-bold text-slate-300 flex items-center justify-between">
                                <span><?php echo htmlspecialchars($__provLabels[$__p] ?? $__p); ?></span>
                                <span class="text-[10px] <?php echo $__bg; ?>"><?php echo $__tx; ?></span>
                            </label>
                            <div class="flex items-center gap-2">
                                <input type="password" name="ai_key_<?php echo $__p; ?>" autocomplete="new-password" placeholder="<?php echo $__has ? 'Em branco = manter a chave atual' : 'Cole a API Key'; ?>"
                                       class="flex-1 min-w-0 px-3 py-2 bg-slate-900 border border-slate-800 rounded-xl text-white font-mono focus:border-brand-500 focus:outline-none">
                                <button type="submit" name="action_test_ai_key" value="<?php echo $__p; ?>" style="white-space:nowrap !important;flex-shrink:0 !important;width:auto !important;overflow:visible !important;text-overflow:clip !important" class="px-3 py-2 bg-slate-800 hover:bg-slate-700 text-slate-200 font-bold rounded-xl border border-slate-700 flex items-center gap-1.5">
                                    <i class="fa-solid fa-flask text-brand-400"></i> Testar
                                </button>
                            </div>
                            <?php if ($__st && empty($__st['ok'])): ?><div class="text-[10px] text-rose-300"><?php echo htmlspecialchars((string)($__st['msg'] ?? '')); ?></div><?php endif; ?>
                            <?php if ($__has && getSetting('ai_key_' . $__p) !== ''): ?>
                            <label class="text-[10px] text-slate-500 flex items-center gap-1"><input type="checkbox" name="ai_key_clear_<?php echo $__p; ?>" value="1"> remover esta chave</label>
                            <?php endif; ?>
                        </div>
                        <?php endforeach; ?>
                    </div>
                </section>

                <!-- PASSO 2 -->
                <section class="space-y-3">
                    <div class="flex items-center gap-2"><span class="w-6 h-6 rounded-full bg-brand-600 text-white font-extrabold flex items-center justify-center text-[11px]">2</span>
                        <h4 class="text-sm font-extrabold text-white">Escolha quem faz cada tarefa</h4></div>
                    <p class="text-slate-400">Os provedores sem chave aprovada ficam apagados. <b class="text-slate-200">Automático</b> deixa o painel escolher sozinho entre as chaves que você colou.</p>
                    <div class="space-y-3">
                        <?php foreach (aiRoles() as $__r => $__lbl): $__rp = (string)getSetting("ai_role_{$__r}_provider"); ?>
                        <div class="p-3 rounded-xl bg-slate-950 border border-slate-800 space-y-2">
                            <div>
                                <div class="font-bold text-slate-200"><?php echo htmlspecialchars($__lbl); ?></div>
                                <div class="text-[10px] text-slate-500"><?php echo htmlspecialchars($__hints[$__r]); ?></div>
                            </div>
                            <div class="grid grid-cols-2 sm:grid-cols-5 gap-2">
                                <label class="cursor-pointer">
                                    <input type="radio" name="ai_role_<?php echo $__r; ?>_provider" value="" class="sr-only peer" <?php echo $__rp === '' ? 'checked' : ''; ?>>
                                    <div class="p-2.5 text-center rounded-xl bg-slate-900 border-2 border-slate-800 peer-checked:border-brand-500 peer-checked:bg-brand-500/10 hover:border-slate-600 transition">
                                        <i class="fa-solid fa-wand-magic-sparkles text-brand-400 block mb-0.5"></i><span class="font-bold text-white block">Automático</span><span class="text-[9px] text-slate-500">recomendado</span>
                                    </div>
                                </label>
                                <?php foreach ($__defs as $__p => $__d):
                                    $__has = aiKeyFor($__p) !== ''; $__st = $__has ? aiKeyStatus($__p) : null;
                                    $__failed = $__st && empty($__st['ok']);
                                    $__noaudio = ($__r === 'audio' && !in_array($__p, ['openai', 'groq'], true));
                                    $__can = $__has && !$__failed && !$__noaudio;
                                    $__why = $__noaudio ? 'não transcreve' : (!$__has ? 'sem chave' : ($__failed ? 'chave com erro' : (($__st && !empty($__st['ok'])) ? 'aprovada' : 'não testada')));
                                ?>
                                <label class="<?php echo $__can ? 'cursor-pointer' : 'cursor-not-allowed opacity-40'; ?>" <?php echo $__can ? '' : 'title="' . htmlspecialchars($__why) . '"'; ?>>
                                    <input type="radio" name="ai_role_<?php echo $__r; ?>_provider" value="<?php echo $__p; ?>" class="sr-only peer" <?php echo $__rp === $__p ? 'checked' : ''; ?> <?php echo $__can ? '' : 'disabled'; ?>>
                                    <div class="p-2.5 text-center rounded-xl bg-slate-900 border-2 border-slate-800 <?php echo $__can ? 'peer-checked:border-brand-500 peer-checked:bg-brand-500/10 hover:border-slate-600' : ''; ?> transition">
                                        <span class="font-bold text-white block"><?php echo htmlspecialchars(preg_replace('/ \(.*\)/', '', $__provLabels[$__p] ?? $__p)); ?></span>
                                        <span class="text-[9px] <?php echo $__can ? (($__st && !empty($__st['ok'])) ? 'text-emerald-400' : 'text-amber-400') : 'text-slate-500'; ?>"><?php echo htmlspecialchars($__why); ?></span>
                                    </div>
                                </label>
                                <?php endforeach; ?>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    </div>
                </section>

                <!-- PASSO 3 -->
                <section class="space-y-3">
                    <div class="flex items-center gap-2"><span class="w-6 h-6 rounded-full bg-slate-700 text-white font-extrabold flex items-center justify-center text-[11px]">3</span>
                        <h4 class="text-sm font-extrabold text-white">Ajustes finos <span class="text-slate-500 font-normal">(opcional)</span></h4></div>
                    <details class="group">
                        <summary class="cursor-pointer text-slate-400 hover:text-white font-bold py-1"><i class="fa-solid fa-sliders"></i> Mostrar ajustes</summary>
                        <div class="space-y-3 pt-3">
                            <div>
                                <label class="font-bold text-slate-300 block mb-1">Instrução de tom do assistente <span class="text-slate-500 font-normal">(vazio = padrão do sistema)</span></label>
                                <textarea name="ai_custom_prompt" rows="3" placeholder="Ex.: Responda sempre de forma curta e cordial."
                                          class="w-full px-3.5 py-2.5 bg-slate-950 border border-slate-800 rounded-xl text-white text-xs focus:border-brand-500 focus:outline-none"><?php echo htmlspecialchars($ai_custom_prompt); ?></textarea>
                            </div>
                            <div>
                                <label class="font-bold text-slate-300 block mb-1">Tamanho máximo das respostas <span class="text-slate-500 font-normal">(tokens)</span></label>
                                <input type="number" name="ai_token_limit" value="<?php echo htmlspecialchars($ai_token_limit); ?>" min="64" max="4000"
                                       class="w-full sm:w-48 px-3.5 py-2.5 bg-slate-950 border border-slate-800 rounded-xl text-white font-mono focus:border-brand-500 focus:outline-none">
                            </div>
                            <div>
                                <label class="font-bold text-slate-300 block mb-1">Modelo específico por tarefa <span class="text-slate-500 font-normal">(vazio = o painel escolhe o melhor disponível)</span></label>
                                <div class="grid grid-cols-1 sm:grid-cols-3 gap-2">
                                    <?php foreach (aiRoles() as $__r => $__lbl): ?>
                                    <input type="text" name="ai_role_<?php echo $__r; ?>_model" value="<?php echo htmlspecialchars((string)getSetting("ai_role_{$__r}_model")); ?>" placeholder="<?php echo htmlspecialchars($__lbl); ?>"
                                           class="px-3 py-2 bg-slate-950 border border-slate-800 rounded-xl text-white font-mono focus:border-brand-500 focus:outline-none">
                                    <?php endforeach; ?>
                                </div>
                            </div>
                        </div>
                    </details>
                </section>

                <div class="flex items-center justify-between pt-1 gap-3 flex-wrap">
                    <p class="text-slate-500 text-[11px] flex items-center gap-1"><i class="fa-solid fa-shield text-brand-400"></i> Chaves guardadas somente no banco local deste servidor.</p>
                    <div class="flex items-center gap-2">
                        <button type="submit" name="action_test_ai_roles" value="1" style="white-space:nowrap !important;flex-shrink:0 !important;width:auto !important;overflow:visible !important;text-overflow:clip !important" class="px-4 py-2.5 bg-slate-800 hover:bg-slate-700 text-slate-200 font-extrabold rounded-xl transition border border-slate-700 flex items-center gap-2">
                            <i class="fa-solid fa-plug-circle-check text-brand-400"></i> Salvar e Testar
                        </button>
                        <button type="submit" name="action_save_ai_roles" value="1" style="white-space:nowrap !important;flex-shrink:0 !important;width:auto !important;overflow:visible !important;text-overflow:clip !important" class="px-5 py-2.5 bg-brand-600 hover:bg-brand-500 text-white font-extrabold rounded-xl transition shadow-lg shadow-brand-600/20 flex items-center gap-2">
                            <i class="fa-solid fa-floppy-disk"></i> Salvar
                        </button>
                    </div>
                </div>
            </form>

            <!-- Card Explicativo: Como a IA Analisa as Ligações -->
            <div class="p-5 rounded-2xl bg-slate-950 border border-purple-500/30 space-y-3">
                <div class="flex items-center gap-2 text-purple-400 font-bold text-xs uppercase tracking-wider">
                    <i class="fa-solid fa-brain"></i> Como a IA Analisa as Ligações do PABX Automático?
                </div>
                <p class="text-slate-300 text-xs leading-relaxed">
                    A IA do PABX Prisma lê automaticamente a transcrição dos áudios das chamadas (utilizando o modelo <b>GroqCloud Whisper Turbo</b> ou <b>OpenAI Whisper</b>) e extrai métricas qualitativas sem intervenção manual:
                </p>
                <div class="grid grid-cols-1 sm:grid-cols-2 gap-3 pt-1 text-[11px]">
                    <div class="p-2.5 rounded-xl bg-slate-900 border border-slate-800 text-slate-300">
                        <strong class="text-emerald-400 block mb-0.5"><i class="fa-solid fa-comments"></i> Saudações & Cordialidade</strong>
                        Verifica se o operador utilizou a saudação padrão da empresa e tom profissional.
                    </div>
                    <div class="p-2.5 rounded-xl bg-slate-900 border border-slate-800 text-slate-300">
                        <strong class="text-amber-400 block mb-0.5"><i class="fa-solid fa-shield-cat"></i> Mapeamento de Objeções</strong>
                        Identifica recusas, dúvidas de preços ou queixas do cliente em tempo real.
                    </div>
                    <div class="p-2.5 rounded-xl bg-slate-900 border border-slate-800 text-slate-300">
                        <strong class="text-purple-400 block mb-0.5"><i class="fa-solid fa-chart-line-up"></i> Rating & Lead Score (0-100)</strong>
                        Classifica a probabilidade de fechamento ou insatisfação com notas automáticas.
                    </div>
                    <div class="p-2.5 rounded-xl bg-slate-900 border border-slate-800 text-slate-300">
                        <strong class="text-cyan-400 block mb-0.5"><i class="fa-solid fa-clock-rotate-left"></i> Histórico de 50 Análises</strong>
                        Mantém gravados os relatórios para consulta em auditorias operacionais.
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- ─── TAB: ELEVENLABS ─────────────────────────────────────────────────── -->
    <?php elseif ($ia_tab === 'elevenlabs'): ?>
    <div class="space-y-5">
        <div class="bg-slate-900/90 border border-purple-500/20 rounded-2xl p-6 shadow-xl max-w-3xl space-y-6">
            <div class="flex items-center gap-3 border-b border-slate-800 pb-4">
                <div class="w-10 h-10 rounded-xl bg-purple-500/20 text-purple-400 border border-purple-500/30 flex items-center justify-center text-lg">
                    <i class="fa-solid fa-waveform-lines"></i>
                </div>
                <div>
                    <h4 class="text-sm font-extrabold text-white">ElevenLabs — Text-to-Speech Avançado</h4>
                    <span class="text-xs text-slate-400">Síntese de voz ultra-realista para anúncios de URA e mensagens de áudio do PABX</span>
                </div>
            </div>

            <form method="POST" action="index.php?module=configuracoes&action=ia&ia_tab=elevenlabs" class="space-y-5 text-xs">
                <input type="hidden" name="action_save_elevenlabs" value="1">

                <!-- Toggle -->
                <div class="flex items-center justify-between p-4 bg-slate-950 rounded-2xl border border-slate-800">
                    <div>
                        <span class="font-extrabold text-white text-sm block">ElevenLabs TTS Ativado</span>
                        <span class="text-slate-400">Quando ativo, utilizado para gerar anúncios de áudio do fluxograma e URA</span>
                    </div>
                    <label class="relative inline-flex items-center cursor-pointer">
                        <input type="checkbox" name="elevenlabs_enabled" value="1" class="sr-only peer" <?php echo $el_enabled === '1' ? 'checked' : ''; ?>>
                        <div class="w-11 h-6 bg-slate-700 peer-focus:outline-none rounded-full peer peer-checked:after:translate-x-full peer-checked:after:border-white after:content-[''] after:absolute after:top-[2px] after:left-[2px] after:bg-white after:border-gray-300 after:border after:rounded-full after:h-5 after:w-5 after:transition-all peer-checked:bg-purple-600"></div>
                    </label>
                </div>

                <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                    <div class="sm:col-span-2">
                        <label class="font-bold text-slate-300 block mb-1 flex items-center gap-1"><i class="fa-solid fa-key text-amber-400"></i> API Key ElevenLabs Universal *</label>
                        <input type="password" name="elevenlabs_api_key" value="<?php echo htmlspecialchars($el_api_key); ?>"
                               placeholder="sk_..."
                               class="w-full px-3.5 py-2.5 bg-slate-950 border border-slate-800 rounded-xl text-white font-mono focus:border-purple-500 focus:outline-none">
                        <p class="text-slate-500 text-[10px] mt-1">Obtenha sua chave em <a href="https://elevenlabs.io" target="_blank" class="text-purple-400 hover:text-purple-300 underline">elevenlabs.io</a></p>
                    </div>
                    <div>
                        <label class="font-bold text-slate-300 block mb-1 flex items-center gap-1"><i class="fa-solid fa-microphone text-purple-400"></i> Voice ID</label>
                        <input type="text" name="elevenlabs_voice_id" value="<?php echo htmlspecialchars($el_voice_id); ?>"
                               placeholder="EXAVITQu4vr4xnSDxMaL (Rachel)"
                               class="w-full px-3.5 py-2.5 bg-slate-950 border border-slate-800 rounded-xl text-white font-mono focus:border-purple-500 focus:outline-none">
                        <p class="text-slate-500 text-[10px] mt-1">Copie o ID da voz desejada no painel da ElevenLabs</p>
                    </div>
                    <div>
                        <label class="font-bold text-slate-300 block mb-1">Modelo de Voz</label>
                        <select name="elevenlabs_model" class="w-full px-3.5 py-2.5 bg-slate-950 border border-slate-800 rounded-xl text-white focus:border-purple-500 focus:outline-none font-mono">
                            <?php foreach ([
                                'eleven_multilingual_v2' => 'Multilingual v2 (Recomendado)',
                                'eleven_monolingual_v1'  => 'Monolingual v1 (EN)',
                                'eleven_turbo_v2'        => 'Turbo v2 (Rápido)',
                                'eleven_turbo_v2_5'      => 'Turbo v2.5 (Ultra-rápido)',
                            ] as $mv => $ml): ?>
                            <option value="<?php echo $mv; ?>" <?php echo $el_model === $mv ? 'selected' : ''; ?>><?php echo $ml; ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>

                <!-- Teste de Voz -->
                <div class="p-4 bg-slate-950 rounded-2xl border border-purple-500/20 space-y-3">
                    <span class="text-purple-300 font-bold text-xs flex items-center gap-2"><i class="fa-solid fa-flask"></i> Testar Síntese de Voz</span>
                    <textarea id="el-test-text" rows="2" placeholder="Digite um texto para testar a voz configurada..."
                              class="w-full px-3.5 py-2 bg-slate-900 border border-slate-800 rounded-xl text-white text-xs focus:border-purple-500 focus:outline-none">Olá, bem-vindo ao atendimento da Prismabot. Como posso ajudar?</textarea>
                    <button type="button" onclick="testElevenLabsTTS()" class="px-4 py-2 bg-purple-600 hover:bg-purple-500 text-white rounded-xl text-xs font-bold transition flex items-center gap-2">
                        <i class="fa-solid fa-play"></i> Ouvir Síntese de Voz
                    </button>
                    <audio id="el-test-audio" class="hidden w-full h-8 mt-1" controls></audio>
                </div>

                <div class="flex items-center justify-between pt-2">
                    <p class="text-slate-500 text-[11px]"><i class="fa-solid fa-info-circle text-purple-400"></i> Configuração universal para todo o PABX.</p>
                    <button type="submit" class="px-5 py-2.5 bg-purple-600 hover:bg-purple-500 text-white font-extrabold rounded-xl transition shadow-lg shadow-purple-600/20 flex items-center gap-2">
                        <i class="fa-solid fa-floppy-disk"></i> Salvar ElevenLabs
                    </button>
                </div>
            </form>
        </div>
    </div>
    <?php endif; ?>
</div>

<script>
// ─── Provedor: Atualiza URLs e Selects de Modelos Compatíveis ────────────────
function updateBaseUrlForProvider(pv) {
    const urls = {
        openai  : 'https://api.openai.com/v1',
        groq    : 'https://api.groq.com/openai/v1',
        gemini  : 'https://generativelanguage.googleapis.com/v1beta/openai',
        deepseek: 'https://api.deepseek.com/v1'
    };
    
    const chatModels = {
        openai: [
            {val: 'gpt-4o-mini', label: 'gpt-4o-mini (Recomendado / Econômico)'},
            {val: 'gpt-4o', label: 'gpt-4o (Mais Inteligente)'},
            {val: 'gpt-3.5-turbo', label: 'gpt-3.5-turbo (Clássico)'}
        ],
        groq: [
            {val: 'llama-3.3-70b-versatile', label: 'llama-3.3-70b-versatile (Recomendado / Ultra-rápido)'},
            {val: 'llama3-8b-8192', label: 'llama3-8b-8192 (Econômico)'},
            {val: 'mixtral-8x7b-32768', label: 'mixtral-8x7b-32768'}
        ],
        gemini: [
            {val: 'gemini-1.5-flash', label: 'gemini-1.5-flash (Recomendado / Gratuito)'},
            {val: 'gemini-1.5-pro', label: 'gemini-1.5-pro (Raciocínio Avançado)'}
        ],
        deepseek: [
            {val: 'deepseek-chat', label: 'deepseek-chat (Recomendado V3)'},
            {val: 'deepseek-coder', label: 'deepseek-coder'}
        ]
    };

    const audioModels = {
        openai: [
            {val: 'whisper-1', label: 'whisper-1 (Oficial OpenAI STT)'}
        ],
        groq: [
            {val: 'whisper-large-v3', label: 'whisper-large-v3 (Recomendado Groq)'},
            {val: 'distil-whisper-large-v3-en', label: 'distil-whisper-large-v3-en'}
        ],
        gemini: [
            {val: 'whisper-1', label: 'whisper-1 (Via Proxy / Transcrição)'}
        ],
        deepseek: [
            {val: 'whisper-1', label: 'whisper-1 (Via Proxy / Transcrição)'}
        ]
    };

    const inpUrl     = document.getElementById('ai_base_url_input');
    const selChat    = document.getElementById('ai_model_chat_select');
    const selAudio   = document.getElementById('ai_model_audio_select');

    if (inpUrl && urls[pv]) inpUrl.value = urls[pv];

    if (selChat && chatModels[pv]) {
        selChat.innerHTML = '';
        chatModels[pv].forEach(m => {
            const opt = document.createElement('option');
            opt.value = m.val;
            opt.textContent = m.label;
            selChat.appendChild(opt);
        });
        const customOpt = document.createElement('option');
        customOpt.value = 'custom';
        customOpt.textContent = 'Outro modelo (Digitar manualmente...)';
        selChat.appendChild(customOpt);
        checkCustomModelInput('chat');
    }

    if (selAudio && audioModels[pv]) {
        selAudio.innerHTML = '';
        audioModels[pv].forEach(m => {
            const opt = document.createElement('option');
            opt.value = m.val;
            opt.textContent = m.label;
            selAudio.appendChild(opt);
        });
        const customAudioOpt = document.createElement('option');
        customAudioOpt.value = 'custom';
        customAudioOpt.textContent = 'Outro modelo de áudio...';
        selAudio.appendChild(customAudioOpt);
        checkCustomModelInput('audio');
    }
}

function checkCustomModelInput(type) {
    const sel = document.getElementById(`ai_model_${type}_select`);
    const inp = document.getElementById(`ai_model_${type}_custom`);
    if (!sel || !inp) return;

    if (sel.value === 'custom') {
        inp.classList.remove('hidden');
        inp.focus();
    } else {
        inp.classList.add('hidden');
        inp.value = sel.value;
    }
}

// ─── Testar Conexão IA (LLM) ────────────────────────────────────────────────
async function testLlmConnection() {
    const btn = document.getElementById('btn-test-llm');
    if (!btn) return;

    const provider  = document.querySelector('input[name="ai_provider"]:checked')?.value || 'openai';
    const apiKey    = document.querySelector('input[name="ai_api_key"]')?.value || '';
    const adv       = document.getElementById('ai_advanced')?.value === '1';
    const baseUrl   = adv ? (document.getElementById('ai_base_url_input')?.value || '') : '';

    let selChat = adv ? document.getElementById('ai_model_chat_select')?.value : '';
    if (selChat === 'custom') selChat = document.getElementById('ai_model_chat_custom')?.value;

    if (!apiKey.trim()) {
        alert('⚠️ Por favor, cole a API Key no campo indicado antes de testar.');
        return;
    }

    const origHTML = btn.innerHTML;
    btn.innerHTML = '<i class="fa-solid fa-spinner fa-spin text-brand-400"></i> Testando...';
    btn.disabled  = true;

    try {
        const res = await fetch('index.php?api_action=test_llm_connection', {
            method: 'POST',
            headers: {'Content-Type': 'application/json'},
            body: JSON.stringify({
                provider  : provider,
                api_key   : apiKey,
                base_url  : baseUrl,
                model_chat: selChat
            })
        });

        const data = await res.json();
        if (data.success) {
            alert('✅ ' + data.message);
        } else {
            alert('❌ Erro na integração: ' + data.error);
        }
    } catch(e) {
        alert('❌ Falha de comunicação com o servidor: ' + e.message);
    } finally {
        btn.innerHTML = origHTML;
        btn.disabled  = false;
    }
}

// ─── Testar ElevenLabs TTS ───────────────────────────────────────────────────
async function testElevenLabsTTS() {
    const text  = document.getElementById('el-test-text')?.value;
    const audio = document.getElementById('el-test-audio');
    if (!text || !audio) return;

    const btn = document.querySelector('[onclick="testElevenLabsTTS()"]');
    const origHTML = btn.innerHTML;
    btn.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> Gerando...';
    btn.disabled  = true;

    try {
        const res = await fetch('index.php?api_action=elevenlabs_tts', {
            method: 'POST',
            headers: {'Content-Type': 'application/json'},
            body: JSON.stringify({text: text})
        });

        if (!res.ok) {
            const err = await res.json().catch(() => ({error: 'Erro desconhecido'}));
            alert('❌ Erro ElevenLabs: ' + (err.error || 'HTTP ' + res.status));
            return;
        }

        const blob = await res.blob();
        const url  = URL.createObjectURL(blob);
        audio.src  = url;
        audio.classList.remove('hidden');
        audio.play();
    } catch(e) {
        alert('Erro ao testar TTS: ' + e.message);
    } finally {
        btn.innerHTML = origHTML;
        btn.disabled  = false;
    }
}
</script>
