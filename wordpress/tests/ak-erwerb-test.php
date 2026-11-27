<?php
/**
 * CLI-Tests für die Erwerbs-Zusammenfassung des Armatum Konfigurators (AK_Erwerb, ab 1.0.9).
 * Aufruf: php wordpress/tests/ak-erwerb-test.php   (ohne WordPress, mit kleinen Platzhalter-Funktionen)
 *
 * Fälle nach Julia (recht/julia-entscheide-2026-11-27.md) und Walters Rechtscheck (Hero-Kombinationen).
 */

define( 'ABSPATH', __DIR__ . '/' );

// --- Minimaler Ersatz für WordPress/WooCommerce ---------------------------------------------
$GLOBALS['ak_test_products'] = array();

function wp_specialchars_decode( $s ) { return html_entity_decode( (string) $s, ENT_QUOTES, 'UTF-8' ); }
function wp_strip_all_tags( $s ) { return strip_tags( (string) $s ); }
function esc_html( $s ) { return htmlspecialchars( (string) $s, ENT_QUOTES, 'UTF-8' ); }
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
function get_the_terms( $id, $tax ) {
	$out = array();
	foreach ( $GLOBALS['ak_test_products'][ $id ]['erwerb'] ?? array() as $tid ) {
		$out[] = (object) array( 'term_id' => $tid, 'name' => AK_TEST_TERMS[ $tid ] );
	}
	return $out ? $out : false;
}
class AK_Test_Product {
	public $id;
	public function __construct( $id ) { $this->id = $id; }
	public function get_name() { return $GLOBALS['ak_test_products'][ $this->id ]['name']; }
	public function get_attribute( $a ) { return $GLOBALS['ak_test_products'][ $this->id ]['attr'][ $a ] ?? ''; }
}
function wc_get_product( $id ) { return isset( $GLOBALS['ak_test_products'][ $id ] ) ? new AK_Test_Product( $id ) : false; }

// Terms des Attributs 12 wie im Shop (27.11.2026).
const AK_TEST_TERMS = array(
	272   => 'gültige Ausweiskopie (Pass/ID)',
	11242 => 'schriftlicher Vertrag, ab 18 Jahren',
	271   => 'Auszug aus dem zentralen Strafregister (nicht älter als 3 Monate)',
	617   => 'WES (Waffenerwerbschein)',
	649   => 'Kleine Ausn.-Bewilligung Sportschütze',
	10794 => 'Ausnahmebewilligung klein (Sammler)',
	658   => 'kantonale Ausnahmebewilligung',
);

require dirname( __DIR__ ) . '/armatum-konfigurator/includes/class-ak-data.php';
require dirname( __DIR__ ) . '/armatum-konfigurator/includes/class-ak-erwerb.php';

function product( $id, $name, $meta = array(), $attr = array(), $cats = array(), $erwerb = array() ) {
	$terms = array( 'product_cat' => $cats );
	$GLOBALS['ak_test_products'][ $id ] = compact( 'name', 'meta', 'attr', 'terms', 'erwerb' );
}

$fails = 0;
$count = 0;
function check( $label, $got, $want ) {
	global $fails, $count;
	$count++;
	if ( $got !== $want ) {
		$fails++;
		echo "FEHLER  $label: erhalten " . var_export( $got, true ) . ', erwartet ' . var_export( $want, true ) . "\n";
	} else {
		echo "ok      $label\n";
	}
}
function has( $list, $needle ) {
	foreach ( (array) $list as $s ) {
		if ( false !== strpos( is_array( $s ) ? $s['text'] . ' ' . $s['grund'] : $s, $needle ) ) {
			return true;
		}
	}
	return false;
}
function run( $w, $parts ) {
	return AK_Erwerb::for_selection( $w, $parts );
}

// --- Waffen ------------------------------------------------------------------------------------
$semi = array( 'Halbautomaten' );
$pist = array( 'Pistolen' );
product( 1, 'SIG516 G3 Bronze 5.56 16"', array( 'role' => 'waffe', 'plattform' => 'ar15', 'laenge_ohne_schaft' => '760' ), array( 'pa_kaliber' => '5.56x45 mm NATO', 'pa_magazinkapazitaet' => '30 Schuss' ), $semi, array( 649, 272 ) );
product( 2, 'Glock 17 Gen5 MOS', array( 'role' => 'waffe' ), array( 'pa_kaliber' => '9x19 mm', 'pa_magazinkapazitaet' => '17 Schuss' ), $pist, array( 617, 272 ) );
product( 3, 'WBP Mini Jack 5.56', array( 'role' => 'waffe', 'plattform' => 'ak', 'laenge_ohne_schaft' => '520' ), array( 'pa_kaliber' => '5.56x45 mm NATO', 'pa_magazinkapazitaet' => '30 Schuss' ), $semi, array( 649, 10794, 272 ) );
product( 4, 'AXSR .338 LM', array( 'role' => 'waffe', 'plattform' => 'repetierer' ), array( 'pa_kaliber' => '.338 Lapua Magnum', 'pa_magazinkapazitaet' => '10 Schuss' ), array( 'Repetierer' ), array( 11242, 271, 272 ) );
product( 5, 'AR15 ohne Längenangabe', array( 'role' => 'waffe', 'plattform' => 'ar15' ), array( 'pa_kaliber' => '.223 Rem', 'pa_magazinkapazitaet' => '10 Schuss' ), $semi, array( 617, 272 ) );
product( 6, 'Ruger 10/22 Takedown', array( 'role' => 'waffe', 'laenge_ohne_schaft' => '480' ), array( 'pa_kaliber' => '.22 LR', 'pa_magazinkapazitaet' => '25 Schuss' ), $semi, array( 617, 272 ) );
product( 7, 'SIG P211 GTO', array( 'role' => 'waffe' ), array( 'pa_kaliber' => '9x19 mm', 'pa_magazinkapazitaet' => '21/23 Schuss' ), $pist, array( 649, 272 ) );
product( 8, 'Waffe ohne Attribut', array( 'role' => 'waffe', 'plattform' => 'ar15' ), array( 'pa_kaliber' => '.223 Rem' ), $semi, array() );
// Geschätzte Länge aus Bildpunkten: Gesamtlänge 900 mm (p1-p2 = 900 px, 1 mm/px), Schaftaufnahme 250 mm vor dem Schaftende.
product( 9, 'AR15 Länge geschätzt', array( 'role' => 'waffe', 'plattform' => 'ar15', 'p1' => '1000,100', 'p2' => '100,100', 'ref_mm' => '900', 'slot_schaft_anchor' => '350,100' ), array( 'pa_kaliber' => '.223 Rem', 'pa_magazinkapazitaet' => '10 Schuss' ), $semi, array( 617, 272 ) );
product( 10, 'AR Pistol 7.5"', array( 'role' => 'waffe', 'waffenart' => 'pistole', 'laenge_ohne_schaft' => '480' ), array( 'pa_kaliber' => '5.56x45 mm NATO', 'pa_magazinkapazitaet' => '30 Schuss' ), $pist, array( 649, 272 ) );

// --- Teile -------------------------------------------------------------------------------------
$z = function ( $id, $name, $gruppe, $meta = array(), $erwerb = array(), $attr = array() ) {
	product( $id, $name, array_merge( array( 'role' => 'zubehoer', 'gruppe' => $gruppe ), $meta ), $attr, array(), $erwerb );
};
$z( 101, 'B&T Rotex Schalldämpfer', 'schalldaempfer', array(), array( 658, 272 ) );
$z( 102, 'Schalldämpfer ohne Attribut', 'schalldaempfer' );
$z( 103, 'B&T HUB 1/2x28', 'hub' );
$z( 104, 'SureFire X400U-A-GN', 'lampe', array(), array( 658, 272 ) );
$z( 105, 'Streamlight TLR-8 X (ohne Attribut)', 'lampe' );
$z( 106, 'Streamlight TLR-1 HL', 'lampe' );
$z( 107, 'Magpul Zhukov-S Klappschaft', 'schaft', array( 'laenge_eingeklappt' => '40' ) );
$z( 108, 'Magpul MOE SL-K Carbine Stock', 'schaft', array( 'laenge_eingeklappt' => '180' ) );
$z( 109, 'Magpul MOE SL-K ohne Länge', 'schaft' );
$z( 110, 'Magpul PRS Gen3 Rifle Stock', 'schaft' );
$z( 111, 'Schulterstütze unbekannt', 'schaft' );
$z( 112, 'SB Tactical Pistol Brace', 'schaft', array( 'schaft_art' => 'fest' ) );
$z( 113, 'Buffer Tube Abdeckung', 'schaft', array( 'schaft_art' => 'ohne' ) );
$z( 114, 'Magazinboden +5', 'muendungsaufsatz', array( 'magazin_plus' => '5' ) ); // Gruppe egal, Feld zählt
$z( 115, 'Klappadapter AR15 (eingeklappt nicht schussfähig)', 'schaft', array( 'schaft_art' => 'klapp', 'laenge_eingeklappt' => '30', 'eingeklappt_funktion' => 'nein' ) );
$z( 116, 'Wechsellauf mit WES', 'griff', array(), array( 617, 272 ) );
$z( 117, 'Zweibein Harris', 'zweibein' );
product( 201, 'Pulsar Thermion Wärmebild-Zielfernrohr', array( 'role' => 'optik' ) );
product( 202, 'Holosun 510C', array( 'role' => 'optik' ) );

// 1. Einzelteil: nur Bedingung der Waffe.
$r = run( 2, array( 202 ) );
check( 'Glock + Rotpunkt: Stufe WES', $r['stufe'], AK_Erwerb::WES );
check( 'Glock + Rotpunkt: Satz', $r['satz'], 'Für diese Zusammenstellung brauchst du mindestens: WES (Waffenerwerbschein) + gültige Ausweiskopie (Pass/ID).' );
check( 'Glock + Rotpunkt: keine Abklärung', $r['abklaerung'], array() );

// 2. Schalldämpfer = kantonale AB, auch ohne Attribut; HUB frei. Hero 1 (Walter): SIG516 + HUB + Dämpfer.
$r = run( 1, array( 103, 101 ) );
check( 'SIG516 + Dämpfer: Stufe kantonal', $r['stufe'], AK_Erwerb::KANTON );
check( 'SIG516 + Dämpfer: Satz nennt beide Bewilligungen', $r['satz'], 'Für diese Zusammenstellung brauchst du mindestens: kantonale Ausnahmebewilligung (Schalldämpfer) + Kleine Ausn.-Bewilligung Sportschütze (Waffe) + gültige Ausweiskopie (Pass/ID).' );
check( 'SIG516 + Dämpfer: HUB keine Zeile', count( $r['zeilen'] ), 2 );
$r = run( 2, array( 102 ) );
check( 'Dämpfer ohne Attribut: kantonal', $r['stufe'], AK_Erwerb::KANTON );
check( 'Dämpfer ohne Attribut: Grund', has( $r['zeilen'], 'Art. 5 Abs. 2 Bst. d WG' ), true );

// 3. Laser = kantonale AB (Attribut gesetzt oder am Namen erkannt), Lampe ohne Laser frei.
check( 'X400 mit Attribut', run( 2, array( 104 ) )['stufe'], AK_Erwerb::KANTON );
check( 'TLR-8 X ohne Attribut', run( 2, array( 105 ) )['stufe'], AK_Erwerb::KANTON );
check( 'TLR-1 ohne Laser', run( 2, array( 106 ) )['stufe'], AK_Erwerb::WES );
$r = run( 1, array( 101, 104 ) );
check( 'Dämpfer + Laser: eine Angabe, beide Teile genannt', strpos( $r['satz'], 'kantonale Ausnahmebewilligung (Schalldämpfer, Laser)' ) !== false, true );
check( 'Wärmebild-Optik = kantonal', run( 1, array( 201 ) )['stufe'], AK_Erwerb::KANTON );

// 4. Klapp-/Teleskopschaft an Halbautomat.
$r = run( 3, array( 107 ) );
check( 'Mini Jack + Klappschaft (56 cm): kantonal', $r['stufe'], AK_Erwerb::KANTON );
check( 'Mini Jack + Klappschaft: keine Sportschützen-Ausnahme', has( $r['zeilen'], 'Sportschützen gilt dafür nicht' ), true );
check( 'Mini Jack + Klappschaft: Satz', $r['satz'], 'Für diese Zusammenstellung brauchst du mindestens: kantonale Ausnahmebewilligung + gültige Ausweiskopie (Pass/ID).' );
$r = run( 1, array( 108 ) );
check( 'SIG516 + Teleskop (94 cm): bleibt Sportschütze', $r['stufe'], AK_Erwerb::AB );
check( 'SIG516 + Teleskop: keine Abklärung', $r['abklaerung'], array() );
$r = run( 1, array( 109 ) );
check( 'Schaftlänge fehlt: Abklärung', has( $r['abklaerung'], 'keine belegte Länge' ), true );
check( 'Schaftlänge fehlt: Stufe unverändert', $r['stufe'], AK_Erwerb::AB );
$r = run( 5, array( 108 ) );
check( 'Waffenlänge fehlt: Abklärung', has( $r['abklaerung'], 'ohne Schulterstütze ist nicht erfasst' ), true );
check( 'Fester Schaft: keine Längenregel', run( 3, array( 110 ) )['abklaerung'], array() );
check( 'Bauart unbekannt: Abklärung', has( run( 1, array( 111 ) )['abklaerung'], 'Bauart' ), true );
check( 'Eingeklappt nicht schussfähig: keine Erhöhung', run( 3, array( 115 ) )['stufe'], AK_Erwerb::AB );
// Schätzung aus Bild (Karl 27.11.: nie stillschweigend erlauben): 650 + 180 = 83 cm → trotzdem Abklärung; nur klar unter 60 cm entscheidet.
check( 'Länge geschätzt', AK_Erwerb::length_without_stock( 9 ), array( 650.0, true ) );
check( 'Geschätzt 83 cm: Abklärung', has( run( 9, array( 108 ) )['abklaerung'], 'nur geschätzt' ), true );
check( 'Geschätzt 83 cm: Stufe unverändert', run( 9, array( 108 ) )['stufe'], AK_Erwerb::WES );
product( 12, 'Kurze AR geschätzt', array( 'role' => 'waffe', 'plattform' => 'ar15', 'p1' => '500,100', 'p2' => '100,100', 'ref_mm' => '400', 'slot_schaft_anchor' => '150,100' ), array( 'pa_kaliber' => '.223 Rem', 'pa_magazinkapazitaet' => '10 Schuss' ), $semi, array( 617, 272 ) );
check( 'Geschätzt 39 cm (klar unter 50): kantonal', run( 12, array( 107 ) )['stufe'], AK_Erwerb::KANTON );
// Randfeuer-Halbautomat mit Klappschaft: Längenregel gilt auch (Art. 5 Abs. 1 Bst. d WG kennt keine Kaliberausnahme).
check( '10/22 + Klappschaft (52 cm): kantonal', run( 6, array( 107 ) )['stufe'], AK_Erwerb::KANTON );

// Originalschaft: Waffenfeld laenge_eingeklappt (Gesamtlänge eingeklappt laut Waffenhersteller).
product( 13, 'Jack KRUK (Klappschaft eingeklappt 590 mm)', array( 'role' => 'waffe', 'plattform' => 'ak', 'laenge_eingeklappt' => '590' ), array( 'pa_kaliber' => '5.56x45 mm NATO', 'pa_magazinkapazitaet' => '30 Schuss' ), $semi, array( 649, 272 ) );
product( 14, 'Geissele 08-522S (eingeschoben 737 mm)', array( 'role' => 'waffe', 'plattform' => 'ar15', 'laenge_eingeklappt' => '737', 'laenge_ohne_schaft' => '640' ), array( 'pa_kaliber' => '5.56x45 mm NATO' ), $semi, array( 617, 272 ) );
check( 'Originalschaft 59 cm: kantonal', run( 13, array( 202 ) )['stufe'], AK_Erwerb::KANTON );
check( 'Originalschaft 74 cm: unverändert', run( 14, array( 202 ) )['stufe'], AK_Erwerb::WES );
$z( 118, 'Strike FSA Dual Folder', 'schaft', array( 'schaft_art' => 'klapp', 'laenge_eingeklappt' => '26' ) );
check( 'Fremdschaft +26 mm an 640 mm: 67 cm, keine Abklärung', run( 14, array( 118 ) )['abklaerung'], array() );
check( 'Fremdschaft +26 mm an 520 mm: kantonal', run( 3, array( 118 ) )['stufe'], AK_Erwerb::KANTON );
check( 'Fremdschaft ohne Wert an Waffe mit Originallänge: Abklärung', has( run( 13, array( 109 ) )['abklaerung'], 'keine belegte Länge' ), true );

// 5. Repetierer + Klapp/Teleskop: Abklärung, keine Längenregel.
$r = run( 4, array( 108 ) );
check( 'AXSR + Teleskop: Abklärung Repetierer', has( $r['abklaerung'], 'Repetierer' ), true );
check( 'AXSR + Teleskop: Stufe Strafregister', $r['stufe'], AK_Erwerb::STRAF );
check( 'AXSR ohne Schaft: Satz mit Vertrag + Strafregister', run( 4, array( 117 ) )['satz'], 'Für diese Zusammenstellung brauchst du mindestens: Auszug aus dem zentralen Strafregister (nicht älter als 3 Monate) + schriftlicher Vertrag, ab 18 Jahren + gültige Ausweiskopie (Pass/ID).' );

// 6. Brace/Schaft an Faustfeuerwaffe = Handfeuerwaffe; Magazin > 10 = AB.
$r = run( 2, array( 112 ) );
check( 'Glock 17 + Brace: Ausnahmebewilligung', $r['stufe'], AK_Erwerb::AB );
check( 'Glock 17 + Brace: Grund Handfeuerwaffe', has( $r['zeilen'], 'Art. 4a Abs. 1 WV' ), true );
check( 'Glock 17 + Brace: Zeile heisst Brace', $r['zeilen'][0]['fuer'], 'Waffe' );
$r = run( 10, array( 108 ) );
check( 'AR-Pistole + Teleskop (66 cm): bleibt AB', $r['stufe'], AK_Erwerb::AB );
$r = run( 10, array( 107 ) );
check( 'AR-Pistole + Klappschaft (52 cm): kantonal', $r['stufe'], AK_Erwerb::KANTON );

// 7. Magazin mit Verlängerung.
$r = run( 2, array( 114 ) );
check( 'Glock 17 + Boden +5 = 22 Schuss: AB', $r['stufe'], AK_Erwerb::AB );
check( 'Glock 17 + Boden +5: Grund', has( $r['zeilen'], '22 Schuss' ), true );
check( 'P211 (23 Schuss) bleibt AB aus Attribut', run( 7, array( 202 ) )['stufe'], AK_Erwerb::AB );
check( '10/22 mit 25er (Randfeuer): keine Magazinregel', run( 6, array( 202 ) )['stufe'], AK_Erwerb::WES );

// 8. Langwaffe ohne Schaft gesperrt.
$r = run( 1, array( 113 ) );
check( 'Langwaffe ohne Schaft: Sperre', has( $r['sperre'], 'Art. 33 Abs. 3 WV' ), true );
check( 'Langwaffe ohne Schaft: Satz', $r['satz'], 'Diese Zusammenstellung können wir so nicht anbieten.' );

// 9. Weitere Teile mit eigener Bedingung, Waffe ohne Attribut.
$r = run( 2, array( 116 ) );
check( 'Teil mit WES: eigene Zeile', count( $r['zeilen'] ), 2 );
$r = run( 8, array( 202 ) );
check( 'Waffe ohne Attribut: Abklärung', has( $r['abklaerung'], 'keine Erwerbsbedingung' ), true );

// 10. Texte je Teil und Terms.
check( 'Text Sportschütze oder Sammler', AK_Erwerb::terms_text( array( 649 => AK_TEST_TERMS[649], 10794 => AK_TEST_TERMS[10794], 272 => AK_TEST_TERMS[272] ) ), 'Kleine Ausn.-Bewilligung Sportschütze oder Ausnahmebewilligung klein (Sammler)' );
check( 'Text Druckluft mit Dämpfer', AK_Erwerb::terms_text( array( 658 => AK_TEST_TERMS[658], 11242 => AK_TEST_TERMS[11242], 271 => AK_TEST_TERMS[271], 272 => AK_TEST_TERMS[272] ) ), 'kantonale Ausnahmebewilligung + Auszug aus dem zentralen Strafregister (nicht älter als 3 Monate) + schriftlicher Vertrag, ab 18 Jahren' );
check( 'Karte Dämpfer ohne Attribut', AK_Erwerb::product_text( 102 ), 'kantonale Ausnahmebewilligung + Ausweiskopie' );
check( 'Karte Lampe ohne Laser', AK_Erwerb::product_text( 106 ), '' );
check( 'Karte Waffe', AK_Erwerb::product_text( 2 ), 'WES (Waffenerwerbschein) + Ausweiskopie' );
check( 'Unbekannter Term aus Name', AK_Erwerb::term_stufe( 99999, 'Kantonale Ausnahmebewilligung (neu)' ), AK_Erwerb::KANTON );
check( 'Bauart aus Name: Zhukov', AK_Erwerb::stock_type( 107 ), 'klapp' );
check( 'Bauart aus Name: SL-K', AK_Erwerb::stock_type( 108 ), 'teleskop' );
check( 'Bauart aus Name: PRS', AK_Erwerb::stock_type( 110 ), 'fest' );
check( 'Funktion aus Kategorie', AK_Erwerb::function_type( 4 ), 'repetierer' );
check( 'Funktion Pistole', AK_Erwerb::function_type( 2 ), 'halbautomat' );
check( 'Magazin «21/23 Schuss»', AK_Erwerb::magazine( 7 ), 23 );

echo "\n{$count} Prüfungen, {$fails} Fehler\n";
exit( $fails ? 1 : 0 );
