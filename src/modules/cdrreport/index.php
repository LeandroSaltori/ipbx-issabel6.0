<?php
/* vim: set expandtab tabstop=4 softtabstop=4 shiftwidth=4:
  Codificación: UTF-8
  +----------------------------------------------------------------------+
  | Issabel version 0.5                                                  |
  | http://www.issabel.org                                               |
  +----------------------------------------------------------------------+
  | Copyright (c) 2006 Palosanto Solutions S. A.                         |
  +----------------------------------------------------------------------+
  | The contents of this file are subject to the General Public License  |
  | (GPL) Version 2 (the "License"); you may not use this file except in |
  | compliance with the License. You may obtain a copy of the License at |
  | http://www.opensource.org/licenses/gpl-license.php                   |
  |                                                                      |
  | Software distributed under the License is distributed on an "AS IS"  |
  | basis, WITHOUT WARRANTY OF ANY KIND, either express or implied. See  |
  | the License for the specific language governing rights and           |
  | limitations under the License.                                       |
  +----------------------------------------------------------------------+
  | The Initial Developer of the Original Code is PaloSanto Solutions    |
  +----------------------------------------------------------------------+
*/
include_once "libs/paloSantoGrid.class.php";
include_once "libs/paloSantoDB.class.php";
include_once "libs/paloSantoForm.class.php";
include_once "libs/paloSantoConfig.class.php";
include_once "libs/paloSantoCDR.class.php";
include_once "libs/paloSantoJSON.class.php";
require_once "libs/misc.lib.php";

if (!function_exists('serveStreamableAudioFile')) {
    function serveStreamableAudioFile($filePath) {
        if (!file_exists($filePath)) {
            header("HTTP/1.1 404 Not Found");
            echo "Arquivo de áudio não encontrado no servidor.";
            exit;
        }
        $fileSize = filesize($filePath);
        $ext = strtolower(pathinfo($filePath, PATHINFO_EXTENSION));
        $contentType = 'audio/wav';
        if ($ext == 'mp3') $contentType = 'audio/mpeg';
        if ($ext == 'gsm') $contentType = 'audio/x-gsm';

        $offset = 0;
        $length = $fileSize;

        if (isset($_SERVER['HTTP_RANGE'])) {
            if (preg_match('/bytes=\h*(\d+)-(\d*)[\D.*]?/i', $_SERVER['HTTP_RANGE'], $matches)) {
                $offset = intval($matches[1]);
                if (!empty($matches[2])) {
                    $length = intval($matches[2]) - $offset + 1;
                } else {
                    $length = $fileSize - $offset;
                }
            }
            header('HTTP/1.1 206 Partial Content');
            header("Content-Range: bytes $offset-" . ($offset + $length - 1) . "/$fileSize");
        } else {
            header('HTTP/1.1 200 OK');
        }

        header('Content-Type: ' . $contentType);
        header('Accept-Ranges: bytes');
        header('Content-Length: ' . $length);
        header('Cache-Control: no-cache, must-revalidate');
        header('Pragma: no-cache');

        $fp = fopen($filePath, 'rb');
        fseek($fp, $offset);
        $chunkSize = 8192;
        while (!feof($fp) && ($pos = ftell($fp)) <= ($offset + $length)) {
            if ($pos + $chunkSize > ($offset + $length)) {
                $chunkSize = ($offset + $length) - $pos;
            }
            if ($chunkSize <= 0) break;
            echo fread($fp, $chunkSize);
            flush();
        }
        fclose($fp);
        exit;
    }
}

function getExternalAddressBookContactsMap() {
    static $map = null;
    if ($map !== null) return $map;
    $map = array();
    $dbPath = '/var/www/db/address_book.db';
    if (!file_exists($dbPath)) return $map;
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

    $sqlOrig = "SELECT src, count(*) as total FROM cdr WHERE calldate >= '$sevenDaysAgo' AND src IN ($inClause) GROUP BY src";
    $resOrig = @$pDB->fetchTable($sqlOrig, true);
    if (is_array($resOrig)) {
        foreach ($resOrig as $row) {
            $stats[$row['src']]['recebidas_do_cliente'] = (int)$row['total'];
        }
    }

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

    if ($contact && !empty($contact['fullName'])) {
        $tooltip = "📇 Contato: " . $contact['fullName'];
        if (!empty($contact['company'])) $tooltip .= "\n🏢 Empresa: " . $contact['company'];
        if (!empty($contact['email'])) $tooltip .= "\n📧 E-mail: " . $contact['email'];
        if (!empty($contact['notes'])) $tooltip .= "\n📝 Obs: " . $contact['notes'];
        $tooltip .= "\n📊 Atividade (Últimos 7 dias):\n  • ⬇️ Recebidas deste número: $fromClientCount\n  • ⬆️ Ligadas para este número: $toClientCount";

        return "<div style='display:inline-flex; align-items:center; gap:6px; flex-wrap:wrap;'>".
            "<span title='" . htmlspecialchars($tooltip, ENT_QUOTES) . "' style='background:rgba(37,99,235,0.08); color:#1d4ed8; padding:3px 8px; border-radius:6px; font-weight:700; font-size:11px; cursor:help; border:1px solid rgba(37,99,235,0.25); display:inline-flex; align-items:center; gap:4px;'>".
            "👤 " . htmlspecialchars($contact['fullName']) . " <span style='color:#64748b; font-size:10px; font-weight:normal;'>(" . htmlspecialchars($val_src) . ")</span>".
            "</span>".
            "</div>";
    }

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
        $tooltip = "🏢 Ramal Interno: $dispText\n📊 Atividade (Últimos 7 dias):\n  • ⬇️ Chamadas atendidas pelo ramal: $toClientCount\n  • ⬆️ Chamadas originadas pelo ramal: $fromClientCount";
        return "<div style='display:inline-flex; align-items:center; gap:6px;'>".
            "<span title='" . htmlspecialchars($tooltip, ENT_QUOTES) . "' style='background:#f1f5f9; color:#334155; padding:3px 8px; border-radius:6px; font-weight:600; font-size:11px; border:1px solid #e2e8f0; display:inline-flex; align-items:center; gap:4px; cursor:help;'>".
            "👤 Ramal " . htmlspecialchars($dispText) .
            "</span>".
            "</div>";
    }

    $tooltip = "📞 Telefone Externo: $val_src\n📊 Atividade (Últimos 7 dias):\n  • ⬇️ Recebidas deste número: $fromClientCount\n  • ⬆️ Ligadas para este número: $toClientCount";
    $html = "<div style='display:inline-flex; align-items:center; gap:6px;'>".
        "<span title='" . htmlspecialchars($tooltip, ENT_QUOTES) . "' style='background:#f8fafc; color:#1e293b; padding:3px 8px; border-radius:6px; font-weight:600; font-size:11px; border:1px solid #e2e8f0; display:inline-flex; align-items:center; gap:4px; cursor:help;'>📞 " . htmlspecialchars($val_src) . "</span>";
    if (!empty($raw_src) && $raw_src != '-') {
        $html .= "<button type='button' onclick=\"openAddressBookModal('" . htmlspecialchars($raw_src, ENT_QUOTES) . "')\" title='📇 Salvar na Agenda Pública\nClique para cadastrar este número na Agenda de Contatos Pública.' style='background:rgba(59,130,246,0.12); color:#2563eb; border:1px solid rgba(59,130,246,0.3); border-radius:50%; width:20px; height:20px; display:inline-flex; align-items:center; justify-content:center; cursor:pointer; font-size:10px; transition:all 0.2s;' onmouseover=\"this.style.background='#2563eb'; this.style.color='#fff';\" onmouseout=\"this.style.background='rgba(59,130,246,0.12)'; this.style.color='#2563eb';\">📇</button>";
    }
    $html .= "</div>";
    return $html;
}

function renderDirectionAndTrunkBadge($raw_src, $val_dst, $did = '', $channel = '', $dstchannel = '', $extNamesMap = array()) {
    $srcClean = preg_replace('/\D/', '', $raw_src);
    $dstClean = preg_replace('/\D/', '', $val_dst);

    $srcIsExt = (strlen($srcClean) >= 2 && strlen($srcClean) <= 5 && !empty($srcClean)) || isset($extNamesMap[$raw_src]);
    $dstIsExt = (strlen($dstClean) >= 2 && strlen($dstClean) <= 5 && !empty($dstClean)) || isset($extNamesMap[$val_dst]);

    $trunkName = '';
    if (!empty($channel) && preg_match('/(?:SIP|DAHDI|IAX2|PJSIP|KHOMP)\/([^\/-]+)/i', $channel, $m)) {
        if (!preg_match('/^\d{2,5}$/', $m[1])) $trunkName = $m[1];
    }
    if (empty($trunkName) && !empty($dstchannel) && preg_match('/(?:SIP|DAHDI|IAX2|PJSIP|KHOMP)\/([^\/-]+)/i', $dstchannel, $m)) {
        if (!preg_match('/^\d{2,5}$/', $m[1])) $trunkName = $m[1];
    }

    if (!empty($did) && $did != '-') {
        return "<span title='📥 Entrada via Linha/DID: " . htmlspecialchars($did, ENT_QUOTES) . "' style='background:#f0fdf4; color:#166534; border:1px solid #bbf7d0; padding:4px 10px; border-radius:12px; font-weight:700; font-size:11px; display:inline-flex; align-items:center; gap:5px;'><i class='fa fa-arrow-down'></i> Entrada <span style='font-size:10px; color:#15803d; font-weight:600;'>(" . htmlspecialchars($did) . ")</span></span>";
    } elseif ($srcIsExt && !$dstIsExt && !empty($val_dst) && $val_dst != '-') {
        $sub = !empty($trunkName) ? " <span style='font-size:10px; color:#1e40af; font-weight:600;'>(" . htmlspecialchars($trunkName) . ")</span>" : "";
        return "<span title='📤 Saída (Ramal para número externo)' style='background:#eff6ff; color:#1d4ed8; border:1px solid #bfdbfe; padding:4px 10px; border-radius:12px; font-weight:700; font-size:11px; display:inline-flex; align-items:center; gap:5px;'><i class='fa fa-arrow-up'></i> Saída$sub</span>";
    } elseif ($srcIsExt && $dstIsExt) {
        return "<span title='🏢 Chamada Interna (Ramal para Ramal)' style='background:#f8fafc; color:#475569; border:1px solid #e2e8f0; padding:4px 10px; border-radius:12px; font-weight:600; font-size:11px; display:inline-flex; align-items:center; gap:5px;'><i class='fa fa-phone'></i> Interno</span>";
    } elseif (!empty($trunkName)) {
        return "<span title='📥 Entrada via Tronco: " . htmlspecialchars($trunkName, ENT_QUOTES) . "' style='background:#f0fdf4; color:#166534; border:1px solid #bbf7d0; padding:4px 10px; border-radius:12px; font-weight:700; font-size:11px; display:inline-flex; align-items:center; gap:5px;'><i class='fa fa-arrow-down'></i> Entrada <span style='font-size:10px; color:#15803d; font-weight:600;'>(" . htmlspecialchars($trunkName) . ")</span></span>";
    } else {
        return "<span style='background:#f0fdf4; color:#166534; border:1px solid #bbf7d0; padding:4px 10px; border-radius:12px; font-weight:700; font-size:11px; display:inline-flex; align-items:center; gap:5px;'><i class='fa fa-arrow-down'></i> Entrada</span>";
    }
}

function handleSaveAddressBook($arrConf = array())
{
    while (ob_get_level()) ob_end_clean();
    header('Content-Type: application/json; charset=utf-8');

    $phone = isset($_POST['phone']) ? trim($_POST['phone']) : '';
    $name = isset($_POST['name']) ? trim($_POST['name']) : '';
    $last_name = isset($_POST['last_name']) ? trim($_POST['last_name']) : '';
    $company = isset($_POST['company']) ? trim($_POST['company']) : '';
    $email = isset($_POST['email']) ? trim($_POST['email']) : '';
    $notes = isset($_POST['notes']) ? trim($_POST['notes']) : '';

    if (empty($phone) || empty($name)) {
        echo json_encode(array('status' => 'error', 'message' => 'Telefone e Nome são campos obrigatórios.'));
        exit;
    }

    $dbPath = '/var/www/db/address_book.db';
    try {
        $cleanPhone = preg_replace('/\D/', '', $phone);
        if (class_exists('SQLite3')) {
            $db = new SQLite3($dbPath);
            $stmt = $db->prepare("SELECT id, name, last_name, telefono FROM contact WHERE (telefono = :p1 OR telefono = :p2) AND directory = 'external' LIMIT 1");
            $stmt->bindValue(':p1', $phone, SQLITE3_TEXT);
            $stmt->bindValue(':p2', $cleanPhone, SQLITE3_TEXT);
            $res = $stmt->execute();
            $existing = $res ? $res->fetchArray(SQLITE3_ASSOC) : false;

            if ($existing && !empty($existing['id'])) {
                $contactName = trim($existing['name'] . ' ' . $existing['last_name']);
                if (empty($contactName)) $contactName = $existing['telefono'];
                echo json_encode(array(
                    'status' => 'exists',
                    'message' => "Este número ($phone) já está cadastrado na Agenda Pública como '$contactName'!"
                ));
                $db->close();
                exit;
            }

            $insert = $db->prepare("INSERT INTO contact (name, last_name, telefono, cell_phone, home_phone, fax1, fax2, email, iduser, picture, province, city, address, company, company_contact, contact_rol, directory, notes, status, department, im) VALUES (:name, :last_name, :telefono, '', '', '', '', :email, 1, '', '', '', '', :company, '', '', 'external', :notes, 'isPublic', '', '')");
            $insert->bindValue(':name', $name, SQLITE3_TEXT);
            $insert->bindValue(':last_name', $last_name, SQLITE3_TEXT);
            $insert->bindValue(':telefono', $phone, SQLITE3_TEXT);
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
        }
    } catch (Exception $e) {
        echo json_encode(array('status' => 'error', 'message' => 'Erro ao salvar contato: ' . $e->getMessage()));
        exit;
    }
}

function handleCdrAudioStream()
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
        serveStreamableAudioFile($filePath);
    } else {
        header("HTTP/1.1 404 Not Found");
        echo "Arquivo de áudio não localizado.";
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
    $arrConf = array_merge($arrConf,$arrConfModule);

    $base_dir=dirname($_SERVER['SCRIPT_FILENAME']);
    $templates_dir=(isset($arrConf['templates_dir']))?$arrConf['templates_dir']:'themes';
    $local_templates_dir="$base_dir/modules/$module_name/".$templates_dir.'/'.$arrConf['theme'];
    
    $filterLocalChannel = true;

    $dsn  = generarDSNSistema('asteriskuser', 'asteriskcdrdb');
    $pDB  = new paloDB($dsn);
    $oCDR = new paloSantoCDR($pDB);

    if (isset($_REQUEST['action']) && $_REQUEST['action'] == 'save_address_book') {
        handleSaveAddressBook($arrConf);
        exit;
    }

    if (isset($_GET['action']) && $_GET['action'] == 'stream_audio') {
        handleCdrAudioStream();
        exit;
    }

    if (isset($_REQUEST['uniqueid'])) {
        return renderCelDetailsHtml($pDB, $_REQUEST['uniqueid']);
    }

    return renderFullCdrReportDashboard($oCDR, $pDB, $module_name, $smarty);
}

function renderCelDetailsHtml(&$pDB, $uniqueId) {
    while (ob_get_level()) ob_end_clean();
    $uniqueId = addslashes($uniqueId);
    $sPeticionSQL = "SELECT linkedid FROM cel WHERE uniqueid='$uniqueId' LIMIT 1";
    $arrData = $pDB->fetchTable($sPeticionSQL, false);
    $linkedId = isset($arrData[0][0]) ? $arrData[0][0] : $uniqueId;

    $sPeticionSQL = "SELECT eventtime, eventtype, cid_name, cid_num, cid_dnid, exten, appname, uniqueid FROM cel WHERE linkedid='$linkedId' ORDER BY id ASC";
    $events = $pDB->fetchTable($sPeticionSQL, true);
    if (!is_array($events)) $events = array();

    ob_start();
    ?>
    <!DOCTYPE html>
    <html lang="pt-BR">
    <head>
        <meta charset="UTF-8">
        <title>Eventos CEL - <?php echo htmlspecialchars($uniqueId); ?></title>
        <style>
            body { font-family: 'Segoe UI', system-ui, sans-serif; font-size: 12px; background: #f8fafc; color: #1e293b; padding: 15px; margin: 0; }
            .cel-header { display: flex; justify-content: space-between; align-items: center; background: #ffffff; padding: 12px 18px; border-radius: 8px; border: 1px solid #e2e8f0; margin-bottom: 15px; }
            .cel-title { font-size: 15px; font-weight: 800; color: #0f172a; }
            .cel-table { width: 100%; border-collapse: collapse; background: #ffffff; border-radius: 8px; overflow: hidden; border: 1px solid #e2e8f0; }
            .cel-table th { background: #f1f5f9; padding: 8px 12px; font-weight: 700; color: #475569; text-align: left; font-size: 11px; text-transform: uppercase; }
            .cel-table td { padding: 8px 12px; border-bottom: 1px solid #f1f5f9; font-size: 12px; }
            .cel-table tr:hover { background: #f8fafc; }
            .event-badge { display: inline-block; padding: 3px 8px; border-radius: 12px; font-size: 10px; font-weight: 700; background: #e0e7ff; color: #4338ca; }
        </style>
    </head>
    <body>
        <div class="cel-header">
            <div class="cel-title">📋 Linha do Tempo CEL (LinkedID: <?php echo htmlspecialchars($linkedId); ?>)</div>
            <button onclick="if(window.parent && window.parent.closeCelModal) { window.parent.closeCelModal(); } else { window.close(); }" style="background: #ef4444; color: #fff; border: none; padding: 6px 14px; border-radius: 6px; font-weight: bold; cursor: pointer;">Fechar ✖</button>
        </div>
        <table class="cel-table">
            <thead>
                <tr>
                    <th>Data / Hora</th>
                    <th>Evento</th>
                    <th>Nome CallerID</th>
                    <th>Número CallerID</th>
                    <th>DNID</th>
                    <th>Ramal / Destino</th>
                    <th>Aplicação</th>
                    <th>UniqueID</th>
                </tr>
            </thead>
            <tbody>
                <?php if (!empty($events)): ?>
                    <?php foreach ($events as $ev): ?>
                        <tr>
                            <td><?php echo htmlspecialchars($ev['eventtime']); ?></td>
                            <td><span class="event-badge"><?php echo htmlspecialchars($ev['eventtype']); ?></span></td>
                            <td><?php echo htmlspecialchars($ev['cid_name']); ?></td>
                            <td>📞 <?php echo htmlspecialchars($ev['cid_num']); ?></td>
                            <td><?php echo htmlspecialchars($ev['cid_dnid']); ?></td>
                            <td>🎯 <?php echo htmlspecialchars($ev['exten']); ?></td>
                            <td><code><?php echo htmlspecialchars($ev['appname']); ?></code></td>
                            <td style="font-family: monospace; font-size: 11px; color: #64748b;"><?php echo htmlspecialchars($ev['uniqueid']); ?></td>
                        </tr>
                    <?php endforeach; ?>
                <?php else: ?>
                    <tr><td colspan="8" style="text-align:center; padding: 20px; color:#94a3b8;">Nenhum evento CEL registrado para esta chamada.</td></tr>
                <?php endif; ?>
            </tbody>
        </table>
    </body>
    </html>
    <?php
    echo ob_get_clean();
    exit;
}

function renderFullCdrReportDashboard($oCDR, &$pDB, $module_name, &$smarty)
{
    $date_start_raw = isset($_REQUEST['date_start']) ? trim($_REQUEST['date_start']) : date("Y-m-d");
    $date_end_raw   = isset($_REQUEST['date_end']) ? trim($_REQUEST['date_end']) : date("Y-m-d");

    $date_start_ts = strtotime($date_start_raw);
    $date_end_ts   = strtotime($date_end_raw);
    $date_start    = $date_start_ts ? date("Y-m-d", $date_start_ts) : date("Y-m-d");
    $date_end      = $date_end_ts ? date("Y-m-d", $date_end_ts) : date("Y-m-d");

    $field_name    = isset($_REQUEST['field_name']) ? trim($_REQUEST['field_name']) : 'dst';
    $field_pattern = isset($_REQUEST['field_pattern']) ? trim($_REQUEST['field_pattern']) : '';
    $status        = isset($_REQUEST['status']) ? trim($_REQUEST['status']) : 'ALL';
    $call_scope    = isset($_REQUEST['call_scope']) ? trim($_REQUEST['call_scope']) : 'ALL';

    $isFirstLoad   = !isset($_REQUEST['filter_applied']) && !isset($_REQUEST['page']);
    if ($isFirstLoad) {
        $min_duration = 0;
        $hide_zero    = 0;
    } else {
        $hide_zero    = isset($_REQUEST['hide_zero']) ? 1 : 0;
        $min_duration = isset($_REQUEST['min_duration']) ? (int)$_REQUEST['min_duration'] : 0;
        if ($hide_zero == 1 && $min_duration < 1) $min_duration = 1;
    }

    $page  = isset($_REQUEST['page']) ? max(1, (int)$_REQUEST['page']) : 1;
    $limit = isset($_REQUEST['limit']) ? (int)$_REQUEST['limit'] : 20;
    if ($limit <= 0) $limit = 20;

    $paramFiltro = array(
        'date_start'    => $date_start . ' 00:00:00',
        'date_end'      => $date_end . ' 23:59:59',
        'field_name'    => $field_name,
        'field_pattern' => $field_pattern,
        'status'        => $status,
        'ringgroup'     => '',
        'limit'         => 100000,
        'timeInSecs'    => 'off'
    );

    $arrResultAll = $oCDR->listarCDRs($paramFiltro, 100000, 0, true);
    $rawList      = isset($arrResultAll['cdrs']) && is_array($arrResultAll['cdrs']) ? $arrResultAll['cdrs'] : array();

    $contactsMap = getExternalAddressBookContactsMap();
    $extNamesMap = getAsteriskExtensionNamesMap($pDB);

    $totalCount = count($rawList);
    $ansCount   = 0;
    $noAnsCount = 0;
    $busyCount  = 0;
    $filteredList = array();

    foreach ($rawList as $r) {
        $st = strtoupper($r[5]);
        if ($st == 'ANSWERED') $ansCount++;
        elseif ($st == 'NO ANSWER') $noAnsCount++;
        elseif ($st == 'BUSY' || $st == 'FAILED') $busyCount++;

        $durSecs = (int)$r[8];
        if ($min_duration > 0 && $durSecs < $min_duration) continue;

        $srcClean = preg_replace('/\D/', '', $r[1]);
        $dstClean = preg_replace('/\D/', '', $r[2]);
        $srcIsExt = (strlen($srcClean) >= 2 && strlen($srcClean) <= 5 && !empty($srcClean)) || isset($extNamesMap[$r[1]]);
        $dstIsExt = (strlen($dstClean) >= 2 && strlen($dstClean) <= 5 && !empty($dstClean)) || isset($extNamesMap[$r[2]]);

        if ($call_scope == 'INCOMING' && (!$dstIsExt || $srcIsExt)) continue;
        if ($call_scope == 'OUTGOING' && (!$srcIsExt || $dstIsExt)) continue;
        if ($call_scope == 'INTERNAL' && (!$srcIsExt || !$dstIsExt)) continue;

        $filteredList[] = $r;
    }

    $filteredTotal = count($filteredList);
    $totalPages    = ceil($filteredTotal / $limit);
    if ($totalPages < 1) $totalPages = 1;
    if ($page > $totalPages) $page = $totalPages;

    $offset = ($page - 1) * $limit;
    $pageList = array_slice($filteredList, $offset, $limit);

    $pageSrcList = array();
    foreach ($pageList as $_row) {
        if (!empty($_row[1]) && $_row[1] != '-') $pageSrcList[] = $_row[1];
    }
    $stats7dMap = getCdr7DaysStatsMap($pageSrcList, $pDB);

    ob_start();
    ?>
    <style>
        .cdr-root { font-family: 'Segoe UI', system-ui, -apple-system, sans-serif; color: #1e293b; padding: 10px 0; }
        .cdr-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 16px; flex-wrap: wrap; gap: 10px; }
        .cdr-title h2 { margin: 0; font-size: 20px; font-weight: 800; color: #0f172a; }
        .cdr-title p { margin: 2px 0 0 0; font-size: 12px; color: #64748b; }
        .cdr-top-btns { display: flex; gap: 8px; }
        .btn-top { padding: 7px 14px; border-radius: 8px; font-weight: 700; font-size: 12px; cursor: pointer; text-decoration: none; display: inline-flex; align-items: center; gap: 6px; border: none; transition: all 0.2s; }
        .btn-top-manual { background: #f1f5f9; color: #334155; border: 1px solid #cbd5e1; }
        .btn-top-manual:hover { background: #e2e8f0; color: #0f172a; }
        .btn-top-expand { background: #6366f1; color: #ffffff; }
        .btn-top-expand:hover { background: #4f46e5; }

        .kpi-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 12px; margin-bottom: 16px; }
        .kpi-card { background: #ffffff; border: 1px solid #e2e8f0; border-radius: 12px; padding: 14px; display: flex; align-items: center; gap: 12px; box-shadow: 0 1px 3px rgba(0,0,0,0.05); }
        .kpi-icon { width: 42px; height: 42px; border-radius: 10px; display: flex; align-items: center; justify-content: center; font-size: 20px; }
        .kpi-icon-total { background: rgba(99,102,241,0.12); color: #6366f1; }
        .kpi-icon-ans { background: rgba(16,185,129,0.12); color: #10b981; }
        .kpi-icon-noans { background: rgba(239,68,68,0.12); color: #ef4444; }
        .kpi-icon-busy { background: rgba(245,158,11,0.12); color: #f59e0b; }
        .kpi-label { font-size: 11px; font-weight: 700; color: #64748b; text-transform: uppercase; }
        .kpi-val { font-size: 20px; font-weight: 800; color: #0f172a; margin-top: 2px; }

        .filter-card { background: #ffffff; border: 1px solid #e2e8f0; border-radius: 12px; padding: 16px; margin-bottom: 16px; box-shadow: 0 1px 3px rgba(0,0,0,0.05); }
        .filter-row { display: flex; flex-wrap: wrap; gap: 12px; align-items: flex-end; }
        .filter-group { display: flex; flex-direction: column; gap: 4px; flex: 1; min-width: 140px; }
        .filter-group label { font-size: 11px; font-weight: 700; color: #475569; text-transform: uppercase; }
        .filter-control { padding: 7px 10px; border: 1px solid #cbd5e1; border-radius: 6px; font-size: 12px; background: #f8fafc; color: #1e293b; outline: none; }
        .filter-control:focus { border-color: #6366f1; background: #fff; }
        .btn-filter-submit { background: #2563eb; color: #fff; border: none; padding: 8px 18px; border-radius: 6px; font-weight: 700; font-size: 12px; cursor: pointer; display: inline-flex; align-items: center; gap: 6px; }
        .btn-filter-submit:hover { background: #1d4ed8; }

        .table-card { background: #ffffff; border: 1px solid #e2e8f0; border-radius: 12px; overflow: hidden; box-shadow: 0 1px 3px rgba(0,0,0,0.05); }
        .cdr-table { width: 100%; border-collapse: collapse; font-size: 12px; text-align: left; }
        .cdr-table th { background: #f8fafc; padding: 10px 14px; font-size: 11px; font-weight: 800; color: #475569; text-transform: uppercase; border-bottom: 1px solid #e2e8f0; }
        .cdr-table td { padding: 10px 14px; border-bottom: 1px solid #f1f5f9; vertical-align: middle; }
        .cdr-table tr:hover { background: #f8fafc; }

        .status-badge { display: inline-block; padding: 3px 8px; border-radius: 12px; font-size: 10px; font-weight: 800; text-transform: uppercase; }
        .status-ans { background: #dcfce7; color: #15803d; }
        .status-noans { background: #fee2e2; color: #b91c1c; }
        .status-busy { background: #fef3c7; color: #b45309; }

        .pagination-box { display: flex; justify-content: space-between; align-items: center; padding: 12px 16px; background: #ffffff; border-top: 1px solid #e2e8f0; }
        .page-btn { padding: 5px 12px; border-radius: 6px; background: #f1f5f9; color: #334155; font-size: 12px; font-weight: 700; text-decoration: none; border: 1px solid #cbd5e1; }
        .page-btn:hover { background: #e2e8f0; }
        .page-btn.disabled { opacity: 0.5; pointer-events: none; }
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
                <button type="button" onclick="window.open('?menu=<?php echo htmlspecialchars($module_name); ?>&rawmode=yes', '_blank')" class="btn-top btn-top-expand">↗ Expandir Aba</button>
            </div>
        </div>

        <!-- Cards KPI Topo -->
        <div class="kpi-grid">
            <div class="kpi-card">
                <div class="kpi-icon kpi-icon-total">📞</div>
                <div>
                    <div class="kpi-label">Total Chamadas</div>
                    <div class="kpi-val"><?php echo number_format($totalCount, 0, ',', '.'); ?></div>
                </div>
            </div>
            <div class="kpi-card">
                <div class="kpi-icon kpi-icon-ans">✅</div>
                <div>
                    <div class="kpi-label">Atendidas</div>
                    <div class="kpi-val"><?php echo number_format($ansCount, 0, ',', '.'); ?></div>
                </div>
            </div>
            <div class="kpi-card">
                <div class="kpi-icon kpi-icon-noans">❌</div>
                <div>
                    <div class="kpi-label">Não Atendidas</div>
                    <div class="kpi-val"><?php echo number_format($noAnsCount, 0, ',', '.'); ?></div>
                </div>
            </div>
            <div class="kpi-card">
                <div class="kpi-icon kpi-icon-busy">⏳</div>
                <div>
                    <div class="kpi-label">Ocupado / Falhas</div>
                    <div class="kpi-val"><?php echo number_format($busyCount, 0, ',', '.'); ?></div>
                </div>
            </div>
        </div>

        <!-- Card de Filtros -->
        <div class="filter-card">
            <form method="GET" action="index.php">
                <input type="hidden" name="menu" value="<?php echo htmlspecialchars($module_name); ?>" />
                <input type="hidden" name="filter_applied" value="1" />
                <div class="filter-row">
                    <div class="filter-group">
                        <label>📅 Data Inicial</label>
                        <input type="date" name="date_start" value="<?php echo htmlspecialchars($date_start); ?>" class="filter-control" />
                    </div>
                    <div class="filter-group">
                        <label>📅 Data Final</label>
                        <input type="date" name="date_end" value="<?php echo htmlspecialchars($date_end); ?>" class="filter-control" />
                    </div>
                    <div class="filter-group">
                        <label>📞 Padrão / Número</label>
                        <input type="text" name="field_pattern" value="<?php echo htmlspecialchars($field_pattern); ?>" placeholder="Ex: 5001 ou 99988..." class="filter-control" />
                    </div>
                    <div class="filter-group">
                        <label>📌 Campo Busca</label>
                        <select name="field_name" class="filter-control">
                            <option value="dst" <?php if ($field_name == 'dst') echo 'selected'; ?>>Destino (dst)</option>
                            <option value="src" <?php if ($field_name == 'src') echo 'selected'; ?>>Origem (src)</option>
                            <option value="channel" <?php if ($field_name == 'channel') echo 'selected'; ?>>Canal Origem</option>
                            <option value="accountcode" <?php if ($field_name == 'accountcode') echo 'selected'; ?>>Fila / Accountcode</option>
                            <option value="dstchannel" <?php if ($field_name == 'dstchannel') echo 'selected'; ?>>Canal Destino</option>
                            <option value="did" <?php if ($field_name == 'did') echo 'selected'; ?>>DID Entrante</option>
                        </select>
                    </div>
                    <div class="filter-group">
                        <label>🚦 Status</label>
                        <select name="status" class="filter-control">
                            <option value="ALL" <?php if ($status == 'ALL') echo 'selected'; ?>>Todos os Status</option>
                            <option value="ANSWERED" <?php if ($status == 'ANSWERED') echo 'selected'; ?>>Atendidas</option>
                            <option value="NO ANSWER" <?php if ($status == 'NO ANSWER') echo 'selected'; ?>>Não Atendidas</option>
                            <option value="BUSY" <?php if ($status == 'BUSY') echo 'selected'; ?>>Ocupadas</option>
                            <option value="FAILED" <?php if ($status == 'FAILED') echo 'selected'; ?>>Falhas</option>
                        </select>
                    </div>
                    <div class="filter-group">
                        <label>🌐 Direção / Escopo</label>
                        <select name="call_scope" class="filter-control">
                            <option value="ALL" <?php if ($call_scope == 'ALL') echo 'selected'; ?>>Todas as Chamadas</option>
                            <option value="INCOMING" <?php if ($call_scope == 'INCOMING') echo 'selected'; ?>>⬇️ Recebidas (Entrada)</option>
                            <option value="OUTGOING" <?php if ($call_scope == 'OUTGOING') echo 'selected'; ?>>⬆️ Efetuadas (Saída)</option>
                            <option value="INTERNAL" <?php if ($call_scope == 'INTERNAL') echo 'selected'; ?>>🏢 Internas (Ramal-Ramal)</option>
                        </select>
                    </div>
                    <div class="filter-group" style="flex:0 0 auto;">
                        <button type="submit" class="btn-filter-submit">🔍 Filtrar</button>
                    </div>
                </div>
            </form>
        </div>

        <!-- Tabela de Resultados -->
        <div class="table-card">
            <table class="cdr-table">
                <thead>
                    <tr>
                        <th>Data / Hora</th>
                        <th>Origem (De)</th>
                        <th>Destino (Para)</th>
                        <th>Direção / Tronco</th>
                        <th>Status</th>
                        <th>Duração</th>
                        <th>Gravação</th>
                        <th>Ações</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (!empty($pageList)): ?>
                        <?php foreach ($pageList as $r): ?>
                            <?php
                            $st = strtoupper($r[5]);
                            $stClass = 'status-ans';
                            if ($st == 'NO ANSWER') $stClass = 'status-noans';
                            elseif ($st == 'BUSY' || $st == 'FAILED') $stClass = 'status-busy';

                            $dur = (int)$r[8];
                            $durStr = $dur . 's';
                            if ($dur >= 60) {
                                $m = floor($dur / 60);
                                $s = $dur % 60;
                                $durStr = $m . 'm ' . ($s < 10 ? '0' . $s : $s) . 's';
                            }

                            $recFile = !empty($r[9]) ? basename($r[9]) : '';
                            $hasRec = !empty($recFile) && $recFile != 'deleted';
                            ?>
                            <tr>
                                <td style="font-weight:600; color:#334155;"><?php echo date('d/m/Y H:i:s', strtotime($r[0])); ?></td>
                                <td><?php echo renderCallerWithContactBadge($r[1], $r[1], $contactsMap, $stats7dMap, $extNamesMap); ?></td>
                                <td><span style="font-weight:600; color:#1e293b;">🎯 <?php echo htmlspecialchars($r[2]); ?></span></td>
                                <td><?php echo renderDirectionAndTrunkBadge($r[1], $r[2], $r[16], $r[3], $r[4], $extNamesMap); ?></td>
                                <td><span class="status-badge <?php echo $stClass; ?>"><?php echo htmlspecialchars($r[5]); ?></span></td>
                                <td style="font-family:monospace; font-weight:700; color:#475569;"><?php echo $durStr; ?></td>
                                <td>
                                    <?php if ($hasRec): ?>
                                        <button type="button" onclick="playCdrAudio('?menu=<?php echo htmlspecialchars($module_name); ?>&action=stream_audio&file=<?php echo urlencode($recFile); ?>', '<?php echo htmlspecialchars($r[1]); ?>', '<?php echo htmlspecialchars($r[2]); ?>', '?menu=<?php echo htmlspecialchars($module_name); ?>&action=download_audio&file=<?php echo urlencode($recFile); ?>')" style="background:#10b981; color:#fff; border:none; padding:4px 10px; border-radius:6px; font-weight:bold; font-size:11px; cursor:pointer;">▶ Ouvir</button>
                                    <?php else: ?>
                                        <span style="color:#94a3b8; font-size:11px;">-</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <button type="button" onclick="openCelModal('<?php echo htmlspecialchars($r[6]); ?>')" style="background:#6366f1; color:#fff; border:none; padding:4px 8px; border-radius:6px; font-weight:bold; font-size:11px; cursor:pointer;">📋 CEL</button>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <tr><td colspan="8" style="text-align:center; padding:30px; color:#94a3b8;">Nenhum registro de chamada encontrado para os filtros selecionados.</td></tr>
                    <?php endif; ?>
                </tbody>
            </table>

            <!-- Barra de Paginação -->
            <div class="pagination-box">
                <div style="font-size:12px; color:#64748b; font-weight:600;">
                    Exibindo <?php echo count($pageList); ?> de <?php echo number_format($filteredTotal, 0, ',', '.'); ?> chamadas (Página <?php echo $page; ?> de <?php echo $totalPages; ?>)
                </div>
                <div style="display:flex; gap:6px;">
                    <?php
                    $qs = http_build_query(array(
                        'menu' => $module_name,
                        'date_start' => $date_start,
                        'date_end' => $date_end,
                        'field_name' => $field_name,
                        'field_pattern' => $field_pattern,
                        'status' => $status,
                        'call_scope' => $call_scope,
                        'filter_applied' => 1
                    ));
                    ?>
                    <a href="?<?php echo $qs; ?>&page=1" class="page-btn <?php if ($page <= 1) echo 'disabled'; ?>">« Primeira</a>
                    <a href="?<?php echo $qs; ?>&page=<?php echo max(1, $page - 1); ?>" class="page-btn <?php if ($page <= 1) echo 'disabled'; ?>">‹ Anterior</a>
                    <a href="?<?php echo $qs; ?>&page=<?php echo min($totalPages, $page + 1); ?>" class="page-btn <?php if ($page >= $totalPages) echo 'disabled'; ?>">Próxima ›</a>
                    <a href="?<?php echo $qs; ?>&page=<?php echo $totalPages; ?>" class="page-btn <?php if ($page >= $totalPages) echo 'disabled'; ?>">Última »</a>
                </div>
            </div>
        </div>
    </div>

    <!-- Sticky Bottom Audio Player -->
    <div id="stickyBottomAudioPlayer" class="sticky-audio-bar">
        <div class="sticky-audio-inner">
            <div class="sticky-audio-info">
                <div class="sticky-audio-icon">🎧</div>
                <div class="sticky-audio-meta">
                    <div class="sticky-audio-title">REPRODUZINDO GRAVAÇÃO</div>
                    <div class="sticky-audio-numbers">
                        <span id="stkCaller">📞 -</span> <i class="fa fa-arrow-right" style="font-size:10px; opacity:0.6;"></i> <span id="stkTarget">🎯 -</span>
                        <span id="stkTime" class="sticky-audio-time">00:00</span>
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
    <div id="celModalCdr" style="display:none; position:fixed; top:0; left:0; width:100%; height:100%; background:rgba(15,23,42,0.65); backdrop-filter:blur(4px); z-index:2147483647; align-items:center; justify-content:center;" onclick="if(event.target === this) closeCelModal();">
        <div style="background:#ffffff; border-radius:14px; padding:20px; width:860px; max-width:95%; box-shadow:0 25px 50px -12px rgba(0,0,0,0.35); text-align:center; border:1px solid #e2e8f0; position:relative;">
            <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:10px; border-bottom:1px solid #f1f5f9; padding-bottom:8px;">
                <h4 style="margin:0; font-size:16px; color:#0f172a; font-weight:800; display:flex; align-items:center; gap:8px;">📋 Histórico de Eventos da Chamada (CEL)</h4>
                <button type="button" onclick="closeCelModal()" style="background:none; border:none; color:#94a3b8; font-size:18px; cursor:pointer; font-weight:bold;">✖</button>
            </div>
            <iframe id="celIframeElement" style="width:100%; height:480px; border:none; border-radius:8px;"></iframe>
            <div style="margin-top:12px; text-align:center;">
                <button type="button" onclick="closeCelModal()" style="background:#475569; color:#fff; border:none; padding:7px 22px; border-radius:8px; font-weight:bold; cursor:pointer; font-size:12px;">Fechar</button>
            </div>
        </div>
    </div>

    <!-- Modal Salvar na Agenda Pública -->
    <div id="addressBookModal" style="display:none; position:fixed; top:0; left:0; width:100%; height:100%; background:rgba(15,23,42,0.65); backdrop-filter:blur(4px); z-index:2147483647; align-items:center; justify-content:center;">
        <div style="background:#ffffff; border-radius:14px; padding:24px; width:440px; max-width:92%; box-shadow:0 25px 50px -12px rgba(0,0,0,0.35); text-align:left; border:1px solid #e2e8f0; font-family:'Segoe UI', system-ui, sans-serif;">
            <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:14px; border-bottom:1px solid #f1f5f9; padding-bottom:10px;">
                <h4 style="margin:0; font-size:16px; color:#0f172a; font-weight:800; display:flex; align-items:center; gap:8px;">📇 Adicionar à Agenda Pública</h4>
                <button type="button" onclick="closeAddressBookModal()" style="background:none; border:none; color:#94a3b8; font-size:18px; cursor:pointer; font-weight:bold;">✖</button>
            </div>
            <form onsubmit="submitSaveAddressBook(event)">
                <div style="margin-bottom:10px;">
                    <label style="display:block; font-size:11px; font-weight:700; color:#475569; margin-bottom:4px;">Número / Telefone</label>
                    <input type="text" id="ab_phone" required readonly style="width:100%; box-sizing:border-box; background:#f8fafc; border:1px solid #cbd5e1; border-radius:6px; padding:8px 10px; font-weight:bold; color:#1e293b;" />
                </div>
                <div style="margin-bottom:10px; display:flex; gap:10px;">
                    <div style="flex:1;">
                        <label style="display:block; font-size:11px; font-weight:700; color:#475569; margin-bottom:4px;">Nome *</label>
                        <input type="text" id="ab_name" required placeholder="Nome" style="width:100%; box-sizing:border-box; border:1px solid #cbd5e1; border-radius:6px; padding:8px 10px;" />
                    </div>
                    <div style="flex:1;">
                        <label style="display:block; font-size:11px; font-weight:700; color:#475569; margin-bottom:4px;">Sobrenome</label>
                        <input type="text" id="ab_last_name" placeholder="Sobrenome" style="width:100%; box-sizing:border-box; border:1px solid #cbd5e1; border-radius:6px; padding:8px 10px;" />
                    </div>
                </div>
                <div style="margin-bottom:10px;">
                    <label style="display:block; font-size:11px; font-weight:700; color:#475569; margin-bottom:4px;">Empresa</label>
                    <input type="text" id="ab_company" placeholder="Nome da Empresa" style="width:100%; box-sizing:border-box; border:1px solid #cbd5e1; border-radius:6px; padding:8px 10px;" />
                </div>
                <div style="margin-bottom:10px;">
                    <label style="display:block; font-size:11px; font-weight:700; color:#475569; margin-bottom:4px;">E-mail</label>
                    <input type="email" id="ab_email" placeholder="contato@empresa.com" style="width:100%; box-sizing:border-box; border:1px solid #cbd5e1; border-radius:6px; padding:8px 10px;" />
                </div>
                <div style="margin-bottom:14px;">
                    <label style="display:block; font-size:11px; font-weight:700; color:#475569; margin-bottom:4px;">Observações</label>
                    <textarea id="ab_notes" rows="2" placeholder="Anotações do contato..." style="width:100%; box-sizing:border-box; border:1px solid #cbd5e1; border-radius:6px; padding:8px 10px; font-size:12px;"></textarea>
                </div>
                <div style="display:flex; justify-content:flex-end; gap:8px;">
                    <button type="button" onclick="closeAddressBookModal()" style="background:#f1f5f9; color:#475569; border:1px solid #cbd5e1; padding:8px 14px; border-radius:6px; font-weight:700; cursor:pointer;">Cancelar</button>
                    <button type="submit" id="btnSaveAb" style="background:#2563eb; color:#fff; border:none; padding:8px 18px; border-radius:6px; font-weight:700; cursor:pointer;">💾 Salvar Contato</button>
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
        ensureAudioBarInBody();
        document.addEventListener('DOMContentLoaded', ensureAudioBarInBody);

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
            if (!secs || isNaN(secs) || !isFinite(secs) || secs < 0) return '00:00';
            var m = Math.floor(secs / 60);
            var s = Math.floor(secs % 60);
            return (m < 10 ? '0' + m : m) + ':' + (s < 10 ? '0' + s : s);
        }

        function openCelModal(uniqueId) {
            var modal = document.getElementById('celModalCdr');
            if (modal && modal.parentElement !== document.body) {
                document.body.appendChild(modal);
            }
            var iframe = document.getElementById('celIframeElement');
            iframe.src = '?menu=<?php echo htmlspecialchars($module_name); ?>&rawmode=yes&uniqueid=' + uniqueId;
            modal.style.display = 'flex';
        }

        function closeCelModal() {
            var modal = document.getElementById('celModalCdr');
            if (modal) {
                modal.style.display = 'none';
                var iframe = document.getElementById('celIframeElement');
                if (iframe) iframe.src = 'about:blank';
            }
        }

        function openAddressBookModal(phoneNumber) {
            var modal = document.getElementById('addressBookModal');
            if (modal && modal.parentElement !== document.body) {
                document.body.appendChild(modal);
            }
            document.getElementById('ab_phone').value = phoneNumber || '';
            document.getElementById('ab_name').value = '';
            document.getElementById('ab_last_name').value = '';
            document.getElementById('ab_company').value = '';
            document.getElementById('ab_email').value = '';
            document.getElementById('ab_notes').value = '';
            modal.style.display = 'flex';
            document.getElementById('ab_name').focus();
        }

        function closeAddressBookModal() {
            var modal = document.getElementById('addressBookModal');
            if (modal) modal.style.display = 'none';
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
                    if (bar && dur > 0 && isFinite(dur)) {
                        bar.value = (cur / dur) * 100;
                    }
                    var timeEl = document.getElementById('stkTime');
                    if (timeEl) {
                        var curText = formatSecondsToMmSs(cur);
                        var durText = (dur && isFinite(dur) && dur > 0) ? ' / ' + formatSecondsToMmSs(dur) : '';
                        timeEl.textContent = curText + durText;
                    }
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
