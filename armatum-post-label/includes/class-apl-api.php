<?php
defined( 'ABSPATH' ) || exit;

/**
 * Digital Commerce API der Schweizerischen Post (OAuth, Adressprüfung, Barcode/Label).
 */
class APL_Api {

	const TOKEN_URL   = 'https://api.post.ch/OAuth/token';
	const LABEL_URL   = 'https://dcapi.apis.post.ch/barcode/v1/generateAddressLabel';
	const ADDRESS_URL = 'https://dcapi.apis.post.ch/address/v1/addresses/validation';
	const TOKEN_CACHE = 'apl_access_token';

	/**
	 * Access Token (zwischengespeichert bis kurz vor Ablauf).
	 *
	 * @return string
	 * @throws Exception Bei fehlenden Zugangsdaten oder Fehlern.
	 */
	public static function token() {
		$cached = get_transient( self::TOKEN_CACHE );
		if ( $cached ) {
			return $cached;
		}
		$client_id     = trim( (string) get_option( 'armatum_post_client_id' ) );
		$client_secret = trim( (string) get_option( 'armatum_post_client_secret' ) );
		if ( '' === $client_id || '' === $client_secret ) {
			throw new Exception( 'Client ID / Client Secret fehlen (Einstellungen > Armatum Post Label).' );
		}

		$response = wp_remote_post(
			self::TOKEN_URL,
			array(
				'timeout' => 30,
				'body'    => array(
					'grant_type'    => 'client_credentials',
					'client_id'     => $client_id,
					'client_secret' => $client_secret,
					'scope'         => 'DCAPI_BARCODE_READ DCAPI_ADDRESS_VALIDATE',
				),
			)
		);
		if ( is_wp_error( $response ) ) {
			throw new Exception( 'Post nicht erreichbar: ' . $response->get_error_message() );
		}
		$body = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( empty( $body['access_token'] ) ) {
			throw new Exception( 'Kein Token von der Post erhalten (HTTP ' . wp_remote_retrieve_response_code( $response ) . '). Zugangsdaten prüfen.' );
		}
		$ttl = isset( $body['expires_in'] ) ? max( 60, (int) $body['expires_in'] - 60 ) : 300;
		set_transient( self::TOKEN_CACHE, $body['access_token'], $ttl );
		return $body['access_token'];
	}

	/**
	 * POST mit JSON an die DCAPI.
	 *
	 * @param string $url     URL.
	 * @param array  $payload Daten.
	 * @return array{status:int,body:mixed,raw:string}
	 * @throws Exception Bei Verbindungsfehlern.
	 */
	private static function post_json( $url, array $payload ) {
		$response = wp_remote_post(
			$url,
			array(
				'timeout' => 60,
				'headers' => array(
					'Authorization' => 'Bearer ' . self::token(),
					'Content-Type'  => 'application/json',
					'Accept'        => 'application/json',
					'client_id'     => trim( (string) get_option( 'armatum_post_client_id' ) ),
				),
				'body'    => wp_json_encode( $payload ),
			)
		);
		if ( is_wp_error( $response ) ) {
			throw new Exception( 'Post nicht erreichbar: ' . $response->get_error_message() );
		}
		$status = (int) wp_remote_retrieve_response_code( $response );
		if ( 401 === $status ) {
			delete_transient( self::TOKEN_CACHE );
		}
		$raw = (string) wp_remote_retrieve_body( $response );
		return array(
			'status' => $status,
			'body'   => json_decode( $raw, true ),
			'raw'    => $raw,
		);
	}

	/**
	 * Prüft eine Schweizer Adresse.
	 *
	 * @param array $recipient Empfänger (firstName, lastName, street, houseNo, zip, city).
	 * @return array{usable:bool,quality:string,message:string}
	 */
	public static function validate_address( array $recipient ) {
		try {
			$result = self::post_json(
				self::ADDRESS_URL,
				array(
					'type'             => 'DOMICILE',
					'addressee'        => array(
						'firstName' => $recipient['firstName'],
						'lastName'  => $recipient['lastName'],
					),
					'logisticLocation' => array(
						'house' => array(
							'street'      => $recipient['street'],
							'houseNumber' => $recipient['houseNo'],
						),
						'zip'   => array(
							'zip'  => $recipient['zip'],
							'city' => $recipient['city'],
						),
					),
					'fullValidation'   => false,
				)
			);
		} catch ( Exception $e ) {
			return array( 'usable' => true, 'quality' => 'UNKNOWN', 'message' => 'Adressprüfung nicht möglich: ' . $e->getMessage() );
		}

		if ( 200 === $result['status'] || 201 === $result['status'] ) {
			$quality = (string) ( $result['body']['quality'] ?? 'UNKNOWN' );
			return array(
				'usable'  => 'UNUSABLE' !== $quality,
				'quality' => $quality,
				'message' => 'UNUSABLE' === $quality ? 'Die Post kennt diese Adresse nicht (Qualität UNUSABLE).' : 'Adressprüfung: ' . $quality,
			);
		}
		// Prüfung selbst fehlgeschlagen -> Label nicht blockieren, aber Hinweis.
		return array( 'usable' => true, 'quality' => 'UNKNOWN', 'message' => 'Adressprüfung nicht möglich (HTTP ' . $result['status'] . ').' );
	}

	/**
	 * Erstellt ein Adresslabel.
	 *
	 * @param array $payload Daten für generateAddressLabel.
	 * @return array{ident_code:string,pdf:string}
	 * @throws Exception Bei Fehlern.
	 */
	public static function generate_label( array $payload ) {
		$result = self::post_json( self::LABEL_URL, $payload );
		if ( 200 !== $result['status'] ) {
			$message = is_array( $result['body'] ) ? wp_json_encode( $result['body'], JSON_UNESCAPED_UNICODE ) : $result['raw'];
			throw new Exception( 'Post-Fehler HTTP ' . $result['status'] . ': ' . substr( $message, 0, 1500 ) );
		}
		$item  = $result['body']['item'] ?? array();
		$label = $item['label'][0] ?? '';
		if ( ! empty( $item['errors'] ) ) {
			throw new Exception( 'Post meldet Fehler: ' . wp_json_encode( $item['errors'], JSON_UNESCAPED_UNICODE ) );
		}
		if ( '' === $label ) {
			throw new Exception( 'Die Post hat kein Label-PDF geliefert.' );
		}
		$pdf = base64_decode( $label, true );
		if ( false === $pdf ) {
			throw new Exception( 'Label-PDF der Post ist ungültig.' );
		}
		return array(
			'ident_code' => (string) ( $item['identCode'] ?? '' ),
			'pdf'        => $pdf,
		);
	}
}
