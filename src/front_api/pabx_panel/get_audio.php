<?php
/**
 * IPbx Prisma - Servidor de Áudio para o Fluxograma, Anúncios e Gravações CDR v7.4
 * Localiza, converte (SoX/FFmpeg) e transmite arquivos de áudio do Asterisk (/var/lib/asterisk/sounds/, /var/spool/asterisk/monitor/)
 * Suporta fallback transparente em PCM WAV 16-bit de alta compatibilidade com HTML5 (Chrome/Edge/Firefox/Safari)
 */

// Limpar QUALQUER output buffer que possa ter sido aberto (crítico para audio streaming)
while (ob_get_level()) {
    @ob_end_clean();
}

require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/functions.php';
// Link assinado temporário (enviado por WhatsApp): vale só para a gravação (uid) e dispensa a sessão
$__signed = false;
if (!empty($_GET['uid']) && !empty($_GET['exp']) && !empty($_GET['sig'])) {
    $__signed = audioSigValid((string)$_GET['uid'], $_GET['exp'], (string)$_GET['sig']);
    if (!$__signed) { http_response_code(403); header('Content-Type: text/plain; charset=utf-8'); echo 'Link inválido ou expirado'; exit; }
}
if (!$__signed) authGate('plain');

$file = (!$__signed && isset($_GET['file'])) ? trim($_GET['file']) : '';
$uid  = isset($_GET['uid']) ? trim($_GET['uid']) : '';

$foundPath = null;

// 1. Se for por uniqueid (gravação CDR)
if (!empty($uid)) {
    // A tabela cdr fica no banco asteriskcdrdb (o getAsteriskDBConnection aponta para o banco "asterisk")
    $ast_db = function_exists('getAsteriskPdoConnection') ? getAsteriskPdoConnection('asteriskcdrdb') : null;
    if (!$ast_db) $ast_db = getAsteriskDBConnection();
    if ($ast_db) {
        try {
            $stmt = $ast_db->prepare("SELECT recordingfile, calldate FROM cdr WHERE uniqueid = :uid LIMIT 1");
            $stmt->execute([':uid' => $uid]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!empty($row['recordingfile']) && function_exists('findRecordingPath')) {
                $fp = findRecordingPath($row['recordingfile'], $row['calldate'] ?? '');
                if ($fp !== '') $foundPath = $fp;
            }
            if (!$foundPath && !empty($row['recordingfile'])) {
                $rec = $row['recordingfile'];
                $cdate = $row['calldate'] ?? '';
                $year = date('Y', strtotime($cdate));
                $month = date('m', strtotime($cdate));
                $day = date('d', strtotime($cdate));

                $possible_rec_paths = [
                    "/var/spool/asterisk/monitor/$rec",
                    "/var/spool/asterisk/monitor/$year/$month/$day/$rec",
                    "/var/spool/asterisk/monitor/$year/$month/$rec",
                    "/var/spool/asterisk/monitor/*/$rec",
                ];
                foreach ($possible_rec_paths as $rp) {
                    $matches = glob($rp);
                    if ($matches) {
                        foreach ($matches as $m) {
                            if (file_exists($m) && is_file($m) && filesize($m) > 0) {
                                $foundPath = $m;
                                break 2;
                            }
                        }
                    }
                }
            }
        } catch (Exception $e) {}

        // 1b. Sem gravação na linha encontrada (chamadas de fila têm várias "pernas"): procura nas
        //     demais pernas da mesma chamada (linkedid) e, por último, pelo uniqueid no nome do arquivo.
        if (!$foundPath) {
            try {
                $legs = [];
                $q = $ast_db->prepare("SELECT calldate, recordingfile FROM cdr WHERE recordingfile <> '' AND (linkedid = :u OR uniqueid = :u OR linkedid = (SELECT linkedid FROM (SELECT linkedid FROM cdr WHERE uniqueid = :u LIMIT 1) t)) ORDER BY billsec DESC LIMIT 10");
                $q->execute([':u' => $uid]);
                $legs = $q->fetchAll(PDO::FETCH_ASSOC);
                foreach ($legs as $lg) {
                    $fp = findRecordingPath($lg['recordingfile'], $lg['calldate'] ?? '');
                    if ($fp !== '') { $foundPath = $fp; break; }
                }
            } catch (Exception $e) {}
        }
        if (!$foundPath && preg_match('/^[0-9]+\.[0-9]+$/', $uid)) {
            $root = '/var/spool/asterisk/monitor';
            $g = glob("$root/*{$uid}*") ?: glob("$root/*/*/*/*{$uid}*") ?: [];
            foreach ($g as $m) {
                $rp = realpath($m);
                if ($rp && is_file($rp) && filesize($rp) > 0 && strpos($rp, $root . '/') === 0) { $foundPath = $rp; break; }
            }
        }
    }
}

// 1c. Igual ao relatório clássico: último recurso é procurar em qualquer subpasta do monitor
if (!$foundPath && !empty($uid)) {
    $__names = [];
    if (!empty($row['recordingfile'])) $__names[] = basename($row['recordingfile']);
    foreach ($__names as $__n) {
        $fp = trim((string)@shell_exec('find /var/spool/asterisk/monitor/ -name ' . escapeshellarg($__n) . ' -size +0 2>/dev/null | head -n 1'));
        if ($fp !== '' && is_file($fp)) { $foundPath = $fp; break; }
    }
    if (!$foundPath && preg_match('/^[0-9]+\.[0-9]+$/', $uid)) {
        $fp = trim((string)@shell_exec('find /var/spool/asterisk/monitor/ -name ' . escapeshellarg("*{$uid}*") . ' -size +0 2>/dev/null | head -n 1'));
        if ($fp !== '' && is_file($fp)) $foundPath = $fp;
    }
}

// 2. Se for por nome de arquivo (anúncios, URAs, sons do sistema): resolve só dentro de /var/lib/asterisk/sounds
$soundParts = [];
if (!$foundPath && !empty($file)) {
    $soundParts = asteriskSoundResolve($file);
    if (count($soundParts) === 1) {
        $foundPath = $soundParts[0];
    }
}

// Formatos "crus" do Asterisk (sem cabeçalho) e gravações compostas ("a&b") exigem FFmpeg
$rawFmt = ['sln' => ['s16le', 8000], 'sln16' => ['s16le', 16000], 'g722' => ['g722', 16000], 'ulaw' => ['mulaw', 8000], 'alaw' => ['alaw', 8000]];
$ffm = trim((string)@shell_exec('which ffmpeg 2>/dev/null'));
if ($ffm === '' && file_exists('/usr/bin/ffmpeg')) $ffm = '/usr/bin/ffmpeg';
$ffInput = function ($path) use ($rawFmt) {
    $e = strtolower(pathinfo($path, PATHINFO_EXTENSION));
    if (isset($rawFmt[$e])) return '-f ' . $rawFmt[$e][0] . ' -ar ' . $rawFmt[$e][1] . ' -ac 1 -i ' . escapeshellarg($path);
    return '-i ' . escapeshellarg($path);
};
if ($ffm !== '' && is_executable($ffm) && (count($soundParts) > 1 || ($foundPath && isset($rawFmt[strtolower(pathinfo($foundPath, PATHINFO_EXTENSION))]) && empty($uid)))) {
    $list = count($soundParts) > 1 ? $soundParts : [$foundPath];
    $inputs = ''; $labels = '';
    foreach ($list as $i => $pp) { $inputs .= ' ' . $ffInput($pp); $labels .= "[$i:a]"; }
    $filter = count($list) > 1 ? ' -filter_complex ' . escapeshellarg($labels . 'concat=n=' . count($list) . ':v=0:a=1') : '';
    header('Content-Type: audio/wav');
    header('Cache-Control: no-cache, must-revalidate');
    header('X-Audio-Source: ffmpeg-asterisk');
    passthru(escapeshellcmd($ffm) . $inputs . $filter . ' -f wav -acodec pcm_s16le -ar 16000 -ac 1 - 2>/dev/null');
    exit;
}

if ($foundPath) {
    // Converte para WAV PCM 16-bit em ARQUIVO (cache) e serve com Content-Length + Range:
    // o navegador mostra a duração, permite avançar e nunca recebe resposta vazia.
    $cacheDir = rtrim(sys_get_temp_dir(), '/') . '/ipbx_audio_cache';
    if (!is_dir($cacheDir)) @mkdir($cacheDir, 0700, true);
    $key = md5($foundPath . '|' . @filemtime($foundPath) . '|' . @filesize($foundPath));
    $out = "$cacheDir/$key.wav";
    $valid = function ($f) { return is_file($f) && filesize($f) > 1024; };
    if (!$valid($out)) {
        @unlink($out);
        $tmp = "$out." . bin2hex(random_bytes(4)) . '.tmp.wav';
        $in  = escapeshellarg($foundPath);
        $o   = escapeshellarg($tmp);
        $ext = strtolower(pathinfo($foundPath, PATHINFO_EXTENSION));
        $fi  = isset($rawFmt[$ext]) ? '-f ' . $rawFmt[$ext][0] . ' -ar ' . $rawFmt[$ext][1] . ' -ac 1 ' : '';
        $cmds = [];
        if ($ffm !== '' && is_executable($ffm)) $cmds[] = escapeshellcmd($ffm) . " -nostdin -y -loglevel error {$fi}-i $in -ar 16000 -ac 1 -c:a pcm_s16le -f wav $o 2>&1";
        $sox = trim((string)@shell_exec('which sox 2>/dev/null'));
        if ($sox === '' && file_exists('/usr/bin/sox')) $sox = '/usr/bin/sox';
        if ($sox !== '' && is_executable($sox)) $cmds[] = escapeshellcmd($sox) . " $in -t wav -e signed-integer -b 16 -r 16000 -c 1 $o 2>&1";
        foreach ($cmds as $c) {
            @unlink($tmp);
            @shell_exec($c);
            if ($valid($tmp)) { @rename($tmp, $out); break; }
        }
        @unlink($tmp);
        // Sem conversor: se já for WAV com cabeçalho PCM, serve o original
        if (!$valid($out) && $ext === 'wav') {
            $h = (string)@file_get_contents($foundPath, false, null, 0, 24);
            if (strlen($h) >= 24 && substr($h, 0, 4) === 'RIFF' && unpack('v', substr($h, 20, 2))[1] === 1) $out = $foundPath;
        }
    }
    if (!$valid($out)) {
        http_response_code(415);
        header('Content-Type: text/plain; charset=utf-8');
        echo 'Gravação encontrada, mas não foi possível convertê-la (instale ffmpeg ou sox no servidor)';
        exit;
    }
    $size = filesize($out); $start = 0; $end = $size - 1;
    if (!empty($_SERVER['HTTP_RANGE']) && preg_match('/bytes=(\d*)-(\d*)/', $_SERVER['HTTP_RANGE'], $m)) {
        if ($m[1] !== '') { $start = (int)$m[1]; if ($m[2] !== '') $end = min($end, (int)$m[2]); }
        elseif ($m[2] !== '') { $start = max(0, $size - (int)$m[2]); }
        if ($start > $end || $start >= $size) { http_response_code(416); header("Content-Range: bytes */$size"); exit; }
        http_response_code(206);
        header("Content-Range: bytes $start-$end/$size");
    }
    header('Content-Type: audio/wav');
    header('Accept-Ranges: bytes');
    header('Content-Length: ' . ($end - $start + 1));
    header('Content-Disposition: inline; filename="' . basename($foundPath) . '.wav"');
    header('Cache-Control: private, max-age=300');
    header('X-Audio-Source: ipbx-converted');
    $fh = fopen($out, 'rb'); fseek($fh, $start); $left = $end - $start + 1;
    while ($left > 0 && !feof($fh)) { $buf = fread($fh, min(65536, $left)); if ($buf === false) break; echo $buf; $left -= strlen($buf); flush(); }
    fclose($fh);
    exit;
}

// Arquivo não encontrado: nunca gerar áudio sintético
http_response_code(404);
header('Content-Type: text/plain; charset=utf-8');
echo 'Áudio não encontrado';
exit;
