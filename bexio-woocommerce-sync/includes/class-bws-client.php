<?php
defined( 'ABSPATH' ) || exit;

/**
 * Schlanker HTTP-Client für die bexio REST API (https://api.bexio.com).
 *
 * Authentifizierung per Personal Access Token (bexio > Einstellungen > Sicherheit > API-Tokens)
 * oder einem OAuth2 Access Token. Pfade werden inkl. Version angegeben, z.B. "2.0/article".
 */
class BWS_Client {

	const BASE_URL    = 'https://api.bexio.com/';
	const MAX_RETRIES = 3;

	/** @var string */
	private $token;

	/** @var BWS_Client|null */
	private static $instance;

	public function __construct( $token ) {
		$this->token = trim( (string) $token );
	}

	/**
	 * Client mit dem gespeicherten Token.
	 *
	 * @return BWS_Client
	 * @throws BWS_Exception Wenn kein Token hinterlegt ist.
	 */
	public static function instance() {
		$token = BWS_Settings::get( 'api_token' );
		if ( empty( $token ) ) {
			throw new BWS_Exception( 'Kein bexio API-Token hinterlegt (WooCommerce > bexio Sync).' );
		}
		if ( ! self::$instance || self::$instance->token !== $token ) {
			self::$instance = new self( $token );
		}
		return self::$instance;
	}

	public function get( $path, array $query = array() ) {
		return $this->request( 'GET', $path, $query );
	}

	public function post( $path, $body = array(), array $query = array() ) {
		return $this->request( 'POST', $path, $query, $body );
	}

	/**
	 * Lädt alle Einträge einer paginierten 2.0-Liste.
	 *
	 * @param string $path  Pfad.
	 * @param array  $query Zusätzliche Parameter.
	 * @return array
	 */
	public function get_all( $path, array $query = array() ) {
		$limit  = 500;
		$offset = 0;
		$all    = array();
		do {
			$page   = $this->get( $path, array_merge( $query, array( 'limit' => $limit, 'offset' => $offset ) ) );
			$page   = is_array( $page ) ? $page : array();
			$all    = array_merge( $all, $page );
			$offset += $limit;
		} while ( count( $page ) === $limit && $offset < 20000 );
		return $all;
	}

	/**
	 * Suche über einen 2.0-Search-Endpoint, z.B. "2.0/contact/search".
	 *
	 * @param string $path     Pfad.
	 * @param string $field    Feld.
	 * @param mixed  $value    Wert.
	 * @param string $criteria Vergleich (=, like, ...).
	 * @return array
	 */
	public function search( $path, $field, $value, $criteria = '=' ) {
		$result = $this->post(
			$path,
			array(
				array(
					'field'    => $field,
					'value'    => $value,
					'criteria' => $criteria,
				),
			),
			array( 'limit' => 10 )
		);
		return is_array( $result ) ? $result : array();
	}

	/**
	 * Führt eine Anfrage aus. Bei 429 (Rate Limit) und 5xx wird automatisch wiederholt.
	 *
	 * @param string     $method HTTP-Methode.
	 * @param string     $path   Pfad inkl. Version.
	 * @param array      $query  Query-Parameter.
	 * @param array|null $body   JSON-Body.
	 * @return mixed Dekodierte JSON-Antwort.
	 * @throws BWS_Exception Bei Fehlern.
	 */
	public function request( $method, $path, array $query = array(), $body = null ) {
		$url = self::BASE_URL . ltrim( $path, '/' );
		if ( $query ) {
			$url = add_query_arg( $query, $url );
		}

		$args = array(
			'method'  => $method,
			'timeout' => 30,
			'headers' => array(
				'Accept'        => 'application/json',
				'Authorization' => 'Bearer ' . $this->token,
			),
		);
		if ( null !== $body ) {
			$args['headers']['Content-Type'] = 'application/json';
			$args['body']                    = wp_json_encode( $body );
		}

		for ( $attempt = 0; ; $attempt++ ) {
			$response = wp_remote_request( $url, $args );

			if ( is_wp_error( $response ) ) {
				if ( $attempt < self::MAX_RETRIES ) {
					sleep( 2 ** $attempt );
					continue;
				}
				throw new BWS_Exception( sprintf( 'bexio nicht erreichbar (%s %s): %s', $method, $path, $response->get_error_message() ) );
			}

			$status = (int) wp_remote_retrieve_response_code( $response );
			$raw    = wp_remote_retrieve_body( $response );

			if ( ( 429 === $status || $status >= 500 ) && $attempt < self::MAX_RETRIES ) {
				$wait = (int) wp_remote_retrieve_header( $response, 'retry-after' );
				sleep( min( max( $wait, 2 ** $attempt ), 30 ) );
				continue;
			}
			break;
		}

		$data = json_decode( $raw, true );

		if ( $status < 200 || $status >= 300 ) {
			$detail = is_array( $data ) ? ( $data['message'] ?? wp_json_encode( $data ) ) : $raw;
			if ( is_array( $data ) && ! empty( $data['errors'] ) ) {
				$detail .= ' ' . wp_json_encode( $data['errors'] );
			}
			$hint = '';
			if ( 401 === $status ) {
				$hint = ' – API-Token ungültig oder abgelaufen.';
			} elseif ( 403 === $status ) {
				$hint = ' – fehlende Berechtigung (Scope) für diesen Bereich.';
			}
			throw new BWS_Exception( sprintf( 'bexio-Fehler %d bei %s %s: %s%s', $status, $method, $path, $detail, $hint ), $status, $data );
		}

		return $data;
	}
}
