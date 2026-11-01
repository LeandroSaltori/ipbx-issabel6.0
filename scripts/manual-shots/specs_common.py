def m(n, t=None, sel=None, **k):
    d = {'n': n}
    if sel: d['sel'] = sel
    if t: d['text'] = t
    d.update(k); return d
D30 = [('clicktext', '30 dias'), ('wait', 1800)]
def fig(id, url, c, legend=None, marks=None, steps=None, key=None, **kw):
    d = {'id': id, 'url': url, 'c': c, 'n': legend or [], 'marks': marks or [], 'steps': steps or []}
    d.update(kw); return d
