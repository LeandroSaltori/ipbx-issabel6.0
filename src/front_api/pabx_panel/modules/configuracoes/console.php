<?php
/** IPbx Prisma - Console da central: terminal web (somente administradores) para consultar a central e ler o log por ramal. */
if (!hasUserPermission('manage_settings')) {
    echo '<div class="p-6 rounded-2xl bg-slate-900/90 border border-slate-800 text-slate-300 text-sm">Esta tela é restrita a administradores.</div>';
    return;
}
?>
<style>
    .cc-btn{white-space:nowrap !important;flex-shrink:0 !important;width:auto !important;overflow:visible !important;text-overflow:clip !important}
    #cc-out{font-family:ui-monospace,SFMono-Regular,Menlo,Consolas,monospace;font-size:12px;line-height:1.45;white-space:pre-wrap;word-break:break-word}
    #cc-out .cc-cmd{color:#a78bfa;font-weight:700}
    #cc-out .cc-err{color:#fda4af}
    #cc-out .cc-ok{color:#e2e8f0}
</style>
<div class="space-y-4">
    <div class="bg-slate-900/90 border border-slate-800 rounded-2xl p-5">
        <div class="flex items-center gap-3">
            <span class="w-10 h-10 rounded-xl bg-gradient-to-br from-violet-600 to-indigo-500 flex items-center justify-center text-white"><i class="fa-solid fa-terminal"></i></span>
            <div>
                <h2 class="text-lg font-extrabold text-white leading-tight">Console da central</h2>
                <p class="text-[12px] text-slate-400">Consulte ramais, registros e chamadas e leia o log da central, como no acesso por terminal. Só comandos de consulta e recargas suaves; tudo fica registrado na auditoria. Digite <b class="text-slate-200">help</b> para ver a lista.</p>
            </div>
        </div>

        <div class="mt-4 flex flex-wrap items-center gap-2" id="cc-chips">
            <button type="button" class="cc-btn px-3 py-1.5 rounded-lg bg-slate-800 hover:bg-slate-700 text-slate-200 text-[12px] font-semibold border border-slate-700" data-cmd="core show channels">Chamadas ativas</button>
            <button type="button" class="cc-btn px-3 py-1.5 rounded-lg bg-slate-800 hover:bg-slate-700 text-slate-200 text-[12px] font-semibold border border-slate-700" data-cmd="sip show peers">Ramais SIP</button>
            <button type="button" class="cc-btn px-3 py-1.5 rounded-lg bg-slate-800 hover:bg-slate-700 text-slate-200 text-[12px] font-semibold border border-slate-700" data-cmd="pjsip show endpoints">Ramais PJSIP</button>
            <button type="button" class="cc-btn px-3 py-1.5 rounded-lg bg-slate-800 hover:bg-slate-700 text-slate-200 text-[12px] font-semibold border border-slate-700" data-cmd="pjsip show contacts">Registros PJSIP</button>
            <button type="button" class="cc-btn px-3 py-1.5 rounded-lg bg-slate-800 hover:bg-slate-700 text-slate-200 text-[12px] font-semibold border border-slate-700" data-cmd="pjsip show transports">Transportes PJSIP</button>
            <button type="button" class="cc-btn px-3 py-1.5 rounded-lg bg-slate-800 hover:bg-slate-700 text-slate-200 text-[12px] font-semibold border border-slate-700" data-cmd="http show status">Servidor web interno</button>
            <button type="button" class="cc-btn px-3 py-1.5 rounded-lg bg-slate-800 hover:bg-slate-700 text-slate-200 text-[12px] font-semibold border border-slate-700" data-cmd="log erros 60">Últimos erros</button>
        </div>

        <div class="mt-3 flex flex-wrap items-center gap-2 p-3 rounded-xl bg-slate-950/60 border border-slate-800">
            <span class="text-[12px] text-slate-400 font-semibold">Ramal</span>
            <input id="cc-ext" type="text" inputmode="numeric" maxlength="12" placeholder="ex.: 208" class="w-24 px-2.5 py-1.5 rounded-lg bg-slate-900 border border-slate-700 text-slate-100 text-[13px] focus:outline-none focus:border-violet-500">
            <button type="button" class="cc-btn px-3 py-1.5 rounded-lg bg-slate-800 hover:bg-slate-700 text-slate-200 text-[12px] font-semibold border border-slate-700" data-ext="sip show peer {x}">Detalhes (SIP)</button>
            <button type="button" class="cc-btn px-3 py-1.5 rounded-lg bg-slate-800 hover:bg-slate-700 text-slate-200 text-[12px] font-semibold border border-slate-700" data-ext="pjsip show endpoint {x}">Detalhes (PJSIP)</button>
            <button type="button" class="cc-btn px-3 py-1.5 rounded-lg bg-slate-800 hover:bg-slate-700 text-slate-200 text-[12px] font-semibold border border-slate-700" data-ext="log {x} 80">Log do ramal</button>
            <button type="button" class="cc-btn px-3 py-1.5 rounded-lg bg-violet-600/80 hover:bg-violet-600 text-white text-[12px] font-bold" data-ext="sip set debug peer {x}">Ligar captura SIP</button>
            <button type="button" class="cc-btn px-3 py-1.5 rounded-lg bg-slate-800 hover:bg-slate-700 text-slate-200 text-[12px] font-semibold border border-slate-700" data-cmd="sip set debug off">Desligar captura</button>
        </div>
    </div>

    <div class="bg-[#0b1020] border border-slate-800 rounded-2xl overflow-hidden">
        <div class="flex items-center justify-between px-4 py-2 border-b border-slate-800 bg-slate-900/70">
            <span class="text-[11px] font-bold text-slate-400 uppercase tracking-wider">Saída</span>
            <div class="flex items-center gap-2">
                <button type="button" id="cc-copy" class="cc-btn px-2.5 py-1 rounded-md bg-slate-800 hover:bg-slate-700 text-slate-200 text-[11px] font-semibold border border-slate-700"><i class="fa-regular fa-copy"></i> Copiar saída</button>
                <button type="button" id="cc-clear" class="cc-btn px-2.5 py-1 rounded-md bg-slate-800 hover:bg-slate-700 text-slate-200 text-[11px] font-semibold border border-slate-700"><i class="fa-solid fa-eraser"></i> Limpar</button>
            </div>
        </div>
        <pre id="cc-out" class="m-0 p-4 text-slate-200 overflow-auto" style="min-height:260px;max-height:58vh"><span class="cc-ok">Console da central pronto. Digite um comando ou use os botões acima. Digite help para ver a lista.
</span></pre>
        <form id="cc-form" class="flex items-center gap-2 px-4 py-3 border-t border-slate-800 bg-slate-900/70" autocomplete="off">
            <span class="text-violet-400 font-bold text-[13px]" style="font-family:ui-monospace,Consolas,monospace">central&gt;</span>
            <input id="cc-in" type="text" spellcheck="false" autocapitalize="off" maxlength="160" class="flex-1 min-w-0 bg-transparent text-slate-100 text-[13px] focus:outline-none" style="font-family:ui-monospace,Consolas,monospace" placeholder="ex.: pjsip show transports" autofocus>
            <button type="submit" id="cc-run" class="cc-btn px-4 py-1.5 rounded-lg bg-gradient-to-r from-violet-600 to-indigo-500 text-white text-[12px] font-bold"><i class="fa-solid fa-play"></i> Executar</button>
        </form>
    </div>
</div>
<script>
(function () {
    var out = document.getElementById('cc-out'), inp = document.getElementById('cc-in'), form = document.getElementById('cc-form'), run = document.getElementById('cc-run');
    var hist = [], hi = 0, busy = false;   // histórico só em memória: nada é gravado no navegador
    function add(cls, txt) {
        var s = document.createElement('span'); s.className = cls; s.textContent = txt + '\n'; out.appendChild(s); out.scrollTop = out.scrollHeight;
    }
    function exec(cmd) {
        cmd = (cmd || '').trim();
        if (!cmd || busy) return;
        if (/^(limpar|clear)$/i.test(cmd)) { out.textContent = ''; return; }
        hist.push(cmd); hi = hist.length;
        add('cc-cmd', 'central> ' + cmd);
        busy = true; run.disabled = true;
        fetch('index.php?api_action=console_exec', { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ cmd: cmd }) })
            .then(function (r) { return r.json(); })
            .then(function (j) { add(j.success ? 'cc-ok' : 'cc-err', j.success ? (j.output || '') : (j.error || 'Falha ao executar.')); })
            .catch(function () { add('cc-err', 'Falha de comunicação com o painel.'); })
            .then(function () { busy = false; run.disabled = false; inp.focus(); });
    }
    form.addEventListener('submit', function (e) { e.preventDefault(); var c = inp.value; inp.value = ''; exec(c); });
    inp.addEventListener('keydown', function (e) {
        if (e.key === 'ArrowUp' && hist.length) { e.preventDefault(); hi = Math.max(0, hi - 1); inp.value = hist[hi] || ''; }
        else if (e.key === 'ArrowDown' && hist.length) { e.preventDefault(); hi = Math.min(hist.length, hi + 1); inp.value = hist[hi] || ''; }
    });
    document.getElementById('cc-chips').addEventListener('click', function (e) { var b = e.target.closest('button[data-cmd]'); if (b) exec(b.getAttribute('data-cmd')); });
    document.querySelectorAll('button[data-cmd]').forEach(function (b) { if (!b.closest('#cc-chips')) b.addEventListener('click', function () { exec(b.getAttribute('data-cmd')); }); });
    document.querySelectorAll('button[data-ext]').forEach(function (b) {
        b.addEventListener('click', function () {
            var x = (document.getElementById('cc-ext').value || '').replace(/[^A-Za-z0-9_.-]/g, '');
            if (!x) { add('cc-err', 'Informe o ramal (ex.: 208).'); document.getElementById('cc-ext').focus(); return; }
            exec(b.getAttribute('data-ext').replace('{x}', x));
        });
    });
    document.getElementById('cc-clear').addEventListener('click', function () { out.textContent = ''; inp.focus(); });
    document.getElementById('cc-copy').addEventListener('click', function () {
        var t = out.innerText || out.textContent || '', btn = this;
        function ok() { var o = btn.innerHTML; btn.innerHTML = '<i class="fa-solid fa-check"></i> Copiado'; setTimeout(function () { btn.innerHTML = o; }, 1400); }
        if (navigator.clipboard && navigator.clipboard.writeText) navigator.clipboard.writeText(t).then(ok, function () {});
        else { var ta = document.createElement('textarea'); ta.value = t; document.body.appendChild(ta); ta.select(); try { document.execCommand('copy'); ok(); } catch (e) {} document.body.removeChild(ta); }
    });
})();
</script>
