<?php
/**
 * Mr. Tee Editor - Visual Placement Stage Component (Admin)
 *
 * Implements the modular multi-rectangle per-field design area system.
 * Allows merchants to visually draw, position, and resize independent design
 * rectangles directly on the product mockup (e.g. stacked rectangles for
 * text lines, or custom rectangles placed anywhere for mugs, cards, plaques).
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
		'#047857', // Emerald (WCAG AA)
		'#7c3aed', // Purple
		'#b45309', // Amber (WCAG AA)
		'#db2777', // Rose
		'#0e7490', // Cyan (WCAG AA)
		'#dc2626', // Red
		'#4f46e5', // Indigo
	);

	/**
	 * Resolve the normalized ordered list of allowed font IDs for a product configuration.
	 *
	 * @param array<string, mixed>               $config Product configuration.
	 * @param array<string, array<string, mixed>> $fonts  Registered fonts map.
	 * @return string[]
	 */
	public static function resolve_allowed_fonts( array $config, array $fonts ) {
		$text_lines    = ! empty( $config['text_lines'] ) && is_array( $config['text_lines'] ) ? $config['text_lines'] : array();
		$allowed_fonts = array();

		if ( ! empty( $config['allowed_fonts'] ) && is_array( $config['allowed_fonts'] ) ) {
			$allowed_fonts = $config['allowed_fonts'];
		} else {
			foreach ( $text_lines as $tl ) {
				if ( ( ! isset( $tl['field_type'] ) || 'image' !== $tl['field_type'] ) && ! empty( $tl['allowed_fonts'] ) && is_array( $tl['allowed_fonts'] ) ) {
					$allowed_fonts = $tl['allowed_fonts'];
					break;
				}
			}
		}

		if ( empty( $allowed_fonts ) ) {
			foreach ( $text_lines as $tl ) {
				if ( isset( $tl['field_type'] ) && 'image' === $tl['field_type'] ) {
					continue;
				}
				if ( ! empty( $tl['font'] ) ) {
					$allowed_fonts[] = $tl['font'];
				}
			}
		}

		$allowed_fonts = array_values(
			array_unique(
				array_filter(
					$allowed_fonts,
					function ( $fid ) use ( $fonts ) {
						return isset( $fonts[ $fid ] );
					}
				)
			)
		);

		if ( empty( $allowed_fonts ) ) {
			$first_font_key = array_key_first( $fonts );
			$allowed_fonts  = $first_font_key ? array( $first_font_key ) : array();
		}

		return $allowed_fonts;
	}

	/**
	 * Render the multi-rectangle visual placement stage HTML.
	 *
	 * @param array<string, mixed> $config    Product configuration.
	 * @param string               $thumb_url Product thumbnail URL.
	 */
	public static function render_stage( array $config, $thumb_url ) {
		$thumb_url  = SceneGraphSchema::normalize_url_scheme( $thumb_url );
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

		$fonts         = FontRegistry::get_fonts();
		$allowed_fonts = self::resolve_allowed_fonts( $config, $fonts );

		$unit       = ! empty( $config['print_unit'] ) ? $config['print_unit'] : 'cm';
		$real_w     = isset( $config['print_real_width'] ) ? floatval( $config['print_real_width'] ) : 30.0;
		$real_h     = isset( $config['print_real_height'] ) ? floatval( $config['print_real_height'] ) : 40.0;
		$dpi        = isset( $config['dpi'] ) ? intval( $config['dpi'] ) : 300;
		$prod_type  = ! empty( $config['product_type'] ) ? $config['product_type'] : 'garment';
		$lock_ratio = isset( $config['lock_aspect_ratio'] ) ? ! empty( $config['lock_aspect_ratio'] ) : ( 'flat_object' === $prod_type );

		$print_sides = ( ! empty( $config['print_sides'] ) && is_array( $config['print_sides'] ) )
			? array_values( $config['print_sides'] )
			: SceneGraphSchema::normalize_print_sides(
				array(),
				array(
					'print_real_width'  => $real_w,
					'print_real_height' => $real_h,
					'print_unit'        => $unit,
					'dpi'               => $dpi,
					'product_type'      => $prod_type,
					'lock_aspect_ratio' => $lock_ratio,
				)
			);

		$sides_by_id = array();
		foreach ( $print_sides as $s ) {
			if ( ! empty( $s['id'] ) ) {
				$sides_by_id[ $s['id'] ] = $s;
			}
		}
		$default_side_id = $print_sides[0]['id'];
		$has_back_side   = isset( $sides_by_id['back'] );

		$field_count_by_side = array();
		foreach ( $text_lines as $tl ) {
			$tl_sid = ! empty( $tl['side_id'] ) && isset( $sides_by_id[ $tl['side_id'] ] ) ? $tl['side_id'] : $default_side_id;
			if ( ! isset( $field_count_by_side[ $tl_sid ] ) ) {
				$field_count_by_side[ $tl_sid ] = 0;
			}
			$field_count_by_side[ $tl_sid ]++;
		}

		$preview_views = ( ! empty( $config['preview_views'] ) && is_array( $config['preview_views'] ) )
			? array_values( $config['preview_views'] )
			: SceneGraphSchema::normalize_preview_views( array(), $print_sides, 0, $thumb_url );

		$first_default_side_view_id = '';
		foreach ( $preview_views as $pv_idx => $pv ) {
			$pv_sid = ! empty( $pv['side_id'] ) && isset( $sides_by_id[ $pv['side_id'] ] ) ? $pv['side_id'] : $default_side_id;
			if ( $pv_sid === $default_side_id ) {
				$first_default_side_view_id = ! empty( $pv['id'] ) ? $pv['id'] : 'view_' . ( $pv_idx + 1 );
				break;
			}
		}

		$first_view         = $preview_views[0];
		$first_view_id      = ! empty( $first_view['id'] ) ? $first_view['id'] : 'view_1';
		$first_view_side_id = ! empty( $first_view['side_id'] ) && isset( $sides_by_id[ $first_view['side_id'] ] ) ? $first_view['side_id'] : $default_side_id;
		$first_view_img_raw = ! empty( $first_view['image_url'] )
			? $first_view['image_url']
			: ( $first_view_id === $first_default_side_view_id ? $thumb_url : '' );
		$first_view_img     = SceneGraphSchema::normalize_url_scheme( $first_view_img_raw );
		$first_view_blend   = ! empty( $first_view['blend_mode'] ) ? $first_view['blend_mode'] : 'normal';

		// Use the active view's printable side dimensions for the physical summary bar & drawer
		if ( isset( $sides_by_id[ $first_view_side_id ] ) ) {
			$active_side = $sides_by_id[ $first_view_side_id ];
			$unit        = ! empty( $active_side['print_unit'] ) ? $active_side['print_unit'] : $unit;
			$real_w      = isset( $active_side['print_real_width'] ) ? floatval( $active_side['print_real_width'] ) : $real_w;
			$real_h      = isset( $active_side['print_real_height'] ) ? floatval( $active_side['print_real_height'] ) : $real_h;
			$dpi         = isset( $active_side['dpi'] ) ? intval( $active_side['dpi'] ) : $dpi;
			$prod_type   = ! empty( $active_side['product_type'] ) ? $active_side['product_type'] : $prod_type;
			$lock_ratio  = isset( $active_side['lock_aspect_ratio'] ) ? (bool) $active_side['lock_aspect_ratio'] : $lock_ratio;
		}
		$is_locked = ( ! empty( $lock_ratio ) || 'flat_object' === $prod_type );

		$ratio      = ( $real_h > 0 ) ? round( $real_w / $real_h, 4 ) : 0.75;
		$cumulative = ( ! empty( $config['side_export_metrics'][ $first_view_side_id ] ) && is_array( $config['side_export_metrics'][ $first_view_side_id ] ) )
			? $config['side_export_metrics'][ $first_view_side_id ]
			: SceneGraphSchema::compute_cumulative_design_area( $config );
		$cum_w      = $cumulative['print_real_width'];
		$cum_h      = $cumulative['print_real_height'];
		$cum_w_px   = $cumulative['width_px'];
		$cum_h_px   = $cumulative['height_px'];
		$cum_simple = $cumulative['simplified_ratio'];

		$role_labels = array(
			'full'    => __( 'Complet', 'mr-tee-editor' ),
			'closeup' => __( 'Zoom', 'mr-tee-editor' ),
			'detail'  => __( 'Détail', 'mr-tee-editor' ),
		);

		$initial_active_idx = 0;
		foreach ( $text_lines as $ti => $tl ) {
			$tl_side = ! empty( $tl['side_id'] ) ? $tl['side_id'] : $default_side_id;
			if ( $tl_side === $first_view_side_id ) {
				$initial_active_idx = $ti;
				break;
			}
		}

		$prod_type_short = ( 'flat_object' === $prod_type )
			? __( '📇 Objet Plat', 'mr-tee-editor' )
			: __( '👕 Vêtement', 'mr-tee-editor' );
		?>
		<div class="mr-tee-stage-wrapper">
			<!-- Single-Row Collapsible Format & Dimensions Summary Bar -->
			<div class="mr-tee-dim-summary-bar" id="mr-tee-dim-summary-bar">
				<div class="mr-tee-dim-summary-info">
					<span class="mr-tee-summary-chip" id="mr-tee-summary-type"><?php echo esc_html( $prod_type_short ); ?></span>
					<span class="mr-tee-summary-sep" aria-hidden="true">·</span>
					<strong id="mr-tee-summary-surface"><?php echo esc_html( $real_w . ' × ' . $real_h . ' ' . $unit ); ?></strong>
					<span class="mr-tee-summary-sep" aria-hidden="true">·</span>
					<span id="mr-tee-summary-dpi"><?php echo esc_html( $dpi . ' DPI' ); ?></span>
					<span class="mr-tee-summary-sep" aria-hidden="true">·</span>
					<span class="mr-tee-summary-export">
						<?php esc_html_e( 'Zone export :', 'mr-tee-editor' ); ?>
						<strong id="mr-tee-summary-cum"><?php echo esc_html( $cum_w . ' × ' . $cum_h . ' ' . $unit ); ?></strong>
						<span class="mr-tee-summary-px">(<span id="mr-tee-summary-px"><?php echo esc_html( $cum_w_px . ' × ' . $cum_h_px . ' px' ); ?></span>)</span>
					</span>
				</div>
				<div class="mr-tee-dim-summary-actions">
					<button type="button" class="button button-small mr-tee-btn-dim-toggle" id="mr-tee-toggle-dim-drawer" aria-expanded="false" aria-controls="mr-tee-dimension-toolbar">
						<span aria-hidden="true">⚙</span> <?php esc_html_e( 'Format & Dimensions', 'mr-tee-editor' ); ?>
					</button>
					<button type="button" class="button button-small mr-tee-btn-jump-fonts" id="mr-tee-jump-to-fonts">
						<span aria-hidden="true">🔤</span> <span id="mr-tee-summary-fonts-count"><?php echo esc_html( count( $allowed_fonts ) ); ?></span> <?php esc_html_e( 'polices →', 'mr-tee-editor' ); ?>
					</button>
				</div>
			</div>

			<!-- Expandable Format & Physical Dimensions Drawer (Collapsed by default so canvas stays above the fold) -->
			<div class="mr-tee-dimension-toolbar" id="mr-tee-dimension-toolbar" style="display:none;">
				<div class="mr-tee-dim-inputs-grid">
					<div class="mr-tee-dim-field">
						<label for="mr_tee_product_type"><strong><?php esc_html_e( 'Type de Produit', 'mr-tee-editor' ); ?></strong></label>
						<select id="mr_tee_product_type" name="mr_tee_config[product_type]" class="mr-tee-dim-select">
							<option value="garment" <?php selected( $prod_type, 'garment' ); ?>><?php esc_html_e( '👕 Vêtement / Textile (Zone sur mockup)', 'mr-tee-editor' ); ?></option>
							<option value="flat_object" <?php selected( $prod_type, 'flat_object' ); ?>><?php esc_html_e( '📇 Objet Plat / Surface Complète (Carte, Plaque, Mug, Flyer)', 'mr-tee-editor' ); ?></option>
						</select>
					</div>

					<div class="mr-tee-dim-field">
						<label for="mr_tee_print_unit"><?php esc_html_e( 'Unité', 'mr-tee-editor' ); ?></label>
						<select id="mr_tee_print_unit" name="mr_tee_config[print_unit]" class="mr-tee-dim-select">
							<option value="cm" <?php selected( $unit, 'cm' ); ?>>cm</option>
							<option value="mm" <?php selected( $unit, 'mm' ); ?>>mm</option>
							<option value="in" <?php selected( $unit, 'in' ); ?>>pouces (in)</option>
						</select>
					</div>

					<div class="mr-tee-dim-field">
						<label for="mr_tee_real_width"><?php esc_html_e( 'Largeur (W)', 'mr-tee-editor' ); ?></label>
						<input type="number" step="any" min="0.1" id="mr_tee_real_width" name="mr_tee_config[print_real_width]" value="<?php echo esc_attr( $real_w ); ?>" class="small-text mr-tee-dim-input" />
					</div>

					<div class="mr-tee-dim-field">
						<label for="mr_tee_real_height"><?php esc_html_e( 'Hauteur (H)', 'mr-tee-editor' ); ?></label>
						<input type="number" step="any" min="0.1" id="mr_tee_real_height" name="mr_tee_config[print_real_height]" value="<?php echo esc_attr( $real_h ); ?>" class="small-text mr-tee-dim-input" />
					</div>

					<div class="mr-tee-dim-field">
						<label for="mr_tee_dpi"><?php esc_html_e( 'DPI', 'mr-tee-editor' ); ?></label>
						<input type="number" step="1" min="72" max="1200" id="mr_tee_dpi" name="mr_tee_config[dpi]" value="<?php echo esc_attr( $dpi ); ?>" class="small-text mr-tee-dim-input" />
					</div>

					<div class="mr-tee-dim-field mr-tee-dim-field-lock">
						<label for="mr_tee_lock_aspect_ratio">
							<input type="hidden" name="mr_tee_config[lock_aspect_ratio]" value="0" />
							<input type="checkbox" id="mr_tee_lock_aspect_ratio" name="mr_tee_config[lock_aspect_ratio]" value="1" <?php checked( $is_locked ); ?> />
							<strong><?php esc_html_e( 'Verrouiller le ratio', 'mr-tee-editor' ); ?></strong>
						</label>
						<span class="description"><?php esc_html_e( 'Conserve ces proportions exactes sur tous les écrans (automatique pour Objet Plat)', 'mr-tee-editor' ); ?></span>
					</div>
				</div>

				<!-- Quick Dimensions Presets (POD Standards) -->
				<div class="mr-tee-presets-fast-bar">
					<span class="mr-tee-fast-label"><?php esc_html_e( 'Gabarits POD :', 'mr-tee-editor' ); ?></span>
					<button type="button" class="button button-small mr-tee-btn-preset-dim" data-type="flat_object" data-unit="cm" data-w="8.5" data-h="5.5" data-lock="1" data-ratio="17:11"><span aria-hidden="true">📇</span> <?php esc_html_e( 'Carte (8.5 × 5.5 cm)', 'mr-tee-editor' ); ?></button>
					<button type="button" class="button button-small mr-tee-btn-preset-dim" data-type="flat_object" data-unit="cm" data-w="15" data-h="10" data-lock="1" data-ratio="3:2"><span aria-hidden="true">🖼️</span> <?php esc_html_e( 'Photo (15 × 10 cm)', 'mr-tee-editor' ); ?></button>
					<button type="button" class="button button-small mr-tee-btn-preset-dim" data-type="flat_object" data-unit="cm" data-w="20" data-h="9.5" data-lock="1" data-ratio="40:19"><span aria-hidden="true">☕</span> <?php esc_html_e( 'Mug Wrap (20 × 9.5 cm)', 'mr-tee-editor' ); ?></button>
					<button type="button" class="button button-small mr-tee-btn-preset-dim" data-type="flat_object" data-unit="cm" data-w="21" data-h="29.7" data-lock="1" data-ratio="1:1.414"><span aria-hidden="true">📄</span> <?php esc_html_e( 'Flyer A4 (21 × 29.7 cm)', 'mr-tee-editor' ); ?></button>
					<button type="button" class="button button-small mr-tee-btn-preset-dim" data-type="flat_object" data-unit="cm" data-w="10" data-h="10" data-lock="1" data-ratio="1:1"><span aria-hidden="true">🟩</span> <?php esc_html_e( 'Carré (10 × 10 cm)', 'mr-tee-editor' ); ?></button>
					<button type="button" class="button button-small mr-tee-btn-preset-dim" data-type="garment" data-unit="cm" data-w="30" data-h="40" data-lock="0" data-ratio="3:4"><span aria-hidden="true">👕</span> <?php esc_html_e( 'T-Shirt (30 × 40 cm)', 'mr-tee-editor' ); ?></button>
				</div>

				<!-- Live Ratio & Dynamic DPI Export Badge -->
				<div class="mr-tee-ratio-badge" id="mr-tee-ratio-badge">
					<span class="mr-tee-badge-icon dashicons dashicons-image-crop" aria-hidden="true"></span>
					<span>
						<strong><?php esc_html_e( 'Zone d\'impression :', 'mr-tee-editor' ); ?></strong>
						<span id="mr-tee-badge-cum-val" class="mr-tee-badge-cum-val"><?php echo esc_html( $cum_w . ' × ' . $cum_h . ' ' . $unit ); ?></span>
					</span>
					<span class="mr-tee-badge-divider" aria-hidden="true">|</span>
					<span>
						<strong><?php esc_html_e( 'Ratio :', 'mr-tee-editor' ); ?></strong>
						<span id="mr-tee-badge-ratio-val"><?php echo esc_html( $cum_simple . ' (~' . round( $cumulative['aspect_ratio'], 3 ) . ' : 1)' ); ?></span>
					</span>
					<span class="mr-tee-badge-divider" aria-hidden="true">|</span>
					<span>
						<strong><?php esc_html_e( 'Fichier', 'mr-tee-editor' ); ?> <span id="mr-tee-badge-dpi-val"><?php echo esc_html( $dpi ); ?></span> DPI :</strong>
						<span id="mr-tee-badge-px-val"><?php echo esc_html( $cum_w_px . ' × ' . $cum_h_px . ' px' ); ?></span>
					</span>
					<span class="mr-tee-badge-status-pill"><?php esc_html_e( 'Print-Ready', 'mr-tee-editor' ); ?></span>
				</div>
			</div>

			<!-- Multi-Side & Multi-View Preview Manager -->
			<div class="mr-tee-multiview-manager" id="mr-tee-multiview-manager">
				<!-- A. Printable Sides Strip -->
				<div class="mr-tee-sides-bar" id="mr-tee-sides-bar">
					<span class="mr-tee-mv-section-label"><?php esc_html_e( 'Faces imprimables :', 'mr-tee-editor' ); ?></span>
					<div class="mr-tee-sides-list" id="mr-tee-sides-list">
						<?php foreach ( $print_sides as $side ) : ?>
							<?php
							$s_id     = $side['id'];
							$s_active = ( $s_id === $first_view_side_id );
							$s_count  = isset( $field_count_by_side[ $s_id ] ) ? intval( $field_count_by_side[ $s_id ] ) : 0;
							?>
							<div
								class="mr-tee-side-pill <?php echo $s_active ? 'is-active' : ''; ?>"
								data-side-id="<?php echo esc_attr( $s_id ); ?>"
							>
								<button
									type="button"
									class="mr-tee-side-pill-main"
									data-side-id="<?php echo esc_attr( $s_id ); ?>"
									aria-pressed="<?php echo $s_active ? 'true' : 'false'; ?>"
								>
									<span class="mr-tee-side-pill-dot" aria-hidden="true"></span>
									<span class="mr-tee-side-pill-label"><?php echo esc_html( $side['label'] ); ?></span>
									<span class="mr-tee-side-pill-dims"><?php echo esc_html( $side['print_real_width'] . '×' . $side['print_real_height'] . ' ' . $side['print_unit'] . ' · ' . $s_count ); ?></span>
								</button>
								<span class="mr-tee-side-pill-controls">
									<button
										type="button"
										class="mr-tee-side-action-btn mr-tee-side-rename-btn"
										data-side-id="<?php echo esc_attr( $s_id ); ?>"
										title="<?php esc_attr_e( 'Renommer cette face', 'mr-tee-editor' ); ?>"
										aria-label="<?php echo esc_attr( sprintf( __( 'Renommer %s', 'mr-tee-editor' ), $side['label'] ) ); ?>"
									>✎</button>
									<?php if ( count( $print_sides ) > 1 ) : ?>
										<button
											type="button"
											class="mr-tee-side-action-btn mr-tee-side-delete-btn"
											data-side-id="<?php echo esc_attr( $s_id ); ?>"
											title="<?php esc_attr_e( 'Supprimer cette face', 'mr-tee-editor' ); ?>"
											aria-label="<?php echo esc_attr( sprintf( __( 'Supprimer %s', 'mr-tee-editor' ), $side['label'] ) ); ?>"
										>✕</button>
									<?php endif; ?>
								</span>
							</div>
						<?php endforeach; ?>
					</div>
					<div class="mr-tee-sides-actions">
						<button
							type="button"
							class="button button-small mr-tee-btn-add-back-side"
							id="mr-tee-btn-add-back-side"
							<?php echo $has_back_side ? 'style="display:none;"' : ''; ?>
						>
							<?php esc_html_e( '+ Verso (Dos)', 'mr-tee-editor' ); ?>
						</button>
						<button type="button" class="button button-small mr-tee-btn-add-side" id="mr-tee-btn-add-side">
							<?php esc_html_e( '+ Nouvelle Face', 'mr-tee-editor' ); ?>
						</button>
					</div>
				</div>

				<!-- B. Preview Views Tab Strip -->
				<div class="mr-tee-views-bar" id="mr-tee-views-bar">
					<div class="mr-tee-views-list" id="mr-tee-views-list">
						<?php foreach ( $preview_views as $v_idx => $view ) : ?>
							<?php
							$v_id         = ! empty( $view['id'] ) ? $view['id'] : 'view_' . ( $v_idx + 1 );
							$v_side_id    = ! empty( $view['side_id'] ) && isset( $sides_by_id[ $view['side_id'] ] ) ? $view['side_id'] : $default_side_id;
							$v_side_label = isset( $sides_by_id[ $v_side_id ]['label'] ) ? $sides_by_id[ $v_side_id ]['label'] : $v_side_id;
							$v_role       = ! empty( $view['view_role'] ) ? $view['view_role'] : 'full';
							$v_role_label = isset( $role_labels[ $v_role ] ) ? $role_labels[ $v_role ] : $role_labels['full'];
							$v_raw_img    = ! empty( $view['image_url'] ) ? $view['image_url'] : ( $v_id === $first_default_side_view_id ? $thumb_url : '' );
							$v_img        = SceneGraphSchema::normalize_url_scheme( $v_raw_img );
							?>
							<button
								type="button"
								class="mr-tee-view-tab <?php echo 0 === $v_idx ? 'is-active' : ''; ?>"
								data-view-id="<?php echo esc_attr( $v_id ); ?>"
								data-side-id="<?php echo esc_attr( $v_side_id ); ?>"
								data-view-role="<?php echo esc_attr( $v_role ); ?>"
								aria-pressed="<?php echo 0 === $v_idx ? 'true' : 'false'; ?>"
							>
								<span class="mr-tee-view-tab-thumb">
									<?php if ( ! empty( $v_img ) ) : ?>
										<img src="<?php echo esc_url( $v_img ); ?>" alt="" loading="lazy" decoding="async" />
									<?php else : ?>
										<span class="dashicons dashicons-format-image" aria-hidden="true"></span>
									<?php endif; ?>
								</span>
								<span class="mr-tee-view-tab-meta">
									<span class="mr-tee-view-tab-label"><?php echo esc_html( $view['label'] ); ?></span>
									<span class="mr-tee-view-tab-badges">
										<span class="mr-tee-view-side-badge"><?php echo esc_html( $v_side_label ); ?></span>
										<span class="mr-tee-view-role-badge is-role-<?php echo esc_attr( $v_role ); ?>"><?php echo esc_html( $v_role_label ); ?></span>
										<?php if ( ! empty( $view['is_cart_preview'] ) ) : ?>
											<span class="mr-tee-view-badge-cart" title="<?php esc_attr_e( 'Aperçu panier', 'mr-tee-editor' ); ?>" aria-label="<?php esc_attr_e( 'Aperçu panier', 'mr-tee-editor' ); ?>">🛒</span>
										<?php endif; ?>
									</span>
								</span>
							</button>
						<?php endforeach; ?>
					</div>
					<div class="mr-tee-views-actions">
						<button type="button" class="button button-secondary button-small mr-tee-btn-add-view" id="mr-tee-btn-add-view">
							<?php esc_html_e( '+ Ajouter une Vue', 'mr-tee-editor' ); ?>
						</button>
					</div>
				</div>

				<!-- C. Active View Settings Mini-Bar -->
				<div class="mr-tee-active-view-bar" id="mr-tee-active-view-bar" data-active-view-id="<?php echo esc_attr( $first_view_id ); ?>">
					<div class="mr-tee-view-setting-group mr-tee-view-setting-name">
						<label for="mr_tee_view_label"><?php esc_html_e( 'Nom de la vue', 'mr-tee-editor' ); ?></label>
						<input
							type="text"
							id="mr_tee_view_label"
							class="mr-tee-view-input"
							value="<?php echo esc_attr( $first_view['label'] ); ?>"
							placeholder="<?php esc_attr_e( 'Zoom Poitrine, T-Shirt Entier, Dos...', 'mr-tee-editor' ); ?>"
						/>
					</div>

					<div class="mr-tee-view-setting-group">
						<label for="mr_tee_view_side_id"><?php esc_html_e( 'Face', 'mr-tee-editor' ); ?></label>
						<select id="mr_tee_view_side_id" class="mr-tee-view-select">
							<?php foreach ( $print_sides as $s ) : ?>
								<option value="<?php echo esc_attr( $s['id'] ); ?>" <?php selected( $first_view_side_id, $s['id'] ); ?>><?php echo esc_html( $s['label'] ); ?></option>
							<?php endforeach; ?>
						</select>
					</div>

					<div class="mr-tee-view-setting-group">
						<label for="mr_tee_view_role"><?php esc_html_e( 'Rôle', 'mr-tee-editor' ); ?></label>
						<select id="mr_tee_view_role" class="mr-tee-view-select">
							<option value="full" <?php selected( $first_view['view_role'], 'full' ); ?>><?php esc_html_e( 'Vue Complète', 'mr-tee-editor' ); ?></option>
							<option value="closeup" <?php selected( $first_view['view_role'], 'closeup' ); ?>><?php esc_html_e( 'Zoom / Zone Agrandie', 'mr-tee-editor' ); ?></option>
							<option value="detail" <?php selected( $first_view['view_role'], 'detail' ); ?>><?php esc_html_e( 'Détail', 'mr-tee-editor' ); ?></option>
						</select>
					</div>

					<div class="mr-tee-view-setting-group">
						<label for="mr_tee_view_blend_mode"><?php esc_html_e( 'Fusion', 'mr-tee-editor' ); ?></label>
						<select id="mr_tee_view_blend_mode" class="mr-tee-view-select">
							<option value="normal" <?php selected( $first_view_blend, 'normal' ); ?>><?php esc_html_e( 'Normal', 'mr-tee-editor' ); ?></option>
							<option value="multiply" <?php selected( $first_view_blend, 'multiply' ); ?>><?php esc_html_e( 'Tissu Clair (Multiply)', 'mr-tee-editor' ); ?></option>
							<option value="soft_ink" <?php selected( $first_view_blend, 'soft_ink' ); ?>><?php esc_html_e( 'Encre Douce (92%)', 'mr-tee-editor' ); ?></option>
						</select>
					</div>

					<div class="mr-tee-view-setting-group mr-tee-view-setting-checkbox">
						<label for="mr_tee_view_is_cart_preview">
							<input
								type="checkbox"
								id="mr_tee_view_is_cart_preview"
								value="1"
								<?php checked( ! empty( $first_view['is_cart_preview'] ) ); ?>
							/>
							<span><?php esc_html_e( 'Aperçu Panier', 'mr-tee-editor' ); ?></span>
						</label>
					</div>

					<div class="mr-tee-view-setting-actions">
						<button type="button" id="mr_tee_view_image_btn" class="button button-small">
							<span aria-hidden="true">📁</span> <?php esc_html_e( 'Image WP', 'mr-tee-editor' ); ?>
						</button>
						<label class="button button-small mr-tee-view-upload-label">
							<span><span aria-hidden="true">⬆️</span> <?php esc_html_e( 'Importer', 'mr-tee-editor' ); ?></span>
							<input type="file" id="mr_tee_view_image_upload" accept="image/*" class="screen-reader-text" />
						</label>
						<button
							type="button"
							id="mr_tee_view_move_left"
							class="button button-small"
							title="<?php esc_attr_e( 'Déplacer la vue vers la gauche', 'mr-tee-editor' ); ?>"
							aria-label="<?php esc_attr_e( 'Déplacer la vue vers la gauche', 'mr-tee-editor' ); ?>"
							disabled
						>◀</button>
						<button
							type="button"
							id="mr_tee_view_move_right"
							class="button button-small"
							title="<?php esc_attr_e( 'Déplacer la vue vers la droite', 'mr-tee-editor' ); ?>"
							aria-label="<?php esc_attr_e( 'Déplacer la vue vers la droite', 'mr-tee-editor' ); ?>"
							<?php echo count( $preview_views ) <= 1 ? 'disabled' : ''; ?>
						>▶</button>
						<button
							type="button"
							id="mr_tee_view_delete_btn"
							class="button button-small button-link-delete"
							title="<?php esc_attr_e( 'Supprimer cette vue', 'mr-tee-editor' ); ?>"
							aria-label="<?php esc_attr_e( 'Supprimer cette vue', 'mr-tee-editor' ); ?>"
							<?php echo count( $preview_views ) <= 1 ? 'disabled' : ''; ?>
						>✕</button>
					</div>
					<span id="mr-tee-view-upload-status" class="mr-tee-view-upload-status" aria-live="polite"></span>
				</div>

				<!-- Hidden JSON Payloads for Printable Sides & Preview Views -->
				<input type="hidden" id="mr_tee_print_sides_json" name="mr_tee_config[print_sides]" value="<?php echo esc_attr( wp_json_encode( array_values( $print_sides ) ) ); ?>" />
				<input type="hidden" id="mr_tee_preview_views_json" name="mr_tee_config[preview_views]" value="<?php echo esc_attr( wp_json_encode( array_values( $preview_views ) ) ); ?>" />
			</div>

			<!-- Single Field Pills Bar (Selection + Reorder + Add Text/Image) -->
			<div class="mr-tee-stage-pills-bar" id="mr-tee-stage-pills-bar">
				<div class="mr-tee-pills-scroll" id="mr-tee-pills-list">
					<?php foreach ( $text_lines as $idx => $line ) : ?>
						<?php
						$color             = self::$field_colors[ $idx % count( self::$field_colors ) ];
						$field_type        = ( isset( $line['field_type'] ) && 'image' === $line['field_type'] ) ? 'image' : 'text';
						$is_image          = ( 'image' === $field_type );
						$line_side_id      = ! empty( $line['side_id'] ) && isset( $sides_by_id[ $line['side_id'] ] ) ? $line['side_id'] : $default_side_id;
						$is_on_active_side = ( $line_side_id === $first_view_side_id );
						$view_placement    = ( $is_on_active_side && ! empty( $line['placements'][ $first_view_id ] ) && is_array( $line['placements'][ $first_view_id ] ) )
							? $line['placements'][ $first_view_id ]
							: null;
						$bw                = ( $view_placement && isset( $view_placement['box_width'] ) ) ? floatval( $view_placement['box_width'] ) : ( isset( $line['box_width'] ) ? floatval( $line['box_width'] ) : 40.0 );
						$bh                = ( $view_placement && isset( $view_placement['box_height'] ) ) ? floatval( $view_placement['box_height'] ) : ( isset( $line['box_height'] ) ? floatval( $line['box_height'] ) : ( $is_image ? 30.0 : 8.5 ) );
						$br                = ( $view_placement && isset( $view_placement['box_rotate'] ) ) ? intval( round( floatval( $view_placement['box_rotate'] ) ) ) : ( isset( $line['box_rotate'] ) ? intval( round( floatval( $line['box_rotate'] ) ) ) : 0 );
						$rot_suffix        = 0 !== $br ? ' (' . $br . '°)' : '';
						?>
						<button
							type="button"
							class="mr-tee-field-pill <?php echo $initial_active_idx === $idx ? 'is-active' : ''; ?> <?php echo $is_image ? 'is-image-pill' : ''; ?> <?php echo $is_on_active_side ? '' : 'is-other-side'; ?>"
							data-field-index="<?php echo esc_attr( $idx ); ?>"
							data-field-type="<?php echo esc_attr( $field_type ); ?>"
							data-side-id="<?php echo esc_attr( $line_side_id ); ?>"
							style="--pill-color: <?php echo esc_attr( $color ); ?>;"
						>
							<span class="mr-tee-pill-badge" style="background: <?php echo esc_attr( $color ); ?>;"><?php echo esc_html( $idx + 1 ); ?></span>
							<?php if ( $is_image ) : ?>
								<span class="mr-tee-pill-type-icon" aria-hidden="true">🖼️</span>
							<?php endif; ?>
							<span class="mr-tee-pill-label"><?php echo esc_html( $line['label'] ); ?></span>
							<span class="mr-tee-pill-dims"><?php echo esc_html( round( $bw, 1 ) . '×' . round( $bh, 1 ) . '%' . $rot_suffix ); ?></span>
						</button>
					<?php endforeach; ?>
				</div>

				<div class="mr-tee-add-buttons-group">
					<div class="mr-tee-field-reorder-group" title="<?php esc_attr_e( 'Réordonner le champ actif dans le formulaire client', 'mr-tee-editor' ); ?>">
						<button type="button" class="button button-small" id="mr-tee-btn-field-move-left" title="<?php esc_attr_e( 'Déplacer le champ actif avant', 'mr-tee-editor' ); ?>" aria-label="<?php esc_attr_e( 'Déplacer le champ actif avant', 'mr-tee-editor' ); ?>" <?php echo count( $text_lines ) <= 1 ? 'disabled' : ''; ?>>◀</button>
						<button type="button" class="button button-small" id="mr-tee-btn-field-move-right" title="<?php esc_attr_e( 'Déplacer le champ actif après', 'mr-tee-editor' ); ?>" aria-label="<?php esc_attr_e( 'Déplacer le champ actif après', 'mr-tee-editor' ); ?>" <?php echo count( $text_lines ) <= 1 ? 'disabled' : ''; ?>>▶</button>
					</div>
					<button type="button" class="button button-secondary button-small mr-tee-btn-add-field-pill" id="mr-tee-btn-add-field-pill">
						+ <?php esc_html_e( 'Texte', 'mr-tee-editor' ); ?>
					</button>
					<button type="button" class="button button-secondary button-small mr-tee-btn-add-image-field-pill" id="mr-tee-btn-add-image-field-pill">
						<span aria-hidden="true">🖼️</span> + <?php esc_html_e( 'Image', 'mr-tee-editor' ); ?>
					</button>
				</div>
			</div>

			<div class="mr-tee-stage-container">
				<!-- Left Column: Canvas Micro-Toolbar + Mockup Stage -->
				<div class="mr-tee-stage-col-preview">
					<!-- Canvas Micro-Toolbar -->
					<div class="mr-tee-canvas-toolbar" id="mr-tee-canvas-toolbar" role="toolbar" aria-label="<?php esc_attr_e( 'Outils de positionnement', 'mr-tee-editor' ); ?>">
						<button type="button" class="mr-tee-canvas-tool-btn" data-action="undo" title="<?php esc_attr_e( 'Annuler le dernier déplacement (Ctrl+Z)', 'mr-tee-editor' ); ?>" aria-label="<?php esc_attr_e( 'Annuler', 'mr-tee-editor' ); ?>" disabled>↶</button>
						<button type="button" class="mr-tee-canvas-tool-btn" data-action="redo" title="<?php esc_attr_e( 'Rétablir (Ctrl+Shift+Z)', 'mr-tee-editor' ); ?>" aria-label="<?php esc_attr_e( 'Rétablir', 'mr-tee-editor' ); ?>" disabled>↷</button>
						<span class="mr-tee-canvas-tool-sep" aria-hidden="true"></span>
						<button type="button" class="mr-tee-canvas-tool-btn" data-action="center-h" title="<?php esc_attr_e( 'Centrer horizontalement', 'mr-tee-editor' ); ?>"><span aria-hidden="true">↔</span> <?php esc_html_e( 'Centrer H', 'mr-tee-editor' ); ?></button>
						<button type="button" class="mr-tee-canvas-tool-btn" data-action="center-v" title="<?php esc_attr_e( 'Centrer verticalement', 'mr-tee-editor' ); ?>"><span aria-hidden="true">↕</span> <?php esc_html_e( 'Centrer V', 'mr-tee-editor' ); ?></button>
						<button type="button" class="mr-tee-canvas-tool-btn" data-action="center-both" title="<?php esc_attr_e( 'Centrer au milieu du visuel', 'mr-tee-editor' ); ?>"><span aria-hidden="true">✛</span> <?php esc_html_e( 'Centrer', 'mr-tee-editor' ); ?></button>
						<span class="mr-tee-canvas-tool-sep" aria-hidden="true"></span>
						<button type="button" class="mr-tee-canvas-tool-btn" data-action="reset-rot" title="<?php esc_attr_e( 'Réinitialiser la rotation à 0°', 'mr-tee-editor' ); ?>"><span aria-hidden="true">↺</span> 0°</button>
						<button type="button" class="mr-tee-canvas-tool-btn" data-action="duplicate" title="<?php esc_attr_e( 'Dupliquer le champ actif', 'mr-tee-editor' ); ?>"><svg class="mr-tee-icon" width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect x="9" y="9" width="13" height="13" rx="2" ry="2"/><path d="M5 15H4a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2h9a2 2 0 0 1 2 2v1"/></svg><?php esc_html_e( 'Dupliquer', 'mr-tee-editor' ); ?></button>
					</div>

					<div
						class="mr-tee-stage-canvas-wrap <?php echo $is_locked ? 'is-aspect-locked' : ''; ?> <?php echo '' === $first_view_img ? 'is-no-image' : ''; ?>"
						id="mr-tee-stage-canvas-wrap"
						tabindex="0"
						aria-label="<?php esc_attr_e( 'Zone de placement visuelle — utilisez les flèches pour ajuster la zone active', 'mr-tee-editor' ); ?>"
						data-active-view-id="<?php echo esc_attr( $first_view_id ); ?>"
						data-active-side-id="<?php echo esc_attr( $first_view_side_id ); ?>"
						data-blend-mode="<?php echo esc_attr( $first_view_blend ); ?>"
						style="<?php echo $is_locked ? 'aspect-ratio: ' . esc_attr( $ratio ) . '; --stage-ratio: ' . esc_attr( $ratio ) . ';' : ''; ?>"
					>
						<img
							src="<?php echo esc_url( $first_view_img ); ?>"
							alt="<?php esc_attr_e( 'Aperçu du produit', 'mr-tee-editor' ); ?>"
							decoding="async"
							class="mr-tee-stage-garment-img"
							id="mr-tee-stage-garment-img"
							<?php echo '' === $first_view_img ? 'style="display:none;"' : ''; ?>
						/>

						<!-- Magnetic Center Alignment Guides & Live Transform HUD -->
						<div class="mr-tee-snap-guide mr-tee-snap-guide-v" id="mr-tee-snap-guide-v" aria-hidden="true"></div>
						<div class="mr-tee-snap-guide mr-tee-snap-guide-h" id="mr-tee-snap-guide-h" aria-hidden="true"></div>
						<div class="mr-tee-transform-hud" id="mr-tee-transform-hud" aria-hidden="true"></div>

						<!-- Empty-State Overlay when active view has no mockup image yet -->
						<div class="mr-tee-stage-empty-overlay" id="mr-tee-stage-empty-overlay" <?php echo '' === $first_view_img ? '' : 'style="display:none;"'; ?>>
							<div class="mr-tee-stage-empty-card">
								<span class="mr-tee-stage-empty-icon" aria-hidden="true">📷</span>
								<strong><?php esc_html_e( 'Choisissez l\'image mockup de cette vue', 'mr-tee-editor' ); ?></strong>
								<p><?php esc_html_e( 'Importez ou sélectionnez la photo de cette vue pour placer vos zones de personnalisation.', 'mr-tee-editor' ); ?></p>
								<div class="mr-tee-stage-empty-actions">
									<button type="button" class="button button-primary mr-tee-empty-pick-wp"><span aria-hidden="true">📁</span> <?php esc_html_e( 'Image WP', 'mr-tee-editor' ); ?></button>
									<button type="button" class="button button-secondary mr-tee-empty-upload-file"><span aria-hidden="true">⬆️</span> <?php esc_html_e( 'Importer', 'mr-tee-editor' ); ?></button>
								</div>
							</div>
						</div>

						<!-- Multi-Rectangle Design Area Boxes -->
						<div id="mr-tee-boxes-container" data-blend="<?php echo esc_attr( $first_view_blend ); ?>">
							<?php foreach ( $text_lines as $idx => $line ) : ?>
								<?php
								$color             = self::$field_colors[ $idx % count( self::$field_colors ) ];
								$field_type        = ( isset( $line['field_type'] ) && 'image' === $line['field_type'] ) ? 'image' : 'text';
								$is_image          = ( 'image' === $field_type );
								$line_side_id      = ! empty( $line['side_id'] ) && isset( $sides_by_id[ $line['side_id'] ] ) ? $line['side_id'] : $default_side_id;
								$is_on_active_side = ( $line_side_id === $first_view_side_id );
								$view_placement    = ( $is_on_active_side && ! empty( $line['placements'][ $first_view_id ] ) && is_array( $line['placements'][ $first_view_id ] ) )
									? $line['placements'][ $first_view_id ]
									: null;
								$bl                = ( $view_placement && isset( $view_placement['box_left'] ) ) ? floatval( $view_placement['box_left'] ) : ( isset( $line['box_left'] ) ? floatval( $line['box_left'] ) : 30.0 );
								$bt                = ( $view_placement && isset( $view_placement['box_top'] ) ) ? floatval( $view_placement['box_top'] ) : ( isset( $line['box_top'] ) ? floatval( $line['box_top'] ) : ( 25.0 + $idx * 12.0 ) );
								$bw                = ( $view_placement && isset( $view_placement['box_width'] ) ) ? floatval( $view_placement['box_width'] ) : ( isset( $line['box_width'] ) ? floatval( $line['box_width'] ) : 40.0 );
								$bh                = ( $view_placement && isset( $view_placement['box_height'] ) ) ? floatval( $view_placement['box_height'] ) : ( isset( $line['box_height'] ) ? floatval( $line['box_height'] ) : ( $is_image ? 30.0 : 8.5 ) );
								$br                = ( $view_placement && isset( $view_placement['box_rotate'] ) ) ? floatval( $view_placement['box_rotate'] ) : ( isset( $line['box_rotate'] ) ? floatval( $line['box_rotate'] ) : 0.0 );
								$badge_classes     = ( $bt < 8.0 ? ' is-badge-bottom' : '' ) . ( ( $bt + $bh ) > 92.0 ? ' is-badge-inside' : '' );
								?>
								<div
									id="mr-tee-field-box-<?php echo esc_attr( $idx ); ?>"
									class="mr-tee-field-box <?php echo $initial_active_idx === $idx ? 'is-active' : ''; ?> <?php echo $is_image ? 'is-image-field' : ''; ?> <?php echo $is_on_active_side ? '' : 'is-other-side'; ?><?php echo esc_attr( $badge_classes ); ?>"
									data-field-index="<?php echo esc_attr( $idx ); ?>"
									data-field-type="<?php echo esc_attr( $field_type ); ?>"
									data-side-id="<?php echo esc_attr( $line_side_id ); ?>"
									style="<?php echo $is_on_active_side ? '' : 'display:none; '; ?>left: <?php echo esc_attr( $bl ); ?>%; top: <?php echo esc_attr( $bt ); ?>%; width: <?php echo esc_attr( $bw ); ?>%; height: <?php echo esc_attr( $bh ); ?>%; transform: rotate(<?php echo esc_attr( $br ); ?>deg); transform-origin: center center; --field-color: <?php echo esc_attr( $color ); ?>;"
								>
									<span class="mr-tee-box-badge" style="background: <?php echo esc_attr( $color ); ?>;">
										<?php echo esc_html( ( $idx + 1 ) . '. ' . ( $is_image ? '🖼️ ' : '' ) . $line['label'] ); ?>
									</span>
									<div class="mr-tee-box-svg-holder" id="mr-tee-box-svg-<?php echo esc_attr( $idx ); ?>">
										<!-- Live SVG rendered by admin JS engine -->
									</div>

									<!-- Interactive Rotation Handle & Stem -->
									<div class="mr-tee-handle-rot-stem" style="background: <?php echo esc_attr( $color ); ?>;"></div>
									<div class="mr-tee-handle-rot" data-handle="rot" style="border-color: <?php echo esc_attr( $color ); ?>;" title="<?php esc_attr_e( 'Faire pivoter la zone', 'mr-tee-editor' ); ?>"></div>

									<!-- Interactive Corner & Edge Resize Handles (NW/NE/SW/SE + N/S/E/W) -->
									<div class="mr-tee-handle mr-tee-handle-nw" data-handle="nw" style="border-color: <?php echo esc_attr( $color ); ?>;"></div>
									<div class="mr-tee-handle mr-tee-handle-ne" data-handle="ne" style="border-color: <?php echo esc_attr( $color ); ?>;"></div>
									<div class="mr-tee-handle mr-tee-handle-sw" data-handle="sw" style="border-color: <?php echo esc_attr( $color ); ?>;"></div>
									<div class="mr-tee-handle mr-tee-handle-se" data-handle="se" style="border-color: <?php echo esc_attr( $color ); ?>;"></div>
									<div class="mr-tee-handle mr-tee-handle-edge mr-tee-handle-n" data-handle="n" style="border-color: <?php echo esc_attr( $color ); ?>;"></div>
									<div class="mr-tee-handle mr-tee-handle-edge mr-tee-handle-s" data-handle="s" style="border-color: <?php echo esc_attr( $color ); ?>;"></div>
									<div class="mr-tee-handle mr-tee-handle-edge mr-tee-handle-e" data-handle="e" style="border-color: <?php echo esc_attr( $color ); ?>;"></div>
									<div class="mr-tee-handle mr-tee-handle-edge mr-tee-handle-w" data-handle="w" style="border-color: <?php echo esc_attr( $color ); ?>;"></div>
								</div>
							<?php endforeach; ?>
						</div>
					</div>

					<p class="description mr-tee-stage-instructions">
						<?php esc_html_e( 'Glissez une zone (aimantée au centre, maintenez Alt pour désactiver), utilisez les flèches quand la scène est active (Maj = 2%), Échap pour annuler, Ctrl+Z pour revenir en arrière.', 'mr-tee-editor' ); ?>
					</p>
				</div>

				<!-- Right Column: Compact Active Field Inspector -->
				<div class="mr-tee-stage-col-controls">
					<div class="mr-tee-controls-header">
						<h4><?php esc_html_e( 'Configuration du Champ Actif', 'mr-tee-editor' ); ?></h4>
					</div>

					<div id="mr-tee-field-panels-container">
						<?php foreach ( $text_lines as $idx => $line ) : ?>
							<?php
							$color             = self::$field_colors[ $idx % count( self::$field_colors ) ];
							$field_type        = ( isset( $line['field_type'] ) && 'image' === $line['field_type'] ) ? 'image' : 'text';
							$is_image          = ( 'image' === $field_type );
							$line_side_id      = ! empty( $line['side_id'] ) && isset( $sides_by_id[ $line['side_id'] ] ) ? $line['side_id'] : $default_side_id;
							$is_on_active_side = ( $line_side_id === $first_view_side_id );
							$base_box          = array(
								'box_left'   => isset( $line['box_left'] ) ? floatval( $line['box_left'] ) : 30.0,
								'box_top'    => isset( $line['box_top'] ) ? floatval( $line['box_top'] ) : ( 25.0 + $idx * 12.0 ),
								'box_width'  => isset( $line['box_width'] ) ? floatval( $line['box_width'] ) : 40.0,
								'box_height' => isset( $line['box_height'] ) ? floatval( $line['box_height'] ) : ( $is_image ? 30.0 : 8.5 ),
								'box_rotate' => isset( $line['box_rotate'] ) ? floatval( $line['box_rotate'] ) : 0.0,
							);
							$line_placements   = ( ! empty( $line['placements'] ) && is_array( $line['placements'] ) )
								? $line['placements']
								: SceneGraphSchema::normalize_field_placements( array(), $base_box, $preview_views, $line_side_id );
							$view_placement    = ( $is_on_active_side && ! empty( $line_placements[ $first_view_id ] ) && is_array( $line_placements[ $first_view_id ] ) )
								? $line_placements[ $first_view_id ]
								: null;
							$bl                = ( $view_placement && isset( $view_placement['box_left'] ) ) ? floatval( $view_placement['box_left'] ) : $base_box['box_left'];
							$bt                = ( $view_placement && isset( $view_placement['box_top'] ) ) ? floatval( $view_placement['box_top'] ) : $base_box['box_top'];
							$bw                = ( $view_placement && isset( $view_placement['box_width'] ) ) ? floatval( $view_placement['box_width'] ) : $base_box['box_width'];
							$bh                = ( $view_placement && isset( $view_placement['box_height'] ) ) ? floatval( $view_placement['box_height'] ) : $base_box['box_height'];
							$br                = ( $view_placement && isset( $view_placement['box_rotate'] ) ) ? floatval( $view_placement['box_rotate'] ) : $base_box['box_rotate'];
							$def_img_url       = ! empty( $line['default_image'] ) ? SceneGraphSchema::normalize_url_scheme( $line['default_image'] ) : '';
							$def_img_id        = ! empty( $line['default_image_id'] ) ? absint( $line['default_image_id'] ) : 0;
							$image_fit         = ( isset( $line['image_fit'] ) && 'cover' === $line['image_fit'] ) ? 'cover' : 'contain';
							$allow_upload      = ! isset( $line['allow_image_upload'] ) || ! empty( $line['allow_image_upload'] );

							$field_uid           = ! empty( $line['id'] ) ? $line['id'] : 'line_' . ( $idx + 1 );
							$field_ref_id        = ! empty( $line['ref_id'] ) ? $line['ref_id'] : ( 'id-ref-' . ( $is_image ? 'image' : 'text' ) . '-line-' . ( $idx + 1 ) );
							$field_color_ref     = ! empty( $line['color_ref_id'] ) ? $line['color_ref_id'] : 'id-ref-color-line-' . ( $idx + 1 );
							$placements_json_val = empty( $line_placements ) ? '{}' : wp_json_encode( $line_placements );

							$selected_line_font = ( ! empty( $line['font'] ) && in_array( $line['font'], $allowed_fonts, true ) )
								? $line['font']
								: ( ! empty( $allowed_fonts[0] ) ? $allowed_fonts[0] : '' );
							$sel_font_def       = ( '' !== $selected_line_font && isset( $fonts[ $selected_line_font ] ) ) ? $fonts[ $selected_line_font ] : array();
							$is_sel_svg_font    = ( ! empty( $sel_font_def['font_type'] ) && 'opentype_svg' === $sel_font_def['font_type'] ) || ! empty( $sel_font_def['color_svg'] );
							$color_mode         = isset( $line['color_mode'] ) && in_array( $line['color_mode'], array( 'multi', 'solid' ), true ) ? $line['color_mode'] : 'multi';
							$font_def_palette0  = ( ! empty( $sel_font_def['palette'] ) && is_array( $sel_font_def['palette'] ) ) ? $sel_font_def['palette'][0] : '#111827';
							$line_color_hex     = ! empty( $line['color_hex'] ) ? $line['color_hex'] : $font_def_palette0;
							if ( ! preg_match( '/^#[0-9a-fA-F]{6}$/', $line_color_hex ) ) {
								$line_color_hex = '#111827';
							}
							$allow_color_pick = ! isset( $line['allow_color_pick'] ) || ! empty( $line['allow_color_pick'] );
							?>
							<div
								class="mr-tee-field-panel <?php echo $initial_active_idx === $idx ? 'is-active' : ''; ?> <?php echo $is_image ? 'is-image-panel' : ''; ?>"
								id="mr-tee-field-panel-<?php echo esc_attr( $idx ); ?>"
								data-field-index="<?php echo esc_attr( $idx ); ?>"
								data-field-type="<?php echo esc_attr( $field_type ); ?>"
								data-side-id="<?php echo esc_attr( $line_side_id ); ?>"
							>
								<input type="hidden" name="mr_tee_config[text_lines][<?php echo esc_attr( $idx ); ?>][id]" value="<?php echo esc_attr( $field_uid ); ?>" class="mr-tee-field-id" data-field-index="<?php echo esc_attr( $idx ); ?>" />
								<input type="hidden" name="mr_tee_config[text_lines][<?php echo esc_attr( $idx ); ?>][ref_id]" value="<?php echo esc_attr( $field_ref_id ); ?>" class="mr-tee-field-ref-id" data-field-index="<?php echo esc_attr( $idx ); ?>" />
								<input type="hidden" name="mr_tee_config[text_lines][<?php echo esc_attr( $idx ); ?>][color_ref_id]" value="<?php echo esc_attr( $field_color_ref ); ?>" class="mr-tee-field-color-ref-id" data-field-index="<?php echo esc_attr( $idx ); ?>" />
								<input
									type="hidden"
									id="mr_tee_field_placements_<?php echo esc_attr( $idx ); ?>"
									class="mr-tee-field-placements-json"
									name="mr_tee_config[text_lines][<?php echo esc_attr( $idx ); ?>][placements]"
									value="<?php echo esc_attr( $placements_json_val ); ?>"
									data-field-index="<?php echo esc_attr( $idx ); ?>"
								/>
								<div class="mr-tee-panel-badge-row">
									<span class="mr-tee-panel-indicator" style="background: <?php echo esc_attr( $color ); ?>;"></span>
									<strong class="mr-tee-panel-title"><?php echo esc_html( sprintf( __( 'Champ #%d : %s', 'mr-tee-editor' ), $idx + 1, $line['label'] ) ); ?></strong>
								</div>

								<table class="form-table mr-tee-coords-table">
									<tr>
										<th><label for="mr_tee_field_label_<?php echo esc_attr( $idx ); ?>" class="mr-tee-lbl-label"><?php esc_html_e( 'Nom', 'mr-tee-editor' ); ?></label></th>
										<td>
											<input
												type="text"
												id="mr_tee_field_label_<?php echo esc_attr( $idx ); ?>"
												name="mr_tee_config[text_lines][<?php echo esc_attr( $idx ); ?>][label]"
												value="<?php echo esc_attr( $line['label'] ); ?>"
												class="regular-text mr-tee-field-label-input"
												data-field-index="<?php echo esc_attr( $idx ); ?>"
											/>
										</td>
									</tr>
									<tr>
										<th><label for="mr_tee_field_type_<?php echo esc_attr( $idx ); ?>" class="mr-tee-lbl-type"><?php esc_html_e( 'Type', 'mr-tee-editor' ); ?></label></th>
										<td>
											<select
												id="mr_tee_field_type_<?php echo esc_attr( $idx ); ?>"
												name="mr_tee_config[text_lines][<?php echo esc_attr( $idx ); ?>][field_type]"
												class="mr-tee-field-type-select"
												data-field-index="<?php echo esc_attr( $idx ); ?>"
											>
												<option value="text" <?php selected( $field_type, 'text' ); ?>><?php esc_html_e( '📝 Champ Texte', 'mr-tee-editor' ); ?></option>
												<option value="image" <?php selected( $field_type, 'image' ); ?>><?php esc_html_e( '🖼️ Champ Image', 'mr-tee-editor' ); ?></option>
											</select>
										</td>
									</tr>
									<tr>
										<th><label for="mr_tee_field_side_<?php echo esc_attr( $idx ); ?>" class="mr-tee-lbl-side"><?php esc_html_e( 'Face', 'mr-tee-editor' ); ?></label></th>
										<td>
											<select
												id="mr_tee_field_side_<?php echo esc_attr( $idx ); ?>"
												name="mr_tee_config[text_lines][<?php echo esc_attr( $idx ); ?>][side_id]"
												class="mr-tee-field-side-select"
												data-field-index="<?php echo esc_attr( $idx ); ?>"
											>
												<?php foreach ( $print_sides as $s ) : ?>
													<option value="<?php echo esc_attr( $s['id'] ); ?>" <?php selected( $line_side_id, $s['id'] ); ?>><?php echo esc_html( $s['label'] ); ?></option>
												<?php endforeach; ?>
											</select>
										</td>
									</tr>

									<!-- Image Field Specific Controls -->
									<tr class="mr-tee-row-image-only" <?php echo $is_image ? '' : 'style="display:none;"'; ?>>
										<th><label for="mr_tee_field_default_image_<?php echo esc_attr( $idx ); ?>" class="mr-tee-lbl-default-image"><?php esc_html_e( 'Image', 'mr-tee-editor' ); ?></label></th>
										<td>
											<div class="mr-tee-default-img-picker" data-field-index="<?php echo esc_attr( $idx ); ?>">
												<div class="mr-tee-default-img-preview-wrap">
													<?php if ( $def_img_url ) : ?>
														<img src="<?php echo esc_url( $def_img_url ); ?>" alt="" loading="lazy" decoding="async" class="mr-tee-default-img-preview" />
													<?php else : ?>
														<span class="mr-tee-default-img-empty"><?php esc_html_e( 'Aucune image par défaut', 'mr-tee-editor' ); ?></span>
													<?php endif; ?>
												</div>
												<input
													type="hidden"
													name="mr_tee_config[text_lines][<?php echo esc_attr( $idx ); ?>][default_image_id]"
													value="<?php echo esc_attr( $def_img_id ); ?>"
													class="mr-tee-field-default-image-id"
													data-field-index="<?php echo esc_attr( $idx ); ?>"
												/>
												<input
													type="text"
													id="mr_tee_field_default_image_<?php echo esc_attr( $idx ); ?>"
													name="mr_tee_config[text_lines][<?php echo esc_attr( $idx ); ?>][default_image]"
													value="<?php echo esc_attr( $def_img_url ); ?>"
													placeholder="https://..."
													class="regular-text mr-tee-field-default-image-input"
													data-field-index="<?php echo esc_attr( $idx ); ?>"
												/>
												<div class="mr-tee-default-img-actions">
													<button type="button" class="button button-secondary button-small mr-tee-btn-select-default-image" data-field-index="<?php echo esc_attr( $idx ); ?>">
														<span aria-hidden="true">📁</span> <?php esc_html_e( 'Médiathèque', 'mr-tee-editor' ); ?>
													</button>
													<label class="button button-small mr-tee-upload-btn-label">
														<span><span aria-hidden="true">⬆️</span> <?php esc_html_e( 'Importer', 'mr-tee-editor' ); ?></span>
														<input type="file" accept="image/*" class="screen-reader-text mr-tee-quick-image-file" data-field-index="<?php echo esc_attr( $idx ); ?>" />
													</label>
													<button
														type="button"
														class="button button-small mr-tee-btn-clear-default-image"
														data-field-index="<?php echo esc_attr( $idx ); ?>"
														aria-label="<?php esc_attr_e( 'Retirer l\'image', 'mr-tee-editor' ); ?>"
														<?php echo $def_img_url ? '' : 'style="display:none;"'; ?>
													>✕</button>
												</div>
												<span class="mr-tee-img-upload-status" aria-live="polite"></span>
											</div>
										</td>
									</tr>
									<tr class="mr-tee-row-image-only" <?php echo $is_image ? '' : 'style="display:none;"'; ?>>
										<th><label for="mr_tee_field_image_fit_<?php echo esc_attr( $idx ); ?>" class="mr-tee-lbl-image-fit"><?php esc_html_e( 'Ajustement', 'mr-tee-editor' ); ?></label></th>
										<td>
											<select
												id="mr_tee_field_image_fit_<?php echo esc_attr( $idx ); ?>"
												name="mr_tee_config[text_lines][<?php echo esc_attr( $idx ); ?>][image_fit]"
												class="mr-tee-field-image-fit"
												data-field-index="<?php echo esc_attr( $idx ); ?>"
											>
												<option value="contain" <?php selected( $image_fit, 'contain' ); ?>><?php esc_html_e( 'Entier (Contain — sans rogner)', 'mr-tee-editor' ); ?></option>
												<option value="cover" <?php selected( $image_fit, 'cover' ); ?>><?php esc_html_e( 'Remplir (Cover — recadré)', 'mr-tee-editor' ); ?></option>
											</select>
										</td>
									</tr>

									<!-- Text Field Specific Controls -->
									<tr class="mr-tee-row-text-only" <?php echo $is_image ? 'style="display:none;"' : ''; ?>>
										<th><label for="mr_tee_field_text_<?php echo esc_attr( $idx ); ?>" class="mr-tee-lbl-text"><?php esc_html_e( 'Texte', 'mr-tee-editor' ); ?></label></th>
										<td>
											<?php if ( ! empty( $line['multiline'] ) ) : ?>
												<textarea
													rows="2"
													id="mr_tee_field_text_<?php echo esc_attr( $idx ); ?>"
													name="mr_tee_config[text_lines][<?php echo esc_attr( $idx ); ?>][default_text]"
													class="regular-text mr-tee-field-text-input"
													data-field-index="<?php echo esc_attr( $idx ); ?>"
												><?php echo esc_textarea( isset( $line['default_text'] ) ? $line['default_text'] : '' ); ?></textarea>
											<?php else : ?>
												<input
													type="text"
													id="mr_tee_field_text_<?php echo esc_attr( $idx ); ?>"
													name="mr_tee_config[text_lines][<?php echo esc_attr( $idx ); ?>][default_text]"
													value="<?php echo esc_attr( isset( $line['default_text'] ) ? $line['default_text'] : '' ); ?>"
													class="regular-text mr-tee-field-text-input"
													data-field-index="<?php echo esc_attr( $idx ); ?>"
												/>
											<?php endif; ?>
										</td>
									</tr>
									<tr class="mr-tee-row-text-only" <?php echo $is_image ? 'style="display:none;"' : ''; ?>>
										<th><label for="mr_tee_field_font_<?php echo esc_attr( $idx ); ?>" class="mr-tee-lbl-font"><?php esc_html_e( 'Police', 'mr-tee-editor' ); ?></label></th>
										<td>
											<?php
											$svg_allowed_ids = array();
											$std_allowed_ids = array();
											foreach ( $allowed_fonts as $af_id ) {
												if ( ! isset( $fonts[ $af_id ] ) ) {
													continue;
												}
												$af_data = $fonts[ $af_id ];
												$af_svg  = ( ! empty( $af_data['font_type'] ) && 'opentype_svg' === $af_data['font_type'] ) || ! empty( $af_data['color_svg'] );
												if ( $af_svg ) {
													$svg_allowed_ids[] = $af_id;
												} else {
													$std_allowed_ids[] = $af_id;
												}
											}
											?>
											<select
												id="mr_tee_field_font_<?php echo esc_attr( $idx ); ?>"
												name="mr_tee_config[text_lines][<?php echo esc_attr( $idx ); ?>][font]"
												class="mr-tee-field-font-select"
												data-field-index="<?php echo esc_attr( $idx ); ?>"
											>
												<?php if ( ! empty( $svg_allowed_ids ) ) : ?>
													<optgroup label="<?php esc_attr_e( '✨ Polices OpenType-SVG', 'mr-tee-editor' ); ?>">
														<?php foreach ( $svg_allowed_ids as $f_id ) : ?>
															<?php $f_data = $fonts[ $f_id ]; ?>
															<option value="<?php echo esc_attr( $f_id ); ?>" <?php selected( $selected_line_font, $f_id ); ?>><?php echo esc_html( $f_data['name'] ); ?></option>
														<?php endforeach; ?>
													</optgroup>
												<?php endif; ?>
												<?php if ( ! empty( $std_allowed_ids ) ) : ?>
													<optgroup label="<?php esc_attr_e( '🔤 Polices TTF', 'mr-tee-editor' ); ?>">
														<?php foreach ( $std_allowed_ids as $f_id ) : ?>
															<?php $f_data = $fonts[ $f_id ]; ?>
															<option value="<?php echo esc_attr( $f_id ); ?>" <?php selected( $selected_line_font, $f_id ); ?>><?php echo esc_html( $f_data['name'] ); ?></option>
														<?php endforeach; ?>
													</optgroup>
												<?php endif; ?>
											</select>
										</td>
									</tr>
									<tr class="mr-tee-row-text-only mr-tee-row-svg-color-notice" <?php echo ( ! $is_image && $is_sel_svg_font ) ? '' : 'style="display:none;"'; ?>>
										<th><span><?php esc_html_e( 'Couleur', 'mr-tee-editor' ); ?></span></th>
										<td>
											<span class="mr-tee-svg-color-notice"><span aria-hidden="true">✨</span> <?php esc_html_e( 'Couleurs intégrées à la police SVG', 'mr-tee-editor' ); ?></span>
										</td>
									</tr>
									<tr class="mr-tee-row-text-only mr-tee-row-color-mode" <?php echo ( ! $is_image && ! $is_sel_svg_font ) ? '' : 'style="display:none;"'; ?>>
										<th><label for="mr_tee_field_color_mode_<?php echo esc_attr( $idx ); ?>" class="mr-tee-lbl-color-mode"><?php esc_html_e( 'Mode Couleur', 'mr-tee-editor' ); ?></label></th>
										<td>
											<select
												id="mr_tee_field_color_mode_<?php echo esc_attr( $idx ); ?>"
												name="mr_tee_config[text_lines][<?php echo esc_attr( $idx ); ?>][color_mode]"
												class="mr-tee-field-color-mode"
												data-field-index="<?php echo esc_attr( $idx ); ?>"
											>
												<option value="multi" <?php selected( $color_mode, 'multi' ); ?>><?php esc_html_e( 'Multicolore (Palette)', 'mr-tee-editor' ); ?></option>
												<option value="solid" <?php selected( $color_mode, 'solid' ); ?>><?php esc_html_e( 'Couleur Unie', 'mr-tee-editor' ); ?></option>
											</select>
											<label class="mr-tee-inline-check">
												<input type="hidden" name="mr_tee_config[text_lines][<?php echo esc_attr( $idx ); ?>][allow_color_pick]" value="0" />
												<input
													type="checkbox"
													name="mr_tee_config[text_lines][<?php echo esc_attr( $idx ); ?>][allow_color_pick]"
													value="1"
													class="mr-tee-field-allow-color-pick"
													data-field-index="<?php echo esc_attr( $idx ); ?>"
													<?php checked( $allow_color_pick ); ?>
												/>
												<?php esc_html_e( 'Choix de couleur client', 'mr-tee-editor' ); ?>
											</label>
										</td>
									</tr>
									<tr class="mr-tee-row-text-only mr-tee-row-solid-color" <?php echo ( ! $is_image && ! $is_sel_svg_font && 'solid' === $color_mode ) ? '' : 'style="display:none;"'; ?>>
										<th><label for="mr_tee_field_color_hex_<?php echo esc_attr( $idx ); ?>" class="mr-tee-lbl-color-hex"><?php esc_html_e( 'Couleur du texte', 'mr-tee-editor' ); ?></label></th>
										<td>
											<div class="mr-tee-color-picker-row">
												<input
													type="color"
													id="mr_tee_field_color_hex_<?php echo esc_attr( $idx ); ?>"
													name="mr_tee_config[text_lines][<?php echo esc_attr( $idx ); ?>][color_hex]"
													value="<?php echo esc_attr( $line_color_hex ); ?>"
													class="mr-tee-field-color-hex"
													data-field-index="<?php echo esc_attr( $idx ); ?>"
												/>
												<span class="mr-tee-color-hex-readout"><?php echo esc_html( strtoupper( $line_color_hex ) ); ?></span>
											</div>
										</td>
									</tr>
									<tr class="mr-tee-row-text-only" <?php echo $is_image ? 'style="display:none;"' : ''; ?>>
										<th><label for="mr_tee_field_max_chars_<?php echo esc_attr( $idx ); ?>" class="mr-tee-lbl-max-chars"><?php esc_html_e( 'Max Caract.', 'mr-tee-editor' ); ?></label></th>
										<td>
											<input
												type="number"
												step="1"
												min="1"
												max="500"
												id="mr_tee_field_max_chars_<?php echo esc_attr( $idx ); ?>"
												name="mr_tee_config[text_lines][<?php echo esc_attr( $idx ); ?>][max_chars]"
												value="<?php echo esc_attr( isset( $line['max_chars'] ) && intval( $line['max_chars'] ) > 0 ? intval( $line['max_chars'] ) : 25 ); ?>"
												class="small-text mr-tee-field-max-chars"
												data-field-index="<?php echo esc_attr( $idx ); ?>"
											/>
										</td>
									</tr>
									<tr>
										<th><span><?php esc_html_e( 'Options', 'mr-tee-editor' ); ?></span></th>
										<td class="mr-tee-options-cell">
											<span class="mr-tee-opt-text-only" <?php echo $is_image ? 'style="display:none;"' : ''; ?>>
												<label>
													<input type="hidden" name="mr_tee_config[text_lines][<?php echo esc_attr( $idx ); ?>][allow_font_pick]" value="0" />
													<input
														type="checkbox"
														name="mr_tee_config[text_lines][<?php echo esc_attr( $idx ); ?>][allow_font_pick]"
														value="1"
														class="mr-tee-field-font-pick"
														data-field-index="<?php echo esc_attr( $idx ); ?>"
														<?php checked( ! isset( $line['allow_font_pick'] ) || ! empty( $line['allow_font_pick'] ) ); ?>
													/>
													<?php esc_html_e( 'Choix de police client', 'mr-tee-editor' ); ?>
												</label>
												<label>
													<input type="hidden" name="mr_tee_config[text_lines][<?php echo esc_attr( $idx ); ?>][multiline]" value="0" />
													<input
														type="checkbox"
														name="mr_tee_config[text_lines][<?php echo esc_attr( $idx ); ?>][multiline]"
														value="1"
														class="mr-tee-field-multiline"
														data-field-index="<?php echo esc_attr( $idx ); ?>"
														<?php checked( ! empty( $line['multiline'] ) ); ?>
													/>
													<?php esc_html_e( 'Autoriser retours à la ligne', 'mr-tee-editor' ); ?>
												</label>
											</span>
											<span class="mr-tee-opt-image-only" <?php echo $is_image ? '' : 'style="display:none;"'; ?>>
												<label>
													<input type="hidden" name="mr_tee_config[text_lines][<?php echo esc_attr( $idx ); ?>][allow_image_upload]" value="0" />
													<input
														type="checkbox"
														name="mr_tee_config[text_lines][<?php echo esc_attr( $idx ); ?>][allow_image_upload]"
														value="1"
														class="mr-tee-field-allow-image-upload"
														data-field-index="<?php echo esc_attr( $idx ); ?>"
														<?php checked( $allow_upload ); ?>
													/>
													<?php esc_html_e( 'Import image par le client', 'mr-tee-editor' ); ?>
												</label>
											</span>
											<label>
												<input type="hidden" name="mr_tee_config[text_lines][<?php echo esc_attr( $idx ); ?>][warning_unedited]" value="0" />
												<input
													type="checkbox"
													name="mr_tee_config[text_lines][<?php echo esc_attr( $idx ); ?>][warning_unedited]"
													value="1"
													class="mr-tee-field-warning"
													data-field-index="<?php echo esc_attr( $idx ); ?>"
													<?php checked( ! empty( $line['warning_unedited'] ) ); ?>
												/>
												<?php esc_html_e( 'Alerter si non personnalisé', 'mr-tee-editor' ); ?>
											</label>
										</td>
									</tr>
								</table>

								<!-- Compact 2x2 Geometry Grid -->
								<div class="mr-tee-geom-section">
									<div class="mr-tee-geom-grid">
										<label class="mr-tee-geom-cell">
											<span><?php esc_html_e( 'X %', 'mr-tee-editor' ); ?></span>
											<input
												type="number"
												step="any"
												min="0"
												max="100"
												id="mr_tee_field_left_<?php echo esc_attr( $idx ); ?>"
												name="mr_tee_config[text_lines][<?php echo esc_attr( $idx ); ?>][box_left]"
												value="<?php echo esc_attr( $bl ); ?>"
												class="mr-tee-coord-input mr-tee-field-coord mr-tee-field-coord-left"
												data-field-index="<?php echo esc_attr( $idx ); ?>"
											/>
										</label>
										<label class="mr-tee-geom-cell">
											<span><?php esc_html_e( 'Y %', 'mr-tee-editor' ); ?></span>
											<input
												type="number"
												step="any"
												min="0"
												max="100"
												id="mr_tee_field_top_<?php echo esc_attr( $idx ); ?>"
												name="mr_tee_config[text_lines][<?php echo esc_attr( $idx ); ?>][box_top]"
												value="<?php echo esc_attr( $bt ); ?>"
												class="mr-tee-coord-input mr-tee-field-coord mr-tee-field-coord-top"
												data-field-index="<?php echo esc_attr( $idx ); ?>"
											/>
										</label>
										<label class="mr-tee-geom-cell">
											<span><?php esc_html_e( 'Largeur %', 'mr-tee-editor' ); ?></span>
											<input
												type="number"
												step="any"
												min="2"
												max="100"
												id="mr_tee_field_width_<?php echo esc_attr( $idx ); ?>"
												name="mr_tee_config[text_lines][<?php echo esc_attr( $idx ); ?>][box_width]"
												value="<?php echo esc_attr( $bw ); ?>"
												class="mr-tee-coord-input mr-tee-field-coord mr-tee-field-coord-width"
												data-field-index="<?php echo esc_attr( $idx ); ?>"
											/>
										</label>
										<label class="mr-tee-geom-cell">
											<span><?php esc_html_e( 'Hauteur %', 'mr-tee-editor' ); ?></span>
											<input
												type="number"
												step="any"
												min="2"
												max="100"
												id="mr_tee_field_height_<?php echo esc_attr( $idx ); ?>"
												name="mr_tee_config[text_lines][<?php echo esc_attr( $idx ); ?>][box_height]"
												value="<?php echo esc_attr( $bh ); ?>"
												class="mr-tee-coord-input mr-tee-field-coord mr-tee-field-coord-height"
												data-field-index="<?php echo esc_attr( $idx ); ?>"
											/>
										</label>
									</div>

									<!-- Compact Single-Row Rotation -->
									<div class="mr-tee-rotate-inline">
										<label for="mr_tee_field_rotate_<?php echo esc_attr( $idx ); ?>" class="mr-tee-rotate-label"><?php esc_html_e( 'Rotation °', 'mr-tee-editor' ); ?></label>
										<input
											type="number"
											step="1"
											min="-180"
											max="180"
											id="mr_tee_field_rotate_<?php echo esc_attr( $idx ); ?>"
											name="mr_tee_config[text_lines][<?php echo esc_attr( $idx ); ?>][box_rotate]"
											value="<?php echo esc_attr( $br ); ?>"
											class="mr-tee-coord-input mr-tee-field-coord mr-tee-field-coord-rotate"
											data-field-index="<?php echo esc_attr( $idx ); ?>"
										/>
										<div class="mr-tee-rotate-presets">
											<button type="button" class="button button-small mr-tee-btn-rotate-preset" data-field-index="<?php echo esc_attr( $idx ); ?>" data-deg="0">0°</button>
											<button type="button" class="button button-small mr-tee-btn-rotate-preset" data-field-index="<?php echo esc_attr( $idx ); ?>" data-deg="-90">-90°</button>
											<button type="button" class="button button-small mr-tee-btn-rotate-preset" data-field-index="<?php echo esc_attr( $idx ); ?>" data-deg="90">90°</button>
										</div>
									</div>
								</div>

								<div class="mr-tee-field-actions">
									<button
										type="button"
										class="button button-small mr-tee-btn-duplicate-field"
										data-field-index="<?php echo esc_attr( $idx ); ?>"
									>
										<svg class="mr-tee-icon" width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect x="9" y="9" width="13" height="13" rx="2" ry="2"/><path d="M5 15H4a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2h9a2 2 0 0 1 2 2v1"/></svg><?php esc_html_e( 'Dupliquer', 'mr-tee-editor' ); ?>
									</button>
									<button
										type="button"
										class="button button-small button-link-delete mr-tee-btn-delete-field"
										data-field-index="<?php echo esc_attr( $idx ); ?>"
										<?php echo count( $text_lines ) <= 1 ? 'style="display:none;"' : ''; ?>
									>
										<?php esc_html_e( 'Supprimer', 'mr-tee-editor' ); ?>
									</button>
								</div>
							</div>
						<?php endforeach; ?>
					</div>
				</div>
			</div>
		</div>
		<?php
	}
}
