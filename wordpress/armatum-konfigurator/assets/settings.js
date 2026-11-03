/* Armatum Konfigurator – Sammelfunktion «Automatisch einrichten» */
(function ($) {
	'use strict';
	function esc(s) {
		return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) {
			return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
		});
	}
	$(function () {
		var running = false;
		$('.ak-run').on('click', function () {
			if (running) { return; }
			running = true;
			var dry = $(this).data('dry') ? 1 : 0;
			var overwrite = $('.ak-overwrite').is(':checked') ? 1 : 0;
			var $status = $('.ak-run-status').text('Produkte werden gesucht …');
			var $table = $('.ak-run-table').prop('hidden', false);
			var $body = $table.find('tbody').empty();
			var count = { ok: 0, skip: 0, error: 0 };
			$('.ak-run').prop('disabled', true);
			$.post(window.ajaxurl, { action: 'ak_auto_list', nonce: akSettings.nonce }).done(function (res) {
				var ids = res.success ? res.data.ids : [];
				var i = 0;
				function next() {
					if (i >= ids.length) {
						$status.text((dry ? 'Probelauf fertig: ' : 'Fertig: ') + count.ok + ' eingemessen, ' + count.skip + ' übersprungen, ' + count.error + ' mit Fehler.');
						running = false;
						$('.ak-run').prop('disabled', false);
						return;
					}
					$status.text('Produkt ' + (i + 1) + ' von ' + ids.length + ' …');
					$.post(window.ajaxurl, { action: 'ak_auto_run', nonce: akSettings.nonce, id: ids[i], dry: dry, overwrite: overwrite }).done(function (r) {
						var d = r.success ? r.data : { name: '#' + ids[i], status: 'error', msg: (r.data && r.data.message) || 'Fehler' };
						count[d.status] = (count[d.status] || 0) + 1;
						$body.append('<tr><td><a href="' + esc(d.edit) + '" target="_blank">' + esc(d.name) + '</a></td><td>' + esc(d.role) + '</td><td>' + (d.length ? esc(d.length) + ' mm' : '–') +
							'</td><td class="ak-' + esc(d.status) + '">' + esc(d.msg) + '</td></tr>');
					}).fail(function () {
						count.error++;
						$body.append('<tr><td>#' + esc(ids[i]) + '</td><td></td><td></td><td class="ak-error">Serverfehler</td></tr>');
					}).always(function () { i++; next(); });
				}
				next();
			}).fail(function () {
				$status.text('Fehler beim Laden der Produkte.');
				running = false;
				$('.ak-run').prop('disabled', false);
			});
		});
	});
})(jQuery);
