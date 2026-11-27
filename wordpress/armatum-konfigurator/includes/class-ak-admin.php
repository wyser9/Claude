<?php
/**
 * Admin: Meta-Box "Konfigurator" am Produkt mit Klick-Kalibrierung im Bild.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class AK_Admin {

	const FIELDS = array( 'role', 'image_id', 'flip', 'p1', 'p2', 'ref_mm', 'anchor', 'tube_anchor', 'schnittstelle', 'mittelrohr', 'direkt', 'gesperrt', 'gruppe', 'hinweis', 'freigestellt', 'ausgang', 'muzzle_device_if', 'muzzle_device_anchor', 'laenge', 'bottom', 'freiraum', 'montage_art', 'achshoehe', 'rail_front', 'rail_rear', 'waffenart', 'plattform', 'kaliber',
		'funktion', 'laenge_ohne_schaft', 'magazin', 'schaft_art', 'brace', 'laenge_eingeklappt', 'eingeklappt_funktion', 'laser', 'magazin_plus' );

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
		// Vorschläge aus den Produktattributen (leer gelassene Felder nimmt das Plugin automatisch daraus).
		$hint     = function ( $list ) {
			$list = array_filter( (array) $list );
			return $list ? 'aus Attribut: ' . implode( ', ', $list ) : '';
		};
		$if_hint  = '' === $v['schnittstelle'] ? $hint( AK_Data::interfaces( $post->ID ) ) : '';
		$out_hint = '' === $v['ausgang'] ? $hint( AK_Data::outputs( $post->ID ) ) : '';
		$cal_auto = AK_Data::caliber_mm( $post->ID );
		$det_type = AK_Data::weapon_type( $post->ID );
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
						<option value="magnifier" <?php selected( $v['role'], 'magnifier' ); ?>>Magnifier (hinter Rotpunkt)</option>
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
					<input type="text" name="ak[schnittstelle]" class="ak-if" data-kind="auto" value="<?php echo esc_attr( $v['schnittstelle'] ); ?>" placeholder="<?php echo esc_attr( $if_hint ? $if_hint : 'picatinny' ); ?>">
				</label>
				<label class="ak-only-montage ak-only-optik">Mittelrohr [mm]
					<input type="text" name="ak[mittelrohr]" value="<?php echo esc_attr( $v['mittelrohr'] ); ?>" placeholder="aus Attribut: <?php echo esc_attr( AK_Data::tube( $post->ID ) ?: '–' ); ?>" size="6">
				</label>
				<label class="ak-only-optik"><input type="checkbox" name="ak[direkt]" value="yes" <?php checked( $v['direkt'], 'yes' ); ?>> direkt auf Schiene oder Footprint (Rotpunkt / Montage integriert, Magnifier mit Halterung)</label>
				<label class="ak-only-montage">Art
					<select name="ak[montage_art]">
						<?php foreach ( AK_Data::MOUNT_TYPES as $mk => $ml ) : ?>
							<option value="<?php echo esc_attr( $mk ); ?>" <?php selected( $v['montage_art'], $mk ); ?>><?php echo esc_html( $ml ); ?></option>
						<?php endforeach; ?>
					</select>
				</label>
				<label class="ak-only-optik ak-only-montage">Achshöhe [mm]
					<input type="text" name="ak[achshoehe]" value="<?php echo esc_attr( $v['achshoehe'] ); ?>" size="5" placeholder="<?php echo esc_attr( 'montage' === $v['role'] && AK_Data::measured_axis_height( $post->ID ) > 0 ? 'gemessen ' . number_format( AK_Data::measured_axis_height( $post->ID ), 1, '.', '' ) : 'z.B. 39' ); ?>">
				</label>
			</p>
			<p class="ak-row">
				<?php if ( 'zubehoer' === $v['role'] ) : ?>
				<label class="ak-only-zubehoer">Für Waffenart (Lampe, Laser, Schalldämpfer)
					<select name="ak[waffenart]">
						<option value="">automatisch (<?php echo esc_html( AK_Data::PART_WEAPON_TYPES[ AK_Data::detect_part_weapon_type( $post->post_title ) ] ); ?>)</option>
						<?php foreach ( AK_Data::PART_WEAPON_TYPES as $tk => $tl ) : ?>
							<option value="<?php echo esc_attr( $tk ); ?>" <?php selected( $v['waffenart'], $tk ); ?>><?php echo esc_html( $tl ); ?></option>
						<?php endforeach; ?>
					</select>
				</label>
				<?php else : ?>
				<label class="ak-only-waffe">Waffenart
					<select name="ak[waffenart]">
						<option value="">automatisch (<?php echo esc_html( AK_Data::WEAPON_TYPES[ $det_type ] ); ?>)</option>
						<?php foreach ( AK_Data::WEAPON_TYPES as $tk => $tl ) : ?>
							<option value="<?php echo esc_attr( $tk ); ?>" <?php selected( $v['waffenart'], $tk ); ?>><?php echo esc_html( $tl ); ?></option>
						<?php endforeach; ?>
					</select>
				</label>
				<?php endif; ?>
				<label>Plattform
					<input type="text" name="ak[plattform]" value="<?php echo esc_attr( $v['plattform'] ); ?>" size="18" placeholder="<?php echo esc_attr( 'waffe' === $v['role'] ? 'z.B. ar15' : 'leer = alle' ); ?>" list="ak-platforms">
				</label>
				<datalist id="ak-platforms">
					<?php foreach ( AK_Data::PLATFORMS as $pk => $pl ) : ?>
						<option value="<?php echo esc_attr( $pk ); ?>"><?php echo esc_html( $pl ); ?></option>
					<?php endforeach; ?>
				</datalist>
				<label class="ak-only-waffe ak-only-zubehoer">Kaliber
					<?php
					$ak_cals = taxonomy_exists( 'pa_kaliber' ) ? get_terms( array( 'taxonomy' => 'pa_kaliber', 'hide_empty' => false, 'fields' => 'names', 'orderby' => 'name' ) ) : array();
					$ak_cals = is_array( $ak_cals ) ? array_map( 'wp_specialchars_decode', $ak_cals ) : array();
					if ( '' !== $v['kaliber'] && ! in_array( $v['kaliber'], $ak_cals, true ) ) {
						array_unshift( $ak_cals, $v['kaliber'] );
					}
					?>
					<select name="ak[kaliber]">
						<option value=""><?php
							$ak_attr_cal = $post->ID ? trim( wp_specialchars_decode( (string) ( wc_get_product( $post->ID ) ? wc_get_product( $post->ID )->get_attribute( 'pa_kaliber' ) : '' ) ) ) : '';
							echo esc_html( '' !== $ak_attr_cal ? 'wie Attribut: ' . $ak_attr_cal : ( $cal_auto > 0 ? 'aus Produktname (' . number_format( $cal_auto, 2, '.', '' ) . ' mm)' : 'nicht erkannt' ) );
							?></option>
						<?php foreach ( $ak_cals as $ak_cal ) : ?>
							<option value="<?php echo esc_attr( $ak_cal ); ?>" <?php selected( $v['kaliber'], $ak_cal ); ?>><?php echo esc_html( $ak_cal ); ?></option>
						<?php endforeach; ?>
					</select>
				</label>
			</p>
			<p class="description">Plattform: <?php echo esc_html( implode( ', ', array_keys( AK_Data::PLATFORMS ) ) ); ?>. Waffe: eine Plattform. Teil (Schaft, Griff, Mündungsteil, Montage): eine oder mehrere, kommagetrennt; leer = passt auf alle Plattformen. Kaliber: bei Waffe und Mündungsteil, Auswahl wie im Attribut «Kaliber». Erste Zeile = Wert aus dem Attribut «Kaliber» (sonst Produktname). Mündungsaufsatz (Bremse, Feuerdämpfer) passt nur bei gleichem Kaliber bzw. wenn das Kaliber der Waffe im Bereich liegt (z.B. «7mm - .30»), dazu muss das Gewinde passen. HUB und Schalldämpfer: Bohrung mindestens so gross wie das Kaliber.</p>
			<p class="description ak-only-waffe">Pistole: im Konfigurator nur Rotpunkte (direkt oder über Adapterplatte), keine Zielfernrohre und Magnifier. Schnittstelle = Optik-Schnitt im Schlitten (z.B. rmr, acro, glock-mos). Adapterplatte: Schnittstelle = Schnitt, Ausgang = Footprint des Rotpunkts. Steckplatz Mündung = Laufgewinde (Kompensator, Adapter), Handschutz unten = Schiene für die Lampe.</p>
			<p class="description ak-only-optik">Achshöhe nur bei Rotpunkt und Magnifier mit Halterung: Höhe der optischen Achse über der Auflagefläche (Schiene oder Footprint), z.B. 39 mm (absolute co-witness) oder 49 mm (lower 1/3). Magnifier und Rotpunkt passen nur zusammen, wenn sie höchstens <?php echo esc_html( (string) AK_Data::MAG_TOL ); ?> mm auseinanderliegen. Leer = nicht geprüft.</p>
			<p class="description ak-only-montage">Achshöhe bei Montage: von Hand eingetragen überschreibt sie die Messung aus dem Bild (Mitte Unterseite bis Rohrmitte). Bei Schrägbildern ist die Messung oft zu hoch, dann die Herstellerangabe eintragen.</p>
			<p class="description ak-only-montage">Art «nur Magnifier»: Ringe für einen Magnifier, erscheint nicht bei Zielfernrohren. Art «Adapterplatte»: Schnittstelle = Schiene (picatinny), Ausgang = Footprint des Rotpunkts (z.B. rmr, acro, micro), Rohrmitte = Mitte der Oberseite.</p>
			<?php
			// Ab 1.0.9: Angaben für die Erwerbs-Zusammenfassung (AK_Erwerb). Leer = automatisch.
			$ak_w_len = AK_Erwerb::length_without_stock( $post->ID );
			?>
			<p class="ak-row ak-only-waffe">
				<label>Funktionsweise
					<select name="ak[funktion]">
						<option value="">automatisch (<?php echo esc_html( AK_Erwerb::FUNKTIONEN[ AK_Erwerb::function_type( $post->ID ) ] ?? 'unbekannt' ); ?>)</option>
						<?php foreach ( AK_Erwerb::FUNKTIONEN as $fk => $fl ) : ?>
							<option value="<?php echo esc_attr( $fk ); ?>" <?php selected( $v['funktion'], $fk ); ?>><?php echo esc_html( $fl ); ?></option>
						<?php endforeach; ?>
					</select>
				</label>
				<label>Länge ohne Schaft [mm]
					<input type="text" name="ak[laenge_ohne_schaft]" value="<?php echo esc_attr( $v['laenge_ohne_schaft'] ); ?>" size="6" placeholder="<?php echo esc_attr( $ak_w_len[0] > 0 ? 'geschätzt ' . round( $ak_w_len[0] ) : '' ); ?>">
				</label>
				<?php if ( 'zubehoer' !== $v['role'] ) : // Gleicher Schlüssel wie beim Schaft, darum nur ein Feld je Rolle. ?>
				<label class="ak-w-eingeklappt">Gesamtlänge mit eingeklapptem Originalschaft [mm]
					<input type="text" name="ak[laenge_eingeklappt]" value="<?php echo esc_attr( $v['laenge_eingeklappt'] ); ?>" size="6" placeholder="nur bei Klapp-/Teleskopschaft">
				</label>
				<?php endif; ?>
				<label>Magazin [Schuss]
					<input type="text" name="ak[magazin]" value="<?php echo esc_attr( $v['magazin'] ); ?>" size="4" placeholder="<?php echo esc_attr( (string) ( AK_Erwerb::magazine( $post->ID ) ?: '' ) ); ?>">
				</label>
				<span class="description">Für die Erwerbs-Zusammenfassung. Länge ohne Schaft = Mündung bis Schaftaufnahme (hinteres Gehäuseende bzw. Ende Buffer Tube, Punkt «Schaftaufnahme»), inkl. fest montiertem Mündungsaufsatz. Leer = aus Messpunkten geschätzt (dann entscheidet das Plugin nur mit 10 cm Abstand zu 60 cm, sonst «Abklärung durch Armatum»). Gesamtlänge mit eingeklapptem Originalschaft: nur bei Klapp-/Teleskopschaft, Wert laut Waffenhersteller (eingeschoben bzw. eingeklappt). Magazin leer = Attribut «Magazinkapazität».</span>
			</p>
			<p class="ak-row ak-only-zubehoer">
				<label>Schulterstütze: Bauart
					<select name="ak[schaft_art]">
						<option value="">automatisch (<?php echo esc_html( AK_Erwerb::SCHAFT_ARTEN[ AK_Erwerb::stock_type( $post->ID ) ] ?? 'unbekannt' ); ?>)</option>
						<?php foreach ( AK_Erwerb::SCHAFT_ARTEN as $sk2 => $sl2 ) : ?>
							<option value="<?php echo esc_attr( $sk2 ); ?>" <?php selected( $v['schaft_art'], $sk2 ); ?>><?php echo esc_html( $sl2 ); ?></option>
						<?php endforeach; ?>
					</select>
				</label>
				<?php if ( 'zubehoer' === $v['role'] ) : ?>
				<label>Länge eingeklappt [mm]
					<input type="text" name="ak[laenge_eingeklappt]" value="<?php echo esc_attr( $v['laenge_eingeklappt'] ); ?>" size="5">
				</label>
				<?php endif; ?>
				<label>eingeklappt schussfähig
					<select name="ak[eingeklappt_funktion]">
						<option value="" <?php selected( $v['eingeklappt_funktion'], '' ); ?>>ja</option>
						<option value="nein" <?php selected( $v['eingeklappt_funktion'], 'nein' ); ?>>nein</option>
					</select>
				</label>
				<label>Brace
					<select name="ak[brace]">
						<option value="">automatisch (Name)</option>
						<option value="yes" <?php selected( $v['brace'], 'yes' ); ?>>ja</option>
						<option value="no" <?php selected( $v['brace'], 'no' ); ?>>nein</option>
					</select>
				</label>
				<label>Laser
					<select name="ak[laser]">
						<option value="">automatisch (Gruppe/Name)</option>
						<option value="yes" <?php selected( $v['laser'], 'yes' ); ?>>mit Laser</option>
						<option value="no" <?php selected( $v['laser'], 'no' ); ?>>ohne Laser</option>
					</select>
				</label>
				<label>Magazin +Schuss
					<input type="text" name="ak[magazin_plus]" value="<?php echo esc_attr( $v['magazin_plus'] ); ?>" size="3" placeholder="z.B. 5">
				</label>
				<span class="description">Länge eingeklappt = belegte Zusatzlänge ab Puffer-/Gehäuseende bis Schaftkappe, ganz eingeschoben bzw. eingeklappt (Herstellerangabe, z.B. Strike FSA Dual Folder 26 mm). Ohne Wert zeigt der Konfigurator an Halbautomaten «Abklärung durch Armatum». Gesamtlänge eingeklappt = Länge ohne Schaft (Waffe) + Länge eingeklappt; unter 60 cm an Halbautomaten = kantonale Ausnahmebewilligung. Laser: Lampen mit Laser brauchen eine kantonale Ausnahmebewilligung. Magazin +Schuss: Magazinverlängerung (zählt zur Kapazität der Waffe).</span>
			</p>
			<p class="ak-row ak-only-zubehoer">
				<label>Hinweis für Kunden (z.B. «Bewilligungspflichtig»)
					<input type="text" name="ak[hinweis]" value="<?php echo esc_attr( $v['hinweis'] ); ?>" size="40">
				</label>
				<label><input type="checkbox" name="ak[freigestellt]" value="yes" <?php checked( $v['freigestellt'], 'yes' ); ?>> Bild ist freigestellt (PNG ohne Hintergrund, verdeckt Originalteil)</label>
			</p>
			<p class="ak-row ak-only-zubehoer ak-only-montage">
				<label>Ausgang (Mündungsaufsatz und HUB: z.B. HUB-Standard 1.375x24; Adapterschiene: picatinny)
					<input type="text" name="ak[ausgang]" class="ak-if" data-kind="auto-out" value="<?php echo esc_attr( $v['ausgang'] ); ?>" size="22" placeholder="<?php echo esc_attr( $out_hint ); ?>">
				</label>
				<span class="description">Bei Mündungsaufsatz und HUB zusätzlich «Ausgangspunkt» (vorderes Ende) setzen. Adapterschiene: Schnittstelle = m-lok, Ausgang = picatinny, Ankerpunkt = Auflage am Handschutz, Ausgangspunkt = Oberfläche der Schiene.</span>
			</p>
			<div class="ak-slots ak-only-waffe">
				<strong>Steckplätze</strong> <span class="description">Schnittstellen (z.B. m-lok, picatinny, 1/2x28, ar15-buffer, ar15-griff) und Punkt im Bild. Leer = Steckplatz nicht verwendet.</span>
				<?php foreach ( AK_Data::SLOTS as $sk => $slabel ) : ?>
					<p class="ak-row">
						<label><?php echo esc_html( $slabel ); ?>
							<input type="text" name="ak[slot_<?php echo esc_attr( $sk ); ?>_if]" class="ak-if" data-kind="<?php echo esc_attr( array( 'muendung' => 'gewinde', 'unten' => 'schiene', 'seite' => 'schiene', 'vorne' => 'schiene', 'schaft' => 'schaft', 'griff' => 'griff' )[ $sk ] ); ?>" value="<?php echo esc_attr( $v[ 'slot_' . $sk . '_if' ] ); ?>" size="22" placeholder="<?php echo esc_attr( 'muendung' === $sk ? $hint( AK_Data::thread_attribute( $post->ID ) ) : '' ); ?>">
						</label>
						<button type="button" class="button ak-tool" data-target="slot_<?php echo esc_attr( $sk ); ?>_anchor">Punkt setzen</button>
						<input type="hidden" name="ak[slot_<?php echo esc_attr( $sk ); ?>_anchor]" class="ak-pt" data-pt="slot_<?php echo esc_attr( $sk ); ?>_anchor" value="<?php echo esc_attr( $v[ 'slot_' . $sk . '_anchor' ] ); ?>">
					</p>
				<?php endforeach; ?>
				<p class="ak-row">
					<span>Obere Schiene (für Rotpunkt + Magnifier):</span>
					<button type="button" class="button ak-tool" data-target="rail_rear">Hinteres Ende setzen</button>
					<button type="button" class="button ak-tool" data-target="rail_front">Vorderes Ende setzen</button>
					<input type="hidden" name="ak[rail_rear]" class="ak-pt" data-pt="rail_rear" value="<?php echo esc_attr( $v['rail_rear'] ); ?>">
					<input type="hidden" name="ak[rail_front]" class="ak-pt" data-pt="rail_front" value="<?php echo esc_attr( $v['rail_front'] ); ?>">
				</p>
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
				<p><b>Magnifier:</b> wie ein Rotpunkt (mit Halterung: «direkt», Ankerpunkt = Mitte Unterseite) oder wie eine Optik mit Mittelrohr (braucht Montage der Art «nur Magnifier»). Mit Magnifier rückt der Rotpunkt nach vorne, das Paar sitzt um den Ankerpunkt der Waffe und bleibt zwischen den Schienenenden.</p>
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
			<p class="ak-status"><?php echo esc_html( AK_Data::is_ready( $post->ID ) ? '✔ Eingerichtet' : 'Noch nicht vollständig eingerichtet. Es fehlt: ' . implode( ', ', AK_Data::missing( $post->ID ) ) ); ?>
				<?php if ( 'montage' === $v['role'] && AK_Data::axis_height( $post->ID ) > 0 ) : ?>
					· Achshöhe über Schiene: <?php echo esc_html( number_format( AK_Data::axis_height( $post->ID ), 1, '.', '' ) ); ?> mm<?php echo AK_Data::own_axis( $post->ID ) > 0 ? ' (von Hand)' : ' (gemessen)'; ?>
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
			if ( 'montage_art' === $f && ! isset( AK_Data::MOUNT_TYPES[ $val ] ) ) {
				$val = '';
			}
			if ( 'waffenart' === $f && ! isset( AK_Data::WEAPON_TYPES[ $val ] ) && ! isset( AK_Data::PART_WEAPON_TYPES[ $val ] ) ) {
				$val = '';
			}
			if ( 'funktion' === $f && ! isset( AK_Erwerb::FUNKTIONEN[ $val ] ) ) {
				$val = '';
			}
			if ( 'schaft_art' === $f && ! isset( AK_Erwerb::SCHAFT_ARTEN[ $val ] ) ) {
				$val = '';
			}
			if ( in_array( $f, array( 'brace', 'laser' ), true ) && ! in_array( $val, array( 'yes', 'no' ), true ) ) {
				$val = '';
			}
			if ( 'eingeklappt_funktion' === $f && 'nein' !== $val ) {
				$val = '';
			}
			if ( in_array( $f, array( 'laenge_ohne_schaft', 'magazin', 'laenge_eingeklappt', 'magazin_plus' ), true ) ) {
				$val = preg_match( '/\d+([.,]\d+)?/', $val, $num ) ? str_replace( ',', '.', $num[0] ) : '';
			}
			if ( 'plattform' === $f ) {
				$val = implode( ', ', array_intersect( array_map( 'trim', explode( ',', strtolower( $val ) ) ), array_keys( AK_Data::PLATFORMS ) ) );
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
