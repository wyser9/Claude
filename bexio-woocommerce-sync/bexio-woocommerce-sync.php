<?php
/**
 * Plugin Name:       bexio WooCommerce Sync
 * Description:       Überträgt Artikel und Bestellungen inkl. Besteller (Kontakt) von WooCommerce nach bexio und übernimmt täglich den Lagerstatus aus bexio.
 * Version:           1.1.2
 * Requires at least: 6.0
 * Requires PHP:      7.4
 * Requires Plugins:  woocommerce
 * WC requires at least: 7.0
 * Text Domain:       bexio-woo-sync
 * License:           GPL-2.0-or-later
 */

defined( 'ABSPATH' ) || exit;

define( 'BWS_VERSION', '1.1.2' );
define( 'BWS_FILE', __FILE__ );
define( 'BWS_DIR', plugin_dir_path( __FILE__ ) );
define( 'BWS_LOG_SOURCE', 'bexio-sync' );
define( 'BWS_AS_GROUP', 'bexio-sync' );

require_once BWS_DIR . 'includes/class-bws-util.php';
require_once BWS_DIR . 'includes/class-bws-exception.php';
require_once BWS_DIR . 'includes/class-bws-settings.php';
require_once BWS_DIR . 'includes/class-bws-client.php';
require_once BWS_DIR . 'includes/class-bws-lookup.php';
require_once BWS_DIR . 'includes/class-bws-product-sync.php';
require_once BWS_DIR . 'includes/class-bws-contact-sync.php';
require_once BWS_DIR . 'includes/class-bws-order-sync.php';
require_once BWS_DIR . 'includes/class-bws-stock-import.php';
require_once BWS_DIR . 'includes/class-bws-admin.php';

// High-Performance Order Storage (HPOS) kompatibel.
add_action(
	'before_woocommerce_init',
	function () {
		if ( class_exists( \Automattic\WooCommerce\Utilities\FeaturesUtil::class ) ) {
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', BWS_FILE, true );
		}
	}
);

register_deactivation_hook(
	__FILE__,
	function () {
		if ( function_exists( 'as_unschedule_all_actions' ) ) {
			as_unschedule_all_actions( 'bws_import_stock' );
		}
		delete_option( 'bws_stock_import_scheduled' );
	}
);

add_action(
	'plugins_loaded',
	function () {
		if ( ! class_exists( 'WooCommerce' ) ) {
			add_action(
				'admin_notices',
				function () {
					echo '<div class="notice notice-error"><p>bexio WooCommerce Sync benötigt ein aktives WooCommerce.</p></div>';
				}
			);
			return;
		}

		BWS_Product_Sync::init();
		BWS_Order_Sync::init();
		BWS_Stock_Import::init();

		if ( is_admin() ) {
			BWS_Admin::init();
		}

		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			require_once BWS_DIR . 'includes/class-bws-cli.php';
			WP_CLI::add_command( 'bexio', 'BWS_CLI' );
		}
	}
);

/**
 * Schreibt einen Eintrag ins WooCommerce-Log (WooCommerce > Status > Logs, Quelle "bexio-sync").
 *
 * @param string $level   debug|info|notice|warning|error.
 * @param string $message Nachricht.
 */
function bws_log( $level, $message ) {
	if ( function_exists( 'wc_get_logger' ) ) {
		wc_get_logger()->log( $level, $message, array( 'source' => BWS_LOG_SOURCE ) );
	}
}
