<?php
/**
 * Integrationstest mit gemockter bexio-API (verändert die Datenbank – nur in einer Test-Installation ausführen!):
 *   wp eval-file wp-content/plugins/bexio-woocommerce-sync/tests/integration.php
 */
$GLOBALS['bws_requests'] = array();
$GLOBALS['bws_next_id']  = 100;
$GLOBALS["fail"] = 0;
function t( $label, $cond, $extra = '' ) { if ( ! $cond ) { $GLOBALS["fail"]++; echo "FAIL $label $extra\n"; } else { echo "ok   $label\n"; } }
function resp( $data, $code = 200 ) { return array( 'headers' => array(), 'body' => wp_json_encode( $data ), 'response' => array( 'code' => $code, 'message' => '' ), 'cookies' => array(), 'filename' => null ); }

add_filter( 'pre_http_request', function ( $pre, $args, $url ) {
	if ( 0 !== strpos( $url, 'https://api.bexio.com/' ) ) { return $pre; }
	$path = preg_replace( '#\?.*$#', '', substr( $url, strlen( 'https://api.bexio.com/' ) ) );
	$body = isset( $args['body'] ) ? json_decode( $args['body'], true ) : null;
	$GLOBALS['bws_requests'][] = array( $args['method'], $path, $body, $args['headers'] );
	$m = $args['method'];
	if ( 'GET' === $m && '3.0/users/me' === $path ) return resp( array( 'id' => 7, 'firstname' => 'Max', 'lastname' => 'Muster', 'email' => 'm@x.ch' ) );
	if ( 'GET' === $m && '3.0/taxes' === $path ) return resp( array( array( 'id' => 11, 'value' => 8.1, 'name' => 'UN81' ), array( 'id' => 12, 'value' => 2.6, 'name' => 'UR26' ), array( 'id' => 14, 'value' => 0, 'name' => 'UN00' ) ) );
	if ( 'GET' === $m && '2.0/country' === $path ) return resp( array( array( 'id' => 1, 'name' => 'Schweiz', 'name_short' => 'CH', 'iso3166_alpha2' => 'CH' ), array( 'id' => 2, 'name' => 'Deutschland', 'name_short' => 'DE', 'iso3166_alpha2' => 'DE' ) ) );
	if ( 'GET' === $m && '3.0/currencies' === $path ) return resp( array( array( 'id' => 1, 'name' => 'CHF' ), array( 'id' => 2, 'name' => 'EUR' ) ) );
	if ( 'GET' === $m && '2.0/article' === $path ) return resp( $GLOBALS['bws_bexio_articles'] ?? array() );
	if ( 'POST' === $m && '2.0/article/search' === $path ) return resp( 'EXIST-1' === $body[0]['value'] ? array( array( 'id' => 55, 'intern_code' => 'EXIST-1' ) ) : array() );
	if ( 'POST' === $m && '2.0/article' === $path ) return resp( array_merge( $body, array( 'id' => ++$GLOBALS['bws_next_id'] ) ) );
	if ( 'POST' === $m && preg_match( '#^2\.0/article/(\d+)$#', $path, $mm ) ) return resp( array_merge( $body, array( 'id' => (int) $mm[1] ) ) );
	if ( 'POST' === $m && '2.0/contact/search' === $path ) return resp( array() );
	if ( 'POST' === $m && '2.0/contact' === $path ) return resp( array_merge( $body, array( 'id' => 500 ) ) );
	if ( 'POST' === $m && '2.0/kb_order/search' === $path ) {
		// bexio "like" = Teilstring-Suche.
		return resp( array_values( array_filter( $GLOBALS['bws_bexio_orders'] ?? array(), function ( $o ) use ( $body ) { return false !== strpos( $o['title'], (string) $body[0]['value'] ); } ) ) );
	}
	if ( 'POST' === $m && '2.0/kb_order' === $path ) {
		$total = 0;
		foreach ( $body['positions'] as $p ) {
			if ( 'KbPositionDiscount' === $p['type'] ) { $total -= (float) $p['value']; } else { $total += (float) $p['amount'] * (float) $p['unit_price']; }
		}
		return resp( array( 'id' => 900, 'document_nr' => 'AU-00001', 'total' => number_format( $total, 2, '.', '' ) ) );
	}
	return resp( array( 'message' => 'not mocked ' . $m . ' ' . $path ), 404 );
}, 10, 3 );

// Shop: Schweiz, Preise inkl. MWST, 8.1 % Normalsatz / 2.6 % reduziert.
update_option( 'woocommerce_default_country', 'CH:ZH' );
update_option( 'woocommerce_currency', 'CHF' );
update_option( 'woocommerce_weight_unit', 'kg' );
update_option( 'woocommerce_calc_taxes', 'yes' );
update_option( 'woocommerce_prices_include_tax', 'yes' );
update_option( 'woocommerce_tax_based_on', 'base' );
WC_Tax::_insert_tax_rate( array( 'tax_rate_country' => 'CH', 'tax_rate' => '8.1', 'tax_rate_name' => 'MWST', 'tax_rate_priority' => 1, 'tax_rate_shipping' => 1, 'tax_rate_class' => '' ) );
WC_Tax::_insert_tax_rate( array( 'tax_rate_country' => 'CH', 'tax_rate' => '2.6', 'tax_rate_name' => 'MWST red.', 'tax_rate_priority' => 1, 'tax_rate_shipping' => 0, 'tax_rate_class' => 'reduced-rate' ) );

BWS_Settings::save( array( 'api_token' => 'test-token', 'unit_id' => '3', 'stock_id' => '1', 'stock_place_id' => '2', 'price_mode' => 'gross', 'stock_mode' => 'to_bexio' ) );
BWS_Lookup::flush();

// --- Artikel ---
$p1 = new WC_Product_Simple();
$p1->set_props( array( 'name' => 'Kaffeebohnen 1kg', 'sku' => 'KAF-1000', 'regular_price' => '32.50', 'manage_stock' => true, 'stock_quantity' => 20, 'tax_class' => 'reduced-rate', 'weight' => '1' ) );
$p1->save();

$p2 = new WC_Product_Simple();
$p2->set_props( array( 'name' => 'Bestehender Artikel', 'sku' => 'EXIST-1', 'regular_price' => '10' ) );
$p2->save();

$attr = new WC_Product_Attribute();
$attr->set_name( 'Grösse' ); $attr->set_options( array( 'S', 'M' ) ); $attr->set_variation( true ); $attr->set_visible( true );
$var_parent = new WC_Product_Variable();
$var_parent->set_props( array( 'name' => 'T-Shirt', 'sku' => 'TS' ) );
$var_parent->set_attributes( array( $attr ) );
$var_parent->save();
$v = new WC_Product_Variation();
$v->set_props( array( 'parent_id' => $var_parent->get_id(), 'sku' => 'TS-M', 'regular_price' => '25', 'manage_stock' => true, 'stock_quantity' => 5 ) );
$v->set_attributes( array( 'grosse' => 'M', sanitize_title( 'Grösse' ) => 'M' ) );
$v->save();

// Hooks haben Jobs eingeplant?
t( 'Produktänderung plant Job ein', as_has_scheduled_action( 'bws_sync_product', array( $p1->get_id() ), BWS_AS_GROUP ) );
t( 'Variable Eltern werden nicht abgeglichen', null === BWS_Product_Sync::sync( wc_get_product( $var_parent->get_id() ) ) );

$GLOBALS['bws_requests'] = array();
$id1 = BWS_Product_Sync::sync( wc_get_product( $p1->get_id() ) );
$create = array_values( array_filter( $GLOBALS['bws_requests'], function ( $r ) { return '2.0/article' === $r[1]; } ) )[0][2];
t( 'Artikel angelegt', $id1 > 100 );
t( 'Bearer-Token gesendet', 'Bearer test-token' === $GLOBALS['bws_requests'][0][3]['Authorization'] );
t( 'intern_code = SKU', 'KAF-1000' === $create['intern_code'] );
t( 'Steuer 2.6 % gemappt', 12 === $create['tax_income_id'] );
t( 'Lagerbestand gesendet', 20 === $create['stock_nr'] && true === $create['is_stock'] && 1 === $create['stock_id'] && 2 === $create['stock_place_id'] );
t( 'Preis', '32.5' === $create['sale_price'] );
t( 'Gewicht in g', 1000.0 === (float) $create['weight'], var_export( $create['weight'] ?? null, true ) );
t( 'Artikel-ID gespeichert', (int) get_post_meta( $p1->get_id(), '_bws_article_id', true ) === $id1 );

$GLOBALS['bws_requests'] = array();
t( 'Bestehender Artikel per SKU gefunden', 55 === BWS_Product_Sync::sync( wc_get_product( $p2->get_id() ) ) );
t( '... und aktualisiert statt neu angelegt', '2.0/article/55' === end( $GLOBALS['bws_requests'] )[1] );

$vid = BWS_Product_Sync::sync( wc_get_product( $v->get_id() ) );
$last = end( $GLOBALS['bws_requests'] )[2];
t( 'Variante als Artikel', $vid > 100 && 'TS-M' === $last['intern_code'] && false !== strpos( $last['intern_name'], 'T-Shirt' ), $last['intern_name'] );
t( 'Steuer 8.1 % für Normalsatz', 11 === $last['tax_income_id'] );

// Lagerbestand ändern -> Hook -> Update mit neuem Bestand.
as_unschedule_all_actions( 'bws_sync_product' );
$rp = new ReflectionProperty( 'BWS_Product_Sync', 'queued' ); $rp->setAccessible( true ); $rp->setValue( null, array() ); // neuer Request
wc_update_product_stock( wc_get_product( $p1->get_id() ), 17 );
t( 'Bestandsänderung plant Job ein', as_has_scheduled_action( 'bws_sync_product', array( $p1->get_id() ), BWS_AS_GROUP ) );
$GLOBALS['bws_requests'] = array();
BWS_Product_Sync::run_job( $p1->get_id() );
$upd = end( $GLOBALS['bws_requests'] );
t( 'Bestand per Update übertragen', '2.0/article/' . $id1 === $upd[1] && 17 === $upd[2]['stock_nr'], wp_json_encode( $upd ) );

// --- Bestellung ---
$coupon = new WC_Coupon(); $coupon->set_code( 'rabatt10' ); $coupon->set_discount_type( 'percent' ); $coupon->set_amount( 10 ); $coupon->save();

$order = wc_create_order();
$order->add_product( wc_get_product( $p1->get_id() ), 2 );
$order->add_product( wc_get_product( $v->get_id() ), 1 );
$order->set_address( array( 'first_name' => 'Anna', 'last_name' => 'Beispiel', 'company' => '', 'address_1' => 'Bahnhofstrasse 12a', 'address_2' => '3. Stock', 'postcode' => '8001', 'city' => 'Zürich', 'country' => 'CH', 'email' => 'anna@example.ch', 'phone' => '044 123 45 67' ), 'billing' );
$order->set_address( array( 'first_name' => 'Anna', 'last_name' => 'Beispiel', 'address_1' => 'Seeweg 3', 'postcode' => '6003', 'city' => 'Luzern', 'country' => 'CH' ), 'shipping' );
$ship = new WC_Order_Item_Shipping(); $ship->set_method_title( 'A-Post' ); $ship->set_method_id( 'flat_rate' ); $ship->set_total( 7.40 ); $order->add_item( $ship );
$order->set_payment_method_title( 'TWINT' );
$order->set_customer_note( 'Bitte klingeln' );
$order->calculate_totals();
$order->apply_coupon( 'rabatt10' );
$order->save();

$GLOBALS['bws_requests'] = array();
$order->update_status( 'processing' );
t( 'Statuswechsel plant Bestell-Job ein', as_has_scheduled_action( 'bws_sync_order', array( $order->get_id() ), BWS_AS_GROUP ) );

BWS_Order_Sync::run_job( $order->get_id() );
$order = wc_get_order( $order->get_id() );
$contact = null; $kb = null;
foreach ( $GLOBALS['bws_requests'] as $r ) { if ( '2.0/contact' === $r[1] ) $contact = $r[2]; if ( '2.0/kb_order' === $r[1] ) $kb = $r[2]; }

t( 'Kontakt als Person', 2 === $contact['contact_type_id'] && 'Beispiel' === $contact['name_1'] && 'Anna' === $contact['name_2'] );
t( 'Adresse zerlegt', 'Bahnhofstrasse' === $contact['street_name'] && '12a' === $contact['house_number'] && '3. Stock' === $contact['address_addition'] );
t( 'Land gemappt', 1 === $contact['country_id'] );
t( 'Kontakt E-Mail / Owner', 'anna@example.ch' === $contact['mail'] && 7 === $contact['owner_id'] );

t( 'Auftrag erstellt', 900 === (int) $order->get_meta( '_bws_order_id' ) && 'AU-00001' === $order->get_meta( '_bws_order_nr' ) );
t( 'Auftrag Kontakt/Währung', 500 === $kb['contact_id'] && 1 === $kb['currency_id'] );
t( 'Brutto-Modus', 0 === $kb['mwst_type'] && false === $kb['mwst_is_net'] );
t( 'api_reference', 'woocommerce-' . $order->get_id() === $kb['api_reference'] );
t( 'Lieferadresse abweichend', 1 === $kb['delivery_address_type'] && false !== strpos( $kb['delivery_address_manual'], 'Seeweg 3' ) && ! isset( $kb['delivery_address'] ) );
$types = array_column( $kb['positions'], 'type' );
t( 'Positionstypen', array( 'KbPositionArticle', 'KbPositionArticle', 'KbPositionDiscount', 'KbPositionCustom' ) === $types, implode( ',', $types ) );
t( 'Artikelposition verknüpft', $id1 === $kb['positions'][0]['article_id'] && '2' === $kb['positions'][0]['amount'] && '32.5' === $kb['positions'][0]['unit_price'] && 12 === $kb['positions'][0]['tax_id'], wp_json_encode( $kb['positions'][0] ) );
t( 'Versand mit 8.1 %', 11 === $kb['positions'][3]['tax_id'] );
t( 'Header enthält Zahlungsart', false !== strpos( $kb['header'], 'TWINT' ) && false !== strpos( $kb['header'], 'Bitte klingeln' ) );

$sum = 0;
foreach ( $kb['positions'] as $p ) { $sum += 'KbPositionDiscount' === $p['type'] ? -(float) $p['value'] : (float) $p['amount'] * (float) $p['unit_price']; }
t( 'Summe Positionen = Bestelltotal', abs( $sum - (float) $order->get_total() ) < 0.011, "$sum vs " . $order->get_total() );
$notes = wc_get_order_notes( array( 'order_id' => $order->get_id() ) );
t( 'Bestellnotiz ohne Abweichung', false !== strpos( $notes[0]->content, 'AU-00001' ) && false === strpos( $notes[0]->content, 'Achtung' ), $notes[0]->content );

// Keine doppelte Übertragung.
$GLOBALS['bws_requests'] = array();
$order->update_status( 'completed' );
BWS_Order_Sync::sync( $order );
t( 'Keine Doppelübertragung', ! array_filter( $GLOBALS['bws_requests'], function ( $r ) { return '2.0/kb_order' === $r[1]; } ) );

// Fehlerfall: kein passender Steuersatz.
update_option( 'woocommerce_prices_include_tax', 'no' );
BWS_Settings::save( array( 'price_mode' => 'net' ) );
WC_Tax::_insert_tax_rate( array( 'tax_rate_country' => 'CH', 'tax_rate' => '19', 'tax_rate_name' => 'X', 'tax_rate_priority' => 1, 'tax_rate_class' => 'zero-rate' ) );
$p3 = new WC_Product_Simple(); $p3->set_props( array( 'name' => 'X', 'sku' => 'X-1', 'regular_price' => '5', 'tax_class' => 'zero-rate' ) ); $p3->save();
try { BWS_Product_Sync::sync( wc_get_product( $p3->get_id() ) ); t( 'Fehler bei unbekanntem Steuersatz', false ); } catch ( BWS_Exception $e ) { t( 'Fehler bei unbekanntem Steuersatz', false !== strpos( $e->getMessage(), '19' ) ); }

// Netto-Auftrag + Firmenkunde.
$o2 = wc_create_order();
$o2->add_product( wc_get_product( $p1->get_id() ), 3 );
$o2->set_address( array( 'company' => 'Muster AG', 'first_name' => 'Peter', 'last_name' => 'Muster', 'address_1' => '12 Rue du Lac', 'postcode' => '1201', 'city' => 'Genève', 'country' => 'CH', 'email' => 'peter@muster.ch' ), 'billing' );
$o2->calculate_totals();
$o2->save();
$GLOBALS['bws_requests'] = array();
BWS_Order_Sync::sync( $o2 );
foreach ( $GLOBALS['bws_requests'] as $r ) { if ( '2.0/contact' === $r[1] ) $contact = $r[2]; if ( '2.0/kb_order' === $r[1] ) $kb = $r[2]; }
t( 'Firmenkontakt', 1 === $contact['contact_type_id'] && 'Muster AG' === $contact['name_1'] && 'Rue du Lac' === $contact['street_name'] && '12' === $contact['house_number'] );
t( 'Netto-Modus', true === $kb['mwst_is_net'] && 0 === $kb['delivery_address_type'] );
$net = 0; foreach ( $kb['positions'] as $p ) { $net += (float) $p['amount'] * (float) $p['unit_price']; }
t( 'Netto-Summe = Total exkl. MWST', abs( $net - ( (float) $o2->get_total() - (float) $o2->get_total_tax() ) ) < 0.011, "$net" );


// --- Bereits vorhandene Aufträge in bexio ---
$mk = function ( $email ) use ( $p1 ) {
	$o = wc_create_order();
	$o->add_product( wc_get_product( $p1->get_id() ), 1 );
	$o->set_address( array( 'first_name' => 'Alt', 'last_name' => 'Kunde', 'address_1' => 'Weg 1', 'postcode' => '8000', 'city' => 'Zürich', 'country' => 'CH', 'email' => $email ), 'billing' );
	$o->calculate_totals();
	$o->save();
	return $o;
};
$old = $mk( 'alt@example.ch' );
$other = $mk( 'neu@example.ch' );
$GLOBALS['bws_bexio_orders'] = array(
	array( 'id' => 4711, 'document_nr' => 'AU-00042', 'title' => $old->get_order_number() . ' Kaffeebohnen Alt Kunde', 'api_reference' => null ),
	array( 'id' => 4712, 'document_nr' => 'AU-00043', 'title' => $other->get_order_number() . '9 andere Bestellung', 'api_reference' => null ),
);
$GLOBALS['bws_requests'] = array();
$linked = BWS_Order_Sync::sync( wc_get_order( $old->get_id() ) );
$old = wc_get_order( $old->get_id() );
$writes = array_filter( $GLOBALS['bws_requests'], function ( $r ) { return in_array( $r[1], array( '2.0/kb_order', '2.0/contact' ), true ); } );
t( 'Bestehender Auftrag gefunden und verknüpft', 4711 === $linked && 4711 === (int) $old->get_meta( '_bws_order_id' ) && 'AU-00042' === $old->get_meta( '_bws_order_nr' ) );
t( 'Kein Auftrag und kein Kontakt angelegt', ! $writes, wp_json_encode( array_values( $writes ) ) );
$n = wc_get_order_notes( array( 'order_id' => $old->get_id() ) );
t( 'Notiz zur Verknüpfung', false !== strpos( $n[0]->content, 'AU-00042' ) && false !== strpos( $n[0]->content, 'kein neuer Auftrag' ), $n[0]->content );

$GLOBALS['bws_requests'] = array();
$new_id = BWS_Order_Sync::sync( wc_get_order( $other->get_id() ) );
t( 'Ähnliche Nummer (…9) zählt nicht als Duplikat -> neuer Auftrag', 900 === $new_id && array_filter( $GLOBALS['bws_requests'], function ( $r ) { return '2.0/kb_order' === $r[1]; } ) );

// Statuswechsel einer alten, bereits verknüpften Bestellung -> kein Job.
as_unschedule_all_actions( 'bws_sync_order' );
$old->update_status( 'completed' );
t( 'Statuswechsel verknüpfter Bestellung plant nichts ein', ! as_has_scheduled_action( 'bws_sync_order', array( $old->get_id() ), BWS_AS_GROUP ) );

// Titel ohne Präfix = nur Bestellnummer.
BWS_Settings::save( array( 'order_title_prefix' => '' ) );
t( 'Titel ohne Präfix', (string) $other->get_order_number() === BWS_Order_Sync::title( $other ) );
BWS_Settings::save( array( 'order_title_prefix' => 'WooCommerce Bestellung' ) );

// Suche schlägt fehl -> nichts anlegen.
$fail_search = function ( $pre, $args, $url ) { return false !== strpos( $url, 'kb_order/search' ) ? resp( array( 'message' => 'kaputt' ), 400 ) : $pre; };
add_filter( 'pre_http_request', $fail_search, 20, 3 );
$o4 = $mk( 'fehler@example.ch' );
$GLOBALS['bws_requests'] = array();
try { BWS_Order_Sync::sync( $o4 ); t( 'Fehler bei Duplikatsuche bricht ab', false ); } catch ( BWS_Exception $e ) { t( 'Fehler bei Duplikatsuche bricht ab', ! array_filter( $GLOBALS['bws_requests'], function ( $r ) { return '2.0/kb_order' === $r[1]; } ) ); }
remove_filter( 'pre_http_request', $fail_search, 20 );

// Nur Adresszeile 2 ausgefüllt -> wird zur Strasse (bexio verlangt street_name).
$o3 = wc_create_order();
$o3->set_address( array( 'first_name' => 'Eva', 'last_name' => 'Nur', 'address_1' => '', 'address_2' => 'Postfach 12', 'postcode' => '3000', 'city' => 'Bern', 'country' => 'CH', 'email' => 'eva@example.ch' ), 'billing' );
$c3 = BWS_Contact_Sync::build_payload( $o3 );
t( 'Adresszusatz ohne Strasse wird zur Strasse', 'Postfach 12' === $c3['street_name'] && ! isset( $c3['address_addition'] ) && ! isset( $c3['house_number'] ), wp_json_encode( $c3 ) );

// --- Lagerimport bexio -> WooCommerce ---
BWS_Settings::save( array( 'stock_mode' => 'from_bexio', 'price_mode' => 'gross' ) );
update_option( 'woocommerce_prices_include_tax', 'yes' );

t( 'Artikel-Payload ohne Bestand, wenn bexio das Lager führt', ! isset( BWS_Product_Sync::build_payload( wc_get_product( $p1->get_id() ) )['stock_nr'] ) );

as_unschedule_all_actions( 'bws_sync_product' );
$rp->setValue( null, array() );
wc_update_product_stock( wc_get_product( $p1->get_id() ), 9 );
t( 'Keine Bestandsübertragung an bexio im Modus "bexio führt"', ! as_has_scheduled_action( 'bws_sync_product', array( $p1->get_id() ), BWS_AS_GROUP ) );

$p2o = wc_get_product( $p2->get_id() ); $p2o->set_stock_status( 'outofstock' ); $p2o->save();
$p4 = new WC_Product_Simple(); $p4->set_props( array( 'name' => 'Ohne Lagerverwaltung', 'sku' => 'UNM-1', 'regular_price' => '3' ) ); $p4->save();
$p5 = new WC_Product_Simple(); $p5->set_props( array( 'name' => 'Nur verknüpft', 'sku' => 'ALT-SKU', 'regular_price' => '3' ) ); $p5->save();
update_post_meta( $p5->get_id(), '_bws_article_id', 777 );
$p5o = wc_get_product( $p5->get_id() ); $p5o->set_stock_status( 'onbackorder' ); $p5o->save();

$GLOBALS['bws_bexio_articles'] = array(
	array( 'id' => $id1, 'intern_code' => 'KAF-1000', 'is_stock' => true, 'stock_nr' => 0, 'stock_available_nr' => 0 ),
	array( 'id' => 55, 'intern_code' => 'EXIST-1', 'is_stock' => true, 'stock_nr' => 5, 'stock_available_nr' => 5 ),
	array( 'id' => $vid, 'intern_code' => 'TS-M', 'is_stock' => true, 'stock_nr' => -2, 'stock_available_nr' => -2 ),
	array( 'id' => 300, 'intern_code' => 'UNM-1', 'is_stock' => true, 'stock_nr' => 0, 'stock_available_nr' => 0 ),
	array( 'id' => 777, 'intern_code' => 'NEUE-NR', 'is_stock' => true, 'stock_nr' => 4, 'stock_available_nr' => 4 ),
	array( 'id' => 301, 'intern_code' => 'NICHT-IM-SHOP', 'is_stock' => true, 'stock_nr' => 3, 'stock_available_nr' => 3 ),
	array( 'id' => 302, 'intern_code' => 'DIENSTLEISTUNG', 'is_stock' => false, 'stock_nr' => 0, 'stock_available_nr' => 0 ),
	array( 'id' => 303, 'intern_code' => 'TS', 'is_stock' => true, 'stock_nr' => 3, 'stock_available_nr' => 3 ),
);

as_unschedule_all_actions( 'bws_sync_product' );
$rp->setValue( null, array() );
$GLOBALS['bws_requests'] = array();
$stats = BWS_Stock_Import::run();
WC_Post_Data::do_deferred_product_sync(); // läuft sonst beim Shutdown des Requests

t( 'Import: keine Schreibzugriffe auf bexio', ! array_filter( $GLOBALS['bws_requests'], function ( $r ) { return 'GET' !== $r[0]; } ) );
t( 'Import: keine Rück-Übertragung eingeplant', ! as_has_scheduled_action( 'bws_sync_product', null, BWS_AS_GROUP ) );

$k = wc_get_product( $p1->get_id() );
t( 'Bestand 0, Lagerverwaltung -> Lieferrückstand', 'onbackorder' === $k->get_stock_status() && 0 === $k->get_stock_quantity() && 'no' !== $k->get_backorders(), $k->get_stock_status() );
t( 'Bestand 5, ohne Lagerverwaltung -> Vorrätig', 'instock' === wc_get_product( $p2->get_id() )->get_stock_status() );
$vv = wc_get_product( $v->get_id() );
t( 'Variante Bestand -2 -> Lieferrückstand', 'onbackorder' === $vv->get_stock_status() && -2 === $vv->get_stock_quantity(), $vv->get_stock_status() . ' ' . $vv->get_stock_quantity() );
t( 'Elternprodukt übernimmt Variantenstatus', 'onbackorder' === wc_get_product( $var_parent->get_id() )->get_stock_status(), wc_get_product( $var_parent->get_id() )->get_stock_status() );
t( 'Bestand 0, ohne Lagerverwaltung -> Lieferrückstand', 'onbackorder' === wc_get_product( $p4->get_id() )->get_stock_status() );
t( 'Zuordnung über verknüpfte Artikel-ID', 'instock' === wc_get_product( $p5->get_id() )->get_stock_status() );
t( 'Statistik', 1 === $stats['not_found'] && 2 === $stats['skipped'] && 2 === $stats['instock'] && 3 === $stats['backorder'] && 0 === $stats['errors'], wp_json_encode( $stats ) );

$stats2 = BWS_Stock_Import::run();
t( 'Zweiter Lauf: alles unverändert', 5 === $stats2['unchanged'] && 0 === $stats2['instock'] + $stats2['backorder'], wp_json_encode( $stats2 ) );

// Bestand wieder > 0 -> Vorrätig.
$GLOBALS['bws_bexio_articles'][0]['stock_nr'] = 12;
BWS_Stock_Import::run();
$k = wc_get_product( $p1->get_id() );
t( 'Bestand 12 -> Vorrätig', 'instock' === $k->get_stock_status() && 12 === $k->get_stock_quantity() );

// Zeitplan.
BWS_Stock_Import::ensure_schedule();
$next = as_next_scheduled_action( BWS_Stock_Import::HOOK, array(), BWS_AS_GROUP );
t( 'Täglicher Import eingeplant', is_int( $next ) && $next > time() && $next <= time() + DAY_IN_SECONDS );
BWS_Settings::save( array( 'stock_mode' => 'to_bexio' ) );
BWS_Stock_Import::ensure_schedule();
t( 'Zeitplan entfernt, wenn WooCommerce führt', false === as_next_scheduled_action( BWS_Stock_Import::HOOK, array(), BWS_AS_GROUP ) );
t( 'Uhrzeit-Validierung', '07:05' === BWS_Stock_Import::normalize_time( '7:05' ) && '03:00' === BWS_Stock_Import::normalize_time( '25:00' ) );

echo $GLOBALS["fail"] ? "\n" . $GLOBALS["fail"] . " FEHLER\n" : "\nAlle Integrationstests bestanden.\n";
