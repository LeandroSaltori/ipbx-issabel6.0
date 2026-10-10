<?php
/**
 * IPbx Prisma - Central de Logs, Diagnósticos & Rastreabilidade v1.0
 * Interface gráfica moderna para inspeção de erros em tempo real, download e limpeza de logs.
 */

if (!function_exists('get_pabx_log_categories')) {
    require_once __DIR__ . '/../../includes/logger.php';
}

$msg = '';
$msg_type = 'success';

$current_cat   = isset($_GET['cat']) ? preg_replace('/[^a-zA-Z0-9_]/', '', $_GET['cat']) : LOG_CAT_AUDIT;
$level_filter  = isset($_GET['level']) ? strtoupper(trim($_GET['level'])) : 'ALL';
$search_query  = isset($_GET['q']) ? trim($_GET['q']) : '';

// ─── Ação: Download de Arquivo de Log ────────────────────────────────────────
if (isset($_GET['download_log']) && $_GET['download_log'] === '1') {
    $cleanCat = preg_replace('/[^a-zA-Z0-9_]/', '', $_GET['cat'] ?? 'system_errors');
    $filePath = PABX_LOG_DIR . '/' . $cleanCat . '.log';
    if (file_exists($filePath)) {
        header('Content-Type: text/plain; charset=utf-8');
        header('Content-Disposition: attachment; filename="pabx_log_' . $cleanCat . '_' . date('Y-m-d_H-i') . '.log"');
        header('Content-Length: ' . filesize($filePath));
        readfile($filePath);
        exit;
    }
}

// ─── Ação: Limpar Log ────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action_clear_log'])) {
    $targetCat = preg_replace('/[^a-zA-Z0-9_]/', '', $_POST['target_cat'] ?? '');
    if (clear_pabx_log_file($targetCat)) {
        $msg = "O arquivo de log '{$targetCat}' foi limpo com sucesso!";
    } else {
        $msg = "Não foi possível limpar o log especificado.";
        $msg_type = 'error';
    }
}

$categories = get_pabx_log_categories();
if (!isset($categories[$current_cat])) {
    $current_cat = LOG_CAT_SYSTEM;
}

$logEntries = read_pabx_log_entries($current_cat, 400, $level_filter, $search_query);
$activeCategoryInfo = $categories[$current_cat];
?>

<div class="space-y-6">
    <?php if (!empty($msg)): ?>
    <div class="p-4 rounded-xl <?php echo $msg_type === 'error' ? 'bg-rose-500/10 text-rose-300 border-rose-500/30' : 'bg-emerald-500/10 text-emerald-300 border-emerald-500/30'; ?> border text-xs font-bold flex items-center gap-2">
        <i class="fa-solid fa-circle-check"></i> <?php echo htmlspecialchars($msg); ?>
    </div>
    <?php endif; ?>

    <!-- Header Principal -->
    <div class="bg-slate-900/90 border border-slate-800 rounded-2xl p-6 shadow-xl space-y-4">
        <div class="flex flex-col md:flex-row items-start md:items-center justify-between gap-4">
            <div>
                <h3 class="text-lg font-extrabold text-white flex items-center gap-2.5">
                    <i class="fa-solid fa-terminal text-brand-400 text-xl"></i> Central de Logs, Diagnósticos & Escalabilidade
                </h3>
                <p class="text-xs text-slate-400 mt-1">Inspeção técnica em tempo real para rastreabilidade de erros, conexões SMTP, API REST, AMI e chamadas de IA</p>
            </div>

            <div class="flex items-center gap-2 flex-wrap">
                <a href="index.php?module=configuracoes&action=logs&cat=<?php echo urlencode($current_cat); ?>&download_log=1"
                   class="px-3.5 py-2 bg-slate-800 hover:bg-slate-700 text-slate-200 border border-slate-700 rounded-xl text-xs font-bold transition flex items-center gap-1.5 shadow">
                    <i class="fa-solid fa-download"></i> Baixar Arquivo .log
                </a>

                <form method="POST" onsubmit="return confirm('Tem certeza que deseja apagar o histórico de log desta categoria?');" class="inline-block">
                    <input type="hidden" name="action_clear_log" value="1">
                    <input type="hidden" name="target_cat" value="<?php echo htmlspecialchars($current_cat); ?>">
                    <button type="submit" class="px-3.5 py-2 bg-rose-600/10 hover:bg-rose-600/20 text-rose-400 border border-rose-500/30 rounded-xl text-xs font-bold transition flex items-center gap-1.5">
                        <i class="fa-solid fa-trash-can"></i> Limpar Log
                    </button>
                </form>
            </div>
        </div>

        <!-- Cards das Categorias de Log -->
        <div class="grid grid-cols-2 sm:grid-cols-4 md:grid-cols-4 lg:grid-cols-8 gap-2.5 pt-2">
            <?php foreach ($categories as $catKey => $catData): ?>
                <?php $isActive = ($catKey === $current_cat); ?>
                <a href="index.php?module=configuracoes&action=logs&cat=<?php echo urlencode($catKey); ?>"
                   class="p-3 rounded-xl border text-left transition flex flex-col justify-between <?php echo $isActive ? 'bg-brand-600/20 border-brand-500/50 text-white shadow-lg' : 'bg-slate-950/60 border-slate-800 text-slate-400 hover:border-slate-700 hover:text-slate-200'; ?>">
                    <div class="flex items-center justify-between">
                        <i class="<?php echo $catData['icon']; ?> text-sm <?php echo $isActive ? 'text-brand-400' : 'text-slate-500'; ?>"></i>
                        <span class="text-[9px] font-mono font-bold px-1.5 py-0.5 rounded bg-slate-900 border border-slate-800 text-slate-400">
                            <?php echo $catData['size_formatted']; ?>
                        </span>
                    </div>
                    <div class="mt-2">
                        <span class="text-[11px] font-bold block truncate" title="<?php echo htmlspecialchars($catData['title']); ?>">
                            <?php echo htmlspecialchars($catData['title']); ?>
                        </span>
                    </div>
                </a>
            <?php endforeach; ?>
        </div>
    </div>

    <!-- Barra de Filtros e Busca de Log -->
    <div class="bg-slate-900/90 border border-slate-800 rounded-2xl p-4 shadow-xl flex flex-col md:flex-row items-center justify-between gap-3">
        <div class="flex items-center gap-2 text-xs text-white font-bold">
            <i class="<?php echo $activeCategoryInfo['icon']; ?> text-brand-400"></i>
            <span>Visualizando: <?php echo htmlspecialchars($activeCategoryInfo['title']); ?></span>
            <span class="text-slate-500 font-mono text-[11px]">(<?php echo count($logEntries); ?> registros exibidos)</span>
        </div>

        <form method="GET" action="index.php" class="flex flex-wrap items-center gap-2 text-xs w-full md:w-auto">
            <input type="hidden" name="module" value="configuracoes">
            <input type="hidden" name="action" value="logs">
            <input type="hidden" name="cat" value="<?php echo htmlspecialchars($current_cat); ?>">

            <select name="level" onchange="this.form.submit()" class="px-3 py-1.5 bg-slate-950 border border-slate-800 rounded-xl text-white font-mono focus:border-brand-500 focus:outline-none cursor-pointer">
                <option value="ALL" <?php echo $level_filter === 'ALL' ? 'selected' : ''; ?>>● Todos os Níveis</option>
                <option value="ERROR" <?php echo $level_filter === 'ERROR' ? 'selected' : ''; ?>>🔴 Apenas ERROS / CRITICAL</option>
                <option value="WARNING" <?php echo $level_filter === 'WARNING' ? 'selected' : ''; ?>>🟡 Apenas AVISOS (WARNING)</option>
                <option value="INFO" <?php echo $level_filter === 'INFO' ? 'selected' : ''; ?>>🟢 Apenas INFO</option>
            </select>

            <div class="relative flex-1 md:w-64">
                <input type="text" name="q" value="<?php echo htmlspecialchars($search_query); ?>" placeholder="Buscar palavra-chave..."
                       class="w-full pl-8 pr-3 py-1.5 bg-slate-950 border border-slate-800 rounded-xl text-white placeholder-slate-600 focus:border-brand-500 focus:outline-none">
                <i class="fa-solid fa-magnifying-glass absolute left-2.5 top-2.5 text-slate-500"></i>
            </div>

            <button type="submit" class="px-3.5 py-1.5 bg-brand-600 hover:bg-brand-500 text-white rounded-xl font-bold transition flex items-center gap-1">
                <i class="fa-solid fa-filter"></i> Filtrar
            </button>
        </form>
    </div>

    <!-- Terminal de Logs Visual -->
    <div class="bg-slate-950 border border-slate-800 rounded-2xl shadow-2xl overflow-hidden">
        <div class="bg-slate-900/90 px-4 py-2.5 border-b border-slate-800 flex items-center justify-between text-xs font-mono">
            <div class="flex items-center gap-2">
                <span class="w-3 h-3 rounded-full bg-rose-500 inline-block"></span>
                <span class="w-3 h-3 rounded-full bg-amber-500 inline-block"></span>
                <span class="w-3 h-3 rounded-full bg-emerald-500 inline-block"></span>
                <span class="text-slate-400 font-bold ml-2">/logs/<?php echo htmlspecialchars($current_cat); ?>.log</span>
            </div>
            <span class="text-slate-500 text-[11px]">Última Modificação: <?php echo $activeCategoryInfo['modified']; ?></span>
        </div>

        <div class="p-4 font-mono text-xs overflow-x-auto max-h-[600px] overflow-y-auto space-y-1.5 bg-slate-950">
            <?php if (empty($logEntries)): ?>
                <div class="py-12 text-center text-slate-500 italic space-y-2">
                    <i class="fa-solid fa-circle-info text-2xl block text-slate-600"></i>
                    <span>Nenhum registro de log encontrado para os filtros selecionados.</span>
                </div>
            <?php else: ?>
                <?php foreach ($logEntries as $entry): ?>
                    <?php
                    $lvl = strtoupper($entry['level']);
                    $badgeClass = 'bg-slate-800 text-slate-300 border-slate-700';
                    if ($lvl === 'ERROR' || $lvl === 'CRITICAL') {
                        $badgeClass = 'bg-rose-500/20 text-rose-400 border-rose-500/30 font-extrabold animate-pulse';
                    } elseif ($lvl === 'WARNING') {
                        $badgeClass = 'bg-amber-500/20 text-amber-300 border-amber-500/30 font-bold';
                    } elseif ($lvl === 'INFO') {
                        $badgeClass = 'bg-emerald-500/20 text-emerald-300 border-emerald-500/30';
                    } elseif ($lvl === 'DEBUG') {
                        $badgeClass = 'bg-cyan-500/20 text-cyan-300 border-cyan-500/30';
                    }
                    ?>
                    <div class="p-2 rounded-lg bg-slate-900/60 hover:bg-slate-900 border border-slate-800/80 transition flex flex-col md:flex-row items-start md:items-center gap-2 leading-relaxed">
                        <span class="text-[10px] text-slate-500 font-mono shrink-0">
                            <?php echo htmlspecialchars($entry['timestamp']); ?>
                        </span>
                        <span class="px-2 py-0.5 rounded text-[9px] uppercase border shrink-0 <?php echo $badgeClass; ?>">
                            <?php echo htmlspecialchars($lvl); ?>
                        </span>
                        <span class="text-[10px] text-slate-400 font-mono shrink-0">
                            [<?php echo htmlspecialchars($entry['ip']); ?>] [<?php echo htmlspecialchars($entry['user']); ?>]
                        </span>
                        <span class="text-slate-200 text-xs font-mono break-all flex-1">
                            <?php echo htmlspecialchars($entry['message']); ?>
                        </span>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
    </div>
</div>
