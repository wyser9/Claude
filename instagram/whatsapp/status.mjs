// Rendert WhatsApp-Status-Bilder (1080×1920) aus status.json.
// Aufruf: node status.mjs <status.json> <ausgabe-ordner>
import { chromium } from 'playwright-core';
import { readFileSync, mkdirSync } from 'node:fs';
import { resolve, dirname } from 'node:path';

const [specPath, outDir] = process.argv.slice(2);
const base = dirname(resolve(specPath));
const spec = JSON.parse(readFileSync(specPath, 'utf8'));
mkdirSync(outDir, { recursive: true });
const img = (f) => (f.endsWith('.jpg') ? 'data:image/jpeg;base64,' : 'data:image/png;base64,') + readFileSync(resolve(base, f)).toString('base64');
const esc = (s) => String(s).replace(/&/g, '&amp;').replace(/</g, '&lt;');
const md = (s) => esc(s).replace(/\*\*(.+?)\*\*/g, '<strong>$1</strong>').replace(/\n/g, '<br>');

const css = `
@import url('https://fonts.googleapis.com/css2?family=Cinzel:wght@500;700&family=Inter:wght@400;600&display=block');
*{box-sizing:border-box;margin:0;padding:0}
body{width:1080px;height:1920px;background:#313131;color:#fff;font-family:Inter,sans-serif;overflow:hidden}
.s{width:1080px;height:1920px;padding:150px 80px 170px;display:flex;flex-direction:column;align-items:center;text-align:center}
.logo{width:420px}
.kicker{color:#a7a7a7;font-size:34px;letter-spacing:.18em;text-transform:uppercase;margin-top:70px}
h1{font-family:Cinzel,serif;font-weight:700;font-size:92px;line-height:1.1;margin-top:20px}
.band{height:10px;width:180px;background:#4e5743;margin:44px auto}
.card{background:#fff;border-radius:26px;padding:24px 30px;width:100%}
.card img{width:100%;display:block}
.cards{display:flex;flex-direction:column;gap:30px;width:100%;margin-top:20px}
.meta{font-size:38px;line-height:1.45;color:#ececec;margin-top:16px}
.body{font-size:46px;line-height:1.45;color:#ececec}
.pill{margin-top:auto;background:#4e5743;border-radius:60px;padding:30px 56px;font-size:44px;font-weight:600}
.phone{border:16px solid #1f1f1f;border-radius:56px;overflow:hidden;box-shadow:0 30px 80px rgba(0,0,0,.55);height:1000px}
.phone img{height:100%;display:block}
.phone.post{height:900px;border-radius:28px}
`;
const logo = img(spec.logo || '../../assets/logo-weiss.png');

function render(s) {
  const cards = (s.images || []).map((x) => `<div><div class="card"><img src="${img(x.src)}"></div>${x.caption ? `<div class="meta">${md(x.caption)}</div>` : ''}</div>`).join('');
  return `<div class="s">
    <img class="logo" src="${logo}">
    ${s.kicker ? `<div class="kicker">${esc(s.kicker)}</div>` : ''}
    ${s.title ? `<h1>${md(s.title)}</h1>` : ''}
    <div class="band"></div>
    ${cards ? `<div class="cards">${cards}</div>` : ''}
    ${s.phone ? `<div class="phone"><img src="${img(s.phone)}"></div>` : ''}
    ${s.body ? `<div class="body" style="margin-top:50px">${md(s.body)}</div>` : ''}
    ${s.pill ? `<div class="pill">${md(s.pill)}</div>` : ''}
  </div>`;
}

const browser = await chromium.launch({ executablePath: process.env.CHROMIUM_PATH || '/opt/pw-browsers/chromium-1194/chrome-linux/chrome' });
const page = await browser.newPage({ viewport: { width: 1080, height: 1920 } });
for (const s of spec.status) {
  await page.setContent(`<!doctype html><html><head><meta charset="utf-8"><style>${css}</style></head><body>${render(s)}</body></html>`, { waitUntil: 'networkidle' });
  await page.evaluate(() => document.fonts.ready);
  const file = resolve(outDir, `${s.name}.png`);
  await page.screenshot({ path: file });
  console.log(file);
}
await browser.close();
