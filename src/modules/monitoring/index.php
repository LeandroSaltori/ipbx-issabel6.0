<?php
  /* vim: set expandtab tabstop=4 softtabstop=4 shiftwidth=4:
  Codificación: UTF-8
  +----------------------------------------------------------------------+
  | Issabel version 4.0.0-18                                               |
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
  $Id: index.php,v 1.3 2007/09/05 00:26:21 gcarrillo Exp $
  $Id: index.php,v 1.3 2008/04/14 09:22:21 afigueroa Exp $
  $Id: index.php,v 2.0 2010/02/03 09:00:00 onavarre Exp $
  $Id: index.php,v 2.1 2010-03-22 05:03:48 Eduardo Cueva ecueva@palosanto.com Exp $ */
//include issabel framework

// exten => s,n,Set(CDR(userfield)=audio:${CALLFILENAME}.${MIXMON_FORMAT})   extensions_additional
require_once "libs/paloSantoACL.class.php";


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
    require_once "modules/$module_name/configs/default.conf.php";
    require_once "modules/$module_name/libs/paloSantoMonitoring.class.php";

    $base_dir=dirname($_SERVER['SCRIPT_FILENAME']);

    load_language_module($module_name);

    //global variables
    global $arrConf;
    global $arrConfModule;
    $arrConf = array_merge($arrConf,$arrConfModule);

    //folder path for custom templates
    $templates_dir=(isset($arrConf['templates_dir']))?$arrConf['templates_dir']:'themes';
    $local_templates_dir="$base_dir/modules/$module_name/".$templates_dir.'/'.$arrConf['theme'];

    //conexion resource
    $arrConf['dsn_conn_database'] = generarDSNSistema('asteriskuser', 'asteriskcdrdb');
    $pDB = new paloDB($arrConf['dsn_conn_database']);
    $pDBACL = new paloDB($arrConf['issabel_dsn']['acl']);
    $pACL = new paloACL($pDBACL);
    $user = isset($_SESSION['issabel_user'])?$_SESSION['issabel_user']:"";
    $extension = $pACL->getUserExtension($user);
    if ($extension == '') $extension = NULL;

    // Sólo el administrador puede consultar con $extension == NULL
    if (is_null($extension)) {
        if (hasModulePrivilege($user, $module_name, 'reportany'))
            $smarty->assign("mb_message", "<b>"._tr("no_extension")."</b>");
        else{
            $smarty->assign("mb_message", "<b>"._tr("contact_admin")."</b>");
            return "";
        }
    }

    if (getParameter('action') == 'save_address_book' || (isset($_REQUEST['action']) && $_REQUEST['action'] == 'save_address_book')) {
        handleSaveAddressBook($arrConf);
        exit;
    }

    switch (getParameter('action')) {
    case 'download':
        $h = 'downloadFile';
        break;
    case 'display_record':
    case 'stream_audio':
        $h = 'streamAudioFile';
        break;
    default:
        $h = 'reportMonitoring';
        break;
    }
    return $h($smarty, $module_name, $local_templates_dir, $pDB, $pACL, $arrConf, $user, $extension);
}

function reportMonitoring($smarty, $module_name, $local_templates_dir, &$pDB, $pACL, $arrConf, $user, $extension)
{
    return renderFullMonitoringDashboard($smarty, $module_name, $local_templates_dir, $pDB, $pACL, $arrConf, $user, $extension);
}

function renderFullMonitoringDashboard($smarty, $module_name, $local_templates_dir, &$pDB, $pACL, $arrConf, $user, $extension)
{
    $bPuedeVerTodos = hasModulePrivilege($user, $module_name, 'reportany');
    $bPuedeBorrar   = hasModulePrivilege($user, $module_name, 'deleteany');
    $pMonitoring    = new paloSantoMonitoring($pDB);

    // Process Deletion if requested
    if ($bPuedeBorrar && isset($_POST['action_type']) && $_POST['action_type'] == 'delete_records' && !empty($_POST['selected_uniqueids'])) {
        $arrIds = is_array($_POST['selected_uniqueids']) ? $_POST['selected_uniqueids'] : $_POST['selected_uniqueids'];
        deleteRecord($smarty, $module_name, $local_templates_dir, $pDB, $pACL, $arrConf, $user, $extension, $arrIds);
    }

    // Filter Parameters
    $date_start_raw = isset($_REQUEST['date_start']) ? trim($_REQUEST['date_start']) : date("Y-m-d");
    $date_end_raw   = isset($_REQUEST['date_end']) ? trim($_REQUEST['date_end']) : date("Y-m-d");

    $date_start_ts = strtotime($date_start_raw);
    $date_end_ts   = strtotime($date_end_raw);
    $date_start    = $date_start_ts ? date("Y-m-d", $date_start_ts) : date("Y-m-d");
    $date_end      = $date_end_ts ? date("Y-m-d", $date_end_ts) : date("Y-m-d");

    // Load Queue and RingGroup definitions for names mapping
    $dsn_asterisk = generarDSNSistema('asteriskuser', 'asterisk');
    $pDB_asterisk = new paloDB($dsn_asterisk);
    $dataRG       = array();
    $dataQueue    = array();

    if (file_exists("modules/cdrreport/libs/ringgroup.php")) {
        require_once "modules/cdrreport/libs/ringgroup.php";
        if (class_exists('RingGroup')) {
            $oRG = new RingGroup($pDB_asterisk);
            $resRG = $oRG->getRingGroup();
            if (is_array($resRG)) $dataRG = $resRG;
        }
    }
    if (file_exists("modules/cdrreport/libs/queues.php")) {
        require_once "modules/cdrreport/libs/queues.php";
        if (class_exists('Queue')) {
            $oQueue = new Queue($pDB_asterisk);
            $resQ = $oQueue->getQueue();
            if (is_array($resQ)) $dataQueue = $resQ;
        }
    }
    $groupsMap = $dataRG + $dataQueue;

    $filter_field  = isset($_REQUEST['filter_field']) ? trim($_REQUEST['filter_field']) : 'src';
    $filter_value  = isset($_REQUEST['filter_value']) ? trim($_REQUEST['filter_value']) : '';
    $rec_type      = isset($_REQUEST['rec_type']) ? trim($_REQUEST['rec_type']) : 'ALL';
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

    $page          = isset($_REQUEST['page']) ? max(1, (int)$_REQUEST['page']) : 1;
    $limit         = isset($_REQUEST['limit']) ? (int)$_REQUEST['limit'] : 20;
    if ($limit <= 0) $limit = 20;

    $param = array(
        'date_start' => $date_start . ' 00:00:00',
        'date_end'   => $date_end . ' 23:59:59',
    );
    if (!$bPuedeVerTodos) $param['extension'] = $extension;
    if (!empty($filter_field) && !empty($filter_value)) {
        $param[$filter_field] = $filter_value;
    }
    if ($rec_type != 'ALL' && !empty($rec_type)) {
        $param['recordingfile'] = $rec_type;
    }

    // Query All records for KPI stats
    $arrResultAll = $pMonitoring->getMonitoring($param, 100000, 0);
    $rawList      = is_array($arrResultAll) ? $arrResultAll : array();

    // Helper to check if a call is internal (Ramal para Ramal)
    $fnIsInternal = function($src, $dst) {
        $s = preg_replace('/\D/', '', (string)$src);
        $d = preg_replace('/\D/', '', (string)$dst);
        return (!empty($s) && strlen($s) <= 5 && !empty($d) && strlen($d) <= 5);
    };

    // Filter by Scope (Internas / Externas), Min Duration, & Only Recorded
    $filteredList = array();
    foreach ($rawList as $r) {
        $srcNum = !empty($r['cnum']) ? $r['cnum'] : $r['src'];
        $dstNum = !empty($r['dst']) ? $r['dst'] : '';
        $isInternal = $fnIsInternal($srcNum, $dstNum);

        if ($call_scope == 'internal' && !$isInternal) {
            continue;
        }
        if ($call_scope == 'external' && $isInternal) {
            continue;
        }

        $durSecs = (int)$r['duration'];
        if ($min_duration > 0 && $durSecs < $min_duration) {
            continue;
        }

        if ($only_recorded == 1) {
            $fname = basename($r['recordingfile']);
            if ($fname == 'deleted' || empty($fname)) {
                continue;
            }
            $recinfo = $pMonitoring->resolveRecordingPath($r['recordingfile']);
            if (is_null($recinfo['fullpath']) || !file_exists($recinfo['fullpath']) || filesize($recinfo['fullpath']) <= 44) {
                continue;
            }
        }

        $filteredList[] = $r;
    }

    $totalCount   = count($filteredList);

    // KPI Stat Counters
    $incCount = 0;
    $outCount = 0;
    $queueCount = 0;
    $groupCount = 0;

    foreach ($filteredList as $r) {
        $fname = basename($r['recordingfile']);
        if ($fname != 'deleted' && !empty($fname)) {
            $char = strtolower($fname[0]);
            if ($char == 'o') $outCount++;
            elseif ($char == 'q') $queueCount++;
            elseif ($char == 'g' || $char == 'r') $groupCount++;
            else $incCount++;
        }
    }

    $offset = ($page - 1) * $limit;
    $pageList = array_slice($filteredList, $offset, $limit);
    $totalPages = max(1, ceil($totalCount / $limit));

    ob_start();
    ?>
    <!DOCTYPE html>
    <html lang="pt-BR">
    <head>
        <meta charset="UTF-8">
        <style>
            .monitoring-root {
                font-family: 'Segoe UI', system-ui, -apple-system, sans-serif;
                background: #f8fafc;
                padding: 18px;
                border-radius: 12px;
                color: #1e293b;
            }
            .monitoring-header {
                display: flex;
                justify-content: space-between;
                align-items: center;
                margin-bottom: 18px;
                background: #ffffff;
                padding: 16px 20px;
                border-radius: 12px;
                box-shadow: 0 1px 3px rgba(0,0,0,0.05);
                border: 1px solid #e2e8f0;
            }
            .monitoring-title h2 {
                margin: 0;
                font-size: 20px;
                font-weight: 800;
                color: #0f172a;
                letter-spacing: -0.5px;
            }
            .monitoring-title p {
                margin: 4px 0 0 0;
                font-size: 12px;
                color: #64748b;
            }
            .monitoring-top-btns {
                display: flex;
                gap: 8px;
            }
            .btn-top {
                padding: 7px 14px;
                border-radius: 8px;
                font-size: 12px;
                font-weight: 700;
                text-decoration: none;
                cursor: pointer;
                border: none;
                transition: all 0.2s;
                display: inline-flex;
                align-items: center;
                gap: 6px;
            }
            .btn-top-manual { background: #f1f5f9; color: #475569; border: 1px solid #cbd5e1; }
            .btn-top-manual:hover { background: #e2e8f0; color: #0f172a; }
            .btn-top-expand { background: #7c3aed; color: #ffffff; }
            .btn-top-expand:hover { background: #6d28d9; }

            .kpi-grid {
                display: grid;
                grid-template-columns: repeat(4, 1fr);
                gap: 15px;
                margin-bottom: 18px;
            }
            @media (max-width: 900px) { .kpi-grid { grid-template-columns: repeat(2, 1fr); } }

            .kpi-card-box {
                background: #ffffff;
                border-radius: 12px;
                padding: 16px 20px;
                box-shadow: 0 1px 3px rgba(0,0,0,0.04);
                border: 1px solid #e2e8f0;
                display: flex;
                align-items: center;
                gap: 15px;
                transition: transform 0.2s, box-shadow 0.2s;
            }
            .kpi-card-box:hover {
                transform: translateY(-2px);
                box-shadow: 0 4px 12px rgba(0,0,0,0.06);
            }
            .kpi-icon-circle {
                width: 46px;
                height: 46px;
                border-radius: 12px;
                display: flex;
                align-items: center;
                justify-content: center;
                font-size: 20px;
                flex-shrink: 0;
            }
            .kpi-icon-total { background: #ede9fe; color: #7c3aed; }
            .kpi-icon-inc { background: #dcfce7; color: #16a34a; }
            .kpi-icon-out { background: #dbeafe; color: #2563eb; }
            .kpi-icon-queue { background: #fef3c7; color: #d97706; }

            .kpi-card-info { flex: 1; }
            .kpi-card-title { font-size: 11px; font-weight: 700; color: #64748b; text-transform: uppercase; letter-spacing: 0.5px; }
            .kpi-card-val { font-size: 22px; font-weight: 800; color: #0f172a; line-height: 1.2; }
            .kpi-card-desc { font-size: 11px; color: #94a3b8; margin-top: 2px; }

            .filter-card-box {
                background: #ffffff;
                border-radius: 12px;
                padding: 16px 20px;
                box-shadow: 0 1px 3px rgba(0,0,0,0.04);
                border: 1px solid #e2e8f0;
                margin-bottom: 18px;
            }
            .filter-inline-row {
                display: flex;
                flex-wrap: wrap;
                gap: 12px;
                align-items: flex-end;
            }
            .filter-field-group {
                display: flex;
                flex-direction: column;
                gap: 4px;
                flex: 1;
                min-width: 140px;
            }
            .filter-field-group label {
                font-size: 11px;
                font-weight: 700;
                color: #475569;
            }
            .filter-input {
                padding: 7px 10px;
                border-radius: 8px;
                border: 1px solid #cbd5e1;
                font-size: 12px;
                color: #1e293b;
                outline: none;
                background: #ffffff;
                transition: border-color 0.2s;
            }
            .filter-input:focus { border-color: #7c3aed; }

            .filter-btn-row {
                display: flex;
                gap: 8px;
                align-items: center;
            }
            .btn-action {
                padding: 8px 16px;
                border-radius: 8px;
                font-size: 12px;
                font-weight: 700;
                border: none;
                cursor: pointer;
                transition: all 0.2s;
                display: inline-flex;
                align-items: center;
                gap: 6px;
            }
            .btn-search {
                background: linear-gradient(135deg, #7c3aed, #6d28d9);
                color: #ffffff;
                box-shadow: 0 2px 6px rgba(124,58,237,0.3);
            }
            .btn-search:hover { background: linear-gradient(135deg, #6d28d9, #5b21b6); }

            .btn-delete-sel {
                background: #ef4444;
                color: #ffffff;
            }
            .btn-delete-sel:hover { background: #dc2626; }

            .table-card-box {
                background: #ffffff;
                border-radius: 12px;
                box-shadow: 0 1px 3px rgba(0,0,0,0.04);
                border: 1px solid #e2e8f0;
                overflow: hidden;
            }
            .monitoring-table {
                width: 100%;
                border-collapse: collapse;
                text-align: left;
            }
            .monitoring-table thead {
                background: #334155;
                color: #ffffff;
            }
            .monitoring-table th {
                padding: 10px 14px;
                font-size: 10px;
                font-weight: 800;
                text-transform: uppercase;
                letter-spacing: 0.5px;
            }
            .monitoring-table td {
                padding: 10px 14px;
                border-bottom: 1px solid #f1f5f9;
                font-size: 12px;
                vertical-align: middle;
            }
            .monitoring-table tbody tr:hover { background: #f8fafc; }

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
    <body>
        <form id="monitoringFormMain" method="POST" action="index.php?menu=<?php echo htmlspecialchars($module_name); ?>">
            <input type="hidden" name="action_type" id="action_type" value="" />
            <input type="hidden" name="filter_applied" value="1" />

            <div class="monitoring-root">
                <!-- Header Principal -->
                <div class="monitoring-header">
                    <div class="monitoring-title">
                        <h2>Relatório de Gravações de Chamadas - IPbx Prisma</h2>
                        <p>Consulta, reprodução e gerenciamento de áudios de chamadas gravadas no sistema</p>
                    </div>
                    <div class="monitoring-top-btns">
                        <a href="modules/monitoring/help/index.html" target="_blank" class="btn-top btn-top-manual">📖 Manual</a>
                        <button type="button" onclick="window.open('?menu=<?php echo htmlspecialchars($module_name); ?>&rawmode=yes', '_blank')" class="btn-top btn-top-expand">↗ Expandir Aba</button>
                    </div>
                </div>

                <!-- Cards KPI Topo -->
                <div class="kpi-grid">
                    <div class="kpi-card-box">
                        <div class="kpi-icon-circle kpi-icon-total">🎙️</div>
                        <div class="kpi-card-info">
                            <div class="kpi-card-title">Total Gravações</div>
                            <div class="kpi-card-val"><?php echo number_format($totalCount, 0, ',', '.'); ?></div>
                            <div class="kpi-card-desc">No período selecionado</div>
                        </div>
                    </div>
                    <div class="kpi-card-box">
                        <div class="kpi-icon-circle kpi-icon-inc">⬇️</div>
                        <div class="kpi-card-info">
                            <div class="kpi-card-title">Entrada</div>
                            <div class="kpi-card-val"><?php echo number_format($incCount, 0, ',', '.'); ?></div>
                            <div class="kpi-card-desc">Gravações receptivas</div>
                        </div>
                    </div>
                    <div class="kpi-card-box">
                        <div class="kpi-icon-circle kpi-icon-out">⬆️</div>
                        <div class="kpi-card-info">
                            <div class="kpi-card-title">Saída</div>
                            <div class="kpi-card-val"><?php echo number_format($outCount, 0, ',', '.'); ?></div>
                            <div class="kpi-card-desc">Gravações ativas</div>
                        </div>
                    </div>
                    <div class="kpi-card-box">
                        <div class="kpi-icon-circle kpi-icon-queue">👥</div>
                        <div class="kpi-card-info">
                            <div class="kpi-card-title">Fila / Grupo</div>
                            <div class="kpi-card-val"><?php echo number_format($queueCount + $groupCount, 0, ',', '.'); ?></div>
                            <div class="kpi-card-desc">Atendimento de grupo</div>
                        </div>
                    </div>
                </div>

                <!-- Card de Filtros Compacto -->
                <div class="filter-card-box">
                    <div class="filter-inline-row">
                        <div class="filter-field-group">
                            <label>📅 Data Inicial</label>
                            <input type="date" name="date_start" value="<?php echo htmlspecialchars($date_start); ?>" class="filter-input" />
                        </div>
                        <div class="filter-field-group">
                            <label>📅 Data Final</label>
                            <input type="date" name="date_end" value="<?php echo htmlspecialchars($date_end); ?>" class="filter-input" />
                        </div>
                        <div class="filter-field-group">
                            <label>📌 Campo Busca</label>
                            <select name="filter_field" class="filter-input">
                                <option value="src" <?php if ($filter_field == 'src') echo 'selected'; ?>>Origem (src / ramal)</option>
                                <option value="dst" <?php if ($filter_field == 'dst') echo 'selected'; ?>>Destino (dst / número)</option>
                            </select>
                        </div>
                        <div class="filter-field-group">
                            <label>📞 Padrão / Número</label>
                            <input type="text" name="filter_value" value="<?php echo htmlspecialchars($filter_value); ?>" placeholder="Ex: 5001 ou 99988..." class="filter-input" />
                        </div>
                        <div class="filter-field-group">
                            <label>🏷️ Tipo Gravação</label>
                            <select name="rec_type" class="filter-input" onchange="this.form.submit()">
                                <option value="ALL" <?php if ($rec_type == 'ALL') echo 'selected'; ?>>-- Todos os Tipos --</option>
                                <option value="incoming" <?php if ($rec_type == 'incoming') echo 'selected'; ?>>⬇️ Entrada (Incoming)</option>
                                <option value="outgoing" <?php if ($rec_type == 'outgoing') echo 'selected'; ?>>⬆️ Saída (Outgoing)</option>
                                <option value="queue" <?php if ($rec_type == 'queue') echo 'selected'; ?>>👥 Fila (Queue)</option>
                                <option value="group" <?php if ($rec_type == 'group') echo 'selected'; ?>>👤+ Grupo (Group)</option>
                            </select>
                        </div>
                        <div class="filter-field-group">
                            <label>🌐 Escopo Chamada</label>
                            <select name="call_scope" class="filter-input" onchange="this.form.submit()">
                                <option value="ALL" <?php if ($call_scope == 'ALL') echo 'selected'; ?>>-- Todas (Internas/Externas) --</option>
                                <option value="external" <?php if ($call_scope == 'external') echo 'selected'; ?>>🌐 Apenas Externas (PSTN/DID)</option>
                                <option value="internal" <?php if ($call_scope == 'internal') echo 'selected'; ?>>🏢 Apenas Internas (Ramal-Ramal)</option>
                            </select>
                        </div>
                        <div class="filter-field-group" title="⏱️ Duração Mínima&#10;Filtre chamadas que duraram pelo menos a quantidade de tempo selecionada.">
                            <label>⏱️ Duração Mínima</label>
                            <select name="min_duration" id="sel_min_duration_mon" class="filter-input" onchange="if (this.value > 0) { document.getElementById('chk_hide_zero_mon').checked = true; } else { document.getElementById('chk_hide_zero_mon').checked = false; } this.form.submit();">
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
                        <div class="filter-field-group">
                            <label>📄 Por Página</label>
                            <select name="limit" class="filter-input" onchange="this.form.submit()">
                                <option value="20" <?php if ($limit == 20) echo 'selected'; ?>>20 por pág</option>
                                <option value="50" <?php if ($limit == 50) echo 'selected'; ?>>50 por pág</option>
                                <option value="100" <?php if ($limit == 100) echo 'selected'; ?>>100 por pág</option>
                                <option value="1000" <?php if ($limit == 1000) echo 'selected'; ?>>1.000 (Geral)</option>
                            </select>
                        </div>
                        <div class="filter-field-group" style="align-self:flex-end; padding-bottom:6px;">
                            <label style="cursor:pointer; display:flex; align-items:center; gap:6px; font-weight:700; color:#ef4444; font-size:12px; margin:0;" title="Ocultar do relatório todas as ligações que registraram 0 segundos">
                                <input type="checkbox" id="chk_hide_zero_mon" name="hide_zero" value="1" <?php if ($hide_zero == 1) echo 'checked'; ?> onchange="if (!this.checked) { document.getElementById('sel_min_duration_mon').value = '0'; } else { document.getElementById('sel_min_duration_mon').value = '1'; } this.form.submit();" style="width:16px; height:16px; cursor:pointer;" />
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
                            <button type="submit" class="btn-action btn-search">🔍 Filtrar</button>
                            <?php if ($bPuedeBorrar): ?>
                                <button type="button" onclick="submitDeleteMonitoring()" class="btn-action btn-delete-sel">🗑️ Excluir Selecionadas</button>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>

                <!-- Tabela de Gravações -->
                <div class="table-card-box">
                    <table class="monitoring-table">
                        <thead>
                            <tr>
                                <?php if ($bPuedeBorrar): ?>
                                    <th style="width:30px; text-align:center;"><input type="checkbox" onclick="toggleSelectAllMonitoring(this)" /></th>
                                <?php endif; ?>
                                
                                <th>Data / Hora</th>
                                <th>Origem</th>
                                <th>Destino</th>
                                <th>Duração</th>
                                <th>Tipo</th>
                                <th>Gravação / Ações</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (is_array($pageList) && count($pageList) > 0): ?>
                                <?php
                                $pageSrcList = array();
                                foreach ($pageList as $_row) {
                                    $_s = !empty($_row['cnum']) ? $_row['cnum'] : (!empty($_row['src']) ? $_row['src'] : '');
                                    if (!empty($_s) && $_s != '-') $pageSrcList[] = $_s;
                                }
                                $abContactsMap = getAddressBookContactsMap();
                                $stats7dMap = getCdr7DaysStatsMap($pageSrcList, $pDB);
                        $extNamesMap = getAsteriskExtensionNamesMap($pDB);
                                ?>
                                <?php foreach ($pageList as $value): ?>
                                    <?php
                                    $uniqueId  = $value['uniqueid'];
                                    $calldate  = date('d/m/Y H:i:s', strtotime($value['calldate']));
                                    $src       = !empty($value['cnum']) ? $value['cnum'] : $value['src'];
                                    $dst       = !empty($value['dst']) ? $value['dst'] : '-';
                                    $durSecs   = (int)$value['duration'];
                                    $durText   = SecToHHMMSS($durSecs);

                                    // Type Badge
                                    $fname = basename($value['recordingfile']);
                                    $recTypeTag = '';
                                    if ($fname == 'deleted') {
                                        $recTypeTag = '<span style="background: rgba(239,68,68,0.15); color: #ef4444; border: 1px solid rgba(239,68,68,0.4); border-radius: 12px; padding: 4px 10px; font-weight: 600; font-size: 11px;">🗑️ Excluída</span>';
                                    } else {
                                        $char = strtolower($fname[0]);
                                        if ($char == 'o') {
                                            $recTypeTag = '<span style="background: rgba(59,130,246,0.15); color: #2563eb; border: 1px solid rgba(96,165,250,0.4); border-radius: 12px; padding: 4px 10px; font-weight: 600; font-size: 11px; display: inline-flex; align-items: center; gap: 4px;"><i class="fa fa-arrow-up"></i> Saída</span>';
                                        } elseif ($char == 'q') {
                                            $recTypeTag = '<span style="background: rgba(168,85,247,0.15); color: #7c3aed; border: 1px solid rgba(192,132,252,0.4); border-radius: 12px; padding: 4px 10px; font-weight: 600; font-size: 11px; display: inline-flex; align-items: center; gap: 4px;"><i class="fa fa-users"></i> Fila</span>';
                                        } elseif ($char == 'g' || $char == 'r') {
                                            $recTypeTag = '<span style="background: rgba(234,179,8,0.15); color: #d97706; border: 1px solid rgba(253,224,71,0.4); border-radius: 12px; padding: 4px 10px; font-weight: 600; font-size: 11px; display: inline-flex; align-items: center; gap: 4px;"><i class="fa fa-user-plus"></i> Grupo</span>';
                                        } else {
                                            $recTypeTag = '<span style="background: rgba(34,197,94,0.15); color: #16a34a; border: 1px solid rgba(74,222,128,0.4); border-radius: 12px; padding: 4px 10px; font-weight: 600; font-size: 11px; display: inline-flex; align-items: center; gap: 4px;"><i class="fa fa-arrow-down"></i> Entrada</span>';
                                        }
                                    }

                                    // Action buttons / Audio check
                                    $recinfo = $pMonitoring->resolveRecordingPath($value['recordingfile']);
                                    $actionHtml = '';
                                    if ($fname == 'deleted') {
                                        $actionHtml = '<span style="color:#ef4444; font-size:11px; font-weight:600;">Excluída</span>';
                                    } elseif (is_null($recinfo['fullpath']) || !file_exists($recinfo['fullpath']) || filesize($recinfo['fullpath']) <= 44) {
                                        $actionHtml = '<span title="Gravação de chamadas desativada para este ramal nas configurações do PBX" style="color: #94a3b8; font-size: 11px; background: rgba(148,163,184,0.15); border: 1px solid rgba(148,163,184,0.3); border-radius: 12px; padding: 4px 10px; font-weight: 500; display: inline-flex; align-items: center; gap: 4px;"><i class="fa fa-microphone-slash"></i> Ramal sem gravação</span>';
                                    } else {
                                        $urlparams = array(
                                            'menu'     => $module_name,
                                            'action'   => 'download',
                                            'id'       => $uniqueId,
                                            'namefile' => $fname,
                                            'rawmode'  => 'yes',
                                        );
                                        $downloadUrl = 'index.php?' . http_build_query($urlparams);
                                        $urlparamsStream = $urlparams;
                                        $urlparamsStream['action'] = 'stream_audio';
                                        $streamUrl = 'index.php?' . http_build_query($urlparamsStream);

                                        $actionHtml = "<div style='display:inline-flex; gap:6px; align-items:center;'>".
                                            "<button type='button' onclick=\"playMonitoringAudio('".htmlspecialchars($streamUrl, ENT_QUOTES)."', '".htmlspecialchars($src, ENT_QUOTES)."', '".htmlspecialchars($dst, ENT_QUOTES)."', '".htmlspecialchars($downloadUrl, ENT_QUOTES)."')\" style='background: linear-gradient(135deg, #7c3aed, #6d28d9); color: #ffffff; border: none; border-radius: 20px; padding: 4px 12px; font-weight: 700; font-size: 11px; cursor: pointer; box-shadow: 0 2px 6px rgba(124,58,237,0.3); transition: all 0.2s;'>▶ Ouvir</button>".
                                            "<a href='".htmlspecialchars($downloadUrl, ENT_QUOTES)."' target='_blank' style='background: rgba(255,255,255,0.08); color: #6d28d9; border: 1px solid rgba(124,58,237,0.4); border-radius: 20px; padding: 3px 10px; font-weight: 600; font-size: 10px; text-decoration: none; transition: all 0.2s;'>⬇️ Baixar</a>".
                                            "</div>";
                                    }
                                    ?>
                                    <tr>
                                        <?php if ($bPuedeBorrar): ?>
                                            <td style="text-align:center;">
                                                <input type="checkbox" name="selected_uniqueids[]" value="<?php echo htmlspecialchars($uniqueId); ?>" class="chk-mon-row" />
                                            </td>
                                        <?php endif; ?>
                                        <?php
                                        if (!empty($dst) && isset($groupsMap[$dst])) {
                                            $dstHtml = "<span title='🏢 Fila: " . htmlspecialchars($dst . " - " . $groupsMap[$dst], ENT_QUOTES) . "' style='background:#ede9fe; color:#6d28d9; padding:3px 8px; border-radius:6px; font-weight:700; font-size:11px; cursor:help; border:1px solid #ddd6fe; display:inline-flex; align-items:center; gap:4px;'><i class='fa fa-users'></i> " . htmlspecialchars($dst) . "</span>";
                                        } else {
                                            $dstHtml = "<span style='background:#ede9fe; color:#6d28d9; padding:3px 8px; border-radius:6px; font-weight:600; font-size:11px;'>🎯 " . htmlspecialchars($dst) . "</span>";
                                        }
                                        ?>
                                        
                                        <td><span style="color:#334155; font-size:11px; font-weight:600;">📅 <?php echo htmlspecialchars($calldate); ?></span></td>
                                        <td>
                                            <?php echo renderCallerWithContactBadge($src, $src, $abContactsMap, $stats7dMap, $extNamesMap); ?>
                                        </td>
                                        <td><?php echo $dstHtml; ?></td>
                                        <td><span style="color:#0f172a; font-weight:700; font-size:11px;">⏱️ <?php echo htmlspecialchars($durText); ?></span></td>
                                        <td><?php echo $recTypeTag; ?></td>
                                        <td><?php echo $actionHtml; ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <tr>
                                    <td colspan="<?php echo $bPuedeBorrar ? 7 : 6; ?>" style="text-align:center; padding:30px; color:#64748b;">
                                        🎙️ Nenhuma gravação de chamada encontrada para os filtros selecionados.
                                    </td>
                                </tr>
                            <?php endif; ?>
                        </tbody>
                    </table>

                    <!-- Barra de Paginação Executiva -->
                    <div class="pagination-bar">
                        <div class="pagination-info">
                            Exibindo <?php echo count($pageList); ?> de <?php echo number_format($totalCount, 0, ',', '.'); ?> gravações (Página <?php echo $page; ?> de <?php echo $totalPages; ?>)
                        </div>
                        <div class="pagination-btns">
                            <?php
                            $navParams = array(
                                'menu'         => $module_name,
                                'date_start'   => $date_start,
                                'date_end'     => $date_end,
                                'filter_field' => $filter_field,
                                'filter_value' => $filter_value,
                                'rec_type'     => $rec_type,
                                'call_scope'   => $call_scope,
                                'only_recorded'=> $only_recorded,
                                'min_duration' => $min_duration,
                                'hide_zero'    => $hide_zero,
                                'limit'        => $limit
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
        </form>

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
    </body>
    </html>
    <?php
    return ob_get_clean();
}

function formatCallRecordingTuple($value)
{
    $namefile = basename($value['recordingfile']);
    if ($namefile == 'deleted') {
        $rectype = _tr('Deleted');
    } else switch($namefile[0]){
        case 'O':  // FreePBX 2.8.1
        case 'o':  // FreePBX 2.11+
            $rectype = _tr("Outgoing");
            break;
        case 'g':  // FreePBX 2.8.1
        case 'r':  // FreePBX 2.11+
            $rectype = _tr("Group");
            break;
        case "q":
            $rectype = _tr("Queue");
            break;
        default :
            $rectype = _tr("Incoming");
            break;
    }

    // Prefer cnum to src if they differ, to show original extension instead of external cidnum
    $src       = isset($value['src']) ? $value['src'] : '';
    $cnum      = isset($value['cnum']) ? $value['cnum'] : '';
    $final_src = $src;
    if(($cnum != $src) && ($cnum != "")) {
        $final_src = $cnum;
    }

    return array(
        date('d/m/Y',strtotime($value['calldate'])),
        date('H:i:s',strtotime($value['calldate'])),
        $final_src,
        isset($value['dst']) ? $value['dst'] : '',
        SecToHHMMSS($value['duration']),
        $rectype,
        $namefile,
    );
}

function downloadFile($smarty, $module_name, $local_templates_dir, &$pDB, $pACL,
    $arrConf, $user, $extension)
{
    $record = getParameter("id");
    $namefile = getParameter('namefile');
    if (is_null($record) || !preg_match('/^[[:digit:]]+\.[[:digit:]]+$/', $record)) {
        // Missing or invalid uniqueid
        Header('HTTP/1.1 404 Not Found');
        die("<b>404 "._tr("no_file")." </b>");
    }

    $pMonitoring = new paloSantoMonitoring($pDB);
    if (!hasModulePrivilege($user, $module_name, 'downloadany')) {
        if (!$pMonitoring->recordBelongsToUser($record, $extension)) {
            Header('HTTP/1.1 403 Forbidden');
            die("<b>403 "._tr("You are not authorized to download this file")." </b>");
        }
    }

    // Check record is valid and points to an actual file
    $filebyUid = $pMonitoring->getAudioByUniqueId($record, $namefile);
    if (is_null($filebyUid) || count($filebyUid) <= 0) {
        // Uniqueid does not point to a record with specified file
        Header('HTTP/1.1 404 Not Found');
        die("<b>404 "._tr("no_file")." </b>");
    }
    if ($filebyUid['deleted']) {
        // Specified file has been deleted
        Header('HTTP/1.1 410 Gone');
        die("<b>410 "._tr("no_file")." </b>");
    }
    if (is_null($filebyUid['fullpath']) || is_null($filebyUid['mimetype'])) {
        Header('HTTP/1.1 404 Not Found');
        die("<b>404 "._tr("no_file")." </b>");
    }

    // Actually open and transmit the file
    $fp = fopen($filebyUid['fullpath'], 'rb');
    if (!$fp) {
        Header('HTTP/1.1 404 Not Found');
        die("<b>404 "._tr("no_file")." </b>");
    }
    header("Pragma: public");
    header("Expires: 0");
    header("Cache-Control: must-revalidate, post-check=0, pre-check=0");
    header("Cache-Control: public");
    header("Content-Description: wav file");
    header("Content-Type: " . $filebyUid['mimetype']);
    header("Content-Disposition: attachment; filename=" . basename($filebyUid['fullpath']));
    header("Content-Transfer-Encoding: binary");
    header("Content-length: " . filesize($filebyUid['fullpath']));
    fpassthru($fp);
    fclose($fp);
}

function streamAudioFile($smarty, $module_name, $local_templates_dir, &$pDB, $pACL,
    $arrConf, $user, $extension)
{
    $record = getParameter("id");
    $namefile = getParameter('namefile');
    if (is_null($record) || !preg_match('/^[[:digit:]]+\.[[:digit:]]+$/', $record)) {
        Header('HTTP/1.1 404 Not Found');
        die("<b>404 "._tr("no_file")." </b>");
    }

    $pMonitoring = new paloSantoMonitoring($pDB);
    if (!hasModulePrivilege($user, $module_name, 'downloadany')) {
        if (!$pMonitoring->recordBelongsToUser($record, $extension)) {
            Header('HTTP/1.1 403 Forbidden');
            die("<b>403 "._tr("You are not authorized to download this file")." </b>");
        }
    }

    $filebyUid = $pMonitoring->getAudioByUniqueId($record, $namefile);
    if (is_null($filebyUid) || count($filebyUid) <= 0 || $filebyUid['deleted'] || is_null($filebyUid['fullpath'])) {
        Header('HTTP/1.1 404 Not Found');
        die("<b>404 "._tr("no_file")." </b>");
    }

    serveStreamableAudioFile($filebyUid['fullpath']);
}

function display_record($smarty, $module_name, $local_templates_dir, &$pDB, $pACL, $arrConf, $user, $extension)
{
    return streamAudioFile($smarty, $module_name, $local_templates_dir, $pDB, $pACL, $arrConf, $user, $extension);
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

function deleteRecord($smarty, $module_name, $local_templates_dir, &$pDB, $pACL, $arrConf, $user, $extension, $arrUniqueids)
{
    if (!hasModulePrivilege($user, $module_name, 'deleteany')) {
        $smarty->assign("mb_title", _tr("ERROR"));
        $smarty->assign("mb_message", _tr("You are not authorized to delete any records"));
        return FALSE;
    }
    $pMonitoring = new paloSantoMonitoring($pDB);
    $path_record = $arrConf['records_dir'];
    foreach ($arrUniqueids as $ID) {
        $nameFile=$pMonitoring->getAudioByUniqueId($ID);
        if ($nameFile['recordingfile'] != "") $pMonitoring->deleteRecordFile($ID, $nameFile['recordingfile']);
    }

    return TRUE;
}

function SecToHHMMSS($sec)
{
    $HH = 0;$MM = 0;$SS = 0;
    $segundos = $sec;

    if( $segundos/3600 >= 1 ){ $HH = (int)($segundos/3600);$segundos = $segundos%3600;} if($HH < 10) $HH = "0$HH";
    if(  $segundos/60 >= 1  ){ $MM = (int)($segundos/60);  $segundos = $segundos%60;  } if($MM < 10) $MM = "0$MM";
    $SS = $segundos; if($SS < 10) $SS = "0$SS";

    return "$HH:$MM:$SS";
}

function createFieldFilter(){
    $arrFilter = array(
            "src"       => _tr("Source"),
            "dst"       => _tr("Destination"),
            "recordingfile" => _tr("Type"),
                    );

    $arrFormElements = array(
            "date_start"  => array(           "LABEL"                  => _tr("Start_Date"),
                                              "REQUIRED"               => "yes",
                                              "INPUT_TYPE"             => "DATE",
                                              "INPUT_EXTRA_PARAM"      => "",
                                              "VALIDATION_TYPE"        => "ereg",
                                              "VALIDATION_EXTRA_PARAM" => "^[[:digit:]]{1,2}[[:space:]]+[[:alnum:]]{3}[[:space:]]+[[:digit:]]{4}$"),
            "date_end"    => array(           "LABEL"                  => _tr("End_Date"),
                                              "REQUIRED"               => "yes",
                                              "INPUT_TYPE"             => "DATE",
                                              "INPUT_EXTRA_PARAM"      => "",
                                              "VALIDATION_TYPE"        => "ereg",
                                              "VALIDATION_EXTRA_PARAM" => "^[[:digit:]]{1,2}[[:space:]]+[[:alnum:]]{3}[[:space:]]+[[:digit:]]{4}$"),
            "filter_field" => array(          "LABEL"                  => _tr("Search"),
                                              "REQUIRED"               => "no",
                                              "INPUT_TYPE"             => "SELECT",
                                              "INPUT_EXTRA_PARAM"      => $arrFilter,
                                              "VALIDATION_TYPE"        => "text",
                                              "VALIDATION_EXTRA_PARAM" => ""),
            "filter_value" => array(          "LABEL"                  => "",
                                              "REQUIRED"               => "no",
                                              "INPUT_TYPE"             => "TEXT",
                                              "INPUT_EXTRA_PARAM"      => "",
                                              "VALIDATION_TYPE"        => "text",
                                              "VALIDATION_EXTRA_PARAM" => ""),
        "limit"  => array("LABEL"                  => _tr("Limit"),
                            "REQUIRED"               => "no",
                            "INPUT_TYPE"             => "SELECT",
                            "INPUT_EXTRA_PARAM"      => array(
                                                        "100000"         => _tr("100.000"),
                                                        "50000"    => _tr("50.000"),
                                                        "20000"        => _tr("20.000"),
                                                        "10000"      => _tr("10.000"),
                                                        "1000"  => _tr("1.000")),
                            "VALIDATION_TYPE"        => "text",
                            "VALIDATION_EXTRA_PARAM" => ""),
                    );
    return $arrFormElements;
}

// Abstracción de privilegio por módulo hasta implementar (Issabel bug #1100).
// Parámetro $module se usará en un futuro al implementar paloACL::hasModulePrivilege().
function hasModulePrivilege($user, $module, $privilege)
{
    global $arrConf;

    $pDB = new paloDB($arrConf['issabel_dsn']['acl']);
    $pACL = new paloACL($pDB);

    if (method_exists($pACL, 'hasModulePrivilege'))
        return $pACL->hasModulePrivilege($user, $module, $privilege);

    $isAdmin = ($pACL->isUserAdministratorGroup($user) !== FALSE);
    return ($isAdmin && in_array($privilege, array(
        'reportany',    // ¿Está autorizado el usuario a ver la información de todos los demás?
        'downloadany',  // ¿Está autorizado el usuario a descargar grabaciones de otros usuarios?
        'deleteany',    // ¿Está autorizado el usuario a borrar grabaciones (propias o de otros)?
    )));
}
