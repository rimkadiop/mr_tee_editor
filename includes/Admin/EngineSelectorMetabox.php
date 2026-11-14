<?php
/**
 * Mr. Tee Editor - Engine Selector & Product Configuration Metabox
 *
 * Implements the WooCommerce product-level engine competitor selector:
 * - None (Standard WooCommerce Product)
 * - NBDesigner (Native NBDesigner Canvas & Toolbars)
 * - Mr. Tee Editor (Live In-Page Form & SVG Preview Editor with 2-Row Mushroom Scene-Graph)
 *
 * @package MrTeeEditor\Admin
 */

namespace MrTeeEditor\Admin;

use MrTeeEditor\Domain\SceneGraphSchema;
use MrTeeEditor\Registry\FontRegistry;
use MrTeeEditor\Registry\ColorPaletteRegistry;
use MrTeeEditor\Registry\IconSetRegistry;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class EngineSelectorMetabox {

	/**
	 * Register hooks.
	 */
	public static function init() {
		add_action( 'add_meta_boxes', array( __CLASS__, 'add_meta_boxes' ) );
		add_action( 'save_post_product', array( __CLASS__, 'save_meta_box' ), 20, 2 );
	}

	/**
	 * Add the Mr. Tee Editor metabox to the product editing screen.
	 */
	public static function add_meta_boxes() {
		add_meta_box(
			'mr_tee_editor_config',
			__( 'M. TEE DESIGNER — Moteur & Configuration du Produit', 'mr-tee-editor' ),
			array( __CLASS__, 'render_metabox' ),
			'product',
			'normal',
			'high'
		);
	}

	/**
	 * Render the metabox HTML.
	 *
	 * @param \WP_Post $post Current post object.
	 */
	public static function render_metabox( $post ) {
		wp_nonce_field( 'mr_tee_save_metabox', 'mr_tee_nonce' );

		$config     = SceneGraphSchema::get_product_config( $post->ID );
		$engine     = $config['engine'];
		$fonts      = FontRegistry::get_fonts();
		$color_sets = ColorPaletteRegistry::get_color_sets();
		$icons      = IconSetRegistry::get_mushroom_icons();
		$thumb_id   = get_post_thumbnail_id( $post->ID );
		$thumb_url  = $thumb_id ? wp_get_attachment_image_url( $thumb_id, 'large' ) : wc_placeholder_img_src();

		?>
		<div class="mr-tee-admin-wrap" data-product-id="<?php echo esc_attr( $post->ID ); ?>" data-thumb-url="<?php echo esc_url( $thumb_url ); ?>">
			<!-- Engine Mode Selector Tabs -->
			<div class="mr-tee-engine-selector-box">
				<h3><?php esc_html_e( 'Moteur de Personnalisation Actif', 'mr-tee-editor' ); ?></h3>
				<p class="description">
					<?php esc_html_e( 'Choisissez quel moteur gère la personnalisation pour ce produit WooCommerce. Vous pouvez basculer entre le personnalisateur standard, NBDesigner natif, ou Mr. Tee Editor.', 'mr-tee-editor' ); ?>
				</p>
				<div class="mr-tee-engine-options">
					<label class="mr-tee-engine-option <?php echo 'none' === $engine ? 'is-selected' : ''; ?>">
						<input type="radio" name="mr_tee_engine_mode" value="none" <?php checked( $engine, 'none' ); ?> />
						<div class="mr-tee-engine-card">
							<span class="dashicons dashicons-products"></span>
							<strong><?php esc_html_e( 'Aucun (Standard)', 'mr-tee-editor' ); ?></strong>
							<span><?php esc_html_e( 'Produit WooCommerce simple sans personnalisateur', 'mr-tee-editor' ); ?></span>
						</div>
					</label>

					<label class="mr-tee-engine-option <?php echo 'nbdesigner' === $engine ? 'is-selected' : ''; ?>">
						<input type="radio" name="mr_tee_engine_mode" value="nbdesigner" <?php checked( $engine, 'nbdesigner' ); ?> />
						<div class="mr-tee-engine-card">
							<span class="dashicons dashicons-art"></span>
							<strong><?php esc_html_e( 'NBDesigner (Natif)', 'mr-tee-editor' ); ?></strong>
							<span><?php esc_html_e( 'Éditeur studio NBDesigner natif avec ses barres d\'outils', 'mr-tee-editor' ); ?></span>
						</div>
					</label>

					<label class="mr-tee-engine-option <?php echo 'mr_tee_editor' === $engine ? 'is-selected' : ''; ?>">
						<input type="radio" name="mr_tee_engine_mode" value="mr_tee_editor" <?php checked( $engine, 'mr_tee_editor' ); ?> />
						<div class="mr-tee-engine-card">
							<span class="dashicons dashicons-superhero"></span>
							<strong><?php esc_html_e( 'Mr. Tee Editor (Monsieur TSHIRT Style)', 'mr-tee-editor' ); ?></strong>
							<span><?php esc_html_e( 'Aperçu live SVG dans la fiche produit + formulaire 2 rangées de champignons', 'mr-tee-editor' ); ?></span>
						</div>
					</label>
				</div>
			</div>

			<!-- Mr. Tee Editor Configuration Settings (Visible when mr_tee_editor is chosen) -->
			<div id="mr-tee-settings-panel" class="mr-tee-settings-panel" style="<?php echo 'mr_tee_editor' === $engine ? '' : 'display:none;'; ?>">
				<div class="mr-tee-preset-banner" style="background:#f8fafc; border:1px solid #e2e8f0; border-radius:10px; padding:12px 18px; margin: 15px 0 20px; display:flex; align-items:center; justify-content:space-between; gap:16px;">
					<div>
						<strong style="color:#0f172a; font-size:14px;"><?php esc_html_e( 'Modèle / Style de Personnalisation Monsieur TSHIRT :', 'mr-tee-editor' ); ?></strong>
						<p class="description" style="margin:2px 0 0;"><?php esc_html_e( 'Sélectionnez un style issu du rétro-engineering de Monsieur TSHIRT (T-Shirt, Casquette brodée, Sac léopard, Mug vintage).', 'mr-tee-editor' ); ?></p>
					</div>
					<div>
						<select name="mr_tee_config[template_preset]" id="mr_tee_template_preset" style="min-width:280px; font-weight:600; padding:6px 10px; border-radius:6px;">
							<?php foreach ( SceneGraphSchema::get_presets() as $p_id => $preset ) : ?>
								<option value="<?php echo esc_attr( $p_id ); ?>" <?php selected( isset( $config['template_preset'] ) ? $config['template_preset'] : 'super_mushroom_24987', $p_id ); ?>>
									<?php echo esc_html( $preset['name'] ); ?>
								</option>
							<?php endforeach; ?>
						</select>
					</div>
				</div>

				<div class="mr-tee-tabs-nav">
					<button type="button" class="mr-tee-tab-btn is-active" data-tab="tab-stage"><?php esc_html_e( 'Zone d\'Impression & Placement Perso', 'mr-tee-editor' ); ?></button>
					<button type="button" class="mr-tee-tab-btn" data-tab="tab-texts"><?php esc_html_e( 'Lignes de Texte', 'mr-tee-editor' ); ?></button>
					<button type="button" class="mr-tee-tab-btn" data-tab="tab-repeater"><?php esc_html_e( 'Grille 2 Rangées (Champignons)', 'mr-tee-editor' ); ?></button>
					<button type="button" class="mr-tee-tab-btn" data-tab="tab-export"><?php esc_html_e( 'Export 300 DPI & NBDesigner', 'mr-tee-editor' ); ?></button>
				</div>

				<!-- TAB 1: Visual Stage & PersoPlacement -->
				<div id="tab-stage" class="mr-tee-tab-pane is-active">
					<?php VisualPlacementStage::render_stage( $config, $thumb_url ); ?>
				</div>

				<!-- TAB 2: OpenType-SVG Font Library & Text Lines Manager -->
				<div id="tab-texts" class="mr-tee-tab-pane">
					<div style="background:#ffffff; border:1px solid #cbd5e1; border-radius:10px; padding:20px; margin-bottom:20px;">
						<div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:15px; border-bottom:1px solid #e2e8f0; padding-bottom:12px;">
							<div>
								<h3 style="margin:0 0 4px; color:#0f172a; display:flex; align-items:center; gap:8px;">
									<span>✨</span> <?php esc_html_e( 'Bibliothèque de Polices OpenType-SVG (Vectorielles & Multi-Couleurs)', 'mr-tee-editor' ); ?>
								</h3>
								<p class="description" style="margin:0;">
									<?php esc_html_e( 'Mr. Tee Editor extrait les tables SVG intégrées des polices couleur pour un rendu vectoriel 100% fidèle sur tous les navigateurs (Chrome, Safari, Firefox, Edge, Mobile) et un export 300 DPI sans perte.', 'mr-tee-editor' ); ?>
								</p>
							</div>
							<button type="button" class="button button-primary" onclick="jQuery('.mr-tee-tab-btn[data-tab=\'tab-stage\']').trigger('click');">
								<?php esc_html_e( '→ Configurer dans le Placement Visuel', 'mr-tee-editor' ); ?>
							</button>
						</div>

						<!-- OpenType-SVG Upload Zone -->
						<div class="mr-tee-font-upload-card" style="background:#f8fafc; border:2px dashed #94a3b8; border-radius:8px; padding:20px; text-align:center; margin-bottom:20px;">
							<span class="dashicons dashicons-cloud-upload" style="font-size:36px; width:36px; height:36px; color:#3b82f6; margin-bottom:8px;"></span>
							<h4 style="margin:0 0 6px; font-size:15px; color:#1e293b;"><?php esc_html_e( 'Importer une Nouvelle Police OpenType-SVG', 'mr-tee-editor' ); ?></h4>
							<p class="description" style="max-width:550px; margin:0 auto 12px; font-size:12px;">
								<?php esc_html_e( 'Glissez-déposez ou sélectionnez un fichier .ttf, .otf ou une archive .zip (ex : Pizzeria, Partyboy, Brainzoo, Purple Magic, Use Your Imagination). L\'analyseur PHP extrait immédiatement la table SVG et crée la bibliothèque de glyphes.', 'mr-tee-editor' ); ?>
							</p>
							<div style="display:inline-flex; align-items:center; gap:10px;">
								<input type="file" id="mr_tee_tab2_font_input" accept=".ttf,.otf,.zip" style="display:none;" />
								<button type="button" class="button button-primary" id="mr_tee_tab2_btn_select">
									📤 <?php esc_html_e( 'Choisir un fichier (.ttf, .otf, .zip)', 'mr-tee-editor' ); ?>
								</button>
								<span id="mr_tee_tab2_file_name" style="font-size:12px; font-weight:600; color:#334155;"></span>
								<button type="button" class="button button-secondary" id="mr_tee_tab2_btn_upload" style="display:none;">
									⚡ <?php esc_html_e( 'Analyser et Enregistrer', 'mr-tee-editor' ); ?>
								</button>
							</div>
							<div id="mr_tee_tab2_upload_status" style="margin-top:10px; font-size:12px; font-weight:600;"></div>
						</div>

						<!-- Registered OpenType-SVG Fonts Grid -->
						<h4 style="margin:0 0 10px; color:#334155; font-size:13px; text-transform:uppercase; letter-spacing:0.05em;">
							<?php esc_html_e( 'Polices OpenType-SVG Actuellement Enregistrées :', 'mr-tee-editor' ); ?>
						</h4>
						<div class="mr-tee-registered-svg-fonts-grid" style="display:grid; grid-template-columns: repeat(auto-fill, minmax(260px, 1fr)); gap:12px;">
							<?php foreach ( $fonts as $f_id => $f_data ) : ?>
								<?php if ( ! empty( $f_data['font_type'] ) && 'opentype_svg' === $f_data['font_type'] ) : ?>
									<div class="mr-tee-svg-font-card" data-font-id="<?php echo esc_attr( $f_id ); ?>" style="background:#f1f5f9; border:1px solid #e2e8f0; border-radius:6px; padding:10px 14px; display:flex; align-items:center; justify-content:space-between;">
										<div>
											<strong style="color:#0f172a; font-size:13px; display:block;"><?php echo esc_html( $f_data['name'] ); ?></strong>
											<span style="font-size:11px; color:#64748b; font-family:monospace;"><?php echo esc_html( $f_data['file'] ); ?></span>
										</div>
										<span style="background:#dbeafe; color:#1d4ed8; font-size:10px; font-weight:700; padding:3px 7px; border-radius:12px; text-transform:uppercase;">
											SVG COLOR
										</span>
									</div>
								<?php endif; ?>
							<?php endforeach; ?>
						</div>
					</div>
				</div>

				<!-- TAB 3: Repeater Grid (2-Row Mushroom Scene-Graph) -->
				<div id="tab-repeater" class="mr-tee-tab-pane">
					<h4><?php esc_html_e( 'Grille 2 Rangées de Champignons (Ligne 1: 1–3, Ligne 2: 4–6)', 'mr-tee-editor' ); ?></h4>
					<p class="description">
						<?php esc_html_e( 'Monsieur TSHIRT organise les champignons en 2 rangées distinctes (Ligne 1 pour les champignons 1..3 à Y=50.2%, et Ligne 2 pour 4..6 à Y=73.2%) avec centrage automatique et auto-zoom proportionnel.', 'mr-tee-editor' ); ?>
					</p>
					<?php $rep = $config['repeater']; ?>
					<table class="form-table">
						<tr>
							<th><label><?php esc_html_e( 'Activer le groupe répéteur', 'mr-tee-editor' ); ?></label></th>
							<td>
								<input type="checkbox" name="mr_tee_config[repeater][enabled]" value="1" <?php checked( ! empty( $rep['enabled'] ) ); ?> />
							</td>
						</tr>
						<tr>
							<th><label><?php esc_html_e( 'Titre du sélecteur', 'mr-tee-editor' ); ?></label></th>
							<td><input type="text" name="mr_tee_config[repeater][label]" value="<?php echo esc_attr( $rep['label'] ); ?>" class="regular-text" /></td>
						</tr>
						<tr>
							<th><label><?php esc_html_e( 'Nombre par défaut / Max', 'mr-tee-editor' ); ?></label></th>
							<td>
								<label><?php esc_html_e( 'Par défaut :', 'mr-tee-editor' ); ?> <input type="number" name="mr_tee_config[repeater][default_count]" value="<?php echo esc_attr( $rep['default_count'] ); ?>" min="0" max="6" class="small-text" /></label>
								&nbsp;&nbsp;
								<label><?php esc_html_e( 'Maximum :', 'mr-tee-editor' ); ?> <input type="number" name="mr_tee_config[repeater][max_items]" value="<?php echo esc_attr( $rep['max_items'] ); ?>" min="1" max="6" class="small-text" /></label>
							</td>
						</tr>
						<tr>
							<th><label><?php esc_html_e( 'Hauteur Ligne 1 / Ligne 2 (%)', 'mr-tee-editor' ); ?></label></th>
							<td>
								<label><?php esc_html_e( 'Ligne 1 Y :', 'mr-tee-editor' ); ?> <input type="number" step="0.1" name="mr_tee_config[repeater][row1_y_pct]" value="<?php echo esc_attr( $rep['row1_y_pct'] ); ?>" class="small-text" /> %</label>
								&nbsp;&nbsp;
								<label><?php esc_html_e( 'Ligne 2 Y :', 'mr-tee-editor' ); ?> <input type="number" step="0.1" name="mr_tee_config[repeater][row2_y_pct]" value="<?php echo esc_attr( $rep['row2_y_pct'] ); ?>" class="small-text" /> %</label>
							</td>
						</tr>
						<tr>
							<th><label><?php esc_html_e( 'Dimensions des icônes (%)', 'mr-tee-editor' ); ?></label></th>
							<td>
								<label><?php esc_html_e( 'Largeur :', 'mr-tee-editor' ); ?> <input type="number" step="0.1" name="mr_tee_config[repeater][icon_width]" value="<?php echo esc_attr( $rep['icon_width'] ); ?>" class="small-text" /> %</label>
								&nbsp;&nbsp;
								<label><?php esc_html_e( 'Hauteur :', 'mr-tee-editor' ); ?> <input type="number" step="0.1" name="mr_tee_config[repeater][icon_height]" value="<?php echo esc_attr( $rep['icon_height'] ); ?>" class="small-text" /> %</label>
							</td>
						</tr>
					</table>
				</div>

				<!-- TAB 4: Export & NBDesigner Bridge -->
				<div id="tab-export" class="mr-tee-tab-pane">
					<h4><?php esc_html_e( 'Spécifications Physiques d\'Impression & Bridge NBDesigner', 'mr-tee-editor' ); ?></h4>
					<p class="description">
						<?php esc_html_e( 'Définissez les dimensions réelles du produit fini (cm/mm/pouces). Le système calcule automatiquement le ratio d\'affichage et la résolution 300 DPI pour un fichier 100% prêt à l\'impression.', 'mr-tee-editor' ); ?>
					</p>
					<table class="form-table">
						<tr>
							<th><label><?php esc_html_e( 'Type de Produit', 'mr-tee-editor' ); ?></label></th>
							<td>
								<select name="mr_tee_config[product_type]" id="mr_tee_tab4_product_type">
									<option value="garment" <?php selected( isset( $config['product_type'] ) ? $config['product_type'] : 'garment', 'garment' ); ?>><?php esc_html_e( '👕 Vêtement / Textile (Zone sur mockup)', 'mr-tee-editor' ); ?></option>
									<option value="flat_object" <?php selected( isset( $config['product_type'] ) ? $config['product_type'] : 'garment', 'flat_object' ); ?>><?php esc_html_e( '📇 Objet Plat / Surface Complète (Carte, Plaque, Mug, Flyer)', 'mr-tee-editor' ); ?></option>
								</select>
							</td>
						</tr>
						<tr>
							<th><label><?php esc_html_e( 'Dimensions Physiques Réelles', 'mr-tee-editor' ); ?></label></th>
							<td>
								<label><?php esc_html_e( 'Largeur :', 'mr-tee-editor' ); ?> <input type="number" step="0.1" name="mr_tee_config[print_real_width]" id="mr_tee_tab4_real_width" value="<?php echo esc_attr( isset( $config['print_real_width'] ) ? $config['print_real_width'] : 30.0 ); ?>" class="small-text" /></label>
								&nbsp;&nbsp;
								<label><?php esc_html_e( 'Hauteur :', 'mr-tee-editor' ); ?> <input type="number" step="0.1" name="mr_tee_config[print_real_height]" id="mr_tee_tab4_real_height" value="<?php echo esc_attr( isset( $config['print_real_height'] ) ? $config['print_real_height'] : 40.0 ); ?>" class="small-text" /></label>
								&nbsp;&nbsp;
								<label><?php esc_html_e( 'Unité :', 'mr-tee-editor' ); ?>
									<select name="mr_tee_config[print_unit]" id="mr_tee_tab4_print_unit">
										<option value="cm" <?php selected( isset( $config['print_unit'] ) ? $config['print_unit'] : 'cm', 'cm' ); ?>>cm</option>
										<option value="mm" <?php selected( isset( $config['print_unit'] ) ? $config['print_unit'] : 'cm', 'mm' ); ?>>mm</option>
										<option value="in" <?php selected( isset( $config['print_unit'] ) ? $config['print_unit'] : 'cm', 'in' ); ?>>in (pouces)</option>
									</select>
								</label>
							</td>
						</tr>
						<tr>
							<th><label><?php esc_html_e( 'Ratio d\'Affichage Responsive', 'mr-tee-editor' ); ?></label></th>
							<td>
								<label>
									<input type="checkbox" name="mr_tee_config[lock_aspect_ratio]" id="mr_tee_tab4_lock_ratio" value="1" <?php checked( ! empty( $config['lock_aspect_ratio'] ) || ( isset( $config['product_type'] ) && 'flat_object' === $config['product_type'] ) ); ?> />
									<strong><?php esc_html_e( 'Verrouiller le ratio sur mobile, tablette et PC', 'mr-tee-editor' ); ?></strong>
								</label>
								<p class="description"><?php esc_html_e( 'Garantit que l\'image du produit et les rectangles de personnalisation s\'affichent exactement dans les mêmes proportions physiques sur tous les écrans.', 'mr-tee-editor' ); ?></p>
							</td>
						</tr>
						<tr>
							<th><label><?php esc_html_e( 'Résolution d\'impression (300 DPI)', 'mr-tee-editor' ); ?></label></th>
							<td>
								<label><?php esc_html_e( 'DPI :', 'mr-tee-editor' ); ?> <input type="number" name="mr_tee_config[dpi]" id="mr_tee_tab4_dpi" value="<?php echo esc_attr( isset( $config['dpi'] ) ? $config['dpi'] : 300 ); ?>" class="small-text" /></label>
								&nbsp;&nbsp;
								<label><?php esc_html_e( 'Largeur (px) :', 'mr-tee-editor' ); ?> <input type="number" name="mr_tee_config[print_width_px]" id="mr_tee_tab4_width_px" value="<?php echo esc_attr( $config['print_width_px'] ); ?>" class="small-text" /></label>
								&nbsp;&nbsp;
								<label><?php esc_html_e( 'Hauteur (px) :', 'mr-tee-editor' ); ?> <input type="number" name="mr_tee_config[print_height_px]" id="mr_tee_tab4_height_px" value="<?php echo esc_attr( $config['print_height_px'] ); ?>" class="small-text" /></label>
							</td>
						</tr>
						<tr>
							<th><label><?php esc_html_e( 'Bridge NBDesigner', 'mr-tee-editor' ); ?></label></th>
							<td>
								<label>
									<input type="checkbox" name="mr_tee_config[nbd_handshake]" value="1" <?php checked( ! empty( $config['nbd_handshake'] ) ); ?> />
									<?php esc_html_e( 'Générer les artefacts NBDesigner à la commande (frame_0.png, frame_0.svg, config.json, option.json, nbd_data)', 'mr-tee-editor' ); ?>
								</label>
							</td>
						</tr>
					</table>
				</div>
			</div>
		</div>
		<?php
	}

	/**
	 * Save metabox data.
	 *
	 * @param int      $post_id Post ID.
	 * @param \WP_Post $post    Post object.
	 */
	public static function save_meta_box( $post_id, $post ) {
		if ( ! isset( $_POST['mr_tee_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['mr_tee_nonce'] ) ), 'mr_tee_save_metabox' ) ) {
			return;
		}

		if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
			return;
		}

		if ( ! current_user_can( 'edit_product', $post_id ) ) {
			return;
		}

		$engine = isset( $_POST['mr_tee_engine_mode'] ) ? sanitize_text_field( wp_unslash( $_POST['mr_tee_engine_mode'] ) ) : 'none';
		update_post_meta( $post_id, '_mr_tee_engine_mode', $engine );

		if ( isset( $_POST['mr_tee_config'] ) && is_array( $_POST['mr_tee_config'] ) ) {
			$raw_config  = wp_unslash( $_POST['mr_tee_config'] ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
			$saved_meta  = get_post_meta( $post_id, '_mr_tee_config', true );
			$prev_preset = ( is_array( $saved_meta ) && ! empty( $saved_meta['template_preset'] ) ) ? $saved_meta['template_preset'] : '';
			$new_preset  = isset( $raw_config['template_preset'] ) ? sanitize_text_field( $raw_config['template_preset'] ) : 'super_mushroom_24987';

			if ( ! empty( $prev_preset ) && $prev_preset !== $new_preset ) {
				$config = SceneGraphSchema::get_default_config( $new_preset );
			} else {
				$config = SceneGraphSchema::get_product_config( $post_id );
			}

			// Sanitize visual editor area & PersoPlacement
			if ( isset( $raw_config['box_left'] ) ) {
				$config['box_left'] = floatval( $raw_config['box_left'] );
			}
			if ( isset( $raw_config['box_top'] ) ) {
				$config['box_top'] = floatval( $raw_config['box_top'] );
			}
			if ( isset( $raw_config['box_width'] ) ) {
				$config['box_width'] = floatval( $raw_config['box_width'] );
			}
			if ( isset( $raw_config['box_height'] ) ) {
				$config['box_height'] = floatval( $raw_config['box_height'] );
			}
			if ( isset( $raw_config['perso_scale'] ) ) {
				$config['perso_scale'] = floatval( $raw_config['perso_scale'] );
			}
			if ( isset( $raw_config['perso_translate_x'] ) ) {
				$config['perso_translate_x'] = floatval( $raw_config['perso_translate_x'] );
			}
			if ( isset( $raw_config['perso_translate_y'] ) ) {
				$config['perso_translate_y'] = floatval( $raw_config['perso_translate_y'] );
			}
			if ( isset( $raw_config['template_preset'] ) ) {
				$config['template_preset'] = sanitize_text_field( $raw_config['template_preset'] );
			}

			// Sanitize ordered allowed_fonts list chosen by the admin
			if ( isset( $raw_config['allowed_fonts'] ) ) {
				$parsed_allowed = $raw_config['allowed_fonts'];
				if ( is_string( $parsed_allowed ) ) {
					$decoded = json_decode( $parsed_allowed, true );
					if ( is_array( $decoded ) ) {
						$parsed_allowed = $decoded;
					} else {
						$parsed_allowed = array_filter( array_map( 'trim', explode( ',', $parsed_allowed ) ) );
					}
				}
				if ( is_array( $parsed_allowed ) ) {
					$sanitized_allowed = array();
					$available_fonts   = FontRegistry::get_fonts();
					foreach ( $parsed_allowed as $af_id ) {
						$sanitized_id = sanitize_key( $af_id );
						if ( isset( $available_fonts[ $sanitized_id ] ) && ! in_array( $sanitized_id, $sanitized_allowed, true ) ) {
							$sanitized_allowed[] = $sanitized_id;
						}
					}
					$config['allowed_fonts'] = $sanitized_allowed;
				}
			}

			// Sanitize text lines (supports adding/deleting dynamic fields and per-line rectangles)
			if ( isset( $raw_config['text_lines'] ) && is_array( $raw_config['text_lines'] ) ) {
				$sanitized_lines = array();
				$line_index      = 0;
				foreach ( $raw_config['text_lines'] as $idx => $line_data ) {
					$line_num = $line_index + 1;
					$existing = isset( $config['text_lines'][ $idx ] ) ? $config['text_lines'][ $idx ] : array();

					$label        = isset( $line_data['label'] ) ? sanitize_text_field( $line_data['label'] ) : ( isset( $existing['label'] ) ? $existing['label'] : sprintf( __( 'Champ %d', 'mr-tee-editor' ), $line_num ) );
					$default_text = isset( $line_data['default_text'] ) ? sanitize_textarea_field( $line_data['default_text'] ) : ( isset( $existing['default_text'] ) ? $existing['default_text'] : sprintf( __( 'Texte %d', 'mr-tee-editor' ), $line_num ) );
					$font         = isset( $line_data['font'] ) ? sanitize_text_field( $line_data['font'] ) : ( isset( $existing['font'] ) ? $existing['font'] : 'super_mario' );

					// If product-level allowed_fonts is defined, enforce first font as default
					if ( ! empty( $config['allowed_fonts'] ) && is_array( $config['allowed_fonts'] ) ) {
						if ( 0 === $line_index || ! in_array( $font, $config['allowed_fonts'], true ) ) {
							$font = $config['allowed_fonts'][0];
						}
					}
					$font_pick    = isset( $line_data['allow_font_pick'] ) ? ! empty( $line_data['allow_font_pick'] ) : ( isset( $existing['allow_font_pick'] ) ? ! empty( $existing['allow_font_pick'] ) : true );
					$color_mode   = isset( $line_data['color_mode'] ) ? sanitize_text_field( $line_data['color_mode'] ) : ( isset( $existing['color_mode'] ) ? $existing['color_mode'] : 'multi' );
					$warning      = isset( $line_data['warning_unedited'] ) ? ! empty( $line_data['warning_unedited'] ) : ( isset( $existing['warning_unedited'] ) ? ! empty( $existing['warning_unedited'] ) : false );
					$multiline    = ! empty( $line_data['multiline'] );
					$max_chars    = isset( $line_data['max_chars'] ) ? max( 1, intval( $line_data['max_chars'] ) ) : ( isset( $existing['max_chars'] ) ? intval( $existing['max_chars'] ) : 25 );

					// Per-line rectangle dimensions & orientation
					$box_l = isset( $line_data['box_left'] ) ? floatval( $line_data['box_left'] ) : ( isset( $existing['box_left'] ) ? floatval( $existing['box_left'] ) : 30.0 );
					$box_t = isset( $line_data['box_top'] ) ? floatval( $line_data['box_top'] ) : ( isset( $existing['box_top'] ) ? floatval( $existing['box_top'] ) : ( 25.0 + $line_index * 12.0 ) );
					$box_w = isset( $line_data['box_width'] ) ? floatval( $line_data['box_width'] ) : ( isset( $existing['box_width'] ) ? floatval( $existing['box_width'] ) : 40.0 );
					$box_h = isset( $line_data['box_height'] ) ? floatval( $line_data['box_height'] ) : ( isset( $existing['box_height'] ) ? floatval( $existing['box_height'] ) : 8.5 );
					$box_r = isset( $line_data['box_rotate'] ) ? floatval( $line_data['box_rotate'] ) : ( isset( $existing['box_rotate'] ) ? floatval( $existing['box_rotate'] ) : 0.0 );

					$font_size = isset( $line_data['font_size'] ) ? floatval( $line_data['font_size'] ) : ( isset( $existing['font_size'] ) ? floatval( $existing['font_size'] ) : ( $box_h * 0.85 ) );
					$y_pct     = isset( $line_data['y_pct'] ) ? floatval( $line_data['y_pct'] ) : ( $box_t + ( $box_h / 2.0 ) );

					$sanitized_line = wp_parse_args(
						array(
							'id'               => ! empty( $existing['id'] ) ? $existing['id'] : 'line_' . $line_num,
							'ref_id'           => ! empty( $existing['ref_id'] ) ? $existing['ref_id'] : 'id-ref-text-line-' . $line_num,
							'color_ref_id'     => ! empty( $existing['color_ref_id'] ) ? $existing['color_ref_id'] : 'id-ref-color-line-' . $line_num,
							'label'            => $label,
							'default_text'     => $default_text,
							'font'             => $font,
							'allowed_fonts'    => ! empty( $config['allowed_fonts'] ) ? $config['allowed_fonts'] : ( isset( $existing['allowed_fonts'] ) ? $existing['allowed_fonts'] : array_keys( FontRegistry::get_fonts() ) ),
							'allow_font_pick'  => $font_pick,
							'color_mode'       => $color_mode,
							'multiline'        => $multiline,
							'max_chars'        => $max_chars,
							'box_left'         => $box_l,
							'box_top'          => $box_t,
							'box_width'        => $box_w,
							'box_height'       => $box_h,
							'box_rotate'       => $box_r,
							'y_pct'            => $y_pct,
							'font_size'        => $font_size,
							'warning_unedited' => $warning,
						),
						$existing
					);

					$sanitized_lines[] = $sanitized_line;
					$line_index++;
				}

				if ( ! empty( $sanitized_lines ) ) {
					$config['text_lines'] = array_values( $sanitized_lines );
				}
			}

			// Sanitize repeater
			if ( isset( $raw_config['repeater'] ) && is_array( $raw_config['repeater'] ) ) {
				$r = $raw_config['repeater'];
				$config['repeater']['enabled']       = ! empty( $r['enabled'] );
				if ( isset( $r['label'] ) ) {
					$config['repeater']['label'] = sanitize_text_field( $r['label'] );
				}
				if ( isset( $r['default_count'] ) ) {
					$config['repeater']['default_count'] = intval( $r['default_count'] );
				}
				if ( isset( $r['max_items'] ) ) {
					$config['repeater']['max_items'] = intval( $r['max_items'] );
				}
				if ( isset( $r['row1_y_pct'] ) ) {
					$config['repeater']['row1_y_pct'] = floatval( $r['row1_y_pct'] );
				}
				if ( isset( $r['row2_y_pct'] ) ) {
					$config['repeater']['row2_y_pct'] = floatval( $r['row2_y_pct'] );
				}
				if ( isset( $r['icon_width'] ) ) {
					$config['repeater']['icon_width'] = floatval( $r['icon_width'] );
				}
				if ( isset( $r['icon_height'] ) ) {
					$config['repeater']['icon_height'] = floatval( $r['icon_height'] );
				}
			}

			// Physical print dimensions & responsive aspect ratio settings
			$config['nbd_handshake'] = ! empty( $raw_config['nbd_handshake'] );
			if ( isset( $raw_config['product_type'] ) ) {
				$config['product_type'] = sanitize_key( $raw_config['product_type'] );
			}
			if ( isset( $raw_config['print_unit'] ) ) {
				$config['print_unit'] = sanitize_key( $raw_config['print_unit'] );
			}
			if ( isset( $raw_config['print_real_width'] ) ) {
				$config['print_real_width'] = max( 0.1, floatval( $raw_config['print_real_width'] ) );
			}
			if ( isset( $raw_config['print_real_height'] ) ) {
				$config['print_real_height'] = max( 0.1, floatval( $raw_config['print_real_height'] ) );
			}
			if ( isset( $raw_config['dpi'] ) ) {
				$config['dpi'] = max( 72, intval( $raw_config['dpi'] ) );
			}
			$config['lock_aspect_ratio'] = ! empty( $raw_config['lock_aspect_ratio'] ) || ( isset( $config['product_type'] ) && 'flat_object' === $config['product_type'] );

			// Compute print dimensions and aspect ratio
			$unit   = ! empty( $config['print_unit'] ) ? $config['print_unit'] : 'cm';
			$real_w = isset( $config['print_real_width'] ) ? $config['print_real_width'] : 30.0;
			$real_h = isset( $config['print_real_height'] ) ? $config['print_real_height'] : 40.0;
			$dpi    = isset( $config['dpi'] ) ? $config['dpi'] : 300;

			$computed_dims = SceneGraphSchema::compute_print_dimensions( $real_w, $real_h, $unit, $dpi );
			$config['print_aspect_ratio'] = $computed_dims['aspect_ratio'];
			$config['print_ratio_str']    = $computed_dims['ratio_str'];

			if ( isset( $raw_config['print_width_px'] ) && intval( $raw_config['print_width_px'] ) > 0 ) {
				$config['print_width_px'] = intval( $raw_config['print_width_px'] );
			} else {
				$config['print_width_px'] = $computed_dims['width_px'];
			}

			if ( isset( $raw_config['print_height_px'] ) && intval( $raw_config['print_height_px'] ) > 0 ) {
				$config['print_height_px'] = intval( $raw_config['print_height_px'] );
			} else {
				$config['print_height_px'] = $computed_dims['height_px'];
			}

			$config['engine'] = $engine;
			update_post_meta( $post_id, '_mr_tee_config', $config );
		}
	}
}
