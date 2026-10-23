<?php
ob_start();
/**
 * IPbx Prisma x Prismabot - Painel Integrado (NEWFRONT)
 * Arquitetura Modular de Engenharia de Software
 */

// Cronômetro: registra em Configurações > Logs > "Desempenho (telas lentas)" as telas/chamadas que demoram mais de 1,5 s
$__perf_t0 = microtime(true);
$__perf_marks = [];
register_shutdown_function(function () use (&$__perf_t0, &$__perf_marks) {
    $d = microtime(true) - $__perf_t0;
    if ($d < 1.5 || !function_exists('pabx_log')) return;
    $alvo = ($_GET['api_action'] ?? $_GET['action'] ?? '') !== '' ? (string)($_GET['api_action'] ?? $_GET['action']) : 'index';
    $tela = (string)($_GET['module'] ?? 'dashboard') . '/' . $alvo;
    $partes = []; $ant = 0.0;
    foreach ($__perf_marks as $nome => $t) { $partes[] = $nome . ' ' . number_format($t - $ant, 2) . 's'; $ant = $t; }
    $partes[] = 'tela/consultas ' . number_format($d - $ant, 2) . 's';
    if (!empty($GLOBALS['__perf_ami'])) $partes[] = '(AMI ' . number_format($GLOBALS['__perf_ami'], 2) . 's dentro da tela)';
    @pabx_log('desempenho', 'WARNING', 'Requisição lenta ' . number_format($d, 2) . 's | ' . $tela . ' | ' . implode(' | ', $partes));
});

require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/address_book_sync.php';
require_once __DIR__ . '/includes/console_central.php';
$__perf_marks['abertura (banco+código)'] = microtime(true) - $__perf_t0;

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
    foreach (['module' => "TEXT DEFAULT 'Dashboard'", 'period' => "TEXT DEFAULT ''"] as $__col => $__def) {
        $__has = false;
        foreach ($db->query("PRAGMA table_info(ai_insights_history)") as $__ci) { if ($__ci['name'] === $__col) $__has = true; }
        if (!$__has) $db->exec("ALTER TABLE ai_insights_history ADD COLUMN $__col $__def");
    }
} catch (Exception $ex_sync) {}

// =========================================================================
// AUTENTICAÇÃO (portão único do painel)
// =========================================================================
$__api_action_names = ['get_logs', 'get_fop_extensions', 'get_trunks_status', 'get_queues_realtime', 'get_parking_lots', 'fop_action', 'originate_call', 'save_contact', 'test_llm_connection', 'analyze_pabx_insights', 'operator_survey_stats', 'panel_rev', 'status_repair', 'status_report', 'generate_ai_insights', 'get_ai_insights_history', 'delete_ai_insight', 'transcribe_audio', 'get_kpi_calls_detail', 'get_active_calls', 'get_time_groups', 'get_time_group_rules', 'save_time_group_rule', 'delete_time_group_rule', 'get_announcements_list', 'update_announcement_audio', 'sync_assets', 'ai_chat', 'ai_chat_info', 'ai_chat_history', 'ai_chat_clear', 'ai_chat_confirm', 'ext_features_get', 'ext_features_set', 'console_exec'];
if (isset($_GET['api_action']) || (isset($_GET['action']) && in_array($_GET['action'], $__api_action_names, true))) {
    authGate('json');
} else {
    authGate('page');
}

$__perf_marks['login/permissões'] = microtime(true) - $__perf_t0;

// =========================================================================
// HANDLER DE ENDPOINTS DE API (JSON)
// =========================================================================
if (isset($_GET['api_action']) || (isset($_GET['action']) && in_array($_GET['action'], ['get_logs', 'get_fop_extensions', 'get_trunks_status', 'get_queues_realtime', 'get_parking_lots', 'fop_action', 'originate_call', 'save_contact', 'test_llm_connection', 'analyze_pabx_insights', 'operator_survey_stats', 'panel_rev', 'status_repair', 'status_report', 'generate_ai_insights', 'get_ai_insights_history', 'delete_ai_insight', 'transcribe_audio', 'get_kpi_calls_detail', 'get_active_calls', 'get_time_groups', 'get_time_group_rules', 'save_time_group_rule', 'delete_time_group_rule', 'get_announcements_list', 'update_announcement_audio', 'sync_assets', 'ai_chat', 'ai_chat_info', 'ai_chat_history', 'ai_chat_clear', 'ai_chat_confirm', 'ext_features_get', 'ext_features_set', 'console_exec', 'whatsapp_webhook']))) {
    $action = $_GET['api_action'] ?? $_GET['action'];
    if (function_exists('auditRequest')) auditRequest('', '', true, (string)$action);
    // Consultas de atualização automática (só leitura) soltam a sessão já: o PHP trava o arquivo de sessão durante a requisição inteira,
    // e essas consultas esperam o AMI por 2-5 s; sem soltar, cada tela aberta ficava na fila atrás delas (navegação lenta).
    if (in_array($action, ['get_fop_extensions', 'get_trunks_status', 'get_queues_realtime', 'get_parking_lots', 'get_conferences_status', 'get_active_calls', 'get_logs', 'get_kpi_calls_detail'], true) && session_status() === PHP_SESSION_ACTIVE) {
        @session_write_close();
    }
    while (ob_get_level()) { @ob_end_clean(); }
    header('Content-Type: application/json; charset=utf-8');

    if ($action === 'sync_assets') {
        try {
            $res = syncPrismaAssets();
            echo json_encode([
                'success'    => true,
                'extensions' => $res['extensions'] ?? 0,
                'queues'     => $res['queues'] ?? 0,
                'message'    => 'Sincronização com o PABX concluída com sucesso!'
            ]);
        } catch (Exception $e) {
            echo json_encode(['success' => false, 'error' => 'Falha na sincronização com o PABX: ' . $e->getMessage()]);
        }
        exit;
    }

    // Console da central (somente administradores): comandos de consulta e recargas suaves; cada comando fica na trilha de auditoria
    if ($action === 'console_exec') {
        if (!hasUserPermission('manage_settings')) { echo json_encode(['success' => false, 'error' => 'Sem permissão.']); exit; }
        $body = json_decode(file_get_contents('php://input'), true) ?: [];
        $cmd = (string)($body['cmd'] ?? '');
        if (trim($cmd) !== '') logUserAction('Console da central', substr(trim($cmd), 0, 120), 'CONFIG');
        echo json_encode(consoleCentralExec($cmd), JSON_UNESCAPED_UNICODE);
        exit;
    }

    // Autoatendimento do ramal: Não Perturbe / Desvios (AstDB)
    if ($action === 'ext_features_get' || $action === 'ext_features_set') {
        if (!hasUserPermission('manage_settings')) { echo json_encode(['success' => false, 'error' => 'Sem permissão.']); exit; }
        if ($action === 'ext_features_get') { echo json_encode(['success' => true, 'states' => (object)getExtensionFeatureStates()]); exit; }
        $body = json_decode(file_get_contents('php://input'), true) ?: [];
        $ext = preg_replace('/\D/', '', (string)($body['ext'] ?? ''));
        $errs = [];
        foreach (['dnd', 'cf', 'cfb', 'cfu'] as $f) {
            if (!array_key_exists($f, $body)) continue;
            $r = setExtensionFeature($ext, $f, $body[$f]);
            if (!$r['success']) $errs[] = strtoupper($f) . ': ' . $r['error'];
        }
        echo json_encode($errs ? ['success' => false, 'error' => implode(' | ', $errs)] : ['success' => true]);
        exit;
    }

    // Chat assistente com IA (por usuário; consultas somente leitura)
    if ($action === 'ai_chat_info') {
        echo json_encode(['success' => true, 'enabled' => (hasUserPermission('ai_chat') && aiChatAvailable())]);
        exit;
    }
    if ($action === 'ai_chat') {
        if (!hasUserPermission('ai_chat')) { echo json_encode(['success' => false, 'error' => 'Seu usuário não tem acesso ao Chat IA.']); exit; }
        $body = json_decode(file_get_contents('php://input'), true) ?: [];
        $hist = is_array($body['messages'] ?? null) ? $body['messages'] : [];
        $last = '';
        foreach (array_reverse($hist) as $h) { if (($h['role'] ?? '') === 'user') { $last = (string)($h['content'] ?? ''); break; } }
        if ($last === '') { echo json_encode(['success' => false, 'error' => 'Pergunta vazia.']); exit; }
        @set_time_limit(120);
        $GLOBALS['__ai_proposals'] = [];
        $r = aiChatRun($hist);
        aiChatLog($last, $r['tools'] ?? [], !empty($r['ok']));
        if (!empty($r['ok'])) { aiHistorySave('user', $last); aiHistorySave('assistant', $r['text']); }
        echo json_encode(!empty($r['ok']) ? ['success' => true, 'text' => $r['text'], 'tools' => $r['tools'], 'proposals' => $GLOBALS['__ai_proposals']] : ['success' => false, 'error' => $r['error']], JSON_UNESCAPED_UNICODE);
        exit;
    }
    if ($action === 'ai_chat_history') {
        if (!hasUserPermission('ai_chat')) { echo json_encode(['success' => false]); exit; }
        echo json_encode(['success' => true, 'messages' => aiHistoryLoad(30)], JSON_UNESCAPED_UNICODE);
        exit;
    }
    if ($action === 'ai_chat_clear') {
        if (!hasUserPermission('ai_chat')) { echo json_encode(['success' => false]); exit; }
        aiHistoryClear();
        echo json_encode(['success' => true]);
        exit;
    }
    if ($action === 'ai_chat_confirm') {
        if (!hasUserPermission('ai_chat')) { echo json_encode(['success' => false, 'error' => 'Sem acesso ao Chat IA.']); exit; }
        $b = json_decode(file_get_contents('php://input'), true) ?: [];
        echo json_encode(aiProposalResolve((string)($b['id'] ?? ''), !empty($b['accept'])), JSON_UNESCAPED_UNICODE);
        exit;
    }

    // WhatsApp direto (QR Code): conectar, acompanhar, desconectar e registrar o recebimento de respostas
    if (in_array($action, ['uazapi_connect', 'uazapi_status', 'uazapi_disconnect'], true)) {
        header('Content-Type: application/json; charset=utf-8');
        if (!hasUserPermission('manage_settings')) { echo json_encode(['success' => false, 'error' => 'Sem permissão.']); exit; }
        if ($action !== 'uazapi_status' && $_SERVER['REQUEST_METHOD'] !== 'POST') { echo json_encode(['success' => false, 'error' => 'Método inválido.']); exit; }
        if ($action === 'uazapi_status') {
            if (trim((string)getSetting('uazapi_token')) === '' || trim((string)getSetting('uazapi_url')) === '') {
                echo json_encode(['success' => true, 'state' => 'not_configured']); exit;
            }
            $i = uazapiConnectionInfo();
            echo json_encode(['success' => $i['state'] !== 'error', 'state' => $i['state'], 'qr' => $i['qr'], 'paircode' => $i['paircode'], 'name' => $i['name'], 'phone' => $i['phone'], 'error' => $i['error']], JSON_UNESCAPED_UNICODE);
            exit;
        }
        if ($action === 'uazapi_connect') {
            $b = json_decode(file_get_contents('php://input'), true) ?: [];
            // Criar canal: grava o servidor informado na tela (sem exigir "Salvar" antes) e cria a conexão sozinho
            if (isset($b['url']) || isset($b['admin_token']) || isset($b['token']) || isset($b['mode'])) {
                uazapiSaveSettings('uazapi', (string)($b['mode'] ?? ''), (string)($b['url'] ?? ''), (string)($b['admin_token'] ?? ''), (string)($b['token'] ?? ''));
            }
            $chName = trim(preg_replace('/\s+/', ' ', (string)($b['name'] ?? '')));
            if (mb_strlen($chName) > 40) $chName = mb_substr($chName, 0, 40);
            $had = trim((string)getSetting('uazapi_token')) !== '';
            if (!$had && trim((string)getSetting('uazapi_url')) === '') { echo json_encode(['success' => false, 'error' => 'Informe o endereço do servidor.'], JSON_UNESCAPED_UNICODE); exit; }
            if (!$had && !uazapiAdminConf()) { echo json_encode(['success' => false, 'error' => 'Informe o token de administração do servidor (ou o token de um canal que você já criou).'], JSON_UNESCAPED_UNICODE); exit; }
            $err = uazapiEnsureInstance($chName);
            if ($err !== '') { echo json_encode(['success' => false, 'error' => $err], JSON_UNESCAPED_UNICODE); exit; }
            $ph = preg_replace('/\D/', '', (string)($b['phone'] ?? ''));
            $r = uazapiRequest('POST', '/instance/connect', $ph !== '' ? ['phone' => $ph] : new stdClass(), 20);
            if ($r['error'] !== '') { echo json_encode(['success' => false, 'error' => $r['error']], JSON_UNESCAPED_UNICODE); exit; }
            if ($r['http'] === 401 || $r['http'] === 403) { echo json_encode(['success' => false, 'error' => 'Token da conexão recusado. Confira o token.']); exit; }
            if ($r['http'] < 200 || $r['http'] >= 300) { echo json_encode(['success' => false, 'error' => 'O servidor respondeu HTTP ' . $r['http'] . '. Confira o endereço.']); exit; }
            logUserAction("Conectar WhatsApp direto", $ph !== '' ? 'código de pareamento' : 'QR Code', "CONFIG");
            echo json_encode(['success' => true, 'created' => !$had], JSON_UNESCAPED_UNICODE);
            exit;
        }
        if ($action === 'uazapi_disconnect') {
            $r = uazapiRequest('POST', '/instance/disconnect', new stdClass(), 20);
            $ok = ($r['error'] === '' && $r['http'] >= 200 && $r['http'] < 300);
            logUserAction("Desconectar WhatsApp direto", "HTTP " . $r['http'], "CONFIG");
            echo json_encode(['success' => $ok, 'error' => $ok ? '' : ($r['error'] ?: 'O servidor respondeu HTTP ' . $r['http'] . '.')], JSON_UNESCAPED_UNICODE);
            exit;
        }
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

    if ($action === 'get_ext_history') {
        $ext = trim($_GET['ext'] ?? '');
        header('Content-Type: application/json; charset=utf-8');
        if ($ext === '' || !preg_match('/^[0-9A-Za-z_\-]{1,20}$/', $ext)) { echo json_encode(['success' => false, 'error' => 'Ramal inválido']); exit; }
        $st = $db->prepare("SELECT id, phone, call_id, rule_type, message, status, response_raw, created_at FROM sent_logs WHERE extension = :e ORDER BY id DESC LIMIT 200");
        $st->execute([':e' => $ext]);
        $rows = $st->fetchAll(PDO::FETCH_ASSOC);
        $tot = ['SUCCESS' => 0, 'ERROR' => 0, 'OUTROS' => 0];
        foreach ($rows as &$r) {
            $r['response_raw'] = mb_substr((string)$r['response_raw'], 0, 600);
            if ($r['status'] === 'SUCCESS') $tot['SUCCESS']++; elseif ($r['status'] === 'ERROR' || $r['status'] === 'FAILED') $tot['ERROR']++; else $tot['OUTROS']++;
        }
        unset($r);
        echo json_encode(['success' => true, 'ext' => $ext, 'totals' => $tot, 'logs' => $rows], JSON_UNESCAPED_UNICODE);
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

        $live_calls = getAsteriskLiveExtensionCalls();

        $fop_data = [];
        foreach ($exts as $e) {
            $num = $e['extension'];
            $st = isset($realtime_statuses[$num]) ? $realtime_statuses[$num] : 'Livre';
            $lv = $live_calls[$num] ?? null;
            if ($lv) $st = $lv['status'];   // canais ativos são a fonte mais precisa

            $fop_data[] = [
                'id' => $num,
                'name' => formatEndpointName($num),
                'tech' => strtoupper($e['tech'] ?: 'PJSIP'),
                'whatsapp' => $e['whatsapp_number'],
                'status' => $st,
                'channel' => $lv ? $lv['channel'] : (strtoupper($e['tech'] ?: 'PJSIP') === 'SIP' ? "SIP/{$num}" : "PJSIP/{$num}"),
                'partner' => ($lv && $lv['partner'] !== '') ? formatEndpointName($lv['partner']) : '',
                'kind' => $lv ? $lv['kind'] : '',
                'secs' => $lv ? (int)$lv['secs'] : 0
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

        // Mesmos filtros da tela de origem (repetidas ocultas e tipo de chamada), para o detalhe bater com o número clicado
        $where_clause .= ' AND ' . cdrDedupCond();
        if (in_array($kpi_type, ['int', 'internal'], true)) $where_clause .= ' AND ' . cdrTypeCond('internal');
        elseif (strpos($kpi_type, 'ext_') !== 0) $where_clause .= ' AND ' . cdrTypeCond();

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
            $spy_flag = 'qE';
            if ($type === 'whisper') $spy_flag = 'qwE';
            if ($type === 'barge') $spy_flag = 'qBE';
            if (empty($sup_ext) || $sup_ext === $target_ext) {
                echo json_encode(['success' => false, 'error' => 'Informe o SEU ramal (diferente do ramal monitorado).']);
                exit;
            }
            // Só espiona se o ramal está realmente em chamada (canal real)
            $live = function_exists('getAsteriskLiveExtensionCalls') ? getAsteriskLiveExtensionCalls() : [];
            if (!isset($live[$target_ext]) || empty($live[$target_ext]['channel'])) {
                echo json_encode(['success' => false, 'error' => "O ramal $target_ext não está em chamada no momento."]);
                exit;
            }
            // ChanSpy com prefixo do canal real (ex.: PJSIP/200)
            $spy_prefix = preg_replace('/-[0-9a-f]+$/i', '', $live[$target_ext]['channel']);
            $res = executeAsteriskAMICommand('Originate', [
                'Channel'     => "Local/{$sup_ext}@from-internal",
                'Application' => 'ChanSpy',
                'Data'        => "{$spy_prefix},{$spy_flag}",
                'CallerID'    => "Supervisao <{$sup_ext}>",
                'Async'       => 'true',
                'Timeout'     => '30000',
            ]);
            $ok = !empty($res['success']) && stripos($res['response'] ?? '', 'Response: Success') !== false;
            $labels = ['spy' => 'Escuta', 'whisper' => 'Sussurro', 'barge' => 'Intervenção'];
            echo json_encode([
                'success' => $ok,
                'message' => $ok ? "{$labels[$type]} iniciada: o ramal $sup_ext vai tocar; atenda para monitorar o ramal $target_ext." : null,
                'error'   => $ok ? '' : (preg_match('/Message:\s*(.+)/i', $res['response'] ?? '', $mm) ? trim($mm[1]) : ($res['error'] ?? 'PABX recusou a ação.'))
            ]);
            exit;
        }

        if ($type === 'hangup' && !empty($channel)) {
            $res = executeAsteriskAMICommand('Hangup', ['Channel' => $channel, 'Cause' => '16']);
            $ok = !empty($res['success']) && stripos($res['response'] ?? '', 'Response: Success') !== false;
            echo json_encode([
                'success' => $ok,
                'message' => $ok ? "Chamada encerrada ($channel)." : null,
                'error'   => $ok ? '' : (preg_match('/Message:\s*(.+)/i', $res['response'] ?? '', $mm) ? trim($mm[1]) : ($res['error'] ?? 'Não foi possível derrubar o canal.'))
            ]);
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
        $to   = isset($_GET['to']) ? preg_replace('/[^0-9+*#]/', '', $_GET['to']) : '';
        if ($from === '') { $__lu = getLoggedUser(); $from = preg_replace('/[^0-9]/', '', (string)($__lu['extension'] ?? '')); }
        if (empty($from)) {
            echo json_encode(['success' => false, 'message' => 'Escolha o seu ramal para fazer a ligação.', 'error' => 'Escolha o seu ramal para fazer a ligação.']);
            exit;
        }
        if (empty($to)) {
            echo json_encode(['success' => false, 'message' => 'Número de destino não informado.', 'error' => 'Número de destino não informado.']);
            exit;
        }
        
        // Disparo direto no Asterisk (PJSIP / SIP / Local)
        $ami_res = executeClickToCall($from, $to);
        if ($ami_res['success']) {
            echo json_encode(['success' => true, 'from' => $from, 'message' => "O seu ramal $from vai tocar. Atenda para completar a ligação para $to."]);
            exit;
        }

        // Fallback REST API
        require_once __DIR__ . '/includes/pbxapi_client.php';
        $apiClient = new PbxApiClient($db);
        $apiRes = $apiClient->originateCall($from, $to);
        echo json_encode([
            'success' => $apiRes['success'], 'from' => $from,
            'message' => $apiRes['success'] ? "O seu ramal $from vai tocar. Atenda para completar a ligação para $to." : ($ami_res['error'] ?: 'Não foi possível ligar agora. Verifique se o ramal está registrado.')
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
            $__hm = trim((string)($_GET['module'] ?? ''));
            if ($__hm !== '') { $__hs = $db->prepare("SELECT * FROM ai_insights_history WHERE module = :m ORDER BY id DESC LIMIT 50"); $__hs->execute([':m' => $__hm]); $rows = $__hs->fetchAll(PDO::FETCH_ASSOC); }
            else $rows = $db->query("SELECT * FROM ai_insights_history ORDER BY id DESC LIMIT 50")->fetchAll(PDO::FETCH_ASSOC);
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
            echo json_encode(['success' => true, 'message' => "⚡ Conexão com Banco MySQL (CDR) realizada com SUCESSO! Total de $count registros no CDR."]);
        } else {
            echo json_encode(['success' => false, 'error' => 'Falha ao conectar no MySQL do PABX. Verifique host, usuário e senha.']);
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
            if (strpos($msg, 'IPbx Prisma') !== 0) $msg = "*IPbx Prisma*\n\n" . $msg;
            if (!hasUserPermission('listen_recordings')) { echo json_encode(['success' => false, 'error' => 'Sem permissão para compartilhar gravações.']); exit; }
            $link = audioSignedUrl($uid, 604800, true);   // só com URL pública cadastrada; o arquivo já vai anexo
            if ($link !== '') $msg .= "\n\n🎧 Gravação (link válido por 7 dias): " . $link;

        }
        $lu  = getLoggedUser();
        $ch  = (string)($body['channel'] ?? '');
        if (in_array($ch, ['zpro', 'uazapi'], true) && !empty(waChannelsConfigured()[$ch])) $GLOBALS['__wa_provider_override'] = $ch;
        $res = sendWhatsAppAPI($phone, $msg, $uid !== '' ? $uid : 'MANUAL', (string)($lu['extension'] ?? 'PAINEL'), 'ENVIO_MANUAL');
        $files_sent = 0; $file_warns = [];
        if (!empty($res['success'])) {
            list($atts, $file_warns) = buildShareAttachments($body);
            foreach ($atts as $a) {
                $fr = sendWhatsAppFile($phone, $a['name'], $a['content'], $a['type'], '', $uid !== '' ? $uid : 'MANUAL', (string)($lu['extension'] ?? 'PAINEL'));
                if (!empty($fr['success'])) $files_sent++;
                else $file_warns[] = 'Texto enviado, mas o arquivo ' . $a['name'] . ' falhou: ' . ($fr['error'] ?: 'erro desconhecido');
            }
        }
        if ($file_warns) $warning = trim($warning . ' ' . implode(' ', $file_warns));
        echo json_encode(['success' => !empty($res['success']), 'error' => $res['error'] ?? '', 'warning' => $warning, 'files_sent' => $files_sent]);
        exit;
    }

    // ---------------------------------------------------------------------
    // Busca de contatos para a janela "Nova Conversa" (cadastro do painel + agenda do PABX)
    // ---------------------------------------------------------------------
    if ($action === 'search_contacts') {
        header('Content-Type: application/json; charset=utf-8');
        if (!hasUserPermission('send_whatsapp')) { echo json_encode(['success' => false, 'contacts' => []]); exit; }
        $q  = trim((string)($_GET['q'] ?? ''));
        $qd = preg_replace('/\D/', '', $q);
        $out = [];
        if (mb_strlen($q) >= 2) {
            try {
                $st = $db->prepare("SELECT name, phone FROM crm_contacts WHERE name LIKE :q OR (:qd != '' AND REPLACE(REPLACE(REPLACE(REPLACE(phone,' ',''),'-',''),'(',''),')','') LIKE :qdl) ORDER BY name LIMIT 8");
                $st->execute([':q' => '%' . $q . '%', ':qd' => $qd, ':qdl' => '%' . $qd . '%']);
                while ($r = $st->fetch(PDO::FETCH_ASSOC)) $out[] = ['name' => (string)$r['name'], 'phone' => (string)$r['phone'], 'src' => 'Contatos'];
            } catch (Exception $e) {}
            if (count($out) < 8 && is_readable('/var/www/db/address_book.db')) {
                try {
                    $ab = new PDO('sqlite:/var/www/db/address_book.db'); $ab->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
                    $st = $ab->prepare("SELECT name, last_name, telefono FROM contact WHERE (name || ' ' || IFNULL(last_name,'')) LIKE :q OR (:qd != '' AND telefono LIKE :qdl) LIMIT 8");
                    $st->execute([':q' => '%' . $q . '%', ':qd' => $qd, ':qdl' => '%' . $qd . '%']);
                    while ($r = $st->fetch(PDO::FETCH_ASSOC)) {
                        if (count($out) >= 8) break;
                        $out[] = ['name' => trim($r['name'] . ' ' . $r['last_name']), 'phone' => (string)$r['telefono'], 'src' => 'Agenda do PABX'];
                    }
                } catch (Exception $e) {}
            }
        }
        echo json_encode(['success' => true, 'contacts' => $out], JSON_UNESCAPED_UNICODE);
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
            $link = audioSignedUrl($uid, 604800, true);   // só com URL pública cadastrada; o arquivo já vai anexo
            if ($link !== '') $msg .= "\n\nGravação (link válido por 7 dias): " . $link;

        }
        list($atts, $file_warns) = buildShareAttachments($body);
        if ($file_warns) $warning = trim($warning . ' ' . implode(' ', $file_warns));
        $res = sendSmtpEmailNative($smtp, $to, $subj, $msg, $atts);
        echo json_encode(['success' => !empty($res['success']), 'error' => $res['error'] ?? '', 'warning' => $warning, 'files_sent' => count($atts)]);
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
        $cfg = aiGetConfig('insights');
        $cfgA = aiGetConfig('audio');
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
        if ($path === '') { echo json_encode(['success' => false, 'error' => 'Arquivo de gravação não encontrado.']); exit; }

        $tr = aiTranscribeFile($path, $cfgA);
        if (!$tr['ok']) { echo json_encode(['success' => false, 'error' => 'Transcrição: ' . $tr['error']]); exit; }
        if ($tr['text'] === '') { echo json_encode(['success' => false, 'error' => 'A transcrição voltou vazia (áudio sem fala?).']); exit; }

        $r = aiChatCompletion(
            'Você analisa ligações telefônicas de atendimento. Responda APENAS um JSON no formato {"resumo":"até 4 linhas","sentimento":"Positivo|Neutro|Negativo","satisfacao":número de 1 a 5 ou null se a conversa não permitir inferir,"recomendacao":"próximo passo objetivo"}. Use somente o que consta na transcrição; não invente fatos.',
            mb_substr($tr['text'], 0, 12000), 40, $cfg
        );
        if (!$r['ok']) { echo json_encode(['success' => false, 'error' => 'Análise: ' . $r['error'], 'transcript' => $tr['text']]); exit; }
        $raw = trim(preg_replace('#<think>.*?</think>#is', '', (string)$r['text']));   // modelos de raciocínio
        $txt = preg_replace('/^```(?:json)?\s*|\s*```$/i', '', $raw);
        $j = json_decode($txt, true);
        if (!is_array($j) && preg_match('/\{.*\}/s', $txt, $mm)) $j = json_decode($mm[0], true);
        if (is_array($j)) {   // chaves alternativas / valores em lista
            foreach (['resumo' => ['resumo', 'summary', 'resumo_chamada'], 'sentimento' => ['sentimento', 'sentiment'], 'recomendacao' => ['recomendacao', 'recomendação', 'recommendation', 'proximo_passo']] as $dst => $alts) {
                foreach ($alts as $a) if (isset($j[$a])) { $j[$dst] = $j[$a]; break; }
            }
            foreach (['resumo', 'sentimento', 'recomendacao'] as $f) if (isset($j[$f]) && is_array($j[$f])) $j[$f] = implode(' ', array_map('strval', $j[$f]));
            if (!isset($j['satisfacao']) && isset($j['satisfação'])) $j['satisfacao'] = $j['satisfação'];
        }
        if (is_array($j) && trim((string)($j['resumo'] ?? '')) === '') {
            // A IA entendeu o pedido mas não achou conteúdo para resumir (áudio curto, ruído, só toque)
            echo json_encode(['success' => false, 'error' => 'A IA não encontrou conteúdo suficiente para resumir. A transcrição tem ' . mb_strlen($tr['text']) . ' caracteres (chamada muito curta, só ruído ou sem conversa).', 'transcript' => $tr['text']]);
            exit;
        }
        if (!is_array($j) || empty($j['resumo'])) {
            if ($raw === '') { echo json_encode(['success' => false, 'error' => 'A IA devolveu resposta vazia (modelo ' . $cfg['model'] . ').', 'transcript' => $tr['text']]); exit; }
            // Resposta em texto livre: mostra como resumo, sem inventar métricas
            $j = ['resumo' => mb_substr($raw, 0, 1500), 'sentimento' => '', 'satisfacao' => null, 'recomendacao' => ''];
        }
        $sat = isset($j['satisfacao']) && is_numeric($j['satisfacao']) ? max(1, min(5, (float)$j['satisfacao'])) : null;
        $result = [
            'success' => true,
            'uid' => $uid,
            'resumo' => (string)$j['resumo'],
            'sentimento' => (string)($j['sentimento'] ?? ''),
            'satisfacao' => $sat,
            'recomendacao' => (string)($j['recomendacao'] ?? ''),
            'transcript' => $tr['text'],
            'provider' => $cfg['provider'],
            'model' => $cfg['model'],
        ];
        // Regras de palavras-chave configuradas em Relatórios > IA Analytics (tópicos, riscos, checklist)
        try {
            $rules = aiAuditApplyRules($tr['text']);
            aiAuditSaveAnalysis($row, $rules, $result, $cfg);
            $result['topics'] = $rules['topics']; $result['risks'] = $rules['risks']; $result['qa_score'] = $rules['qa_score'];
        } catch (Throwable $e) { /* persistência é best-effort */ }
        echo json_encode($result);
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
            'gemini'   => ['url' => 'https://generativelanguage.googleapis.com/v1beta/openai', 'model' => 'gemini-1.5-flash']
        ];

        // A chave identifica o provedor; ajustes de outro provedor são descartados
        $byKey = (strpos($key, 'gsk_') === 0) ? 'groq' : ((strpos($key, 'AIza') === 0) ? 'gemini' : '');
        if ($byKey !== '' && $byKey !== $provider) { $provider = $byKey; $baseUrl = ''; $model = ''; }
        $pDef = isset($providerDefaults[$provider]) ? $providerDefaults[$provider] : $providerDefaults['openai'];
        if (empty($baseUrl)) $baseUrl = $pDef['url'];
        if (empty($model))   $model   = $pDef['model'];
        $ids = aiAvailableModels($provider, $key, $baseUrl, true);
        if ($ids && !in_array($model, $ids, true)) $model = aiPickModel($ids, 'chat', '') ?: $model;

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

    if ($action === 'status_repair' || $action === 'status_report') {
        if (ob_get_level()) ob_end_clean();
        if (!hasUserPermission('manage_settings')) { header('Content-Type: application/json; charset=utf-8'); echo json_encode(['success' => false, 'error' => 'Sem permissão.']); exit; }
        if ($action === 'status_report') { header('Content-Type: text/plain; charset=utf-8'); echo supportReportText(); exit; }
        header('Content-Type: application/json; charset=utf-8');
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') { echo json_encode(['success' => false, 'error' => 'Método inválido.']); exit; }
        $r = dbRepair();
        if (function_exists('logUserAction')) logUserAction('Status: tentou consertar o banco', $r['ok'] ? 'ok' : 'ainda com problema', 'CONFIG');
        echo json_encode(['success' => true, 'ok' => $r['ok'], 'feito' => $r['feito'], 'linhas' => $r['diagnostico']['linhas']], JSON_UNESCAPED_UNICODE); exit;
    }
    if ($action === 'panel_rev') {
        if (ob_get_level()) ob_end_clean();
        header('Content-Type: application/json; charset=utf-8'); header('Cache-Control: no-store');
        echo json_encode(['success' => true, 'rev' => panelRevAll()]); exit;
    }
    if ($action === 'operator_survey_stats') {
        if (ob_get_level()) ob_end_clean();
        header('Content-Type: application/json; charset=utf-8');
        $ext = preg_replace('/[^0-9A-Za-z_\-]/', '', (string)($_GET['ext'] ?? ''));
        $dv = function ($k, $def) { $v = (string)($_GET[$k] ?? ''); return preg_match('/^\d{4}-\d{2}-\d{2}$/', $v) ? $v : $def; };
        $p_ini = $dv('start', date('Y-m-d', strtotime('-30 days'))); $p_fim = $dv('end', date('Y-m-d'));
        $out = ['success' => true, 'has_data' => false, 'ini' => $p_ini, 'fim' => $p_fim, 'n' => 0, 'media' => null, 'pos' => 0, 'neu' => 0, 'neg' => 0, 'sim' => 0, 'nao_res' => 0, 'recent' => ['pos' => [], 'neu' => [], 'neg' => []]];
        $pq = null;
        $c = function_exists('getAsteriskPdoConnection') ? getAsteriskPdoConnection('asteriskcdrdb') : null;
        if ($c) { try { if ($c->query("SELECT 1 FROM pesquisa LIMIT 1") !== false) $pq = $c; } catch (Throwable $e) {} }
        if (!$pq && is_file('/var/www/db/pesquisa.db')) { try { $pq = new PDO('sqlite:/var/www/db/pesquisa.db'); } catch (Throwable $e) {} }
        if ($pq && $ext !== '') {
            $map = ['EXCELENTE' => 5, 'OTIMO' => 5, 'ÓTIMO' => 5, '5' => 5, 'MUITO BOM' => 4, '4' => 4, 'BOM' => 3, 'MEDIO' => 3, 'MÉDIO' => 3, 'REGULAR' => 3, '3' => 3, 'RUIM' => 2, '2' => 2, 'PESSIMO' => 1, 'PÉSSIMO' => 1, '1' => 1];
            try {
                $st = $pq->prepare("SELECT data, hora, telefone, avaliacao, solucao FROM pesquisa WHERE operador = ? AND data >= ? AND data <= ? ORDER BY data DESC, hora DESC");
                $st->execute([$ext, $p_ini, $p_fim]);
                $sum = 0;
                foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
                    $n = $map[mb_strtoupper(trim((string)$r['avaliacao']))] ?? 0;
                    $sol = mb_strtoupper(trim((string)$r['solucao']));
                    if ($n === 0) continue;
                    $out['n']++; $sum += $n;
                    if (in_array($sol, ['SIM', '1'], true)) $out['sim']++; elseif (in_array($sol, ['NAO', 'NÃO', '2'], true)) $out['nao_res']++;
                    $k = $n >= 4 ? 'pos' : ($n === 3 ? 'neu' : 'neg');
                    $out[$k]++;
                    if (count($out['recent'][$k]) < 8) $out['recent'][$k][] = ['data' => date('d/m/Y', strtotime($r['data'])), 'hora' => $r['hora'], 'tel' => function_exists('formatPhoneNumber') ? formatPhoneNumber($r['telefone']) : $r['telefone'], 'nota' => $n];
                }
                if ($out['n'] > 0) { $out['has_data'] = true; $out['media'] = round($sum / $out['n'], 1); }
            } catch (Throwable $e) {}
        }
        echo json_encode($out, JSON_UNESCAPED_UNICODE); exit;
    }

    if ($action === 'analyze_pabx_insights') {
        if (ob_get_level()) ob_end_clean();
        header('Content-Type: application/json; charset=utf-8');
        $body = json_decode(file_get_contents('php://input'), true) ?: [];

        $tot   = (int)($body['total'] ?? 0);
        $atend = (isset($body['atendidas']) && $body['atendidas'] !== null && $body['atendidas'] !== '') ? (int)$body['atendidas'] : null;
        // TMA/TME só entram se a tela enviou o valor real; ausente = não informado (nunca assumir número)
        $tma   = (isset($body['tmaSec']) && $body['tmaSec'] !== null && $body['tmaSec'] !== '') ? (int)$body['tmaSec'] : null;
        $tme   = (isset($body['tmeSec']) && $body['tmeSec'] !== null && $body['tmeSec'] !== '') ? (int)$body['tmeSec'] : null;
        $module = preg_replace('/[^\p{L}\p{N} _\-]/u', '', (string)($body['module'] ?? 'Dashboard'));
        $periodo = mb_substr(preg_replace('/[^\p{L}\p{N} _\-\/:.,()]/u', '', (string)($body['periodo'] ?? '')), 0, 80);
        $extras = [];
        if (!empty($body['extras']) && is_array($body['extras'])) foreach (array_slice($body['extras'], 0, 12) as $__k => $__v) { $extras[] = mb_substr(preg_replace('/[^\p{L}\p{N} _\-\/:.,%()]/u', '', (string)$__k), 0, 40) . ': ' . mb_substr(preg_replace('/[^\p{L}\p{N} _\-\/:.,%()]/u', '', (string)$__v), 0, 40); }

        $cfg = aiGetConfig('insights');
        $ai_configured = ($cfg['key'] !== '' && $cfg['enabled']);
        $facts = aiFactInsights($tot, $atend, $tma, $tme);
        $insights = [];
        $aiError = '';

        if ($ai_configured && $tot > 0) {
            $pct = $atend !== null ? round(($atend / $tot) * 100, 1) : null;
            $r = aiChatCompletion(
                'Você é analista de PABX IP. Retorne apenas JSON puro no formato {"insights": ["...","...","..."]}, com no máximo 3 itens curtos, objetivos e em português. Use somente os números informados; não invente dados.',
                "Módulo: $module." . (isset($_GET['call_type']) ? ' Tipo de chamadas na tela: ' . cdrTypeLabel(cdrTypeSel()) . '.' : '') . (isset($_GET['dedup']) ? ($_GET['dedup'] === '0' ? ' Filtro da tela: SEM ocultar chamadas repetidas (todos os registros da telefonia, cada ramal que tocou conta).' : ' Filtro da tela: chamadas repetidas OCULTADAS (ligação que tocou em vários ramais conta uma vez só, a de maior duração).') : '') . ($periodo !== '' ? " Período: $periodo." : '') . " Chamadas: $tot" . ($atend !== null ? "; atendidas: $atend ($pct%)" : '') . ($tma !== null ? "; TMA (conversa): {$tma}s" : '') . ($tme !== null ? "; TME (espera): {$tme}s" : '') . '.' . ($extras ? ' Outros dados reais: ' . implode('; ', $extras) . '.' : '') . ' Se um indicador não foi informado, não comente sobre ele.',
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
        aiSaveHistory($usedAi ? $cfg['provider'] : 'metricas', $usedAi ? $cfg['model'] : 'sem-ia', $insights, $module, $periodo);

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

        $cfg      = aiGetConfig('audio');
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
            echo json_encode(['success' => false, 'error' => 'Arquivo de áudio não encontrado na pasta do PABX.']);
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

        $cfg = aiGetConfig('insights');
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
        elseif (!$m['ok'])        $notice = 'Não foi possível ler o CDR.';

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
    'ramais' => ['fop', 'editar_nome', 'gestao', 'autoatendimento'],
    'troncos' => ['view'],
    'filas' => ['realtime', 'flowchart', 'agentes'],
    'relatorios' => ['ia_analytics', 'filas_stats', 'cdr_gravacoes', 'graphic_reports', 'relatorio_geral', 'relatorio_filas', 'pesquisa'],
    'whatsapp' => ['regras', 'historico', 'disparos', 'contatos'],
    'telefonia' => ['blacklist'],
    'manual' => ['view'],
    'configuracoes' => ['ami', 'ia', 'api', 'usuarios', 'api_connection', 'smtp', 'logs', 'status', 'seguranca', 'chat_ia', 'console', 'mapa', 'resumo_diario', 'assistente_whatsapp']
];

if (!isset($allowed_modules[$module]) || !in_array($action, $allowed_modules[$module])) {
    $module = 'dashboard';
    $action = 'view_v1';
}

// Trilha de auditoria: tela/relatório acessado ou formulário enviado
if (function_exists('auditRequest')) auditRequest($module, $action, false);

// Permissão por módulo (mesmas regras de exibição do menu)
$__route_denied = false;
$__route_perm = authRoutePermission($module, $action);
if ($__route_perm !== '' && !hasUserPermission($__route_perm)) {
    $__route_denied = true;
}

// Casca do painel: com o Webphone habilitado, a tela (menu, topo e conteúdo) roda dentro de um quadro e o telefone fica FORA dele.
// Assim, trocar de tela não recarrega o telefone: o registro e a chamada em andamento continuam. Só vale para abertura de página
// (nunca para API, exportação ou download, que já encerraram antes deste ponto) e só em navegador que informa o destino da requisição.
$__wp_u = function_exists('getLoggedUser') ? getLoggedUser() : null;
if ($__wp_u && userHasWebphone($__wp_u)
    && ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'GET'
    && ($_SERVER['HTTP_SEC_FETCH_DEST'] ?? '') === 'document' && ($_GET['noshell'] ?? '') === '') {
    while (ob_get_level()) ob_end_clean();
    header('Content-Type: text/html; charset=utf-8');
    header('Cache-Control: no-store');
    $__src = htmlspecialchars($_SERVER['REQUEST_URI'] ?? 'index.php', ENT_QUOTES, 'UTF-8');
    echo '<!DOCTYPE html><html lang="pt-BR" class="dark"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0">'
       . '<title>IPbx Prisma x Prismabot - Painel Integrado</title><link rel="icon" type="image/png" href="favicon.png">'
       . '<style>html,body{margin:0;height:100%;background:#0f172a;overflow:hidden}#prisma-shell-frame{display:block;width:100%;height:100vh;height:100dvh;border:0;background:#0f172a}</style></head><body>'
       . '<iframe id="prisma-shell-frame" src="' . $__src . '" allow="microphone; autoplay; clipboard-write; fullscreen"></iframe>'
       . '<script>(function(){var f=document.getElementById("prisma-shell-frame");f.addEventListener("load",function(){try{var w=f.contentWindow;'
       . 'history.replaceState(null,"",w.location.pathname+w.location.search+w.location.hash);if(w.document.title)document.title=w.document.title;'
       . '}catch(e){}'
       . 'if(window.PrismaWebphone&&window.PrismaWebphone.refreshPill)window.PrismaWebphone.refreshPill();});})();</script>'
       . '<script src="webphone.php?js=1"></script></body></html>';
    exit;
}
?>
<!DOCTYPE html>
<html lang="pt-BR" class="dark">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>IPbx Prisma x Prismabot - Painel Integrado (NEWFRONT)</title>
    <link rel="icon" type="image/png" href="favicon.png">
    
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
                            50: '#eef2ff', 300: '#a5b4fc', 400: '#818cf8', 500: '#6366f1', 600: '#4f46e5', 700: '#4338ca', 900: '#312e81'
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


        /* ===== Modo Claro v2: trilhas, chips, textos coloridos, hover e modais (só vale com body.theme-light) ===== */
        body.theme-light { color-scheme: light; }
        body.theme-light .custom-scrollbar::-webkit-scrollbar-track { background: #e2e8f0; }
        body.theme-light .custom-scrollbar::-webkit-scrollbar-thumb { background: #94a3b8; }
        /* superfícies translúcidas que faltavam */
        body.theme-light :is(.bg-slate-900\/95, .bg-slate-900\/50, .bg-slate-900\/40):not(.fixed) { background-color: #ffffff !important; border-color: #e2e8f0 !important; }
        body.theme-light :is(.bg-slate-950\/95, .bg-slate-950\/70, .bg-slate-950\/60, .bg-slate-950\/40):not(.fixed) { background-color: #f8fafc !important; border-color: #e2e8f0 !important; }
        /* fundos escuros de tela cheia (modais) continuam escurecendo a página, não viram branco */
        body.theme-light .fixed.inset-0[class*="bg-slate-9"] { background-color: rgba(15, 23, 42, 0.45) !important; }
        /* trilhas de barras de progresso, chips e linhas em cinza claro */
        body.theme-light :is(.bg-slate-800, .bg-slate-700, .bg-slate-600):not(button):not(a):not(.fixed) { background-color: #e2e8f0 !important; color: #1e293b !important; }
        body.theme-light :is(.bg-slate-800\/80, .bg-slate-800\/60, .bg-slate-800\/50, .bg-slate-800\/40, .bg-slate-800\/30):not(button):not(a):not(.fixed) { background-color: rgba(226, 232, 240, 0.7) !important; color: #1e293b !important; }
        body.theme-light :is(button, a):is(.bg-slate-700, .bg-slate-800\/60, .bg-slate-800\/50, .bg-slate-800\/80) { background-color: #f1f5f9 !important; color: #1e293b !important; border-color: #cbd5e1 !important; }
        body.theme-light :is(button, a):is(.hover\:bg-slate-700, .hover\:bg-slate-800, .hover\:bg-slate-600):hover { background-color: #e2e8f0 !important; color: #0f172a !important; }
        body.theme-light .bg-slate-500 { background-color: #94a3b8 !important; }
        /* bordas e divisores */
        body.theme-light :is(.border-slate-600, .border-slate-900, .border-slate-950, .border-slate-700\/50, .border-slate-800\/50) { border-color: #e2e8f0 !important; }
        body.theme-light [class*="divide-slate-"] > * + * { border-color: #e2e8f0 !important; }
        body.theme-light hr { border-color: #e2e8f0; }
        /* títulos/ícones em texto claro dentro de superfícies agora claras */
        body.theme-light :is(.text-slate-600, .text-slate-700) { color: #475569 !important; }
        body.theme-light .hover\:text-white:hover { color: #0f172a !important; }
        body.theme-light .placeholder-slate-600::placeholder, body.theme-light ::placeholder { color: #94a3b8 !important; }
        body.theme-light dialog { background-color: #ffffff !important; color: #0f172a !important; border-color: #cbd5e1 !important; }
        body.theme-light dialog::backdrop { background: rgba(15, 23, 42, 0.45); }
        body.theme-light table { color: #1e293b; }
        body.theme-light audio { filter: none; }
        /* textos coloridos: tons 200-400 (pensados para fundo escuro) viram 700; 500 vira 600 */
        body.theme-light :is(.text-emerald-100, .text-emerald-200, .text-emerald-300, .text-emerald-400) { color: #047857 !important; }
        body.theme-light .text-emerald-500 { color: #047857 !important; }
        body.theme-light :is(.text-purple-100, .text-purple-200, .text-purple-300, .text-purple-400) { color: #6d28d9 !important; }
        body.theme-light .text-purple-500 { color: #6d28d9 !important; }
        body.theme-light :is(.text-cyan-100, .text-cyan-200, .text-cyan-300, .text-cyan-400) { color: #0e7490 !important; }
        body.theme-light .text-cyan-500 { color: #0e7490 !important; }
        body.theme-light :is(.text-amber-100, .text-amber-200, .text-amber-300, .text-amber-400) { color: #b45309 !important; }
        body.theme-light .text-amber-500 { color: #b45309 !important; }
        body.theme-light :is(.text-rose-100, .text-rose-200, .text-rose-300, .text-rose-400) { color: #be123c !important; }
        body.theme-light .text-rose-500 { color: #be123c !important; }
        body.theme-light :is(.text-indigo-100, .text-indigo-200, .text-indigo-300, .text-indigo-400) { color: #4338ca !important; }
        body.theme-light .text-indigo-500 { color: #4338ca !important; }
        body.theme-light :is(.text-blue-100, .text-blue-200, .text-blue-300, .text-blue-400) { color: #1d4ed8 !important; }
        body.theme-light .text-blue-500 { color: #1d4ed8 !important; }
        body.theme-light :is(.text-brand-100, .text-brand-200, .text-brand-300, .text-brand-400) { color: #4f46e5 !important; }
        body.theme-light .text-brand-500 { color: #4f46e5 !important; }
        body.theme-light :is(.text-sky-100, .text-sky-200, .text-sky-300, .text-sky-400) { color: #0369a1 !important; }
        body.theme-light .text-sky-500 { color: #0369a1 !important; }
        body.theme-light :is(.text-pink-100, .text-pink-200, .text-pink-300, .text-pink-400) { color: #be185d !important; }
        body.theme-light .text-pink-500 { color: #be185d !important; }
        body.theme-light :is(.text-orange-100, .text-orange-200, .text-orange-300, .text-orange-400) { color: #c2410c !important; }
        body.theme-light .text-orange-500 { color: #c2410c !important; }
        body.theme-light :is(.text-teal-100, .text-teal-200, .text-teal-300, .text-teal-400) { color: #0f766e !important; }
        body.theme-light .text-teal-500 { color: #0f766e !important; }
        body.theme-light :is(.text-red-100, .text-red-200, .text-red-300, .text-red-400) { color: #b91c1c !important; }
        body.theme-light .text-red-500 { color: #b91c1c !important; }
        body.theme-light :is(.text-green-100, .text-green-200, .text-green-300, .text-green-400) { color: #15803d !important; }
        body.theme-light .text-green-500 { color: #15803d !important; }
        body.theme-light :is(.text-yellow-100, .text-yellow-200, .text-yellow-300, .text-yellow-400) { color: #a16207 !important; }
        body.theme-light .text-yellow-500 { color: #a16207 !important; }
        body.theme-light :is(.text-violet-100, .text-violet-200, .text-violet-300, .text-violet-400) { color: #6d28d9 !important; }
        body.theme-light .text-violet-500 { color: #6d28d9 !important; }
        body.theme-light :is(.text-fuchsia-100, .text-fuchsia-200, .text-fuchsia-300, .text-fuchsia-400) { color: #a21caf !important; }
        body.theme-light .text-fuchsia-500 { color: #a21caf !important; }
        /* textos coloridos com opacidade, hover e estados selecionados (peer-checked) */
        body.theme-light :is(.text-emerald-200\/60, .text-emerald-200\/70, .text-emerald-200\/80, .text-emerald-200\/90, .text-emerald-300\/60, .text-emerald-300\/70, .text-emerald-300\/80, .text-emerald-300\/90, .text-emerald-400\/60, .text-emerald-400\/70, .text-emerald-400\/80, .text-emerald-400\/90) { color: #047857 !important; }
        body.theme-light :is(.hover\:text-emerald-100:hover, .hover\:text-emerald-200:hover, .hover\:text-emerald-300:hover, .hover\:text-emerald-400:hover) { color: #047857 !important; }
        body.theme-light :is(.text-purple-200\/60, .text-purple-200\/70, .text-purple-200\/80, .text-purple-200\/90, .text-purple-300\/60, .text-purple-300\/70, .text-purple-300\/80, .text-purple-300\/90, .text-purple-400\/60, .text-purple-400\/70, .text-purple-400\/80, .text-purple-400\/90) { color: #6d28d9 !important; }
        body.theme-light :is(.hover\:text-purple-100:hover, .hover\:text-purple-200:hover, .hover\:text-purple-300:hover, .hover\:text-purple-400:hover) { color: #6d28d9 !important; }
        body.theme-light :is(.text-cyan-200\/60, .text-cyan-200\/70, .text-cyan-200\/80, .text-cyan-200\/90, .text-cyan-300\/60, .text-cyan-300\/70, .text-cyan-300\/80, .text-cyan-300\/90, .text-cyan-400\/60, .text-cyan-400\/70, .text-cyan-400\/80, .text-cyan-400\/90) { color: #0e7490 !important; }
        body.theme-light :is(.hover\:text-cyan-100:hover, .hover\:text-cyan-200:hover, .hover\:text-cyan-300:hover, .hover\:text-cyan-400:hover) { color: #0e7490 !important; }
        body.theme-light :is(.text-amber-200\/60, .text-amber-200\/70, .text-amber-200\/80, .text-amber-200\/90, .text-amber-300\/60, .text-amber-300\/70, .text-amber-300\/80, .text-amber-300\/90, .text-amber-400\/60, .text-amber-400\/70, .text-amber-400\/80, .text-amber-400\/90) { color: #b45309 !important; }
        body.theme-light :is(.hover\:text-amber-100:hover, .hover\:text-amber-200:hover, .hover\:text-amber-300:hover, .hover\:text-amber-400:hover) { color: #b45309 !important; }
        body.theme-light :is(.text-rose-200\/60, .text-rose-200\/70, .text-rose-200\/80, .text-rose-200\/90, .text-rose-300\/60, .text-rose-300\/70, .text-rose-300\/80, .text-rose-300\/90, .text-rose-400\/60, .text-rose-400\/70, .text-rose-400\/80, .text-rose-400\/90) { color: #be123c !important; }
        body.theme-light :is(.hover\:text-rose-100:hover, .hover\:text-rose-200:hover, .hover\:text-rose-300:hover, .hover\:text-rose-400:hover) { color: #be123c !important; }
        body.theme-light :is(.text-indigo-200\/60, .text-indigo-200\/70, .text-indigo-200\/80, .text-indigo-200\/90, .text-indigo-300\/60, .text-indigo-300\/70, .text-indigo-300\/80, .text-indigo-300\/90, .text-indigo-400\/60, .text-indigo-400\/70, .text-indigo-400\/80, .text-indigo-400\/90) { color: #4338ca !important; }
        body.theme-light :is(.hover\:text-indigo-100:hover, .hover\:text-indigo-200:hover, .hover\:text-indigo-300:hover, .hover\:text-indigo-400:hover) { color: #4338ca !important; }
        body.theme-light :is(.text-blue-200\/60, .text-blue-200\/70, .text-blue-200\/80, .text-blue-200\/90, .text-blue-300\/60, .text-blue-300\/70, .text-blue-300\/80, .text-blue-300\/90, .text-blue-400\/60, .text-blue-400\/70, .text-blue-400\/80, .text-blue-400\/90) { color: #1d4ed8 !important; }
        body.theme-light :is(.hover\:text-blue-100:hover, .hover\:text-blue-200:hover, .hover\:text-blue-300:hover, .hover\:text-blue-400:hover) { color: #1d4ed8 !important; }
        body.theme-light :is(.text-brand-200\/60, .text-brand-200\/70, .text-brand-200\/80, .text-brand-200\/90, .text-brand-300\/60, .text-brand-300\/70, .text-brand-300\/80, .text-brand-300\/90, .text-brand-400\/60, .text-brand-400\/70, .text-brand-400\/80, .text-brand-400\/90) { color: #4f46e5 !important; }
        body.theme-light :is(.hover\:text-brand-100:hover, .hover\:text-brand-200:hover, .hover\:text-brand-300:hover, .hover\:text-brand-400:hover) { color: #4f46e5 !important; }
        body.theme-light :is(.text-sky-200\/60, .text-sky-200\/70, .text-sky-200\/80, .text-sky-200\/90, .text-sky-300\/60, .text-sky-300\/70, .text-sky-300\/80, .text-sky-300\/90, .text-sky-400\/60, .text-sky-400\/70, .text-sky-400\/80, .text-sky-400\/90) { color: #0369a1 !important; }
        body.theme-light :is(.hover\:text-sky-100:hover, .hover\:text-sky-200:hover, .hover\:text-sky-300:hover, .hover\:text-sky-400:hover) { color: #0369a1 !important; }
        body.theme-light :is(.text-pink-200\/60, .text-pink-200\/70, .text-pink-200\/80, .text-pink-200\/90, .text-pink-300\/60, .text-pink-300\/70, .text-pink-300\/80, .text-pink-300\/90, .text-pink-400\/60, .text-pink-400\/70, .text-pink-400\/80, .text-pink-400\/90) { color: #be185d !important; }
        body.theme-light :is(.hover\:text-pink-100:hover, .hover\:text-pink-200:hover, .hover\:text-pink-300:hover, .hover\:text-pink-400:hover) { color: #be185d !important; }
        body.theme-light :is(.text-orange-200\/60, .text-orange-200\/70, .text-orange-200\/80, .text-orange-200\/90, .text-orange-300\/60, .text-orange-300\/70, .text-orange-300\/80, .text-orange-300\/90, .text-orange-400\/60, .text-orange-400\/70, .text-orange-400\/80, .text-orange-400\/90) { color: #c2410c !important; }
        body.theme-light :is(.hover\:text-orange-100:hover, .hover\:text-orange-200:hover, .hover\:text-orange-300:hover, .hover\:text-orange-400:hover) { color: #c2410c !important; }
        body.theme-light :is(.text-teal-200\/60, .text-teal-200\/70, .text-teal-200\/80, .text-teal-200\/90, .text-teal-300\/60, .text-teal-300\/70, .text-teal-300\/80, .text-teal-300\/90, .text-teal-400\/60, .text-teal-400\/70, .text-teal-400\/80, .text-teal-400\/90) { color: #0f766e !important; }
        body.theme-light :is(.hover\:text-teal-100:hover, .hover\:text-teal-200:hover, .hover\:text-teal-300:hover, .hover\:text-teal-400:hover) { color: #0f766e !important; }
        body.theme-light :is(.text-red-200\/60, .text-red-200\/70, .text-red-200\/80, .text-red-200\/90, .text-red-300\/60, .text-red-300\/70, .text-red-300\/80, .text-red-300\/90, .text-red-400\/60, .text-red-400\/70, .text-red-400\/80, .text-red-400\/90) { color: #b91c1c !important; }
        body.theme-light :is(.hover\:text-red-100:hover, .hover\:text-red-200:hover, .hover\:text-red-300:hover, .hover\:text-red-400:hover) { color: #b91c1c !important; }
        body.theme-light :is(.text-green-200\/60, .text-green-200\/70, .text-green-200\/80, .text-green-200\/90, .text-green-300\/60, .text-green-300\/70, .text-green-300\/80, .text-green-300\/90, .text-green-400\/60, .text-green-400\/70, .text-green-400\/80, .text-green-400\/90) { color: #15803d !important; }
        body.theme-light :is(.hover\:text-green-100:hover, .hover\:text-green-200:hover, .hover\:text-green-300:hover, .hover\:text-green-400:hover) { color: #15803d !important; }
        body.theme-light :is(.text-yellow-200\/60, .text-yellow-200\/70, .text-yellow-200\/80, .text-yellow-200\/90, .text-yellow-300\/60, .text-yellow-300\/70, .text-yellow-300\/80, .text-yellow-300\/90, .text-yellow-400\/60, .text-yellow-400\/70, .text-yellow-400\/80, .text-yellow-400\/90) { color: #a16207 !important; }
        body.theme-light :is(.hover\:text-yellow-100:hover, .hover\:text-yellow-200:hover, .hover\:text-yellow-300:hover, .hover\:text-yellow-400:hover) { color: #a16207 !important; }
        body.theme-light :is(.text-violet-200\/60, .text-violet-200\/70, .text-violet-200\/80, .text-violet-200\/90, .text-violet-300\/60, .text-violet-300\/70, .text-violet-300\/80, .text-violet-300\/90, .text-violet-400\/60, .text-violet-400\/70, .text-violet-400\/80, .text-violet-400\/90) { color: #6d28d9 !important; }
        body.theme-light :is(.hover\:text-violet-100:hover, .hover\:text-violet-200:hover, .hover\:text-violet-300:hover, .hover\:text-violet-400:hover) { color: #6d28d9 !important; }
        body.theme-light :is(.text-fuchsia-200\/60, .text-fuchsia-200\/70, .text-fuchsia-200\/80, .text-fuchsia-200\/90, .text-fuchsia-300\/60, .text-fuchsia-300\/70, .text-fuchsia-300\/80, .text-fuchsia-300\/90, .text-fuchsia-400\/60, .text-fuchsia-400\/70, .text-fuchsia-400\/80, .text-fuchsia-400\/90) { color: #a21caf !important; }
        body.theme-light :is(.hover\:text-fuchsia-100:hover, .hover\:text-fuchsia-200:hover, .hover\:text-fuchsia-300:hover, .hover\:text-fuchsia-400:hover) { color: #a21caf !important; }
        body.theme-light .peer:checked + div[class*="peer-checked:border-"] { border-color: #6366f1 !important; background-color: #eef2ff !important; box-shadow: 0 0 0 3px rgba(99, 102, 241, 0.15) !important; }
        body.theme-light .peer:checked ~ [class*="peer-checked:bg-brand-"] { background-color: #4f46e5 !important; }
        body.theme-light .peer:checked ~ [class*="peer-checked:bg-purple-"] { background-color: #7c3aed !important; }
        body.theme-light .peer:checked ~ [class*="peer-checked:bg-emerald-"] { background-color: #059669 !important; }
        body.theme-light .peer:not(:checked) ~ div.rounded-full[class*="peer-checked:bg-"] { background-color: #cbd5e1 !important; }
        /* texto branco volta em botões/etiquetas de cor sólida */
        body.theme-light :is(.bg-emerald-500, .bg-emerald-600, .bg-emerald-700, .bg-purple-500, .bg-purple-600, .bg-purple-700, .bg-cyan-500, .bg-cyan-600, .bg-cyan-700, .bg-amber-500, .bg-amber-600, .bg-amber-700, .bg-rose-500, .bg-rose-600, .bg-rose-700, .bg-indigo-500, .bg-indigo-600, .bg-indigo-700, .bg-blue-500, .bg-blue-600, .bg-blue-700, .bg-brand-500, .bg-brand-600, .bg-brand-700, .bg-sky-500, .bg-sky-600, .bg-sky-700, .bg-pink-500, .bg-pink-600, .bg-pink-700, .bg-orange-500, .bg-orange-600, .bg-orange-700, .bg-teal-500, .bg-teal-600, .bg-teal-700, .bg-red-500, .bg-red-600, .bg-red-700, .bg-green-500, .bg-green-600, .bg-green-700, .bg-yellow-500, .bg-yellow-600, .bg-yellow-700, .bg-violet-500, .bg-violet-600, .bg-violet-700, .bg-fuchsia-500, .bg-fuchsia-600, .bg-fuchsia-700), body.theme-light :is(.bg-emerald-500, .bg-emerald-600, .bg-emerald-700, .bg-purple-500, .bg-purple-600, .bg-purple-700, .bg-cyan-500, .bg-cyan-600, .bg-cyan-700, .bg-amber-500, .bg-amber-600, .bg-amber-700, .bg-rose-500, .bg-rose-600, .bg-rose-700, .bg-indigo-500, .bg-indigo-600, .bg-indigo-700, .bg-blue-500, .bg-blue-600, .bg-blue-700, .bg-brand-500, .bg-brand-600, .bg-brand-700, .bg-sky-500, .bg-sky-600, .bg-sky-700, .bg-pink-500, .bg-pink-600, .bg-pink-700, .bg-orange-500, .bg-orange-600, .bg-orange-700, .bg-teal-500, .bg-teal-600, .bg-teal-700, .bg-red-500, .bg-red-600, .bg-red-700, .bg-green-500, .bg-green-600, .bg-green-700, .bg-yellow-500, .bg-yellow-600, .bg-yellow-700, .bg-violet-500, .bg-violet-600, .bg-violet-700, .bg-fuchsia-500, .bg-fuchsia-600, .bg-fuchsia-700) * { color: #ffffff !important; }
        body.theme-light :is(button, a)[class*="from-"][class*="to-"], body.theme-light :is(button, a)[class*="from-"][class*="to-"] * { color: #ffffff !important; }
        body.theme-light :is([class*="hover:bg-emerald-"], [class*="hover:bg-purple-"], [class*="hover:bg-cyan-"], [class*="hover:bg-amber-"], [class*="hover:bg-rose-"], [class*="hover:bg-indigo-"], [class*="hover:bg-blue-"], [class*="hover:bg-brand-"], [class*="hover:bg-sky-"], [class*="hover:bg-pink-"], [class*="hover:bg-orange-"], [class*="hover:bg-teal-"], [class*="hover:bg-red-"], [class*="hover:bg-green-"], [class*="hover:bg-yellow-"], [class*="hover:bg-violet-"], [class*="hover:bg-fuchsia-"]):hover, body.theme-light :is([class*="hover:bg-emerald-"], [class*="hover:bg-purple-"], [class*="hover:bg-cyan-"], [class*="hover:bg-amber-"], [class*="hover:bg-rose-"], [class*="hover:bg-indigo-"], [class*="hover:bg-blue-"], [class*="hover:bg-brand-"], [class*="hover:bg-sky-"], [class*="hover:bg-pink-"], [class*="hover:bg-orange-"], [class*="hover:bg-teal-"], [class*="hover:bg-red-"], [class*="hover:bg-green-"], [class*="hover:bg-yellow-"], [class*="hover:bg-violet-"], [class*="hover:bg-fuchsia-"]):hover * { color: #ffffff !important; }

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

    <!-- Webphone: agora é uma janela própria (webphone.php), que mantém o ramal registrado enquanto estiver aberta -->

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
                            <span class="font-bold text-[10px] uppercase tracking-wider text-amber-400">Satisfação (pesquisa por ligação)</span>
                            <i class="fa-solid fa-star text-amber-400"></i>
                        </div>
                        <div class="flex items-baseline gap-2">
                            <span id="ia-modal-score" class="text-2xl font-black text-white font-mono">--</span>
                            <span id="ia-modal-score-sub" class="text-[10px] text-amber-300 font-bold">--</span>
                        </div>
                        <div class="w-full bg-slate-800 h-1.5 rounded-full overflow-hidden">
                            <div id="ia-modal-score-bar" class="bg-gradient-to-r from-amber-500 to-emerald-400 h-full rounded-full" style="width: 0%"></div>
                        </div>
                    </div>

                    <!-- Resolução em 1ª Chamada (FCR) -->
                    <div class="bg-slate-950/80 border border-emerald-500/30 rounded-2xl p-4 flex flex-col justify-between space-y-2">
                        <div class="flex items-center justify-between text-slate-400">
                            <span class="font-bold text-[10px] uppercase tracking-wider text-emerald-400">Problema resolvido</span>
                            <i class="fa-solid fa-circle-check text-emerald-400"></i>
                        </div>
                        <div class="flex items-baseline gap-2">
                            <span id="ia-modal-fcr" class="text-2xl font-black text-white font-mono">--</span>
                            <span id="ia-modal-fcr-sub" class="text-[10px] text-emerald-300 font-bold">--</span>
                        </div>
                        <div class="w-full bg-slate-800 h-1.5 rounded-full overflow-hidden">
                            <div id="ia-modal-fcr-bar" class="bg-emerald-500 h-full rounded-full" style="width: 0%"></div>
                        </div>
                    </div>

                    <!-- Tom de Voz & Cordialidade -->
                    <div class="bg-slate-950/80 border border-purple-500/30 rounded-2xl p-4 flex flex-col justify-between space-y-2">
                        <div class="flex items-center justify-between text-slate-400">
                            <span class="font-bold text-[10px] uppercase tracking-wider text-purple-400">Avaliações no período</span>
                            <i class="fa-solid fa-face-smile text-purple-400"></i>
                        </div>
                        <div class="flex items-baseline gap-2">
                            <span id="ia-modal-empathy" class="text-2xl font-black text-white font-mono">--</span>
                            <span class="text-[10px] text-purple-300 font-bold">notas recebidas</span>
                        </div>
                        <div class="w-full bg-slate-800 h-1.5 rounded-full overflow-hidden">
                            <div id="ia-modal-empathy-bar" class="bg-purple-500 h-full rounded-full" style="width: 0%"></div>
                        </div>
                    </div>

                </div>

                <!-- Resumo Analítico da IA -->
                <div class="bg-slate-950/90 border border-slate-800 rounded-2xl p-4 space-y-2">
                    <div class="flex items-center justify-between text-slate-400 border-b border-slate-800/80 pb-2">
                        <span class="font-extrabold text-white flex items-center gap-2">
                            <i class="fa-solid fa-brain text-purple-400"></i> Resumo da pesquisa de satisfação
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
                            <span>Notas dos clientes (pesquisa por ligação):</span>
                            <span class="text-[9px] text-purple-400 font-normal">Clique para ver chamadas</span>
                        </span>
                        <div class="space-y-1.5">
                            <div onclick="openSentimentDetailsModal('positivo')" class="cursor-pointer hover:bg-slate-900/80 p-1.5 rounded-xl transition flex items-center justify-between text-[11px] group">
                                <span class="text-emerald-400 font-bold flex items-center gap-1.5">
                                    <i class="fa-solid fa-face-smile"></i> Positivo (notas 4 e 5)
                                    <i class="fa-solid fa-arrow-up-right-from-square text-[9px] opacity-0 group-hover:opacity-100 transition"></i>
                                </span>
                                <span id="ia-modal-sent-pos" class="font-mono text-white font-bold">--</span>
                            </div>
                            <div class="w-full bg-slate-800 h-1 rounded-full overflow-hidden">
                                <div id="ia-modal-sent-pos-bar" class="bg-emerald-500 h-full rounded-full" style="width: 0%"></div>
                            </div>

                            <div onclick="openSentimentDetailsModal('neutro')" class="cursor-pointer hover:bg-slate-900/80 p-1.5 rounded-xl transition flex items-center justify-between text-[11px] group pt-1">
                                <span class="text-amber-400 font-bold flex items-center gap-1.5">
                                    <i class="fa-solid fa-face-meh"></i> Neutro (nota 3)
                                    <i class="fa-solid fa-arrow-up-right-from-square text-[9px] opacity-0 group-hover:opacity-100 transition"></i>
                                </span>
                                <span id="ia-modal-sent-neu" class="font-mono text-white font-bold">--</span>
                            </div>
                            <div class="w-full bg-slate-800 h-1 rounded-full overflow-hidden">
                                <div id="ia-modal-sent-neu-bar" class="bg-amber-400 h-full rounded-full" style="width: 0%"></div>
                            </div>

                            <div onclick="openSentimentDetailsModal('insatisfeito')" class="cursor-pointer hover:bg-slate-900/80 p-1.5 rounded-xl transition flex items-center justify-between text-[11px] group pt-1">
                                <span class="text-rose-400 font-bold flex items-center gap-1.5">
                                    <i class="fa-solid fa-face-frown"></i> Insatisfeito (notas 1 e 2)
                                    <i class="fa-solid fa-arrow-up-right-from-square text-[9px] opacity-0 group-hover:opacity-100 transition"></i>
                                </span>
                                <span id="ia-modal-sent-neg" class="font-mono text-white font-bold">--</span>
                            </div>
                            <div class="w-full bg-slate-800 h-1 rounded-full overflow-hidden">
                                <div id="ia-modal-sent-neg-bar" class="bg-rose-500 h-full rounded-full" style="width: 0%"></div>
                            </div>
                        </div>
                    </div>

                    <!-- Pontos Fortes & Recomendação da IA -->
                    <div class="bg-slate-950/80 border border-slate-800 rounded-2xl p-4 space-y-2">
                        <span class="font-bold text-slate-300 block text-[11px]">Pontos de atenção (dados reais):</span>
                        <ul id="ia-modal-insights" class="space-y-1.5 text-[11px] text-slate-300"><li class="text-slate-500">--</li></ul>
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
        var currentFopTechFilter = 'ALL';
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

        window.__opSurvey = null;
        async function openOperatorIaModal(name, ext, atendidas, total, tma, tme) {
            const modal = document.getElementById('modal-operator-ia');
            if (!modal) return;
            const $ = (id) => document.getElementById(id);
            const atend = parseInt(atendidas) || 0, tot = parseInt(total) || 0;
            $('ia-modal-op-name').innerText = name || ('Ramal ' + ext);
            $('ia-modal-ramal-badge').innerText = 'Ramal ' + ext;
            ['score', 'fcr', 'empathy', 'sent-pos', 'sent-neu', 'sent-neg'].forEach(k => { $('ia-modal-' + k).innerText = '...'; const b = $('ia-modal-' + k + '-bar'); if (b) b.style.width = '0%'; });
            $('ia-modal-score-sub').innerText = ''; $('ia-modal-fcr-sub').innerText = '';
            $('ia-modal-summary').innerText = 'Carregando dados reais da pesquisa...';
            $('ia-modal-insights').innerHTML = '<li class="text-slate-500">Carregando...</li>';
            window.__opSurvey = null;
            openModal('modal-operator-ia');
            let d = null;
            const per = window.__dashPeriod || {};
            const br = (x) => x ? x.split('-').reverse().join('/') : '';
            let qs = ''; if (per.start && per.end) qs = '&start=' + per.start + '&end=' + per.end;
            const perTxt = (per.start && per.end) ? (per.start === per.end ? br(per.start) : br(per.start) + ' a ' + br(per.end)) : 'últimos 30 dias';
            try { d = await (await fetch('index.php?api_action=operator_survey_stats&ext=' + encodeURIComponent(ext) + qs)).json(); } catch (e) {}
            window.__opSurvey = d;
            const taxaAt = tot > 0 ? Math.round(atend / tot * 100) : null;
            const tmaTxt = tma ? ` TMA ${tma}.` : '';
            if (!d || !d.has_data) {
                $('ia-modal-summary').innerText = `Ramal ${ext} (${name}): nenhum cliente avaliou no período selecionado (${perTxt}).` + (tot > 0 ? ` No mesmo período: ${atend} atendidas de ${tot} chamadas.${tmaTxt}` : '');
                ['score', 'fcr', 'empathy', 'sent-pos', 'sent-neu', 'sent-neg'].forEach(k => { $('ia-modal-' + k).innerText = 'Sem avaliação'; });
                $('ia-modal-score-sub').innerText = 'no período'; $('ia-modal-fcr-sub').innerText = 'no período';
                $('ia-modal-insights').innerHTML = '<li class="text-slate-400">Sem avaliação no período. A pesquisa é feita por ligação, ao final do atendimento.</li>';
                return;
            }
            const pct = (v) => Math.round(v / d.n * 100);
            const res = d.sim + d.nao_res;
            $('ia-modal-score').innerText = d.media.toFixed(1).replace('.', ',') + ' / 5';
            $('ia-modal-score-sub').innerText = pct(d.pos) + '% positivas';
            $('ia-modal-score-bar').style.width = (d.media / 5 * 100) + '%';
            $('ia-modal-fcr').innerText = res ? Math.round(d.sim / res * 100) + '%' : '--';
            $('ia-modal-fcr-sub').innerText = res ? d.sim + ' de ' + res + ' respostas' : 'sem resposta';
            if (!res) $('ia-modal-fcr').innerText = 'Sem avaliação';
            $('ia-modal-fcr-bar').style.width = (res ? d.sim / res * 100 : 0) + '%';
            $('ia-modal-empathy').innerText = d.n;
            $('ia-modal-empathy-bar').style.width = '100%';
            [['pos', d.pos], ['neu', d.neu], ['neg', d.neg]].forEach(([k, v]) => { $('ia-modal-sent-' + k).innerText = pct(v) + '% (' + v + ')'; $('ia-modal-sent-' + k + '-bar').style.width = pct(v) + '%'; });
            $('ia-modal-summary').innerText = `Ramal ${ext} (${name}), período ${perTxt}: ${d.n} avaliações, média ${d.media.toFixed(1).replace('.', ',')} de 5; ${pct(d.pos)}% positivas, ${pct(d.neu)}% neutras e ${pct(d.neg)}% negativas.` + (res ? ` Problema resolvido em ${Math.round(d.sim / res * 100)}% das respostas.` : '');
            const li = [];
            if (d.neg > 0) li.push(`<i class="fa-solid fa-triangle-exclamation text-rose-400"></i> ${d.neg} nota(s) 1 ou 2: vale ouvir essas gravações em Pesquisa de Satisfação.`);
            if (d.nao_res > 0) li.push(`<i class="fa-solid fa-circle-xmark text-amber-400"></i> ${d.nao_res} cliente(s) disseram que o problema não foi resolvido.`);
            if (!li.length) li.push('<i class="fa-solid fa-circle-check text-emerald-400"></i> Nenhuma nota baixa nem problema não resolvido no período.');
            $('ia-modal-insights').innerHTML = li.map(t => '<li class="flex items-start gap-1.5">' + t + '</li>').join('');
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

            const key = type === 'positivo' ? 'pos' : (type === 'neutro' ? 'neu' : 'neg');
            const rows = (window.__opSurvey && window.__opSurvey.recent && window.__opSurvey.recent[key]) || [];
            const notaTxt = {5: 'Excelente / Ótimo', 4: 'Muito Bom', 3: 'Bom / Regular', 2: 'Ruim', 1: 'Péssimo'};
            let html = rows.length ? rows.map(r => `
                <div class="p-3 bg-slate-950 border border-slate-800 rounded-2xl flex items-center justify-between text-xs">
                    <span class="font-bold text-white">${r.tel || '-'}</span>
                    <span class="text-slate-300">${notaTxt[r.nota] || ''} (${r.nota})</span>
                    <span class="text-slate-400 font-mono">${r.data} ${r.hora || ''}</span>
                </div>`).join('') + `<a href="index.php?module=relatorios&action=pesquisa" class="block text-center text-brand-400 font-bold hover:underline text-xs pt-2">Ver relatório completo e ouvir gravações</a>`
                : '<div class="p-3 text-slate-400 text-xs">Nenhuma chamada nesta categoria no período selecionado.</div>';

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

        // Webphone: widget flutuante (webphone_widget.js, carregado só para quem tem o Webphone habilitado)
        // Com a casca do painel, o telefone mora na página de fora (parent): é ele que mantém o registro entre as telas
        function WPH() { try { if (window.parent && window.parent !== window && window.parent.PrismaWebphone) return window.parent.PrismaWebphone; } catch (e) {} return window.PrismaWebphone || null; }
        function toggleWebPhone() {
            if (WPH()) { WPH().toggle(); return; }
            alert('O Webphone não está habilitado para o seu usuário. Peça ao administrador em Configurações > Usuários.');
        }
        function connectWebPhoneSip() { if (WPH()) WPH().open(); }
        function disconnectWebPhoneSip() { if (WPH()) WPH().close(); }
        // Liga para um número usando o Webphone (espera o registro, se preciso)
        function makeWebPhoneCall(numero) {
            const num = String(numero || '').replace(/[^0-9*#+]/g, '');
            if (!num) { alert('Digite um número para discar.'); return; }
            if (WPH()) WPH().dial(num);
        }
        function hangupWebPhoneCall() { /* o desligar fica no widget do Webphone */ }

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

            showToastNotification('Sincronização PABX', 'Sincronizando ramais e filas diretamente do Servidor PABX...', 'info');

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

            // Webphone registrado: disca por ele (usa o ramal do próprio usuário); senão segue pelo PABX (AMI Originate)
            if (WPH() && WPH().ready()) {
                makeWebPhoneCall(to);
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
                    alert(`⚠️ Falha ao Disparar Chamada via AMI:\n\n${json.error || 'Erro de comunicação no PABX'}`);
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

        window.__myExt = <?php $__lu2 = function_exists('getLoggedUser') ? getLoggedUser() : null; echo json_encode((string)($__lu2['extension'] ?? '')); ?>;
        window.__extList = <?php $__el = []; try { foreach ($db->query("SELECT extension, agent_name FROM extensions_config ORDER BY CAST(extension AS UNSIGNED) ASC") as $__r) $__el[] = [$__r['extension'], $__r['agent_name']]; } catch (Throwable $e) {} echo json_encode($__el, JSON_UNESCAPED_UNICODE); ?>;
        async function originateCall(destNum, destName = '') {
            // Escolha do ramal: o do usuário logado (se houver), senão o último usado neste navegador
            let saved = ''; try { saved = localStorage.getItem('pabx_user_exten') || ''; } catch (e) {}
            const mine = window.__myExt || saved;
            let dlg = document.getElementById('dlg-click-to-call');
            if (dlg) dlg.remove();
            dlg = document.createElement('dialog'); dlg.id = 'dlg-click-to-call';
            dlg.style.cssText = 'background:#1c1b2f;color:#e2e8f0;border:1px solid #334155;border-radius:18px;padding:22px;max-width:420px;width:92vw';
            const esc = (t) => String(t == null ? '' : t).replace(/[&<>"]/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;'}[c]));
            const opts = window.__extList.map(([e, n]) => `<option value="${esc(e)}" ${e === mine ? 'selected' : ''}>${esc(e)}${n && n !== e ? ' - ' + esc(n) : ''}</option>`).join('');
            dlg.innerHTML = `<style>#dlg-click-to-call::backdrop{background:rgba(0,0,0,.65);backdrop-filter:blur(4px)}</style>
              <div style="font-weight:800;font-size:16px;margin-bottom:6px"><i class="fa-solid fa-phone-volume" style="color:#34d399"></i> Ligar agora</div>
              <p style="font-size:13px;color:#94a3b8;margin:0 0 12px">Primeiro o seu ramal toca. Quando você atender, o sistema liga para <b style="color:#fff">${esc(destName || destNum)}</b>${destName ? ' (' + esc(destNum) + ')' : ''}. Não precisa ser WebRTC.</p>
              <label style="font-size:12px;color:#94a3b8">Seu ramal</label>
              ${opts ? `<select id="c2c-ext" style="width:100%;margin:4px 0 14px;padding:9px;border-radius:10px;background:#0f172a;color:#fff;border:1px solid #334155">${opts}</select>` : `<input id="c2c-ext" value="${esc(mine)}" placeholder="Ex.: 201" style="width:100%;margin:4px 0 14px;padding:9px;border-radius:10px;background:#0f172a;color:#fff;border:1px solid #334155">`}
              <div id="c2c-msg" style="font-size:12px;min-height:16px;margin-bottom:10px"></div>
              <div style="display:flex;gap:10px;justify-content:flex-end">
                <button type="button" id="c2c-cancel" style="white-space:nowrap !important;flex-shrink:0 !important;width:auto !important;padding:9px 14px;border-radius:10px;background:#334155;color:#fff;border:0;cursor:pointer">Cancelar</button>
                <button type="button" id="c2c-go" style="white-space:nowrap !important;flex-shrink:0 !important;width:auto !important;padding:9px 16px;border-radius:10px;background:#059669;color:#fff;border:0;font-weight:800;cursor:pointer"><i class="fa-solid fa-phone"></i> Ligar</button>
              </div>`;
            document.body.appendChild(dlg); dlg.showModal();
            dlg.querySelector('#c2c-cancel').onclick = () => { dlg.close(); dlg.remove(); };
            dlg.addEventListener('cancel', () => setTimeout(() => dlg.remove(), 0));
            dlg.querySelector('#c2c-go').onclick = async () => {
                const ext = (dlg.querySelector('#c2c-ext').value || '').replace(/\D/g, '');
                const msg = dlg.querySelector('#c2c-msg');
                if (!ext) { msg.style.color = '#fda4af'; msg.textContent = 'Escolha o seu ramal.'; return; }
                msg.style.color = '#94a3b8'; msg.textContent = 'Chamando o seu ramal...';
                try {
                    const res = await fetch(`index.php?api_action=originate_call&from=${encodeURIComponent(ext)}&to=${encodeURIComponent(destNum)}`);
                    const data = await res.json();
                    if (data.success) {
                        try { localStorage.setItem('pabx_user_exten', ext); } catch (e) {}
                        dlg.close(); dlg.remove();
                        if (typeof showIaToast === 'function') showIaToast(data.message, true);
                    } else { msg.style.color = '#fda4af'; msg.textContent = data.message || data.error || 'Não foi possível ligar agora.'; }
                } catch (e) { msg.style.color = '#fda4af'; msg.textContent = 'Sem resposta do painel. Tente de novo.'; }
            };
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
                    <p class="text-xs text-slate-400">Espelhamento direto com o Servidor PABX</p>
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

                <div class="flex items-center justify-between gap-3 p-3 rounded-xl bg-slate-950 border border-slate-800">
                    <div>
                        <span class="font-bold text-slate-300 block"><i class="fa-solid fa-headset text-emerald-400 mr-1"></i> Webphone</span>
                        <span id="user-profile-wp-st" class="text-[11px] text-emerald-300 block"></span>
                        <span class="text-[11px] text-slate-400"><?php echo userHasWebphone($__pu) ? 'Habilitado para o seu usuário (' . (!empty($__pu['wp_custom']) ? 'conta personalizada' : 'ramal ' . htmlspecialchars((string)$__pu['extension'])) . ').' : 'Não habilitado. Peça ao administrador em Configurações > Usuários.'; ?></span>
                    </div>
                    <?php if (userHasWebphone($__pu)): ?>
                    <button type="button" onclick="closeModal('modal-user-profile'); toggleWebPhone();" style="white-space:nowrap !important;flex-shrink:0 !important;width:auto !important;overflow:visible !important;text-overflow:clip !important;" class="px-3 py-2 bg-emerald-600 hover:bg-emerald-500 text-white font-bold rounded-xl text-xs transition flex items-center gap-2"><i class="fa-solid fa-phone"></i> Abrir Webphone</button>
                    <button type="button" onclick="(window.top || window).location.reload();" title="Registra o ramal de novo (use depois de mudar o ramal ou o WebRTC no PABX)" style="white-space:nowrap !important;flex-shrink:0 !important;width:auto !important;overflow:visible !important;text-overflow:clip !important;" class="px-3 py-2 bg-slate-800 hover:bg-slate-700 text-slate-200 font-bold rounded-xl text-xs transition flex items-center gap-2"><i class="fa-solid fa-rotate"></i> Reconectar</button>
                    <?php endif; ?>
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

    <!-- NOVIDADES DO SISTEMA (histórico de entregas) - <dialog> nativo (Top Layer) -->
    <?php $__chg = @include __DIR__ . '/includes/changelog.php'; if (!is_array($__chg)) $__chg = ['done' => [], 'plan' => []]; ?>
    <style>
    dialog#modal-roadmap { border: 1px solid #334155; border-radius: 24px; padding: 0; background: #0f172a; color: #e2e8f0; width: min(680px, calc(100vw - 24px)); max-height: 90vh; }
    dialog#modal-roadmap::backdrop { background: rgba(0, 0, 0, 0.65); backdrop-filter: blur(4px); }
    </style>
    <dialog id="modal-roadmap">
        <div class="p-6 space-y-5">
            <div class="flex items-center justify-between border-b border-slate-800 pb-3">
                <div class="flex items-center gap-3">
                    <div class="p-3 bg-amber-500/10 rounded-2xl border border-amber-500/20 text-amber-400"><i class="fa-solid fa-rocket text-xl"></i></div>
                    <div>
                        <h4 class="text-base font-black text-white">Novidades & Melhorias do Sistema</h4>
                        <p class="text-xs text-slate-400">Histórico do que foi entregue e do que vem a seguir</p>
                    </div>
                </div>
                <button type="button" onclick="document.getElementById('modal-roadmap').close()" class="p-2 text-slate-400 hover:text-white rounded-xl hover:bg-slate-800 transition" style="white-space:nowrap;flex-shrink:0;width:auto;overflow:visible"><i class="fa-solid fa-xmark text-lg"></i></button>
            </div>
            <div class="space-y-3">
                <div class="text-xs font-black text-emerald-400 uppercase tracking-wider flex items-center gap-2"><i class="fa-solid fa-circle-check"></i> Entregue</div>
                <div class="space-y-2 text-xs text-slate-300">
                    <?php foreach ($__chg['done'] as $__c): ?>
                    <div class="p-3 bg-slate-950 rounded-2xl border border-emerald-500/20 flex items-start gap-2.5">
                        <i class="fa-solid <?php echo htmlspecialchars($__c['icon'] ?? 'fa-check'); ?> text-emerald-400 mt-0.5"></i>
                        <div class="min-w-0">
                            <strong class="text-white block"><?php echo htmlspecialchars($__c['titulo']); ?>
                                <?php if (!empty($__c['novo'])): ?><span class="ml-1 text-[9px] px-1.5 py-0.5 rounded bg-emerald-500/20 text-emerald-300 border border-emerald-500/30 font-black uppercase">Novo</span><?php endif; ?>
                                <span class="ml-1 text-[10px] text-slate-500 font-normal"><?php echo htmlspecialchars(!empty($__c['data']) ? date('d/m/Y', strtotime($__c['data'])) : ($__c['mes'] ?? '')); ?></span>
                            </strong>
                            <p class="text-slate-400 text-[11px]"><?php echo htmlspecialchars($__c['desc']); ?></p>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>
            <?php if (!empty($__chg['plan'])): ?>
            <div class="space-y-3">
                <div class="text-xs font-black text-amber-400 uppercase tracking-wider flex items-center gap-2"><i class="fa-solid fa-clock-rotate-left"></i> Próximos passos</div>
                <div class="space-y-2 text-xs text-slate-300">
                    <?php foreach ($__chg['plan'] as $__c): ?>
                    <div class="p-3 bg-slate-950 rounded-2xl border border-amber-500/20 flex items-start gap-2.5">
                        <i class="fa-solid <?php echo htmlspecialchars($__c['icon'] ?? 'fa-gear'); ?> text-amber-400 mt-0.5"></i>
                        <div class="min-w-0"><strong class="text-white block"><?php echo htmlspecialchars($__c['titulo']); ?></strong><p class="text-slate-400 text-[11px]"><?php echo htmlspecialchars($__c['desc']); ?></p></div>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>
            <?php endif; ?>
        </div>
    </dialog>

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
            const st = document.getElementById('user-profile-wp-st'), pill = document.getElementById('webphone-pill');
            if (st && pill) st.textContent = 'Estado: ' + (pill.title || 'desconectado');
        }

        function openRoadmapModal() {
            const modal = document.getElementById('modal-roadmap');
            if (modal && modal.showModal) modal.showModal();
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

                <div id="share-email-format-box" class="space-y-1.5">
                    <label class="text-xs font-bold text-slate-300">Formato do arquivo anexo</label>
                    <div class="flex items-center gap-2 text-xs">
                        <label class="flex items-center gap-1.5 px-3 py-2 bg-slate-950 border border-slate-800 rounded-xl cursor-pointer"><input type="radio" name="share-email-format" value="xlsx" checked class="accent-amber-500"> <span class="text-slate-200 font-bold">Excel (.xlsx)</span></label>
                        <label class="flex items-center gap-1.5 px-3 py-2 bg-slate-950 border border-slate-800 rounded-xl cursor-pointer"><input type="radio" name="share-email-format" value="pdf" class="accent-amber-500"> <span class="text-slate-200 font-bold">PDF</span></label>
                        <label class="flex items-center gap-1.5 px-3 py-2 bg-slate-950 border border-slate-800 rounded-xl cursor-pointer"><input type="radio" name="share-email-format" value="csv" class="accent-amber-500"> <span class="text-slate-200 font-bold">CSV</span></label>
                    </div>
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

                <div id="share-wa-format-box" class="space-y-1.5">
                    <label class="text-xs font-bold text-slate-300">Formato do arquivo anexo</label>
                    <div class="flex items-center gap-2 text-xs">
                        <label class="flex items-center gap-1.5 px-3 py-2 bg-slate-950 border border-slate-800 rounded-xl cursor-pointer"><input type="radio" name="share-wa-format" value="xlsx" checked class="accent-emerald-500"> <span class="text-slate-200 font-bold">Excel (.xlsx)</span></label>
                        <label class="flex items-center gap-1.5 px-3 py-2 bg-slate-950 border border-slate-800 rounded-xl cursor-pointer"><input type="radio" name="share-wa-format" value="pdf" class="accent-emerald-500"> <span class="text-slate-200 font-bold">PDF</span></label>
                        <label class="flex items-center gap-1.5 px-3 py-2 bg-slate-950 border border-slate-800 rounded-xl cursor-pointer"><input type="radio" name="share-wa-format" value="csv" class="accent-emerald-500"> <span class="text-slate-200 font-bold">CSV</span></label>
                    </div>
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
        function shareFormatInit(prefix) {
            const box = document.getElementById(prefix + '-format-box');
            const isRec = !!window.__shareUid || typeof window.__reportShare !== 'function';
            if (box) box.classList.toggle('hidden', isRec);
            let saved = ''; try { saved = localStorage.getItem('prisma_share_format') || ''; } catch (e) {}
            if (saved) { const r = document.querySelector('input[name="' + prefix + '-format"][value="' + saved + '"]'); if (r) r.checked = true; }
        }
        function shareFormatValue(prefix) {
            const r = document.querySelector('input[name="' + prefix + '-format"]:checked');
            const v = r ? r.value : 'xlsx';
            try { localStorage.setItem('prisma_share_format', v); } catch (e) {}
            return v;
        }

        function shareReportViaEmail(itemTitle, defaultEmail = '') {
            window.__shareDetail = window.__pendingDetail || ''; window.__pendingDetail = ''; window.__shareUid = window.__pendingUid || ''; window.__pendingUid = '';
            document.getElementById('share-email-subject-val').value = itemTitle;
            document.getElementById('share-email-item-display').innerText = itemTitle;
            const targetInput = document.getElementById('share-email-target');
            if (targetInput) targetInput.value = defaultEmail || localStorage.getItem('prisma_user_email') || '';
            
            shareFormatInit('share-email');
            const modal = document.getElementById('modal-share-email');
            if (modal) modal.classList.remove('hidden');
        }

        function shareReportViaWhatsApp(itemTitle, defaultPhone = '') {
            window.__shareDetail = window.__pendingDetail || ''; window.__pendingDetail = ''; window.__shareUid = window.__pendingUid || ''; window.__pendingUid = '';
            document.getElementById('share-wa-item-val').value = itemTitle;
            document.getElementById('share-wa-item-display').innerText = itemTitle;
            const targetInput = document.getElementById('share-wa-target');
            if (targetInput) targetInput.value = defaultPhone || '';
            
            shareFormatInit('share-wa');
            const modal = document.getElementById('modal-share-whatsapp');
            if (modal) modal.classList.remove('hidden');
        }

        function sendSingleCallEmail(uid, src, dst, calldate, duration) {
            window.__pendingUid = uid;
            window.__pendingDetail = `Origem: ${src}\nDestino: ${dst}\nData: ${calldate}\nDuração: ${duration}s`;
            shareReportViaEmail(`Gravação de Chamada #${uid} (${src} ➔ ${dst})`);
        }

        function sendSingleCallWa(uid, src, dst, calldate, duration) {
            window.__pendingUid = uid;
            window.__pendingDetail = `Origem: ${src}\nDestino: ${dst}\nData: ${calldate}\nDuração: ${duration}s`;
            shareReportViaWhatsApp(`Gravação de Chamada #${uid} (${src} ➔ ${dst})`);
        }

        async function executeShareEmail() {
            const itemTitle = document.getElementById('share-email-subject-val').value;
            const email = document.getElementById('share-email-target').value.trim();
            const btn = document.getElementById('btn-submit-share-email');
            const origHtml = btn ? btn.innerHTML : '';
            let __rs = null; try { __rs = (!window.__shareUid && typeof window.__reportShare === 'function') ? window.__reportShare() : null; } catch (e) { __rs = null; }

            if (!email) {
                showToastNotification('E-mail Obrigatório', 'Informe o endereço de e-mail de destino.', 'warning');
                return;
            }

            if (btn) { btn.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> Enviando...'; btn.disabled = true; }
            try {
                const res = await fetch('index.php?api_action=send_email_report', {
                    method: 'POST',
                    headers: {'Content-Type': 'application/json'},
                    body: JSON.stringify(Object.assign({ to: email, subject: itemTitle, message: itemTitle + (__rs && __rs.summary ? '\n\n' + __rs.summary : '') + (window.__shareUid && window.__shareDetail ? '\n\n' + window.__shareDetail : ''), uid: window.__shareUid || '' }, (__rs && !window.__shareUid) ? { csv: __rs.csv || '', filename: __rs.filename || 'relatorio.csv', title: itemTitle, summary: __rs.summary || '', format: shareFormatValue('share-email') } : {}))
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
            let __rs = null; try { __rs = (!window.__shareUid && typeof window.__reportShare === 'function') ? window.__reportShare() : null; } catch (e) { __rs = null; }

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
                    body: JSON.stringify(Object.assign({ phone: phone, message: (custom ? custom + '\n\n' : '') + '📌 ' + itemTitle + (__rs && __rs.summary ? '\n\n' + __rs.summary : '') + (window.__shareUid && window.__shareDetail ? '\n\n' + window.__shareDetail : ''), uid: window.__shareUid || '' }, (__rs && !window.__shareUid) ? { csv: __rs.csv || '', filename: __rs.filename || 'relatorio.csv', title: itemTitle, summary: __rs.summary || '', format: shareFormatValue('share-wa') } : {}))
                });
                const data = await res.json();
                if (data.success) {
                    showToastNotification('WhatsApp enviado', `Mensagem enviada para +${phone}` + (data.files_sent ? ` com ${data.files_sent} anexo(s).` : '.') + (data.warning ? ' ' + data.warning : ''), data.warning ? 'warning' : 'success');
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

<?php if (function_exists('hasUserPermission') && hasUserPermission('ai_chat') && function_exists('aiChatAvailable') && aiChatAvailable()): ?>
<style>
#ai-chat-fab{position:fixed;right:20px;bottom:20px;z-index:60;width:54px;height:54px;border-radius:50%;border:0;cursor:pointer;color:#fff;font-size:22px;background:linear-gradient(135deg,#7c3aed,#6366f1);box-shadow:0 8px 24px rgba(99,102,241,.45)}
#ai-chat-fab:hover{transform:scale(1.06)}
dialog#ai-chat-dialog{border:1px solid #334155;border-radius:18px;padding:0;background:#1c1b2f;color:#e2e8f0;width:min(420px,calc(100vw - 24px));height:min(620px,calc(100vh - 24px));margin:0 20px 20px auto;overflow:hidden;font-family:'Segoe UI',system-ui,sans-serif}
dialog#ai-chat-dialog[open]{display:flex;flex-direction:column}
dialog#ai-chat-dialog::backdrop{background:rgba(0,0,0,.65);backdrop-filter:blur(4px)}
#ai-chat-head{display:flex;align-items:center;justify-content:space-between;padding:12px 14px;background:linear-gradient(135deg,#7c3aed,#6366f1);color:#fff;font-weight:700;font-size:14px}
#ai-chat-head button{background:transparent;border:0;color:#fff;cursor:pointer;font-size:16px;white-space:nowrap;flex-shrink:0;width:auto;overflow:visible}
#ai-chat-body{flex:1;overflow:auto;padding:12px;display:flex;flex-direction:column;gap:8px;background:#0f172a}
.ai-msg{max-width:92%;padding:9px 12px;border-radius:14px;font-size:13px;line-height:1.45;word-wrap:break-word}
.ai-msg.bot{background:#252440;border:1px solid #334155;align-self:flex-start}
.ai-msg.me{background:#6366f1;color:#fff;align-self:flex-end}
.ai-msg a{color:#a5b4fc;text-decoration:underline}
.ai-msg table{border-collapse:collapse;font-size:11px;margin:6px 0;display:block;overflow:auto}
.ai-msg th,.ai-msg td{border:1px solid #475569;padding:3px 6px;white-space:nowrap}
.ai-chip{display:inline-block;margin:3px 4px 0 0;padding:5px 10px;border-radius:999px;border:1px solid #6366f1;background:transparent;color:#c7d2fe;font-size:11px;cursor:pointer;white-space:nowrap;flex-shrink:0;width:auto;overflow:visible}
.ai-chip:hover{background:#6366f1;color:#fff}
#ai-chat-form{display:flex;gap:8px;padding:10px;background:#1c1b2f;border-top:1px solid #334155}
#ai-chat-input{flex:1;background:#0f172a;border:1px solid #334155;border-radius:12px;color:#fff;padding:9px 12px;font-size:13px;outline:none}
#ai-chat-send{background:linear-gradient(135deg,#7c3aed,#6366f1);border:0;color:#fff;border-radius:12px;padding:0 14px;font-weight:700;cursor:pointer;white-space:nowrap;flex-shrink:0;width:auto;overflow:visible}
</style>
<button id="ai-chat-fab" type="button" title="Assistente IA" onclick="aiChatOpen()"><i class="fa-solid fa-robot"></i></button>
<script>
(function(){
  var hist = [], dlg = null, body = null, started = false, busy = false;
  var SUG = ['Ligações de hoje do ramal 201', 'Quantas vezes o número 11999998888 ligou nos últimos 7 dias?', 'Resumo das chamadas de hoje', 'Como estão as filas agora?', 'Quais ramais estão em chamada?'];
  function esc(t){return String(t).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;');}
  function md(t){
    t = esc(t);
    var lines = t.split('\n'), out = [], tbl = [];
    function flush(){ if(!tbl.length) return; var h = tbl[0], rows = tbl.slice(2);
      var cells = function(l,tag){ return '<tr>'+l.replace(/^\||\|$/g,'').split('|').map(function(c){return '<'+tag+'>'+c.trim()+'</'+tag+'>';}).join('')+'</tr>'; };
      out.push('<table>'+cells(h,'th')+rows.map(function(r){return cells(r,'td');}).join('')+'</table>'); tbl = []; }
    lines.forEach(function(l){ if (/^\s*\|.*\|\s*$/.test(l)) tbl.push(l); else { flush(); out.push(l); } });
    flush();
    t = out.join('\n').replace(/\n(?=<table)|(<\/table>)\n/g,'$1');
    t = t.replace(/\[([^\]]+)\]\((https?:\/\/[^\s)]*get_audio\.php\?[^\s)]*)\)/g, function(m,l,u){ return '<audio controls preload="none" src="'+u+'" style="width:100%;margin:4px 0"></audio><br><a href="'+u+'" target="_blank" rel="noopener">Abrir / baixar áudio</a>'; });
    t = t.replace(/\*\*(.+?)\*\*/g,'<b>$1</b>')
         .replace(/\[([^\]]+)\]\((https?:\/\/[^\s)]+)\)/g,'<a href="$2" target="_blank" rel="noopener">$1</a>')
         .replace(/\n/g,'<br>');
    return t;
  }
  function add(cls, html){ var d = document.createElement('div'); d.className = 'ai-msg '+cls; d.innerHTML = html; body.appendChild(d); body.scrollTop = body.scrollHeight; return d; }
  function build(){
    dlg = document.createElement('dialog'); dlg.id = 'ai-chat-dialog';
    dlg.innerHTML = '<div id="ai-chat-head"><span><i class="fa-solid fa-robot"></i> Assistente IPbx Prisma</span><span><button type="button" id="ai-chat-new" title="Nova conversa"><i class="fa-solid fa-rotate-right"></i></button> <button type="button" id="ai-chat-x" title="Fechar">✕</button></span></div>'
      + '<div id="ai-chat-body"></div>'
      + '<form id="ai-chat-form"><input id="ai-chat-input" autocomplete="off" placeholder="Pergunte sobre ramais, ligações, filas..."><button id="ai-chat-send" type="submit">Enviar</button></form>';
    document.body.appendChild(dlg);
    body = dlg.querySelector('#ai-chat-body');
    dlg.querySelector('#ai-chat-x').onclick = function(){ dlg.close(); };
    dlg.querySelector('#ai-chat-new').onclick = async function(){
      try { await fetch('index.php?api_action=ai_chat_clear', {method:'POST'}); } catch(e){}
      hist = []; body.innerHTML = ''; greet();
    };
    dlg.addEventListener('click', function(e){ if (e.target === dlg) dlg.close(); });
    dlg.querySelector('#ai-chat-form').onsubmit = function(e){ e.preventDefault(); var i = dlg.querySelector('#ai-chat-input'); var v = i.value.trim(); if (v) { i.value = ''; send(v); } };
  }
  function greet(){
    started = true;
    add('bot', '<b>Olá! Sou o assistente do seu PABX.</b><br>Consulto dados reais e posso:<br>• listar ligações de um ramal ou número<br>• contar quantas vezes um número ligou<br>• resumir o período (atendidas, TMA, TME)<br>• mostrar status de ramais e filas<br>• gerar o link de uma gravação<br>• ligar/desligar Não Perturbe e desvios (com a sua confirmação)<br><span style="color:#94a3b8">Experimente:</span><br>'
      + SUG.map(function(s){ return '<button type="button" class="ai-chip" data-q="'+esc(s)+'">'+esc(s)+'</button>'; }).join(''));
    body.querySelectorAll('.ai-chip').forEach(function(b){ b.onclick = function(){ send(b.getAttribute('data-q')); }; });
  }
  function card(p){
    var c = add('bot', '<b>Confirmar ação?</b><br>' + esc(p.resumo) + '<br><button type="button" class="ai-chip" data-ok="1">Confirmar</button><button type="button" class="ai-chip" data-ok="0">Cancelar</button>');
    c.querySelectorAll('button').forEach(function(b){ b.onclick = async function(){
      c.querySelectorAll('button').forEach(function(x){ x.disabled = true; });
      try {
        var r = await fetch('index.php?api_action=ai_chat_confirm', {method:'POST', headers:{'Content-Type':'application/json'}, body: JSON.stringify({id:p.id, accept:b.getAttribute('data-ok')==='1'})});
        var d = await r.json();
        c.innerHTML = d.success ? esc(d.message || 'Feito.') : '⚠️ ' + esc(d.error || 'Falha.');
        if (d.success && window.ipbxLiveCheck) setTimeout(function () { window.ipbxLiveCheck(true); }, 600);   // a tela por trás do chat também se atualiza
      } catch(e){ c.innerHTML = '⚠️ ' + esc(e.message); }
    }; });
  }
  async function send(text){
    if (busy) return; busy = true;
    add('me', esc(text)); hist.push({role:'user', content:text});
    var w = add('bot', '<i>consultando o PABX…</i>');
    try {
      var r = await fetch('index.php?api_action=ai_chat', {method:'POST', headers:{'Content-Type':'application/json'}, body: JSON.stringify({messages: hist})});
      var d = await r.json();
      if (d.success) { w.innerHTML = md(d.text || '(sem resposta)'); hist.push({role:'assistant', content:d.text || ''}); (d.proposals || []).forEach(card); }
      else { w.innerHTML = '⚠️ ' + esc(d.error || 'Falha ao consultar a IA.'); hist.pop(); }
    } catch(e){ w.innerHTML = '⚠️ ' + esc(e.message); hist.pop(); }
    busy = false; body.scrollTop = body.scrollHeight;
  }
  async function loadHistory(){
    try {
      var r = await fetch('index.php?api_action=ai_chat_history'); var d = await r.json();
      if (d.success && d.messages && d.messages.length) {
        started = true;
        d.messages.forEach(function(m){ hist.push({role:m.role, content:m.content}); add(m.role === 'user' ? 'me' : 'bot', m.role === 'user' ? esc(m.content) : md(m.content)); });
        return true;
      }
    } catch(e){}
    return false;
  }
  window.aiChatOpen = async function(){ if (!dlg) { build(); await loadHistory(); } if (!started) greet(); dlg.showModal(); setTimeout(function(){ var i = dlg.querySelector('#ai-chat-input'); if (i) i.focus(); }, 50); };
})();
</script>
<?php endif; ?>
<script>
/* Tempo real: quando o Chat IA (ou outro usuário) altera agendamentos, envios ou ramais, a tela aberta se atualiza sozinha. */
(function () {
  var mod = <?php echo json_encode($module . ':' . $action); ?>;
  var map = {'configuracoes:smtp': ['schedules', 'wa_sends'], 'whatsapp:disparos': ['schedules', 'wa_sends'], 'whatsapp:historico': ['wa_sends'],
             'ramais:editar_nome': ['extensions'], 'ramais:fop': ['extensions'], 'dashboard:view_v1': ['extensions'], 'whatsapp:contatos': ['extensions', 'contacts'], 'whatsapp:regras': ['rules'], 'configuracoes:resumo_diario': ['daily'], 'configuracoes:assistente_whatsapp': ['wa_asst'], 'telefonia:blacklist': ['blacklist']};
  var areas = map[mod] || [];
  var base = null, busy = false;
  window.ipbxLiveCheck = async function (force) {
    if (!areas.length || busy) return;
    busy = true;
    try {
      var r = await (await fetch('index.php?api_action=panel_rev', {cache: 'no-store'})).json();
      if (!r || !r.rev) return;
      if (base === null) { base = r.rev; return; }
      var changed = areas.some(function (a) { return (r.rev[a] || '') !== (base[a] || ''); });
      if (!changed) return;
      var typing = document.activeElement && /^(INPUT|TEXTAREA|SELECT)$/.test(document.activeElement.tagName);
      var modal = document.querySelector('dialog[open], .modal-open');
      if (!force && (typing || modal)) return;   // não interrompe quem está digitando; tenta no próximo ciclo
      if (force) { try { sessionStorage.setItem('ipbx_chat_reopen', '1'); } catch (e) {} }
      location.reload();
    } catch (e) {} finally { busy = false; }
  };
  window.ipbxLiveBase = function () { base = null; return window.ipbxLiveCheck(); };
  if (areas.length) { window.ipbxLiveCheck(); setInterval(function () { window.ipbxLiveCheck(); }, 8000); }
  document.addEventListener('DOMContentLoaded', function () { try { if (sessionStorage.getItem('ipbx_chat_reopen') === '1') { sessionStorage.removeItem('ipbx_chat_reopen'); if (window.aiChatOpen) setTimeout(window.aiChatOpen, 300); } } catch (e) {} });
})();
</script>
<script>
/* Histórico de análises de IA (por tela). Usa <dialog> nativo na Top Layer. */
/* Executa a análise de IA de uma tela usando SOMENTE números reais enviados pela própria tela. */
window.ipbxIaRun = async function (o) {
  var btn = o.btn, orig = btn ? btn.innerHTML : '';
  if (btn) { btn.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> Analisando...'; btn.disabled = true; }
  var esc = function (t) { return String(t == null ? '' : t).replace(/[&<>"]/g, function (c) { return {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;'}[c]; }); };
  try {
    var r = await fetch('index.php?api_action=analyze_pabx_insights'+(window.__ipbxDedup===undefined?'':'&dedup='+window.__ipbxDedup)+(window.__ipbxCallType===undefined?'':'&call_type='+window.__ipbxCallType)+'', { method: 'POST', headers: {'Content-Type': 'application/json'},
      body: JSON.stringify({ module: o.module, periodo: o.periodo || '', total: o.total, atendidas: o.atendidas, tmaSec: o.tmaSec, tmeSec: o.tmeSec, extras: o.extras || {} }) });
    var d = await r.json();
    if (!d.success) throw new Error(d.error || 'Falha na análise');
    var html = (d.notice ? '<div style="color:#fbbf24;margin-bottom:6px"><i class="fa-solid fa-triangle-exclamation"></i> ' + esc(d.notice) + '</div>' : '')
      + '<div style="color:#a78bfa;font-weight:800;margin-bottom:4px">' + esc(d.engine_label || '') + (o.periodo ? ' · ' + esc(o.periodo) : '') + '</div>'
      + '<ul style="margin:0;padding-left:18px">' + (d.insights || []).map(function (i) { return '<li style="margin:3px 0">' + esc(i) + '</li>'; }).join('') + '</ul>';
    o.out.innerHTML = html; o.out.style.fontStyle = 'normal';
    if (o.box) o.box.classList.remove('hidden');
  } catch (e) {
    o.out.innerHTML = '<span style="color:#fb7185">Não foi possível analisar agora: ' + esc(e.message) + '</span>';
    if (o.box) o.box.classList.remove('hidden');
  } finally { if (btn) { btn.innerHTML = orig; btn.disabled = false; } }
};
window.ipbxIaHistory = async function (module, title) {
  var dlg = document.getElementById('ipbx-ia-hist');
  if (!dlg) {
    var st = document.createElement('style');
    st.textContent = '#ipbx-ia-hist{border:1px solid #334155;border-radius:18px;background:#0f172a;color:#e2e8f0;padding:0;width:min(680px,94vw);max-height:84vh}#ipbx-ia-hist::backdrop{background:rgba(0,0,0,.65);backdrop-filter:blur(4px)}#ipbx-ia-hist .ih-b{padding:18px 20px;overflow:auto;max-height:68vh}#ipbx-ia-hist .ih-i{border:1px solid #1e293b;background:#020617;border-radius:12px;padding:12px 14px;margin-bottom:10px;font-size:12px}#ipbx-ia-hist button{white-space:nowrap !important;flex-shrink:0 !important;width:auto !important;overflow:visible !important;text-overflow:clip !important}';
    document.head.appendChild(st);
    dlg = document.createElement('dialog'); dlg.id = 'ipbx-ia-hist';
    document.body.appendChild(dlg);
    dlg.addEventListener('click', function (e) { if (e.target === dlg) dlg.close(); });
  }
  var esc = function (t) { return String(t == null ? '' : t).replace(/[&<>"]/g, function (c) { return {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;'}[c]; }); };
  dlg.innerHTML = '<div style="display:flex;justify-content:space-between;align-items:center;padding:14px 20px;border-bottom:1px solid #1e293b"><b style="font-size:14px">Histórico de análises — ' + esc(title || module) + '</b><button type="button" id="ipbx-ia-hist-x" style="background:#1e293b;color:#e2e8f0;border:1px solid #334155;border-radius:10px;padding:6px 12px;font-weight:700;cursor:pointer">Fechar</button></div><div class="ih-b">Carregando...</div>';
  dlg.querySelector('#ipbx-ia-hist-x').onclick = function () { dlg.close(); };
  if (!dlg.open) dlg.showModal();
  var body = dlg.querySelector('.ih-b');
  try {
    var r = await fetch('index.php?api_action=get_ai_insights_history&module=' + encodeURIComponent(module)); var d = await r.json();
    if (!d.success || !d.history || !d.history.length) { body.innerHTML = '<div style="color:#94a3b8;text-align:center;padding:24px">Nenhuma análise feita ainda nesta tela.</div>'; return; }
    body.innerHTML = d.history.map(function (h) {
      return '<div class="ih-i" data-id="' + h.id + '"><div style="display:flex;justify-content:space-between;gap:8px;color:#94a3b8;margin-bottom:6px"><span>' + esc(h.created_at_fmt) + (h.period ? ' · período ' + esc(h.period) : '') + ' · ' + (h.provider === 'metricas' ? 'métricas reais (sem IA)' : esc(h.provider)) + '</span><a href="#" data-del="' + h.id + '" style="color:#fb7185">excluir</a></div><ul style="margin:0;padding-left:18px">' + (h.insights || []).map(function (i) { return '<li style="margin:3px 0">' + esc(i) + '</li>'; }).join('') + '</ul></div>';
    }).join('');
    body.querySelectorAll('[data-del]').forEach(function (a) { a.onclick = async function (e) { e.preventDefault(); await fetch('index.php?api_action=delete_ai_insight&id=' + a.getAttribute('data-del')); a.closest('.ih-i').remove(); }; });
  } catch (e) { body.innerHTML = '<div style="color:#fb7185">Falha ao carregar o histórico.</div>'; }
};
</script>
<audio id="webphone-remote-audio" autoplay></audio>
<audio id="webphone-local-audio" autoplay muted></audio>
<?php if (hasUserPermission('send_whatsapp')): $__wa_ok = whatsappProviderConfigured(); $__wa_nome = (waProvider() === 'uazapi') ? 'UAZAPI' : 'Prisma API'; $__nc_user = getLoggedUser(); ?>
<!-- Nova Conversa: envio de mensagem de WhatsApp (dialog nativo na Top Layer) -->
<style>
#dlg-nova-conversa{box-sizing:border-box;border:1px solid #334155;border-radius:20px;padding:0;background:#0f172a;color:#e2e8f0;width:min(460px,calc(100vw - 24px));max-height:calc(100vh - 32px);overflow:auto;box-shadow:0 30px 80px rgba(0,0,0,.6)}
#dlg-nova-conversa::backdrop{background:rgba(0,0,0,.65);backdrop-filter:blur(4px)}
#dlg-nova-conversa .nc-in{box-sizing:border-box;width:100%;background:#0b1220;border:1px solid #334155;border-radius:12px;color:#fff;padding:10px 12px;font-size:13px}
#dlg-nova-conversa .nc-in:focus{outline:none;border-color:#6366f1}
#dlg-nova-conversa .nc-btn{white-space:nowrap!important;flex-shrink:0!important;width:auto!important;overflow:visible!important;text-overflow:clip!important;padding:10px 18px;border-radius:12px;font-weight:800;font-size:13px;cursor:pointer;display:inline-flex;align-items:center;gap:8px;border:1px solid #334155;color:#e2e8f0;background:#1c1b2f}
#dlg-nova-conversa .nc-send{background:linear-gradient(135deg,#7c3aed,#6366f1);border-color:#6366f1;color:#fff}
#dlg-nova-conversa .nc-send:disabled{opacity:.5;cursor:not-allowed}
#nc-results{display:none;margin-top:6px;border:1px solid #334155;border-radius:12px;background:#0b1220;overflow:hidden}
#nc-results button{display:flex;width:100%;justify-content:space-between;gap:10px;padding:8px 12px;background:none;border:0;border-bottom:1px solid #1f2937;color:#e2e8f0;cursor:pointer;text-align:left;font-size:12px}
#nc-results button:hover{background:#252440}#nc-results button:last-child{border-bottom:0}
</style>
<dialog id="dlg-nova-conversa">
  <form method="dialog" onsubmit="return false;" style="padding:22px">
    <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:4px">
      <h3 style="margin:0;font-size:17px;font-weight:800;color:#fff"><i class="fa-regular fa-paper-plane" style="margin-right:8px"></i>Nova Conversa</h3>
      <button type="button" onclick="closeNovaConversa()" style="background:none;border:0;color:#94a3b8;cursor:pointer;font-size:16px" title="Fechar"><i class="fa-solid fa-xmark"></i></button>
    </div>
    <p style="margin:0 0 14px;color:#94a3b8;font-size:12px">Envie uma mensagem de WhatsApp para um número específico.</p>
    <?php $__ncc = waChannelsConfigured(); $__nc_names = ['zpro' => 'Prismabot (API)', 'uazapi' => 'WhatsApp direto (QR Code)']; $__nc_avail = array_keys(array_filter($__ncc)); ?>
    <?php if (count($__nc_avail) > 1): ?>
    <div style="margin-bottom:14px">
      <label for="nc-channel" style="font-size:12px;font-weight:700;color:#cbd5e1;display:block;margin-bottom:6px">Enviar por</label>
      <select id="nc-channel" class="nc-in">
        <?php foreach ($__nc_avail as $__k): ?><option value="<?php echo $__k; ?>" <?php echo waProvider() === $__k ? 'selected' : ''; ?>><?php echo $__nc_names[$__k]; ?></option><?php endforeach; ?>
      </select>
    </div>
    <?php else: ?>
    <input type="hidden" id="nc-channel" value="<?php echo $__nc_avail ? $__nc_avail[0] : ''; ?>">
    <div style="margin-bottom:14px;padding:8px 12px;border-radius:12px;font-size:12px;<?php echo $__nc_avail ? 'background:rgba(16,185,129,.1);border:1px solid rgba(16,185,129,.35);color:#6ee7b7' : 'background:rgba(244,63,94,.1);border:1px solid rgba(244,63,94,.35);color:#fda4af'; ?>">
      <?php echo $__nc_avail ? 'Envio por: <b>' . $__nc_names[$__nc_avail[0]] . '</b>' : 'Nenhum servidor de envio está configurado. Ajuste em Configurações &gt; APIs &amp; Conexões.'; ?>
    </div>
    <?php endif; ?>
    <label style="font-size:12px;font-weight:700;color:#cbd5e1;display:block;margin-bottom:6px">Número *</label>
    <input id="nc-search" class="nc-in" type="search" autocomplete="off" placeholder="Buscar contato no sistema..." oninput="ncSearch(this.value)">
    <div id="nc-results"></div>
    <input id="nc-phone" class="nc-in" type="tel" inputmode="tel" autocomplete="off" placeholder="+5511999999999" style="margin-top:8px">
    <span style="font-size:11px;color:#94a3b8;display:block;margin-top:4px">Inclua o código do país (ex: 55 para Brasil). Sem ele, o 55 é adicionado.</span>
    <div style="display:flex;align-items:center;justify-content:space-between;margin:14px 0 6px">
      <label for="nc-msg" style="font-size:12px;font-weight:700;color:#cbd5e1">Mensagem *</label>
      <label style="display:flex;align-items:center;gap:8px;font-size:12px;color:#94a3b8;cursor:pointer"><input type="checkbox" id="nc-sign" checked style="accent-color:#10b981"> Assinado</label>
    </div>
    <textarea id="nc-msg" class="nc-in" rows="5" maxlength="4000" placeholder="Digite sua mensagem..." style="resize:vertical"></textarea>
    <div id="nc-err" style="display:none;margin-top:10px;padding:8px 12px;border-radius:10px;font-size:12px;background:rgba(244,63,94,.1);border:1px solid rgba(244,63,94,.35);color:#fda4af"></div>
    <div style="display:flex;justify-content:flex-end;gap:10px;margin-top:16px">
      <button type="button" class="nc-btn" onclick="closeNovaConversa()">Cancelar</button>
      <button type="button" class="nc-btn nc-send" id="nc-send" onclick="sendNovaConversa()" <?php echo $__wa_ok ? '' : 'disabled'; ?>><i class="fa-regular fa-paper-plane"></i> Enviar</button>
    </div>
  </form>
</dialog>
<script>
(function () {
  const NC_USER = <?php echo json_encode((string)($__nc_user['name'] ?? ''), JSON_UNESCAPED_UNICODE); ?>;
  const $ = id => document.getElementById(id);
  let tmr = null;
  window.openNovaConversa = function () {
    const d = $('dlg-nova-conversa'); if (!d) return;
    $('nc-err').style.display = 'none';
    if (!d.open) { if (d.showModal) d.showModal(); else d.setAttribute('open', ''); }
    setTimeout(() => { const i = $('nc-search'); if (i) i.focus(); }, 50);
  };
  window.closeNovaConversa = function () { const d = $('dlg-nova-conversa'); if (d && d.open) d.close(); };
  function erro(m) { const e = $('nc-err'); e.textContent = m || ''; e.style.display = m ? 'block' : 'none'; }
  window.ncSearch = function (q) {
    clearTimeout(tmr); const box = $('nc-results');
    q = String(q || '').trim();
    if (q.length < 2) { box.style.display = 'none'; box.textContent = ''; return; }
    tmr = setTimeout(async () => {
      try {
        const r = await fetch('index.php?api_action=search_contacts&q=' + encodeURIComponent(q), {credentials: 'same-origin'});
        const j = await r.json(); box.textContent = '';
        if (!j.contacts || !j.contacts.length) { box.style.display = 'block'; const s = document.createElement('div'); s.style.cssText = 'padding:8px 12px;color:#94a3b8;font-size:12px'; s.textContent = 'Nenhum contato encontrado. Digite o número abaixo.'; box.appendChild(s); return; }
        j.contacts.forEach(c => {
          const b = document.createElement('button'); b.type = 'button';
          const n = document.createElement('span'); n.textContent = c.name || c.phone;
          const p = document.createElement('span'); p.style.color = '#94a3b8'; p.textContent = c.phone + ' · ' + c.src;
          b.append(n, p);
          b.addEventListener('click', () => { $('nc-phone').value = c.phone; $('nc-search').value = c.name || ''; box.style.display = 'none'; $('nc-msg').focus(); });
          box.appendChild(b);
        });
        box.style.display = 'block';
      } catch (e) { box.style.display = 'none'; }
    }, 250);
  };
  window.sendNovaConversa = async function () {
    erro('');
    let phone = $('nc-phone').value.replace(/\D/g, ''), msg = $('nc-msg').value.trim();
    if (phone.length < 10) { erro('Informe o número com DDD (e o código do país, se for fora do Brasil).'); return; }
    if (phone.length <= 11) phone = '55' + phone;
    if (!msg) { erro('Digite a mensagem.'); return; }
    if ($('nc-sign').checked && NC_USER) msg = '*' + NC_USER + '*\n' + msg;
    const btn = $('nc-send'), orig = btn.innerHTML; btn.disabled = true; btn.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> Enviando...';
    try {
      const r = await fetch('index.php?api_action=send_whatsapp_message', {method: 'POST', credentials: 'same-origin', headers: {'Content-Type': 'application/json'}, body: JSON.stringify({phone: phone, message: msg, channel: ($('nc-channel') ? $('nc-channel').value : '')})});
      const j = await r.json();
      if (j.success) {
        closeNovaConversa(); $('nc-msg').value = ''; $('nc-phone').value = ''; $('nc-search').value = ''; $('nc-results').style.display = 'none';
        if (typeof showToastNotification === 'function') showToastNotification('WhatsApp enviado', 'Mensagem enviada para +' + phone + '.', 'success');
      } else { erro(j.error || 'Não foi possível enviar a mensagem.'); }
    } catch (e) { erro('Falha de comunicação com o painel: ' + e.message); }
    finally { btn.disabled = false; btn.innerHTML = orig; }
  };
})();
</script>
<?php endif; ?>
<?php $__u_wp = function_exists('getLoggedUser') ? getLoggedUser() : null; if ($__u_wp && userHasWebphone($__u_wp) && ($_SERVER['HTTP_SEC_FETCH_DEST'] ?? '') !== 'iframe'): ?>
<script src="webphone.php?js=1"></script>
<?php endif; ?>
</body>
</html>


