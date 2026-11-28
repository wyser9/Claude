// Aufruf: node check.js <page.html> <label> [shotdir]  (Seite aus gen.php)
// Prüft je Pixel 7, 360x740, Desktop 1440: keine Überbreite, keine Überlappung mit der Vorschau, Handy-Bedienelemente >= 44 px.
// Ab 1.0.11 zusätzlich: schwebender Fly-Cart-Knopf (#wcspc-count) verdeckt keine Schritt-Zeile/Karte, Handy-Leiste sichtbar
// (nur Handy), Knopf >= 44 px, am Seitenende nichts unter der Leiste, Leisten-Knopf löst GA4 genau einmal aus.
// Ab 1.0.12 (Handy): Vorschau (.ak-stage) ist sticky und bleibt beim Scrollen durch die Auswahl ganz im Fenster, nichts
// liegt über ihr (Hit-Test: oberstes Element an Punkten der Vorschau gehört zur Vorschau), Karten unter ihr bleiben
// antippbar, Hinweistext und «Melden» sind nicht sticky, Leiste unten frei, fester Theme-Header wird berücksichtigt,
// Querformat (Höhe < 500 px): nicht sticky. Desktop: Vorschau-Block wie bisher sticky (top 1em).
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
			const inter = (a, b) => a.left < b.right - 1 && b.left < a.right - 1 && a.top < b.bottom - 1 && b.top < a.bottom - 1;
			// zu breite Elemente
			document.querySelectorAll('.ak-konfigurator *').forEach(el => {
				const r = el.getBoundingClientRect();
				if (r.width && r.right > vw + 0.5 && !el.closest('.ak-stage')) { out.wide.push((el.className || el.tagName) + ' ' + Math.round(r.right)); }
			});
			// Überlappung mit der Vorschau an mehreren Scroll-Positionen
			const sels = '.ak-panel .ak-card, .ak-panel .ak-card-price, .ak-panel del, .ak-panel ins, .ak-step > summary, .ak-summary';
			const H = document.documentElement.scrollHeight;
			const stage = document.querySelector('.ak-stage');
			const sticky = getComputedStyle(stage).position === 'sticky';
			for (let y = 0; y < H; y += 150) {
				window.scrollTo(0, y);
				if (sticky) {
					// Sticky: Karten laufen absichtlich unter der Vorschau durch. Geprüft wird, dass nichts darüber gemalt wird.
					const w = stage.getBoundingClientRect();
					if (w.bottom <= 0 || w.top >= innerHeight) { continue; }
					for (let fx = 0.05; fx < 1; fx += 0.15) {
						for (let fy = 0.05; fy < 1; fy += 0.3) {
							const px = w.left + w.width * fx, py = Math.max(0, w.top) + (Math.min(innerHeight, w.bottom) - Math.max(0, w.top)) * fy;
							const top = document.elementFromPoint(px, py);
							if (top && !stage.contains(top)) { out.overlaps.push(y + ':' + (top.className || top.tagName)); }
						}
					}
					continue;
				}
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
		// 1.0.12: Sticky-Vorschau
		const st = await page.evaluate(async (mobile) => {
			const out = { fails: [] };
			const tick = () => new Promise(r => requestAnimationFrame(() => requestAnimationFrame(r)));
			const stage = document.querySelector('.ak-stage');
			const layout = document.querySelector('.ak-layout');
			const barEl = document.querySelector('.ak-bar');
			const pos = el => getComputedStyle(el).position;
			out.stagePos = pos(stage);
			out.wrapPos = pos(document.querySelector('.ak-stage-wrap'));
			if (!mobile) {
				if (out.wrapPos !== 'sticky') { out.fails.push('desktop: Vorschau-Block nicht mehr sticky'); }
				return out;
			}
			if (out.stagePos !== 'sticky') { out.fails.push('Vorschau nicht sticky'); return out; }
			// Hinweistext und «Melden» gehören nicht zum sticky Teil
			['.ak-note', '.ak-report'].forEach(sel => { const el = document.querySelector(sel); if (stage.contains(el)) { out.fails.push(sel + ' im sticky Teil'); } });
			const cs = getComputedStyle(stage);
			out.bg = cs.backgroundColor;
			if (!/rgb\(255, 255, 255\)/.test(cs.backgroundColor)) { out.fails.push('Hintergrund nicht deckend weiss: ' + cs.backgroundColor); }
			out.z = cs.zIndex;
			out.stageH = Math.round(stage.getBoundingClientRect().height);
			out.maxH = Math.round(innerHeight * 0.38);
			if (out.stageH > out.maxH + 1) { out.fails.push('Vorschau höher als 38vh: ' + out.stageH); }
			// Bild skaliert mit: alle Bild-Ebenen innerhalb der Vorschau
			stage.querySelectorAll('.ak-layer, .ak-ph').forEach(l => {
				const r = l.getBoundingClientRect(), s = stage.getBoundingClientRect();
				if (r.bottom > s.bottom + 1.5 || r.left < s.left - 1.5 || r.right > s.right + 1.5) { out.fails.push('Bild ragt aus der Vorschau'); }
			});
			// Scrollen durch die ganze Auswahl: Vorschau bleibt ganz sichtbar, oben angeheftet, über der Leiste
			const L = layout.getBoundingClientRect().top + scrollY;
			const end = L + layout.offsetHeight - stage.offsetHeight - innerHeight * 0.2;
			const st0 = stage.getBoundingClientRect().top + scrollY;
			let n = 0;
			for (let y = st0 + 20; y < end; y += 120) {
				window.scrollTo(0, y); await tick(); n++;
				const r = stage.getBoundingClientRect();
				const topPx = parseFloat(getComputedStyle(document.documentElement).getPropertyValue('--ak-sticky-top')) || 0;
				if (Math.abs(r.top - topPx) > 1.5) { out.fails.push(Math.round(y) + ': Vorschau nicht oben (' + Math.round(r.top) + ')'); }
				if (r.bottom > innerHeight || r.top < 0) { out.fails.push(Math.round(y) + ': Vorschau nicht ganz im Fenster'); }
				const b = barEl.getBoundingClientRect();
				if (r.bottom > b.top - 1) { out.fails.push(Math.round(y) + ': Vorschau überlappt Leiste'); }
			}
			out.scrollSteps = n;
			// Mindestens 2 Auswahlzeilen zwischen Vorschau und Leiste (Kartenhöhe aus der Optik-Liste)
			const card = document.querySelector('.ak-step[open] .ak-card');
			const free = barEl.getBoundingClientRect().top - stage.getBoundingClientRect().bottom;
			out.free = Math.round(free);
			if (card && free < 2 * Math.min(card.offsetHeight, 90)) { out.fails.push('zu wenig Platz unter der Vorschau: ' + Math.round(free)); }
			// Aufgeklappte Auswahl: eine Karte unter der Vorschau ist verdeckt (läuft drunter durch), eine darunter antippbar
			const cards = [...document.querySelectorAll('.ak-step[open] .ak-card')].filter(c => c.offsetParent);
			const c3 = cards[3];
			if (c3) {
				const sR = stage.getBoundingClientRect();
				window.scrollTo(0, c3.getBoundingClientRect().top + scrollY - sR.bottom - 10); await tick();
				let r = c3.getBoundingClientRect();
				const hit = document.elementFromPoint(r.left + r.width / 2, Math.min(r.top + 20, innerHeight - 1));
				out.cardTappable = !!hit && c3.contains(hit);
				if (!out.cardTappable) { out.fails.push('Karte unter der Vorschau nicht antippbar: ' + (hit && hit.className)); }
				window.scrollTo(0, scrollY + 60); await tick();
				r = c3.getBoundingClientRect();
				const hid = document.elementFromPoint(r.left + r.width / 2, r.top + 5);
				out.cardUnderStage = r.top < stage.getBoundingClientRect().bottom && !!hid && stage.contains(hid);
				if (!out.cardUnderStage) { out.fails.push('Karte läuft nicht unter der Vorschau durch: ' + (hid && hid.className)); }
			}
			// Fester Theme-Header (Nachbildung Blocksy, 60 px): Vorschau klebt darunter
			const hd = document.createElement('header');
			hd.id = 'header';
			hd.innerHTML = '<div data-sticky="yes" style="position:fixed;top:0;left:0;right:0;height:60px;z-index:50;background:#192a3d"></div>';
			document.body.prepend(hd);
			window.scrollTo(0, st0 + 300); window.dispatchEvent(new Event('scroll')); await tick(); await tick();
			out.headerTop = Math.round(stage.getBoundingClientRect().top);
			if (Math.abs(out.headerTop - 60) > 1.5) { out.fails.push('Vorschau nicht unter festem Header: ' + out.headerTop); }
			hd.remove(); window.dispatchEvent(new Event('scroll')); await tick(); await tick();
			out.headerTopAfter = Math.round(stage.getBoundingClientRect().top);
			if (out.headerTopAfter !== 0) { out.fails.push('Header-Abstand bleibt nach Entfernen: ' + out.headerTopAfter); }
			window.scrollTo(0, 0);
			return out;
		}, mobile);
		res.sticky = st;
		if (v.name === 'pixel7') {
			// Querformat (Höhe < 500 px, schmaler als 861 px): nicht sticky
			await page.setViewportSize({ width: 800, height: 380 });
			await page.waitForTimeout(200);
			st.quer = await page.evaluate(() => getComputedStyle(document.querySelector('.ak-stage')).position);
			if (st.quer === 'sticky') { st.fails.push('Querformat: Vorschau sticky'); }
			await page.setViewportSize(v.opts.viewport);
			await page.waitForTimeout(200);
		}
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
		const ok = st.fails.length === 0 && res.sw <= res.vw && res.iw <= res.vw && res.overlapCount === 0 && (!mobile || res.small.length === 0) && !errors.length && barOk && gaOk;
		if (!ok) { fail++; }
		console.log(label, v.name, ok ? 'OK' : 'FEHLER', JSON.stringify({ ...res, errors }));
		await ctx.close();
	}
	await browser.close();
	process.exit(fail ? 1 : 0);
})();
