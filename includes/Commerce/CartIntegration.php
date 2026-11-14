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
	 * Register hooks.
	 */
	public static function init() {
		add_filter( 'woocommerce_add_cart_item_data', array( __CLASS__, 'capture_cart_item_data' ), 10, 3 );
		add_filter( 'woocommerce_add_to_cart_redirect', array( __CLASS__, 'filter_add_to_cart_redirect' ), 99999, 2 );
		add_filter( 'woocommerce_get_item_data', array( __CLASS__, 'display_cart_item_data' ), 10, 2 );
		add_action( 'woocommerce_after_cart_item_name', array( __CLASS__, 'render_cart_item_preview_attachment' ), 20, 2 );
		add_action( 'woocommerce_checkout_create_order_line_item', array( __CLASS__, 'save_order_line_item_data' ), 10, 4 );
		add_filter( 'woocommerce_cart_item_thumbnail', array( __CLASS__, 'filter_cart_item_thumbnail' ), 999, 3 );
		add_filter( 'woocommerce_store_api_cart_item_images', array( __CLASS__, 'filter_store_api_cart_item_images' ), 999, 3 );
		add_filter( 'woocommerce_cart_item_name', array( __CLASS__, 'filter_checkout_item_name' ), 25, 3 );
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
		if ( $product_id > 0 && 'mr_tee_editor' === get_post_meta( $product_id, '_mr_tee_engine_mode', true ) ) {
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
	 * Capture personalization payload when item is added to cart.
	 *
	 * @param array<string, mixed> $cart_item_data Existing item data.
	 * @param int                  $product_id     Product ID.
	 * @param int                  $variation_id   Variation ID.
	 * @return array<string, mixed>
	 */
	public static function capture_cart_item_data( $cart_item_data, $product_id, $variation_id ) {
		$engine = get_post_meta( $product_id, '_mr_tee_engine_mode', true );
		if ( 'mr_tee_editor' !== $engine ) {
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

		$posted_key = isset( $_POST['mr_tee_design_key'] ) ? preg_replace( '/[^a-zA-Z0-9_-]/', '', wp_unslash( $_POST['mr_tee_design_key'] ) ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized,WordPress.Security.NonceVerification.Missing
		$design_key = preg_match( '/^mrtee_[a-zA-Z0-9_-]{8,64}$/', $posted_key ) ? $posted_key : ( 'mrtee_' . wp_generate_uuid4() );
		$preview_png  = isset( $_POST['mr_tee_preview_png'] ) ? wp_unslash( $_POST['mr_tee_preview_png'] ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized,WordPress.Security.NonceVerification.Missing
		$vector_svg   = isset( $_POST['mr_tee_vector_svg'] ) ? wp_unslash( $_POST['mr_tee_vector_svg'] ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized,WordPress.Security.NonceVerification.Missing
		$print_300dpi = isset( $_POST['mr_tee_print_300dpi_png'] ) ? wp_unslash( $_POST['mr_tee_print_300dpi_png'] ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized,WordPress.Security.NonceVerification.Missing

		// Save inline files to disk, or reuse pre-uploaded chunked artifacts already stored under designs/{design_key}
		$sub_dir = 'designs/' . $design_key;
		$paths   = PrintAssetGenerator::get_upload_dir( $sub_dir );

		$preview_res = false;
		if ( ! empty( $preview_png ) ) {
			$is_jpeg     = ( strpos( $preview_png, 'image/jpeg' ) !== false || strpos( $preview_png, 'image/jpg' ) !== false );
			$preview_ext = $is_jpeg ? 'design_preview.jpg' : 'design_preview.png';
			$preview_res = PrintAssetGenerator::save_base64_png( $preview_png, $sub_dir, $preview_ext );
			if ( $preview_res && $is_jpeg ) {
				@copy( $preview_res['file'], dirname( $preview_res['file'] ) . '/design_preview.png' );
			}
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

		$svg_res = false;
		if ( ! empty( $vector_svg ) ) {
			$svg_res = PrintAssetGenerator::save_svg( $vector_svg, $sub_dir, 'design.svg' );
		} else {
			$svg_file = trailingslashit( $paths['dir'] ) . 'design.svg';
			if ( file_exists( $svg_file ) && filesize( $svg_file ) > 0 ) {
				$svg_res = array(
					'file' => $svg_file,
					'url'  => trailingslashit( $paths['url'] ) . 'design.svg',
				);
			}
		}

		$print_res = false;
		if ( ! empty( $print_300dpi ) ) {
			$print_res = PrintAssetGenerator::save_base64_png( $print_300dpi, $sub_dir, 'print_300dpi.png' );
		} else {
			$print_file = trailingslashit( $paths['dir'] ) . 'print_300dpi.png';
			if ( file_exists( $print_file ) && filesize( $print_file ) > 0 ) {
				$print_res = array(
					'file' => $print_file,
					'url'  => trailingslashit( $paths['url'] ) . 'print_300dpi.png',
				);
			}
		}

		$config       = SceneGraphSchema::get_product_config( $product_id );
		$is_flat      = isset( $config['product_type'] ) && 'flat_object' === $config['product_type'];
		$crop_w       = ! empty( $payload['crop_print_width'] ) ? floatval( $payload['crop_print_width'] ) : ( isset( $config['crop_print_width'] ) ? $config['crop_print_width'] : ( isset( $config['print_real_width'] ) ? $config['print_real_width'] : 30.0 ) );
		$crop_h       = ! empty( $payload['crop_print_height'] ) ? floatval( $payload['crop_print_height'] ) : ( isset( $config['crop_print_height'] ) ? $config['crop_print_height'] : ( isset( $config['print_real_height'] ) ? $config['print_real_height'] : 40.0 ) );
		$crop_px_w    = ! empty( $payload['crop_width_px'] ) ? intval( $payload['crop_width_px'] ) : ( isset( $config['print_width_px'] ) ? $config['print_width_px'] : 2400 );
		$crop_px_h    = ! empty( $payload['crop_height_px'] ) ? intval( $payload['crop_height_px'] ) : ( isset( $config['print_height_px'] ) ? $config['print_height_px'] : 3000 );
		$unit         = ! empty( $payload['print_unit'] ) ? $payload['print_unit'] : ( isset( $config['print_unit'] ) ? $config['print_unit'] : 'cm' );
		$dpi          = ! empty( $payload['dpi'] ) ? intval( $payload['dpi'] ) : ( isset( $config['dpi'] ) ? $config['dpi'] : 300 );
		$crop_ratio   = ( $crop_h > 0 ) ? round( $crop_w / $crop_h, 3 ) : 1.0;

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
			'svg_url'            => $svg_res ? $svg_res['url'] : '',
			'svg_file'           => $svg_res ? $svg_res['file'] : '',
			'print_url'          => $print_res ? $print_res['url'] : '',
			'print_file'         => $print_res ? $print_res['file'] : '',
		);

		// Handle NBDesigner Handshake if enabled or if NBDesigner plugin is active
		$should_handshake = isset( $config['nbd_handshake'] ) ? ! empty( $config['nbd_handshake'] ) : NBDesignerBridge::is_nbd_active();
		if ( $should_handshake && NBDesignerBridge::is_nbd_active() ) {
			$nbd_meta = NBDesignerBridge::inject_nbd_artifacts(
				$design_key,
				$payload,
				$preview_png,
				$vector_svg,
				$print_300dpi,
				$product_id,
				array(
					'preview_file' => $preview_res ? $preview_res['file'] : '',
					'svg_file'     => $svg_res ? $svg_res['file'] : '',
					'print_file'   => $print_res ? $print_res['file'] : '',
				)
			);
			$cart_item_data['mr_tee_editor']['nbd'] = $nbd_meta;
		}

		$cart_item_data['unique_key'] = $design_key;

		return $cart_item_data;
	}

	/**
	 * Render a clear composite preview attachment box directly inside the cart item details
	 * so desktop and mobile shoppers see the whole customized product (mockup + design)
	 * even when the theme shrinks or hides `.product-thumbnail`.
	 *
	 * @param array<string, mixed> $cart_item     Cart item array.
	 * @param string               $cart_item_key Cart item key.
	 */
	public static function render_cart_item_preview_attachment( $cart_item, $cart_item_key ) {
		if ( empty( $cart_item['mr_tee_editor']['preview_url'] ) ) {
			return;
		}
		$preview_url = self::normalize_url( $cart_item['mr_tee_editor']['preview_url'] );
		if ( empty( $preview_url ) ) {
			return;
		}
		?>
		<div class="mr-tee-cart-preview-attachment" style="margin:8px 0 6px;display:flex;align-items:center;gap:10px;flex-wrap:wrap;padding:6px 8px;background:#f8fafc;border:1px solid #cbd5e1;border-radius:8px;max-width:340px;">
			<a href="<?php echo esc_url( $preview_url ); ?>" target="_blank" rel="noopener noreferrer" class="mr-tee-cart-preview-thumb-link" style="display:block;flex-shrink:0;line-height:0;">
				<img src="<?php echo esc_url( $preview_url ); ?>" alt="<?php esc_attr_e( 'Aperçu du produit personnalisé', 'mr-tee-editor' ); ?>" class="mr-tee-cart-attachment-img skip-lazy" style="width:76px;height:auto;max-height:100px;object-fit:contain;border-radius:6px;border:1px solid #cbd5e1;background:#ffffff;display:block;" />
			</a>
			<div class="mr-tee-cart-preview-meta" style="flex:1 1 130px;min-width:0;font-size:12px;line-height:1.35;">
				<span style="display:block;font-weight:700;color:#0f172a;margin-bottom:2px;"><?php esc_html_e( 'Aperçu personnalisé', 'mr-tee-editor' ); ?></span>
				<a href="<?php echo esc_url( $preview_url ); ?>" target="_blank" rel="noopener noreferrer" style="color:#154694;font-weight:600;text-decoration:underline;"><?php esc_html_e( 'Voir l\'image complète', 'mr-tee-editor' ); ?></a>
			</div>
		</div>
		<?php
	}

	/**
	 * Display formatted customization details in cart and checkout.
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

		// Physical Print Dimensions
		if ( ! empty( $data['print_dimensions'] ) ) {
			$item_data[] = array(
				'key'     => __( "Dimensions d'impression", 'mr-tee-editor' ),
				'value'   => esc_html( $data['print_dimensions'] ),
				'display' => esc_html( $data['print_dimensions'] ),
			);
		}

		// Text & Image fields
		if ( ! empty( $payload['text_lines'] ) && is_array( $payload['text_lines'] ) ) {
			foreach ( $payload['text_lines'] as $line ) {
				$is_image = isset( $line['fieldType'] ) && 'image' === $line['fieldType'];
				$label    = ! empty( $line['label'] ) ? $line['label'] : ( $is_image ? 'Image' : 'Texte' );
				$val      = ! empty( $line['value'] ) ? $line['value'] : '';
				$rot      = isset( $line['rotate'] ) ? floatval( $line['rotate'] ) : ( isset( $line['box_rotate'] ) ? floatval( $line['box_rotate'] ) : 0.0 );

				if ( $is_image ) {
					$img_url     = ! empty( $line['imageUrl'] ) ? self::normalize_url( $line['imageUrl'] ) : '';
					$img_summary = ! empty( $val ) ? $val : ( ! empty( $img_url ) ? __( 'Image', 'mr-tee-editor' ) : '' );
					if ( ! empty( $img_summary ) ) {
						$display_html = esc_html( $img_summary );
						if ( ! empty( $img_url ) && 0 === strpos( $img_url, 'http' ) ) {
							$display_html .= ' — <a href="' . esc_url( $img_url ) . '" target="_blank" rel="noopener noreferrer">' . esc_html__( 'Voir', 'mr-tee-editor' ) . '</a>';
						}
						$item_data[] = array(
							'key'     => $label,
							'value'   => esc_html( $img_summary ),
							'display' => $display_html,
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

		// Text & Image fields readable meta
		if ( ! empty( $payload['text_lines'] ) && is_array( $payload['text_lines'] ) ) {
			foreach ( $payload['text_lines'] as $line ) {
				$is_image = isset( $line['fieldType'] ) && 'image' === $line['fieldType'];
				$label    = ! empty( $line['label'] ) ? $line['label'] : ( $is_image ? 'Image' : 'Texte' );
				$val      = ! empty( $line['value'] ) ? $line['value'] : '';
				if ( $is_image ) {
					$img_url = ! empty( $line['imageUrl'] ) ? $line['imageUrl'] : '';
					$summary = ! empty( $val ) ? $val : '';
					if ( ! empty( $img_url ) && 0 === strpos( $img_url, 'http' ) ) {
						$summary = trim( $summary . ' — ' . esc_url_raw( $img_url ) );
					}
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

		// Physical Print Dimensions & File URLs
		if ( ! empty( $data['print_dimensions'] ) ) {
			$item->add_meta_data( __( "Dimensions d'impression", 'mr-tee-editor' ), $data['print_dimensions'], true );
		}
		if ( ! empty( $data['preview_url'] ) ) {
			$item->add_meta_data( '_mr_tee_preview_url', $data['preview_url'], true );
		}
		if ( ! empty( $data['svg_url'] ) ) {
			$item->add_meta_data( '_mr_tee_svg_url', $data['svg_url'], true );
			$item->add_meta_data( __( 'Fichier Vectoriel (SVG)', 'mr-tee-editor' ), esc_url( $data['svg_url'] ), true );
		}
		if ( ! empty( $data['print_url'] ) ) {
			$item->add_meta_data( '_mr_tee_print_url', $data['print_url'], true );
			$item->add_meta_data( __( 'Fichier HD 300 DPI (PNG)', 'mr-tee-editor' ), esc_url( $data['print_url'] ), true );
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

		return $product_name;
	}
}
