<?php
/**
 * Admin: Meta-Box "Konfigurator" am Produkt mit Klick-Kalibrierung im Bild.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class AK_Admin {

	const FIELDS = array( 'role', 'image_id', 'flip', 'p1', 'p2', 'ref_mm', 'anchor', 'tube_anchor', 'schnittstelle', 'mittelrohr', 'direkt', 'gesperrt', 'gruppe', 'hinweis', 'freigestellt', 'ausgang', 'muzzle_device_if', 'muzzle_device_anchor', 'laenge', 'bottom', 'freiraum' );

	/** Alle gespeicherten Felder inkl. Steckplätze der Waffe. */
	public static function fields() {
		$fields = self::FIELDS;
		foreach ( array_keys( AK_Data::SLOTS ) as $slot ) {
			$fields[] = 'slot_' . $slot . '_anchor';
			$fields[] = 'slot_' . $slot . '_if';
		}
		return $fields;
	}

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
		foreach ( self::fields() as $f ) {
			$v[ $f ] = (string) get_post_meta( $post->ID, '_ak_' . $f, true );
		}
		$image_id = $v['image_id'] ? (int) $v['image_id'] : (int) get_post_thumbnail_id( $post->ID );
		$src      = $image_id ? wp_get_attachment_image_src( $image_id, 'full' ) : false;
		$scale    = AK_Data::scale( $post->ID );
		$product  = wc_get_product( $post->ID );
		$gallery  = array_values( array_unique( array_filter( array_merge( array( (int) get_post_thumbnail_id( $post->ID ) ), $product ? array_map( 'intval', $product->get_gallery_image_ids() ) : array() ) ) ) );
		$variable = $product && $product->is_type( 'variable' );
		?>
		<div class="ak-box" data-role="<?php echo esc_attr( $v['role'] ); ?>" data-ifs="<?php echo esc_attr( wp_json_encode( AK_Data::known_interfaces() ) ); ?>" data-gallery="<?php echo esc_attr( wp_json_encode( $gallery ) ); ?>">
			<?php if ( $variable ) : ?>
				<p class="ak-variable-note">Variables Produkt: Die Einrichtung gilt für alle Varianten. Jede kaufbare Variante erscheint im Konfigurator einzeln mit eigenem Preis und Lager. Den Mittelrohrdurchmesser nimmt das Plugin aus dem Variantenattribut «Mittelrohrdurchmesser [mm]», das Feld unten bitte leer lassen.</p>
			<?php endif; ?>
			<p class="ak-row">
				<label>Rolle
					<select name="ak[role]" class="ak-role">
						<option value="">nicht im Konfigurator</option>
						<option value="waffe" <?php selected( $v['role'], 'waffe' ); ?>>Waffe</option>
						<option value="optik" <?php selected( $v['role'], 'optik' ); ?>>Optik</option>
						<option value="montage" <?php selected( $v['role'], 'montage' ); ?>>Montage (für Optik)</option>
						<option value="zubehoer" <?php selected( $v['role'], 'zubehoer' ); ?>>Zubehör</option>
					</select>
				</label>
				<label class="ak-only-zubehoer">Gruppe
					<select name="ak[gruppe]">
						<option value="">wählen</option>
						<?php foreach ( AK_Data::GROUPS as $gk => $gdef ) : ?>
							<option value="<?php echo esc_attr( $gk ); ?>" <?php selected( $v['gruppe'], $gk ); ?>><?php echo esc_html( $gdef[1] ); ?></option>
						<?php endforeach; ?>
					</select>
				</label>
				<label><span class="ak-if-label">Schnittstelle(n)</span>
					<input type="text" name="ak[schnittstelle]" class="ak-if" data-kind="auto" value="<?php echo esc_attr( $v['schnittstelle'] ); ?>" placeholder="picatinny">
				</label>
				<label class="ak-only-montage ak-only-optik">Mittelrohr [mm]
					<input type="text" name="ak[mittelrohr]" value="<?php echo esc_attr( $v['mittelrohr'] ); ?>" placeholder="aus Attribut: <?php echo esc_attr( AK_Data::tube( $post->ID ) ?: '–' ); ?>" size="6">
				</label>
				<label class="ak-only-optik"><input type="checkbox" name="ak[direkt]" value="yes" <?php checked( $v['direkt'], 'yes' ); ?>> direkt auf Schiene oder Footprint (Rotpunkt / Montage integriert)</label>
			</p>
			<p class="ak-row ak-only-zubehoer">
				<label>Hinweis für Kunden (z.B. «Bewilligungspflichtig»)
					<input type="text" name="ak[hinweis]" value="<?php echo esc_attr( $v['hinweis'] ); ?>" size="40">
				</label>
				<label><input type="checkbox" name="ak[freigestellt]" value="yes" <?php checked( $v['freigestellt'], 'yes' ); ?>> Bild ist freigestellt (PNG ohne Hintergrund, verdeckt Originalteil)</label>
			</p>
			<p class="ak-row ak-only-zubehoer">
				<label>Ausgang (Mündungsaufsatz und HUB: z.B. HUB-Standard 1.375x24; Adapterschiene: picatinny)
					<input type="text" name="ak[ausgang]" class="ak-if" data-kind="auto-out" value="<?php echo esc_attr( $v['ausgang'] ); ?>" size="22">
				</label>
				<span class="description">Bei Mündungsaufsatz und HUB zusätzlich «Ausgangspunkt» (vorderes Ende) setzen. Adapterschiene: Schnittstelle = m-lok, Ausgang = picatinny, Ankerpunkt = Auflage am Handschutz, Ausgangspunkt = Oberfläche der Schiene.</span>
			</p>
			<div class="ak-slots ak-only-waffe">
				<strong>Steckplätze</strong> <span class="description">Schnittstellen (z.B. m-lok, picatinny, 1/2x28, ar15-buffer, ar15-griff) und Punkt im Bild. Leer = Steckplatz nicht verwendet.</span>
				<?php foreach ( AK_Data::SLOTS as $sk => $slabel ) : ?>
					<p class="ak-row">
						<label><?php echo esc_html( $slabel ); ?>
							<input type="text" name="ak[slot_<?php echo esc_attr( $sk ); ?>_if]" class="ak-if" data-kind="<?php echo esc_attr( array( 'muendung' => 'gewinde', 'unten' => 'schiene', 'seite' => 'schiene', 'vorne' => 'schiene', 'schaft' => 'schaft', 'griff' => 'griff' )[ $sk ] ); ?>" value="<?php echo esc_attr( $v[ 'slot_' . $sk . '_if' ] ); ?>" size="22">
						</label>
						<button type="button" class="button ak-tool" data-target="slot_<?php echo esc_attr( $sk ); ?>_anchor">Punkt setzen</button>
						<input type="hidden" name="ak[slot_<?php echo esc_attr( $sk ); ?>_anchor]" class="ak-pt" data-pt="slot_<?php echo esc_attr( $sk ); ?>_anchor" value="<?php echo esc_attr( $v[ 'slot_' . $sk . '_anchor' ] ); ?>">
					</p>
				<?php endforeach; ?>
				<p class="ak-row">
					<label>Original-Mündungsaufsatz: Schnittstelle für HUB (leer = keiner)
						<input type="text" name="ak[muzzle_device_if]" class="ak-if" data-kind="gewinde" value="<?php echo esc_attr( $v['muzzle_device_if'] ); ?>" size="22">
					</label>
					<button type="button" class="button ak-tool" data-target="muzzle_device_anchor">Vorderes Ende setzen</button>
					<input type="hidden" name="ak[muzzle_device_anchor]" class="ak-pt" data-pt="muzzle_device_anchor" value="<?php echo esc_attr( $v['muzzle_device_anchor'] ); ?>">
				</p>
			</div>
			<p class="ak-row ak-only-waffe">
				<label>Gesperrte Produkt-IDs (Ausnahmen, kommagetrennt)
					<input type="text" name="ak[gesperrt]" value="<?php echo esc_attr( $v['gesperrt'] ); ?>" size="30">
				</label>
			</p>
			<p class="ak-row ak-only-waffe">
				<label>Freiraum unter der Optik [mm]
					<input type="text" name="ak[freiraum]" value="<?php echo esc_attr( $v['freiraum'] ); ?>" size="5" placeholder="0">
				</label>
				<span class="description">Wie weit unter der Oberkante der Schiene unter Objektiv und Okular Platz ist. Durchgehende Schiene (z.B. AR15) = 0, Repetierer mit Montagebasis über dem Lauf z.B. 10–15. Eine Montage passt nur, wenn Achshöhe + Freiraum mindestens 1 mm mehr ist, als die Optik unter die Rohrachse reicht.</span>
			</p>

			<p class="ak-row">
				<input type="hidden" name="ak[image_id]" class="ak-image-id" value="<?php echo esc_attr( $v['image_id'] ); ?>">
				<button type="button" class="button ak-pick-image">Vorschaubild aus Produktbildern</button>
				<button type="button" class="button-link ak-pick-image ak-pick-all">andere Bilder (Mediathek)</button>
				<span class="description">Seitenansicht von rechts. Leer = Produktbild.</span>
				<label><input type="checkbox" name="ak[flip]" value="yes" <?php checked( $v['flip'], 'yes' ); ?>> Bild spiegeln (wenn es die linke Seite zeigt)</label>
			</p>

			<div class="ak-auto-row">
				<label>Länge [mm] <input type="text" name="ak[laenge]" class="ak-laenge" value="<?php echo esc_attr( $v['laenge'] ); ?>" size="6" placeholder="<?php echo esc_attr( (string) ( AK_Auto::length( $post->ID ) ?: '' ) ); ?>"></label>
				<button type="button" class="button button-primary ak-auto" data-id="<?php echo (int) $post->ID; ?>" data-nonce="<?php echo esc_attr( wp_create_nonce( 'ak_auto' ) ); ?>">Automatisch einmessen</button>
				<span class="description">Gesamtlänge des Produkts im Bild. Leer = Attribut «Länge [mm]». Das Plugin erkennt den Umriss (weisser oder transparenter Hintergrund) und setzt Messpunkte und Ankerpunkte. Danach prüfen und speichern.</span>
				<span class="ak-auto-msg"></span>
			</div>
			<div class="ak-tools">
				<strong>Punkte im Bild setzen:</strong>
				<button type="button" class="button ak-tool" data-target="p1">Messpunkt 1</button>
				<button type="button" class="button ak-tool" data-target="p2">Messpunkt 2</button>
				<label>Abstand der Messpunkte [mm] <input type="text" name="ak[ref_mm]" class="ak-ref" value="<?php echo esc_attr( $v['ref_mm'] ); ?>" size="6"></label>
				<button type="button" class="button ak-tool" data-target="anchor">Ankerpunkt</button>
				<button type="button" class="button ak-tool ak-only-montage" data-target="tube_anchor">Rohrmitte</button>
				<button type="button" class="button ak-tool ak-only-zubehoer" data-target="tube_anchor">Ausgangspunkt</button>
				<button type="button" class="button ak-tool ak-only-optik" data-target="bottom">Tiefster Punkt</button>
				<span class="ak-scale">Massstab: <?php echo $scale > 0 ? esc_html( number_format( $scale, 3, '.', '' ) ) . ' mm/px' : 'noch nicht gesetzt'; ?></span>
			</div>
			<div class="ak-help">
				<p><b>Messpunkte:</b> zwei Punkte mit bekanntem Abstand, z.B. Mündung und Schaftende (Gesamtlänge) oder Vorder- und Hinterende der Optik (Länge).</p>
				<p><b>Ankerpunkt:</b> Waffe = Stelle auf der Oberkante der Schiene, wo die Optik sitzen soll. Montage = Mitte der Unterseite (Schienenauflage). Optik mit Mittelrohr = Mitte des Mittelrohrs (Rohrachse). Rotpunkt = Mitte der Unterseite.</p>
				<p><b>Rohrmitte (nur Montage):</b> Mitte der Ringe, wo die Rohrachse der Optik liegt.</p>
				<p><b>Zubehör-Anker:</b> Mündungsaufsatz, HUB und Schalldämpfer = hinteres Ende auf der Laufachse; Ausgangspunkt = vorderes Ende auf der Laufachse. Handgriff und Laser = Mitte der Befestigungsfläche. Schulterstütze = vorderes Ende auf der Achse des Buffer Tubes. Griff = oberer vorderer Punkt am Gehäuse.</p>
				<p><b>Steckplätze der Waffe:</b> Mündung = Gewindeschulter (Beginn des Laufgewindes) auf der Laufachse. Original-Mündungsaufsatz = sein vorderes Ende. Handschutz = Stelle für Griff bzw. Laser. Schaft = hinteres Gehäuseende auf der Achse des Buffer Tubes. Griff = Griffaufnahme oben vorne.</p>
			</div>
			<?php foreach ( array( 'p1', 'p2', 'anchor', 'tube_anchor', 'bottom' ) as $pt ) : ?>
				<input type="hidden" name="ak[<?php echo esc_attr( $pt ); ?>]" class="ak-pt" data-pt="<?php echo esc_attr( $pt ); ?>" value="<?php echo esc_attr( $v[ $pt ] ); ?>">
			<?php endforeach; ?>
			<div class="ak-canvas">
				<?php if ( $src ) : ?>
					<img src="<?php echo esc_url( $src[0] ); ?>" data-w="<?php echo (int) $src[1]; ?>" data-h="<?php echo (int) $src[2]; ?>" class="ak-img" alt="">
				<?php else : ?>
					<p class="ak-noimg">Kein Bild. Bitte Produktbild oder Vorschaubild setzen.</p>
				<?php endif; ?>
			</div>
			<p class="ak-status"><?php echo AK_Data::is_ready( $post->ID ) ? '✔ Eingerichtet' : 'Noch nicht vollständig eingerichtet'; ?>
				<?php if ( 'montage' === $v['role'] && AK_Data::axis_height( $post->ID ) > 0 ) : ?>
					· Achshöhe über Schiene: <?php echo esc_html( number_format( AK_Data::axis_height( $post->ID ), 1, '.', '' ) ); ?> mm
				<?php endif; ?>
				<?php if ( 'optik' === $v['role'] && 'yes' !== $v['direkt'] && AK_Data::optic_depth( $post->ID ) > 0 ) : ?>
					· Reicht <?php echo esc_html( number_format( AK_Data::optic_depth( $post->ID ), 1, '.', '' ) ); ?> mm unter die Rohrachse<?php echo AK_Data::point( $post->ID, 'bottom' ) ? '' : ' (geschätzt aus Objektivdurchmesser)'; ?>
				<?php endif; ?>
			</p>
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
		foreach ( self::fields() as $f ) {
			$val = isset( $in[ $f ] ) ? sanitize_text_field( $in[ $f ] ) : '';
			if ( in_array( $f, array( 'flip', 'direkt', 'freigestellt' ), true ) ) {
				$val = 'yes' === $val ? 'yes' : 'no';
			}
			if ( 'role' === $f && ! in_array( $val, AK_Data::ROLES, true ) ) {
				$val = '';
			}
			if ( 'gruppe' === $f && ! isset( AK_Data::GROUPS[ $val ] ) ) {
				$val = '';
			}
			if ( in_array( $f, array( 'schnittstelle', 'ausgang', 'muzzle_device_if' ), true ) || preg_match( '/^slot_.+_if$/', $f ) ) {
				$val = implode( ', ', AK_Data::split( $val ) );
			}
			if ( '' === $val ) {
				delete_post_meta( $post_id, '_ak_' . $f );
			} else {
				update_post_meta( $post_id, '_ak_' . $f, $val );
			}
		}
	}
}
