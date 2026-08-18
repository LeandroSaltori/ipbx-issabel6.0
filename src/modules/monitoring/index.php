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

    switch (getParameter('action')) {
    case 'download':
        $h = 'downloadFile';
        break;
    case 'display_record':
        $h = 'display_record';
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

    $filter_field  = isset($_REQUEST['filter_field']) ? trim($_REQUEST['filter_field']) : 'src';
    $filter_value  = isset($_REQUEST['filter_value']) ? trim($_REQUEST['filter_value']) : '';
    $rec_type      = isset($_REQUEST['rec_type']) ? trim($_REQUEST['rec_type']) : 'ALL';
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
    $totalCount   = count($rawList);

    // KPI Stat Counters
    $incCount = 0;
    $outCount = 0;
    $queueCount = 0;
    $groupCount = 0;

    foreach ($rawList as $r) {
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
    $pageList = array_slice($rawList, $offset, $limit);
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

            #audioModalMonitoring {
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
        </style>
    </head>
    <body>
        <form id="monitoringFormMain" method="POST" action="index.php?menu=<?php echo htmlspecialchars($module_name); ?>">
            <input type="hidden" name="action_type" id="action_type" value="" />

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
                            <select name="rec_type" class="filter-input">
                                <option value="ALL" <?php if ($rec_type == 'ALL') echo 'selected'; ?>>-- Todos os Tipos --</option>
                                <option value="incoming" <?php if ($rec_type == 'incoming') echo 'selected'; ?>>⬇️ Entrada (Incoming)</option>
                                <option value="outgoing" <?php if ($rec_type == 'outgoing') echo 'selected'; ?>>⬆️ Saída (Outgoing)</option>
                                <option value="queue" <?php if ($rec_type == 'queue') echo 'selected'; ?>>👥 Fila (Queue)</option>
                                <option value="group" <?php if ($rec_type == 'group') echo 'selected'; ?>>👤+ Grupo (Group)</option>
                            </select>
                        </div>
                        <div class="filter-field-group">
                            <label>📊 Limite / Pág</label>
                            <select name="limit" class="filter-input">
                                <option value="20" <?php if ($limit == 20) echo 'selected'; ?>>20 por pág</option>
                                <option value="50" <?php if ($limit == 50) echo 'selected'; ?>>50 por pág</option>
                                <option value="100" <?php if ($limit == 100) echo 'selected'; ?>>100 por pág</option>
                                <option value="1000" <?php if ($limit == 1000) echo 'selected'; ?>>1.000 (Geral)</option>
                            </select>
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
                                    } elseif (is_null($recinfo['fullpath'])) {
                                        $actionHtml = '<span style="color: #94a3b8; font-size: 11px; background: rgba(148,163,184,0.15); border: 1px solid rgba(148,163,184,0.3); border-radius: 12px; padding: 4px 10px; font-weight: 500; display: inline-flex; align-items: center; gap: 4px;"><i class="fa fa-microphone-slash"></i> Gravação Ausente</span>';
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
                                        $urlparamsStream['action'] = 'display_record';
                                        $streamUrl = 'index.php?' . http_build_query($urlparamsStream);

                                        $actionHtml = "<div style='display:inline-flex; gap:6px; align-items:center;'>".
                                            "<button type='button' onclick=\"playMonitoringAudio('".htmlspecialchars($downloadUrl, ENT_QUOTES)."')\" style='background: linear-gradient(135deg, #7c3aed, #6d28d9); color: #ffffff; border: none; border-radius: 20px; padding: 4px 12px; font-weight: 700; font-size: 11px; cursor: pointer; box-shadow: 0 2px 6px rgba(124,58,237,0.3); transition: all 0.2s;'>▶ Ouvir</button>".
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
                                        <td><span style="color:#64748b; font-size:11px; font-family:monospace; font-weight:bold;"><code><?php echo htmlspecialchars($uniqueId); ?></code></span></td>
                                        <td><span style="color:#334155; font-size:11px; font-weight:600;">📅 <?php echo htmlspecialchars($calldate); ?></span></td>
                                        <td><span style="font-weight:600; color:#1e293b;">📞 <?php echo htmlspecialchars($src); ?></span></td>
                                        <td><span style="background:#ede9fe; color:#6d28d9; padding:3px 8px; border-radius:6px; font-weight:600; font-size:11px;">🎯 <?php echo htmlspecialchars($dst); ?></span></td>
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

        <!-- Modal Player de Audio -->
        <div id="audioModalMonitoring">
            <div class="modal-content-box">
                <h4 style="margin:0 0 12px 0; color:#1e293b; font-size:15px; font-weight:800;">🎧 Reproduzindo Gravação de Chamada</h4>
                <audio id="monAudioElement" controls style="width:100%; margin-bottom:15px;"></audio>
                <button type="button" onclick="closeMonitoringAudioModal()" style="background:#64748b; color:#fff; border:none; padding:6px 16px; border-radius:6px; font-weight:bold; cursor:pointer;">Fechar</button>
            </div>
        </div>

        <script>
            function playMonitoringAudio(audioUrl) {
                var modal = document.getElementById('audioModalMonitoring');
                var audio = document.getElementById('monAudioElement');
                audio.src = audioUrl;
                modal.style.display = 'flex';
                audio.play();
            }
            function closeMonitoringAudioModal() {
                var modal = document.getElementById('audioModalMonitoring');
                var audio = document.getElementById('monAudioElement');
                audio.pause();
                audio.currentTime = 0;
                modal.style.display = 'none';
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

function display_record($smarty, $module_name, $local_templates_dir, &$pDB, $pACL, $arrConf, $user, $extension){
    $file = getParameter("id");
    $namefile = getParameter('namefile');
    $pMonitoring = new paloSantoMonitoring($pDB);

    if (!hasModulePrivilege($user, $module_name, 'downloadany')) {
        if(!$pMonitoring->recordBelongsToUser($file, $extension)){
            return _tr("You are not authorized to listen this file");
        }
    }

    $recinfo = $pMonitoring->getAudioByUniqueId($file, $namefile);
    if (!is_array($recinfo)) {
        return $pMonitoring->errMsg;
    }
    $ctype = is_null($recinfo['mimetype']) ? '' : $recinfo['mimetype'];
    $audiourl = construirURL(array(
        'menu'             =>  $module_name,
        'action'           =>  'download',
        'id'               =>  $file,
        'namefile'         =>  $namefile,
        'rawmode'          =>  'yes',
        'issabelSession'   =>  session_id(),
    ));
    $sContenido=<<<contenido
<!DOCTYPE html>
<script>
modal.style.display = "block";
</script>
<html>
<head><title>Issabel</title></head>
<body>
    <audio src="$audiourl" controls autoplay>
        <embed src="$audiourl" width="300" height="20" autoplay="true" loop="false" type="$ctype" />
    </audio>
    <br/>
</body>
</html>
contenido;
    $protocol = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? "https" : "http");
    $sContenido=$protocol.'://'.$_SERVER["HTTP_HOST"].'/index.php'.$audiourl;
    return $sContenido;
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
