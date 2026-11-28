<?php
/**
 * Mr. Tee Editor - OpenType-SVG Font Packager (Mobile-First Delivery Format v2)
 *
 * Bitmap-based OpenType-SVG fonts (Pizzeria, Partyboy, Brainzoo, Purple Magic,
 * Jellyjam, Lovespink...) embed one large base64 PNG per glyph inside their
 * 'SVG ' table. Shipping those as a single JSON file means 9–27 MB per font,
 * which freezes mobile browsers.
 *
 * This packager converts a parsed font into a lightweight, HTTP-cacheable package:
 *
 *   assets/fonts/cache/<font_id>/
 *     manifest.json      Metrics + glyph SVG wrappers (~20–40 KB, images replaced by tokens)
 *     p/<key>.webp       Preview tier (max 360 px, WebP q80) used for on-screen rendering
 *     f/<key>.<ext>      Full tier: the ORIGINAL embedded image bytes, untouched,
 *                        used only for the 300 DPI print export (zero quality loss)
 *     thumb.webp         Tiny sample glyph used by the font picker pills
 *
 * Images are deduplicated by content hash, and every URL is versioned so that the
 * web server can serve them with long-lived immutable caching.
 *
 * @package MrTeeEditor\Registry
 */

namespace MrTeeEditor\Registry;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class SvgFontPackager {

	/** Package format version. Bump to force a rebuild of every package. */
	const FORMAT = 'mrtee-svgfont-v2.4';

	/** Longest side (px) of the on-screen preview tier (256px provides sharp Retina on-screen rendering at ~12–20 KB/glyph while f/ keeps 100% original resolution for 300 DPI export). */
	const PREVIEW_MAX = 256;

	/** WebP quality of the preview tier (82 = crisp on-screen rendering with ultra-fast mobile alphabet pre-warming). */
	const PREVIEW_QUALITY = 82;

	/** Longest side (px) of the font picker thumbnail. */
	const THUMB_MAX = 72;

	/** Persistent lightweight WordPress option storing per-font package index metadata. */
	const INDEX_OPTION = 'mr_tee_svg_font_pkg_index_v24';

	/** Single-event WP-Cron hook scheduled when storefronts encounter missing/outdated packages. */
	const CRON_HOOK = 'mr_tee_build_svg_font_packages';

	/**
	 * Per-request in-memory cache of decoded font manifests.
	 *
	 * @var array<string, array<string, mixed>|null>
	 */
	private static $manifest_cache = array();

	/**
	 * Per-request in-memory cache of versioned asset URLs (manifest & thumb).
	 *
	 * @var array<string, string>
	 */
	private static $url_cache = array();

	/**
	 * Per-request memoized wp_upload_dir() array.
	 *
	 * @var array<string, mixed>|null
	 */
	private static $upload_dir_cache = null;

	/**
	 * Per-request memoized site host and HTTP host pair.
	 *
	 * @var array{site_host: string, http_host: string}|null
	 */
	private static $host_cache = null;

	/**
	 * Per-request memoized resolve_cache_location( $font_id, false, '' ) results.
	 *
	 * @var array<string, array{base_dir: string, base_url: string, dir: string, url_path: string}>
	 */
	private static $location_cache = array();

	/**
	 * Per-request memoized persistent package index loaded from WordPress options.
	 *
	 * @var array<string, array<string, mixed>>|null
	 */
	private static $index_cache = null;

	/**
	 * Sanitize a font identifier safely in both WordPress and standalone CLI contexts.
	 *
	 * @param string $font_id Raw font identifier.
	 * @return string
	 */
	private static function clean_id( $font_id ) {
		if ( function_exists( 'sanitize_key' ) ) {
			return sanitize_key( (string) $font_id );
		}
		return strtolower( (string) preg_replace( '/[^a-z0-9_\-]/i', '', (string) $font_id ) );
	}

	/**
	 * Normalize filesystem path separators safely in both WordPress and CLI contexts.
	 *
	 * @param string $path Raw filesystem path.
	 * @return string
	 */
	private static function normalize_path( $path ) {
		if ( function_exists( 'wp_normalize_path' ) ) {
			return wp_normalize_path( (string) $path );
		}
		return str_replace( '\\', '/', (string) $path );
	}

	/**
	 * Ensure a path/URL ends with a single forward slash.
	 *
	 * @param string $path Raw path or URL.
	 * @return string
	 */
	private static function trailingslash( $path ) {
		if ( function_exists( 'trailingslashit' ) ) {
			return trailingslashit( (string) $path );
		}
		return rtrim( (string) $path, '/\\' ) . '/';
	}

	/**
	 * Return memoized wp_upload_dir() metadata for this request.
	 *
	 * @return array<string, mixed>
	 */
	private static function get_upload_dir_info() {
		if ( null === self::$upload_dir_cache ) {
			self::$upload_dir_cache = function_exists( 'wp_upload_dir' ) ? wp_upload_dir() : array();
		}
		return self::$upload_dir_cache;
	}

	/**
	 * Get absolute base directory of the plugin.
	 *
	 * @return string
	 */
	public static function get_plugin_dir() {
		if ( defined( 'MR_TEE_EDITOR_DIR' ) ) {
			return MR_TEE_EDITOR_DIR;
		}
		return dirname( dirname( __DIR__ ) ) . '/';
	}

	/**
	 * Get base URL of the plugin.
	 *
	 * @return string
	 */
	public static function get_plugin_url() {
		if ( defined( 'MR_TEE_EDITOR_URL' ) ) {
			return MR_TEE_EDITOR_URL;
		}
		return function_exists( 'plugins_url' ) ? plugins_url( '/mr_tee_editor/' ) : '/wp-content/plugins/mr_tee_editor/';
	}

	/**
	 * Check whether a directory exists (or can be created) and is writable.
	 *
	 * @param string $dir Directory path.
	 * @return bool
	 */
	private static function is_dir_writable( $dir ) {
		if ( empty( $dir ) || ! is_string( $dir ) ) {
			return false;
		}
		if ( ! is_dir( $dir ) ) {
			if ( function_exists( 'wp_mkdir_p' ) ) {
				wp_mkdir_p( $dir );
			} else {
				@mkdir( $dir, 0755, true ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
			}
		}
		if ( ! is_dir( $dir ) ) {
			return false;
		}
		return function_exists( 'wp_is_writable' ) ? wp_is_writable( $dir ) : is_writable( $dir ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_is_writable
	}

	/**
	 * Recursively remove a directory ONLY if its canonical realpath resides strictly
	 * inside `$allowed_parent`.
	 *
	 * @param string $target_dir     Directory to remove.
	 * @param string $allowed_parent Parent directory boundary.
	 */
	private static function recursive_rmdir_inside( $target_dir, $allowed_parent ) {
		if ( ! is_dir( $target_dir ) || ! is_dir( $allowed_parent ) ) {
			return;
		}
		$real_target = realpath( $target_dir );
		$real_parent = realpath( $allowed_parent );
		if ( false === $real_target || false === $real_parent ) {
			return;
		}
		$norm_target = self::normalize_path( $real_target );
		$norm_parent = self::trailingslash( self::normalize_path( $real_parent ) );
		if ( 0 !== strpos( $norm_target, $norm_parent ) || $norm_target === rtrim( $norm_parent, '/' ) ) {
			return;
		}

		$entries = @scandir( $real_target ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		if ( is_array( $entries ) ) {
			foreach ( $entries as $entry ) {
				if ( '.' === $entry || '..' === $entry ) {
					continue;
				}
				$full = $real_target . DIRECTORY_SEPARATOR . $entry;
				if ( is_dir( $full ) && ! is_link( $full ) ) {
					self::recursive_rmdir_inside( $full, $real_parent );
				} else {
					@unlink( $full ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
				}
			}
		}
		@rmdir( $real_target ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
	}

	/**
	 * Convert same-origin full URL to root-relative URL path while preserving CDN/external URLs.
	 *
	 * @param string $url Raw URL.
	 * @return string
	 */
	private static function to_url_path( $url ) {
		if ( null === self::$host_cache ) {
			$site_host        = ( function_exists( 'home_url' ) && function_exists( 'wp_parse_url' ) )
				? strtolower( (string) wp_parse_url( home_url(), PHP_URL_HOST ) )
				: '';
			$http_host        = isset( $_SERVER['HTTP_HOST'] )
				? strtolower( (string) preg_replace( '/:\d+$/', '', (string) ( function_exists( 'wp_unslash' ) ? wp_unslash( $_SERVER['HTTP_HOST'] ) : $_SERVER['HTTP_HOST'] ) ) ) // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
				: '';
			self::$host_cache = array(
				'site_host' => $site_host,
				'http_host' => $http_host,
			);
		}

		$parsed    = function_exists( 'wp_parse_url' ) ? wp_parse_url( $url ) : parse_url( $url ); // phpcs:ignore WordPress.WP.AlternativeFunctions.parse_url_parse_url
		$url_host  = ! empty( $parsed['host'] ) ? strtolower( (string) $parsed['host'] ) : '';
		$site_host = self::$host_cache['site_host'];
		$http_host = self::$host_cache['http_host'];

		// Preserve full URL when served from an external CDN or distinct hostname.
		if ( '' !== $url_host && '' !== $site_host && $url_host !== $site_host && ( '' === $http_host || $url_host !== $http_host ) ) {
			return $url;
		}

		return ! empty( $parsed['path'] ) ? $parsed['path'] : $url;
	}

	/**
	 * Read the declared `"format":"..."` header from the first 160 bytes of a manifest.json file.
	 *
	 * @param string $manifest_file Absolute path to manifest.json.
	 * @return string Format identifier (e.g. `mrtee-svgfont-v2.4`), or empty string if unreadable/absent.
	 */
	private static function read_manifest_format_from_file( $manifest_file ) {
		if ( ! is_string( $manifest_file ) || '' === $manifest_file || ! file_exists( $manifest_file ) ) {
			return '';
		}
		$head = (string) @file_get_contents( $manifest_file, false, null, 0, 160 ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents, WordPress.PHP.NoSilencedErrors.Discouraged
		if ( '' !== $head && preg_match( '/"format"\s*:\s*"([^"]+)"/', $head, $m ) ) {
			return $m[1];
		}
		return '';
	}

	/**
	 * Check whether a specific manifest.json file exists, matches the current FORMAT,
	 * and (when `$font_path` is provided) is at least as new as the source font file.
	 *
	 * @param string $manifest_file Absolute path to manifest.json.
	 * @param string $font_path     Optional source font file path.
	 * @return bool
	 */
	private static function is_manifest_file_fresh( $manifest_file, $font_path = '' ) {
		if ( ! is_string( $manifest_file ) || '' === $manifest_file || ! file_exists( $manifest_file ) ) {
			return false;
		}
		if ( '' !== $font_path ) {
			if ( ! file_exists( $font_path ) ) {
				return false;
			}
			if ( filemtime( $manifest_file ) < filemtime( $font_path ) ) {
				return false;
			}
		}
		return self::FORMAT === self::read_manifest_format_from_file( $manifest_file );
	}

	/**
	 * Load the persistent package index from WordPress options (memoized per request).
	 *
	 * @return array<string, array<string, mixed>>
	 */
	private static function load_index() {
		if ( null !== self::$index_cache ) {
			return self::$index_cache;
		}
		if ( function_exists( 'get_option' ) ) {
			$raw               = get_option( self::INDEX_OPTION, array() );
			self::$index_cache = is_array( $raw ) ? $raw : array();
		} else {
			self::$index_cache = array();
		}
		return self::$index_cache;
	}

	/**
	 * Persist the lightweight package index to WordPress options.
	 *
	 * @param array<string, array<string, mixed>> $index Package index map.
	 */
	private static function save_index( array $index ) {
		self::$index_cache = $index;
		if ( function_exists( 'update_option' ) ) {
			update_option( self::INDEX_OPTION, $index, false );
		}
	}

	/**
	 * Update a single font's entry inside the persistent package index.
	 *
	 * @param string               $font_id Font identifier.
	 * @param array<string, mixed> $entry   Package metadata entry.
	 */
	private static function update_index_entry( $font_id, array $entry ) {
		$clean_id = self::clean_id( $font_id );
		if ( '' === $clean_id ) {
			return;
		}
		$index              = self::load_index();
		$index[ $clean_id ] = $entry;
		self::save_index( $index );
	}

	/**
	 * Probe filesystem metadata for a single font package once (used lazily on index miss or after build).
	 *
	 * @param string $font_id   Font identifier.
	 * @param string $font_path Optional source font path.
	 * @return array{format: string, manifest_url: string, thumb_url: string, dir: string, base_url: string, manifest_mtime: int}
	 */
	private static function probe_font_metadata( $font_id, $font_path = '' ) {
		$clean_id      = self::clean_id( $font_id );
		$loc           = self::resolve_cache_location( $clean_id, false, $font_path );
		$dir           = $loc['dir'];
		$url_path      = $loc['url_path'];
		$manifest_file = $dir . 'manifest.json';

		$format         = '';
		$manifest_mtime = 0;
		$manifest_url   = '';
		if ( file_exists( $manifest_file ) ) {
			$manifest_mtime = (int) @filemtime( $manifest_file ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
			$format         = self::read_manifest_format_from_file( $manifest_file );
			if ( '' !== $format ) {
				$manifest_url = $url_path . 'manifest.json?v=' . $manifest_mtime;
			}
		}

		$thumb_url = '';
		foreach ( array( 'thumb.webp', 'thumb.png' ) as $name ) {
			if ( file_exists( $dir . $name ) ) {
				$thumb_url = $url_path . $name . '?v=' . (int) @filemtime( $dir . $name ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
				break;
			}
		}

		return array(
			'format'         => $format,
			'manifest_url'   => $manifest_url,
			'thumb_url'      => $thumb_url,
			'dir'            => $dir,
			'base_url'       => $url_path,
			'manifest_mtime' => $manifest_mtime,
		);
	}

	/**
	 * Retrieve a font's metadata entry from the persistent option index, lazily probing disk
	 * once if not indexed yet.
	 *
	 * @param string $font_id          Font identifier.
	 * @param bool   $probe_if_missing Whether to probe disk & persist if absent from the index.
	 * @return array<string, mixed>|null
	 */
	public static function get_index_entry( $font_id, $probe_if_missing = true ) {
		$clean_id = self::clean_id( $font_id );
		if ( '' === $clean_id ) {
			return null;
		}
		$index = self::load_index();
		if ( isset( $index[ $clean_id ] ) && is_array( $index[ $clean_id ] ) ) {
			return $index[ $clean_id ];
		}
		if ( ! $probe_if_missing ) {
			return null;
		}
		$entry              = self::probe_font_metadata( $clean_id );
		$index[ $clean_id ] = $entry;
		self::save_index( $index );
		return $entry;
	}

	/**
	 * Batch-resolve manifest and thumbnail URLs for multiple OpenType-SVG fonts from the
	 * persistent option index. Steady-state page loads perform ZERO disk I/O and ZERO option writes.
	 *
	 * @param array<int, string> $font_ids Font identifiers.
	 * @return array<string, array{manifest_url: string, thumb_url: string, format: string}>
	 */
	public static function get_indexed_package_urls( array $font_ids ) {
		$index  = self::load_index();
		$dirty  = false;
		$result = array();

		foreach ( $font_ids as $font_id ) {
			$clean_id = self::clean_id( $font_id );
			if ( '' === $clean_id ) {
				continue;
			}
			if ( ! isset( $index[ $clean_id ] ) || ! is_array( $index[ $clean_id ] ) ) {
				$index[ $clean_id ] = self::probe_font_metadata( $clean_id );
				$dirty              = true;
			}
			$entry = $index[ $clean_id ];
			$m_url = ! empty( $entry['manifest_url'] ) ? (string) $entry['manifest_url'] : '';
			$t_url = ! empty( $entry['thumb_url'] ) ? (string) $entry['thumb_url'] : '';

			self::$url_cache[ 'm:' . $clean_id ] = $m_url;
			self::$url_cache[ 't:' . $clean_id ] = $t_url;
			if ( $font_id !== $clean_id ) {
				self::$url_cache[ 'm:' . $font_id ] = $m_url;
				self::$url_cache[ 't:' . $font_id ] = $t_url;
			}

			$result[ $font_id ] = array(
				'manifest_url' => $m_url,
				'thumb_url'    => $t_url,
				'format'       => ! empty( $entry['format'] ) ? (string) $entry['format'] : '',
			);
		}

		if ( $dirty ) {
			self::save_index( $index );
		}

		return $result;
	}

	/**
	 * Clear per-request static memoization caches (for a single font or all fonts).
	 *
	 * @param string $font_id Optional font identifier. Empty string clears all static caches.
	 */
	public static function invalidate_static_caches( $font_id = '' ) {
		if ( '' !== $font_id ) {
			$clean_id = self::clean_id( $font_id );
			unset(
				self::$manifest_cache[ $font_id ],
				self::$manifest_cache[ $clean_id ],
				self::$url_cache[ 'm:' . $font_id ],
				self::$url_cache[ 'm:' . $clean_id ],
				self::$url_cache[ 't:' . $font_id ],
				self::$url_cache[ 't:' . $clean_id ],
				self::$location_cache[ $font_id ],
				self::$location_cache[ $clean_id ]
			);
			return;
		}

		self::$manifest_cache   = array();
		self::$url_cache        = array();
		self::$location_cache   = array();
		self::$upload_dir_cache = null;
		self::$host_cache       = null;
		self::$index_cache      = null;
	}

	/**
	 * Remove one font (or the entire index when `$font_id === ''`) from the persistent WP option
	 * index and clear per-request static caches.
	 *
	 * @param string $font_id Optional font identifier. Empty string deletes the whole index option.
	 */
	public static function invalidate_index( $font_id = '' ) {
		if ( '' === $font_id ) {
			self::invalidate_static_caches();
			self::$index_cache = array();
			if ( function_exists( 'delete_option' ) ) {
				delete_option( self::INDEX_OPTION );
			}
			return;
		}

		$clean_id = self::clean_id( $font_id );
		if ( '' === $clean_id ) {
			return;
		}

		self::invalidate_static_caches( $clean_id );
		$index = self::load_index();
		if ( array_key_exists( $clean_id, $index ) ) {
			unset( $index[ $clean_id ] );
			self::save_index( $index );
		}
	}

	/**
	 * Delete a font's cached package directories (uploads and/or plugin cache) and invalidate
	 * both per-request static caches and the persistent WP option index.
	 *
	 * @param string $font_id Font identifier.
	 */
	public static function delete_package( $font_id ) {
		$clean_id = self::clean_id( $font_id );
		if ( '' === $clean_id ) {
			return;
		}

		$upload_base = self::uploads_cache_base_dir();
		if ( is_dir( $upload_base . $clean_id ) ) {
			self::recursive_rmdir_inside( $upload_base . $clean_id, $upload_base );
		}
		if ( file_exists( $upload_base . $clean_id . '.json' ) ) {
			@unlink( $upload_base . $clean_id . '.json' ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		}

		$plugin_base = self::get_plugin_dir() . 'assets/fonts/cache/';
		if ( is_dir( $plugin_base . $clean_id ) ) {
			self::recursive_rmdir_inside( $plugin_base . $clean_id, $plugin_base );
		}
		if ( file_exists( $plugin_base . $clean_id . '.json' ) ) {
			@unlink( $plugin_base . $clean_id . '.json' ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		}

		self::invalidate_static_caches( $clean_id );
		self::invalidate_index( $clean_id );
	}

	/**
	 * Schedule a single asynchronous WP-Cron job to build/upgrade OpenType-SVG packages
	 * without ever blocking storefront page rendering.
	 */
	public static function schedule_background_build() {
		if ( function_exists( 'wp_next_scheduled' ) && function_exists( 'wp_schedule_single_event' ) ) {
			if ( ! wp_next_scheduled( self::CRON_HOOK ) ) {
				wp_schedule_single_event( time(), self::CRON_HOOK );
			}
		}
	}

	/**
	 * Base uploads cache directory fallback when plugin directory is read-only.
	 *
	 * @return string
	 */
	public static function uploads_cache_base_dir() {
		$up = self::get_upload_dir_info();
		if ( ! empty( $up['basedir'] ) ) {
			return self::trailingslash( $up['basedir'] ) . 'mr_tee_editor/font-cache/';
		}
		return sys_get_temp_dir() . '/mr_tee_editor/font-cache/';
	}

	/**
	 * Base uploads cache URL fallback when plugin directory is read-only.
	 *
	 * @return string
	 */
	public static function uploads_cache_base_url() {
		$up = self::get_upload_dir_info();
		if ( ! empty( $up['baseurl'] ) ) {
			return self::trailingslash( $up['baseurl'] ) . 'mr_tee_editor/font-cache/';
		}
		return '/wp-content/uploads/mr_tee_editor/font-cache/';
	}

	/**
	 * Resolve cache location (`base_dir`, `base_url`, `dir`, `url_path`) for a font package.
	 *
	 * When reading (`$for_write === false`), prefers whichever location has a fresh manifest
	 * matching `self::FORMAT` (preferring uploads over a stale plugin-dir manifest).
	 *
	 * @param string $font_id   Font identifier.
	 * @param bool   $for_write Whether resolving for writing/building packages.
	 * @param string $font_path Optional source font path for mtime freshness comparison.
	 * @return array{base_dir: string, base_url: string, dir: string, url_path: string}
	 */
	private static function resolve_cache_location( $font_id, $for_write = false, $font_path = '' ) {
		$clean_id    = self::clean_id( $font_id );
		$use_memoize = ( ! $for_write && '' === $font_path );

		if ( $use_memoize && isset( self::$location_cache[ $clean_id ] ) ) {
			return self::$location_cache[ $clean_id ];
		}

		$plugin_base = self::get_plugin_dir() . 'assets/fonts/cache/';
		$plugin_url  = self::get_plugin_url() . 'assets/fonts/cache/';
		$plugin_dir  = $plugin_base . $clean_id . '/';

		$upload_base = self::uploads_cache_base_dir();
		$upload_url  = self::uploads_cache_base_url();
		$upload_dir  = $upload_base . $clean_id . '/';

		$plugin_loc = array(
			'base_dir' => $plugin_base,
			'base_url' => $plugin_url,
			'dir'      => $plugin_dir,
			'url_path' => self::to_url_path( $plugin_url . $clean_id . '/' ),
		);
		$upload_loc = array(
			'base_dir' => $upload_base,
			'base_url' => $upload_url,
			'dir'      => $upload_dir,
			'url_path' => self::to_url_path( $upload_url . $clean_id . '/' ),
		);

		$resolved = null;
		if ( ! $for_write ) {
			$plugin_manifest = $plugin_dir . 'manifest.json';
			$upload_manifest = $upload_dir . 'manifest.json';
			$plugin_fresh    = self::is_manifest_file_fresh( $plugin_manifest, $font_path );
			$upload_fresh    = self::is_manifest_file_fresh( $upload_manifest, $font_path );

			if ( $plugin_fresh && $upload_fresh ) {
				$resolved = ( filemtime( $upload_manifest ) > filemtime( $plugin_manifest ) ) ? $upload_loc : $plugin_loc;
			} elseif ( $plugin_fresh ) {
				$resolved = $plugin_loc;
			} elseif ( $upload_fresh ) {
				$resolved = $upload_loc;
			} elseif ( file_exists( $plugin_manifest ) || file_exists( $plugin_dir . 'thumb.webp' ) || file_exists( $plugin_dir . 'thumb.png' ) ) {
				$resolved = $plugin_loc;
			} elseif ( file_exists( $upload_manifest ) || file_exists( $upload_dir . 'thumb.webp' ) || file_exists( $upload_dir . 'thumb.png' ) ) {
				$resolved = $upload_loc;
			}
		}

		if ( null === $resolved ) {
			// Custom uploaded fonts (stored inside uploads/) must always package into uploads/ so plugin updates never wipe them.
			$is_custom_font_path = '' !== $font_path && 0 !== strpos( self::normalize_path( $font_path ), self::normalize_path( self::get_plugin_dir() ) );
			if ( ! $is_custom_font_path && self::is_dir_writable( $plugin_base ) ) {
				$resolved = $plugin_loc;
			} else {
				$resolved = $upload_loc;
			}
		}

		if ( $use_memoize ) {
			self::$location_cache[ $clean_id ] = $resolved;
		}

		return $resolved;
	}

	/**
	 * Absolute directory of a font package.
	 *
	 * @param string $font_id Font identifier.
	 * @return string
	 */
	public static function package_dir( $font_id ) {
		$entry = self::get_index_entry( $font_id, false );
		if ( is_array( $entry ) && ! empty( $entry['dir'] ) ) {
			$indexed_dir = (string) $entry['dir'];
			if ( 0 === strpos( $indexed_dir, self::get_plugin_dir() ) || is_dir( $indexed_dir ) ) {
				return $indexed_dir;
			}
		}
		$loc = self::resolve_cache_location( $font_id, false );
		return $loc['dir'];
	}

	/**
	 * Root-relative URL path of a font package (protocol/host agnostic to avoid
	 * mixed-content and http/https mismatch issues, while preserving external CDNs).
	 *
	 * @param string $font_id Font identifier.
	 * @return string
	 */
	public static function package_url_path( $font_id ) {
		$entry = self::get_index_entry( $font_id, false );
		if ( is_array( $entry ) && ! empty( $entry['base_url'] ) ) {
			return (string) $entry['base_url'];
		}
		$loc = self::resolve_cache_location( $font_id, false );
		return $loc['url_path'];
	}

	/**
	 * Absolute path of the package manifest.
	 *
	 * @param string $font_id Font identifier.
	 * @return string
	 */
	public static function manifest_path( $font_id ) {
		return self::package_dir( $font_id ) . 'manifest.json';
	}

	/**
	 * Versioned URL of the manifest (from persistent index with lazy disk probe), or empty string if absent.
	 *
	 * @param string $font_id Font identifier.
	 * @return string
	 */
	public static function manifest_url( $font_id ) {
		$clean_id  = self::clean_id( $font_id );
		$cache_key = 'm:' . $clean_id;
		if ( isset( self::$url_cache[ $cache_key ] ) ) {
			return self::$url_cache[ $cache_key ];
		}
		$entry                         = self::get_index_entry( $clean_id, true );
		$url                           = ( is_array( $entry ) && ! empty( $entry['manifest_url'] ) ) ? (string) $entry['manifest_url'] : '';
		self::$url_cache[ $cache_key ] = $url;
		return $url;
	}

	/**
	 * Versioned root-relative URL of the picker thumbnail (from persistent index with lazy disk probe), or empty string.
	 *
	 * @param string $font_id Font identifier.
	 * @return string
	 */
	public static function thumb_url( $font_id ) {
		$clean_id  = self::clean_id( $font_id );
		$cache_key = 't:' . $clean_id;
		if ( isset( self::$url_cache[ $cache_key ] ) ) {
			return self::$url_cache[ $cache_key ];
		}
		$entry                         = self::get_index_entry( $clean_id, true );
		$url                           = ( is_array( $entry ) && ! empty( $entry['thumb_url'] ) ) ? (string) $entry['thumb_url'] : '';
		self::$url_cache[ $cache_key ] = $url;
		return $url;
	}

	/**
	 * Is the package present and up to date for this font file?
	 *
	 * @param string $font_path Absolute font path.
	 * @param string $font_id   Font identifier.
	 * @return bool
	 */
	public static function is_fresh( $font_path, $font_id ) {
		$loc = self::resolve_cache_location( $font_id, false, $font_path );
		return self::is_manifest_file_fresh( $loc['dir'] . 'manifest.json', $font_path );
	}

	/**
	 * Load the raw manifest, optionally building the package first if stale/missing.
	 *
	 * Supports both `( $font_id, $font_path, $font_name, $allow_build )` and legacy
	 * `( $font_path, $font_id, $font_name, $allow_build )` argument orders.
	 *
	 * When `$allow_build === false` and no fresh v2.4 manifest exists yet:
	 *  - NEVER invokes `build()` synchronously;
	 *  - Schedules a single background WP-Cron event (`mr_tee_build_svg_font_packages`);
	 *  - Falls back to reading any existing v2.1/v2.2/v2.3 `manifest.json` on disk if readable, else `null`.
	 *
	 * @param string $font_path   Font identifier or absolute font path.
	 * @param string $font_id     Absolute font path or font identifier.
	 * @param string $font_name   Display name.
	 * @param bool   $allow_build Whether synchronous `build()` is allowed when stale/missing.
	 * @return array<string, mixed>|null
	 */
	public static function get_manifest( $font_path, $font_id = '', $font_name = '', $allow_build = true ) {
		// Normalize argument order so both ( $font_id, $font_path, ... ) and ( $font_path, $font_id, ... ) work seamlessly.
		$arg1         = (string) $font_path;
		$arg2         = (string) $font_id;
		$arg1_is_path = ( '' !== $arg1 ) && ( false !== strpos( $arg1, '/' ) || false !== strpos( $arg1, '\\' ) || (bool) preg_match( '/\.(?:ttf|otf)$/i', $arg1 ) );
		$arg2_is_path = ( '' !== $arg2 ) && ( false !== strpos( $arg2, '/' ) || false !== strpos( $arg2, '\\' ) || (bool) preg_match( '/\.(?:ttf|otf)$/i', $arg2 ) );

		if ( ! $arg1_is_path && '' !== $arg1 && ( $arg2_is_path || '' === $arg2 ) ) {
			$font_id   = $arg1;
			$font_path = $arg2;
		} else {
			$font_path = $arg1;
			$font_id   = $arg2;
		}

		$clean_id = self::clean_id( $font_id );
		if ( '' === $clean_id ) {
			return null;
		}

		if ( array_key_exists( $clean_id, self::$manifest_cache ) ) {
			$cached = self::$manifest_cache[ $clean_id ];
			if ( ! $allow_build || ( is_array( $cached ) && isset( $cached['format'] ) && self::FORMAT === $cached['format'] ) ) {
				return $cached;
			}
		}

		// Zero-stat fast path on storefront when persistent index confirms a fresh v2.4 package.
		if ( ! $allow_build ) {
			$indexed = self::get_index_entry( $clean_id, false );
			if ( is_array( $indexed ) && isset( $indexed['format'] ) && self::FORMAT === $indexed['format'] && ! empty( $indexed['dir'] ) ) {
				$indexed_file = (string) $indexed['dir'] . 'manifest.json';
				$json         = @file_get_contents( $indexed_file ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents, WordPress.PHP.NoSilencedErrors.Discouraged
				if ( false !== $json && '' !== $json ) {
					$data = json_decode( $json, true );
					if ( is_array( $data ) && ! empty( $data['glyphs'] ) ) {
						self::$manifest_cache[ $clean_id ] = $data;
						return $data;
					}
				}
				// Indexed path disappeared (e.g. post-migration or manual deletion); drop stale entry and re-probe.
				self::invalidate_index( $clean_id );
			}
		}

		if ( self::is_fresh( $font_path, $clean_id ) ) {
			$loc  = self::resolve_cache_location( $clean_id, false, $font_path );
			$json = @file_get_contents( $loc['dir'] . 'manifest.json' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents, WordPress.PHP.NoSilencedErrors.Discouraged
			$data = json_decode( (string) $json, true );
			if ( is_array( $data ) && ! empty( $data['glyphs'] ) ) {
				$existing_idx = self::get_index_entry( $clean_id, false );
				if ( ! is_array( $existing_idx ) || ! isset( $existing_idx['format'] ) || self::FORMAT !== $existing_idx['format'] ) {
					self::update_index_entry( $clean_id, self::probe_font_metadata( $clean_id, $font_path ) );
				}
				self::$manifest_cache[ $clean_id ] = $data;
				return $data;
			}
		}

		if ( ! $allow_build ) {
			// Storefront non-blocking path: schedule background rebuild and gracefully fall back to any readable legacy v2.1–v2.3 manifest.
			self::schedule_background_build();

			$candidates = array();
			$loc        = self::resolve_cache_location( $clean_id, false, $font_path );
			if ( ! empty( $loc['dir'] ) ) {
				$candidates[] = $loc['dir'] . 'manifest.json';
			}
			$candidates[] = self::get_plugin_dir() . 'assets/fonts/cache/' . $clean_id . '/manifest.json';
			$candidates[] = self::uploads_cache_base_dir() . $clean_id . '/manifest.json';

			foreach ( array_values( array_unique( $candidates ) ) as $manifest_file ) {
				if ( ! file_exists( $manifest_file ) || ! is_readable( $manifest_file ) ) {
					continue;
				}
				$json = @file_get_contents( $manifest_file ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents, WordPress.PHP.NoSilencedErrors.Discouraged
				if ( false === $json || '' === $json ) {
					continue;
				}
				$data = json_decode( $json, true );
				if ( is_array( $data ) && ! empty( $data['glyphs'] ) ) {
					self::$manifest_cache[ $clean_id ] = $data;
					return $data;
				}
			}

			self::$manifest_cache[ $clean_id ] = null;
			return null;
		}

		if ( '' === $font_path || ! file_exists( $font_path ) ) {
			self::$manifest_cache[ $clean_id ] = null;
			return null;
		}

		$built                             = self::build( $font_path, $clean_id, $font_name );
		self::$manifest_cache[ $clean_id ] = ! empty( $built ) ? $built : null;
		return self::$manifest_cache[ $clean_id ];
	}

	/**
	 * Resolve image tokens into real (root-relative, versioned) URLs.
	 *
	 * Returns the legacy font-data shape consumed by every renderer
	 * (glyphs[char] = { gid, adv, svg }) plus:
	 *  - fullMap: { previewUrl: fullUrl } used by the print exporter to swap in
	 *             the original artwork at export time (omitted when `$include_full_map === false`),
	 *  - preview: true flag so renderers know glyph images are external URLs.
	 *
	 * @param array<string, mixed> $manifest         Raw manifest.
	 * @param string               $font_id          Font identifier.
	 * @param bool                 $include_full_map Whether to include 300 DPI `fullMap` URLs.
	 * @return array<string, mixed>
	 */
	public static function hydrate( array $manifest, $font_id, $include_full_map = true ) {
		$base   = self::package_url_path( $font_id );
		$ver    = isset( $manifest['v'] ) ? $manifest['v'] : '';
		$images = isset( $manifest['images'] ) && is_array( $manifest['images'] ) ? $manifest['images'] : array();

		$preview_urls = array();
		$full_map     = array();
		foreach ( $images as $key => $img ) {
			$p                    = $base . $img['p'] . '?v=' . $ver;
			$preview_urls[ $key ] = $p;
			if ( $include_full_map && ! empty( $img['f'] ) ) {
				$full_map[ $p ] = $base . $img['f'] . '?v=' . $ver;
			}
		}

		$resolve = static function ( $svg ) use ( $preview_urls ) {
			if ( false === strpos( $svg, '{{p:' ) ) {
				return $svg;
			}
			return preg_replace_callback(
				'/\{\{p:([a-z0-9]+)\}\}/',
				static function ( $m ) use ( $preview_urls ) {
					return isset( $preview_urls[ $m[1] ] ) ? $preview_urls[ $m[1] ] : '';
				},
				$svg
			);
		};

		$out = $manifest;
		unset( $out['images'] );
		foreach ( $out['glyphs'] as $ch => $g ) {
			if ( ! empty( $g['svg'] ) ) {
				$out['glyphs'][ $ch ]['svg'] = $resolve( $g['svg'] );
			}
		}
		if ( ! empty( $out['defs'] ) ) {
			$out['defs'] = $resolve( $out['defs'] );
		}

		if ( $include_full_map ) {
			$out['fullMap'] = (object) $full_map;
			$out['preview'] = ! empty( $full_map );
		} else {
			unset( $out['fullMap'] );
			$out['preview'] = false;
		}
		$out['base'] = $base;

		return $out;
	}

	/**
	 * Build a compact 0-RTT partial font bootstrap payload containing ONLY space `' '` plus the
	 * unique glyphs required to render `$chars_string` (including uppercase, lowercase, and NFD
	 * diacritic-stripped base variants), along with `manifestUrl` for background full-alphabet hydration.
	 *
	 * @param array<string, mixed> $hydrated_data Hydrated OpenType-SVG font dataset.
	 * @param string               $chars_string  Characters needed for initial 0-RTT paint (e.g. Slide 0 default text).
	 * @param string               $manifest_url  Optional versioned manifest.json URL for background upgrade.
	 * @return array<string, mixed>
	 */
	public static function build_partial_bootstrap( array $hydrated_data, $chars_string, $manifest_url = '' ) {
		$font_id               = isset( $hydrated_data['id'] ) ? (string) $hydrated_data['id'] : '';
		$resolved_manifest_url = is_string( $manifest_url ) ? trim( $manifest_url ) : '';
		if ( '' === $resolved_manifest_url && '' !== $font_id ) {
			$resolved_manifest_url = self::manifest_url( $font_id );
		}

		$all_glyphs  = ! empty( $hydrated_data['glyphs'] ) ? (array) $hydrated_data['glyphs'] : array();
		$default_adv = isset( $hydrated_data['defaultAdv'] ) ? $hydrated_data['defaultAdv'] : 500;

		$partial_glyphs = array();
		if ( isset( $all_glyphs[' '] ) ) {
			$partial_glyphs[' '] = $all_glyphs[' '];
		} else {
			$partial_glyphs[' '] = array(
				'gid' => 0,
				'adv' => $default_adv,
				'svg' => '',
			);
		}

		$raw_chars = (string) $chars_string;
		if ( '' !== $raw_chars ) {
			$chars = preg_split( '//u', $raw_chars, -1, PREG_SPLIT_NO_EMPTY );
			if ( ! is_array( $chars ) ) {
				$chars = str_split( $raw_chars );
			}

			$seen_chars = array();
			foreach ( $chars as $ch ) {
				if ( '' === $ch || ' ' === $ch || "\n" === $ch || "\r" === $ch || "\t" === $ch || isset( $seen_chars[ $ch ] ) ) {
					continue;
				}
				$seen_chars[ $ch ] = true;

				$upper      = function_exists( 'mb_strtoupper' ) ? mb_strtoupper( $ch, 'UTF-8' ) : strtoupper( $ch );
				$lower      = function_exists( 'mb_strtolower' ) ? mb_strtolower( $ch, 'UTF-8' ) : strtolower( $ch );
				$candidates = array( $ch, $upper, $lower );

				$base = '';
				if ( class_exists( '\Normalizer' ) ) {
					$nfd = \Normalizer::normalize( $ch, \Normalizer::FORM_D );
					if ( is_string( $nfd ) ) {
						$base = (string) preg_replace( '/\p{Mn}+/u', '', $nfd );
					}
				} elseif ( function_exists( 'remove_accents' ) ) {
					$base = remove_accents( $ch );
				}

				if ( is_string( $base ) && '' !== $base && $base !== $ch ) {
					$base_upper   = function_exists( 'mb_strtoupper' ) ? mb_strtoupper( $base, 'UTF-8' ) : strtoupper( $base );
					$base_lower   = function_exists( 'mb_strtolower' ) ? mb_strtolower( $base, 'UTF-8' ) : strtolower( $base );
					$candidates[] = $base;
					$candidates[] = $base_upper;
					$candidates[] = $base_lower;
				}

				foreach ( array_unique( $candidates ) as $cand ) {
					if ( '' !== $cand && isset( $all_glyphs[ $cand ] ) && ! isset( $partial_glyphs[ $cand ] ) ) {
						$partial_glyphs[ $cand ] = $all_glyphs[ $cand ];
					}
				}
			}
		}

		$visual_center_factor = null;
		if ( isset( $hydrated_data['visualCenterFactor'] ) && is_numeric( $hydrated_data['visualCenterFactor'] ) && (float) $hydrated_data['visualCenterFactor'] > 0 ) {
			$visual_center_factor = (float) $hydrated_data['visualCenterFactor'];
		} else {
			$ascent = isset( $hydrated_data['ascent'] ) ? (float) $hydrated_data['ascent'] : 800.0;
			$visual_center_factor = self::compute_visual_center_factor( $all_glyphs, $ascent );
		}

		$bootstrap = array(
			'partial'     => true,
			'manifestUrl' => $resolved_manifest_url,
			'id'          => $font_id,
			'name'        => isset( $hydrated_data['name'] ) ? (string) $hydrated_data['name'] : $font_id,
			'type'        => isset( $hydrated_data['type'] ) ? (string) $hydrated_data['type'] : 'opentype_svg',
			'unitsPerEm'  => isset( $hydrated_data['unitsPerEm'] ) ? $hydrated_data['unitsPerEm'] : 1000,
			'ascent'      => isset( $hydrated_data['ascent'] ) ? $hydrated_data['ascent'] : 800,
			'descent'     => isset( $hydrated_data['descent'] ) ? $hydrated_data['descent'] : -200,
			'defaultAdv'  => $default_adv,
			'defs'        => isset( $hydrated_data['defs'] ) ? (string) $hydrated_data['defs'] : '',
			'v'           => isset( $hydrated_data['v'] ) ? (string) $hydrated_data['v'] : '',
			'preview'     => false,
			'glyphs'      => $partial_glyphs,
		);
		if ( null !== $visual_center_factor ) {
			$bootstrap['visualCenterFactor'] = $visual_center_factor;
		}
		return $bootstrap;
	}

	/**
	 * Compute optical vertical center factor matching SvgRenderer.computeFontVisualCenterFactor()
	 * across the full font's sample uppercase bitmap glyphs so partial bootstraps never shift vertically.
	 *
	 * @param array<string, mixed> $glyphs Full font glyph map.
	 * @param float                $ascent Font ascent in font units.
	 * @return float|null
	 */
	private static function compute_visual_center_factor( array $glyphs, $ascent = 800.0 ) {
		if ( $ascent <= 0 ) {
			return null;
		}
		$sample_chars = array( 'A', 'B', 'E', 'H', 'M', 'O', 'S', 'T', 'N', 'R' );
		$factors      = array();
		foreach ( $sample_chars as $ch ) {
			$g = isset( $glyphs[ $ch ] ) ? $glyphs[ $ch ] : ( isset( $glyphs[ strtolower( $ch ) ] ) ? $glyphs[ strtolower( $ch ) ] : null );
			if ( ! is_array( $g ) || empty( $g['svg'] ) || false === strpos( $g['svg'], '<image' ) ) {
				continue;
			}
			$svg = (string) $g['svg'];
			if ( ! preg_match( '/<image\b([^>]*)>/i', $svg, $im ) ) {
				continue;
			}
			$attrs = $im[1];
			$img_h = preg_match( '/\bheight\s*=\s*["\'](-?\d*\.?\d+)/i', $attrs, $mh ) ? (float) $mh[1] : 0.0;
			$img_y = preg_match( '/\by\s*=\s*["\'](-?\d*\.?\d+)/i', $attrs, $my ) ? (float) $my[1] : 0.0;
			if ( $img_h <= 0 ) {
				continue;
			}
			$ty = 0.0;
			$sy = 1.0;
			if ( preg_match_all( '/transform\s*=\s*["\']([^"\']+)["\']/i', $svg, $tm ) ) {
				foreach ( $tm[1] as $tr ) {
					if ( preg_match_all( '/(translate|scale)\s*\(([^)]+)\)/i', $tr, $om, PREG_SET_ORDER ) ) {
						foreach ( $om as $op ) {
							$fn   = strtolower( $op[1] );
							$nums = preg_split( '/[\s,]+/', trim( $op[2] ) );
							$nums = array_values( array_filter( array_map( 'floatval', is_array( $nums ) ? $nums : array() ), 'is_finite' ) );
							if ( 'translate' === $fn && count( $nums ) >= 1 ) {
								$dy  = count( $nums ) >= 2 ? $nums[1] : 0.0;
								$ty += $sy * $dy;
							} elseif ( 'scale' === $fn && count( $nums ) >= 1 ) {
								$nsy = count( $nums ) >= 2 ? $nums[1] : $nums[0];
								$sy *= $nsy;
							}
						}
					}
				}
			}
			$y1     = $ty + ( $sy * $img_y );
			$y2     = $ty + ( $sy * ( $img_y + $img_h ) );
			$mid_y  = ( min( $y1, $y2 ) + max( $y1, $y2 ) ) / 2.0;
			$factor = ( -$mid_y ) / $ascent;
			if ( is_finite( $factor ) && $factor >= 0.15 && $factor <= 0.85 ) {
				$factors[] = $factor;
			}
		}
		if ( empty( $factors ) ) {
			return null;
		}
		$avg = array_sum( $factors ) / count( $factors );
		return round( max( 0.2, min( 0.75, $avg ) ), 5 );
	}

	/**
	 * Clean glyph SVG markup once at package build time:
	 *  - Strip unreferenced Illustrator `id="..."` attributes (IDs not referenced by `url(#id)` or `href="#id"`).
	 *  - Strip empty `<g ...></g>` and `<g .../>` shells (iteratively for nested empty shells).
	 *  - Compact inter-tag whitespace cleanly.
	 *
	 * @param string $svg          Glyph SVG fragment.
	 * @param string $defs_context Optional font-level `<defs>` markup for fragment reference checks.
	 * @return string
	 */
	private static function clean_glyph_svg( $svg, $defs_context = '' ) {
		if ( ! is_string( $svg ) || '' === $svg ) {
			return '';
		}

		// 1. Strip unreferenced id="..." attributes (e.g. duplicate Illustrator Layer_1_copy_2 IDs).
		if ( false !== stripos( $svg, 'id=' ) ) {
			$combined   = '' !== $defs_context ? ( $svg . "\n" . $defs_context ) : $svg;
			$referenced = array();

			if ( preg_match_all( '/url\(\s*[\'"]?#([^\)\'"\s]+)[\'"]?\s*\)/i', $combined, $um ) ) {
				foreach ( $um[1] as $ref_id ) {
					if ( '' !== $ref_id ) {
						$referenced[ $ref_id ] = true;
					}
				}
			}
			if ( preg_match_all( '/(?:xlink:href|href)\s*=\s*["\']#([^"\']+)["\']/i', $combined, $hm ) ) {
				foreach ( $hm[1] as $ref_id ) {
					if ( '' !== $ref_id ) {
						$referenced[ $ref_id ] = true;
					}
				}
			}

			$svg = (string) preg_replace_callback(
				'/\s+id\s*=\s*(["\'])([^"\']+)\1/i',
				static function ( $m ) use ( $referenced ) {
					return isset( $referenced[ $m[2] ] ) ? $m[0] : '';
				},
				$svg
			);
		}

		// 2. Strip empty <g ...></g> and self-closing <g .../> shells (up to 4 passes for nested shells).
		for ( $pass = 0; $pass < 4; $pass++ ) {
			$prev = $svg;
			$svg  = (string) preg_replace( '/<g(?:\s+(?:"[^"]*"|\'[^\']*\'|[^>"\'])*)?\s*>\s*<\/g>/i', '', $svg );
			$svg  = (string) preg_replace( '/<g(?:\s+(?:"[^"]*"|\'[^\']*\'|[^>"\'])*)?\s*\/>/i', '', $svg );
			if ( $svg === $prev ) {
				break;
			}
		}

		// 3. Compact whitespace between tags and stray trailing whitespace before '>'.
		$svg = (string) preg_replace( '/>\s+</', '><', trim( $svg ) );
		$svg = (string) preg_replace( '/\s+>/', '>', $svg );

		return $svg;
	}

	/**
	 * Build (or rebuild) the package for a font file (both bitmap and pure-vector OpenType-SVG fonts).
	 *
	 * @param string $font_path Absolute font path.
	 * @param string $font_id   Font identifier.
	 * @param string $font_name Display name.
	 * @return array<string, mixed>|null Raw manifest.
	 */
	public static function build( $font_path, $font_id, $font_name = '' ) {
		if ( ! file_exists( $font_path ) ) {
			return null;
		}

		$clean_id = self::clean_id( $font_id );
		if ( '' === $clean_id ) {
			return null;
		}

		$loc      = self::resolve_cache_location( $clean_id, true, $font_path );
		$dir      = $loc['dir'];
		$base_dir = $loc['base_dir'];
		if ( ! self::is_dir_writable( $dir ) ) {
			return null;
		}

		// Serialize concurrent builds (two simultaneous first visitors or cron ticks).
		$lock_handle = @fopen( $dir . '.build.lock', 'c' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen, WordPress.PHP.NoSilencedErrors.Discouraged
		if ( $lock_handle ) {
			flock( $lock_handle, LOCK_EX );
			self::invalidate_static_caches( $clean_id );
			clearstatcache( true, $dir . 'manifest.json' );
			if ( self::is_fresh( $font_path, $clean_id ) ) {
				flock( $lock_handle, LOCK_UN );
				fclose( $lock_handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
				$fresh_loc = self::resolve_cache_location( $clean_id, false, $font_path );
				$json      = @file_get_contents( $fresh_loc['dir'] . 'manifest.json' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents, WordPress.PHP.NoSilencedErrors.Discouraged
				$data      = json_decode( (string) $json, true );
				if ( is_array( $data ) && ! empty( $data['glyphs'] ) ) {
					self::update_index_entry( $clean_id, self::probe_font_metadata( $clean_id, $font_path ) );
					self::$manifest_cache[ $clean_id ] = $data;
					return $data;
				}
				return null;
			}
		}

		if ( function_exists( 'set_time_limit' ) ) {
			@set_time_limit( 300 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		}

		$parsed = OpenTypeSvgParser::parse_font( $font_path, $clean_id, $font_name );
		if ( empty( $parsed['glyphs'] ) ) {
			if ( $lock_handle ) {
				flock( $lock_handle, LOCK_UN );
				fclose( $lock_handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
			}
			return null;
		}

		$ver         = substr( md5( self::FORMAT . '|' . filesize( $font_path ) . '|' . filemtime( $font_path ) . '|' . self::PREVIEW_MAX . '|' . self::PREVIEW_QUALITY ), 0, 10 );
		$images      = array();
		$tiers_ready = false;
		$stats       = array(
			'images'        => 0,
			'full_bytes'    => 0,
			'preview_bytes' => 0,
		);

		$extract = static function ( $svg ) use ( $dir, &$images, &$stats, &$tiers_ready ) {
			if ( false === stripos( $svg, 'data:image/' ) ) {
				return $svg;
			}
			return preg_replace_callback(
				'/(xlink:href|href)\s*=\s*(["\'])\s*data:image\/([a-zA-Z0-9.+-]+);base64,([A-Za-z0-9+\/=\s]+)\2/',
				static function ( $m ) use ( $dir, &$images, &$stats, &$tiers_ready ) {
					$b64 = preg_replace( '/\s+/', '', $m[4] );
					$key = substr( md5( $b64 ), 0, 12 );

					if ( ! isset( $images[ $key ] ) ) {
						$binary = base64_decode( $b64 ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode
						if ( false === $binary || '' === $binary ) {
							return $m[0];
						}
						if ( ! $tiers_ready ) {
							self::is_dir_writable( $dir . 'p' );
							self::is_dir_writable( $dir . 'f' );
							$tiers_ready = true;
						}
						$mime = strtolower( $m[3] );
						$ext  = ( 'jpeg' === $mime || 'jpg' === $mime ) ? 'jpg' : ( 'webp' === $mime ? 'webp' : ( 'gif' === $mime ? 'gif' : 'png' ) );

						// Full tier: original bytes, byte-for-byte (300 DPI print fidelity).
						$full_rel = 'f/' . $key . '.' . $ext;
						file_put_contents( $dir . $full_rel, $binary ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents

						// Preview tier: downscaled WebP.
						$size        = @getimagesizefromstring( $binary ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
						$w           = $size ? (int) $size[0] : 0;
						$h           = $size ? (int) $size[1] : 0;
						$preview_rel = self::write_preview( $binary, $dir, 'p/' . $key, self::PREVIEW_MAX, self::PREVIEW_QUALITY );
						if ( ! $preview_rel ) {
							$preview_rel = $full_rel; // Graceful fallback: serve original.
						}

						$images[ $key ] = array(
							'w' => $w,
							'h' => $h,
							'p' => $preview_rel,
							'f' => $full_rel,
						);
						$stats['images']++;
						$stats['full_bytes']    += strlen( $binary );
						$stats['preview_bytes'] += (int) @filesize( $dir . $preview_rel ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
					}

					return $m[1] . '=' . $m[2] . '{{p:' . $key . '}}' . $m[2];
				},
				$svg
			);
		};

		// Extract font-level <defs> first so referenced fragment IDs are preserved across glyphs.
		$defs = ! empty( $parsed['defs'] ) ? trim( $extract( (string) $parsed['defs'] ) ) : '';

		$glyphs = array();
		foreach ( $parsed['glyphs'] as $ch => $g ) {
			$svg = isset( $g['svg'] ) ? (string) $g['svg'] : '';
			if ( '' !== $svg ) {
				$svg = $extract( $svg );
				$svg = self::clean_glyph_svg( $svg, $defs );
			}
			$glyphs[ (string) $ch ] = array(
				'gid' => $g['gid'],
				'adv' => $g['adv'],
				'svg' => $svg,
			);
		}

		// Picker thumbnail from a representative bitmap glyph (pure-vector fonts keep thumb empty).
		$thumb_rel = '';
		if ( ! empty( $images ) ) {
			foreach ( array( 'A', 'a', 'B', 'M', '1' ) as $sample ) {
				if ( empty( $glyphs[ $sample ]['svg'] ) ) {
					continue;
				}
				if ( preg_match( '/\{\{p:([a-z0-9]+)\}\}/', $glyphs[ $sample ]['svg'], $tm ) && isset( $images[ $tm[1] ] ) ) {
					$full_bin  = @file_get_contents( $dir . $images[ $tm[1] ]['f'] ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents, WordPress.PHP.NoSilencedErrors.Discouraged
					if ( false !== $full_bin && '' !== $full_bin ) {
						$thumb_rel = self::write_preview( $full_bin, $dir, 'thumb', self::THUMB_MAX, 82 );
					}
					break;
				}
			}
		}

		// Remove orphaned images from previous builds (safe no-op for pure-vector fonts without p/ or f/).
		$keep = array();
		foreach ( $images as $img ) {
			$keep[ $img['p'] ] = true;
			$keep[ $img['f'] ] = true;
		}
		foreach ( array( 'p', 'f' ) as $sub ) {
			if ( ! is_dir( $dir . $sub ) ) {
				continue;
			}
			foreach ( (array) glob( $dir . $sub . '/*' ) as $file ) {
				if ( ! is_string( $file ) || '' === $file ) {
					continue;
				}
				$rel = $sub . '/' . basename( $file );
				if ( empty( $keep[ $rel ] ) ) {
					@unlink( $file ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
				}
			}
		}

		$manifest = array(
			'format'     => self::FORMAT,
			'v'          => $ver,
			'id'         => $clean_id,
			'name'       => ! empty( $parsed['name'] ) ? $parsed['name'] : $clean_id,
			'type'       => 'opentype_svg',
			'unitsPerEm' => $parsed['unitsPerEm'],
			'ascent'     => $parsed['ascent'],
			'descent'    => $parsed['descent'],
			'defaultAdv' => $parsed['defaultAdv'],
			'defs'       => $defs,
			'thumb'      => $thumb_rel,
			'stats'      => $stats,
			'images'     => (object) $images,
			'glyphs'     => (object) $glyphs,
		);

		$json = function_exists( 'wp_json_encode' ) ? wp_json_encode( $manifest, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) : json_encode( $manifest, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
		$tmp  = $dir . 'manifest.json.tmp';
		file_put_contents( $tmp, $json ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
		rename( $tmp, $dir . 'manifest.json' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.rename_rename
		clearstatcache( true, $dir . 'manifest.json' );

		self::write_htaccess( $base_dir );

		if ( $lock_handle ) {
			flock( $lock_handle, LOCK_UN );
			fclose( $lock_handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
		}

		self::invalidate_static_caches( $clean_id );

		$manifest_mtime = (int) @filemtime( $dir . 'manifest.json' ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		$thumb_url      = '';
		if ( '' !== $thumb_rel && file_exists( $dir . $thumb_rel ) ) {
			clearstatcache( true, $dir . $thumb_rel );
			$thumb_url = $loc['url_path'] . $thumb_rel . '?v=' . (int) @filemtime( $dir . $thumb_rel ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		}
		self::update_index_entry(
			$clean_id,
			array(
				'format'         => self::FORMAT,
				'manifest_url'   => $loc['url_path'] . 'manifest.json?v=' . $manifest_mtime,
				'thumb_url'      => $thumb_url,
				'dir'            => $dir,
				'base_url'       => $loc['url_path'],
				'manifest_mtime' => $manifest_mtime,
			)
		);

		$decoded                           = json_decode( (string) $json, true );
		self::$manifest_cache[ $clean_id ] = is_array( $decoded ) ? $decoded : null;
		return self::$manifest_cache[ $clean_id ];
	}

	/**
	 * Write a downscaled WebP (or PNG fallback) preview of an image.
	 *
	 * @param string $binary  Source image bytes.
	 * @param string $dir     Package directory.
	 * @param string $rel     Relative target path without extension.
	 * @param int    $max     Longest side in px.
	 * @param int    $quality WebP quality.
	 * @return string Relative path written, or '' on failure.
	 */
	protected static function write_preview( $binary, $dir, $rel, $max, $quality ) {
		// 1. Imagick: Lanczos resampling + full 8-bit alpha.
		if ( class_exists( '\Imagick' ) ) {
			try {
				$formats = \Imagick::queryFormats( 'WEBP' );
				if ( ! empty( $formats ) ) {
					$im = new \Imagick();
					$im->readImageBlob( $binary );
					$im->setImageAlphaChannel( \Imagick::ALPHACHANNEL_ACTIVATE );
					$w = $im->getImageWidth();
					$h = $im->getImageHeight();
					$s = min( 1.0, $max / max( 1, $w, $h ) );
					if ( $s < 1.0 ) {
						$im->resizeImage( max( 1, (int) round( $w * $s ) ), max( 1, (int) round( $h * $s ) ), \Imagick::FILTER_LANCZOS, 1 );
					}
					$im->setImageFormat( 'webp' );
					$im->setImageCompressionQuality( $quality );
					$im->setOption( 'webp:method', '5' );
					$im->setOption( 'webp:alpha-quality', '100' );
					$im->stripImage();
					$target = $rel . '.webp';
					$ok     = $im->writeImage( $dir . $target );
					$im->clear();
					if ( $ok && filesize( $dir . $target ) > 0 ) {
						return $target;
					}
				}
			} catch ( \Exception $e ) { // phpcs:ignore Generic.CodeAnalysis.EmptyStatement.DetectedCatch
				// Fall through to GD.
			}
		}

		// 2. GD fallback.
		if ( function_exists( 'imagecreatefromstring' ) ) {
			$src = @imagecreatefromstring( $binary ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
			if ( $src ) {
				if ( function_exists( 'imagepalettetotruecolor' ) && ! imageistruecolor( $src ) ) {
					imagepalettetotruecolor( $src );
				}
				$w   = imagesx( $src );
				$h   = imagesy( $src );
				$s   = min( 1.0, $max / max( 1, $w, $h ) );
				$nw  = max( 1, (int) round( $w * $s ) );
				$nh  = max( 1, (int) round( $h * $s ) );
				$dst = imagecreatetruecolor( $nw, $nh );
				imagealphablending( $dst, false );
				imagesavealpha( $dst, true );
				imagefill( $dst, 0, 0, imagecolorallocatealpha( $dst, 0, 0, 0, 127 ) );
				imagecopyresampled( $dst, $src, 0, 0, 0, 0, $nw, $nh, $w, $h );

				$target = '';
				if ( function_exists( 'imagewebp' ) && imagewebp( $dst, $dir . $rel . '.webp', $quality ) ) {
					$target = $rel . '.webp';
				} elseif ( imagepng( $dst, $dir . $rel . '.png', 9 ) ) {
					$target = $rel . '.png';
				}
				imagedestroy( $src );
				imagedestroy( $dst );
				return $target;
			}
		}

		return '';
	}

	/**
	 * Drop an .htaccess into the cache folder enabling long-lived immutable caching
	 * (URLs are versioned) and gzip for manifests on Apache/LiteSpeed.
	 *
	 * @param string $base_dir Optional base cache directory path.
	 */
	public static function write_htaccess( $base_dir = '' ) {
		$dir = ! empty( $base_dir ) ? self::trailingslash( $base_dir ) : ( self::get_plugin_dir() . 'assets/fonts/cache/' );
		if ( ! self::is_dir_writable( $dir ) ) {
			return;
		}
		$file = $dir . '.htaccess';
		if ( file_exists( $file ) ) {
			return;
		}
		$rules = "# Mr. Tee Editor - OpenType-SVG font packages (versioned URLs)\n"
			. "<IfModule mod_mime.c>\n  AddType image/webp .webp\n  AddType application/json .json\n</IfModule>\n"
			. "<IfModule mod_deflate.c>\n  AddOutputFilterByType DEFLATE application/json\n</IfModule>\n"
			. "<IfModule mod_expires.c>\n  ExpiresActive On\n  ExpiresByType image/webp \"access plus 1 year\"\n  ExpiresByType image/png \"access plus 1 year\"\n  ExpiresByType image/jpeg \"access plus 1 year\"\n  ExpiresByType application/json \"access plus 1 year\"\n</IfModule>\n"
			. "<IfModule mod_headers.c>\n  <FilesMatch \"\\.(webp|png|jpe?g|gif|json)$\">\n    Header set Cache-Control \"public, max-age=31536000, immutable\"\n  </FilesMatch>\n  <FilesMatch \"\\.lock$\">\n    Require all denied\n  </FilesMatch>\n</IfModule>\n";
		@file_put_contents( $file, $rules ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents, WordPress.PHP.NoSilencedErrors.Discouraged
	}

	/**
	 * Build packages for every registered OpenType-SVG font (both bitmap and pure-vector; used by cron / upgrades).
	 *
	 * @return array<string, mixed> Per-font build report.
	 */
	public static function build_all() {
		$report = array();
		foreach ( FontRegistry::get_fonts() as $id => $font ) {
			$is_ot_svg = ( ! empty( $font['font_type'] ) && 'opentype_svg' === $font['font_type'] ) || ! empty( $font['color_svg'] );
			if ( ! $is_ot_svg || empty( $font['path'] ) || ! file_exists( $font['path'] ) ) {
				continue;
			}
			$t             = microtime( true );
			$manifest      = self::get_manifest( $font['path'], $id, isset( $font['name'] ) ? $font['name'] : $id, true );
			$report[ $id ] = array(
				'ok'    => ! empty( $manifest ),
				'stats' => isset( $manifest['stats'] ) ? $manifest['stats'] : null,
				'ms'    => (int) round( ( microtime( true ) - $t ) * 1000 ),
			);
		}
		return $report;
	}
}
