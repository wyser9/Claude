<?php
/**
 * REST-Schnittstelle (Namespace ak/v1) zum Einrichten vieler Produkte, z.B. durch einen Assistenten.
 * Nur für Benutzer mit manage_woocommerce.
 *
 * GET  /ak/v1/products  Liste mit Status (Filter: role, category, status=offen|geprueft|fehler|alle, page, per_page)
 * POST /ak/v1/setup     { ids:[], set:{feld:wert}, measure:true, overwrite:false, dry:false } → Felder setzen und einmessen
 * POST /ak/v1/check     { ids:[], checked:true } → als geprüft markieren
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class AK_Rest {

	/** Felder, die über /setup gesetzt werden dürfen. */
	const SETTABLE = array( 'role', 'gruppe', 'direkt', 'schnittstelle', 'ausgang', 'laenge', 'mittelrohr', 'hinweis', 'freigestellt', 'flip', 'image_id', 'freiraum' );

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
				'hersteller'   => $p->get_attribute( 'pa_hersteller' ),
			) : array(),
			'sku'      => $p ? $p->get_sku() : '',
		);
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
			if ( ! in_array( $k, self::SETTABLE, true ) ) {
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
			if ( in_array( $k, array( 'schnittstelle', 'ausgang' ), true ) ) {
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
