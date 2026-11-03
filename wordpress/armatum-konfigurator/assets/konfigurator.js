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

	function Konfigurator($root) {
		this.$root = $root;
		this.cfg = $root.data('config');
		this.mountsById = {};
		var self = this;
		$.each(this.cfg.mounts, function (_, m) { self.mountsById[m.id] = m; });
		this.optic = null;
		this.mount = null;
		this.parts = {};
		this.groups = this.cfg.groups || [];
		this.renderLists();
		this.update();
		$(window).on('resize', function () { self.draw(); });
	}

	Konfigurator.prototype.card = function (item, name, extra) {
		return '<label class="ak-card">' +
			'<input type="radio" name="' + name + '" value="' + item.id + '">' +
			'<img src="' + esc(item.thumb) + '" alt="" loading="lazy">' +
			'<span class="ak-card-text"><span class="ak-card-name">' + esc(item.name) + '</span>' +
			'<span class="ak-card-price">' + item.priceHtml + '</span>' +
			'<span class="ak-card-stock">' + esc(item.stock) + (extra ? ' · ' + esc(extra) : '') + '</span>' +
			(item.hinweis ? '<span class="ak-card-hint">' + esc(item.hinweis) + '</span>' : '') + '</span>' +
			'</label>';
	};

	Konfigurator.prototype.noneCard = function (name, label) {
		return '<label class="ak-card ak-card-none"><input type="radio" name="' + name + '" value="0" checked>' +
			'<span></span><span class="ak-card-text"><span class="ak-card-name">' + esc(label) + '</span></span></label>';
	};

	Konfigurator.prototype.renderLists = function () {
		var self = this;
		var html = this.noneCard('ak_optic', 'Keine Optik');
		$.each(this.cfg.optics, function (_, o) {
			html += self.card(o, 'ak_optic', o.direct ? 'direkt auf Schiene' : 'Mittelrohr ' + o.tube + 'mm');
		});
		this.$root.find('.ak-optics').html(html).on('change', 'input', function () {
			var id = parseInt(this.value, 10);
			self.optic = self.cfg.optics.filter(function (o) { return o.id === id; })[0] || null;
			self.mount = null;
			self.renderMounts();
			self.update();
		});
		this.$root.find('.ak-mounts').on('change', 'input', function () {
			self.mount = self.mountsById[parseInt(this.value, 10)] || null;
			self.update();
		});
		var ghtml = '';
		$.each(this.groups, function (_, g) {
			ghtml += '<div class="ak-step" data-group="' + esc(g.key) + '"><h3>' + esc(g.label) + '</h3><div class="ak-list" role="radiogroup">' +
				self.noneCard('ak_g_' + g.key, (g.key === 'schaft' ? 'Keine ' : 'Kein ') + g.label);
			$.each(g.items, function (_, it) { ghtml += self.card(it, 'ak_g_' + g.key); });
			ghtml += '</div></div>';
		});
		this.$root.find('.ak-groups').html(ghtml).on('change', 'input', function () {
			var key = $(this).closest('.ak-step').data('group');
			var id = parseInt(this.value, 10);
			var g = self.groups.filter(function (x) { return x.key === key; })[0];
			self.parts[key] = id && g ? (g.items.filter(function (it) { return it.id === id; })[0] || null) : null;
			self.update();
		});
		this.$root.find('.ak-add').on('click', function () { self.add(); });
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

	Konfigurator.prototype.renderMounts = function () {
		var self = this;
		var $step = this.$root.find('.ak-step-mount');
		if (!this.optic || this.optic.direct || !this.optic.mounts.length) {
			$step.prop('hidden', true);
			return;
		}
		var html = '';
		$.each(this.optic.mounts, function (_, id) {
			if (self.mountsById[id]) { html += self.card(self.mountsById[id], 'ak_mount'); }
		});
		$step.prop('hidden', false).find('.ak-mounts').html(html);
		var $first = $step.find('input').first();
		$first.prop('checked', true);
		this.mount = this.mountsById[parseInt($first.val(), 10)] || null;
	};

	Konfigurator.prototype.layers = function () {
		var self = this;
		var w = this.cfg.weapon;
		var list = [$.extend(place(w, w.anchor, 0, 0), { z: 1 })];
		$.each(this.selectedParts(), function (_, x) {
			var sp = w.slots && w.slots[x.g.slot];
			if (!sp) { return; }
			var at = self.weaponPoint(sp);
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
		var lines = [cfg.weapon];
		if (this.mount && this.optic && !this.optic.direct) { lines.push(this.mount); }
		if (this.optic) { lines.push(this.optic); }
		$.each(this.selectedParts(), function (_, x) { lines.push(x.item); });
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
		var ready = opticOk && lines.length > 1;
		this.$root.find('.ak-add').prop('disabled', !ready);
		this.draw();
	};

	Konfigurator.prototype.add = function () {
		var self = this;
		var $btn = this.$root.find('.ak-add').prop('disabled', true).addClass('loading');
		var $msg = this.$root.find('.ak-msg').removeClass('ak-error').text('');
		var data = {
			weapon: this.cfg.weapon.id,
			optic: this.optic ? this.optic.id : 0,
			mount: this.optic && this.mount && !this.optic.direct ? this.mount.id : 0
		};
		$.each(this.selectedParts(), function (_, x) { data['parts[' + x.g.key + ']'] = x.item.id; });
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
