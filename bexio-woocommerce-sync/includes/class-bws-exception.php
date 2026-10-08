<?php
defined( 'ABSPATH' ) || exit;

/**
 * Fehler bei der Kommunikation mit bexio oder beim Aufbereiten der Daten.
 */
class BWS_Exception extends Exception {

	/** @var mixed Antwort von bexio (falls vorhanden). */
	public $response_body;

	public function __construct( $message, $code = 0, $response_body = null ) {
		parent::__construct( $message, $code );
		$this->response_body = $response_body;
	}
}
