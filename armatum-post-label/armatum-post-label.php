<?php
/**
 * Plugin Name:       Armatum Post Label
 * Description:       Erstellt Versandlabels der Schweizerischen Post (PostPac Economy, optional Signature / Sperrgut) direkt aus WooCommerce-Bestellungen.
 * Version:           1.0.0
 * Author:            Armatum GmbH
 * Requires at least: 6.0
 * Requires PHP:      7.4
 * Requires Plugins:  woocommerce
 * License:           GPL-2.0-or-later
 */

defined( 'ABSPATH' ) || exit;

define( 'APL_VERSION', '1.0.0' );
define( 'APL_FILE', __FILE__ );
define( 'APL_LOG_SOURCE', 'armatum-post-label' );

require_once __DIR__ . '/includes/class-apl-util.php';
require_once __DIR__ . '/includes/class-apl-api.php';
require_once __DIR__ . '/includes/class-apl-label.php';
require_once __DIR__ . '/includes/class-apl-admin.php';

add_action(
	'before_woocommerce_init',
	function () {
		if ( class_exists( \Automattic\WooCommerce\Utilities\FeaturesUtil::class ) ) {
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', APL_FILE, true );
		}
	}
);

register_activation_hook( APL_FILE, array( 'APL_Label', 'activate' ) );

add_action(
	'plugins_loaded',
	function () {
		if ( ! class_exists( 'WooCommerce' ) ) {
			return;
		}
		APL_Label::init();

		// Beim Ersetzen eines aktiven Plugins per ZIP läuft der Aktivierungs-Hook nicht -> Update erkennen.
		if ( get_option( 'apl_version' ) !== APL_VERSION ) {
			APL_Label::activate();
			update_option( 'apl_version', APL_VERSION, false );
		}
		if ( is_admin() ) {
			APL_Admin::init();
		}
	}
);

/**
 * Log-Eintrag (WooCommerce > Status > Logs, Quelle "armatum-post-label").
 *
 * @param string $level   Level.
 * @param string $message Nachricht.
 */
function apl_log( $level, $message ) {
	if ( function_exists( 'wc_get_logger' ) ) {
		wc_get_logger()->log( $level, $message, array( 'source' => APL_LOG_SOURCE ) );
	}
}
