<?php
defined( 'ABSPATH' ) || exit;

/**
 * Einmaliges Nachrüsten bestehender bexio-Artikel, die mit Shop-Produkten verknüpft sind:
 * "Ist ein Produkt mit Lager" (falls noch nicht gesetzt), Aufwandkonto und Vorsteuer (falls leer).
 *
 * Es werden nur fehlende Werte ergänzt. Lagerartikel bleiben unverändert (kein Überschreiben des
 * Bestands), ebenso bereits gesetzte Konten/Steuern und Artikel ohne Shop-Produkt (z. B. Waffen-
 * Kopien mit Seriennummer).
 */
class BWS_Backfill {

	const HOOK_SCAN  = 'bws_backfill_scan';
	const HOOK_CHUNK = 'bws_backfill_chunk';
	const OPT_QUEUE  = 'bws_backfill_queue';
	const OPT_STATUS = 'bws_backfill_status';
	const CHUNK      = 25;

	public static function init() {
		add_action( self::HOOK_SCAN, array( __CLASS__, 'scan' ) );
		add_action( self::HOOK_CHUNK, array( __CLASS__, 'chunk' ) );
	}

	public static function start() {
		update_option( self::OPT_STATUS, array( 'state' => 'scanning', 'time' => time() ), false );
		as_enqueue_async_action( self::HOOK_SCAN, array(), BWS_AS_GROUP );
	}

	/**
	 * Fehlende Felder für einen bexio-Artikel.
	 *
	 * @param array      $article bexio-Artikel.
	 * @param WC_Product $product Verknüpftes Produkt.
	 * @return array
	 */
	public static function missing_fields( array $article, WC_Product $product ) {
		$fields = array();
		if ( empty( $article['is_stock'] ) && BWS_Settings::enabled( 'new_article_stock' ) && ! $product->is_virtual() ) {
			$fields['is_stock']     = true;
			$fields['stock_nr']     = 0;
			$fields['stock_min_nr'] = 0;
			if ( BWS_Settings::id( 'stock_id' ) ) {
				$fields['stock_id'] = BWS_Settings::id( 'stock_id' );
			}
			if ( BWS_Settings::id( 'stock_place_id' ) ) {
				$fields['stock_place_id'] = BWS_Settings::id( 'stock_place_id' );
			}
		}
		if ( empty( $article['expense_account_id'] ) ) {
			$account = BWS_Lookup::expense_account_id();
			if ( $account ) {
				$fields['expense_account_id'] = $account;
			}
		}
		if ( empty( $article['tax_expense_id'] ) ) {
			$tax = BWS_Lookup::tax_expense_id();
			if ( $tax ) {
				$fields['tax_expense_id'] = $tax;
			}
		}
		return $fields;
	}

	/**
	 * Liest alle bexio-Artikel und plant die nötigen Änderungen.
	 *
	 * @return array Status.
	 */
	public static function scan() {
		$articles = BWS_Client::instance()->get_all( '2.0/article' );
		$linked   = BWS_Product_Sync::products_by_article_id();
		$queue    = array();
		foreach ( $articles as $article ) {
			$id = (int) ( $article['id'] ?? 0 );
			if ( ! $id || ! isset( $linked[ $id ] ) ) {
				continue;
			}
			$product = wc_get_product( $linked[ $id ] );
			if ( ! $product ) {
				continue;
			}
			$fields = self::missing_fields( $article, $product );
			if ( $fields ) {
				$queue[ $id ] = $fields;
			}
		}
		update_option( self::OPT_QUEUE, $queue, false );
		$status = array(
			'state'    => $queue ? 'running' : 'done',
			'time'     => time(),
			'articles' => count( $articles ),
			'linked'   => count( array_intersect_key( $linked, array_flip( array_column( $articles, 'id' ) ) ) ),
			'todo'     => count( $queue ),
			'done'     => 0,
			'errors'   => 0,
		);
		update_option( self::OPT_STATUS, $status, false );
		bws_log( 'info', sprintf( 'Nachrüsten: %d von %d verknüpften Artikeln brauchen Änderungen.', $status['todo'], $status['linked'] ) );
		if ( $queue && function_exists( 'as_enqueue_async_action' ) ) {
			as_enqueue_async_action( self::HOOK_CHUNK, array(), BWS_AS_GROUP );
		}
		return $status;
	}

	/**
	 * Arbeitet ein Paket der Warteschlange ab.
	 *
	 * @return array Status.
	 */
	public static function chunk() {
		$queue  = (array) get_option( self::OPT_QUEUE, array() );
		$status = (array) get_option( self::OPT_STATUS, array() ) + array( 'done' => 0, 'errors' => 0 );
		$batch  = array_slice( $queue, 0, self::CHUNK, true );
		foreach ( $batch as $id => $fields ) {
			try {
				BWS_Client::instance()->post( '2.0/article/' . (int) $id, $fields );
				$status['done']++;
			} catch ( BWS_Exception $e ) {
				$status['errors']++;
				bws_log( 'error', sprintf( 'Nachrüsten bexio-Artikel %d: %s', $id, $e->getMessage() ) );
			}
			unset( $queue[ $id ] );
		}
		update_option( self::OPT_QUEUE, $queue, false );
		$status['state'] = $queue ? 'running' : 'done';
		$status['time']  = time();
		update_option( self::OPT_STATUS, $status, false );
		if ( $queue && function_exists( 'as_enqueue_async_action' ) ) {
			as_enqueue_async_action( self::HOOK_CHUNK, array(), BWS_AS_GROUP );
		} elseif ( ! $queue ) {
			bws_log( 'info', sprintf( 'Nachrüsten abgeschlossen: %d Artikel aktualisiert, %d Fehler.', $status['done'], $status['errors'] ) );
		}
		return $status;
	}
}
