<?php
defined( 'ABSPATH' ) || exit;

/**
 * Admin-Oberfläche: Einstellungsseite, Bestell-Metabox und Bestell-Aktionen.
 */
class BWS_Admin {

	const PAGE = 'bws-settings';

	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'menu' ) );
		add_action( 'admin_post_bws_save', array( __CLASS__, 'handle_save' ) );
		add_action( 'admin_post_bws_tool', array( __CLASS__, 'handle_tool' ) );

		add_filter( 'woocommerce_order_actions', array( __CLASS__, 'order_actions' ), 10, 2 );
		add_action( 'woocommerce_order_action_bws_send_to_bexio', array( __CLASS__, 'order_action_send' ) );
		add_action( 'add_meta_boxes', array( __CLASS__, 'order_meta_box' ) );

		// Sammelaktion in der Bestellübersicht (klassisch und HPOS).
		foreach ( array( 'bulk_actions-edit-shop_order', 'bulk_actions-woocommerce_page_wc-orders' ) as $hook ) {
			add_filter( $hook, array( __CLASS__, 'bulk_actions' ) );
		}
		foreach ( array( 'handle_bulk_actions-edit-shop_order', 'handle_bulk_actions-woocommerce_page_wc-orders' ) as $hook ) {
			add_filter( $hook, array( __CLASS__, 'handle_bulk_action' ), 10, 3 );
		}

		add_filter( 'plugin_action_links_' . plugin_basename( BWS_FILE ), array( __CLASS__, 'plugin_links' ) );
	}

	public static function menu() {
		add_submenu_page( 'woocommerce', 'bexio Sync', 'bexio Sync', 'manage_woocommerce', self::PAGE, array( __CLASS__, 'render_page' ) );
	}

	public static function plugin_links( $links ) {
		array_unshift( $links, '<a href="' . esc_url( admin_url( 'admin.php?page=' . self::PAGE ) ) . '">Einstellungen</a>' );
		return $links;
	}

	/* ------------------------------------------------------------------ */
	/* Einstellungsseite                                                   */
	/* ------------------------------------------------------------------ */

	public static function render_page() {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			return;
		}
		$s         = BWS_Settings::all();
		$connected = false;
		$error     = '';
		$me        = null;

		if ( BWS_Settings::get( 'api_token' ) ) {
			try {
				$me        = BWS_Client::instance()->get( '3.0/users/me' );
				$connected = true;
			} catch ( BWS_Exception $e ) {
				$error = $e->getMessage();
			}
		}

		$lists = array();
		if ( $connected ) {
			$map = array(
				'units'          => array( 'BWS_Lookup', 'units' ),
				'taxes'          => array( 'BWS_Lookup', 'taxes' ),
				'stocks'         => array( 'BWS_Lookup', 'stocks' ),
				'stock_places'   => array( 'BWS_Lookup', 'stock_places' ),
				'accounts'       => array( 'BWS_Lookup', 'accounts' ),
				'contact_groups' => array( 'BWS_Lookup', 'contact_groups' ),
				'languages'      => array( 'BWS_Lookup', 'languages' ),
			);
			foreach ( $map as $key => $callback ) {
				try {
					$lists[ $key ] = call_user_func( $callback );
				} catch ( BWS_Exception $e ) {
					$lists[ $key ] = array();
					bws_log( 'warning', 'Stammdaten ' . $key . ' konnten nicht geladen werden: ' . $e->getMessage() );
				}
			}
		}

		$msg = isset( $_GET['bws_msg'] ) ? sanitize_text_field( wp_unslash( $_GET['bws_msg'] ) ) : '';
		?>
		<div class="wrap">
			<h1>bexio WooCommerce Sync</h1>

			<?php if ( $msg ) : ?>
				<div class="notice notice-info is-dismissible"><p><?php echo esc_html( $msg ); ?></p></div>
			<?php endif; ?>

			<?php if ( $connected ) : ?>
				<div class="notice notice-success inline"><p>Verbunden mit bexio als <strong><?php echo esc_html( trim( ( $me['firstname'] ?? '' ) . ' ' . ( $me['lastname'] ?? '' ) ) . ' (' . ( $me['email'] ?? '' ) . ')' ); ?></strong>.</p></div>
			<?php elseif ( $error ) : ?>
				<div class="notice notice-error inline"><p><?php echo esc_html( $error ); ?></p></div>
			<?php else : ?>
				<div class="notice notice-warning inline"><p>Bitte API-Token hinterlegen. Erstellen unter bexio &rarr; Einstellungen &rarr; Sicherheit &rarr; API-Tokens (Personal Access Token).</p></div>
			<?php endif; ?>

			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="bws_save">
				<?php wp_nonce_field( 'bws_save' ); ?>

				<h2>Verbindung</h2>
				<table class="form-table">
					<tr>
						<th><label for="bws_api_token">API-Token</label></th>
						<td>
							<?php if ( defined( 'BEXIO_API_TOKEN' ) && BEXIO_API_TOKEN ) : ?>
								<em>Wird über die Konstante <code>BEXIO_API_TOKEN</code> in wp-config.php gesetzt.</em>
							<?php else : ?>
								<input type="password" id="bws_api_token" name="api_token" class="large-text" autocomplete="off" placeholder="<?php echo $s['api_token'] ? '•••••••• (gespeichert – leer lassen, um beizubehalten)' : ''; ?>">
							<?php endif; ?>
						</td>
					</tr>
				</table>

				<h2>Synchronisation</h2>
				<table class="form-table">
					<?php
					self::checkbox( 'sync_products', 'Artikel übertragen', 'Produkte und Varianten werden bei jeder Änderung als bexio-Artikel angelegt/aktualisiert (Zuordnung über SKU = Artikel-Nr.).', $s );
					self::checkbox( 'sync_orders', 'Bestellungen übertragen', 'Bestellungen werden inkl. Besteller als Auftrag in bexio angelegt.', $s );
					self::checkbox( 'sku_fallback', 'Ohne SKU übertragen', 'Produkte ohne SKU erhalten in bexio die Artikel-Nr. "WC-&lt;ID&gt;".', $s );
					self::checkbox( 'update_contacts', 'Bestehende Kontakte aktualisieren', 'Adressdaten bereits vorhandener bexio-Kontakte mit den Angaben der Bestellung überschreiben.', $s );
					?>
					<tr>
						<th><label for="bws_stock_mode">Lagerbestand</label></th>
						<td>
							<select id="bws_stock_mode" name="stock_mode">
								<option value="from_bexio" <?php selected( BWS_Settings::stock_mode(), 'from_bexio' ); ?>>bexio führt das Lager – täglich Lagerstatus aus bexio übernehmen</option>
								<option value="to_bexio" <?php selected( BWS_Settings::stock_mode(), 'to_bexio' ); ?>>WooCommerce führt das Lager – Bestand an bexio senden</option>
								<option value="off" <?php selected( BWS_Settings::stock_mode(), 'off' ); ?>>Kein Lagerabgleich</option>
							</select>
							<p class="description">bexio führt: Für jeden bexio-Lagerartikel mit passender SKU im Shop wird bei Bestand &gt; 0 „Vorrätig“, bei Bestand &lt;= 0 „Lieferrückstand“ gesetzt. Artikel, die es im Shop nicht gibt, werden ignoriert.</p>
						</td>
					</tr>
					<tr>
						<th><label for="bws_stock_import_time">Lagerimport täglich um</label></th>
						<td>
							<input type="time" id="bws_stock_import_time" name="stock_import_time" value="<?php echo esc_attr( BWS_Stock_Import::normalize_time( $s['stock_import_time'] ) ); ?>">
							<select name="stock_field">
								<option value="stock_nr" <?php selected( $s['stock_field'], 'stock_nr' ); ?>>Lagerbestand (physisch)</option>
								<option value="stock_available_nr" <?php selected( $s['stock_field'], 'stock_available_nr' ); ?>>Verfügbarer Bestand (abzüglich reserviert)</option>
							</select>
							<?php
							$last = get_option( BWS_Stock_Import::OPTION_LAST );
							$next = function_exists( 'as_next_scheduled_action' ) ? as_next_scheduled_action( BWS_Stock_Import::HOOK, array(), BWS_AS_GROUP ) : false;
							?>
							<p class="description">
								<?php if ( is_int( $next ) ) : ?>
									Nächster Lauf: <?php echo esc_html( wp_date( 'd.m.Y H:i', $next ) ); ?>.
								<?php endif; ?>
								<?php if ( is_array( $last ) && ! empty( $last['error'] ) ) : ?>
									Letzter Lauf <?php echo esc_html( wp_date( 'd.m.Y H:i', $last['time'] ) ); ?> fehlgeschlagen: <?php echo esc_html( $last['error'] ); ?>
								<?php elseif ( is_array( $last ) ) : ?>
									Letzter Lauf <?php echo esc_html( wp_date( 'd.m.Y H:i', $last['time'] ) ); ?>: <?php echo (int) $last['instock']; ?> auf Vorrätig, <?php echo (int) $last['backorder']; ?> auf Lieferrückstand, <?php echo (int) $last['unchanged']; ?> unverändert, <?php echo (int) $last['not_found']; ?> nicht im Shop, <?php echo (int) $last['errors']; ?> Fehler.
								<?php endif; ?>
							</p>
						</td>
					</tr>
					<tr>
						<th>Bestellungen übertragen bei Status</th>
						<td>
							<?php foreach ( wc_get_order_statuses() as $key => $label ) : $key = substr( $key, 3 ); ?>
								<label style="display:inline-block;margin-right:16px"><input type="checkbox" name="order_statuses[]" value="<?php echo esc_attr( $key ); ?>" <?php checked( in_array( $key, (array) $s['order_statuses'], true ) ); ?>> <?php echo esc_html( $label ); ?></label>
							<?php endforeach; ?>
						</td>
					</tr>
					<tr>
						<th><label for="bws_price_mode">Preise in Aufträgen</label></th>
						<td>
							<select id="bws_price_mode" name="price_mode">
								<option value="gross" <?php selected( $s['price_mode'], 'gross' ); ?>>Brutto (inkl. MWST)</option>
								<option value="net" <?php selected( $s['price_mode'], 'net' ); ?>>Netto (exkl. MWST)</option>
							</select>
							<p class="description">Empfehlung für Schweizer B2C-Shops: Brutto. Die Beträge werden aus der Bestellung exakt übernommen.</p>
						</td>
					</tr>
					<tr>
						<th><label for="bws_order_title_prefix">Auftragstitel</label></th>
						<td><input type="text" id="bws_order_title_prefix" name="order_title_prefix" value="<?php echo esc_attr( $s['order_title_prefix'] ); ?>" class="regular-text"> <code>#&lt;Bestellnummer&gt;</code></td>
					</tr>
				</table>

				<h2>bexio-Zuordnungen</h2>
				<?php if ( ! $connected ) : ?>
					<p>Nach dem Speichern eines gültigen Tokens können hier Einheit, Lager, Konten usw. gewählt werden.</p>
				<?php else : ?>
					<table class="form-table">
						<?php
						self::select( 'unit_id', 'Einheit', $lists['units'], 'name', $s, 'Einheit für Artikel und Positionen (z.B. "Stk.").' );
						self::select( 'stock_id', 'Lager', $lists['stocks'], 'name', $s, 'Lagerort für Lagerartikel.' );
						self::select( 'stock_place_id', 'Lagerplatz', $lists['stock_places'], 'name', $s );
						self::select( 'account_id', 'Ertragskonto', $lists['accounts'], array( 'account_no', 'name' ), $s, 'Leer = Standard-Ertragskonto aus bexio.' );
						self::select( 'default_tax_id', 'Fallback-MWST', $lists['taxes'], array( 'name', 'value' ), $s, 'Wird verwendet, wenn zu einem Woo-Steuersatz keine bexio-Steuer mit gleichem Satz gefunden wird. Normalerweise leer lassen.' );
						self::select( 'contact_group_id', 'Kontaktgruppe für neue Kunden', $lists['contact_groups'], 'name', $s );
						self::select( 'language_id', 'Sprache', $lists['languages'], 'name', $s );
						?>
						<tr>
							<th><label for="bws_user_id">bexio Benutzer-ID</label></th>
							<td><input type="number" id="bws_user_id" name="user_id" value="<?php echo esc_attr( $s['user_id'] ); ?>" class="small-text"> <span class="description">Leer = Inhaber des API-Tokens (ID <?php echo (int) ( $me['id'] ?? 0 ); ?>).</span></td>
						</tr>
					</table>
				<?php endif; ?>

				<?php submit_button( 'Einstellungen speichern' ); ?>
			</form>

			<?php if ( $connected ) : ?>
				<h2>Werkzeuge</h2>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:inline">
					<input type="hidden" name="action" value="bws_tool">
					<?php wp_nonce_field( 'bws_tool' ); ?>
					<button class="button button-primary" name="tool" value="sync_all_products">Alle Artikel jetzt an bexio übertragen</button>
					<?php if ( 'from_bexio' === BWS_Settings::stock_mode() ) : ?>
						<button class="button" name="tool" value="import_stock">Lagerstatus jetzt aus bexio holen</button>
					<?php endif; ?>
					<button class="button" name="tool" value="flush_cache">bexio-Stammdaten neu laden</button>
				</form>
				<p class="description">Die Übertragung läuft im Hintergrund (WooCommerce &rarr; Status &rarr; Geplante Aktionen, Gruppe "bexio-sync"). Protokoll: WooCommerce &rarr; Status &rarr; Logs, Quelle "bexio-sync".</p>
			<?php endif; ?>
		</div>
		<?php
	}

	private static function checkbox( $key, $label, $description, array $s ) {
		?>
		<tr>
			<th><?php echo esc_html( $label ); ?></th>
			<td>
				<label><input type="checkbox" name="<?php echo esc_attr( $key ); ?>" value="yes" <?php checked( 'yes', $s[ $key ] ); ?>> <?php echo wp_kses_post( $description ); ?></label>
			</td>
		</tr>
		<?php
	}

	/**
	 * @param string       $key         Einstellung.
	 * @param string       $label       Beschriftung.
	 * @param array        $items       bexio-Liste.
	 * @param string|array $label_field Feld(er) für die Anzeige.
	 * @param array        $s           Einstellungen.
	 * @param string       $description Hilfetext.
	 */
	private static function select( $key, $label, array $items, $label_field, array $s, $description = '' ) {
		?>
		<tr>
			<th><label for="bws_<?php echo esc_attr( $key ); ?>"><?php echo esc_html( $label ); ?></label></th>
			<td>
				<select id="bws_<?php echo esc_attr( $key ); ?>" name="<?php echo esc_attr( $key ); ?>">
					<option value="">— keine Auswahl —</option>
					<?php foreach ( $items as $item ) : ?>
						<?php
						if ( ! isset( $item['id'] ) ) {
							continue;
						}
						$text = array();
						foreach ( (array) $label_field as $field ) {
							if ( isset( $item[ $field ] ) && '' !== (string) $item[ $field ] ) {
								$text[] = 'value' === $field ? $item[ $field ] . ' %' : $item[ $field ];
							}
						}
						?>
						<option value="<?php echo (int) $item['id']; ?>" <?php selected( (string) $s[ $key ], (string) $item['id'] ); ?>><?php echo esc_html( implode( ' – ', $text ) ); ?></option>
					<?php endforeach; ?>
				</select>
				<?php if ( $description ) : ?>
					<p class="description"><?php echo esc_html( $description ); ?></p>
				<?php endif; ?>
			</td>
		</tr>
		<?php
	}

	public static function handle_save() {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( 'Keine Berechtigung.' );
		}
		check_admin_referer( 'bws_save' );

		$values = array();
		foreach ( array( 'sync_products', 'sync_orders', 'sku_fallback', 'update_contacts' ) as $key ) {
			$values[ $key ] = isset( $_POST[ $key ] ) ? 'yes' : 'no';
		}
		$token = isset( $_POST['api_token'] ) ? trim( sanitize_text_field( wp_unslash( $_POST['api_token'] ) ) ) : '';
		if ( '' !== $token ) {
			$values['api_token'] = $token;
			BWS_Lookup::flush();
		}
		$values['stock_mode']         = ( isset( $_POST['stock_mode'] ) && in_array( $_POST['stock_mode'], array( 'from_bexio', 'to_bexio', 'off' ), true ) ) ? sanitize_key( $_POST['stock_mode'] ) : 'from_bexio';
		$values['stock_import_time']  = BWS_Stock_Import::normalize_time( isset( $_POST['stock_import_time'] ) ? sanitize_text_field( wp_unslash( $_POST['stock_import_time'] ) ) : '' );
		$values['stock_field']        = ( isset( $_POST['stock_field'] ) && 'stock_available_nr' === $_POST['stock_field'] ) ? 'stock_available_nr' : 'stock_nr';
		$values['order_statuses']     = isset( $_POST['order_statuses'] ) ? array_map( 'sanitize_key', (array) wp_unslash( $_POST['order_statuses'] ) ) : array();
		$values['price_mode']         = ( isset( $_POST['price_mode'] ) && 'net' === $_POST['price_mode'] ) ? 'net' : 'gross';
		$values['order_title_prefix'] = isset( $_POST['order_title_prefix'] ) ? sanitize_text_field( wp_unslash( $_POST['order_title_prefix'] ) ) : '';

		foreach ( array( 'user_id', 'unit_id', 'stock_id', 'stock_place_id', 'account_id', 'default_tax_id', 'contact_group_id', 'language_id' ) as $key ) {
			if ( isset( $_POST[ $key ] ) ) {
				$raw            = sanitize_text_field( wp_unslash( $_POST[ $key ] ) );
				$values[ $key ] = '' === $raw ? '' : (string) absint( $raw );
			}
		}

		BWS_Settings::save( $values );
		BWS_Stock_Import::ensure_schedule();
		self::redirect( 'Einstellungen gespeichert.' );
	}

	public static function handle_tool() {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( 'Keine Berechtigung.' );
		}
		check_admin_referer( 'bws_tool' );
		$tool = isset( $_POST['tool'] ) ? sanitize_key( $_POST['tool'] ) : '';

		if ( 'sync_all_products' === $tool ) {
			as_enqueue_async_action( 'bws_bulk_sync_products', array( 1 ), BWS_AS_GROUP );
			self::redirect( 'Übertragung aller Artikel wurde im Hintergrund gestartet.' );
		}
		if ( 'import_stock' === $tool ) {
			as_enqueue_async_action( BWS_Stock_Import::HOOK, array(), BWS_AS_GROUP );
			self::redirect( 'Lagerimport aus bexio wurde im Hintergrund gestartet.' );
		}
		if ( 'flush_cache' === $tool ) {
			BWS_Lookup::flush();
			self::redirect( 'bexio-Stammdaten werden neu geladen.' );
		}
		self::redirect( '' );
	}

	private static function redirect( $message ) {
		wp_safe_redirect( add_query_arg( array( 'page' => self::PAGE, 'bws_msg' => rawurlencode( $message ) ), admin_url( 'admin.php' ) ) );
		exit;
	}

	/* ------------------------------------------------------------------ */
	/* Bestellungen                                                        */
	/* ------------------------------------------------------------------ */

	public static function order_actions( $actions, $order = null ) {
		if ( ! $order instanceof WC_Order || ! $order->get_meta( BWS_Order_Sync::META_ORDER_ID ) ) {
			$actions['bws_send_to_bexio'] = 'An bexio übertragen';
		}
		return $actions;
	}

	public static function order_action_send( WC_Order $order ) {
		try {
			BWS_Order_Sync::sync( $order );
		} catch ( BWS_Exception $e ) {
			bws_log( 'error', sprintf( 'Bestellung #%s: %s', $order->get_order_number(), $e->getMessage() ) );
			$order->update_meta_data( BWS_Order_Sync::META_ERROR, $e->getMessage() );
			$order->add_order_note( 'bexio-Übertragung fehlgeschlagen: ' . $e->getMessage() );
			$order->save();
		}
	}

	public static function bulk_actions( $actions ) {
		$actions['bws_send_to_bexio'] = 'An bexio übertragen';
		return $actions;
	}

	public static function handle_bulk_action( $redirect, $action, $ids ) {
		if ( 'bws_send_to_bexio' !== $action ) {
			return $redirect;
		}
		foreach ( (array) $ids as $id ) {
			BWS_Order_Sync::enqueue( (int) $id );
		}
		return $redirect;
	}

	public static function order_meta_box() {
		$screen = function_exists( 'wc_get_page_screen_id' ) ? wc_get_page_screen_id( 'shop-order' ) : 'shop_order';
		add_meta_box( 'bws-order', 'bexio', array( __CLASS__, 'render_order_meta_box' ), $screen, 'side', 'default' );
	}

	public static function render_order_meta_box( $post_or_order ) {
		$order = $post_or_order instanceof WC_Order ? $post_or_order : wc_get_order( $post_or_order->ID );
		if ( ! $order ) {
			return;
		}
		$id    = (int) $order->get_meta( BWS_Order_Sync::META_ORDER_ID );
		$nr    = $order->get_meta( BWS_Order_Sync::META_ORDER_NR );
		$error = $order->get_meta( BWS_Order_Sync::META_ERROR );

		if ( $id ) {
			printf(
				'<p>Auftrag <strong>%s</strong><br><a href="%s" target="_blank" rel="noopener">In bexio öffnen</a></p>',
				esc_html( $nr ? $nr : $id ),
				esc_url( 'https://office.bexio.com/index.php/kb_order/show/id/' . $id )
			);
		} else {
			echo '<p>Noch nicht übertragen. Aktion „An bexio übertragen“ wählen, um manuell zu senden.</p>';
		}
		if ( $error ) {
			echo '<p style="color:#b32d2e">Letzter Fehler: ' . esc_html( $error ) . '</p>';
		}
	}
}
