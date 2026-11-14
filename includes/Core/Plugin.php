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

		add_action( 'wp_ajax_mr_tee_upload_customer_image', array( $this, 'handle_customer_image_upload' ) );
		add_action( 'wp_ajax_nopriv_mr_tee_upload_customer_image', array( $this, 'handle_customer_image_upload' ) );
		add_action( 'wp_ajax_mr_tee_upload_default_image', array( $this, 'handle_admin_default_image_upload' ) );
	}

	/**
	 * Handle frontend customer image upload via AJAX so cart payloads stay compact
	 * while original uploaded artwork is safely persisted on disk.
	 */
	public function handle_customer_image_upload() {
		check_ajax_referer( 'mr_tee_frontend_upload', 'nonce' );

		if ( empty( $_FILES['image_file'] ) || ! empty( $_FILES['image_file']['error'] ) ) {
			wp_send_json_error( array( 'message' => __( 'Aucun fichier image valide reçu.', 'mr-tee-editor' ) ), 400 );
		}

		$file = $_FILES['image_file']; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		$max_bytes = 15 * 1024 * 1024; // 15 MB
		if ( ! empty( $file['size'] ) && intval( $file['size'] ) > $max_bytes ) {
			wp_send_json_error( array( 'message' => __( 'Image trop volumineuse (max 15 Mo).', 'mr-tee-editor' ) ), 400 );
		}

		$orig_name = ! empty( $file['name'] ) ? sanitize_file_name( wp_unslash( $file['name'] ) ) : 'image.png';
		$ext       = strtolower( pathinfo( $orig_name, PATHINFO_EXTENSION ) );
		$allowed   = array( 'jpg', 'jpeg', 'png', 'webp', 'gif', 'svg' );
		if ( ! in_array( $ext, $allowed, true ) ) {
			wp_send_json_error( array( 'message' => __( 'Format non supporté. Utilisez PNG, JPG, WEBP, GIF ou SVG.', 'mr-tee-editor' ) ), 400 );
		}

		$raw_bytes = @file_get_contents( $file['tmp_name'] );
		if ( false === $raw_bytes || '' === $raw_bytes ) {
			wp_send_json_error( array( 'message' => __( 'Impossible de lire le fichier image.', 'mr-tee-editor' ) ), 400 );
		}

		if ( 'svg' === $ext ) {
			if ( stripos( $raw_bytes, '<svg' ) === false ) {
				wp_send_json_error( array( 'message' => __( 'Fichier SVG invalide.', 'mr-tee-editor' ) ), 400 );
			}
			// Strip potentially dangerous script tags / event attributes in SVG uploads
			$raw_bytes = preg_replace( '#<script\b[^>]*>.*?</script>#is', '', $raw_bytes );
			$raw_bytes = preg_replace( '/\son[a-z]+\s*=\s*(?:"[^"]*"|\'[^\']*\')/i', '', $raw_bytes );
		} else {
			$img_info = @getimagesizefromstring( $raw_bytes );
			if ( false === $img_info || empty( $img_info['mime'] ) || strpos( $img_info['mime'], 'image/' ) !== 0 ) {
				wp_send_json_error( array( 'message' => __( 'Le fichier envoyé n\'est pas une image valide.', 'mr-tee-editor' ) ), 400 );
			}
		}

		$sub_dir   = 'customer_uploads/' . gmdate( 'Y-m' );
		$paths     = \MrTeeEditor\Export\PrintAssetGenerator::get_upload_dir( $sub_dir );
		$unique_fn = wp_unique_filename( $paths['dir'], wp_generate_uuid4() . '-' . $orig_name );
		$dest_file = trailingslashit( $paths['dir'] ) . $unique_fn;
		$dest_url  = trailingslashit( $paths['url'] ) . $unique_fn;

		if ( false === @file_put_contents( $dest_file, $raw_bytes ) ) {
			wp_send_json_error( array( 'message' => __( 'Erreur lors de l\'enregistrement de l\'image.', 'mr-tee-editor' ) ), 500 );
		}

		wp_send_json_success(
			array(
				'url'      => $dest_url,
				'filename' => $orig_name,
			)
		);
	}

	/**
	 * Handle direct file upload for admin default image field (stores in WP Media Library).
	 */
	public function handle_admin_default_image_upload() {
		check_ajax_referer( 'mr_tee_admin_nonce', 'nonce' );

		if ( ! current_user_can( 'upload_files' ) ) {
			wp_send_json_error( array( 'message' => __( 'Permission refusée.', 'mr-tee-editor' ) ), 403 );
		}

		if ( empty( $_FILES['image_file'] ) || ! empty( $_FILES['image_file']['error'] ) ) {
			wp_send_json_error( array( 'message' => __( 'Aucun fichier image reçu.', 'mr-tee-editor' ) ), 400 );
		}

		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/image.php';
		require_once ABSPATH . 'wp-admin/includes/media.php';

		$attachment_id = media_handle_upload( 'image_file', isset( $_POST['product_id'] ) ? absint( $_POST['product_id'] ) : 0 );
		if ( is_wp_error( $attachment_id ) ) {
			wp_send_json_error( array( 'message' => $attachment_id->get_error_message() ), 400 );
		}

		$url = wp_get_attachment_url( $attachment_id );
		wp_send_json_success(
			array(
				'id'  => $attachment_id,
				'url' => $url,
			)
		);
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

		if ( function_exists( 'wp_enqueue_media' ) ) {
			wp_enqueue_media( array( 'post' => $post->ID ) );
		}

		$config = SceneGraphSchema::get_product_config( $post->ID );
		$proto  = is_ssl() ? 'https' : 'http';

		// Collect strictly the fonts selected on this product (allowed_fonts + active text line fonts)
		$all_fonts         = \MrTeeEditor\Registry\FontRegistry::get_fonts();
		$selected_font_ids = array();
		if ( ! empty( $config['allowed_fonts'] ) && is_array( $config['allowed_fonts'] ) ) {
			$selected_font_ids = $config['allowed_fonts'];
		} elseif ( ! empty( $config['text_lines'] ) && is_array( $config['text_lines'] ) ) {
			foreach ( $config['text_lines'] as $tl ) {
				if ( ( ! isset( $tl['field_type'] ) || 'image' !== $tl['field_type'] ) && ! empty( $tl['allowed_fonts'] ) && is_array( $tl['allowed_fonts'] ) ) {
					$selected_font_ids = $tl['allowed_fonts'];
					break;
				}
			}
		}
		if ( ! empty( $config['text_lines'] ) && is_array( $config['text_lines'] ) ) {
			foreach ( $config['text_lines'] as $tl ) {
				if ( isset( $tl['field_type'] ) && 'image' === $tl['field_type'] ) {
					continue;
				}
				if ( ! empty( $tl['font'] ) ) {
					$selected_font_ids[] = $tl['font'];
				}
			}
		}
		$selected_font_ids = array_values( array_unique( array_filter( $selected_font_ids ) ) );

		wp_enqueue_style(
			'mr-tee-admin-style',
			MR_TEE_EDITOR_URL . 'assets/css/mr-tee-admin.css',
			array(),
			MR_TEE_EDITOR_VERSION
		);

		// Dynamic @font-face CSS for ONLY selected standard vector fonts (OpenType-SVG .otf files are skipped;
		// additional TTF fonts added dynamically in the admin UI are injected on-demand by mr-tee-admin.js)
		$font_face_css = \MrTeeEditor\Registry\FontRegistry::get_font_face_css( ! empty( $selected_font_ids ) ? $selected_font_ids : array() );
		if ( '' !== $font_face_css ) {
			wp_add_inline_style( 'mr-tee-admin-style', $font_face_css );
		}

		wp_enqueue_script(
			'mr-tee-admin-js',
			MR_TEE_EDITOR_URL . 'assets/js/mr-tee-admin.js',
			array( 'jquery', 'jquery-ui-draggable', 'jquery-ui-resizable' ),
			MR_TEE_EDITOR_VERSION,
			true
		);

		// Preload ONLY active text line OpenType-SVG fonts (other allowed OpenType-SVG fonts are lazy-loaded when selected)
		$preloaded_svg_fonts = array();
		if ( ! empty( $config['text_lines'] ) && is_array( $config['text_lines'] ) ) {
			foreach ( $config['text_lines'] as $line ) {
				if ( isset( $line['field_type'] ) && 'image' === $line['field_type'] ) {
					continue;
				}
				if ( empty( $line['font'] ) || isset( $preloaded_svg_fonts[ $line['font'] ] ) ) {
					continue;
				}
				$f_def = isset( $all_fonts[ $line['font'] ] ) ? $all_fonts[ $line['font'] ] : null;
				if ( $f_def && ( ( ! empty( $f_def['font_type'] ) && 'opentype_svg' === $f_def['font_type'] ) || ! empty( $f_def['color_svg'] ) ) ) {
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
				'fonts'     => $all_fonts,
				'svgFonts'  => $preloaded_svg_fonts,
				'ajaxUrl'   => admin_url( 'admin-ajax.php', $proto ),
				'nonce'     => wp_create_nonce( 'mr_tee_admin_nonce' ),
				'pluginUrl' => MR_TEE_EDITOR_URL,
			)
		);
	}
}
