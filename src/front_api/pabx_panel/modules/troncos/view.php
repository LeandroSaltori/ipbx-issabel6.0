<?php
/**
 * IPbx Prisma - Módulo Linhas e Troncos de Saída/Entrada
 */
?>

<div class="space-y-6">
    <div class="bg-slate-900/90 border border-slate-800 rounded-xl p-5 shadow-xl">
        <div class="flex items-center justify-between border-b border-slate-800 pb-4 mb-4">
            <div>
                <h3 class="text-sm font-bold text-white flex items-center gap-2">
                    <i class="fa-solid fa-network-wired text-cyan-400"></i> Monitor de Linhas e Troncos de Saída/Entrada
                </h3>
                <span class="text-xs text-slate-400">Visualize em tempo real o status de registro e ocupação de canais de suas linhas operacionais</span>
            </div>
            <button onclick="updateTrunksStatus()" class="px-3 py-1.5 bg-slate-800 text-slate-300 hover:text-white rounded-lg text-xs font-bold transition flex items-center gap-1.5 border border-slate-700">
                <i class="fa-solid fa-rotate text-cyan-400"></i> Atualizar Status
            </button>
        </div>

        <div id="trunks-grid" class="grid grid-cols-1 sm:grid-cols-2 md:grid-cols-3 lg:grid-cols-4 gap-4">
            <div class="col-span-full text-center py-6 text-slate-500 text-xs">Consultando troncos configurados no Asterisk...</div>
        </div>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', () => {
    if (typeof updateTrunksStatus === 'function') {
        updateTrunksStatus();
    }
});
</script>
