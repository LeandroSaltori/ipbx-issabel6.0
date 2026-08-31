<?php
/* vim: set expandtab tabstop=4 softtabstop=4 shiftwidth=4:
  Codificación: UTF-8
  +----------------------------------------------------------------------+
  | Issabel version {ISSBEL_VERSION}                                     |
  | http://www.issabel.org                                               |
  +----------------------------------------------------------------------+
  | Copyright (c) 2017 Issabel Foundation                                |
  | Copyright (c) 2006 Palosanto Solutions S. A.                         |
  +----------------------------------------------------------------------+
  | The Initial Developer of the Original Code is PaloSanto Solutions    |
  +----------------------------------------------------------------------+
  $Id: index.php,v 18.0 2026-08-18 Prisma Telecom $ */

require_once "modules/agent_console/libs/issabel2.lib.php";
include_once "libs/paloSantoDB.class.php";
include_once "libs/paloSantoConfig.class.php";
require_once "libs/misc.lib.php";

function formatDateBr($d) {
    if (empty($d) || $d == '-') return '-';
    $d = trim($d);
    if (preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $d, $m)) {
        return "{$m[3]}/{$m[2]}/{$m[1]}";
    }
    $ts = strtotime($d);
    return $ts ? date('d/m/Y', $ts) : $d;
}

function formatPhoneBr($num) {
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
    include_once "modules/$module_name/configs/default.conf.php";
    include_once "modules/$module_name/libs/paloSantoPesquisa.class.php";

    load_language_module($module_name);
    global $arrConf;

    $pPesquisaObj = new paloSantoPesquisa();

    if (isset($_REQUEST['action']) && $_REQUEST['action'] == 'save_address_book') {
        handleSaveAddressBook($arrConf);
        exit;
    }

    if (isset($_GET['action']) && ($_GET['action'] == 'stream_audio' || $_GET['action'] == 'download_audio')) {
        handleAudioPlayback();
        exit;
    }

    if (isset($_GET['action']) && $_GET['action'] == 'export_excel') {
        handleExportExcel($pPesquisaObj);
        exit;
    }

    if (isset($_GET['action']) && $_GET['action'] == 'export_pdf') {
        handleExportPdf($pPesquisaObj);
        exit;
    }

    return renderFullExecutiveDashboard($pPesquisaObj, $module_name);
}

function handleAudioPlayback()
{
    while (ob_get_level()) {
        ob_end_clean();
    }

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

function handleExportExcel($pPesquisa)
{
    while (ob_get_level()) {
        ob_end_clean();
    }

    $date_start = isset($_REQUEST['date_start']) ? trim($_REQUEST['date_start']) : '';
    $date_end   = isset($_REQUEST['date_end']) ? trim($_REQUEST['date_end']) : '';
    $operador   = isset($_REQUEST['operador']) ? trim($_REQUEST['operador']) : '';
    $avaliacao  = isset($_REQUEST['avaliacao']) ? trim($_REQUEST['avaliacao']) : '';
    $solucao    = isset($_REQUEST['solucao']) ? trim($_REQUEST['solucao']) : '';

    $total = $pPesquisa->getNumPesquisa('', '', $date_start, $date_end, $operador, $avaliacao, $solucao);
    $arrResult = $pPesquisa->getPesquisa($total > 0 ? $total : 5000, 0, '', '', $date_start, $date_end, $operador, $avaliacao, $solucao);
    $extNamesMap   = $pPesquisa->getExtensionNamesMap();
    $queueNamesMap = $pPesquisa->getQueueNamesMap();

    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename=Pesquisa_Satisfacao_' . date('Ymd_His') . '.csv');

    $output = fopen('php://output', 'w');
    fprintf($output, chr(0xEF).chr(0xBB).chr(0xBF));
    fputcsv($output, array('Operador / Ramal', 'Nome do Atendente', 'Fila', 'Data', 'Hora', 'Duracao', 'Telefone', 'Avaliacao', 'Problema Resolvido'), ';');

    if (is_array($arrResult)) {
        foreach ($arrResult as $row) {
            $val_operador  = !empty($row['operador']) ? $row['operador'] : (!empty($row['ramal']) ? $row['ramal'] : '-');
            $val_nome      = isset($extNamesMap[$val_operador]) ? $extNamesMap[$val_operador] : '-';
            $val_data      = formatDateBr(!empty($row['data']) ? $row['data'] : '-');
            $val_hora      = !empty($row['hora']) ? $row['hora'] : '-';
            $raw_tel       = !empty($row['telefone']) ? $row['telefone'] : (!empty($row['numero']) ? $row['numero'] : '-');
            $val_telefone  = formatPhoneBr($raw_tel);
            $val_avaliacao = !empty($row['avaliacao']) ? $row['avaliacao'] : 'NÃO AVALIOU';
            $val_solucao   = !empty($row['solucao']) ? $row['solucao'] : 'NÃO AVALIOU';

            $cdrInfo     = $pPesquisa->findCdrInfoForCall($raw_tel, !empty($row['data']) ? $row['data'] : '', $val_hora, $val_operador);
            $val_duracao = !empty($cdrInfo['duration_formatted']) ? $cdrInfo['duration_formatted'] : '-';

            $rawFila = !empty($row['fila']) ? trim($row['fila']) : (!empty($cdrInfo['fila']) ? trim($cdrInfo['fila']) : '');
            if (!empty($rawFila) && isset($queueNamesMap[$rawFila])) {
                $val_fila = "$rawFila - " . $queueNamesMap[$rawFila];
            } elseif (!empty($rawFila) && is_numeric($rawFila) && strlen($rawFila) >= 3 && $rawFila != '7940') {
                $val_fila = "Fila $rawFila";
            } else {
                $val_fila = '-';
            }

            fputcsv($output, array($val_operador, $val_nome, $val_fila, $val_data, $val_hora, $val_duracao, $val_telefone, $val_avaliacao, $val_solucao), ';');
        }
    }
    fclose($output);
    exit;
}

function handleExportPdf($pPesquisa)
{
    while (ob_get_level()) {
        ob_end_clean();
    }

    $date_start = isset($_REQUEST['date_start']) ? trim($_REQUEST['date_start']) : '';
    $date_end   = isset($_REQUEST['date_end']) ? trim($_REQUEST['date_end']) : '';
    $operador   = isset($_REQUEST['operador']) ? trim($_REQUEST['operador']) : '';
    $avaliacao  = isset($_REQUEST['avaliacao']) ? trim($_REQUEST['avaliacao']) : '';
    $solucao    = isset($_REQUEST['solucao']) ? trim($_REQUEST['solucao']) : '';

    $total = $pPesquisa->getNumPesquisa('', '', $date_start, $date_end, $operador, $avaliacao, $solucao);
    $arrResult = $pPesquisa->getPesquisa($total > 0 ? $total : 5000, 0, '', '', $date_start, $date_end, $operador, $avaliacao, $solucao);
    $extNamesMap   = $pPesquisa->getExtensionNamesMap();
    $queueNamesMap = $pPesquisa->getQueueNamesMap();

    ?>
    <!DOCTYPE html>
    <html lang="pt-BR">
    <head>
        <meta charset="UTF-8">
        <title>Relatório de Pesquisa de Satisfação - IPbx Prisma</title>
        <style>
            body { font-family: 'Segoe UI', sans-serif; font-size:12px; color:#1e293b; padding:20px; }
            h2 { margin:0 0 5px 0; color:#0f172a; }
            p { margin:0 0 15px 0; color:#64748b; font-size:11px; }
            table { width:100%; border-collapse:collapse; margin-top:15px; }
            th { background:#334155; color:#ffffff; padding:8px; text-align:left; font-size:11px; text-transform:uppercase; }
            td { padding:8px; border-bottom:1px solid #e2e8f0; }
            tr:nth-child(even) { background:#f8fafc; }
            @media print { .no-print { display:none; } }
        #prisma_report_tooltip, .prisma_report_tooltip { display: none !important; opacity: 0 !important; visibility: hidden !important; pointer-events: none !important; }

        /* Modal Popup de Reprodução de Áudio */
        .audio-modal-overlay {
            position: fixed !important;
            top: 0 !important;
            left: 0 !important;
            width: 100vw !important;
            height: 100vh !important;
            background: rgba(15, 23, 42, 0.75) !important;
            backdrop-filter: blur(6px) !important;
            -webkit-backdrop-filter: blur(6px) !important;
            z-index: 2147483647 !important;
            display: none;
            align-items: center !important;
            justify-content: center !important;
        }
        .audio-modal-overlay.active {
            display: flex !important;
        }
        @keyframes audioFadeIn { from { opacity: 0; } to { opacity: 1; } }
        .audio-modal-card {
            background: linear-gradient(145deg, #1e1b4b 0%, #0f172a 100%) !important;
            border: 1px solid rgba(139, 92, 246, 0.4) !important;
            border-radius: 16px !important;
            box-shadow: 0 25px 50px -12px rgba(0, 0, 0, 0.8), 0 0 30px rgba(124, 58, 237, 0.3) !important;
            width: 92% !important;
            max-width: 520px !important;
            overflow: hidden !important;
            color: #ffffff !important;
            font-family: 'Segoe UI', -apple-system, BlinkMacSystemFont, sans-serif !important;
            animation: audioPopIn 0.25s cubic-bezier(0.16, 1, 0.3, 1) !important;
        }
        @keyframes audioPopIn { from { transform: scale(0.92) translateY(10px); opacity: 0; } to { transform: scale(1) translateY(0); opacity: 1; } }
        .audio-modal-header { padding: 16px 20px !important; background: rgba(255, 255, 255, 0.05) !important; border-bottom: 1px solid rgba(255, 255, 255, 0.1) !important; display: flex !important; justify-content: space-between !important; align-items: center !important; }
        .audio-modal-title-box { display: flex !important; align-items: center !important; gap: 12px !important; }
        .audio-modal-icon { font-size: 24px !important; background: rgba(124, 58, 237, 0.3) !important; border: 1px solid rgba(139, 92, 246, 0.4) !important; padding: 8px !important; border-radius: 10px !important; display: flex !important; align-items: center !important; justify-content: center !important; }
        .audio-modal-title { margin: 0 !important; font-size: 16px !important; font-weight: 800 !important; color: #f8fafc !important; letter-spacing: -0.2px !important; }
        .audio-modal-meta { margin-top: 3px !important; font-size: 12px !important; color: #cbd5e1 !important; font-weight: 600 !important; }
        .audio-modal-close-btn { background: rgba(255, 255, 255, 0.1) !important; color: #e2e8f0 !important; border: 1px solid rgba(255, 255, 255, 0.15) !important; font-size: 15px !important; font-weight: bold !important; cursor: pointer !important; width: 32px !important; height: 32px !important; border-radius: 8px !important; display: flex !important; align-items: center !important; justify-content: center !important; transition: all 0.15s ease !important; }
        .audio-modal-close-btn:hover { background: #ef4444 !important; border-color: #dc2626 !important; color: #ffffff !important; transform: scale(1.05) !important; }
        .audio-modal-body { padding: 20px !important; }
        .audio-progress-container { margin-bottom: 18px !important; }
        .audio-time-row { display: flex !important; justify-content: space-between !important; font-size: 12px !important; color: #94a3b8 !important; font-weight: 700 !important; margin-bottom: 6px !important; }
        .audio-range-slider { width: 100% !important; height: 6px !important; border-radius: 3px !important; background: rgba(255, 255, 255, 0.2) !important; outline: none !important; cursor: pointer !important; accent-color: #8b5cf6 !important; }
        .audio-controls-row { display: flex !important; justify-content: center !important; align-items: center !important; gap: 14px !important; margin-bottom: 20px !important; }
        .btn-audio-ctrl { background: rgba(255, 255, 255, 0.1) !important; color: #ffffff !important; border: 1px solid rgba(255, 255, 255, 0.15) !important; padding: 8px 14px !important; border-radius: 8px !important; font-size: 13px !important; font-weight: 700 !important; cursor: pointer !important; transition: all 0.15s ease !important; }
        .btn-audio-ctrl:hover { background: rgba(255, 255, 255, 0.2) !important; transform: translateY(-1px) !important; }
        .btn-play-main { background: linear-gradient(135deg, #7c3aed 0%, #6366f1 100%) !important; border: none !important; padding: 10px 24px !important; font-size: 14px !important; box-shadow: 0 4px 12px rgba(124, 58, 237, 0.4) !important; }
        .btn-play-main:hover { background: linear-gradient(135deg, #6d28d9 0%, #4f46e5 100%) !important; box-shadow: 0 6px 16px rgba(124, 58, 237, 0.5) !important; }
        .audio-footer-row { display: flex !important; justify-content: space-between !important; align-items: center !important; padding-top: 14px !important; border-top: 1px solid rgba(255, 255, 255, 0.1) !important; flex-wrap: wrap !important; gap: 10px !important; }
        .audio-speed-group { display: flex !important; align-items: center !important; gap: 5px !important; }
        .speed-label { font-size: 11px !important; color: #94a3b8 !important; font-weight: 600 !important; margin-right: 2px !important; }
        .speed-btn { background: rgba(255, 255, 255, 0.08) !important; border: 1px solid rgba(255, 255, 255, 0.12) !important; color: #94a3b8 !important; border-radius: 6px !important; font-size: 11px !important; font-weight: 700 !important; padding: 4px 8px !important; cursor: pointer !important; transition: all 0.15s !important; }
        .speed-btn.active, .speed-btn:hover { background: #8b5cf6 !important; border-color: #8b5cf6 !important; color: #ffffff !important; }
        .btn-audio-download { background: rgba(16, 185, 129, 0.2) !important; border: 1px solid rgba(16, 185, 129, 0.4) !important; color: #34d399 !important; padding: 6px 12px !important; border-radius: 6px !important; font-size: 11px !important; font-weight: 700 !important; text-decoration: none !important; display: inline-flex !important; align-items: center !important; gap: 4px !important; transition: all 0.15s !important; }
        .btn-audio-download:hover { background: #10b981 !important; color: #ffffff !important; }

    </style>
    </head>
    <body onload="window.print();">
        <div class="no-print" style="margin-bottom:15px;">
            <button onclick="window.print();" style="background:#0284c7; color:#fff; border:none; padding:8px 16px; border-radius:6px; font-weight:bold; cursor:pointer;">🖨️ Imprimir / Salvar PDF</button>
        </div>
        <h2>📊 Relatório de Pesquisa de Satisfação - IPbx Prisma</h2>
        <p>Gerado em <?php echo date('d/m/Y H:i:s'); ?> | Total: <?php echo count($arrResult); ?> avaliações</p>
        
        <table>
            <thead>
                <tr>
                    <th>Operador / Ramal</th>
                    <th>Fila</th>
                    <th>Data</th>
                    <th>Hora</th>
                    <th>Duração</th>
                    <th>Telefone</th>
                    <th>Avaliação</th>
                    <th>Problema Resolvido?</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($arrResult as $row): ?>
                <?php
                $val_operador  = !empty($row['operador']) ? $row['operador'] : (!empty($row['ramal']) ? $row['ramal'] : '-');
                $val_nome      = isset($extNamesMap[$val_operador]) ? $extNamesMap[$val_operador] : '';
                $val_disp_op   = !empty($val_nome) ? "$val_operador - $val_nome" : $val_operador;
                $val_data      = formatDateBr(!empty($row['data']) ? $row['data'] : '-');
                $val_hora      = !empty($row['hora']) ? $row['hora'] : '-';
                $raw_tel       = !empty($row['telefone']) ? $row['telefone'] : (!empty($row['numero']) ? $row['numero'] : '-');
                $val_telefone  = formatPhoneBr($raw_tel);
                $val_avaliacao = !empty($row['avaliacao']) ? $row['avaliacao'] : 'NÃO AVALIOU';
                $val_solucao   = !empty($row['solucao']) ? $row['solucao'] : 'NÃO AVALIOU';

                $cdrInfo       = $pPesquisa->findCdrInfoForCall($raw_tel, !empty($row['data']) ? $row['data'] : '', $val_hora, $val_operador);
                $val_duracao   = !empty($cdrInfo['duration_formatted']) ? $cdrInfo['duration_formatted'] : '-';

                $rawFila = !empty($row['fila']) ? trim($row['fila']) : (!empty($cdrInfo['fila']) ? trim($cdrInfo['fila']) : '');
                if (!empty($rawFila) && isset($queueNamesMap[$rawFila])) {
                    $val_fila = "$rawFila - " . $queueNamesMap[$rawFila];
                } elseif (!empty($rawFila) && is_numeric($rawFila) && strlen($rawFila) >= 3 && $rawFila != '7940') {
                    $val_fila = "Fila $rawFila";
                } else {
                    $val_fila = '-';
                }
                ?>
                <tr>
                    <td><strong>👤 <?php echo htmlspecialchars($val_disp_op); ?></strong></td>
                    <td>🏢 <?php echo htmlspecialchars($val_fila); ?></td>
                    <td>📅 <?php echo htmlspecialchars($val_data); ?></td>
                    <td>🕒 <?php echo htmlspecialchars($val_hora); ?></td>
                    <td>⏱️ <?php echo htmlspecialchars($val_duracao); ?></td>
                    <td>📞 <?php echo htmlspecialchars($val_telefone); ?></td>
                    <td><?php echo htmlspecialchars($val_avaliacao); ?></td>
                    <td><?php echo htmlspecialchars($val_solucao); ?></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </body>
    </html>
    <?php
    exit;
}

function renderFullExecutiveDashboard($pPesquisa, $module_name)
{
    $date_start = isset($_REQUEST['date_start']) ? trim($_REQUEST['date_start']) : '';
    $date_end   = isset($_REQUEST['date_end']) ? trim($_REQUEST['date_end']) : '';
    $operador   = isset($_REQUEST['operador']) ? trim($_REQUEST['operador']) : '';
    $avaliacao  = isset($_REQUEST['avaliacao']) ? trim($_REQUEST['avaliacao']) : '';
    $solucao    = isset($_REQUEST['solucao']) ? trim($_REQUEST['solucao']) : '';

    $page  = isset($_REQUEST['page']) ? max(1, (int)$_REQUEST['page']) : 1;
    $limit = 20;
    $offset = ($page - 1) * $limit;

    $stats = $pPesquisa->getPesquisaStats($date_start, $date_end, $operador);

    $operadoresList = $pPesquisa->getOperadoresList();
    $extNamesMap    = $pPesquisa->getExtensionNamesMap();
    $queueNamesMap  = $pPesquisa->getQueueNamesMap();

    $total = $pPesquisa->getNumPesquisa('', '', $date_start, $date_end, $operador, $avaliacao, $solucao);
    $arrResult = $pPesquisa->getPesquisa($limit, $offset, '', '', $date_start, $date_end, $operador, $avaliacao, $solucao);

    $totalPages = max(1, ceil($total / $limit));

    $exportParams = http_build_query(array(
        'menu' => $module_name,
        'rawmode' => 'yes',
        'date_start' => $date_start,
        'date_end' => $date_end,
        'operador' => $operador,
        'avaliacao' => $avaliacao,
        'solucao' => $solucao
    ));

    $totalCount  = isset($stats['total']) ? $stats['total'] : 0;
    $media       = isset($stats['media_estrelas']) ? $stats['media_estrelas'] : 0;
    $resolucao   = isset($stats['taxa_resolucao']) ? $stats['taxa_resolucao'] : 0;
    $satisfacao  = isset($stats['taxa_satisfacao']) ? $stats['taxa_satisfacao'] : 0;
    $nao_avaliou = isset($stats['nao_avaliou']) ? (int)$stats['nao_avaliou'] : 0;

    $otimo     = isset($stats['otimo']) ? (int)$stats['otimo'] : 0;
    $muito_bom = isset($stats['muito_bom']) ? (int)$stats['muito_bom'] : 0;
    $medio     = isset($stats['medio']) ? (int)$stats['medio'] : 0;
    $bom       = isset($stats['bom']) ? (int)$stats['bom'] : 0;
    $ruim      = isset($stats['ruim']) ? (int)$stats['ruim'] : 0;
    $sim       = isset($stats['resolvido_sim']) ? (int)$stats['resolvido_sim'] : 0;
    $nao       = isset($stats['resolvido_nao']) ? (int)$stats['resolvido_nao'] : 0;

    ob_start();
    ?>
    <script src="https://cdn.jsdelivr.net/npm/chart.js@3.9.1/dist/chart.min.js"></script>
    <style>
        .pesquisa-root {
            font-family: 'Segoe UI', -apple-system, BlinkMacSystemFont, sans-serif;
            color: #1e293b;
            padding: 5px;
        }

        .pesquisa-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 12px;
        }
        .pesquisa-title h2 {
            margin: 0;
            font-size: 20px;
            font-weight: 800;
            color: #0f172a;
            letter-spacing: -0.3px;
        }
        .pesquisa-title p {
            margin: 1px 0 0 0;
            font-size: 11px;
            color: #64748b;
        }
        .pesquisa-top-btns {
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
            min-width: 130px;
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
            grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
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
        .kpi-card-item.blue { border-left-color: #3b82f6; }
        .kpi-card-item.amber { border-left-color: #f59e0b; }
        .kpi-card-item.slate { border-left-color: #64748b; }

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
            grid-template-columns: 1fr 1fr;
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
        .pesquisa-table {
            width: 100%;
            border-collapse: collapse;
            text-align: left;
        }
        .pesquisa-table thead {
            background: #334155;
            color: #ffffff;
        }
        .pesquisa-table th {
            padding: 10px 14px;
            font-size: 10px;
            font-weight: 800;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }
        .pesquisa-table td {
            padding: 10px 14px;
            border-bottom: 1px solid #f1f5f9;
            font-size: 12px;
            vertical-align: middle;
        }
        .pesquisa-table tbody tr:hover {
            background: #f8fafc;
        }

        .pagination-bar {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 12px 16px;
            background: #ffffff;
            border-top: 1px solid #f1f5f9;
        }
        .pagination-info {
            font-size: 12px;
            color: #64748b;
            font-weight: 600;
        }
        .pagination-btns {
            display: flex;
            gap: 6px;
        }
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

        /* Sticky Bottom Audio Player */
        .sticky-audio-bar {
            position: fixed !important;
            bottom: -160px;
            left: 280px !important;
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
    #prisma_report_tooltip, .prisma_report_tooltip { display: none !important; opacity: 0 !important; visibility: hidden !important; pointer-events: none !important; }

        /* Modal Popup de Reprodução de Áudio */
        .audio-modal-overlay {
            position: fixed !important;
            top: 0 !important;
            left: 0 !important;
            width: 100vw !important;
            height: 100vh !important;
            background: rgba(15, 23, 42, 0.75) !important;
            backdrop-filter: blur(6px) !important;
            -webkit-backdrop-filter: blur(6px) !important;
            z-index: 2147483647 !important;
            display: none;
            align-items: center !important;
            justify-content: center !important;
        }
        .audio-modal-overlay.active {
            display: flex !important;
        }
        @keyframes audioFadeIn { from { opacity: 0; } to { opacity: 1; } }
        .audio-modal-card {
            background: linear-gradient(145deg, #1e1b4b 0%, #0f172a 100%) !important;
            border: 1px solid rgba(139, 92, 246, 0.4) !important;
            border-radius: 16px !important;
            box-shadow: 0 25px 50px -12px rgba(0, 0, 0, 0.8), 0 0 30px rgba(124, 58, 237, 0.3) !important;
            width: 92% !important;
            max-width: 520px !important;
            overflow: hidden !important;
            color: #ffffff !important;
            font-family: 'Segoe UI', -apple-system, BlinkMacSystemFont, sans-serif !important;
            animation: audioPopIn 0.25s cubic-bezier(0.16, 1, 0.3, 1) !important;
        }
        @keyframes audioPopIn { from { transform: scale(0.92) translateY(10px); opacity: 0; } to { transform: scale(1) translateY(0); opacity: 1; } }
        .audio-modal-header { padding: 16px 20px !important; background: rgba(255, 255, 255, 0.05) !important; border-bottom: 1px solid rgba(255, 255, 255, 0.1) !important; display: flex !important; justify-content: space-between !important; align-items: center !important; }
        .audio-modal-title-box { display: flex !important; align-items: center !important; gap: 12px !important; }
        .audio-modal-icon { font-size: 24px !important; background: rgba(124, 58, 237, 0.3) !important; border: 1px solid rgba(139, 92, 246, 0.4) !important; padding: 8px !important; border-radius: 10px !important; display: flex !important; align-items: center !important; justify-content: center !important; }
        .audio-modal-title { margin: 0 !important; font-size: 16px !important; font-weight: 800 !important; color: #f8fafc !important; letter-spacing: -0.2px !important; }
        .audio-modal-meta { margin-top: 3px !important; font-size: 12px !important; color: #cbd5e1 !important; font-weight: 600 !important; }
        .audio-modal-close-btn { background: rgba(255, 255, 255, 0.1) !important; color: #e2e8f0 !important; border: 1px solid rgba(255, 255, 255, 0.15) !important; font-size: 15px !important; font-weight: bold !important; cursor: pointer !important; width: 32px !important; height: 32px !important; border-radius: 8px !important; display: flex !important; align-items: center !important; justify-content: center !important; transition: all 0.15s ease !important; }
        .audio-modal-close-btn:hover { background: #ef4444 !important; border-color: #dc2626 !important; color: #ffffff !important; transform: scale(1.05) !important; }
        .audio-modal-body { padding: 20px !important; }
        .audio-progress-container { margin-bottom: 18px !important; }
        .audio-time-row { display: flex !important; justify-content: space-between !important; font-size: 12px !important; color: #94a3b8 !important; font-weight: 700 !important; margin-bottom: 6px !important; }
        .audio-range-slider { width: 100% !important; height: 6px !important; border-radius: 3px !important; background: rgba(255, 255, 255, 0.2) !important; outline: none !important; cursor: pointer !important; accent-color: #8b5cf6 !important; }
        .audio-controls-row { display: flex !important; justify-content: center !important; align-items: center !important; gap: 14px !important; margin-bottom: 20px !important; }
        .btn-audio-ctrl { background: rgba(255, 255, 255, 0.1) !important; color: #ffffff !important; border: 1px solid rgba(255, 255, 255, 0.15) !important; padding: 8px 14px !important; border-radius: 8px !important; font-size: 13px !important; font-weight: 700 !important; cursor: pointer !important; transition: all 0.15s ease !important; }
        .btn-audio-ctrl:hover { background: rgba(255, 255, 255, 0.2) !important; transform: translateY(-1px) !important; }
        .btn-play-main { background: linear-gradient(135deg, #7c3aed 0%, #6366f1 100%) !important; border: none !important; padding: 10px 24px !important; font-size: 14px !important; box-shadow: 0 4px 12px rgba(124, 58, 237, 0.4) !important; }
        .btn-play-main:hover { background: linear-gradient(135deg, #6d28d9 0%, #4f46e5 100%) !important; box-shadow: 0 6px 16px rgba(124, 58, 237, 0.5) !important; }
        .audio-footer-row { display: flex !important; justify-content: space-between !important; align-items: center !important; padding-top: 14px !important; border-top: 1px solid rgba(255, 255, 255, 0.1) !important; flex-wrap: wrap !important; gap: 10px !important; }
        .audio-speed-group { display: flex !important; align-items: center !important; gap: 5px !important; }
        .speed-label { font-size: 11px !important; color: #94a3b8 !important; font-weight: 600 !important; margin-right: 2px !important; }
        .speed-btn { background: rgba(255, 255, 255, 0.08) !important; border: 1px solid rgba(255, 255, 255, 0.12) !important; color: #94a3b8 !important; border-radius: 6px !important; font-size: 11px !important; font-weight: 700 !important; padding: 4px 8px !important; cursor: pointer !important; transition: all 0.15s !important; }
        .speed-btn.active, .speed-btn:hover { background: #8b5cf6 !important; border-color: #8b5cf6 !important; color: #ffffff !important; }
        .btn-audio-download { background: rgba(16, 185, 129, 0.2) !important; border: 1px solid rgba(16, 185, 129, 0.4) !important; color: #34d399 !important; padding: 6px 12px !important; border-radius: 6px !important; font-size: 11px !important; font-weight: 700 !important; text-decoration: none !important; display: inline-flex !important; align-items: center !important; gap: 4px !important; transition: all 0.15s !important; }
        .btn-audio-download:hover { background: #10b981 !important; color: #ffffff !important; }

    </style>

    <div class="pesquisa-root">
        <!-- Header Principal -->
        <div class="pesquisa-header">
            <div class="pesquisa-title">
                <h2>Relatório de Pesquisa de Satisfação - IPbx Prisma</h2>
                <p>Módulo Executivo Oficial de Pesquisa pós-atendimento (Disque / Transfira para <strong>9000</strong> ou <strong>8996</strong>)</p>
            </div>
            <div class="pesquisa-top-btns">
                <a href="modules/pesquisa/help/index.html" target="_blank" class="btn-top btn-top-manual">📖 Manual</a>
                <button onclick="window.open('?menu=<?php echo htmlspecialchars($module_name); ?>&rawmode=yes', '_blank')" class="btn-top btn-top-expand">↗ Expandir Aba</button>
            </div>
        </div>

        <!-- Card de Filtros Compacto de 1 Linha -->
        <div class="filter-card-box">
            <form method="GET" action="index.php">
                <input type="hidden" name="menu" value="<?php echo htmlspecialchars($module_name); ?>" />
                <div class="filter-inline-row">
                    <div class="filter-field-group" title="📅 Data Inicial do Período&#10;Selecione a data de início para buscar as avaliações registradas.">
                        <label>📅 Data Inicial</label>
                        <input type="date" name="date_start" value="<?php echo htmlspecialchars($date_start); ?>" class="filter-input" />
                    </div>
                    <div class="filter-field-group" title="📅 Data Final do Período&#10;Selecione a data limite para encerramento do filtro.">
                        <label>📅 Data Final</label>
                        <input type="date" name="date_end" value="<?php echo htmlspecialchars($date_end); ?>" class="filter-input" />
                    </div>
                    <div class="filter-field-group" title="👤 Filtro de Operador ou Ramal&#10;Filtre o resultado pelo ramal ou nome do atendente que realizou a ligação.">
                        <label>👤 Operador / Ramal</label>
                        <select name="operador" class="filter-input" onchange="this.form.submit()">
                            <option value="">-- Todos os Operadores --</option>
                            <?php if (is_array($operadoresList)): ?>
                                <?php foreach ($operadoresList as $op): ?>
                                    <?php
                                    $opName = isset($extNamesMap[$op]) ? $extNamesMap[$op] : '';
                                    $opLabel = !empty($opName) ? "$op - $opName" : "Ramal $op";
                                    ?>
                                    <option value="<?php echo htmlspecialchars($op); ?>" <?php if ($operador == $op) echo 'selected'; ?>>👤 <?php echo htmlspecialchars($opLabel); ?></option>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </select>
                    </div>
                    <div class="filter-field-group" title="⭐ Filtro de Avaliação&#10;Filtre por notas de 1 a 5 estrelas ou chamadas onde o cliente não avaliou.">
                        <label>⭐ Avaliação</label>
                        <select name="avaliacao" class="filter-input" onchange="this.form.submit()">
                            <option value="">-- Todas as Notas --</option>
                            <option value="EXCELENTE" <?php if ($avaliacao == 'EXCELENTE') echo 'selected'; ?>>⭐⭐⭐⭐⭐ EXCELENTE</option>
                            <option value="OTIMO" <?php if ($avaliacao == 'OTIMO') echo 'selected'; ?>>⭐⭐⭐⭐⭐ ÓTIMO</option>
                            <option value="MUITO BOM" <?php if ($avaliacao == 'MUITO BOM') echo 'selected'; ?>>⭐⭐⭐⭐ MUITO BOM</option>
                            <option value="BOM" <?php if ($avaliacao == 'BOM') echo 'selected'; ?>>⭐⭐⭐ BOM</option>
                            <option value="MEDIO" <?php if ($avaliacao == 'MEDIO') echo 'selected'; ?>>⭐⭐⭐ MÉDIO / REGULAR</option>
                            <option value="RUIM" <?php if ($avaliacao == 'RUIM') echo 'selected'; ?>>⭐ RUIM / PÉSSIMO</option>
                            <option value="NAO AVALIOU" <?php if ($avaliacao == 'NAO AVALIOU') echo 'selected'; ?>>📵 NÃO AVALIOU / DESISTIU</option>
                        </select>
                    </div>
                    <div class="filter-field-group" title="🎯 Resolução de Problemas&#10;Filtre por chamadas onde o cliente respondeu SIM (Resolvido) ou NÃO (Não Resolvido).">
                        <label>🎯 Resolução</label>
                        <select name="solucao" class="filter-input" onchange="this.form.submit()">
                            <option value="">-- Todas --</option>
                            <option value="SIM" <?php if ($solucao == 'SIM') echo 'selected'; ?>>✔ SIM (Resolvido)</option>
                            <option value="NAO" <?php if ($solucao == 'NAO') echo 'selected'; ?>>✖ NÃO (Não Resolvido)</option>
                        </select>
                    </div>
                    <div class="filter-btn-row">
                        <button type="submit" class="btn-action btn-search" title="🔍 Aplicar Filtros&#10;Atualizar relatórios e gráficos com as datas e filtros selecionados.">🔍 Filtrar</button>
                        <a href="?<?php echo $exportParams; ?>&action=export_excel" class="btn-action btn-excel" title="📊 Exportar Planilha Excel&#10;Baixar arquivo .csv contendo a lista completa de avaliações do filtro.">📊 Excel</a>
                        <a href="?<?php echo $exportParams; ?>&action=export_pdf" target="_blank" class="btn-action btn-pdf" title="📄 Imprimir ou Salvar PDF&#10;Gerar documento de relatório formatado para impressão ou salvamento em PDF.">📄 PDF</a>
                        <a href="?menu=<?php echo htmlspecialchars($module_name); ?>" class="btn-action btn-reset" title="🔄 Restaurar Padrão&#10;Limpar filtros e exibir todas as avaliações.">🔄 Ver Todos</a>
                    </div>
                </div>
            </form>
        </div>

        <!-- Grid de 5 Cards KPIs Executivos -->
        <div class="kpi-grid">
            <div class="kpi-card-item purple" title="📋 Total de Chamadas em Pesquisa&#10;Soma acumulada de todas as ligações direcionadas para a URA de Pesquisa (ramal 9000/8996).">
                <div class="kpi-card-title">📋 Total de Chamadas</div>
                <div class="kpi-card-num"><?php echo number_format($totalCount, 0, ',', '.'); ?></div>
                <div class="kpi-card-desc">Transferidas para Pesquisa</div>
            </div>
            <div class="kpi-card-item green" title="⭐ Média Geral de Satisfação&#10;Média aritmética calculada entre todas as notas atribuídas pelos clientes (de 1.0 a 5.0 estrelas).">
                <div class="kpi-card-title">⭐ Média de Satisfação</div>
                <div class="kpi-card-num"><?php echo $media; ?> <span style="font-size:14px; color:#f59e0b;">/ 5.0</span></div>
                <div class="kpi-card-desc">Índice dos Avaliados</div>
            </div>
            <div class="kpi-card-item blue" title="🎯 Taxa de Resolução de Solução&#10;Percentual de ligações onde o cliente confirmou que seu problema foi solucionado durante o atendimento.">
                <div class="kpi-card-title">🎯 Taxa de Resolução</div>
                <div class="kpi-card-num"><?php echo $resolucao; ?>%</div>
                <div class="kpi-card-desc"><?php echo $sim; ?> resolvidos de <?php echo ($sim + $nao); ?></div>
            </div>
            <div class="kpi-card-item amber" title="🏆 Índice de Satisfação Positiva&#10;Percentual acumulado de notas altas (Excelente, Ótimo e Muito Bom) sobre o total de avaliados.">
                <div class="kpi-card-title">🏆 Satisfação Positiva</div>
                <div class="kpi-card-num"><?php echo $satisfacao; ?>%</div>
                <div class="kpi-card-desc">Notas Excelente, Ótimo & Muito Bom</div>
            </div>
            <div class="kpi-card-item slate" title="📵 Clientes que Desligaram sem Avaliar&#10;Quantidade e percentual de clientes que desligaram o telefone antes de digitar a nota na URA.">
                <div class="kpi-card-title">📵 Desligou sem Avaliar</div>
                <div class="kpi-card-num"><?php echo number_format($nao_avaliou, 0, ',', '.'); ?></div>
                <div class="kpi-card-desc"><?php echo $totalCount > 0 ? round(($nao_avaliou / $totalCount) * 100, 1) : 0; ?>% das chamadas</div>
            </div>
        </div>

        <!-- Grid de Gráficos -->
        <div class="charts-grid">
            <div class="chart-card-box" title="📊 Distribuição das Notas&#10;Gráfico comparativo demonstrando a proporção de avaliações de 1 a 5 estrelas e não avaliadas.">
                <h4>📊 Distribuição das Notas</h4>
                <div class="chart-canvas-wrapper">
                    <canvas id="chartNotasCustom"></canvas>
                </div>
            </div>
            <div class="chart-card-box" title="🎯 Resolução de Problemas&#10;Gráfico de rosca exibindo a proporção entre chamadas resolvidas (Sim) vs não resolvidas (Não).">
                <h4>🎯 Resolução de Problemas</h4>
                <div class="chart-canvas-wrapper">
                    <canvas id="chartSolucaoCustom"></canvas>
                </div>
            </div>
        </div>

        <!-- Tabela Customizada Moderna -->
        <div class="table-card-box">
            <table class="pesquisa-table">
                <thead>
                    <tr>
                        <th title="👤 Operador ou Ramal&#10;Identificação do agente e ramal que atendeu a ligação inicial antes da transferência.">Operador / Ramal</th>
                        <th title="🏢 Fila de Atendimento&#10;Fila telefônica por onde a chamada entrou na central PBX. Passe o mouse sobre o selo para ver o nome da fila.">Fila</th>
                        <th title="📅 Data do Atendimento&#10;Data em que a ligação foi realizada e avaliada.">Data</th>
                        <th title="🕒 Hora da Chamada&#10;Horário em que o cliente entrou na URA de pesquisa.">Hora</th>
                        <th title="⏱️ Duração Total&#10;Tempo total em minutos e segundos de conversa com o operador.">Duração</th>
                        <th title="📞 Telefone do Cliente&#10;Número da Bina de quem ligou (identificador telefônico do cliente).">Telefone</th>
                        <th title="⭐ Avaliação do Atendimento&#10;Nota digitada pelo cliente (1 a 5 estrelas) ou indicação de NÃO AVALIOU se o cliente desligou.">Avaliação do Atendimento</th>
                        <th title="🎯 Problema Resolvido?&#10;Confirmação digitada pelo cliente se a solicitação foi resolvida (Sim / Não).">Problema Resolvido?</th>
                        <th title="🎧 Gravação da Chamada&#10;Clique no botão Play para escutar o áudio completo da ligação no player flutuante.">Gravação</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (is_array($arrResult) && count($arrResult) > 0): ?>
                        <?php foreach ($arrResult as $row): ?>
                            <?php
                            $val_operador  = !empty($row['operador']) ? $row['operador'] : (!empty($row['ramal']) ? $row['ramal'] : '-');
                            $val_nome      = isset($extNamesMap[$val_operador]) ? $extNamesMap[$val_operador] : '';
                            $val_disp_op   = !empty($val_nome) ? "$val_operador - $val_nome" : $val_operador;
                            $val_data      = formatDateBr(!empty($row['data']) ? $row['data'] : '-');
                            $val_hora      = !empty($row['hora']) ? $row['hora'] : '-';
                            $raw_tel       = !empty($row['telefone']) ? $row['telefone'] : (!empty($row['numero']) ? $row['numero'] : '-');
                            $val_telefone  = formatPhoneBr($raw_tel);
                            $val_avaliacao = !empty($row['avaliacao']) ? $row['avaliacao'] : 'NÃO AVALIOU';
                            $val_solucao   = !empty($row['solucao']) ? $row['solucao'] : 'NÃO AVALIOU';

                            $avUpper = strtoupper(trim($val_avaliacao));
                            $isEvaluated = !in_array($avUpper, array('NAO AVALIOU', 'NÃO AVALIOU', 'ABANDONOU', 'SEM RESPOSTA', 'DESISTIU', '0', ''));

                            $cdrInfo       = $pPesquisa->findCdrInfoForCall($raw_tel, !empty($row['data']) ? $row['data'] : '', $val_hora, $val_operador);
                            $val_duracao   = !empty($cdrInfo['duration_formatted']) ? $cdrInfo['duration_formatted'] : '-';
                            $recFile       = !empty($cdrInfo['recordingfile']) ? $cdrInfo['recordingfile'] : '';

                            $rawFila = !empty($row['fila']) ? trim($row['fila']) : (!empty($cdrInfo['fila']) ? trim($cdrInfo['fila']) : '');
                            if (!empty($rawFila) && isset($queueNamesMap[$rawFila])) {
                                $fullName = "$rawFila - " . $queueNamesMap[$rawFila];
                                $val_fila_html = "<span title='" . htmlspecialchars($fullName, ENT_QUOTES) . "' class='queue-badge-compact'>🏢 $rawFila</span>";
                            } elseif (!empty($rawFila) && is_numeric($rawFila) && strlen($rawFila) >= 3 && $rawFila != '7940') {
                                $val_fila_html = "<span title='Fila $rawFila' class='queue-badge-compact'>🏢 $rawFila</span>";
                            } else {
                                $val_fila_html = "<span style='color:#cbd5e1;'>-</span>";
                            }
                            ?>
                            <tr>
                                <td><span style='background:#ede9fe; color:#6d28d9; padding:3px 8px; border-radius:6px; font-weight:600; font-size:11px;'>👤 <?php echo htmlspecialchars($val_disp_op); ?></span></td>
                                <td><?php echo $val_fila_html; ?></td>
                                <td><span style='color:#334155; font-size:11px;'>📅 <?php echo htmlspecialchars($val_data); ?></span></td>
                                <td><span style='color:#64748b; font-size:11px;'>🕒 <?php echo htmlspecialchars($val_hora); ?></span></td>
                                <td><span style='color:#0f172a; font-weight:600; font-size:11px;'>⏱️ <?php echo htmlspecialchars($val_duracao); ?></span></td>
                                <td>
                                    <?php echo renderCallerWithContactBadge($raw_tel, $val_telefone, $abContactsMap, $stats7dMap, $extNamesMap); ?>
                                </td>
                                <td>
                                    <?php
                                    switch ($avUpper) {
                                        case 'EXCELENTE':
                                        case 'OTIMO':
                                        case 'ÓTIMO':
                                        case '5':
                                            echo "<span style='background:#10b981; color:#ffffff; padding:3px 10px; border-radius:10px; font-weight:bold; font-size:10px; display:inline-block;'>⭐⭐⭐⭐⭐ EXCELENTE</span>";
                                            break;
                                        case 'MUITO BOM':
                                        case '4':
                                            echo "<span style='background:#3b82f6; color:#ffffff; padding:3px 10px; border-radius:10px; font-weight:bold; font-size:10px; display:inline-block;'>⭐⭐⭐⭐ MUITO BOM</span>";
                                            break;
                                        case 'MEDIO':
                                        case 'MÉDIO':
                                        case 'REGULAR':
                                        case 'BOM':
                                        case '3':
                                            echo "<span style='background:#f59e0b; color:#ffffff; padding:3px 10px; border-radius:10px; font-weight:bold; font-size:10px; display:inline-block;'>⭐⭐⭐ $avUpper</span>";
                                            break;
                                        case '2':
                                            echo "<span style='background:#f97316; color:#ffffff; padding:3px 10px; border-radius:10px; font-weight:bold; font-size:10px; display:inline-block;'>⭐⭐ BOM</span>";
                                            break;
                                        case 'RUIM':
                                        case 'PESSIMO':
                                        case 'PÉSSIMO':
                                        case '1':
                                            echo "<span style='background:#ef4444; color:#ffffff; padding:3px 10px; border-radius:10px; font-weight:bold; font-size:10px; display:inline-block;'>⭐ $avUpper</span>";
                                            break;
                                        case 'NAO AVALIOU':
                                        case 'NÃO AVALIOU':
                                        case 'ABANDONOU':
                                        case 'SEM RESPOSTA':
                                        case 'DESISTIU':
                                        case '0':
                                        case '':
                                            echo "<span style='background:#64748b; color:#ffffff; padding:3px 10px; border-radius:10px; font-weight:bold; font-size:10px; display:inline-block;'>📵 NÃO AVALIOU</span>";
                                            break;
                                        default:
                                            echo "<span style='background:#94a3b8; color:#ffffff; padding:3px 10px; border-radius:10px; font-weight:bold; font-size:10px; display:inline-block;'>$avUpper</span>";
                                            break;
                                    }
                                    ?>
                                </td>
                                <td>
                                    <?php
                                    $solUpper = strtoupper(trim($val_solucao));
                                    if ($solUpper == 'SIM' || $solUpper == '1') {
                                        echo "<span style='background:#dcfce7; color:#15803d; border:1px solid #86efac; padding:3px 8px; border-radius:6px; font-weight:bold; font-size:10px;'>✔ SIM</span>";
                                    } elseif ($solUpper == 'NAO' || $solUpper == 'NÃO' || $solUpper == '2') {
                                        echo "<span style='background:#fee2e2; color:#b91c1c; border:1px solid #fca5a5; padding:3px 8px; border-radius:6px; font-weight:bold; font-size:10px;'>✖ NÃO</span>";
                                    } elseif (in_array($solUpper, array('NAO AVALIOU', 'NÃO AVALIOU', '0', '', '-'))) {
                                        echo "<span style='background:#f1f5f9; color:#64748b; padding:3px 8px; border-radius:6px; font-weight:bold; font-size:10px;'>📵 NÃO AVALIOU</span>";
                                    } else {
                                        echo "<span style='background:#f1f5f9; color:#64748b; padding:3px 8px; border-radius:6px; font-size:10px;'>$solUpper</span>";
                                    }
                                    ?>
                                </td>
                                <td>
                                    <?php
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
                                    ?>
                                    <?php if (!empty($recFile) && $hasValidAudio): ?>
                                        <?php 
                                        $fileEnc = urlencode($recFile);
                                        $streamUrl = "?menu=" . htmlspecialchars($module_name) . "&rawmode=yes&action=stream_audio&file=" . $fileEnc;
                                        $downUrl   = "?menu=" . htmlspecialchars($module_name) . "&rawmode=yes&action=download_audio&file=" . $fileEnc;
                                        ?>
                                        <div style="display:flex; gap:6px; align-items:center;">
                                            <button type="button" onclick="playPesquisaAudio('<?php echo htmlspecialchars($streamUrl, ENT_QUOTES); ?>', '<?php echo htmlspecialchars($val_telefone, ENT_QUOTES); ?>', '<?php echo htmlspecialchars($val_disp_op, ENT_QUOTES); ?>', '<?php echo htmlspecialchars($downUrl, ENT_QUOTES); ?>')" style="background: linear-gradient(135deg, #7c3aed, #6d28d9); color: #ffffff; border: none; padding: 4px 10px; border-radius: 20px; font-weight: 700; font-size: 10px; cursor: pointer; box-shadow: 0 2px 6px rgba(124,58,237,0.3); transition: all 0.2s;">▶ Ouvir</button>
                                            <a href="<?php echo htmlspecialchars($downUrl, ENT_QUOTES); ?>" target="_blank" style="background: rgba(255,255,255,0.08); color: #6d28d9; border: 1px solid rgba(124,58,237,0.4); padding: 3px 9px; border-radius: 20px; font-weight: 600; font-size: 10px; text-decoration: none; transition: all 0.2s;">⬇️ Baixar</a>
                                        </div>
                                    <?php else: ?>
                                        <span title="Gravação de chamadas desativada para este ramal nas configurações do PBX" style="color:#94a3b8; font-size:10px; background:rgba(148,163,184,0.15); border:1px solid rgba(148,163,184,0.3); border-radius:12px; padding:3px 8px; font-weight:500; display:inline-flex; align-items:center; gap:4px;"><i class="fa fa-microphone-slash"></i> Ramal sem gravação</span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <tr>
                            <td colspan="9" style="text-align:center; padding:25px; color:#64748b;">
                                🚀 Nenhum registro encontrado para os filtros selecionados.
                            </td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>

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

            <!-- Barra de Paginação -->
            <div class="pagination-bar">
                <div class="pagination-info">
                    Exibindo <?php echo count($arrResult); ?> de <?php echo number_format($total, 0, ',', '.'); ?> avaliações (Página <?php echo $page; ?> de <?php echo $totalPages; ?>)
                </div>
                <div class="pagination-btns">
                    <?php
                    $navParams = array(
                        'menu' => $module_name,
                        'date_start' => $date_start,
                        'date_end' => $date_end,
                        'operador' => $operador,
                        'avaliacao' => $avaliacao,
                        'solucao' => $solucao
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

    
        
        <!-- Modal Popup de Reprodução de Gravação (Totalmente Desacoplado do Rodapé) -->
        <div id="audioPlayerModal" class="audio-modal-overlay" onclick="if(event.target === this) closeAudioPlayerModal();">
            <div class="audio-modal-card" style="background:linear-gradient(145deg, #1e1b4b 0%, #0f172a 100%) !important; border:1px solid rgba(139,92,246,0.4) !important; border-radius:16px !important; box-shadow:0 25px 50px -12px rgba(0,0,0,0.8), 0 0 30px rgba(124,58,237,0.3) !important; width:92% !important; max-width:520px !important; overflow:hidden !important; color:#ffffff !important; font-family:'Segoe UI', sans-serif !important;">
                <!-- Header do Popup -->
                <div class="audio-modal-header" style="padding:16px 20px; background:rgba(255,255,255,0.05); border-bottom:1px solid rgba(255,255,255,0.1); display:flex; justify-content:space-between; align-items:center;">
                    <div class="audio-modal-title-box" style="display:flex; align-items:center; gap:12px;">
                        <span class="audio-modal-icon" style="font-size:24px; background:rgba(124,58,237,0.3); border:1px solid rgba(139,92,246,0.4); padding:8px; border-radius:10px; display:flex; align-items:center; justify-content:center;">🎧</span>
                        <div>
                            <h3 class="audio-modal-title" style="margin:0; font-size:16px; font-weight:800; color:#f8fafc;">Reproduzindo Gravação</h3>
                            <div class="audio-modal-meta" style="margin-top:3px; font-size:12px; color:#cbd5e1; font-weight:600;">
                                <span id="stkCaller">📞 Origem: -</span> ➔ <span id="stkTarget">🎯 Destino: -</span>
                            </div>
                        </div>
                    </div>
                    <button type="button" class="audio-modal-close-btn" onclick="closeAudioPlayerModal()" title="Fechar" style="background:rgba(255,255,255,0.1); color:#e2e8f0; border:1px solid rgba(255,255,255,0.15); font-size:15px; font-weight:bold; cursor:pointer; width:32px; height:32px; border-radius:8px; display:flex; align-items:center; justify-content:center;">✖</button>
                </div>

                <!-- Body do Popup -->
                <div class="audio-modal-body" style="padding:20px;">
                    <!-- Barra de Progresso e Tempo -->
                    <div class="audio-progress-container" style="margin-bottom:18px;">
                        <div class="audio-time-row" style="display:flex; justify-content:space-between; font-size:12px; color:#94a3b8; font-weight:700; margin-bottom:6px;">
                            <span id="stkCurTime" class="time-text">00:00</span>
                            <span id="stkTotalTime" class="time-text">00:00</span>
                        </div>
                        <input type="range" id="stkProgressBar" min="0" max="100" value="0" step="0.1" oninput="stkSeekTo(this.value)" class="audio-range-slider" style="width:100%; height:6px; border-radius:3px; background:rgba(255,255,255,0.2); outline:none; cursor:pointer; accent-color:#8b5cf6;" />
                    </div>

                    <!-- Controles Principais -->
                    <div class="audio-controls-row" style="display:flex; justify-content:center; align-items:center; gap:14px; margin-bottom:20px;">
                        <button type="button" class="btn-audio-ctrl" onclick="stkSeekRelative(-5)" title="Voltar 5 segundos" style="background:rgba(255,255,255,0.1); color:#ffffff; border:1px solid rgba(255,255,255,0.15); padding:8px 14px; border-radius:8px; font-size:13px; font-weight:700; cursor:pointer;">⏮ -5s</button>
                        <button type="button" id="stkPlayPauseBtn" class="btn-audio-ctrl btn-play-main" onclick="stkTogglePlay()" style="background:linear-gradient(135deg, #7c3aed 0%, #6366f1 100%); color:#ffffff; border:none; padding:10px 24px; border-radius:8px; font-size:14px; font-weight:700; cursor:pointer; box-shadow:0 4px 12px rgba(124,58,237,0.4);">⏸ Pausar</button>
                        <button type="button" class="btn-audio-ctrl" onclick="stkSeekRelative(5)" title="Avançar 5 segundos" style="background:rgba(255,255,255,0.1); color:#ffffff; border:1px solid rgba(255,255,255,0.15); padding:8px 14px; border-radius:8px; font-size:13px; font-weight:700; cursor:pointer;">+5s ⏭</button>
                    </div>

                    <!-- Velocidade de Reprodução e Download -->
                    <div class="audio-footer-row" style="display:flex; justify-content:space-between; align-items:center; padding-top:14px; border-top:1px solid rgba(255,255,255,0.1); flex-wrap:wrap; gap:10px;">
                        <div class="audio-speed-group" style="display:flex; align-items:center; gap:5px;">
                            <span class="speed-label" style="font-size:11px; color:#94a3b8; font-weight:600; margin-right:2px;">Velocidade:</span>
                            <button type="button" class="speed-btn active" onclick="stkSetSpeed(1.0, this)" style="background:#8b5cf6; border:1px solid #8b5cf6; color:#ffffff; border-radius:6px; font-size:11px; font-weight:700; padding:4px 8px; cursor:pointer;">1.0x</button>
                            <button type="button" class="speed-btn" onclick="stkSetSpeed(1.25, this)" style="background:rgba(255,255,255,0.08); border:1px solid rgba(255,255,255,0.12); color:#94a3b8; border-radius:6px; font-size:11px; font-weight:700; padding:4px 8px; cursor:pointer;">1.25x</button>
                            <button type="button" class="speed-btn" onclick="stkSetSpeed(1.5, this)" style="background:rgba(255,255,255,0.08); border:1px solid rgba(255,255,255,0.12); color:#94a3b8; border-radius:6px; font-size:11px; font-weight:700; padding:4px 8px; cursor:pointer;">1.5x</button>
                            <button type="button" class="speed-btn" onclick="stkSetSpeed(2.0, this)" style="background:rgba(255,255,255,0.08); border:1px solid rgba(255,255,255,0.12); color:#94a3b8; border-radius:6px; font-size:11px; font-weight:700; padding:4px 8px; cursor:pointer;">2.0x</button>
                        </div>
                        <a id="stkDownloadBtn" href="#" target="_blank" class="btn-audio-download" title="Baixar Gravação" style="background:rgba(16,185,129,0.2); border:1px solid rgba(16,185,129,0.4); color:#34d399; padding:6px 12px; border-radius:6px; font-size:11px; font-weight:700; text-decoration:none; display:inline-flex; align-items:center; gap:4px;">⬇️ Baixar Gravação</a>
                    </div>
                </div>
            </div>
            <audio id="stkAudioElement" preload="auto"></audio>
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

            function ensureAudioModalInBody() {
                var modal = document.getElementById('audioPlayerModal');
                if (modal && modal.parentElement !== document.body) {
                    document.body.appendChild(modal);
                }
            }

            function playCdrAudio(audioUrl, caller, target, downloadUrl) {
                ensureAudioModalInBody();
                var modal = document.getElementById('audioPlayerModal');
                var aud = getOrInitAudio();

                var callerEl = document.getElementById('stkCaller');
                if (callerEl) callerEl.textContent = '📞 Origem: ' + (caller || '-');
                var targetEl = document.getElementById('stkTarget');
                if (targetEl) targetEl.textContent = '🎯 Destino: ' + (target || '-');
                var downEl = document.getElementById('stkDownloadBtn');
                if (downEl) downEl.href = downloadUrl || audioUrl;

                var curTimeEl = document.getElementById('stkCurTime');
                if (curTimeEl) curTimeEl.textContent = '00:00';
                var totalTimeEl = document.getElementById('stkTotalTime');
                if (totalTimeEl) totalTimeEl.textContent = '00:00';
                var bar = document.getElementById('stkProgressBar');
                if (bar) bar.value = 0;

                aud.src = audioUrl;
                if (modal) {
                    modal.classList.add('active');
                    modal.style.setProperty('display', 'flex', 'important');
                }

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

            function playMonitoringAudio(audioUrl, caller, target, downloadUrl) {
                playCdrAudio(audioUrl, caller, target, downloadUrl);
            }

            function updatePlayPauseButton(isPlaying) {
                var btn = document.getElementById('stkPlayPauseBtn');
                if (btn) {
                    if (isPlaying) {
                        btn.innerHTML = '⏸ Pausar';
                        btn.style.background = '#e11d48';
                    } else {
                        btn.innerHTML = '▶ Continuar';
                        btn.style.background = 'linear-gradient(135deg, #7c3aed 0%, #6366f1 100%)';
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
                    aud.currentTime = Math.max(0, Math.min(aud.duration || 0, frictionSeek(aud.currentTime + seconds, aud.duration)));
                }
            }

            function frictionSeek(t, dur) {
                return Math.max(0, Math.min(dur || 0, t));
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
                    var pills = document.querySelectorAll('.audio-speed-group .speed-btn');
                    pills.forEach(function(p) { 
                        p.classList.remove('active');
                        p.style.background = 'rgba(255,255,255,0.08)';
                        p.style.borderColor = 'rgba(255,255,255,0.12)';
                        p.style.color = '#94a3b8';
                    });
                    if (btn) {
                        btn.classList.add('active');
                        btn.style.background = '#8b5cf6';
                        btn.style.borderColor = '#8b5cf6';
                        btn.style.color = '#ffffff';
                    }
                }
            }

            function closeAudioPlayerModal() {
                var aud = getOrInitAudio();
                if (aud) {
                    aud.pause();
                    aud.currentTime = 0;
                }
                var modal = document.getElementById('audioPlayerModal');
                if (modal) {
                    modal.classList.remove('active');
                    modal.style.setProperty('display', 'none', 'important');
                }
                updatePlayPauseButton(false);
            }

            function formatSecondsToMmSs(sec) {
                if (!sec || isNaN(sec) || !isFinite(sec) || sec < 0) return '00:00';
                var m = Math.floor(sec / 60);
                var s = Math.floor(sec % 60);
                return (m < 10 ? '0' + m : m) + ':' + (s < 10 ? '0' + s : s);
            }

            document.addEventListener('DOMContentLoaded', function() {
                var aud = getOrInitAudio();
                if (aud) {
                    aud.addEventListener('timeupdate', function() {
                        var cur = aud.currentTime || 0;
                        var dur = aud.duration || 0;
                        var bar = document.getElementById('stkProgressBar');
                        if (bar && dur > 0) {
                            bar.value = (cur / dur) * 100;
                        }
                        var curEl = document.getElementById('stkCurTime');
                        if (curEl) curEl.textContent = formatSecondsToMmSs(cur);
                        var totEl = document.getElementById('stkTotalTime');
                        if (totEl && dur > 0) totEl.textContent = formatSecondsToMmSs(dur);
                    });

                    aud.addEventListener('ended', function() {
                        updatePlayPauseButton(false);
                    });
                }
            });

            

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


        </script>
    <?php
    return ob_get_clean();
}

function getAction()
{
    if (getParameter("save_new")) return "save_new";
    else if (getParameter("save_edit")) return "save_edit";
    else if (getParameter("delete")) return "delete";
    else if (getParameter("new_open")) return "view_form";
    else if (getParameter("action") == "view") return "view_form";
    else if (getParameter("action") == "view_edit") return "view_form";
    else return "report";
}
?>