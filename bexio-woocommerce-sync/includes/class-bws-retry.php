<?php
defined( 'ABSPATH' ) || exit;

/**
 * Automatische Wiederholung bei vorübergehenden bexio-Störungen (Wartung, Überlastung, Netzwerk).
 *
 * Statt den Hintergrund-Job endgültig scheitern zu lassen, wird er später erneut eingeplant:
 * nach 10 Min., 30 Min., 1, 2, 4 und 8 Stunden. Erst danach gilt er als fehlgeschlagen.
 */
class BWS_Retry {

	/** Wartezeiten in Sekunden bis zum nächsten Versuch. */
	const DELAYS = array( 600, 1800, 3600, 7200, 14400, 28800 );

	private static function key( $hook, array $args ) {
		return 'bws_retry_' . md5( $hook . wp_json_encode( array_values( $args ) ) );
	}

	/**
	 * Bisherige Wiederholungen dieses Jobs.
	 *
	 * @param string $hook Hook.
	 * @param array  $args Argumente.
	 * @return int
	 */
	public static function attempts( $hook, array $args ) {
		return (int) get_transient( self::key( $hook, $args ) );
	}

	/**
	 * Plant den Job erneut ein, wenn der Fehler vorübergehend ist und noch Versuche übrig sind.
	 *
	 * @param string        $hook Hook des Action Schedulers.
	 * @param array         $args Argumente.
	 * @param BWS_Exception $e    Fehler.
	 * @return int|false Zeitpunkt des nächsten Versuchs oder false (dann endgültig fehlgeschlagen).
	 */
	public static function later( $hook, array $args, BWS_Exception $e ) {
		if ( ! $e->is_temporary() || ! function_exists( 'as_schedule_single_action' ) ) {
			return false;
		}
		$attempt = self::attempts( $hook, $args );
		if ( $attempt >= count( self::DELAYS ) ) {
			self::reset( $hook, $args );
			return false;
		}
		$when = time() + self::DELAYS[ $attempt ];
		set_transient( self::key( $hook, $args ), $attempt + 1, 2 * DAY_IN_SECONDS );
		if ( ! as_has_scheduled_action( $hook, $args, BWS_AS_GROUP ) ) {
			as_schedule_single_action( $when, $hook, $args, BWS_AS_GROUP );
		}
		return $when;
	}

	/**
	 * Zähler nach Erfolg zurücksetzen.
	 *
	 * @param string $hook Hook.
	 * @param array  $args Argumente.
	 */
	public static function reset( $hook, array $args ) {
		delete_transient( self::key( $hook, $args ) );
	}
}
