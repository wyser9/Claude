<?php
defined( 'ABSPATH' ) || exit;

/**
 * Reine Hilfsfunktionen ohne WordPress-Abhängigkeiten (unit-testbar).
 */
class APL_Util {

	/** Höchstgewicht PostPac in Gramm. */
	const MAX_WEIGHT_G = 30000;

	/**
	 * Zerlegt eine Adresszeile in Strasse und Hausnummer.
	 *
	 * @param string $line Adresszeile.
	 * @return array{0:string,1:string}
	 */
	public static function split_street( $line ) {
		$line = trim( preg_replace( '/\s+/', ' ', (string) $line ) );
		if ( '' === $line ) {
			return array( '', '' );
		}
		if ( preg_match( '/^(.+?)\s+(\d+\s?[a-zA-Z]?(?:\s?[-\/]\s?\d+\s?[a-zA-Z]?)?)$/u', $line, $m ) ) {
			return array( trim( $m[1], ' ,' ), str_replace( ' ', '', $m[2] ) );
		}
		if ( preg_match( '/^(\d+\s?[a-zA-Z]?)[\s,]+(.+)$/u', $line, $m ) ) {
			return array( trim( $m[2] ), str_replace( ' ', '', $m[1] ) );
		}
		return array( $line, '' );
	}

	/**
	 * Gewicht in ganze Gramm umrechnen.
	 *
	 * @param float  $weight Gewicht.
	 * @param string $unit   kg|g|lbs|oz.
	 * @return int
	 */
	public static function to_grams( $weight, $unit ) {
		$factor = array(
			'kg'  => 1000,
			'g'   => 1,
			'lbs' => 453.59237,
			'oz'  => 28.349523125,
		);
		return (int) round( (float) $weight * ( $factor[ $unit ] ?? 1000 ) );
	}

	/**
	 * Prüft das Paketgewicht für PostPac.
	 *
	 * @param int $grams Gewicht in Gramm.
	 * @return string|null Fehlermeldung oder null.
	 */
	public static function weight_error( $grams ) {
		if ( $grams <= 0 ) {
			return 'Kein Gewicht: Bitte das Paketgewicht eintragen (bei den Produkten fehlt das Gewicht).';
		}
		if ( $grams > self::MAX_WEIGHT_G ) {
			return sprintf( 'Gewicht %s kg überschreitet das PostPac-Maximum von 30 kg. Bitte auf mehrere Pakete aufteilen oder Gewicht prüfen.', number_format( $grams / 1000, 2, '.', "'" ) );
		}
		return null;
	}

	/**
	 * Post-Zusatzleistungen (PRZL) für PostPac Economy.
	 *
	 * @param bool $signature Signature (SI).
	 * @param bool $bulky     Sperrgut (SP).
	 * @return string[]
	 */
	public static function services( $signature, $bulky ) {
		$services = array( 'ECO' );
		if ( $signature ) {
			$services[] = 'SI';
		}
		if ( $bulky ) {
			$services[] = 'SP';
		}
		return $services;
	}

	/**
	 * Vorschlag für die Signature-Option anhand des Versandart-Namens.
	 *
	 * @param string[] $shipping_names Namen der Versandarten der Bestellung.
	 * @return bool
	 */
	public static function suggest_signature( array $shipping_names ) {
		foreach ( $shipping_names as $name ) {
			if ( preg_match( '/signatur|waffe/i', (string) $name ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Vorschlag für Sperrgut anhand des Versandart-Namens.
	 *
	 * @param string[] $shipping_names Namen der Versandarten.
	 * @return bool
	 */
	public static function suggest_bulky( array $shipping_names ) {
		foreach ( $shipping_names as $name ) {
			if ( preg_match( '/sperrgut/i', (string) $name ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Link zur Sendungsverfolgung.
	 *
	 * @param string $ident_code Sendungsnummer.
	 * @return string
	 */
	public static function tracking_url( $ident_code ) {
		return 'https://www.post.ch/swisspost-tracking?formattedParcelCodes=' . rawurlencode( preg_replace( '/\s+/', '', (string) $ident_code ) );
	}
}
