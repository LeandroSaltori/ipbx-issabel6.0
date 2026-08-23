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
  $Id: index.php, Fri 09 Apr 2021 10:46:33 AM EDT, nicolas@issabel.com
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

    $pDBACL = new paloDB($arrConf['issabel_dsn']['acl']);
    if (!empty($pDBACL->errMsg)) {
        return "ERROR DE DB: $pDBACL->errMsg";
    }
    $pACL = new paloACL($pDBACL);
    if (!empty($pACL->errMsg)) {
        return "ERROR DE ACL: $pACL->errMsg";
    }
    $user = $_SESSION['issabel_user'];
    $extension = $pACL->getUserExtension($user);
    if ($extension == '') $extension = NULL;

    $bPuedeVerTodos = hasModulePrivilege($user, $module_name, 'reportany');

    if (is_null($extension)) {
        if ($bPuedeVerTodos)
            $smarty->assign("mb_message", "<b>"._tr("no_extension")."</b>");
        else{
            $smarty->assign("mb_message", "<b>"._tr("contact_admin")."</b>");
            return "";
        }
    }

    $bPuedeBorrar = hasModulePrivilege($user, $module_name, 'deleteany');

    $dsn_asterisk = generarDSNSistema('asteriskuser', 'asterisk');
    $pDB_asterisk = new paloDB($dsn_asterisk);
    $oRG          = new RingGroup($pDB_asterisk);
    $dataRG       = $oRG->getRingGroup();
    $oQueue       = new Queue($pDB_asterisk);
    $dataQueue    = $oQueue->getQueue();
    $dataRG       = $dataRG + $dataQueue;
    $dataRG['']  = _tr('(Any ringgroup)');

    $disableCel = false;
    $query      = "DESC asteriskcdrdb.cel";
    $result     = $pDB->genQuery($query);
    if ($result === false) {
        $disableCel=true;
    }

    $smarty->assign(array(
        "Filter"    =>  _tr("Filter"),
    ));

    $arrFormElements = array(
        "date_start"  => array(
                            "LABEL"                  => _tr("Start Date"),
                            "REQUIRED"               => "yes",
                            "INPUT_TYPE"             => "DATE",
                            "INPUT_EXTRA_PARAM"      => "",
                            "VALIDATION_TYPE"        => "ereg",
                            "VALIDATION_EXTRA_PARAM" => "^[[:digit:]]{1,2}[[:space:]]+[[:alnum:]]{3}[[:space:]]+[[:digit:]]{4}$"),
        "date_end"    => array(
                            "LABEL"                  => _tr("End Date"),
                            "REQUIRED"               => "yes",
                            "INPUT_TYPE"             => "DATE",
                            "INPUT_EXTRA_PARAM"      => "",
                            "VALIDATION_TYPE"        => "ereg",
                            "VALIDATION_EXTRA_PARAM" => "^[[:digit:]]{1,2}[[:space:]]+[[:alnum:]]{3}[[:space:]]+[[:digit:]]{4}$"),
        "field_name"  => array(
                            "LABEL"                  => _tr("Field Name"),
                            "REQUIRED"               => "no",
                            "INPUT_TYPE"             => "SELECT",
                            "INPUT_EXTRA_PARAM"      => array( "dst"         => _tr("Destination"),
                                                               "src"         => _tr("Source"),
                                                               "channel"     => _tr("Src. Channel"),
                                                               "accountcode" => _tr("Account Code"),
                                                               "dstchannel"  => _tr("Dst. Channel"),
                                                               "did"         => _tr("DID"),
                                                               "userfield"   => _tr("User Field")),
                            "VALIDATION_TYPE"        => "ereg",
                            "VALIDATION_EXTRA_PARAM" => "^(dst|src|channel|dstchannel|accountcode|userfield|did)$"),
        "field_pattern" => array(
                            "LABEL"                  => _tr("Field"),
                            "REQUIRED"               => "no",
                            "INPUT_TYPE"             => "TEXT",
                            "INPUT_EXTRA_PARAM"      => "",
                            "VALIDATION_TYPE"        => "ereg",
                            "VALIDATION_EXTRA_PARAM" => "^[\*|[:alnum:]@_\.,\/\-]+$"),
        "status"  => array(
                            "LABEL"                  => _tr("Status"),
                            "REQUIRED"               => "no",
                            "INPUT_TYPE"             => "SELECT",
                            "INPUT_EXTRA_PARAM"      => array(
                                                        "ALL"         => _tr("ALL"),
                                                        "ANSWERED"    => _tr("ANSWERED"),
                                                        "BUSY"        => _tr("BUSY"),
                                                        "FAILED"      => _tr("FAILED"),
                                                        "NO ANSWER"  => _tr("NO ANSWER")),
                            "VALIDATION_TYPE"        => "text",
                            "VALIDATION_EXTRA_PARAM" => ""),
        "ringgroup"  => array(
                            "LABEL"                  => _tr("Ring Group"),
                            "REQUIRED"               => "no",
                            "INPUT_TYPE"             => "SELECT",
                            "INPUT_EXTRA_PARAM"      => $dataRG ,
                            "VALIDATION_TYPE"        => "text",
                            "VALIDATION_EXTRA_PARAM" => ""),
         "queue"  => array(
                            "LABEL"                  => _tr("Queue"),
                            "REQUIRED"               => "no",
                            "INPUT_TYPE"             => "SELECT",
                            "INPUT_EXTRA_PARAM"      => $dataQueue ,
                            "VALIDATION_TYPE"        => "text",
                            "VALIDATION_EXTRA_PARAM" => ""),
        "limit"  => array(  
                            "LABEL"                  => _tr("Limit"),
                            "REQUIRED"               => "no",
                            "INPUT_TYPE"             => "SELECT",
                            "INPUT_EXTRA_PARAM"      => array(
                                                        "100000" => _tr("100.000"),
                                                        "50000"  => _tr("50.000"),
                                                        "20000"  => _tr("20.000"),
                                                        "10000"  => _tr("10.000"),
                                                        "1000"   => _tr("1.000")),
                            "VALIDATION_TYPE"        => "text",
                            "VALIDATION_EXTRA_PARAM" => ""),
        "timeInSecs"     => array( 
                            "LABEL"                  => _tr("Show time in Secs"),
                            "REQUIRED"               => "no",
                            "INPUT_TYPE"             => "CHECKBOX",
                            "INPUT_EXTRA_PARAM"      => "",
                            "VALIDATION_TYPE"        => "text",
                            "VALIDATION_EXTRA_PARAM" => "",
                            "EDITABLE"               => "yes"),
        );

    $oFilterForm = new paloForm($smarty, $arrFormElements);

    $url = array('menu' => $module_name);
    $paramFiltroBase = $paramFiltro = array(
        'date_start'    => date("d M Y"),
        'date_end'      => date("d M Y"),
        'field_name'    => 'dst',
        'field_pattern' => '',
        'status'        => 'ALL',
        'ringgroup'     =>  '',
        'limit'         => '100000',
        'timeInSecs'    => 'off',
    );
    foreach (array_keys($paramFiltro) as $k) {
        if (!is_null(getParameter($k))){
            $paramFiltro[$k] = getParameter($k);
        }
    }

    $oGrid  = new paloSantoGrid($smarty);

    if(isset($_REQUEST['loading'])) {
        $content="<html><body><div style='margin:auto; text-align:center'><img src='/modules/$module_name/images/loading.svg'></div>";
        return $content;
        die();
    }

    if(isset($_REQUEST['uniqueid'])) {
        $oGrid->setTitle(_tr("CDR Events"));
        $arrColumns =array('eventtime', 'eventtype', 'cid_name', 'cid_num', 'cid_dnid', 'exten', 'appname', 'uniqueid');
        $columnas = implode(",",$arrColumns);

        $sPeticionSQL = "SELECT linkedid FROM cel WHERE uniqueid=? LIMIT 1";
        $paramSQL=array($_REQUEST['uniqueid']);
        $arrData = $pDB->fetchTable($sPeticionSQL, FALSE, $paramSQL);
        $linkedId = isset($arrData[0][0]) ? $arrData[0][0] : $_REQUEST['uniqueid'];

        $sPeticionSQL = "SELECT $columnas FROM cel WHERE linkedid=?";
        $paramSQL = array($linkedId);

        $arrData = $pDB->fetchTable($sPeticionSQL, FALSE, $paramSQL);
        $oGrid->setColumns($arrColumns);
        $oGrid->setData($arrData);
        $content = $smarty->fetch("$local_templates_dir/cel.tpl");
        $content.= $oGrid->fetchGrid();
        return $content;
        die();
    }

    if($paramFiltro['date_start']==="") {
        $paramFiltro['date_start']  = " ";
    }

    if($paramFiltro['date_end']==="") {
        $paramFiltro['date_end']  = " ";
    }

    $valueFieldName = $arrFormElements['field_name']["INPUT_EXTRA_PARAM"][$paramFiltro['field_name']];
    $valueStatus    = $arrFormElements['status']["INPUT_EXTRA_PARAM"][$paramFiltro['status']];
    $valueRingGRoup = $arrFormElements['ringgroup']["INPUT_EXTRA_PARAM"][$paramFiltro['ringgroup']];

    if (!$oFilterForm->validateForm($paramFiltro)) {
        $smarty->assign(array(
            'mb_title'      =>  _tr('Validation Error'),
            'mb_message'    =>  '<b>'._tr('The following fields contain errors').':</b><br/>'.
                                implode(', ', array_keys($oFilterForm->arrErroresValidacion)),
        ));
        $paramFiltro = $paramFiltroBase;
        unset($_POST['delete']);
    }

    $url = array_merge($url, $paramFiltro);
    $paramFiltro['date_start'] = translateDate($paramFiltro['date_start']).' 00:00:00';
    $paramFiltro['date_end']   = translateDate($paramFiltro['date_end']).' 23:59:59';

    if (!$bPuedeVerTodos) $paramFiltro['extension'] = $extension;

    $arrData   = null;
    $limit     = $paramFiltro['limit'];
    $timeInSecs = $paramFiltro['timeInSecs'];
    $arrResult = $oCDR->listarCDRs($paramFiltro, $limit, 0, $filterLocalChannel);
    $total     = isset($arrResult['cdrs']) && is_array($arrResult['cdrs']) ? count($arrResult['cdrs']) : 0;

    $contactsMap = getExternalAddressBookContactsMap();
    $extNamesMap = getAsteriskExtensionNamesMap($pDB);

    $pageSrcList = array();
    if (is_array($arrResult['cdrs'])) {
        foreach ($arrResult['cdrs'] as $_row) {
            if (!empty($_row[1]) && $_row[1] != '-') $pageSrcList[] = $_row[1];
        }
    }
    $stats7dMap = getCdr7DaysStatsMap($pageSrcList, $pDB);

    if(is_array($arrResult['cdrs']) && $total>0) {
        foreach($arrResult['cdrs'] as $key => $value) {
            $arrTmp[0] = $value[0];
            $arrTmp[1] = renderCallerWithContactBadge($value[1], $value[1], $contactsMap, $stats7dMap, $extNamesMap);
            $arrTmp[2] = $value[11];
            $arrTmp[3] = $value[2];
            $arrTmp[4] = $value[3];
            $arrTmp[5] = $value[9];
            $arrTmp[6] = $value[4];

            if ($value[5] == "ANSWERED") {
               $value[5] = "<font color=green>"._tr($value[5])."</font>";
            }
            elseif ($value[5] == "NO ANSWER") {
               $value[5] = "<font color=red>"._tr($value[5])."</font>";
            }
            elseif ($value[5] == "BUSY") {
                $value[5] = "<font color=ambar>"._tr($value[5])."</font>";
            }
            elseif ($value[5] == "FAILED") {
                $value[5] = "<font color=red>"._tr($value[5])."</font>";
            }
            else {
                $value[5] = "<font color=red>$value[5]</font>";
            }

            $arrTmp[7] = $value[5];
            $iDuracion = $value[8];

            if ($timeInSecs == "on") {
                 $sTiempo = $iDuracion;
            } else {
                $iSec = $iDuracion % 60; $iDuracion = (int)(($iDuracion - $iSec) / 60);
                $iMin = $iDuracion % 60; $iDuracion = (int)(($iDuracion - $iMin) / 60);
                $sTiempo = "{$value[8]}s";
                if ($value[8] >= 60) {
                      if ($iDuracion > 0) $sTiempo = "{$iDuracion}h {$iMin}m {$iSec}s";
                      elseif ($iMin > 0)  $sTiempo = "{$iMin}m {$iSec}s";
                }
            }
            $arrTmp[8]  = $sTiempo;
            $arrTmp[9]  = $value[6];
            $arrTmp[10] = $value[17];
            $arrTmp[11] = renderDirectionAndTrunkBadge($value[1], $value[2], $value[16], $value[3], $value[4], $extNamesMap);

            if(!$disableCel) {
                $arrTmp[12] = '<a onclick="showCel(\'' . $value[6] . '\')" style="cursor:pointer;"> <span class="glyphicon glyphicon-list-alt" aria-hidden="true"></span> </a>';
            }
            
            $arrData[] = $arrTmp;
        }

        if (!is_array($arrResult)) {
            $smarty->assign(array(
                'mb_title'      =>  _tr('ERROR'),
                'mb_message'    =>  $oCDR->errMsg,
            ));
        }
    }
    $smarty->assign('modalClass','modal-lg');
    $smarty->assign('modalContent','<iframe id="celdetails" onLoad="celFrameLoaded();" src="index.php?menu='.$module_name.'&rawmode=yes&loading=yes" frameborder=0 width="100%" height="100px"></iframe>');

    $cel_code = "
        function showCel(uniqueid) {
            $('#celdetails').attr('src','index.php?menu=".$module_name."&rawmode=yes&uniqueid='+uniqueid);
            $('#gridModal').modal();
        }

        function celFrameLoaded() {
            fh = $('#celdetails').contents().find('html').height();
            if(fh==0) {fh=100;}
            $('#celdetails').height(fh);
            $('#gridModal').find('.modal-body').css({
              height: fh, 
            });
            $('.modal-dialog').css('top',$(window).scrollTop());
        }

        $('#gridModal').on('hidden.bs.modal', function () {
            $('#celdetails').attr('src','index.php?menu=".$module_name."&rawmode=yes&loading=yes');
        })
        $('#gridModal').on('shown.bs.modal', function () {
            $('#myInput').trigger('focus')
        })

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

            fetch('index.php?menu=" . $module_name . "&rawmode=yes', {
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
    ";

    $smarty->assign('customJS',$cel_code);

    $valueLimit = $arrFormElements['limit']["INPUT_EXTRA_PARAM"][$paramFiltro['limit']];
    if ($total == $paramFiltro['limit']) {
        $msgLimit =    '<font color=red>'.
                       '<span class="glyphicon glyphicon-info-sign" aria-hidden="true"></span>'." ".
                       _tr("Limit")." = ".$valueLimit.
                       '</font>';
    } else {
        $msgLimit =    '<font color=green>'.
                       '<span class="glyphicon glyphicon-info-sign" aria-hidden="true"></span>'." ".
                       _tr("Limit")." = ".$valueLimit.
                       '</font>';
    }

    $MsgFilter = "<b>"._tr("Filter applied: ")."</b>".
    '<span class="glyphicon glyphicon-calendar" aria-hidden="true"></span>'." ".
    _tr("Start Date")." = ".$paramFiltro['date_start'].", "._tr("End Date")." = ".
    $paramFiltro['date_end']." - ".
    '<span class="glyphicon glyphicon-phone-alt" aria-hidden="true"></span>'." ".
    $valueFieldName." = ".$paramFiltro['field_pattern']. " - ".
    '<span class="glyphicon glyphicon-tag" aria-hidden="true"></span>'." ".
    _tr("Status")." = ".$valueStatus." - ".
    '<span class="glyphicon glyphicon-list" aria-hidden="true"></span>'." ".
    _tr("Ring Group")." = ".$valueRingGRoup." - ".
    $msgLimit;

    $arrColumns = array(_tr("Date"), _tr("Source"), _tr("Ring Group"), _tr("Destination"), _tr("Src. Channel"),_tr("Account Code"),_tr("Dst. Channel"),_tr("Status"),_tr("Duration"),_tr("UniqueID"),_tr("Recording"), _tr("Cnum"),_tr("Cnam"), _tr("Outbound Cnum"), _tr("DID"), _tr("User Field"));
    $smarty->assign("SHOW",        _tr("Show"));
    $smarty->assign("DELMSG",      _tr("Are you sure you wish to delete CDR(s) Report(s)?"));
    $smarty->assign("COLUMNS",     $arrColumns);
    $smarty->assign("FILTER_SHOW", _tr("Show Filter"));
    $smarty->assign("FILTER_MSG",  $MsgFilter);
    $smarty->assign("Filter",      _tr("Filter"));
    $lang = get_language();
    $smarty->assign("LANG",$lang);
    $smarty->assign("module_name", $module_name);
    $smarty->assign($arrFormElements); 
    $smarty->assign("CDR", json_encode($arrData));
    $paramFiltro['date_start'] = date('d M Y', strtotime($paramFiltro['date_start']));
    $paramFiltro['date_end']   = date('d M Y', strtotime($paramFiltro['date_end']));
    $content = $oFilterForm->fetchForm("$local_templates_dir/filter.tpl", "", $paramFiltro);
    $content .= $smarty->fetch("$local_templates_dir/datatables.tpl");

    $modal_ab = "
    <!-- Modal Salvar na Agenda Pública -->
    <div id=\"addressBookModal\" style=\"display:none; position:fixed; top:0; left:0; width:100%; height:100%; background:rgba(15,23,42,0.65); backdrop-filter:blur(4px); z-index:2147483647; align-items:center; justify-content:center;\">
        <div style=\"background:#ffffff; border-radius:14px; padding:24px; width:440px; max-width:92%; box-shadow:0 25px 50px -12px rgba(0,0,0,0.35); text-align:left; border:1px solid #e2e8f0; font-family:'Segoe UI', system-ui, sans-serif;\">
            <div style=\"display:flex; justify-content:space-between; align-items:center; margin-bottom:14px; border-bottom:1px solid #f1f5f9; padding-bottom:10px;\">
                <h4 style=\"margin:0; font-size:16px; color:#0f172a; font-weight:800; display:flex; align-items:center; gap:8px;\">📇 Adicionar à Agenda Pública</h4>
                <button type=\"button\" onclick=\"closeAddressBookModal()\" style=\"background:none; border:none; color:#94a3b8; font-size:18px; cursor:pointer; font-weight:bold;\">✖</button>
            </div>
            <form onsubmit=\"submitSaveAddressBook(event)\">
                <div style=\"margin-bottom:10px;\">
                    <label style=\"display:block; font-size:11px; font-weight:700; color:#475569; margin-bottom:4px;\">Número / Telefone</label>
                    <input type=\"text\" id=\"ab_phone\" required readonly style=\"width:100%; box-sizing:border-box; background:#f8fafc; border:1px solid #cbd5e1; border-radius:6px; padding:8px 10px; font-weight:bold; color:#1e293b;\" />
                </div>
                <div style=\"margin-bottom:10px; display:flex; gap:10px;\">
                    <div style=\"flex:1;\">
                        <label style=\"display:block; font-size:11px; font-weight:700; color:#475569; margin-bottom:4px;\">Nome *</label>
                        <input type=\"text\" id=\"ab_name\" required placeholder=\"Nome\" style=\"width:100%; box-sizing:border-box; border:1px solid #cbd5e1; border-radius:6px; padding:8px 10px;\" />
                    </div>
                    <div style=\"flex:1;\">
                        <label style=\"display:block; font-size:11px; font-weight:700; color:#475569; margin-bottom:4px;\">Sobrenome</label>
                        <input type=\"text\" id=\"ab_last_name\" placeholder=\"Sobrenome\" style=\"width:100%; box-sizing:border-box; border:1px solid #cbd5e1; border-radius:6px; padding:8px 10px;\" />
                    </div>
                </div>
                <div style=\"margin-bottom:10px;\">
                    <label style=\"display:block; font-size:11px; font-weight:700; color:#475569; margin-bottom:4px;\">Empresa</label>
                    <input type=\"text\" id=\"ab_company\" placeholder=\"Nome da Empresa\" style=\"width:100%; box-sizing:border-box; border:1px solid #cbd5e1; border-radius:6px; padding:8px 10px;\" />
                </div>
                <div style=\"margin-bottom:10px;\">
                    <label style=\"display:block; font-size:11px; font-weight:700; color:#475569; margin-bottom:4px;\">E-mail</label>
                    <input type=\"email\" id=\"ab_email\" placeholder=\"contato@empresa.com\" style=\"width:100%; box-sizing:border-box; border:1px solid #cbd5e1; border-radius:6px; padding:8px 10px;\" />
                </div>
                <div style=\"margin-bottom:14px;\">
                    <label style=\"display:block; font-size:11px; font-weight:700; color:#475569; margin-bottom:4px;\">Observações</label>
                    <textarea id=\"ab_notes\" rows=\"2\" placeholder=\"Anotações do contato...\" style=\"width:100%; box-sizing:border-box; border:1px solid #cbd5e1; border-radius:6px; padding:8px 10px; font-size:12px;\"></textarea>
                </div>
                <div style=\"display:flex; justify-content:flex-end; gap:8px;\">
                    <button type=\"button\" onclick=\"closeAddressBookModal()\" style=\"background:#f1f5f9; color:#475569; border:1px solid #cbd5e1; padding:8px 14px; border-radius:6px; font-weight:700; cursor:pointer;\">Cancelar</button>
                    <button type=\"submit\" id=\"btnSaveAb\" style=\"background:#2563eb; color:#fff; border:none; padding:8px 18px; border-radius:6px; font-weight:700; cursor:pointer;\">💾 Salvar Contato</button>
                </div>
            </form>
        </div>
    </div>
    ";

    return $content . $modal_ab;
}

function hasModulePrivilege($user, $module, $privilege)
{
    global $arrConf;
    $pDB = new paloDB($arrConf['issabel_dsn']['acl']);
    $pACL = new paloACL($pDB);
    if (method_exists($pACL, 'hasModulePrivilege'))
        return $pACL->hasModulePrivilege($user, $module, $privilege);

    $isAdmin = ($pACL->isUserAdministratorGroup($user) !== FALSE);
    return ($isAdmin && in_array($privilege, array('reportany', 'deleteany')));
}
?>
