<?php
/**
 * Einstellungen unter WooCommerce → Konfigurator: Konfigurator-Seite und Testmodus.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class AK_Settings {

	const SLUG = 'ak-settings';

	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'menu' ), 60 );
		add_action( 'admin_post_ak_save_settings', array( __CLASS__, 'save' ) );
		add_action( 'admin_post_ak_create_page', array( __CLASS__, 'create_page' ) );
		add_filter( 'display_post_states', array( __CLASS__, 'post_state' ), 10, 2 );
	}

	public static function menu() {
		add_submenu_page( 'woocommerce', 'Konfigurator', 'Konfigurator', 'manage_woocommerce', self::SLUG, array( __CLASS__, 'render' ) );
	}

	public static function post_state( $states, $post ) {
		if ( (int) get_option( 'ak_page_id' ) === $post->ID ) {
			$states['ak_page'] = 'Konfigurator-Seite';
		}
		return $states;
	}

	private static function back( $msg ) {
		wp_safe_redirect( add_query_arg( array( 'page' => self::SLUG, 'ak_msg' => $msg ), admin_url( 'admin.php' ) ) );
		exit;
	}

	public static function save() {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( 'Keine Berechtigung.' );
		}
		check_admin_referer( 'ak_settings' );
		update_option( 'ak_page_id', isset( $_POST['ak_page_id'] ) ? absint( $_POST['ak_page_id'] ) : 0 );
		update_option( 'ak_testmode', empty( $_POST['ak_testmode'] ) ? 'no' : 'yes' );
		self::back( 'saved' );
	}

	/** Legt eine private Seite mit dem Shortcode an (sichtbar nur für Admins, bis sie veröffentlicht wird). */
	public static function create_page() {
		if ( ! current_user_can( 'manage_woocommerce' ) || ! current_user_can( 'publish_pages' ) ) {
			wp_die( 'Keine Berechtigung.' );
		}
		check_admin_referer( 'ak_create_page' );
		$id = wp_insert_post(
			array(
				'post_type'    => 'page',
				'post_status'  => 'private',
				'post_title'   => 'Konfigurator',
				'post_name'    => 'konfigurator',
				'post_content' => '<!-- wp:shortcode -->[armatum_konfigurator]<!-- /wp:shortcode -->',
			),
			true
		);
		if ( is_wp_error( $id ) ) {
			self::back( 'error' );
		}
		update_option( 'ak_page_id', (int) $id );
		self::back( 'created' );
	}

	public static function render() {
		$page_id  = (int) get_option( 'ak_page_id' );
		$testmode = 'no' !== get_option( 'ak_testmode', 'yes' );
		$msg      = isset( $_GET['ak_msg'] ) ? sanitize_key( $_GET['ak_msg'] ) : ''; // phpcs:ignore WordPress.Security.NonceVerification
		$messages = array(
			'saved'   => 'Einstellungen gespeichert.',
			'created' => 'Seite «Konfigurator» als private Seite angelegt.',
			'error'   => 'Die Seite konnte nicht angelegt werden.',
		);
		?>
		<div class="wrap">
			<h1>Konfigurator</h1>
			<?php if ( isset( $messages[ $msg ] ) ) : ?>
				<div class="notice notice-<?php echo 'error' === $msg ? 'error' : 'success'; ?> is-dismissible"><p><?php echo esc_html( $messages[ $msg ] ); ?></p></div>
			<?php endif; ?>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="ak_save_settings">
				<?php wp_nonce_field( 'ak_settings' ); ?>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><label for="ak_page_id">Konfigurator-Seite</label></th>
						<td>
							<?php
							wp_dropdown_pages(
								array(
									'name'              => 'ak_page_id',
									'id'                => 'ak_page_id',
									'selected'          => $page_id,
									'show_option_none'  => '– keine –',
									'option_none_value' => 0,
									'post_status'       => array( 'publish', 'private', 'draft' ),
								)
							);
							?>
							<p class="description">Seite mit dem Shortcode <code>[armatum_konfigurator]</code>. Die Knöpfe auf den Produktseiten führen dorthin.</p>
							<?php if ( $page_id && get_post( $page_id ) ) : ?>
								<p><a href="<?php echo esc_url( get_permalink( $page_id ) ); ?>" target="_blank" rel="noopener">Seite ansehen</a> · <a href="<?php echo esc_url( get_edit_post_link( $page_id ) ); ?>">bearbeiten</a> · Status: <?php echo esc_html( get_post_status_object( get_post_status( $page_id ) )->label ); ?></p>
							<?php endif; ?>
						</td>
					</tr>
					<tr>
						<th scope="row">Testmodus</th>
						<td>
							<label><input type="checkbox" name="ak_testmode" value="1" <?php checked( $testmode ); ?>> Nur Admins und Shop-Manager sehen den Konfigurator</label>
							<p class="description">Zum Freischalten für alle: Haken entfernen und die Seite veröffentlichen.</p>
						</td>
					</tr>
				</table>
				<?php submit_button( 'Speichern' ); ?>
			</form>
			<?php if ( ! $page_id || ! get_post( $page_id ) ) : ?>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
					<input type="hidden" name="action" value="ak_create_page">
					<?php wp_nonce_field( 'ak_create_page' ); ?>
					<p>Noch keine Seite? <?php submit_button( 'Seite anlegen', 'secondary', 'submit', false ); ?> (privat, nur für Admins sichtbar)</p>
				</form>
			<?php endif; ?>
		</div>
		<?php
	}
}
