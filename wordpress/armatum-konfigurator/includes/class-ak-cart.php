<?php
/**
 * Warenkorb: Paket hinzufügen (jede Komponente als eigene Position),
 * Gratis-Montage als eigene Position mit CHF 0.00, Aufräumen bei Entfernen.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class AK_Cart {

	const OPTION_MONTAGE = 'ak_montage_product_id';
	const MONTAGE_SKU    = 'AK-MONTAGE';

	/** true, solange das Plugin selbst Positionen hinzufügt. */
	private static $adding = false;

	public static function init() {
		add_action( 'wc_ajax_ak_add_package', array( __CLASS__, 'ajax_add_package' ) );
		add_filter( 'woocommerce_add_to_cart_validation', array( __CLASS__, 'block_manual_montage' ), 10, 2 );
		add_filter( 'woocommerce_get_item_data', array( __CLASS__, 'item_data' ), 10, 2 );
		add_action( 'woocommerce_before_calculate_totals', array( __CLASS__, 'before_totals' ), 20 );
		add_action( 'woocommerce_cart_item_removed', array( __CLASS__, 'cleanup' ) );
		add_action( 'woocommerce_cart_loaded_from_session', array( __CLASS__, 'cleanup' ) );
		add_filter( 'woocommerce_cart_item_quantity', array( __CLASS__, 'lock_montage_qty' ), 10, 3 );
		add_action( 'woocommerce_checkout_create_order_line_item', array( __CLASS__, 'order_item_meta' ), 10, 3 );
		add_action( 'woocommerce_thankyou', array( __CLASS__, 'ga_purchase' ), 20 );
	}

	/** Legt das versteckte Produkt "Montage" an (Preis 0), falls es fehlt. */
	public static function ensure_montage_product() {
		if ( ! function_exists( 'wc_get_product' ) ) {
			return 0;
		}
		$id = (int) get_option( self::OPTION_MONTAGE );
		if ( $id && wc_get_product( $id ) ) {
			return $id;
		}
		$existing = wc_get_product_id_by_sku( self::MONTAGE_SKU );
		if ( $existing ) {
			update_option( self::OPTION_MONTAGE, $existing );
			return (int) $existing;
		}
		$p = new WC_Product_Simple();
		$p->set_name( 'Montage (gratis im Paket)' );
		$p->set_sku( self::MONTAGE_SKU );
		$p->set_status( 'publish' );
		$p->set_catalog_visibility( 'hidden' );
		$p->set_virtual( true );
		$p->set_regular_price( '0' );
		$p->set_tax_status( 'none' );
		$p->set_short_description( '<p>Fachgerechte Montage von Optik und Zubehör auf die Waffe. Kostenlos beim Kauf eines Pakets aus dem Konfigurator.</p>' );
		$id = $p->save();
		update_option( self::OPTION_MONTAGE, $id );
		return (int) $id;
	}

	public static function montage_id() {
		return (int) get_option( self::OPTION_MONTAGE );
	}

	public static function block_manual_montage( $passed, $product_id ) {
		if ( ! self::$adding && (int) $product_id === self::montage_id() ) {
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

		$montage_id = self::ensure_montage_product();
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
		$lines['service'] = $montage_id;

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
			$price  = 'service' === $role ? 0.0 : (float) wc_get_price_to_display( $p );
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

	/** Montage immer 0 und Menge 1. */
	public static function before_totals( $cart ) {
		$montage_id = self::montage_id();
		foreach ( $cart->get_cart() as $key => $item ) {
			if ( (int) $item['product_id'] === $montage_id ) {
				$item['data']->set_price( 0 );
				if ( 1 !== (int) $item['quantity'] ) {
					$cart->cart_contents[ $key ]['quantity'] = 1;
				}
			}
		}
	}

	/** Entfernt die Montage, wenn die Waffe oder alle Zubehörteile des Pakets fehlen. */
	public static function cleanup() {
		static $running = false;
		$cart = WC()->cart;
		if ( ! $cart || $running ) {
			return;
		}
		$running = true;
		$montage_id = self::montage_id();
		$present    = array();
		foreach ( $cart->get_cart() as $item ) {
			if ( ! empty( $item['ak_group'] ) ) {
				$present[ $item['ak_group'] ][ $item['ak_role'] ] = true;
			}
		}
		foreach ( $cart->get_cart() as $key => $item ) {
			if ( (int) $item['product_id'] !== $montage_id ) {
				continue;
			}
			$g = isset( $item['ak_group'] ) ? $item['ak_group'] : '';
			$others = $g && isset( $present[ $g ] ) ? array_diff( array_keys( $present[ $g ] ), array( 'waffe', 'service' ) ) : array();
			if ( ! $g || empty( $present[ $g ]['waffe'] ) || ! $others ) {
				$cart->remove_cart_item( $key );
			}
		}
		$running = false;
	}

	public static function lock_montage_qty( $html, $key, $item ) {
		if ( (int) $item['product_id'] === self::montage_id() ) {
			return '1';
		}
		return $html;
	}

	public static function order_item_meta( $order_item, $key, $values ) {
		if ( ! empty( $values['ak_group'] ) ) {
			$order_item->add_meta_data( 'Paket', get_the_title( (int) $values['ak_weapon'] ) . ' (' . $values['ak_group'] . ')', true );
			// Maschinenlesbar (verborgen, Unterstrich): für konfigurator_kauf und Auswertungen.
			$order_item->add_meta_data( '_ak_group', (string) $values['ak_group'], true );
			$order_item->add_meta_data( '_ak_role', isset( $values['ak_role'] ) ? (string) $values['ak_role'] : '', true );
			if ( ! empty( $values['ak_erwerb'] ) ) {
				$order_item->add_meta_data( 'Erwerb (Paket)', (string) $values['ak_erwerb'], true );
			}
		}
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
