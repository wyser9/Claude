<?php
defined( 'ABSPATH' ) || exit;

/**
 * Artikel- und Lagerbestands-Synchronisation WooCommerce -> bexio.
 *
 * WooCommerce ist das führende System: Jedes einfache Produkt und jede Variante wird als
 * bexio-Artikel geführt. Die Zuordnung erfolgt über die Artikel-ID (Meta "_bws_article_id"),
 * beim ersten Abgleich über SKU = bexio "Artikel-Nr." (intern_code), damit bereits in bexio
 * erfasste Artikel nicht doppelt angelegt werden.
 */
class BWS_Product_Sync {

	const META_ARTICLE_ID = '_bws_article_id';
	const META_SYNCED_AT  = '_bws_synced_at';
	const META_ERROR      = '_bws_last_error';

	/** @var array<int,bool> Bereits in diesem Request eingeplante Produkte. */
	private static $queued = array();

	/** @var bool Während des Lagerimports aus bexio keine Rück-Übertragung auslösen. */
	public static $suspended = false;

	/** @var array<int,bool> Produkte, die gerade nur wegen einer Bestandsänderung gespeichert werden. */
	private static $stock_only = array();

	public static function init() {
		add_action( 'woocommerce_new_product', array( __CLASS__, 'on_product_change' ), 20, 1 );
		add_action( 'woocommerce_update_product', array( __CLASS__, 'on_product_change' ), 20, 1 );
		add_action( 'woocommerce_new_product_variation', array( __CLASS__, 'on_product_change' ), 20, 1 );
		add_action( 'woocommerce_update_product_variation', array( __CLASS__, 'on_product_change' ), 20, 1 );

		// wc_update_product_stock() speichert das Produkt (z.B. bei jedem Verkauf). Diese reinen
		// Bestandsänderungen sollen keinen kompletten Artikelabgleich auslösen.
		add_action( 'woocommerce_product_before_set_stock', array( __CLASS__, 'before_stock_change' ), 10, 1 );
		add_action( 'woocommerce_variation_before_set_stock', array( __CLASS__, 'before_stock_change' ), 10, 1 );
		add_action( 'woocommerce_product_set_stock', array( __CLASS__, 'on_stock_change' ), 20, 1 );
		add_action( 'woocommerce_variation_set_stock', array( __CLASS__, 'on_stock_change' ), 20, 1 );

		add_action( 'bws_sync_product', array( __CLASS__, 'run_job' ), 10, 1 );
		add_action( 'bws_bulk_sync_products', array( __CLASS__, 'run_bulk_job' ), 10, 1 );
	}

	public static function before_stock_change( $product ) {
		if ( $product instanceof WC_Product ) {
			self::$stock_only[ $product->get_id() ] = true;
		}
	}

	public static function on_product_change( $product_id ) {
		if ( BWS_Settings::enabled( 'sync_products' ) && ! self::$suspended && ! isset( self::$stock_only[ (int) $product_id ] ) ) {
			self::enqueue( (int) $product_id );
		}
	}

	public static function on_stock_change( $product ) {
		if ( $product instanceof WC_Product ) {
			unset( self::$stock_only[ $product->get_id() ] );
		}
		if ( 'to_bexio' === BWS_Settings::stock_mode() && ! self::$suspended && $product instanceof WC_Product ) {
			self::enqueue( $product->get_id() );
		}
	}

	/**
	 * Plant den Abgleich eines Produkts im Hintergrund (Action Scheduler) ein.
	 *
	 * @param int $product_id Produkt-/Varianten-ID.
	 */
	public static function enqueue( $product_id ) {
		if ( ! $product_id || isset( self::$queued[ $product_id ] ) || ! BWS_Settings::get( 'api_token' ) ) {
			return;
		}
		self::$queued[ $product_id ] = true;

		$product = wc_get_product( $product_id );
		if ( ! $product || ! self::is_syncable( $product ) ) {
			return;
		}

		if ( ! function_exists( 'as_enqueue_async_action' ) ) {
			self::run_job( $product_id );
			return;
		}
		// Nur wartende Jobs zählen: Läuft gerade ein Abgleich, hat er evtl. den alten Bestand gelesen.
		$pending = as_get_scheduled_actions(
			array(
				'hook'     => 'bws_sync_product',
				'args'     => array( $product_id ),
				'group'    => BWS_AS_GROUP,
				'status'   => ActionScheduler_Store::STATUS_PENDING,
				'per_page' => 1,
			),
			'ids'
		);
		if ( ! $pending ) {
			as_enqueue_async_action( 'bws_sync_product', array( $product_id ), BWS_AS_GROUP );
		}
	}

	/**
	 * Hintergrund-Job.
	 *
	 * @param int $product_id Produkt-ID.
	 * @throws BWS_Exception Damit der Action Scheduler den Job als fehlgeschlagen markiert.
	 */
	public static function run_job( $product_id ) {
		$product = wc_get_product( (int) $product_id );
		if ( ! $product ) {
			return;
		}
		try {
			self::sync( $product );
		} catch ( BWS_Exception $e ) {
			bws_log( 'error', sprintf( 'Produkt #%d: %s', $product_id, $e->getMessage() ) );
			update_post_meta( $product->get_id(), self::META_ERROR, $e->getMessage() );
			throw $e;
		}
	}

	/**
	 * Alle Produkte in Paketen à 50 abgleichen.
	 *
	 * @param int $page Seite (beginnend bei 1).
	 */
	public static function run_bulk_job( $page = 1 ) {
		$ids = self::syncable_ids( (int) $page, 50 );
		foreach ( $ids as $id ) {
			self::enqueue( $id );
		}
		if ( count( $ids ) === 50 && function_exists( 'as_enqueue_async_action' ) ) {
			as_enqueue_async_action( 'bws_bulk_sync_products', array( (int) $page + 1 ), BWS_AS_GROUP );
		}
	}

	/**
	 * IDs aller abgleichbaren Produkte (einfache Produkte + Varianten, keine variablen Eltern).
	 *
	 * @param int $page     Seite.
	 * @param int $per_page Anzahl.
	 * @return int[]
	 */
	public static function syncable_ids( $page, $per_page ) {
		return wc_get_products(
			array(
				'type'     => array_merge( array( 'variation' ), array_diff( array_keys( wc_get_product_types() ), array( 'variable', 'grouped', 'external' ) ) ),
				'status'   => array( 'publish', 'private' ),
				'limit'    => $per_page,
				'page'     => $page,
				'orderby'  => 'ID',
				'order'    => 'ASC',
				'return'   => 'ids',
			)
		);
	}

	/**
	 * Ob ein Produkt als bexio-Artikel geführt wird.
	 *
	 * @param WC_Product $product Produkt.
	 * @return bool
	 */
	public static function is_syncable( WC_Product $product ) {
		return ! $product->is_type( array( 'variable', 'grouped', 'external' ) );
	}

	/**
	 * Gleicht ein Produkt mit bexio ab und liefert die bexio Artikel-ID.
	 *
	 * @param WC_Product $product Produkt.
	 * @return int|null bexio Artikel-ID oder null, wenn das Produkt nicht abgeglichen wird.
	 * @throws BWS_Exception Bei Fehlern.
	 */
	public static function sync( WC_Product $product ) {
		if ( ! self::is_syncable( $product ) ) {
			return null;
		}

		$client     = BWS_Client::instance();
		$payload    = self::build_payload( $product );
		$article_id = (int) $product->get_meta( self::META_ARTICLE_ID, true );

		if ( ! $article_id ) {
			$article_id = self::find_article_id( $payload['intern_code'] );
		}

		if ( $article_id ) {
			try {
				// bexio akzeptiert die Artikelart nur beim Anlegen, beim Bearbeiten führt sie zu Fehler 422.
				$update = $payload;
				unset( $update['article_type_id'] );
				$result = $client->post( '2.0/article/' . $article_id, $update );
			} catch ( BWS_Exception $e ) {
				if ( 404 !== $e->getCode() ) {
					throw $e;
				}
				// In bexio gelöscht -> neu anlegen.
				$result = $client->post( '2.0/article', $payload );
			}
		} else {
			$result = $client->post( '2.0/article', $payload );
		}

		if ( empty( $result['id'] ) ) {
			throw new BWS_Exception( 'Unerwartete Antwort von bexio beim Speichern des Artikels ' . $payload['intern_code'] );
		}
		$article_id = (int) $result['id'];

		if ( isset( $payload['stock_nr'], $result['stock_nr'] ) && (float) $result['stock_nr'] !== (float) $payload['stock_nr'] ) {
			bws_log(
				'warning',
				sprintf(
					'Artikel %s: bexio hat den Lagerbestand nicht übernommen (gesendet %s, bexio %s). Prüfen Sie, ob der Artikel in bexio als Lagerartikel mit Lagerort geführt wird.',
					$payload['intern_code'],
					$payload['stock_nr'],
					$result['stock_nr']
				)
			);
		}

		// Direkt in die Post-Meta schreiben, damit kein erneuter "update_product"-Hook ausgelöst wird.
		update_post_meta( $product->get_id(), self::META_ARTICLE_ID, $article_id );
		update_post_meta( $product->get_id(), self::META_SYNCED_AT, time() );
		delete_post_meta( $product->get_id(), self::META_ERROR );
		$product->update_meta_data( self::META_ARTICLE_ID, $article_id );

		bws_log( 'info', sprintf( 'Produkt #%d -> bexio Artikel %d (%s)', $product->get_id(), $article_id, $payload['intern_code'] ) );

		return $article_id;
	}

	/**
	 * Liefert die bexio Artikel-ID: gespeicherte Verknüpfung, sonst Suche per SKU, sonst (optional) Neuanlage.
	 *
	 * @param WC_Product $product      Produkt.
	 * @param bool       $allow_create Artikel anlegen, wenn in bexio keiner mit dieser SKU existiert.
	 * @return int|null
	 */
	public static function ensure_article( WC_Product $product, $allow_create = true ) {
		$article_id = (int) $product->get_meta( self::META_ARTICLE_ID, true );
		if ( $article_id ) {
			return $article_id;
		}

		// Bestehenden bexio-Artikel nur verknüpfen, nicht überschreiben (bexio-Daten bleiben unverändert).
		$sku = trim( (string) $product->get_sku() );
		if ( '' !== $sku ) {
			$article_id = self::find_article_id( $sku );
			if ( $article_id ) {
				update_post_meta( $product->get_id(), self::META_ARTICLE_ID, $article_id );
				$product->update_meta_data( self::META_ARTICLE_ID, $article_id );
				bws_log( 'info', sprintf( 'Produkt #%d mit bestehendem bexio-Artikel %d (%s) verknüpft.', $product->get_id(), $article_id, $sku ) );
				return $article_id;
			}
		}

		return $allow_create ? self::sync( $product ) : null;
	}

	/**
	 * Sucht einen bexio-Artikel anhand der Artikel-Nr.
	 *
	 * @param string $intern_code Artikel-Nr.
	 * @return int|null
	 */
	public static function find_article_id( $intern_code ) {
		$hits = BWS_Client::instance()->search( '2.0/article/search', 'intern_code', $intern_code );
		foreach ( $hits as $hit ) {
			if ( isset( $hit['intern_code'] ) && (string) $hit['intern_code'] === (string) $intern_code ) {
				return (int) $hit['id'];
			}
		}
		return null;
	}

	/**
	 * Artikel-Nr. für bexio (SKU, sonst "WC-<ID>").
	 *
	 * @param WC_Product $product Produkt.
	 * @return string
	 * @throws BWS_Exception Wenn keine SKU vorhanden ist und kein Fallback erlaubt ist.
	 */
	public static function intern_code( WC_Product $product ) {
		$sku = trim( (string) $product->get_sku() );
		if ( '' !== $sku ) {
			return BWS_Util::truncate( $sku, 255 );
		}
		if ( ! BWS_Settings::enabled( 'sku_fallback' ) ) {
			throw new BWS_Exception( sprintf( 'Produkt #%d hat keine Artikelnummer (SKU).', $product->get_id() ) );
		}
		return 'WC-' . $product->get_id();
	}

	/**
	 * Steuersatz des Produkts (Basis-Steuersatz des Shops) in Prozent.
	 *
	 * @param WC_Product $product Produkt.
	 * @return float
	 */
	public static function tax_rate( WC_Product $product ) {
		if ( ! wc_tax_enabled() || 'none' === $product->get_tax_status() ) {
			return 0.0;
		}
		$rate = 0.0;
		foreach ( WC_Tax::get_base_tax_rates( $product->get_tax_class() ) as $tax ) {
			$rate += (float) $tax['rate'];
		}
		return round( $rate, 2 );
	}

	/**
	 * Baut die bexio-Artikeldaten.
	 *
	 * @param WC_Product $product Produkt.
	 * @return array
	 */
	public static function build_payload( WC_Product $product ) {
		$name = $product->get_name();
		if ( $product->is_type( 'variation' ) ) {
			$attributes = wc_get_formatted_variation( $product, true, false, false );
			if ( $attributes && false === strpos( $name, $attributes ) ) {
				$name .= ' – ' . $attributes;
			}
		}

		$description = $product->get_short_description();
		if ( '' === trim( $description ) ) {
			$description = $product->get_description();
		}
		if ( '' === trim( $description ) && $product->is_type( 'variation' ) ) {
			$parent      = wc_get_product( $product->get_parent_id() );
			$description = $parent ? $parent->get_short_description() : '';
		}

		$payload = array(
			'user_id'           => BWS_Lookup::user_id(),
			'article_type_id'   => $product->is_virtual() ? 2 : 1, // 1 = physisch, 2 = Dienstleistung.
			'intern_code'       => self::intern_code( $product ),
			'intern_name'       => BWS_Util::truncate( wp_strip_all_tags( $name ), 255 ),
			'intern_description' => wp_strip_all_tags( $description ),
			'sale_price'        => BWS_Util::amount( (float) $product->get_regular_price(), 2 ),
			'tax_income_id'     => BWS_Lookup::tax_id_for_rate( self::tax_rate( $product ) ),
		);

		if ( BWS_Settings::id( 'unit_id' ) ) {
			$payload['unit_id'] = BWS_Settings::id( 'unit_id' );
		}
		if ( BWS_Settings::id( 'account_id' ) ) {
			$payload['account_id'] = BWS_Settings::id( 'account_id' );
		}
		if ( $product->get_weight() ) {
			// bexio akzeptiert nur ganze Gramm ("weight: 154.5 is not an integer").
			$payload['weight'] = (int) round( (float) wc_get_weight( $product->get_weight(), 'g' ) );
		}

		$manages_stock = $product->managing_stock() && ! $product->is_virtual();
		// Bestand nur senden, wenn WooCommerce das Lager führt – sonst würde der bexio-Bestand überschrieben.
		if ( $manages_stock && 'to_bexio' === BWS_Settings::stock_mode() ) {
			$payload['is_stock'] = true;
			$payload['stock_nr'] = max( 0, (int) $product->get_stock_quantity() );
			if ( BWS_Settings::id( 'stock_id' ) ) {
				$payload['stock_id'] = BWS_Settings::id( 'stock_id' );
			}
			if ( BWS_Settings::id( 'stock_place_id' ) ) {
				$payload['stock_place_id'] = BWS_Settings::id( 'stock_place_id' );
			}
		}

		/**
		 * Artikeldaten vor dem Senden an bexio anpassen.
		 *
		 * @param array      $payload Daten für POST /2.0/article.
		 * @param WC_Product $product Produkt.
		 */
		return apply_filters( 'bws_article_payload', $payload, $product );
	}
}
