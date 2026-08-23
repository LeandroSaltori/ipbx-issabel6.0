<?php
/* vim: set expandtab tabstop=4 softtabstop=4 shiftwidth=4:
  Codificación: UTF-8
  +----------------------------------------------------------------------+
  | Issabel version 5.0 - Módulo Executivo de Chamadas Perdidas          |
  | http://www.issabel.org                                               |
  +----------------------------------------------------------------------+
  | Copyright (c) 2006 Palosanto Solutions S. A.                         |
  +----------------------------------------------------------------------+
  $Id: index.php,v 20.0 2026-08-18 Prisma Telecom $ */

include_once "libs/paloSantoDB.class.php";
include_once "libs/paloSantoConfig.class.php";
require_once "libs/misc.lib.php";

function formatSecsMc($sec) {
    $sec = (int)$sec;
    if ($sec <= 0) return '00:00';
    $m = floor($sec / 60);
    $s = $sec % 60;
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
    global $arrConf;
    if (isset($_REQUEST['action']) && $_REQUEST['action'] == 'save_address_book') {
        handleSaveAddressBook($arrConf);
        exit;
    }

    load_language_module($module_name);

    $date_start    = isset($_REQUEST['date_start']) ? trim($_REQUEST['date_start']) : date("Y-m-d");
    $date_end      = isset($_REQUEST['date_end']) ? trim($_REQUEST['date_end']) : date("Y-m-d");
    $filter_field  = isset($_REQUEST['filter_field']) ? trim($_REQUEST['filter_field']) : 'dst';
    $filter_value  = isset($_REQUEST['filter_value']) ? trim($_REQUEST['filter_value']) : '';

    $dsn  = generarDSNSistema('asteriskuser', 'asteriskcdrdb');
    $pDB  = new paloDB($dsn);

    $where = array("calldate BETWEEN ? AND ?", "disposition != 'ANSWERED'");
    $params = array($date_start . " 00:00:00", $date_end . " 23:59:59");

    if (!empty($filter_value)) {
        if ($filter_field == 'src') {
            $where[] = "src LIKE ?";
            $params[] = "%$filter_value%";
        } else {
            $where[] = "dst LIKE ?";
            $params[] = "%$filter_value%";
        }
    }

    $sqlWhere = "WHERE " . implode(" AND ", $where);
    $sql = "SELECT calldate, src, dst, disposition, duration, billsec, lastapp, accountcode FROM cdr $sqlWhere ORDER BY calldate DESC LIMIT 10000";
    $rows = $pDB->fetchTable($sql, TRUE, $params);
    if (!is_array($rows)) $rows = array();

    $totCalls   = count($rows);
    $noAnsCount = 0;
    $busyCount  = 0;
    $failCount  = 0;
    $totalWait  = 0;

    $hourlyLost = array_fill(0, 24, 0);

    foreach ($rows as $r) {
        $st = strtoupper(trim($r['disposition']));
        $dur = (int)$r['duration'];
        $totalWait += $dur;

        if ($st == 'NO ANSWER') $noAnsCount++;
        elseif ($st == 'BUSY') $busyCount++;
        elseif ($st == 'FAILED') $failCount++;
        else $noAnsCount++;

        if (preg_match('/(\d{2}):\d{2}:\d{2}/', $r['calldate'], $m)) {
            $h = (int)$m[1];
            if ($h >= 0 && $h <= 23) $hourlyLost[$h]++;
        }
    }

    $avgWait = $totCalls > 0 ? (int)round($totalWait / $totCalls) : 0;

    ob_start();
    ?>
    <script src="https://cdn.jsdelivr.net/npm/chart.js@3.9.1/dist/chart.min.js"></script>
    <style>
        .mc-root { font-family: 'Segoe UI', -apple-system, BlinkMacSystemFont, sans-serif; color: #1e293b; padding: 5px; }
        .mc-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 12px; }
        .mc-title h2 { margin: 0; font-size: 20px; font-weight: 800; color: #0f172a; letter-spacing: -0.3px; }
        .mc-title p { margin: 1px 0 0 0; font-size: 11px; color: #64748b; }
        .mc-top-btns { display: flex; gap: 8px; }
        .btn-top { padding: 6px 12px; border-radius: 6px; font-weight: 700; font-size: 12px; text-decoration: none; border: none; cursor: pointer; display: inline-flex; align-items: center; gap: 5px; box-shadow: 0 1px 2px rgba(0,0,0,0.05); transition: all 0.2s; }
        .btn-top:hover { transform: translateY(-1px); opacity: 0.95; }
        .btn-top-manual { background: #0284c7; color: #ffffff; }
        .btn-top-expand { background: #0d9488; color: #ffffff; }

        .filter-card-box { background: #ffffff; border-radius: 10px; padding: 12px 16px; margin-bottom: 15px; box-shadow: 0 1px 4px rgba(0,0,0,0.04); border: 1px solid #e2e8f0; }
        .filter-inline-row { display: flex; flex-wrap: wrap; gap: 10px; align-items: flex-end; }
        .filter-field-group { display: flex; flex-direction: column; flex: 1; min-width: 140px; }
        .filter-field-group label { font-size: 10px; font-weight: 700; color: #475569; text-transform: uppercase; letter-spacing: 0.4px; margin-bottom: 3px; }
        .filter-input { padding: 6px 10px; border: 1px solid #cbd5e1; border-radius: 6px; font-size: 12px; color: #0f172a; background: #ffffff; outline: none; height: 32px; box-sizing: border-box; transition: border-color 0.2s; }
        .filter-input:focus { border-color: #6366f1; }
        .filter-btn-row { display: flex; gap: 6px; align-items: center; }
        .btn-action { height: 32px; padding: 0 14px; border-radius: 6px; font-weight: 700; font-size: 12px; border: none; cursor: pointer; text-decoration: none; display: inline-flex; align-items: center; justify-content: center; gap: 5px; box-sizing: border-box; transition: all 0.2s; }
        .btn-action:hover { opacity: 0.9; }
        .btn-search { background: #dc2626; color: #ffffff; }
        .btn-reset { background: #f1f5f9; color: #475569; border: 1px solid #cbd5e1; }

        .kpi-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(170px, 1fr)); gap: 12px; margin-bottom: 15px; }
        .kpi-card-item { background: #ffffff; border-radius: 10px; padding: 14px 16px; box-shadow: 0 1px 4px rgba(0,0,0,0.04); border: 1px solid #f1f5f9; border-left: 5px solid #ef4444; display: flex; flex-direction: column; justify-content: space-between; }
        .kpi-card-item.red { border-left-color: #ef4444; }
        .kpi-card-item.amber { border-left-color: #f59e0b; }
        .kpi-card-item.purple { border-left-color: #8b5cf6; }
        .kpi-card-item.slate { border-left-color: #64748b; }

        .kpi-card-title { font-size: 10px; font-weight: 800; color: #64748b; text-transform: uppercase; letter-spacing: 0.5px; margin-bottom: 4px; }
        .kpi-card-num { font-size: 24px; font-weight: 800; color: #0f172a; line-height: 1; }
        .kpi-card-desc { font-size: 11px; color: #94a3b8; margin-top: 4px; }

        .charts-grid { display: grid; grid-template-columns: 2fr 1fr; gap: 15px; margin-bottom: 15px; }
        @media (max-width: 900px) { .charts-grid { grid-template-columns: 1fr; } }
        .chart-card-box { background: #ffffff; border-radius: 10px; padding: 16px 20px; box-shadow: 0 1px 4px rgba(0,0,0,0.04); border: 1px solid #e2e8f0; height: 260px; display: flex; flex-direction: column; margin-bottom: 15px; }
        .chart-card-box h4 { margin: 0 0 10px 0; font-size: 12px; font-weight: 800; color: #334155; text-transform: uppercase; border-bottom: 1px solid #f1f5f9; padding-bottom: 4px; }
        .chart-canvas-wrapper { position: relative; flex: 1; width: 100%; height: 100%; }

        .table-card-box { background: #ffffff; border-radius: 10px; box-shadow: 0 1px 4px rgba(0,0,0,0.04); border: 1px solid #e2e8f0; overflow: hidden; }
        .mc-table { width: 100%; border-collapse: collapse; text-align: left; }
        .mc-table thead { background: #334155; color: #ffffff; }
        .mc-table th { padding: 10px 14px; font-size: 10px; font-weight: 800; text-transform: uppercase; letter-spacing: 0.5px; }
        .mc-table td { padding: 10px 14px; border-bottom: 1px solid #f1f5f9; font-size: 12px; vertical-align: middle; }
        .mc-table tbody tr:hover { background: #fff1f2; }

        .badge-noans { background: #fee2e2; color: #b91c1c; border: 1px solid #fca5a5; padding: 3px 8px; border-radius: 6px; font-weight: bold; font-size: 10px; }
        .badge-busy { background: #fef3c7; color: #b45309; border: 1px solid #fde68a; padding: 3px 8px; border-radius: 6px; font-weight: bold; font-size: 10px; }
        .badge-fail { background: #fee2e2; color: #b91c1c; border: 1px solid #fca5a5; padding: 3px 8px; border-radius: 6px; font-weight: bold; font-size: 10px; }
    </style>

    <div class="mc-root">
        <!-- Header Principal -->
        <div class="mc-header">
            <div class="mc-title">
                <h2>Relatório de Chamadas Perdidas - IPbx Prisma</h2>
                <p>Histórico detalhado de ligações não atendidas, ocupadas ou abandonadas</p>
            </div>
            <div class="mc-top-btns">
                <a href="modules/missed_calls/help/index.html" target="_blank" class="btn-top btn-top-manual">📖 Manual</a>
                <button onclick="window.open('?menu=<?php echo htmlspecialchars($module_name); ?>&rawmode=yes', '_blank')" class="btn-top btn-top-expand">↗ Expandir Aba</button>
            </div>
        </div>

        <!-- Filtro Compacto -->
        <div class="filter-card-box">
            <form method="GET" action="index.php">
                <input type="hidden" name="menu" value="<?php echo htmlspecialchars($module_name); ?>" />
                <div class="filter-inline-row">
                    <div class="filter-field-group" title="📅 Data Inicial do Período&#10;Selecione a data de início da busca de ligações perdidas.">
                        <label>📅 Data Inicial</label>
                        <input type="date" name="date_start" value="<?php echo htmlspecialchars($date_start); ?>" class="filter-input" />
                    </div>
                    <div class="filter-field-group" title="📅 Data Final do Período&#10;Selecione a data limite da consulta.">
                        <label>📅 Data Final</label>
                        <input type="date" name="date_end" value="<?php echo htmlspecialchars($date_end); ?>" class="filter-input" />
                    </div>
                    <div class="filter-field-group" title="📌 Campo de Busca SQL&#10;Escolha se a pesquisa será feita pelo número de Destino (ramal/fila) ou Origem (bina).">
                        <label>📌 Buscar Campo</label>
                        <select name="filter_field" class="filter-input">
                            <option value="dst" <?php if ($filter_field == 'dst') echo 'selected'; ?>>Destino (dst)</option>
                            <option value="src" <?php if ($filter_field == 'src') echo 'selected'; ?>>Origem (src)</option>
                        </select>
                    </div>
                    <div class="filter-field-group" title="📞 Padrão / Número&#10;Digite o ramal ou número de telefone para filtrar chamadas perdidas específicas.">
                        <label>📞 Padrão / Número</label>
                        <input type="text" name="filter_value" value="<?php echo htmlspecialchars($filter_value); ?>" placeholder="Ex: 5001..." class="filter-input" />
                    </div>
                    <div class="filter-btn-row">
                        <button type="submit" class="btn-action btn-search" title="🔍 Filtrar Ligações Perdidas&#10;Buscar apenas chamadas não atendidas com base no período e filtros.">🔍 Filtrar Perdidas</button>
                        <a href="?menu=<?php echo htmlspecialchars($module_name); ?>" class="btn-action btn-reset" title="🔄 Restaurar Filtro&#10;Limpar filtros e exibir todas as ligações perdidas da data atual.">🔄 Reset</a>
                    </div>
                </div>
            </form>
        </div>

        <!-- Grid de 5 Cards KPIs -->
        <div class="kpi-grid">
            <div class="kpi-card-item red" title="📵 Total de Ligações Perdidas&#10;Soma de todas as chamadas no período que não foram completadas por atendimento.">
                <div class="kpi-card-title">📵 Chamadas Perdidas</div>
                <div class="kpi-card-num"><?php echo number_format($totCalls, 0, ',', '.'); ?></div>
                <div class="kpi-card-desc">Total no período</div>
            </div>
            <div class="kpi-card-item red" title="🚫 Ligações Não Atendidas (NO ANSWER)&#10;Quantidade de ligações que tocaram no destino até o limite de tempo sem atendimento.">
                <div class="kpi-card-title">🚫 Não Atendidas</div>
                <div class="kpi-card-num"><?php echo number_format($noAnsCount, 0, ',', '.'); ?></div>
                <div class="kpi-card-desc">Sem resposta no ramal</div>
            </div>
            <div class="kpi-card-item amber" title="🟡 Ramal Ocupado (BUSY)&#10;Chamadas onde o ramal ou canal de destino encontrava-se ocupado em outra ligação.">
                <div class="kpi-card-title">🟡 Ramal Ocupado</div>
                <div class="kpi-card-num"><?php echo number_format($busyCount, 0, ',', '.'); ?></div>
                <div class="kpi-card-desc">Ocupado no atendimento</div>
            </div>
            <div class="kpi-card-item purple" title="✖ Falhas Técnicas (FAILED)&#10;Chamadas não completadas por erro de rota, sinalização ou falha na rede de telefonia.">
                <div class="kpi-card-title">✖ Falhas Tecnicas</div>
                <div class="kpi-card-num"><?php echo number_format($failCount, 0, ',', '.'); ?></div>
                <div class="kpi-card-desc">Erro de rota/sinal</div>
            </div>
            <div class="kpi-card-item slate" title="⏳ Tempo Médio de Espera&#10;Tempo médio em segundos que o cliente aguardou na linha antes de desligar ou desistir.">
                <div class="kpi-card-title">⏳ Tempo Médio Espera</div>
                <div class="kpi-card-num"><?php echo formatSecsMc($avgWait); ?></div>
                <div class="kpi-card-desc">Antes de desligar</div>
            </div>
        </div>

        <!-- Gráficos -->
        <div class="charts-grid">
            <div class="chart-card-box" title="📊 Perdidas por Horário&#10;Gráfico de barras indicando a quantidade de chamadas perdidas hora a hora.">
                <h4>📊 Volume de Chamadas Perdidas por Horário</h4>
                <div class="chart-canvas-wrapper">
                    <canvas id="chartMcHourly"></canvas>
                </div>
            </div>
            <div class="chart-card-box" title="🚦 Motivo do Não Atendimento&#10;Gráfico de rosca demonstrando a divisão entre Não Atendeu, Ocupado e Falha.">
                <h4>🚦 Motivo do Não Atendimento</h4>
                <div class="chart-canvas-wrapper">
                    <canvas id="chartMcReason"></canvas>
                </div>
            </div>
        </div>

        <!-- Tabela -->
        <div class="table-card-box">
            <table class="mc-table">
                <thead>
                    <tr>
                        <th title="📅 Data e Hora&#10;Momento do início da tentativa de ligação.">Data / Hora</th>
                        <th title="📞 Origem (Bina)&#10;Número de telefone de quem realizou a chamada não atendida.">Origem (Bina)</th>
                        <th title="🎯 Destino / Fila&#10;Ramal, fila ou número externo que não atendeu.">Destino / Fila</th>
                        <th title="🚦 Status / Motivo&#10;Razão pela qual a chamada foi perdida (Não Atendeu, Ocupado ou Falha).">Status / Motivo</th>
                        <th title="⏱️ Tempo de Espera&#10;Tempo total que a ligação tocou antes do encerramento.">Tempo de Espera</th>
                        <th title="⚙️ Última Aplicação Asterisk&#10;Aplicação executada pelo PBX no momento da desconexão (ex: Dial, Voicemail, Hangup).">Última Aplicação</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (count($rows) > 0): ?>
                        <?php
                        $pageSrcList = array();
                        foreach ($rows as $_row) {
                            if (!empty($_row['src']) && $_row['src'] != '-') $pageSrcList[] = $_row['src'];
                        }
                        $abContactsMap = getAddressBookContactsMap();
                        $stats7dMap = getCdr7DaysStatsMap($pageSrcList, $pDB);
                        $extNamesMap = getAsteriskExtensionNamesMap($pDB);
                        ?>
                        <?php foreach ($rows as $r): ?>
                            <?php
                            $st = strtoupper(trim($r['disposition']));
                            $raw_src = !empty($r['src']) ? $r['src'] : '-';
                            $val_src = formatPhoneBrMc($raw_src);
                            ?>
                            <tr>
                                <td><span style="color:#334155; font-size:11px; font-weight:600;">📅 <?php echo htmlspecialchars($r['calldate']); ?></span></td>
                                <td>
                                    <?php echo renderCallerWithContactBadge($raw_src, $val_src, $abContactsMap, $stats7dMap, $extNamesMap); ?>
                                </td>
                                <td><span style="background:#f1f5f9; color:#334155; padding:3px 8px; border-radius:6px; font-weight:600; font-size:11px;">🎯 <?php echo htmlspecialchars(!empty($r['dst']) ? $r['dst'] : '-'); ?></span></td>
                                <td>
                                    <?php
                                    if ($st == 'NO ANSWER') echo "<span class='badge-noans'>📵 NÃO ATENDEU</span>";
                                    elseif ($st == 'BUSY') echo "<span class='badge-busy'>🟡 OCUPADO</span>";
                                    else echo "<span class='badge-fail'>✖ $st</span>";
                                    ?>
                                </td>
                                <td><span style="color:#0f172a; font-weight:700; font-size:11px;">⏱️ <?php echo formatSecsMc($r['duration']); ?></span></td>
                                <td>
                                    <?php if (!empty($r['lastapp']) && $r['lastapp'] != '-'): ?>
                                        <span style='color:#475569; font-size:11px;'><code><?php echo htmlspecialchars($r['lastapp']); ?></code></span>
                                    <?php else: ?>
                                        <span style='color:#cbd5e1;'>-</span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <tr>
                            <td colspan="6" style="text-align:center; padding:25px; color:#64748b;">
                                🎉 Nenhuma chamada perdida encontrada para os filtros selecionados!
                            </td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>
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
        if (typeof Chart !== 'undefined') {
            var ctxHourly = document.getElementById('chartMcHourly').getContext('2d');
            new Chart(ctxHourly, {
                type: 'bar',
                data: {
                    labels: ['00h','01h','02h','03h','04h','05h','06h','07h','08h','09h','10h','11h','12h','13h','14h','15h','16h','17h','18h','19h','20h','21h','22h','23h'],
                    datasets: [{
                        label: 'Chamadas Perdidas',
                        data: <?php echo json_encode($hourlyLost); ?>,
                        backgroundColor: '#ef4444',
                        borderRadius: 4
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: { legend: { display: false } },
                    scales: {
                        y: { beginAtZero: true, grid: { color: '#f1f5f9' } },
                        x: { grid: { display: false } }
                    }
                }
            });

            var ctxReason = document.getElementById('chartMcReason').getContext('2d');
            new Chart(ctxReason, {
                type: 'doughnut',
                data: {
                    labels: ['Não Atendeu', 'Ocupado', 'Falha'],
                    datasets: [{
                        data: [<?php echo "$noAnsCount, $busyCount, $failCount"; ?>],
                        backgroundColor: ['#ef4444', '#f59e0b', '#dc2626'],
                        borderWidth: 2,
                        borderColor: '#ffffff'
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: { legend: { position: 'right' } }
                }
            });
        }
    });
    </script>
    <?php
    return ob_get_clean();
}
?>
