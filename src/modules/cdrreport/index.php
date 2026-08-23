<?php
/* vim: set expandtab tabstop=4 softtabstop=4 shiftwidth=4:
  Codificación: UTF-8
  +----------------------------------------------------------------------+
  | Issabel version 5.0 - Módulo Executivo de Ligações                   |
  | http://www.issabel.org                                               |
  +----------------------------------------------------------------------+
  | Copyright (c) 2017 Issabel Foundation                                |
  | Copyright (c) 2006 Palosanto Solutions S. A.                         |
  +----------------------------------------------------------------------+
  $Id: index.php,v 20.0 2026-08-18 Prisma Telecom $ */

include_once "libs/paloSantoGrid.class.php";
include_once "libs/paloSantoDB.class.php";
include_once "libs/paloSantoForm.class.php";
include_once "libs/paloSantoConfig.class.php";
include_once "libs/paloSantoCDR.class.php";
include_once "libs/paloSantoJSON.class.php";
require_once "libs/misc.lib.php";

function formatDateBrCdr($d) {
    if (empty($d) || $d == '-') return '-';
    $d = trim($d);
    if (preg_match('/^(\d{4})-(\d{2})-(\d{2})(.*)$/', $d, $m)) {
        $datePart = "{$m[3]}/{$m[2]}/{$m[1]}";
        $timePart = trim($m[4]);
        return !empty($timePart) ? "$datePart $timePart" : $datePart;
    }
    $ts = strtotime($d);
    return $ts ? date('d/m/Y H:i:s', $ts) : $d;
}

function formatPhoneBrCdr($num) {
    if (empty($num) || $num == '-') return '-';
    $clean = preg_replace('/[^0-9]/', '', $num);
    
    if (strlen($clean) >= 12 && substr($clean, 0, 2) === '00') {
        $clean = substr($clean, 2);
    } elseif (strlen($clean) >= 11 && substr($clean, 0, 1) === '0') {
        $clean = substr($clean, 1);
    }

    if (strlen($clean) == 11) {
        return sprintf('(%s) %s-%s', substr($clean, 0, 2), substr($clean, 2, 5), substr($clean, 7, 4));
    } elseif (strlen($clean) == 10) {
        return sprintf('(%s) %s-%s', substr($clean, 0, 2), substr($clean, 2, 4), substr($clean, 6, 4));
    }
    return $num;
}

function formatSecsCdr($sec) {
    $sec = (int)$sec;
    if ($sec <= 0) return '00:00';
    $m = floor($sec / 60);
    $s = $sec % 60;
    if ($m >= 60) {
        $h = floor($m / 60);
        $m = $m % 60;
        return sprintf('%02dh %02dm %02ds', $h, $m, $s);
    }
    return sprintf('%02d:%02d', $m, $s);
}


function getAddressBookContactsMap() {
    static $map = null;
    if ($map !== null) return $map;
    $map = array();
    $possibleDbPaths = array(
        '/var/www/db/address_book.db',
        '/var/www/html/db/address_book.db'
    );
    $dbPath = '';
    foreach ($possibleDbPaths as $p) {
        if (file_exists($p)) {
            $dbPath = $p;
            break;
        }
    }
    if (empty($dbPath)) return $map;

    try {
        if (class_exists('SQLite3')) {
            $db = @new SQLite3($dbPath, SQLITE3_OPEN_READONLY);
            if ($db) {
                $res = @$db->query("SELECT id, name, last_name, telefono, cell_phone, email, company, notes FROM contact WHERE directory='external'");
                if ($res) {
                    while ($row = $res->fetchArray(SQLITE3_ASSOC)) {
                        $fullName = trim($row['name'] . ' ' . $row['last_name']);
                        if (empty($fullName)) $fullName = $row['name'];
                        $row['fullName'] = $fullName;
                        if (!empty($row['telefono'])) {
                            $map[$row['telefono']] = $row;
                            $clean = preg_replace('/\D/', '', $row['telefono']);
                            if (!empty($clean)) $map[$clean] = $row;
                        }
                        if (!empty($row['cell_phone'])) {
                            $map[$row['cell_phone']] = $row;
                            $clean = preg_replace('/\D/', '', $row['cell_phone']);
                            if (!empty($clean)) $map[$clean] = $row;
                        }
                    }
                }
                $db->close();
            }
        }
    } catch (Exception $e) {}
    return $map;
}

function getCdr7DaysStatsMap($phoneNumbers = array(), $pDB = null) {
    $stats = array();
    if (empty($phoneNumbers) || !is_object($pDB)) return $stats;

    $cleanList = array();
    foreach ($phoneNumbers as $p) {
        $p = trim($p);
        if (!empty($p) && $p != '-') {
            $cleanList[] = $p;
            $digits = preg_replace('/\D/', '', $p);
            if (!empty($digits)) $cleanList[] = $digits;
        }
    }
    $cleanList = array_unique(array_filter($cleanList));
    if (empty($cleanList)) return $stats;

    $sevenDaysAgo = date('Y-m-d 00:00:00', strtotime('-7 days'));
    $inClause = "'" . implode("','", array_map('addslashes', $cleanList)) . "'";

    // Quando o número é SRC no CDR -> Significa chamada recebida deste número (Entrada)
    $sqlOrig = "SELECT src, count(*) as total FROM cdr WHERE calldate >= '$sevenDaysAgo' AND src IN ($inClause) GROUP BY src";
    $resOrig = @$pDB->fetchTable($sqlOrig, true);
    if (is_array($resOrig)) {
        foreach ($resOrig as $row) {
            $stats[$row['src']]['recebidas_do_cliente'] = (int)$row['total'];
        }
    }

    // Quando o número é DST no CDR -> Significa que a empresa/ramal ligou para este número (Saída)
    $sqlRec = "SELECT dst, count(*) as total FROM cdr WHERE calldate >= '$sevenDaysAgo' AND dst IN ($inClause) GROUP BY dst";
    $resRec = @$pDB->fetchTable($sqlRec, true);
    if (is_array($resRec)) {
        foreach ($resRec as $row) {
            $stats[$row['dst']]['ligadas_para_o_cliente'] = (int)$row['total'];
        }
    }
    return $stats;
}

function getAsteriskExtensionNamesMap($pDB = null) {
    static $extMap = null;
    if ($extMap !== null) return $extMap;
    $extMap = array();
    if (!is_object($pDB)) return $extMap;
    $sql = "SELECT extension, name FROM asterisk.users WHERE extension IS NOT NULL AND extension != ''";
    $res = @$pDB->fetchTable($sql, true);
    if (is_array($res)) {
        foreach ($res as $row) {
            $extMap[$row['extension']] = $row['name'];
        }
    }
    return $extMap;
}

function renderCallerWithContactBadge($raw_src, $val_src, $contactsMap = array(), $stats7d = array(), $extNamesMap = array()) {
    $raw_clean = preg_replace('/\D/', '', $raw_src);
    $contact = null;
    if (isset($contactsMap[$raw_src])) {
        $contact = $contactsMap[$raw_src];
    } elseif (!empty($raw_clean) && isset($contactsMap[$raw_clean])) {
        $contact = $contactsMap[$raw_clean];
    }

    $fromClientCount = isset($stats7d[$raw_src]['recebidas_do_cliente']) ? $stats7d[$raw_src]['recebidas_do_cliente'] : (isset($stats7d[$raw_clean]['recebidas_do_cliente']) ? $stats7d[$raw_clean]['recebidas_do_cliente'] : 0);
    $toClientCount = isset($stats7d[$raw_src]['ligadas_para_o_cliente']) ? $stats7d[$raw_src]['ligadas_para_o_cliente'] : (isset($stats7d[$raw_clean]['ligadas_para_o_cliente']) ? $stats7d[$raw_clean]['ligadas_para_o_cliente'] : 0);

    // 1. Se já for um contato cadastrado na Agenda Externa
    if ($contact && !empty($contact['fullName'])) {
        $tooltip = "📇 Contato: " . $contact['fullName'];
        if (!empty($contact['company'])) $tooltip .= "\n🏢 Empresa: " . $contact['company'];
        if (!empty($contact['email'])) $tooltip .= "\n📧 E-mail: " . $contact['email'];
        if (!empty($contact['notes'])) $tooltip .= "\n📝 Obs: " . $contact['notes'];
        $tooltip .= "\n📊 Últimos 7 dias:\n  • ⬇️ Recebidas deste número (Entrada): $fromClientCount\n  • ⬆️ Ligadas para este número (Saída): $toClientCount";

        return "<div style='display:inline-flex; align-items:center; gap:6px; flex-wrap:wrap;'>".
            "<span title='" . htmlspecialchars($tooltip, ENT_QUOTES) . "' style='background:rgba(37,99,235,0.08); color:#1d4ed8; padding:3px 8px; border-radius:6px; font-weight:700; font-size:11px; cursor:help; border:1px solid rgba(37,99,235,0.25); display:inline-flex; align-items:center; gap:4px;'>".
            "👤 " . htmlspecialchars($contact['fullName']) . " <span style='color:#64748b; font-size:10px; font-weight:normal;'>(" . htmlspecialchars($val_src) . ")</span>".
            "</span>".
            "</div>";
    }

    // 2. Verificar se é Ramal Interno (2 a 5 dígitos ou mapeado no PBX)
    $isInternal = false;
    $extName = '';
    if (!empty($raw_src) && isset($extNamesMap[$raw_src])) {
        $isInternal = true;
        $extName = $extNamesMap[$raw_src];
    } elseif (!empty($raw_clean) && isset($extNamesMap[$raw_clean])) {
        $isInternal = true;
        $extName = $extNamesMap[$raw_clean];
    } elseif (strlen($raw_clean) >= 2 && strlen($raw_clean) <= 5 && !empty($raw_clean)) {
        $isInternal = true;
    }

    if ($isInternal) {
        $dispText = !empty($extName) ? "$val_src - $extName" : "$val_src";
        $tooltip = "🏢 Ramal Interno: $dispText\n📊 Últimos 7 dias:\n  • ⬇️ Chamadas recebidas: $toClientCount\n  • ⬆️ Chamadas originadas: $fromClientCount";
        return "<div style='display:inline-flex; align-items:center; gap:6px;'>".
            "<span title='" . htmlspecialchars($tooltip, ENT_QUOTES) . "' style='background:#f1f5f9; color:#334155; padding:3px 8px; border-radius:6px; font-weight:600; font-size:11px; border:1px solid #e2e8f0; display:inline-flex; align-items:center; gap:4px;'>".
            "👤 Ramal " . htmlspecialchars($dispText) .
            "</span>".
            "</div>";
    }

    // 3. Número Externo (PSTN / Celular / Fixo) -> Exibe botão para Salvar na Agenda
    $tooltip = "📞 Número Externo: $val_src\n📊 Últimos 7 dias:\n  • ⬇️ Recebidas deste número (Entrada): $fromClientCount\n  • ⬆️ Ligadas para este número (Saída): $toClientCount";
    $html = "<div style='display:inline-flex; align-items:center; gap:6px;'>".
        "<span title='" . htmlspecialchars($tooltip, ENT_QUOTES) . "' style='font-weight:600; color:#1e293b; cursor:help;'>📞 " . htmlspecialchars($val_src) . "</span>";
    if (!empty($raw_src) && $raw_src != '-') {
        $html .= "<button type='button' onclick=\"openAddressBookModal('" . htmlspecialchars($raw_src, ENT_QUOTES) . "')\" title='📇 Salvar na Agenda Pública\nClique para cadastrar este número na Agenda de Contatos Pública.' style='background:rgba(59,130,246,0.12); color:#2563eb; border:1px solid rgba(59,130,246,0.3); border-radius:50%; width:20px; height:20px; display:inline-flex; align-items:center; justify-content:center; cursor:pointer; font-size:10px; transition:all 0.2s;' onmouseover=\"this.style.background='#2563eb'; this.style.color='#fff';\" onmouseout=\"this.style.background='rgba(59,130,246,0.12)'; this.style.color='#2563eb';\">📇</button>";
    }
    $html .= "</div>";
    return $html;
}


function handleSaveAddressBook($arrConf = array())
{
    while (ob_get_level()) ob_end_clean();
    header('Content-Type: application/json; charset=utf-8');

    $name      = isset($_POST['name']) ? trim($_POST['name']) : '';
    $last_name = isset($_POST['last_name']) ? trim($_POST['last_name']) : '';
    $telefono  = isset($_POST['phone']) ? trim($_POST['phone']) : '';
    $company   = isset($_POST['company']) ? trim($_POST['company']) : '';
    $email     = isset($_POST['email']) ? trim($_POST['email']) : '';
    $notes     = isset($_POST['notes']) ? trim($_POST['notes']) : '';

    if (empty($telefono)) {
        echo json_encode(array('status' => 'error', 'message' => 'O número de telefone é obrigatório.'));
        exit;
    }
    if (empty($name)) {
        $name = $telefono;
    }

    $possibleDbPaths = array(
        '/var/www/db/address_book.db',
        '/var/www/html/db/address_book.db',
        isset($arrConf['issabel_dbdir']) ? $arrConf['issabel_dbdir'] . '/address_book.db' : '/var/www/db/address_book.db'
    );

    $dbPath = '';
    foreach ($possibleDbPaths as $p) {
        if (file_exists($p)) {
            $dbPath = $p;
            break;
        }
    }
    if (empty($dbPath)) {
        $dbPath = '/var/www/db/address_book.db';
    }

    $cleanPhone = preg_replace('/\D/', '', $telefono);

    try {
        if (class_exists('SQLite3')) {
            $db = new SQLite3($dbPath, SQLITE3_OPEN_READWRITE | SQLITE3_OPEN_CREATE);
            
            $stmt = $db->prepare("SELECT id, name, last_name, telefono FROM contact WHERE (telefono = :p1 OR telefono = :p2) AND directory = 'external' LIMIT 1");
            $stmt->bindValue(':p1', $telefono, SQLITE3_TEXT);
            $stmt->bindValue(':p2', $cleanPhone, SQLITE3_TEXT);
            $res = $stmt->execute();
            $existing = $res ? $res->fetchArray(SQLITE3_ASSOC) : false;

            if ($existing && !empty($existing['id'])) {
                $contactName = trim($existing['name'] . ' ' . $existing['last_name']);
                if (empty($contactName)) $contactName = $existing['telefono'];
                echo json_encode(array(
                    'status' => 'exists',
                    'message' => "Este número ($telefono) já está cadastrado na Agenda Pública como '$contactName'!"
                ));
                $db->close();
                exit;
            }

            $insert = $db->prepare("INSERT INTO contact (name, last_name, telefono, cell_phone, home_phone, fax1, fax2, email, iduser, picture, province, city, address, company, company_contact, contact_rol, directory, notes, status, department, im) VALUES (:name, :last_name, :telefono, '', '', '', '', :email, 1, '', '', '', '', :company, '', '', 'external', :notes, 'isPublic', '', '')");
            $insert->bindValue(':name', $name, SQLITE3_TEXT);
            $insert->bindValue(':last_name', $last_name, SQLITE3_TEXT);
            $insert->bindValue(':telefono', $telefono, SQLITE3_TEXT);
            $insert->bindValue(':email', $email, SQLITE3_TEXT);
            $insert->bindValue(':company', $company, SQLITE3_TEXT);
            $insert->bindValue(':notes', $notes, SQLITE3_TEXT);
            $insert->execute();
            $db->close();

            echo json_encode(array(
                'status' => 'success',
                'message' => "Contato '$name' cadastrado com sucesso na Agenda Pública!"
            ));
            exit;
        } else {
            $pdo = new PDO("sqlite:$dbPath");
            $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

            $stmt = $pdo->prepare("SELECT id, name, last_name, telefono FROM contact WHERE (telefono = ? OR telefono = ?) AND directory = 'external' LIMIT 1");
            $stmt->execute(array($telefono, $cleanPhone));
            $existing = $stmt->fetch(PDO::FETCH_ASSOC);

            if ($existing && !empty($existing['id'])) {
                $contactName = trim($existing['name'] . ' ' . $existing['last_name']);
                if (empty($contactName)) $contactName = $existing['telefono'];
                echo json_encode(array(
                    'status' => 'exists',
                    'message' => "Este número ($telefono) já está cadastrado na Agenda Pública como '$contactName'!"
                ));
                exit;
            }

            $insert = $pdo->prepare("INSERT INTO contact (name, last_name, telefono, cell_phone, home_phone, fax1, fax2, email, iduser, picture, province, city, address, company, company_contact, contact_rol, directory, notes, status, department, im) VALUES (?, ?, ?, '', '', '', '', ?, 1, '', '', '', '', ?, '', '', 'external', ?, 'isPublic', '', '')");
            $insert->execute(array($name, $last_name, $telefono, $email, $company, $notes));

            echo json_encode(array(
                'status' => 'success',
                'message' => "Contato '$name' cadastrado com sucesso na Agenda Pública!"
            ));
            exit;
        }
    } catch (Exception $e) {
        echo json_encode(array(
            'status' => 'error',
            'message' => 'Erro ao salvar contato no banco da Agenda: ' . $e->getMessage()
        ));
        exit;
    }
}

function _moduleContent(&$smarty, $module_name)
{
    require_once "modules/$module_name/libs/ringgroup.php";
    require_once "modules/$module_name/libs/queues.php";
    include_once "modules/$module_name/configs/default.conf.php";

    load_language_module($module_name);

    global $arrConf;
    global $arrConfModule;
    $arrConf = array_merge($arrConf, $arrConfModule);

    $filterLocalChannel = true;
    $dsn  = generarDSNSistema('asteriskuser', 'asteriskcdrdb');
    $pDB  = new paloDB($dsn);
    $oCDR = new paloSantoCDR($pDB);

    if (isset($_REQUEST['action']) && $_REQUEST['action'] == 'save_address_book') {
        handleSaveAddressBook($arrConf);
        exit;
    }

    if (isset($_GET['action']) && ($_GET['action'] == 'stream_audio' || $_GET['action'] == 'download_audio')) {
        handleCdrAudioPlayback();
        exit;
    }

    if (isset($_GET['action']) && $_GET['action'] == 'export_excel') {
        handleCdrExportExcel($oCDR, $module_name);
        exit;
    }

    if (isset($_GET['action']) && $_GET['action'] == 'export_pdf') {
        handleCdrExportPdf($oCDR, $module_name);
        exit;
    }

    if (isset($_REQUEST['uniqueid'])) {
        return renderCelDetailsHtml($pDB, $_REQUEST['uniqueid']);
    }

    return renderFullCdrDashboard($oCDR, $pDB, $module_name, $smarty);
}

function handleCdrAudioPlayback()
{
    while (ob_get_level()) ob_end_clean();

    $file = isset($_GET['file']) ? urldecode($_GET['file']) : '';
    $file = basename($file);
    if (empty($file)) {
        header("HTTP/1.1 404 Not Found");
        echo "Arquivo não especificado.";
        exit;
    }

    $possiblePaths = array(
        "/var/spool/asterisk/monitor/$file",
        "/var/spool/asterisk/monitor/" . date('Y/m/d/') . $file,
        "/var/spool/asterisk/monitor/" . date('Y/m/') . $file
    );

    $filePath = '';
    foreach ($possiblePaths as $p) {
        if (file_exists($p)) {
            $filePath = $p;
            break;
        }
    }

    if (empty($filePath)) {
        $find = shell_exec("find /var/spool/asterisk/monitor/ -name " . escapeshellarg($file) . " 2>/dev/null | head -n 1");
        $filePath = trim($find);
    }

    if (!empty($filePath) && file_exists($filePath)) {
        $ext = strtolower(pathinfo($filePath, PATHINFO_EXTENSION));
        $mime = 'audio/wav';
        if ($ext == 'mp3') $mime = 'audio/mpeg';
        if ($ext == 'gsm') $mime = 'audio/x-gsm';

        if ($_GET['action'] == 'download_audio') {
            header('Content-Description: File Transfer');
            header('Content-Type: application/octet-stream');
            header('Content-Disposition: attachment; filename="' . basename($filePath) . '"');
            header('Expires: 0');
            header('Cache-Control: must-revalidate');
            header('Pragma: public');
            header('Content-Length: ' . filesize($filePath));
            readfile($filePath);
            exit;
        } else {
            serveStreamableAudioFile($filePath);
        }
    } else {
        header("HTTP/1.1 404 Not Found");
        echo "Áudio não encontrado no servidor.";
        exit;
    }
}

if (!function_exists('serveStreamableAudioFile')) {
    function serveStreamableAudioFile($filePath)
    {
        while (ob_get_level()) ob_end_clean();

        if (empty($filePath) || !file_exists($filePath)) {
            header("HTTP/1.1 404 Not Found");
            die("404 Audio file not found");
        }

        $ext = strtolower(pathinfo($filePath, PATHINFO_EXTENSION));

        // 1. SoX: PCM 16-bit WAV (8000Hz, Mono, Signed Integer)
        $sox = trim(@shell_exec('which sox 2>/dev/null'));
        if (empty($sox) && file_exists('/usr/bin/sox')) $sox = '/usr/bin/sox';
        if (!empty($sox) && is_executable($sox)) {
            header('Content-Type: audio/wav');
            header('Content-Disposition: inline; filename="call_audio.wav"');
            header('Cache-Control: no-cache, must-revalidate');
            header('Pragma: no-cache');
            passthru(escapeshellcmd($sox) . ' ' . escapeshellarg($filePath) . ' -t wav -e signed-integer -b 16 -r 8000 -c 1 - 2>/dev/null');
            exit;
        }

        // 2. FFmpeg: PCM WAV
        $ffmpeg = trim(@shell_exec('which ffmpeg 2>/dev/null'));
        if (!empty($ffmpeg) && is_executable($ffmpeg)) {
            header('Content-Type: audio/wav');
            header('Content-Disposition: inline; filename="call_audio.wav"');
            header('Cache-Control: no-cache, must-revalidate');
            header('Pragma: no-cache');
            passthru(escapeshellcmd($ffmpeg) . ' -i ' . escapeshellarg($filePath) . ' -f wav -acodec pcm_s16le -ar 8000 -ac 1 - 2>/dev/null');
            exit;
        }

        // 3. LAME: MP3
        $lame = trim(@shell_exec('which lame 2>/dev/null'));
        if (!empty($lame) && is_executable($lame)) {
            header('Content-Type: audio/mpeg');
            header('Content-Disposition: inline; filename="call_audio.mp3"');
            header('Cache-Control: no-cache, must-revalidate');
            header('Pragma: no-cache');
            passthru(escapeshellcmd($lame) . ' -b 64 ' . escapeshellarg($filePath) . ' - 2>/dev/null');
            exit;
        }

        // 4. Fallback: Direct Streaming with HTTP Byte-Ranges
        $fileSize = filesize($filePath);
        $mime = ($ext === 'mp3') ? 'audio/mpeg' : (($ext === 'ogg') ? 'audio/ogg' : 'audio/wav');
        $offset = 0;
        $length = $fileSize;

        if (isset($_SERVER['HTTP_RANGE'])) {
            preg_match('/bytes=(\d+)-(\d+)?/', $_SERVER['HTTP_RANGE'], $matches);
            $offset = intval($matches[1]);
            $end = (isset($matches[2]) && $matches[2] !== '') ? intval($matches[2]) : ($fileSize - 1);
            $length = $end - $offset + 1;
            http_response_code(206);
            header('HTTP/1.1 206 Partial Content');
            header("Content-Range: bytes $offset-$end/$fileSize");
        } else {
            http_response_code(200);
        }

        header('Content-Type: ' . $mime);
        header('Content-Length: ' . $length);
        header('Content-Disposition: inline; filename="' . basename($filePath) . '"');
        header('Accept-Ranges: bytes');
        header('Cache-Control: no-cache, must-revalidate');

        $fp = fopen($filePath, 'rb');
        fseek($fp, $offset);
        $bufferSize = 8192;
        $bytesSent = 0;
        while (!feof($fp) && $bytesSent < $length) {
            $read = min($bufferSize, $length - $bytesSent);
            echo fread($fp, $read);
            flush();
            $bytesSent += $read;
        }
        fclose($fp);
        exit;
    }
}

function renderCelDetailsHtml($pDB, $uniqueid)
{
    while (ob_get_level()) ob_end_clean();
    $arrColumns = array('eventtime', 'eventtype', 'cid_name', 'cid_num', 'cid_dnid', 'exten', 'appname', 'uniqueid');
    $columnas = implode(",", $arrColumns);

    $sPeticionSQL = "SELECT linkedid FROM cel WHERE uniqueid=? LIMIT 1";
    $arrData = $pDB->fetchTable($sPeticionSQL, FALSE, array($uniqueid));
    $linkedId = !empty($arrData[0][0]) ? $arrData[0][0] : $uniqueid;

    $sPeticionSQL = "SELECT $columnas FROM cel WHERE linkedid=? ORDER BY eventtime ASC";
    $arrEvents = $pDB->fetchTable($sPeticionSQL, FALSE, array($linkedId));

    $evtMap = array(
        'CHAN_START'    => array('icon' => '🚀', 'label' => 'Início de Canal', 'color' => '#dbeafe', 'text' => '#1e40af', 'desc' => 'Canal telefônico criado e processamento iniciado no PBX.'),
        'ANSWER'        => array('icon' => '📞', 'label' => 'Atendida', 'color' => '#dcfce7', 'text' => '#15803d', 'desc' => 'Ligação atendida com sucesso pelo destinatário.'),
        'HANGUP'        => array('icon' => '📴', 'label' => 'Desconectada', 'color' => '#fee2e2', 'text' => '#b91c1c', 'desc' => 'Canal ou parte da chamada foi desconectada.'),
        'CHAN_END'      => array('icon' => '🏁', 'label' => 'Fim Canal', 'color' => '#f1f5f9', 'text' => '#475569', 'desc' => 'Canal individual encerrado.'),
        'LINKEDID_END'  => array('icon' => '🔚', 'label' => 'Fim da Ligação', 'color' => '#e0e7ff', 'text' => '#4338ca', 'desc' => 'Todos os canais vinculados à chamada foram finalizados.'),
        'BRIDGE_ENTER'  => array('icon' => '🤝', 'label' => 'Conversa Conectada', 'color' => '#fef3c7', 'text' => '#b45309', 'desc' => 'Ponte de áudio conectada, conversa em andamento.'),
        'BRIDGE_EXIT'   => array('icon' => '🔌', 'label' => 'Conversa Encerrada', 'color' => '#fee2e2', 'text' => '#991b1b', 'desc' => 'Ponte de áudio desconectada entre as partes.'),
        'APP_START'     => array('icon' => '⚙️', 'label' => 'Início Aplicação', 'color' => '#f3e8ff', 'text' => '#6b21a8', 'desc' => 'Execução de aplicação PBX (Dial, Queue, URA, etc).'),
        'APP_END'       => array('icon' => '⚙️', 'label' => 'Fim Aplicação', 'color' => '#f3e8ff', 'text' => '#581c87', 'desc' => 'Término da execução da aplicação PBX.')
    );

    ?>
    <!DOCTYPE html>
    <html lang="pt-BR">
    <head>
        <meta charset="UTF-8">
        <style>
            body { font-family:'Segoe UI', system-ui, sans-serif; font-size:12px; color:#1e293b; padding:15px; margin:0; background:#f8fafc; }
            .cel-header { display:flex; justify-content:space-between; align-items:center; margin-bottom:12px; }
            h3 { margin:0; font-size:15px; color:#0f172a; font-weight:800; }
            .tab-nav { display:flex; gap:8px; margin-bottom:12px; }
            .tab-btn { background:#f1f5f9; color:#475569; border:1px solid #cbd5e1; border-radius:6px; padding:6px 14px; font-weight:700; font-size:11px; cursor:pointer; transition:all 0.2s; }
            .tab-btn.active { background:#7c3aed; color:#fff; border-color:#7c3aed; }
            .cel-info-banner { background:#eff6ff; border-left:4px solid #3b82f6; padding:10px 14px; border-radius:6px; font-size:11px; color:#1e3a8a; margin-bottom:12px; line-height:1.4; }
            
            /* Timeline Styling */
            .timeline-container { position:relative; padding:10px 0 10px 24px; }
            .timeline-container::before { content:''; position:absolute; top:0; bottom:0; left:9px; width:2px; background:#e2e8f0; }
            .timeline-item { position:relative; margin-bottom:16px; }
            .timeline-point { position:absolute; left:-24px; top:2px; width:20px; height:20px; border-radius:50%; background:#7c3aed; color:#fff; display:flex; align-items:center; justify-content:center; font-size:11px; box-shadow:0 0 0 3px #f8fafc; }
            .timeline-card { background:#ffffff; border:1px solid #e2e8f0; border-radius:8px; padding:10px 14px; box-shadow:0 1px 3px rgba(0,0,0,0.05); }
            .timeline-card-head { display:flex; justify-content:space-between; align-items:center; margin-bottom:4px; }
            .timeline-card-title { font-weight:700; font-size:12px; color:#0f172a; display:flex; align-items:center; gap:6px; }
            .timeline-time { font-family:monospace; font-size:11px; color:#64748b; font-weight:600; }
            .timeline-card-body { font-size:11px; color:#475569; line-height:1.4; }

            /* Table Styling */
            table { width:100%; border-collapse:collapse; background:#ffffff; border-radius:8px; overflow:hidden; box-shadow:0 1px 3px rgba(0,0,0,0.05); border:1px solid #e2e8f0; }
            th { background:#334155; color:#ffffff; padding:8px 10px; font-size:10px; text-transform:uppercase; text-align:left; letter-spacing:0.5px; }
            td { padding:8px 10px; border-bottom:1px solid #f1f5f9; font-size:11px; vertical-align:middle; }
            tr:nth-child(even) { background:#f8fafc; }
            .badge-evt { padding:3px 8px; border-radius:6px; font-weight:bold; font-size:10px; display:inline-block; cursor:help; }
            .exten-badge { background:#f1f5f9; color:#334155; padding:2px 6px; border-radius:4px; font-family:monospace; font-weight:bold; font-size:11px; }
        </style>
    </head>
    <body>
        <div class="cel-header">
            <h3>📋 Histórico de Eventos da Chamada (CEL)</h3>
            <div class="tab-nav">
                <button type="button" class="tab-btn active" onclick="switchCelTab('timeline')">⏱️ Linha do Tempo</button>
                <button type="button" class="tab-btn" onclick="switchCelTab('table')">📋 Tabela Técnica</button>
            </div>
        </div>
        <div class="cel-info-banner">
            💡 <strong>Raio-X da Chamada (LinkedID: <?php echo htmlspecialchars($linkedId); ?>)</strong>: Acompanhe a sequência de passos da ligação desde o momento em que entrou na central até a finalização.
        </div>

        <!-- View 1: Linha do Tempo Visual -->
        <div id="celViewTimeline" class="timeline-container">
            <?php if (is_array($arrEvents) && count($arrEvents) > 0): ?>
                <?php foreach ($arrEvents as $ev): ?>
                    <?php
                    $timeStr = !empty($ev[0]) ? date('H:i:s', strtotime($ev[0])) : '-';
                    $evtRaw  = trim($ev[1]);
                    $caller  = !empty($ev[3]) ? $ev[3] : (!empty($ev[2]) ? $ev[2] : 'Desconhecido');
                    $exten   = !empty($ev[5]) ? $ev[5] : '';
                    $app     = !empty($ev[6]) ? $ev[6] : '';

                    $eInfo = isset($evtMap[$evtRaw]) ? $evtMap[$evtRaw] : array('icon'=>'📌', 'label'=>$evtRaw, 'color'=>'#e0e7ff', 'text'=>'#4338ca', 'desc'=>'Evento: '.$evtRaw);
                    ?>
                    <div class="timeline-item">
                        <div class="timeline-point" style="background:<?php echo $eInfo['text']; ?>;"><?php echo $eInfo['icon']; ?></div>
                        <div class="timeline-card">
                            <div class="timeline-card-head">
                                <div class="timeline-card-title">
                                    <span style="background:<?php echo $eInfo['color']; ?>; color:<?php echo $eInfo['text']; ?>; padding:2px 8px; border-radius:4px; font-size:10px; font-weight:800;"><?php echo $eInfo['label']; ?></span>
                                    <?php if (!empty($app)): ?>
                                        <code style="color:#6b21a8; font-size:11px;">[<?php echo htmlspecialchars($app); ?>]</code>
                                    <?php endif; ?>
                                </div>
                                <div class="timeline-time">🕒 <?php echo htmlspecialchars($timeStr); ?> (<?php echo date('d/m/Y', strtotime($ev[0])); ?>)</div>
                            </div>
                            <div class="timeline-card-body">
                                <div><strong>Origem / Caller:</strong> 📞 <?php echo htmlspecialchars($caller); ?> <?php if (!empty($exten)) echo "➔ <strong>Destino/Exten:</strong> 🎯 <span class='exten-badge'>".htmlspecialchars($exten)."</span>"; ?></div>
                                <div style="color:#64748b; margin-top:2px; font-size:10px;"><?php echo htmlspecialchars($eInfo['desc']); ?></div>
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>
            <?php else: ?>
                <div style="text-align:center; padding:25px; color:#64748b;">Nenhum evento registrado para esta chamada.</div>
            <?php endif; ?>
        </div>

        <!-- View 2: Tabela Técnica -->
        <div id="celViewTable" style="display:none;">
            <table>
                <thead>
                    <tr>
                        <th>Data / Hora</th>
                        <th>Evento Asterisk</th>
                        <th>Nome Caller</th>
                        <th>Origem (Num)</th>
                        <th>DNID</th>
                        <th>Exten</th>
                        <th>Aplicação</th>
                        <th>UniqueID</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (is_array($arrEvents) && count($arrEvents) > 0): ?>
                        <?php foreach ($arrEvents as $ev): ?>
                            <?php
                            $evtRaw = trim($ev[1]);
                            $eInfo  = isset($evtMap[$evtRaw]) ? $evtMap[$evtRaw] : array('label'=>$evtRaw, 'color'=>'#e0e7ff', 'text'=>'#4338ca', 'desc'=>'Evento: '.$evtRaw);
                            ?>
                            <tr>
                                <td><span style="color:#334155; font-weight:600; font-size:10px;">📅 <?php echo htmlspecialchars($ev[0]); ?></span></td>
                                <td><span class="badge-evt" style="background:<?php echo $eInfo['color']; ?>; color:<?php echo $eInfo['text']; ?>;"><?php echo $eInfo['label']; ?></span></td>
                                <td><?php echo htmlspecialchars(!empty($ev[2]) ? $ev[2] : '-'); ?></td>
                                <td><span style="font-weight:600; color:#0f172a;">📞 <?php echo htmlspecialchars(!empty($ev[3]) ? $ev[3] : '-'); ?></span></td>
                                <td><?php echo htmlspecialchars(!empty($ev[4]) ? $ev[4] : '-'); ?></td>
                                <td><span class="exten-badge"><?php echo htmlspecialchars(!empty($ev[5]) ? $ev[5] : '-'); ?></span></td>
                                <td><code><?php echo htmlspecialchars(!empty($ev[6]) ? $ev[6] : '-'); ?></code></td>
                                <td><small style="color:#94a3b8; font-size:10px;"><?php echo htmlspecialchars($ev[7]); ?></small></td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>

        <script>
            function switchCelTab(tab) {
                var btnTl = document.querySelectorAll('.tab-btn')[0];
                var btnTb = document.querySelectorAll('.tab-btn')[1];
                var viewTl = document.getElementById('celViewTimeline');
                var viewTb = document.getElementById('celViewTable');
                if (tab === 'timeline') {
                    btnTl.classList.add('active');
                    btnTb.classList.remove('active');
                    viewTl.style.display = 'block';
                    viewTb.style.display = 'none';
                } else {
                    btnTb.classList.add('active');
                    btnTl.classList.remove('active');
                    viewTb.style.display = 'block';
                    viewTl.style.display = 'none';
                }
            }
        </script>
    </body>
    </html>
    <?php
    exit;
}

function handleCdrExportExcel($oCDR, $module_name)
{
    while (ob_get_level()) ob_end_clean();

    $date_start    = isset($_REQUEST['date_start']) ? trim($_REQUEST['date_start']) : date("Y-m-d");
    $date_end      = isset($_REQUEST['date_end']) ? trim($_REQUEST['date_end']) : date("Y-m-d");
    $field_name    = isset($_REQUEST['field_name']) ? trim($_REQUEST['field_name']) : 'dst';
    $field_pattern = isset($_REQUEST['field_pattern']) ? trim($_REQUEST['field_pattern']) : '';
    $status        = isset($_REQUEST['status']) ? trim($_REQUEST['status']) : 'ALL';
    $ringgroup     = isset($_REQUEST['ringgroup']) ? trim($_REQUEST['ringgroup']) : '';

    $paramFiltro = array(
        'date_start'    => $date_start . ' 00:00:00',
        'date_end'      => $date_end . ' 23:59:59',
        'field_name'    => $field_name,
        'field_pattern' => $field_pattern,
        'status'        => $status,
        'ringgroup'     => $ringgroup,
        'limit'         => '100000',
        'timeInSecs'    => 'off'
    );

    $arrResult = $oCDR->listarCDRs($paramFiltro, 100000, 0, true);

    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename=Relatorio_Ligacoes_' . date('Ymd_His') . '.csv');

    $output = fopen('php://output', 'w');
    fprintf($output, chr(0xEF).chr(0xBB).chr(0xBF));
    fputcsv($output, array('Data/Hora', 'Origem', 'Grupo/Fila', 'Destino', 'Canal Origem', 'Canal Destino', 'Status', 'Duracao', 'DID', 'Gravacao'), ';');

    if (is_array($arrResult['cdrs'])) {
        foreach ($arrResult['cdrs'] as $r) {
            $dt     = formatDateBrCdr($r[0]);
            $src    = !empty($r[1]) ? $r[1] : '-';
            $rg     = !empty($r[11]) ? $r[11] : '-';
            $dst    = !empty($r[2]) ? $r[2] : '-';
            $cSrc   = !empty($r[3]) ? $r[3] : '-';
            $cDst   = !empty($r[4]) ? $r[4] : '-';
            $st     = !empty($r[5]) ? $r[5] : '-';
            $dur    = formatSecsCdr($r[8]);
            $did    = !empty($r[16]) ? $r[16] : '-';
            $rec    = !empty($r[9]) ? $r[9] : '-';

            fputcsv($output, array($dt, $src, $rg, $dst, $cSrc, $cDst, $st, $dur, $did, $rec), ';');
        }
    }
    fclose($output);
    exit;
}

function handleCdrExportPdf($oCDR, $module_name)
{
    while (ob_get_level()) ob_end_clean();

    $date_start    = isset($_REQUEST['date_start']) ? trim($_REQUEST['date_start']) : date("Y-m-d");
    $date_end      = isset($_REQUEST['date_end']) ? trim($_REQUEST['date_end']) : date("Y-m-d");
    $field_name    = isset($_REQUEST['field_name']) ? trim($_REQUEST['field_name']) : 'dst';
    $field_pattern = isset($_REQUEST['field_pattern']) ? trim($_REQUEST['field_pattern']) : '';
    $status        = isset($_REQUEST['status']) ? trim($_REQUEST['status']) : 'ALL';
    $ringgroup     = isset($_REQUEST['ringgroup']) ? trim($_REQUEST['ringgroup']) : '';

    $paramFiltro = array(
        'date_start'    => $date_start . ' 00:00:00',
        'date_end'      => $date_end . ' 23:59:59',
        'field_name'    => $field_name,
        'field_pattern' => $field_pattern,
        'status'        => $status,
        'ringgroup'     => $ringgroup,
        'limit'         => '100000',
        'timeInSecs'    => 'off'
    );

    $arrResult = $oCDR->listarCDRs($paramFiltro, 100000, 0, true);

    ?>
    <!DOCTYPE html>
    <html lang="pt-BR">
    <head>
        <meta charset="UTF-8">
        <title>Relatório de Ligações - IPbx Prisma</title>
        <style>
            body { font-family: 'Segoe UI', sans-serif; font-size:12px; color:#1e293b; padding:20px; }
            h2 { margin:0 0 5px 0; color:#0f172a; }
            p { margin:0 0 15px 0; color:#64748b; font-size:11px; }
            table { width:100%; border-collapse:collapse; margin-top:15px; }
            th { background:#334155; color:#ffffff; padding:8px; text-align:left; font-size:11px; text-transform:uppercase; }
            td { padding:8px; border-bottom:1px solid #e2e8f0; }
            tr:nth-child(even) { background:#f8fafc; }
            @media print { .no-print { display:none; } }
        </style>
    </head>
    <body onload="window.print();">
        <div class="no-print" style="margin-bottom:15px;">
            <button onclick="window.print();" style="background:#0284c7; color:#fff; border:none; padding:8px 16px; border-radius:6px; font-weight:bold; cursor:pointer;">🖨️ Imprimir / Salvar PDF</button>
        </div>
        <h2>📞 Relatório de Ligações - IPbx Prisma</h2>
        <p>Gerado em <?php echo date('d/m/Y H:i:s'); ?> | Total: <?php echo is_array($arrResult['cdrs']) ? count($arrResult['cdrs']) : 0; ?> chamadas</p>
        
        <table>
            <thead>
                <tr>
                    <th>Data/Hora</th>
                    <th>Origem</th>
                    <th>Fila / Grupo</th>
                    <th>Destino</th>
                    <th>Status</th>
                    <th>Duração</th>
                    <th>DID</th>
                </tr>
            </thead>
            <tbody>
                <?php if (is_array($arrResult['cdrs'])): ?>
                    <?php foreach ($arrResult['cdrs'] as $r): ?>
                    <tr>
                        <td><?php echo formatDateBrCdr($r[0]); ?></td>
                        <td>📞 <?php echo htmlspecialchars($r[1]); ?></td>
                        <td>🏢 <?php echo htmlspecialchars(!empty($r[11]) ? $r[11] : '-'); ?></td>
                        <td>🎯 <?php echo htmlspecialchars($r[2]); ?></td>
                        <td><?php echo htmlspecialchars($r[5]); ?></td>
                        <td>⏱️ <?php echo formatSecsCdr($r[8]); ?></td>
                        <td><?php echo htmlspecialchars(!empty($r[16]) ? $r[16] : '-'); ?></td>
                    </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </body>
    </html>
    <?php
    exit;
}

function renderFullCdrDashboard($oCDR, $pDB, $module_name, $smarty)
{
    $date_start    = isset($_REQUEST['date_start']) ? trim($_REQUEST['date_start']) : date("Y-m-d");
    $date_end      = isset($_REQUEST['date_end']) ? trim($_REQUEST['date_end']) : date("Y-m-d");
    $field_name    = isset($_REQUEST['field_name']) ? trim($_REQUEST['field_name']) : 'dst';
    $field_pattern = isset($_REQUEST['field_pattern']) ? trim($_REQUEST['field_pattern']) : '';
    $status        = isset($_REQUEST['status']) ? trim($_REQUEST['status']) : 'ALL';
    $ringgroup     = isset($_REQUEST['ringgroup']) ? trim($_REQUEST['ringgroup']) : '';
    $call_scope    = isset($_REQUEST['call_scope']) ? trim($_REQUEST['call_scope']) : 'ALL';
    $isFirstLoad   = !isset($_REQUEST['filter_applied']) && !isset($_REQUEST['page']);
    if ($isFirstLoad) {
        $only_recorded = 1;
        $hide_zero     = 1;
        $min_duration  = 1;
    } else {
        $only_recorded = isset($_REQUEST['only_recorded']) ? 1 : 0;
        $hide_zero     = isset($_REQUEST['hide_zero']) ? 1 : 0;
        $min_duration  = isset($_REQUEST['min_duration']) ? (int)$_REQUEST['min_duration'] : 0;
        if ($hide_zero == 1 && $min_duration < 1) {
            $min_duration = 1;
        } elseif ($hide_zero == 0 && $min_duration == 1) {
            $min_duration = 0;
        }
    }

    $page  = isset($_REQUEST['page']) ? max(1, (int)$_REQUEST['page']) : 1;
    $limit = 20;
    $offset = ($page - 1) * $limit;

    require_once "modules/$module_name/libs/ringgroup.php";
    require_once "modules/$module_name/libs/queues.php";

    $dsn_asterisk = generarDSNSistema('asteriskuser', 'asterisk');
    $pDB_asterisk = new paloDB($dsn_asterisk);
    $dataRG       = array();
    $dataQueue    = array();

    if (class_exists('RingGroup')) {
        $oRG = new RingGroup($pDB_asterisk);
        $resRG = $oRG->getRingGroup();
        if (is_array($resRG)) $dataRG = $resRG;
    }
    if (class_exists('Queue')) {
        $oQueue = new Queue($pDB_asterisk);
        $resQ = $oQueue->getQueue();
        if (is_array($resQ)) $dataQueue = $resQ;
    }
    $groupsMap = $dataRG + $dataQueue;

    $paramFiltro = array(
        'date_start'    => $date_start . ' 00:00:00',
        'date_end'      => $date_end . ' 23:59:59',
        'field_name'    => $field_name,
        'field_pattern' => $field_pattern,
        'status'        => $status,
        'ringgroup'     => $ringgroup,
        'limit'         => '100000',
        'timeInSecs'    => 'off'
    );

    $arrResultAll = $oCDR->listarCDRs($paramFiltro, 100000, 0, true);
    $rawList      = is_array($arrResultAll['cdrs']) ? $arrResultAll['cdrs'] : array();

    // Helper to check if a call is internal (Ramal para Ramal)
    $fnIsInternalCdr = function($src, $dst, $did) {
        if (!empty($did) && $did != '-') return false;
        $s = preg_replace('/\D/', '', (string)$src);
        $d = preg_replace('/\D/', '', (string)$dst);
        return (!empty($s) && strlen($s) <= 5 && !empty($d) && strlen($d) <= 5);
    };

    $filteredList = array();
    foreach ($rawList as $r) {
        $srcNum = !empty($r[1]) ? $r[1] : '';
        $dstNum = !empty($r[2]) ? $r[2] : '';
        $didNum = !empty($r[16]) ? $r[16] : '';
        $isInternal = $fnIsInternalCdr($srcNum, $dstNum, $didNum);

        if ($call_scope == 'internal' && !$isInternal) {
            continue;
        }
        if ($call_scope == 'external' && $isInternal) {
            continue;
        }

        $durSecs = (int)$r[8];
        if ($min_duration > 0 && $durSecs < $min_duration) {
            continue;
        }

        if ($only_recorded == 1) {
            $recFile  = !empty($r[9]) ? $r[9] : '';
            $uniqueId = !empty($r[6]) ? $r[6] : '';
            if (empty($recFile) && !empty($uniqueId)) {
                $globRes = glob("/var/spool/asterisk/monitor/*{$uniqueId}*");
                if (empty($globRes)) {
                    $globRes = glob("/var/spool/asterisk/monitor/*/*/*/*{$uniqueId}*");
                }
                if (!empty($globRes) && isset($globRes[0])) {
                    $recFile = basename($globRes[0]);
                }
            }
            if (empty($recFile)) {
                continue;
            }
            $hasAudio = false;
            $checkPaths = array(
                "/var/spool/asterisk/monitor/$recFile",
                "/var/spool/asterisk/monitor/" . date('Y/m/d/') . $recFile,
                "/var/spool/asterisk/monitor/" . date('Y/m/') . $recFile
            );
            foreach ($checkPaths as $cp) {
                if (file_exists($cp) && filesize($cp) > 44) {
                    $hasAudio = true;
                    break;
                }
            }
            if (!$hasAudio) {
                $findP = trim(shell_exec("find /var/spool/asterisk/monitor/ -name " . escapeshellarg($recFile) . " 2>/dev/null | head -n 1"));
                if (!empty($findP) && file_exists($findP) && filesize($findP) > 44) {
                    $hasAudio = true;
                }
            }
            if (!$hasAudio) {
                continue;
            }
        }

        $filteredList[] = $r;
    }

    $totalCount = count($filteredList);
    $pageList   = array_slice($filteredList, $offset, $limit);
    $totalPages = max(1, ceil($totalCount / $limit));

    // Stats Computation
    $answeredCount  = 0;
    $noAnswerCount  = 0;
    $busyCount      = 0;
    $failedCount    = 0;
    $totalDuration  = 0;
    $hourlyTraffic  = array_fill(0, 24, 0);

    foreach ($filteredList as $r) {
        $st = strtoupper(trim($r[5]));
        $dur = (int)$r[8];
        $totalDuration += $dur;

        if ($st == 'ANSWERED') $answeredCount++;
        elseif ($st == 'NO ANSWER') $noAnswerCount++;
        elseif ($st == 'BUSY') $busyCount++;
        elseif ($st == 'FAILED') $failedCount++;
        else $noAnswerCount++;

        $timeStr = !empty($r[0]) ? $r[0] : '';
        if (preg_match('/(\d{2}):\d{2}:\d{2}/', $timeStr, $m)) {
            $h = (int)$m[1];
            if ($h >= 0 && $h <= 23) $hourlyTraffic[$h]++;
        }
    }

    $avgDuration = $answeredCount > 0 ? (int)round($totalDuration / $answeredCount) : 0;
    $ansPercent  = $totalCount > 0 ? round(($answeredCount / $totalCount) * 100, 1) : 0;
    $missPercent = $totalCount > 0 ? round((($noAnswerCount + $busyCount + $failedCount) / $totalCount) * 100, 1) : 0;

    $exportParams = http_build_query(array(
        'menu' => $module_name,
        'rawmode' => 'yes',
        'date_start' => $date_start,
        'date_end' => $date_end,
        'field_name' => $field_name,
        'field_pattern' => $field_pattern,
        'status' => $status,
        'ringgroup' => $ringgroup
    ));

    ob_start();
    ?>
    <script src="https://cdn.jsdelivr.net/npm/chart.js@3.9.1/dist/chart.min.js"></script>
    <style>
        .cdr-root {
            font-family: 'Segoe UI', -apple-system, BlinkMacSystemFont, sans-serif;
            color: #1e293b;
            padding: 5px;
        }
        .cdr-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 12px;
        }
        .cdr-title h2 {
            margin: 0;
            font-size: 20px;
            font-weight: 800;
            color: #0f172a;
            letter-spacing: -0.3px;
        }
        .cdr-title p {
            margin: 1px 0 0 0;
            font-size: 11px;
            color: #64748b;
        }
        .cdr-top-btns {
            display: flex;
            gap: 8px;
        }
        .btn-top {
            padding: 6px 12px;
            border-radius: 6px;
            font-weight: 700;
            font-size: 12px;
            text-decoration: none;
            border: none;
            cursor: pointer;
            display: inline-flex;
            align-items: center;
            gap: 5px;
            box-shadow: 0 1px 2px rgba(0,0,0,0.05);
            transition: all 0.2s;
        }
        .btn-top:hover { transform: translateY(-1px); opacity: 0.95; }
        .btn-top-manual { background: #0284c7; color: #ffffff; }
        .btn-top-expand { background: #0d9488; color: #ffffff; }

        .filter-card-box {
            background: #ffffff;
            border-radius: 10px;
            padding: 12px 16px;
            margin-bottom: 15px;
            box-shadow: 0 1px 4px rgba(0,0,0,0.04);
            border: 1px solid #e2e8f0;
        }
        .filter-inline-row {
            display: flex;
            flex-wrap: wrap;
            gap: 10px;
            align-items: flex-end;
        }
        .filter-field-group {
            display: flex;
            flex-direction: column;
            flex: 1;
            min-width: 120px;
        }
        .filter-field-group label {
            font-size: 10px;
            font-weight: 700;
            color: #475569;
            text-transform: uppercase;
            letter-spacing: 0.4px;
            margin-bottom: 3px;
        }
        .filter-input {
            padding: 6px 10px;
            border: 1px solid #cbd5e1;
            border-radius: 6px;
            font-size: 12px;
            color: #0f172a;
            background: #ffffff;
            outline: none;
            height: 32px;
            box-sizing: border-box;
            transition: border-color 0.2s;
        }
        .filter-input:focus { border-color: #6366f1; }
        .filter-btn-row {
            display: flex;
            gap: 6px;
            align-items: center;
        }
        .btn-action {
            height: 32px;
            padding: 0 14px;
            border-radius: 6px;
            font-weight: 700;
            font-size: 12px;
            border: none;
            cursor: pointer;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 5px;
            box-sizing: border-box;
            transition: all 0.2s;
        }
        .btn-action:hover { opacity: 0.9; }
        .btn-search { background: #4f46e5; color: #ffffff; }
        .btn-excel { background: #16a34a; color: #ffffff; }
        .btn-pdf { background: #dc2626; color: #ffffff; }
        .btn-reset { background: #f1f5f9; color: #475569; border: 1px solid #cbd5e1; }

        .kpi-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(170px, 1fr));
            gap: 12px;
            margin-bottom: 15px;
        }
        .kpi-card-item {
            background: #ffffff;
            border-radius: 10px;
            padding: 14px 16px;
            box-shadow: 0 1px 4px rgba(0,0,0,0.04);
            border: 1px solid #f1f5f9;
            border-left: 5px solid #6366f1;
            display: flex;
            flex-direction: column;
            justify-content: space-between;
        }
        .kpi-card-item.purple { border-left-color: #8b5cf6; }
        .kpi-card-item.green { border-left-color: #10b981; }
        .kpi-card-item.red { border-left-color: #ef4444; }
        .kpi-card-item.blue { border-left-color: #3b82f6; }
        .kpi-card-item.amber { border-left-color: #f59e0b; }

        .kpi-card-title {
            font-size: 10px;
            font-weight: 800;
            color: #64748b;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            margin-bottom: 4px;
        }
        .kpi-card-num {
            font-size: 24px;
            font-weight: 800;
            color: #0f172a;
            line-height: 1;
        }
        .kpi-card-desc {
            font-size: 11px;
            color: #94a3b8;
            margin-top: 4px;
        }

        .charts-grid {
            display: grid;
            grid-template-columns: 2fr 1fr;
            gap: 15px;
            margin-bottom: 15px;
        }
        @media (max-width: 900px) { .charts-grid { grid-template-columns: 1fr; } }

        .chart-card-box {
            background: #ffffff;
            border-radius: 10px;
            padding: 14px 16px;
            box-shadow: 0 1px 4px rgba(0,0,0,0.04);
            border: 1px solid #f1f5f9;
            height: 220px;
            display: flex;
            flex-direction: column;
        }
        .chart-card-box h4 {
            margin: 0 0 8px 0;
            font-size: 12px;
            font-weight: 800;
            color: #334155;
            text-transform: uppercase;
            border-bottom: 1px solid #f1f5f9;
            padding-bottom: 4px;
        }
        .chart-canvas-wrapper {
            position: relative;
            flex: 1;
            width: 100%;
            height: 100%;
        }

        .table-card-box {
            background: #ffffff;
            border-radius: 10px;
            box-shadow: 0 1px 4px rgba(0,0,0,0.04);
            border: 1px solid #e2e8f0;
            overflow: hidden;
        }
        .cdr-table {
            width: 100%;
            border-collapse: collapse;
            text-align: left;
        }
        .cdr-table thead {
            background: #334155;
            color: #ffffff;
        }
        .cdr-table th {
            padding: 10px 14px;
            font-size: 10px;
            font-weight: 800;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }
        .cdr-table td {
            padding: 10px 14px;
            border-bottom: 1px solid #f1f5f9;
            font-size: 12px;
            vertical-align: middle;
        }
        .cdr-table tbody tr:hover { background: #f8fafc; }

        .pagination-bar {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 12px 16px;
            background: #ffffff;
            border-top: 1px solid #f1f5f9;
        }
        .pagination-info { font-size: 12px; color: #64748b; font-weight: 600; }
        .pagination-btns { display: flex; gap: 6px; }
        .page-link-btn {
            padding: 5px 12px;
            border-radius: 6px;
            background: #f1f5f9;
            color: #334155;
            font-size: 12px;
            font-weight: 700;
            text-decoration: none;
            border: 1px solid #cbd5e1;
            transition: background 0.2s;
        }
        .page-link-btn:hover { background: #e2e8f0; }
        .page-link-btn.disabled { opacity: 0.5; pointer-events: none; }

        .queue-badge-compact {
            background: #f1f5f9;
            color: #334155;
            padding: 3px 8px;
            border-radius: 6px;
            font-weight: 700;
            font-size: 11px;
            cursor: help;
            border: 1px solid #e2e8f0;
            display: inline-flex;
            align-items: center;
            gap: 4px;
            transition: all 0.2s;
        }
        .queue-badge-compact:hover {
            background: #e2e8f0;
            color: #0f172a;
            border-color: #cbd5e1;
        }

        .status-badge-ans { background:#dcfce7; color:#15803d; border:1px solid #86efac; padding:3px 8px; border-radius:6px; font-weight:bold; font-size:10px; display:inline-flex; align-items:center; gap:3px; }
        .status-badge-noans { background:#fee2e2; color:#b91c1c; border:1px solid #fca5a5; padding:3px 8px; border-radius:6px; font-weight:bold; font-size:10px; display:inline-flex; align-items:center; gap:3px; }
        .status-badge-busy { background:#fef3c7; color:#b45309; border:1px solid #fde68a; padding:3px 8px; border-radius:6px; font-weight:bold; font-size:10px; display:inline-flex; align-items:center; gap:3px; }
        .status-badge-fail { background:#fee2e2; color:#b91c1c; border:1px solid #fca5a5; padding:3px 8px; border-radius:6px; font-weight:bold; font-size:10px; display:inline-flex; align-items:center; gap:3px; }

        #celModalCdr {
            display: none;
            position: fixed;
            top: 0; left: 0; width: 100%; height: 100%;
            background: rgba(15, 23, 42, 0.6);
            backdrop-filter: blur(4px);
            z-index: 99999;
            align-items: center;
            justify-content: center;
        }
        .modal-content-box {
            background: #ffffff;
            border-radius: 12px;
            padding: 24px;
            width: 420px;
            box-shadow: 0 20px 25px -5px rgba(0,0,0,0.2);
            text-align: center;
        }

        /* Sticky Bottom Audio Player */
        .sticky-audio-bar {
            position: fixed !important;
            bottom: -160px;
            left: 0 !important;
            right: 0 !important;
            width: 100% !important;
            max-width: 100vw !important;
            box-sizing: border-box !important;
            background: linear-gradient(135deg, #0f172a 0%, #1e1b4b 100%);
            border-top: 2px solid #8b5cf6;
            box-shadow: 0 -10px 30px rgba(0, 0, 0, 0.5);
            z-index: 2147483647 !important;
            display: flex;
            align-items: center;
            padding: 10px 24px;
            transition: bottom 0.35s cubic-bezier(0.16, 1, 0.3, 1);
            color: #ffffff;
            font-family: 'Segoe UI', system-ui, -apple-system, sans-serif;
            margin: 0 !important;
            transform: none !important;
        }
        .sticky-audio-bar.active {
            bottom: 0 !important;
        }
        .sticky-audio-inner {
            display: flex;
            align-items: center;
            justify-content: space-between;
            width: 100%;
            gap: 20px;
            flex-wrap: wrap;
        }
        .sticky-audio-info {
            display: flex;
            align-items: center;
            gap: 12px;
            min-width: 250px;
        }
        .sticky-audio-icon {
            font-size: 24px;
            background: rgba(139, 92, 246, 0.25);
            border: 1px solid rgba(139, 92, 246, 0.4);
            border-radius: 50%;
            width: 42px;
            height: 42px;
            display: flex;
            align-items: center;
            justify-content: center;
        }
        .sticky-audio-title {
            font-size: 11px;
            font-weight: 700;
            color: #a78bfa;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }
        .sticky-audio-meta {
            font-size: 13px;
            font-weight: 600;
            color: #f8fafc;
            display: flex;
            align-items: center;
            gap: 8px;
            margin-top: 2px;
        }
        .stk-time-badge {
            background: rgba(255, 255, 255, 0.1);
            border: 1px solid rgba(255, 255, 255, 0.2);
            padding: 2px 8px;
            border-radius: 12px;
            font-size: 11px;
            color: #38bdf8;
            font-family: monospace;
        }
        .sticky-audio-controls {
            display: flex;
            flex-direction: column;
            align-items: center;
            flex: 1;
            max-width: 520px;
            gap: 6px;
        }
        .sticky-audio-buttons {
            display: flex;
            align-items: center;
            gap: 10px;
        }
        .btn-audio-ctrl {
            background: rgba(255, 255, 255, 0.1);
            color: #f8fafc;
            border: 1px solid rgba(255, 255, 255, 0.2);
            padding: 4px 12px;
            border-radius: 16px;
            font-size: 11px;
            font-weight: 700;
            cursor: pointer;
            transition: all 0.2s;
        }
        .btn-audio-ctrl:hover {
            background: rgba(255, 255, 255, 0.2);
            transform: translateY(-1px);
        }
        .btn-play-main {
            background: #7c3aed;
            color: #ffffff;
            border: none;
            padding: 6px 18px;
            border-radius: 20px;
            font-size: 12px;
            box-shadow: 0 2px 8px rgba(124, 58, 237, 0.4);
        }
        .sticky-audio-progress-wrap {
            width: 100%;
        }
        .sticky-audio-progress-wrap input[type="range"] {
            width: 100%;
            accent-color: #a855f7;
            height: 6px;
            border-radius: 3px;
            cursor: pointer;
        }
        .sticky-audio-actions {
            display: flex;
            align-items: center;
            gap: 12px;
            min-width: 260px;
            justify-content: flex-end;
        }
        .sticky-speed-selector {
            display: flex;
            background: rgba(255, 255, 255, 0.08);
            border: 1px solid rgba(255, 255, 255, 0.15);
            border-radius: 18px;
            padding: 2px;
            gap: 2px;
        }
        .sticky-speed-selector .speed-btn {
            background: transparent;
            border: none;
            color: #94a3b8;
            padding: 3px 8px;
            border-radius: 14px;
            font-size: 10px;
            font-weight: 700;
            cursor: pointer;
            transition: all 0.2s;
        }
        .sticky-speed-selector .speed-btn.active, .sticky-speed-selector .speed-btn:hover {
            background: #8b5cf6;
            color: #ffffff;
        }
        .btn-audio-download {
            background: rgba(59, 130, 246, 0.2);
            color: #60a5fa;
            border: 1px solid rgba(59, 130, 246, 0.4);
            width: 32px;
            height: 32px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            text-decoration: none;
            font-size: 13px;
            transition: all 0.2s;
        }
        .btn-audio-download:hover {
            background: #3b82f6;
            color: #ffffff;
        }
        .btn-audio-close {
            background: rgba(239, 68, 68, 0.2);
            color: #f87171;
            border: 1px solid rgba(239, 68, 68, 0.4);
            width: 32px;
            height: 32px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            cursor: pointer;
            font-size: 12px;
            font-weight: bold;
            transition: all 0.2s;
        }
        .btn-audio-close:hover {
            background: #ef4444;
            color: #ffffff;
        }
    </style>

    <div class="cdr-root">
        <!-- Header Principal -->
        <div class="cdr-header">
            <div class="cdr-title">
                <h2>Relatório de Ligações (CDR) - IPbx Prisma</h2>
                <p>Histórico detalhado de chamadas recebidas, efetuadas e internas com gravação de áudio</p>
            </div>
            <div class="cdr-top-btns">
                <a href="modules/cdrreport/help/index.html" target="_blank" class="btn-top btn-top-manual">📖 Manual</a>
                <button onclick="window.open('?menu=<?php echo htmlspecialchars($module_name); ?>&rawmode=yes', '_blank')" class="btn-top btn-top-expand">↗ Expandir Aba</button>
            </div>
        </div>

        <!-- Card de Filtros Compacto -->
        <div class="filter-card-box">
            <form method="GET" action="index.php">
                <input type="hidden" name="menu" value="<?php echo htmlspecialchars($module_name); ?>" />
                <input type="hidden" name="filter_applied" value="1" />
                <div class="filter-inline-row">
                    <div class="filter-field-group" title="📅 Data Inicial do Período&#10;Selecione a data de início da busca de chamadas no CDR.">
                        <label>📅 Data Inicial</label>
                        <input type="date" name="date_start" value="<?php echo htmlspecialchars($date_start); ?>" class="filter-input" />
                    </div>
                    <div class="filter-field-group" title="📅 Data Final do Período&#10;Selecione a data limite da busca de ligações.">
                        <label>📅 Data Final</label>
                        <input type="date" name="date_end" value="<?php echo htmlspecialchars($date_end); ?>" class="filter-input" />
                    </div>
                    <div class="filter-field-group" title="📞 Padrão ou Número&#10;Digite o ramal, número de telefone ou texto a ser pesquisado no campo selecionado.">
                        <label>📞 Padrão / Número</label>
                        <input type="text" name="field_pattern" value="<?php echo htmlspecialchars($field_pattern); ?>" placeholder="Ex: 5001 ou 99988..." class="filter-input" />
                    </div>
                    <div class="filter-field-group" title="📌 Campo de Busca SQL&#10;Escolha em qual coluna do CDR aplicar a pesquisa do texto digitado:&#10;• Destino (dst): Ramal ou número chamado&#10;• Origem (src): Número da Bina ou ramal que ligou&#10;• Canal Origem: Porta/tronco de entrada (ex: SIP/1001)&#10;• Fila/Accountcode: Código da fila de atendimento&#10;• DID: Linha direta de entrada no PBX.">
                        <label>📌 Campo Busca</label>
                        <select name="field_name" class="filter-input">
                            <option value="dst" <?php if ($field_name == 'dst') echo 'selected'; ?>>Destino (dst)</option>
                            <option value="src" <?php if ($field_name == 'src') echo 'selected'; ?>>Origem (src)</option>
                            <option value="channel" <?php if ($field_name == 'channel') echo 'selected'; ?>>Canal Origem</option>
                            <option value="accountcode" <?php if ($field_name == 'accountcode') echo 'selected'; ?>>Fila / Accountcode</option>
                            <option value="dstchannel" <?php if ($field_name == 'dstchannel') echo 'selected'; ?>>Canal Destino</option>
                            <option value="did" <?php if ($field_name == 'did') echo 'selected'; ?>>DID Entrante</option>
                        </select>
                    </div>
                    <div class="filter-field-group" title="🚦 Status da Ligação&#10;Filtre por:&#10;• Atendidas (ANSWERED): Houve diálogo&#10;• Não Atendidas (NO ANSWER): Tocou sem resposta&#10;• Ocupado (BUSY): Destino ocupado&#10;• Falhas (FAILED): Erro de rota ou rede.">
                        <label>🚦 Status</label>
                        <select name="status" class="filter-input" onchange="this.form.submit()">
                            <option value="ALL" <?php if ($status == 'ALL') echo 'selected'; ?>>-- Todos os Status --</option>
                            <option value="ANSWERED" <?php if ($status == 'ANSWERED') echo 'selected'; ?>>✅ Atendidas (ANSWERED)</option>
                            <option value="NO ANSWER" <?php if ($status == 'NO ANSWER') echo 'selected'; ?>>📵 Não Atendidas (NO ANSWER)</option>
                            <option value="BUSY" <?php if ($status == 'BUSY') echo 'selected'; ?>>🟡 Ocupado (BUSY)</option>
                            <option value="FAILED" <?php if ($status == 'FAILED') echo 'selected'; ?>>✖ Falhas (FAILED)</option>
                        </select>
                    </div>
                    <div class="filter-field-group" title="🏢 Fila ou Grupo de Atendimento&#10;Filtre pelas ligações direcionadas a uma fila de atendimento específica.">
                        <label>🏢 Fila / Grupo</label>
                        <select name="ringgroup" class="filter-input" onchange="this.form.submit()">
                            <option value="">-- Qualquer Fila/Grupo --</option>
                            <?php if (is_array($groupsMap)): ?>
                                <?php foreach ($groupsMap as $gKey => $gVal): ?>
                                    <?php if (!empty($gKey)): ?>
                                        <option value="<?php echo htmlspecialchars($gKey); ?>" <?php if ($ringgroup == $gKey) echo 'selected'; ?>>🏢 <?php echo htmlspecialchars("$gKey - $gVal"); ?></option>
                                    <?php endif; ?>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </select>
                    </div>
                    <div class="filter-field-group" title="🌐 Escopo da Chamada&#10;Filtre entre ligações que utilizaram troncos externos ou apenas ramal para ramal.">
                        <label>🌐 Escopo</label>
                        <select name="call_scope" class="filter-input" onchange="this.form.submit()">
                            <option value="ALL" <?php if ($call_scope == 'ALL') echo 'selected'; ?>>-- Todas (Internas/Externas) --</option>
                            <option value="external" <?php if ($call_scope == 'external') echo 'selected'; ?>>🌐 Apenas Externas (PSTN/DID)</option>
                            <option value="internal" <?php if ($call_scope == 'internal') echo 'selected'; ?>>🏢 Apenas Internas (Ramal-Ramal)</option>
                        </select>
                    </div>
                    <div class="filter-field-group" title="⏱️ Duração Mínima&#10;Filtre chamadas que duraram pelo menos a quantidade de tempo selecionada.">
                        <label>⏱️ Duração Mínima</label>
                        <select name="min_duration" id="sel_min_duration_cdr" class="filter-input" onchange="if (this.value > 0) { document.getElementById('chk_hide_zero_cdr').checked = true; } else { document.getElementById('chk_hide_zero_cdr').checked = false; } this.form.submit();">
                            <option value="0" <?php if ($min_duration == 0 && $hide_zero == 0) echo 'selected'; ?>>-- Qualquer Duração --</option>
                            <option value="1" <?php if ($min_duration == 1 || ($min_duration == 0 && $hide_zero == 1)) echo 'selected'; ?>>🚫 Ocultar Zeradas (> 0s)</option>
                            <option value="10" <?php if ($min_duration == 10) echo 'selected'; ?>>≥ 10 segundos</option>
                            <option value="30" <?php if ($min_duration == 30) echo 'selected'; ?>>≥ 30 segundos</option>
                            <option value="60" <?php if ($min_duration == 60) echo 'selected'; ?>>≥ 1 minuto</option>
                            <option value="120" <?php if ($min_duration == 120) echo 'selected'; ?>>≥ 2 minutos</option>
                            <option value="300" <?php if ($min_duration == 300) echo 'selected'; ?>>≥ 5 minutos</option>
                            <option value="600" <?php if ($min_duration == 600) echo 'selected'; ?>>≥ 10 minutos</option>
                        </select>
                    </div>
                    <div class="filter-field-group" style="align-self:flex-end; padding-bottom:6px;">
                        <label style="cursor:pointer; display:flex; align-items:center; gap:6px; font-weight:700; color:#ef4444; font-size:12px; margin:0;" title="Ocultar do relatório todas as ligações que registraram 0 segundos">
                            <input type="checkbox" id="chk_hide_zero_cdr" name="hide_zero" value="1" <?php if ($hide_zero == 1) echo 'checked'; ?> onchange="if (!this.checked) { document.getElementById('sel_min_duration_cdr').value = '0'; } else { document.getElementById('sel_min_duration_cdr').value = '1'; } this.form.submit();" style="width:16px; height:16px; cursor:pointer;" />
                            🚫 Ocultar Zeradas (0s)
                        </label>
                    </div>
                    <div class="filter-field-group" style="align-self:flex-end; padding-bottom:6px;">
                        <label style="cursor:pointer; display:flex; align-items:center; gap:6px; font-weight:700; color:#7c3aed; font-size:12px; margin:0;">
                            <input type="checkbox" name="only_recorded" value="1" <?php if ($only_recorded == 1) echo 'checked'; ?> onchange="this.form.submit();" style="width:16px; height:16px; cursor:pointer;" />
                            🎯 Apenas com Gravação
                        </label>
                    </div>
                    <div class="filter-btn-row">
                        <button type="submit" class="btn-action btn-search" title="🔍 Filtrar Resultados&#10;Buscar ligações no banco CDR com base nos critérios informados.">🔍 Filtrar</button>
                        <a href="?<?php echo $exportParams; ?>&action=export_excel" class="btn-action btn-excel" title="📊 Baixar Excel (.csv)&#10;Exportar a lista inteira de ligações em formato de planilha.">📊 Excel</a>
                        <a href="?<?php echo $exportParams; ?>&action=export_pdf" target="_blank" class="btn-action btn-pdf" title="📄 Gerar Relatório PDF&#10;Abrir visualização pronta para impressão ou salvar em documento PDF.">📄 PDF</a>
                        <a href="?menu=<?php echo htmlspecialchars($module_name); ?>" class="btn-action btn-reset" title="🔄 Limpar Filtros&#10;Restaurar o filtro para a data atual.">🔄 Reset</a>
                    </div>
                </div>
            </form>
        </div>

        <!-- Grid de 5 Cards KPIs Executivos -->
        <div class="kpi-grid">
            <div class="kpi-card-item purple" title="📋 Total de Ligações&#10;Soma de todas as chamadas registradas (recebidas, efetuadas e internas) no período selecionado.">
                <div class="kpi-card-title">📋 Total de Ligações</div>
                <div class="kpi-card-num"><?php echo number_format($totalCount, 0, ',', '.'); ?></div>
                <div class="kpi-card-desc">Chamadas no Período</div>
            </div>
            <div class="kpi-card-item green" title="✅ Ligações Atendidas&#10;Total e percentual de ligações onde o atendimento foi realizado e houve diálogo.">
                <div class="kpi-card-title">✅ Atendidas</div>
                <div class="kpi-card-num"><?php echo number_format($answeredCount, 0, ',', '.'); ?></div>
                <div class="kpi-card-desc"><?php echo $ansPercent; ?>% do total</div>
            </div>
            <div class="kpi-card-item red" title="📵 Ligações Não Atendidas / Ocupado&#10;Total e percentual de chamadas não completadas (chamadas sem resposta, ocupado ou com falha).">
                <div class="kpi-card-title">📵 Não Atendidas</div>
                <div class="kpi-card-num"><?php echo number_format($noAnswerCount + $busyCount + $failedCount, 0, ',', '.'); ?></div>
                <div class="kpi-card-desc"><?php echo $missPercent; ?>% não completaram</div>
            </div>
            <div class="kpi-card-item blue" title="⏱️ Duração Total Falada&#10;Soma acumulada do tempo de conversação de todas as ligações atendidas.">
                <div class="kpi-card-title">⏱️ Duração Total</div>
                <div class="kpi-card-num"><?php echo formatSecsCdr($totalDuration); ?></div>
                <div class="kpi-card-desc">Tempo acumulado de fala</div>
            </div>
            <div class="kpi-card-item amber" title="⏳ Tempo Médio por Chamada&#10;Tempo médio de conversa por cada ligação atendida no PBX.">
                <div class="kpi-card-title">⏳ Tempo Médio</div>
                <div class="kpi-card-num"><?php echo formatSecsCdr($avgDuration); ?></div>
                <div class="kpi-card-desc">Por ligação atendida</div>
            </div>
        </div>

        <!-- Grid de Gráficos -->
        <div class="charts-grid">
            <div class="chart-card-box" title="📊 Volume por Horário&#10;Gráfico de barras indicando a quantidade de chamadas recebidas hora a hora para identificação de picos.">
                <h4>📊 Distribuição de Chamadas por Horário (00h - 23h)</h4>
                <div class="chart-canvas-wrapper">
                    <canvas id="chartCdrHourly"></canvas>
                </div>
            </div>
            <div class="chart-card-box" title="🚦 Status das Chamadas&#10;Gráfico de rosca demonstrando o percentual de Atendidas, Não Atendidas, Ocupadas e Falhas.">
                <h4>🚦 Ocupação e Status das Chamadas</h4>
                <div class="chart-canvas-wrapper">
                    <canvas id="chartCdrStatus"></canvas>
                </div>
            </div>
        </div>

        <!-- Tabela Customizada Moderna -->
        <div class="table-card-box">
            <table class="cdr-table">
                <thead>
                    <tr>
                        <th title="📅 Data e Hora&#10;Momento exato da entrada da ligação no PBX.">Data / Hora</th>
                        <th title="📞 Número de Origem (Bina)&#10;Telefone da pessoa que realizou a ligação ou ramal interno que originou.">Origem (Bina)</th>
                        <th title="🏢 Fila ou Grupo&#10;Selo da fila por onde a ligação passou. Passe o mouse sobre o selo para ver o nome completo da fila.">Fila / Grupo</th>
                        <th title="🎯 Destino&#10;Ramal, número externo ou DID direcionado.">Destino</th>
                        <th title="🚦 Status da Chamada&#10;Classificação do resultado da ligação (Atendida, Não Atendeu, Ocupado ou Falha).">Status</th>
                        <th title="⏱️ Duração da Conversa&#10;Tempo total de fala formatado em minutos e segundos.">Duração</th>
                        <th title="📟 DID / Tronco&#10;Número de linha direta por onde a ligação entrou na central.">DID / Tronco</th>
                        <th title="🎧 Gravação de Áudio&#10;Clique em Play para escutar no player ou Baixar para obter o áudio.">Gravação</th>
                        <th title="📋 Histórico CEL&#10;Clique em CEL para abrir a janela de raio-X do histórico de eventos da chamada no Asterisk.">Eventos</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (is_array($pageList) && count($pageList) > 0): ?>
                        <?php
                        $pageSrcList = array();
                        foreach ($pageList as $_row) {
                            if (!empty($_row[1]) && $_row[1] != '-') $pageSrcList[] = $_row[1];
                        }
                        $abContactsMap = getAddressBookContactsMap();
                        $stats7dMap = getCdr7DaysStatsMap($pageSrcList, $pDB);
                        $extNamesMap = getAsteriskExtensionNamesMap($pDB);
                        ?>
                        <?php foreach ($pageList as $r): ?>
                            <?php
                            $val_data  = formatDateBrCdr($r[0]);
                            $raw_src   = !empty($r[1]) ? $r[1] : '-';
                            $val_src   = formatPhoneBrCdr($raw_src);
                            $raw_rg    = !empty($r[11]) ? trim($r[11]) : '';
                            $val_dst   = !empty($r[2]) ? $r[2] : '-';
                            $raw_st    = strtoupper(trim($r[5]));
                            $val_dur   = formatSecsCdr($r[8]);
                            $recFile   = !empty($r[9]) ? $r[9] : '';
                            $uniqueId  = !empty($r[6]) ? $r[6] : '';
                            $did       = !empty($r[16]) ? $r[16] : '-';

                            // Fallback se a gravação não estiver no campo recordingfile, busca pelo UniqueID no monitor
                            if (empty($recFile) && !empty($uniqueId)) {
                                $globRes = glob("/var/spool/asterisk/monitor/*{$uniqueId}*");
                                if (empty($globRes)) {
                                    $globRes = glob("/var/spool/asterisk/monitor/*/*/*/*{$uniqueId}*");
                                }
                                if (!empty($globRes) && isset($globRes[0])) {
                                    $recFile = basename($globRes[0]);
                                }
                            }

                            $hasValidAudio = false;
                            if (!empty($recFile)) {
                                $checkPaths = array(
                                    "/var/spool/asterisk/monitor/$recFile",
                                    "/var/spool/asterisk/monitor/" . date('Y/m/d/') . $recFile,
                                    "/var/spool/asterisk/monitor/" . date('Y/m/') . $recFile
                                );
                                foreach ($checkPaths as $cp) {
                                    if (file_exists($cp) && filesize($cp) > 44) {
                                        $hasValidAudio = true;
                                        break;
                                    }
                                }
                                if (!$hasValidAudio) {
                                    $findP = trim(shell_exec("find /var/spool/asterisk/monitor/ -name " . escapeshellarg($recFile) . " 2>/dev/null | head -n 1"));
                                    if (!empty($findP) && file_exists($findP) && filesize($findP) > 44) {
                                        $hasValidAudio = true;
                                    }
                                }
                            }

                            if (!empty($raw_rg) && isset($groupsMap[$raw_rg])) {
                                $fullName = "$raw_rg - " . $groupsMap[$raw_rg];
                                $val_rg_html = "<span title='" . htmlspecialchars($fullName, ENT_QUOTES) . "' class='queue-badge-compact'>🏢 $raw_rg</span>";
                            } elseif (!empty($raw_rg) && is_numeric($raw_rg) && strlen($raw_rg) >= 3) {
                                $val_rg_html = "<span title='Fila / Grupo $raw_rg' class='queue-badge-compact'>🏢 $raw_rg</span>";
                            } else {
                                $val_rg_html = "<span style='color:#cbd5e1;'>-</span>";
                            }

                            if (!empty($val_dst) && isset($groupsMap[$val_dst])) {
                                $val_dst_html = "<span title='🏢 Fila: " . htmlspecialchars($val_dst . " - " . $groupsMap[$val_dst], ENT_QUOTES) . "' style='background:#ede9fe; color:#6d28d9; padding:3px 8px; border-radius:6px; font-weight:700; font-size:11px; cursor:help; border:1px solid #ddd6fe; display:inline-flex; align-items:center; gap:4px;'><i class='fa fa-users'></i> " . htmlspecialchars($val_dst) . "</span>";
                            } else {
                                $val_dst_html = "<span style='background:#ede9fe; color:#6d28d9; padding:3px 8px; border-radius:6px; font-weight:600; font-size:11px;'>🎯 " . htmlspecialchars($val_dst) . "</span>";
                            }
                            ?>
                            <tr>
                                <td><span style='color:#334155; font-size:11px; font-weight:600;'>📅 <?php echo htmlspecialchars($val_data); ?></span></td>
                                <td>
                                    <?php echo renderCallerWithContactBadge($raw_src, $val_src, $abContactsMap, $stats7dMap, $extNamesMap); ?>
                                </td>
                                <td><?php echo $val_rg_html; ?></td>
                                <td><?php echo $val_dst_html; ?></td>
                                <td>
                                    <?php
                                    if ($raw_st == 'ANSWERED') {
                                        echo "<span class='status-badge-ans' title='✅ Chamada Atendida&#10;A ligação foi atendida com sucesso e houve diálogo.'>✅ Atendida</span>";
                                    } elseif ($raw_st == 'NO ANSWER') {
                                        echo "<span class='status-badge-noans' title='📵 Não Atendeu&#10;A ligação tocou no ramal ou fila mas ninguém respondeu antes do encerramento.'>📵 Não Atendeu</span>";
                                    } elseif ($raw_st == 'BUSY') {
                                        echo "<span class='status-badge-busy' title='🟡 Ocupado&#10;O ramal de destino estava ocupado em outra chamada.'>🟡 Ocupado</span>";
                                    } elseif ($raw_st == 'CONGESTION') {
                                        echo "<span class='status-badge-fail' title='✖ Desistência / Linha Ocupada (CONGESTION)&#10;O chamador desligou a linha antes que qualquer ramal da fila/grupo atendesse, ou os canais estavam ocupados.'>✖ CONGESTION</span>";
                                    } else {
                                        echo "<span class='status-badge-fail' title='✖ Falha ou Desistência ($raw_st)&#10;A chamada não pôde ser completada devido a desistência rápida do chamador ou indisponibilidade de rota.'>✖ " . htmlspecialchars($raw_st) . "</span>";
                                    }
                                    ?>
                                </td>
                                <td><span style='color:#0f172a; font-weight:700; font-size:11px;'>⏱️ <?php echo htmlspecialchars($val_dur); ?></span></td>
                                <td>
                                    <?php
                                    $cleanSrc = preg_replace('/\D/', '', $raw_src);
                                    $cleanDst = preg_replace('/\D/', '', $val_dst);
                                    $srcIsExt = (strlen($cleanSrc) >= 2 && strlen($cleanSrc) <= 5) || isset($extNamesMap[$raw_src]) || isset($extNamesMap[$cleanSrc]);
                                    $dstIsExt = (strlen($cleanDst) >= 2 && strlen($cleanDst) <= 5) || isset($extNamesMap[$val_dst]) || isset($extNamesMap[$cleanDst]) || isset($groupsMap[$val_dst]);

                                    if (!empty($did) && $did != '-') {
                                        echo "<span title='📥 Chamada de Entrada via Linha/DID: " . htmlspecialchars($did, ENT_QUOTES) . "' style='background:#f0fdf4; color:#166534; border:1px solid #bbf7d0; padding:3px 8px; border-radius:6px; font-weight:700; font-size:11px; display:inline-flex; align-items:center; gap:4px;'><i class='fa fa-hashtag'></i> " . htmlspecialchars($did) . "</span>";
                                    } elseif ($srcIsExt && !$dstIsExt && !empty($val_dst) && $val_dst != '-') {
                                        echo "<span title='📤 Chamada Sainte (Ramal discou para número externo)' style='background:#eff6ff; color:#1e40af; border:1px solid #bfdbfe; padding:3px 8px; border-radius:6px; font-weight:600; font-size:11px; display:inline-flex; align-items:center; gap:4px;'><i class='fa fa-arrow-up'></i> Saída</span>";
                                    } elseif ($srcIsExt && $dstIsExt) {
                                        echo "<span title='🏢 Chamada Interna (Ramal para Ramal)' style='background:#f8fafc; color:#475569; border:1px solid #e2e8f0; padding:3px 8px; border-radius:6px; font-weight:600; font-size:11px; display:inline-flex; align-items:center; gap:4px;'><i class='fa fa-phone'></i> Interno</span>";
                                    } else {
                                        echo "<span style='color:#94a3b8; font-size:11px;'>-</span>";
                                    }
                                    ?>
                                </td>
                                <td>
                                    <?php if (!empty($recFile) && $hasValidAudio): ?>
                                        <?php 
                                        $fileEnc = urlencode($recFile);
                                        $streamUrl = "?menu=" . htmlspecialchars($module_name) . "&rawmode=yes&action=stream_audio&file=" . $fileEnc;
                                        $downUrl   = "?menu=" . htmlspecialchars($module_name) . "&rawmode=yes&action=download_audio&file=" . $fileEnc;
                                        ?>
                                        <div style="display:flex; gap:6px; align-items:center;">
                                            <button type="button" onclick="playCdrAudio('<?php echo htmlspecialchars($streamUrl, ENT_QUOTES); ?>', '<?php echo htmlspecialchars($raw_src, ENT_QUOTES); ?>', '<?php echo htmlspecialchars($val_dst, ENT_QUOTES); ?>', '<?php echo htmlspecialchars($downUrl, ENT_QUOTES); ?>')" style="background: linear-gradient(135deg, #7c3aed, #6d28d9); color: #ffffff; border: none; padding: 4px 10px; border-radius: 20px; font-weight: 700; font-size: 10px; cursor: pointer; box-shadow: 0 2px 6px rgba(124,58,237,0.3); transition: all 0.2s;" title="🎧 Ouvir Gravação">▶ Ouvir</button>
                                            <a href="<?php echo htmlspecialchars($downUrl, ENT_QUOTES); ?>" target="_blank" style="background: rgba(255,255,255,0.08); color: #6d28d9; border: 1px solid rgba(124,58,237,0.4); padding: 3px 9px; border-radius: 20px; font-weight: 600; font-size: 10px; text-decoration: none; transition: all 0.2s;" title="⬇️ Baixar Arquivo de Áudio">⬇️ Baixar</a>
                                        </div>
                                    <?php else: ?>
                                        <span title="Gravação de chamadas desativada para este ramal nas configurações do PBX" style="color:#94a3b8; font-size:10px; background:rgba(148,163,184,0.15); border:1px solid rgba(148,163,184,0.3); border-radius:12px; padding:3px 8px; font-weight:500; display:inline-flex; align-items:center; gap:4px;"><i class="fa fa-microphone-slash"></i> Ramal sem gravação</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php if (!empty($uniqueId)): ?>
                                        <button type="button" onclick="openCelModal('<?php echo htmlspecialchars($uniqueId); ?>')" style="background:#6366f1; color:#fff; border:none; padding:3px 8px; border-radius:6px; font-weight:bold; font-size:10px; cursor:pointer;">📋 CEL</button>
                                    <?php else: ?>
                                        <span style="color:#cbd5e1;">-</span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <tr>
                            <td colspan="9" style="text-align:center; padding:25px; color:#64748b;">
                                🚀 Nenhuma ligação encontrada para os filtros selecionados.
                            </td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>

            <!-- Barra de Paginação -->
            <div class="pagination-bar">
                <div class="pagination-info">
                    Exibindo <?php echo count($pageList); ?> de <?php echo number_format($totalCount, 0, ',', '.'); ?> ligações (Página <?php echo $page; ?> de <?php echo $totalPages; ?>)
                </div>
                <div class="pagination-btns">
                    <?php
                    $navParams = array(
                        'menu' => $module_name,
                        'date_start' => $date_start,
                        'date_end' => $date_end,
                        'field_name' => $field_name,
                        'field_pattern' => $field_pattern,
                        'status' => $status,
                        'ringgroup' => $ringgroup,
                        'call_scope' => $call_scope,
                        'only_recorded' => $only_recorded,
                        'min_duration' => $min_duration,
                        'hide_zero' => $hide_zero
                    );
                    $prevPage = max(1, $page - 1);
                    $nextPage = min($totalPages, $page + 1);

                    $prevUrl = '?' . http_build_query(array_merge($navParams, array('page' => $prevPage)));
                    $nextUrl = '?' . http_build_query(array_merge($navParams, array('page' => $nextPage)));
                    ?>
                    <a href="<?php echo $prevUrl; ?>" class="page-link-btn <?php if ($page <= 1) echo 'disabled'; ?>">◀ Anterior</a>
                    <a href="<?php echo $nextUrl; ?>" class="page-link-btn <?php if ($page >= $totalPages) echo 'disabled'; ?>">Próxima ▶</a>
                </div>
            </div>
        </div>
    </div>

    <!-- Sticky Bottom Audio Player Flutuante -->
    <div id="stickyBottomAudioPlayer" class="sticky-audio-bar">
        <div class="sticky-audio-inner">
            <!-- Info da Chamada -->
            <div class="sticky-audio-info">
                <div class="sticky-audio-icon">🎧</div>
                <div class="sticky-audio-details">
                    <div class="sticky-audio-title">Reproduzindo Gravação</div>
                    <div class="sticky-audio-meta">
                        <span id="stkCaller">📞 -</span> ➔ <span id="stkTarget">🎯 -</span>
                        <span id="stkTime" class="stk-time-badge">00:00 / 00:00</span>
                    </div>
                </div>
            </div>

            <!-- Controles Centrais -->
            <div class="sticky-audio-controls">
                <div class="sticky-audio-buttons">
                    <button type="button" class="btn-audio-ctrl" onclick="stkSeekRelative(-5)" title="Voltar 5 segundos">⏮ -5s</button>
                    <button type="button" id="stkPlayPauseBtn" class="btn-audio-ctrl btn-play-main" onclick="stkTogglePlay()">⏸ Pausar</button>
                    <button type="button" class="btn-audio-ctrl" onclick="stkSeekRelative(5)" title="Avançar 5 segundos">+5s ⏭</button>
                </div>
                <div class="sticky-audio-progress-wrap">
                    <input type="range" id="stkProgressBar" min="0" max="100" value="0" step="0.1" oninput="stkSeekTo(this.value)" />
                </div>
            </div>

            <!-- Velocidade e Ações -->
            <div class="sticky-audio-actions">
                <div class="sticky-speed-selector">
                    <button type="button" class="speed-btn active" onclick="stkSetSpeed(1.0, this)">1.0x</button>
                    <button type="button" class="speed-btn" onclick="stkSetSpeed(1.25, this)">1.25x</button>
                    <button type="button" class="speed-btn" onclick="stkSetSpeed(1.5, this)">1.5x</button>
                    <button type="button" class="speed-btn" onclick="stkSetSpeed(2.0, this)">2.0x</button>
                </div>
                <a id="stkDownloadBtn" href="#" target="_blank" class="btn-audio-download" title="Baixar Áudio">⬇️</a>
                <button type="button" class="btn-audio-close" onclick="closeStickyAudioPlayer()" title="Fechar Player">✖</button>
            </div>
        </div>
        <audio id="stkAudioElement" preload="auto"></audio>
    </div>

    <!-- Modal CEL Events -->
    <div id="celModalCdr">
        <div class="modal-content-box" style="width:820px; max-width:95%;">
            <iframe id="celIframeElement" style="width:100%; height:450px; border:none; border-radius:8px;"></iframe>
            <button onclick="closeCelModal()" style="background:#64748b; color:#fff; border:none; padding:6px 16px; border-radius:6px; font-weight:bold; cursor:pointer; margin-top:10px;">Fechar</button>
        </div>
    </div>

    <!-- Modal Salvar na Agenda Pública -->
    <div id="addressBookModal" style="display:none; position:fixed; top:0; left:0; width:100%; height:100%; background:rgba(15,23,42,0.65); backdrop-filter:blur(4px); z-index:2147483647; align-items:center; justify-content:center;">
        <div style="background:#ffffff; border-radius:14px; padding:24px; width:440px; max-width:92%; box-shadow:0 25px 50px -12px rgba(0,0,0,0.35); text-align:left; border:1px solid #e2e8f0; font-family:'Segoe UI', system-ui, sans-serif;">
            <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:14px; border-bottom:1px solid #f1f5f9; padding-bottom:10px;">
                <div style="display:flex; align-items:center; gap:8px;">
                    <div style="background:#eff6ff; color:#2563eb; width:34px; height:34px; border-radius:50%; display:flex; align-items:center; justify-content:center; font-size:16px;">📇</div>
                    <h4 style="margin:0; font-size:16px; color:#0f172a; font-weight:800;">Cadastrar na Agenda Pública</h4>
                </div>
                <button type="button" onclick="closeAddressBookModal()" style="background:none; border:none; color:#94a3b8; font-size:18px; cursor:pointer; font-weight:bold;">✖</button>
            </div>
            <div style="background:#f0fdf4; border-left:4px solid #22c55e; padding:8px 12px; border-radius:6px; font-size:11px; color:#15803d; margin-bottom:14px;">
                🌐 Este contato será cadastrado como <strong>Público</strong> e ficará visível para toda a empresa na Agenda.
            </div>
            <form id="formAddressBookModal" onsubmit="submitSaveAddressBook(event)">
                <div style="display:grid; grid-template-columns:1fr 1fr; gap:10px; margin-bottom:10px;">
                    <div>
                        <label style="display:block; font-size:11px; font-weight:700; color:#334155; margin-bottom:4px;">Nome *</label>
                        <input type="text" id="ab_name" required placeholder="Ex: João" style="width:100%; box-sizing:border-box; padding:7px 10px; border:1px solid #cbd5e1; border-radius:6px; font-size:12px;" />
                    </div>
                    <div>
                        <label style="display:block; font-size:11px; font-weight:700; color:#334155; margin-bottom:4px;">Sobrenome</label>
                        <input type="text" id="ab_last_name" placeholder="Ex: Silva" style="width:100%; box-sizing:border-box; padding:7px 10px; border:1px solid #cbd5e1; border-radius:6px; font-size:12px;" />
                    </div>
                </div>
                <div style="margin-bottom:10px;">
                    <label style="display:block; font-size:11px; font-weight:700; color:#334155; margin-bottom:4px;">Número / Telefone *</label>
                    <input type="text" id="ab_phone" required style="width:100%; box-sizing:border-box; padding:7px 10px; border:1px solid #cbd5e1; border-radius:6px; font-size:12px; font-weight:bold; color:#0f172a; background:#f8fafc;" />
                </div>
                <div style="display:grid; grid-template-columns:1fr 1fr; gap:10px; margin-bottom:10px;">
                    <div>
                        <label style="display:block; font-size:11px; font-weight:700; color:#334155; margin-bottom:4px;">Empresa</label>
                        <input type="text" id="ab_company" placeholder="Ex: Acme Ltda" style="width:100%; box-sizing:border-box; padding:7px 10px; border:1px solid #cbd5e1; border-radius:6px; font-size:12px;" />
                    </div>
                    <div>
                        <label style="display:block; font-size:11px; font-weight:700; color:#334155; margin-bottom:4px;">E-mail</label>
                        <input type="email" id="ab_email" placeholder="cliente@empresa.com" style="width:100%; box-sizing:border-box; padding:7px 10px; border:1px solid #cbd5e1; border-radius:6px; font-size:12px;" />
                    </div>
                </div>
                <div style="margin-bottom:16px;">
                    <label style="display:block; font-size:11px; font-weight:700; color:#334155; margin-bottom:4px;">Observações</label>
                    <textarea id="ab_notes" rows="2" placeholder="Informações adicionais..." style="width:100%; box-sizing:border-box; padding:7px 10px; border:1px solid #cbd5e1; border-radius:6px; font-size:12px; resize:vertical;"></textarea>
                </div>
                <div style="display:flex; justify-content:flex-end; gap:8px;">
                    <button type="button" onclick="closeAddressBookModal()" style="background:#f1f5f9; color:#475569; border:1px solid #cbd5e1; padding:7px 16px; border-radius:6px; font-weight:bold; cursor:pointer; font-size:12px;">Cancelar</button>
                    <button type="submit" id="btnSaveAb" style="background:#2563eb; color:#ffffff; border:none; padding:7px 20px; border-radius:6px; font-weight:bold; cursor:pointer; font-size:12px; box-shadow:0 2px 6px rgba(37,99,235,0.3);">💾 Salvar Contato</button>
                </div>
            </form>
        </div>
    </div>

            <script>
            var currentAudio = null;

            function getOrInitAudio() {
                if (!currentAudio) {
                    currentAudio = document.getElementById('stkAudioElement');
                }
                if (!currentAudio) {
                    currentAudio = document.createElement('audio');
                    currentAudio.id = 'stkAudioElement';
                    currentAudio.preload = 'auto';
                    document.body.appendChild(currentAudio);
                }
                return currentAudio;
            }

            function ensureAudioBarInBody() {
                var bar = document.getElementById('stickyBottomAudioPlayer');
                if (bar && bar.parentElement !== document.body) {
                    document.body.appendChild(bar);
                }
            }

            function playCdrAudio(audioUrl, caller, target, downloadUrl) {
                ensureAudioBarInBody();
                var bar = document.getElementById('stickyBottomAudioPlayer');
                var aud = getOrInitAudio();

                var callerEl = document.getElementById('stkCaller');
                if (callerEl) callerEl.textContent = '📞 ' + (caller || '-');
                var targetEl = document.getElementById('stkTarget');
                if (targetEl) targetEl.textContent = '🎯 ' + (target || '-');
                var downEl = document.getElementById('stkDownloadBtn');
                if (downEl) downEl.href = downloadUrl || audioUrl;
                
                aud.src = audioUrl;
                if (bar) bar.classList.add('active');
                
                var p = aud.play();
                if (p !== undefined) {
                    p.then(function() {
                        updatePlayPauseButton(true);
                    }).catch(function(err) {
                        console.log("Audio play error:", err);
                        updatePlayPauseButton(false);
                    });
                }
            }

            function updatePlayPauseButton(isPlaying) {
                var btn = document.getElementById('stkPlayPauseBtn');
                if (btn) {
                    if (isPlaying) {
                        btn.innerHTML = '⏸ Pausar';
                        btn.style.background = '#e11d48';
                    } else {
                        btn.innerHTML = '▶ Continuar';
                        btn.style.background = '#7c3aed';
                    }
                }
            }

            function stkTogglePlay() {
                var aud = getOrInitAudio();
                if (aud.paused) {
                    aud.play();
                    updatePlayPauseButton(true);
                } else {
                    aud.pause();
                    updatePlayPauseButton(false);
                }
            }

            function stkSeekRelative(seconds) {
                var aud = getOrInitAudio();
                if (aud) {
                    aud.currentTime = Math.max(0, Math.min(aud.duration || 0, aud.currentTime + seconds));
                }
            }

            function stkSeekTo(val) {
                var aud = getOrInitAudio();
                if (aud && aud.duration) {
                    aud.currentTime = (val / 100) * aud.duration;
                }
            }

            function stkSetSpeed(speed, btn) {
                var aud = getOrInitAudio();
                if (aud) {
                    aud.playbackRate = speed;
                    var pills = document.querySelectorAll('.sticky-speed-selector .speed-btn');
                    pills.forEach(function(p) { p.classList.remove('active'); });
                    if (btn) btn.classList.add('active');
                }
            }

            function closeStickyAudioPlayer() {
                var aud = getOrInitAudio();
                if (aud) {
                    aud.pause();
                    aud.currentTime = 0;
                }
                var bar = document.getElementById('stickyBottomAudioPlayer');
                if (bar) bar.classList.remove('active');
            }

            function formatSecondsToMmSs(secs) {
                var m = Math.floor(secs / 60);
                var s = Math.floor(secs % 60);
                return (m < 10 ? '0' + m : m) + ':' + (s < 10 ? '0' + s : s);
            }

            function openCelModal(uniqueId) {
                var modal = document.getElementById('celModalCdr');
                var iframe = document.getElementById('celIframeElement');
                iframe.src = '?menu=<?php echo htmlspecialchars($module_name); ?>&rawmode=yes&uniqueid=' + uniqueId;
                modal.style.display = 'flex';
            }

            function openAddressBookModal(phoneNumber, name, lastName, company, email, notes) {
                var modal = document.getElementById('addressBookModal');
                if (modal && modal.parentElement !== document.body) {
                    document.body.appendChild(modal);
                }
                document.getElementById('ab_phone').value = phoneNumber || '';
                document.getElementById('ab_name').value = name || '';
                document.getElementById('ab_last_name').value = lastName || '';
                document.getElementById('ab_company').value = company || '';
                document.getElementById('ab_email').value = email || '';
                document.getElementById('ab_notes').value = notes || '';
                modal.style.display = 'flex';
                document.getElementById('ab_name').focus();
            }

            function closeAddressBookModal() {
                document.getElementById('addressBookModal').style.display = 'none';
            }

            function submitSaveAddressBook(e) {
                e.preventDefault();
                var btn = document.getElementById('btnSaveAb');
                btn.disabled = true;
                btn.textContent = 'Salvando...';

                var data = new FormData();
                data.append('action', 'save_address_book');
                data.append('phone', document.getElementById('ab_phone').value);
                data.append('name', document.getElementById('ab_name').value);
                data.append('last_name', document.getElementById('ab_last_name').value);
                data.append('company', document.getElementById('ab_company').value);
                data.append('email', document.getElementById('ab_email').value);
                data.append('notes', document.getElementById('ab_notes').value);

                fetch('index.php?menu=<?php echo htmlspecialchars($module_name); ?>&rawmode=yes', {
                    method: 'POST',
                    body: data
                })
                .then(function(r) { return r.json(); })
                .then(function(res) {
                    btn.disabled = false;
                    btn.textContent = '💾 Salvar Contato';
                    if (res.status === 'success') {
                        alert('✅ ' + res.message);
                        closeAddressBookModal();
                        window.location.reload();
                    } else if (res.status === 'exists') {
                        alert('⚠️ ' + res.message);
                    } else {
                        alert('❌ ' + (res.message || 'Erro ao salvar contato.'));
                    }
                })
                .catch(function(err) {
                    btn.disabled = false;
                    btn.textContent = '💾 Salvar Contato';
                    alert('❌ Erro na comunicação com o servidor: ' + err);
                });
            }

            document.addEventListener("DOMContentLoaded", function() {
                ensureAudioBarInBody();
                var aud = getOrInitAudio();
                if (aud) {
                    aud.addEventListener('timeupdate', function() {
                        var cur = aud.currentTime || 0;
                        var dur = aud.duration || 0;
                        var bar = document.getElementById('stkProgressBar');
                        if (bar && dur > 0) {
                            bar.value = (cur / dur) * 100;
                        }
                        var timeEl = document.getElementById('stkTime');
                        if (timeEl) timeEl.textContent = formatSecondsToMmSs(cur) + ' / ' + formatSecondsToMmSs(dur);
                    });

                    aud.addEventListener('ended', function() {
                        updatePlayPauseButton(false);
                    });
                }
            });
        </script>
    <?php
    return ob_get_clean();
}
?>
