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
		'unten'    => 'Schiene unten (Handschutz oder Pistolenrahmen)',
		'seite'    => 'Schiene seitlich/oben am Handschutz',
		'schaft'   => 'Schaftaufnahme',
		'griff'    => 'Aufnahme Pistolengriff',
	);

	/** Mündungskette in Reihenfolge: jedes Teil sitzt auf dem Ausgang des vorherigen. */
	const MUZZLE_CHAIN = array( 'muendungsaufsatz', 'hub', 'schalldaempfer' );

	/** Gewinde, die als HUB-Standard (Ausgang zum Schalldämpfer) gelten. */
	const HUB_STANDARDS = array( '1.375x24', '1.5x24', '1.5x18', '1.75x24' );

	/** Zubehörgruppen: Schlüssel => array( Steckplatz (mehrere mit Komma, erster passender gilt), Bezeichnung, Ebene ). */
	const GROUPS = array(
		'muendungsaufsatz' => array( 'muendung', 'Mündungsaufsatz', 2 ),
		'hub'            => array( 'muendung', 'HUB (Schalldämpfer-Adapter)', 3 ),
		'schalldaempfer' => array( 'muendung', 'Schalldämpfer', 4 ),
		'handgriff'      => array( 'unten', 'Frontgriff (am Handschutz)', 3 ),
		'laser'          => array( 'seite,unten', 'Laser', 3 ),
		'lampe'          => array( 'unten,seite', 'Lampe (Waffenlicht)', 3 ),
		'schaft'         => array( 'schaft', 'Schulterstütze', 2 ),
		'griff'          => array( 'griff', 'Pistolengriff (beim Abzug)', 2 ),
		// Adapterschiene (z.B. M-LOK → Picatinny): kein eigener Schritt, wird beim Handgriff/Laser mitgewählt.
		'adapterschiene' => array( '', 'Adapterschiene (z.B. M-LOK auf Picatinny)', 2 ),
	);

	/** Gängige Schnittstellen (Vorschläge im Backend). */
	const KNOWN_INTERFACES = array(
		'picatinny'   => 'schiene',
		'rmr'         => 'optik',
		'dpp'         => 'optik',
		'rms'         => 'optik',
		'rmsc'        => 'optik',
		'acro'        => 'optik',
		'docter'      => 'optik',
		'm-lok'       => 'schiene',
		'keymod'      => 'schiene',
		'1/2x28'      => 'gewinde',
		'5/8x24'      => 'gewinde',
		'm14x1-lh'    => 'gewinde',
		'm13.5x1-lh'  => 'gewinde',
		'm15x1'       => 'gewinde',
		'1.375x24'    => 'gewinde',
		'1.5x24'      => 'gewinde',
		'1.75x24'     => 'gewinde',
		'3-lug'       => 'gewinde',
		'taper-lok'   => 'gewinde',
		'ar15-buffer' => 'schaft',
		'ar15-griff'  => 'griff',
	);

	/** Schreibweisen, die auf eine Schnittstelle vereinheitlicht werden. */
	const ALIASES = array(
		'mlok'               => 'm-lok',
		'm-lock'             => 'm-lok',
		'mlock'              => 'm-lok',
		'picatiny'           => 'picatinny',
		'picatinnyschiene'   => 'picatinny',
		'picatinny-schiene'  => 'picatinny',
		'picatinnyrail'      => 'picatinny',
		'picatinny-rail'     => 'picatinny',
		'pica'               => 'picatinny',
		'mil-std-1913'       => 'picatinny',
		'1913'               => 'picatinny',
		'weaver/picatinny'   => 'picatinny',
		'key-mod'            => 'keymod',
		// Footprints für Rotpunkte (Pistolenschlitten, Adapterplatten).
		'rmr/sro'            => 'rmr',
		'trijiconrmr'        => 'rmr',
		'trijiconrmr/sro'    => 'rmr',
		'sro'                => 'rmr',
		'deltapointpro'      => 'dpp',
		'deltapoint'         => 'dpp',
		'leupolddeltapointpro' => 'dpp',
		'delta-point-pro'    => 'dpp',
		'leupold-dpp'        => 'dpp',
		'shieldrms'          => 'rms',
		'shieldrmsc'         => 'rmsc',
		'rms/rmsc'           => 'rms',
		'aimpointacro'       => 'acro',
		'docter/noblex'      => 'docter',
		'doctor/noblex'      => 'docter',
		'noblex'             => 'docter',
		'doctor'             => 'docter',
	);

	/** Steckplätze einer Gruppe in Reihenfolge. */
	public static function group_slots( $group ) {
		if ( ! isset( self::GROUPS[ $group ] ) || '' === self::GROUPS[ $group ][0] ) {
			return array();
		}
		return explode( ',', self::GROUPS[ $group ][0] );
	}

	/** Elternprodukt einer Variante, sonst 0. */
	public static function parent_of( $product_id ) {
		static $cache = array();
		if ( ! isset( $cache[ $product_id ] ) ) {
			$cache[ $product_id ] = 'product_variation' === get_post_type( $product_id ) ? (int) wp_get_post_parent_id( $product_id ) : 0;
		}
		return $cache[ $product_id ];
	}

	/** Kaufbare Varianten eines variablen Produkts. */
	public static function variations( $product_id ) {
		$product = wc_get_product( $product_id );
		if ( ! $product || ! $product->is_type( 'variable' ) ) {
			return array();
		}
		$out = array();
		foreach ( $product->get_children() as $vid ) {
			$v = wc_get_product( $vid );
			if ( $v && $v->is_purchasable() && $v->is_in_stock() && $v->variation_is_visible() ) {
				$out[] = (int) $vid;
			}
		}
		return $out;
	}

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
		if ( ( '' === $value || null === $value ) && self::parent_of( $product_id ) ) {
			// Varianten übernehmen die Einrichtung des Hauptprodukts.
			$value = get_post_meta( self::parent_of( $product_id ), '_ak_' . $key, true );
		}
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
		if ( self::parent_of( $product_id ) ) {
			$v = wc_get_product( $product_id );
			$a = $v ? (string) $v->get_attribute( 'pa_mittelrohrdurchmesser' ) : '';
			if ( preg_match( '/^\s*(\d+(?:[.,]\d+)?)\s*$/', $a, $m ) ) {
				return (float) str_replace( ',', '.', $m[1] );
			}
		}
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
		// Bei mehreren Werten (z.B. «30, 34» am variablen Hauptprodukt) gilt der erste.
		return preg_match( '/\d+(?:[.,]\d+)?/', $attr, $m ) ? (float) str_replace( ',', '.', $m[0] ) : 0.0;
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
			$parent = self::parent_of( $product_id );
			$id     = (int) get_post_thumbnail_id( $parent ? $parent : $product_id );
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
		if ( 'zubehoer' === $role && in_array( self::group( $product_id ), array( 'muendungsaufsatz', 'hub', 'adapterschiene' ), true ) && ( ! self::point( $product_id, 'tube_anchor' ) || ! self::outputs( $product_id ) ) ) {
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
		$ids = array_filter( array_map( 'intval', explode( ',', $raw ) ) );
		foreach ( $ids as $id ) {
			$ids = array_merge( $ids, self::variations( $id ) );
		}
		return array_values( array_unique( $ids ) );
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
			$p = isset( self::ALIASES[ $p ] ) ? self::ALIASES[ $p ] : $p;
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
		return '' !== self::fitting_slot( $weapon_id, $acc_id );
	}

	/** Erster Steckplatz der Waffe, auf den das Zubehör direkt passt, sonst ''. */
	public static function fitting_slot( $weapon_id, $acc_id ) {
		$need = self::interfaces( $acc_id );
		foreach ( self::group_slots( self::group( $acc_id ) ) as $slot_key ) {
			$slot = self::weapon_slot( $weapon_id, $slot_key );
			if ( $slot && array_intersect( $slot['interfaces'], $need ) ) {
				return $slot_key;
			}
		}
		return '';
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
		$out = array();
		foreach ( $ids as $id ) {
			$p = wc_get_product( $id );
			if ( ! $p || ! self::is_ready( $id ) ) {
				continue;
			}
			if ( $p->is_type( 'variable' ) ) {
				// Jede kaufbare Variante ist ein eigener Eintrag (eigener Preis, Lager, Mittelrohr).
				$out = array_merge( $out, self::variations( $id ) );
			} elseif ( $p->is_purchasable() && $p->is_in_stock() ) {
				$out[] = (int) $id;
			}
		}
		return $out;
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
		$all_acc  = array_diff( self::products_by_role( 'zubehoer' ), $blocked );
		$rails    = array_values(
			array_filter(
				$all_acc,
				function ( $id ) {
					return 'adapterschiene' === self::group( $id );
				}
			)
		);
		$adapters = array();
		$slots    = array();
		foreach ( $all_acc as $acc_id ) {
			$g = self::group( $acc_id );
			if ( 'adapterschiene' === $g ) {
				continue;
			}
			if ( in_array( $g, self::MUZZLE_CHAIN, true ) ) {
				$muzzle[ $g ][] = $acc_id;
			} elseif ( '' !== ( $slot_key = self::fitting_slot( $weapon_id, $acc_id ) ) ) { // phpcs:ignore
				$parts[ $g ][]      = $acc_id;
				$slots[ $acc_id ]   = $slot_key;
			} else {
				list( $via, $slot_key ) = self::adapters_for( $weapon_id, $acc_id, $rails );
				if ( $via ) {
					$parts[ $g ][]       = $acc_id;
					$adapters[ $acc_id ] = $via;
					$slots[ $acc_id ]    = $slot_key;
				}
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
			'parts'    => $parts,
			'adapters' => $adapters,
			'slots'    => $slots,
		);
	}

	/** Adapterschienen, über die ein Zubehör an seinen Steckplatz passt (Eingang = Steckplatz, Ausgang = Zubehör). */
	public static function adapters_for( $weapon_id, $acc_id, $rails ) {
		$need = self::interfaces( $acc_id );
		foreach ( self::group_slots( self::group( $acc_id ) ) as $slot_key ) {
			$slot = self::weapon_slot( $weapon_id, $slot_key );
			if ( ! $slot ) {
				continue;
			}
			$out = array();
			foreach ( $rails as $r ) {
				if ( array_intersect( $slot['interfaces'], self::interfaces( $r ) ) && array_intersect( self::outputs( $r ), $need ) ) {
					$out[] = (int) $r;
				}
			}
			if ( $out ) {
				return array( $out, $slot_key );
			}
		}
		return array( array(), '' );
	}

	/**
	 * Serverseitige Prüfung einer Auswahl.
	 *
	 * @param int   $weapon_id Waffe.
	 * @param int   $optic_id  Optik oder 0.
	 * @param int   $mount_id  Montage oder 0.
	 * @param array $parts     Gruppe => Produkt-ID.
	 */
	public static function validate_selection( $weapon_id, $optic_id, $mount_id, $parts = array(), $adapters = array() ) {
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
		foreach ( $parts as $group => $acc_id ) {
			$need = isset( $options['adapters'][ $acc_id ] ) ? $options['adapters'][ $acc_id ] : array();
			$got  = isset( $adapters[ $group ] ) ? (int) $adapters[ $group ] : 0;
			if ( $need && ! in_array( $got, $need, true ) ) {
				return new WP_Error( 'ak_adapter', 'Für ' . get_the_title( $acc_id ) . ' braucht es eine passende Adapterschiene.' );
			}
			if ( ! $need && $got ) {
				return new WP_Error( 'ak_adapter', 'Für ' . get_the_title( $acc_id ) . ' braucht es keine Adapterschiene.' );
			}
		}
		if ( array_diff_key( $adapters, $parts ) ) {
			return new WP_Error( 'ak_adapter', 'Eine Adapterschiene gibt es nur zusammen mit einem Zubehör.' );
		}
		$chain = array_intersect_key( $parts, array_flip( self::MUZZLE_CHAIN ) );
		if ( $chain && ! self::muzzle_chain_valid( $weapon_id, $chain ) ) {
			return new WP_Error( 'ak_muzzle', 'Die gewählten Teile an der Mündung passen nicht zusammen. Ein Schalldämpfer passt direkt auf ein gleiches Mündungsgewinde oder auf einen passenden HUB.' );
		}
		if ( ! $optic_id && ! $parts ) {
			return new WP_Error( 'ak_empty', 'Bitte mindestens ein Zubehör wählen.' );
		}
		return true;
	}

	/** Bekannte Schnittstellen: Standardliste, im Shop verwendete Werte und Gewinde-Attribute. */
	public static function known_interfaces() {
		global $wpdb;
		$list = array_keys( self::KNOWN_INTERFACES );
		$rows = $wpdb->get_col( "SELECT DISTINCT meta_value FROM {$wpdb->postmeta} WHERE meta_key IN ('_ak_schnittstelle','_ak_ausgang','_ak_muzzle_device_if') OR meta_key LIKE '\\_ak\\_slot\\_%\\_if'" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		foreach ( (array) $rows as $r ) {
			$list = array_merge( $list, self::split( $r ) );
		}
		foreach ( array( 'pa_muendungsgewinde', 'pa_hub-gewinde' ) as $tax ) {
			$terms = taxonomy_exists( $tax ) ? get_terms( array( 'taxonomy' => $tax, 'hide_empty' => false, 'fields' => 'names' ) ) : array();
			if ( ! is_wp_error( $terms ) ) {
				foreach ( $terms as $t ) {
					$list = array_merge( $list, self::split( $t ) );
				}
			}
		}
		$out = array();
		foreach ( array_unique( array_filter( $list ) ) as $v ) {
			$out[ $v ] = self::interface_kind( $v );
		}
		return $out;
	}

	/** Art einer Schnittstelle für die Vorschläge im Backend: optik, schiene, gewinde, schaft, griff, andere. */
	public static function interface_kind( $v ) {
		if ( isset( self::KNOWN_INTERFACES[ $v ] ) ) {
			return self::KNOWN_INTERFACES[ $v ];
		}
		if ( preg_match( '/buffer|schaft|stock/', $v ) ) {
			return 'schaft';
		}
		if ( preg_match( '/griff|grip/', $v ) ) {
			return 'griff';
		}
		if ( preg_match( '/\d.*x\s*\d|-lh$|lug|taper/', $v ) ) {
			return 'gewinde';
		}
		return 'andere';
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
			'url'        => $product->get_permalink(),
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
