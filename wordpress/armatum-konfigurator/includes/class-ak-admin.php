<?php
/**
 * Admin: Meta-Box "Konfigurator" am Produkt mit Klick-Kalibrierung im Bild.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class AK_Admin {

	const FIELDS = array( 'role', 'image_id', 'flip', 'p1', 'p2', 'ref_mm', 'anchor', 'tube_anchor', 'schnittstelle', 'mittelrohr', 'direkt', 'gesperrt' );

	public static function init() {
		add_action( 'add_meta_boxes', array( __CLASS__, 'add_box' ) );
		add_action( 'save_post_product', array( __CLASS__, 'save' ), 10, 2 );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'assets' ) );
	}

	public static function add_box() {
		add_meta_box( 'ak_konfigurator', 'Konfigurator', array( __CLASS__, 'render' ), 'product', 'normal', 'default' );
	}

	public static function assets( $hook ) {
		if ( ! in_array( $hook, array( 'post.php', 'post-new.php' ), true ) || 'product' !== get_post_type() ) {
			return;
		}
		wp_enqueue_media();
		wp_enqueue_style( 'ak-admin', AK_URL . 'assets/admin.css', array(), AK_VERSION );
		wp_enqueue_script( 'ak-admin', AK_URL . 'assets/admin.js', array( 'jquery' ), AK_VERSION, true );
	}

	public static function render( $post ) {
		wp_nonce_field( 'ak_save', 'ak_nonce' );
		$v = array();
		foreach ( self::FIELDS as $f ) {
			$v[ $f ] = (string) get_post_meta( $post->ID, '_ak_' . $f, true );
		}
		$image_id = $v['image_id'] ? (int) $v['image_id'] : (int) get_post_thumbnail_id( $post->ID );
		$src      = $image_id ? wp_get_attachment_image_src( $image_id, 'full' ) : false;
		$scale    = AK_Data::scale( $post->ID );
		?>
		<div class="ak-box" data-role="<?php echo esc_attr( $v['role'] ); ?>">
			<p class="ak-row">
				<label>Rolle
					<select name="ak[role]" class="ak-role">
						<option value="">nicht im Konfigurator</option>
						<option value="waffe" <?php selected( $v['role'], 'waffe' ); ?>>Waffe</option>
						<option value="optik" <?php selected( $v['role'], 'optik' ); ?>>Optik</option>
						<option value="montage" <?php selected( $v['role'], 'montage' ); ?>>Montage</option>
					</select>
				</label>
				<label>Schnittstelle(n)
					<input type="text" name="ak[schnittstelle]" value="<?php echo esc_attr( $v['schnittstelle'] ); ?>" placeholder="picatinny">
				</label>
				<label class="ak-only-montage ak-only-optik">Mittelrohr [mm]
					<input type="text" name="ak[mittelrohr]" value="<?php echo esc_attr( $v['mittelrohr'] ); ?>" placeholder="aus Attribut: <?php echo esc_attr( AK_Data::tube( $post->ID ) ?: '–' ); ?>" size="6">
				</label>
				<label class="ak-only-optik"><input type="checkbox" name="ak[direkt]" value="yes" <?php checked( $v['direkt'], 'yes' ); ?>> direkt auf Schiene (Rotpunkt / Montage integriert)</label>
			</p>
			<p class="ak-row ak-only-waffe">
				<label>Gesperrte Produkt-IDs (Ausnahmen, kommagetrennt)
					<input type="text" name="ak[gesperrt]" value="<?php echo esc_attr( $v['gesperrt'] ); ?>" size="30">
				</label>
			</p>

			<p class="ak-row">
				<input type="hidden" name="ak[image_id]" class="ak-image-id" value="<?php echo esc_attr( $v['image_id'] ); ?>">
				<button type="button" class="button ak-pick-image">Vorschaubild wählen</button>
				<span class="description">Seitenansicht von rechts. Leer = Produktbild.</span>
				<label><input type="checkbox" name="ak[flip]" value="yes" <?php checked( $v['flip'], 'yes' ); ?>> Bild spiegeln (wenn es die linke Seite zeigt)</label>
			</p>

			<div class="ak-tools">
				<strong>Punkte im Bild setzen:</strong>
				<button type="button" class="button ak-tool" data-target="p1">Messpunkt 1</button>
				<button type="button" class="button ak-tool" data-target="p2">Messpunkt 2</button>
				<label>Abstand der Messpunkte [mm] <input type="text" name="ak[ref_mm]" value="<?php echo esc_attr( $v['ref_mm'] ); ?>" size="6"></label>
				<button type="button" class="button ak-tool" data-target="anchor">Ankerpunkt</button>
				<button type="button" class="button ak-tool ak-only-montage" data-target="tube_anchor">Rohrmitte</button>
				<span class="ak-scale">Massstab: <?php echo $scale > 0 ? esc_html( number_format( $scale, 3, '.', '' ) ) . ' mm/px' : 'noch nicht gesetzt'; ?></span>
			</div>
			<div class="ak-help">
				<p><b>Messpunkte:</b> zwei Punkte mit bekanntem Abstand, z.B. Mündung und Schaftende (Gesamtlänge) oder Vorder- und Hinterende der Optik (Länge).</p>
				<p><b>Ankerpunkt:</b> Waffe = Stelle auf der Oberkante der Schiene, wo die Optik sitzen soll. Montage = Mitte der Unterseite (Schienenauflage). Optik mit Mittelrohr = Mitte des Mittelrohrs (Rohrachse). Rotpunkt = Mitte der Unterseite.</p>
				<p><b>Rohrmitte (nur Montage):</b> Mitte der Ringe, wo die Rohrachse der Optik liegt.</p>
			</div>
			<?php foreach ( array( 'p1', 'p2', 'anchor', 'tube_anchor' ) as $pt ) : ?>
				<input type="hidden" name="ak[<?php echo esc_attr( $pt ); ?>]" class="ak-pt" data-pt="<?php echo esc_attr( $pt ); ?>" value="<?php echo esc_attr( $v[ $pt ] ); ?>">
			<?php endforeach; ?>
			<div class="ak-canvas">
				<?php if ( $src ) : ?>
					<img src="<?php echo esc_url( $src[0] ); ?>" data-w="<?php echo (int) $src[1]; ?>" data-h="<?php echo (int) $src[2]; ?>" class="ak-img" alt="">
				<?php else : ?>
					<p class="ak-noimg">Kein Bild. Bitte Produktbild oder Vorschaubild setzen.</p>
				<?php endif; ?>
			</div>
			<p class="ak-status"><?php echo AK_Data::is_ready( $post->ID ) ? '✔ Eingerichtet' : 'Noch nicht vollständig eingerichtet'; ?></p>
		</div>
		<?php
	}

	public static function save( $post_id, $post ) {
		if ( ! isset( $_POST['ak_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['ak_nonce'] ) ), 'ak_save' ) ) {
			return;
		}
		if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
			return;
		}
		if ( ! current_user_can( 'edit_product', $post_id ) ) {
			return;
		}
		$in = isset( $_POST['ak'] ) && is_array( $_POST['ak'] ) ? wp_unslash( $_POST['ak'] ) : array();
		foreach ( self::FIELDS as $f ) {
			$val = isset( $in[ $f ] ) ? sanitize_text_field( $in[ $f ] ) : '';
			if ( in_array( $f, array( 'flip', 'direkt' ), true ) ) {
				$val = 'yes' === $val ? 'yes' : 'no';
			}
			if ( 'role' === $f && ! in_array( $val, AK_Data::ROLES, true ) ) {
				$val = '';
			}
			if ( '' === $val ) {
				delete_post_meta( $post_id, '_ak_' . $f );
			} else {
				update_post_meta( $post_id, '_ak_' . $f, $val );
			}
		}
	}
}
