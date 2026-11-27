<?php
/**
 * REST-Schnittstelle (Namespace ak/v1) zum Einrichten vieler Produkte, z.B. durch einen Assistenten.
 * Nur für Benutzer mit manage_woocommerce.
 *
 * GET  /ak/v1/products  Liste mit Status (Filter: role, category, status=offen|geprueft|fehler|alle, page, per_page)
 * POST /ak/v1/setup     { ids:[], set:{feld:wert}, measure:true, overwrite:false, dry:false } → Felder setzen und einmessen
 *                      Punkte (anchor, p1, p2, tube_anchor, bottom, rail_*, slot_*_anchor) als «x,y» in Bildpixeln.
 *                      Für Waffen auch Steckplätze slot_{muendung|unten|seite|vorne|schaft|griff}_if und _anchor.
 * POST /ak/v1/check     { ids:[], checked:true } → als geprüft markieren
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class AK_Rest {

	/** Felder, die über /setup gesetzt werden dürfen. */
	const SETTABLE = array( 'role', 'gruppe', 'direkt', 'schnittstelle', 'ausgang', 'laenge', 'mittelrohr', 'hinweis', 'freigestellt', 'flip', 'image_id', 'freiraum', 'montage_art', 'achshoehe', 'rail_front', 'rail_rear',
		'anchor', 'p1', 'p2', 'ref_mm', 'tube_anchor', 'bottom', 'muzzle_device_if', 'muzzle_device_anchor', 'gesperrt', 'waffenart', 'plattform', 'kaliber',
		'funktion', 'laenge_ohne_schaft', 'magazin', 'schaft_art', 'brace', 'laenge_eingeklappt', 'eingeklappt_funktion', 'laser', 'magazin_plus' );

	/** Erlaubte Felder inkl. Steckplätze der Waffe. */
	public static function settable() {
		$list = self::SETTABLE;
		foreach ( array_keys( AK_Data::SLOTS ) as $slot ) {
			$list[] = 'slot_' . $slot . '_if';
			$list[] = 'slot_' . $slot . '_anchor';
		}
		return $list;
	}

	/** Ist das Feld ein Bildpunkt «x,y»? */
	public static function is_point_field( $k ) {
		return in_array( $k, array( 'anchor', 'p1', 'p2', 'tube_anchor', 'bottom', 'muzzle_device_anchor', 'rail_front', 'rail_rear' ), true ) || (bool) preg_match( '/^slot_.+_anchor$/', $k );
	}

	public static function init() {
		add_action( 'rest_api_init', array( __CLASS__, 'routes' ) );
	}

	public static function can() {
		return current_user_can( 'manage_woocommerce' );
	}

	public static function routes() {
		register_rest_route(
			'ak/v1',
			'/products',
			array(
				'methods'             => 'GET',
				'callback'            => array( __CLASS__, 'products' ),
				'permission_callback' => array( __CLASS__, 'can' ),
			)
		);
		register_rest_route(
			'ak/v1',
			'/setup',
			array(
				'methods'             => 'POST',
				'callback'            => array( __CLASS__, 'setup' ),
				'permission_callback' => array( __CLASS__, 'can' ),
			)
		);
		register_rest_route(
			'ak/v1',
			'/check',
			array(
				'methods'             => 'POST',
				'callback'            => array( __CLASS__, 'check' ),
				'permission_callback' => array( __CLASS__, 'can' ),
			)
		);
	}

	/** Statuszeile eines Produkts (auch für die Prüfliste). */
	public static function status( $id ) {
		$p    = wc_get_product( $id );
		$role = AK_Data::role( $id );
		$g    = AK_Data::group( $id );
		$warn = array_filter( explode( ' ', (string) get_post_meta( $id, '_ak_warn', true ) ) );
		return array(
			'id'       => $id,
			'name'     => $p ? $p->get_name() : get_the_title( $id ),
			'type'     => $p ? $p->get_type() : '',
			'status'   => get_post_status( $id ),
			'role'     => $role,
			'gruppe'   => $g,
			'direkt'   => AK_Data::is_direct( $id ),
			'ifs'      => AK_Data::interfaces( $id ),
			'outs'     => AK_Data::outputs( $id ),
			'tube'     => AK_Data::tube( $id ),
			'montage_art' => 'montage' === $role ? AK_Data::mount_type( $id ) : '',
			'achshoehe'   => AK_Data::own_axis( $id ),
			'achshoehe_gemessen' => 'montage' === $role ? round( AK_Data::measured_axis_height( $id ), 1 ) : 0,
			'waffenart'   => 'waffe' === $role ? AK_Data::weapon_type( $id ) : ( in_array( $g, AK_Data::TYPED_GROUPS, true ) ? AK_Data::part_weapon_type( $id ) : ( in_array( $g, AK_Data::LONG_ONLY_GROUPS, true ) ? 'langwaffe' : ( 'schalldaempfer' === $g && AK_Data::suppressor_short_classes( $id ) ? 'pistole' : '' ) ) ),
			'kaliber_klasse' => in_array( $role, array( 'waffe', 'zubehoer' ), true ) ? AK_Data::caliber_classes( $id ) : array(),
			'sd_fuer'     => 'schalldaempfer' === $g ? AK_Data::suppressor_short_classes( $id ) : array(),
			'platzhalter' => AK_Data::shows_placeholder( $id ),
			'erwerb'      => self::erwerb_status( $id, $role, $g ),
			'plattform'   => AK_Data::platforms( $id ),
			'kaliber_mm'  => AK_Data::caliber_mm( $id ),
			'slots'       => 'waffe' === $role ? self::slots( $id ) : array(),
			'points'      => self::points( $id ),
			'axis_height' => 'montage' === $role ? round( AK_Data::axis_height( $id ), 1 ) : 0,
			'laenge'   => AK_Auto::length( $id ),
			'image_id' => AK_Data::image_id( $id ),
			'mmPerPx'  => round( AK_Data::scale( $id ), 4 ),
			'ready'    => AK_Data::is_ready( $id ),
			'warn'     => array_values( $warn ),
			'checked'  => (string) get_post_meta( $id, '_ak_checked', true ),
			'cats'     => wp_get_post_terms( $id, 'product_cat', array( 'fields' => 'names' ) ),
			'attrs'    => $p ? array(
				'montage'      => $p->get_attribute( 'pa_montage' ),
				'mittelrohr'   => $p->get_attribute( 'pa_mittelrohrdurchmesser' ),
				'gewinde'      => $p->get_attribute( 'pa_muendungsgewinde' ),
				'hub_gewinde'  => $p->get_attribute( 'pa_hub-gewinde' ),
				'laenge'       => $p->get_attribute( 'pa_laenge' ),
				'objektiv'     => $p->get_attribute( 'pa_objektivdurchmesser' ),
				'kaliber'      => $p->get_attribute( 'pa_kaliber' ),
				'hersteller'   => $p->get_attribute( 'pa_hersteller' ),
			) : array(),
			'sku'      => $p ? $p->get_sku() : '',
		);
	}

	/** Angaben für die Erwerbs-Zusammenfassung (ab 1.0.9), damit Ruedi fehlende Längen findet. */
	public static function erwerb_status( $id, $role, $g ) {
		$out = array( 'text' => AK_Erwerb::product_text( $id ) );
		if ( 'waffe' === $role ) {
			$len                       = AK_Erwerb::length_without_stock( $id );
			$out['funktion']           = AK_Erwerb::function_type( $id );
			$out['magazin']            = AK_Erwerb::magazine( $id );
			$out['laenge_ohne_schaft'] = round( $len[0] );
			$out['laenge_geschaetzt']  = $len[1];
			$out['laenge_eingeklappt'] = (string) AK_Data::meta( $id, 'laenge_eingeklappt' );
			$out['schaft_art']         = (string) AK_Data::meta( $id, 'schaft_art' );
		} elseif ( 'schaft' === $g ) {
			$f                         = AK_Erwerb::part_facts( $id );
			$out['schaft_art']         = $f['schaft_art'];
			$out['brace']              = $f['brace'];
			$out['laenge_eingeklappt'] = $f['laenge_eingeklappt'];
		} elseif ( in_array( $g, array( 'lampe', 'laser' ), true ) ) {
			$out['laser'] = AK_Erwerb::is_laser( $id );
		}
		return $out;
	}

	/** Steckplätze der Waffe: Schnittstellen (auch aus Attribut) und Punkt. */
	public static function slots( $id ) {
		$out = array();
		foreach ( array_keys( AK_Data::SLOTS ) as $slot ) {
			$s = AK_Data::weapon_slot( $id, $slot );
			$out[ $slot ] = array(
				'if'     => $s ? $s['interfaces'] : AK_Data::split( AK_Data::meta( $id, 'slot_' . $slot . '_if' ) ),
				'anchor' => $s ? $s['anchor'] : null,
			);
		}
		return $out;
	}

	/** Gesetzte Bildpunkte und Bildgrösse (zum Setzen weiterer Punkte per REST). */
	public static function points( $id ) {
		$out = array();
		foreach ( array( 'p1', 'p2', 'anchor', 'tube_anchor', 'bottom', 'rail_front', 'rail_rear', 'muzzle_device_anchor' ) as $k ) {
			$pt = AK_Data::point( $id, $k );
			if ( $pt ) {
				$out[ $k ] = $pt;
			}
		}
		$src = wp_get_attachment_image_src( AK_Data::image_id( $id ), 'full' );
		if ( $src ) {
			$out['img'] = array( 'url' => $src[0], 'w' => (int) $src[1], 'h' => (int) $src[2] );
		}
		return $out;
	}

	public static function products( WP_REST_Request $r ) {
		$args = array(
			'post_type'      => 'product',
			'post_status'    => array( 'publish', 'draft', 'private', 'pending' ),
			'posts_per_page' => min( 100, max( 1, (int) ( $r['per_page'] ?: 50 ) ) ),
			'paged'          => max( 1, (int) $r['page'] ),
			'fields'         => 'ids',
			'orderby'        => 'title',
			'order'          => 'ASC',
		);
		if ( $r['category'] ) {
			$args['tax_query'] = array( // phpcs:ignore WordPress.DB.SlowDBQuery
				array(
					'taxonomy' => 'product_cat',
					'field'    => is_numeric( $r['category'] ) ? 'term_id' : 'slug',
					'terms'    => array_map( 'trim', explode( ',', (string) $r['category'] ) ),
				),
			);
		}
		$meta = array();
		if ( $r['role'] ) {
			$meta[] = array( 'key' => '_ak_role', 'value' => sanitize_key( $r['role'] ) );
		} elseif ( ! $r['category'] ) {
			$meta[] = array( 'key' => '_ak_role', 'compare' => 'EXISTS' );
		}
		if ( 'geprueft' === $r['status'] ) {
			$meta[] = array( 'key' => '_ak_checked', 'compare' => 'EXISTS' );
		} elseif ( 'offen' === $r['status'] ) {
			$meta[] = array( 'key' => '_ak_checked', 'compare' => 'NOT EXISTS' );
		} elseif ( 'fehler' === $r['status'] ) {
			$meta[] = array( 'key' => '_ak_warn', 'compare' => 'EXISTS' );
		}
		if ( $meta ) {
			$args['meta_query'] = $meta; // phpcs:ignore WordPress.DB.SlowDBQuery
		}
		$q = new WP_Query( $args );
		return array(
			'total' => (int) $q->found_posts,
			'pages' => (int) $q->max_num_pages,
			'items' => array_map( array( __CLASS__, 'status' ), array_map( 'intval', $q->posts ) ),
		);
	}

	/** Felder setzen (gleiche Normalisierung wie im Produkt-Editor). */
	public static function set_fields( $id, $set ) {
		foreach ( (array) $set as $k => $v ) {
			if ( ! in_array( $k, self::settable(), true ) ) {
				continue;
			}
			$v = is_bool( $v ) ? ( $v ? 'yes' : 'no' ) : sanitize_text_field( (string) $v );
			if ( in_array( $k, array( 'direkt', 'freigestellt', 'flip' ), true ) ) {
				$v = 'yes' === $v || '1' === $v ? 'yes' : 'no';
			}
			if ( 'role' === $k && ! in_array( $v, AK_Data::ROLES, true ) ) {
				$v = '';
			}
			if ( 'gruppe' === $k && ! isset( AK_Data::GROUPS[ $v ] ) ) {
				$v = '';
			}
			if ( 'montage_art' === $k && ! isset( AK_Data::MOUNT_TYPES[ $v ] ) ) {
				$v = '';
			}
			if ( 'waffenart' === $k && ! isset( AK_Data::WEAPON_TYPES[ $v ] ) && ! isset( AK_Data::PART_WEAPON_TYPES[ $v ] ) ) {
				$v = '';
			}
			if ( 'funktion' === $k && ! isset( AK_Erwerb::FUNKTIONEN[ $v ] ) ) {
				$v = '';
			}
			if ( 'schaft_art' === $k && ! isset( AK_Erwerb::SCHAFT_ARTEN[ $v ] ) ) {
				$v = '';
			}
			if ( in_array( $k, array( 'brace', 'laser' ), true ) && ! in_array( $v, array( 'yes', 'no' ), true ) ) {
				$v = '';
			}
			if ( 'eingeklappt_funktion' === $k && 'nein' !== $v ) {
				$v = '';
			}
			if ( in_array( $k, array( 'laenge_ohne_schaft', 'magazin', 'laenge_eingeklappt', 'magazin_plus' ), true ) ) {
				$v = preg_match( '/\d+([.,]\d+)?/', $v, $num ) ? str_replace( ',', '.', $num[0] ) : '';
			}
			if ( 'plattform' === $k ) {
				$v = implode( ', ', array_intersect( array_map( 'trim', explode( ',', strtolower( $v ) ) ), array_keys( AK_Data::PLATFORMS ) ) );
			}
			if ( 'gesperrt' === $k ) {
				$v = implode( ',', array_filter( array_map( 'absint', explode( ',', $v ) ) ) );
			}
			if ( self::is_point_field( $k ) && ! preg_match( '/^-?\d+(\.\d+)?,-?\d+(\.\d+)?$/', $v ) ) {
				$v = '';
			}
			if ( in_array( $k, array( 'schnittstelle', 'ausgang', 'muzzle_device_if' ), true ) || preg_match( '/^slot_.+_if$/', $k ) ) {
				$v = implode( ', ', AK_Data::split( $v ) );
			}
			if ( '' === $v ) {
				delete_post_meta( $id, '_ak_' . $k );
			} else {
				update_post_meta( $id, '_ak_' . $k, $v );
			}
		}
	}

	public static function setup( WP_REST_Request $r ) {
		$ids     = array_filter( array_map( 'absint', (array) $r['ids'] ) );
		$dry     = ! empty( $r['dry'] );
		$measure = null === $r['measure'] ? true : (bool) $r['measure'];
		$out     = array();
		foreach ( array_slice( $ids, 0, 50 ) as $id ) {
			if ( 'product' !== get_post_type( $id ) ) {
				$out[] = array( 'id' => $id, 'status' => 'error', 'msg' => 'Kein Produkt.' );
				continue;
			}
			if ( ! $dry && $r['set'] ) {
				self::set_fields( $id, $r['set'] );
			}
			$res   = $measure ? AK_Auto::process( $id, ! empty( $r['overwrite'] ), $dry ) : array( 'id' => $id, 'status' => 'ok', 'msg' => 'Felder gesetzt.' );
			$out[] = array_merge( $res, array( 'state' => self::status( $id ) ) );
		}
		return array( 'results' => $out );
	}

	public static function check( WP_REST_Request $r ) {
		$ids = array_filter( array_map( 'absint', (array) $r['ids'] ) );
		foreach ( $ids as $id ) {
			if ( false === $r['checked'] ) {
				delete_post_meta( $id, '_ak_checked' );
			} else {
				update_post_meta( $id, '_ak_checked', gmdate( 'Y-m-d' ) );
			}
		}
		return array( 'updated' => count( $ids ) );
	}
}
