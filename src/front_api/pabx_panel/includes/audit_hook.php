<?php
/**
 * IPbx Prisma - Trilha de auditoria central (quem fez o quê).
 * Registra em logs/user_actions.log cada ação relevante: relatórios consultados,
 * configurações salvas, criações/exclusões, análises de IA, envios, testes, etc.
 * Nunca grava valores de campos sensíveis (chaves, senhas, tokens): só nomes de campos.
 */

function auditCurrentUser() {
    $name = $_SESSION['user_username'] ?? $_SESSION['username'] ?? '';
    if ($name !== '') return $name;
    if (function_exists('getLoggedUser')) {
        try {
            $u = getLoggedUser();
            if (is_array($u)) {
                $n = $u['name'] ?? $u['nome'] ?? $u['username'] ?? '';
                $e = $u['email'] ?? '';
                if ($n !== '') return $e !== '' ? "$n <$e>" : $n;
            }
        } catch (Throwable $e) {}
    }
    return PHP_SAPI === 'cli' ? 'CLI/Cron' : 'System';
}

function auditSafeParams(array $src, array $allow) {
    $out = [];
    foreach ($allow as $k) {
        if (isset($src[$k]) && is_scalar($src[$k]) && $src[$k] !== '') {
            $out[$k] = mb_substr((string)$src[$k], 0, 80);
        }
    }
    return $out;
}

function auditMaskPhone($v) {
    $d = preg_replace('/\D+/', '', (string)$v);
    return strlen($d) > 4 ? str_repeat('*', strlen($d) - 4) . substr($d, -4) : $d;
}

function auditApiLabels() {
    return [
        'sync_assets' => 'Sincronizou ramais/filas/troncos do Issabel',
        'fop_action' => 'Executou ação no painel de operador (FOP)',
        'originate_call' => 'Originou chamada',
        'save_contact' => 'Salvou contato',
        'test_llm_connection' => 'Testou conexão com a IA',
        'analyze_pabx_insights' => 'Pediu análise de IA do PABX',
        'generate_ai_insights' => 'Gerou insights de IA',
        'delete_ai_insight' => 'Excluiu insight de IA',
        'transcribe_audio' => 'Transcreveu áudio',
        'analyze_call_audio' => 'Analisou chamada com IA',
        'get_kpi_calls_detail' => 'Consultou detalhe de KPI de chamadas',
        'save_time_group_rule' => 'Salvou regra de grupo de horário',
        'delete_time_group_rule' => 'Excluiu regra de grupo de horário',
        'update_announcement_audio' => 'Atualizou áudio de anúncio',
        'send_email_report' => 'Enviou relatório por e-mail',
        'send_whatsapp_message' => 'Enviou mensagem por WhatsApp',
        'elevenlabs_tts' => 'Gerou áudio (ElevenLabs)',
        'test_pbx_api' => 'Testou API do PABX',
        'test_prismabot_api' => 'Testou API Prismabot',
        'test_mysql_cdr' => 'Testou conexão MySQL (CDR)',
        'update_announcement' => 'Atualizou anúncio',
    ];
}

function auditPostLabels() {
    return [
        'action_add_blacklist' => 'Adicionou número à blacklist',
        'action_delete_blacklist' => 'Removeu número da blacklist',
        'action_add_contact' => 'Criou contato',
        'action_edit_contact' => 'Editou contato',
        'action_delete_contact' => 'Excluiu contato',
        'action_create_tag' => 'Criou tag',
        'action_bulk_permissions' => 'Aplicou permissões em lote',
        'action_save_ext_permissions' => 'Salvou permissões do ramal',
        'action_save_grid_permissions' => 'Salvou grade de permissões',
        'action_clear_log' => 'Limpou arquivo de log',
        'action_create_ext_api' => 'Criou ramal via API',
        'action_delete_qmsg' => 'Excluiu mensagem de fila',
        'action_save_qmsg' => 'Salvou mensagem de fila',
        'action_save_queue_abandon' => 'Salvou regra de abandono de fila',
        'action_delete_user' => 'Excluiu usuário do painel',
        'action_save_user' => 'Criou/editou usuário do painel',
        'action_ia_rule' => 'Alterou regra de auditoria de IA',
        'action_save_ia' => 'Salvou configurações de IA',
        'action_save_ami' => 'Salvou configuração AMI',
        'action_save_api_settings' => 'Salvou configurações de API',
        'action_save_elevenlabs' => 'Salvou configuração ElevenLabs',
        'action_save_mysql' => 'Salvou configuração MySQL',
        'action_save_pbx_api' => 'Salvou API do PABX',
        'action_save_prismabot_api' => 'Salvou API Prismabot',
        'action_save_public_url' => 'Salvou URL pública',
        'action_save_rules' => 'Salvou regras do WhatsApp',
        'action_reset_defaults' => 'Redefiniu padrões',
        'action_restore_defaults' => 'Restaurou padrões',
        'action_reset_mysql' => 'Redefiniu configuração MySQL',
        'action_reset_pbx_api' => 'Redefiniu API do PABX',
        'action_reset_prismabot_api' => 'Redefiniu API Prismabot',
        'action_send_direct_test_wa' => 'Enviou teste direto de WhatsApp',
        'action_send_manual_test' => 'Enviou teste manual de WhatsApp',
        'action_test_rule_wa' => 'Testou regra de WhatsApp',
        'action_sync_api_extensions' => 'Sincronizou ramais da API',
        'action_sync_custom_destinations' => 'Sincronizou destinos personalizados',
        'action_sync_pabx_calls' => 'Sincronizou chamadas do PABX',
        'action_sync_prismabot' => 'Sincronizou Prismabot',
        'action_test_ami' => 'Testou conexão AMI',
        'action_test_api_connection' => 'Testou conexão da API',
        'action_test_db' => 'Testou conexão com banco',
        'action_test_mysql' => 'Testou conexão MySQL',
        'action_test_pbx_api' => 'Testou API do PABX',
        'action_test_prismabot_api' => 'Testou API Prismabot',
        'action_update_ext_name' => 'Alterou nome do ramal',
    ];
}

function auditPageLabels() {
    return [
        'dashboard' => 'Dashboard', 'ramais' => 'Ramais', 'troncos' => 'Troncos', 'filas' => 'Filas',
        'relatorios' => 'Relatórios', 'whatsapp' => 'WhatsApp', 'telefonia' => 'Telefonia', 'configuracoes' => 'Configurações',
        'ia_analytics' => 'Auditoria & Análise de IA', 'filas_stats' => 'Estatísticas de Filas',
        'cdr_gravacoes' => 'CDR e Gravações', 'graphic_reports' => 'Relatórios Gráficos',
        'relatorio_geral' => 'Relatório Geral', 'relatorio_filas' => 'Relatório de Filas',
        'logs' => 'Logs', 'usuarios' => 'Usuários', 'ia' => 'Configuração de IA', 'smtp' => 'SMTP',
        'ami' => 'AMI', 'api' => 'API', 'api_connection' => 'Conexão API',
    ];
}

/** Grava a linha de auditoria (descrição humana + contexto sem segredos). */
function auditLog($what, array $ctx = [], $module = 'AUDIT') {
    if (!function_exists('pabx_log')) return false;
    return pabx_log('user_actions', 'INFO', "[$module] $what", $ctx);
}

/** Chamado uma vez por requisição, após o portão de login. */
function auditRequest($module = '', $page = '', $isApi = false, $apiAction = '') {
    static $done = false;
    if ($done || PHP_SAPI === 'cli') return;
    $done = true;
    $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
    $safeKeys = ['uid', 'id', 'ext', 'extension', 'queue', 'fila', 'from', 'date_start', 'date_end', 'period', 'periodo', 'src', 'dst', 'status', 'disposition', 'tipo'];

    if ($isApi) {
        $labels = auditApiLabels();
        // Consultas de atualização automática (get_*) geram ruído: só registra a de detalhe de KPI
        if (strpos($apiAction, 'get_') === 0 && $apiAction !== 'get_kpi_calls_detail') return;
        if (in_array($apiAction, ['log_event', 'whatsapp_webhook', 'filas_stats', 'view'], true)) return;
        $what = $labels[$apiAction] ?? ('Executou ação ' . $apiAction);
        $ctx = auditSafeParams($_REQUEST, $safeKeys);
        foreach (['to', 'phone', 'number', 'destino', 'numero'] as $pk) {
            if (!empty($_REQUEST[$pk]) && is_scalar($_REQUEST[$pk])) $ctx[$pk] = auditMaskPhone($_REQUEST[$pk]);
        }
        auditLog($what, $ctx, 'API');
        return;
    }

    $pages = auditPageLabels();
    $pageName = ($pages[$module] ?? $module) . ' > ' . ($pages[$page] ?? $page);
    $ctx = [];

    if ($method === 'POST') {
        $labels = auditPostLabels();
        $found = false;
        foreach (array_keys($_POST) as $k) {
            if (strpos($k, 'action_') !== 0 || $k === 'action_type') continue;
            $found = true;
            $ctx = auditSafeParams($_POST, $safeKeys);
            if (isset($_POST['rule_action']) && is_scalar($_POST['rule_action'])) $ctx['rule_action'] = mb_substr((string)$_POST['rule_action'], 0, 40);
            $ctx['campos'] = implode(',', array_slice(array_diff(array_keys($_POST), [$k, 'password', 'senha']), 0, 25));
            $ctx['tela'] = $pageName;
            auditLog($labels[$k] ?? ('Executou ' . str_replace('_', ' ', substr($k, 7))), $ctx, 'POST');
        }
        if ($found) return;
        if (!empty($_POST)) {
            $ctx = auditSafeParams($_POST, $safeKeys);
            $ctx['tela'] = $pageName;
            auditLog('Enviou formulário', $ctx, 'POST');
        }
        return;
    }

    // Visualização de tela/relatório (evita repetir a mesma tela em 20s)
    $sig = $module . '|' . $page . '|' . http_build_query(auditSafeParams($_GET, $safeKeys));
    $now = time();
    if (isset($_SESSION['__audit_last']) && $_SESSION['__audit_last'][0] === $sig && $now - $_SESSION['__audit_last'][1] < 20) return;
    $_SESSION['__audit_last'] = [$sig, $now];
    $ctx = auditSafeParams($_GET, $safeKeys);
    $isReport = ($module === 'relatorios');
    auditLog(($isReport ? 'Consultou relatório: ' : 'Acessou tela: ') . $pageName, $ctx, $isReport ? 'RELATORIO' : 'TELA');
}
