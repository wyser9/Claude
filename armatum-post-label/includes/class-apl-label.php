<?php
defined( 'ABSPATH' ) || exit;

/**
 * Label-Erstellung, geschützte PDF-Ablage und Sendungsnummer in der Kunden-E-Mail.
 */
class APL_Label {

	const META_IDENT   = '_apl_ident_code';
	const META_FILE    = '_apl_label_file';
	const META_CREATED = '_apl_created';
	const META_PREVIEW = '_apl_preview';
	const META_INFO    = '_apl_info';

	const DIR = 'armatum-post-labels';

	public static function init() {
		add_action( 'admin_post_apl_create_label', array( __CLASS__, 'handle_create' ) );
		add_action( 'admin_post_apl_download_label', array( __CLASS__, 'handle_download' ) );
		add_action( 'woocommerce_email_order_meta', array( __CLASS__, 'email_tracking' ), 20, 3 );
	}

	/* ------------------------------------------------------------------ */
	/* Aktivierung: geschützter Ordner + Aufräumen der alten Version 0.1   */
	/* ------------------------------------------------------------------ */

	public static function activate() {
		self::protected_dir();
		self::cleanup_legacy();
		add_option( 'armatum_post_test_mode', 'yes' );
	}

	/**
	 * Entfernt die Muster-Labels und Verknüpfungen der Version 0.1
	 * (öffentlicher Ordner uploads/post-labels, Meta _armatum_post_*).
	 *
	 * @return int Anzahl gelöschter PDFs.
	 */
	public static function cleanup_legacy() {
		global $wpdb;
		$upload  = wp_upload_dir();
		$folder  = trailingslashit( $upload['basedir'] ) . 'post-labels';
		$deleted = 0;
		if ( is_dir( $folder ) ) {
			foreach ( (array) glob( $folder . '/post-label-*.pdf' ) as $file ) {
				if ( is_file( $file ) && @unlink( $file ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors
					$deleted++;
				}
			}
			$rest = array_diff( (array) scandir( $folder ), array( '.', '..' ) );
			if ( ! $rest ) {
				@rmdir( $folder ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
			}
		}

		$keys = array( '_armatum_post_tracking_number', '_armatum_post_label_pdf', '_armatum_post_weight' );
		$in   = implode( ',', array_fill( 0, count( $keys ), '%s' ) );
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->postmeta} WHERE meta_key IN ($in)", $keys ) ); // phpcs:ignore WordPress.DB
		$hpos = $wpdb->prefix . 'wc_orders_meta';
		if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $hpos ) ) === $hpos ) {
			$wpdb->query( $wpdb->prepare( "DELETE FROM {$hpos} WHERE meta_key IN ($in)", $keys ) ); // phpcs:ignore WordPress.DB
		}

		if ( $deleted ) {
			apl_log( 'info', sprintf( '%d Muster-Labels der Version 0.1 gelöscht.', $deleted ) );
		}
		update_option( 'apl_legacy_cleanup', array( 'time' => time(), 'deleted' => $deleted ), false );
		return $deleted;
	}

	/**
	 * Geschützter Ablageordner (kein Direktzugriff, zufällige Dateinamen).
	 *
	 * @return string Pfad.
	 */
	public static function protected_dir() {
		$upload = wp_upload_dir();
		$dir    = trailingslashit( $upload['basedir'] ) . self::DIR;
		if ( ! is_dir( $dir ) ) {
			wp_mkdir_p( $dir );
		}
		if ( ! file_exists( $dir . '/.htaccess' ) ) {
			file_put_contents( $dir . '/.htaccess', "Require all denied\nDeny from all\n" ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		}
		if ( ! file_exists( $dir . '/index.php' ) ) {
			file_put_contents( $dir . '/index.php', "<?php // Silence is golden.\n" ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		}
		return $dir;
	}

	/* ------------------------------------------------------------------ */
	/* Daten aus der Bestellung                                            */
	/* ------------------------------------------------------------------ */

	/**
	 * Namen der Versandarten einer Bestellung.
	 *
	 * @param WC_Order $order Bestellung.
	 * @return string[]
	 */
	public static function shipping_names( WC_Order $order ) {
		$names = array();
		foreach ( $order->get_shipping_methods() as $method ) {
			$names[] = $method->get_name();
		}
		return $names;
	}

	/**
	 * Ob die Bestellung abgeholt wird.
	 *
	 * @param WC_Order $order Bestellung.
	 * @return bool
	 */
	public static function is_pickup( WC_Order $order ) {
		foreach ( $order->get_shipping_methods() as $method ) {
			if ( 0 === strpos( (string) $method->get_method_id(), 'local_pickup' ) || 'pickup_location' === $method->get_method_id() ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Gewicht der Bestellung aus den Produkten (Gramm).
	 *
	 * @param WC_Order $order Bestellung.
	 * @return int
	 */
	public static function order_weight_g( WC_Order $order ) {
		$unit  = get_option( 'woocommerce_weight_unit', 'kg' );
		$grams = 0;
		foreach ( $order->get_items() as $item ) {
			/** @var WC_Order_Item_Product $item */
			$product = $item->get_product();
			if ( $product && ! $product->is_virtual() && $product->get_weight() ) {
				$grams += APL_Util::to_grams( (float) $product->get_weight(), $unit ) * (int) $item->get_quantity();
			}
		}
		return $grams;
	}

	/**
	 * Empfänger aus Lieferadresse (Fallback Rechnungsadresse).
	 *
	 * @param WC_Order $order Bestellung.
	 * @return array
	 */
	public static function recipient( WC_Order $order ) {
		$type = $order->get_shipping_address_1() ? 'shipping' : 'billing';
		$get  = function ( $field ) use ( $order, $type ) {
			$method = "get_{$type}_{$field}";
			return trim( (string) $order->$method() );
		};

		list( $street, $house_no ) = APL_Util::split_street( $get( 'address_1' ) );
		$person                    = trim( $get( 'first_name' ) . ' ' . $get( 'last_name' ) );
		$company                   = $get( 'company' );

		$recipient = array(
			'name1'   => $company ? $company : $person,
			'street'  => $street,
			'houseNo' => $house_no,
			'zip'     => $get( 'postcode' ),
			'city'    => $get( 'city' ),
			'country' => $get( 'country' ) ? $get( 'country' ) : 'CH',
		);
		if ( $company && $person ) {
			$recipient['name2'] = $person;
		}
		if ( $get( 'address_2' ) ) {
			$recipient['addressSuffix'] = $get( 'address_2' );
		}

		return array(
			'label'     => array_filter( $recipient, 'strlen' ),
			'firstName' => $get( 'first_name' ),
			'lastName'  => $get( 'last_name' ) ? $get( 'last_name' ) : $company,
		);
	}

	/**
	 * Absender aus den Einstellungen.
	 *
	 * @return array
	 */
	public static function sender() {
		$defaults = array(
			'name1'   => 'Armatum GmbH',
			'street'  => 'Färchstrasse 6b',
			'zip'     => '4629',
			'city'    => 'Fulenbach',
			'country' => 'CH',
		);
		$saved = get_option( 'armatum_post_sender', array() );
		return array_filter( wp_parse_args( is_array( $saved ) ? $saved : array(), $defaults ), 'strlen' );
	}

	/**
	 * Daten für generateAddressLabel.
	 *
	 * @param WC_Order $order     Bestellung.
	 * @param int      $grams     Gewicht.
	 * @param bool     $signature Signature.
	 * @param bool     $bulky     Sperrgut.
	 * @param bool     $preview   Testmodus (SPECIMEN).
	 * @return array
	 */
	public static function build_payload( WC_Order $order, $grams, $signature, $bulky, $preview ) {
		$recipient = self::recipient( $order )['label'];
		return array(
			'language'        => 'DE',
			'frankingLicense' => trim( (string) get_option( 'armatum_post_franking_license' ) ),
			'customer'        => self::sender(),
			'labelDefinition' => array(
				'labelLayout'     => 'A6',
				'printAddresses'  => 'RECIPIENT_AND_CUSTOMER',
				'imageFileType'   => 'PDF',
				'imageResolution' => 300,
				'printPreview'    => (bool) $preview,
			),
			'item'            => array(
				'itemID'     => 'ARM-' . $order->get_order_number() . '-' . time(),
				'recipient'  => $recipient,
				'attributes' => array(
					'przl'   => APL_Util::services( $signature, $bulky ),
					'weight' => (int) $grams,
				),
			),
		);
	}

	/* ------------------------------------------------------------------ */
	/* Label erstellen                                                     */
	/* ------------------------------------------------------------------ */

	/**
	 * Erstellt ein Label für eine Bestellung.
	 *
	 * @param WC_Order $order     Bestellung.
	 * @param int      $grams     Gewicht in Gramm.
	 * @param bool     $signature Signature.
	 * @param bool     $bulky     Sperrgut.
	 * @return string Sendungsnummer.
	 * @throws Exception Bei Fehlern.
	 */
	public static function create( WC_Order $order, $grams, $signature, $bulky ) {
		if ( self::is_pickup( $order ) ) {
			throw new Exception( 'Die Bestellung wird abgeholt – kein Post-Label nötig.' );
		}
		$recipient = self::recipient( $order );
		if ( 'CH' !== strtoupper( $recipient['label']['country'] ) && 'LI' !== strtoupper( $recipient['label']['country'] ) ) {
			throw new Exception( 'Nur Lieferungen in die Schweiz und nach Liechtenstein werden unterstützt.' );
		}
		if ( empty( $recipient['label']['street'] ) || empty( $recipient['label']['zip'] ) || empty( $recipient['label']['city'] ) ) {
			throw new Exception( 'Die Lieferadresse ist unvollständig (Strasse, PLZ oder Ort fehlt).' );
		}
		$weight_error = APL_Util::weight_error( $grams );
		if ( $weight_error ) {
			throw new Exception( $weight_error );
		}
		if ( '' === trim( (string) get_option( 'armatum_post_franking_license' ) ) ) {
			throw new Exception( 'Keine Frankierlizenz hinterlegt (Einstellungen > Armatum Post Label).' );
		}

		$check = APL_Api::validate_address(
			array(
				'firstName' => $recipient['firstName'],
				'lastName'  => $recipient['lastName'],
				'street'    => $recipient['label']['street'],
				'houseNo'   => $recipient['label']['houseNo'] ?? '',
				'zip'       => $recipient['label']['zip'],
				'city'      => $recipient['label']['city'],
			)
		);
		if ( ! $check['usable'] ) {
			throw new Exception( $check['message'] . ' Bitte die Lieferadresse korrigieren.' );
		}

		$preview = 'yes' === get_option( 'armatum_post_test_mode', 'yes' );
		$result  = APL_Api::generate_label( self::build_payload( $order, $grams, $signature, $bulky, $preview ) );

		$dir  = self::protected_dir();
		$file = sprintf( 'label-%s-%s.pdf', $order->get_id(), wp_generate_password( 24, false ) );
		if ( false === file_put_contents( trailingslashit( $dir ) . $file, $result['pdf'] ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions
			throw new Exception( 'Label konnte nicht gespeichert werden (Schreibrechte im Upload-Ordner prüfen).' );
		}

		$old = (string) $order->get_meta( self::META_FILE );
		if ( $old && $old !== $file ) {
			@unlink( trailingslashit( $dir ) . basename( $old ) ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
		}

		$services = implode( ', ', array_map( array( __CLASS__, 'service_label' ), APL_Util::services( $signature, $bulky ) ) );
		$order->update_meta_data( self::META_IDENT, $result['ident_code'] );
		$order->update_meta_data( self::META_FILE, $file );
		$order->update_meta_data( self::META_CREATED, time() );
		$order->update_meta_data( self::META_PREVIEW, $preview ? 'yes' : 'no' );
		$order->update_meta_data( self::META_INFO, sprintf( '%s · %s kg', $services, number_format( $grams / 1000, 3, '.', '' ) ) );
		$order->add_order_note(
			sprintf(
				'%s erstellt: %s · %s kg · Sendungsnummer %s. %s',
				$preview ? 'Post-TESTLABEL (SPECIMEN, nicht versandfähig)' : 'Post-Label',
				$services,
				number_format( $grams / 1000, 3, '.', '' ),
				$result['ident_code'],
				$check['message']
			)
		);
		$order->save();

		apl_log( 'info', sprintf( 'Bestellung #%s: Label %s (%s)%s', $order->get_order_number(), $result['ident_code'], $services, $preview ? ' [Testmodus]' : '' ) );
		return $result['ident_code'];
	}

	public static function service_label( $code ) {
		$map = array(
			'ECO' => 'PostPac Economy',
			'SI'  => 'Signature',
			'SP'  => 'Sperrgut',
		);
		return $map[ $code ] ?? $code;
	}

	/**
	 * Formular-Handler "Post-Label erstellen" (aus der Bestell-Box).
	 */
	public static function handle_create() {
		$order_id = isset( $_POST['apl_order_id'] ) ? absint( $_POST['apl_order_id'] ) : 0;
		if ( ! current_user_can( 'edit_shop_orders' ) || ! $order_id ) {
			wp_die( 'Keine Berechtigung.' );
		}
		check_admin_referer( 'apl_create_' . $order_id, 'apl_nonce' );
		$order = wc_get_order( $order_id );
		if ( ! $order ) {
			wp_die( 'Bestellung nicht gefunden.' );
		}

		$weight_kg = isset( $_POST['apl_weight'] ) ? (float) str_replace( ',', '.', sanitize_text_field( wp_unslash( $_POST['apl_weight'] ) ) ) : 0;
		$signature = ! empty( $_POST['apl_signature'] );
		$bulky     = ! empty( $_POST['apl_bulky'] );

		try {
			self::create( $order, APL_Util::to_grams( $weight_kg, 'kg' ), $signature, $bulky );
		} catch ( Exception $e ) {
			$order->add_order_note( 'Post-Label nicht erstellt: ' . $e->getMessage() );
			apl_log( 'error', sprintf( 'Bestellung #%s: %s', $order->get_order_number(), $e->getMessage() ) );
		}

		wp_safe_redirect( $order->get_edit_order_url() );
		exit;
	}

	/**
	 * Gibt das Label-PDF nur an angemeldete Shop-Mitarbeitende aus.
	 */
	public static function handle_download() {
		$order_id = isset( $_GET['order_id'] ) ? absint( $_GET['order_id'] ) : 0;
		if ( ! current_user_can( 'edit_shop_orders' ) || ! $order_id ) {
			wp_die( 'Keine Berechtigung.' );
		}
		check_admin_referer( 'apl_download_' . $order_id );
		$order = wc_get_order( $order_id );
		$file  = $order ? basename( (string) $order->get_meta( self::META_FILE ) ) : '';
		$path  = trailingslashit( self::protected_dir() ) . $file;
		if ( ! $file || ! is_file( $path ) ) {
			wp_die( 'Label nicht gefunden.' );
		}
		nocache_headers();
		header( 'Content-Type: application/pdf' );
		header( 'Content-Disposition: inline; filename="post-label-' . $order->get_order_number() . '.pdf"' );
		header( 'Content-Length: ' . filesize( $path ) );
		readfile( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		exit;
	}

	/* ------------------------------------------------------------------ */
	/* Sendungsnummer in der E-Mail "Bestellung abgeschlossen"             */
	/* ------------------------------------------------------------------ */

	public static function email_tracking( $order, $sent_to_admin, $plain_text ) {
		if ( $sent_to_admin || ! $order instanceof WC_Order || ! $order->has_status( 'completed' ) ) {
			return;
		}
		$ident = (string) $order->get_meta( self::META_IDENT );
		if ( ! $ident || 'yes' === $order->get_meta( self::META_PREVIEW ) ) {
			return;
		}
		$url = APL_Util::tracking_url( $ident );
		if ( $plain_text ) {
			echo "\nSendungsverfolgung Schweizerische Post: " . esc_html( $ident ) . "\n" . esc_url_raw( $url ) . "\n";
			return;
		}
		printf(
			'<h2>Sendungsverfolgung</h2><p>Ihr Paket ist mit der Schweizerischen Post unterwegs. Sendungsnummer: <a href="%s">%s</a></p>',
			esc_url( $url ),
			esc_html( $ident )
		);
	}
}
