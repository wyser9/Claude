<?php
/**
 * Konfigurator-Daten pro Produkt und Kompatibilitätsregeln.
 *
 * Rollen:
 *  - waffe:   hat Schnittstellen (z.B. picatinny) und einen Ankerpunkt auf der Schiene.
 *  - montage: sitzt auf einer Schnittstelle, hat Ringe für ein Mittelrohr (mm) und eine Rohrmitte.
 *  - optik:   entweder "direkt" auf eine Schnittstelle (Rotpunkt, Optik mit integrierter Montage)
 *             oder mit Mittelrohr, dann braucht sie eine passende Montage.
 *  - magnifier: Vergrösserung hinter einem Rotpunkt. «direkt» = mit eigener Halterung,
 *             sonst mit Mittelrohr und Montage der Art «magnifier».
 *  - zubehoer: gehört zu einer Gruppe (Schalldämpfer, Handgriff, Laser, Schulterstütze, Griff),
 *             die zu einem Steckplatz der Waffe gehört. Passt, wenn die Schnittstellen übereinstimmen.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class AK_Data {

	/** Pro Anfrage: Optionen je Waffe, Produkte je Rolle. */
	private static $options_memo = array();
	private static $role_memo    = array();
	private static $bumped       = false;

	/** Cache leeren, sobald sich Produkte, Lager oder Konfigurator-Felder ändern. */
	public static function cache_hooks() {
		$meta = function ( $meta_id, $object_id, $meta_key ) {
			if ( 0 === strpos( (string) $meta_key, '_ak_' ) ) {
				self::bump_cache();
			}
		};
		add_action( 'added_post_meta', $meta, 10, 3 );
		add_action( 'updated_post_meta', $meta, 10, 3 );
		add_action( 'deleted_post_meta', $meta, 10, 3 );
		$post = function ( $post_id ) {
			if ( in_array( get_post_type( $post_id ), array( 'product', 'product_variation' ), true ) ) {
				self::bump_cache();
			}
		};
		add_action( 'save_post', $post );
		add_action( 'before_delete_post', $post );
		add_action( 'trashed_post', $post );
		add_action( 'woocommerce_product_set_stock_status', array( __CLASS__, 'bump_cache' ) );
		add_action( 'woocommerce_variation_set_stock_status', array( __CLASS__, 'bump_cache' ) );
		add_action( 'ak_warm_cache', array( __CLASS__, 'warm_cache' ) );
	}

	/** Hintergrund (WP-Cron): Optionen aller Waffen vorrechnen, in Etappen von höchstens 20 Sekunden. */
	public static function warm_cache() {
		if ( ! class_exists( 'AK_Frontend' ) ) {
			return;
		}
		$start = microtime( true );
		foreach ( AK_Frontend::weapons() as $w ) {
			if ( ( microtime( true ) - $start ) > 20 ) {
				wp_schedule_single_event( time() + 30, 'ak_warm_cache' );
				return;
			}
			self::options_for_weapon( $w );
		}
	}

	const ROLES = array( 'waffe', 'montage', 'optik', 'magnifier', 'zubehoer' );

	/** Arten von Montagen: '' = für Optik mit Mittelrohr (Zielfernrohr). */
	const MOUNT_TYPES = array(
		''          => 'Optik mit Mittelrohr (Zielfernrohr)',
		'magnifier' => 'nur Magnifier',
		'footprint' => 'Adapterplatte / Erhöhung für Rotpunkt (Schiene auf Footprint)',
	);

	/** Waffenarten: bestimmen die Auswahl im Konfigurator (Pistolen: Rotpunkt mit Footprint, Lauf, Unterschiene). */
	const WEAPON_TYPES = array(
		'langwaffe' => 'Langwaffe',
		'pistole'   => 'Pistole',
	);

	/** Plattformen (Waffe: eine, Teil: eine oder mehrere, leer = passt auf alle). */
	const PLATFORMS = array(
		'ar15'   => 'AR-15 / M4',
		'ar10'   => 'AR-10 / SR-25',
		'ak'     => 'AK (Kalaschnikow)',
		'repetierer' => 'Repetierer (Bolt Action)',
		'pistole'    => 'Pistole',
		'flinte'     => 'Flinte',
		'andere'     => 'andere',
	);

	/**
	 * Geschossdurchmesser in mm je Kaliber-Schreibweise (Reihenfolge egal, es gilt der grösste Treffer).
	 * Mündungsteile passen nur, wenn ihre Bohrung mindestens so gross ist wie das Kaliber der Waffe.
	 */
	const CALIBERS = array(
		'/\.50\b|\.50\s*bmg|12[.,]7\s*x/i'                                  => 12.95,
		'/\.45\b|\.45\s*acp|11[.,]43/i'                                      => 11.5,
		'/\.416\b/i'                                                             => 10.6,
		'/\.40\b|\.40\s*s\s*&?\s*w|\b10\s*mm\b|10x25/i'                  => 10.2,
		'/\.375\b|9[.,]3\s*x/i'                                                 => 9.5,
		'/\.35[5-8]\b|\.38\b|\.380\b|\b9\s*mm\b|\b9\s*x\s*(19|21|18|17)\b|\b9mm/i' => 9.1,
		'/\.338\b|8[.,]6\s*(x|mm|blk)/i'                                        => 8.6,
		'/\b8\s*x\s*(57|68|60)|\.32[3-5]\b|\b8\s*mm\b/i'                  => 8.2,
		'/\.303\b|7[.,]62\s*x\s*54|7[.,]7\s*x/i'                             => 7.9,
		'/\.30\b|\.30-|\.30\s*cal|\.30[0-9]\b|7[.,]62|7[.,]5\s*x\s*55|\.300\b/i' => 7.8,
		'/\b7\s*mm\b|\b7mm|\.28[04]\b|\b7\s*x\s*(57|64|65)|7-08/i'      => 7.2,
		'/\.270\b|6[.,]8\s*(x|mm|spc|blk)?/i'                                  => 7.0,
		'/6[.,]5\s*(x|mm|creed|prc|grendel)?|\.26[04]\b/i'                     => 6.7,
		'/\.25\b|\.25-|\.257\b/i'                                            => 6.5,
		'/\b6\s*mm\b|\b6mm|\.243\b|\.24\b|6\s*arc|6\s*creedmoor/i'     => 6.2,
		'/\.22\b|\.22[0-9]\b|\.22-|5[.,]56|5[.,]45|\.204\b|\.17\b/i'    => 5.7,
	);

	/** Abstand Rotpunkt – Magnifier in mm und erlaubte Abweichung der Achshöhe. */
	const MAG_GAP = 10;
	const MAG_TOL = 1.5;

	/** Steckplätze an der Waffe (ausser Optik, die den Hauptanker nutzt). */
	const SLOTS = array(
		'muendung' => 'Mündung',
		'unten'    => 'Schiene unten (Handschutz oder Pistolenrahmen)',
		'seite'    => 'Schiene seitlich/oben am Handschutz',
		'vorne'    => 'Schiene unten vorne (Zweibein)',
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
		'zweibein'       => array( 'vorne,unten', 'Zweibein', 3 ),
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
		'micro'       => 'optik',
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
		'aimpointmicro'      => 'micro',
		'aimpoint-micro'     => 'micro',
		'microt1'            => 'micro',
		't1'                 => 'micro',
		't2'                 => 'micro',
		't1/t2'              => 'micro',
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
		// Schienenteile (Montage, Rotpunkt, Griff, Laser, Lampe, Zweibein): Attribut «Montage», z.B. Picatinny oder M-LOK.
		if ( ! $list && self::role( $product_id ) && 'waffe' !== self::role( $product_id ) && ! in_array( self::group( $product_id ), array_merge( self::MUZZLE_CHAIN, array( 'schaft', 'griff' ) ), true ) ) {
			$list = self::attribute_interfaces( $product_id );
		}
		return $list;
	}

	/** Schnittstellen aus dem Attribut «Montage» (nur bekannte Schienen und Footprints). */
	public static function attribute_interfaces( $product_id ) {
		$product = wc_get_product( $product_id );
		if ( ! $product ) {
			return array();
		}
		$known = array_keys( self::KNOWN_INTERFACES );
		return array_values( array_intersect( self::split( $product->get_attribute( 'pa_montage' ) ), $known ) );
	}

	/** Waffenart: Feld, sonst aus der Kategorie (Pistole, Revolver), sonst Langwaffe. */
	public static function weapon_type( $product_id ) {
		$t = (string) self::meta( $product_id, 'waffenart' );
		if ( isset( self::WEAPON_TYPES[ $t ] ) ) {
			return $t;
		}
		$pid   = self::parent_of( $product_id ) ? self::parent_of( $product_id ) : $product_id;
		$names = wp_get_post_terms( $pid, 'product_cat', array( 'fields' => 'names' ) );
		foreach ( is_wp_error( $names ) ? array() : $names as $n ) {
			if ( preg_match( '/pistol|revolver/i', $n ) ) {
				return 'pistole';
			}
		}
		return 'langwaffe';
	}

	/** Plattformen eines Produkts (Feld «plattform», kommagetrennt), leer = keine Einschränkung. */
	public static function platforms( $product_id ) {
		$raw = strtolower( (string) self::meta( $product_id, 'plattform' ) );
		return array_values( array_intersect( array_map( 'trim', explode( ',', $raw ) ), array_keys( self::PLATFORMS ) ) );
	}

	/** Passt das Teil zur Plattform der Waffe? Teil ohne Plattform passt immer, Teil mit Plattform nur auf Waffen dieser Plattform. */
	public static function platform_ok( $weapon_id, $part_id ) {
		$need = self::platforms( $part_id );
		return ! $need || (bool) array_intersect( $need, self::platforms( $weapon_id ) );
	}

	/** Grösster Geschossdurchmesser in mm aus einem Text, 0 = unbekannt. */
	public static function caliber_from_text( $text, $loose = false ) {
		$text = wp_specialchars_decode( (string) $text );
		if ( preg_match( '/^\s*(\d+(?:[.,]\d+)?)\s*$/', $text, $m ) ) {
			return (float) str_replace( ',', '.', $m[1] ); // Zahl = mm.
		}
		$best = 0.0;
		foreach ( self::CALIBERS as $re => $mm ) {
			if ( preg_match( $re, $text ) ) {
				$best = max( $best, $mm );
			}
		}
		return ( $best > 0 || ! $loose ) ? $best : self::caliber_loose( $text );
	}

	/**
	 * Grobe Erkennung für Kaliberbezeichnungen ohne eigenen Eintrag (nur Feld und Attribut, nicht Produktname):
	 * «.44 Magnum» = Zoll, «8x57», «5.7x28», «bis 9.3 mm» = mm, «308 Norma» = Zoll ohne Punkt. Schrot = unbekannt.
	 */
	private static function caliber_loose( $text ) {
		if ( preg_match( '/schrot|\b\d{2}\/\d{2}\b|kal\.\s*\d+\s*\(/i', $text ) ) {
			return 0.0;
		}
		if ( preg_match( '/(?:^|[\s(])\.(\d{2,3})(?!\d)/', $text, $m ) ) {
			return round( (int) $m[1] / ( 3 === strlen( $m[1] ) ? 1000 : 100 ) * 25.4, 2 );
		}
		if ( preg_match( '/(?:^|\s)(\d{1,2}(?:\.\d{1,2})?)\s*(?:x\s*\d|mm\b)/i', $text, $m ) ) {
			return (float) $m[1];
		}
		if ( preg_match( '/^\s*([2-5]\d{2})\s+[a-z]/i', $text, $m ) ) {
			return round( (int) $m[1] / 1000 * 25.4, 2 );
		}
		if ( preg_match( '/^\s*(\d{1,2}(?:\.\d{1,2})?)(?:\s|-|$)/', $text, $m ) && (float) $m[1] >= 4 && (float) $m[1] <= 13 ) {
			return (float) $m[1];
		}
		return 0.0;
	}

	/** Kaliber (Bohrung) in mm: Feld «kaliber», sonst Attribut «Kaliber», sonst Produktname. 0 = unbekannt. */
	public static function caliber_mm( $product_id ) {
		$own = self::caliber_from_text( self::meta( $product_id, 'kaliber' ), true );
		if ( $own > 0 ) {
			return $own;
		}
		$product = wc_get_product( $product_id );
		if ( ! $product ) {
			return 0.0;
		}
		$attr = self::caliber_from_text( $product->get_attribute( 'pa_kaliber' ), true );
		return $attr > 0 ? $attr : self::caliber_from_text( $product->get_name() );
	}

	/** Ist die Bohrung des Mündungsteils gross genug für das Kaliber der Waffe? Unbekannt = kein Ausschluss. */
	public static function caliber_ok( $weapon_id, $part_id ) {
		$w = self::caliber_mm( $weapon_id );
		$p = self::caliber_mm( $part_id );
		return $w <= 0 || $p <= 0 || $p >= $w - 0.05;
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
		return ! self::missing( $product_id );
	}

	/** Was fehlt noch bis «eingerichtet»? Liste kurzer Hinweise, leer = alles da. */
	public static function missing( $product_id ) {
		$role = self::role( $product_id );
		if ( ! $role ) {
			return array( 'Rolle wählen' );
		}
		$out = array();
		if ( ! self::image_id( $product_id ) ) {
			$out[] = 'Bild';
		}
		if ( ! self::point( $product_id, 'p1' ) || ! self::point( $product_id, 'p2' ) ) {
			$out[] = 'Messpunkt 1 und 2';
		}
		if ( (float) str_replace( ',', '.', (string) self::meta( $product_id, 'ref_mm', '0' ) ) <= 0 ) {
			$out[] = 'Abstand der Messpunkte [mm]';
		}
		if ( ! self::point( $product_id, 'anchor' ) ) {
			$out[] = 'Ankerpunkt';
		}
		if ( 'montage' === $role && ! self::point( $product_id, 'tube_anchor' ) ) {
			$out[] = 'Rohrmitte';
		}
		if ( 'montage' === $role && 'footprint' === self::mount_type( $product_id ) && ! self::outputs( $product_id ) ) {
			$out[] = 'Ausgang (Footprint des Rotpunkts)';
		}
		if ( 'zubehoer' === $role && ! self::group( $product_id ) ) {
			$out[] = 'Gruppe';
		}
		if ( 'zubehoer' === $role && in_array( self::group( $product_id ), array( 'muendungsaufsatz', 'hub', 'adapterschiene' ), true ) ) {
			if ( ! self::point( $product_id, 'tube_anchor' ) ) {
				$out[] = 'Ausgangspunkt';
			}
			if ( ! self::outputs( $product_id ) ) {
				$out[] = 'Ausgang';
			}
		}
		return $out;
	}

	/** Kann die Optik ohne Montage auf diese Waffe? */
	public static function optic_direct_fits( $weapon_id, $optic_id ) {
		if ( ! self::is_direct( $optic_id ) ) {
			return false;
		}
		return (bool) array_intersect( self::interfaces( $weapon_id ), self::interfaces( $optic_id ) );
	}

	/** Höhe der Rohrachse über der Schiene in mm (Montage: Ankerpunkt Unterseite → Rohrmitte). */
	public static function axis_height( $mount_id ) {
		$own = self::own_axis( $mount_id );
		if ( $own > 0 && 'montage' === self::role( $mount_id ) ) {
			return $own; // Von Hand eingetragen (Bildmessung bei Schrägbildern oft zu hoch).
		}
		return self::measured_axis_height( $mount_id );
	}

	/** Achshöhe aus den Bildpunkten Anker → Rohrmitte. */
	public static function measured_axis_height( $mount_id ) {
		$a = self::point( $mount_id, 'anchor' );
		$t = self::point( $mount_id, 'tube_anchor' );
		return $a && $t ? ( $a[1] - $t[1] ) * self::scale( $mount_id ) : 0.0;
	}

	/**
	 * Wie weit die Optik unter die Rohrachse reicht (Objektiv/Okular) in mm.
	 * Aus dem Punkt «Tiefster Punkt», sonst geschätzt aus dem Attribut Objektivdurchmesser (+ 4 mm Gehäuse), sonst 0 = unbekannt.
	 */
	public static function optic_depth( $optic_id ) {
		$a = self::point( $optic_id, 'anchor' );
		$b = self::point( $optic_id, 'bottom' );
		if ( $a && $b && self::scale( $optic_id ) > 0 ) {
			return max( 0.0, ( $b[1] - $a[1] ) * self::scale( $optic_id ) );
		}
		$p   = wc_get_product( $optic_id );
		$obj = $p ? (string) $p->get_attribute( 'pa_objektivdurchmesser' ) : '';
		return preg_match( '/\d+(?:[.,]\d+)?/', $obj, $m ) ? (float) str_replace( ',', '.', $m[0] ) / 2 + 4 : 0.0;
	}

	/** Freiraum der Waffe unter der Optik in mm (wie tief unter der Schienen-Oberkante vor/hinter der Montage noch Platz ist). */
	public static function clearance( $weapon_id ) {
		return max( 0.0, (float) str_replace( ',', '.', (string) self::meta( $weapon_id, 'freiraum', '0' ) ) );
	}

	/** Steht die Optik mit dieser Montage auf der Waffe auf? 1 mm Luft. Unbekannte Werte = kein Ausschluss. */
	public static function height_ok( $weapon_id, $mount_id, $optic_id ) {
		$depth  = self::optic_depth( $optic_id );
		$height = self::axis_height( $mount_id );
		if ( $depth <= 0 || $height <= 0 ) {
			return true;
		}
		return $height + self::clearance( $weapon_id ) >= $depth + 1;
	}

	/** Passt die Montage auf die Waffe und zur Optik? */
	public static function mount_fits( $weapon_id, $mount_id, $optic_id ) {
		if ( self::is_direct( $optic_id ) || '' !== self::mount_type( $mount_id ) ) {
			return false;
		}
		if ( ! array_intersect( self::interfaces( $weapon_id ), self::interfaces( $mount_id ) ) ) {
			return false;
		}
		$optic_tube = self::tube( $optic_id );
		$mount_tube = self::tube( $mount_id );
		return $optic_tube > 0 && abs( $optic_tube - $mount_tube ) < 0.01 && self::height_ok( $weapon_id, $mount_id, $optic_id );
	}

	/** Art der Montage: '', 'magnifier' oder 'footprint'. */
	public static function mount_type( $mount_id ) {
		$t = (string) self::meta( $mount_id, 'montage_art' );
		return isset( self::MOUNT_TYPES[ $t ] ) ? $t : '';
	}

	/** Länge des Produkts im Bild in mm (Abstand der Messpunkte, sonst Feld/Attribut Länge). */
	public static function length_mm( $product_id ) {
		$ref = (float) str_replace( ',', '.', (string) self::meta( $product_id, 'ref_mm', '0' ) );
		return $ref > 0 ? $ref : AK_Auto::length( $product_id );
	}

	/** Eigene Achshöhe in mm über der Auflagefläche (Rotpunkt, Magnifier mit Halterung), 0 = unbekannt. */
	public static function own_axis( $product_id ) {
		return max( 0.0, (float) str_replace( ',', '.', (string) self::meta( $product_id, 'achshoehe', '0' ) ) );
	}

	/** Passt die Adapterplatte (Montage-Art footprint) auf die Waffe und zum Footprint des Rotpunkts? */
	public static function plate_fits( $weapon_id, $plate_id, $optic_id ) {
		return self::is_direct( $optic_id ) && 'footprint' === self::mount_type( $plate_id )
			&& array_intersect( self::interfaces( $weapon_id ), self::interfaces( $plate_id ) )
			&& array_intersect( self::outputs( $plate_id ), self::interfaces( $optic_id ) );
	}

	/** Passt die Magnifier-Montage auf die Waffe und zum Mittelrohr des Magnifiers? */
	public static function mag_mount_fits( $weapon_id, $mount_id, $mag_id ) {
		if ( self::is_direct( $mag_id ) || 'magnifier' !== self::mount_type( $mount_id ) ) {
			return false;
		}
		$tube = self::tube( $mag_id );
		return $tube > 0 && abs( $tube - self::tube( $mount_id ) ) < 0.01 && array_intersect( self::interfaces( $weapon_id ), self::interfaces( $mount_id ) );
	}

	/** Kann ein Magnifier hinter diese Optik? Nur Rotpunkte (direkt, ohne Mittelrohr). */
	public static function takes_magnifier( $optic_id ) {
		return self::is_direct( $optic_id ) && self::tube( $optic_id ) <= 0;
	}

	/** Achshöhe des Rotpunkts über der Schiene (mit Adapterplatte), 0 = unbekannt. */
	public static function red_axis( $optic_id, $plate_id = 0 ) {
		$own = self::own_axis( $optic_id );
		if ( $own <= 0 ) {
			return 0.0;
		}
		return $own + ( $plate_id ? self::axis_height( $plate_id ) : 0.0 );
	}

	/** Achshöhe des Magnifiers über der Schiene, 0 = unbekannt. */
	public static function mag_axis( $mag_id, $mount_id = 0 ) {
		return $mount_id ? self::axis_height( $mount_id ) : self::own_axis( $mag_id );
	}

	/** x eines Punkts der Waffe in mm relativ zum Optik-Anker (Mündung = +x). */
	public static function weapon_x( $weapon_id, $key ) {
		$p = self::point( $weapon_id, $key );
		$a = self::point( $weapon_id, 'anchor' );
		$s = self::scale( $weapon_id );
		if ( ! $p || ! $a || $s <= 0 ) {
			return null;
		}
		$dx = $p[0] - $a[0];
		return ( 'yes' === self::meta( $weapon_id, 'flip', 'no' ) ? -$dx : $dx ) * $s;
	}

	/**
	 * Lage von Rotpunkt und Magnifier auf der Schiene: Mitte des Paars am Optik-Anker,
	 * Rotpunkt vorne, wenn nötig verschoben bis zum Schienenende. Liefert [x Rotpunkt, x Magnifier] oder null (kein Platz).
	 */
	public static function pair_offsets( $weapon_id, $len_red, $len_mag ) {
		$total = $len_red + self::MAG_GAP + $len_mag;
		$xr    = $total / 2 - $len_red / 2;
		$xm    = -$total / 2 + $len_mag / 2;
		$front = self::weapon_x( $weapon_id, 'rail_front' );
		$rear  = self::weapon_x( $weapon_id, 'rail_rear' );
		if ( null === $front || null === $rear ) {
			return array( $xr, $xm );
		}
		if ( $front < $rear ) {
			list( $front, $rear ) = array( $rear, $front );
		}
		if ( $total > $front - $rear + 0.5 ) {
			return null;
		}
		$shift = min( 0, $front - ( $xr + $len_red / 2 ) ) + max( 0, $rear - ( $xm - $len_mag / 2 ) );
		return array( $xr + $shift, $xm + $shift );
	}

	/** Passt diese Kombination aus Rotpunkt (+ Platte) und Magnifier (+ Montage)? Unbekannte Höhen = kein Ausschluss. */
	public static function mag_combo_ok( $weapon_id, $optic_id, $plate_id, $mag_id, $mag_mount_id ) {
		$a = self::red_axis( $optic_id, $plate_id );
		$b = self::mag_axis( $mag_id, $mag_mount_id );
		if ( $a > 0 && $b > 0 && abs( $a - $b ) > self::MAG_TOL ) {
			return false;
		}
		$lr = max( self::length_mm( $optic_id ), $plate_id ? self::length_mm( $plate_id ) : 0 );
		$lm = max( self::length_mm( $mag_id ), $mag_mount_id ? self::length_mm( $mag_mount_id ) : 0 );
		return null !== self::pair_offsets( $weapon_id, $lr, $lm );
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
		foreach ( $chain as $id ) {
			if ( $id && ! self::caliber_ok( $weapon_id, $id ) ) {
				return false; // Bohrung zu klein für das Kaliber der Waffe.
			}
		}
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
		if ( isset( self::$role_memo[ $role ] ) ) {
			return self::$role_memo[ $role ];
		}
		self::$role_memo[ $role ] = self::query_role( $role );
		return self::$role_memo[ $role ];
	}

	private static function query_role( $role ) {
		$ids = get_posts(
			array(
				'post_type'      => 'product',
				'post_status'    => 'publish',
				'posts_per_page' => -1,
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
		$weapon_id = (int) $weapon_id;
		if ( isset( self::$options_memo[ $weapon_id ] ) ) {
			return self::$options_memo[ $weapon_id ];
		}
		$ver = self::cache_version();
		$hit = get_transient( 'ak_opt_' . $weapon_id ); // Ein Eintrag pro Waffe, mit Version (kein Anwachsen der Tabelle).
		if ( is_array( $hit ) && isset( $hit['v'], $hit['o'] ) && $ver === (int) $hit['v'] ) {
			$o = $hit['o'];
		} else {
			$o = self::compute_options_for_weapon( $weapon_id );
			set_transient( 'ak_opt_' . $weapon_id, array( 'v' => $ver, 'o' => $o ), 12 * HOUR_IN_SECONDS );
		}
		self::$options_memo[ $weapon_id ] = $o;
		return $o;
	}

	/** Liegen die Optionen dieser Waffe schon im Cache? */
	public static function options_cached( $weapon_id ) {
		if ( isset( self::$options_memo[ (int) $weapon_id ] ) ) {
			return true;
		}
		$hit = get_transient( 'ak_opt_' . (int) $weapon_id );
		return is_array( $hit ) && isset( $hit['v'] ) && self::cache_version() === (int) $hit['v'];
	}

	/** Cache-Version: jede Änderung an Produkten oder _ak_-Feldern macht alle Einträge ungültig. */
	public static function cache_version() {
		return (int) get_option( 'ak_cache_ver', 1 );
	}

	public static function bump_cache() {
		if ( self::$bumped ) {
			return;
		}
		self::$bumped       = true;
		self::$options_memo = array();
		self::$role_memo    = array();
		update_option( 'ak_cache_ver', self::cache_version() + 1, false );
		if ( ! wp_next_scheduled( 'ak_warm_cache' ) ) {
			wp_schedule_single_event( time() + 120, 'ak_warm_cache' );
		}
	}

	public static function compute_options_for_weapon( $weapon_id ) {
		$blocked = self::blocked( $weapon_id );
		$allowed = function ( $ids ) use ( $weapon_id, $blocked ) {
			return array_values(
				array_filter(
					array_diff( $ids, $blocked ),
					function ( $id ) use ( $weapon_id ) {
						return self::platform_ok( $weapon_id, $id );
					}
				)
			);
		};
		$mounts  = $allowed( self::products_by_role( 'montage' ) );
		$optics  = array();
		$used_mounts = array();

		// Einmal pro Montage vorrechnen statt für jedes Paar Optik/Montage (sonst bei vielen Montagen zu langsam).
		$w_ifs     = self::interfaces( $weapon_id );
		$clearance = self::clearance( $weapon_id );
		$ring      = array(); // Montagen für Optik mit Mittelrohr: id => array( Rohr, Achshöhe ).
		$plate_out = array(); // Adapterplatten: id => Ausgänge.
		foreach ( $mounts as $mount_id ) {
			$type = self::mount_type( $mount_id );
			if ( 'magnifier' === $type || ! array_intersect( $w_ifs, self::interfaces( $mount_id ) ) ) {
				continue;
			}
			if ( 'footprint' === $type ) {
				$plate_out[ $mount_id ] = self::outputs( $mount_id );
			} else {
				$ring[ $mount_id ] = array( self::tube( $mount_id ), self::axis_height( $mount_id ) );
			}
		}

		$pistol = 'pistole' === self::weapon_type( $weapon_id );
		foreach ( $allowed( self::products_by_role( 'optik' ) ) as $optic_id ) {
			$direct = self::is_direct( $optic_id );
			if ( $pistol && ! $direct ) {
				continue; // Pistole: nur Rotpunkte (direkt oder über Adapterplatte), keine Zielfernrohre.
			}
			if ( $direct ) {
				$o_ifs = self::interfaces( $optic_id );
				if ( array_intersect( $w_ifs, $o_ifs ) ) {
					$optics[ $optic_id ] = array();
					continue;
				}
				// Rotpunkt mit Footprint auf Schiene: über eine passende Adapterplatte.
				$plates = array();
				foreach ( $plate_out as $plate_id => $outs ) {
					if ( array_intersect( $outs, $o_ifs ) ) {
						$plates[] = $plate_id;
					}
				}
				if ( $plates ) {
					$optics[ $optic_id ] = $plates;
					$used_mounts         = array_merge( $used_mounts, $plates );
				}
				continue;
			}
			$o_tube = self::tube( $optic_id );
			if ( $o_tube <= 0 ) {
				continue;
			}
			$depth   = self::optic_depth( $optic_id );
			$fitting = array();
			foreach ( $ring as $mount_id => $info ) {
				list( $m_tube, $height ) = $info;
				if ( abs( $o_tube - $m_tube ) >= 0.01 ) {
					continue;
				}
				// Wie height_ok(): Optik darf nicht auf der Waffe aufliegen.
				if ( $depth > 0 && $height > 0 && $height + $clearance < $depth + 1 ) {
					continue;
				}
				$fitting[] = $mount_id;
			}
			if ( $fitting ) {
				$optics[ $optic_id ] = $fitting;
				$used_mounts         = array_merge( $used_mounts, $fitting );
			}
		}

		$parts = array();
		$muzzle = array();
		$all_acc  = $allowed( self::products_by_role( 'zubehoer' ) );
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
		// Lineare Prüfung statt Dreifachschleife (gleiches Ergebnis wie muzzle_chain_valid für jede Kombination).
		$ok     = array();
		$thread = self::weapon_slot( $weapon_id, 'muendung' );
		$t_ifs  = $thread ? $thread['interfaces'] : array();
		$base   = array(); // Enden ohne Mündungsaufsatz (Gewinde, Originalaufsatz).
		foreach ( self::muzzle_ends( $weapon_id ) as $end ) {
			$base = array_merge( $base, $end['interfaces'] );
		}
		$cal = array();
		$cal_ok = function ( $id ) use ( $weapon_id, &$cal ) {
			if ( ! isset( $cal[ $id ] ) ) {
				$cal[ $id ] = self::caliber_ok( $weapon_id, $id );
			}
			return $cal[ $id ];
		};
		// Ausgänge der gültigen Mündungsaufsätze.
		$sources = array( 0 => $base ); // 0 = kein Aufsatz.
		foreach ( array_slice( $aufs, 1 ) as $a ) {
			if ( $t_ifs && $cal_ok( $a ) && array_intersect( $t_ifs, self::interfaces( $a ) ) ) {
				$ok['muendungsaufsatz'][ $a ] = true;
				$sources[ $a ]                = self::outputs( $a );
			}
		}
		// HUBs: auf einem gültigen Ende. Merkt sich, über welche Aufsätze der HUB erreichbar ist.
		$hub_out = array();
		foreach ( array_slice( $hubs, 1 ) as $h ) {
			if ( ! $cal_ok( $h ) ) {
				continue;
			}
			$h_ifs = self::interfaces( $h );
			foreach ( $sources as $a => $outs ) {
				if ( array_intersect( $outs, $h_ifs ) ) {
					$ok['hub'][ $h ] = true;
					$hub_out[ $h ][] = $a;
				}
			}
		}
		// Schalldämpfer: direkt auf einem Ende oder auf einem erreichbaren HUB.
		foreach ( array_slice( $sds, 1 ) as $d ) {
			if ( ! $cal_ok( $d ) ) {
				continue;
			}
			$d_ifs = self::interfaces( $d );
			foreach ( $sources as $a => $outs ) {
				if ( array_intersect( $outs, $d_ifs ) ) {
					$ok['schalldaempfer'][ $d ] = true;
					break;
				}
			}
			foreach ( $hub_out as $h => $via ) {
				if ( array_intersect( self::outputs( $h ), $d_ifs ) ) {
					$ok['schalldaempfer'][ $d ] = true;
				}
			}
		}
		foreach ( self::MUZZLE_CHAIN as $g ) {
			if ( ! empty( $ok[ $g ] ) ) {
				$parts[ $g ] = array_map( 'intval', array_keys( $ok[ $g ] ) );
			}
		}

		// Magnifier: mit eigener Halterung direkt auf die Schiene, sonst über eine Magnifier-Montage.
		$mags       = array();
		$mag_mounts = array();
		foreach ( $pistol ? array() : $allowed( self::products_by_role( 'magnifier' ) ) as $mag_id ) {
			if ( self::optic_direct_fits( $weapon_id, $mag_id ) ) {
				$mags[ $mag_id ] = array();
				continue;
			}
			$fit = array_values(
				array_filter(
					$mounts,
					function ( $m ) use ( $weapon_id, $mag_id ) {
						return self::mag_mount_fits( $weapon_id, $m, $mag_id );
					}
				)
			);
			if ( $fit ) {
				$mags[ $mag_id ] = $fit;
				$mag_mounts      = array_merge( $mag_mounts, $fit );
			}
		}
		$optic_mags = array();
		foreach ( $optics as $optic_id => $plates ) {
			if ( ! $mags || ! self::takes_magnifier( $optic_id ) ) {
				continue;
			}
			foreach ( $mags as $mag_id => $mm ) {
				$ok = false;
				foreach ( ( $plates ? $plates : array( 0 ) ) as $plate ) {
					foreach ( ( $mm ? $mm : array( 0 ) ) as $m ) {
						if ( self::mag_combo_ok( $weapon_id, $optic_id, $plate, $mag_id, $m ) ) {
							$ok = true;
							break 2;
						}
					}
				}
				if ( $ok ) {
					$optic_mags[ $optic_id ][] = (int) $mag_id;
				}
			}
		}

		return array(
			'optics'     => $optics,
			'mounts'     => array_values( array_unique( $used_mounts ) ),
			'parts'      => $parts,
			'adapters'   => $adapters,
			'slots'      => $slots,
			'magnifiers' => $mags,
			'mag_mounts' => array_values( array_unique( $mag_mounts ) ),
			'optic_mags' => $optic_mags,
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
	public static function validate_selection( $weapon_id, $optic_id, $mount_id, $parts = array(), $adapters = array(), $mag_id = 0, $mag_mount_id = 0 ) {
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
		if ( $mag_id ) {
			if ( ! $optic_id || empty( $options['optic_mags'][ $optic_id ] ) || ! in_array( $mag_id, $options['optic_mags'][ $optic_id ], true ) ) {
				return new WP_Error( 'ak_mag', 'Dieser Magnifier passt nicht zur gewählten Optik.' );
			}
			$need = $options['magnifiers'][ $mag_id ];
			if ( $need && ! in_array( $mag_mount_id, $need, true ) ) {
				return new WP_Error( 'ak_mag', 'Bitte eine passende Halterung für den Magnifier wählen.' );
			}
			if ( ! $need && $mag_mount_id ) {
				return new WP_Error( 'ak_mag', 'Dieser Magnifier hat schon eine Halterung.' );
			}
			if ( ! self::mag_combo_ok( $weapon_id, $optic_id, $mount_id, $mag_id, $mag_mount_id ) ) {
				return new WP_Error( 'ak_mag', 'Magnifier und Rotpunkt haben nicht die gleiche Achshöhe oder zu wenig Platz auf der Schiene.' );
			}
		} elseif ( $mag_mount_id ) {
			return new WP_Error( 'ak_mag', 'Eine Magnifier-Halterung gibt es nur zusammen mit einem Magnifier.' );
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
		if ( $anchor && 'montage' === self::role( $product_id ) && self::own_axis( $product_id ) > 0 && self::scale( $product_id ) > 0 ) {
			// Achshöhe von Hand: Rohrmitte senkrecht über dem Ankerpunkt in der eingetragenen Höhe.
			$tube_a = array( $tube_a ? $tube_a[0] : $anchor[0], $anchor[1] - self::own_axis( $product_id ) / self::scale( $product_id ) );
		}

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
			'length'     => self::length_mm( $product_id ),
			'axis'       => self::own_axis( $product_id ),
			'axisHeight' => 'montage' === self::role( $product_id ) ? self::axis_height( $product_id ) : 0,
			'mountType'  => 'montage' === self::role( $product_id ) ? self::mount_type( $product_id ) : '',
		);
	}
}
