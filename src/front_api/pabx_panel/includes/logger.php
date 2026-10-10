<?php
/**
 * IPbx Prisma - Núcleo Centralizado de Logs, Diagnósticos & Rastreabilidade v1.0
 * Suporte a rotação automática de arquivos (Max 5MB), categorias especializadas e visualização em tempo real.
 */

if (!defined('PABX_LOG_DIR')) {
    define('PABX_LOG_DIR', __DIR__ . '/../logs');
}

// Categorias padrão de Logs
define('LOG_CAT_SYSTEM',   'system_errors');
define('LOG_CAT_SMTP',     'smtp');
define('LOG_CAT_API',      'api_rest');
define('LOG_CAT_AMI',      'ami');
define('LOG_CAT_AI',       'ai_copilot');
define('LOG_CAT_WA',       'whatsapp');
define('LOG_CAT_SECURITY', 'security');
define('LOG_CAT_AUDIT',    'user_actions');

/**
 * Funções Auxiliares de Registro Rápido
 */
function logSystemError($message, array $context = []) {
    return pabx_log('system_errors', 'ERROR', $message, $context);
}

function logUserAction($action, $details = '', $module = 'GENERAL') {
    return pabx_log('user_actions', 'INFO', "[$module] $action - $details");
}

/**
 * Função Principal de Log Centralizado
 *
 * @param string $category  Categoria do Log (system_errors, smtp, api_rest, user_actions, etc)
 * @param string $level     Nível: DEBUG, INFO, WARNING, ERROR, CRITICAL
 * @param string $message   Mensagem descritiva do evento/erro
 * @param array  $context   Array associativo com dados técnicos de contexto (ex: payload, código de erro)
 * @return bool
 */
function pabx_log($category, $level, $message, array $context = []) {
    try {
        $logDir = defined('PABX_LOG_DIR') ? PABX_LOG_DIR : __DIR__ . '/../logs';
        if (!is_dir($logDir)) {
            @mkdir($logDir, 0777, true);
            @chmod($logDir, 0777);
            @file_put_contents($logDir . '/.htaccess', "Deny from all\n");
            @file_put_contents($logDir . '/index.html', "<!DOCTYPE html><html><head><title>403</title></head><body>Forbidden</body></html>");
        }

        $cleanCategory = preg_replace('/[^a-zA-Z0-9_]/', '', $category ?: 'system_errors');
        $logFile = $logDir . '/' . $cleanCategory . '.log';

        // ─── Rotação Automática de Log (Limite de 5 MB) ──────────────────────
        $maxSizeBytes = 5 * 1024 * 1024;
        if (file_exists($logFile) && filesize($logFile) >= $maxSizeBytes) {
            @unlink($logFile . '.3');
            if (file_exists($logFile . '.2')) @rename($logFile . '.2', $logFile . '.3');
            if (file_exists($logFile . '.1')) @rename($logFile . '.1', $logFile . '.2');
            @rename($logFile, $logFile . '.1');
        }

        // ─── Identificar Origem do Cliente / Usuário ─────────────────────────
        $clientIp = $_SERVER['REMOTE_ADDR'] ?? 'CLI/Cron';
        $userName = $_SESSION['user_username'] ?? $_SESSION['username'] ?? 'System';

        // ─── Formatação de Data com Milissegundos ───────────────────────────
        $microtime = microtime(true);
        $micro = sprintf("%03d", ($microtime - floor($microtime)) * 1000);
        $timestamp = date('Y-m-d H:i:s', (int)$microtime) . '.' . $micro;

        $levelUpper = strtoupper(trim($level ?: 'INFO'));

        // Contexto opcional em formato JSON
        $contextStr = '';
        if (!empty($context)) {
            $contextJson = @json_encode($context, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            if ($contextJson) {
                $contextStr = ' | Context: ' . $contextJson;
            }
        }

        $logLine = sprintf(
            "[%s] [%s] [%s] [IP: %s] [User: %s] %s%s\n",
            $timestamp,
            $levelUpper,
            $cleanCategory,
            $clientIp,
            $userName,
            $message,
            $contextStr
        );

        $written = @file_put_contents($logFile, $logLine, FILE_APPEND | LOCK_EX);
        if ($written === false) {
            @chmod($logFile, 0666);
            @file_put_contents($logFile, $logLine, FILE_APPEND);
        }
        return true;
    } catch (Exception $e) {
        return false;
    }
}

/**
 * Retorna as categorias de log disponíveis com seus respectivos tamanhos e contagem
 */
function get_pabx_log_categories() {
    $categories = [
        LOG_CAT_AUDIT    => ['title' => 'Ações de Usuários (Audit Log)', 'icon' => 'fa-solid fa-user-shield', 'color' => 'emerald'],
        LOG_CAT_SYSTEM   => ['title' => 'Erros do Sistema & PHP', 'icon' => 'fa-solid fa-bug', 'color' => 'rose'],
        LOG_CAT_SMTP     => ['title' => 'SMTP & Disparo E-mails', 'icon' => 'fa-solid fa-envelope', 'color' => 'amber'],
        LOG_CAT_API      => ['title' => 'API REST PABX', 'icon' => 'fa-solid fa-code', 'color' => 'cyan'],
        LOG_CAT_AMI      => ['title' => 'Asterisk AMI (Manager)', 'icon' => 'fa-solid fa-server', 'color' => 'purple'],
        LOG_CAT_AI       => ['title' => 'Copiloto de IA & LLMs', 'icon' => 'fa-solid fa-wand-magic-sparkles', 'color' => 'indigo'],
        LOG_CAT_WA       => ['title' => 'Prismabot WhatsApp API', 'icon' => 'fa-brands fa-whatsapp', 'color' => 'teal'],
        LOG_CAT_SECURITY => ['title' => 'Segurança & Permissões', 'icon' => 'fa-solid fa-shield-halved', 'color' => 'blue'],
    ];

    foreach ($categories as $cat => &$info) {
        $filePath = PABX_LOG_DIR . '/' . $cat . '.log';
        if (file_exists($filePath)) {
            $bytes = filesize($filePath);
            $info['size_formatted'] = formatBytesHuman($bytes);
            $info['exists'] = true;
            $info['modified'] = date('d/m/Y H:i:s', filemtime($filePath));
        } else {
            $info['size_formatted'] = '0 KB';
            $info['exists'] = false;
            $info['modified'] = '-';
        }
    }

    return $categories;
}

/**
 * Lê registros de um arquivo de log com suporte a filtros
 */
function read_pabx_log_entries($category, $limit = 500, $levelFilter = 'ALL', $searchQuery = '') {
    $cleanCategory = preg_replace('/[^a-zA-Z0-9_]/', '', $category ?: 'system_errors');
    $logFile = PABX_LOG_DIR . '/' . $cleanCategory . '.log';

    if (!file_exists($logFile)) {
        return [];
    }

    $lines = @file($logFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    if (empty($lines)) {
        return [];
    }

    // Inverter para mostrar os logs mais recentes no topo
    $lines = array_reverse($lines);

    $results = [];
    $count = 0;
    $levelFilterUpper = strtoupper(trim($levelFilter));
    $queryLower = strtolower(trim($searchQuery));

    foreach ($lines as $line) {
        if ($count >= $limit) break;

        // Filtrar por nível se especificado
        if ($levelFilterUpper !== 'ALL') {
            if (strpos($line, "[{$levelFilterUpper}]") === false) {
                continue;
            }
        }

        // Filtrar por busca textual
        if (!empty($queryLower)) {
            if (strpos(strtolower($line), $queryLower) === false) {
                continue;
            }
        }

        // Parse da linha para objeto estruturado
        $entry = [
            'raw'       => $line,
            'timestamp' => '',
            'level'     => 'INFO',
            'category'  => $cleanCategory,
            'ip'        => '',
            'user'      => '',
            'message'   => $line
        ];

        if (preg_match('/^\[(.*?)\] \[(.*?)\] \[(.*?)\] \[IP: (.*?)\] \[User: (.*?)\] (.*)$/', $line, $m)) {
            $entry['timestamp'] = $m[1];
            $entry['level']     = $m[2];
            $entry['category']  = $m[3];
            $entry['ip']        = $m[4];
            $entry['user']      = $m[5];
            $entry['message']   = $m[6];
        }

        $results[] = $entry;
        $count++;
    }

    return $results;
}

/**
 * Limpa o conteúdo de um arquivo de log
 */
function clear_pabx_log_file($category) {
    $cleanCategory = preg_replace('/[^a-zA-Z0-9_]/', '', $category ?: 'system_errors');
    $logFile = PABX_LOG_DIR . '/' . $cleanCategory . '.log';
    if (file_exists($logFile)) {
        @file_put_contents($logFile, '');
        pabx_log($cleanCategory, 'INFO', "Arquivo de log '{$cleanCategory}' limpo pelo operador.");
        return true;
    }
    return false;
}

function formatBytesHuman($bytes, $precision = 2) {
    $units = ['B', 'KB', 'MB', 'GB'];
    $bytes = max($bytes, 0);
    $pow = floor(($bytes ? log($bytes) : 0) / log(1024));
    $pow = min($pow, count($units) - 1);
    $bytes /= pow(1024, $pow);
    return round($bytes, $precision) . ' ' . $units[$pow];
}

// ─── Registro dos Handlers de Erro Globais do PHP ────────────────────────────
set_error_handler(function($errno, $errstr, $errfile, $errline) {
    // Ignorar erros suprimidos com @
    if (!(error_reporting() & $errno)) {
        return false;
    }

    $level = 'ERROR';
    if ($errno === E_WARNING || $errno === E_USER_WARNING) $level = 'WARNING';
    if ($errno === E_NOTICE  || $errno === E_USER_NOTICE)  $level = 'INFO';

    $basename = basename($errfile);
    pabx_log(LOG_CAT_SYSTEM, $level, "PHP Error: {$errstr} em {$basename}:{$errline}", [
        'errno' => $errno,
        'file'  => $errfile,
        'line'  => $errline
    ]);

    return false; // Permite que o tratamento padrão do PHP continue se necessário
});

set_exception_handler(function($exception) {
    pabx_log(LOG_CAT_SYSTEM, 'CRITICAL', "Exceção Não Tratada: " . $exception->getMessage(), [
        'file'  => $exception->getFile(),
        'line'  => $exception->getLine(),
        'trace' => substr($exception->getTraceAsString(), 0, 500)
    ]);
});
