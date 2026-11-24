<?php
/**
 * Mr. Tee Editor - Engine Selector & Product Configuration Metabox
 *
 * Implements the WooCommerce product-level engine selector:
 * - None (Standard WooCommerce Product)
 * - NBDesigner (Native NBDesigner Canvas & Toolbars)
 * - Mr. Tee Editor (Live In-Page Visual Configurator & SVG Preview Editor)
 *
 * @package MrTeeEditor\Admin
 */

namespace MrTeeEditor\Admin;

use MrTeeEditor\Domain\SceneGraphSchema;
use MrTeeEditor\Registry\FontRegistry;

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
		add_filter( 'use_block_editor_for_post_type', array( __CLASS__, 'disable_block_editor_for_products' ), 100, 2 );
	}

	/**
	 * Ensure WooCommerce products use the fast native Classic Product Editor even when a theme
	 * (such as Shoptimizer's `shoptimizer_activate_gutenberg_product`) overrides
	 * `WC_Post_Types::gutenberg_can_edit_post_type` at priority 10.
	 *
	 * @param bool   $can_edit  Whether the post type can be edited with the block editor.
	 * @param string $post_type Post type slug.
	 * @return bool
	 */
	public static function disable_block_editor_for_products( $can_edit, $post_type ) {
		if ( 'product' === $post_type ) {
			return (bool) apply_filters( 'mr_tee_use_block_editor_for_product', false, $can_edit );
		}
		return $can_edit;
	}

	/**
	 * Add the Mr. Tee Editor metabox to the product editing screen.
	 */
	public static function add_meta_boxes() {
		add_meta_box(
			'mr_tee_editor_config',
			__( 'Mr. Tee Editor — Configurateur Produit', 'mr-tee-editor' ),
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

		$config             = SceneGraphSchema::get_product_config( $post->ID, array( 'compile_scene_graph' => false ) );
		$engine             = ! empty( $config['engine'] ) ? $config['engine'] : 'none';
		$is_mr_tee          = ( 'mr_tee_editor' === $engine );
		$fonts              = FontRegistry::get_fonts();
		$allowed_fonts      = VisualPlacementStage::resolve_allowed_fonts( $config, $fonts );
		$thumb_id           = get_post_thumbnail_id( $post->ID );
		$thumb_url          = $thumb_id ? wp_get_attachment_image_url( $thumb_id, 'large' ) : ( function_exists( 'wc_placeholder_img_src' ) ? wc_placeholder_img_src() : '' );
		$thumb_url          = SceneGraphSchema::normalize_url_scheme( $thumb_url );
		$include_wc_gallery = isset( $config['include_wc_gallery_slides'] ) ? (bool) $config['include_wc_gallery_slides'] : true;
		$rep                = ! empty( $config['repeater'] ) && is_array( $config['repeater'] ) ? $config['repeater'] : array();
		$rep_enabled        = ! empty( $rep['enabled'] );

		$svg_font_count = 0;
		foreach ( $fonts as $f_data ) {
			if ( ( ! empty( $f_data['font_type'] ) && 'opentype_svg' === $f_data['font_type'] ) || ! empty( $f_data['color_svg'] ) ) {
				$svg_font_count++;
			}
		}

		$print_sides         = ! empty( $config['print_sides'] ) && is_array( $config['print_sides'] ) ? $config['print_sides'] : array();
		$side_export_metrics = ! empty( $config['side_export_metrics'] ) && is_array( $config['side_export_metrics'] ) ? $config['side_export_metrics'] : array();
		?>
		<div class="mr-tee-admin-wrap" data-product-id="<?php echo esc_attr( $post->ID ); ?>" data-thumb-url="<?php echo esc_url( $thumb_url ); ?>">
			<!-- Engine Mode Selector (Compact Segmented Strip when Mr. Tee Editor is active) -->
			<div class="mr-tee-engine-selector-box <?php echo $is_mr_tee ? 'is-compact' : ''; ?>" id="mr-tee-engine-selector-box">
				<div class="mr-tee-engine-header">
					<strong class="mr-tee-engine-title"><?php esc_html_e( 'Moteur de personnalisation', 'mr-tee-editor' ); ?></strong>
					<p class="mr-tee-engine-subtitle description">
						<?php esc_html_e( 'Choisissez le moteur actif pour ce produit WooCommerce.', 'mr-tee-editor' ); ?>
					</p>
				</div>
				<div class="mr-tee-engine-options">
					<label class="mr-tee-engine-option <?php echo 'none' === $engine ? 'is-selected' : ''; ?>">
						<input type="radio" name="mr_tee_engine_mode" value="none" <?php checked( $engine, 'none' ); ?> />
						<div class="mr-tee-engine-card">
							<span class="dashicons dashicons-products"></span>
							<strong><?php esc_html_e( 'Aucun (Standard)', 'mr-tee-editor' ); ?></strong>
							<span class="mr-tee-engine-card-desc"><?php esc_html_e( 'Produit WooCommerce simple sans personnalisateur', 'mr-tee-editor' ); ?></span>
						</div>
					</label>

					<label class="mr-tee-engine-option <?php echo 'nbdesigner' === $engine ? 'is-selected' : ''; ?>">
						<input type="radio" name="mr_tee_engine_mode" value="nbdesigner" <?php checked( $engine, 'nbdesigner' ); ?> />
						<div class="mr-tee-engine-card">
							<span class="dashicons dashicons-art"></span>
							<strong><?php esc_html_e( 'NBDesigner', 'mr-tee-editor' ); ?></strong>
							<span class="mr-tee-engine-card-desc"><?php esc_html_e( 'Éditeur studio NBDesigner natif', 'mr-tee-editor' ); ?></span>
						</div>
					</label>

					<label class="mr-tee-engine-option <?php echo $is_mr_tee ? 'is-selected' : ''; ?>">
						<input type="radio" name="mr_tee_engine_mode" value="mr_tee_editor" <?php checked( $engine, 'mr_tee_editor' ); ?> />
						<div class="mr-tee-engine-card">
							<span class="dashicons dashicons-edit"></span>
							<strong><?php esc_html_e( 'Mr. Tee Editor', 'mr-tee-editor' ); ?></strong>
							<span class="mr-tee-engine-card-desc"><?php esc_html_e( 'Studio visuel & aperçu en direct sur la fiche produit', 'mr-tee-editor' ); ?></span>
						</div>
					</label>
				</div>
			</div>

			<!-- Mr. Tee Editor Configuration Settings (Disabled & Hidden when engine !== mr_tee_editor) -->
			<div id="mr-tee-settings-panel" class="mr-tee-settings-panel" style="<?php echo $is_mr_tee ? '' : 'display:none;'; ?>">
				<fieldset id="mr-tee-settings-fieldset" class="mr-tee-settings-fieldset" <?php disabled( ! $is_mr_tee ); ?>>
					<!-- Compact Top Navigation Row: 3 Tabs + Inline Preset Loader -->
					<div class="mr-tee-topbar">
						<div class="mr-tee-tabs-nav" role="tablist">
							<button type="button" class="mr-tee-tab-btn is-active" data-tab="tab-stage" role="tab" aria-selected="true">
								<span aria-hidden="true">🎨</span> <?php esc_html_e( 'Studio Visuel', 'mr-tee-editor' ); ?>
							</button>
							<button type="button" class="mr-tee-tab-btn" data-tab="tab-fonts" role="tab" aria-selected="false">
								<span aria-hidden="true">🔤</span> <?php esc_html_e( 'Polices', 'mr-tee-editor' ); ?>
								<span class="mr-tee-tab-count" id="mr-tee-tab-fonts-badge"><?php echo esc_html( count( $allowed_fonts ) ); ?></span>
							</button>
							<button type="button" class="mr-tee-tab-btn" data-tab="tab-export" role="tab" aria-selected="false">
								<span aria-hidden="true">⚙️</span> <?php esc_html_e( 'Export & Avancé', 'mr-tee-editor' ); ?>
							</button>
						</div>

						<div class="mr-tee-preset-bar">
							<label for="mr_tee_template_preset" class="mr-tee-preset-label"><?php esc_html_e( 'Modèle de départ :', 'mr-tee-editor' ); ?></label>
							<select name="mr_tee_config[template_preset]" id="mr_tee_template_preset" class="mr-tee-preset-select">
								<?php foreach ( SceneGraphSchema::get_presets() as $p_id => $preset ) : ?>
									<option value="<?php echo esc_attr( $p_id ); ?>" <?php selected( isset( $config['template_preset'] ) ? $config['template_preset'] : 'super_mushroom_24987', $p_id ); ?>><?php echo esc_html( $preset['name'] ); ?></option>
								<?php endforeach; ?>
							</select>
							<button type="button" class="button button-small mr-tee-btn-apply-preset" id="mr_tee_btn_apply_preset">
								<?php esc_html_e( 'Appliquer ce modèle', 'mr-tee-editor' ); ?>
							</button>
							<input type="hidden" name="mr_tee_apply_preset" id="mr_tee_apply_preset" value="0" />
						</div>
					</div>

					<!-- TAB 1: Visual Studio (Canvas Immediately Above the Fold) -->
					<div id="tab-stage" class="mr-tee-tab-pane is-active">
						<?php VisualPlacementStage::render_stage( $config, $thumb_url ); ?>
					</div>

					<!-- TAB 2: All Font Management (Allowed Fonts + Uploader + Full Library) -->
					<div id="tab-fonts" class="mr-tee-tab-pane">
						<!-- 2A. Allowed Fonts & Display Order -->
						<div class="mr-tee-allowed-fonts-card" id="mr-tee-allowed-fonts-card">
							<div class="mr-tee-allowed-fonts-header">
								<div>
									<h4 class="mr-tee-allowed-fonts-title">
										<span aria-hidden="true">🔤</span> <?php esc_html_e( 'Polices Autorisées & Ordre d\'Affichage', 'mr-tee-editor' ); ?>
									</h4>
									<p class="mr-tee-allowed-fonts-desc">
										<?php esc_html_e( 'Seules les polices listées ci-dessous sont chargées sur cette fiche produit pour un affichage rapide. La police #1 est utilisée par défaut pour les nouveaux champs.', 'mr-tee-editor' ); ?>
									</p>
								</div>
								<div class="mr-tee-allowed-fonts-stats">
									<span id="mr-tee-allowed-fonts-count"><?php echo esc_html( count( $allowed_fonts ) ); ?></span> <?php esc_html_e( 'police(s) active(s)', 'mr-tee-editor' ); ?>
								</div>
							</div>

							<div class="mr-tee-allowed-fonts-list" id="mr-tee-allowed-fonts-list">
								<?php foreach ( $allowed_fonts as $order_idx => $af_id ) : ?>
									<?php
									$f_info      = isset( $fonts[ $af_id ] ) ? $fonts[ $af_id ] : array( 'name' => $af_id, 'font_type' => 'ttf' );
									$is_svg      = ( ! empty( $f_info['font_type'] ) && 'opentype_svg' === $f_info['font_type'] ) || ! empty( $f_info['color_svg'] );
									$is_default  = ( 0 === $order_idx );
									$f_thumb_url = ! empty( $f_info['thumb_url'] ) ? SceneGraphSchema::normalize_url_scheme( $f_info['thumb_url'] ) : '';
									?>
									<div class="mr-tee-allowed-font-item <?php echo $is_default ? 'is-default-font' : ''; ?>" data-font-id="<?php echo esc_attr( $af_id ); ?>">
										<div class="mr-tee-allowed-font-info">
											<span class="mr-tee-font-order-badge <?php echo $is_default ? 'is-default' : 'is-option'; ?>">
												<?php echo $is_default ? esc_html__( '#1 DÉFAUT', 'mr-tee-editor' ) : esc_html( '#' . ( $order_idx + 1 ) . ' OPTION' ); ?>
											</span>
											<span class="mr-tee-font-type-pill <?php echo $is_svg ? 'is-svg' : 'is-ttf'; ?>">
												<?php echo $is_svg ? 'SVG 3D' : 'TTF'; ?>
											</span>
											<?php if ( ! empty( $f_thumb_url ) ) : ?>
												<img src="<?php echo esc_url( $f_thumb_url ); ?>" alt="" loading="lazy" decoding="async" class="mr-tee-font-mini-thumb" />
											<?php endif; ?>
											<strong class="mr-tee-font-name-label"><?php echo esc_html( $f_info['name'] ); ?></strong>
										</div>
										<div class="mr-tee-allowed-font-actions">
											<button type="button" class="mr-tee-btn-font-action mr-tee-btn-font-up" title="<?php esc_attr_e( 'Monter', 'mr-tee-editor' ); ?>" <?php echo $is_default ? 'disabled' : ''; ?>>▲</button>
											<button type="button" class="mr-tee-btn-font-action mr-tee-btn-font-down" title="<?php esc_attr_e( 'Descendre', 'mr-tee-editor' ); ?>" <?php echo ( $order_idx === count( $allowed_fonts ) - 1 ) ? 'disabled' : ''; ?>>▼</button>
											<button type="button" class="mr-tee-btn-font-action mr-tee-btn-font-delete" title="<?php esc_attr_e( 'Retirer cette police', 'mr-tee-editor' ); ?>" <?php echo ( count( $allowed_fonts ) <= 1 ) ? 'disabled' : ''; ?>>✕</button>
										</div>
									</div>
								<?php endforeach; ?>
							</div>

							<input type="hidden" name="mr_tee_config[allowed_fonts]" id="mr_tee_allowed_fonts_json" value="<?php echo esc_attr( wp_json_encode( array_values( $allowed_fonts ) ) ); ?>" />

							<div class="mr-tee-allowed-fonts-toolbar">
								<div class="mr-tee-add-font-controls">
									<select id="mr_tee_add_font_picker" class="mr-tee-add-font-picker">
										<option value=""><?php esc_html_e( '-- Choisir une police à ajouter --', 'mr-tee-editor' ); ?></option>
										<optgroup label="<?php esc_attr_e( '✨ Polices OpenType-SVG', 'mr-tee-editor' ); ?>">
											<?php foreach ( $fonts as $f_id => $f_data ) : ?>
												<?php $is_svg_picker = ( ! empty( $f_data['font_type'] ) && 'opentype_svg' === $f_data['font_type'] ) || ! empty( $f_data['color_svg'] ); ?>
												<?php if ( $is_svg_picker ) : ?>
													<?php $in_allowed = in_array( $f_id, $allowed_fonts, true ); ?>
													<option value="<?php echo esc_attr( $f_id ); ?>" <?php echo $in_allowed ? 'disabled' : ''; ?>><?php echo esc_html( trim( $f_data['name'] ) . ( $in_allowed ? ' (déjà active)' : '' ) ); ?></option>
												<?php endif; ?>
											<?php endforeach; ?>
										</optgroup>
										<optgroup label="<?php esc_attr_e( '🔤 Polices Stylisées TTF', 'mr-tee-editor' ); ?>">
											<?php foreach ( $fonts as $f_id => $f_data ) : ?>
												<?php $is_svg_picker = ( ! empty( $f_data['font_type'] ) && 'opentype_svg' === $f_data['font_type'] ) || ! empty( $f_data['color_svg'] ); ?>
												<?php if ( ! $is_svg_picker ) : ?>
													<?php $in_allowed = in_array( $f_id, $allowed_fonts, true ); ?>
													<option value="<?php echo esc_attr( $f_id ); ?>" <?php echo $in_allowed ? 'disabled' : ''; ?>><?php echo esc_html( trim( $f_data['name'] ) . ( $in_allowed ? ' (déjà active)' : '' ) ); ?></option>
												<?php endif; ?>
											<?php endforeach; ?>
										</optgroup>
									</select>
									<button type="button" class="button button-primary mr-tee-btn-add-allowed-font" id="mr_tee_btn_add_allowed_font">
										+ <?php esc_html_e( 'Ajouter', 'mr-tee-editor' ); ?>
									</button>
								</div>

								<div class="mr-tee-allowed-font-presets">
									<span class="mr-tee-fast-label"><?php esc_html_e( 'Raccourcis :', 'mr-tee-editor' ); ?></span>
									<button type="button" class="button button-small" id="mr_tee_preset_only_first" title="<?php esc_attr_e( 'Conserver uniquement la police #1', 'mr-tee-editor' ); ?>">
										🎯 <?php esc_html_e( 'Police #1 Seule', 'mr-tee-editor' ); ?>
									</button>
									<button type="button" class="button button-small" id="mr_tee_preset_all_svg" title="<?php esc_attr_e( 'Sélectionner toutes les polices OpenType-SVG', 'mr-tee-editor' ); ?>">
										✨ <span id="mr-tee-svg-fonts-total-count"><?php echo esc_html( $svg_font_count ); ?></span> <?php esc_html_e( 'Polices OpenType-SVG', 'mr-tee-editor' ); ?>
									</button>
									<button type="button" class="button button-small" id="mr_tee_preset_all_fonts" title="<?php esc_attr_e( 'Activer toutes les polices de la boutique', 'mr-tee-editor' ); ?>">
										🔤 <?php esc_html_e( 'Toutes les polices', 'mr-tee-editor' ); ?>
									</button>
								</div>
							</div>
						</div>

						<!-- 2B. Font Importer (.ttf, .otf, .zip) -->
						<div class="mr-tee-fonts-library-panel">
							<div class="mr-tee-font-upload-card">
								<span class="dashicons dashicons-cloud-upload mr-tee-upload-icon"></span>
								<h4><?php esc_html_e( 'Importer une Nouvelle Police (.ttf, .otf, .zip)', 'mr-tee-editor' ); ?></h4>
								<p class="description">
									<?php esc_html_e( 'Glissez-déposez ou sélectionnez une police standard (TTF/OTF) ou OpenType-SVG couleur. Les nouvelles polices sont automatiquement ajoutées aux polices autorisées de ce produit.', 'mr-tee-editor' ); ?>
								</p>
								<div class="mr-tee-upload-controls">
									<input type="file" id="mr_tee_tab2_font_input" accept=".ttf,.otf,.zip" style="display:none;" />
									<button type="button" class="button button-primary" id="mr_tee_tab2_btn_select">
										📤 <?php esc_html_e( 'Choisir un fichier (.ttf, .otf, .zip)', 'mr-tee-editor' ); ?>
									</button>
									<span id="mr_tee_tab2_file_name" class="mr-tee-upload-filename"></span>
									<button type="button" class="button button-secondary" id="mr_tee_tab2_btn_upload" style="display:none;">
										⚡ <?php esc_html_e( 'Analyser et Enregistrer', 'mr-tee-editor' ); ?>
									</button>
								</div>
								<div id="mr_tee_tab2_upload_status" class="mr-tee-upload-status"></div>
							</div>

							<!-- 2C. Full Registered Font Library Grid (Both OpenType-SVG & TTF) -->
							<div class="mr-tee-library-header">
								<h4><?php esc_html_e( 'Bibliothèque Complète de Polices', 'mr-tee-editor' ); ?> (<span id="mr-tee-library-total-count"><?php echo esc_html( count( $fonts ) ); ?></span>)</h4>
								<button type="button" class="button button-small" id="mr-tee-btn-back-to-studio">
									← <?php esc_html_e( 'Retour au Studio Visuel', 'mr-tee-editor' ); ?>
								</button>
							</div>
							<div class="mr-tee-fonts-library-grid mr-tee-registered-svg-fonts-grid" id="mr-tee-fonts-library-grid">
								<?php foreach ( $fonts as $f_id => $f_data ) : ?>
									<?php
									$is_svg_card   = ( ! empty( $f_data['font_type'] ) && 'opentype_svg' === $f_data['font_type'] ) || ! empty( $f_data['color_svg'] );
									$is_custom     = ! empty( $f_data['is_custom'] );
									$card_thumb    = ! empty( $f_data['thumb_url'] ) ? SceneGraphSchema::normalize_url_scheme( $f_data['thumb_url'] ) : '';
									$card_family   = ! empty( $f_data['family'] ) ? $f_data['family'] : 'sans-serif';
									?>
									<div class="mr-tee-font-lib-card <?php echo $is_svg_card ? 'is-svg' : 'is-ttf'; ?>" data-font-id="<?php echo esc_attr( $f_id ); ?>">
										<div class="mr-tee-font-lib-preview">
											<?php if ( ! empty( $card_thumb ) ) : ?>
												<img src="<?php echo esc_url( $card_thumb ); ?>" alt="" loading="lazy" decoding="async" />
											<?php else : ?>
												<span class="mr-tee-font-lib-sample" style="font-family: '<?php echo esc_attr( $card_family ); ?>', sans-serif;">Aa</span>
											<?php endif; ?>
										</div>
										<div class="mr-tee-font-lib-meta">
											<strong><?php echo esc_html( $f_data['name'] ); ?></strong>
											<span class="mr-tee-font-lib-file"><?php echo esc_html( ! empty( $f_data['file'] ) ? $f_data['file'] : $f_id ); ?></span>
										</div>
										<div class="mr-tee-font-lib-badges">
											<span class="mr-tee-font-type-pill <?php echo $is_svg_card ? 'is-svg' : 'is-ttf'; ?>">
												<?php echo $is_svg_card ? 'SVG' : 'TTF'; ?>
											</span>
											<?php if ( $is_custom ) : ?>
												<button
													type="button"
													class="mr-tee-btn-delete-custom-font"
													data-font-id="<?php echo esc_attr( $f_id ); ?>"
													data-font-name="<?php echo esc_attr( $f_data['name'] ); ?>"
													title="<?php esc_attr_e( 'Supprimer définitivement cette police personnalisée', 'mr-tee-editor' ); ?>"
												>✕</button>
											<?php endif; ?>
										</div>
									</div>
								<?php endforeach; ?>
							</div>
						</div>
					</div>

					<!-- TAB 3: Export, Gallery, NBDesigner Bridge & Advanced Repeater -->
					<div id="tab-export" class="mr-tee-tab-pane">
						<!-- 3A. Read-Only Summary of Configured Print Sides & Export Resolutions -->
						<div class="mr-tee-export-card">
							<div class="mr-tee-export-card-header">
								<h4><?php esc_html_e( 'Résumé des Faces d\'Impression & Résolutions', 'mr-tee-editor' ); ?></h4>
								<span class="description"><?php esc_html_e( 'Modifiez les dimensions physiques et la résolution DPI depuis le Studio Visuel (⚙ Format & Dimensions).', 'mr-tee-editor' ); ?></span>
							</div>
							<div id="mr-tee-export-sides-summary" class="mr-tee-export-sides-summary">
								<?php foreach ( $print_sides as $side ) : ?>
									<?php
									$sid     = ! empty( $side['id'] ) ? $side['id'] : 'front';
									$metrics = isset( $side_export_metrics[ $sid ] ) ? $side_export_metrics[ $sid ] : null;
									$s_type  = ( isset( $side['product_type'] ) && 'flat_object' === $side['product_type'] )
										? __( 'Objet plat', 'mr-tee-editor' )
										: __( 'Vêtement', 'mr-tee-editor' );
									$s_unit  = ! empty( $side['print_unit'] ) ? $side['print_unit'] : 'cm';
									$s_dpi   = isset( $side['dpi'] ) ? intval( $side['dpi'] ) : 300;
									?>
									<div class="mr-tee-export-side-row">
										<div class="mr-tee-export-side-name">
											<strong><?php echo esc_html( ! empty( $side['label'] ) ? $side['label'] : $sid ); ?></strong>
											<span class="mr-tee-export-type-tag"><?php echo esc_html( $s_type ); ?></span>
										</div>
										<div class="mr-tee-export-side-specs">
											<span><?php echo esc_html( sprintf( '%s × %s %s', $side['print_real_width'], $side['print_real_height'], $s_unit ) ); ?></span>
											<span>·</span>
											<span><?php echo esc_html( $s_dpi . ' DPI' ); ?></span>
											<?php if ( $metrics ) : ?>
												<span>·</span>
												<strong class="mr-tee-export-px"><?php echo esc_html( $metrics['print_real_width'] . ' × ' . $metrics['print_real_height'] . ' ' . $s_unit . ' (' . $metrics['width_px'] . ' × ' . $metrics['height_px'] . ' px)' ); ?></strong>
											<?php endif; ?>
										</div>
									</div>
								<?php endforeach; ?>
							</div>
						</div>

						<!-- 3B. Storefront Gallery & NBDesigner Bridge -->
						<div class="mr-tee-export-card">
							<h4><?php esc_html_e( 'Passerelle NBDesigner & Galerie Storefront', 'mr-tee-editor' ); ?></h4>
							<table class="form-table mr-tee-compact-table">
								<tr>
									<th><label for="mr_tee_nbd_handshake"><?php esc_html_e( 'Bridge NBDesigner', 'mr-tee-editor' ); ?></label></th>
									<td>
										<label>
											<input type="hidden" name="mr_tee_config[nbd_handshake]" value="0" />
											<input type="checkbox" id="mr_tee_nbd_handshake" name="mr_tee_config[nbd_handshake]" value="1" <?php checked( ! empty( $config['nbd_handshake'] ) ); ?> />
											<?php esc_html_e( 'Générer les artefacts NBDesigner à la commande (frame_0.png, frame_0.svg, config.json, option.json, nbd_data)', 'mr-tee-editor' ); ?>
										</label>
									</td>
								</tr>
								<tr>
									<th><label for="mr_tee_include_wc_gallery"><?php esc_html_e( 'Galerie Storefront', 'mr-tee-editor' ); ?></label></th>
									<td>
										<label>
											<input type="hidden" name="mr_tee_config[include_wc_gallery_slides]" value="0" />
											<input
												type="checkbox"
												id="mr_tee_include_wc_gallery"
												name="mr_tee_config[include_wc_gallery_slides]"
												value="1"
												<?php checked( $include_wc_gallery ); ?>
											/>
											<?php esc_html_e( 'Inclure les autres photos de la galerie WooCommerce après les vues de personnalisation', 'mr-tee-editor' ); ?>
										</label>
									</td>
								</tr>
							</table>
						</div>

						<!-- 3C. Advanced Module: 2-Row Avatar / Icon Repeater -->
						<div class="mr-tee-export-card">
							<div class="mr-tee-repeater-header">
								<div>
									<h4><?php esc_html_e( 'Module Avancé : Grille d\'Avatars / Icônes (Répéteur 2 Rangées)', 'mr-tee-editor' ); ?></h4>
									<p class="description">
										<?php esc_html_e( 'Permet au client d\'ajouter de 0 à 6 icônes avec prénoms disposées automatiquement sur 2 rangées centrées (Rangée 1 : 1–3, Rangée 2 : 4–6).', 'mr-tee-editor' ); ?>
									</p>
								</div>
								<label class="mr-tee-toggle-pill">
									<input type="hidden" name="mr_tee_config[repeater][enabled]" value="0" />
									<input type="checkbox" id="mr_tee_repeater_enabled" name="mr_tee_config[repeater][enabled]" value="1" <?php checked( $rep_enabled ); ?> />
									<span><?php esc_html_e( 'Activer ce module', 'mr-tee-editor' ); ?></span>
								</label>
							</div>

							<div id="mr-tee-repeater-body" style="<?php echo $rep_enabled ? '' : 'display:none;'; ?>">
								<table class="form-table mr-tee-compact-table">
									<tr>
										<th><label for="mr_tee_rep_label"><?php esc_html_e( 'Titre du sélecteur', 'mr-tee-editor' ); ?></label></th>
										<td><input type="text" id="mr_tee_rep_label" name="mr_tee_config[repeater][label]" value="<?php echo esc_attr( isset( $rep['label'] ) ? $rep['label'] : '' ); ?>" class="regular-text" /></td>
									</tr>
									<tr>
										<th><label><?php esc_html_e( 'Nombre par défaut / Max', 'mr-tee-editor' ); ?></label></th>
										<td>
											<label><?php esc_html_e( 'Par défaut :', 'mr-tee-editor' ); ?> <input type="number" name="mr_tee_config[repeater][default_count]" value="<?php echo esc_attr( isset( $rep['default_count'] ) ? max( 0, min( 6, intval( $rep['default_count'] ) ) ) : 0 ); ?>" min="0" max="6" step="1" class="small-text" /></label>
											&nbsp;&nbsp;
											<label><?php esc_html_e( 'Maximum :', 'mr-tee-editor' ); ?> <input type="number" name="mr_tee_config[repeater][max_items]" value="<?php echo esc_attr( isset( $rep['max_items'] ) ? max( 0, min( 6, intval( $rep['max_items'] ) ) ) : 0 ); ?>" min="0" max="6" step="1" class="small-text" /></label>
										</td>
									</tr>
									<tr>
										<th><label><?php esc_html_e( 'Position verticale Rangée 1 / 2 (%)', 'mr-tee-editor' ); ?></label></th>
										<td>
											<label><?php esc_html_e( 'Rangée 1 Y :', 'mr-tee-editor' ); ?> <input type="number" step="any" min="0" max="100" name="mr_tee_config[repeater][row1_y_pct]" value="<?php echo esc_attr( isset( $rep['row1_y_pct'] ) ? $rep['row1_y_pct'] : 50.2 ); ?>" class="small-text" /> %</label>
											&nbsp;&nbsp;
											<label><?php esc_html_e( 'Rangée 2 Y :', 'mr-tee-editor' ); ?> <input type="number" step="any" min="0" max="100" name="mr_tee_config[repeater][row2_y_pct]" value="<?php echo esc_attr( isset( $rep['row2_y_pct'] ) ? $rep['row2_y_pct'] : 73.2 ); ?>" class="small-text" /> %</label>
										</td>
									</tr>
									<tr>
										<th><label><?php esc_html_e( 'Dimensions des icônes (%)', 'mr-tee-editor' ); ?></label></th>
										<td>
											<label><?php esc_html_e( 'Largeur :', 'mr-tee-editor' ); ?> <input type="number" step="any" min="1" max="100" name="mr_tee_config[repeater][icon_width]" value="<?php echo esc_attr( isset( $rep['icon_width'] ) ? $rep['icon_width'] : 20 ); ?>" class="small-text" /> %</label>
											&nbsp;&nbsp;
											<label><?php esc_html_e( 'Hauteur :', 'mr-tee-editor' ); ?> <input type="number" step="any" min="1" max="100" name="mr_tee_config[repeater][icon_height]" value="<?php echo esc_attr( isset( $rep['icon_height'] ) ? $rep['icon_height'] : 20 ); ?>" class="small-text" /> %</label>
										</td>
									</tr>
								</table>
							</div>
						</div>
					</div>
				</fieldset>
			</div>
		</div>
		<?php
	}

	/**
	 * Strip runtime-only keys so `_mr_tee_config` postmeta stays lean and free of duplicated registries.
	 *
	 * @param array<string, mixed> $config Configuration array to prune.
	 * @return array<string, mixed>
	 */
	private static function strip_runtime_keys( array $config ) {
		unset(
			$config['fonts'],
			$config['icons'],
			$config['color_sets'],
			$config['solid_colors'],
			$config['perso_visual'],
			$config['side_export_metrics'],
			$config['cumulative_design_area']
		);
		return $config;
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
		if ( ! in_array( $engine, array( 'none', 'nbdesigner', 'mr_tee_editor' ), true ) ) {
			$engine = 'none';
		}

		update_post_meta( $post_id, '_mr_tee_engine_mode', $engine );
		if ( 'nbdesigner' === $engine ) {
			update_post_meta( $post_id, '_nbdesigner_enable', '1' );
		} else {
			delete_post_meta( $post_id, '_nbdesigner_enable' );
		}

		// Guard: never persist `_mr_tee_config` when saving a standard or NBDesigner product
		if ( 'mr_tee_editor' !== $engine ) {
			SceneGraphSchema::clear_product_config_cache( $post_id );
			return;
		}

		if ( ! isset( $_POST['mr_tee_config'] ) || ! is_array( $_POST['mr_tee_config'] ) ) {
			SceneGraphSchema::clear_product_config_cache( $post_id );
			return;
		}

		$raw_config   = wp_unslash( $_POST['mr_tee_config'] ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		$apply_preset = isset( $_POST['mr_tee_apply_preset'] ) && '1' === sanitize_text_field( wp_unslash( $_POST['mr_tee_apply_preset'] ) );
		$new_preset   = isset( $raw_config['template_preset'] ) ? sanitize_text_field( $raw_config['template_preset'] ) : 'super_mushroom_24987';

		// Explicit Preset Apply: replace cleanly with default preset config without overwriting with stale DOM fields
		if ( $apply_preset ) {
			$preset_config           = SceneGraphSchema::get_default_config( $new_preset );
			$preset_config['engine'] = 'mr_tee_editor';
			$preset_config           = self::strip_runtime_keys( $preset_config );
			update_post_meta( $post_id, '_mr_tee_config', $preset_config );
			SceneGraphSchema::clear_product_config_cache( $post_id );
			return;
		}

		$config = SceneGraphSchema::get_product_config( $post_id, array( 'compile_scene_graph' => false ) );

		if ( isset( $raw_config['template_preset'] ) ) {
			$config['template_preset'] = $new_preset;
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

		// Parse toolbar physical dimensions as fallback defaults for side 0
		$toolbar_prod_type = isset( $raw_config['product_type'] ) ? sanitize_key( $raw_config['product_type'] ) : ( ! empty( $config['product_type'] ) ? sanitize_key( $config['product_type'] ) : 'garment' );
		if ( ! in_array( $toolbar_prod_type, array( 'garment', 'flat_object' ), true ) ) {
			$toolbar_prod_type = 'garment';
		}
		$toolbar_unit = isset( $raw_config['print_unit'] ) ? sanitize_key( $raw_config['print_unit'] ) : ( ! empty( $config['print_unit'] ) ? sanitize_key( $config['print_unit'] ) : 'cm' );
		if ( ! in_array( $toolbar_unit, array( 'cm', 'mm', 'in' ), true ) ) {
			$toolbar_unit = 'cm';
		}
		$toolbar_real_w = isset( $raw_config['print_real_width'] ) ? max( 0.1, floatval( $raw_config['print_real_width'] ) ) : ( isset( $config['print_real_width'] ) ? max( 0.1, floatval( $config['print_real_width'] ) ) : 30.0 );
		$toolbar_real_h = isset( $raw_config['print_real_height'] ) ? max( 0.1, floatval( $raw_config['print_real_height'] ) ) : ( isset( $config['print_real_height'] ) ? max( 0.1, floatval( $config['print_real_height'] ) ) : 40.0 );
		$toolbar_dpi    = isset( $raw_config['dpi'] ) ? max( 72, intval( $raw_config['dpi'] ) ) : ( isset( $config['dpi'] ) ? max( 72, intval( $config['dpi'] ) ) : 300 );
		$toolbar_lock   = ! empty( $raw_config['lock_aspect_ratio'] ) || ( 'flat_object' === $toolbar_prod_type );

		$fallback_side_dims = array(
			'print_real_width'  => $toolbar_real_w,
			'print_real_height' => $toolbar_real_h,
			'print_unit'        => $toolbar_unit,
			'dpi'               => $toolbar_dpi,
			'product_type'      => $toolbar_prod_type,
			'lock_aspect_ratio' => $toolbar_lock,
		);

		// Parse & sanitize print_sides (JSON string or array), ensuring at least 1 side exists
		if ( isset( $raw_config['print_sides'] ) && '' !== $raw_config['print_sides'] && array() !== $raw_config['print_sides'] ) {
			$print_sides = SceneGraphSchema::normalize_print_sides( $raw_config['print_sides'], $fallback_side_dims );
		} else {
			$existing_sides                      = ! empty( $config['print_sides'] ) ? $config['print_sides'] : array();
			$print_sides                         = SceneGraphSchema::normalize_print_sides( $existing_sides, $fallback_side_dims );
			$print_sides[0]['print_real_width']  = $toolbar_real_w;
			$print_sides[0]['print_real_height'] = $toolbar_real_h;
			$print_sides[0]['print_unit']        = $toolbar_unit;
			$print_sides[0]['dpi']               = $toolbar_dpi;
			$print_sides[0]['product_type']      = $toolbar_prod_type;
			$print_sides[0]['lock_aspect_ratio'] = $toolbar_lock;
		}

		$config['print_sides'] = $print_sides;
		$valid_side_ids        = array_column( $print_sides, 'id' );
		$default_side_id       = $print_sides[0]['id'];

		// Sync top-level physical dimensions from side 0
		$primary_side                = $print_sides[0];
		$config['product_type']      = $primary_side['product_type'];
		$config['print_unit']        = $primary_side['print_unit'];
		$config['print_real_width']  = $primary_side['print_real_width'];
		$config['print_real_height'] = $primary_side['print_real_height'];
		$config['dpi']               = $primary_side['dpi'];
		$config['lock_aspect_ratio'] = $primary_side['lock_aspect_ratio'];

		// Parse & sanitize preview_views (JSON string or array)
		$thumb_id     = (int) get_post_thumbnail_id( $post_id );
		$featured_url = $thumb_id ? ( wp_get_attachment_image_url( $thumb_id, 'large' ) ?: wp_get_attachment_image_url( $thumb_id, 'full' ) ) : '';
		if ( ! is_string( $featured_url ) ) {
			$featured_url = '';
		}
		if ( isset( $raw_config['preview_views'] ) && '' !== $raw_config['preview_views'] && array() !== $raw_config['preview_views'] ) {
			$preview_views = SceneGraphSchema::normalize_preview_views( $raw_config['preview_views'], $print_sides, $thumb_id, $featured_url );
		} else {
			$existing_views = ! empty( $config['preview_views'] ) ? $config['preview_views'] : array();
			$preview_views  = SceneGraphSchema::normalize_preview_views( $existing_views, $print_sides, $thumb_id, $featured_url );
		}
		$config['preview_views'] = $preview_views;

		// Sanitize WooCommerce gallery inclusion toggle
		if ( isset( $raw_config['include_wc_gallery_slides'] ) ) {
			$config['include_wc_gallery_slides'] = ! empty( $raw_config['include_wc_gallery_slides'] );
		} elseif ( ! isset( $config['include_wc_gallery_slides'] ) ) {
			$config['include_wc_gallery_slides'] = true;
		}

		// Sanitize fields (supports adding/deleting/reordering dynamic text & image fields and per-line rectangles)
		if ( isset( $raw_config['text_lines'] ) && is_array( $raw_config['text_lines'] ) ) {
			$existing_by_id = array();
			if ( ! empty( $config['text_lines'] ) && is_array( $config['text_lines'] ) ) {
				foreach ( $config['text_lines'] as $ex_line ) {
					if ( ! empty( $ex_line['id'] ) ) {
						$existing_by_id[ (string) $ex_line['id'] ] = $ex_line;
					}
				}
			}

			$sanitized_lines = array();
			$used_ids        = array();
			$used_ref_ids    = array();
			$line_index      = 0;

			foreach ( $raw_config['text_lines'] as $idx => $line_data ) {
				if ( ! is_array( $line_data ) ) {
					continue;
				}

				$line_num   = $line_index + 1;
				$sub_id     = isset( $line_data['id'] ) ? preg_replace( '/[^a-zA-Z0-9_-]/', '', (string) $line_data['id'] ) : '';
				$sub_ref_id = isset( $line_data['ref_id'] ) ? preg_replace( '/[^a-zA-Z0-9_-]/', '', (string) $line_data['ref_id'] ) : '';
				$sub_cref   = isset( $line_data['color_ref_id'] ) ? preg_replace( '/[^a-zA-Z0-9_-]/', '', (string) $line_data['color_ref_id'] ) : '';

				if ( '' !== $sub_id && isset( $existing_by_id[ $sub_id ] ) ) {
					$existing = $existing_by_id[ $sub_id ];
				} elseif ( '' === $sub_id && isset( $config['text_lines'][ $idx ] ) ) {
					$existing = $config['text_lines'][ $idx ];
				} else {
					$existing = array();
				}

				$field_type    = ( isset( $line_data['field_type'] ) && 'image' === $line_data['field_type'] ) ? 'image' : 'text';
				$existing_type = ( isset( $existing['field_type'] ) && 'image' === $existing['field_type'] ) ? 'image' : 'text';

				$field_id = '' !== $sub_id ? $sub_id : ( ! empty( $existing['id'] ) ? $existing['id'] : 'line_' . $line_num );
				while ( isset( $used_ids[ $field_id ] ) ) {
					$field_id .= '_' . $line_num;
				}
				$used_ids[ $field_id ] = true;

				$default_ref_prefix = ( 'image' === $field_type ) ? 'id-ref-image-line-' : 'id-ref-text-line-';
				$ref_id             = '' !== $sub_ref_id ? $sub_ref_id : ( ! empty( $existing['ref_id'] ) ? $existing['ref_id'] : $default_ref_prefix . $line_num );
				while ( isset( $used_ref_ids[ $ref_id ] ) ) {
					$ref_id .= '_' . $line_num;
				}
				$used_ref_ids[ $ref_id ] = true;

				// Preserve intentionally shared color_ref_id (e.g. vintage_date_names_34359's 'id-ref-color-shared-1')
				$color_ref_id = '' !== $sub_cref ? $sub_cref : ( ! empty( $existing['color_ref_id'] ) ? $existing['color_ref_id'] : 'id-ref-color-line-' . $line_num );

				$label   = isset( $line_data['label'] ) ? sanitize_text_field( $line_data['label'] ) : ( isset( $existing['label'] ) ? $existing['label'] : sprintf( __( 'Champ %d', 'mr-tee-editor' ), $line_num ) );
				$warning = isset( $line_data['warning_unedited'] ) ? ! empty( $line_data['warning_unedited'] ) : ( isset( $existing['warning_unedited'] ) ? ! empty( $existing['warning_unedited'] ) : false );

				// Sanitize printable side assignment
				$raw_side_id   = isset( $line_data['side_id'] ) ? sanitize_key( $line_data['side_id'] ) : ( ! empty( $existing['side_id'] ) ? sanitize_key( $existing['side_id'] ) : $default_side_id );
				$field_side_id = in_array( $raw_side_id, $valid_side_ids, true ) ? $raw_side_id : $default_side_id;

				// Per-field rectangle dimensions & orientation
				$box_l = isset( $line_data['box_left'] ) ? floatval( $line_data['box_left'] ) : ( isset( $existing['box_left'] ) ? floatval( $existing['box_left'] ) : 30.0 );
				$box_t = isset( $line_data['box_top'] ) ? floatval( $line_data['box_top'] ) : ( isset( $existing['box_top'] ) ? floatval( $existing['box_top'] ) : ( 25.0 + $line_index * 12.0 ) );
				$box_w = isset( $line_data['box_width'] ) ? floatval( $line_data['box_width'] ) : ( isset( $existing['box_width'] ) ? floatval( $existing['box_width'] ) : 40.0 );
				$box_h = isset( $line_data['box_height'] ) ? floatval( $line_data['box_height'] ) : ( isset( $existing['box_height'] ) ? floatval( $existing['box_height'] ) : ( 'image' === $field_type ? 30.0 : 8.5 ) );
				$box_r = isset( $line_data['box_rotate'] ) ? floatval( $line_data['box_rotate'] ) : ( isset( $existing['box_rotate'] ) ? floatval( $existing['box_rotate'] ) : 0.0 );

				$base_box = array(
					'box_left'   => $box_l,
					'box_top'    => $box_t,
					'box_width'  => $box_w,
					'box_height' => $box_h,
					'box_rotate' => $box_r,
				);

				$ref_view = SceneGraphSchema::get_side_reference_view( $preview_views, $field_side_id );

				// Parse & sanitize per-view placements map (JSON string or array)
				if ( isset( $line_data['placements'] ) && '' !== $line_data['placements'] && array() !== $line_data['placements'] ) {
					$raw_placements = $line_data['placements'];
					if ( is_string( $raw_placements ) ) {
						$decoded_p      = json_decode( $raw_placements, true );
						$raw_placements = is_array( $decoded_p ) ? $decoded_p : array();
					}
					$placements = SceneGraphSchema::normalize_field_placements( $raw_placements, $base_box, $preview_views, $field_side_id );
				} else {
					$legacy_placements = ( ! empty( $existing['placements'] ) && is_array( $existing['placements'] ) ) ? $existing['placements'] : array();
					if ( $ref_view && ! empty( $ref_view['id'] ) ) {
						$legacy_placements[ $ref_view['id'] ] = $base_box;
					}
					$placements = SceneGraphSchema::normalize_field_placements( $legacy_placements, $base_box, $preview_views, $field_side_id );
				}

				// Keep top-level box coordinates synced with the side's reference view
				if ( $ref_view && isset( $placements[ $ref_view['id'] ] ) ) {
					$ref_p = $placements[ $ref_view['id'] ];
					$box_l = $ref_p['box_left'];
					$box_t = $ref_p['box_top'];
					$box_w = $ref_p['box_width'];
					$box_h = $ref_p['box_height'];
					$box_r = $ref_p['box_rotate'];
				}

				if ( 'image' === $field_type ) {
					$default_img_url  = isset( $line_data['default_image'] ) ? esc_url_raw( trim( $line_data['default_image'] ) ) : ( ( 'image' === $existing_type && isset( $existing['default_image'] ) ) ? esc_url_raw( $existing['default_image'] ) : '' );
					$default_img_id   = isset( $line_data['default_image_id'] ) ? absint( $line_data['default_image_id'] ) : ( ( 'image' === $existing_type && isset( $existing['default_image_id'] ) ) ? absint( $existing['default_image_id'] ) : 0 );
					$image_fit        = ( isset( $line_data['image_fit'] ) && 'cover' === $line_data['image_fit'] ) ? 'cover' : 'contain';
					$allow_img_upload = isset( $line_data['allow_image_upload'] ) ? ! empty( $line_data['allow_image_upload'] ) : true;

					$sanitized_line = array(
						'id'                 => $field_id,
						'ref_id'             => $ref_id,
						'color_ref_id'       => $color_ref_id,
						'field_type'         => 'image',
						'side_id'            => $field_side_id,
						'placements'         => $placements,
						'label'              => $label,
						'default_text'       => '',
						'default_image'      => $default_img_url,
						'default_image_id'   => $default_img_id,
						'image_fit'          => $image_fit,
						'allow_image_upload' => $allow_img_upload,
						'box_left'           => $box_l,
						'box_top'            => $box_t,
						'box_width'          => $box_w,
						'box_height'         => $box_h,
						'box_rotate'         => $box_r,
						'warning_unedited'   => $warning,
					);
				} else {
					$existing_text = ( 'text' === $existing_type ) ? $existing : array();
					unset(
						$existing_text['default_image'],
						$existing_text['default_image_id'],
						$existing_text['image_fit'],
						$existing_text['allow_image_upload']
					);

					$default_text = isset( $line_data['default_text'] ) ? sanitize_textarea_field( $line_data['default_text'] ) : ( isset( $existing_text['default_text'] ) ? $existing_text['default_text'] : sprintf( __( 'Texte %d', 'mr-tee-editor' ), $line_num ) );
					$font         = isset( $line_data['font'] ) ? sanitize_text_field( $line_data['font'] ) : ( isset( $existing_text['font'] ) ? $existing_text['font'] : 'super_mario' );

					// If product-level allowed_fonts is defined, fall back to first font only when unset or disallowed
					if ( ! empty( $config['allowed_fonts'] ) && is_array( $config['allowed_fonts'] ) ) {
						if ( '' === $font || ! in_array( $font, $config['allowed_fonts'], true ) ) {
							$font = $config['allowed_fonts'][0];
						}
					}

					$font_pick  = isset( $line_data['allow_font_pick'] ) ? ! empty( $line_data['allow_font_pick'] ) : ( isset( $existing_text['allow_font_pick'] ) ? ! empty( $existing_text['allow_font_pick'] ) : true );
					$color_mode = ( isset( $line_data['color_mode'] ) && in_array( $line_data['color_mode'], array( 'multi', 'solid' ), true ) )
						? $line_data['color_mode']
						: ( isset( $existing_text['color_mode'] ) ? $existing_text['color_mode'] : 'multi' );
					$raw_hex    = isset( $line_data['color_hex'] ) ? trim( (string) $line_data['color_hex'] ) : '';
					$color_hex  = function_exists( 'sanitize_hex_color' ) ? sanitize_hex_color( $raw_hex ) : ( preg_match( '/^#[0-9a-fA-F]{6}$/', $raw_hex ) ? $raw_hex : '' );
					if ( empty( $color_hex ) ) {
						$color_hex = ! empty( $existing_text['color_hex'] ) ? $existing_text['color_hex'] : '#111827';
					}
					$allow_color_pick = isset( $line_data['allow_color_pick'] )
						? ! empty( $line_data['allow_color_pick'] )
						: ( isset( $existing_text['allow_color_pick'] ) ? ! empty( $existing_text['allow_color_pick'] ) : true );

					$multiline = ! empty( $line_data['multiline'] );
					$max_chars = isset( $line_data['max_chars'] ) ? max( 1, intval( $line_data['max_chars'] ) ) : ( isset( $existing_text['max_chars'] ) ? intval( $existing_text['max_chars'] ) : 25 );
					$font_size = isset( $line_data['font_size'] ) ? floatval( $line_data['font_size'] ) : round( $box_h * 0.85, 2 );
					$y_pct     = isset( $line_data['y_pct'] ) ? floatval( $line_data['y_pct'] ) : round( $box_t + ( $box_h / 2.0 ), 2 );

					$sanitized_line = wp_parse_args(
						array(
							'id'               => $field_id,
							'ref_id'           => $ref_id,
							'color_ref_id'     => $color_ref_id,
							'field_type'       => 'text',
							'side_id'          => $field_side_id,
							'placements'       => $placements,
							'label'            => $label,
							'default_text'     => $default_text,
							'font'             => $font,
							'allowed_fonts'    => ! empty( $config['allowed_fonts'] ) ? $config['allowed_fonts'] : ( isset( $existing_text['allowed_fonts'] ) ? $existing_text['allowed_fonts'] : array_keys( FontRegistry::get_fonts() ) ),
							'allow_font_pick'  => $font_pick,
							'color_mode'       => $color_mode,
							'color_hex'        => $color_hex,
							'allow_color_pick' => $allow_color_pick,
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
						$existing_text
					);
				}

				$sanitized_lines[] = $sanitized_line;
				$line_index++;
			}

			if ( ! empty( $sanitized_lines ) ) {
				$config['text_lines'] = array_values( $sanitized_lines );
			}
		}

		// Sanitize repeater (strictly clamped 0..6 and default_count <= max_items)
		if ( isset( $raw_config['repeater'] ) && is_array( $raw_config['repeater'] ) ) {
			$r                              = $raw_config['repeater'];
			$config['repeater']['enabled']  = ! empty( $r['enabled'] );
			if ( isset( $r['label'] ) ) {
				$config['repeater']['label'] = sanitize_text_field( $r['label'] );
			}
			$max_items = isset( $r['max_items'] )
				? max( 0, min( 6, intval( $r['max_items'] ) ) )
				: ( isset( $config['repeater']['max_items'] ) ? max( 0, min( 6, intval( $config['repeater']['max_items'] ) ) ) : 0 );
			$def_count = isset( $r['default_count'] )
				? max( 0, min( $max_items, intval( $r['default_count'] ) ) )
				: ( isset( $config['repeater']['default_count'] ) ? max( 0, min( $max_items, intval( $config['repeater']['default_count'] ) ) ) : 0 );

			$config['repeater']['max_items']     = $max_items;
			$config['repeater']['default_count'] = $def_count;

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

		// NBDesigner handshake & derived print dimensions (top-level physical dims synced from side 0)
		$config['nbd_handshake'] = ! empty( $raw_config['nbd_handshake'] );

		$unit   = ! empty( $config['print_unit'] ) ? $config['print_unit'] : 'cm';
		$real_w = isset( $config['print_real_width'] ) ? $config['print_real_width'] : 30.0;
		$real_h = isset( $config['print_real_height'] ) ? $config['print_real_height'] : 40.0;
		$dpi    = isset( $config['dpi'] ) ? $config['dpi'] : 300;

		$computed_dims                = SceneGraphSchema::compute_print_dimensions( $real_w, $real_h, $unit, $dpi );
		$config['print_aspect_ratio'] = $computed_dims['aspect_ratio'];
		$config['print_ratio_str']    = $computed_dims['ratio_str'];
		$config['print_width_px']     = $computed_dims['width_px'];
		$config['print_height_px']    = $computed_dims['height_px'];
		$config['engine']             = $engine;

		$config = self::strip_runtime_keys( $config );
		update_post_meta( $post_id, '_mr_tee_config', $config );

		SceneGraphSchema::clear_product_config_cache( $post_id );
	}
}
