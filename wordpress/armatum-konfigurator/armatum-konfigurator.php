<?php
/**
 * Plugin Name: Armatum Konfigurator
 * Description: Waffen-Konfigurator für WooCommerce: Kunde wählt Optik und Montage, sieht eine Vorschau und legt das Paket (jede Komponente als eigene Position) in den Warenkorb. Montage im Paket gratis.
 * Version: 0.1.0
 * Author: Armatum GmbH
 * Requires at least: 6.0
 * Requires PHP: 7.4
 * Text Domain: armatum-konfigurator
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'AK_VERSION', '0.1.0' );
define( 'AK_FILE', __FILE__ );
define( 'AK_DIR', plugin_dir_path( __FILE__ ) );
define( 'AK_URL', plugin_dir_url( __FILE__ ) );

require_once AK_DIR . 'includes/class-ak-data.php';
require_once AK_DIR . 'includes/class-ak-admin.php';
require_once AK_DIR . 'includes/class-ak-frontend.php';
require_once AK_DIR . 'includes/class-ak-cart.php';

add_action(
	'plugins_loaded',
	function () {
		if ( ! class_exists( 'WooCommerce' ) ) {
			return;
		}
		AK_Admin::init();
		AK_Frontend::init();
		AK_Cart::init();
	}
);

register_activation_hook( __FILE__, array( 'AK_Cart', 'ensure_montage_product' ) );
