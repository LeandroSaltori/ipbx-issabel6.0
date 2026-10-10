<?php
/**
 * IPbx Prisma - Fluxograma Central de Ligações (Layout Árvore Visual) v7.2
 * Design ultra-intuitivo e 100% dinâmico baseado no set destination real do Asterisk
 */
$flows = getIssabelInboundCallFlows();

if (!function_exists('renderDynamicFlowNode')) {
    function renderDynamicFlowNode($node, $routeId, $nodePath = 'root') {
        if (empty($node)) return;

        $type = $node['type'] ?? 'dest';

        switch ($type) {
            case 'timecondition':
                $tg = $node['timegroup'] ?? [];
                $tgRules = $tg['rules'] ?? [];
                ?>
                <!-- NÓ DE DECISÃO: TIME CONDITION -->
                <div id="node-<?php echo $routeId; ?>-<?php echo $nodePath; ?>" class="flow-tree-node space-y-6">
                    <!-- CARD DIAMOND DE DECISÃO DE HORÁRIO -->
                    <div class="node-diamond-decision max-w-2xl mx-auto p-6 rounded-3xl text-center space-y-4 relative shadow-2xl">
                        <div class="flex items-center justify-between border-b border-amber-500/30 pb-3">
                            <span class="inline-flex items-center gap-2 px-3 py-1 bg-amber-500/20 text-amber-300 border border-amber-500/40 rounded-full text-xs font-black uppercase">
                                <i class="fa-solid fa-clock"></i> <?php echo htmlspecialchars($node['title']); ?>
                            </span>
                            <span class="px-2.5 py-0.5 bg-amber-500/30 text-amber-200 text-[10px] font-mono rounded">Filtro de Horário</span>
                        </div>

                        <!-- EXIBIÇÃO DETALHADA DAS REGRAS DO TIME GROUP DO PABX -->
                        <div class="bg-slate-950/80 p-4 rounded-2xl border border-amber-500/30 text-left space-y-2">
                            <div class="flex items-center justify-between">
                                <span class="text-xs font-extrabold text-amber-300 flex items-center gap-1.5">
                                    <i class="fa-solid fa-calendar-days text-amber-400"></i> Grupo de Horários: <?php echo htmlspecialchars($tg['name'] ?? 'Horário PABX'); ?>
                                </span>
                                <span class="text-[10px] text-slate-400 font-mono">ID: #<?php echo htmlspecialchars($tg['id'] ?? 'N/A'); ?></span>
                            </div>

                            <div class="space-y-1.5 pt-1">
                                <?php foreach ($tgRules as $ruleText): ?>
                                    <div class="text-xs text-slate-200 bg-slate-900/90 px-3 py-1.5 rounded-xl border border-amber-500/20 flex items-center gap-2">
                                        <i class="fa-solid fa-clock-check text-amber-400 text-xs"></i>
                                        <span><?php echo htmlspecialchars($ruleText); ?></span>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        </div>

                        <p class="text-xs text-slate-300">Clique em uma opção para desdobrar a ramificação de atendimento do PABX:</p>

                        <!-- BOTÕES DE RAMIFICAÇÃO: SIM vs NÃO -->
                        <div class="flex flex-col sm:flex-row items-center justify-center gap-4 pt-1">
                            <button onclick="toggleTreeBranch('branch-<?php echo $routeId; ?>-<?php echo $nodePath; ?>-true', this)" 
                                    class="px-5 py-2.5 bg-emerald-600 hover:bg-emerald-500 text-white rounded-2xl text-xs font-extrabold transition flex items-center gap-2 shadow-lg shadow-emerald-600/30">
                                <i class="fa-solid fa-circle-check"></i> <span>[ + ] SIM (Dentro do Horário Configurado)</span>
                            </button>
                            
                            <button onclick="toggleTreeBranch('branch-<?php echo $routeId; ?>-<?php echo $nodePath; ?>-false', this)" 
                                    class="px-5 py-2.5 bg-amber-600 hover:bg-amber-500 text-white rounded-2xl text-xs font-extrabold transition flex items-center gap-2 shadow-lg shadow-amber-600/30">
                                <i class="fa-solid fa-circle-xmark"></i> <span>[ + ] NÃO (Fora do Horário Configurado / Feriado)</span>
                            </button>
                        </div>
                    </div>

                    <!-- ÁREA DE RAMIFICAÇÃO DUPLA (SIM / NÃO) -->
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-8 items-start pt-2">
                        
                        <!-- RAMO SIM: DENTRO DO HORÁRIO -->
                        <div id="branch-<?php echo $routeId; ?>-<?php echo $nodePath; ?>-true" class="flow-tree-node hidden space-y-4">
                            <div class="flex items-center gap-2 text-emerald-400 font-extrabold text-xs border-b border-emerald-500/30 pb-2">
                                <i class="fa-solid fa-circle-check"></i> 🟢 SE DENTRO DO HORÁRIO: <?php echo htmlspecialchars($node['true_label'] ?? 'Ir para Destino'); ?>
                            </div>

                            <?php if (!empty($node['true_node'])): ?>
                                <?php renderDynamicFlowNode($node['true_node'], $routeId, $nodePath . '-true'); ?>
                            <?php endif; ?>
                        </div>

                        <!-- RAMO NÃO: FORA DO HORÁRIO / FERIADO -->
                        <div id="branch-<?php echo $routeId; ?>-<?php echo $nodePath; ?>-false" class="flow-tree-node hidden space-y-4">
                            <div class="flex items-center gap-2 text-amber-400 font-extrabold text-xs border-b border-amber-500/30 pb-2">
                                <i class="fa-solid fa-circle-xmark"></i> 🔴 SE FORA DO HORÁRIO / FERIADO: <?php echo htmlspecialchars($node['false_label'] ?? 'Ir para Destino'); ?>
                            </div>

                            <?php if (!empty($node['false_node'])): ?>
                                <?php renderDynamicFlowNode($node['false_node'], $routeId, $nodePath . '-false'); ?>
                            <?php endif; ?>
                        </div>

                    </div>
                </div>
                <?php
                break;

            case 'ringgroup':
                $rgMembers = $node['members'] ?? [];
                ?>
                <!-- NÓ GRUPO DE RAMAIS (RING GROUP) -->
                <div id="node-<?php echo $routeId; ?>-<?php echo $nodePath; ?>" class="flow-tree-node space-y-5">
                    <div class="bg-gradient-to-r from-indigo-950/80 to-slate-900 border border-indigo-500/40 rounded-3xl p-6 space-y-5 shadow-2xl">
                        <!-- Cabeçalho do Grupo de Ramais -->
                        <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-3 border-b border-indigo-500/30 pb-4">
                            <div>
                                <div class="flex items-center gap-2">
                                    <span class="px-2.5 py-0.5 bg-indigo-500/30 text-indigo-200 text-[10px] font-black rounded uppercase tracking-wider">GRUPO DE RAMAIS</span>
                                    <h5 class="text-base font-black text-white flex items-center gap-2">
                                        <i class="fa-solid fa-layer-group text-indigo-400"></i> <?php echo htmlspecialchars($node['title']); ?>
                                    </h5>
                                </div>
                                <span class="text-xs text-indigo-200/80">Estratégia de Toque: <strong class="text-white"><?php echo htmlspecialchars($node['strategy_label'] ?? 'Simultâneo'); ?></strong></span>
                            </div>

                            <div class="flex items-center gap-2">
                                <span class="px-2.5 py-1 bg-cyan-500/20 text-cyan-300 border border-cyan-500/30 text-[10px] font-bold rounded-xl flex items-center gap-1.5">
                                    <i class="fa-solid fa-stopwatch"></i> Tempo de Toque: <?php echo htmlspecialchars($node['ring_time'] ?? '20'); ?>s
                                </span>
                            </div>
                        </div>

                        <!-- INTEGRANTES DO GRUPO DE RAMAIS -->
                        <div class="space-y-2 bg-slate-950/70 p-4 rounded-2xl border border-indigo-500/20">
                            <span class="text-xs font-black text-indigo-300 uppercase tracking-wide flex items-center gap-1.5">
                                <i class="fa-solid fa-headset text-indigo-400"></i> Ramais Integrantes do Grupo:
                            </span>

                            <div class="flex flex-wrap gap-2 pt-1">
                                <?php if (!empty($rgMembers)): 
                                    foreach ($rgMembers as $rmem):
                                ?>
                                    <div class="px-3 py-1.5 bg-indigo-500/20 border border-indigo-500/40 text-indigo-200 rounded-xl text-xs font-bold flex items-center gap-2 shadow">
                                        <i class="fa-solid fa-phone"></i>
                                        <span>Ramal <?php echo htmlspecialchars($rmem['extension']); ?> (<?php echo htmlspecialchars($rmem['name']); ?>)</span>
                                    </div>
                                <?php 
                                    endforeach;
                                else: 
                                ?>
                                    <span class="text-xs text-slate-300 italic">Nenhum ramal cadastrado neste grupo.</span>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>

                    <!-- TRANSBORDO / FAILOVER SE HOUVER -->
                    <?php if (!empty($node['failover_node'])): ?>
                        <div class="flex flex-col items-center pt-2 space-y-2">
                            <div class="tree-connector-v h-6"></div>
                            <div class="w-full space-y-2">
                                <div class="flex items-center justify-center gap-2 text-rose-300 font-bold text-xs">
                                    <i class="fa-solid fa-right-left text-rose-400"></i> Encaminhamento de Transbordo (Sem Atendimento no Grupo):
                                </div>
                                <?php renderDynamicFlowNode($node['failover_node'], $routeId, $nodePath . '-failover'); ?>
                            </div>
                        </div>
                    <?php endif; ?>
                </div>
                <?php
                break;

            case 'queue':
                $qDetails = $node['details'] ?? [];
                $mList = $qDetails['members'] ?? [];
                ?>
                <!-- NÓ FILA DE ATENDIMENTO REAL DO PABX -->
                <div id="node-<?php echo $routeId; ?>-<?php echo $nodePath; ?>" class="flow-tree-node space-y-5">
                    <div class="node-card-queue p-6 rounded-3xl space-y-5 border border-purple-500/40 shadow-2xl">
                        <!-- Cabeçalho da Fila -->
                        <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-3 border-b border-purple-500/30 pb-4">
                            <div>
                                <div class="flex items-center gap-2">
                                    <span class="px-2.5 py-0.5 bg-purple-500/30 text-purple-200 text-[10px] font-black rounded uppercase tracking-wider">FILA DE ESPERA PABX</span>
                                    <h5 class="text-base font-black text-white flex items-center gap-2">
                                        <i class="fa-solid fa-users-line text-purple-400"></i> <?php echo htmlspecialchars($node['title']); ?>
                                    </h5>
                                </div>
                                <span class="text-xs text-purple-200/80">Estratégia de Toque: <strong class="text-white"><?php echo htmlspecialchars($qDetails['strategy_label'] ?? 'Ringall'); ?></strong></span>
                            </div>

                            <div class="flex items-center gap-2 flex-wrap">
                                <span class="px-2.5 py-1 bg-emerald-500/20 text-emerald-300 border border-emerald-500/30 text-[10px] font-bold rounded-xl flex items-center gap-1.5">
                                    <i class="fa-solid fa-microphone"></i> Gravação: <?php echo htmlspecialchars($qDetails['recording'] ?? 'Ativa'); ?>
                                </span>
                                <span class="px-2.5 py-1 bg-cyan-500/20 text-cyan-300 border border-cyan-500/30 text-[10px] font-bold rounded-xl flex items-center gap-1.5">
                                    <i class="fa-solid fa-clock"></i> Espera Máx: <?php echo htmlspecialchars($qDetails['max_wait_time_label'] ?? '2 min'); ?>
                                </span>
                            </div>
                        </div>

                        <!-- SEÇÃO MEMBROS E PENALIDADES DA FILA -->
                        <div class="space-y-2 bg-slate-950/70 p-4 rounded-2xl border border-purple-500/20">
                            <div class="flex items-center justify-between">
                                <span class="text-xs font-black text-purple-300 uppercase tracking-wide flex items-center gap-1.5">
                                    <i class="fa-solid fa-headset text-purple-400"></i> Agentes Integrantes (Membros da Fila):
                                </span>
                                <span class="text-[10px] text-slate-400 font-mono">Penalidade 0 (1º Nível) | 1+ (Transbordo)</span>
                            </div>

                            <div class="flex flex-wrap gap-2 pt-1">
                                <?php if (!empty($mList)): 
                                    foreach ($mList as $mem):
                                        $isSec = ($mem['penalty'] > 0);
                                        $badgeBg = $isSec ? 'bg-amber-500/20 text-amber-300 border-amber-500/40' : 'bg-emerald-500/20 text-emerald-300 border-emerald-500/40';
                                        $pIcon = $isSec ? 'fa-user-clock' : 'fa-user-check';
                                ?>
                                    <div class="px-3 py-1.5 <?php echo $badgeBg; ?> border rounded-xl text-xs font-bold flex items-center gap-2 shadow">
                                        <i class="fa-solid <?php echo $pIcon; ?>"></i>
                                        <span>Ramal <?php echo htmlspecialchars($mem['extension']); ?> (<?php echo htmlspecialchars($mem['name']); ?>)</span>
                                        <span class="px-1.5 py-0.2 bg-slate-900/80 rounded text-[9px] font-mono">Penalidade <?php echo htmlspecialchars($mem['penalty']); ?></span>
                                    </div>
                                <?php 
                                    endforeach;
                                else: 
                                ?>
                                    <span class="text-xs text-slate-300 italic">Nenhum ramal cadastrado nesta fila.</span>
                                <?php endif; ?>
                            </div>

                            <div class="text-[10px] text-purple-200/80 pt-1.5 font-mono flex items-center gap-1.5 border-t border-purple-500/10 mt-2">
                                <i class="fa-solid fa-circle-info text-cyan-400 text-xs"></i>
                                <span><?php 
                                    $p0 = []; $p1 = [];
                                    if (!empty($mList)) {
                                        foreach ($mList as $mem) {
                                            $mLabel = "Ramal {$mem['extension']}" . (!empty($mem['name']) && $mem['name'] !== "Ramal {$mem['extension']}" ? " ({$mem['name']})" : "");
                                            if ($mem['penalty'] == 0) $p0[] = $mLabel;
                                            else $p1[] = "$mLabel [Penalidade {$mem['penalty']}]";
                                        }
                                    }
                                    if (!empty($p0)) {
                                        echo "<strong>Escalonamento por Penalidades:</strong> Ramais com <strong>Penalidade 0</strong> (" . htmlspecialchars(implode(', ', $p0)) . ") tocam em 1º nível.";
                                        if (!empty($p1)) {
                                            echo " Se estiverem ocupados ou indisponíveis, a chamada escala para " . htmlspecialchars(implode(', ', $p1)) . ".";
                                        } else {
                                            echo " Não há ramais de suporte/transbordo com penalidade superior cadastrados.";
                                        }
                                    } else {
                                        echo "<strong>Regra de Penalidades:</strong> Nenhum ramal configurado nesta fila.";
                                    }
                                ?></span>
                            </div>
                        </div>

                        <!-- PARÂMETROS DA FILA -->
                        <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-3 text-xs">
                            <div class="p-3 bg-slate-950/80 rounded-2xl border border-slate-800 space-y-2 shadow">
                                <div class="text-[11px] font-black text-amber-400 uppercase flex items-center gap-1.5 border-b border-slate-800 pb-1">
                                    <i class="fa-solid fa-stopwatch"></i> Horários & Agentes
                                </div>
                                <div class="space-y-1 text-[11px] text-slate-300">
                                    <div>⏱️ Espera Máx: <strong class="text-white"><?php echo htmlspecialchars($qDetails['max_wait_time_label'] ?? '2 min'); ?></strong> (<?php echo htmlspecialchars($qDetails['max_wait_mode'] ?? 'Estrito'); ?>)</div>
                                    <div>📞 Tempo Agente: <span class="text-amber-200"><?php echo htmlspecialchars($qDetails['agent_timeout'] ?? 'Ilimitado'); ?></span></div>
                                    <div>🔄 Re-tentativa: <span class="text-slate-200"><?php echo htmlspecialchars($qDetails['retry'] ?? '5s'); ?></span></div>
                                    <div>☕ Pós-atendimento: <span class="text-slate-200"><?php echo htmlspecialchars($qDetails['wrap_up_time'] ?? '0s'); ?></span></div>
                                </div>
                            </div>

                            <div class="p-3 bg-slate-950/80 rounded-2xl border border-slate-800 space-y-2 shadow">
                                <div class="text-[11px] font-black text-cyan-400 uppercase flex items-center gap-1.5 border-b border-slate-800 pb-1">
                                    <i class="fa-solid fa-bullhorn"></i> Capacidade & Anúncios
                                </div>
                                <div class="space-y-1 text-[11px] text-slate-300">
                                    <div>👥 Capacidade Máxima: <strong class="text-white"><?php echo htmlspecialchars($qDetails['max_callers'] ?? 'Sem Limite'); ?></strong></div>
                                    <div>🚪 Entrar se Vazia: <span class="text-cyan-200"><?php echo htmlspecialchars($qDetails['join_empty'] ?? 'Sim'); ?></span></div>
                                    <div>🚶 Sair se Vazia: <span class="text-slate-200"><?php echo htmlspecialchars($qDetails['leave_empty'] ?? 'Não'); ?></span></div>
                                    <div>📢 Anúncio Posição: <span class="text-cyan-200"><?php echo htmlspecialchars($qDetails['announce_frequency'] ?? '30s'); ?></span></div>
                                </div>
                            </div>

                            <div class="p-3 bg-slate-950/80 rounded-2xl border border-slate-800 space-y-2 shadow">
                                <div class="text-[11px] font-black text-purple-400 uppercase flex items-center gap-1.5 border-b border-slate-800 pb-1">
                                    <i class="fa-solid fa-sliders"></i> Gravação & PABX
                                </div>
                                <div class="space-y-1 text-[11px] text-slate-300">
                                    <div>🎙️ Gravação: <strong class="text-white"><?php echo htmlspecialchars($qDetails['recording'] ?? 'Ativa'); ?></strong></div>
                                    <div>🔊 Modo: <span class="text-purple-200"><?php echo htmlspecialchars($qDetails['recording_mode'] ?? 'Incluir Tempo de Espera'); ?></span></div>
                                    <div>⚡ Tecnologia: <span class="text-purple-300 font-bold">SIP / PJSIP Asterisk</span></div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- TRANSBORDO / FAILOVER SE HOUVER -->
                    <?php if (!empty($node['failover_node'])): ?>
                        <div class="flex flex-col items-center pt-2 space-y-2">
                            <div class="tree-connector-v h-6"></div>
                            <div class="w-full space-y-2">
                                <div class="flex items-center justify-center gap-2 text-rose-300 font-bold text-xs">
                                    <i class="fa-solid fa-right-left text-rose-400"></i> Encaminhamento de Transbordo (Estouro de Espera na Fila):
                                </div>
                                <?php renderDynamicFlowNode($node['failover_node'], $routeId, $nodePath . '-failover'); ?>
                            </div>
                        </div>
                    <?php endif; ?>
                </div>
                <?php
                break;

            case 'announcement':
                ?>
                <!-- NÓ ANÚNCIO DE VOZ -->
                <div id="node-<?php echo $routeId; ?>-<?php echo $nodePath; ?>" class="flow-tree-node space-y-4">
                    <div class="node-card-night p-5 rounded-2xl space-y-3">
                        <div class="flex items-center justify-between border-b border-amber-500/30 pb-2">
                            <span class="text-xs font-black text-amber-200 flex items-center gap-2">
                                <i class="fa-solid fa-volume-high text-amber-400"></i> <?php echo htmlspecialchars($node['title']); ?>
                            </span>
                            <span class="px-2 py-0.5 bg-amber-500/30 text-amber-200 text-[9px] font-bold rounded">Áudio PABX</span>
                        </div>
                        
                        <div class="text-xs text-slate-200 space-y-3">
                            <?php if (!empty($node['audio_file'])): ?>
                                <div class="flex items-center gap-2">
                                    <i class="fa-solid fa-file-audio text-amber-400"></i> Arquivo de Áudio: <code class="bg-slate-900 px-2 py-0.5 rounded text-amber-300 text-[10px] font-mono"><?php echo htmlspecialchars($node['audio_file']); ?></code>
                                </div>
                                
                                <!-- Player de Áudio 100% Funcional (get_audio.php) -->
                                <div class="p-3 bg-slate-950/90 rounded-xl border border-amber-500/40 flex flex-col sm:flex-row items-center justify-between gap-3 shadow">
                                    <div class="flex items-center gap-2 text-xs font-bold text-amber-300">
                                        <i class="fa-solid fa-circle-play text-amber-400 text-base"></i>
                                        <span>Ouvir Mensagem</span>
                                    </div>
                                    
                                    <audio controls controlsList="nodownload" class="h-8 max-w-full sm:w-64 text-xs opacity-95">
                                        <source src="get_audio.php?file=<?php echo urlencode($node['audio_file']); ?>" type="audio/wav">
                                        Seu navegador não suporta o elemento de áudio.
                                    </audio>
                                </div>
                            <?php else: ?>
                                <div class="text-xs text-slate-300 italic">Mensagem sonora configurada no Asterisk sem gravação customizada.</div>
                            <?php endif; ?>

                            <div class="flex items-center justify-between pt-1 text-[11px]">
                                <button onclick="speakAnnouncementText('Mensagem do PABX: <?php echo addslashes(htmlspecialchars($node['title'])); ?>.')" 
                                        class="px-3 py-1.5 bg-amber-500/20 hover:bg-amber-500/40 text-amber-300 border border-amber-500/30 rounded-lg font-bold transition flex items-center gap-1.5">
                                    <i class="fa-solid fa-bullhorn text-amber-400"></i> <span>Voz Sintetizada (pt-BR)</span>
                                </button>
                            </div>
                        </div>
                    </div>

                    <?php if (!empty($node['next_node'])): ?>
                        <div class="flex flex-col items-center space-y-2">
                            <div class="tree-connector-v h-4"></div>
                            <?php renderDynamicFlowNode($node['next_node'], $routeId, $nodePath . '-annnext'); ?>
                        </div>
                    <?php endif; ?>
                </div>
                <?php
                break;

            case 'ivr':
                ?>
                <!-- NÓ URA / IVR REAL DO PABX -->
                <div id="node-<?php echo $routeId; ?>-<?php echo $nodePath; ?>" class="flow-tree-node space-y-4">
                    <div class="bg-gradient-to-r from-cyan-950/80 to-slate-900 border border-cyan-500/40 rounded-3xl p-6 space-y-4 shadow-2xl">
                        <!-- Cabeçalho URA -->
                        <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-3 border-b border-cyan-500/30 pb-3">
                            <div>
                                <div class="flex items-center gap-2">
                                    <span class="px-2.5 py-0.5 bg-cyan-500/30 text-cyan-200 text-[10px] font-black rounded uppercase tracking-wider">URA / MENU INTERATIVO</span>
                                    <h5 class="text-base font-black text-white flex items-center gap-2">
                                        <i class="fa-solid fa-sitemap text-cyan-400"></i> <?php echo htmlspecialchars($node['title']); ?>
                                    </h5>
                                </div>
                                <span class="text-xs text-cyan-200/80">Atendimento Interativo por Teclas PSTN/SIP</span>
                            </div>

                            <div class="flex items-center gap-2 flex-wrap">
                                <span class="px-2.5 py-1 bg-cyan-500/20 text-cyan-300 border border-cyan-500/30 text-[10px] font-bold rounded-xl flex items-center gap-1.5">
                                    <i class="fa-solid fa-clock"></i> Digit. Timeout: <?php echo htmlspecialchars($node['timeout'] ?? '5s'); ?>
                                </span>
                                <span class="px-2.5 py-1 bg-amber-500/20 text-amber-300 border border-amber-500/30 text-[10px] font-bold rounded-xl flex items-center gap-1.5">
                                    <i class="fa-solid fa-rotate-left"></i> Re-tentativas: <?php echo htmlspecialchars($node['invalid_loops'] ?? '3x'); ?>
                                </span>
                            </div>
                        </div>

                        <!-- ÁUDIO DE SAUDAÇÃO DA URA SE HOUVER -->
                        <?php if (!empty($node['audio_file'])): ?>
                            <div class="p-3 bg-slate-950/90 rounded-2xl border border-cyan-500/30 space-y-2">
                                <div class="flex items-center justify-between text-xs font-bold text-cyan-300">
                                    <span class="flex items-center gap-2">
                                        <i class="fa-solid fa-file-audio text-cyan-400"></i> Áudio de Saudação URA: <code class="bg-slate-900 px-2 py-0.5 rounded text-cyan-300 text-[10px] font-mono"><?php echo htmlspecialchars($node['audio_file']); ?></code>
                                    </span>
                                    <button onclick="speakAnnouncementText('Mensagem da URA: <?php echo addslashes(htmlspecialchars($node['title'])); ?>.')" 
                                            class="px-2.5 py-1 bg-cyan-500/20 hover:bg-cyan-500/40 text-cyan-200 border border-cyan-500/30 rounded-lg text-[10px] font-bold transition flex items-center gap-1">
                                        <i class="fa-solid fa-bullhorn"></i> <span>Voz Sintetizada</span>
                                    </button>
                                </div>
                                <div class="flex flex-col sm:flex-row items-center justify-between gap-3 pt-1">
                                    <audio controls controlsList="nodownload" class="h-8 max-w-full sm:w-72 text-xs opacity-95">
                                        <source src="get_audio.php?file=<?php echo urlencode($node['audio_file']); ?>" type="audio/wav">
                                        Navegador sem suporte a áudio.
                                    </audio>
                                    <span class="text-[10px] text-slate-400 font-mono">Tocada ao entrar no menu</span>
                                </div>
                            </div>
                        <?php endif; ?>

                        <!-- PARÂMETROS E CONFIGURAÇÕES DA URA -->
                        <div class="grid grid-cols-1 sm:grid-cols-3 gap-3 text-xs">
                            <div class="p-3 bg-slate-950/80 rounded-2xl border border-slate-800 space-y-1">
                                <div class="text-[10px] font-black text-cyan-400 uppercase">⏱️ Tempo limite de digitação</div>
                                <div class="text-xs font-bold text-white"><?php echo htmlspecialchars($node['timeout'] ?? '5 segundos'); ?></div>
                            </div>
                            <div class="p-3 bg-slate-950/80 rounded-2xl border border-slate-800 space-y-1">
                                <div class="text-[10px] font-black text-amber-400 uppercase">🔢 Limite de opção inválida</div>
                                <div class="text-xs font-bold text-white"><?php echo htmlspecialchars($node['invalid_loops'] ?? '3 tentativas'); ?></div>
                            </div>
                            <div class="p-3 bg-slate-950/80 rounded-2xl border border-slate-800 space-y-1">
                                <div class="text-[10px] font-black text-emerald-400 uppercase">☎️ Discagem Direta Ramal</div>
                                <div class="text-xs font-bold text-white"><?php echo htmlspecialchars($node['directdial'] ?? 'Desativada'); ?></div>
                            </div>
                        </div>

                        <!-- OPÇÕES DE TECLAS DIGITADAS -->
                        <?php if (!empty($node['entries'])): ?>
                            <div class="space-y-4 pt-2">
                                <div class="text-xs font-extrabold text-cyan-200 flex items-center gap-2 border-b border-cyan-500/20 pb-1">
                                    <i class="fa-solid fa-keyboard text-cyan-400"></i> Opções de Menu Digitadas (Teclas):
                                </div>
                                <?php foreach ($node['entries'] as $optKey => $optNode): ?>
                                    <div class="bg-slate-950/80 p-4 rounded-2xl border border-cyan-500/20 space-y-3">
                                        <div class="text-xs font-extrabold text-cyan-300 flex items-center gap-2 border-b border-slate-800 pb-2">
                                            <span class="px-2.5 py-1 bg-cyan-500/30 text-white rounded-lg font-mono text-xs shadow">Tecla <?php echo htmlspecialchars($optKey); ?></span>
                                            <span>Destino para a Tecla <?php echo htmlspecialchars($optKey); ?>:</span>
                                        </div>
                                        <?php renderDynamicFlowNode($optNode, $routeId, $nodePath . '-opt-' . $optKey); ?>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>

                        <!-- TRANSBORDO / FAILOVER DE OPÇÃO INVÁLIDA -->
                        <?php if (!empty($node['invalid_node'])): ?>
                            <div class="pt-3 border-t border-cyan-500/20 space-y-2">
                                <div class="text-xs font-bold text-rose-300 flex items-center gap-2">
                                    <i class="fa-solid fa-triangle-exclamation text-rose-400"></i> Se exceder limite de Opção Inválida:
                                </div>
                                <?php renderDynamicFlowNode($node['invalid_node'], $routeId, $nodePath . '-invalid'); ?>
                            </div>
                        <?php endif; ?>

                        <!-- TRANSBORDO / FAILOVER DE TIMEOUT -->
                        <?php if (!empty($node['timeout_node'])): ?>
                            <div class="pt-3 border-t border-cyan-500/20 space-y-2">
                                <div class="text-xs font-bold text-amber-300 flex items-center gap-2">
                                    <i class="fa-solid fa-hourglass-end text-amber-400"></i> Se exceder Tempo Sem Digitação (Timeout):
                                </div>
                                <?php renderDynamicFlowNode($node['timeout_node'], $routeId, $nodePath . '-timeout'); ?>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
                <?php
                break;

            case 'extension':
                ?>
                <!-- NÓ RAMAL DIRETO -->
                <div id="node-<?php echo $routeId; ?>-<?php echo $nodePath; ?>" class="flow-tree-node">
                    <div class="bg-gradient-to-r from-emerald-950/80 to-slate-900 border border-emerald-500/40 rounded-2xl p-5 space-y-2 shadow-xl">
                        <div class="flex items-center justify-between">
                            <span class="text-xs font-black text-emerald-300 flex items-center gap-2">
                                <i class="fa-solid fa-phone text-emerald-400"></i> <?php echo htmlspecialchars($node['title']); ?>
                            </span>
                            <span class="px-2 py-0.5 bg-emerald-500/20 text-emerald-200 text-[9px] font-bold rounded">Ramal Direto</span>
                        </div>
                        <p class="text-xs text-slate-300"><?php echo htmlspecialchars($node['desc'] ?? ''); ?></p>
                    </div>
                </div>
                <?php
                break;

            case 'voicemail':
                ?>
                <!-- NÓ CAIXA POSTAL (VOICEMAIL) -->
                <div id="node-<?php echo $routeId; ?>-<?php echo $nodePath; ?>" class="flow-tree-node">
                    <div class="bg-gradient-to-r from-amber-950/80 to-slate-900 border border-amber-500/40 rounded-2xl p-5 space-y-2 shadow-xl">
                        <div class="flex items-center justify-between">
                            <span class="text-xs font-black text-amber-300 flex items-center gap-2">
                                <i class="fa-solid fa-voicemail text-amber-400"></i> <?php echo htmlspecialchars($node['title']); ?>
                            </span>
                            <span class="px-2 py-0.5 bg-amber-500/20 text-amber-200 text-[9px] font-bold rounded">Caixa Postal</span>
                        </div>
                        <p class="text-xs text-slate-300"><?php echo htmlspecialchars($node['desc'] ?? ''); ?></p>
                    </div>
                </div>
                <?php
                break;

            case 'hangup':
                ?>
                <!-- NÓ ENCERRAMENTO -->
                <div id="node-<?php echo $routeId; ?>-<?php echo $nodePath; ?>" class="flow-tree-node">
                    <div class="bg-gradient-to-r from-rose-950/80 to-slate-900 border border-rose-500/40 rounded-2xl p-5 space-y-2 shadow-xl">
                        <div class="flex items-center justify-between border-b border-rose-500/30 pb-2">
                            <span class="text-xs font-black text-rose-300 uppercase tracking-wider flex items-center gap-2">
                                <i class="fa-solid fa-phone-slash text-rose-400"></i> Destino Final: Encerrar Chamada
                            </span>
                            <span class="px-2.5 py-1 bg-rose-500/20 text-rose-200 text-[9px] font-mono rounded">Desconectar</span>
                        </div>
                        <p class="text-xs text-slate-300">Conexão finalizada diretamente pelo PABX Asterisk.</p>
                    </div>
                </div>
                <?php
                break;

            default:
                ?>
                <div id="node-<?php echo $routeId; ?>-<?php echo $nodePath; ?>" class="flow-tree-node">
                    <div class="bg-slate-900/90 border border-indigo-500/40 rounded-2xl p-5 space-y-2 shadow-xl">
                        <div class="text-xs font-bold text-white">
                            <?php echo htmlspecialchars($node['title'] ?? 'Destino'); ?>
                        </div>
                        <?php if (!empty($node['desc'])): ?>
                            <div class="text-xs text-slate-300"><?php echo htmlspecialchars($node['desc']); ?></div>
                        <?php endif; ?>
                    </div>
                </div>
                <?php
                break;
        }
    }
}
?>

<style>
    .flow-tree-node {
        transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
    }
    
    .node-pill-entry {
        background: linear-gradient(135deg, rgba(6, 182, 212, 0.25) 0%, rgba(14, 116, 144, 0.45) 100%);
        border: 1px solid rgba(6, 182, 212, 0.5);
        box-shadow: 0 8px 32px 0 rgba(6, 182, 212, 0.2);
    }
    
    .node-diamond-decision {
        background: linear-gradient(135deg, rgba(217, 119, 6, 0.2) 0%, rgba(180, 83, 9, 0.35) 100%);
        border: 1.5px solid rgba(245, 158, 11, 0.5);
        box-shadow: 0 10px 40px 0 rgba(245, 158, 11, 0.15);
    }

    .node-card-queue {
        background: linear-gradient(135deg, rgba(88, 28, 135, 0.35) 0%, rgba(15, 23, 42, 0.95) 100%);
    }

    .node-card-night {
        background: linear-gradient(135deg, rgba(120, 53, 15, 0.35) 0%, rgba(15, 23, 42, 0.95) 100%);
        border: 1px solid rgba(245, 158, 11, 0.4);
    }

    .tree-connector-v {
        width: 2px;
        background: linear-gradient(180deg, rgba(6, 182, 212, 0.6) 0%, rgba(245, 158, 11, 0.6) 100%);
    }
</style>

<div class="space-y-8 pb-12">
    <!-- TITULO E FILTROS -->
    <div class="flex flex-col lg:flex-row lg:items-center justify-between gap-4 bg-slate-900/80 p-6 rounded-3xl border border-slate-800 shadow-2xl backdrop-blur-xl">
        <div class="space-y-1">
            <div class="flex items-center gap-3">
                <div class="p-3 bg-cyan-500/10 rounded-2xl border border-cyan-500/20 text-cyan-400">
                    <i class="fa-solid fa-diagram-project text-xl"></i>
                </div>
                <div>
                    <h3 class="text-xl font-black text-white tracking-wide">Fluxograma Central de Ligações PABX</h3>
                    <p class="text-xs text-slate-300">Visualização multinível encadeada ponto-a-ponto a partir do destino de entrada real no PABX</p>
                </div>
            </div>
        </div>

        <div class="flex items-center gap-3 flex-wrap">
            <button onclick="openFeriadosModal()" class="px-4 py-2.5 bg-gradient-to-r from-amber-600 to-amber-700 hover:from-amber-500 hover:to-amber-600 text-white font-extrabold rounded-2xl text-xs flex items-center gap-2 shadow-lg shadow-amber-600/30 transition border border-amber-500/40">
                <i class="fa-solid fa-calendar-plus text-amber-200"></i> Gerenciar Feriados & Horários Especiais
            </button>

            <div class="relative min-w-[200px]">
                <select id="flow-did-filter" onchange="filterFlowchartRoutes()" class="w-full bg-slate-950 border border-slate-800 rounded-2xl px-4 py-2.5 text-xs text-white focus:outline-none focus:border-cyan-500 appearance-none font-bold">
                    <option value="ALL">🔍 Todas as Rotas (<?php echo count($flows); ?>)</option>
                    <?php foreach ($flows as $f): ?>
                        <option value="<?php echo htmlspecialchars($f['did']); ?>">
                            DID: <?php echo htmlspecialchars($f['did']); ?> (<?php echo htmlspecialchars($f['description']); ?>)
                        </option>
                    <?php endforeach; ?>
                </select>
                <i class="fa-solid fa-chevron-down absolute right-4 top-3.5 text-xs text-slate-400 pointer-events-none"></i>
            </div>

            <button onclick="expandAllTreeNodes()" class="px-4 py-2.5 bg-slate-800 hover:bg-slate-700 text-slate-200 rounded-2xl text-xs font-bold transition border border-slate-700 flex items-center gap-2">
                <i class="fa-solid fa-folder-open text-cyan-400"></i> Expandir
            </button>
            <button onclick="collapseAllTreeNodes()" class="px-4 py-2.5 bg-slate-800 hover:bg-slate-700 text-slate-200 rounded-2xl text-xs font-bold transition border border-slate-700 flex items-center gap-2">
                <i class="fa-solid fa-folder text-slate-400"></i> Recolher
            </button>
        </div>
    </div>

    <!-- LISTAGEM DAS ROTAS EM LAYOUT DE FLUXOGRAMA DE ÁRVORE -->
    <div class="space-y-10" id="flowchart-container">
        <?php foreach ($flows as $index => $f): 
            $routeId = 'route-' . $index;
        ?>
            <div class="flow-route-card bg-slate-900/60 rounded-3xl border border-slate-800/80 p-6 space-y-6 shadow-2xl backdrop-blur-md" data-did="<?php echo htmlspecialchars($f['did']); ?>">
                
                <!-- HEADER DA ROTA DE ENTRADA -->
                <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-4 border-b border-slate-800 pb-4">
                    <div class="flex items-center gap-3">
                        <span class="px-3 py-1 bg-cyan-500/10 text-cyan-400 border border-cyan-500/20 text-xs font-black rounded-xl uppercase">ROTA #<?php echo ($index + 1); ?></span>
                        <div>
                            <h4 class="text-lg font-black text-white flex items-center gap-2">
                                <span><?php echo htmlspecialchars($f['description']); ?></span>
                                <span class="text-xs text-slate-300 font-mono font-normal">(DID: <?php echo htmlspecialchars($f['did']); ?>)</span>
                            </h4>
                            <span class="text-xs text-slate-300">Destino Configurado: <code class="bg-slate-950 px-1.5 py-0.5 rounded text-cyan-300 font-mono text-[11px]"><?php echo htmlspecialchars($f['destination']); ?></code></span>
                        </div>
                    </div>

                    <div class="flex items-center gap-2">
                        <span class="px-3 py-1 bg-emerald-500/10 text-emerald-400 border border-emerald-500/20 text-xs font-bold rounded-xl flex items-center gap-1.5">
                            <i class="fa-solid fa-shield-halved"></i> Rota Ativa
                        </span>
                    </div>
                </div>

                <!-- CANVAS DE FLUXOGRAMA CENTRALIZADO -->
                <div class="p-8 bg-slate-950/95 rounded-3xl border border-slate-800/80 space-y-8 overflow-x-auto custom-scrollbar min-w-[850px]">
                    
                    <!-- 1. NÓ TOPO CENTRAL: ENTRADA DA CHAMADA -->
                    <div class="flex flex-col items-center">
                        <div class="node-pill-entry px-8 py-3.5 rounded-full text-center space-y-1 min-w-[260px] cursor-pointer pulse-btn" onclick="toggleTreeBranch('tree-dec1-<?php echo $routeId; ?>', this)">
                            <span class="text-[10px] font-black text-cyan-300 uppercase tracking-widest block">📞 ENTRADA DA CHAMADA</span>
                            <h5 class="text-sm font-black text-white">DID: <?php echo htmlspecialchars($f['did']); ?></h5>
                            <span class="text-[10px] text-cyan-200 block font-mono">Tronco SIP Entrante</span>
                            <div class="text-[9px] text-cyan-300 pt-0.5 flex items-center justify-center gap-1 font-bold">
                                <span>[ + ] Clique para Iniciar o Fluxo</span>
                            </div>
                        </div>

                        <!-- LINHA VERTICAL PARA O DESTINO -->
                        <div class="tree-connector-v h-8"></div>
                        <i class="fa-solid fa-chevron-down text-amber-400 text-xs"></i>
                    </div>

                    <!-- 2. NÓ RECURSIVO 100% DINÂMICO BASEADO NO SET DESTINATION -->
                    <div id="tree-dec1-<?php echo $routeId; ?>" class="flow-tree-node hidden space-y-8">
                        <?php if (!empty($f['tree'])): ?>
                            <?php renderDynamicFlowNode($f['tree'], $routeId, 'root'); ?>
                        <?php else: ?>
                            <div class="text-xs text-slate-300 italic p-4 bg-slate-900 rounded-xl text-center">
                                Sem destino configurado para esta rota.
                            </div>
                        <?php endif; ?>
                    </div>

                </div>
            </div>
        <?php endforeach; ?>
    </div>
</div>

<script>
    // Função para alternar e expandir os nós da árvore
    function toggleTreeBranch(nodeId, btnEl) {
        const node = document.getElementById(nodeId);
        if (!node) return;

        const isHidden = node.classList.contains('hidden');
        if (isHidden) {
            node.classList.remove('hidden');
            node.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
        } else {
            node.classList.add('hidden');
        }
    }

    // Expandir toda a árvore central
    function expandAllTreeNodes() {
        document.querySelectorAll('.flow-tree-node').forEach(n => n.classList.remove('hidden'));
    }

    // Recolher toda a árvore de volta ao nó topo
    function collapseAllTreeNodes() {
        document.querySelectorAll('.flow-tree-node').forEach(n => n.classList.add('hidden'));
    }

    // Filtrar por Rota (DID)
    function filterFlowchartRoutes() {
        const val = document.getElementById('flow-did-filter')?.value || 'ALL';
        document.querySelectorAll('.flow-route-card').forEach(card => {
            const did = card.getAttribute('data-did');
            if (val === 'ALL' || did === val) {
                card.style.display = '';
            } else {
                card.style.display = 'none';
            }
        });
    }

    // Reprodução de Áudio de Anúncios do Fluxo via Síntese de Voz (pt-BR)
    function speakAnnouncementText(text) {
        if (!('speechSynthesis' in window)) {
            alert('Seu navegador não suporta reprodução de voz sintetizada.');
            return;
        }
        window.speechSynthesis.cancel();
        const utterance = new SpeechSynthesisUtterance(text);
        utterance.lang = 'pt-BR';
        utterance.rate = 0.95;
        utterance.pitch = 1.0;

        const voices = window.speechSynthesis.getVoices();
        const ptVoice = voices.find(v => v.lang && (v.lang.includes('pt-BR') || v.lang.includes('pt_BR')));
        if (ptVoice) utterance.voice = ptVoice;

        window.speechSynthesis.speak(utterance);
    }

    // Modal de Gerenciamento de Feriados & Horários PABX
    let cachedTimeGroups = [];
    let cachedRecordings = [];

    function openFeriadosModal() {
        document.getElementById('modal-feriados').classList.remove('hidden');
        loadTimeGroupsPABX();
    }

    function closeFeriadosModal() {
        document.getElementById('modal-feriados').classList.add('hidden');
    }

    function switchModalTab(tabName) {
        const btnTg = document.getElementById('tab-btn-timegroups');
        const btnAnn = document.getElementById('tab-btn-announcements');
        const contentTg = document.getElementById('tab-content-timegroups');
        const contentAnn = document.getElementById('tab-content-announcements');

        if (tabName === 'timegroups') {
            btnTg.className = "px-4 py-2 bg-amber-500/20 text-amber-300 border border-amber-500/40 rounded-xl text-xs font-black flex items-center gap-2 transition";
            btnAnn.className = "px-4 py-2 bg-slate-800 text-slate-400 border border-slate-700 rounded-xl text-xs font-bold flex items-center gap-2 hover:bg-slate-700 hover:text-white transition";
            contentTg.classList.remove('hidden');
            contentAnn.classList.add('hidden');
        } else {
            btnAnn.className = "px-4 py-2 bg-cyan-500/20 text-cyan-300 border border-cyan-500/40 rounded-xl text-xs font-black flex items-center gap-2 transition";
            btnTg.className = "px-4 py-2 bg-slate-800 text-slate-400 border border-slate-700 rounded-xl text-xs font-bold flex items-center gap-2 hover:bg-slate-700 hover:text-white transition";
            contentAnn.classList.remove('hidden');
            contentTg.classList.add('hidden');
            loadAnnouncementsPABX();
        }
    }

    async function loadTimeGroupsPABX() {
        const select = document.getElementById('tg-select');
        select.innerHTML = '<option value="">Carregando grupos de horários do PABX...</option>';

        try {
            const resp = await fetch('index.php?action=get_time_groups');
            const data = await resp.json();

            if (data.success && data.timegroups) {
                cachedTimeGroups = data.timegroups;
                let html = '';
                data.timegroups.forEach(g => {
                    html += `<option value="${g.id}">#${g.id}: ${escapeHtml(g.name)} (${g.rules_count} regra${g.rules_count === 1 ? '' : 's'})</option>`;
                });
                html += `<option value="NEW">➕ [ Criar Novo Grupo de Horários ]</option>`;
                select.innerHTML = html;

                if (data.timegroups.length > 0) {
                    select.value = data.timegroups[0].id;
                    onTimeGroupSelectChange();
                }
            }
        } catch (err) {
            select.innerHTML = '<option value="">Erro ao carregar grupos do PABX</option>';
        }
    }

    function onTimeGroupSelectChange() {
        const select = document.getElementById('tg-select');
        const val = select.value;
        const newBox = document.getElementById('new-tg-input-box');

        if (val === 'NEW') {
            newBox.classList.remove('hidden');
            loadRulesForTimeGroup('');
        } else {
            newBox.classList.add('hidden');
            loadRulesForTimeGroup(val);
        }
    }

    async function loadRulesForTimeGroup(tgId) {
        const tbody = document.getElementById('tg-rules-table-body');
        const countBadge = document.getElementById('tg-rules-count-badge');
        
        if (!tgId) {
            tbody.innerHTML = '<tr><td colspan="3" class="text-center py-6 text-slate-400 text-xs italic">Selecione ou crie um grupo de horários acima.</td></tr>';
            countBadge.innerText = '0 regras';
            return;
        }

        tbody.innerHTML = '<tr><td colspan="3" class="text-center py-4 text-slate-400 text-xs"><i class="fa-solid fa-spinner fa-spin mr-2"></i> Consultando regras reais no PABX...</td></tr>';

        try {
            const resp = await fetch('index.php?action=get_time_group_rules&tg_id=' + encodeURIComponent(tgId));
            const data = await resp.json();

            if (!data.success || !data.rules || data.rules.length === 0) {
                tbody.innerHTML = '<tr><td colspan="3" class="text-center py-6 text-slate-400 text-xs italic">Nenhuma regra cadastrada neste grupo. Adicione uma nova regra abaixo.</td></tr>';
                countBadge.innerText = '0 regras';
                return;
            }

            countBadge.innerText = `${data.rules.length} regra${data.rules.length === 1 ? '' : 's'}`;
            let html = '';
            data.rules.forEach(r => {
                html += `
                    <tr class="border-b border-slate-800/80 hover:bg-slate-900/50 text-xs text-slate-200">
                        <td class="py-3 px-4 font-bold text-amber-300 flex items-center gap-2">
                            <i class="fa-solid fa-clock-check text-amber-400"></i> ${escapeHtml(r.parsed_label)}
                        </td>
                        <td class="py-3 px-4 font-mono text-[11px] text-cyan-300">${escapeHtml(r.rule_raw)}</td>
                        <td class="py-3 px-4 text-right">
                            <button type="button" onclick="deleteTimeGroupRuleAction('${r.id}')" class="px-2.5 py-1 bg-rose-500/20 hover:bg-rose-500/40 text-rose-300 border border-rose-500/30 rounded-lg text-[10px] font-bold transition flex items-center gap-1 ml-auto">
                                <i class="fa-solid fa-trash"></i> Remover
                            </button>
                        </td>
                    </tr>
                `;
            });
            tbody.innerHTML = html;
        } catch (err) {
            tbody.innerHTML = '<tr><td colspan="3" class="text-center py-4 text-rose-400 text-xs">Erro ao consultar regras do grupo.</td></tr>';
        }
    }

    function onTimePresetChange() {
        const preset = document.getElementById('rule-time-preset').value;
        const customBox = document.getElementById('custom-time-range-box');
        if (preset === 'CUSTOM') {
            customBox.classList.remove('hidden');
        } else {
            customBox.classList.add('hidden');
        }
    }

    async function submitTimeGroupRule(e) {
        e.preventDefault();
        const select = document.getElementById('tg-select');
        const tgId = select.value;
        const newName = document.getElementById('new-tg-name').value.trim();

        if (tgId === 'NEW' && !newName) {
            alert('Por favor, digite o nome do novo Grupo de Horários.');
            return;
        }

        const preset = document.getElementById('rule-time-preset').value;
        let tStart = '00:00', tEnd = '23:59';

        if (preset === 'CUSTOM') {
            tStart = document.getElementById('rule-time-start').value || '08:00';
            tEnd = document.getElementById('rule-time-end').value || '09:00';
        } else if (preset) {
            const parts = preset.split('-');
            tStart = parts[0] || '00:00';
            tEnd = parts[1] || '23:59';
        }

        const wDays = document.getElementById('rule-wdays').value;
        const mDays = document.getElementById('rule-mdays').value;
        const month = document.getElementById('rule-month').value;

        const formData = new FormData();
        formData.append('tg_id', tgId);
        formData.append('tg_name', newName);
        formData.append('time_start', tStart);
        formData.append('time_end', tEnd);
        formData.append('w_days', wDays);
        formData.append('m_days', mDays);
        formData.append('month', month);

        try {
            const resp = await fetch('index.php?action=save_time_group_rule', {
                method: 'POST',
                body: formData
            });
            const data = await resp.json();

            if (data.success) {
                alert(data.message);
                if (tgId === 'NEW' && data.timegroupid) {
                    await loadTimeGroupsPABX();
                    document.getElementById('tg-select').value = data.timegroupid;
                    onTimeGroupSelectChange();
                } else {
                    loadRulesForTimeGroup(tgId);
                }
            } else {
                alert('Erro ao salvar regra: ' + data.message);
            }
        } catch (err) {
            alert('Erro de conexão ao salvar regra no PABX.');
        }
    }

    async function deleteTimeGroupRuleAction(ruleId) {
        if (!confirm('Deseja realmente remover esta regra do PABX?')) return;

        const formData = new FormData();
        formData.append('id', ruleId);

        try {
            const resp = await fetch('index.php?action=delete_time_group_rule', {
                method: 'POST',
                body: formData
            });
            const data = await resp.json();

            if (data.success) {
                alert(data.message);
                const tgId = document.getElementById('tg-select').value;
                loadRulesForTimeGroup(tgId);
            } else {
                alert('Erro ao remover: ' + data.message);
            }
        } catch (err) {
            alert('Erro de conexão ao remover regra.');
        }
    }

    async function loadAnnouncementsPABX() {
        const container = document.getElementById('announcements-container');
        container.innerHTML = '<div class="text-center py-6 text-slate-400 text-xs"><i class="fa-solid fa-spinner fa-spin mr-2"></i> Carregando anúncios de áudio do PABX...</div>';

        try {
            const resp = await fetch('index.php?action=get_announcements_list');
            const data = await resp.json();

            if (!data.success || !data.announcements || data.announcements.length === 0) {
                container.innerHTML = '<div class="text-center py-6 text-slate-400 text-xs italic">Nenhum anúncio de áudio cadastrado no PABX.</div>';
                return;
            }

            cachedRecordings = data.recordings || [];

            let html = '';
            data.announcements.forEach(ann => {
                let recOptions = '<option value="">-- Selecionar Novo Áudio --</option>';
                cachedRecordings.forEach(rec => {
                    const selected = (String(rec.id) === String(ann.recording_id)) ? 'selected' : '';
                    recOptions += `<option value="${rec.id}" ${selected}>${escapeHtml(rec.name)} (${rec.filename})</option>`;
                });

                html += `
                    <div class="bg-slate-900 border border-slate-800 p-4 rounded-2xl space-y-3">
                        <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-2 border-b border-slate-800 pb-2">
                            <span class="text-xs font-black text-cyan-300 flex items-center gap-2">
                                <i class="fa-solid fa-bullhorn text-cyan-400"></i> ${escapeHtml(ann.name)} (ID #${ann.id})
                            </span>
                            <span class="text-[10px] text-slate-400 font-mono">Arquivo atual: ${escapeHtml(ann.audio_file || 'Sem áudio')}</span>
                        </div>

                        <div class="flex flex-col sm:flex-row items-center gap-3">
                            <select id="ann-rec-select-${ann.id}" class="w-full bg-slate-950 border border-slate-700 rounded-xl px-3 py-2 text-xs text-white focus:outline-none focus:border-cyan-500 font-bold">
                                ${recOptions}
                            </select>
                            <button type="button" onclick="saveAnnouncementAudioAction('${ann.id}')" class="w-full sm:w-auto px-4 py-2 bg-cyan-600 hover:bg-cyan-500 text-white rounded-xl text-xs font-bold transition flex items-center justify-center gap-1.5 whitespace-nowrap shadow">
                                <i class="fa-solid fa-floppy-disk"></i> Substituir Áudio
                            </button>
                        </div>
                    </div>
                `;
            });

            container.innerHTML = html;
        } catch (err) {
            container.innerHTML = '<div class="text-center py-6 text-rose-400 text-xs">Erro ao carregar anúncios do PABX.</div>';
        }
    }

    async function saveAnnouncementAudioAction(annId) {
        const select = document.getElementById(`ann-rec-select-${annId}`);
        const recId = select ? select.value : '';

        if (!recId) {
            alert('Selecione uma gravação de áudio válida.');
            return;
        }

        const formData = new FormData();
        formData.append('ann_id', annId);
        formData.append('rec_id', recId);

        try {
            const resp = await fetch('index.php?action=update_announcement_audio', {
                method: 'POST',
                body: formData
            });
            const data = await resp.json();

            if (data.success) {
                alert(data.message);
                loadAnnouncementsPABX();
            } else {
                alert('Erro ao atualizar áudio: ' + data.message);
            }
        } catch (err) {
            alert('Erro de conexão ao salvar mensagem de áudio.');
        }
    }

    function escapeHtml(str) {
        return String(str || '').replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');
    }
</script>

<!-- MODAL GERENCIADOR DE GRUPOS DE HORÁRIO, FERIADOS E MENSAGENS PABX -->
<div id="modal-feriados" class="fixed inset-0 z-50 bg-slate-950/80 backdrop-blur-md flex items-center justify-center p-4 hidden">
    <div class="bg-slate-900 border border-slate-800 rounded-3xl max-w-4xl w-full p-6 space-y-6 shadow-2xl max-h-[92vh] overflow-y-auto custom-scrollbar">
        
        <!-- CABEÇALHO MODAL -->
        <div class="flex items-center justify-between border-b border-slate-800 pb-4">
            <div class="flex items-center gap-3">
                <div class="p-3 bg-amber-500/10 rounded-2xl border border-amber-500/20 text-amber-400">
                    <i class="fa-solid fa-clock-rotate-left text-xl"></i>
                </div>
                <div>
                    <h4 class="text-lg font-black text-white">Gerenciar Condições de Horário, Feriados & Áudio PABX</h4>
                    <p class="text-xs text-slate-300">Sincronização 100% direta com MySQL Issabel/Asterisk e recarga instantânea de dialplan</p>
                </div>
            </div>
            <button onclick="closeFeriadosModal()" class="p-2 text-slate-400 hover:text-white rounded-xl hover:bg-slate-800 transition">
                <i class="fa-solid fa-xmark text-lg"></i>
            </button>
        </div>

        <!-- ABA SELETORA -->
        <div class="flex items-center gap-3 border-b border-slate-800 pb-3">
            <button id="tab-btn-timegroups" onclick="switchModalTab('timegroups')" class="px-4 py-2 bg-amber-500/20 text-amber-300 border border-amber-500/40 rounded-xl text-xs font-black flex items-center gap-2 transition">
                <i class="fa-solid fa-calendar-days"></i> ⏰ Grupos de Horários & Feriados
            </button>
            <button id="tab-btn-announcements" onclick="switchModalTab('announcements')" class="px-4 py-2 bg-slate-800 text-slate-400 border border-slate-700 rounded-xl text-xs font-bold flex items-center gap-2 hover:bg-slate-700 hover:text-white transition">
                <i class="fa-solid fa-volume-high"></i> 🔊 Mensagens de Áudio / Anúncios
            </button>
        </div>

        <!-- CONTEÚDO TAB 1: GRUPOS DE HORÁRIOS & FERIADOS -->
        <div id="tab-content-timegroups" class="space-y-6">
            
            <!-- SELETOR DE GRUPO DE HORÁRIOS DO PABX -->
            <div class="bg-slate-950/90 p-4 rounded-2xl border border-amber-500/30 space-y-3">
                <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-3">
                    <label class="text-xs font-black text-amber-300 uppercase tracking-wide flex items-center gap-2">
                        <i class="fa-solid fa-layer-group text-amber-400"></i> Selecionar Grupo de Horários do PABX:
                    </label>
                    <span class="text-[10px] text-slate-400 font-mono">Feriados, Horário Comercial, Recessos, etc.</span>
                </div>

                <div class="flex flex-col sm:flex-row items-center gap-3">
                    <select id="tg-select" onchange="onTimeGroupSelectChange()" class="w-full bg-slate-900 border border-slate-700 rounded-xl px-4 py-2.5 text-xs text-white focus:outline-none focus:border-amber-500 font-extrabold">
                        <option value="">Carregando grupos de horários...</option>
                    </select>
                </div>

                <!-- Campo para criar novo grupo se selecionar NEW -->
                <div id="new-tg-input-box" class="hidden pt-2 space-y-1">
                    <label class="text-[11px] font-bold text-amber-300">Nome do Novo Grupo de Horários:</label>
                    <input type="text" id="new-tg-name" placeholder="Ex: Recesso de Fim de Ano, Plantão Noturno..." class="w-full bg-slate-900 border border-amber-500/50 rounded-xl px-3 py-2 text-xs text-white focus:outline-none">
                </div>
            </div>

            <!-- TABELA DE REGRAS DO GRUPO SELECIONADO -->
            <div class="space-y-3">
                <div class="text-xs font-black text-slate-300 uppercase tracking-wide flex items-center justify-between">
                    <span class="flex items-center gap-2"><i class="fa-solid fa-list-check text-amber-400"></i> Regras Cadastradas neste Grupo no Issabel:</span>
                    <span id="tg-rules-count-badge" class="text-[10px] bg-amber-500/20 text-amber-300 border border-amber-500/30 px-2 py-0.5 rounded font-mono">0 regras</span>
                </div>

                <div class="overflow-x-auto rounded-2xl border border-slate-800">
                    <table class="w-full text-left border-collapse">
                        <thead>
                            <tr class="bg-slate-950 text-[11px] font-black text-slate-400 uppercase tracking-wider border-b border-slate-800">
                                <th class="py-3 px-4">Regra Formatada</th>
                                <th class="py-3 px-4">Sintaxe Raw PABX</th>
                                <th class="py-3 px-4 text-right">Ação</th>
                            </tr>
                        </thead>
                        <tbody id="tg-rules-table-body">
                            <!-- Preenchido dinamicamente via JS -->
                        </tbody>
                    </table>
                </div>
            </div>

            <!-- FORMULÁRIO DE ADIÇÃO DE NOVA REGRA AO GRUPO SELECIONADO -->
            <form id="form-tg-rule" onsubmit="submitTimeGroupRule(event)" class="bg-slate-950/80 p-5 rounded-2xl border border-slate-800 space-y-4">
                <div class="text-xs font-black text-amber-300 uppercase tracking-wide flex items-center gap-2">
                    <i class="fa-solid fa-plus-circle text-amber-400"></i> Adicionar Nova Regra de Horário ao Grupo:
                </div>

                <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-3">
                    <!-- Pré-definição de Horário ou Personalizado -->
                    <div class="sm:col-span-2 space-y-1">
                        <label class="text-[11px] font-bold text-slate-300">Modo de Horário:</label>
                        <select id="rule-time-preset" onchange="onTimePresetChange()" class="w-full bg-slate-900 border border-slate-700 rounded-xl px-3 py-2 text-xs text-white focus:outline-none focus:border-amber-500 font-bold">
                            <option value="00:00-23:59">Dia Inteiro (00:00 às 23:59)</option>
                            <option value="08:00-12:00">Turno Manhã (08:00 às 12:00)</option>
                            <option value="12:00-18:00">Turno Tarde (12:00 às 18:00)</option>
                            <option value="08:00-18:00">Horário Comercial (08:00 às 18:00)</option>
                            <option value="CUSTOM">⏰ Horário Personalizado (Definir Hora de Início e Fim)</option>
                        </select>
                    </div>

                    <!-- Campos de Hora Personalizada (Início / Fim) -->
                    <div id="custom-time-range-box" class="sm:col-span-2 hidden grid grid-cols-2 gap-2">
                        <div class="space-y-1">
                            <label class="text-[11px] font-bold text-amber-300">Hora Início:</label>
                            <input type="time" id="rule-time-start" value="08:00" class="w-full bg-slate-900 border border-amber-500/50 rounded-xl px-3 py-2 text-xs text-white focus:outline-none font-mono">
                        </div>
                        <div class="space-y-1">
                            <label class="text-[11px] font-bold text-amber-300">Hora Fim:</label>
                            <input type="time" id="rule-time-end" value="09:00" class="w-full bg-slate-900 border border-amber-500/50 rounded-xl px-3 py-2 text-xs text-white focus:outline-none font-mono">
                        </div>
                    </div>
                </div>

                <div class="grid grid-cols-1 sm:grid-cols-3 gap-3 pt-1">
                    <!-- Dias da Semana -->
                    <div class="space-y-1">
                        <label class="text-[11px] font-bold text-slate-300">Dias da Semana:</label>
                        <select id="rule-wdays" class="w-full bg-slate-900 border border-slate-700 rounded-xl px-3 py-2 text-xs text-white focus:outline-none focus:border-amber-500 font-bold">
                            <option value="*">Todos os Dias (*)</option>
                            <option value="mon-fri">Segunda a Sexta (mon-fri)</option>
                            <option value="sat-sun">Sábado e Domingo (sat-sun)</option>
                            <option value="mon">Segunda-feira</option>
                            <option value="tue">Terça-feira</option>
                            <option value="wed">Quarta-feira</option>
                            <option value="thu">Quinta-feira</option>
                            <option value="fri">Sexta-feira</option>
                            <option value="sat">Sábado</option>
                            <option value="sun">Domingo</option>
                        </select>
                    </div>

                    <!-- Dia do Mês -->
                    <div class="space-y-1">
                        <label class="text-[11px] font-bold text-slate-300">Dia do Mês:</label>
                        <select id="rule-mdays" class="w-full bg-slate-900 border border-slate-700 rounded-xl px-3 py-2 text-xs text-white focus:outline-none focus:border-amber-500 font-bold">
                            <option value="*">Todos os Dias do Mês (*)</option>
                            <?php for ($d = 1; $d <= 31; $d++): ?>
                                <option value="<?php echo $d; ?>">Dia <?php echo sprintf('%02d', $d); ?></option>
                            <?php endfor; ?>
                        </select>
                    </div>

                    <!-- Mês -->
                    <div class="space-y-1">
                        <label class="text-[11px] font-bold text-slate-300">Mês:</label>
                        <select id="rule-month" class="w-full bg-slate-900 border border-slate-700 rounded-xl px-3 py-2 text-xs text-white focus:outline-none focus:border-amber-500 font-bold">
                            <option value="*">Todos os Meses (*)</option>
                            <option value="jan">Janeiro</option>
                            <option value="feb">Fevereiro</option>
                            <option value="mar">Março</option>
                            <option value="apr">Abril</option>
                            <option value="may">Maio</option>
                            <option value="jun">Junho</option>
                            <option value="jul">Julho</option>
                            <option value="aug">Agosto</option>
                            <option value="sep">Setembro</option>
                            <option value="oct">Outubro</option>
                            <option value="nov">Novembro</option>
                            <option value="dec">Dezembro</option>
                        </select>
                    </div>
                </div>

                <div class="flex justify-end pt-2">
                    <button type="submit" class="px-6 py-2.5 bg-gradient-to-r from-amber-600 to-amber-700 hover:from-amber-500 hover:to-amber-600 text-white font-black rounded-xl text-xs shadow-lg shadow-amber-600/30 transition flex items-center gap-2">
                        <i class="fa-solid fa-cloud-arrow-up"></i> <span>Salvar & Sincronizar Regra no PABX</span>
                    </button>
                </div>
            </form>
        </div>

        <!-- CONTEÚDO TAB 2: MENSAGENS DE ÁUDIO & ANÚNCIOS -->
        <div id="tab-content-announcements" class="space-y-6 hidden">
            <div class="bg-slate-950/80 p-5 rounded-2xl border border-slate-800 space-y-4">
                <div class="text-xs font-black text-cyan-300 uppercase tracking-wide flex items-center justify-between">
                    <span class="flex items-center gap-2"><i class="fa-solid fa-volume-high text-cyan-400"></i> Anúncios e Mensagens de Áudio Configurados no PABX:</span>
                    <span class="text-[10px] text-slate-400 font-mono">Edição e Substituição de Arquivo `.wav`</span>
                </div>

                <div id="announcements-container" class="space-y-4">
                    <!-- Preenchido via JS -->
                </div>
            </div>
        </div>

    </div>
</div>
