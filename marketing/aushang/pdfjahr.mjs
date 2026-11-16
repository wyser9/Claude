import { chromium } from 'playwright-core';
import { readFileSync } from 'node:fs';
const [logo,out]=process.argv.slice(2);
const L='data:image/png;base64,'+readFileSync(logo).toString('base64');
const html=`<!doctype html><html><head><meta charset="utf-8"><title>Jahresend-Angebote</title><style>
@import url('https://fonts.googleapis.com/css2?family=Cinzel:wght@700&family=Inter:wght@400;600;700&display=block');
@page{size:A4;margin:0}*{margin:0;padding:0;box-sizing:border-box}
body{width:210mm;height:296mm;overflow:hidden;font-family:Inter,sans-serif;color:#1f1f1f;display:flex;flex-direction:column;align-items:center;text-align:center;padding:20mm 18mm}
img{width:85mm}.band{height:2.5mm;min-height:2.5mm;width:40mm;background:#4e5743;-webkit-print-color-adjust:exact;print-color-adjust:exact;margin:8mm auto}
.k{font-size:6mm;letter-spacing:.2em;text-transform:uppercase;color:#666}
h1{font-family:Cinzel,serif;font-size:15mm;line-height:1.1;margin-top:3mm}
.sub{font-size:6.5mm;font-weight:600;margin-top:3mm}
table{width:100%;border-collapse:collapse;margin-top:4mm;font-size:6mm}
th{font-size:4.8mm;text-transform:uppercase;letter-spacing:.08em;color:#666;font-weight:600;padding:2mm 0;border-bottom:.6mm solid #1f1f1f}
td{padding:4.2mm 0;border-bottom:.3mm solid #ccc}
td.p{font-weight:700}td.r{color:#4e5743;font-weight:600}
tr.top td{background:#4e5743;color:#fff;-webkit-print-color-adjust:exact;print-color-adjust:exact}
.note{font-size:4.6mm;line-height:1.55;margin-top:7mm;color:#444}
.box{margin-top:auto;border:1mm solid #4e5743;border-radius:5mm;padding:6mm 10mm;font-size:4.8mm;line-height:1.6;width:100%}
</style></head><body>
<img src="${L}"><div class="band"></div>
<div class="k">Bis 31. Dezember 2026</div>
<h1>Jahresend-<br>Angebote</h1>
<div class="sub">10 % auf ausgewählte Artikel · solange Vorrat</div>
<div class="band"></div>
<table>
<tr><th>Artikel</th><th>statt</th><th>jetzt</th></tr>
<tr><td style="text-align:left">CZ TS 2 Orange 9mm<br><span style="font-size:4.4mm;color:#666">Meistermodell IPSC Standard</span></td><td>3079.00</td><td class="p">CHF 2771.10</td></tr>
<tr class="top"><td style="text-align:left">Schmeisser AR15 M4A2 14.5"<br><span style="font-size:4.4mm">Classic Limited Edition – nur 1 Stück</span></td><td>1589.00</td><td class="p">CHF 1430.10</td></tr>
<tr><td style="text-align:left">Fiocchi Slug Cervo 12/67.5<br><span style="font-size:4.4mm;color:#666">28 g, Packung</span></td><td>24.50</td><td class="p">CHF 22.05</td></tr>
</table>
<div class="note">Weitere Angebote im Laden und auf armatum.ch/jahresend-angebote<br>Erwerb gemäss Waffengesetz – wir beraten dich gerne.</div>
<div class="box"><b>Auch im Onlineshop: armatum.ch</b><br>+41 79 712 66 35 · info@armatum.ch</div>
</body></html>`;
const b=await chromium.launch({executablePath:process.env.CHROMIUM_PATH});
const p=await b.newPage();await p.setContent(html,{waitUntil:'networkidle'});await p.evaluate(()=>document.fonts.ready);
await p.pdf({path:out,format:"A4",printBackground:true,pageRanges:"1"});await b.close();
