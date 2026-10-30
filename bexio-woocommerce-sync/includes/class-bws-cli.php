<?php
defined( 'ABSPATH' ) || exit;

/**
 * WP-CLI Befehle für den bexio-Abgleich.
 *
 * ## EXAMPLES
 *
 *     wp bexio test
 *     wp bexio products
 *     wp bexio products --id=123
 *     wp bexio order 456
 *     wp bexio import-stock
 *     wp bexio backfill
 *     wp bexio import-status
 */
class BWS_CLI {

	/**
	 * Prüft die Verbindung zu bexio.
	 */
	public function test() {
		try {
			$me = BWS_Client::instance()->get( '3.0/users/me' );
			WP_CLI::success( sprintf( 'Verbunden als %s %s (%s), Benutzer-ID %d', $me['firstname'] ?? '', $me['lastname'] ?? '', $me['email'] ?? '', $me['id'] ?? 0 ) );
		} catch ( BWS_Exception $e ) {
			WP_CLI::error( $e->getMessage() );
		}
	}

	/**
	 * Überträgt Artikel und Lagerbestände (synchron).
	 *
	 * [--id=<id>]
	 * : Nur dieses Produkt / diese Variante.
	 */
	public function products( $args, $assoc ) {
		$ids = isset( $assoc['id'] ) ? array( (int) $assoc['id'] ) : null;
		$ok  = 0;
		$err = 0;
		$page = 1;
		do {
			$batch = $ids ? $ids : BWS_Product_Sync::syncable_ids( $page, 100 );
			foreach ( $batch as $id ) {
				$product = wc_get_product( $id );
				if ( ! $product ) {
					continue;
				}
				try {
					$article_id = BWS_Product_Sync::sync( $product );
					WP_CLI::log( sprintf( '#%d %s -> bexio %s', $id, $product->get_name(), $article_id ? $article_id : '(übersprungen)' ) );
					$ok++;
				} catch ( BWS_Exception $e ) {
					WP_CLI::warning( sprintf( '#%d: %s', $id, $e->getMessage() ) );
					$err++;
				}
			}
			$page++;
		} while ( ! $ids && count( $batch ) === 100 );

		WP_CLI::success( sprintf( '%d übertragen, %d Fehler.', $ok, $err ) );
	}

	/**
	 * Übernimmt den Lagerstatus aus bexio (Bestand > 0 = Vorrätig, <= 0 = Lieferrückstand).
	 *
	 * @subcommand import-stock
	 */
	public function import_stock() {
		try {
			$s = BWS_Stock_Import::run();
			WP_CLI::success( sprintf( '%d Artikel: %d Vorrätig, %d Lieferrückstand, %d unverändert, %d nicht im Shop, %d übersprungen, %d Fehler.', $s['articles'], $s['instock'], $s['backorder'], $s['unchanged'], $s['not_found'], $s['skipped'], $s['errors'] ) );
		} catch ( BWS_Exception $e ) {
			WP_CLI::error( $e->getMessage() );
		}
	}

	/**
	 * Übernimmt Bezahlt/Erledigt aus bexio in offene Bestellungen.
	 *
	 * @subcommand import-status
	 */
	public function import_status() {
		try {
			$s = BWS_Status_Import::run();
			WP_CLI::success( sprintf( '%d geprüft, %d bezahlt, %d abgeschlossen, %d Fehler.', $s['checked'], $s['paid'], $s['completed'], $s['errors'] ) );
		} catch ( BWS_Exception $e ) {
			WP_CLI::error( $e->getMessage() );
		}
	}

	/**
	 * Rüstet bestehende verknüpfte bexio-Artikel nach (Lager, Aufwandkonto, Vorsteuer – nur fehlende Werte).
	 */
	public function backfill() {
		try {
			delete_option( BWS_Backfill::OPT_STATUS );
			$status = BWS_Backfill::scan();
			while ( 'scanning' === $status['state'] ) {
				$status = BWS_Backfill::scan();
			}
			while ( 'running' === $status['state'] ) {
				$status = BWS_Backfill::chunk();
				WP_CLI::log( sprintf( '%d / %d', $status['done'], $status['todo'] ) );
			}
			WP_CLI::success( sprintf( '%d Artikel aktualisiert, %d Fehler.', $status['done'] ?? 0, $status['errors'] ?? 0 ) );
		} catch ( BWS_Exception $e ) {
			WP_CLI::error( $e->getMessage() );
		}
	}

	/**
	 * Überträgt eine Bestellung.
	 *
	 * <id>
	 * : Bestell-ID.
	 *
	 * [--force]
	 * : Auch übertragen, wenn bereits ein bexio-Auftrag verknüpft ist (erzeugt einen weiteren Auftrag).
	 */
	public function order( $args, $assoc ) {
		$order = wc_get_order( (int) $args[0] );
		if ( ! $order ) {
			WP_CLI::error( 'Bestellung nicht gefunden.' );
		}
		try {
			$id = BWS_Order_Sync::sync( $order, isset( $assoc['force'] ) );
			if ( ! $id ) {
				WP_CLI::warning( 'Nicht übertragen: Bestellung enthält nur Platzhalterdaten.' );
				return;
			}
			WP_CLI::success( 'bexio Auftrag-ID ' . $id );
		} catch ( BWS_Exception $e ) {
			WP_CLI::error( $e->getMessage() );
		}
	}
}
