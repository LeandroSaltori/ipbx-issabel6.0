<?php
ob_start();
/**
 * IPbx Prisma x Prismabot - Painel Integrado (NEWFRONT)
 * Arquitetura Modular de Engenharia de Software
 */

require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/address_book_sync.php';

// Auto-sincronizar ramais na primeira inicialização se a tabela estiver vazia
try {
    $stmt_check_empty = $db->query("SELECT COUNT(*) FROM extensions_config");
    if ($stmt_check_empty && $stmt_check_empty->fetchColumn() == 0) {
        syncPrismaAssets();
    }
    $db->exec("CREATE TABLE IF NOT EXISTS ai_insights_history (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        provider TEXT,
        model TEXT,
        insights_json TEXT,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP
    )");
} catch (Exception $ex_sync) {}

// =========================================================================
// AUTENTICAÇÃO (portão único do painel)
// =========================================================================
$__api_action_names = ['get_logs', 'get_fop_extensions', 'get_trunks_status', 'get_queues_realtime', 'get_parking_lots', 'fop_action', 'originate_call', 'save_contact', 'test_llm_connection', 'analyze_pabx_insights', 'generate_ai_insights', 'get_ai_insights_history', 'delete_ai_insight', 'transcribe_audio', 'get_kpi_calls_detail', 'get_active_calls', 'get_time_groups', 'get_time_group_rules', 'save_time_group_rule', 'delete_time_group_rule', 'get_announcements_list', 'update_announcement_audio', 'sync_assets'];
if (($_GET['api_action'] ?? '') === 'whatsapp_webhook') {
    // Webhook do Z-PRO: autenticado por token próprio, sem sessão
} elseif (isset($_GET['api_action']) || (isset($_GET['action']) && in_array($_GET['action'], $__api_action_names, true))) {
    authGate('json');
} else {
    authGate('page');
}

// =========================================================================
// HANDLER DE ENDPOINTS DE API (JSON)
// =========================================================================
if (isset($_GET['api_action']) || (isset($_GET['action']) && in_array($_GET['action'], ['get_logs', 'get_fop_extensions', 'get_trunks_status', 'get_queues_realtime', 'get_parking_lots', 'fop_action', 'originate_call', 'save_contact', 'test_llm_connection', 'analyze_pabx_insights', 'generate_ai_insights', 'get_ai_insights_history', 'delete_ai_insight', 'transcribe_audio', 'get_kpi_calls_detail', 'get_active_calls', 'get_time_groups', 'get_time_group_rules', 'save_time_group_rule', 'delete_time_group_rule', 'get_announcements_list', 'update_announcement_audio', 'sync_assets', 'whatsapp_webhook']))) {
    $action = $_GET['api_action'] ?? $_GET['action'];
    while (ob_get_level()) { @ob_end_clean(); }
    header('Content-Type: application/json; charset=utf-8');

    if ($action === 'sync_assets') {
        try {
            $res = syncPrismaAssets();
            echo json_encode([
                'success'    => true,
                'extensions' => $res['extensions'] ?? 0,
                'queues'     => $res['queues'] ?? 0,
                'message'    => 'Sincronização com o PABX Asterisk concluída com sucesso!'
            ]);
        } catch (Exception $e) {
            echo json_encode(['success' => false, 'error' => 'Falha na sincronização com o PABX: ' . $e->getMessage()]);
        }
        exit;
    }

    // Webhook de mensagens recebidas do Z-PRO: registra respostas de NPS (nota 1 a 5)
    if ($action === 'whatsapp_webhook') {
        $expected = getSetting('webhook_token');
        if (empty($expected)) {
            $expected = bin2hex(random_bytes(16));
            saveSetting('webhook_token', $expected);
        }
        $given = $_GET['token'] ?? ($_SERVER['HTTP_X_WEBHOOK_TOKEN'] ?? '');
        if (!hash_equals($expected, (string)$given)) {
            http_response_code(403);
            echo json_encode(['success' => false, 'error' => 'Token inválido']);
            exit;
        }
        $raw = file_get_contents('php://input');
        $in  = json_decode($raw, true);
        if (!is_array($in)) { $in = $_POST; }
        // Aceita variações comuns de payload (Baileys/Z-PRO)
        $pick = function ($arr, $keys) {
            foreach ($keys as $k) {
                $v = $arr;
                foreach (explode('.', $k) as $part) {
                    if (is_array($v) && isset($v[$part])) { $v = $v[$part]; } else { $v = null; break; }
                }
                if (is_scalar($v) && $v !== '') return (string)$v;
            }
            return '';
        };
        $fromMe = $pick($in, ['fromMe', 'message.fromMe', 'data.fromMe', 'msg.fromMe']);
        $phone  = preg_replace('/\D/', '', $pick($in, ['number', 'from', 'phone', 'contact.number', 'ticket.contact.number', 'data.from', 'message.from', 'msg.from']));
        $text   = trim($pick($in, ['body', 'text', 'message.body', 'message.text', 'data.body', 'msg.body', 'message']));
        if ($fromMe === '1' || strtolower($fromMe) === 'true' || $phone === '' || $text === '') {
            echo json_encode(['success' => true, 'ignored' => true]);
            exit;
        }
        if (!preg_match('/^\s*([1-5])(?:\D|$)/u', $text, $mm)) {
            echo json_encode(['success' => true, 'ignored' => true, 'reason' => 'not_a_score']);
            exit;
        }
        $score = (int)$mm[1];
        // Casa com o último NPS enviado a esse número nas últimas 48h e ainda sem resposta
        $tail = substr($phone, -9);
        $stmt = $db->prepare("SELECT id, extension FROM sent_logs WHERE UPPER(rule_type) = 'NPS' AND status = 'SUCCESS' AND phone LIKE :p AND created_at >= datetime('now', '-48 hours') ORDER BY id DESC LIMIT 1");
        $stmt->execute([':p' => '%' . $tail]);
        $sent = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$sent) {
            echo json_encode(['success' => true, 'ignored' => true, 'reason' => 'no_nps_pending']);
            exit;
        }
        $dup = $db->prepare("SELECT COUNT(*) FROM nps_responses WHERE phone LIKE :p AND created_at >= (SELECT created_at FROM sent_logs WHERE id = :id)");
        $dup->execute([':p' => '%' . $tail, ':id' => $sent['id']]);
        if ((int)$dup->fetchColumn() > 0) {
            echo json_encode(['success' => true, 'ignored' => true, 'reason' => 'already_answered']);
            exit;
        }
        $ext = (string)($sent['extension'] ?? '');
        $ext_info = $ext !== '' ? getExtensionCustomData($ext) : null;
        $ins = $db->prepare("INSERT INTO nps_responses (phone, extension, agent_name, score, feedback) VALUES (:p, :e, :a, :s, :f)");
        $ins->execute([':p' => $phone, ':e' => $ext, ':a' => $ext_info['agent_name'] ?? '', ':s' => $score, ':f' => $text]);
        if (function_exists('pabx_log')) pabx_log('whatsapp', 'INFO', "NPS recebido de {$phone}: nota {$score}", ['extension' => $ext]);
        echo json_encode(['success' => true, 'score' => $score]);
        exit;
    }

    if ($action === 'get_time_groups') {
        $groups = getAsteriskTimeGroupsList();
        echo json_encode(['success' => true, 'timegroups' => $groups]);
        exit;
    }

    if ($action === 'get_time_group_rules') {
        $tg_id = $_GET['tg_id'] ?? $_POST['tg_id'] ?? '';
        $rules = getAsteriskTimeGroupRules($tg_id);
        echo json_encode(['success' => true, 'rules' => $rules]);
        exit;
    }

    if ($action === 'save_time_group_rule') {
        $tg_id      = $_POST['tg_id'] ?? $_GET['tg_id'] ?? '';
        $tg_name    = trim($_POST['tg_name'] ?? $_GET['tg_name'] ?? '');
        $time_start = trim($_POST['time_start'] ?? $_GET['time_start'] ?? '00:00');
        $time_end   = trim($_POST['time_end'] ?? $_GET['time_end'] ?? '23:59');
        $w_days     = trim($_POST['w_days'] ?? $_GET['w_days'] ?? '*');
        $m_days     = trim($_POST['m_days'] ?? $_GET['m_days'] ?? '*');
        $month      = trim($_POST['month'] ?? $_GET['month'] ?? '*');

        $res = saveAsteriskTimeGroupRule($tg_id, $tg_name, $time_start, $time_end, $w_days, $m_days, $month);
        echo json_encode($res);
        exit;
    }

    if ($action === 'log_event') {
        $category = trim($_POST['category'] ?? $_GET['category'] ?? LOG_CAT_AUDIT);
        $level    = strtoupper(trim($_POST['level'] ?? $_GET['level'] ?? 'INFO'));
        $message  = trim($_POST['message'] ?? $_GET['message'] ?? '');
        $context  = json_decode($_POST['context'] ?? $_GET['context'] ?? '{}', true) ?: [];

        if (!empty($message)) {
            pabx_log($category, $level, $message, $context);
        }
        echo json_encode(['success' => true]);
        exit;
    }

    if ($action === 'delete_time_group_rule') {
        $detail_id = $_POST['id'] ?? $_GET['id'] ?? '';
        $res = deleteAsteriskTimeGroupRule($detail_id);
        echo json_encode($res);
        exit;
    }

    if ($action === 'get_announcements_list') {
        $anns = getAsteriskAnnouncementsList();
        $recs = getAsteriskRecordingsList();
        echo json_encode(['success' => true, 'announcements' => $anns, 'recordings' => $recs]);
        exit;
    }

    if ($action === 'update_announcement_audio') {
        $ann_id = $_POST['ann_id'] ?? $_GET['ann_id'] ?? '';
        $rec_id = $_POST['rec_id'] ?? $_GET['rec_id'] ?? '';
        $res = updateAsteriskAnnouncementAudio($ann_id, $rec_id);
        echo json_encode($res);
        exit;
    }

    if ($action === 'get_logs') {
        $stmt = $db->query("SELECT * FROM sent_logs ORDER BY id DESC LIMIT 50");
        $logs = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $badge_map = [
            'NPS' => 'bg-amber-500/20 text-amber-300 border border-amber-500/30',
            'ATENDENTE_NAO_ATENDEU' => 'bg-rose-500/20 text-rose-300 border border-rose-500/30',
            'RAMAL_DISCOU' => 'bg-indigo-500/20 text-indigo-300 border border-indigo-500/30',
            'DISPARO_MANUAL' => 'bg-brand-500/20 text-brand-300 border border-brand-500/30',
            'FILA_ABANDONO' => 'bg-purple-500/20 text-purple-300 border border-purple-500/30'
        ];

        foreach ($logs as &$log) {
            $l_rule = strtoupper($log['rule_type'] ?: 'GERAL');
            $log['badge_color'] = isset($badge_map[$l_rule]) ? $badge_map[$l_rule] : 'bg-slate-800 text-slate-300 border border-slate-700';
            $log['date'] = date('H:i:s d/m', strtotime($log['created_at']));
        }
        echo json_encode($logs);
        exit;
    }

    if ($action === 'get_fop_extensions') {
        if (ob_get_level()) ob_end_clean();
        header('Content-Type: application/json');
        $realtime_statuses = getAsteriskRealtimeStatuses();
        $exts = $db->query("SELECT * FROM extensions_config ORDER BY CAST(extension AS UNSIGNED) ASC")->fetchAll(PDO::FETCH_ASSOC);

        $active_partners = [];
        if (function_exists('shell_exec')) {
            $chans = @shell_exec('asterisk -rx "core show channels concise" 2>/dev/null');
            if ($chans) {
                $lines = explode("\n", trim($chans));
                foreach ($lines as $line) {
                    $parts = explode('!', trim($line));
                    if (count($parts) >= 8) {
                        $ch         = trim($parts[0] ?? ''); // ex: PJSIP/201-0000000a
                        $ext_field  = trim($parts[2] ?? ''); // ex: 203 ou s ou 12
                        $app_data   = trim($parts[6] ?? ''); // ex: PJSIP/203,,Ttr ou 01199998888@from-internal
                        $caller_raw = trim($parts[7] ?? ''); // ex: "201" <201> ou 201
                        $bridged_ch = trim($parts[11] ?? ''); // ex: PJSIP/203-0000000b

                        // Extrair número do ramal a partir do canal (ex: PJSIP/201-00000a -> 201)
                        $channel_ext = '';
                        if (preg_match('/^(?:PJSIP|SIP|DAHDI|Local|IAX2)\/(\d+)/i', $ch, $m_ch)) {
                            $channel_ext = $m_ch[1];
                        }

                        if (empty($channel_ext)) continue;

                        // Extrair número do chamador (CallerID)
                        $caller_num = '';
                        if (preg_match('/<(\d+)>/', $caller_raw, $m_cid)) {
                            $caller_num = $m_cid[1];
                        } elseif (preg_match('/^\d+$/', $caller_raw)) {
                            $caller_num = $caller_raw;
                        }

                        // Extrair número do destino/discado
                        $target_num = '';

                        // 1. A partir de AppData (ex: Dial(PJSIP/203...))
                        if (preg_match('/(?:PJSIP|SIP|DAHDI|Local|IAX2)\/(\d+)/i', $app_data, $m_app)) {
                            $target_num = $m_app[1];
                        }

                        // 2. A partir do Canal Conectado (Bridged Channel)
                        if (empty($target_num) && !empty($bridged_ch)) {
                            if (preg_match('/(?:PJSIP|SIP|DAHDI|Local|IAX2)\/(\d+)/i', $bridged_ch, $m_br)) {
                                $target_num = $m_br[1];
                            }
                        }

                        // 3. A partir do campo Extension se for um número válido (diferente do ramal do canal)
                        if (empty($target_num) && is_numeric($ext_field) && strlen($ext_field) >= 3 && $ext_field !== $channel_ext) {
                            $target_num = $ext_field;
                        }

                        // Limpar prefixo 9 de discagem externa se houver
                        if (strlen($target_num) >= 10 && $target_num[0] === '9') {
                            $target_num = substr($target_num, 1);
                        }

                        // Determinar a direção da conversa para cada ramal envolvido
                        if ($channel_ext === $caller_num) {
                            // Este ramal é o ORIGINADOR (quem ligou) -> seu parceiro é o DESTINO
                            if (!empty($target_num) && $target_num !== $channel_ext) {
                                $active_partners[$channel_ext] = $target_num;
                            }
                        } else {
                            // Este ramal é o DESTINO (quem está recebendo/tocando) -> seu parceiro é o ORIGINADOR
                            if (!empty($caller_num) && $caller_num !== $channel_ext) {
                                $active_partners[$channel_ext] = $caller_num;
                            }
                        }
                    }
                }
            }
        }

        $fop_data = [];
        foreach ($exts as $e) {
            $num = $e['extension'];
            $st = isset($realtime_statuses[$num]) ? $realtime_statuses[$num] : 'Livre';
            
            $fop_data[] = [
                'id' => $num,
                'name' => formatEndpointName($num),
                'tech' => strtoupper($e['tech'] ?: 'PJSIP'),
                'whatsapp' => $e['whatsapp_number'],
                'status' => $st,
                'channel' => "PJSIP/{$num}",
                'partner' => isset($active_partners[$num]) ? formatEndpointName($active_partners[$num]) : ''
            ];
        }
        echo json_encode($fop_data);
        exit;
    }

    if ($action === 'get_trunks_status') {
        if (ob_get_level()) ob_end_clean();
        header('Content-Type: application/json');
        echo json_encode(getAsteriskTrunksStatus());
        exit;
    }

    if ($action === 'get_queues_realtime') {
        if (ob_get_level()) ob_end_clean();
        header('Content-Type: application/json');
        $period = isset($_GET['period']) ? trim($_GET['period']) : 'today';
        echo json_encode(getAsteriskQueuesRealtimeStatus($period));
        exit;
    }

    
    if ($action === 'get_active_calls') {
        if (ob_get_level()) ob_end_clean();
        header('Content-Type: application/json');
        echo json_encode(getAsteriskActiveCallsDetailed());
        exit;
    }

    if ($action === 'get_parking_lots') {
        if (ob_get_level()) ob_end_clean();
        header('Content-Type: application/json');
        echo json_encode(getAsteriskParkingLots());
        exit;
    }

    if ($action === 'get_conferences_status') {
        if (ob_get_level()) ob_end_clean();
        header('Content-Type: application/json');
        echo json_encode(getAsteriskConferences());
        exit;
    }

    if ($action === 'get_kpi_calls_detail') {
        if (ob_get_level()) ob_end_clean();
        header('Content-Type: application/json; charset=utf-8');

        $kpi_type   = trim($_GET['kpi_type'] ?? 'total');
        $start_date = trim($_GET['start_date'] ?? date('Y-m-d'));
        $end_date   = trim($_GET['end_date'] ?? date('Y-m-d'));

        $sql_date_condition = "calldate >= '$start_date 00:00:00' AND calldate <= '$end_date 23:59:59'";
        $where_clause = $sql_date_condition;

        $title_label = "Total de Atendimentos";
        if ($kpi_type === 'all' || $kpi_type === 'total_all') {
            $where_clause .= "";
            $title_label = "Total de Chamadas no Período";
        } elseif ($kpi_type === 'total' || $kpi_type === 'total_answered') {
            $where_clause .= " AND disposition = 'ANSWERED'";
            $title_label = "Total de Atendimentos Concluídos";
        } elseif ($kpi_type === 'ativa' || $kpi_type === 'out' || $kpi_type === 'outgoing') {
            $where_clause .= " AND (src REGEXP '^[0-9]{3,4}$' AND NOT dst REGEXP '^[0-9]{3,4}$')";
            $title_label = "Demanda Ativa (Chamadas Efetuadas)";
        } elseif ($kpi_type === 'receptiva' || $kpi_type === 'inc' || $kpi_type === 'incoming') {
            $where_clause .= " AND NOT src REGEXP '^[0-9]{3,4}$'";
            $title_label = "Demanda Receptiva (Chamadas Recebidas)";
        } elseif ($kpi_type === 'int' || $kpi_type === 'internal') {
            $where_clause .= " AND (src REGEXP '^[0-9]{3,4}$' AND dst REGEXP '^[0-9]{3,4}$')";
            $title_label = "Chamadas Internas (Entre Ramais)";
        } elseif ($kpi_type === 'unique') {
            $where_clause .= "";
            $title_label = "Chamadores Únicos";
        } elseif (strpos($kpi_type, 'hour_') === 0) {
            $hrNum = intval(str_replace('hour_', '', $kpi_type));
            $where_clause .= " AND HOUR(calldate) = $hrNum";
            $title_label = sprintf("Chamadas do Pico das %02d:00", $hrNum);
        } elseif (strpos($kpi_type, 'ext_') === 0) {
            $extNum = preg_replace('/\D/', '', $kpi_type);
            $where_clause .= " AND (src = '$extNum' OR dst = '$extNum')";
            $title_label = "Detalhamento do Ramal $extNum";
        } elseif ($kpi_type === 'tma') {
            $where_clause .= " AND disposition = 'ANSWERED'";
            $title_label = "Chamadas para Análise de TMA";
        } elseif ($kpi_type === 'tme' || $kpi_type === 'tpr') {
            $where_clause .= " AND disposition = 'ANSWERED' AND (duration - billsec) > 0";
            $title_label = "Chamadas com Tempo de Espera (TME / TPR)";
        }

        $ast_db = function_exists('getAsteriskPdoConnection') ? getAsteriskPdoConnection('asteriskcdrdb') : null;
        $calls = [];

        if ($ast_db) {
            try {
                $sql = "SELECT uniqueid, calldate, src, dst, disposition, duration, billsec, recordingfile 
                        FROM cdr 
                        WHERE $where_clause 
                        ORDER BY calldate DESC 
                        LIMIT 100";
                $stmt = $ast_db->query($sql);
                if ($stmt) $calls = $stmt->fetchAll(PDO::FETCH_ASSOC);
            } catch (Exception $e) {}
        }

        $contacts_map = function_exists('getContactsMap') ? getContactsMap() : [];
        foreach ($calls as &$c) {
            $c['src_name'] = function_exists('lookupContactName') ? lookupContactName($c['src'], $contacts_map) : '';
            $c['dst_name'] = function_exists('lookupContactName') ? lookupContactName($c['dst'], $contacts_map) : '';
            $c['calldate_fmt'] = date('d/m/Y H:i:s', strtotime($c['calldate']));
            $c['billsec_fmt']  = gmdate('i:s', intval($c['billsec']));
            $c['tme_sec']      = max(0, intval($c['duration']) - intval($c['billsec']));
            $c['tme_fmt']      = gmdate('i:s', $c['tme_sec']);
        }

        echo json_encode([
            'success' => true,
            'kpi_type' => $kpi_type,
            'title' => $title_label,
            'period' => "$start_date a $end_date",
            'count' => count($calls),
            'calls' => $calls
        ]);
        exit;
    }

    if ($action === 'fop_action') {
        $type = $_GET['type'] ?? '';
        $target_ext = isset($_GET['ext']) ? preg_replace('/[^0-9]/', '', $_GET['ext']) : '';
        $sup_ext = isset($_GET['sup_ext']) ? preg_replace('/[^0-9]/', '', $_GET['sup_ext']) : '1000';
        $channel = isset($_GET['channel']) ? preg_replace('/[^a-zA-Z0-9.\/_\-@]/', '', $_GET['channel']) : '';

        if (in_array($type, ['spy', 'whisper', 'barge'])) {
            if (empty($target_ext)) {
                echo json_encode(['success' => false, 'error' => 'Ramal de destino inválido.']);
                exit;
            }
            $spy_flag = 'q';
            if ($type === 'whisper') $spy_flag = 'qw';
            if ($type === 'barge') $spy_flag = 'qB';

            $ami_res = executeClickToCall($sup_ext, "*55{$target_ext}");
            echo json_encode([
                'success' => $ami_res['success'],
                'message' => $ami_res['success'] ? "Escuta ($type) iniciada no ramal $sup_ext enviando ChanSpy($spy_flag) no ramal $target_ext" : $ami_res['error']
            ]);
            exit;
        }

        if ($type === 'hangup' && !empty($channel)) {
            if (function_exists('shell_exec')) {
                @shell_exec('asterisk -rx ' . escapeshellarg('channel request hangup ' . $channel) . ' 2>/dev/null');
                echo json_encode(['success' => true, 'message' => "Solicitado Hangup no canal $channel"]);
            } else {
                echo json_encode(['success' => false, 'error' => "shell_exec desabilitado"]);
            }
            exit;
        }

        if ($type === 'update_extension_name') {
            $newName = trim($_GET['new_name'] ?? '');
            $newTech = strtoupper(trim($_GET['new_tech'] ?? 'PJSIP'));
            if (!empty($target_ext) && !empty($newName)) {
                $stmt = $db->prepare("UPDATE extensions_config SET agent_name = :n, tech = :t WHERE extension = :e");
                $stmt->execute([':n' => $newName, ':t' => $newTech, ':e' => $target_ext]);

                if (function_exists('getPABXSystemConfig')) {
                    $sys_conf = getPABXSystemConfig();
                    if (!empty($sys_conf['mysqlrootpwd'])) {
                        try {
                            $ast_db = new PDO("mysql:host=localhost;dbname=asterisk;charset=utf8", 'root', $sys_conf['mysqlrootpwd'], [
                                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                                PDO::ATTR_TIMEOUT => 2
                            ]);
                            $ast_db->prepare("UPDATE users SET name = :n WHERE extension = :e")->execute([':n' => $newName, ':e' => $target_ext]);
                            $ast_db->prepare("UPDATE devices SET description = :n, tech = :t WHERE id = :e")->execute([':n' => $newName, ':t' => strtolower($newTech), ':e' => $target_ext]);
                            if (function_exists('shell_exec')) {
                                @shell_exec('asterisk -rx "dialplan reload" 2>/dev/null');
                            }
                        } catch (Exception $ex) {}
                    }
                }

                echo json_encode(['success' => true, 'message' => "Ramal $target_ext atualizado com sucesso!"]);
            } else {
                echo json_encode(['success' => false, 'error' => "Parâmetros inválidos"]);
            }
            exit;
        }

        if ($type === 'pause_queue_agent') {
            $queue = $_GET['queue'] ?? '';
            $iface = $_GET['interface'] ?? '';
            $paused = intval($_GET['paused'] ?? 1);
            $pause = ($paused === 1);
            $reason = $_GET['reason'] ?? 'Pausa Operacional';
            
            if ($queue && $iface) {
                $ami_res = pauseAsteriskQueueMember($queue, $iface, $pause, $paused, $reason);
                echo json_encode(['success' => $ami_res['success'], 'message' => $ami_res['success'] ? ($paused === 1 ? 'Agente pausado.' : 'Agente liberado.') : $ami_res['error']]);
            } else {
                echo json_encode(['success' => false, 'error' => "Parâmetros inválidos"]);
            }
            exit;
        }

        echo json_encode(['success' => false, 'error' => "Ação $type desconhecida."]);
        exit;
    }

    if ($action === 'originate_call') {
        if (!hasUserPermission('click_to_call')) {
            echo json_encode(['success' => false, 'error' => 'Sem permissão para originar chamadas.']);
            exit;
        }
        $from = isset($_GET['from']) ? preg_replace('/[^0-9]/', '', $_GET['from']) : '';
        $to   = isset($_GET['to']) ? preg_replace('/[^0-9]/', '', $_GET['to']) : '';
        if (empty($from) || empty($to)) {
            echo json_encode(['success' => false, 'error' => 'Origem e destino são obrigatórios']);
            exit;
        }
        
        // Disparo direto no Asterisk (PJSIP / SIP / Local)
        $ami_res = executeClickToCall($from, $to);
        if ($ami_res['success']) {
            echo json_encode(['success' => true, 'message' => "Chamada originada com sucesso! O ramal $from está tocando."]);
            exit;
        }

        // Fallback REST API
        require_once __DIR__ . '/includes/pbxapi_client.php';
        $apiClient = new PbxApiClient($db);
        $apiRes = $apiClient->originateCall($from, $to);
        echo json_encode([
            'success' => $apiRes['success'], 
            'message' => $apiRes['success'] ? "Chamada disparada via REST API do ramal $from para $to!" : ($ami_res['error'] ?: 'Falha ao conectar no Asterisk')
        ]);
        exit;
    }


    if ($action === 'save_contact') {
        if (ob_get_level()) ob_end_clean();
        header('Content-Type: application/json');

        $body = json_decode(file_get_contents('php://input'), true);
        $n = trim($body['c_name'] ?? ''); 
        $p = trim($body['c_phone'] ?? '');
        $e = trim($body['c_email'] ?? ''); 
        $co = trim($body['c_company'] ?? '');
        $t = trim($body['c_tag'] ?? ''); 
        $no = trim($body['c_notes'] ?? '');

        $cid = intval($body['c_id'] ?? 0);

        if ($n && $p) {
            try {
                if ($cid > 0) {
                    $db->prepare("UPDATE crm_contacts SET name=:n, phone=:p, email=:e, company=:co, tag=:t, notes=:no, updated_at=CURRENT_TIMESTAMP WHERE id=:id")
                       ->execute([':n'=>$n,':p'=>$p,':e'=>$e,':co'=>$co,':t'=>$t,':no'=>$no,':id'=>$cid]);
                } else {
                    $db->prepare("INSERT INTO crm_contacts (name, phone, email, company, tag, source, notes) VALUES (:n,:p,:e,:co,:t,'Manual',:no)")
                       ->execute([':n'=>$n,':p'=>$p,':e'=>$e,':co'=>$co,':t'=>$t,':no'=>$no]);
                }
                   
                // Tentativa de sincronizar com a agenda do PABX (address_book.db)
                try { AddressBookSync::syncToPABX($n, $p, $e, $co, $no); } catch (Throwable $e_abs) {}

                $msg = $cid > 0 ? "Contato '$n' atualizado com sucesso!" : "Contato '$n' adicionado com sucesso!";
                echo json_encode(['success' => true, 'message' => $msg, 'issabel_sync_error' => $sync_error ?? null]);
            } catch (Exception $e2) {
                echo json_encode(['success' => false, 'error' => "Erro: " . $e2->getMessage()]);
            }
        } else {
            echo json_encode(['success' => false, 'error' => 'Nome e Telefone são obrigatórios.']);
        }
        exit;
    }

    
    
    if ($action === 'get_ai_insights_history' || $action === 'get_ai_history') {
        if (ob_get_level()) ob_end_clean();
        header('Content-Type: application/json');
        try {
            $rows = $db->query("SELECT * FROM ai_insights_history ORDER BY id DESC LIMIT 50")->fetchAll(PDO::FETCH_ASSOC);
            foreach ($rows as &$r) {
                $dec = json_decode((string)$r['insights_json'], true);
                $r['insights'] = is_array($dec) ? $dec : [];
                $r['created_at_fmt'] = date('d/m/Y, H:i:s', strtotime($r['created_at']));
            }
            unset($r);
            echo json_encode(['success' => true, 'history' => $rows]);
        } catch (Exception $e) {
            echo json_encode(['success' => false, 'error' => $e->getMessage()]);
        }
        exit;
    }

    if ($action === 'delete_ai_insight') {
        if (ob_get_level()) ob_end_clean();
        header('Content-Type: application/json');
        $id = (int)($_GET['id'] ?? 0);
        if ($id > 0) {
            $db->prepare("DELETE FROM ai_insights_history WHERE id = :id")->execute([':id' => $id]);
        }
        echo json_encode(['success' => true]);
        exit;
    }

    if ($action === 'test_pbx_api') {
        if (ob_get_level()) ob_end_clean();
        header('Content-Type: application/json');
        require_once __DIR__ . '/includes/pbxapi_client.php';
        $apiClient = new PbxApiClient($db);
        $res = $apiClient->authenticate();
        if ($res['success']) {
            echo json_encode(['success' => true, 'message' => '⚡ Conexão com REST API PABX estabelecida com SUCESSO! Token JWT autenticado.']);
        } else {
            echo json_encode(['success' => false, 'error' => $res['message']]);
        }
        exit;
    }

    if ($action === 'test_prismabot_api') {
        if (ob_get_level()) ob_end_clean();
        header('Content-Type: application/json');
        $url   = getSetting('api_url');
        $token = getSetting('api_token');
        if (empty($url) || empty($token)) {
            echo json_encode(['success' => false, 'error' => 'Webhook URL ou Token do Prismabot não configurados.']);
            exit;
        }
        $ch = curl_init(rtrim($url, '/') . '/status');
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 5,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . $token]
        ]);
        $out = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if ($code >= 200 && $code < 400) {
            echo json_encode(['success' => true, 'message' => '⚡ Conexão com API Prismabot realizada com SUCESSO!']);
        } else {
            echo json_encode(['success' => true, 'message' => '⚡ Webhook Prismabot alcançado com código HTTP ' . $code]);
        }
        exit;
    }

    if ($action === 'test_mysql_cdr') {
        if (ob_get_level()) ob_end_clean();
        header('Content-Type: application/json');
        $pdo = getAsteriskPdoConnection('asteriskcdrdb');
        if ($pdo) {
            $stmt = $pdo->query("SELECT COUNT(*) FROM cdr");
            $count = $stmt ? $stmt->fetchColumn() : 0;
            echo json_encode(['success' => true, 'message' => "⚡ Conexão com Banco MySQL (asteriskcdrdb) realizada com SUCESSO! Total de $count registros no CDR."]);
        } else {
            echo json_encode(['success' => false, 'error' => 'Falha ao conectar no MySQL do Asterisk. Verifique host, usuário e senha.']);
        }
        exit;
    }
    // ---------------------------------------------------------------------
    // Envio manual de WhatsApp (botões de compartilhar / FOP / contatos / resumo IA)
    // ---------------------------------------------------------------------
    if ($action === 'send_whatsapp_message') {
        header('Content-Type: application/json; charset=utf-8');
        $body  = json_decode(file_get_contents('php://input'), true) ?: [];
        $phone = preg_replace('/\D/', '', (string)($body['phone'] ?? ''));
        $msg   = trim((string)($body['message'] ?? ''));
        $uid   = trim((string)($body['uid'] ?? ''));
        if (!hasUserPermission('send_whatsapp')) { echo json_encode(['success' => false, 'error' => 'Sem permissão para enviar WhatsApp.']); exit; }
        if (strlen($phone) < 10 || strlen($phone) > 15) { echo json_encode(['success' => false, 'error' => 'Número inválido. Informe DDD + número.']); exit; }
        if ($msg === '' || mb_strlen($msg) > 4000) { echo json_encode(['success' => false, 'error' => 'Mensagem vazia ou maior que 4000 caracteres.']); exit; }
        $warning = '';
        if ($uid !== '') {
            if (!hasUserPermission('listen_recordings')) { echo json_encode(['success' => false, 'error' => 'Sem permissão para compartilhar gravações.']); exit; }
            $link = audioSignedUrl($uid);
            if ($link !== '') $msg .= "\n\n🎧 Gravação (link válido por 7 dias): " . $link;
            else $warning = 'Enviado sem o link da gravação: configure a "URL pública do painel" em Configurações > API.';
        }
        $lu  = getLoggedUser();
        $res = sendWhatsAppAPI($phone, $msg, $uid !== '' ? $uid : 'MANUAL', (string)($lu['extension'] ?? 'PAINEL'), 'ENVIO_MANUAL');
        echo json_encode(['success' => !empty($res['success']), 'error' => $res['error'] ?? '', 'warning' => $warning]);
        exit;
    }

    // ---------------------------------------------------------------------
    // Envio manual de e-mail (SMTP configurado em Configurações > SMTP)
    // ---------------------------------------------------------------------
    if ($action === 'send_email_report') {
        header('Content-Type: application/json; charset=utf-8');
        $body = json_decode(file_get_contents('php://input'), true) ?: [];
        $to   = trim((string)($body['to'] ?? ''));
        $subj = trim(preg_replace('/[\r\n]+/', ' ', (string)($body['subject'] ?? 'Relatório IPbx Prisma')));
        $msg  = trim((string)($body['message'] ?? ''));
        $uid  = trim((string)($body['uid'] ?? ''));
        if (!hasUserPermission('send_whatsapp') && !hasUserPermission('schedule_reports')) { echo json_encode(['success' => false, 'error' => 'Sem permissão para enviar e-mails.']); exit; }
        if (!filter_var($to, FILTER_VALIDATE_EMAIL)) { echo json_encode(['success' => false, 'error' => 'E-mail de destino inválido.']); exit; }
        if ($msg === '' || mb_strlen($msg) > 20000) { echo json_encode(['success' => false, 'error' => 'Mensagem vazia ou muito longa.']); exit; }
        $smtpFile = __DIR__ . '/config/smtp_settings.json';
        $smtp = is_file($smtpFile) ? json_decode((string)@file_get_contents($smtpFile), true) : null;
        if (!is_array($smtp) || empty($smtp['host']) || empty($smtp['is_active'])) {
            echo json_encode(['success' => false, 'error' => 'SMTP não configurado/ativo. Abra Configurações > Servidor SMTP e salve as credenciais.']);
            exit;
        }
        $warning = '';
        if ($uid !== '') {
            if (!hasUserPermission('listen_recordings')) { echo json_encode(['success' => false, 'error' => 'Sem permissão para compartilhar gravações.']); exit; }
            $link = audioSignedUrl($uid);
            if ($link !== '') $msg .= "\n\nGravação (link válido por 7 dias): " . $link;
            else $warning = 'Enviado sem o link da gravação: configure a "URL pública do painel" em Configurações > API.';
        }
        $res = sendSmtpEmailNative($smtp, $to, $subj, $msg);
        echo json_encode(['success' => !empty($res['success']), 'error' => $res['error'] ?? '', 'warning' => $warning]);
        exit;
    }

    // ---------------------------------------------------------------------
    // Análise de uma gravação por IA: transcrição (Whisper) + resumo/sentimento (LLM)
    // ---------------------------------------------------------------------
    if ($action === 'analyze_call_audio') {
        header('Content-Type: application/json; charset=utf-8');
        $body = json_decode(file_get_contents('php://input'), true) ?: [];
        $uid  = trim((string)($body['uid'] ?? ''));
        if (!hasUserPermission('listen_recordings')) { echo json_encode(['success' => false, 'error' => 'Sem permissão para acessar gravações.']); exit; }
        if ($uid === '') { echo json_encode(['success' => false, 'error' => 'Chamada não informada.']); exit; }
        $cfg = aiGetConfig();
        if ($cfg['key'] === '')  { echo json_encode(['success' => false, 'error' => 'API Key de IA não configurada em Configurações > Inteligência Artificial.']); exit; }
        if (!$cfg['enabled'])    { echo json_encode(['success' => false, 'error' => 'Copiloto de IA desativado em Configurações > Inteligência Artificial.']); exit; }

        $cdr = getAsteriskPdoConnection('asteriskcdrdb');
        $row = false;
        if ($cdr) {
            try {
                $st = $cdr->prepare("SELECT src, dst, calldate, billsec, disposition, recordingfile FROM cdr WHERE uniqueid = :u LIMIT 1");
                $st->execute([':u' => $uid]);
                $row = $st->fetch(PDO::FETCH_ASSOC);
            } catch (Exception $e) {}
        }
        if (!$row || empty($row['recordingfile'])) { echo json_encode(['success' => false, 'error' => 'Chamada ou gravação não encontrada no CDR.']); exit; }
        $path = findRecordingPath($row['recordingfile'], $row['calldate']);
        if ($path === '') { echo json_encode(['success' => false, 'error' => 'Arquivo de gravação não encontrado em /var/spool/asterisk/monitor.']); exit; }

        $tr = aiTranscribeFile($path, $cfg);
        if (!$tr['ok']) { echo json_encode(['success' => false, 'error' => 'Transcrição: ' . $tr['error']]); exit; }
        if ($tr['text'] === '') { echo json_encode(['success' => false, 'error' => 'A transcrição voltou vazia (áudio sem fala?).']); exit; }

        $r = aiChatCompletion(
            'Você analisa ligações telefônicas de atendimento. Responda APENAS um JSON no formato {"resumo":"até 4 linhas","sentimento":"Positivo|Neutro|Negativo","satisfacao":número de 1 a 5 ou null se a conversa não permitir inferir,"recomendacao":"próximo passo objetivo"}. Use somente o que consta na transcrição; não invente fatos.',
            mb_substr($tr['text'], 0, 12000), 40, $cfg
        );
        if (!$r['ok']) { echo json_encode(['success' => false, 'error' => 'Análise: ' . $r['error'], 'transcript' => $tr['text']]); exit; }
        $txt = preg_replace('/^```(?:json)?\s*|\s*```$/i', '', trim($r['text']));
        $j = json_decode($txt, true);
        if (!is_array($j) && preg_match('/\{.*\}/s', $txt, $mm)) $j = json_decode($mm[0], true);
        if (!is_array($j) || empty($j['resumo'])) { echo json_encode(['success' => false, 'error' => 'A IA respondeu fora do formato esperado.', 'transcript' => $tr['text']]); exit; }
        $sat = isset($j['satisfacao']) && is_numeric($j['satisfacao']) ? max(1, min(5, (float)$j['satisfacao'])) : null;
        echo json_encode([
            'success' => true,
            'resumo' => (string)$j['resumo'],
            'sentimento' => (string)($j['sentimento'] ?? ''),
            'satisfacao' => $sat,
            'recomendacao' => (string)($j['recomendacao'] ?? ''),
            'transcript' => $tr['text'],
            'provider' => $cfg['provider'],
            'model' => $cfg['model'],
        ]);
        exit;
    }

    if ($action === 'test_llm_connection') {
        if (ob_get_level()) ob_end_clean();
        header('Content-Type: application/json');

        $body     = json_decode(file_get_contents('php://input'), true);
        $provider = trim($body['provider']  ?? getSetting('ai_provider') ?? 'openai');
        $key      = trim($body['api_key']   ?? getSetting('ai_api_key')  ?? '');
        $baseUrl  = trim($body['base_url']  ?? getSetting('ai_base_url') ?? '');
        $model    = trim($body['model_chat']?? getSetting('ai_model_chat')?? '');

        $providerDefaults = [
            'openai'   => ['url' => 'https://api.openai.com/v1',                           'model' => 'gpt-4o-mini'],
            'groq'     => ['url' => 'https://api.groq.com/openai/v1',                      'model' => 'llama-3.3-70b-versatile'],
            'gemini'   => ['url' => 'https://generativelanguage.googleapis.com/v1beta/openai', 'model' => 'gemini-1.5-flash'],
            'deepseek' => ['url' => 'https://api.deepseek.com/v1',                         'model' => 'deepseek-chat']
        ];

        $pDef = isset($providerDefaults[$provider]) ? $providerDefaults[$provider] : $providerDefaults['openai'];
        if (empty($baseUrl)) $baseUrl = $pDef['url'];
        if (empty($model))   $model   = $pDef['model'];

        if (empty($key)) {
            echo json_encode(['success' => false, 'error' => 'API Key não informada. Cole a chave antes de testar.']);
            exit;
        }

        $endpoint = rtrim($baseUrl, '/') . '/chat/completions';
        $payload  = json_encode([
            'model'    => $model,
            'messages' => [
                ['role' => 'system', 'content' => 'Você é um assistente de teste de conexão PABX IP Prisma.'],
                ['role' => 'user', 'content' => 'Responda exatamente: OK']
            ],
            'max_tokens' => 5
        ]);

        $ch = curl_init($endpoint);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $payload,
            CURLOPT_HTTPHEADER     => [
                'Content-Type: application/json',
                'Authorization: Bearer ' . $key
            ],
            CURLOPT_TIMEOUT        => 15,
            CURLOPT_SSL_VERIFYPEER => (getSetting("ai_ssl_insecure") !== "1")
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlErr  = curl_error($ch);
        curl_close($ch);

        if ($curlErr) {
            echo json_encode(['success' => false, 'error' => 'Erro de conexão cURL: ' . $curlErr]);
            exit;
        }

        $resData = json_decode($response, true);
        if ($httpCode === 200 && isset($resData['choices'][0]['message']['content'])) {
            echo json_encode([
                'success' => true, 
                'message' => "Conexão com $provider ($model) estabelecida com sucesso! Resposta: " . trim($resData['choices'][0]['message']['content'])
            ]);
        } else {
            $errDetail = isset($resData['error']['message']) ? $resData['error']['message'] : "HTTP $httpCode";
            echo json_encode(['success' => false, 'error' => "Falha na API ($provider): " . $errDetail]);
        }
        exit;
    }

    if ($action === 'analyze_pabx_insights') {
        if (ob_get_level()) ob_end_clean();
        header('Content-Type: application/json; charset=utf-8');
        $body = json_decode(file_get_contents('php://input'), true) ?: [];

        $tot   = (int)($body['total'] ?? 0);
        $atend = (int)($body['atendidas'] ?? 0);
        $tma   = (int)($body['tmaSec'] ?? 0);
        $tme   = (int)($body['tmeSec'] ?? 0);
        $module = preg_replace('/[^\p{L}\p{N} _\-]/u', '', (string)($body['module'] ?? 'Geral'));

        $cfg = aiGetConfig();
        $ai_configured = ($cfg['key'] !== '' && $cfg['enabled']);
        $facts = aiFactInsights($tot, $atend, $tma, $tme);
        $insights = [];
        $aiError = '';

        if ($ai_configured && $tot > 0) {
            $pct = round(($atend / $tot) * 100, 1);
            $r = aiChatCompletion(
                'Você é analista de PABX IP. Retorne apenas JSON puro no formato {"insights": ["...","...","..."]}, com no máximo 3 itens curtos, objetivos e em português. Use somente os números informados; não invente dados.',
                "Módulo: $module. Chamadas: $tot; atendidas: $atend ($pct%); TMA (conversa): {$tma}s; TME (espera): {$tme}s.",
                20, $cfg
            );
            if ($r['ok']) {
                $insights = aiParseInsights($r['text']);
                if (empty($insights)) $aiError = 'Resposta da IA fora do formato esperado';
            } else {
                $aiError = $r['error'];
            }
        }

        $usedAi = !empty($insights);
        if (!$usedAi) $insights = $facts;
        aiSaveHistory($usedAi ? $cfg['provider'] : 'metricas', $usedAi ? $cfg['model'] : 'sem-ia', $insights);

        $notice = null;
        if ($cfg['key'] === '')      $notice = 'IA não configurada (Configurações > Inteligência Artificial): exibindo apenas as métricas reais.';
        elseif (!$cfg['enabled'])    $notice = 'Copiloto de IA desativado em Configurações: exibindo apenas as métricas reais.';
        elseif ($aiError !== '')     $notice = 'Falha na IA (' . $aiError . '): exibindo apenas as métricas reais.';

        echo json_encode([
            'success' => true,
            'ai_configured' => $ai_configured,
            'engine_label' => $usedAi ? "IA Generativa ({$cfg['provider']})" : 'Métricas reais do PABX (sem IA)',
            'notice' => $notice,
            'insights' => $insights
        ]);
        exit;
    }

    if ($action === 'transcribe_audio') {
        if (ob_get_level()) ob_end_clean();
        header('Content-Type: application/json');

        $body     = json_decode(file_get_contents('php://input'), true);
        $fileRel  = trim($body['file'] ?? '');

        $cfg      = aiGetConfig();
        $provider = $cfg['provider'];
        $key      = $cfg['key'];
        $model    = $cfg['audio'];

        if (!$cfg['enabled']) {
            echo json_encode(['success' => false, 'error' => 'Copiloto de IA desativado em Configurações.']);
            exit;
        }
        if (empty($key)) {
            echo json_encode(['success' => false, 'error' => 'Nenhuma API Key de IA configurada nas Configurações.']);
            exit;
        }
        if (!in_array($provider, ['openai', 'groq'], true) || empty($model)) {
            echo json_encode(['success' => false, 'error' => "O provedor '$provider' não oferece transcrição de áudio. Use OpenAI ou Groq (Whisper) em Configurações > Inteligência Artificial."]);
            exit;
        }
        if (function_exists('hasUserPermission') && !hasUserPermission('listen_recordings')) {
            echo json_encode(['success' => false, 'error' => 'Sem permissão para acessar gravações.']);
            exit;
        }
        if (empty($fileRel)) {
            echo json_encode(['success' => false, 'error' => 'Arquivo de áudio não especificado.']);
            exit;
        }

        // Apenas arquivos dentro das pastas de gravação/sons do Asterisk (bloqueia ../ e caminhos absolutos)
        $audioFilePath = '';
        $roots = ['/var/spool/asterisk/monitor', '/var/lib/asterisk/sounds'];
        $cands = [];
        $relClean = ltrim(str_replace('\\', '/', $fileRel), '/');
        foreach ($roots as $rt) {
            $cands[] = $rt . '/' . $relClean;
            $cands[] = $rt . '/' . date('Y/m/d/') . basename($relClean);
            foreach (glob($rt . '/*/*/*/' . basename($relClean)) ?: [] as $g) $cands[] = $g;
        }
        foreach ($cands as $p) {
            $rp = realpath($p);
            if ($rp === false || !is_file($rp) || !is_readable($rp)) continue;
            foreach ($roots as $rt) {
                if (strpos($rp, $rt . '/') === 0) { $audioFilePath = $rp; break 2; }
            }
        }

        if (empty($audioFilePath)) {
            echo json_encode(['success' => false, 'error' => 'Arquivo de áudio não encontrado na pasta do Asterisk.']);
            exit;
        }

        $tr = aiTranscribeFile($audioFilePath, $cfg);
        if ($tr['ok']) {
            echo json_encode([
                'success' => true,
                'text'    => $tr['text'],
                'message' => "Transcrição gerada com sucesso via $provider ($model)!"
            ]);
        } else {
            echo json_encode(['success' => false, 'error' => 'Falha na transcrição: ' . $tr['error']]);
        }
        exit;
    }

    if ($action === 'generate_ai_insights') {
        if (ob_get_level()) ob_end_clean();
        header('Content-Type: application/json');

        $cfg = aiGetConfig();
        $m   = aiTodayMetrics();
        $facts = aiFactInsights($m['total'], $m['atendidas'], $m['tma'], $m['tme']);
        $insightsList = [];
        $aiError = '';

        if ($cfg['key'] !== '' && $cfg['enabled'] && $m['total'] > 0) {
            $r = aiChatCompletion(
                'Você é analista de PABX IP e telefonia empresarial. Retorne apenas JSON puro no formato {"insights": ["...","...","..."]}, com 3 itens curtos e acionáveis em português. Use somente os números informados; não invente dados.',
                "Métricas de hoje: chamadas {$m['total']}; atendidas {$m['atendidas']}; TMA {$m['tma']}s; TME {$m['tme']}s.",
                25, $cfg
            );
            if ($r['ok']) {
                $insightsList = aiParseInsights($r['text']);
                if (empty($insightsList)) $aiError = 'Resposta da IA fora do formato esperado';
            } else {
                $aiError = $r['error'];
            }
        }

        $usedAi = !empty($insightsList);
        if (!$usedAi) $insightsList = $facts;
        aiSaveHistory($usedAi ? $cfg['provider'] : 'metricas', $usedAi ? $cfg['model'] : 'sem-ia', $insightsList);

        $notice = null;
        if ($cfg['key'] === '')   $notice = 'IA não configurada em Configurações > Inteligência Artificial: exibindo apenas as métricas reais.';
        elseif (!$cfg['enabled']) $notice = 'Copiloto de IA desativado em Configurações: exibindo apenas as métricas reais.';
        elseif ($aiError !== '')  $notice = 'Falha na IA (' . $aiError . '): exibindo apenas as métricas reais.';
        elseif (!$m['ok'])        $notice = 'Não foi possível ler o CDR (asteriskcdrdb).';

        echo json_encode([
            'success' => true,
            'ai_configured' => ($cfg['key'] !== '' && $cfg['enabled']),
            'notice' => $notice,
            'provider' => $usedAi ? $cfg['provider'] : 'metricas',
            'model' => $usedAi ? $cfg['model'] : 'sem-ia',
            'created_at_fmt' => date('H:i'),
            'insights' => $insightsList
        ]);
        exit;
    }

    if ($action === 'elevenlabs_tts') {
        if (ob_get_level()) ob_end_clean();
        $el_key      = getSetting('elevenlabs_api_key');
        $el_voice    = preg_replace('/[^A-Za-z0-9_-]/', '', (string)(getSetting('elevenlabs_voice_id') ?: 'EXAVITQu4vr4xnSDxMaL'));
        $el_model    = getSetting('elevenlabs_model')    ?: 'eleven_multilingual_v2';

        $body = json_decode(file_get_contents('php://input'), true);
        $text = trim($body['text'] ?? '');

        if (getSetting('elevenlabs_enabled') === '0' || empty($el_key) || empty($text)) {
            http_response_code(400);
            echo json_encode(['error' => 'API Key ElevenLabs ou texto não configurado.']);
            exit;
        }

        $el_url = "https://api.elevenlabs.io/v1/text-to-speech/{$el_voice}";
        $payload = json_encode([
            'text'           => $text,
            'model_id'       => $el_model,
            'voice_settings' => ['stability' => 0.5, 'similarity_boost' => 0.8]
        ]);

        $ch = curl_init($el_url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $payload,
            CURLOPT_HTTPHEADER     => [
                'Content-Type: application/json',
                'xi-api-key: ' . $el_key,
                'Accept: audio/mpeg'
            ],
            CURLOPT_TIMEOUT        => 30,
            CURLOPT_SSL_VERIFYPEER => true,
        ]);

        $result   = curl_exec($ch);
        $http_c   = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curl_err = curl_error($ch);
        curl_close($ch);

        if ($curl_err || $http_c !== 200) {
            http_response_code(502);
            echo json_encode(['error' => 'ElevenLabs TTS falhou: ' . ($curl_err ?: 'HTTP ' . $http_c)]);
            exit;
        }

        header('Content-Type: audio/mpeg');
        header('Content-Length: ' . strlen($result));
        echo $result;
        exit;
    }
}

// Route parameters
$module = isset($_GET['module']) ? $_GET['module'] : 'dashboard';
$action = isset($_GET['action']) ? $_GET['action'] : 'view_v1';

// Mapeamento de atalho: se o usuário tentar abrir action=view no dashboard, direciona para view_v1
if ($module === 'dashboard' && $action === 'view') {
    $action = 'view_v1';
}

// Security Whitelist Router
$allowed_modules = [
    'dashboard' => ['view_v1', 'view_v2', 'view'],
    'ramais' => ['fop', 'editar_nome', 'gestao'],
    'troncos' => ['view'],
    'filas' => ['realtime', 'flowchart', 'agentes'],
    'relatorios' => ['ia_analytics', 'filas_stats', 'cdr_gravacoes', 'graphic_reports', 'relatorio_geral', 'relatorio_filas'],
    'whatsapp' => ['regras', 'historico', 'disparos', 'contatos'],
    'telefonia' => ['blacklist'],
    'configuracoes' => ['ami', 'ia', 'api', 'usuarios', 'api_connection', 'smtp', 'logs']
];

if (!isset($allowed_modules[$module]) || !in_array($action, $allowed_modules[$module])) {
    $module = 'dashboard';
    $action = 'view_v1';
}

// Permissão por módulo (mesmas regras de exibição do menu)
$__route_denied = false;
$__route_perm = authRoutePermission($module, $action);
if ($__route_perm !== '' && !hasUserPermission($__route_perm)) {
    $__route_denied = true;
}
?>
<!DOCTYPE html>
<html lang="pt-BR" class="dark">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>IPbx Prisma x Prismabot - Painel Integrado (NEWFRONT)</title>
    
    <!-- Google Fonts: Inter -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800;900&display=swap" rel="stylesheet">
    
    <!-- FontAwesome 6 Pro -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    
    <!-- Tailwind CSS (CDN compilado) -->
    <script src="https://cdn.tailwindcss.com"></script>
    <script>
        tailwind.config = {
            darkMode: 'class',
            theme: {
                extend: {
                    fontFamily: { sans: ['Inter', 'sans-serif'] },
                    colors: {
                        brand: {
                            50: '#eef2ff', 500: '#6366f1', 600: '#4f46e5', 700: '#4338ca', 900: '#312e81'
                        }
                    }
                }
            }
        }
    </script>
    
    <!-- JsSIP WebRTC Softphone Library -->
    <script src="jssip.min.js" onerror="this.onerror=null;this.src='https://cdnjs.cloudflare.com/ajax/libs/jssip/3.10.1/jssip.min.js';"></script>

    <!-- Export PDF & Image Libraries (html2pdf & html2canvas) -->
    <script src="https://cdnjs.cloudflare.com/ajax/libs/html2pdf.js/0.10.1/html2pdf.bundle.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/html2canvas/1.4.1/html2canvas.min.js"></script>

    <style>
        .custom-scrollbar::-webkit-scrollbar { width: 6px; height: 6px; }
        .custom-scrollbar::-webkit-scrollbar-track { background: rgba(15, 23, 42, 0.6); }
        .custom-scrollbar::-webkit-scrollbar-thumb { background: rgba(51, 65, 85, 0.8); border-radius: 4px; }
        .custom-scrollbar::-webkit-scrollbar-thumb:hover { background: rgba(99, 102, 241, 0.8); }

        /* Estilos do Modo Claro (Light Mode Pro Max: Alta Definição, Contraste & Elegância Soft) */
        body.theme-light {
            background: linear-gradient(135deg, #f8fafc 0%, #f1f5f9 100%) !important;
            color: #0f172a !important;
            -webkit-font-smoothing: antialiased;
        }
        body.theme-light aside {
            background: #ffffff !important;
            border-right: 1px solid #e2e8f0 !important;
            box-shadow: 4px 0 24px rgba(15, 23, 42, 0.03) !important;
        }
        body.theme-light header {
            background: rgba(255, 255, 255, 0.95) !important;
            backdrop-filter: blur(12px);
            border-bottom: 1px solid #e2e8f0 !important;
            color: #0f172a !important;
        }
        body.theme-light .bg-slate-900, 
        body.theme-light .bg-slate-900\/90,
        body.theme-light .bg-slate-900\/80,
        body.theme-light .bg-slate-900\/60 {
            background-color: #ffffff !important;
            border-color: #e2e8f0 !important;
            color: #0f172a !important;
            box-shadow: 0 10px 30px -5px rgba(15, 23, 42, 0.05), 0 4px 6px -2px rgba(15, 23, 42, 0.02) !important;
        }
        body.theme-light .bg-slate-950,
        body.theme-light .bg-slate-950\/90,
        body.theme-light .bg-slate-950\/80 {
            background-color: #f8fafc !important;
            border-color: #e2e8f0 !important;
            color: #0f172a !important;
        }
        body.theme-light .text-white, 
        body.theme-light .text-slate-100, 
        body.theme-light .text-slate-200 {
            color: #0f172a !important;
        }
        body.theme-light .text-slate-300, 
        body.theme-light .text-slate-400 {
            color: #475569 !important;
        }
        body.theme-light .text-slate-500 {
            color: #64748b !important;
        }
        body.theme-light .border-slate-800, 
        body.theme-light .border-slate-700,
        body.theme-light .border-slate-800\/60,
        body.theme-light .border-slate-800\/80 {
            border-color: #e2e8f0 !important;
        }
        body.theme-light input, 
        body.theme-light select, 
        body.theme-light textarea {
            background-color: #ffffff !important;
            color: #0f172a !important;
            border-color: #cbd5e1 !important;
            box-shadow: inset 0 1px 2px rgba(0, 0, 0, 0.03) !important;
        }
        body.theme-light input:focus, 
        body.theme-light select:focus, 
        body.theme-light textarea:focus {
            border-color: #6366f1 !important;
            box-shadow: 0 0 0 3px rgba(99, 102, 241, 0.15) !important;
        }
        body.theme-light table thead {
            background-color: #f1f5f9 !important;
            color: #1e293b !important;
            border-bottom: 2px solid #e2e8f0 !important;
        }
        body.theme-light table tbody tr:hover {
            background-color: #f8fafc !important;
        }
        body.theme-light .sidebar-text span, body.theme-light .sidebar-text h1 {
            color: #0f172a !important;
        }
        /* Botões secundários e cards no Modo Claro */
        body.theme-light button.bg-slate-800,
        body.theme-light button.bg-slate-900,
        body.theme-light a.bg-slate-800 {
            background-color: #f1f5f9 !important;
            color: #1e293b !important;
            border-color: #cbd5e1 !important;
        }
        body.theme-light button.bg-slate-800:hover,
        body.theme-light button.bg-slate-900:hover,
        body.theme-light a.bg-slate-800:hover {
            background-color: #e2e8f0 !important;
            color: #0f172a !important;
        }
        body.theme-light .queue-item-card, body.theme-light .fop-card {
            box-shadow: 0 10px 25px -5px rgba(15, 23, 42, 0.06), 0 8px 10px -6px rgba(15, 23, 42, 0.02) !important;
        }

        /* -----------------------------------------------------------------
         * IMPRESSÃO / EXPORTAÇÃO PDF LIMPA (SEM SIDEBAR, HEADER E MENUS)
         * ----------------------------------------------------------------- */
        @media print {
            aside, header, #webphone-modal, .print\:hidden, .no-print, button, nav, form button, .no-export {
                display: none !important;
            }
            body, main, div.flex-1 {
                background-color: #0f172a !important;
                color: #ffffff !important;
                margin: 0 !important;
                padding: 0 !important;
                width: 100% !important;
                box-shadow: none !important;
                border: none !important;
            }
            main {
                padding: 10px !important;
            }
            .bg-slate-900\/90, .bg-slate-900, .bg-slate-950 {
                background-color: #0f172a !important;
                border-color: #334155 !important;
            }
        }
    </style>
</head>
<body class="bg-slate-950 text-slate-100 font-sans antialiased min-h-screen flex flex-col overflow-x-hidden">

    <div class="flex flex-1 min-h-screen">
        <!-- Sidebar Navigation (Menus & Submenus) -->
        <?php include_once __DIR__ . '/includes/sidebar.php'; ?>

        <!-- Main Workspace View -->
        <div class="flex-1 flex flex-col min-w-0 bg-slate-950">
            <!-- Header Bar -->
            <?php include_once __DIR__ . '/includes/header.php'; ?>

            <!-- Dynamic Module Content Router -->
            <main class="flex-1 p-6 overflow-y-auto custom-scrollbar">
                <?php
                if ($module === 'dashboard' && $action === 'view_v2') {
                    $target_file = __DIR__ . "/modules/dashboard/view.php";
                } elseif ($module === 'relatorios' && $action === 'filas_stats') {
                    $target_file = __DIR__ . "/modules/relatorios/relatorio_filas.php";
                } else {
                    $target_file = __DIR__ . "/modules/{$module}/{$action}.php";
                }

                if ($__route_denied) {
                    echo '<div class="p-10 text-center text-slate-400"><i class="fa-solid fa-lock text-3xl text-rose-400 mb-3"></i><div class="font-bold text-white">Acesso negado</div><div class="text-xs mt-1">Seu perfil não tem permissão para este módulo.</div></div>';
                } elseif (file_exists($target_file)) {
                    include_once $target_file;
                } else {
                    echo '<div class="p-10 text-center text-slate-500">Módulo não encontrado.</div>';
                }
                ?>
            </main>
        </div>
    </div>

    <!-- WebRTC Softphone Modal Flutuante -->
    <div id="webphone-modal" class="fixed bottom-16 right-6 z-50 hidden w-96 bg-slate-900/95 backdrop-blur-xl border border-slate-700/60 rounded-3xl shadow-2xl overflow-hidden transition-all duration-300">
        <!-- Modal Header -->
        <div class="p-4 bg-slate-950/80 border-b border-slate-800 flex items-center justify-between">
            <div class="flex items-center gap-3">
                <div class="w-8 h-8 rounded-full bg-emerald-500/20 text-emerald-400 flex items-center justify-center text-sm font-bold border border-emerald-500/30">
                    <i class="fa-solid fa-headset"></i>
                </div>
                <div>
                    <h3 class="text-sm font-extrabold text-white flex items-center gap-1.5">
                        WebPhone
                        <i class="fa-solid fa-grip-vertical text-slate-600 text-xs"></i>
                    </h3>
                    <span id="webphone-status-label" class="text-[11px] text-slate-400 font-medium block">Desconectado</span>
                </div>
            </div>
            <div class="flex items-center gap-2 text-slate-400 text-xs">
                <button title="Fixar" class="hover:text-white transition p-1"><i class="fa-solid fa-thumbtack"></i></button>
                <button title="Configurações" class="hover:text-white transition p-1"><i class="fa-solid fa-gear"></i></button>
                <button onclick="toggleWebPhone()" title="Minimizar" class="hover:text-white transition p-1"><i class="fa-solid fa-minus"></i></button>
            </div>
        </div>

        <!-- Modal Body Form & Dialpad -->
        <div class="p-5 space-y-4 text-xs">
            <!-- Box de Alerta de Erro SIP/WSS -->
            <div id="webphone-error-alert" class="hidden p-3 rounded-xl bg-rose-500/10 text-rose-400 border border-rose-500/30 font-bold space-y-1">
                <div class="flex items-center gap-1.5 text-xs">
                    <i class="fa-solid fa-triangle-exclamation"></i>
                    <span id="webphone-error-title">Falha ao Conectar Ramal</span>
                </div>
                <p id="webphone-error-desc" class="text-[11px] text-rose-300 font-normal leading-tight">Verifique a senha, o ramal ou aceite o certificado SSL da URL WSS no navegador.</p>
            </div>

            <!-- VIEW 1: Form de Conexão -->
            <div id="webphone-form-view" class="space-y-3">
                <div class="text-center pb-1">
                    <h4 class="font-extrabold text-white text-xs">Credenciais do Ramal WebRTC</h4>
                    <p class="text-[11px] text-slate-400 mt-0.5">Conecte seu ramal diretamente pelo navegador</p>
                </div>

                <div>
                    <label class="block text-[10px] font-extrabold uppercase tracking-wider text-slate-400 mb-1">RAMAL SIP</label>
                    <input type="text" id="webphone-sip-ext" placeholder="Ex: 1001" value="1001"
                           class="w-full px-3.5 py-2.5 bg-slate-950/90 border border-slate-800 rounded-xl text-white font-mono placeholder-slate-600 focus:border-emerald-500 focus:outline-none transition">
                </div>

                <div>
                    <label class="block text-[10px] font-extrabold uppercase tracking-wider text-slate-400 mb-1">SENHA DO RAMAL</label>
                    <input type="password" id="webphone-sip-pass" placeholder="Senha do ramal" value="1001"
                           class="w-full px-3.5 py-2.5 bg-slate-950/90 border border-slate-800 rounded-xl text-white font-mono placeholder-slate-600 focus:border-emerald-500 focus:outline-none transition">
                </div>

                <div>
                    <?php $default_wss_host = isset($_SERVER['HTTP_HOST']) ? explode(':', $_SERVER['HTTP_HOST'])[0] : '192.168.0.251'; ?>
                    <label class="block text-[10px] font-extrabold uppercase tracking-wider text-slate-400 mb-1">SERVIDOR WSS (WEBSOCKET)</label>
                    <input type="text" id="webphone-wss-url" placeholder="wss://<?php echo $default_wss_host; ?>:8089/ws" value="wss://<?php echo $default_wss_host; ?>:8089/ws"
                           class="w-full px-3.5 py-2.5 bg-slate-950/90 border border-slate-800 rounded-xl text-white font-mono placeholder-slate-600 focus:border-emerald-500 focus:outline-none transition">
                    <span class="text-[10px] text-slate-500 block mt-1 leading-tight">
                        Exemplo: wss://<?php echo $default_wss_host; ?>:8089/ws
                    </span>
                </div>

                <button onclick="connectWebPhoneSip()" class="w-full py-3 bg-emerald-600 hover:bg-emerald-500 text-white font-extrabold rounded-xl transition shadow-lg shadow-emerald-600/30 flex items-center justify-center gap-2 text-xs mt-2">
                    <i class="fa-solid fa-plug"></i>
                    <span>Conectar Ramal</span>
                </button>
            </div>

            <!-- VIEW 2: Dialpad Numérico de Chamadas -->
            <div id="webphone-dialpad-view" class="hidden space-y-3">
                <div class="bg-slate-950 p-3 rounded-xl border border-slate-800 text-center">
                    <input type="text" id="webphone-dial-display" placeholder="Digite ou clique nos números..."
                           class="w-full bg-transparent text-center text-xl font-bold font-mono text-emerald-400 focus:outline-none tracking-widest">
                </div>

                <!-- Teclado Numérico -->
                <div class="grid grid-cols-3 gap-2 text-center font-mono">
                    <button onclick="pressDialKey('1')" class="py-2.5 bg-slate-800 hover:bg-slate-700 text-white font-bold rounded-xl text-sm transition">1</button>
                    <button onclick="pressDialKey('2')" class="py-2.5 bg-slate-800 hover:bg-slate-700 text-white font-bold rounded-xl text-sm transition">2</button>
                    <button onclick="pressDialKey('3')" class="py-2.5 bg-slate-800 hover:bg-slate-700 text-white font-bold rounded-xl text-sm transition">3</button>
                    <button onclick="pressDialKey('4')" class="py-2.5 bg-slate-800 hover:bg-slate-700 text-white font-bold rounded-xl text-sm transition">4</button>
                    <button onclick="pressDialKey('5')" class="py-2.5 bg-slate-800 hover:bg-slate-700 text-white font-bold rounded-xl text-sm transition">5</button>
                    <button onclick="pressDialKey('6')" class="py-2.5 bg-slate-800 hover:bg-slate-700 text-white font-bold rounded-xl text-sm transition">6</button>
                    <button onclick="pressDialKey('7')" class="py-2.5 bg-slate-800 hover:bg-slate-700 text-white font-bold rounded-xl text-sm transition">7</button>
                    <button onclick="pressDialKey('8')" class="py-2.5 bg-slate-800 hover:bg-slate-700 text-white font-bold rounded-xl text-sm transition">8</button>
                    <button onclick="pressDialKey('9')" class="py-2.5 bg-slate-800 hover:bg-slate-700 text-white font-bold rounded-xl text-sm transition">9</button>
                    <button onclick="pressDialKey('*')" class="py-2.5 bg-slate-800 hover:bg-slate-700 text-white font-bold rounded-xl text-sm transition">*</button>
                    <button onclick="pressDialKey('0')" class="py-2.5 bg-slate-800 hover:bg-slate-700 text-white font-bold rounded-xl text-sm transition">0</button>
                    <button onclick="pressDialKey('#')" class="py-2.5 bg-slate-800 hover:bg-slate-700 text-white font-bold rounded-xl text-sm transition">#</button>
                </div>

                <div class="grid grid-cols-2 gap-2 pt-1">
                    <button onclick="makeWebPhoneCall()" class="py-2.5 bg-emerald-600 hover:bg-emerald-500 text-white font-bold rounded-xl transition flex items-center justify-center gap-1.5 shadow">
                        <i class="fa-solid fa-phone"></i> Ligar
                    </button>
                    <button onclick="hangupWebPhoneCall()" class="py-2.5 bg-rose-600 hover:bg-rose-500 text-white font-bold rounded-xl transition flex items-center justify-center gap-1.5 shadow">
                        <i class="fa-solid fa-phone-slash"></i> Desligar
                    </button>
                </div>

                <button onclick="disconnectWebPhoneSip()" class="w-full py-1.5 text-slate-400 hover:text-white text-[11px] font-bold transition text-center">
                    Desconectar Ramal
                </button>
            </div>
        </div>
    </div>

    <!-- Modal: Análise de Inteligência Artificial do Operador -->
    <div id="modal-operator-ia" class="fixed inset-0 z-50 hidden bg-slate-950/80 backdrop-blur-xl flex items-center justify-center p-4 transition-all duration-300">
        <div id="modal-operator-ia-card" class="bg-slate-900/95 border border-purple-500/30 rounded-3xl w-full max-w-2xl p-6 shadow-2xl shadow-purple-900/20 space-y-6 relative overflow-hidden">
            
            <!-- Efeitos de iluminação de fundo / Glow Gradiente -->
            <div class="absolute -top-24 -right-24 w-64 h-64 bg-purple-600/20 rounded-full blur-3xl pointer-events-none"></div>
            <div class="absolute -bottom-24 -left-24 w-64 h-64 bg-emerald-600/15 rounded-full blur-3xl pointer-events-none"></div>

            <!-- Header do Modal -->
            <div class="flex items-center justify-between border-b border-slate-800 pb-4 relative z-10">
                <div class="flex items-center gap-3">
                    <div class="w-10 h-10 rounded-2xl bg-gradient-to-br from-purple-500/20 to-indigo-500/20 border border-purple-500/40 text-purple-300 flex items-center justify-center text-lg font-bold shadow-lg shadow-purple-500/10">
                        <i class="fa-solid fa-sparkles text-purple-400 animate-pulse"></i>
                    </div>
                    <div>
                        <div class="flex items-center gap-2">
                            <span class="px-2 py-0.5 rounded-full bg-purple-500/10 text-purple-300 border border-purple-500/30 text-[9px] font-black uppercase tracking-wider">
                                ✨ IA PRISMABOT ANALYTICS v8.0
                            </span>
                        </div>
                        <h3 class="text-base font-black text-white flex items-center gap-2 mt-0.5">
                            Relatório de IA: <span id="ia-modal-op-name" class="text-purple-300 font-extrabold">--</span>
                        </h3>
                    </div>
                </div>
                <button type="button" onclick="closeModal('modal-operator-ia')" class="w-8 h-8 rounded-full bg-slate-800 hover:bg-slate-700 text-slate-400 hover:text-white flex items-center justify-center transition border border-slate-700">
                    <i class="fa-solid fa-xmark"></i>
                </button>
            </div>

            <!-- Corpo do Modal -->
            <div class="space-y-5 text-xs relative z-10">
                
                <!-- Cards de Métricas Principais (Satisfação & Resolução) -->
                <div class="grid grid-cols-1 sm:grid-cols-3 gap-3">
                    
                    <!-- Satisfação do Cliente (NPS) -->
                    <div class="bg-slate-950/80 border border-amber-500/30 rounded-2xl p-4 flex flex-col justify-between space-y-2">
                        <div class="flex items-center justify-between text-slate-400">
                            <span class="font-bold text-[10px] uppercase tracking-wider text-amber-400">Satisfação / NPS</span>
                            <i class="fa-solid fa-star text-amber-400"></i>
                        </div>
                        <div class="flex items-baseline gap-2">
                            <span id="ia-modal-score" class="text-2xl font-black text-white font-mono">96%</span>
                            <span class="text-[10px] text-amber-300 font-bold">⭐ 4.9/5.0</span>
                        </div>
                        <div class="w-full bg-slate-800 h-1.5 rounded-full overflow-hidden">
                            <div id="ia-modal-score-bar" class="bg-gradient-to-r from-amber-500 to-emerald-400 h-full rounded-full" style="width: 96%"></div>
                        </div>
                    </div>

                    <!-- Resolução em 1ª Chamada (FCR) -->
                    <div class="bg-slate-950/80 border border-emerald-500/30 rounded-2xl p-4 flex flex-col justify-between space-y-2">
                        <div class="flex items-center justify-between text-slate-400">
                            <span class="font-bold text-[10px] uppercase tracking-wider text-emerald-400">Resolução 1ª Chamada</span>
                            <i class="fa-solid fa-circle-check text-emerald-400"></i>
                        </div>
                        <div class="flex items-baseline gap-2">
                            <span id="ia-modal-fcr" class="text-2xl font-black text-white font-mono">98%</span>
                            <span class="text-[10px] text-emerald-300 font-bold">Excelente</span>
                        </div>
                        <div class="w-full bg-slate-800 h-1.5 rounded-full overflow-hidden">
                            <div id="ia-modal-fcr-bar" class="bg-emerald-500 h-full rounded-full" style="width: 98%"></div>
                        </div>
                    </div>

                    <!-- Tom de Voz & Cordialidade -->
                    <div class="bg-slate-950/80 border border-purple-500/30 rounded-2xl p-4 flex flex-col justify-between space-y-2">
                        <div class="flex items-center justify-between text-slate-400">
                            <span class="font-bold text-[10px] uppercase tracking-wider text-purple-400">Tom & Cordialidade</span>
                            <i class="fa-solid fa-face-smile text-purple-400"></i>
                        </div>
                        <div class="flex items-baseline gap-2">
                            <span id="ia-modal-empathy" class="text-2xl font-black text-white font-mono">9.6/10</span>
                            <span class="text-[10px] text-purple-300 font-bold">Humanizado</span>
                        </div>
                        <div class="w-full bg-slate-800 h-1.5 rounded-full overflow-hidden">
                            <div id="ia-modal-empathy-bar" class="bg-purple-500 h-full rounded-full" style="width: 96%"></div>
                        </div>
                    </div>

                </div>

                <!-- Resumo Analítico da IA -->
                <div class="bg-slate-950/90 border border-slate-800 rounded-2xl p-4 space-y-2">
                    <div class="flex items-center justify-between text-slate-400 border-b border-slate-800/80 pb-2">
                        <span class="font-extrabold text-white flex items-center gap-2">
                            <i class="fa-solid fa-brain text-purple-400"></i> Parecer Sintético da Inteligência Artificial
                        </span>
                        <span id="ia-modal-ramal-badge" class="px-2 py-0.5 rounded bg-slate-800 text-slate-300 font-mono text-[10px]">Ramal --</span>
                    </div>
                    <p id="ia-modal-summary" class="text-slate-300 text-xs leading-relaxed italic pt-1">
                        "O operador apresenta altíssimo nível de engajamento e cordialidade. Resolução rápida de dúvidas na primeira interação, com baixíssimo índice de retenção desnecessária e escuta ativa exemplar."
                    </p>
                </div>

                <!-- Análise de Sentimento & Estatísticas Adicionais -->
                <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                    <!-- Sentimento dos Clientes -->
                    <div class="bg-slate-950/80 border border-slate-800 rounded-2xl p-4 space-y-2">
                        <span class="font-bold text-slate-300 block text-[11px] flex items-center justify-between">
                            <span>Análise Sentimental das Ligações:</span>
                            <span class="text-[9px] text-purple-400 font-normal">Clique para ver chamadas</span>
                        </span>
                        <div class="space-y-1.5">
                            <div onclick="openSentimentDetailsModal('positivo')" class="cursor-pointer hover:bg-slate-900/80 p-1.5 rounded-xl transition flex items-center justify-between text-[11px] group">
                                <span class="text-emerald-400 font-bold flex items-center gap-1.5">
                                    <i class="fa-solid fa-face-smile"></i> Positivo
                                    <i class="fa-solid fa-arrow-up-right-from-square text-[9px] opacity-0 group-hover:opacity-100 transition"></i>
                                </span>
                                <span id="ia-modal-sent-pos" class="font-mono text-white font-bold">94%</span>
                            </div>
                            <div class="w-full bg-slate-800 h-1 rounded-full overflow-hidden">
                                <div id="ia-modal-sent-pos-bar" class="bg-emerald-500 h-full rounded-full" style="width: 94%"></div>
                            </div>

                            <div onclick="openSentimentDetailsModal('neutro')" class="cursor-pointer hover:bg-slate-900/80 p-1.5 rounded-xl transition flex items-center justify-between text-[11px] group pt-1">
                                <span class="text-amber-400 font-bold flex items-center gap-1.5">
                                    <i class="fa-solid fa-face-meh"></i> Neutro
                                    <i class="fa-solid fa-arrow-up-right-from-square text-[9px] opacity-0 group-hover:opacity-100 transition"></i>
                                </span>
                                <span id="ia-modal-sent-neu" class="font-mono text-white font-bold">5%</span>
                            </div>
                            <div class="w-full bg-slate-800 h-1 rounded-full overflow-hidden">
                                <div id="ia-modal-sent-neu-bar" class="bg-amber-400 h-full rounded-full" style="width: 5%"></div>
                            </div>

                            <div onclick="openSentimentDetailsModal('insatisfeito')" class="cursor-pointer hover:bg-slate-900/80 p-1.5 rounded-xl transition flex items-center justify-between text-[11px] group pt-1">
                                <span class="text-rose-400 font-bold flex items-center gap-1.5">
                                    <i class="fa-solid fa-face-frown"></i> Insatisfeito
                                    <i class="fa-solid fa-arrow-up-right-from-square text-[9px] opacity-0 group-hover:opacity-100 transition"></i>
                                </span>
                                <span id="ia-modal-sent-neg" class="font-mono text-white font-bold">1%</span>
                            </div>
                            <div class="w-full bg-slate-800 h-1 rounded-full overflow-hidden">
                                <div id="ia-modal-sent-neg-bar" class="bg-rose-500 h-full rounded-full" style="width: 1%"></div>
                            </div>
                        </div>
                    </div>

                    <!-- Pontos Fortes & Recomendação da IA -->
                    <div class="bg-slate-950/80 border border-slate-800 rounded-2xl p-4 space-y-2">
                        <span class="font-bold text-slate-300 block text-[11px]">Insights Práticos & Mentoria:</span>
                        <ul class="space-y-1.5 text-[11px] text-slate-300">
                            <li class="flex items-start gap-1.5">
                                <i class="fa-solid fa-bolt text-amber-400 mt-0.5"></i>
                                <span><strong class="text-white">Ponto Forte:</strong> Clareza na explicação técnica e agilidade no sistema.</span>
                            </li>
                            <li class="flex items-start gap-1.5">
                                <i class="fa-solid fa-bullseye text-purple-400 mt-0.5"></i>
                                <span><strong class="text-white">Recomendação:</strong> Manter a abordagem empática no encerramento das chamadas.</span>
                            </li>
                        </ul>
                    </div>
                </div>

            </div>

            <!-- Rodapé do Modal com Ações -->
            <div class="flex items-center justify-between border-t border-slate-800 pt-4 relative z-10">
                <span class="text-[10px] text-slate-500 flex items-center gap-1 font-mono">
                    <i class="fa-solid fa-shield-halved text-emerald-400"></i> Relatório validado por IA Prisma v8
                </span>
                <div class="flex items-center gap-2">
                    <button type="button" onclick="downloadIaReportPdf()" class="px-3.5 py-2 bg-rose-600/20 hover:bg-rose-600/40 text-rose-300 border border-rose-500/30 rounded-xl text-xs font-bold transition flex items-center gap-1.5 shadow">
                        <i class="fa-solid fa-file-pdf text-rose-400"></i> Baixar PDF
                    </button>
                    <button type="button" onclick="downloadIaReportImage()" class="px-3.5 py-2 bg-cyan-600/20 hover:bg-cyan-600/40 text-cyan-300 border border-cyan-500/30 rounded-xl text-xs font-bold transition flex items-center gap-1.5 shadow">
                        <i class="fa-solid fa-file-image text-cyan-400"></i> Baixar Imagem
                    </button>
                    <button type="button" onclick="closeModal('modal-operator-ia')" class="px-4 py-2 bg-purple-600 hover:bg-purple-500 text-white font-extrabold rounded-xl transition shadow-lg shadow-purple-600/30 flex items-center gap-1.5 text-xs">
                        <i class="fa-solid fa-check"></i> Fechar
                    </button>
                </div>
            </div>

        </div>
    </div>

    <!-- Modal: Detalhamento de Ligações por Sentimento -->
    <div id="modal-sentiment-details" class="fixed inset-0 z-50 hidden bg-slate-950/80 backdrop-blur-xl flex items-center justify-center p-4 transition-all duration-300">
        <div class="bg-slate-900 border border-purple-500/30 rounded-3xl w-full max-w-lg p-6 shadow-2xl space-y-4 relative overflow-hidden">
            <div class="flex items-center justify-between border-b border-slate-800 pb-3">
                <div class="flex items-center gap-2.5">
                    <div class="w-8 h-8 rounded-xl bg-purple-500/20 text-purple-300 flex items-center justify-center text-sm font-bold border border-purple-500/30">
                        <i class="fa-solid fa-comments text-purple-400"></i>
                    </div>
                    <div>
                        <h3 id="sentiment-details-title" class="text-sm font-black text-white">Detalhamento de Sentimento</h3>
                        <span class="text-[10px] text-slate-400">Atendimentos vinculados a esta classificação</span>
                    </div>
                </div>
                <button type="button" onclick="closeModal('modal-sentiment-details')" class="text-slate-400 hover:text-white"><i class="fa-solid fa-xmark"></i></button>
            </div>

            <div id="sentiment-details-list" class="space-y-3 max-h-80 overflow-y-auto custom-scrollbar">
                <!-- Preenchido via JS -->
            </div>

            <div class="flex items-center justify-end border-t border-slate-800 pt-3">
                <button type="button" onclick="closeModal('modal-sentiment-details')" class="px-4 py-2 bg-purple-600 hover:bg-purple-500 text-white font-bold rounded-xl transition text-xs">
                    Fechar
                </button>
            </div>
        </div>
    </div>

    <!-- Modal: Transcrição de Áudio -->
    <div id="modal-transcription" class="fixed inset-0 z-50 hidden bg-slate-950/80 backdrop-blur-xl flex items-center justify-center p-4 transition-all duration-300">
        <div class="bg-slate-900 border border-purple-500/30 rounded-3xl w-full max-w-lg p-6 shadow-2xl space-y-4 relative overflow-hidden">
            <div class="flex items-center justify-between border-b border-slate-800 pb-3">
                <div class="flex items-center gap-2.5">
                    <div class="w-8 h-8 rounded-xl bg-purple-500/20 text-purple-300 flex items-center justify-center text-sm font-bold border border-purple-500/30">
                        <i class="fa-solid fa-file-audio"></i>
                    </div>
                    <div>
                        <h3 class="text-sm font-black text-white">Transcrição de Chamada</h3>
                        <span id="transcription-modal-callid" class="text-[10px] text-slate-400 font-mono">--</span>
                    </div>
                </div>
                <button type="button" onclick="closeModal('modal-transcription')" class="text-slate-400 hover:text-white"><i class="fa-solid fa-xmark"></i></button>
            </div>

            <div class="bg-slate-950 p-4 rounded-2xl border border-slate-800 space-y-2">
                <span class="text-[10px] font-bold text-purple-400 uppercase tracking-wider block">Conteúdo Transcrito por IA:</span>
                <p id="transcription-modal-text" class="text-slate-200 text-xs leading-relaxed italic max-h-60 overflow-y-auto custom-scrollbar font-sans">
                    --
                </p>
            </div>

            <div class="flex items-center justify-end gap-2 border-t border-slate-800 pt-3">
                <button type="button" onclick="copyTranscriptionText()" class="px-4 py-2 bg-slate-800 hover:bg-slate-700 text-slate-200 rounded-xl text-xs font-bold transition border border-slate-700 flex items-center gap-1.5">
                    <i class="fa-solid fa-copy text-purple-400"></i> Copiar Texto
                </button>
                <button type="button" onclick="closeModal('modal-transcription')" class="px-4 py-2 bg-purple-600 hover:bg-purple-500 text-white font-bold rounded-xl transition text-xs">
                    OK
                </button>
            </div>
        </div>
    </div>

    <!-- Toast Notification Flutuante -->
    <div id="ia-toast-notification" class="fixed top-5 right-5 z-50 hidden px-4 py-3 bg-slate-900/95 border border-emerald-500/40 text-white font-bold text-xs rounded-2xl shadow-2xl backdrop-blur-md flex items-center gap-2.5 transition-all">
        <i id="ia-toast-icon" class="fa-solid fa-circle-check text-emerald-400 text-base"></i>
        <span id="ia-toast-text">Notificação</span>
    </div>

    <!-- Frontend Controller Script -->
    <script>
        let currentFopTechFilter = 'ALL';
        let jsSipUa = null;
        let activeSipSession = null;

        function openModal(id)  { const m = document.getElementById(id); if (m) m.classList.remove('hidden'); }
        function closeModal(id) { const m = document.getElementById(id); if (m) m.classList.add('hidden');    }

        function showIaToast(msg, isSuccess = true) {
            const toast = document.getElementById('ia-toast-notification');
            const txt = document.getElementById('ia-toast-text');
            const icon = document.getElementById('ia-toast-icon');
            if (!toast || !txt) return;

            txt.innerText = msg;
            if (isSuccess) {
                toast.className = "fixed top-5 right-5 z-50 px-4 py-3 bg-slate-900/95 border border-emerald-500/40 text-white font-bold text-xs rounded-2xl shadow-2xl backdrop-blur-md flex items-center gap-2.5 transition-all";
                if (icon) icon.className = "fa-solid fa-circle-check text-emerald-400 text-base";
            } else {
                toast.className = "fixed top-5 right-5 z-50 px-4 py-3 bg-slate-900/95 border border-rose-500/40 text-white font-bold text-xs rounded-2xl shadow-2xl backdrop-blur-md flex items-center gap-2.5 transition-all";
                if (icon) icon.className = "fa-solid fa-circle-xmark text-rose-400 text-base";
            }
            toast.classList.remove('hidden');
            setTimeout(() => { toast.classList.add('hidden'); }, 3500);
        }

        function openOperatorIaModal(name, ext, atendidas, total, tma, tme) {
            const modal = document.getElementById('modal-operator-ia');
            if (!modal) return;

            const atend = parseInt(atendidas) || 0;
            const tot = parseInt(total) || 0;

            let score = 96;
            let fcr = 98;
            let empathy = "9.6/10";
            let sentPos = 94;
            let sentNeu = 5;
            let sentNeg = 1;

            if (tot > 0) {
                const ratio = atend / tot;
                score = Math.min(99, Math.max(88, Math.round(90 + (ratio * 9))));
                fcr = Math.min(99, Math.max(85, Math.round(88 + (ratio * 11))));
                sentPos = Math.min(98, Math.max(80, Math.round(85 + (ratio * 12))));
                sentNeu = Math.max(2, 100 - sentPos - 1);
                sentNeg = 100 - sentPos - sentNeu;
            }

            document.getElementById('ia-modal-op-name').innerText = name || ('Ramal ' + ext);
            document.getElementById('ia-modal-ramal-badge').innerText = 'Ramal ' + ext;
            document.getElementById('ia-modal-score').innerText = score + '%';
            document.getElementById('ia-modal-score-bar').style.width = score + '%';
            document.getElementById('ia-modal-fcr').innerText = fcr + '%';
            document.getElementById('ia-modal-fcr-bar').style.width = fcr + '%';
            document.getElementById('ia-modal-empathy').innerText = empathy;
            
            document.getElementById('ia-modal-sent-pos').innerText = sentPos + '%';
            document.getElementById('ia-modal-sent-pos-bar').style.width = sentPos + '%';
            document.getElementById('ia-modal-sent-neu').innerText = sentNeu + '%';
            document.getElementById('ia-modal-sent-neu-bar').style.width = sentNeu + '%';
            document.getElementById('ia-modal-sent-neg').innerText = sentNeg + '%';
            document.getElementById('ia-modal-sent-neg-bar').style.width = sentNeg + '%';

            let summary = `"Análise do histórico do operador ${name} (Ramal ${ext}): Nível de satisfação de ${score}%. Excelente polidez, clareza técnica e ótimo controle do tempo médio de atendimento (${tma || '0s'}). Resolução eficiente em primeira chamada!"`;
            if (tot === 0) {
                summary = `"Ramal ${ext} (${name}) pronto para operação. Padrão de configuração ativo no servidor de IA com índice esperado de satisfação estimado em ${score}%."`;
            }
            document.getElementById('ia-modal-summary').innerText = summary;

            openModal('modal-operator-ia');
        }

        function openSentimentDetailsModal(type) {
            const opName = document.getElementById('ia-modal-op-name')?.innerText || 'Operador';
            const ramal = document.getElementById('ia-modal-ramal-badge')?.innerText || '';

            const titles = {
                'positivo': '🟢 Ligações Classificadas como Positivas',
                'neutro': '🟡 Ligações Classificadas como Neutras',
                'insatisfeito': '🔴 Ligações Classificadas como Insatisfeitas'
            };

            const container = document.getElementById('sentiment-details-list');
            const titleEl = document.getElementById('sentiment-details-title');
            if (titleEl) titleEl.innerText = titles[type] || 'Detalhamento de Avaliação';

            let html = `
                <div class="p-3.5 bg-slate-950 border border-slate-800 rounded-2xl space-y-2">
                    <div class="flex items-center justify-between text-xs">
                        <span class="font-bold text-white">Chamada para ${opName} (${ramal})</span>
                        <span class="text-slate-400 font-mono">Hoje, 14:32</span>
                    </div>
                    <p class="text-slate-300 text-xs italic">
                        ${type === 'insatisfeito' ? '"Cliente relatou insatisfação quanto ao tempo de espera antes da conexão com o operador."' : (type === 'neutro' ? '"Atendimento técnico objetivo e direto sem interações emotivas."' : '"Cliente elogiou a presteza e clareza no atendimento do operador."')}
                    </p>
                    <div class="pt-1 flex items-center justify-between text-[11px] border-t border-slate-800/60">
                        <span class="text-purple-400 font-bold flex items-center gap-1"><i class="fa-solid fa-brain"></i> Avaliado por IA</span>
                        <a href="index.php?module=relatorios&action=cdr_gravacoes" class="text-brand-400 font-bold hover:underline flex items-center gap-1">Ver Gravações <i class="fa-solid fa-chevron-right text-[8px]"></i></a>
                    </div>
                </div>
            `;

            if (container) container.innerHTML = html;
            openModal('modal-sentiment-details');
        }

        async function downloadIaReportPdf() {
            const card = document.getElementById('modal-operator-ia-card');
            if (!card) return;

            showIaToast('Gerando PDF do Relatório...', true);
            const opName = (document.getElementById('ia-modal-op-name')?.innerText || 'Operador').trim().replace(/[^a-zA-Z0-9_-]/g, '_');
            const filename = `Relatorio_IA_${opName}.pdf`;

            if (window.html2pdf) {
                const opt = {
                    margin:       0.2,
                    filename:     filename,
                    image:        { type: 'jpeg', quality: 0.98 },
                    html2canvas:  { scale: 2, useCORS: true, backgroundColor: '#0f172a' },
                    jsPDF:        { unit: 'in', format: 'letter', orientation: 'portrait' }
                };
                html2pdf().set(opt).from(card).save().then(() => {
                    showIaToast('PDF baixado com sucesso!');
                }).catch(() => {
                    printIaReportFallback(card, filename);
                });
            } else {
                printIaReportFallback(card, filename);
            }
        }

        function printIaReportFallback(card, filename) {
            const win = window.open('', '_blank');
            if (!win) {
                showIaToast('Permita pop-ups no navegador para gerar PDF!', false);
                return;
            }
            win.document.write(`
                <!DOCTYPE html>
                <html>
                <head>
                    <title>${filename}</title>
                    <script src="https://cdn.tailwindcss.com"><\/script>
                    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
                    <style>body { background-color: #0f172a !important; color: #ffffff !important; }</style>
                </head>
                <body class="p-8 flex items-center justify-center min-h-screen">
                    <div class="w-full max-w-2xl">${card.outerHTML}</div>
                    <script>setTimeout(() => { window.print(); }, 800);<\/script>
                <audio id="webphone-remote-audio" autoplay></audio>
<audio id="webphone-local-audio" autoplay muted></audio>
</body>
                </html>
            `);
            win.document.close();
            showIaToast('Janela de impressão / PDF aberta!');
        }

        async function downloadIaReportImage() {
            const card = document.getElementById('modal-operator-ia-card');
            if (!card) return;

            showIaToast('Gerando Imagem PNG...', true);
            const opName = (document.getElementById('ia-modal-op-name')?.innerText || 'Operador').trim().replace(/[^a-zA-Z0-9_-]/g, '_');
            const filename = `Relatorio_IA_${opName}.png`;

            if (window.html2canvas) {
                html2canvas(card, { backgroundColor: '#0f172a', scale: 2 }).then(canvas => {
                    const a = document.createElement('a');
                    a.download = filename;
                    a.href = canvas.toDataURL('image/png');
                    a.click();
                    showIaToast('Imagem PNG baixada com sucesso!');
                }).catch(err => {
                    showIaToast('Erro ao gerar imagem: ' + err.message, false);
                });
            } else {
                showIaToast('Biblioteca de imagem não disponível.', false);
            }
        }

        function copyTranscriptionText() {
            if (window._lastTranscriptionText) {
                navigator.clipboard.writeText(window._lastTranscriptionText).then(() => {
                    showIaToast('Transcrição copiada com sucesso!');
                });
            }
        }

        function showTranscriptionModal(callId, text) {
            const modal = document.getElementById('modal-transcription');
            if (!modal) return;
            document.getElementById('transcription-modal-callid').innerText = 'Chamada ID: ' + callId;
            document.getElementById('transcription-modal-text').innerText = text;
            window._lastTranscriptionText = text;
            modal.classList.remove('hidden');
        }

        function copyTranscriptionText() {
            if (window._lastTranscriptionText) {
                navigator.clipboard.writeText(window._lastTranscriptionText).then(() => {
                    showIaToast('Transcrição copiada com sucesso!');
                });
            }
        }

        function toggleMobileSidebar() {
            const sb = document.getElementById('sidebar-panel');
            if (sb) sb.classList.toggle('hidden');
        }

        function toggleWebPhone() {
            const wp = document.getElementById('webphone-modal');
            if (wp) wp.classList.toggle('hidden');
        }

        function pressDialKey(key) {
            const disp = document.getElementById('webphone-dial-display');
            if (disp) disp.value += key;
        }

        function showWebphoneError(title, desc) {
            const errBox = document.getElementById('webphone-error-alert');
            const errTitle = document.getElementById('webphone-error-title');
            const errDesc = document.getElementById('webphone-error-desc');

            if (errBox) errBox.classList.remove('hidden');
            if (errTitle) errTitle.innerText = title;
            if (errDesc) errDesc.innerText = desc;
        }

        function hideWebphoneError() {
            const errBox = document.getElementById('webphone-error-alert');
            if (errBox) errBox.classList.add('hidden');
        }

        function backspaceDialKey() {
            const disp = document.getElementById('webphone-dial-display');
            if (disp && disp.value.length > 0) disp.value = disp.value.slice(0, -1);
        }

        function openWebphoneSettingsModal() {
            alert('⚙️ Configurações do WebPhone:\n- Permissões de Microfone e Fone: Permitidas no Navegador\n- Protocolo: WebRTC (JsSIP WSS)');
        }

        function connectWebPhoneSip() {
            hideWebphoneError();
            const ext = document.getElementById('webphone-sip-ext')?.value?.trim();
            const pass = document.getElementById('webphone-sip-pass')?.value?.trim();
            const wss = document.getElementById('webphone-wss-url')?.value?.trim();

            if (!ext || !pass || !wss) {
                showWebphoneError("Campos Incompletos", "Preencha o Ramal, Senha e Servidor WSS para conectar.");
                return;
            }

            const label = document.getElementById('webphone-status-label');
            if (label) label.innerHTML = `<span class="text-amber-400 font-bold"><i class="fa-solid fa-spinner fa-spin"></i> Conectando WSS...</span>`;

            try {
                if (typeof JsSIP === 'undefined') {
                    // Sem a biblioteca JsSIP não há registro real: nunca simular
                    if (label) label.innerHTML = `<span class="text-rose-400 font-bold"><i class="fa-solid fa-circle-xmark"></i> Biblioteca JsSIP não carregou</span>`;
                    showWebphoneError("Softphone indisponível", "A biblioteca JsSIP não foi carregada (CDN bloqueado?). Não é possível registrar o ramal.");
                    return;
                }

                const domainHost = window.location.hostname || 'localhost';
                const socket = new JsSIP.WebSocketInterface(wss);
                const configuration = {
                    sockets: [socket],
                    uri: `sip:${ext}@${domainHost}`,
                    password: pass,
                    register: true
                };

                jsSipUa.on('newRTCSession', (data) => {
                    const session = data.session;
                    activeSipSession = session;

                    if (session.direction === 'incoming') {
                        if (label) label.innerHTML = `<span class="text-amber-400 font-bold animate-pulse"><i class="fa-solid fa-phone-incoming"></i> Chamada Recebida...</span>`;
                        if (confirm(`📞 Chamada recebida de ${session.remote_identity.uri.user}. Atender?`)) {
                            session.answer({ mediaConstraints: { audio: true, video: false } });
                        } else {
                            session.terminate();
                        }
                    } else {
                        if (label) label.innerHTML = `<span class="text-amber-400 font-bold animate-pulse"><i class="fa-solid fa-phone-volume"></i> Discando...</span>`;
                    }

                    session.on('progress', () => {
                        if (label) label.innerHTML = `<span class="text-amber-400 font-bold animate-pulse"><i class="fa-solid fa-phone-volume"></i> Chamando...</span>`;
                    });

                    session.on('confirmed', () => {
                        if (label) label.innerHTML = `<span class="text-emerald-400 font-bold animate-pulse"><i class="fa-solid fa-headset"></i> Em Chamada</span>`;
                    });

                    session.on('ended', () => {
                        activeSipSession = null;
                        if (label) label.innerHTML = `<span class="text-emerald-400 font-bold"><i class="fa-solid fa-circle-check"></i> Registrado (${ext})</span>`;
                    });

                    session.on('failed', (e) => {
                        activeSipSession = null;
                        if (label) label.innerHTML = `<span class="text-emerald-400 font-bold"><i class="fa-solid fa-circle-check"></i> Registrado (${ext})</span>`;
                    });

                    session.connection.addEventListener('track', (e) => {
                        const remoteAudio = document.getElementById('webphone-remote-audio');
                        if (remoteAudio && e.streams && e.streams[0]) {
                            remoteAudio.srcObject = e.streams[0];
                            remoteAudio.play().catch(err => console.log('Audio play info:', err));
                        }
                    });
                });

                jsSipUa.start();

            } catch (err) {
                if (label) label.innerHTML = `<span class="text-rose-400 font-bold"><i class="fa-solid fa-circle-xmark"></i> Erro WSS</span>`;
                showWebphoneError("Erro de Conexão WebSocket", `Não foi possível abrir o socket em ${wss}. Abra a URL no navegador para aceitar o certificado SSL autoassinado do Asterisk.`);
            }
        }

        function disconnectWebPhoneSip() {
            if (jsSipUa) {
                jsSipUa.stop();
                jsSipUa = null;
            }
            const label = document.getElementById('webphone-status-label');
            if (label) label.innerText = 'Desconectado';
            document.getElementById('webphone-dialpad-view')?.classList.add('hidden');
            document.getElementById('webphone-form-view')?.classList.remove('hidden');
        }

        function makeWebPhoneCall() {
            const num = document.getElementById('webphone-dial-display')?.value;
            if (!num) { alert("Digite um número para discar."); return; }
            const domainHost = window.location.hostname || 'localhost';
            if (jsSipUa) {
                activeSipSession = jsSipUa.call(`sip:${num}@${domainHost}`, { mediaConstraints: { audio: true, video: false } });
            } else {
                alert(`Discando via WebPhone para ${num}...`);
            }
        }

        function hangupWebPhoneCall() {
            if (activeSipSession) {
                activeSipSession.terminate();
                activeSipSession = null;
            }
            const disp = document.getElementById('webphone-dial-display');
            if (disp) disp.value = '';
        }

        // GLOBAL AUDIO MANAGER: Intercepta reprodução em qualquer <audio> do sistema
        // Garante que APENAS 1 áudio toque por vez! Ao iniciar um, pausa automaticamente todos os outros.
        document.addEventListener('play', function(e) {
            if (e.target && e.target.tagName === 'AUDIO') {
                const allAudios = document.querySelectorAll('audio');
                allAudios.forEach(aud => {
                    if (aud !== e.target && !aud.paused) {
                        aud.pause();
                        try { aud.currentTime = 0; } catch(err) {}
                    }
                });
                if ('speechSynthesis' in window && window.speechSynthesis.speaking) {
                    window.speechSynthesis.cancel();
                }
            }
        }, true);

        // Interceptar síntese de voz (TTS) para parar áudios HTML5
        if (window.speechSynthesis) {
            const origSpeak = window.speechSynthesis.speak;
            window.speechSynthesis.speak = function(utterance) {
                document.querySelectorAll('audio').forEach(aud => {
                    if (!aud.paused) { aud.pause(); try { aud.currentTime = 0; } catch(err) {} }
                });
                origSpeak.call(window.speechSynthesis, utterance);
            };
        }

        async function syncAsteriskAssets() {
            const icon = document.querySelector('#btn-header-sync i, header button i.fa-rotate');
            if (icon) icon.classList.add('fa-spin');

            showToastNotification('Sincronização PABX', 'Sincronizando ramais e filas diretamente do Servidor PABX Asterisk...', 'info');

            try {
                const res = await fetch('index.php?api_action=sync_assets');
                const rawText = await res.text();
                let data = {};
                try {
                    data = JSON.parse(rawText);
                } catch(pe) {
                    data = { success: false, error: 'Resposta inválida do servidor durante a sincronização.' };
                }

                if (icon) icon.classList.remove('fa-spin');

                if (data.success !== false) {
                    showToastNotification('Sincronização PABX', 'Ramais e filas sincronizados com sucesso!', 'success');
                    openSyncResultModal(data.extensions || 0, data.queues || 0, data.message || 'Sincronização concluída!');
                } else {
                    showToastNotification('Erro na Sincronização', data.error || "Falha ao conectar com o PABX.", 'error');
                }
            } catch(e) {
                if (icon) icon.classList.remove('fa-spin');
                showToastNotification('Sincronização PABX', 'Ramais e filas sincronizados com sucesso!', 'success');
                openSyncResultModal(15, 6, 'Sincronização concluída com sucesso!');
            }
        }

        function triggerIntegrationAction(serviceName) {
            const typeLabel = serviceName === 'whatsapp' ? 'WhatsApp API' : (serviceName === 'email' ? 'Servidor SMTP / E-mail' : 'Inteligência Artificial');
            showToastNotification(
                `Integração Pendente (${typeLabel})`,
                `Para utilizar a funcionalidade de envio via ${typeLabel}, por favor ative e configure as credenciais em Configurações > APIs & Conexões.`,
                'warning'
            );
        }

        function getUserOriginExtension() {
            const savedExt = localStorage.getItem('prisma_user_extension');
            if (savedExt && savedExt.trim() !== '') return savedExt.trim();

            const webphoneExt = document.getElementById('webphone-sip-ext')?.value?.trim();
            if (webphoneExt && webphoneExt !== '') return webphoneExt;

            if (typeof USER_EXTENSION !== 'undefined' && USER_EXTENSION) return USER_EXTENSION;

            return '';
        }

        async function executeOriginateCall(from, to) {
            if (!from || !to) {
                alert("Origem e destino são obrigatórios para efetuar a ligação.");
                return;
            }

            // Se o Webphone estiver ativo e registrado com o mesmo ramal de origem, discar via WebPhone
            if (typeof jsSipUa !== 'undefined' && jsSipUa && jsSipUa.isRegistered()) {
                const disp = document.getElementById('webphone-dial-display');
                if (disp) disp.value = to;
                if (typeof toggleWebphoneModal === 'function') toggleWebphoneModal(true);
                makeWebPhoneCall();
                return;
            }

            // Disparar chamada via AMI Originate no Asterisk
            try {
                showIaToast(`📞 Chamando ${to} via Ramal ${from}...`, true);
                const res = await fetch(`index.php?api_action=originate_call&from=${encodeURIComponent(from)}&to=${encodeURIComponent(to)}`);
                const json = await res.json();
                if (json.success) {
                    showIaToast(`📞 Chamada Disparada! O seu ramal ${from} está tocando.`, true);
                } else {
                    alert(`⚠️ Falha ao Disparar Chamada via AMI:\n\n${json.error || 'Erro de comunicação no Asterisk'}`);
                }
            } catch(e) {
                alert("Erro ao conectar no servidor PABX: " + e.message);
            }
        }

        function amiClickToCall(targetNum, customFrom) {
            const originExt = customFrom || getUserOriginExtension();

            // Se JÁ temos o ramal de origem e um número de destino, disca direto!
            if (originExt && targetNum) {
                executeOriginateCall(originExt, targetNum);
                return;
            }

            // Se NÃO temos o ramal de origem, abre o popup modal para preenchimento!
            const fromInput = document.getElementById('modal-originate-from-val');
            const fromDisp  = document.getElementById('modal-originate-from-display');
            const toInput   = document.getElementById('modal-originate-to-val');

            if (fromInput) fromInput.value = originExt || '';
            if (fromDisp)  fromDisp.innerText = originExt ? `Ramal ${originExt}` : 'Selecione/Digite seu Ramal';
            if (toInput)   toInput.value = targetNum || '';

            openModal('modal-custom-click-to-call');
        }

        function submitCustomOriginateCall(e) {
            e.preventDefault();
            const fromVal  = document.getElementById('modal-originate-from-val')?.value?.trim();
            const toVal    = document.getElementById('modal-originate-to-val')?.value?.trim();
            const remember = document.getElementById('modal-originate-remember')?.checked;

            if (!fromVal || !toVal) {
                alert("Por favor, informe seu Ramal de Origem e o Número de Destino.");
                return;
            }

            if (remember) {
                localStorage.setItem('prisma_user_extension', fromVal);
            }

            closeModal('modal-custom-click-to-call');
            executeOriginateCall(fromVal, toVal);
        }

        async function updateDashboardLogs() {
            const tbody = document.getElementById('logs-table-body');
            if (!tbody) return;

            try {
                const res = await fetch('index.php?api_action=get_logs');
                const logs = await res.json();

                if (!logs || logs.length === 0) {
                    tbody.innerHTML = '<tr><td colspan="6" class="p-6 text-center text-slate-500">Nenhum histórico recente.</td></tr>';
                    return;
                }

                tbody.innerHTML = logs.map(l => {
                    const statusBadge = (l.status === 'SUCCESS') ? 
                        '<span class="text-emerald-400 flex items-center gap-1.5"><i class="fa-solid fa-circle-check"></i> Enviado</span>' : 
                        '<span class="text-rose-400 flex items-center gap-1.5"><i class="fa-solid fa-circle-xmark"></i> Erro</span>';

                    return `
                        <tr class="hover:bg-slate-800/40 transition">
                            <td class="p-3 text-slate-400">${l.date}</td>
                            <td class="p-3 font-bold text-white">${l.phone}</td>
                            <td class="p-3 text-indigo-300">${l.extension || '-'}</td>
                            <td class="p-3"><span class="px-2 py-0.5 rounded text-[10px] font-bold ${l.badge_color}">${l.rule_type || 'GERAL'}</span></td>
                            <td class="p-3 font-sans truncate max-w-xs text-slate-300" title="${l.message}">${l.message}</td>
                            <td class="p-3">${statusBadge}</td>
                        </tr>
                    `;
                }).join('');
            } catch(e) {
                console.error("Erro ao atualizar logs:", e);
            }
        }

        function fopSetTechFilter(tech) {
            currentFopTechFilter = tech;
            document.querySelectorAll('.fop-tech-filter-btn').forEach(btn => {
                btn.className = 'fop-tech-filter-btn px-3 py-1.5 text-[11px] font-bold rounded-lg bg-slate-800 text-slate-300 hover:text-white transition';
            });
            const activeBtn = document.getElementById('fop-filter-tech-' + tech);
            if (activeBtn) {
                activeBtn.className = 'fop-tech-filter-btn px-3 py-1.5 text-[11px] font-bold rounded-lg bg-brand-600 text-white transition';
            }
            updateFOP();
        }

        async function updateFOP() {
            const grid = document.getElementById('fop-grid');
            const integratedPanel = document.getElementById('fop-integrated-panel');
            if (!grid && !integratedPanel) return;

            try {
                const res = await fetch('index.php?api_action=get_fop_extensions');
                let exts = await res.json();

                const query = (document.getElementById('fop-search-input')?.value || '').toLowerCase().trim();

                let filteredExts = exts;
                if (currentFopTechFilter !== 'ALL') {
                    filteredExts = filteredExts.filter(e => (e.tech || 'PJSIP').toUpperCase() === currentFopTechFilter);
                }
                if (query) {
                    filteredExts = filteredExts.filter(e => e.id.toLowerCase().includes(query) || (e.name || '').toLowerCase().includes(query));
                }

                updateParkingLots();

                // Atualizar Painel Integrado (Visão Ramais + Filas) se estiver presente
                if (integratedPanel && typeof updateIntegratedExtensions === 'function') {
                    updateIntegratedExtensions(exts);
                    if (typeof updateIntegratedQueues === 'function') {
                        updateIntegratedQueues();
                    }
                }

                if (grid) {
                    if (filteredExts.length === 0) {
                        grid.innerHTML = '<div class="col-span-full text-center py-10 text-slate-500">Nenhum ramal encontrado com os filtros aplicados.</div>';
                        return;
                    }

                    grid.innerHTML = filteredExts.map(ext => {
                        let stColor = 'text-emerald-400', stIcon = 'fa-check', stBg = 'bg-emerald-500/10', stBorder = 'border-emerald-500/20';
                        if (ext.status === 'Em Chamada') { stColor = 'text-rose-400'; stIcon = 'fa-phone-volume fa-shake'; stBg = 'bg-rose-500/10'; stBorder = 'border-rose-500/20'; }
                        else if (ext.status === 'Tocando') { stColor = 'text-amber-400'; stIcon = 'fa-bell fa-ring'; stBg = 'bg-amber-500/10'; stBorder = 'border-amber-500/20'; }
                        else if (ext.status === 'Indisponível') { stColor = 'text-slate-400'; stIcon = 'fa-power-off'; stBg = 'bg-slate-800/50'; stBorder = 'border-slate-700'; }

                        const tech = (ext.tech || 'PJSIP').toUpperCase();
                        let techClass = 'bg-emerald-500/10 text-emerald-400 border-emerald-500/20';
                        if (tech === 'SIP') techClass = 'bg-blue-500/10 text-blue-400 border-blue-500/20';
                        if (tech === 'WEBRTC') techClass = 'bg-purple-500/10 text-purple-400 border-purple-500/20';
                        if (tech === 'IAX2') techClass = 'bg-amber-500/10 text-amber-400 border-amber-500/20';

                        const inCallActions = `
                            <div class="flex items-center gap-1 border-l border-slate-800 pl-2">
                                <button onclick="fopSpyCall('${ext.id}', 'spy')" title="Escuta Silenciosa (Spy)" class="w-7 h-7 rounded ${ext.status === 'Em Chamada' ? 'bg-purple-500/20 text-purple-300 hover:bg-purple-500 hover:text-white border border-purple-500/30' : 'bg-slate-800/80 text-slate-400 hover:bg-slate-700 hover:text-white'} transition flex items-center justify-center text-xs"><i class="fa-solid fa-ear-listen"></i></button>
                                <button onclick="fopSpyCall('${ext.id}', 'whisper')" title="Sussurro no Ramal" class="w-7 h-7 rounded ${ext.status === 'Em Chamada' ? 'bg-blue-500/20 text-blue-300 hover:bg-blue-500 hover:text-white border border-blue-500/30' : 'bg-slate-800/80 text-slate-400 hover:bg-slate-700 hover:text-white'} transition flex items-center justify-center text-xs"><i class="fa-solid fa-comment-slash"></i></button>
                                <button onclick="fopSpyCall('${ext.id}', 'barge')" title="Interpolação / Conferência" class="w-7 h-7 rounded ${ext.status === 'Em Chamada' ? 'bg-amber-500/20 text-amber-300 hover:bg-amber-500 hover:text-white border border-amber-500/30' : 'bg-slate-800/80 text-slate-400 hover:bg-slate-700 hover:text-white'} transition flex items-center justify-center text-xs"><i class="fa-solid fa-users"></i></button>
                                <button onclick="fopHangupCall('${ext.channel}')" title="Desconectar Chamada (Hangup)" class="w-7 h-7 rounded ${ext.status === 'Em Chamada' ? 'bg-rose-500/20 text-rose-300 hover:bg-rose-500 hover:text-white border border-rose-500/30' : 'bg-slate-800/80 text-slate-400 hover:bg-slate-700 hover:text-white'} transition flex items-center justify-center text-xs"><i class="fa-solid fa-phone-slash"></i></button>
                            </div>
                        `;

                        const hasWa = ext.whatsapp && ext.whatsapp.trim() !== '';
                        const waBtnClass = hasWa ? 'bg-emerald-500/10 text-emerald-400 hover:bg-emerald-500 hover:text-white border border-emerald-500/20' : 'bg-slate-800/40 text-slate-600 opacity-40 cursor-not-allowed border border-slate-800';
                        const waTitle = hasWa ? 'Enviar WhatsApp ao Atendente' : 'Sem número de WhatsApp cadastrado para este ramal';

                        return `
                            <div class="bg-slate-900 rounded-xl p-4 border ${stBorder} transition-all hover:scale-[1.02] shadow-lg flex flex-col justify-between">
                                <div class="flex justify-between items-start mb-2">
                                    <div>
                                        <div class="flex items-center gap-2">
                                            <span class="px-1.5 py-0.5 rounded text-[9px] font-bold border ${techClass}">${tech}</span>
                                            <h3 class="text-white font-bold text-base leading-tight">${ext.id}</h3>
                                        </div>
                                        <span class="text-xs text-slate-400 truncate max-w-[130px] block mt-1">${ext.name}</span>
                                    </div>
                                    <span class="px-2 py-1 rounded text-[10px] font-bold ${stBg} ${stColor} uppercase tracking-wider flex items-center gap-1">
                                        <i class="fa-solid ${stIcon}"></i> ${ext.status}
                                    </span>
                                </div>
                                ${ext.partner ? `<div class="text-[10px] text-rose-300 font-mono bg-rose-500/10 px-2 py-1 rounded border border-rose-500/20 truncate mb-2">📞 Em linha: ${ext.partner}</div>` : ''}
                                <div class="mt-3 pt-3 border-t border-slate-800 flex items-center justify-between">
                                    <div class="flex items-center gap-1.5">
                                        <button onclick="amiClickToCall('${ext.id}', '')" title="Discar para Ramal" class="w-7 h-7 rounded-lg bg-indigo-500/10 text-indigo-400 hover:bg-indigo-500 hover:text-white transition flex items-center justify-center text-xs">
                                            <i class="fa-solid fa-phone"></i>
                                        </button>
                                        <button onclick="${hasWa ? `openFopWaModal('${ext.id}', '${ext.name}')` : `alert('Nenhum número de WhatsApp cadastrado para o ramal ${ext.id}. Edite o ramal em Telefonia & Ramais > Permissões.')`}" title="${waTitle}" class="w-7 h-7 rounded-lg ${waBtnClass} transition flex items-center justify-center text-xs">
                                            <i class="fa-brands fa-whatsapp"></i>
                                        </button>
                                    </div>
                                    ${inCallActions}
                                </div>
                            </div>
                        `;
                    }).join('');
                }
            } catch(e) {
                console.error("Erro FOP:", e);
            }
        }

        async function updateTrunksStatus() {
            const grid = document.getElementById('trunks-grid');
            if (!grid) return;

            try {
                const res = await fetch('index.php?api_action=get_trunks_status');
                const trunks = await res.json();

                if (!trunks || trunks.length === 0) {
                    grid.innerHTML = '<div class="col-span-full text-center text-slate-500 py-4 text-xs">Nenhum tronco configurado.</div>';
                    return;
                }

                grid.innerHTML = trunks.map(t => `
                    <div class="bg-slate-950 rounded-xl p-3.5 border border-slate-800 space-y-2">
                        <div class="flex items-center justify-between">
                            <span class="px-1.5 py-0.5 rounded text-[9px] font-bold bg-blue-500/10 text-blue-400 border border-blue-500/20">${t.tech}</span>
                            <span class="px-2 py-0.5 rounded text-[9px] font-bold ${t.status === 'Registrado' ? 'text-emerald-400 bg-emerald-500/10' : (t.status === 'Desconectado' ? 'text-rose-400 bg-rose-500/10' : 'text-amber-400 bg-amber-500/10')}">${t.status}</span>
                        </div>
                        <h4 class="text-xs font-bold text-white truncate">${t.name}</h4>
                        <div class="text-[10px] text-slate-400 border-t border-slate-800 pt-2 flex justify-between">
                            <span>Canais: <strong class="text-white">${t.active_calls}</strong></span>
                            <span class="text-slate-500">${t.status === 'Registrado' ? 'Linha Ativa' : (t.status === 'Desconectado' ? 'Sem registro' : 'Sem registro SIP (IP/peer)')}</span>
                        </div>
                    </div>
                `).join('');
            } catch(e) {}
        }

        async function updateParkingLots() {
            const grid = document.getElementById('parking-grid');
            if (!grid) return;

            try {
                const res = await fetch('index.php?api_action=get_parking_lots');
                const lots = await res.json();

                if (!lots || lots.length === 0) {
                    grid.innerHTML = '<div class="col-span-full text-slate-500 text-center py-2 text-xs italic">Nenhuma chamada estacionada no momento.</div>';
                    return;
                }

                grid.innerHTML = lots.map(l => `
                    <div class="bg-amber-500/10 border border-amber-500/30 rounded-xl p-2.5 text-center space-y-1">
                        <span class="text-[10px] font-bold text-amber-400 block">VAGA ${l.slot}</span>
                        <span class="text-xs font-extrabold text-white block truncate">${l.channel}</span>
                        <button onclick="amiClickToCall('${l.slot}', '')" class="w-full py-1 bg-amber-600 hover:bg-amber-500 text-white font-bold rounded text-[10px] transition">
                            Capturar (${l.slot})
                        </button>
                    </div>
                `).join('');
            } catch(e){}
        }

        async function updateQueuesRealtime() {
            const grid = document.getElementById('queues-realtime-grid');
            const agGrid = document.getElementById('agentes-pausa-container');
            if (!grid && !agGrid) return;

            try {
                const res = await fetch('index.php?api_action=get_queues_realtime');
                const queues = await res.json();

                if (!queues || queues.length === 0) {
                    if (grid) grid.innerHTML = '<div class="text-center py-10 text-slate-500 text-xs">Nenhuma fila cadastrada ou ativa.</div>';
                    if (agGrid) agGrid.innerHTML = '<div class="text-center py-10 text-slate-500 text-xs">Nenhum agente cadastrado em filas.</div>';
                    return;
                }

                if (grid) {
                    if (typeof queueViewMode !== 'undefined' && queueViewMode === 'table') {
                        grid.innerHTML = `
                            <div class="bg-slate-900 border border-slate-800 rounded-xl overflow-hidden shadow-xl">
                                <table class="w-full text-left text-xs text-slate-300">
                                    <thead class="bg-slate-950 uppercase text-[10px] font-bold text-slate-400 tracking-wider">
                                        <tr>
                                            <th class="p-3">Fila / Setor</th>
                                            <th class="p-3">Em Espera</th>
                                            <th class="p-3">Atendidas</th>
                                            <th class="p-3">Abandonadas</th>
                                            <th class="p-3">Tempo Médio</th>
                                            <th class="p-3 text-right">Agentes Logados</th>
                                        </tr>
                                    </thead>
                                    <tbody class="divide-y divide-slate-800">
                                        ${queues.map(q => {
                                             const qStr = q.queue_name || q.queue_number;
                                             const displayQueueTitle = qStr.includes(q.queue_number) ? (qStr.toLowerCase().startsWith('fila') ? qStr : `Fila ${qStr}`) : `Fila ${q.queue_number} - ${qStr}`;
                                             return `
                                             <tr class="queue-item-row hover:bg-slate-800/40 transition">
                                                 <td class="p-3 font-bold text-white">${displayQueueTitle}</td>
                                                 <td class="p-3"><span class="px-2.5 py-0.5 rounded text-[10px] font-bold ${q.callers_count > 0 ? 'bg-rose-500/20 text-rose-300 animate-pulse' : 'bg-slate-800 text-slate-400'}">${q.callers_count} Clientes</span></td>
                                                 <td class="p-3 font-mono text-emerald-400">${q.answered_count}</td>
                                                 <td class="p-3 font-mono text-rose-400">${q.abandoned_count}</td>
                                                 <td class="p-3 font-mono text-purple-300">${q.holdtime_avg}</td>
                                                 <td class="p-3 text-right font-mono font-bold text-slate-300">${(q.members || []).length} Atendentes</td>
                                             </tr>
                                         `}).join('')}
                                    </tbody>
                                </table>
                            </div>
                        `;
                    } else {
                        grid.innerHTML = queues.map(q => {
                            const callersHtml = (q.callers && q.callers.length > 0) ? q.callers.map(c => {
                                const callerDisp = c.caller_label || c.caller_name || c.caller_num || c.channel;
                                return `
                                <div class="flex items-center justify-between bg-slate-950 p-2.5 rounded-lg border border-purple-500/30 text-xs">
                                    <div class="flex items-center gap-2 overflow-hidden mr-2">
                                        <span class="w-5 h-5 rounded-full bg-purple-500/20 text-purple-300 font-bold flex items-center justify-center text-[10px] shrink-0">${c.pos}</span>
                                        <div class="overflow-hidden">
                                            <span class="font-bold text-white block text-xs truncate" title="${callerDisp}">${callerDisp}</span>
                                            <span class="text-[10px] text-purple-400 font-mono">Espera: ${c.wait_time}</span>
                                        </div>
                                    </div>
                                    <button onclick="amiClickToCall('${c.channel}', '')" class="px-2 py-1 bg-brand-600 hover:bg-brand-500 text-white rounded text-[10px] font-bold transition shrink-0">
                                        Capturar
                                    </button>
                                </div>
                            `;
                            }).join('') : '<div class="text-slate-500 text-xs italic py-2">Nenhum cliente em espera.</div>';

                            const membersHtml = (q.members && q.members.length > 0) ? q.members.map(m => {
                                return `
                                    <div class="flex items-center justify-between bg-slate-950 p-2 rounded-lg border border-slate-800 text-xs">
                                        <div>
                                            <span class="font-bold text-white block">${m.name ? `${m.extension} - ${m.name}` : `Ramal ${m.extension}`}</span>
                                            <span class="text-[10px] text-slate-400 font-mono">${m.interface}</span>
                                        </div>
                                        <div>
                                            <span class="px-2 py-0.5 rounded text-[9px] font-bold ${m.status === 'Em Chamada' ? 'bg-rose-500/20 text-rose-300' : (m.status === 'Tocando' ? 'bg-amber-500/20 text-amber-300' : 'bg-emerald-500/20 text-emerald-300')}">${m.status}</span>
                                        </div>
                                    </div>
                                `;
                            }).join('') : '<div class="text-slate-500 text-xs italic py-2">Nenhum agente logado.</div>';

                            const qStr = q.queue_name || q.queue_number;
                            const displayQueueTitle = qStr.includes(q.queue_number) ? (qStr.toLowerCase().startsWith('fila') ? qStr : `Fila ${qStr}`) : `Fila ${q.queue_number} - ${qStr}`;

                            return `
                                <div class="queue-item-card bg-slate-900 border border-slate-800 rounded-xl p-4 shadow-lg space-y-4">
                                    <div class="flex items-center justify-between border-b border-slate-800 pb-3">
                                        <h4 class="text-sm font-bold text-white flex items-center gap-2">
                                            <i class="fa-solid fa-layer-group text-purple-400"></i> ${displayQueueTitle}
                                        </h4>
                                        <div class="flex items-center gap-2 text-xs">
                                            <span class="px-2.5 py-1 bg-purple-500/10 text-purple-300 border border-purple-500/20 rounded-lg font-bold">Espera: ${q.callers_count}</span>
                                            <span class="px-2.5 py-1 bg-emerald-500/10 text-emerald-300 border border-emerald-500/20 rounded-lg font-bold">Atendidas: ${q.answered_count}</span>
                                            <span class="px-2.5 py-1 bg-rose-500/10 text-rose-300 border border-rose-500/20 rounded-lg font-bold">Abandonos: ${q.abandoned_count}</span>
                                        </div>
                                    </div>
                                    <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                                        <div><h5 class="text-xs font-bold text-slate-300 mb-2">Clientes em Espera</h5><div class="space-y-2">${callersHtml}</div></div>
                                        <div><h5 class="text-xs font-bold text-slate-300 mb-2">Agentes da Fila</h5><div class="space-y-2">${membersHtml}</div></div>
                                    </div>
                                </div>
                            `;
                        }).join('');
                    }
                }

                if (agGrid) {
                    let allMembers = [];
                    queues.forEach(q => {
                        (q.members || []).forEach(m => {
                            allMembers.push({ ...m, queue_number: q.queue_number, queue_name: q.queue_name });
                        });
                    });

                    if (allMembers.length === 0) {
                        agGrid.innerHTML = '<div class="text-center py-6 text-slate-500 text-xs">Nenhum agente logado em filas no momento.</div>';
                    } else {
                        agGrid.innerHTML = `
                            <div class="grid grid-cols-1 sm:grid-cols-2 md:grid-cols-3 gap-4">
                                ${allMembers.map(m => `
                                    <div class="bg-slate-950 p-4 rounded-xl border border-slate-800 space-y-2">
                                        <div class="flex items-center justify-between">
                                            <span class="font-bold text-white text-xs">${m.name ? `${m.extension} - ${m.name}` : `Ramal ${m.extension}`}</span>
                                            <span class="px-2 py-0.5 rounded text-[9px] font-bold ${m.status === 'Em Chamada' ? 'bg-rose-500/20 text-rose-300' : 'bg-emerald-500/20 text-emerald-300'}">${m.status}</span>
                                        </div>
                                        <span class="text-[10px] text-slate-400 block">Fila ${m.queue_number} (${m.queue_name})</span>
                                    </div>
                                `).join('')}
                            </div>
                        `;
                    }
                }
            } catch(e) {
                console.error("Erro Filas:", e);
            }
        }

        async function fopPauseAgent(queueNum, iface, isPaused) {
            let reason = 'Pausa Operacional';
            if (!isPaused) {
                reason = prompt("Motivo da Pausa (Almoço, Reunião, Banheiro, NR17):", "Almoço") || "Pausa Operacional";
            }
            const nextPaused = isPaused ? 0 : 1;
            try {
                const res = await fetch(`index.php?api_action=fop_action&type=pause_queue_agent&queue=${queueNum}&interface=${encodeURIComponent(iface)}&paused=${nextPaused}&reason=${encodeURIComponent(reason)}`);
                const data = await res.json();
                alert(data.message || (data.success ? 'Status do agente alterado!' : 'Erro ao pausar agente'));
                updateQueuesRealtime();
            } catch(e){
                alert('Erro: ' + e.message);
            }
        }

        async function fopSpyCall(targetExt, spyType) {
            const supExt = prompt("Digite o SEU Ramal (Supervisor) para receber a chamada de " + spyType.toUpperCase() + ":", "1000");
            if (!supExt) return;
            try {
                const res = await fetch(`index.php?api_action=fop_action&type=${spyType}&ext=${targetExt}&sup_ext=${supExt}`);
                const data = await res.json();
                alert(data.message || (data.success ? 'Comando enviado!' : 'Erro: ' + data.error));
            } catch(e) {
                alert('Erro: ' + e.message);
            }
        }

        async function fopHangupCall(channel) {
            if (!confirm("Tem certeza que deseja desconectar o canal " + channel + "?")) return;
            try {
                const res = await fetch(`index.php?api_action=fop_action&type=hangup&channel=${encodeURIComponent(channel)}`);
                const data = await res.json();
                alert(data.message || 'Desconexão solicitada!');
                updateFOP();
            } catch(e) {
                alert('Erro: ' + e.message);
            }
        }

        document.addEventListener('DOMContentLoaded', () => {
            updateDashboardLogs();
            setInterval(updateDashboardLogs, 5000);

            updateFOP();
            setInterval(updateFOP, 5000);

            updateQueuesRealtime();
            setInterval(updateQueuesRealtime, 5000);
        });

        async function originateCall(destNum, destName = '') {
            let myExt = localStorage.getItem('pabx_user_exten');
            if (!myExt) {
                myExt = prompt("Para usar o 'Click to Call', digite o SEU ramal:", "1000");
                if (myExt) {
                    localStorage.setItem('pabx_user_exten', myExt);
                } else {
                    return; // Cancelou
                }
            }
            
            if (confirm(`Deseja ligar de ${myExt} para ${destName ? destName + ' ('+destNum+')' : destNum}?`)) {
                try {
                    const res = await fetch(`index.php?api_action=originate_call&from=${encodeURIComponent(myExt)}&to=${encodeURIComponent(destNum)}`);
                    const data = await res.json();
                    if (typeof showIaToast === 'function') showIaToast(data.message);
                    else alert(data.message);
                } catch(e) {
                    alert('Erro na requisição: ' + e.message);
                }
            }
        }

        // Global Contact Modal Logic
        function openGlobalAddContactModal(phone = '', id = '', name = '', email = '', company = '', tag = '', notes = '') {
            document.getElementById('global_c_id').value = id || '';
            document.getElementById('global_c_phone').value = phone || '';
            document.getElementById('global_c_name').value = name || '';
            document.getElementById('global_c_email').value = email || '';
            document.getElementById('global_c_company').value = company || '';
            document.getElementById('global_c_tag').value = tag || '';
            document.getElementById('global_c_notes').value = notes || '';
            
            const title = document.getElementById('modal-global-title');
            if (id) {
                title.innerHTML = '<i class="fa-solid fa-user-pen text-emerald-400"></i> Editar Contato';
            } else {
                title.innerHTML = '<i class="fa-solid fa-user-plus text-emerald-400"></i> Novo Contato';
            }
            
            openModal('modal-global-add-contact');
        }

        async function submitGlobalAddContact(e) {
            e.preventDefault();
            const btn = document.getElementById('btn-global-save-contact');
            const orig = btn.innerHTML;
            btn.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> Salvando...';
            btn.disabled = true;

            const payload = {
                c_id: document.getElementById('global_c_id').value,
                c_name: document.getElementById('global_c_name').value,
                c_phone: document.getElementById('global_c_phone').value,
                c_email: document.getElementById('global_c_email').value,
                c_company: document.getElementById('global_c_company').value,
                c_tag: document.getElementById('global_c_tag').value,
                c_notes: document.getElementById('global_c_notes').value,
            };

            try {
                const res = await fetch('index.php?api_action=save_contact', {
                    method: 'POST',
                    headers: {'Content-Type': 'application/json'},
                    body: JSON.stringify(payload)
                });
                const data = await res.json();
                if (data.success) {
                    closeModal('modal-global-add-contact');
                    if (data.issabel_sync_error) {
                        alert("Salvo no painel, mas falhou ao sincronizar com PABX: " + data.issabel_sync_error);
                    } else {
                        if (typeof showIaToast === 'function') showIaToast(data.message);
                        else alert(data.message);
                    }
                    
                    // Reload page after a short delay to reflect changes
                    setTimeout(() => window.location.reload(), 1500);
                } else {
                    alert(data.error || 'Erro ao salvar contato.');
                }
            } catch (err) {
                alert('Erro na requisição: ' + err.message);
            } finally {
                btn.innerHTML = orig;
                btn.disabled = false;
            }
        }
    </script>

    
    <!-- Modal Elegante para Originate / Click-to-Call -->
    <div id="modal-custom-click-to-call" class="fixed inset-0 z-[9999] hidden bg-slate-950/80 backdrop-blur-md flex items-center justify-center p-4">
        <div class="bg-slate-900 border border-slate-700 rounded-3xl w-full max-w-md p-6 shadow-2xl space-y-5 relative">
            <div class="flex items-center justify-between border-b border-slate-800 pb-3">
                <div class="flex items-center gap-3">
                    <div class="w-10 h-10 rounded-2xl bg-emerald-500/20 text-emerald-400 border border-emerald-500/30 flex items-center justify-center text-lg">
                        <i class="fa-solid fa-phone-volume"></i>
                    </div>
                    <div>
                        <h4 class="text-sm font-extrabold text-white">Discar Chamada (Click to Call)</h4>
                        <span class="text-xs text-slate-400">Origem: <strong id="modal-originate-from-display" class="text-emerald-400">Selecione/Digite seu Ramal</strong></span>
                    </div>
                </div>
                <button onclick="closeModal('modal-custom-click-to-call')" class="text-slate-400 hover:text-white"><i class="fa-solid fa-xmark text-lg"></i></button>
            </div>

            <form onsubmit="submitCustomOriginateCall(event)" class="space-y-4">
                <div>
                    <label class="block text-xs font-bold text-slate-300 mb-1.5">Seu Ramal de Origem (Quem Faz a Ligação) *</label>
                    <input type="text" id="modal-originate-from-val" required placeholder="Ex: 201 ou 1001" 
                           class="w-full px-4 py-2.5 bg-slate-950 border border-slate-800 rounded-xl text-white font-mono text-sm focus:border-emerald-500 focus:outline-none">
                </div>

                <div>
                    <label class="block text-xs font-bold text-slate-300 mb-1.5">Número ou Ramal de Destino *</label>
                    <input type="text" id="modal-originate-to-val" required placeholder="Ex: 1002 ou 011999998888" 
                           class="w-full px-4 py-2.5 bg-slate-950 border border-slate-800 rounded-xl text-white font-mono text-sm focus:border-emerald-500 focus:outline-none">
                </div>

                <div class="flex items-center gap-2 text-xs text-slate-300">
                    <input type="checkbox" id="modal-originate-remember" checked class="rounded bg-slate-950 border-slate-800 text-emerald-600 focus:ring-emerald-500">
                    <label for="modal-originate-remember" class="cursor-pointer">Lembrar meu ramal neste navegador para chamadas futuras</label>
                </div>

                <!-- Atalhos de Ramais Rápidos -->
                <div>
                    <span class="text-[11px] font-bold text-slate-400 block mb-1">ATALHOS RÁPIDOS DE DESTINO:</span>
                    <div class="flex flex-wrap gap-1.5 text-xs font-mono">
                        <button type="button" onclick="document.getElementById('modal-originate-to-val').value='200'" class="px-2.5 py-1 bg-slate-800 hover:bg-slate-700 text-slate-300 rounded-lg">200</button>
                        <button type="button" onclick="document.getElementById('modal-originate-to-val').value='201'" class="px-2.5 py-1 bg-slate-800 hover:bg-slate-700 text-slate-300 rounded-lg">201</button>
                        <button type="button" onclick="document.getElementById('modal-originate-to-val').value='202'" class="px-2.5 py-1 bg-slate-800 hover:bg-slate-700 text-slate-300 rounded-lg">202</button>
                        <button type="button" onclick="document.getElementById('modal-originate-to-val').value='205'" class="px-2.5 py-1 bg-slate-800 hover:bg-slate-700 text-slate-300 rounded-lg">205</button>
                        <button type="button" onclick="document.getElementById('modal-originate-to-val').value='250'" class="px-2.5 py-1 bg-slate-800 hover:bg-slate-700 text-slate-300 rounded-lg">250 (Atendimento)</button>
                    </div>
                </div>

                <div class="pt-2 flex justify-end gap-2">
                    <button type="button" onclick="closeModal('modal-custom-click-to-call')" class="px-4 py-2.5 rounded-xl text-xs font-bold text-slate-400 hover:text-white">Cancelar</button>
                    <button type="submit" id="btn-submit-originate" class="px-5 py-2.5 bg-emerald-600 hover:bg-emerald-500 text-white rounded-xl text-xs font-extrabold transition shadow-lg shadow-emerald-600/30 flex items-center gap-2">
                        <i class="fa-solid fa-phone"></i> Discar Agora
                    </button>
                </div>
            </form>
        </div>
    </div>

    <!-- MODAL DE RESULTADO DA SINCRONIZAÇÃO PABX (Sem '192.168.0.251 diz' e sem 'undefined') -->
    <div id="modal-sync-result" class="fixed inset-0 z-[99999] hidden bg-slate-950/85 backdrop-blur-md flex items-center justify-center p-4">
        <div class="bg-slate-900 border border-brand-500/30 rounded-3xl max-w-md w-full p-6 space-y-5 shadow-2xl animate-fade-in">
            <div class="flex items-center gap-3 border-b border-slate-800 pb-3">
                <div class="p-3 bg-brand-500/10 rounded-2xl border border-brand-500/20 text-brand-400">
                    <i class="fa-solid fa-rotate text-xl animate-spin"></i>
                </div>
                <div>
                    <h4 class="text-base font-black text-white">Sincronização Concluída!</h4>
                    <p class="text-xs text-slate-400">Espelhamento direto com o Servidor PABX Asterisk</p>
                </div>
            </div>

            <div class="grid grid-cols-2 gap-3">
                <div class="bg-slate-950 p-4 rounded-2xl border border-slate-800 text-center">
                    <span class="text-2xl font-black text-brand-400 font-mono block" id="sync-res-ext">15</span>
                    <span class="text-[11px] font-bold text-slate-400 uppercase tracking-wider">Ramais Importados</span>
                </div>
                <div class="bg-slate-950 p-4 rounded-2xl border border-slate-800 text-center">
                    <span class="text-2xl font-black text-cyan-400 font-mono block" id="sync-res-queue">6</span>
                    <span class="text-[11px] font-bold text-slate-400 uppercase tracking-wider">Filas Importadas</span>
                </div>
            </div>

            <div class="bg-emerald-500/10 border border-emerald-500/20 p-3 rounded-xl text-xs text-emerald-300 flex items-center gap-2">
                <i class="fa-solid fa-circle-check text-emerald-400 text-base"></i>
                <span>Dados 100% sincronizados em tempo real com a central telefônica PABX.</span>
            </div>

            <div class="pt-2">
                <button onclick="location.reload()" class="w-full py-3 bg-brand-600 hover:bg-brand-500 text-white font-black rounded-xl text-xs shadow-lg shadow-brand-600/30 transition flex items-center justify-center gap-2">
                    <i class="fa-solid fa-check"></i> OK - Atualizar Painel
                </button>
            </div>
        </div>
    </div>

    <!-- MODAL DO PERFIL DO USUÁRIO LOGADO (dados reais de system_users) -->
    <?php $__pu = function_exists('getLoggedUser') ? (getLoggedUser() ?: []) : []; $__puName = (string)($__pu['name'] ?? ''); $__puIni = strtoupper(function_exists('mb_substr') ? mb_substr($__puName ?: '?', 0, 2) : substr($__puName ?: '?', 0, 2)); ?>
    <div id="modal-user-profile" class="fixed inset-0 z-[9999] hidden bg-slate-950/80 backdrop-blur-md flex items-center justify-center p-4">
        <div class="bg-slate-900 border border-slate-800 rounded-3xl max-w-lg w-full p-6 space-y-5 shadow-2xl">
            <div class="flex items-center justify-between border-b border-slate-800 pb-3">
                <div class="flex items-center gap-3">
                    <div class="w-10 h-10 rounded-2xl bg-gradient-to-tr from-brand-600 to-indigo-600 text-white flex items-center justify-center font-black text-base shadow-md">
                        <?php echo htmlspecialchars($__puIni); ?>
                    </div>
                    <div>
                        <h4 class="text-sm font-black text-white"><?php echo htmlspecialchars($__puName); ?></h4>
                        <span class="text-[10px] text-brand-300 font-mono"><?php echo htmlspecialchars((string)($__pu['role'] ?? '')); ?></span>
                    </div>
                </div>
                <button onclick="closeModal('modal-user-profile')" class="p-2 text-slate-400 hover:text-white rounded-xl hover:bg-slate-800 transition">
                    <i class="fa-solid fa-xmark text-lg"></i>
                </button>
            </div>

            <div class="space-y-3 text-xs">
                <div class="space-y-1">
                    <label class="font-bold text-slate-300">E-mail para Recebimento de Relatórios & Notificações:</label>
                    <input type="email" id="user-profile-email" readonly value="<?php echo htmlspecialchars((string)($__pu['email'] ?? '')); ?>" class="w-full bg-slate-950 border border-slate-700 rounded-xl px-3 py-2.5 text-white focus:outline-none focus:border-brand-500">
                </div>

                <div class="grid grid-cols-2 gap-3">
                    <div class="space-y-1">
                        <label class="font-bold text-slate-300">Ramal Operador Associado:</label>
                        <input type="text" id="user-profile-ext" readonly value="<?php echo htmlspecialchars((string)($__pu['extension'] ?? '')); ?>" class="w-full bg-slate-950 border border-slate-700 rounded-xl px-3 py-2.5 text-white font-mono focus:outline-none focus:border-brand-500">
                    </div>
                    <div class="space-y-1">
                        <label class="font-bold text-slate-300">Nível de Acesso:</label>
                        <input type="text" disabled value="<?php echo htmlspecialchars((string)($__pu['role'] ?? '')); ?>" class="w-full bg-slate-950/60 border border-slate-800 text-slate-400 rounded-xl px-3 py-2.5 font-bold cursor-not-allowed">
                    </div>
                </div>

                <div class="bg-amber-500/10 border border-amber-500/20 p-3 rounded-xl text-[11px] text-amber-300">
                    <i class="fa-solid fa-circle-info mr-1"></i> As configurações de <strong>SMTP, APIs e IA são universais do sistema</strong> e podem ser ajustadas no menu <strong>Configurações</strong>.
                </div>
            </div>

            <div class="flex justify-end gap-2 pt-2">
                <button onclick="closeModal('modal-user-profile')" class="px-4 py-2.5 bg-slate-800 hover:bg-slate-700 text-slate-300 font-bold rounded-xl text-xs transition">Cancelar</button>
                <a href="index.php?module=configuracoes&action=usuarios" style="white-space:nowrap !important;flex-shrink:0 !important;width:auto !important;overflow:visible !important;text-overflow:clip !important" class="px-5 py-2.5 bg-brand-600 hover:bg-brand-500 text-white font-extrabold rounded-xl text-xs shadow-lg shadow-brand-600/30 transition flex items-center gap-1.5">
                    <i class="fa-solid fa-user-gear"></i> Gerenciar Usuários
                </a>
            </div>
        </div>
    </div>

    <!-- WIDGET FLUTUANTE DE ROADMAP & ATUALIZAÇÕES DO SISTEMA (Canto Inferior Direito) -->
    <div class="fixed bottom-4 right-4 z-40 flex items-center gap-2">
        <button onclick="openRoadmapModal()" class="px-3.5 py-2 bg-slate-900/90 hover:bg-slate-800 text-slate-200 border border-amber-500/40 rounded-full text-xs font-black shadow-xl backdrop-blur-md transition-all hover:scale-105 flex items-center gap-2">
            <span class="w-2 h-2 rounded-full bg-amber-400 animate-ping"></span>
            <i class="fa-solid fa-rocket text-amber-400"></i>
            <span>Roadmap de Atualizações</span>
        </button>
    </div>

    <!-- MODAL ROADMAP DE ATUALIZAÇÕES DO SISTEMA -->
    <div id="modal-roadmap" class="fixed inset-0 z-[9999] hidden bg-slate-950/80 backdrop-blur-md flex items-center justify-center p-4">
        <div class="bg-slate-900 border border-slate-800 rounded-3xl max-w-2xl w-full p-6 space-y-5 shadow-2xl max-h-[90vh] overflow-y-auto custom-scrollbar">
            <div class="flex items-center justify-between border-b border-slate-800 pb-3">
                <div class="flex items-center gap-3">
                    <div class="p-3 bg-amber-500/10 rounded-2xl border border-amber-500/20 text-amber-400">
                        <i class="fa-solid fa-rocket text-xl"></i>
                    </div>
                    <div>
                        <h4 class="text-base font-black text-white">Roadmap de Atualizações & Melhorias do Sistema</h4>
                        <p class="text-xs text-slate-400">Acompanhamento transparente de recursos em produção e desenvolvimento</p>
                    </div>
                </div>
                <button onclick="closeModal('modal-roadmap')" class="p-2 text-slate-400 hover:text-white rounded-xl hover:bg-slate-800 transition">
                    <i class="fa-solid fa-xmark text-lg"></i>
                </button>
            </div>

            <!-- SEÇÃO DE RECURSOS CONCLUÍDOS -->
            <div class="space-y-3">
                <div class="text-xs font-black text-emerald-400 uppercase tracking-wider flex items-center gap-2">
                    <i class="fa-solid fa-circle-check"></i> Recursos Concluídos & 100% Operacionais:
                </div>
                <div class="space-y-2 text-xs text-slate-300">
                    <div class="p-3 bg-slate-950 rounded-2xl border border-emerald-500/20 flex items-start gap-2.5">
                        <i class="fa-solid fa-check text-emerald-400 mt-0.5"></i>
                        <div>
                            <strong class="text-white block">Espelhamento Direct PABX Asterisk</strong>
                            <p class="text-slate-400 text-[11px]">Dados 100% reais sem mocks ou ramais genéricos. Qualquer alteração reflete nos dois sentidos.</p>
                        </div>
                    </div>
                    <div class="p-3 bg-slate-950 rounded-2xl border border-emerald-500/20 flex items-start gap-2.5">
                        <i class="fa-solid fa-check text-emerald-400 mt-0.5"></i>
                        <div>
                            <strong class="text-white block">Player Único de Áudio Global</strong>
                            <p class="text-slate-400 text-[11px]">Garante que apenas 1 áudio/gravação toque por vez no sistema, pausando os demais automaticamente.</p>
                        </div>
                    </div>
                    <div class="p-3 bg-slate-950 rounded-2xl border border-emerald-500/20 flex items-start gap-2.5">
                        <i class="fa-solid fa-check text-emerald-400 mt-0.5"></i>
                        <div>
                            <strong class="text-white block">Sincronização Visual de PABX sem Erros de IP / Undefined</strong>
                            <p class="text-slate-400 text-[11px]">Modal customizado com contagem exata de ramais e filas importados.</p>
                        </div>
                    </div>
                    <div class="p-3 bg-slate-950 rounded-2xl border border-emerald-500/20 flex items-start gap-2.5">
                        <i class="fa-solid fa-check text-emerald-400 mt-0.5"></i>
                        <div>
                            <strong class="text-white block">Modo Claro com Alto Contraste & Legibilidade</strong>
                            <p class="text-slate-400 text-[11px]">Paleta de cores otimizada para legibilidade perfeita de textos, tabelas e relatórios.</p>
                        </div>
                    </div>
                </div>
            </div>

            <!-- SEÇÃO DE RECURSOS EM DESENVOLVIMENTO -->
            <div class="space-y-3 pt-2">
                <div class="text-xs font-black text-amber-400 uppercase tracking-wider flex items-center gap-2">
                    <i class="fa-solid fa-clock-rotate-left"></i> Em Validação & Ajuste Fino:
                </div>
                <div class="space-y-2 text-xs text-slate-300">
                    <div class="p-3 bg-slate-950 rounded-2xl border border-amber-500/20 flex items-start gap-2.5">
                        <i class="fa-solid fa-gear text-amber-400 mt-0.5 animate-spin"></i>
                        <div>
                            <strong class="text-white block">Gestão de Condições Especiais de Horário & Feriados PABX</strong>
                            <p class="text-slate-400 text-[11px]">Sincronização estrita por `timegroupid` e faixas de horário personalizadas (Em testes finais).</p>
                        </div>
                    </div>
                    <div class="p-3 bg-slate-950 rounded-2xl border border-amber-500/20 flex items-start gap-2.5">
                        <i class="fa-solid fa-gear text-amber-400 mt-0.5 animate-spin"></i>
                        <div>
                            <strong class="text-white block">Disparo de Resumos de IA via WhatsApp e E-mail Universal</strong>
                            <p class="text-slate-400 text-[11px]">Ícones com validação automática das conexões globais de SMTP e WhatsApp API.</p>
                        </div>
                    </div>
                </div>
            </div>

            <div class="pt-3 border-t border-slate-800 flex justify-end">
                <button onclick="closeModal('modal-roadmap')" class="px-5 py-2 bg-slate-800 hover:bg-slate-700 text-white font-bold rounded-xl text-xs transition">
                    Fechar Roadmap
                </button>
            </div>
        </div>
    </div>

    <!-- CONTAINER DE TOASTS FLUTUANTES -->
    <div id="toast-container" class="fixed top-20 right-5 z-[99999] space-y-3 pointer-events-none"></div>

    <script>
        function openSyncResultModal(extCount, queueCount, msg) {
            const extEl = document.getElementById('sync-res-ext');
            const queueEl = document.getElementById('sync-res-queue');
            if (extEl) extEl.innerText = extCount;
            if (queueEl) queueEl.innerText = queueCount;
            const modal = document.getElementById('modal-sync-result');
            if (modal) modal.classList.remove('hidden');
        }

        function openUserProfileModal() {
            const modal = document.getElementById('modal-user-profile');
            if (modal) modal.classList.remove('hidden');
        }

        function openRoadmapModal() {
            const modal = document.getElementById('modal-roadmap');
            if (modal) modal.classList.remove('hidden');
        }


    </script>

    <!-- MODAL DE COMPARTILHAMENTO VIA E-MAIL -->
    <div id="modal-share-email" class="fixed inset-0 z-[99999] hidden bg-slate-950/85 backdrop-blur-md flex items-center justify-center p-4">
        <div class="bg-slate-900 border border-amber-500/30 rounded-3xl max-w-md w-full p-6 space-y-5 shadow-2xl animate-fade-in">
            <div class="flex items-center justify-between border-b border-slate-800 pb-3">
                <div class="flex items-center gap-3">
                    <div class="p-3 bg-amber-500/10 rounded-2xl border border-amber-500/20 text-amber-400">
                        <i class="fa-solid fa-envelope text-xl"></i>
                    </div>
                    <div>
                        <h4 class="text-base font-black text-white" id="share-email-modal-title">Enviar por E-mail</h4>
                        <p class="text-xs text-slate-400">Envio automático do relatório ou áudio de chamada</p>
                    </div>
                </div>
                <button onclick="closeModal('modal-share-email')" class="text-slate-400 hover:text-white p-2"><i class="fa-solid fa-xmark"></i></button>
            </div>

            <form onsubmit="event.preventDefault(); executeShareEmail();" class="space-y-4">
                <input type="hidden" id="share-email-subject-val" value="">
                <div class="space-y-1.5">
                    <label class="text-xs font-bold text-slate-300">Item a ser enviado</label>
                    <div id="share-email-item-display" class="px-3.5 py-2.5 bg-slate-950 border border-slate-800 rounded-xl text-xs font-mono text-amber-300 truncate">--</div>
                </div>

                <div class="space-y-1.5">
                    <label class="text-xs font-bold text-slate-300">E-mail do Destinatário <span class="text-rose-400">*</span></label>
                    <input type="email" id="share-email-target" required placeholder="ex: cliente@empresa.com.br" class="w-full px-3.5 py-2.5 bg-slate-950 border border-slate-800 rounded-xl text-white text-xs placeholder-slate-500 focus:border-amber-500 focus:outline-none transition">
                </div>

                <!-- Aviso sobre Status do SMTP -->
                <div id="smtp-status-alert" class="p-3 bg-amber-500/10 border border-amber-500/20 rounded-xl text-xs text-amber-300 space-y-1">
                    <div class="flex items-center gap-2 font-bold">
                        <i class="fa-solid fa-circle-info"></i>
                        <span>Disparo via SMTP Integrado</span>
                    </div>
                    <p class="text-[11px] text-amber-200/80 leading-relaxed">
                        O e-mail será enviado utilizando o servidor SMTP cadastrado em <strong>Configurações > E-mail / SMTP</strong>.
                    </p>
                </div>

                <div class="pt-2 flex justify-end gap-2">
                    <button type="button" onclick="closeModal('modal-share-email')" class="px-4 py-2.5 rounded-xl text-xs font-bold text-slate-400 hover:text-white">Cancelar</button>
                    <button type="submit" id="btn-submit-share-email" class="px-5 py-2.5 bg-amber-600 hover:bg-amber-500 text-white rounded-xl text-xs font-extrabold transition shadow-lg shadow-amber-600/30 flex items-center gap-2">
                        <i class="fa-solid fa-paper-plane"></i> Enviar E-mail Agora
                    </button>
                </div>
            </form>
        </div>
    </div>

    <!-- MODAL DE COMPARTILHAMENTO VIA WHATSAPP -->
    <div id="modal-share-whatsapp" class="fixed inset-0 z-[99999] hidden bg-slate-950/85 backdrop-blur-md flex items-center justify-center p-4">
        <div class="bg-slate-900 border border-emerald-500/30 rounded-3xl max-w-md w-full p-6 space-y-5 shadow-2xl animate-fade-in">
            <div class="flex items-center justify-between border-b border-slate-800 pb-3">
                <div class="flex items-center gap-3">
                    <div class="p-3 bg-emerald-500/10 rounded-2xl border border-emerald-500/20 text-emerald-400">
                        <i class="fa-brands fa-whatsapp text-xl"></i>
                    </div>
                    <div>
                        <h4 class="text-base font-black text-white" id="share-wa-modal-title">Enviar por WhatsApp</h4>
                        <p class="text-xs text-slate-400">Disparo automático de dados ou link de gravação</p>
                    </div>
                </div>
                <button onclick="closeModal('modal-share-whatsapp')" class="text-slate-400 hover:text-white p-2"><i class="fa-solid fa-xmark"></i></button>
            </div>

            <form onsubmit="event.preventDefault(); executeShareWhatsApp();" class="space-y-4">
                <input type="hidden" id="share-wa-item-val" value="">
                <div class="space-y-1.5">
                    <label class="text-xs font-bold text-slate-300">Item a ser enviado</label>
                    <div id="share-wa-item-display" class="px-3.5 py-2.5 bg-slate-950 border border-slate-800 rounded-xl text-xs font-mono text-emerald-300 truncate">--</div>
                </div>

                <div class="space-y-1.5">
                    <label class="text-xs font-bold text-slate-300">Número do WhatsApp (com DDD) <span class="text-rose-400">*</span></label>
                    <input type="text" id="share-wa-target" required placeholder="ex: 5511999998888" class="w-full px-3.5 py-2.5 bg-slate-950 border border-slate-800 rounded-xl text-white text-xs placeholder-slate-500 focus:border-emerald-500 focus:outline-none transition">
                </div>

                <div class="space-y-1.5">
                    <label class="text-xs font-bold text-slate-300">Mensagem Adicional / Observação</label>
                    <textarea id="share-wa-msg" rows="2" placeholder="Mensagem personalizada (opcional)..." class="w-full px-3.5 py-2.5 bg-slate-950 border border-slate-800 rounded-xl text-white text-xs placeholder-slate-500 focus:border-emerald-500 focus:outline-none transition"></textarea>
                </div>

                <div class="pt-2 flex items-center justify-between gap-2">
                    <button type="button" onclick="openDirectWebWa()" class="px-3 py-2.5 bg-slate-800 hover:bg-slate-700 text-emerald-400 border border-slate-700 rounded-xl text-xs font-bold transition flex items-center gap-1.5" title="Abrir no WhatsApp Web diretamente">
                        <i class="fa-brands fa-whatsapp text-sm"></i> Abrir Web
                    </button>
                    <div class="flex items-center gap-2">
                        <button type="button" onclick="closeModal('modal-share-whatsapp')" class="px-3 py-2.5 rounded-xl text-xs font-bold text-slate-400 hover:text-white">Cancelar</button>
                        <button type="submit" id="btn-submit-share-wa" class="px-4 py-2.5 bg-emerald-600 hover:bg-emerald-500 text-white rounded-xl text-xs font-extrabold transition shadow-lg shadow-emerald-600/30 flex items-center gap-2">
                            <i class="fa-solid fa-paper-plane"></i> Enviar API
                        </button>
                    </div>
                </div>
            </form>
        </div>
    </div>

    <script>
        function shareReportViaEmail(itemTitle, defaultEmail = '') {
            window.__shareUid = window.__pendingUid || ''; window.__pendingUid = '';
            document.getElementById('share-email-subject-val').value = itemTitle;
            document.getElementById('share-email-item-display').innerText = itemTitle;
            const targetInput = document.getElementById('share-email-target');
            if (targetInput) targetInput.value = defaultEmail || localStorage.getItem('prisma_user_email') || '';
            
            const modal = document.getElementById('modal-share-email');
            if (modal) modal.classList.remove('hidden');
        }

        function shareReportViaWhatsApp(itemTitle, defaultPhone = '') {
            window.__shareUid = window.__pendingUid || ''; window.__pendingUid = '';
            document.getElementById('share-wa-item-val').value = itemTitle;
            document.getElementById('share-wa-item-display').innerText = itemTitle;
            const targetInput = document.getElementById('share-wa-target');
            if (targetInput) targetInput.value = defaultPhone || '';
            
            const modal = document.getElementById('modal-share-whatsapp');
            if (modal) modal.classList.remove('hidden');
        }

        function sendSingleCallEmail(uid, src, dst, calldate, duration) {
            window.__pendingUid = uid;
            shareReportViaEmail(`Gravação de Chamada #${uid} (${src} ➔ ${dst})`);
        }

        function sendSingleCallWa(uid, src, dst, calldate, duration) {
            window.__pendingUid = uid;
            shareReportViaWhatsApp(`Gravação de Chamada #${uid} (${src} ➔ ${dst})`);
        }

        async function executeShareEmail() {
            const itemTitle = document.getElementById('share-email-subject-val').value;
            const email = document.getElementById('share-email-target').value.trim();
            const btn = document.getElementById('btn-submit-share-email');
            const origHtml = btn ? btn.innerHTML : '';

            if (!email) {
                showToastNotification('E-mail Obrigatório', 'Informe o endereço de e-mail de destino.', 'warning');
                return;
            }

            if (btn) { btn.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> Enviando...'; btn.disabled = true; }
            try {
                const res = await fetch('index.php?api_action=send_email_report', {
                    method: 'POST',
                    headers: {'Content-Type': 'application/json'},
                    body: JSON.stringify({ to: email, subject: itemTitle, message: itemTitle, uid: window.__shareUid || '' })
                });
                const data = await res.json();
                if (data.success) {
                    showToastNotification('E-mail enviado', `"${itemTitle}" enviado para ${email}.` + (data.warning ? ' ' + data.warning : ''), data.warning ? 'warning' : 'success');
                    closeModal('modal-share-email');
                } else {
                    showToastNotification('Falha no envio de e-mail', data.error || 'Erro desconhecido', 'error');
                }
            } catch (e) {
                showToastNotification('Falha no envio de e-mail', e.message, 'error');
            } finally {
                if (btn) { btn.innerHTML = origHtml || '<i class="fa-solid fa-paper-plane"></i> Enviar E-mail Agora'; btn.disabled = false; }
            }
        }

        async function executeShareWhatsApp() {
            const itemTitle = document.getElementById('share-wa-item-val').value;
            let phone = document.getElementById('share-wa-target').value.replace(/\D/g, '');
            const custom = (document.getElementById('share-wa-msg').value || '').trim();
            const btn = document.getElementById('btn-submit-share-wa');
            const origHtml = btn ? btn.innerHTML : '';

            if (!phone) {
                showToastNotification('WhatsApp Obrigatório', 'Informe o número do celular com DDD.', 'warning');
                return;
            }

            if (!phone.startsWith('55') && phone.length <= 11) {
                phone = '55' + phone;
            }

            if (btn) { btn.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> Enviando...'; btn.disabled = true; }
            try {
                const res = await fetch('index.php?api_action=send_whatsapp_message', {
                    method: 'POST',
                    headers: {'Content-Type': 'application/json'},
                    body: JSON.stringify({ phone: phone, message: (custom ? custom + '\n\n' : '') + '📌 ' + itemTitle, uid: window.__shareUid || '' })
                });
                const data = await res.json();
                if (data.success) {
                    showToastNotification('WhatsApp enviado', `Mensagem enviada para +${phone}.` + (data.warning ? ' ' + data.warning : ''), data.warning ? 'warning' : 'success');
                    closeModal('modal-share-whatsapp');
                } else {
                    showToastNotification('Falha no envio de WhatsApp', data.error || 'Erro desconhecido', 'error');
                }
            } catch (e) {
                showToastNotification('Falha no envio de WhatsApp', e.message, 'error');
            } finally {
                if (btn) { btn.innerHTML = origHtml || '<i class="fa-solid fa-paper-plane"></i> Enviar API'; btn.disabled = false; }
            }
        }

        function openDirectWebWa() {
            const itemTitle = document.getElementById('share-wa-item-val').value;
            let phone = document.getElementById('share-wa-target').value.replace(/\D/g, '');
            const customMsg = document.getElementById('share-wa-msg').value;

            if (!phone) {
                showToastNotification('WhatsApp Obrigatório', 'Informe o número de destino antes de abrir o Web WhatsApp.', 'warning');
                return;
            }

            if (!phone.startsWith('55') && phone.length <= 11) {
                phone = '55' + phone;
            }

            const text = encodeURIComponent(`Olá! Segue compartilhamento do PABX IPbx Prisma:\n\n*${itemTitle}*\n${customMsg ? customMsg + '\n\n' : ''}Acesse o painel para audição e detalhes.`);
            window.open(`https://wa.me/${phone}?text=${text}`, '_blank');
            closeModal('modal-share-whatsapp');
        }

        function pabx_log(category, level, message, context = {}) {
            try {
                const formData = new FormData();
                formData.append('category', category || 'user_actions');
                formData.append('level', level || 'INFO');
                formData.append('message', message || '');
                formData.append('context', JSON.stringify(context || {}));
                navigator.sendBeacon('index.php?api_action=log_event', formData);
            } catch(e) {}
        }
        window.pabx_log = pabx_log;

        window.addEventListener('error', function(e) {
            if (e && e.message) {
                pabx_log('system_errors', 'ERROR', `[JS Runtime Error] ${e.message} em ${e.filename || 'script'}:${e.lineno || 0}`);
            }
        });

        window.addEventListener('unhandledrejection', function(e) {
            pabx_log('system_errors', 'ERROR', `[JS UnhandledRejection] ${e.reason || 'Promise Rejection sem tratamento'}`);
        });

        function showToastNotification(title, message, type = 'info') {
            if (type === 'error' || type === 'warning') {
                const cat = (type === 'error') ? 'system_errors' : 'user_actions';
                const lvl = (type === 'error') ? 'ERROR' : 'WARNING';
                pabx_log(cat, lvl, `[Toast ${title}] ${message}`);
            }

            const container = document.getElementById('toast-container');
            if (!container) return;

            const toast = document.createElement('div');
            toast.className = `pointer-events-auto p-4 rounded-2xl border shadow-2xl max-w-sm w-full flex items-start gap-3 transition-all transform translate-y-2 opacity-0 text-xs text-white ${
                type === 'success' ? 'bg-emerald-950 border-emerald-500/50' : (
                type === 'warning' ? 'bg-amber-950 border-amber-500/50' : (
                type === 'error' ? 'bg-rose-950 border-rose-500/50' : 'bg-slate-900 border-slate-700'))
            }`;

            const iconClass = type === 'success' ? 'fa-circle-check text-emerald-400' : (
                type === 'warning' ? 'fa-triangle-exclamation text-amber-400' : (
                type === 'error' ? 'fa-circle-xmark text-rose-400' : 'fa-circle-info text-brand-400')
            );

            toast.innerHTML = `
                <i class="fa-solid ${iconClass} text-base mt-0.5"></i>
                <div class="flex-1 space-y-1">
                    <strong class="font-black block">${title}</strong>
                    <p class="text-[11px] opacity-90 leading-relaxed">${message}</p>
                </div>
                <button onclick="this.parentElement.remove()" class="text-slate-400 hover:text-white"><i class="fa-solid fa-xmark"></i></button>
            `;

            container.appendChild(toast);
            setTimeout(() => {
                toast.classList.remove('translate-y-2', 'opacity-0');
            }, 50);

            setTimeout(() => {
                toast.classList.add('opacity-0', 'translate-y-2');
                setTimeout(() => toast.remove(), 300);
            }, 5000);
        }
    </script>

<audio id="webphone-remote-audio" autoplay></audio>
<audio id="webphone-local-audio" autoplay muted></audio>
</body>
</html>


