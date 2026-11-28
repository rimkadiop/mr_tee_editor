<?php
/**
 * Mr. Tee Editor - AI Media Store & Image Optimizer
 *
 * Handles:
 * - Converting local/uploaded reference images to compact <=1536px JPEG data URIs
 *   (immune to Cloudflare/firewall blocks preventing Runware from fetching staging/private URLs)
 * - SSRF-safe sideloading of generated AI artwork into uploads/mr_tee_editor/ai/YYYY-MM/
 *   keeping the full-resolution PNG for 300 DPI print export AND generating a lightweight
 *   ~512px WebP preview for ultra-fast mobile garment rendering
 * - Scheduled daily cleanup of abandoned temporary AI images
 *
 * @package MrTeeEditor\AI
 */

namespace MrTeeEditor\AI;

use MrTeeEditor\Domain\SceneGraphSchema;
use MrTeeEditor\Export\PrintAssetGenerator;
use MrTeeEditor\Registry\AiRegistry;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class AiMediaStore {

	const CRON_HOOK = 'mr_tee_ai_cleanup_cron';

	/**
	 * Register cron hook for orphan temporary AI media cleanup.
	 */
	public static function register_hooks() {
		add_action( self::CRON_HOOK, array( __CLASS__, 'cleanup_old_files' ) );
		if ( ! wp_next_scheduled( self::CRON_HOOK ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', self::CRON_HOOK );
		}
	}

	/**
	 * Convert a local WordPress attachment ID, local site URL, or raw image bytes into a
	 * compact base64 data URI (<= 1536px) for reliable Runware multi-reference transfer.
	 *
	 * @param int    $attachment_id Optional WP attachment ID.
	 * @param string $url           Optional image URL.
	 * @return string Data URI or normalized public HTTPS URL (empty if unreadable).
	 */
	public static function prepare_reference_image( $attachment_id = 0, $url = '' ) {
		$local_path = '';

		$att_id = absint( $attachment_id );
		if ( $att_id > 0 && function_exists( 'get_attached_file' ) ) {
			$candidate = (string) get_attached_file( $att_id );
			if ( '' !== $candidate && is_readable( $candidate ) ) {
				$local_path = $candidate;
			}
		}

		$clean_url = trim( (string) $url );
		if ( '' === $local_path && '' !== $clean_url ) {
			$local_path = self::resolve_local_upload_path( $clean_url );
		}

		if ( '' !== $local_path && is_readable( $local_path ) ) {
			$bytes = @file_get_contents( $local_path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents, WordPress.PHP.NoSilencedErrors.Discouraged
			if ( is_string( $bytes ) && '' !== $bytes ) {
				$data_uri = self::bytes_to_compact_data_uri( $bytes, 1536 );
				if ( '' !== $data_uri ) {
					return $data_uri;
				}
			}
		}

		if ( '' !== $clean_url && ( 0 === strpos( $clean_url, 'https://' ) || 0 === strpos( $clean_url, 'http://' ) || 0 === strpos( $clean_url, 'data:image/' ) ) ) {
			return $clean_url;
		}

		return '';
	}

	/**
	 * Convert raw uploaded raster bytes (e.g. customer selfie from $_FILES) into a compact data URI.
	 *
	 * @param string $raw_bytes Raw image bytes.
	 * @param int    $max_edge  Maximum width/height in pixels.
	 * @return string Data URI or empty string on invalid image.
	 */
	public static function bytes_to_compact_data_uri( $raw_bytes, $max_edge = 1536 ) {
		if ( ! is_string( $raw_bytes ) || '' === $raw_bytes ) {
			return '';
		}

		$info = @getimagesizefromstring( $raw_bytes ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		if ( false === $info || empty( $info[0] ) || empty( $info[1] ) || empty( $info['mime'] ) || 0 !== strpos( (string) $info['mime'], 'image/' ) ) {
			return '';
		}

		$w    = (int) $info[0];
		$h    = (int) $info[1];
		$mime = strtolower( (string) $info['mime'] );

		// Detect mobile camera EXIF Orientation on JPEGs so portraits never arrive sideways at Runware.
		$exif_orientation = 1;
		if ( 'image/jpeg' === $mime && function_exists( 'exif_read_data' ) ) {
			$mem = @fopen( 'php://temp', 'r+b' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen, WordPress.PHP.NoSilencedErrors.Discouraged
			if ( is_resource( $mem ) ) {
				@fwrite( $mem, $raw_bytes ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite, WordPress.PHP.NoSilencedErrors.Discouraged
				@rewind( $mem ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
				$exif = @exif_read_data( $mem ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
				@fclose( $mem ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose, WordPress.PHP.NoSilencedErrors.Discouraged
				if ( is_array( $exif ) && ! empty( $exif['Orientation'] ) ) {
					$exif_orientation = (int) $exif['Orientation'];
				}
			}
		}

		$needs_exif_rotate = in_array( $exif_orientation, array( 3, 6, 8 ), true );

		// If already reasonably sized (< 1.2 MB, within max_edge, and upright), send directly without re-encoding.
		if ( ! $needs_exif_rotate && $w <= $max_edge && $h <= $max_edge && strlen( $raw_bytes ) <= 1258291 && in_array( $mime, array( 'image/jpeg', 'image/png', 'image/webp' ), true ) ) {
			return 'data:' . $mime . ';base64,' . base64_encode( $raw_bytes ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
		}

		// Re-encode / downscale / auto-orient via GD when available.
		if ( function_exists( 'imagecreatefromstring' ) ) {
			$src = @imagecreatefromstring( $raw_bytes ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
			if ( $src ) {
				if ( $needs_exif_rotate && function_exists( 'imagerotate' ) ) {
					$angle   = ( 3 === $exif_orientation ) ? 180 : ( ( 6 === $exif_orientation ) ? -90 : 90 );
					$rotated = @imagerotate( $src, $angle, 0 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
					if ( $rotated ) {
						imagedestroy( $src );
						$src = $rotated;
						$w   = imagesx( $src );
						$h   = imagesy( $src );
					}
				}

				$scale         = min( 1.0, $max_edge / max( 1, max( $w, $h ) ) );
				$dst_w         = max( 1, (int) round( $w * $scale ) );
				$dst_h         = max( 1, (int) round( $h * $scale ) );
				$preserve_alpha = in_array( $mime, array( 'image/png', 'image/webp' ), true );

				$dst = imagecreatetruecolor( $dst_w, $dst_h );
				if ( $dst ) {
					if ( $preserve_alpha ) {
						imagealphablending( $dst, false );
						imagesavealpha( $dst, true );
						$transparent = imagecolorallocatealpha( $dst, 0, 0, 0, 127 );
						imagefill( $dst, 0, 0, $transparent );
						imagecopyresampled( $dst, $src, 0, 0, 0, 0, $dst_w, $dst_h, $w, $h );
						ob_start();
						if ( function_exists( 'imagewebp' ) ) {
							imagewebp( $dst, null, 88 );
							$out_mime = 'image/webp';
						} else {
							imagepng( $dst, null, 6 );
							$out_mime = 'image/png';
						}
						$out_bytes = ob_get_clean();
					} else {
						$white = imagecolorallocate( $dst, 255, 255, 255 );
						imagefill( $dst, 0, 0, $white );
						imagecopyresampled( $dst, $src, 0, 0, 0, 0, $dst_w, $dst_h, $w, $h );
						ob_start();
						imagejpeg( $dst, null, 88 );
						$out_bytes = ob_get_clean();
						$out_mime  = 'image/jpeg';
					}
					imagedestroy( $dst );
					imagedestroy( $src );
					if ( is_string( $out_bytes ) && '' !== $out_bytes ) {
						return 'data:' . $out_mime . ';base64,' . base64_encode( $out_bytes ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
					}
				} else {
					imagedestroy( $src );
				}
			}
		}

		return 'data:' . $mime . ';base64,' . base64_encode( $raw_bytes ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
	}

	/**
	 * Sideload a generated Runware image (HTTPS URL or base64 data URI) into local WP uploads,
	 * preserving the full-res original for 300 DPI print export and generating a fast WebP preview.
	 *
	 * @param string $source_url Remote URL or data URI returned by Runware.
	 * @return array<string, mixed>|\WP_Error
	 */
	public static function sideload_generated_image( $source_url ) {
		$src = trim( (string) $source_url );
		if ( '' === $src ) {
			return new \WP_Error( 'empty_source', __( 'Aucune image retournée par l\'IA.', 'mr-tee-editor' ) );
		}

		$raw_bytes = '';
		if ( 0 === stripos( $src, 'data:image/' ) ) {
			$comma = strpos( $src, ',' );
			if ( false === $comma ) {
				return new \WP_Error( 'invalid_data_uri', __( 'Données image IA invalides.', 'mr-tee-editor' ) );
			}
			$decoded = base64_decode( substr( $src, $comma + 1 ), true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode
			if ( false === $decoded || '' === $decoded ) {
				return new \WP_Error( 'invalid_base64', __( 'Impossible de décoder l\'image générée.', 'mr-tee-editor' ) );
			}
			$raw_bytes = $decoded;
		} elseif ( 0 === stripos( $src, 'https://' ) ) {
			$host = (string) wp_parse_url( $src, PHP_URL_HOST );
			if ( '' === $host || self::is_private_host( $host ) ) {
				return new \WP_Error( 'unsafe_host', __( 'Source image non autorisée.', 'mr-tee-editor' ) );
			}
			$resp = wp_remote_get(
				$src,
				array(
					'timeout'     => 45,
					'redirection' => 3,
				)
			);
			if ( is_wp_error( $resp ) ) {
				return new \WP_Error(
					'download_failed',
					sprintf( __( 'Impossible de télécharger l\'image IA : %s', 'mr-tee-editor' ), $resp->get_error_message() )
				);
			}
			$code = (int) wp_remote_retrieve_response_code( $resp );
			if ( $code < 200 || $code >= 300 ) {
				return new \WP_Error(
					'download_http_error',
					sprintf( __( 'Téléchargement de l\'image IA refusé (HTTP %d).', 'mr-tee-editor' ), $code )
				);
			}
			$raw_bytes = (string) wp_remote_retrieve_body( $resp );
		} else {
			return new \WP_Error( 'unsupported_scheme', __( 'Protocole d\'image non pris en charge.', 'mr-tee-editor' ) );
		}

		if ( '' === $raw_bytes ) {
			return new \WP_Error( 'empty_image_bytes', __( 'Image générée vide.', 'mr-tee-editor' ) );
		}

		$info = @getimagesizefromstring( $raw_bytes ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		if ( false === $info || empty( $info[0] ) || empty( $info[1] ) || empty( $info['mime'] ) || 0 !== strpos( (string) $info['mime'], 'image/' ) ) {
			return new \WP_Error( 'invalid_image', __( 'Le fichier retourné par l\'IA n\'est pas une image valide.', 'mr-tee-editor' ) );
		}

		$mime = strtolower( (string) $info['mime'] );
		$ext  = 'png';
		if ( 'image/jpeg' === $mime ) {
			$ext = 'jpg';
		} elseif ( 'image/webp' === $mime ) {
			$ext = 'webp';
		}

		$sub_dir   = 'ai/' . gmdate( 'Y-m' );
		$paths     = PrintAssetGenerator::get_upload_dir( $sub_dir );
		$uuid      = wp_generate_uuid4();
		$filename  = 'ai-' . substr( str_replace( '-', '', $uuid ), 0, 12 ) . '.' . $ext;
		$unique_fn = wp_unique_filename( $paths['dir'], $filename );
		$dest_file = trailingslashit( $paths['dir'] ) . $unique_fn;
		$dest_url  = SceneGraphSchema::normalize_url_scheme( trailingslashit( $paths['url'] ) . $unique_fn );

		if ( false === @file_put_contents( $dest_file, $raw_bytes ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents, WordPress.PHP.NoSilencedErrors.Discouraged
			return new \WP_Error( 'write_failed', __( 'Impossible d\'enregistrer l\'image IA sur le serveur.', 'mr-tee-editor' ) );
		}

		// Build a fast ~512px WebP preview alongside the full-resolution print file.
		$preview_url = self::create_fast_webp_preview( $raw_bytes, (int) $info[0], (int) $info[1], $paths['dir'], $paths['url'], pathinfo( $unique_fn, PATHINFO_FILENAME ) );
		if ( '' === $preview_url ) {
			$preview_url = $dest_url;
		}

		return array(
			'url'         => $dest_url,
			'preview_url' => SceneGraphSchema::normalize_url_scheme( $preview_url ),
			'path'        => $dest_file,
			'filename'    => $unique_fn,
			'width'       => (int) $info[0],
			'height'      => (int) $info[1],
		);
	}

	/**
	 * Create a ~512px WebP overlay preview (alpha-preserving) for fast mobile rendering.
	 *
	 * @param string $raw_bytes Raw image bytes.
	 * @param int    $orig_w    Original width.
	 * @param int    $orig_h    Original height.
	 * @param string $dir       Target directory path.
	 * @param string $base_url  Target directory URL.
	 * @param string $stem      Filename stem without extension.
	 * @return string Preview URL or empty string if WebP unsupported.
	 */
	private static function create_fast_webp_preview( $raw_bytes, $orig_w, $orig_h, $dir, $base_url, $stem ) {
		if ( ! function_exists( 'imagecreatefromstring' ) || ! function_exists( 'imagewebp' ) ) {
			return '';
		}
		$src = @imagecreatefromstring( $raw_bytes ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		if ( ! $src ) {
			return '';
		}

		$max_edge = 512;
		$scale    = min( 1.0, $max_edge / max( 1, max( $orig_w, $orig_h ) ) );
		$dst_w    = max( 1, (int) round( $orig_w * $scale ) );
		$dst_h    = max( 1, (int) round( $orig_h * $scale ) );

		$dst = imagecreatetruecolor( $dst_w, $dst_h );
		if ( ! $dst ) {
			imagedestroy( $src );
			return '';
		}

		imagealphablending( $dst, false );
		imagesavealpha( $dst, true );
		$transparent = imagecolorallocatealpha( $dst, 0, 0, 0, 127 );
		imagefill( $dst, 0, 0, $transparent );
		imagecopyresampled( $dst, $src, 0, 0, 0, 0, $dst_w, $dst_h, $orig_w, $orig_h );

		$prev_name = $stem . '_preview.webp';
		$prev_file = trailingslashit( $dir ) . $prev_name;
		$ok        = @imagewebp( $dst, $prev_file, 84 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged

		imagedestroy( $dst );
		imagedestroy( $src );

		if ( $ok && file_exists( $prev_file ) && filesize( $prev_file ) > 0 ) {
			return trailingslashit( $base_url ) . $prev_name;
		}
		return '';
	}

	/**
	 * Map a same-site uploads URL to its local filesystem path safely.
	 *
	 * @param string $url URL string.
	 * @return string Local readable path or empty string.
	 */
	private static function resolve_local_upload_path( $url ) {
		if ( ! function_exists( 'wp_upload_dir' ) ) {
			return '';
		}
		$up       = wp_upload_dir();
		$base_url = ! empty( $up['baseurl'] ) ? (string) $up['baseurl'] : '';
		$base_dir = ! empty( $up['basedir'] ) ? (string) $up['basedir'] : '';
		if ( '' === $base_url || '' === $base_dir ) {
			return '';
		}

		$url_path  = (string) wp_parse_url( $url, PHP_URL_PATH );
		$base_path = rtrim( (string) wp_parse_url( $base_url, PHP_URL_PATH ), '/' );
		if ( '' === $url_path || '' === $base_path || 0 !== strpos( $url_path, $base_path . '/' ) ) {
			return '';
		}

		$rel = ltrim( substr( $url_path, strlen( $base_path ) ), '/' );
		$rel = rawurldecode( $rel );
		if ( false !== strpos( $rel, '..' ) || false !== strpos( $rel, "\0" ) ) {
			return '';
		}

		$full = trailingslashit( $base_dir ) . $rel;
		$real = realpath( $full );
		$root = realpath( $base_dir );
		if ( false !== $real && false !== $root && 0 === strpos( $real, $root . DIRECTORY_SEPARATOR ) && is_readable( $real ) ) {
			return $real;
		}
		return '';
	}

	/**
	 * Guard against SSRF into private / loopback IP addresses.
	 *
	 * @param string $host Hostname.
	 * @return bool True if private/loopback.
	 */
	private static function is_private_host( $host ) {
		$lower = strtolower( trim( $host ) );
		if ( in_array( $lower, array( 'localhost', '127.0.0.1', '::1', '0.0.0.0' ), true ) ) {
			return true;
		}
		$ip = gethostbyname( $lower );
		if ( filter_var( $ip, FILTER_VALIDATE_IP ) ) {
			return false === filter_var( $ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE );
		}
		return false;
	}

	/**
	 * Daily cron cleanup of old temporary AI images not referenced by any WooCommerce order.
	 */
	public static function cleanup_old_files() {
		$settings = AiRegistry::get_settings();
		$days     = ! empty( $settings['cleanup_days'] ) ? (int) $settings['cleanup_days'] : 14;
		$cutoff   = time() - ( $days * DAY_IN_SECONDS );

		$root = PrintAssetGenerator::get_upload_dir( 'ai' );
		if ( empty( $root['dir'] ) || ! is_dir( $root['dir'] ) ) {
			return;
		}

		global $wpdb;
		$order_meta_table = $wpdb->prefix . 'woocommerce_order_itemmeta';
		$sessions_table   = $wpdb->prefix . 'woocommerce_sessions';
		$has_order_table  = ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $order_meta_table ) ) === $order_meta_table ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$has_sess_table   = ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $sessions_table ) ) === $sessions_table ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

		$iter = new \RecursiveIteratorIterator(
			new \RecursiveDirectoryIterator( $root['dir'], \FilesystemIterator::SKIP_DOTS ),
			\RecursiveIteratorIterator::CHILD_FIRST
		);

		$deleted = 0;
		foreach ( $iter as $file ) {
			if ( $deleted >= 150 || ! $file->isFile() ) {
				continue;
			}
			if ( $file->getMTime() >= $cutoff ) {
				continue;
			}
			$basename = $file->getFilename();
			if ( 'index.php' === $basename || '.htaccess' === $basename ) {
				continue;
			}
			$stem = preg_replace( '/_preview\.webp$/i', '', $basename );
			$like = '%' . $wpdb->esc_like( $stem ) . '%';

			// Never delete files referenced by a saved WooCommerce order or an active shopper cart session.
			if ( $has_order_table ) {
				$used = $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
					$wpdb->prepare(
						"SELECT meta_id FROM {$order_meta_table} WHERE meta_key = '_mr_tee_editor_data' AND meta_value LIKE %s LIMIT 1", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
						$like
					)
				);
				if ( ! empty( $used ) ) {
					continue;
				}
			}
			if ( $has_sess_table ) {
				$in_cart = $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
					$wpdb->prepare(
						"SELECT session_id FROM {$sessions_table} WHERE session_expiry > %d AND session_value LIKE %s LIMIT 1", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
						time(),
						$like
					)
				);
				if ( ! empty( $in_cart ) ) {
					continue;
				}
			}
			if ( @unlink( $file->getPathname() ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink, WordPress.PHP.NoSilencedErrors.Discouraged
				$deleted++;
			}
		}
	}
}
