/* Armatum Konfigurator – Vorschau und Paket-Auswahl */
(function ($) {
	'use strict';

	function fmt(n, cfg) {
		var parts = n.toFixed(cfg.decimals).split('.');
		parts[0] = parts[0].replace(/\B(?=(\d{3})+(?!\d))/g, "'");
		return cfg.currency + ' ' + parts.join('.');
	}

	function esc(s) {
		return String(s).replace(/[&<>"']/g, function (c) {
			return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
		});
	}

	/** Ankerpunkt in Bildpixeln, bei gespiegeltem Bild x gespiegelt. */
	function pt(item, p) {
		if (!p) { return [0, 0]; }
		return [item.flip ? item.imgW - p[0] : p[0], p[1]];
	}

	/** Länge des Platzhalters in mm: Länge des Teils (Messpunkte bzw. Feld Länge), sonst Bildbreite im Massstab. */
	function phLength(item) {
		return item.length > 0 ? item.length : item.imgW * item.mmPerPx;
	}

	/** Höhe des Platzhalters in mm (geschätzt, die Länge stimmt). */
	function phHeight(item) {
		var len = phLength(item);
		if (item.group === 'adapterschiene' && item.anchor && item.tubeAnchor) {
			var h = Math.abs(item.tubeAnchor[1] - item.anchor[1]) * item.mmPerPx;
			if (h > 2) { return h; }
		}
		if (item.tube > 0) { return item.tube * 1.5; }
		if (item.group === 'schalldaempfer') { return Math.min(50, Math.max(25, len * 0.25)); }
		return Math.min(60, Math.max(8, len * 0.3));
	}

	/**
	 * Rechteck eines Bildes in Millimetern, wenn sein Punkt p auf (x, y) liegen soll.
	 * Platzhalter (Teil hat nur eine Schrägansicht): Lage wie der Punkt im Bild liegt
	 * (Punkt hinten = Teil ragt nach vorne, Punkt unten = Teil steht darauf, sonst mittig).
	 */
	function place(item, p, x, y) {
		var s = item.mmPerPx;
		var a = pt(item, p);
		if (item.ph) {
			var len = phLength(item), hgt = phHeight(item);
			var fx = item.imgW ? a[0] / item.imgW : 0.5, fy = item.imgH ? a[1] / item.imgH : 0.5;
			return {
				item: item, ph: true, w: len, h: hgt,
				left: fx < 0.3 ? x : (fx > 0.7 ? x - len : x - len / 2),
				top: fy < 0.35 ? y : (fy > 0.65 ? y - hgt : y - hgt / 2)
			};
		}
		return { item: item, left: x - a[0] * s, top: y - a[1] * s, w: item.imgW * s, h: item.imgH * s };
	}

	function meets(a, b) {
		return (a || []).some(function (x) { return (b || []).indexOf(x) >= 0; });
	}

	var CHAIN = ['muendungsaufsatz', 'hub', 'schalldaempfer'];

	/** Einstieg (z.B. produkt, startseite, teilen) aus der URL, für alle GA4-Ereignisse. */
	var EINSTIEG = (function () {
		try { return (new URLSearchParams(window.location.search).get('einstieg') || '').slice(0, 40); } catch (e) { return ''; }
	})();

	/**
	 * GA4-Ereignis (ab 1.0.9). Nur wenn gtag auf der Seite ist (GA-Plugin, Einwilligung nach Snippet 52).
	 * Kein Personenbezug: nur Produkt-IDs, Artikelnummern, Namen, Preise.
	 */
	function track(name, params) {
		if (typeof window.gtag !== 'function') { return; }
		params = params || {};
		if (EINSTIEG && !params.einstieg) { params.einstieg = EINSTIEG; }
		try { window.gtag('event', name, params); } catch (e) { /* Messung darf den Konfigurator nie stören */ }
	}

	/** Teile, deren Shop-Bild im Browser freigestellt wird, damit sie das Originalteil der Waffe verdecken. */
	var AUTOCUT = ['schaft', 'griff'];
	var CUT_CACHE = {};

	/** Hintergrund vom Rand her entfernen (einfarbiger Fotohintergrund), Ergebnis als PNG zwischenspeichern. */
	function autoCut(img) {
		var url = img.getAttribute('data-src');
		var run = function () {
			try {
				var max = 900, sc = Math.min(1, max / Math.max(img.naturalWidth, img.naturalHeight));
				var w = Math.max(1, Math.round(img.naturalWidth * sc)), h = Math.max(1, Math.round(img.naturalHeight * sc));
				var c = document.createElement('canvas');
				c.width = w; c.height = h;
				var ctx = c.getContext('2d');
				ctx.drawImage(img, 0, 0, w, h);
				var d = ctx.getImageData(0, 0, w, h), px = d.data;
				var seen = new Uint8Array(w * h), stack = [];
				var push = function (x, y) {
					var i = y * w + x;
					if (!seen[i]) { seen[i] = 1; stack.push(i); }
				};
				var x, y;
				for (x = 0; x < w; x++) { push(x, 0); push(x, h - 1); }
				for (y = 0; y < h; y++) { push(0, y); push(w - 1, y); }
				// Hintergrund = helle, wenig gesättigte Pixel, die vom Rand her zusammenhängen.
				var bg = function (i) {
					var o = i * 4, r = px[o], g = px[o + 1], b = px[o + 2];
					if (px[o + 3] < 20) { return true; }
					var mx = Math.max(r, g, b), mn = Math.min(r, g, b);
					return mn > 200 && mx - mn < 25;
				};
				while (stack.length) {
					var i = stack.pop();
					if (!bg(i)) { continue; }
					px[i * 4 + 3] = 0;
					x = i % w; y = (i - x) / w;
					if (x > 0) { push(x - 1, y); }
					if (x < w - 1) { push(x + 1, y); }
					if (y > 0) { push(x, y - 1); }
					if (y < h - 1) { push(x, y + 1); }
				}
				ctx.putImageData(d, 0, 0);
				CUT_CACHE[url] = c.toDataURL('image/png');
				img.src = CUT_CACHE[url];
				$(img).removeClass('ak-autocut').addClass('ak-cutout');
			} catch (e) {
				// Fremdes Bild (Canvas gesperrt): Bild bleibt wie es ist.
			}
		};
		if (img.complete && img.naturalWidth) { run(); } else { img.addEventListener('load', run, { once: true }); }
	}

	var NONE = {
		optik: 'Keine Optik',
		schaft: 'Original-Schulterstütze behalten',
		hub: 'Kein HUB',
		muendungsaufsatz: 'Original-Mündungsaufsatz behalten',
		griff: 'Original-Griff behalten',
		handgriff: 'Kein Frontgriff',
		laser: 'Kein Laser',
		lampe: 'Keine Lampe',
		zweibein: 'Kein Zweibein'
	};
	var FILTER_MIN = 6;

	function Konfigurator($root) {
		this.$root = $root;
		this.cfg = $root.data('config');
		this.mountsById = {};
		var self = this;
		$.each(this.cfg.mounts, function (_, m) { self.mountsById[m.id] = m; });
		this.railsById = {};
		$.each(this.cfg.adapters || [], function (_, r) { self.railsById[r.id] = r; });
		this.magsById = {};
		$.each(this.cfg.mags || [], function (_, m) { self.magsById[m.id] = m; });
		this.magMountsById = {};
		$.each(this.cfg.magMounts || [], function (_, m) { self.magMountsById[m.id] = m; });
		this.rails = {};
		this.optic = null;
		this.mount = null;
		this.mag = null;
		this.magMount = null;
		this.parts = {};
		this.groups = this.cfg.groups || [];
		this.erwerbSeq = 0;
		this.erwerbKey = ''; // Zusammenfassung ohne Teile kommt schon fertig vom Server.
		this.sperre = false;
		this.renderSteps();
		this.initBar();
		this.bind();
		if (this.cfg.restore && this.cfg.restore.length) {
			this.restore(this.cfg.restore);
		} else {
			this.preselect(parseInt(this.cfg.preselect, 10) || 0);
		}
		this.update();
		$(window).on('resize', function () { self.draw(); });
		this.initStickyTop();
		var w = this.cfg.weapon;
		track('konfigurator_start', {
			waffe_id: w.id, waffe_name: w.name, artikelnummer: w.sku || '',
			waffenart: w.art === 'pistole' ? 'kurzwaffe' : 'langwaffe',
			teil_id: parseInt(this.cfg.preselect, 10) || 0
		});
	}

	/** GA4 komponente_gewaehlt: nur bei Klick des Kunden (nicht bei Vorwahl, Wiederherstellen oder automatischer Auswahl). */
	Konfigurator.prototype.trackPick = function (typ, item) {
		if (!item) { return; }
		track('komponente_gewaehlt', {
			typ: typ, artikelnummer: item.sku || '', item_id: item.id, item_name: item.name,
			preis: item.price, waffe_id: this.cfg.weapon.id
		});
	};

	/**
	 * Teilen-Link wiederherstellen (?teile=ID,ID,…, ab 1.0.9). Reihenfolge: Optik mit Montage und Magnifier,
	 * dann die Gruppen (Mündungskette zuerst), dann Adapterschienen. Was nicht (mehr) passt, fällt still weg.
	 */
	Konfigurator.prototype.restore = function (ids) {
		var self = this;
		var want = {};
		$.each(ids, function (_, id) { want[parseInt(id, 10)] = true; });
		var pick = function (list) { return (list || []).filter(function (id) { return want[id]; })[0] || 0; };
		var optic = this.cfg.optics.filter(function (o) { return want[o.id]; })[0];
		if (optic) {
			this.setOptic(optic.id, pick(optic.mounts));
			var magId = pick(optic.mags);
			if (magId && this.magsById[magId]) {
				this.renderMags(magId, pick(this.magsById[magId].mounts));
			}
		}
		$.each(this.groups, function (_, g) {
			var it = g.items.filter(function (x) { return want[x.id]; })[0];
			if (!it) { return; }
			if (CHAIN.indexOf(g.key) >= 0) {
				self.filterMuzzle();
				if (!self.chainFits(g.key, it.id)) { return; }
			}
			self.setPart(g.key, it.id);
			if (it.adapters && it.adapters.length) { self.renderRails(g.key, pick(it.adapters)); }
		});
	};

	/** Alle gewählten Produkt-IDs (für Teilen-Link und Erwerbs-Zusammenfassung). */
	Konfigurator.prototype.selectedIds = function () {
		var self = this;
		var ids = [];
		if (this.optic) {
			ids.push(this.optic.id);
			if (this.mount) { ids.push(this.mount.id); }
			if (this.mag) { ids.push(this.mag.id); }
			if (this.mag && this.magMount) { ids.push(this.magMount.id); }
		}
		$.each(this.selectedParts(), function (_, x) {
			ids.push(x.item.id);
			if (self.rails[x.g.key]) { ids.push(self.rails[x.g.key].id); }
		});
		return ids;
	};

	/** Link auf die aktuelle Zusammenstellung. */
	Konfigurator.prototype.shareUrl = function (forShare) {
		var u = new URL(window.location.href);
		u.searchParams.set('waffe', this.cfg.weapon.id);
		u.searchParams.delete('teil');
		var ids = this.selectedIds();
		if (ids.length) { u.searchParams.set('teile', ids.join(',')); } else { u.searchParams.delete('teile'); }
		if (forShare) { u.searchParams.set('einstieg', 'teilen'); }
		u.hash = '';
		return u.toString();
	};

	Konfigurator.prototype.copyLink = function () {
		var url = this.shareUrl(true);
		var $msg = this.$root.find('.ak-share-msg');
		var done = function () { $msg.text('Link kopiert.'); };
		var fallback = function () {
			var ta = document.createElement('textarea');
			ta.value = url;
			ta.setAttribute('readonly', '');
			ta.style.position = 'absolute';
			ta.style.left = '-9999px';
			document.body.appendChild(ta);
			ta.select();
			var ok = false;
			try { ok = document.execCommand('copy'); } catch (e) { ok = false; }
			document.body.removeChild(ta);
			if (ok) { done(); } else { $msg.text(url); }
		};
		if (navigator.clipboard && window.isSecureContext) {
			navigator.clipboard.writeText(url).then(done, fallback);
		} else {
			fallback();
		}
		track('konfigurator_link_kopiert', { waffe_id: this.cfg.weapon.id, anzahl_teile: this.selectedIds().length });
	};

	/** Erwerbs-Zusammenfassung vom Server holen (Regeln stehen zentral in AK_Erwerb). */
	Konfigurator.prototype.loadErwerb = function () {
		var self = this;
		var key = this.selectedIds().join(',');
		if (key === this.erwerbKey) { return; }
		this.erwerbKey = key;
		var seq = ++this.erwerbSeq;
		clearTimeout(this.erwerbTimer);
		var $box = this.$root.find('.ak-erwerb').addClass('ak-loading');
		this.erwerbTimer = setTimeout(function () {
			$.post(self.cfg.erwerbUrl, { weapon: self.cfg.weapon.id, ids: key }).done(function (res) {
				if (seq !== self.erwerbSeq || !res || !res.data) { return; }
				$box.html(res.data.html);
				self.sperre = !!res.data.sperre;
				self.updateButton();
				self.barHint();
			}).fail(function () {
				if (seq !== self.erwerbSeq) { return; }
				$box.html('<p class="ak-erwerb-abkl"><strong>Abklärung durch Armatum:</strong> Die Erwerbsbedingungen konnten gerade nicht berechnet werden. Wir prüfen die Zusammenstellung vor der Montage.</p>');
				self.barHint();
			}).always(function () {
				if (seq === self.erwerbSeq) { $box.removeClass('ak-loading'); }
			});
		}, 200);
	};

	Konfigurator.prototype.card = function (item, name, extra) {
		return '<label class="ak-card" data-id="' + item.id + '" data-search="' + esc((item.name + ' ' + (item.brand || '')).toLowerCase()) + '" data-brand="' + esc(item.brand || '') + '">' +
			'<input type="radio" name="' + name + '" value="' + item.id + '">' +
			'<img src="' + esc(item.thumb) + '" alt="" loading="lazy">' +
			'<span class="ak-card-text"><span class="ak-card-name">' + esc(item.name) + '</span>' +
			'<span class="ak-card-price">' + item.priceHtml + '</span>' +
			'<span class="ak-card-stock">' + esc(item.stock) + (extra ? ' · ' + esc(extra) : '') + '</span>' +
			(item.hinweis ? '<span class="ak-card-hint">' + esc(item.hinweis) + '</span>' : '') +
			(item.erwerb ? '<span class="ak-card-erwerb">Erwerb: ' + esc(item.erwerb) + '</span>' : '') + '</span>' +
			'</label>';
	};

	Konfigurator.prototype.noneCard = function (name, label) {
		return '<label class="ak-card ak-card-none" data-id="0"><input type="radio" name="' + name + '" value="0" checked>' +
			'<span></span><span class="ak-card-text"><span class="ak-card-name">' + esc(label) + '</span></span></label>';
	};

	/** Suche und Herstellerfilter, sobald eine Liste mehr als FILTER_MIN Artikel hat. */
	Konfigurator.prototype.filterBar = function (items) {
		if (items.length <= FILTER_MIN) { return ''; }
		var brands = [];
		$.each(items, function (_, it) {
			if (it.brand && brands.indexOf(it.brand) < 0) { brands.push(it.brand); }
		});
		brands.sort();
		var html = '<div class="ak-filter"><input type="search" class="ak-search" placeholder="Suchen …" aria-label="Suchen">';
		if (brands.length > 1) {
			html += '<select class="ak-brand" aria-label="Hersteller"><option value="">Alle Hersteller</option>';
			$.each(brands, function (_, b) { html += '<option value="' + esc(b) + '">' + esc(b) + '</option>'; });
			html += '</select>';
		}
		return html + '</div>';
	};

	/** Pistole: Optik-Schritt heisst «Rotpunkt» (nur Rotpunkte, direkt oder mit Adapterplatte). */
	Konfigurator.prototype.isPistol = function () {
		return this.cfg.weapon && this.cfg.weapon.art === 'pistole';
	};

	Konfigurator.prototype.noneOptic = function () {
		return this.isPistol() ? 'Kein Rotpunkt' : NONE.optik;
	};

	Konfigurator.prototype.step = function (key, title, inner, open) {
		return '<details class="ak-step" data-group="' + esc(key) + '"' + (open ? ' open' : '') + '>' +
			'<summary><span class="ak-step-title">' + esc(title) + '</span><span class="ak-step-choice"></span></summary>' +
			'<div class="ak-step-body">' + inner + '</div></details>';
	};

	Konfigurator.prototype.renderSteps = function () {
		var self = this;
		var html = '';
		var pistol = this.isPistol();
		if (this.cfg.optics.length) {
			var list = this.noneCard('ak_optic', this.noneOptic());
			$.each(this.cfg.optics, function (_, o) {
				var sub = o.mounts && o.mounts.length ? 'mit Adapterplatte' : (pistol ? 'direkt auf Schlitten' : 'direkt auf Schiene');
				list += self.card(o, 'ak_optic', o.direct ? sub : 'Mittelrohr ' + o.tube + 'mm');
			});
			html += this.step('optik', pistol ? 'Rotpunkt' : 'Optik', this.filterBar(this.cfg.optics) + '<div class="ak-list" role="radiogroup">' + list + '</div>' +
				'<div class="ak-step-mount" hidden><h4>Montage</h4><div class="ak-list ak-mounts" role="radiogroup"></div></div>' +
				'<div class="ak-step-mag" hidden><h4>Magnifier dazu?</h4><div class="ak-list ak-mags" role="radiogroup"></div>' +
				'<div class="ak-step-magmount" hidden><h4>Halterung für den Magnifier</h4><div class="ak-list ak-magmounts" role="radiogroup"></div></div></div>', true);
		}
		$.each(this.groups, function (i, g) {
			var list = self.noneCard('ak_g_' + g.key, NONE[g.key] || 'Kein ' + g.label);
			$.each(g.items, function (_, it) { list += self.card(it, 'ak_g_' + g.key, it.adapters && it.adapters.length ? 'mit Adapterschiene' : ''); });
			var note = g.key === 'schalldaempfer' ? 'Kein Schalldämpfer passt direkt auf das Mündungsgewinde. Wähle zuerst einen passenden HUB.' : g.key === 'hub' ? 'Für die gewählte Mündung ist kein HUB erfasst.' : '';
			html += self.step(g.key, g.label, (note ? '<p class="ak-step-note">' + esc(note) + '</p>' : '') + self.filterBar(g.items) + '<div class="ak-list" role="radiogroup">' + list + '</div>' +
				'<div class="ak-step-adapter" hidden><h4>Adapterschiene</h4><div class="ak-list ak-adapters" role="radiogroup"></div></div>', !html);
		});
		this.$root.find('.ak-steps').html(html);
	};

	Konfigurator.prototype.bind = function () {
		var self = this;
		var $steps = this.$root.find('.ak-steps');
		$steps.on('change', '.ak-step[data-group="optik"] > .ak-step-body > .ak-list input', function () {
			self.setOptic(parseInt(this.value, 10));
			self.trackPick('optik', self.optic);
			self.update();
		});
		$steps.on('change', '.ak-mounts input', function () {
			self.mount = self.mountsById[parseInt(this.value, 10)] || null;
			self.trackPick('montage', self.mount);
			self.renderMags(self.mag ? self.mag.id : 0, self.magMount ? self.magMount.id : 0);
			self.update();
		});
		$steps.on('change', '.ak-mags input', function () {
			self.setMag(parseInt(this.value, 10));
			self.trackPick('magnifier', self.mag);
			self.update();
		});
		$steps.on('change', '.ak-magmounts input', function () {
			self.magMount = self.magMountsById[parseInt(this.value, 10)] || null;
			self.trackPick('magnifier_montage', self.magMount);
			self.update();
		});
		$steps.on('change', '.ak-step:not([data-group="optik"]) > .ak-step-body > .ak-list input', function () {
			var key = $(this).closest('.ak-step').data('group');
			self.setPart(key, parseInt(this.value, 10));
			self.trackPick(key, self.parts[key]);
			self.update();
		});
		$steps.on('change', '.ak-adapters input', function () {
			var key = $(this).closest('.ak-step').data('group');
			self.rails[key] = self.railsById[parseInt(this.value, 10)] || null;
			self.trackPick('adapter_' + key, self.rails[key]);
			self.update();
		});
		$steps.on('input change', '.ak-search, .ak-brand', function () {
			self.applyFilter($(this).closest('.ak-step'));
		});
		this.$add.on('click', function () { self.add(); });
		this.$root.find('.ak-report-send').on('click', function () { self.report(); });
		this.$root.find('.ak-share-copy').on('click', function () { self.copyLink(); });
	};

	/**
	 * Handy-Leiste (ab 1.0.11): fest unten bis 860 px (CSS), Total, Erwerbs-Kurzhinweis, derselbe Paket-Knopf
	 * (gleiche Funktion add(), GA4 nur dort) und Warenkorb-Link mit Anzahl. Hängt am body, damit kein Theme-Container
	 * mit transform die feste Position bricht. body.ak-has-bar reserviert unten die Leistenhöhe (--ak-bar-h).
	 */
	Konfigurator.prototype.initBar = function () {
		var self = this;
		var cfg = this.cfg;
		var svg = '<svg viewBox="0 0 24 24" width="22" height="22" aria-hidden="true" focusable="false"><path fill="currentColor" d="M7 18a2 2 0 1 0 0 4 2 2 0 0 0 0-4zm10 0a2 2 0 1 0 0 4 2 2 0 0 0 0-4zM5.2 4l.6 2H21l-2.6 8.4a2 2 0 0 1-1.9 1.4H8.2a2 2 0 0 1-1.9-1.5L3.7 4H2V2h3a1 1 0 0 1 .9.7L6.3 4z"/></svg>';
		this.$bar = $('<div class="ak-bar" role="region" aria-label="Paket">' +
			'<p class="ak-bar-hint" role="status"></p>' +
			'<div class="ak-bar-row">' +
			'<span class="ak-bar-total"></span>' +
			'<button type="button" class="button alt ak-add ak-bar-add" disabled>Paket in den Warenkorb</button>' +
			'<a class="ak-bar-cart" href="' + esc(cfg.cartUrl) + '">' + svg + '<span class="ak-bar-count">0</span></a>' +
			'</div></div>').appendTo(document.body);
		this.$add = this.$root.find('.ak-add').add(this.$bar.find('.ak-add'));
		$(document.body).addClass('ak-konfigurator-seite ak-has-bar');
		var root = document.documentElement;
		var bar = this.$bar[0];
		var measure = function () {
			var h = bar.offsetHeight;
			if (h) { root.style.setProperty('--ak-bar-h', h + 'px'); }
		};
		if (window.ResizeObserver) { new ResizeObserver(measure).observe(bar); }
		$(window).on('resize', measure);
		measure();
		// Anzahl: Zähler des Fly-Cart-Plugins (#wcspc-count-number) mitlesen, sonst Wert vom Server bzw. aus dem Paket-Aufruf.
		this.setCount(cfg.cartCount || 0);
		var fc = document.getElementById('wcspc-count-number');
		if (fc) {
			var sync = function () { var n = parseInt(fc.textContent, 10); if (!isNaN(n)) { self.setCount(n); } };
			sync();
			if (window.MutationObserver) { new MutationObserver(sync).observe(fc, { childList: true, characterData: true, subtree: true }); }
		}
	};

	/**
	 * 1.0.12: Sticky-Vorschau auf dem Handy. Misst, ob oben ein fester/sticky Theme-Header (Blocksy, Admin-Leiste o.ä.)
	 * steht, und setzt dessen Unterkante als --ak-sticky-top, damit die Vorschau darunter und nicht dahinter klebt.
	 * Ohne festen Header bleibt der Wert 0. Neu gemessen bei Scrollen (Blocksy macht den Header erst beim Scrollen fest)
	 * und bei Grössenänderung.
	 */
	Konfigurator.prototype.initStickyTop = function () {
		var root = document.documentElement;
		var konf = this.$root[0];
		var mq = window.matchMedia ? window.matchMedia('(max-width: 860px)') : null;
		var last = -1;
		var pending = false;
		var fixed = function (el) {
			var p = window.getComputedStyle(el).position;
			return p === 'fixed' || p === 'sticky';
		};
		var measure = function () {
			pending = false;
			var top = 0;
			if (mq && mq.matches) {
				var seen = [];
				var add = function (el) {
					for (; el && el !== document.body && el !== root; el = el.parentElement) {
						if (seen.indexOf(el) >= 0 || konf.contains(el) || (el.closest && el.closest('.ak-bar'))) { continue; }
						seen.push(el);
						if (!fixed(el)) { continue; }
						var r = el.getBoundingClientRect();
						if (r.top <= 1 && r.bottom > 0 && r.height < window.innerHeight / 2 && r.width >= window.innerWidth / 2) {
							top = Math.max(top, Math.round(r.bottom));
						}
					}
				};
				var pts = document.elementsFromPoint ? document.elementsFromPoint(window.innerWidth / 2, 1) : [];
				for (var i = 0; i < pts.length; i++) { add(pts[i]); }
				$('#wpadminbar, #header [data-sticky], .ct-sticky-container > *, header.site-header').each(function () { add(this); });
			}
			if (top !== last) {
				last = top;
				root.style.setProperty('--ak-sticky-top', top + 'px');
			}
		};
		var queue = function () {
			if (pending) { return; }
			pending = true;
			(window.requestAnimationFrame || setTimeout)(measure);
		};
		$(window).on('scroll resize', queue);
		measure();
	};

	Konfigurator.prototype.setCount = function (n) {
		n = parseInt(n, 10) || 0;
		this.$bar.find('.ak-bar-count').text(n).prop('hidden', !n);
		this.$bar.find('.ak-bar-cart').attr('aria-label', 'Warenkorb, ' + n + ' Artikel');
	};

	/** Eine Zeile in der Leiste: Meldung nach dem Hinzufügen, sonst Erwerbs-Kurzhinweis. */
	Konfigurator.prototype.barHint = function (text, cls) {
		if (!this.$bar) { return; }
		var $box = this.$root.find('.ak-erwerb');
		if (text === undefined) {
			if (this.barMsgUntil && Date.now() < this.barMsgUntil) { return; }
			var $s = $box.find('.ak-erwerb-sperre').first();
			cls = $s.length ? 'ak-bar-sperre' : '';
			text = $.trim(($s.length ? $s : $box.find('.ak-erwerb-satz, .ak-erwerb-abkl').first()).text());
		}
		this.$bar.find('.ak-bar-hint').attr('class', 'ak-bar-hint' + (cls ? ' ' + cls : '')).text(text).prop('hidden', !text).attr('title', text);
	};

	Konfigurator.prototype.applyFilter = function ($step) {
		var q = ($step.find('.ak-search').val() || '').toLowerCase().trim();
		var brand = $step.find('.ak-brand').val() || '';
		$step.find('> .ak-step-body > .ak-list > .ak-card').each(function () {
			var $c = $(this);
			if ($c.data('id') === 0 || $c.find('input').prop('checked')) { $c.removeClass('ak-hide-filter'); return; }
			var ok = (!q || String($c.data('search')).indexOf(q) >= 0) && (!brand || $c.data('brand') === brand);
			$c.toggleClass('ak-hide-filter', !ok);
		});
	};

	Konfigurator.prototype.group = function (key) {
		return this.groups.filter(function (x) { return x.key === key; })[0] || null;
	};

	Konfigurator.prototype.setOptic = function (id, mountId) {
		this.optic = this.cfg.optics.filter(function (o) { return o.id === id; })[0] || null;
		this.mount = null;
		this.$root.find('input[name="ak_optic"][value="' + (this.optic ? id : 0) + '"]').prop('checked', true);
		this.renderMounts(mountId);
		this.renderMags(0, 0);
	};

	/** Passt der Magnifier (mit Halterung mm) hinter den gewählten Rotpunkt (mit Platte)? Wie AK_Data::mag_combo_ok. */
	Konfigurator.prototype.magFits = function (mag, mm) {
		var o = this.optic;
		if (!o) { return false; }
		var plate = this.mount;
		var a = o.axis > 0 ? o.axis + (plate ? plate.axisHeight : 0) : 0;
		var b = mm ? mm.axisHeight : mag.axis;
		if (a > 0 && b > 0 && Math.abs(a - b) > this.cfg.weapon.magTol) { return false; }
		return !!this.pairOffsets(mag, mm);
	};

	/** x-Lage von Rotpunkt und Magnifier in mm (Mitte des Paars am Anker, Rotpunkt vorne, innerhalb der Schiene). */
	Konfigurator.prototype.pairOffsets = function (mag, mm) {
		var w = this.cfg.weapon;
		var lr = Math.max(this.optic.length || 0, this.mount ? this.mount.length || 0 : 0);
		var lm = Math.max(mag.length || 0, mm ? mm.length || 0 : 0);
		var total = lr + w.magGap + lm;
		var xr = total / 2 - lr / 2;
		var xm = -total / 2 + lm / 2;
		if (w.railFront === null || w.railRear === null || w.railFront === undefined || w.railRear === undefined) { return [xr, xm]; }
		var front = Math.max(w.railFront, w.railRear), rear = Math.min(w.railFront, w.railRear);
		if (total > front - rear + 0.5) { return null; }
		var shift = Math.min(0, front - (xr + lr / 2)) + Math.max(0, rear - (xm - lm / 2));
		return [xr + shift, xm + shift];
	};

	/** Magnifier-Auswahl unter der Optik (nur Rotpunkte). */
	Konfigurator.prototype.renderMags = function (magId, mountId) {
		var self = this;
		var $box = this.$root.find('.ak-step-mag');
		var list = this.optic && this.optic.mags ? this.optic.mags.map(function (id) { return self.magsById[id]; }).filter(function (m) {
			return m && (m.mounts.length ? m.mounts.some(function (mid) { return self.magMountsById[mid] && self.magFits(m, self.magMountsById[mid]); }) : self.magFits(m, null));
		}) : [];
		if (!list.length) {
			this.mag = null;
			this.magMount = null;
			$box.prop('hidden', true).find('.ak-mags').empty();
			$box.find('.ak-step-magmount').prop('hidden', true).find('.ak-magmounts').empty();
			return;
		}
		var html = this.noneCard('ak_mag', 'Kein Magnifier');
		$.each(list, function (_, m) { html += self.card(m, 'ak_mag', m.mounts.length ? 'braucht Halterung' : 'mit Halterung'); });
		$box.prop('hidden', false).find('.ak-mags').html(html);
		var keep = list.some(function (m) { return m.id === magId; }) ? magId : 0;
		this.setMag(keep, mountId);
	};

	Konfigurator.prototype.setMag = function (id, mountId) {
		var self = this;
		this.mag = id ? this.magsById[id] || null : null;
		this.magMount = null;
		this.$root.find('input[name="ak_mag"][value="' + (this.mag ? id : 0) + '"]').prop('checked', true);
		var $box = this.$root.find('.ak-step-magmount');
		if (!this.mag || !this.mag.mounts.length) {
			$box.prop('hidden', true).find('.ak-magmounts').empty();
			return;
		}
		var html = '';
		$.each(this.mag.mounts, function (_, mid) {
			var mm = self.magMountsById[mid];
			if (mm && self.magFits(self.mag, mm)) { html += self.card(mm, 'ak_magmount'); }
		});
		$box.prop('hidden', false).find('.ak-magmounts').html(html);
		var $pick = mountId ? $box.find('input[value="' + mountId + '"]') : $();
		if (!$pick.length) { $pick = $box.find('input').first(); }
		$pick.prop('checked', true);
		this.magMount = this.magMountsById[parseInt($pick.val(), 10)] || null;
	};

	Konfigurator.prototype.setPart = function (key, id) {
		var g = this.group(key);
		this.parts[key] = id && g ? (g.items.filter(function (it) { return it.id === id; })[0] || null) : null;
		this.$root.find('input[name="ak_g_' + key + '"][value="' + (this.parts[key] ? id : 0) + '"]').prop('checked', true);
		this.renderRails(key);
	};

	/** Adapterschiene für ein Zubehör, das nicht direkt auf den Steckplatz passt (z.B. Picatinny-Laser auf M-LOK). */
	Konfigurator.prototype.renderRails = function (key, railId) {
		var self = this;
		var it = this.parts[key];
		var $box = this.$root.find('.ak-step[data-group="' + key + '"] .ak-step-adapter');
		this.rails[key] = null;
		if (!it || !it.adapters || !it.adapters.length) {
			$box.prop('hidden', true).find('.ak-adapters').empty();
			return;
		}
		var html = '';
		$.each(it.adapters, function (_, id) {
			if (self.railsById[id]) { html += self.card(self.railsById[id], 'ak_r_' + key); }
		});
		$box.prop('hidden', false).find('.ak-adapters').html(html);
		var $pick = railId ? $box.find('input[value="' + railId + '"]') : $();
		if (!$pick.length) { $pick = $box.find('input').first(); }
		$pick.prop('checked', true);
		this.rails[key] = this.railsById[parseInt($pick.val(), 10)] || null;
	};

	/** Teil aus ?teil= vorwählen; beim Schalldämpfer eine passende Kette (Aufsatz/HUB) suchen. */
	Konfigurator.prototype.preselect = function (id) {
		if (!id) { return; }
		var self = this;
		var key = null;
		if (this.cfg.optics.some(function (o) { return o.id === id; })) {
			this.setOptic(id);
			key = 'optik';
		} else if (this.mountsById[id]) {
			var o = this.cfg.optics.filter(function (x) { return x.mounts.indexOf(id) >= 0; })[0];
			if (o) { this.setOptic(o.id, id); key = 'optik'; }
		} else if (this.magsById[id] || this.magMountsById[id]) {
			var magId = this.magsById[id] ? id : ((this.cfg.mags || []).filter(function (m) { return m.mounts.indexOf(id) >= 0; })[0] || {}).id;
			var ro = this.cfg.optics.filter(function (x) { return (x.mags || []).indexOf(magId) >= 0; })[0];
			if (ro && magId) {
				this.setOptic(ro.id);
				this.renderMags(magId, this.magMountsById[id] ? id : 0);
				key = 'optik';
			}
		} else {
			$.each(this.groups, function (_, g) {
				if (g.items.some(function (it) { return it.id === id; })) { key = g.key; }
			});
			if (key && CHAIN.indexOf(key) >= 0) {
				this.preselectChain(key, id);
			} else if (key) {
				this.setPart(key, id);
			}
		}
		if (!key) { return; }
		this.$root.find('.ak-step').prop('open', false);
		var $step = this.$root.find('.ak-step[data-group="' + key + '"]').prop('open', true);
		if ($step[0] && $step[0].scrollIntoView) {
			setTimeout(function () { self.$root[0].scrollIntoView({ behavior: 'smooth', block: 'start' }); }, 50);
		}
	};

	/** Probiert Kombinationen der vorgelagerten Kettenglieder, bis das Teil passt. */
	Konfigurator.prototype.preselectChain = function (key, id) {
		var self = this;
		var idx = CHAIN.indexOf(key);
		var before = CHAIN.slice(0, idx);
		var options = before.map(function (g) {
			var grp = self.group(g);
			return [0].concat(grp ? grp.items.map(function (it) { return it.id; }) : []);
		});
		var combo = [];
		var found = false;
		var tryAt = function (i) {
			if (found) { return; }
			if (i === before.length) {
				$.each(before, function (j, g) { self.setPart(g, combo[j]); });
				self.setPart(key, id);
				if (self.chainFits(key, id)) { found = true; }
				return;
			}
			for (var k = 0; k < options[i].length && !found; k++) {
				combo[i] = options[i][k];
				tryAt(i + 1);
			}
		};
		tryAt(0);
		if (!found) {
			$.each(CHAIN, function (_, g) { self.setPart(g, 0); });
		}
	};

	/** Passt das Kettenglied key/id zu den aktuell gewählten vorgelagerten Teilen? */
	Konfigurator.prototype.chainFits = function (key, id) {
		var it = this.group(key).items.filter(function (x) { return x.id === id; })[0];
		var state = this.muzzle(key);
		// Vorgelagerte Teile müssen selbst sitzen.
		for (var g in state.placed) {
			if (Object.prototype.hasOwnProperty.call(state.placed, g) && !state.placed[g]) { return false; }
		}
		if (key === 'muendungsaufsatz') { return !!(state.thread && meets(it.ifs, state.thread.ifs)); }
		return state.ends.some(function (e) { return meets(it.ifs, e.ifs); });
	};

	/** Zeigt im Kopf jedes Schritts die aktuelle Wahl. */
	Konfigurator.prototype.updateChoices = function () {
		var self = this;
		this.$root.find('.ak-step').each(function () {
			var key = $(this).data('group');
			var text;
			if (key === 'optik') {
				text = self.optic ? self.optic.name + (self.mount ? ' + ' + self.mount.name : '') + (self.mag ? ' + ' + self.mag.name : '') : self.noneOptic();
			} else {
				var g = self.group(key);
				text = self.parts[key] ? self.parts[key].name + (self.rails[key] ? ' + ' + self.rails[key].name : '') : (NONE[key] || 'Kein ' + (g ? g.label : ''));
			}
			$(this).toggleClass('ak-step-set', key === 'optik' ? !!self.optic : !!self.parts[key])
				.find('.ak-step-choice').text(text);
		});
	};

	/** Punkt des Waffenbildes in Welt-Millimetern (Hauptanker = 0,0). */
	Konfigurator.prototype.weaponPoint = function (p) {
		var w = this.cfg.weapon;
		var a = pt(w, w.anchor);
		var q = pt(w, p);
		return [(q[0] - a[0]) * w.mmPerPx, (q[1] - a[1]) * w.mmPerPx];
	};

	Konfigurator.prototype.selectedParts = function () {
		var self = this;
		return this.groups.map(function (g) { return { g: g, item: self.parts[g.key] }; })
			.filter(function (x) { return x.item; });
	};

	Konfigurator.prototype.renderMounts = function (mountId) {
		var self = this;
		var $step = this.$root.find('.ak-step-mount');
		if (!this.optic || !this.optic.mounts.length) {
			$step.prop('hidden', true).find('.ak-mounts').empty();
			return;
		}
		// Rotpunkt mit Footprint auf einer Schiene: Adapterplatte statt Ringmontage.
		$step.find('h4').text(this.optic.direct ? 'Adapterplatte' : 'Montage');
		var html = '';
		$.each(this.optic.mounts, function (_, id) {
			if (self.mountsById[id]) { html += self.card(self.mountsById[id], 'ak_mount'); }
		});
		$step.prop('hidden', false).find('.ak-mounts').html(html);
		var $pick = mountId ? $step.find('input[value="' + mountId + '"]') : $();
		if (!$pick.length) { $pick = $step.find('input').first(); }
		$pick.prop('checked', true);
		this.mount = this.mountsById[parseInt($pick.val(), 10)] || null;
	};

	/**
	 * Mündungskette auflösen: liefert pro gewähltem Teil den Weltpunkt, an dem es sitzt,
	 * und die Schnittstellen, die danach nach vorne frei sind.
	 */
	Konfigurator.prototype.muzzle = function (upTo) {
		var w = this.cfg.weapon;
		var thread = w.slots && w.slots.muendung ? { ifs: w.threadIfs, at: this.weaponPoint(w.slots.muendung) } : null;
		var original = w.deviceAnchor && w.deviceIfs.length ? { ifs: w.deviceIfs, at: this.weaponPoint(w.deviceAnchor) } : null;
		var ends = [original, thread].filter(Boolean);
		var placed = {};
		for (var i = 0; i < CHAIN.length; i++) {
			var g = CHAIN[i];
			if (g === upTo) { break; }
			var it = this.parts[g];
			if (!it) { continue; }
			var base = g === 'muendungsaufsatz' ? (thread && meets(it.ifs, thread.ifs) ? thread : null)
				: ends.filter(function (e) { return meets(it.ifs, e.ifs); })[0];
			if (!base) { placed[g] = null; continue; }
			placed[g] = base.at;
			if (it.outs && it.outs.length && it.tubeAnchor) {
				var a = pt(it, it.anchor), t = pt(it, it.tubeAnchor);
				ends = [{ ifs: it.outs, at: [base.at[0] + (t[0] - a[0]) * it.mmPerPx, base.at[1] + (t[1] - a[1]) * it.mmPerPx] }];
			} else {
				ends = [];
			}
		}
		return { ends: ends, placed: placed, thread: thread };
	};

	/** Blendet Mündungsteile aus, die zur aktuellen Kette nicht passen, und setzt ungültige zurück. */
	Konfigurator.prototype.filterMuzzle = function () {
		var self = this;
		$.each(CHAIN, function (_, g) {
			var $step = self.$root.find('.ak-step[data-group="' + g + '"]');
			if (!$step.length) { return; }
			var state = self.muzzle(g);
			var fits = function (it) {
				return g === 'muendungsaufsatz' ? !!(state.thread && meets(it.ifs, state.thread.ifs))
					: state.ends.some(function (e) { return meets(it.ifs, e.ifs); });
			};
			var grp = self.group(g);
			$.each(grp.items, function (_, it) {
				var ok = fits(it);
				$step.find('input[value="' + it.id + '"]').closest('.ak-card').toggleClass('ak-hide-fit', !ok);
				if (!ok && self.parts[g] && self.parts[g].id === it.id) {
					self.parts[g] = null;
					$step.find('input[value="0"]').prop('checked', true);
				}
			});
			var visible = grp.items.some(function (it) { return fits(it); });
			$step.toggleClass('ak-step-empty', !visible);
		});
	};

	Konfigurator.prototype.layers = function () {
		var self = this;
		var w = this.cfg.weapon;
		var list = [$.extend(place(w, w.anchor, 0, 0), { z: 1 })];
		var mz = this.muzzle();
		$.each(this.selectedParts(), function (_, x) {
			var at;
			if (CHAIN.indexOf(x.g.key) >= 0) {
				at = mz.placed[x.g.key];
				if (!at) { return; }
			} else {
				var sp = w.slots && w.slots[x.item.slot || x.g.slot];
				if (!sp) { return; }
				at = self.weaponPoint(sp);
				var r = self.rails[x.g.key];
				if (r) {
					list.push($.extend(place(r, r.anchor, at[0], at[1]), { z: x.g.layer - 1 }));
					var ra = pt(r, r.anchor), rt = pt(r, r.tubeAnchor);
					at = [at[0] + (rt[0] - ra[0]) * r.mmPerPx, at[1] + (rt[1] - ra[1]) * r.mmPerPx];
				}
			}
			if (x.g.key === 'schaft') {
				// Original-Schulterstütze abdecken: alles hinter dem Gehäuseende (Mündung zeigt nach rechts).
				var wl = list[0];
				if (at[0] > wl.left) {
					list.push({ mask: true, left: wl.left, top: wl.top, w: at[0] - wl.left, h: wl.h, z: 1 });
				}
			}
			list.push($.extend(place(x.item, x.item.anchor, at[0], at[1]), { z: x.g.layer, autocut: AUTOCUT.indexOf(x.g.key) >= 0 && !x.item.cutout && !x.item.ph }));
		});
		// Mit Magnifier: Rotpunkt nach vorne, Magnifier dahinter.
		var off = this.optic && this.mag ? this.pairOffsets(this.mag, this.magMount) : null;
		var dx = off ? off[0] : 0;
		var mounted = function (item, mount, x, z) {
			if (!mount) {
				list.push($.extend(place(item, item.anchor, x, 0), { z: z }));
				return;
			}
			var a = pt(mount, mount.anchor);
			var t = pt(mount, mount.tubeAnchor);
			list.push($.extend(place(item, item.anchor, x + (t[0] - a[0]) * mount.mmPerPx, (t[1] - a[1]) * mount.mmPerPx), { z: z }));
			list.push($.extend(place(mount, mount.anchor, x, 0), { z: z + 1 }));
		};
		if (this.optic && (this.optic.direct || this.mount)) {
			mounted(this.optic, this.mount, dx, 4);
		}
		if (off) {
			mounted(this.mag, this.magMount, off[1], 4);
		}
		return list;
	};

	Konfigurator.prototype.draw = function () {
		var $stage = this.$root.find('.ak-stage');
		var layers = this.layers();
		var minX = Infinity, minY = Infinity, maxX = -Infinity, maxY = -Infinity;
		$.each(layers, function (_, l) {
			minX = Math.min(minX, l.left); minY = Math.min(minY, l.top);
			maxX = Math.max(maxX, l.left + l.w); maxY = Math.max(maxY, l.top + l.h);
		});
		var width = $stage.width() || 600;
		var k = width / (maxX - minX);
		// 1.0.12: Auf dem Handy ist die Vorschau sticky und in der Höhe begrenzt (CSS max-height). Dann das Bild in diese
		// Höhe einpassen (Seitenverhältnis bleibt) und waagrecht mitteln, statt es unten abzuschneiden.
		var maxH = $stage[0] ? parseFloat(window.getComputedStyle($stage[0]).maxHeight) : NaN;
		var dx = 0;
		if (maxH > 0 && (maxY - minY) * k > maxH) {
			k = maxH / (maxY - minY);
			dx = (width - (maxX - minX) * k) / 2;
		}
		var html = '';
		$.each(layers, function (i, l) {
			var box = 'left:' + (dx + (l.left - minX) * k).toFixed(1) + 'px;top:' + ((l.top - minY) * k).toFixed(1) + 'px;' +
				'width:' + (l.w * k).toFixed(1) + 'px;';
			if (l.mask) {
				html += '<div class="ak-mask" style="' + box + 'height:' + (l.h * k).toFixed(1) + 'px;z-index:' + l.z + '"></div>';
				return;
			}
			if (l.ph) {
				// Platzhalter statt Schrägbild: grau, in richtiger Länge, mit kurzem Namen.
				var hpx = l.h * k;
				html += '<div class="ak-ph" title="' + esc(l.item.name + ' (kein Seitenbild, Platzhalter in Originallänge)') + '" style="' + box +
					'height:' + hpx.toFixed(1) + 'px;font-size:' + Math.max(8, Math.min(13, hpx * 0.45)).toFixed(1) + 'px;z-index:' + (l.z || (i + 1)) + '">' +
					'<span>' + esc(l.item.short || l.item.name) + '</span></div>';
				return;
			}
			var src = l.autocut && CUT_CACHE[l.item.img] ? CUT_CACHE[l.item.img] : l.item.img;
			var cut = l.item.cutout || (l.autocut && CUT_CACHE[l.item.img]);
			html += '<img class="ak-layer' + (l.item.flip ? ' ak-flip' : '') + (cut ? ' ak-cutout' : '') + (l.autocut && !cut ? ' ak-autocut' : '') +
				'" alt="' + esc(l.item.name) + '" src="' + esc(src) + '" data-src="' + esc(l.item.img) + '" style="' + box + 'z-index:' + (l.z || (i + 1)) + '">';
		});
		$stage.css('height', ((maxY - minY) * k).toFixed(0) + 'px').html(html);
		if (this.$root[0] && $stage[0]) { this.$root[0].style.setProperty('--ak-stage-h', $stage[0].offsetHeight + 'px'); }
		this.$root.find('.ak-ph-note').prop('hidden', !layers.some(function (l) { return l.ph; }));
		$stage.find('img.ak-autocut').each(function () { autoCut(this); });
	};

	Konfigurator.prototype.update = function () {
		var cfg = this.cfg;
		this.filterMuzzle();
		this.updateChoices();
		var lines = [cfg.weapon];
		if (this.mount && this.optic) { lines.push(this.mount); }
		if (this.optic) { lines.push(this.optic); }
		if (this.optic && this.magMount && this.mag) { lines.push(this.magMount); }
		if (this.optic && this.mag) { lines.push(this.mag); }
		var self = this;
		var railMissing = false;
		$.each(this.selectedParts(), function (_, x) {
			lines.push(x.item);
			if (self.rails[x.g.key]) { lines.push(self.rails[x.g.key]); }
			if (x.item.adapters && x.item.adapters.length && !self.rails[x.g.key]) { railMissing = true; }
		});
		var total = 0;
		var html = '';
		$.each(lines, function (_, l) {
			total += l.price;
			html += '<li><span>' + esc(l.name) + '</span><span>' + fmt(l.price, cfg) + '</span></li>';
		});
		if (lines.length > 1) {
			html += '<li class="ak-free"><span>Montage</span><span>gratis</span></li>';
		}
		this.$root.find('.ak-lines').html(html);
		this.$root.find('.ak-total').html('Total <strong>' + fmt(total, cfg) + '</strong>');
		if (this.$bar) {
			this.$bar.find('.ak-bar-total').html('<small>Total</small> <strong>' + fmt(total, cfg) + '</strong>');
			this.barHint();
		}
		var opticOk = (!this.optic || !this.optic.mounts.length || this.mount) && (!this.mag || !this.mag.mounts.length || this.magMount);
		this.ready = opticOk && !railMissing && lines.length > 1;
		this.total = total;
		this.lineCount = lines.length;
		this.updateButton();
		this.draw();
		this.loadErwerb();
		// Adresszeile zeigt die Zusammenstellung (Teilen über den Browser, Neuladen behält die Auswahl).
		if (window.history && window.history.replaceState) {
			try { window.history.replaceState(null, '', this.shareUrl(false)); } catch (e) { /* z.B. file:// */ }
		}
	};

	Konfigurator.prototype.updateButton = function () {
		this.$add.prop('disabled', !this.ready || this.sperre);
	};

	/** Auswahl als Formulardaten (für Warenkorb und Meldung). */
	Konfigurator.prototype.selection = function () {
		var self = this;
		var data = {
			weapon: this.cfg.weapon.id,
			optic: this.optic ? this.optic.id : 0,
			mount: this.optic && this.mount ? this.mount.id : 0,
			mag: this.optic && this.mag ? this.mag.id : 0,
			mag_mount: this.optic && this.mag && this.magMount ? this.magMount.id : 0
		};
		$.each(this.selectedParts(), function (_, x) {
			data['parts[' + x.g.key + ']'] = x.item.id;
			if (self.rails[x.g.key]) { data['adapters[' + x.g.key + ']'] = self.rails[x.g.key].id; }
		});
		return data;
	};

	Konfigurator.prototype.report = function () {
		var $r = this.$root.find('.ak-report');
		var $msg = $r.find('.ak-report-msg').removeClass('ak-error').text('');
		var text = $.trim($r.find('.ak-report-text').val());
		if (text.length < 5) { $msg.addClass('ak-error').text('Bitte kurz beschreiben, was nicht stimmt.'); return; }
		var $btn = $r.find('.ak-report-send').prop('disabled', true);
		var data = $.extend(this.selection(), {
			text: text, email: $r.find('.ak-report-mail').val(), website: $r.find('.ak-report-hp').val(), url: window.location.href
		});
		var self = this;
		$.post(this.cfg.reportUrl, data).done(function (res) {
			$msg.text(res.data.message);
			track('konfigurator_meldung', { waffe_id: self.cfg.weapon.id });
			$r.find('.ak-report-text').val('');
		}).fail(function (xhr) {
			$msg.addClass('ak-error').text(xhr.responseJSON && xhr.responseJSON.data ? xhr.responseJSON.data.message : 'Senden fehlgeschlagen.');
		}).always(function () { $btn.prop('disabled', false); });
	};

	Konfigurator.prototype.add = function () {
		var self = this;
		if (this.adding) { return; } // Doppeltipp oder beide Knöpfe: nur ein Aufruf, GA4 nur einmal.
		this.adding = true;
		var $btn = this.$add.prop('disabled', true).addClass('loading');
		var $msg = this.$root.find('.ak-msg').removeClass('ak-error').text('');
		var data = this.selection();
		$.post(this.cfg.ajaxUrl, data).done(function (res) {
			$msg.html(esc(res.data.message) + ' <a href="' + esc(self.cfg.cartUrl) + '">Zum Warenkorb</a>');
			if (typeof res.data.count === 'number') { self.setCount(res.data.count); }
			self.barMsgUntil = Date.now() + 5000;
			self.barHint(res.data.message, 'ak-bar-ok');
			setTimeout(function () { self.barHint(); }, 5100);
			// Kein added_to_cart auslösen: sonst sendet das GA-Plugin add_to_cart doppelt.
			$(document.body).trigger('wc_fragment_refresh');
			self.trackCart(res.data);
		}).fail(function (xhr) {
			var m = xhr.responseJSON && xhr.responseJSON.data ? xhr.responseJSON.data.message : 'Fehler beim Hinzufügen.';
			$msg.addClass('ak-error').text(m);
			self.barMsgUntil = Date.now() + 8000;
			self.barHint(m, 'ak-bar-sperre');
			setTimeout(function () { self.barHint(); }, 8100);
			track('paket_fehler', { fehler: String(m).slice(0, 100), waffe_id: self.cfg.weapon.id });
		}).always(function () {
			self.adding = false;
			$btn.removeClass('loading');
			self.updateButton();
		});
	};

	/** GA4 nach erfolgreichem Hinzufügen: paket_in_warenkorb (eigenes) und add_to_cart (Standard, alle Positionen). */
	Konfigurator.prototype.trackCart = function (d) {
		var cfg = this.cfg;
		var lines = (d && d.lines) || [];
		var value = d && typeof d.value === 'number' ? d.value : this.total;
		var parts = lines.filter(function (l) { return l.role !== 'service'; });
		track('paket_in_warenkorb', {
			value: value, currency: 'CHF', anzahl_teile: parts.length || this.lineCount,
			waffe_id: cfg.weapon.id, paket_id: (d && d.package) || ''
		});
		if (!parts.length) { return; }
		track('add_to_cart', {
			currency: 'CHF', value: value,
			items: parts.map(function (l) {
				return {
					item_id: String(l.id), item_name: l.name, price: l.price, quantity: 1,
					item_category: l.role, item_list_id: 'konfigurator', item_list_name: 'Konfigurator'
				};
			})
		});
	};

	/** Waffenauswahl: Suche und Filter im Browser. */
	function initPicker($root) {
		var $cards = $root.find('.ak-weapon-card');
		var $count = $root.find('.ak-pf-count');
		var $none = $root.find('.ak-pf-none');
		function norm(s) { return String(s || '').toLowerCase().normalize('NFD').replace(/[\u0300-\u036f]/g, ''); }
		$cards.each(function () { this._q = norm($(this).attr('data-q')); });
		function apply() {
			var words = norm($root.find('.ak-pf-q').val()).split(/\s+/).filter(Boolean);
			var sel = {};
			$root.find('.ak-pf-sel').each(function () { if (this.value) { sel[$(this).data('facet')] = this.value; } });
			var chip = $root.find('.ak-pf-chips button.is-active').attr('data-value');
			if (chip) { sel.cat = chip; }
			var n = 0;
			$cards.each(function () {
				var el = this, ok = words.every(function (w) { return el._q.indexOf(w) >= 0; });
				Object.keys(sel).forEach(function (f) {
					if (ok && String($(el).attr('data-' + f) || '').split('|').indexOf(sel[f]) < 0) { ok = false; }
				});
				el.hidden = !ok;
				if (ok) { n++; }
			});
			$count.text(n + (n === 1 ? ' Waffe' : ' Waffen'));
			$none.prop('hidden', n > 0);
		}
		$root.on('input change', '.ak-pf-q, .ak-pf-sel', apply);
		track('konfigurator_auswahl', { anzahl_waffen: parseInt($root.data('anzahl'), 10) || $cards.length, teil_id: parseInt($root.data('teil'), 10) || 0 });
		$root.on('click', '.ak-pf-chips button', function () {
			$(this).addClass('is-active').siblings().removeClass('is-active');
			apply();
		});
	}

	$(function () {
		$('.ak-picker').each(function () { initPicker($(this)); });
		$('.ak-konfigurator').not('.ak-picker').each(function () { new Konfigurator($(this)); });
	});
})(jQuery);
