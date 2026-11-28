<?php
/**
 * CLI-Tests für den Warenkorb des Armatum Konfigurators (AK_Cart, ab 1.0.12: Montage ohne eigene Position).
 * Aufruf: php wordpress/tests/ak-cart-test.php   (ohne WordPress, mit kleinen Platzhaltern)
 */

define( 'ABSPATH', __DIR__ . '/' );

// --- Minimaler Ersatz für WordPress/WooCommerce ---------------------------------------------
$GLOBALS['opts']   = array( 'ak_montage_product_id' => 999 );
$GLOBALS['titles'] = array( 1 => 'SIG MCX Spear LT' );
function get_option( $k ) { return $GLOBALS['opts'][ $k ] ?? false; }
function wc_get_product_id_by_sku( $s ) { return 0; }
function get_the_title( $id ) { return $GLOBALS['titles'][ $id ] ?? ''; }
function get_post_meta( $id, $k, $single = false ) { return ''; }
function esc_html( $s ) { return htmlspecialchars( (string) $s, ENT_QUOTES, 'UTF-8' ); }
function wp_strip_all_tags( $s ) { return strip_tags( (string) $s ); }
function wc_get_order( $id ) { return $GLOBALS['order'] ?? false; }

class T_Cart {
	public $cart_contents = array();
	public $removed       = array();
	public function get_cart() { return $this->cart_contents; }
	public function remove_cart_item( $k ) { $this->removed[] = $k; unset( $this->cart_contents[ $k ] ); return true; }
}
class T_WC { public $cart; }
function WC() { return $GLOBALS['wc']; }

class T_Product {
	private $sku;
	public function __construct( $sku ) { $this->sku = $sku; }
	public function get_sku() { return $this->sku; }
}
class T_OrderItem {
	public $meta = array();
	private $name, $pid, $sku, $qty;
	public function __construct( $name = '', $pid = 0, $sku = '', $meta = array(), $qty = 1 ) {
		$this->name = $name; $this->pid = $pid; $this->sku = $sku; $this->meta = $meta; $this->qty = $qty;
	}
	public function add_meta_data( $k, $v, $unique = false ) { $this->meta[ $k ] = $v; }
	public function get_meta( $k ) { return $this->meta[ $k ] ?? ''; }
	public function get_name() { return $this->name; }
	public function get_product_id() { return $this->pid; }
	public function get_product() { return new T_Product( $this->sku ); }
	public function get_quantity() { return $this->qty; }
}
class T_Order {
	public $items = array();
	public $notes = array();
	public $meta  = array();
	public $saves = 0;
	public function get_items() { return $this->items; }
	public function add_order_note( $t, $customer = 0, $by_user = false ) { $this->notes[] = array( $t, $customer ); }
	public function get_meta( $k ) { return $this->meta[ $k ] ?? ''; }
	public function update_meta_data( $k, $v ) { $this->meta[ $k ] = $v; }
	public function save() { $this->saves++; }
}

require dirname( __DIR__ ) . '/armatum-konfigurator/includes/class-ak-cart.php';

$fails = 0;
function check( $name, $ok ) {
	global $fails;
	echo ( $ok ? 'OK   ' : 'FEHL ' ) . $name . "\n";
	if ( ! $ok ) {
		$fails++;
	}
}

// --- Warenkorb: Paket p1 (Waffe + Optik + alte Montage-Position), Paket p2 nur Optik (Waffe entfernt), fremder Artikel ---
$GLOBALS['wc']       = new T_WC();
$GLOBALS['wc']->cart = new T_Cart();
$GLOBALS['wc']->cart->cart_contents = array(
	'a' => array( 'product_id' => 1, 'ak_group' => 'p1', 'ak_role' => 'waffe', 'ak_weapon' => 1 ),
	'b' => array( 'product_id' => 2, 'ak_group' => 'p1', 'ak_role' => 'optik', 'ak_weapon' => 1 ),
	'c' => array( 'product_id' => 999, 'ak_group' => 'p1', 'ak_role' => 'service', 'ak_weapon' => 1 ),
	'd' => array( 'product_id' => 3, 'ak_group' => 'p2', 'ak_role' => 'optik', 'ak_weapon' => 1 ),
	'e' => array( 'product_id' => 4 ),
	'f' => array( 'product_id' => 999 ), // altes Montage-Produkt ohne Paketdaten
);

AK_Cart::cleanup();
check( 'Alte Montage-Positionen entfernt (mit und ohne Paketdaten)', array( 'c', 'f' ) === $GLOBALS['wc']->cart->removed );
check( 'Andere Positionen bleiben', array( 'a', 'b', 'd', 'e' ) === array_keys( $GLOBALS['wc']->cart->cart_contents ) );
AK_Cart::cleanup();
check( 'Zweites Laden ohne Fehler, nichts mehr zu entfernen', 2 === count( $GLOBALS['wc']->cart->removed ) );

function has_montage( $data ) {
	foreach ( $data as $d ) {
		if ( 'Montage im Paket' === $d['key'] && 'gratis' === $d['value'] ) {
			return true;
		}
	}
	return false;
}
$cart = $GLOBALS['wc']->cart->cart_contents;
check( 'Vermerk an der Waffe', has_montage( AK_Cart::item_data( array(), $cart['a'] ) ) );
check( 'Vermerk an der Optik', has_montage( AK_Cart::item_data( array(), $cart['b'] ) ) );
check( 'Kein Vermerk, wenn die Waffe des Pakets fehlt', ! has_montage( AK_Cart::item_data( array(), $cart['d'] ) ) );
check( 'Kein Vermerk an fremdem Artikel', array() === AK_Cart::item_data( array(), $cart['e'] ) );

$oi = new T_OrderItem();
AK_Cart::order_item_meta( $oi, 'b', $cart['b'] );
check( 'Bestellposition: Meta «Montage im Paket: gratis»', 'gratis' === ( $oi->meta['Montage im Paket'] ?? '' ) );
check( 'Bestellposition: Paket-ID mitgeführt', 'p1' === ( $oi->meta['_ak_group'] ?? '' ) && false !== strpos( $oi->meta['Paket'], '(p1)' ) );

// --- Werkstatt-Notiz ---
$order        = new T_Order();
$order->items = array(
	new T_OrderItem( 'SIG MCX Spear LT', 1, 'SIG-123', array( '_ak_group' => 'p1', '_ak_role' => 'waffe' ) ),
	new T_OrderItem( 'Aimpoint CompM5', 2, 'AIM-5', array( '_ak_group' => 'p1', '_ak_role' => 'optik' ) ),
	new T_OrderItem( 'Unity FAST Mount', 5, '', array( '_ak_group' => 'p1', '_ak_role' => 'montage' ) ),
	new T_OrderItem( 'Montage (gratis im Paket)', 999, 'AK-MONTAGE', array( '_ak_group' => 'p1', '_ak_role' => 'service' ) ),
	new T_OrderItem( 'Putzzeug', 7, 'PZ-1' ),
	new T_OrderItem( 'Optik allein', 3, 'X-1', array( '_ak_group' => 'p2', '_ak_role' => 'optik' ) ),
);
$GLOBALS['order'] = $order;
AK_Cart::workshop_note( $order );            // klassischer Checkout
AK_Cart::workshop_note( $order );            // Block-Checkout feuert ebenfalls
AK_Cart::workshop_note( 123 );               // als ID übergeben
$expect = 'Konfigurator-Paket: Montage gratis. Waffe: SIG MCX Spear LT (Art.-Nr. SIG-123). Montieren: Aimpoint CompM5 (AIM-5), Unity FAST Mount. (Paket p1)';
check( 'Genau eine Notiz (keine Doppelnotiz, Paket ohne Waffe ohne Notiz)', 1 === count( $order->notes ) );
check( 'Notiztext', isset( $order->notes[0] ) && $expect === $order->notes[0][0] );
check( 'Interne Notiz (nicht an Kunden)', isset( $order->notes[0] ) && 0 === $order->notes[0][1] );
check( 'Doppelschutz gespeichert', '' !== ( $order->meta['_ak_werkstatt_notiz'] ?? '' ) && 1 === $order->saves );

$empty = new T_Order();
$empty->items = array( new T_OrderItem( 'Putzzeug', 7, 'PZ-1' ) );
AK_Cart::workshop_note( $empty );
check( 'Bestellung ohne Paket: keine Notiz, kein Speichern', ! $empty->notes && 0 === $empty->saves );

echo $fails ? "\n$fails Fehler\n" : "\nAlle Tests bestanden\n";
exit( $fails ? 1 : 0 );
