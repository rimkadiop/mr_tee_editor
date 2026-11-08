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

		wp_enqueue_style(
			'mr-tee-admin-style',
			MR_TEE_EDITOR_URL . 'assets/css/mr-tee-admin.css',
			array(),
			MR_TEE_EDITOR_VERSION
		);

		wp_enqueue_script(
			'mr-tee-admin-js',
			MR_TEE_EDITOR_URL . 'assets/js/mr-tee-admin.js',
			array( 'jquery', 'jquery-ui-draggable', 'jquery-ui-resizable' ),
			MR_TEE_EDITOR_VERSION,
			true
		);

		$config = SceneGraphSchema::get_product_config( $post->ID );
		wp_localize_script(
			'mr-tee-admin-js',
			'mrTeeAdminData',
			array(
				'productId' => $post->ID,
				'config'    => $config,
				'pluginUrl' => MR_TEE_EDITOR_URL,
			)
		);
	}
}
