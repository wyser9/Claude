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

	/** Rechteck eines Bildes in Millimetern, wenn sein Punkt p auf (x, y) liegen soll. */
	function place(item, p, x, y) {
		var s = item.mmPerPx;
		var a = pt(item, p);
		return { item: item, left: x - a[0] * s, top: y - a[1] * s, w: item.imgW * s, h: item.imgH * s };
	}

	function meets(a, b) {
		return (a || []).some(function (x) { return (b || []).indexOf(x) >= 0; });
	}

	var CHAIN = ['muendungsaufsatz', 'hub', 'schalldaempfer'];

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
		schaft: 'Keine Schulterstütze',
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
		this.renderSteps();
		this.bind();
		this.preselect(parseInt(this.cfg.preselect, 10) || 0);
		this.update();
		$(window).on('resize', function () { self.draw(); });
	}

	Konfigurator.prototype.card = function (item, name, extra) {
		return '<label class="ak-card" data-id="' + item.id + '" data-search="' + esc((item.name + ' ' + (item.brand || '')).toLowerCase()) + '" data-brand="' + esc(item.brand || '') + '">' +
			'<input type="radio" name="' + name + '" value="' + item.id + '">' +
			'<img src="' + esc(item.thumb) + '" alt="" loading="lazy">' +
			'<span class="ak-card-text"><span class="ak-card-name">' + esc(item.name) + '</span>' +
			'<span class="ak-card-price">' + item.priceHtml + '</span>' +
			'<span class="ak-card-stock">' + esc(item.stock) + (extra ? ' · ' + esc(extra) : '') + '</span>' +
			(item.hinweis ? '<span class="ak-card-hint">' + esc(item.hinweis) + '</span>' : '') + '</span>' +
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
			self.update();
		});
		$steps.on('change', '.ak-mounts input', function () {
			self.mount = self.mountsById[parseInt(this.value, 10)] || null;
			self.renderMags(self.mag ? self.mag.id : 0, self.magMount ? self.magMount.id : 0);
			self.update();
		});
		$steps.on('change', '.ak-mags input', function () {
			self.setMag(parseInt(this.value, 10));
			self.update();
		});
		$steps.on('change', '.ak-magmounts input', function () {
			self.magMount = self.magMountsById[parseInt(this.value, 10)] || null;
			self.update();
		});
		$steps.on('change', '.ak-step:not([data-group="optik"]) > .ak-step-body > .ak-list input', function () {
			self.setPart($(this).closest('.ak-step').data('group'), parseInt(this.value, 10));
			self.update();
		});
		$steps.on('change', '.ak-adapters input', function () {
			self.rails[$(this).closest('.ak-step').data('group')] = self.railsById[parseInt(this.value, 10)] || null;
			self.update();
		});
		$steps.on('input change', '.ak-search, .ak-brand', function () {
			self.applyFilter($(this).closest('.ak-step'));
		});
		this.$root.find('.ak-add').on('click', function () { self.add(); });
		this.$root.find('.ak-report-send').on('click', function () { self.report(); });
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
			list.push($.extend(place(x.item, x.item.anchor, at[0], at[1]), { z: x.g.layer, autocut: AUTOCUT.indexOf(x.g.key) >= 0 && !x.item.cutout }));
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
		var html = '';
		$.each(layers, function (i, l) {
			var box = 'left:' + ((l.left - minX) * k).toFixed(1) + 'px;top:' + ((l.top - minY) * k).toFixed(1) + 'px;' +
				'width:' + (l.w * k).toFixed(1) + 'px;';
			if (l.mask) {
				html += '<div class="ak-mask" style="' + box + 'height:' + (l.h * k).toFixed(1) + 'px;z-index:' + l.z + '"></div>';
				return;
			}
			var src = l.autocut && CUT_CACHE[l.item.img] ? CUT_CACHE[l.item.img] : l.item.img;
			var cut = l.item.cutout || (l.autocut && CUT_CACHE[l.item.img]);
			html += '<img class="ak-layer' + (l.item.flip ? ' ak-flip' : '') + (cut ? ' ak-cutout' : '') + (l.autocut && !cut ? ' ak-autocut' : '') +
				'" alt="' + esc(l.item.name) + '" src="' + esc(src) + '" data-src="' + esc(l.item.img) + '" style="' + box + 'z-index:' + (l.z || (i + 1)) + '">';
		});
		$stage.css('height', ((maxY - minY) * k).toFixed(0) + 'px').html(html);
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
		var opticOk = (!this.optic || !this.optic.mounts.length || this.mount) && (!this.mag || !this.mag.mounts.length || this.magMount);
		var ready = opticOk && !railMissing && lines.length > 1;
		this.$root.find('.ak-add').prop('disabled', !ready);
		this.draw();
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
		$.post(this.cfg.reportUrl, data).done(function (res) {
			$msg.text(res.data.message);
			$r.find('.ak-report-text').val('');
		}).fail(function (xhr) {
			$msg.addClass('ak-error').text(xhr.responseJSON && xhr.responseJSON.data ? xhr.responseJSON.data.message : 'Senden fehlgeschlagen.');
		}).always(function () { $btn.prop('disabled', false); });
	};

	Konfigurator.prototype.add = function () {
		var self = this;
		var $btn = this.$root.find('.ak-add').prop('disabled', true).addClass('loading');
		var $msg = this.$root.find('.ak-msg').removeClass('ak-error').text('');
		var data = this.selection();
		$.post(this.cfg.ajaxUrl, data).done(function (res) {
			$msg.html(esc(res.data.message) + ' <a href="' + esc(self.cfg.cartUrl) + '">Zum Warenkorb</a>');
			$(document.body).trigger('wc_fragment_refresh');
		}).fail(function (xhr) {
			var m = xhr.responseJSON && xhr.responseJSON.data ? xhr.responseJSON.data.message : 'Fehler beim Hinzufügen.';
			$msg.addClass('ak-error').text(m);
		}).always(function () {
			$btn.prop('disabled', false).removeClass('loading');
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
