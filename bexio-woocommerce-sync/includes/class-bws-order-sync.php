<?php
defined( 'ABSPATH' ) || exit;

/**
 * Überträgt WooCommerce-Bestellungen als Aufträge (kb_order) nach bexio.
 *
 * Auslöser: Statuswechsel auf einen der konfigurierten Status (Standard: "In Bearbeitung" und
 * "Abgeschlossen"). Jede Bestellung wird genau einmal übertragen (Meta "_bws_order_id").
 */
class BWS_Order_Sync {

	const META_ORDER_ID  = '_bws_order_id';
	const META_ORDER_NR  = '_bws_order_nr';
	const META_ERROR     = '_bws_last_error';
	const META_CONTACT   = '_bws_contact_id';

	public static function init() {
		add_action( 'woocommerce_order_status_changed', array( __CLASS__, 'on_status_changed' ), 20, 3 );
		add_action( 'bws_sync_order', array( __CLASS__, 'run_job' ), 10, 1 );
	}

	public static function on_status_changed( $order_id, $old_status, $new_status ) {
		if ( ! BWS_Settings::enabled( 'sync_orders' ) || ! BWS_Settings::get( 'api_token' ) ) {
			return;
		}
		if ( ! in_array( $new_status, (array) BWS_Settings::get( 'order_statuses' ), true ) ) {
			return;
		}
		self::enqueue( (int) $order_id );
	}

	/**
	 * Plant die Übertragung im Hintergrund ein.
	 *
	 * @param int $order_id Bestell-ID.
	 */
	public static function enqueue( $order_id ) {
		$order = wc_get_order( $order_id );
		if ( ! $order || $order->get_meta( self::META_ORDER_ID ) ) {
			return;
		}
		if ( ! function_exists( 'as_enqueue_async_action' ) ) {
			self::run_job( $order_id );
			return;
		}
		if ( ! as_has_scheduled_action( 'bws_sync_order', array( $order_id ), BWS_AS_GROUP ) ) {
			as_enqueue_async_action( 'bws_sync_order', array( $order_id ), BWS_AS_GROUP );
		}
	}

	/**
	 * Hintergrund-Job.
	 *
	 * @param int $order_id Bestell-ID.
	 * @throws BWS_Exception Damit der Action Scheduler den Job als fehlgeschlagen markiert.
	 */
	public static function run_job( $order_id ) {
		$order = wc_get_order( (int) $order_id );
		if ( ! $order ) {
			return;
		}
		try {
			self::sync( $order );
		} catch ( BWS_Exception $e ) {
			bws_log( 'error', sprintf( 'Bestellung #%s: %s', $order->get_order_number(), $e->getMessage() ) );
			$order->update_meta_data( self::META_ERROR, $e->getMessage() );
			$order->add_order_note( 'bexio-Übertragung fehlgeschlagen: ' . $e->getMessage() );
			$order->save();
			throw $e;
		}
	}

	/**
	 * Überträgt eine Bestellung nach bexio.
	 *
	 * @param WC_Order $order Bestellung.
	 * @param bool     $force Auch übertragen, wenn bereits ein bexio-Auftrag verknüpft ist.
	 * @return int bexio Auftrags-ID.
	 * @throws BWS_Exception Bei Fehlern.
	 */
	public static function sync( WC_Order $order, $force = false ) {
		$existing = (int) $order->get_meta( self::META_ORDER_ID );
		if ( $existing && ! $force ) {
			return $existing;
		}

		// Schutz gegen parallele Übertragung derselben Bestellung.
		$lock = 'bws_order_lock_' . $order->get_id();
		if ( get_transient( $lock ) ) {
			throw new BWS_Exception( 'Übertragung läuft bereits.' );
		}
		set_transient( $lock, 1, 5 * MINUTE_IN_SECONDS );

		try {
			$contact_id = BWS_Contact_Sync::ensure_contact( $order );
			$payload    = self::build_payload( $order, $contact_id );
			$result     = BWS_Client::instance()->post( '2.0/kb_order', $payload );
		} finally {
			delete_transient( $lock );
		}

		if ( empty( $result['id'] ) ) {
			throw new BWS_Exception( 'Unerwartete Antwort von bexio beim Anlegen des Auftrags.' );
		}

		$bexio_id = (int) $result['id'];
		$nr       = isset( $result['document_nr'] ) ? (string) $result['document_nr'] : (string) $bexio_id;

		$order->update_meta_data( self::META_ORDER_ID, $bexio_id );
		$order->update_meta_data( self::META_ORDER_NR, $nr );
		$order->update_meta_data( self::META_CONTACT, $contact_id );
		$order->delete_meta_data( self::META_ERROR );

		$note = sprintf( 'An bexio übertragen: Auftrag %s (Kontakt-ID %d).', $nr, $contact_id );
		if ( isset( $result['total'] ) && abs( (float) $result['total'] - (float) $order->get_total() ) > 0.05 ) {
			$note .= sprintf( ' Achtung: Total in bexio %s weicht vom Bestelltotal %s ab – bitte Steuereinstellungen prüfen.', $result['total'], $order->get_total() );
			bws_log( 'warning', sprintf( 'Bestellung #%s: Total-Abweichung bexio %s / Woo %s', $order->get_order_number(), $result['total'], $order->get_total() ) );
		}
		$order->add_order_note( $note );
		$order->save();

		bws_log( 'info', sprintf( 'Bestellung #%s -> bexio Auftrag %s (ID %d)', $order->get_order_number(), $nr, $bexio_id ) );

		do_action( 'bws_order_synced', $order, $bexio_id, $result );

		return $bexio_id;
	}

	/**
	 * Ob Positionspreise brutto (inkl. MWST) übertragen werden.
	 *
	 * @return bool
	 */
	public static function is_gross() {
		return 'gross' === BWS_Settings::get( 'price_mode' );
	}

	/**
	 * Baut die Auftragsdaten für POST /2.0/kb_order.
	 *
	 * @param WC_Order $order      Bestellung.
	 * @param int      $contact_id bexio Kontakt-ID.
	 * @return array
	 */
	public static function build_payload( WC_Order $order, $contact_id ) {
		$gross   = self::is_gross();
		$created = $order->get_date_created();

		$payload = array(
			'title'               => BWS_Util::truncate( trim( BWS_Settings::get( 'order_title_prefix' ) . ' #' . $order->get_order_number() ), 255 ),
			'contact_id'          => (int) $contact_id,
			'user_id'             => BWS_Lookup::user_id(),
			'api_reference'       => 'woocommerce-' . $order->get_id(),
			'mwst_type'           => 0, // 0 = MWST-pflichtig.
			'mwst_is_net'         => ! $gross, // true = Nettopreise (MWST wird addiert), false = Bruttopreise (inkl. MWST).
			'show_position_taxes' => false,
			'positions'           => self::build_positions( $order ),
		);

		if ( $created ) {
			$payload['is_valid_from'] = $created->date( 'Y-m-d' );
		}

		$currency_id = BWS_Lookup::currency_id( $order->get_currency() );
		if ( $currency_id ) {
			$payload['currency_id'] = $currency_id;
		}
		if ( BWS_Settings::id( 'language_id' ) ) {
			$payload['language_id'] = BWS_Settings::id( 'language_id' );
		}

		$header = array();
		if ( $order->get_payment_method_title() ) {
			$header[] = 'Zahlungsart: ' . $order->get_payment_method_title();
		}
		if ( $order->get_transaction_id() ) {
			$header[] = 'Transaktions-ID: ' . $order->get_transaction_id();
		}
		if ( $order->get_customer_note() ) {
			$header[] = 'Bemerkung des Kunden: ' . $order->get_customer_note();
		}
		if ( $header ) {
			$payload['header'] = nl2br( esc_html( implode( "\n", $header ) ) );
		}

		$delivery = self::delivery_address( $order );
		if ( $delivery ) {
			$payload['delivery_address_type'] = 1; // Abweichende Lieferadresse.
			$payload['delivery_address']      = $delivery;
		} else {
			$payload['delivery_address_type'] = 0;
		}

		/**
		 * Auftragsdaten vor dem Senden an bexio anpassen.
		 *
		 * @param array    $payload Daten für POST /2.0/kb_order.
		 * @param WC_Order $order   Bestellung.
		 */
		return apply_filters( 'bws_order_payload', $payload, $order );
	}

	/**
	 * Auftragspositionen: Artikel, Rabatte, Versand, Gebühren.
	 *
	 * @param WC_Order $order Bestellung.
	 * @return array
	 */
	public static function build_positions( WC_Order $order ) {
		$gross     = self::is_gross();
		$unit_id   = BWS_Settings::id( 'unit_id' );
		$account   = BWS_Settings::id( 'account_id' );
		$positions = array();

		$base = function ( array $position ) use ( $unit_id, $account ) {
			if ( $unit_id ) {
				$position['unit_id'] = $unit_id;
			}
			if ( $account ) {
				$position['account_id'] = $account;
			}
			return $position;
		};

		foreach ( $order->get_items( 'line_item' ) as $item ) {
			/** @var WC_Order_Item_Product $item */
			$qty = (float) $item->get_quantity();
			if ( $qty <= 0 ) {
				continue;
			}
			$net      = (float) $item->get_subtotal();
			$tax      = (float) $item->get_subtotal_tax();
			// Brutto: auf Rappen runden, sonst entstehen durch die Woo-Steuerrundung Preise wie 32.501414.
			$line     = $gross ? round( $net + $tax, wc_get_price_decimals() ) : $net;
			$text     = self::item_text( $item );
			$position = $base(
				array(
					'type'                => 'KbPositionCustom',
					'amount'              => BWS_Util::amount( $qty ),
					'tax_id'              => BWS_Lookup::tax_id_for_rate( BWS_Util::tax_rate( $net, $tax ) ),
					'text'                => $text,
					'unit_price'          => BWS_Util::amount( $line / $qty ),
					'discount_in_percent' => '0',
				)
			);

			$product = $item->get_product();
			if ( $product && BWS_Settings::enabled( 'sync_products' ) && BWS_Product_Sync::is_syncable( $product ) ) {
				$article_id = BWS_Product_Sync::ensure_article( $product );
				if ( $article_id ) {
					$position['type']       = 'KbPositionArticle';
					$position['article_id'] = $article_id;
				}
			}

			$positions[] = apply_filters( 'bws_order_item_position', $position, $item, $order );
		}

		// Gutscheine/Rabatte als Rabattposition (Woo-Positionen werden zum Preis vor Rabatt übertragen).
		$discount = (float) $order->get_discount_total() + ( $gross ? (float) $order->get_discount_tax() : 0 );
		if ( $discount > 0.001 ) {
			$codes       = $order->get_coupon_codes();
			$positions[] = array(
				'type'          => 'KbPositionDiscount',
				'text'          => $codes ? 'Rabatt (Gutschein: ' . implode( ', ', $codes ) . ')' : 'Rabatt',
				'is_percentual' => false,
				'value'         => BWS_Util::amount( $discount, 2 ),
			);
		}

		foreach ( $order->get_items( 'shipping' ) as $item ) {
			/** @var WC_Order_Item_Shipping $item */
			$net = (float) $item->get_total();
			$tax = (float) $item->get_total_tax();
			if ( abs( $net ) < 0.001 && abs( $tax ) < 0.001 ) {
				continue;
			}
			$positions[] = $base(
				array(
					'type'                => 'KbPositionCustom',
					'amount'              => '1',
					'tax_id'              => BWS_Lookup::tax_id_for_rate( BWS_Util::tax_rate( $net, $tax ) ),
					'text'                => 'Versand: ' . $item->get_name(),
					'unit_price'          => BWS_Util::amount( $gross ? round( $net + $tax, wc_get_price_decimals() ) : $net ),
					'discount_in_percent' => '0',
				)
			);
		}

		foreach ( $order->get_items( 'fee' ) as $item ) {
			/** @var WC_Order_Item_Fee $item */
			$net = (float) $item->get_total();
			$tax = (float) $item->get_total_tax();
			$positions[] = $base(
				array(
					'type'                => 'KbPositionCustom',
					'amount'              => '1',
					'tax_id'              => BWS_Lookup::tax_id_for_rate( BWS_Util::tax_rate( $net, $tax ) ),
					'text'                => $item->get_name(),
					'unit_price'          => BWS_Util::amount( $gross ? round( $net + $tax, wc_get_price_decimals() ) : $net ),
					'discount_in_percent' => '0',
				)
			);
		}

		return $positions;
	}

	/**
	 * Positionstext inkl. Variantenmerkmalen und SKU.
	 *
	 * @param WC_Order_Item_Product $item Position.
	 * @return string
	 */
	private static function item_text( WC_Order_Item_Product $item ) {
		$lines   = array( $item->get_name() );
		$product = $item->get_product();
		if ( $product && $product->get_sku() ) {
			$lines[] = 'Art.-Nr.: ' . $product->get_sku();
		}
		foreach ( $item->get_formatted_meta_data( '_', true ) as $meta ) {
			$value = wp_strip_all_tags( $meta->display_value );
			if ( false === strpos( $item->get_name(), $value ) ) {
				$lines[] = wp_strip_all_tags( $meta->display_key ) . ': ' . trim( $value );
			}
		}
		return implode( "\n", $lines );
	}

	/**
	 * Lieferadresse als Text, falls sie von der Rechnungsadresse abweicht.
	 *
	 * @param WC_Order $order Bestellung.
	 * @return string|null
	 */
	private static function delivery_address( WC_Order $order ) {
		if ( ! $order->has_shipping_address() ) {
			return null;
		}
		$fields   = array( 'company', 'first_name', 'last_name', 'address_1', 'address_2', 'postcode', 'city', 'country' );
		$differs  = false;
		foreach ( $fields as $field ) {
			if ( trim( (string) $order->{"get_billing_$field"}() ) !== trim( (string) $order->{"get_shipping_$field"}() ) ) {
				$differs = true;
				break;
			}
		}
		if ( ! $differs ) {
			return null;
		}

		$countries = WC()->countries->get_countries();
		$country   = $order->get_shipping_country();
		$lines     = array_filter(
			array(
				$order->get_shipping_company(),
				trim( $order->get_shipping_first_name() . ' ' . $order->get_shipping_last_name() ),
				$order->get_shipping_address_1(),
				$order->get_shipping_address_2(),
				trim( $order->get_shipping_postcode() . ' ' . $order->get_shipping_city() ),
				$country && $country !== WC()->countries->get_base_country() ? ( $countries[ $country ] ?? $country ) : '',
			)
		);
		return implode( "\n", $lines );
	}
}
