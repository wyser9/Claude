// Rendert Karussell-Slides (1080×1350) aus einer JSON-Beschreibung.
// Aufruf: node render.mjs <slides.json> <ausgabe-ordner>
// Benötigt playwright-core und Chromium (CHROMIUM_PATH oder /opt/pw-browsers).
import { chromium } from 'playwright-core';
import { readFileSync, mkdirSync } from 'node:fs';
import { resolve, dirname } from 'node:path';
import { fileURLToPath } from 'node:url';

const here = dirname(fileURLToPath(import.meta.url));
const assets = resolve(here, '../assets');
const logo = (name) => 'data:image/png;base64,' + readFileSync(resolve(assets, name)).toString('base64');

const [specPath, outDir] = process.argv.slice(2);
const spec = JSON.parse(readFileSync(specPath, 'utf8'));
mkdirSync(outDir, { recursive: true });

const C = {
  bg: '#313131', olive: '#4e5743', text: '#ffffff', muted: '#a7a7a7',
  line: '#415161', warn: '#E07A1F',
};

const esc = (s) => String(s).replace(/&/g, '&amp;').replace(/</g, '&lt;');
const md = (s) => esc(s).replace(/\*\*(.+?)\*\*/g, '<strong>$1</strong>').replace(/\n/g, '<br>');

const css = `
@import url('https://fonts.googleapis.com/css2?family=Cinzel:wght@500;700&family=Inter:wght@400;600&display=block');
*{box-sizing:border-box;margin:0;padding:0}
body{width:1080px;height:1350px;background:${C.bg};color:${C.text};font-family:Inter,sans-serif;overflow:hidden}
.slide{position:relative;width:1080px;height:1350px;padding:120px 88px}
.cinzel{font-family:Cinzel,serif;letter-spacing:.02em}
.foot{position:absolute;left:88px;right:88px;bottom:64px;display:flex;align-items:center;justify-content:center}
.foot img{width:270px;opacity:.9}
.count{position:absolute;right:0;color:${C.muted};font-size:28px}
.band{height:10px;background:${C.olive};width:180px;margin:0 auto}
.badge{display:inline-flex;align-items:center;justify-content:center;min-width:120px;height:120px;padding:0 24px;border-radius:24px;background:${C.olive};font-family:Cinzel,serif;font-weight:700;font-size:64px}
.kicker{color:${C.muted};font-size:32px;text-transform:uppercase;letter-spacing:.18em;margin-top:72px}
h2{font-family:Cinzel,serif;font-weight:700;font-size:80px;line-height:1.15;margin-top:20px}
.body{font-size:48px;line-height:1.5;margin-top:56px;color:#ececec}
.body strong{color:#fff}
.hr{height:2px;background:${C.line};margin-top:48px}
.warn{margin-top:48px;border:3px solid ${C.warn};border-radius:20px;padding:36px 40px;font-size:34px;line-height:1.5}
.warn .t{color:${C.warn};font-weight:600;font-size:30px;letter-spacing:.12em;text-transform:uppercase;margin-bottom:12px}
.specs{width:100%;border-collapse:collapse;margin-top:28px}.specs td{padding:20px 0;border-bottom:2px solid ${C.line};vertical-align:top;font-size:36px;line-height:1.35}.specs .k{color:${C.muted};width:34%;padding-right:24px;font-size:30px;text-transform:uppercase;letter-spacing:.08em;padding-top:24px}.specs .v{color:#fff}
.card{background:#fff;border-radius:22px;padding:22px 28px}.card img{width:100%;display:block}
.cta{background:${C.olive};border-radius:24px;padding:56px 56px;font-size:46px;line-height:1.4;text-align:center;font-weight:600}
`;

function cover(s) {
  return `<div class="slide" style="display:flex;flex-direction:column;align-items:center;text-align:center">
    <img src="${logo('logo-weiss.png')}" style="width:440px;margin-top:40px">
    <div style="flex:1;display:flex;flex-direction:column;justify-content:center;gap:56px">
      <div class="band"></div>
      <div class="cinzel" style="font-weight:700;font-size:92px;line-height:1.12">${md(s.title)}</div>
      ${s.subtitle ? `<div style="font-size:44px;color:${C.muted};line-height:1.4">${md(s.subtitle)}</div>` : ''}
      <div class="band"></div>
    </div>
    <div style="font-size:32px;color:${C.muted};letter-spacing:.14em;text-transform:uppercase;margin-bottom:24px">Swipe →</div>
  </div>`;
}

function content(s, i, n) {
  return `<div class="slide">
    <div class="badge">${esc(s.badge)}</div>
    ${s.kicker ? `<div class="kicker">${esc(s.kicker)}</div>` : ''}
    <h2>${md(s.title)}</h2>
    <div class="hr"></div>
    <div class="body">${md(s.body)}</div>
    ${s.warn ? `<div class="warn"><div class="t">⚠ Sicherheit</div>${md(s.warn)}</div>` : ''}
    <div class="foot"><img src="${logo('schriftzug-weiss.png')}"><span class="count">${i + 1}/${n}</span></div>
  </div>`;
}

function shot(s, i, n) {
  const src = 'data:image/png;base64,' + readFileSync(resolve(dirname(specPath), s.image)).toString('base64');
  return `<div class="slide" style="display:flex;flex-direction:column">
    ${s.kicker ? `<div class="kicker" style="margin-top:0">${esc(s.kicker)}</div>` : ''}
    <h2 style="font-size:68px">${md(s.title)}</h2>
    <div style="flex:1;display:flex;align-items:center;justify-content:center;margin:40px 0 90px">
      <img src="${src}" style="max-width:${s.maxWidth || 904}px;max-height:${s.maxHeight || 820}px;border-radius:${s.radius || 18}px;border:${s.border || 10}px solid #1f1f1f;box-shadow:0 20px 60px rgba(0,0,0,.5)">
    </div>
    <div class="foot"><img src="${logo('schriftzug-weiss.png')}"><span class="count">${i + 1}/${n}</span></div>
  </div>`;
}

function specs(s, i, n) {
  const rows = s.rows.map(([k, v]) => `<tr><td class="k">${esc(k)}</td><td class="v">${md(v)}</td></tr>`).join('');
  return `<div class="slide">
    ${s.kicker ? `<div class="kicker" style="margin-top:0">${esc(s.kicker)}</div>` : ''}
    <h2 style="font-size:72px">${md(s.title)}</h2>
    <div class="hr"></div>
    <table class="specs">${rows}</table>
    <div class="foot"><img src="${logo('schriftzug-weiss.png')}"><span class="count">${esc(s.counter || `${i + 1}/${n}`)}</span></div>
  </div>`;
}

function product(s, i, n) {
  const src = (f) => 'data:image/png;base64,' + readFileSync(resolve(dirname(specPath), f)).toString('base64');
  const imgs = (s.images || [s.image]).map((f) => `<div class="card"><img src="${src(f)}"${s.imgHeight ? ` style="height:${s.imgHeight}px;object-fit:contain"` : ''}></div>`).join('');
  const rows = (s.rows || []).map(([k, v]) => `<tr><td class="k">${esc(k)}</td><td class="v">${md(v)}</td></tr>`).join('');
  return `<div class="slide">
    ${s.kicker ? `<div class="kicker" style="margin-top:0">${esc(s.kicker)}</div>` : ''}
    <h2 style="font-size:${s.titleSize || 64}px">${md(s.title)}</h2>
    <div style="display:flex;flex-direction:column;gap:28px;margin-top:40px">${imgs}</div>
    ${s.body ? `<div class="body" style="margin-top:40px">${md(s.body)}</div>` : ''}
    ${rows ? `<table class="specs">${rows}</table>` : ''}
    <div class="foot"><img src="${logo('schriftzug-weiss.png')}"><span class="count">${i + 1}/${n}</span></div>
  </div>`;
}

function cta(s, i, n) {
  return `<div class="slide" style="display:flex;flex-direction:column;align-items:center;text-align:center">
    ${s.warn ? `<div class="warn" style="margin-top:0;text-align:left;width:100%"><div class="t">⚠ Sicherheit</div>${md(s.warn)}</div>` : '<div style="height:40px"></div>'}
    <div style="flex:1;display:flex;flex-direction:column;justify-content:center;gap:64px;width:100%">
      <div class="cta">${md(s.title)}</div>
      ${s.body ? `<div style="font-size:40px;line-height:1.45">${md(s.body)}</div>` : ''}
    </div>
    <img src="${logo('logo-weiss.png')}" style="width:420px">
    <div style="font-size:28px;color:${C.muted};margin-top:36px;margin-bottom:8px">armatum.ch · Laden · Werkstatt · Onlineshop · Fulenbach SO</div>
  </div>`;
}

const browser = await chromium.launch({
  executablePath: process.env.CHROMIUM_PATH || '/opt/pw-browsers/chromium-1194/chrome-linux/chrome',
});
const page = await browser.newPage({ viewport: { width: 1080, height: 1350 } });
const n = spec.slides.length;
for (const [i, s] of spec.slides.entries()) {
  const inner = s.type === 'cover' ? cover(s) : s.type === 'cta' ? cta(s, i, n) : s.type === 'shot' ? shot(s, i, n) : s.type === 'specs' ? specs(s, i, n) : s.type === 'product' ? product(s, i, n) : content(s, i, n);
  await page.setContent(`<!doctype html><html><head><meta charset="utf-8"><style>${css}</style></head><body>${inner}</body></html>`, { waitUntil: 'networkidle' });
  await page.evaluate(() => document.fonts.ready);
  const file = resolve(outDir, `${String(i + 1).padStart(2, '0')}.png`);
  await page.screenshot({ path: file });
  console.log(file);
}
await browser.close();
