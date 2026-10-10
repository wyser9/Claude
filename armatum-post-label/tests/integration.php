<?php
/**
 * Integrationstest mit simulierter Post-API (nur in einer Test-Installation ausführen!):
 *   wp eval-file wp-content/plugins/armatum-post-label/tests/integration.php
 */
$GLOBALS['fail'] = 0;
$GLOBALS['apl_req'] = array();
$GLOBALS['apl_quality'] = 'CERTIFIED';
function t( $label, $cond, $extra = '' ) { if ( ! $cond ) { $GLOBALS['fail']++; echo "FAIL $label $extra\n"; } else { echo "ok   $label\n"; } }
function r( $code, $data ) { return array( 'headers' => array(), 'body' => is_string( $data ) ? $data : wp_json_encode( $data ), 'response' => array( 'code' => $code, 'message' => '' ), 'cookies' => array(), 'filename' => null ); }

add_filter( 'pre_http_request', function ( $pre, $args, $url ) {
	if ( false === strpos( $url, 'post.ch' ) ) return $pre;
	$GLOBALS['apl_req'][] = array( $url, $args );
	if ( false !== strpos( $url, 'OAuth/token' ) ) return r( 200, array( 'access_token' => 'TOKEN123', 'expires_in' => 3600 ) );
	if ( false !== strpos( $url, 'addresses/validation' ) ) return r( 200, array( 'quality' => $GLOBALS['apl_quality'] ) );
	if ( false !== strpos( $url, 'generateAddressLabel' ) ) return r( 200, array( 'item' => array( 'identCode' => '996012345600000099', 'label' => array( base64_encode( '%PDF-1.4 test' ) ) ) ) );
	return r( 404, array() );
}, 10, 3 );

update_option( 'woocommerce_weight_unit', 'kg' );
update_option( 'armatum_post_client_id', 'cid' );
update_option( 'armatum_post_client_secret', 'secret' );
update_option( 'armatum_post_franking_license', '60123456' );
delete_option( 'armatum_post_test_mode' );
delete_transient( APL_Api::TOKEN_CACHE );

// --- Aufräumen der Version 0.1 bei Aktivierung ---
$up = wp_upload_dir();
wp_mkdir_p( $up['basedir'] . '/post-labels' );
file_put_contents( $up['basedir'] . '/post-labels/post-label-15512.pdf', 'alt' );
file_put_contents( $up['basedir'] . '/post-labels/post-label-15432.pdf', 'alt' );
$legacy = wc_create_order(); $legacy->save();
update_post_meta( $legacy->get_id(), '_armatum_post_tracking_number', '996016135300000001' );
update_post_meta( $legacy->get_id(), '_armatum_post_label_pdf', $up['basedir'] . '/post-labels/post-label-15512.pdf' );
APL_Label::activate();
t( 'Alte Muster-PDFs gelöscht', ! file_exists( $up['basedir'] . '/post-labels/post-label-15512.pdf' ) && ! is_dir( $up['basedir'] . '/post-labels' ) );
t( 'Alte Meta entfernt', '' === get_post_meta( $legacy->get_id(), '_armatum_post_tracking_number', true ) );
t( 'Aufräum-Protokoll', 2 === get_option( 'apl_legacy_cleanup' )['deleted'] );
t( 'Testmodus standardmässig an', 'yes' === get_option( 'armatum_post_test_mode' ) );
t( 'Geschützter Ordner mit .htaccess', file_exists( $up['basedir'] . '/armatum-post-labels/.htaccess' ) && false !== strpos( file_get_contents( $up['basedir'] . '/armatum-post-labels/.htaccess' ), 'Require all denied' ) );

// --- Bestellung ---
$p = new WC_Product_Simple(); $p->set_props( array( 'name' => 'Holster', 'regular_price' => '50', 'weight' => '0.454' ) ); $p->save();
$order = wc_create_order();
$order->add_product( wc_get_product( $p->get_id() ), 2 );
$order->set_address( array( 'first_name' => 'Anna', 'last_name' => 'Beispiel', 'company' => 'Muster AG', 'address_1' => 'Bahnhofstrasse 12a', 'address_2' => 'c/o Lager', 'postcode' => '8001', 'city' => 'Zürich', 'country' => 'CH' ), 'shipping' );
$ship = new WC_Order_Item_Shipping(); $ship->set_method_title( 'Kleinsendung Signature' ); $ship->set_method_id( 'wbsng' ); $order->add_item( $ship );
$order->calculate_totals(); $order->save();

t( 'Gewicht aus Produkten in Gramm', 908 === APL_Label::order_weight_g( $order ) );
t( 'Signature vorgeschlagen', APL_Util::suggest_signature( APL_Label::shipping_names( $order ) ) );

$ident = APL_Label::create( $order, 908, true, false );
$order = wc_get_order( $order->get_id() );
$label_req = null; $addr_req = null;
foreach ( $GLOBALS['apl_req'] as $req ) {
	if ( false !== strpos( $req[0], 'generateAddressLabel' ) ) $label_req = json_decode( $req[1]['body'], true ) + array( '_headers' => $req[1]['headers'] );
	if ( false !== strpos( $req[0], 'validation' ) ) $addr_req = json_decode( $req[1]['body'], true );
}
t( 'Sendungsnummer gespeichert', '996012345600000099' === $ident && $ident === $order->get_meta( '_apl_ident_code' ) );
t( 'Gewicht als ganze Gramm', 908 === $label_req['item']['attributes']['weight'] );
t( 'Leistungen ECO + SI', array( 'ECO', 'SI' ) === $label_req['item']['attributes']['przl'] );
t( 'Testmodus -> printPreview true', true === $label_req['labelDefinition']['printPreview'] );
$rec = $label_req['item']['recipient'];
t( 'Firma + Person + Zusatz', 'Muster AG' === $rec['name1'] && 'Anna Beispiel' === $rec['name2'] && 'c/o Lager' === $rec['addressSuffix'] );
t( 'Strasse/Hausnummer getrennt', 'Bahnhofstrasse' === $rec['street'] && '12a' === $rec['houseNo'] );
t( 'Absender Armatum', 'Armatum GmbH' === $label_req['customer']['name1'] && '4629' === $label_req['customer']['zip'] );
t( 'Bearer + client_id Header', 'Bearer TOKEN123' === $label_req['_headers']['Authorization'] && 'cid' === $label_req['_headers']['client_id'] );
t( 'Adressprüfung mit Hausnummer', '12a' === $addr_req['logisticLocation']['house']['houseNumber'] );
$file = $order->get_meta( '_apl_label_file' );
t( 'PDF im geschützten Ordner mit Zufallsnamen', preg_match( '/^label-\d+-[A-Za-z0-9]{24}\.pdf$/', $file ) && is_file( $up['basedir'] . '/armatum-post-labels/' . $file ) );
$notes = wc_get_order_notes( array( 'order_id' => $order->get_id() ) );
t( 'Notiz kennzeichnet Testlabel', false !== strpos( $notes[0]->content, 'TESTLABEL' ) && false !== strpos( $notes[0]->content, 'Signature' ) );

// Token wird zwischengespeichert.
$GLOBALS['apl_req'] = array();
update_option( 'armatum_post_test_mode', 'no' );
APL_Label::create( $order, 1500, false, true );
$tokens = array_filter( $GLOBALS['apl_req'], function ( $q ) { return false !== strpos( $q[0], 'OAuth' ); } );
t( 'Token wiederverwendet', 0 === count( $tokens ) );
$last = json_decode( end( $GLOBALS['apl_req'] )[1]['body'], true );
t( 'Echtbetrieb -> printPreview false, ECO + SP', false === $last['labelDefinition']['printPreview'] && array( 'ECO', 'SP' ) === $last['item']['attributes']['przl'] );
$order = wc_get_order( $order->get_id() );
t( 'Altes PDF beim Neuerstellen entfernt', ! is_file( $up['basedir'] . '/armatum-post-labels/' . $file ) );

// E-Mail "abgeschlossen" enthält Tracking (nicht bei Testlabel).
$order->set_status( 'completed' ); $order->save();
ob_start(); APL_Label::email_tracking( $order, false, false ); $mail = ob_get_clean();
t( 'Tracking in Kunden-E-Mail', false !== strpos( $mail, 'swisspost-tracking' ) && false !== strpos( $mail, '996012345600000099' ) );
$order->update_meta_data( '_apl_preview', 'yes' ); $order->save();
ob_start(); APL_Label::email_tracking( $order, false, false ); $mail = ob_get_clean();
t( 'Kein Tracking bei Testlabel', '' === $mail );

// Fehlerfälle.
$err = function ( $fn ) { try { $fn(); return ''; } catch ( Exception $e ) { return $e->getMessage(); } };
t( 'Gewicht 0 abgelehnt', false !== strpos( $err( function () use ( $order ) { APL_Label::create( $order, 0, false, false ); } ), 'Gewicht' ) );
t( 'Über 30 kg abgelehnt', false !== strpos( $err( function () use ( $order ) { APL_Label::create( $order, 31000, false, false ); } ), '30 kg' ) );
$GLOBALS['apl_quality'] = 'UNUSABLE';
t( 'Unbrauchbare Adresse blockiert', false !== strpos( $err( function () use ( $order ) { APL_Label::create( $order, 1000, false, false ); } ), 'korrigieren' ) );
$GLOBALS['apl_quality'] = 'CERTIFIED';
$pick = wc_create_order(); $ps = new WC_Order_Item_Shipping(); $ps->set_method_title( 'Abholung vor Ort' ); $ps->set_method_id( 'local_pickup' ); $pick->add_item( $ps ); $pick->save();
t( 'Abholung abgelehnt', false !== strpos( $err( function () use ( $pick ) { APL_Label::create( $pick, 1000, false, false ); } ), 'abgeholt' ) );

// Box rendert.
ob_start(); APL_Admin::render_box( $order ); $box = ob_get_clean();
t( 'Box zeigt Sendungsnummer und Formular', false !== strpos( $box, '996012345600000099' ) && false !== strpos( $box, 'apl_create_label' ) && false !== strpos( $box, 'data-apl="apl_weight"' ) );

echo $GLOBALS['fail'] ? "\n" . $GLOBALS['fail'] . " FEHLER\n" : "\nAlle Integrationstests bestanden.\n";
