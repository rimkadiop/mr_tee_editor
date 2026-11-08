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

				<!-- TAB 2: Text Lines -->
				<div id="tab-texts" class="mr-tee-tab-pane">
					<h4><?php esc_html_e( 'Lignes de Texte Primaires ("Super" + "Prénom")', 'mr-tee-editor' ); ?></h4>
					<p class="description"><?php esc_html_e( 'Configurez les champs texte principaux, leurs polices par défaut, et les liaisons COLOR_SET.', 'mr-tee-editor' ); ?></p>
					<div class="mr-tee-text-lines-grid">
						<?php foreach ( $config['text_lines'] as $idx => $line ) : ?>
							<div class="mr-tee-line-card" data-line-index="<?php echo esc_attr( $idx ); ?>">
								<h5><?php echo esc_html( $line['label'] ); ?></h5>
								<table class="form-table">
									<tr>
										<th><label><?php esc_html_e( 'Libellé', 'mr-tee-editor' ); ?></label></th>
										<td><input type="text" name="mr_tee_config[text_lines][<?php echo esc_attr( $idx ); ?>][label]" value="<?php echo esc_attr( $line['label'] ); ?>" class="regular-text" /></td>
									</tr>
									<tr>
										<th><label><?php esc_html_e( 'Texte par défaut', 'mr-tee-editor' ); ?></label></th>
										<td>
											<?php if ( ! empty( $line['multiline'] ) ) : ?>
												<textarea name="mr_tee_config[text_lines][<?php echo esc_attr( $idx ); ?>][default_text]" rows="3" class="large-text"><?php echo esc_textarea( $line['default_text'] ); ?></textarea>
											<?php else : ?>
												<input type="text" name="mr_tee_config[text_lines][<?php echo esc_attr( $idx ); ?>][default_text]" value="<?php echo esc_attr( $line['default_text'] ); ?>" class="regular-text" />
											<?php endif; ?>
										</td>
									</tr>
									<tr>
										<th><label><?php esc_html_e( 'Police', 'mr-tee-editor' ); ?></label></th>
										<td>
											<select name="mr_tee_config[text_lines][<?php echo esc_attr( $idx ); ?>][font]">
												<?php foreach ( $fonts as $f_id => $f_data ) : ?>
													<option value="<?php echo esc_attr( $f_id ); ?>" <?php selected( $line['font'], $f_id ); ?>>
														<?php echo esc_html( $f_data['name'] ); ?>
													</option>
												<?php endforeach; ?>
											</select>
										</td>
									</tr>
									<tr>
										<th><label><?php esc_html_e( 'Choix de Police Client', 'mr-tee-editor' ); ?></label></th>
										<td>
											<label>
												<input type="checkbox" name="mr_tee_config[text_lines][<?php echo esc_attr( $idx ); ?>][allow_font_pick]" value="1" <?php checked( ! empty( $line['allow_font_pick'] ) ); ?> />
												<?php esc_html_e( 'Afficher les pastilles de sélection de police sur la fiche produit', 'mr-tee-editor' ); ?>
											</label>
										</td>
									</tr>
									<tr>
										<th><label><?php esc_html_e( 'Mode Couleur', 'mr-tee-editor' ); ?></label></th>
										<td>
											<select name="mr_tee_config[text_lines][<?php echo esc_attr( $idx ); ?>][color_mode]">
												<option value="multi" <?php selected( $line['color_mode'], 'multi' ); ?>><?php esc_html_e( 'Multicolore (COLOR_SET cyclique par lettre)', 'mr-tee-editor' ); ?></option>
												<option value="solid" <?php selected( $line['color_mode'], 'solid' ); ?>><?php esc_html_e( 'Couleur Unie', 'mr-tee-editor' ); ?></option>
											</select>
										</td>
									</tr>
									<tr>
										<th><label><?php esc_html_e( 'Position Y (%)', 'mr-tee-editor' ); ?></label></th>
										<td><input type="number" step="0.1" name="mr_tee_config[text_lines][<?php echo esc_attr( $idx ); ?>][y_pct]" value="<?php echo esc_attr( $line['y_pct'] ); ?>" class="small-text" /> %</td>
									</tr>
									<tr>
										<th><label><?php esc_html_e( 'Taille Police (%)', 'mr-tee-editor' ); ?></label></th>
										<td><input type="number" step="0.1" name="mr_tee_config[text_lines][<?php echo esc_attr( $idx ); ?>][font_size]" value="<?php echo esc_attr( $line['font_size'] ); ?>" class="small-text" /> %</td>
									</tr>
									<tr>
										<th><label><?php esc_html_e( 'Avertissement non-édité', 'mr-tee-editor' ); ?></label></th>
										<td>
											<label>
												<input type="checkbox" name="mr_tee_config[text_lines][<?php echo esc_attr( $idx ); ?>][warning_unedited]" value="1" <?php checked( ! empty( $line['warning_unedited'] ) ); ?> />
												<?php esc_html_e( 'Alerter le client si ce champ reste non personnalisé (IGNORE_PERSO_WARNINGS_LABEL)', 'mr-tee-editor' ); ?>
											</label>
										</td>
									</tr>
								</table>
							</div>
						<?php endforeach; ?>
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
					<h4><?php esc_html_e( 'Spécifications d\'Export Haute Définition & Bridge NBDesigner', 'mr-tee-editor' ); ?></h4>
					<p class="description">
						<?php esc_html_e( 'Générez des fichiers prêts pour l\'impression DTG/sérigraphie 300 DPI sans fond, et injectez-les automatiquement dans les 5 artefacts NBDesigner.', 'mr-tee-editor' ); ?>
					</p>
					<table class="form-table">
						<tr>
							<th><label><?php esc_html_e( 'Bridge NBDesigner', 'mr-tee-editor' ); ?></label></th>
							<td>
								<label>
									<input type="checkbox" name="mr_tee_config[nbd_handshake]" value="1" <?php checked( ! empty( $config['nbd_handshake'] ) ); ?> />
									<?php esc_html_e( 'Générer les 5 artefacts NBDesigner à la commande (design_preview.png, design.svg, config.json, print_file_front.png, nbd_data)', 'mr-tee-editor' ); ?>
								</label>
							</td>
						</tr>
						<tr>
							<th><label><?php esc_html_e( 'Résolution d\'impression', 'mr-tee-editor' ); ?></label></th>
							<td>
								<label><?php esc_html_e( 'DPI :', 'mr-tee-editor' ); ?> <input type="number" name="mr_tee_config[dpi]" value="<?php echo esc_attr( $config['dpi'] ); ?>" class="small-text" /></label>
								&nbsp;&nbsp;
								<label><?php esc_html_e( 'Largeur (px) :', 'mr-tee-editor' ); ?> <input type="number" name="mr_tee_config[print_width_px]" value="<?php echo esc_attr( $config['print_width_px'] ); ?>" class="small-text" /></label>
								&nbsp;&nbsp;
								<label><?php esc_html_e( 'Hauteur (px) :', 'mr-tee-editor' ); ?> <input type="number" name="mr_tee_config[print_height_px]" value="<?php echo esc_attr( $config['print_height_px'] ); ?>" class="small-text" /></label>
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

			// Sanitize text lines
			if ( isset( $raw_config['text_lines'] ) && is_array( $raw_config['text_lines'] ) ) {
				foreach ( $raw_config['text_lines'] as $idx => $line_data ) {
					if ( isset( $config['text_lines'][ $idx ] ) ) {
						if ( isset( $line_data['label'] ) ) {
							$config['text_lines'][ $idx ]['label'] = sanitize_text_field( $line_data['label'] );
						}
						if ( isset( $line_data['default_text'] ) ) {
							$config['text_lines'][ $idx ]['default_text'] = sanitize_textarea_field( $line_data['default_text'] );
						}
						if ( isset( $line_data['multiline'] ) ) {
							$config['text_lines'][ $idx ]['multiline'] = ! empty( $line_data['multiline'] );
						}
						if ( isset( $line_data['font'] ) ) {
							$config['text_lines'][ $idx ]['font'] = sanitize_text_field( $line_data['font'] );
						}
						$config['text_lines'][ $idx ]['allow_font_pick'] = ! empty( $line_data['allow_font_pick'] );
						if ( isset( $line_data['color_mode'] ) ) {
							$config['text_lines'][ $idx ]['color_mode'] = sanitize_text_field( $line_data['color_mode'] );
						}
						if ( isset( $line_data['y_pct'] ) ) {
							$config['text_lines'][ $idx ]['y_pct'] = floatval( $line_data['y_pct'] );
						}
						if ( isset( $line_data['font_size'] ) ) {
							$config['text_lines'][ $idx ]['font_size'] = floatval( $line_data['font_size'] );
						}
						$config['text_lines'][ $idx ]['warning_unedited'] = ! empty( $line_data['warning_unedited'] );
					}
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

			// Export settings
			$config['nbd_handshake'] = ! empty( $raw_config['nbd_handshake'] );
			if ( isset( $raw_config['dpi'] ) ) {
				$config['dpi'] = intval( $raw_config['dpi'] );
			}
			if ( isset( $raw_config['print_width_px'] ) ) {
				$config['print_width_px'] = intval( $raw_config['print_width_px'] );
			}
			if ( isset( $raw_config['print_height_px'] ) ) {
				$config['print_height_px'] = intval( $raw_config['print_height_px'] );
			}

			$config['engine'] = $engine;
			update_post_meta( $post_id, '_mr_tee_config', $config );
		}
	}
}
