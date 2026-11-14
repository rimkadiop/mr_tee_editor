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
	const FORMAT = 'mrtee-svgfont-v2.3';

	/** Longest side (px) of the on-screen preview tier (384px provides 2x-4x Retina supersampling on screen at ~40 KB/glyph while f/ keeps 100% original resolution for 300 DPI export). */
	const PREVIEW_MAX = 384;

	/** WebP quality of the preview tier (86 = visually indistinguishable on screen with 3x smaller payload for instant alphabet pre-warming). */
	const PREVIEW_QUALITY = 86;

	/** Longest side (px) of the font picker thumbnail. */
	const THUMB_MAX = 72;

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
	 * Absolute directory of a font package.
	 *
	 * @param string $font_id Font identifier.
	 * @return string
	 */
	public static function package_dir( $font_id ) {
		return self::get_plugin_dir() . 'assets/fonts/cache/' . sanitize_key( $font_id ) . '/';
	}

	/**
	 * Root-relative URL path of a font package (protocol/host agnostic to avoid
	 * mixed-content and http/https mismatch issues).
	 *
	 * @param string $font_id Font identifier.
	 * @return string
	 */
	public static function package_url_path( $font_id ) {
		$base   = self::get_plugin_url() . 'assets/fonts/cache/' . sanitize_key( $font_id ) . '/';
		$parsed = function_exists( 'wp_parse_url' ) ? wp_parse_url( $base ) : parse_url( $base ); // phpcs:ignore WordPress.WP.AlternativeFunctions.parse_url_parse_url
		return ! empty( $parsed['path'] ) ? $parsed['path'] : $base;
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
	 * Versioned root-relative URL of the manifest, or empty string if not built yet.
	 *
	 * @param string $font_id Font identifier.
	 * @return string
	 */
	public static function manifest_url( $font_id ) {
		$cache_key = 'm:' . $font_id;
		if ( isset( self::$url_cache[ $cache_key ] ) ) {
			return self::$url_cache[ $cache_key ];
		}
		$file = self::manifest_path( $font_id );
		if ( ! file_exists( $file ) ) {
			self::$url_cache[ $cache_key ] = '';
			return '';
		}
		$url = self::package_url_path( $font_id ) . 'manifest.json?v=' . filemtime( $file );
		self::$url_cache[ $cache_key ] = $url;
		return $url;
	}

	/**
	 * Versioned root-relative URL of the picker thumbnail, or empty string.
	 *
	 * @param string $font_id Font identifier.
	 * @return string
	 */
	public static function thumb_url( $font_id ) {
		$cache_key = 't:' . $font_id;
		if ( isset( self::$url_cache[ $cache_key ] ) ) {
			return self::$url_cache[ $cache_key ];
		}
		$dir = self::package_dir( $font_id );
		foreach ( array( 'thumb.webp', 'thumb.png' ) as $name ) {
			if ( file_exists( $dir . $name ) ) {
				$url = self::package_url_path( $font_id ) . $name . '?v=' . filemtime( $dir . $name );
				self::$url_cache[ $cache_key ] = $url;
				return $url;
			}
		}
		self::$url_cache[ $cache_key ] = '';
		return '';
	}

	/**
	 * Is the package present and up to date for this font file?
	 *
	 * @param string $font_path Absolute font path.
	 * @param string $font_id   Font identifier.
	 * @return bool
	 */
	public static function is_fresh( $font_path, $font_id ) {
		$manifest = self::manifest_path( $font_id );
		if ( ! file_exists( $manifest ) || ! file_exists( $font_path ) ) {
			return false;
		}
		if ( filemtime( $manifest ) < filemtime( $font_path ) ) {
			return false;
		}
		// Cheap format check: read only the head of the file.
		$head = (string) file_get_contents( $manifest, false, null, 0, 160 ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		return false !== strpos( $head, '"' . self::FORMAT . '"' );
	}

	/**
	 * Load the raw manifest, building the package first if needed.
	 *
	 * @param string $font_path Absolute font path.
	 * @param string $font_id   Font identifier.
	 * @param string $font_name Display name.
	 * @return array<string, mixed>|null
	 */
	public static function get_manifest( $font_path, $font_id, $font_name = '' ) {
		if ( array_key_exists( $font_id, self::$manifest_cache ) ) {
			return self::$manifest_cache[ $font_id ];
		}

		if ( ! self::is_fresh( $font_path, $font_id ) ) {
			$built = self::build( $font_path, $font_id, $font_name );
			self::$manifest_cache[ $font_id ] = ! empty( $built ) ? $built : null;
			return self::$manifest_cache[ $font_id ];
		}

		$json = file_get_contents( self::manifest_path( $font_id ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		$data = json_decode( (string) $json, true );
		self::$manifest_cache[ $font_id ] = ( is_array( $data ) && ! empty( $data['glyphs'] ) ) ? $data : null;
		return self::$manifest_cache[ $font_id ];
	}

	/**
	 * Resolve image tokens into real (root-relative, versioned) URLs.
	 *
	 * Returns the legacy font-data shape consumed by every renderer
	 * (glyphs[char] = { gid, adv, svg }) plus:
	 *  - fullMap: { previewUrl: fullUrl } used by the print exporter to swap in
	 *             the original artwork at export time,
	 *  - preview: true flag so renderers know glyph images are external URLs.
	 *
	 * @param array<string, mixed> $manifest Raw manifest.
	 * @param string               $font_id  Font identifier.
	 * @return array<string, mixed>
	 */
	public static function hydrate( array $manifest, $font_id ) {
		$base   = self::package_url_path( $font_id );
		$ver    = isset( $manifest['v'] ) ? $manifest['v'] : '';
		$images = isset( $manifest['images'] ) && is_array( $manifest['images'] ) ? $manifest['images'] : array();

		$preview_urls = array();
		$full_map     = array();
		foreach ( $images as $key => $img ) {
			$p                    = $base . $img['p'] . '?v=' . $ver;
			$f                    = $base . $img['f'] . '?v=' . $ver;
			$preview_urls[ $key ] = $p;
			$full_map[ $p ]       = $f;
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
		$out['fullMap'] = (object) $full_map;
		$out['base']    = $base;
		$out['preview'] = ! empty( $full_map );

		return $out;
	}

	/**
	 * Build (or rebuild) the package for a font file.
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

		$dir = self::package_dir( $font_id );
		if ( ! is_dir( $dir . 'p' ) ) {
			wp_mkdir_p( $dir . 'p' );
		}
		if ( ! is_dir( $dir . 'f' ) ) {
			wp_mkdir_p( $dir . 'f' );
		}

		// Serialize concurrent builds (two first visitors at the same time).
		$lock_handle = fopen( $dir . '.build.lock', 'c' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen
		if ( $lock_handle ) {
			flock( $lock_handle, LOCK_EX );
			if ( self::is_fresh( $font_path, $font_id ) ) {
				flock( $lock_handle, LOCK_UN );
				fclose( $lock_handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
				$json = file_get_contents( self::manifest_path( $font_id ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
				$data = json_decode( (string) $json, true );
				return is_array( $data ) ? $data : null;
			}
		}

		if ( function_exists( 'set_time_limit' ) ) {
			@set_time_limit( 300 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		}

		$parsed = OpenTypeSvgParser::parse_font( $font_path, $font_id, $font_name );
		if ( empty( $parsed['glyphs'] ) ) {
			if ( $lock_handle ) {
				flock( $lock_handle, LOCK_UN );
				fclose( $lock_handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
			}
			return null;
		}

		$ver    = substr( md5( self::FORMAT . '|' . filesize( $font_path ) . '|' . filemtime( $font_path ) . '|' . self::PREVIEW_MAX . '|' . self::PREVIEW_QUALITY ), 0, 10 );
		$images = array();
		$stats  = array(
			'images'        => 0,
			'full_bytes'    => 0,
			'preview_bytes' => 0,
		);

		$extract = static function ( $svg ) use ( $dir, &$images, &$stats ) {
			if ( false === stripos( $svg, 'data:image/' ) ) {
				return $svg;
			}
			return preg_replace_callback(
				'/(xlink:href|href)\s*=\s*(["\'])\s*data:image\/([a-zA-Z0-9.+-]+);base64,([A-Za-z0-9+\/=\s]+)\2/',
				static function ( $m ) use ( $dir, &$images, &$stats ) {
					$b64 = preg_replace( '/\s+/', '', $m[4] );
					$key = substr( md5( $b64 ), 0, 12 );

					if ( ! isset( $images[ $key ] ) ) {
						$binary = base64_decode( $b64 ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode
						if ( false === $binary || '' === $binary ) {
							return $m[0];
						}
						$mime = strtolower( $m[3] );
						$ext  = ( 'jpeg' === $mime || 'jpg' === $mime ) ? 'jpg' : ( 'webp' === $mime ? 'webp' : ( 'gif' === $mime ? 'gif' : 'png' ) );

						// Full tier: original bytes, byte-for-byte (print fidelity).
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

		$glyphs = array();
		foreach ( $parsed['glyphs'] as $ch => $g ) {
			$svg = isset( $g['svg'] ) ? (string) $g['svg'] : '';
			if ( '' !== $svg ) {
				$svg = $extract( $svg );
				// Compact whitespace between tags (safe for SVG markup).
				$svg = preg_replace( '/>\s+</', '><', trim( $svg ) );
			}
			$glyphs[ (string) $ch ] = array(
				'gid' => $g['gid'],
				'adv' => $g['adv'],
				'svg' => $svg,
			);
		}

		$defs = ! empty( $parsed['defs'] ) ? $extract( (string) $parsed['defs'] ) : '';

		// Picker thumbnail from a representative glyph.
		$thumb_rel = '';
		foreach ( array( 'A', 'a', 'B', 'M', '1' ) as $sample ) {
			if ( empty( $glyphs[ $sample ]['svg'] ) ) {
				continue;
			}
			if ( preg_match( '/\{\{p:([a-z0-9]+)\}\}/', $glyphs[ $sample ]['svg'], $tm ) && isset( $images[ $tm[1] ] ) ) {
				$full_bin  = file_get_contents( $dir . $images[ $tm[1] ]['f'] ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
				$thumb_rel = self::write_preview( $full_bin, $dir, 'thumb', self::THUMB_MAX, 82 );
			}
			break;
		}

		// Remove orphaned images from previous builds.
		$keep = array();
		foreach ( $images as $img ) {
			$keep[ $img['p'] ] = true;
			$keep[ $img['f'] ] = true;
		}
		foreach ( array( 'p', 'f' ) as $sub ) {
			foreach ( (array) glob( $dir . $sub . '/*' ) as $file ) {
				$rel = $sub . '/' . basename( $file );
				if ( empty( $keep[ $rel ] ) ) {
					@unlink( $file ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
				}
			}
		}

		$manifest = array(
			'format'     => self::FORMAT,
			'v'          => $ver,
			'id'         => $font_id,
			'name'       => ! empty( $parsed['name'] ) ? $parsed['name'] : $font_id,
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
		rename( $tmp, self::manifest_path( $font_id ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.rename_rename

		self::write_htaccess();

		if ( $lock_handle ) {
			flock( $lock_handle, LOCK_UN );
			fclose( $lock_handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
		}

		unset( self::$url_cache[ 'm:' . $font_id ], self::$url_cache[ 't:' . $font_id ] );
		$decoded = json_decode( $json, true );
		self::$manifest_cache[ $font_id ] = is_array( $decoded ) ? $decoded : null;
		return self::$manifest_cache[ $font_id ];
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
	 */
	public static function write_htaccess() {
		$file = self::get_plugin_dir() . 'assets/fonts/cache/.htaccess';
		if ( file_exists( $file ) ) {
			return;
		}
		$rules = "# Mr. Tee Editor - OpenType-SVG font packages (versioned URLs)\n"
			. "<IfModule mod_mime.c>\n  AddType image/webp .webp\n  AddType application/json .json\n</IfModule>\n"
			. "<IfModule mod_deflate.c>\n  AddOutputFilterByType DEFLATE application/json\n</IfModule>\n"
			. "<IfModule mod_expires.c>\n  ExpiresActive On\n  ExpiresByType image/webp \"access plus 1 year\"\n  ExpiresByType image/png \"access plus 1 year\"\n  ExpiresByType image/jpeg \"access plus 1 year\"\n  ExpiresByType application/json \"access plus 1 year\"\n</IfModule>\n"
			. "<IfModule mod_headers.c>\n  <FilesMatch \"\\.(webp|png|jpe?g|gif|json)$\">\n    Header set Cache-Control \"public, max-age=31536000, immutable\"\n  </FilesMatch>\n  <FilesMatch \"\\.lock$\">\n    Require all denied\n  </FilesMatch>\n</IfModule>\n";
		file_put_contents( $file, $rules ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
	}

	/**
	 * Build packages for every registered OpenType-SVG font (used by cron / upgrades).
	 *
	 * @return array<string, mixed> Per-font build report.
	 */
	public static function build_all() {
		$report = array();
		foreach ( FontRegistry::get_fonts() as $id => $font ) {
			if ( empty( $font['font_type'] ) || 'opentype_svg' !== $font['font_type'] || empty( $font['path'] ) ) {
				continue;
			}
			$t        = microtime( true );
			$manifest = self::get_manifest( $font['path'], $id, isset( $font['name'] ) ? $font['name'] : $id );
			$report[ $id ] = array(
				'ok'    => ! empty( $manifest ),
				'stats' => isset( $manifest['stats'] ) ? $manifest['stats'] : null,
				'ms'    => (int) round( ( microtime( true ) - $t ) * 1000 ),
			);
		}
		return $report;
	}
}
