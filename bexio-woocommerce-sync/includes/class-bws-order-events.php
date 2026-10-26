<?php
defined( 'ABSPATH' ) || exit;

/**
 * Ereignisse rund um bereits übertragene Bestellungen:
 *  - Post-Sendungsnummer als Kommentar in den bexio-Auftrag (Plugin "Armatum Post Label")
 *  - Täglicher Hinweis an den Shop bei unbezahlten Vorkasse-Bestellungen
 *  - Storno im Shop: Kommentar im bexio-Auftrag und Hinweis an den Shop
 *
 * In bexio werden nur Kommentare hinzugefügt – Auftrag, Positionen, Status und Rechnungen bleiben unverändert.
 */
class BWS_Order_Events {

	const HOOK_COMMENT  = 'bws_order_comment';
	const HOOK_REMINDER = 'bws_payment_reminder';
	const META_TRACKING = '_bws_tracking_sent';
	const META_REMINDED = '_bws_payment_reminded';
	const META_CANCEL   = '_bws_cancel_sent';
	const REMINDER_TIME = '07:45';

	/** Zahlungsarten mit Vorkasse. */
	const PREPAY_METHODS = array( 'bacs', 'cheque' );

	public static function init() {
		add_action( 'apl_label_created', array( __CLASS__, 'on_label_created' ), 10, 3 );
		add_action( 'woocommerce_order_status_cancelled', array( __CLASS__, 'on_cancelled' ), 20, 1 );
		add_action( self::HOOK_COMMENT, array( __CLASS__, 'run_comment_job' ), 10, 3 );
		add_action( self::HOOK_REMINDER, array( __CLASS__, 'run_reminder' ) );
		add_action( 'action_scheduler_init', array( __CLASS__, 'ensure_schedule' ) );
	}

	private static function ready() {
		return BWS_Settings::enabled( 'sync_orders' ) && BWS_Settings::get( 'api_token' );
	}

	/**
	 * Empfänger der Hinweise an den Shop.
	 *
	 * @return string
	 */
	public static function notify_email() {
		$email = sanitize_email( (string) BWS_Settings::get( 'notify_email' ) );
		return $email ? $email : (string) get_option( 'admin_email' );
	}

	/* ------------------------------------------------------------------ */
	/* 1. Sendungsnummer                                                   */
	/* ------------------------------------------------------------------ */

	/**
	 * Label erstellt (Armatum Post Label).
	 *
	 * @param WC_Order $order   Bestellung.
	 * @param string   $ident   Sendungsnummer.
	 * @param bool     $preview Testlabel (SPECIMEN).
	 */
	public static function on_label_created( $order, $ident, $preview = false ) {
		if ( $preview || ! $order instanceof WC_Order || ! BWS_Settings::enabled( 'tracking_to_bexio' ) || ! self::ready() ) {
			return;
		}
		if ( ! $order->get_meta( BWS_Order_Sync::META_ORDER_ID ) || (string) $order->get_meta( self::META_TRACKING ) === (string) $ident ) {
			return;
		}
		self::enqueue( $order->get_id(), 'tracking', (string) $ident );
	}

	/* ------------------------------------------------------------------ */
	/* 3. Storno                                                           */
	/* ------------------------------------------------------------------ */

	/**
	 * Bestellung im Shop storniert.
	 *
	 * @param int $order_id Bestell-ID.
	 */
	public static function on_cancelled( $order_id ) {
		$order = wc_get_order( $order_id );
		if ( ! $order || ! BWS_Settings::enabled( 'cancel_to_bexio' ) || ! self::ready() ) {
			return;
		}
		if ( ! $order->get_meta( BWS_Order_Sync::META_ORDER_ID ) || $order->get_meta( self::META_CANCEL ) ) {
			return;
		}
		self::enqueue( $order->get_id(), 'cancelled', '' );
	}

	/**
	 * bexio-Auftragsnummer (Fallback: ID).
	 *
	 * @param WC_Order $order Bestellung.
	 * @return string
	 */
	private static function order_nr( WC_Order $order ) {
		$nr = (string) $order->get_meta( BWS_Order_Sync::META_ORDER_NR );
		return '' !== $nr ? $nr : (string) $order->get_meta( BWS_Order_Sync::META_ORDER_ID );
	}

	/**
	 * Betrag als Klartext für E-Mails, z.B. "CHF 120.00".
	 *
	 * @param WC_Order $order Bestellung.
	 * @return string
	 */
	private static function money( WC_Order $order ) {
		return html_entity_decode( wp_strip_all_tags( wc_price( $order->get_total(), array( 'currency' => $order->get_currency() ) ) ), ENT_QUOTES, 'UTF-8' );
	}

	private static function enqueue( $order_id, $type, $value ) {
		$args = array( (int) $order_id, $type, $value );
		if ( ! function_exists( 'as_enqueue_async_action' ) ) {
			self::run_comment_job( ...$args );
			return;
		}
		if ( ! as_has_scheduled_action( self::HOOK_COMMENT, $args, BWS_AS_GROUP ) ) {
			as_enqueue_async_action( self::HOOK_COMMENT, $args, BWS_AS_GROUP );
		}
	}

	/**
	 * Hintergrund-Job: Kommentar in bexio schreiben.
	 *
	 * @param int    $order_id Bestell-ID.
	 * @param string $type     tracking|cancelled.
	 * @param string $value    Sendungsnummer.
	 * @throws BWS_Exception Damit der Action Scheduler den Job als fehlgeschlagen markiert.
	 */
	public static function run_comment_job( $order_id, $type, $value = '' ) {
		$order = wc_get_order( (int) $order_id );
		if ( ! $order ) {
			return;
		}
		try {
			if ( 'tracking' === $type ) {
				self::send_tracking( $order, (string) $value );
			} elseif ( 'cancelled' === $type ) {
				self::send_cancel( $order );
			}
		} catch ( BWS_Exception $e ) {
			bws_log( 'error', sprintf( 'Bestellung #%s: Kommentar in bexio fehlgeschlagen: %s', $order->get_order_number(), $e->getMessage() ) );
			$order->add_order_note( 'bexio-Kommentar fehlgeschlagen: ' . $e->getMessage() );
			throw $e;
		}
	}

	/**
	 * Sendungsnummer als Kommentar im bexio-Auftrag.
	 *
	 * @param WC_Order $order Bestellung.
	 * @param string   $ident Sendungsnummer.
	 */
	public static function send_tracking( WC_Order $order, $ident ) {
		if ( '' === $ident || (string) $order->get_meta( self::META_TRACKING ) === $ident ) {
			return;
		}
		$url = 'https://www.post.ch/swisspost-tracking?formattedParcelCodes=' . rawurlencode( preg_replace( '/\s+/', '', $ident ) );
		self::comment( $order, sprintf( 'Versand mit der Post – Sendungsnummer %s. Sendungsverfolgung: %s', $ident, $url ) );

		$order->update_meta_data( self::META_TRACKING, $ident );
		$order->add_order_note( sprintf( 'Sendungsnummer %s im bexio-Auftrag %s eingetragen.', $ident, self::order_nr( $order ) ) );
		$order->save();
	}

	/**
	 * Storno: Kommentar im bexio-Auftrag und Hinweis an den Shop.
	 *
	 * Die bexio-API bietet keinen Weg, einen Auftrag zu stornieren – das geschieht deshalb in bexio von Hand.
	 *
	 * @param WC_Order $order Bestellung.
	 */
	public static function send_cancel( WC_Order $order ) {
		if ( $order->get_meta( self::META_CANCEL ) ) {
			return;
		}
		$nr = self::order_nr( $order );
		self::comment( $order, sprintf( 'Bestellung #%s wurde im Shop am %s storniert. Bitte diesen Auftrag (und eine allfällige Rechnung) in bexio stornieren.', $order->get_order_number(), wp_date( 'd.m.Y H:i' ) ) );

		$order->update_meta_data( self::META_CANCEL, time() );
		$order->add_order_note( sprintf( 'Storno in bexio-Auftrag %s vermerkt. Bitte den Auftrag in bexio stornieren.', $nr ) );
		$order->save();

		wp_mail(
			self::notify_email(),
			sprintf( '[%s] Bestellung #%s storniert – bexio-Auftrag %s stornieren', wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES ), $order->get_order_number(), $nr ),
			sprintf(
				"Die Bestellung #%s (%s, %s) wurde im Shop storniert.\n\nBitte in bexio den Auftrag %s und eine allfällige Rechnung stornieren – das ist über die bexio-Schnittstelle nicht möglich.\n\nBestellung: %s\n",
				$order->get_order_number(),
				trim( $order->get_formatted_billing_full_name() ),
				self::money( $order ),
				$nr,
				$order->get_edit_order_url()
			)
		);
		bws_log( 'info', sprintf( 'Bestellung #%s storniert: Kommentar im bexio-Auftrag %s, Hinweis an %s.', $order->get_order_number(), $nr, self::notify_email() ) );
	}

	/**
	 * Interner Kommentar (nicht für den Kunden sichtbar) im bexio-Auftrag.
	 *
	 * @param WC_Order $order Bestellung.
	 * @param string   $text  Text.
	 * @throws BWS_Exception Bei Fehlern.
	 */
	public static function comment( WC_Order $order, $text ) {
		BWS_Client::instance()->post(
			'2.0/kb_order/' . (int) $order->get_meta( BWS_Order_Sync::META_ORDER_ID ) . '/comment',
			array(
				'text'      => $text,
				'user_id'   => BWS_Lookup::user_id(),
				'user_name' => BWS_Lookup::user_name(),
				'is_public' => false,
			)
		);
	}

	/* ------------------------------------------------------------------ */
	/* 2. Unbezahlte Vorkasse-Bestellungen                                 */
	/* ------------------------------------------------------------------ */

	public static function reminder_days() {
		return max( 0, (int) BWS_Settings::get( 'payment_reminder_days' ) );
	}

	/**
	 * Plant den täglichen Hinweis ein bzw. entfernt ihn.
	 */
	public static function ensure_schedule() {
		if ( ! function_exists( 'as_schedule_recurring_action' ) ) {
			return;
		}
		$active    = self::reminder_days() > 0 && BWS_Settings::get( 'api_token' );
		$scheduled = as_next_scheduled_action( self::HOOK_REMINDER, array(), BWS_AS_GROUP );
		if ( $active && ! $scheduled ) {
			list( $h, $m ) = array_map( 'intval', explode( ':', self::REMINDER_TIME ) );
			$next          = new DateTime( 'now', wp_timezone() );
			$next->setTime( $h, $m, 0 );
			if ( $next->getTimestamp() <= time() ) {
				$next->modify( '+1 day' );
			}
			as_schedule_recurring_action( $next->getTimestamp(), DAY_IN_SECONDS, self::HOOK_REMINDER, array(), BWS_AS_GROUP );
		} elseif ( ! $active && $scheduled ) {
			as_unschedule_all_actions( self::HOOK_REMINDER, array(), BWS_AS_GROUP );
		}
	}

	/**
	 * Unbezahlte Vorkasse-Bestellungen, die älter als die eingestellte Frist sind und noch keinen Hinweis hatten.
	 *
	 * @return WC_Order[]
	 */
	public static function overdue_orders() {
		$days = self::reminder_days();
		if ( ! $days ) {
			return array();
		}
		$orders = wc_get_orders(
			array(
				'status'         => array( 'on-hold', 'pending' ),
				'payment_method' => self::PREPAY_METHODS,
				'date_created'   => '<' . ( time() - $days * DAY_IN_SECONDS ),
				'limit'          => 200,
				'orderby'        => 'date',
				'order'          => 'ASC',
			)
		);
		return array_values(
			array_filter(
				$orders,
				function ( $order ) {
					return ! $order->get_meta( self::META_REMINDED ) && in_array( $order->get_payment_method(), self::PREPAY_METHODS, true );
				}
			)
		);
	}

	/**
	 * Täglicher Job: eine Sammel-E-Mail an den Shop, pro Bestellung nur einmal.
	 *
	 * @return int Anzahl gemeldeter Bestellungen.
	 */
	public static function run_reminder() {
		$orders = self::overdue_orders();
		if ( ! $orders ) {
			return 0;
		}
		$days  = self::reminder_days();
		$lines = array();
		foreach ( $orders as $order ) {
			$created = $order->get_date_created();
			$lines[] = sprintf(
				'#%s vom %s – %s – %s – bexio-Auftrag %s – %s',
				$order->get_order_number(),
				$created ? wp_date( 'd.m.Y', $created->getTimestamp() ) : '?',
				trim( $order->get_formatted_billing_full_name() ),
				self::money( $order ),
				$order->get_meta( BWS_Order_Sync::META_ORDER_ID ) ? self::order_nr( $order ) : '–',
				$order->get_edit_order_url()
			);
		}

		$sent = wp_mail(
			self::notify_email(),
			sprintf( '[%s] %d unbezahlte Vorkasse-Bestellung(en) seit über %d Tagen', wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES ), count( $orders ), $days ),
			sprintf( "Für diese Bestellungen mit Vorkasse ist nach %d Tagen noch keine Zahlung verbucht (in bexio keine bezahlte Rechnung):\n\n%s\n\nJede Bestellung wird nur einmal gemeldet. Frist ändern: WooCommerce > bexio.\n", $days, implode( "\n", $lines ) )
		);
		if ( ! $sent ) {
			bws_log( 'warning', 'Zahlungshinweis: E-Mail an ' . self::notify_email() . ' konnte nicht gesendet werden.' );
			return 0;
		}

		foreach ( $orders as $order ) {
			$order->update_meta_data( self::META_REMINDED, time() );
			$order->add_order_note( sprintf( 'Seit über %d Tagen unbezahlt – Hinweis an %s gesendet.', $days, self::notify_email() ) );
			$order->save();
		}
		bws_log( 'info', sprintf( 'Zahlungshinweis für %d Bestellung(en) an %s gesendet.', count( $orders ), self::notify_email() ) );
		return count( $orders );
	}
}
