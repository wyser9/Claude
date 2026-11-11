<?php
defined( 'ABSPATH' ) || exit;

/**
 * TWINT-Abrechnung: ordnet die Zeilen der Abrechnungs-CSV aus dem TWINT-Portal den Bestellungen zu.
 *
 * Pro Überweisung (Sammelgutschrift) zeigt die Auswertung, welche Bestellungen bezahlt bzw. erstattet wurden,
 * die Kommission und den gutgeschriebenen Betrag, und prüft Abweichungen.
 *
 * Zuordnung über die „Händlertransaktions-ID“: Zahlungen „<Bestell-ID>-xxxx“, Rückerstattungen
 * „R-<TWINT-Order-ID>-xxxx“ (über die TWINT-Order-ID der ursprünglichen Zahlung bzw. die Tabelle
 * des TWINT-Plugins). In bexio wird nichts gebucht.
 */
class BWS_Twint {

	const PAGE         = 'bws-twint';
	const META_PAYOUT  = '_bws_twint_payout';
	const TRANSIENT    = 'bws_twint_report_';

	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'menu' ), 20 );
		add_action( 'admin_post_bws_twint_upload', array( __CLASS__, 'handle_upload' ) );
		add_action( 'admin_post_bws_twint_export', array( __CLASS__, 'handle_export' ) );
	}

	public static function menu() {
		add_submenu_page( 'woocommerce', 'TWINT-Abrechnung', 'TWINT-Abrechnung', 'manage_woocommerce', self::PAGE, array( __CLASS__, 'render_page' ) );
	}

	/* ------------------------------------------------------------------ */
	/* CSV einlesen                                                        */
	/* ------------------------------------------------------------------ */

	/**
	 * Liest die Abrechnungs-CSV (Semikolon, UTF-8 oder Windows-1252, Kopfzeilen vor der Tabelle).
	 *
	 * @param string $content Dateiinhalt.
	 * @return array{meta:array,rows:array} rows: payout_date, fee, credited, amount, currency, date, time, type, twint_order_id, merchant_ref.
	 * @throws Exception Wenn die Datei keine TWINT-Abrechnung ist.
	 */
	public static function parse( $content ) {
		$content = (string) $content;
		if ( 0 === strpos( $content, "\xEF\xBB\xBF" ) ) {
			$content = substr( $content, 3 );
		} elseif ( function_exists( 'mb_check_encoding' ) && ! mb_check_encoding( $content, 'UTF-8' ) ) {
			$content = mb_convert_encoding( $content, 'UTF-8', 'Windows-1252' );
		}
		$lines = preg_split( '/\r\n|\r|\n/', $content );
		$meta  = array();
		$head  = null;
		$rows  = array();
		foreach ( $lines as $line ) {
			if ( '' === trim( $line ) ) {
				continue;
			}
			$cells = array_map( 'trim', str_getcsv( $line, ';', '"', '\\' ) );
			if ( null === $head ) {
				if ( in_array( 'Händlertransaktions-ID', $cells, true ) || in_array( 'Merchant transaction ID', $cells, true ) ) {
					$head = $cells;
				} elseif ( 2 === count( $cells ) ) {
					$meta[ $cells[0] ] = $cells[1];
				}
				continue;
			}
			$row = array();
			foreach ( $head as $i => $name ) {
				// Doppelte Spaltennamen ("Währung"): der erste Wert gewinnt.
				if ( ! array_key_exists( $name, $row ) ) {
					$row[ $name ] = $cells[ $i ] ?? '';
				}
			}
			$rows[] = array(
				'payout_date'    => self::date( $row['Überweisung am'] ?? '' ),
				'fee'            => self::num( $row['Transaktionsgebühr'] ?? 0 ),
				'credited'       => self::num( $row['Gutgeschriebener Betrag'] ?? 0 ),
				'amount'         => self::num( $row['Betrag Transaktion'] ?? 0 ),
				'currency'       => $row['Währung'] ?? 'CHF',
				'date'           => self::date( $row['Transaktionsdatum'] ?? '' ),
				'time'           => $row['Transaktionszeit'] ?? '',
				'type'           => $row['Typ'] ?? '',
				'twint_order_id' => $row['Order ID'] ?? '',
				'merchant_ref'   => $row['Händlertransaktions-ID'] ?? '',
			);
		}
		if ( null === $head ) {
			throw new Exception( 'Das ist keine TWINT-Abrechnung (Spalte „Händlertransaktions-ID“ fehlt).' );
		}
		return array(
			'meta' => $meta,
			'rows' => $rows,
		);
	}

	private static function num( $value ) {
		$value = str_replace( array( "'", ' ', "\xC2\xA0" ), '', (string) $value );
		if ( preg_match( '/^-?\d+,\d+$/', $value ) ) {
			$value = str_replace( ',', '.', $value );
		}
		return round( (float) $value, 2 );
	}

	/**
	 * "09.11.2026" -> "2026-11-09".
	 */
	private static function date( $value ) {
		return preg_match( '/^(\d{2})\.(\d{2})\.(\d{4})$/', trim( (string) $value ), $m ) ? "{$m[3]}-{$m[2]}-{$m[1]}" : (string) $value;
	}

	/* ------------------------------------------------------------------ */
	/* Auswertung                                                          */
	/* ------------------------------------------------------------------ */

	/**
	 * Bestell-ID zu einer Zeile.
	 *
	 * @param array $row       Zeile.
	 * @param array $by_twint  TWINT-Order-ID => Bestell-ID (aus derselben Datei).
	 * @return int
	 */
	public static function order_id_for( array $row, array $by_twint ) {
		$ref = (string) $row['merchant_ref'];
		if ( preg_match( '/^(\d+)-[A-Za-z0-9]{2,8}$/', $ref, $m ) ) {
			return (int) $m[1];
		}
		if ( preg_match( '/^R-([0-9a-f-]{36})-/i', $ref, $m ) ) {
			$uuid = strtolower( $m[1] );
			if ( isset( $by_twint[ $uuid ] ) ) {
				return $by_twint[ $uuid ];
			}
			global $wpdb;
			$table = $wpdb->prefix . 'twint_pairing';
			if ( $table === $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) ) { // phpcs:ignore WordPress.DB.DirectDatabaseQuery
				return (int) $wpdb->get_var( $wpdb->prepare( "SELECT wc_order_id FROM {$table} WHERE id = %s", $uuid ) ); // phpcs:ignore WordPress.DB
			}
		}
		return 0;
	}

	/**
	 * Wertet die Abrechnung aus.
	 *
	 * @param array $parsed Ergebnis von parse().
	 * @return array{payouts:array,open:array,meta:array}
	 */
	public static function report( array $parsed ) {
		$by_twint = array();
		foreach ( $parsed['rows'] as $row ) {
			if ( preg_match( '/^(\d+)-[A-Za-z0-9]{2,8}$/', $row['merchant_ref'], $m ) && $row['twint_order_id'] ) {
				$by_twint[ strtolower( $row['twint_order_id'] ) ] = (int) $m[1];
			}
		}

		$payouts = array();
		$seen    = array();
		foreach ( $parsed['rows'] as $row ) {
			$order_id = self::order_id_for( $row, $by_twint );
			$order    = $order_id ? wc_get_order( $order_id ) : null;
			$refund   = $row['amount'] < 0 || 'Cancellation' === $row['type'];
			$issues   = array();

			if ( ! $order ) {
				$issues[] = 'Bestellung nicht gefunden';
			} elseif ( ! $refund && abs( (float) $order->get_total() - $row['amount'] ) > 0.009 ) {
				$issues[] = sprintf( 'Betrag weicht ab: Bestellung %s', wc_format_decimal( $order->get_total(), 2 ) );
			} elseif ( $refund && $order->get_total_refunded() <= 0 ) {
				$issues[] = 'Im Shop keine Rückerstattung erfasst';
			}
			if ( $order && ! $refund ) {
				$key = $order->get_id() . '|' . $row['amount'];
				if ( isset( $seen[ $key ] ) ) {
					$issues[] = 'Doppelt in der Abrechnung';
				}
				$seen[ $key ] = true;
			}

			$payout = $row['payout_date'];
			if ( ! isset( $payouts[ $payout ] ) ) {
				$payouts[ $payout ] = array(
					'date'     => $payout,
					'lines'    => array(),
					'amount'   => 0.0,
					'fee'      => 0.0,
					'credited' => 0.0,
				);
			}
			$payouts[ $payout ]['lines'][] = array(
				'row'      => $row,
				'order_id' => $order ? $order->get_id() : 0,
				'number'   => $order ? $order->get_order_number() : '',
				'customer' => $order ? trim( $order->get_formatted_billing_full_name() ) : '',
				'status'   => $order ? wc_get_order_status_name( $order->get_status() ) : '',
				'bexio'    => $order ? (string) $order->get_meta( BWS_Order_Sync::META_ORDER_NR ) : '',
				'refund'   => $refund,
				'issues'   => $issues,
			);
			$payouts[ $payout ]['amount']   += $row['amount'];
			$payouts[ $payout ]['fee']      += $row['fee'];
			$payouts[ $payout ]['credited'] += $row['credited'];
		}
		krsort( $payouts );

		// TWINT-Zahlungen im Zeitraum der Datei, die in keiner Abrechnungszeile vorkommen (noch nicht ausbezahlt).
		$open  = array();
		$dates = array_filter( array_column( $parsed['rows'], 'date' ) );
		if ( $dates ) {
			$in_file = array();
			foreach ( $payouts as $p ) {
				foreach ( $p['lines'] as $l ) {
					$in_file[ $l['order_id'] ] = true;
				}
			}
			$orders = wc_get_orders(
				array(
					'date_paid'      => strtotime( min( $dates ) . ' 00:00:00' ) . '...' . strtotime( max( $dates ) . ' 23:59:59' ),
					'limit'          => 500,
				)
			);
			foreach ( $orders as $o ) {
				if ( ! isset( $in_file[ $o->get_id() ] ) && in_array( $o->get_payment_method(), array( 'twint_regular', 'twint_express' ), true ) ) {
					$open[] = array(
						'order_id' => $o->get_id(),
						'number'   => $o->get_order_number(),
						'customer' => trim( $o->get_formatted_billing_full_name() ),
						'total'    => (float) $o->get_total(),
						'paid'     => $o->get_date_paid() ? $o->get_date_paid()->date_i18n( 'd.m.Y H:i' ) : '',
					);
				}
			}
		}

		return array(
			'payouts' => $payouts,
			'open'    => $open,
			'meta'    => $parsed['meta'],
		);
	}

	/**
	 * Merkt an den Bestellungen, mit welcher Überweisung sie ausbezahlt wurden.
	 *
	 * @param array $report Auswertung.
	 * @return int Anzahl markierter Bestellungen.
	 */
	public static function mark_orders( array $report ) {
		$count = 0;
		foreach ( $report['payouts'] as $p ) {
			foreach ( $p['lines'] as $l ) {
				if ( ! $l['order_id'] || $l['refund'] ) {
					continue;
				}
				$order = wc_get_order( $l['order_id'] );
				$value = sprintf( '%s|%s|%s', $p['date'], wc_format_decimal( $l['row']['amount'], 2 ), wc_format_decimal( $l['row']['fee'], 2 ) );
				if ( $order && $order->get_meta( self::META_PAYOUT ) !== $value ) {
					$order->update_meta_data( self::META_PAYOUT, $value );
					$order->add_order_note( sprintf( 'TWINT: ausbezahlt mit Sammelgutschrift vom %s (Kommission CHF %s).', wp_date( 'd.m.Y', strtotime( $p['date'] ) ), wc_format_decimal( $l['row']['fee'], 2 ) ) );
					$order->save();
					$count++;
				}
			}
		}
		return $count;
	}

	/* ------------------------------------------------------------------ */
	/* Admin                                                               */
	/* ------------------------------------------------------------------ */

	public static function handle_upload() {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( 'Keine Berechtigung.' );
		}
		check_admin_referer( 'bws_twint_upload' );
		$key = '';
		$msg = '';
		try {
			if ( empty( $_FILES['twint_csv']['tmp_name'] ) || ! is_uploaded_file( $_FILES['twint_csv']['tmp_name'] ) ) { // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
				throw new Exception( 'Bitte eine CSV-Datei auswählen.' );
			}
			$content = file_get_contents( $_FILES['twint_csv']['tmp_name'] ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput, WordPress.WP.AlternativeFunctions
			$report  = self::report( self::parse( $content ) );
			$report['file']   = sanitize_file_name( wp_unslash( $_FILES['twint_csv']['name'] ?? 'twint.csv' ) ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
			$report['marked'] = self::mark_orders( $report );
			$key              = wp_generate_password( 12, false );
			set_transient( self::TRANSIENT . $key, $report, DAY_IN_SECONDS );
		} catch ( Exception $e ) {
			$msg = $e->getMessage();
		}
		wp_safe_redirect( add_query_arg( array_filter( array( 'page' => self::PAGE, 'report' => $key, 'msg' => rawurlencode( $msg ) ) ), admin_url( 'admin.php' ) ) );
		exit;
	}

	/**
	 * Excel-taugliche CSV der Auswertung.
	 */
	public static function handle_export() {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( 'Keine Berechtigung.' );
		}
		check_admin_referer( 'bws_twint_export' );
		$key    = isset( $_GET['report'] ) ? sanitize_key( wp_unslash( $_GET['report'] ) ) : '';
		$report = get_transient( self::TRANSIENT . $key );
		if ( ! is_array( $report ) ) {
			wp_die( 'Auswertung abgelaufen – bitte die CSV erneut hochladen.' );
		}
		nocache_headers();
		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="twint-abrechnung-' . gmdate( 'Y-m-d' ) . '.csv"' );
		echo self::export_csv( $report ); // phpcs:ignore WordPress.Security.EscapeOutput
		exit;
	}

	/**
	 * @param array $report Auswertung.
	 * @return string
	 */
	public static function export_csv( array $report ) {
		$out = fopen( 'php://temp', 'w+' );
		fwrite( $out, "\xEF\xBB\xBF" ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		$put = function ( array $cells ) use ( $out ) {
			fputcsv( $out, $cells, ';', '"', '\\' );
		};
		$put( array( 'Überweisung', 'Bestellung', 'Kunde', 'bexio-Auftrag', 'Typ', 'Transaktion', 'Betrag', 'Kommission', 'Gutgeschrieben', 'Hinweis' ) );
		$fmt = function ( $n ) {
			return number_format( (float) $n, 2, '.', '' );
		};
		foreach ( $report['payouts'] as $p ) {
			$date = wp_date( 'd.m.Y', strtotime( $p['date'] ) );
			foreach ( $p['lines'] as $l ) {
				$put( array( $date, $l['number'], $l['customer'], $l['bexio'], $l['refund'] ? 'Rückerstattung' : 'Zahlung', wp_date( 'd.m.Y', strtotime( $l['row']['date'] ) ) . ' ' . $l['row']['time'], $fmt( $l['row']['amount'] ), $fmt( $l['row']['fee'] ), $fmt( $l['row']['credited'] ), implode( '; ', $l['issues'] ) ) );
			}
			$put( array( $date, 'TOTAL', '', '', '', '', $fmt( $p['amount'] ), $fmt( $p['fee'] ), $fmt( $p['credited'] ), '' ) );
			$put( array() );
		}
		rewind( $out );
		$csv = stream_get_contents( $out );
		fclose( $out ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		return $csv;
	}

	public static function render_page() {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			return;
		}
		$key    = isset( $_GET['report'] ) ? sanitize_key( wp_unslash( $_GET['report'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification
		$msg    = isset( $_GET['msg'] ) ? sanitize_text_field( rawurldecode( wp_unslash( $_GET['msg'] ) ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification
		$report = $key ? get_transient( self::TRANSIENT . $key ) : null;
		$money  = function ( $n ) {
			return number_format( (float) $n, 2, '.', "'" );
		};
		?>
		<div class="wrap">
			<h1>TWINT-Abrechnung</h1>
			<?php if ( $msg ) : ?>
				<div class="notice notice-error"><p><?php echo esc_html( $msg ); ?></p></div>
			<?php endif; ?>
			<p>Im TWINT-Portal die Abrechnung als CSV herunterladen und hier hochladen. Pro Sammelgutschrift siehst du, welche Bestellungen bezahlt bzw. erstattet wurden und wie viel Kommission abgezogen wurde. In bexio wird nichts gebucht.</p>
			<form method="post" enctype="multipart/form-data" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="bws_twint_upload">
				<?php wp_nonce_field( 'bws_twint_upload' ); ?>
				<input type="file" name="twint_csv" accept=".csv,text/csv" required>
				<button class="button button-primary">Auswerten</button>
			</form>

			<?php if ( is_array( $report ) ) : ?>
				<p>
					Datei: <strong><?php echo esc_html( $report['file'] ?? '' ); ?></strong>
					<?php if ( ! empty( $report['marked'] ) ) : ?>
						– <?php echo (int) $report['marked']; ?> Bestellung(en) mit Auszahlungsdatum vermerkt.
					<?php endif; ?>
					<a class="button" style="margin-left:12px" href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=bws_twint_export&report=' . $key ), 'bws_twint_export' ) ); ?>">Als Excel/CSV herunterladen</a>
				</p>
				<?php foreach ( $report['payouts'] as $p ) : ?>
					<h2>Überweisung vom <?php echo esc_html( wp_date( 'd.m.Y', strtotime( $p['date'] ) ) ); ?>: CHF <?php echo esc_html( $money( $p['credited'] ) ); ?></h2>
					<table class="widefat striped">
						<thead><tr><th>Bestellung</th><th>Kunde</th><th>bexio-Auftrag</th><th>Status</th><th>Transaktion</th><th style="text-align:right">Betrag</th><th style="text-align:right">Kommission</th><th style="text-align:right">Gutgeschrieben</th><th>Hinweis</th></tr></thead>
						<tbody>
						<?php foreach ( $p['lines'] as $l ) : ?>
							<tr<?php echo $l['issues'] ? ' style="background:#fde2e2"' : ''; ?>>
								<td><?php echo $l['order_id'] ? '<a href="' . esc_url( admin_url( 'post.php?post=' . $l['order_id'] . '&action=edit' ) ) . '">#' . esc_html( $l['number'] ) . '</a>' : esc_html( $l['row']['merchant_ref'] ); ?><?php echo $l['refund'] ? ' <em>(Rückerstattung)</em>' : ''; ?></td>
								<td><?php echo esc_html( $l['customer'] ); ?></td>
								<td><?php echo esc_html( $l['bexio'] ); ?></td>
								<td><?php echo esc_html( $l['status'] ); ?></td>
								<td><?php echo esc_html( wp_date( 'd.m.Y', strtotime( $l['row']['date'] ) ) . ' ' . $l['row']['time'] ); ?></td>
								<td style="text-align:right"><?php echo esc_html( $money( $l['row']['amount'] ) ); ?></td>
								<td style="text-align:right"><?php echo esc_html( $money( $l['row']['fee'] ) ); ?></td>
								<td style="text-align:right"><?php echo esc_html( $money( $l['row']['credited'] ) ); ?></td>
								<td><?php echo esc_html( implode( '; ', $l['issues'] ) ); ?></td>
							</tr>
						<?php endforeach; ?>
						</tbody>
						<tfoot><tr><th colspan="5">Total (<?php echo count( $p['lines'] ); ?> Zeilen)</th><th style="text-align:right"><?php echo esc_html( $money( $p['amount'] ) ); ?></th><th style="text-align:right"><?php echo esc_html( $money( $p['fee'] ) ); ?></th><th style="text-align:right"><?php echo esc_html( $money( $p['credited'] ) ); ?></th><th></th></tr></tfoot>
					</table>
				<?php endforeach; ?>
				<?php if ( $report['open'] ) : ?>
					<h2>TWINT-Zahlungen im gleichen Zeitraum, die (noch) in keiner Abrechnung stehen</h2>
					<table class="widefat striped">
						<thead><tr><th>Bestellung</th><th>Kunde</th><th>Bezahlt am</th><th style="text-align:right">Betrag</th></tr></thead>
						<tbody>
						<?php foreach ( $report['open'] as $o ) : ?>
							<tr><td><a href="<?php echo esc_url( admin_url( 'post.php?post=' . $o['order_id'] . '&action=edit' ) ); ?>">#<?php echo esc_html( $o['number'] ); ?></a></td><td><?php echo esc_html( $o['customer'] ); ?></td><td><?php echo esc_html( $o['paid'] ); ?></td><td style="text-align:right"><?php echo esc_html( $money( $o['total'] ) ); ?></td></tr>
						<?php endforeach; ?>
						</tbody>
					</table>
				<?php endif; ?>
			<?php endif; ?>
		</div>
		<?php
	}
}
