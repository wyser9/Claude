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
	const OPTION_STATE = 'bws_stock_import_state';
	const HOOK_STEP    = 'bws_import_stock_step';
	const PAGE         = 500;
	const STEP_SECONDS = 15; // Server bricht nach 30 s ab.

	public static function init() {
		add_action( self::HOOK, array( __CLASS__, 'run_job' ) );
		add_action( self::HOOK_STEP, array( __CLASS__, 'run_step' ) );
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
	 * Hintergrund-Job (täglich bzw. Knopf): startet den Import in Etappen.
	 */
	public static function run_job() {
		if ( 'from_bexio' !== BWS_Settings::stock_mode() ) {
			return;
		}
		self::start();
	}

	/**
	 * Startet einen Import in Etappen (jede Etappe bleibt deutlich unter dem PHP-Zeitlimit des Servers).
	 */
	public static function start() {
		update_option(
			self::OPTION_STATE,
			array(
				'phase'    => 'fetch',
				'offset'   => 0,
				'cursor'   => 0,
				'articles' => array(),
				'handled'  => array(),
				'stats'    => self::empty_stats(),
			),
			false
		);
		if ( function_exists( 'as_enqueue_async_action' ) ) {
			as_unschedule_all_actions( self::HOOK_STEP, array(), BWS_AS_GROUP );
			as_enqueue_async_action( self::HOOK_STEP, array(), BWS_AS_GROUP );
		} else {
			while ( self::step( PHP_INT_MAX ) ) {
				continue;
			}
		}
	}

	/**
	 * Eine Etappe; plant die nächste ein, solange noch Arbeit übrig ist.
	 *
	 * @throws BWS_Exception Damit der Action Scheduler die Etappe als fehlgeschlagen markiert.
	 */
	public static function run_step() {
		try {
			$more = self::step( self::STEP_SECONDS );
		} catch ( BWS_Exception $e ) {
			bws_log( 'error', 'Lagerimport aus bexio fehlgeschlagen: ' . $e->getMessage() );
			update_option( self::OPTION_LAST, array( 'time' => time(), 'error' => $e->getMessage() ), false );
			delete_option( self::OPTION_STATE );
			throw $e;
		}
		if ( $more ) {
			as_enqueue_async_action( self::HOOK_STEP, array(), BWS_AS_GROUP );
		}
	}

	/**
	 * Arbeitet höchstens $budget Sekunden am gespeicherten Import weiter.
	 *
	 * @param int $budget Sekunden.
	 * @return bool Ob noch Arbeit übrig ist.
	 */
	public static function step( $budget ) {
		$state = get_option( self::OPTION_STATE );
		if ( ! is_array( $state ) ) {
			return false;
		}
		$until = time() + $budget;

		if ( 'fetch' === $state['phase'] ) {
			do {
				$page = BWS_Client::instance()->get( '2.0/article', array( 'limit' => self::PAGE, 'offset' => $state['offset'] ) );
				$page = is_array( $page ) ? $page : array();
				foreach ( $page as $article ) {
					$state['articles'][] = self::compact( $article );
				}
				$state['offset'] += self::PAGE;
			} while ( count( $page ) === self::PAGE && $state['offset'] < 20000 && time() < $until );

			if ( count( $page ) === self::PAGE && $state['offset'] < 20000 ) {
				update_option( self::OPTION_STATE, $state, false );
				return true;
			}
			$state['phase']             = 'process';
			$state['stats']['articles'] = count( $state['articles'] );
		}

		$field = self::field();
		$ctx   = array(
			'field'      => $field,
			'by_id'      => BWS_Product_Sync::products_by_article_id(),
			'serials'    => self::serial_stock( $state['articles'], $field ),
			'serial_cat' => self::serial_category_ids(),
		);

		BWS_Product_Sync::$suspended = true;
		try {
			$total = count( $state['articles'] );
			while ( $state['cursor'] < $total ) {
				self::process_article( $state['articles'][ $state['cursor'] ], $ctx, $state['stats'], $state['handled'] );
				$state['cursor']++;
				if ( time() >= $until && $state['cursor'] < $total ) {
					update_option( self::OPTION_STATE, $state, false );
					return true;
				}
			}
			self::process_leftover_serials( $ctx, $state['stats'], $state['handled'] );
		} finally {
			BWS_Product_Sync::$suspended = false;
		}

		delete_option( self::OPTION_STATE );
		self::finish( $state['stats'] );
		return false;
	}

	/**
	 * Holt alle Artikel aus bexio und setzt den Lagerstatus in WooCommerce (in einem Durchgang, z.B. WP-CLI).
	 *
	 * @return array Zusammenfassung.
	 */
	public static function run() {
		$articles = array_map( array( __CLASS__, 'compact' ), BWS_Client::instance()->get_all( '2.0/article' ) );
		$field    = self::field();
		$ctx      = array(
			'field'      => $field,
			'by_id'      => BWS_Product_Sync::products_by_article_id(),
			'serials'    => self::serial_stock( $articles, $field ),
			'serial_cat' => self::serial_category_ids(),
		);
		$handled           = array();
		$stats             = self::empty_stats();
		$stats['articles'] = count( $articles );

		BWS_Product_Sync::$suspended = true;
		try {
			foreach ( $articles as $article ) {
				self::process_article( $article, $ctx, $stats, $handled );
			}
			self::process_leftover_serials( $ctx, $stats, $handled );
		} finally {
			BWS_Product_Sync::$suspended = false;
		}

		self::finish( $stats );
		return $stats;
	}

	private static function field() {
		return 'stock_available_nr' === BWS_Settings::get( 'stock_field' ) ? 'stock_available_nr' : 'stock_nr';
	}

	private static function empty_stats() {
		return array(
			'time'      => time(),
			'articles'  => 0,
			'serial'    => 0,
			'instock'   => 0,
			'backorder' => 0,
			'unchanged' => 0,
			'not_found' => 0,
			'skipped'   => 0,
			'errors'    => 0,
		);
	}

	/**
	 * Nur die für den Import nötigen Felder (hält den gespeicherten Zwischenstand klein).
	 *
	 * @param array $article bexio-Artikel.
	 * @return array
	 */
	public static function compact( $article ) {
		$keep = array( 'id', 'intern_code', 'is_stock', 'stock_nr', 'stock_available_nr', 'deliverer_code' );
		return array_intersect_key( (array) $article, array_flip( $keep ) );
	}

	/**
	 * Verarbeitet einen bexio-Artikel.
	 *
	 * @param array $article bexio-Artikel.
	 * @param array $ctx     field, by_id, serials, serial_cat.
	 * @param array $stats   Zusammenfassung (wird ergänzt).
	 * @param array $handled Bereits behandelte Hauptartikel-Nummern (wird ergänzt).
	 */
	private static function process_article( array $article, array $ctx, array &$stats, array &$handled ) {
		$field      = $ctx['field'];
		$serials    = $ctx['serials'];
		$serial_cat = $ctx['serial_cat'];
		$code       = trim( (string) ( $article['intern_code'] ?? '' ) );
		$serial     = $serials[ $code ]['stock'] ?? 0;

		$is_stock = ! empty( $article['is_stock'] ) && isset( $article[ $field ] );
		$product  = self::find_product( $article, $ctx['by_id'] );

		// Nur Lagerartikel – bei anderen Artikeln ist der Bestand in bexio immer 0.
		// Ausnahme: Hauptartikel der Seriennummer-Kategorie (Waffen), deren Bestand aus den Kopien kommt.
		if ( ! $is_stock && ! ( $product && BWS_Product_Sync::in_category_tree( $product, $serial_cat ) ) ) {
			$stats['skipped']++;
			return;
		}
		if ( ! $product ) {
			$stats['not_found']++;
			return;
		}
		$own = empty( $article['is_stock'] ) ? 0.0 : (float) ( $article[ $field ] ?? 0 );
		if ( $serial && ! BWS_Product_Sync::in_category_tree( $product, $serial_cat ) ) {
			bws_log( 'notice', sprintf( 'Lagerimport: Artikel %s hat Kopien mit Produktcode Lieferant (%s), ist aber nicht in der Seriennummer-Kategorie – ignoriert.', $code, implode( ', ', $serials[ $code ]['codes'] ) ) );
			$serial = 0;
			if ( empty( $article['is_stock'] ) ) {
				$stats['skipped']++;
				return;
			}
		}
		$handled[ $code ] = true;
		if ( $product->is_type( array( 'variable', 'grouped', 'external' ) ) ) {
			bws_log( 'notice', sprintf( 'Lagerimport: Artikel %s gehört zu einem %s-Produkt (#%d) – Status wird dort aus den Varianten berechnet, übersprungen.', $code, $product->get_type(), $product->get_id() ) );
			$stats['skipped']++;
			return;
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
			bws_log( 'error', sprintf( 'Lagerimport: Produkt #%d (%s): %s', $product->get_id(), $code, $e->getMessage() ) );
		}
	}

	/**
	 * Hauptartikel, die in bexio nicht (mehr) existieren, aber Seriennummer-Kopien haben.
	 *
	 * @param array $ctx     Kontext.
	 * @param array $stats   Zusammenfassung.
	 * @param array $handled Bereits behandelte Hauptartikel-Nummern.
	 */
	private static function process_leftover_serials( array $ctx, array &$stats, array $handled ) {
		foreach ( $ctx['serials'] as $code => $info ) {
			if ( isset( $handled[ $code ] ) ) {
				continue;
			}
			$product_id = wc_get_product_id_by_sku( $code );
			$product    = $product_id ? wc_get_product( $product_id ) : null;
			if ( ! $product || $product->is_type( array( 'variable', 'grouped', 'external' ) ) || ! BWS_Product_Sync::in_category_tree( $product, $ctx['serial_cat'] ) ) {
				continue;
			}
			$result = self::apply( $product, $info['stock'] );
			$stats[ $result ]++;
			$stats['serial']++;
		}
	}

	private static function finish( array $stats ) {
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
	 * Kategorien der Artikel mit Seriennummer-Kopien (inkl. Unterkategorien).
	 * Einstellung, sonst automatisch "Waffen", "Waffenzubehör" und Kategorien mit "Laser" im Namen.
	 *
	 * @return int[]
	 */
	public static function serial_category_ids() {
		$configured = array_filter( array_map( 'intval', (array) BWS_Settings::get( 'serial_categories' ) ) );
		if ( $configured ) {
			return array_values( $configured );
		}
		$ids   = array();
		$terms = get_terms( array( 'taxonomy' => 'product_cat', 'hide_empty' => false ) );
		foreach ( is_array( $terms ) ? $terms : array() as $term ) {
			$name = function_exists( 'mb_strtolower' ) ? mb_strtolower( $term->name ) : strtolower( $term->name );
			if ( ( 0 === (int) $term->parent && in_array( $name, array( 'waffen', 'waffenzubehör' ), true ) )
				|| in_array( $term->slug, array( 'waffen', 'waffenzubehoer' ), true )
				|| false !== strpos( $name, 'laser' ) ) {
				$ids[] = (int) $term->term_id;
			}
		}
		return array_values( array_unique( $ids ) );
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
