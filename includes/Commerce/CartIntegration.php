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
	 * Normalize same-site asset URLs to match the active HTTP/HTTPS request scheme
	 * so browsers never hit mixed-content blocks or cross-scheme failures.
	 *
	 * @param string $url Raw URL.
	 * @return string
	 */
	private static function normalize_url( $url ) {
		if ( empty( $url ) || ! is_string( $url ) ) {
			return '';
		}
		if ( 0 !== strpos( $url, 'http' ) ) {
			return $url;
		}
		$site_host = wp_parse_url( home_url(), PHP_URL_HOST );
		$http_host = isset( $_SERVER['HTTP_HOST'] ) ? preg_replace( '/:\d+$/', '', sanitize_text_field( wp_unslash( $_SERVER['HTTP_HOST'] ) ) ) : '';
		$url_host  = wp_parse_url( $url, PHP_URL_HOST );

		if ( $url_host && ( $url_host === $site_host || ( $http_host && $url_host === $http_host ) ) ) {
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
			$product_id = (int) $adding_to_cart->get_id();
			if ( method_exists( $adding_to_cart, 'is_type' ) && $adding_to_cart->is_type( 'variation' ) && method_exists( $adding_to_cart, 'get_parent_id' ) ) {
				$parent_id = (int) $adding_to_cart->get_parent_id();
				if ( $parent_id > 0 ) {
					$product_id = $parent_id;
				}
			}
		} elseif ( ! empty( $_REQUEST['add-to-cart'] ) && is_numeric( $_REQUEST['add-to-cart'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$product_id = absint( wp_unslash( $_REQUEST['add-to-cart'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		}

		$is_mr_tee = false;
		if ( $product_id > 0 && self::is_mr_tee_product( $product_id ) ) {
			$is_mr_tee = true;
		} elseif ( ! empty( $_POST['mr_tee_editor_payload'] ) || ! empty( $_POST['mr_tee_design_key'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing
			$is_mr_tee = true;
		}

		if ( $is_mr_tee && function_exists( 'wc_get_cart_url' ) ) {
			$cart_url = wc_get_cart_url();
			if ( ! empty( $cart_url ) ) {
				return self::normalize_url( $cart_url );
			}
		}

		return $url;
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
		if ( ! self::is_mr_tee_product( $product_id ) && empty( $_POST['mr_tee_editor_payload'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing
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

		$config    = SceneGraphSchema::get_product_config( $product_id );
		$crop_w    = ! empty( $payload['crop_print_width'] ) ? floatval( $payload['crop_print_width'] ) : ( isset( $config['crop_print_width'] ) ? $config['crop_print_width'] : ( isset( $config['print_real_width'] ) ? $config['print_real_width'] : 30.0 ) );
		$crop_h    = ! empty( $payload['crop_print_height'] ) ? floatval( $payload['crop_print_height'] ) : ( isset( $config['crop_print_height'] ) ? $config['crop_print_height'] : ( isset( $config['print_real_height'] ) ? $config['print_real_height'] : 40.0 ) );
		$crop_px_w = ! empty( $payload['crop_width_px'] ) ? intval( $payload['crop_width_px'] ) : ( isset( $config['print_width_px'] ) ? $config['print_width_px'] : 2400 );
		$crop_px_h = ! empty( $payload['crop_height_px'] ) ? intval( $payload['crop_height_px'] ) : ( isset( $config['print_height_px'] ) ? $config['print_height_px'] : 3000 );
		$unit      = ! empty( $payload['print_unit'] ) ? $payload['print_unit'] : ( isset( $config['print_unit'] ) ? $config['print_unit'] : 'cm' );
		$dpi       = ! empty( $payload['dpi'] ) ? intval( $payload['dpi'] ) : ( isset( $config['dpi'] ) ? $config['dpi'] : 300 );
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

		$cart_item_data['mr_tee_editor'] = array(
			'key'                => $design_key,
			'payload'            => $payload,
			'print_dimensions'   => $dims_summary,
			'print_real_width'   => $crop_w,
			'print_real_height'  => $crop_h,
			'print_width_px'     => $crop_px_w,
			'print_height_px'    => $crop_px_h,
			'ref_real_width'     => isset( $config['print_real_width'] ) ? $config['print_real_width'] : 30.0,
			'ref_real_height'    => isset( $config['print_real_height'] ) ? $config['print_real_height'] : 40.0,
			'print_unit'         => $unit,
			'print_aspect_ratio' => $crop_ratio,
			'product_type'       => isset( $config['product_type'] ) ? $config['product_type'] : 'garment',
			'repeater_enabled'   => ! empty( $config['repeater']['enabled'] ),
			'preview_url'        => $preview_res ? $preview_res['url'] : '',
			'preview_file'       => $preview_res ? $preview_res['file'] : '',
			'svg_url'            => '',
			'svg_file'           => '',
			'print_url'          => '',
			'print_file'         => '',
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
				)
			);
			$cart_item_data['mr_tee_editor']['nbd'] = $nbd_meta;
		}

		$cart_item_data['unique_key'] = $design_key;

		return $cart_item_data;
	}

	/**
	 * Render non-clickable composite preview thumbnail on Customer Order Confirmation ("Thank You"),
	 * View Order, and Order Email views without any file download links.
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
		$data        = $item->get_meta( '_mr_tee_editor_data', true );
		$raw_preview = ! empty( $data['preview_url'] ) ? $data['preview_url'] : $item->get_meta( '_mr_tee_preview_url', true );
		if ( empty( $raw_preview ) ) {
			return;
		}
		$preview_url = self::normalize_url( $raw_preview );
		if ( empty( $preview_url ) ) {
			return;
		}
		?>
		<div class="mr-tee-order-preview-attachment" style="margin:8px 0 6px;display:flex;align-items:center;gap:10px;flex-wrap:wrap;padding:6px 8px;background:#f8fafc;border:1px solid #cbd5e1;border-radius:8px;max-width:340px;">
			<img src="<?php echo esc_url( $preview_url ); ?>" data-mr-tee-preview="1" data-mr-tee-full-src="<?php echo esc_url( $preview_url ); ?>" alt="<?php esc_attr_e( 'Aperçu du produit personnalisé', 'mr-tee-editor' ); ?>" class="skip-lazy mr-tee-cart-thumb" style="width:72px;height:auto;max-height:96px;object-fit:contain;border-radius:6px;border:1px solid #cbd5e1;background:#ffffff;display:block;flex-shrink:0;cursor:zoom-in;" />
			<div style="flex:1 1 130px;min-width:0;font-size:12px;line-height:1.35;">
				<span style="display:block;font-weight:700;color:#0f172a;"><?php esc_html_e( 'Aperçu personnalisé', 'mr-tee-editor' ); ?></span>
			</div>
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
		if ( empty( $cart_item['mr_tee_editor'] ) ) {
			return $item_data;
		}

		$data    = $cart_item['mr_tee_editor'];
		$payload = $data['payload'];
		$icons   = IconSetRegistry::get_mushroom_icons();

		// Text & Image fields
		if ( ! empty( $payload['text_lines'] ) && is_array( $payload['text_lines'] ) ) {
			foreach ( $payload['text_lines'] as $line ) {
				$is_image = isset( $line['fieldType'] ) && 'image' === $line['fieldType'];
				$label    = ! empty( $line['label'] ) ? $line['label'] : ( $is_image ? 'Image' : 'Texte' );
				$val      = ! empty( $line['value'] ) ? $line['value'] : '';
				$rot      = isset( $line['rotate'] ) ? floatval( $line['rotate'] ) : ( isset( $line['box_rotate'] ) ? floatval( $line['box_rotate'] ) : 0.0 );

				if ( $is_image ) {
					$img_url     = ! empty( $line['imageUrl'] ) ? $line['imageUrl'] : '';
					$img_summary = ! empty( $val ) ? $val : ( ! empty( $img_url ) ? __( 'Image', 'mr-tee-editor' ) : '' );
					if ( ! empty( $img_summary ) ) {
						$item_data[] = array(
							'key'     => $label,
							'value'   => esc_html( $img_summary ),
							'display' => esc_html( $img_summary ),
						);
					}
					continue;
				}

				if ( ! empty( $val ) ) {
					$rot_label = '';
					if ( 0.0 !== $rot ) {
						if ( 90.0 === $rot || -90.0 === $rot ) {
							$rot_label = ' ' . sprintf( __( '(Vertical %d°)', 'mr-tee-editor' ), $rot );
						} else {
							$rot_label = ' ' . sprintf( __( '(%d°)', 'mr-tee-editor' ), $rot );
						}
					}
					$display_val = esc_html( $val ) . ( ! empty( $rot_label ) ? ' <small style="color:#64748b; font-weight:normal;">' . esc_html( $rot_label ) . '</small>' : '' );
					$item_data[] = array(
						'key'     => $label,
						'value'   => esc_html( $val . $rot_label ),
						'display' => $display_val,
					);
				}
			}
		}

		// Repeater items (Mushrooms)
		if ( isset( $payload['repeater_count'] ) ) {
			$count            = intval( $payload['repeater_count'] );
			$repeater_enabled = array_key_exists( 'repeater_enabled', $data ) ? ! empty( $data['repeater_enabled'] ) : ( $count > 0 );
			if ( $repeater_enabled || $count > 0 ) {
				$item_data[] = array(
					'key'     => __( 'Nombre de champignons', 'mr-tee-editor' ),
					'value'   => (string) $count,
					'display' => 0 === $count ? '0&nbsp;' : (string) $count,
				);

				if ( ! empty( $payload['repeater_items'] ) && is_array( $payload['repeater_items'] ) ) {
					$mushroom_summary = array();
					foreach ( $payload['repeater_items'] as $idx => $m_item ) {
						if ( $idx >= $count ) {
							break;
						}
						$name     = ! empty( $m_item['name'] ) ? $m_item['name'] : 'Sans nom';
						$ic_key   = ! empty( $m_item['icon'] ) ? $m_item['icon'] : 'mushroom_red';
						$ic_name  = isset( $icons[ $ic_key ] ) ? $icons[ $ic_key ]['name'] : $ic_key;
						$mushroom_summary[] = sprintf( '%s (%s)', $name, $ic_name );
					}
					if ( ! empty( $mushroom_summary ) ) {
						$item_data[] = array(
							'key'     => __( 'Détail des champignons', 'mr-tee-editor' ),
							'value'   => implode( ', ', $mushroom_summary ),
							'display' => implode( '<br>', array_map( 'esc_html', $mushroom_summary ) ),
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
		if ( empty( $values['mr_tee_editor'] ) ) {
			return;
		}

		$data    = $values['mr_tee_editor'];
		$payload = $data['payload'];
		$icons   = IconSetRegistry::get_mushroom_icons();

		$item->add_meta_data( '_mr_tee_editor_data', $data, true );

		// Text & Image fields readable meta (no raw file URLs exposed on customer order/email)
		if ( ! empty( $payload['text_lines'] ) && is_array( $payload['text_lines'] ) ) {
			foreach ( $payload['text_lines'] as $line ) {
				$is_image = isset( $line['fieldType'] ) && 'image' === $line['fieldType'];
				$label    = ! empty( $line['label'] ) ? $line['label'] : ( $is_image ? 'Image' : 'Texte' );
				$val      = ! empty( $line['value'] ) ? $line['value'] : '';
				if ( $is_image ) {
					$img_url = ! empty( $line['imageUrl'] ) ? $line['imageUrl'] : '';
					$summary = ! empty( $val ) ? $val : ( ! empty( $img_url ) ? __( 'Image', 'mr-tee-editor' ) : '' );
					if ( ! empty( $summary ) ) {
						$item->add_meta_data( $label, $summary, true );
					}
					continue;
				}
				if ( ! empty( $val ) ) {
					$item->add_meta_data( $label, $val, true );
				}
			}
		}

		// Repeater summary readable meta
		if ( isset( $payload['repeater_count'] ) ) {
			$count            = intval( $payload['repeater_count'] );
			$repeater_enabled = array_key_exists( 'repeater_enabled', $data ) ? ! empty( $data['repeater_enabled'] ) : ( $count > 0 );
			if ( $repeater_enabled || $count > 0 ) {
				$item->add_meta_data( __( 'Nombre de champignons', 'mr-tee-editor' ), $count, true );

				if ( ! empty( $payload['repeater_items'] ) && is_array( $payload['repeater_items'] ) ) {
					$summary = array();
					foreach ( $payload['repeater_items'] as $idx => $m_item ) {
						if ( $idx >= $count ) {
							break;
						}
						$name     = ! empty( $m_item['name'] ) ? $m_item['name'] : 'Sans nom';
						$ic_key   = ! empty( $m_item['icon'] ) ? $m_item['icon'] : 'mushroom_red';
						$ic_name  = isset( $icons[ $ic_key ] ) ? $icons[ $ic_key ]['name'] : $ic_key;
						$summary[] = sprintf( '%d. %s (%s)', $idx + 1, $name, $ic_name );
					}
					$item->add_meta_data( __( 'Détail des champignons', 'mr-tee-editor' ), implode( "\n", $summary ), true );
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
		if ( ! empty( $data['svg_url'] ) ) {
			$item->add_meta_data( '_mr_tee_svg_url', $data['svg_url'], true );
		}
		if ( ! empty( $data['print_url'] ) ) {
			$item->add_meta_data( '_mr_tee_print_url', $data['print_url'], true );
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
	 * Attach in-memory preview URL metadata onto the cart item's WC_Product instance
	 * so themes/side-carts that call $_product->get_image() directly without
	 * woocommerce_cart_item_thumbnail still render the personalized preview.
	 *
	 * @param array<string, mixed> $cart_item Cart item array.
	 * @return array<string, mixed>
	 */
	public static function attach_preview_to_cart_product( $cart_item ) {
		if ( ! empty( $cart_item['mr_tee_editor']['preview_url'] ) && ! empty( $cart_item['data'] ) && is_object( $cart_item['data'] ) && method_exists( $cart_item['data'], 'update_meta_data' ) ) {
			$cart_item['data']->update_meta_data( '_mr_tee_cart_preview_url', $cart_item['mr_tee_editor']['preview_url'] );
		}
		return $cart_item;
	}

	/**
	 * Restore in-memory preview URL metadata onto the WC_Product instance when cart loads from session.
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
	 * @param string $raw_preview_url Raw preview URL.
	 * @param string $product_name    Optional product name for alt text.
	 * @return string
	 */
	private static function build_cart_thumb_html( $raw_preview_url, $product_name = '' ) {
		$preview_url = self::normalize_url( $raw_preview_url );
		if ( empty( $preview_url ) ) {
			return '';
		}
		$alt = ! empty( $product_name )
			? sprintf( __( 'Aperçu personnalisé — %s', 'mr-tee-editor' ), $product_name )
			: __( 'Aperçu du produit personnalisé', 'mr-tee-editor' );

		return sprintf(
			'<span class="mr-tee-cart-thumb-wrap" data-mr-tee-preview-wrap="1" title="%s"><img src="%s" data-mr-tee-preview="1" data-mr-tee-full-src="%s" class="attachment-woocommerce_thumbnail size-woocommerce_thumbnail mr-tee-cart-thumb skip-lazy" alt="%s" style="width:80px;max-width:100%%;height:auto;max-height:110px;object-fit:contain;border-radius:6px;border:1px solid #e2e8f0;background:#ffffff;display:block;cursor:zoom-in;" /><span class="mr-tee-cart-zoom-hint" aria-hidden="true">+</span></span>',
			esc_attr__( 'Cliquer pour agrandir votre aperçu personnalisé', 'mr-tee-editor' ),
			esc_url( $preview_url ),
			esc_url( $preview_url ),
			esc_attr( $alt )
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
		if ( ! is_object( $product ) || ! method_exists( $product, 'get_meta' ) ) {
			return $image;
		}
		$preview_url = $product->get_meta( '_mr_tee_cart_preview_url', true );
		if ( empty( $preview_url ) ) {
			return $image;
		}
		$name = method_exists( $product, 'get_name' ) ? $product->get_name() : '';
		$html = self::build_cart_thumb_html( $preview_url, $name );
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
			$name = ( ! empty( $cart_item['data'] ) && method_exists( $cart_item['data'], 'get_name' ) )
				? $cart_item['data']->get_name()
				: '';
			$html = self::build_cart_thumb_html( $cart_item['mr_tee_editor']['preview_url'], $name );
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
	 * Render or replace checkout review item thumbnail with the composite preview image across all themes.
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
		if ( empty( $cart_item['mr_tee_editor']['preview_url'] ) ) {
			return $product_name;
		}
		$preview_url = self::normalize_url( $cart_item['mr_tee_editor']['preview_url'] );
		if ( empty( $preview_url ) ) {
			return $product_name;
		}

		$thumb_html = sprintf(
			'<img src="%s" data-mr-tee-preview="1" data-mr-tee-full-src="%s" alt="%s" class="skip-lazy mr-tee-checkout-thumb" style="width:60px;height:auto;max-height:80px;object-fit:contain;border-radius:6px;border:1px solid #e2e8f0;background:#ffffff;cursor:zoom-in;" />',
			esc_url( $preview_url ),
			esc_url( $preview_url ),
			esc_attr__( 'Aperçu du produit personnalisé', 'mr-tee-editor' )
		);

		if ( false !== strpos( $product_name, 'product-item-thumbnail' ) ) {
			$replaced = preg_replace(
				'#(<div\b[^>]*class="[^"]*\bproduct-item-thumbnail\b[^"]*"[^>]*>).*?(</div>)#s',
				'$1' . $thumb_html . '$2',
				$product_name,
				1
			);
			if ( is_string( $replaced ) && '' !== $replaced ) {
				return $replaced;
			}
		}

		if ( false === strpos( $product_name, 'mr-tee-checkout-thumb' ) && preg_match( '#<img\b[^>]*>#i', $product_name ) ) {
			$replaced_img = preg_replace( '#<img\b[^>]*>#i', $thumb_html, $product_name, 1 );
			if ( is_string( $replaced_img ) && '' !== $replaced_img ) {
				return $replaced_img;
			}
		}

		if ( false === strpos( $product_name, 'mr-tee-checkout-thumb' ) ) {
			return '<div class="mr-tee-checkout-thumb-wrap" style="display:inline-flex;align-items:center;gap:10px;vertical-align:middle;"><span style="flex-shrink:0;line-height:0;">' . $thumb_html . '</span><span>' . $product_name . '</span></div>';
		}

		return $product_name;
	}

	/**
	 * Output universal Cart / Checkout / Order Preview Lightbox Modal and capture-phase click interceptor
	 * so clicking a personalized product thumbnail on any WordPress theme opens a large preview dialog
	 * instead of navigating away to the blank product page.
	 */
	public static function render_cart_preview_lightbox() {
		if ( is_admin() ) {
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
				max-width: 680px !important;
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
			.mr-tee-cart-modal-body {
				flex: 1 1 auto !important;
				overflow: auto !important;
				display: flex !important;
				align-items: center !important;
				justify-content: center !important;
				background: #f1f5f9 !important;
				padding: 16px !important;
				min-height: 240px !important;
				max-height: calc(92vh - 62px) !important;
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
				max-height: calc(85vh - 96px) !important;
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
				<div class="mr-tee-cart-modal-body" id="mr-tee-cart-modal-body">
					<img src="" alt="<?php esc_attr_e( 'Aperçu du produit personnalisé', 'mr-tee-editor' ); ?>" class="mr-tee-cart-modal-img skip-lazy" id="mr-tee-cart-modal-img" />
				</div>
			</div>
		</div>
		<script id="mr-tee-cart-preview-lightbox-js">
		(function() {
			var PREVIEW_SEL = 'img.mr-tee-cart-thumb, img.mr-tee-checkout-thumb, .mr-tee-order-preview-attachment img, img[data-mr-tee-preview="1"], img[src*="mr_tee_editor/designs/"]';
			var THUMB_CELL_SEL = 'td.product-thumbnail, .product-thumbnail, .wc-block-cart-item__image, .wc-block-components-order-summary-item__image, .cart-item-thumbnail, .cart-item-image, .ast-cart-item-thumbnail, .wd-cart-item-img';

			function getModalElements() {
				var modal = document.getElementById('mr-tee-cart-preview-modal');
				var dialog = document.getElementById('mr-tee-cart-modal-dialog');
				var img = document.getElementById('mr-tee-cart-modal-img');
				var title = document.getElementById('mr-tee-cart-modal-title');
				var zoomBtn = document.getElementById('mr-tee-cart-modal-zoom');
				var closeBtn = document.getElementById('mr-tee-cart-modal-close');
				if (modal && modal.parentElement !== document.body) {
					document.body.appendChild(modal);
				}
				return { modal: modal, dialog: dialog, img: img, title: title, zoomBtn: zoomBtn, closeBtn: closeBtn };
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

			function openPreviewModal(src, altText) {
				if (!src) return;
				var els = getModalElements();
				if (!els.modal || !els.img) return;
				setZoomState(false);
				els.img.src = src;
				els.img.alt = altText || 'Aper\u00e7u de votre personnalisation';
				if (els.title) {
					els.title.textContent = altText || 'Aper\u00e7u de votre personnalisation';
				}
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
				var removable = clone.querySelectorAll('.mr-tee-cart-thumb-wrap, .mr-tee-cart-zoom-hint, .screen-reader-text, img, svg');
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
				openPreviewModal(fullSrc, altText);
			}, true);

			window.addEventListener('keydown', function(e) {
				if (e.key === 'Escape' || e.keyCode === 27) {
					var modal = document.getElementById('mr-tee-cart-preview-modal');
					if (modal && modal.classList.contains('is-open')) {
						closePreviewModal();
					}
				}
			});

			if (document.readyState === 'loading') {
				document.addEventListener('DOMContentLoaded', neutralizeThumbnailLinks);
			} else {
				neutralizeThumbnailLinks();
			}

			if (typeof MutationObserver !== 'undefined' && document.body) {
				var obs = new MutationObserver(function() {
					neutralizeThumbnailLinks();
				});
				obs.observe(document.body, { childList: true, subtree: true });
			}
		})();
		</script>
		<?php
	}
}
