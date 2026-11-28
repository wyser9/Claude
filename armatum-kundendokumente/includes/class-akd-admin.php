<?php
defined( 'ABSPATH' ) || exit;

/**
 * Anzeige in der Bestellung, Spalte in der Bestellliste, Einstellungsseite.
 */
class AKD_Admin {

	const PAGE = 'armatum-kundendokumente';

	public static function init() {
		add_action( 'add_meta_boxes', array( __CLASS__, 'meta_box' ), 30 );
		add_action( 'admin_post_akd_recheck', array( __CLASS__, 'recheck' ) );
		add_action( 'admin_post_akd_remind', array( __CLASS__, 'remind' ) );
		add_action( 'admin_post_akd_refresh', array( __CLASS__, 'refresh_index' ) );
		add_action( 'admin_menu', array( __CLASS__, 'menu' ), 60 );
		add_action( 'admin_notices', array( __CLASS__, 'notice' ) );

		// Spalte in der Bestellliste (HPOS und klassisch).
		add_filter( 'manage_woocommerce_page_wc-orders_columns', array( __CLASS__, 'add_column' ) );
		add_action( 'manage_woocommerce_page_wc-orders_custom_column', array( __CLASS__, 'column_hpos' ), 10, 2 );
		add_filter( 'manage_edit-shop_order_columns', array( __CLASS__, 'add_column' ) );
		add_action( 'manage_shop_order_posts_custom_column', array( __CLASS__, 'column_legacy' ), 10, 2 );
	}

	/** Farbe und Text je Status. */
	public static function badge( $status ) {
		$map = array(
			'ok'      => array( '#00a32a', '✔ vollständig' ),
			'pruefen' => array( '#dba617', '? prüfen' ),
			'fehlt'   => array( '#d63638', '✘ fehlt' ),
			'warten'  => array( '#8c8f94', '… ausstehend' ),
		);
		$m = $map[ $status ] ?? array( '#8c8f94', $status );
		return '<span style="display:inline-block;padding:1px 7px;border-radius:3px;color:#fff;background:' . esc_attr( $m[0] ) . '">' . esc_html( $m[1] ) . '</span>';
	}

	public static function add_column( $cols ) {
		$new = array();
		foreach ( $cols as $k => $v ) {
			$new[ $k ] = $v;
			if ( 'order_status' === $k ) {
				$new['akd_docs'] = 'Dokumente';
			}
		}
		if ( ! isset( $new['akd_docs'] ) ) {
			$new['akd_docs'] = 'Dokumente';
		}
		return $new;
	}

	public static function column_hpos( $col, $order ) {
		if ( 'akd_docs' === $col ) {
			self::column_output( $order );
		}
	}

	public static function column_legacy( $col, $post_id ) {
		if ( 'akd_docs' === $col ) {
			self::column_output( wc_get_order( $post_id ) );
		}
	}

	private static function column_output( $order ) {
		if ( ! $order ) {
			return;
		}
		$r = $order->get_meta( AKD_Check::META_RESULT );
		if ( is_array( $r ) ) {
			echo self::badge( $r['status'] ); // phpcs:ignore WordPress.Security.EscapeOutput
			if ( $order->get_meta( AKD_Check::META_REMINDED ) ) {
				echo ' <span title="Erinnerung gesendet">✉</span>';
			}
		}
	}

	public static function meta_box() {
		$screen = function_exists( 'wc_get_page_screen_id' ) ? wc_get_page_screen_id( 'shop-order' ) : 'shop_order';
		add_meta_box( 'akd-docs', 'Kundendokumente (bexio)', array( __CLASS__, 'render_box' ), $screen, 'side', 'high' );
	}

	public static function render_box( $post_or_order ) {
		$order = $post_or_order instanceof WC_Order ? $post_or_order : wc_get_order( $post_or_order->ID );
		if ( ! $order ) {
			return;
		}
		$r      = $order->get_meta( AKD_Check::META_RESULT );
		$labels = AKD_Docs::labels();
		if ( ! is_array( $r ) ) {
			echo '<p>' . ( AKD_Check::relevant_items( $order ) ? 'Noch nicht geprüft.' : 'Keine Artikel mit Erwerbsbedingung – keine Prüfung nötig.' ) . '</p>';
		} else {
			echo '<p>' . self::badge( $r['status'] ) . ' <span class="description">geprüft ' . esc_html( wp_date( 'd.m.Y H:i', $r['time'] ) ) . ( $r['contact'] ? ', bexio-Kontakt ' . (int) $r['contact'] : '' ) . '</span></p>'; // phpcs:ignore
			if ( ! empty( $r['docs'] ) ) {
				echo '<ul style="margin:0 0 8px 0">';
				foreach ( array( AKD_Docs::AUSWEIS, AKD_Docs::WES, AKD_Docs::STRAF, AKD_Docs::JAGD ) as $t ) {
					$d = $r['docs'][ $t ];
					if ( AKD_Docs::JAGD === $t && AKD_Docs::FEHLT === $d['status'] ) {
						continue;
					}
					$icon = array( 'ok' => '✔', 'pruefen' => '?', 'fehlt' => '✘' )[ $d['status'] ] ?? '';
					echo '<li><strong>' . esc_html( $icon . ' ' . $labels[ $t ] ) . '</strong><br><span class="description">' . esc_html( $d['note'] ) . ( ! empty( $d['name'] ) ? ' – ' . esc_html( $d['name'] ) : '' ) . '</span></li>';
				}
				echo '</ul>';
				if ( ! empty( $r['unknown'] ) ) {
					echo '<p class="description">Weitere Dateien beim Kontakt (Art nicht erkannt): ' . esc_html( implode( ', ', $r['unknown'] ) ) . '</p>';
				}
			}
			if ( ! empty( $r['note'] ) ) {
				echo '<p class="description">' . esc_html( $r['note'] ) . '</p>';
			}
		}

		$url = admin_url( 'admin-post.php' );
		echo '<p><a class="button" href="' . esc_url( wp_nonce_url( add_query_arg( array( 'action' => 'akd_recheck', 'order' => $order->get_id() ), $url ), 'akd_recheck_' . $order->get_id() ) ) . '">Jetzt prüfen</a></p>';

		if ( is_array( $r ) && in_array( $r['status'], array( 'fehlt', 'pruefen' ), true ) ) {
			$sent = (int) $order->get_meta( AKD_Check::META_REMINDED );
			// Eigenes Formular ausserhalb des Bestellformulars geht in der Seitenleiste nicht -> Felder mit form-Attribut.
			echo '<details' . ( $sent ? '' : ' open' ) . '><summary><strong>Erinnerung an Kunden</strong>' . ( $sent ? ' <span class="description">(gesendet ' . esc_html( wp_date( 'd.m.Y H:i', $sent ) ) . ')</span>' : '' ) . '</summary>';
			echo '<p class="description">An ' . esc_html( $order->get_billing_email() ) . '. Text vor dem Senden anpassen.</p>';
			echo '<textarea form="akd-remind-form" name="akd_text" rows="12" style="width:100%">' . esc_textarea( AKD_Check::reminder_text( $order, $r ) ) . '</textarea>';
			echo '<p><button class="button button-primary" form="akd-remind-form" onclick="return confirm(\'Erinnerung jetzt an den Kunden senden?\')">Erinnerung senden</button></p></details>';
			add_action(
				'admin_footer',
				function () use ( $order, $url ) {
					echo '<form id="akd-remind-form" method="post" action="' . esc_url( $url ) . '" style="display:none"><input type="hidden" name="action" value="akd_remind"><input type="hidden" name="order" value="' . (int) $order->get_id() . '">';
					wp_nonce_field( 'akd_remind_' . $order->get_id() );
					echo '</form>';
				}
			);
		}
	}

	private static function back( $order_id, $msg ) {
		set_transient( 'akd_notice_' . get_current_user_id(), $msg, 60 );
		$order = wc_get_order( $order_id );
		wp_safe_redirect( $order ? $order->get_edit_order_url() : admin_url() );
		exit;
	}

	public static function recheck() {
		$id = absint( $_GET['order'] ?? 0 );
		if ( ! current_user_can( 'edit_shop_orders' ) ) {
			wp_die( 'Keine Berechtigung.' );
		}
		check_admin_referer( 'akd_recheck_' . $id );
		$order = wc_get_order( $id );
		if ( ! $order ) {
			wp_die( 'Bestellung nicht gefunden.' );
		}
		$r = AKD_Check::run( $order, true );
		self::back( $id, 'Kundendokumente geprüft: ' . AKD_Check::note_text( $r ) );
	}

	public static function remind() {
		$id = absint( $_POST['order'] ?? 0 );
		if ( ! current_user_can( 'edit_shop_orders' ) ) {
			wp_die( 'Keine Berechtigung.' );
		}
		check_admin_referer( 'akd_remind_' . $id );
		$order = wc_get_order( $id );
		$text  = sanitize_textarea_field( wp_unslash( $_POST['akd_text'] ?? '' ) );
		if ( ! $order || '' === $text ) {
			wp_die( 'Bestellung oder Text fehlt.' );
		}
		$ok = AKD_Check::send_reminder( $order, $text );
		self::back( $id, $ok ? 'Erinnerung an ' . $order->get_billing_email() . ' gesendet.' : 'Erinnerung konnte nicht gesendet werden (E-Mail-Adresse oder Mailversand prüfen).' );
	}

	public static function notice() {
		$key = 'akd_notice_' . get_current_user_id();
		$msg = get_transient( $key );
		if ( $msg ) {
			delete_transient( $key );
			echo '<div class="notice notice-info is-dismissible"><p>' . esc_html( $msg ) . '</p></div>';
		}
	}

	public static function menu() {
		add_submenu_page( 'woocommerce', 'Kundendokumente', 'Kundendokumente', 'manage_woocommerce', self::PAGE, array( __CLASS__, 'page' ) );
	}

	public static function refresh_index() {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( 'Keine Berechtigung.' );
		}
		check_admin_referer( 'akd_refresh' );
		delete_transient( 'akd_index_lock' );
		AKD_Index::refresh( 300 );
		wp_safe_redirect( admin_url( 'admin.php?page=' . self::PAGE ) );
		exit;
	}

	public static function page() {
		$run   = get_option( AKD_Index::OPTION_RUN );
		$index = AKD_Index::all();
		$open  = 0;
		$with  = 0;
		$types = array();
		foreach ( $index as $f ) {
			if ( empty( $f['u'] ) ) {
				$open++;
			} elseif ( ! empty( $f['k'] ) ) {
				$with++;
				$t = AKD_Docs::classify( $f['n'] ) ?: 'unbekannt';
				$types[ $t ] = ( $types[ $t ] ?? 0 ) + 1;
			}
		}
		$labels = AKD_Docs::labels() + array( 'unbekannt' => 'Art nicht erkannt' );
		echo '<div class="wrap"><h1>Kundendokumente (bexio)</h1>';
		echo '<p>Bei Bestellungen mit Erwerbsbedingung wird anhand der Dateien beim bexio-Kontakt geprüft: <strong>Ausweiskopie gültig</strong> und <strong>Waffenerwerbsschein max. 2 Jahre</strong> oder <strong>Strafregisterauszug max. 3 Monate</strong>. Das Ergebnis steht in der Bestellung (Kasten «Kundendokumente») und in der Bestellliste (Spalte «Dokumente»).</p>';
		echo '<h2>Dateinamen in bexio</h2><ul style="list-style:disc;margin-left:20px">';
		echo '<li>Die Art wird aus dem Dateinamen erkannt: <code>Ausweis</code>, <code>ID</code>, <code>Pass</code>, <code>Identitätskarte</code>, <code>Ausländerausweis</code> · <code>WES</code>, <code>Waffenerwerbsschein</code> · <code>Strafregister</code> · <code>Jagdschein</code>.</li>';
		echo '<li>Ausweis: ein Datum im Namen gilt als <strong>gültig bis</strong>, z.B. <code>Ausweis bis 31.05.2031.pdf</code>. Ohne Datum: «bitte prüfen».</li>';
		echo '<li>WES und Strafregister: ein Datum im Namen gilt als <strong>Ausstelldatum</strong>, z.B. <code>WES 12.03.2025.pdf</code>. Ohne Datum zählt das Upload-Datum in bexio.</li>';
		echo '<li>Datumsformate: 31.05.2031, 2031-05-31, 20310531, 05.2031.</li></ul>';

		echo '<h2>Dateiverzeichnis</h2>';
		if ( is_array( $run ) ) {
			printf(
				'<p>Letzter Lauf %s: %d Dateien in bexio, %d neu, %d Zuordnungen gelesen, %d noch offen.%s</p>',
				esc_html( wp_date( 'd.m.Y H:i', $run['time'] ) ),
				(int) $run['files'],
				(int) $run['new'],
				(int) $run['usage'],
				(int) $open,
				$run['error'] ? ' <strong style="color:#d63638">Fehler: ' . esc_html( $run['error'] ) . '</strong>' : ''
			);
		} else {
			echo '<p>Noch kein Lauf.</p>';
		}
		echo '<p>' . (int) $with . ' Dateien hängen an Kontakten.';
		foreach ( $types as $t => $n ) {
			echo ' ' . esc_html( $labels[ $t ] ?? $t ) . ': ' . (int) $n . '.';
		}
		echo '</p>';
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '"><input type="hidden" name="action" value="akd_refresh">';
		wp_nonce_field( 'akd_refresh' );
		echo '<button class="button">Verzeichnis jetzt aktualisieren</button> <span class="description">Liest die Dateiliste und bis 300 neue Zuordnungen (sonst stündlich automatisch).</span></form>';

		$sample = get_option( AKD_Index::OPTION_RAW );
		if ( $sample ) {
			echo '<h3>Beispielantwort Zuordnung (ohne Texte)</h3><pre style="background:#fff;padding:8px;max-width:700px;overflow:auto">' . esc_html( wp_json_encode( $sample, JSON_PRETTY_PRINT ) ) . '</pre>';
		}

		$cid = absint( $_GET['contact'] ?? 0 ); // phpcs:ignore WordPress.Security.NonceVerification
		echo '<h2>Kontakt testen</h2><form method="get"><input type="hidden" name="page" value="' . esc_attr( self::PAGE ) . '"><input type="number" name="contact" value="' . ( $cid ? (int) $cid : '' ) . '" placeholder="bexio-Kontakt-ID"> <button class="button">Anzeigen</button></form>';
		if ( $cid ) {
			$files = AKD_Index::files_for( $cid );
			$r     = AKD_Docs::evaluate( $files, time() );
			echo '<p>' . self::badge( $r['status'] ) . ' ' . count( $files ) . ' Datei(en).</p><table class="widefat striped" style="max-width:900px"><thead><tr><th>Datei</th><th>Erkannt als</th><th>Datum im Namen</th><th>Upload</th></tr></thead><tbody>'; // phpcs:ignore
			foreach ( $files as $f ) {
				$t = AKD_Docs::classify( $f['name'] );
				$d = AKD_Docs::dates( $f['name'] );
				echo '<tr><td>' . esc_html( $f['name'] ) . '</td><td>' . esc_html( $t ? $labels[ $t ] : '–' ) . '</td><td>' . esc_html( $d ? gmdate( 'd.m.Y', end( $d ) ) : '–' ) . '</td><td>' . esc_html( $f['created'] ? wp_date( 'd.m.Y', $f['created'] ) : '–' ) . '</td></tr>';
			}
			echo '</tbody></table><ul>';
			foreach ( $r['docs'] as $t => $d ) {
				echo '<li>' . esc_html( $labels[ $t ] . ': ' . $d['note'] ) . '</li>';
			}
			echo '</ul>';
		}
		echo '</div>';
	}
}
