"""Teste de fumaca do painel (ambiente de teste, NAO roda em cliente).
Abre cada tela (modules/*/*.php) em um navegador headless, registra erros de JavaScript, avisos/erros PHP
e respostas HTTP >= 400, e clica nos botoes que abrem pop-ups conferindo se um pop-up novo ficou visivel.
Uso: painel de teste em http://127.0.0.1:18802/ e  python3 scripts/smoke-painel.py
Saida completa em /tmp/shot/smoke.json. Validado: detecta o defeito antigo do pop-up Novo Contato."""
import asyncio, re, json, glob, os, sys
from playwright.async_api import async_playwright
ROOT='/home/claude/ipbx-issabel6.0/src/front_api/pabx_panel/modules'
BASE='http://127.0.0.1:18802/index.php'
pairs=[(os.path.basename(os.path.dirname(f)),os.path.basename(f)[:-4]) for f in sorted(glob.glob(ROOT+'/*/*.php'))]
SKIP=re.compile(r'delet|exclu|remov|apag|reinici|restart|logout|sair|wipe|reset|clear|limpar|disconnect|desconect|backup|restore|update|atualiz|rollback|block|bloque|ligar|call|originate|enviar|send|dispar|test',re.I)
async def main():
    rep={'pages':[], 'clicks':[]}
    async with async_playwright() as p:
        b=await p.chromium.launch()
        for mod,act in pairs:
            ctx=await b.new_context(viewport={'width':1500,'height':900}); pg=await ctx.new_page()
            errs=[]; bad=[]
            pg.on('pageerror',lambda e:errs.append(str(e)[:160]))
            pg.on('console',lambda m: errs.append('console:'+m.text[:160]) if m.type=='error' and 'Failed to load resource' not in m.text else None)
            pg.on('response',lambda r: bad.append(f"{r.status} {r.url.split('127.0.0.1:18802')[-1][:90]}") if r.status>=400 and r.url.startswith('http://127.0.0.1') and not r.url.endswith(('.png','.jpg','.ico','.svg','.woff2')) else None)
            pg.on('dialog',lambda d:asyncio.ensure_future(d.dismiss()))
            await pg.route('**/*', lambda r: r.continue_() if r.request.url.startswith('http://127.0.0.1') else (r.fulfill(path='/tmp/shot/chart.umd.js',content_type='application/javascript') if 'chart' in r.request.url.lower() and r.request.url.endswith('.js') else r.abort()))
            await pg.add_init_script("document.addEventListener('DOMContentLoaded',()=>{const s=document.createElement('style');s.textContent='.hidden{display:none!important}';document.head.appendChild(s)})")
            url=f'{BASE}?module={mod}&action={act}'
            try: await pg.goto(url,wait_until='domcontentloaded',timeout=20000); await pg.wait_for_timeout(1800)
            except Exception as e: errs.append('goto:'+str(e)[:100])
            body=await pg.evaluate("document.body?document.body.innerText:''")
            flags=[k for k in ['Fatal error','Parse error','Warning:','Notice:','Uncaught','Não foi possível carregar esta tela'] if k in body]
            errs=[e for e in errs if 'tailwind' not in e]
            rep['pages'].append({'page':f'{mod}/{act}','errors':errs[:5],'http':bad[:4],'flags':flags,'len':len(body)})
            # cliques que abrem pop-up
            els=await pg.evaluate("""()=>[...document.querySelectorAll('[onclick]')].map((e,i)=>({i,o:e.getAttribute('onclick'),vis:!!(e.offsetWidth||e.offsetHeight)})).filter(x=>x.vis&&/(openModal|Modal\\()/.test(x.o))""")
            seen=set(); n=0
            for x in els:
                if x['o'] in seen or SKIP.search(x['o']) or n>=20: continue
                seen.add(x['o']); n+=1
                before=len(errs)
                VIS="()=>[...document.querySelectorAll('[id^=modal],dialog[open],[role=dialog]')].filter(e=>e.offsetWidth&&e.offsetHeight&&getComputedStyle(e).visibility!=='hidden').map(e=>e.id||e.tagName)"
                pre=set(await pg.evaluate(VIS))
                try:
                    await pg.evaluate("(i)=>document.querySelectorAll('[onclick]')[i].click()",x['i']); await pg.wait_for_timeout(350)
                except Exception as e: errs.append('click:'+str(e)[:80])
                shown=[m for m in await pg.evaluate(VIS) if m not in pre]
                new=errs[before:]
                rep['clicks'].append({'page':f'{mod}/{act}','onclick':x['o'][:90],'modal_aberto':shown[:2],'erros':new[:2]})
                await pg.evaluate("document.querySelectorAll('[id^=modal]').forEach(m=>m.classList.add('hidden'));document.querySelectorAll('dialog[open]').forEach(d=>d.close&&d.close())")
            await ctx.close()
        await b.close()
    json.dump(rep,open('/tmp/shot/smoke.json','w'),ensure_ascii=False,indent=1)
    print('PAGINAS',len(rep['pages']),'CLIQUES',len(rep['clicks']))
    for r in rep['pages']:
        if r['errors'] or r['http'] or r['flags']: print('PAG',r)
    for c in rep['clicks']:
        if not c['modal_aberto'] or c['erros']: print('CLK',c)
asyncio.run(main())
