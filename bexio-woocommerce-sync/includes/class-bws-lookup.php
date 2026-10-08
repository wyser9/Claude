<?php
defined( 'ABSPATH' ) || exit;

/**
 * Stammdaten aus bexio (Steuern, Länder, Währungen, Einheiten, Lager ...), zwischengespeichert als Transient.
 */
class BWS_Lookup {

	const CACHE_PREFIX = 'bws_lookup_';
	const CACHE_TTL    = 12 * HOUR_IN_SECONDS;

	/**
	 * Holt eine Liste aus bexio und cached sie.
	 *
	 * @param string   $key     Cache-Schlüssel.
	 * @param callable $fetcher Liefert die Daten.
	 * @return array
	 */
	private static function cached( $key, callable $fetcher ) {
		$cached = get_transient( self::CACHE_PREFIX . $key );
		if ( is_array( $cached ) ) {
			return $cached;
		}
		$data = $fetcher();
		$data = is_array( $data ) ? $data : array();
		set_transient( self::CACHE_PREFIX . $key, $data, self::CACHE_TTL );
		return $data;
	}

	/**
	 * Löscht alle zwischengespeicherten Stammdaten.
	 */
	public static function flush() {
		foreach ( array( 'me', 'taxes', 'countries', 'currencies', 'units', 'stocks', 'stock_places', 'accounts', 'contact_groups', 'languages' ) as $key ) {
			delete_transient( self::CACHE_PREFIX . $key );
		}
	}

	/**
	 * bexio-Benutzer-ID für user_id/owner_id (Einstellung oder Token-Inhaber).
	 *
	 * @return int
	 */
	public static function user_id() {
		$configured = BWS_Settings::id( 'user_id' );
		if ( $configured ) {
			return $configured;
		}
		$me = self::cached(
			'me',
			function () {
				return BWS_Client::instance()->get( '3.0/users/me' );
			}
		);
		if ( empty( $me['id'] ) ) {
			throw new BWS_Exception( 'bexio-Benutzer konnte nicht ermittelt werden. Bitte Benutzer-ID in den Einstellungen setzen.' );
		}
		return (int) $me['id'];
	}

	/**
	 * Aktive Umsatzsteuern.
	 *
	 * @return array
	 */
	public static function taxes() {
		return self::cached(
			'taxes',
			function () {
				return BWS_Client::instance()->get(
					'3.0/taxes',
					array(
						'scope' => 'active',
						'types' => 'sales_tax',
					)
				);
			}
		);
	}

	/**
	 * bexio Steuer-ID zu einem Steuersatz.
	 *
	 * @param float $rate Satz in Prozent.
	 * @return int
	 * @throws BWS_Exception Wenn keine passende Steuer existiert.
	 */
	public static function tax_id_for_rate( $rate ) {
		$id = BWS_Util::match_tax_id( $rate, self::taxes() );
		if ( null === $id ) {
			$id = BWS_Settings::id( 'default_tax_id' );
		}
		if ( ! $id ) {
			throw new BWS_Exception( sprintf( 'Keine aktive bexio-Umsatzsteuer mit %s %% gefunden und keine Standard-Steuer konfiguriert.', $rate ) );
		}
		return $id;
	}

	/**
	 * bexio Länder-ID zu ISO-Code (CH, DE, ...).
	 *
	 * @param string $iso ISO 3166 alpha-2.
	 * @return int|null
	 */
	public static function country_id( $iso ) {
		$iso = strtoupper( (string) $iso );
		if ( '' === $iso ) {
			return null;
		}
		$countries = self::cached(
			'countries',
			function () {
				return BWS_Client::instance()->get_all( '2.0/country' );
			}
		);
		foreach ( $countries as $country ) {
			$code = strtoupper( (string) ( $country['iso3166_alpha2'] ?? $country['name_short'] ?? '' ) );
			if ( $code === $iso ) {
				return (int) $country['id'];
			}
		}
		return null;
	}

	/**
	 * bexio Währungs-ID zu ISO-Code (CHF, EUR ...).
	 *
	 * @param string $code Währung.
	 * @return int|null
	 */
	public static function currency_id( $code ) {
		foreach ( self::currencies() as $currency ) {
			if ( strtoupper( (string) $currency['name'] ) === strtoupper( (string) $code ) ) {
				return (int) $currency['id'];
			}
		}
		return null;
	}

	public static function currencies() {
		return self::cached(
			'currencies',
			function () {
				return BWS_Client::instance()->get( '3.0/currencies' );
			}
		);
	}

	public static function units() {
		return self::cached(
			'units',
			function () {
				return BWS_Client::instance()->get_all( '2.0/unit' );
			}
		);
	}

	public static function stocks() {
		return self::cached(
			'stocks',
			function () {
				return BWS_Client::instance()->get_all( '2.0/stock' );
			}
		);
	}

	public static function stock_places() {
		return self::cached(
			'stock_places',
			function () {
				try {
					return BWS_Client::instance()->get_all( '2.0/stock_place' );
				} catch ( BWS_Exception $e ) {
					if ( 404 !== $e->getCode() ) {
						throw $e;
					}
					return BWS_Client::instance()->get_all( '2.0/stock_places' );
				}
			}
		);
	}

	public static function accounts() {
		return self::cached(
			'accounts',
			function () {
				return BWS_Client::instance()->get( '3.0/accounts', array( 'limit' => 2000 ) );
			}
		);
	}

	public static function contact_groups() {
		return self::cached(
			'contact_groups',
			function () {
				return BWS_Client::instance()->get_all( '2.0/contact_group' );
			}
		);
	}

	public static function languages() {
		return self::cached(
			'languages',
			function () {
				return BWS_Client::instance()->get_all( '2.0/language' );
			}
		);
	}
}
