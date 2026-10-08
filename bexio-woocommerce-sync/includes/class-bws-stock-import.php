<?php
defined( 'ABSPATH' ) || exit;

/**
 * Täglicher Lagerabgleich bexio -> WooCommerce (bexio führt das Lager).
 *
 * Für jeden bexio-Lagerartikel wird das WooCommerce-Produkt mit gleicher SKU (bzw. bereits verknüpfter
 * Artikel-ID) gesucht:
 *  - Produkt nicht vorhanden      -> nichts tun
 *  - Bestand > 0                  -> "Vorrätig" (instock)
 *  - Bestand <= 0                 -> "Lieferrückstand" (onbackorder)
 *
 * Bei Produkten mit aktivierter Lagerverwaltung in WooCommerce wird zusätzlich die Menge übernommen
 * und Lieferrückstand erlaubt, da WooCommerce den Status dort aus der Menge berechnet.
 */
class BWS_Stock_Import {

	const HOOK         = 'bws_import_stock';
	const OPTION_LAST  = 'bws_stock_import_last';
	const OPTION_SCHED = 'bws_stock_import_scheduled';

	public static function init() {
		add_action( self::HOOK, array( __CLASS__, 'run_job' ) );
		add_action( 'action_scheduler_init', array( __CLASS__, 'ensure_schedule' ) );
	}

	/**
	 * Plant den täglichen Import ein bzw. entfernt ihn, passend zu den Einstellungen.
	 */
	public static function ensure_schedule() {
		if ( ! function_exists( 'as_schedule_recurring_action' ) ) {
			return;
		}
		$wanted = ( 'from_bexio' === BWS_Settings::stock_mode() && BWS_Settings::get( 'api_token' ) )
			? self::normalize_time( BWS_Settings::get( 'stock_import_time' ) )
			: '';

		$scheduled = as_next_scheduled_action( self::HOOK, array(), BWS_AS_GROUP );
		if ( $scheduled && get_option( self::OPTION_SCHED ) === $wanted ) {
			return;
		}

		as_unschedule_all_actions( self::HOOK, array(), BWS_AS_GROUP );
		update_option( self::OPTION_SCHED, $wanted, false );

		if ( '' !== $wanted ) {
			as_schedule_recurring_action( self::next_run( $wanted ), DAY_IN_SECONDS, self::HOOK, array(), BWS_AS_GROUP );
		}
	}

	/**
	 * Uhrzeit "HH:MM" bereinigen.
	 *
	 * @param string $time Eingabe.
	 * @return string
	 */
	public static function normalize_time( $time ) {
		if ( preg_match( '/^(\d{1,2}):(\d{2})$/', trim( (string) $time ), $m ) && (int) $m[1] < 24 && (int) $m[2] < 60 ) {
			return sprintf( '%02d:%02d', $m[1], $m[2] );
		}
		return '03:00';
	}

	/**
	 * Nächster Ausführungszeitpunkt (Zeitzone der Website).
	 *
	 * @param string $time "HH:MM".
	 * @return int Unix-Timestamp.
	 */
	private static function next_run( $time ) {
		list( $h, $m ) = array_map( 'intval', explode( ':', $time ) );
		$next = new DateTime( 'now', wp_timezone() );
		$next->setTime( $h, $m, 0 );
		if ( $next->getTimestamp() <= time() ) {
			$next->modify( '+1 day' );
		}
		return $next->getTimestamp();
	}

	/**
	 * Hintergrund-Job.
	 *
	 * @throws BWS_Exception Damit der Action Scheduler den Lauf als fehlgeschlagen markiert.
	 */
	public static function run_job() {
		if ( 'from_bexio' !== BWS_Settings::stock_mode() ) {
			return;
		}
		try {
			self::run();
		} catch ( BWS_Exception $e ) {
			bws_log( 'error', 'Lagerimport aus bexio fehlgeschlagen: ' . $e->getMessage() );
			update_option( self::OPTION_LAST, array( 'time' => time(), 'error' => $e->getMessage() ), false );
			throw $e;
		}
	}

	/**
	 * Holt alle Artikel aus bexio und setzt den Lagerstatus in WooCommerce.
	 *
	 * @return array Zusammenfassung.
	 */
	public static function run() {
		$articles = BWS_Client::instance()->get_all( '2.0/article' );
		$field    = 'stock_available_nr' === BWS_Settings::get( 'stock_field' ) ? 'stock_available_nr' : 'stock_nr';
		$by_id    = self::products_by_article_id();
		$stats    = array(
			'time'      => time(),
			'articles'  => count( $articles ),
			'instock'   => 0,
			'backorder' => 0,
			'unchanged' => 0,
			'not_found' => 0,
			'skipped'   => 0,
			'errors'    => 0,
		);

		BWS_Product_Sync::$suspended = true;
		try {
			foreach ( $articles as $article ) {
				// Nur Lagerartikel – bei anderen Artikeln ist der Bestand in bexio immer 0.
				if ( empty( $article['is_stock'] ) || ! isset( $article[ $field ] ) ) {
					$stats['skipped']++;
					continue;
				}

				$product = self::find_product( $article, $by_id );
				if ( ! $product ) {
					$stats['not_found']++;
					continue;
				}
				if ( $product->is_type( array( 'variable', 'grouped', 'external' ) ) ) {
					bws_log( 'notice', sprintf( 'Lagerimport: Artikel %s gehört zu einem %s-Produkt (#%d) – Status wird dort aus den Varianten berechnet, übersprungen.', $article['intern_code'], $product->get_type(), $product->get_id() ) );
					$stats['skipped']++;
					continue;
				}

				try {
					$result = self::apply( $product, (float) $article[ $field ] );
					$stats[ $result ]++;
				} catch ( Exception $e ) {
					$stats['errors']++;
					bws_log( 'error', sprintf( 'Lagerimport: Produkt #%d (%s): %s', $product->get_id(), $article['intern_code'], $e->getMessage() ) );
				}
			}
		} finally {
			BWS_Product_Sync::$suspended = false;
		}

		update_option( self::OPTION_LAST, $stats, false );
		bws_log(
			'info',
			sprintf(
				'Lagerimport aus bexio: %d Artikel, %d auf Vorrätig, %d auf Lieferrückstand, %d unverändert, %d nicht im Shop, %d übersprungen, %d Fehler.',
				$stats['articles'],
				$stats['instock'],
				$stats['backorder'],
				$stats['unchanged'],
				$stats['not_found'],
				$stats['skipped'],
				$stats['errors']
			)
		);
		return $stats;
	}

	/**
	 * Setzt den Lagerstatus eines Produkts nach der bexio-Regel.
	 *
	 * @param WC_Product $product Produkt oder Variante.
	 * @param float      $stock   Bestand in bexio.
	 * @return string "instock", "backorder" oder "unchanged".
	 */
	public static function apply( WC_Product $product, $stock ) {
		$status = $stock > 0 ? 'instock' : 'onbackorder';

		if ( $product->managing_stock() ) {
			// WooCommerce leitet den Status aus Menge + Lieferrückstand-Einstellung ab.
			$qty     = (int) floor( $stock );
			$changed = (int) $product->get_stock_quantity() !== $qty;
			if ( 'onbackorder' === $status && 'no' === $product->get_backorders() ) {
				$product->set_backorders( 'notify' );
				$changed = true;
			}
			if ( ! $changed && $product->get_stock_status() === $status ) {
				return 'unchanged';
			}
			$product->set_stock_quantity( $qty );
		} else {
			if ( $product->get_stock_status() === $status ) {
				return 'unchanged';
			}
			$product->set_stock_status( $status );
		}

		$product->save();

		if ( $product->get_stock_status() !== $status ) {
			bws_log( 'warning', sprintf( 'Lagerimport: Produkt #%d hat Status "%s" statt "%s" (Schwelle "Nicht vorrätig" in WooCommerce prüfen).', $product->get_id(), $product->get_stock_status(), $status ) );
		}

		return 'instock' === $status ? 'instock' : 'backorder';
	}

	/**
	 * WooCommerce-Produkt zu einem bexio-Artikel: zuerst per SKU, dann per gespeicherter Artikel-ID.
	 *
	 * @param array $article bexio-Artikel.
	 * @param array $by_id   bexio Artikel-ID => Produkt-ID.
	 * @return WC_Product|null
	 */
	private static function find_product( array $article, array $by_id ) {
		$product_id = 0;
		if ( ! empty( $article['intern_code'] ) ) {
			$product_id = wc_get_product_id_by_sku( $article['intern_code'] );
		}
		if ( ! $product_id && isset( $article['id'], $by_id[ (int) $article['id'] ] ) ) {
			$product_id = $by_id[ (int) $article['id'] ];
		}
		$product = $product_id ? wc_get_product( $product_id ) : null;
		return $product ? $product : null;
	}

	/**
	 * Alle bereits verknüpften Produkte (Meta _bws_article_id).
	 *
	 * @return array<int,int>
	 */
	private static function products_by_article_id() {
		global $wpdb;
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT pm.post_id, pm.meta_value FROM {$wpdb->postmeta} pm
				INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id AND p.post_status <> 'trash'
				WHERE pm.meta_key = %s",
				BWS_Product_Sync::META_ARTICLE_ID
			)
		);
		$map = array();
		foreach ( $rows as $row ) {
			$map[ (int) $row->meta_value ] = (int) $row->post_id;
		}
		return $map;
	}
}
