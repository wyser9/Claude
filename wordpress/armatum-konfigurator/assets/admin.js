/* Armatum Konfigurator – Kalibrierung im Produkt-Backend */
(function ($) {
	'use strict';

	var COLORS = { p1: '#0073aa', p2: '#0073aa', anchor: '#d63638', tube_anchor: '#00a32a',
		slot_muendung_anchor: '#8c5fd6', slot_unten_anchor: '#dba617', slot_seite_anchor: '#dba617', slot_schaft_anchor: '#3c434a', slot_griff_anchor: '#3c434a' };
	var LABELS = { p1: '1', p2: '2', anchor: 'A', tube_anchor: 'R',
		slot_muendung_anchor: 'M', slot_unten_anchor: 'U', slot_seite_anchor: 'L', slot_schaft_anchor: 'S', slot_griff_anchor: 'G' };

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
			var frame = wp.media({ title: 'Vorschaubild wählen', multiple: false, library: { type: 'image' } });
			frame.on('select', function () {
				var a = frame.state().get('selection').first().toJSON();
				$box.find('.ak-image-id').val(a.id);
				$box.find('.ak-canvas').html('<img class="ak-img" alt="" src="' + a.url + '" data-w="' + a.width + '" data-h="' + a.height + '">');
				$box.find('.ak-pt').val('');
				img().on('load', drawMarkers);
			});
			frame.open();
		});

		img().on('load', drawMarkers);
		if (img().length && img()[0].complete) { drawMarkers(); }
		$(window).on('resize', drawMarkers);
	});
})(jQuery);
