<?php
/**
 * Tests für BWS_Util (ohne WordPress): php tests/test-util.php
 */
define( 'ABSPATH', __DIR__ );
require __DIR__ . '/../includes/class-bws-util.php';

$failures = 0;
function check( $label, $expected, $actual ) {
	global $failures;
	if ( $expected !== $actual ) {
		$failures++;
		echo "FAIL: $label\n  erwartet: " . var_export( $expected, true ) . "\n  erhalten: " . var_export( $actual, true ) . "\n";
	} else {
		echo "ok   $label\n";
	}
}

check( 'Strasse mit Nummer', array( 'Bahnhofstrasse', '12' ), BWS_Util::split_street( 'Bahnhofstrasse 12' ) );
check( 'Nummer mit Zusatz', array( 'Bahnhofstrasse', '12a' ), BWS_Util::split_street( 'Bahnhofstrasse 12a' ) );
check( 'Nummer mit Leerzeichen-Zusatz', array( 'Seestr.', '5b' ), BWS_Util::split_street( 'Seestr. 5 b' ) );
check( 'Nummernbereich', array( 'Hauptstrasse', '10-12' ), BWS_Util::split_street( 'Hauptstrasse 10-12' ) );
check( 'Französisches Format', array( 'Rue du Lac', '12' ), BWS_Util::split_street( '12 Rue du Lac' ) );
check( 'Mehrteiliger Name', array( 'Avenue de la Gare', '3' ), BWS_Util::split_street( 'Avenue de la Gare 3' ) );
check( 'Ohne Nummer', array( 'Postfach', '' ), BWS_Util::split_street( 'Postfach' ) );
check( 'Leer', array( '', '' ), BWS_Util::split_street( '  ' ) );

check( 'Steuersatz 8.1', 8.1, BWS_Util::tax_rate( 100, 8.1 ) );
check( 'Steuersatz gerundet', 8.1, BWS_Util::tax_rate( 27.66, 2.24 ) );
check( 'Steuersatz 0 bei Betrag 0', 0.0, BWS_Util::tax_rate( 0, 0 ) );

$taxes = array(
	array( 'id' => 11, 'value' => 8.1 ),
	array( 'id' => 12, 'value' => 2.6 ),
	array( 'id' => 13, 'value' => 3.8 ),
	array( 'id' => 14, 'value' => 0 ),
);
check( 'Steuer-ID 8.1', 11, BWS_Util::match_tax_id( 8.1, $taxes ) );
check( 'Steuer-ID mit Rundungsabweichung', 11, BWS_Util::match_tax_id( 8.07, $taxes ) );
check( 'Steuer-ID 2.6', 12, BWS_Util::match_tax_id( 2.6, $taxes ) );
check( 'Steuer-ID 0', 14, BWS_Util::match_tax_id( 0.0, $taxes ) );
check( 'Keine passende Steuer', null, BWS_Util::match_tax_id( 19, $taxes ) );

check( 'Betrag ganzzahlig', '2', BWS_Util::amount( 2.0 ) );
check( 'Betrag Dezimal', '10.833333', BWS_Util::amount( 32.5 / 3 ) );
check( 'Betrag 2 Stellen', '19.9', BWS_Util::amount( 19.9, 2 ) );
check( 'Betrag 0', '0', BWS_Util::amount( -0.0000001 ) );

check( 'Titel = Nummer', true, BWS_Util::title_matches_order_number( '12345', '12345' ) );
check( 'Titel mit Beschreibung', true, BWS_Util::title_matches_order_number( '12345 Max Muster Kaffee', '12345' ) );
check( 'Titel mit Präfix', true, BWS_Util::title_matches_order_number( 'WooCommerce Bestellung #12345', '12345' ) );
check( 'Titel Nummer am Ende', true, BWS_Util::title_matches_order_number( 'Shop 12345', '12345' ) );
check( 'Längere Nummer passt nicht', false, BWS_Util::title_matches_order_number( '123456 Muster', '12345' ) );
check( 'Nummer in anderer Zahl', false, BWS_Util::title_matches_order_number( 'A-112345', '12345' ) );
check( 'Kurze Nummer nicht in langer', false, BWS_Util::title_matches_order_number( '1234 Muster', '123' ) );
check( 'Leere Nummer', false, BWS_Util::title_matches_order_number( '12345', '' ) );

check( 'Kürzen', 'abc', BWS_Util::truncate( ' abcdef ', 3 ) );

echo $failures ? "\n$failures Fehler\n" : "\nAlle Tests bestanden.\n";
exit( $failures ? 1 : 0 );
