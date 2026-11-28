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
		add_action( 'init', array( $this, 'load_textdomain' ) );
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
		if ( is_admin() ) {
			EngineSelectorMetabox::init();
			OrderAdminPanel::init();
		}
		ProductPageRenderer::init();
		CartIntegration::init();
		\MrTeeEditor\Registry\FontRegistry::register_hooks();

		add_action( 'mr_tee_build_svg_font_packages', array( 'MrTeeEditor\\Registry\\SvgFontPackager', 'build_all' ) );

		add_action( 'wp_ajax_mr_tee_upload_customer_image', array( $this, 'handle_customer_image_upload' ) );
		add_action( 'wp_ajax_nopriv_mr_tee_upload_customer_image', array( $this, 'handle_customer_image_upload' ) );
		add_action( 'wp_ajax_mr_tee_upload_design_artifact', array( $this, 'handle_design_artifact_upload' ) );
		add_action( 'wp_ajax_nopriv_mr_tee_upload_design_artifact', array( $this, 'handle_design_artifact_upload' ) );
		add_action( 'wp_ajax_mr_tee_upload_default_image', array( $this, 'handle_admin_default_image_upload' ) );
		add_action( 'wp_ajax_mr_tee_admin_get_order_item_context', array( $this, 'handle_admin_get_order_item_context' ) );
		add_action( 'wp_ajax_mr_tee_admin_save_print_artifacts', array( $this, 'handle_admin_save_print_artifacts' ) );
		add_action( 'wp_ajax_mr_tee_delete_custom_font', array( '\MrTeeEditor\Registry\FontRegistry', 'ajax_delete_custom_font' ) );
	}

	/**
	 * Sanitize raw SVG markup against XSS, XXE, and script injection while preserving
	 * valid print/artwork SVG elements, embedded `@font-face` rules, and raster/SVG data URIs.
	 *
	 * Strips:
	 * - `<!DOCTYPE ...>` and `<!ENTITY ...>` declarations
	 * - `<script>`, `<foreignObject>`, `<iframe>`, `<object>`, `<embed>` elements (paired & self-closing)
	 * - All `on*=` event handler attributes (including slash-delimited like `<svg/onload=...>` and unquoted values)
	 * - `javascript:`, `vbscript:`, `data:text/html`, `data:application/xhtml+xml`, and `data:*javascript` URIs
	 *
	 * @param string $raw_svg Raw SVG XML markup.
	 * @return string Sanitized SVG markup.
	 */
	public static function sanitize_svg_markup( $raw_svg ) {
		if ( ! is_string( $raw_svg ) || '' === $raw_svg ) {
			return '';
		}

		// Strip NULL bytes that could split tokens or break parsers.
		$svg = str_replace( "\0", '', $raw_svg );

		// Strip DOCTYPE (including internal DTD subsets), ENTITY declarations, and xml-stylesheet PIs.
		$svg = (string) preg_replace( '/<!DOCTYPE\b[^>\[]*(?:\[[^\]]*\])?\s*>/is', '', $svg );
		$svg = (string) preg_replace( '/<!ENTITY\b[^>]*>/is', '', $svg );
		$svg = (string) preg_replace( '/<\?xml-stylesheet\b[^>]*\?>/is', '', $svg );

		// Repeatedly strip dangerous active elements until stable (defeats nested tag tricks).
		$dangerous_tags = 'script|foreignObject|iframe|object|embed';
		$max_passes     = 5;
		for ( $pass = 0; $pass < $max_passes; $pass++ ) {
			$prev = $svg;
			$svg  = (string) preg_replace( '#<(?:[a-z0-9_-]+:)?(' . $dangerous_tags . ')(?:[\s/](?:"[^"]*"|\'[^\']*\'|[^>"\']+)*)?\s*>.*?</(?:[a-z0-9_-]+:)?\1\s*>#is', '', $svg );
			$svg  = (string) preg_replace( '#</?(?:[a-z0-9_-]+:)?(' . $dangerous_tags . ')(?:[\s/](?:"[^"]*"|\'[^\']*\'|[^>"\']+)*)?\s*/?>#is', '', $svg );
			if ( $svg === $prev ) {
				break;
			}
		}

		// Sanitize CSS <style> contents against javascript:/vbscript:/data:text/html url() and legacy expression().
		$svg = (string) preg_replace_callback(
			'#(<style\b[^>]*>)(.*?)(</style\s*>)#is',
			static function ( $m ) {
				$css = $m[2];
				$css = (string) preg_replace( '/url\s*\(\s*[\'"]?\s*(?:javascript|vbscript)\s*:[^)]*\)/i', 'none', $css );
				$css = (string) preg_replace( '/url\s*\(\s*[\'"]?\s*data\s*:\s*(?:text\/html|application\/xhtml|text\/javascript|application\/javascript)[^)]*\)/i', 'none', $css );
				$css = (string) preg_replace( '/expression\s*\([^)]*\)/i', 'none', $css );
				$css = (string) preg_replace( '/@import\b[^;]+;?/i', '', $css );
				return $m[1] . $css . $m[3];
			},
			$svg
		);

		// Linear O(n) opening-tag attribute scanner (immune to PCRE backtrack limits on multi-MB base64 SVGs).
		$len    = strlen( $svg );
		$out    = '';
		$cursor = 0;

		while ( $cursor < $len ) {
			$lt = strpos( $svg, '<', $cursor );
			if ( false === $lt ) {
				$out .= substr( $svg, $cursor );
				break;
			}

			$out .= substr( $svg, $cursor, $lt - $cursor );

			if ( $lt + 1 >= $len ) {
				$out .= '<';
				break;
			}

			$next_ch = $svg[ $lt + 1 ];

			// Closing tags, comments, CDATA, and XML processing instructions: copy verbatim.
			if ( '/' === $next_ch || '!' === $next_ch || '?' === $next_ch ) {
				if ( '!' === $next_ch && 0 === substr_compare( $svg, '<!--', $lt, 4 ) ) {
					$end = strpos( $svg, '-->', $lt + 4 );
					$end = ( false === $end ) ? $len : ( $end + 3 );
				} elseif ( '!' === $next_ch && 0 === substr_compare( $svg, '<![CDATA[', $lt, 9 ) ) {
					$end = strpos( $svg, ']]>', $lt + 9 );
					$end = ( false === $end ) ? $len : ( $end + 3 );
				} elseif ( '?' === $next_ch ) {
					$end = strpos( $svg, '?>', $lt + 2 );
					$end = ( false === $end ) ? $len : ( $end + 2 );
				} else {
					$end = strpos( $svg, '>', $lt + 2 );
					$end = ( false === $end ) ? $len : ( $end + 1 );
				}
				$out   .= substr( $svg, $lt, $end - $lt );
				$cursor = $end;
				continue;
			}

			// Not an element start tag (e.g. stray '<' in text).
			if ( ! ctype_alpha( $next_ch ) ) {
				$out   .= '<';
				$cursor = $lt + 1;
				continue;
			}

			// Locate the matching '>' of this opening tag while jumping over quoted attribute values.
			$pos    = $lt + 1;
			$gt_pos = false;
			while ( $pos < $len ) {
				$ch = $svg[ $pos ];
				if ( '>' === $ch ) {
					$gt_pos = $pos;
					break;
				}
				if ( '"' === $ch || "'" === $ch ) {
					$close_q = strpos( $svg, $ch, $pos + 1 );
					if ( false === $close_q ) {
						$pos = $len;
						break;
					}
					$pos = $close_q + 1;
					continue;
				}
				$pos++;
			}

			if ( false === $gt_pos ) {
				$out .= substr( $svg, $lt );
				break;
			}

			$tag_raw = substr( $svg, $lt, $gt_pos - $lt + 1 );
			$cursor  = $gt_pos + 1;

			// Fast path: tag without attributes (no '=' present) cannot hold event handlers or URIs.
			if ( false === strpos( $tag_raw, '=' ) ) {
				$out .= $tag_raw;
				continue;
			}

			$out .= self::sanitize_svg_opening_tag( $tag_raw );
		}

		return $out;
	}

	/**
	 * Parse and sanitize attributes of a single SVG opening tag `<tagName ...>`.
	 *
	 * @param string $tag_raw Raw opening tag starting with `<` and ending with `>`.
	 * @return string Sanitized opening tag.
	 */
	private static function sanitize_svg_opening_tag( $tag_raw ) {
		$inner = substr( $tag_raw, 1, -1 );
		$ilen  = strlen( $inner );

		// Read tag name (stops at whitespace or '/').
		$p = 0;
		while ( $p < $ilen && ! ctype_space( $inner[ $p ] ) && '/' !== $inner[ $p ] ) {
			$p++;
		}
		$tag_name = substr( $inner, 0, $p );
		if ( '' === $tag_name ) {
			return $tag_raw;
		}

		// Detect trailing self-closing '/' (ignoring trailing whitespace).
		$trim_end     = rtrim( $inner );
		$self_closing = ( strlen( $trim_end ) > $p && '/' === substr( $trim_end, -1 ) );
		$attr_limit   = $self_closing ? ( strlen( $trim_end ) - 1 ) : strlen( $trim_end );

		$kept_attrs = array();

		while ( $p < $attr_limit ) {
			// Skip whitespace and stray '/' delimiters (e.g. `<svg/onload=...>`).
			while ( $p < $attr_limit && ( ctype_space( $inner[ $p ] ) || '/' === $inner[ $p ] ) ) {
				$p++;
			}
			if ( $p >= $attr_limit ) {
				break;
			}

			// Read attribute name.
			$name_start = $p;
			while ( $p < $attr_limit && ! ctype_space( $inner[ $p ] ) && '=' !== $inner[ $p ] && '/' !== $inner[ $p ] ) {
				$p++;
			}
			$attr_name = substr( $inner, $name_start, $p - $name_start );
			if ( '' === $attr_name ) {
				$p++;
				continue;
			}

			// Skip whitespace before optional '='.
			while ( $p < $attr_limit && ctype_space( $inner[ $p ] ) ) {
				$p++;
			}

			$has_value  = false;
			$quote_char = '';
			$raw_val    = '';

			if ( $p < $attr_limit && '=' === $inner[ $p ] ) {
				$has_value = true;
				$p++; // Consume '='.
				while ( $p < $attr_limit && ctype_space( $inner[ $p ] ) ) {
					$p++;
				}

				if ( $p < $attr_limit && ( '"' === $inner[ $p ] || "'" === $inner[ $p ] ) ) {
					$quote_char = $inner[ $p ];
					$val_start  = $p + 1;
					$close_q    = strpos( $inner, $quote_char, $val_start );
					if ( false === $close_q || $close_q >= $attr_limit ) {
						$raw_val = substr( $inner, $val_start, $attr_limit - $val_start );
						$p       = $attr_limit;
					} else {
						$raw_val = substr( $inner, $val_start, $close_q - $val_start );
						$p       = $close_q + 1;
					}
				} else {
					// Unquoted attribute value (ends at whitespace or slash delimiter).
					$val_start = $p;
					while ( $p < $attr_limit && ! ctype_space( $inner[ $p ] ) && '/' !== $inner[ $p ] ) {
						$p++;
					}
					$raw_val = substr( $inner, $val_start, $p - $val_start );
				}
			}

			$lower_name = strtolower( $attr_name );
			$colon_pos  = strrpos( $lower_name, ':' );
			$local_name = ( false !== $colon_pos ) ? substr( $lower_name, $colon_pos + 1 ) : $lower_name;

			// Drop ALL on*= event handler attributes (including namespaced variants).
			if ( preg_match( '/^on[a-z]/', $lower_name ) || preg_match( '/^on[a-z]/', $local_name ) ) {
				continue;
			}

			// Inspect attribute value for dangerous URI schemes while fast-passing pure raster base64 data URIs.
			if ( $has_value && '' !== $raw_val ) {
				$is_safe_raster_b64 = (bool) preg_match( '/^\s*data:image\/(?:png|jpeg|jpg|webp|gif);base64,/i', substr( $raw_val, 0, 64 ) );
				if ( ! $is_safe_raster_b64 ) {
					$decoded_val = html_entity_decode( $raw_val, ENT_QUOTES | ENT_HTML5, 'UTF-8' );
					$norm_val    = strtolower( (string) preg_replace( '/[\x00-\x20]+/', '', $decoded_val ) );
					if (
						preg_match( '/(?:^|url\s*\(\s*[\'"]?)(?:javascript|vbscript):/i', $norm_val ) ||
						preg_match( '/data:(?:text\/html|application\/xhtml\+xml|text\/javascript|application\/javascript)/i', $norm_val )
					) {
						continue;
					}
					if ( 'style' === $local_name && preg_match( '/expression\s*\(|@import/i', $norm_val ) ) {
						continue;
					}
				}
			}

			if ( $has_value ) {
				if ( '' !== $quote_char ) {
					$kept_attrs[] = $attr_name . '=' . $quote_char . $raw_val . $quote_char;
				} else {
					$kept_attrs[] = $attr_name . '="' . htmlspecialchars( $raw_val, ENT_QUOTES, 'UTF-8' ) . '"';
				}
			} else {
				$kept_attrs[] = $attr_name;
			}
		}

		$attrs_str = ! empty( $kept_attrs ) ? ( ' ' . implode( ' ', $kept_attrs ) ) : '';
		return '<' . $tag_name . $attrs_str . ( $self_closing ? '/>' : '>' );
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

		$file      = $_FILES['image_file']; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
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

		$raw_bytes = @file_get_contents( $file['tmp_name'] ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents, WordPress.PHP.NoSilencedErrors.Discouraged
		if ( false === $raw_bytes || '' === $raw_bytes ) {
			wp_send_json_error( array( 'message' => __( 'Impossible de lire le fichier image.', 'mr-tee-editor' ) ), 400 );
		}

		if ( 'svg' === $ext ) {
			if ( stripos( $raw_bytes, '<svg' ) === false ) {
				wp_send_json_error( array( 'message' => __( 'Fichier SVG invalide.', 'mr-tee-editor' ) ), 400 );
			}
			$raw_bytes = self::sanitize_svg_markup( $raw_bytes );
			if ( stripos( $raw_bytes, '<svg' ) === false ) {
				wp_send_json_error( array( 'message' => __( 'Fichier SVG invalide.', 'mr-tee-editor' ) ), 400 );
			}
		} else {
			$img_info = @getimagesizefromstring( $raw_bytes ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
			if ( false === $img_info || empty( $img_info['mime'] ) || strpos( $img_info['mime'], 'image/' ) !== 0 ) {
				wp_send_json_error( array( 'message' => __( 'Le fichier envoyé n\'est pas une image valide.', 'mr-tee-editor' ) ), 400 );
			}
		}

		$sub_dir   = 'customer_uploads/' . gmdate( 'Y-m' );
		$paths     = \MrTeeEditor\Export\PrintAssetGenerator::get_upload_dir( $sub_dir );
		$unique_fn = wp_unique_filename( $paths['dir'], wp_generate_uuid4() . '-' . $orig_name );
		$dest_file = trailingslashit( $paths['dir'] ) . $unique_fn;
		$dest_url  = trailingslashit( $paths['url'] ) . $unique_fn;

		if ( false === @file_put_contents( $dest_file, $raw_bytes ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents, WordPress.PHP.NoSilencedErrors.Discouraged
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
	 *
	 * Fully idempotent on chunk retries: each chunk is written to its own indexed part file
	 * (`.part_{upload_id}_{chunk_index}`) and sequentially assembled on the final chunk.
	 */
	public function handle_design_artifact_upload() {
		$artifact          = isset( $_POST['artifact'] ) ? sanitize_key( wp_unslash( $_POST['artifact'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing
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

		$chunk_index  = isset( $_POST['chunk_index'] ) ? intval( $_POST['chunk_index'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Missing
		$total_chunks = isset( $_POST['total_chunks'] ) ? intval( $_POST['total_chunks'] ) : 1; // phpcs:ignore WordPress.Security.NonceVerification.Missing
		if ( $total_chunks < 1 || $total_chunks > 200 || $chunk_index < 0 || $chunk_index >= $total_chunks ) {
			wp_send_json_error( array( 'message' => __( 'Paramètres de transfert invalides.', 'mr-tee-editor' ) ), 400 );
		}

		$upload_id = isset( $_POST['upload_id'] ) ? preg_replace( '/[^a-zA-Z0-9_-]/', '', wp_unslash( $_POST['upload_id'] ) ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized,WordPress.Security.NonceVerification.Missing
		if ( strlen( $upload_id ) < 6 || strlen( $upload_id ) > 64 ) {
			wp_send_json_error( array( 'message' => __( 'Identifiant de transfert invalide.', 'mr-tee-editor' ) ), 400 );
		}

		if ( empty( $_FILES['chunk_file'] ) || ! empty( $_FILES['chunk_file']['error'] ) ) {
			wp_send_json_error( array( 'message' => __( 'Fragment de fichier manquant.', 'mr-tee-editor' ) ), 400 );
		}

		$chunk_file  = $_FILES['chunk_file']; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		$chunk_bytes = @file_get_contents( $chunk_file['tmp_name'] ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents, WordPress.PHP.NoSilencedErrors.Discouraged
		if ( false === $chunk_bytes || strlen( $chunk_bytes ) > 2 * 1024 * 1024 ) {
			wp_send_json_error( array( 'message' => __( 'Fragment de fichier invalide ou trop volumineux.', 'mr-tee-editor' ) ), 400 );
		}

		$design_key = '';
		$orig_name  = '';
		$ext        = '';

		if ( 'customer_image' === $artifact ) {
			$orig_name = ! empty( $_POST['file_name'] ) ? sanitize_file_name( wp_unslash( $_POST['file_name'] ) ) : 'image.png'; // phpcs:ignore WordPress.Security.NonceVerification.Missing
			$ext       = strtolower( pathinfo( $orig_name, PATHINFO_EXTENSION ) );
			$allowed   = array( 'jpg', 'jpeg', 'png', 'webp', 'gif', 'svg' );
			if ( ! in_array( $ext, $allowed, true ) ) {
				wp_send_json_error( array( 'message' => __( 'Format non supporté. Utilisez PNG, JPG, WEBP, GIF ou SVG.', 'mr-tee-editor' ) ), 400 );
			}
			$sub_dir = 'customer_uploads/' . gmdate( 'Y-m' );
		} else {
			$raw_key    = isset( $_POST['design_key'] ) ? wp_unslash( $_POST['design_key'] ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized,WordPress.Security.NonceVerification.Missing
			$design_key = preg_replace( '/[^a-zA-Z0-9_-]/', '', $raw_key );
			if ( ! preg_match( '/^mrtee_[a-zA-Z0-9_-]{8,64}$/', $design_key ) ) {
				wp_send_json_error( array( 'message' => __( 'Clé de création invalide.', 'mr-tee-editor' ) ), 400 );
			}
			$sub_dir = 'designs/' . $design_key;
		}

		$paths          = \MrTeeEditor\Export\PrintAssetGenerator::get_upload_dir( $sub_dir );
		$part_prefix    = trailingslashit( $paths['dir'] ) . '.part_' . $upload_id . '_';
		$chunk_part     = $part_prefix . $chunk_index;
		$assembled_file = trailingslashit( $paths['dir'] ) . '.assembled_' . $upload_id;

		$cleanup_parts = static function () use ( $part_prefix, $total_chunks ) {
			for ( $ci = 0; $ci < $total_chunks; $ci++ ) {
				$f = $part_prefix . $ci;
				if ( file_exists( $f ) ) {
					@unlink( $f ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
				}
			}
			$leftovers = glob( $part_prefix . '*' );
			if ( is_array( $leftovers ) ) {
				foreach ( $leftovers as $lf ) {
					@unlink( $lf ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
				}
			}
		};

		// Write this chunk to its own indexed part file (100% idempotent on retries).
		$written = @file_put_contents( $chunk_part, $chunk_bytes ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents, WordPress.PHP.NoSilencedErrors.Discouraged
		if ( false === $written ) {
			@unlink( $chunk_part ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
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

		// Final chunk: verify all chunks 0..(total_chunks-1) exist and total size <= 35 MB.
		$max_assembled_bytes = 35 * 1024 * 1024; // 35 MB
		$total_size          = 0;
		for ( $ci = 0; $ci < $total_chunks; $ci++ ) {
			$c_path = $part_prefix . $ci;
			if ( ! file_exists( $c_path ) ) {
				$cleanup_parts();
				wp_send_json_error( array( 'message' => __( 'Session de transfert expirée ou fragment manquant.', 'mr-tee-editor' ) ), 400 );
			}
			$c_size = @filesize( $c_path ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
			if ( false === $c_size ) {
				$cleanup_parts();
				wp_send_json_error( array( 'message' => __( 'Impossible de lire les fragments sur le serveur.', 'mr-tee-editor' ) ), 500 );
			}
			$total_size += $c_size;
			if ( $total_size > $max_assembled_bytes ) {
				$cleanup_parts();
				wp_send_json_error( array( 'message' => __( 'Fichier assemblé trop volumineux (max 35 Mo).', 'mr-tee-editor' ) ), 400 );
			}
		}

		// Sequentially assemble all chunks into a temporary file, then unlink part files.
		$out_fp = @fopen( $assembled_file, 'wb' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen, WordPress.PHP.NoSilencedErrors.Discouraged
		if ( ! $out_fp ) {
			$cleanup_parts();
			wp_send_json_error( array( 'message' => __( 'Erreur d\'écriture sur le serveur.', 'mr-tee-editor' ) ), 500 );
		}

		for ( $ci = 0; $ci < $total_chunks; $ci++ ) {
			$c_data = @file_get_contents( $part_prefix . $ci ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents, WordPress.PHP.NoSilencedErrors.Discouraged
			if ( false === $c_data || false === @fwrite( $out_fp, $c_data ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite, WordPress.PHP.NoSilencedErrors.Discouraged
				fclose( $out_fp ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
				@unlink( $assembled_file ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
				$cleanup_parts();
				wp_send_json_error( array( 'message' => __( 'Erreur lors de l\'assemblage du fichier.', 'mr-tee-editor' ) ), 500 );
			}
		}
		fclose( $out_fp ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
		$cleanup_parts();

		// Validate complete assembled file and atomically move into place.
		if ( 'customer_image' === $artifact ) {
			if ( 'svg' === $ext ) {
				$svg_content = @file_get_contents( $assembled_file ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents, WordPress.PHP.NoSilencedErrors.Discouraged
				if ( false === $svg_content || stripos( $svg_content, '<svg' ) === false ) {
					@unlink( $assembled_file ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
					wp_send_json_error( array( 'message' => __( 'Fichier SVG invalide.', 'mr-tee-editor' ) ), 400 );
				}
				$svg_content = self::sanitize_svg_markup( $svg_content );
				if ( stripos( $svg_content, '<svg' ) === false ) {
					@unlink( $assembled_file ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
					wp_send_json_error( array( 'message' => __( 'Fichier SVG invalide.', 'mr-tee-editor' ) ), 400 );
				}
				@file_put_contents( $assembled_file, $svg_content ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents, WordPress.PHP.NoSilencedErrors.Discouraged
			} else {
				$img_info = @getimagesize( $assembled_file ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
				if ( false === $img_info || empty( $img_info['mime'] ) || strpos( $img_info['mime'], 'image/' ) !== 0 ) {
					@unlink( $assembled_file ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
					wp_send_json_error( array( 'message' => __( 'Le fichier envoyé n\'est pas une image valide.', 'mr-tee-editor' ) ), 400 );
				}
			}

			$unique_fn = wp_unique_filename( $paths['dir'], wp_generate_uuid4() . '-' . $orig_name );
			$dest_file = trailingslashit( $paths['dir'] ) . $unique_fn;
			$dest_url  = trailingslashit( $paths['url'] ) . $unique_fn;

			if ( ! @rename( $assembled_file, $dest_file ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
				@unlink( $assembled_file ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
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

		$raw_suffix = isset( $_POST['suffix'] ) ? wp_unslash( $_POST['suffix'] ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized,WordPress.Security.NonceVerification.Missing
		$suffix     = substr( preg_replace( '/[^a-zA-Z0-9_-]/', '', (string) $raw_suffix ), 0, 32 );
		$is_primary = isset( $_POST['is_primary'] ) && '1' === (string) wp_unslash( $_POST['is_primary'] ); // phpcs:ignore WordPress.Security.NonceVerification.Missing

		$canonical_names = array(
			'preview_jpg' => 'design_preview.jpg',
			'preview_png' => 'design_preview.png',
			'svg'         => 'design.svg',
			'print_png'   => 'print_300dpi.png',
		);
		$canonical_name = $canonical_names[ $artifact ];

		if ( '' !== $suffix ) {
			$suffixed_names = array(
				'preview_jpg' => 'design_preview_' . $suffix . '.jpg',
				'preview_png' => 'design_preview_' . $suffix . '.png',
				'svg'         => 'design_' . $suffix . '.svg',
				'print_png'   => 'print_300dpi_' . $suffix . '.png',
			);
			$target_name = $suffixed_names[ $artifact ];
		} else {
			$target_name = $canonical_name;
		}

		if ( 'svg' === $artifact ) {
			$svg_raw = @file_get_contents( $assembled_file ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents, WordPress.PHP.NoSilencedErrors.Discouraged
			if ( false === $svg_raw || stripos( $svg_raw, '<svg' ) === false ) {
				@unlink( $assembled_file ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
				wp_send_json_error( array( 'message' => __( 'Fichier SVG invalide.', 'mr-tee-editor' ) ), 400 );
			}
			$svg_raw = self::sanitize_svg_markup( $svg_raw );
			if ( stripos( $svg_raw, '<svg' ) === false ) {
				@unlink( $assembled_file ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
				wp_send_json_error( array( 'message' => __( 'Fichier SVG invalide.', 'mr-tee-editor' ) ), 400 );
			}
			@file_put_contents( $assembled_file, $svg_raw ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents, WordPress.PHP.NoSilencedErrors.Discouraged
		} else {
			$img_info = @getimagesize( $assembled_file ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
			if ( false === $img_info || empty( $img_info['mime'] ) || strpos( $img_info['mime'], 'image/' ) !== 0 ) {
				@unlink( $assembled_file ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
				wp_send_json_error( array( 'message' => __( 'Fichier image généré invalide.', 'mr-tee-editor' ) ), 400 );
			}
		}

		$dest_file = trailingslashit( $paths['dir'] ) . $target_name;
		$dest_url  = trailingslashit( $paths['url'] ) . $target_name;

		if ( ! @rename( $assembled_file, $dest_file ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
			@unlink( $assembled_file ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
			wp_send_json_error( array( 'message' => __( 'Erreur lors de la finalisation du fichier.', 'mr-tee-editor' ) ), 500 );
		}

		if ( $is_primary && '' !== $suffix ) {
			$canonical_file = trailingslashit( $paths['dir'] ) . $canonical_name;
			@copy( $dest_file, $canonical_file ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		}

		wp_send_json_success(
			array(
				'done'      => true,
				'artifact'  => $artifact,
				'suffix'    => $suffix,
				'file_name' => $target_name,
				'url'       => $dest_url,
				'bytes'     => @filesize( $dest_file ), // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
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
	 * Collect ONLY font IDs/family identifiers actually used by an order item payload
	 * (falling back to configured default line fonts only if the payload omits them).
	 *
	 * @param array<string, mixed> $data   Order item _mr_tee_editor_data.
	 * @param array<string, mixed> $config Product config.
	 * @return array<int, string>
	 */
	private function collect_font_ids_for_item( array $data, array $config ) {
		$ids     = array();
		$payload = ! empty( $data['payload'] ) && is_array( $data['payload'] ) ? $data['payload'] : array();

		$config_lines_by_id  = array();
		$config_lines_by_ref = array();
		$config_lines_list   = array();
		if ( ! empty( $config['text_lines'] ) && is_array( $config['text_lines'] ) ) {
			foreach ( array_values( $config['text_lines'] ) as $c_idx => $c_tl ) {
				if ( ! is_array( $c_tl ) || ( isset( $c_tl['field_type'] ) && 'image' === $c_tl['field_type'] ) ) {
					continue;
				}
				$config_lines_list[ $c_idx ] = $c_tl;
				if ( ! empty( $c_tl['id'] ) ) {
					$config_lines_by_id[ (string) $c_tl['id'] ] = $c_tl;
				}
				if ( ! empty( $c_tl['ref_id'] ) ) {
					$config_lines_by_ref[ (string) $c_tl['ref_id'] ] = $c_tl;
				}
			}
		}

		if ( ! empty( $payload['text_lines'] ) && is_array( $payload['text_lines'] ) ) {
			foreach ( array_values( $payload['text_lines'] ) as $p_idx => $tl ) {
				if ( ! is_array( $tl ) ) {
					continue;
				}
				if ( ( isset( $tl['fieldType'] ) && 'image' === $tl['fieldType'] ) || ( isset( $tl['field_type'] ) && 'image' === $tl['field_type'] ) ) {
					continue;
				}
				if ( ! empty( $tl['fontId'] ) ) {
					$ids[] = (string) $tl['fontId'];
				} elseif ( ! empty( $tl['font'] ) ) {
					$ids[] = (string) $tl['font'];
				} else {
					$matched_cfg = null;
					if ( ! empty( $tl['id'] ) && isset( $config_lines_by_id[ (string) $tl['id'] ] ) ) {
						$matched_cfg = $config_lines_by_id[ (string) $tl['id'] ];
					} elseif ( ! empty( $tl['refId'] ) && isset( $config_lines_by_ref[ (string) $tl['refId'] ] ) ) {
						$matched_cfg = $config_lines_by_ref[ (string) $tl['refId'] ];
					} elseif ( ! empty( $tl['ref_id'] ) && isset( $config_lines_by_ref[ (string) $tl['ref_id'] ] ) ) {
						$matched_cfg = $config_lines_by_ref[ (string) $tl['ref_id'] ];
					} elseif ( isset( $config_lines_list[ $p_idx ] ) ) {
						$matched_cfg = $config_lines_list[ $p_idx ];
					}
					if ( $matched_cfg && ! empty( $matched_cfg['font'] ) ) {
						$ids[] = (string) $matched_cfg['font'];
					}
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

		if ( empty( $ids ) && ! empty( $config_lines_list ) ) {
			foreach ( $config_lines_list as $tl ) {
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
				$svg_data = \MrTeeEditor\Registry\FontRegistry::get_svg_font_data( $resolved_id, true, true );
				if ( $svg_data ) {
					$svg_fonts[ $resolved_id ] = $svg_data;
				}
			}
		}
		return $svg_fonts;
	}

	/**
	 * Strip internal server filesystem paths from font definitions before exposing to client JS.
	 *
	 * @param array<string, array<string, mixed>> $all_fonts      Raw registered fonts map.
	 * @param bool                                $strip_data_url Whether to also strip legacy `data_url`.
	 * @return array<string, array<string, mixed>>
	 */
	private function sanitize_fonts_for_client( array $all_fonts, $strip_data_url = false ) {
		$client_fonts = array();
		foreach ( $all_fonts as $f_id => $f_meta ) {
			if ( ! is_array( $f_meta ) ) {
				continue;
			}
			unset( $f_meta['path'] );
			if ( $strip_data_url ) {
				unset( $f_meta['data_url'] );
			}
			if ( ! empty( $f_meta['url'] ) ) {
				$f_meta['url'] = SceneGraphSchema::normalize_url_scheme( $f_meta['url'] );
			}
			if ( ! empty( $f_meta['thumb_url'] ) ) {
				$f_meta['thumb_url'] = SceneGraphSchema::normalize_url_scheme( $f_meta['thumb_url'] );
			}
			$client_fonts[ $f_id ] = $f_meta;
		}
		return $client_fonts;
	}

	/**
	 * Determine whether an order item's `_mr_tee_editor_data` already has generated print artifacts.
	 *
	 * @param array<string, mixed> $data Order item customization metadata.
	 * @return bool
	 */
	private function order_item_has_print_artifacts( array $data ) {
		if ( ! empty( $data['side_exports'] ) && is_array( $data['side_exports'] ) ) {
			$valid_sides = 0;
			foreach ( $data['side_exports'] as $se ) {
				if ( is_array( $se ) && ! empty( $se['print_url'] ) && ! empty( $se['svg_url'] ) ) {
					$valid_sides++;
				}
			}
			if ( $valid_sides > 1 ) {
				return true;
			}
		}
		return ( ! empty( $data['print_url'] ) && ! empty( $data['svg_url'] ) );
	}

	/**
	 * Append a file-modification-time cache-buster (`?v=<mtime>`) to an artifact URL.
	 *
	 * @param string $url  Base URL without query string.
	 * @param string $file Absolute filesystem path to artifact.
	 * @return string
	 */
	private function append_file_version_query( $url, $file ) {
		$mtime = ( ! empty( $file ) && file_exists( $file ) ) ? @filemtime( $file ) : false; // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		$ver   = $mtime ? (string) $mtime : (string) time();
		$sep   = ( false === strpos( $url, '?' ) ) ? '?' : '&';
		return $url . $sep . 'v=' . $ver;
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
				'fonts'       => $this->sanitize_fonts_for_client( $all_fonts ),
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
		if ( empty( $data ) || ! is_array( $data ) || empty( $data['key'] ) || ! is_string( $data['key'] ) || ! hash_equals( $data['key'], $design_key ) ) {
			wp_send_json_error( array( 'message' => __( 'Clé de création incohérente.', 'mr-tee-editor' ) ), 400 );
		}

		$paths         = \MrTeeEditor\Export\PrintAssetGenerator::get_upload_dir( 'designs/' . $design_key );
		$raw_sides     = isset( $_POST['sides_json'] ) ? wp_unslash( $_POST['sides_json'] ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized,WordPress.Security.NonceVerification.Missing
		$decoded_sides = null;
		if ( '' !== trim( (string) $raw_sides ) ) {
			$decoded_sides = json_decode( $raw_sides, true );
			if ( JSON_ERROR_NONE !== json_last_error() || ! is_array( $decoded_sides ) ) {
				wp_send_json_error( array( 'message' => __( 'Payload sides_json invalide.', 'mr-tee-editor' ) ), 400 );
			}
		}
		$side_exports  = array();

		if ( is_array( $decoded_sides ) && ! empty( $decoded_sides ) ) {
			foreach ( $decoded_sides as $idx => $entry ) {
				if ( ! is_array( $entry ) ) {
					continue;
				}
				$raw_side_id = isset( $entry['side_id'] ) ? (string) $entry['side_id'] : '';
				$side_id     = substr( preg_replace( '/[^a-zA-Z0-9_-]/', '', $raw_side_id ), 0, 32 );
				if ( '' === $side_id ) {
					$side_id = ( 0 === $idx ) ? 'front' : 'side_' . $idx;
				}
				$label      = ! empty( $entry['label'] ) ? sanitize_text_field( (string) $entry['label'] ) : ucfirst( $side_id );
				$raw_suffix = isset( $entry['suffix'] ) ? (string) $entry['suffix'] : '';
				$suffix     = substr( preg_replace( '/[^a-zA-Z0-9_-]/', '', $raw_suffix ), 0, 32 );

				$svg_fn = ! empty( $entry['svg_file_name'] ) ? sanitize_file_name( basename( (string) $entry['svg_file_name'] ) ) : '';
				if ( ! preg_match( '/^design(?:_[a-zA-Z0-9_-]{1,32})?\.svg$/', $svg_fn ) ) {
					$svg_fn = ( '' !== $suffix ) ? 'design_' . $suffix . '.svg' : 'design.svg';
				}

				$print_fn = ! empty( $entry['print_file_name'] ) ? sanitize_file_name( basename( (string) $entry['print_file_name'] ) ) : '';
				if ( ! preg_match( '/^print_300dpi(?:_[a-zA-Z0-9_-]{1,32})?\.png$/', $print_fn ) ) {
					$print_fn = ( '' !== $suffix ) ? 'print_300dpi_' . $suffix . '.png' : 'print_300dpi.png';
				}

				$s_svg_file   = trailingslashit( $paths['dir'] ) . $svg_fn;
				$s_print_file = trailingslashit( $paths['dir'] ) . $print_fn;

				if ( ! file_exists( $s_svg_file ) || ! filesize( $s_svg_file ) || ! file_exists( $s_print_file ) || ! filesize( $s_print_file ) ) {
					wp_send_json_error(
						array(
							'message' => sprintf(
								/* translators: %s: side label */
								__( 'Les fichiers générés (SVG ou PNG 300 DPI) pour la face « %s » sont introuvables sur le disque.', 'mr-tee-editor' ),
								$label
							),
						),
						400
					);
				}

				$s_svg_url   = $this->append_file_version_query( trailingslashit( $paths['url'] ) . $svg_fn, $s_svg_file );
				$s_print_url = $this->append_file_version_query( trailingslashit( $paths['url'] ) . $print_fn, $s_print_file );

				$side_exports[ $side_id ] = array(
					'side_id'          => $side_id,
					'label'            => $label,
					'svg_file'         => $s_svg_file,
					'svg_url'          => $s_svg_url,
					'print_file'       => $s_print_file,
					'print_url'        => $s_print_url,
					'print_dimensions' => ! empty( $entry['print_dimensions'] ) ? sanitize_text_field( (string) $entry['print_dimensions'] ) : '',
					'real_width'       => ! empty( $entry['real_width'] ) ? floatval( $entry['real_width'] ) : 0.0,
					'real_height'      => ! empty( $entry['real_height'] ) ? floatval( $entry['real_height'] ) : 0.0,
					'width_px'         => ! empty( $entry['width_px'] ) ? intval( $entry['width_px'] ) : 0,
					'height_px'        => ! empty( $entry['height_px'] ) ? intval( $entry['height_px'] ) : 0,
					'unit'             => ! empty( $entry['unit'] ) ? sanitize_key( (string) $entry['unit'] ) : '',
					'dpi'              => ! empty( $entry['dpi'] ) ? intval( $entry['dpi'] ) : 0,
				);
			}
		}

		if ( ! empty( $side_exports ) ) {
			$primary_side         = reset( $side_exports );
			$svg_file             = $primary_side['svg_file'];
			$svg_url              = $primary_side['svg_url'];
			$print_file           = $primary_side['print_file'];
			$print_url            = $primary_side['print_url'];
			$data['side_exports'] = $side_exports;

			if ( ! empty( $primary_side['print_dimensions'] ) ) {
				$data['print_dimensions'] = $primary_side['print_dimensions'];
			}
			if ( ! empty( $primary_side['real_width'] ) && $primary_side['real_width'] > 0 ) {
				$data['print_real_width'] = $primary_side['real_width'];
			}
			if ( ! empty( $primary_side['real_height'] ) && $primary_side['real_height'] > 0 ) {
				$data['print_real_height'] = $primary_side['real_height'];
			}
			if ( ! empty( $primary_side['width_px'] ) && $primary_side['width_px'] > 0 ) {
				$data['print_width_px'] = $primary_side['width_px'];
			}
			if ( ! empty( $primary_side['height_px'] ) && $primary_side['height_px'] > 0 ) {
				$data['print_height_px'] = $primary_side['height_px'];
			}
		} else {
			$svg_file   = trailingslashit( $paths['dir'] ) . 'design.svg';
			$print_file = trailingslashit( $paths['dir'] ) . 'print_300dpi.png';

			if ( ! file_exists( $svg_file ) || ! filesize( $svg_file ) || ! file_exists( $print_file ) || ! filesize( $print_file ) ) {
				wp_send_json_error( array( 'message' => __( 'Les fichiers générés (SVG ou PNG 300 DPI) sont introuvables sur le disque.', 'mr-tee-editor' ) ), 400 );
			}

			$svg_url   = $this->append_file_version_query( trailingslashit( $paths['url'] ) . 'design.svg', $svg_file );
			$print_url = $this->append_file_version_query( trailingslashit( $paths['url'] ) . 'print_300dpi.png', $print_file );

			if ( ! empty( $_POST['print_dimensions'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing
				$data['print_dimensions'] = sanitize_text_field( wp_unslash( $_POST['print_dimensions'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Missing
			}
			if ( ! empty( $_POST['print_real_width'] ) && floatval( $_POST['print_real_width'] ) > 0 ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing
				$data['print_real_width'] = floatval( $_POST['print_real_width'] ); // phpcs:ignore WordPress.Security.NonceVerification.Missing
			}
			if ( ! empty( $_POST['print_real_height'] ) && floatval( $_POST['print_real_height'] ) > 0 ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing
				$data['print_real_height'] = floatval( $_POST['print_real_height'] ); // phpcs:ignore WordPress.Security.NonceVerification.Missing
			}
			if ( ! empty( $_POST['print_width_px'] ) && intval( $_POST['print_width_px'] ) > 0 ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing
				$data['print_width_px'] = intval( $_POST['print_width_px'] ); // phpcs:ignore WordPress.Security.NonceVerification.Missing
			}
			if ( ! empty( $_POST['print_height_px'] ) && intval( $_POST['print_height_px'] ) > 0 ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing
				$data['print_height_px'] = intval( $_POST['print_height_px'] ); // phpcs:ignore WordPress.Security.NonceVerification.Missing
			}
		}

		$data['key']        = $design_key;
		$data['svg_file']   = $svg_file;
		$data['svg_url']    = $svg_url;
		$data['print_file'] = $print_file;
		$data['print_url']  = $print_url;

		$nbd_key = ! empty( $data['nbd']['key'] ) ? $data['nbd']['key'] : $item->get_meta( '_nbd', true );
		if ( ! empty( $nbd_key ) ) {
			if ( ! empty( $side_exports ) && method_exists( '\MrTeeEditor\Export\NBDesignerBridge', 'sync_multi_side_artifacts' ) ) {
				$synced = \MrTeeEditor\Export\NBDesignerBridge::sync_multi_side_artifacts( $nbd_key, $side_exports );
			} else {
				$synced = \MrTeeEditor\Export\NBDesignerBridge::sync_print_artifacts( $nbd_key, $svg_file, $print_file );
			}
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
			if ( ! empty( $synced['sides'] ) ) {
				$data['nbd']['nbd_data']['sides'] = $synced['sides'];
			}
			$item->update_meta_data( '_nbdesigner_data', $data['nbd']['nbd_data'] );
		}

		$item->update_meta_data( '_mr_tee_editor_data', $data );
		$item->update_meta_data( '_mr_tee_svg_url', $svg_url );
		$item->update_meta_data( '_mr_tee_print_url', $print_url );
		$item->save();

		$response_sides = array();
		foreach ( $side_exports as $s_id => $s_exp ) {
			$response_sides[ $s_id ] = array(
				'side_id'          => $s_exp['side_id'],
				'label'            => $s_exp['label'],
				'svg_url'          => $this->normalize_same_site_url( $s_exp['svg_url'] ),
				'print_url'        => $this->normalize_same_site_url( $s_exp['print_url'] ),
				'print_dimensions' => $s_exp['print_dimensions'],
			);
		}

		wp_send_json_success(
			array(
				'order_id'     => $order_id,
				'item_id'      => $item_id,
				'design_key'   => $design_key,
				'nbd_key'      => $nbd_key ? $nbd_key : '',
				'svg_url'      => $this->normalize_same_site_url( $svg_url ),
				'print_url'    => $this->normalize_same_site_url( $print_url ),
				'side_exports' => $response_sides,
			)
		);
	}

	/**
	 * Handle direct file upload for admin default image field (stores in WP Media Library).
	 */
	public function handle_admin_default_image_upload() {
		if ( false === check_ajax_referer( 'mr_tee_admin_nonce', 'nonce', false ) ) {
			wp_send_json_error( array( 'message' => __( 'Nonce de sécurité invalide ou expiré.', 'mr-tee-editor' ) ), 403 );
		}

		if ( ! current_user_can( 'upload_files' ) || ( ! current_user_can( 'edit_products' ) && ! current_user_can( 'manage_woocommerce' ) ) ) {
			wp_send_json_error( array( 'message' => __( 'Permission refusée.', 'mr-tee-editor' ) ), 403 );
		}

		$product_id = isset( $_POST['product_id'] ) ? absint( $_POST['product_id'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Missing
		if ( $product_id > 0 && ! current_user_can( 'edit_post', $product_id ) ) {
			wp_send_json_error( array( 'message' => __( 'Permission refusée pour ce produit.', 'mr-tee-editor' ) ), 403 );
		}

		if ( empty( $_FILES['image_file'] ) || ! empty( $_FILES['image_file']['error'] ) ) {
			wp_send_json_error( array( 'message' => __( 'Aucun fichier image reçu.', 'mr-tee-editor' ) ), 400 );
		}

		$file      = $_FILES['image_file']; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		$max_bytes = 15 * 1024 * 1024; // 15 MB
		if ( ! empty( $file['size'] ) && intval( $file['size'] ) > $max_bytes ) {
			wp_send_json_error( array( 'message' => __( 'Image trop volumineuse (max 15 Mo).', 'mr-tee-editor' ) ), 400 );
		}

		$orig_name = ! empty( $file['name'] ) ? sanitize_file_name( wp_unslash( $file['name'] ) ) : '';
		$ext       = strtolower( pathinfo( $orig_name, PATHINFO_EXTENSION ) );
		$allowed   = array( 'jpg', 'jpeg', 'png', 'webp', 'gif' );
		if ( ! in_array( $ext, $allowed, true ) ) {
			wp_send_json_error( array( 'message' => __( 'Format non supporté. Utilisez une image JPG, PNG, WEBP ou GIF.', 'mr-tee-editor' ) ), 400 );
		}

		$allowed_mimes = array( 'image/jpeg', 'image/png', 'image/webp', 'image/gif' );
		$img_info      = ! empty( $file['tmp_name'] ) ? @getimagesize( $file['tmp_name'] ) : false; // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		if ( false === $img_info || empty( $img_info['mime'] ) || ! in_array( strtolower( (string) $img_info['mime'] ), $allowed_mimes, true ) ) {
			wp_send_json_error( array( 'message' => __( 'Le fichier envoyé n\'est pas une image valide.', 'mr-tee-editor' ) ), 400 );
		}

		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/image.php';
		require_once ABSPATH . 'wp-admin/includes/media.php';

		$attachment_id = media_handle_upload( 'image_file', $product_id );
		if ( is_wp_error( $attachment_id ) ) {
			wp_send_json_error( array( 'message' => $attachment_id->get_error_message() ), 400 );
		}

		$url = SceneGraphSchema::normalize_url_scheme( wp_get_attachment_url( $attachment_id ) );
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
			// Preserve existing script tag attributes (e.g. CSP nonce) while adding type="module" and normalized src
			$updated = preg_replace( '/\btype=(["\'])[^"\']*\1/i', '', $tag );
			$updated = preg_replace( '/\bsrc=(["\'])[^"\']*\1/i', 'src="' . esc_url( $resolved_src ) . '"', $updated, 1 );
			$updated = preg_replace( '/<script\b/i', '<script type="module"', $updated, 1 );
			return $updated ?: sprintf( '<script type="module" src="%s" id="mr-tee-admin-order-export-js-js"></script>' . "\n", esc_url( $resolved_src ) );
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
		$mtime     = file_exists( $full_path ) ? @filemtime( $full_path ) : false; // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
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
				$has_mr_tee_items  = false;
				$has_pending_print = false;
				$order_font_ids    = array();
				foreach ( $order->get_items() as $item ) {
					$data = $item->get_meta( '_mr_tee_editor_data', true );
					if ( ! empty( $data ) && is_array( $data ) ) {
						$has_mr_tee_items = true;
						if ( ! $this->order_item_has_print_artifacts( $data ) ) {
							$has_pending_print = true;
							$pid               = method_exists( $item, 'get_product_id' ) ? (int) $item->get_product_id() : 0;
							$cfg               = ( $pid > 0 && get_post( $pid ) )
								? SceneGraphSchema::get_product_config( $pid, array( 'compile_scene_graph' => false ) )
								: array();
							$order_font_ids    = array_merge( $order_font_ids, $this->collect_font_ids_for_item( $data, $cfg ) );
						}
					}
				}

				if ( $has_mr_tee_items ) {
					$all_fonts     = \MrTeeEditor\Registry\FontRegistry::get_fonts();
					$font_face_css = \MrTeeEditor\Registry\FontRegistry::get_font_face_css();

					// Eagerly hydrate OpenType-SVG datasets only when at least one item still needs print generation.
					$svg_fonts = array();
					if ( $has_pending_print ) {
						$order_font_ids = array_values( array_unique( array_filter( $order_font_ids ) ) );
						$svg_fonts      = $this->load_svg_fonts_for_ids( $order_font_ids, $all_fonts );
					}

					// Inject @font-face CSS into the admin document so Canvas TTF width measurement matches storefront 1:1.
					if ( '' !== $font_face_css ) {
						wp_register_style( 'mr-tee-admin-order-fonts', false, array(), MR_TEE_EDITOR_VERSION );
						wp_enqueue_style( 'mr-tee-admin-order-fonts' );
						wp_add_inline_style( 'mr-tee-admin-order-fonts', $font_face_css );
					}

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
							'fonts'       => $this->sanitize_fonts_for_client( $all_fonts ),
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

		if ( function_exists( 'wp_enqueue_media' ) && ! did_action( 'wp_enqueue_media' ) ) {
			wp_enqueue_media( array( 'post' => $post->ID ) );
		}

		$config = SceneGraphSchema::get_product_config( $post->ID, array( 'compile_scene_graph' => false ) );

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
			array( 'jquery' ),
			$this->get_asset_version( 'assets/js/mr-tee-admin.js' ),
			true
		);

		// Preload ONLY active text line OpenType-SVG fonts when Mr. Tee Editor is active
		$preloaded_svg_fonts = array();
		if ( ! empty( $config['engine'] ) && 'mr_tee_editor' === $config['engine'] && ! empty( $config['text_lines'] ) && is_array( $config['text_lines'] ) ) {
			foreach ( $config['text_lines'] as $line ) {
				if ( isset( $line['field_type'] ) && 'image' === $line['field_type'] ) {
					continue;
				}
				if ( empty( $line['font'] ) || isset( $preloaded_svg_fonts[ $line['font'] ] ) ) {
					continue;
				}
				$f_def = isset( $all_fonts[ $line['font'] ] ) ? $all_fonts[ $line['font'] ] : null;
				if ( $f_def && ( ( ! empty( $f_def['font_type'] ) && 'opentype_svg' === $f_def['font_type'] ) || ! empty( $f_def['color_svg'] ) ) ) {
					$svg_data = \MrTeeEditor\Registry\FontRegistry::get_svg_font_data( $line['font'], false, true );
					if ( $svg_data ) {
						// Omit 300-DPI fullMap from admin placement stage payload (saves ~15.5 KB per font)
						unset( $svg_data['fullMap'] );
						$preloaded_svg_fonts[ $line['font'] ] = $svg_data;
					}
				}
			}
		}

		// Strip internal server paths & legacy data_url while preserving `palette` for accurate admin WYSIWYG colors
		$admin_fonts = $this->sanitize_fonts_for_client( $all_fonts, true );

		// Strip duplicate fonts/scene-graph/palettes from localized admin config (~40 KB -> ~4 KB)
		$admin_config = $config;
		unset(
			$admin_config['fonts'],
			$admin_config['perso_visual'],
			$admin_config['color_sets'],
			$admin_config['icons'],
			$admin_config['solid_colors'],
			$admin_config['garment_colors']
		);

		wp_localize_script(
			'mr-tee-admin-js',
			'mrTeeAdminData',
			array(
				'productId' => $post->ID,
				'config'    => $admin_config,
				'fonts'     => $admin_fonts,
				'svgFonts'  => $preloaded_svg_fonts,
				'ajaxUrl'   => admin_url( 'admin-ajax.php', $proto ),
				'nonce'     => wp_create_nonce( 'mr_tee_admin_nonce' ),
				'pluginUrl' => MR_TEE_EDITOR_URL,
			)
		);
	}
}
