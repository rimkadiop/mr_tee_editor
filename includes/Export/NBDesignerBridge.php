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
	 * @param array<string, string> $source_files Optional pre-uploaded disk file paths (preview_file, svg_file, print_file).
	 * @return array<string, mixed> Metadata for order line item.
	 */
	public static function inject_nbd_artifacts( $nbd_item_key, array $custom_data, $preview_b64, $svg_markup, $print_b64, $product_id, array $source_files = array() ) {
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

		// 1. Artifacts: Preview thumbnail (store directly without slow GD PNG re-encoding)
		$preview_rel = '';
		if ( ! empty( $preview_b64 ) ) {
			$raw_b64 = strpos( $preview_b64, 'base64,' ) !== false ? explode( 'base64,', $preview_b64 )[1] : $preview_b64;
			$decoded = base64_decode( $raw_b64 );
			$is_jpg  = ( strpos( $preview_b64, 'image/jpeg' ) !== false || strpos( $preview_b64, 'image/jpg' ) !== false );
			if ( false !== $decoded && '' !== $decoded ) {
				if ( $is_jpg ) {
					file_put_contents( trailingslashit( $nbd_dir ) . 'design_preview.jpg', $decoded );
					file_put_contents( trailingslashit( $nbd_dir ) . 'preview/frame_0.jpg', $decoded );
					$preview_rel = 'preview/frame_0.jpg';
				} else {
					file_put_contents( trailingslashit( $nbd_dir ) . 'design_preview.png', $decoded );
					file_put_contents( trailingslashit( $nbd_dir ) . 'preview/frame_0.png', $decoded );
					$preview_rel = 'preview/frame_0.png';
				}
			}
		} elseif ( ! empty( $source_files['preview_file'] ) && file_exists( $source_files['preview_file'] ) ) {
			$ext = strtolower( pathinfo( $source_files['preview_file'], PATHINFO_EXTENSION ) );
			if ( 'jpg' === $ext || 'jpeg' === $ext ) {
				@copy( $source_files['preview_file'], trailingslashit( $nbd_dir ) . 'design_preview.jpg' );
				@copy( $source_files['preview_file'], trailingslashit( $nbd_dir ) . 'preview/frame_0.jpg' );
				$preview_rel = 'preview/frame_0.jpg';
			} else {
				@copy( $source_files['preview_file'], trailingslashit( $nbd_dir ) . 'design_preview.png' );
				@copy( $source_files['preview_file'], trailingslashit( $nbd_dir ) . 'preview/frame_0.png' );
				$preview_rel = 'preview/frame_0.png';
			}
		}

		// 2. Artifacts: SVG scene-graphs (populated during admin order export or if explicitly provided)
		$svg_written = false;
		if ( ! empty( $svg_markup ) ) {
			file_put_contents( trailingslashit( $nbd_dir ) . 'design.svg', $svg_markup );
			file_put_contents( trailingslashit( $nbd_dir ) . 'frame_0.svg', $svg_markup );
			file_put_contents( trailingslashit( $nbd_dir ) . 'svg/frame_0.svg', $svg_markup );
			$svg_written = true;
		} elseif ( ! empty( $source_files['svg_file'] ) && file_exists( $source_files['svg_file'] ) ) {
			@copy( $source_files['svg_file'], trailingslashit( $nbd_dir ) . 'design.svg' );
			@copy( $source_files['svg_file'], trailingslashit( $nbd_dir ) . 'frame_0.svg' );
			@copy( $source_files['svg_file'], trailingslashit( $nbd_dir ) . 'svg/frame_0.svg' );
			$svg_written = true;
		}

		// 3. Artifacts: 300 DPI Transparent print rasters (populated during admin order export or if explicitly provided)
		$print_written = false;
		if ( ! empty( $print_b64 ) ) {
			$raw_b64 = strpos( $print_b64, 'base64,' ) !== false ? explode( 'base64,', $print_b64 )[1] : $print_b64;
			$decoded = base64_decode( $raw_b64 );
			if ( false !== $decoded && '' !== $decoded ) {
				file_put_contents( trailingslashit( $nbd_dir ) . 'frame_0.png', $decoded );
				file_put_contents( trailingslashit( $nbd_dir ) . 'print_file_front.png', $decoded );
				file_put_contents( trailingslashit( $nbd_dir ) . 'print_300dpi.png', $decoded );
				$print_written = true;
			}
		} elseif ( ! empty( $source_files['print_file'] ) && file_exists( $source_files['print_file'] ) ) {
			@copy( $source_files['print_file'], trailingslashit( $nbd_dir ) . 'frame_0.png' );
			@copy( $source_files['print_file'], trailingslashit( $nbd_dir ) . 'print_file_front.png' );
			@copy( $source_files['print_file'], trailingslashit( $nbd_dir ) . 'print_300dpi.png' );
			$print_written = true;
		}

		// 4. Product sides configuration with dynamic physical dimensions
		$cfg    = \MrTeeEditor\Domain\SceneGraphSchema::get_product_config( $product_id );
		$prod_w = ! empty( $custom_data['crop_print_width'] ) ? floatval( $custom_data['crop_print_width'] ) : ( isset( $cfg['print_real_width'] ) ? floatval( $cfg['print_real_width'] ) : 30.0 );
		$prod_h = ! empty( $custom_data['crop_print_height'] ) ? floatval( $custom_data['crop_print_height'] ) : ( isset( $cfg['print_real_height'] ) ? floatval( $cfg['print_real_height'] ) : 40.0 );
		$unit   = ! empty( $custom_data['print_unit'] ) ? $custom_data['print_unit'] : ( ! empty( $cfg['print_unit'] ) ? $cfg['print_unit'] : 'cm' );
		$dpi    = ! empty( $custom_data['dpi'] ) ? intval( $custom_data['dpi'] ) : ( isset( $cfg['dpi'] ) ? intval( $cfg['dpi'] ) : 300 );
		$area_w = ! empty( $custom_data['crop_width_px'] ) ? intval( $custom_data['crop_width_px'] ) : ( isset( $cfg['print_width_px'] ) ? intval( $cfg['print_width_px'] ) : 2400 );
		$area_h = ! empty( $custom_data['crop_height_px'] ) ? intval( $custom_data['crop_height_px'] ) : ( isset( $cfg['print_height_px'] ) ? intval( $cfg['print_height_px'] ) : 3000 );

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
			'version'     => '2.3.18',
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

		// Text & Image fields
		if ( ! empty( $custom_data['text_lines'] ) && is_array( $custom_data['text_lines'] ) ) {
			foreach ( $custom_data['text_lines'] as $idx => $line ) {
				$is_image = isset( $line['fieldType'] ) && 'image' === $line['fieldType'];
				if ( $is_image ) {
					$img_url = ! empty( $line['imageUrl'] ) ? $line['imageUrl'] : '';
					if ( ! empty( $img_url ) ) {
						$objects[] = array(
							'type'       => 'image',
							'src'        => $img_url,
							'origin_url' => $img_url,
							'left'       => 100,
							'top'        => 50 + ( $idx * 55 ),
							'width'      => 180,
							'height'     => 180,
						);
					}
					continue;
				}

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
				'preview'    => $preview_rel ? trailingslashit( $nbd_url ) . $preview_rel : '',
				'svg'        => $svg_written ? trailingslashit( $nbd_url ) . 'svg/frame_0.svg' : '',
				'print_file' => $print_written ? trailingslashit( $nbd_url ) . 'frame_0.png' : '',
				'config'     => trailingslashit( $nbd_url ) . 'config.json',
			),
		);
	}

	/**
	 * Copy deferred admin-generated vector SVG and 300 DPI print PNG into an existing
	 * NBDesigner item folder so NBDesigner order downloads stay synchronized.
	 *
	 * @param string $nbd_item_key NBDesigner design item key.
	 * @param string $svg_file     Source SVG file path.
	 * @param string $print_file   Source 300 DPI PNG file path.
	 * @return array{svg: string, print_file: string}
	 */
	public static function sync_print_artifacts( $nbd_item_key, $svg_file = '', $print_file = '' ) {
		$out = array(
			'svg'        => '',
			'print_file' => '',
		);

		$clean_key = sanitize_file_name( (string) $nbd_item_key );
		if ( '' === $clean_key ) {
			return $out;
		}

		$wp_upload = wp_upload_dir();
		$nbd_dir   = trailingslashit( $wp_upload['basedir'] ) . 'nbdesigner/designs/' . $clean_key;
		$nbd_url   = trailingslashit( $wp_upload['baseurl'] ) . 'nbdesigner/designs/' . $clean_key;

		if ( ! file_exists( $nbd_dir ) ) {
			wp_mkdir_p( $nbd_dir );
		}
		if ( ! file_exists( $nbd_dir . '/svg' ) ) {
			wp_mkdir_p( $nbd_dir . '/svg' );
		}

		if ( ! empty( $svg_file ) && file_exists( $svg_file ) ) {
			@copy( $svg_file, trailingslashit( $nbd_dir ) . 'design.svg' );
			@copy( $svg_file, trailingslashit( $nbd_dir ) . 'frame_0.svg' );
			@copy( $svg_file, trailingslashit( $nbd_dir ) . 'svg/frame_0.svg' );
			$out['svg'] = trailingslashit( $nbd_url ) . 'svg/frame_0.svg';
		}

		if ( ! empty( $print_file ) && file_exists( $print_file ) ) {
			@copy( $print_file, trailingslashit( $nbd_dir ) . 'frame_0.png' );
			@copy( $print_file, trailingslashit( $nbd_dir ) . 'print_file_front.png' );
			@copy( $print_file, trailingslashit( $nbd_dir ) . 'print_300dpi.png' );
			$out['print_file'] = trailingslashit( $nbd_url ) . 'frame_0.png';
		}

		return $out;
	}
}
