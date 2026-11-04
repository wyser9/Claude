<?php
/**
 * «Passt etwas nicht? Melden»: Kunden melden Fehler im Konfigurator. Die aktuelle Auswahl wird mitgeschickt.
 * Meldungen gehen per E-Mail an den Shop und erscheinen unter WooCommerce → Konfigurator.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class AK_Report {

	const OPTION = 'ak_reports';
	const MAX    = 200;

	public static function init() {
		add_action( 'wc_ajax_ak_report', array( __CLASS__, 'ajax' ) );
		add_action( 'admin_post_ak_clear_reports', array( __CLASS__, 'clear' ) );
	}

	public static function all() {
		$list = get_option( self::OPTION, array() );
		return is_array( $list ) ? $list : array();
	}

	private static function ids( $raw ) {
		$out = array();
		foreach ( (array) $raw as $k => $v ) {
			$v = absint( $v );
			if ( $v ) {
				$out[ sanitize_key( $k ) ] = $v;
			}
		}
		return $out;
	}

	public static function ajax() {
		if ( ! AK_Data::visible_for_current_user() ) {
			wp_send_json_error( array( 'message' => 'Der Konfigurator ist noch nicht freigeschaltet.' ), 403 );
		}
		$p = wp_unslash( $_POST ); // phpcs:ignore WordPress.Security.NonceVerification
		// Honeypot: Bots füllen das versteckte Feld aus.
		if ( ! empty( $p['website'] ) ) {
			wp_send_json_success( array( 'message' => 'Danke für die Meldung.' ) );
		}
		$text = trim( sanitize_textarea_field( (string) ( $p['text'] ?? '' ) ) );
		if ( mb_strlen( $text ) < 5 ) {
			wp_send_json_error( array( 'message' => 'Bitte kurz beschreiben, was nicht stimmt.' ), 400 );
		}
		$ip    = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '';
		$limit = 'ak_rep_' . md5( $ip );
		$count = (int) get_transient( $limit );
		if ( $count >= 5 ) {
			wp_send_json_error( array( 'message' => 'Zu viele Meldungen. Bitte später nochmals versuchen.' ), 429 );
		}
		set_transient( $limit, $count + 1, HOUR_IN_SECONDS );

		$weapon = absint( $p['weapon'] ?? 0 );
		$items  = array_merge(
			array(
				'optik'   => absint( $p['optic'] ?? 0 ),
				'montage' => absint( $p['mount'] ?? 0 ),
			),
			self::ids( $p['parts'] ?? array() ),
			array_combine(
				array_map(
					function ( $k ) {
						return 'adapter_' . $k;
					},
					array_keys( self::ids( $p['adapters'] ?? array() ) )
				),
				array_values( self::ids( $p['adapters'] ?? array() ) )
			) ?: array()
		);
		$items  = array_filter( $items );
		$report = array(
			'time'   => current_time( 'mysql' ),
			'weapon' => $weapon,
			'items'  => $items,
			'text'   => mb_substr( $text, 0, 2000 ),
			'email'  => sanitize_email( (string) ( $p['email'] ?? '' ) ),
			'url'    => esc_url_raw( (string) ( $p['url'] ?? '' ) ),
			'user'   => get_current_user_id(),
		);
		$list = self::all();
		array_unshift( $list, $report );
		update_option( self::OPTION, array_slice( $list, 0, self::MAX ), false );

		$to    = get_option( 'woocommerce_email_from_address' ) ?: get_option( 'admin_email' );
		$lines = array( 'Neue Meldung aus dem Konfigurator:', '', $report['text'], '', 'Waffe: ' . self::name( $weapon ) );
		foreach ( $items as $role => $id ) {
			$lines[] = ucfirst( str_replace( '_', ' ', $role ) ) . ': ' . self::name( $id );
		}
		$lines[] = '';
		$lines[] = 'E-Mail Kunde: ' . ( $report['email'] ? $report['email'] : '–' );
		$lines[] = 'Seite: ' . $report['url'];
		$lines[] = 'Alle Meldungen: ' . admin_url( 'admin.php?page=' . AK_Settings::SLUG );
		$headers = $report['email'] ? array( 'Reply-To: ' . $report['email'] ) : array();
		wp_mail( $to, 'Konfigurator: Meldung zu ' . get_the_title( $weapon ), implode( "\n", $lines ), $headers );

		wp_send_json_success( array( 'message' => 'Danke für die Meldung. Wir schauen sie uns an.' ) );
	}

	private static function name( $id ) {
		return $id ? get_the_title( $id ) . ' (#' . $id . ')' : '–';
	}

	public static function clear() {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( 'Keine Berechtigung.' );
		}
		check_admin_referer( 'ak_clear_reports' );
		delete_option( self::OPTION );
		wp_safe_redirect( add_query_arg( array( 'page' => AK_Settings::SLUG, 'ak_msg' => 'cleared' ), admin_url( 'admin.php' ) ) );
		exit;
	}

	/** Liste für die Einstellungsseite. */
	public static function render_list() {
		$list = self::all();
		echo '<hr><h2 id="meldungen">Meldungen von Kunden (' . count( $list ) . ')</h2>';
		if ( ! $list ) {
			echo '<p>Noch keine Meldungen.</p>';
			return;
		}
		echo '<table class="widefat striped"><thead><tr><th>Zeit</th><th>Auswahl</th><th>Meldung</th><th>E-Mail</th></tr></thead><tbody>';
		foreach ( $list as $r ) {
			$links = array();
			foreach ( array_merge( array( 'waffe' => $r['weapon'] ), (array) $r['items'] ) as $id ) {
				if ( $id ) {
					$edit    = get_edit_post_link( AK_Data::parent_of( $id ) ? AK_Data::parent_of( $id ) : $id );
					$links[] = '<a href="' . esc_url( (string) $edit ) . '">' . esc_html( get_the_title( $id ) ) . '</a>';
				}
			}
			echo '<tr><td>' . esc_html( $r['time'] ) . '</td><td>' . implode( '<br>', $links ) . '</td><td>' . nl2br( esc_html( $r['text'] ) ) . '</td><td>' . esc_html( $r['email'] ) . '</td></tr>'; // phpcs:ignore WordPress.Security.EscapeOutput
		}
		echo '</tbody></table>';
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '"><input type="hidden" name="action" value="ak_clear_reports">';
		wp_nonce_field( 'ak_clear_reports' );
		submit_button( 'Alle Meldungen löschen', 'delete', 'submit', false );
		echo '</form>';
	}
}
