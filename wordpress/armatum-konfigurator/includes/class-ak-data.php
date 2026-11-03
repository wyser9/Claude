<?php
/**
 * Konfigurator-Daten pro Produkt und Kompatibilitätsregeln.
 *
 * Rollen:
 *  - waffe:   hat Schnittstellen (z.B. picatinny) und einen Ankerpunkt auf der Schiene.
 *  - montage: sitzt auf einer Schnittstelle, hat Ringe für ein Mittelrohr (mm) und eine Rohrmitte.
 *  - optik:   entweder "direkt" auf eine Schnittstelle (Rotpunkt, Optik mit integrierter Montage)
 *             oder mit Mittelrohr, dann braucht sie eine passende Montage.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class AK_Data {

	const ROLES = array( 'waffe', 'montage', 'optik' );

	/** Liest einen Meta-Wert mit Präfix _ak_. */
	public static function meta( $product_id, $key, $default = '' ) {
		$value = get_post_meta( $product_id, '_ak_' . $key, true );
		return ( '' === $value || null === $value ) ? $default : $value;
	}

	public static function role( $product_id ) {
		$role = self::meta( $product_id, 'role' );
		return in_array( $role, self::ROLES, true ) ? $role : '';
	}

	/** "x,y" -> array(x, y) als float oder null. */
	public static function point( $product_id, $key ) {
		$raw = self::meta( $product_id, $key );
		if ( ! is_string( $raw ) || false === strpos( $raw, ',' ) ) {
			return null;
		}
		list( $x, $y ) = array_map( 'floatval', explode( ',', $raw, 2 ) );
		return array( $x, $y );
	}

	/** Liste von Schnittstellen in Kleinbuchstaben. */
	public static function interfaces( $product_id ) {
		$raw = (string) self::meta( $product_id, 'schnittstelle' );
		$list = array_filter( array_map( 'trim', explode( ',', strtolower( $raw ) ) ) );
		return array_values( array_unique( $list ) );
	}

	/** Mittelrohr in mm: eigenes Feld, sonst Attribut pa_mittelrohrdurchmesser. */
	public static function tube( $product_id ) {
		$own = self::meta( $product_id, 'mittelrohr' );
		if ( '' !== $own ) {
			return (float) str_replace( ',', '.', $own );
		}
		$product = wc_get_product( $product_id );
		if ( ! $product ) {
			return 0.0;
		}
		$attr = $product->get_attribute( 'pa_mittelrohrdurchmesser' );
		if ( '' === $attr ) {
			return 0.0;
		}
		return (float) str_replace( ',', '.', preg_replace( '/[^0-9.,]/', '', $attr ) );
	}

	public static function is_direct( $product_id ) {
		return 'yes' === self::meta( $product_id, 'direkt', 'no' );
	}

	/** Millimeter pro Bildpixel aus zwei Messpunkten und Referenzlänge. */
	public static function scale( $product_id ) {
		$p1  = self::point( $product_id, 'p1' );
		$p2  = self::point( $product_id, 'p2' );
		$ref = (float) str_replace( ',', '.', (string) self::meta( $product_id, 'ref_mm', '0' ) );
		if ( ! $p1 || ! $p2 || $ref <= 0 ) {
			return 0.0;
		}
		$dist = sqrt( pow( $p2[0] - $p1[0], 2 ) + pow( $p2[1] - $p1[1], 2 ) );
		return $dist > 0 ? $ref / $dist : 0.0;
	}

	public static function image_id( $product_id ) {
		$id = (int) self::meta( $product_id, 'image_id', 0 );
		if ( ! $id ) {
			$id = (int) get_post_thumbnail_id( $product_id );
		}
		return $id;
	}

	/** Ist das Produkt vollständig eingerichtet (Bild, Massstab, Anker)? */
	public static function is_ready( $product_id ) {
		$role = self::role( $product_id );
		if ( ! $role || ! self::image_id( $product_id ) || self::scale( $product_id ) <= 0 || ! self::point( $product_id, 'anchor' ) ) {
			return false;
		}
		if ( 'montage' === $role && ! self::point( $product_id, 'tube_anchor' ) ) {
			return false;
		}
		return true;
	}

	/** Kann die Optik ohne Montage auf diese Waffe? */
	public static function optic_direct_fits( $weapon_id, $optic_id ) {
		if ( ! self::is_direct( $optic_id ) ) {
			return false;
		}
		return (bool) array_intersect( self::interfaces( $weapon_id ), self::interfaces( $optic_id ) );
	}

	/** Passt die Montage auf die Waffe und zur Optik? */
	public static function mount_fits( $weapon_id, $mount_id, $optic_id ) {
		if ( self::is_direct( $optic_id ) ) {
			return false;
		}
		if ( ! array_intersect( self::interfaces( $weapon_id ), self::interfaces( $mount_id ) ) ) {
			return false;
		}
		$optic_tube = self::tube( $optic_id );
		$mount_tube = self::tube( $mount_id );
		return $optic_tube > 0 && abs( $optic_tube - $mount_tube ) < 0.01;
	}

	/** Ausnahmen: manuell gesperrte Produkt-IDs an der Waffe. */
	public static function blocked( $weapon_id ) {
		$raw = (string) self::meta( $weapon_id, 'gesperrt' );
		return array_filter( array_map( 'intval', explode( ',', $raw ) ) );
	}

	/** Produkte einer Rolle, die eingerichtet und kaufbar sind. */
	public static function products_by_role( $role ) {
		$ids = get_posts(
			array(
				'post_type'      => 'product',
				'post_status'    => 'publish',
				'posts_per_page' => 200,
				'fields'         => 'ids',
				'meta_key'       => '_ak_role',
				'meta_value'     => $role,
			)
		);
		return array_values(
			array_filter(
				$ids,
				function ( $id ) {
					$p = wc_get_product( $id );
					return $p && $p->is_purchasable() && $p->is_in_stock() && self::is_ready( $id );
				}
			)
		);
	}

	/**
	 * Alle passenden Optiken und Montagen für eine Waffe.
	 *
	 * @return array{optics: array, mounts: array}
	 */
	public static function options_for_weapon( $weapon_id ) {
		$blocked = self::blocked( $weapon_id );
		$mounts  = array_diff( self::products_by_role( 'montage' ), $blocked );
		$optics  = array();
		$used_mounts = array();

		foreach ( array_diff( self::products_by_role( 'optik' ), $blocked ) as $optic_id ) {
			if ( self::optic_direct_fits( $weapon_id, $optic_id ) ) {
				$optics[ $optic_id ] = array();
				continue;
			}
			$fitting = array_values(
				array_filter(
					$mounts,
					function ( $mount_id ) use ( $weapon_id, $optic_id ) {
						return self::mount_fits( $weapon_id, $mount_id, $optic_id );
					}
				)
			);
			if ( $fitting ) {
				$optics[ $optic_id ] = $fitting;
				$used_mounts         = array_merge( $used_mounts, $fitting );
			}
		}

		return array(
			'optics' => $optics,
			'mounts' => array_values( array_unique( $used_mounts ) ),
		);
	}

	/** Serverseitige Prüfung einer Auswahl. */
	public static function validate_selection( $weapon_id, $optic_id, $mount_id ) {
		if ( 'waffe' !== self::role( $weapon_id ) || ! self::is_ready( $weapon_id ) ) {
			return new WP_Error( 'ak_weapon', 'Diese Waffe ist nicht für den Konfigurator eingerichtet.' );
		}
		if ( ! $optic_id ) {
			return new WP_Error( 'ak_optic', 'Bitte eine Optik wählen.' );
		}
		$options = self::options_for_weapon( $weapon_id );
		if ( ! array_key_exists( $optic_id, $options['optics'] ) ) {
			return new WP_Error( 'ak_optic', 'Diese Optik passt nicht auf die gewählte Waffe.' );
		}
		$needs_mount = ! empty( $options['optics'][ $optic_id ] );
		if ( $needs_mount && ! in_array( $mount_id, $options['optics'][ $optic_id ], true ) ) {
			return new WP_Error( 'ak_mount', 'Bitte eine passende Montage wählen.' );
		}
		if ( ! $needs_mount && $mount_id ) {
			return new WP_Error( 'ak_mount', 'Diese Optik braucht keine zusätzliche Montage.' );
		}
		return true;
	}

	/** Daten eines Produkts für die Vorschau (JavaScript). */
	public static function item_payload( $product_id ) {
		$product  = wc_get_product( $product_id );
		$image_id = self::image_id( $product_id );
		$src      = wp_get_attachment_image_src( $image_id, 'full' );
		$thumb    = wp_get_attachment_image_src( (int) get_post_thumbnail_id( $product_id ), 'woocommerce_thumbnail' );
		$anchor   = self::point( $product_id, 'anchor' );
		$tube_a   = self::point( $product_id, 'tube_anchor' );

		return array(
			'id'         => $product_id,
			'name'       => $product->get_name(),
			'price'      => (float) wc_get_price_to_display( $product ),
			'priceHtml'  => $product->get_price_html(),
			'stock'      => $product->is_on_backorder() ? 'Lieferbar auf Bestellung' : 'An Lager',
			'url'        => get_permalink( $product_id ),
			'img'        => $src ? $src[0] : '',
			'imgW'       => $src ? (int) $src[1] : 0,
			'imgH'       => $src ? (int) $src[2] : 0,
			'thumb'      => $thumb ? $thumb[0] : ( $src ? $src[0] : '' ),
			'mmPerPx'    => self::scale( $product_id ),
			'anchor'     => $anchor,
			'tubeAnchor' => $tube_a,
			'flip'       => 'yes' === self::meta( $product_id, 'flip', 'no' ),
			'direct'     => self::is_direct( $product_id ),
			'tube'       => self::tube( $product_id ),
		);
	}
}
