<?php
/**
 * IPbx Prisma - Webphone (WebRTC) do painel
 * Janela própria: fica registrada no PABX enquanto estiver aberta (o painel navega por páginas inteiras,
 * então um telefone dentro dele perderia o registro a cada clique e chamadas recebidas cairiam).
 *
 * GET              -> a página do telefone
 * POST ?cred=1     -> JSON com o ramal e a senha do PRÓPRIO usuário logado (nunca de outro) para registrar no PABX.
 *                    A senha vem do IssabelPBX (não é guardada no painel) e só sai se o webphone estiver habilitado
 *                    para o usuário em Configurações > Usuários.
 */
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/functions.php';

// ---------- Credenciais (JSON) ----------
if (isset($_GET['cred'])) {
    authGate('json');
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    if ($_SERVER['REQUEST_METHOD'] !== 'POST' || ($_SERVER['HTTP_X_WEBPHONE'] ?? '') !== '1') {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Requisição inválida.']);
        exit;
    }
    $u = getLoggedUser();
    $ext = preg_replace('/[^0-9A-Za-z_\-]/', '', (string)($u['extension'] ?? ''));
    if (!$u || empty($u['webphone']) || $ext === '') {
        echo json_encode(['success' => false, 'error' => 'O Webphone não está habilitado para o seu usuário. Peça ao administrador em Configurações > Usuários.']);
        exit;
    }
    $secret = ''; $webrtc = false;
    try {
        $ast = getAsteriskDBConnection();
        if ($ast) {
            foreach (['pjsip', 'sip'] as $tbl) {
                try {
                    $st = $ast->prepare("SELECT keyword, value FROM $tbl WHERE id = :id AND keyword IN ('secret','webrtc','transport','media_encryption','dtlsenable')");
                    $st->execute([':id' => $ext]);
                    while ($r = $st->fetch(PDO::FETCH_ASSOC)) {
                        $k = strtolower($r['keyword']); $v = strtolower(trim((string)$r['value']));
                        if ($k === 'secret' && $secret === '') $secret = (string)$r['value'];
                        if (($k === 'webrtc' && $v === 'yes') || ($k === 'transport' && strpos($v, 'ws') !== false)
                            || ($k === 'media_encryption' && $v === 'dtls') || ($k === 'dtlsenable' && $v === 'yes')) $webrtc = true;
                    }
                } catch (Exception $e) { /* tabela inexistente nesta instalação */ }
            }
        }
    } catch (Exception $e) {}
    if ($secret === '') {
        echo json_encode(['success' => false, 'error' => "Não foi possível ler a senha do ramal $ext no PABX."]);
        exit;
    }
    $host = explode(':', (string)($_SERVER['HTTP_HOST'] ?? ''))[0];
    $stun = trim((string)getSetting('webphone_stun'));
    echo json_encode([
        'success' => true, 'ext' => $ext, 'secret' => $secret, 'name' => (string)($u['name'] ?? ''),
        'wss' => 'wss://' . $host . ':8089/ws', 'host' => $host, 'webrtc' => $webrtc, 'stun' => $stun,
    ]);
    exit;
}

// ---------- Página ----------
authGate('page');
$u = getLoggedUser();
$habilitado = ($u && !empty($u['webphone']) && !empty($u['extension']));
header('Cache-Control: no-store');
?><!DOCTYPE html>
<html lang="pt-BR">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Webphone - IPbx Prisma</title>
<link rel="icon" type="image/png" href="favicon.png">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
<script src="jssip.min.js" onerror="this.onerror=null;this.src='https://cdnjs.cloudflare.com/ajax/libs/jssip/3.10.1/jssip.min.js';"></script>
<style>
:root{--bg:#0f172a;--card:#1c1b2f;--card2:#252440;--bd:#334155;--tx:#e2e8f0;--mu:#94a3b8;--ok:#10b981;--bad:#f43f5e;--warn:#f59e0b}
*{box-sizing:border-box}
body{margin:0;background:var(--bg);color:var(--tx);font-family:'Segoe UI',Inter,system-ui,sans-serif;font-size:13px;min-height:100vh;display:flex;flex-direction:column}
header{padding:12px 14px;background:linear-gradient(135deg,#1c1b2f,#252440);border-bottom:1px solid var(--bd);display:flex;align-items:center;gap:10px}
header .ic{width:34px;height:34px;border-radius:50%;background:rgba(16,185,129,.15);color:var(--ok);display:flex;align-items:center;justify-content:center;border:1px solid rgba(16,185,129,.35)}
header b{display:block;font-size:14px}
#st{font-size:11px;color:var(--mu)}
#st.ok{color:var(--ok)} #st.bad{color:var(--bad)} #st.warn{color:var(--warn)}
main{padding:14px;flex:1;display:flex;flex-direction:column;gap:10px}
.box{background:var(--card);border:1px solid var(--bd);border-radius:14px;padding:12px}
.alert{border-radius:10px;padding:9px 11px;font-size:12px;line-height:1.35;display:none}
.alert.show{display:block}
.alert.err{background:rgba(244,63,94,.1);color:#fda4af;border:1px solid rgba(244,63,94,.35)}
.alert.warn{background:rgba(245,158,11,.1);color:#fcd34d;border:1px solid rgba(245,158,11,.35)}
#disp{width:100%;background:#0b1220;border:1px solid var(--bd);border-radius:12px;color:#fff;font-size:20px;font-weight:700;text-align:center;padding:11px;letter-spacing:1px}
#disp:focus{outline:none;border-color:#6366f1}
.pad{display:grid;grid-template-columns:repeat(3,1fr);gap:7px}
.pad button{padding:11px 0;border-radius:12px;background:var(--card2);border:1px solid var(--bd);color:#fff;font-size:17px;font-weight:700;cursor:pointer}
.pad button:hover{background:#312e5a}
.btn{white-space:nowrap!important;flex-shrink:0!important;width:auto!important;overflow:visible!important;text-overflow:clip!important;padding:10px 14px;border-radius:12px;border:0;color:#fff;font-weight:800;font-size:13px;cursor:pointer;display:inline-flex;align-items:center;justify-content:center;gap:7px}
.row{display:flex;gap:8px}.row .btn{flex:1}
.call{background:linear-gradient(135deg,#059669,#10b981)}.hang{background:linear-gradient(135deg,#e11d48,#f43f5e)}
.sec{background:var(--card2);border:1px solid var(--bd)}.sec.on{background:linear-gradient(135deg,#7c3aed,#6366f1);border-color:#6366f1}
#incoming,#incall{display:none}
#incoming.show,#incall.show{display:block}
#incoming{border-color:rgba(245,158,11,.5);animation:pulse 1.2s infinite}
@keyframes pulse{50%{box-shadow:0 0 0 4px rgba(245,158,11,.18)}}
.who{font-size:18px;font-weight:800;text-align:center;margin:2px 0 2px}.sub{text-align:center;color:var(--mu);font-size:11px;margin-bottom:10px}
#tm{font-variant-numeric:tabular-nums}
.q{display:flex;align-items:center;justify-content:center;gap:6px;font-size:11px;color:var(--mu);margin-top:8px}
.dot{width:9px;height:9px;border-radius:50%;background:var(--mu)}
.lvl{height:5px;border-radius:4px;background:#0b1220;overflow:hidden;margin-top:6px}.lvl i{display:block;height:100%;width:0;background:linear-gradient(90deg,#10b981,#6366f1);transition:width .15s}
#xfer{display:none;margin-top:8px}#xfer.show{display:flex;gap:6px}
#xfer input{flex:1;min-width:0;background:#0b1220;border:1px solid var(--bd);border-radius:10px;color:#fff;padding:8px 10px}
h4{margin:0 0 7px;font-size:11px;text-transform:uppercase;letter-spacing:.6px;color:var(--mu)}
.h{display:flex;align-items:center;gap:8px;padding:6px 4px;border-bottom:1px solid #26263f;cursor:pointer;border-radius:8px}.h:hover{background:#252440}
.h:last-child{border-bottom:0}.h span.n{flex:1;font-weight:600}.h span.d{color:var(--mu);font-size:11px}
.out{color:var(--ok)}.inn{color:#818cf8}.mis{color:var(--bad)}
</style>
</head>
<body>
<header>
  <div class="ic"><i class="fa-solid fa-headset"></i></div>
  <div><b>Webphone</b><span id="st">Desconectado</span></div>
</header>
<main>
<?php if (!$habilitado): ?>
  <div class="box">O Webphone não está habilitado para o seu usuário.<br><span style="color:var(--mu)">Peça ao administrador para habilitar em Configurações &gt; Usuários (é preciso ter um ramal vinculado).</span></div>
<?php else: ?>
  <div id="al-err" class="alert err"></div>
  <div id="al-warn" class="alert warn"></div>

  <div id="incoming" class="box">
    <div class="who" id="in-who">-</div><div class="sub">Chamada recebida</div>
    <div class="row"><button class="btn call" onclick="answerIn()"><i class="fa-solid fa-phone"></i> Atender</button><button class="btn hang" onclick="rejectIn()"><i class="fa-solid fa-phone-slash"></i> Recusar</button></div>
  </div>

  <div id="incall" class="box">
    <div class="who" id="ic-who">-</div><div class="sub"><span id="ic-state">Chamando...</span> · <span id="tm">00:00</span></div>
    <div class="row">
      <button class="btn sec" id="b-mute" onclick="toggleMute()"><i class="fa-solid fa-microphone-slash"></i> Mudo</button>
      <button class="btn sec" id="b-hold" onclick="toggleHold()"><i class="fa-solid fa-pause"></i> Espera</button>
      <button class="btn sec" onclick="document.getElementById('xfer').classList.toggle('show')"><i class="fa-solid fa-right-left"></i> Transferir</button>
    </div>
    <div id="xfer"><input id="xnum" placeholder="Ramal ou número" inputmode="tel"><button class="btn call" onclick="doTransfer()">OK</button></div>
    <div class="lvl" title="Nível do seu microfone"><i id="lv"></i></div>
    <div class="q"><span class="dot" id="qd"></span><span id="qt">Qualidade: medindo...</span></div>
    <div class="row" style="margin-top:10px"><button class="btn hang" onclick="hangup()"><i class="fa-solid fa-phone-slash"></i> Desligar</button></div>
  </div>

  <div class="box">
    <input id="disp" placeholder="Número ou ramal" inputmode="tel" autocomplete="off">
    <div class="pad" style="margin-top:9px">
      <?php foreach (['1','2','3','4','5','6','7','8','9','*','0','#'] as $k): ?><button onclick="key('<?php echo $k; ?>')"><?php echo $k; ?></button><?php endforeach; ?>
    </div>
    <div class="row" style="margin-top:9px">
      <button class="btn call" id="b-call" onclick="dial()"><i class="fa-solid fa-phone"></i> Ligar</button>
      <button class="btn sec" onclick="document.getElementById('disp').value=document.getElementById('disp').value.slice(0,-1)"><i class="fa-solid fa-delete-left"></i> Apagar</button>
    </div>
  </div>

  <div class="box"><h4>Últimas chamadas deste navegador</h4><div id="hist"><span style="color:var(--mu)">Nenhuma chamada ainda.</span></div></div>
<?php endif; ?>
</main>
<audio id="ra" autoplay></audio>
<?php if ($habilitado): ?>
<script>
(function () {
  const $ = id => document.getElementById(id);
  let ua = null, cfg = null, cur = null, pend = null, pendWho = '', inCall = false, muted = false, held = false, t0 = 0, tmr = null, stTmr = null, ringT = null, actx = null, reg = false;

  function status(txt, cls) { const s = $('st'); s.textContent = txt; s.className = cls || ''; }
  function showErr(m) { const a = $('al-err'); a.textContent = m || ''; a.classList.toggle('show', !!m); }
  function showWarn(m) { const a = $('al-warn'); a.textContent = m || ''; a.classList.toggle('show', !!m); }
  const num = s => String(s || '').replace(/[^0-9*#+]/g, '');

  // ---- histórico (só neste navegador) ----
  function hist(add) {
    let h = []; try { h = JSON.parse(localStorage.getItem('prisma_wp_hist') || '[]'); } catch (e) {}
    if (add) { h.unshift(add); h = h.slice(0, 10); try { localStorage.setItem('prisma_wp_hist', JSON.stringify(h)); } catch (e) {} }
    const box = $('hist'); if (!box) return; box.textContent = '';
    if (!h.length) { const s = document.createElement('span'); s.style.color = 'var(--mu)'; s.textContent = 'Nenhuma chamada ainda.'; box.appendChild(s); return; }
    h.forEach(x => {
      const d = document.createElement('div'); d.className = 'h'; d.title = 'Ligar de novo';
      const i = document.createElement('i'); i.className = 'fa-solid ' + (x.r === 'mis' ? 'fa-phone-slash mis' : x.d === 'in' ? 'fa-arrow-down inn' : 'fa-arrow-up out');
      const n = document.createElement('span'); n.className = 'n'; n.textContent = x.n;
      const t = document.createElement('span'); t.className = 'd'; t.textContent = new Date(x.t).toLocaleTimeString('pt-BR', {hour: '2-digit', minute: '2-digit'}) + (x.s ? ' · ' + x.s + 's' : '');
      d.append(i, n, t); d.onclick = () => { $('disp').value = x.n; };
      box.appendChild(d);
    });
  }

  // ---- toque de chamada (sem arquivo de áudio) ----
  function ring(on) {
    clearInterval(ringT); ringT = null;
    if (!on) return;
    const beep = () => { try {
      actx = actx || new (window.AudioContext || window.webkitAudioContext)();
      [0, 0.25].forEach(d => { const o = actx.createOscillator(), g = actx.createGain(); o.frequency.value = 440 + (d ? 40 : 0); g.gain.value = 0.08;
        o.connect(g); g.connect(actx.destination); o.start(actx.currentTime + d); o.stop(actx.currentTime + d + 0.2); });
    } catch (e) {} };
    beep(); ringT = setInterval(beep, 2000);
  }

  // ---- estado da chamada ----
  function ui() {
    $('incall').classList.toggle('show', inCall);
    $('incoming').classList.toggle('show', !!pend && !inCall);
    $('b-call').disabled = inCall;
  }
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
  let hungByMe = false;
  function bind(s, dir, who) {
    hungByMe = false; attachAudio(s.connection);
    cur = s; inCall = true; muted = false; held = false; t0 = Date.now();
    $('ic-who').textContent = who; $('ic-state').textContent = dir === 'in' ? 'Conectando...' : 'Chamando...';
    $('b-mute').classList.remove('on'); $('b-hold').classList.remove('on'); $('xfer').classList.remove('show'); $('tm').textContent = '00:00';
    ui();
    let connected = false;
    s.on('progress', () => { $('ic-state').textContent = 'Chamando...'; });
    s.on('accepted', () => { connected = true; t0 = Date.now(); $('ic-state').textContent = 'Em chamada'; clearInterval(tmr); tmr = setInterval(tick, 1000); watch(s); });
    s.on('confirmed', () => { connected = true; attachAudio(s.connection); fallbackAudio(s); });
    s.on('peerconnection', e => attachAudio(e.peerconnection));
    const end = (why) => {
      clearInterval(tmr); clearInterval(stTmr); ring(false);
      hist({n: who, d: dir, t: Date.now(), s: connected ? Math.floor((Date.now() - t0) / 1000) : 0, r: connected ? 'ok' : (dir === 'in' ? 'mis' : 'no')});
      cur = null; inCall = false; $('ra').srcObject = null; $('lv').style.width = '0'; ui();
      if (!connected && why && dir === 'out' && !hungByMe) showErr('Chamada não completada: ' + (CAUSAS[why] || why));
    };
    s.on('ended', () => end(''));
    s.on('failed', e => end((e && e.cause) || 'falha'));
    s.on('hold', () => { $('ic-state').textContent = 'Em espera'; });
    s.on('unhold', () => { $('ic-state').textContent = 'Em chamada'; });
  }

  // diagnóstico: qualidade (RTT, jitter, perda) e nível do microfone
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
        $('qd').style.background = ruim ? 'var(--bad)' : medio ? 'var(--warn)' : 'var(--ok)';
        $('qt').textContent = 'Qualidade: ' + (ruim ? 'ruim' : medio ? 'regular' : 'boa') + (rtt != null ? ' · ' + Math.round(rtt) + ' ms' : '') + (jit != null ? ' · jitter ' + Math.round(jit) + ' ms' : '') + ' · perda ' + perda.toFixed(1) + '%';
        if (lvl != null) $('lv').style.width = Math.min(100, Math.round(lvl * 180)) + '%';
      } catch (e) {}
    }, 1000);
  }

  // ---- ações ----
  window.key = k => {
    if (cur && inCall) {
      try { cur.sendDTMF(k); } catch (e) {}
      const d = $('ic-state'); d.textContent = 'Enviou tom ' + k;
      setTimeout(() => { if (inCall) d.textContent = held ? 'Em espera' : 'Em chamada'; }, 700);
    } else { $('disp').value += k; }
  };
  window.dial = () => {
    showErr(''); const n = num($('disp').value);
    if (!ua || !reg) { showErr('Ainda não registrado no PABX. Aguarde "Registrado" no topo.'); return; }
    if (!n) { showErr('Digite um número ou ramal.'); return; }
    if (inCall) return;
    const opts = {mediaConstraints: {audio: true, video: false}, pcConfig: cfg.pc, rtcOfferConstraints: {offerToReceiveAudio: true}, eventHandlers: {peerconnection: e => attachAudio(e.peerconnection)}};
    const s = ua.call('sip:' + n + '@' + cfg.host, opts);
    bind(s, 'out', n);
  };
  window.hangup = () => { hungByMe = true; if (cur) { try { cur.terminate(); } catch (e) {} } };
  window.toggleMute = () => { if (!cur) return; muted = !muted; muted ? cur.mute({audio: true}) : cur.unmute({audio: true}); $('b-mute').classList.toggle('on', muted); };
  window.toggleHold = () => { if (!cur) return; held = !held; held ? cur.hold() : cur.unhold(); $('b-hold').classList.toggle('on', held); };
  window.doTransfer = () => { const n = num($('xnum').value); if (!cur || !n) return; try { cur.refer('sip:' + n + '@' + cfg.host); $('ic-state').textContent = 'Transferindo para ' + n + '...'; $('xnum').value = ''; $('xfer').classList.remove('show'); } catch (e) { showErr('Não foi possível transferir.'); } };
  window.answerIn = () => { if (!pend) return; ring(false); const s = pend, who = pendWho; pend = null; bind(s, 'in', who); s.answer({mediaConstraints: {audio: true, video: false}, pcConfig: cfg.pc, eventHandlers: {peerconnection: e => attachAudio(e.peerconnection)}}); };
  window.rejectIn = () => { if (!pend) return; ring(false); const s = pend; pend = null; try { s.terminate({status_code: 486, reason_phrase: 'Busy Here'}); } catch (e) {} ui(); };
  $('disp').addEventListener('keydown', e => { if (e.key === 'Enter') dial(); });

  // chamada pedida pelo painel (clique para ligar)
  window.addEventListener('message', e => {
    if (e.origin !== location.origin || !e.data || e.data.type !== 'webphone-dial') return;
    $('disp').value = num(e.data.number); dial();
  });

  // ---- registro ----
  function start(c) {
    cfg = {host: c.host, pc: {iceServers: c.stun ? [{urls: c.stun}] : []}};
    if (!window.JsSIP) { showErr('A biblioteca do telefone (JsSIP) não carregou. Verifique a conexão e recarregue.'); status('Indisponível', 'bad'); return; }
    if (!c.webrtc) showWarn('O ramal ' + c.ext + ' não parece estar configurado como WebRTC no PABX. Se o registro falhar, peça ao administrador para ativar WebRTC neste ramal.');
    const sock = new JsSIP.WebSocketInterface(c.wss);
    ua = new JsSIP.UA({sockets: [sock], uri: 'sip:' + c.ext + '@' + c.host, password: c.secret, register: true, register_expires: 300, user_agent: 'IPbx-Prisma-Webphone', connection_recovery_min_interval: 2, connection_recovery_max_interval: 20});
    ua.on('connecting', () => { if (!reg) status('Conectando...', 'warn'); });
    ua.on('connected', () => { showErr(''); });
    ua.on('disconnected', () => { reg = false; status('Sem conexão com o PABX, tentando de novo...', 'warn'); });
    ua.on('registered', () => { reg = true; showErr(''); status('Registrado · ramal ' + c.ext, 'ok'); });
    ua.on('unregistered', () => { reg = false; status('Não registrado', 'warn'); });
    ua.on('registrationFailed', e => { reg = false; status('Falha no registro', 'bad'); showErr('Falha ao registrar o ramal ' + c.ext + ' (' + ((e && e.cause) || 'recusado') + '). Confira se o ramal é WebRTC e se o certificado do PABX foi aceito: abra ' + c.wss.replace('wss://', 'https://') + ' uma vez no navegador.'); });
    ua.on('newRTCSession', d => {
      const s = d.session;
      if (s.direction !== 'incoming') return;
      const who = num(s.remote_identity.uri.user) || s.remote_identity.display_name || 'Desconhecido';
      if (inCall || pend) { try { s.terminate({status_code: 486, reason_phrase: 'Busy Here'}); } catch (e) {} return; }
      pend = s; pendWho = who; $('in-who').textContent = (s.remote_identity.display_name ? s.remote_identity.display_name + ' · ' : '') + who; ui(); ring(true);
      try { if ('Notification' in window && Notification.permission === 'granted') new Notification('Chamada recebida', {body: who}); } catch (e) {}
      s.on('ended', () => { if (pend === s) { ring(false); pend = null; hist({n: who, d: 'in', t: Date.now(), s: 0, r: 'mis'}); ui(); } });
      s.on('failed', () => { if (pend === s) { ring(false); pend = null; hist({n: who, d: 'in', t: Date.now(), s: 0, r: 'mis'}); ui(); } });
    });
    ua.start();
  }

  status('Buscando credenciais...', 'warn'); hist();
  try { if ('Notification' in window && Notification.permission === 'default') Notification.requestPermission(); } catch (e) {}
  fetch('webphone.php?cred=1', {method: 'POST', credentials: 'same-origin', headers: {'X-Webphone': '1'}})
    .then(r => r.json()).then(j => { if (!j.success) { status('Indisponível', 'bad'); showErr(j.error || 'Falha ao obter o ramal.'); return; } start(j); })
    .catch(() => { status('Indisponível', 'bad'); showErr('Não foi possível falar com o painel. Recarregue a página.'); });
  window.addEventListener('beforeunload', () => { try { if (ua) ua.stop(); } catch (e) {} });
})();
</script>
<?php endif; ?>
</body>
</html>
