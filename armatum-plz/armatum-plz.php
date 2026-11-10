<?php
/**
 * Plugin Name:       Armatum PLZ → Ort
 * Description:       Füllt bei Schweizer und Liechtensteiner Adressen nach Eingabe der Postleitzahl den Ort automatisch aus (Kasse, Warenkorb-Versandrechner, Mein Konto). Mehrere Orte zur PLZ werden als Auswahl angeboten.
 * Version:           1.0.0
 * Requires at least: 6.0
 * Requires PHP:      7.4
 * Requires Plugins:  woocommerce
 * License:           GPL-2.0-or-later
 *
 * Ortsdaten: GeoNames (https://www.geonames.org/), Lizenz CC BY 4.0.
 */

defined( 'ABSPATH' ) || exit;

define( 'APLZ_VERSION', '1.0.0' );

add_action(
	'wp_enqueue_scripts',
	function () {
		if ( ! function_exists( 'is_checkout' ) || ! ( is_checkout() || is_cart() || is_account_page() ) ) {
			return;
		}
		$data = __DIR__ . '/data/plz.json';
		wp_enqueue_script( 'armatum-plz', plugins_url( 'assets/plz.js', __FILE__ ), array(), APLZ_VERSION, true );
		wp_localize_script(
			'armatum-plz',
			'armatumPlz',
			array(
				// Version im Pfad, damit Browser und Cache neue Daten nach einem Update laden.
				'url' => add_query_arg( 'v', APLZ_VERSION . '-' . ( is_file( $data ) ? filemtime( $data ) : 0 ), plugins_url( 'data/plz.json', __FILE__ ) ),
			)
		);
	}
);
