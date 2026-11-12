<?php
/**
 * Prüfliste: alle eingerichteten Produkte mit Bild und gesetzten Punkten auf einen Blick.
 * Passt es, hakt man es ab («OK»), sonst öffnet man das Produkt und korrigiert.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class AK_Review {

	const SLUG = 'ak-pruefliste';

	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'menu' ), 61 );
		add_action( 'wp_ajax_ak_check', array( __CLASS__, 'ajax_check' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'assets' ) );
	}

	public static function menu() {
		add_submenu_page( 'woocommerce', 'Konfigurator-Prüfliste', 'Konfigurator-Prüfliste', 'manage_woocommerce', self::SLUG, array( __CLASS__, 'render' ) );
	}

	public static function assets( $hook ) {
		if ( false === strpos( (string) $hook, self::SLUG ) ) {
			return;
		}
		wp_enqueue_style( 'ak-admin', AK_URL . 'assets/admin.css', array(), AK_VERSION );
		wp_enqueue_script( 'ak-review', AK_URL . 'assets/review.js', array( 'jquery' ), AK_VERSION, true );
		wp_localize_script( 'ak-review', 'akReview', array( 'nonce' => wp_create_nonce( 'ak_check' ) ) );
	}

	public static function ajax_check() {
		check_ajax_referer( 'ak_check', 'nonce' );
		$id = isset( $_POST['id'] ) ? absint( $_POST['id'] ) : 0;
		if ( ! $id || ! current_user_can( 'manage_woocommerce' ) ) {
			wp_send_json_error( array( 'message' => 'Keine Berechtigung.' ), 403 );
		}
		if ( empty( $_POST['checked'] ) ) {
			delete_post_meta( $id, '_ak_checked' );
		} else {
			update_post_meta( $id, '_ak_checked', gmdate( 'Y-m-d' ) );
		}
		wp_send_json_success();
	}

	/** Beschreibung der Punkte, damit man weiss, wo sie sitzen sollten. */
	private static function legend( $role, $group ) {
		if ( 'waffe' === $role ) {
			return 'A = Oberkante Schiene (Optik), 1/2 = Gesamtlänge';
		}
		if ( 'montage' === $role ) {
			return 'A = Mitte Unterseite, R = Ringmitte (Rohrachse)';
		}
		if ( 'optik' === $role ) {
			return 'A = Rohrachse (Rotpunkt: Mitte Unterseite), T = tiefster Punkt';
		}
		if ( in_array( $group, array( 'muendungsaufsatz', 'hub' ), true ) ) {
			return 'A = hinteres Ende, R = vorderes Ende (Laufachse)';
		}
		if ( 'schalldaempfer' === $group ) {
			return 'A = hinteres Ende auf der Laufachse';
		}
		if ( 'adapterschiene' === $group ) {
			return 'A = Auflage oben, R = Schiene unten';
		}
		return 'A = Befestigungspunkt';
	}

	public static function render() {
		$status = isset( $_GET['ak_status'] ) ? sanitize_key( $_GET['ak_status'] ) : 'offen'; // phpcs:ignore WordPress.Security.NonceVerification
		$role   = isset( $_GET['ak_role'] ) ? sanitize_key( $_GET['ak_role'] ) : '';       // phpcs:ignore WordPress.Security.NonceVerification
		$paged  = isset( $_GET['paged'] ) ? max( 1, absint( $_GET['paged'] ) ) : 1;        // phpcs:ignore WordPress.Security.NonceVerification
		$meta   = array( array( 'key' => '_ak_role', 'value' => $role ? $role : AK_Data::ROLES, 'compare' => $role ? '=' : 'IN' ) );
		if ( 'offen' === $status ) {
			$meta[] = array( 'key' => '_ak_checked', 'compare' => 'NOT EXISTS' );
		} elseif ( 'geprueft' === $status ) {
			$meta[] = array( 'key' => '_ak_checked', 'compare' => 'EXISTS' );
		} elseif ( 'hinweis' === $status ) {
			$meta[] = array( 'key' => '_ak_warn', 'compare' => 'EXISTS' );
		}
		$q = new WP_Query(
			array(
				'post_type'      => 'product',
				'post_status'    => array( 'publish', 'draft', 'private', 'pending' ),
				'posts_per_page' => 40,
				'paged'          => $paged,
				'fields'         => 'ids',
				'orderby'        => 'title',
				'order'          => 'ASC',
				'meta_query'     => $meta, // phpcs:ignore WordPress.DB.SlowDBQuery
			)
		);
		$labels = AK_Auto::warning_labels();
		$base   = admin_url( 'admin.php?page=' . self::SLUG );
		?>
		<div class="wrap ak-review">
			<h1>Konfigurator-Prüfliste</h1>
			<p>Jedes Produkt mit seinen Punkten. Stimmt alles, auf «OK» klicken. Sonst «Bearbeiten», Punkte korrigieren und speichern. Neu eingemessene Produkte erscheinen wieder unter «Offen».</p>
			<ul class="subsubsub">
				<?php
				$tabs = array(
					'offen'    => 'Offen',
					'hinweis'  => 'Mit Bildhinweis',
					'geprueft' => 'Geprüft',
					'alle'     => 'Alle',
				);
				$links = array();
				foreach ( $tabs as $k => $t ) {
					$links[] = '<li><a href="' . esc_url( add_query_arg( array( 'ak_status' => $k, 'ak_role' => $role ), $base ) ) . '"' . ( $k === $status ? ' class="current"' : '' ) . '>' . esc_html( $t ) . '</a></li>';
				}
				echo implode( ' | ', $links ); // phpcs:ignore WordPress.Security.EscapeOutput
				?>
			</ul>
			<form method="get" class="ak-review-filter">
				<input type="hidden" name="page" value="<?php echo esc_attr( self::SLUG ); ?>">
				<input type="hidden" name="ak_status" value="<?php echo esc_attr( $status ); ?>">
				<select name="ak_role" onchange="this.form.submit()">
					<option value="">Alle Rollen</option>
					<?php foreach ( array( 'waffe' => 'Waffen', 'optik' => 'Optiken', 'montage' => 'Montagen', 'zubehoer' => 'Zubehör' ) as $k => $t ) : ?>
						<option value="<?php echo esc_attr( $k ); ?>" <?php selected( $role, $k ); ?>><?php echo esc_html( $t ); ?></option>
					<?php endforeach; ?>
				</select>
				<span><?php echo (int) $q->found_posts; ?> Produkte</span>
			</form>
			<div class="ak-review-grid">
				<?php
				foreach ( $q->posts as $id ) :
					$id    = (int) $id;
					$r     = AK_Data::role( $id );
					$g     = AK_Data::group( $id );
					$img   = AK_Data::image_id( $id );
					$full  = $img ? wp_get_attachment_image_src( $img, 'full' ) : false;
					$show  = $img ? wp_get_attachment_image_src( $img, 'medium_large' ) : false;
					$warn  = array_filter( explode( ' ', (string) get_post_meta( $id, '_ak_warn', true ) ) );
					$check = (string) get_post_meta( $id, '_ak_checked', true );
					$pts   = array(
						'p1'          => array( '1', '#0073aa' ),
						'p2'          => array( '2', '#0073aa' ),
						'anchor'      => array( 'A', '#d63638' ),
						'tube_anchor' => array( 'R', '#00a32a' ),
						'bottom'      => array( 'T', '#e65100' ),
					);
					$info = array();
					if ( $g ) {
						$info[] = AK_Data::GROUPS[ $g ][1];
					} else {
						$info[] = ucfirst( $r ) . ( AK_Data::is_direct( $id ) ? ' (direkt)' : '' );
					}
					if ( AK_Auto::length( $id ) ) {
						$info[] = 'Länge ' . AK_Auto::length( $id ) . ' mm';
					}
					if ( 'montage' === $r && AK_Data::axis_height( $id ) > 0 ) {
						$info[] = 'Achshöhe ' . number_format( AK_Data::axis_height( $id ), 1, '.', '' ) . ' mm';
					}
					if ( 'optik' === $r && ! AK_Data::is_direct( $id ) && AK_Data::optic_depth( $id ) > 0 ) {
						$info[] = 'unter Achse ' . number_format( AK_Data::optic_depth( $id ), 1, '.', '' ) . ' mm';
					}
					$ifs = AK_Data::interfaces( $id );
					if ( $ifs ) {
						$info[] = 'passt auf: ' . implode( ', ', $ifs );
					}
					?>
					<div class="ak-review-card<?php echo $check ? ' is-checked' : ''; ?>" data-id="<?php echo (int) $id; ?>">
						<div class="ak-review-img">
							<?php if ( $show && $full ) : ?>
								<img src="<?php echo esc_url( $show[0] ); ?>" alt="" loading="lazy" class="<?php echo 'yes' === AK_Data::meta( $id, 'flip', 'no' ) ? 'ak-flip' : ''; ?>">
								<?php
								foreach ( $pts as $k => $d ) :
									$p = AK_Data::point( $id, $k );
									if ( ! $p ) {
										continue;
									}
									$x = $p[0] / max( 1, $full[1] ) * 100;
									if ( 'yes' === AK_Data::meta( $id, 'flip', 'no' ) ) {
										$x = 100 - $x;
									}
									?>
									<span class="ak-marker" style="left:<?php echo esc_attr( round( $x, 2 ) ); ?>%;top:<?php echo esc_attr( round( $p[1] / max( 1, $full[2] ) * 100, 2 ) ); ?>%;background:<?php echo esc_attr( $d[1] ); ?>"><?php echo esc_html( $d[0] ); ?></span>
								<?php endforeach; ?>
							<?php else : ?>
								<p>Kein Bild</p>
							<?php endif; ?>
						</div>
						<strong><?php echo esc_html( get_the_title( $id ) ); ?></strong>
						<span class="ak-review-info"><?php echo esc_html( implode( ' · ', $info ) ); ?></span>
						<span class="ak-review-legend"><?php echo esc_html( self::legend( $r, $g ) ); ?></span>
						<?php if ( ! AK_Data::is_ready( $id ) ) : ?>
							<span class="ak-review-warn">Noch nicht vollständig eingerichtet</span>
						<?php endif; ?>
						<?php foreach ( $warn as $w ) : ?>
							<span class="ak-review-warn"><?php echo esc_html( $labels[ $w ] ?? $w ); ?></span>
						<?php endforeach; ?>
						<span class="ak-review-actions">
							<button type="button" class="button button-primary ak-review-ok"><?php echo $check ? 'Geprüft ✔' : 'OK'; ?></button>
							<a class="button" href="<?php echo esc_url( (string) get_edit_post_link( $id ) ); ?>#ak_konfigurator" target="_blank">Bearbeiten</a>
						</span>
					</div>
				<?php endforeach; ?>
			</div>
			<?php
			echo '<div class="tablenav"><div class="tablenav-pages">' . paginate_links( // phpcs:ignore WordPress.Security.EscapeOutput
				array(
					'base'    => add_query_arg( 'paged', '%#%' ),
					'format'  => '',
					'current' => $paged,
					'total'   => max( 1, (int) $q->max_num_pages ),
				)
			) . '</div></div>';
			?>
		</div>
		<?php
	}
}
