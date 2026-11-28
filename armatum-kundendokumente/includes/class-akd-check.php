<?php
defined( 'ABSPATH' ) || exit;

/**
 * Prüfung je Bestellung und Erinnerungs-Mail an den Kunden.
 */
class AKD_Check {

	const META_RESULT   = '_akd_result';
	const META_REMINDED = '_akd_reminded';
	const HOOK          = 'akd_check_order';
	const TAXONOMY      = 'pa_erwerbsbedingungen';

	public static function init() {
		add_action( self::HOOK, array( __CLASS__, 'run_job' ) );
		add_action( 'bws_order_synced', array( __CLASS__, 'queue' ), 20, 1 );
		add_action( 'woocommerce_order_status_processing', array( __CLASS__, 'queue' ), 30, 1 );
		add_action( 'woocommerce_order_status_on-hold', array( __CLASS__, 'queue' ), 30, 1 );
	}

	/**
	 * Prüfung für eine Bestellung einplanen (ein Lauf je Bestellung).
	 *
	 * @param int|WC_Order $order Bestellung.
	 */
	public static function queue( $order ) {
		$id = $order instanceof WC_Order ? $order->get_id() : (int) $order;
		if ( $id && ! wp_next_scheduled( self::HOOK, array( $id ) ) ) {
			wp_schedule_single_event( time() + 30, self::HOOK, array( $id ) );
		}
	}

	public static function run_job( $order_id ) {
		$order = wc_get_order( $order_id );
		if ( $order ) {
			self::run( $order );
		}
	}

	/**
	 * Braucht die Bestellung Unterlagen? (mind. ein Artikel mit Erwerbsbedingung)
	 *
	 * @param WC_Order $order Bestellung.
	 * @return string[] Namen der betroffenen Artikel.
	 */
	public static function relevant_items( WC_Order $order ) {
		$tax   = apply_filters( 'akd_taxonomy', self::TAXONOMY );
		$names = array();
		foreach ( $order->get_items() as $item ) {
			if ( ! is_callable( array( $item, 'get_product_id' ) ) ) {
				continue;
			}
			foreach ( array_unique( array_filter( array( (int) $item->get_product_id(), (int) $item->get_variation_id() ) ) ) as $pid ) {
				$terms = get_the_terms( $pid, $tax );
				if ( $terms && ! is_wp_error( $terms ) ) {
					$names[] = $item->get_name();
					break;
				}
			}
		}
		return $names;
	}

	/** bexio-Kontakt der Bestellung (vom bexio-Sync gesetzt). */
	public static function contact_id( WC_Order $order ) {
		$id = (int) $order->get_meta( '_bws_contact_id' );
		if ( ! $id && $order->get_customer_id() ) {
			$id = (int) get_user_meta( $order->get_customer_id(), '_bws_contact_id', true );
		}
		return $id;
	}

	/**
	 * Prüft die Bestellung und speichert das Ergebnis.
	 *
	 * @param WC_Order $order Bestellung.
	 * @param bool     $force Auch ohne Erwerbsbedingung prüfen.
	 * @return array|null Ergebnis oder null, wenn keine Prüfung nötig.
	 */
	public static function run( WC_Order $order, $force = false ) {
		$items = self::relevant_items( $order );
		if ( ! $items && ! $force ) {
			return null;
		}
		$contact = self::contact_id( $order );
		$old     = $order->get_meta( self::META_RESULT );
		$result  = array(
			'time'    => time(),
			'contact' => $contact,
			'items'   => $items,
		);

		if ( ! $contact ) {
			$result += array(
				'status'  => 'warten',
				'note'    => 'Noch kein bexio-Kontakt (Bestellung noch nicht an bexio übertragen).',
				'docs'    => array(),
				'missing' => array(),
			);
		} else {
			$run = get_option( AKD_Index::OPTION_RUN );
			if ( ! is_array( $run ) || empty( $run['time'] ) || time() - $run['time'] > 10 * MINUTE_IN_SECONDS ) {
				AKD_Index::refresh( 40 );
			}
			$files   = AKD_Index::files_for( $contact );
			$result += AKD_Docs::evaluate( $files, time() );
			$result['files'] = count( $files );
			if ( ! AKD_Index::complete() ) {
				$result['note'] = 'Das Dateiverzeichnis ist noch im Aufbau, Ergebnis kann unvollständig sein (wird stündlich ergänzt).';
			}
		}

		$order->update_meta_data( self::META_RESULT, $result );
		$order->save();

		if ( 'warten' !== $result['status'] && ( ! is_array( $old ) || ( $old['status'] ?? '' ) !== $result['status'] ) ) {
			$order->add_order_note( self::note_text( $result ) );
		}
		return $result;
	}

	/** Kurztext für die Bestellnotiz. */
	public static function note_text( array $r ) {
		$head = array(
			'ok'      => 'Kundendokumente vollständig',
			'pruefen' => 'Kundendokumente bitte von Hand prüfen',
			'fehlt'   => 'Kundendokumente fehlen',
			'warten'  => 'Kundendokumente noch nicht geprüft',
		);
		$text = ( $head[ $r['status'] ] ?? $r['status'] ) . '.';
		if ( ! empty( $r['docs'] ) ) {
			$labels = AKD_Docs::labels();
			$parts  = array();
			foreach ( array( AKD_Docs::AUSWEIS, AKD_Docs::WES, AKD_Docs::STRAF ) as $t ) {
				$parts[] = $labels[ $t ] . ': ' . $r['docs'][ $t ]['note'];
			}
			$text .= ' ' . implode( '; ', $parts ) . '.';
		}
		if ( ! empty( $r['note'] ) ) {
			$text .= ' ' . $r['note'];
		}
		return $text;
	}

	/** Vorschlag für die Erinnerungs-Mail. */
	public static function reminder_text( WC_Order $order, array $r ) {
		$name    = trim( $order->get_billing_first_name() );
		$missing = $r['missing'] ?? array();
		if ( ! $missing ) {
			$missing = array( 'eine Kopie deines Ausweises', 'einen Waffenerwerbsschein (nicht älter als 2 Jahre) oder einen Strafregisterauszug (nicht älter als 3 Monate)' );
		}
		$lines   = array();
		$lines[] = 'Hallo' . ( $name ? ' ' . $name : '' );
		$lines[] = '';
		$lines[] = sprintf( 'Vielen Dank für deine Bestellung %s bei Armatum.', $order->get_order_number() );
		$lines[] = 'Damit wir sie ausliefern dürfen, brauchen wir von dir noch:';
		foreach ( $missing as $m ) {
			$lines[] = '- ' . $m;
		}
		$lines[] = '';
		$lines[] = 'Du kannst die Unterlagen einfach als Foto oder PDF auf diese E-Mail zurückschicken. Sobald sie bei uns sind, geht deine Bestellung raus.';
		$lines[] = '';
		$lines[] = 'Freundliche Grüsse';
		$lines[] = 'Armatum GmbH';
		return implode( "\n", $lines );
	}

	/**
	 * Erinnerungs-Mail über den WooCommerce-Mailer senden (Absender = Shop-Absender).
	 *
	 * @param WC_Order $order Bestellung.
	 * @param string   $text  Text.
	 * @return bool
	 */
	public static function send_reminder( WC_Order $order, $text ) {
		$to = $order->get_billing_email();
		if ( ! $to || ! is_email( $to ) ) {
			return false;
		}
		$subject = sprintf( 'Bestellung %s: Unterlagen für den Waffen- und Munitionskauf', $order->get_order_number() );
		$mailer  = WC()->mailer();
		$body    = $mailer->wrap_message( 'Unterlagen für deine Bestellung', wpautop( esc_html( $text ) ) );
		$sent    = $mailer->send( $to, $subject, $body, array( 'Content-Type: text/html; charset=UTF-8' ) );
		if ( $sent ) {
			$order->update_meta_data( self::META_REMINDED, time() );
			$order->save();
			$order->add_order_note( 'Erinnerung Kundendokumente an ' . $to . ' gesendet:' . "\n" . $text );
		}
		return (bool) $sent;
	}
}
