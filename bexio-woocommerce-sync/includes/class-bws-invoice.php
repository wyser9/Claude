<?php
defined( 'ABSPATH' ) || exit;

/**
 * TWINT-Bestellungen: Rechnung in bexio automatisch aus dem Auftrag erstellen und ausstellen.
 *
 * TWINT ist beim Bestellen schon bezahlt. Sobald der Auftrag in bexio steht, erstellt das Plugin daraus die
 * Rechnung (alle Positionen) und stellt sie aus (Status „Offen“). Die Zahlung verbucht der Shop selbst in bexio
 * (Bankabgleich der TWINT-Sammelgutschrift); das Plugin bucht weder Zahlungen noch Kommissionen.
 *
 * Sicherheit gegen Doppel-Rechnungen: Rechnungs-ID am Auftrag gespeichert, vorhandene Rechnungen zum Auftrag
 * (gleiche Suche wie beim Statusabgleich) werden nur verknüpft, Sperre gegen parallele Läufe.
 */
class BWS_Invoice {

	const HOOK          = 'bws_create_invoice';
	const META_ID       = '_bws_invoice_id';
	const META_NR       = '_bws_invoice_nr';
	const META_ERROR    = '_bws_invoice_error';
	const TWINT_METHODS = array( 'twint_regular', 'twint_express' );

	public static function init() {
		add_action( 'bws_order_synced', array( __CLASS__, 'on_synced' ), 20, 1 );
		add_action( self::HOOK, array( __CLASS__, 'run_job' ), 10, 1 );
	}

	/**
	 * Bestellung mit TWINT bezahlt?
	 *
	 * @param WC_Order $order Bestellung.
	 * @return bool
	 */
	public static function is_twint( WC_Order $order ) {
		return in_array( $order->get_payment_method(), self::TWINT_METHODS, true );
	}

	/**
	 * Kommt die Bestellung für eine automatische Rechnung in Frage?
	 *
	 * @param WC_Order $order Bestellung.
	 * @return bool
	 */
	public static function eligible( WC_Order $order ) {
		return BWS_Settings::enabled( 'twint_invoice' )
			&& self::is_twint( $order )
			&& $order->is_paid()
			&& ! in_array( $order->get_status(), array( 'cancelled', 'refunded', 'failed' ), true )
			&& (int) $order->get_meta( BWS_Order_Sync::META_ORDER_ID )
			&& ! $order->get_meta( self::META_ID );
	}

	/**
	 * Nach der Übertragung des Auftrags: Rechnung im Hintergrund erstellen.
	 *
	 * @param WC_Order $order Bestellung.
	 */
	public static function on_synced( $order ) {
		if ( $order instanceof WC_Order && self::eligible( $order ) ) {
			self::enqueue( $order->get_id() );
		}
	}

	public static function enqueue( $order_id ) {
		$args = array( (int) $order_id );
		if ( function_exists( 'as_enqueue_async_action' ) && ! as_has_scheduled_action( self::HOOK, $args, BWS_AS_GROUP ) ) {
			as_enqueue_async_action( self::HOOK, $args, BWS_AS_GROUP );
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
		if ( ! $order || ! self::eligible( $order ) ) {
			return;
		}
		$args = array( (int) $order_id );
		try {
			self::create( $order );
			BWS_Retry::reset( self::HOOK, $args );
		} catch ( BWS_Exception $e ) {
			$next = BWS_Retry::later( self::HOOK, $args, $e );
			if ( $next ) {
				bws_log( 'warning', sprintf( 'Rechnung Bestellung #%s: %s – neuer Versuch um %s.', $order->get_order_number(), $e->getMessage(), wp_date( 'H:i', $next ) ) );
				return;
			}
			$order->update_meta_data( self::META_ERROR, $e->getMessage() );
			$order->add_order_note( 'bexio-Rechnung konnte nicht erstellt werden: ' . $e->getMessage() . ' Bitte in bexio aus dem Auftrag erstellen.' );
			$order->save();
			bws_log( 'error', sprintf( 'Rechnung Bestellung #%s: %s', $order->get_order_number(), $e->getMessage() ) );
			throw $e;
		}
	}

	/**
	 * Erstellt die Rechnung aus dem bexio-Auftrag und stellt sie aus.
	 *
	 * @param WC_Order $order Bestellung.
	 * @return int bexio Rechnungs-ID (0, wenn nichts zu tun war).
	 * @throws BWS_Exception Bei Fehlern.
	 */
	public static function create( WC_Order $order ) {
		if ( $order->get_meta( self::META_ID ) ) {
			return (int) $order->get_meta( self::META_ID );
		}
		$kb_id = (int) $order->get_meta( BWS_Order_Sync::META_ORDER_ID );
		if ( ! $kb_id ) {
			return 0;
		}

		$lock = 'bws_invoice_lock_' . $order->get_id();
		if ( get_transient( $lock ) ) {
			throw new BWS_Exception( 'Rechnung wird bereits erstellt.' );
		}
		set_transient( $lock, 1, 5 * MINUTE_IN_SECONDS );

		try {
			$client = BWS_Client::instance();
			$kb     = $client->get( '2.0/kb_order/' . $kb_id );
			if ( ! is_array( $kb ) || empty( $kb['id'] ) ) {
				throw new BWS_Exception( 'bexio-Auftrag ' . $kb_id . ' nicht gefunden.' );
			}
			$kb_nr = (string) ( $kb['document_nr'] ?? $kb_id );

			// Gibt es schon eine Rechnung zum Auftrag (z. B. von Hand erstellt)? Dann nur verknüpfen.
			$existing = BWS_Status_Import::invoices( $order, $kb );
			if ( $existing ) {
				$first = $existing[0];
				$nr    = (string) ( $first['document_nr'] ?? $first['id'] );
				$order->update_meta_data( self::META_ID, (int) $first['id'] );
				$order->update_meta_data( self::META_NR, $nr );
				$order->delete_meta_data( self::META_ERROR );
				$order->add_order_note( sprintf( 'bexio: Rechnung %s zum Auftrag %s ist schon vorhanden, keine neue erstellt.', $nr, $kb_nr ) );
				$order->save();
				return (int) $first['id'];
			}

			$positions = array();
			foreach ( (array) ( $kb['positions'] ?? array() ) as $p ) {
				if ( empty( $p['id'] ) || empty( $p['type'] ) ) {
					continue;
				}
				$pos = array(
					'id'   => (int) $p['id'],
					'type' => (string) $p['type'],
				);
				// bexio verlangt bei Artikel- und Eigenpositionen die zu verrechnende Menge (sonst 422 «amount Pflichtfeld»).
				// Volle Menge aus dem Auftrag übernehmen; Text-, Zwischensummen- und Rabattpositionen haben keine Menge.
				if ( isset( $p['amount'] ) && '' !== (string) $p['amount'] ) {
					$pos['amount'] = (string) $p['amount'];
				} elseif ( in_array( $pos['type'], array( 'KbPositionArticle', 'KbPositionCustom' ), true ) ) {
					$pos['amount'] = '1';
				}
				$positions[] = $pos;
			}
			$body    = $positions ? array( 'positions' => $positions ) : array();
			$invoice = $client->post( '2.0/kb_order/' . $kb_id . '/invoice', $body );
			if ( ! is_array( $invoice ) || empty( $invoice['id'] ) ) {
				throw new BWS_Exception( 'Unerwartete Antwort von bexio beim Erstellen der Rechnung.' );
			}
		} finally {
			delete_transient( $lock );
		}

		$inv_id = (int) $invoice['id'];
		$nr     = (string) ( $invoice['document_nr'] ?? $inv_id );
		// Sofort speichern: Scheitert danach das Ausstellen, wird beim nächsten Lauf keine zweite Rechnung erstellt.
		$order->update_meta_data( self::META_ID, $inv_id );
		$order->update_meta_data( self::META_NR, $nr );
		$order->delete_meta_data( self::META_ERROR );
		$order->save();

		$note = sprintf( 'bexio: Rechnung %s aus Auftrag %s erstellt (TWINT)', $nr, $kb_nr );
		try {
			$client->post( '2.0/kb_invoice/' . $inv_id . '/issue' );
			$note .= ' und ausgestellt. Die Zahlung bitte wie gewohnt in bexio mit der TWINT-Gutschrift verbuchen.';
		} catch ( BWS_Exception $e ) {
			$note .= ', aber nicht ausgestellt (' . $e->getMessage() . '). Sie steht in bexio als Entwurf, bitte dort ausstellen.';
			bws_log( 'warning', sprintf( 'Rechnung %s (Bestellung #%s) nicht ausgestellt: %s', $nr, $order->get_order_number(), $e->getMessage() ) );
		}
		if ( isset( $invoice['total'] ) && abs( (float) $invoice['total'] - (float) $order->get_total() ) > 0.05 ) {
			$note .= sprintf( ' Achtung: Rechnungstotal %s weicht vom Bestelltotal %s ab.', $invoice['total'], $order->get_total() );
		}
		$order->add_order_note( $note );
		$order->save();
		bws_log( 'info', sprintf( 'Bestellung #%s -> bexio Rechnung %s (ID %d)', $order->get_order_number(), $nr, $inv_id ) );

		return $inv_id;
	}
}
