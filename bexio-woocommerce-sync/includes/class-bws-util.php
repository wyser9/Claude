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
}
