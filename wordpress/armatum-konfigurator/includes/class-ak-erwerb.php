<?php
/**
 * Erwerbs-Zusammenfassung (ab 1.0.9): Was braucht der Kunde mindestens für die ganze Zusammenstellung?
 *
 * Grundlage:
 *  - Attribut «Erwerbsbedingungen» (pa_erwerbsbedingungen, Attribut 12) je Produkt.
 *  - Kombinationsregeln nach Julia (Recht, Entscheide 27.11.2026, Ziffern 1, 2, 4, 8) und Walters Rechtscheck:
 *      R1 Schalldämpfer und Laser (auch Nachtsicht-/Wärmebildzielgerät) = kantonale Ausnahmebewilligung je Stück,
 *         auch wenn das Attribut fehlt (Art. 4 Abs. 2, Art. 5 Abs. 2 Bst. d WG).
 *      R2 Schulterstütze/Brace an Faustfeuerwaffe = Handfeuerwaffe (Art. 4a Abs. 1 WV), Magazingrenze 10 statt 20.
 *      R3 Magazin (auch mit Verlängerung) über 20 (Faust) bzw. 10 (Hand) Schuss an halbautomatischer
 *         Zentralfeuerwaffe = Ausnahmebewilligung, Sportschützen klein (Art. 4 Abs. 2bis, Art. 5 Abs. 1 Bst. c WG).
 *      R4 Klapp-/Teleskopschaft an Halbautomat (Handfeuerwaffe): Gesamtlänge eingeklappt unter 60 cm und dabei
 *         schussfähig = kantonale Ausnahmebewilligung, keine Sportschützen-Ausnahme (Art. 5 Abs. 1 Bst. d,
 *         Art. 28d Abs. 1 WG).
 *         - Originalschaft: Waffenfeld «laenge_eingeklappt» = Gesamtlänge mit eingeklapptem/eingeschobenem
 *           Originalschaft laut Waffenhersteller (Karl/Walter 27.11.).
 *         - Fremdschaft: nur rechnen, wenn beim Schaft ein belegter Wert «laenge_eingeklappt» steht (Zusatzlänge ab
 *           Puffer-/Gehäuseende, z.B. Strike FSA Dual Folder +26 mm), Gesamtlänge = Waffenlänge ohne Schaft + dieser Wert.
 *         Fehlt ein Wert (oder ist die Waffenlänge nur aus dem Bild geschätzt und nicht klar unter 60 cm):
 *         «Abklärung durch Armatum». Nie stillschweigend erlauben.
 *      R5 Langwaffe ohne Schulterstütze (Art. 33 Abs. 3 WV): gesperrt.
 *      R6 Klapp-/Teleskopschaft an Repetierer: «Abklärung durch Armatum» (Pascal 17.11.: dann WES, mit Julia klären).
 *  - Alle übrigen Teile: ihre eigene Bedingung aus dem Attribut.
 *
 * Die Zusammenfassung zeigt nie weniger als die strengste Bedingung eines Teils (UWG Art. 3 Abs. 1 Bst. b).
 * evaluate() arbeitet nur mit «Fakten» (Arrays) und ist ohne WordPress testbar (wordpress/tests/ak-erwerb-test.php).
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class AK_Erwerb {

	const TAX = 'pa_erwerbsbedingungen';

	/** Stufen: je höher, desto strenger. */
	const KEINE   = 0;
	const AUSWEIS = 1;
	const VERTRAG = 2;
	const STRAF   = 3;
	const WES     = 4;
	const AB      = 5;
	const KANTON  = 6;

	/** Term-IDs im Shop (Attribut 12, Stand 27.11.2026) => Stufe. */
	const TERM_STUFE = array(
		272   => self::AUSWEIS, // gültige Ausweiskopie (Pass/ID)
		11242 => self::VERTRAG, // schriftlicher Vertrag, ab 18 Jahren
		271   => self::STRAF,   // Auszug aus dem zentralen Strafregister
		11629 => self::STRAF,   // Munition: WES, Jagdschein, Strafregisterauszug oder Polizeiausweis
		617   => self::WES,     // WES (Waffenerwerbschein)
		649   => self::AB,      // Kleine Ausn.-Bewilligung Sportschütze
		10794 => self::AB,      // Ausnahmebewilligung klein (Sammler)
		658   => self::KANTON,  // kantonale Ausnahmebewilligung
	);

	const TEXT_AUSWEIS = 'gültige Ausweiskopie (Pass/ID)';
	const TEXT_AB      = 'Ausnahmebewilligung (Sportschützen: Kleine Ausn.-Bewilligung Sportschütze, Sammler: Ausnahmebewilligung klein)';
	const TEXT_KANTON  = 'kantonale Ausnahmebewilligung';
	const ABKLAERUNG   = 'Abklärung durch Armatum';

	/** Art. 5 Abs. 1 Bst. d WG: 60 cm. */
	const MIN_LAENGE = 600;
	/** Art. 4 Abs. 2bis WG: Ladevorrichtung hoher Kapazität über 20 (Faust) bzw. 10 (Hand) Schuss. */
	const GRENZE_FAUST = 20;
	const GRENZE_HAND  = 10;
	/** Geschätzte Waffenlänge (aus Bildpunkten, ±60 mm): nur entscheiden, wenn sie so weit von 60 cm weg ist. */
	const SCHAETZ_MARGE = 100;

	/** Bauart der Schulterstütze (Feld «schaft_art»). */
	const SCHAFT_ARTEN = array(
		'fest'     => 'fest',
		'teleskop' => 'Teleskop (verstellbar)',
		'klapp'    => 'Klappschaft',
		'ohne'     => 'kein Schaft (Abdeckung)',
	);

	/** Funktionsweise der Waffe (Feld «funktion»). */
	const FUNKTIONEN = array(
		'halbautomat' => 'Halbautomat',
		'repetierer'  => 'Repetierer / Unterhebel / Vorderschaft',
		'andere'      => 'andere (Revolver, Kipplauf, Einzellader …)',
	);

	/** Lampen mit Laser am Namen erkennen (Feld «laser» geht vor). */
	const LASER_RE = '/laser|\bX400|\bTLR[- ]?(2|4|6|8)(?!\d)|\bDBAL|\bPEQ|\bNGAL|\bMAWL|\bLA-5|\bPERST/i';
	/** Nachtsicht-/Wärmebildzielgeräte (Optik): gleich wie Laser (Julia Ziffer 1). */
	const NACHTSICHT_RE = '/nachtsicht|w(ä|ae)rmebild|thermal|night\s*vision/i';

	// ------------------------------------------------------------------------------------------
	// Fakten aus den Produktdaten
	// ------------------------------------------------------------------------------------------

	/** Terms des Attributs 12 eines Produkts: id => Name (Varianten: vom Hauptprodukt). */
	public static function terms( $product_id ) {
		$pid   = AK_Data::parent_of( $product_id ) ? AK_Data::parent_of( $product_id ) : $product_id;
		$terms = get_the_terms( $pid, self::TAX );
		$out   = array();
		foreach ( ( $terms && ! is_wp_error( $terms ) ) ? $terms : array() as $t ) {
			$out[ (int) $t->term_id ] = trim( wp_specialchars_decode( (string) $t->name ) );
		}
		return $out;
	}

	/** Stufe eines Terms: bekannte ID, sonst aus dem Namen. */
	public static function term_stufe( $id, $name ) {
		if ( isset( self::TERM_STUFE[ $id ] ) ) {
			return self::TERM_STUFE[ $id ];
		}
		$checks = array(
			'/kantonal/i'                 => self::KANTON,
			'/ausnahme|ausn\./i'          => self::AB,
			'/\bWES\b|waffenerwerb/i'     => self::WES,
			'/strafregister/i'            => self::STRAF,
			'/vertrag/i'                  => self::VERTRAG,
			'/ausweis/i'                  => self::AUSWEIS,
		);
		foreach ( $checks as $re => $stufe ) {
			if ( preg_match( $re, $name ) ) {
				return $stufe;
			}
		}
		return self::AUSWEIS; // Unbekannte Bedingung: mindestens irgendein Dokument.
	}

	/** Höchste Stufe einer Term-Liste. */
	public static function stufe( $terms ) {
		$max = self::KEINE;
		foreach ( (array) $terms as $id => $name ) {
			$max = max( $max, self::term_stufe( $id, $name ) );
		}
		return $max;
	}

	/**
	 * Text einer Term-Liste ohne Ausweiskopie (die kommt immer dazu).
	 * Ausnahmebewilligungen (Sportschütze, Sammler) sind Alternativen («oder»), der Rest gilt zusammen («+»).
	 */
	public static function terms_text( $terms ) {
		$alt = array();
		$and = array();
		foreach ( (array) $terms as $id => $name ) {
			$s = self::term_stufe( $id, $name );
			if ( self::AUSWEIS === $s ) {
				continue;
			}
			if ( self::AB === $s ) {
				$alt[] = $name;
			} else {
				$and[ $name ] = $s;
			}
		}
		arsort( $and );
		$parts = array_keys( $and );
		if ( $alt ) {
			$parts[] = implode( ' oder ', $alt );
			usort(
				$parts,
				function ( $a, $b ) use ( $and ) {
					$sa = isset( $and[ $a ] ) ? $and[ $a ] : self::AB;
					$sb = isset( $and[ $b ] ) ? $and[ $b ] : self::AB;
					return $sb - $sa;
				}
			);
		}
		return implode( ' + ', $parts );
	}

	/** Zahl in mm aus einem Feld (Komma erlaubt), 0 = leer. */
	private static function mm( $product_id, $key ) {
		$v = str_replace( ',', '.', (string) AK_Data::meta( $product_id, $key ) );
		return preg_match( '/\d+(\.\d+)?/', $v, $m ) ? (float) $m[0] : 0.0;
	}

	/** Magazinkapazität: Feld «magazin», sonst Attribut «Magazinkapazität» (grösste Zahl, ohne Klammern), 0 = unbekannt. */
	public static function magazine( $product_id ) {
		$own = self::mm( $product_id, 'magazin' );
		if ( $own > 0 ) {
			return (int) $own;
		}
		$p   = wc_get_product( $product_id );
		$raw = $p ? preg_replace( '/\(.*?\)/', '', wp_specialchars_decode( (string) $p->get_attribute( 'pa_magazinkapazitaet' ) ) ) : '';
		preg_match_all( '/\d+/', (string) $raw, $m );
		return $m[0] ? (int) max( array_map( 'intval', $m[0] ) ) : 0;
	}

	/** Funktionsweise: Feld, sonst Kategorie, sonst Plattform/Waffenart, sonst '' (unbekannt). */
	public static function function_type( $weapon_id ) {
		$f = (string) AK_Data::meta( $weapon_id, 'funktion' );
		if ( isset( self::FUNKTIONEN[ $f ] ) ) {
			return $f;
		}
		$pid  = AK_Data::parent_of( $weapon_id ) ? AK_Data::parent_of( $weapon_id ) : $weapon_id;
		$cats = wp_get_post_terms( $pid, 'product_cat', array( 'fields' => 'names' ) );
		$text = implode( ' ', is_wp_error( $cats ) ? array() : (array) $cats ) . ' ' . get_the_title( $pid );
		if ( preg_match( '/revolver|kipplauf|einzellader|drilling|bockdoppel|doppelflinte/i', $text ) ) {
			return 'andere';
		}
		if ( preg_match( '/halbautomat|selbstlade|semi.?auto/i', $text ) ) {
			return 'halbautomat';
		}
		if ( preg_match( '/repetier|bolt.?action|geradezug|unterhebel|vorderschaft|pump/i', $text ) ) {
			return 'repetierer';
		}
		$plat = AK_Data::platforms( $weapon_id );
		if ( array_intersect( $plat, array( 'ar15', 'ar10', 'ak' ) ) ) {
			return 'halbautomat';
		}
		if ( in_array( 'repetierer', $plat, true ) ) {
			return 'repetierer';
		}
		if ( 'pistole' === AK_Data::weapon_type( $weapon_id ) ) {
			return 'halbautomat'; // Pistolen sind Halbautomaten, Revolver oben ausgenommen.
		}
		return '';
	}

	/**
	 * Länge der Waffe ohne Schulterstütze in mm (Mündung bis Schaftaufnahme).
	 * Feld «laenge_ohne_schaft», sonst geschätzt aus Messpunkten und Punkt der Schaftaufnahme.
	 *
	 * @return array( Länge, geschätzt? )
	 */
	public static function length_without_stock( $weapon_id ) {
		$own = self::mm( $weapon_id, 'laenge_ohne_schaft' );
		if ( $own > 0 ) {
			return array( $own, false );
		}
		$s  = AK_Data::point( $weapon_id, 'slot_schaft_anchor' );
		$p1 = AK_Data::point( $weapon_id, 'p1' );
		$p2 = AK_Data::point( $weapon_id, 'p2' );
		$k  = AK_Data::scale( $weapon_id );
		if ( ! $s || ! $p1 || ! $p2 || $k <= 0 ) {
			return array( 0.0, false );
		}
		return array( max( abs( $p1[0] - $s[0] ), abs( $p2[0] - $s[0] ) ) * $k, true );
	}

	/** Bauart einer Schulterstütze: Feld, sonst aus dem Namen, sonst '' (unbekannt). */
	public static function stock_type( $part_id ) {
		$t = (string) AK_Data::meta( $part_id, 'schaft_art' );
		if ( isset( self::SCHAFT_ARTEN[ $t ] ) ) {
			return $t;
		}
		$pid  = AK_Data::parent_of( $part_id ) ? AK_Data::parent_of( $part_id ) : $part_id;
		$name = wp_specialchars_decode( (string) get_the_title( $pid ) );
		if ( preg_match( '/klapp|fold|zhukov/i', $name ) ) {
			return 'klapp';
		}
		if ( preg_match( '/\bfixed\b|\bfest\b|\bA[12]\b|\bPRS\b|rifle\s+stock|präzision|precision/i', $name ) ) {
			return 'fest';
		}
		if ( preg_match( '/teleskop|collapsible|carbine|verstellbar|adjustable|\bCTR\b|\bMOE\b|SL-?K|\bSTR\b|\bACS\b|SOPMOD|\bUBR\b|crane|e-?stock|\bBCM\b|gunfighter|\bSL\b/i', $name ) ) {
			return 'teleskop';
		}
		return '';
	}

	/** Brace (Pistolen-Stabilisator): Feld «brace», sonst Name. */
	public static function is_brace( $part_id ) {
		$v = (string) AK_Data::meta( $part_id, 'brace' );
		if ( 'yes' === $v || 'no' === $v ) {
			return 'yes' === $v;
		}
		$pid = AK_Data::parent_of( $part_id ) ? AK_Data::parent_of( $part_id ) : $part_id;
		return (bool) preg_match( '/brace|stabili[sz]er/i', (string) get_the_title( $pid ) );
	}

	/** Laserzielgerät: Gruppe Laser, Feld «laser», sonst Name (bei Lampen). Nachtsicht-/Wärmebild bei Optik. */
	public static function is_laser( $part_id ) {
		$v = (string) AK_Data::meta( $part_id, 'laser' );
		if ( 'yes' === $v || 'no' === $v ) {
			return 'yes' === $v;
		}
		if ( 'laser' === AK_Data::group( $part_id ) ) {
			return true;
		}
		$pid  = AK_Data::parent_of( $part_id ) ? AK_Data::parent_of( $part_id ) : $part_id;
		$name = (string) get_the_title( $pid );
		if ( 'lampe' === AK_Data::group( $part_id ) ) {
			return (bool) preg_match( self::LASER_RE, $name );
		}
		return false;
	}

	public static function is_night_vision( $part_id ) {
		$pid = AK_Data::parent_of( $part_id ) ? AK_Data::parent_of( $part_id ) : $part_id;
		return in_array( AK_Data::role( $part_id ), array( 'optik', 'magnifier' ), true ) && (bool) preg_match( self::NACHTSICHT_RE, (string) get_the_title( $pid ) );
	}

	/** Fakten einer Waffe für evaluate(). */
	public static function weapon_facts( $weapon_id ) {
		list( $len, $est ) = self::length_without_stock( $weapon_id );
		$classes           = AK_Data::caliber_classes( $weapon_id );
		return array(
			'id'                 => (int) $weapon_id,
			'name'               => get_the_title( $weapon_id ),
			'terms'              => self::terms( $weapon_id ),
			'art'                => AK_Data::weapon_type( $weapon_id ),
			'funktion'           => self::function_type( $weapon_id ),
			'randfeuer'          => $classes && ! array_diff( $classes, array( 'kk' ) ),
			'magazin'            => self::magazine( $weapon_id ),
			'laenge_ohne_schaft' => $len,
			'laenge_geschaetzt'  => $est,
			'laenge_eingeklappt' => self::mm( $weapon_id, 'laenge_eingeklappt' ),
		);
	}

	/** Fakten eines Teils für evaluate(). */
	public static function part_facts( $part_id ) {
		$g    = AK_Data::group( $part_id );
		$role = AK_Data::role( $part_id );
		$fn   = (string) AK_Data::meta( $part_id, 'eingeklappt_funktion' );
		return array(
			'id'                   => (int) $part_id,
			'name'                 => get_the_title( AK_Data::parent_of( $part_id ) ? AK_Data::parent_of( $part_id ) : $part_id ),
			'gruppe'               => $g ? $g : $role,
			'terms'                => self::terms( $part_id ),
			'schalldaempfer'       => 'schalldaempfer' === $g,
			'laser'                => self::is_laser( $part_id ),
			'nachtsicht'           => self::is_night_vision( $part_id ),
			'schaft_art'           => 'schaft' === $g ? self::stock_type( $part_id ) : '',
			'brace'                => 'schaft' === $g && self::is_brace( $part_id ),
			'laenge_eingeklappt'   => 'schaft' === $g ? self::mm( $part_id, 'laenge_eingeklappt' ) : 0.0,
			'eingeklappt_funktion' => 'nein' !== $fn,
			'magazin'              => (int) self::mm( $part_id, 'magazin' ),
			'magazin_plus'         => (int) self::mm( $part_id, 'magazin_plus' ),
		);
	}

	/** Zusammenfassung für eine Auswahl (Waffe + beliebige Produkt-IDs: Optik, Montage, Zubehör, Adapter). */
	public static function for_selection( $weapon_id, $part_ids ) {
		$parts = array();
		foreach ( array_unique( array_filter( array_map( 'intval', (array) $part_ids ) ) ) as $pid ) {
			if ( $pid !== (int) $weapon_id ) {
				$parts[] = self::part_facts( $pid );
			}
		}
		return self::evaluate( self::weapon_facts( $weapon_id ), $parts );
	}

	/** Bedingung eines einzelnen Teils für die Karte im Konfigurator («Erwerb: …»), '' = frei. */
	public static function product_text( $product_id ) {
		$terms = self::terms( $product_id );
		$text  = self::terms_text( $terms );
		if ( 'waffe' !== AK_Data::role( $product_id ) && self::stufe( $terms ) < self::KANTON ) {
			if ( 'schalldaempfer' === AK_Data::group( $product_id ) || self::is_laser( $product_id ) || self::is_night_vision( $product_id ) ) {
				$text = self::TEXT_KANTON;
			}
		}
		if ( '' === $text ) {
			return $terms ? self::TEXT_AUSWEIS : '';
		}
		return $text . ' + Ausweiskopie';
	}

	// ------------------------------------------------------------------------------------------
	// Regeln
	// ------------------------------------------------------------------------------------------

	/**
	 * Mindestanforderung der Zusammenstellung.
	 *
	 * @param array $w     Fakten der Waffe (weapon_facts).
	 * @param array $parts Fakten der Teile (part_facts).
	 * @return array {
	 *   stufe: höchste Stufe, zeilen: [ {fuer, name, text, stufe, grund} ], abklaerung: [Text], sperre: [Text],
	 *   satz: «Für diese Zusammenstellung brauchst du mindestens: …»
	 * }
	 */
	public static function evaluate( $w, $parts ) {
		$abkl   = array();
		$sperre = array();
		$zeilen = array();

		// Waffe: eigene Bedingung aus dem Attribut.
		$w_terms = isset( $w['terms'] ) ? (array) $w['terms'] : array();
		$waffe   = array(
			'fuer'  => 'Waffe',
			'name'  => $w['name'],
			'text'  => self::terms_text( $w_terms ),
			'stufe' => self::stufe( $w_terms ),
			'grund' => '',
		);
		if ( ! $w_terms ) {
			$abkl[] = 'Für die Waffe ist keine Erwerbsbedingung hinterlegt.';
		}
		$raise = function ( $stufe, $text, $grund ) use ( &$waffe ) {
			if ( $stufe > $waffe['stufe'] ) {
				$waffe['stufe'] = $stufe;
				$waffe['text']  = $text;
				$waffe['grund'] = $grund;
			} elseif ( $stufe === $waffe['stufe'] && '' === $waffe['grund'] ) {
				$waffe['grund'] = $grund;
			}
		};

		$stocks = array_values( array_filter( $parts, function ( $p ) { return 'schaft' === $p['gruppe']; } ) );
		$stock  = $stocks ? $stocks[0] : null;
		$pistol = 'pistole' === $w['art'];
		$semi   = 'halbautomat' === $w['funktion'];

		// R5: Langwaffe ohne Schulterstütze.
		if ( $stock && ! $pistol && 'ohne' === $stock['schaft_art'] ) {
			$sperre[] = 'Eine Langwaffe ohne Schulterstütze bieten wir nicht an (Art. 33 Abs. 3 WV).';
		}

		// R2: Schulterstütze oder Brace an Faustfeuerwaffe = Handfeuerwaffe.
		$hand = ! $pistol;
		if ( $pistol && $stock && 'ohne' !== $stock['schaft_art'] ) {
			$hand = true;
		}
		$brace_note = $pistol && $hand ? 'Mit ' . ( $stock['brace'] ? 'Brace' : 'Schulterstütze' ) . ' gilt die Pistole als Handfeuerwaffe (Art. 4a Abs. 1 WV). ' : '';

		// R3: Magazinkapazität (Waffe, Magazin als Teil, Verlängerung).
		$cap  = (int) $w['magazin'];
		$plus = 0;
		foreach ( $parts as $p ) {
			if ( $p['magazin'] > 0 ) {
				$cap = max( $cap, $p['magazin'] );
			}
			$plus += max( 0, $p['magazin_plus'] );
		}
		$cap_total = $cap > 0 ? $cap + $plus : 0;
		$grenze    = $hand ? self::GRENZE_HAND : self::GRENZE_FAUST;
		if ( empty( $w['randfeuer'] ) ) {
			if ( $cap_total > $grenze ) {
				if ( $semi ) {
					$what = $plus ? 'Das Magazin mit Verlängerung fasst ' . $cap_total . ' Schuss' : 'Das Magazin fasst ' . $cap_total . ' Schuss';
					$raise( self::AB, self::TEXT_AB, $brace_note . $what . ', mehr als ' . $grenze . ' Schuss (Art. 5 Abs. 1 Bst. c WG).' );
				} elseif ( '' === $w['funktion'] ) {
					$abkl[] = 'Magazin über ' . $grenze . ' Schuss: Funktionsweise der Waffe nicht erfasst.';
				}
			} elseif ( ! $cap && ( $plus || ( $pistol && $hand ) ) ) {
				$abkl[] = 'Magazinkapazität der Waffe ist nicht erfasst (Grenze ' . $grenze . ' Schuss).';
			}
		}

		// R4 / R6: Klapp- oder Teleskopschaft.
		if ( $stock && $hand && in_array( $stock['schaft_art'], array( 'klapp', 'teleskop', '' ), true ) ) {
			if ( 'repetierer' === $w['funktion'] && '' !== $stock['schaft_art'] ) {
				$abkl[] = 'Klapp- oder Teleskopschaft an einem Repetierer.';
			} elseif ( $semi ) {
				if ( '' === $stock['schaft_art'] ) {
					$abkl[] = 'Bauart der Schulterstütze (fest, Teleskop, Klapp) ist nicht erfasst.';
				} elseif ( ! $stock['eingeklappt_funktion'] ) { // phpcs:ignore Generic.CodeAnalysis.EmptyStatement
					// Eingeklappt nicht schussfähig (z.B. AR15 Direktgas mit umgeklapptem Buffer Tube): Art. 5 Abs. 1 Bst. d WG greift nicht.
				} elseif ( $stock['laenge_eingeklappt'] <= 0 ) {
					$abkl[] = 'Für diese Schulterstütze ist keine belegte Länge im eingeklappten bzw. eingeschobenen Zustand erfasst (Grenze 60 cm).';
				} elseif ( $w['laenge_ohne_schaft'] <= 0 ) {
					$abkl[] = 'Die Länge der Waffe ohne Schulterstütze ist nicht erfasst (Grenze 60 cm).';
				} else {
					$total = $w['laenge_ohne_schaft'] + $stock['laenge_eingeklappt'];
					$est   = ! empty( $w['laenge_geschaetzt'] );
					if ( $total < self::MIN_LAENGE && ( ! $est || $total < self::MIN_LAENGE - self::SCHAETZ_MARGE ) ) {
						$raise(
							self::KANTON,
							self::TEXT_KANTON,
							$brace_note . 'Mit eingeklappter bzw. eingeschobener Schulterstütze ist die Waffe ' . ( $est ? 'etwa ' : '' ) . round( $total / 10 ) . ' cm lang, kürzer als 60 cm (Art. 5 Abs. 1 Bst. d WG). Die Ausnahme für Sportschützen gilt dafür nicht.'
						);
					} elseif ( $est ) {
						// Geschätzte Waffenlänge (Bildpunkte, ±60 mm) reicht nicht, um eine Kombination zu erlauben.
						$abkl[] = 'Gesamtlänge eingeklappt etwa ' . round( $total / 10 ) . ' cm, die Länge der Waffe ohne Schulterstütze ist nur geschätzt (Grenze 60 cm).';
					}
				}
			} elseif ( '' === $w['funktion'] && '' !== $stock['schaft_art'] ) {
				$abkl[] = 'Klapp- oder Teleskopschaft: Funktionsweise der Waffe ist nicht erfasst.';
			}
		}
		// R4 Originalschaft: Waffe mit eigenem Klapp-/Teleskopschaft, eingeklappt laut Hersteller unter 60 cm.
		if ( ! $stock && $hand && $semi && $w['laenge_eingeklappt'] > 0 && $w['laenge_eingeklappt'] < self::MIN_LAENGE ) {
			$raise( self::KANTON, self::TEXT_KANTON, 'Mit eingeklapptem bzw. eingeschobenem Originalschaft ist die Waffe ' . round( $w['laenge_eingeklappt'] / 10 ) . ' cm lang, kürzer als 60 cm (Art. 5 Abs. 1 Bst. d WG). Die Ausnahme für Sportschützen gilt dafür nicht.' );
		}
		if ( $brace_note && '' === $waffe['grund'] ) {
			$waffe['grund'] = trim( $brace_note );
		}
		if ( $waffe['stufe'] >= self::VERTRAG || $waffe['grund'] ) {
			$zeilen[] = $waffe;
		}

		// R1 und übrige Teile.
		foreach ( $parts as $p ) {
			$stufe = self::stufe( $p['terms'] );
			$text  = self::terms_text( $p['terms'] );
			$grund = '';
			if ( $p['schalldaempfer'] || $p['laser'] || $p['nachtsicht'] ) {
				$what = $p['schalldaempfer'] ? 'Schalldämpfer' : ( $p['laser'] ? 'Laserzielgerät' : 'Nachtsicht- oder Wärmebildzielgerät' );
				$grund = $what . ', je Stück eine Bewilligung (Art. 5 Abs. 2 Bst. d WG).';
				if ( $stufe < self::KANTON ) {
					$stufe = self::KANTON;
					$text  = self::TEXT_KANTON;
				}
			}
			if ( $stufe >= self::VERTRAG ) {
				$zeilen[] = array(
					'fuer'  => self::label( $p ),
					'name'  => $p['name'],
					'text'  => $text,
					'stufe' => $stufe,
					'grund' => $grund,
				);
			}
		}

		$max = $waffe['stufe'];
		foreach ( $parts as $p ) {
			$max = max( $max, self::stufe( $p['terms'] ) );
		}
		foreach ( $zeilen as $z ) {
			$max = max( $max, $z['stufe'] );
		}
		usort(
			$zeilen,
			function ( $a, $b ) {
				return $b['stufe'] - $a['stufe'];
			}
		);
		$out = array(
			'stufe'      => $max,
			'zeilen'     => $zeilen,
			'abklaerung' => array_values( array_unique( $abkl ) ),
			'sperre'     => $sperre,
		);
		$out['satz'] = self::sentence( $out );
		return $out;
	}

	/** Bezeichnung eines Teils in der Zusammenfassung. */
	public static function label( $p ) {
		if ( ! empty( $p['brace'] ) ) {
			return 'Brace';
		}
		if ( ! empty( $p['laser'] ) ) {
			return 'Laser';
		}
		if ( ! empty( $p['nachtsicht'] ) ) {
			return 'Nachtsichtgerät';
		}
		$labels = array(
			'optik'     => 'Optik',
			'montage'   => 'Montage',
			'magnifier' => 'Magnifier',
		);
		if ( isset( $labels[ $p['gruppe'] ] ) ) {
			return $labels[ $p['gruppe'] ];
		}
		return isset( AK_Data::GROUPS[ $p['gruppe'] ] ) ? preg_replace( '/\s*\(.*\)$/', '', AK_Data::GROUPS[ $p['gruppe'] ][1] ) : 'Teil';
	}

	/** Satz «Für diese Zusammenstellung brauchst du mindestens: …». */
	public static function sentence( $r ) {
		if ( $r['sperre'] ) {
			return 'Diese Zusammenstellung können wir so nicht anbieten.';
		}
		$by_text = array();
		foreach ( $r['zeilen'] as $z ) {
			if ( '' === $z['text'] ) {
				continue;
			}
			if ( ! isset( $by_text[ $z['text'] ] ) ) {
				$by_text[ $z['text'] ] = array();
			}
			if ( ! in_array( $z['fuer'], $by_text[ $z['text'] ], true ) ) {
				$by_text[ $z['text'] ][] = $z['fuer'];
			}
		}
		$parts = array();
		foreach ( $by_text as $text => $fuer ) {
			$parts[] = count( $by_text ) > 1 || 'Waffe' !== $fuer[0] || count( $fuer ) > 1 ? $text . ' (' . implode( ', ', $fuer ) . ')' : $text;
		}
		if ( $r['stufe'] >= self::AUSWEIS ) {
			$parts[] = self::TEXT_AUSWEIS;
		}
		if ( ! $parts ) {
			return $r['abklaerung'] ? 'Die Erwerbsbedingungen für diese Zusammenstellung klären wir ab.' : 'Für diese Zusammenstellung sind keine Erwerbsdokumente nötig.';
		}
		return 'Für diese Zusammenstellung brauchst du mindestens: ' . implode( ' + ', $parts ) . '.';
	}

	/** HTML für Konfigurator und Warenkorb-Schritt. */
	public static function html( $r ) {
		$h = '<p class="ak-erwerb-satz">' . esc_html( $r['satz'] ) . '</p>';
		if ( $r['sperre'] ) {
			$h .= '<p class="ak-erwerb-sperre">' . esc_html( implode( ' ', $r['sperre'] ) ) . '</p>';
		}
		if ( $r['abklaerung'] ) {
			$h .= '<p class="ak-erwerb-abkl"><strong>' . esc_html( self::ABKLAERUNG ) . ':</strong> ' . esc_html( implode( ' ', $r['abklaerung'] ) ) . ' Wir prüfen die Zusammenstellung vor der Montage und melden uns.</p>';
		}
		$items = '';
		foreach ( $r['zeilen'] as $z ) {
			$items .= '<li><strong>' . esc_html( $z['fuer'] ) . '</strong> ' . esc_html( $z['name'] ) . ': ' . esc_html( '' !== $z['text'] ? $z['text'] : self::TEXT_AUSWEIS ) .
				( $z['grund'] ? '<br><small>' . esc_html( $z['grund'] ) . '</small>' : '' ) . '</li>';
		}
		if ( $items ) {
			$h .= '<details class="ak-erwerb-details"><summary>Je Teil</summary><ul>' . $items . '</ul></details>';
		}
		return $h;
	}
}
