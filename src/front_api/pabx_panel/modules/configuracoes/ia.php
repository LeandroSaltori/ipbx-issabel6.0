<?php
/**
 * IPbx Prisma - Configurações de IA & Integrações v8.0 (Redesign Dashboard de IA Universal)
 * Seções organizadas: Copiloto Prismabot, ElevenLabs TTS, Status de Integrações Globais
 */

$msg      = '';
$msg_type = 'success';

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

        $vals = [
            ':p'  => trim($_POST['ai_provider']     ?? 'openai'),
            ':k'  => trim($_POST['ai_api_key']      ?? ''),
            ':b'  => trim($_POST['ai_base_url']     ?? 'https://api.openai.com/v1'),
            ':mc' => $m_chat,
            ':ma' => $m_audio,
            ':ec' => isset($_POST['enable_copilot']) ? '1' : '0',
            ':pr' => trim($_POST['ai_custom_prompt']?? ''),
            ':tl' => trim($_POST['ai_token_limit']  ?? '1024'),
        ];
        $db->prepare("INSERT OR REPLACE INTO settings (key_name, value_val) VALUES
            ('ai_provider',:p),('ai_api_key',:k),('ai_base_url',:b),('ai_model_chat',:mc),('ai_model_audio',:ma),('enable_copilot',:ec),('ai_custom_prompt',:pr),('ai_token_limit',:tl)")
           ->execute($vals);
        $msg = "Configurações Universais do Copiloto de IA salvas com sucesso!";
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

        <!-- Cards Status de Conectividade Global -->
        <div class="grid grid-cols-1 sm:grid-cols-3 gap-3 pt-2">
            <div class="p-3.5 rounded-xl bg-slate-950 border border-slate-800 flex items-center justify-between">
                <div>
                    <span class="text-[10px] uppercase font-mono text-slate-500 font-bold block">Motor de IA LLM</span>
                    <strong class="text-xs text-white capitalize flex items-center gap-1.5 mt-0.5">
                        <i class="fa-solid fa-brain text-purple-400"></i> <?php echo htmlspecialchars($ai_provider); ?>
                    </strong>
                </div>
                <span class="px-2 py-0.5 rounded text-[9px] font-bold font-mono border <?php echo !empty($ai_api_key) ? 'bg-emerald-500/10 text-emerald-400 border-emerald-500/20' : 'bg-rose-500/10 text-rose-400 border-rose-500/20'; ?>">
                    <?php echo !empty($ai_api_key) ? '● API Chave OK' : '○ Sem API Key'; ?>
                </span>
            </div>

            <div class="p-3.5 rounded-xl bg-slate-950 border border-slate-800 flex items-center justify-between">
                <div>
                    <span class="text-[10px] uppercase font-mono text-slate-500 font-bold block">Transcrição & STT</span>
                    <strong class="text-xs text-white font-mono flex items-center gap-1.5 mt-0.5">
                        <i class="fa-solid fa-waveform text-cyan-400"></i> <?php echo htmlspecialchars($ai_model_audio); ?>
                    </strong>
                </div>
                <span class="px-2 py-0.5 rounded text-[9px] font-bold font-mono border bg-cyan-500/10 text-cyan-300 border-cyan-500/20">
                    Ativo
                </span>
            </div>

            <div class="p-3.5 rounded-xl bg-slate-950 border border-slate-800 flex items-center justify-between">
                <div>
                    <span class="text-[10px] uppercase font-mono text-slate-500 font-bold block">Síntese ElevenLabs</span>
                    <strong class="text-xs text-white font-mono flex items-center gap-1.5 mt-0.5">
                        <i class="fa-solid fa-microphone text-amber-400"></i> <?php echo $el_enabled === '1' ? 'Habilitado' : 'Desabilitado'; ?>
                    </strong>
                </div>
                <span class="px-2 py-0.5 rounded text-[9px] font-bold font-mono border <?php echo ($el_enabled === '1' && !empty($el_api_key)) ? 'bg-amber-500/10 text-amber-300 border-amber-500/20' : 'bg-slate-800 text-slate-500 border-slate-700'; ?>">
                    <?php echo ($el_enabled === '1' && !empty($el_api_key)) ? '● Ativo' : '○ Inativo'; ?>
                </span>
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
        <?php if (empty($ai_api_key)): ?>
        <div class="p-4 rounded-xl bg-amber-500/10 text-amber-300 border border-amber-500/20 text-xs font-bold flex items-center gap-2">
            <i class="fa-solid fa-triangle-exclamation text-amber-400"></i>
            Nenhuma API Key configurada. O Copiloto usará dados demonstrativos até que uma chave seja inserida abaixo.
        </div>
        <?php endif; ?>

        <div class="bg-slate-900/90 border border-slate-800 rounded-2xl p-6 shadow-xl max-w-4xl space-y-6">
            <div class="flex items-center gap-3 border-b border-slate-800 pb-4">
                <div class="w-10 h-10 rounded-xl bg-brand-500/20 text-brand-400 border border-brand-500/30 flex items-center justify-center text-lg">
                    <i class="fa-solid fa-robot"></i>
                </div>
                <div>
                    <h4 class="text-sm font-extrabold text-white">Provedor LLM Universal & Copiloto Prismabot</h4>
                    <span class="text-xs text-slate-400">Configuração universal compartilhada por todos os usuários do PABX</span>
                </div>
            </div>

            <form method="POST" action="index.php?module=configuracoes&action=ia&ia_tab=copiloto" class="space-y-5 text-xs">
                <input type="hidden" name="action_save_ia" value="1">

                <!-- Toggle Ativo -->
                <div class="flex items-center justify-between p-4 bg-slate-950 rounded-2xl border border-slate-800">
                    <div>
                        <span class="font-extrabold text-white text-sm block">Copiloto de IA Ativo</span>
                        <span class="text-slate-400">Habilita a assistente virtual e resumos automáticos no painel do PABX</span>
                    </div>
                    <label class="relative inline-flex items-center cursor-pointer">
                        <input type="checkbox" name="enable_copilot" value="1" class="sr-only peer" <?php echo $enable_copilot === '1' ? 'checked' : ''; ?>>
                        <div class="w-11 h-6 bg-slate-700 peer-focus:outline-none rounded-full peer peer-checked:after:translate-x-full peer-checked:after:border-white after:content-[''] after:absolute after:top-[2px] after:left-[2px] after:bg-white after:border-gray-300 after:border after:rounded-full after:h-5 after:w-5 after:transition-all peer-checked:bg-brand-600"></div>
                    </label>
                </div>

                <!-- Provedor LLM -->
                <div>
                    <label class="font-bold text-slate-300 block mb-2 flex items-center gap-1">
                        <i class="fa-solid fa-layer-group text-brand-400"></i> Selecionar Provedor Universal
                    </label>
                    <div class="grid grid-cols-2 sm:grid-cols-4 gap-2.5">
                        <?php foreach (['openai' => ['label'=>'OpenAI','icon'=>'fa-brain','color'=>'emerald'], 'groq' => ['label'=>'Groq','icon'=>'fa-bolt','color'=>'orange'], 'gemini' => ['label'=>'Gemini','icon'=>'fa-google','color'=>'blue'], 'deepseek' => ['label'=>'DeepSeek','icon'=>'fa-circle-nodes','color'=>'purple']] as $pv => $pi): ?>
                        <label class="cursor-pointer">
                            <input type="radio" name="ai_provider" value="<?php echo $pv; ?>" class="sr-only peer" <?php echo $ai_provider === $pv ? 'checked' : ''; ?> onchange="updateBaseUrlForProvider('<?php echo $pv; ?>')">
                            <div class="p-3 bg-slate-950 border-2 border-slate-800 peer-checked:border-brand-500 rounded-xl text-center space-y-1 transition hover:border-slate-600">
                                <i class="fa-solid <?php echo $pi['icon']; ?> text-<?php echo $pi['color']; ?>-400 text-lg block"></i>
                                <span class="font-bold text-white text-xs block"><?php echo $pi['label']; ?></span>
                            </div>
                        </label>
                        <?php endforeach; ?>
                    </div>
                </div>

                <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                    <div>
                        <label class="font-bold text-slate-300 block mb-1 flex items-center gap-1"><i class="fa-solid fa-key text-amber-400"></i> API Key Universal *</label>
                        <input type="password" name="ai_api_key" value="<?php echo htmlspecialchars($ai_api_key); ?>" placeholder="sk-...  |  gsk_...  |  AIza..."
                               class="w-full px-3.5 py-2.5 bg-slate-950 border border-slate-800 rounded-xl text-white font-mono focus:border-brand-500 focus:outline-none">
                    </div>
                    <div>
                        <label class="font-bold text-slate-300 block mb-1 flex items-center justify-between">
                            <span>URL Base da API <span class="text-slate-500 font-normal text-[10px]">(opcional)</span></span>
                        </label>
                        <input type="text" name="ai_base_url" id="ai_base_url_input" value="<?php echo htmlspecialchars($ai_base_url); ?>" placeholder="https://api.openai.com/v1"
                               class="w-full px-3.5 py-2.5 bg-slate-950 border border-slate-800 rounded-xl text-white font-mono placeholder-slate-600 focus:border-brand-500 focus:outline-none">
                    </div>
                    <div>
                        <label class="font-bold text-slate-300 block mb-1 flex items-center justify-between">
                            <span>Modelo de Chat (LLM)</span>
                        </label>
                        <select name="ai_model_chat" id="ai_model_chat_select" onchange="checkCustomModelInput('chat')"
                                class="w-full px-3.5 py-2.5 bg-slate-950 border border-slate-800 rounded-xl text-white font-mono focus:border-brand-500 focus:outline-none">
                            <option value="gpt-4o-mini" <?php echo $ai_model_chat === 'gpt-4o-mini' ? 'selected' : ''; ?>>gpt-4o-mini (Recomendado / Econômico)</option>
                            <option value="gpt-4o" <?php echo $ai_model_chat === 'gpt-4o' ? 'selected' : ''; ?>>gpt-4o (Mais Inteligente)</option>
                            <option value="gpt-3.5-turbo" <?php echo $ai_model_chat === 'gpt-3.5-turbo' ? 'selected' : ''; ?>>gpt-3.5-turbo</option>
                            <option value="custom" <?php echo !in_array($ai_model_chat, ['gpt-4o-mini','gpt-4o','gpt-3.5-turbo','llama-3.3-70b-versatile','llama3-8b-8192','gemini-1.5-flash','deepseek-chat']) ? 'selected' : ''; ?>>Outro modelo (Digitar manualmente...)</option>
                        </select>
                        <input type="text" id="ai_model_chat_custom" name="ai_model_chat_custom" value="<?php echo htmlspecialchars($ai_model_chat); ?>" placeholder="Ex: gpt-4-turbo"
                               class="w-full px-3.5 py-2.5 bg-slate-950 border border-slate-800 rounded-xl text-white font-mono mt-2 focus:border-brand-500 focus:outline-none hidden">
                    </div>

                    <div>
                        <label class="font-bold text-slate-300 block mb-1 flex items-center justify-between">
                            <span>Modelo de Áudio / Transcrição (STT)</span>
                        </label>
                        <select name="ai_model_audio" id="ai_model_audio_select" onchange="checkCustomModelInput('audio')"
                                class="w-full px-3.5 py-2.5 bg-slate-950 border border-slate-800 rounded-xl text-white font-mono focus:border-brand-500 focus:outline-none">
                            <option value="whisper-1" <?php echo $ai_model_audio === 'whisper-1' ? 'selected' : ''; ?>>whisper-1 (Oficial OpenAI STT)</option>
                            <option value="custom" <?php echo !in_array($ai_model_audio, ['whisper-1','whisper-large-v3','distil-whisper-large-v3-en']) ? 'selected' : ''; ?>>Outro modelo de áudio...</option>
                        </select>
                        <input type="text" id="ai_model_audio_custom" name="ai_model_audio_custom" value="<?php echo htmlspecialchars($ai_model_audio); ?>" placeholder="Ex: whisper-1"
                               class="w-full px-3.5 py-2.5 bg-slate-950 border border-slate-800 rounded-xl text-white font-mono mt-2 focus:border-brand-500 focus:outline-none hidden">
                    </div>
                </div>

                <!-- Prompt Personalizado -->
                <div>
                    <label class="font-bold text-slate-300 block mb-1 flex items-center justify-between">
                        <span><i class="fa-solid fa-terminal text-brand-400"></i> Prompt Personalizado do Copiloto</span>
                        <span class="text-[10px] text-slate-500 font-normal">Instrução de tom e regras do PABX</span>
                    </label>
                    <textarea name="ai_custom_prompt" rows="3" placeholder="Deixe em branco para usar o prompt padrão do sistema. Ex: Você é um assistente do PABX Prisma. Responda sempre de forma concisa e cortês."
                              class="w-full px-3.5 py-2.5 bg-slate-950 border border-slate-800 rounded-xl text-white font-mono text-xs focus:border-brand-500 focus:outline-none"><?php echo htmlspecialchars($ai_custom_prompt); ?></textarea>
                </div>

                <!-- Limite de Tokens -->
                <div>
                    <label class="font-bold text-slate-300 block mb-1 flex items-center justify-between">
                        <span><i class="fa-solid fa-gauge-high text-cyan-400"></i> Limite de Tokens na Resposta (Max Tokens)</span>
                    </label>
                    <input type="number" name="ai_token_limit" value="<?php echo htmlspecialchars($ai_token_limit); ?>" placeholder="1024"
                           class="w-full px-3.5 py-2.5 bg-slate-950 border border-slate-800 rounded-xl text-white font-mono focus:border-brand-500 focus:outline-none">
                </div>

                <div class="flex items-center justify-between pt-2 gap-3 flex-wrap">
                    <p class="text-slate-500 text-[11px] flex items-center gap-1"><i class="fa-solid fa-shield text-brand-400"></i> Chaves salvas universalmente no banco local SQLite.</p>
                    
                    <div class="flex items-center gap-2">
                        <button type="button" onclick="testLlmConnection()" id="btn-test-llm" class="px-4 py-2.5 bg-slate-800 hover:bg-slate-700 text-slate-200 font-extrabold rounded-xl transition border border-slate-700 flex items-center gap-2">
                            <i class="fa-solid fa-plug-circle-check text-brand-400"></i> Testar Conexão IA
                        </button>

                        <button type="submit" class="px-5 py-2.5 bg-brand-600 hover:bg-brand-500 text-white font-extrabold rounded-xl transition shadow-lg shadow-brand-600/20 flex items-center gap-2">
                            <i class="fa-solid fa-floppy-disk"></i> Salvar Copiloto IA
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
    const baseUrl   = document.getElementById('ai_base_url_input')?.value || '';
    
    let selChat = document.getElementById('ai_model_chat_select')?.value;
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
