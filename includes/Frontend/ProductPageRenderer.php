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
	 * Per-request in-memory cache of compiled product configurations.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	private static $config_cache = array();

	/**
	 * Track product IDs whose personalization form has already been rendered in this request
	 * so themes with sticky add-to-cart bars never emit duplicate `#mr-tee-editor-container` IDs.
	 *
	 * @var array<int, bool>
	 */
	private static $rendered_products = array();

	/**
	 * Track whether frontend scripts & styles have been enqueued for the request.
	 *
	 * @var bool
	 */
	private static $assets_enqueued = false;

	/**
	 * Register hooks.
	 */
	public static function init() {
		ProductGalleryRenderer::init();
		add_action( 'wp', array( __CLASS__, 'suppress_nbd_frontend_hooks' ), 120 );
		add_action( 'wp_head', array( __CLASS__, 'render_performance_hints' ), 2 );
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'enqueue_assets' ) );
		add_action( 'woocommerce_before_add_to_cart_button', array( __CLASS__, 'render_personalization_form' ), 15 );
		add_filter( 'body_class', array( __CLASS__, 'filter_body_class' ) );
		add_filter( 'script_loader_tag', array( __CLASS__, 'filter_script_loader_tag' ), 10, 3 );
		add_filter( 'nbd_conditional_show_design_btn', array( __CLASS__, 'filter_nbd_show_design_btn' ), 999 );
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
			// Convert same-origin URLs to root-relative paths to prevent scheme mismatches while preserving external CDN URLs.
			$parsed    = wp_parse_url( $src );
			$url_host  = ! empty( $parsed['host'] ) ? $parsed['host'] : '';
			$site_host = wp_parse_url( home_url(), PHP_URL_HOST );
			$http_host = isset( $_SERVER['HTTP_HOST'] ) ? preg_replace( '/:\d+$/', '', sanitize_text_field( wp_unslash( $_SERVER['HTTP_HOST'] ) ) ) : '';
			$same_orig = ! $url_host || $url_host === $site_host || ( $http_host && $url_host === $http_host );

			$resolved_src = $src;
			if ( $same_orig && ! empty( $parsed['path'] ) ) {
				$resolved_src = $parsed['path'] . ( ! empty( $parsed['query'] ) ? '?' . $parsed['query'] : '' );
			}
			return sprintf( '<script type="module" src="%s" id="mr-tee-frontend-js-js"></script>', esc_url( $resolved_src ) );
		}
		return $tag;
	}

	/**
	 * Check if a specific product ID is configured to use Mr. Tee Editor.
	 *
	 * @param int $product_id Product ID.
	 * @return bool
	 */
	public static function is_mr_tee_product( $product_id ) {
		$pid = (int) $product_id;
		if ( $pid <= 0 ) {
			return false;
		}
		$engine = get_post_meta( $pid, '_mr_tee_engine_mode', true );
		if ( empty( $engine ) ) {
			$engine = get_post_meta( $pid, '_mr_tee_editor_mode', true );
		}
		if ( empty( $engine ) ) {
			$saved = get_post_meta( $pid, '_mr_tee_config', true );
			if ( is_array( $saved ) && ! empty( $saved['editor_mode'] ) ) {
				$engine = $saved['editor_mode'];
			}
		}
		return 'mr_tee_editor' === $engine;
	}

	/**
	 * Resolve the active Mr. Tee Editor product ID across standard single-product templates,
	 * `[product_page]` shortcodes, and page-builder product contexts.
	 *
	 * @return int
	 */
	public static function get_active_product_id() {
		if ( function_exists( 'is_product' ) && is_product() ) {
			$qid = get_queried_object_id();
			if ( $qid > 0 && self::is_mr_tee_product( $qid ) ) {
				return (int) $qid;
			}
			global $post;
			if ( $post && 'product' === $post->post_type && self::is_mr_tee_product( $post->ID ) ) {
				return (int) $post->ID;
			}
		}

		global $product;
		if ( is_object( $product ) && method_exists( $product, 'get_id' ) ) {
			$pid = (int) $product->get_id();
			if ( $pid > 0 && self::is_mr_tee_product( $pid ) ) {
				return $pid;
			}
		}

		global $post;
		if ( $post && 'product' === $post->post_type && self::is_mr_tee_product( $post->ID ) ) {
			return (int) $post->ID;
		}

		if ( $post && is_string( $post->post_content ) && has_shortcode( $post->post_content, 'product_page' ) ) {
			if ( preg_match( '/\[product_page[^\]]*id=["\']?(\d+)["\']?/', $post->post_content, $m ) ) {
				$sc_pid = absint( $m[1] );
				if ( $sc_pid > 0 && self::is_mr_tee_product( $sc_pid ) ) {
					return $sc_pid;
				}
			}
		}

		return 0;
	}

	/**
	 * Check if Mr. Tee Editor is active on the current product context.
	 *
	 * @return bool
	 */
	public static function is_active() {
		return self::get_active_product_id() > 0;
	}

	/**
	 * Prevent NBDesigner frontend design button from rendering alongside Mr. Tee Editor.
	 *
	 * @param bool $show Existing NBDesigner flag.
	 * @return bool
	 */
	public static function filter_nbd_show_design_btn( $show ) {
		if ( self::is_active() ) {
			return false;
		}
		global $product;
		if ( is_object( $product ) && method_exists( $product, 'get_id' ) && self::is_mr_tee_product( $product->get_id() ) ) {
			return false;
		}
		return $show;
	}

	/**
	 * Remove NBDesigner single-product design button callbacks (including third-party mobile relocations)
	 * whenever Mr. Tee Editor owns the current product page.
	 */
	public static function suppress_nbd_frontend_hooks() {
		if ( ! self::is_active() ) {
			return;
		}
		global $nb_designer;
		if ( is_object( $nb_designer ) ) {
			foreach ( array( 'woocommerce_before_add_to_cart_button', 'woocommerce_before_add_to_cart_form', 'woocommerce_after_add_to_cart_button', 'woocommerce_single_product_summary' ) as $hook ) {
				remove_action( $hook, array( $nb_designer, 'nbdesigner_button' ), 30 );
				remove_action( $hook, array( $nb_designer, 'nbdesigner_button' ), 1 );
				remove_action( $hook, array( $nb_designer, 'nbdesigner_button2' ), 30 );
				remove_action( $hook, array( $nb_designer, 'nbd_before_add_to_cart_button' ), 10 );
			}
		}
	}

	/**
	 * Multibyte-safe uppercase helper.
	 *
	 * @param string $text Input text.
	 * @return string
	 */
	private static function upper_utf8( $text ) {
		$str = (string) $text;
		return function_exists( 'mb_strtoupper' ) ? mb_strtoupper( $str, 'UTF-8' ) : strtoupper( $str );
	}

	/**
	 * Multibyte-safe string length helper.
	 *
	 * @param string $text Input text.
	 * @return int
	 */
	private static function strlen_utf8( $text ) {
		$str = (string) $text;
		return function_exists( 'mb_strlen' ) ? mb_strlen( $str, 'UTF-8' ) : strlen( $str );
	}

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
		$product_id = self::get_active_product_id();
		if ( $product_id > 0 ) {
			$classes[] = 'has-mr-tee-editor';
			$config    = self::get_cached_config( $product_id );
			if ( ! empty( $config['lock_aspect_ratio'] ) || ( isset( $config['product_type'] ) && 'flat_object' === $config['product_type'] ) ) {
				$classes[] = 'mr-tee-locked-aspect-ratio';
			}
		}
		return $classes;
	}

	/**
	 * Build a file-modification-aware asset version string so CDN/proxy caches bust on deploys.
	 *
	 * @param string $relative_path Path relative to MR_TEE_EDITOR_DIR.
	 * @return string
	 */
	private static function get_asset_version( $relative_path ) {
		$full_path = MR_TEE_EDITOR_DIR . ltrim( $relative_path, '/' );
		$mtime     = file_exists( $full_path ) ? @filemtime( $full_path ) : false;
		return $mtime ? ( MR_TEE_EDITOR_VERSION . '.' . $mtime ) : MR_TEE_EDITOR_VERSION;
	}

	/**
	 * Output high-priority performance hints in wp_head:
	 * - Emits <link rel="modulepreload"> for mr-tee-frontend.js and its 6 ES modules to eliminate the footer waterfall.
	 * - Preloads glyph images used by default text with fetchpriority="high" so 3D fonts render instantly without flicker.
	 */
	public static function render_performance_hints() {
		$product_id = self::get_active_product_id();
		if ( $product_id <= 0 ) {
			return;
		}

		// 1. Preload ES6 module graph in <head> to eliminate the 6-file discovery waterfall at bottom of <body>
		$base_parsed = wp_parse_url( MR_TEE_EDITOR_URL );
		$url_host    = ! empty( $base_parsed['host'] ) ? $base_parsed['host'] : '';
		$site_host   = wp_parse_url( home_url(), PHP_URL_HOST );
		$http_host   = isset( $_SERVER['HTTP_HOST'] ) ? preg_replace( '/:\d+$/', '', sanitize_text_field( wp_unslash( $_SERVER['HTTP_HOST'] ) ) ) : '';
		$same_orig   = ! $url_host || $url_host === $site_host || ( $http_host && $url_host === $http_host );
		$base_path   = ( $same_orig && ! empty( $base_parsed['path'] ) ) ? trailingslashit( $base_parsed['path'] ) : trailingslashit( MR_TEE_EDITOR_URL );
		$entry_ver   = self::get_asset_version( 'assets/js/mr-tee-frontend.js' );
		$mod_ver     = MR_TEE_EDITOR_VERSION;

		printf(
			"<link rel=\"modulepreload\" href=\"%s\" />\n",
			esc_url( $base_path . 'assets/js/mr-tee-frontend.js?ver=' . $entry_ver )
		);

		$es_modules = array(
			'state-store.js',
			'scene-graph-engine.js',
			'svg-renderer.js',
			'dedicated-gallery.js',
			'gallery-stage.js',
			'form-controller.js',
			'print-exporter.js',
		);
		foreach ( $es_modules as $mod_file ) {
			printf(
				"<link rel=\"modulepreload\" href=\"%s\" />\n",
				esc_url( $base_path . 'assets/js/modules/' . $mod_file . '?v=' . $mod_ver )
			);
		}

		$config = self::get_cached_config( $product_id );
		if ( empty( $config['text_lines'] ) || ! is_array( $config['text_lines'] ) ) {
			return;
		}

		$preloaded_images = array();
		foreach ( $config['text_lines'] as $line ) {
			if ( isset( $line['field_type'] ) && 'image' === $line['field_type'] ) {
				if ( ! empty( $line['default_image'] ) && ! isset( $preloaded_images[ $line['default_image'] ] ) && count( $preloaded_images ) < 24 ) {
					$preloaded_images[ $line['default_image'] ] = true;
					printf(
						"<link rel=\"preload\" href=\"%s\" as=\"image\" fetchpriority=\"high\" />\n",
						esc_url( $line['default_image'] )
					);
				}
				continue;
			}
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
					$default_text = self::upper_utf8( $default_text );
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
						$ch_upper    = self::upper_utf8( $ch );
						$g           = isset( $svg_data['glyphs'][ $ch ] ) ? $svg_data['glyphs'][ $ch ] : ( isset( $svg_data['glyphs'][ $ch_upper ] ) ? $svg_data['glyphs'][ $ch_upper ] : null );
						if ( $g && ! empty( $g['svg'] ) ) {
							if ( preg_match( '/(xlink:href|href)="([^"]+)"/', $g['svg'], $im ) ) {
								$img_url = $im[2];
								if ( ! isset( $preloaded_images[ $img_url ] ) && count( $preloaded_images ) < 24 ) {
									$preloaded_images[ $img_url ] = true;
									$img_path  = wp_parse_url( $img_url, PHP_URL_PATH );
									$type_attr = ( is_string( $img_path ) && '.webp' === strtolower( substr( $img_path, -5 ) ) ) ? ' type="image/webp"' : '';
									printf(
										"<link rel=\"preload\" href=\"%s\" as=\"image\"%s fetchpriority=\"high\" />\n",
										esc_url( $img_url ),
										$type_attr
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
	 *
	 * @param int $forced_product_id Optional product ID when invoked lazily from a shortcode/builder.
	 */
	public static function enqueue_assets( $forced_product_id = 0 ) {
		if ( self::$assets_enqueued ) {
			return;
		}
		$product_id = (int) $forced_product_id > 0 ? (int) $forced_product_id : self::get_active_product_id();
		if ( $product_id <= 0 ) {
			return;
		}
		self::$assets_enqueued = true;

		$config = self::get_cached_config( $product_id );

		// Determine allowed fonts strictly for this product
		$all_fonts        = FontRegistry::get_fonts();
		$allowed_font_ids = array();
		if ( ! empty( $config['allowed_fonts'] ) && is_array( $config['allowed_fonts'] ) ) {
			$allowed_font_ids = $config['allowed_fonts'];
		} else {
			if ( ! empty( $config['text_lines'] ) && is_array( $config['text_lines'] ) ) {
				foreach ( $config['text_lines'] as $tl ) {
					if ( ( empty( $tl['field_type'] ) || 'image' !== $tl['field_type'] ) && ! empty( $tl['allowed_fonts'] ) && is_array( $tl['allowed_fonts'] ) ) {
						$allowed_font_ids = $tl['allowed_fonts'];
						break;
					}
				}
			}
			if ( empty( $allowed_font_ids ) ) {
				$allowed_font_ids = array_keys( $all_fonts );
			}
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
			self::get_asset_version( 'assets/css/mr-tee-frontend.css' )
		);

		// Dedicated lightweight storefront gallery styles (loads after mr-tee-frontend-style)
		wp_enqueue_style(
			'mr-tee-gallery-style',
			MR_TEE_EDITOR_URL . 'assets/css/mr-tee-gallery.css',
			array( 'mr-tee-frontend-style' ),
			self::get_asset_version( 'assets/css/mr-tee-gallery.css' )
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
			self::get_asset_version( 'assets/js/mr-tee-frontend.js' ),
			true
		);

		$proto = is_ssl() ? 'https' : 'http';

		// Inline ONLY the active default OpenType-SVG font(s) for 0-RTT initial render;
		// secondary allowed OpenType-SVG fonts are lazy-loaded via manifest_url in the background.
		$preloaded_svg_fonts = array();
		if ( ! empty( $config['text_lines'] ) && is_array( $config['text_lines'] ) ) {
			foreach ( $config['text_lines'] as $line ) {
				if ( isset( $line['field_type'] ) && 'image' === $line['field_type'] ) {
					continue;
				}
				$active_fid = ! empty( $line['font'] ) ? $line['font'] : ( ! empty( $line['allowed_fonts'][0] ) ? $line['allowed_fonts'][0] : '' );
				if ( empty( $active_fid ) || isset( $preloaded_svg_fonts[ $active_fid ] ) ) {
					continue;
				}
				$f_def = isset( $filtered_fonts[ $active_fid ] ) ? $filtered_fonts[ $active_fid ] : FontRegistry::get_font( $active_fid );
				if ( ! empty( $f_def ) && ( ( ! empty( $f_def['font_type'] ) && 'opentype_svg' === $f_def['font_type'] ) || ! empty( $f_def['color_svg'] ) ) ) {
					$svg_data = FontRegistry::get_svg_font_data( $active_fid );
					if ( $svg_data ) {
						unset( $svg_data['fullMap'] );
						$svg_data['preview']                 = false;
						$preloaded_svg_fonts[ $active_fid ] = $svg_data;
					}
				}
			}
		}

		$thumb_id          = get_post_thumbnail_id( $product_id );
		$product_image_url = $thumb_id ? ( wp_get_attachment_image_url( $thumb_id, 'large' ) ?: wp_get_attachment_image_url( $thumb_id, 'full' ) ) : '';
		if ( ! empty( $product_image_url ) && ! is_ssl() ) {
			$product_image_url = set_url_scheme( $product_image_url, 'http' );
		}
		$cart_url       = function_exists( 'wc_get_cart_url' ) ? wc_get_cart_url() : '';
		$gallery_slides = ProductGalleryRenderer::get_gallery_slides( $product_id, $config );

		wp_localize_script(
			'mr-tee-frontend-js',
			'mrTeeProductData',
			array(
				'productId'       => $product_id,
				'config'          => $config,
				'gallerySlides'   => $gallery_slides,
				'fonts'           => $filtered_fonts,
				'svgFonts'        => $preloaded_svg_fonts,
				'ajaxUrl'         => admin_url( 'admin-ajax.php', $proto ),
				'cartUrl'         => $cart_url,
				'productImageUrl' => $product_image_url ? $product_image_url : '',
				'uploadNonce'     => wp_create_nonce( 'mr_tee_frontend_upload' ),
				'pluginUrl'       => MR_TEE_EDITOR_URL,
				'fontFaceCss'     => $font_face_css,
				'i18n'            => array(
					'warningTitle'     => __( 'Attention ! Personnalisation incomplète', 'mr-tee-editor' ),
					'warningMessage'   => __( 'Un ou plusieurs champs n\'ont pas été personnalisés. Souhaitez-vous continuer avec les valeurs par défaut ?', 'mr-tee-editor' ),
					'confirmKeep'      => __( 'Continuer quand même', 'mr-tee-editor' ),
					'cancelEdit'       => __( 'Modifier ma personnalisation', 'mr-tee-editor' ),
					'characters'       => __( 'caractères', 'mr-tee-editor' ),
					'generatingDpi'    => __( 'Préparation de l\'aperçu...', 'mr-tee-editor' ),
					'changeImage'      => __( 'Changer l\'image', 'mr-tee-editor' ),
					'importImage'      => __( 'Importer une image', 'mr-tee-editor' ),
					'uploadingImage'   => __( 'Envoi en cours...', 'mr-tee-editor' ),
					'imageDefault'     => __( 'Image par défaut', 'mr-tee-editor' ),
					'imageEmpty'       => __( 'Aucune image sélectionnée', 'mr-tee-editor' ),
					'imageUploadError' => __( 'Erreur lors du chargement de l\'image.', 'mr-tee-editor' ),
					'preparingOrder'   => __( 'Préparation...', 'mr-tee-editor' ),
					'exportUploadError'=> __( 'Impossible d\'envoyer votre personnalisation. Veuillez réessayer.', 'mr-tee-editor' ),
					'prevView'         => __( 'Vue précédente', 'mr-tee-editor' ),
					'nextView'         => __( 'Vue suivante', 'mr-tee-editor' ),
					'zoomIn'           => __( 'Zoom +', 'mr-tee-editor' ),
					'zoomOut'          => __( 'Zoom −', 'mr-tee-editor' ),
					'fullscreen'       => __( 'Agrandir l\'aperçu', 'mr-tee-editor' ),
					'close'            => __( 'Fermer', 'mr-tee-editor' ),
					'previewBtn'       => __( 'Aperçu', 'mr-tee-editor' ),
					'viewsLabel'       => __( 'Vues du produit', 'mr-tee-editor' ),
					'thumbsLabel'      => __( 'Miniatures des vues', 'mr-tee-editor' ),
				),
			)
		);
	}

	/**
	 * Render the personalization form on the single product page.
	 */
	public static function render_personalization_form() {
		global $product, $post;

		$product_id = 0;
		if ( is_object( $product ) && method_exists( $product, 'get_id' ) ) {
			$product_id = (int) $product->get_id();
		} elseif ( $post && 'product' === $post->post_type ) {
			$product_id = (int) $post->ID;
		} else {
			$product_id = self::get_active_product_id();
		}

		if ( $product_id <= 0 || ! self::is_mr_tee_product( $product_id ) ) {
			return;
		}

		// Prevent themes with sticky add-to-cart bars or duplicate product forms from emitting duplicate DOM IDs.
		if ( ! empty( self::$rendered_products[ $product_id ] ) ) {
			return;
		}
		self::$rendered_products[ $product_id ] = true;

		if ( ! self::$assets_enqueued ) {
			self::enqueue_assets( $product_id );
		}

		$wc_product = is_object( $product ) && method_exists( $product, 'is_type' ) ? $product : ( function_exists( 'wc_get_product' ) ? wc_get_product( $product_id ) : null );
		$config     = self::get_cached_config( $product_id );
		$all_fonts  = FontRegistry::get_fonts();
		$allowed_ids = array();
		if ( ! empty( $config['allowed_fonts'] ) && is_array( $config['allowed_fonts'] ) ) {
			$allowed_ids = $config['allowed_fonts'];
		} else {
			if ( ! empty( $config['text_lines'] ) && is_array( $config['text_lines'] ) ) {
				foreach ( $config['text_lines'] as $tl ) {
					if ( ( empty( $tl['field_type'] ) || 'image' !== $tl['field_type'] ) && ! empty( $tl['allowed_fonts'] ) && is_array( $tl['allowed_fonts'] ) ) {
						$allowed_ids = $tl['allowed_fonts'];
						break;
					}
				}
			}
			if ( empty( $allowed_ids ) ) {
				$allowed_ids = array_keys( $all_fonts );
			}
		}
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

		$side_labels = array(
			'front' => __( 'Recto', 'mr-tee-editor' ),
			'back'  => __( 'Verso', 'mr-tee-editor' ),
		);
		$has_multiple_sides = ! empty( $config['print_sides'] ) && is_array( $config['print_sides'] ) && count( $config['print_sides'] ) > 1;
		if ( ! empty( $config['print_sides'] ) && is_array( $config['print_sides'] ) ) {
			foreach ( $config['print_sides'] as $s_key => $s_def ) {
				if ( is_array( $s_def ) ) {
					$sid = ! empty( $s_def['id'] ) ? sanitize_key( $s_def['id'] ) : sanitize_key( (string) $s_key );
					if ( '' !== $sid && ! empty( $s_def['label'] ) ) {
						$side_labels[ $sid ] = (string) $s_def['label'];
					}
				}
			}
		}

		?>
		<div id="mr-tee-editor-container" class="mr-tee-editor-container" data-product-id="<?php echo esc_attr( $product_id ); ?>">
			<!-- Header Card: PERSONNALISER -->
			<div class="mr-tee-card mr-tee-main-card">
				<div class="mr-tee-card-header">
					<h3 class="mr-tee-card-title">
						<span class="mr-tee-badge-number">1</span>
						<?php esc_html_e( 'PERSONNALISER', 'mr-tee-editor' ); ?>
					</h3>
				</div>

				<!-- Text & Image Field Controls -->
				<div class="mr-tee-text-inputs-wrap">
					<?php foreach ( $config['text_lines'] as $idx => $line ) : ?>
						<?php
						$field_id       = 'mr_tee_line_' . $idx;
						$line_side_id   = ! empty( $line['side_id'] ) ? sanitize_key( $line['side_id'] ) : 'front';
						$side_label     = isset( $side_labels[ $line_side_id ] ) ? $side_labels[ $line_side_id ] : ucfirst( $line_side_id );
						$is_image_field = ( isset( $line['field_type'] ) && 'image' === $line['field_type'] );
						if ( $is_image_field ) :
							$default_img  = ! empty( $line['default_image'] ) ? $line['default_image'] : '';
							$allow_upload = ! isset( $line['allow_image_upload'] ) || ! empty( $line['allow_image_upload'] );
							$img_status   = $default_img ? __( 'Image par défaut', 'mr-tee-editor' ) : __( 'Aucune image sélectionnée', 'mr-tee-editor' );
							?>
							<div class="mr-tee-field-group mr-tee-field-group-image" data-line-index="<?php echo esc_attr( $idx ); ?>" data-line-id="<?php echo esc_attr( $line['id'] ); ?>" data-field-type="image" data-side-id="<?php echo esc_attr( $line_side_id ); ?>">
								<div class="mr-tee-image-field-card" data-default-image="<?php echo esc_attr( $default_img ); ?>" data-warning="<?php echo ! empty( $line['warning_unedited'] ) ? '1' : '0'; ?>">
									<div class="mr-tee-image-field-main">
										<div class="mr-tee-img-field-thumb <?php echo $default_img ? '' : 'is-empty'; ?>">
											<?php if ( $default_img ) : ?>
												<img src="<?php echo esc_url( $default_img ); ?>" alt="<?php echo esc_attr( $line['label'] ); ?>" />
											<?php else : ?>
												<span class="mr-tee-img-field-placeholder-icon" aria-hidden="true">🖼️</span>
											<?php endif; ?>
										</div>
										<div class="mr-tee-img-field-info">
											<div class="mr-tee-img-field-label">
												<?php echo esc_html( $line['label'] ); ?>
												<?php if ( $has_multiple_sides ) : ?>
													<span class="mr-tee-side-badge" data-side-id="<?php echo esc_attr( $line_side_id ); ?>"><?php echo esc_html( $side_label ); ?></span>
												<?php endif; ?>
											</div>
											<div class="mr-tee-img-field-status"><?php echo esc_html( $img_status ); ?></div>
										</div>
										<?php if ( $allow_upload ) : ?>
											<div class="mr-tee-img-field-actions">
												<input
													type="file"
													id="<?php echo esc_attr( $field_id ); ?>"
													class="mr-tee-image-file-input"
													accept="image/png,image/jpeg,image/webp,image/gif,image/svg+xml"
													data-line-index="<?php echo esc_attr( $idx ); ?>"
													data-ref-id="<?php echo esc_attr( $line['ref_id'] ); ?>"
													data-default-image="<?php echo esc_attr( $default_img ); ?>"
													data-warning="<?php echo ! empty( $line['warning_unedited'] ) ? '1' : '0'; ?>"
													style="display:none;"
												/>
												<button type="button" class="mr-tee-image-upload-btn" data-target-input="<?php echo esc_attr( $field_id ); ?>">
													<?php echo $default_img ? esc_html__( 'Changer l\'image', 'mr-tee-editor' ) : esc_html__( 'Importer une image', 'mr-tee-editor' ); ?>
												</button>
												<button type="button" class="mr-tee-image-reset-btn" data-line-index="<?php echo esc_attr( $idx ); ?>" style="display:none;" title="<?php esc_attr_e( 'Revenir à l\'image par défaut', 'mr-tee-editor' ); ?>">
													↺
												</button>
											</div>
										<?php endif; ?>
									</div>
								</div>
							</div>
							<?php
							continue;
						endif;
						$is_multiline = ! empty( $line['multiline'] );
						?>
						<div class="mr-tee-field-group" data-line-index="<?php echo esc_attr( $idx ); ?>" data-line-id="<?php echo esc_attr( $line['id'] ); ?>" data-side-id="<?php echo esc_attr( $line_side_id ); ?>">
							<div class="mr-tee-input-row">
								<div class="mr-tee-floating-label-wrap <?php echo $is_multiline ? 'mr-tee-floating-label-wrap--textarea' : ''; ?>">
									<?php if ( $is_multiline ) : ?>
										<textarea
											id="<?php echo esc_attr( $field_id ); ?>"
											class="mr-tee-text-input mr-tee-textarea"
											name="mr_tee_custom_text[<?php echo esc_attr( $idx ); ?>]"
											rows="<?php echo esc_attr( ! empty( $line['max_lines'] ) ? $line['max_lines'] : 3 ); ?>"
											maxlength="<?php echo esc_attr( $line['max_chars'] ); ?>"
											data-ref-id="<?php echo esc_attr( $line['ref_id'] ); ?>"
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
											data-ref-id="<?php echo esc_attr( $line['ref_id'] ); ?>"
											data-default="<?php echo esc_attr( $line['default_text'] ); ?>"
											data-warning="<?php echo ! empty( $line['warning_unedited'] ) ? '1' : '0'; ?>"
											placeholder=" "
											autocomplete="off"
										/>
									<?php endif; ?>
									<label for="<?php echo esc_attr( $field_id ); ?>" class="mr-tee-floating-label">
										<?php echo esc_html( $line['label'] ); ?>
										<?php if ( $has_multiple_sides ) : ?>
											<span class="mr-tee-side-badge" data-side-id="<?php echo esc_attr( $line_side_id ); ?>"><?php echo esc_html( $side_label ); ?></span>
										<?php endif; ?>
									</label>
									<span class="mr-tee-char-counter">
										<span class="mr-tee-char-count"><?php echo esc_html( (string) self::strlen_utf8( $line['default_text'] ) ); ?></span>/<?php echo esc_attr( $line['max_chars'] ); ?>
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
								<svg class="mr-tee-chevron-icon" width="18" height="18" viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M7.5 4.5L13 10l-5.5 5.5"/></svg>
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
										<svg class="mr-tee-chevron-icon" width="18" height="18" viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M5 7.5L10 12.5l5-5"/></svg>
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
											data-ref-id="id-ref-text-champ-<?php echo esc_attr( $item_num ); ?>"
											data-warning="1"
											placeholder=" "
											autocomplete="off"
										/>
										<label for="mr_tee_rep_name_<?php echo esc_attr( $item_num ); ?>" class="mr-tee-floating-label">
											<?php esc_html_e( 'Prénom', 'mr-tee-editor' ); ?>
										</label>
										<span class="mr-tee-char-counter">
											<span class="mr-tee-char-count"><?php echo esc_html( (string) self::strlen_utf8( $def_item['name'] ) ); ?></span>/<?php echo esc_attr( $rep['max_chars'] ); ?>
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
						<span class="mr-tee-warn-icon" aria-hidden="true">
							<svg width="24" height="24" viewBox="0 0 24 24" fill="#eab308" stroke="#854d0e" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><path d="M10.29 3.86L1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"/><line x1="12" y1="9" x2="12" y2="13" stroke="#ffffff" stroke-width="2"/><circle cx="12" cy="17" r="1" fill="#ffffff" stroke="none"/></svg>
						</span>
						<h4 class="mr-tee-modal-title"><?php esc_html_e( 'Attention ! Personnalisation incomplète', 'mr-tee-editor' ); ?></h4>
						<button type="button" class="mr-tee-modal-close-btn" id="mr-tee-close-warning-modal">&times;</button>
					</div>
					<div class="mr-tee-modal-body">
						<p id="mr-tee-warning-text">
							<?php esc_html_e( 'Un ou plusieurs champs n\'ont pas été personnalisés. Souhaitez-vous continuer avec les valeurs par défaut ?', 'mr-tee-editor' ); ?>
						</p>
					</div>
					<div class="mr-tee-modal-footer">
						<button type="button" class="button mr-tee-btn-cancel-warning" id="mr-tee-btn-modify">
							<?php esc_html_e( 'Modifier ma personnalisation', 'mr-tee-editor' ); ?>
						</button>
						<button type="button" class="button button-primary mr-tee-btn-ignore-warning" id="mr-tee-btn-proceed">
							<?php esc_html_e( 'Continuer quand même', 'mr-tee-editor' ); ?>
						</button>
					</div>
				</div>
			</div>

			<!-- Hidden Form Inputs for Cart Payload Submission -->
			<?php if ( ! $wc_product || ( ! $wc_product->is_type( 'variable' ) && ! $wc_product->is_type( 'grouped' ) ) ) : ?>
				<input type="hidden" name="add-to-cart" value="<?php echo esc_attr( $product_id ); ?>" />
			<?php endif; ?>
			<input type="hidden" name="mr_tee_design_key" id="mr_tee_design_key" value="" />
			<input type="hidden" name="mr_tee_editor_payload" id="mr_tee_editor_payload" value="" />
			<input type="hidden" name="mr_tee_preview_png" id="mr_tee_preview_png" value="" />
			<input type="hidden" name="mr_tee_previews_json" id="mr_tee_previews_json" value="" />
		</div>
		<?php
	}
}
