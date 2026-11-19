from PIL import Image, ImageDraw, ImageFont
W=1200
img=Image.new('RGB',(W,W),'#eceae4')
d=ImageDraw.Draw(img)
# card
cx0,cy0,cx1,cy1=110,250,1090,880
d.rounded_rectangle((cx0+12,cy0+16,cx1+12,cy1+16),36,fill='#cfccc3')
d.rounded_rectangle((cx0,cy0,cx1,cy1),36,fill='#4e5743')
# ribbon
d.rectangle((cx0+850,cy0,cx0+910,cy1),fill='#b89b5e')
d.rectangle((cx0,cy0+330,cx1,cy0+390),fill='#b89b5e')
logo=Image.open('/tmp/repo/instagram/assets/logo-weiss.png').convert('RGBA')
lw=500; lh=int(logo.height*lw/logo.width)
if lh>230: lh=230; lw=int(logo.width*lh/logo.height)
logo=logo.resize((lw,lh))
img.paste(logo,(cx0+40+(560-lw)//2,cy0+40+(270-lh)//2),logo)
fb=ImageFont.truetype('/usr/share/fonts/truetype/dejavu/DejaVuSans-Bold.ttf',56)
fr=ImageFont.truetype('/usr/share/fonts/truetype/dejavu/DejaVuSans.ttf',38)
d.text((cx0+60,cy0+430),'GESCHENKGUTSCHEIN',font=fb,fill='white')
d.text((cx0+60,cy0+530),'CHF 50 · 100 · 200',font=fr,fill='#e6dcc3')
ft=ImageFont.truetype('/usr/share/fonts/truetype/dejavu/DejaVuSans.ttf',34)
t='Einlösbar online auf armatum.ch und im Laden in Fulenbach'
w=d.textlength(t,font=ft); d.text(((W-w)/2,960),t,font=ft,fill='#4e5743')
img.save('gutschein.png',optimize=True)
