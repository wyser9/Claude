<?php
// Aufruf: php tests/test-docs.php  (ohne WordPress)
define( 'ABSPATH', __DIR__ );
require __DIR__ . '/../includes/class-akd-docs.php';
$fail = 0;
function t( $l, $c, $x = '' ) { global $fail; if ( ! $c ) { $fail++; echo "FAIL $l $x\n"; } else { echo "ok   $l\n"; } }
$d = function ( $s ) { return gmmktime( 0, 0, 0, (int) substr( $s, 3, 2 ), (int) substr( $s, 0, 2 ), (int) substr( $s, 6, 4 ) ); };
$now = $d( '28.11.2026' );

t( 'Ausweis', 'ausweis' === AKD_Docs::classify( 'Ausweis Muster.pdf' ) );
t( 'ID-Karte', 'ausweis' === AKD_Docs::classify( 'ID_Muster_Hans.jpg' ) );
t( 'Identitätskarte', 'ausweis' === AKD_Docs::classify( 'Identitätskarte.pdf' ) );
t( 'Pass', 'ausweis' === AKD_Docs::classify( 'Pass Hans.pdf' ) );
t( 'Passfoto kein Pass? (Teilwort)', null === AKD_Docs::classify( 'Passfoto.pdf' ) );
t( 'Ausländerausweis', 'ausweis' === AKD_Docs::classify( 'Ausländerausweis B bis 30.06.2028.pdf' ) );
t( 'Niederlassungsbewilligung', 'ausweis' === AKD_Docs::classify( 'Niederlassungsbewilligung C.pdf' ) );
t( 'Permis de séjour', 'ausweis' === AKD_Docs::classify( 'Permis de séjour.pdf' ) );
t( 'Führerausweis zählt nicht', null === AKD_Docs::classify( 'Führerausweis.pdf' ) );
t( 'WES', 'wes' === AKD_Docs::classify( 'WES 2025.pdf' ) );
t( 'Waffenerwerbsschein', 'wes' === AKD_Docs::classify( 'Waffenerwerbsschein_Muster.pdf' ) );
t( 'Strafregister', 'strafregister' === AKD_Docs::classify( 'Strafregisterauszug Muster.pdf' ) );
t( 'Jagdschein', 'jagdschein' === AKD_Docs::classify( 'Jagdpass 2026.pdf' ) );
t( 'Rechnung unbekannt', null === AKD_Docs::classify( 'Rechnung RE2026.pdf' ) );
t( 'Wesen kein WES', null === AKD_Docs::classify( 'Wesemann.pdf' ) );

t( 'Datum d.m.Y', array( $d( '31.05.2031' ) ) === AKD_Docs::dates( 'Ausweis bis 31.05.2031.pdf' ) );
t( 'Datum Y-m-d', array( $d( '12.03.2025' ) ) === AKD_Docs::dates( 'WES_2025-03-12.pdf' ) );
t( 'Datum Ymd', array( $d( '01.11.2026' ) ) === AKD_Docs::dates( 'Strafregister 20261101.pdf' ) );
t( 'Monat/Jahr = Monatsende', array( $d( '31.05.2031' ) ) === AKD_Docs::dates( 'ID 05.2031.pdf' ) );
t( 'd.m.yy', array( $d( '12.03.2025' ) ) === AKD_Docs::dates( 'WES 12.03.25.pdf' ) );
t( 'Kein Datum aus Jahr allein', array() === AKD_Docs::dates( 'WES 2025.pdf' ) );
t( 'Ungültiges Datum', array() === AKD_Docs::dates( 'Scan 45.13.2026.pdf' ) );

// Alles da.
$r = AKD_Docs::evaluate( array(
	array( 'name' => 'Ausweis bis 31.05.2031.pdf', 'created' => 0 ),
	array( 'name' => 'WES 12.03.2025.pdf', 'created' => 0 ),
	array( 'name' => 'Rechnung.pdf', 'created' => 0 ),
), $now );
t( 'vollständig', 'ok' === $r['status'], print_r( $r, true ) );
t( 'unbekannte Datei gemeldet', array( 'Rechnung.pdf' ) === $r['unknown'] );
t( 'WES bis 12.03.2027', $d( '12.03.2027' ) === $r['docs']['wes']['until'] );

// WES zu alt, Strafregister frisch per Upload-Datum.
$r = AKD_Docs::evaluate( array(
	array( 'name' => 'Ausweis bis 31.05.2031.pdf', 'created' => 0 ),
	array( 'name' => 'WES 01.11.2024.pdf', 'created' => 0 ),
	array( 'name' => 'Strafregisterauszug.pdf', 'created' => $d( '15.10.2026' ) ),
), $now );
t( 'Strafregister per Upload-Datum ok', 'ok' === $r['status'] && 'Upload-Datum' === $r['docs']['strafregister']['date_source'] );
t( 'WES zu alt', 'fehlt' === $r['docs']['wes']['status'] );

// Strafregister 4 Monate alt.
$r = AKD_Docs::evaluate( array(
	array( 'name' => 'Ausweis bis 31.05.2031.pdf', 'created' => 0 ),
	array( 'name' => 'Strafregister 01.07.2026.pdf', 'created' => 0 ),
), $now );
t( 'Strafregister zu alt -> fehlt', 'fehlt' === $r['status'] && 1 === count( $r['missing'] ) );

// Ausweis abgelaufen, mehrere Kopien: neueste zählt.
$r = AKD_Docs::evaluate( array(
	array( 'name' => 'Ausweis bis 01.01.2020.pdf', 'created' => 0 ),
	array( 'name' => 'Ausweis bis 01.01.2030.pdf', 'created' => 0 ),
	array( 'name' => 'WES 12.03.2025.pdf', 'created' => 0 ),
), $now );
t( 'neueste Ausweiskopie zählt', 'ok' === $r['status'] );
$r = AKD_Docs::evaluate( array(
	array( 'name' => 'ID bis 01.01.2026.pdf', 'created' => 0 ),
	array( 'name' => 'WES 12.03.2025.pdf', 'created' => 0 ),
), $now );
t( 'Ausweis abgelaufen -> fehlt', 'fehlt' === $r['status'] && false !== strpos( $r['missing'][0], 'abgelaufen' ) );

// Ausweis ohne Datum.
$r = AKD_Docs::evaluate( array(
	array( 'name' => 'Ausweis.pdf', 'created' => $d( '01.01.2020' ) ),
	array( 'name' => 'WES 12.03.2025.pdf', 'created' => 0 ),
), $now );
t( 'Ausweis ohne Datum -> prüfen', 'pruefen' === $r['status'] );

// Nichts.
$r = AKD_Docs::evaluate( array(), $now );
t( 'nichts vorhanden -> fehlt, 2 Punkte', 'fehlt' === $r['status'] && 2 === count( $r['missing'] ) );

// Grenzfall: WES genau 2 Jahre alt am Stichtag noch gültig.
$r = AKD_Docs::evaluate( array( array( 'name' => 'Ausweis 12.2030.pdf', 'created' => 0 ), array( 'name' => 'WES 28.11.2024.pdf', 'created' => 0 ) ), $now );
t( 'WES genau 2 Jahre noch ok', 'ok' === $r['status'] );

echo $fail ? "\n$fail FEHLER\n" : "\nAlle Tests ok\n";
exit( $fail ? 1 : 0 );
