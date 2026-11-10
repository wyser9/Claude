/**
 * PLZ → Ort für Schweiz und Liechtenstein.
 *
 * - Block-Kasse/-Warenkorb (WooCommerce Blocks): Adresse im Store "wc/store/cart" beobachten und den Ort über
 *   setShippingAddress / setBillingAddress setzen (React-Felder lassen sich nicht direkt beschreiben).
 * - Klassische Formulare (Mein Konto → Adressen, Versandrechner, klassische Kasse): Felder direkt füllen.
 *
 * Ein vom Kunden selbst eingetragener Ort wird nie überschrieben – nur ein leerer oder zuvor automatisch
 * gesetzter Ort. Gibt es mehrere Orte zur PLZ, stehen sie im Ort-Feld zur Auswahl.
 */
( function () {
	'use strict';

	var cfg = window.armatumPlz || {};
	var data = null;
	var loading = null;
	var auto = {}; // Formular-Schlüssel -> zuletzt automatisch gesetzter Ort.

	function load() {
		if ( data ) {
			return Promise.resolve( data );
		}
		if ( ! loading ) {
			loading = fetch( cfg.url, { credentials: 'same-origin' } )
				.then( function ( r ) {
					return r.json();
				} )
				.then( function ( json ) {
					data = json || {};
					return data;
				} )
				.catch( function () {
					data = {};
					return data;
				} );
		}
		return loading;
	}

	function places( country, postcode ) {
		var plz = String( postcode || '' ).replace( /\s+/g, '' );
		var c = String( country || 'CH' ).toUpperCase();
		if ( ! /^\d{4}$/.test( plz ) || ( 'CH' !== c && 'LI' !== c ) || ! data || ! data[ c ] ) {
			return [];
		}
		// Liechtenstein gehört zum Schweizer PLZ-System: bei Bedarf im jeweils anderen Land nachsehen.
		return data[ c ][ plz ] || ( data[ 'CH' === c ? 'LI' : 'CH' ] || {} )[ plz ] || [];
	}

	// Auswahlliste (datalist) am Ort-Feld.
	function suggest( input, list ) {
		if ( ! input ) {
			return;
		}
		var id = 'armatum-plz-' + ( input.id || input.name || 'city' );
		var dl = document.getElementById( id );
		if ( list.length < 2 ) {
			if ( dl ) {
				dl.innerHTML = '';
			}
			return;
		}
		if ( ! dl ) {
			dl = document.createElement( 'datalist' );
			dl.id = id;
			document.body.appendChild( dl );
		}
		dl.innerHTML = '';
		list.forEach( function ( name ) {
			var o = document.createElement( 'option' );
			o.value = name;
			dl.appendChild( o );
		} );
		input.setAttribute( 'list', id );
		input.setAttribute( 'autocomplete', 'off' );
	}

	function shouldFill( key, current ) {
		return ! current || current === auto[ key ];
	}

	/* ------------------------------------------------------------ */
	/* Block-Kasse                                                   */
	/* ------------------------------------------------------------ */

	function initBlocks() {
		if ( ! window.wp || ! wp.data || ! wp.data.select || ! wp.data.select( 'wc/store/cart' ) ) {
			return false;
		}
		var store = wp.data.select( 'wc/store/cart' );
		var actions = wp.data.dispatch( 'wc/store/cart' );
		if ( ! store.getCustomerData || ! actions ) {
			return false;
		}
		var last = {};

		function handle( type, address ) {
			load().then( function () {
				var list = places( address.country, address.postcode );
				suggest( document.getElementById( type + '-city' ), list );
				if ( ! list.length ) {
					// Unbekannte PLZ: einen automatisch gesetzten Ort wieder leeren, er passt nicht mehr.
					if ( address.city && address.city === auto[ 'block-' + type ] ) {
						auto[ 'block-' + type ] = '';
						( 'shipping' === type ? actions.setShippingAddress : actions.setBillingAddress )( { city: '' } );
					}
					return;
				}
				if ( ! shouldFill( 'block-' + type, address.city ) ) {
					return;
				}
				auto[ 'block-' + type ] = list[ 0 ];
				var setter = 'shipping' === type ? actions.setShippingAddress : actions.setBillingAddress;
				if ( setter ) {
					var change = { city: list[ 0 ] };
					setter( change );
				}
			} );
		}

		wp.data.subscribe( function () {
			var customer = store.getCustomerData();
			if ( ! customer ) {
				return;
			}
			[ 'shipping', 'billing' ].forEach( function ( type ) {
				var address = customer[ type + 'Address' ];
				if ( ! address ) {
					return;
				}
				var key = ( address.country || '' ) + '|' + ( address.postcode || '' );
				if ( last[ type ] !== key ) {
					last[ type ] = key;
					handle( type, address );
				}
			} );
		}, 'wc/store/cart' );
		return true;
	}

	/* ------------------------------------------------------------ */
	/* Klassische Formulare                                          */
	/* ------------------------------------------------------------ */

	function field( prefix, name ) {
		return document.getElementById( prefix + '_' + name ) || document.querySelector( '[name="' + prefix + '_' + name + '"]' );
	}

	function classic( prefix ) {
		var postcode = field( prefix, 'postcode' );
		var city = field( prefix, 'city' );
		var country = field( prefix, 'country' );
		if ( ! postcode || ! city ) {
			return;
		}
		var countryCode = country && country.value ? country.value : 'CH';
		load().then( function () {
			var list = places( countryCode || 'CH', postcode.value );
			suggest( city, list );
			var value;
			if ( ! list.length ) {
				// Unbekannte PLZ: einen automatisch gesetzten Ort wieder leeren.
				if ( ! city.value || city.value !== auto[ prefix ] ) {
					return;
				}
				value = '';
			} else if ( shouldFill( prefix, city.value ) ) {
				value = list[ 0 ];
			} else {
				return;
			}
			auto[ prefix ] = value;
			city.value = value;
			city.dispatchEvent( new Event( 'input', { bubbles: true } ) );
			city.dispatchEvent( new Event( 'change', { bubbles: true } ) );
		} );
	}

	function onClassicInput( e ) {
		var t = e.target;
		if ( ! t || ! t.name ) {
			return;
		}
		var m = t.name.match( /^(billing|shipping|calc_shipping)_(postcode|country)$/ );
		if ( m ) {
			classic( m[ 1 ] );
		}
	}

	document.addEventListener( 'input', onClassicInput, true );
	document.addEventListener( 'change', onClassicInput, true );

	// Bereits vorausgefüllte Formulare (z. B. Mein Konto) beim Laden prüfen.
	function start() {
		[ 'billing', 'shipping', 'calc_shipping' ].forEach( function ( prefix ) {
			var p = field( prefix, 'postcode' );
			if ( p && p.value ) {
				classic( prefix );
			}
		} );
		if ( ! initBlocks() ) {
			// Block-Store wird teils erst später registriert.
			var tries = 0;
			var timer = setInterval( function () {
				if ( initBlocks() || ++tries > 20 ) {
					clearInterval( timer );
				}
			}, 500 );
		}
	}

	if ( 'loading' === document.readyState ) {
		document.addEventListener( 'DOMContentLoaded', start );
	} else {
		start();
	}
} )();
