#!/usr/bin/php -q
<?php
/**
 * IPbx Prisma - Script AGI de Notificação WhatsApp (Z-PRO / Prismabot)
 * Executado pelo PABX Asterisk em tempo real no encerramento de chamadas, estouros de fila, NPS e Custom Destinations.
 */

error_reporting(E_ALL);
ini_set('display_errors', 0);

chdir(__DIR__);
require_once __DIR__ . '/includes/functions.php';

// Capturar argumentos passados pelo Asterisk AGI
$caller_id  = trim($argv[1] ?? '');
$event_type = trim($argv[2] ?? 'no_answer');
$called_ext = trim($argv[3] ?? '');
$queue_name = trim($argv[4] ?? '');

// Se caller_id ou ramal não vier nos argumentos CLI, ler do fluxo AGI STDIN
if (empty($caller_id) || strpos($caller_id, '$') !== false) {
    while ($line = fgets(STDIN)) {
        $line = trim($line);
        if ($line === '') break;
        if (strpos($line, 'agi_callerid:') !== false) {
            $caller_id = trim(substr($line, strlen('agi_callerid:')));
        }
        if (strpos($line, 'agi_extension:') !== false && empty($called_ext)) {
            $called_ext = trim(substr($line, strlen('agi_extension:')));
        }
    }
}

// Log inicial de rastreamento AGI
if (function_exists('pabx_log')) {
    pabx_log('whatsapp', 'INFO', "AGI Executado pelo Asterisk [Evento: {$event_type}]", [
        'caller_id'  => $caller_id,
        'called_ext' => $called_ext,
        'queue_name' => $queue_name,
        'cli_argv'   => $argv
    ]);
}

$clean_caller = preg_replace('/\D/', '', $caller_id);
if (empty($clean_caller)) {
    if (function_exists('pabx_log')) {
        pabx_log('whatsapp', 'WARNING', "AGI Interrompido: Caller ID nulo ou inválido", ['raw' => $caller_id]);
    }
    exit(0);
}

$data_hora = date('d/m/Y H:i:s');

// Carregar credenciais da API Z-PRO (Prismabot)
$api_url   = getSetting('api_url')   ?: getSetting('prismabot_api_url');
$api_token = getSetting('api_token') ?: getSetting('prismabot_api_token');

if (empty($api_url) || empty($api_token)) {
    if (function_exists('pabx_log')) {
        pabx_log('whatsapp', 'ERROR', "AGI Interrompido: Credenciais da API Z-PRO não configuradas em Configurações -> API", [
            'api_url' => $api_url ? 'Configurada' : 'Vazia',
            'api_token' => $api_token ? 'Configurado' : 'Vazio'
        ]);
    }
    exit(0);
}

// Se quem discou for um ramal interno (ex: Ramal 201 ou 208), resolver o número para o WhatsApp cadastrado no ramal
$target_number = $clean_caller;
if (strlen($clean_caller) <= 5) {
    $ramal_caller_info = getExtensionCustomData($clean_caller);
    if ($ramal_caller_info && !empty($ramal_caller_info['whatsapp_number'])) {
        $target_number = preg_replace('/\D/', '', $ramal_caller_info['whatsapp_number']);
        if (function_exists('pabx_log')) {
            pabx_log('whatsapp', 'INFO', "Ramal interno {$clean_caller} mapeado para o WhatsApp {$target_number}");
        }
    } else {
        if (function_exists('pabx_log')) {
            pabx_log('whatsapp', 'WARNING', "Ramal interno {$clean_caller} não possui WhatsApp cadastrado no menu Ramais -> Editar Nome");
        }
    }
}

// -------------------------------------------------------------
// 1. NOTIFICAR O ATENDENTE DO RAMAL (Chamada Perdida de Ramal)
// -------------------------------------------------------------
if (!empty($called_ext) && ($event_type === 'no_answer' || $event_type === 'missed_extension')) {
    $ext_info = getExtensionCustomData($called_ext);
    if ($ext_info && !empty($ext_info['whatsapp_number']) && !empty($ext_info['notify_agent_missed'])) {
        $wa_atendente   = preg_replace('/\D/', '', $ext_info['whatsapp_number']);
        $nome_atendente = !empty($ext_info['agent_name']) ? $ext_info['agent_name'] : "Ramal {$called_ext}";

        $msg_atendente = "⚠️ *Notificação de Chamada Perdida*\n\nOlá *{$nome_atendente}*! O cliente número *{$clean_caller}* tentou ligar no seu ramal *{$called_ext}* às *{$data_hora}* e a chamada não foi atendida.";

        sendWhatsAppMessageViaZPro($api_url, $api_token, $wa_atendente, $msg_atendente);
    }
}

// -------------------------------------------------------------
// 2. DISPARAR A REGRA CORRESPONDENTE (Cliente ou Teste de Ramal)
// -------------------------------------------------------------
$DEFAULT_TEMPLATES = [
    'no_answer'  => "⚠️ *Notificação IPbx Prisma*\n\nOlá! Vimos que você ligou para o setor *{NOME_FILA}* às *{DATA_HORA}* e a chamada não pôde ser atendida no momento. Em breve entraremos em contato!",
    'transbordo' => "📲 *Atendimento Prisma WhatsApp*\n\nOlá! Recebemos sua solicitação via PABX às *{DATA_HORA}*. Em instantes um de nossos atendentes dará sequência ao seu atendimento por este canal.",
    'audio'      => "🎙️ *Notificação de Chamada - IPbx Prisma*\n\nInformações da ligação:\n• Cliente: *{CLIENTE}*\n• Atendente: *{ATENDENTE}* (Ramal *{RAMAL}*)\n• Data/Hora: *{DATA_HORA}*\n\nO registro de áudio/gravação da chamada está disponível no sistema.",
    'nps'        => "⭐ *Pesquisa de Satisfação - IPbx Prisma*\n\nOlá! Como você avalia o atendimento recebido pelo atendente *{ATENDENTE}* (Ramal *{RAMAL}*) às *{DATA_HORA}*?\n\nResponda com uma nota de 1 (Péssimo) a 5 (Excelente)."
];

$template = '';
if ($event_type === 'no_answer' || $event_type === 'missed_extension') {
    $template = (function_exists('getRule') && getRule('queue_abandon_msg_default')) ? getRule('queue_abandon_msg_default') : (getSetting('prismabot_msg_no_answer') ?: $DEFAULT_TEMPLATES['no_answer']);
} elseif ($event_type === 'nps') {
    $template = (function_exists('getRule') && getRule('nps_msg')) ? getRule('nps_msg') : (getSetting('prismabot_msg_nps') ?: $DEFAULT_TEMPLATES['nps']);
} elseif ($event_type === 'transbordo') {
    $template = (function_exists('getRule') && getRule('queue_exit_whatsapp_msg')) ? getRule('queue_exit_whatsapp_msg') : (getSetting('prismabot_msg_transbordo') ?: $DEFAULT_TEMPLATES['transbordo']);
} elseif ($event_type === 'audio') {
    $template = (function_exists('getRule') && getRule('missed_client_msg')) ? getRule('missed_client_msg') : (getSetting('prismabot_msg_audio') ?: $DEFAULT_TEMPLATES['audio']);
}

if (!empty($template) && !empty($target_number)) {
    $nome_atendente = (!empty($called_ext) && !empty($ext_info['agent_name'])) ? $ext_info['agent_name'] : "Atendente";
    $nome_fila      = !empty($queue_name) ? $queue_name : "Atendimento";

    $msg_cliente = str_replace(
        ['{CLIENTE}', '{NOME_FILA}', '{ATENDENTE}', '{RAMAL}', '{DATA_HORA}'],
        [$clean_caller, $nome_fila, $nome_atendente, $called_ext, $data_hora],
        $template
    );

    $sent = sendWhatsAppMessageViaZPro($api_url, $api_token, $target_number, $msg_cliente);
    if (function_exists('pabx_log')) {
        pabx_log('whatsapp', $sent ? 'INFO' : 'ERROR', "Resultado do envio da mensagem de evento '{$event_type}'", [
            'target_number' => $target_number,
            'success'       => $sent
        ]);
    }
} else {
    if (function_exists('pabx_log')) {
        pabx_log('whatsapp', 'WARNING', "Nenhum template de mensagem ou número de destino válido para o evento '{$event_type}'", [
            'template'      => $template ? 'Carregado' : 'Vazio',
            'target_number' => $target_number
        ]);
    }
}

exit(0);
