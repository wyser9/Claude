<?php
/**
 * Integrationstest mit simuliertem bexio (nur in einer Test-Installation!):
 *   wp eval-file wp-content/plugins/armatum-kundendokumente/tests/integration.php
 */
function akd_test() {
	$fail = 0;
	$t = function ( $l, $c, $x = '' ) use ( &$fail ) { if ( ! $c ) { $fail++; echo "FAIL $l $x\n"; } else { echo "ok   $l\n"; } };
	$r = function ( $code, $data ) { return array( 'headers' => array(), 'body' => wp_json_encode( $data ), 'response' => array( 'code' => $code, 'message' => '' ), 'cookies' => array(), 'filename' => null ); };
	$today = gmdate( 'd.m.Y' );
	$files = array();
	// 150 Fremddateien (zweite Seite testen), dazu Kontakt 77 und 88.
	for ( $i = 1; $i <= 150; $i++ ) { $files[] = array( 'id' => $i, 'name' => 'Rechnung ' . $i, 'extension' => 'pdf', 'created_at' => '2026-01-01T10:00:00+00:00' ); }
	$files[] = array( 'id' => 201, 'name' => 'Ausweis bis 31.05.2031', 'extension' => 'pdf', 'created_at' => '2025-02-01T10:00:00+00:00' );
	$files[] = array( 'id' => 202, 'name' => 'WES ' . gmdate( 'd.m.Y', strtotime( '-1 year' ) ) . '.pdf', 'extension' => 'pdf', 'created_at' => '2025-02-01T10:00:00+00:00' );
	$files[] = array( 'id' => 203, 'name' => 'Scan_0001', 'extension' => 'jpg', 'created_at' => '2025-02-01T10:00:00+00:00' );
	$files[] = array( 'id' => 301, 'name' => 'Strafregisterauszug', 'extension' => 'pdf', 'created_at' => gmdate( 'c', strtotime( '-5 months' ) ) );
	$GLOBALS['akd_files'] = $files;
	$GLOBALS['akd_calls'] = array(); $GLOBALS['akd_comments'] = array();
	add_filter( 'pre_http_request', function ( $pre, $args, $url ) use ( $r ) {
		if ( false === strpos( $url, 'api.bexio.com' ) ) return $pre;
		$GLOBALS['akd_calls'][] = $args['method'] . ' ' . $url;
		if ( 'POST' === $args['method'] && preg_match( '#kb_order/(\d+)/comment#', $url ) ) { $GLOBALS['akd_comments'][] = array( $url, json_decode( $args['body'], true ) ); return $r( 201, array( 'id' => 1 ) ); }
		if ( false !== strpos( $url, '3.0/users/me' ) ) return $r( 200, array( 'id' => 1, 'firstname' => 'Pascal', 'lastname' => 'W' ) );
		if ( 'GET' !== $args['method'] ) return $r( 405, array() );
		if ( preg_match( '#3\.0/files/(\d+)/usage#', $url, $m ) ) {
			$id = (int) $m[1];
			if ( $id >= 201 && $id <= 203 ) return $r( 200, array( 'id' => 77, 'ref_class' => 'Contact', 'title' => 'Hans Muster' ) );
			if ( 301 === $id ) return $r( 200, array( array( 'id' => 88, 'ref_class' => 'Contact', 'title' => 'Eva' ) ) );
			return $r( 200, array( 'id' => 5000 + $id, 'ref_class' => 'KbInvoice', 'title' => 'RE' ) );
		}
		if ( false !== strpos( $url, '3.0/files' ) ) {
			parse_str( (string) wp_parse_url( $url, PHP_URL_QUERY ), $q );
			return $r( 200, array_slice( $GLOBALS['akd_files'], (int) $q['offset'], (int) $q['limit'] ) );
		}
		return $r( 404, array() );
	}, 10, 3 );
	$mails = array();
	add_filter( 'pre_wp_mail', function ( $null, $atts ) use ( &$mails ) { $mails[] = $atts; return true; }, 10, 2 );

	update_option( 'bws_settings', array( 'api_token' => 'TEST' ) );
	delete_option( AKD_Index::OPTION ); delete_option( AKD_Index::OPTION_RUN ); delete_option( AKD_Index::OPTION_RAW ); delete_transient( 'akd_index_lock' );

	// Produkte: Pistole mit Erwerbsbedingung, Holster ohne.
	if ( ! taxonomy_exists( 'pa_erwerbsbedingungen' ) ) register_taxonomy( 'pa_erwerbsbedingungen', 'product' );
	$term = wp_insert_term( 'Ausweiskopie', 'pa_erwerbsbedingungen' );
	$gun  = new WC_Product_Simple(); $gun->set_props( array( 'name' => 'Pistole', 'regular_price' => '900' ) ); $gun->save();
	wp_set_object_terms( $gun->get_id(), array( (int) $term['term_id'] ), 'pa_erwerbsbedingungen' );
	$hol  = new WC_Product_Simple(); $hol->set_props( array( 'name' => 'Holster', 'regular_price' => '50' ) ); $hol->save();

	$mk = function ( $products, $contact ) {
		$o = wc_create_order();
		foreach ( $products as $p ) $o->add_product( wc_get_product( $p ), 1 );
		$o->set_billing_first_name( 'Hans' ); $o->set_billing_email( 'hans@example.com' );
		if ( $contact ) { $o->update_meta_data( '_bws_contact_id', $contact ); $o->update_meta_data( '_bws_order_id', 900 + $contact ); $o->update_meta_data( '_bws_order_nr', 'AU2026' . $contact ); }
		$o->calculate_totals(); $o->save();
		return wc_get_order( $o->get_id() );
	};

	// Ohne Erwerbsbedingung: keine Prüfung.
	$o0 = $mk( array( $hol->get_id() ), 77 );
	$t( 'Holster: keine Prüfung', null === AKD_Check::run( $o0 ) && ! $GLOBALS['akd_calls'] );

	// Kunde 77: alles vorhanden.
	$o1  = $mk( array( $gun->get_id(), $hol->get_id() ), 77 );
	$res = AKD_Check::run( $o1 );
	$run = get_option( AKD_Index::OPTION_RUN );
	$t( 'Dateiliste in 2 Seiten gelesen', 154 === $run['files'] && 2 === count( preg_grep( '#3\.0/files\?#', $GLOBALS['akd_calls'] ) ), print_r( $run, true ) );
	$t( 'beim ersten Lauf max. 40 Zuordnungen', 40 === $run['usage'] && 114 === $run['open'] );
	$t( 'neueste Dateien zuerst -> Kontaktdateien schon drin', 3 === $res['files'], print_r( $res, true ) );
	$t( 'Kunde 77 vollständig', 'ok' === $res['status'] );
	$t( 'Hinweis Verzeichnis im Aufbau', false !== strpos( $res['note'] ?? '', 'Aufbau' ) );
	$t( 'unbekannte Datei gemeldet', array( 'Scan_0001.jpg' ) === $res['unknown'] );
	$t( 'betroffener Artikel', array( 'Pistole' ) === $res['items'] );
	$o1 = wc_get_order( $o1->get_id() );
	$notes = wc_get_order_notes( array( 'order_id' => $o1->get_id() ) );
	$t( 'Notiz vollständig', $notes && 0 === strpos( $notes[1]->content, 'Kundendokumente vollständig' ) && ! $notes[1]->customer_note );
	$t( 'sonst nur gelesen', ! preg_grep( '#^(PUT|DELETE)#', $GLOBALS['akd_calls'] ) && 1 === count( preg_grep( '#^POST#', $GLOBALS['akd_calls'] ) ) );
	$c = $GLOBALS['akd_comments'][0] ?? null;
	$t( 'Kommentar im bexio-Auftrag 977', $c && false !== strpos( $c[0], 'kb_order/977/comment' ) && false === $c[1]['is_public'] && false !== strpos( $c[1]['text'], 'Kundendokumente vorhanden' ) && false !== strpos( $c[1]['text'], 'gültig bis 31.05.2031' ) && false !== strpos( $c[1]['text'], 'Waffenerwerbsschein vom' ), print_r( $c, true ) );
	$t( 'Notiz zum Kommentar', false !== strpos( wc_get_order_notes( array( 'order_id' => $o1->get_id() ) )[0]->content, 'AU202677' ) );
	$sample = get_option( AKD_Index::OPTION_RAW );
	$t( 'Beispielantwort ohne Namen', is_array( $sample ) && false === strpos( wp_json_encode( $sample ), 'Muster' ) && false === strpos( wp_json_encode( $sample ), 'RE' ) );

	// Erneut prüfen: keine doppelte Notiz.
	$notes = wc_get_order_notes( array( 'order_id' => $o1->get_id() ) );
	AKD_Check::run( wc_get_order( $o1->get_id() ) );
	$t( 'Kommentar nur einmal', 1 === count( $GLOBALS['akd_comments'] ) );
	$t( 'gleicher Status -> keine neue Notiz', count( wc_get_order_notes( array( 'order_id' => $o1->get_id() ) ) ) === count( $notes ) );

	// Verzeichnis vervollständigen.
	$full = AKD_Index::refresh();
	$t( 'Rest nachgeholt', 114 === $full['usage'] && 0 === $full['open'] && AKD_Index::complete() );
	$t( 'Rechnungen keinem Kontakt zugeordnet', ! AKD_Index::files_for( 5001 ) && 1 === count( AKD_Index::files_for( 88 ) ) );
	$n = count( $GLOBALS['akd_calls'] );
	AKD_Index::refresh();
	$t( 'zweiter Lauf: keine Zuordnungen mehr abfragen', 2 === count( $GLOBALS['akd_calls'] ) - $n );

	// Kunde 88: Strafregister 5 Monate (Upload), kein Ausweis -> fehlt.
	$o2  = $mk( array( $gun->get_id() ), 88 );
	$res = AKD_Check::run( $o2 );
	$t( 'fehlt: kein bexio-Kommentar', 1 === count( $GLOBALS['akd_comments'] ) );
	$t( 'Kunde 88 fehlt', 'fehlt' === $res['status'] && 2 === count( $res['missing'] ), print_r( $res, true ) );
	$t( 'Strafregister zu alt (Upload-Datum)', 'fehlt' === $res['docs']['strafregister']['status'] && false !== strpos( $res['docs']['strafregister']['note'], 'Upload-Datum' ) );
	$t( 'kein Hinweis mehr auf Aufbau', empty( $res['note'] ) );

	// Erinnerung.
	$text = AKD_Check::reminder_text( $o2, $res );
	$t( 'Mailtext mit Du und beiden Punkten', false !== strpos( $text, 'Hallo Hans' ) && false !== strpos( $text, 'Ausweises' ) && false !== strpos( $text, 'Strafregisterauszug (nicht älter als 3 Monate)' ) && false !== strpos( $text, 'deine Bestellung' ) );
	$t( 'kein ß', false === strpos( $text, 'ß' ) );
	$ok = AKD_Check::send_reminder( $o2, $text );
	$t( 'Mail an Kunden gesendet', $ok && 1 === count( $mails ) && 'hans@example.com' === $mails[0]['to'] && false !== strpos( $mails[0]['subject'], $o2->get_order_number() ) );
	$o2 = wc_get_order( $o2->get_id() );
	$t( 'Versand vermerkt', $o2->get_meta( AKD_Check::META_REMINDED ) > 0 );

	// Ohne Kontakt -> warten, keine Notiz.
	$o3 = $mk( array( $gun->get_id() ), 0 );
	$before = count( wc_get_order_notes( array( 'order_id' => $o3->get_id() ) ) );
	$res = AKD_Check::run( $o3 );
	$t( 'ohne Kontakt: warten ohne Notiz', 'warten' === $res['status'] && count( wc_get_order_notes( array( 'order_id' => $o3->get_id() ) ) ) === $before );

	// Kontakt über Kundenkonto.
	$uid = wp_create_user( 'eva', 'pw', 'eva@example.com' );
	update_user_meta( $uid, '_bws_contact_id', 88 );
	$o4 = $mk( array( $gun->get_id() ), 0 ); $o4->set_customer_id( $uid ); $o4->save();
	$t( 'Ausländerausweis im Mailtext', false !== strpos( $text, 'Ausländerausweis' ) );
	$t( 'Kontakt aus Kundenkonto', 88 === AKD_Check::contact_id( wc_get_order( $o4->get_id() ) ) );

	// Hook nach bexio-Übertragung plant Prüfung ein.
	do_action( 'bws_order_synced', $o3, 123, array() );
	$t( 'Prüfung nach bexio-Übertragung eingeplant', (bool) wp_next_scheduled( AKD_Check::HOOK, array( $o3->get_id() ) ) );

	// Kasten in der Bestellung.
	wp_set_current_user( 1 );
	ob_start(); AKD_Admin::render_box( $o2 ); $html = ob_get_clean();
	$t( 'Kasten zeigt Status und Senden-Knopf', false !== strpos( $html, '✘ fehlt' ) && false !== strpos( $html, 'Erinnerung senden' ) && false !== strpos( $html, 'form="akd-remind-form"' ) );
	ob_start(); AKD_Admin::render_box( $o1 ); $html = ob_get_clean();
	$t( 'vollständig: kein Senden-Knopf', false !== strpos( $html, 'vollständig' ) && false === strpos( $html, 'Erinnerung senden' ) );
	ob_start(); AKD_Admin::render_box( $o0 ); $html = ob_get_clean();
	$t( 'Holster: keine Prüfung nötig', false !== strpos( $html, 'keine Prüfung nötig' ) );
	$_GET['contact'] = 77;
	ob_start(); AKD_Admin::page(); $html = ob_get_clean();
	$t( 'Einstellungsseite listet Kontaktdateien', false !== strpos( $html, 'Ausweis bis 31.05.2031.pdf' ) && false !== strpos( $html, '154 Dateien' ) );

	echo $fail ? "\n$fail FEHLER\n" : "\nAlle Tests ok\n";
}
akd_test();
