<?php
/**
 * Mr. Tee Editor - Visual Placement Stage Component (Admin)
 *
 * Implements the modular multi-rectangle per-field design area system.
 * Allows merchants to visually draw, position, and resize independent design
 * rectangles directly on the product mockup (e.g. 3 stacked rectangles for
 * 3 text lines, or custom rectangles placed anywhere for mugs, cards, plaques).
 *
 * Each rectangle dynamically dictates:
 * - Position (box_left, box_top)
 * - Proportional Text Sizing (derived from box_height)
 * - Auto-Fit Boundaries (strictly constrained by box_width)
 *
 * @package MrTeeEditor\Admin
 */

namespace MrTeeEditor\Admin;

use MrTeeEditor\Domain\SceneGraphSchema;
use MrTeeEditor\Registry\FontRegistry;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class VisualPlacementStage {

	/**
	 * Predefined color accents for distinct field rectangles.
	 *
	 * @var string[]
	 */
	public static $field_colors = array(
		'#2563eb', // Blue
		'#059669', // Emerald
		'#7c3aed', // Purple
		'#d97706', // Amber
		'#db2777', // Rose
		'#0891b2', // Cyan
		'#dc2626', // Red
		'#4f46e5', // Indigo
	);

	/**
	 * Render the multi-rectangle visual placement stage HTML.
	 *
	 * @param array<string, mixed> $config    Product configuration.
	 * @param string               $thumb_url Product thumbnail URL.
	 */
	public static function render_stage( array $config, $thumb_url ) {
		$text_lines = ! empty( $config['text_lines'] ) && is_array( $config['text_lines'] ) ? $config['text_lines'] : array();
		if ( empty( $text_lines ) ) {
			$text_lines = array(
				array(
					'label'        => __( 'Texte 1', 'mr-tee-editor' ),
					'default_text' => 'Super',
					'box_left'     => 30.0,
					'box_top'      => 25.0,
					'box_width'    => 40.0,
					'box_height'   => 8.5,
					'font'         => 'super_mario',
				),
			);
		}

		$fonts       = FontRegistry::get_fonts();
		$unit        = ! empty( $config['print_unit'] ) ? $config['print_unit'] : 'cm';
		$real_w      = isset( $config['print_real_width'] ) ? floatval( $config['print_real_width'] ) : 30.0;
		$real_h      = isset( $config['print_real_height'] ) ? floatval( $config['print_real_height'] ) : 40.0;
		$dpi         = isset( $config['dpi'] ) ? intval( $config['dpi'] ) : 300;
		$prod_type   = ! empty( $config['product_type'] ) ? $config['product_type'] : 'garment';
		$lock_ratio  = isset( $config['lock_aspect_ratio'] ) ? ! empty( $config['lock_aspect_ratio'] ) : ( 'flat_object' === $prod_type );
		$ratio       = isset( $config['print_aspect_ratio'] ) ? floatval( $config['print_aspect_ratio'] ) : ( ( $real_h > 0 ) ? round( $real_w / $real_h, 4 ) : 0.75 );
		$simplified  = SceneGraphSchema::calculate_simplified_ratio( $real_w, $real_h );
		$print_w_px  = isset( $config['print_width_px'] ) ? intval( $config['print_width_px'] ) : 2400;
		$print_h_px  = isset( $config['print_height_px'] ) ? intval( $config['print_height_px'] ) : 3000;
		?>
		<div class="mr-tee-stage-wrapper">
			<!-- Physical Dimensions & Automatic Aspect Ratio Toolbar -->
			<div class="mr-tee-dimension-toolbar" id="mr-tee-dimension-toolbar">
				<div class="mr-tee-dim-inputs-grid">
					<div class="mr-tee-dim-field">
						<label for="mr_tee_product_type"><strong><?php esc_html_e( 'Type de Produit', 'mr-tee-editor' ); ?></strong></label>
						<select id="mr_tee_product_type" name="mr_tee_config[product_type]" class="mr-tee-dim-select">
							<option value="garment" <?php selected( $prod_type, 'garment' ); ?>><?php esc_html_e( '👕 Vêtement / Textile (Zone sur mockup)', 'mr-tee-editor' ); ?></option>
							<option value="flat_object" <?php selected( $prod_type, 'flat_object' ); ?>><?php esc_html_e( '📇 Objet Plat / Surface Complète (Carte, Plaque, Mug, Flyer)', 'mr-tee-editor' ); ?></option>
						</select>
					</div>

					<div class="mr-tee-dim-field mr-tee-dim-field-unit">
						<label for="mr_tee_print_unit"><?php esc_html_e( 'Unité', 'mr-tee-editor' ); ?></label>
						<select id="mr_tee_print_unit" name="mr_tee_config[print_unit]" class="mr-tee-dim-select">
							<option value="cm" <?php selected( $unit, 'cm' ); ?>>cm</option>
							<option value="mm" <?php selected( $unit, 'mm' ); ?>>mm</option>
							<option value="in" <?php selected( $unit, 'in' ); ?>>pouces (in)</option>
						</select>
					</div>

					<div class="mr-tee-dim-field">
						<label for="mr_tee_real_width"><?php esc_html_e( 'Largeur Réelle (W)', 'mr-tee-editor' ); ?></label>
						<input type="number" step="0.1" min="0.5" id="mr_tee_real_width" name="mr_tee_config[print_real_width]" value="<?php echo esc_attr( $real_w ); ?>" class="small-text mr-tee-dim-input" />
					</div>

					<div class="mr-tee-dim-field">
						<label for="mr_tee_real_height"><?php esc_html_e( 'Hauteur Réelle (H)', 'mr-tee-editor' ); ?></label>
						<input type="number" step="0.1" min="0.5" id="mr_tee_real_height" name="mr_tee_config[print_real_height]" value="<?php echo esc_attr( $real_h ); ?>" class="small-text mr-tee-dim-input" />
					</div>

					<div class="mr-tee-dim-field">
						<label for="mr_tee_dpi"><?php esc_html_e( 'DPI', 'mr-tee-editor' ); ?></label>
						<input type="number" min="72" max="1200" id="mr_tee_dpi" name="mr_tee_config[dpi]" value="<?php echo esc_attr( $dpi ); ?>" class="small-text mr-tee-dim-input" />
					</div>

					<div class="mr-tee-dim-field mr-tee-dim-field-lock">
						<label>
							<input type="checkbox" id="mr_tee_lock_aspect_ratio" name="mr_tee_config[lock_aspect_ratio]" value="1" <?php checked( $lock_ratio ); ?> />
							<strong><?php esc_html_e( 'Verrouiller le ratio sur tous les écrans', 'mr-tee-editor' ); ?></strong>
						</label>
						<span class="description" style="display:block; font-size:11px; color:#64748b;"><?php esc_html_e( 'Affiche l\'image dans ce ratio exact sur mobile, tablette et PC', 'mr-tee-editor' ); ?></span>
					</div>
				</div>

				<!-- Quick Dimensions Presets (Matching POD Standards) -->
				<div class="mr-tee-presets-fast-bar">
					<span class="mr-tee-fast-label"><?php esc_html_e( 'Gabarits standard :', 'mr-tee-editor' ); ?></span>
					<button type="button" class="button button-small mr-tee-btn-preset-dim" data-type="flat_object" data-unit="cm" data-w="8.5" data-h="5.5" data-lock="1" data-ratio="17:11" title="<?php esc_attr_e( 'Carte de Visite (8.5 × 5.5 cm | 17:11 | ~1.55:1 Landscape)', 'mr-tee-editor' ); ?>">📇 <?php esc_html_e( 'Carte (8.5 × 5.5 cm | 17:11)', 'mr-tee-editor' ); ?></button>
					<button type="button" class="button button-small mr-tee-btn-preset-dim" data-type="flat_object" data-unit="cm" data-w="15" data-h="10" data-lock="1" data-ratio="3:2" title="<?php esc_attr_e( 'Carte Postale / Photo (15 × 10 cm | 3:2 | 1.50:1 Landscape)', 'mr-tee-editor' ); ?>">🖼️ <?php esc_html_e( 'Photo (15 × 10 cm | 3:2)', 'mr-tee-editor' ); ?></button>
					<button type="button" class="button button-small mr-tee-btn-preset-dim" data-type="flat_object" data-unit="cm" data-w="20" data-h="9.5" data-lock="1" data-ratio="40:19" title="<?php esc_attr_e( 'Mug Wrap Complet (20 × 9.5 cm | 40:19 | ~2.11:1 Panoramique)', 'mr-tee-editor' ); ?>">☕ <?php esc_html_e( 'Mug Wrap (20 × 9.5 cm | 40:19)', 'mr-tee-editor' ); ?></button>
					<button type="button" class="button button-small mr-tee-btn-preset-dim" data-type="flat_object" data-unit="cm" data-w="21" data-h="29.7" data-lock="1" data-ratio="1:1.414" title="<?php esc_attr_e( 'Flyer / Poster A4 (21 × 29.7 cm | 1:1.414 Portrait)', 'mr-tee-editor' ); ?>">📄 <?php esc_html_e( 'Flyer A4 (21 × 29.7 cm)', 'mr-tee-editor' ); ?></button>
					<button type="button" class="button button-small mr-tee-btn-preset-dim" data-type="flat_object" data-unit="cm" data-w="10" data-h="10" data-lock="1" data-ratio="1:1" title="<?php esc_attr_e( 'Sous-verre / Patch Carré (10 × 10 cm | 1:1 Square)', 'mr-tee-editor' ); ?>">🟩 <?php esc_html_e( 'Carré (10 × 10 cm | 1:1)', 'mr-tee-editor' ); ?></button>
					<button type="button" class="button button-small mr-tee-btn-preset-dim" data-type="garment" data-unit="cm" data-w="30" data-h="40" data-lock="0" data-ratio="3:4" title="<?php esc_attr_e( 'Plateau T-Shirt DTG (30 × 40 cm | 3:4 | 0.75:1 Portrait)', 'mr-tee-editor' ); ?>">👕 <?php esc_html_e( 'T-Shirt (30 × 40 cm | 3:4)', 'mr-tee-editor' ); ?></button>
				</div>

				<!-- Live Ratio & 300 DPI Export Badge -->
				<div class="mr-tee-ratio-badge" id="mr-tee-ratio-badge">
					<span class="mr-tee-badge-icon dashicons dashicons-image-crop"></span>
					<span class="mr-tee-badge-ratio-text">
						<strong><?php esc_html_e( 'Ratio Calculé :', 'mr-tee-editor' ); ?></strong>
						<span id="mr-tee-badge-ratio-val"><?php echo esc_html( $simplified . ' (~' . round( $ratio, 3 ) . ' : 1)' ); ?></span>
					</span>
					<span class="mr-tee-badge-divider">|</span>
					<span class="mr-tee-badge-px-text">
						<strong><?php esc_html_e( 'Fichier 300 DPI :', 'mr-tee-editor' ); ?></strong>
						<span id="mr-tee-badge-px-val"><?php echo esc_html( $print_w_px . ' × ' . $print_h_px . ' px' ); ?></span>
					</span>
					<span class="mr-tee-badge-divider">|</span>
					<span class="mr-tee-badge-status-pill"><?php esc_html_e( '100% Print-Ready', 'mr-tee-editor' ); ?></span>
				</div>
			</div>

			<!-- Header Field Pills Bar -->
			<div class="mr-tee-stage-pills-bar" id="mr-tee-stage-pills-bar">
				<div class="mr-tee-pills-scroll" id="mr-tee-pills-list">
					<?php foreach ( $text_lines as $idx => $line ) : ?>
						<?php
						$color = self::$field_colors[ $idx % count( self::$field_colors ) ];
						$bw    = isset( $line['box_width'] ) ? floatval( $line['box_width'] ) : 40.0;
						$bh    = isset( $line['box_height'] ) ? floatval( $line['box_height'] ) : 8.5;
						?>
						<button
							type="button"
							class="mr-tee-field-pill <?php echo 0 === $idx ? 'is-active' : ''; ?>"
							data-field-index="<?php echo esc_attr( $idx ); ?>"
							style="--pill-color: <?php echo esc_attr( $color ); ?>;"
						>
							<span class="mr-tee-pill-badge" style="background: <?php echo esc_attr( $color ); ?>;"><?php echo esc_html( $idx + 1 ); ?></span>
							<span class="mr-tee-pill-label"><?php echo esc_html( $line['label'] ); ?></span>
							<span class="mr-tee-pill-dims"><?php echo esc_html( round( $bw, 1 ) . '×' . round( $bh, 1 ) . '%' ); ?></span>
						</button>
					<?php endforeach; ?>
				</div>
				<button type="button" class="button button-secondary mr-tee-btn-add-field-pill" id="mr-tee-btn-add-field-pill">
					<span class="dashicons dashicons-plus-alt2"></span> <?php esc_html_e( '+ Ajouter un champ', 'mr-tee-editor' ); ?>
				</button>
			</div>

			<div class="mr-tee-stage-container">
				<!-- Stage Canvas Column -->
				<div class="mr-tee-stage-col-preview">
					<div class="mr-tee-stage-canvas-wrap <?php echo ( ! empty( $lock_ratio ) || 'flat_object' === $prod_type ) ? 'is-aspect-locked' : ''; ?>" id="mr-tee-stage-canvas-wrap" style="<?php echo ( ! empty( $lock_ratio ) || 'flat_object' === $prod_type ) ? 'aspect-ratio: ' . esc_attr( $ratio ) . ';' : ''; ?>">
						<img src="<?php echo esc_url( $thumb_url ); ?>" alt="Garment Preview" class="mr-tee-stage-garment-img" id="mr-tee-stage-garment-img" />

						<!-- Multi-Rectangle Design Area Boxes -->
						<div id="mr-tee-boxes-container">
							<?php foreach ( $text_lines as $idx => $line ) : ?>
								<?php
								$color = self::$field_colors[ $idx % count( self::$field_colors ) ];
								$bl    = isset( $line['box_left'] ) ? floatval( $line['box_left'] ) : 30.0;
								$bt    = isset( $line['box_top'] ) ? floatval( $line['box_top'] ) : ( 25.0 + $idx * 12.0 );
								$bw    = isset( $line['box_width'] ) ? floatval( $line['box_width'] ) : 40.0;
								$bh    = isset( $line['box_height'] ) ? floatval( $line['box_height'] ) : 8.5;
								?>
								<div
									id="mr-tee-field-box-<?php echo esc_attr( $idx ); ?>"
									class="mr-tee-field-box <?php echo 0 === $idx ? 'is-active' : ''; ?>"
									data-field-index="<?php echo esc_attr( $idx ); ?>"
									style="left: <?php echo esc_attr( $bl ); ?>%; top: <?php echo esc_attr( $bt ); ?>%; width: <?php echo esc_attr( $bw ); ?>%; height: <?php echo esc_attr( $bh ); ?>%; --field-color: <?php echo esc_attr( $color ); ?>;"
								>
									<span class="mr-tee-box-badge" style="background: <?php echo esc_attr( $color ); ?>;">
										<?php echo esc_html( ( $idx + 1 ) . '. ' . $line['label'] ); ?>
									</span>
									<div class="mr-tee-box-svg-holder" id="mr-tee-box-svg-<?php echo esc_attr( $idx ); ?>">
										<!-- Live SVG rendered by admin JS engine -->
									</div>

									<!-- Interactive Corner Resize Handles -->
									<div class="mr-tee-handle mr-tee-handle-nw" data-handle="nw" style="border-color: <?php echo esc_attr( $color ); ?>;"></div>
									<div class="mr-tee-handle mr-tee-handle-ne" data-handle="ne" style="border-color: <?php echo esc_attr( $color ); ?>;"></div>
									<div class="mr-tee-handle mr-tee-handle-sw" data-handle="sw" style="border-color: <?php echo esc_attr( $color ); ?>;"></div>
									<div class="mr-tee-handle mr-tee-handle-se" data-handle="se" style="border-color: <?php echo esc_attr( $color ); ?>;"></div>
								</div>
							<?php endforeach; ?>
						</div>
					</div>

					<p class="description mr-tee-stage-instructions">
						<?php esc_html_e( 'Cliquez sur un rectangle pour l\'activer. Déplacez-le par glisser-déposer sur le fond et tirez sur les poignées pour modifier sa largeur/hauteur. Le texte s\'adapte automatiquement aux dimensions du rectangle sans déborder.', 'mr-tee-editor' ); ?>
					</p>
				</div>

				<!-- Stage Controls Column -->
				<div class="mr-tee-stage-col-controls">
					<div class="mr-tee-controls-header">
						<h4><?php esc_html_e( 'Configuration du Rectangle Actif', 'mr-tee-editor' ); ?></h4>
					</div>

					<div id="mr-tee-field-panels-container">
						<?php foreach ( $text_lines as $idx => $line ) : ?>
							<?php
							$color = self::$field_colors[ $idx % count( self::$field_colors ) ];
							$bl    = isset( $line['box_left'] ) ? floatval( $line['box_left'] ) : 30.0;
							$bt    = isset( $line['box_top'] ) ? floatval( $line['box_top'] ) : ( 25.0 + $idx * 12.0 );
							$bw    = isset( $line['box_width'] ) ? floatval( $line['box_width'] ) : 40.0;
							$bh    = isset( $line['box_height'] ) ? floatval( $line['box_height'] ) : 8.5;
							?>
							<div
								class="mr-tee-field-panel <?php echo 0 === $idx ? 'is-active' : ''; ?>"
								id="mr-tee-field-panel-<?php echo esc_attr( $idx ); ?>"
								data-field-index="<?php echo esc_attr( $idx ); ?>"
							>
								<div class="mr-tee-panel-badge-row">
									<span class="mr-tee-panel-indicator" style="background: <?php echo esc_attr( $color ); ?>;"></span>
									<strong class="mr-tee-panel-title"><?php echo esc_html( sprintf( __( 'Champ #%d : %s', 'mr-tee-editor' ), $idx + 1, $line['label'] ) ); ?></strong>
								</div>

								<table class="form-table mr-tee-coords-table">
									<tr>
										<th><label><?php esc_html_e( 'Nom du Champ', 'mr-tee-editor' ); ?></label></th>
										<td>
											<input
												type="text"
												name="mr_tee_config[text_lines][<?php echo esc_attr( $idx ); ?>][label]"
												value="<?php echo esc_attr( $line['label'] ); ?>"
												class="regular-text mr-tee-field-label-input"
												data-field-index="<?php echo esc_attr( $idx ); ?>"
											/>
										</td>
									</tr>
									<tr>
										<th><label><?php esc_html_e( 'Texte par Défaut', 'mr-tee-editor' ); ?></label></th>
										<td>
											<input
												type="text"
												name="mr_tee_config[text_lines][<?php echo esc_attr( $idx ); ?>][default_text]"
												value="<?php echo esc_attr( $line['default_text'] ); ?>"
												class="regular-text mr-tee-field-text-input"
												data-field-index="<?php echo esc_attr( $idx ); ?>"
											/>
										</td>
									</tr>
									<tr>
										<th><label><?php esc_html_e( 'Police par Défaut', 'mr-tee-editor' ); ?></label></th>
										<td>
											<select
												name="mr_tee_config[text_lines][<?php echo esc_attr( $idx ); ?>][font]"
												class="mr-tee-field-font-select"
												data-field-index="<?php echo esc_attr( $idx ); ?>"
											>
												<?php foreach ( $fonts as $f_id => $f_data ) : ?>
													<option value="<?php echo esc_attr( $f_id ); ?>" <?php selected( isset( $line['font'] ) ? $line['font'] : 'super_mario', $f_id ); ?>>
														<?php echo esc_html( $f_data['name'] ); ?>
													</option>
												<?php endforeach; ?>
											</select>
										</td>
									</tr>
									<tr>
										<th><label><?php esc_html_e( 'Mode Couleur', 'mr-tee-editor' ); ?></label></th>
										<td>
											<select name="mr_tee_config[text_lines][<?php echo esc_attr( $idx ); ?>][color_mode]" class="mr-tee-field-color-mode">
												<option value="multi" <?php selected( isset( $line['color_mode'] ) ? $line['color_mode'] : 'multi', 'multi' ); ?>><?php esc_html_e( 'Multicolore (COLOR_SET cyclique)', 'mr-tee-editor' ); ?></option>
												<option value="solid" <?php selected( isset( $line['color_mode'] ) ? $line['color_mode'] : 'multi', 'solid' ); ?>><?php esc_html_e( 'Couleur Unie', 'mr-tee-editor' ); ?></option>
											</select>
										</td>
									</tr>
									<tr>
										<th><label><?php esc_html_e( 'Max Caractères', 'mr-tee-editor' ); ?></label></th>
										<td>
											<input
												type="number"
												min="1"
												max="100"
												name="mr_tee_config[text_lines][<?php echo esc_attr( $idx ); ?>][max_chars]"
												value="<?php echo esc_attr( isset( $line['max_chars'] ) ? $line['max_chars'] : 25 ); ?>"
												class="small-text mr-tee-field-max-chars"
											/>
										</td>
									</tr>
									<tr>
										<th><label><?php esc_html_e( 'Options', 'mr-tee-editor' ); ?></label></th>
										<td>
											<label>
												<input
													type="checkbox"
													name="mr_tee_config[text_lines][<?php echo esc_attr( $idx ); ?>][allow_font_pick]"
													value="1"
													class="mr-tee-field-font-pick"
													<?php checked( ! isset( $line['allow_font_pick'] ) || ! empty( $line['allow_font_pick'] ) ); ?>
												/>
												<?php esc_html_e( 'Choix de police client', 'mr-tee-editor' ); ?>
											</label>
											<br />
											<label>
												<input
													type="checkbox"
													name="mr_tee_config[text_lines][<?php echo esc_attr( $idx ); ?>][multiline]"
													value="1"
													class="mr-tee-field-multiline"
													<?php checked( ! empty( $line['multiline'] ) ); ?>
												/>
												<?php esc_html_e( 'Autoriser retours à la ligne', 'mr-tee-editor' ); ?>
											</label>
											<br />
											<label>
												<input
													type="checkbox"
													name="mr_tee_config[text_lines][<?php echo esc_attr( $idx ); ?>][warning_unedited]"
													value="1"
													class="mr-tee-field-warning"
													<?php checked( ! empty( $line['warning_unedited'] ) ); ?>
												/>
												<?php esc_html_e( 'Alerter si non personnalisé', 'mr-tee-editor' ); ?>
											</label>
										</td>
									</tr>
									<tr>
										<th><label><?php esc_html_e( 'Position X (Left %)', 'mr-tee-editor' ); ?></label></th>
										<td>
											<input
												type="number"
												step="0.1"
												min="0"
												max="100"
												id="mr_tee_field_left_<?php echo esc_attr( $idx ); ?>"
												name="mr_tee_config[text_lines][<?php echo esc_attr( $idx ); ?>][box_left]"
												value="<?php echo esc_attr( $bl ); ?>"
												class="small-text mr-tee-coord-input mr-tee-field-coord mr-tee-field-coord-left"
												data-field-index="<?php echo esc_attr( $idx ); ?>"
											/> %
										</td>
									</tr>
									<tr>
										<th><label><?php esc_html_e( 'Position Y (Top %)', 'mr-tee-editor' ); ?></label></th>
										<td>
											<input
												type="number"
												step="0.1"
												min="0"
												max="100"
												id="mr_tee_field_top_<?php echo esc_attr( $idx ); ?>"
												name="mr_tee_config[text_lines][<?php echo esc_attr( $idx ); ?>][box_top]"
												value="<?php echo esc_attr( $bt ); ?>"
												class="small-text mr-tee-coord-input mr-tee-field-coord mr-tee-field-coord-top"
												data-field-index="<?php echo esc_attr( $idx ); ?>"
											/> %
										</td>
									</tr>
									<tr>
										<th><label><?php esc_html_e( 'Largeur (Width %)', 'mr-tee-editor' ); ?></label></th>
										<td>
											<input
												type="number"
												step="0.1"
												min="5"
												max="100"
												id="mr_tee_field_width_<?php echo esc_attr( $idx ); ?>"
												name="mr_tee_config[text_lines][<?php echo esc_attr( $idx ); ?>][box_width]"
												value="<?php echo esc_attr( $bw ); ?>"
												class="small-text mr-tee-coord-input mr-tee-field-coord mr-tee-field-coord-width"
												data-field-index="<?php echo esc_attr( $idx ); ?>"
											/> %
										</td>
									</tr>
									<tr>
										<th><label><?php esc_html_e( 'Hauteur (Height %)', 'mr-tee-editor' ); ?></label></th>
										<td>
											<input
												type="number"
												step="0.1"
												min="3"
												max="100"
												id="mr_tee_field_height_<?php echo esc_attr( $idx ); ?>"
												name="mr_tee_config[text_lines][<?php echo esc_attr( $idx ); ?>][box_height]"
												value="<?php echo esc_attr( $bh ); ?>"
												class="small-text mr-tee-coord-input mr-tee-field-coord mr-tee-field-coord-height"
												data-field-index="<?php echo esc_attr( $idx ); ?>"
											/> %
										</td>
									</tr>
								</table>

								<div class="mr-tee-field-actions">
									<button
										type="button"
										class="button button-link-delete mr-tee-btn-delete-field"
										data-field-index="<?php echo esc_attr( $idx ); ?>"
										<?php echo count( $text_lines ) <= 1 ? 'style="display:none;"' : ''; ?>
									>
										<?php esc_html_e( 'Supprimer ce champ', 'mr-tee-editor' ); ?>
									</button>
								</div>
							</div>
						<?php endforeach; ?>
					</div>

					<div class="mr-tee-add-field-action-wrap">
						<button type="button" class="button button-secondary mr-tee-btn-add-field" id="mr-tee-btn-add-field">
							<span class="dashicons dashicons-plus-alt2"></span> <?php esc_html_e( '+ Ajouter un nouveau rectangle / champ', 'mr-tee-editor' ); ?>
						</button>
					</div>

					<!-- Legacy Fallback Coordinates for older extensions (Hidden / Synchronized) -->
					<input type="hidden" id="mr_tee_box_left" name="mr_tee_config[box_left]" value="<?php echo esc_attr( isset( $config['box_left'] ) ? $config['box_left'] : 26.0 ); ?>" />
					<input type="hidden" id="mr_tee_box_top" name="mr_tee_config[box_top]" value="<?php echo esc_attr( isset( $config['box_top'] ) ? $config['box_top'] : 23.0 ); ?>" />
					<input type="hidden" id="mr_tee_box_width" name="mr_tee_config[box_width]" value="<?php echo esc_attr( isset( $config['box_width'] ) ? $config['box_width'] : 48.0 ); ?>" />
					<input type="hidden" id="mr_tee_box_height" name="mr_tee_config[box_height]" value="<?php echo esc_attr( isset( $config['box_height'] ) ? $config['box_height'] : 54.0 ); ?>" />
					<input type="hidden" id="mr_tee_perso_scale" name="mr_tee_config[perso_scale]" value="<?php echo esc_attr( isset( $config['perso_scale'] ) ? $config['perso_scale'] : 1.0 ); ?>" />
					<input type="hidden" id="mr_tee_perso_tx" name="mr_tee_config[perso_translate_x]" value="<?php echo esc_attr( isset( $config['perso_translate_x'] ) ? $config['perso_translate_x'] : 0.0 ); ?>" />
					<input type="hidden" id="mr_tee_perso_ty" name="mr_tee_config[perso_translate_y]" value="<?php echo esc_attr( isset( $config['perso_translate_y'] ) ? $config['perso_translate_y'] : 0.0 ); ?>" />
				</div>
			</div>
		</div>
		<?php
	}
}
