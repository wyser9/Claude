<?php
/**
 * Frontend: Konfigurator-Bereich auf der Produktseite einer eingerichteten Waffe.
 * Zusätzlich Shortcode [armatum_konfigurator waffe="123"].
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class AK_Frontend {

	public static function init() {
		add_action( 'woocommerce_after_single_product_summary', array( __CLASS__, 'product_section' ), 5 );
		add_shortcode( 'armatum_konfigurator', array( __CLASS__, 'shortcode' ) );
	}

	public static function product_section() {
		global $product;
		if ( ! $product instanceof WC_Product ) {
			return;
		}
		echo self::render( $product->get_id() ); // phpcs:ignore WordPress.Security.EscapeOutput
	}

	public static function shortcode( $atts ) {
		$atts = shortcode_atts( array( 'waffe' => 0 ), $atts );
		return self::render( (int) $atts['waffe'] );
	}

	public static function render( $weapon_id ) {
		if ( ! AK_Data::visible_for_current_user() ) {
			return '';
		}
		if ( ! $weapon_id || 'waffe' !== AK_Data::role( $weapon_id ) || ! AK_Data::is_ready( $weapon_id ) ) {
			return '';
		}
		$options = AK_Data::options_for_weapon( $weapon_id );
		if ( empty( $options['optics'] ) && empty( $options['parts'] ) ) {
			return '';
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
			'weapon'   => $weapon,
			'optics'   => $optics,
			'mounts'   => $mounts,
			'groups'   => $groups,
			'ajaxUrl'  => WC_AJAX::get_endpoint( 'ak_add_package' ),
			'cartUrl'  => wc_get_cart_url(),
			'currency' => get_woocommerce_currency_symbol(),
			'decimals' => wc_get_price_decimals(),
		);

		wp_enqueue_style( 'ak-front', AK_URL . 'assets/konfigurator.css', array(), AK_VERSION );
		wp_enqueue_script( 'ak-front', AK_URL . 'assets/konfigurator.js', array( 'jquery' ), AK_VERSION, true );

		ob_start();
		?>
		<section class="ak-konfigurator" id="konfigurator" data-config="<?php echo esc_attr( wp_json_encode( $config ) ); ?>">
			<div class="ak-head">
				<?php if ( 'no' !== get_option( 'ak_testmode', 'yes' ) ) : ?>
					<p class="ak-testmode">Testmodus: nur für Admins sichtbar.</p>
				<?php endif; ?>
				<h2>Waffe konfigurieren</h2>
				<p>Wähle Optik und Zubehör und sieh direkt, wie die <?php echo esc_html( get_the_title( $weapon_id ) ); ?> damit aussieht. Die Montage ist im Paket gratis.</p>
			</div>
			<div class="ak-layout">
				<div class="ak-stage-wrap">
					<div class="ak-stage" aria-live="polite"></div>
					<p class="ak-note">Vorschau mit Herstellerbildern. Proportionen sind massstabsgetreu, Farben und Details können abweichen.</p>
				</div>
				<div class="ak-panel">
					<div class="ak-step-optic" <?php echo empty( $optics ) ? 'hidden' : ''; ?>>
						<h3>Optik</h3>
						<div class="ak-list ak-optics" role="radiogroup" aria-label="Optik"></div>
						<div class="ak-step-mount" hidden>
							<h3>Montage</h3>
							<div class="ak-list ak-mounts" role="radiogroup" aria-label="Montage"></div>
						</div>
					</div>
					<div class="ak-groups"></div>
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
