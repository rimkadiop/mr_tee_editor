<?php
/**
 * Mr. Tee Editor - Core Plugin Container
 *
 * Bootstraps the modular architecture, SPL class autoloader, and service lifecycle.
 *
 * @package MrTeeEditor\Core
 */

namespace MrTeeEditor\Core;

use MrTeeEditor\Domain\SceneGraphSchema;
use MrTeeEditor\Admin\EngineSelectorMetabox;
use MrTeeEditor\Frontend\ProductPageRenderer;
use MrTeeEditor\Commerce\CartIntegration;
use MrTeeEditor\Commerce\OrderAdminPanel;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Plugin {

	/**
	 * Singleton instance.
	 *
	 * @var Plugin|null
	 */
	private static $instance = null;

	/**
	 * Main instance accessor.
	 *
	 * @return Plugin
	 */
	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Constructor: registers autoloader and hooks.
	 */
	private function __construct() {
		$this->register_autoloader();
		$this->init_services();
		add_action( 'plugins_loaded', array( $this, 'load_textdomain' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_admin_assets' ) );
	}

	/**
	 * Register PSR-4 compatible SPL autoloader for `MrTeeEditor\` namespace.
	 */
	private function register_autoloader() {
		spl_autoload_register(
			function ( $class ) {
				$prefix   = 'MrTeeEditor\\';
				$base_dir = MR_TEE_EDITOR_DIR . 'includes/';

				$len = strlen( $prefix );
				if ( strncmp( $prefix, $class, $len ) !== 0 ) {
					return;
				}

				$relative_class = substr( $class, $len );
				$file           = $base_dir . str_replace( '\\', '/', $relative_class ) . '.php';

				if ( file_exists( $file ) ) {
					require_once $file;
				}
			}
		);
	}

	/**
	 * Initialize domain and commerce subsystems.
	 */
	private function init_services() {
		EngineSelectorMetabox::init();
		ProductPageRenderer::init();
		CartIntegration::init();
		OrderAdminPanel::init();
		\MrTeeEditor\Registry\FontRegistry::register_hooks();
	}

	/**
	 * Load translation textdomain.
	 */
	public function load_textdomain() {
		load_plugin_textdomain(
			'mr-tee-editor',
			false,
			dirname( plugin_basename( MR_TEE_EDITOR_FILE ) ) . '/languages'
		);
	}

	/**
	 * Enqueue admin scripts & styles on product edit screens.
	 *
	 * @param string $hook Current admin screen hook.
	 */
	public function enqueue_admin_assets( $hook ) {
		global $post;

		if ( ! in_array( $hook, array( 'post.php', 'post-new.php' ), true ) ) {
			return;
		}

		if ( ! $post || 'product' !== $post->post_type ) {
			return;
		}

		// Google Fonts for Admin Preview
		wp_enqueue_style(
			'mr-tee-google-fonts',
			'https://fonts.googleapis.com/css2?family=Abril+Fatface&family=Bebas+Neue&family=Bungee&family=Cinzel+Decorative:wght@700&family=Luckiest+Guy&family=Pacifico&family=Permanent+Marker&family=Press+Start+2P&display=swap',
			array(),
			MR_TEE_EDITOR_VERSION
		);

		wp_enqueue_style(
			'mr-tee-admin-style',
			MR_TEE_EDITOR_URL . 'assets/css/mr-tee-admin.css',
			array(),
			MR_TEE_EDITOR_VERSION
		);

		// Dynamic @font-face CSS for bundled fonts
		$font_face_css = \MrTeeEditor\Registry\FontRegistry::get_font_face_css();
		wp_add_inline_style( 'mr-tee-admin-style', $font_face_css );

		wp_enqueue_script(
			'mr-tee-admin-js',
			MR_TEE_EDITOR_URL . 'assets/js/mr-tee-admin.js',
			array( 'jquery', 'jquery-ui-draggable', 'jquery-ui-resizable' ),
			MR_TEE_EDITOR_VERSION,
			true
		);

		$config = SceneGraphSchema::get_product_config( $post->ID );
		$proto  = is_ssl() ? 'https' : 'http';

		// Preload active SVG fonts
		$preloaded_svg_fonts = array();
		if ( ! empty( $config['text_lines'] ) && is_array( $config['text_lines'] ) ) {
			foreach ( $config['text_lines'] as $line ) {
				if ( ! empty( $line['font'] ) ) {
					$svg_data = \MrTeeEditor\Registry\FontRegistry::get_svg_font_data( $line['font'] );
					if ( $svg_data ) {
						$preloaded_svg_fonts[ $line['font'] ] = $svg_data;
					}
				}
			}
		}

		wp_localize_script(
			'mr-tee-admin-js',
			'mrTeeAdminData',
			array(
				'productId' => $post->ID,
				'config'    => $config,
				'fonts'     => \MrTeeEditor\Registry\FontRegistry::get_fonts(),
				'svgFonts'  => $preloaded_svg_fonts,
				'ajaxUrl'   => admin_url( 'admin-ajax.php', $proto ),
				'nonce'     => wp_create_nonce( 'mr_tee_admin_nonce' ),
				'pluginUrl' => MR_TEE_EDITOR_URL,
			)
		);
	}
}
