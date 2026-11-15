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
		add_filter( 'script_loader_tag', array( $this, 'filter_admin_script_loader_tag' ), 10, 3 );
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
		add_action( 'wp_ajax_mr_tee_upload_design_artifact', array( $this, 'handle_design_artifact_upload' ) );
		add_action( 'wp_ajax_nopriv_mr_tee_upload_design_artifact', array( $this, 'handle_design_artifact_upload' ) );
		add_action( 'wp_ajax_mr_tee_upload_default_image', array( $this, 'handle_admin_default_image_upload' ) );
		add_action( 'wp_ajax_mr_tee_admin_get_order_item_context', array( $this, 'handle_admin_get_order_item_context' ) );
		add_action( 'wp_ajax_mr_tee_admin_save_print_artifacts', array( $this, 'handle_admin_save_print_artifacts' ) );
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
	 * Handle chunked binary uploads for customer photos and print export artifacts
	 * (preview JPEG/PNG, vector SVG, and 300 DPI print PNG) so WooCommerce Add-to-Cart
	 * POST bodies remain tiny and never trigger HTTP 413 Request Entity Too Large.
	 */
	public function handle_design_artifact_upload() {
		$artifact          = isset( $_POST['artifact'] ) ? sanitize_key( wp_unslash( $_POST['artifact'] ) ) : '';
		$allowed_artifacts = array( 'preview_jpg', 'preview_png', 'svg', 'print_png', 'customer_image' );
		if ( ! in_array( $artifact, $allowed_artifacts, true ) ) {
			check_ajax_referer( 'mr_tee_frontend_upload', 'nonce' );
			wp_send_json_error( array( 'message' => __( 'Type de fichier non autorisé.', 'mr-tee-editor' ) ), 400 );
		}

		if ( in_array( $artifact, array( 'svg', 'print_png' ), true ) ) {
			if ( false === check_ajax_referer( 'mr_tee_admin_nonce', 'nonce', false ) ) {
				wp_send_json_error( array( 'message' => __( 'Nonce administrateur invalide ou expiré.', 'mr-tee-editor' ) ), 403 );
			}
			if ( ! current_user_can( 'manage_woocommerce' ) && ! current_user_can( 'edit_shop_orders' ) ) {
				wp_send_json_error( array( 'message' => __( 'Permission refusée : génération haute résolution réservée à l\'administration.', 'mr-tee-editor' ) ), 403 );
			}
		} else {
			check_ajax_referer( 'mr_tee_frontend_upload', 'nonce' );
		}

		$chunk_index  = isset( $_POST['chunk_index'] ) ? intval( $_POST['chunk_index'] ) : 0;
		$total_chunks = isset( $_POST['total_chunks'] ) ? intval( $_POST['total_chunks'] ) : 1;
		if ( $total_chunks < 1 || $total_chunks > 200 || $chunk_index < 0 || $chunk_index >= $total_chunks ) {
			wp_send_json_error( array( 'message' => __( 'Paramètres de transfert invalides.', 'mr-tee-editor' ) ), 400 );
		}

		$upload_id = isset( $_POST['upload_id'] ) ? preg_replace( '/[^a-zA-Z0-9_-]/', '', wp_unslash( $_POST['upload_id'] ) ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		if ( strlen( $upload_id ) < 6 || strlen( $upload_id ) > 64 ) {
			wp_send_json_error( array( 'message' => __( 'Identifiant de transfert invalide.', 'mr-tee-editor' ) ), 400 );
		}

		if ( empty( $_FILES['chunk_file'] ) || ! empty( $_FILES['chunk_file']['error'] ) ) {
			wp_send_json_error( array( 'message' => __( 'Fragment de fichier manquant.', 'mr-tee-editor' ) ), 400 );
		}

		$chunk_file  = $_FILES['chunk_file']; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		$chunk_bytes = @file_get_contents( $chunk_file['tmp_name'] );
		if ( false === $chunk_bytes || strlen( $chunk_bytes ) > 2 * 1024 * 1024 ) {
			wp_send_json_error( array( 'message' => __( 'Fragment de fichier invalide ou trop volumineux.', 'mr-tee-editor' ) ), 400 );
		}

		$design_key = '';
		$orig_name  = '';
		$ext        = '';

		if ( 'customer_image' === $artifact ) {
			$orig_name = ! empty( $_POST['file_name'] ) ? sanitize_file_name( wp_unslash( $_POST['file_name'] ) ) : 'image.png';
			$ext       = strtolower( pathinfo( $orig_name, PATHINFO_EXTENSION ) );
			$allowed   = array( 'jpg', 'jpeg', 'png', 'webp', 'gif', 'svg' );
			if ( ! in_array( $ext, $allowed, true ) ) {
				wp_send_json_error( array( 'message' => __( 'Format non supporté. Utilisez PNG, JPG, WEBP, GIF ou SVG.', 'mr-tee-editor' ) ), 400 );
			}
			$sub_dir = 'customer_uploads/' . gmdate( 'Y-m' );
		} else {
			$raw_key    = isset( $_POST['design_key'] ) ? wp_unslash( $_POST['design_key'] ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
			$design_key = preg_replace( '/[^a-zA-Z0-9_-]/', '', $raw_key );
			if ( ! preg_match( '/^mrtee_[a-zA-Z0-9_-]{8,64}$/', $design_key ) ) {
				wp_send_json_error( array( 'message' => __( 'Clé de création invalide.', 'mr-tee-editor' ) ), 400 );
			}
			$sub_dir = 'designs/' . $design_key;
		}

		$paths     = \MrTeeEditor\Export\PrintAssetGenerator::get_upload_dir( $sub_dir );
		$part_file = trailingslashit( $paths['dir'] ) . '.part_' . $upload_id;

		if ( 0 === $chunk_index ) {
			@unlink( $part_file );
			$written = @file_put_contents( $part_file, $chunk_bytes );
		} else {
			if ( ! file_exists( $part_file ) ) {
				wp_send_json_error( array( 'message' => __( 'Session de transfert expirée.', 'mr-tee-editor' ) ), 400 );
			}
			$current_size = @filesize( $part_file );
			if ( false === $current_size || ( $current_size + strlen( $chunk_bytes ) ) > 25 * 1024 * 1024 ) {
				@unlink( $part_file );
				wp_send_json_error( array( 'message' => __( 'Fichier assemblé trop volumineux (max 25 Mo).', 'mr-tee-editor' ) ), 400 );
			}
			$written = @file_put_contents( $part_file, $chunk_bytes, FILE_APPEND );
		}

		if ( false === $written ) {
			@unlink( $part_file );
			wp_send_json_error( array( 'message' => __( 'Erreur d\'écriture sur le serveur.', 'mr-tee-editor' ) ), 500 );
		}

		if ( $chunk_index < ( $total_chunks - 1 ) ) {
			wp_send_json_success(
				array(
					'done'        => false,
					'chunk_index' => $chunk_index,
				)
			);
		}

		// Final chunk: validate complete assembled file and atomically move into place
		if ( 'customer_image' === $artifact ) {
			if ( 'svg' === $ext ) {
				$svg_content = @file_get_contents( $part_file );
				if ( false === $svg_content || stripos( $svg_content, '<svg' ) === false ) {
					@unlink( $part_file );
					wp_send_json_error( array( 'message' => __( 'Fichier SVG invalide.', 'mr-tee-editor' ) ), 400 );
				}
				$svg_content = preg_replace( '#<script\b[^>]*>.*?</script>#is', '', $svg_content );
				$svg_content = preg_replace( '/\son[a-z]+\s*=\s*(?:"[^"]*"|\'[^\']*\')/i', '', $svg_content );
				@file_put_contents( $part_file, $svg_content );
			} else {
				$img_info = @getimagesize( $part_file );
				if ( false === $img_info || empty( $img_info['mime'] ) || strpos( $img_info['mime'], 'image/' ) !== 0 ) {
					@unlink( $part_file );
					wp_send_json_error( array( 'message' => __( 'Le fichier envoyé n\'est pas une image valide.', 'mr-tee-editor' ) ), 400 );
				}
			}

			$unique_fn = wp_unique_filename( $paths['dir'], wp_generate_uuid4() . '-' . $orig_name );
			$dest_file = trailingslashit( $paths['dir'] ) . $unique_fn;
			$dest_url  = trailingslashit( $paths['url'] ) . $unique_fn;

			if ( ! @rename( $part_file, $dest_file ) ) {
				@unlink( $part_file );
				wp_send_json_error( array( 'message' => __( 'Erreur lors de la finalisation de l\'image.', 'mr-tee-editor' ) ), 500 );
			}

			wp_send_json_success(
				array(
					'done'     => true,
					'url'      => $dest_url,
					'filename' => $orig_name,
				)
			);
		}

		$target_names = array(
			'preview_jpg' => 'design_preview.jpg',
			'preview_png' => 'design_preview.png',
			'svg'         => 'design.svg',
			'print_png'   => 'print_300dpi.png',
		);
		$target_name = $target_names[ $artifact ];

		if ( 'svg' === $artifact ) {
			$svg_raw = @file_get_contents( $part_file );
			if ( false === $svg_raw || stripos( $svg_raw, '<svg' ) === false ) {
				@unlink( $part_file );
				wp_send_json_error( array( 'message' => __( 'Fichier SVG invalide.', 'mr-tee-editor' ) ), 400 );
			}
			$svg_raw = preg_replace( '#<script\b[^>]*>.*?</script>#is', '', $svg_raw );
			@file_put_contents( $part_file, $svg_raw );
		} else {
			$img_info = @getimagesize( $part_file );
			if ( false === $img_info || empty( $img_info['mime'] ) || strpos( $img_info['mime'], 'image/' ) !== 0 ) {
				@unlink( $part_file );
				wp_send_json_error( array( 'message' => __( 'Fichier image généré invalide.', 'mr-tee-editor' ) ), 400 );
			}
		}

		$dest_file = trailingslashit( $paths['dir'] ) . $target_name;
		$dest_url  = trailingslashit( $paths['url'] ) . $target_name;

		if ( ! @rename( $part_file, $dest_file ) ) {
			@unlink( $part_file );
			wp_send_json_error( array( 'message' => __( 'Erreur lors de la finalisation du fichier.', 'mr-tee-editor' ) ), 500 );
		}

		wp_send_json_success(
			array(
				'done'     => true,
				'artifact' => $artifact,
				'url'      => $dest_url,
				'bytes'    => @filesize( $dest_file ),
			)
		);
	}

	/**
	 * Normalize same-site asset URLs to match active HTTP/HTTPS request scheme.
	 *
	 * @param string $url Raw URL.
	 * @return string
	 */
	private function normalize_same_site_url( $url ) {
		if ( empty( $url ) || ! is_string( $url ) ) {
			return '';
		}
		if ( 0 !== strpos( $url, 'http' ) ) {
			return $url;
		}
		$site_host = wp_parse_url( home_url(), PHP_URL_HOST );
		$http_host = isset( $_SERVER['HTTP_HOST'] ) ? preg_replace( '/:\d+$/', '', sanitize_text_field( wp_unslash( $_SERVER['HTTP_HOST'] ) ) ) : '';
		$url_host  = wp_parse_url( $url, PHP_URL_HOST );

		if ( $url_host && ( $url_host === $site_host || ( $http_host && $url_host === $http_host ) ) ) {
			return set_url_scheme( $url, is_ssl() ? 'https' : 'http' );
		}
		return $url;
	}

	/**
	 * Collect all font IDs/family identifiers referenced by an order item payload and product config.
	 *
	 * @param array<string, mixed> $data   Order item _mr_tee_editor_data.
	 * @param array<string, mixed> $config Product config.
	 * @return array<int, string>
	 */
	private function collect_font_ids_for_item( array $data, array $config ) {
		$ids     = array();
		$payload = ! empty( $data['payload'] ) && is_array( $data['payload'] ) ? $data['payload'] : array();

		if ( ! empty( $payload['text_lines'] ) && is_array( $payload['text_lines'] ) ) {
			foreach ( $payload['text_lines'] as $tl ) {
				if ( ! empty( $tl['fontId'] ) ) {
					$ids[] = (string) $tl['fontId'];
				}
			}
		}

		$pv = ! empty( $payload['perso_visual'] ) && is_array( $payload['perso_visual'] ) ? $payload['perso_visual'] : array();
		$walk = function ( $nodes ) use ( &$walk, &$ids ) {
			if ( ! is_array( $nodes ) ) {
				return;
			}
			foreach ( $nodes as $n ) {
				if ( ! is_array( $n ) ) {
					continue;
				}
				if ( ! empty( $n['fontId'] ) ) {
					$ids[] = (string) $n['fontId'];
				}
				if ( ! empty( $n['fontFamily'] ) ) {
					$ids[] = (string) $n['fontFamily'];
				}
				if ( ! empty( $n['items'] ) ) {
					$walk( $n['items'] );
				}
			}
		};
		if ( ! empty( $pv['references'] ) ) {
			$walk( $pv['references'] );
		}
		if ( ! empty( $pv['layers'] ) ) {
			$walk( $pv['layers'] );
		}

		if ( ! empty( $config['allowed_fonts'] ) && is_array( $config['allowed_fonts'] ) ) {
			foreach ( $config['allowed_fonts'] as $fid ) {
				$ids[] = (string) $fid;
			}
		}
		if ( ! empty( $config['text_lines'] ) && is_array( $config['text_lines'] ) ) {
			foreach ( $config['text_lines'] as $tl ) {
				if ( ! empty( $tl['font'] ) ) {
					$ids[] = (string) $tl['font'];
				}
			}
		}

		return array_values( array_unique( array_filter( $ids ) ) );
	}

	/**
	 * Hydrate full-resolution OpenType-SVG font datasets (including fullMap) for admin order export.
	 *
	 * @param array<int, string>                 $font_ids  Font IDs or family names.
	 * @param array<string, array<string, mixed>> $all_fonts All registered fonts.
	 * @return array<string, array<string, mixed>>
	 */
	private function load_svg_fonts_for_ids( array $font_ids, array $all_fonts ) {
		$svg_fonts = array();
		foreach ( $font_ids as $raw_id ) {
			$clean_id = sanitize_key( $raw_id );
			$f_def    = isset( $all_fonts[ $raw_id ] ) ? $all_fonts[ $raw_id ] : ( isset( $all_fonts[ $clean_id ] ) ? $all_fonts[ $clean_id ] : \MrTeeEditor\Registry\FontRegistry::get_font( $raw_id ) );
			if ( empty( $f_def ) || empty( $f_def['id'] ) ) {
				continue;
			}
			$resolved_id = $f_def['id'];
			$is_ot_svg   = ( ! empty( $f_def['font_type'] ) && 'opentype_svg' === $f_def['font_type'] ) || ! empty( $f_def['color_svg'] );
			if ( ! $is_ot_svg ) {
				continue;
			}
			if ( ! isset( $svg_fonts[ $resolved_id ] ) ) {
				$svg_data = \MrTeeEditor\Registry\FontRegistry::get_svg_font_data( $resolved_id );
				if ( $svg_data ) {
					$svg_fonts[ $resolved_id ] = $svg_data;
				}
			}
		}
		return $svg_fonts;
	}

	/**
	 * AJAX handler: return scene-graph data, product config, fonts, and fullMap OpenType-SVG datasets
	 * for generating 300 DPI PNG and vector SVG files in WP Admin order screens.
	 */
	public function handle_admin_get_order_item_context() {
		if ( false === check_ajax_referer( 'mr_tee_admin_nonce', 'nonce', false ) ) {
			wp_send_json_error( array( 'message' => __( 'Nonce administrateur invalide ou expiré.', 'mr-tee-editor' ) ), 403 );
		}
		if ( ! current_user_can( 'manage_woocommerce' ) && ! current_user_can( 'edit_shop_orders' ) ) {
			wp_send_json_error( array( 'message' => __( 'Permission refusée.', 'mr-tee-editor' ) ), 403 );
		}

		$order_id = isset( $_REQUEST['order_id'] ) ? absint( $_REQUEST['order_id'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$item_id  = isset( $_REQUEST['item_id'] ) ? absint( $_REQUEST['item_id'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( $order_id <= 0 || $item_id <= 0 || ! function_exists( 'wc_get_order' ) ) {
			wp_send_json_error( array( 'message' => __( 'Paramètres de commande manquants.', 'mr-tee-editor' ) ), 400 );
		}

		$order = wc_get_order( $order_id );
		$item  = $order ? $order->get_item( $item_id ) : false;
		if ( ! $item ) {
			wp_send_json_error( array( 'message' => __( 'Article de commande introuvable.', 'mr-tee-editor' ) ), 404 );
		}

		$data = $item->get_meta( '_mr_tee_editor_data', true );
		if ( empty( $data ) || ! is_array( $data ) ) {
			wp_send_json_error( array( 'message' => __( 'Aucune donnée de personnalisation trouvée.', 'mr-tee-editor' ) ), 404 );
		}

		$product_id = method_exists( $item, 'get_product_id' ) ? (int) $item->get_product_id() : 0;
		$config     = ( $product_id > 0 && get_post( $product_id ) ) ? SceneGraphSchema::get_product_config( $product_id ) : array();
		unset( $config['fonts'] );

		$all_fonts     = \MrTeeEditor\Registry\FontRegistry::get_fonts();
		$font_ids      = $this->collect_font_ids_for_item( $data, $config );
		$svg_fonts     = $this->load_svg_fonts_for_ids( $font_ids, $all_fonts );
		$font_face_css = \MrTeeEditor\Registry\FontRegistry::get_font_face_css();

		wp_send_json_success(
			array(
				'order_id'    => $order_id,
				'item_id'     => $item_id,
				'design_key'  => ! empty( $data['key'] ) ? $data['key'] : '',
				'editor_data' => $data,
				'config'      => $config,
				'fonts'       => $all_fonts,
				'svgFonts'    => $svg_fonts,
				'fontFaceCss' => $font_face_css,
			)
		);
	}

	/**
	 * AJAX handler: bind uploaded 300 DPI PNG and vector SVG artifacts on disk to the order line item
	 * and synchronize the files into NBDesigner's order item directory.
	 */
	public function handle_admin_save_print_artifacts() {
		if ( false === check_ajax_referer( 'mr_tee_admin_nonce', 'nonce', false ) ) {
			wp_send_json_error( array( 'message' => __( 'Nonce administrateur invalide ou expiré.', 'mr-tee-editor' ) ), 403 );
		}
		if ( ! current_user_can( 'manage_woocommerce' ) && ! current_user_can( 'edit_shop_orders' ) ) {
			wp_send_json_error( array( 'message' => __( 'Permission refusée.', 'mr-tee-editor' ) ), 403 );
		}

		$order_id   = isset( $_POST['order_id'] ) ? absint( $_POST['order_id'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Missing
		$item_id    = isset( $_POST['item_id'] ) ? absint( $_POST['item_id'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Missing
		$raw_key    = isset( $_POST['design_key'] ) ? wp_unslash( $_POST['design_key'] ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized,WordPress.Security.NonceVerification.Missing
		$design_key = preg_replace( '/[^a-zA-Z0-9_-]/', '', $raw_key );

		if ( $order_id <= 0 || $item_id <= 0 || ! preg_match( '/^mrtee_[a-zA-Z0-9_-]{8,64}$/', $design_key ) || ! function_exists( 'wc_get_order' ) ) {
			wp_send_json_error( array( 'message' => __( 'Paramètres d\'enregistrement invalides.', 'mr-tee-editor' ) ), 400 );
		}

		$order = wc_get_order( $order_id );
		$item  = $order ? $order->get_item( $item_id ) : false;
		if ( ! $item ) {
			wp_send_json_error( array( 'message' => __( 'Article de commande introuvable.', 'mr-tee-editor' ) ), 404 );
		}

		$data = $item->get_meta( '_mr_tee_editor_data', true );
		if ( empty( $data ) || ! is_array( $data ) || ( ! empty( $data['key'] ) && $data['key'] !== $design_key ) ) {
			wp_send_json_error( array( 'message' => __( 'Clé de création incohérente.', 'mr-tee-editor' ) ), 400 );
		}

		$paths      = \MrTeeEditor\Export\PrintAssetGenerator::get_upload_dir( 'designs/' . $design_key );
		$svg_file   = trailingslashit( $paths['dir'] ) . 'design.svg';
		$svg_url    = trailingslashit( $paths['url'] ) . 'design.svg';
		$print_file = trailingslashit( $paths['dir'] ) . 'print_300dpi.png';
		$print_url  = trailingslashit( $paths['url'] ) . 'print_300dpi.png';

		if ( ! file_exists( $svg_file ) || ! filesize( $svg_file ) || ! file_exists( $print_file ) || ! filesize( $print_file ) ) {
			wp_send_json_error( array( 'message' => __( 'Les fichiers générés (SVG ou PNG 300 DPI) sont introuvables sur le disque.', 'mr-tee-editor' ) ), 400 );
		}

		$data['key']        = $design_key;
		$data['svg_file']   = $svg_file;
		$data['svg_url']    = $svg_url;
		$data['print_file'] = $print_file;
		$data['print_url']  = $print_url;

		$nbd_key = ! empty( $data['nbd']['key'] ) ? $data['nbd']['key'] : $item->get_meta( '_nbd', true );
		if ( ! empty( $nbd_key ) ) {
			$synced = \MrTeeEditor\Export\NBDesignerBridge::sync_print_artifacts( $nbd_key, $svg_file, $print_file );
			if ( empty( $data['nbd'] ) || ! is_array( $data['nbd'] ) ) {
				$data['nbd'] = array(
					'key'      => $nbd_key,
					'nbd_data' => array(),
				);
			}
			if ( empty( $data['nbd']['nbd_data'] ) || ! is_array( $data['nbd']['nbd_data'] ) ) {
				$data['nbd']['nbd_data'] = array();
			}
			$data['nbd']['nbd_data']['svg']        = $synced['svg'];
			$data['nbd']['nbd_data']['print_file'] = $synced['print_file'];
			$item->update_meta_data( '_nbdesigner_data', $data['nbd']['nbd_data'] );
		}

		$item->update_meta_data( '_mr_tee_editor_data', $data );
		$item->update_meta_data( '_mr_tee_svg_url', $svg_url );
		$item->update_meta_data( '_mr_tee_print_url', $print_url );
		$item->save();

		wp_send_json_success(
			array(
				'order_id'   => $order_id,
				'item_id'    => $item_id,
				'design_key' => $design_key,
				'nbd_key'    => $nbd_key ? $nbd_key : '',
				'svg_url'    => $this->normalize_same_site_url( $svg_url ),
				'print_url'  => $this->normalize_same_site_url( $print_url ),
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
	 * Filter admin script tag to load mr-tee-admin-order-export.js as an ES6 module.
	 *
	 * @param string $tag    HTML script tag.
	 * @param string $handle Enqueued script handle.
	 * @param string $src    Script URL.
	 * @return string
	 */
	public function filter_admin_script_loader_tag( $tag, $handle, $src ) {
		if ( 'mr-tee-admin-order-export-js' === $handle ) {
			$parsed    = wp_parse_url( $src );
			$url_host  = ! empty( $parsed['host'] ) ? $parsed['host'] : '';
			$site_host = wp_parse_url( home_url(), PHP_URL_HOST );
			$http_host = isset( $_SERVER['HTTP_HOST'] ) ? preg_replace( '/:\d+$/', '', sanitize_text_field( wp_unslash( $_SERVER['HTTP_HOST'] ) ) ) : '';
			$same_orig = ! $url_host || $url_host === $site_host || ( $http_host && $url_host === $http_host );

			$resolved_src = $src;
			if ( $same_orig && ! empty( $parsed['path'] ) ) {
				$resolved_src = $parsed['path'] . ( ! empty( $parsed['query'] ) ? '?' . $parsed['query'] : '' );
			}
			return sprintf( '<script type="module" src="%s" id="mr-tee-admin-order-export-js-js"></script>' . "\n", esc_url( $resolved_src ) );
		}
		return $tag;
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
	 * Build a file-modification-aware asset version string so admin caches bust on deploys.
	 *
	 * @param string $relative_path Path relative to MR_TEE_EDITOR_DIR.
	 * @return string
	 */
	private function get_asset_version( $relative_path ) {
		$full_path = MR_TEE_EDITOR_DIR . ltrim( $relative_path, '/' );
		$mtime     = file_exists( $full_path ) ? @filemtime( $full_path ) : false;
		return $mtime ? ( MR_TEE_EDITOR_VERSION . '.' . $mtime ) : MR_TEE_EDITOR_VERSION;
	}

	/**
	 * Enqueue admin scripts & styles on product edit and WooCommerce order edit screens (HPOS + CPT).
	 *
	 * @param string $hook Current admin screen hook.
	 */
	public function enqueue_admin_assets( $hook ) {
		global $post;

		$proto = is_ssl() ? 'https' : 'http';

		// 1. WooCommerce Order edit screens (HPOS `wc-orders` and legacy CPT `shop_order`)
		$order_id       = 0;
		$hpos_screen    = function_exists( 'wc_get_page_screen_id' ) ? wc_get_page_screen_id( 'shop-order' ) : 'woocommerce_page_wc-orders';
		$is_hpos_orders = ( $hook === $hpos_screen || 'woocommerce_page_wc-orders' === $hook || ( isset( $_GET['page'] ) && 'wc-orders' === sanitize_key( wp_unslash( $_GET['page'] ) ) ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( $is_hpos_orders && ! empty( $_GET['id'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$action = isset( $_GET['action'] ) ? sanitize_key( wp_unslash( $_GET['action'] ) ) : 'edit'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			if ( 'edit' === $action ) {
				$order_id = absint( $_GET['id'] ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			}
		} elseif ( in_array( $hook, array( 'post.php', 'post-new.php' ), true ) && ! empty( $post ) && 'shop_order' === $post->post_type ) {
			$order_id = absint( $post->ID );
		}

		if ( $order_id > 0 && function_exists( 'wc_get_order' ) ) {
			$order = wc_get_order( $order_id );
			if ( $order ) {
				$has_mr_tee_items = false;
				$order_font_ids   = array();
				foreach ( $order->get_items() as $item ) {
					$data = $item->get_meta( '_mr_tee_editor_data', true );
					if ( ! empty( $data ) && is_array( $data ) ) {
						$has_mr_tee_items = true;
						$pid              = method_exists( $item, 'get_product_id' ) ? (int) $item->get_product_id() : 0;
						$cfg              = ( $pid > 0 && get_post( $pid ) ) ? SceneGraphSchema::get_product_config( $pid ) : array();
						$order_font_ids   = array_merge( $order_font_ids, $this->collect_font_ids_for_item( $data, $cfg ) );
					}
				}

				if ( $has_mr_tee_items ) {
					$all_fonts     = \MrTeeEditor\Registry\FontRegistry::get_fonts();
					$order_font_ids = array_values( array_unique( array_filter( $order_font_ids ) ) );
					$svg_fonts     = $this->load_svg_fonts_for_ids( $order_font_ids, $all_fonts );
					$font_face_css = \MrTeeEditor\Registry\FontRegistry::get_font_face_css();

					wp_enqueue_script(
						'mr-tee-admin-order-export-js',
						MR_TEE_EDITOR_URL . 'assets/js/mr-tee-admin-order-export.js',
						array(),
						$this->get_asset_version( 'assets/js/mr-tee-admin-order-export.js' ),
						true
					);

					wp_localize_script(
						'mr-tee-admin-order-export-js',
						'mrTeeOrderExportData',
						array(
							'orderId'     => $order_id,
							'ajaxUrl'     => admin_url( 'admin-ajax.php', $proto ),
							'nonce'       => wp_create_nonce( 'mr_tee_admin_nonce' ),
							'pluginUrl'   => MR_TEE_EDITOR_URL,
							'fonts'       => $all_fonts,
							'svgFonts'    => $svg_fonts,
							'fontFaceCss' => $font_face_css,
						)
					);
				}
			}
			return;
		}

		// 2. Product edit screens
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
			$this->get_asset_version( 'assets/css/mr-tee-admin.css' )
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
			$this->get_asset_version( 'assets/js/mr-tee-admin.js' ),
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
