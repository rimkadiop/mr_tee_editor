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

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class OrderAdminPanel {

	/**
	 * Register hooks.
	 */
	public static function init() {
		add_action( 'woocommerce_after_order_itemmeta', array( __CLASS__, 'render_order_item_actions' ), 10, 3 );
		add_action( 'add_meta_boxes', array( __CLASS__, 'register_order_metabox' ) );
	}

	/**
	 * Register order export metabox.
	 */
	public static function register_order_metabox() {
		$screen = class_exists( '\Automattic\WooCommerce\Internal\DataStores\Orders\CustomOrdersTableController' ) &&
			wc_get_container()->get( \Automattic\WooCommerce\Internal\DataStores\Orders\CustomOrdersTableController::class )->custom_orders_table_usage_is_enabled()
			? wc_get_page_screen_id( 'shop-order' )
			: 'shop_order';

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
	 * Normalize same-site asset URLs to match the active HTTP/HTTPS request scheme.
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
		return set_url_scheme( $url, is_ssl() ? 'https' : 'http' );
	}

	/**
	 * Extract customer-uploaded image fields from order item payload.
	 *
	 * @param array<string, mixed> $data Order item _mr_tee_editor_data.
	 * @return array<int, array<string, string>>
	 */
	private static function extract_customer_images( array $data ) {
		$images = array();
		if ( ! empty( $data['payload']['text_lines'] ) && is_array( $data['payload']['text_lines'] ) ) {
			foreach ( $data['payload']['text_lines'] as $idx => $line ) {
				if ( isset( $line['fieldType'] ) && 'image' === $line['fieldType'] && ! empty( $line['imageUrl'] ) ) {
					$url = self::normalize_url( $line['imageUrl'] );
					if ( ! empty( $url ) && 0 === strpos( $url, 'http' ) ) {
						$images[] = array(
							'label' => ! empty( $line['label'] ) ? $line['label'] : sprintf( __( 'Image #%d', 'mr-tee-editor' ), $idx + 1 ),
							'url'   => $url,
						);
					}
				}
			}
		}
		return $images;
	}

	/**
	 * Render download links and on-demand high-res print export controls directly under the order line item.
	 *
	 * @param int                    $item_id Order item ID.
	 * @param \WC_Order_Item_Product $item    Order item object.
	 * @param \WC_Product            $product Product object.
	 */
	public static function render_order_item_actions( $item_id, $item, $product ) {
		$data = $item->get_meta( '_mr_tee_editor_data' );
		if ( empty( $data ) || ! is_array( $data ) ) {
			return;
		}

		$order_id    = method_exists( $item, 'get_order_id' ) ? (int) $item->get_order_id() : 0;
		$design_key  = ! empty( $data['key'] ) ? $data['key'] : '';
		$print_url   = ! empty( $data['print_url'] ) ? self::normalize_url( $data['print_url'] ) : '';
		$svg_url     = ! empty( $data['svg_url'] ) ? self::normalize_url( $data['svg_url'] ) : '';
		$preview_url = ! empty( $data['preview_url'] ) ? self::normalize_url( $data['preview_url'] ) : '';
		$cust_images = self::extract_customer_images( $data );
		$has_print   = ( ! empty( $print_url ) && ! empty( $svg_url ) );

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
			<?php if ( ! empty( $data['print_dimensions'] ) ) : ?>
				<div style="margin-bottom:8px;font-size:11.5px;color:#4b5563;">
					<strong><?php esc_html_e( "Dimensions d'impression :", 'mr-tee-editor' ); ?></strong>
					<span><?php echo esc_html( $data['print_dimensions'] ); ?></span>
				</div>
			<?php endif; ?>
			<?php if ( ! empty( $preview_url ) ) : ?>
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
				<a
					href="<?php echo esc_url( $print_url ? $print_url : '#' ); ?>"
					target="_blank"
					class="button button-primary button-small mr-tee-btn-print-png"
					download
					style="<?php echo empty( $print_url ) ? 'display:none;' : ''; ?>"
				>
					<span class="dashicons dashicons-download" style="vertical-align:middle;font-size:14px;width:14px;height:14px;"></span>
					<?php esc_html_e( 'PNG 300 DPI (Sans fond)', 'mr-tee-editor' ); ?>
				</a>

				<a
					href="<?php echo esc_url( $svg_url ? $svg_url : '#' ); ?>"
					target="_blank"
					class="button button-secondary button-small mr-tee-btn-print-svg"
					download
					style="<?php echo empty( $svg_url ) ? 'display:none;' : ''; ?>"
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

				<?php if ( ! empty( $preview_url ) ) : ?>
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
			$found       = true;
			$design_key  = ! empty( $data['key'] ) ? $data['key'] : '';
			$print_url   = ! empty( $data['print_url'] ) ? self::normalize_url( $data['print_url'] ) : '';
			$svg_url     = ! empty( $data['svg_url'] ) ? self::normalize_url( $data['svg_url'] ) : '';
			$preview_url = ! empty( $data['preview_url'] ) ? self::normalize_url( $data['preview_url'] ) : '';
			$cust_images = self::extract_customer_images( $data );
			$has_print   = ( ! empty( $print_url ) && ! empty( $svg_url ) );
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
				<?php if ( ! empty( $preview_url ) ) : ?>
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
					<a
						href="<?php echo esc_url( $print_url ? $print_url : '#' ); ?>"
						target="_blank"
						class="button button-primary mr-tee-btn-print-png"
						download
						style="text-align:center;<?php echo empty( $print_url ) ? 'display:none;' : ''; ?>"
					>
						<?php esc_html_e( 'Télécharger PNG 300 DPI', 'mr-tee-editor' ); ?>
					</a>
					<a
						href="<?php echo esc_url( $svg_url ? $svg_url : '#' ); ?>"
						target="_blank"
						class="button mr-tee-btn-print-svg"
						download
						style="text-align:center;<?php echo empty( $svg_url ) ? 'display:none;' : ''; ?>"
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
					<?php if ( ! empty( $preview_url ) ) : ?>
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
