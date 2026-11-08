<?php
/**
 * Mr. Tee Editor - NBDesigner Bridge Adapter
 *
 * Implements the 10-artifact handshake architecture to seamlessly inject
 * Mr. Tee Editor designs into NBDesigner's backend export pipeline without
 * replacing the native NBDesigner product customizer.
 *
 * Complete 10-Artifact Suite:
 * 1.  `frame_0.png`          - 300 DPI transparent print raster (NBDesigner side 0 export)
 * 2.  `print_file_front.png` - Transparent raster alias
 * 3.  `print_300dpi.png`     - High-DPI transparent raster alias
 * 4.  `frame_0.svg`          - Vector SVG scene-graph in root
 * 5.  `svg/frame_0.svg`      - Vector SVG scene-graph for NBDesigner SVG export
 * 6.  `design.svg`           - Vector SVG alias
 * 7.  `preview/frame_0.png`  - Client garment preview in preview/ subfolder (for WooCommerce Order metabox)
 * 8.  `design_preview.png`   - Client garment preview in root
 * 9.  `design.json`          - Fabric/NBDesigner scene-graph layer schema (texts and images)
 * 10. `config.json`          - NBDesigner configuration (dpi: 300, unit: "cm", product sides)
 * 11. `product.json`         - Serialized PHP array of product sides
 * 12. `option.json`          - Serialized PHP array of dpi & unit options
 * 13. `used_font.json`       - Safe fonts definition array
 *
 * @package MrTeeEditor\Export
 */

namespace MrTeeEditor\Export;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class NBDesignerBridge {

	/**
	 * Check if NBDesigner plugin is active.
	 *
	 * @return bool
	 */
	public static function is_nbd_active() {
		return class_exists( 'Nbdesigner_Plugin' ) || defined( 'NBDESIGNER_VERSION' );
	}

	/**
	 * Create the full 10-artifact NBDesigner handshake for an ordered item.
	 *
	 * @param string               $nbd_item_key Unique item key or token.
	 * @param array<string, mixed> $custom_data  Sanitized customization payload.
	 * @param string               $preview_b64  Base64 garment preview image.
	 * @param string               $svg_markup   Vector SVG string.
	 * @param string               $print_b64    Base64 300 DPI transparent raster.
	 * @param int                  $product_id   Product ID.
	 * @return array<string, mixed> Metadata for order line item.
	 */
	public static function inject_nbd_artifacts( $nbd_item_key, array $custom_data, $preview_b64, $svg_markup, $print_b64, $product_id ) {
		$wp_upload = wp_upload_dir();
		$nbd_dir   = trailingslashit( $wp_upload['basedir'] ) . 'nbdesigner/designs/' . sanitize_file_name( $nbd_item_key );
		$nbd_url   = trailingslashit( $wp_upload['baseurl'] ) . 'nbdesigner/designs/' . sanitize_file_name( $nbd_item_key );

		if ( ! file_exists( $nbd_dir ) ) {
			wp_mkdir_p( $nbd_dir );
		}
		if ( ! file_exists( $nbd_dir . '/preview' ) ) {
			wp_mkdir_p( $nbd_dir . '/preview' );
		}
		if ( ! file_exists( $nbd_dir . '/svg' ) ) {
			wp_mkdir_p( $nbd_dir . '/svg' );
		}

		// 1. Artifacts: Preview images (both root design_preview and preview/frame_0)
		if ( ! empty( $preview_b64 ) ) {
			$raw_b64 = strpos( $preview_b64, 'base64,' ) !== false ? explode( 'base64,', $preview_b64 )[1] : $preview_b64;
			$decoded = base64_decode( $raw_b64 );
			$is_jpg  = ( strpos( $preview_b64, 'image/jpeg' ) !== false || strpos( $preview_b64, 'image/jpg' ) !== false );
			file_put_contents( trailingslashit( $nbd_dir ) . 'design_preview.png', $decoded );
			file_put_contents( trailingslashit( $nbd_dir ) . 'preview/frame_0.png', $decoded );
			if ( $is_jpg ) {
				file_put_contents( trailingslashit( $nbd_dir ) . 'design_preview.jpg', $decoded );
				file_put_contents( trailingslashit( $nbd_dir ) . 'preview/frame_0.jpg', $decoded );
			}
		}

		// 2. Artifacts: SVG scene-graphs (root frame_0.svg, root design.svg, and svg/frame_0.svg)
		if ( ! empty( $svg_markup ) ) {
			file_put_contents( trailingslashit( $nbd_dir ) . 'design.svg', $svg_markup );
			file_put_contents( trailingslashit( $nbd_dir ) . 'frame_0.svg', $svg_markup );
			file_put_contents( trailingslashit( $nbd_dir ) . 'svg/frame_0.svg', $svg_markup );
		}

		// 3. Artifacts: 300 DPI Transparent print rasters (frame_0.png, print_file_front.png, print_300dpi.png)
		if ( ! empty( $print_b64 ) ) {
			$raw_b64 = strpos( $print_b64, 'base64,' ) !== false ? explode( 'base64,', $print_b64 )[1] : $print_b64;
			$decoded = base64_decode( $raw_b64 );
			file_put_contents( trailingslashit( $nbd_dir ) . 'frame_0.png', $decoded );
			file_put_contents( trailingslashit( $nbd_dir ) . 'print_file_front.png', $decoded );
			file_put_contents( trailingslashit( $nbd_dir ) . 'print_300dpi.png', $decoded );
		}

		// 4. Product sides configuration with dynamic physical dimensions
		$cfg    = \MrTeeEditor\Domain\SceneGraphSchema::get_product_config( $product_id );
		$prod_w = isset( $cfg['print_real_width'] ) ? floatval( $cfg['print_real_width'] ) : 30.0;
		$prod_h = isset( $cfg['print_real_height'] ) ? floatval( $cfg['print_real_height'] ) : 40.0;
		$unit   = ! empty( $cfg['print_unit'] ) ? $cfg['print_unit'] : 'cm';
		$dpi    = isset( $cfg['dpi'] ) ? intval( $cfg['dpi'] ) : 300;
		$area_w = isset( $cfg['print_width_px'] ) ? intval( $cfg['print_width_px'] ) : 2400;
		$area_h = isset( $cfg['print_height_px'] ) ? intval( $cfg['print_height_px'] ) : 3000;

		$product_sides = array(
			array(
				'orientation_name'   => 'Front',
				'product_width'      => $prod_w,
				'product_height'     => $prod_h,
				'real_width'         => $prod_w,
				'real_height'        => $prod_h,
				'real_top'           => 0,
				'real_left'          => 0,
				'area_design_width'  => $area_w,
				'area_design_height' => $area_h,
				'img_src'            => '',
				'bg_type'            => 'color',
				'bg_color_value'     => '#ffffff',
				'bleed_top_bottom'   => 0,
				'bleed_left_right'   => 0,
				'include_background' => 0,
			),
		);

		// 5. Artifacts: product.json and option.json (serialized PHP arrays)
		file_put_contents( trailingslashit( $nbd_dir ) . 'product.json', serialize( $product_sides ) );
		file_put_contents(
			trailingslashit( $nbd_dir ) . 'option.json',
			serialize(
				array(
					'dpi'    => $dpi,
					'unit'   => $unit,
					'layout' => 'm',
				)
			)
		);

		// 6. Artifact: config.json
		$config_data = array(
			'version'     => '2.2.0',
			'engine'      => 'mr_tee_editor',
			'product_id'  => $product_id,
			'created_at'  => current_time( 'mysql' ),
			'dpi'         => $dpi,
			'unit'        => $unit,
			'orientation' => 'front',
			'product'     => $product_sides,
			'stage'       => array(
				'width'  => $area_w,
				'height' => $area_h,
			),
			'custom_data' => $custom_data,
		);
		file_put_contents( trailingslashit( $nbd_dir ) . 'config.json', wp_json_encode( $config_data, JSON_PRETTY_PRINT ) );

		// 7. Artifact: design.json (Fabric/NBDesigner layers for "View detail" admin screen)
		$objects = array();

		// Text lines
		if ( ! empty( $custom_data['text_lines'] ) && is_array( $custom_data['text_lines'] ) ) {
			foreach ( $custom_data['text_lines'] as $idx => $line ) {
				$text   = ! empty( $line['value'] ) ? $line['value'] : ( ! empty( $line['default_text'] ) ? $line['default_text'] : '' );
				$family = ! empty( $line['fontId'] ) ? $line['fontId'] : 'Super Mario Wii';
				if ( ! empty( $text ) ) {
					$objects[] = array(
						'type'       => 'i-text',
						'text'       => $text,
						'fontFamily' => $family,
						'fontSize'   => ( 0 === $idx ) ? 36 : 48,
						'ptFontSize' => ( 0 === $idx ) ? 27 : 36,
						'fill'       => ( 0 === $idx ) ? '#e42616' : '#222222',
						'left'       => 100,
						'top'        => 50 + ( $idx * 55 ),
						'width'      => 240,
						'height'     => 50,
					);
				}
			}
		}

		// Repeater items (Mushrooms)
		$repeater_count = isset( $custom_data['repeater_count'] ) ? intval( $custom_data['repeater_count'] ) : 0;
		if ( ! empty( $custom_data['repeater_items'] ) && is_array( $custom_data['repeater_items'] ) ) {
			$base_url = plugins_url( 'assets/icons/', dirname( __DIR__ ) );
			foreach ( $custom_data['repeater_items'] as $idx => $m_item ) {
				if ( $idx >= $repeater_count ) {
					break;
				}
				$m_name   = ! empty( $m_item['name'] ) ? $m_item['name'] : sprintf( 'Champignon %d', $idx + 1 );
				$m_icon   = ! empty( $m_item['icon'] ) ? $m_item['icon'] : 'mushroom_red';
				$icon_url = $base_url . $m_icon . '.svg';

				// Image layer for mushroom icon
				$objects[] = array(
					'type'       => 'image',
					'src'        => $icon_url,
					'origin_url' => $icon_url,
					'left'       => 60 + ( $idx * 80 ),
					'top'        => 190,
					'width'      => 50,
					'height'     => 50,
				);

				// Text layer for mushroom name
				$objects[] = array(
					'type'       => 'i-text',
					'text'       => $m_name,
					'fontFamily' => 'COCOGOOSE CAPS',
					'fontSize'   => 16,
					'ptFontSize' => 12,
					'fill'       => '#111111',
					'left'       => 60 + ( $idx * 80 ),
					'top'        => 245,
					'width'      => 65,
					'height'     => 20,
				);
			}
		}

		$design_json_data = array(
			'frame_0' => array(
				'version' => '3.4.0',
				'objects' => $objects,
			),
		);
		file_put_contents( trailingslashit( $nbd_dir ) . 'design.json', wp_json_encode( $design_json_data, JSON_PRETTY_PRINT ) );

		// 8. Artifact: used_font.json
		file_put_contents( trailingslashit( $nbd_dir ) . 'used_font.json', '[]' );

		// 9. Return metadata array for WooCommerce order line item
		return array(
			'folder'   => $nbd_dir,
			'url'      => $nbd_url,
			'key'      => $nbd_item_key,
			'nbd_data' => array(
				'product_id' => $product_id,
				'folder'     => $nbd_item_key,
				'preview'    => trailingslashit( $nbd_url ) . 'preview/frame_0.png',
				'svg'        => trailingslashit( $nbd_url ) . 'svg/frame_0.svg',
				'print_file' => trailingslashit( $nbd_url ) . 'frame_0.png',
				'config'     => trailingslashit( $nbd_url ) . 'config.json',
			),
		);
	}
}
