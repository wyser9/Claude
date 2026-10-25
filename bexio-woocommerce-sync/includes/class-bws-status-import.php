<?php
defined( 'ABSPATH' ) || exit;

/**
 * Übernimmt den Zahlungs-/Erledigt-Status aus bexio in die WooCommerce-Bestellung (stündlich).
 *
 *  - Rechnung(en) zum Auftrag bezahlt                     -> Bestellung "In Bearbeitung" (bezahlt)
 *  - Rechnung(en) bezahlt und Auftrag in bexio "Erledigt" -> Bestellung "Abgeschlossen"
 *
 * Geprüft werden nur verknüpfte Bestellungen, die noch offen sind (Wartend, Zahlung ausstehend,
 * In Bearbeitung). In bexio wird nichts verändert.
 */
class BWS_Status_Import {

	const HOOK        = 'bws_import_order_status';
	const OPTION_LAST = 'bws_status_import_last';

	// bexio kb_item_status_id.
	const ORDER_DONE      = 6;
	const ORDER_CANCELLED = 21;
	const INVOICE_PAID    = 9;
	const INVOICE_CANCEL  = 19;

	/** Bestellstatus, die noch weitergeschaltet werden können. */
	const OPEN_STATUSES = array( 'on-hold', 'pending', 'processing' );

	public static function init() {
		add_action( self::HOOK, array( __CLASS__, 'run_job' ) );
		add_action( 'action_scheduler_init', array( __CLASS__, 'ensure_schedule' ) );
	}

	public static function active() {
		return BWS_Settings::enabled( 'status_from_bexio' ) && BWS_Settings::enabled( 'sync_orders' ) && BWS_Settings::get( 'api_token' );
	}

	/**
	 * Plant den stündlichen Abgleich ein bzw. entfernt ihn.
	 */
	public static function ensure_schedule() {
		if ( ! function_exists( 'as_schedule_recurring_action' ) ) {
			return;
		}
		$scheduled = as_next_scheduled_action( self::HOOK, array(), BWS_AS_GROUP );
		if ( self::active() && ! $scheduled ) {
			as_schedule_recurring_action( time() + 5 * MINUTE_IN_SECONDS, HOUR_IN_SECONDS, self::HOOK, array(), BWS_AS_GROUP );
		} elseif ( ! self::active() && $scheduled ) {
			as_unschedule_all_actions( self::HOOK, array(), BWS_AS_GROUP );
		}
	}

	/**
	 * Hintergrund-Job.
	 *
	 * @throws BWS_Exception Damit der Action Scheduler den Lauf als fehlgeschlagen markiert.
	 */
	public static function run_job() {
		if ( ! self::active() ) {
			return;
		}
		try {
			self::run();
		} catch ( BWS_Exception $e ) {
			bws_log( 'error', 'Statusabgleich aus bexio fehlgeschlagen: ' . $e->getMessage() );
			update_option( self::OPTION_LAST, array( 'time' => time(), 'error' => $e->getMessage() ), false );
			throw $e;
		}
	}

	/**
	 * Prüft alle offenen, verknüpften Bestellungen.
	 *
	 * @return array Zusammenfassung.
	 */
	public static function run() {
		$stats = array(
			'time'      => time(),
			'checked'   => 0,
			'paid'      => 0,
			'completed' => 0,
			'errors'    => 0,
		);

		$orders = wc_get_orders(
			array(
				'status'       => self::OPEN_STATUSES,
				'limit'        => 200,
				'orderby'      => 'date',
				'order'        => 'DESC',
				'date_created' => '>' . ( time() - 180 * DAY_IN_SECONDS ),
				'meta_query'   => array( // phpcs:ignore WordPress.DB.SlowDBQuery
					array(
						'key'     => BWS_Order_Sync::META_ORDER_ID,
						'compare' => 'EXISTS',
					),
				),
			)
		);

		foreach ( $orders as $order ) {
			if ( ! (int) $order->get_meta( BWS_Order_Sync::META_ORDER_ID ) ) {
				continue;
			}
			$stats['checked']++;
			try {
				$result = self::check( $order );
				if ( in_array( 'paid', $result, true ) ) {
					$stats['paid']++;
				}
				if ( in_array( 'completed', $result, true ) ) {
					$stats['completed']++;
				}
			} catch ( BWS_Exception $e ) {
				$stats['errors']++;
				bws_log( 'warning', sprintf( 'Statusabgleich Bestellung #%s: %s', $order->get_order_number(), $e->getMessage() ) );
			}
		}

		update_option( self::OPTION_LAST, $stats, false );
		bws_log( 'info', sprintf( 'Statusabgleich aus bexio: %d geprüft, %d bezahlt, %d abgeschlossen, %d Fehler.', $stats['checked'], $stats['paid'], $stats['completed'], $stats['errors'] ) );
		return $stats;
	}

	/**
	 * Prüft eine Bestellung und schaltet den Status weiter.
	 *
	 * @param WC_Order $order Bestellung.
	 * @return string[] Ausgeführte Schritte ("paid", "completed").
	 * @throws BWS_Exception Bei API-Fehlern.
	 */
	public static function check( WC_Order $order ) {
		$done = array();
		if ( ! $order->has_status( self::OPEN_STATUSES ) ) {
			return $done;
		}

		$client = BWS_Client::instance();
		$kb     = $client->get( '2.0/kb_order/' . (int) $order->get_meta( BWS_Order_Sync::META_ORDER_ID ) );
		if ( ! is_array( $kb ) || empty( $kb['id'] ) || self::ORDER_CANCELLED === (int) ( $kb['kb_item_status_id'] ?? 0 ) ) {
			return $done;
		}

		$invoices = self::invoices( $order, $kb );
		$open     = array_filter(
			$invoices,
			function ( $invoice ) {
				return self::INVOICE_PAID !== (int) ( $invoice['kb_item_status_id'] ?? 0 );
			}
		);
		if ( ! $invoices || $open ) {
			return $done; // Noch keine Rechnung oder nicht alles bezahlt.
		}

		$numbers = implode( ', ', array_map( function ( $i ) { return $i['document_nr'] ?? $i['id']; }, $invoices ) );

		if ( ! $order->is_paid() ) {
			$note = sprintf( 'bexio: Rechnung %s bezahlt.', $numbers );
			$order->add_order_note( $note );
			$order->payment_complete();
			if ( ! $order->is_paid() ) {
				$order->update_status( 'processing' );
			}
			$done[] = 'paid';
			bws_log( 'info', sprintf( 'Bestellung #%s: %s -> In Bearbeitung', $order->get_order_number(), $note ) );
		}

		// Bestellungen ohne Versand (z.B. nur virtuelle Artikel) schliesst WooCommerce beim Bezahlen selbst ab.
		if ( self::ORDER_DONE === (int) ( $kb['kb_item_status_id'] ?? 0 ) && ! $order->has_status( 'completed' ) ) {
			$order->update_status( 'completed', sprintf( 'bexio: Rechnung %s bezahlt und Auftrag %s erledigt.', $numbers, $kb['document_nr'] ?? $kb['id'] ) );
			$done[] = 'completed';
			bws_log( 'info', sprintf( 'Bestellung #%s -> Abgeschlossen (bexio-Auftrag erledigt).', $order->get_order_number() ) );
		}

		return $done;
	}

	/**
	 * Rechnungen zum bexio-Auftrag (ohne stornierte).
	 *
	 * bexio liefert die Verknüpfung Auftrag -> Rechnung nicht über die API. Gesucht werden daher die Rechnungen
	 * des Kontakts; zugeordnet wird über den Titel (enthält die Bestellnummer, wird aus dem Auftrag übernommen),
	 * sonst über ein identisches Total, sofern genau eine solche Rechnung existiert.
	 *
	 * @param WC_Order $order Bestellung.
	 * @param array    $kb    bexio-Auftrag.
	 * @return array
	 */
	public static function invoices( WC_Order $order, array $kb ) {
		$contact_id = (int) ( $kb['contact_id'] ?? 0 );
		if ( ! $contact_id ) {
			return array();
		}
		$hits = BWS_Client::instance()->post(
			'2.0/kb_invoice/search',
			array(
				array(
					'field'    => 'contact_id',
					'value'    => $contact_id,
					'criteria' => '=',
				),
			),
			array( 'limit' => 200 )
		);
		$hits = array_filter(
			is_array( $hits ) ? $hits : array(),
			function ( $invoice ) {
				return self::INVOICE_CANCEL !== (int) ( $invoice['kb_item_status_id'] ?? 0 );
			}
		);

		$number   = (string) $order->get_order_number();
		$by_title = array_filter(
			$hits,
			function ( $invoice ) use ( $number, $kb ) {
				$title = (string) ( $invoice['title'] ?? '' );
				return '' !== $title && ( BWS_Util::title_matches_order_number( $title, $number ) || $title === (string) ( $kb['title'] ?? '' ) );
			}
		);
		if ( $by_title ) {
			return array_values( $by_title );
		}

		$total    = (float) ( $kb['total'] ?? $order->get_total() );
		$since    = substr( (string) ( $kb['is_valid_from'] ?? '' ), 0, 10 );
		$by_total = array_filter(
			$hits,
			function ( $invoice ) use ( $total, $since ) {
				$from = substr( (string) ( $invoice['is_valid_from'] ?? '' ), 0, 10 );
				return abs( (float) ( $invoice['total'] ?? 0 ) - $total ) < 0.01 && ( '' === $since || $from >= $since );
			}
		);
		return 1 === count( $by_total ) ? array_values( $by_total ) : array();
	}
}
