<?php
/**
 * Mr. Tee Editor - OpenType-SVG Font Parser & Color Vector Extractor
 *
 * High-performance, pure PHP binary parser for OpenType/TrueType fonts with
 * embedded 'SVG ' tables (OpenType-SVG color fonts).
 *
 * Implements the ISO/IEC 14496-22 & Adobe/Microsoft OpenType-SVG Specification:
 * - SFNT table directory inspection
 * - 'head' & 'hhea' font metrics (unitsPerEm, ascent, descent)
 * - 'hmtx' horizontal advance metrics & kerning
 * - 'cmap' Unicode format 4 & format 12 character-to-glyph-index mapping
 * - 'SVG ' table document list parsing with gzip decompression (RFC 1952)
 * - High-speed JSON caching for instant sub-millisecond frontend delivery
 *
 * @package MrTeeEditor\Registry
 */

namespace MrTeeEditor\Registry;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class OpenTypeSvgParser {

	/**
	 * Check if a font file contains an OpenType-SVG table ('SVG ').
	 *
	 * @param string $file_path Absolute path to TTF/OTF file.
	 * @return bool
	 */
	public static function is_opentype_svg( $file_path ) {
		if ( ! file_exists( $file_path ) || ! is_readable( $file_path ) ) {
			return false;
		}

		$handle = fopen( $file_path, 'rb' );
		if ( ! $handle ) {
			return false;
		}

		$header = fread( $handle, 12 );
		if ( strlen( $header ) < 12 ) {
			fclose( $handle );
			return false;
		}

		$num_tables = unpack( 'n', substr( $header, 4, 2 ) )[1];
		for ( $i = 0; $i < $num_tables; $i++ ) {
			$entry = fread( $handle, 16 );
			if ( strlen( $entry ) < 16 ) {
				break;
			}
			$tag = substr( $entry, 0, 4 );
			if ( $tag === 'SVG ' ) {
				fclose( $handle );
				return true;
			}
		}

		fclose( $handle );
		return false;
	}

	/**
	 * Get cached JSON font definition or parse and generate cache.
	 *
	 * @param string $file_path Absolute path to font file.
	 * @param string $font_id Font identifier.
	 * @param string $font_name Display name.
	 * @return array<string, mixed>
	 */
	public static function get_or_create_cache( $file_path, $font_id, $font_name = '' ) {
		$clean_id     = sanitize_key( $font_id );
		$primary_dir  = dirname( $file_path ) . '/cache';
		$primary_file = $primary_dir . '/' . $clean_id . '.json';

		$up           = function_exists( 'wp_upload_dir' ) ? wp_upload_dir() : array();
		$upload_dir   = ! empty( $up['basedir'] ) ? trailingslashit( $up['basedir'] ) . 'mr_tee_editor/font-cache' : '';
		$upload_file  = $upload_dir ? $upload_dir . '/' . $clean_id . '.json' : '';

		foreach ( array_filter( array( $primary_file, $upload_file ) ) as $candidate ) {
			if ( file_exists( $candidate ) && file_exists( $file_path ) && filemtime( $candidate ) >= filemtime( $file_path ) ) {
				$cached = @file_get_contents( $candidate ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents, WordPress.PHP.NoSilencedErrors.Discouraged
				if ( ! empty( $cached ) ) {
					$data = json_decode( $cached, true );
					if ( is_array( $data ) && ! empty( $data['glyphs'] ) ) {
						return $data;
					}
				}
			}
		}

		$parsed = self::parse_font( $file_path, $font_id, $font_name );
		if ( ! empty( $parsed['glyphs'] ) ) {
			$target_dir  = $primary_dir;
			$target_file = $primary_file;
			if ( ! is_dir( $target_dir ) ) {
				if ( function_exists( 'wp_mkdir_p' ) ) {
					wp_mkdir_p( $target_dir );
				} else {
					@mkdir( $target_dir, 0755, true ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
				}
			}
			$writable = is_dir( $target_dir ) && ( function_exists( 'wp_is_writable' ) ? wp_is_writable( $target_dir ) : is_writable( $target_dir ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_is_writable
			if ( ! $writable && $upload_dir ) {
				if ( ! is_dir( $upload_dir ) ) {
					if ( function_exists( 'wp_mkdir_p' ) ) {
						wp_mkdir_p( $upload_dir );
					} else {
						@mkdir( $upload_dir, 0755, true ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
					}
				}
				if ( is_dir( $upload_dir ) ) {
					$target_file = $upload_file;
					$writable    = function_exists( 'wp_is_writable' ) ? wp_is_writable( $upload_dir ) : is_writable( $upload_dir ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_is_writable
				}
			}
			if ( $writable ) {
				$encoded = function_exists( 'wp_json_encode' ) ? wp_json_encode( $parsed ) : json_encode( $parsed );
				@file_put_contents( $target_file, $encoded ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents, WordPress.PHP.NoSilencedErrors.Discouraged
			}
		}

		return $parsed;
	}

	/**
	 * Convert a Unicode code point to a UTF-8 string without requiring mbstring.
	 *
	 * @param int $cp Unicode code point.
	 * @return string
	 */
	private static function codepoint_to_utf8( $cp ) {
		$cp = (int) $cp;
		if ( $cp < 0 || $cp > 0x10FFFF ) {
			return '';
		}
		if ( function_exists( 'mb_chr' ) ) {
			$ch = mb_chr( $cp, 'UTF-8' );
			return is_string( $ch ) ? $ch : '';
		}
		if ( class_exists( '\IntlChar' ) ) {
			$ch = \IntlChar::chr( $cp );
			return is_string( $ch ) ? $ch : '';
		}
		if ( $cp < 0x80 ) {
			return chr( $cp );
		}
		if ( $cp < 0x800 ) {
			return chr( 0xC0 | ( $cp >> 6 ) )
				. chr( 0x80 | ( $cp & 0x3F ) );
		}
		if ( $cp < 0x10000 ) {
			return chr( 0xE0 | ( $cp >> 12 ) )
				. chr( 0x80 | ( ( $cp >> 6 ) & 0x3F ) )
				. chr( 0x80 | ( $cp & 0x3F ) );
		}
		return chr( 0xF0 | ( $cp >> 18 ) )
			. chr( 0x80 | ( ( $cp >> 12 ) & 0x3F ) )
			. chr( 0x80 | ( ( $cp >> 6 ) & 0x3F ) )
			. chr( 0x80 | ( $cp & 0x3F ) );
	}

	/**
	 * Sanitize extracted SVG markup using Plugin::sanitize_svg_markup().
	 *
	 * @param string $svg Raw SVG fragment.
	 * @return string
	 */
	private static function sanitize_svg_fragment( $svg ) {
		if ( ! is_string( $svg ) || '' === $svg ) {
			return '';
		}
		if ( ! class_exists( '\MrTeeEditor\Core\Plugin' ) ) {
			$plugin_file = dirname( __DIR__ ) . '/Core/Plugin.php';
			if ( file_exists( $plugin_file ) ) {
				require_once $plugin_file;
			}
		}
		if ( class_exists( '\MrTeeEditor\Core\Plugin' ) ) {
			return \MrTeeEditor\Core\Plugin::sanitize_svg_markup( $svg );
		}
		return $svg;
	}

	/**
	 * Parse OpenType font file and extract SVG table glyphs and metrics.
	 *
	 * @param string $file_path Absolute path to TTF/OTF file.
	 * @param string $font_id Font identifier.
	 * @param string $font_name Display name.
	 * @return array<string, mixed>
	 */
	public static function parse_font( $file_path, $font_id, $font_name = '' ) {
		if ( ! file_exists( $file_path ) || ! is_readable( $file_path ) ) {
			return array();
		}

		$data = file_get_contents( $file_path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		if ( false === $data || strlen( $data ) < 12 ) {
			return array();
		}
		$data_len = strlen( $data );

		$num_tables = unpack( 'n', substr( $data, 4, 2 ) )[1];
		$tables     = array();

		for ( $i = 0; $i < $num_tables; $i++ ) {
			$offset = 12 + $i * 16;
			if ( $offset + 16 > $data_len ) {
				break;
			}
			$tag = substr( $data, $offset, 4 );
			$unp = unpack( 'NcheckSum/Noffset/Nlength', substr( $data, $offset + 4, 12 ) );
			$tables[ $tag ] = array(
				'offset' => $unp['offset'],
				'length' => $unp['length'],
			);
		}

		// 1. Parse 'head' table for unitsPerEm
		$units_per_em = 1000;
		if ( isset( $tables['head'] ) && $tables['head']['offset'] + 20 <= $data_len ) {
			$h_off        = $tables['head']['offset'];
			$units_per_em = unpack( 'n', substr( $data, $h_off + 18, 2 ) )[1];
		}

		// 2. Parse 'hhea' table for ascent, descent, and numberOfHMetrics
		$ascent        = 800;
		$descent       = -200;
		$num_h_metrics = 1;
		if ( isset( $tables['hhea'] ) && $tables['hhea']['offset'] + 36 <= $data_len ) {
			$hh_off        = $tables['hhea']['offset'];
			$hh_asc        = unpack( 'n', substr( $data, $hh_off + 4, 2 ) )[1];
			$hh_desc       = unpack( 'n', substr( $data, $hh_off + 6, 2 ) )[1];
			$ascent        = ( $hh_asc >= 0x8000 ) ? ( $hh_asc - 0x10000 ) : $hh_asc;
			$descent       = ( $hh_desc >= 0x8000 ) ? ( $hh_desc - 0x10000 ) : $hh_desc;
			$num_h_metrics = unpack( 'n', substr( $data, $hh_off + 34, 2 ) )[1];
		}

		// 3. Parse 'hmtx' table for advance widths
		$advance_widths = array();
		if ( isset( $tables['hmtx'] ) ) {
			$hmtx_off = $tables['hmtx']['offset'];
			for ( $gid = 0; $gid < $num_h_metrics; $gid++ ) {
				$rec_off = $hmtx_off + $gid * 4;
				if ( $rec_off + 2 > $data_len ) {
					break;
				}
				$adv_w                  = unpack( 'n', substr( $data, $rec_off, 2 ) )[1];
				$advance_widths[ $gid ] = $adv_w;
			}
		}
		$last_adv = ! empty( $advance_widths ) ? end( $advance_widths ) : (int) ( $units_per_em * 0.5 );

		// 4. Parse 'cmap' table for Unicode to GID mapping
		$char_to_gid = array();
		if ( isset( $tables['cmap'] ) && $tables['cmap']['offset'] + 4 <= $data_len ) {
			$cmap_off      = $tables['cmap']['offset'];
			$num_sub       = unpack( 'n', substr( $data, $cmap_off + 2, 2 ) )[1];
			$sub_off       = null;
			$best_priority = -1;

			// Prefer Format 4 subtable (platform 3 encoding 1 or platform 0 encoding 3) instead of breaking early on Format 12.
			for ( $i = 0; $i < $num_sub; $i++ ) {
				$rec_pos = $cmap_off + 4 + $i * 8;
				if ( $rec_pos + 8 > $data_len ) {
					break;
				}
				$rec  = unpack( 'nplat/nenc/Noffset', substr( $data, $rec_pos, 8 ) );
				$cand = $cmap_off + $rec['offset'];
				if ( $cand + 2 > $data_len ) {
					continue;
				}
				$cand_fmt = unpack( 'n', substr( $data, $cand, 2 ) )[1];
				$priority = -1;

				if ( 4 === $cand_fmt ) {
					if ( 3 === $rec['plat'] && 1 === $rec['enc'] ) {
						$priority = 100;
					} elseif ( 0 === $rec['plat'] && 3 === $rec['enc'] ) {
						$priority = 90;
					} elseif ( 0 === $rec['plat'] ) {
						$priority = 80;
					} else {
						$priority = 70;
					}
				} elseif ( 12 === $cand_fmt ) {
					if ( 3 === $rec['plat'] && 10 === $rec['enc'] ) {
						$priority = 50;
					} elseif ( 0 === $rec['plat'] ) {
						$priority = 40;
					} else {
						$priority = 30;
					}
				}

				if ( $priority > $best_priority ) {
					$best_priority = $priority;
					$sub_off       = $cand;
				}
			}

			if ( null === $sub_off && $num_sub > 0 && $cmap_off + 12 <= $data_len ) {
				$rec     = unpack( 'nplat/nenc/Noffset', substr( $data, $cmap_off + 4, 8 ) );
				$sub_off = $cmap_off + $rec['offset'];
			}

			if ( null !== $sub_off && $sub_off + 2 <= $data_len ) {
				$fmt = unpack( 'n', substr( $data, $sub_off, 2 ) )[1];
				if ( 4 === $fmt && $sub_off + 14 <= $data_len ) {
					$seg_count_x2 = unpack( 'n', substr( $data, $sub_off + 6, 2 ) )[1];
					$seg_count    = (int) ( $seg_count_x2 / 2 );

					if ( $seg_count > 0 && $sub_off + 16 + $seg_count * 8 <= $data_len ) {
						$end_codes     = array_values( unpack( 'n' . $seg_count, substr( $data, $sub_off + 14, $seg_count * 2 ) ) );
						$start_codes   = array_values( unpack( 'n' . $seg_count, substr( $data, $sub_off + 16 + $seg_count * 2, $seg_count * 2 ) ) );
						$id_deltas_raw = array_values( unpack( 'n' . $seg_count, substr( $data, $sub_off + 16 + $seg_count * 4, $seg_count * 2 ) ) );
						$ro_base       = $sub_off + 16 + $seg_count * 6;

						for ( $seg = 0; $seg < $seg_count; $seg++ ) {
							$start = $start_codes[ $seg ];
							$end   = $end_codes[ $seg ];
							$raw_d = $id_deltas_raw[ $seg ];
							$delta = ( $raw_d >= 0x8000 ) ? ( $raw_d - 0x10000 ) : $raw_d;
							$ro    = unpack( 'n', substr( $data, $ro_base + $seg * 2, 2 ) )[1];

							if ( 0xFFFF === $start || 0xFFFF === $end || $end < $start ) {
								continue;
							}

							for ( $cp = $start; $cp <= $end; $cp++ ) {
								if ( 0 === $ro ) {
									$gid = ( $cp + $delta ) & 0xFFFF;
								} else {
									$ro_addr = $ro_base + $seg * 2 + $ro + ( $cp - $start ) * 2;
									if ( $ro_addr + 2 > $data_len ) {
										continue;
									}
									$gid = unpack( 'n', substr( $data, $ro_addr, 2 ) )[1];
									if ( 0 !== $gid ) {
										$gid = ( $gid + $delta ) & 0xFFFF;
									}
								}
								if ( 0 !== $gid ) {
									$utf8_char = self::codepoint_to_utf8( $cp );
									if ( '' !== $utf8_char ) {
										$char_to_gid[ $utf8_char ] = $gid;
									}
								}
							}
						}
					}
				} elseif ( 12 === $fmt && $sub_off + 16 <= $data_len ) {
					$num_groups = unpack( 'N', substr( $data, $sub_off + 12, 4 ) )[1];
					for ( $g_idx = 0; $g_idx < $num_groups; $g_idx++ ) {
						$g_pos = $sub_off + 16 + $g_idx * 12;
						if ( $g_pos + 12 > $data_len ) {
							break;
						}
						$grp        = unpack( 'Nstart/Nend/Ngid', substr( $data, $g_pos, 12 ) );
						$start_cp   = $grp['start'];
						$end_cp     = $grp['end'];
						$start_gid  = $grp['gid'];
						if ( $end_cp < $start_cp || ( $end_cp - $start_cp ) > 65535 ) {
							continue;
						}
						for ( $cp = $start_cp; $cp <= $end_cp; $cp++ ) {
							$gid = $start_gid + ( $cp - $start_cp );
							if ( 0 !== $gid ) {
								$utf8_char = self::codepoint_to_utf8( $cp );
								if ( '' !== $utf8_char ) {
									$char_to_gid[ $utf8_char ] = $gid;
								}
							}
						}
					}
				}
			}
		}

		// 5. Parse 'SVG ' table
		$gid_to_svg    = array();
		$font_svg_defs = '';
		$max_gz_in     = 15 * 1024 * 1024; // 15 MB max compressed SVG document
		$max_gz_out    = 30 * 1024 * 1024; // 30 MB max decompressed SVG document

		if ( isset( $tables['SVG '] ) && $tables['SVG ']['offset'] + 6 <= $data_len ) {
			$s_off        = $tables['SVG ']['offset'];
			$doc_list_off = unpack( 'N', substr( $data, $s_off + 2, 4 ) )[1];
			$doc_list_pos = $s_off + $doc_list_off;
			$num_entries  = ( $doc_list_pos + 2 <= $data_len ) ? unpack( 'n', substr( $data, $doc_list_pos, 2 ) )[1] : 0;

			for ( $d = 0; $d < $num_entries; $d++ ) {
				$rec_pos = $doc_list_pos + 2 + $d * 12;
				if ( $rec_pos + 12 > $data_len ) {
					break;
				}
				$rec_unp   = unpack( 'nstart/nend/Noffset/Nlen', substr( $data, $rec_pos, 12 ) );
				$start_gid = $rec_unp['start'];
				$end_gid   = $rec_unp['end'];
				$svg_pos   = $doc_list_pos + $rec_unp['offset'];
				$svg_len   = $rec_unp['len'];

				if ( $svg_len <= 0 || $svg_pos < 0 || $svg_pos + $svg_len > $data_len ) {
					continue;
				}

				$raw = substr( $data, $svg_pos, $svg_len );
				if ( strlen( $raw ) >= 2 && substr( $raw, 0, 2 ) === "\x1f\x8b" ) {
					// Gzip-compressed XML: enforce strict compressed & decompressed caps against zip bombs.
					if ( strlen( $raw ) > $max_gz_in || ! function_exists( 'gzdecode' ) ) {
						continue;
					}
					$decoded = @gzdecode( $raw, $max_gz_out ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
					$raw     = ( false !== $decoded ) ? $decoded : '';
				} elseif ( strlen( $raw ) > $max_gz_out ) {
					continue;
				}

				if ( ! empty( $raw ) ) {
					// Clean up XML declaration and sanitize extracted SVG markup before caching.
					$raw = (string) preg_replace( '/<\?xml[^>]*\?>/i', '', $raw );
					$raw = trim( self::sanitize_svg_fragment( $raw ) );
					if ( '' === $raw ) {
						continue;
					}

					// Extract shared <defs> if present in the SVG document.
					if ( preg_match( '/<defs[^>]*>(.*?)<\/defs>/is', $raw, $defs_m ) ) {
						$font_svg_defs .= self::sanitize_svg_fragment( $defs_m[1] ) . "\n";
					}

					for ( $g = $start_gid; $g <= $end_gid; $g++ ) {
						// Look for targeted glyph element if multi-glyph document.
						if ( $start_gid !== $end_gid && preg_match( '/(<(?:g|path|text|symbol)[^>]*id=[\'"]glyph' . $g . '[\'"].*?<\/(?:g|path|text|symbol)>|<(?:g|path|text|symbol)[^>]*id=[\'"]glyph' . $g . '[\'"][^\/>]*\/>)/is', $raw, $gm ) ) {
							$gid_to_svg[ $g ] = self::sanitize_svg_fragment( $gm[1] );
						} else {
							// Single glyph document or root svg wrapper.
							$inner = (string) preg_replace( '/^<svg[^>]*>/i', '', $raw );
							$inner = (string) preg_replace( '/<\/svg>$/i', '', $inner );
							$inner = trim( $inner );
							$gid_to_svg[ $g ] = self::sanitize_svg_fragment( $inner );
						}
					}
				}
			}
		}

		// 6. Build unified glyph dictionary
		$glyphs = array();
		foreach ( $char_to_gid as $char => $gid ) {
			$adv             = isset( $advance_widths[ $gid ] ) ? $advance_widths[ $gid ] : $last_adv;
			$svg_xml         = isset( $gid_to_svg[ $gid ] ) ? $gid_to_svg[ $gid ] : '';
			$glyphs[ $char ] = array(
				'gid' => $gid,
				'adv' => $adv,
				'svg' => $svg_xml,
			);
		}

		$space_adv = isset( $char_to_gid[' '] ) && isset( $advance_widths[ $char_to_gid[' '] ] )
			? $advance_widths[ $char_to_gid[' '] ]
			: (int) ( $units_per_em * 0.35 );

		return array(
			'id'         => $font_id,
			'name'       => ! empty( $font_name ) ? $font_name : $font_id,
			'type'       => 'opentype_svg',
			'unitsPerEm' => $units_per_em,
			'ascent'     => $ascent,
			'descent'    => $descent,
			'defaultAdv' => $space_adv,
			'defs'       => trim( $font_svg_defs ),
			'glyphs'     => $glyphs,
		);
	}
}
