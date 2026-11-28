<?php
/**
 * Mr. Tee Editor - WooCommerce Cart & Checkout Integration
 *
 * Captures personalization payloads upon Add-to-Cart, displays item data in cart/checkout,
 * and transfers high-resolution print files to order line items.
 *
 * @package MrTeeEditor\Commerce
 */

namespace MrTeeEditor\Commerce;

use MrTeeEditor\Domain\SceneGraphSchema;
use MrTeeEditor\Export\PrintAssetGenerator;
use MrTeeEditor\Export\NBDesignerBridge;
use MrTeeEditor\Registry\IconSetRegistry;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class CartIntegration {

	/**
	 * Track whether NBDesigner options are temporarily suppressed for the current order item.
	 *
	 * @var bool
	 */
	private static $suppress_nbd_for_item = false;

	/**
	 * Per-request cache of product ID => bool indicating Mr. Tee Editor mode.
	 *
	 * @var array<int, bool>
	 */
	private static $mr_tee_product_cache = array();

	/**
	 * In-memory per-request map keyed by spl_object_id( $product ) holding personalized
	 * cart preview data so customer previews are never written onto WC_Product meta / wp_postmeta.
	 *
	 * @var array<int, array{url: string, previews: array<int, mixed>}>
	 */
	private static $runtime_cart_previews = array();

	/**
	 * Register hooks.
	 */
	public static function init() {
		add_filter( 'woocommerce_add_cart_item_data', array( __CLASS__, 'capture_cart_item_data' ), 10, 3 );
		add_filter( 'woocommerce_add_to_cart_redirect', array( __CLASS__, 'filter_add_to_cart_redirect' ), 99999, 2 );
		add_filter( 'woocommerce_get_item_data', array( __CLASS__, 'display_cart_item_data' ), 10, 2 );
		add_action( 'woocommerce_order_item_meta_start', array( __CLASS__, 'suppress_nbd_order_item_meta_start' ), 1, 4 );
		add_action( 'woocommerce_order_item_meta_start', array( __CLASS__, 'render_order_item_preview_attachment' ), 10, 4 );
		add_action( 'woocommerce_order_item_meta_end', array( __CLASS__, 'restore_nbd_order_item_meta_end' ), 999, 4 );
		add_action( 'woocommerce_checkout_create_order_line_item', array( __CLASS__, 'save_order_line_item_data' ), 10, 4 );
		add_action( 'woocommerce_checkout_order_created', array( __CLASS__, 'sync_nbd_order_meta' ), 20, 1 );
		add_action( 'woocommerce_store_api_checkout_order_processed', array( __CLASS__, 'sync_nbd_order_meta' ), 20, 1 );
		add_filter( 'woocommerce_add_cart_item', array( __CLASS__, 'attach_preview_to_cart_product' ), 20, 1 );
		add_filter( 'woocommerce_get_cart_item_from_session', array( __CLASS__, 'filter_cart_item_from_session' ), 20, 2 );
		add_filter( 'woocommerce_product_get_image', array( __CLASS__, 'filter_product_get_image_in_cart' ), 999, 5 );
		add_filter( 'woocommerce_cart_item_thumbnail', array( __CLASS__, 'filter_cart_item_thumbnail' ), 999, 3 );
		add_filter( 'woocommerce_store_api_cart_item_images', array( __CLASS__, 'filter_store_api_cart_item_images' ), 999, 3 );
		add_filter( 'woocommerce_cart_item_name', array( __CLASS__, 'filter_checkout_item_name' ), 25, 3 );
		add_filter( 'woocommerce_product_supports', array( __CLASS__, 'filter_product_supports' ), 20, 3 );
		add_filter( 'woocommerce_product_add_to_cart_url', array( __CLASS__, 'filter_product_add_to_cart_url' ), 20, 2 );
		add_filter( 'woocommerce_product_add_to_cart_text', array( __CLASS__, 'filter_product_add_to_cart_text' ), 20, 2 );
		add_filter( 'woocommerce_loop_add_to_cart_link', array( __CLASS__, 'filter_loop_add_to_cart_link' ), 20, 3 );
		add_filter( 'woocommerce_order_again_cart_item_data', array( __CLASS__, 'filter_order_again_cart_item_data' ), 20, 3 );
		add_filter( 'nbd_show_design_section_in_cart', array( __CLASS__, 'filter_nbd_show_design_in_cart' ), 999, 2 );
		add_filter( 'nbd_show_edit_design_link_in_cart', array( __CLASS__, 'filter_nbd_show_edit_in_cart' ), 999, 2 );
		add_action( 'wp_footer', array( __CLASS__, 'render_cart_preview_lightbox' ), 99 );
	}

	/**
	 * Helper callback returning 'no' for filtered NBDesigner options.
	 *
	 * @return string
	 */
	public static function return_no_option() {
		return 'no';
	}

	/**
	 * Suppress NBDesigner cart design block for Mr. Tee Editor items so customers see only one preview thumbnail.
	 *
	 * @param string               $show      Existing 'yes'|'no' flag.
	 * @param array<string, mixed> $cart_item Cart item array.
	 * @return string
	 */
	public static function filter_nbd_show_design_in_cart( $show, $cart_item = array() ) {
		if ( ! empty( $cart_item['mr_tee_editor'] ) || ( ! empty( $cart_item['product_id'] ) && self::is_mr_tee_product( $cart_item['product_id'] ) ) ) {
			return 'no';
		}
		return $show;
	}

	/**
	 * Suppress NBDesigner cart edit link for Mr. Tee Editor items.
	 *
	 * @param bool                 $show      Existing bool flag.
	 * @param array<string, mixed> $cart_item Cart item array.
	 * @return bool
	 */
	public static function filter_nbd_show_edit_in_cart( $show, $cart_item = array() ) {
		if ( ! empty( $cart_item['mr_tee_editor'] ) || ( ! empty( $cart_item['product_id'] ) && self::is_mr_tee_product( $cart_item['product_id'] ) ) ) {
			return false;
		}
		return $show;
	}

	/**
	 * Temporarily suppress NBDesigner customer order/email design & download output for Mr. Tee Editor items.
	 *
	 * @param int                    $item_id    Order item ID.
	 * @param \WC_Order_Item_Product $item       Order item object.
	 * @param \WC_Order              $order      Order object.
	 * @param bool                   $plain_text Plain text email flag.
	 */
	public static function suppress_nbd_order_item_meta_start( $item_id, $item, $order, $plain_text = false ) {
		if ( ! is_object( $item ) || ! method_exists( $item, 'get_meta' ) ) {
			return;
		}
		$data = $item->get_meta( '_mr_tee_editor_data', true );
		if ( ! empty( $data ) ) {
			self::$suppress_nbd_for_item = true;
			add_filter( 'pre_option_nbdesigner_show_in_order', array( __CLASS__, 'return_no_option' ), 999 );
			add_filter( 'pre_option_allow_customer_redesign_after_order', array( __CLASS__, 'return_no_option' ), 999 );
			add_filter( 'pre_option_allow_customer_download_after_complete_order', array( __CLASS__, 'return_no_option' ), 999 );
		}
	}

	/**
	 * Restore NBDesigner options after each order item finishes rendering.
	 *
	 * @param int                    $item_id    Order item ID.
	 * @param \WC_Order_Item_Product $item       Order item object.
	 * @param \WC_Order              $order      Order object.
	 * @param bool                   $plain_text Plain text email flag.
	 */
	public static function restore_nbd_order_item_meta_end( $item_id, $item, $order, $plain_text = false ) {
		if ( self::$suppress_nbd_for_item ) {
			self::$suppress_nbd_for_item = false;
			remove_filter( 'pre_option_nbdesigner_show_in_order', array( __CLASS__, 'return_no_option' ), 999 );
			remove_filter( 'pre_option_allow_customer_redesign_after_order', array( __CLASS__, 'return_no_option' ), 999 );
			remove_filter( 'pre_option_allow_customer_download_after_complete_order', array( __CLASS__, 'return_no_option' ), 999 );
		}
	}

	/**
	 * Check whether a product ID is configured to use Mr. Tee Editor across all supported meta keys.
	 *
	 * @param int $product_id Product ID.
	 * @return bool
	 */
	private static function is_mr_tee_product( $product_id ) {
		$pid = (int) $product_id;
		if ( $pid <= 0 ) {
			return false;
		}
		if ( array_key_exists( $pid, self::$mr_tee_product_cache ) ) {
			return self::$mr_tee_product_cache[ $pid ];
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
		self::$mr_tee_product_cache[ $pid ] = ( 'mr_tee_editor' === $engine );
		return self::$mr_tee_product_cache[ $pid ];
	}

	/**
	 * Resolve canonical parent product ID from a WC_Product instance.
	 *
	 * @param \WC_Product $product Product instance.
	 * @return int
	 */
	private static function resolve_parent_product_id( $product ) {
		if ( ! is_object( $product ) || ! method_exists( $product, 'get_id' ) ) {
			return 0;
		}
		$pid = (int) $product->get_id();
		if ( method_exists( $product, 'is_type' ) && $product->is_type( 'variation' ) && method_exists( $product, 'get_parent_id' ) ) {
			$parent_id = (int) $product->get_parent_id();
			if ( $parent_id > 0 ) {
				return $parent_id;
			}
		}
		return $pid;
	}

	/**
	 * Disable AJAX add-to-cart on Shop/Archive grids for Mr. Tee Editor products
	 * so customers cannot add blank uncustomized products from product loops.
	 *
	 * @param bool        $supports Existing feature support flag.
	 * @param string      $feature  Feature name.
	 * @param \WC_Product $product  Product object.
	 * @return bool
	 */
	public static function filter_product_supports( $supports, $feature, $product ) {
		if ( 'ajax_add_to_cart' === $feature ) {
			$pid = self::resolve_parent_product_id( $product );
			if ( $pid > 0 && self::is_mr_tee_product( $pid ) ) {
				return false;
			}
		}
		return $supports;
	}

	/**
	 * Route archive/Block-theme loop add-to-cart URLs to the single product customizer page.
	 *
	 * @param string      $url     Default add-to-cart URL.
	 * @param \WC_Product $product Product object.
	 * @return string
	 */
	public static function filter_product_add_to_cart_url( $url, $product ) {
		$pid = self::resolve_parent_product_id( $product );
		if ( $pid <= 0 || ! self::is_mr_tee_product( $pid ) ) {
			return $url;
		}
		$permalink = ( is_object( $product ) && method_exists( $product, 'get_permalink' ) )
			? $product->get_permalink()
			: get_permalink( $pid );
		return ! empty( $permalink ) ? $permalink : $url;
	}

	/**
	 * Replace archive/Block-theme loop add-to-cart button label with "Personnaliser".
	 *
	 * @param string      $text    Default loop button text.
	 * @param \WC_Product $product Product object.
	 * @return string
	 */
	public static function filter_product_add_to_cart_text( $text, $product ) {
		$pid = self::resolve_parent_product_id( $product );
		if ( $pid > 0 && self::is_mr_tee_product( $pid ) ) {
			return __( 'Personnaliser', 'mr-tee-editor' );
		}
		return $text;
	}

	/**
	 * Replace Shop/Category archive add-to-cart button with a clean "Personnaliser" link
	 * pointing to the product page for Mr. Tee Editor products.
	 *
	 * @param string               $html    Existing loop button HTML.
	 * @param \WC_Product          $product Product object.
	 * @param array<string, mixed> $args    Optional button arguments.
	 * @return string
	 */
	public static function filter_loop_add_to_cart_link( $html, $product, $args = array() ) {
		$pid = self::resolve_parent_product_id( $product );
		if ( $pid <= 0 || ! self::is_mr_tee_product( $pid ) ) {
			return $html;
		}

		$permalink = ( is_object( $product ) && method_exists( $product, 'get_permalink' ) )
			? $product->get_permalink()
			: get_permalink( $pid );
		if ( empty( $permalink ) ) {
			return $html;
		}

		$raw_class = ( is_array( $args ) && ! empty( $args['class'] ) ) ? (string) $args['class'] : 'button';
		$classes   = preg_split( '/\s+/', $raw_class );
		$classes   = array_filter(
			is_array( $classes ) ? $classes : array( 'button' ),
			static function ( $cls ) {
				return '' !== $cls && ! in_array( $cls, array( 'ajax_add_to_cart', 'add_to_cart_button' ), true );
			}
		);
		if ( ! in_array( 'button', $classes, true ) ) {
			array_unshift( $classes, 'button' );
		}
		$classes[] = 'mr-tee-customize-loop-btn';

		$aria_label = ( is_object( $product ) && method_exists( $product, 'get_name' ) )
			? sprintf( __( 'Personnaliser « %s »', 'mr-tee-editor' ), $product->get_name() )
			: __( 'Personnaliser', 'mr-tee-editor' );

		return sprintf(
			'<a href="%s" class="%s" aria-label="%s">%s</a>',
			esc_url( $permalink ),
			esc_attr( implode( ' ', array_unique( $classes ) ) ),
			esc_attr( $aria_label ),
			esc_html__( 'Personnaliser', 'mr-tee-editor' )
		);
	}

	/**
	 * Preserve Mr. Tee customization data when a customer clicks "Order Again" ("Commander à nouveau")
	 * from My Account -> Orders, isolating the reordered item into a fresh design directory.
	 *
	 * @param array<string, mixed>   $cart_item_data Cart item data being built.
	 * @param \WC_Order_Item_Product $item           Original order line item.
	 * @param \WC_Order              $order          Original order object.
	 * @return array<string, mixed>
	 */
	public static function filter_order_again_cart_item_data( $cart_item_data, $item, $order ) {
		if ( ! is_object( $item ) || ! method_exists( $item, 'get_meta' ) ) {
			return $cart_item_data;
		}
		$orig_data = $item->get_meta( '_mr_tee_editor_data', true );
		if ( empty( $orig_data ) || ! is_array( $orig_data ) ) {
			return $cart_item_data;
		}

		$cloned  = $orig_data;
		$new_key = 'mrtee_' . wp_generate_uuid4();
		$paths   = PrintAssetGenerator::get_upload_dir( 'designs/' . $new_key );

		$cloned['key']        = $new_key;
		$cloned['svg_url']    = '';
		$cloned['svg_file']   = '';
		$cloned['print_url']  = '';
		$cloned['print_file'] = '';
		unset( $cloned['side_exports'], $cloned['nbd'] );

		// Clone preview files on disk into the new design directory so each order stays isolated.
		$copied_primary_file = '';
		$copied_primary_url  = '';
		if ( ! empty( $orig_data['preview_file'] ) && is_string( $orig_data['preview_file'] ) && file_exists( $orig_data['preview_file'] ) && filesize( $orig_data['preview_file'] ) > 0 ) {
			$base = sanitize_file_name( basename( $orig_data['preview_file'] ) );
			if ( '' !== $base ) {
				$dest = trailingslashit( $paths['dir'] ) . $base;
				if ( @copy( $orig_data['preview_file'], $dest ) ) {
					$copied_primary_file    = $dest;
					$copied_primary_url     = trailingslashit( $paths['url'] ) . $base;
					$cloned['preview_file'] = $copied_primary_file;
					$cloned['preview_url']  = $copied_primary_url;
				}
			}
		}

		if ( ! empty( $orig_data['previews'] ) && is_array( $orig_data['previews'] ) ) {
			$cloned_previews = array();
			foreach ( $orig_data['previews'] as $p_idx => $pv ) {
				if ( ! is_array( $pv ) ) {
					continue;
				}
				$pv_copy = $pv;
				if ( ! empty( $pv['file'] ) && is_string( $pv['file'] ) && file_exists( $pv['file'] ) && filesize( $pv['file'] ) > 0 ) {
					$base = sanitize_file_name( basename( $pv['file'] ) );
					if ( '' !== $base ) {
						$dest = trailingslashit( $paths['dir'] ) . $base;
						if ( ! file_exists( $dest ) ) {
							@copy( $pv['file'], $dest );
						}
						if ( file_exists( $dest ) && filesize( $dest ) > 0 ) {
							$pv_copy['file'] = $dest;
							$pv_copy['url']  = trailingslashit( $paths['url'] ) . $base;
						}
					}
				} elseif ( 0 === $p_idx && '' !== $copied_primary_url ) {
					$pv_copy['file'] = $copied_primary_file;
					$pv_copy['url']  = $copied_primary_url;
				}
				$cloned_previews[] = $pv_copy;
			}
			$cloned['previews'] = $cloned_previews;
		}

		$product_id = method_exists( $item, 'get_product_id' ) ? (int) $item->get_product_id() : 0;
		if ( $product_id > 0 && NBDesignerBridge::is_nbd_active() ) {
			$cfg              = SceneGraphSchema::get_product_config( $product_id, array( 'compile_scene_graph' => false ) );
			$should_handshake = isset( $cfg['nbd_handshake'] ) ? ! empty( $cfg['nbd_handshake'] ) : true;
			if ( $should_handshake && ! empty( $cloned['payload'] ) && is_array( $cloned['payload'] ) ) {
				$cloned['nbd'] = NBDesignerBridge::inject_nbd_artifacts(
					$new_key,
					$cloned['payload'],
					'',
					'',
					'',
					$product_id,
					array(
						'preview_file' => ! empty( $cloned['preview_file'] ) ? $cloned['preview_file'] : '',
						'previews'     => ! empty( $cloned['previews'] ) ? $cloned['previews'] : array(),
					)
				);
			}
		}

		$cart_item_data['mr_tee_editor'] = $cloned;
		$cart_item_data['unique_key']    = $new_key;

		return $cart_item_data;
	}

	/**
	 * Strictly validate that a URL points to this WordPress site (comparing against
	 * `wp_parse_url( home_url(), PHP_URL_HOST )` — never trusting `$_SERVER['HTTP_HOST']`).
	 *
	 * @param string $raw_url Raw URL or root-relative path.
	 * @return string Sanitized same-site URL, or empty string if invalid/off-site.
	 */
	private static function sanitize_same_site_url( $raw_url ) {
		if ( ! is_string( $raw_url ) ) {
			return '';
		}
		$url = trim( $raw_url );
		if ( '' === $url ) {
			return '';
		}

		// Allow clean root-relative same-site paths (reject protocol-relative `//` and traversal).
		if ( 0 === strpos( $url, '/' ) && 0 !== strpos( $url, '//' ) ) {
			if ( false !== strpos( $url, '..' ) || preg_match( '/[\r\n\0]/', $url ) ) {
				return '';
			}
			$resolved = esc_url_raw( rtrim( home_url(), '/' ) . $url );
			if ( '' === $resolved ) {
				return '';
			}
			$url = $resolved;
		}

		if ( ! preg_match( '#^https?://#i', $url ) ) {
			return '';
		}

		$site_host = strtolower( (string) wp_parse_url( home_url(), PHP_URL_HOST ) );
		$url_host  = strtolower( (string) wp_parse_url( $url, PHP_URL_HOST ) );
		if ( '' === $site_host || $url_host !== $site_host ) {
			return '';
		}

		return esc_url_raw( $url );
	}

	/**
	 * Normalize same-site asset URLs to match the active HTTP/HTTPS scheme (or `home_url()` scheme
	 * when rendering asynchronous emails where `is_ssl()` may be false).
	 *
	 * @param string $url             Raw URL.
	 * @param bool   $use_home_scheme When true (e.g. emails), preserve `home_url()` scheme instead of `is_ssl()`.
	 * @return string
	 */
	private static function normalize_url( $url, $use_home_scheme = false ) {
		if ( empty( $url ) || ! is_string( $url ) ) {
			return '';
		}
		$url = trim( $url );
		if ( 0 === strpos( $url, '/' ) && 0 !== strpos( $url, '//' ) ) {
			if ( $use_home_scheme ) {
				return rtrim( home_url(), '/' ) . $url;
			}
			return $url;
		}
		if ( 0 !== strpos( $url, 'http://' ) && 0 !== strpos( $url, 'https://' ) ) {
			return $url;
		}

		$site_host = strtolower( (string) wp_parse_url( home_url(), PHP_URL_HOST ) );
		$url_host  = strtolower( (string) wp_parse_url( $url, PHP_URL_HOST ) );

		if ( '' !== $site_host && $url_host === $site_host ) {
			if ( $use_home_scheme ) {
				$home_scheme   = wp_parse_url( home_url(), PHP_URL_SCHEME );
				$target_scheme = ( 'https' === $home_scheme || 'http' === $home_scheme ) ? $home_scheme : ( is_ssl() ? 'https' : 'http' );
				return set_url_scheme( $url, $target_scheme );
			}
			return set_url_scheme( $url, is_ssl() ? 'https' : 'http' );
		}
		return $url;
	}

	/**
	 * Automatically redirect customers to the WooCommerce Cart page after adding
	 * a customized Mr. Tee Editor product to the cart.
	 *
	 * @param string           $url            Existing redirect URL.
	 * @param \WC_Product|null $adding_to_cart Product added to cart.
	 * @return string
	 */
	public static function filter_add_to_cart_redirect( $url, $adding_to_cart = null ) {
		$product_id = 0;
		if ( is_object( $adding_to_cart ) && method_exists( $adding_to_cart, 'get_id' ) ) {
			$product_id = self::resolve_parent_product_id( $adding_to_cart );
		} elseif ( ! empty( $_REQUEST['add-to-cart'] ) && is_numeric( $_REQUEST['add-to-cart'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$product_id = absint( wp_unslash( $_REQUEST['add-to-cart'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		}

		$is_mr_tee = ( $product_id > 0 && self::is_mr_tee_product( $product_id ) );

		if ( $is_mr_tee && function_exists( 'wc_get_cart_url' ) ) {
			$cart_url = wc_get_cart_url();
			if ( ! empty( $cart_url ) ) {
				return self::normalize_url( $cart_url );
			}
		}

		return $url;
	}

	/**
	 * Safely resolve and validate a preview entry belonging to the current design_key directory
	 * (or a strictly verified same-site URL matching `home_url()` host).
	 *
	 * @param string               $raw_url Raw posted URL.
	 * @param string               $view_id Optional view identifier.
	 * @param array<string,string> $paths   Design upload directory & URL pair.
	 * @return array{file: string, url: string}|false
	 */
	private static function resolve_safe_design_preview( $raw_url, $view_id, array $paths ) {
		$url_str  = is_string( $raw_url ) ? trim( $raw_url ) : '';
		$safe_vid = substr( preg_replace( '/[^a-zA-Z0-9_-]/', '', (string) $view_id ), 0, 32 );

		// 1. Prefer verifying an exact file inside designs/{design_key}/ referenced by URL
		if ( '' !== $url_str ) {
			$url_path = wp_parse_url( $url_str, PHP_URL_PATH );
			$base     = is_string( $url_path ) ? basename( $url_path ) : '';
			if ( '' !== $base && preg_match( '/^design_preview(?:_[a-zA-Z0-9_-]{1,32})?\.(?:jpg|jpeg|png|webp)$/i', $base ) ) {
				$cand_file = trailingslashit( $paths['dir'] ) . $base;
				if ( file_exists( $cand_file ) && filesize( $cand_file ) > 0 ) {
					return array(
						'file' => $cand_file,
						'url'  => trailingslashit( $paths['url'] ) . $base,
					);
				}
			}
		}

		// 2. Fallback: look up suffixed preview file by view_id inside designs/{design_key}/
		if ( '' !== $safe_vid ) {
			foreach ( array( 'design_preview_' . $safe_vid . '.jpg', 'design_preview_' . $safe_vid . '.png' ) as $cand ) {
				$cand_file = trailingslashit( $paths['dir'] ) . $cand;
				if ( file_exists( $cand_file ) && filesize( $cand_file ) > 0 ) {
					return array(
						'file' => $cand_file,
						'url'  => trailingslashit( $paths['url'] ) . $cand,
					);
				}
			}
		}

		// 3. Fallback: accept strictly validated same-site HTTP(S) URLs (home_url host only, never HTTP_HOST)
		if ( '' !== $url_str ) {
			$clean_url = self::sanitize_same_site_url( $url_str );
			if ( '' !== $clean_url ) {
				return array(
					'file' => '',
					'url'  => $clean_url,
				);
			}
		}

		return false;
	}

	/**
	 * Normalize a previews list into compact `[{ label, src }]` entries for the lightbox or emails.
	 *
	 * @param array<int, mixed> $previews        Raw previews array.
	 * @param string            $fallback_url    Optional primary fallback URL.
	 * @param bool              $use_home_scheme Whether to normalize using `home_url()` scheme (emails).
	 * @return array<int, array{label: string, src: string}>
	 */
	private static function format_previews_for_lightbox( array $previews, $fallback_url = '', $use_home_scheme = false ) {
		$out  = array();
		$seen = array();

		foreach ( $previews as $idx => $entry ) {
			if ( ! is_array( $entry ) ) {
				continue;
			}
			$raw_src = ! empty( $entry['url'] ) ? $entry['url'] : ( ! empty( $entry['src'] ) ? $entry['src'] : '' );
			$src     = self::normalize_url( $raw_src, $use_home_scheme );
			if ( '' === $src || isset( $seen[ $src ] ) ) {
				continue;
			}
			$seen[ $src ] = true;
			$label        = ! empty( $entry['label'] ) ? sanitize_text_field( (string) $entry['label'] ) : sprintf( __( 'Vue %d', 'mr-tee-editor' ), $idx + 1 );
			$out[]        = array(
				'label' => $label,
				'src'   => $src,
			);
		}

		if ( empty( $out ) && '' !== $fallback_url ) {
			$norm = self::normalize_url( $fallback_url, $use_home_scheme );
			if ( '' !== $norm ) {
				$out[] = array(
					'label' => __( 'Aperçu', 'mr-tee-editor' ),
					'src'   => $norm,
				);
			}
		}

		return $out;
	}

	/**
	 * Capture personalization payload and thumbnail preview when item is added to cart.
	 * High-resolution 300 DPI print PNG & vector SVG are generated strictly on WP Admin order screens.
	 *
	 * @param array<string, mixed> $cart_item_data Existing item data.
	 * @param int                  $product_id     Product ID.
	 * @param int                  $variation_id   Variation ID.
	 * @return array<string, mixed>
	 */
	public static function capture_cart_item_data( $cart_item_data, $product_id, $variation_id ) {
		if ( ! self::is_mr_tee_product( $product_id ) ) {
			return $cart_item_data;
		}

		if ( empty( $_POST['mr_tee_editor_payload'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing
			return $cart_item_data;
		}

		$raw_payload = wp_unslash( $_POST['mr_tee_editor_payload'] ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized,WordPress.Security.NonceVerification.Missing
		$payload     = json_decode( $raw_payload, true );

		if ( ! is_array( $payload ) ) {
			return $cart_item_data;
		}

		// Sanitize text_lines labels/values cleanly while preserving Worker 3 geometry & color fields and '0' values.
		if ( ! empty( $payload['text_lines'] ) && is_array( $payload['text_lines'] ) ) {
			$sanitized_lines = array();
			foreach ( $payload['text_lines'] as $line ) {
				if ( ! is_array( $line ) ) {
					continue;
				}
				$raw_ft            = isset( $line['fieldType'] ) ? (string) $line['fieldType'] : 'text';
				$is_ai_image       = ( 'ai_image' === $raw_ft );
				$is_image          = ( 'image' === $raw_ft || $is_ai_image );
				$line['fieldType'] = $is_ai_image ? 'ai_image' : ( $is_image ? 'image' : 'text' );
				$line['label']      = isset( $line['label'] ) ? sanitize_text_field( (string) $line['label'] ) : '';
				if ( isset( $line['sideId'] ) ) {
					$line['sideId'] = substr( preg_replace( '/[^a-zA-Z0-9_-]/', '', (string) $line['sideId'] ), 0, 32 );
				}
				if ( isset( $line['side_id'] ) ) {
					$line['side_id'] = substr( preg_replace( '/[^a-zA-Z0-9_-]/', '', (string) $line['side_id'] ), 0, 32 );
				}
				if ( isset( $line['fontId'] ) ) {
					$line['fontId'] = sanitize_text_field( (string) $line['fontId'] );
				}
				if ( isset( $line['colorHex'] ) ) {
					$line['colorHex'] = sanitize_text_field( (string) $line['colorHex'] );
				}
				if ( isset( $line['fileName'] ) ) {
					$line['fileName'] = sanitize_text_field( (string) $line['fileName'] );
				}

				if ( $is_image ) {
					$line['value'] = isset( $line['value'] ) ? sanitize_text_field( (string) $line['value'] ) : '';
					$raw_img_url   = isset( $line['imageUrl'] ) ? (string) $line['imageUrl'] : '';
					$safe_img_url  = self::sanitize_same_site_url( $raw_img_url );
					$line['imageUrl'] = $safe_img_url;
					if ( '' !== trim( $raw_img_url ) && '' === $safe_img_url ) {
						// Reject off-site or invalid image URLs cleanly.
						$line['value']  = '';
						$line['edited'] = false;
					}
				} else {
					$line['value'] = isset( $line['value'] ) ? sanitize_textarea_field( (string) $line['value'] ) : '';
					if ( isset( $line['imageUrl'] ) ) {
						unset( $line['imageUrl'] );
					}
				}

				$sanitized_lines[] = $line;
			}
			$payload['text_lines'] = $sanitized_lines;
		}

		// Sanitize repeater item names/icons while preserving '0' names.
		if ( ! empty( $payload['repeater_items'] ) && is_array( $payload['repeater_items'] ) ) {
			$sanitized_rep = array();
			foreach ( $payload['repeater_items'] as $m_item ) {
				if ( ! is_array( $m_item ) ) {
					continue;
				}
				$m_item['name'] = isset( $m_item['name'] ) ? sanitize_text_field( (string) $m_item['name'] ) : '';
				$m_item['icon'] = isset( $m_item['icon'] ) ? sanitize_key( (string) $m_item['icon'] ) : 'mushroom_red';
				$sanitized_rep[] = $m_item;
			}
			$payload['repeater_items'] = $sanitized_rep;
		}

		$posted_key  = isset( $_POST['mr_tee_design_key'] ) ? preg_replace( '/[^a-zA-Z0-9_-]/', '', wp_unslash( $_POST['mr_tee_design_key'] ) ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized,WordPress.Security.NonceVerification.Missing
		$design_key  = preg_match( '/^mrtee_[a-zA-Z0-9_-]{8,64}$/', $posted_key ) ? $posted_key : ( 'mrtee_' . wp_generate_uuid4() );
		$preview_png = isset( $_POST['mr_tee_preview_png'] ) ? wp_unslash( $_POST['mr_tee_preview_png'] ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized,WordPress.Security.NonceVerification.Missing

		// Save inline thumbnail preview to disk, or reuse pre-uploaded chunked preview stored under designs/{design_key}
		$sub_dir = 'designs/' . $design_key;
		$paths   = PrintAssetGenerator::get_upload_dir( $sub_dir );

		$preview_res = false;
		if ( ! empty( $preview_png ) ) {
			$is_jpeg     = ( strpos( $preview_png, 'image/jpeg' ) !== false || strpos( $preview_png, 'image/jpg' ) !== false );
			$preview_ext = $is_jpeg ? 'design_preview.jpg' : 'design_preview.png';
			$preview_res = PrintAssetGenerator::save_base64_png( $preview_png, $sub_dir, $preview_ext );
		} else {
			foreach ( array( 'design_preview.jpg', 'design_preview.png' ) as $cand ) {
				$cand_file = trailingslashit( $paths['dir'] ) . $cand;
				if ( file_exists( $cand_file ) && filesize( $cand_file ) > 0 ) {
					$preview_res = array(
						'file' => $cand_file,
						'url'  => trailingslashit( $paths['url'] ) . $cand,
					);
					break;
				}
			}
		}

		$config = SceneGraphSchema::get_product_config( $product_id, array( 'compile_scene_graph' => false ) );

		// Parse optional multi-view mr_tee_previews_json while guaranteeing primary preview is item 0
		$raw_previews_json = isset( $_POST['mr_tee_previews_json'] ) ? wp_unslash( $_POST['mr_tee_previews_json'] ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized,WordPress.Security.NonceVerification.Missing
		$decoded_previews  = ! empty( $raw_previews_json ) ? json_decode( $raw_previews_json, true ) : null;
		$previews          = array();
		$seen_preview_urls = array();

		$primary_view_cfg = null;
		if ( ! empty( $config['preview_views'] ) && is_array( $config['preview_views'] ) ) {
			foreach ( $config['preview_views'] as $pv ) {
				if ( is_array( $pv ) && ( ! isset( $pv['is_cart_preview'] ) || false !== $pv['is_cart_preview'] ) ) {
					$primary_view_cfg = $pv;
					break;
				}
			}
			if ( null === $primary_view_cfg && ! empty( $config['preview_views'][0] ) && is_array( $config['preview_views'][0] ) ) {
				$primary_view_cfg = $config['preview_views'][0];
			}
		}

		$primary_view_id = ! empty( $primary_view_cfg['id'] ) ? (string) $primary_view_cfg['id'] : 'front';
		$primary_side_id = ! empty( $primary_view_cfg['side_id'] )
			? (string) $primary_view_cfg['side_id']
			: ( ! empty( $config['print_sides'][0]['id'] ) ? (string) $config['print_sides'][0]['id'] : 'front' );
		$primary_label   = ! empty( $primary_view_cfg['label'] )
			? (string) $primary_view_cfg['label']
			: ( ! empty( $config['print_sides'][0]['label'] ) ? (string) $config['print_sides'][0]['label'] : __( 'Recto', 'mr-tee-editor' ) );

		if ( is_array( $decoded_previews ) && ! empty( $decoded_previews[0] ) && is_array( $decoded_previews[0] ) ) {
			if ( ! empty( $decoded_previews[0]['view_id'] ) ) {
				$primary_view_id = substr( preg_replace( '/[^a-zA-Z0-9_-]/', '', (string) $decoded_previews[0]['view_id'] ), 0, 32 );
			}
			if ( ! empty( $decoded_previews[0]['side_id'] ) ) {
				$primary_side_id = substr( preg_replace( '/[^a-zA-Z0-9_-]/', '', (string) $decoded_previews[0]['side_id'] ), 0, 32 );
			}
			if ( ! empty( $decoded_previews[0]['label'] ) ) {
				$primary_label = sanitize_text_field( (string) $decoded_previews[0]['label'] );
			}
		}

		if ( $preview_res && ! empty( $preview_res['url'] ) ) {
			$previews[]                               = array(
				'view_id' => $primary_view_id,
				'side_id' => $primary_side_id,
				'label'   => $primary_label,
				'url'     => $preview_res['url'],
				'file'    => $preview_res['file'],
			);
			$seen_preview_urls[ $preview_res['url'] ] = true;
		}

		if ( is_array( $decoded_previews ) ) {
			foreach ( $decoded_previews as $idx => $entry ) {
				if ( ! is_array( $entry ) ) {
					continue;
				}
				$view_id  = ! empty( $entry['view_id'] ) ? substr( preg_replace( '/[^a-zA-Z0-9_-]/', '', (string) $entry['view_id'] ), 0, 32 ) : 'view_' . $idx;
				$side_id  = ! empty( $entry['side_id'] ) ? substr( preg_replace( '/[^a-zA-Z0-9_-]/', '', (string) $entry['side_id'] ), 0, 32 ) : 'front';
				$label    = ! empty( $entry['label'] ) ? sanitize_text_field( (string) $entry['label'] ) : sprintf( __( 'Vue %d', 'mr-tee-editor' ), $idx + 1 );
				$raw_url  = ! empty( $entry['url'] ) ? (string) $entry['url'] : '';
				$raw_b64  = ! empty( $entry['b64'] ) && is_string( $entry['b64'] ) ? $entry['b64'] : '';
				$resolved = false;
				if ( '' === $raw_url && '' !== $raw_b64 && $idx > 0 ) {
					$is_entry_jpg = ( strpos( $raw_b64, 'image/jpeg' ) !== false || strpos( $raw_b64, 'image/jpg' ) !== false );
					$entry_fname  = 'design_preview_' . $view_id . ( $is_entry_jpg ? '.jpg' : '.png' );
					$resolved     = PrintAssetGenerator::save_base64_png( $raw_b64, $sub_dir, $entry_fname );
				}
				if ( ! $resolved ) {
					$resolved = self::resolve_safe_design_preview( $raw_url, $view_id, $paths );
				}
				if ( ! $resolved || empty( $resolved['url'] ) ) {
					continue;
				}
				if ( isset( $seen_preview_urls[ $resolved['url'] ] ) ) {
					continue;
				}
				$seen_preview_urls[ $resolved['url'] ] = true;
				$previews[]                            = array(
					'view_id' => $view_id,
					'side_id' => $side_id,
					'label'   => $label,
					'url'     => $resolved['url'],
					'file'    => $resolved['file'],
				);
			}
		}

		$allowed_units = array( 'cm', 'mm', 'in' );
		$raw_unit      = ! empty( $payload['print_unit'] )
			? strtolower( trim( (string) $payload['print_unit'] ) )
			: ( isset( $config['print_unit'] ) ? strtolower( trim( (string) $config['print_unit'] ) ) : 'cm' );
		$unit          = in_array( $raw_unit, $allowed_units, true ) ? $raw_unit : 'cm';
		$payload['print_unit'] = $unit;

		$crop_w     = ! empty( $payload['crop_print_width'] ) ? floatval( $payload['crop_print_width'] ) : ( isset( $config['crop_print_width'] ) ? floatval( $config['crop_print_width'] ) : ( isset( $config['print_real_width'] ) ? floatval( $config['print_real_width'] ) : 30.0 ) );
		$crop_h     = ! empty( $payload['crop_print_height'] ) ? floatval( $payload['crop_print_height'] ) : ( isset( $config['crop_print_height'] ) ? floatval( $config['crop_print_height'] ) : ( isset( $config['print_real_height'] ) ? floatval( $config['print_real_height'] ) : 40.0 ) );
		$crop_px_w  = ! empty( $payload['crop_width_px'] ) ? intval( $payload['crop_width_px'] ) : ( isset( $config['print_width_px'] ) ? intval( $config['print_width_px'] ) : 2400 );
		$crop_px_h  = ! empty( $payload['crop_height_px'] ) ? intval( $payload['crop_height_px'] ) : ( isset( $config['print_height_px'] ) ? intval( $config['print_height_px'] ) : 3000 );
		$dpi        = ! empty( $payload['dpi'] ) ? intval( $payload['dpi'] ) : ( isset( $config['dpi'] ) ? intval( $config['dpi'] ) : 300 );
		$dpi        = max( 72, min( 1200, $dpi ) );
		$crop_ratio = ( $crop_h > 0 ) ? round( $crop_w / $crop_h, 3 ) : 1.0;

		$dims_summary = sprintf(
			'%s × %s %s (%d DPI | %d × %d px)',
			$crop_w,
			$crop_h,
			$unit,
			$dpi,
			$crop_px_w,
			$crop_px_h
		);

		$repeater_label      = ! empty( $config['repeater']['label'] ) ? sanitize_text_field( (string) $config['repeater']['label'] ) : '';
		$repeater_item_label = ! empty( $config['repeater']['item_label'] ) ? sanitize_text_field( (string) $config['repeater']['item_label'] ) : '';

		$cart_item_data['mr_tee_editor'] = array(
			'key'                 => $design_key,
			'payload'             => $payload,
			'print_dimensions'    => $dims_summary,
			'print_real_width'    => $crop_w,
			'print_real_height'   => $crop_h,
			'print_width_px'      => $crop_px_w,
			'print_height_px'     => $crop_px_h,
			'ref_real_width'      => isset( $config['print_real_width'] ) ? $config['print_real_width'] : 30.0,
			'ref_real_height'     => isset( $config['print_real_height'] ) ? $config['print_real_height'] : 40.0,
			'print_unit'          => $unit,
			'print_aspect_ratio'  => $crop_ratio,
			'product_type'        => isset( $config['product_type'] ) ? $config['product_type'] : 'garment',
			'repeater_enabled'    => ! empty( $config['repeater']['enabled'] ),
			'repeater_label'      => $repeater_label,
			'repeater_item_label' => $repeater_item_label,
			'preview_url'         => $preview_res ? $preview_res['url'] : ( ! empty( $previews[0]['url'] ) ? $previews[0]['url'] : '' ),
			'preview_file'        => $preview_res ? $preview_res['file'] : ( ! empty( $previews[0]['file'] ) ? $previews[0]['file'] : '' ),
			'previews'            => $previews,
			'svg_url'             => '',
			'svg_file'            => '',
			'print_url'           => '',
			'print_file'          => '',
		);

		// Handle NBDesigner Handshake if enabled or if NBDesigner plugin is active
		$should_handshake = isset( $config['nbd_handshake'] ) ? ! empty( $config['nbd_handshake'] ) : NBDesignerBridge::is_nbd_active();
		if ( $should_handshake && NBDesignerBridge::is_nbd_active() ) {
			$nbd_meta = NBDesignerBridge::inject_nbd_artifacts(
				$design_key,
				$payload,
				$preview_png,
				'',
				'',
				$product_id,
				array(
					'preview_file' => $preview_res ? $preview_res['file'] : '',
					'previews'     => $previews,
				)
			);
			$cart_item_data['mr_tee_editor']['nbd'] = $nbd_meta;
		}

		$cart_item_data['unique_key'] = $design_key;

		return $cart_item_data;
	}

	/**
	 * Build normalized, disambiguated, customer-safe display entries for text & image fields.
	 * Strips leading underscores so meta keys can never clobber hidden `_mr_tee_*` / `_nbd` keys,
	 * preserves `'0'` values, hides unedited `"Image par défaut"` rows, and disambiguates duplicate labels.
	 *
	 * @param array<string, mixed> $payload Sanitized customization payload.
	 * @return array<int, array{key: string, value: string, is_image: bool}>
	 */
	private static function build_display_text_line_entries( array $payload ) {
		if ( empty( $payload['text_lines'] ) || ! is_array( $payload['text_lines'] ) ) {
			return array();
		}

		$side_labels = array(
			'front' => __( 'Recto', 'mr-tee-editor' ),
			'back'  => __( 'Verso', 'mr-tee-editor' ),
		);
		if ( ! empty( $payload['print_sides'] ) && is_array( $payload['print_sides'] ) ) {
			foreach ( $payload['print_sides'] as $ps ) {
				if ( is_array( $ps ) && ! empty( $ps['id'] ) && ! empty( $ps['label'] ) ) {
					$side_labels[ (string) $ps['id'] ] = sanitize_text_field( (string) $ps['label'] );
				}
			}
		}

		$raw_entries = array();
		foreach ( $payload['text_lines'] as $line ) {
			if ( ! is_array( $line ) ) {
				continue;
			}
			$is_image    = isset( $line['fieldType'] ) && in_array( $line['fieldType'], array( 'image', 'ai_image' ), true );
			$raw_val     = isset( $line['value'] ) ? (string) $line['value'] : '';
			$trimmed_val = trim( $raw_val );

			if ( $is_image ) {
				$is_unedited_default = ( isset( $line['edited'] ) && ! $line['edited'] )
					|| ( 0 === strcasecmp( $trimmed_val, 'Image par défaut' ) );
				if ( $is_unedited_default ) {
					continue;
				}
				$img_url     = ! empty( $line['imageUrl'] ) ? self::sanitize_same_site_url( (string) $line['imageUrl'] ) : '';
				$img_summary = ( '' !== $trimmed_val )
					? sanitize_text_field( $raw_val )
					: ( '' !== $img_url ? __( 'Image personnalisée', 'mr-tee-editor' ) : '' );
				if ( '' === trim( $img_summary ) ) {
					continue;
				}
				$clean_val = $img_summary;
			} else {
				if ( '' === $trimmed_val ) {
					continue;
				}
				$clean_val = sanitize_textarea_field( $raw_val );
				if ( '' === trim( $clean_val ) ) {
					continue;
				}
			}

			$raw_label  = isset( $line['label'] ) ? sanitize_text_field( (string) $line['label'] ) : '';
			$base_label = trim( ltrim( trim( $raw_label ), '_' ) );
			if ( '' === $base_label ) {
				$base_label = $is_image ? __( 'Image', 'mr-tee-editor' ) : __( 'Texte', 'mr-tee-editor' );
			}

			$side_id    = ! empty( $line['sideId'] ) ? (string) $line['sideId'] : ( ! empty( $line['side_id'] ) ? (string) $line['side_id'] : '' );
			$side_label = ( '' !== $side_id && isset( $side_labels[ $side_id ] ) ) ? $side_labels[ $side_id ] : '';

			$raw_entries[] = array(
				'base_label' => $base_label,
				'side_id'    => $side_id,
				'side_label' => $side_label,
				'value'      => $clean_val,
				'is_image'   => $is_image,
			);
		}

		// Count duplicates across base labels so unique labels remain clean while colliding labels disambiguate.
		$label_counts = array();
		$label_sides  = array();
		foreach ( $raw_entries as $entry ) {
			$lk = function_exists( 'mb_strtolower' ) ? mb_strtolower( $entry['base_label'], 'UTF-8' ) : strtolower( $entry['base_label'] );
			if ( ! isset( $label_counts[ $lk ] ) ) {
				$label_counts[ $lk ] = 0;
				$label_sides[ $lk ]  = array();
			}
			$label_counts[ $lk ]++;
			if ( '' !== $entry['side_id'] ) {
				$label_sides[ $lk ][ $entry['side_id'] ] = true;
			}
		}

		$used_keys = array();
		$entries   = array();
		foreach ( $raw_entries as $entry ) {
			$lk        = function_exists( 'mb_strtolower' ) ? mb_strtolower( $entry['base_label'], 'UTF-8' ) : strtolower( $entry['base_label'] );
			$candidate = $entry['base_label'];

			if ( $label_counts[ $lk ] > 1 && '' !== $entry['side_label'] && count( $label_sides[ $lk ] ) > 1 ) {
				$candidate = sprintf( '%s (%s)', $entry['base_label'], $entry['side_label'] );
			}

			$candidate = trim( ltrim( $candidate, '_' ) );
			if ( '' === $candidate ) {
				$candidate = $entry['is_image'] ? __( 'Image', 'mr-tee-editor' ) : __( 'Texte', 'mr-tee-editor' );
			}

			$final_key = $candidate;
			$ck        = function_exists( 'mb_strtolower' ) ? mb_strtolower( $final_key, 'UTF-8' ) : strtolower( $final_key );
			$suffix    = 2;
			while ( isset( $used_keys[ $ck ] ) ) {
				$final_key = sprintf( '%s #%d', $candidate, $suffix );
				$ck        = function_exists( 'mb_strtolower' ) ? mb_strtolower( $final_key, 'UTF-8' ) : strtolower( $final_key );
				$suffix++;
			}
			$used_keys[ $ck ] = true;

			$entries[] = array(
				'key'      => $final_key,
				'value'    => $entry['value'],
				'is_image' => $entry['is_image'],
			);
		}

		return $entries;
	}

	/**
	 * Resolve merchant-configured repeater count & detail labels (avoiding hardcoded "champignons").
	 *
	 * @param array<string, mixed> $data       Mr. Tee editor cart/order data array.
	 * @param int                  $product_id Optional product ID fallback for pre-existing cart items.
	 * @return array{count_label: string, detail_label: string, item_label: string}
	 */
	private static function resolve_repeater_labels( array $data, $product_id = 0 ) {
		$count_label = ! empty( $data['repeater_label'] ) ? sanitize_text_field( (string) $data['repeater_label'] ) : '';
		$item_label  = ! empty( $data['repeater_item_label'] ) ? sanitize_text_field( (string) $data['repeater_item_label'] ) : '';

		if ( '' === $count_label && $product_id > 0 ) {
			$cfg = SceneGraphSchema::get_product_config( $product_id, array( 'compile_scene_graph' => false ) );
			if ( ! empty( $cfg['repeater']['label'] ) ) {
				$count_label = sanitize_text_field( (string) $cfg['repeater']['label'] );
			}
			if ( '' === $item_label && ! empty( $cfg['repeater']['item_label'] ) ) {
				$item_label = sanitize_text_field( (string) $cfg['repeater']['item_label'] );
			}
		}

		$count_label = trim( ltrim( $count_label, '_' ) );
		$item_label  = trim( ltrim( $item_label, '_' ) );

		if ( '' === $count_label ) {
			$count_label = __( "Nombre d'éléments", 'mr-tee-editor' );
		}

		$detail_label = '' !== $item_label
			? sprintf( __( 'Détail — %s', 'mr-tee-editor' ), $item_label )
			: __( 'Détail des éléments', 'mr-tee-editor' );

		return array(
			'count_label'  => trim( ltrim( $count_label, '_' ) ),
			'detail_label' => trim( ltrim( $detail_label, '_' ) ),
			'item_label'   => $item_label,
		);
	}

	/**
	 * Render composite preview thumbnail on Customer Order Confirmation ("Thank You"),
	 * View Order, and Order Email views without any file download links.
	 * Uses an email-safe table layout with explicit width and home_url() scheme in emails.
	 *
	 * @param int                    $item_id    Order item ID.
	 * @param \WC_Order_Item_Product $item       Order item object.
	 * @param \WC_Order              $order      Order object.
	 * @param bool                   $plain_text Whether rendering plain-text email.
	 */
	public static function render_order_item_preview_attachment( $item_id, $item, $order, $plain_text = false ) {
		if ( $plain_text || ! is_object( $item ) || ! method_exists( $item, 'get_meta' ) ) {
			return;
		}
		$is_email    = did_action( 'woocommerce_email_header' ) > did_action( 'woocommerce_email_footer' );
		$data        = $item->get_meta( '_mr_tee_editor_data', true );
		$raw_preview = ! empty( $data['preview_url'] ) ? $data['preview_url'] : $item->get_meta( '_mr_tee_preview_url', true );
		$raw_list    = ( is_array( $data ) && ! empty( $data['previews'] ) && is_array( $data['previews'] ) ) ? $data['previews'] : array();
		$lb_previews = self::format_previews_for_lightbox( $raw_list, $raw_preview, $is_email );
		if ( empty( $lb_previews ) ) {
			return;
		}

		if ( $is_email ) {
			$is_multi = count( $lb_previews ) > 1;
			?>
			<table role="presentation" cellpadding="0" cellspacing="0" border="0" style="margin:8px 0 6px;border-collapse:collapse;">
				<tr>
					<?php foreach ( $lb_previews as $pv ) : ?>
						<td valign="top" align="center" style="padding:0 8px 4px 0;border:0;">
							<img
								src="<?php echo esc_url( $pv['src'] ); ?>"
								width="72"
								alt="<?php echo esc_attr( $is_multi ? sprintf( __( 'Aperçu du produit personnalisé — %s', 'mr-tee-editor' ), $pv['label'] ) : __( 'Aperçu du produit personnalisé', 'mr-tee-editor' ) ); ?>"
								style="width:72px;max-width:72px;height:auto;border-radius:6px;border:1px solid #cbd5e1;background:#ffffff;display:block;"
							/>
							<?php if ( $is_multi && '' !== $pv['label'] ) : ?>
								<span style="display:block;margin-top:3px;font-size:11px;font-weight:600;color:#475569;text-align:center;"><?php echo esc_html( $pv['label'] ); ?></span>
							<?php endif; ?>
						</td>
					<?php endforeach; ?>
				</tr>
			</table>
			<?php
			return;
		}

		$preview_url = $lb_previews[0]['src'];
		if ( count( $lb_previews ) > 1 ) {
			$previews_json = wp_json_encode( $lb_previews );
			?>
			<div class="mr-tee-order-preview-attachment" style="margin:8px 0 6px;display:inline-flex;align-items:flex-start;gap:8px;flex-wrap:wrap;padding:6px 8px;background:#f8fafc;border:1px solid #cbd5e1;border-radius:8px;">
				<?php foreach ( $lb_previews as $p_idx => $pv ) : ?>
					<div class="mr-tee-order-view-card" data-mr-tee-view-card="1" style="display:flex;flex-direction:column;align-items:center;gap:3px;cursor:zoom-in;">
						<img
							src="<?php echo esc_url( $pv['src'] ); ?>"
							width="72"
							data-mr-tee-preview="1"
							data-mr-tee-full-src="<?php echo esc_url( $pv['src'] ); ?>"
							data-mr-tee-previews="<?php echo esc_attr( $previews_json ); ?>"
							data-mr-tee-preview-index="<?php echo esc_attr( (string) $p_idx ); ?>"
							alt="<?php echo esc_attr( sprintf( __( 'Aperçu du produit personnalisé — %s', 'mr-tee-editor' ), $pv['label'] ) ); ?>"
							class="skip-lazy mr-tee-cart-thumb"
							style="width:72px;height:auto;max-height:96px;object-fit:contain;border-radius:6px;border:1px solid #cbd5e1;background:#ffffff;display:block;flex-shrink:0;cursor:zoom-in;"
						/>
						<span style="font-size:11px;font-weight:600;color:#475569;"><?php echo esc_html( $pv['label'] ); ?></span>
					</div>
				<?php endforeach; ?>
			</div>
			<?php
			return;
		}
		?>
		<div class="mr-tee-order-preview-attachment" style="margin:8px 0 6px;display:inline-flex;align-items:center;padding:6px 8px;background:#f8fafc;border:1px solid #cbd5e1;border-radius:8px;">
			<img src="<?php echo esc_url( $preview_url ); ?>" width="72" data-mr-tee-preview="1" data-mr-tee-full-src="<?php echo esc_url( $preview_url ); ?>" alt="<?php esc_attr_e( 'Aperçu du produit personnalisé', 'mr-tee-editor' ); ?>" class="skip-lazy mr-tee-cart-thumb" style="width:72px;height:auto;max-height:96px;object-fit:contain;border-radius:6px;border:1px solid #cbd5e1;background:#ffffff;display:block;flex-shrink:0;cursor:zoom-in;" />
		</div>
		<?php
	}

	/**
	 * Display formatted customization details in cart and checkout (plain text only, no download links).
	 *
	 * @param array<int, array<string, mixed>> $item_data Existing item display data.
	 * @param array<string, mixed>             $cart_item Cart item array.
	 * @return array<int, array<string, mixed>>
	 */
	public static function display_cart_item_data( $item_data, $cart_item ) {
		if ( empty( $cart_item['mr_tee_editor'] ) || ! is_array( $cart_item['mr_tee_editor'] ) ) {
			return $item_data;
		}

		$data       = $cart_item['mr_tee_editor'];
		$payload    = ( ! empty( $data['payload'] ) && is_array( $data['payload'] ) ) ? $data['payload'] : array();
		$icons      = IconSetRegistry::get_mushroom_icons();
		$product_id = ! empty( $cart_item['product_id'] ) ? (int) $cart_item['product_id'] : 0;

		// Text & Image fields (raw sanitized value, ' | ' display separator, no double-escaping, no rotation degrees, no default image rows)
		foreach ( self::build_display_text_line_entries( $payload ) as $entry ) {
			$display_text = str_replace( array( "\r\n", "\r", "\n" ), ' | ', $entry['value'] );
			$item_data[]  = array(
				'key'     => $entry['key'],
				'value'   => $entry['value'],
				'display' => ( '0' === $display_text ) ? '0&nbsp;' : esc_html( $display_text ),
			);
		}

		// Repeater items using merchant's configured repeater label
		if ( isset( $payload['repeater_count'] ) ) {
			$count            = intval( $payload['repeater_count'] );
			$repeater_enabled = array_key_exists( 'repeater_enabled', $data ) ? ! empty( $data['repeater_enabled'] ) : ( $count > 0 );
			if ( $repeater_enabled || $count > 0 ) {
				$rep_labels  = self::resolve_repeater_labels( $data, $product_id );
				$item_data[] = array(
					'key'     => $rep_labels['count_label'],
					'value'   => (string) $count,
					'display' => ( 0 === $count ) ? '0&nbsp;' : (string) $count,
				);

				if ( $count > 0 && ! empty( $payload['repeater_items'] ) && is_array( $payload['repeater_items'] ) ) {
					$repeater_summary = array();
					foreach ( $payload['repeater_items'] as $idx => $m_item ) {
						if ( $idx >= $count ) {
							break;
						}
						if ( ! is_array( $m_item ) ) {
							continue;
						}
						$raw_name = isset( $m_item['name'] ) ? sanitize_text_field( (string) $m_item['name'] ) : '';
						$name     = ( '' !== trim( $raw_name ) ) ? $raw_name : __( 'Sans nom', 'mr-tee-editor' );
						$ic_key   = ! empty( $m_item['icon'] ) ? sanitize_key( (string) $m_item['icon'] ) : 'mushroom_red';
						$ic_name  = isset( $icons[ $ic_key ] ) ? $icons[ $ic_key ]['name'] : $ic_key;
						$repeater_summary[] = sprintf( '%s (%s)', $name, $ic_name );
					}
					if ( ! empty( $repeater_summary ) ) {
						$item_data[] = array(
							'key'     => $rep_labels['detail_label'],
							'value'   => implode( ' | ', $repeater_summary ),
							'display' => implode( ' | ', array_map( 'esc_html', $repeater_summary ) ),
						);
					}
				}
			}
		}

		return $item_data;
	}

	/**
	 * Transfer cart customization data to WooCommerce order line item.
	 *
	 * @param \WC_Order_Item_Product $item          Order item object.
	 * @param string                 $cart_item_key Cart item key.
	 * @param array<string, mixed>   $values        Cart item values.
	 * @param \WC_Order              $order         Order object.
	 */
	public static function save_order_line_item_data( $item, $cart_item_key, $values, $order ) {
		if ( empty( $values['mr_tee_editor'] ) || ! is_array( $values['mr_tee_editor'] ) ) {
			return;
		}

		$data       = $values['mr_tee_editor'];
		$payload    = ( ! empty( $data['payload'] ) && is_array( $data['payload'] ) ) ? $data['payload'] : array();
		$icons      = IconSetRegistry::get_mushroom_icons();
		$product_id = ! empty( $values['product_id'] ) ? (int) $values['product_id'] : ( method_exists( $item, 'get_product_id' ) ? (int) $item->get_product_id() : 0 );

		$item->add_meta_data( '_mr_tee_editor_data', $data, true );

		// Text & Image fields readable meta (disambiguated keys, leading '_' stripped, '0' preserved, unedited default images omitted)
		$used_meta_keys = array();
		foreach ( self::build_display_text_line_entries( $payload ) as $entry ) {
			$item->add_meta_data( $entry['key'], $entry['value'], true );
			$uk                    = function_exists( 'mb_strtolower' ) ? mb_strtolower( $entry['key'], 'UTF-8' ) : strtolower( $entry['key'] );
			$used_meta_keys[ $uk ] = true;
		}

		// Repeater summary readable meta using merchant's configured label and guarding empty summaries
		if ( isset( $payload['repeater_count'] ) ) {
			$count            = intval( $payload['repeater_count'] );
			$repeater_enabled = array_key_exists( 'repeater_enabled', $data ) ? ! empty( $data['repeater_enabled'] ) : ( $count > 0 );
			if ( $repeater_enabled || $count > 0 ) {
				$rep_labels = self::resolve_repeater_labels( $data, $product_id );
				$count_key  = $rep_labels['count_label'];
				$ck         = function_exists( 'mb_strtolower' ) ? mb_strtolower( $count_key, 'UTF-8' ) : strtolower( $count_key );
				if ( isset( $used_meta_keys[ $ck ] ) ) {
					$count_key .= ' #2';
				}
				$item->add_meta_data( $count_key, (string) $count, true );

				if ( $count > 0 && ! empty( $payload['repeater_items'] ) && is_array( $payload['repeater_items'] ) ) {
					$summary = array();
					foreach ( $payload['repeater_items'] as $idx => $m_item ) {
						if ( $idx >= $count ) {
							break;
						}
						if ( ! is_array( $m_item ) ) {
							continue;
						}
						$raw_name  = isset( $m_item['name'] ) ? sanitize_text_field( (string) $m_item['name'] ) : '';
						$name      = ( '' !== trim( $raw_name ) ) ? $raw_name : __( 'Sans nom', 'mr-tee-editor' );
						$ic_key    = ! empty( $m_item['icon'] ) ? sanitize_key( (string) $m_item['icon'] ) : 'mushroom_red';
						$ic_name   = isset( $icons[ $ic_key ] ) ? $icons[ $ic_key ]['name'] : $ic_key;
						$summary[] = sprintf( '%d. %s (%s)', $idx + 1, $name, $ic_name );
					}
					if ( ! empty( $summary ) ) {
						$detail_key = $rep_labels['detail_label'];
						$dk         = function_exists( 'mb_strtolower' ) ? mb_strtolower( $detail_key, 'UTF-8' ) : strtolower( $detail_key );
						if ( isset( $used_meta_keys[ $dk ] ) ) {
							$detail_key .= ' #2';
						}
						$item->add_meta_data( $detail_key, implode( "\n", $summary ), true );
					}
				}
			}
		}

		// Hidden internal metadata (only visible in WP Admin via OrderAdminPanel)
		if ( ! empty( $data['print_dimensions'] ) ) {
			$item->add_meta_data( '_mr_tee_print_dimensions', $data['print_dimensions'], true );
		}
		if ( ! empty( $data['preview_url'] ) ) {
			$item->add_meta_data( '_mr_tee_preview_url', $data['preview_url'], true );
		}

		// NBDesigner native handshake meta
		if ( ! empty( $data['nbd']['key'] ) ) {
			$item->add_meta_data( '_nbd', $data['nbd']['key'], true );
			$item->add_meta_data( '_nbd_cart_item_key', $data['nbd']['key'], true );
			$item->add_meta_data( '_nbdesigner_data', $data['nbd']['nbd_data'], true );

			if ( $order && is_a( $order, '\WC_Order' ) ) {
				$order->update_meta_data( '_nbd', '1' );
			}
		}
	}

	/**
	 * Persist order-level `_nbd = 1` flag once the WooCommerce order has been saved
	 * (compatible with Classic Checkout, Store API / Block Checkout, HPOS, and CPT storage).
	 *
	 * @param \WC_Order $order Order object.
	 */
	public static function sync_nbd_order_meta( $order ) {
		if ( ! $order || ! is_a( $order, '\WC_Order' ) || ! $order->get_id() ) {
			return;
		}
		$has_nbd = false;
		foreach ( $order->get_items() as $item ) {
			if ( is_object( $item ) && method_exists( $item, 'get_meta' ) && ! empty( $item->get_meta( '_nbd', true ) ) ) {
				$has_nbd = true;
				break;
			}
		}
		if ( ! $has_nbd ) {
			return;
		}
		if ( '1' !== (string) $order->get_meta( '_nbd', true ) ) {
			$order->update_meta_data( '_nbd', '1' );
			$order->save();
		}
	}

	/**
	 * Register the personalized preview in an in-memory per-request PHP map keyed by `spl_object_id( $product )`
	 * so themes/side-carts that call `$_product->get_image()` directly still render the custom preview
	 * WITHOUT ever calling `$product->update_meta_data()` (preventing leakage into global `wp_postmeta`).
	 *
	 * @param array<string, mixed> $cart_item Cart item array.
	 * @return array<string, mixed>
	 */
	public static function attach_preview_to_cart_product( $cart_item ) {
		if ( ! empty( $cart_item['mr_tee_editor']['preview_url'] ) && ! empty( $cart_item['data'] ) && is_object( $cart_item['data'] ) ) {
			$obj_id                                  = spl_object_id( $cart_item['data'] );
			self::$runtime_cart_previews[ $obj_id ] = array(
				'url'      => (string) $cart_item['mr_tee_editor']['preview_url'],
				'previews' => ( ! empty( $cart_item['mr_tee_editor']['previews'] ) && is_array( $cart_item['mr_tee_editor']['previews'] ) )
					? $cart_item['mr_tee_editor']['previews']
					: array(),
			);
		}
		return $cart_item;
	}

	/**
	 * Restore in-memory preview mapping when cart loads from session.
	 *
	 * @param array<string, mixed> $cart_item Cart item array.
	 * @param array<string, mixed> $values    Session values.
	 * @return array<string, mixed>
	 */
	public static function filter_cart_item_from_session( $cart_item, $values ) {
		if ( ! empty( $values['mr_tee_editor'] ) && empty( $cart_item['mr_tee_editor'] ) ) {
			$cart_item['mr_tee_editor'] = $values['mr_tee_editor'];
		}
		return self::attach_preview_to_cart_product( $cart_item );
	}

	/**
	 * Build the standard zoomable cart thumbnail HTML markup.
	 *
	 * @param string            $raw_preview_url Raw preview URL.
	 * @param string            $product_name    Optional product name for alt text.
	 * @param array<int, mixed> $previews        Optional multi-view previews list.
	 * @return string
	 */
	private static function build_cart_thumb_html( $raw_preview_url, $product_name = '', array $previews = array() ) {
		$preview_url = self::normalize_url( $raw_preview_url );
		if ( empty( $preview_url ) ) {
			return '';
		}
		$alt = ! empty( $product_name )
			? sprintf( __( 'Aperçu personnalisé — %s', 'mr-tee-editor' ), $product_name )
			: __( 'Aperçu du produit personnalisé', 'mr-tee-editor' );

		$lb_previews   = self::format_previews_for_lightbox( $previews, $preview_url );
		$previews_attr = '';
		$views_badge   = '';
		$wrap_class    = 'mr-tee-cart-thumb-wrap';
		if ( count( $lb_previews ) > 1 ) {
			$previews_attr = sprintf( ' data-mr-tee-previews="%s"', esc_attr( wp_json_encode( $lb_previews ) ) );
			$views_badge   = sprintf(
				'<span class="mr-tee-cart-views-badge" aria-hidden="true">%s</span>',
				esc_html( sprintf( _n( '%d vue', '%d vues', count( $lb_previews ), 'mr-tee-editor' ), count( $lb_previews ) ) )
			);
			$wrap_class   .= ' mr-tee-has-views-badge';
		}

		return sprintf(
			'<span class="%s" data-mr-tee-preview-wrap="1" title="%s"><img src="%s" data-mr-tee-preview="1" data-mr-tee-full-src="%s"%s class="attachment-woocommerce_thumbnail size-woocommerce_thumbnail mr-tee-cart-thumb skip-lazy" alt="%s" style="width:80px;max-width:100%%;height:auto;max-height:110px;object-fit:contain;border-radius:6px;border:1px solid #e2e8f0;background:#ffffff;display:block;cursor:zoom-in;" />%s<span class="mr-tee-cart-zoom-hint" aria-hidden="true">+</span></span>',
			esc_attr( $wrap_class ),
			esc_attr__( 'Cliquer pour agrandir votre aperçu personnalisé', 'mr-tee-editor' ),
			esc_url( $preview_url ),
			esc_url( $preview_url ),
			$previews_attr,
			esc_attr( $alt ),
			$views_badge
		);
	}

	/**
	 * Fallback filter on WC_Product::get_image() for ThemeForest / Astra / third-party AJAX side-cart
	 * templates that call $_product->get_image() directly without applying woocommerce_cart_item_thumbnail.
	 *
	 * @param string      $image       Existing HTML image tag.
	 * @param \WC_Product $product     Product instance.
	 * @param string      $size        Image size.
	 * @param array       $attr        Attributes.
	 * @param bool        $placeholder Whether placeholder is enabled.
	 * @return string
	 */
	public static function filter_product_get_image_in_cart( $image, $product, $size = 'woocommerce_thumbnail', $attr = array(), $placeholder = true ) {
		if ( ! is_object( $product ) ) {
			return $image;
		}
		$obj_id = spl_object_id( $product );
		if ( empty( self::$runtime_cart_previews[ $obj_id ]['url'] ) ) {
			return $image;
		}
		$entry = self::$runtime_cart_previews[ $obj_id ];
		$name  = method_exists( $product, 'get_name' ) ? $product->get_name() : '';
		$html  = self::build_cart_thumb_html( $entry['url'], $name, is_array( $entry['previews'] ) ? $entry['previews'] : array() );
		return ! empty( $html ) ? $html : $image;
	}

	/**
	 * Filter cart item thumbnail to show the composite personalized product preview.
	 *
	 * @param string               $thumbnail     Existing thumbnail HTML.
	 * @param array<string, mixed> $cart_item     Cart item array.
	 * @param string               $cart_item_key Cart item key.
	 * @return string
	 */
	public static function filter_cart_item_thumbnail( $thumbnail, $cart_item, $cart_item_key ) {
		if ( ! empty( $cart_item['mr_tee_editor']['preview_url'] ) ) {
			$name     = ( ! empty( $cart_item['data'] ) && method_exists( $cart_item['data'], 'get_name' ) )
				? $cart_item['data']->get_name()
				: '';
			$previews = ( ! empty( $cart_item['mr_tee_editor']['previews'] ) && is_array( $cart_item['mr_tee_editor']['previews'] ) )
				? $cart_item['mr_tee_editor']['previews']
				: array();
			$html     = self::build_cart_thumb_html( $cart_item['mr_tee_editor']['preview_url'], $name, $previews );
			if ( ! empty( $html ) ) {
				return $html;
			}
		}
		return $thumbnail;
	}

	/**
	 * Filter WooCommerce Store API / Cart Block item images so modern Block Cart
	 * displays the composite customized product preview instead of the blank mockup.
	 *
	 * @param array<int, object>   $product_images Existing image objects.
	 * @param array<string, mixed> $cart_item      Cart item array.
	 * @param string               $cart_item_key  Cart item key.
	 * @return array<int, object>
	 */
	public static function filter_store_api_cart_item_images( $product_images, $cart_item, $cart_item_key ) {
		if ( empty( $cart_item['mr_tee_editor']['preview_url'] ) ) {
			return $product_images;
		}
		$preview_url = self::normalize_url( $cart_item['mr_tee_editor']['preview_url'] );
		if ( 0 === strpos( $preview_url, '/' ) && 0 !== strpos( $preview_url, '//' ) ) {
			$preview_url = set_url_scheme( rtrim( home_url(), '/' ) . $preview_url, is_ssl() ? 'https' : 'http' );
		}
		if ( empty( $preview_url ) || ! filter_var( $preview_url, FILTER_VALIDATE_URL ) ) {
			return $product_images;
		}

		$attachment_id = ! empty( $cart_item['product_id'] ) ? (int) get_post_thumbnail_id( $cart_item['product_id'] ) : 0;

		$item_name = ( ! empty( $cart_item['data'] ) && method_exists( $cart_item['data'], 'get_name' ) )
			? $cart_item['data']->get_name()
			: __( 'Produit personnalisé', 'mr-tee-editor' );

		return array(
			(object) array(
				'id'        => $attachment_id,
				'src'       => $preview_url,
				'thumbnail' => $preview_url,
				'srcset'    => '',
				'sizes'     => '',
				'name'      => sprintf( __( 'Aperçu — %s', 'mr-tee-editor' ), $item_name ),
				'alt'       => sprintf( __( 'Aperçu du produit personnalisé — %s', 'mr-tee-editor' ), $item_name ),
			),
		);
	}

	/**
	 * Render or replace checkout review item thumbnail with the composite preview image across all themes,
	 * skipping Mini-Cart widgets so they never get duplicate thumbnails.
	 *
	 * @param string               $product_name  Checkout item name HTML.
	 * @param array<string, mixed> $cart_item     Cart item array.
	 * @param string               $cart_item_key Cart item key.
	 * @return string
	 */
	public static function filter_checkout_item_name( $product_name, $cart_item, $cart_item_key ) {
		if ( ! function_exists( 'is_checkout' ) || ! is_checkout() ) {
			return $product_name;
		}
		if ( did_action( 'woocommerce_before_mini_cart' ) > did_action( 'woocommerce_after_mini_cart' ) ) {
			return $product_name;
		}
		if ( empty( $cart_item['mr_tee_editor']['preview_url'] ) ) {
			return $product_name;
		}
		if ( false !== strpos( $product_name, 'mr-tee-checkout-thumb' ) || false !== strpos( $product_name, 'mr-tee-cart-thumb' ) ) {
			return $product_name;
		}
		$preview_url = self::normalize_url( $cart_item['mr_tee_editor']['preview_url'] );
		if ( empty( $preview_url ) ) {
			return $product_name;
		}

		$raw_previews  = ( ! empty( $cart_item['mr_tee_editor']['previews'] ) && is_array( $cart_item['mr_tee_editor']['previews'] ) )
			? $cart_item['mr_tee_editor']['previews']
			: array();
		$lb_previews   = self::format_previews_for_lightbox( $raw_previews, $preview_url );
		$previews_attr = count( $lb_previews ) > 1
			? sprintf( ' data-mr-tee-previews="%s"', esc_attr( wp_json_encode( $lb_previews ) ) )
			: '';

		$thumb_html = sprintf(
			'<img src="%s" data-mr-tee-preview="1" data-mr-tee-full-src="%s"%s alt="%s" class="skip-lazy mr-tee-checkout-thumb" style="width:60px;height:auto;max-height:80px;object-fit:contain;border-radius:6px;border:1px solid #e2e8f0;background:#ffffff;cursor:zoom-in;" />',
			esc_url( $preview_url ),
			esc_url( $preview_url ),
			$previews_attr,
			esc_attr__( 'Aperçu du produit personnalisé', 'mr-tee-editor' )
		);

		if ( false !== strpos( $product_name, 'product-item-thumbnail' ) ) {
			$replaced = preg_replace_callback(
				'#(<div\b[^>]*class="[^"]*\bproduct-item-thumbnail\b[^"]*"[^>]*>).*?(</div>)#s',
				static function ( $matches ) use ( $thumb_html ) {
					return $matches[1] . $thumb_html . $matches[2];
				},
				$product_name,
				1
			);
			if ( is_string( $replaced ) && '' !== $replaced ) {
				return $replaced;
			}
		}

		if ( preg_match( '#<img\b[^>]*>#i', $product_name ) ) {
			$replaced_img = preg_replace_callback(
				'#<img\b[^>]*>#i',
				static function () use ( $thumb_html ) {
					return $thumb_html;
				},
				$product_name,
				1
			);
			if ( is_string( $replaced_img ) && '' !== $replaced_img ) {
				return $replaced_img;
			}
		}

		return '<span class="mr-tee-checkout-thumb-wrap" style="display:inline-flex;align-items:center;gap:10px;vertical-align:middle;"><span style="flex-shrink:0;line-height:0;">' . $thumb_html . '</span><span>' . $product_name . '</span></span>';
	}

	/**
	 * Output universal Cart / Checkout / Order Preview Lightbox Modal and capture-phase click interceptor
	 * so clicking a personalized product thumbnail on any WordPress theme opens a large preview dialog
	 * with multi-view switcher pills & prev/next navigation instead of navigating away.
	 */
	public static function render_cart_preview_lightbox() {
		if ( is_admin() ) {
			return;
		}

		$has_mr_tee_in_cart = false;
		$cart_previews_map  = array();
		if ( function_exists( 'WC' ) && WC() && ! empty( WC()->cart ) && method_exists( WC()->cart, 'get_cart' ) ) {
			foreach ( WC()->cart->get_cart() as $c_item ) {
				if ( empty( $c_item['mr_tee_editor'] ) || ! is_array( $c_item['mr_tee_editor'] ) ) {
					continue;
				}
				$has_mr_tee_in_cart = true;
				if ( empty( $c_item['mr_tee_editor']['preview_url'] ) || empty( $c_item['mr_tee_editor']['previews'] ) || ! is_array( $c_item['mr_tee_editor']['previews'] ) ) {
					continue;
				}
				$primary_norm = self::normalize_url( $c_item['mr_tee_editor']['preview_url'] );
				$lb_list      = self::format_previews_for_lightbox( $c_item['mr_tee_editor']['previews'], $primary_norm );
				if ( '' !== $primary_norm && count( $lb_list ) > 1 ) {
					$cart_previews_map[ $primary_norm ] = $lb_list;
					$parsed_path                        = wp_parse_url( $primary_norm, PHP_URL_PATH );
					if ( is_string( $parsed_path ) && '' !== $parsed_path ) {
						$cart_previews_map[ $parsed_path ] = $lb_list;
					}
				}
			}
		}

		$is_commerce_view = ( function_exists( 'is_cart' ) && is_cart() )
			|| ( function_exists( 'is_checkout' ) && is_checkout() )
			|| ( function_exists( 'is_account_page' ) && is_account_page() )
			|| ( function_exists( 'is_wc_endpoint_url' ) && is_wc_endpoint_url() );

		if ( ! $has_mr_tee_in_cart && ! $is_commerce_view ) {
			return;
		}
		?>
		<style id="mr-tee-cart-preview-lightbox-css">
			.mr-tee-cart-thumb-wrap {
				position: relative !important;
				display: inline-block !important;
				line-height: 0 !important;
				cursor: zoom-in !important;
				vertical-align: middle !important;
			}
			.mr-tee-cart-thumb,
			.mr-tee-checkout-thumb,
			.mr-tee-order-preview-attachment img,
			.woocommerce-cart-form img[src*="mr_tee_editor/designs/"],
			.wc-block-cart-item__image img[src*="mr_tee_editor/designs/"],
			.wc-block-components-order-summary-item__image img[src*="mr_tee_editor/designs/"],
			img[src*="mr_tee_editor/designs/"] {
				cursor: zoom-in !important;
				transition: transform 0.16s ease, box-shadow 0.16s ease !important;
			}
			.mr-tee-cart-thumb-wrap:hover .mr-tee-cart-thumb,
			.mr-tee-checkout-thumb:hover,
			.woocommerce-cart-form img[src*="mr_tee_editor/designs/"]:hover,
			.wc-block-cart-item__image img[src*="mr_tee_editor/designs/"]:hover {
				transform: scale(1.03);
				box-shadow: 0 4px 12px rgba(15, 23, 42, 0.14);
			}
			.mr-tee-cart-zoom-hint {
				position: absolute !important;
				right: 4px !important;
				bottom: 4px !important;
				width: 20px !important;
				height: 20px !important;
				border-radius: 999px !important;
				background: rgba(15, 23, 42, 0.78) !important;
				color: #ffffff !important;
				font-size: 13px !important;
				font-weight: 700 !important;
				line-height: 20px !important;
				text-align: center !important;
				pointer-events: none !important;
				box-shadow: 0 2px 6px rgba(0, 0, 0, 0.25) !important;
			}
			.mr-tee-cart-views-badge {
				position: absolute !important;
				left: 4px !important;
				bottom: 4px !important;
				padding: 2px 6px !important;
				border-radius: 999px !important;
				background: rgba(15, 23, 42, 0.82) !important;
				color: #ffffff !important;
				font-size: 10px !important;
				font-weight: 700 !important;
				line-height: 1.4 !important;
				letter-spacing: 0.01em !important;
				white-space: nowrap !important;
				pointer-events: none !important;
				box-shadow: 0 2px 6px rgba(0, 0, 0, 0.22) !important;
			}
			.mr-tee-cart-thumb-wrap.mr-tee-has-views-badge .mr-tee-cart-zoom-hint,
			.mr-tee-cart-thumb-wrap:has(.mr-tee-cart-views-badge) .mr-tee-cart-zoom-hint {
				top: 4px !important;
				bottom: auto !important;
				right: 4px !important;
			}
			#mr-tee-cart-preview-modal {
				position: fixed !important;
				inset: 0 !important;
				z-index: 2147483647 !important;
				display: none;
				align-items: center !important;
				justify-content: center !important;
				background: rgba(15, 23, 42, 0.88) !important;
				backdrop-filter: blur(5px) !important;
				-webkit-backdrop-filter: blur(5px) !important;
				padding: 14px !important;
				box-sizing: border-box !important;
			}
			#mr-tee-cart-preview-modal.is-open {
				display: flex !important;
			}
			.mr-tee-cart-modal-dialog {
				position: relative !important;
				width: 100% !important;
				max-width: 720px !important;
				max-height: 92vh !important;
				background: #ffffff !important;
				border-radius: 16px !important;
				box-shadow: 0 25px 60px rgba(0, 0, 0, 0.48) !important;
				display: flex !important;
				flex-direction: column !important;
				overflow: hidden !important;
				box-sizing: border-box !important;
			}
			.mr-tee-cart-modal-header {
				display: flex !important;
				align-items: center !important;
				justify-content: space-between !important;
				gap: 10px !important;
				padding: 12px 16px !important;
				border-bottom: 1px solid #e2e8f0 !important;
				background: #ffffff !important;
				flex-shrink: 0 !important;
			}
			.mr-tee-cart-modal-title {
				margin: 0 !important;
				font-size: 15px !important;
				font-weight: 700 !important;
				color: #0f172a !important;
				line-height: 1.3 !important;
				overflow: hidden !important;
				text-overflow: ellipsis !important;
				white-space: nowrap !important;
			}
			.mr-tee-cart-modal-actions {
				display: inline-flex !important;
				align-items: center !important;
				gap: 8px !important;
				flex-shrink: 0 !important;
			}
			.mr-tee-cart-modal-btn {
				appearance: none !important;
				-webkit-appearance: none !important;
				border: 1px solid #cbd5e1 !important;
				background: #f8fafc !important;
				color: #0f172a !important;
				border-radius: 8px !important;
				height: 36px !important;
				min-width: 36px !important;
				padding: 0 10px !important;
				font-size: 13px !important;
				font-weight: 600 !important;
				line-height: 1 !important;
				display: inline-flex !important;
				align-items: center !important;
				justify-content: center !important;
				cursor: pointer !important;
				text-decoration: none !important;
				box-shadow: none !important;
				margin: 0 !important;
			}
			.mr-tee-cart-modal-btn:hover {
				background: #e2e8f0 !important;
				color: #0f172a !important;
			}
			.mr-tee-cart-modal-close {
				font-size: 20px !important;
				padding: 0 !important;
				width: 36px !important;
			}
			.mr-tee-cart-modal-views-bar {
				display: none;
				align-items: center !important;
				justify-content: center !important;
				gap: 8px !important;
				flex-wrap: wrap !important;
				padding: 10px 16px !important;
				background: #f8fafc !important;
				border-bottom: 1px solid #e2e8f0 !important;
				flex-shrink: 0 !important;
			}
			.mr-tee-cart-modal-views-bar.is-visible {
				display: flex !important;
			}
			.mr-tee-cart-view-pill {
				appearance: none !important;
				-webkit-appearance: none !important;
				border: 1px solid #cbd5e1 !important;
				background: #ffffff !important;
				color: #334155 !important;
				border-radius: 999px !important;
				padding: 6px 14px !important;
				font-size: 13px !important;
				font-weight: 600 !important;
				line-height: 1.2 !important;
				cursor: pointer !important;
				transition: background 0.15s ease, color 0.15s ease, border-color 0.15s ease !important;
				margin: 0 !important;
				box-shadow: none !important;
			}
			.mr-tee-cart-view-pill:hover {
				background: #e2e8f0 !important;
				color: #0f172a !important;
			}
			.mr-tee-cart-view-pill.is-active {
				background: #0f172a !important;
				color: #ffffff !important;
				border-color: #0f172a !important;
			}
			.mr-tee-cart-modal-stage-wrap {
				position: relative !important;
				flex: 1 1 auto !important;
				display: flex !important;
				min-height: 240px !important;
				max-height: calc(92vh - 62px) !important;
				overflow: hidden !important;
			}
			.mr-tee-cart-modal-nav {
				position: absolute !important;
				top: 50% !important;
				transform: translateY(-50%) !important;
				z-index: 4 !important;
				width: 40px !important;
				height: 40px !important;
				border-radius: 999px !important;
				border: 1px solid rgba(15, 23, 42, 0.14) !important;
				background: rgba(255, 255, 255, 0.94) !important;
				color: #0f172a !important;
				font-size: 22px !important;
				font-weight: 700 !important;
				line-height: 1 !important;
				display: none;
				align-items: center !important;
				justify-content: center !important;
				cursor: pointer !important;
				padding: 0 !important;
				margin: 0 !important;
				box-shadow: 0 4px 14px rgba(15, 23, 42, 0.18) !important;
			}
			.mr-tee-cart-modal-nav.is-visible {
				display: inline-flex !important;
			}
			.mr-tee-cart-modal-nav:hover {
				background: #0f172a !important;
				color: #ffffff !important;
			}
			.mr-tee-cart-modal-prev {
				left: 12px !important;
			}
			.mr-tee-cart-modal-next {
				right: 12px !important;
			}
			.mr-tee-cart-modal-body {
				flex: 1 1 auto !important;
				overflow: auto !important;
				display: flex !important;
				align-items: center !important;
				justify-content: center !important;
				background: #f1f5f9 !important;
				padding: 16px !important;
				min-height: 240px !important;
				box-sizing: border-box !important;
				-webkit-overflow-scrolling: touch !important;
			}
			.mr-tee-cart-modal-dialog.is-zoomed .mr-tee-cart-modal-body {
				align-items: flex-start !important;
				justify-content: flex-start !important;
			}
			.mr-tee-cart-modal-img {
				display: block !important;
				width: auto !important;
				height: auto !important;
				max-width: 100% !important;
				max-height: calc(84vh - 120px) !important;
				object-fit: contain !important;
				border-radius: 10px !important;
				background: #ffffff !important;
				box-shadow: 0 8px 28px rgba(15, 23, 42, 0.12) !important;
				cursor: zoom-in !important;
				margin: auto !important;
			}
			.mr-tee-cart-modal-dialog.is-zoomed .mr-tee-cart-modal-img {
				width: 175% !important;
				max-width: none !important;
				max-height: none !important;
				cursor: zoom-out !important;
			}
		</style>
		<div id="mr-tee-cart-preview-modal" role="dialog" aria-modal="true" aria-hidden="true" aria-label="<?php esc_attr_e( 'Aperçu du produit personnalisé', 'mr-tee-editor' ); ?>">
			<div class="mr-tee-cart-modal-dialog" id="mr-tee-cart-modal-dialog">
				<div class="mr-tee-cart-modal-header">
					<div class="mr-tee-cart-modal-title" id="mr-tee-cart-modal-title"><?php esc_html_e( 'Aperçu de votre personnalisation', 'mr-tee-editor' ); ?></div>
					<div class="mr-tee-cart-modal-actions">
						<button type="button" class="mr-tee-cart-modal-btn" id="mr-tee-cart-modal-zoom" aria-label="<?php esc_attr_e( 'Zoomer', 'mr-tee-editor' ); ?>">Zoom +</button>
						<button type="button" class="mr-tee-cart-modal-btn mr-tee-cart-modal-close" id="mr-tee-cart-modal-close" aria-label="<?php esc_attr_e( 'Fermer', 'mr-tee-editor' ); ?>">&times;</button>
					</div>
				</div>
				<div class="mr-tee-cart-modal-views-bar" id="mr-tee-cart-modal-views" role="tablist" aria-label="<?php esc_attr_e( 'Changer de vue', 'mr-tee-editor' ); ?>"></div>
				<div class="mr-tee-cart-modal-stage-wrap">
					<button type="button" class="mr-tee-cart-modal-nav mr-tee-cart-modal-prev" id="mr-tee-cart-modal-prev" aria-label="<?php esc_attr_e( 'Vue précédente', 'mr-tee-editor' ); ?>">&lsaquo;</button>
					<div class="mr-tee-cart-modal-body" id="mr-tee-cart-modal-body">
						<img src="" alt="<?php esc_attr_e( 'Aperçu du produit personnalisé', 'mr-tee-editor' ); ?>" class="mr-tee-cart-modal-img skip-lazy" id="mr-tee-cart-modal-img" />
					</div>
					<button type="button" class="mr-tee-cart-modal-nav mr-tee-cart-modal-next" id="mr-tee-cart-modal-next" aria-label="<?php esc_attr_e( 'Vue suivante', 'mr-tee-editor' ); ?>">&rsaquo;</button>
				</div>
			</div>
		</div>
		<script id="mr-tee-cart-preview-lightbox-js">
		(function() {
			var PREVIEW_SEL = 'img.mr-tee-cart-thumb, img.mr-tee-checkout-thumb, .mr-tee-order-preview-attachment img, img[data-mr-tee-preview="1"], img[src*="mr_tee_editor/designs/"]';
			var THUMB_CELL_SEL = 'td.product-thumbnail, .product-thumbnail, .wc-block-cart-item__image, .wc-block-components-order-summary-item__image, .cart-item-thumbnail, .cart-item-image, .ast-cart-item-thumbnail, .wd-cart-item-img';
			var CART_PREVIEWS_MAP = <?php echo wp_json_encode( $cart_previews_map ); ?> || {};
			var modalState = { previews: [], activeIndex: 0, baseTitle: '' };

			function getModalElements() {
				var modal = document.getElementById('mr-tee-cart-preview-modal');
				var dialog = document.getElementById('mr-tee-cart-modal-dialog');
				var img = document.getElementById('mr-tee-cart-modal-img');
				var title = document.getElementById('mr-tee-cart-modal-title');
				var zoomBtn = document.getElementById('mr-tee-cart-modal-zoom');
				var closeBtn = document.getElementById('mr-tee-cart-modal-close');
				var viewsBar = document.getElementById('mr-tee-cart-modal-views');
				var prevBtn = document.getElementById('mr-tee-cart-modal-prev');
				var nextBtn = document.getElementById('mr-tee-cart-modal-next');
				if (modal && modal.parentElement !== document.body) {
					document.body.appendChild(modal);
				}
				return {
					modal: modal,
					dialog: dialog,
					img: img,
					title: title,
					zoomBtn: zoomBtn,
					closeBtn: closeBtn,
					viewsBar: viewsBar,
					prevBtn: prevBtn,
					nextBtn: nextBtn
				};
			}

			function setZoomState(zoomed) {
				var els = getModalElements();
				if (!els.dialog || !els.zoomBtn) return;
				if (zoomed) {
					els.dialog.classList.add('is-zoomed');
					els.zoomBtn.textContent = 'Zoom \u2212';
				} else {
					els.dialog.classList.remove('is-zoomed');
					els.zoomBtn.textContent = 'Zoom +';
				}
			}

			function parsePreviewsForImg(previewImg, fullSrc) {
				var list = [];
				var rawAttr = previewImg ? previewImg.getAttribute('data-mr-tee-previews') : '';
				if (rawAttr) {
					try {
						var parsed = JSON.parse(rawAttr);
						if (Array.isArray(parsed)) {
							for (var i = 0; i < parsed.length; i++) {
								if (parsed[i] && parsed[i].src) {
									list.push({
										label: String(parsed[i].label || ('Vue ' + (i + 1))),
										src: String(parsed[i].src)
									});
								}
							}
						}
					} catch (e) {}
				}
				if (list.length <= 1 && fullSrc) {
					var mapped = CART_PREVIEWS_MAP[fullSrc];
					if (!mapped) {
						try {
							var parsedPath = new URL(fullSrc, window.location.origin).pathname;
							if (parsedPath && CART_PREVIEWS_MAP[parsedPath]) {
								mapped = CART_PREVIEWS_MAP[parsedPath];
							}
						} catch (e) {}
					}
					if (Array.isArray(mapped)) {
						list = mapped.slice();
					}
				}
				if (list.length === 0 && fullSrc) {
					list.push({ label: '', src: fullSrc });
				}
				return list;
			}

			function renderActiveView() {
				var els = getModalElements();
				if (!els.img || !modalState.previews.length) return;
				var idx = modalState.activeIndex;
				if (idx < 0 || idx >= modalState.previews.length) idx = 0;
				modalState.activeIndex = idx;

				var current = modalState.previews[idx];
				var isMulti = modalState.previews.length > 1;
				setZoomState(false);

				els.img.src = current.src;
				els.img.alt = (isMulti && current.label)
					? (modalState.baseTitle + ' — ' + current.label)
					: modalState.baseTitle;

				if (els.title) {
					els.title.textContent = (isMulti && current.label)
						? (modalState.baseTitle + ' — ' + current.label)
						: modalState.baseTitle;
				}

				if (els.viewsBar) {
					if (isMulti) {
						els.viewsBar.innerHTML = '';
						for (var i = 0; i < modalState.previews.length; i++) {
							var pill = document.createElement('button');
							pill.type = 'button';
							pill.className = 'mr-tee-cart-view-pill' + (i === idx ? ' is-active' : '');
							pill.setAttribute('role', 'tab');
							pill.setAttribute('aria-selected', i === idx ? 'true' : 'false');
							pill.setAttribute('data-mr-tee-view-index', String(i));
							pill.textContent = modalState.previews[i].label || ('Vue ' + (i + 1));
							els.viewsBar.appendChild(pill);
						}
						els.viewsBar.classList.add('is-visible');
					} else {
						els.viewsBar.innerHTML = '';
						els.viewsBar.classList.remove('is-visible');
					}
				}

				if (els.prevBtn && els.nextBtn) {
					if (isMulti) {
						els.prevBtn.classList.add('is-visible');
						els.nextBtn.classList.add('is-visible');
					} else {
						els.prevBtn.classList.remove('is-visible');
						els.nextBtn.classList.remove('is-visible');
					}
				}
			}

			function stepView(delta) {
				if (modalState.previews.length <= 1) return;
				var len = modalState.previews.length;
				modalState.activeIndex = (modalState.activeIndex + delta + len) % len;
				renderActiveView();
			}

			function openPreviewModal(src, altText, previewsList, startIndex) {
				var list = (Array.isArray(previewsList) && previewsList.length > 0)
					? previewsList
					: (src ? [{ label: '', src: src }] : []);
				if (!list.length) return;
				var els = getModalElements();
				if (!els.modal || !els.img) return;

				modalState.previews = list;
				modalState.activeIndex = (typeof startIndex === 'number' && startIndex >= 0 && startIndex < list.length) ? startIndex : 0;
				modalState.baseTitle = altText || 'Aper\u00e7u de votre personnalisation';
				renderActiveView();

				els.modal.classList.add('is-open');
				els.modal.setAttribute('aria-hidden', 'false');
			}

			function closePreviewModal() {
				var els = getModalElements();
				if (!els.modal) return;
				els.modal.classList.remove('is-open');
				els.modal.setAttribute('aria-hidden', 'true');
				setZoomState(false);
			}

			function isInsideProductStage(node) {
				if (!node || !node.closest) return false;
				return Boolean(
					node.closest('#mr-tee-editor-container') ||
					node.closest('#mr-tee-gallery-root') ||
					node.closest('.woocommerce-product-gallery') ||
					node.closest('.product-gallery-slider') ||
					node.closest('.wc-block-product-gallery') ||
					node.closest('#mr-tee-cart-preview-modal')
				);
			}

			function isDedicatedThumbnailAnchor(anchor) {
				if (!anchor) return false;
				if (anchor.classList.contains('mr-tee-cart-preview-link')) return true;
				if (anchor.closest && anchor.closest(THUMB_CELL_SEL)) return true;
				var clone = anchor.cloneNode(true);
				var removable = clone.querySelectorAll('.mr-tee-cart-thumb-wrap, .mr-tee-cart-zoom-hint, .mr-tee-cart-views-badge, .screen-reader-text, img, svg');
				for (var i = 0; i < removable.length; i++) {
					if (removable[i].parentNode) removable[i].parentNode.removeChild(removable[i]);
				}
				return (clone.textContent || '').trim() === '';
			}

			function findPreviewImageFromEventTarget(target) {
				if (!target || typeof target.closest !== 'function') return null;
				if (isInsideProductStage(target)) return null;

				var directImg = target.closest(PREVIEW_SEL);
				if (directImg && !isInsideProductStage(directImg)) {
					return directImg;
				}

				var viewCard = target.closest('[data-mr-tee-view-card]');
				if (viewCard) {
					var cardImg = viewCard.querySelector(PREVIEW_SEL);
					if (cardImg) return cardImg;
				}

				var wrap = target.closest('.mr-tee-cart-thumb-wrap, [data-mr-tee-preview-wrap="1"], .mr-tee-order-preview-attachment');
				if (wrap) {
					var wrapImg = wrap.querySelector(PREVIEW_SEL);
					if (wrapImg) return wrapImg;
				}

				var anyLink = target.closest('a');
				if (anyLink && isDedicatedThumbnailAnchor(anyLink)) {
					var linkImg = anyLink.querySelector(PREVIEW_SEL);
					if (linkImg) return linkImg;
				}

				return null;
			}

			function neutralizeThumbnailLinks() {
				var imgs = document.querySelectorAll(PREVIEW_SEL);
				for (var i = 0; i < imgs.length; i++) {
					var img = imgs[i];
					if (isInsideProductStage(img)) continue;
					img.setAttribute('data-mr-tee-preview', '1');
					var parentLink = img.closest('a');
					if (!parentLink) continue;
					if (isDedicatedThumbnailAnchor(parentLink)) {
						if (parentLink.getAttribute('href') && parentLink.getAttribute('href') !== '#mr-tee-preview') {
							parentLink.setAttribute('data-mr-tee-orig-href', parentLink.getAttribute('href'));
							parentLink.setAttribute('href', '#mr-tee-preview');
						}
						parentLink.classList.add('mr-tee-cart-preview-link');
						parentLink.setAttribute('role', 'button');
						parentLink.setAttribute('aria-label', 'Agrandir l\u2019aper\u00e7u personnalis\u00e9');
					}
				}
			}

			window.addEventListener('click', function(e) {
				var target = e.target;
				if (!target) return;

				if (target.closest && target.closest('#mr-tee-cart-preview-modal')) {
					var els = getModalElements();
					if (target.closest('#mr-tee-cart-modal-close') || target === els.modal || target === els.dialog.parentElement) {
						e.preventDefault();
						closePreviewModal();
						return;
					}
					var pillBtn = target.closest('.mr-tee-cart-view-pill');
					if (pillBtn) {
						e.preventDefault();
						var targetIdx = parseInt(pillBtn.getAttribute('data-mr-tee-view-index'), 10);
						if (!isNaN(targetIdx) && targetIdx >= 0 && targetIdx < modalState.previews.length) {
							modalState.activeIndex = targetIdx;
							renderActiveView();
						}
						return;
					}
					if (target.closest('#mr-tee-cart-modal-prev')) {
						e.preventDefault();
						stepView(-1);
						return;
					}
					if (target.closest('#mr-tee-cart-modal-next')) {
						e.preventDefault();
						stepView(1);
						return;
					}
					if (target.closest('#mr-tee-cart-modal-zoom') || target.closest('#mr-tee-cart-modal-img')) {
						e.preventDefault();
						var isZoomed = els.dialog && els.dialog.classList.contains('is-zoomed');
						setZoomState(!isZoomed);
						return;
					}
					return;
				}

				var previewImg = findPreviewImageFromEventTarget(target);
				if (!previewImg) return;

				e.preventDefault();
				e.stopPropagation();
				if (typeof e.stopImmediatePropagation === 'function') {
					e.stopImmediatePropagation();
				}

				var fullSrc = previewImg.getAttribute('data-mr-tee-full-src') || previewImg.currentSrc || previewImg.getAttribute('src') || '';
				var altText = previewImg.getAttribute('alt') || 'Aper\u00e7u de votre personnalisation';
				var previewsList = parsePreviewsForImg(previewImg, fullSrc);
				var rawIdx = parseInt(previewImg.getAttribute('data-mr-tee-preview-index'), 10);
				var startIdx = !isNaN(rawIdx) ? rawIdx : 0;
				openPreviewModal(fullSrc, altText, previewsList, startIdx);
			}, true);

			window.addEventListener('keydown', function(e) {
				var modal = document.getElementById('mr-tee-cart-preview-modal');
				if (!modal || !modal.classList.contains('is-open')) return;
				if (e.key === 'Escape' || e.keyCode === 27) {
					closePreviewModal();
				} else if (e.key === 'ArrowLeft' || e.keyCode === 37) {
					if (modalState.previews.length > 1) {
						e.preventDefault();
						stepView(-1);
					}
				} else if (e.key === 'ArrowRight' || e.keyCode === 39) {
					if (modalState.previews.length > 1) {
						e.preventDefault();
						stepView(1);
					}
				}
			});

			if (document.readyState === 'loading') {
				document.addEventListener('DOMContentLoaded', neutralizeThumbnailLinks);
			} else {
				neutralizeThumbnailLinks();
			}

			if (typeof MutationObserver !== 'undefined' && document.body) {
				var obsTimer = null;
				var scheduleNeutralize = function() {
					if (obsTimer) clearTimeout(obsTimer);
					obsTimer = setTimeout(function() {
						obsTimer = null;
						neutralizeThumbnailLinks();
					}, 150);
				};
				var obs = new MutationObserver(function(mutations) {
					var relevant = false;
					for (var i = 0; i < mutations.length; i++) {
						var m = mutations[i];
						if (!m.addedNodes || !m.addedNodes.length) continue;
						var t = m.target;
						if (t && t.nodeType === 1) {
							if (t.namespaceURI === 'http://www.w3.org/2000/svg' || isInsideProductStage(t)) {
								continue;
							}
						}
						for (var j = 0; j < m.addedNodes.length; j++) {
							var added = m.addedNodes[j];
							if (added && added.nodeType === 1 && added.namespaceURI !== 'http://www.w3.org/2000/svg' && !isInsideProductStage(added)) {
								relevant = true;
								break;
							}
						}
						if (relevant) break;
					}
					if (relevant) {
						scheduleNeutralize();
					}
				});
				obs.observe(document.body, { childList: true, subtree: true });
			}
		})();
		</script>
		<?php
	}
}
