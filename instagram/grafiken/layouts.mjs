// Neue Layout-Bausteine für mehr Abwechslung (Irene, 12.11.2026).
// Aufruf: node layouts.mjs <slides.json> <ausgabe-ordner>
// Jede Slide hat "type" (fototitel | split | zahl | grid | liste | zitat | vergleich)
// und optional "theme": "dunkel" (Standard) | "hell" | "oliv".
import { chromium } from 'playwright-core';
import { readFileSync, mkdirSync } from 'node:fs';
import { resolve, dirname } from 'node:path';

const assets = '/home/user/Claude/instagram/assets';
const b64 = (p) => 'data:image/' + (p.endsWith('.png') ? 'png' : 'jpeg') + ';base64,' + readFileSync(p).toString('base64');
const logo = (n) => b64(resolve(assets, n));
const [specPath, outDir] = process.argv.slice(2);
const spec = JSON.parse(readFileSync(specPath, 'utf8'));
const img = (f) => b64(resolve(dirname(specPath), f));
mkdirSync(outDir, { recursive: true });

const T = {
  dunkel: { bg: '#313131', fg: '#ffffff', muted: '#a7a7a7', acc: '#4e5743', line: '#4a4a4a', logo: 'weiss' },
  hell: { bg: '#efe9df', fg: '#1f1f1f', muted: '#6b665d', acc: '#4e5743', line: '#d3cbbd', logo: 'schwarz' },
  oliv: { bg: '#4e5743', fg: '#ffffff', muted: '#d7dccf', acc: '#e9e4d8', line: '#66705a', logo: 'weiss' },
};
const esc = (s) => String(s ?? '').replace(/&/g, '&amp;').replace(/</g, '&lt;');
const md = (s) => esc(s).replace(/\*\*(.+?)\*\*/g, '<strong>$1</strong>').replace(/\n/g, '<br>');

const css = (t) => `
@import url('https://fonts.googleapis.com/css2?family=Cinzel:wght@500;700&family=Inter:wght@400;600;800&display=block');
*{box-sizing:border-box;margin:0;padding:0}
body{width:1080px;height:1350px;background:${t.bg};color:${t.fg};font-family:Inter,sans-serif;overflow:hidden}
.s{position:relative;width:1080px;height:1350px}
.cz{font-family:Cinzel,serif;font-weight:700;letter-spacing:.01em}
.k{font-size:30px;letter-spacing:.2em;text-transform:uppercase;color:${t.muted}}
.foot{position:absolute;left:80px;right:80px;bottom:60px;display:flex;justify-content:space-between;align-items:center;font-size:26px;color:${t.muted}}
.foot img{height:34px}
strong{font-weight:800}
`;
const foot = (t, i, n) => `<div class="foot"><img src="${logo(`schriftzug-${t.logo}.png`)}"><span>${i + 1}/${n}</span></div>`;

const L = {
  // Vollflächiges Foto, Verlauf unten, grosser Titel darüber
  fototitel: (s, t) => `<div class="s">
    <img src="${img(s.image)}" style="position:absolute;inset:0;width:100%;height:100%;object-fit:cover">
    <div style="position:absolute;inset:0;background:linear-gradient(180deg,rgba(0,0,0,.35) 0%,rgba(0,0,0,0) 22%,rgba(0,0,0,0) 48%,rgba(0,0,0,.82) 100%)"></div>
    <img src="${logo('logo-weiss.png')}" style="position:absolute;top:56px;left:64px;width:300px">
    <div style="position:absolute;left:72px;right:72px;bottom:96px;color:#fff">
      ${s.kicker ? `<div class="k" style="color:#e9e4d8">${esc(s.kicker)}</div>` : ''}
      <div class="cz" style="font-size:${s.size || 104}px;line-height:1.02;margin-top:18px">${md(s.title)}</div>
      ${s.sub ? `<div style="font-size:40px;margin-top:22px;opacity:.9">${md(s.sub)}</div>` : ''}
      <div style="margin-top:34px;display:inline-block;background:#4e5743;padding:14px 28px;border-radius:40px;font-size:30px;font-weight:600">Swipe →</div>
    </div></div>`,
  // Foto oben, Textblock unten (farbig)
  split: (s, t, i, n) => `<div class="s">
    <img src="${img(s.image)}" style="position:absolute;top:0;left:0;width:1080px;height:${s.photoH || 760}px;object-fit:cover">
    <div style="position:absolute;top:${(s.photoH || 760) - 40}px;left:64px;background:${t.acc};color:${t.bg === '#4e5743' ? '#1f1f1f' : '#fff'};font-size:30px;font-weight:600;padding:14px 26px;border-radius:12px">${esc(s.tag || '')}</div>
    <div style="position:absolute;top:${(s.photoH || 760) + 50}px;left:72px;right:72px">
      <div class="cz" style="font-size:64px;line-height:1.1">${md(s.title)}</div>
      <div style="font-size:38px;line-height:1.5;margin-top:24px;color:${t.fg};opacity:.92">${md(s.body)}</div>
    </div>${foot(t, i, n)}</div>`,
  // Eine grosse Zahl / ein Fakt
  zahl: (s, t, i, n) => `<div class="s" style="display:flex;flex-direction:column;justify-content:center;padding:0 90px">
    <div class="k">${esc(s.kicker)}</div>
    <div style="font-size:${s.size || 300}px;font-weight:800;line-height:.95;letter-spacing:-.03em;margin-top:30px;color:${t.fg}">${md(s.zahl)}</div>
    <div style="height:12px;width:200px;background:${t.acc};margin:50px 0 40px"></div>
    <div style="font-size:46px;line-height:1.4;max-width:860px">${md(s.text)}</div>
    ${foot(t, i, n)}</div>`,
  // 2x2 Fotoraster mit kurzen Labels
  grid: (s, t, i, n) => `<div class="s" style="padding:80px 64px">
    <div class="k">${esc(s.kicker || '')}</div>
    <div class="cz" style="font-size:66px;margin:14px 0 36px">${md(s.title)}</div>
    <div style="display:grid;grid-template-columns:1fr 1fr;gap:18px">
      ${s.items.map((x) => `<div style="position:relative;height:440px;border-radius:20px;overflow:hidden">
        <img src="${img(x.image)}" style="width:100%;height:100%;object-fit:cover;object-position:${x.pos || 'center'}">
        <div style="position:absolute;left:14px;bottom:14px;background:rgba(0,0,0,.66);color:#fff;font-size:28px;font-weight:600;padding:10px 18px;border-radius:10px">${esc(x.label)}</div></div>`).join('')}
    </div>${foot(t, i, n)}</div>`,
  // Nummerierte Liste
  liste: (s, t, i, n) => `<div class="s" style="padding:110px 88px">
    <div class="k">${esc(s.kicker || '')}</div>
    <div class="cz" style="font-size:76px;line-height:1.1;margin-top:16px">${md(s.title)}</div>
    <div style="margin-top:56px;display:flex;flex-direction:column;gap:34px">
      ${s.items.map((x, j) => `<div style="display:flex;gap:30px;align-items:flex-start">
        <div style="flex:none;width:84px;height:84px;border-radius:50%;background:${t.acc};color:${t.bg};display:flex;align-items:center;justify-content:center;font-size:40px;font-weight:800">${j + 1}</div>
        <div style="font-size:40px;line-height:1.38;padding-top:12px">${md(x)}</div></div>`).join('')}
    </div>${foot(t, i, n)}</div>`,
  // Zitat / Kundenstimme / Werkstatt-Satz
  zitat: (s, t, i, n) => `<div class="s" style="display:flex;flex-direction:column;justify-content:center;padding:0 100px">
    <div class="cz" style="font-size:280px;line-height:.6;color:${t.acc};height:150px">«</div>
    <div style="font-size:62px;line-height:1.3;font-weight:600">${md(s.text)}</div>
    <div style="font-size:34px;margin-top:50px;color:${t.muted}">${md(s.von || '')}</div>
    ${foot(t, i, n)}</div>`,
  // Zwei Spalten gegenüberstellen
  vergleich: (s, t, i, n) => `<div class="s" style="padding:100px 64px">
    <div class="k" style="text-align:center">${esc(s.kicker || '')}</div>
    <div class="cz" style="font-size:70px;text-align:center;margin:14px 0 50px">${md(s.title)}</div>
    <div style="display:grid;grid-template-columns:1fr 1fr;gap:24px">
      ${[s.links, s.rechts].map((c, j) => `<div style="background:${j ? t.acc : 'transparent'};border:3px solid ${t.acc};border-radius:26px;padding:40px 34px;color:${j ? (t.bg === '#4e5743' ? '#1f1f1f' : '#fff') : t.fg}">
        <div style="font-size:44px;font-weight:800;margin-bottom:26px">${md(c.kopf)}</div>
        ${c.punkte.map((p) => `<div style="font-size:33px;line-height:1.4;margin-top:16px">▪ ${md(p)}</div>`).join('')}</div>`).join('')}
    </div>${foot(t, i, n)}</div>`,
};

const browser = await chromium.launch({ executablePath: process.env.CHROMIUM_PATH || '/opt/pw-browsers/chromium-1243/chrome-linux64/chrome' });
const page = await browser.newPage({ viewport: { width: 1080, height: 1350 } });
const n = spec.slides.length;
for (const [i, s] of spec.slides.entries()) {
  const t = T[s.theme || 'dunkel'];
  await page.setContent(`<!doctype html><html><head><meta charset="utf-8"><style>${css(t)}</style></head><body>${L[s.type](s, t, i, n)}</body></html>`, { waitUntil: 'networkidle' });
  await page.evaluate(() => document.fonts.ready);
  const f = resolve(outDir, `${String(i + 1).padStart(2, '0')}.png`);
  await page.screenshot({ path: f });
  console.log(f);
}
await browser.close();
