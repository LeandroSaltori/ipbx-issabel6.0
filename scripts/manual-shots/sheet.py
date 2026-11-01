import sys
from PIL import Image
ids=sys.argv[2:]; out=sys.argv[1]; W=720
ims=[Image.open('/tmp/shot/out/%s.png'%i).convert('RGB') for i in ids]
ims=[im.resize((W,int(im.height*W/im.width))) if im.width>W else im for im in ims]
cols=2; rows=(len(ims)+1)//2; rh=[max(ims[r*2+c].height for c in range(2) if r*2+c<len(ims)) for r in range(rows)]
S=Image.new('RGB',(cols*W+10,sum(rh)+10*rows),(60,60,60)); y=0
for r in range(rows):
    for c in range(2):
        k=r*2+c
        if k<len(ims): S.paste(ims[k],(c*(W+10),y))
    y+=rh[r]+10
S.save(out)
