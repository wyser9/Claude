<?php
defined( 'ABSPATH' ) || exit;

/**
 * Verzeichnis der bexio-Dateien: welche Datei hängt an welchem Kontakt.
 *
 * bexio liefert die Zuordnung Datei → Kontakt nur je Datei (GET 3.0/files/{id}/usage). Darum wird
 * stündlich die Dateiliste gelesen und für neue Dateien die Zuordnung einmal abgefragt und gespeichert.
 * Alles nur lesend, es wird nichts in bexio geändert.
 */
class AKD_Index {

	const OPTION     = 'akd_index';
	const OPTION_RUN = 'akd_index_run';
	const OPTION_RAW = 'akd_index_sample';
	const HOOK       = 'akd_index_refresh';
	const PAGE       = 100;
	const MAX_USAGE  = 150;

	public static function init() {
		add_action( self::HOOK, array( __CLASS__, 'refresh' ) );
		if ( ! wp_next_scheduled( self::HOOK ) ) {
			self::schedule();
		}
	}

	public static function schedule() {
		if ( ! wp_next_scheduled( self::HOOK ) ) {
			wp_schedule_event( time() + 60, 'hourly', self::HOOK );
		}
	}

	public static function unschedule() {
		wp_clear_scheduled_hook( self::HOOK );
	}

	/** @return array file_id => [n => Name, c => Upload-Zeitstempel, k => Kontakt-IDs, u => Zuordnung gelesen] */
	public static function all() {
		$i = get_option( self::OPTION, array() );
		return is_array( $i ) ? $i : array();
	}

	/** Ist die Zuordnung für alle bekannten Dateien gelesen? */
	public static function complete() {
		foreach ( self::all() as $f ) {
			if ( empty( $f['u'] ) ) {
				return false;
			}
		}
		$run = get_option( self::OPTION_RUN );
		return is_array( $run ) && ! empty( $run['time'] );
	}

	/**
	 * Dateien eines bexio-Kontakts.
	 *
	 * @param int $contact_id Kontakt-ID.
	 * @return array Liste mit id, name, created.
	 */
	public static function files_for( $contact_id ) {
		$out = array();
		foreach ( self::all() as $id => $f ) {
			if ( ! empty( $f['k'] ) && in_array( (int) $contact_id, $f['k'], true ) ) {
				$out[] = array(
					'id'      => (int) $id,
					'name'    => $f['n'],
					'created' => (int) $f['c'],
				);
			}
		}
		return $out;
	}

	/**
	 * Dateiliste lesen und fehlende Zuordnungen nachholen.
	 *
	 * @param int $max_usage Höchstzahl Zuordnungs-Abfragen in diesem Lauf.
	 * @return array Laufprotokoll.
	 */
	public static function refresh( $max_usage = self::MAX_USAGE ) {
		$run = array(
			'time'    => time(),
			'files'   => 0,
			'new'     => 0,
			'usage'   => 0,
			'open'    => 0,
			'error'   => '',
		);
		if ( ! class_exists( 'BWS_Client' ) ) {
			$run['error'] = 'bexio-Sync-Plugin ist nicht aktiv.';
			update_option( self::OPTION_RUN, $run, false );
			return $run;
		}
		$lock = 'akd_index_lock';
		if ( get_transient( $lock ) ) {
			$run['error'] = 'läuft bereits';
			return $run;
		}
		set_transient( $lock, 1, 10 * MINUTE_IN_SECONDS );

		$index = self::all();
		try {
			$client = BWS_Client::instance();
			$seen   = array();
			$offset = 0;
			do {
				$page = $client->get( '3.0/files', array( 'limit' => self::PAGE, 'offset' => $offset ) );
				$page = is_array( $page ) ? $page : array();
				foreach ( $page as $f ) {
					if ( empty( $f['id'] ) ) {
						continue;
					}
					$id          = (int) $f['id'];
					$seen[ $id ] = true;
					$name        = (string) ( $f['name'] ?? '' );
					if ( ! empty( $f['extension'] ) && ! preg_match( '/\.' . preg_quote( $f['extension'], '/' ) . '$/i', $name ) ) {
						$name .= '.' . $f['extension'];
					}
					$created = ! empty( $f['created_at'] ) ? (int) strtotime( $f['created_at'] ) : 0;
					if ( ! isset( $index[ $id ] ) ) {
						$index[ $id ] = array( 'n' => $name, 'c' => $created, 'k' => array(), 'u' => 0 );
						$run['new']++;
					} else {
						$index[ $id ]['n'] = $name; // Umbenennungen übernehmen.
					}
				}
				$offset += self::PAGE;
			} while ( count( $page ) === self::PAGE && $offset < 50000 );
			$run['files'] = count( $seen );

			// Gelöschte Dateien entfernen.
			foreach ( array_keys( $index ) as $id ) {
				if ( ! isset( $seen[ $id ] ) ) {
					unset( $index[ $id ] );
				}
			}

			// Zuordnung für neue Dateien (neueste zuerst).
			krsort( $index );
			foreach ( $index as $id => $f ) {
				if ( ! empty( $f['u'] ) ) {
					continue;
				}
				if ( $run['usage'] >= $max_usage ) {
					$run['open']++;
					continue;
				}
				$usage = $client->get( '3.0/files/' . (int) $id . '/usage' );
				$run['usage']++;
				if ( ! get_option( self::OPTION_RAW ) && ! empty( $usage ) ) {
					update_option( self::OPTION_RAW, self::mask( $usage ), false );
				}
				$index[ $id ]['k'] = self::contacts_from_usage( $usage );
				$index[ $id ]['u'] = time();
			}
		} catch ( Exception $e ) {
			$run['error'] = $e->getMessage();
			akd_log( 'error', 'Dateiverzeichnis: ' . $e->getMessage() );
		}
		delete_transient( $lock );
		update_option( self::OPTION, $index, false );
		update_option( self::OPTION_RUN, $run, false );
		return $run;
	}

	/**
	 * Kontakt-IDs aus der bexio-Antwort von /files/{id}/usage.
	 *
	 * @param mixed $usage Antwort (ein Objekt oder eine Liste).
	 * @return int[]
	 */
	public static function contacts_from_usage( $usage ) {
		if ( ! is_array( $usage ) || ! $usage ) {
			return array();
		}
		$list = isset( $usage[0] ) ? $usage : array( $usage );
		$ids  = array();
		foreach ( $list as $u ) {
			if ( ! is_array( $u ) ) {
				continue;
			}
			$class = strtolower( (string) ( $u['ref_class'] ?? $u['type'] ?? $u['source_type'] ?? '' ) );
			if ( false === strpos( $class, 'contact' ) ) {
				continue;
			}
			$id = $u['ref_id'] ?? $u['contact_id'] ?? $u['id'] ?? 0;
			if ( (int) $id > 0 ) {
				$ids[] = (int) $id;
			}
		}
		return array_values( array_unique( $ids ) );
	}

	/** Beispielantwort ohne Texte (nur Feldnamen und Zahlen) für die Fehlersuche speichern. */
	private static function mask( $data ) {
		if ( is_array( $data ) ) {
			$out = array();
			foreach ( $data as $k => $v ) {
				$out[ $k ] = ( 'ref_class' === $k || 'type' === $k ) ? $v : self::mask( $v );
			}
			return $out;
		}
		return is_string( $data ) ? '…' : $data;
	}
}
