<?php
defined( 'ABSPATH' ) || exit;

/**
 * Fehler bei der Kommunikation mit bexio oder beim Aufbereiten der Daten.
 */
class BWS_Exception extends Exception {

	/** @var mixed Antwort von bexio (falls vorhanden). */
	public $response_body;

	/** @var bool Vorübergehender Fehler (Wartung, Überlastung, Netzwerk) – später erneut versuchen. */
	public $temporary = false;

	public function __construct( $message, $code = 0, $response_body = null, $temporary = false ) {
		parent::__construct( $message, $code );
		$this->response_body = $response_body;
		$this->temporary     = (bool) $temporary;
	}

	/**
	 * Lohnt sich ein späterer neuer Versuch?
	 *
	 * @return bool
	 */
	public function is_temporary() {
		return $this->temporary;
	}
}
