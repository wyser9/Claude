/* Armatum Konfigurator – Kalibrierung im Produkt-Backend */
(function ($) {
	'use strict';

	var COLORS = { p1: '#0073aa', p2: '#0073aa', anchor: '#d63638', tube_anchor: '#00a32a', bottom: '#e65100',
		slot_muendung_anchor: '#8c5fd6', slot_unten_anchor: '#dba617', slot_seite_anchor: '#dba617', slot_schaft_anchor: '#3c434a', slot_griff_anchor: '#3c434a', muzzle_device_anchor: '#8c5fd6' };
	var LABELS = { p1: '1', p2: '2', anchor: 'A', tube_anchor: 'R', bottom: 'T',
		slot_muendung_anchor: 'M', slot_unten_anchor: 'U', slot_seite_anchor: 'L', slot_schaft_anchor: 'S', slot_griff_anchor: 'G', muzzle_device_anchor: 'O' };

	$(function () {
		var $box = $('.ak-box');
		if (!$box.length) { return; }
		var active = null;

		function img() { return $box.find('.ak-img'); }

		function natural($img) {
			var el = $img[0];
			return {
				w: el.naturalWidth || parseInt($img.data('w'), 10) || 1,
				h: el.naturalHeight || parseInt($img.data('h'), 10) || 1
			};
		}

		function drawMarkers() {
			var $img = img();
			var $canvas = $box.find('.ak-canvas');
			$canvas.find('.ak-marker').remove();
			if (!$img.length) { return; }
			var n = natural($img);
			var k = $img.width() / n.w;
			$box.find('.ak-pt').each(function () {
				var key = $(this).data('pt');
				var val = $(this).val();
				if (!val || val.indexOf(',') < 0) { return; }
				var xy = val.split(',').map(parseFloat);
				$('<span class="ak-marker"></span>')
					.text(LABELS[key])
					.css({ left: xy[0] * k, top: xy[1] * k, background: COLORS[key] })
					.attr('title', key)
					.appendTo($canvas);
			});
		}

		function syncRole() {
			$box.attr('data-role', $box.find('.ak-role').val());
		}

		// Schnittstellen: passende Vorschläge als Knöpfe (Rest unter «weitere»), Klick fügt hinzu oder entfernt.
		var known = $box.data('ifs') || {};
		var KIND_LABEL = {
			waffe: 'Optik-Aufnahme (Schiene oder Footprint)',
			optik: 'Optik-Aufnahme (nur bei «direkt»: Schiene oder Footprint)',
			montage: 'Passt auf (Schiene)',
			gewinde: 'Passt auf (Gewinde)',
			schiene: 'Passt auf (Schiene)',
			schaft: 'Passt auf (Schaftaufnahme)',
			griff: 'Passt auf (Griffaufnahme)'
		};
		var GROUP_KIND = { muendungsaufsatz: 'gewinde', hub: 'gewinde', schalldaempfer: 'gewinde', handgriff: 'schiene', laser: 'schiene', lampe: 'schiene', adapterschiene: 'schiene', schaft: 'schaft', griff: 'griff' };
		function esc(t) { return $('<i>').text(t).html(); }
		function norm(v) { return v.toLowerCase().replace(/\s+/g, ''); }
		function values($in) { return $in.val().split(/\s*[,;]\s*/).map($.trim).filter(Boolean); }
		function fieldKinds($in) {
			var k = $in.data('kind');
			var role = $box.find('.ak-role').val();
			var g = $box.find('[name="ak[gruppe]"]').val();
			if (k === 'auto') {
				if (role === 'waffe' || role === 'optik') { return ['schiene', 'optik']; }
				if (role === 'montage') { return ['schiene']; }
				return GROUP_KIND[g] ? [GROUP_KIND[g]] : [];
			}
			if (k === 'auto-out') { return g === 'adapterschiene' ? ['schiene'] : ['gewinde']; }
			return [k];
		}
		function fieldLabel() {
			var role = $box.find('.ak-role').val();
			var g = $box.find('[name="ak[gruppe]"]').val();
			var key = role === 'zubehoer' ? GROUP_KIND[g] : role;
			$box.find('.ak-if-label').text(KIND_LABEL[key] || 'Schnittstelle(n)');
		}
		function buildChips($in) {
			var kinds = fieldKinds($in);
			var main = '', more = '';
			$.each(known, function (v, kind) {
				var b = '<button type="button" class="ak-chip" data-v="' + esc(v) + '">' + esc(v) + '</button>';
				if (!kinds.length || kinds.indexOf(kind) >= 0) { main += b; } else { more += b; }
			});
			var html = '<span class="ak-chips">' + main +
				(more ? '<button type="button" class="ak-chip-more">weitere …</button><span class="ak-chips-more" hidden>' + more + '</span>' : '') +
				'<span class="ak-chip-warn"></span></span>';
			$in.next('.ak-chips').remove();
			$in.after(html);
			paintChips($in);
		}
		function paintChips($in) {
			var cur = values($in).map(norm);
			$in.next('.ak-chips').find('.ak-chip').each(function () {
				$(this).toggleClass('ak-chip-on', cur.indexOf(String($(this).data('v'))) >= 0);
			});
			var unknown = cur.filter(function (v) { return !Object.prototype.hasOwnProperty.call(known, v); });
			$in.next('.ak-chips').find('.ak-chip-warn').text(unknown.length ? 'Neu: ' + unknown.join(', ') + ' (Schreibweise prüfen)' : '');
		}
		function rebuildAll() {
			fieldLabel();
			$box.find('.ak-if').each(function () { buildChips($(this)); });
		}
		rebuildAll();
		$box.on('change', '.ak-role, [name="ak[gruppe]"]', rebuildAll);
		$box.on('input', '.ak-if', function () { paintChips($(this)); });
		$box.on('click', '.ak-chip-more', function () {
			var $m = $(this).next('.ak-chips-more');
			$m.prop('hidden', !$m.prop('hidden'));
			$(this).text($m.prop('hidden') ? 'weitere …' : 'weniger');
		});
		$box.on('click', '.ak-chip', function () {
			var $in = $(this).closest('.ak-chips').prev('.ak-if');
			var v = String($(this).data('v'));
			var cur = values($in);
			var i = cur.map(norm).indexOf(v);
			if (i >= 0) { cur.splice(i, 1); } else { cur.push(v); }
			$in.val(cur.join(', '));
			paintChips($in);
		});

		$box.on('change', '.ak-role', syncRole);
		syncRole();

		$box.on('click', '.ak-tool', function () {
			active = $(this).data('target');
			$box.find('.ak-tool').removeClass('button-primary');
			$(this).addClass('button-primary');
			$box.find('.ak-canvas').addClass('ak-picking');
		});

		$box.on('click', '.ak-img', function (e) {
			if (!active) { return; }
			var $img = $(this);
			var n = natural($img);
			var rect = this.getBoundingClientRect();
			var x = (e.clientX - rect.left) * n.w / rect.width;
			var y = (e.clientY - rect.top) * n.h / rect.height;
			$box.find('.ak-pt[data-pt="' + active + '"]').val(x.toFixed(1) + ',' + y.toFixed(1));
			active = null;
			$box.find('.ak-tool').removeClass('button-primary');
			$box.find('.ak-canvas').removeClass('ak-picking');
			drawMarkers();
		});

		$box.on('click', '.ak-pick-image', function (e) {
			e.preventDefault();
			var ids = $box.data('gallery') || [];
			var all = $(this).hasClass('ak-pick-all') || !ids.length;
			var frame = wp.media({
				title: all ? 'Vorschaubild aus der Mediathek wählen' : 'Vorschaubild aus den Bildern dieses Produkts wählen',
				button: { text: 'Bild verwenden' },
				multiple: false,
				library: all ? { type: 'image' } : { type: 'image', post__in: ids, orderby: 'post__in', posts_per_page: -1 }
			});
			frame.on('select', function () {
				var a = frame.state().get('selection').first().toJSON();
				$box.find('.ak-image-id').val(a.id);
				$box.find('.ak-canvas').html('<img class="ak-img" alt="" src="' + a.url + '" data-w="' + a.width + '" data-h="' + a.height + '">');
				$box.find('.ak-pt').val('');
				img().on('load', drawMarkers);
			});
			frame.open();
		});

		$box.on('click', '.ak-auto', function () {
			var $btn = $(this).prop('disabled', true);
			var $msg = $box.find('.ak-auto-msg').removeClass('ak-error').text('Bild wird analysiert …');
			var val = function (n) { return $box.find('[name="ak[' + n + ']"]').val() || ''; };
			var on = function (n) { return $box.find('[name="ak[' + n + ']"]').is(':checked') ? 1 : 0; };
			$.post(window.ajaxurl, {
				action: 'ak_auto_preview', nonce: $btn.data('nonce'), id: $btn.data('id'),
				role: val('role'), gruppe: val('gruppe'), direkt: on('direkt'), flip: on('flip'),
				mittelrohr: val('mittelrohr'), laenge: val('laenge'), image_id: val('image_id')
			}).done(function (res) {
				if (!res.success) { $msg.addClass('ak-error').text(res.data.message); return; }
				$.each(['p1', 'p2', 'anchor', 'tube_anchor', 'bottom'], function (_, k) {
					if (res.data[k]) { $box.find('.ak-pt[data-pt="' + k + '"]').val(res.data[k]); }
				});
				$box.find('.ak-ref').val(res.data.ref_mm);
				$box.find('.ak-scale').text('Massstab: ' + Number(res.data.mmPerPx).toFixed(3) + ' mm/px (noch nicht gespeichert)');
				$msg.text('Punkte gesetzt. Bitte im Bild prüfen und das Produkt speichern.');
				drawMarkers();
			}).fail(function (xhr) {
				$msg.addClass('ak-error').text(xhr.responseJSON && xhr.responseJSON.data ? xhr.responseJSON.data.message : 'Fehler bei der Analyse.');
			}).always(function () { $btn.prop('disabled', false); });
		});

		img().on('load', drawMarkers);
		if (img().length && img()[0].complete) { drawMarkers(); }
		$(window).on('resize', drawMarkers);
	});
})(jQuery);
