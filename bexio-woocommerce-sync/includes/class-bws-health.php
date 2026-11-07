<?php
defined( 'ABSPATH' ) || exit;

/**
 * Tägliche Warn-Mail (07:50), wenn etwas nicht läuft wie es soll:
 *  - fehlgeschlagene Hintergrundaufgaben (Gruppe "bexio-sync") seit der letzten Prüfung
 *  - Lagerabgleich länger als 36 Stunden nicht gelaufen oder mit Fehler beendet
 *  - Statusabgleich (Bezahlt/Erledigt) länger als 3 Stunden nicht gelaufen
 *  - bezahlte bzw. wartende Bestellungen der letzten 7 Tage ohne bexio-Auftrag
 *  - Produkte, deren Übertragung nach bexio fehlschlägt
 *
 * Ohne Befund wird keine Mail verschickt.
 */
class BWS_Health {

	const HOOK       = 'bws_health_check';
	const OPTION     = 'bws_health_last';
	const CHECK_TIME = '07:50';

	public static function init() {
		add_action( self::HOOK, array( __CLASS__, 'run' ) );
		add_action( 'action_scheduler_init', array( __CLASS__, 'ensure_schedule' ) );
	}

	public static function active() {
		return BWS_Settings::enabled( 'health_mail' ) && BWS_Settings::get( 'api_token' );
	}

	/**
	 * Plant die tägliche Prüfung ein bzw. entfernt sie.
	 */
	public static function ensure_schedule() {
		if ( ! function_exists( 'as_schedule_recurring_action' ) ) {
			return;
		}
		$scheduled = as_next_scheduled_action( self::HOOK, array(), BWS_AS_GROUP );
		if ( self::active() && ! $scheduled ) {
			list( $h, $m ) = array_map( 'intval', explode( ':', self::CHECK_TIME ) );
			$next          = new DateTime( 'now', wp_timezone() );
			$next->setTime( $h, $m, 0 );
			if ( $next->getTimestamp() <= time() ) {
				$next->modify( '+1 day' );
			}
			as_schedule_recurring_action( $next->getTimestamp(), DAY_IN_SECONDS, self::HOOK, array(), BWS_AS_GROUP );
		} elseif ( ! self::active() && $scheduled ) {
			as_unschedule_all_actions( self::HOOK, array(), BWS_AS_GROUP );
		}
	}

	/**
	 * Prüft und schickt bei Befund eine Mail.
	 *
	 * @return string[] Befunde.
	 */
	public static function run() {
		$last     = get_option( self::OPTION );
		$since    = is_array( $last ) && ! empty( $last['time'] ) ? (int) $last['time'] : time() - DAY_IN_SECONDS;
		$findings = self::findings( $since );

		$sent = false;
		if ( $findings ) {
			$sent = wp_mail(
				BWS_Order_Events::notify_email(),
				sprintf( '[%s] bexio-Abgleich: %d Hinweis(e)', wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES ), count( $findings ) ),
				"Die tägliche Prüfung des bexio-Abgleichs hat Folgendes gefunden:\n\n- " . implode( "\n\n- ", $findings ) . "\n\nDetails: WooCommerce > Status > Geplante Aktionen (Gruppe bexio-sync) und WooCommerce > Status > Logs (Quelle bexio-sync).\nEinstellungen: " . admin_url( 'admin.php?page=' . BWS_Admin::PAGE ) . "\n"
			);
		}
		update_option(
			self::OPTION,
			array(
				'time'     => time(),
				'findings' => $findings,
				'sent'     => $sent,
			),
			false
		);
		if ( $findings ) {
			bws_log( $sent ? 'warning' : 'error', sprintf( 'Tägliche Prüfung: %d Hinweis(e)%s.', count( $findings ), $sent ? ', E-Mail an ' . BWS_Order_Events::notify_email() : ', E-Mail konnte nicht gesendet werden' ) );
		}
		return $findings;
	}

	/**
	 * Alle Befunde.
	 *
	 * @param int $since Fehlgeschlagene Aufgaben ab diesem Zeitpunkt.
	 * @return string[]
	 */
	public static function findings( $since ) {
		$findings = array();

		// 1. Fehlgeschlagene Hintergrundaufgaben.
		foreach ( self::failed_actions( $since ) as $hook => $info ) {
			$findings[] = sprintf( '%d× fehlgeschlagen: %s. Letzter Fehler: %s', $info['count'], self::hook_label( $hook ), $info['message'] );
		}

		// 2. Lagerabgleich.
		if ( 'from_bexio' === BWS_Settings::stock_mode() ) {
			$stock = get_option( BWS_Stock_Import::OPTION_LAST );
			if ( ! is_array( $stock ) || empty( $stock['time'] ) ) {
				$findings[] = 'Lagerabgleich: noch nie erfolgreich gelaufen.';
			} elseif ( ! empty( $stock['error'] ) ) {
				$findings[] = sprintf( 'Lagerabgleich vom %s mit Fehler beendet: %s', wp_date( 'd.m.Y H:i', $stock['time'] ), $stock['error'] );
			} elseif ( time() - (int) $stock['time'] > 36 * HOUR_IN_SECONDS ) {
				$findings[] = sprintf( 'Lagerabgleich: letzter erfolgreicher Lauf am %s – seither nicht mehr abgeschlossen. Der Lagerstatus im Shop ist veraltet.', wp_date( 'd.m.Y H:i', $stock['time'] ) );
			} elseif ( ! empty( $stock['errors'] ) ) {
				$findings[] = sprintf( 'Lagerabgleich: %d Produkt(e) konnten nicht aktualisiert werden (siehe Log).', $stock['errors'] );
			}
		}

		// 3. Statusabgleich.
		if ( BWS_Status_Import::active() ) {
			$status = get_option( BWS_Status_Import::OPTION_LAST );
			if ( is_array( $status ) && ! empty( $status['error'] ) ) {
				$findings[] = sprintf( 'Statusabgleich (Bezahlt/Erledigt) vom %s mit Fehler: %s', wp_date( 'd.m.Y H:i', $status['time'] ), $status['error'] );
			} elseif ( is_array( $status ) && ! empty( $status['time'] ) && time() - (int) $status['time'] > 3 * HOUR_IN_SECONDS ) {
				$findings[] = sprintf( 'Statusabgleich (Bezahlt/Erledigt): letzter Lauf am %s – läuft nicht mehr stündlich.', wp_date( 'd.m.Y H:i', $status['time'] ) );
			}
		}

		// 4. Bestellungen ohne bexio-Auftrag.
		if ( BWS_Settings::enabled( 'sync_orders' ) ) {
			$missing = self::orders_without_bexio();
			if ( $missing ) {
				$findings[] = sprintf( '%d Bestellung(en) der letzten 7 Tage ohne bexio-Auftrag: %s', count( $missing ), implode( ', ', $missing ) );
			}
		}

		// 5. Produkte mit Übertragungsfehler.
		$products = self::products_with_errors();
		if ( $products['count'] ) {
			$findings[] = sprintf( '%d Produkt(e) lassen sich nicht nach bexio übertragen, z. B. %s', $products['count'], implode( '; ', $products['examples'] ) );
		}

		return $findings;
	}

	/**
	 * Fehlgeschlagene Aufgaben seit $since, je Hook mit Anzahl und letzter Fehlermeldung.
	 *
	 * @param int $since Zeitpunkt.
	 * @return array<string,array{count:int,message:string}>
	 */
	public static function failed_actions( $since ) {
		if ( ! function_exists( 'as_get_scheduled_actions' ) || ! class_exists( 'ActionScheduler' ) ) {
			return array();
		}
		$ids = as_get_scheduled_actions(
			array(
				'group'        => BWS_AS_GROUP,
				'status'       => ActionScheduler_Store::STATUS_FAILED,
				'date'         => gmdate( 'Y-m-d H:i:s', $since ),
				'date_compare' => '>=',
				'per_page'     => 200,
				'orderby'      => 'date',
				'order'        => 'DESC',
			),
			'ids'
		);
		$out = array();
		foreach ( (array) $ids as $id ) {
			$action = ActionScheduler::store()->fetch_action( $id );
			$hook   = $action ? $action->get_hook() : '?';
			if ( ! isset( $out[ $hook ] ) ) {
				$message = '';
				foreach ( array_reverse( ActionScheduler::logger()->get_logs( $id ) ) as $entry ) {
					$text = $entry->get_message();
					if ( 0 !== strpos( $text, 'action ' ) && false === strpos( $text, 'gestartet' ) && false === strpos( $text, 'erstellt' ) && false === strpos( $text, 'started' ) && false === strpos( $text, 'created' ) ) {
						$message = $text;
						break;
					}
				}
				$out[ $hook ] = array(
					'count'   => 0,
					'message' => '' !== $message ? mb_substr( $message, 0, 300 ) : 'unbekannt',
				);
			}
			$out[ $hook ]['count']++;
		}
		return $out;
	}

	private static function hook_label( $hook ) {
		$labels = array(
			BWS_Stock_Import::HOOK      => 'Lagerabgleich (Start)',
			BWS_Stock_Import::HOOK_STEP => 'Lagerabgleich (Etappe)',
			BWS_Status_Import::HOOK     => 'Statusabgleich Bezahlt/Erledigt',
			'bws_sync_order'            => 'Bestellung nach bexio',
			'bws_sync_product'          => 'Artikel nach bexio',
			'bws_bulk_sync_products'    => 'Alle Artikel übertragen',
			BWS_Backfill::HOOK_SCAN     => 'Nachrüsten (Lesen)',
			BWS_Backfill::HOOK_CHUNK    => 'Nachrüsten (Schreiben)',
			BWS_Order_Events::HOOK_COMMENT  => 'Kommentar im bexio-Auftrag',
			BWS_Order_Events::HOOK_REMINDER => 'Zahlungshinweis',
		);
		return $labels[ $hook ] ?? $hook;
	}

	/**
	 * Bestellnummern (bezahlt/wartend, letzte 7 Tage, älter als 1 Stunde) ohne bexio-Auftrag.
	 *
	 * @return string[]
	 */
	public static function orders_without_bexio() {
		$statuses = array_values( array_intersect( (array) BWS_Settings::get( 'order_statuses' ), array( 'processing', 'on-hold', 'completed' ) ) );
		if ( ! $statuses ) {
			return array();
		}
		$orders = wc_get_orders(
			array(
				'status'       => $statuses,
				'date_created' => ( time() - 7 * DAY_IN_SECONDS ) . '...' . ( time() - HOUR_IN_SECONDS ),
				'limit'        => 100,
			)
		);
		$out = array();
		foreach ( $orders as $order ) {
			if ( $order->get_meta( BWS_Order_Sync::META_ORDER_ID ) || BWS_Order_Sync::is_placeholder( $order ) ) {
				continue;
			}
			$error = (string) $order->get_meta( BWS_Order_Sync::META_ERROR );
			$out[] = '#' . $order->get_order_number() . ( '' !== $error ? ' (' . mb_substr( $error, 0, 120 ) . ')' : '' );
		}
		return $out;
	}

	/**
	 * Veröffentlichte Produkte mit gespeichertem Übertragungsfehler.
	 *
	 * @return array{count:int,examples:string[]}
	 */
	public static function products_with_errors() {
		global $wpdb;
		$rows = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare(
				"SELECT p.ID, p.post_title, pm.meta_value FROM {$wpdb->postmeta} pm JOIN {$wpdb->posts} p ON p.ID = pm.post_id
				 WHERE pm.meta_key = %s AND p.post_status = 'publish' AND p.post_type IN ('product','product_variation') ORDER BY p.ID DESC LIMIT 200",
				BWS_Product_Sync::META_ERROR
			)
		);
		$examples = array();
		foreach ( array_slice( (array) $rows, 0, 3 ) as $row ) {
			$examples[] = sprintf( '#%d %s: %s', $row->ID, $row->post_title, mb_substr( $row->meta_value, 0, 120 ) );
		}
		return array(
			'count'    => count( (array) $rows ),
			'examples' => $examples,
		);
	}
}
