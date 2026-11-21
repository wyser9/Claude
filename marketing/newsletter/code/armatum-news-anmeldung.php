// Armatum News: Anmeldung an der Kasse und per Shortcode [armatum_newsletter], Double-Opt-in via FluentCRM (Liste 1)
if ( ! defined( 'ABSPATH' ) ) { return; }

function armatum_nl_add( $email, $first_name = '', $last_name = '' ) {
	if ( ! function_exists( 'FluentCrmApi' ) || ! is_email( $email ) ) { return false; }
	$api      = FluentCrmApi( 'contacts' );
	$existing = $api->getContact( $email );
	if ( $existing && $existing->status === 'subscribed' ) {
		$existing->attachLists( array( 1 ) );
		return 'subscribed';
	}
	$contact = $api->createOrUpdate( array(
		'email'      => sanitize_email( $email ),
		'first_name' => sanitize_text_field( $first_name ),
		'last_name'  => sanitize_text_field( $last_name ),
		'status'     => 'pending',
		'lists'      => array( 1 ),
	), true );
	if ( $contact && $contact->status === 'pending' ) {
		$contact->sendDoubleOptinEmail();
		return 'pending';
	}
	return false;
}

// Kasse: Haekchen vor dem Bestellknopf (nicht vorausgewaehlt)
add_action( 'woocommerce_review_order_before_submit', function () {
	echo '<p class="form-row armatum-nl-checkout" style="margin:0 0 1em;"><label class="woocommerce-form__label woocommerce-form__label-for-checkbox checkbox">'
		. '<input type="checkbox" class="woocommerce-form__input woocommerce-form__input-checkbox input-checkbox" name="armatum_newsletter" value="1"> '
		. '<span>Ja, ich m&ouml;chte Armatum News erhalten: einmal im Monat Neuheiten, Occasionen und Aktionen. Abmeldung jederzeit m&ouml;glich.</span>'
		. '</label></p>';
} );

add_action( 'woocommerce_checkout_order_processed', function ( $order_id, $posted, $order ) {
	if ( empty( $_POST['armatum_newsletter'] ) || ! $order ) { return; }
	$order->update_meta_data( '_armatum_newsletter', 'yes' );
	$order->save();
	armatum_nl_add( $order->get_billing_email(), $order->get_billing_first_name(), $order->get_billing_last_name() );
}, 20, 3 );

// Shortcode-Formular
add_shortcode( 'armatum_newsletter', function ( $atts ) {
	$atts  = shortcode_atts( array( 'titel' => '' ), $atts );
	$state = isset( $_GET['nl'] ) ? sanitize_key( $_GET['nl'] ) : '';
	$out   = '<div class="armatum-nl">';
	if ( $atts['titel'] !== '' ) { $out .= '<h3>' . esc_html( $atts['titel'] ) . '</h3>'; }
	if ( $state === 'ok' ) {
		$out .= '<p><strong>Fast geschafft:</strong> Bitte best&auml;tigen Sie die Anmeldung &uuml;ber den Link in der E-Mail, die wir Ihnen eben geschickt haben.</p>';
	} elseif ( $state === 'schon' ) {
		$out .= '<p>Sie sind bereits angemeldet. Vielen Dank!</p>';
	} elseif ( $state === 'fehler' ) {
		$out .= '<p>Bitte geben Sie eine g&uuml;ltige E-Mail-Adresse ein.</p>';
	}
	$out .= '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" class="armatum-nl-form" style="display:flex;flex-wrap:wrap;gap:8px;align-items:center;">'
		. '<input type="hidden" name="action" value="armatum_nl">'
		. '<input type="hidden" name="back" value="' . esc_attr( get_permalink() ) . '">'
		. '<input type="text" name="firma_web" value="" tabindex="-1" autocomplete="off" style="position:absolute;left:-9999px;" aria-hidden="true">'
		. '<input type="hidden" name="nl_chk" value="">'
		. '<input type="text" name="vorname" placeholder="Vorname" style="flex:1 1 140px;min-width:0;">'
		. '<input type="email" name="email" placeholder="E-Mail-Adresse" required style="flex:2 1 200px;min-width:0;">'
		. '<button type="submit" class="button">Anmelden</button>'
		. '</form>'
		. '<script>setTimeout(function(){document.querySelectorAll(\'input[name="nl_chk"]\').forEach(function(e){e.value=\'arm\'+\'atum-\'+\'ok\';});},2000);</script>'
		. '<p style="font-size:0.85em;opacity:0.8;margin-top:6px;">Einmal im Monat Neuheiten, Occasionen, Werkstatt und Wiederladen. Abmeldung jederzeit mit einem Klick. <a href="' . esc_url( get_privacy_policy_url() ) . '">Datenschutz</a></p>'
		. '</div>';
	return $out;
} );

function armatum_nl_handle() {
	$back = isset( $_POST['back'] ) ? esc_url_raw( wp_unslash( $_POST['back'] ) ) : home_url( '/' );
	$back = wp_validate_redirect( $back, home_url( '/' ) );
	if ( ! empty( $_POST['firma_web'] ) ) { wp_safe_redirect( add_query_arg( 'nl', 'ok', $back ) ); exit; }
	// Bot-Schutz (seit 21.11.2026): Feld wird erst 2 s nach dem Laden per JavaScript gesetzt; Tageslimit 30 Web-Anmeldungen
	if ( ! isset( $_POST['nl_chk'] ) || 'armatum-ok' !== $_POST['nl_chk'] ) { wp_safe_redirect( add_query_arg( 'nl', 'ok', $back ) ); exit; }
	$day_key = 'armatum_nl_day_' . gmdate( 'Ymd' );
	$day_n   = (int) get_transient( $day_key );
	if ( $day_n >= 30 ) { wp_safe_redirect( add_query_arg( 'nl', 'ok', $back ) ); exit; }
	set_transient( $day_key, $day_n + 1, DAY_IN_SECONDS );
	$ip  = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( $_SERVER['REMOTE_ADDR'] ) : '';
	$key = 'armatum_nl_' . md5( $ip );
	$n   = (int) get_transient( $key );
	if ( $n >= 5 ) { wp_safe_redirect( add_query_arg( 'nl', 'ok', $back ) ); exit; }
	set_transient( $key, $n + 1, HOUR_IN_SECONDS );
	$email = isset( $_POST['email'] ) ? sanitize_email( wp_unslash( $_POST['email'] ) ) : '';
	$name  = isset( $_POST['vorname'] ) ? sanitize_text_field( wp_unslash( $_POST['vorname'] ) ) : '';
	if ( ! is_email( $email ) ) { wp_safe_redirect( add_query_arg( 'nl', 'fehler', $back ) . '#newsletter' ); exit; }
	$r = armatum_nl_add( $email, $name );
	wp_safe_redirect( add_query_arg( 'nl', $r === 'subscribed' ? 'schon' : 'ok', $back ) . '#newsletter' );
	exit;
}
add_action( 'admin_post_nopriv_armatum_nl', 'armatum_nl_handle' );
add_action( 'admin_post_armatum_nl', 'armatum_nl_handle' );

// Block-Kasse (armatum.ch nutzt den Checkout-Block): Haekchen als Zusatzfeld, nicht vorausgewaehlt
add_action( 'woocommerce_init', function () {
	if ( ! function_exists( 'woocommerce_register_additional_checkout_field' ) ) { return; }
	woocommerce_register_additional_checkout_field( array(
		'id'       => 'armatum/newsletter',
		'label'    => 'Ja, ich möchte Armatum News erhalten: einmal im Monat Neuheiten, Occasionen und Aktionen. Abmeldung jederzeit möglich.',
		'location' => 'order',
		'type'     => 'checkbox',
	) );
} );

add_action( 'woocommerce_store_api_checkout_order_processed', function ( $order ) {
	if ( ! $order ) { return; }
	$v = $order->get_meta( '_wc_other/armatum/newsletter' );
	if ( ! filter_var( $v, FILTER_VALIDATE_BOOLEAN ) ) { return; }
	$order->update_meta_data( '_armatum_newsletter', 'yes' );
	$order->save();
	armatum_nl_add( $order->get_billing_email(), $order->get_billing_first_name(), $order->get_billing_last_name() );
} );
