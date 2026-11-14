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
		add_action( 'wp_head', array( __CLASS__, 'render_performance_hints' ), 2 );
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
			// Convert to root-relative path to completely eliminate CORS scheme mismatches
			$parsed = wp_parse_url( $src );
			$path   = ! empty( $parsed['path'] ) ? $parsed['path'] : $src;
			if ( ! empty( $parsed['query'] ) ) {
				$path .= '?' . $parsed['query'];
			}
			return sprintf( '<script type="module" src="%s" id="mr-tee-frontend-js-js"></script>', esc_url( $path ) );
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
	 * Per-request in-memory cache of compiled product configurations.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	private static $config_cache = array();

	/**
	 * Retrieve compiled product configuration once per request.
	 *
	 * @param int $product_id Product ID.
	 * @return array<string, mixed>
	 */
	private static function get_cached_config( $product_id ) {
		$pid = (int) $product_id;
		if ( ! isset( self::$config_cache[ $pid ] ) ) {
			self::$config_cache[ $pid ] = SceneGraphSchema::get_product_config( $pid );
		}
		return self::$config_cache[ $pid ];
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
			global $post;
			if ( $post ) {
				$config = self::get_cached_config( $post->ID );
				if ( ! empty( $config['lock_aspect_ratio'] ) || ( isset( $config['product_type'] ) && 'flat_object' === $config['product_type'] ) ) {
					$classes[] = 'mr-tee-locked-aspect-ratio';
				}
			}
		}
		return $classes;
	}

	/**
	 * Output high-priority performance hints in wp_head:
	 * - Emits <link rel="modulepreload"> for mr-tee-frontend.js and its 6 ES modules to eliminate the footer waterfall.
	 * - Preloads glyph images used by default text with fetchpriority="high" so 3D fonts render instantly without flicker.
	 */
	public static function render_performance_hints() {
		if ( ! self::is_active() ) {
			return;
		}

		global $post;
		if ( ! $post ) {
			return;
		}

		// 1. Preload ES6 module graph in <head> to eliminate the 6-file discovery waterfall at bottom of <body>
		$base_parsed = wp_parse_url( MR_TEE_EDITOR_URL );
		$base_path   = ! empty( $base_parsed['path'] ) ? trailingslashit( $base_parsed['path'] ) : MR_TEE_EDITOR_URL;
		$ver         = MR_TEE_EDITOR_VERSION;

		printf(
			"<link rel=\"modulepreload\" href=\"%s\" />\n",
			esc_url( $base_path . 'assets/js/mr-tee-frontend.js?ver=' . $ver )
		);

		$es_modules = array(
			'state-store.js',
			'scene-graph-engine.js',
			'svg-renderer.js',
			'gallery-stage.js',
			'form-controller.js',
			'print-exporter.js',
		);
		foreach ( $es_modules as $mod_file ) {
			printf(
				"<link rel=\"modulepreload\" href=\"%s\" />\n",
				esc_url( $base_path . 'assets/js/modules/' . $mod_file . '?v=' . $ver )
			);
		}

		$config = self::get_cached_config( $post->ID );
		if ( empty( $config['text_lines'] ) || ! is_array( $config['text_lines'] ) ) {
			return;
		}

		$preloaded_images = array();
		foreach ( $config['text_lines'] as $line ) {
			$font_id = ! empty( $line['font'] ) ? $line['font'] : ( ! empty( $line['allowed_fonts'][0] ) ? $line['allowed_fonts'][0] : '' );
			if ( empty( $font_id ) ) {
				continue;
			}
			$font = FontRegistry::get_font( $font_id );
			if ( empty( $font ) || ( ( empty( $font['font_type'] ) || 'opentype_svg' !== $font['font_type'] ) && empty( $font['color_svg'] ) ) ) {
				continue;
			}

			// Preload glyph images for default text characters with high priority (active font manifest is already inlined in HTML)
			$default_text = ! empty( $line['default_text'] ) ? $line['default_text'] : '';
			if ( '' !== $default_text ) {
				$is_upper = ( isset( $line['casing'] ) && 'UPPER' === $line['casing'] ) || ! empty( $font['uppercase'] );
				if ( $is_upper ) {
					$default_text = mb_strtoupper( $default_text, 'UTF-8' );
				}
				$svg_data = FontRegistry::get_svg_font_data( $font_id );
				if ( ! empty( $svg_data['glyphs'] ) ) {
					$chars = preg_split( '//u', $default_text, -1, PREG_SPLIT_NO_EMPTY );
					$seen  = array();
					foreach ( $chars as $ch ) {
						if ( ' ' === $ch || "\n" === $ch || "\r" === $ch || isset( $seen[ $ch ] ) ) {
							continue;
						}
						$seen[ $ch ] = true;
						$g = isset( $svg_data['glyphs'][ $ch ] ) ? $svg_data['glyphs'][ $ch ] : ( isset( $svg_data['glyphs'][ mb_strtoupper( $ch, 'UTF-8' ) ] ) ? $svg_data['glyphs'][ mb_strtoupper( $ch, 'UTF-8' ) ] : null );
						if ( $g && ! empty( $g['svg'] ) ) {
							if ( preg_match( '/(xlink:href|href)="([^"]+)"/', $g['svg'], $im ) ) {
								$img_url = $im[2];
								if ( ! isset( $preloaded_images[ $img_url ] ) && count( $preloaded_images ) < 24 ) {
									$preloaded_images[ $img_url ] = true;
									printf(
										"<link rel=\"preload\" href=\"%s\" as=\"image\" type=\"image/webp\" fetchpriority=\"high\" />\n",
										esc_url( $img_url )
									);
								}
							}
						}
					}
				}
			}
		}
	}

	/**
	 * Enqueue frontend CSS and JavaScript modules.
	 */
	public static function enqueue_assets() {
		if ( ! self::is_active() ) {
			return;
		}

		global $post;
		$config = self::get_cached_config( $post->ID );

		// Determine allowed fonts strictly for this product
		$all_fonts        = FontRegistry::get_fonts();
		$allowed_font_ids = array();
		if ( ! empty( $config['allowed_fonts'] ) && is_array( $config['allowed_fonts'] ) ) {
			$allowed_font_ids = $config['allowed_fonts'];
		} elseif ( ! empty( $config['text_lines'][0]['allowed_fonts'] ) && is_array( $config['text_lines'][0]['allowed_fonts'] ) ) {
			$allowed_font_ids = $config['text_lines'][0]['allowed_fonts'];
		} else {
			$allowed_font_ids = array_keys( $all_fonts );
		}

		// Filter and strictly preserve the admin's chosen font order
		$filtered_fonts = array();
		foreach ( $allowed_font_ids as $f_id ) {
			if ( isset( $all_fonts[ $f_id ] ) ) {
				$filtered_fonts[ $f_id ] = $all_fonts[ $f_id ];
			}
		}
		if ( empty( $filtered_fonts ) ) {
			$filtered_fonts = $all_fonts;
		}
		unset( $config['fonts'] );

		// Frontend styles (all fancy display fonts bundled locally in assets/fonts/ with font-display: swap)
		wp_enqueue_style(
			'mr-tee-frontend-style',
			MR_TEE_EDITOR_URL . 'assets/css/mr-tee-frontend.css',
			array(),
			MR_TEE_EDITOR_VERSION
		);

		// Dynamic @font-face CSS for ONLY allowed standard vector fonts (skips OpenType-SVG .otf binaries)
		$font_face_css = FontRegistry::get_font_face_css( array_keys( $filtered_fonts ) );
		wp_add_inline_style( 'mr-tee-frontend-style', $font_face_css );

		// Dynamic Aspect Ratio CSS Variables
		$ratio     = isset( $config['print_aspect_ratio'] ) ? floatval( $config['print_aspect_ratio'] ) : 1.0;
		$ratio_css = sprintf(
			':root { --mr-tee-aspect-ratio: %s; --mr-tee-real-width: %s; --mr-tee-real-height: %s; }',
			$ratio,
			isset( $config['print_real_width'] ) ? floatval( $config['print_real_width'] ) : 30.0,
			isset( $config['print_real_height'] ) ? floatval( $config['print_real_height'] ) : 40.0
		);
		wp_add_inline_style( 'mr-tee-frontend-style', $ratio_css );

		// Frontend JavaScript Orchestrator
		wp_enqueue_script(
			'mr-tee-frontend-js',
			MR_TEE_EDITOR_URL . 'assets/js/mr-tee-frontend.js',
			array( 'jquery' ),
			MR_TEE_EDITOR_VERSION,
			true
		);

		$proto = is_ssl() ? 'https' : 'http';

		// Inline ONLY the active default OpenType-SVG font(s) for 0-RTT initial render;
		// secondary allowed OpenType-SVG fonts are lazy-loaded via manifest_url in the background.
		$preloaded_svg_fonts = array();
		if ( ! empty( $config['text_lines'] ) && is_array( $config['text_lines'] ) ) {
			foreach ( $config['text_lines'] as $line ) {
				$active_fid = ! empty( $line['font'] ) ? $line['font'] : ( ! empty( $line['allowed_fonts'][0] ) ? $line['allowed_fonts'][0] : '' );
				if ( empty( $active_fid ) || isset( $preloaded_svg_fonts[ $active_fid ] ) ) {
					continue;
				}
				$f_def = isset( $filtered_fonts[ $active_fid ] ) ? $filtered_fonts[ $active_fid ] : FontRegistry::get_font( $active_fid );
				if ( ! empty( $f_def ) && ( ( ! empty( $f_def['font_type'] ) && 'opentype_svg' === $f_def['font_type'] ) || ! empty( $f_def['color_svg'] ) ) ) {
					$svg_data = FontRegistry::get_svg_font_data( $active_fid );
					if ( $svg_data ) {
						$preloaded_svg_fonts[ $active_fid ] = $svg_data;
					}
				}
			}
		}

		wp_localize_script(
			'mr-tee-frontend-js',
			'mrTeeProductData',
			array(
				'productId'   => $post->ID,
				'config'      => $config,
				'fonts'       => $filtered_fonts,
				'svgFonts'    => $preloaded_svg_fonts,
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
		$config      = self::get_cached_config( $post->ID );
		$all_fonts   = FontRegistry::get_fonts();
		$allowed_ids = ! empty( $config['allowed_fonts'] ) && is_array( $config['allowed_fonts'] )
			? $config['allowed_fonts']
			: ( ! empty( $config['text_lines'][0]['allowed_fonts'] ) && is_array( $config['text_lines'][0]['allowed_fonts'] ) ? $config['text_lines'][0]['allowed_fonts'] : array_keys( $all_fonts ) );
		$fonts       = array();
		foreach ( $allowed_ids as $aid ) {
			if ( isset( $all_fonts[ $aid ] ) ) {
				$fonts[ $aid ] = $all_fonts[ $aid ];
			}
		}
		if ( empty( $fonts ) ) {
			$fonts = $all_fonts;
		}
		$swatches   = ColorPaletteRegistry::get_solid_swatches();
		$icons      = IconSetRegistry::get_mushroom_icons();
		$rep        = $config['repeater'];
		$def_count  = isset( $rep['default_count'] ) ? intval( $rep['default_count'] ) : 2;
		$max_items  = isset( $rep['max_items'] ) ? intval( $rep['max_items'] ) : 6;

		?>
		<div id="mr-tee-editor-container" class="mr-tee-editor-container" data-product-id="<?php echo esc_attr( $post->ID ); ?>">
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

							<!-- Font Selector Pills (collapsible, hidden if only 1 font allowed) -->
							<?php
							$allowed_font_ids = ( ! empty( $line['allowed_fonts'] ) && is_array( $line['allowed_fonts'] ) )
								? $line['allowed_fonts']
								: array_keys( $fonts );
							// Strictly filter to allowed fonts and keep order
							$ordered_pills = array();
							foreach ( $allowed_font_ids as $f_id ) {
								if ( isset( $fonts[ $f_id ] ) ) {
									$ordered_pills[ $f_id ] = $fonts[ $f_id ];
								}
							}
							?>
							<?php if ( ! empty( $line['allow_font_pick'] ) && count( $ordered_pills ) > 1 ) : ?>
								<div class="mr-tee-font-selector-row">
									<div class="mr-tee-font-pills" data-line-index="<?php echo esc_attr( $idx ); ?>">
										<?php foreach ( $ordered_pills as $f_id => $f_data ) : ?>
											<?php
											$is_pill_ot_svg = ( ! empty( $f_data['font_type'] ) && 'opentype_svg' === $f_data['font_type'] ) || ! empty( $f_data['color_svg'] );
											$pill_style     = $is_pill_ot_svg ? '' : "font-family: '" . esc_attr( $f_data['family'] ) . "', sans-serif;";
											?>
											<button
												type="button"
												class="mr-tee-font-pill <?php echo $line['font'] === $f_id ? 'is-active' : ''; ?>"
												data-font-id="<?php echo esc_attr( $f_id ); ?>"
												<?php if ( '' !== $pill_style ) : ?>
													style="<?php echo $pill_style; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>"
												<?php endif; ?>
												title="<?php echo esc_attr( $f_data['name'] ); ?>"
											>
												<?php if ( ! empty( $f_data['thumb_url'] ) ) : ?>
													<img src="<?php echo esc_url( $f_data['thumb_url'] ); ?>" alt="" class="mr-tee-pill-thumb" decoding="async" style="width:16px; height:16px; object-fit:contain; vertical-align:middle; margin-right:4px; display:inline-block;" />
												<?php endif; ?>
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
			<input type="hidden" name="add-to-cart" value="<?php echo esc_attr( $post->ID ); ?>" />
			<input type="hidden" name="mr_tee_editor_payload" id="mr_tee_editor_payload" value="" />
			<input type="hidden" name="mr_tee_preview_png" id="mr_tee_preview_png" value="" />
			<input type="hidden" name="mr_tee_vector_svg" id="mr_tee_vector_svg" value="" />
			<input type="hidden" name="mr_tee_print_300dpi_png" id="mr_tee_print_300dpi_png" value="" />
		</div>
		<?php
	}
}
