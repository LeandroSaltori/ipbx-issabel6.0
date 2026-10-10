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

$file = isset($_GET['file']) ? trim($_GET['file']) : '';
$uid  = isset($_GET['uid']) ? trim($_GET['uid']) : '';

$foundPath = null;

// 1. Se for por uniqueid (gravação CDR)
if (!empty($uid)) {
    $ast_db = getAsteriskDBConnection();
    if ($ast_db) {
        try {
            $stmt = $ast_db->prepare("SELECT recordingfile, calldate FROM cdr WHERE uniqueid = :uid LIMIT 1");
            $stmt->execute([':uid' => $uid]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!empty($row['recordingfile'])) {
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

// 2. Se for por nome de arquivo (anúncios, uras, sons do sistema)
if (!$foundPath && !empty($file)) {
    $filenameNoExt = pathinfo($file, PATHINFO_FILENAME);
    $paths = [
        "/var/lib/asterisk/sounds/custom/$file",
        "/var/lib/asterisk/sounds/custom/{$filenameNoExt}.wav",
        "/var/lib/asterisk/sounds/custom/{$filenameNoExt}.mp3",
        "/var/lib/asterisk/sounds/custom/{$filenameNoExt}.gsm",
        "/var/lib/asterisk/sounds/pt_BR/$file",
        "/var/lib/asterisk/sounds/pt_BR/{$filenameNoExt}.wav",
        "/var/lib/asterisk/sounds/$file",
        "/var/lib/asterisk/sounds/{$filenameNoExt}.wav",
        __DIR__ . "/sounds/$file",
        __DIR__ . "/sounds/{$filenameNoExt}.wav",
    ];

    foreach ($paths as $p) {
        if (file_exists($p) && is_file($p) && is_readable($p) && filesize($p) > 0) {
            $foundPath = $p;
            break;
        }
    }
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
    $foundMime = isset($mime_map[$pExt]) ? $mime_map[$pExt] : 'audio/wav';
    header("Content-Type: $foundMime");
    header("Content-Length: $fsize");
    header("Accept-Ranges: bytes");
    header("Cache-Control: no-cache");
    header("X-Audio-Source: direct-file");
    readfile($foundPath);
    exit;
}

// -------------------------------------------------------------------------
// Fallback Avançado: Gerar Áudio WAV PCM 16-bit (16000Hz mono) em memória
// Compatível 100% com navegadores modernos (Chrome, Edge, Firefox, Safari)
// -------------------------------------------------------------------------
$sampleRate = 16000;
$duration   = 2.5; // 2.5 segundos
$numSamples = (int)($sampleRate * $duration);

// Sequência de notas de anúncio PABX (Chime: Dó5 523Hz -> Mi5 659Hz -> Sol5 784Hz)
$pcm = '';
for ($i = 0; $i < $numSamples; $i++) {
    $t = $i / $sampleRate;
    $freq = 523.25; // Dó (0 a 0.8s)
    if ($t > 0.8 && $t <= 1.6) $freq = 659.25; // Mi (0.8s a 1.6s)
    if ($t > 1.6) $freq = 783.99; // Sol (1.6s a 2.5s)

    // Envelope de suavização (fade in / fade out por nota)
    $noteT = fmod($t, 0.8);
    $env   = min(1, min($noteT / 0.05, (0.8 - $noteT) / 0.05));

    $val = (int)(16000 * $env * sin(2 * M_PI * $freq * $t));
    $val = max(-32768, min(32767, $val));
    $pcm .= pack('v', $val); // 16-bit signed PCM little endian
}

// Cabeçalho WAV RIFF PCM 16-bit
$dataSize   = strlen($pcm);
$byteRate   = $sampleRate * 1 * 2; // sampleRate * channels * bytesPerSample
$blockAlign = 2; // 1 canal * 2 bytes

$header  = 'RIFF';
$header .= pack('V', 36 + $dataSize);   // ChunkSize
$header .= 'WAVE';
$header .= 'fmt ';
$header .= pack('V', 16);              // Subchunk1Size (PCM = 16)
$header .= pack('v', 1);              // AudioFormat (PCM = 1)
$header .= pack('v', 1);              // NumChannels (1 = mono)
$header .= pack('V', $sampleRate);    // SampleRate (16000 Hz)
$header .= pack('V', $byteRate);      // ByteRate (32000 B/s)
$header .= pack('v', $blockAlign);    // BlockAlign (2 bytes)
$header .= pack('v', 16);             // BitsPerSample (16 bits - CRÍTICO PARA HTML5)
$header .= 'data';
$header .= pack('V', $dataSize);

$wav = $header . $pcm;

header("Content-Type: audio/wav");
header("Content-Length: " . strlen($wav));
header("Accept-Ranges: bytes");
header("Cache-Control: no-cache");
header("X-Audio-Source: generated-16bit-pcm");

echo $wav;
exit;
