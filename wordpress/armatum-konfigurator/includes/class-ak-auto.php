<?php
/**
 * Automatisches Einmessen: findet den Umriss des Produkts im Bild (weisser oder transparenter
 * Hintergrund, Seitenansicht) und setzt daraus Messpunkte, Massstab und Ankerpunkte je Gruppe.
 * Ausserdem Rolle/Gruppe aus der Kategorie-Zuordnung (Einstellungen).
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class AK_Auto {

	/** Ziele der Kategorie-Zuordnung: Schlüssel => [Rolle, Gruppe, direkt, Bezeichnung]. */
	const TARGETS = array(
		'waffe'            => array( 'waffe', '', false, 'Waffe' ),
		'optik'            => array( 'optik', '', false, 'Optik mit Mittelrohr (braucht Montage)' ),
		'optik_direkt'     => array( 'optik', '', true, 'Optik direkt auf Schiene (Rotpunkt, integrierte Montage)' ),
		'montage'          => array( 'montage', '', false, 'Montage für Optik' ),
		'muendungsaufsatz' => array( 'zubehoer', 'muendungsaufsatz', false, 'Mündungsaufsatz' ),
		'hub'              => array( 'zubehoer', 'hub', false, 'HUB' ),
		'schalldaempfer'   => array( 'zubehoer', 'schalldaempfer', false, 'Schalldämpfer' ),
		'handgriff'        => array( 'zubehoer', 'handgriff', false, 'Handgriff' ),
		'laser'            => array( 'zubehoer', 'laser', false, 'Laser' ),
		'schaft'           => array( 'zubehoer', 'schaft', false, 'Schulterstütze' ),
		'griff'            => array( 'zubehoer', 'griff', false, 'Griff' ),
		'adapterschiene'   => array( 'zubehoer', 'adapterschiene', false, 'Adapterschiene (M-LOK auf Picatinny)' ),
	);

	public static function init() {
		add_action( 'wp_ajax_ak_auto_preview', array( __CLASS__, 'ajax_preview' ) );
		add_action( 'wp_ajax_ak_auto_list', array( __CLASS__, 'ajax_list' ) );
		add_action( 'wp_ajax_ak_auto_run', array( __CLASS__, 'ajax_run' ) );
	}

	/** Option ak_cat_map: Ziel => Liste von Kategorie-IDs. */
	public static function cat_map() {
		$map = get_option( 'ak_cat_map', array() );
		return is_array( $map ) ? $map : array();
	}

	/** Ziel aus den Kategorien des Produkts (spezifischere Ziele zuerst, Optik direkt vor Optik). */
	public static function target_for( $product_id ) {
		$cats = wp_get_post_terms( $product_id, 'product_cat', array( 'fields' => 'ids' ) );
		if ( is_wp_error( $cats ) || ! $cats ) {
			return '';
		}
		$map   = self::cat_map();
		$order = array( 'adapterschiene', 'schalldaempfer', 'hub', 'muendungsaufsatz', 'handgriff', 'laser', 'schaft', 'griff', 'montage', 'optik_direkt', 'optik', 'waffe' );
		foreach ( $order as $t ) {
			if ( ! empty( $map[ $t ] ) && array_intersect( array_map( 'intval', $map[ $t ] ), $cats ) ) {
				return $t;
			}
		}
		return '';
	}

	/** Länge in mm: Feld «Länge» im Konfigurator, sonst Attribut «Länge [mm]». */
	public static function length( $product_id ) {
		$own = AK_Data::meta( $product_id, 'laenge' );
		if ( '' === $own ) {
			$p   = wc_get_product( $product_id );
			$own = $p ? $p->get_attribute( 'pa_laenge' ) : '';
		}
		$own = str_replace( ',', '.', (string) $own );
		return preg_match( '/\d+(\.\d+)?/', $own, $m ) ? (float) $m[0] : 0.0;
	}

	/**
	 * Bild analysieren. Liefert Umriss (Bildpixel, nicht gespiegelt) und Spaltenprofile
	 * oder WP_Error.
	 */
	public static function analyse_file( $file ) {
		if ( ! function_exists( 'imagecreatefromstring' ) ) {
			return new WP_Error( 'ak_gd', 'Die PHP-Erweiterung GD fehlt auf dem Server.' );
		}
		$data = $file && is_readable( $file ) ? file_get_contents( $file ) : false; // phpcs:ignore WordPress.WP.AlternativeFunctions
		$src  = $data ? @imagecreatefromstring( $data ) : false; // phpcs:ignore WordPress.PHP.NoSilencedErrors
		if ( ! $src ) {
			return new WP_Error( 'ak_img', 'Bild kann nicht gelesen werden.' );
		}
		$w = imagesx( $src );
		$h = imagesy( $src );
		// Für die Analyse auf höchstens 800 px Breite verkleinern.
		$f  = max( 1, $w / 800 );
		$sw = max( 1, (int) round( $w / $f ) );
		$sh = max( 1, (int) round( $h / $f ) );
		$im = imagecreatetruecolor( $sw, $sh );
		imagealphablending( $im, false );
		imagesavealpha( $im, true );
		imagecopyresampled( $im, $src, 0, 0, 0, 0, $sw, $sh, $w, $h );
		imagedestroy( $src );

		// Hintergrund aus den vier Ecken.
		$corners = array( array( 1, 1 ), array( $sw - 2, 1 ), array( 1, $sh - 2 ), array( $sw - 2, $sh - 2 ) );
		$alpha   = 0;
		$bg      = array( 0, 0, 0 );
		foreach ( $corners as $c ) {
			$px     = imagecolorat( $im, max( 0, $c[0] ), max( 0, $c[1] ) );
			$alpha += ( $px >> 24 ) & 0x7F;
			$bg[0] += ( $px >> 16 ) & 0xFF;
			$bg[1] += ( $px >> 8 ) & 0xFF;
			$bg[2] += $px & 0xFF;
		}
		$transparent = $alpha / 4 > 100;
		$bg          = array( $bg[0] / 4, $bg[1] / 4, $bg[2] / 4 );

		$top = array();
		$bot = array();
		for ( $x = 0; $x < $sw; $x++ ) {
			$first = -1;
			$last  = -1;
			$count = 0;
			for ( $y = 0; $y < $sh; $y++ ) {
				$px = imagecolorat( $im, $x, $y );
				if ( $transparent ) {
					$fg = ( ( $px >> 24 ) & 0x7F ) < 64;
				} else {
					$d  = max( abs( ( ( $px >> 16 ) & 0xFF ) - $bg[0] ), abs( ( ( $px >> 8 ) & 0xFF ) - $bg[1] ), abs( ( $px & 0xFF ) - $bg[2] ) );
					$fg = $d > 28 && ( ( $px >> 24 ) & 0x7F ) < 64;
				}
				if ( $fg ) {
					if ( $first < 0 ) {
						$first = $y;
					}
					$last = $y;
					++$count;
				}
			}
			if ( $count >= 2 ) {
				$top[ $x ] = $first;
				$bot[ $x ] = $last;
			}
		}
		imagedestroy( $im );
		if ( count( $top ) < 10 ) {
			return new WP_Error( 'ak_empty', 'Im Bild wurde kein Produkt erkannt (Hintergrund nicht weiss oder transparent?).' );
		}
		$xs = array_keys( $top );
		return array(
			'factor'      => $f,
			'w'           => $w,
			'h'           => $h,
			'minx'        => min( $xs ),
			'maxx'        => max( $xs ),
			'miny'        => min( $top ),
			'maxy'        => max( $bot ),
			'top'         => $top,
			'bot'         => $bot,
			'transparent' => $transparent,
		);
	}

	/** Mitte der Spalten in einem Bereich [x0, x1] (Analysepixel). */
	private static function mid_y( $a, $x0, $x1 ) {
		$sum = 0;
		$n   = 0;
		for ( $x = (int) $x0; $x <= (int) $x1; $x++ ) {
			if ( isset( $a['top'][ $x ] ) ) {
				$sum += ( $a['top'][ $x ] + $a['bot'][ $x ] ) / 2;
				++$n;
			}
		}
		return $n ? $sum / $n : ( $a['miny'] + $a['maxy'] ) / 2;
	}

	/**
	 * Punkte berechnen.
	 *
	 * @param array $a      Ergebnis von analyse_file().
	 * @param array $o      role, gruppe, direkt (bool), flip (bool), tube (mm), length (mm).
	 * @return array|WP_Error  Punkte in Original-Bildpixeln: p1, p2, ref_mm, anchor, tube_anchor, mmPerPx.
	 */
	public static function compute( $a, $o ) {
		$length = (float) $o['length'];
		if ( $length <= 0 ) {
			return new WP_Error( 'ak_len', 'Länge fehlt (Attribut «Länge [mm]» oder Feld «Länge»).' );
		}
		$f    = $a['factor'];
		$minx = $a['minx'];
		$maxx = $a['maxx'];
		$wpx  = max( 1, $maxx - $minx );
		$mmpp = $length / ( $wpx * $f ); // mm pro Originalpixel
		$band = max( 2, (int) round( $wpx * 0.03 ) );
		$flip = ! empty( $o['flip'] );
		// In Blickrichtung des Konfigurators (Mündung rechts): bei gespiegeltem Bild sind links/rechts vertauscht.
		$rear_x  = $flip ? $maxx : $minx;
		$front_x = $flip ? $minx : $maxx;
		$rear_y  = $flip ? self::mid_y( $a, $maxx - $band, $maxx ) : self::mid_y( $a, $minx, $minx + $band );
		$front_y = $flip ? self::mid_y( $a, $minx, $minx + $band ) : self::mid_y( $a, $maxx - $band, $maxx );
		$cx      = ( $minx + $maxx ) / 2;
		$cy      = ( $a['miny'] + $a['maxy'] ) / 2;

		$anchor = null;
		$tube_a = null;
		$group  = (string) $o['gruppe'];
		switch ( $o['role'] ) {
			case 'waffe':
				break;
			case 'montage':
				$anchor = array( $cx, $a['maxy'] );
				$tube   = $o['tube'] > 0 ? $o['tube'] : 30;
				// Ringmitte: Oberkante der Ringe plus Rohrradius plus ca. 3 mm Ringwandstärke.
				$tube_a = array( $cx, $a['miny'] + ( $tube / 2 + 3 ) / ( $mmpp * $f ) );
				break;
			case 'optik':
				if ( ! empty( $o['direkt'] ) ) {
					$anchor = array( $cx, $a['maxy'] );
					break;
				}
				// Rohrachse: Spalte im mittleren Bereich, deren Höhe am besten zum Mittelrohr passt.
				$expect = $o['tube'] > 0 ? $o['tube'] / ( $mmpp * $f ) : 0;
				$best   = null;
				$score  = INF;
				for ( $x = (int) ( $minx + $wpx * 0.2 ); $x <= (int) ( $minx + $wpx * 0.8 ); $x++ ) {
					if ( ! isset( $a['top'][ $x ] ) ) {
						continue;
					}
					$ext = $a['bot'][ $x ] - $a['top'][ $x ];
					$s   = $expect > 0 ? abs( $ext - $expect ) : $ext;
					if ( $s < $score ) {
						$score = $s;
						$best  = $x;
					}
				}
				$anchor = array( $cx, null === $best ? $cy : ( $a['top'][ $best ] + $a['bot'][ $best ] ) / 2 );
				break;
			case 'zubehoer':
				if ( in_array( $group, array( 'muendungsaufsatz', 'hub', 'schalldaempfer' ), true ) ) {
					$anchor = array( $rear_x, $rear_y );
					if ( 'schalldaempfer' !== $group ) {
						$tube_a = array( $front_x, $front_y );
					}
				} elseif ( 'adapterschiene' === $group ) {
					// Befestigungsfläche oben (am Handschutz), Schiene unten.
					$anchor = array( $cx, $a['miny'] );
					$tube_a = array( $cx, $a['maxy'] );
				} elseif ( 'schaft' === $group ) {
					$anchor = array( $front_x, $front_y );
				} elseif ( 'griff' === $group ) {
					// Oberer vorderer Punkt: in den obersten 5 % die Spalte am weitesten vorne.
					$limit = $a['miny'] + ( $a['maxy'] - $a['miny'] ) * 0.05;
					$gx    = $front_x;
					$found = null;
					foreach ( $a['top'] as $x => $t ) {
						if ( $t <= $limit && ( null === $found || ( $flip ? $x < $found : $x > $found ) ) ) {
							$found = $x;
						}
					}
					$anchor = array( null === $found ? $gx : $found, $a['miny'] );
				} else {
					$anchor = array( $cx, $a['miny'] );
				}
				break;
		}

		$scale = function ( $p ) use ( $f ) {
			return null === $p ? '' : round( $p[0] * $f, 1 ) . ',' . round( $p[1] * $f, 1 );
		};
		return array(
			'p1'          => $scale( array( $minx, $cy ) ),
			'p2'          => $scale( array( $maxx, $cy ) ),
			'ref_mm'      => $length,
			'anchor'      => $scale( $anchor ),
			'tube_anchor' => $scale( $tube_a ),
			'mmPerPx'     => $mmpp,
		);
	}

	/** Optionen eines gespeicherten Produkts für compute(). */
	public static function options_for( $product_id ) {
		return array(
			'role'   => AK_Data::role( $product_id ),
			'gruppe' => AK_Data::group( $product_id ),
			'direkt' => AK_Data::is_direct( $product_id ),
			'flip'   => 'yes' === AK_Data::meta( $product_id, 'flip', 'no' ),
			'tube'   => AK_Data::tube( $product_id ),
			'length' => self::length( $product_id ),
		);
	}

	/**
	 * Ein Produkt einrichten: Rolle aus Kategorie (falls leer), dann einmessen.
	 * Bestehende Punkte bleiben, ausser $overwrite.
	 *
	 * @return array status (ok|skip|error), message, role, gruppe, length
	 */
	public static function process( $product_id, $overwrite = false, $dry = false ) {
		$res  = array(
			'id'     => $product_id,
			'name'   => get_the_title( $product_id ),
			'edit'   => get_edit_post_link( $product_id, 'raw' ),
			'status' => 'ok',
			'msg'    => '',
		);
		$role = AK_Data::role( $product_id );
		if ( ! $role ) {
			$t = self::target_for( $product_id );
			if ( ! $t ) {
				return array_merge( $res, array( 'status' => 'skip', 'msg' => 'Keine Rolle und keine passende Kategorie.' ) );
			}
			$def = self::TARGETS[ $t ];
			if ( ! $dry ) {
				update_post_meta( $product_id, '_ak_role', $def[0] );
				if ( $def[1] ) {
					update_post_meta( $product_id, '_ak_gruppe', $def[1] );
				}
				if ( $def[2] ) {
					update_post_meta( $product_id, '_ak_direkt', 'yes' );
				}
			}
			$res['msg'] = 'Rolle aus Kategorie: ' . $def[3] . '. ';
			$role       = $def[0];
		}
		$o = $dry && ! AK_Data::role( $product_id ) && isset( $def )
			? array_merge( self::options_for( $product_id ), array( 'role' => $def[0], 'gruppe' => $def[1], 'direkt' => $def[2] ) )
			: self::options_for( $product_id );
		$res['role']   = $o['gruppe'] ? AK_Data::GROUPS[ $o['gruppe'] ][1] : ucfirst( $o['role'] ) . ( $o['direkt'] ? ' (direkt)' : '' );
		$res['length'] = $o['length'];

		if ( ! $overwrite && AK_Data::point( $product_id, 'p1' ) && ( 'waffe' === $role || AK_Data::point( $product_id, 'anchor' ) ) ) {
			return array_merge( $res, array( 'status' => 'skip', 'msg' => $res['msg'] . 'Schon eingemessen.' ) );
		}
		$image = AK_Data::image_id( $product_id );
		if ( ! $image ) {
			return array_merge( $res, array( 'status' => 'error', 'msg' => $res['msg'] . 'Kein Bild.' ) );
		}
		$a = self::analyse_file( get_attached_file( $image ) );
		if ( is_wp_error( $a ) ) {
			return array_merge( $res, array( 'status' => 'error', 'msg' => $res['msg'] . $a->get_error_message() ) );
		}
		$pts = self::compute( $a, $o );
		if ( is_wp_error( $pts ) ) {
			return array_merge( $res, array( 'status' => 'error', 'msg' => $res['msg'] . $pts->get_error_message() ) );
		}
		if ( ! $dry ) {
			foreach ( array( 'p1', 'p2', 'ref_mm', 'anchor', 'tube_anchor' ) as $k ) {
				if ( '' !== $pts[ $k ] ) {
					update_post_meta( $product_id, '_ak_' . $k, (string) $pts[ $k ] );
				}
			}
			update_post_meta( $product_id, '_ak_auto', gmdate( 'Y-m-d' ) );
		}
		$res['msg'] .= 'Eingemessen (' . round( $pts['mmPerPx'], 3 ) . ' mm/px).' . ( 'waffe' === $role ? ' Steckplätze und Ankerpunkt bitte selbst setzen.' : '' );
		return $res;
	}

	/** Produkte mit Rolle oder in zugeordneten Kategorien. */
	public static function candidates() {
		$ids  = get_posts(
			array(
				'post_type'      => 'product',
				'post_status'    => array( 'publish', 'draft', 'private', 'pending' ),
				'posts_per_page' => -1,
				'fields'         => 'ids',
				'meta_key'       => '_ak_role',
				'meta_compare'   => 'EXISTS',
			)
		);
		$cats = array();
		foreach ( self::cat_map() as $list ) {
			$cats = array_merge( $cats, array_map( 'intval', (array) $list ) );
		}
		if ( $cats ) {
			$ids = array_merge(
				$ids,
				get_posts(
					array(
						'post_type'      => 'product',
						'post_status'    => array( 'publish', 'draft', 'private', 'pending' ),
						'posts_per_page' => -1,
						'fields'         => 'ids',
						'tax_query'      => array( // phpcs:ignore WordPress.DB.SlowDBQuery
							array(
								'taxonomy'         => 'product_cat',
								'terms'            => array_unique( $cats ),
								'include_children' => false,
							),
						),
					)
				)
			);
		}
		return array_values( array_unique( array_map( 'intval', $ids ) ) );
	}

	private static function check() {
		check_ajax_referer( 'ak_auto', 'nonce' );
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_send_json_error( array( 'message' => 'Keine Berechtigung.' ), 403 );
		}
	}

	public static function ajax_list() {
		self::check();
		wp_send_json_success( array( 'ids' => self::candidates() ) );
	}

	public static function ajax_run() {
		self::check();
		$id = isset( $_POST['id'] ) ? absint( $_POST['id'] ) : 0;
		if ( ! $id || 'product' !== get_post_type( $id ) ) {
			wp_send_json_error( array( 'message' => 'Unbekanntes Produkt.' ) );
		}
		wp_send_json_success( self::process( $id, ! empty( $_POST['overwrite'] ), ! empty( $_POST['dry'] ) ) );
	}

	/** Vorschau für den Knopf im Produkt: rechnet mit den Werten im Formular, speichert nichts. */
	public static function ajax_preview() {
		check_ajax_referer( 'ak_auto', 'nonce' );
		$id = isset( $_POST['id'] ) ? absint( $_POST['id'] ) : 0;
		if ( ! $id || ! current_user_can( 'edit_post', $id ) ) {
			wp_send_json_error( array( 'message' => 'Keine Berechtigung.' ), 403 );
		}
		$p      = wp_unslash( $_POST );
		$image  = absint( $p['image_id'] ?? 0 );
		$image  = $image ? $image : (int) get_post_thumbnail_id( $id );
		$len    = (float) str_replace( ',', '.', (string) ( $p['laenge'] ?? '' ) );
		$tube   = (float) str_replace( ',', '.', (string) ( $p['mittelrohr'] ?? '' ) );
		$o      = array(
			'role'   => sanitize_key( $p['role'] ?? '' ),
			'gruppe' => sanitize_key( $p['gruppe'] ?? '' ),
			'direkt' => ! empty( $p['direkt'] ),
			'flip'   => ! empty( $p['flip'] ),
			'tube'   => $tube > 0 ? $tube : AK_Data::tube( $id ),
			'length' => $len > 0 ? $len : self::length( $id ),
		);
		if ( ! $o['role'] ) {
			wp_send_json_error( array( 'message' => 'Zuerst eine Rolle wählen.' ) );
		}
		if ( ! $image ) {
			wp_send_json_error( array( 'message' => 'Kein Bild.' ) );
		}
		$a = self::analyse_file( get_attached_file( $image ) );
		if ( is_wp_error( $a ) ) {
			wp_send_json_error( array( 'message' => $a->get_error_message() ) );
		}
		$pts = self::compute( $a, $o );
		if ( is_wp_error( $pts ) ) {
			wp_send_json_error( array( 'message' => $pts->get_error_message() ) );
		}
		wp_send_json_success( $pts );
	}
}
