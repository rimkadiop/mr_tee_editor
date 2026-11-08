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
	 * Get or create the base upload directory for Mr. Tee Editor assets.
	 *
	 * @param string $sub Subdirectory path (e.g. 'orders/19310').
	 * @return array{dir: string, url: string}
	 */
	public static function get_upload_dir( $sub = '' ) {
		$wp_upload = wp_upload_dir();
		$base_dir  = trailingslashit( $wp_upload['basedir'] ) . 'mr_tee_editor';
		$base_url  = trailingslashit( $wp_upload['baseurl'] ) . 'mr_tee_editor';

		if ( ! empty( $sub ) ) {
			$base_dir .= '/' . trim( $sub, '/' );
			$base_url .= '/' . trim( $sub, '/' );
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
	 * Store a base64 encoded PNG file to disk.
	 *
	 * @param string $b64_data Base64 PNG data string (with or without data:image/png;base64, prefix).
	 * @param string $sub_dir  Subdirectory (e.g. 'orders/19310').
	 * @param string $filename Target filename (e.g. 'print_300dpi.png').
	 * @return array{file: string, url: string}|false
	 */
	public static function save_base64_png( $b64_data, $sub_dir, $filename ) {
		if ( empty( $b64_data ) ) {
			return false;
		}

		if ( strpos( $b64_data, 'base64,' ) !== false ) {
			$parts    = explode( 'base64,', $b64_data );
			$b64_data = $parts[1];
		}

		$binary = base64_decode( $b64_data );
		if ( ! $binary ) {
			return false;
		}

		$paths = self::get_upload_dir( $sub_dir );
		$file  = trailingslashit( $paths['dir'] ) . sanitize_file_name( $filename );
		$url   = trailingslashit( $paths['url'] ) . sanitize_file_name( $filename );

		$saved = file_put_contents( $file, $binary );
		if ( false === $saved ) {
			return false;
		}

		return array(
			'file' => $file,
			'url'  => $url,
		);
	}

	/**
	 * Store an SVG XML string to disk.
	 *
	 * @param string $svg_content Raw SVG markup.
	 * @param string $sub_dir     Subdirectory (e.g. 'orders/19310').
	 * @param string $filename    Target filename (e.g. 'design.svg').
	 * @return array{file: string, url: string}|false
	 */
	public static function save_svg( $svg_content, $sub_dir, $filename ) {
		if ( empty( $svg_content ) ) {
			return false;
		}

		$paths = self::get_upload_dir( $sub_dir );
		$file  = trailingslashit( $paths['dir'] ) . sanitize_file_name( $filename );
		$url   = trailingslashit( $paths['url'] ) . sanitize_file_name( $filename );

		$saved = file_put_contents( $file, $svg_content );
		if ( false === $saved ) {
			return false;
		}

		return array(
			'file' => $file,
			'url'  => $url,
		);
	}
}
