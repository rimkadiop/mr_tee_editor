<?php
/**
 * Mr. Tee Editor - Order Admin Panel & Export metabox
 *
 * Renders high-resolution download actions and design preview in WooCommerce
 * order screens (compatible with both HPOS and traditional CPT post types).
 *
 * @package MrTeeEditor\Commerce
 */

namespace MrTeeEditor\Commerce;

use MrTeeEditor\Export\PrintAssetGenerator;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class OrderAdminPanel {

	/**
	 * Register hooks.
	 */
	public static function init() {
		add_action( 'woocommerce_after_order_itemmeta', array( __CLASS__, 'render_order_item_actions' ), 10, 3 );
		add_action( 'add_meta_boxes', array( __CLASS__, 'register_order_metabox' ), 10, 2 );

		// WooCommerce Orders List status column (HPOS + legacy CPT).
		add_filter( 'manage_woocommerce_page_wc-orders_columns', array( __CLASS__, 'add_orders_list_column' ), 20 );
		add_action( 'manage_woocommerce_page_wc-orders_custom_column', array( __CLASS__, 'render_orders_list_column' ), 20, 2 );
		add_filter( 'manage_edit-shop_order_columns', array( __CLASS__, 'add_orders_list_column' ), 20 );
		add_action( 'manage_shop_order_posts_custom_column', array( __CLASS__, 'render_orders_list_column' ), 20, 2 );
	}

	/**
	 * Resolve active WooCommerce order edit screen ID (HPOS vs legacy CPT).
	 *
	 * @return string
	 */
	private static function get_order_screen_id() {
		$screen = 'shop_order';
		if (
			class_exists( '\Automattic\WooCommerce\Internal\DataStores\Orders\CustomOrdersTableController' ) &&
			function_exists( 'wc_get_container' ) &&
			function_exists( 'wc_get_page_screen_id' )
		) {
			$controller = wc_get_container()->get( \Automattic\WooCommerce\Internal\DataStores\Orders\CustomOrdersTableController::class );
			if ( $controller && $controller->custom_orders_table_usage_is_enabled() ) {
				$screen = wc_get_page_screen_id( 'shop-order' );
			}
		}
		return $screen;
	}

	/**
	 * Check whether an order contains at least one Mr. Tee Editor customized line item.
	 *
	 * @param \WC_Order $order WooCommerce order object.
	 * @return bool
	 */
	private static function order_has_mr_tee_items( $order ) {
		if ( ! $order || ! is_a( $order, '\WC_Order' ) ) {
			return false;
		}
		foreach ( $order->get_items() as $item ) {
			if ( is_object( $item ) && method_exists( $item, 'get_meta' ) ) {
				$data = $item->get_meta( '_mr_tee_editor_data', true );
				if ( ! empty( $data ) && is_array( $data ) ) {
					return true;
				}
			}
		}
		return false;
	}

	/**
	 * Register order export metabox ONLY on orders containing at least one Mr. Tee item.
	 *
	 * @param string                  $post_type     Current post type or HPOS screen ID.
	 * @param \WP_Post|\WC_Order|null $post_or_order Current post or WC_Order object.
	 */
	public static function register_order_metabox( $post_type = '', $post_or_order = null ) {
		$screen = self::get_order_screen_id();
		if ( '' !== $post_type && $post_type !== $screen ) {
			return;
		}

		$order = null;
		if ( $post_or_order instanceof \WC_Order ) {
			$order = $post_or_order;
		} elseif ( is_object( $post_or_order ) && ! empty( $post_or_order->ID ) && function_exists( 'wc_get_order' ) ) {
			$order = wc_get_order( absint( $post_or_order->ID ) );
		} elseif ( function_exists( 'wc_get_order' ) ) {
			$candidate_id = 0;
			if ( ! empty( $_GET['id'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
				$candidate_id = absint( $_GET['id'] ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			} elseif ( ! empty( $_GET['post'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
				$candidate_id = absint( $_GET['post'] ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			}
			if ( $candidate_id > 0 ) {
				$order = wc_get_order( $candidate_id );
			}
		}

		if ( ! $order || ! self::order_has_mr_tee_items( $order ) ) {
			return;
		}

		add_meta_box(
			'mr_tee_order_exports',
			__( 'M. TEE DESIGNER — Fichiers d\'Impression & Export Haute Résolution', 'mr-tee-editor' ),
			array( __CLASS__, 'render_order_metabox_content' ),
			$screen,
			'side',
			'high'
		);
	}

	/**
	 * Add Mr. Tee 300 DPI status column to WooCommerce Orders list (HPOS & legacy CPT).
	 *
	 * @param array<string, string> $columns Existing columns.
	 * @return array<string, string>
	 */
	public static function add_orders_list_column( $columns ) {
		if ( ! is_array( $columns ) ) {
			return $columns;
		}
		$new_columns = array();
		$inserted    = false;
		foreach ( $columns as $key => $label ) {
			$new_columns[ $key ] = $label;
			if ( 'order_status' === $key ) {
				$new_columns['mr_tee_design'] = __( 'Mr. Tee 300 DPI', 'mr-tee-editor' );
				$inserted                     = true;
			}
		}
		if ( ! $inserted ) {
			$new_columns['mr_tee_design'] = __( 'Mr. Tee 300 DPI', 'mr-tee-editor' );
		}
		return $new_columns;
	}

	/**
	 * Render thumbnail + 300 DPI readiness badge in WooCommerce Orders list column (HPOS & legacy CPT).
	 *
	 * @param string        $column           Column slug.
	 * @param \WC_Order|int $order_or_post_id Order object (HPOS) or Post ID (legacy CPT).
	 */
	public static function render_orders_list_column( $column, $order_or_post_id ) {
		if ( 'mr_tee_design' !== $column ) {
			return;
		}
		$order = ( $order_or_post_id instanceof \WC_Order )
			? $order_or_post_id
			: ( function_exists( 'wc_get_order' ) ? wc_get_order( absint( $order_or_post_id ) ) : false );

		if ( ! $order || ! is_a( $order, '\WC_Order' ) ) {
			echo '<span style="color:#9ca3af;">&mdash;</span>';
			return;
		}

		$total_items   = 0;
		$ready_items   = 0;
		$thumb_url     = '';

		foreach ( $order->get_items() as $item ) {
			if ( ! is_object( $item ) || ! method_exists( $item, 'get_meta' ) ) {
				continue;
			}
			$data = $item->get_meta( '_mr_tee_editor_data', true );
			if ( empty( $data ) || ! is_array( $data ) ) {
				continue;
			}
			$total_items++;
			$state = self::resolve_item_print_state( $data );
			if ( $state['has_print'] ) {
				$ready_items++;
			}
			if ( '' === $thumb_url ) {
				$previews = self::extract_previews( $data );
				if ( ! empty( $previews[0]['url'] ) ) {
					$thumb_url = $previews[0]['url'];
				}
			}
		}

		if ( 0 === $total_items ) {
			echo '<span style="color:#9ca3af;">&mdash;</span>';
			return;
		}

		$all_ready  = ( $ready_items === $total_items );
		$badge_bg   = $all_ready ? '#d1fae5' : '#dbeafe';
		$badge_fg   = $all_ready ? '#065f46' : '#1d4ed8';
		$badge_bd   = $all_ready ? '#a7f3d0' : '#bfdbfe';
		$badge_text = $all_ready
			? __( '300 DPI ✓', 'mr-tee-editor' )
			: __( 'À générer', 'mr-tee-editor' );
		if ( $total_items > 1 ) {
			$badge_text .= sprintf( ' (%d/%d)', $ready_items, $total_items );
		}
		?>
		<div class="mr-tee-order-col-status" style="display:inline-flex;align-items:center;gap:6px;">
			<?php if ( '' !== $thumb_url ) : ?>
				<img
					src="<?php echo esc_url( $thumb_url ); ?>"
					alt=""
					style="width:34px;height:34px;object-fit:contain;border-radius:4px;border:1px solid #d1d5db;background:#ffffff;display:block;flex-shrink:0;"
				/>
			<?php endif; ?>
			<span style="display:inline-block;padding:2px 7px;border-radius:999px;font-size:11px;font-weight:600;line-height:1.4;white-space:nowrap;background:<?php echo esc_attr( $badge_bg ); ?>;color:<?php echo esc_attr( $badge_fg ); ?>;border:1px solid <?php echo esc_attr( $badge_bd ); ?>;">
				<?php echo esc_html( $badge_text ); ?>
			</span>
		</div>
		<?php
	}

	/**
	 * Normalize same-host asset URLs to match the active HTTP/HTTPS request scheme,
	 * preserving external HTTPS CDN URLs untouched.
	 *
	 * @param string $url Raw URL.
	 * @return string
	 */
	private static function normalize_url( $url ) {
		if ( empty( $url ) || ! is_string( $url ) ) {
			return '';
		}
		$url = trim( $url );
		if ( 0 !== strpos( $url, 'http://' ) && 0 !== strpos( $url, 'https://' ) ) {
			return $url;
		}
		$site_host = strtolower( (string) wp_parse_url( home_url(), PHP_URL_HOST ) );
		$url_host  = strtolower( (string) wp_parse_url( $url, PHP_URL_HOST ) );
		if ( '' !== $site_host && $url_host === $site_host ) {
			return set_url_scheme( $url, is_ssl() ? 'https' : 'http' );
		}
		return $url;
	}

	/**
	 * Strictly validate that a URL belongs to this WordPress site (`home_url()` host).
	 *
	 * @param string $raw_url Raw URL or root-relative path.
	 * @return string Sanitized same-site URL, or empty string if off-site/invalid.
	 */
	private static function sanitize_same_site_url( $raw_url ) {
		if ( ! is_string( $raw_url ) ) {
			return '';
		}
		$url = trim( $raw_url );
		if ( '' === $url ) {
			return '';
		}
		if ( 0 === strpos( $url, '/' ) && 0 !== strpos( $url, '//' ) ) {
			if ( false !== strpos( $url, '..' ) || preg_match( '/[\r\n\0]/', $url ) ) {
				return '';
			}
			$url = rtrim( home_url(), '/' ) . $url;
		}
		if ( ! preg_match( '#^https?://#i', $url ) ) {
			return '';
		}
		$site_host = strtolower( (string) wp_parse_url( home_url(), PHP_URL_HOST ) );
		$url_host  = strtolower( (string) wp_parse_url( $url, PHP_URL_HOST ) );
		if ( '' === $site_host || $url_host !== $site_host ) {
			return '';
		}
		return self::normalize_url( esc_url_raw( $url ) );
	}

	/**
	 * Verify that an export file physically exists on disk and is non-empty.
	 *
	 * @param string $file_path Absolute disk path.
	 * @return bool
	 */
	private static function verify_file_exists( $file_path ) {
		return is_string( $file_path ) && '' !== $file_path && file_exists( $file_path ) && filesize( $file_path ) > 0;
	}

	/**
	 * Resolve a verified on-disk file path for a PNG/SVG export, falling back to `designs/{design_key}/`
	 * when only a URL is available on legacy order items.
	 *
	 * @param string $explicit_file Recorded disk path (if any).
	 * @param string $url           Recorded file URL.
	 * @param string $design_key    Item design key.
	 * @param string $kind          'png' | 'svg'
	 * @return string Verified disk path, or empty string if missing.
	 */
	private static function resolve_verified_export_file( $explicit_file, $url, $design_key, $kind ) {
		if ( self::verify_file_exists( $explicit_file ) ) {
			return (string) $explicit_file;
		}
		$clean_key = preg_replace( '/[^a-zA-Z0-9_-]/', '', (string) $design_key );
		if ( '' === $clean_key || ! preg_match( '/^mrtee_[a-zA-Z0-9_-]{8,64}$/', $clean_key ) ) {
			return '';
		}
		$url_path = is_string( $url ) ? wp_parse_url( $url, PHP_URL_PATH ) : '';
		$base     = is_string( $url_path ) ? basename( $url_path ) : '';
		$pattern  = ( 'svg' === $kind )
			? '/^design(?:_[a-zA-Z0-9_-]{1,32})?\.svg$/i'
			: '/^print_300dpi(?:_[a-zA-Z0-9_-]{1,32})?\.png$/i';
		if ( '' === $base || ! preg_match( $pattern, $base ) ) {
			return '';
		}
		$paths     = PrintAssetGenerator::get_upload_dir( 'designs/' . $clean_key );
		$candidate = trailingslashit( $paths['dir'] ) . $base;
		return self::verify_file_exists( $candidate ) ? $candidate : '';
	}

	/**
	 * Append a `?v=<filemtime>` cache-buster to a normalized same-site download URL.
	 *
	 * @param string $raw_url   Raw URL.
	 * @param string $file_path Verified on-disk file path.
	 * @return string
	 */
	private static function with_file_cache_buster( $raw_url, $file_path ) {
		$url = self::normalize_url( $raw_url );
		if ( '' === $url ) {
			return '';
		}
		if ( self::verify_file_exists( $file_path ) ) {
			$mtime = @filemtime( $file_path );
			if ( $mtime ) {
				$url = add_query_arg( 'v', (string) $mtime, $url );
			}
		}
		return $url;
	}

	/**
	 * Determine which print side IDs in an order item payload actually contain printable artwork
	 * so `$has_print` verifies every active side has been generated on disk.
	 *
	 * @param array<string, mixed> $data Order item `_mr_tee_editor_data`.
	 * @return array<int, string>
	 */
	private static function resolve_expected_side_ids( array $data ) {
		$payload     = ( ! empty( $data['payload'] ) && is_array( $data['payload'] ) ) ? $data['payload'] : array();
		$print_sides = ( ! empty( $payload['print_sides'] ) && is_array( $payload['print_sides'] ) )
			? array_values( $payload['print_sides'] )
			: array();

		if ( count( $print_sides ) <= 1 ) {
			$first_id = ! empty( $print_sides[0]['id'] ) ? (string) $print_sides[0]['id'] : 'front';
			return array( $first_id );
		}

		$primary_id   = ! empty( $print_sides[0]['id'] ) ? (string) $print_sides[0]['id'] : 'front';
		$active_sides = array( $primary_id => true );

		// Mark any secondary side referenced by a non-empty text or image field.
		if ( ! empty( $payload['text_lines'] ) && is_array( $payload['text_lines'] ) ) {
			foreach ( $payload['text_lines'] as $line ) {
				if ( ! is_array( $line ) ) {
					continue;
				}
				$sid      = ! empty( $line['sideId'] ) ? (string) $line['sideId'] : ( ! empty( $line['side_id'] ) ? (string) $line['side_id'] : $primary_id );
				$is_image = isset( $line['fieldType'] ) && 'image' === $line['fieldType'];
				if ( $is_image ) {
					if ( ! empty( $line['imageUrl'] ) ) {
						$active_sides[ $sid ] = true;
					}
				} else {
					$val = isset( $line['value'] ) ? (string) $line['value'] : '';
					if ( '' !== trim( $val ) ) {
						$active_sides[ $sid ] = true;
					}
				}
			}
		}

		// Also inspect scene-graph layers (e.g., PATH cliparts or reference-only nodes on secondary sides).
		if ( ! empty( $payload['perso_visual']['layers'] ) && is_array( $payload['perso_visual']['layers'] ) ) {
			$ref_map  = array();
			$refs_raw = ( ! empty( $payload['perso_visual']['references'] ) && is_array( $payload['perso_visual']['references'] ) )
				? $payload['perso_visual']['references']
				: array();
			$index_refs = static function ( $nodes ) use ( &$index_refs, &$ref_map ) {
				foreach ( $nodes as $r ) {
					if ( ! is_array( $r ) ) {
						continue;
					}
					if ( ! empty( $r['id'] ) ) {
						$ref_map[ (string) $r['id'] ] = $r;
					}
					if ( ! empty( $r['items'] ) && is_array( $r['items'] ) ) {
						$index_refs( $r['items'] );
					}
				}
			};
			$index_refs( $refs_raw );

			$walk_layers = static function ( $nodes, $inherited_side = null ) use ( &$walk_layers, &$active_sides, $ref_map, $primary_id ) {
				foreach ( $nodes as $node ) {
					if ( ! is_array( $node ) || ! empty( $node['hide'] ) ) {
						continue;
					}
					$n_side = ! empty( $node['side_id'] )
						? (string) $node['side_id']
						: ( ! empty( $node['sideId'] ) ? (string) $node['sideId'] : $inherited_side );
					$type   = isset( $node['type'] ) ? (string) $node['type'] : '';
					if ( 'GROUP' === $type || 'MASK' === $type ) {
						if ( ! empty( $node['items'] ) && is_array( $node['items'] ) ) {
							$walk_layers( $node['items'], $n_side );
						}
						continue;
					}
					$eff_side = $n_side ? $n_side : $primary_id;
					$ref      = ( ! empty( $node['reference'] ) && isset( $ref_map[ (string) $node['reference'] ] ) )
						? $ref_map[ (string) $node['reference'] ]
						: null;
					if ( 'TEXT' === $type ) {
						$val = ( $ref && isset( $ref['value'] ) ) ? (string) $ref['value'] : ( isset( $node['value'] ) ? (string) $node['value'] : ( isset( $node['text'] ) ? (string) $node['text'] : '' ) );
						if ( '' !== trim( $val ) ) {
							$active_sides[ $eff_side ] = true;
						}
					} elseif ( 'IMAGE' === $type ) {
						$url = ( $ref && ! empty( $ref['url'] ) ) ? (string) $ref['url'] : ( ! empty( $node['url'] ) ? (string) $node['url'] : '' );
						if ( '' !== trim( $url ) ) {
							$active_sides[ $eff_side ] = true;
						}
					} elseif ( 'PATH' === $type && ! empty( $node['path'] ) ) {
						$active_sides[ $eff_side ] = true;
					}
				}
			};
			$walk_layers( $payload['perso_visual']['layers'], null );
		}

		$ordered = array();
		foreach ( $print_sides as $side ) {
			if ( ! is_array( $side ) || empty( $side['id'] ) ) {
				continue;
			}
			$sid = (string) $side['id'];
			if ( isset( $active_sides[ $sid ] ) ) {
				$ordered[] = $sid;
			}
		}

		return ! empty( $ordered ) ? $ordered : array( $primary_id );
	}

	/**
	 * Extract customer-uploaded image fields from order item payload (strictly same-site URLs only).
	 *
	 * @param array<string, mixed> $data Order item _mr_tee_editor_data.
	 * @return array<int, array<string, string>>
	 */
	private static function extract_customer_images( array $data ) {
		$images = array();
		if ( ! empty( $data['payload']['text_lines'] ) && is_array( $data['payload']['text_lines'] ) ) {
			foreach ( $data['payload']['text_lines'] as $idx => $line ) {
				if ( isset( $line['fieldType'] ) && 'image' === $line['fieldType'] && ! empty( $line['imageUrl'] ) ) {
					$url = self::sanitize_same_site_url( (string) $line['imageUrl'] );
					if ( '' !== $url ) {
						$images[] = array(
							'label' => ! empty( $line['label'] ) ? sanitize_text_field( (string) $line['label'] ) : sprintf( __( 'Image #%d', 'mr-tee-editor' ), $idx + 1 ),
							'url'   => $url,
						);
					}
				}
			}
		}
		return $images;
	}

	/**
	 * Extract normalized mockup previews from order item data.
	 *
	 * @param array<string, mixed> $data Order item _mr_tee_editor_data.
	 * @return array<int, array{label: string, url: string}>
	 */
	private static function extract_previews( array $data ) {
		$previews = array();
		$seen     = array();
		if ( ! empty( $data['previews'] ) && is_array( $data['previews'] ) ) {
			foreach ( $data['previews'] as $idx => $item ) {
				if ( ! is_array( $item ) || empty( $item['url'] ) ) {
					continue;
				}
				$url = self::normalize_url( $item['url'] );
				if ( '' === $url || isset( $seen[ $url ] ) ) {
					continue;
				}
				$seen[ $url ] = true;
				$previews[]   = array(
					'label' => ! empty( $item['label'] ) ? (string) $item['label'] : sprintf( __( 'Vue %d', 'mr-tee-editor' ), $idx + 1 ),
					'url'   => $url,
				);
			}
		}
		if ( empty( $previews ) && ! empty( $data['preview_url'] ) ) {
			$url = self::normalize_url( $data['preview_url'] );
			if ( '' !== $url ) {
				$previews[] = array(
					'label' => '',
					'url'   => $url,
				);
			}
		}
		return $previews;
	}

	/**
	 * Extract verified multi-side print export entries from order item data with `?v=<filemtime>` cache-busters.
	 *
	 * @param array<string, mixed> $data Order item _mr_tee_editor_data.
	 * @return array<int, array{side_id: string, label: string, print_url: string, svg_url: string, print_exists: bool, svg_exists: bool, print_dimensions: string}>
	 */
	private static function extract_side_exports( array $data ) {
		$sides      = array();
		$design_key = ! empty( $data['key'] ) ? (string) $data['key'] : '';
		if ( ! empty( $data['side_exports'] ) && is_array( $data['side_exports'] ) ) {
			foreach ( $data['side_exports'] as $key => $entry ) {
				if ( ! is_array( $entry ) ) {
					continue;
				}
				$raw_p_url  = ! empty( $entry['print_url'] ) ? (string) $entry['print_url'] : '';
				$raw_s_url  = ! empty( $entry['svg_url'] ) ? (string) $entry['svg_url'] : '';
				$p_file     = self::resolve_verified_export_file( isset( $entry['print_file'] ) ? (string) $entry['print_file'] : '', $raw_p_url, $design_key, 'png' );
				$s_file     = self::resolve_verified_export_file( isset( $entry['svg_file'] ) ? (string) $entry['svg_file'] : '', $raw_s_url, $design_key, 'svg' );
				$p_exists   = ( '' !== $p_file );
				$s_exists   = ( '' !== $s_file );
				$p_url      = ( $p_exists && '' !== $raw_p_url ) ? self::with_file_cache_buster( $raw_p_url, $p_file ) : '';
				$s_url      = ( $s_exists && '' !== $raw_s_url ) ? self::with_file_cache_buster( $raw_s_url, $s_file ) : '';

				if ( '' === $p_url && '' === $s_url ) {
					continue;
				}
				$side_id = ! empty( $entry['side_id'] ) ? (string) $entry['side_id'] : ( is_string( $key ) ? $key : 'side_' . count( $sides ) );
				$label   = ! empty( $entry['label'] ) ? (string) $entry['label'] : ucfirst( $side_id );
				$sides[] = array(
					'side_id'          => $side_id,
					'label'            => $label,
					'print_url'        => $p_url,
					'svg_url'          => $s_url,
					'print_exists'     => $p_exists,
					'svg_exists'       => $s_exists,
					'print_dimensions' => ! empty( $entry['print_dimensions'] ) ? (string) $entry['print_dimensions'] : '',
				);
			}
		}
		return $sides;
	}

	/**
	 * Verify on-disk existence & completeness of all required print sides for an order item,
	 * returning cache-busted download URLs and accurate `$has_print` status.
	 *
	 * @param array<string, mixed> $data Order item `_mr_tee_editor_data`.
	 * @return array{has_print: bool, print_url: string, svg_url: string, side_exports: array<int, array<string, mixed>>, is_multi_sides: bool}
	 */
	private static function resolve_item_print_state( array $data ) {
		$design_key = ! empty( $data['key'] ) ? (string) $data['key'] : '';
		$raw_p_url  = ! empty( $data['print_url'] ) ? (string) $data['print_url'] : '';
		$raw_s_url  = ! empty( $data['svg_url'] ) ? (string) $data['svg_url'] : '';

		$p_file   = self::resolve_verified_export_file( isset( $data['print_file'] ) ? (string) $data['print_file'] : '', $raw_p_url, $design_key, 'png' );
		$s_file   = self::resolve_verified_export_file( isset( $data['svg_file'] ) ? (string) $data['svg_file'] : '', $raw_s_url, $design_key, 'svg' );
		$p_exists = ( '' !== $p_file );
		$s_exists = ( '' !== $s_file );

		$print_url = ( $p_exists && '' !== $raw_p_url ) ? self::with_file_cache_buster( $raw_p_url, $p_file ) : '';
		$svg_url   = ( $s_exists && '' !== $raw_s_url ) ? self::with_file_cache_buster( $raw_s_url, $s_file ) : '';

		$side_exports   = self::extract_side_exports( $data );
		$is_multi_sides = count( $side_exports ) > 1;
		$expected_sides = self::resolve_expected_side_ids( $data );

		$sides_by_id = array();
		foreach ( $side_exports as $s_exp ) {
			$sides_by_id[ $s_exp['side_id'] ] = $s_exp;
		}

		if ( count( $expected_sides ) > 1 ) {
			$has_print = true;
			foreach ( $expected_sides as $exp_sid ) {
				if (
					! isset( $sides_by_id[ $exp_sid ] ) ||
					empty( $sides_by_id[ $exp_sid ]['print_exists'] ) ||
					empty( $sides_by_id[ $exp_sid ]['svg_exists'] )
				) {
					$has_print = false;
					break;
				}
			}
		} elseif ( ! empty( $side_exports ) ) {
			$has_print = true;
			foreach ( $side_exports as $s_exp ) {
				if ( empty( $s_exp['print_exists'] ) || empty( $s_exp['svg_exists'] ) ) {
					$has_print = false;
					break;
				}
			}
		} else {
			$has_print = ( $p_exists && $s_exists && '' !== $print_url && '' !== $svg_url );
		}

		return array(
			'has_print'      => $has_print,
			'print_url'      => $print_url,
			'svg_url'        => $svg_url,
			'side_exports'   => $side_exports,
			'is_multi_sides' => $is_multi_sides,
		);
	}

	/**
	 * Render download links and on-demand high-res print export controls directly under the order line item.
	 *
	 * @param int                    $item_id Order item ID.
	 * @param \WC_Order_Item_Product $item    Order item object.
	 * @param \WC_Product            $product Product object.
	 */
	public static function render_order_item_actions( $item_id, $item, $product ) {
		if ( ! is_admin() ) {
			return;
		}
		if ( ! is_object( $item ) || ! method_exists( $item, 'get_meta' ) ) {
			return;
		}
		$data = $item->get_meta( '_mr_tee_editor_data' );
		if ( empty( $data ) || ! is_array( $data ) ) {
			return;
		}

		$order_id          = method_exists( $item, 'get_order_id' ) ? (int) $item->get_order_id() : 0;
		$design_key        = ! empty( $data['key'] ) ? $data['key'] : '';
		$print_state       = self::resolve_item_print_state( $data );
		$print_url         = $print_state['print_url'];
		$svg_url           = $print_state['svg_url'];
		$side_exports      = $print_state['side_exports'];
		$is_multi_sides    = $print_state['is_multi_sides'];
		$has_print         = $print_state['has_print'];
		$preview_url       = ! empty( $data['preview_url'] ) ? self::normalize_url( $data['preview_url'] ) : '';
		$previews          = self::extract_previews( $data );
		$is_multi_previews = count( $previews ) > 1;
		$cust_images       = self::extract_customer_images( $data );

		?>
		<div
			class="mr-tee-order-item-export"
			data-order-id="<?php echo esc_attr( $order_id ); ?>"
			data-item-id="<?php echo esc_attr( $item_id ); ?>"
			data-design-key="<?php echo esc_attr( $design_key ); ?>"
			data-has-print="<?php echo $has_print ? '1' : '0'; ?>"
			style="margin-top:10px;padding:10px 12px;background:#f9fafb;border:1px solid #e5e7eb;border-radius:6px;"
		>
			<strong style="display:block;margin-bottom:6px;color:#111827;font-size:12px;">
				<?php esc_html_e( 'Fichiers Mr. Tee Designer :', 'mr-tee-editor' ); ?>
			</strong>
			<?php if ( $is_multi_sides ) : ?>
				<?php
				$dim_parts = array();
				foreach ( $side_exports as $s_exp ) {
					if ( ! empty( $s_exp['print_dimensions'] ) ) {
						$dim_parts[] = $s_exp['label'] . ' : ' . $s_exp['print_dimensions'];
					}
				}
				?>
				<?php if ( ! empty( $dim_parts ) ) : ?>
					<div class="mr-tee-print-dimensions" style="margin-bottom:8px;font-size:11.5px;color:#4b5563;">
						<strong><?php esc_html_e( "Dimensions d'impression :", 'mr-tee-editor' ); ?></strong>
						<span><?php echo esc_html( implode( ' | ', $dim_parts ) ); ?></span>
					</div>
				<?php elseif ( ! empty( $data['print_dimensions'] ) ) : ?>
					<div class="mr-tee-print-dimensions" style="margin-bottom:8px;font-size:11.5px;color:#4b5563;">
						<strong><?php esc_html_e( "Dimensions d'impression :", 'mr-tee-editor' ); ?></strong>
						<span><?php echo esc_html( $data['print_dimensions'] ); ?></span>
					</div>
				<?php endif; ?>
			<?php elseif ( ! empty( $data['print_dimensions'] ) ) : ?>
				<div class="mr-tee-print-dimensions" style="margin-bottom:8px;font-size:11.5px;color:#4b5563;">
					<strong><?php esc_html_e( "Dimensions d'impression :", 'mr-tee-editor' ); ?></strong>
					<span><?php echo esc_html( $data['print_dimensions'] ); ?></span>
				</div>
			<?php endif; ?>
			<?php if ( $is_multi_previews ) : ?>
				<div style="margin-bottom:8px;display:flex;align-items:flex-start;gap:12px;flex-wrap:wrap;">
					<?php foreach ( $previews as $pv ) : ?>
						<div style="display:flex;flex-direction:column;align-items:center;gap:4px;">
							<a href="<?php echo esc_url( $pv['url'] ); ?>" target="_blank" rel="noopener noreferrer">
								<img src="<?php echo esc_url( $pv['url'] ); ?>" alt="<?php echo esc_attr( $pv['label'] ? $pv['label'] : 'Mockup Preview' ); ?>" style="width:68px;height:auto;max-height:90px;object-fit:contain;border-radius:5px;border:1px solid #d1d5db;background:#fff;display:block;" />
							</a>
							<?php if ( ! empty( $pv['label'] ) ) : ?>
								<span style="font-size:11px;font-weight:600;color:#374151;"><?php echo esc_html( $pv['label'] ); ?></span>
							<?php endif; ?>
						</div>
					<?php endforeach; ?>
				</div>
			<?php elseif ( ! empty( $preview_url ) ) : ?>
				<div style="margin-bottom:8px;display:flex;align-items:center;gap:10px;">
					<a href="<?php echo esc_url( $preview_url ); ?>" target="_blank" rel="noopener noreferrer">
						<img src="<?php echo esc_url( $preview_url ); ?>" alt="Mockup Preview" style="width:68px;height:auto;max-height:90px;object-fit:contain;border-radius:5px;border:1px solid #d1d5db;background:#fff;display:block;" />
					</a>
					<span style="font-size:12px;color:#374151;"><?php esc_html_e( 'Aperçu complet (Produit + Design)', 'mr-tee-editor' ); ?></span>
				</div>
			<?php endif; ?>
			<div class="mr-tee-print-status" style="margin-bottom:8px;font-size:12px;color:<?php echo $has_print ? '#065f46' : '#1d4ed8'; ?>;font-weight:600;">
				<?php
				if ( $has_print ) {
					esc_html_e( 'Fichiers d\'impression haute résolution (300 DPI & SVG) prêts.', 'mr-tee-editor' );
				} else {
					esc_html_e( 'Fichiers 300 DPI & SVG en attente de génération côté admin...', 'mr-tee-editor' );
				}
				?>
			</div>
			<div class="mr-tee-print-actions" style="display:flex;gap:8px;flex-wrap:wrap;align-items:center;">
				<?php if ( $is_multi_sides ) : ?>
					<?php foreach ( $side_exports as $s_exp ) : ?>
						<?php if ( ! empty( $s_exp['print_url'] ) ) : ?>
							<a
								href="<?php echo esc_url( $s_exp['print_url'] ); ?>"
								target="_blank"
								class="button button-primary button-small mr-tee-btn-side-export mr-tee-btn-side-png"
								data-side-id="<?php echo esc_attr( $s_exp['side_id'] ); ?>"
								download
							>
								<span class="dashicons dashicons-download" style="vertical-align:middle;font-size:14px;width:14px;height:14px;"></span>
								<?php echo esc_html( sprintf( __( '%s — PNG 300 DPI', 'mr-tee-editor' ), $s_exp['label'] ) ); ?>
							</a>
						<?php endif; ?>
						<?php if ( ! empty( $s_exp['svg_url'] ) ) : ?>
							<a
								href="<?php echo esc_url( $s_exp['svg_url'] ); ?>"
								target="_blank"
								class="button button-secondary button-small mr-tee-btn-side-export mr-tee-btn-side-svg"
								data-side-id="<?php echo esc_attr( $s_exp['side_id'] ); ?>"
								download
							>
								<span class="dashicons dashicons-media-code" style="vertical-align:middle;font-size:14px;width:14px;height:14px;"></span>
								<?php echo esc_html( sprintf( __( '%s — Vector SVG', 'mr-tee-editor' ), $s_exp['label'] ) ); ?>
							</a>
						<?php endif; ?>
					<?php endforeach; ?>
				<?php endif; ?>

				<a
					href="<?php echo esc_url( $print_url ? $print_url : '#' ); ?>"
					target="_blank"
					class="button button-primary button-small mr-tee-btn-print-png"
					download
					style="<?php echo ( $is_multi_sides || empty( $print_url ) ) ? 'display:none;' : ''; ?>"
				>
					<span class="dashicons dashicons-download" style="vertical-align:middle;font-size:14px;width:14px;height:14px;"></span>
					<?php esc_html_e( 'PNG 300 DPI (Sans fond)', 'mr-tee-editor' ); ?>
				</a>

				<a
					href="<?php echo esc_url( $svg_url ? $svg_url : '#' ); ?>"
					target="_blank"
					class="button button-secondary button-small mr-tee-btn-print-svg"
					download
					style="<?php echo ( $is_multi_sides || empty( $svg_url ) ) ? 'display:none;' : ''; ?>"
				>
					<span class="dashicons dashicons-media-code" style="vertical-align:middle;font-size:14px;width:14px;height:14px;"></span>
					<?php esc_html_e( 'Vector SVG', 'mr-tee-editor' ); ?>
				</a>

				<button
					type="button"
					class="button button-secondary button-small mr-tee-admin-gen-print-btn"
					data-order-id="<?php echo esc_attr( $order_id ); ?>"
					data-item-id="<?php echo esc_attr( $item_id ); ?>"
					data-design-key="<?php echo esc_attr( $design_key ); ?>"
				>
					<span class="dashicons dashicons-update" style="vertical-align:middle;font-size:14px;width:14px;height:14px;"></span>
					<?php echo $has_print ? esc_html__( 'Régénérer 300 DPI & SVG', 'mr-tee-editor' ) : esc_html__( 'Générer 300 DPI & SVG', 'mr-tee-editor' ); ?>
				</button>

				<?php if ( $is_multi_previews ) : ?>
					<?php foreach ( $previews as $pv ) : ?>
						<a href="<?php echo esc_url( $pv['url'] ); ?>" target="_blank" class="button button-secondary button-small" download>
							<span class="dashicons dashicons-visibility" style="vertical-align:middle;font-size:14px;width:14px;height:14px;"></span>
							<?php echo esc_html( sprintf( __( 'Aperçu %s', 'mr-tee-editor' ), $pv['label'] ) ); ?>
						</a>
					<?php endforeach; ?>
				<?php elseif ( ! empty( $preview_url ) ) : ?>
					<a href="<?php echo esc_url( $preview_url ); ?>" target="_blank" class="button button-secondary button-small" download>
						<span class="dashicons dashicons-visibility" style="vertical-align:middle;font-size:14px;width:14px;height:14px;"></span>
						<?php esc_html_e( 'Aperçu Mockup', 'mr-tee-editor' ); ?>
					</a>
				<?php endif; ?>

				<?php foreach ( $cust_images as $c_img ) : ?>
					<a href="<?php echo esc_url( $c_img['url'] ); ?>" target="_blank" class="button button-secondary button-small" download>
						<span class="dashicons dashicons-format-image" style="vertical-align:middle;font-size:14px;width:14px;height:14px;"></span>
						<?php echo esc_html( $c_img['label'] ); ?>
					</a>
				<?php endforeach; ?>
			</div>
		</div>
		<?php
	}

	/**
	 * Render sidebar order metabox.
	 *
	 * @param \WP_Post|\WC_Order $post_or_order Post or Order object.
	 */
	public static function render_order_metabox_content( $post_or_order ) {
		$order = ( $post_or_order instanceof \WC_Order ) ? $post_or_order : wc_get_order( $post_or_order->ID );
		if ( ! $order ) {
			echo '<p>' . esc_html__( 'Commande introuvable.', 'mr-tee-editor' ) . '</p>';
			return;
		}

		$order_id = (int) $order->get_id();
		$found    = false;
		foreach ( $order->get_items() as $item_id => $item ) {
			$data = $item->get_meta( '_mr_tee_editor_data' );
			if ( empty( $data ) || ! is_array( $data ) ) {
				continue;
			}
			$found             = true;
			$design_key        = ! empty( $data['key'] ) ? $data['key'] : '';
			$print_state       = self::resolve_item_print_state( $data );
			$print_url         = $print_state['print_url'];
			$svg_url           = $print_state['svg_url'];
			$side_exports      = $print_state['side_exports'];
			$is_multi_sides    = $print_state['is_multi_sides'];
			$has_print         = $print_state['has_print'];
			$preview_url       = ! empty( $data['preview_url'] ) ? self::normalize_url( $data['preview_url'] ) : '';
			$previews          = self::extract_previews( $data );
			$is_multi_previews = count( $previews ) > 1;
			$cust_images       = self::extract_customer_images( $data );
			?>
			<div
				class="mr-tee-metabox-item"
				data-order-id="<?php echo esc_attr( $order_id ); ?>"
				data-item-id="<?php echo esc_attr( $item_id ); ?>"
				data-design-key="<?php echo esc_attr( $design_key ); ?>"
				data-has-print="<?php echo $has_print ? '1' : '0'; ?>"
				style="margin-bottom:15px;padding-bottom:12px;border-bottom:1px solid #e5e7eb;"
			>
				<h4 style="margin:0 0 8px 0;font-size:13px;"><?php echo esc_html( $item->get_name() ); ?></h4>
				<?php if ( $is_multi_previews ) : ?>
					<div style="display:flex;justify-content:center;gap:8px;flex-wrap:wrap;margin-bottom:10px;">
						<?php foreach ( $previews as $pv ) : ?>
							<div style="text-align:center;">
								<a href="<?php echo esc_url( $pv['url'] ); ?>" target="_blank" rel="noopener noreferrer">
									<img src="<?php echo esc_url( $pv['url'] ); ?>" alt="<?php echo esc_attr( $pv['label'] ? $pv['label'] : 'Preview' ); ?>" style="max-width:108px;border-radius:6px;border:1px solid #d1d5db;box-shadow:0 1px 3px rgba(0,0,0,0.1);display:block;" />
								</a>
								<?php if ( ! empty( $pv['label'] ) ) : ?>
									<span style="display:block;margin-top:3px;font-size:11px;font-weight:600;color:#374151;"><?php echo esc_html( $pv['label'] ); ?></span>
								<?php endif; ?>
							</div>
						<?php endforeach; ?>
					</div>
				<?php elseif ( ! empty( $preview_url ) ) : ?>
					<div style="text-align:center;margin-bottom:10px;">
						<a href="<?php echo esc_url( $preview_url ); ?>" target="_blank" rel="noopener noreferrer">
							<img src="<?php echo esc_url( $preview_url ); ?>" alt="Preview" style="max-width:140px;border-radius:6px;border:1px solid #d1d5db;box-shadow:0 1px 3px rgba(0,0,0,0.1);" />
						</a>
					</div>
				<?php endif; ?>
				<ul style="margin:0 0 10px 0;padding-left:15px;font-size:12px;color:#4b5563;">
					<li><strong><?php esc_html_e( 'Clé de design :', 'mr-tee-editor' ); ?></strong> <code><?php echo esc_html( $design_key ); ?></code></li>
					<?php if ( ! empty( $data['nbd']['key'] ) ) : ?>
						<li><strong><?php esc_html_e( 'Dossier NBDesigner :', 'mr-tee-editor' ); ?></strong> <code><?php echo esc_html( $data['nbd']['key'] ); ?></code></li>
					<?php endif; ?>
				</ul>
				<div class="mr-tee-metabox-status" style="margin-bottom:8px;font-size:11.5px;font-weight:600;color:<?php echo $has_print ? '#065f46' : '#1d4ed8'; ?>;">
					<?php echo $has_print ? esc_html__( '300 DPI & SVG prêts', 'mr-tee-editor' ) : esc_html__( 'Génération 300 DPI & SVG requise', 'mr-tee-editor' ); ?>
				</div>
				<div class="mr-tee-metabox-actions" style="display:flex;flex-direction:column;gap:6px;">
					<?php if ( $is_multi_sides ) : ?>
						<?php foreach ( $side_exports as $s_exp ) : ?>
							<?php if ( ! empty( $s_exp['print_url'] ) ) : ?>
								<a
									href="<?php echo esc_url( $s_exp['print_url'] ); ?>"
									target="_blank"
									class="button button-primary mr-tee-btn-side-export mr-tee-btn-side-png"
									data-side-id="<?php echo esc_attr( $s_exp['side_id'] ); ?>"
									download
									style="text-align:center;"
								>
									<?php echo esc_html( sprintf( __( '%s — PNG 300 DPI', 'mr-tee-editor' ), $s_exp['label'] ) ); ?>
								</a>
							<?php endif; ?>
							<?php if ( ! empty( $s_exp['svg_url'] ) ) : ?>
								<a
									href="<?php echo esc_url( $s_exp['svg_url'] ); ?>"
									target="_blank"
									class="button mr-tee-btn-side-export mr-tee-btn-side-svg"
									data-side-id="<?php echo esc_attr( $s_exp['side_id'] ); ?>"
									download
									style="text-align:center;"
								>
									<?php echo esc_html( sprintf( __( '%s — Vector SVG', 'mr-tee-editor' ), $s_exp['label'] ) ); ?>
								</a>
							<?php endif; ?>
						<?php endforeach; ?>
					<?php endif; ?>

					<a
						href="<?php echo esc_url( $print_url ? $print_url : '#' ); ?>"
						target="_blank"
						class="button button-primary mr-tee-btn-print-png"
						download
						style="text-align:center;<?php echo ( $is_multi_sides || empty( $print_url ) ) ? 'display:none;' : ''; ?>"
					>
						<?php esc_html_e( 'Télécharger PNG 300 DPI', 'mr-tee-editor' ); ?>
					</a>
					<a
						href="<?php echo esc_url( $svg_url ? $svg_url : '#' ); ?>"
						target="_blank"
						class="button mr-tee-btn-print-svg"
						download
						style="text-align:center;<?php echo ( $is_multi_sides || empty( $svg_url ) ) ? 'display:none;' : ''; ?>"
					>
						<?php esc_html_e( 'Télécharger Vector SVG', 'mr-tee-editor' ); ?>
					</a>
					<button
						type="button"
						class="button mr-tee-admin-gen-print-btn"
						data-order-id="<?php echo esc_attr( $order_id ); ?>"
						data-item-id="<?php echo esc_attr( $item_id ); ?>"
						data-design-key="<?php echo esc_attr( $design_key ); ?>"
						style="text-align:center;"
					>
						<?php echo $has_print ? esc_html__( 'Régénérer 300 DPI & SVG', 'mr-tee-editor' ) : esc_html__( 'Générer 300 DPI & SVG', 'mr-tee-editor' ); ?>
					</button>
					<?php if ( $is_multi_previews ) : ?>
						<?php foreach ( $previews as $pv ) : ?>
							<a href="<?php echo esc_url( $pv['url'] ); ?>" target="_blank" class="button" download style="text-align:center;">
								<?php echo esc_html( sprintf( __( 'Télécharger Aperçu %s', 'mr-tee-editor' ), $pv['label'] ) ); ?>
							</a>
						<?php endforeach; ?>
					<?php elseif ( ! empty( $preview_url ) ) : ?>
						<a href="<?php echo esc_url( $preview_url ); ?>" target="_blank" class="button" download style="text-align:center;">
							<?php esc_html_e( 'Télécharger Aperçu Mockup', 'mr-tee-editor' ); ?>
						</a>
					<?php endif; ?>
					<?php foreach ( $cust_images as $c_img ) : ?>
						<a href="<?php echo esc_url( $c_img['url'] ); ?>" target="_blank" class="button" download style="text-align:center;">
							<?php echo esc_html( sprintf( __( 'Télécharger %s', 'mr-tee-editor' ), $c_img['label'] ) ); ?>
						</a>
					<?php endforeach; ?>
				</div>
			</div>
			<?php
		}

		if ( ! $found ) {
			echo '<p style="color:#6b7280;font-size:13px;">' . esc_html__( 'Aucun article personnalisé avec Mr. Tee Editor dans cette commande.', 'mr-tee-editor' ) . '</p>';
		}
	}
}
