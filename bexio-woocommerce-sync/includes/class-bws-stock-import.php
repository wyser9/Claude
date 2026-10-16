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
		$by_id    = BWS_Product_Sync::products_by_article_id();
		$serials  = self::serial_stock( $articles, $field );
		$serial_cat = self::serial_category_id();
		$handled  = array();
		$stats    = array(
			'time'      => time(),
			'articles'  => count( $articles ),
			'serial'    => 0,
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
				$code   = trim( (string) ( $article['intern_code'] ?? '' ) );
				$serial = $serials[ $code ]['stock'] ?? 0;

				$is_stock = ! empty( $article['is_stock'] ) && isset( $article[ $field ] );
				$product  = self::find_product( $article, $by_id );

				// Nur Lagerartikel – bei anderen Artikeln ist der Bestand in bexio immer 0.
				// Ausnahme: Hauptartikel der Seriennummer-Kategorie (Waffen), deren Bestand aus den Kopien kommt.
				if ( ! $is_stock && ! ( $product && BWS_Product_Sync::in_category_tree( $product, $serial_cat ) ) ) {
					$stats['skipped']++;
					continue;
				}
				if ( ! $product ) {
					$stats['not_found']++;
					continue;
				}
				$own = empty( $article['is_stock'] ) ? 0.0 : (float) ( $article[ $field ] ?? 0 );
				if ( $serial && ! BWS_Product_Sync::in_category_tree( $product, $serial_cat ) ) {
					bws_log( 'notice', sprintf( 'Lagerimport: Artikel %s hat Kopien mit Produktcode Lieferant (%s), ist aber nicht in der Seriennummer-Kategorie – ignoriert.', $code, implode( ', ', $serials[ $code ]['codes'] ) ) );
					$serial = 0;
					if ( empty( $article['is_stock'] ) ) {
						$stats['skipped']++;
						continue;
					}
				}
				$handled[ $code ] = true;
				if ( $product->is_type( array( 'variable', 'grouped', 'external' ) ) ) {
					bws_log( 'notice', sprintf( 'Lagerimport: Artikel %s gehört zu einem %s-Produkt (#%d) – Status wird dort aus den Varianten berechnet, übersprungen.', $article['intern_code'], $product->get_type(), $product->get_id() ) );
					$stats['skipped']++;
					continue;
				}

				try {
					$result = self::apply( $product, $own + $serial );
					$stats[ $result ]++;
					if ( $serial ) {
						$stats['serial']++;
						bws_log( 'info', sprintf( 'Lagerimport: %s (Produkt #%d) – %s Stück über Seriennummern %s.', $code, $product->get_id(), $serial, implode( ', ', $serials[ $code ]['codes'] ) ) );
					}
				} catch ( Exception $e ) {
					$stats['errors']++;
					bws_log( 'error', sprintf( 'Lagerimport: Produkt #%d (%s): %s', $product->get_id(), $article['intern_code'], $e->getMessage() ) );
				}
			}
			// Hauptartikel, die in bexio nicht (mehr) existieren, aber Seriennummer-Kopien haben.
			foreach ( $serials as $code => $info ) {
				if ( isset( $handled[ $code ] ) ) {
					continue;
				}
				$product_id = wc_get_product_id_by_sku( $code );
				$product    = $product_id ? wc_get_product( $product_id ) : null;
				if ( ! $product || $product->is_type( array( 'variable', 'grouped', 'external' ) ) || ! BWS_Product_Sync::in_category_tree( $product, $serial_cat ) ) {
					continue;
				}
				$result = self::apply( $product, $info['stock'] );
				$stats[ $result ]++;
				$stats['serial']++;
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
	 * Bestand der Seriennummer-Kopien je Hauptartikel-Nummer.
	 *
	 * Eine Kopie ist ein bexio-Lagerartikel, dessen "Produktcode Lieferant" (deliverer_code) die
	 * Artikelnummer eines anderen Artikels trägt, z. B. Kopie "096-77116" mit Produktcode Lieferant
	 * "19442". Der bexio-Bestand wird nur gelesen, nie verändert.
	 *
	 * @param array  $articles bexio-Artikel.
	 * @param string $field    stock_nr | stock_available_nr.
	 * @return array<string,array{stock:float,codes:string[]}>
	 */
	public static function serial_stock( array $articles, $field ) {
		$main_codes = array();
		foreach ( $articles as $article ) {
			$main_codes[ trim( (string) ( $article['intern_code'] ?? '' ) ) ] = true;
		}
		$sums = array();
		foreach ( $articles as $article ) {
			$parent = trim( (string) ( $article['deliverer_code'] ?? '' ) );
			$code   = trim( (string) ( $article['intern_code'] ?? '' ) );
			$stock  = (float) ( $article[ $field ] ?? 0 );
			if ( '' === $parent || $parent === $code || empty( $article['is_stock'] ) || $stock <= 0 ) {
				continue;
			}
			// Nur zählen, wenn der Hauptartikel in bexio existiert oder es ein Shop-Produkt mit dieser SKU gibt.
			if ( ! isset( $main_codes[ $parent ] ) && ! wc_get_product_id_by_sku( $parent ) ) {
				continue;
			}
			if ( ! isset( $sums[ $parent ] ) ) {
				$sums[ $parent ] = array( 'stock' => 0.0, 'codes' => array() );
			}
			$sums[ $parent ]['stock']  += $stock;
			$sums[ $parent ]['codes'][] = $code;
		}
		return $sums;
	}

	/**
	 * Kategorie der Artikel mit Seriennummer-Kopien (Einstellung, sonst Hauptkategorie "Waffen").
	 *
	 * @return int
	 */
	public static function serial_category_id() {
		$configured = BWS_Settings::id( 'serial_category' );
		if ( $configured ) {
			return $configured;
		}
		$term = get_term_by( 'slug', 'waffen', 'product_cat' );
		if ( ! $term ) {
			$found = get_terms( array( 'taxonomy' => 'product_cat', 'name' => 'Waffen', 'parent' => 0, 'hide_empty' => false ) );
			$term  = is_array( $found ) && $found ? $found[0] : null;
		}
		return $term ? (int) $term->term_id : 0;
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
}
