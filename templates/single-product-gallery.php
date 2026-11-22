<?php
/**
 * Mr. Tee Editor - Dedicated Single Product Gallery Template
 *
 * Overrides WooCommerce `single-product/product-image.php` exclusively for
 * products powered by Mr. Tee Editor.
 *
 * @package MrTeeEditor\Templates
 */

use MrTeeEditor\Frontend\ProductGalleryRenderer;
use MrTeeEditor\Frontend\ProductPageRenderer;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

global $product, $post;

$mr_tee_product_id = 0;
if ( is_object( $product ) && method_exists( $product, 'get_id' ) ) {
	$mr_tee_product_id = (int) $product->get_id();
} elseif ( $post && 'product' === $post->post_type ) {
	$mr_tee_product_id = (int) $post->ID;
} else {
	$mr_tee_product_id = ProductPageRenderer::get_active_product_id();
}

if ( $mr_tee_product_id > 0 ) {
	echo ProductGalleryRenderer::render_gallery_html( $mr_tee_product_id ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
}
