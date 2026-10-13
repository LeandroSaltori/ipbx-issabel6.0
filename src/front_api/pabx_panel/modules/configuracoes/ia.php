<?php
/**
 * IPbx Prisma - Configurações de IA & Integrações v8.0 (Redesign Dashboard de IA Universal)
 * Seções organizadas: Copiloto Prismabot, ElevenLabs TTS, Status de Integrações Globais
 */

$msg      = '';
$msg_type = 'success';
$__provLabelsTop = ['openai' => 'OpenAI (GPT)', 'groq' => 'Groq', 'gemini' => 'Google Gemini'];

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
            // Cada página salva só o que ela mostra: provedor das tarefas (página Tarefas) e modelo (página Ajustes)
            if (isset($_POST['ai_roles_present'])) {
                $__prov = trim($_POST["ai_role_{$__r}_provider"] ?? '');
                if ($__prov !== '' && !isset(aiProviderDefaults()[$__prov])) $__prov = '';
                $__save->execute([':k' => "ai_role_{$__r}_provider", ':v' => $__prov]);
            }
            if (isset($_POST['ai_adv_present'])) $__save->execute([':k' => "ai_role_{$__r}_model", ':v' => trim($_POST["ai_role_{$__r}_model"] ?? '')]);
        }
        if (isset($_POST['ai_toggle_present'])) $__save->execute([':k' => 'enable_copilot', ':v' => isset($_POST['enable_copilot']) ? '1' : '0']);
        if (isset($_POST['action_test_ai_key'])) {
            $__tp = (string)$_POST['action_test_ai_key'];
            $__tr = aiTestProviderKey($__tp);
            $msg = ($__provLabelsTop[$__tp] ?? $__tp) . ': ' . ($__tr['ok'] ? '✅ chave aprovada e salva.' : '❌ ' . $__tr['msg'] . '. A chave ficou salva; corrija e teste de novo.');
            if (!$__tr['ok']) $msg_type = 'info';
        }
        if (isset($_POST['ai_adv_present'])) {
            $__save->execute([':k' => 'ai_custom_prompt',  ':v' => trim($_POST['ai_custom_prompt'] ?? '')]);
            $__save->execute([':k' => 'ai_token_limit',    ':v' => (string)max(64, min(4000, (int)($_POST['ai_token_limit'] ?? 1024) ?: 1024))]);
        }
        if (!empty($_POST['ai_provider_page']) && isset(aiProviderDefaults()[$_POST['ai_provider_page']])) {
            $__tp = (string)$_POST['ai_provider_page'];
            if (!empty($_POST['ai_key_clear_' . $__tp])) { $msg = ($__provLabelsTop[$__tp] ?? $__tp) . ': chave removida.'; }
            elseif (aiKeyFor($__tp) === '') { $msg = ($__provLabelsTop[$__tp] ?? $__tp) . ': cole a chave de acesso para salvar.'; $msg_type = 'info'; }
            else {
                $__tr = aiTestProviderKey($__tp);
                $msg = ($__provLabelsTop[$__tp] ?? $__tp) . ': ' . ($__tr['ok'] ? '✅ chave salva e aprovada.' : '❌ chave salva, mas o teste falhou: ' . $__tr['msg'] . '.');
                if (!$__tr['ok']) $msg_type = 'info';
            }
        }
        if (!isset($_POST['action_test_ai_key']) && empty($_POST['ai_provider_page'])) $msg = "Configuração de IA salva! Cada função passa a usar o provedor escolhido (ou o automático).";
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

$__provLabels = ['openai' => 'OpenAI (GPT)', 'groq' => 'Groq', 'gemini' => 'Google Gemini'];
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

    <?php
        $__ui   = ['openai', 'groq', 'gemini'];                       // provedores disponíveis
        $__defs = aiProviderDefaults();
        $__hints = [
            'audio'    => 'Converte a gravação da chamada em texto. Só OpenAI ou Groq fazem isso.',
            'insights' => 'Resumos de chamada, auditoria e análises do Copiloto. Textos curtos: vale um modelo rápido e barato.',
            'chat'     => 'Conversa do assistente dentro do painel. Precisa de um modelo que use ferramentas.',
        ];
        $__provInfo = [
            'openai' => ['nome' => 'OpenAI (ChatGPT)', 'icon' => 'fa-brain', 'cor' => 'text-emerald-400', 'url' => 'https://platform.openai.com/api-keys', 'fazem' => 'Transcrição de áudio, resumos e chat.', 'nota' => 'Serviço pago, cobrado por uso. Confira o plano atual no site da OpenAI.'],
            'groq'   => ['nome' => 'Groq (Llama e Whisper)', 'icon' => 'fa-bolt', 'cor' => 'text-orange-400', 'url' => 'https://console.groq.com/keys', 'fazem' => 'Transcrição de áudio (Whisper), resumos e chat (Llama).', 'nota' => 'Costuma ter plano gratuito com limites diários, e é muito rápido. Não confunda com o Grok, da xAI. Confira os limites atuais no site da Groq.'],
            'gemini' => ['nome' => 'Google Gemini', 'icon' => 'fa-gem', 'cor' => 'text-sky-400', 'url' => 'https://aistudio.google.com/app/apikey', 'fazem' => 'Resumos e chat. Não transcreve áudio.', 'nota' => 'Costuma ter plano gratuito com limites. Confira o plano atual no Google AI Studio.'],
        ];
        $__dot = function ($p) {
            if (aiKeyFor($p) === '') return 'bg-slate-600';
            $st = aiKeyStatus($p);
            if ($st && !empty($st['ok'])) return 'bg-emerald-400';
            if ($st) return 'bg-rose-400';
            return 'bg-amber-400';
        };
        $__tabs = ['geral', 'tarefas', 'openai', 'groq', 'gemini', 'ajustes', 'elevenlabs'];
        if ($ia_tab === 'copiloto' || !in_array($ia_tab, $__tabs, true)) $ia_tab = 'geral';
        $__navBtn = function ($tab, $label, $icon, $dot = '') use ($ia_tab) {
            $on = $ia_tab === $tab;
            return '<a href="index.php?module=configuracoes&action=ia&ia_tab=' . $tab . '" class="flex items-center gap-2.5 px-3 py-2.5 rounded-xl text-xs font-bold transition ' . ($on ? 'bg-brand-600 text-white shadow-lg' : 'text-slate-400 hover:text-white hover:bg-slate-800/60') . '">'
                 . '<i class="fa-solid ' . $icon . ' w-4 text-center"></i><span class="flex-1">' . $label . '</span>'
                 . ($dot !== '' ? '<span class="w-2 h-2 rounded-full ' . $dot . '"></span>' : '') . '</a>';
        };
        $__saveBtnStyle = 'white-space:nowrap !important;flex-shrink:0 !important;width:auto !important;overflow:visible !important;text-overflow:clip !important';
    ?>

    <div class="flex items-center gap-3">
        <div class="w-10 h-10 rounded-xl bg-brand-500/20 text-brand-400 border border-brand-500/30 flex items-center justify-center text-lg"><i class="fa-solid fa-robot"></i></div>
        <div>
            <h3 class="text-lg font-extrabold text-white">Inteligência Artificial</h3>
            <p class="text-xs text-slate-400">Chaves dos provedores e quem faz cada tarefa no painel.</p>
        </div>
    </div>

    <div class="grid grid-cols-1 lg:grid-cols-[230px_minmax(0,1fr)] gap-5 items-start">
        <!-- Menu lateral -->
        <nav class="bg-slate-900/90 border border-slate-800 rounded-2xl p-2.5 space-y-1">
            <?php echo $__navBtn('geral', 'Visão geral', 'fa-gauge-high'); ?>
            <?php echo $__navBtn('tarefas', 'Tarefas da IA', 'fa-diagram-project'); ?>
            <div class="px-3 pt-3 pb-1 text-[10px] uppercase tracking-wider text-slate-500 font-bold">Provedores</div>
            <?php foreach ($__ui as $__p) echo $__navBtn($__p, htmlspecialchars(explode(' (', $__provInfo[$__p]['nome'])[0]), $__provInfo[$__p]['icon'], $__dot($__p)); ?>
            <div class="px-3 pt-3 pb-1 text-[10px] uppercase tracking-wider text-slate-500 font-bold">Voz</div>
            <?php echo $__navBtn('elevenlabs', 'ElevenLabs', 'fa-microphone-lines', ($el_enabled === '1' && !empty($el_api_key)) ? 'bg-emerald-400' : 'bg-slate-600'); ?>
            <div class="px-3 pt-3 pb-1 text-[10px] uppercase tracking-wider text-slate-500 font-bold">Avançado</div>
            <?php echo $__navBtn('ajustes', 'Ajustes do assistente', 'fa-sliders'); ?>
        </nav>

        <!-- Conteúdo -->
        <div class="min-w-0">

    <?php if ($ia_tab === 'geral'): ?>
        <div class="bg-slate-900/90 border border-slate-800 rounded-2xl p-6 shadow-xl space-y-5">
            <div class="flex items-start justify-between gap-3 flex-wrap">
                <div><h4 class="text-base font-extrabold text-white">Visão geral</h4><p class="text-xs text-slate-400">O que a IA está usando agora e o que falta para funcionar.</p></div>
                <button type="submit" form="f_geral" style="<?php echo $__saveBtnStyle; ?>" class="px-4 py-2 bg-brand-600 hover:bg-brand-500 text-white font-extrabold rounded-xl text-xs flex items-center gap-2"><i class="fa-solid fa-floppy-disk"></i> Salvar</button>
            </div>

            <div class="grid grid-cols-1 sm:grid-cols-3 gap-3">
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
            </div>

            <form id="f_geral" method="POST" action="index.php?module=configuracoes&action=ia&ia_tab=geral">
                <input type="hidden" name="ai_toggle_present" value="1">
                <input type="hidden" name="action_save_ai_roles" value="1">
                <div class="flex items-center justify-between p-4 bg-slate-950 rounded-2xl border border-slate-800">
                    <div><span class="font-extrabold text-white text-sm block">Inteligência Artificial ativa</span><span class="text-xs text-slate-400">Liga o assistente do painel e os resumos automáticos. Desligada, nada é enviado a provedores de IA.</span></div>
                    <label class="relative inline-flex items-center cursor-pointer">
                        <input type="checkbox" name="enable_copilot" value="1" class="sr-only peer" <?php echo $enable_copilot === '1' ? 'checked' : ''; ?>>
                        <div class="w-11 h-6 bg-slate-700 peer-focus:outline-none rounded-full peer peer-checked:after:translate-x-full peer-checked:after:border-white after:content-[''] after:absolute after:top-[2px] after:left-[2px] after:bg-white after:border-gray-300 after:border after:rounded-full after:h-5 after:w-5 after:transition-all peer-checked:bg-brand-600"></div>
                    </label>
                </div>
            </form>

            <div class="p-4 rounded-2xl bg-slate-950 border border-slate-800 space-y-2 text-xs">
                <div class="font-extrabold text-white text-sm">Como configurar</div>
                <?php $__okKey = false; foreach ($__ui as $__p) { $s = aiKeyStatus($__p); if (aiKeyFor($__p) !== '' && $s && !empty($s['ok'])) $__okKey = true; } ?>
                <div class="flex items-center gap-2"><i class="fa-solid <?php echo $__okKey ? 'fa-circle-check text-emerald-400' : 'fa-circle text-slate-600'; ?>"></i><span class="text-slate-300"><b>1.</b> Em <a class="text-brand-400 underline" href="index.php?module=configuracoes&action=ia&ia_tab=groq">Groq</a>, <a class="text-brand-400 underline" href="index.php?module=configuracoes&action=ia&ia_tab=openai">OpenAI</a> ou <a class="text-brand-400 underline" href="index.php?module=configuracoes&action=ia&ia_tab=gemini">Gemini</a>, cole a chave e clique em Salvar (ela já é testada).</span></div>
                <div class="flex items-center gap-2"><i class="fa-solid <?php echo $__anyKey ? 'fa-circle-check text-emerald-400' : 'fa-circle text-slate-600'; ?>"></i><span class="text-slate-300"><b>2.</b> Em <a class="text-brand-400 underline" href="index.php?module=configuracoes&action=ia&ia_tab=tarefas">Tarefas da IA</a>, deixe Automático ou escolha quem faz cada tarefa.</span></div>
                <div class="flex items-center gap-2"><i class="fa-solid <?php echo $enable_copilot === '1' ? 'fa-circle-check text-emerald-400' : 'fa-circle text-slate-600'; ?>"></i><span class="text-slate-300"><b>3.</b> Deixe a Inteligência Artificial ativa (acima).</span></div>
            </div>

            <div class="p-5 rounded-2xl bg-slate-950 border border-purple-500/30 space-y-3">
                <div class="flex items-center gap-2 text-purple-400 font-bold text-xs uppercase tracking-wider"><i class="fa-solid fa-brain"></i> O que a IA faz com as ligações</div>
                <div class="grid grid-cols-1 sm:grid-cols-2 gap-3 text-[11px]">
                    <div class="p-2.5 rounded-xl bg-slate-900 border border-slate-800 text-slate-300"><strong class="text-emerald-400 block mb-0.5"><i class="fa-solid fa-comments"></i> Saudações e cordialidade</strong>Verifica se o atendente usou a saudação padrão e um tom profissional.</div>
                    <div class="p-2.5 rounded-xl bg-slate-900 border border-slate-800 text-slate-300"><strong class="text-amber-400 block mb-0.5"><i class="fa-solid fa-shield-cat"></i> Objeções e reclamações</strong>Identifica recusas, dúvidas de preço ou queixas do cliente.</div>
                    <div class="p-2.5 rounded-xl bg-slate-900 border border-slate-800 text-slate-300"><strong class="text-purple-400 block mb-0.5"><i class="fa-solid fa-chart-line"></i> Nota de qualidade</strong>Classifica o atendimento com uma nota de 0 a 100.</div>
                    <div class="p-2.5 rounded-xl bg-slate-900 border border-slate-800 text-slate-300"><strong class="text-cyan-400 block mb-0.5"><i class="fa-solid fa-clock-rotate-left"></i> Histórico</strong>Guarda as análises para consulta em auditorias.</div>
                </div>
            </div>
        </div>

    <?php elseif ($ia_tab === 'tarefas'): ?>
        <form id="f_tarefas" method="POST" action="index.php?module=configuracoes&action=ia&ia_tab=tarefas" class="bg-slate-900/90 border border-slate-800 rounded-2xl p-6 shadow-xl space-y-5 text-xs">
            <input type="hidden" name="ai_roles_present" value="1">
            <button type="submit" name="action_save_ai_roles" value="1" class="hidden" tabindex="-1" aria-hidden="true"></button>
            <div class="flex items-start justify-between gap-3 flex-wrap">
                <div><h4 class="text-base font-extrabold text-white">Tarefas da IA</h4><p class="text-slate-400">Escolha quem faz cada tarefa. Provedores sem chave aprovada ficam apagados. <b class="text-slate-200">Automático</b> deixa o painel escolher entre as chaves que você colou.</p></div>
                <div class="flex items-center gap-2">
                    <button type="submit" name="action_test_ai_roles" value="1" style="<?php echo $__saveBtnStyle; ?>" class="px-4 py-2 bg-slate-800 hover:bg-slate-700 text-slate-200 font-extrabold rounded-xl border border-slate-700 flex items-center gap-2"><i class="fa-solid fa-plug-circle-check text-brand-400"></i> Salvar e Testar</button>
                    <button type="submit" name="action_save_ai_roles" value="1" style="<?php echo $__saveBtnStyle; ?>" class="px-4 py-2 bg-brand-600 hover:bg-brand-500 text-white font-extrabold rounded-xl flex items-center gap-2"><i class="fa-solid fa-floppy-disk"></i> Salvar</button>
                </div>
            </div>
            <div class="space-y-3">
                <?php foreach (aiRoles() as $__r => $__lbl): $__rp = (string)getSetting("ai_role_{$__r}_provider"); ?>
                <div class="p-3 rounded-xl bg-slate-950 border border-slate-800 space-y-2">
                    <div><div class="font-bold text-slate-200 text-sm"><?php echo htmlspecialchars($__lbl); ?></div><div class="text-[11px] text-slate-500"><?php echo htmlspecialchars($__hints[$__r]); ?></div></div>
                    <div class="grid grid-cols-2 sm:grid-cols-4 gap-2">
                        <label class="cursor-pointer">
                            <input type="radio" name="ai_role_<?php echo $__r; ?>_provider" value="" class="sr-only peer" <?php echo ($__rp === '' || !in_array($__rp, $__ui, true)) ? 'checked' : ''; ?>>
                            <div class="p-2.5 text-center rounded-xl bg-slate-900 border-2 border-slate-800 peer-checked:border-brand-500 peer-checked:bg-brand-500/10 hover:border-slate-600 transition"><i class="fa-solid fa-wand-magic-sparkles text-brand-400 block mb-0.5"></i><span class="font-bold text-white block">Automático</span><span class="text-[9px] text-slate-500">recomendado</span></div>
                        </label>
                        <?php foreach ($__ui as $__p):
                            $__has = aiKeyFor($__p) !== ''; $__st = $__has ? aiKeyStatus($__p) : null;
                            $__failed = $__st && empty($__st['ok']);
                            $__noaudio = ($__r === 'audio' && !in_array($__p, ['openai', 'groq'], true));
                            $__can = $__has && !$__failed && !$__noaudio;
                            $__why = $__noaudio ? 'não transcreve' : (!$__has ? 'sem chave' : ($__failed ? 'chave com erro' : (($__st && !empty($__st['ok'])) ? 'aprovada' : 'não testada')));
                        ?>
                        <label class="<?php echo $__can ? 'cursor-pointer' : 'cursor-not-allowed opacity-40'; ?>" <?php echo $__can ? '' : 'title="' . htmlspecialchars($__why) . '"'; ?>>
                            <input type="radio" name="ai_role_<?php echo $__r; ?>_provider" value="<?php echo $__p; ?>" class="sr-only peer" <?php echo $__rp === $__p ? 'checked' : ''; ?> <?php echo $__can ? '' : 'disabled'; ?>>
                            <div class="p-2.5 text-center rounded-xl bg-slate-900 border-2 border-slate-800 <?php echo $__can ? 'peer-checked:border-brand-500 peer-checked:bg-brand-500/10 hover:border-slate-600' : ''; ?> transition">
                                <i class="fa-solid <?php echo $__provInfo[$__p]['icon'] . ' ' . $__provInfo[$__p]['cor']; ?> block mb-0.5"></i>
                                <span class="font-bold text-white block"><?php echo htmlspecialchars(explode(' (', $__provInfo[$__p]['nome'])[0]); ?></span>
                                <span class="text-[9px] <?php echo $__can ? (($__st && !empty($__st['ok'])) ? 'text-emerald-400' : 'text-amber-400') : 'text-slate-500'; ?>"><?php echo htmlspecialchars($__why); ?></span>
                            </div>
                        </label>
                        <?php endforeach; ?>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
        </form>

    <?php elseif (in_array($ia_tab, $__ui, true)): $__p = $ia_tab; $__pi = $__provInfo[$__p]; $__has = aiKeyFor($__p) !== ''; $__st = $__has ? aiKeyStatus($__p) : null;
        if (!$__has) { $__bg = 'bg-slate-800 text-slate-400 border-slate-700'; $__tx = '○ Sem chave'; }
        elseif ($__st && !empty($__st['ok'])) { $__bg = 'bg-emerald-500/10 text-emerald-400 border-emerald-500/30'; $__tx = '✓ Chave aprovada'; }
        elseif ($__st) { $__bg = 'bg-rose-500/10 text-rose-400 border-rose-500/30'; $__tx = '✗ Falhou'; }
        else { $__bg = 'bg-amber-500/10 text-amber-400 border-amber-500/30'; $__tx = '● Salva, ainda não testada'; } ?>
        <form id="f_prov" method="POST" action="index.php?module=configuracoes&action=ia&ia_tab=<?php echo $__p; ?>" class="bg-slate-900/90 border border-slate-800 rounded-2xl p-6 shadow-xl space-y-5 text-xs">
            <input type="hidden" name="ai_provider_page" value="<?php echo $__p; ?>">
            <button type="submit" name="action_save_ai_roles" value="1" class="hidden" tabindex="-1" aria-hidden="true"></button>
            <div class="flex items-start justify-between gap-3 flex-wrap">
                <div class="flex items-center gap-3"><i class="fa-solid <?php echo $__pi['icon'] . ' ' . $__pi['cor']; ?> text-2xl"></i>
                    <div><h4 class="text-base font-extrabold text-white"><?php echo htmlspecialchars($__pi['nome']); ?></h4><p class="text-slate-400">O que ele faz aqui: <?php echo htmlspecialchars($__pi['fazem']); ?></p></div></div>
                <button type="submit" name="action_save_ai_roles" value="1" style="<?php echo $__saveBtnStyle; ?>" class="px-4 py-2 bg-brand-600 hover:bg-brand-500 text-white font-extrabold rounded-xl flex items-center gap-2"><i class="fa-solid fa-floppy-disk"></i> Salvar</button>
            </div>
            <div class="p-4 rounded-2xl bg-slate-950 border border-slate-800 space-y-3">
                <div class="flex items-center justify-between"><span class="font-extrabold text-white text-sm">Chave de acesso (API Key)</span><span class="px-2 py-0.5 rounded text-[10px] font-bold border <?php echo $__bg; ?>"><?php echo $__tx; ?></span></div>
                <div class="flex items-center gap-2">
                    <input type="password" name="ai_key_<?php echo $__p; ?>" autocomplete="new-password" placeholder="<?php echo $__has ? htmlspecialchars(aiMaskKey(aiKeyFor($__p))) . '  (salva)' : 'Cole a API Key aqui'; ?>" class="flex-1 min-w-0 px-3.5 py-2.5 bg-slate-900 border <?php echo $__has ? 'border-emerald-500/30' : 'border-slate-800'; ?> rounded-xl text-white font-mono placeholder-slate-300 focus:border-brand-500 focus:outline-none">
                </div>
                <?php if ($__has): ?><div class="text-emerald-400"><i class="fa-solid fa-lock"></i> Chave salva e oculta (<?php echo htmlspecialchars(aiMaskKey(aiKeyFor($__p))); ?>). Para trocar, cole uma nova no campo e clique em Salvar (ela é testada automaticamente).</div><?php endif; ?>
                <?php if ($__st && empty($__st['ok'])): ?><div class="text-rose-300"><?php echo htmlspecialchars((string)($__st['msg'] ?? '')); ?></div><?php endif; ?>
                <?php if ($__st && !empty($__st['ok']) && !empty($__st['t'])): ?><div class="text-slate-500">Testada em <?php echo date('d/m/Y H:i', (int)$__st['t']); ?>.</div><?php endif; ?>
                <div class="flex items-center justify-between flex-wrap gap-2 text-[11px] text-slate-500">
                    <span>Não tem chave? <a href="<?php echo $__pi['url']; ?>" target="_blank" rel="noopener" class="text-brand-400 underline">Gerar chave no site do provedor</a></span>
                    <?php if ($__has && getSetting('ai_key_' . $__p) !== ''): ?><label class="flex items-center gap-1"><input type="checkbox" name="ai_key_clear_<?php echo $__p; ?>" value="1"> remover esta chave</label><?php endif; ?>
                </div>
            </div>
        </form>

    <?php elseif ($ia_tab === 'ajustes'): ?>
        <form id="f_ajustes" method="POST" action="index.php?module=configuracoes&action=ia&ia_tab=ajustes" class="bg-slate-900/90 border border-slate-800 rounded-2xl p-6 shadow-xl space-y-5 text-xs">
            <input type="hidden" name="ai_adv_present" value="1">
            <button type="submit" name="action_save_ai_roles" value="1" class="hidden" tabindex="-1" aria-hidden="true"></button>
            <div class="flex items-start justify-between gap-3 flex-wrap">
                <div><h4 class="text-base font-extrabold text-white">Ajustes do assistente</h4><p class="text-slate-400">Opcional. Só mexa se quiser mudar o jeito que a IA responde.</p></div>
                <button type="submit" name="action_save_ai_roles" value="1" style="<?php echo $__saveBtnStyle; ?>" class="px-4 py-2 bg-brand-600 hover:bg-brand-500 text-white font-extrabold rounded-xl flex items-center gap-2"><i class="fa-solid fa-floppy-disk"></i> Salvar</button>
            </div>
            <div>
                <label class="font-bold text-slate-300 block mb-1">Instrução de tom do assistente <span class="text-slate-500 font-normal">(vazio = padrão do sistema)</span></label>
                <textarea name="ai_custom_prompt" rows="3" placeholder="Ex.: Responda sempre de forma curta e cordial." class="w-full px-3.5 py-2.5 bg-slate-950 border border-slate-800 rounded-xl text-white text-xs focus:border-brand-500 focus:outline-none"><?php echo htmlspecialchars($ai_custom_prompt); ?></textarea>
            </div>
            <div>
                <label class="font-bold text-slate-300 block mb-1">Tamanho máximo das respostas <span class="text-slate-500 font-normal">(tokens)</span></label>
                <input type="number" name="ai_token_limit" value="<?php echo htmlspecialchars($ai_token_limit); ?>" min="64" max="4000" class="w-full sm:w-48 px-3.5 py-2.5 bg-slate-950 border border-slate-800 rounded-xl text-white font-mono focus:border-brand-500 focus:outline-none">
            </div>
            <div>
                <label class="font-bold text-slate-300 block mb-1">Modelo específico por tarefa <span class="text-slate-500 font-normal">(vazio = o painel escolhe o melhor disponível)</span></label>
                <div class="grid grid-cols-1 sm:grid-cols-3 gap-2">
                    <?php foreach (aiRoles() as $__r => $__lbl): ?>
                    <input type="text" name="ai_role_<?php echo $__r; ?>_model" value="<?php echo htmlspecialchars((string)getSetting("ai_role_{$__r}_model")); ?>" placeholder="<?php echo htmlspecialchars($__lbl); ?>" class="px-3 py-2 bg-slate-950 border border-slate-800 rounded-xl text-white font-mono focus:border-brand-500 focus:outline-none">
                    <?php endforeach; ?>
                </div>
            </div>
        </form>
        <form method="POST" onsubmit="return confirm('Isto apaga TODAS as configurações do painel (IA, WhatsApp, regras e demais). Continuar?');" class="mt-4 p-4 rounded-2xl border border-rose-500/30 bg-rose-500/5 flex items-center justify-between gap-3 flex-wrap text-xs">
            <input type="hidden" name="action_reset_defaults" value="1">
            <div><div class="font-bold text-rose-300">Restaurar padrões de fábrica</div><div class="text-slate-400">Atenção: apaga todas as configurações do painel, não só as de IA.</div></div>
            <button type="submit" style="<?php echo $__saveBtnStyle; ?>" class="px-3.5 py-2 bg-rose-600/10 hover:bg-rose-600/20 text-rose-400 border border-rose-500/30 rounded-xl font-bold flex items-center gap-1.5"><i class="fa-solid fa-rotate-left"></i> Restaurar padrões</button>
        </form>

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
                        <p class="text-slate-500 text-[10px] mt-1">Não tem chave? <a href="https://elevenlabs.io/app/settings/api-keys" target="_blank" rel="noopener" class="text-brand-400 underline">Gerar chave no site do provedor</a></p>
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
    </div>
</div>

<script>
// ─── ElevenLabs: teste de voz ────────────────────────────────────────────
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
