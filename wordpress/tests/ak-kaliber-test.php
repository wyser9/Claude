<?php
/**
 * CLI-Tests für Kaliber-Erkennung und Schalldämpfer-/Waffenart-Regeln des Armatum Konfigurators.
 * Aufruf: php wordpress/tests/ak-kaliber-test.php   (ohne WordPress, mit kleinen Platzhalter-Funktionen)
 */

define( 'ABSPATH', __DIR__ . '/' );

// --- Minimaler Ersatz für WordPress/WooCommerce ---------------------------------------------
$GLOBALS['ak_test_products'] = array();

function wp_specialchars_decode( $s ) { return html_entity_decode( (string) $s, ENT_QUOTES, 'UTF-8' ); }
function wp_strip_all_tags( $s ) { return strip_tags( (string) $s ); }
function is_wp_error( $x ) { return false; }
function get_post_type( $id ) { return isset( $GLOBALS['ak_test_products'][ $id ] ) ? 'product' : false; }
function wp_get_post_parent_id( $id ) { return 0; }
function get_the_title( $id ) { return $GLOBALS['ak_test_products'][ $id ]['name'] ?? ''; }
function get_post_meta( $id, $key, $single = false ) {
	$k = substr( $key, 4 ); // ohne _ak_
	return $GLOBALS['ak_test_products'][ $id ]['meta'][ $k ] ?? '';
}
function wp_get_post_terms( $id, $tax, $args = array() ) {
	return $GLOBALS['ak_test_products'][ $id ]['terms'][ $tax ] ?? array();
}
class AK_Test_Product {
	public $id;
	public function __construct( $id ) { $this->id = $id; }
	public function get_name() { return $GLOBALS['ak_test_products'][ $this->id ]['name']; }
	public function get_attribute( $a ) { return $GLOBALS['ak_test_products'][ $this->id ]['attr'][ $a ] ?? ''; }
}
function wc_get_product( $id ) { return isset( $GLOBALS['ak_test_products'][ $id ] ) ? new AK_Test_Product( $id ) : false; }

require dirname( __DIR__ ) . '/armatum-konfigurator/includes/class-ak-data.php';

function product( $id, $name, $meta = array(), $attr = array(), $terms = array() ) {
	$GLOBALS['ak_test_products'][ $id ] = compact( 'name', 'meta', 'attr', 'terms' );
}

$fails = 0;
$count = 0;
function check( $label, $got, $want ) {
	global $fails, $count;
	$count++;
	$ok = is_float( $want ) ? abs( $got - $want ) < 0.001 : $got === $want;
	if ( ! $ok ) {
		$fails++;
		echo "FEHLER  $label: erhalten " . var_export( $got, true ) . ', erwartet ' . var_export( $want, true ) . "\n";
	} else {
		echo "ok      $label\n";
	}
}

// --- 1. Kaliber-Schreibweisen (Shop-Team) -------------------------------------------------------
$cal = array(
	'9x19 mm'          => array( 9.1, array( 'pistole' ) ),
	'9 mm Luger'       => array( 9.1, array( 'pistole' ) ),
	'9mm Para'         => array( 9.1, array( 'pistole' ) ),
	'9x19mm'           => array( 9.1, array( 'pistole' ) ),
	'9×19 mm'          => array( 9.1, array( 'pistole' ) ),
	'.308 Win'         => array( 7.8, array( 'gewehr' ) ),
	'7.62x51 mm NATO'  => array( 7.8, array( 'gewehr' ) ),
	'.223 Rem'         => array( 5.7, array( 'gewehr' ) ),
	'5.56x45 mm NATO'  => array( 5.7, array( 'gewehr' ) ),
	'.243 Win'         => array( 6.2, array( 'gewehr' ) ),
	'.22 LR'           => array( 5.7, array( 'kk' ) ),
	'.22LR'            => array( 5.7, array( 'kk' ) ),
	'5.6 mm lfB'       => array( 5.7, array( 'kk' ) ),
	'.22 WMR'          => array( 5.7, array( 'kk' ) ),
	'.22 Hornet'       => array( 5.7, array( 'gewehr' ) ),
	'.22-250 Rem'      => array( 5.7, array( 'gewehr' ) ),
	'.17 HMR'          => array( 5.7, array( 'kk' ) ),
	'.45 ACP'          => array( 11.5, array( 'pistole' ) ),
	'.45-70 Govt'      => array( 11.5, array( 'gewehr' ) ),
	'.40 S&W'          => array( 10.2, array( 'pistole' ) ),
	'.300 AAC Blackout' => array( 7.8, array( 'gewehr' ) ),
	'.30-06 Springfield' => array( 7.8, array( 'gewehr' ) ),
	'6.5 Creedmoor'    => array( 6.7, array( 'gewehr' ) ),
	'7.62x39 mm'       => array( 7.8, array( 'gewehr' ) ),
	'.338 Lapua Magnum' => array( 8.6, array( 'gewehr' ) ),
	'9.3x62'           => array( 9.5, array( 'gewehr' ) ),
	'5.7x28 mm'        => array( 5.7, array( 'pistole' ) ),
	'.357 Magnum'      => array( 9.1, array( 'pistole' ) ),
	'.380 ACP'         => array( 9.1, array( 'pistole' ) ),
	'5.56 / 9mm'       => array( 9.1, array( 'gewehr', 'pistole' ) ),
	'.224 and 9mm'     => array( 9.1, array( 'pistole', 'gewehr' ) ),
	'7mm - .30 (.308)' => array( 7.8, array( 'gewehr' ) ),
	'bis 9.3 mm'       => array( 9.3, array() ),
);
foreach ( $cal as $text => $want ) {
	check( "kaliber_mm «{$text}»", AK_Data::caliber_from_text( $text, true ), $want[0] );
	$got = AK_Data::caliber_classes_from_text( $text );
	sort( $got );
	sort( $want[1] );
	check( "klasse «{$text}»", $got, $want[1] );
}
// Produktname (ohne lockere Erkennung).
check( 'Name «Glock 17 MOS Gen5 9x19mm»', AK_Data::caliber_from_text( 'Glock 17 MOS Gen5 9x19mm' ), 9.1 );
check( 'Name «Glock 45» (Modell, kein Kaliber)', AK_Data::caliber_from_text( 'Glock 45' ), 0.0 );
check( 'Name «HK416 A5 .223»', AK_Data::caliber_from_text( 'HK416 A5 .223' ), 5.7 );

// --- 2. Waffen und Teile ------------------------------------------------------------------------
$pistol_cat = array( 'product_cat' => array( 'Pistolen' ) );
$long_cat   = array( 'product_cat' => array( 'Halbautomaten' ) );
product( 1, 'SIG516 G3 Bronze', array( 'role' => 'waffe', 'plattform' => 'ar15' ), array( 'pa_kaliber' => '5.56x45 mm NATO' ), $long_cat );
product( 2, 'Glock 17 MOS Gen5', array( 'role' => 'waffe', 'plattform' => 'pistole' ), array( 'pa_kaliber' => '9 mm Luger' ), $pistol_cat );
product( 3, 'SIG Sauer P320 X-Carry', array( 'role' => 'waffe' ), array(), $pistol_cat ); // Kaliber unbekannt
product( 4, 'B&T APC9 Pro', array( 'role' => 'waffe' ), array( 'pa_kaliber' => '9x19 mm' ), $long_cat ); // Pistolenkaliber-Karabiner
product( 5, 'Ruger 10/22', array( 'role' => 'waffe' ), array( 'pa_kaliber' => '.22 LR' ), $long_cat );
product( 6, 'Sig Cross', array( 'role' => 'waffe' ), array( 'pa_kaliber' => '.308 Win' ), $long_cat );
product( 7, 'Walther PDP .22', array( 'role' => 'waffe', 'waffenart' => 'pistole' ), array( 'pa_kaliber' => '.22 LR' ) );

$sd = function ( $id, $name, $meta = array(), $attr = array() ) {
	product( $id, $name, array_merge( array( 'role' => 'zubehoer', 'gruppe' => 'schalldaempfer' ), $meta ), $attr );
};
$sd( 101, 'SD-988006-M9A3 Schalldämpfer', array( 'plattform' => 'pistole' ), array( 'pa_kaliber' => '9 mm Luger' ) );
$sd( 102, 'Schalldämpfer 9mm ohne Plattform', array(), array( 'pa_kaliber' => '9x19 mm' ) );
$sd( 103, 'B&T Tiger OK1079 .22LR', array( 'kaliber' => '.243 Win' ), array( 'pa_kaliber' => '.22 LR' ) );
$sd( 104, 'KK-Dämpfer .22 LR', array(), array( 'pa_kaliber' => '.22 LR' ) );
$sd( 105, 'Rifle Can 5.56', array(), array( 'pa_kaliber' => '5.56x45 mm NATO' ) );
$sd( 106, 'Rifle Can .30', array(), array( 'pa_kaliber' => '7.62x51 mm NATO' ) );
$sd( 107, 'Dämpfer ohne Kaliber' );
$sd( 108, 'Kurzwaffen-Dämpfer (Feld)', array( 'waffenart' => 'pistole' ) );
$sd( 109, 'Multi 9mm/.22LR', array(), array( 'pa_kaliber' => '9 mm Luger, .22 LR' ) );
$sd( 110, '.45 ACP Dämpfer', array(), array( 'pa_kaliber' => '.45 ACP' ) );

$want = array(
	// Waffe => array( SD-ID => passt? )  (nur Waffenart-/Klassen-Regel, ohne Bohrung und Gewinde)
	1 => array( 101 => false, 102 => false, 103 => true, 104 => false, 105 => true, 106 => true, 107 => true, 108 => false, 109 => false, 110 => false ),
	2 => array( 101 => true, 102 => true, 103 => true, 104 => false, 105 => true, 106 => true, 107 => true, 108 => true, 109 => true, 110 => true ),
	3 => array( 101 => true, 102 => true, 104 => false, 108 => true ),
	4 => array( 101 => true, 102 => true, 104 => false, 105 => true ),
	5 => array( 101 => false, 102 => false, 103 => true, 104 => true, 109 => true ),
	6 => array( 101 => false, 102 => false, 104 => false, 106 => true ),
	7 => array( 101 => false, 104 => true, 109 => true ),
);
foreach ( $want as $w => $list ) {
	foreach ( $list as $p => $ok ) {
		check( 'SD ' . get_the_title( $p ) . ' an ' . get_the_title( $w ), AK_Data::suppressor_ok( $w, $p ), $ok );
	}
}
// Bohrungsregel bleibt: .243-Dämpfer an .22-Waffe ja, 9-mm-Dämpfer an .45 nein.
check( 'Bohrung .243-SD an 10/22', AK_Data::caliber_ok( 5, 103 ), true );
check( 'Bohrung 5.56-SD an .308', AK_Data::caliber_ok( 6, 105 ), false );

// Zweibein / Frontgriff nur Langwaffen, Lampe nach Waffenart.
product( 201, 'Harris Zweibein', array( 'role' => 'zubehoer', 'gruppe' => 'zweibein' ) );
product( 202, 'Magpul AFG', array( 'role' => 'zubehoer', 'gruppe' => 'handgriff' ) );
product( 203, 'Streamlight TLR-7', array( 'role' => 'zubehoer', 'gruppe' => 'lampe' ) );
check( 'Zweibein an Glock', AK_Data::weapon_type_ok( 2, 201 ), false );
check( 'Zweibein an SIG516', AK_Data::weapon_type_ok( 1, 201 ), true );
check( 'Frontgriff an P320', AK_Data::weapon_type_ok( 3, 202 ), false );
check( 'Frontgriff an APC9 (Karabiner)', AK_Data::weapon_type_ok( 4, 202 ), true );
check( 'TLR-7 an Glock', AK_Data::weapon_type_ok( 2, 203 ), true );
check( 'TLR-7 an SIG516', AK_Data::weapon_type_ok( 1, 203 ), false );

// Platzhalter und Kurzname.
product( 301, 'Vortex Razor HD Gen III 6-36x56 FFP', array( 'role' => 'optik', 'warn' => 'hintergrund winkel' ) );
product( 302, 'Aimpoint Micro T-2', array( 'role' => 'optik', 'warn' => 'klein' ) );
check( 'Platzhalter bei «winkel»', AK_Data::shows_placeholder( 301 ), true );
check( 'kein Platzhalter ohne «winkel»', AK_Data::shows_placeholder( 302 ), false );
check( 'Kurzname', AK_Data::short_name( 'Vortex Razor HD Gen III 6-36x56 FFP' ), 'Vortex Razor HD Gen' );

echo "\n{$count} Prüfungen, {$fails} Fehler\n";
exit( $fails ? 1 : 0 );
