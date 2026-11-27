// Aufruf: node check.js <page.html> <label> [shotdir]  (Seite aus gen.php)
// Prüft je Pixel 7, 360x740, Desktop 1440: keine Überbreite, keine Überlappung mit der Vorschau, Handy-Bedienelemente >= 44 px.
const { chromium, devices } = require('playwright');
const path = require('path');
const page_ = path.resolve(process.argv[2]);
const label = process.argv[3];
const shotdir = process.argv[4] || __dirname;

const views = [
	{ name: 'pixel7', opts: { ...devices['Pixel 7'], deviceScaleFactor: 2 } },
	{ name: '360', opts: { viewport: { width: 360, height: 740 }, isMobile: true, hasTouch: true, deviceScaleFactor: 2, userAgent: devices['Pixel 7'].userAgent } },
	{ name: 'desktop1440', opts: { viewport: { width: 1440, height: 900 }, deviceScaleFactor: 1 } },
];

(async () => {
	const browser = await chromium.launch();
	let fail = 0;
	for (const v of views) {
		const ctx = await browser.newContext(v.opts);
		const page = await ctx.newPage();
		const errors = [];
		page.on('pageerror', e => errors.push(e.message));
		await page.goto('file://' + page_);
		await page.waitForTimeout(600);
		// Optik-Schritt und einen Mündungsschritt öffnen (wie im Screenshot)
		await page.evaluate(() => document.querySelectorAll('.ak-step').forEach((d, i) => { if (i < 3) d.open = true; }));
		await page.waitForTimeout(200);
		const vw = (v.opts.viewport || {}).width;
		const res = await page.evaluate((vw) => {
			const out = { vw, iw: innerWidth, sw: document.documentElement.scrollWidth, overlaps: [], small: [], wide: [] };
			const wrap = document.querySelector('.ak-stage-wrap').getBoundingClientRect();
			const inter = (a, b) => a.left < b.right - 1 && b.left < a.right - 1 && a.top < b.bottom - 1 && b.top < a.bottom - 1;
			// zu breite Elemente
			document.querySelectorAll('.ak-konfigurator *').forEach(el => {
				const r = el.getBoundingClientRect();
				if (r.width && r.right > vw + 0.5 && !el.closest('.ak-stage')) { out.wide.push((el.className || el.tagName) + ' ' + Math.round(r.right)); }
			});
			// Überlappung mit der Vorschau an mehreren Scroll-Positionen
			const sels = '.ak-panel .ak-card, .ak-panel .ak-card-price, .ak-panel del, .ak-panel ins, .ak-step > summary, .ak-summary';
			const H = document.documentElement.scrollHeight;
			for (let y = 0; y < H; y += 150) {
				window.scrollTo(0, y);
				const w = document.querySelector('.ak-stage-wrap').getBoundingClientRect();
				document.querySelectorAll(sels).forEach(el => {
					const r = el.getBoundingClientRect();
					if (r.width && r.height && inter(r, w)) { out.overlaps.push(y + ':' + (el.className || el.tagName)); }
				});
			}
			window.scrollTo(0, 0);
			// Knöpfe/Bedienelemente unter 44 px (nur Handy relevant)
			document.querySelectorAll('.ak-konfigurator button, .ak-konfigurator .button, .ak-konfigurator select, .ak-konfigurator input[type=search], .ak-step > summary, .ak-report > summary, .ak-card').forEach(el => {
				const r = el.getBoundingClientRect();
				if (r.height && r.height < 43.5) { out.small.push((el.className || el.tagName) + ' ' + Math.round(r.height)); }
			});
			out.overlapCount = out.overlaps.length;
			out.overlaps = out.overlaps.slice(0, 8);
			out.wide = out.wide.slice(0, 12);
			return out;
		}, vw);
		const mobile = v.name !== 'desktop1440';
		const ok = res.sw <= res.vw && res.iw <= res.vw && res.overlapCount === 0 && (!mobile || res.small.length === 0) && !errors.length;
		if (!ok) { fail++; }
		console.log(label, v.name, ok ? 'OK' : 'FEHLER', JSON.stringify({ ...res, errors }));
		// Screenshots: oben, und gescrollt in die Kartenliste (wie Pascals Bild)
		await page.screenshot({ path: path.join(shotdir, `${label}-${v.name}-oben.png`) });
		await page.evaluate(() => { const c = document.querySelectorAll('.ak-step[data-group="optik"] .ak-card')[3]; if (c) { window.scrollTo(0, c.getBoundingClientRect().top + scrollY - innerHeight * 0.55); } });
		await page.waitForTimeout(150);
		await page.screenshot({ path: path.join(shotdir, `${label}-${v.name}-liste.png`) });
		if (!mobile) { await page.screenshot({ path: path.join(shotdir, `${label}-${v.name}-ganz.png`), fullPage: true }); }
		await ctx.close();
	}
	await browser.close();
	process.exit(fail ? 1 : 0);
})();
