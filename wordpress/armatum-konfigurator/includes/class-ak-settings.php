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
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'assets' ) );
	}

	public static function assets( $hook ) {
		if ( false === strpos( (string) $hook, self::SLUG ) ) {
			return;
		}
		wp_enqueue_style( 'ak-admin', AK_URL . 'assets/admin.css', array(), AK_VERSION );
		wp_enqueue_script( 'ak-settings', AK_URL . 'assets/settings.js', array( 'jquery' ), AK_VERSION, true );
		wp_localize_script( 'ak-settings', 'akSettings', array( 'nonce' => wp_create_nonce( 'ak_auto' ) ) );
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
		$map = array();
		$raw = isset( $_POST['ak_cat'] ) && is_array( $_POST['ak_cat'] ) ? wp_unslash( $_POST['ak_cat'] ) : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
		foreach ( array_keys( AK_Auto::TARGETS ) as $t ) {
			if ( ! empty( $raw[ $t ] ) && is_array( $raw[ $t ] ) ) {
				$map[ $t ] = array_values( array_filter( array_map( 'absint', $raw[ $t ] ) ) );
			}
		}
		update_option( 'ak_cat_map', $map, false );
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
				<h2>Kategorien zuordnen</h2>
				<p>Produkte ohne Rolle bekommen beim automatischen Einrichten Rolle und Gruppe aus ihrer Kategorie. Mehrfachauswahl mit Ctrl/Cmd. Produkte mit eingetragener Rolle bleiben unverändert.</p>
				<table class="form-table ak-catmap" role="presentation">
					<?php
					$map   = AK_Auto::cat_map();
					$terms = get_terms(
						array(
							'taxonomy'   => 'product_cat',
							'hide_empty' => true,
							'orderby'    => 'name',
						)
					);
					$terms = is_wp_error( $terms ) ? array() : $terms;
					foreach ( AK_Auto::TARGETS as $tkey => $def ) :
						$sel = isset( $map[ $tkey ] ) ? array_map( 'intval', $map[ $tkey ] ) : array();
						?>
						<tr>
							<th scope="row"><?php echo esc_html( $def[3] ); ?></th>
							<td>
								<select name="ak_cat[<?php echo esc_attr( $tkey ); ?>][]" multiple size="5">
									<?php foreach ( $terms as $term ) : ?>
										<option value="<?php echo (int) $term->term_id; ?>" <?php selected( in_array( (int) $term->term_id, $sel, true ) ); ?>><?php echo esc_html( $term->name . ' (' . $term->count . ')' ); ?></option>
									<?php endforeach; ?>
								</select>
							</td>
						</tr>
					<?php endforeach; ?>
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
			<hr>
			<h2>Automatisch einrichten</h2>
			<p>Geht alle Produkte mit Rolle oder in einer zugeordneten Kategorie durch: setzt fehlende Rollen aus der Kategorie und misst das Bild ein (Umriss erkennen, Länge aus Attribut «Länge [mm]» oder Feld «Länge»). Bereits eingemessene Produkte werden übersprungen.</p>
			<p>
				<button type="button" class="button ak-run" data-dry="1">Probelauf (nichts speichern)</button>
				<button type="button" class="button button-primary ak-run" data-dry="0">Einrichten</button>
				<label><input type="checkbox" class="ak-overwrite"> Bestehende Punkte überschreiben</label>
				<span class="ak-run-status"></span>
			</p>
			<table class="widefat striped ak-run-table" hidden>
				<thead><tr><th>Produkt</th><th>Rolle / Gruppe</th><th>Länge</th><th>Ergebnis</th></tr></thead>
				<tbody></tbody>
			</table>
		</div>
		<?php
	}
}
