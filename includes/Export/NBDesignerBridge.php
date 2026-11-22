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
	 * Resolve target NBDesigner design directory and URL, honoring NBDesigner constants when defined.
	 *
	 * @param string $nbd_item_key NBDesigner item key.
	 * @return array{dir: string, url: string}
	 */
	private static function resolve_nbd_location( $nbd_item_key ) {
		$clean_key = sanitize_file_name( (string) $nbd_item_key );
		$wp_upload = wp_upload_dir();
		$base_dir  = defined( 'NBDESIGNER_CUSTOMER_DIR' ) ? trailingslashit( NBDESIGNER_CUSTOMER_DIR ) : trailingslashit( $wp_upload['basedir'] ) . 'nbdesigner/designs/';
		$base_url  = defined( 'NBDESIGNER_CUSTOMER_URL' ) ? trailingslashit( NBDESIGNER_CUSTOMER_URL ) : trailingslashit( $wp_upload['baseurl'] ) . 'nbdesigner/designs/';
		return array(
			'dir' => rtrim( $base_dir, '/\\' ) . '/' . $clean_key,
			'url' => rtrim( $base_url, '/' ) . '/' . $clean_key,
		);
	}

	/**
	 * Create the full 10-artifact NBDesigner handshake for an ordered item.
	 *
	 * @param string                $nbd_item_key Unique item key or token.
	 * @param array<string, mixed>  $custom_data  Sanitized customization payload.
	 * @param string                $preview_b64  Base64 garment preview image.
	 * @param string                $svg_markup   Vector SVG string.
	 * @param string                $print_b64    Base64 300 DPI transparent raster.
	 * @param int                   $product_id   Product ID.
	 * @param array<string, string> $source_files Optional pre-uploaded disk file paths (preview_file, svg_file, print_file).
	 * @return array<string, mixed> Metadata for order line item.
	 */
	public static function inject_nbd_artifacts( $nbd_item_key, array $custom_data, $preview_b64, $svg_markup, $print_b64, $product_id, array $source_files = array() ) {
		$loc     = self::resolve_nbd_location( $nbd_item_key );
		$nbd_dir = $loc['dir'];
		$nbd_url = $loc['url'];

		if ( ! file_exists( $nbd_dir ) ) {
			wp_mkdir_p( $nbd_dir );
		}
		if ( ! file_exists( $nbd_dir . '/preview' ) ) {
			wp_mkdir_p( $nbd_dir . '/preview' );
		}
		if ( ! file_exists( $nbd_dir . '/svg' ) ) {
			wp_mkdir_p( $nbd_dir . '/svg' );
		}

		$cfg           = \MrTeeEditor\Domain\SceneGraphSchema::get_product_config( $product_id );
		$print_sides   = ( ! empty( $custom_data['print_sides'] ) && is_array( $custom_data['print_sides'] ) )
			? array_values( $custom_data['print_sides'] )
			: ( ( ! empty( $cfg['print_sides'] ) && is_array( $cfg['print_sides'] ) ) ? array_values( $cfg['print_sides'] ) : array() );
		$previews_list = ( ! empty( $source_files['previews'] ) && is_array( $source_files['previews'] ) )
			? array_values( $source_files['previews'] )
			: array();

		// 1. Artifacts: Preview thumbnail(s) (store directly without slow GD PNG re-encoding)
		$preview_rel  = '';
		$preview_urls = array();
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
				$preview_urls[0] = trailingslashit( $nbd_url ) . $preview_rel;
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
			$preview_urls[0] = trailingslashit( $nbd_url ) . $preview_rel;
		}

		// Copy additional side/view previews into preview/frame_{idx}.{jpg|png}
		if ( count( $print_sides ) > 1 && ! empty( $previews_list ) ) {
			foreach ( $print_sides as $s_idx => $side ) {
				if ( 0 === $s_idx && '' !== $preview_rel ) {
					continue;
				}
				$side_id = ! empty( $side['id'] ) ? (string) $side['id'] : '';
				$matched = null;
				if ( '' !== $side_id ) {
					foreach ( $previews_list as $p_item ) {
						if ( ! empty( $p_item['side_id'] ) && (string) $p_item['side_id'] === $side_id && ! empty( $p_item['file'] ) && file_exists( $p_item['file'] ) ) {
							$matched = $p_item;
							break;
						}
					}
				}
				if ( null === $matched && ! empty( $previews_list[ $s_idx ]['file'] ) && file_exists( $previews_list[ $s_idx ]['file'] ) ) {
					$matched = $previews_list[ $s_idx ];
				}
				if ( $matched && ! empty( $matched['file'] ) && file_exists( $matched['file'] ) ) {
					$p_ext      = strtolower( pathinfo( $matched['file'], PATHINFO_EXTENSION ) );
					$target_ext = ( 'jpg' === $p_ext || 'jpeg' === $p_ext ) ? 'jpg' : 'png';
					$frame_rel  = 'preview/frame_' . $s_idx . '.' . $target_ext;
					@copy( $matched['file'], trailingslashit( $nbd_dir ) . $frame_rel );
					if ( 0 === $s_idx ) {
						@copy( $matched['file'], trailingslashit( $nbd_dir ) . 'design_preview.' . $target_ext );
						$preview_rel = $frame_rel;
					}
					$preview_urls[ $s_idx ] = trailingslashit( $nbd_url ) . $frame_rel;
				}
			}
		} elseif ( count( $previews_list ) > 1 ) {
			foreach ( $previews_list as $p_idx => $p_item ) {
				if ( 0 === $p_idx && '' !== $preview_rel ) {
					continue;
				}
				if ( ! empty( $p_item['file'] ) && file_exists( $p_item['file'] ) ) {
					$p_ext      = strtolower( pathinfo( $p_item['file'], PATHINFO_EXTENSION ) );
					$target_ext = ( 'jpg' === $p_ext || 'jpeg' === $p_ext ) ? 'jpg' : 'png';
					$frame_rel  = 'preview/frame_' . $p_idx . '.' . $target_ext;
					@copy( $p_item['file'], trailingslashit( $nbd_dir ) . $frame_rel );
					if ( 0 === $p_idx ) {
						@copy( $p_item['file'], trailingslashit( $nbd_dir ) . 'design_preview.' . $target_ext );
						$preview_rel = $frame_rel;
					}
					$preview_urls[ $p_idx ] = trailingslashit( $nbd_url ) . $frame_rel;
				}
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

		// 4. Product sides configuration with dynamic physical dimensions (supports multi-side)
		$prod_w = ! empty( $custom_data['crop_print_width'] ) ? floatval( $custom_data['crop_print_width'] ) : ( isset( $cfg['print_real_width'] ) ? floatval( $cfg['print_real_width'] ) : 30.0 );
		$prod_h = ! empty( $custom_data['crop_print_height'] ) ? floatval( $custom_data['crop_print_height'] ) : ( isset( $cfg['print_real_height'] ) ? floatval( $cfg['print_real_height'] ) : 40.0 );
		$unit   = ! empty( $custom_data['print_unit'] ) ? $custom_data['print_unit'] : ( ! empty( $cfg['print_unit'] ) ? $cfg['print_unit'] : 'cm' );
		$dpi    = ! empty( $custom_data['dpi'] ) ? intval( $custom_data['dpi'] ) : ( isset( $cfg['dpi'] ) ? intval( $cfg['dpi'] ) : 300 );
		$area_w = ! empty( $custom_data['crop_width_px'] ) ? intval( $custom_data['crop_width_px'] ) : ( isset( $cfg['print_width_px'] ) ? intval( $cfg['print_width_px'] ) : 2400 );
		$area_h = ! empty( $custom_data['crop_height_px'] ) ? intval( $custom_data['crop_height_px'] ) : ( isset( $cfg['print_height_px'] ) ? intval( $cfg['print_height_px'] ) : 3000 );

		if ( count( $print_sides ) > 1 ) {
			$product_sides = array();
			foreach ( $print_sides as $s_idx => $side ) {
				$s_label   = ! empty( $side['label'] ) ? (string) $side['label'] : ( 0 === $s_idx ? 'Front' : 'Side ' . ( $s_idx + 1 ) );
				$s_unit    = ! empty( $side['print_unit'] ) ? (string) $side['print_unit'] : $unit;
				$s_dpi     = ! empty( $side['dpi'] ) ? intval( $side['dpi'] ) : $dpi;
				$s_real_w  = ( 0 === $s_idx && ! empty( $custom_data['crop_print_width'] ) )
					? floatval( $custom_data['crop_print_width'] )
					: ( ! empty( $side['print_real_width'] ) ? floatval( $side['print_real_width'] ) : $prod_w );
				$s_real_h  = ( 0 === $s_idx && ! empty( $custom_data['crop_print_height'] ) )
					? floatval( $custom_data['crop_print_height'] )
					: ( ! empty( $side['print_real_height'] ) ? floatval( $side['print_real_height'] ) : $prod_h );
				$to_inches = ( 'mm' === $s_unit ) ? ( 1 / 25.4 ) : ( ( 'in' === $s_unit ) ? 1.0 : ( 1 / 2.54 ) );
				$s_area_w  = ( 0 === $s_idx && ! empty( $custom_data['crop_width_px'] ) )
					? intval( $custom_data['crop_width_px'] )
					: max( 10, (int) round( $s_real_w * $to_inches * $s_dpi ) );
				$s_area_h  = ( 0 === $s_idx && ! empty( $custom_data['crop_height_px'] ) )
					? intval( $custom_data['crop_height_px'] )
					: max( 10, (int) round( $s_real_h * $to_inches * $s_dpi ) );

				$product_sides[] = array(
					'orientation_name'   => $s_label,
					'product_width'      => $s_real_w,
					'product_height'     => $s_real_h,
					'real_width'         => $s_real_w,
					'real_height'        => $s_real_h,
					'real_top'           => 0,
					'real_left'          => 0,
					'area_design_width'  => $s_area_w,
					'area_design_height' => $s_area_h,
					'img_src'            => '',
					'bg_type'            => 'color',
					'bg_color_value'     => '#ffffff',
					'bleed_top_bottom'   => 0,
					'bleed_left_right'   => 0,
					'include_background' => 0,
				);
			}
		} else {
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
		}

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
			'version'     => defined( 'MR_TEE_EDITOR_VERSION' ) ? MR_TEE_EDITOR_VERSION : '2.4.0',
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

		// 7. Artifact: design.json (Fabric/NBDesigner layers for "View detail" admin screen, per frame)
		$frame_count   = max( 1, count( $product_sides ) );
		$frame_objects = array_fill( 0, $frame_count, array() );
		$frame_indices = array_fill( 0, $frame_count, 0 );
		$side_id_map   = array();
		foreach ( $print_sides as $s_idx => $side ) {
			if ( ! empty( $side['id'] ) ) {
				$side_id_map[ (string) $side['id'] ] = $s_idx;
			}
		}
		$default_side_id = ! empty( $print_sides[0]['id'] ) ? (string) $print_sides[0]['id'] : 'front';

		// Text & Image fields
		if ( ! empty( $custom_data['text_lines'] ) && is_array( $custom_data['text_lines'] ) ) {
			foreach ( $custom_data['text_lines'] as $line ) {
				$line_side = ! empty( $line['sideId'] )
					? (string) $line['sideId']
					: ( ! empty( $line['side_id'] ) ? (string) $line['side_id'] : $default_side_id );
				$f_idx     = ( $frame_count > 1 && isset( $side_id_map[ $line_side ] ) ) ? $side_id_map[ $line_side ] : 0;
				$idx       = $frame_indices[ $f_idx ];
				$frame_indices[ $f_idx ]++;

				$is_image = isset( $line['fieldType'] ) && 'image' === $line['fieldType'];
				if ( $is_image ) {
					$img_url = ! empty( $line['imageUrl'] ) ? $line['imageUrl'] : '';
					if ( ! empty( $img_url ) ) {
						$frame_objects[ $f_idx ][] = array(
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
					$frame_objects[ $f_idx ][] = array(
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

		// Repeater items (Mushrooms, frame 0)
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
				$frame_objects[0][] = array(
					'type'       => 'image',
					'src'        => $icon_url,
					'origin_url' => $icon_url,
					'left'       => 60 + ( $idx * 80 ),
					'top'        => 190,
					'width'      => 50,
					'height'     => 50,
				);

				// Text layer for mushroom name
				$frame_objects[0][] = array(
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

		$design_json_data = array();
		foreach ( $frame_objects as $f_idx => $objs ) {
			$design_json_data[ 'frame_' . $f_idx ] = array(
				'version' => '3.4.0',
				'objects' => $objs,
			);
		}
		file_put_contents( trailingslashit( $nbd_dir ) . 'design.json', wp_json_encode( $design_json_data, JSON_PRETTY_PRINT ) );

		// 8. Artifact: used_font.json
		file_put_contents( trailingslashit( $nbd_dir ) . 'used_font.json', '[]' );

		// 9. Return metadata array for WooCommerce order line item
		$nbd_data = array(
			'product_id' => $product_id,
			'folder'     => $nbd_item_key,
			'preview'    => $preview_rel ? trailingslashit( $nbd_url ) . $preview_rel : '',
			'svg'        => $svg_written ? trailingslashit( $nbd_url ) . 'svg/frame_0.svg' : '',
			'print_file' => $print_written ? trailingslashit( $nbd_url ) . 'frame_0.png' : '',
			'config'     => trailingslashit( $nbd_url ) . 'config.json',
		);
		if ( count( $preview_urls ) > 1 ) {
			ksort( $preview_urls );
			$nbd_data['previews'] = array_values( $preview_urls );
		}

		return array(
			'folder'   => $nbd_dir,
			'url'      => $nbd_url,
			'key'      => $nbd_item_key,
			'nbd_data' => $nbd_data,
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

		$loc     = self::resolve_nbd_location( $clean_key );
		$nbd_dir = $loc['dir'];
		$nbd_url = $loc['url'];

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

	/**
	 * Copy multi-side deferred admin-generated vector SVGs and 300 DPI print PNGs into
	 * an existing NBDesigner item folder as frame_0, frame_1, ... while keeping side 0 aliases.
	 *
	 * @param string                              $nbd_item_key NBDesigner design item key.
	 * @param array<string|int, array<string, mixed>> $side_exports Map or list of side export definitions.
	 * @return array{svg: string, print_file: string, sides: array<string, array<string, mixed>>}
	 */
	public static function sync_multi_side_artifacts( $nbd_item_key, array $side_exports ) {
		$out = array(
			'svg'        => '',
			'print_file' => '',
			'sides'      => array(),
		);

		$clean_key = sanitize_file_name( (string) $nbd_item_key );
		if ( '' === $clean_key || empty( $side_exports ) ) {
			return $out;
		}

		$loc     = self::resolve_nbd_location( $clean_key );
		$nbd_dir = $loc['dir'];
		$nbd_url = $loc['url'];

		if ( ! file_exists( $nbd_dir ) ) {
			wp_mkdir_p( $nbd_dir );
		}
		if ( ! file_exists( $nbd_dir . '/svg' ) ) {
			wp_mkdir_p( $nbd_dir . '/svg' );
		}

		$idx = 0;
		foreach ( $side_exports as $key => $side ) {
			if ( ! is_array( $side ) ) {
				continue;
			}
			$side_id    = ! empty( $side['side_id'] ) ? (string) $side['side_id'] : ( is_string( $key ) && '' !== $key ? $key : ( 0 === $idx ? 'front' : 'side_' . $idx ) );
			$svg_file   = ! empty( $side['svg_file'] ) ? (string) $side['svg_file'] : '';
			$print_file = ! empty( $side['print_file'] ) ? (string) $side['print_file'] : '';

			$side_svg_url   = '';
			$side_print_url = '';

			if ( '' !== $svg_file && file_exists( $svg_file ) ) {
				@copy( $svg_file, trailingslashit( $nbd_dir ) . 'frame_' . $idx . '.svg' );
				@copy( $svg_file, trailingslashit( $nbd_dir ) . 'svg/frame_' . $idx . '.svg' );
				if ( 0 === $idx ) {
					@copy( $svg_file, trailingslashit( $nbd_dir ) . 'design.svg' );
				}
				$side_svg_url = trailingslashit( $nbd_url ) . 'svg/frame_' . $idx . '.svg';
				if ( 0 === $idx ) {
					$out['svg'] = $side_svg_url;
				}
			}

			if ( '' !== $print_file && file_exists( $print_file ) ) {
				@copy( $print_file, trailingslashit( $nbd_dir ) . 'frame_' . $idx . '.png' );
				if ( 0 === $idx ) {
					@copy( $print_file, trailingslashit( $nbd_dir ) . 'print_file_front.png' );
					@copy( $print_file, trailingslashit( $nbd_dir ) . 'print_300dpi.png' );
				}
				$side_print_url = trailingslashit( $nbd_url ) . 'frame_' . $idx . '.png';
				if ( 0 === $idx ) {
					$out['print_file'] = $side_print_url;
				}
			}

			$out['sides'][ $side_id ] = array(
				'frame_index' => $idx,
				'svg'         => $side_svg_url,
				'print_file'  => $side_print_url,
			);
			$idx++;
		}

		return $out;
	}
}
