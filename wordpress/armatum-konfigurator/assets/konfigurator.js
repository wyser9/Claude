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
		this.rails = {};
		this.optic = null;
		this.mount = null;
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

	Konfigurator.prototype.step = function (key, title, inner, open) {
		return '<details class="ak-step" data-group="' + esc(key) + '"' + (open ? ' open' : '') + '>' +
			'<summary><span class="ak-step-title">' + esc(title) + '</span><span class="ak-step-choice"></span></summary>' +
			'<div class="ak-step-body">' + inner + '</div></details>';
	};

	Konfigurator.prototype.renderSteps = function () {
		var self = this;
		var html = '';
		if (this.cfg.optics.length) {
			var list = this.noneCard('ak_optic', NONE.optik);
			$.each(this.cfg.optics, function (_, o) {
				list += self.card(o, 'ak_optic', o.direct ? 'direkt auf Schiene' : 'Mittelrohr ' + o.tube + 'mm');
			});
			html += this.step('optik', 'Optik', this.filterBar(this.cfg.optics) + '<div class="ak-list" role="radiogroup">' + list + '</div>' +
				'<div class="ak-step-mount" hidden><h4>Montage</h4><div class="ak-list ak-mounts" role="radiogroup"></div></div>', true);
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
				text = self.optic ? self.optic.name + (self.mount && !self.optic.direct ? ' + ' + self.mount.name : '') : NONE.optik;
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
		if (!this.optic || this.optic.direct || !this.optic.mounts.length) {
			$step.prop('hidden', true).find('.ak-mounts').empty();
			return;
		}
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
			list.push($.extend(place(x.item, x.item.anchor, at[0], at[1]), { z: x.g.layer }));
		});
		if (this.optic && this.optic.direct) {
			list.push($.extend(place(this.optic, this.optic.anchor, 0, 0), { z: 4 }));
		} else if (this.optic && this.mount) {
			var m = this.mount;
			var a = pt(m, m.anchor);
			var t = pt(m, m.tubeAnchor);
			list.push($.extend(place(this.optic, this.optic.anchor, (t[0] - a[0]) * m.mmPerPx, (t[1] - a[1]) * m.mmPerPx), { z: 4 }));
			list.push($.extend(place(m, m.anchor, 0, 0), { z: 5 }));
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
			html += '<img class="ak-layer' + (l.item.flip ? ' ak-flip' : '') + (l.item.cutout ? ' ak-cutout' : '') + '" alt="' + esc(l.item.name) + '" src="' + esc(l.item.img) + '" style="' +
				'left:' + ((l.left - minX) * k).toFixed(1) + 'px;top:' + ((l.top - minY) * k).toFixed(1) + 'px;' +
				'width:' + (l.w * k).toFixed(1) + 'px;z-index:' + (l.z || (i + 1)) + '">';
		});
		$stage.css('height', ((maxY - minY) * k).toFixed(0) + 'px').html(html);
	};

	Konfigurator.prototype.update = function () {
		var cfg = this.cfg;
		this.filterMuzzle();
		this.updateChoices();
		var lines = [cfg.weapon];
		if (this.mount && this.optic && !this.optic.direct) { lines.push(this.mount); }
		if (this.optic) { lines.push(this.optic); }
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
		var opticOk = !this.optic || this.optic.direct || this.mount;
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
			mount: this.optic && this.mount && !this.optic.direct ? this.mount.id : 0
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

	$(function () {
		$('.ak-konfigurator').each(function () { new Konfigurator($(this)); });
	});
})(jQuery);
