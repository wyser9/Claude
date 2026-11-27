// Aufruf: node check.js <page.html> <label> [shotdir]  (Seite aus gen.php)
// Prüft je Pixel 7, 360x740, Desktop 1440: keine Überbreite, keine Überlappung mit der Vorschau, Handy-Bedienelemente >= 44 px.
// Ab 1.0.11 zusätzlich: schwebender Fly-Cart-Knopf (#wcspc-count) verdeckt keine Schritt-Zeile/Karte, Handy-Leiste sichtbar
// (nur Handy), Knopf >= 44 px, am Seitenende nichts unter der Leiste, Leisten-Knopf löst GA4 genau einmal aus.
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
		// Screenshots: oben, und gescrollt in die Kartenliste (wie Pascals Bild)
		await page.screenshot({ path: path.join(shotdir, `${label}-${v.name}-oben.png`) });
		await page.evaluate(() => { const c = document.querySelectorAll('.ak-step[data-group="optik"] .ak-card')[3]; if (c) { window.scrollTo(0, c.getBoundingClientRect().top + scrollY - innerHeight * 0.55); } });
		await page.waitForTimeout(150);
		await page.screenshot({ path: path.join(shotdir, `${label}-${v.name}-liste.png`) });
		// Schritte wie Pascals Bild: Kopf «HUB (Schalldämpfer-Adapter)» auf Höhe des schwebenden Knopfs
		await page.evaluate(() => {
			document.querySelectorAll('.ak-step').forEach(d => { d.open = false; });
			const s = document.querySelector('.ak-step[data-group="hub"] > summary');
			if (s) { window.scrollTo(0, s.getBoundingClientRect().top + scrollY - innerHeight + 130); }
		});
		await page.waitForTimeout(150);
		await page.screenshot({ path: path.join(shotdir, `${label}-${v.name}-schritte.png`) });
		await page.evaluate(() => window.scrollTo(0, document.documentElement.scrollHeight));
		await page.waitForTimeout(150);
		await page.screenshot({ path: path.join(shotdir, `${label}-${v.name}-ende.png`) });
		if (!mobile) { await page.screenshot({ path: path.join(shotdir, `${label}-${v.name}-ganz.png`), fullPage: true }); }
		// 1.0.11: Fly-Cart-Knopf und Handy-Leiste
		const bar = await page.evaluate(() => {
			const out = { covered: [] };
			const vis = el => { if (!el) { return null; } const cs = getComputedStyle(el); const r = el.getBoundingClientRect(); return cs.display !== 'none' && cs.visibility !== 'hidden' && r.width && r.height ? r : null; };
			const inter = (a, b) => a.left < b.right - 1 && b.left < a.right - 1 && a.top < b.bottom - 1 && b.top < a.bottom - 1;
			const fc = document.getElementById('wcspc-count');
			const barEl = document.querySelector('.ak-bar');
			out.flycart = !!vis(fc);
			out.bar = !!vis(barEl);
			const H = document.documentElement.scrollHeight;
			for (let y = 0; y < H; y += 100) {
				window.scrollTo(0, y);
				const covers = [vis(fc), vis(barEl)].filter(Boolean);
				document.querySelectorAll('.ak-step > summary, .ak-card, .ak-summary .ak-add, .ak-share-copy, .ak-legal').forEach(el => {
					const r = el.getBoundingClientRect();
					if (!r.height || r.bottom <= 0 || r.top >= innerHeight) { return; }
					// Verdeckt = Element liegt im Fenster unter dem Knopf, obwohl es nicht ganz unten am Seitenende hinter der Reserve liegt
					if (covers[0] && fc && vis(fc) && inter(r, covers[0])) { out.covered.push(y + ':flycart:' + (el.className || el.tagName)); }
				});
			}
			// Seitenende: letztes Element des Konfigurators muss über der Leiste liegen
			window.scrollTo(0, H);
			const last = document.querySelector('.ak-legal').getBoundingClientRect();
			const br = vis(barEl);
			out.endFree = !br || last.bottom <= br.top + 0.5;
			if (br) {
				out.barH = Math.round(br.height);
				out.btnH = Math.round(barEl.querySelector('.ak-bar-add').getBoundingClientRect().height);
				out.cartH = Math.round(barEl.querySelector('.ak-bar-cart').getBoundingClientRect().height);
				out.count = barEl.querySelector('.ak-bar-count').textContent;
				out.total = barEl.querySelector('.ak-bar-total').textContent;
				out.hint = barEl.querySelector('.ak-bar-hint').textContent.slice(0, 60);
				out.barInside = br.left >= 0 && br.right <= innerWidth + 0.5;
			}
			window.scrollTo(0, 0);
			out.coveredCount = out.covered.length;
			out.covered = out.covered.slice(0, 6);
			return out;
		});
		// GA4: Leisten-Knopf (Handy) bzw. Paket-Knopf (Desktop) einmal antippen, Warenkorb-Aufruf nachgebildet
		const ga = await page.evaluate(async (mobile) => {
			const ev = [];
			window.gtag = (t, n) => ev.push(n);
			const btn = document.querySelector(mobile ? '.ak-bar .ak-bar-add' : '.ak-summary .ak-add');
			if (!btn) { return { missing: true }; }
			const disabled = btn.disabled;
			btn.click(); btn.click();
			await new Promise(r => setTimeout(r, 300));
			const posts = window.__akPosts;
			const c = document.querySelector('.ak-bar-count');
			return { disabled, posts, paket: ev.filter(n => n === 'paket_in_warenkorb').length, add: ev.filter(n => n === 'add_to_cart').length, count: c ? c.textContent : null };
		}, mobile);
		res.bar = bar;
		res.ga = ga;
		const barOk = mobile
			? bar.bar && !bar.flycart && bar.coveredCount === 0 && bar.endFree && bar.btnH >= 44 && bar.cartH >= 44 && bar.barInside
			: !bar.bar && bar.flycart;
		const gaOk = !ga.missing && !ga.disabled && ga.posts === 1 && ga.paket === 1 && ga.add === 1 && (!mobile || ga.count === '13');
		const ok = res.sw <= res.vw && res.iw <= res.vw && res.overlapCount === 0 && (!mobile || res.small.length === 0) && !errors.length && barOk && gaOk;
		if (!ok) { fail++; }
		console.log(label, v.name, ok ? 'OK' : 'FEHLER', JSON.stringify({ ...res, errors }));
		await ctx.close();
	}
	await browser.close();
	process.exit(fail ? 1 : 0);
})();
