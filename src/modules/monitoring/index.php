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

    $dbDir = isset($arrConf['issabel_dbdir']) ? $arrConf['issabel_dbdir'] : '/var/www/db';
    if (!file_exists("{$dbDir}/address_book.db") && file_exists("/var/www/db/address_book.db")) {
        $dbDir = '/var/www/db';
    }
    $dsn = "sqlite3:///{$dbDir}/address_book.db";
    $pDB_addr = new paloDB($dsn);

    if (!$pDB_addr->connStatus) {
        echo json_encode(array('status' => 'error', 'message' => 'Erro ao conectar ao banco da Agenda: ' . $pDB_addr->errMsg));
        exit;
    }

    $cleanPhone = preg_replace('/\D/', '', $telefono);
    $checkSql = "SELECT id, name, last_name FROM contact WHERE (telefono = ? OR telefono = ?) AND directory = 'external' LIMIT 1";
    $existing = $pDB_addr->getFirstRowQuery($checkSql, true, array($telefono, $cleanPhone));

    if (!empty($existing) && isset($existing['id'])) {
        $updateSql = "UPDATE contact SET name=?, last_name=?, company=?, email=?, notes=?, status='isPublic', directory='external' WHERE id=?";
        $ok = $pDB_addr->genQuery($updateSql, array($name, $last_name, $company, $email, $notes, $existing['id']));
        if ($ok) {
            echo json_encode(array('status' => 'success', 'message' => "Contato '$name' atualizado com sucesso na Agenda Pública!"));
        } else {
            echo json_encode(array('status' => 'error', 'message' => 'Erro ao atualizar contato: ' . $pDB_addr->errMsg));
        }
    } else {
        $insertSql = "INSERT INTO contact (name, last_name, telefono, cell_phone, home_phone, fax1, fax2, email, iduser, picture, province, city, address, company, company_contact, contact_rol, directory, notes, status, department, im) VALUES (?, ?, ?, '', '', '', '', ?, 1, '', '', '', '', ?, '', '', 'external', ?, 'isPublic', '', '')";
        $ok = $pDB_addr->genQuery($insertSql, array($name, $last_name, $telefono, $email, $company, $notes));
        if ($ok) {
            echo json_encode(array('status' => 'success', 'message' => "Contato '$name' cadastrado com sucesso na Agenda Pública!"));
        } else {
            echo json_encode(array('status' => 'error', 'message' => 'Erro ao cadastrar contato: ' . $pDB_addr->errMsg));
        }
    }
    exit;
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
                                <th>ID Único</th>
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
                                        <td><span style="color:#64748b; font-size:11px; font-family:monospace; font-weight:bold;"><code><?php echo htmlspecialchars($uniqueId); ?></code></span></td>
                                        <td><span style="color:#334155; font-size:11px; font-weight:600;">📅 <?php echo htmlspecialchars($calldate); ?></span></td>
                                        <td>
                                            <div style='display:inline-flex; align-items:center; gap:6px;'>
                                                <span style="font-weight:600; color:#1e293b;">📞 <?php echo htmlspecialchars($src); ?></span>
                                                <?php if (!empty($src) && $src != '-'): ?>
                                                    <button type="button" onclick="openAddressBookModal('<?php echo htmlspecialchars($src, ENT_QUOTES); ?>')" title="📇 Salvar na Agenda Pública&#10;Clique para cadastrar este número na Agenda de Contatos Pública." style="background:rgba(59,130,246,0.12); color:#2563eb; border:1px solid rgba(59,130,246,0.3); border-radius:50%; width:20px; height:20px; display:inline-flex; align-items:center; justify-content:center; cursor:pointer; font-size:10px; transition:all 0.2s;" onmouseover="this.style.background='#2563eb'; this.style.color='#fff';" onmouseout="this.style.background='rgba(59,130,246,0.12)'; this.style.color='#2563eb';">📇</button>
                                                <?php endif; ?>
                                            </div>
                                        </td>
                                        <td><?php echo $dstHtml; ?></td>
                                        <td><span style="color:#0f172a; font-weight:700; font-size:11px;">⏱️ <?php echo htmlspecialchars($durText); ?></span></td>
                                        <td><?php echo $recTypeTag; ?></td>
                                        <td><?php echo $actionHtml; ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <tr>
                                    <td colspan="<?php echo $bPuedeBorrar ? 8 : 7; ?>" style="text-align:center; padding:30px; color:#64748b;">
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

                <!-- Controles de Áudio Centrais -->
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

                <!-- Velocidade e Ações Extras -->
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

        <script>
            var currentAudio = document.getElementById('stkAudioElement');

            function ensureAudioBarInBody() {
                var bar = document.getElementById('stickyBottomAudioPlayer');
                if (bar && bar.parentElement !== document.body) {
                    document.body.appendChild(bar);
                }
            }

            document.addEventListener("DOMContentLoaded", function() {
                ensureAudioBarInBody();
            });

            function playMonitoringAudio(audioUrl, caller, target, downloadUrl) {
                ensureAudioBarInBody();
                var bar = document.getElementById('stickyBottomAudioPlayer');
                document.getElementById('stkCaller').textContent = '📞 ' + (caller || '-');
                document.getElementById('stkTarget').textContent = '🎯 ' + (target || '-');
                document.getElementById('stkDownloadBtn').href = downloadUrl || audioUrl;
                
                currentAudio.src = audioUrl;
                bar.classList.add('active');
                
                var p = currentAudio.play();
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
                if (isPlaying) {
                    btn.innerHTML = '⏸ Pausar';
                    btn.style.background = '#e11d48';
                } else {
                    btn.innerHTML = '▶ Continuar';
                    btn.style.background = '#7c3aed';
                }
            }

            function stkTogglePlay() {
                if (currentAudio.paused) {
                    currentAudio.play();
                    updatePlayPauseButton(true);
                } else {
                    currentAudio.pause();
                    updatePlayPauseButton(false);
                }
            }

            function stkSeekRelative(seconds) {
                if (currentAudio) {
                    currentAudio.currentTime = Math.max(0, Math.min(currentAudio.duration || 0, currentAudio.currentTime + seconds));
                }
            }

            function stkSeekTo(val) {
                if (currentAudio && currentAudio.duration) {
                    currentAudio.currentTime = (val / 100) * currentAudio.duration;
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

            function stkSetSpeed(speed, btn) {
                if (currentAudio) {
                    currentAudio.playbackRate = speed;
                    var pills = document.querySelectorAll('.sticky-speed-selector .speed-btn');
                    pills.forEach(function(p) { p.classList.remove('active'); });
                    if (btn) btn.classList.add('active');
                }
            }

            function closeStickyAudioPlayer() {
                if (currentAudio) {
                    currentAudio.pause();
                    currentAudio.currentTime = 0;
                }
                document.getElementById('stickyBottomAudioPlayer').classList.remove('active');
            }

            currentAudio.addEventListener('timeupdate', function() {
                var cur = currentAudio.currentTime || 0;
                var dur = currentAudio.duration || 0;
                var bar = document.getElementById('stkProgressBar');
                if (dur > 0) {
                    bar.value = (cur / dur) * 100;
                }
                document.getElementById('stkTime').textContent = formatSecondsToMmSs(cur) + ' / ' + formatSecondsToMmSs(dur);
            });

            currentAudio.addEventListener('ended', function() {
                updatePlayPauseButton(false);
            });

            function formatSecondsToMmSs(secs) {
                var m = Math.floor(secs / 60);
                var s = Math.floor(secs % 60);
                return (m < 10 ? '0' + m : m) + ':' + (s < 10 ? '0' + s : s);
            }

            function toggleSelectAllMonitoring(master) {
                var checkboxes = document.querySelectorAll('.chk-mon-row');
                for (var i = 0; i < checkboxes.length; i++) {
                    checkboxes[i].checked = master.checked;
                }
            }

            function submitDeleteMonitoring() {
                var checked = document.querySelectorAll('.chk-mon-row:checked');
                if (checked.length === 0) {
                    alert('Por favor, selecione ao menos uma gravação para excluir.');
                    return;
                }
                if (confirm('Tem certeza de que deseja excluir as ' + checked.length + ' gravações selecionadas? Esta ação não pode ser desfeita.')) {
                    document.getElementById('action_type').value = 'delete_records';
                    document.getElementById('monitoringFormMain').submit();
                }
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
