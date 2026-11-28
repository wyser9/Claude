<?php
/**
 * Warenkorb: Paket hinzufügen (jede Komponente als eigene Position).
 * Gratis-Montage ab 1.0.12 nicht mehr als eigene Position, sondern als Vermerk
 * «Montage im Paket: gratis» an jeder Paket-Position und als interne Bestellnotiz für die Werkstatt.
 * Alte Positionen des Produkts AK-MONTAGE (bis 1.0.11) werden beim Laden des Warenkorbs entfernt.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class AK_Cart {

	const OPTION_MONTAGE = 'ak_montage_product_id';
	const MONTAGE_SKU    = 'AK-MONTAGE';
	const MONTAGE_KEY    = 'Montage im Paket';
	const MONTAGE_VAL    = 'gratis';
	const NOTE_META      = '_ak_werkstatt_notiz';

	/** true, solange das Plugin selbst Positionen hinzufügt. */
	private static $adding = false;

	public static function init() {
		add_action( 'wc_ajax_ak_add_package', array( __CLASS__, 'ajax_add_package' ) );
		add_filter( 'woocommerce_add_to_cart_validation', array( __CLASS__, 'block_manual_montage' ), 10, 2 );
		add_filter( 'woocommerce_get_item_data', array( __CLASS__, 'item_data' ), 10, 2 );
		add_action( 'woocommerce_cart_loaded_from_session', array( __CLASS__, 'cleanup' ) );
		add_action( 'woocommerce_checkout_create_order_line_item', array( __CLASS__, 'order_item_meta' ), 10, 3 );
		// Interne Werkstatt-Notiz: klassischer Checkout und Block-Checkout (Store API), Doppelschutz über Order-Meta.
		add_action( 'woocommerce_checkout_order_created', array( __CLASS__, 'workshop_note' ) );
		add_action( 'woocommerce_store_api_checkout_order_processed', array( __CLASS__, 'workshop_note' ) );
		add_action( 'woocommerce_thankyou', array( __CLASS__, 'ga_purchase' ), 20 );
	}

	/**
	 * ID des alten Montage-Produkts (bis 1.0.11 als eigene Position, SKU AK-MONTAGE). 0, wenn es keins gibt.
	 * Das Plugin legt das Produkt nicht mehr an und löscht es nicht; es dient nur noch zum Aufräumen alter Warenkörbe.
	 */
	public static function montage_id() {
		static $id = null;
		if ( null === $id ) {
			$id = (int) get_option( self::OPTION_MONTAGE );
			if ( ! $id && function_exists( 'wc_get_product_id_by_sku' ) ) {
				$id = (int) wc_get_product_id_by_sku( self::MONTAGE_SKU );
			}
		}
		return $id;
	}

	/** Ist die Warenkorb-/Bestellposition die alte Montage-Position? */
	private static function is_legacy_montage( $product_id, $role ) {
		$mid = self::montage_id();
		return 'service' === (string) $role || ( $mid && (int) $product_id === $mid );
	}

	/** Das alte Montage-Produkt kann man nicht einzeln in den Warenkorb legen. */
	public static function block_manual_montage( $passed, $product_id ) {
		$mid = self::montage_id();
		if ( ! self::$adding && $mid && (int) $product_id === $mid ) {
			wc_add_notice( 'Die Montage gibt es nur zusammen mit einem Paket aus dem Konfigurator.', 'error' );
			return false;
		}
		return $passed;
	}

	public static function ajax_add_package() {
		if ( ! AK_Data::visible_for_current_user() ) {
			wp_send_json_error( array( 'message' => 'Der Konfigurator ist noch nicht freigeschaltet.' ), 403 );
		}
		$weapon_id = isset( $_POST['weapon'] ) ? absint( $_POST['weapon'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification
		$optic_id  = isset( $_POST['optic'] ) ? absint( $_POST['optic'] ) : 0;   // phpcs:ignore WordPress.Security.NonceVerification
		$mount_id  = isset( $_POST['mount'] ) ? absint( $_POST['mount'] ) : 0;   // phpcs:ignore WordPress.Security.NonceVerification
		$mag_id    = isset( $_POST['mag'] ) ? absint( $_POST['mag'] ) : 0;       // phpcs:ignore WordPress.Security.NonceVerification
		$mag_mount = isset( $_POST['mag_mount'] ) ? absint( $_POST['mag_mount'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification
		$parts     = array();
		$raw_parts = isset( $_POST['parts'] ) && is_array( $_POST['parts'] ) ? wp_unslash( $_POST['parts'] ) : array(); // phpcs:ignore WordPress.Security.NonceVerification
		foreach ( $raw_parts as $group => $pid ) {
			$group = sanitize_key( $group );
			$pid   = absint( $pid );
			if ( $pid ) {
				$parts[ $group ] = $pid;
			}
		}

		$adapters     = array();
		$raw_adapters = isset( $_POST['adapters'] ) && is_array( $_POST['adapters'] ) ? wp_unslash( $_POST['adapters'] ) : array(); // phpcs:ignore WordPress.Security.NonceVerification
		foreach ( $raw_adapters as $group => $pid ) {
			$pid = absint( $pid );
			if ( $pid ) {
				$adapters[ sanitize_key( $group ) ] = $pid;
			}
		}

		$valid = AK_Data::validate_selection( $weapon_id, $optic_id, $mount_id, $parts, $adapters, $mag_id, $mag_mount );
		if ( is_wp_error( $valid ) ) {
			wp_send_json_error( array( 'message' => $valid->get_error_message() ), 400 );
		}

		$package    = substr( md5( uniqid( '', true ) ), 0, 10 );
		// Erwerbs-Zusammenfassung des Pakets (ab 1.0.9): steht im Warenkorb und in der Bestellung bei der Waffe.
		$erwerb     = AK_Erwerb::for_selection( $weapon_id, array_merge( array( $optic_id, $mount_id, $mag_id, $mag_mount ), array_values( $parts ), array_values( $adapters ) ) );
		$erwerb_txt = $erwerb['satz'] . ( $erwerb['abklaerung'] ? ' ' . AK_Erwerb::ABKLAERUNG . ': ' . implode( ' ', $erwerb['abklaerung'] ) : '' );
		$lines      = array( 'waffe' => $weapon_id );
		if ( $mount_id ) {
			$lines['montage'] = $mount_id;
		}
		if ( $optic_id ) {
			$lines['optik'] = $optic_id;
		}
		if ( $mag_mount ) {
			$lines['magnifier_montage'] = $mag_mount;
		}
		if ( $mag_id ) {
			$lines['magnifier'] = $mag_id;
		}
		foreach ( $parts as $part_group => $pid ) {
			$lines[ $part_group ] = $pid;
			if ( ! empty( $adapters[ $part_group ] ) ) {
				$lines[ 'adapter_' . $part_group ] = $adapters[ $part_group ];
			}
		}

		self::$adding = true;
		$added        = array();
		$failed       = false;
		foreach ( $lines as $role => $pid ) {
			$parent = AK_Data::parent_of( $pid );
			$attrs  = array();
			if ( $parent ) {
				$variation = wc_get_product( $pid );
				$attrs     = $variation ? $variation->get_variation_attributes() : array();
			}
			$data = array(
				'ak_group'  => $package,
				'ak_role'   => $role,
				'ak_weapon' => $weapon_id,
			);
			if ( 'waffe' === $role ) {
				$data['ak_erwerb'] = $erwerb_txt;
			}
			$key = WC()->cart->add_to_cart( $parent ? $parent : $pid, 1, $parent ? $pid : 0, $attrs, $data );
			if ( ! $key ) {
				$failed = true;
				break;
			}
			$added[] = $key;
		}
		self::$adding = false;

		if ( $failed ) {
			foreach ( $added as $key ) {
				WC()->cart->remove_cart_item( $key );
			}
			$notices = wc_get_notices( 'error' );
			wc_clear_notices();
			$msg = $notices ? wp_strip_all_tags( $notices[0]['notice'] ) : 'Das Paket konnte nicht in den Warenkorb gelegt werden.';
			wp_send_json_error( array( 'message' => $msg ), 409 );
		}

		wc_clear_notices();
		// Für GA4 (add_to_cart, paket_in_warenkorb): Positionen mit Preis, wie sie im Warenkorb liegen. Ohne Personenbezug.
		$out   = array();
		$value = 0.0;
		foreach ( $lines as $role => $pid ) {
			$p = wc_get_product( $pid );
			if ( ! $p ) {
				continue;
			}
			$price  = (float) wc_get_price_to_display( $p );
			$value += $price;
			$out[]  = array(
				'id'    => (int) $pid,
				'sku'   => (string) $p->get_sku(),
				'name'  => $p->get_name(),
				'price' => $price,
				'role'  => $role,
			);
		}
		wp_send_json_success(
			array(
				'message' => 'Paket liegt im Warenkorb.',
				'count'   => WC()->cart->get_cart_contents_count(),
				'package' => $package,
				'value'   => round( $value, 2 ),
				'lines'   => $out,
				'erwerb'  => $erwerb['satz'],
			)
		);
	}

	public static function item_data( $data, $item ) {
		if ( ! empty( $item['ak_group'] ) && ! empty( $item['ak_weapon'] ) ) {
			$data[] = array(
				'key'   => 'Paket',
				'value' => esc_html( get_the_title( (int) $item['ak_weapon'] ) ),
			);
			if ( self::package_complete( (string) $item['ak_group'] ) ) {
				$data[] = array(
					'key'   => self::MONTAGE_KEY,
					'value' => self::MONTAGE_VAL,
				);
			}
			if ( ! empty( $item['ak_erwerb'] ) ) {
				$data[] = array(
					'key'   => 'Erwerb (Paket)',
					'value' => esc_html( $item['ak_erwerb'] ),
				);
			}
			$hinweis = (string) get_post_meta( (int) $item['product_id'], '_ak_hinweis', true );
			if ( '' !== $hinweis ) {
				$data[] = array(
					'key'   => 'Hinweis',
					'value' => esc_html( $hinweis ),
				);
			}
		}
		return $data;
	}

	/**
	 * Gratis-Montage gilt, solange vom Paket die Waffe und mindestens ein Teil im Warenkorb liegen.
	 * Ohne Warenkorb (z.B. Bestellung im Backend) gilt sie.
	 */
	public static function package_complete( $group ) {
		$cart = function_exists( 'WC' ) && WC() ? WC()->cart : null;
		if ( ! $cart || '' === $group ) {
			return true;
		}
		$waffe = false;
		$teile = 0;
		foreach ( $cart->get_cart() as $item ) {
			if ( empty( $item['ak_group'] ) || (string) $item['ak_group'] !== $group ) {
				continue;
			}
			$role = isset( $item['ak_role'] ) ? (string) $item['ak_role'] : '';
			if ( self::is_legacy_montage( $item['product_id'] ?? 0, $role ) ) {
				continue;
			}
			if ( 'waffe' === $role ) {
				$waffe = true;
			} else {
				$teile++;
			}
		}
		return $waffe && $teile > 0;
	}

	/** Entfernt alte Montage-Positionen (bis 1.0.11) aus Warenkörben, die noch in der Sitzung liegen. */
	public static function cleanup() {
		static $running = false;
		$cart = function_exists( 'WC' ) && WC() ? WC()->cart : null;
		if ( ! $cart || $running ) {
			return;
		}
		$running = true;
		foreach ( $cart->get_cart() as $key => $item ) {
			$role = isset( $item['ak_role'] ) ? (string) $item['ak_role'] : '';
			if ( self::is_legacy_montage( $item['product_id'] ?? 0, $role ) ) {
				$cart->remove_cart_item( $key );
			}
		}
		$running = false;
	}

	public static function order_item_meta( $order_item, $key, $values ) {
		if ( ! empty( $values['ak_group'] ) ) {
			$order_item->add_meta_data( 'Paket', get_the_title( (int) $values['ak_weapon'] ) . ' (' . $values['ak_group'] . ')', true );
			// Maschinenlesbar (verborgen, Unterstrich): für konfigurator_kauf und Auswertungen.
			$order_item->add_meta_data( '_ak_group', (string) $values['ak_group'], true );
			$order_item->add_meta_data( '_ak_role', isset( $values['ak_role'] ) ? (string) $values['ak_role'] : '', true );
			if ( self::package_complete( (string) $values['ak_group'] ) ) {
				$order_item->add_meta_data( self::MONTAGE_KEY, self::MONTAGE_VAL, true );
			}
			if ( ! empty( $values['ak_erwerb'] ) ) {
				$order_item->add_meta_data( 'Erwerb (Paket)', (string) $values['ak_erwerb'], true );
			}
		}
	}

	/**
	 * Interne Bestellnotiz für die Werkstatt (nicht an den Kunden), eine je Paket:
	 * «Konfigurator-Paket: Montage gratis. Waffe: <Name> (Art.-Nr. <SKU>). Montieren: <Teil (SKU)>, …»
	 * Läuft im klassischen und im Block-Checkout. Gleicher Inhalt wird nur einmal notiert
	 * (Block-Checkout kann bei erneutem Zahlversuch nochmals auslösen).
	 */
	public static function workshop_note( $order ) {
		if ( is_numeric( $order ) ) {
			$order = wc_get_order( (int) $order );
		}
		if ( ! $order || ! is_object( $order ) || ! method_exists( $order, 'add_order_note' ) ) {
			return;
		}
		$notes = self::workshop_notes( $order );
		if ( ! $notes ) {
			return;
		}
		$hash = md5( implode( "\n", $notes ) );
		if ( (string) $order->get_meta( self::NOTE_META ) === $hash ) {
			return;
		}
		foreach ( $notes as $text ) {
			$order->add_order_note( $text, 0, false );
		}
		$order->update_meta_data( self::NOTE_META, $hash );
		$order->save();
	}

	/** Notiztexte je Paket aus den Bestellpositionen (Meta _ak_group, _ak_role). */
	public static function workshop_notes( $order ) {
		$packages = array();
		foreach ( $order->get_items() as $item ) {
			$group = (string) $item->get_meta( '_ak_group' );
			if ( '' === $group ) {
				continue;
			}
			$role = (string) $item->get_meta( '_ak_role' );
			$pid  = method_exists( $item, 'get_product_id' ) ? (int) $item->get_product_id() : 0;
			if ( self::is_legacy_montage( $pid, $role ) ) {
				continue;
			}
			$product = method_exists( $item, 'get_product' ) ? $item->get_product() : null;
			$sku     = $product ? (string) $product->get_sku() : '';
			$qty     = (int) $item->get_quantity();
			$label   = wp_strip_all_tags( $item->get_name() );
			$label   = ( $qty > 1 ? $qty . '× ' : '' ) . $label;
			if ( ! isset( $packages[ $group ] ) ) {
				$packages[ $group ] = array( 'waffe' => '', 'teile' => array() );
			}
			if ( 'waffe' === $role && '' === $packages[ $group ]['waffe'] ) {
				$packages[ $group ]['waffe'] = $label . ' (Art.-Nr. ' . ( '' !== $sku ? $sku : '–' ) . ')';
			} else {
				$packages[ $group ]['teile'][] = $label . ( '' !== $sku ? ' (' . $sku . ')' : '' );
			}
		}
		$out = array();
		foreach ( $packages as $group => $p ) {
			if ( '' === $p['waffe'] || ! $p['teile'] ) {
				continue; // Ohne Waffe oder ohne Teile keine Paket-Montage.
			}
			$out[] = 'Konfigurator-Paket: Montage gratis. Waffe: ' . $p['waffe'] . '. Montieren: ' . implode( ', ', $p['teile'] ) . '. (Paket ' . $group . ')';
		}
		return $out;
	}

	/**
	 * GA4 «konfigurator_kauf» auf der Bestätigungsseite (ab 1.0.9), einmal je Bestellung.
	 * Nur Bestellnummer, Summe der Paket-Positionen, Anzahl Pakete und Teile, kein Personenbezug.
	 * Feuert nur, wenn gtag auf der Seite vorhanden ist (GA-Plugin, Einwilligung nach Snippet 52).
	 */
	public static function ga_purchase( $order_id ) {
		$order = $order_id ? wc_get_order( $order_id ) : false;
		if ( ! $order || $order->get_meta( '_ak_ga_gesendet' ) ) {
			return;
		}
		$packages = array();
		$parts    = 0;
		$value    = 0.0;
		foreach ( $order->get_items() as $item ) {
			$group = (string) $item->get_meta( '_ak_group' );
			if ( '' === $group ) {
				continue;
			}
			$packages[ $group ] = true;
			$value             += (float) $item->get_total() + (float) $item->get_total_tax();
			if ( 'service' !== (string) $item->get_meta( '_ak_role' ) ) {
				$parts++;
			}
		}
		if ( ! $packages ) {
			return;
		}
		$order->update_meta_data( '_ak_ga_gesendet', gmdate( 'c' ) );
		$order->save();
		$params = array(
			'transaction_id' => (string) $order->get_order_number(),
			'value'          => round( $value, 2 ),
			'currency'       => $order->get_currency(),
			'pakete'         => count( $packages ),
			'anzahl_teile'   => $parts,
		);
		echo '<script>if(typeof window.gtag==="function"){window.gtag("event","konfigurator_kauf",' . wp_json_encode( $params ) . ');}</script>';
	}
}
