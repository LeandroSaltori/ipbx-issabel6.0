<!DOCTYPE html>
<html lang="pt-BR">
<head>
<meta charset="utf-8">
<meta http-equiv="X-UA-Compatible" content="IE=edge">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>IPbx - Prisma Telecom - {$PAGE_NAME}</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Exo+2:wght@500;700;800&family=Noto+Sans:wght@400;600&display=swap">
{literal}
<style>
*{box-sizing:border-box}
:root{--az:#0079ad;--az2:#005b87;--vd:#9ccc3c;--tl:#2a9d8f;--card:rgba(10,24,48,.78)}
html,body{margin:0;min-height:100%}
body{font-family:'Noto Sans','Segoe UI',system-ui,sans-serif;color:#e6eef7;min-height:100vh;display:flex;align-items:center;justify-content:center;padding:24px;
 background:radial-gradient(1200px 700px at 12% 90%,rgba(156,204,60,.30),transparent 60%),radial-gradient(900px 600px at 95% 0%,rgba(42,157,143,.35),transparent 55%),linear-gradient(135deg,#00436b 0%,#006a9c 50%,#0a86b8 100%);overflow-x:hidden;position:relative}
.tri{position:fixed;pointer-events:none;opacity:.10;background:url('{/literal}{$WEBPATH}themes/{$THEMENAME}/images/login/triangulo.png{literal}') center/contain no-repeat}
.tri.a{width:520px;height:520px;left:-160px;top:-120px;transform:rotate(-14deg)}
.tri.b{width:380px;height:380px;right:-110px;bottom:-90px;transform:rotate(18deg)}
.wrap{position:relative;z-index:1;width:100%;max-width:1060px;display:grid;grid-template-columns:1.05fr 1fr;gap:40px;align-items:center}
.brand{position:relative;min-height:660px;padding-bottom:330px;display:flex;flex-direction:column;justify-content:flex-start}
.brand img.logo{width:300px;max-width:80%;height:auto}
.brand h1{font-family:'Exo 2',sans-serif;font-weight:800;font-size:40px;line-height:1.1;margin:28px 0 8px;letter-spacing:-.5px}
.brand h1 span{color:var(--vd)}
.brand p{margin:0;color:#cfe3f1;max-width:360px;font-size:15px;line-height:1.55}
.mascote{position:absolute;left:0;bottom:0;height:320px;width:auto;filter:drop-shadow(0 18px 24px rgba(0,30,60,.45))}
.bubble{position:absolute;left:150px;bottom:262px;background:#fff;color:#0b3550;border-radius:16px 16px 16px 4px;padding:10px 14px;font-weight:700;font-size:14px;box-shadow:0 10px 24px rgba(0,30,60,.35);max-width:210px}
.card{background:var(--card);backdrop-filter:blur(14px);-webkit-backdrop-filter:blur(14px);border:1px solid rgba(255,255,255,.14);border-radius:24px;padding:34px 32px;box-shadow:0 30px 70px rgba(0,25,50,.45)}
.card h2{font-family:'Exo 2',sans-serif;font-size:26px;font-weight:800;margin:0 0 4px}
.card .sub{margin:0 0 22px;color:#9fb8cf;font-size:14px}
.err{background:rgba(220,38,38,.16);border:1px solid rgba(248,113,113,.45);color:#fecaca;border-radius:12px;padding:10px 14px;margin-bottom:16px;font-size:14px;transition:opacity .8s}
.fld{position:relative;margin-bottom:14px}
.fld svg{position:absolute;left:14px;top:50%;transform:translateY(-50%);width:18px;height:18px;stroke:#7fa6c4;fill:none;stroke-width:1.8}
.fld input[type=text],.fld input[type=password]{width:100%;height:50px;border-radius:14px;border:1px solid rgba(255,255,255,.16);background:rgba(255,255,255,.07);color:#fff;font-size:15px;padding:0 46px 0 44px;outline:none;transition:.2s}
.fld input::placeholder{color:#7f9bb3}
.fld input:focus{border-color:var(--vd);background:rgba(255,255,255,.11);box-shadow:0 0 0 4px rgba(156,204,60,.18)}
.eye{position:absolute;right:6px;top:50%;transform:translateY(-50%);background:none;border:0;color:#7fa6c4;cursor:pointer;padding:8px;width:auto;flex-shrink:0}
.eye svg{position:static;transform:none;width:20px;height:20px}
.lbl{font-size:12px;font-weight:600;color:#9fb8cf;letter-spacing:.06em;text-transform:uppercase;margin:6px 0 8px}
.pn{display:grid;grid-template-columns:1fr 1fr;gap:10px;margin-bottom:18px}
.pn label{cursor:pointer;margin:0}
.pn input{position:absolute;opacity:0;pointer-events:none}
.pn .t{display:block;height:100%;border:1px solid rgba(255,255,255,.16);border-radius:14px;padding:12px 12px;background:rgba(255,255,255,.05);transition:.2s}
.pn .t b{display:block;font-family:'Exo 2',sans-serif;font-size:15px;white-space:nowrap}
.pn .t small{display:block;color:#9fb8cf;font-size:12px;margin-top:2px;line-height:1.35}
.pn input:checked + .t{background:linear-gradient(135deg,rgba(156,204,60,.25),rgba(0,121,173,.35));border-color:var(--vd);box-shadow:0 0 0 3px rgba(156,204,60,.15)}
.pn input:focus-visible + .t{outline:2px solid #fff}
.go{width:100%;height:52px;border:0;border-radius:14px;cursor:pointer;color:#06243a;font-family:'Exo 2',sans-serif;font-weight:800;font-size:17px;letter-spacing:.02em;background:linear-gradient(90deg,#a8d94a,#52b788 60%,#2a9d8f);box-shadow:0 12px 26px rgba(82,183,136,.35);display:flex;align-items:center;justify-content:center;gap:10px;transition:.2s;white-space:nowrap;flex-shrink:0;overflow:visible}
.go:hover{transform:translateY(-1px);filter:brightness(1.06)}
.go:disabled{opacity:.7;cursor:wait;transform:none}
.go svg{width:20px;height:20px;stroke:#06243a;fill:none;stroke-width:2.2}
.foot{text-align:center;margin-top:20px;font-size:12px;color:#86a5bf}
.foot a{color:#9fc7e3;text-decoration:none}
@media(max-width:900px){.wrap{grid-template-columns:1fr;max-width:460px;gap:22px}.brand{min-height:0;padding-bottom:0;align-items:center;text-align:center}.brand h1,.brand p,.mascote,.bubble{display:none}.brand img.logo{width:230px}}
@media(max-width:420px){.card{padding:26px 20px}.pn{grid-template-columns:1fr}}
</style>
{/literal}
</head>
<body>
<div class="tri a"></div><div class="tri b"></div>
<div class="wrap">
  <section class="brand">
    <img class="logo" src="{$WEBPATH}themes/{$THEMENAME}/images/login/logo_branco.png" alt="Prisma Telecom">
    <h1>Conecte.<br>Automatize.<br><span>Expanda.</span></h1>
    <p>Sua central telefônica, filas, relatórios e WhatsApp em um só lugar.</p>
    <div class="bubble">Olá! Escolha seu painel e vamos lá.</div>
    <img class="mascote" src="{$WEBPATH}themes/{$THEMENAME}/images/login/mascote.png" alt="">
  </section>

  <main class="card">
    <h2>Acesse sua central</h2>
    <p class="sub">Entre com seu usuário e senha.</p>
    {if $smarty.server.REQUEST_METHOD == 'POST' && $LOGIN_INCORRECT && $LOGIN_INCORRECT != ''}
    <div class="err" id="login_error_alert">Usuário ou senha incorretos. Tente novamente.</div>
    {literal}<script>
      if (window.history && window.history.replaceState) { window.history.replaceState(null, null, window.location.pathname); }
      setTimeout(function(){ var a=document.getElementById('login_error_alert'); if(a){ a.style.opacity='0'; setTimeout(function(){ a.style.display='none'; },900); } },4500);
    </script>{/literal}
    {/if}
    <form method="post" action="index.php" id="form_login" autocomplete="off">
      <div class="fld">
        <svg viewBox="0 0 24 24"><circle cx="12" cy="8" r="4"/><path d="M4 21c1.5-4 5-6 8-6s6.500 2 8 6"/></svg>
        <input type="text" name="input_user" id="input_user" placeholder="Usuário" autocomplete="off" autofocus required>
      </div>
      <div class="fld">
        <svg viewBox="0 0 24 24"><rect x="4" y="10" width="16" height="11" rx="2"/><path d="M8 10V7a4 4 0 0 1 8 0v3"/></svg>
        <input type="password" name="input_pass" id="input_pass" placeholder="Senha" autocomplete="off" required>
        <button type="button" class="eye" id="eye" aria-label="Mostrar senha"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M2 12s3.500-7 10-7 10 7 10 7-3.500 7-10 7S2 12 2 12z"/><circle cx="12" cy="12" r="3"/></svg></button>
      </div>
      <div class="lbl">Abrir em</div>
      <div class="pn" id="painel-sel">
        <label><input type="radio" name="painel" value="novo"><span class="t"><b>Painel Novo 6.0</b><small>Dashboard, filas ao vivo, relatórios e IA</small></span></label>
        <label><input type="radio" name="painel" value="classico" checked><span class="t"><b>Painel Clássico</b><small>Configurações completas da central</small></span></label>
      </div>
      <button type="submit" class="go" name="submit_login" id="go"><span>Entrar</span><svg viewBox="0 0 24 24"><path d="M5 12h14M13 6l6 6-6 6"/></svg></button>
    </form>
    <div class="foot"><a href="http://www.prismatelecom.com" target="_blank" rel="noopener">IPbx | Prisma Telecom 2006 - {$currentyear}.</a></div>
  </main>
</div>
{literal}
<script>
(function(){
  try {
    var m = document.cookie.match(/(?:^|; )ipbx_painel=([^;]+)/);
    if (m) { var r = document.querySelector('#painel-sel input[value="' + m[1] + '"]'); if (r) r.checked = true; }
  } catch (e) {}
  var f = document.getElementById('form_login'), go = document.getElementById('go');
  f.addEventListener('submit', function(){
    try { var c = document.querySelector('#painel-sel input:checked'); if (c) document.cookie = 'ipbx_painel=' + c.value + '; path=/; max-age=31536000; SameSite=Lax'; } catch (e) {}
    setTimeout(function(){ go.disabled = true; go.firstChild.textContent = 'Entrando...'; }, 0);
  });
  document.getElementById('eye').addEventListener('click', function(){
    var p = document.getElementById('input_pass'); p.type = (p.type === 'password') ? 'text' : 'password';
  });
})();
</script>
{/literal}
</body>
</html>
