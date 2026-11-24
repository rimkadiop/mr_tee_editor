<?php
/**
 * Mr. Tee Editor - High-Resolution Print Asset Generator
 *
 * Persists 300 DPI transparent raster PNGs and standalone vector SVG files
 * to disk in `wp-content/uploads/mr_tee_editor/` with robust sanitization.
 *
 * @package MrTeeEditor\Export
 */

namespace MrTeeEditor\Export;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class PrintAssetGenerator {

	/**
	 * Track root upload directories already hardened in this request.
	 *
	 * @var array<string, bool>
	 */
	private static $hardened_roots = array();

	/**
	 * Maximum decoded raster size accepted by save_base64_png() (5 MB).
	 */
	const MAX_BASE64_PNG_BYTES = 5242880;

	/**
	 * Ensure the root `mr_tee_editor` upload directory contains security `index.php`
	 * and `.htaccess` files (forcing nosniff and sandboxing SVG responses).
	 *
	 * @param string $root_dir Absolute path to `uploads/mr_tee_editor`.
	 */
	private static function harden_root_upload_dir( $root_dir ) {
		if ( isset( self::$hardened_roots[ $root_dir ] ) ) {
			return;
		}
		if ( ! file_exists( $root_dir ) ) {
			wp_mkdir_p( $root_dir );
		}
		if ( ! is_dir( $root_dir ) ) {
			return;
		}

		$index_file = trailingslashit( $root_dir ) . 'index.php';
		if ( ! file_exists( $index_file ) ) {
			@file_put_contents( $index_file, "<?php\n// Silence is golden.\n" ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents, WordPress.PHP.NoSilencedErrors.Discouraged
		}

		$htaccess_file = trailingslashit( $root_dir ) . '.htaccess';
		if ( ! file_exists( $htaccess_file ) ) {
			$rules = "# Mr. Tee Editor - Upload Directory Security\n"
				. "<IfModule mod_headers.c>\n"
				. "    Header always set X-Content-Type-Options \"nosniff\"\n"
				. "    <FilesMatch \"\\.svgz?$\">\n"
				. "        Header always set Content-Security-Policy \"sandbox\"\n"
				. "        Header always set X-Content-Type-Options \"nosniff\"\n"
				. "    </FilesMatch>\n"
				. "</IfModule>\n";
			@file_put_contents( $htaccess_file, $rules ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents, WordPress.PHP.NoSilencedErrors.Discouraged
		}

		self::$hardened_roots[ $root_dir ] = true;
	}

	/**
	 * Get or create the base upload directory for Mr. Tee Editor assets.
	 *
	 * Rejects any path traversal (`..`) segments and ensures the root upload
	 * directory is hardened with `index.php` and `.htaccess`.
	 *
	 * @param string $sub_dir Subdirectory path (e.g. 'orders/19310').
	 * @return array{dir: string, url: string}
	 */
	public static function get_upload_dir( $sub_dir = '' ) {
		$wp_upload = wp_upload_dir();
		$root_dir  = trailingslashit( $wp_upload['basedir'] ) . 'mr_tee_editor';
		$root_url  = trailingslashit( $wp_upload['baseurl'] ) . 'mr_tee_editor';

		self::harden_root_upload_dir( $root_dir );

		$base_dir = $root_dir;
		$base_url = $root_url;

		if ( ! empty( $sub_dir ) && is_string( $sub_dir ) ) {
			$normalized    = str_replace( '\\', '/', $sub_dir );
			$raw_segments  = explode( '/', trim( $normalized, '/' ) );
			$safe_segments = array();
			$has_traversal = false;

			foreach ( $raw_segments as $segment ) {
				if ( '' === $segment || '.' === $segment ) {
					continue;
				}
				if ( '..' === $segment || false !== strpos( $segment, '..' ) ) {
					$has_traversal = true;
					break;
				}
				$clean = preg_replace( '/[^a-zA-Z0-9_-]/', '', $segment );
				if ( '' !== $clean ) {
					$safe_segments[] = $clean;
				}
			}

			if ( ! $has_traversal && ! empty( $safe_segments ) ) {
				$rel_path  = implode( '/', $safe_segments );
				$base_dir .= '/' . $rel_path;
				$base_url .= '/' . $rel_path;
			}
		}

		if ( ! file_exists( $base_dir ) ) {
			wp_mkdir_p( $base_dir );
		}

		return array(
			'dir' => $base_dir,
			'url' => $base_url,
		);
	}

	/**
	 * Store a base64 encoded raster image file to disk with strict size & MIME validation.
	 *
	 * @param string $b64_data Base64 image data string (with or without data:image/png;base64, prefix).
	 * @param string $sub_dir  Subdirectory (e.g. 'orders/19310').
	 * @param string $filename Target filename (e.g. 'print_300dpi.png').
	 * @return array{file: string, url: string}|false
	 */
	public static function save_base64_png( $b64_data, $sub_dir, $filename ) {
		if ( empty( $b64_data ) || ! is_string( $b64_data ) ) {
			return false;
		}
		if ( is_string( $sub_dir ) && false !== strpos( str_replace( '\\', '/', $sub_dir ), '..' ) ) {
			return false;
		}

		if ( false !== strpos( $b64_data, 'base64,' ) ) {
			$parts    = explode( 'base64,', $b64_data, 2 );
			$b64_data = $parts[1];
		}

		$clean_b64 = preg_replace( '/\s+/', '', $b64_data );
		if ( ! is_string( $clean_b64 ) || '' === $clean_b64 ) {
			return false;
		}

		// Pre-check encoded byte budget before decoding to prevent OOM on oversized payloads.
		$max_encoded_len = (int) ceil( self::MAX_BASE64_PNG_BYTES * 4 / 3 ) + 4;
		if ( strlen( $clean_b64 ) > $max_encoded_len ) {
			return false;
		}

		$binary = base64_decode( $clean_b64, true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode
		if ( false === $binary || '' === $binary || strlen( $binary ) > self::MAX_BASE64_PNG_BYTES ) {
			return false;
		}

		$img_info = @getimagesizefromstring( $binary ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		if ( false === $img_info || empty( $img_info['mime'] ) || 0 !== strpos( (string) $img_info['mime'], 'image/' ) ) {
			return false;
		}

		$safe_name = sanitize_file_name( basename( (string) $filename ) );
		if ( '' === $safe_name ) {
			return false;
		}

		$paths = self::get_upload_dir( $sub_dir );
		$file  = trailingslashit( $paths['dir'] ) . $safe_name;
		$url   = trailingslashit( $paths['url'] ) . $safe_name;

		$saved = file_put_contents( $file, $binary ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
		if ( false === $saved ) {
			return false;
		}

		return array(
			'file' => $file,
			'url'  => $url,
		);
	}
}
