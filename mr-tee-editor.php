<?php
/**
 * Plugin Name: Mr. Tee Editor – Form-Based Live Product Customizer
 * Plugin URI:  https://github.com/rimkadiop/mr_tee_editor
 * Description: Monsieur TSHIRT style in-product-page live preview form customizer with 2-row scene-graph layout, relative anchors, fancy 3D fonts, visual placement stage, and WooCommerce + NBDesigner 300 DPI print export.
 * Version:     2.3.1
 * Author:      Rimka Diop
 * Text Domain: mr-tee-editor
 * Domain Path: /languages
 *
 * @package MrTeeEditor
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Plugin Constants
define( 'MR_TEE_EDITOR_VERSION', '2.3.1' );
define( 'MR_TEE_EDITOR_FILE', __FILE__ );
define( 'MR_TEE_EDITOR_DIR', plugin_dir_path( __FILE__ ) );

// Determine protocol-aware plugin URL
$mr_tee_url = plugin_dir_url( __FILE__ );
if ( isset( $_SERVER['HTTP_HOST'] ) ) {
	$mr_tee_proto = is_ssl() ? 'https://' : 'http://';
	$mr_tee_url   = preg_replace( '#^https?://#', $mr_tee_proto, $mr_tee_url );
}
define( 'MR_TEE_EDITOR_URL', $mr_tee_url );

// Backward-compatibility constants
if ( ! defined( 'MR_TEE_VERSION' ) ) {
	define( 'MR_TEE_VERSION', MR_TEE_EDITOR_VERSION );
}
if ( ! defined( 'MR_TEE_PLUGIN_FILE' ) ) {
	define( 'MR_TEE_PLUGIN_FILE', MR_TEE_EDITOR_FILE );
}
if ( ! defined( 'MR_TEE_PLUGIN_DIR' ) ) {
	define( 'MR_TEE_PLUGIN_DIR', MR_TEE_EDITOR_DIR );
}
if ( ! defined( 'MR_TEE_PLUGIN_URL' ) ) {
	define( 'MR_TEE_PLUGIN_URL', MR_TEE_EDITOR_URL );
}

// Bootstrap PSR-4 autoloader and core plugin service container
require_once MR_TEE_EDITOR_DIR . 'includes/Core/Plugin.php';

add_action(
	'plugins_loaded',
	function () {
		\MrTeeEditor\Core\Plugin::instance();
	},
	5
);

// Ensure WooCommerce session cookies work over both HTTP and HTTPS in test/staging environments
add_filter( 'wc_session_use_secure_cookie', '__return_false', 999 );
add_filter( 'woocommerce_cookie_is_secure', '__return_false', 999 );
add_filter( 'wc_cookie_is_secure', '__return_false', 999 );

// Normalize form action & redirects to prevent 503 errors on HTTPS port 443
$mr_tee_http_filter = function ( $url ) {
	return is_string( $url ) ? str_replace( 'https://1.tawfekh-digital.com', 'http://1.tawfekh-digital.com', $url ) : $url;
};
add_filter( 'woocommerce_add_to_cart_form_action', $mr_tee_http_filter, 999 );
add_filter( 'woocommerce_get_cart_url', $mr_tee_http_filter, 999 );
add_filter( 'woocommerce_get_checkout_url', $mr_tee_http_filter, 999 );
add_filter( 'page_link', $mr_tee_http_filter, 999 );
add_filter( 'post_link', $mr_tee_http_filter, 999 );
add_filter( 'post_type_link', $mr_tee_http_filter, 999 );
add_filter( 'wp_redirect', $mr_tee_http_filter, 999 );

/**
 * Backward compatibility helper: get product configuration.
 *
 * @param int $product_id Product ID.
 * @return array<string, mixed>
 */
function mr_tee_get_product_config( $product_id ) {
	return \MrTeeEditor\Domain\SceneGraphSchema::get_product_config( $product_id );
}

/**
 * Backward compatibility helper: get available fonts.
 *
 * @return array<string, array<string, mixed>>
 */
function mr_tee_get_available_fonts() {
	return \MrTeeEditor\Registry\FontRegistry::get_fonts();
}

/**
 * Backward compatibility helper: get available color palettes.
 *
 * @return array<string, array<string, mixed>>
 */
function mr_tee_get_color_palettes() {
	return \MrTeeEditor\Registry\ColorPaletteRegistry::get_color_sets();
}

/**
 * Backward compatibility helper: get available icons.
 *
 * @return array<string, array<string, string>>
 */
function mr_tee_get_available_icons() {
	return \MrTeeEditor\Registry\IconSetRegistry::get_mushroom_icons();
}
