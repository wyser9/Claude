<?php
defined( 'ABSPATH' ) || exit;

/**
 * Zugriff auf die Plugin-Einstellungen (Option "bws_settings").
 */
class BWS_Settings {

	const OPTION = 'bws_settings';

	/**
	 * Standardwerte.
	 *
	 * @return array
	 */
	public static function defaults() {
		return array(
			'api_token'          => '',
			'sync_products'      => 'yes',
			'stock_mode'         => 'from_bexio', // from_bexio | to_bexio | off.
			'stock_import_time'  => '03:00',
			'stock_field'        => 'stock_nr', // stock_nr | stock_available_nr.
			'sync_orders'        => 'yes',
			'order_statuses'     => array( 'processing', 'completed' ),
			'price_mode'         => function_exists( 'wc_prices_include_tax' ) && wc_prices_include_tax() ? 'gross' : 'net',
			'sku_fallback'       => 'yes',
			'user_id'            => '',
			'unit_id'            => '',
			'stock_id'           => '',
			'stock_place_id'     => '',
			'account_id'         => '',
			'default_tax_id'     => '',
			'contact_group_id'   => '',
			'language_id'        => '',
			'order_title_prefix' => 'WooCommerce Bestellung',
			'update_contacts'    => 'no',
		);
	}

	/**
	 * Alle Einstellungen.
	 *
	 * @return array
	 */
	public static function all() {
		$saved = get_option( self::OPTION, array() );
		return wp_parse_args( is_array( $saved ) ? $saved : array(), self::defaults() );
	}

	/**
	 * Einzelne Einstellung.
	 *
	 * @param string $key     Schlüssel.
	 * @param mixed  $default Fallback.
	 * @return mixed
	 */
	public static function get( $key, $default = null ) {
		// Token kann alternativ per wp-config.php gesetzt werden (empfohlen).
		if ( 'api_token' === $key && defined( 'BEXIO_API_TOKEN' ) && BEXIO_API_TOKEN ) {
			return BEXIO_API_TOKEN;
		}
		$all = self::all();
		return array_key_exists( $key, $all ) ? $all[ $key ] : $default;
	}

	/**
	 * Ja/Nein-Einstellung.
	 *
	 * @param string $key Schlüssel.
	 * @return bool
	 */
	public static function enabled( $key ) {
		return 'yes' === self::get( $key );
	}

	/**
	 * Richtung des Lagerabgleichs: "from_bexio" (bexio führt), "to_bexio" (WooCommerce führt) oder "off".
	 *
	 * @return string
	 */
	public static function stock_mode() {
		$mode = self::get( 'stock_mode' );
		return in_array( $mode, array( 'from_bexio', 'to_bexio', 'off' ), true ) ? $mode : 'from_bexio';
	}

	/**
	 * Ganzzahlige ID-Einstellung oder null.
	 *
	 * @param string $key Schlüssel.
	 * @return int|null
	 */
	public static function id( $key ) {
		$value = self::get( $key );
		return ( '' === $value || null === $value ) ? null : (int) $value;
	}

	/**
	 * Speichert Einstellungen.
	 *
	 * @param array $values Werte.
	 */
	public static function save( array $values ) {
		update_option( self::OPTION, array_merge( self::all(), $values ), false );
	}
}
