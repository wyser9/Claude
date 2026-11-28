<?php
/**
 * Plugin Name:       Armatum Kundendokumente
 * Description:       Prüft bei Bestellungen mit Erwerbsbedingungen anhand der Dateien beim bexio-Kontakt, ob Ausweiskopie (gültig) und Waffenerwerbsschein (max. 2 Jahre) oder Strafregisterauszug (max. 3 Monate) vorliegen. Nutzt den bexio-Zugang des bexio-Sync-Plugins (nur lesend).
 * Version:           1.1.0
 * Author:            Armatum GmbH
 * Requires at least: 6.0
 * Requires PHP:      7.4
 * Requires Plugins:  woocommerce
 * License:           GPL-2.0-or-later
 */

defined( 'ABSPATH' ) || exit;

define( 'AKD_VERSION', '1.1.0' );
define( 'AKD_FILE', __FILE__ );

require_once __DIR__ . '/includes/class-akd-docs.php';
require_once __DIR__ . '/includes/class-akd-index.php';
require_once __DIR__ . '/includes/class-akd-check.php';
require_once __DIR__ . '/includes/class-akd-admin.php';

add_action(
	'before_woocommerce_init',
	function () {
		if ( class_exists( \Automattic\WooCommerce\Utilities\FeaturesUtil::class ) ) {
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', AKD_FILE, true );
		}
	}
);

register_activation_hook( AKD_FILE, array( 'AKD_Index', 'schedule' ) );
register_deactivation_hook( AKD_FILE, array( 'AKD_Index', 'unschedule' ) );

add_action(
	'plugins_loaded',
	function () {
		if ( ! class_exists( 'WooCommerce' ) ) {
			return;
		}
		AKD_Index::init();
		AKD_Check::init();
		if ( is_admin() ) {
			AKD_Admin::init();
		}
	}
);

/**
 * Log-Eintrag (WooCommerce > Status > Logs, Quelle "armatum-kundendokumente").
 *
 * @param string $level   Level.
 * @param string $message Nachricht.
 */
function akd_log( $level, $message ) {
	if ( function_exists( 'wc_get_logger' ) ) {
		wc_get_logger()->log( $level, $message, array( 'source' => 'armatum-kundendokumente' ) );
	}
}
