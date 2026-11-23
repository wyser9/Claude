<?php
/**
 * Frontend:
 *  - Konfigurator-Seite mit Shortcode [armatum_konfigurator] (ohne Waffe: Waffenauswahl).
 *    URL-Parameter: ?waffe=ID (Waffe vorwählen), ?teil=ID (Zubehör vorwählen).
 *  - Knopf auf Produktseiten: Waffe → «Diese Waffe konfigurieren», Zubehör → «Im Konfigurator ansehen».
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class AK_Frontend {

	public static function init() {
		add_action( 'woocommerce_single_product_summary', array( __CLASS__, 'product_button' ), 35 );
		add_shortcode( 'armatum_konfigurator', array( __CLASS__, 'shortcode' ) );
	}

	public static function page_url( $args = array() ) {
		$page_id = (int) get_option( 'ak_page_id' );
		if ( ! $page_id || ! get_post( $page_id ) ) {
			return '';
		}
		return add_query_arg( $args, get_permalink( $page_id ) );
	}

	private static function assets() {
		wp_enqueue_style( 'ak-front', AK_URL . 'assets/konfigurator.css', array(), AK_VERSION );
		wp_enqueue_script( 'ak-front', AK_URL . 'assets/konfigurator.js', array( 'jquery' ), AK_VERSION, true );
	}

	/** Waffen, die im Konfigurator angeboten werden. Admins sehen auch Entwürfe (zum Testen). */
	public static function weapons() {
		$status = current_user_can( 'edit_products' ) ? array( 'publish', 'draft', 'private', 'pending' ) : array( 'publish' );
		$ids    = get_posts(
			array(
				'post_type'      => 'product',
				'post_status'    => $status,
				'posts_per_page' => -1,
				'fields'         => 'ids',
				'meta_key'       => '_ak_role',
				'meta_value'     => 'waffe',
				'orderby'        => 'title',
				'order'          => 'ASC',
			)
		);
		return array_values( array_filter( $ids, array( 'AK_Data', 'is_ready' ) ) );
	}

	/** Kommt das Produkt in den Optionen dieser Waffe vor? */
	public static function part_fits_weapon( $weapon_id, $part_id ) {
		return (bool) self::resolve_part( $weapon_id, $part_id );
	}

	/** ID im Konfigurator für ein Produkt: es selbst oder (bei variablen Produkten) die erste passende Variante, sonst 0. */
	public static function resolve_part( $weapon_id, $part_id ) {
		foreach ( array_merge( array( (int) $part_id ), AK_Data::variations( $part_id ) ) as $id ) {
			if ( self::in_options( $weapon_id, $id ) ) {
				return $id;
			}
		}
		return 0;
	}

	private static function in_options( $weapon_id, $part_id ) {
		$o = AK_Data::options_for_weapon( $weapon_id );
		if ( array_key_exists( $part_id, $o['optics'] ) || in_array( $part_id, $o['mounts'], true ) || in_array( $part_id, $o['mag_mounts'], true ) ) {
			return true;
		}
		if ( isset( $o['magnifiers'][ $part_id ] ) && array_filter( $o['optic_mags'], function ( $l ) use ( $part_id ) { return in_array( $part_id, $l, true ); } ) ) {
			return true;
		}
		foreach ( $o['parts'] as $ids ) {
			if ( in_array( $part_id, $ids, true ) ) {
				return true;
			}
		}
		foreach ( $o['adapters'] as $ids ) {
			if ( in_array( $part_id, $ids, true ) ) {
				return true;
			}
		}
		return false;
	}

	public static function product_button() {
		global $product;
		if ( ! $product instanceof WC_Product || ! AK_Data::visible_for_current_user() ) {
			return;
		}
		if ( ! self::page_url() ) {
			return; // Noch keine Konfigurator-Seite: kein Knopf und keine Prüfung.
		}
		$id   = $product->get_id();
		$role = AK_Data::role( $id );
		if ( ! $role || ! AK_Data::is_ready( $id ) ) {
			return;
		}
		if ( 'waffe' === $role ) {
			$url   = self::page_url( array( 'waffe' => $id ) );
			$label = 'Diese Waffe konfigurieren';
		} else {
			// Zubehör: nur zwischengespeicherte Ergebnisse verwenden, damit die Produktseite schnell bleibt.
			// Die Waffenauswahl im Konfigurator zeigt danach nur passende Waffen.
			$args   = array( 'teil' => $id );
			$fits   = array();
			$all    = true;
			foreach ( self::weapons() as $w ) {
				if ( ! AK_Data::options_cached( $w ) ) {
					$all = false;
					continue;
				}
				if ( self::part_fits_weapon( $w, $id ) ) {
					$fits[] = $w;
				}
			}
			if ( $all && ! $fits ) {
				return;
			}
			if ( $all && 1 === count( $fits ) ) {
				$args['waffe'] = reset( $fits );
			}
			$url   = self::page_url( $args );
			$label = 'Im Konfigurator ansehen';
		}
		if ( ! $url ) {
			return;
		}
		self::assets();
		echo '<p class="ak-product-cta"><a class="button ak-cta" href="' . esc_url( $url ) . '">' . esc_html( $label ) . '</a></p>';
	}

	/**
	 * Waffen, auf die das Teil passt. Rechnet höchstens $budget Sekunden neu;
	 * Waffen, die danach noch nicht geprüft sind, werden mit angezeigt (Prüfung beim Öffnen der Waffe).
	 */
	public static function weapons_for_part( $weapons, $part_id, $budget = 8 ) {
		$start = microtime( true );
		$out   = array();
		foreach ( $weapons as $w ) {
			if ( ! AK_Data::options_cached( $w ) && ( microtime( true ) - $start ) > $budget ) {
				$out[] = $w;
				continue;
			}
			if ( self::part_fits_weapon( $w, $part_id ) ) {
				$out[] = $w;
			}
		}
		return $out;
	}

	public static function shortcode( $atts ) {
		if ( ! AK_Data::visible_for_current_user() ) {
			return '';
		}
		$atts      = shortcode_atts( array( 'waffe' => 0 ), $atts );
		$weapon_id = (int) $atts['waffe'];
		if ( ! $weapon_id && isset( $_GET['waffe'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification
			$weapon_id = absint( $_GET['waffe'] ); // phpcs:ignore WordPress.Security.NonceVerification
		}
		$part_id = isset( $_GET['teil'] ) ? absint( $_GET['teil'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification

		if ( $weapon_id && 'waffe' === AK_Data::role( $weapon_id ) && AK_Data::is_ready( $weapon_id ) ) {
			return self::render( $weapon_id, $part_id );
		}
		return self::render_picker( $part_id );
	}

	private static function testmode_badge() {
		return 'no' !== get_option( 'ak_testmode', 'yes' ) ? '<p class="ak-testmode">Testmodus: nur für Admins sichtbar.</p>' : '';
	}

	/** Schritt 0: Waffe wählen (optional nur Waffen, auf die das Teil passt). */
	public static function render_picker( $part_id ) {
		self::assets();
		$weapons = self::weapons();
		$part    = $part_id ? wc_get_product( $part_id ) : null;
		if ( $part ) {
			$weapons = self::weapons_for_part( $weapons, $part_id );
		}
		ob_start();
		?>
		<section class="ak-konfigurator ak-picker">
			<?php echo self::testmode_badge(); // phpcs:ignore WordPress.Security.EscapeOutput ?>
			<h2>Waffe wählen</h2>
			<?php if ( $part ) : ?>
				<p>Waffen, auf die <strong><?php echo esc_html( $part->get_name() ); ?></strong> passt:</p>
			<?php else : ?>
				<p>Wähle die Waffe, die du konfigurieren möchtest. Optik und Zubehör wählst du im nächsten Schritt.</p>
			<?php endif; ?>
			<?php if ( ! $weapons ) : ?>
				<p>Zurzeit ist keine passende Waffe im Konfigurator verfügbar.</p>
			<?php else : ?>
				<?php
				// Suche und Filter wie in der Shop-Kategorie Waffen (im Browser, ohne Neuladen).
				$labels = array(
					'brand'  => 'Alle Marken',
					'kal'    => 'Alle Kaliber',
					'schrot' => 'Alle Schrotkaliber',
					'lauf'   => 'Alle Lauflängen',
				);
				$facets = array_fill_keys( array_merge( array( 'cat' ), array_keys( $labels ) ), array() );
				$meta   = array();
				foreach ( $weapons as $w ) {
					$m = self::weapon_facets( $w );
					$meta[ $w ] = $m;
					foreach ( array_keys( $facets ) as $f ) {
						foreach ( $m[ $f ] as $v ) {
							$facets[ $f ][ $v ] = isset( $facets[ $f ][ $v ] ) ? $facets[ $f ][ $v ] + 1 : 1;
						}
					}
				}
				uksort( $facets['cat'], 'strnatcasecmp' );
				?>
				<?php if ( count( $facets['cat'] ) > 1 ) : ?>
					<nav class="ak-type-tabs ak-pf-chips" aria-label="Kategorie">
						<button type="button" class="is-active" data-value="">Alle (<?php echo (int) count( $weapons ); ?>)</button>
						<?php foreach ( $facets['cat'] as $v => $n ) : ?>
							<button type="button" data-value="<?php echo esc_attr( $v ); ?>"><?php echo esc_html( $v . ' (' . $n . ')' ); ?></button>
						<?php endforeach; ?>
					</nav>
				<?php endif; ?>
				<div class="ak-picker-filter">
					<input type="search" class="ak-pf-q" placeholder="Waffe suchen, z.B. Glock 19 oder .308" aria-label="Waffe suchen">
					<?php foreach ( $labels as $f => $all ) : ?>
						<?php
						if ( count( $facets[ $f ] ) < 2 ) {
							continue;
						}
						uksort( $facets[ $f ], 'strnatcasecmp' );
						?>
						<select class="ak-pf-sel" data-facet="<?php echo esc_attr( $f ); ?>" aria-label="<?php echo esc_attr( $all ); ?>">
							<option value=""><?php echo esc_html( $all ); ?></option>
							<?php foreach ( $facets[ $f ] as $v => $n ) : ?>
								<option value="<?php echo esc_attr( $v ); ?>"><?php echo esc_html( $v . ' (' . $n . ')' ); ?></option>
							<?php endforeach; ?>
						</select>
					<?php endforeach; ?>
					<span class="ak-pf-count"><?php echo (int) count( $weapons ); ?> Waffen</span>
				</div>
				<p class="ak-pf-none" hidden>Keine Waffe gefunden. Suche oder Filter anpassen.</p>
				<div class="ak-weapon-grid">
					<?php
					foreach ( $weapons as $w ) :
						$p     = wc_get_product( $w );
						$m     = $meta[ $w ];
						$args  = array( 'waffe' => $w );
						if ( $part ) {
							$args['teil'] = $part_id;
						}
						?>
						<a class="ak-weapon-card" href="<?php echo esc_url( add_query_arg( $args ) ); ?>"
							data-q="<?php echo esc_attr( strtolower( $p->get_name() . ' ' . $p->get_sku() . ' ' . implode( ' ', $m['brand'] ) . ' ' . implode( ' ', $m['kal'] ) ) ); ?>"
							<?php foreach ( array( 'cat', 'brand', 'kal', 'schrot', 'lauf' ) as $f ) : ?>
								data-<?php echo esc_attr( $f ); ?>="<?php echo esc_attr( implode( '|', $m[ $f ] ) ); ?>"
							<?php endforeach; ?>>
							<?php echo wp_get_attachment_image( AK_Data::image_id( $w ), 'woocommerce_thumbnail', false, array( 'loading' => 'lazy' ) ); ?>
							<span class="ak-card-name"><?php echo esc_html( $p->get_name() ); ?></span>
							<span class="ak-card-price"><?php echo wp_kses_post( $p->get_price_html() ); ?></span>
							<?php if ( 'publish' !== $p->get_status() ) : ?>
								<span class="ak-card-hint">Entwurf (nur für Admins)</span>
							<?php endif; ?>
						</a>
					<?php endforeach; ?>
				</div>
			<?php endif; ?>
		</section>
		<?php
		return ob_get_clean();
	}

	/** Kategorie, Marke, Kaliber, Schrotkaliber und Lauflänge einer Waffe für die Filter der Waffenauswahl (wie Shop-Kategorie Waffen). */
	public static function weapon_facets( $weapon_id ) {
		$p    = wc_get_product( $weapon_id );
		$attr = function ( $name ) use ( $p ) {
			$raw = $p ? (string) $p->get_attribute( $name ) : '';
			return array_values( array_filter( array_map( 'trim', array_map( 'html_entity_decode', explode( ',', $raw ) ) ) ) );
		};
		// Marke: wie der Shop-Filter (Taxonomie Marke), sonst Attribut Hersteller.
		$brand = taxonomy_exists( 'product_brand' ) ? wp_get_post_terms( $weapon_id, 'product_brand', array( 'fields' => 'names' ) ) : array();
		$brand = ( $brand && ! is_wp_error( $brand ) ) ? array_map( 'html_entity_decode', $brand ) : $attr( 'pa_hersteller' );
		// Kategorie: Unterkategorien von «Waffen».
		$cats   = array();
		$parent = get_term_by( 'slug', 'waffen', 'product_cat' );
		$terms  = get_the_terms( $weapon_id, 'product_cat' );
		if ( $parent && $terms && ! is_wp_error( $terms ) ) {
			foreach ( $terms as $t ) {
				if ( (int) $t->parent === (int) $parent->term_id ) {
					$cats[] = html_entity_decode( $t->name );
				}
			}
		}
		return array(
			'cat'    => $cats,
			'brand'  => array_values( $brand ),
			'kal'    => $attr( 'pa_kaliber' ),
			'schrot' => $attr( 'pa_schrotkaliber' ),
			'lauf'   => $attr( 'pa_lauflaenge' ),
		);
	}

	public static function render( $weapon_id, $part_id = 0 ) {
		$options = AK_Data::options_for_weapon( $weapon_id );
		if ( empty( $options['optics'] ) && empty( $options['parts'] ) ) {
			return '<p>Für diese Waffe ist noch kein Zubehör im Konfigurator erfasst.</p>';
		}

		$optics = array();
		foreach ( $options['optics'] as $optic_id => $mount_ids ) {
			$item           = AK_Data::item_payload( $optic_id );
			$item['mounts'] = array_map( 'intval', $mount_ids );
			$item['mags']   = isset( $options['optic_mags'][ $optic_id ] ) ? $options['optic_mags'][ $optic_id ] : array();
			$optics[]       = $item;
		}
		$mounts = array_map( array( 'AK_Data', 'item_payload' ), $options['mounts'] );
		$mags   = array();
		foreach ( $options['magnifiers'] as $mag_id => $mm ) {
			$item           = AK_Data::item_payload( $mag_id );
			$item['mounts'] = array_map( 'intval', $mm );
			$mags[]         = $item;
		}
		$mag_mounts = array_map( array( 'AK_Data', 'item_payload' ), $options['mag_mounts'] );

		$weapon          = AK_Data::item_payload( $weapon_id );
		$weapon['slots'] = array();
		foreach ( array_keys( AK_Data::SLOTS ) as $slot ) {
			$data = AK_Data::weapon_slot( $weapon_id, $slot );
			if ( $data ) {
				$weapon['slots'][ $slot ] = $data['anchor'];
			}
		}
		$thread                 = AK_Data::weapon_slot( $weapon_id, 'muendung' );
		$weapon['threadIfs']    = $thread ? $thread['interfaces'] : array();
		$weapon['deviceIfs']    = AK_Data::split( AK_Data::meta( $weapon_id, 'muzzle_device_if' ) );
		$weapon['deviceAnchor'] = AK_Data::point( $weapon_id, 'muzzle_device_anchor' );
		$weapon['railFront']    = AK_Data::weapon_x( $weapon_id, 'rail_front' );
		$weapon['railRear']     = AK_Data::weapon_x( $weapon_id, 'rail_rear' );
		$weapon['magGap']       = AK_Data::MAG_GAP;
		$weapon['magTol']       = AK_Data::MAG_TOL;
		$weapon['art']          = AK_Data::weapon_type( $weapon_id );

		$groups   = array();
		$rail_ids = array();
		foreach ( AK_Data::GROUPS as $key => $def ) {
			if ( empty( $options['parts'][ $key ] ) ) {
				continue;
			}
			$items = array();
			foreach ( $options['parts'][ $key ] as $pid ) {
				$item             = AK_Data::item_payload( $pid );
				$item['slot']     = isset( $options['slots'][ $pid ] ) ? $options['slots'][ $pid ] : '';
				$item['adapters'] = isset( $options['adapters'][ $pid ] ) ? $options['adapters'][ $pid ] : array();
				$rail_ids         = array_merge( $rail_ids, $item['adapters'] );
				$items[]          = $item;
			}
			$groups[] = array(
				'key'   => $key,
				'slot'  => AK_Data::group_slots( $key )[0] ?? '',
				'label' => $def[1],
				'layer' => $def[2],
				'items' => $items,
			);
		}
		$rails = array_map( array( 'AK_Data', 'item_payload' ), array_values( array_unique( $rail_ids ) ) );

		$config = array(
			'weapon'    => $weapon,
			'optics'    => $optics,
			'mounts'    => $mounts,
			'mags'      => $mags,
			'magMounts' => $mag_mounts,
			'groups'    => $groups,
			'adapters'  => $rails,
			'preselect' => $part_id ? self::resolve_part( $weapon_id, $part_id ) : 0,
			'ajaxUrl'   => WC_AJAX::get_endpoint( 'ak_add_package' ),
			'reportUrl' => WC_AJAX::get_endpoint( 'ak_report' ),
			'cartUrl'   => wc_get_cart_url(),
			'currency'  => get_woocommerce_currency_symbol(),
			'decimals'  => wc_get_price_decimals(),
		);

		self::assets();
		$change_url = remove_query_arg( array( 'waffe', 'teil' ) );

		ob_start();
		?>
		<section class="ak-konfigurator" id="konfigurator" data-config="<?php echo esc_attr( wp_json_encode( $config ) ); ?>">
			<div class="ak-head">
				<?php echo self::testmode_badge(); // phpcs:ignore WordPress.Security.EscapeOutput ?>
				<h2><?php echo esc_html( get_the_title( $weapon_id ) ); ?> konfigurieren</h2>
				<p>Wähle Optik und Zubehör und sieh direkt, wie die Waffe damit aussieht. Die Montage ist im Paket gratis. <a href="<?php echo esc_url( $change_url ); ?>">Andere Waffe wählen</a></p>
			</div>
			<div class="ak-layout">
				<div class="ak-stage-wrap">
					<div class="ak-stage" aria-live="polite"></div>
					<p class="ak-note">Vorschau mit Herstellerbildern. Proportionen sind massstabsgetreu, Farben und Details können abweichen.</p>
					<details class="ak-report">
						<summary>Passt etwas nicht? Melden</summary>
						<textarea class="ak-report-text" rows="3" maxlength="2000" aria-label="Was stimmt nicht?" placeholder="Was stimmt nicht? Zum Beispiel: Teil passt in Wirklichkeit nicht, Bild sitzt schief."></textarea>
						<input type="email" class="ak-report-mail" placeholder="E-Mail für Rückfragen (freiwillig)" aria-label="E-Mail für Rückfragen (freiwillig)">
						<input type="text" class="ak-report-hp" name="website" tabindex="-1" autocomplete="off" aria-hidden="true">
						<button type="button" class="button ak-report-send">Meldung senden</button>
						<span class="ak-report-msg" role="status"></span>
						<small>Deine aktuelle Auswahl wird automatisch mitgeschickt.</small>
					</details>
				</div>
				<div class="ak-panel">
					<div class="ak-steps"></div>
					<div class="ak-summary">
						<ul class="ak-lines"></ul>
						<p class="ak-total"></p>
						<button type="button" class="button alt ak-add" disabled>Paket in den Warenkorb</button>
						<p class="ak-msg" role="status"></p>
						<p class="ak-legal">Die Waffe wird erst nach Prüfung der Erwerbsdokumente ausgeliefert. Im Paket montieren wir Optik und Zubehör kostenlos.</p>
					</div>
				</div>
			</div>
		</section>
		<?php
		return ob_get_clean();
	}
}
