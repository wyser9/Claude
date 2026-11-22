<?php
defined( 'ABSPATH' ) || exit;

/**
 * Sammel-Labels: In der Bestellübersicht mehrere Bestellungen markieren → „Post-Labels erstellen“.
 * Gewicht, Signature und Sperrgut werden wie in der Bestell-Box vorgeschlagen; danach gibt es alle Labels
 * in einem PDF zum Drucken (Papier „A4, oberes Drittel“).
 */
class APL_Bulk {

	const ACTION = 'apl_create_labels';
	/** Höchstdauer eines Durchgangs in Sekunden; der Rest wird als „nicht verarbeitet“ gemeldet. */
	const TIME_BUDGET = 20;

	public static function init() {
		foreach ( array( 'bulk_actions-edit-shop_order', 'bulk_actions-woocommerce_page_wc-orders' ) as $hook ) {
			add_filter( $hook, array( __CLASS__, 'add_action' ) );
		}
		foreach ( array( 'handle_bulk_actions-edit-shop_order', 'handle_bulk_actions-woocommerce_page_wc-orders' ) as $hook ) {
			add_filter( $hook, array( __CLASS__, 'handle' ), 10, 3 );
		}
		add_action( 'admin_notices', array( __CLASS__, 'notice' ) );
		add_action( 'admin_post_apl_download_bulk', array( __CLASS__, 'download' ) );
	}

	public static function add_action( $actions ) {
		$actions[ self::ACTION ] = 'Post-Labels erstellen';
		return $actions;
	}

	/**
	 * Sammelaktion ausführen.
	 *
	 * @param string $redirect Ziel.
	 * @param string $action   Aktion.
	 * @param int[]  $ids      Bestell-IDs.
	 * @return string
	 */
	public static function handle( $redirect, $action, $ids ) {
		if ( self::ACTION !== $action || ! current_user_can( 'edit_shop_orders' ) ) {
			return $redirect;
		}
		$result = self::run( array_map( 'absint', (array) $ids ) );
		$key    = wp_generate_password( 12, false );
		set_transient( 'apl_bulk_' . $key, $result, DAY_IN_SECONDS );
		return add_query_arg( 'apl_bulk', $key, remove_query_arg( array( 'apl_bulk' ), $redirect ) );
	}

	/**
	 * Labels für mehrere Bestellungen erstellen.
	 *
	 * @param int[] $ids Bestell-IDs.
	 * @return array{created:int[],skipped:array,rest:string[],preview:bool}
	 */
	public static function run( array $ids ) {
		if ( function_exists( 'set_time_limit' ) ) {
			@set_time_limit( 120 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
		}
		$start   = time();
		$preview = 'yes' === get_option( 'armatum_post_test_mode', 'yes' );
		$out     = array(
			'created' => array(),
			'skipped' => array(),
			'rest'    => array(),
			'preview' => $preview,
		);
		sort( $ids );
		foreach ( $ids as $id ) {
			$order = wc_get_order( $id );
			if ( ! $order ) {
				continue;
			}
			$nr = '#' . $order->get_order_number();
			if ( time() - $start >= self::TIME_BUDGET ) {
				$out['rest'][] = $nr;
				continue;
			}
			$reason = self::skip_reason( $order, $preview );
			if ( $reason ) {
				$out['skipped'][] = array( $nr, $reason );
				continue;
			}
			$names = APL_Label::shipping_names( $order );
			try {
				APL_Label::create( $order, APL_Label::order_weight_g( $order ), APL_Util::suggest_signature( $names ), APL_Util::suggest_bulky( $names ) );
				$out['created'][] = $order->get_id();
			} catch ( Exception $e ) {
				$order->add_order_note( 'Post-Label nicht erstellt (Sammelaktion): ' . $e->getMessage() );
				apl_log( 'error', sprintf( 'Bestellung %s (Sammelaktion): %s', $nr, $e->getMessage() ) );
				$out['skipped'][] = array( $nr, $e->getMessage() );
			}
		}
		return $out;
	}

	/**
	 * Grund, warum eine Bestellung übersprungen wird ('' = Label erstellen).
	 *
	 * @param WC_Order $order   Bestellung.
	 * @param bool     $preview Testmodus.
	 * @return string
	 */
	public static function skip_reason( WC_Order $order, $preview ) {
		if ( APL_Label::is_pickup( $order ) ) {
			return 'Abholung – kein Label nötig';
		}
		if ( $order->has_status( array( 'cancelled', 'refunded', 'failed', 'pending' ) ) ) {
			return 'Status „' . wc_get_order_status_name( $order->get_status() ) . '“';
		}
		// Ein vorhandenes echtes Label nicht doppelt erstellen (ein Testlabel darf im Echtbetrieb ersetzt werden).
		if ( $order->get_meta( APL_Label::META_IDENT ) && ( 'yes' !== $order->get_meta( APL_Label::META_PREVIEW ) || $preview ) ) {
			return 'hat bereits ein Label (' . $order->get_meta( APL_Label::META_IDENT ) . ') – bei Bedarf in der Bestellung neu erstellen';
		}
		foreach ( APL_Label::shipping_names( $order ) as $name ) {
			if ( preg_match( '/brief/i', $name ) ) {
				return 'Versandart „' . $name . '“ (Brief) wird nicht unterstützt';
			}
		}
		if ( ! APL_Label::order_weight_g( $order ) ) {
			return 'Gewicht fehlt bei den Produkten – in der Bestellung von Hand eintragen';
		}
		return '';
	}

	/**
	 * Ergebnis über der Bestellübersicht.
	 */
	public static function notice() {
		$key = isset( $_GET['apl_bulk'] ) ? sanitize_key( wp_unslash( $_GET['apl_bulk'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification
		if ( ! $key || ! current_user_can( 'edit_shop_orders' ) ) {
			return;
		}
		$r = get_transient( 'apl_bulk_' . $key );
		if ( ! is_array( $r ) ) {
			return;
		}
		$created = count( $r['created'] );
		echo '<div class="notice ' . ( $created ? 'notice-success' : 'notice-warning' ) . '"><p><strong>Post-Labels:</strong> ';
		printf( '%d erstellt', (int) $created );
		if ( $r['preview'] ) {
			echo ' <strong style="color:#b32d2e">(Testmodus – SPECIMEN, nicht versandfähig)</strong>';
		}
		echo '.</p>';
		if ( $created ) {
			$url = wp_nonce_url( admin_url( 'admin-post.php?action=apl_download_bulk&key=' . $key ), 'apl_bulk_' . $key );
			printf( '<p><a class="button button-primary" href="%s" target="_blank">Alle %d Labels drucken (PDF)</a> <span class="description">Drucken mit „Tatsächliche Grösse / 100 %%“.</span></p>', esc_url( $url ), (int) $created );
		}
		if ( $r['skipped'] ) {
			echo '<p>Übersprungen:</p><ul style="list-style:disc;margin-left:20px">';
			foreach ( $r['skipped'] as $s ) {
				printf( '<li>%s: %s</li>', esc_html( $s[0] ), esc_html( $s[1] ) );
			}
			echo '</ul>';
		}
		if ( $r['rest'] ) {
			printf( '<p>Aus Zeitgründen noch nicht verarbeitet: %s – bitte diese Bestellungen erneut auswählen.</p>', esc_html( implode( ', ', $r['rest'] ) ) );
		}
		echo '</div>';
	}

	/**
	 * Alle Labels einer Sammelaktion als ein PDF.
	 */
	public static function download() {
		$key = isset( $_GET['key'] ) ? sanitize_key( wp_unslash( $_GET['key'] ) ) : '';
		if ( ! current_user_can( 'edit_shop_orders' ) || ! $key ) {
			wp_die( 'Keine Berechtigung.' );
		}
		check_admin_referer( 'apl_bulk_' . $key );
		$r = get_transient( 'apl_bulk_' . $key );
		if ( ! is_array( $r ) || empty( $r['created'] ) ) {
			wp_die( 'Sammelaktion abgelaufen – Labels bitte einzeln in den Bestellungen öffnen.' );
		}
		$pdfs = array();
		foreach ( $r['created'] as $id ) {
			$order = wc_get_order( $id );
			$file  = $order ? basename( (string) $order->get_meta( APL_Label::META_FILE ) ) : '';
			$path  = trailingslashit( APL_Label::protected_dir() ) . $file;
			if ( $file && is_file( $path ) ) {
				$pdfs[] = (string) file_get_contents( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions
			}
		}
		$merged = APL_Util::merge_label_pdfs( $pdfs );
		if ( null === $merged ) {
			wp_die( 'Die Labels liegen im Format „A6-PDF der Post“ vor und können nicht zusammengefügt werden. Bitte einzeln in den Bestellungen öffnen oder unter Einstellungen > Armatum Post Label auf „A4, oberes Drittel“ umstellen.' );
		}
		nocache_headers();
		header( 'Content-Type: application/pdf' );
		header( 'Content-Disposition: inline; filename="post-labels-' . gmdate( 'Y-m-d' ) . '.pdf"' );
		header( 'Content-Length: ' . strlen( $merged ) );
		echo $merged; // phpcs:ignore WordPress.Security.EscapeOutput
		exit;
	}
}
