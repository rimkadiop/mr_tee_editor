<?php
/**
 * Mr. Tee Editor - Dedicated Storefront Product Gallery Renderer
 *
 * Replaces WooCommerce's heavy default gallery (Flexslider + Zoom + PhotoSwipe)
 * with a lightweight, zero-dependency multi-view GPU swipe gallery engineered
 * specifically for live SVG customization overlays across Classic and Block themes.
 *
 * @package MrTeeEditor\Frontend
 */

namespace MrTeeEditor\Frontend;

use MrTeeEditor\Domain\SceneGraphSchema;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ProductGalleryRenderer {

	/**
	 * Guard against duplicate hook registration.
	 *
	 * @var bool
	 */
	private static $initialized = false;

	/**
	 * Track product IDs whose dedicated gallery has already been rendered in this request
	 * so themes with duplicate mobile/desktop wrappers or related product loops never emit duplicate #mr-tee-gallery-root IDs.
	 *
	 * @var array<int, bool>
	 */
	private static $rendered_galleries = array();

	/**
	 * Per-request cache of computed gallery slides by product ID.
	 *
	 * @var array<int, array<int, array<string, mixed>>>
	 */
	private static $slides_cache = array();

	/**
	 * Register WordPress & WooCommerce gallery override hooks.
	 */
	public static function init() {
		if ( self::$initialized ) {
			return;
		}
		self::$initialized = true;

		add_filter( 'woocommerce_locate_template', array( __CLASS__, 'override_product_image_template' ), 99, 3 );
		add_filter( 'render_block', array( __CLASS__, 'override_product_gallery_block' ), 20, 2 );
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'dequeue_theme_gallery_bloat' ), 100 );
	}

	/**
	 * Resolve active product ID when WooCommerce renders single-product image templates.
	 * Strictly verifies global $product when present so related/upsell product loops or duplicate calls
	 * never hijack non-Mr. Tee products or output duplicate #mr-tee-gallery-root IDs.
	 *
	 * @return int
	 */
	private static function resolve_mr_tee_product_id() {
		global $product;
		if ( is_object( $product ) && method_exists( $product, 'get_id' ) ) {
			$pid = (int) $product->get_id();
			if ( $pid > 0 ) {
				if ( ! empty( self::$rendered_galleries[ $pid ] ) ) {
					return 0;
				}
				return ProductPageRenderer::is_mr_tee_product( $pid ) ? $pid : 0;
			}
		}

		$active_id = ProductPageRenderer::get_active_product_id();
		if ( $active_id > 0 && empty( self::$rendered_galleries[ $active_id ] ) ) {
			return $active_id;
		}

		return 0;
	}

	/**
	 * Override WooCommerce `single-product/product-image.php` exclusively on Mr. Tee products.
	 *
	 * @param string $template      Located template path.
	 * @param string $template_name Template relative name.
	 * @param string $template_path Template base path.
	 * @return string
	 */
	public static function override_product_image_template( $template, $template_name, $template_path ) {
		if ( 'single-product/product-image.php' !== $template_name ) {
			return $template;
		}

		if ( self::resolve_mr_tee_product_id() <= 0 ) {
			return $template;
		}

		$custom_template = MR_TEE_EDITOR_DIR . 'templates/single-product-gallery.php';
		if ( file_exists( $custom_template ) ) {
			return $custom_template;
		}

		return $template;
	}

	/**
	 * Override WooCommerce Block theme gallery blocks exclusively on Mr. Tee products.
	 *
	 * @param string               $block_content Rendered block HTML.
	 * @param array<string, mixed> $block         Parsed block array.
	 * @return string
	 */
	public static function override_product_gallery_block( $block_content, $block ) {
		if ( is_admin() || empty( $block['blockName'] ) ) {
			return $block_content;
		}

		$gallery_blocks = array(
			'woocommerce/product-image-gallery',
			'woocommerce/product-gallery',
		);
		if ( ! in_array( $block['blockName'], $gallery_blocks, true ) ) {
			return $block_content;
		}

		if ( ! ProductPageRenderer::is_active() ) {
			return $block_content;
		}

		$product_id = self::resolve_mr_tee_product_id();
		if ( $product_id <= 0 ) {
			return $block_content;
		}

		$gallery_html = self::render_gallery_html( $product_id );
		return '' !== $gallery_html ? $gallery_html : $block_content;
	}

	/**
	 * Dequeue WooCommerce's unused gallery JS/CSS bloat (Flexslider, Zoom, PhotoSwipe)
	 * and legacy NBDesigner frontend assets (AngularJS 1.6.9, nbdesigner.js/css)
	 * exclusively on Mr. Tee product pages (~350 KB saved per page load).
	 */
	public static function dequeue_theme_gallery_bloat() {
		if ( ! ProductPageRenderer::is_active() ) {
			return;
		}

		$unused_scripts = array(
			'flexslider',
			'zoom',
			'photoswipe',
			'photoswipe-ui-default',
			'angularjs',
			'nbdesigner',
			'nbd-vista',
			'app-modern',
			'nbd-bundle',
			'nbd-quote',
			'jquery-ui-vista',
		);
		foreach ( $unused_scripts as $handle ) {
			if ( wp_script_is( $handle, 'enqueued' ) || wp_script_is( $handle, 'registered' ) ) {
				wp_dequeue_script( $handle );
			}
		}

		global $wp_scripts;
		if ( is_object( $wp_scripts ) && ! empty( $wp_scripts->registered['custom-js']->src ) ) {
			if ( false !== strpos( (string) $wp_scripts->registered['custom-js']->src, 'web-to-print-online-designer' ) ) {
				wp_dequeue_script( 'custom-js' );
			}
		}

		$unused_styles = array(
			'photoswipe',
			'photoswipe-default-skin',
			'nbdesigner',
			'vista',
			'vista-rtl',
			'spectrum',
			'product-builder',
			'nbd-quote',
		);
		foreach ( $unused_styles as $handle ) {
			if ( wp_style_is( $handle, 'enqueued' ) || wp_style_is( $handle, 'registered' ) ) {
				wp_dequeue_style( $handle );
			}
		}
	}

	/**
	 * Normalize HTTP/HTTPS scheme to match active `is_ssl()` state without breaking external CDNs.
	 *
	 * @param string $url Raw image URL.
	 * @return string
	 */
	public static function normalize_url_scheme( $url ) {
		if ( empty( $url ) || ! is_string( $url ) ) {
			return '';
		}
		$url = trim( $url );
		if ( 0 !== strpos( $url, 'http://' ) && 0 !== strpos( $url, 'https://' ) ) {
			return $url;
		}

		$site_host = wp_parse_url( home_url(), PHP_URL_HOST );
		$http_host = isset( $_SERVER['HTTP_HOST'] ) ? preg_replace( '/:\d+$/', '', sanitize_text_field( wp_unslash( $_SERVER['HTTP_HOST'] ) ) ) : '';
		$url_host  = wp_parse_url( $url, PHP_URL_HOST );

		if ( $url_host && ( $url_host === $site_host || ( $http_host && $url_host === $http_host ) ) ) {
			return set_url_scheme( $url, is_ssl() ? 'https' : 'http' );
		}

		if ( is_ssl() && 0 === strpos( $url, 'http://' ) ) {
			return set_url_scheme( $url, 'https' );
		}

		return $url;
	}

	/**
	 * Normalize URL schemes across a responsive `srcset` attribute string.
	 *
	 * @param string $srcset Raw srcset string from WordPress.
	 * @return string
	 */
	private static function normalize_srcset_scheme( $srcset ) {
		if ( empty( $srcset ) || ! is_string( $srcset ) ) {
			return '';
		}
		$parts = explode( ',', $srcset );
		$out   = array();
		foreach ( $parts as $part ) {
			$trimmed = trim( $part );
			if ( '' === $trimmed ) {
				continue;
			}
			$tokens = preg_split( '/\s+/', $trimmed, 2 );
			$u      = self::normalize_url_scheme( $tokens[0] );
			$out[]  = isset( $tokens[1] ) ? ( $u . ' ' . $tokens[1] ) : $u;
		}
		return implode( ', ', $out );
	}

	/**
	 * Normalize URL path key for deduplicating images across preview_views and WooCommerce gallery.
	 *
	 * @param string $url Raw URL.
	 * @return string
	 */
	private static function normalize_dedup_key( $url ) {
		if ( empty( $url ) || ! is_string( $url ) ) {
			return '';
		}
		$path = wp_parse_url( $url, PHP_URL_PATH );
		if ( ! is_string( $path ) || '' === $path ) {
			return strtolower( trim( $url ) );
		}
		// Strip WP scaled/resized size suffixes (-scaled, -1024x1024, or both) for robust deduplication.
		$clean = preg_replace( '/(?:-scaled|-\d+x\d+)+(?=\.[a-z0-9]+$)/i', '', $path );
		return strtolower( $clean );
	}

	/**
	 * Resolve image URL, responsive srcset/sizes, thumbnail URL, alt text, and natural dimensions from an attachment ID.
	 *
	 * @param int    $attachment_id WP attachment ID.
	 * @param string $fallback_alt  Fallback alt text.
	 * @return array{image_url: string, srcset: string, sizes: string, thumb_url: string, alt: string, width: int, height: int, aspect_ratio: float}
	 */
	private static function resolve_attachment_image( $attachment_id, $fallback_alt = '' ) {
		$att_id = absint( $attachment_id );
		if ( $att_id <= 0 ) {
			return array(
				'image_url'    => '',
				'srcset'       => '',
				'sizes'        => '',
				'thumb_url'    => '',
				'alt'          => $fallback_alt,
				'width'        => 0,
				'height'       => 0,
				'aspect_ratio' => 1.0,
			);
		}

		$src = wp_get_attachment_image_src( $att_id, 'large' );
		if ( empty( $src ) || empty( $src[0] ) ) {
			$src = wp_get_attachment_image_src( $att_id, 'full' );
		}

		$image_url = ! empty( $src[0] ) ? self::normalize_url_scheme( $src[0] ) : '';
		$width     = ! empty( $src[1] ) ? (int) $src[1] : 0;
		$height    = ! empty( $src[2] ) ? (int) $src[2] : 0;

		$raw_srcset = function_exists( 'wp_get_attachment_image_srcset' ) ? wp_get_attachment_image_srcset( $att_id, 'large' ) : '';
		$srcset     = is_string( $raw_srcset ) && '' !== $raw_srcset ? self::normalize_srcset_scheme( $raw_srcset ) : '';
		$sizes      = '' !== $srcset ? '(max-width: 767px) 100vw, (max-width: 1200px) 52vw, 680px' : '';

		$thumb_src = wp_get_attachment_image_src( $att_id, 'woocommerce_gallery_thumbnail' );
		if ( empty( $thumb_src ) || empty( $thumb_src[0] ) ) {
			$thumb_src = wp_get_attachment_image_src( $att_id, 'thumbnail' );
		}
		$thumb_url = ! empty( $thumb_src[0] ) ? self::normalize_url_scheme( $thumb_src[0] ) : $image_url;

		$alt = get_post_meta( $att_id, '_wp_attachment_image_alt', true );
		if ( ! is_string( $alt ) || '' === trim( $alt ) ) {
			$alt = $fallback_alt;
		}

		$aspect_ratio = ( $width > 0 && $height > 0 ) ? round( $width / $height, 4 ) : 1.0;

		return array(
			'image_url'    => $image_url,
			'srcset'       => $srcset,
			'sizes'        => $sizes,
			'thumb_url'    => $thumb_url,
			'alt'          => $alt,
			'width'        => $width,
			'height'       => $height,
			'aspect_ratio' => $aspect_ratio,
		);
	}

	/**
	 * Return safe fallback placeholder image URL.
	 *
	 * @return string
	 */
	private static function get_placeholder_url() {
		$placeholder = function_exists( 'wc_placeholder_img_src' )
			? wc_placeholder_img_src( 'woocommerce_single' )
			: '';
		return self::normalize_url_scheme( $placeholder );
	}

	/**
	 * Build ordered list of storefront gallery slides (customizable views first,
	 * optionally followed by deduplicated static WooCommerce gallery photos).
	 *
	 * @param int                  $product_id WooCommerce product ID.
	 * @param array<string, mixed> $config     Compiled product configuration.
	 * @return array<int, array<string, mixed>>
	 */
	public static function get_gallery_slides( $product_id, array $config = array() ) {
		$product_id = absint( $product_id );
		if ( $product_id <= 0 ) {
			return array();
		}
		if ( isset( self::$slides_cache[ $product_id ] ) ) {
			return self::$slides_cache[ $product_id ];
		}
		if ( empty( $config ) ) {
			$config = ProductPageRenderer::get_cached_config( $product_id );
		}

		$wc_product    = function_exists( 'wc_get_product' ) ? wc_get_product( $product_id ) : null;
		$product_title = $wc_product && method_exists( $wc_product, 'get_name' )
			? (string) $wc_product->get_name()
			: get_the_title( $product_id );
		$featured_id   = (int) get_post_thumbnail_id( $product_id );
		if ( $featured_id <= 0 && $wc_product && method_exists( $wc_product, 'get_image_id' ) ) {
			$featured_id = (int) $wc_product->get_image_id();
		}

		$placeholder_url     = self::get_placeholder_url();
		$slides              = array();
		$used_attachment_ids = array();
		$used_url_keys       = array();
		$featured_used       = false;

		$raw_views = ( ! empty( $config['preview_views'] ) && is_array( $config['preview_views'] ) )
			? $config['preview_views']
			: array();

		// Legacy single-view fallback when product config has no explicit preview_views yet.
		if ( empty( $raw_views ) ) {
			$raw_views = array(
				array(
					'id'         => 'view_front_full',
					'side_id'    => 'front',
					'label'      => __( 'Recto', 'mr-tee-editor' ),
					'view_role'  => 'full',
					'blend_mode' => 'normal',
					'image_id'   => $featured_id,
					'image_url'  => '',
				),
			);
		}

		foreach ( array_values( $raw_views ) as $idx => $view ) {
			if ( ! is_array( $view ) ) {
				continue;
			}

			$view_id = ! empty( $view['id'] )
				? sanitize_key( $view['id'] )
				: ( ! empty( $view['view_id'] ) ? sanitize_key( $view['view_id'] ) : 'view_' . $idx );
			$side_id = ! empty( $view['side_id'] ) ? sanitize_key( $view['side_id'] ) : 'front';

			$raw_role  = ! empty( $view['view_role'] ) ? sanitize_key( $view['view_role'] ) : ( ! empty( $view['role'] ) ? sanitize_key( $view['role'] ) : 'full' );
			$view_role = in_array( $raw_role, array( 'full', 'closeup', 'detail' ), true ) ? $raw_role : 'full';

			$raw_blend  = ! empty( $view['blend_mode'] ) ? sanitize_key( $view['blend_mode'] ) : 'normal';
			$blend_mode = in_array( $raw_blend, array( 'normal', 'multiply', 'soft_ink' ), true ) ? $raw_blend : 'normal';

			$default_label = ( 'back' === $side_id )
				? __( 'Verso', 'mr-tee-editor' )
				: ( 'closeup' === $view_role ? __( 'Zoom', 'mr-tee-editor' ) : __( 'Recto', 'mr-tee-editor' ) );
			$label = ! empty( $view['label'] ) ? (string) $view['label'] : ( ! empty( $view['name'] ) ? (string) $view['name'] : $default_label );

			$att_id = 0;
			if ( ! empty( $view['image_id'] ) ) {
				$att_id = absint( $view['image_id'] );
			} elseif ( ! empty( $view['attachment_id'] ) ) {
				$att_id = absint( $view['attachment_id'] );
			}

			$raw_url   = ! empty( $view['image_url'] ) ? (string) $view['image_url'] : ( ! empty( $view['url'] ) ? (string) $view['url'] : '' );
			$raw_thumb = ! empty( $view['thumb_url'] ) ? (string) $view['thumb_url'] : '';

			// Primary front slide falls back to product featured image if no custom mockup URL was assigned.
			if ( $att_id <= 0 && '' === trim( $raw_url ) && $featured_id > 0 && ( 0 === $idx || 'front' === $side_id ) ) {
				$att_id = $featured_id;
			}

			$att_meta = $att_id > 0
				? self::resolve_attachment_image( $att_id, $product_title ? $product_title . ' - ' . $label : $label )
				: array(
					'image_url'    => '',
					'srcset'       => '',
					'sizes'        => '',
					'thumb_url'    => '',
					'alt'          => $product_title ? $product_title . ' - ' . $label : $label,
					'width'        => ! empty( $view['width'] ) ? absint( $view['width'] ) : 0,
					'height'       => ! empty( $view['height'] ) ? absint( $view['height'] ) : 0,
					'aspect_ratio' => ! empty( $view['aspect_ratio'] ) ? floatval( $view['aspect_ratio'] ) : 1.0,
				);

			$image_url = '' !== trim( $raw_url )
				? self::normalize_url_scheme( $raw_url )
				: $att_meta['image_url'];

			if ( '' === $image_url ) {
				$image_url = $placeholder_url;
			}

			// Use attachment srcset only if image_url comes from that attachment
			$use_srcset = ( $att_id > 0 && '' !== $att_meta['srcset'] && ( '' === trim( $raw_url ) || self::normalize_dedup_key( $raw_url ) === self::normalize_dedup_key( $att_meta['image_url'] ) ) );

			$thumb_url = '' !== trim( $raw_thumb )
				? self::normalize_url_scheme( $raw_thumb )
				: ( '' !== $att_meta['thumb_url'] ? $att_meta['thumb_url'] : $image_url );

			$width        = $att_meta['width'] > 0 ? $att_meta['width'] : ( ! empty( $view['width'] ) ? absint( $view['width'] ) : 0 );
			$height       = $att_meta['height'] > 0 ? $att_meta['height'] : ( ! empty( $view['height'] ) ? absint( $view['height'] ) : 0 );
			$aspect_ratio = ( $width > 0 && $height > 0 )
				? round( $width / $height, 4 )
				: ( ! empty( $view['aspect_ratio'] ) && floatval( $view['aspect_ratio'] ) > 0 ? round( floatval( $view['aspect_ratio'] ), 4 ) : 1.0 );

			if ( $att_id > 0 ) {
				$used_attachment_ids[ $att_id ] = true;
				if ( $att_id === $featured_id ) {
					$featured_used = true;
				}
			}
			$dedup_key = self::normalize_dedup_key( $image_url );
			if ( '' !== $dedup_key ) {
				$used_url_keys[ $dedup_key ] = true;
			}
			if ( $featured_id > 0 && ! $featured_used ) {
				$feat_url = wp_get_attachment_image_url( $featured_id, 'full' );
				if ( $feat_url && self::normalize_dedup_key( $feat_url ) === $dedup_key ) {
					$featured_used = true;
				}
			}

			$slides[] = array(
				'is_customizable' => true,
				'view_id'         => $view_id,
				'side_id'         => $side_id,
				'label'           => $label,
				'view_role'       => $view_role,
				'blend_mode'      => $blend_mode,
				'image_id'        => $att_id,
				'image_url'       => $image_url,
				'srcset'          => $use_srcset ? $att_meta['srcset'] : '',
				'sizes'           => $use_srcset ? $att_meta['sizes'] : '',
				'thumb_url'       => $thumb_url,
				'alt'             => ! empty( $view['alt'] ) ? (string) $view['alt'] : $att_meta['alt'],
				'width'           => $width,
				'height'          => $height,
				'aspect_ratio'    => $aspect_ratio,
			);
		}

		// Optionally append static WooCommerce gallery photos not already present in preview_views.
		if ( ! empty( $config['include_wc_gallery_slides'] ) && $wc_product && method_exists( $wc_product, 'get_gallery_image_ids' ) ) {
			$gallery_ids  = $wc_product->get_gallery_image_ids();
			$static_index = 1;
			if ( is_array( $gallery_ids ) ) {
				foreach ( $gallery_ids as $raw_att_id ) {
					$att_id = absint( $raw_att_id );
					if ( $att_id <= 0 ) {
						continue;
					}
					if ( isset( $used_attachment_ids[ $att_id ] ) ) {
						continue;
					}
					if ( $featured_used && $att_id === $featured_id ) {
						continue;
					}

					$att_meta = self::resolve_attachment_image(
						$att_id,
						$product_title ? sprintf( '%s - %s %d', $product_title, __( 'Photo', 'mr-tee-editor' ), $static_index ) : sprintf( __( 'Photo %d', 'mr-tee-editor' ), $static_index )
					);
					if ( '' === $att_meta['image_url'] ) {
						continue;
					}

					$dedup_key = self::normalize_dedup_key( $att_meta['image_url'] );
					if ( '' !== $dedup_key && isset( $used_url_keys[ $dedup_key ] ) ) {
						continue;
					}

					$used_attachment_ids[ $att_id ] = true;
					if ( '' !== $dedup_key ) {
						$used_url_keys[ $dedup_key ] = true;
					}

					$slides[] = array(
						'is_customizable' => false,
						'view_id'         => 'static_' . $att_id,
						'side_id'         => '',
						'label'           => sprintf( __( 'Photo %d', 'mr-tee-editor' ), $static_index ),
						'view_role'       => 'static',
						'blend_mode'      => 'normal',
						'image_id'        => $att_id,
						'image_url'       => $att_meta['image_url'],
						'srcset'          => $att_meta['srcset'],
						'sizes'           => $att_meta['sizes'],
						'thumb_url'       => $att_meta['thumb_url'],
						'alt'             => $att_meta['alt'],
						'width'           => $att_meta['width'],
						'height'          => $att_meta['height'],
						'aspect_ratio'    => $att_meta['aspect_ratio'],
					);
					$static_index++;
				}
			}
		}

		// Ultimate fallback if everything was empty.
		if ( empty( $slides ) ) {
			$slides[] = array(
				'is_customizable' => true,
				'view_id'         => 'view_front_full',
				'side_id'         => 'front',
				'label'           => __( 'Recto', 'mr-tee-editor' ),
				'view_role'       => 'full',
				'blend_mode'      => 'normal',
				'image_id'        => 0,
				'image_url'       => $placeholder_url,
				'srcset'          => '',
				'sizes'           => '',
				'thumb_url'       => $placeholder_url,
				'alt'             => $product_title ? $product_title : __( 'Aperçu produit', 'mr-tee-editor' ),
				'width'           => 0,
				'height'          => 0,
				'aspect_ratio'    => 1.0,
			);
		}

		self::$slides_cache[ $product_id ] = $slides;
		return $slides;
	}

	/**
	 * Produce a concise, readable pill label for storefront navigation by stripping
	 * leading numbering ("1. ", "2 - "), redundant side prefixes when clear, and verbose parentheticals.
	 *
	 * @param string $raw_label     Raw merchant view label.
	 * @param string $side_id       Side identifier ('front', 'back', etc.).
	 * @param string $view_role     Role ('full', 'closeup', 'detail', 'static').
	 * @param bool   $is_custom     Whether slide is customizable.
	 * @param bool   $has_multi_side Whether gallery spans multiple print sides.
	 * @return string
	 */
	public static function format_compact_view_label( $raw_label, $side_id = 'front', $view_role = 'full', $is_custom = true, $has_multi_side = false ) {
		$label = trim( (string) $raw_label );
		$lower = function_exists( 'mb_strtolower' ) ? mb_strtolower( $label, 'UTF-8' ) : strtolower( $label );

		// Canonical crisp labels for standard customizable T-Shirt views so 2-3 pills fit side-by-side on mobile
		if ( $is_custom ) {
			$is_closeup = in_array( $view_role, array( 'closeup', 'detail' ), true )
				|| false !== strpos( $lower, 'zoom' )
				|| false !== strpos( $lower, 'gros plan' );
			if ( $is_closeup ) {
				if ( false !== strpos( $lower, 'cœur' ) || false !== strpos( $lower, 'coeur' ) || false !== strpos( $lower, 'poitrine' ) ) {
					return __( 'Zoom Cœur', 'mr-tee-editor' );
				}
				if ( 'back' === $side_id || false !== strpos( $lower, 'verso' ) || false !== strpos( $lower, 'dos' ) ) {
					return __( 'Zoom Dos', 'mr-tee-editor' );
				}
				return __( 'Zoom', 'mr-tee-editor' );
			}
			if ( 'back' === $side_id ) {
				return __( 'Verso', 'mr-tee-editor' );
			}
			if ( 'front' === $side_id && 'full' === $view_role ) {
				return $has_multi_side ? __( 'Recto', 'mr-tee-editor' ) : __( 'Vue Complète', 'mr-tee-editor' );
			}
		}

		// Fallback cleanup for custom sides (sleeves, lifestyle photos, etc.)
		$label = (string) preg_replace( '/^\s*#?\d+\s*[\.\-\)\:]\s*/u', '', $label );
		$stripped_paren = trim( (string) preg_replace( '/\s*\([^)]*\)\s*/u', ' ', $label ) );
		if ( '' !== $stripped_paren ) {
			$label = $stripped_paren;
		}
		$label = trim( (string) preg_replace( '/\s{2,}/u', ' ', $label ) );
		if ( '' === $label ) {
			return ! $is_custom ? __( 'Photo', 'mr-tee-editor' ) : __( 'Vue', 'mr-tee-editor' );
		}
		return $label;
	}

	/**
	 * Render the dedicated Mr. Tee product gallery HTML markup.
	 *
	 * Keeps `woocommerce-product-gallery images` so every Classic, ThemeForest, and Block
	 * theme's 2-column CSS layout stays 100% intact, while deliberately omitting
	 * `woocommerce-product-gallery--with-images` so WooCommerce's Flexslider never hijacks it.
	 *
	 * @param int $product_id WooCommerce product ID.
	 * @return string Rendered HTML.
	 */
	public static function render_gallery_html( $product_id ) {
		$product_id = absint( $product_id );
		if ( $product_id <= 0 || ! ProductPageRenderer::is_mr_tee_product( $product_id ) ) {
			return '';
		}
		if ( ! empty( self::$rendered_galleries[ $product_id ] ) ) {
			return '';
		}
		self::$rendered_galleries[ $product_id ] = true;

		$config      = ProductPageRenderer::get_cached_config( $product_id );
		$slides      = self::get_gallery_slides( $product_id, $config );
		$slide_count = count( $slides );
		$is_locked   = ! empty( $config['lock_aspect_ratio'] ) || ( isset( $config['product_type'] ) && 'flat_object' === $config['product_type'] );
		$lock_ratio  = ( ! empty( $config['print_aspect_ratio'] ) && floatval( $config['print_aspect_ratio'] ) > 0 )
			? floatval( $config['print_aspect_ratio'] )
			: 1.0;

		$sides_by_id        = array();
		$distinct_slide_sds = array();
		$has_static_slides  = false;
		if ( ! empty( $config['print_sides'] ) && is_array( $config['print_sides'] ) ) {
			foreach ( $config['print_sides'] as $ps ) {
				if ( is_array( $ps ) && ! empty( $ps['id'] ) ) {
					$sides_by_id[ (string) $ps['id'] ] = $ps;
				}
			}
		}
		foreach ( $slides as $sl_check ) {
			if ( empty( $sl_check['is_customizable'] ) ) {
				$has_static_slides = true;
			} elseif ( ! empty( $sl_check['side_id'] ) ) {
				$distinct_slide_sds[ (string) $sl_check['side_id'] ] = true;
			}
		}
		$has_multi_side = count( $distinct_slide_sds ) > 1;
		// Only render the bottom static thumbnail strip when there are non-customizable lifestyle photos or >4 slides;
		// for 2-4 customizable views, the top segmented view bar + swipe + arrows keep the shirt large and clean without blank-shirt confusion.
		$show_thumb_strip = $slide_count > 4 || ( $slide_count > 1 && $has_static_slides );

		$slide_locks    = array();
		$any_slide_lock = false;
		foreach ( $slides as $idx => $slide ) {
			$s_locked = false;
			$s_ratio  = $lock_ratio;
			if ( ! empty( $slide['is_customizable'] ) ) {
				$s_def    = ( ! empty( $slide['side_id'] ) && isset( $sides_by_id[ (string) $slide['side_id'] ] ) )
					? $sides_by_id[ (string) $slide['side_id'] ]
					: null;
				$s_locked = ( null !== $s_def && array_key_exists( 'lock_aspect_ratio', $s_def ) )
					? ( ! empty( $s_def['lock_aspect_ratio'] ) || ( isset( $s_def['product_type'] ) && 'flat_object' === $s_def['product_type'] ) )
					: $is_locked;
				$s_w      = ( null !== $s_def && ! empty( $s_def['print_real_width'] ) ) ? floatval( $s_def['print_real_width'] ) : 0.0;
				$s_h      = ( null !== $s_def && ! empty( $s_def['print_real_height'] ) ) ? floatval( $s_def['print_real_height'] ) : 0.0;
				if ( $s_w > 0 && $s_h > 0 ) {
					$s_ratio = round( $s_w / $s_h, 6 );
				}
			}
			if ( $s_locked ) {
				$any_slide_lock = true;
			}
			$slide_locks[ $idx ] = array(
				'locked' => $s_locked,
				'ratio'  => $s_ratio,
			);
		}

		$wp_post_image_idx = 0;
		foreach ( $slides as $idx => $slide ) {
			if ( ! empty( $slide['is_customizable'] ) && 'front' === $slide['side_id'] && 'full' === $slide['view_role'] ) {
				$wp_post_image_idx = $idx;
				break;
			}
		}

		$svg_icon_shirt = '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M20.38 3.46L16 2a4 4 0 0 1-8 0L3.62 3.46a2 2 0 0 0-1.34 2.23l.58 3.47a1 1 0 0 0 .99.84H6v10c0 1.1.9 2 2 2h8a2 2 0 0 0 2-2V10h2.15a1 1 0 0 0 .99-.84l.58-3.47a2 2 0 0 0-1.34-2.23z"/></svg>';
		$svg_icon_zoom  = '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="11" cy="11" r="7"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>';
		$svg_icon_photo = '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect x="3" y="3" width="18" height="18" rx="2" ry="2"/><circle cx="8.5" cy="8.5" r="1.5"/><polyline points="21 15 16 10 5 21"/></svg>';
		$svg_icon_flip  = '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><polyline points="17 1 21 5 17 9"/><path d="M3 11V9a4 4 0 0 1 4-4h14"/><polyline points="7 23 3 19 7 15"/><path d="M21 13v2a4 4 0 0 1-4 4H3"/></svg>';

		ob_start();
		?>
		<div
			id="mr-tee-gallery-root"
			class="woocommerce-product-gallery images wp-block-woocommerce-product-image-gallery mr-tee-dedicated-gallery<?php echo $any_slide_lock ? ' mr-tee-gallery--aspect-locked' : ''; ?>"
			data-product-id="<?php echo esc_attr( $product_id ); ?>"
			data-columns="4"
			data-slide-count="<?php echo esc_attr( $slide_count ); ?>"
		>
			<div class="mr-tee-gallery-viewport">
				<div class="mr-tee-gallery-track">
					<?php foreach ( $slides as $idx => $slide ) : ?>
						<?php
						$s_lock_info = isset( $slide_locks[ $idx ] ) ? $slide_locks[ $idx ] : array( 'locked' => false, 'ratio' => $lock_ratio );
						$s_is_locked = ! empty( $s_lock_info['locked'] );
						$s_ratio_str = (string) $s_lock_info['ratio'];
						?>
						<div
							class="mr-tee-slide<?php echo 0 === $idx ? ' is-active' : ''; ?>"
							data-slide-index="<?php echo esc_attr( $idx ); ?>"
							data-view-id="<?php echo esc_attr( $slide['view_id'] ); ?>"
							data-side-id="<?php echo esc_attr( $slide['side_id'] ); ?>"
							data-view-role="<?php echo esc_attr( $slide['view_role'] ); ?>"
							data-customizable="<?php echo ! empty( $slide['is_customizable'] ) ? '1' : '0'; ?>"
						>
							<div
								class="mr-tee-slide-stage<?php echo $s_is_locked ? ' mr-tee-slide-stage--locked' : ''; ?>"
								<?php if ( $s_is_locked ) : ?>
									style="--mr-tee-slide-ratio: <?php echo esc_attr( $s_ratio_str ); ?>; aspect-ratio: <?php echo esc_attr( $s_ratio_str ); ?>;"
								<?php endif; ?>
							>
								<img
									src="<?php echo esc_url( $slide['image_url'] ); ?>"
									<?php if ( ! empty( $slide['srcset'] ) ) : ?>
										srcset="<?php echo esc_attr( $slide['srcset'] ); ?>"
									<?php endif; ?>
									<?php if ( ! empty( $slide['sizes'] ) ) : ?>
										sizes="<?php echo esc_attr( $slide['sizes'] ); ?>"
									<?php endif; ?>
									alt="<?php echo esc_attr( $slide['alt'] ); ?>"
									class="mr-tee-slide-img<?php echo $wp_post_image_idx === $idx ? ' wp-post-image' : ''; ?> skip-lazy no-lazyload"
									data-no-lazy="1"
									data-skip-lazy="1"
									data-large_image="<?php echo esc_url( $slide['image_url'] ); ?>"
									<?php if ( ! empty( $slide['width'] ) && ! empty( $slide['height'] ) ) : ?>
										width="<?php echo esc_attr( (string) $slide['width'] ); ?>"
										height="<?php echo esc_attr( (string) $slide['height'] ); ?>"
									<?php endif; ?>
									<?php if ( 0 === $idx ) : ?>
										loading="eager"
										fetchpriority="high"
										decoding="sync"
									<?php else : ?>
										loading="lazy"
										decoding="async"
									<?php endif; ?>
									draggable="false"
								/>
								<?php if ( ! empty( $slide['is_customizable'] ) ) : ?>
									<div
										class="mr-tee-live-stage-overlay"
										data-view-id="<?php echo esc_attr( $slide['view_id'] ); ?>"
										data-side-id="<?php echo esc_attr( $slide['side_id'] ); ?>"
										data-blend="<?php echo esc_attr( $slide['blend_mode'] ); ?>"
									>
										<div class="mr-tee-live-svg-container"></div>
									</div>
								<?php endif; ?>
							</div>
						</div>
					<?php endforeach; ?>
				</div>

				<?php if ( $slide_count > 1 ) : ?>
					<button
						type="button"
						class="mr-tee-gallery-arrow mr-tee-gallery-arrow--prev"
						aria-label="<?php esc_attr_e( 'Vue précédente', 'mr-tee-editor' ); ?>"
					>
						<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.3" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><polyline points="15 18 9 12 15 6"/></svg>
					</button>
					<button
						type="button"
						class="mr-tee-gallery-arrow mr-tee-gallery-arrow--next"
						aria-label="<?php esc_attr_e( 'Vue suivante', 'mr-tee-editor' ); ?>"
					>
						<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.3" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><polyline points="9 18 15 12 9 6"/></svg>
					</button>
				<?php endif; ?>

				<button
					type="button"
					class="mr-tee-gallery-fullscreen-btn"
					aria-label="<?php esc_attr_e( 'Agrandir l\'aperçu', 'mr-tee-editor' ); ?>"
					title="<?php esc_attr_e( 'Agrandir l\'aperçu', 'mr-tee-editor' ); ?>"
				>
					<svg width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.1" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
						<polyline points="15 3 21 3 21 9"/>
						<polyline points="9 21 3 21 3 15"/>
						<line x1="21" y1="3" x2="14" y2="10"/>
						<line x1="3" y1="21" x2="10" y2="14"/>
					</svg>
				</button>
			</div>

			<?php if ( $slide_count > 1 ) : ?>
				<div class="mr-tee-view-pills" role="tablist" aria-label="<?php esc_attr_e( 'Vues du produit', 'mr-tee-editor' ); ?>">
					<?php foreach ( $slides as $idx => $slide ) : ?>
						<?php
						$pill_icon = $svg_icon_shirt;
						if ( empty( $slide['is_customizable'] ) ) {
							$pill_icon = $svg_icon_photo;
						} elseif ( in_array( $slide['view_role'], array( 'closeup', 'detail' ), true ) ) {
							$pill_icon = $svg_icon_zoom;
						} elseif ( 'back' === $slide['side_id'] ) {
							$pill_icon = $svg_icon_flip;
						}
						$short_label = self::format_compact_view_label(
							$slide['label'],
							$slide['side_id'],
							$slide['view_role'],
							! empty( $slide['is_customizable'] ),
							$has_multi_side
						);
						?>
						<button
							type="button"
							role="tab"
							class="mr-tee-view-pill<?php echo 0 === $idx ? ' is-active' : ''; ?>"
							aria-selected="<?php echo 0 === $idx ? 'true' : 'false'; ?>"
							tabindex="<?php echo 0 === $idx ? '0' : '-1'; ?>"
							data-slide-index="<?php echo esc_attr( $idx ); ?>"
							data-view-id="<?php echo esc_attr( $slide['view_id'] ); ?>"
							data-side-id="<?php echo esc_attr( $slide['side_id'] ); ?>"
							data-view-role="<?php echo esc_attr( $slide['view_role'] ); ?>"
							title="<?php echo esc_attr( $slide['label'] ); ?>"
						>
							<span class="mr-tee-view-pill-icon" aria-hidden="true"><?php echo $pill_icon; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></span>
							<span class="mr-tee-view-pill-label"><?php echo esc_html( $short_label ); ?></span>
						</button>
					<?php endforeach; ?>
				</div>
			<?php endif; ?>

			<?php if ( $show_thumb_strip ) : ?>
				<div class="mr-tee-gallery-thumbs" role="group" aria-label="<?php esc_attr_e( 'Miniatures des vues', 'mr-tee-editor' ); ?>">
					<?php foreach ( $slides as $idx => $slide ) : ?>
						<button
							type="button"
							class="mr-tee-gallery-thumb<?php echo 0 === $idx ? ' is-active' : ''; ?>"
							<?php echo 0 === $idx ? 'aria-current="true"' : ''; ?>
							data-slide-index="<?php echo esc_attr( $idx ); ?>"
							data-view-id="<?php echo esc_attr( $slide['view_id'] ); ?>"
							data-side-id="<?php echo esc_attr( $slide['side_id'] ); ?>"
							aria-label="<?php echo esc_attr( $slide['label'] ); ?>"
						>
							<img
								src="<?php echo esc_url( $slide['thumb_url'] ); ?>"
								alt="<?php echo esc_attr( $slide['label'] ); ?>"
								class="skip-lazy no-lazyload"
								data-no-lazy="1"
								data-skip-lazy="1"
								loading="lazy"
								decoding="async"
								draggable="false"
							/>
							<span class="mr-tee-gallery-thumb-label"><?php echo esc_html( $slide['label'] ); ?></span>
						</button>
					<?php endforeach; ?>
				</div>
			<?php endif; ?>
		</div>
		<?php
		return (string) ob_get_clean();
	}
}
