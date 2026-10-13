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
    $pExt = strtolower(pathinfo($foundPath, PATHINFO_EXTENSION));

    // 1. Tentar SoX para conversão forçada para PCM 16-bit WAV (Alta Compatibilidade com HTML5)
    $sox = trim(@shell_exec('which sox 2>/dev/null'));
    if (empty($sox) && file_exists('/usr/bin/sox')) $sox = '/usr/bin/sox';
    if (!empty($sox) && is_executable($sox)) {
        header('Content-Type: audio/wav');
        header('Content-Disposition: inline; filename="' . basename($foundPath) . '.wav"');
        header('Cache-Control: no-cache, must-revalidate');
        header('X-Audio-Source: sox-transcoder');
        passthru(escapeshellcmd($sox) . ' ' . escapeshellarg($foundPath) . ' -t wav -e signed-integer -b 16 -r 16000 -c 1 - 2>/dev/null');
        exit;
    }

    // 2. Tentar FFmpeg para conversão para PCM 16-bit WAV
    $ffmpeg = trim(@shell_exec('which ffmpeg 2>/dev/null'));
    if (empty($ffmpeg) && file_exists('/usr/bin/ffmpeg')) $ffmpeg = '/usr/bin/ffmpeg';
    if (!empty($ffmpeg) && is_executable($ffmpeg)) {
        header('Content-Type: audio/wav');
        header('Content-Disposition: inline; filename="' . basename($foundPath) . '.wav"');
        header('Cache-Control: no-cache, must-revalidate');
        header('X-Audio-Source: ffmpeg-transcoder');
        passthru(escapeshellcmd($ffmpeg) . ' -i ' . escapeshellarg($foundPath) . ' -f wav -acodec pcm_s16le -ar 16000 -ac 1 - 2>/dev/null');
        exit;
    }

    // 3. Streaming Direto com HTTP headers
    $fsize = filesize($foundPath);
    $mime_map = ['wav'=>'audio/wav','mp3'=>'audio/mpeg','gsm'=>'audio/x-gsm','ogg'=>'audio/ogg'];
    $foundMime = isset($mime_map[$pExt]) ? $mime_map[$pExt] : 'audio/wav';
    header("Content-Type: $foundMime");
    header("Content-Length: $fsize");
    header("Accept-Ranges: bytes");
    header("Cache-Control: no-cache");
    header("X-Audio-Source: direct-file");
    readfile($foundPath);
    exit;
}

// Arquivo não encontrado: nunca gerar áudio sintético
http_response_code(404);
header('Content-Type: text/plain; charset=utf-8');
echo 'Áudio não encontrado';
exit;
