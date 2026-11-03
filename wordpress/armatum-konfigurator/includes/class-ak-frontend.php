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
				'posts_per_page' => 200,
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
		$o = AK_Data::options_for_weapon( $weapon_id );
		if ( array_key_exists( $part_id, $o['optics'] ) || in_array( $part_id, $o['mounts'], true ) ) {
			return true;
		}
		foreach ( $o['parts'] as $ids ) {
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
		$id   = $product->get_id();
		$role = AK_Data::role( $id );
		if ( ! $role || ! AK_Data::is_ready( $id ) ) {
			return;
		}
		if ( 'waffe' === $role ) {
			$url   = self::page_url( array( 'waffe' => $id ) );
			$label = 'Diese Waffe konfigurieren';
		} else {
			$fits = array_filter(
				self::weapons(),
				function ( $w ) use ( $id ) {
					return self::part_fits_weapon( $w, $id );
				}
			);
			if ( ! $fits ) {
				return;
			}
			$args = array( 'teil' => $id );
			if ( 1 === count( $fits ) ) {
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
			$weapons = array_values(
				array_filter(
					$weapons,
					function ( $w ) use ( $part_id ) {
						return self::part_fits_weapon( $w, $part_id );
					}
				)
			);
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
				<div class="ak-weapon-grid">
					<?php
					foreach ( $weapons as $w ) :
						$p     = wc_get_product( $w );
						$args  = array( 'waffe' => $w );
						if ( $part ) {
							$args['teil'] = $part_id;
						}
						?>
						<a class="ak-weapon-card" href="<?php echo esc_url( add_query_arg( $args ) ); ?>">
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

	public static function render( $weapon_id, $part_id = 0 ) {
		$options = AK_Data::options_for_weapon( $weapon_id );
		if ( empty( $options['optics'] ) && empty( $options['parts'] ) ) {
			return '<p>Für diese Waffe ist noch kein Zubehör im Konfigurator erfasst.</p>';
		}

		$optics = array();
		foreach ( $options['optics'] as $optic_id => $mount_ids ) {
			$item           = AK_Data::item_payload( $optic_id );
			$item['mounts'] = array_map( 'intval', $mount_ids );
			$optics[]       = $item;
		}
		$mounts = array_map( array( 'AK_Data', 'item_payload' ), $options['mounts'] );

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

		$groups = array();
		foreach ( AK_Data::GROUPS as $key => $def ) {
			if ( empty( $options['parts'][ $key ] ) ) {
				continue;
			}
			$groups[] = array(
				'key'   => $key,
				'slot'  => $def[0],
				'label' => $def[1],
				'layer' => $def[2],
				'items' => array_map( array( 'AK_Data', 'item_payload' ), $options['parts'][ $key ] ),
			);
		}

		$config = array(
			'weapon'    => $weapon,
			'optics'    => $optics,
			'mounts'    => $mounts,
			'groups'    => $groups,
			'preselect' => $part_id,
			'ajaxUrl'   => WC_AJAX::get_endpoint( 'ak_add_package' ),
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
