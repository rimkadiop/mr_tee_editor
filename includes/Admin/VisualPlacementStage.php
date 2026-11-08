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

		$fonts = FontRegistry::get_fonts();
		?>
		<div class="mr-tee-stage-wrapper">
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
					<div class="mr-tee-stage-canvas-wrap" id="mr-tee-stage-canvas-wrap">
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
