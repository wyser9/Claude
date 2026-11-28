/* Armatum Konfigurator – Prüfliste: «OK» markiert ein Produkt als geprüft (nochmals klicken = zurücknehmen). */
(function ($) {
	'use strict';
	$(document).on('click', '.ak-review-ok', function () {
		var $card = $(this).closest('.ak-review-card');
		var checked = !$card.hasClass('is-checked');
		var $btn = $(this).prop('disabled', true);
		$.post(window.ajaxurl, { action: 'ak_check', nonce: akReview.nonce, id: $card.data('id'), checked: checked ? 1 : 0 })
			.done(function () {
				$card.toggleClass('is-checked', checked);
				$btn.text(checked ? 'Geprüft ✔' : 'OK');
			})
			.always(function () { $btn.prop('disabled', false); });
	});
})(jQuery);
