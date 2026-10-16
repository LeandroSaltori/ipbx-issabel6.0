<?php
/**
 * Player de gravação (janela flutuante) — mesmo comportamento do relatório clássico:
 * barra de progresso, -5s/+5s, velocidade 1x/1.25x/1.5x/2x e download.
 * Uso: require_once __DIR__ . '/../../includes/audio_player.php'; e chamar ipbxOpenPlayer(uid, origem, destino).
 */
if (defined('IPBX_AUDIO_PLAYER_LOADED')) return;
define('IPBX_AUDIO_PLAYER_LOADED', 1);
?>
<style>
#ipbx-player{border:none;background:transparent;padding:0;margin:auto;overflow:visible;outline:none}
#ipbx-player::backdrop{background:rgba(2,6,23,.7);backdrop-filter:blur(3px)}
#ipbx-player .ap-card{background:linear-gradient(145deg,#1e1b4b 0%,#0f172a 100%);border:1px solid rgba(139,92,246,.65);border-radius:16px;box-shadow:0 25px 60px -10px rgba(0,0,0,.85),0 0 35px rgba(124,58,237,.45);width:min(520px,92vw);color:#fff;overflow:hidden}
#ipbx-player .ap-head{padding:16px 20px;background:rgba(255,255,255,.06);border-bottom:1px solid rgba(255,255,255,.1);display:flex;justify-content:space-between;align-items:center;gap:12px}
#ipbx-player .ap-ico{font-size:22px;background:rgba(124,58,237,.3);border:1px solid rgba(139,92,246,.4);padding:8px;border-radius:10px}
#ipbx-player .ap-title{margin:0;font-size:16px;font-weight:800;color:#f8fafc}
#ipbx-player .ap-meta{margin-top:3px;font-size:12px;color:#cbd5e1;font-weight:600}
#ipbx-player .ap-body{padding:20px}
#ipbx-player .ap-time{display:flex;justify-content:space-between;font-size:12px;color:#94a3b8;font-weight:700;margin-bottom:6px}
#ipbx-player input[type=range]{width:100%;height:6px;cursor:pointer;accent-color:#8b5cf6}
#ipbx-player .ap-row{display:flex;justify-content:center;align-items:center;gap:14px;margin:18px 0 20px}
#ipbx-player .ap-btn,#ipbx-player .ap-x,#ipbx-player .ap-sp{white-space:nowrap !important;flex-shrink:0 !important;width:auto !important;overflow:visible !important;text-overflow:clip !important;cursor:pointer;font-weight:700;color:#fff}
#ipbx-player .ap-btn{background:rgba(255,255,255,.1);border:1px solid rgba(255,255,255,.15);padding:8px 14px;border-radius:8px;font-size:13px}
#ipbx-player .ap-main{background:linear-gradient(135deg,#7c3aed 0%,#6366f1 100%);border:none;padding:10px 24px;border-radius:8px;font-size:14px;box-shadow:0 4px 12px rgba(124,58,237,.4)}
#ipbx-player .ap-x{background:rgba(255,255,255,.1);border:1px solid rgba(255,255,255,.15);width:32px !important;height:32px;border-radius:8px}
#ipbx-player .ap-foot{display:flex;justify-content:space-between;align-items:center;gap:12px;padding-top:14px;border-top:1px solid rgba(255,255,255,.1);flex-wrap:wrap}
#ipbx-player .ap-sp{background:rgba(255,255,255,.08);border:1px solid rgba(255,255,255,.12);color:#94a3b8;border-radius:6px;font-size:11px;padding:4px 8px}
#ipbx-player .ap-sp.on{background:#8b5cf6;border-color:#8b5cf6;color:#fff}
#ipbx-player .ap-dl{white-space:nowrap !important;display:inline-flex;align-items:center;gap:6px;background:rgba(16,185,129,.2);border:1px solid rgba(16,185,129,.4);color:#34d399;padding:6px 14px;border-radius:6px;font-size:11px;font-weight:700;text-decoration:none}
#ipbx-player .ap-err{display:none;margin-top:12px;color:#fda4af;font-size:12px;font-weight:700;text-align:center}
</style>
<dialog id="ipbx-player">
  <div class="ap-card">
    <div class="ap-head">
      <div style="display:flex;align-items:center;gap:12px">
        <span class="ap-ico">🎧</span>
        <div><h3 class="ap-title">Reproduzindo Gravação</h3>
          <div class="ap-meta"><span id="ap-caller">📞 Origem: -</span> ➔ <span id="ap-target">🎯 Destino: -</span></div></div>
      </div>
      <button type="button" class="ap-x" onclick="ipbxClosePlayer()" title="Fechar">✖</button>
    </div>
    <div class="ap-body">
      <div class="ap-time"><span id="ap-cur">00:00</span><span id="ap-tot">00:00</span></div>
      <input type="range" id="ap-bar" min="0" max="100" value="0" step="0.1" oninput="ipbxSeekTo(this.value)">
      <div class="ap-row">
        <button type="button" class="ap-btn" onclick="ipbxSeekRel(-5)" title="Voltar 5 segundos">⏮ -5s</button>
        <button type="button" class="ap-btn ap-main" id="ap-play" onclick="ipbxTogglePlay()">⏸ Pausar</button>
        <button type="button" class="ap-btn" onclick="ipbxSeekRel(5)" title="Avançar 5 segundos">+5s ⏭</button>
      </div>
      <div class="ap-foot">
        <div style="display:flex;align-items:center;gap:5px;flex-wrap:wrap">
          <span style="font-size:11px;color:#94a3b8;font-weight:600">Velocidade:</span>
          <button type="button" class="ap-sp on" onclick="ipbxSetSpeed(1,this)">1.0x</button>
          <button type="button" class="ap-sp" onclick="ipbxSetSpeed(1.25,this)">1.25x</button>
          <button type="button" class="ap-sp" onclick="ipbxSetSpeed(1.5,this)">1.5x</button>
          <button type="button" class="ap-sp" onclick="ipbxSetSpeed(2,this)">2.0x</button>
        </div>
        <a id="ap-dl" class="ap-dl" href="#" download title="Baixar gravação">⬇️ Baixar Gravação</a>
      </div>
      <div class="ap-err" id="ap-err">Gravação indisponível: arquivo não encontrado ou sem conversor no servidor.</div>
    </div>
  </div>
  <audio id="ap-audio" preload="auto"></audio>
</dialog>
<script>
(function () {
  var A = function () { return document.getElementById('ap-audio'); };
  function fmt(s) { if (!s || isNaN(s) || !isFinite(s) || s < 0) return '00:00'; var m = Math.floor(s / 60), r = Math.floor(s % 60); return (m < 10 ? '0' : '') + m + ':' + (r < 10 ? '0' : '') + r; }
  function btn(p) { var b = document.getElementById('ap-play'); b.innerHTML = p ? '⏸ Pausar' : '▶ Continuar'; }
  var a = A();
  a.addEventListener('timeupdate', function () { document.getElementById('ap-cur').textContent = fmt(a.currentTime); if (a.duration) document.getElementById('ap-bar').value = (a.currentTime / a.duration) * 100; });
  a.addEventListener('loadedmetadata', function () { document.getElementById('ap-tot').textContent = fmt(a.duration); });
  a.addEventListener('ended', function () { btn(false); });
  a.addEventListener('error', function () { if (a.getAttribute('src')) document.getElementById('ap-err').style.display = 'block'; });
  window.ipbxOpenPlayer = function (uid, caller, target) {
    var d = document.getElementById('ipbx-player');
    if (d.parentElement !== document.body) document.body.appendChild(d);
    var url = 'get_audio.php?uid=' + encodeURIComponent(uid);
    document.getElementById('ap-caller').textContent = '📞 Origem: ' + (caller || '-');
    document.getElementById('ap-target').textContent = '🎯 Destino: ' + (target || '-');
    document.getElementById('ap-dl').href = url;
    document.getElementById('ap-err').style.display = 'none';
    document.getElementById('ap-cur').textContent = '00:00'; document.getElementById('ap-tot').textContent = '00:00'; document.getElementById('ap-bar').value = 0;
    if (typeof d.showModal === 'function') { if (!d.open) d.showModal(); } else d.setAttribute('open', '');
    a.src = url; a.playbackRate = 1;
    document.querySelectorAll('#ipbx-player .ap-sp').forEach(function (b, i) { b.classList.toggle('on', i === 0); });
    var p = a.play(); if (p && p.then) p.then(function () { btn(true); }).catch(function () { btn(false); });
  };
  window.ipbxClosePlayer = function () { a.pause(); a.removeAttribute('src'); a.load(); var d = document.getElementById('ipbx-player'); if (d.close) { if (d.open) d.close(); } else d.removeAttribute('open'); btn(false); };
  window.ipbxTogglePlay = function () { if (a.paused) { a.play(); btn(true); } else { a.pause(); btn(false); } };
  window.ipbxSeekRel = function (s) { a.currentTime = Math.max(0, Math.min(a.duration || 0, a.currentTime + s)); };
  window.ipbxSeekTo = function (v) { if (a.duration) a.currentTime = (v / 100) * a.duration; };
  window.ipbxSetSpeed = function (v, b) { a.playbackRate = v; document.querySelectorAll('#ipbx-player .ap-sp').forEach(function (x) { x.classList.remove('on'); }); if (b) b.classList.add('on'); };
  document.getElementById('ipbx-player').addEventListener('close', function () { a.pause(); });
})();
</script>
