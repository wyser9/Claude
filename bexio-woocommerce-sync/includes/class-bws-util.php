<?php
defined( 'ABSPATH' ) || exit;

/**
 * Reine Hilfsfunktionen ohne WordPress-Abhängigkeiten (unit-testbar).
 */
class BWS_Util {

	/**
	 * Zerlegt eine Adresszeile in Strasse und Hausnummer.
	 * "Bahnhofstrasse 12a" => ["Bahnhofstrasse", "12a"], "12 Rue du Lac" => ["Rue du Lac", "12"].
	 *
	 * @param string $line Adresszeile.
	 * @return array{0:string,1:string}
	 */
	public static function split_street( $line ) {
		$line = trim( preg_replace( '/\s+/', ' ', (string) $line ) );
		if ( '' === $line ) {
			return array( '', '' );
		}
		// Hausnummer am Ende (CH/DE/AT-Format).
		if ( preg_match( '/^(.+?)\s+(\d+\s?[a-zA-Z]?(?:\s?[-\/]\s?\d+\s?[a-zA-Z]?)?)$/u', $line, $m ) ) {
			return array( trim( $m[1], ' ,' ), str_replace( ' ', '', $m[2] ) );
		}
		// Hausnummer am Anfang (FR/IT-Format).
		if ( preg_match( '/^(\d+\s?[a-zA-Z]?)[\s,]+(.+)$/u', $line, $m ) ) {
			return array( trim( $m[2] ), str_replace( ' ', '', $m[1] ) );
		}
		return array( $line, '' );
	}

	/**
	 * Steuersatz in Prozent aus Betrag und Steuer berechnen (gerundet auf 2 Stellen).
	 *
	 * @param float $net Nettobetrag.
	 * @param float $tax Steuerbetrag.
	 * @return float
	 */
	public static function tax_rate( $net, $tax ) {
		$net = (float) $net;
		if ( abs( $net ) < 0.00001 ) {
			return 0.0;
		}
		return round( (float) $tax / $net * 100, 2 );
	}

	/**
	 * Sucht in einer Liste von bexio-Steuern die mit dem passenden Satz.
	 *
	 * @param float $rate      Satz in Prozent (z.B. 8.1).
	 * @param array $taxes     Liste aus /3.0/taxes, jeweils mit "id" und "value".
	 * @param float $tolerance Erlaubte Abweichung in Prozentpunkten (Rundung von Woo-Beträgen).
	 * @return int|null bexio Steuer-ID oder null.
	 */
	public static function match_tax_id( $rate, array $taxes, $tolerance = 0.1 ) {
		$best      = null;
		$best_diff = PHP_FLOAT_MAX;
		foreach ( $taxes as $tax ) {
			if ( ! isset( $tax['id'], $tax['value'] ) ) {
				continue;
			}
			$diff = abs( (float) $tax['value'] - (float) $rate );
			if ( $diff <= $tolerance && $diff < $best_diff ) {
				$best      = (int) $tax['id'];
				$best_diff = $diff;
			}
		}
		return $best;
	}

	/**
	 * Betrag als String im von bexio erwarteten Format.
	 *
	 * @param float $amount   Betrag.
	 * @param int   $decimals Nachkommastellen.
	 * @return string
	 */
	public static function amount( $amount, $decimals = 6 ) {
		$formatted = number_format( (float) $amount, $decimals, '.', '' );
		$formatted = rtrim( rtrim( $formatted, '0' ), '.' );
		return ( '' === $formatted || '-0' === $formatted ) ? '0' : $formatted;
	}

	/**
	 * Ob ein Auftragstitel die Bestellnummer als eigenständige Zahl enthält.
	 * "12345", "12345 Max Muster", "WooCommerce Bestellung #12345" => ja; "123456" oder "A-112345" => nein.
	 *
	 * @param string $title        Auftragstitel aus bexio.
	 * @param string $order_number WooCommerce-Bestellnummer.
	 * @return bool
	 */
	public static function title_matches_order_number( $title, $order_number ) {
		$order_number = trim( (string) $order_number );
		if ( '' === $order_number ) {
			return false;
		}
		$pattern = '/(?<![0-9A-Za-z])' . preg_quote( $order_number, '/' ) . '(?![0-9A-Za-z])/u';
		return 1 === preg_match( $pattern, (string) $title );
	}

	/**
	 * Fasst Produktinfos zu den wichtigsten Fakten als Stichpunkte zusammen.
	 *
	 * Reihenfolge: Merkmale ("Name: Wert"), dann Aufzählungspunkte aus der Kurz- bzw. Langbeschreibung;
	 * gibt es dort keine Liste, die ersten Sätze der Kurzbeschreibung. Doppelte Punkte werden entfernt.
	 *
	 * @param array  $facts      Merkmal => Wert.
	 * @param string $short_html Kurzbeschreibung (HTML).
	 * @param string $long_html  Beschreibung (HTML).
	 * @param int    $max        Maximale Anzahl Punkte.
	 * @return string[]
	 */
	public static function description_bullets( array $facts, $short_html, $long_html, $max = 10 ) {
		$items = array();
		foreach ( $facts as $label => $value ) {
			$value = self::clean_text( $value );
			if ( '' !== $value ) {
				$items[] = self::clean_text( $label ) . ': ' . $value;
			}
		}
		$list = self::list_items( $short_html );
		if ( ! $list ) {
			$list = self::list_items( $long_html );
		}
		if ( ! $list ) {
			$list = array_slice( self::sentences( $short_html ), 0, 3 );
		}
		$items = array_merge( $items, $list );

		$seen = array();
		$out  = array();
		foreach ( $items as $item ) {
			$item = self::truncate( rtrim( $item, ' .;' ), 200 );
			$key  = function_exists( 'mb_strtolower' ) ? mb_strtolower( $item ) : strtolower( $item );
			if ( '' === $item || isset( $seen[ $key ] ) ) {
				continue;
			}
			$seen[ $key ] = true;
			$out[]        = $item;
			if ( count( $out ) >= $max ) {
				break;
			}
		}
		return $out;
	}

	/**
	 * Stichpunkte als HTML-Liste.
	 *
	 * @param string[] $bullets Punkte.
	 * @return string
	 */
	public static function bullets_html( array $bullets ) {
		if ( ! $bullets ) {
			return '';
		}
		$li = array_map(
			function ( $b ) {
				return '<li>' . htmlspecialchars( $b, ENT_QUOTES, 'UTF-8' ) . '</li>';
			},
			$bullets
		);
		return '<ul>' . implode( '', $li ) . '</ul>';
	}

	/**
	 * Texte aller <li>-Elemente.
	 *
	 * @param string $html HTML.
	 * @return string[]
	 */
	public static function list_items( $html ) {
		if ( ! preg_match_all( '#<li[^>]*>(.*?)</li>#is', (string) $html, $m ) ) {
			return array();
		}
		return array_values( array_filter( array_map( array( __CLASS__, 'clean_text' ), $m[1] ), 'strlen' ) );
	}

	/**
	 * Sätze bzw. Zeilen eines Textes.
	 *
	 * @param string $html HTML oder Text.
	 * @return string[]
	 */
	public static function sentences( $html ) {
		$text  = preg_replace( '#<(br|/p|/div|/h\d)[^>]*>#i', "\n", (string) $html );
		$text  = html_entity_decode( strip_tags( $text ), ENT_QUOTES, 'UTF-8' );
		$parts = preg_split( '/(?<=[.!?])\s+(?=[A-ZÄÖÜ0-9])|\n+/u', $text );
		return array_values( array_filter( array_map( array( __CLASS__, 'clean_text' ), (array) $parts ), 'strlen' ) );
	}

	/**
	 * HTML entfernen, Entities dekodieren, Leerraum vereinheitlichen.
	 *
	 * @param string $value Wert.
	 * @return string
	 */
	public static function clean_text( $value ) {
		$value = html_entity_decode( strip_tags( (string) $value ), ENT_QUOTES, 'UTF-8' );
		return trim( preg_replace( '/\s+/u', ' ', $value ) );
	}

	/**
	 * Kürzt einen String auf die maximale Feldlänge von bexio.
	 *
	 * @param string $value Text.
	 * @param int    $max   Maximale Länge.
	 * @return string
	 */
	public static function truncate( $value, $max ) {
		$value = trim( (string) $value );
		if ( function_exists( 'mb_substr' ) ) {
			return mb_substr( $value, 0, $max );
		}
		return substr( $value, 0, $max );
	}

	/**
	 * Ob eine Bestellung nur Platzhalterdaten enthält (z.B. TWINT Express vor der Zahlung:
	 * "First Last", "123 Main St", "email@example.com").
	 *
	 * @param string $first   Vorname.
	 * @param string $last    Nachname.
	 * @param string $email   E-Mail.
	 * @param string $address Strasse.
	 * @return bool
	 */
	public static function is_placeholder_customer( $first, $last, $email, $address ) {
		$email = strtolower( trim( (string) $email ) );
		if ( '' !== $email && preg_match( '/@example\.(com|org|net)$/', $email ) ) {
			return true;
		}
		$name = strtolower( trim( $first ) . ' ' . trim( $last ) );
		return 'first last' === $name && ( '' === trim( (string) $address ) || '123 main st' === strtolower( trim( $address ) ) );
	}

	/**
	 * Zeitpunkt, bis zu dem eine Etappe arbeiten darf. Der Server zählt sein Zeitlimit (max_execution_time)
	 * ab Beginn des Aufrufs – im selben Aufruf können vorher schon andere Hintergrundaufgaben gelaufen sein.
	 *
	 * @param int $budget Gewünschte Sekunden.
	 * @return int Unix-Timestamp.
	 */
	public static function deadline( $budget ) {
		$until = time() + (int) $budget;
		$limit = (int) ini_get( 'max_execution_time' );
		if ( $limit > 0 ) {
			$start = isset( $_SERVER['REQUEST_TIME'] ) ? (int) $_SERVER['REQUEST_TIME'] : time();
			$until = min( $until, $start + (int) floor( $limit * 0.6 ) );
		}
		return $until;
	}
}
