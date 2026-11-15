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
		add_filter( 'woocommerce_cart_item_thumbnail', array( __CLASS__, 'filter_cart_item_thumbnail' ), 999, 3 );
		add_filter( 'woocommerce_store_api_cart_item_images', array( __CLASS__, 'filter_store_api_cart_item_images' ), 999, 3 );
		add_filter( 'woocommerce_cart_item_name', array( __CLASS__, 'filter_checkout_item_name' ), 25, 3 );
		add_filter( 'nbd_show_design_section_in_cart', array( __CLASS__, 'filter_nbd_show_design_in_cart' ), 999, 2 );
		add_filter( 'nbd_show_edit_design_link_in_cart', array( __CLASS__, 'filter_nbd_show_edit_in_cart' ), 999, 2 );
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
			<img src="<?php echo esc_url( $preview_url ); ?>" alt="<?php esc_attr_e( 'Aperçu du produit personnalisé', 'mr-tee-editor' ); ?>" class="skip-lazy" style="width:72px;height:auto;max-height:96px;object-fit:contain;border-radius:6px;border:1px solid #cbd5e1;background:#ffffff;display:block;flex-shrink:0;" />
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
					'value'   => $count,
					'display' => $count,
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
			if ( method_exists( $item, 'save' ) && $item->get_id() ) {
				$item->save();
			}

			// Ensure order-level postmeta and WC Order meta _nbd = 1 is set so NBDesigner metabox displays
			if ( $order && is_a( $order, '\WC_Order' ) ) {
				$order->update_meta_data( '_nbd', '1' );
				update_post_meta( $order->get_id(), '_nbd', '1' );
				$order->save();
			}
		}
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
			$preview_url = self::normalize_url( $cart_item['mr_tee_editor']['preview_url'] );
			$alt         = ( ! empty( $cart_item['data'] ) && method_exists( $cart_item['data'], 'get_name' ) )
				? sprintf( __( 'Aperçu personnalisé — %s', 'mr-tee-editor' ), $cart_item['data']->get_name() )
				: __( 'Aperçu du produit personnalisé', 'mr-tee-editor' );
			return sprintf(
				'<img src="%s" class="attachment-woocommerce_thumbnail size-woocommerce_thumbnail mr-tee-cart-thumb skip-lazy" alt="%s" style="width:80px;max-width:100%%;height:auto;max-height:110px;object-fit:contain;border-radius:6px;border:1px solid #e2e8f0;background:#ffffff;display:block;" />',
				esc_url( $preview_url ),
				esc_attr( $alt )
			);
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
		if ( empty( $preview_url ) || ! filter_var( $preview_url, FILTER_VALIDATE_URL ) ) {
			return $product_images;
		}

		$attachment_id = ! empty( $cart_item['product_id'] ) ? (int) get_post_thumbnail_id( $cart_item['product_id'] ) : 0;
		if ( $attachment_id <= 0 ) {
			$attachment_id = 1;
		}

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
	 * Replace Shoptimizer's checkout review thumbnail with the composite preview image.
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
			'<img src="%s" alt="%s" class="skip-lazy mr-tee-checkout-thumb" style="width:60px;height:auto;max-height:80px;object-fit:contain;border-radius:6px;border:1px solid #e2e8f0;background:#ffffff;" />',
			esc_url( $preview_url ),
			esc_attr__( 'Aperçu du produit personnalisé', 'mr-tee-editor' )
		);

		if ( false !== strpos( $product_name, 'product-item-thumbnail' ) ) {
			$replaced = preg_replace(
				'#(<div class="product-item-thumbnail">).*?(</div>)#s',
				'$1' . $thumb_html . '$2',
				$product_name,
				1
			);
			if ( is_string( $replaced ) && '' !== $replaced ) {
				return $replaced;
			}
		}

		if ( false === strpos( $product_name, 'mr-tee-checkout-thumb' ) ) {
			return '<div class="mr-tee-checkout-thumb-wrap" style="display:inline-flex;align-items:center;gap:10px;vertical-align:middle;"><span style="flex-shrink:0;line-height:0;">' . $thumb_html . '</span><span>' . $product_name . '</span></div>';
		}

		return $product_name;
	}
}
