<?php
/**
 * Mr. Tee Editor - Visual Placement Stage Component (Admin)
 *
 * Renders the interactive garment mock stage where the merchant can
 * visually position and resize the Editor Area (`box_left`, `box_top`,
 * `box_width`, `box_height`) and calibrate the exact `PersoPlacement`
 * coordinates (`perso_scale`, `perso_translate_x`, `perso_translate_y`).
 *
 * @package MrTeeEditor\Admin
 */

namespace MrTeeEditor\Admin;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class VisualPlacementStage {

	/**
	 * Render the visual placement stage HTML.
	 *
	 * @param array<string, mixed> $config    Product configuration.
	 * @param string               $thumb_url Product thumbnail URL.
	 */
	public static function render_stage( array $config, $thumb_url ) {
		$left   = floatval( $config['box_left'] );
		$top    = floatval( $config['box_top'] );
		$width  = floatval( $config['box_width'] );
		$height = floatval( $config['box_height'] );
		$scale  = floatval( $config['perso_scale'] );
		$tx     = floatval( $config['perso_translate_x'] );
		$ty     = floatval( $config['perso_translate_y'] );
		?>
		<div class="mr-tee-stage-container">
			<div class="mr-tee-stage-col-preview">
				<div class="mr-tee-stage-canvas-wrap" id="mr-tee-stage-canvas-wrap">
					<img src="<?php echo esc_url( $thumb_url ); ?>" alt="Garment Preview" class="mr-tee-stage-garment-img" id="mr-tee-stage-garment-img" />

					<!-- Interactive Red-Dashed Visual Editor Area Box -->
					<div
						id="mr-tee-editor-box"
						class="mr-tee-editor-box"
						style="left: <?php echo esc_attr( $left ); ?>%; top: <?php echo esc_attr( $top ); ?>%; width: <?php echo esc_attr( $width ); ?>%; height: <?php echo esc_attr( $height ); ?>%;"
					>
						<span class="mr-tee-box-badge"><?php esc_html_e( 'ZONE D\'IMPRESSION (PERSO_PLACEMENT)', 'mr-tee-editor' ); ?></span>
						<div class="mr-tee-box-svg-holder" id="mr-tee-admin-svg-preview">
							<!-- Dynamic SVG rendered by admin JS engine -->
						</div>

						<!-- Resize Handles -->
						<div class="mr-tee-handle mr-tee-handle-nw" data-handle="nw"></div>
						<div class="mr-tee-handle mr-tee-handle-ne" data-handle="ne"></div>
						<div class="mr-tee-handle mr-tee-handle-sw" data-handle="sw"></div>
						<div class="mr-tee-handle mr-tee-handle-se" data-handle="se"></div>
					</div>
				</div>
				<p class="description mr-tee-stage-instructions">
					<?php esc_html_e( 'Glissez pour déplacer la zone sur la poitrine du t-shirt. Tirez sur les poignées d\'angle pour redimensionner. L\'aperçu du design se met à jour en temps réel.', 'mr-tee-editor' ); ?>
				</p>
			</div>

			<div class="mr-tee-stage-col-controls">
				<h4><?php esc_html_e( 'Coordonnées de Placement (0..100%)', 'mr-tee-editor' ); ?></h4>
				<table class="form-table mr-tee-coords-table">
					<tr>
						<th><label for="mr_tee_box_left"><?php esc_html_e( 'Gauche (Left %)', 'mr-tee-editor' ); ?></label></th>
						<td>
							<input type="number" step="0.1" min="0" max="100" id="mr_tee_box_left" name="mr_tee_config[box_left]" value="<?php echo esc_attr( $left ); ?>" class="small-text mr-tee-coord-input" /> %
						</td>
					</tr>
					<tr>
						<th><label for="mr_tee_box_top"><?php esc_html_e( 'Haut (Top %)', 'mr-tee-editor' ); ?></label></th>
						<td>
							<input type="number" step="0.1" min="0" max="100" id="mr_tee_box_top" name="mr_tee_config[box_top]" value="<?php echo esc_attr( $top ); ?>" class="small-text mr-tee-coord-input" /> %
						</td>
					</tr>
					<tr>
						<th><label for="mr_tee_box_width"><?php esc_html_e( 'Largeur (Width %)', 'mr-tee-editor' ); ?></label></th>
						<td>
							<input type="number" step="0.1" min="5" max="100" id="mr_tee_box_width" name="mr_tee_config[box_width]" value="<?php echo esc_attr( $width ); ?>" class="small-text mr-tee-coord-input" /> %
						</td>
					</tr>
					<tr>
						<th><label for="mr_tee_box_height"><?php esc_html_e( 'Hauteur (Height %)', 'mr-tee-editor' ); ?></label></th>
						<td>
							<input type="number" step="0.1" min="5" max="100" id="mr_tee_box_height" name="mr_tee_config[box_height]" value="<?php echo esc_attr( $height ); ?>" class="small-text mr-tee-coord-input" /> %
						</td>
					</tr>
					<tr>
						<th><label for="mr_tee_perso_scale"><?php esc_html_e( 'Perso Scale', 'mr-tee-editor' ); ?></label></th>
						<td>
							<input type="number" step="0.01" min="0.1" max="5.0" id="mr_tee_perso_scale" name="mr_tee_config[perso_scale]" value="<?php echo esc_attr( $scale ); ?>" class="small-text mr-tee-coord-input" />
						</td>
					</tr>
					<tr>
						<th><label for="mr_tee_perso_tx"><?php esc_html_e( 'Perso Translate X', 'mr-tee-editor' ); ?></label></th>
						<td>
							<input type="number" step="0.01" id="mr_tee_perso_tx" name="mr_tee_config[perso_translate_x]" value="<?php echo esc_attr( $tx ); ?>" class="small-text mr-tee-coord-input" />
						</td>
					</tr>
					<tr>
						<th><label for="mr_tee_perso_ty"><?php esc_html_e( 'Perso Translate Y', 'mr-tee-editor' ); ?></label></th>
						<td>
							<input type="number" step="0.01" id="mr_tee_perso_ty" name="mr_tee_config[perso_translate_y]" value="<?php echo esc_attr( $ty ); ?>" class="small-text mr-tee-coord-input" />
						</td>
					</tr>
				</table>

				<div class="mr-tee-preset-actions">
					<button type="button" class="button mr-tee-btn-reset-coords" id="mr-tee-btn-reset-coords">
						<?php esc_html_e( 'Réinitialiser aux valeurs Monsieur TSHIRT (#24987)', 'mr-tee-editor' ); ?>
					</button>
				</div>
			</div>
		</div>
		<?php
	}
}
