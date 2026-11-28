<?php
defined( 'ABSPATH' ) || exit;

/**
 * Post-Label direkt aus einem bexio-Lieferschein drucken (Test, ab 1.7.0).
 *
 * Das Userscript «bexio Postlabel» baut im bexio-Lieferschein einen Knopf ein. Der Knopf öffnet
 *   /wp-admin/admin-post.php?action=apl_bexio_label&key=…&delivery=<bexio-ID>&hints=<Text>
 * in einem neuen Tab. Der Shop sucht die passende Bestellung, erstellt das Label (falls noch keines
 * da ist) und zeigt es mit Druckdialog an.
 *
 * Schutz: Anmeldung im Shop (Recht edit_shop_orders) UND geheimer Schlüssel aus den Einstellungen,
 * damit eine fremde Seite kein Label auslösen kann.
 */
class APL_Bexio {

	const OPT_KEY   = 'apl_bexio_key';
	const OPT_CHECK = 'apl_bexio_files_check';

	public static function init() {
		add_action( 'admin_post_apl_bexio_label', array( __CLASS__, 'handle' ) );
		add_action( 'admin_post_nopriv_apl_bexio_label', array( __CLASS__, 'login_first' ) );
		add_action( 'admin_post_apl_bexio_pdf', array( __CLASS__, 'pdf' ) );
		add_action( 'admin_post_apl_bexio_new_key', array( __CLASS__, 'new_key' ) );
		add_action( 'admin_post_apl_bexio_files_check', array( __CLASS__, 'files_check' ) );
	}

	/**
	 * Schlüssel (wird bei Bedarf erzeugt).
	 *
	 * @return string
	 */
	public static function key() {
		$key = (string) get_option( self::OPT_KEY );
		if ( '' === $key ) {
			$key = wp_generate_password( 32, false );
			update_option( self::OPT_KEY, $key, false );
		}
		return $key;
	}

	/** Nicht angemeldet: zuerst anmelden, danach zurück zum gleichen Aufruf. */
	public static function login_first() {
		auth_redirect();
	}

	/* ------------------------------------------------------------------ */
	/* Bestellung finden                                                   */
	/* ------------------------------------------------------------------ */

	/**
	 * Bestellnummern und bexio-Auftragsnummern aus einem Text.
	 *
	 * @param string $text Text (Titel des Lieferscheins, Seitentext).
	 * @return array{orders:string[],bexio:string[],wc_ids:int[]}
	 */
	public static function parse_hints( $text ) {
		$text = (string) $text;
		$out  = array(
			'orders' => array(),
			'bexio'  => array(),
			'wc_ids' => array(),
		);
		if ( preg_match_all( '/woocommerce-(\d+)/i', $text, $m ) ) {
			$out['wc_ids'] = array_map( 'intval', $m[1] );
		}
		if ( preg_match_all( '/\b(AU-\d{3,})\b/i', $text, $m ) ) {
			$out['bexio'] = array_map( 'strtoupper', $m[1] );
		}
		if ( preg_match_all( '/#\s?(\d{3,7})\b/', $text, $m ) ) {
			$out['orders'] = $m[1];
		}
		if ( preg_match( '/^\s*(\d{3,7})\s*$/', $text, $m ) ) {
			$out['orders'][] = $m[1]; // Nur eine Zahl eingegeben.
		}
		foreach ( $out as $k => $v ) {
			$out[ $k ] = array_values( array_unique( $v ) );
		}
		return $out;
	}

	/**
	 * Bestellung nach Bestellnummer (Nummer = ID oder eigene Nummer).
	 *
	 * @param string $nr Nummer.
	 * @return WC_Order|null
	 */
	public static function order_by_number( $nr ) {
		$order = wc_get_order( absint( $nr ) );
		if ( $order instanceof WC_Order && (string) $order->get_order_number() === (string) $nr ) {
			return $order;
		}
		$found = wc_get_orders(
			array(
				'limit'      => 2,
				'meta_key'   => '_order_number', // phpcs:ignore WordPress.DB.SlowDBQuery
				'meta_value' => (string) $nr, // phpcs:ignore WordPress.DB.SlowDBQuery
				'type'       => 'shop_order',
			)
		);
		return 1 === count( $found ) ? $found[0] : null;
	}

	/**
	 * Bestellungen mit gleichem Meta-Wert.
	 *
	 * @param string $key   Meta-Schlüssel.
	 * @param string $value Wert.
	 * @param int    $limit Anzahl.
	 * @return WC_Order[]
	 */
	private static function orders_by_meta( $key, $value, $limit = 10 ) {
		return wc_get_orders(
			array(
				'limit'      => $limit,
				'meta_key'   => $key, // phpcs:ignore WordPress.DB.SlowDBQuery
				'meta_value' => (string) $value, // phpcs:ignore WordPress.DB.SlowDBQuery
				'type'       => 'shop_order',
				'orderby'    => 'date',
				'order'      => 'DESC',
			)
		);
	}

	/**
	 * Sucht die Bestellung zu einem Lieferschein.
	 *
	 * Reihenfolge: Hinweise vom Userscript (woocommerce-ID, AU-Nummer, #Bestellnummer), dann der
	 * Lieferschein aus der bexio-API (Titel, API-Referenz), zuletzt offene Bestellungen desselben
	 * bexio-Kontakts.
	 *
	 * @param int    $delivery_id bexio-ID des Lieferscheins (0 = unbekannt).
	 * @param string $hints       Text vom Userscript.
	 * @return array{order:?WC_Order,candidates:WC_Order[],delivery:array,how:string,error:string}
	 */
	public static function find_order( $delivery_id, $hints ) {
		$res = array(
			'order'      => null,
			'candidates' => array(),
			'delivery'   => array(),
			'how'        => '',
			'error'      => '',
		);

		$delivery = array();
		if ( $delivery_id && class_exists( 'BWS_Client' ) ) {
			try {
				$delivery = (array) BWS_Client::instance()->get( '2.0/kb_delivery/' . (int) $delivery_id );
			} catch ( Exception $e ) {
				$res['error'] = 'Lieferschein in bexio nicht lesbar: ' . $e->getMessage();
				apl_log( 'warning', 'bexio-Lieferschein ' . (int) $delivery_id . ': ' . $e->getMessage() );
			}
		}
		$res['delivery'] = $delivery;

		$p_hints = self::parse_hints( $hints ); // Erkennt auch eine allein eingegebene Bestellnummer.
		$text    = (string) $hints;
		foreach ( array( 'api_reference', 'title', 'document_nr' ) as $field ) {
			if ( ! empty( $delivery[ $field ] ) ) {
				$text .= ' ' . $delivery[ $field ];
			}
		}
		$p           = self::parse_hints( $text );
		$p['orders'] = array_values( array_unique( array_merge( $p_hints['orders'], $p['orders'] ) ) );
		if ( ! empty( $delivery['title'] ) && preg_match( '/^\s*(\d{3,7})\s*$/', (string) $delivery['title'], $m ) ) {
			$p['orders'][] = $m[1]; // Auftragstitel ohne Präfix = nur die Bestellnummer.
		}

		foreach ( $p['wc_ids'] as $id ) {
			$order = wc_get_order( $id );
			if ( $order instanceof WC_Order && 'shop_order' === $order->get_type() ) {
				$res['order'] = $order;
				$res['how']   = 'API-Referenz woocommerce-' . $id;
				return $res;
			}
		}
		foreach ( $p['bexio'] as $au ) {
			$found = self::orders_by_meta( '_bws_order_nr', $au, 2 );
			if ( 1 === count( $found ) ) {
				$res['order'] = $found[0];
				$res['how']   = 'bexio-Auftrag ' . $au;
				return $res;
			}
		}
		foreach ( $p['orders'] as $nr ) {
			$order = self::order_by_number( $nr );
			if ( $order ) {
				$res['order'] = $order;
				$res['how']   = 'Bestellnummer #' . $nr;
				return $res;
			}
		}

		// Letzter Versuch: offene Bestellungen desselben bexio-Kontakts.
		if ( ! empty( $delivery['contact_id'] ) ) {
			$open = array();
			foreach ( self::orders_by_meta( '_bws_contact_id', (int) $delivery['contact_id'], 20 ) as $order ) {
				if ( $order->has_status( array( 'processing', 'on-hold' ) ) && ! APL_Label::is_pickup( $order ) ) {
					$open[] = $order;
				}
			}
			$without_label = array_values(
				array_filter(
					$open,
					function ( $o ) {
						return ! $o->get_meta( APL_Label::META_IDENT );
					}
				)
			);
			if ( 1 === count( $without_label ) ) {
				$res['order'] = $without_label[0];
				$res['how']   = 'einzige offene Bestellung des Kontakts ohne Label';
				return $res;
			}
			$res['candidates'] = $open;
		}
		return $res;
	}

	/* ------------------------------------------------------------------ */
	/* Aufruf aus bexio                                                    */
	/* ------------------------------------------------------------------ */

	public static function handle() {
		nocache_headers();
		if ( ! current_user_can( 'edit_shop_orders' ) ) {
			self::page( 'Keine Berechtigung', '<p>Mit diesem Benutzer dürfen keine Bestellungen bearbeitet werden.</p>' );
		}
		$key = isset( $_GET['key'] ) ? sanitize_text_field( wp_unslash( $_GET['key'] ) ) : '';
		if ( ! hash_equals( self::key(), $key ) ) {
			self::page( 'Schlüssel falsch', '<p>Der Schlüssel im Userscript stimmt nicht. Den aktuellen Schlüssel findest du unter <a href="' . esc_url( admin_url( 'options-general.php?page=armatum-post-label#apl-bexio' ) ) . '">Einstellungen &gt; Armatum Post Label</a>. Im bexio-Lieferschein mit gedrückter Umschalttaste auf «Postlabel drucken» klicken, um ihn neu einzugeben.</p>' );
		}

		$delivery_id = isset( $_GET['delivery'] ) ? absint( $_GET['delivery'] ) : 0;
		$hints       = isset( $_GET['hints'] ) ? sanitize_textarea_field( wp_unslash( $_GET['hints'] ) ) : '';
		$order_id    = isset( $_GET['order_id'] ) ? absint( $_GET['order_id'] ) : 0; // Auswahl aus der Kandidatenliste.
		$renew       = ! empty( $_GET['renew'] );

		if ( $order_id ) {
			$order = wc_get_order( $order_id );
			$found = array(
				'order'      => $order instanceof WC_Order ? $order : null,
				'candidates' => array(),
				'how'        => 'Auswahl',
				'error'      => '',
				'delivery'   => array(),
			);
		} else {
			$found = self::find_order( $delivery_id, $hints );
		}
		$order = $found['order'];

		if ( ! $order ) {
			$body = '<p>Zu diesem Lieferschein wurde keine eindeutige Shop-Bestellung gefunden.</p>';
			if ( $found['error'] ) {
				$body .= '<p class="err">' . esc_html( $found['error'] ) . '</p>';
			}
			if ( $found['candidates'] ) {
				$body .= '<p>Offene Bestellungen dieses Kunden:</p><ul>';
				foreach ( $found['candidates'] as $c ) {
					$url   = add_query_arg(
						array(
							'action'   => 'apl_bexio_label',
							'key'      => $key,
							'order_id' => $c->get_id(),
						),
						admin_url( 'admin-post.php' )
					);
					$ident = (string) $c->get_meta( APL_Label::META_IDENT );
					$body .= sprintf(
						'<li><a href="%s">#%s</a> · %s · %s%s</li>',
						esc_url( $url ),
						esc_html( $c->get_order_number() ),
						esc_html( $c->get_formatted_shipping_full_name() ?: $c->get_formatted_billing_full_name() ),
						esc_html( wc_format_datetime( $c->get_date_created() ) ),
						$ident ? ' · hat Label ' . esc_html( $ident ) : ''
					);
				}
				$body .= '</ul>';
			}
			$body .= '<form method="get" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '"><input type="hidden" name="action" value="apl_bexio_label"><input type="hidden" name="key" value="' . esc_attr( $key ) . '"><label>Bestellnummer: <input name="hints" placeholder="#36895" required></label> <button>Label drucken</button></form>';
			self::page( 'Bestellung nicht gefunden', $body );
		}

		$nr      = $order->get_order_number();
		$preview = 'yes' === get_option( 'armatum_post_test_mode', 'yes' );
		$ident   = (string) $order->get_meta( APL_Label::META_IDENT );
		$old_test = $ident && 'yes' === $order->get_meta( APL_Label::META_PREVIEW ) && ! $preview;
		$note    = '';

		if ( ! $ident || $renew || $old_test ) {
			$reason = self::block_reason( $order );
			if ( $reason ) {
				self::page( 'Kein Label für #' . $nr, '<p class="err">' . esc_html( $reason ) . '</p>' . self::order_link( $order ) );
			}
			$names = APL_Label::shipping_names( $order );
			try {
				$ident = APL_Label::create( $order, APL_Label::order_weight_g( $order ), APL_Util::suggest_signature( $names ), APL_Util::suggest_bulky( $names ) );
				$order = wc_get_order( $order->get_id() );
				$note  = 'Neu erstellt';
				$order->add_order_note( 'Post-Label aus bexio-Lieferschein ausgelöst' . ( $delivery_id ? ' (bexio-ID ' . $delivery_id . ')' : '' ) . '.' );
			} catch ( Exception $e ) {
				$order->add_order_note( 'Post-Label nicht erstellt (aus bexio): ' . $e->getMessage() );
				apl_log( 'error', sprintf( 'Bestellung #%s (aus bexio): %s', $nr, $e->getMessage() ) );
				self::page( 'Label nicht erstellt', '<p class="err">' . esc_html( $e->getMessage() ) . '</p>' . self::order_link( $order ) );
			}
		} else {
			$note = 'Vorhandenes Label';
		}

		$pdf = add_query_arg(
			array(
				'action'   => 'apl_bexio_pdf',
				'order_id' => $order->get_id(),
				'_wpnonce' => wp_create_nonce( 'apl_download_' . $order->get_id() ),
			),
			admin_url( 'admin-post.php' )
		);
		$renew_url = add_query_arg(
			array(
				'action'   => 'apl_bexio_label',
				'key'      => $key,
				'order_id' => $order->get_id(),
				'renew'    => 1,
			),
			admin_url( 'admin-post.php' )
		);
		$info = (string) $order->get_meta( APL_Label::META_INFO );
		$body = sprintf(
			'<p><strong>#%1$s</strong> · %2$s · %3$s · Sendungsnummer %4$s%5$s</p><p class="small">%6$s (gefunden über %7$s). <a href="%8$s" target="_blank">PDF öffnen</a> · <button type="button" onclick="pr()">Nochmals drucken</button> · <a href="%9$s" onclick="return confirm(\'Wirklich ein neues Label erstellen? Das alte wird ersetzt.\')">Neues Label erstellen</a> · %10$s</p><iframe id="pdf" src="%8$s"></iframe>',
			esc_html( $nr ),
			esc_html( $order->get_formatted_shipping_full_name() ?: $order->get_formatted_billing_full_name() ),
			esc_html( $info ),
			esc_html( $ident ),
			'yes' === $order->get_meta( APL_Label::META_PREVIEW ) ? ' · <strong class="err">TESTLABEL (SPECIMEN)</strong>' : '',
			esc_html( $note ),
			esc_html( $found['how'] ),
			esc_url( $pdf ),
			esc_url( $renew_url ),
			self::order_link( $order, false )
		);
		$body .= '<script>function pr(){try{document.getElementById("pdf").contentWindow.focus();document.getElementById("pdf").contentWindow.print();}catch(e){window.open(document.getElementById("pdf").src);}}document.getElementById("pdf").addEventListener("load",function(){setTimeout(pr,600);});</script>';
		self::page( 'Postlabel #' . $nr, $body );
	}

	/**
	 * Gründe, warum aus bexio kein Label erstellt wird ('' = ok). Wie bei der Sammelaktion, ohne die
	 * Prüfung auf ein vorhandenes Label.
	 *
	 * @param WC_Order $order Bestellung.
	 * @return string
	 */
	public static function block_reason( WC_Order $order ) {
		if ( APL_Label::is_pickup( $order ) ) {
			return 'Die Bestellung wird abgeholt – kein Label nötig.';
		}
		if ( $order->has_status( array( 'cancelled', 'refunded', 'failed', 'pending' ) ) ) {
			return 'Status „' . wc_get_order_status_name( $order->get_status() ) . '“ – kein Label.';
		}
		foreach ( APL_Label::shipping_names( $order ) as $name ) {
			if ( preg_match( '/brief/i', $name ) ) {
				return 'Versandart „' . $name . '“ (Brief) wird nicht unterstützt.';
			}
		}
		if ( ! APL_Label::order_weight_g( $order ) ) {
			return 'Gewicht fehlt bei den Produkten – Label in der Bestellung mit Gewicht von Hand erstellen.';
		}
		return '';
	}

	private static function order_link( WC_Order $order, $para = true ) {
		$a = '<a href="' . esc_url( $order->get_edit_order_url() ) . '" target="_blank">Bestellung im Shop öffnen</a>';
		return $para ? '<p>' . $a . '</p>' : $a;
	}

	/**
	 * Einfache Seite ausgeben und beenden.
	 *
	 * @param string $title Titel.
	 * @param string $body  HTML (bereits maskiert).
	 */
	private static function page( $title, $body ) {
		header( 'Content-Type: text/html; charset=utf-8' );
		header( 'X-Robots-Tag: noindex' );
		echo '<!doctype html><html lang="de"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>' . esc_html( $title ) . '</title>';
		echo '<style>body{font:15px/1.45 -apple-system,Segoe UI,Arial,sans-serif;margin:16px;color:#1d2327;background:#f6f7f7}h1{font-size:20px;margin:0 0 8px}.err{color:#b32d2e}.small{font-size:13px;color:#50575e}iframe{width:100%;height:calc(100vh - 150px);border:1px solid #c3c4c7;background:#fff}button{cursor:pointer}</style></head><body>';
		echo '<h1>' . esc_html( $title ) . '</h1>' . $body . '</body></html>'; // phpcs:ignore WordPress.Security.EscapeOutput
		exit;
	}

	/** PDF inline ausgeben (gleiche Prüfung wie der normale Download). */
	public static function pdf() {
		APL_Label::handle_download();
	}

	/* ------------------------------------------------------------------ */
	/* Einstellungen                                                       */
	/* ------------------------------------------------------------------ */

	public static function new_key() {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( 'Keine Berechtigung.' );
		}
		check_admin_referer( 'apl_bexio_new_key' );
		update_option( self::OPT_KEY, wp_generate_password( 32, false ), false );
		wp_safe_redirect( admin_url( 'options-general.php?page=armatum-post-label&saved=1#apl-bexio' ) );
		exit;
	}

	/**
	 * Prüft, ob der bexio-Zugang des bexio-Sync-Plugins Dateien lesen darf (nur lesen, nichts ändern).
	 */
	public static function files_check() {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( 'Keine Berechtigung.' );
		}
		check_admin_referer( 'apl_bexio_files_check' );
		$result = array(
			'time' => time(),
			'ok'   => false,
			'msg'  => '',
		);
		if ( ! class_exists( 'BWS_Client' ) ) {
			$result['msg'] = 'bexio-Sync-Plugin ist nicht aktiv.';
		} else {
			try {
				$files         = BWS_Client::instance()->get( '3.0/files', array( 'limit' => 5 ) );
				$count         = is_array( $files ) ? count( $files ) : 0;
				$result['ok']  = true;
				$result['msg'] = sprintf( 'Dateien lesbar (Test: %d Datei(en) geladen).', $count );
				if ( $count && isset( $files[0]['id'] ) ) {
					try {
						BWS_Client::instance()->get( '3.0/files/' . (int) $files[0]['id'] . '/usage' );
						$result['msg'] .= ' Zuordnung Datei → Kontakt/Dokument lesbar.';
					} catch ( Exception $e ) {
						$result['msg'] .= ' Zuordnung Datei → Kontakt nicht lesbar: ' . $e->getMessage();
					}
				}
			} catch ( Exception $e ) {
				$result['msg'] = $e->getMessage();
			}
		}
		update_option( self::OPT_CHECK, $result, false );
		apl_log( 'info', 'bexio-Dateizugriff: ' . $result['msg'] );
		wp_safe_redirect( admin_url( 'options-general.php?page=armatum-post-label#apl-bexio' ) );
		exit;
	}

	/** Abschnitt auf der Einstellungsseite. */
	public static function settings_section() {
		$check = get_option( self::OPT_CHECK );
		?>
		<h2 id="apl-bexio">Postlabel aus bexio (Test)</h2>
		<p>Mit dem Userscript «bexio Postlabel» (Tampermonkey) erscheint im bexio-Lieferschein der Knopf <strong>«Postlabel drucken»</strong>. Beim ersten Klick fragt das Script nach diesem Schlüssel:</p>
		<p><input type="text" readonly value="<?php echo esc_attr( self::key() ); ?>" class="regular-text code" onclick="this.select()"></p>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:inline">
			<input type="hidden" name="action" value="apl_bexio_new_key"><?php wp_nonce_field( 'apl_bexio_new_key' ); ?>
			<button class="button" onclick="return confirm('Neuen Schlüssel erzeugen? Der alte funktioniert danach nicht mehr.')">Neuen Schlüssel erzeugen</button>
		</form>
		<p class="description">Der Shop muss im gleichen Browser angemeldet sein. Ohne Anmeldung kommt zuerst die Anmeldeseite.</p>
		<h3>bexio-Dateizugriff</h3>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<input type="hidden" name="action" value="apl_bexio_files_check"><?php wp_nonce_field( 'apl_bexio_files_check' ); ?>
			<button class="button">Dateizugriff prüfen</button>
			<span class="description">Prüft nur lesend, ob der API-Token des bexio-Sync-Plugins die Dateien (z.B. Waffenerwerbsscheine bei Kontakten) lesen darf.</span>
		</form>
		<?php if ( is_array( $check ) ) : ?>
			<p style="color:<?php echo $check['ok'] ? '#00a32a' : '#b32d2e'; ?>"><strong><?php echo $check['ok'] ? 'OK' : 'Nicht möglich'; ?>:</strong> <?php echo esc_html( $check['msg'] ); ?> <span class="description">(<?php echo esc_html( wp_date( 'd.m.Y H:i', $check['time'] ) ); ?>)</span></p>
		<?php endif; ?>
		<?php
	}
}
