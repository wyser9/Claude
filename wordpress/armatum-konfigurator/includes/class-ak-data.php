<?php
/**
 * Konfigurator-Daten pro Produkt und Kompatibilitätsregeln.
 *
 * Rollen:
 *  - waffe:   hat Schnittstellen (z.B. picatinny) und einen Ankerpunkt auf der Schiene.
 *  - montage: sitzt auf einer Schnittstelle, hat Ringe für ein Mittelrohr (mm) und eine Rohrmitte.
 *  - optik:   entweder "direkt" auf eine Schnittstelle (Rotpunkt, Optik mit integrierter Montage)
 *             oder mit Mittelrohr, dann braucht sie eine passende Montage.
 *  - zubehoer: gehört zu einer Gruppe (Schalldämpfer, Handgriff, Laser, Schulterstütze, Griff),
 *             die zu einem Steckplatz der Waffe gehört. Passt, wenn die Schnittstellen übereinstimmen.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class AK_Data {

	const ROLES = array( 'waffe', 'montage', 'optik', 'zubehoer' );

	/** Steckplätze an der Waffe (ausser Optik, die den Hauptanker nutzt). */
	const SLOTS = array(
		'muendung' => 'Mündung',
		'unten'    => 'Handschutz unten',
		'seite'    => 'Handschutz seitlich/oben',
		'schaft'   => 'Schaftaufnahme',
		'griff'    => 'Griffaufnahme',
	);

	/** Mündungskette in Reihenfolge: jedes Teil sitzt auf dem Ausgang des vorherigen. */
	const MUZZLE_CHAIN = array( 'muendungsaufsatz', 'hub', 'schalldaempfer' );

	/** Gewinde, die als HUB-Standard (Ausgang zum Schalldämpfer) gelten. */
	const HUB_STANDARDS = array( '1.375x24', '1.5x24', '1.5x18', '1.75x24' );

	/** Zubehörgruppen: Schlüssel => array( Steckplatz, Bezeichnung, Ebene ). */
	const GROUPS = array(
		'muendungsaufsatz' => array( 'muendung', 'Mündungsaufsatz', 2 ),
		'hub'            => array( 'muendung', 'HUB (Schalldämpfer-Adapter)', 3 ),
		'schalldaempfer' => array( 'muendung', 'Schalldämpfer', 4 ),
		'handgriff'      => array( 'unten', 'Handgriff', 3 ),
		'laser'          => array( 'seite', 'Laser', 3 ),
		'schaft'         => array( 'schaft', 'Schulterstütze', 2 ),
		'griff'          => array( 'griff', 'Griff', 2 ),
	);

	/**
	 * Testmodus: Konfigurator nur für Shop-Manager/Admins sichtbar.
	 * Standard an. Ausschalten mit der Option ak_testmode = 'no'.
	 */
	public static function visible_for_current_user() {
		if ( 'no' === get_option( 'ak_testmode', 'yes' ) ) {
			return true;
		}
		return current_user_can( 'manage_woocommerce' );
	}

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
		$list = self::split( self::meta( $product_id, 'schnittstelle' ) );
		// Attribut «Mündungsgewinde» = Eingang bei Mündungsaufsatz und Schalldämpfer.
		// Beim HUB ist es der Ausgang zum Schalldämpfer (siehe outputs()), der Eingang muss eingetragen werden.
		if ( ! $list && 'schalldaempfer' === self::group( $product_id ) ) {
			$list = self::hub_attribute( $product_id );
		}
		if ( ! $list && 'zubehoer' === self::role( $product_id ) && in_array( self::group( $product_id ), array( 'muendungsaufsatz', 'schalldaempfer' ), true ) ) {
			$list = self::thread_attribute( $product_id );
		}
		// HUB: Attributwerte, die kein HUB-Standard sind, gelten als Eingang (z.B. 1/2-28" neben 1.375x24 UNEF).
		if ( ! $list && 'hub' === self::group( $product_id ) ) {
			$list = array_values( array_diff( self::thread_attribute( $product_id ), self::HUB_STANDARDS ) );
		}
		return $list;
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
		if ( 'zubehoer' === $role && ! self::group( $product_id ) ) {
			return false;
		}
		if ( 'zubehoer' === $role && in_array( self::group( $product_id ), array( 'muendungsaufsatz', 'hub' ), true ) && ( ! self::point( $product_id, 'tube_anchor' ) || ! self::outputs( $product_id ) ) ) {
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

	public static function group( $product_id ) {
		$g = (string) self::meta( $product_id, 'gruppe' );
		return isset( self::GROUPS[ $g ] ) ? $g : '';
	}

	/** Steckplatz der Waffe: Anker (Bildpixel) und Schnittstellen, oder null. */
	public static function weapon_slot( $weapon_id, $slot ) {
		$anchor = self::point( $weapon_id, 'slot_' . $slot . '_anchor' );
		if ( ! $anchor ) {
			return null;
		}
		$list = self::split( self::meta( $weapon_id, 'slot_' . $slot . '_if' ) );
		if ( ! $list && 'muendung' === $slot ) {
			$list = self::thread_attribute( $weapon_id );
		}
		return array(
			'anchor'     => $anchor,
			'interfaces' => $list,
		);
	}

	/**
	 * Schnittstellen-Liste vereinheitlichen, damit Attributwerte und Eingaben vergleichbar sind:
	 * «1/2-28"» → 1/2x28, «1.375x24 UNEF» → 1.375x24, «MT5/8-24» → 5/8x24, «M14x1 L» → m14x1-lh,
	 * «3-Lug und 1/2-28"» → 3-lug, 1/2x28.
	 */
	public static function split( $raw ) {
		$raw   = strtolower( str_replace( array( '×', '"', '″' ), array( 'x', '', '' ), (string) $raw ) );
		$parts = preg_split( '/\s*(,|;|\bund\b|\/\/)\s*/u', $raw );
		$out   = array();
		foreach ( $parts as $p ) {
			$p = trim( $p );
			if ( '' === $p ) {
				continue;
			}
			$p = preg_replace( '/\s+(unef|unf|uns|un|tpi)$/', '', $p );
			$p = preg_replace( '/\s+(links|lh|l)$/', '-lh', $p );
			$p = preg_replace( '/^mt(?=\d)/', '', $p );
			$p = preg_replace( '/^(\d+(?:[\/.]\d+)?)\s*-\s*(\d+)$/', '$1x$2', $p );
			$p = preg_replace( '/\s+/', '', $p );
			$out[] = $p;
		}
		return array_values( array_unique( $out ) );
	}

	/** HUB-Gewinde (Schalldämpfer-Seite) aus dem Produktattribut «HUB-Gewinde». */
	public static function hub_attribute( $product_id ) {
		$product = wc_get_product( $product_id );
		return $product ? self::split( $product->get_attribute( 'pa_hub-gewinde' ) ) : array();
	}

	/** Mündungsgewinde aus dem Produktattribut «Mündungsgewinde». */
	public static function thread_attribute( $product_id ) {
		$product = wc_get_product( $product_id );
		return $product ? self::split( $product->get_attribute( 'pa_muendungsgewinde' ) ) : array();
	}

	/** Ausgang (Schnittstelle nach vorne) eines Mündungsaufsatzes oder HUBs. */
	public static function outputs( $product_id ) {
		$list = self::split( self::meta( $product_id, 'ausgang' ) );
		if ( ! $list && 'hub' === self::group( $product_id ) ) {
			$list = self::hub_attribute( $product_id );
		}
		if ( ! $list && 'hub' === self::group( $product_id ) ) {
			$list = array_values( array_intersect( self::thread_attribute( $product_id ), self::HUB_STANDARDS ) );
		}
		return $list;
	}

	/**
	 * Mündungs-Enden der Waffe ohne Zubehör:
	 *  - Gewinde (Steckplatz Mündung, Punkt = Gewindeende)
	 *  - Original-Mündungsaufsatz (falls eingetragen, Punkt = vorderes Ende)
	 */
	public static function muzzle_ends( $weapon_id ) {
		$ends   = array();
		$thread = self::weapon_slot( $weapon_id, 'muendung' );
		if ( $thread ) {
			$ends[] = array( 'type' => 'gewinde', 'interfaces' => $thread['interfaces'] );
		}
		$dev_if = self::split( self::meta( $weapon_id, 'muzzle_device_if' ) );
		if ( $dev_if && self::point( $weapon_id, 'muzzle_device_anchor' ) ) {
			$ends[] = array( 'type' => 'original', 'interfaces' => $dev_if );
		}
		return $ends;
	}

	/**
	 * Prüft eine Mündungskette. $chain = Gruppe => Produkt-ID (nur gewählte).
	 * Mündungsaufsatz ersetzt den Originalaufsatz und sitzt immer auf dem Gewinde.
	 */
	public static function muzzle_chain_valid( $weapon_id, $chain ) {
		$thread = self::weapon_slot( $weapon_id, 'muendung' );
		if ( ! empty( $chain['muendungsaufsatz'] ) ) {
			$id = $chain['muendungsaufsatz'];
			if ( ! $thread || ! array_intersect( $thread['interfaces'], self::interfaces( $id ) ) ) {
				return false;
			}
			$current = self::outputs( $id );
		} else {
			$current = array();
			foreach ( self::muzzle_ends( $weapon_id ) as $end ) {
				$current = array_merge( $current, $end['interfaces'] );
			}
		}
		if ( ! empty( $chain['hub'] ) ) {
			if ( ! array_intersect( $current, self::interfaces( $chain['hub'] ) ) ) {
				return false;
			}
			$current = self::outputs( $chain['hub'] );
		}
		if ( ! empty( $chain['schalldaempfer'] ) && ! array_intersect( $current, self::interfaces( $chain['schalldaempfer'] ) ) ) {
			return false;
		}
		return true;
	}

	/** Passt das Zubehör auf den Steckplatz der Waffe? */
	public static function accessory_fits( $weapon_id, $acc_id ) {
		$g = self::group( $acc_id );
		if ( ! $g ) {
			return false;
		}
		if ( in_array( $g, self::MUZZLE_CHAIN, true ) ) {
			return true; // Mündungsteile werden in options_for_weapon über die Kette geprüft.
		}
		$slot = self::weapon_slot( $weapon_id, self::GROUPS[ $g ][0] );
		return $slot && array_intersect( $slot['interfaces'], self::interfaces( $acc_id ) );
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

		$parts = array();
		$muzzle = array();
		foreach ( array_diff( self::products_by_role( 'zubehoer' ), $blocked ) as $acc_id ) {
			$g = self::group( $acc_id );
			if ( in_array( $g, self::MUZZLE_CHAIN, true ) ) {
				$muzzle[ $g ][] = $acc_id;
			} elseif ( self::accessory_fits( $weapon_id, $acc_id ) ) {
				$parts[ $g ][] = $acc_id;
			}
		}
		// Mündung: nur Teile, die in mindestens einer gültigen Kette vorkommen.
		$aufs = array_merge( array( 0 ), isset( $muzzle['muendungsaufsatz'] ) ? $muzzle['muendungsaufsatz'] : array() );
		$hubs = array_merge( array( 0 ), isset( $muzzle['hub'] ) ? $muzzle['hub'] : array() );
		$sds  = array_merge( array( 0 ), isset( $muzzle['schalldaempfer'] ) ? $muzzle['schalldaempfer'] : array() );
		$ok   = array();
		foreach ( $aufs as $a ) {
			foreach ( $hubs as $h ) {
				foreach ( $sds as $d ) {
					if ( ! $a && ! $h && ! $d ) {
						continue;
					}
					$chain = array_filter( array( 'muendungsaufsatz' => $a, 'hub' => $h, 'schalldaempfer' => $d ) );
					if ( self::muzzle_chain_valid( $weapon_id, $chain ) ) {
						foreach ( $chain as $g => $id ) {
							$ok[ $g ][ $id ] = true;
						}
					}
				}
			}
		}
		foreach ( self::MUZZLE_CHAIN as $g ) {
			if ( ! empty( $ok[ $g ] ) ) {
				$parts[ $g ] = array_map( 'intval', array_keys( $ok[ $g ] ) );
			}
		}

		return array(
			'optics' => $optics,
			'mounts' => array_values( array_unique( $used_mounts ) ),
			'parts'  => $parts,
		);
	}

	/**
	 * Serverseitige Prüfung einer Auswahl.
	 *
	 * @param int   $weapon_id Waffe.
	 * @param int   $optic_id  Optik oder 0.
	 * @param int   $mount_id  Montage oder 0.
	 * @param array $parts     Gruppe => Produkt-ID.
	 */
	public static function validate_selection( $weapon_id, $optic_id, $mount_id, $parts = array() ) {
		if ( 'waffe' !== self::role( $weapon_id ) || ! self::is_ready( $weapon_id ) ) {
			return new WP_Error( 'ak_weapon', 'Diese Waffe ist nicht für den Konfigurator eingerichtet.' );
		}
		$options = self::options_for_weapon( $weapon_id );
		if ( $optic_id ) {
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
		} elseif ( $mount_id ) {
			return new WP_Error( 'ak_mount', 'Eine Montage gibt es nur zusammen mit einer Optik.' );
		}
		foreach ( $parts as $group => $acc_id ) {
			if ( ! isset( self::GROUPS[ $group ] ) || empty( $options['parts'][ $group ] ) || ! in_array( $acc_id, $options['parts'][ $group ], true ) ) {
				return new WP_Error( 'ak_part', 'Ein gewähltes Zubehör passt nicht auf diese Waffe.' );
			}
		}
		$chain = array_intersect_key( $parts, array_flip( self::MUZZLE_CHAIN ) );
		if ( $chain && ! self::muzzle_chain_valid( $weapon_id, $chain ) ) {
			return new WP_Error( 'ak_muzzle', 'Die gewählten Teile an der Mündung passen nicht zusammen. Ein Schalldämpfer braucht einen passenden HUB.' );
		}
		if ( ! $optic_id && ! $parts ) {
			return new WP_Error( 'ak_empty', 'Bitte mindestens ein Zubehör wählen.' );
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
			'group'      => self::group( $product_id ),
			'ifs'        => self::interfaces( $product_id ),
			'outs'       => self::outputs( $product_id ),
			'hinweis'    => (string) self::meta( $product_id, 'hinweis' ),
			'cutout'     => 'yes' === self::meta( $product_id, 'freigestellt', 'no' ),
			'brand'      => trim( (string) $product->get_attribute( 'pa_hersteller' ) ),
		);
	}
}
