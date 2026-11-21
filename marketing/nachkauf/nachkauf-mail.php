<?php
/**
 * Armatum Nachkauf-Mail Munition / Wiederlade-Verbrauchsmaterial.
 * - Bei Bestellstatus «Abgeschlossen» mit Munition (Kat. 209 + Unterkat.), Pulver (2066),
 *   Zündhütchen (2320) oder Geschossen (3019) wird eine Mail in 45 Tagen geplant (Action Scheduler).
 * - Gesendet wird nur, wenn die E-Mail in FluentCRM «angemeldet» ist (Armatum News) und der Kunde
 *   seither nicht wieder solche Artikel bestellt hat.
 * - Mail per Du, mit den gekauften Artikeln und «Nochmals in den Warenkorb»-Links.
 */
if ( ! defined( 'ARMATUM_NK_DAYS' ) ) { define( 'ARMATUM_NK_DAYS', 45 ); }

function armatum_nk_cats() {
	static $cats = null;
	if ( null === $cats ) {
		$cats = array_merge( array( 209, 2066, 2320, 3019 ), array_map( 'intval', (array) get_term_children( 209, 'product_cat' ) ) );
	}
	return $cats;
}

function armatum_nk_items( $order ) {
	$out = array();
	foreach ( $order->get_items() as $item ) {
		$pid = (int) $item->get_product_id();
		if ( ! $pid || ! has_term( armatum_nk_cats(), 'product_cat', $pid ) ) { continue; }
		$out[] = $item;
	}
	return $out;
}

function armatum_nk_schedule( $order_id ) {
	$order = wc_get_order( $order_id );
	if ( ! $order || $order->get_meta( '_armatum_nk_scheduled' ) || ! armatum_nk_items( $order ) ) { return; }
	$base = $order->get_date_completed() ? $order->get_date_completed()->getTimestamp() : time();
	$when = max( time() + 3600, $base + ARMATUM_NK_DAYS * DAY_IN_SECONDS );
	as_schedule_single_action( $when, 'armatum_nk_send', array( (int) $order_id ), 'armatum' );
	$order->update_meta_data( '_armatum_nk_scheduled', gmdate( 'Y-m-d H:i', $when ) );
	$order->save();
}
add_action( 'woocommerce_order_status_completed', 'armatum_nk_schedule', 30 );

function armatum_nk_subscriber( $email ) {
	if ( ! class_exists( '\FluentCrm\App\Models\Subscriber' ) ) { return null; }
	$s = \FluentCrm\App\Models\Subscriber::where( 'email', $email )->first();
	return ( $s && 'subscribed' === $s->status ) ? $s : null;
}

function armatum_nk_reordered( $order ) {
	$email = $order->get_billing_email();
	$newer = wc_get_orders( array(
		'billing_email' => $email,
		'date_created'  => '>' . $order->get_date_created()->getTimestamp(),
		'status'        => array( 'processing', 'completed', 'on-hold' ),
		'limit'         => 20,
	) );
	foreach ( $newer as $o ) {
		if ( $o->get_id() !== $order->get_id() && armatum_nk_items( $o ) ) { return true; }
	}
	return false;
}

function armatum_nk_unsub_url( $subscriber ) {
	if ( $subscriber && function_exists( 'fluentCrmGetContactManagedHash' ) ) {
		return add_query_arg( array( 'fluentcrm' => 1, 'route' => 'unsubscribe', 'secure_hash' => fluentCrmGetContactManagedHash( $subscriber->id ) ), site_url( '/' ) );
	}
	return 'mailto:info@armatum.ch?subject=Abmelden';
}

function armatum_nk_html( $order, $subscriber ) {
	$rows = '';
	foreach ( armatum_nk_items( $order ) as $item ) {
		$p = $item->get_product();
		if ( ! $p ) { continue; }
		$img  = wp_get_attachment_image_url( $p->get_image_id(), 'thumbnail' );
		$link = $p->get_permalink();
		$buy  = ( $p->is_purchasable() && $p->is_in_stock() ) ? add_query_arg( array( 'add-to-cart' => $p->get_id(), 'quantity' => (int) $item->get_quantity() ), wc_get_cart_url() ) : $link;
		$rows .= '<tr>'
			. '<td style="padding:10px 0;width:90px;vertical-align:top;">' . ( $img ? '<a href="' . esc_url( $link ) . '"><img src="' . esc_url( $img ) . '" width="80" style="width:80px;height:auto;border:1px solid #eee;"></a>' : '' ) . '</td>'
			. '<td style="padding:10px 0 10px 12px;vertical-align:top;"><a href="' . esc_url( $link ) . '" style="color:#333;text-decoration:none;font-weight:bold;">' . esc_html( $p->get_name() ) . '</a><br>'
			. '<span style="color:#777;font-size:13px;">Letztes Mal: ' . (int) $item->get_quantity() . ' Stk. · heute ' . wp_strip_all_tags( wc_price( wc_get_price_to_display( $p ) ) ) . ( $p->is_in_stock() ? '' : ' · zurzeit nicht an Lager' ) . '</span><br>'
			. '<a href="' . esc_url( $buy ) . '" style="display:inline-block;margin-top:8px;background:#4e5743;color:#fff;text-decoration:none;padding:7px 14px;border-radius:4px;font-size:14px;">Nochmals in den Warenkorb</a>'
			. '</td></tr>';
	}
	$name = $order->get_billing_first_name();
	return '<p>Hallo ' . esc_html( $name ) . '</p>'
		. '<p>Vor ein paar Wochen hast du bei uns Munition bzw. Wiederlade-Material bestellt. Wird der Vorrat langsam knapp? Mit einem Klick liegt die gleiche Sorte wieder im Warenkorb:</p>'
		. '<table role="presentation" width="100%" cellpadding="0" cellspacing="0">' . $rows . '</table>'
		. '<p>Fragen zu Sorte oder Lieferzeit? Antworte einfach auf diese Mail.</p>'
		. '<p>Gut Schuss<br>Pascal<br>Armatum GmbH · Färchstrasse 6b · CH-4629 Fulenbach</p>'
		. '<p style="font-size:12px;color:#999;">Du bekommst diese Mail, weil du Armatum News abonniert hast. <a href="' . esc_url( armatum_nk_unsub_url( $subscriber ) ) . '" style="color:#999;">Abmelden</a></p>';
}

function armatum_nk_send( $order_id, $to_override = '' ) {
	$order = wc_get_order( $order_id );
	if ( ! $order ) { return; }
	$subscriber = armatum_nk_subscriber( $order->get_billing_email() );
	if ( ! $to_override ) {
		if ( $order->get_meta( '_armatum_nk_sent' ) || in_array( $order->get_status(), array( 'cancelled', 'refunded', 'failed' ), true ) ) { return; }
		if ( ! $subscriber ) { $order->add_order_note( 'Nachkauf-Mail nicht gesendet: nicht für Armatum News angemeldet.' ); return; }
		if ( armatum_nk_reordered( $order ) ) { $order->add_order_note( 'Nachkauf-Mail nicht gesendet: hat seither wieder bestellt.' ); return; }
	}
	$mailer  = WC()->mailer();
	$subject = 'Wird der Vorrat knapp? Deine Munition von Armatum';
	$html    = $mailer->wrap_message( 'Nachschub gefällig?', armatum_nk_html( $order, $subscriber ) );
	$mailer->send( $to_override ? $to_override : $order->get_billing_email(), $subject, $html, array( 'Content-Type: text/html; charset=UTF-8' ) );
	if ( ! $to_override ) {
		$order->update_meta_data( '_armatum_nk_sent', current_time( 'mysql' ) );
		$order->add_order_note( 'Nachkauf-Mail gesendet.' );
		$order->save();
	}
}
add_action( 'armatum_nk_send', 'armatum_nk_send', 10, 1 );
