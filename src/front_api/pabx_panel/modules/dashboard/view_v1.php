<?php
/**
 * IPbx Prisma - Módulo Dashboard 1 (Dados Reais do Asterisk/Issabel + Ajustes de UX)
 */

// Filtro de período selecionado (padrão: hoje)
$period = isset($_GET['period']) ? $_GET['period'] : 'hoje';
$start_date = isset($_GET['start_date']) && !empty($_GET['start_date']) ? $_GET['start_date'] : date('Y-m-d');
$end_date = isset($_GET['end_date']) && !empty($_GET['end_date']) ? $_GET['end_date'] : date('Y-m-d');

if ($period === '7dias') {
    $start_date = date('Y-m-d', strtotime('-7 days'));
    $end_date = date('Y-m-d');
} elseif ($period === '30dias') {
    $start_date = date('Y-m-d', strtotime('-30 days'));
    $end_date = date('Y-m-d');
} elseif ($period === 'hoje') {
    $start_date = date('Y-m-d');
    $end_date = date('Y-m-d');
}

$call_type_filter = isset($_GET['call_type']) ? $_GET['call_type'] : 'external';
if (!in_array($call_type_filter, ['external', 'internal', 'all'])) {
    $call_type_filter = 'external';
}

$sql_call_type_condition = "";
if ($call_type_filter === 'external') {
    $sql_call_type_condition = " AND NOT (LENGTH(src) <= 4 AND LENGTH(dst) <= 4)";
} elseif ($call_type_filter === 'internal') {
    $sql_call_type_condition = " AND (LENGTH(src) <= 4 AND LENGTH(dst) <= 4)";
}

$show_all_ramais = isset($_GET['show_all_ramais']) && $_GET['show_all_ramais'] == '1';

// Calcular período anterior equivalente para comparação precisa
$start_ts = strtotime($start_date);
$end_ts = strtotime($end_date);
$days_diff = max(1, round(($end_ts - $start_ts) / 86400) + 1);

$prev_end_ts = $start_ts - 86400;
$prev_start_ts = $prev_end_ts - (($days_diff - 1) * 86400);

$prev_start_date = date('Y-m-d', $prev_start_ts);
$prev_end_date = date('Y-m-d', $prev_end_ts);

// 1. Conexão e Busca de Ramais Reais no Banco do Sistema
$real_extensions = [];
if (isset($db)) {
    try {
        $stmt_exts = $db->query("SELECT extension, agent_name, tech FROM extensions_config ORDER BY CAST(extension AS UNSIGNED) ASC");
        if ($stmt_exts) {
            $real_extensions = $stmt_exts->fetchAll(PDO::FETCH_ASSOC);
        }
    } catch (Exception $e) {}
}

// 2. Conectar ao MySQL Asterisk CDR para buscar métricas reais completas da operação
$sys_conf = function_exists('getIssabelSystemConfig') ? getIssabelSystemConfig() : ['mysqlrootpwd' => ''];
$ast_db = null;
$ramal_stats = [];

// Variáveis reais da operação de telefonia no período atual
$total_chamadas_real = 0;
$total_atendidas_real = 0;
$total_abandonadas_real = 0;
$demanda_ativa_real = 0;
$demanda_receptiva_real = 0;
$tma_medio_geral = 0;
$tme_medio_geral = 0;
$tpr_medio_geral = 0;

// Métricas do período anterior para comparação %
$prev_atendidas = 0;
$prev_ativa = 0;
$prev_receptiva = 0;
$prev_tma = 0;
$prev_tme = 0;
$prev_tpr = 0;

$real_queues = [];
$queues_rt_map = [];

// Funções auxiliares de formatação e tendência
if (!function_exists('calcTrendBadge')) {
    function calcTrendBadge($current, $previous) {
        if ($previous <= 0) {
            if ($current > 0) {
                return '<span class="text-emerald-400 font-bold">↗ +100%</span> <span class="text-slate-500 font-normal">vs. período anterior</span>';
            }
            return '<span class="text-slate-400 font-medium">= 0%</span> <span class="text-slate-500 font-normal">vs. período anterior</span>';
        }
        $diff = $current - $previous;
        $pct = round(($diff / $previous) * 100, 1);
        if ($pct > 0) {
            return '<span class="text-emerald-400 font-bold">↗ +' . $pct . '%</span> <span class="text-slate-500 font-normal">vs. período anterior</span>';
        } elseif ($pct < 0) {
            return '<span class="text-rose-400 font-bold">↘ ' . $pct . '%</span> <span class="text-slate-500 font-normal">vs. período anterior</span>';
        }
        return '<span class="text-slate-400 font-medium">= 0%</span> <span class="text-slate-500 font-normal">vs. período anterior</span>';
    }
}

if (!function_exists('formatSecondsToHuman')) {
    function formatSecondsToHuman($seconds) {
        $seconds = (int)$seconds;
        if ($seconds <= 0) return '0s';
        if ($seconds < 60) return "{$seconds}s";
        $m = floor($seconds / 60);
        $s = $seconds % 60;
        if ($m < 60) {
            return $s > 0 ? "{$m}m {$s}s" : "{$m}m";
        }
        $h = floor($m / 60);
        $m = $m % 60;
        return $m > 0 ? "{$h}h {$m}m" : "{$h}h";
    }
}

$sql_date_condition = "calldate >= '$start_date 00:00:00' AND calldate <= '$end_date 23:59:59'";
$sql_prev_date_condition = "calldate >= '$prev_start_date 00:00:00' AND calldate <= '$prev_end_date 23:59:59'";

$chart_labels = [];
$chart_inc = [];
$chart_out = [];
$chart_fixo = [];
$chart_movel = [];

$ast_db = function_exists('getAsteriskPdoConnection') ? getAsteriskPdoConnection('asteriskcdrdb') : null;
if ($ast_db) {
    try {

        // Resumo Geral da Operação no período atual
        $sql_summary = "SELECT 
                            COUNT(*) as total_chamadas,
                            SUM(CASE WHEN disposition = 'ANSWERED' THEN 1 ELSE 0 END) as atendidas,
                            SUM(CASE WHEN disposition != 'ANSWERED' THEN 1 ELSE 0 END) as abandonadas,
                            SUM(CASE WHEN (LENGTH(src) <= 5 AND src REGEXP '^[0-9]+$') AND LENGTH(dst) > 5 THEN 1 ELSE 0 END) as ativa,
                            SUM(CASE WHEN LENGTH(src) > 5 OR src REGEXP '^0?[1-9]{2}' THEN 1 ELSE 0 END) as receptiva,
                            AVG(CASE WHEN disposition = 'ANSWERED' THEN billsec ELSE NULL END) as avg_tma,
                            AVG(CASE WHEN disposition = 'ANSWERED' THEN (duration - billsec) ELSE NULL END) as avg_tme,
                            AVG(CASE WHEN disposition = 'ANSWERED' AND (LENGTH(src) > 5 OR src REGEXP '^0?[1-9]{2}') THEN (duration - billsec) ELSE NULL END) as avg_tpr
                        FROM cdr 
                        WHERE $sql_date_condition $sql_call_type_condition";
        $q_sum = $ast_db->query($sql_summary);
        if ($q_sum && $row_sum = $q_sum->fetch(PDO::FETCH_ASSOC)) {
            $total_chamadas_real   = (int)($row_sum['total_chamadas'] ?? 0);
            $total_atendidas_real  = (int)($row_sum['atendidas'] ?? 0);
            $total_abandonadas_real = (int)($row_sum['abandonadas'] ?? 0);
            $demanda_ativa_real    = (int)($row_sum['ativa'] ?? 0);
            $demanda_receptiva_real = (int)($row_sum['receptiva'] ?? 0);
            $tma_medio_geral       = round((float)($row_sum['avg_tma'] ?? 0));
            $tme_medio_geral       = round((float)($row_sum['avg_tme'] ?? 0));
            $tpr_medio_geral       = round((float)($row_sum['avg_tpr'] ?? 0));
        }

        // Resumo do Período Anterior para Comparação %
        $sql_prev = "SELECT 
                        SUM(CASE WHEN disposition = 'ANSWERED' THEN 1 ELSE 0 END) as atendidas,
                        SUM(CASE WHEN (LENGTH(src) <= 5 AND src REGEXP '^[0-9]+$') AND LENGTH(dst) > 5 THEN 1 ELSE 0 END) as ativa,
                        SUM(CASE WHEN LENGTH(src) > 5 OR src REGEXP '^0?[1-9]{2}' THEN 1 ELSE 0 END) as receptiva,
                        AVG(CASE WHEN disposition = 'ANSWERED' THEN billsec ELSE NULL END) as avg_tma,
                        AVG(CASE WHEN disposition = 'ANSWERED' THEN (duration - billsec) ELSE NULL END) as avg_tme,
                        AVG(CASE WHEN disposition = 'ANSWERED' AND (LENGTH(src) > 5 OR src REGEXP '^0?[1-9]{2}') THEN (duration - billsec) ELSE NULL END) as avg_tpr
                    FROM cdr 
                    WHERE $sql_prev_date_condition $sql_call_type_condition";
        $q_prev = $ast_db->query($sql_prev);
        if ($q_prev && $row_prev = $q_prev->fetch(PDO::FETCH_ASSOC)) {
            $prev_atendidas = (int)($row_prev['atendidas'] ?? 0);
            $prev_ativa     = (int)($row_prev['ativa'] ?? 0);
            $prev_receptiva = (int)($row_prev['receptiva'] ?? 0);
            $prev_tma       = round((float)($row_prev['avg_tma'] ?? 0));
            $prev_tme       = round((float)($row_prev['avg_tme'] ?? 0));
            $prev_tpr       = round((float)($row_prev['avg_tpr'] ?? 0));
        }

        // Dados Dinâmicos do Gráfico de Evolução Temporal
        if ($start_date === $end_date) {
            // Filtro de 1 dia: agrupar por HORA (00:00 a 23:00)
            $hours_map = [];
            for ($h = 0; $h < 24; $h++) {
                $key = sprintf('%02d:00', $h);
                $hours_map[$key] = ['inc' => 0, 'out' => 0, 'fixo' => 0, 'movel' => 0];
            }

            $sql_chart = "SELECT 
                            DATE_FORMAT(calldate, '%H:00') as hr,
                            SUM(CASE WHEN (LENGTH(src) > 5 OR src REGEXP '^0?[1-9]{2}') THEN 1 ELSE 0 END) as inc,
                            SUM(CASE WHEN (LENGTH(src) <= 5 AND src REGEXP '^[0-9]+$') AND LENGTH(dst) > 5 THEN 1 ELSE 0 END) as out_call,
                            SUM(CASE WHEN (dst REGEXP '^(0?[1-9]{2})?[2-5][0-9]{7}$' OR src REGEXP '^(0?[1-9]{2})?[2-5][0-9]{7}$') THEN 1 ELSE 0 END) as fixo,
                            SUM(CASE WHEN (dst REGEXP '^(0?[1-9]{2})?9[0-9]{8}$' OR src REGEXP '^(0?[1-9]{2})?9[0-9]{8}$') THEN 1 ELSE 0 END) as movel
                        FROM cdr 
                        WHERE $sql_date_condition $sql_call_type_condition
                        GROUP BY DATE_FORMAT(calldate, '%H:00')
                        ORDER BY hr ASC";
            $q_ch = $ast_db->query($sql_chart);
            if ($q_ch) {
                while ($r = $q_ch->fetch(PDO::FETCH_ASSOC)) {
                    $hr = $r['hr'];
                    if (isset($hours_map[$hr])) {
                        $hours_map[$hr] = [
                            'inc'   => (int)$r['inc'],
                            'out'   => (int)$r['out_call'],
                            'fixo'  => (int)$r['fixo'],
                            'movel' => (int)$r['movel']
                        ];
                    }
                }
            }

            $has_out_of_hours = false;
            foreach ($hours_map as $hr_str => $vals) {
                $h_num = (int)substr($hr_str, 0, 2);
                if (($h_num < 8 || $h_num > 18) && ($vals['inc'] > 0 || $vals['out'] > 0)) {
                    $has_out_of_hours = true;
                    break;
                }
            }

            foreach ($hours_map as $hr_str => $vals) {
                $h_num = (int)substr($hr_str, 0, 2);
                if (!$has_out_of_hours && ($h_num < 8 || $h_num > 18)) {
                    continue;
                }
                $chart_labels[] = $hr_str;
                $chart_inc[]    = $vals['inc'];
                $chart_out[]    = $vals['out'];
                $chart_fixo[]   = $vals['fixo'];
                $chart_movel[]  = $vals['movel'];
            }
        } else {
            // Filtro de múltiplos dias: agrupar por DATA (DD/MM)
            $sql_chart = "SELECT 
                            DATE_FORMAT(calldate, '%d/%m') as dt,
                            SUM(CASE WHEN (LENGTH(src) > 5 OR src REGEXP '^0?[1-9]{2}') THEN 1 ELSE 0 END) as inc,
                            SUM(CASE WHEN (LENGTH(src) <= 5 AND src REGEXP '^[0-9]+$') AND LENGTH(dst) > 5 THEN 1 ELSE 0 END) as out_call,
                            SUM(CASE WHEN (dst REGEXP '^(0?[1-9]{2})?[2-5][0-9]{7}$' OR src REGEXP '^(0?[1-9]{2})?[2-5][0-9]{7}$') THEN 1 ELSE 0 END) as fixo,
                            SUM(CASE WHEN (dst REGEXP '^(0?[1-9]{2})?9[0-9]{8}$' OR src REGEXP '^(0?[1-9]{2})?9[0-9]{8}$') THEN 1 ELSE 0 END) as movel
                        FROM cdr 
                        WHERE $sql_date_condition $sql_call_type_condition
                        GROUP BY DATE_FORMAT(calldate, '%Y-%m-%d')
                        ORDER BY calldate ASC";
            $q_ch = $ast_db->query($sql_chart);
            if ($q_ch) {
                while ($r = $q_ch->fetch(PDO::FETCH_ASSOC)) {
                    $chart_labels[] = $r['dt'];
                    $chart_inc[]    = (int)$r['inc'];
                    $chart_out[]    = (int)$r['out_call'];
                    $chart_fixo[]   = (int)$r['fixo'];
                    $chart_movel[]  = (int)$r['movel'];
                }
            }
        }

        // Buscar estatísticas por ramal no período (Diferenciando Atendidas Receptivas vs Efetuadas Ativas)
        $sql_cdr_src = "SELECT 
                            src as extension,
                            COUNT(*) as total_efetuadas_tentativas,
                            SUM(CASE WHEN disposition = 'ANSWERED' THEN 1 ELSE 0 END) as efetuadas_atendidas,
                            AVG(CASE WHEN disposition = 'ANSWERED' THEN billsec ELSE 0 END) as avg_tma_src
                        FROM cdr 
                        WHERE $sql_date_condition $sql_call_type_condition AND LENGTH(src) <= 5
                        GROUP BY src";
        $q_src = $ast_db->query($sql_cdr_src);
        $ramal_src_stats = [];
        if ($q_src) {
            while ($row = $q_src->fetch(PDO::FETCH_ASSOC)) {
                $ramal_src_stats[$row['extension']] = $row;
            }
        }

        $sql_cdr_dst = "SELECT 
                            dst as extension,
                            COUNT(*) as total_receptivas_recebidas,
                            SUM(CASE WHEN disposition = 'ANSWERED' THEN 1 ELSE 0 END) as receptivas_atendidas,
                            AVG(CASE WHEN disposition = 'ANSWERED' THEN billsec ELSE 0 END) as avg_tma_dst,
                            AVG(duration - billsec) as avg_tme_dst
                        FROM cdr 
                        WHERE $sql_date_condition $sql_call_type_condition AND LENGTH(dst) <= 5
                        GROUP BY dst";
        $q_dst = $ast_db->query($sql_cdr_dst);
        $ramal_dst_stats = [];
        if ($q_dst) {
            while ($row = $q_dst->fetch(PDO::FETCH_ASSOC)) {
                $ramal_dst_stats[$row['extension']] = $row;
            }
        }

        // Filas reais cadastradas
        try {
            $q_queues = $ast_db->query("SELECT extension as queue_num, descr as queue_name FROM asterisk.queues_config");
            if ($q_queues) $real_queues = $q_queues->fetchAll(PDO::FETCH_ASSOC);
        } catch (Exception $e) {}

    } catch (Exception $ex) {}
}

if (empty($chart_labels)) {
    $chart_labels = ['08:00', '10:00', '12:00', '14:00', '16:00', '18:00'];
    $chart_inc   = [0, 0, 0, 0, 0, 0];
    $chart_out   = [0, 0, 0, 0, 0, 0];
    $chart_fixo  = [0, 0, 0, 0, 0, 0];
    $chart_movel = [0, 0, 0, 0, 0, 0];
}

// Obter status realtime de filas via AMI/Asterisk CLI
if (function_exists('getAsteriskQueuesRealtimeStatus')) {
    $rt_queues = getAsteriskQueuesRealtimeStatus();
    foreach ($rt_queues as $rqs) {
        $qnum = $rqs['queue_num'] ?? ($rqs['queue_number'] ?? '');
        if ($qnum) $queues_rt_map[$qnum] = $rqs;
    }
}

// Montar lista filtrada de ramais para a tabela de desempenho
$users_performance = [];
foreach ($real_extensions as $ext_item) {
    $ext_num = $ext_item['extension'];
    $agent_name = function_exists('formatEndpointName') ? formatEndpointName($ext_num) : ($ext_item['agent_name'] ?: "Ramal $ext_num");
    
    $stats_src = isset($ramal_src_stats[$ext_num]) ? $ramal_src_stats[$ext_num] : null;
    $stats_dst = isset($ramal_dst_stats[$ext_num]) ? $ramal_dst_stats[$ext_num] : null;

    $atendidas_receptivas = $stats_dst ? (int)$stats_dst['receptivas_atendidas'] : 0;
    $efetuadas_atendidas  = $stats_src ? (int)$stats_src['efetuadas_atendidas'] : 0;
    
    $total_tentativas_src = $stats_src ? (int)$stats_src['total_efetuadas_tentativas'] : 0;
    $total_recebidas_dst  = $stats_dst ? (int)$stats_dst['total_receptivas_recebidas'] : 0;
    $total_chamadas       = $total_tentativas_src + $total_recebidas_dst;

    $tma_sec_src = $stats_src ? (float)$stats_src['avg_tma_src'] : 0;
    $tma_sec_dst = $stats_dst ? (float)$stats_dst['avg_tma_dst'] : 0;
    $total_atendidas_geral = $atendidas_receptivas + $efetuadas_atendidas;
    $tma_sec = $total_atendidas_geral > 0 ? round((($tma_sec_src * $efetuadas_atendidas) + ($tma_sec_dst * $atendidas_receptivas)) / $total_atendidas_geral) : 0;

    $tme_sec = $stats_dst ? round((float)$stats_dst['avg_tme_dst']) : 0;

    if ($total_chamadas == 0 && !$show_all_ramais) {
        continue;
    }

    $users_performance[] = [
        'extension' => $ext_num,
        'name' => $agent_name,
        'tech' => $ext_item['tech'],
        'em_chamada' => 0,
        'atendidas_receptivas' => $atendidas_receptivas,
        'efetuadas_atendidas'  => $efetuadas_atendidas,
        'total' => $total_chamadas,
        'tme' => $tme_sec > 0 ? gmdate('i\s', $tme_sec) : '0s',
        'tma' => $tma_sec > 0 ? gmdate('i\m s\s', $tma_sec) : '0s'
    ];
}

// Buscar Gravações Reais do CDR no período
$recent_calls = [];
if ($ast_db) {
    try {
        $q_cdr_recent = $ast_db->query("SELECT uniqueid, calldate, src, dst, disposition, billsec, recordingfile FROM cdr WHERE $sql_date_condition $sql_call_type_condition AND recordingfile != '' ORDER BY calldate DESC LIMIT 10");
        if ($q_cdr_recent) $recent_calls = $q_cdr_recent->fetchAll(PDO::FETCH_ASSOC);

        if (empty($recent_calls)) {
            $q_cdr_fallback = $ast_db->query("SELECT uniqueid, calldate, src, dst, disposition, billsec, recordingfile FROM cdr WHERE $sql_date_condition $sql_call_type_condition ORDER BY calldate DESC LIMIT 10");
            if ($q_cdr_fallback) $recent_calls = $q_cdr_fallback->fetchAll(PDO::FETCH_ASSOC);
        }
    } catch (Exception $ex) {}
}
?>

<div class="space-y-6">

    <!-- BARRA SUPERIOR: Saudação, Filtro de Período e Ações -->
    <div class="flex flex-col lg:flex-row items-start lg:items-center justify-between gap-4 bg-slate-900/90 border border-slate-800 p-5 rounded-2xl shadow-xl">
        <div>
            <div class="flex items-center gap-2 text-xs font-semibold text-slate-400 mb-1">
                <span>Central Telefônica PABX IP</span>
                <i class="fa-solid fa-chevron-right text-[10px]"></i>
                <span class="text-brand-400 font-bold">Dashboard Principal</span>
            </div>
            <h1 class="text-2xl font-black text-white flex items-center gap-2">
                Painel Operacional de Voz <span class="text-xl">📞</span>
            </h1>
        </div>

        <!-- Seletor de Período, Tipo de Chamada e Personalização -->
        <form method="GET" action="index.php" class="flex flex-wrap items-center gap-2.5">
            <input type="hidden" name="module" value="dashboard">
            <input type="hidden" name="action" value="view_v1">
            <input type="hidden" name="period" value="personalizado">
            <input type="hidden" name="call_type" value="<?php echo htmlspecialchars($call_type_filter); ?>">

            <!-- Filtro de Período -->
            <div class="bg-slate-950/80 border border-slate-800 p-1 rounded-xl flex items-center gap-1 text-xs font-bold">
                <a href="index.php?module=dashboard&action=view_v1&period=hoje&call_type=<?php echo $call_type_filter; ?>" class="px-3 py-1.5 rounded-lg transition <?php echo $period === 'hoje' ? 'bg-brand-600 text-white shadow' : 'text-slate-400 hover:text-white'; ?>">Hoje</a>
                <a href="index.php?module=dashboard&action=view_v1&period=7dias&call_type=<?php echo $call_type_filter; ?>" class="px-3 py-1.5 rounded-lg transition <?php echo $period === '7dias' ? 'bg-brand-600 text-white shadow' : 'text-slate-400 hover:text-white'; ?>">7 dias</a>
                <a href="index.php?module=dashboard&action=view_v1&period=30dias&call_type=<?php echo $call_type_filter; ?>" class="px-3 py-1.5 rounded-lg transition <?php echo $period === '30dias' ? 'bg-brand-600 text-white shadow' : 'text-slate-400 hover:text-white'; ?>">30 dias</a>
            </div>

            <!-- Filtro de Tipo de Chamada (Externas vs Internas vs Todas) -->
            <div class="bg-slate-950/80 border border-slate-800 p-1 rounded-xl flex items-center gap-1 text-xs font-bold" title="Filtro do Dashboard: Separe chamadas externas para não poluir as métricas com ligações ramal-a-ramal">
                <a href="index.php?module=dashboard&action=view_v1&period=<?php echo $period; ?>&start_date=<?php echo $start_date; ?>&end_date=<?php echo $end_date; ?>&call_type=external" 
                   class="px-2.5 py-1.5 rounded-lg transition flex items-center gap-1.5 <?php echo $call_type_filter === 'external' ? 'bg-indigo-600 text-white shadow-lg' : 'text-slate-400 hover:text-white'; ?>"
                   title="Exibir apenas chamadas externas (clientes/troncos), ignorando ligações ramal a ramal">
                   <i class="fa-solid fa-globe text-[11px]"></i> Externas
                </a>
                <a href="index.php?module=dashboard&action=view_v1&period=<?php echo $period; ?>&start_date=<?php echo $start_date; ?>&end_date=<?php echo $end_date; ?>&call_type=internal" 
                   class="px-2.5 py-1.5 rounded-lg transition flex items-center gap-1.5 <?php echo $call_type_filter === 'internal' ? 'bg-indigo-600 text-white shadow-lg' : 'text-slate-400 hover:text-white'; ?>"
                   title="Exibir apenas chamadas internas (ramal a ramal)">
                   <i class="fa-solid fa-network-wired text-[11px]"></i> Internas
                </a>
                <a href="index.php?module=dashboard&action=view_v1&period=<?php echo $period; ?>&start_date=<?php echo $start_date; ?>&end_date=<?php echo $end_date; ?>&call_type=all" 
                   class="px-2.5 py-1.5 rounded-lg transition flex items-center gap-1 <?php echo $call_type_filter === 'all' ? 'bg-indigo-600 text-white shadow-lg' : 'text-slate-400 hover:text-white'; ?>"
                   title="Exibir todas as chamadas (internas + externas)">
                   Todas
                </a>
            </div>

            <!-- Intervalo de Datas (Agenda Visual com Seleção Direta ao Clicar) -->
            <div class="flex items-center gap-2 text-xs font-bold text-slate-400">
                <div onclick="try{this.querySelector('input').showPicker();}catch(e){}" class="flex items-center gap-1.5 bg-slate-950/90 border border-slate-800 hover:border-brand-500/50 px-3 py-1.5 rounded-xl cursor-pointer transition">
                    <i class="fa-solid fa-calendar-days text-brand-400 text-xs"></i>
                    <span class="text-[10px] text-slate-400 font-extrabold uppercase">DE</span>
                    <input type="date" name="start_date" value="<?php echo htmlspecialchars($start_date); ?>" onclick="try{this.showPicker();}catch(e){}" class="bg-transparent text-white focus:outline-none text-xs font-mono font-bold cursor-pointer">
                </div>
                <div onclick="try{this.querySelector('input').showPicker();}catch(e){}" class="flex items-center gap-1.5 bg-slate-950/90 border border-slate-800 hover:border-brand-500/50 px-3 py-1.5 rounded-xl cursor-pointer transition">
                    <i class="fa-solid fa-calendar-days text-brand-400 text-xs"></i>
                    <span class="text-[10px] text-slate-400 font-extrabold uppercase">ATÉ</span>
                    <input type="date" name="end_date" value="<?php echo htmlspecialchars($end_date); ?>" onclick="try{this.showPicker();}catch(e){}" class="bg-transparent text-white focus:outline-none text-xs font-mono font-bold cursor-pointer">
                </div>
                <button type="submit" class="px-3.5 py-2 bg-brand-600 hover:bg-brand-500 text-white rounded-xl font-extrabold text-xs transition shadow-lg shadow-brand-600/30 flex items-center gap-1.5">
                    <i class="fa-solid fa-filter"></i> Filtrar
                </button>
            </div>
        </form>
    </div>

    <!-- BANNER DE INSIGHTS DE IA (DESIGN IDÊNTICO À REFERÊNCIA DO USUÁRIO COM HISTÓRICO E ANÁLISE SOB DEMANDA) -->
    <div class="bg-slate-900/90 border border-purple-500/20 rounded-2xl p-5 shadow-xl space-y-4">
        <div class="flex flex-col md:flex-row items-start md:items-center justify-between gap-4">
            <div>
                <h3 class="text-sm font-extrabold text-white flex items-center gap-2">
                    <i class="fa-solid fa-wand-magic-sparkles text-purple-400"></i> Insights de IA
                    <span id="ai-last-updated-badge" class="text-[11px] font-bold text-slate-400 ml-2 hidden">
                        · Atualizado em: <span id="ai-last-updated-time" class="text-purple-300">--:--</span>
                    </span>
                </h3>
                <p id="ai-sub-description" class="text-xs text-slate-400 italic mt-0.5">
                    Clique em "Analisar" para gerar insights baseados nas métricas atuais.
                </p>
            </div>

            <!-- Botões de Ação: Histórico e Analisar -->
            <div class="flex items-center gap-2">
                <button onclick="openAiHistoryModal()" 
                        class="px-3.5 py-1.5 bg-slate-800 hover:bg-slate-700 text-slate-200 rounded-xl text-xs font-bold transition border border-slate-700 flex items-center gap-1.5">
                    <i class="fa-solid fa-clock-rotate-left text-slate-400"></i> Histórico
                </button>

                <button onclick="triggerAiInsightsAnalysis()" id="btn-trigger-ai-analysis"
                        class="px-4 py-1.5 bg-purple-600 hover:bg-purple-500 text-white rounded-xl text-xs font-extrabold transition shadow-lg shadow-purple-600/30 flex items-center gap-2">
                    <i class="fa-solid fa-sparkles"></i>
                    <span>Analisar</span>
                </button>
            </div>
        </div>

        <!-- LISTA DE CARDS DE INSIGHTS GERADOS -->
        <div id="ai-insights-container" class="hidden space-y-3 pt-3 border-t border-slate-800/80">
            <div class="grid grid-cols-1 md:grid-cols-3 gap-3" id="ai-insights-cards">
                <!-- Preenchido via JavaScript -->
            </div>
        </div>
    </div>

    <!-- MODAL DE HISTÓRICO DE ANÁLISES DE IA (MANTEMOS AS ÚLTIMAS 50) -->
    <div id="modal-ai-history" class="fixed inset-0 z-50 bg-slate-950/80 backdrop-blur-sm flex items-center justify-center p-4 hidden">
        <div class="bg-slate-900 border border-slate-800 rounded-2xl w-full max-w-2xl shadow-2xl overflow-hidden space-y-4">
            <div class="p-5 border-b border-slate-800 flex items-center justify-between">
                <div>
                    <h3 class="text-base font-extrabold text-white flex items-center gap-2">
                        <i class="fa-solid fa-clock-rotate-left text-purple-400"></i> Histórico de análises
                    </h3>
                    <p class="text-xs text-slate-400">Análises anteriores deste PABX (mantemos as últimas 50).</p>
                </div>
                <button onclick="closeAiHistoryModal()" class="w-8 h-8 rounded-full bg-slate-800 hover:bg-slate-700 text-slate-400 hover:text-white flex items-center justify-center transition">
                    <i class="fa-solid fa-xmark"></i>
                </button>
            </div>

            <div class="p-5 max-h-[60vh] overflow-y-auto space-y-3 custom-scrollbar" id="ai-history-list">
                <!-- Lista de histórico de análises via JS -->
            </div>

            <div class="p-4 bg-slate-950/80 border-t border-slate-800 flex justify-end">
                <button onclick="closeAiHistoryModal()" class="px-4 py-2 bg-slate-800 hover:bg-slate-700 text-white rounded-xl text-xs font-bold transition">
                    Fechar
                </button>
            </div>
        </div>
    </div>

    <!-- MODAL DE DETALHAMENTO DE LIGAÇÕES DOS CARDS DE KPI -->
    <div id="modal-kpi-calls-detail" class="fixed inset-0 z-50 bg-slate-950/80 backdrop-blur-md flex items-center justify-center p-4 hidden">
        <div class="bg-slate-900 border border-slate-800 rounded-3xl w-full max-w-5xl shadow-2xl overflow-hidden flex flex-col max-h-[85vh]">
            <!-- Header Modal -->
            <div class="p-5 bg-slate-950/90 border-b border-slate-800 flex flex-col sm:flex-row sm:items-center justify-between gap-4">
                <div class="flex items-center gap-3">
                    <div class="w-10 h-10 rounded-2xl bg-brand-500/10 border border-brand-500/30 text-brand-400 flex items-center justify-center text-lg font-bold">
                        <i class="fa-solid fa-list-check"></i>
                    </div>
                    <div>
                        <h3 id="modal-kpi-title" class="text-base font-extrabold text-white flex items-center gap-2">
                            Detalhamento de Chamadas
                        </h3>
                        <div class="flex items-center gap-2 text-xs text-slate-400 mt-0.5 font-mono">
                            <span>Período: <strong id="modal-kpi-period" class="text-slate-200">--</strong></span>
                            <span>·</span>
                            <span class="text-brand-400 font-bold"><strong id="modal-kpi-count">0</strong> chamadas encontradas</span>
                        </div>
                    </div>
                </div>

                <div class="flex items-center gap-3">
                    <!-- Busca rápida na tabela -->
                    <div class="relative">
                        <i class="fa-solid fa-magnifying-glass absolute left-3 top-2.5 text-slate-500 text-xs"></i>
                        <input type="text" id="kpi-modal-search" onkeyup="filterKpiModalTable()" placeholder="Buscar por número ou nome..." class="bg-slate-900 border border-slate-700 text-xs text-white pl-8 pr-3 py-1.5 rounded-xl focus:outline-none focus:border-brand-500 w-48 sm:w-64 font-medium">
                    </div>
                    <button onclick="closeKpiDetailModal()" class="w-9 h-9 rounded-xl bg-slate-800 hover:bg-slate-700 text-slate-400 hover:text-white flex items-center justify-center transition border border-slate-700">
                        <i class="fa-solid fa-xmark text-sm"></i>
                    </button>
                </div>
            </div>

            <!-- Conteúdo da Tabela de Ligações -->
            <div class="p-5 flex-1 overflow-y-auto custom-scrollbar bg-slate-950/40">
                <div class="overflow-x-auto">
                    <table class="w-full text-left text-xs font-semibold">
                        <thead class="bg-slate-950 uppercase text-[10px] tracking-wider text-slate-400 border-b border-slate-800">
                            <tr>
                                <th class="py-3 px-4">Data / Hora</th>
                                <th class="py-3 px-4">Origem</th>
                                <th class="py-3 px-4">Destino</th>
                                <th class="py-3 px-4 text-center">TMA (Conversa)</th>
                                <th class="py-3 px-4 text-center">TME (Espera)</th>
                                <th class="py-3 px-4 text-center">Status</th>
                                <th class="py-3 px-4 text-center">Gravação de Áudio</th>
                            </tr>
                        </thead>
                        <tbody id="kpi-calls-tbody" class="divide-y divide-slate-800/60 text-slate-200">
                            <tr>
                                <td colspan="7" class="py-12 text-center text-slate-500">
                                    <i class="fa-solid fa-spinner fa-spin text-brand-400 text-xl block mb-2"></i>
                                    Carregando extrato de chamadas do PABX...
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>

            <!-- Footer Modal com Atalho -->
            <div class="p-4 bg-slate-950/90 border-t border-slate-800 flex items-center justify-between">
                <a id="btn-kpi-go-to-cdr" href="index.php?module=relatorios&action=cdr_gravacoes" class="text-xs font-bold text-brand-400 hover:text-brand-300 transition flex items-center gap-1.5">
                    <i class="fa-solid fa-arrow-up-right-from-square"></i> Abrir Relatório CDR Completo com Gravações
                </a>
                <button onclick="closeKpiDetailModal()" class="px-5 py-2 bg-slate-800 hover:bg-slate-700 text-white rounded-xl text-xs font-bold transition border border-slate-700">
                    Fechar
                </button>
            </div>
        </div>
    </div>

    <!-- BLOCO 1: CARDS DE KPIS DE TELEFONIA & DEMANDAS (DADOS REAIS E CLICÁVEIS) -->
    <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-4">

        <!-- Card 1: Total de Atendimentos -->
        <div onclick="openKpiDetailModal('total')" title="Clique para ver a lista de todos os atendimentos no período" class="bg-slate-900/90 border border-slate-800 p-5 rounded-2xl shadow-xl flex flex-col justify-between space-y-3 hover:border-brand-500/50 hover:shadow-2xl hover:bg-slate-900 transition group cursor-pointer relative overflow-hidden transform hover:-translate-y-0.5">
            <div class="flex items-center justify-between">
                <div class="flex items-center gap-1.5 text-xs font-bold text-slate-300">
                    <span>Total de Atendimentos</span>
                    <i class="fa-solid fa-circle-info text-slate-500 text-[10px]" title="Soma total de atendimentos concluídos no período"></i>
                </div>
                <div class="w-8 h-8 rounded-xl bg-emerald-500/10 border border-emerald-500/20 text-emerald-400 flex items-center justify-center">
                    <i class="fa-regular fa-message text-xs"></i>
                </div>
            </div>
            <div>
                <span class="text-3xl font-black text-white tracking-tight font-mono"><?php echo number_format($total_atendidas_real, 0, ',', '.'); ?></span>
                <div class="text-xs font-bold mt-1 flex items-center justify-between">
                    <div><?php echo calcTrendBadge($total_atendidas_real, $prev_atendidas); ?></div>
                    <span class="text-[10px] text-brand-400 font-extrabold opacity-0 group-hover:opacity-100 transition flex items-center gap-1">Ver chamadas <i class="fa-solid fa-chevron-right text-[8px]"></i></span>
                </div>
            </div>
            <div class="h-8 w-full mt-2 -mb-1 opacity-80">
                <svg class="w-full h-full" viewBox="0 0 100 30" preserveAspectRatio="none">
                    <defs>
                        <linearGradient id="grad-blue-c1" x1="0" y1="0" x2="0" y2="1">
                            <stop offset="0%" stop-color="#3b82f6" stop-opacity="0.4"/>
                            <stop offset="100%" stop-color="#3b82f6" stop-opacity="0.0"/>
                        </linearGradient>
                    </defs>
                    <path d="M0 25 Q 25 28, 45 10 T 100 18 L 100 30 L 0 30 Z" fill="url(#grad-blue-c1)" />
                    <path d="M0 25 Q 25 28, 45 10 T 100 18" fill="none" stroke="#3b82f6" stroke-width="2" />
                </svg>
            </div>
        </div>

        <!-- Card 2: Demanda Ativa -->
        <div onclick="openKpiDetailModal('ativa')" title="Clique para ver a lista de chamadas efetuadas (ativas)" class="bg-slate-900/90 border border-slate-800 p-5 rounded-2xl shadow-xl flex flex-col justify-between space-y-3 hover:border-emerald-500/50 hover:shadow-2xl hover:bg-slate-900 transition group cursor-pointer relative overflow-hidden transform hover:-translate-y-0.5">
            <div class="flex items-center justify-between">
                <div class="flex items-center gap-1.5 text-xs font-bold text-slate-300">
                    <span>Demanda Ativa</span>
                    <i class="fa-solid fa-circle-info text-slate-500 text-[10px]" title="Chamadas efetuadas ativamente pelos operadores"></i>
                </div>
                <div class="w-8 h-8 rounded-xl bg-emerald-500/10 border border-emerald-500/20 text-emerald-400 flex items-center justify-center">
                    <i class="fa-solid fa-phone text-xs"></i>
                </div>
            </div>
            <div>
                <span class="text-3xl font-black text-white tracking-tight font-mono"><?php echo number_format($demanda_ativa_real, 0, ',', '.'); ?></span>
                <div class="text-xs font-bold mt-1 flex items-center justify-between">
                    <div><?php echo calcTrendBadge($demanda_ativa_real, $prev_ativa); ?></div>
                    <span class="text-[10px] text-emerald-400 font-extrabold opacity-0 group-hover:opacity-100 transition flex items-center gap-1">Ver chamadas <i class="fa-solid fa-chevron-right text-[8px]"></i></span>
                </div>
            </div>
            <div class="h-8 w-full mt-2 -mb-1 opacity-80">
                <svg class="w-full h-full" viewBox="0 0 100 30" preserveAspectRatio="none">
                    <defs>
                        <linearGradient id="grad-green-c2" x1="0" y1="0" x2="0" y2="1">
                            <stop offset="0%" stop-color="#10b981" stop-opacity="0.4"/>
                            <stop offset="100%" stop-color="#10b981" stop-opacity="0.0"/>
                        </linearGradient>
                    </defs>
                    <path d="M0 20 Q 30 22, 60 18 T 100 12 L 100 30 L 0 30 Z" fill="url(#grad-green-c2)" />
                    <path d="M0 20 Q 30 22, 60 18 T 100 12" fill="none" stroke="#10b981" stroke-width="2" />
                </svg>
            </div>
        </div>

        <!-- Card 3: Demanda Receptiva -->
        <div onclick="openKpiDetailModal('receptiva')" title="Clique para ver a lista de chamadas recebidas (receptivas)" class="bg-slate-900/90 border border-slate-800 p-5 rounded-2xl shadow-xl flex flex-col justify-between space-y-3 hover:border-purple-500/50 hover:shadow-2xl hover:bg-slate-900 transition group cursor-pointer relative overflow-hidden transform hover:-translate-y-0.5">
            <div class="flex items-center justify-between">
                <div class="flex items-center gap-1.5 text-xs font-bold text-slate-300">
                    <span>Demanda Receptiva</span>
                    <i class="fa-solid fa-circle-info text-slate-500 text-[10px]" title="Chamadas recebidas do público/clientes"></i>
                </div>
                <div class="w-8 h-8 rounded-xl bg-purple-500/10 border border-purple-500/20 text-purple-400 flex items-center justify-center">
                    <i class="fa-solid fa-user-plus text-xs"></i>
                </div>
            </div>
            <div>
                <span class="text-3xl font-black text-white tracking-tight font-mono"><?php echo number_format($demanda_receptiva_real, 0, ',', '.'); ?></span>
                <div class="text-xs font-bold mt-1 flex items-center justify-between">
                    <div><?php echo calcTrendBadge($demanda_receptiva_real, $prev_receptiva); ?></div>
                    <span class="text-[10px] text-purple-400 font-extrabold opacity-0 group-hover:opacity-100 transition flex items-center gap-1">Ver chamadas <i class="fa-solid fa-chevron-right text-[8px]"></i></span>
                </div>
            </div>
            <div class="h-8 w-full mt-2 -mb-1 opacity-80">
                <svg class="w-full h-full" viewBox="0 0 100 30" preserveAspectRatio="none">
                    <defs>
                        <linearGradient id="grad-purple-c3" x1="0" y1="0" x2="0" y2="1">
                            <stop offset="0%" stop-color="#a855f7" stop-opacity="0.4"/>
                            <stop offset="100%" stop-color="#a855f7" stop-opacity="0.0"/>
                        </linearGradient>
                    </defs>
                    <path d="M0 18 Q 40 24, 70 14 T 100 8 L 100 30 L 0 30 Z" fill="url(#grad-purple-c3)" />
                    <path d="M0 18 Q 40 24, 70 14 T 100 8" fill="none" stroke="#a855f7" stroke-width="2" />
                </svg>
            </div>
        </div>

        <!-- Card 4: TMA -->
        <div onclick="openKpiDetailModal('tma')" title="Clique para ver o extrato de chamadas do cálculo do Tempo Médio de Atendimento" class="bg-slate-900/90 border border-slate-800 p-5 rounded-2xl shadow-xl flex flex-col justify-between space-y-3 hover:border-amber-500/50 hover:shadow-2xl hover:bg-slate-900 transition group cursor-pointer relative overflow-hidden transform hover:-translate-y-0.5">
            <div class="flex items-center justify-between">
                <div class="flex items-center gap-1.5 text-xs font-bold text-slate-300">
                    <span>TMA</span>
                    <i class="fa-solid fa-circle-info text-slate-500 text-[10px]" title="Tempo Médio de Atendimento"></i>
                </div>
                <div class="w-8 h-8 rounded-xl bg-amber-500/10 border border-amber-500/20 text-amber-400 flex items-center justify-center">
                    <i class="fa-solid fa-clock text-xs"></i>
                </div>
            </div>
            <div>
                <span class="text-3xl font-black text-white tracking-tight font-mono"><?php echo formatSecondsToHuman($tma_medio_geral); ?></span>
                <div class="text-xs font-bold mt-1 flex items-center justify-between">
                    <div><?php echo calcTrendBadge($tma_medio_geral, $prev_tma); ?></div>
                    <span class="text-[10px] text-amber-400 font-extrabold opacity-0 group-hover:opacity-100 transition flex items-center gap-1">Ver chamadas <i class="fa-solid fa-chevron-right text-[8px]"></i></span>
                </div>
                <span class="text-[10px] text-slate-500 font-medium block mt-0.5">Tempo Médio de Atendimento</span>
            </div>
        </div>

        <!-- Card 5: TME -->
        <div onclick="openKpiDetailModal('tme')" title="Clique para ver o extrato de chamadas com Tempo Médio de Espera" class="bg-slate-900/90 border border-slate-800 p-5 rounded-2xl shadow-xl flex flex-col justify-between space-y-3 hover:border-cyan-500/50 hover:shadow-2xl hover:bg-slate-900 transition group cursor-pointer relative overflow-hidden transform hover:-translate-y-0.5">
            <div class="flex items-center justify-between">
                <div class="flex items-center gap-1.5 text-xs font-bold text-slate-300">
                    <span>TME</span>
                    <i class="fa-solid fa-circle-info text-slate-500 text-[10px]" title="Tempo Médio de Espera"></i>
                </div>
                <div class="w-8 h-8 rounded-xl bg-cyan-500/10 border border-cyan-500/20 text-cyan-400 flex items-center justify-center">
                    <i class="fa-solid fa-hourglass-half text-xs"></i>
                </div>
            </div>
            <div>
                <span class="text-3xl font-black text-white tracking-tight font-mono"><?php echo formatSecondsToHuman($tme_medio_geral); ?></span>
                <div class="text-xs font-bold mt-1 flex items-center justify-between">
                    <div><?php echo calcTrendBadge($tme_medio_geral, $prev_tme); ?></div>
                    <span class="text-[10px] text-cyan-400 font-extrabold opacity-0 group-hover:opacity-100 transition flex items-center gap-1">Ver chamadas <i class="fa-solid fa-chevron-right text-[8px]"></i></span>
                </div>
                <span class="text-[10px] text-slate-500 font-medium block mt-0.5">Tempo Médio de Espera</span>
            </div>
        </div>

        <!-- Card 6: TPR -->
        <div onclick="openKpiDetailModal('tpr')" title="Clique para ver a lista de chamadas com o Tempo de Primeira Resposta" class="bg-slate-900/90 border border-slate-800 p-5 rounded-2xl shadow-xl flex flex-col justify-between space-y-3 hover:border-indigo-500/50 hover:shadow-2xl hover:bg-slate-900 transition group cursor-pointer relative overflow-hidden transform hover:-translate-y-0.5">
            <div class="flex items-center justify-between">
                <div class="flex items-center gap-1.5 text-xs font-bold text-slate-300">
                    <span>TPR</span>
                    <i class="fa-solid fa-circle-info text-slate-500 text-[10px]" title="Tempo de Primeira Resposta (atendimento inicial)"></i>
                </div>
                <div class="w-8 h-8 rounded-xl bg-indigo-500/10 border border-indigo-500/20 text-indigo-400 flex items-center justify-center">
                    <i class="fa-solid fa-headset text-xs"></i>
                </div>
            </div>
            <div>
                <span class="text-3xl font-black text-white tracking-tight font-mono"><?php echo formatSecondsToHuman($tpr_medio_geral); ?></span>
                <div class="text-xs font-bold mt-1 flex items-center justify-between">
                    <div><?php echo calcTrendBadge($tpr_medio_geral, $prev_tpr); ?></div>
                    <span class="text-[10px] text-indigo-400 font-extrabold opacity-0 group-hover:opacity-100 transition flex items-center gap-1">Ver chamadas <i class="fa-solid fa-chevron-right text-[8px]"></i></span>
                </div>
                <span class="text-[10px] text-slate-500 font-medium block mt-0.5">Tempo de Primeira Resposta</span>
            </div>
        </div>

    </div>

    <!-- BLOCO 2: MONITORAMENTO DE FILAS REAIS + AUTOMAÇÃO WHATSAPP & NPS -->
    <div class="grid grid-cols-1 lg:grid-cols-4 gap-4">

        <!-- Filas Reais do Asterisk (Exibe as filas cadastradas no PABX) -->
        <?php if (!empty($real_queues)): ?>
            <?php foreach (array_slice($real_queues, 0, 2) as $rq): ?>
                <?php 
                $qnum = $rq['queue_num'];
                $rt_info = isset($queues_rt_map[$qnum]) ? $queues_rt_map[$qnum] : null;
                $callers_waiting_cnt = isset($rt_info['callers_waiting']) && is_array($rt_info['callers_waiting']) ? count($rt_info['callers_waiting']) : 0;
                $longest_wait_str = isset($rt_info['longest_wait']) && !empty($rt_info['longest_wait']) ? $rt_info['longest_wait'] : '0s';
                ?>
                <div title="Fila de atendimento de voz configurada no servidor Asterisk PABX" class="bg-slate-900/90 border border-slate-800 p-4 rounded-2xl shadow-xl flex flex-col justify-between space-y-3 hover:border-slate-700 transition">
                    <div class="flex items-center justify-between">
                        <h3 class="text-xs font-extrabold text-white uppercase tracking-wider flex items-center gap-2">
                            <i class="fa-solid fa-headset text-brand-400"></i> <?php echo htmlspecialchars(formatEndpointName($rq['queue_num'])); ?>
                        </h3>
                    </div>
                    <div>
                        <div class="grid grid-cols-2 gap-2 text-center my-1">
                            <div title="Número de chamadas telefônicas aguardando atendimento de um operador nesta fila neste momento" class="bg-slate-950 p-2 rounded-xl border border-slate-800">
                                <span class="text-xl font-black <?php echo $callers_waiting_cnt > 0 ? 'text-amber-400 animate-pulse' : 'text-slate-300'; ?> block font-mono"><?php echo $callers_waiting_cnt; ?></span>
                                <span class="text-[9px] text-slate-400 uppercase font-bold">Fila Espera</span>
                            </div>
                            <div title="Tempo da chamada que está há mais tempo aguardando na fila para ser atendida" class="bg-slate-950 p-2 rounded-xl border border-slate-800">
                                <span class="text-xl font-black text-cyan-400 block font-mono"><?php echo htmlspecialchars($longest_wait_str); ?></span>
                                <span class="text-[9px] text-slate-400 uppercase font-bold">Maior Espera</span>
                            </div>
                        </div>
                    </div>
                    <div class="pt-2 border-t border-slate-800/80 flex items-center justify-between text-[11px] font-bold text-slate-400">
                        <span>Fila de Atendimento</span>
                        <a href="index.php?module=filas&action=realtime" class="text-brand-400 hover:underline flex items-center gap-1">Visão ao Vivo <i class="fa-solid fa-chevron-right text-[8px]"></i></a>
                    </div>
                </div>
            <?php endforeach; ?>
        <?php else: ?>
            <!-- Caso não tenha filas ativas cadastradas no Asterisk -->
            <div title="Status das filas de atendimento telefônico receptivo da central" class="bg-slate-900/90 border border-slate-800 p-4 rounded-2xl shadow-xl flex flex-col justify-between space-y-3 lg:col-span-2">
                <div class="flex items-center justify-between">
                    <h3 class="text-xs font-extrabold text-white uppercase tracking-wider flex items-center gap-2">
                        <i class="fa-solid fa-headset text-brand-400"></i> Filas de Atendimento
                    </h3>
                </div>
                <div class="py-2">
                    <span class="text-xs font-bold text-slate-300 block">Nenhuma fila receptiva em atendimento no momento</span>
                    <span class="text-[11px] text-slate-500 block mt-1">As chamadas entram diretamente para os ramais dos operadores.</span>
                </div>
                <div class="pt-2 border-t border-slate-800/80 flex items-center justify-between text-[11px] font-bold">
                    <a href="index.php?module=filas&action=agentes" class="text-brand-400 hover:underline">Ver Gestão de Filas <i class="fa-solid fa-chevron-right text-[8px]"></i></a>
                </div>
            </div>
        <?php endif; ?>

        <!-- Card Disparos WhatsApp (Dados Reais do SQLite) -->
        <div class="bg-slate-900/90 border border-slate-800 p-4 rounded-2xl shadow-xl flex flex-col justify-between space-y-3">
            <div class="flex items-center justify-between">
                <span class="text-xs font-bold text-slate-400 uppercase tracking-wider flex items-center gap-1.5">
                    <i class="fa-brands fa-whatsapp text-emerald-400"></i> Automação WhatsApp
                </span>
                <span class="px-2 py-0.5 bg-emerald-500/20 text-emerald-300 border border-emerald-500/30 rounded text-[10px] font-bold">
                    <?php 
                    $stmt_tot = $db->query("SELECT COUNT(*) FROM sent_logs");
                    $tot_sent = $stmt_tot ? $stmt_tot->fetchColumn() : 0;
                    echo $tot_sent > 0 ? "Integrado" : "Sem Integração";
                    ?>
                </span>
            </div>
            <div>
                <?php if ($tot_sent > 0): ?>
                    <span class="text-2xl font-black text-emerald-400 tracking-tight font-mono"><?php echo number_format($tot_sent, 0, ',', '.'); ?></span>
                    <div class="text-[10px] font-bold text-slate-300 mt-1">
                        Notificações de voz e mensagens automáticas
                    </div>
                <?php else: ?>
                    <div class="py-1">
                        <span class="text-xs font-bold text-slate-400 block">Sem disparos ativados</span>
                        <span class="text-[10px] text-slate-500 block mt-0.5">Módulo WhatsApp/ZPRO não configurado.</span>
                    </div>
                <?php endif; ?>
            </div>
            <div class="pt-2 border-t border-slate-800/80 flex items-center justify-between text-[11px] font-bold">
                <a href="index.php?module=whatsapp&action=historico" class="text-brand-400 hover:text-brand-300 transition flex items-center gap-1">
                    Ver histórico <i class="fa-solid fa-chevron-right text-[8px]"></i>
                </a>
            </div>
        </div>

        <!-- Card Pesquisa de Satisfação NPS (Dados Reais do SQLite) -->
        <div class="bg-slate-900/90 border border-slate-800 p-4 rounded-2xl shadow-xl flex flex-col justify-between space-y-3">
            <div class="flex items-center justify-between">
                <span class="text-xs font-bold text-slate-400 uppercase tracking-wider flex items-center gap-1.5">
                    <i class="fa-solid fa-star text-amber-400"></i> Avaliação NPS
                </span>
                <span class="px-2 py-0.5 bg-amber-500/20 text-amber-300 border border-amber-500/30 rounded text-[10px] font-bold">Pesquisa</span>
            </div>
            <div>
                <?php
                $stmt_nps = $db->query("SELECT AVG(score) as avg_score, COUNT(*) as count_nps FROM nps_responses");
                $nps_data = $stmt_nps ? $stmt_nps->fetch(PDO::FETCH_ASSOC) : ['avg_score' => 0, 'count_nps' => 0];
                $avg_nps = round((float)($nps_data['avg_score'] ?: 0), 1);
                $count_nps = (int)($nps_data['count_nps'] ?: 0);
                ?>
                <span class="text-2xl font-black text-amber-400 tracking-tight font-mono"><?php echo $avg_nps; ?> <span class="text-xs font-semibold text-slate-400">/ 5.0</span></span>
                <div class="text-[10px] font-bold text-slate-300 mt-1 flex items-center gap-1">
                    <i class="fa-solid fa-thumbs-up text-amber-400"></i> <?php echo $count_nps; ?> <span class="text-slate-400 font-normal">avaliações</span>
                </div>
            </div>
            <div class="pt-2 border-t border-slate-800/80 flex items-center justify-between text-[11px] font-bold">
                <a href="index.php?module=whatsapp&action=regras" class="text-amber-400 hover:text-amber-300 transition flex items-center gap-1">
                    Regras NPS <i class="fa-solid fa-chevron-right text-[8px]"></i>
                </a>
            </div>
        </div>

    </div>

    <!-- BLOCO 3: GRAVAÇÕES CDR RECENTES COM PLAYER & BOTÃO DE IA SOB DEMANDA -->
    <div class="bg-slate-900/90 border border-slate-800 rounded-2xl shadow-xl overflow-hidden">
        <div class="p-5 border-b border-slate-800 flex items-center justify-between">
            <div>
                <h3 class="text-sm font-extrabold text-white flex items-center gap-2">
                    <i class="fa-solid fa-microphone text-brand-400"></i> Gravações do Asterisk CDR & Transcrição IA
                </h3>
                <span class="text-xs text-slate-400">Ouça os áudios gravados ou solicite a transcrição em texto via IA sob demanda</span>
            </div>
            <a href="index.php?module=relatorios&action=cdr_gravacoes" class="px-3 py-1 bg-slate-800 hover:bg-slate-700 text-slate-300 rounded-lg text-xs font-bold transition border border-slate-700">
                Ver Todas as Gravações
            </a>
        </div>

        <div class="overflow-x-auto">
            <table class="w-full text-left text-xs font-semibold">
                <thead class="bg-slate-950/80 text-slate-400 uppercase text-[10px] tracking-wider border-b border-slate-800">
                    <tr>
                        <th class="py-3 px-4">Data / Hora</th>
                        <th class="py-3 px-4">Origem</th>
                        <th class="py-3 px-4">Destino / Fila</th>
                        <th class="py-3 px-4 text-center">Status</th>
                        <th class="py-3 px-4 text-center">Duração</th>
                        <th class="py-3 px-4 text-center">Gravação de Áudio</th>
                        <th class="py-3 px-4 text-center">Ações IA (Sob Demanda)</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-800/60 text-slate-200">
                    <?php 
                    $contacts_map = getContactsMap();
                    foreach ($recent_calls as $c): 
                        $src_name = lookupContactName($c['src'], $contacts_map);
                        $dst_name = lookupContactName($c['dst'], $contacts_map);
                    ?>
                    <tr class="hover:bg-slate-800/40 transition">
                        <td class="py-3 px-4 font-mono text-slate-400"><?php echo date('d/m/Y H:i', strtotime($c['calldate'])); ?></td>
                        <td class="py-3 px-4">
                            <?php if ($src_name): ?>
                                <div class="font-bold text-white flex items-center gap-1"><i class="fa-solid fa-user text-emerald-400 text-[10px]"></i> <?php echo htmlspecialchars($src_name); ?></div>
                                <div class="text-[10px] text-slate-400 font-mono"><?php echo htmlspecialchars($c['src']); ?></div>
                            <?php else: ?>
                                <div class="font-bold text-white"><?php echo htmlspecialchars($c['src']); ?></div>
                            <?php endif; ?>
                        </td>
                        <td class="py-3 px-4">
                            <?php if ($dst_name): ?>
                                <div class="font-bold text-indigo-300 flex items-center gap-1"><i class="fa-solid fa-user text-indigo-400 text-[10px]"></i> <?php echo htmlspecialchars($dst_name); ?></div>
                                <div class="text-[10px] text-slate-400 font-mono"><?php echo htmlspecialchars($c['dst']); ?></div>
                            <?php else: ?>
                                <div class="font-bold text-slate-300"><?php echo htmlspecialchars($c['dst']); ?></div>
                            <?php endif; ?>
                        </td>
                        <td class="py-3 px-4 text-center">
                            <?php if ($c['disposition'] === 'ANSWERED'): ?>
                                <span class="px-2 py-0.5 bg-emerald-500/20 text-emerald-400 border border-emerald-500/30 rounded text-[10px] font-bold">ATENDIDA</span>
                            <?php else: ?>
                                <span class="px-2 py-0.5 bg-rose-500/20 text-rose-400 border border-rose-500/30 rounded text-[10px] font-bold">NÃO ATENDIDA</span>
                            <?php endif; ?>
                        </td>
                        <td class="py-3 px-4 text-center font-mono text-slate-300"><?php echo gmdate('i:s', $c['billsec']); ?></td>
                        <td class="py-3 px-4 text-center">
                            <audio controls class="h-7 w-48 inline-block opacity-90">
                                <source src="relatorio_filas.php?action=stream_audio&uid=<?php echo urlencode($c['uniqueid']); ?>" type="audio/wav">
                            </audio>
                        </td>
                        <td class="py-3 px-4 text-center">
                            <?php if (!empty($c['recordingfile'])): ?>
                                <button onclick="transcreverAudioReal(this, '<?php echo urlencode($c['recordingfile']); ?>', '<?php echo $c['uniqueid']; ?>')" 
                                        class="px-2.5 py-1 bg-purple-600/20 hover:bg-purple-600 text-purple-300 hover:text-white rounded border border-purple-500/30 text-[10px] font-bold transition flex items-center gap-1 mx-auto">
                                    <i class="fa-solid fa-wand-magic-sparkles"></i> Transcrever IA
                                </button>
                            <?php else: ?>
                                <span class="text-slate-600">-</span>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>

    <!-- BLOCO 4: TABELA DE DESEMPENHO DOS RAMAIS (DADOS REAIS DA BASE ASTERISK) -->
    <div class="bg-slate-900/90 border border-slate-800 rounded-2xl shadow-xl overflow-hidden">
        <div class="p-5 border-b border-slate-800 flex flex-col sm:flex-row sm:items-center justify-between gap-4">
            <div>
                <h3 class="text-sm font-extrabold text-white flex items-center gap-2">
                    <i class="fa-solid fa-users text-blue-400"></i> Desempenho dos Ramais Reais do PABX
                </h3>
                <span class="text-xs text-slate-400">Produtividade de voz individual de ramais cadastrados no sistema</span>
            </div>
            
            <div class="flex items-center gap-2">
                <!-- Botão Toggle de Mostrar Apenas com Ligações vs Mostrar Todos -->
                <?php if ($show_all_ramais): ?>
                    <a href="index.php?module=dashboard&action=view_v1&show_all_ramais=0" class="px-3 py-1.5 bg-brand-600/20 text-brand-300 border border-brand-500/30 rounded-xl text-xs font-bold transition hover:bg-brand-600/30 flex items-center gap-1.5">
                        <i class="fa-solid fa-filter text-[10px]"></i> Exibindo Todos os Ramais (Clique para filtrar zerados)
                    </a>
                <?php else: ?>
                    <a href="index.php?module=dashboard&action=view_v1&show_all_ramais=1" class="px-3 py-1.5 bg-slate-800 hover:bg-slate-700 text-slate-300 rounded-xl text-xs font-bold transition border border-slate-700 flex items-center gap-1.5">
                        <i class="fa-solid fa-eye text-[10px]"></i> Mostrar Todos os Ramais (Incluindo sem chamadas)
                    </a>
                <?php endif; ?>

                <span class="px-3 py-1.5 bg-slate-800 text-slate-300 rounded-xl text-xs font-bold border border-slate-700">
                    <?php echo count($users_performance); ?> Ramais
                </span>
            </div>
        </div>

        <div class="overflow-x-auto">
            <table class="w-full text-left text-xs font-semibold">
                <thead class="bg-slate-950/80 text-slate-400 uppercase text-[10px] tracking-wider border-b border-slate-800">
                    <tr>
                        <th title="Nome do operador cadastrado e o número do ramal PABX correspondente" class="py-3.5 px-4 cursor-help">Ramal / Operador</th>
                        <th title="Quantidade de chamadas recebidas que este ramal atendeu com sucesso" class="py-3.5 px-4 text-center cursor-help text-emerald-400">Atendidas (Receptivas)</th>
                        <th title="Quantidade de chamadas efetuadas por este ramal que completaram com sucesso" class="py-3.5 px-4 text-center cursor-help text-cyan-400">Efetuadas (Ativas)</th>
                        <th title="Soma total de chamadas recebidas e efetuadas (tentativas/interações) por este ramal" class="py-3.5 px-4 text-center cursor-help">Total de Chamadas</th>
                        <th title="Tempo Médio de Espera: Tempo que os clientes aguardaram até este operador atender" class="py-3.5 px-4 text-center cursor-help">TME (Espera)</th>
                        <th title="Tempo Médio de Atendimento: Duração média de conversa telefônica deste ramal" class="py-3.5 px-4 text-center cursor-help">TMA (Atendimento)</th>
                        <th title="Ação sob demanda para disparar análise da IA sobre o desempenho e satisfação do operador" class="py-3.5 px-4 text-center cursor-help">Análise de IA (Sob Demanda)</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-800/60 text-slate-200">
                    <?php if (empty($users_performance)): ?>
                    <tr>
                        <td colspan="7" class="py-8 text-center text-slate-500 font-medium">
                            Nenhum ramal com chamadas registradas no período selecionado.
                            <a href="index.php?module=dashboard&action=view_v1&show_all_ramais=1" class="text-brand-400 underline font-bold ml-1">Clique para ver todos os ramais</a>
                        </td>
                    </tr>
                    <?php else: ?>
                        <?php foreach ($users_performance as $u): ?>
                        <tr class="hover:bg-slate-800/40 transition">
                            <td class="py-3.5 px-4">
                                <div class="flex items-center gap-3">
                                    <div class="w-8 h-8 rounded-full bg-slate-800 border border-slate-700 flex items-center justify-center font-bold text-brand-400 text-xs uppercase">
                                        <?php echo htmlspecialchars(substr($u['name'], 0, 1)); ?>
                                    </div>
                                    <div>
                                        <div class="font-bold text-white"><?php echo htmlspecialchars($u['name']); ?></div>
                                        <div class="text-[10px] text-slate-400 font-mono">Ramal <?php echo htmlspecialchars($u['extension']); ?></div>
                                    </div>
                                </div>
                            </td>
                            <td class="py-3.5 px-4 text-center text-emerald-400 font-bold"><?php echo $u['atendidas_receptivas']; ?></td>
                            <td class="py-3.5 px-4 text-center text-cyan-400 font-bold"><?php echo $u['efetuadas_atendidas']; ?></td>
                            <td class="py-3.5 px-4 text-center font-black text-white"><?php echo $u['total']; ?></td>
                            <td class="py-3.5 px-4 text-center font-mono text-slate-300"><?php echo $u['tme']; ?></td>
                            <td class="py-3.5 px-4 text-center font-mono text-slate-300"><?php echo $u['tma']; ?></td>
                            <td class="py-3.5 px-4 text-center">
                                <button title="Clique para gerar relatório de inteligência artificial com NPS e análise de tom de voz deste operador" 
                                        onclick="openOperatorIaModal('<?php echo htmlspecialchars(addslashes($u['name'])); ?>', '<?php echo $u['extension']; ?>', <?php echo ($u['atendidas_receptivas'] + $u['efetuadas_atendidas']); ?>, <?php echo $u['total']; ?>, '<?php echo $u['tma']; ?>', '<?php echo $u['tme']; ?>')" 
                                        class="px-2.5 py-1 bg-purple-600/20 hover:bg-purple-600 text-purple-300 hover:text-white rounded-lg border border-purple-500/30 text-[10px] font-bold transition flex items-center gap-1.5 mx-auto">
                                    <i class="fa-solid fa-sparkles"></i> Analisar Operador
                                </button>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>

    <!-- BLOCO DE EVOLUÇÃO TEMPORAL DE VOZ COM LINHAS INTERATIVAS (CLICÁVEIS) -->
    <div class="bg-slate-900/90 border border-slate-800 p-5 rounded-2xl shadow-xl space-y-4">
        <div class="flex flex-col md:flex-row md:items-center justify-between gap-4">
            <div>
                <h3 class="text-sm font-extrabold text-white flex items-center gap-2">
                    <i class="fa-solid fa-chart-line text-blue-400"></i> Evolução das Ligações de Voz por Categoria
                </h3>
                <span class="text-xs text-slate-400">Clique nos botões para desenhar ou remover as linhas do gráfico em tempo real</span>
            </div>

            <!-- Botões Interativos para Alternar (Mostrar/Ocultar) as Linhas -->
            <div class="flex flex-wrap items-center gap-2 text-xs font-bold">
                <button title="Clique para exibir ou ocultar a linha verde de ligações receptivas recebidas" onclick="toggleChartDataset(0)" id="btn-ds-0" class="px-3 py-1.5 rounded-xl border border-emerald-500/40 bg-emerald-500/20 text-emerald-300 hover:bg-emerald-500/30 transition flex items-center gap-1.5">
                    <span class="w-2.5 h-2.5 rounded-full bg-emerald-400"></span> Entrada (Receptiva)
                </button>
                <button title="Clique para exibir ou ocultar a linha azul de ligações ativas efetuadas pelos operadores" onclick="toggleChartDataset(1)" id="btn-ds-1" class="px-3 py-1.5 rounded-xl border border-blue-500/40 bg-blue-500/20 text-blue-300 hover:bg-blue-500/30 transition flex items-center gap-1.5">
                    <span class="w-2.5 h-2.5 rounded-full bg-blue-400"></span> Saída (Ativa)
                </button>
                <button title="Clique para exibir ou ocultar a linha amarela de chamadas destinadas a números fixos" onclick="toggleChartDataset(2)" id="btn-ds-2" class="px-3 py-1.5 rounded-xl border border-amber-500/40 bg-amber-500/20 text-amber-300 hover:bg-amber-500/30 transition flex items-center gap-1.5">
                    <span class="w-2.5 h-2.5 rounded-full bg-amber-400"></span> Fixo
                </button>
                <button title="Clique para exibir ou ocultar a linha roxa de chamadas destinadas a celulares/móveis" onclick="toggleChartDataset(3)" id="btn-ds-3" class="px-3 py-1.5 rounded-xl border border-purple-500/40 bg-purple-500/20 text-purple-300 hover:bg-purple-500/30 transition flex items-center gap-1.5">
                    <span class="w-2.5 h-2.5 rounded-full bg-purple-400"></span> Móvel
                </button>
            </div>
        </div>

        <div class="relative h-72 w-full">
            <canvas id="chartEvolucaoVoz"></canvas>
        </div>
    </div>

</div>

<!-- CHART.JS SCRIPTS & LÓGICA DE MOSTRAR/OCULTAR LINHAS -->
<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
<script>
let chartVozInstance = null;

document.addEventListener("DOMContentLoaded", function() {
    const ctx = document.getElementById('chartEvolucaoVoz').getContext('2d');

    chartVozInstance = new Chart(ctx, {
        type: 'line',
        data: {
            labels: <?php echo json_encode($chart_labels); ?>,
            datasets: [
                {
                    label: 'Ligação de Entrada',
                    data: <?php echo json_encode($chart_inc); ?>,
                    borderColor: '#10b981',
                    backgroundColor: 'rgba(16, 185, 129, 0.1)',
                    borderWidth: 3,
                    tension: 0.35,
                    pointRadius: 4,
                    fill: false
                },
                {
                    label: 'Ligação de Saída',
                    data: <?php echo json_encode($chart_out); ?>,
                    borderColor: '#3b82f6',
                    backgroundColor: 'rgba(59, 130, 246, 0.1)',
                    borderWidth: 3,
                    tension: 0.35,
                    pointRadius: 4,
                    fill: false
                },
                {
                    label: 'Fixo',
                    data: <?php echo json_encode($chart_fixo); ?>,
                    borderColor: '#f59e0b',
                    backgroundColor: 'rgba(245, 158, 11, 0.1)',
                    borderWidth: 2.5,
                    borderDash: [5, 5],
                    tension: 0.35,
                    pointRadius: 3,
                    fill: false
                },
                {
                    label: 'Móvel',
                    data: <?php echo json_encode($chart_movel); ?>,
                    borderColor: '#a855f7',
                    backgroundColor: 'rgba(168, 85, 247, 0.1)',
                    borderWidth: 2.5,
                    borderDash: [2, 2],
                    tension: 0.35,
                    pointRadius: 3,
                    fill: false
                }
            ]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            plugins: {
                legend: { display: false } // Usamos nossos botões customizados acima
            },
            scales: {
                x: {
                    grid: { color: 'rgba(255, 255, 255, 0.05)' },
                    ticks: { color: '#94a3b8', font: { size: 10 } }
                },
                y: {
                    grid: { color: 'rgba(255, 255, 255, 0.05)' },
                    ticks: { color: '#94a3b8', font: { size: 10 } },
                    beginAtZero: true
                }
            }
        }
    });
});

// Função para desenhar ou remover a linha do gráfico ao clicar no botão
function toggleChartDataset(index) {
    if (!chartVozInstance) return;
    
    const isVisible = chartVozInstance.isDatasetVisible(index);
    chartVozInstance.setDatasetVisibility(index, !isVisible);
    chartVozInstance.update();

    const btn = document.getElementById('btn-ds-' + index);
    if (btn) {
        if (isVisible) {
            btn.classList.add('opacity-40', 'line-through');
        } else {
            btn.classList.remove('opacity-40', 'line-through');
        }
    }
}

// ─── Disparo de Análise de Insights de IA em Tempo Real (Design ZPRO) ───────
async function triggerAiInsightsAnalysis() {
    const btn = document.getElementById('btn-trigger-ai-analysis');
    const badge = document.getElementById('ai-last-updated-badge');
    const timeSpan = document.getElementById('ai-last-updated-time');
    const subDesc = document.getElementById('ai-sub-description');
    const container = document.getElementById('ai-insights-container');
    const cardsDiv = document.getElementById('ai-insights-cards');

    if (!btn) return;

    const origHTML = btn.innerHTML;
    btn.innerHTML = '<i class="fa-solid fa-spinner fa-spin text-purple-300"></i> <span>Analisando...</span>';
    btn.disabled = true;

    try {
        const total = <?php echo (int)$total_chamadas_real; ?>;
        const atendidas = <?php echo (int)$total_atendidas_real; ?>;
        const tmaSec = <?php echo (int)$tma_medio_geral; ?>;
        const tmeSec = <?php echo (int)$tme_medio_geral; ?>;

        const res = await fetch('index.php?api_action=analyze_pabx_insights', {
            method: 'POST',
            headers: {'Content-Type': 'application/json'},
            body: JSON.stringify({ total, atendidas, tmaSec, tmeSec })
        });
        const data = await res.json();

        if (data.insights && Array.isArray(data.insights) && data.insights.length > 0) {
            const nowStr = new Date().toLocaleTimeString('pt-BR', {hour: '2-digit', minute:'2-digit'});
            if (timeSpan) timeSpan.textContent = nowStr;
            if (badge) badge.classList.remove('hidden');
            if (subDesc) subDesc.classList.add('hidden');

            if (container) {
                // Banner de aviso quando a IA não está ativa
                let bannerHTML = '';
                if (!data.ai_configured) {
                    bannerHTML = `
                        <div class="bg-amber-500/10 border border-amber-500/30 rounded-xl p-3.5 text-amber-300 text-xs font-semibold flex flex-col sm:flex-row items-start sm:items-center justify-between gap-3 mb-3">
                            <div class="flex items-center gap-2.5">
                                <div class="w-8 h-8 rounded-xl bg-amber-500/20 text-amber-400 flex items-center justify-center flex-shrink-0 text-sm">
                                    <i class="fa-solid fa-triangle-exclamation"></i>
                                </div>
                                <div>
                                    <strong class="text-amber-200 font-extrabold block">Aviso: Inteligência Artificial (LLM) Desativada</strong>
                                    <span class="text-slate-300 text-[11px]">Esta análise foi gerada automaticamente pelo <strong>Motor Operacional do PABX IP</strong>. Para respostas generativas com IA, integre o Groq ou OpenAI.</span>
                                </div>
                            </div>
                            <a href="index.php?module=configuracoes&action=ia" class="px-3 py-1.5 bg-amber-500/20 hover:bg-amber-500/30 text-amber-200 border border-amber-500/40 rounded-xl text-xs font-extrabold whitespace-nowrap transition flex items-center gap-1.5 shadow-sm">
                                <i class="fa-solid fa-plug text-[10px]"></i> Ativar IA no Painel
                            </a>
                        </div>
                    `;
                } else {
                    bannerHTML = `
                        <div class="bg-purple-500/10 border border-purple-500/30 rounded-xl p-3 text-purple-300 text-xs font-semibold flex items-center justify-between gap-2 mb-3">
                            <div class="flex items-center gap-2">
                                <i class="fa-solid fa-wand-magic-sparkles text-purple-400"></i>
                                <span><strong>IA Conectada:</strong> Análise preditiva gerada em tempo real por Inteligência Artificial.</span>
                            </div>
                            <span class="px-2.5 py-0.5 bg-purple-500/20 text-purple-300 border border-purple-500/30 rounded-full text-[10px] font-extrabold uppercase">
                                Resposta LLM
                            </span>
                        </div>
                    `;
                }

                container.innerHTML = bannerHTML + '<div class="grid grid-cols-1 md:grid-cols-3 gap-3" id="ai-insights-cards"></div>';
                const cardsDiv = document.getElementById('ai-insights-cards');

                if (cardsDiv) {
                    const icons = ['fa-chart-line', 'fa-thumbs-up', 'fa-lightbulb'];
                    const colors = ['purple', 'emerald', 'amber'];

                    data.insights.forEach((item, idx) => {
                        const c = colors[idx % colors.length];
                        const ic = icons[idx % icons.length];
                        const titleLabel = data.ai_configured ? `Insight IA ${idx + 1}` : `Insight PABX ${idx + 1}`;
                        const engineTag  = data.ai_configured ? 'LLM' : 'Métricas PABX';

                        const card = document.createElement('div');
                        card.className = `bg-slate-950/80 border border-${c}-500/20 rounded-2xl p-4 space-y-1.5 shadow`;
                        card.innerHTML = `
                            <div class="font-extrabold text-${c}-300 text-xs flex items-center justify-between">
                                <span class="flex items-center gap-2"><i class="fa-solid ${ic}"></i> ${titleLabel}</span>
                                <span class="text-[9px] px-1.5 py-0.5 rounded bg-slate-900 border border-slate-700 text-slate-400 font-mono">${engineTag}</span>
                            </div>
                            <p class="text-slate-300 text-xs leading-relaxed">${item}</p>
                        `;
                        cardsDiv.appendChild(card);
                    });
                }
                container.classList.remove('hidden');
            }

            if (typeof showIaToast === 'function') {
                const toastMsg = data.ai_configured ? 'Insights de IA atualizados com LLM real!' : 'Análise de métricas gerada pelo Motor PABX (IA inativa)';
                showIaToast(toastMsg);
            }
        } else if (data.error) {
            if (typeof showIaToast === 'function') showIaToast(data.error, false);
            else alert('⚠️ ' + data.error);
        }
    } catch(e) {
        if (typeof showIaToast === 'function') showIaToast('Erro na análise de insights: ' + e.message, false);
        else alert('❌ Erro na requisição: ' + e.message);
    } finally {
        btn.innerHTML = origHTML;
        btn.disabled = false;
    }
}

// ─── Modal de Histórico de Análises de IA ────────────────────────────────────
async function openAiHistoryModal() {
    const modal = document.getElementById('modal-ai-history');
    const list = document.getElementById('ai-history-list');
    if (!modal || !list) return;

    list.innerHTML = '<div class="text-center text-slate-400 text-xs py-8"><i class="fa-solid fa-spinner fa-spin text-purple-400 text-lg mb-2 block"></i>Carregando histórico...</div>';
    modal.classList.remove('hidden');

    try {
        const res = await fetch('index.php?api_action=get_ai_insights_history');
        const data = await res.json();

        if (data.success && data.history) {
            if (data.history.length === 0) {
                list.innerHTML = '<div class="text-center text-slate-500 text-xs py-8">Nenhum histórico registrado ainda. Clique em "Analisar" para gerar o primeiro insight!</div>';
                return;
            }

            list.innerHTML = '';
            data.history.forEach(item => {
                const row = document.createElement('div');
                row.className = 'p-4 bg-slate-950 border border-slate-800 rounded-2xl space-y-2.5 relative group hover:border-purple-500/30 transition';
                
                let bulletsHTML = '';
                if (Array.isArray(item.insights)) {
                    item.insights.forEach(ins => {
                        bulletsHTML += `<li class="flex items-start gap-2 text-slate-300 text-xs"><span class="text-purple-400 mt-1">•</span> <span>${ins}</span></li>`;
                    });
                }

                const insightsJsonEscaped = JSON.stringify(item.insights).replace(/'/g, "&apos;").replace(/"/g, "&quot;");
                row.innerHTML = `
                    <div class="flex items-center justify-between">
                        <div>
                            <span class="font-extrabold text-white text-xs block">${item.created_at_fmt}</span>
                            <span class="text-[10px] text-slate-500 font-mono">⚙️ ${item.model || 'gpt-4o-mini'} · ${item.provider || 'openai'}</span>
                        </div>
                        <div class="flex items-center gap-2">
                            <button onclick="loadInsightFromHistory('${item.id}', this)" data-insights='${insightsJsonEscaped}' data-time='${item.created_at_fmt}' class="btn-load-insight text-xs font-bold text-brand-400 hover:underline">Abrir</button>
                            <button onclick="deleteAiHistoryItem(${item.id}, this)" class="text-slate-500 hover:text-rose-400 text-xs p-1 transition" title="Excluir"><i class="fa-solid fa-trash"></i></button>
                        </div>
                    </div>
                    <ul class="space-y-1 mt-1 pl-1">${bulletsHTML}</ul>
                `;
                list.appendChild(row);
            });
        }
    } catch(e) {
        list.innerHTML = '<div class="text-center text-rose-400 text-xs py-8">Erro ao carregar histórico: ' + e.message + '</div>';
    }
}

function closeAiHistoryModal() {
    const modal = document.getElementById('modal-ai-history');
    if (modal) modal.classList.add('hidden');
}

function loadInsightFromHistory(id, btn) {
    closeAiHistoryModal();
    if (!btn) return;
    
    let insightsArray = [];
    try {
        insightsArray = JSON.parse(btn.getAttribute('data-insights') || '[]');
    } catch(e) {}
    
    const timeStr = btn.getAttribute('data-time') || '';
    const badge = document.getElementById('ai-last-updated-badge');
    const timeSpan = document.getElementById('ai-last-updated-time');
    const subDesc = document.getElementById('ai-sub-description');
    const container = document.getElementById('ai-insights-container');
    const cardsDiv = document.getElementById('ai-insights-cards');

    if (timeSpan && badge) {
        timeSpan.textContent = timeStr;
        badge.classList.remove('hidden');
    }
    if (subDesc) subDesc.classList.add('hidden');

    if (cardsDiv && container) {
        cardsDiv.innerHTML = '';
        const icons = ['fa-chart-line', 'fa-thumbs-up', 'fa-lightbulb'];
        const colors = ['purple', 'emerald', 'amber'];

        insightsArray.forEach((item, idx) => {
            const c = colors[idx % colors.length];
            const ic = icons[idx % icons.length];
            const card = document.createElement('div');
            card.className = `bg-slate-950/80 border border-${c}-500/20 rounded-2xl p-4 space-y-1.5 shadow`;
            card.innerHTML = `
                <div class="font-extrabold text-${c}-300 text-xs flex items-center gap-2">
                    <i class="fa-solid ${ic}"></i> Insight ${idx + 1}
                </div>
                <p class="text-slate-300 text-xs leading-relaxed">${item}</p>
            `;
            cardsDiv.appendChild(card);
        });
        container.classList.remove('hidden');
    }
}

async function deleteAiHistoryItem(id, btn) {
    if (!confirm('Deseja excluir esta análise do histórico?')) return;
    try {
        await fetch('index.php?api_action=delete_ai_insight&id=' + id);
        const card = btn.closest('.relative');
        if (card) card.remove();
    } catch(e) {}
}

// ─── Transcrição de Áudio Real via OpenAI Whisper ───────────────────────────
async function transcreverAudioReal(btn, recordingFile, uniqueId) {
    if (!btn || !recordingFile) return;

    const origHTML = btn.innerHTML;
    btn.innerHTML = '<i class="fa-solid fa-spinner fa-spin text-purple-400"></i> Transcrevendo...';
    btn.disabled = true;

    try {
        const res = await fetch('index.php?api_action=transcribe_audio', {
            method: 'POST',
            headers: {'Content-Type': 'application/json'},
            body: JSON.stringify({ file: decodeURIComponent(recordingFile) })
        });

        const data = await res.json();
        if (data.success) {
            showTranscriptionModal(uniqueId, data.text);
        } else {
            showIaToast(data.error || 'Erro na transcrição', false);
        }
    } catch(e) {
        alert('❌ Falha na requisição de transcrição: ' + e.message);
    } finally {
        btn.innerHTML = origHTML;
        btn.disabled = false;
    }
}
function switchViewTab(tabName) {
    const btnFila = document.getElementById('tab-btn-fila');
    const btnStatus = document.getElementById('tab-btn-status');
    const btnCanal = document.getElementById('tab-btn-canal');

    const activeCls = "px-4 py-2 rounded-xl text-xs font-extrabold transition bg-brand-600 text-white shadow-lg shadow-brand-600/30 flex items-center gap-2";
    const inactiveCls = "px-4 py-2 rounded-xl text-xs font-bold transition bg-slate-900 hover:bg-slate-800 text-slate-400 hover:text-white border border-slate-800 flex items-center gap-2";

    if (btnFila) btnFila.className = tabName === 'fila' ? activeCls : inactiveCls;
    if (btnStatus) btnStatus.className = tabName === 'status' ? activeCls : inactiveCls;
    if (btnCanal) btnCanal.className = tabName === 'canal' ? activeCls : inactiveCls;

    if (typeof showIaToast === 'function') {
        showIaToast("Visão alternada para: Por " + tabName.charAt(0).toUpperCase() + tabName.slice(1));
    }
}

// ─── Modal de Detalhamento de Ligações dos KPI Cards ───────────────────────
async function openKpiDetailModal(kpiType) {
    const modal = document.getElementById('modal-kpi-calls-detail');
    const titleEl = document.getElementById('modal-kpi-title');
    const periodEl = document.getElementById('modal-kpi-period');
    const countEl = document.getElementById('modal-kpi-count');
    const tbody = document.getElementById('kpi-calls-tbody');
    const searchInput = document.getElementById('kpi-modal-search');
    const cdrLink = document.getElementById('btn-kpi-go-to-cdr');

    if (!modal || !tbody) return;

    if (searchInput) searchInput.value = '';
    tbody.innerHTML = `
        <tr>
            <td colspan="7" class="py-12 text-center text-slate-500 font-sans">
                <i class="fa-solid fa-spinner fa-spin text-brand-400 text-2xl block mb-3 mx-auto"></i>
                Consultando chamadas reais do Asterisk PABX...
            </td>
        </tr>
    `;
    modal.classList.remove('hidden');

    const startDate = '<?php echo htmlspecialchars($start_date); ?>';
    const endDate   = '<?php echo htmlspecialchars($end_date); ?>';

    if (cdrLink) {
        cdrLink.href = `index.php?module=relatorios&action=cdr_gravacoes&start_date=${startDate}&end_date=${endDate}`;
    }

    try {
        const url = `index.php?api_action=get_kpi_calls_detail&kpi_type=${encodeURIComponent(kpiType)}&start_date=${startDate}&end_date=${endDate}`;
        const res = await fetch(url);
        const data = await res.json();

        if (data.success) {
            if (titleEl) titleEl.innerHTML = `<i class="fa-solid fa-phone-volume text-brand-400"></i> ${data.title}`;
            if (periodEl) periodEl.textContent = data.period;
            if (countEl) countEl.textContent = data.count;

            if (data.calls.length === 0) {
                tbody.innerHTML = `
                    <tr>
                        <td colspan="7" class="py-12 text-center text-slate-500 font-sans">
                            Nenhuma chamada registrada para este indicador no período selecionado.
                        </td>
                    </tr>
                `;
                return;
            }

            tbody.innerHTML = '';
            data.calls.forEach(c => {
                const tr = document.createElement('tr');
                tr.className = 'hover:bg-slate-800/50 transition kpi-modal-row';

                let dispBadge = 'bg-emerald-500/20 text-emerald-300 border-emerald-500/30';
                let dispLabel = 'Atendida';
                const disp = (c.disposition || '').toUpperCase();
                if (disp === 'NO ANSWER') { dispBadge = 'bg-rose-500/20 text-rose-300 border-rose-500/30'; dispLabel = 'Não Atendeu'; }
                if (disp === 'BUSY') { dispBadge = 'bg-amber-500/20 text-amber-300 border-amber-500/30'; dispLabel = 'Ocupado'; }
                if (disp === 'FAILED') { dispBadge = 'bg-slate-800 text-slate-400 border-slate-700'; dispLabel = 'Falhou'; }

                const srcDisplay = c.src_name ? `<span class="font-extrabold text-white"><i class="fa-solid fa-user text-emerald-400 text-[10px] mr-1"></i>${c.src_name}</span><div class="text-[10px] text-slate-400 font-mono">${c.src}</div>` : `<span class="font-bold text-white font-mono">${c.src}</span>`;
                const dstDisplay = c.dst_name ? `<span class="font-extrabold text-indigo-300"><i class="fa-solid fa-user text-indigo-400 text-[10px] mr-1"></i>${c.dst_name}</span><div class="text-[10px] text-slate-400 font-mono">${c.dst}</div>` : `<span class="font-bold text-indigo-300 font-mono">${c.dst}</span>`;

                let audioHTML = '<span class="text-slate-600 font-mono text-[10px]">-</span>';
                if (c.uniqueid) {
                    audioHTML = `
                        <audio controls class="h-6 w-44 inline-block opacity-90">
                            <source src="relatorio_filas.php?action=stream_audio&uid=${encodeURIComponent(c.uniqueid)}" type="audio/wav">
                        </audio>
                    `;
                }

                tr.innerHTML = `
                    <td class="py-3 px-4 font-mono text-slate-400 whitespace-nowrap">${c.calldate_fmt}</td>
                    <td class="py-3 px-4">${srcDisplay}</td>
                    <td class="py-3 px-4">${dstDisplay}</td>
                    <td class="py-3 px-4 text-center font-mono text-white font-bold">${c.billsec_fmt}</td>
                    <td class="py-3 px-4 text-center font-mono text-slate-400">${c.tme_fmt}</td>
                    <td class="py-3 px-4 text-center">
                        <span class="px-2 py-0.5 rounded text-[9px] font-extrabold border ${dispBadge}">${dispLabel}</span>
                    </td>
                    <td class="py-3 px-4 text-center">${audioHTML}</td>
                `;
                tbody.appendChild(tr);
            });
        }
    } catch(e) {
        tbody.innerHTML = `
            <tr>
                <td colspan="7" class="py-12 text-center text-rose-400 font-sans">
                    Erro ao carregar ligações: ${e.message}
                </td>
            </tr>
        `;
    }
}

function closeKpiDetailModal() {
    const modal = document.getElementById('modal-kpi-calls-detail');
    if (modal) modal.classList.add('hidden');
}

function filterKpiModalTable() {
    const query = (document.getElementById('kpi-modal-search').value || '').toLowerCase();
    const rows = document.querySelectorAll('.kpi-modal-row');
    rows.forEach(row => {
        const text = row.textContent.toLowerCase();
        row.style.display = text.includes(query) ? '' : 'none';
    });
}
</script>
