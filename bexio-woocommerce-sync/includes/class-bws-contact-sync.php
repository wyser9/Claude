<?php
defined( 'ABSPATH' ) || exit;

/**
 * Besteller (Rechnungsadresse) als bexio-Kontakt anlegen bzw. wiederfinden.
 *
 * Reihenfolge: gespeicherte Kontakt-ID am Kundenkonto -> Suche per E-Mail -> Suche über PLZ und Name
 * (bzw. Telefon) -> neu anlegen. So entstehen keine Doubletten, wenn ein bestehender bexio-Kontakt noch
 * keine E-Mail hat. Fehlende E-Mail/Telefonnummer werden beim gefundenen Kontakt ergänzt.
 * Firmenkunden (Feld "Firma" ausgefüllt) werden als Firma, alle anderen als Person angelegt.
 */
class BWS_Contact_Sync {

	const META_CONTACT_ID = '_bws_contact_id';

	/**
	 * Liefert die bexio Kontakt-ID für den Besteller einer Bestellung.
	 *
	 * @param WC_Order $order Bestellung.
	 * @return int
	 * @throws BWS_Exception Bei Fehlern.
	 */
	public static function ensure_contact( WC_Order $order ) {
		$client      = BWS_Client::instance();
		$customer_id = $order->get_customer_id();
		$payload     = self::build_payload( $order );

		$contact_id = $customer_id ? (int) get_user_meta( $customer_id, self::META_CONTACT_ID, true ) : 0;

		$match = null;
		if ( ! $contact_id && ! empty( $payload['mail'] ) ) {
			$contact_id = self::find_by_email( $payload['mail'] );
		}
		if ( ! $contact_id ) {
			$match = self::find_by_address( $payload );
			if ( $match ) {
				$contact_id = (int) $match['id'];
				bws_log( 'info', sprintf( 'Bestellung #%s: bestehender bexio-Kontakt %d über Name und PLZ gefunden – kein neuer Kontakt.', $order->get_order_number(), $contact_id ) );
				if ( ! BWS_Settings::enabled( 'update_contacts' ) ) {
					self::fill_missing( $contact_id, $match, $payload );
				}
			}
		}

		if ( $contact_id && BWS_Settings::enabled( 'update_contacts' ) ) {
			try {
				$client->post( '2.0/contact/' . $contact_id, $payload );
			} catch ( BWS_Exception $e ) {
				if ( 404 !== $e->getCode() ) {
					throw $e;
				}
				$contact_id = 0;
			}
		}

		if ( ! $contact_id ) {
			$result = $client->post( '2.0/contact', $payload );
			if ( empty( $result['id'] ) ) {
				throw new BWS_Exception( 'Unerwartete Antwort von bexio beim Anlegen des Kontakts.' );
			}
			$contact_id = (int) $result['id'];
			bws_log( 'info', sprintf( 'Bestellung #%s: bexio-Kontakt %d angelegt (%s)', $order->get_order_number(), $contact_id, $payload['mail'] ?? '' ) );
		}

		if ( $customer_id ) {
			update_user_meta( $customer_id, self::META_CONTACT_ID, $contact_id );
		}

		return $contact_id;
	}

	/**
	 * Sucht einen Kontakt per E-Mail (Haupt- oder Zweitadresse).
	 *
	 * @param string $email E-Mail.
	 * @return int|null
	 */
	public static function find_by_email( $email ) {
		$email = strtolower( trim( $email ) );
		foreach ( array( 'mail', 'mail_second' ) as $field ) {
			foreach ( BWS_Client::instance()->search( '2.0/contact/search', $field, $email ) as $hit ) {
				if ( isset( $hit[ $field ] ) && strtolower( trim( $hit[ $field ] ) ) === $email ) {
					return (int) $hit['id'];
				}
			}
		}
		return null;
	}

	/**
	 * Sucht einen Kontakt mit gleicher PLZ und gleichem Namen (Umlaute, Gross-/Kleinschreibung und
	 * vertauschte Vor-/Nachnamen egal) oder gleicher Telefonnummer.
	 *
	 * @param array $payload Kontaktdaten der Bestellung.
	 * @return array|null bexio-Kontakt.
	 */
	public static function find_by_address( array $payload ) {
		if ( empty( $payload['postcode'] ) ) {
			return null;
		}
		$hits = BWS_Client::instance()->post(
			'2.0/contact/search',
			array(
				array(
					'field'    => 'postcode',
					'value'    => (string) $payload['postcode'],
					'criteria' => '=',
				),
			),
			array( 'limit' => 500 )
		);
		$phone = BWS_Util::normalize_phone( $payload['phone_fixed'] ?? '' );
		$found = array();
		foreach ( is_array( $hits ) ? $hits : array() as $hit ) {
			if ( self::same_name( $hit, $payload ) || ( '' !== $phone && in_array( $phone, array( BWS_Util::normalize_phone( $hit['phone_fixed'] ?? '' ), BWS_Util::normalize_phone( $hit['phone_mobile'] ?? '' ) ), true ) && self::same_name( $hit, $payload, true ) ) ) {
				$found[ (int) $hit['id'] ] = $hit;
			}
		}
		if ( ! $found ) {
			return null;
		}
		ksort( $found ); // Bei mehreren Treffern den ältesten Kontakt nehmen.
		return reset( $found );
	}

	/**
	 * Gleicher Name? Personen: Vor- und Nachname (auch vertauscht). Firmen: Firmenname.
	 *
	 * @param array $hit       bexio-Kontakt.
	 * @param array $payload   Kontaktdaten der Bestellung.
	 * @param bool  $last_only Nur Nach- bzw. Firmenname vergleichen (bei gleicher Telefonnummer).
	 * @return bool
	 */
	private static function same_name( array $hit, array $payload, $last_only = false ) {
		$n = array( 'BWS_Util', 'normalize_name' );
		$a1 = $n( $hit['name_1'] ?? '' );
		$a2 = $n( $hit['name_2'] ?? '' );
		$b1 = $n( $payload['name_1'] ?? '' );
		$b2 = $n( $payload['name_2'] ?? '' );
		if ( '' === $b1 || (int) ( $hit['contact_type_id'] ?? 0 ) !== (int) $payload['contact_type_id'] ) {
			return false;
		}
		if ( 1 === (int) $payload['contact_type_id'] || $last_only ) {
			return $a1 === $b1 || ( ! $last_only ? false : $a2 === $b1 );
		}
		return ( $a1 === $b1 && $a2 === $b2 ) || ( $a1 === $b2 && $a2 === $b1 );
	}

	/**
	 * Ergänzt beim gefundenen Kontakt nur leere Felder (E-Mail, Telefon). Bestehende Werte bleiben.
	 *
	 * @param int   $contact_id bexio-Kontakt-ID.
	 * @param array $existing   bexio-Kontakt.
	 * @param array $payload    Kontaktdaten der Bestellung.
	 */
	private static function fill_missing( $contact_id, array $existing, array $payload ) {
		$fields = array();
		if ( ! empty( $payload['mail'] ) && empty( $existing['mail'] ) ) {
			$fields['mail'] = $payload['mail'];
		}
		if ( ! empty( $payload['phone_fixed'] ) && empty( $existing['phone_fixed'] ) && empty( $existing['phone_mobile'] ) ) {
			$key            = BWS_Util::is_mobile_phone( $payload['phone_fixed'] ) ? 'phone_mobile' : 'phone_fixed';
			$fields[ $key ] = $payload['phone_fixed'];
		}
		if ( ! $fields ) {
			return;
		}
		// bexio verlangt beim Bearbeiten die Grunddaten des Kontakts.
		$base = array_intersect_key( $existing, array_flip( array( 'contact_type_id', 'name_1', 'name_2', 'user_id', 'owner_id' ) ) );
		try {
			BWS_Client::instance()->post( '2.0/contact/' . (int) $contact_id, array_filter( $base, array( __CLASS__, 'not_empty' ) ) + $fields );
			bws_log( 'info', sprintf( 'bexio-Kontakt %d ergänzt: %s.', $contact_id, implode( ', ', array_keys( $fields ) ) ) );
		} catch ( BWS_Exception $e ) {
			bws_log( 'warning', sprintf( 'bexio-Kontakt %d konnte nicht ergänzt werden: %s', $contact_id, $e->getMessage() ) );
		}
	}

	/**
	 * bexio-Kontaktdaten aus der Rechnungsadresse.
	 *
	 * @param WC_Order $order Bestellung.
	 * @return array
	 */
	public static function build_payload( WC_Order $order ) {
		$user_id = BWS_Lookup::user_id();
		$company = trim( $order->get_billing_company() );
		$first   = trim( $order->get_billing_first_name() );
		$last    = trim( $order->get_billing_last_name() );

		list( $street, $number ) = BWS_Util::split_street( $order->get_billing_address_1() );
		$addition                = trim( $order->get_billing_address_2() );
		// bexio verlangt eine Strasse, sobald Hausnummer oder Adresszusatz gesetzt sind.
		if ( '' === $street ) {
			$street   = $addition;
			$addition = '';
			if ( '' === $street ) {
				$number = '';
			}
		}

		if ( '' !== $company ) {
			$payload = array(
				'contact_type_id' => 1, // Firma.
				'name_1'          => BWS_Util::truncate( $company, 255 ),
				'name_2'          => BWS_Util::truncate( trim( $first . ' ' . $last ), 255 ),
			);
		} else {
			$payload = array(
				'contact_type_id' => 2, // Person.
				'name_1'          => BWS_Util::truncate( '' !== $last ? $last : $first, 255 ),
				'name_2'          => BWS_Util::truncate( '' !== $last ? $first : '', 255 ),
			);
		}

		if ( '' === $payload['name_1'] ) {
			$payload['name_1'] = $order->get_billing_email() ? $order->get_billing_email() : 'WooCommerce Kunde';
		}

		$payload += array(
			'street_name'      => BWS_Util::truncate( $street, 255 ),
			'house_number'     => BWS_Util::truncate( $number, 255 ),
			'address_addition' => BWS_Util::truncate( $addition, 255 ),
			'postcode'         => BWS_Util::truncate( $order->get_billing_postcode(), 255 ),
			'city'             => BWS_Util::truncate( $order->get_billing_city(), 255 ),
			'mail'             => $order->get_billing_email(),
			'phone_fixed'      => BWS_Util::truncate( $order->get_billing_phone(), 255 ),
			'user_id'          => $user_id,
			'owner_id'         => $user_id,
		);

		$country_id = BWS_Lookup::country_id( $order->get_billing_country() );
		if ( $country_id ) {
			$payload['country_id'] = $country_id;
		}
		if ( BWS_Settings::id( 'language_id' ) ) {
			$payload['language_id'] = BWS_Settings::id( 'language_id' );
		}
		if ( BWS_Settings::id( 'contact_group_id' ) ) {
			$payload['contact_group_ids'] = (string) BWS_Settings::id( 'contact_group_id' );
		}

		/**
		 * Kontaktdaten vor dem Senden an bexio anpassen.
		 *
		 * @param array    $payload Daten für POST /2.0/contact.
		 * @param WC_Order $order   Bestellung.
		 */
		return apply_filters( 'bws_contact_payload', array_filter( $payload, array( __CLASS__, 'not_empty' ) ), $order );
	}

	private static function not_empty( $value ) {
		return null !== $value && '' !== $value;
	}
}
