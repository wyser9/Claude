<?php
/**
 * Tests für APL_Util (ohne WordPress): php tests/test-util.php
 */
define( 'ABSPATH', __DIR__ );
require __DIR__ . '/../includes/class-apl-util.php';

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

check( 'Strasse/Nr', array( 'Färchstrasse', '6b' ), APL_Util::split_street( 'Färchstrasse 6b' ) );
check( 'Ohne Nr', array( 'Postfach', '' ), APL_Util::split_street( 'Postfach' ) );
check( 'kg -> g', 454, APL_Util::to_grams( 0.454, 'kg' ) );
check( 'g -> g', 1250, APL_Util::to_grams( 1250, 'g' ) );
check( 'Gewicht 0 = Fehler', true, null !== APL_Util::weight_error( 0 ) );
check( 'Gewicht 30 kg ok', null, APL_Util::weight_error( 30000 ) );
check( 'Gewicht > 30 kg = Fehler', true, null !== APL_Util::weight_error( 30001 ) );
check( 'Nur Economy', array( 'ECO' ), APL_Util::services( false, false ) );
check( 'Economy + Signature + Sperrgut', array( 'ECO', 'SI', 'SP' ), APL_Util::services( true, true ) );
check( 'Signature-Vorschlag', true, APL_Util::suggest_signature( array( 'Kleinsendung Signature' ) ) );
check( 'Signature bei Waffen', true, APL_Util::suggest_signature( array( 'Waffen Standard' ) ) );
check( 'Kein Signature-Vorschlag', false, APL_Util::suggest_signature( array( 'bis 10kg', 'Versandkosten' ) ) );
check( 'Kein Signature bei „ohne Munition/Waffen“', false, APL_Util::suggest_signature( array( 'Gratisversand ab CHF 150 (ohne Munition/Waffen)' ) ) );
check( 'Kein Signature bei „exkl. Waffen“', false, APL_Util::suggest_signature( array( 'Standard exkl. Waffen' ) ) );
check( 'Signature trotz Zusatz', true, APL_Util::suggest_signature( array( 'Waffenversand Signature (ohne Munition)' ) ) );
check( 'Sperrgut bei Überlänge', true, APL_Util::suggest_bulky( array( 'Versandkosten PostPac Economy Signature Überlänge' ) ) );
check( 'Kein Sperrgut bei „ohne Sperrgut“', false, APL_Util::suggest_bulky( array( 'Paket (ohne Sperrgut)' ) ) );
check( 'Sperrgut-Vorschlag', true, APL_Util::suggest_bulky( array( 'PostPac Economy Sperrgut' ) ) );
check( 'Tracking-Link', 'https://www.post.ch/swisspost-tracking?formattedParcelCodes=996016135300000001', APL_Util::tracking_url( '9960 1613 5300 0000 01' ) );

echo $failures ? "\n$failures Fehler\n" : "\nAlle Tests bestanden.\n";
exit( $failures ? 1 : 0 );
