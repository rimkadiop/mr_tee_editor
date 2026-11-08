<?php
/**
 * Mr. Tee Editor - Frontend Product Page Renderer
 *
 * Injects the client-side form controls, live SVG stage overlays into the
 * WooCommerce gallery, dynamic count selector drawer modal, and unedited
 * warning validation matching Monsieur TSHIRT Product #24987.
 *
 * @package MrTeeEditor\Frontend
 */

namespace MrTeeEditor\Frontend;

use MrTeeEditor\Domain\SceneGraphSchema;
use MrTeeEditor\Registry\FontRegistry;
use MrTeeEditor\Registry\ColorPaletteRegistry;
use MrTeeEditor\Registry\IconSetRegistry;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ProductPageRenderer {

	/**
	 * Register hooks.
	 */
	public static function init() {
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'enqueue_assets' ) );
		add_action( 'woocommerce_before_add_to_cart_button', array( __CLASS__, 'render_personalization_form' ), 15 );
		add_filter( 'body_class', array( __CLASS__, 'filter_body_class' ) );
		add_filter( 'script_loader_tag', array( __CLASS__, 'filter_script_loader_tag' ), 10, 3 );
	}

	/**
	 * Filter script tag to enable ES6 module imports for mr-tee-frontend.js.
	 *
	 * @param string $tag    Script tag HTML.
	 * @param string $handle Script handle.
	 * @param string $src    Script source URL.
	 * @return string
	 */
	public static function filter_script_loader_tag( $tag, $handle, $src ) {
		if ( 'mr-tee-frontend-js' === $handle ) {
			return sprintf( '<script type="module" src="%s" id="mr-tee-frontend-js-js"></script>', esc_url( $src ) );
		}
		return $tag;
	}

	/**
	 * Check if Mr. Tee Editor is active on the current product.
	 *
	 * @return bool
	 */
	public static function is_active() {
		if ( ! is_product() ) {
			return false;
		}
		global $post;
		if ( ! $post ) {
			return false;
		}
		$engine = get_post_meta( $post->ID, '_mr_tee_engine_mode', true );
		if ( empty( $engine ) ) {
			$engine = get_post_meta( $post->ID, '_mr_tee_editor_mode', true );
		}
		if ( empty( $engine ) ) {
			$saved = get_post_meta( $post->ID, '_mr_tee_config', true );
			if ( is_array( $saved ) && ! empty( $saved['editor_mode'] ) ) {
				$engine = $saved['editor_mode'];
			}
		}
		return 'mr_tee_editor' === $engine;
	}

	/**
	 * Add body class for styling scoping.
	 *
	 * @param array<int, string> $classes Existing body classes.
	 * @return array<int, string>
	 */
	public static function filter_body_class( $classes ) {
		if ( self::is_active() ) {
			$classes[] = 'has-mr-tee-editor';
		}
		return $classes;
	}

	/**
	 * Enqueue frontend CSS and JavaScript modules.
	 */
	public static function enqueue_assets() {
		if ( ! self::is_active() ) {
			return;
		}

		global $post;
		$config = SceneGraphSchema::get_product_config( $post->ID );

		// Google Fonts
		wp_enqueue_style(
			'mr-tee-google-fonts',
			'https://fonts.googleapis.com/css2?family=Abril+Fatface&family=Bebas+Neue&family=Cinzel+Decorative:wght@700&family=Pacifico&family=Permanent+Marker&family=Press+Start+2P&display=swap',
			array(),
			MR_TEE_EDITOR_VERSION
		);

		// Frontend styles
		wp_enqueue_style(
			'mr-tee-frontend-style',
			MR_TEE_EDITOR_URL . 'assets/css/mr-tee-frontend.css',
			array(),
			MR_TEE_EDITOR_VERSION
		);

		// Dynamic @font-face CSS
		$font_face_css = FontRegistry::get_font_face_css();
		wp_add_inline_style( 'mr-tee-frontend-style', $font_face_css );

		// Frontend JavaScript Orchestrator
		wp_enqueue_script(
			'mr-tee-frontend-js',
			MR_TEE_EDITOR_URL . 'assets/js/mr-tee-frontend.js',
			array( 'jquery' ),
			MR_TEE_EDITOR_VERSION,
			true
		);

		$proto = is_ssl() ? 'https' : 'http';
		wp_localize_script(
			'mr-tee-frontend-js',
			'mrTeeProductData',
			array(
				'productId'   => $post->ID,
				'config'      => $config,
				'fonts'       => FontRegistry::get_fonts(),
				'ajaxUrl'     => admin_url( 'admin-ajax.php', $proto ),
				'pluginUrl'   => MR_TEE_EDITOR_URL,
				'fontFaceCss' => $font_face_css,
				'i18n'        => array(
					'warningTitle'   => __( 'Attention ! Personnalisation incomplète', 'mr-tee-editor' ),
					'warningMessage' => __( 'Un ou plusieurs champs n\'ont pas été personnalisés. Souhaitez-vous continuer avec les textes par défaut ?', 'mr-tee-editor' ),
					'confirmKeep'    => __( 'Continuer quand même', 'mr-tee-editor' ),
					'cancelEdit'     => __( 'Modifier mes textes', 'mr-tee-editor' ),
					'characters'     => __( 'caractères', 'mr-tee-editor' ),
					'generatingDpi'  => __( 'Génération du fichier d\'impression haute résolution...', 'mr-tee-editor' ),
				),
			)
		);
	}

	/**
	 * Render the personalization form on the single product page.
	 */
	public static function render_personalization_form() {
		if ( ! self::is_active() ) {
			return;
		}

		global $post;
		$config     = SceneGraphSchema::get_product_config( $post->ID );
		$fonts      = FontRegistry::get_fonts();
		$swatches   = ColorPaletteRegistry::get_solid_swatches();
		$icons      = IconSetRegistry::get_mushroom_icons();
		$rep        = $config['repeater'];
		$def_count  = isset( $rep['default_count'] ) ? intval( $rep['default_count'] ) : 2;
		$max_items  = isset( $rep['max_items'] ) ? intval( $rep['max_items'] ) : 6;

		?>
		<div id="mr-tee-editor-container" class="mr-tee-editor-container" data-product-id="<?php echo esc_attr( $post->ID ); ?>">
			<!-- Mobile Sticky Live Preview Bar -->
			<div id="mr-tee-mobile-sticky-preview" class="mr-tee-mobile-sticky-preview" style="display:none;">
				<div class="mr-tee-sticky-inner">
					<div class="mr-tee-sticky-thumb-box" style="background-image: url('<?php echo esc_url( get_the_post_thumbnail_url( $post->ID, 'thumbnail' ) ); ?>'); background-size: cover; background-position: center;">
						<div class="mr-tee-mobile-overlay-mini"></div>
					</div>
					<div class="mr-tee-sticky-meta">
						<span class="mr-tee-sticky-tag"><?php esc_html_e( 'Aperçu en direct', 'mr-tee-editor' ); ?></span>
						<span class="mr-tee-sticky-title"><?php echo esc_html( get_the_title( $post->ID ) ); ?></span>
					</div>
					<button type="button" class="mr-tee-sticky-btn-scroll" id="mr-tee-btn-scroll-mockup" title="<?php esc_attr_e( 'Voir mockup', 'mr-tee-editor' ); ?>">
						<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="18 15 12 9 6 15"></polyline></svg>
					</button>
				</div>
			</div>

			<!-- Header Card: PERSONNALISER -->
			<div class="mr-tee-card mr-tee-main-card">
				<div class="mr-tee-card-header">
					<h3 class="mr-tee-card-title">
						<span class="mr-tee-badge-number">1</span>
						<?php esc_html_e( 'PERSONNALISER', 'mr-tee-editor' ); ?>
					</h3>
				</div>

				<!-- Text Lines Controls -->
				<div class="mr-tee-text-inputs-wrap">
					<?php foreach ( $config['text_lines'] as $idx => $line ) : ?>
						<?php
						$field_id = 'mr_tee_line_' . $idx;
						$cur_font = isset( $fonts[ $line['font'] ] ) ? $fonts[ $line['font'] ] : $fonts['super_mario'];
						?>
						<div class="mr-tee-field-group" data-line-index="<?php echo esc_attr( $idx ); ?>" data-line-id="<?php echo esc_attr( $line['id'] ); ?>">
							<div class="mr-tee-input-row">
								<div class="mr-tee-floating-label-wrap">
									<?php if ( ! empty( $line['multiline'] ) ) : ?>
										<textarea
											id="<?php echo esc_attr( $field_id ); ?>"
											class="mr-tee-text-input mr-tee-textarea"
											name="mr_tee_custom_text[<?php echo esc_attr( $idx ); ?>]"
											rows="<?php echo esc_attr( ! empty( $line['max_lines'] ) ? $line['max_lines'] : 3 ); ?>"
											maxlength="<?php echo esc_attr( $line['max_chars'] ); ?>"
											data-default="<?php echo esc_attr( $line['default_text'] ); ?>"
											data-warning="<?php echo ! empty( $line['warning_unedited'] ) ? '1' : '0'; ?>"
											placeholder=" "
										><?php echo esc_textarea( $line['default_text'] ); ?></textarea>
									<?php else : ?>
										<input
											type="text"
											id="<?php echo esc_attr( $field_id ); ?>"
											class="mr-tee-text-input"
											name="mr_tee_custom_text[<?php echo esc_attr( $idx ); ?>]"
											value="<?php echo esc_attr( $line['default_text'] ); ?>"
											maxlength="<?php echo esc_attr( $line['max_chars'] ); ?>"
											data-default="<?php echo esc_attr( $line['default_text'] ); ?>"
											data-warning="<?php echo ! empty( $line['warning_unedited'] ) ? '1' : '0'; ?>"
											placeholder=" "
											autocomplete="off"
										/>
									<?php endif; ?>
									<label for="<?php echo esc_attr( $field_id ); ?>" class="mr-tee-floating-label">
										<?php echo esc_html( $line['label'] ); ?>
									</label>
									<span class="mr-tee-char-counter">
										<span class="mr-tee-char-count"><?php echo mb_strlen( $line['default_text'] ); ?></span>/<?php echo esc_attr( $line['max_chars'] ); ?>
									</span>
								</div>
							</div>

							<!-- Live Typography Preview Pill -->
							<div class="mr-tee-inline-typography-preview" id="mr-tee-inline-preview-<?php echo esc_attr( $idx ); ?>">
								<span class="mr-tee-inline-preview-label"><?php esc_html_e( 'Rendu typographique :', 'mr-tee-editor' ); ?></span>
								<span class="mr-tee-inline-preview-text" id="mr-tee-preview-text-<?php echo esc_attr( $idx ); ?>" style="font-family: '<?php echo esc_attr( $cur_font['family'] ); ?>', Arial Black, Impact, sans-serif;"><?php echo esc_html( $line['default_text'] ); ?></span>
							</div>

							<!-- Color Swatch Selector Pills (for embroidery thread or ink) -->
							<?php if ( ! empty( $line['allow_color_pick'] ) && ! empty( $line['color_swatches'] ) && is_array( $line['color_swatches'] ) ) : ?>
								<div class="mr-tee-color-swatches-wrap" data-color-ref-id="<?php echo esc_attr( ! empty( $line['color_ref_id'] ) ? $line['color_ref_id'] : 'id-ref-color-' . $idx ); ?>">
									<label class="mr-tee-picker-label"><?php esc_html_e( 'Couleur de broderie / impression :', 'mr-tee-editor' ); ?></label>
									<div class="mr-tee-color-swatches-list">
										<?php foreach ( $line['color_swatches'] as $hex => $label ) : ?>
											<button
												type="button"
												class="mr-tee-color-swatch-pill <?php echo ( isset( $line['color_hex'] ) && $line['color_hex'] === $hex ) ? 'is-active' : ''; ?>"
												data-color-hex="<?php echo esc_attr( $hex ); ?>"
												style="background-color: <?php echo esc_attr( $hex ); ?>; border: 1px solid <?php echo ( '#ffffff' === strtolower( $hex ) ? '#cccccc' : esc_attr( $hex ) ); ?>;"
												title="<?php echo esc_attr( $label ); ?>"
											></button>
										<?php endforeach; ?>
									</div>
								</div>
							<?php endif; ?>

							<!-- Font Selector Pills (collapsible) -->
							<?php if ( ! empty( $line['allow_font_pick'] ) ) : ?>
								<?php
								$allowed_font_ids = ( ! empty( $line['allowed_fonts'] ) && is_array( $line['allowed_fonts'] ) )
									? $line['allowed_fonts']
									: array_keys( $fonts );
								?>
								<div class="mr-tee-font-selector-row">
									<div class="mr-tee-font-pills" data-line-index="<?php echo esc_attr( $idx ); ?>">
										<?php foreach ( $allowed_font_ids as $f_id ) : ?>
											<?php
											if ( ! isset( $fonts[ $f_id ] ) ) {
												continue;
											}
											$f_data = $fonts[ $f_id ];
											?>
											<button
												type="button"
												class="mr-tee-font-pill <?php echo $line['font'] === $f_id ? 'is-active' : ''; ?>"
												data-font-id="<?php echo esc_attr( $f_id ); ?>"
												style="font-family: '<?php echo esc_attr( $f_data['family'] ); ?>', sans-serif;"
												title="<?php echo esc_attr( $f_data['name'] ); ?>"
											>
												<?php echo esc_html( $f_data['name'] ); ?>
											</button>
										<?php endforeach; ?>
									</div>
								</div>
							<?php endif; ?>
						</div>
					<?php endforeach; ?>
				</div>

				<!-- Repeater Trigger Accordion: "Nombre de champignons" -->
				<?php if ( ! empty( $rep['enabled'] ) ) : ?>
					<div class="mr-tee-repeater-trigger-card">
						<button type="button" class="mr-tee-repeater-trigger-btn" id="mr-tee-open-count-modal">
							<div class="mr-tee-trigger-left">
								<span class="mr-tee-badge-number">2</span>
								<div class="mr-tee-trigger-texts">
									<strong class="mr-tee-trigger-title"><?php echo esc_html( $rep['label'] ); ?></strong>
									<span class="mr-tee-trigger-subtitle"><?php echo esc_html( $rep['subtitle'] ); ?></span>
								</div>
							</div>
							<div class="mr-tee-trigger-right">
								<span class="mr-tee-active-count-badge" id="mr-tee-active-count-display"><?php echo esc_html( $def_count ); ?></span>
								<span class="dashicons dashicons-arrow-right-alt2"></span>
							</div>
						</button>
					</div>

					<!-- Individual Mushroom Accordions -->
					<div class="mr-tee-repeater-items-wrap" id="mr-tee-repeater-items-wrap">
						<?php for ( $i = 0; $i < $max_items; $i++ ) : ?>
							<?php
							$item_num  = $i + 1;
							$row_num   = ( $i < 3 ) ? 1 : 2;
							$def_item  = isset( $rep['defaults'][ $i ] ) ? $rep['defaults'][ $i ] : array( 'name' => 'Prénom ' . $item_num, 'icon' => 'mushroom_red' );
							$icon_key  = isset( $def_item['icon'] ) && isset( $icons[ $def_item['icon'] ] ) ? $def_item['icon'] : 'mushroom_red';
							$is_active = ( $item_num <= $def_count );
							?>
							<div
								class="mr-tee-repeater-item-card <?php echo $is_active ? 'is-visible' : 'is-hidden'; ?>"
								data-item-index="<?php echo esc_attr( $i ); ?>"
								data-item-num="<?php echo esc_attr( $item_num ); ?>"
								data-row-num="<?php echo esc_attr( $row_num ); ?>"
								id="mr-tee-rep-card-<?php echo esc_attr( $item_num ); ?>"
							>
								<div class="mr-tee-rep-card-header">
									<div class="mr-tee-rep-thumb" id="mr-tee-rep-thumb-<?php echo esc_attr( $item_num ); ?>">
										<img src="<?php echo esc_url( $icons[ $icon_key ]['url'] ); ?>" alt="Mushroom Icon" />
									</div>
									<div class="mr-tee-rep-name-summary">
										<strong><?php echo esc_html( $rep['item_label'] . ' ' . $item_num ); ?> :</strong>
										<span class="mr-tee-rep-summary-text" id="mr-tee-rep-summary-<?php echo esc_attr( $item_num ); ?>"><?php echo esc_html( $def_item['name'] ); ?></span>
									</div>
									<button type="button" class="mr-tee-rep-toggle-btn" aria-label="Toggle details">
										<span class="dashicons dashicons-arrow-down-alt2"></span>
									</button>
								</div>

								<div class="mr-tee-rep-card-body">
									<div class="mr-tee-floating-label-wrap">
										<input
											type="text"
											class="mr-tee-text-input mr-tee-rep-name-input"
											id="mr_tee_rep_name_<?php echo esc_attr( $item_num ); ?>"
											name="mr_tee_repeater[<?php echo esc_attr( $i ); ?>][name]"
											value="<?php echo esc_attr( $def_item['name'] ); ?>"
											maxlength="<?php echo esc_attr( $rep['max_chars'] ); ?>"
											placeholder=" "
											autocomplete="off"
										/>
										<label for="mr_tee_rep_name_<?php echo esc_attr( $item_num ); ?>" class="mr-tee-floating-label">
											<?php esc_html_e( 'Prénom', 'mr-tee-editor' ); ?>
										</label>
										<span class="mr-tee-char-counter">
											<span class="mr-tee-char-count"><?php echo mb_strlen( $def_item['name'] ); ?></span>/<?php echo esc_attr( $rep['max_chars'] ); ?>
										</span>
									</div>

									<div class="mr-tee-icon-picker-wrap">
										<label class="mr-tee-picker-label"><?php esc_html_e( 'Choisir la couleur du champignon :', 'mr-tee-editor' ); ?></label>
										<div class="mr-tee-icon-options">
											<?php foreach ( $icons as $ic_id => $ic_data ) : ?>
												<label class="mr-tee-icon-option <?php echo $icon_key === $ic_id ? 'is-selected' : ''; ?>" title="<?php echo esc_attr( $ic_data['name'] ); ?>">
													<input
														type="radio"
														name="mr_tee_repeater[<?php echo esc_attr( $i ); ?>][icon]"
														value="<?php echo esc_attr( $ic_id ); ?>"
														<?php checked( $icon_key, $ic_id ); ?>
													/>
													<img src="<?php echo esc_url( $ic_data['url'] ); ?>" alt="<?php echo esc_attr( $ic_data['name'] ); ?>" />
												</label>
											<?php endforeach; ?>
										</div>
									</div>
								</div>
							</div>
						<?php endfor; ?>
					</div>
				<?php endif; ?>
			</div>

			<!-- Count Selector Drawer / Modal (matches Monsieur TSHIRT's PersoVisualCfg dialog) -->
			<div id="mr-tee-count-modal" class="mr-tee-modal-backdrop" style="display:none;">
				<div class="mr-tee-modal-dialog">
					<div class="mr-tee-modal-header">
						<h4 class="mr-tee-modal-title"><?php echo esc_html( $rep['label'] ); ?></h4>
						<button type="button" class="mr-tee-modal-close-btn" id="mr-tee-close-count-modal">&times;</button>
					</div>
					<div class="mr-tee-modal-body">
						<p class="mr-tee-modal-desc"><?php esc_html_e( 'Combien de champignons souhaitez-vous afficher sur le t-shirt ?', 'mr-tee-editor' ); ?></p>
						<div class="mr-tee-count-stepper">
							<button type="button" class="mr-tee-step-btn" id="mr-tee-step-minus" aria-label="Moins">&minus;</button>
							<div class="mr-tee-step-val-display" id="mr-tee-step-val"><?php echo esc_html( $def_count ); ?></div>
							<button type="button" class="mr-tee-step-btn" id="mr-tee-step-plus" aria-label="Plus">&plus;</button>
						</div>
						<div class="mr-tee-count-dots">
							<?php for ( $c = 0; $c <= $max_items; $c++ ) : ?>
								<button type="button" class="mr-tee-count-dot-btn <?php echo $c === $def_count ? 'is-active' : ''; ?>" data-count="<?php echo esc_attr( $c ); ?>">
									<?php echo esc_html( $c ); ?>
								</button>
							<?php endfor; ?>
						</div>
					</div>
					<div class="mr-tee-modal-footer">
						<button type="button" class="button button-primary mr-tee-btn-confirm-count" id="mr-tee-confirm-count">
							<?php esc_html_e( 'Valider', 'mr-tee-editor' ); ?>
						</button>
					</div>
				</div>
			</div>

			<!-- Unedited Warning Modal (matches Monsieur TSHIRT's IGNORE_PERSO_WARNINGS_LABEL) -->
			<div id="mr-tee-warning-modal" class="mr-tee-modal-backdrop mr-tee-warning-modal" style="display:none;">
				<div class="mr-tee-modal-dialog">
					<div class="mr-tee-modal-header mr-tee-warning-header">
						<span class="dashicons dashicons-warning mr-tee-warn-icon"></span>
						<h4 class="mr-tee-modal-title"><?php esc_html_e( 'Attention ! Personnalisation incomplète', 'mr-tee-editor' ); ?></h4>
						<button type="button" class="mr-tee-modal-close-btn" id="mr-tee-close-warning-modal">&times;</button>
					</div>
					<div class="mr-tee-modal-body">
						<p id="mr-tee-warning-text">
							<?php esc_html_e( 'Vous avez laissé un ou plusieurs champs avec les valeurs par défaut (ex: "Papa"). Voulez-vous continuer ou personnaliser vos textes ?', 'mr-tee-editor' ); ?>
						</p>
					</div>
					<div class="mr-tee-modal-footer">
						<button type="button" class="button mr-tee-btn-cancel-warning" id="mr-tee-btn-modify">
							<?php esc_html_e( 'Modifier mes textes', 'mr-tee-editor' ); ?>
						</button>
						<button type="button" class="button button-primary mr-tee-btn-ignore-warning" id="mr-tee-btn-proceed">
							<?php esc_html_e( 'Continuer quand même', 'mr-tee-editor' ); ?>
						</button>
					</div>
				</div>
			</div>

			<!-- Hidden Form Inputs for Cart Payload Submission -->
			<input type="hidden" name="mr_tee_editor_payload" id="mr_tee_editor_payload" value="" />
			<input type="hidden" name="mr_tee_preview_png" id="mr_tee_preview_png" value="" />
			<input type="hidden" name="mr_tee_vector_svg" id="mr_tee_vector_svg" value="" />
			<input type="hidden" name="mr_tee_print_300dpi_png" id="mr_tee_print_300dpi_png" value="" />
		</div>
		<?php
	}
}
