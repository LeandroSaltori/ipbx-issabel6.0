<?php
/**
 * IPbx Prisma - Pausa e Gestão de Agentes
 */
?>

<div class="space-y-6">
    <div class="bg-slate-900/90 border border-slate-800 rounded-xl p-5 shadow-xl">
        <div class="flex items-center justify-between border-b border-slate-800 pb-4 mb-4">
            <div>
                <h3 class="text-sm font-bold text-white flex items-center gap-2">
                    <i class="fa-solid fa-user-clock text-amber-400"></i> Gestão de Pausas de Agentes
                </h3>
                <span class="text-xs text-slate-400">Pause ou despause atendentes em filas com registro do motivo (Almoço, Reunião, NR17, Banheiro)</span>
            </div>
            <button onclick="updateQueuesRealtime()" class="px-3 py-1.5 bg-slate-800 text-slate-300 hover:text-white rounded-lg text-xs font-bold transition flex items-center gap-1.5 border border-slate-700">
                <i class="fa-solid fa-rotate text-amber-400"></i> Atualizar Status
            </button>
        </div>

        <div id="agentes-pausa-container" class="space-y-4">
            <div class="text-center py-10 text-slate-500 text-xs">Carregando lista de agentes das filas...</div>
        </div>
    </div>
</div>
