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
use MrTeeEditor\Registry\SvgFontPackager;
use MrTeeEditor\Registry\ColorPaletteRegistry;
use MrTeeEditor\Registry\IconSetRegistry;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ProductPageRenderer {

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
	 * Per-request cache of product ID => bool indicating Mr. Tee Editor mode.
	 *
	 * @var array<int, bool>
	 */
	private static $product_mode_cache = array();

	/**
	 * Per-request cache of active product ID once the main WP query (`wp` action) has run.
	 *
	 * @var int|null
	 */
	private static $active_product_id_cache = null;

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
	 * Filter script tag to enable ES6 module imports for mr-tee-frontend.js while preserving
	 * existing attributes such as CSP `nonce`, `id`, and custom `data-*` attributes.
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
			$safe_src = esc_url( $resolved_src );

			$updated = preg_replace_callback(
				'/<script\b([^>]*)\bsrc=(["\'])[^"\']*\2([^>]*)>/i',
				static function ( $m ) use ( $safe_src ) {
					$attrs = $m[1] . 'src="' . $safe_src . '"' . $m[3];
					if ( preg_match( '/\btype=(["\'])[^"\']*\1/i', $attrs ) ) {
						$attrs = preg_replace( '/\btype=(["\'])[^"\']*\1/i', 'type="module"', $attrs, 1 );
					} else {
						$attrs = ' type="module"' . $attrs;
					}
					return '<script' . $attrs . '>';
				},
				$tag,
				1
			);

			if ( is_string( $updated ) && '' !== $updated ) {
				return $updated;
			}
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
		if ( array_key_exists( $pid, self::$product_mode_cache ) ) {
			return self::$product_mode_cache[ $pid ];
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
		self::$product_mode_cache[ $pid ] = ( 'mr_tee_editor' === $engine );
		return self::$product_mode_cache[ $pid ];
	}

	/**
	 * Resolve the active Mr. Tee Editor product ID across standard single-product templates,
	 * `[product_page]` shortcodes, and page-builder product contexts without falsely activating
	 * on shop/archive/search views or related-product loops.
	 *
	 * @return int
	 */
	public static function get_active_product_id() {
		$can_cache = function_exists( 'did_action' ) && did_action( 'wp' ) > 0;
		if ( $can_cache && null !== self::$active_product_id_cache ) {
			return self::$active_product_id_cache;
		}

		$resolved = 0;

		if ( function_exists( 'is_product' ) && is_product() ) {
			$qid = get_queried_object_id();
			if ( $qid > 0 && self::is_mr_tee_product( $qid ) ) {
				$resolved = (int) $qid;
			}
		} elseif ( ! ( is_archive() || is_search() || ( function_exists( 'is_shop' ) && is_shop() ) ) ) {
			global $product;
			if ( is_object( $product ) && method_exists( $product, 'get_id' ) ) {
				$pid = (int) $product->get_id();
				if ( $pid > 0 && self::is_mr_tee_product( $pid ) ) {
					$resolved = $pid;
				}
			}

			if ( 0 === $resolved ) {
				global $post;
				if ( $post && 'product' === $post->post_type && self::is_mr_tee_product( $post->ID ) ) {
					$resolved = (int) $post->ID;
				} elseif ( $post && is_string( $post->post_content ) && has_shortcode( $post->post_content, 'product_page' ) ) {
					if ( preg_match( '/\[product_page[^\]]*id=["\']?(\d+)["\']?/', $post->post_content, $m ) ) {
						$sc_pid = absint( $m[1] );
						if ( $sc_pid > 0 && self::is_mr_tee_product( $sc_pid ) ) {
							$resolved = $sc_pid;
						}
					}
				}
			}
		}

		if ( $can_cache ) {
			self::$active_product_id_cache = $resolved;
		}

		return $resolved;
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
	 * Multibyte-safe lowercase helper.
	 *
	 * @param string $text Input text.
	 * @return string
	 */
	private static function lower_utf8( $text ) {
		$str = (string) $text;
		return function_exists( 'mb_strtolower' ) ? mb_strtolower( $str, 'UTF-8' ) : strtolower( $str );
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
	 * Resolve an OpenType-SVG glyph entry with case and NFD diacritic fallback (e.g. É -> E).
	 *
	 * @param array<string, array<string, mixed>> $glyphs Font glyph map.
	 * @param string                              $ch     Single UTF-8 character.
	 * @return array<string, mixed>|null
	 */
	private static function resolve_svg_glyph( array $glyphs, $ch ) {
		$candidates = array( $ch, self::upper_utf8( $ch ), self::lower_utf8( $ch ) );

		$base = '';
		if ( class_exists( '\Normalizer' ) ) {
			$nfd = \Normalizer::normalize( $ch, \Normalizer::FORM_D );
			if ( is_string( $nfd ) ) {
				$base = (string) preg_replace( '/\p{Mn}+/u', '', $nfd );
			}
		} elseif ( function_exists( 'remove_accents' ) ) {
			$base = remove_accents( $ch );
		}
		if ( is_string( $base ) && '' !== $base && $base !== $ch ) {
			$candidates[] = $base;
			$candidates[] = self::upper_utf8( $base );
			$candidates[] = self::lower_utf8( $base );
		}

		foreach ( $candidates as $cand ) {
			if ( '' !== $cand && isset( $glyphs[ $cand ] ) && ! empty( $glyphs[ $cand ]['svg'] ) ) {
				return $glyphs[ $cand ];
			}
		}
		return null;
	}

	/**
	 * Check whether a URL is safe to emit as a `<link rel="preload">` href (skips empty, data:, and # fragments).
	 *
	 * @param string $url Raw candidate URL.
	 * @return bool
	 */
	private static function is_preloadable_url( $url ) {
		if ( ! is_string( $url ) ) {
			return false;
		}
		$trimmed = trim( $url );
		if ( '' === $trimmed ) {
			return false;
		}
		if ( 0 === stripos( $trimmed, 'data:' ) || 0 === strpos( $trimmed, '#' ) ) {
			return false;
		}
		return true;
	}

	/**
	 * Retrieve compiled product configuration via SceneGraphSchema's per-request cache.
	 *
	 * @param int $product_id Product ID.
	 * @return array<string, mixed>
	 */
	public static function get_cached_config( $product_id ) {
		return SceneGraphSchema::get_product_config( (int) $product_id );
	}

	/**
	 * Resolve the ordered map of allowed fonts for a product configuration across all text lines
	 * (including multi-side Recto/Verso lines) while stripping internal server filesystem paths.
	 *
	 * @param array<string, mixed> $config Compiled product configuration.
	 * @return array<string, array<string, mixed>>
	 */
	private static function resolve_allowed_fonts_for_config( array $config ) {
		$all_fonts        = FontRegistry::get_fonts();
		$allowed_font_ids = array();

		if ( ! empty( $config['allowed_fonts'] ) && is_array( $config['allowed_fonts'] ) ) {
			foreach ( $config['allowed_fonts'] as $fid ) {
				if ( is_string( $fid ) && '' !== $fid && ! in_array( $fid, $allowed_font_ids, true ) ) {
					$allowed_font_ids[] = $fid;
				}
			}
		}

		if ( ! empty( $config['text_lines'] ) && is_array( $config['text_lines'] ) ) {
			foreach ( $config['text_lines'] as $tl ) {
				if ( ! is_array( $tl ) || ( isset( $tl['field_type'] ) && 'image' === $tl['field_type'] ) ) {
					continue;
				}
				if ( ! empty( $tl['font'] ) && is_string( $tl['font'] ) && ! in_array( $tl['font'], $allowed_font_ids, true ) ) {
					$allowed_font_ids[] = $tl['font'];
				}
				if ( ! empty( $tl['allowed_fonts'] ) && is_array( $tl['allowed_fonts'] ) ) {
					foreach ( $tl['allowed_fonts'] as $fid ) {
						if ( is_string( $fid ) && '' !== $fid && ! in_array( $fid, $allowed_font_ids, true ) ) {
							$allowed_font_ids[] = $fid;
						}
					}
				}
			}
		}

		if ( empty( $allowed_font_ids ) ) {
			$allowed_font_ids = array_keys( $all_fonts );
		}

		$filtered_fonts = array();
		foreach ( $allowed_font_ids as $f_id ) {
			if ( isset( $all_fonts[ $f_id ] ) ) {
				$f_entry = $all_fonts[ $f_id ];
				unset( $f_entry['path'] );
				$filtered_fonts[ $f_id ] = $f_entry;
			}
		}
		if ( empty( $filtered_fonts ) ) {
			foreach ( $all_fonts as $f_id => $f_entry ) {
				unset( $f_entry['path'] );
				$filtered_fonts[ $f_id ] = $f_entry;
			}
		}

		return $filtered_fonts;
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
	 * - Emits Slide 0 Hero LCP <link rel="preload" as="image" fetchpriority="high"> FIRST.
	 * - Emits <link rel="modulepreload"> for mr-tee-frontend.js and its 6 core ES modules using MR_TEE_EDITOR_VERSION.
	 * - Preloads Slide 0's active standard vector font(s) (max 2, WOFF2/TTF) with crossorigin.
	 * - Preloads Slide 0's default_image_preview and up to 10 unique OpenType-SVG default_text glyphs without fetchpriority="high".
	 */
	public static function render_performance_hints() {
		$product_id = self::get_active_product_id();
		if ( $product_id <= 0 ) {
			return;
		}

		$config         = self::get_cached_config( $product_id );
		$gallery_slides = ProductGalleryRenderer::get_gallery_slides( $product_id, $config );
		$slide0         = ! empty( $gallery_slides[0] ) && is_array( $gallery_slides[0] ) ? $gallery_slides[0] : null;
		$slide0_side_id = ( $slide0 && ! empty( $slide0['side_id'] ) ) ? (string) $slide0['side_id'] : 'front';

		// 1. Emit Slide 0 Hero LCP garment image preload FIRST in <head> (before modulepreloads)
		$preloaded_images = array();
		if ( $slide0 && ! empty( $slide0['image_url'] ) && self::is_preloadable_url( $slide0['image_url'] ) ) {
			$s0_url                      = $slide0['image_url'];
			$preloaded_images[ $s0_url ] = true;
			$srcset_attr                 = ! empty( $slide0['srcset'] ) ? sprintf( ' imagesrcset="%s"', esc_attr( $slide0['srcset'] ) ) : '';
			$sizes_attr                  = ! empty( $slide0['sizes'] ) ? sprintf( ' imagesizes="%s"', esc_attr( $slide0['sizes'] ) ) : '';
			printf(
				"<link rel=\"preload\" href=\"%s\" as=\"image\"%s%s fetchpriority=\"high\" />\n",
				esc_url( $s0_url ),
				$srcset_attr,
				$sizes_attr
			);
		}

		// 2. Preload ES6 module graph in <head> to eliminate the discovery waterfall at bottom of <body>
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
		);
		foreach ( $es_modules as $mod_file ) {
			printf(
				"<link rel=\"modulepreload\" href=\"%s\" />\n",
				esc_url( $base_path . 'assets/js/modules/' . $mod_file . '?v=' . $mod_ver )
			);
		}

		if ( empty( $config['text_lines'] ) || ! is_array( $config['text_lines'] ) ) {
			return;
		}

		// 3. Preload Slide 0's active standard vector fonts (max 2), default image previews,
		//    and up to 10 unique OpenType-SVG default_text glyphs (never competing with Hero LCP).
		$preloaded_fonts        = array();
		$checked_vector_fonts   = array();
		$glyph_preloads_emitted = 0;
		$has_font_url_helper    = method_exists( FontRegistry::class, 'get_primary_woff2_or_ttf_url' );

		foreach ( $config['text_lines'] as $line ) {
			if ( ! is_array( $line ) ) {
				continue;
			}
			$line_side = ! empty( $line['side_id'] ) ? (string) $line['side_id'] : 'front';
			if ( '' !== $slide0_side_id && $line_side !== $slide0_side_id ) {
				continue;
			}
			if ( isset( $line['field_type'] ) && 'image' === $line['field_type'] ) {
				$preload_img = ! empty( $line['default_image_preview'] )
					? (string) $line['default_image_preview']
					: ( ! empty( $line['default_image'] ) ? (string) $line['default_image'] : '' );
				if ( '' !== $preload_img && self::is_preloadable_url( $preload_img ) && ! isset( $preloaded_images[ $preload_img ] ) && count( $preloaded_images ) < 24 ) {
					$preloaded_images[ $preload_img ] = true;
					printf(
						"<link rel=\"preload\" href=\"%s\" as=\"image\" />\n",
						esc_url( $preload_img )
					);
				}
				continue;
			}

			$font_id = ! empty( $line['font'] ) ? (string) $line['font'] : ( ! empty( $line['allowed_fonts'][0] ) ? (string) $line['allowed_fonts'][0] : '' );
			if ( '' === $font_id ) {
				continue;
			}
			$font = FontRegistry::get_font( $font_id );
			if ( empty( $font ) ) {
				continue;
			}

			$resolved_fid = ! empty( $font['id'] ) ? (string) $font['id'] : $font_id;
			$is_ot_svg    = ( ! empty( $font['font_type'] ) && 'opentype_svg' === $font['font_type'] ) || ! empty( $font['color_svg'] );

			if ( ! $is_ot_svg ) {
				if ( $has_font_url_helper && count( $preloaded_fonts ) < 2 && ! isset( $checked_vector_fonts[ $resolved_fid ] ) ) {
					$checked_vector_fonts[ $resolved_fid ] = true;
					$font_asset                            = FontRegistry::get_primary_woff2_or_ttf_url( $resolved_fid );
					if ( is_array( $font_asset ) && ! empty( $font_asset['url'] ) && self::is_preloadable_url( $font_asset['url'] ) && ! isset( $preloaded_fonts[ $font_asset['url'] ] ) ) {
						$preloaded_fonts[ $font_asset['url'] ] = true;
						$font_mime                             = ! empty( $font_asset['type'] ) ? (string) $font_asset['type'] : 'font/woff2';
						printf(
							"<link rel=\"preload\" href=\"%s\" as=\"font\" type=\"%s\" crossorigin />\n",
							esc_url( $font_asset['url'] ),
							esc_attr( $font_mime )
						);
					}
				}
				continue;
			}

			// Preload up to 10 unique OpenType-SVG default_text glyphs without fetchpriority="high";
			// pass $allow_build = false so storefront wp_head NEVER runs a synchronous .otf build.
			if ( $glyph_preloads_emitted >= 10 ) {
				continue;
			}
			$default_text = ! empty( $line['default_text'] ) ? (string) $line['default_text'] : '';
			if ( '' !== $default_text ) {
				$is_upper = ( isset( $line['casing'] ) && 'UPPER' === $line['casing'] ) || ! empty( $font['uppercase'] );
				if ( $is_upper ) {
					$default_text = self::upper_utf8( $default_text );
				}
				$svg_data = FontRegistry::get_svg_font_data( $resolved_fid, false, false );
				if ( ! empty( $svg_data['glyphs'] ) && is_array( $svg_data['glyphs'] ) ) {
					$chars = preg_split( '//u', $default_text, -1, PREG_SPLIT_NO_EMPTY );
					$seen  = array();
					foreach ( $chars as $ch ) {
						if ( $glyph_preloads_emitted >= 10 ) {
							break;
						}
						if ( ' ' === $ch || "\n" === $ch || "\r" === $ch || "\t" === $ch || isset( $seen[ $ch ] ) ) {
							continue;
						}
						$seen[ $ch ] = true;
						$g           = self::resolve_svg_glyph( $svg_data['glyphs'], $ch );
						if ( $g && ! empty( $g['svg'] ) ) {
							if ( preg_match( '/(?:xlink:href|href)="([^"]+)"/', $g['svg'], $im ) ) {
								$img_url = $im[1];
								if ( self::is_preloadable_url( $img_url ) && ! isset( $preloaded_images[ $img_url ] ) && count( $preloaded_images ) < 24 ) {
									$preloaded_images[ $img_url ] = true;
									$glyph_preloads_emitted++;
									$img_path  = wp_parse_url( $img_url, PHP_URL_PATH );
									$type_attr = ( is_string( $img_path ) && '.webp' === strtolower( substr( $img_path, -5 ) ) ) ? ' type="image/webp"' : '';
									printf(
										"<link rel=\"preload\" href=\"%s\" as=\"image\"%s />\n",
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

		$config         = self::get_cached_config( $product_id );
		$filtered_fonts = self::resolve_allowed_fonts_for_config( $config );

		// Strip server-only & unused registry bloat before localizing to storefront HTML
		$localized_config = $config;
		unset(
			$localized_config['fonts'],
			$localized_config['color_sets'],
			$localized_config['solid_colors'],
			$localized_config['side_export_metrics'],
			$localized_config['cumulative_design_area'],
			$localized_config['garment_colors']
		);
		if ( isset( $localized_config['perso_visual'] ) && is_array( $localized_config['perso_visual'] ) ) {
			unset( $localized_config['perso_visual']['perso_visual_cfg'] );
		}
		if ( empty( $localized_config['repeater']['enabled'] ) ) {
			unset( $localized_config['icons'] );
			$localized_config['repeater'] = array(
				'enabled'       => false,
				'min_items'     => 0,
				'max_items'     => 0,
				'default_count' => 0,
			);
		} elseif ( ! empty( $localized_config['icons'] ) && is_array( $localized_config['icons'] ) ) {
			foreach ( $localized_config['icons'] as $ic_key => $ic_val ) {
				if ( is_array( $ic_val ) ) {
					unset( $localized_config['icons'][ $ic_key ]['path'] );
				}
			}
		}

		// Enqueue single minified storefront CSS bundle when available (1 blocking CSS request instead of 2),
		// falling back cleanly to separate unminified stylesheets if the bundle is absent.
		$bundle_rel = 'assets/css/mr-tee-storefront.min.css';
		if ( file_exists( MR_TEE_EDITOR_DIR . $bundle_rel ) ) {
			wp_enqueue_style(
				'mr-tee-frontend-style',
				MR_TEE_EDITOR_URL . $bundle_rel,
				array(),
				self::get_asset_version( $bundle_rel )
			);
		} else {
			wp_enqueue_style(
				'mr-tee-frontend-style',
				MR_TEE_EDITOR_URL . 'assets/css/mr-tee-frontend.css',
				array(),
				self::get_asset_version( 'assets/css/mr-tee-frontend.css' )
			);

			wp_enqueue_style(
				'mr-tee-gallery-style',
				MR_TEE_EDITOR_URL . 'assets/css/mr-tee-gallery.css',
				array( 'mr-tee-frontend-style' ),
				self::get_asset_version( 'assets/css/mr-tee-gallery.css' )
			);
		}

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

		// Only require jQuery on variable products (for WC variation events); simple products run 100% vanilla JS.
		$wc_product  = function_exists( 'wc_get_product' ) ? wc_get_product( $product_id ) : null;
		$is_variable = $wc_product && method_exists( $wc_product, 'is_type' ) && ( $wc_product->is_type( 'variable' ) || $wc_product->is_type( 'variable-subscription' ) );
		$script_deps = $is_variable ? array( 'jquery' ) : array();

		// Frontend JavaScript Orchestrator
		wp_enqueue_script(
			'mr-tee-frontend-js',
			MR_TEE_EDITOR_URL . 'assets/js/mr-tee-frontend.js',
			$script_deps,
			self::get_asset_version( 'assets/js/mr-tee-frontend.js' ),
			true
		);

		$proto             = is_ssl() ? 'https' : 'http';
		$gallery_slides    = ProductGalleryRenderer::get_gallery_slides( $product_id, $config );
		$slide0            = ! empty( $gallery_slides[0] ) && is_array( $gallery_slides[0] ) ? $gallery_slides[0] : null;
		$slide0_side_id    = ( $slide0 && ! empty( $slide0['side_id'] ) ) ? (string) $slide0['side_id'] : 'front';
		$product_image_url = ! empty( $gallery_slides[0]['image_url'] ) ? $gallery_slides[0]['image_url'] : '';
		$cart_url          = function_exists( 'wc_get_cart_url' ) ? wc_get_cart_url() : '';

		// Inline ONLY a lightweight partial bootstrap subset of Slide 0's active OpenType-SVG font(s)
		// containing ONLY Slide 0's default_text characters (~2-4 KB instead of ~110 KB).
		$slide0_ot_chars_by_font = array();
		if ( ! empty( $config['text_lines'] ) && is_array( $config['text_lines'] ) ) {
			foreach ( $config['text_lines'] as $line ) {
				if ( ! is_array( $line ) || ( isset( $line['field_type'] ) && 'image' === $line['field_type'] ) ) {
					continue;
				}
				$line_side = ! empty( $line['side_id'] ) ? (string) $line['side_id'] : 'front';
				if ( '' !== $slide0_side_id && $line_side !== $slide0_side_id ) {
					continue;
				}
				$active_fid = ! empty( $line['font'] ) ? (string) $line['font'] : ( ! empty( $line['allowed_fonts'][0] ) ? (string) $line['allowed_fonts'][0] : '' );
				if ( '' === $active_fid ) {
					continue;
				}
				$f_def = isset( $filtered_fonts[ $active_fid ] ) ? $filtered_fonts[ $active_fid ] : FontRegistry::get_font( $active_fid );
				if ( empty( $f_def ) || ( ( empty( $f_def['font_type'] ) || 'opentype_svg' !== $f_def['font_type'] ) && empty( $f_def['color_svg'] ) ) ) {
					continue;
				}
				if ( ! isset( $slide0_ot_chars_by_font[ $active_fid ] ) ) {
					$slide0_ot_chars_by_font[ $active_fid ] = array(
						'font'  => $f_def,
						'chars' => '',
					);
				}
				$def_text = isset( $line['default_text'] ) ? (string) $line['default_text'] : '';
				if ( '' !== $def_text ) {
					$is_upper = ( isset( $line['casing'] ) && 'UPPER' === $line['casing'] ) || ! empty( $f_def['uppercase'] );
					if ( $is_upper ) {
						$def_text = self::upper_utf8( $def_text );
					}
					$slide0_ot_chars_by_font[ $active_fid ]['chars'] .= $def_text;
				}
			}
		}

		$preloaded_svg_fonts = array();
		if ( ! empty( $slide0_ot_chars_by_font ) ) {
			$has_partial_builder = method_exists( SvgFontPackager::class, 'build_partial_bootstrap' );
			foreach ( $slide0_ot_chars_by_font as $active_fid => $ot_entry ) {
				$svg_data = FontRegistry::get_svg_font_data( $active_fid, false, false );
				if ( ! $svg_data ) {
					continue;
				}
				if ( $has_partial_builder ) {
					$manifest_url = ! empty( $ot_entry['font']['manifest_url'] )
						? (string) $ot_entry['font']['manifest_url']
						: SvgFontPackager::manifest_url( $active_fid );
					$partial = SvgFontPackager::build_partial_bootstrap( $svg_data, $ot_entry['chars'], $manifest_url );
					if ( ! empty( $partial ) ) {
						$preloaded_svg_fonts[ $active_fid ] = $partial;
					}
				} else {
					unset( $svg_data['fullMap'] );
					$svg_data['preview']                 = false;
					$preloaded_svg_fonts[ $active_fid ] = $svg_data;
				}
			}
		}

		// Strip unused server keys from localized fonts payload (keeping only client-consumed metadata).
		$keep_font_keys  = array(
			'id',
			'name',
			'family',
			'font_type',
			'color_svg',
			'manifest_url',
			'letter_spacing',
			'stroke',
			'stroke_width',
			'outside_only',
			'multi_color',
			'palette',
			'uppercase',
		);
		$localized_fonts = array();
		foreach ( $filtered_fonts as $f_id => $f_entry ) {
			if ( ! is_array( $f_entry ) ) {
				continue;
			}
			$is_ot_svg = ( ! empty( $f_entry['font_type'] ) && 'opentype_svg' === $f_entry['font_type'] ) || ! empty( $f_entry['color_svg'] );
			$slim      = array();
			foreach ( $keep_font_keys as $k ) {
				if ( array_key_exists( $k, $f_entry ) ) {
					$slim[ $k ] = $f_entry[ $k ];
				}
			}
			if ( ! $is_ot_svg && ! empty( $f_entry['url'] ) ) {
				$slim['url'] = $f_entry['url'];
			}
			$localized_fonts[ $f_id ] = $slim;
		}

		$payload = array(
			'productId'       => $product_id,
			'config'          => $localized_config,
			'gallerySlides'   => $gallery_slides,
			'fonts'           => $localized_fonts,
			'svgFonts'        => $preloaded_svg_fonts,
			'ajaxUrl'         => admin_url( 'admin-ajax.php', $proto ),
			'cartUrl'         => $cart_url,
			'productImageUrl' => $product_image_url ? $product_image_url : '',
			'uploadNonce'     => wp_create_nonce( 'mr_tee_frontend_upload' ),
			'pluginUrl'       => MR_TEE_EDITOR_URL,
			'i18n'            => array(
				'warningMessage'    => __( 'Un ou plusieurs champs n\'ont pas été personnalisés. Souhaitez-vous continuer avec les valeurs par défaut ?', 'mr-tee-editor' ),
				'warningSingle'     => __( 'Le champ suivant n\'a pas été personnalisé : %s. Voulez-vous continuer avec cette valeur par défaut ou modifier votre personnalisation ?', 'mr-tee-editor' ),
				'warningMulti'      => __( 'Les champs suivants n\'ont pas été personnalisés : %s. Voulez-vous continuer avec les valeurs par défaut ou modifier votre personnalisation ?', 'mr-tee-editor' ),
				'unnamed'           => __( 'Sans nom', 'mr-tee-editor' ),
				'fieldDefault'      => __( 'Champ', 'mr-tee-editor' ),
				'changeImage'       => __( 'Changer l\'image', 'mr-tee-editor' ),
				'importImage'       => __( 'Importer une image', 'mr-tee-editor' ),
				'uploadingImage'    => __( 'Envoi en cours...', 'mr-tee-editor' ),
				'imageDefault'      => __( 'Image par défaut', 'mr-tee-editor' ),
				'imageEmpty'        => __( 'Aucune image sélectionnée', 'mr-tee-editor' ),
				'imageUploadError'  => __( 'Erreur lors du chargement de l\'image.', 'mr-tee-editor' ),
				'imageInvalidType'  => __( 'Format d\'image non pris en charge (PNG, JPG, WebP, GIF, SVG).', 'mr-tee-editor' ),
				'imageTooLarge'     => __( 'L\'image dépasse la taille maximale autorisée (15 Mo).', 'mr-tee-editor' ),
				'preparingOrder'    => __( 'Préparation...', 'mr-tee-editor' ),
				'exportUploadError' => __( 'Impossible d\'envoyer votre personnalisation. Veuillez réessayer.', 'mr-tee-editor' ),
				'prevView'          => __( 'Vue précédente', 'mr-tee-editor' ),
				'nextView'          => __( 'Vue suivante', 'mr-tee-editor' ),
				'zoomIn'            => __( 'Zoom +', 'mr-tee-editor' ),
				'zoomOut'           => __( 'Zoom −', 'mr-tee-editor' ),
				'fullscreen'        => __( 'Agrandir l\'aperçu', 'mr-tee-editor' ),
				'close'             => __( 'Fermer', 'mr-tee-editor' ),
				'previewBtn'        => __( 'Aperçu', 'mr-tee-editor' ),
				'viewsLabel'        => __( 'Vues du produit', 'mr-tee-editor' ),
				'thumbsLabel'       => __( 'Miniatures des vues', 'mr-tee-editor' ),
				'multicolorLabel'   => __( 'Multicolore', 'mr-tee-editor' ),
				'clearField'        => __( 'Effacer le texte', 'mr-tee-editor' ),
				'colorLabel'        => __( 'Couleur', 'mr-tee-editor' ),
				'chooseColor'       => __( 'Choisir', 'mr-tee-editor' ),
			),
		);

		$encoded_payload = wp_json_encode( $payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
		if ( false !== $encoded_payload ) {
			$encoded_payload = str_ireplace( '</script', '<\/script', $encoded_payload );
			wp_add_inline_script(
				'mr-tee-frontend-js',
				'window.mrTeeProductData = ' . $encoded_payload . ';',
				'before'
			);
		}
	}

	/**
	 * Format a clean, customer-friendly font pill label by removing technical suffixes
	 * (`OpenType-SVG`, `personaluse`, random 4-5 char font-foundry upload hashes, etc.).
	 *
	 * @param string $raw_name Raw font name from FontRegistry.
	 * @return string
	 */
	public static function format_clean_font_label( $raw_name ) {
		$name = trim( (string) $raw_name );
		if ( '' === $name ) {
			return '';
		}
		// Strip parenthetical style descriptions ("(Impact Condensé)", "(Jeu Vidéo)", "(Ballon Or 3D OpenType-SVG)")
		// since pills already preview the typeface visually (and display fonts often lack parenthesis/accent glyphs)
		$stripped_parens = trim( (string) preg_replace( '/\s*\([^)]*\)/u', '', $name ) );
		if ( '' !== $stripped_parens ) {
			$name = $stripped_parens;
		}
		// Strip foundry "personaluse" concatenations (e.g. "WesaysopersonaluseBold" -> "Wesayso Bold")
		$name = (string) preg_replace( '/personal\s*use/iu', ' ', $name );
		// Split CamelCase words and glued "italic" suffixes (e.g. "Loungeitalic" -> "Lounge Italic")
		$name = (string) preg_replace( '/([a-z])([A-Z])/u', '$1 $2', $name );
		$name = (string) preg_replace( '/([A-Za-z]{3,})(italic)\b/iu', '$1 Italic', $name );
		// Strip trailing numeric foundry versions glued to a word (e.g. "Super Mario256" -> "Super Mario")
		$name = (string) preg_replace( '/([A-Za-z]{2,})\d{2,4}$/u', '$1', $name );
		// Strip trailing dafont/fontspace upload hashes (tokens containing a digit, optionally followed by a
		// short all-caps remnant split by CamelCase, e.g. "L3o7 W", "L3o OZ"). Real words ("Marker", "Mario") are kept.
		$name = (string) preg_replace( '/(?:\s+(?!\d[Dd]\b)[A-Za-z]*\d[A-Za-z0-9]*(?:\s+[A-Z]{1,3})?)+$/u', '', $name );
		// Strip raw filename weight abbreviations (meaningful weights such as "Bold"/"Black" are kept)
		$name = (string) preg_replace( '/\b(?:Bdit|Ita|Xbdxcn|Regular)\b/iu', '', $name );
		$name = trim( (string) preg_replace( '/\s{2,}/u', ' ', $name ), " \t\n\r\0\x0B-–—_·," );
		return '' !== $name ? $name : trim( (string) $raw_name );
	}

	/**
	 * Strip redundant side prefixes ("Recto (Cœur) — ", "Verso (Dos) — ") from a field label
	 * when the field is already rendered inside a dedicated Recto / Verso side group.
	 *
	 * @param string $raw_label Raw field label.
	 * @param string $side_id   Field side ID ('front', 'back', etc.).
	 * @return string
	 */
	private static function clean_field_label_for_side( $raw_label, $side_id ) {
		$label = trim( (string) $raw_label );
		if ( 'front' === $side_id ) {
			$stripped = trim( (string) preg_replace( '/^(?:Recto|Devant)(?:\s*\([^)]*\))?\s*[—–\-:]\s*/iu', '', $label ) );
			if ( '' !== $stripped ) {
				$label = $stripped;
			}
		} elseif ( 'back' === $side_id ) {
			$stripped = trim( (string) preg_replace( '/^(?:Verso|Dos)(?:\s*\([^)]*\))?\s*[—–\-:]\s*/iu', '', $label ) );
			if ( '' !== $stripped ) {
				$label = $stripped;
			}
		}
		return $label;
	}

	/**
	 * Match a hex color against a swatch palette using exact match first, then perceptual RGB proximity
	 * (Euclidean distance <= 32) so near-identical default reds/blues never create duplicate dots.
	 *
	 * @param string               $target_hex Target hex string (e.g. '#dc2626').
	 * @param array<string,string> $swatches   Palette map of hex => label.
	 * @return string Matched swatch hex key (lowercase) or empty string.
	 */
	private static function find_matching_swatch_hex( $target_hex, array $swatches ) {
		$norm = strtolower( trim( (string) $target_hex ) );
		if ( '' === $norm || 0 !== strpos( $norm, '#' ) ) {
			return '';
		}
		foreach ( $swatches as $s_hex => $s_label ) {
			if ( strtolower( trim( (string) $s_hex ) ) === $norm ) {
				return strtolower( trim( (string) $s_hex ) );
			}
		}
		$parse_rgb = static function ( $h ) {
			$c = ltrim( strtolower( trim( (string) $h ) ), '#' );
			if ( 3 === strlen( $c ) ) {
				$c = $c[0] . $c[0] . $c[1] . $c[1] . $c[2] . $c[2];
			}
			if ( ! preg_match( '/^[0-9a-f]{6}$/', $c ) ) {
				return null;
			}
			return array( hexdec( substr( $c, 0, 2 ) ), hexdec( substr( $c, 2, 2 ) ), hexdec( substr( $c, 4, 2 ) ) );
		};
		$t_rgb = $parse_rgb( $norm );
		if ( ! $t_rgb ) {
			return '';
		}
		$best_hex  = '';
		$best_dist = 33.0;
		foreach ( $swatches as $s_hex => $s_label ) {
			$s_rgb = $parse_rgb( $s_hex );
			if ( ! $s_rgb ) {
				continue;
			}
			$dist = sqrt(
				pow( $t_rgb[0] - $s_rgb[0], 2 )
				+ pow( $t_rgb[1] - $s_rgb[1], 2 )
				+ pow( $t_rgb[2] - $s_rgb[2], 2 )
			);
			if ( $dist < $best_dist ) {
				$best_dist = $dist;
				$best_hex  = strtolower( trim( (string) $s_hex ) );
			}
		}
		return $best_hex;
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

		$wc_product   = is_object( $product ) && method_exists( $product, 'is_type' ) ? $product : ( function_exists( 'wc_get_product' ) ? wc_get_product( $product_id ) : null );
		$config       = self::get_cached_config( $product_id );
		$fonts        = self::resolve_allowed_fonts_for_config( $config );
		$rep          = isset( $config['repeater'] ) && is_array( $config['repeater'] ) ? $config['repeater'] : array();
		$rep_enabled  = ! empty( $rep['enabled'] );
		$icons        = $rep_enabled ? IconSetRegistry::get_mushroom_icons() : array();
		$min_items    = isset( $rep['min_items'] ) ? max( 0, intval( $rep['min_items'] ) ) : 0;
		$max_items    = isset( $rep['max_items'] ) ? max( $min_items, intval( $rep['max_items'] ) ) : 6;
		$def_count    = isset( $rep['default_count'] ) ? max( $min_items, min( $max_items, intval( $rep['default_count'] ) ) ) : max( $min_items, 2 );
		$color_sets   = ColorPaletteRegistry::get_color_sets();

		$side_labels = array(
			'front' => __( 'Recto (Devant)', 'mr-tee-editor' ),
			'back'  => __( 'Verso (Dos)', 'mr-tee-editor' ),
		);
		$ordered_side_ids = array();
		if ( ! empty( $config['print_sides'] ) && is_array( $config['print_sides'] ) ) {
			foreach ( $config['print_sides'] as $s_key => $s_def ) {
				if ( is_array( $s_def ) ) {
					$sid = ! empty( $s_def['id'] ) ? sanitize_key( $s_def['id'] ) : sanitize_key( (string) $s_key );
					if ( '' !== $sid ) {
						if ( ! in_array( $sid, $ordered_side_ids, true ) ) {
							$ordered_side_ids[] = $sid;
						}
						if ( ! empty( $s_def['label'] ) ) {
							$side_labels[ $sid ] = (string) $s_def['label'];
						}
					}
				}
			}
		}

		// Group fields by print side while preserving each field's global $idx so JS indices stay 100% intact.
		$fields_by_side = array();
		if ( ! empty( $config['text_lines'] ) && is_array( $config['text_lines'] ) ) {
			foreach ( $config['text_lines'] as $idx => $line ) {
				$lsid = ! empty( $line['side_id'] ) ? sanitize_key( $line['side_id'] ) : 'front';
				if ( '' === $lsid ) {
					$lsid = 'front';
				}
				if ( ! isset( $fields_by_side[ $lsid ] ) ) {
					$fields_by_side[ $lsid ] = array();
					if ( ! in_array( $lsid, $ordered_side_ids, true ) ) {
						$ordered_side_ids[] = $lsid;
					}
				}
				$fields_by_side[ $lsid ][ $idx ] = $line;
			}
		}
		// Keep only sides that actually contain customizable fields
		$active_side_groups = array();
		foreach ( $ordered_side_ids as $sid ) {
			if ( ! empty( $fields_by_side[ $sid ] ) ) {
				$active_side_groups[ $sid ] = $fields_by_side[ $sid ];
			}
		}
		$has_multiple_sides = count( $active_side_groups ) > 1;
		$first_side_id      = ! empty( $active_side_groups ) ? (string) array_key_first( $active_side_groups ) : 'front';

		$svg_icon_front = '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M20.38 3.46L16 2a4 4 0 0 1-8 0L3.62 3.46a2 2 0 0 0-1.34 2.23l.58 3.47a1 1 0 0 0 .99.84H6v10c0 1.1.9 2 2 2h8a2 2 0 0 0 2-2V10h2.15a1 1 0 0 0 .99-.84l.58-3.47a2 2 0 0 0-1.34-2.23z"/></svg>';
		$svg_icon_back  = '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><polyline points="17 1 21 5 17 9"/><path d="M3 11V9a4 4 0 0 1 4-4h14"/><polyline points="7 23 3 19 7 15"/><path d="M21 13v2a4 4 0 0 1-4 4H3"/></svg>';
		$svg_icon_edit  = '<svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.1" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M12 20h9"/><path d="M16.5 3.5a2.121 2.121 0 0 1 3 3L7 19l-4 1 1-4L16.5 3.5z"/></svg>';
		?>
		<div id="mr-tee-editor-container" class="mr-tee-editor-container<?php echo $has_multiple_sides ? ' mr-tee-has-multi-sides' : ''; ?>" data-product-id="<?php echo esc_attr( $product_id ); ?>">
			<!-- Header Card: PERSONNALISER -->
			<div class="mr-tee-card mr-tee-main-card">
				<div class="mr-tee-card-header">
					<h3 class="mr-tee-card-title">
						<?php if ( $rep_enabled ) : ?>
							<span class="mr-tee-badge-number">1</span>
						<?php else : ?>
							<span class="mr-tee-title-icon" aria-hidden="true"><?php echo $svg_icon_edit; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></span>
						<?php endif; ?>
						<?php esc_html_e( 'Personnalisation', 'mr-tee-editor' ); ?>
					</h3>
				</div>

				<!-- Text & Image Field Controls (cleanly grouped by side when Recto + Verso exist) -->
				<div class="mr-tee-text-inputs-wrap">
					<?php foreach ( $active_side_groups as $group_side_id => $side_lines ) : ?>
						<?php
						$group_side_label = isset( $side_labels[ $group_side_id ] ) ? $side_labels[ $group_side_id ] : ucfirst( $group_side_id );
						$is_first_side    = ( $group_side_id === $first_side_id );
						$side_icon        = ( 'back' === $group_side_id ) ? $svg_icon_back : $svg_icon_front;
						$side_field_count = count( $side_lines );
						?>
						<?php if ( $has_multiple_sides ) : ?>
							<div class="mr-tee-side-section<?php echo $is_first_side ? ' is-active-side' : ''; ?>" data-side-id="<?php echo esc_attr( $group_side_id ); ?>">
								<button type="button" class="mr-tee-side-section-header" data-side-id="<?php echo esc_attr( $group_side_id ); ?>">
									<span class="mr-tee-side-section-title">
										<span class="mr-tee-side-section-icon" aria-hidden="true"><?php echo $side_icon; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></span>
										<span><?php echo esc_html( $group_side_label ); ?></span>
									</span>
									<span class="mr-tee-side-section-count">
										<?php
										echo esc_html(
											sprintf(
												/* translators: %d: number of customizable zones on this garment side */
												_n( '%d zone', '%d zones', $side_field_count, 'mr-tee-editor' ),
												$side_field_count
											)
										);
										?>
									</span>
								</button>
								<div class="mr-tee-side-section-body">
						<?php endif; ?>

						<?php foreach ( $side_lines as $idx => $line ) : ?>
							<?php
							$field_id       = 'mr_tee_line_' . $idx;
							$line_side_id   = ! empty( $line['side_id'] ) ? sanitize_key( $line['side_id'] ) : 'front';
							$display_label  = $has_multiple_sides
								? self::clean_field_label_for_side( $line['label'], $line_side_id )
								: (string) $line['label'];
							$is_image_field = ( isset( $line['field_type'] ) && 'image' === $line['field_type'] );

							if ( $is_image_field ) :
								$default_img       = ! empty( $line['default_image'] ) ? $line['default_image'] : '';
								$default_img_thumb = ! empty( $line['default_image_thumb'] ) ? $line['default_image_thumb'] : $default_img;
								$allow_upload      = ! isset( $line['allow_image_upload'] ) || ! empty( $line['allow_image_upload'] );
								$img_status        = $default_img ? __( 'Image par défaut', 'mr-tee-editor' ) : __( 'Aucune image sélectionnée', 'mr-tee-editor' );
								?>
								<div class="mr-tee-field-group mr-tee-field-group-image<?php echo ( $has_multiple_sides && $is_first_side ) ? ' is-active-side' : ''; ?>" data-line-index="<?php echo esc_attr( $idx ); ?>" data-line-id="<?php echo esc_attr( $line['id'] ); ?>" data-field-type="image" data-side-id="<?php echo esc_attr( $line_side_id ); ?>">
									<div class="mr-tee-image-field-card" data-default-image="<?php echo esc_attr( $default_img ); ?>" data-warning="<?php echo ! empty( $line['warning_unedited'] ) ? '1' : '0'; ?>">
										<div class="mr-tee-image-field-main">
											<div class="mr-tee-img-field-thumb <?php echo $default_img ? '' : 'is-empty'; ?>">
												<?php if ( $default_img ) : ?>
													<img src="<?php echo esc_url( $default_img_thumb ); ?>" alt="<?php echo esc_attr( $display_label ); ?>" width="44" height="44" loading="lazy" decoding="async" />
												<?php else : ?>
													<span class="mr-tee-img-field-placeholder-icon" aria-hidden="true"><svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="3" width="18" height="18" rx="2" ry="2"/><circle cx="8.5" cy="8.5" r="1.5"/><polyline points="21 15 16 10 5 21"/></svg></span>
												<?php endif; ?>
											</div>
											<div class="mr-tee-img-field-info">
												<div class="mr-tee-img-field-label" title="<?php echo esc_attr( $line['label'] ); ?>">
													<?php echo esc_html( $display_label ); ?>
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

							$is_multiline   = ! empty( $line['multiline'] );
							$active_font_id = ! empty( $line['font'] ) ? (string) $line['font'] : ( ! empty( $line['allowed_fonts'][0] ) ? (string) $line['allowed_fonts'][0] : '' );
							$active_f_def   = isset( $fonts[ $active_font_id ] ) ? $fonts[ $active_font_id ] : FontRegistry::get_font( $active_font_id );
							$is_active_ot   = ! empty( $active_f_def ) && ( ( ! empty( $active_f_def['font_type'] ) && 'opentype_svg' === $active_f_def['font_type'] ) || ! empty( $active_f_def['color_svg'] ) );
							$is_masked_line = ! empty( $line['is_masked'] );
							$hide_swatches  = $is_active_ot || $is_masked_line;

							$line_cset_id   = ! empty( $line['color_set_id'] ) ? (string) $line['color_set_id'] : '';
							$supports_multi = ! empty( $active_f_def['multi_color'] )
								|| ( ! empty( $active_f_def['palette'] ) && is_array( $active_f_def['palette'] ) && count( $active_f_def['palette'] ) > 1 )
								|| ( '' !== $line_cset_id && ! empty( $color_sets[ $line_cset_id ]['multiple'] ) );
							$line_color_mode = isset( $line['color_mode'] ) ? (string) $line['color_mode'] : 'solid';
							$is_multi_active = $supports_multi && 'multi' === $line_color_mode;
							$has_init_text   = '' !== (string) $line['default_text'];
							?>
							<div class="mr-tee-field-group<?php echo ( $has_multiple_sides && $is_first_side ) ? ' is-active-side' : ''; ?>" data-line-index="<?php echo esc_attr( $idx ); ?>" data-line-id="<?php echo esc_attr( $line['id'] ); ?>" data-side-id="<?php echo esc_attr( $line_side_id ); ?>">
								<div class="mr-tee-input-row">
									<div class="mr-tee-floating-label-wrap <?php echo $is_multiline ? 'mr-tee-floating-label-wrap--textarea' : ''; ?><?php echo $has_init_text ? ' has-value' : ''; ?>">
										<?php if ( $is_multiline ) : ?>
											<textarea
												id="<?php echo esc_attr( $field_id ); ?>"
												class="mr-tee-text-input mr-tee-textarea"
												rows="<?php echo esc_attr( ! empty( $line['max_lines'] ) ? $line['max_lines'] : 3 ); ?>"
												maxlength="<?php echo esc_attr( $line['max_chars'] ); ?>"
												data-max-lines="<?php echo esc_attr( ! empty( $line['max_lines'] ) ? $line['max_lines'] : 3 ); ?>"
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
												value="<?php echo esc_attr( $line['default_text'] ); ?>"
												maxlength="<?php echo esc_attr( $line['max_chars'] ); ?>"
												data-ref-id="<?php echo esc_attr( $line['ref_id'] ); ?>"
												data-default="<?php echo esc_attr( $line['default_text'] ); ?>"
												data-warning="<?php echo ! empty( $line['warning_unedited'] ) ? '1' : '0'; ?>"
												placeholder=" "
												autocomplete="off"
											/>
										<?php endif; ?>
										<label for="<?php echo esc_attr( $field_id ); ?>" class="mr-tee-floating-label" title="<?php echo esc_attr( $line['label'] ); ?>">
											<?php echo esc_html( $display_label ); ?>
										</label>
										<div class="mr-tee-input-meta-right">
											<span class="mr-tee-char-counter">
												<span class="mr-tee-char-count"><?php echo esc_html( (string) self::strlen_utf8( $line['default_text'] ) ); ?></span>/<?php echo esc_attr( $line['max_chars'] ); ?>
											</span>
											<button
												type="button"
												class="mr-tee-input-clear-btn"
												data-target-input="<?php echo esc_attr( $field_id ); ?>"
												aria-label="<?php esc_attr_e( 'Effacer le texte', 'mr-tee-editor' ); ?>"
												title="<?php esc_attr_e( 'Effacer le texte', 'mr-tee-editor' ); ?>"
												<?php echo $has_init_text ? '' : 'style="display:none;"'; ?>
											>&times;</button>
										</div>
									</div>
								</div>

								<!-- Unified Style Controls Row: Color Switch (left on mobile) + Visual Font Pills (right on same line) -->
								<?php
								$allowed_font_ids = ( ! empty( $line['allowed_fonts'] ) && is_array( $line['allowed_fonts'] ) )
									? $line['allowed_fonts']
									: array_keys( $fonts );
								$ordered_pills = array();
								foreach ( $allowed_font_ids as $f_id ) {
									if ( isset( $fonts[ $f_id ] ) ) {
										$ordered_pills[ $f_id ] = $fonts[ $f_id ];
									}
								}
								$has_font_picker  = ! empty( $line['allow_font_pick'] ) && count( $ordered_pills ) > 1;
								$has_color_picker = ! empty( $line['allow_color_pick'] ) && ! empty( $line['color_swatches'] ) && is_array( $line['color_swatches'] );

								$line_swatches      = array();
								$active_swatch_hex  = '';
								$active_swatch_name = '';
								if ( $has_color_picker ) {
									$line_swatches     = $line['color_swatches'];
									$active_swatch_hex = isset( $line['color_hex'] ) ? strtolower( trim( (string) $line['color_hex'] ) ) : '';
									if ( '' !== $active_swatch_hex && 0 === strpos( $active_swatch_hex, '#' ) ) {
										$matched_hex = self::find_matching_swatch_hex( $active_swatch_hex, $line_swatches );
										if ( '' !== $matched_hex ) {
											$active_swatch_hex = $matched_hex;
										} elseif ( ! $is_multi_active ) {
											$line_swatches = array( $active_swatch_hex => __( 'Par défaut', 'mr-tee-editor' ) ) + $line_swatches;
										}
									}

									if ( $is_multi_active ) {
										$active_swatch_name = __( 'Multicolore', 'mr-tee-editor' );
									} else {
										foreach ( $line_swatches as $s_hex => $s_label ) {
											if ( strtolower( trim( (string) $s_hex ) ) === $active_swatch_hex ) {
												$active_swatch_name = (string) $s_label;
												break;
											}
										}
									}
								}
								?>
								<?php if ( $has_font_picker ) : ?>
									<?php $color_tray_id = 'mr-tee-color-tray-' . $idx; ?>
									<div class="mr-tee-style-controls<?php echo $has_color_picker ? ' mr-tee-style-controls--dual' : ''; ?>">
										<?php if ( $has_color_picker ) : ?>
											<button
												type="button"
												class="mr-tee-color-trigger-btn"
												aria-expanded="false"
												aria-controls="<?php echo esc_attr( $color_tray_id ); ?>"
												aria-label="<?php echo esc_attr( sprintf( __( 'Couleur : %s', 'mr-tee-editor' ), '' !== $active_swatch_name ? $active_swatch_name : __( 'Choisir', 'mr-tee-editor' ) ) ); ?>"
												title="<?php echo esc_attr( '' !== $active_swatch_name ? $active_swatch_name : __( 'Couleur', 'mr-tee-editor' ) ); ?>"
												<?php echo $hide_swatches ? 'style="display:none;"' : ''; ?>
											>
												<span
													class="mr-tee-color-trigger-dot<?php echo $is_multi_active ? ' mr-tee-color-trigger-dot--multi' : ''; ?>"
													<?php if ( ! $is_multi_active && '' !== $active_swatch_hex ) : ?>
														style="background-color: <?php echo esc_attr( $active_swatch_hex ); ?>;"
													<?php endif; ?>
												></span>
												<svg class="mr-tee-color-trigger-caret" width="11" height="11" viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M5 7.5L10 12.5l5-5"/></svg>
											</button>
										<?php endif; ?>

										<div class="mr-tee-font-selector-row">
											<div class="mr-tee-font-pills" data-line-index="<?php echo esc_attr( $idx ); ?>">
												<?php foreach ( $ordered_pills as $f_id => $f_data ) : ?>
													<?php
													$is_pill_ot_svg  = ( ! empty( $f_data['font_type'] ) && 'opentype_svg' === $f_data['font_type'] ) || ! empty( $f_data['color_svg'] );
													$safe_family     = isset( $f_data['family'] ) ? preg_replace( '/["\'\\\\<>]/', '', (string) $f_data['family'] ) : '';
													$is_pill_current = ( $active_font_id === (string) $f_id );
													$clean_pill_name = self::format_clean_font_label( isset( $f_data['name'] ) ? (string) $f_data['name'] : (string) $f_id );
													?>
													<button
														type="button"
														class="mr-tee-font-pill<?php echo $is_pill_current ? ' is-active' : ''; ?>"
														data-font-id="<?php echo esc_attr( $f_id ); ?>"
														<?php if ( ! $is_pill_ot_svg && '' !== $safe_family ) : ?>
															data-font-family="<?php echo esc_attr( $safe_family ); ?>"
															<?php if ( $is_pill_current ) : ?>
																style="<?php echo esc_attr( "font-family: '" . $safe_family . "', sans-serif;" ); ?>"
															<?php endif; ?>
														<?php endif; ?>
														title="<?php echo esc_attr( $f_data['name'] ); ?>"
													>
														<?php if ( ! empty( $f_data['thumb_url'] ) ) : ?>
															<img src="<?php echo esc_url( $f_data['thumb_url'] ); ?>" alt="" class="mr-tee-pill-thumb" width="20" height="20" loading="lazy" decoding="async" fetchpriority="low" />
														<?php endif; ?>
														<span class="mr-tee-font-pill-text"><?php echo esc_html( $clean_pill_name ); ?></span>
													</button>
												<?php endforeach; ?>
											</div>
										</div>
									</div>
								<?php endif; ?>

								<!-- Color Swatch Selector Pills (shown ONLY when active font supports custom ink/thread colors; collapsible tray on mobile when paired with fonts) -->
								<?php if ( $has_color_picker ) : ?>
									<div
										id="<?php echo esc_attr( 'mr-tee-color-tray-' . $idx ); ?>"
										class="mr-tee-color-swatches-wrap<?php echo $has_font_picker ? ' mr-tee-color-swatches-wrap--collapsible' : ''; ?>"
										data-color-ref-id="<?php echo esc_attr( ! empty( $line['color_ref_id'] ) ? $line['color_ref_id'] : 'id-ref-color-' . $idx ); ?>"
										data-masked="<?php echo $is_masked_line ? '1' : '0'; ?>"
										<?php echo $hide_swatches ? 'style="display:none;"' : ''; ?>
									>
										<div class="mr-tee-color-tray-head">
											<label class="mr-tee-picker-label">
												<?php esc_html_e( 'Couleur :', 'mr-tee-editor' ); ?>
												<span class="mr-tee-active-color-name"><?php echo esc_html( $active_swatch_name ); ?></span>
											</label>
											<?php if ( $has_font_picker ) : ?>
												<button type="button" class="mr-tee-color-tray-close" aria-label="<?php esc_attr_e( 'Fermer la palette', 'mr-tee-editor' ); ?>">&times;</button>
											<?php endif; ?>
										</div>
										<div class="mr-tee-color-swatches-list">
											<button
												type="button"
												class="mr-tee-color-swatch-pill mr-tee-color-swatch-pill--multi<?php echo $is_multi_active ? ' is-active' : ''; ?>"
												data-color-hex="multi"
												data-color-name="<?php esc_attr_e( 'Multicolore', 'mr-tee-editor' ); ?>"
												aria-pressed="<?php echo $is_multi_active ? 'true' : 'false'; ?>"
												aria-label="<?php esc_attr_e( 'Multicolore', 'mr-tee-editor' ); ?>"
												title="<?php esc_attr_e( 'Multicolore', 'mr-tee-editor' ); ?>"
												<?php echo $supports_multi ? '' : 'style="display:none;"'; ?>
											></button>
											<?php foreach ( $line_swatches as $hex => $label ) : ?>
												<?php $is_swatch_active = ( ! $is_multi_active && '' !== $active_swatch_hex && strtolower( trim( (string) $hex ) ) === $active_swatch_hex ); ?>
												<button
													type="button"
													class="mr-tee-color-swatch-pill<?php echo $is_swatch_active ? ' is-active' : ''; ?>"
													data-color-hex="<?php echo esc_attr( $hex ); ?>"
													data-color-name="<?php echo esc_attr( $label ); ?>"
													aria-pressed="<?php echo $is_swatch_active ? 'true' : 'false'; ?>"
													aria-label="<?php echo esc_attr( $label ); ?>"
													style="background-color: <?php echo esc_attr( $hex ); ?>;"
													title="<?php echo esc_attr( $label ); ?>"
												></button>
											<?php endforeach; ?>
										</div>
									</div>
								<?php endif; ?>
							</div>
						<?php endforeach; ?>

						<?php if ( $has_multiple_sides ) : ?>
								</div>
							</div>
						<?php endif; ?>
					<?php endforeach; ?>
				</div>

				<!-- Repeater Trigger Accordion: "Nombre de champignons" -->
				<?php if ( $rep_enabled ) : ?>
					<?php
					$rep_label      = ! empty( $rep['label'] ) ? (string) $rep['label'] : __( 'Nombre d\'éléments', 'mr-tee-editor' );
					$rep_subtitle   = isset( $rep['subtitle'] ) ? (string) $rep['subtitle'] : '';
					$rep_item_label = ! empty( $rep['item_label'] ) ? (string) $rep['item_label'] : __( 'Élément', 'mr-tee-editor' );
					$rep_max_chars  = ! empty( $rep['max_chars'] ) ? intval( $rep['max_chars'] ) : 12;
					?>
					<div class="mr-tee-repeater-trigger-card">
						<button type="button" class="mr-tee-repeater-trigger-btn" id="mr-tee-open-count-modal">
							<div class="mr-tee-trigger-left">
								<span class="mr-tee-badge-number">2</span>
								<div class="mr-tee-trigger-texts">
									<strong class="mr-tee-trigger-title"><?php echo esc_html( $rep_label ); ?></strong>
									<span class="mr-tee-trigger-subtitle"><?php echo esc_html( $rep_subtitle ); ?></span>
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
										<img src="<?php echo esc_url( $icons[ $icon_key ]['url'] ); ?>" alt="Mushroom Icon" loading="lazy" decoding="async" />
									</div>
									<div class="mr-tee-rep-name-summary">
										<strong><?php echo esc_html( $rep_item_label . ' ' . $item_num ); ?> :</strong>
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
											value="<?php echo esc_attr( $def_item['name'] ); ?>"
											maxlength="<?php echo esc_attr( $rep_max_chars ); ?>"
											data-ref-id="id-ref-text-champ-<?php echo esc_attr( $item_num ); ?>"
											data-warning="1"
											placeholder=" "
											autocomplete="off"
										/>
										<label for="mr_tee_rep_name_<?php echo esc_attr( $item_num ); ?>" class="mr-tee-floating-label">
											<?php esc_html_e( 'Prénom', 'mr-tee-editor' ); ?>
										</label>
										<div class="mr-tee-input-meta-right">
											<span class="mr-tee-char-counter">
												<span class="mr-tee-char-count"><?php echo esc_html( (string) self::strlen_utf8( $def_item['name'] ) ); ?></span>/<?php echo esc_attr( $rep_max_chars ); ?>
											</span>
										</div>
									</div>

									<div class="mr-tee-icon-picker-wrap">
										<label class="mr-tee-picker-label"><?php esc_html_e( 'Choisir la couleur du champignon :', 'mr-tee-editor' ); ?></label>
										<div class="mr-tee-icon-options">
											<?php foreach ( $icons as $ic_id => $ic_data ) : ?>
												<label class="mr-tee-icon-option <?php echo $icon_key === $ic_id ? 'is-selected' : ''; ?>" title="<?php echo esc_attr( $ic_data['name'] ); ?>">
													<input
														type="radio"
														name="mr_tee_repeater_icon_<?php echo esc_attr( $i ); ?>"
														value="<?php echo esc_attr( $ic_id ); ?>"
														<?php checked( $icon_key, $ic_id ); ?>
													/>
													<img src="<?php echo esc_url( $ic_data['url'] ); ?>" alt="<?php echo esc_attr( $ic_data['name'] ); ?>" loading="lazy" decoding="async" />
												</label>
											<?php endforeach; ?>
										</div>
									</div>
								</div>
							</div>
						<?php endfor; ?>
					</div>

					<!-- Count Selector Drawer / Modal (rendered strictly when Repeater is enabled) -->
					<div id="mr-tee-count-modal" class="mr-tee-modal-backdrop" style="display:none;">
						<div class="mr-tee-modal-dialog">
							<div class="mr-tee-modal-header">
								<h4 class="mr-tee-modal-title"><?php echo esc_html( $rep_label ); ?></h4>
								<button type="button" class="mr-tee-modal-close-btn" id="mr-tee-close-count-modal">&times;</button>
							</div>
							<div class="mr-tee-modal-body">
								<p class="mr-tee-modal-desc"><?php esc_html_e( 'Combien d\'éléments souhaitez-vous afficher ?', 'mr-tee-editor' ); ?></p>
								<div class="mr-tee-count-stepper">
									<button type="button" class="mr-tee-step-btn" id="mr-tee-step-minus" aria-label="Moins">&minus;</button>
									<div class="mr-tee-step-val-display" id="mr-tee-step-val"><?php echo esc_html( $def_count ); ?></div>
									<button type="button" class="mr-tee-step-btn" id="mr-tee-step-plus" aria-label="Plus">&plus;</button>
								</div>
								<div class="mr-tee-count-dots">
									<?php for ( $c = $min_items; $c <= $max_items; $c++ ) : ?>
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
				<?php endif; ?>
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
