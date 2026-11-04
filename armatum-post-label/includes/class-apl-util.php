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

	/**
	 * Ob das Label auf A4 (oberes Drittel) ausgegeben wird. Braucht die PHP-Bildbibliothek GD.
	 *
	 * @return bool
	 */
	public static function use_a4() {
		return 'a6' !== get_option( 'armatum_post_paper', 'a4_third' ) && function_exists( 'imagecreatefromstring' );
	}

	/**
	 * Setzt ein Label-Bild (PNG der Post, 300 dpi) in das obere Drittel einer A4-Seite, damit das Blatt
	 * wie ein Brief in drei Teile gefaltet werden kann. Hochformat-Labels werden gedreht. Das Label wird nur
	 * so weit verkleinert, wie es für das Drittel nötig ist. Dünne Falzlinien zeigen die Faltstellen.
	 *
	 * @param string $image Bilddaten (PNG/GIF/JPG).
	 * @param int    $dpi   Auflösung des Bilds.
	 * @return array{pdf:string,scale:float}|null
	 */
	public static function a4_third_pdf( $image, $dpi = 300 ) {
		if ( ! function_exists( 'imagecreatefromstring' ) ) {
			return null;
		}
		$src = @imagecreatefromstring( $image ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
		if ( ! $src ) {
			return null;
		}
		$w = imagesx( $src );
		$h = imagesy( $src );
		// Auf Weiss setzen (Transparenz) und als 8-Bit-Graustufen lesen.
		$img = imagecreatetruecolor( $w, $h );
		imagefill( $img, 0, 0, imagecolorallocate( $img, 255, 255, 255 ) );
		imagecopy( $img, $src, 0, 0, 0, 0, $w, $h );
		imagedestroy( $src );
		$raw = '';
		for ( $y = 0; $y < $h; $y++ ) {
			$row = '';
			for ( $x = 0; $x < $w; $x++ ) {
				$c    = imagecolorat( $img, $x, $y );
				$row .= chr( (int) ( ( ( ( $c >> 16 ) & 255 ) * 299 + ( ( $c >> 8 ) & 255 ) * 587 + ( $c & 255 ) * 114 ) / 1000 ) );
			}
			$raw .= $row;
		}
		imagedestroy( $img );
		$data = gzcompress( $raw, 9 );

		$pt     = 72 / 25.4;
		$page_w = 210 * $pt;
		$page_h = 297 * $pt;
		$third  = $page_h / 3;
		$margin = 3 * $pt;
		$iw     = $w / $dpi * 72;
		$ih     = $h / $dpi * 72;
		$rotate = $h > $w;
		$disp_w = $rotate ? $ih : $iw;
		$disp_h = $rotate ? $iw : $ih;
		$scale  = min( 1.0, ( $page_w - 2 * $margin ) / $disp_w, ( $third - 2 * $margin ) / $disp_h );
		$disp_w *= $scale;
		$disp_h *= $scale;
		$x0      = ( $page_w - $disp_w ) / 2;
		$y0      = $page_h - $third + ( $third - $disp_h ) / 2;
		$f       = function ( $n ) {
			return number_format( $n, 3, '.', '' );
		};
		$matrix  = $rotate
			? sprintf( '0 %s %s 0 %s %s', $f( $iw * $scale ), $f( -$ih * $scale ), $f( $x0 + $disp_w ), $f( $y0 ) )
			: sprintf( '%s 0 0 %s %s %s', $f( $iw * $scale ), $f( $ih * $scale ), $f( $x0 ), $f( $y0 ) );
		$content = sprintf( "q %s cm /Im0 Do Q\n", $matrix );
		foreach ( array( $page_h - $third, $page_h - 2 * $third ) as $fold ) {
			$content .= sprintf( "q 0.6 G 0.4 w [4 4] 0 d 0 %s m %s %s l S Q\n", $f( $fold ), $f( $page_w ), $f( $fold ) );
		}

		$objects = array(
			'<< /Type /Catalog /Pages 2 0 R >>',
			'<< /Type /Pages /Kids [3 0 R] /Count 1 >>',
			sprintf( '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 %s %s] /Resources << /XObject << /Im0 5 0 R >> >> /Contents 4 0 R >>', $f( $page_w ), $f( $page_h ) ),
			sprintf( "<< /Length %d >>\nstream\n%s\nendstream", strlen( $content ), $content ),
			sprintf( "<< /Type /XObject /Subtype /Image /Width %d /Height %d /ColorSpace /DeviceGray /BitsPerComponent 8 /Filter /FlateDecode /Length %d >>\nstream\n%s\nendstream", $w, $h, strlen( $data ), $data ),
		);
		$pdf     = "%PDF-1.4\n%\xE2\xE3\xCF\xD3\n";
		$offsets = array();
		foreach ( $objects as $i => $object ) {
			$offsets[] = strlen( $pdf );
			$pdf      .= ( $i + 1 ) . " 0 obj\n" . $object . "\nendobj\n";
		}
		$xref = strlen( $pdf );
		$pdf .= 'xref' . "\n0 " . ( count( $objects ) + 1 ) . "\n0000000000 65535 f \n";
		foreach ( $offsets as $offset ) {
			$pdf .= sprintf( "%010d 00000 n \n", $offset );
		}
		$pdf .= sprintf( "trailer\n<< /Size %d /Root 1 0 R >>\nstartxref\n%d\n%%%%EOF\n", count( $objects ) + 1, $xref );

		return array(
			'pdf'   => $pdf,
			'scale' => $scale,
		);
	}
}
