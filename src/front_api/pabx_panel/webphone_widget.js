/**
 * IPbx Prisma - Webphone (widget flutuante, WebRTC/SIP via JsSIP)
 * Carregado em todas as telas do painel por webphone.php?js=1 (só para quem tem o Webphone habilitado).
 * Interface isolada em Shadow DOM (não sofre nem causa conflito de estilos com o painel) e painel na Top Layer (popover),
 * por cima de qualquer tela ou janela do painel.
 * API: window.PrismaWebphone = { open(), close(), toggle(), dial(numero), ready() }
 */
(function () {
  if (window.PrismaWebphone) return;
  const SRC = (document.currentScript && document.currentScript.src) || (location.href);
  const url = p => new URL(p, SRC).href;
  const FA = 'https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css';

  const CSS = `
:host{all:initial}
*{box-sizing:border-box;font-family:'Segoe UI',Inter,system-ui,sans-serif}
#panel{position:fixed;inset:var(--wp-top,72px) 24px auto auto;margin:0;padding:0;border:1px solid #334155;width:340px;max-width:calc(100vw - 24px);max-height:calc(100vh - var(--wp-top,72px) - 84px);overflow:auto;border-radius:22px;background:rgba(15,23,42,.97);backdrop-filter:blur(14px);color:#e2e8f0;font-size:13px;box-shadow:0 25px 60px rgba(0,0,0,.55)}
header{padding:12px 14px;background:linear-gradient(135deg,#1c1b2f,#252440);border-bottom:1px solid #334155;display:flex;align-items:center;gap:10px;position:sticky;top:0;z-index:2}
header .ic{width:34px;height:34px;border-radius:50%;background:rgba(16,185,129,.15);color:#10b981;display:flex;align-items:center;justify-content:center;border:1px solid rgba(16,185,129,.35)}
header .t{flex:1;min-width:0}header b{display:block;font-size:14px;color:#fff}
#st{font-size:11px;color:#94a3b8;display:block}#st.ok{color:#10b981}#st.bad{color:#f43f5e}#st.warn{color:#f59e0b}
.x{background:none;border:0;color:#94a3b8;cursor:pointer;font-size:15px;padding:6px}.x:hover{color:#fff}
main{padding:12px;display:flex;flex-direction:column;gap:10px}
.box{background:#1c1b2f;border:1px solid #334155;border-radius:14px;padding:11px}
.alert{border-radius:10px;padding:9px 11px;font-size:12px;line-height:1.35;display:none}.alert.show{display:block}
.alert.err{background:rgba(244,63,94,.1);color:#fda4af;border:1px solid rgba(244,63,94,.35)}
.alert.warn{background:rgba(245,158,11,.1);color:#fcd34d;border:1px solid rgba(245,158,11,.35)}
#disp{width:100%;background:#0b1220;border:1px solid #334155;border-radius:12px;color:#fff;font-size:19px;font-weight:700;text-align:center;padding:10px;letter-spacing:1px}
#disp:focus{outline:none;border-color:#6366f1}
.pad{display:grid;grid-template-columns:repeat(3,1fr);gap:7px;margin-top:9px}
.pad button{padding:10px 0;border-radius:12px;background:#252440;border:1px solid #334155;color:#fff;font-size:16px;font-weight:700;cursor:pointer}.pad button:hover{background:#312e5a}
.btn{white-space:nowrap!important;flex-shrink:0!important;width:auto!important;overflow:visible!important;text-overflow:clip!important;padding:10px 12px;border-radius:12px;border:0;color:#fff;font-weight:800;font-size:12.5px;cursor:pointer;display:inline-flex;align-items:center;justify-content:center;gap:7px}
.btn:disabled{opacity:.5;cursor:not-allowed}
.row{display:flex;gap:8px;margin-top:9px}.row .btn{flex:1}
.call{background:linear-gradient(135deg,#059669,#10b981)}.hang{background:linear-gradient(135deg,#e11d48,#f43f5e)}
.sec{background:#252440;border:1px solid #334155}.sec.on{background:linear-gradient(135deg,#7c3aed,#6366f1);border-color:#6366f1}
#incoming,#incall{display:none}#incoming.show,#incall.show{display:block}
#incoming{border-color:rgba(245,158,11,.55)}
.who{font-size:17px;font-weight:800;text-align:center;color:#fff}.sub{text-align:center;color:#94a3b8;font-size:11px;margin-top:2px}
#xfer{display:none;margin-top:8px;gap:6px}#xfer.show{display:flex}
#xfer input{flex:1;min-width:0;background:#0b1220;border:1px solid #334155;border-radius:10px;color:#fff;padding:8px 10px}
.lvl{height:5px;border-radius:4px;background:#0b1220;overflow:hidden;margin-top:9px}.lvl i{display:block;height:100%;width:0;background:linear-gradient(90deg,#10b981,#6366f1);transition:width .15s}
.q{display:flex;align-items:center;justify-content:center;gap:6px;font-size:11px;color:#94a3b8;margin-top:7px}.dot{width:9px;height:9px;border-radius:50%;background:#94a3b8}
#tabs{display:flex;gap:6px}.tab{flex:1;padding:8px 10px;border-radius:10px;border:1px solid #334155;background:#1c1b2f;color:#94a3b8;font-weight:800;font-size:12px;cursor:pointer;white-space:nowrap!important}
.tab.on{background:linear-gradient(135deg,#7c3aed,#6366f1);border-color:#6366f1;color:#fff}
#agenda{display:none}#agenda.show{display:block}#tab-dial.hide{display:none}
#ag-q{width:100%;background:#0b1220;border:1px solid #334155;border-radius:12px;color:#fff;padding:9px 11px;font-size:13px}#ag-q:focus{outline:none;border-color:#6366f1}
#ag-list{margin-top:8px;max-height:330px;overflow:auto}
.ag-h{margin:8px 2px 4px;font-size:10.5px;text-transform:uppercase;letter-spacing:.6px;color:#94a3b8}
.ag{display:flex;align-items:center;gap:9px;padding:7px 6px;border-radius:10px;cursor:pointer}.ag:hover{background:#252440}
.ag .p{width:9px;height:9px;border-radius:50%;background:#64748b;flex-shrink:0}.ag .p.on{background:#10b981}.ag .p.off{background:#ef4444}.ag.offl{opacity:.5}
.ag .nm{flex:1;min-width:0;font-weight:600;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}.ag .nu{color:#94a3b8;font-size:11px}
.ag .go{background:#059669;border:0;color:#fff;border-radius:50%;width:28px;height:28px;cursor:pointer;flex-shrink:0}
h4{margin:0 0 6px;font-size:10.5px;text-transform:uppercase;letter-spacing:.6px;color:#94a3b8}
.h{display:flex;align-items:center;gap:8px;padding:6px 4px;border-bottom:1px solid #26263f;cursor:pointer;border-radius:8px}.h:hover{background:#252440}.h:last-child{border-bottom:0}
.h .n{flex:1;font-weight:600}.h .d{color:#94a3b8;font-size:11px}.out{color:#10b981}.inn{color:#818cf8}.mis{color:#f43f5e}
`;

  const KEYS = ['1','2','3','4','5','6','7','8','9','*','0','#'];
  const HTML = `
<style>${CSS}</style><link rel="stylesheet" href="${FA}">
<div id="panel" popover="manual">
  <header><div class="ic"><i class="fa-solid fa-headset"></i></div><div class="t"><b>Webphone</b><span id="st">Desconectado</span></div><button class="x" data-act="close" title="Minimizar"><i class="fa-solid fa-minus"></i></button></header>
  <main>
    <div id="al-err" class="alert err"></div><div id="al-warn" class="alert warn"></div>
    <div id="incoming" class="box"><div class="who" id="in-who">-</div><div class="sub">Chamada recebida</div>
      <div class="row"><button class="btn call" data-act="answer"><i class="fa-solid fa-phone"></i> Atender</button><button class="btn hang" data-act="reject"><i class="fa-solid fa-phone-slash"></i> Recusar</button></div></div>
    <div id="incall" class="box"><div class="who" id="ic-who">-</div><div class="sub"><span id="ic-state">Chamando...</span> · <span id="tm">00:00</span></div>
      <div class="row"><button class="btn sec" id="b-mute" data-act="mute"><i class="fa-solid fa-microphone-slash"></i> Mudo</button><button class="btn sec" id="b-hold" data-act="hold"><i class="fa-solid fa-pause"></i> Espera</button><button class="btn sec" data-act="xfer"><i class="fa-solid fa-right-left"></i> Transferir</button></div>
      <div id="xfer"><input id="xnum" placeholder="Ramal ou número" inputmode="tel"><button class="btn call" data-act="transfer">OK</button></div>
      <div class="lvl" title="Nível do seu microfone"><i id="lv"></i></div><div class="q"><span class="dot" id="qd"></span><span id="qt">Qualidade: medindo...</span></div>
      <div class="row"><button class="btn hang" data-act="hangup"><i class="fa-solid fa-phone-slash"></i> Desligar</button></div></div>
    <div id="tabs"><button class="tab on" data-act="tab" data-k="dial"><i class="fa-solid fa-table-cells"></i> Teclado</button><button class="tab" data-act="tab" data-k="agenda"><i class="fa-regular fa-address-book"></i> Agenda</button></div>
    <div class="box" id="agenda"><input id="ag-q" type="search" placeholder="Buscar ramal ou contato..." autocomplete="off"><div id="ag-list"></div><div class="sub" id="ag-st" style="margin-top:6px"></div></div>
    <div id="tab-dial"><div class="box"><input id="disp" placeholder="Número ou ramal" inputmode="tel" autocomplete="off">
      <div class="pad">${KEYS.map(k => `<button data-act="key" data-k="${k}">${k}</button>`).join('')}</div>
      <div class="row"><button class="btn call" id="b-call" data-act="dial"><i class="fa-solid fa-phone"></i> Ligar</button><button class="btn sec" data-act="del"><i class="fa-solid fa-delete-left"></i> Apagar</button></div></div>
    <div class="box" style="margin-top:10px"><h4>Últimas chamadas deste navegador</h4><div id="hist"></div></div></div>
  </main>
</div>
<audio id="ra" autoplay></audio>`;

  const host = document.createElement('div');
  host.id = 'prisma-webphone-host';
  const root = host.attachShadow({mode: 'open'});
  root.innerHTML = HTML;
  const $ = id => root.getElementById(id);
  const ss = { get: k => { try { return sessionStorage.getItem(k); } catch (e) { return null; } }, set: (k, v) => { try { sessionStorage.setItem(k, v); } catch (e) {} } };

  let ua = null, cfg = null, cur = null, pend = null, pendWho = '', inCall = false, muted = false, held = false, t0 = 0, tmr = null, stTmr = null, ringT = null, actx = null, reg = false, hungByMe = false, queued = '';
  const num = s => String(s || '').replace(/[^0-9*#+]/g, '');
  const panel = $('panel');

  // ---- abrir / fechar (Top Layer: fica por cima de qualquer tela do painel) ----
  function isOpen() { try { return panel.matches(':popover-open'); } catch (e) { return panel.dataset.open === '1'; } }
  function open() { if (isOpen()) return; placePanel(); try { panel.showPopover(); } catch (e) { panel.style.display = 'block'; panel.dataset.open = '1'; } ss.set('prisma_wp_open', '1'); if (tabAtual === 'agenda') loadAgenda(); }
  function close() { try { panel.hidePopover(); } catch (e) { panel.style.display = 'none'; panel.dataset.open = '0'; } ss.set('prisma_wp_open', '0'); }
  function toggle() { isOpen() ? close() : open(); }

  function status(txt, cls) { const s = $('st'); s.textContent = txt; s.className = cls || ''; pillState(cls || ''); }
  function showErr(m) { const a = $('al-err'); a.textContent = m || ''; a.classList.toggle('show', !!m); }
  function showWarn(m) { const a = $('al-warn'); a.textContent = m || ''; a.classList.toggle('show', !!m); }

  // ---- histórico (só neste navegador) ----
  function hist(add) {
    let h = []; try { h = JSON.parse(localStorage.getItem('prisma_wp_hist') || '[]'); } catch (e) {}
    if (add) { h.unshift(add); h = h.slice(0, 10); try { localStorage.setItem('prisma_wp_hist', JSON.stringify(h)); } catch (e) {} }
    const box = $('hist'); box.textContent = '';
    if (!h.length) { const s = document.createElement('span'); s.style.color = '#94a3b8'; s.textContent = 'Nenhuma chamada ainda.'; box.appendChild(s); return; }
    h.forEach(x => {
      const d = document.createElement('div'); d.className = 'h'; d.title = 'Ligar de novo';
      const i = document.createElement('i'); i.className = 'fa-solid ' + (x.r === 'mis' ? 'fa-phone-slash mis' : x.d === 'in' ? 'fa-arrow-down inn' : 'fa-arrow-up out');
      const n = document.createElement('span'); n.className = 'n'; n.textContent = x.n;
      const t = document.createElement('span'); t.className = 'd'; t.textContent = new Date(x.t).toLocaleTimeString('pt-BR', {hour: '2-digit', minute: '2-digit'}) + (x.s ? ' · ' + x.s + 's' : '');
      d.append(i, n, t); d.addEventListener('click', () => { $('disp').value = x.n; });
      box.appendChild(d);
    });
  }

  // ---- toque (sem arquivo de áudio) ----
  function ring(on) {
    clearInterval(ringT); ringT = null; const pl = pillEl(); if (pl) pl.classList.toggle('animate-bounce', !!on);
    if (!on) return;
    const beep = () => { try {
      actx = actx || new (window.AudioContext || window.webkitAudioContext)(); if (actx.state === 'suspended') actx.resume();
      [0, 0.25].forEach(d => { const o = actx.createOscillator(), g = actx.createGain(); o.frequency.value = 440 + (d ? 40 : 0); g.gain.value = 0.08; o.connect(g); g.connect(actx.destination); o.start(actx.currentTime + d); o.stop(actx.currentTime + d + 0.2); });
    } catch (e) {} };
    beep(); ringT = setInterval(beep, 2000);
  }
  document.addEventListener('click', () => { try { if (actx && actx.state === 'suspended') actx.resume(); } catch (e) {} }, {once: true, capture: true});

  // ---- estado da chamada ----
  function ui() { $('incall').classList.toggle('show', inCall); $('incoming').classList.toggle('show', !!pend && !inCall); $('b-call').disabled = inCall; }
  function tick() { const s = Math.floor((Date.now() - t0) / 1000); $('tm').textContent = String(Math.floor(s / 60)).padStart(2, '0') + ':' + String(s % 60).padStart(2, '0'); }
  const CAUSAS = {'Busy': 'ocupado', 'Rejected': 'recusada', 'Unavailable': 'indisponível', 'Not Found': 'número não encontrado', 'No Answer': 'sem resposta', 'Canceled': 'cancelada', 'Request Timeout': 'tempo esgotado', 'Connection Error': 'erro de conexão', 'Address Incomplete': 'número incompleto', 'User Denied Media Access': 'microfone bloqueado no navegador', 'WebRTC Error': 'erro de áudio (WebRTC)'};
  function attachAudio(pc) {
    if (!pc || pc.__wp) return; pc.__wp = 1;
    pc.addEventListener('track', ev => { const a = $('ra'); if (ev.streams && ev.streams[0]) { a.srcObject = ev.streams[0]; a.play().catch(() => {}); } });
  }
  function fallbackAudio(s) {
    const a = $('ra'); if (a.srcObject || !s.connection) return;
    const tr = s.connection.getReceivers().map(r => r.track).filter(t => t && t.kind === 'audio');
    if (tr.length) { a.srcObject = new MediaStream(tr); a.play().catch(() => {}); }
  }
  function bind(s, dir, who) {
    hungByMe = false; attachAudio(s.connection);
    cur = s; inCall = true; muted = false; held = false; t0 = Date.now();
    $('ic-who').textContent = who; $('ic-state').textContent = dir === 'in' ? 'Conectando...' : 'Chamando...';
    $('b-mute').classList.remove('on'); $('b-hold').classList.remove('on'); $('xfer').classList.remove('show'); $('tm').textContent = '00:00';
    ui(); open();
    let connected = false;
    s.on('progress', () => { $('ic-state').textContent = 'Chamando...'; });
    s.on('accepted', () => { connected = true; t0 = Date.now(); $('ic-state').textContent = 'Em chamada'; clearInterval(tmr); tmr = setInterval(tick, 1000); watch(s); });
    s.on('confirmed', () => { connected = true; attachAudio(s.connection); fallbackAudio(s); });
    s.on('peerconnection', e => attachAudio(e.peerconnection));
    const end = why => {
      clearInterval(tmr); clearInterval(stTmr); ring(false);
      hist({n: who, d: dir, t: Date.now(), s: connected ? Math.floor((Date.now() - t0) / 1000) : 0, r: connected ? 'ok' : (dir === 'in' ? 'mis' : 'no')});
      cur = null; inCall = false; $('ra').srcObject = null; $('lv').style.width = '0'; ui();
      if (!connected && why && dir === 'out' && !hungByMe) showErr('Chamada não completada: ' + (CAUSAS[why] || why));
    };
    s.on('ended', () => end('')); s.on('failed', e => end((e && e.cause) || 'falha'));
    s.on('hold', () => { $('ic-state').textContent = 'Em espera'; }); s.on('unhold', () => { $('ic-state').textContent = 'Em chamada'; });
  }
  // diagnóstico: qualidade (latência, jitter, perda) e nível do microfone
  function watch(s) {
    clearInterval(stTmr);
    stTmr = setInterval(async () => {
      try {
        const pc = s.connection; if (!pc) return;
        const rep = await pc.getStats(); let rtt = null, jit = null, lost = 0, pk = 0, lvl = null;
        rep.forEach(r => {
          if (r.type === 'candidate-pair' && r.state === 'succeeded' && r.currentRoundTripTime != null) rtt = r.currentRoundTripTime * 1000;
          if (r.type === 'inbound-rtp' && r.kind === 'audio') { jit = (r.jitter || 0) * 1000; lost = r.packetsLost || 0; pk = r.packetsReceived || 0; }
          if (r.type === 'media-source' && r.kind === 'audio' && r.audioLevel != null) lvl = r.audioLevel;
        });
        const perda = pk + lost > 0 ? (lost / (pk + lost)) * 100 : 0;
        const ruim = (rtt != null && rtt > 400) || (jit != null && jit > 60) || perda > 5;
        const medio = (rtt != null && rtt > 200) || (jit != null && jit > 30) || perda > 2;
        $('qd').style.background = ruim ? '#f43f5e' : medio ? '#f59e0b' : '#10b981';
        $('qt').textContent = 'Qualidade: ' + (ruim ? 'ruim' : medio ? 'regular' : 'boa') + (rtt != null ? ' · ' + Math.round(rtt) + ' ms' : '') + (jit != null ? ' · jitter ' + Math.round(jit) + ' ms' : '') + ' · perda ' + perda.toFixed(1) + '%';
        if (lvl != null) $('lv').style.width = Math.min(100, Math.round(lvl * 180)) + '%';
      } catch (e) {}
    }, 1000);
  }

  // ---- agenda (ramais com status + contatos), lida de <endereço do painel>/agenda.php, o mesmo arquivo do MicroSIP ----
  const AGENDA_URL = location.origin + '/agenda.php';   // o "domínio" só serve para montar este endereço
  let agItems = [], agTimer = null, agLoaded = 0, tabAtual = 'dial';
  function showTab(k) {
    tabAtual = k;
    $('agenda').classList.toggle('show', k === 'agenda'); $('tab-dial').classList.toggle('hide', k === 'agenda');
    root.querySelectorAll('.tab').forEach(b => b.classList.toggle('on', b.dataset.k === k));
    if (k === 'agenda') { loadAgenda(); $('ag-q').focus(); } else { clearTimeout(agTimer); }
  }
  function renderAgenda() {
    const q = $('ag-q').value.trim().toLowerCase(), box = $('ag-list'); box.textContent = '';
    const ok = it => !q || (it.name + ' ' + it.number).toLowerCase().includes(q);
    const grupos = [['Ramais', agItems.filter(i => i.starred && ok(i))], ['Contatos externos', agItems.filter(i => !i.starred && ok(i))]];
    let total = 0;
    grupos.forEach(([titulo, lista]) => {
      if (!lista.length) return; total += lista.length;
      const h = document.createElement('div'); h.className = 'ag-h'; h.textContent = titulo + ' (' + lista.length + ')'; box.appendChild(h);
      lista.forEach(it => {
        const r = document.createElement('div'); r.className = 'ag' + (it.starred && !it.presence ? ' offl' : ''); r.title = (it.info || '') + (it.starred ? (it.presence ? ' · online' : ' · offline') : '');
        const p = document.createElement('span'); p.className = 'p' + (it.starred ? (it.presence ? ' on' : ' off') : '');
        const nm = document.createElement('span'); nm.className = 'nm'; nm.textContent = it.name;
        const nu = document.createElement('span'); nu.className = 'nu'; nu.textContent = it.number;
        const g = document.createElement('button'); g.className = 'go'; g.title = 'Ligar'; const gi = document.createElement('i'); gi.className = 'fa-solid fa-phone'; g.appendChild(gi);
        g.addEventListener('click', e => { e.stopPropagation(); showTab('dial'); dial(it.number); });
        r.addEventListener('click', () => { $('disp').value = it.number; showTab('dial'); });
        r.append(p, nm, nu, g); box.appendChild(r);
      });
    });
    if (!total) { const s = document.createElement('div'); s.className = 'sub'; s.textContent = agItems.length ? 'Nada encontrado.' : 'Agenda vazia.'; box.appendChild(s); }
  }
  async function loadAgenda() {
    clearTimeout(agTimer);
    try {
      const r = await fetch(AGENDA_URL, {cache: 'no-store'});
      if (!r.ok) throw new Error('HTTP ' + r.status);
      const j = await r.json();
      if (j.error) throw new Error(j.error);
      agItems = (j.items || []).map(i => ({name: String(i.name || i.number || ''), number: String(i.number || ''), presence: parseInt(i.presence || 0, 10), starred: parseInt(i.starred || 0, 10), info: String(i.info || '')})).filter(i => i.number);
      // Presença real do PABX (hints do Asterisk) sobrepõe a do agenda.php, que assume "online" quando não sabe
      try {
        const rs = await fetch('index.php?api_action=get_fop_extensions', {cache: 'no-store', credentials: 'same-origin'});
        const ls = rs.ok ? await rs.json() : null;
        if (Array.isArray(ls)) {
          const m = {}; ls.forEach(x => { m[String(x.id)] = !/indispon|offline|unavailable/i.test(String(x.status || '')); });
          agItems.forEach(i => { if (i.starred && Object.prototype.hasOwnProperty.call(m, i.number)) i.presence = m[i.number] ? 1 : 0; });
        }
      } catch (e2) {}
      agLoaded = Date.now(); $('ag-st').textContent = agItems.filter(i => i.starred && i.presence).length + ' ramal(is) online de ' + agItems.filter(i => i.starred).length + ' · atualizado ' + new Date().toLocaleTimeString('pt-BR');
      renderAgenda();
      agTimer = setTimeout(() => { if (tabAtual === 'agenda' && isOpen()) loadAgenda(); }, Math.max(30, parseInt(j.refresh || 60, 10)) * 1000);
    } catch (e) {
      $('ag-list').textContent = ''; $('ag-st').textContent = 'Agenda indisponível (' + AGENDA_URL + '): ' + e.message + '. Instale o agenda.php pelo menu do IPbx.';
    }
  }

  // ---- ações ----
  function dial(n) {
    showErr(''); n = num(n != null ? n : $('disp').value);
    if (!n) { showErr('Digite um número ou ramal.'); open(); return; }
    if (inCall) return;
    $('disp').value = n; open();
    if (!ua || !reg) { queued = n; showWarn('Aguardando o registro no PABX para ligar para ' + n + '...'); return; }
    queued = ''; showWarn('');
    const s = ua.call('sip:' + n + '@' + cfg.host, {mediaConstraints: {audio: true, video: false}, pcConfig: cfg.pc, rtcOfferConstraints: {offerToReceiveAudio: true}, eventHandlers: {peerconnection: e => attachAudio(e.peerconnection)}});
    bind(s, 'out', n);
  }
  const actions = {
    close,
    dial: () => dial(),
    del: () => { const d = $('disp'); d.value = d.value.slice(0, -1); },
    key: k => {
      if (cur && inCall) { try { cur.sendDTMF(k); } catch (e) {} const d = $('ic-state'); d.textContent = 'Enviou tom ' + k; setTimeout(() => { if (inCall) d.textContent = held ? 'Em espera' : 'Em chamada'; }, 700); }
      else { $('disp').value += k; }
    },
    hangup: () => { hungByMe = true; if (cur) { try { cur.terminate(); } catch (e) {} } },
    mute: () => { if (!cur) return; muted = !muted; muted ? cur.mute({audio: true}) : cur.unmute({audio: true}); $('b-mute').classList.toggle('on', muted); },
    hold: () => { if (!cur) return; held = !held; held ? cur.hold() : cur.unhold(); $('b-hold').classList.toggle('on', held); },
    xfer: () => $('xfer').classList.toggle('show'),
    tab: k => showTab(k),
    transfer: () => { const n = num($('xnum').value); if (!cur || !n) return; try { cur.refer('sip:' + n + '@' + cfg.host); $('ic-state').textContent = 'Transferindo para ' + n + '...'; $('xnum').value = ''; $('xfer').classList.remove('show'); } catch (e) { showErr('Não foi possível transferir.'); } },
    answer: () => { if (!pend) return; ring(false); const s = pend, who = pendWho; pend = null; bind(s, 'in', who); s.answer({mediaConstraints: {audio: true, video: false}, pcConfig: cfg.pc, eventHandlers: {peerconnection: e => attachAudio(e.peerconnection)}}); },
    reject: () => { if (!pend) return; ring(false); const s = pend; pend = null; try { s.terminate({status_code: 486, reason_phrase: 'Busy Here'}); } catch (e) {} ui(); },
  };
  root.addEventListener('click', e => { const b = e.target.closest && e.target.closest('[data-act]'); if (b && actions[b.dataset.act]) actions[b.dataset.act](b.dataset.k); });
  $('disp').addEventListener('keydown', e => { if (e.key === 'Enter') dial(); });
  $('disp').addEventListener('input', () => ss.set('prisma_wp_num', $('disp').value));
  $('ag-q').addEventListener('input', renderAgenda);

  // ---- registro no PABX ----
  function start(c) {
    cfg = {host: c.host, pc: {iceServers: c.stun ? [{urls: c.stun}] : []}};
    if (!c.webrtc) showWarn('O ramal ' + c.ext + ' não parece estar configurado como WebRTC no PABX. Se o registro falhar, peça ao administrador para ativar WebRTC neste ramal.');
    const sock = new JsSIP.WebSocketInterface(c.wss);
    ua = new JsSIP.UA({sockets: [sock], uri: 'sip:' + c.ext + '@' + c.host, password: c.secret, register: true, register_expires: 300, user_agent: 'IPbx-Prisma-Webphone', connection_recovery_min_interval: 2, connection_recovery_max_interval: 20});
    ua.on('connecting', () => { if (!reg) status('Conectando...', 'warn'); });
    ua.on('connected', () => showErr(''));
    ua.on('disconnected', () => { reg = false; status('Sem conexão com o PABX, tentando de novo...', 'warn'); });
    ua.on('registered', () => { reg = true; showErr(''); status('Registrado · ramal ' + c.ext, 'ok'); if (queued) { showWarn(''); dial(queued); } });
    ua.on('unregistered', () => { reg = false; status('Não registrado', 'warn'); });
    ua.on('registrationFailed', e => { reg = false; status('Falha no registro', 'bad'); showErr('Falha ao registrar o ramal ' + c.ext + ' (' + ((e && e.cause) || 'recusado') + '). Confira se o ramal é WebRTC e se o certificado do PABX foi aceito: abra ' + c.wss.replace('wss://', 'https://') + ' uma vez no navegador.'); });
    ua.on('newRTCSession', d => {
      const s = d.session; if (s.direction !== 'incoming') return;
      const who = num(s.remote_identity.uri.user) || s.remote_identity.display_name || 'Desconhecido';
      if (inCall || pend) { try { s.terminate({status_code: 486, reason_phrase: 'Busy Here'}); } catch (e) {} return; }
      pend = s; pendWho = who; $('in-who').textContent = (s.remote_identity.display_name ? s.remote_identity.display_name + ' · ' : '') + who; ui(); open(); ring(true);
      try { if ('Notification' in window && Notification.permission === 'granted') new Notification('Chamada recebida', {body: who}); } catch (e) {}
      const perdida = () => { if (pend === s) { ring(false); pend = null; hist({n: who, d: 'in', t: Date.now(), s: 0, r: 'mis'}); ui(); } };
      s.on('ended', perdida); s.on('failed', perdida);
    });
    ua.start();
  }
  function loadJsSip(cb) {
    if (window.JsSIP) return cb(true);
    const s = document.createElement('script'); s.src = url('jssip.min.js');
    s.onload = () => cb(true);
    s.onerror = () => { const c = document.createElement('script'); c.src = 'https://cdnjs.cloudflare.com/ajax/libs/jssip/3.10.1/jssip.min.js'; c.onload = () => cb(true); c.onerror = () => cb(false); document.head.appendChild(c); };
    document.head.appendChild(s);
  }

  // Navegar no painel recarrega a página: durante uma chamada, avisa antes de sair (o áudio cairia)
  // Ao sair da página, desregistra (Expires: 0) para o PABX não ficar testando conexões antigas já fechadas
  window.addEventListener('pagehide', () => { try { if (ua && !inCall) ua.stop(); } catch (e) {} });
  window.addEventListener('beforeunload', e => { if (inCall) { e.preventDefault(); e.returnValue = ''; return ''; } });

  // O botão verde "WebPhone" do topo é o gatilho e a bolinha dele mostra o estado (não existe botão flutuante: não cobre nada na tela)
  const pillEl = () => document.getElementById('webphone-pill');
  const PILL = {ok: '#10b981', warn: '#fbbf24', bad: '#ef4444', '': '#94a3b8'};
  function pillState(cls) {
    const d = document.getElementById('webphone-pill-dot'); if (!d) return;
    d.style.background = PILL[cls] || PILL['']; d.classList.toggle('animate-pulse', cls === 'warn' || !cls);
    const p = pillEl(); if (p) p.title = 'Webphone: ' + ({ok: 'registrado', warn: 'conectando...', bad: 'com falha'}[cls] || 'desconectado');
  }
  function placePanel() {
    const p = pillEl();
    host.style.setProperty('--wp-top', (p ? Math.round(p.getBoundingClientRect().bottom + 10) : 72) + 'px');
  }
  function boot() {
    document.body.appendChild(host); window.addEventListener('resize', placePanel);
    hist(); const d = ss.get('prisma_wp_num'); if (d) $('disp').value = d;
    status('Buscando credenciais...', 'warn');
    try { if ('Notification' in window && Notification.permission === 'default') Notification.requestPermission(); } catch (e) {}
    loadJsSip(ok => {
      if (!ok) { status('Indisponível', 'bad'); showErr('A biblioteca do telefone (JsSIP) não carregou. Verifique a conexão e recarregue a página.'); return; }
      fetch(url('webphone.php?cred=1'), {method: 'POST', credentials: 'same-origin', headers: {'X-Webphone': '1'}})
        .then(r => r.json()).then(j => { if (!j.success) { status('Indisponível', 'bad'); showErr(j.error || 'Falha ao obter o ramal.'); return; } start(j); })
        .catch(() => { status('Indisponível', 'bad'); showErr('Não foi possível falar com o painel. Recarregue a página.'); });
    });
    if (ss.get('prisma_wp_open') === '1') open();
  }
  window.PrismaWebphone = {open, close, toggle, dial, ready: () => reg};
  if (document.body) boot(); else document.addEventListener('DOMContentLoaded', boot);
})();
