<?php
/**
 * IPbx Prisma - Monitor de ramais e conexões (Configurações > Monitor de Ramais).
 * Somente leitura: mostra, por ramal, se está online, IP, aparelho (modelo/versão), MAC quando o aparelho informa,
 * quantas vezes caiu e voltou (oscilação) e quantas tentativas de senha errada recebeu.
 * Tudo vem da própria central (registros SIP, banco interno do Asterisk e logs). Nada é alterado.
 */

/** Lê registros dos ramais: ramal => [ip, porta, aparelho, mac, rtt, online]. PJSIP primeiro; SIP antigo como complemento. */
function monitorRamaisParseContacts($txt) {
    $out = [];
    foreach (preg_split('/\r?\n/', (string)$txt) as $ln) {
        // " Contact:  200/sip:200@192.168.1.10:5060;ob   a1b2c3d4  Avail   12.345"
        if (!preg_match('/^\s*Contact:\s+([^\/\s]+)\/(\S+)\s+\S+\s+(Avail|Unavail|NonQual|Unknown|Removed)\b\s*([0-9.]+)?/i', $ln, $m)) continue;
        $ext = $m[1];
        $ip = ''; $porta = '';
        if (preg_match('/@\[?([0-9a-fA-F\.:]+?)\]?(?::(\d+))?(?:;|>|$)/', $m[2], $a)) { $ip = $a[1]; $porta = $a[2] ?? ''; }
        $online = (strcasecmp($m[3], 'Avail') === 0 || strcasecmp($m[3], 'NonQual') === 0);
        // um ramal pode ter varios registros (celular + mesa): fica o que esta online
        if (isset($out[$ext]) && $out[$ext]['online'] && !$online) continue;
        $out[$ext] = ['ip' => $ip, 'porta' => $porta, 'aparelho' => '', 'mac' => '', 'rtt' => ($m[4] ?? '') !== '' ? (float)$m[4] : null, 'online' => $online];
    }
    return $out;
}

/** Complementa com o banco interno do Asterisk ("database show registrar/contact"): aparelho (User-Agent) e MAC se o aparelho informar. */
function monitorRamaisParseRegistrarDb($txt, array $contacts) {
    foreach (preg_split('/\r?\n/', (string)$txt) as $ln) {
        if (!preg_match('~^/registrar/contact/([^;\s]+);\S*\s*:\s*(\{.*\})\s*$~', $ln, $m)) continue;
        $j = json_decode($m[2], true);
        if (!is_array($j)) continue;
        $ext = (string)($j['endpoint'] ?? $m[1]);
        $ua = trim((string)($j['user_agent'] ?? ''));
        if (!isset($contacts[$ext])) {
            $uri = (string)($j['uri'] ?? '');
            $ip = ''; $porta = '';
            if (preg_match('/@\[?([0-9a-fA-F\.:]+?)\]?(?::(\d+))?(?:;|>|$)/', $uri, $a)) { $ip = $a[1]; $porta = $a[2] ?? ''; }
            $contacts[$ext] = ['ip' => $ip, 'porta' => $porta, 'aparelho' => '', 'mac' => '', 'rtt' => null, 'online' => true];
        }
        if ($ua !== '' && $contacts[$ext]['aparelho'] === '') $contacts[$ext]['aparelho'] = $ua;
        if ($contacts[$ext]['mac'] === '') $contacts[$ext]['mac'] = monitorRamaisFindMac($ua . ' ' . (string)($j['uri'] ?? '') . ' ' . (string)($j['path'] ?? ''));
    }
    return $contacts;
}

/** Procura um MAC no texto (so aparece quando o aparelho coloca no User-Agent). Devolve '' se nao houver. */
function monitorRamaisFindMac($txt) {
    if (preg_match('/\b([0-9A-Fa-f]{2}([:\-])(?:[0-9A-Fa-f]{2}\2){4}[0-9A-Fa-f]{2})\b/', $txt, $m)) return strtoupper(str_replace('-', ':', $m[1]));
    if (preg_match('/(?<![0-9A-Fa-f])([0-9A-Fa-f]{12})(?![0-9A-Fa-f])/', $txt, $m) && preg_match('/[A-Fa-f]/', $m[1]) && preg_match('/\d/', $m[1])) {
        return strtoupper(implode(':', str_split($m[1], 2)));
    }
    return '';
}

/** Conta quedas e voltas por ramal no log. Linhas: "[Nov 27 13:00:01] NOTICE[..]: ... Contact 200/sip:200@1.2.3.4:5060 is now Unreachable" */
function monitorRamaisParseFlaps($logTxt, $desdeTs = 0) {
    $res = [];
    $ano = (int)date('Y');
    foreach (preg_split('/\r?\n/', (string)$logTxt) as $ln) {
        if (!preg_match('/Contact\s+([^\/\s]+)\/\S+\s+is now\s+(Unreachable|Reachable)/i', $ln, $m)) continue;
        $ts = 0;
        if (preg_match('/^\[([A-Za-z]{3}\s+\d+\s+\d{2}:\d{2}:\d{2})\]/', $ln, $t)) {
            $ts = (int)strtotime($t[1] . ' ' . $ano);
            if ($ts > time() + 86400) $ts = (int)strtotime($t[1] . ' ' . ($ano - 1)); // log de dezembro lido em janeiro
        }
        if ($desdeTs && $ts && $ts < $desdeTs) continue;
        $e = &$res[$m[1]];
        if (!$e) $e = ['quedas' => 0, 'voltas' => 0, 'ultimo' => 0];
        if (strcasecmp($m[2], 'Unreachable') === 0) $e['quedas']++; else $e['voltas']++;
        if ($ts) $e['ultimo'] = max($e['ultimo'], $ts);
        unset($e);
    }
    return $res;
}

/** Junta tudo. $nomes: ramal => nome. Devolve ['ramais' => [...], 'avisos' => [...], 'desde' => 'texto']. */
function monitorRamaisColetar(array $nomes = []) {
    $avisos = [];
    $contacts = monitorRamaisParseContacts(asteriskCliOutput('pjsip show contacts'));
    $contacts = monitorRamaisParseRegistrarDb(asteriskCliOutput('database show registrar/contact'), $contacts);

    $flapTxt = securityTailFile('/var/log/asterisk/full', 3000000);
    $flaps = [];
    $desde = '';
    if ($flapTxt === null) {
        $avisos[] = 'Registro de eventos da central (full) não encontrado ou sem permissão de leitura: a coluna "Oscilação" fica indisponível.';
    } else {
        $flaps = monitorRamaisParseFlaps($flapTxt, time() - 86400);
        if (preg_match('/^\[([A-Za-z]{3}\s+\d+\s+\d{2}:\d{2}:\d{2})\]/m', $flapTxt, $d)) $desde = $d[1];
    }

    $falhas = [];
    try {
        $sec = securityOverview();
        foreach ($sec['tentativas'] as $t) {
            if ($t['conta'] !== '' && in_array($t['evento'], ['InvalidPassword', 'ChallengeResponseFailed'], true)) $falhas[$t['conta']] = ($falhas[$t['conta']] ?? 0) + 1;
        }
    } catch (Throwable $e) {}

    $exts = array_unique(array_merge(array_keys($nomes), array_keys($contacts), array_keys($flaps), array_keys($falhas)));
    usort($exts, function ($a, $b) { return (int)$a <=> (int)$b ?: strcmp($a, $b); });
    $rows = [];
    foreach ($exts as $e) {
        if (!preg_match('/^\d{2,8}$/', (string)$e)) continue; // so ramais numericos (ignora contas de tronco)
        $c = $contacts[$e] ?? null;
        $f = $flaps[$e] ?? ['quedas' => 0, 'voltas' => 0, 'ultimo' => 0];
        $oscila = ($f['quedas'] + $f['voltas']) >= 6; // 3 quedas+voltas em 24h
        $rows[] = [
            'ramal' => (string)$e, 'nome' => (string)($nomes[$e] ?? ''),
            'online' => $c ? $c['online'] : false, 'registrado' => (bool)$c,
            'ip' => $c['ip'] ?? '', 'porta' => $c['porta'] ?? '', 'aparelho' => $c['aparelho'] ?? '', 'mac' => $c['mac'] ?? '', 'rtt' => $c['rtt'] ?? null,
            'quedas' => $f['quedas'], 'ultima_queda' => $f['ultimo'] ? date('d/m H:i', $f['ultimo']) : '',
            'oscila' => $oscila, 'senha_errada' => $falhas[$e] ?? 0,
        ];
    }
    return ['ramais' => $rows, 'avisos' => $avisos, 'desde' => $desde];
}
