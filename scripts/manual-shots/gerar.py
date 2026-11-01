"""Gera as figuras e includes/manual_figuras.php.  Uso: python3 gerar.py specs_p1 [specs_p2 ...]  (ids opcionais: --only a,b)"""
import sys, os, asyncio, json, importlib
sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))
import shotlib
from PIL import Image
REPO = os.path.abspath(os.path.join(os.path.dirname(os.path.abspath(__file__)), '..', '..'))
IMG = REPO + '/src/front_api/img/manual/fig/'
PHP = REPO + '/src/front_api/pabx_panel/includes/manual_figuras.php'
mods = [a for a in sys.argv[1:] if not a.startswith('--')]
only = []
for a in sys.argv[1:]:
    if a.startswith('--only='): only = a[7:].split(',')
F = {}
for mname in mods:
    F.update(importlib.import_module(mname).F)
specs = [f for v in F.values() for f in v]
if '--nocapture' not in sys.argv: asyncio.run(shotlib.main(specs, only))
os.makedirs(IMG, exist_ok=True)
for sp in specs:
    src = shotlib.OUT + sp['id'] + '.png'
    if os.path.isfile(src):
        im = Image.open(src).convert('RGB')
        im.quantize(colors=160, method=Image.Quantize.MEDIANCUT).save(IMG + sp['id'] + '.png', optimize=True)
def q(s): return "'" + s.replace('\\', '\\\\').replace("'", "\\'") + "'"
out = ["<?php\n/** Figuras do manual (gerado por scripts/manual-shots/gerar.py). Chave 'secao|titulo' => figuras com legenda numerada. */\nreturn [\n"]
for (sec, t), figs in F.items():
    items = []
    for f in figs:
        if not os.path.isfile(IMG + f['id'] + '.png'): continue
        items.append("['f' => %s, 'c' => %s, 'n' => [%s]]" % (q('manual/fig/' + f['id'] + '.png'), q(f['c']), ', '.join(q(x) for x in f['n'])))
    if items: out.append("    %s => [%s],\n" % (q(sec + '|' + t), ",\n        ".join(items)))
out.append("];\n")
open(PHP, 'w').write(''.join(out)); print('figuras:', sum(len(v) for v in F.values()))
