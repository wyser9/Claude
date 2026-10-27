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
	if ( 'GET' === $m && '3.0/taxes' === $path && false !== strpos( $url, 'pre_tax' ) ) return resp( array( array( 'id' => 21, 'code' => 'VM81', 'name' => 'VM81', 'value' => 8.1 ), array( 'id' => 22, 'code' => 'VM26', 'name' => 'VM26', 'value' => 2.6 ) ) );
	if ( 'GET' === $m && '3.0/accounts' === $path ) return resp( array( array( 'id' => 43, 'account_no' => '3200', 'name' => 'Handelserlös' ), array( 'id' => 42, 'account_no' => '4200', 'name' => 'Einkauf Handelsware' ) ) );
	if ( 'GET' === $m && '3.0/taxes' === $path ) return resp( array( array( 'id' => 11, 'value' => 8.1, 'name' => 'UN81' ), array( 'id' => 12, 'value' => 2.6, 'name' => 'UR26' ), array( 'id' => 14, 'value' => 0, 'name' => 'UN00' ) ) );
	if ( 'GET' === $m && '2.0/country' === $path ) return resp( array( array( 'id' => 1, 'name' => 'Schweiz', 'name_short' => 'CH', 'iso3166_alpha2' => 'CH' ), array( 'id' => 2, 'name' => 'Deutschland', 'name_short' => 'DE', 'iso3166_alpha2' => 'DE' ) ) );
	if ( 'GET' === $m && '3.0/currencies' === $path ) return resp( array( array( 'id' => 1, 'name' => 'CHF' ), array( 'id' => 2, 'name' => 'EUR' ) ) );
	if ( 'GET' === $m && '2.0/article' === $path ) {
		parse_str( (string) wp_parse_url( $url, PHP_URL_QUERY ), $qa );
		return resp( array_slice( $GLOBALS['bws_bexio_articles'] ?? array(), (int) ( $qa['offset'] ?? 0 ), isset( $qa['limit'] ) ? (int) $qa['limit'] : null ) );
	}
	if ( 'POST' === $m && '2.0/article/search' === $path ) {
		if ( 'KAPUTT-1' === $body[0]['value'] ) return resp( array( 'message' => 'Serverfehler' ), 400 );
		$known = array( 'EXIST-1' => 55, 'BEXIO-2575' => 2575 );
		return resp( isset( $known[ $body[0]['value'] ] ) ? array( array( 'id' => $known[ $body[0]['value'] ], 'intern_code' => $body[0]['value'] ) ) : array() );
	}
	if ( 'POST' === $m && '2.0/article' === $path ) return resp( array_merge( $body, array( 'id' => ++$GLOBALS['bws_next_id'] ) ) );
	if ( 'POST' === $m && preg_match( '#^2\.0/article/(\d+)$#', $path, $mm ) ) {
		// Wie das echte bexio: Artikelart ist beim Bearbeiten nicht erlaubt.
		if ( isset( $body['article_type_id'] ) ) return resp( array( 'error_code' => 422, 'message' => 'The form could not be saved due to the following errors:', 'errors' => array( 'Widget schema does not include the following field(s): article_type_id' ) ), 422 );
		return resp( array_merge( $body, array( 'id' => (int) $mm[1] ) ) );
	}
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
	if ( 'GET' === $m && preg_match( '#^2\.0/kb_order/(\d+)$#', $path, $mm ) && isset( $GLOBALS['bws_kb'][ (int) $mm[1] ] ) ) return resp( $GLOBALS['bws_kb'][ (int) $mm[1] ] );
	if ( 'POST' === $m && preg_match( '#^2\.0/kb_order/(\d+)/comment$#', $path, $mm ) ) {
		if ( empty( $body['text'] ) || empty( $body['user_id'] ) || empty( $body['user_name'] ) ) return resp( array( 'message' => 'missing' ), 422 );
		return resp( array_merge( $body, array( 'id' => 1 ) ) );
	}
	if ( 'POST' === $m && '2.0/kb_invoice/search' === $path ) {
		return resp( array_values( array_filter( $GLOBALS['bws_invoices'] ?? array(), function ( $i ) use ( $body ) { return (int) $i['contact_id'] === (int) $body[0]['value']; } ) ) );
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
t( 'Gewicht in g (ganze Zahl)', 1000 === $create['weight'], var_export( $create['weight'] ?? null, true ) );
$pw = new WC_Product_Simple(); $pw->set_props( array( 'name' => 'Krumm', 'sku' => 'W-1', 'regular_price' => '1', 'weight' => '0.1545' ) ); $pw->save();
t( 'Gewicht gerundet (154.5 g -> 155)', 155 === BWS_Product_Sync::build_payload( wc_get_product( $pw->get_id() ) )['weight'] );
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

// --- Bestellung mit bestehendem bexio-Artikel (Fall armatum.ch: Fehler 422 bei article_type_id) ---
remove_all_actions( 'woocommerce_update_product' ); // Hintergrund-Sync hier nicht relevant
$pb = new WC_Product_Simple(); $pb->set_props( array( 'name' => 'Schon in bexio', 'sku' => 'BEXIO-2575', 'regular_price' => '40' ) ); $pb->save();
$pk = new WC_Product_Simple(); $pk->set_props( array( 'name' => 'Kaputter Artikel', 'sku' => 'KAPUTT-1', 'regular_price' => '10' ) ); $pk->save();
$o5 = $mk( 'artikel@example.ch' );
foreach ( $o5->get_items() as $iid => $it ) { $o5->remove_item( $iid ); }
$o5->add_product( wc_get_product( $pb->get_id() ), 2 );
$o5->add_product( wc_get_product( $pk->get_id() ), 1 );
$o5->calculate_totals(); $o5->save();
$GLOBALS['bws_requests'] = array();
$r5 = BWS_Order_Sync::sync( wc_get_order( $o5->get_id() ) );
$kb5 = null; $art_writes = array();
foreach ( $GLOBALS['bws_requests'] as $r ) {
	if ( '2.0/kb_order' === $r[1] ) $kb5 = $r[2];
	if ( preg_match( '#^2\.0/article(/\d+)?$#', $r[1] ) ) $art_writes[] = $r[1];
}
t( 'Bestellung trotz Artikelproblem übertragen', 900 === $r5 && $kb5 );
t( 'Bestehender Artikel nur verknüpft (article_id 2575)', 'KbPositionArticle' === $kb5['positions'][0]['type'] && 2575 === $kb5['positions'][0]['article_id'], wp_json_encode( $kb5['positions'][0] ) );
t( 'Bestehender bexio-Artikel wird bei Bestellung nicht überschrieben', ! in_array( '2.0/article/2575', $art_writes, true ), implode( ',', $art_writes ) );
t( 'Verknüpfung am Produkt gespeichert', 2575 === (int) get_post_meta( $pb->get_id(), '_bws_article_id', true ) );
t( 'Kaputter Artikel als freie Position', 'KbPositionCustom' === $kb5['positions'][1]['type'] && ! isset( $kb5['positions'][1]['article_id'] ) );
$n5 = wc_get_order_notes( array( 'order_id' => $o5->get_id() ) );
t( 'Notiz nennt freie Position', false !== strpos( $n5[0]->content, 'Kaputter Artikel' ) && false !== strpos( $n5[0]->content, 'freie Position' ), $n5[0]->content );

// Produktaktualisierung eines verknüpften Artikels: ohne article_type_id.
$GLOBALS['bws_requests'] = array();
BWS_Product_Sync::sync( wc_get_product( $pb->get_id() ) );
$upd = end( $GLOBALS['bws_requests'] );
t( 'Artikel-Update ohne article_type_id', '2.0/article/2575' === $upd[1] && ! isset( $upd[2]['article_type_id'] ), wp_json_encode( $upd ) );

// Nur Adresszeile 2 ausgefüllt -> wird zur Strasse (bexio verlangt street_name).
$o3 = wc_create_order();
$o3->set_address( array( 'first_name' => 'Eva', 'last_name' => 'Nur', 'address_1' => '', 'address_2' => 'Postfach 12', 'postcode' => '3000', 'city' => 'Bern', 'country' => 'CH', 'email' => 'eva@example.ch' ), 'billing' );
$c3 = BWS_Contact_Sync::build_payload( $o3 );
t( 'Adresszusatz ohne Strasse wird zur Strasse', 'Postfach 12' === $c3['street_name'] && ! isset( $c3['address_addition'] ) && ! isset( $c3['house_number'] ), wp_json_encode( $c3 ) );

// --- Artikelgruppen aus Hauptkategorien ---
$mkcat = function ( $name, $parent = 0 ) { $t = wp_insert_term( $name, 'product_cat', array( 'parent' => $parent ) ); return (int) $t['term_id']; };
$c_mun = $mkcat( 'Munition' ); $c_308 = $mkcat( '.308 Winchester', $c_mun );
$c_wl = $mkcat( 'Wiederladen' ); $c_ges = $mkcat( 'Geschosse', $c_wl );
$c_her = $mkcat( 'Hersteller' ); $c_horn = $mkcat( 'Hornady', $c_her ); $c_sale = $mkcat( 'Sale %' );
BWS_Settings::save( array( 'category_groups' => array( $c_mun => 7, $c_wl => 9 ) ) );
$pc1 = new WC_Product_Simple(); $pc1->set_props( array( 'name' => 'Patrone', 'sku' => 'CAT-1', 'regular_price' => '1', 'category_ids' => array( $c_horn, $c_308, $c_sale ) ) ); $pc1->save();
$pc2 = new WC_Product_Simple(); $pc2->set_props( array( 'name' => 'Geschoss', 'sku' => 'CAT-2', 'regular_price' => '1', 'category_ids' => array( $c_ges ) ) ); $pc2->save();
$pc3 = new WC_Product_Simple(); $pc3->set_props( array( 'name' => 'Nur Marke', 'sku' => 'CAT-3', 'regular_price' => '1', 'category_ids' => array( $c_horn, $c_sale ) ) ); $pc3->save();
t( 'Unterkategorie erbt Gruppe der Hauptkategorie (Hersteller/Sale ignoriert)', 7 === BWS_Product_Sync::build_payload( wc_get_product( $pc1->get_id() ) )['article_group_id'] );
t( 'Wiederladen > Geschosse -> Gruppe 9', 9 === BWS_Product_Sync::build_payload( wc_get_product( $pc2->get_id() ) )['article_group_id'] );
t( 'Ohne zugeordnete Kategorie keine Gruppe', ! isset( BWS_Product_Sync::build_payload( wc_get_product( $pc3->get_id() ) )['article_group_id'] ) );
$vp = wc_get_product( $var_parent->get_id() ); $vp->set_category_ids( array( $c_308 ) ); $vp->save();
t( 'Variante übernimmt Kategorie des Elternprodukts', 7 === BWS_Product_Sync::article_group_id( wc_get_product( $v->get_id() ) ) );
BWS_Settings::save( array( 'category_groups' => array( $c_mun => 7, $c_308 => 8 ) ) );
t( 'Genauere Zuordnung gewinnt', 8 === BWS_Product_Sync::article_group_id( wc_get_product( $pc1->get_id() ) ) );
BWS_Settings::save( array( 'category_groups' => array() ) );
t( 'Ohne Zuordnungen kein Feld', ! isset( BWS_Product_Sync::build_payload( wc_get_product( $pc1->get_id() ) )['article_group_id'] ) );

// --- Neue Artikel: Lagerartikel, Aufwandkonto 4200, Vorsteuer VM81 ---
BWS_Lookup::flush();
$pn = new WC_Product_Simple(); $pn->set_props( array( 'name' => 'Streamlight ProTac', 'sku' => 'SL-88061', 'regular_price' => '79.90' ) ); $pn->save();
$GLOBALS['bws_requests'] = array();
BWS_Product_Sync::sync( wc_get_product( $pn->get_id() ) );
$cr = null; foreach ( $GLOBALS['bws_requests'] as $q ) { if ( 'POST' === $q[0] && '2.0/article' === $q[1] ) $cr = $q[2]; }
t( 'Neuer Artikel: Ist ein Produkt mit Lager', true === $cr['is_stock'] && 0 === $cr['stock_nr'] && 0 === $cr['stock_min_nr'], wp_json_encode( $cr ) );
t( 'Neuer Artikel: Lagerort/Lagerplatz aus Einstellungen', 1 === $cr['stock_id'] && 2 === $cr['stock_place_id'] );
t( 'Neuer Artikel: Aufwandkonto 4200 automatisch', 42 === $cr['expense_account_id'] );
t( 'Neuer Artikel: Vorsteuer VM81 automatisch', 21 === $cr['tax_expense_id'] );
$GLOBALS['bws_requests'] = array();
BWS_Product_Sync::sync( wc_get_product( $pn->get_id() ) );
$up = end( $GLOBALS['bws_requests'] );
t( 'Bestehender Artikel: keine Lager-/Konto-Felder beim Update', 0 === strpos( $up[1], '2.0/article/' ) && ! isset( $up[2]['is_stock'] ) && ! isset( $up[2]['expense_account_id'] ) && ! isset( $up[2]['tax_expense_id'] ), wp_json_encode( $up ) );
BWS_Settings::save( array( 'expense_account_id' => '43', 'tax_expense_id' => '22', 'new_article_stock' => 'no' ) );
$pv = new WC_Product_Simple(); $pv->set_props( array( 'name' => 'Ohne Lager', 'sku' => 'NL-1', 'regular_price' => '1' ) ); $pv->save();
$f = BWS_Product_Sync::create_only_fields( wc_get_product( $pv->get_id() ) );
t( 'Einstellungen übersteuern Automatik, Lager abschaltbar', 43 === $f['expense_account_id'] && 22 === $f['tax_expense_id'] && ! isset( $f['is_stock'] ) );
BWS_Settings::save( array( 'expense_account_id' => '', 'tax_expense_id' => '', 'new_article_stock' => 'yes' ) );
$pvv = new WC_Product_Simple(); $pvv->set_props( array( 'name' => 'Gutschein', 'sku' => 'GS-1', 'regular_price' => '50', 'virtual' => true ) ); $pvv->save();
t( 'Virtuelles Produkt (Dienstleistung) ohne Lager', ! isset( BWS_Product_Sync::create_only_fields( wc_get_product( $pvv->get_id() ) )['is_stock'] ) );

// --- Beschreibung als Stichpunkte ---
$pa = new WC_Product_Attribute(); $pa->set_name( 'Hersteller' ); $pa->set_options( array( 'Streamlight' ) ); $pa->set_visible( true );
$pd = new WC_Product_Simple(); $pd->set_props( array( 'name' => 'ProTac', 'sku' => 'DESC-1', 'regular_price' => '79.9', 'short_description' => '<p>350 Lumen, Reichweite bis 160 Meter. Ultrakompakt: nur 10.8 cm lang.</p>' ) ); $pd->set_attributes( array( $pa ) ); $pd->save();
$desc = BWS_Product_Sync::build_payload( wc_get_product( $pd->get_id() ) )['intern_description'];
t( 'bexio-Beschreibung als Stichpunkte', '<ul><li>Hersteller: Streamlight</li><li>350 Lumen, Reichweite bis 160 Meter</li><li>Ultrakompakt: nur 10.8 cm lang</li></ul>' === $desc, $desc );
BWS_Settings::save( array( 'description_bullets' => 'no' ) );
t( 'Stichpunkte abschaltbar (Fliesstext)', false === strpos( BWS_Product_Sync::build_payload( wc_get_product( $pd->get_id() ) )['intern_description'], '<li>' ) );
BWS_Settings::save( array( 'description_bullets' => 'yes' ) );

// --- Nachrüsten bestehender Artikel ---
BWS_Lookup::flush();
$pb1 = new WC_Product_Simple(); $pb1->set_props( array( 'name' => 'Alt ohne Lager', 'sku' => 'BF-1', 'regular_price' => '1' ) ); $pb1->save(); update_post_meta( $pb1->get_id(), '_bws_article_id', 5001 );
$pb2 = new WC_Product_Simple(); $pb2->set_props( array( 'name' => 'Alt mit Lager', 'sku' => 'BF-2', 'regular_price' => '1' ) ); $pb2->save(); update_post_meta( $pb2->get_id(), '_bws_article_id', 5002 );
$pb3 = new WC_Product_Simple(); $pb3->set_props( array( 'name' => 'Komplett', 'sku' => 'BF-3', 'regular_price' => '1' ) ); $pb3->save(); update_post_meta( $pb3->get_id(), '_bws_article_id', 5003 );
$GLOBALS['bws_bexio_articles'] = array(
	array( 'id' => 5001, 'intern_code' => 'BF-1', 'is_stock' => false, 'stock_nr' => 0, 'expense_account_id' => null, 'tax_expense_id' => null ),
	array( 'id' => 5002, 'intern_code' => 'BF-2', 'is_stock' => true, 'stock_nr' => 7, 'expense_account_id' => null, 'tax_expense_id' => 99 ),
	array( 'id' => 5003, 'intern_code' => 'BF-3', 'is_stock' => true, 'stock_nr' => 2, 'expense_account_id' => 42, 'tax_expense_id' => 21 ),
	array( 'id' => 5004, 'intern_code' => '096-77116', 'deliverer_code' => '19442', 'is_stock' => true, 'stock_nr' => 1 ),
);
$GLOBALS['bws_requests'] = array();
// 1200 weitere, nicht verknüpfte Artikel: Lesen über mehrere Seiten à 500.
for ( $i = 0; $i < 1200; $i++ ) { $GLOBALS['bws_bexio_articles'][] = array( 'id' => 90000 + $i, 'intern_code' => 'X-' . $i, 'is_stock' => false ); }
delete_option( BWS_Backfill::OPT_STATUS );
$st = BWS_Backfill::scan();
$scans = 1;
while ( 'scanning' === $st['state'] && $scans < 10 ) { $st = BWS_Backfill::scan(); $scans++; }
$article_gets = count( array_filter( $GLOBALS['bws_requests'], function ( $r ) { return 'GET' === $r[0] && '2.0/article' === $r[1]; } ) );
t( 'Nachrüsten liest seitenweise', 3 === $article_gets && 1204 === $st['articles'] && 3 === $st['linked'], $article_gets . ' ' . wp_json_encode( $st ) );
while ( 'running' === $st['state'] ) { $st = BWS_Backfill::chunk(); }
$GLOBALS['bws_bexio_articles'] = array_slice( $GLOBALS['bws_bexio_articles'], 0, 4 );
$posts = array(); foreach ( $GLOBALS['bws_requests'] as $q ) { if ( 'POST' === $q[0] ) $posts[ $q[1] ] = $q[2]; }
t( 'Nachrüsten: Artikel ohne Lager bekommt Lager, Konto, Vorsteuer', isset( $posts['2.0/article/5001'] ) && true === $posts['2.0/article/5001']['is_stock'] && 42 === $posts['2.0/article/5001']['expense_account_id'] && 21 === $posts['2.0/article/5001']['tax_expense_id'], wp_json_encode( $posts ) );
t( 'Nachrüsten: Lagerartikel – Bestand unangetastet, nur fehlendes Konto', isset( $posts['2.0/article/5002'] ) && ! isset( $posts['2.0/article/5002']['is_stock'] ) && ! isset( $posts['2.0/article/5002']['stock_nr'] ) && ! isset( $posts['2.0/article/5002']['tax_expense_id'] ) && 42 === $posts['2.0/article/5002']['expense_account_id'] );
t( 'Nachrüsten: vollständiger Artikel unverändert', ! isset( $posts['2.0/article/5003'] ) );
t( 'Nachrüsten: Seriennummer-Kopie (kein Shop-Produkt) unverändert', ! isset( $posts['2.0/article/5004'] ) );
t( 'Nachrüsten: Status', 'done' === $st['state'] && 2 === $st['done'] && 0 === $st['errors'] );

// --- Waffen mit Seriennummer-Kopien ---
$c_waf = (int) wp_insert_term( 'Waffen', 'product_cat', array( 'slug' => 'waffen' ) )['term_id'];
$c_pis = (int) wp_insert_term( 'Pistolen', 'product_cat', array( 'parent' => $c_waf ) )['term_id'];
$c_zub = (int) wp_insert_term( 'Zubehör X', 'product_cat' )['term_id'];
$c_wz  = (int) wp_insert_term( 'Waffenzubehör', 'product_cat', array( 'slug' => 'waffenzubehoer' ) )['term_id'];
$c_las = (int) wp_insert_term( 'Lampen / Laser', 'product_cat', array( 'parent' => $c_wz ) )['term_id'];
$laser = new WC_Product_Simple(); $laser->set_props( array( 'name' => 'Laser Klasse 4', 'sku' => 'LAS-1', 'regular_price' => '300', 'category_ids' => array( $c_las ) ) ); $laser->save();
$lo = wc_get_product( $laser->get_id() ); $lo->set_stock_status( 'onbackorder' ); $lo->save();
$gun = new WC_Product_Simple(); $gun->set_props( array( 'name' => 'Ruger RXM 17 Schuss', 'sku' => '19442', 'regular_price' => '700', 'category_ids' => array( $c_pis ) ) ); $gun->save();
$gun2 = new WC_Product_Simple(); $gun2->set_props( array( 'name' => 'Ruger ohne Kopie', 'sku' => '19443', 'regular_price' => '700', 'category_ids' => array( $c_pis ) ) ); $gun2->save();
$acc = new WC_Product_Simple(); $acc->set_props( array( 'name' => 'Zubehör mit Lieferantencode', 'sku' => 'ZB-1', 'regular_price' => '10', 'category_ids' => array( $c_zub ) ) ); $acc->save();
foreach ( array( $gun, $gun2, $acc ) as $pp ) { $o = wc_get_product( $pp->get_id() ); $o->set_stock_status( 'onbackorder' ); $o->save(); }
$GLOBALS['bws_bexio_articles'] = array(
	array( 'id' => 6001, 'intern_code' => '19442', 'is_stock' => false, 'stock_nr' => 0, 'stock_available_nr' => 0 ),
	array( 'id' => 6002, 'intern_code' => '096-77116', 'deliverer_code' => '19442', 'is_stock' => true, 'stock_nr' => 1, 'stock_available_nr' => 1 ),
	array( 'id' => 6003, 'intern_code' => '096-77117', 'deliverer_code' => '19442', 'is_stock' => true, 'stock_nr' => 1, 'stock_available_nr' => 1 ),
	array( 'id' => 6004, 'intern_code' => '096-80000', 'deliverer_code' => '19442', 'is_stock' => true, 'stock_nr' => 0, 'stock_available_nr' => 0 ),
	array( 'id' => 6005, 'intern_code' => '19443', 'is_stock' => true, 'stock_nr' => 0, 'stock_available_nr' => 0 ),
	array( 'id' => 6006, 'intern_code' => 'ZB-1', 'is_stock' => true, 'stock_nr' => 0, 'stock_available_nr' => 0 ),
	array( 'id' => 6007, 'intern_code' => 'LIEF-ABC', 'deliverer_code' => 'ZB-1', 'is_stock' => true, 'stock_nr' => 5, 'stock_available_nr' => 5 ),
	array( 'id' => 6008, 'intern_code' => 'LAS-1', 'is_stock' => false, 'stock_nr' => 0, 'stock_available_nr' => 0 ),
	array( 'id' => 6009, 'intern_code' => 'L-SN-001', 'deliverer_code' => 'LAS-1', 'is_stock' => true, 'stock_nr' => 1, 'stock_available_nr' => 1 ),
);
BWS_Settings::save( array( 'stock_mode' => 'from_bexio' ) );
$GLOBALS['bws_requests'] = array();
$ss = BWS_Stock_Import::run();
t( 'Waffe: Hauptartikel an Lager über 2 Seriennummern', 'instock' === wc_get_product( $gun->get_id() )->get_stock_status(), wp_json_encode( $ss ) );
t( 'Waffe ohne Kopie bleibt Lieferrückstand', 'onbackorder' === wc_get_product( $gun2->get_id() )->get_stock_status() );
t( 'Nicht-Waffe: Lieferantencode zählt nicht', 'onbackorder' === wc_get_product( $acc->get_id() )->get_stock_status() );
t( 'Laser (Waffenzubehör > Lampen / Laser) über Seriennummer an Lager', 'instock' === wc_get_product( $laser->get_id() )->get_stock_status() );
t( 'Automatische Kategorien: Waffen, Waffenzubehör, Laser', ! array_diff( array( $c_waf, $c_wz, $c_las ), BWS_Stock_Import::serial_category_ids() ) && ! in_array( $c_zub, BWS_Stock_Import::serial_category_ids(), true ) );
t( 'Statistik Seriennummern', 2 === $ss['serial'], wp_json_encode( $ss ) );
t( 'Lagerimport schreibt nichts in bexio', ! array_filter( $GLOBALS['bws_requests'], function ( $q ) { return 'GET' !== $q[0]; } ) );
$GLOBALS['bws_bexio_articles'][1]['stock_nr'] = 0; $GLOBALS['bws_bexio_articles'][2]['stock_nr'] = 0;
BWS_Stock_Import::run();
t( 'Waffe verkauft (Kopien 0) -> Lieferrückstand', 'onbackorder' === wc_get_product( $gun->get_id() )->get_stock_status() );
BWS_Settings::save( array( 'stock_mode' => 'to_bexio' ) );

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

// Etappenweise (wie im Hintergrund): gleiches Ergebnis wie in einem Durchgang.
BWS_Stock_Import::start();
$steps = 0;
while ( BWS_Stock_Import::step( 0 ) && $steps < 1000 ) { $steps++; }
$staged = get_option( BWS_Stock_Import::OPTION_LAST );
t( 'Import in Etappen läuft durch', $steps > 3 && false === get_option( BWS_Stock_Import::OPTION_STATE ) && $staged['articles'] === $stats['articles'] && 5 === $staged['unchanged'] && 0 === $staged['errors'], $steps . ' ' . wp_json_encode( $staged ) );
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

// --- Bestellstatus aus bexio ---
BWS_Settings::save( array( 'status_from_bexio' => 'yes', 'sync_orders' => 'yes' ) );
foreach ( wc_get_orders( array( 'limit' => -1, 'status' => BWS_Status_Import::OPEN_STATUSES ) ) as $prev ) { $prev->delete_meta_data( BWS_Order_Sync::META_ORDER_ID ); $prev->save(); }
$ship_product = new WC_Product_Simple(); $ship_product->set_name( 'Versandartikel' ); $ship_product->set_regular_price( 120 ); $ship_product->save();
$mk = function ( $status, $bexio_id ) use ( $ship_product ) {
	$o = wc_create_order(); $o->add_product( $ship_product, 1 ); $o->set_payment_method( 'bacs' ); $o->set_total( 120 ); $o->set_status( $status );
	$o->update_meta_data( BWS_Order_Sync::META_ORDER_ID, $bexio_id ); $o->save();
	return $o;
};
$oa = $mk( 'on-hold', 801 );   // Rechnung bezahlt, Auftrag offen -> In Bearbeitung.
$ob = $mk( 'on-hold', 802 );   // Rechnung bezahlt, Auftrag erledigt -> Abgeschlossen.
$oc = $mk( 'on-hold', 803 );   // Rechnung offen -> unverändert.
$od = $mk( 'processing', 804 ); // Ohne passenden Titel, gleiches Total -> Abgeschlossen.
$oe = $mk( 'on-hold', 805 );   // Keine Rechnung -> unverändert.
$of = $mk( 'on-hold', 806 );   // Zwei Rechnungen, eine offen -> unverändert.
$og = $mk( 'cancelled', 807 ); // Storniert -> wird nicht angefasst.
$GLOBALS['bws_kb'] = array();
foreach ( array( 801 => array( $oa, 5, 61 ), 802 => array( $ob, 6, 62 ), 803 => array( $oc, 6, 63 ), 804 => array( $od, 6, 64 ), 805 => array( $oe, 6, 65 ), 806 => array( $of, 6, 66 ), 807 => array( $og, 6, 67 ) ) as $kid => $x ) {
	$GLOBALS['bws_kb'][ $kid ] = array( 'id' => $kid, 'document_nr' => 'AU-' . $kid, 'title' => 'WooCommerce Bestellung #' . $x[0]->get_order_number(), 'kb_item_status_id' => $x[1], 'contact_id' => $x[2], 'total' => '120.00', 'is_valid_from' => '2026-10-01' );
}
$GLOBALS['bws_invoices'] = array(
	array( 'id' => 1, 'document_nr' => 'RE-1', 'contact_id' => 61, 'title' => 'WooCommerce Bestellung #' . $oa->get_order_number(), 'kb_item_status_id' => 9, 'total' => '120.00', 'is_valid_from' => '2026-10-02' ),
	array( 'id' => 2, 'document_nr' => 'RE-2', 'contact_id' => 62, 'title' => 'WooCommerce Bestellung #' . $ob->get_order_number(), 'kb_item_status_id' => 9, 'total' => '120.00', 'is_valid_from' => '2026-10-02' ),
	array( 'id' => 9, 'document_nr' => 'RE-9', 'contact_id' => 62, 'title' => 'Andere Bestellung #1', 'kb_item_status_id' => 8, 'total' => '50.00', 'is_valid_from' => '2026-10-02' ),
	array( 'id' => 3, 'document_nr' => 'RE-3', 'contact_id' => 63, 'title' => 'WooCommerce Bestellung #' . $oc->get_order_number(), 'kb_item_status_id' => 8, 'total' => '120.00', 'is_valid_from' => '2026-10-02' ),
	array( 'id' => 4, 'document_nr' => 'RE-4', 'contact_id' => 64, 'title' => 'Rechnung', 'kb_item_status_id' => 9, 'total' => '120.00', 'is_valid_from' => '2026-10-03' ),
	array( 'id' => 5, 'document_nr' => 'RE-5', 'contact_id' => 64, 'title' => 'Alte Rechnung', 'kb_item_status_id' => 8, 'total' => '120.00', 'is_valid_from' => '2026-09-01' ),
	array( 'id' => 6, 'document_nr' => 'RE-6', 'contact_id' => 66, 'title' => 'WooCommerce Bestellung #' . $of->get_order_number() . ' Teil 1', 'kb_item_status_id' => 9, 'total' => '60.00', 'is_valid_from' => '2026-10-02' ),
	array( 'id' => 7, 'document_nr' => 'RE-7', 'contact_id' => 66, 'title' => 'WooCommerce Bestellung #' . $of->get_order_number() . ' Teil 2', 'kb_item_status_id' => 16, 'total' => '60.00', 'is_valid_from' => '2026-10-02' ),
	array( 'id' => 8, 'document_nr' => 'RE-8', 'contact_id' => 66, 'title' => 'WooCommerce Bestellung #' . $of->get_order_number(), 'kb_item_status_id' => 19, 'total' => '120.00', 'is_valid_from' => '2026-10-01' ),
	array( 'id' => 10, 'document_nr' => 'RE-10', 'contact_id' => 67, 'title' => 'WooCommerce Bestellung #' . $og->get_order_number(), 'kb_item_status_id' => 9, 'total' => '120.00', 'is_valid_from' => '2026-10-02' ),
);
$GLOBALS['bws_requests'] = array();
$ss = BWS_Status_Import::run();
$st = function ( $o ) { return wc_get_order( $o->get_id() )->get_status(); };
t( 'Rechnung bezahlt -> In Bearbeitung', 'processing' === $st( $oa ) && wc_get_order( $oa->get_id() )->get_date_paid(), $st( $oa ) );
t( 'Bezahlt + Auftrag erledigt -> Abgeschlossen', 'completed' === $st( $ob ), $st( $ob ) );
t( 'Rechnung offen -> unverändert', 'on-hold' === $st( $oc ), $st( $oc ) );
t( 'Zuordnung über Total (Rechnung nach Auftragsdatum)', 'completed' === $st( $od ), $st( $od ) );
t( 'Keine Rechnung -> unverändert', 'on-hold' === $st( $oe ), $st( $oe ) );
t( 'Teilrechnung offen, stornierte ignoriert -> unverändert', 'on-hold' === $st( $of ), $st( $of ) );
t( 'Stornierte Bestellung nicht angefasst', 'cancelled' === $st( $og ) );
$n = wc_get_order_notes( array( 'order_id' => $ob->get_id() ) );
t( 'Bestellnotiz nennt Rechnung und Auftrag', false !== strpos( wp_json_encode( wp_list_pluck( $n, 'content' ) ), 'RE-2' ) && false !== strpos( wp_json_encode( wp_list_pluck( $n, 'content' ) ), 'AU-802' ) );
t( 'Statistik Statusabgleich', 6 === $ss['checked'] && 2 === $ss['paid'] && 2 === $ss['completed'] && 0 === $ss['errors'], wp_json_encode( $ss ) );
t( 'In bexio nichts verändert', ! array_filter( $GLOBALS['bws_requests'], function ( $r ) { return 'POST' === $r[0] && 'search' !== substr( $r[1], -6 ); } ) );
// Zweiter Lauf: Auftrag 801 jetzt erledigt -> Abgeschlossen; Abgeschlossene werden nicht mehr abgefragt.
$GLOBALS['bws_kb'][801]['kb_item_status_id'] = 6;
$GLOBALS['bws_requests'] = array();
$ss = BWS_Status_Import::run();
t( 'Später erledigt -> Abgeschlossen', 'completed' === $st( $oa ) && 0 === $ss['paid'] && 1 === $ss['completed'], wp_json_encode( $ss ) );
t( 'Abgeschlossene nicht mehr geprüft', 4 === $ss['checked'], wp_json_encode( $ss ) );
BWS_Status_Import::ensure_schedule();
t( 'Stündlicher Abgleich eingeplant', is_int( as_next_scheduled_action( BWS_Status_Import::HOOK, array(), BWS_AS_GROUP ) ) );
BWS_Settings::save( array( 'status_from_bexio' => 'no' ) );
BWS_Status_Import::ensure_schedule();
t( 'Abgleich abschaltbar', false === as_next_scheduled_action( BWS_Status_Import::HOOK, array(), BWS_AS_GROUP ) );

// --- Sendungsnummer, Zahlungshinweis, Storno ---
$GLOBALS['bws_mails'] = array();
add_filter( 'pre_wp_mail', function ( $r, $atts ) { $GLOBALS['bws_mails'][] = $atts; return true; }, 10, 2 );
$comments = function () { return array_values( array_filter( $GLOBALS['bws_requests'], function ( $r ) { return 'POST' === $r[0] && '/comment' === substr( $r[1], -8 ); } ) ); };
BWS_Settings::save( array( 'notify_email' => 'shop@armatum.ch', 'payment_reminder_days' => 10 ) );
$ot = $mk( 'processing', 811 );
$GLOBALS['bws_requests'] = array();
do_action( 'apl_label_created', $ot, '99.60.123456.12345678', true );
t( 'Testlabel: kein Kommentar', ! $comments() && ! as_has_scheduled_action( BWS_Order_Events::HOOK_COMMENT, array( $ot->get_id(), 'tracking', '99.60.123456.12345678' ), BWS_AS_GROUP ) );
do_action( 'apl_label_created', $ot, '99.60.123456.12345678', false );
t( 'Label: Kommentar-Job eingeplant', as_has_scheduled_action( BWS_Order_Events::HOOK_COMMENT, array( $ot->get_id(), 'tracking', '99.60.123456.12345678' ), BWS_AS_GROUP ) );
BWS_Order_Events::run_comment_job( $ot->get_id(), 'tracking', '99.60.123456.12345678' );
$c = $comments();
t( 'Sendungsnummer als interner Kommentar im Auftrag', 1 === count( $c ) && '2.0/kb_order/811/comment' === $c[0][1] && false !== strpos( $c[0][2]['text'], '99.60.123456.12345678' ) && false !== strpos( $c[0][2]['text'], 'swisspost-tracking' ) && false === $c[0][2]['is_public'] && 'Max Muster' === $c[0][2]['user_name'], wp_json_encode( $c ) );
BWS_Order_Events::run_comment_job( $ot->get_id(), 'tracking', '99.60.123456.12345678' );
t( 'Gleiche Sendungsnummer nicht doppelt', 1 === count( $comments() ) );
$unlinked = wc_create_order(); $unlinked->save();
do_action( 'apl_label_created', $unlinked, '99.1', false );
t( 'Nicht übertragene Bestellung: nichts', ! as_has_scheduled_action( BWS_Order_Events::HOOK_COMMENT, array( $unlinked->get_id(), 'tracking', '99.1' ), BWS_AS_GROUP ) );

// Zahlungshinweis.
$old1 = $mk( 'on-hold', 812 ); $old1->set_date_created( time() - 12 * DAY_IN_SECONDS ); $old1->save();
$old2 = $mk( 'on-hold', 813 ); $old2->set_payment_method( 'twint_regular' ); $old2->set_date_created( time() - 12 * DAY_IN_SECONDS ); $old2->save();
$new1 = $mk( 'on-hold', 814 ); $new1->set_date_created( time() - 3 * DAY_IN_SECONDS ); $new1->save();
$GLOBALS['bws_mails'] = array();
$cnt = BWS_Order_Events::run_reminder();
$ids = wp_list_pluck( BWS_Order_Events::overdue_orders(), 'id' );
$mail = $GLOBALS['bws_mails'][0] ?? array();
t( 'Hinweis-Mail an Shop mit überfälliger Vorkasse', 1 === count( $GLOBALS['bws_mails'] ) && 'shop@armatum.ch' === $mail['to'] && false !== strpos( $mail['message'], '#' . $old1->get_order_number() . ' ' ), wp_json_encode( $mail ) );
t( 'TWINT und neue Bestellung nicht gemeldet', false === strpos( $mail['message'] ?? '', '#' . $old2->get_order_number() . ' ' ) && false === strpos( $mail['message'] ?? '', '#' . $new1->get_order_number() . ' ' ) );
t( 'Notiz in gemeldeter Bestellung', false !== strpos( wp_json_encode( wp_list_pluck( wc_get_order_notes( array( 'order_id' => $old1->get_id() ) ), 'content' ) ), 'unbezahlt' ) );
$GLOBALS['bws_mails'] = array();
t( 'Zweiter Lauf meldet nichts erneut', 0 === BWS_Order_Events::run_reminder() && ! $GLOBALS['bws_mails'] );
BWS_Order_Events::ensure_schedule();
t( 'Täglicher Hinweis eingeplant', is_int( as_next_scheduled_action( BWS_Order_Events::HOOK_REMINDER, array(), BWS_AS_GROUP ) ) );
BWS_Settings::save( array( 'payment_reminder_days' => 0 ) );
BWS_Order_Events::ensure_schedule();
t( 'Hinweis abschaltbar', false === as_next_scheduled_action( BWS_Order_Events::HOOK_REMINDER, array(), BWS_AS_GROUP ) );

// Storno.
$GLOBALS['bws_requests'] = array(); $GLOBALS['bws_mails'] = array();
$ox = $mk( 'processing', 815 );
$ox->update_status( 'cancelled' );
t( 'Storno: Job eingeplant', as_has_scheduled_action( BWS_Order_Events::HOOK_COMMENT, array( $ox->get_id(), 'cancelled', '' ), BWS_AS_GROUP ) );
BWS_Order_Events::run_comment_job( $ox->get_id(), 'cancelled', '' );
$c = $comments();
$bws_mails = function () { return array_values( array_filter( $GLOBALS['bws_mails'], function ( $m ) { return false !== strpos( $m['subject'], 'bexio-Auftrag' ); } ) ); };
t( 'Storno-Kommentar im bexio-Auftrag', 1 === count( $c ) && '2.0/kb_order/815/comment' === $c[0][1] && false !== strpos( $c[0][2]['text'], 'storniert' ), wp_json_encode( $c ) );
t( 'Storno-Mail an Shop', 1 === count( $bws_mails() ) && 'shop@armatum.ch' === $bws_mails()[0]['to'] && false !== strpos( $bws_mails()[0]['message'], 'Auftrag 815 ' ) && false !== strpos( $bws_mails()[0]['message'], 'CHF120.00' ), wp_json_encode( $GLOBALS['bws_mails'] ) );
t( 'Storno: sonst nichts in bexio geändert', 1 === count( array_filter( $GLOBALS['bws_requests'], function ( $r ) { return 'GET' !== $r[0]; } ) ), wp_json_encode( wp_list_pluck( $GLOBALS['bws_requests'], 1 ) ) );
BWS_Order_Events::run_comment_job( $ox->get_id(), 'cancelled', '' );
t( 'Storno nur einmal gemeldet', 1 === count( $comments() ) && 1 === count( $bws_mails() ) );

echo $GLOBALS["fail"] ? "\n" . $GLOBALS["fail"] . " FEHLER\n" : "\nAlle Integrationstests bestanden.\n";
