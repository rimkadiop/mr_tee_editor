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
		add_filter( 'woocommerce_get_item_data', array( __CLASS__, 'display_cart_item_data' ), 10, 2 );
		add_action( 'woocommerce_checkout_create_order_line_item', array( __CLASS__, 'save_order_line_item_data' ), 10, 4 );
		add_filter( 'woocommerce_cart_item_thumbnail', array( __CLASS__, 'filter_cart_item_thumbnail' ), 10, 3 );
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

		$design_key   = 'mrtee_' . wp_generate_uuid4();
		$preview_png  = isset( $_POST['mr_tee_preview_png'] ) ? wp_unslash( $_POST['mr_tee_preview_png'] ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized,WordPress.Security.NonceVerification.Missing
		$vector_svg   = isset( $_POST['mr_tee_vector_svg'] ) ? wp_unslash( $_POST['mr_tee_vector_svg'] ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized,WordPress.Security.NonceVerification.Missing
		$print_300dpi = isset( $_POST['mr_tee_print_300dpi_png'] ) ? wp_unslash( $_POST['mr_tee_print_300dpi_png'] ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized,WordPress.Security.NonceVerification.Missing

		// Save files to disk
		$sub_dir     = 'designs/' . $design_key;
		$is_jpeg     = ( strpos( $preview_png, 'image/jpeg' ) !== false || strpos( $preview_png, 'image/jpg' ) !== false );
		$preview_ext = $is_jpeg ? 'design_preview.jpg' : 'design_preview.png';
		$preview_res = PrintAssetGenerator::save_base64_png( $preview_png, $sub_dir, $preview_ext );
		if ( $preview_res && $is_jpeg ) {
			@copy( $preview_res['file'], dirname( $preview_res['file'] ) . '/design_preview.png' );
		}
		$svg_res     = PrintAssetGenerator::save_svg( $vector_svg, $sub_dir, 'design.svg' );
		$print_res   = PrintAssetGenerator::save_base64_png( $print_300dpi, $sub_dir, 'print_300dpi.png' );

		$config           = SceneGraphSchema::get_product_config( $product_id );
		$dims_summary     = sprintf(
			'%s × %s %s (Ratio: %s | 300 DPI: %s × %s px)',
			isset( $config['print_real_width'] ) ? $config['print_real_width'] : 30.0,
			isset( $config['print_real_height'] ) ? $config['print_real_height'] : 40.0,
			isset( $config['print_unit'] ) ? $config['print_unit'] : 'cm',
			isset( $config['print_aspect_ratio'] ) ? round( floatval( $config['print_aspect_ratio'] ), 3 ) : '0.75',
			isset( $config['print_width_px'] ) ? $config['print_width_px'] : 2400,
			isset( $config['print_height_px'] ) ? $config['print_height_px'] : 3000
		);

		$cart_item_data['mr_tee_editor'] = array(
			'key'               => $design_key,
			'payload'           => $payload,
			'print_dimensions'  => $dims_summary,
			'print_real_width'  => isset( $config['print_real_width'] ) ? $config['print_real_width'] : 30.0,
			'print_real_height' => isset( $config['print_real_height'] ) ? $config['print_real_height'] : 40.0,
			'print_unit'        => isset( $config['print_unit'] ) ? $config['print_unit'] : 'cm',
			'print_aspect_ratio'=> isset( $config['print_aspect_ratio'] ) ? $config['print_aspect_ratio'] : 0.75,
			'product_type'      => isset( $config['product_type'] ) ? $config['product_type'] : 'garment',
			'preview_url'       => $preview_res ? $preview_res['url'] : '',
			'preview_file'      => $preview_res ? $preview_res['file'] : '',
			'svg_url'           => $svg_res ? $svg_res['url'] : '',
			'svg_file'          => $svg_res ? $svg_res['file'] : '',
			'print_url'         => $print_res ? $print_res['url'] : '',
			'print_file'        => $print_res ? $print_res['file'] : '',
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
				$product_id
			);
			$cart_item_data['mr_tee_editor']['nbd'] = $nbd_meta;
		}

		$cart_item_data['unique_key'] = $design_key;

		return $cart_item_data;
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

		// Text lines
		if ( ! empty( $payload['text_lines'] ) && is_array( $payload['text_lines'] ) ) {
			foreach ( $payload['text_lines'] as $line ) {
				$label = ! empty( $line['label'] ) ? $line['label'] : 'Texte';
				$val   = ! empty( $line['value'] ) ? $line['value'] : '';
				if ( ! empty( $val ) ) {
					$item_data[] = array(
						'key'     => $label,
						'value'   => esc_html( $val ),
						'display' => esc_html( $val ),
					);
				}
			}
		}

		// Repeater items (Mushrooms)
		if ( isset( $payload['repeater_count'] ) ) {
			$count = intval( $payload['repeater_count'] );
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

		// Text lines readable meta
		if ( ! empty( $payload['text_lines'] ) && is_array( $payload['text_lines'] ) ) {
			foreach ( $payload['text_lines'] as $line ) {
				$label = ! empty( $line['label'] ) ? $line['label'] : 'Texte';
				$val   = ! empty( $line['value'] ) ? $line['value'] : '';
				if ( ! empty( $val ) ) {
					$item->add_meta_data( $label, $val, true );
				}
			}
		}

		// Repeater summary readable meta
		if ( isset( $payload['repeater_count'] ) ) {
			$count = intval( $payload['repeater_count'] );
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
	 * Filter cart item thumbnail to show the personalized preview.
	 *
	 * @param string               $thumbnail Existing thumbnail HTML.
	 * @param array<string, mixed> $cart_item Cart item array.
	 * @param string               $cart_item_key Cart item key.
	 * @return string
	 */
	public static function filter_cart_item_thumbnail( $thumbnail, $cart_item, $cart_item_key ) {
		if ( ! empty( $cart_item['mr_tee_editor']['preview_url'] ) ) {
			return sprintf(
				'<img src="%s" class="attachment-woocommerce_thumbnail size-woocommerce_thumbnail mr-tee-cart-thumb" alt="Personalized Preview" style="width:70px;height:70px;object-fit:cover;border-radius:6px;" />',
				esc_url( $cart_item['mr_tee_editor']['preview_url'] )
			);
		}
		return $thumbnail;
	}
}
