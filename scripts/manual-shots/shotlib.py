"""Captura de figuras do manual: abre a tela do painel, destaca partes com caixa + numero e recorta.
Uso: python3 shotlib.py <specs.py> [id ...]   (BASE e pasta de saida via variaveis de ambiente)"""
import asyncio, json, os, sys, importlib.util
from playwright.async_api import async_playwright
BASE = os.environ.get('SHOT_BASE', 'http://127.0.0.1:18802/')
SHOTDIR = os.environ.get('SHOT_ASSETS', '/tmp/shot/')
OUT = os.environ.get('SHOT_OUT', '/tmp/shot/out/')
IMGDIR = os.environ.get('SHOT_IMG', '/home/claude/ipbx-issabel6.0/src/front_api/img/')
TW = open(SHOTDIR + 'tw.css').read()
FAD = SHOTDIR + 'node_modules/@fortawesome/fontawesome-free/'
FA = open(FAD + 'css/all.min.css').read().replace('../webfonts/', BASE + '__fa/')

async def route(r):
    u = r.request.url
    if u.startswith(BASE + '__fa/'):
        return await r.fulfill(path=FAD + 'webfonts/' + u.split('/__fa/')[1].split('?')[0])
    if u.startswith(BASE + 'img/'):
        fp = IMGDIR + u.split('/img/')[1].split('?')[0]
        if os.path.isfile(fp): return await r.fulfill(path=fp)
    if u.startswith(BASE + 'themes/'):
        fp = '/home/claude/ipbx-issabel6.0/src/themes/' + u.split('/themes/')[1].split('?')[0]
        if os.path.isfile(fp): return await r.fulfill(path=fp)
    if u.startswith('http://127.0.0.1'): return await r.continue_()
    if 'cdn.tailwindcss.com' in u:
        return await r.fulfill(content_type='application/javascript', body="window.tailwind={};(function(){var s=document.createElement('style');s.textContent=" + json.dumps(TW) + ";document.head.appendChild(s)})();")
    if 'font-awesome' in u and u.endswith('.css'): return await r.fulfill(content_type='text/css', body=FA)
    if 'chart.js' in u: return await r.fulfill(path=SHOTDIR + 'chart.umd.js', content_type='application/javascript')
    return await r.abort()

# desenha caixas e numeros (position:fixed) e devolve o retangulo que reune tudo
MARK_JS = r"""
(marks)=>{
  document.querySelectorAll('.__mk').forEach(e=>e.remove());
  const deepAll=(sel,root=document)=>{let out=[...root.querySelectorAll(sel)]; root.querySelectorAll('*').forEach(e=>{ if(e.shadowRoot) out=out.concat(deepAll(sel,e.shadowRoot)); }); return out;};
  const rects=[]; const sty=document.createElement('style'); sty.className='__mk';
  sty.textContent='.__mk-b{position:fixed;border:3px solid #f59e0b;border-radius:10px;box-shadow:0 0 0 4px rgba(245,158,11,.25);z-index:2147483646;pointer-events:none}.__mk-n{position:fixed;width:26px;height:26px;border-radius:50%;background:#f59e0b;color:#111;font:800 14px/26px Inter,Arial,sans-serif;text-align:center;z-index:2147483647;box-shadow:0 2px 6px rgba(0,0,0,.5);pointer-events:none}';
  document.head.appendChild(sty); const HOST=document.querySelector('dialog[open]')||document.body; if(HOST!==document.body){ HOST.appendChild(sty.cloneNode(true)); }
  for(const m of marks){
    let el=null;
    if(m.sel) el=deepAll(m.sel)[0]||null;
    if(!el && m.text){ const re=new RegExp(m.text,'i'); const all=deepAll(m.within||'a,button,label,h1,h2,h3,th,td,span,div,p,li,input,select').filter(e=>e.offsetParent!==null||getComputedStyle(e).position==='fixed'); let best=null; for(const e of all){ const tx=(e.innerText||e.value||e.placeholder||'').trim(); if(re.test(tx) && tx.length<(m.maxlen||120)){ if(!best||tx.length<best.l){ best={e:e,l:tx.length}; } } } if(best) el=best.e; }
    if(!el){ console.log('MARK-NOT-FOUND '+(m.sel||m.text)); continue; }
    if(m.closest){ const c=el.closest(m.closest); if(c) el=c; }
    if(m.up){ for(let i=0;i<m.up;i++) el=el.parentElement||el; }
    const r=el.getBoundingClientRect(); if(r.width<2||r.height<2){ console.log('MARK-EMPTY '+(m.sel||m.text)); continue; }
    const p=m.pad??5; const box={x:r.left-p,y:r.top-p,w:r.width+2*p,h:r.height+2*p};
    if(!m.nobox){ const b=document.createElement('div'); b.className='__mk __mk-b'; Object.assign(b.style,{left:box.x+'px',top:box.y+'px',width:box.w+'px',height:box.h+'px'}); HOST.appendChild(b); }
    const nb=document.createElement('div'); nb.className='__mk __mk-n'; nb.textContent=m.n;
    const pos=m.pos||'tl'; let nx=box.x-13, ny=box.y-13;
    if(pos==='tr'){nx=box.x+box.w-13;} if(pos==='l'){nx=box.x-34;ny=box.y+box.h/2-13;} if(pos==='r'){nx=box.x+box.w+8;ny=box.y+box.h/2-13;} if(pos==='bl'){ny=box.y+box.h-13;}
    if(pos==='b'){nx=box.x+box.w/2-13;ny=box.y+box.h+6;} if(pos==='t'){nx=box.x+box.w/2-13;ny=box.y-32;}
    Object.assign(nb.style,{left:nx+'px',top:ny+'px'}); HOST.appendChild(nb);
    rects.push({x:Math.min(box.x,nx),y:Math.min(box.y,ny),x2:Math.max(box.x+box.w,nx+26),y2:Math.max(box.y+box.h,ny+26),keep:m.nocrop?0:1});
  }
  const k=rects.filter(r=>r.keep); if(!k.length) return null;
  return {x:Math.min(...k.map(r=>r.x)),y:Math.min(...k.map(r=>r.y)),x2:Math.max(...k.map(r=>r.x2)),y2:Math.max(...k.map(r=>r.y2))};
}
"""

async def run_step(pg, st):
    k = st[0]
    if k == 'click': await pg.click(st[1], timeout=6000)
    elif k == 'clicktext': await pg.get_by_text(st[1], exact=False).first.click(timeout=6000)
    elif k == 'fill': await pg.fill(st[1], st[2])
    elif k == 'select': await pg.select_option(st[1], st[2])
    elif k == 'hover': await pg.hover(st[1])
    elif k == 'js': await pg.evaluate(st[1])
    elif k == 'wait': await pg.wait_for_timeout(st[1])
    elif k == 'goto': await pg.goto(BASE + st[1], wait_until='load')
    elif k == 'scroll': await pg.evaluate("s=>{const e=document.querySelector(s); if(e) e.scrollIntoView({block:'center'})}", st[1])
    elif k == 'key': await pg.keyboard.press(st[1])

async def shoot(ctx, sp):
    pg = await ctx.new_page(); await pg.route('**/*', route)
    logs = []; pg.on('console', lambda m: logs.append(m.text) if 'MARK-' in m.text else None)
    for c in sp.get('pre', []): os.system(c)
    vp = sp.get('vp', (1440, 900)); await pg.set_viewport_size({'width': vp[0], 'height': vp[1]})
    await pg.goto(BASE + sp['url'], wait_until='load'); await pg.wait_for_timeout(sp.get('delay', 1800))
    for st in sp.get('steps', []): await run_step(pg, st)
    await pg.wait_for_timeout(300)
    fr = pg
    r = await pg.evaluate(MARK_JS, sp.get('marks', []))
    clip = None
    if sp.get('full'): clip = {'x': 0, 'y': 0, 'width': vp[0], 'height': vp[1]}
    elif sp.get('clip'): c = sp['clip']; clip = {'x': c[0], 'y': c[1], 'width': c[2], 'height': c[3]}
    elif r:
        pad = sp.get('pad', 28); mw = sp.get('minw', 520); mh = sp.get('minh', 160)
        x = max(0, r['x'] - pad); y = max(0, r['y'] - pad); x2 = min(vp[0], r['x2'] + pad); y2 = min(vp[1], r['y2'] + pad)
        if x2 - x < mw: cx = (x + x2) / 2; x = max(0, cx - mw / 2); x2 = min(vp[0], x + mw); x = max(0, x2 - mw)
        if y2 - y < mh: cy = (y + y2) / 2; y = max(0, cy - mh / 2); y2 = min(vp[1], y + mh); y = max(0, y2 - mh)
        clip = {'x': x, 'y': y, 'width': x2 - x, 'height': y2 - y}
    else: clip = {'x': 0, 'y': 0, 'width': vp[0], 'height': vp[1]}
    os.makedirs(OUT, exist_ok=True); path = OUT + sp['id'] + '.png'
    await pg.screenshot(path=path, clip=clip); await pg.close()
    for c in sp.get('post', []): os.system(c)
    return path, logs

async def main(specs, only):
    async with async_playwright() as p:
        b = await p.chromium.launch()
        for sp in specs:
            if only and sp['id'] not in only: continue
            ctx = await b.new_context(viewport={'width': 1440, 'height': 900}, locale='pt-BR', device_scale_factor=1)
            try:
                path, logs = await shoot(ctx, sp); print('ok', sp['id'], ' '.join(logs))
            except Exception as e: print('FALHOU', sp['id'], str(e).splitlines()[0][:150])
            await ctx.close()
        await b.close()

if __name__ == '__main__':
    spec = importlib.util.spec_from_file_location('specs', sys.argv[1]); m = importlib.util.module_from_spec(spec); spec.loader.exec_module(m)
    asyncio.run(main(m.SPECS, sys.argv[2:]))
