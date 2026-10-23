#!/usr/bin/env python3
# Gera docs/mapa-do-ipbx.html (cidade, conexões e histórico) a partir do código e do git. Roda sozinho a cada commit (.githooks/pre-commit).
import subprocess,json,os,re,collections
R=os.path.abspath(os.path.join(os.path.dirname(__file__),'..'))
def sh(c): return subprocess.run(c,shell=True,cwd=R,capture_output=True,text=True).stdout
base='src/front_api'
files=[f for f in sh(f"git ls-files {base}").split('\n') if f.endswith(('.php','.sh','.js','.py')) ]
def area(f):
    p=f[len(base)+1:]
    if p.startswith('pabx_panel/modules/'): return p.split('/')[2]
    if p.startswith('pabx_panel/includes/'): return 'includes'
    if p.startswith('pabx_panel/cron/'): return 'cron'
    if p.startswith('pabx_panel/'): return 'painel'
    return 'instalador'
nodes=[];idx={}
for f in files:
    try: t=open(f'{R}/{f}',errors='ignore').read()
    except: continue
    n=t.count('\n')+1
    cm=int(sh(f"git log --oneline -- '{f}' | wc -l").strip() or 0)
    nodes.append({'id':len(nodes),'f':f[len(base)+1:],'a':area(f),'l':n,'c':cm,'t':t});idx[f[len(base)+1:]]=len(nodes)-1
# edges: includes
edges=[]
for nd in nodes:
    for m in re.finditer(r"(?:require_once|require|include_once|include)\s*\(?\s*(?:__DIR__\s*\.\s*)?['\"]([^'\"]+\.php)['\"]",nd['t']):
        b=os.path.basename(m.group(1))
        for o in nodes:
            if o['id']!=nd['id'] and os.path.basename(o['f'])==b: edges.append([nd['id'],o['id'],'inclui'])
# function usage -> includes files
defs={}
for nd in nodes:
    if nd['a']=='includes':
        for m in re.finditer(r'function\s+([A-Za-z_]\w+)\s*\(',nd['t']): defs.setdefault(m.group(1),nd['id'])
big=[k for k,v in defs.items() if len(k)>6]
for nd in nodes:
    if nd['a']=='includes': continue
    used=collections.Counter()
    for k in big:
        if k in nd['t'] and re.search(r'\b'+k+r'\s*\(',nd['t']): used[defs[k]]+=1
    for tid,c in used.items():
        if tid!=nd['id']: edges.append([nd['id'],tid,'usa',c])
for nd in nodes: del nd['t']
# git history
log=sh("git log --reverse --date=short --pretty=format:'%h|%ad|%s' --shortstat -- src/front_api")
commits=[];cur=None
for ln in log.split('\n'):
    if '|' in ln and re.match(r'^[0-9a-f]{7}\|',ln):
        h,d,s=ln.split('|',2);cur={'h':h,'d':d,'s':s[:90],'fc':0,'ins':0,'del':0};commits.append(cur)
    elif cur and 'changed' in ln:
        m=re.search(r'(\d+) file',ln);i=re.search(r'(\d+) insert',ln);dl=re.search(r'(\d+) delet',ln)
        cur['fc']=int(m.group(1)) if m else 0;cur['ins']=int(i.group(1)) if i else 0;cur['del']=int(dl.group(1)) if dl else 0
out={'nodes':nodes,'edges':edges,'commits':commits}
data=json.dumps(out,ensure_ascii=False,separators=(',',':')).replace('</','<\\/')
tpl=open(R+'/docs/mapa/template.html',encoding='utf-8').read()
open(R+'/docs/mapa-do-ipbx.html','w',encoding='utf-8').write(tpl.replace('__DATA__',data))
print('mapa gerado:',len(nodes),'arquivos,',len(edges),'ligações,',len(commits),'entregas')
