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
	 * Render download links directly under the order line item.
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

		?>
		<div class="mr-tee-order-item-export" style="margin-top:10px;padding:8px 12px;background:#f9fafb;border:1px solid #e5e7eb;border-radius:6px;">
			<strong style="display:block;margin-bottom:6px;color:#111827;font-size:12px;">
				<?php esc_html_e( 'Fichiers Mr. Tee Designer :', 'mr-tee-editor' ); ?>
			</strong>
			<div style="display:flex;gap:8px;flex-wrap:wrap;align-items:center;">
				<?php if ( ! empty( $data['print_url'] ) ) : ?>
					<a href="<?php echo esc_url( $data['print_url'] ); ?>" target="_blank" class="button button-primary button-small" download>
						<span class="dashicons dashicons-download" style="vertical-align:middle;font-size:14px;width:14px;height:14px;"></span>
						<?php esc_html_e( 'PNG 300 DPI (Sans fond)', 'mr-tee-editor' ); ?>
					</a>
				<?php endif; ?>

				<?php if ( ! empty( $data['svg_url'] ) ) : ?>
					<a href="<?php echo esc_url( $data['svg_url'] ); ?>" target="_blank" class="button button-secondary button-small" download>
						<span class="dashicons dashicons-media-code" style="vertical-align:middle;font-size:14px;width:14px;height:14px;"></span>
						<?php esc_html_e( 'Vector SVG', 'mr-tee-editor' ); ?>
					</a>
				<?php endif; ?>

				<?php if ( ! empty( $data['preview_url'] ) ) : ?>
					<a href="<?php echo esc_url( $data['preview_url'] ); ?>" target="_blank" class="button button-secondary button-small">
						<span class="dashicons dashicons-visibility" style="vertical-align:middle;font-size:14px;width:14px;height:14px;"></span>
						<?php esc_html_e( 'Aperçu Mockup', 'mr-tee-editor' ); ?>
					</a>
				<?php endif; ?>
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

		$found = false;
		foreach ( $order->get_items() as $item ) {
			$data = $item->get_meta( '_mr_tee_editor_data' );
			if ( empty( $data ) ) {
				continue;
			}
			$found = true;
			?>
			<div style="margin-bottom:15px;padding-bottom:12px;border-bottom:1px solid #e5e7eb;">
				<h4 style="margin:0 0 8px 0;font-size:13px;"><?php echo esc_html( $item->get_name() ); ?></h4>
				<?php if ( ! empty( $data['preview_url'] ) ) : ?>
					<div style="text-align:center;margin-bottom:10px;">
						<img src="<?php echo esc_url( $data['preview_url'] ); ?>" alt="Preview" style="max-width:140px;border-radius:6px;border:1px solid #d1d5db;box-shadow:0 1px 3px rgba(0,0,0,0.1);" />
					</div>
				<?php endif; ?>
				<ul style="margin:0 0 10px 0;padding-left:15px;font-size:12px;color:#4b5563;">
					<li><strong><?php esc_html_e( 'Clé de design :', 'mr-tee-editor' ); ?></strong> <code><?php echo esc_html( $data['key'] ); ?></code></li>
					<?php if ( ! empty( $data['nbd']['key'] ) ) : ?>
						<li><strong><?php esc_html_e( 'Dossier NBDesigner :', 'mr-tee-editor' ); ?></strong> <code><?php echo esc_html( $data['nbd']['key'] ); ?></code></li>
					<?php endif; ?>
				</ul>
				<div style="display:flex;flex-direction:column;gap:6px;">
					<?php if ( ! empty( $data['print_url'] ) ) : ?>
						<a href="<?php echo esc_url( $data['print_url'] ); ?>" target="_blank" class="button button-primary" download style="text-align:center;">
							<?php esc_html_e( 'Télécharger PNG 300 DPI', 'mr-tee-editor' ); ?>
						</a>
					<?php endif; ?>
					<?php if ( ! empty( $data['svg_url'] ) ) : ?>
						<a href="<?php echo esc_url( $data['svg_url'] ); ?>" target="_blank" class="button" download style="text-align:center;">
							<?php esc_html_e( 'Télécharger Vector SVG', 'mr-tee-editor' ); ?>
						</a>
					<?php endif; ?>
				</div>
			</div>
			<?php
		}

		if ( ! $found ) {
			echo '<p style="color:#6b7280;font-size:13px;">' . esc_html__( 'Aucun article personnalisé avec Mr. Tee Editor dans cette commande.', 'mr-tee-editor' ) . '</p>';
		}
	}
}
