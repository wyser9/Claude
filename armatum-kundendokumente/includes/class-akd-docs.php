<?php
defined( 'ABSPATH' ) || exit;

/**
 * Reine Logik ohne WordPress-Abhängigkeit: Dokumentart aus dem Dateinamen erkennen,
 * Datum im Dateinamen lesen und die Unterlagen eines Kunden bewerten.
 */
class AKD_Docs {

	const AUSWEIS = 'ausweis';
	const WES     = 'wes';
	const STRAF   = 'strafregister';
	const JAGD    = 'jagdschein';

	const OK     = 'ok';
	const PRUEFEN = 'pruefen';
	const FEHLT  = 'fehlt';

	/** Gültigkeit in Monaten. */
	const WES_MONATE   = 24;
	const STRAF_MONATE = 3;

	/** @return array Anzeigenamen. */
	public static function labels() {
		return array(
			self::AUSWEIS => 'Ausweiskopie',
			self::WES     => 'Waffenerwerbsschein',
			self::STRAF   => 'Strafregisterauszug',
			self::JAGD    => 'Jagdschein',
		);
	}

	/**
	 * Suchbegriffe je Dokumentart (klein geschrieben, Umlaute vereinfacht). Reihenfolge = Vorrang.
	 *
	 * @return array
	 */
	public static function keywords() {
		return array(
			self::STRAF   => array( 'strafregister', 'registerauszug', 'casier', 'straf' ),
			self::WES     => array( 'waffenerwerb', 'erwerbsschein', 'erwerbsbewilligung', 'wes', 'wbs', 'permis d acquisition', 'pada' ),
			self::JAGD    => array( 'jagd', 'jagdschein', 'jagdpass', 'chasse' ),
			self::AUSWEIS => array( 'ausweis', 'identitaetskarte', 'identitatskarte', 'identitat', 'identitaet', 'identity', 'id karte', 'idkarte', 'id', 'idk', 'pass', 'reisepass', 'passport', 'carte d identite', 'fuehrerausweis', 'auslaenderausweis', 'aufenthaltsbewilligung' ),
		);
	}

	/** Dateiname vereinfachen: klein, Umlaute ersetzt, Trennzeichen zu Leerzeichen. */
	public static function normalize( $name ) {
		$name = (string) $name;
		$name = preg_replace( '/\.[a-z0-9]{2,5}$/i', '', $name );
		$name = function_exists( 'mb_strtolower' ) ? mb_strtolower( $name, 'UTF-8' ) : strtolower( $name );
		$name = strtr(
			$name,
			array(
				'ä' => 'ae', 'ö' => 'oe', 'ü' => 'ue', 'é' => 'e', 'è' => 'e', 'ê' => 'e', 'à' => 'a', 'â' => 'a', 'ç' => 'c', 'ß' => 'ss', 'ï' => 'i', 'ô' => 'o',
			)
		);
		$name = preg_replace( '/[^a-z0-9]+/', ' ', $name );
		return ' ' . trim( $name ) . ' ';
	}

	/**
	 * Dokumentart eines Dateinamens.
	 *
	 * @param string $name Dateiname.
	 * @return string|null
	 */
	public static function classify( $name ) {
		$n = self::normalize( $name );
		foreach ( self::keywords() as $type => $words ) {
			foreach ( $words as $w ) {
				// Kurze Wörter (id, wes, pass ...) nur als ganzes Wort, lange auch als Wortteil.
				if ( strlen( $w ) <= 4 ) {
					if ( false !== strpos( $n, ' ' . $w . ' ' ) ) {
						return $type;
					}
				} elseif ( false !== strpos( $n, $w ) ) {
					return $type;
				}
			}
		}
		return null;
	}

	/**
	 * Alle Daten im Dateinamen als Zeitstempel (00:00 UTC). Monat/Jahr ohne Tag = letzter Tag des Monats.
	 *
	 * @param string $name Dateiname.
	 * @return int[]
	 */
	public static function dates( $name ) {
		$s     = preg_replace( '/\.[a-z0-9]{2,5}$/i', '', (string) $name );
		$found = array();
		$used  = array();
		$add   = function ( $y, $m, $d, $start, $len ) use ( &$found, &$used ) {
			$y = (int) $y;
			if ( $y < 100 ) {
				$y += 2000;
			}
			$m = (int) $m;
			$d = (int) $d;
			if ( $y < 1990 || $y > 2100 || $m < 1 || $m > 12 ) {
				return;
			}
			if ( 0 === $d ) {
				$d = (int) gmdate( 't', gmmktime( 0, 0, 0, $m, 1, $y ) );
			}
			if ( ! checkdate( $m, $d, $y ) ) {
				return;
			}
			for ( $i = $start; $i < $start + $len; $i++ ) {
				$used[ $i ] = true;
			}
			$found[] = gmmktime( 0, 0, 0, $m, $d, $y );
		};
		$free = function ( $start, $len ) use ( &$used ) {
			for ( $i = $start; $i < $start + $len; $i++ ) {
				if ( isset( $used[ $i ] ) ) {
					return false;
				}
			}
			return true;
		};
		// 2026-11-28, 2026.11.28, 2026_11_28
		if ( preg_match_all( '/(?<!\d)(\d{4})[.\-_ ](\d{1,2})[.\-_ ](\d{1,2})(?!\d)/', $s, $m, PREG_OFFSET_CAPTURE | PREG_SET_ORDER ) ) {
			foreach ( $m as $x ) {
				$add( $x[1][0], $x[2][0], $x[3][0], $x[0][1], strlen( $x[0][0] ) );
			}
		}
		// 28.11.2026, 28-11-26
		if ( preg_match_all( '/(?<!\d)(\d{1,2})[.\-_ \/](\d{1,2})[.\-_ \/](\d{4}|\d{2})(?!\d)/', $s, $m, PREG_OFFSET_CAPTURE | PREG_SET_ORDER ) ) {
			foreach ( $m as $x ) {
				if ( $free( $x[0][1], strlen( $x[0][0] ) ) ) {
					$add( $x[3][0], $x[2][0], $x[1][0], $x[0][1], strlen( $x[0][0] ) );
				}
			}
		}
		// 20261128
		if ( preg_match_all( '/(?<!\d)((?:19|20)\d{2})(\d{2})(\d{2})(?!\d)/', $s, $m, PREG_OFFSET_CAPTURE | PREG_SET_ORDER ) ) {
			foreach ( $m as $x ) {
				if ( $free( $x[0][1], strlen( $x[0][0] ) ) ) {
					$add( $x[1][0], $x[2][0], $x[3][0], $x[0][1], strlen( $x[0][0] ) );
				}
			}
		}
		// 11.2026, 11-2026 (Monat/Jahr)
		if ( preg_match_all( '/(?<!\d)(\d{1,2})[.\-_ \/]((?:19|20)\d{2})(?!\d)/', $s, $m, PREG_OFFSET_CAPTURE | PREG_SET_ORDER ) ) {
			foreach ( $m as $x ) {
				if ( $free( $x[0][1], strlen( $x[0][0] ) ) ) {
					$add( $x[2][0], $x[1][0], 0, $x[0][1], strlen( $x[0][0] ) );
				}
			}
		}
		sort( $found );
		return $found;
	}

	/** Zeitstempel plus Monate. */
	public static function add_months( $ts, $months ) {
		return gmmktime( 0, 0, 0, (int) gmdate( 'n', $ts ) + $months, (int) gmdate( 'j', $ts ), (int) gmdate( 'Y', $ts ) );
	}

	/**
	 * Bewertet die Dateien eines Kunden.
	 *
	 * @param array $files Liste mit name (string), created (Zeitstempel oder 0).
	 * @param int   $now   Jetzt.
	 * @return array status (ok|pruefen|fehlt), docs (je Art: status, name, date, date_source, until, note), missing (Texte für die Kundenmail), unknown (Dateinamen ohne erkannte Art)
	 */
	public static function evaluate( array $files, $now ) {
		$today = gmmktime( 0, 0, 0, (int) gmdate( 'n', $now ), (int) gmdate( 'j', $now ), (int) gmdate( 'Y', $now ) );
		$by    = array();
		$unknown = array();
		foreach ( $files as $f ) {
			$type = self::classify( $f['name'] );
			if ( ! $type ) {
				$unknown[] = $f['name'];
				continue;
			}
			$dates = self::dates( $f['name'] );
			$by[ $type ][] = array(
				'name'    => $f['name'],
				'date'    => $dates ? end( $dates ) : 0,
				'created' => (int) ( $f['created'] ?? 0 ),
			);
		}

		$docs = array();

		// Ausweis: Datum im Namen = gültig bis. Bestes = spätestes Ablaufdatum.
		$docs[ self::AUSWEIS ] = array( 'status' => self::FEHLT, 'note' => 'keine Ausweiskopie beim bexio-Kontakt' );
		if ( ! empty( $by[ self::AUSWEIS ] ) ) {
			$list = $by[ self::AUSWEIS ];
			usort( $list, function ( $a, $b ) { return $b['date'] <=> $a['date'] ?: $b['created'] <=> $a['created']; } );
			$best = $list[0];
			if ( $best['date'] ) {
				$valid = $best['date'] >= $today;
				$docs[ self::AUSWEIS ] = array(
					'status' => $valid ? self::OK : self::FEHLT,
					'name'   => $best['name'],
					'until'  => $best['date'],
					'note'   => ( $valid ? 'gültig bis ' : 'abgelaufen am ' ) . gmdate( 'd.m.Y', $best['date'] ),
				);
			} else {
				$docs[ self::AUSWEIS ] = array(
					'status' => self::PRUEFEN,
					'name'   => $best['name'],
					'note'   => 'vorhanden, Ablaufdatum nicht im Dateinamen: bitte Gültigkeit von Hand prüfen',
				);
			}
		}

		// WES / Strafregister / Jagdschein: Datum im Namen = Ausstelldatum, sonst Upload-Datum.
		foreach ( array( self::WES => self::WES_MONATE, self::STRAF => self::STRAF_MONATE, self::JAGD => 0 ) as $type => $months ) {
			$docs[ $type ] = array( 'status' => self::FEHLT, 'note' => 'nicht vorhanden' );
			if ( empty( $by[ $type ] ) ) {
				continue;
			}
			$best = null;
			foreach ( $by[ $type ] as $d ) {
				$d['use']    = $d['date'] ?: $d['created'];
				$d['source'] = $d['date'] ? 'Datum im Dateinamen' : 'Upload-Datum';
				if ( ! $best || $d['use'] > $best['use'] ) {
					$best = $d;
				}
			}
			if ( ! $best['use'] ) {
				$docs[ $type ] = array( 'status' => self::PRUEFEN, 'name' => $best['name'], 'note' => 'vorhanden, Datum unbekannt: bitte von Hand prüfen' );
				continue;
			}
			if ( self::JAGD === $type ) {
				$docs[ $type ] = array( 'status' => self::PRUEFEN, 'name' => $best['name'], 'date' => $best['use'], 'note' => 'vorhanden (' . $best['source'] . ' ' . gmdate( 'd.m.Y', $best['use'] ) . '), Gültigkeit von Hand prüfen' );
				continue;
			}
			$until = self::add_months( $best['use'], $months );
			$valid = $until >= $today;
			$docs[ $type ] = array(
				'status'      => $valid ? self::OK : self::FEHLT,
				'name'        => $best['name'],
				'date'        => $best['use'],
				'date_source' => $best['source'],
				'until'       => $until,
				'note'        => sprintf( '%s %s, %s %s', $best['source'], gmdate( 'd.m.Y', $best['use'] ), $valid ? 'gültig bis' : 'zu alt seit', gmdate( 'd.m.Y', $until ) ),
			);
		}

		// Gesamt: Ausweis UND (WES oder Strafregister).
		$a     = $docs[ self::AUSWEIS ]['status'];
		$w     = $docs[ self::WES ]['status'];
		$s     = $docs[ self::STRAF ]['status'];
		$proof = ( self::OK === $w || self::OK === $s ) ? self::OK : ( ( self::PRUEFEN === $w || self::PRUEFEN === $s ) ? self::PRUEFEN : self::FEHLT );

		$missing = array();
		if ( self::OK !== $a ) {
			$missing[] = isset( $docs[ self::AUSWEIS ]['until'] ) ? 'eine Kopie deines gültigen Ausweises (die vorhandene Kopie ist abgelaufen)' : ( self::PRUEFEN === $a ? 'eine Kopie deines gültigen Ausweises (Pass oder Identitätskarte, Vorder- und Rückseite)' : 'eine Kopie deines Ausweises (Pass oder Identitätskarte, Vorder- und Rückseite)' );
		}
		if ( self::OK !== $proof ) {
			$missing[] = 'einen Waffenerwerbsschein (nicht älter als 2 Jahre, er darf noch leer sein) oder einen Strafregisterauszug (nicht älter als 3 Monate)';
		}

		if ( self::FEHLT === $a || self::FEHLT === $proof ) {
			$status = self::FEHLT;
		} elseif ( self::PRUEFEN === $a || self::PRUEFEN === $proof ) {
			$status = self::PRUEFEN;
		} else {
			$status = self::OK;
		}

		return array(
			'status'  => $status,
			'docs'    => $docs,
			'missing' => $missing,
			'unknown' => $unknown,
		);
	}
}
