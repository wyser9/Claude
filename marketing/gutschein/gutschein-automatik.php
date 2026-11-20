<?php
/**
 * Armatum Geschenkgutschein: Code automatisch erstellen und per E-Mail senden.
 * - Bei Zahlungseingang (Status «In Bearbeitung» oder «Abgeschlossen») wird pro gekauftem
 *   Gutschein (Produkt 33960) ein WooCommerce-Gutscheincode angelegt (fester Betrag, 2 Jahre gültig).
 * - Der Käufer bekommt eine E-Mail mit Gutschein zum Ausdrucken, info@armatum.ch eine Kopie.
 * - Restguthaben: Wird ein Gutschein eingelöst, sinkt sein Betrag um den eingelösten Wert.
 *   Bei 0 läuft er ab.
 */
if ( ! defined( 'ARMATUM_GS_PRODUCT' ) ) { define( 'ARMATUM_GS_PRODUCT', 33960 ); }

function armatum_gs_code() {
	$chars = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
	do {
		$c = 'ARM-';
		for ( $i = 0; $i < 8; $i++ ) { $c .= $chars[ random_int( 0, strlen( $chars ) - 1 ) ]; if ( 3 === $i ) { $c .= '-'; } }
	} while ( wc_get_coupon_id_by_code( $c ) );
	return $c;
}

function armatum_gs_issue( $order_id ) {
	$order = wc_get_order( $order_id );
	if ( ! $order || $order->get_meta( '_armatum_gs_done' ) ) { return; }
	$issued = array();
	foreach ( $order->get_items() as $item ) {
		if ( (int) $item->get_product_id() !== ARMATUM_GS_PRODUCT ) { continue; }
		$qty = max( 1, (int) $item->get_quantity() );
		$value = round( ( (float) $item->get_subtotal() + (float) $item->get_subtotal_tax() ) / $qty, 2 );
		if ( $value <= 0 ) { continue; }
		for ( $n = 0; $n < $qty; $n++ ) {
			$code = armatum_gs_code();
			$expires = strtotime( '+2 years', current_time( 'timestamp' ) );
			$coupon = new WC_Coupon();
			$coupon->set_code( $code );
			$coupon->set_discount_type( 'fixed_cart' );
			$coupon->set_amount( $value );
			$coupon->set_date_expires( $expires );
			$coupon->set_individual_use( false );
			$coupon->set_excluded_product_ids( array( ARMATUM_GS_PRODUCT ) );
			$coupon->set_description( sprintf( 'Geschenkgutschein CHF %s aus Bestellung #%s', number_format( $value, 2, '.', "'" ), $order->get_order_number() ) );
			$coupon->update_meta_data( '_armatum_gutschein', 1 );
			$coupon->update_meta_data( '_armatum_gs_initial', $value );
			$coupon->update_meta_data( '_armatum_gs_order', $order_id );
			$coupon->save();
			$issued[] = array( 'code' => $code, 'value' => $value, 'expires' => $expires );
		}
	}
	if ( ! $issued ) { return; }
	$order->update_meta_data( '_armatum_gs_done', wp_json_encode( $issued ) );
	$order->add_order_note( 'Gutschein-Codes erstellt und per E-Mail gesendet: ' . implode( ', ', wp_list_pluck( $issued, 'code' ) ) );
	$order->save();
	armatum_gs_mail( $order, $issued );
}
add_action( 'woocommerce_order_status_processing', 'armatum_gs_issue', 20 );
add_action( 'woocommerce_order_status_completed', 'armatum_gs_issue', 20 );

function armatum_gs_mail( $order, $issued ) {
	$logo = 'https://armatum.ch/wp-content/uploads/2024/02/Armatum-Logo-schwarz-1-e1755603785390.png';
	$cards = '';
	foreach ( $issued as $g ) {
		$cards .= '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="margin:0 0 24px;border:2px solid #4e5743;border-radius:12px;">'
			. '<tr><td style="background:#4e5743;color:#fff;padding:14px 20px;border-radius:10px 10px 0 0;font-size:13px;letter-spacing:2px;">ARMATUM GESCHENKGUTSCHEIN</td></tr>'
			. '<tr><td style="padding:22px 20px;text-align:center;">'
			. '<img src="' . esc_url( $logo ) . '" alt="Armatum" width="160" style="width:160px;height:auto;"><br><br>'
			. '<div style="font-size:34px;font-weight:bold;color:#4e5743;">CHF ' . number_format( $g['value'], 2, '.', "'" ) . '</div>'
			. '<div style="margin:16px 0 6px;font-size:13px;color:#777;">Gutscheincode</div>'
			. '<div style="display:inline-block;font-family:Consolas,monospace;font-size:24px;letter-spacing:2px;border:1px dashed #b89b5e;padding:8px 16px;">' . esc_html( $g['code'] ) . '</div>'
			. '<div style="margin-top:16px;font-size:13px;color:#555;">Gültig bis ' . esc_html( date_i18n( 'd.m.Y', $g['expires'] ) ) . ' · einlösbar auf armatum.ch und im Laden in Fulenbach</div>'
			. '</td></tr></table>';
	}
	$body = '<p>Hallo ' . esc_html( $order->get_billing_first_name() ) . '</p>'
		. '<p>Vielen Dank für Ihren Einkauf. Hier ist Ihr Armatum-Geschenkgutschein. Sie können diese E-Mail ausdrucken oder weiterleiten.</p>'
		. $cards
		. '<p><strong>So wird der Gutschein eingelöst:</strong><br>Online auf armatum.ch den Code im Warenkorb oder an der Kasse eingeben. Im Laden in Fulenbach einfach den Code zeigen.</p>'
		. '<p>Wird nicht der ganze Betrag gebraucht, bleibt das Restguthaben auf dem Code. Keine Barauszahlung.</p>'
		. '<p>Freundliche Grüsse<br>Pascal<br>Armatum GmbH · Färchstrasse 6b · CH-4629 Fulenbach</p>';
	$mailer  = WC()->mailer();
	$subject = 'Ihr Armatum-Geschenkgutschein';
	$html    = $mailer->wrap_message( $subject, $body );
	$headers = array( 'Content-Type: text/html; charset=UTF-8', 'Bcc: info@armatum.ch' );
	$mailer->send( $order->get_billing_email(), $subject, $html, $headers );
}

/* Restguthaben: eingelösten Betrag abziehen */
function armatum_gs_redeem( $order_id ) {
	$order = wc_get_order( $order_id );
	if ( ! $order || $order->get_meta( '_armatum_gs_redeemed' ) ) { return; }
	$done = array();
	$log  = array();
	foreach ( $order->get_items( 'coupon' ) as $ci ) {
		$cid = wc_get_coupon_id_by_code( $ci->get_code() );
		if ( ! $cid ) { continue; }
		$coupon = new WC_Coupon( $cid );
		if ( ! $coupon->get_meta( '_armatum_gutschein' ) ) { continue; }
		$used = round( (float) $ci->get_discount() + (float) $ci->get_discount_tax(), 2 );
		$rest = max( 0, round( (float) $coupon->get_amount() - $used, 2 ) );
		$coupon->set_amount( $rest );
		if ( $rest <= 0 ) { $coupon->set_date_expires( current_time( 'timestamp' ) - 60 ); }
		$coupon->save();
		$log[ $cid ] = $used;
		$done[] = $ci->get_code() . ': CHF ' . number_format( $used, 2, '.', "'" ) . ' eingelöst, Rest CHF ' . number_format( $rest, 2, '.', "'" );
	}
	if ( $done ) {
		$order->update_meta_data( '_armatum_gs_redeemed', wp_json_encode( $log ) );
		$order->add_order_note( 'Geschenkgutschein: ' . implode( '; ', $done ) );
		$order->save();
	}
}
add_action( 'woocommerce_order_status_processing', 'armatum_gs_redeem', 10 );
add_action( 'woocommerce_order_status_completed', 'armatum_gs_redeem', 10 );
add_action( 'woocommerce_order_status_on-hold', 'armatum_gs_redeem', 10 );

/* Storno: eingelösten Betrag wieder gutschreiben */
function armatum_gs_restore( $order_id ) {
	$order = wc_get_order( $order_id );
	if ( ! $order ) { return; }
	$log = json_decode( (string) $order->get_meta( '_armatum_gs_redeemed' ), true );
	if ( ! is_array( $log ) || ! $log ) { return; }
	foreach ( $log as $cid => $used ) {
		$coupon = new WC_Coupon( (int) $cid );
		if ( ! $coupon->get_id() ) { continue; }
		$coupon->set_amount( round( (float) $coupon->get_amount() + (float) $used, 2 ) );
		$initial_exp = strtotime( '+2 years', strtotime( get_post_field( 'post_date', $coupon->get_id() ) ) );
		if ( $coupon->get_date_expires() && $coupon->get_date_expires()->getTimestamp() < time() && $initial_exp > time() ) {
			$coupon->set_date_expires( $initial_exp );
		}
		$coupon->save();
	}
	$order->delete_meta_data( '_armatum_gs_redeemed' );
	$order->add_order_note( 'Geschenkgutschein: eingelöster Betrag wieder gutgeschrieben (Storno).' );
	$order->save();
}
add_action( 'woocommerce_order_status_cancelled', 'armatum_gs_restore', 10 );
add_action( 'woocommerce_order_status_refunded', 'armatum_gs_restore', 10 );
add_action( 'woocommerce_order_status_failed', 'armatum_gs_restore', 10 );
