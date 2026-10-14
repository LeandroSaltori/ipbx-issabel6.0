<?php
/**
 * IPbx Prisma - Console da central (Configurações > Console da central).
 * Terminal web, somente para administradores, que envia comandos à central como o "asterisk -rx" do SSH.
 * Não é um terminal do sistema operacional: só roda comandos da lista abaixo (consulta e recargas suaves).
 */

/** Comandos aceitos (início do comando, em minúsculas). O que não casar com nenhum é recusado. */
function consoleCentralAllowed() {
    return [
        // consultas
        '#^core show (channels( concise| verbose)?|channel \S+|calls|hints|uptime|version|settings|taskprocessors|applications|application \S+|functions|function \S+|codecs|sysinfo|threads|locks|file version|help)$#',
        '#^pjsip show (endpoints|contacts|aors|transports|registrations|identifies|channels|channelstats|settings)$#',
        '#^pjsip show (endpoint|contact|aor|transport|registration|identify) \S+$#',
        '#^pjsip list (endpoints|contacts|aors|transports|registrations|channels)$#',
        '#^sip show (peers|peer \S+|registry|channels|channel \S+|settings|users|user \S+|inuse|tcp|subscriptions|sched|history)$#',
        '#^sip show peer \S+ load$#',
        '#^dialplan show( \S+)?$#',
        '#^(queue|queues) show( \S+)?$#',
        '#^(agent|agents) show$#',
        '#^database show( \S+)?$#',
        '#^database get \S+ \S+$#',
        '#^voicemail show (users|zones)$#',
        '#^iax2 show (peers|registry|channels)$#',
        '#^(http|manager) show \S+$#',
        '#^module show( like \S+)?$#',
        '#^moh show (classes|files)$#',
        '#^confbridge list( \S+)?$#',
        '#^devstate list$#',
        '#^rtp show \S+$#',
        '#^cdr show \S+$#',
        '#^sip qualify peer \S+$#',
        // captura de chamada (a saída aparece no log: use "log")
        '#^sip set debug (peer \S+|ip \S+|off|on)$#',
        '#^pjsip set logger (on|off|host \S+)$#',
        // recargas suaves (não derrubam chamadas)
        '#^dialplan reload$#',
        '#^logger reload$#',
    ];
}

function consoleCentralHelp() {
    return "Console da central (somente administradores). Comandos de consulta e recargas suaves.\n\n"
        . "  core show channels            chamadas ativas\n"
        . "  core show hints               estado dos ramais\n"
        . "  sip show peers                ramais/linhas (SIP)\n"
        . "  sip show peer 208             detalhes de um ramal (SIP)\n"
        . "  pjsip show endpoints          ramais (PJSIP)\n"
        . "  pjsip show endpoint 212       detalhes de um ramal (PJSIP)\n"
        . "  pjsip show contacts           registros PJSIP\n"
        . "  pjsip show transports         transportes PJSIP (UDP/TCP/WS/WSS)\n"
        . "  http show status              servidor web interno (WebSocket)\n"
        . "  queue show                    filas\n"
        . "  sip set debug peer 208        liga a captura SIP do ramal (leia com: log)\n"
        . "  sip set debug off             desliga a captura\n"
        . "  dialplan reload               recarrega o plano de discagem (suave)\n\n"
        . "  log <texto> [linhas]          últimas linhas do log da central que contêm o texto\n"
        . "                                 (ex.: log 208 80). Linhas de autenticação são ocultadas.\n"
        . "  log erros [linhas]            últimos erros e avisos\n"
        . "  limpar                        limpa a tela\n";
}

/** Últimas linhas do log da central (lê só o final do arquivo), sem linhas de autenticação. */
function consoleCentralLog($filtro, $max) {
    $file = '/var/log/asterisk/full';
    if (!is_readable($file)) return ['success' => false, 'error' => 'Log da central não está legível por este usuário.'];
    $max = max(5, min(300, (int)$max));
    $size = @filesize($file) ?: 0;
    $fh = @fopen($file, 'rb');
    if (!$fh) return ['success' => false, 'error' => 'Não foi possível abrir o log.'];
    $janela = 6 * 1024 * 1024; // últimos 6 MB
    if ($size > $janela) fseek($fh, $size - $janela);
    $txt = (string)stream_get_contents($fh);
    fclose($fh);
    $out = [];
    foreach (explode("\n", $txt) as $ln) {
        if ($ln === '') continue;
        if (preg_match('/authorization|digest|nonce=|response=|password|secret/i', $ln)) continue;
        if ($filtro === '@erros') { if (!preg_match('/\b(ERROR|WARNING)\[/', $ln)) continue; }
        elseif ($filtro !== '' && stripos($ln, $filtro) === false) continue;
        $out[] = mb_substr($ln, 0, 400, 'UTF-8');
    }
    $out = array_slice($out, -$max);
    return ['success' => true, 'output' => $out ? implode("\n", $out) : '(nenhuma linha encontrada nos últimos registros)'];
}

/** Executa um comando do console. Retorna ['success'=>bool,'output'=>string] ou ['success'=>false,'error'=>string]. */
function consoleCentralExec($cmd) {
    $cmd = trim(preg_replace('/\s+/', ' ', (string)$cmd));
    if ($cmd === '') return ['success' => true, 'output' => ''];
    if (strlen($cmd) > 160) return ['success' => false, 'error' => 'Comando muito longo.'];
    if (preg_match('/[\x00-\x1f\x7f;|&`$<>\\\\]/', $cmd)) return ['success' => false, 'error' => 'Caracteres não permitidos no comando.'];
    $lc = strtolower($cmd);
    if ($lc === 'help' || $lc === 'ajuda' || $lc === '?') return ['success' => true, 'output' => consoleCentralHelp()];
    if (preg_match('/^log(?: (.+))?$/i', $cmd, $m)) {
        $arg = trim($m[1] ?? '');
        $n = 60;
        if (preg_match('/^(.*?)\s+(\d{1,3})$/', $arg, $mm)) { $arg = trim($mm[1]); $n = (int)$mm[2]; }
        if (strtolower($arg) === 'erros') $arg = '@erros';
        elseif ($arg !== '' && !preg_match('/^[A-Za-z0-9._:@\/\- ]{1,60}$/', $arg)) return ['success' => false, 'error' => 'Texto de busca inválido (use letras, números e . _ : @ / -).'];
        return consoleCentralLog($arg, $n);
    }
    $ok = false;
    foreach (consoleCentralAllowed() as $re) { if (preg_match($re, $lc)) { $ok = true; break; } }
    if (!$ok) return ['success' => false, 'error' => "Comando não permitido neste console. Digite 'help' para ver a lista."];
    $out = asteriskCliOutput($cmd);
    // nunca devolve senhas: linhas com senha/segredo aparecem mascaradas (valores "<Set>" são só indicação)
    $out = preg_replace_callback('/^(.*\b(?:password|passwd|secret|md5_cred|md5secret)\b[^:=\n]*[:=]\s*)(?!<)(\S.*)$/mi', function ($m) { return $m[1] . '[oculto]'; }, $out);
    return ['success' => true, 'output' => ($out === '' ? '(sem saída)' : $out)];
}
