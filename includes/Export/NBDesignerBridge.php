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
	 * Resolve an actual CSS/TTF font family name from a fontId slug, alias, or family string.
	 *
	 * @param string                              $raw_font  Raw fontId or family name.
	 * @param array<string, array<string, mixed>> $all_fonts Registered fonts map from FontRegistry.
	 * @param string                              $fallback  Default family name when empty.
	 * @return string
	 */
	private static function resolve_font_family_name( $raw_font, array $all_fonts, $fallback = 'Super Mario Wii' ) {
		$candidate = trim( (string) $raw_font );
		if ( '' === $candidate ) {
			return $fallback;
		}
		if ( isset( $all_fonts[ $candidate ]['family'] ) && '' !== trim( (string) $all_fonts[ $candidate ]['family'] ) ) {
			return (string) $all_fonts[ $candidate ]['family'];
		}
		$clean_key = strtolower( preg_replace( '/[\s\-]+/', '_', $candidate ) );
		if ( isset( $all_fonts[ $clean_key ]['family'] ) && '' !== trim( (string) $all_fonts[ $clean_key ]['family'] ) ) {
			return (string) $all_fonts[ $clean_key ]['family'];
		}
		foreach ( $all_fonts as $f_def ) {
			if ( ! is_array( $f_def ) || empty( $f_def['family'] ) ) {
				continue;
			}
			if ( 0 === strcasecmp( (string) $f_def['family'], $candidate ) ) {
				return (string) $f_def['family'];
			}
			if ( ! empty( $f_def['aliases'] ) && is_array( $f_def['aliases'] ) ) {
				foreach ( $f_def['aliases'] as $alias ) {
					if ( 0 === strcasecmp( (string) $alias, $candidate ) ) {
						return (string) $f_def['family'];
					}
				}
			}
		}
		return $candidate;
	}

	/**
	 * Extract a positive numeric metric from an associative array checking multiple candidate keys in order.
	 *
	 * @param array<string, mixed> $source Metric source array.
	 * @param array<int, string>   $keys   Candidate keys ordered by priority.
	 * @param bool                 $as_int Whether to cast to int (true) or float (false).
	 * @return int|float
	 */
	private static function pick_positive_metric( array $source, array $keys, $as_int = false ) {
		foreach ( $keys as $key ) {
			if ( isset( $source[ $key ] ) && is_numeric( $source[ $key ] ) ) {
				$val = $as_int ? intval( $source[ $key ] ) : floatval( $source[ $key ] );
				if ( $val > 0 ) {
					return $val;
				}
			}
		}
		return $as_int ? 0 : 0.0;
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
	 * @param array<string, mixed>  $source_files Optional pre-uploaded disk file paths (preview_file, svg_file, print_file, previews).
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

		// Copy additional side previews into preview/frame_{idx}.{jpg|png} ONLY when there are multiple printable sides.
		// Single-side multi-view products only have 1 NBDesigner side (frame_0) and must NEVER emit preview/frame_1.
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
		} elseif ( '' === $preview_rel && ! empty( $previews_list[0]['file'] ) && file_exists( $previews_list[0]['file'] ) ) {
			$p_ext           = strtolower( pathinfo( $previews_list[0]['file'], PATHINFO_EXTENSION ) );
			$target_ext      = ( 'jpg' === $p_ext || 'jpeg' === $p_ext ) ? 'jpg' : 'png';
			$frame_rel       = 'preview/frame_0.' . $target_ext;
			@copy( $previews_list[0]['file'], trailingslashit( $nbd_dir ) . $frame_rel );
			@copy( $previews_list[0]['file'], trailingslashit( $nbd_dir ) . 'design_preview.' . $target_ext );
			$preview_rel     = $frame_rel;
			$preview_urls[0] = trailingslashit( $nbd_url ) . $frame_rel;
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
		$prod_w = self::pick_positive_metric( $custom_data, array( 'crop_print_width', 'print_real_width', 'real_width' ), false );
		if ( $prod_w <= 0 ) {
			$prod_w = isset( $cfg['print_real_width'] ) ? floatval( $cfg['print_real_width'] ) : 30.0;
		}
		$prod_h = self::pick_positive_metric( $custom_data, array( 'crop_print_height', 'print_real_height', 'real_height' ), false );
		if ( $prod_h <= 0 ) {
			$prod_h = isset( $cfg['print_real_height'] ) ? floatval( $cfg['print_real_height'] ) : 40.0;
		}
		$unit   = ! empty( $custom_data['print_unit'] ) ? (string) $custom_data['print_unit'] : ( ! empty( $cfg['print_unit'] ) ? (string) $cfg['print_unit'] : 'cm' );
		$dpi    = ! empty( $custom_data['dpi'] ) ? intval( $custom_data['dpi'] ) : ( isset( $cfg['dpi'] ) ? intval( $cfg['dpi'] ) : 300 );
		$area_w = self::pick_positive_metric( $custom_data, array( 'crop_width_px', 'width_px', 'print_width_px' ), true );
		if ( $area_w <= 0 ) {
			$area_w = isset( $cfg['print_width_px'] ) ? intval( $cfg['print_width_px'] ) : 2400;
		}
		$area_h = self::pick_positive_metric( $custom_data, array( 'crop_height_px', 'height_px', 'print_height_px' ), true );
		if ( $area_h <= 0 ) {
			$area_h = isset( $cfg['print_height_px'] ) ? intval( $cfg['print_height_px'] ) : 3000;
		}

		if ( count( $print_sides ) > 1 ) {
			$product_sides = array();
			$side_metrics  = ( ! empty( $custom_data['side_export_metrics'] ) && is_array( $custom_data['side_export_metrics'] ) )
				? $custom_data['side_export_metrics']
				: array();
			foreach ( $print_sides as $s_idx => $side ) {
				$s_id      = ! empty( $side['id'] ) ? (string) $side['id'] : ( 0 === $s_idx ? 'front' : 'side_' . $s_idx );
				$s_m       = ( isset( $side_metrics[ $s_id ] ) && is_array( $side_metrics[ $s_id ] ) ) ? $side_metrics[ $s_id ] : array();
				$s_label   = ! empty( $side['label'] ) ? (string) $side['label'] : ( 0 === $s_idx ? 'Front' : 'Side ' . ( $s_idx + 1 ) );
				$s_unit    = ! empty( $s_m['print_unit'] ) ? (string) $s_m['print_unit'] : ( ! empty( $side['print_unit'] ) ? (string) $side['print_unit'] : $unit );
				$s_dpi     = ! empty( $s_m['dpi'] ) ? intval( $s_m['dpi'] ) : ( ! empty( $side['dpi'] ) ? intval( $side['dpi'] ) : $dpi );
				$sm_rw     = self::pick_positive_metric( $s_m, array( 'crop_print_width', 'print_real_width', 'real_width' ), false );
				$sm_rh     = self::pick_positive_metric( $s_m, array( 'crop_print_height', 'print_real_height', 'real_height' ), false );
				$s_real_w  = $sm_rw > 0
					? $sm_rw
					: ( ( 0 === $s_idx && ! empty( $custom_data['crop_print_width'] ) )
						? floatval( $custom_data['crop_print_width'] )
						: ( ! empty( $side['print_real_width'] ) ? floatval( $side['print_real_width'] ) : $prod_w ) );
				$s_real_h  = $sm_rh > 0
					? $sm_rh
					: ( ( 0 === $s_idx && ! empty( $custom_data['crop_print_height'] ) )
						? floatval( $custom_data['crop_print_height'] )
						: ( ! empty( $side['print_real_height'] ) ? floatval( $side['print_real_height'] ) : $prod_h ) );
				$to_inches = ( 'mm' === $s_unit ) ? ( 1 / 25.4 ) : ( ( 'in' === $s_unit ) ? 1.0 : ( 1 / 2.54 ) );
				$sm_pw     = self::pick_positive_metric( $s_m, array( 'crop_width_px', 'width_px', 'print_width_px' ), true );
				$sm_ph     = self::pick_positive_metric( $s_m, array( 'crop_height_px', 'height_px', 'print_height_px' ), true );
				$s_area_w  = $sm_pw > 0
					? $sm_pw
					: ( ( 0 === $s_idx && ! empty( $custom_data['crop_width_px'] ) )
						? intval( $custom_data['crop_width_px'] )
						: max( 10, (int) round( $s_real_w * $to_inches * $s_dpi ) ) );
				$s_area_h  = $sm_ph > 0
					? $sm_ph
					: ( ( 0 === $s_idx && ! empty( $custom_data['crop_height_px'] ) )
						? intval( $custom_data['crop_height_px'] )
						: max( 10, (int) round( $s_real_h * $to_inches * $s_dpi ) ) );

				$product_sides[] = array(
					'side_id'            => $s_id,
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
			$single_side_id    = ! empty( $print_sides[0]['id'] ) ? (string) $print_sides[0]['id'] : 'front';
			$single_side_label = ! empty( $print_sides[0]['label'] ) ? (string) $print_sides[0]['label'] : 'Front';
			$product_sides     = array(
				array(
					'side_id'            => $single_side_id,
					'orientation_name'   => $single_side_label,
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
			'version'     => defined( 'MR_TEE_EDITOR_VERSION' ) ? MR_TEE_EDITOR_VERSION : '2.5.0',
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
		$all_fonts       = class_exists( '\MrTeeEditor\Registry\FontRegistry' ) ? \MrTeeEditor\Registry\FontRegistry::get_fonts() : array();

		// Text & Image fields
		if ( ! empty( $custom_data['text_lines'] ) && is_array( $custom_data['text_lines'] ) ) {
			foreach ( $custom_data['text_lines'] as $line ) {
				if ( ! is_array( $line ) ) {
					continue;
				}
				$line_side = ! empty( $line['sideId'] )
					? (string) $line['sideId']
					: ( ! empty( $line['side_id'] ) ? (string) $line['side_id'] : $default_side_id );
				$f_idx     = ( $frame_count > 1 && isset( $side_id_map[ $line_side ] ) ) ? $side_id_map[ $line_side ] : 0;
				$idx       = $frame_indices[ $f_idx ];
				$frame_indices[ $f_idx ]++;

				$is_image = isset( $line['fieldType'] ) && 'image' === $line['fieldType'];
				if ( $is_image ) {
					$img_url = ! empty( $line['imageUrl'] ) ? (string) $line['imageUrl'] : '';
					if ( '' !== trim( $img_url ) ) {
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

				$has_value   = isset( $line['value'] ) && '' !== trim( (string) $line['value'] );
				$has_default = isset( $line['default_text'] ) && '' !== trim( (string) $line['default_text'] );
				$text        = $has_value ? (string) $line['value'] : ( $has_default ? (string) $line['default_text'] : '' );
				$raw_font    = ! empty( $line['fontId'] ) ? (string) $line['fontId'] : ( ! empty( $line['fontFamily'] ) ? (string) $line['fontFamily'] : '' );
				$family      = self::resolve_font_family_name( $raw_font, $all_fonts, 'Super Mario Wii' );
				$fill_color  = ! empty( $line['colorHex'] ) ? (string) $line['colorHex'] : ( ( 0 === $idx ) ? '#e42616' : '#222222' );
				if ( '' !== trim( $text ) ) {
					$frame_objects[ $f_idx ][] = array(
						'type'       => 'i-text',
						'text'       => $text,
						'fontFamily' => $family,
						'fontSize'   => ( 0 === $idx ) ? 36 : 48,
						'ptFontSize' => ( 0 === $idx ) ? 27 : 36,
						'fill'       => $fill_color,
						'left'       => 100,
						'top'        => 50 + ( $idx * 55 ),
						'width'      => 240,
						'height'     => 50,
					);
				}
			}
		}

		// Repeater items (Mushrooms, frame 0 — fontFamily matches SceneGraphSchema 'Bebas Neue')
		$repeater_count = isset( $custom_data['repeater_count'] ) ? intval( $custom_data['repeater_count'] ) : 0;
		if ( ! empty( $custom_data['repeater_items'] ) && is_array( $custom_data['repeater_items'] ) ) {
			$base_url = plugins_url( 'assets/icons/', dirname( __DIR__ ) );
			foreach ( $custom_data['repeater_items'] as $idx => $m_item ) {
				if ( $idx >= $repeater_count ) {
					break;
				}
				if ( ! is_array( $m_item ) ) {
					continue;
				}
				$m_name   = ( isset( $m_item['name'] ) && '' !== trim( (string) $m_item['name'] ) ) ? (string) $m_item['name'] : sprintf( 'Champignon %d', $idx + 1 );
				$m_icon   = ! empty( $m_item['icon'] ) ? (string) $m_item['icon'] : 'mushroom_red';
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
					'fontFamily' => 'Bebas Neue',
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
	 * an existing NBDesigner item folder matching each side's real frame index in `product.json`
	 * (and `config.json`), preserving side indices when middle sides are empty and updating both
	 * `product.json` and `config.json` with exact physical & pixel metrics.
	 *
	 * @param string                                  $nbd_item_key NBDesigner design item key.
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

		$product_json_path = trailingslashit( $nbd_dir ) . 'product.json';
		$config_json_path  = trailingslashit( $nbd_dir ) . 'config.json';
		$product_sides     = null;
		$config_data       = null;
		$product_modified  = false;
		$config_modified   = false;

		if ( file_exists( $product_json_path ) ) {
			$raw_prod = @file_get_contents( $product_json_path );
			if ( is_string( $raw_prod ) && '' !== $raw_prod ) {
				$decoded_prod = @unserialize( $raw_prod, array( 'allowed_classes' => false ) );
				if ( is_array( $decoded_prod ) ) {
					$product_sides = $decoded_prod;
				}
			}
		}

		if ( file_exists( $config_json_path ) ) {
			$raw_cfg = @file_get_contents( $config_json_path );
			if ( is_string( $raw_cfg ) && '' !== $raw_cfg ) {
				$decoded_cfg = json_decode( $raw_cfg, true );
				if ( is_array( $decoded_cfg ) ) {
					$config_data = $decoded_cfg;
				}
			}
		}

		// Build side_id -> frame_index & orientation_name -> frame_index maps from product.json & config.json
		// so middle empty sides never cause later sides to shift to the wrong frame index.
		$side_index_map  = array();
		$label_index_map = array();

		if ( is_array( $product_sides ) ) {
			foreach ( $product_sides as $p_idx => $p_side ) {
				if ( ! is_array( $p_side ) ) {
					continue;
				}
				if ( ! empty( $p_side['side_id'] ) ) {
					$side_index_map[ (string) $p_side['side_id'] ] = (int) $p_idx;
				}
				if ( ! empty( $p_side['orientation_name'] ) ) {
					$lk = strtolower( trim( (string) $p_side['orientation_name'] ) );
					if ( '' !== $lk && ! isset( $label_index_map[ $lk ] ) ) {
						$label_index_map[ $lk ] = (int) $p_idx;
					}
				}
			}
		}

		if ( is_array( $config_data ) && ! empty( $config_data['custom_data']['print_sides'] ) && is_array( $config_data['custom_data']['print_sides'] ) ) {
			foreach ( array_values( $config_data['custom_data']['print_sides'] ) as $c_idx => $c_side ) {
				if ( ! is_array( $c_side ) ) {
					continue;
				}
				if ( ! empty( $c_side['id'] ) && ! isset( $side_index_map[ (string) $c_side['id'] ] ) ) {
					$side_index_map[ (string) $c_side['id'] ] = (int) $c_idx;
				}
				if ( ! empty( $c_side['label'] ) ) {
					$lk = strtolower( trim( (string) $c_side['label'] ) );
					if ( '' !== $lk && ! isset( $label_index_map[ $lk ] ) ) {
						$label_index_map[ $lk ] = (int) $c_idx;
					}
				}
			}
		}

		$used_indices = array();
		$seq_cursor   = 0;
		foreach ( $side_exports as $key => $side ) {
			if ( ! is_array( $side ) ) {
				continue;
			}
			$side_id    = ! empty( $side['side_id'] ) ? (string) $side['side_id'] : ( is_string( $key ) && '' !== $key ? $key : ( 0 === $seq_cursor ? 'front' : 'side_' . $seq_cursor ) );
			$side_label = ! empty( $side['label'] ) ? strtolower( trim( (string) $side['label'] ) ) : '';

			if ( isset( $side_index_map[ $side_id ] ) && ! isset( $used_indices[ $side_index_map[ $side_id ] ] ) ) {
				$idx = $side_index_map[ $side_id ];
			} elseif ( '' !== $side_label && isset( $label_index_map[ $side_label ] ) && ! isset( $used_indices[ $label_index_map[ $side_label ] ] ) ) {
				$idx = $label_index_map[ $side_label ];
			} else {
				while ( isset( $used_indices[ $seq_cursor ] ) ) {
					$seq_cursor++;
				}
				$idx = $seq_cursor;
			}
			$used_indices[ $idx ] = true;
			$seq_cursor++;

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
				if ( 0 === $idx || '' === $out['svg'] ) {
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
				if ( 0 === $idx || '' === $out['print_file'] ) {
					$out['print_file'] = $side_print_url;
				}
			}

			// Support crop_print_width / print_real_width / real_width and px metric variants uniformly.
			$rw = self::pick_positive_metric( $side, array( 'real_width', 'crop_print_width', 'print_real_width' ), false );
			$rh = self::pick_positive_metric( $side, array( 'real_height', 'crop_print_height', 'print_real_height' ), false );
			$pw = self::pick_positive_metric( $side, array( 'width_px', 'crop_width_px', 'print_width_px' ), true );
			$ph = self::pick_positive_metric( $side, array( 'height_px', 'crop_height_px', 'print_height_px' ), true );

			if ( is_array( $product_sides ) && isset( $product_sides[ $idx ] ) && is_array( $product_sides[ $idx ] ) ) {
				if ( $rw > 0 && $rh > 0 ) {
					$product_sides[ $idx ]['product_width']  = $rw;
					$product_sides[ $idx ]['product_height'] = $rh;
					$product_sides[ $idx ]['real_width']     = $rw;
					$product_sides[ $idx ]['real_height']    = $rh;
					$product_modified                        = true;
				}
				if ( $pw > 0 && $ph > 0 ) {
					$product_sides[ $idx ]['area_design_width']  = $pw;
					$product_sides[ $idx ]['area_design_height'] = $ph;
					$product_modified                            = true;
				}
			}

			if ( is_array( $config_data ) ) {
				if ( isset( $config_data['product'][ $idx ] ) && is_array( $config_data['product'][ $idx ] ) ) {
					if ( $rw > 0 && $rh > 0 ) {
						$config_data['product'][ $idx ]['product_width']  = $rw;
						$config_data['product'][ $idx ]['product_height'] = $rh;
						$config_data['product'][ $idx ]['real_width']     = $rw;
						$config_data['product'][ $idx ]['real_height']    = $rh;
						$config_modified                                  = true;
					}
					if ( $pw > 0 && $ph > 0 ) {
						$config_data['product'][ $idx ]['area_design_width']  = $pw;
						$config_data['product'][ $idx ]['area_design_height'] = $ph;
						$config_modified                                      = true;
					}
				}
				if ( 0 === $idx && $pw > 0 && $ph > 0 ) {
					$config_data['stage'] = array(
						'width'  => $pw,
						'height' => $ph,
					);
					$config_modified      = true;
				}
			}

			$out['sides'][ $side_id ] = array(
				'frame_index' => $idx,
				'svg'         => $side_svg_url,
				'print_file'  => $side_print_url,
			);
		}

		if ( $product_modified && is_array( $product_sides ) ) {
			@file_put_contents( $product_json_path, serialize( $product_sides ) );
		}
		if ( $config_modified && is_array( $config_data ) ) {
			@file_put_contents( $config_json_path, wp_json_encode( $config_data, JSON_PRETTY_PRINT ) );
		}

		return $out;
	}
}
