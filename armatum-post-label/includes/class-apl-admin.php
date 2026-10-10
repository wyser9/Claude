<?php
defined( 'ABSPATH' ) || exit;

/**
 * Einstellungsseite und Box "Schweizerische Post" in der Bestellung.
 */
class APL_Admin {

	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'menu' ) );
		add_action( 'admin_post_apl_save_settings', array( __CLASS__, 'save_settings' ) );
		add_action( 'add_meta_boxes', array( __CLASS__, 'meta_box' ) );
		add_filter( 'plugin_action_links_' . plugin_basename( APL_FILE ), array( __CLASS__, 'plugin_links' ) );
	}

	public static function menu() {
		add_options_page( 'Armatum Post Label', 'Armatum Post Label', 'manage_woocommerce', 'armatum-post-label', array( __CLASS__, 'settings_page' ) );
	}

	public static function plugin_links( $links ) {
		array_unshift( $links, '<a href="' . esc_url( admin_url( 'options-general.php?page=armatum-post-label' ) ) . '">Einstellungen</a>' );
		return $links;
	}

	/* ------------------------------------------------------------------ */
	/* Einstellungen                                                       */
	/* ------------------------------------------------------------------ */

	public static function settings_page() {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			return;
		}
		$sender  = APL_Label::sender();
		$test    = 'yes' === get_option( 'armatum_post_test_mode', 'yes' );
		$secret  = (string) get_option( 'armatum_post_client_secret' );
		$cleanup = get_option( 'apl_legacy_cleanup' );
		?>
		<div class="wrap">
			<h1>Armatum Post Label</h1>
			<?php if ( isset( $_GET['saved'] ) ) : ?>
				<div class="notice notice-success is-dismissible"><p>Einstellungen gespeichert.</p></div>
			<?php endif; ?>
			<?php if ( $test ) : ?>
				<div class="notice notice-warning inline"><p><strong>Testmodus aktiv:</strong> Labels werden mit dem Aufdruck „SPECIMEN“ erstellt und sind nicht versandfähig. Nach erfolgreichem Test unten ausschalten.</p></div>
			<?php endif; ?>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="apl_save_settings">
				<?php wp_nonce_field( 'apl_save_settings' ); ?>
				<h2>Zugang Digital Commerce API</h2>
				<table class="form-table">
					<tr><th><label for="apl_client_id">Client ID</label></th><td><input type="text" id="apl_client_id" name="client_id" value="<?php echo esc_attr( get_option( 'armatum_post_client_id' ) ); ?>" class="regular-text"></td></tr>
					<tr><th><label for="apl_client_secret">Client Secret</label></th><td><input type="password" id="apl_client_secret" name="client_secret" value="" autocomplete="off" class="regular-text" placeholder="<?php echo $secret ? '•••••••• (gespeichert – leer lassen, um beizubehalten)' : ''; ?>"></td></tr>
					<tr><th><label for="apl_license">Frankierlizenz</label></th><td><input type="text" id="apl_license" name="franking_license" value="<?php echo esc_attr( get_option( 'armatum_post_franking_license' ) ); ?>" class="regular-text"></td></tr>
					<tr><th>Testmodus</th><td><label><input type="checkbox" name="test_mode" value="yes" <?php checked( $test ); ?>> Labels als Muster („SPECIMEN“) erstellen – nicht versandfähig, wird nicht verrechnet</label></td></tr>
				</table>
				<h2>Absender</h2>
				<table class="form-table">
					<?php foreach ( array( 'name1' => 'Firma', 'street' => 'Strasse und Nr.', 'zip' => 'PLZ', 'city' => 'Ort' ) as $key => $label ) : ?>
						<tr><th><label for="apl_sender_<?php echo esc_attr( $key ); ?>"><?php echo esc_html( $label ); ?></label></th><td><input type="text" id="apl_sender_<?php echo esc_attr( $key ); ?>" name="sender[<?php echo esc_attr( $key ); ?>]" value="<?php echo esc_attr( $sender[ $key ] ?? '' ); ?>" class="regular-text"></td></tr>
					<?php endforeach; ?>
				</table>
				<?php submit_button( 'Einstellungen speichern' ); ?>
			</form>
			<h2>Versandarten</h2>
			<p>Produkt ist immer <strong>PostPac Economy</strong>. Die Gewichtsstufe (bis 2 / 10 / 30 kg) ergibt sich aus dem Gewicht. In der Bestellung werden „Signature“ und „Sperrgut“ angekreuzt; vorgeschlagen wird Signature, wenn die Versandart „Signature“ oder „Waffen“ enthält, Sperrgut bei „Sperrgut“.</p>
			<?php if ( is_array( $cleanup ) ) : ?>
				<p class="description">Aufräumen Version 0.1: <?php echo (int) $cleanup['deleted']; ?> alte Muster-Labels gelöscht am <?php echo esc_html( wp_date( 'd.m.Y H:i', $cleanup['time'] ) ); ?>.</p>
			<?php endif; ?>
		</div>
		<?php
	}

	public static function save_settings() {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( 'Keine Berechtigung.' );
		}
		check_admin_referer( 'apl_save_settings' );
		update_option( 'armatum_post_client_id', sanitize_text_field( wp_unslash( $_POST['client_id'] ?? '' ) ) );
		$secret = trim( sanitize_text_field( wp_unslash( $_POST['client_secret'] ?? '' ) ) );
		if ( '' !== $secret ) {
			update_option( 'armatum_post_client_secret', $secret );
		}
		update_option( 'armatum_post_franking_license', sanitize_text_field( wp_unslash( $_POST['franking_license'] ?? '' ) ) );
		update_option( 'armatum_post_test_mode', empty( $_POST['test_mode'] ) ? 'no' : 'yes' );
		$sender = array( 'country' => 'CH' );
		foreach ( array( 'name1', 'street', 'zip', 'city' ) as $key ) {
			$sender[ $key ] = sanitize_text_field( wp_unslash( $_POST['sender'][ $key ] ?? '' ) );
		}
		update_option( 'armatum_post_sender', $sender );
		delete_transient( APL_Api::TOKEN_CACHE );
		wp_safe_redirect( admin_url( 'options-general.php?page=armatum-post-label&saved=1' ) );
		exit;
	}

	/* ------------------------------------------------------------------ */
	/* Box in der Bestellung                                               */
	/* ------------------------------------------------------------------ */

	public static function meta_box() {
		$screen = function_exists( 'wc_get_page_screen_id' ) ? wc_get_page_screen_id( 'shop-order' ) : 'shop_order';
		add_meta_box( 'apl-post-label', 'Schweizerische Post', array( __CLASS__, 'render_box' ), $screen, 'side', 'high' );
	}

	public static function render_box( $post_or_order ) {
		$order = $post_or_order instanceof WC_Order ? $post_or_order : wc_get_order( $post_or_order->ID );
		if ( ! $order ) {
			return;
		}
		if ( APL_Label::is_pickup( $order ) ) {
			echo '<p>Abholung vor Ort – kein Post-Label nötig.</p>';
			return;
		}

		$ident = (string) $order->get_meta( APL_Label::META_IDENT );
		if ( $ident ) {
			$preview  = 'yes' === $order->get_meta( APL_Label::META_PREVIEW );
			$download = wp_nonce_url( admin_url( 'admin-post.php?action=apl_download_label&order_id=' . $order->get_id() ), 'apl_download_' . $order->get_id() );
			echo '<p>';
			if ( $preview ) {
				echo '<strong style="color:#b32d2e">TESTLABEL (SPECIMEN)</strong><br>';
			}
			printf( 'Sendungsnummer: <a href="%s" target="_blank" rel="noopener">%s</a><br>', esc_url( APL_Util::tracking_url( $ident ) ), esc_html( $ident ) );
			echo '<small>' . esc_html( (string) $order->get_meta( APL_Label::META_INFO ) ) . '</small></p>';
			printf( '<p><a class="button button-primary" href="%s" target="_blank">Label öffnen / drucken</a></p><hr>', esc_url( $download ) );
		}

		$names     = APL_Label::shipping_names( $order );
		$grams     = APL_Label::order_weight_g( $order );
		$signature = APL_Util::suggest_signature( $names );
		$bulky     = APL_Util::suggest_bulky( $names );

		foreach ( $names as $name ) {
			if ( preg_match( '/brief/i', $name ) ) {
				echo '<p style="color:#b32d2e">Versandart „' . esc_html( $name ) . '“: Briefe werden nicht unterstützt, nur PostPac.</p>';
			}
		}
		?>
		<div id="apl-fields">
			<p><label>Gewicht (kg)<br>
				<input type="text" data-apl="apl_weight" value="<?php echo esc_attr( $grams ? number_format( $grams / 1000, 3, '.', '' ) : '' ); ?>" style="width:100%" placeholder="z. B. 1.250"></label>
				<?php if ( ! $grams ) : ?>
					<br><small style="color:#b32d2e">Bei den Produkten fehlt das Gewicht – bitte eintragen.</small>
				<?php endif; ?>
			</p>
			<p>
				<label><input type="checkbox" data-apl="apl_signature" value="1" <?php checked( $signature ); ?>> Signature</label><br>
				<label><input type="checkbox" data-apl="apl_bulky" value="1" <?php checked( $bulky ); ?>> Sperrgut</label>
			</p>
		</div>
		<p><button type="button" id="apl-create" class="button<?php echo $ident ? '' : ' button-primary'; ?>"><?php echo $ident ? 'Neues Label erstellen' : 'Post-Label erstellen'; ?></button></p>
		<script>
		( function () {
			// Eigenes Formular, da die Box im Bestellformular liegt (verschachtelte Formulare sind nicht erlaubt).
			document.getElementById( 'apl-create' ).addEventListener( 'click', function () {
				var form = document.createElement( 'form' );
				form.method = 'post';
				form.action = <?php echo wp_json_encode( admin_url( 'admin-post.php' ) ); ?>;
				var add = function ( name, value ) {
					var input = document.createElement( 'input' );
					input.type = 'hidden'; input.name = name; input.value = value;
					form.appendChild( input );
				};
				add( 'action', 'apl_create_label' );
				add( 'apl_order_id', <?php echo (int) $order->get_id(); ?> );
				add( 'apl_nonce', <?php echo wp_json_encode( wp_create_nonce( 'apl_create_' . $order->get_id() ) ); ?> );
				document.querySelectorAll( '#apl-fields [data-apl]' ).forEach( function ( el ) {
					if ( el.type !== 'checkbox' || el.checked ) {
						add( el.getAttribute( 'data-apl' ), el.value );
					}
				} );
				this.disabled = true;
				document.body.appendChild( form );
				form.submit();
			} );
		} )();
		</script>
		<p class="description">PostPac Economy<?php echo 'yes' === get_option( 'armatum_post_test_mode', 'yes' ) ? ' · <strong>Testmodus</strong>' : ''; ?></p>
		<?php
	}
}
