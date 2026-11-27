<?php
/**
 * Testseite für das Handy-Layout: rendert AK_Frontend::render() mit Platzhalter-WP und Beispieldaten.
 * Aufruf (aus dem Repo-Stamm):
 *   php wordpress/tests/ak-mobile/gen.php wordpress/armatum-konfigurator /tmp/ak-mobile.html 1
 *   NODE_PATH=$(npm root -g) node wordpress/tests/ak-mobile/check.js /tmp/ak-mobile.html nachher /tmp
 * Drittes Argument 1 = mit theme-sim.css (Nachbildung typischer Blocksy-/WooCommerce-Regeln, nicht das Original).
 * jQuery: /usr/share/javascript/jquery/jquery.min.js oder Umgebungsvariable AK_JQUERY.
 * Optionen als JSON in AK_OPTS, z.B. AK_OPTS='{"ak_flycart_mode":"lift"}'.
 * Ab 1.0.11: Nachbau des schwebenden Warenkorb-Knopfs von «WooCommerce Modal Fly Cart PRO» (Markup wie live, CSS in theme-sim.css).
 */
define( 'ABSPATH', __DIR__ . '/' );
$plugin = realpath( $argv[1] );
$out    = $argv[2];
$theme  = ! empty( $argv[3] );

define( 'AK_URL', '' );
define( 'AK_T', 'file://' . __DIR__ . '/' );
define( 'AK_VERSION', 'x' );
function add_action() {}
function add_filter() {}
$GLOBALS['inline_css'] = '';
function wp_add_inline_style( $h, $css ) { $GLOBALS['inline_css'] .= $css; }
function add_shortcode() {}
function wp_enqueue_style() {}
function wp_enqueue_script() {}
function esc_attr( $s ) { return htmlspecialchars( (string) $s, ENT_QUOTES, 'UTF-8' ); }
function esc_html( $s ) { return htmlspecialchars( (string) $s, ENT_QUOTES, 'UTF-8' ); }
function esc_url( $s ) { return esc_attr( $s ); }
function wp_json_encode( $x ) { return json_encode( $x ); }
function get_the_title( $id ) { return 'Daniel Defense DDM4 V7 Pro 5.56 NATO 16"'; }
function remove_query_arg() { return '?'; }
function wc_get_cart_url() { return '/warenkorb/'; }
function get_woocommerce_currency_symbol() { return 'CHF'; }
function wc_get_price_decimals() { return 2; }
function get_option( $k, $d = false ) { $o = array( 'ak_testmode' => 'yes' ); $o = array_merge( $o, json_decode( (string) getenv( 'AK_OPTS' ), true ) ?: array() ); return array_key_exists( $k, $o ) ? $o[ $k ] : $d; }
class WC_AJAX { public static function get_endpoint( $e ) { return '/?wc-ajax=' . $e; } }

function price_html( $reg, $sale = null ) {
	$f = function ( $n ) {
		return '<span class="woocommerce-Price-amount amount"><bdi><span class="woocommerce-Price-currencySymbol">CHF</span>&nbsp;' . number_format( $n, 2, '.', '&#039;' ) . '</bdi></span>';
	};
	if ( null === $sale ) {
		return $f( $reg );
	}
	return '<del aria-hidden="true">' . $f( $reg ) . '</del> <span class="screen-reader-text">Ursprünglicher Preis war: CHF&nbsp;' . number_format( $reg, 2, '.', '&#039;' ) . '.</span><ins aria-hidden="true">' . $f( $sale ) . '</ins><span class="screen-reader-text">Aktueller Preis ist: CHF&nbsp;' . number_format( $sale, 2, '.', '&#039;' ) . '.</span>';
}

$GLOBALS['items'] = array();
function item( $id, $name, $brand, $reg, $sale, $extra = array() ) {
	$GLOBALS['items'][ $id ] = array_merge(
		array(
			'id' => $id, 'name' => $name, 'sku' => 'SKU' . $id, 'erwerb' => '', 'price' => (float) ( null === $sale ? $reg : $sale ),
			'priceHtml' => price_html( $reg, $sale ), 'stock' => $id % 3 ? 'Lieferbar auf Bestellung' : 'An Lager', 'url' => '#',
			'img' => AK_T . 'thumb.png', 'imgW' => 300, 'imgH' => 300, 'thumb' => AK_T . 'thumb.png', 'mmPerPx' => 0.2, 'anchor' => array( 150, 150 ),
			'tubeAnchor' => null, 'flip' => false, 'direct' => true, 'tube' => 30, 'group' => '', 'ifs' => array( 'picatinny' ), 'outs' => array(),
			'hinweis' => '', 'cutout' => false, 'brand' => $brand, 'ph' => false, 'short' => $name, 'length' => 0, 'axis' => 0, 'axisHeight' => 0, 'mountType' => '',
		),
		$extra
	);
}

class AK_Data {
	const SLOTS  = array( 'muendung' => 1, 'unten' => 1, 'seite' => 1, 'vorne' => 1, 'schaft' => 1, 'griff' => 1 );
	const GROUPS = array(
		'muendungsaufsatz' => array( 'muendung', 'Mündungsaufsatz', 2 ),
		'hub'              => array( 'muendung', 'HUB (Schalldämpfer-Adapter)', 3 ),
		'schalldaempfer'   => array( 'muendung', 'Schalldämpfer', 4 ),
		'handgriff'        => array( 'unten', 'Frontgriff (am Handschutz)', 3 ),
		'lampe'            => array( 'unten,seite', 'Lampe (Waffenlicht)', 3 ),
		'zweibein'         => array( 'vorne,unten', 'Zweibein', 3 ),
		'schaft'           => array( 'schaft', 'Schulterstütze', 2 ),
	);
	const MAG_GAP = 5;
	const MAG_TOL = 10;
	public static function note_visit() {}
	public static function options_for_weapon( $w ) {
		$optics = array();
		foreach ( range( 101, 112 ) as $id ) {
			$optics[ $id ] = array();
		}
		return array(
			'optics' => $optics, 'optic_mags' => array(), 'mounts' => array(), 'magnifiers' => array(), 'mag_mounts' => array(),
			'parts'  => array(
				'muendungsaufsatz' => range( 201, 204 ),
				'hub'              => range( 211, 213 ),
				'schalldaempfer'   => range( 221, 229 ),
				'handgriff'        => range( 231, 238 ),
				'lampe'            => range( 241, 243 ),
				'zweibein'         => range( 251, 252 ),
				'schaft'           => range( 261, 263 ),
			),
			'slots'  => array(), 'adapters' => array(),
		);
	}
	public static function item_payload( $id ) { return $GLOBALS['items'][ $id ]; }
	public static function weapon_slot( $w, $slot ) { return array( 'anchor' => array( 'muendung' => array( 1950, 235 ), 'unten' => array( 1300, 270 ), 'seite' => array( 1300, 235 ), 'vorne' => array( 1700, 270 ), 'schaft' => array( 420, 250 ), 'griff' => array( 740, 270 ) )[ $slot ], 'interfaces' => array( '1/2x28' ) ); }
	public static function split( $s ) { return array(); }
	public static function meta( $id, $k, $d = '' ) { return ''; }
	public static function point( $id, $k ) { return array( 1950, 235 ); }
	public static function weapon_x( $id, $k ) { return 'rail_front' === $k ? 1500 : 500; }
	public static function weapon_type( $id ) { return 'langwaffe'; }
	public static function group_slots( $g ) { return explode( ',', self::GROUPS[ $g ][0] ); }
}
class AK_Erwerb {
	public static function for_selection() { return array(); }
	public static function html( $r ) {
		return '<p class="ak-erwerb-satz">Für diese Zusammenstellung brauchst du: Waffenerwerbsschein (WES), gültige Ausweiskopie (Pass/ID).</p><details class="ak-erwerb-details"><summary>Je Teil</summary><ul><li><strong>Waffe</strong> Daniel Defense DDM4 V7 Pro: WES (Waffenerwerbschein)</li></ul></details>';
	}
}

// Waffe
item( 1, 'Daniel Defense DDM4 V7 Pro 5.56 NATO 16"', 'Daniel Defense', 2490, null, array( 'img' => AK_T . 'rifle.png', 'imgW' => 2000, 'imgH' => 600, 'mmPerPx' => 0.45, 'anchor' => array( 1200, 200 ) ) );
// Optiken (Namen und Preise wie im Screenshot)
$optic_names = array(
	array( 'Eotech EXPS3-2 Schwarz', 'Eotech', 1019.5, 999 ),
	array( 'Sig Sauer Tango-MSR LPVO 1-10x28 FFP Coyote (FDE) mit ALPHA-MSR Montage', 'Sig Sauer', 1042, 899 ),
	array( 'Aimpoint CompM5s 2 MOA', 'Aimpoint', 1042, 1019.5 ),
	array( 'Vortex Strike Eagle 1-8x24 AR-BDC3', 'Vortex', 649, null ),
	array( 'Holosun HS510C', 'Holosun', 429, null ),
	array( 'Leupold Mark 5HD 5-25x56 (35mm) M5C3 FFP Tremor 3', 'Leupold', 3290, 2990 ),
	array( 'Trijicon ACOG 4x32 BAC Dual Illuminated Chevron .223 Ballistic Reticle', 'Trijicon', 1890, null ),
	array( 'Schmidt&Bender PM II 5-25x56 Ultra Short P4FL-MOA', 'Schmidt&Bender', 4290, null ),
	array( 'Aimpoint Micro T-2 2 MOA', 'Aimpoint', 869, null ),
	array( 'Eotech Vudu 1-6x24 FFP SR3', 'Eotech', 1690, 1590 ),
	array( 'Steiner T6Xi 1-6x24 KSR', 'Steiner', 1590, null ),
	array( 'Zeiss LRP S5 3-18x50 ZF-MRi', 'Zeiss', 3150, null ),
);
foreach ( $optic_names as $i => $o ) {
	item( 101 + $i, $o[0], $o[1], $o[2], $o[3], array( 'img' => AK_T . 'optic.png', 'imgW' => 900, 'imgH' => 260, 'mmPerPx' => 0.3, 'anchor' => array( 450, 250 ), 'erwerb' => $i % 4 ? '' : 'ohne Bewilligung' ) );
}
$parts = array(
	201 => 'SureFire SF3P Flash Hider 1/2x28', 202 => 'B&T Mündungsbremse Kompensator 1/2-28 UNEF', 203 => 'Daniel Defense Superior Suppression Device', 204 => 'Original-Mündungsfeuerdämpfer A2',
	211 => 'HUB-Adapter 1/2x28 auf 1.375x24', 212 => 'Rugged Mündungsbremse HUB 1/2x28', 213 => 'Hausken Adapter',
	241 => 'SureFire M640V-BK-PRO Scout Light Pro', 242 => 'Streamlight TLR-1 HL', 243 => 'Cloud Defensive REIN 3.0',
	251 => 'Harris Zweibein S-BRM 6-9"', 252 => 'Magpul MOE Bipod',
	261 => 'Magpul CTR Schaft', 262 => 'B5 Systems Bravo', 263 => 'Daniel Defense Buttstock',
);
foreach ( range( 221, 229 ) as $i ) { $parts[ $i ] = 'Hausken JD184 XTRM MKII Schalldämpfer ' . $i; }
foreach ( range( 231, 238 ) as $i ) { $parts[ $i ] = 'Magpul RVG Rail Vertical Grip ' . $i; }
foreach ( $parts as $id => $n ) {
	item( $id, $n, strtok( $n, ' ' ), 89 + $id, $id % 4 ? null : 70 + $id, array( 'ph' => true, 'length' => 60, 'group' => '' ) );
}

require $plugin . '/includes/class-ak-frontend.php';
$html  = AK_Frontend::render( 1, 0, array( 102 ) );

$theme_css = $theme ? file_get_contents( __DIR__ . '/theme-sim.css' ) : '';
$page      = '<!doctype html><html lang="de"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">' .
	'<link rel="stylesheet" href="file://' . $plugin . '/assets/konfigurator.css"><style>' . $theme_css . '</style><style id="ak-front-inline-css">' . $GLOBALS['inline_css'] . '</style></head><body>' .
	'<main class="site-main"><div class="ct-container"><article class="entry-content"><h1>Konfigurator</h1>' . $html . '</article></div></main>' .
	'<div id="wcspc-area" class="wcspc-area wcspc-effect-01"></div><div id="wcspc-count" class="wcspc-count wcspc-count-bottom-left"><i class="wcspc-icon-cart1"></i><span id="wcspc-count-number">10</span></div>' .
	'<script src="file://' . ( getenv( 'AK_JQUERY' ) ?: '/usr/share/javascript/jquery/jquery.min.js' ) . '"></script>' .
	// Server-Antworten nachgebildet (asynchron wie echt): Erwerb und Paket in den Warenkorb.
	'<script>(function($){var o=$.post;window.__akPosts=0;$.post=function(u){var d=$.Deferred();if(/ak_erwerb/.test(u)){setTimeout(function(){d.resolve({success:true,data:{html:' . json_encode( AK_Erwerb::html( array() ) ) . ',satz:"",sperre:false}});},30);return d.promise();}' .
	'if(/ak_add_package/.test(u)){window.__akPosts++;setTimeout(function(){d.resolve({success:true,data:{message:"Paket liegt im Warenkorb.",count:13,package:"p1",value:3389,lines:[{id:1,name:"Waffe",price:2490,role:"waffe"},{id:102,name:"Optik",price:899,role:"optik"}]}});},80);return d.promise();}return o.apply(this,arguments);};})(jQuery);</script>' .
	'<script src="file://' . $plugin . '/assets/konfigurator.js"></script></body></html>';
file_put_contents( $out, $page );
echo "ok\n";
