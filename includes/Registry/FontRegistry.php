<?php
/**
 * Mr. Tee Editor - Extensible Font Registry
 *
 * Manages fancy display fonts, multi-color COLOR_SET font pairings,
 * and @font-face CSS generation with embedded base64 data URIs for 100%
 * reliability across web browsers and canvas/SVG print rasterization.
 *
 * @package MrTeeEditor\Registry
 */

namespace MrTeeEditor\Registry;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class FontRegistry {

	/**
	 * Per-request in-memory cache of registered fonts.
	 *
	 * @var array<string, array<string, mixed>>|null
	 */
	private static $fonts_cache = null;

	/**
	 * Per-request in-memory cache of hydrated OpenType-SVG font datasets.
	 *
	 * @var array<string, array<string, mixed>|null>
	 */
	private static $svg_data_cache = array();

	/**
	 * Per-request memoized filemtime() cache (0 = missing/unreadable) for font binaries and .woff2 companions.
	 *
	 * @var array<string, int>
	 */
	private static $file_stat_cache = array();

	/**
	 * Per-request memoized site host and HTTP host pair for same-origin URL normalization.
	 *
	 * @var array{site_host: string, http_host: string}|null
	 */
	private static $host_cache = null;

	/**
	 * Return all built-in bundled font definitions (cannot be overwritten or deleted by custom uploads).
	 *
	 * @return array<string, array<string, mixed>>
	 */
	private static function get_builtin_fonts() {
		return array(
			'super_mario' => array(
				'id'                => 'super_mario',
				'name'              => 'Super Mario (Jeu Vidéo)',
				'family'            => 'Super Mario Wii',
				'aliases'           => array( 'SuperMario256', 'super_mario', 'Super Mario' ),
				'file'              => 'SuperMario256.ttf',
				'format'            => 'truetype',
				'url'               => MR_TEE_EDITOR_URL . 'assets/fonts/SuperMario256.ttf',
				'path'              => MR_TEE_EDITOR_DIR . 'assets/fonts/SuperMario256.ttf',
				'multi_color'       => true,
				'default_color_set' => 'super_mario_palette',
				'palette'           => array( '#209cee', '#ffdd57', '#ff3860', '#23d160' ),
				'stroke'            => '#000000',
				'stroke_width'      => 0.72,
				'outside_only'      => true,
				'letter_spacing'    => 0.15,
				'uppercase'         => true,
				'category'          => 'gaming',
			),
			'press_start' => array(
				'id'                => 'press_start',
				'name'              => 'Arcade 8-Bit (Pixel)',
				'family'            => 'Press Start 2P',
				'aliases'           => array( 'PressStart2P', 'press_start', 'Press Start' ),
				'file'              => 'PressStart2P-Regular.ttf',
				'format'            => 'truetype',
				'url'               => MR_TEE_EDITOR_URL . 'assets/fonts/PressStart2P-Regular.ttf',
				'path'              => MR_TEE_EDITOR_DIR . 'assets/fonts/PressStart2P-Regular.ttf',
				'multi_color'       => true,
				'default_color_set' => 'arcade_gold',
				'palette'           => array( '#ffdd57', '#ff3860', '#209cee', '#23d160' ),
				'stroke'            => '#000000',
				'stroke_width'      => 0.55,
				'outside_only'      => true,
				'letter_spacing'    => 0.05,
				'uppercase'         => true,
				'category'          => 'gaming',
			),
			'bungee' => array(
				'id'                => 'bungee',
				'name'              => 'Bungee (Gras & Moderne)',
				'family'            => 'Bungee',
				'aliases'           => array( 'bungee', 'Bungee-Regular' ),
				'file'              => 'bungee.ttf',
				'format'            => 'truetype',
				'url'               => MR_TEE_EDITOR_URL . 'assets/fonts/bungee.ttf',
				'path'              => MR_TEE_EDITOR_DIR . 'assets/fonts/bungee.ttf',
				'multi_color'       => true,
				'default_color_set' => 'super_mario_palette',
				'palette'           => array( '#209cee', '#ffdd57', '#ff3860', '#23d160' ),
				'stroke'            => '#000000',
				'stroke_width'      => 0.65,
				'outside_only'      => true,
				'letter_spacing'    => 0.08,
				'uppercase'         => true,
				'category'          => 'display',
			),
			'luckiest_guy' => array(
				'id'                => 'luckiest_guy',
				'name'              => 'Luckiest Guy (Bulle BD & Fun)',
				'family'            => 'Luckiest Guy',
				'aliases'           => array( 'LuckiestGuy', 'luckiest_guy', 'LuckiestGuy-Regular' ),
				'file'              => 'luckiest-guy.ttf',
				'format'            => 'truetype',
				'url'               => MR_TEE_EDITOR_URL . 'assets/fonts/luckiest-guy.ttf',
				'path'              => MR_TEE_EDITOR_DIR . 'assets/fonts/luckiest-guy.ttf',
				'multi_color'       => true,
				'default_color_set' => 'super_mario_palette',
				'palette'           => array( '#ff3860', '#ffdd57', '#209cee', '#23d160' ),
				'stroke'            => '#000000',
				'stroke_width'      => 0.70,
				'outside_only'      => true,
				'letter_spacing'    => 0.06,
				'uppercase'         => true,
				'category'          => 'display',
			),
			'cocogoose' => array(
				'id'                => 'cocogoose',
				'name'              => 'Cocogoose (Caps Géométrique)',
				'family'            => 'COCOGOOSE CAPS',
				'aliases'           => array( 'CocogooseCaps', 'Cocogoose', 'cocogoose' ),
				'file'              => 'cocogoose-caps.ttf',
				'format'            => 'truetype',
				'url'               => MR_TEE_EDITOR_URL . 'assets/fonts/cocogoose-caps.ttf',
				'path'              => MR_TEE_EDITOR_DIR . 'assets/fonts/cocogoose-caps.ttf',
				'multi_color'       => false,
				'default_color_set' => 'classic_white',
				'palette'           => array( '#ffffff' ),
				'stroke'            => '#000000',
				'stroke_width'      => 0.65,
				'outside_only'      => true,
				'letter_spacing'    => 0.12,
				'uppercase'         => true,
				'category'          => 'headline',
			),
			'bebas' => array(
				'id'                => 'bebas',
				'name'              => 'Bebas Neue (Impact Condensé)',
				'family'            => 'Bebas Neue',
				'aliases'           => array( 'BebasNeue', 'bebas', 'BebasNeue-Regular' ),
				'file'              => 'BebasNeue-Regular.ttf',
				'format'            => 'truetype',
				'url'               => MR_TEE_EDITOR_URL . 'assets/fonts/BebasNeue-Regular.ttf',
				'path'              => MR_TEE_EDITOR_DIR . 'assets/fonts/BebasNeue-Regular.ttf',
				'multi_color'       => false,
				'default_color_set' => 'retro_black',
				'palette'           => array( '#111827' ),
				'stroke'            => 'transparent',
				'stroke_width'      => 0,
				'outside_only'      => false,
				'letter_spacing'    => 0.10,
				'uppercase'         => true,
				'category'          => 'headline',
			),
			'marker' => array(
				'id'                => 'marker',
				'name'              => 'Permanent Marker (Street Art)',
				'family'            => 'Permanent Marker',
				'aliases'           => array( 'PermanentMarker', 'marker', 'PermanentMarker-Regular' ),
				'file'              => 'PermanentMarker-Regular.ttf',
				'format'            => 'truetype',
				'url'               => MR_TEE_EDITOR_URL . 'assets/fonts/PermanentMarker-Regular.ttf',
				'path'              => MR_TEE_EDITOR_DIR . 'assets/fonts/PermanentMarker-Regular.ttf',
				'multi_color'       => false,
				'default_color_set' => 'retro_black',
				'palette'           => array( '#111827' ),
				'stroke'            => 'transparent',
				'stroke_width'      => 0,
				'outside_only'      => false,
				'letter_spacing'    => 0.05,
				'uppercase'         => true,
				'category'          => 'handwritten',
			),
			'pacifico' => array(
				'id'                => 'pacifico',
				'name'              => 'Pacifico (Script Cursif)',
				'family'            => 'Pacifico',
				'aliases'           => array( 'pacifico', 'Pacifico-Regular' ),
				'file'              => 'Pacifico-Regular.ttf',
				'format'            => 'truetype',
				'url'               => MR_TEE_EDITOR_URL . 'assets/fonts/Pacifico-Regular.ttf',
				'path'              => MR_TEE_EDITOR_DIR . 'assets/fonts/Pacifico-Regular.ttf',
				'multi_color'       => false,
				'default_color_set' => 'retro_black',
				'palette'           => array( '#111827' ),
				'stroke'            => 'transparent',
				'stroke_width'      => 0,
				'outside_only'      => false,
				'letter_spacing'    => 0.02,
				'uppercase'         => false,
				'category'          => 'script',
			),
			'abril' => array(
				'id'                => 'abril',
				'name'              => 'Abril Fatface (Rétro Chic)',
				'family'            => 'Abril Fatface',
				'aliases'           => array( 'AbrilFatface', 'abril', 'AbrilFatface-Regular' ),
				'file'              => 'AbrilFatface-Regular.ttf',
				'format'            => 'truetype',
				'url'               => MR_TEE_EDITOR_URL . 'assets/fonts/AbrilFatface-Regular.ttf',
				'path'              => MR_TEE_EDITOR_DIR . 'assets/fonts/AbrilFatface-Regular.ttf',
				'multi_color'       => false,
				'default_color_set' => 'retro_black',
				'palette'           => array( '#111827' ),
				'stroke'            => 'transparent',
				'stroke_width'      => 0,
				'outside_only'      => false,
				'letter_spacing'    => 0.04,
				'uppercase'         => false,
				'category'          => 'serif',
			),
			'cinzel' => array(
				'id'                => 'cinzel',
				'name'              => 'Cinzel Decorative (Prestige)',
				'family'            => 'Cinzel Decorative',
				'aliases'           => array( 'CinzelDecorative', 'cinzel', 'CinzelDecorative-Bold' ),
				'file'              => 'CinzelDecorative-Bold.ttf',
				'format'            => 'truetype',
				'url'               => MR_TEE_EDITOR_URL . 'assets/fonts/CinzelDecorative-Bold.ttf',
				'path'              => MR_TEE_EDITOR_DIR . 'assets/fonts/CinzelDecorative-Bold.ttf',
				'multi_color'       => false,
				'default_color_set' => 'retro_black',
				'palette'           => array( '#111827' ),
				'stroke'            => 'transparent',
				'stroke_width'      => 0,
				'outside_only'      => false,
				'letter_spacing'    => 0.10,
				'uppercase'         => true,
				'category'          => 'serif',
			),
			// OpenType-SVG Color Fonts (Vector Multi-Color Artwork)
			'use_your_imagination_blue' => array(
				'id'                => 'use_your_imagination_blue',
				'name'              => 'Use Your Imagination (Bleu Néon)',
				'family'            => 'Use Your Imagination Blue',
				'aliases'           => array( 'UseYourImagination-Blue', 'UseYourImaginationb', 'use_your_imagination_blue' ),
				'file'              => 'UseYourImagination-Blue.ttf',
				'format'            => 'truetype',
				'url'               => MR_TEE_EDITOR_URL . 'assets/fonts/UseYourImagination-Blue.ttf',
				'path'              => MR_TEE_EDITOR_DIR . 'assets/fonts/UseYourImagination-Blue.ttf',
				'data_url'          => MR_TEE_EDITOR_URL . 'assets/fonts/cache/use_your_imagination_blue.json',
				'font_type'         => 'opentype_svg',
				'color_svg'         => true,
				'category'          => 'color_svg',
				'letter_spacing'    => 0.05,
				'uppercase'         => true,
			),
			'use_your_imagination_green' => array(
				'id'                => 'use_your_imagination_green',
				'name'              => 'Use Your Imagination (Vert Flash)',
				'family'            => 'Use Your Imagination Green',
				'aliases'           => array( 'UseYourImagination-Green', 'UseYourImaginationg', 'use_your_imagination_green' ),
				'file'              => 'UseYourImagination-Green.ttf',
				'format'            => 'truetype',
				'url'               => MR_TEE_EDITOR_URL . 'assets/fonts/UseYourImagination-Green.ttf',
				'path'              => MR_TEE_EDITOR_DIR . 'assets/fonts/UseYourImagination-Green.ttf',
				'data_url'          => MR_TEE_EDITOR_URL . 'assets/fonts/cache/use_your_imagination_green.json',
				'font_type'         => 'opentype_svg',
				'color_svg'         => true,
				'category'          => 'color_svg',
				'letter_spacing'    => 0.05,
				'uppercase'         => true,
			),
			'use_your_imagination_red' => array(
				'id'                => 'use_your_imagination_red',
				'name'              => 'Use Your Imagination (Or Solaire)',
				'family'            => 'Use Your Imagination Red',
				'aliases'           => array( 'UseYourImagination-Red', 'UseYourImaginationr', 'use_your_imagination_red' ),
				'file'              => 'UseYourImagination-Red.ttf',
				'format'            => 'truetype',
				'url'               => MR_TEE_EDITOR_URL . 'assets/fonts/UseYourImagination-Red.ttf',
				'path'              => MR_TEE_EDITOR_DIR . 'assets/fonts/UseYourImagination-Red.ttf',
				'data_url'          => MR_TEE_EDITOR_URL . 'assets/fonts/cache/use_your_imagination_red.json',
				'font_type'         => 'opentype_svg',
				'color_svg'         => true,
				'category'          => 'color_svg',
				'letter_spacing'    => 0.05,
				'uppercase'         => true,
			),
			'palestine_1445' => array(
				'id'                => 'palestine_1445',
				'name'              => 'Palestine 1445 (Drapeau SVG)',
				'family'            => 'Palestine 1445',
				'aliases'           => array( 'Palestine1445', 'palestine_1445' ),
				'file'              => 'Palestine1445.ttf',
				'format'            => 'truetype',
				'url'               => MR_TEE_EDITOR_URL . 'assets/fonts/Palestine1445.ttf',
				'path'              => MR_TEE_EDITOR_DIR . 'assets/fonts/Palestine1445.ttf',
				'data_url'          => MR_TEE_EDITOR_URL . 'assets/fonts/cache/palestine_1445.json',
				'font_type'         => 'opentype_svg',
				'color_svg'         => true,
				'colr_hybrid'       => true,
				'category'          => 'color_svg',
				'letter_spacing'    => 0.06,
				'uppercase'         => false,
			),
			'pizzeria' => array(
				'id'                => 'pizzeria',
				'name'              => 'Pizzeria (Pizza 3D OpenType-SVG)',
				'family'            => 'Pizzeria',
				'aliases'           => array( 'Pizzeria', 'pizzeria', 'PizzeriapersonaluseBold' ),
				'file'              => 'PizzeriapersonaluseBold-L3j1Z.otf',
				'format'            => 'opentype',
				'url'               => MR_TEE_EDITOR_URL . 'assets/fonts/PizzeriapersonaluseBold-L3j1Z.otf',
				'path'              => MR_TEE_EDITOR_DIR . 'assets/fonts/PizzeriapersonaluseBold-L3j1Z.otf',
				'data_url'          => MR_TEE_EDITOR_URL . 'assets/fonts/cache/pizzeria.json',
				'font_type'         => 'opentype_svg',
				'color_svg'         => true,
				'category'          => 'color_svg',
				'letter_spacing'    => 0.05,
				'uppercase'         => true,
			),
			'partyboy' => array(
				'id'                => 'partyboy',
				'name'              => 'Partyboy (Ballon Or 3D OpenType-SVG)',
				'family'            => 'Partyboy',
				'aliases'           => array( 'Partyboy', 'partyboy', 'PartyboypersonaluseXbdxcn' ),
				'file'              => 'PartyboypersonaluseXbdxcn-8MAMA.otf',
				'format'            => 'opentype',
				'url'               => MR_TEE_EDITOR_URL . 'assets/fonts/PartyboypersonaluseXbdxcn-8MAMA.otf',
				'path'              => MR_TEE_EDITOR_DIR . 'assets/fonts/PartyboypersonaluseXbdxcn-8MAMA.otf',
				'data_url'          => MR_TEE_EDITOR_URL . 'assets/fonts/cache/partyboy.json',
				'font_type'         => 'opentype_svg',
				'color_svg'         => true,
				'category'          => 'color_svg',
				'letter_spacing'    => 0.05,
				'uppercase'         => true,
			),
			'brainzoo' => array(
				'id'                => 'brainzoo',
				'name'              => 'Brainzoo (Créature 3D OpenType-SVG)',
				'family'            => 'Brainzoo',
				'aliases'           => array( 'Brainzoo', 'brainzoo', 'BrainzoopersonaluseBold' ),
				'file'              => 'BrainzoopersonaluseBold-p7mLO.otf',
				'format'            => 'opentype',
				'url'               => MR_TEE_EDITOR_URL . 'assets/fonts/BrainzoopersonaluseBold-p7mLO.otf',
				'path'              => MR_TEE_EDITOR_DIR . 'assets/fonts/BrainzoopersonaluseBold-p7mLO.otf',
				'data_url'          => MR_TEE_EDITOR_URL . 'assets/fonts/cache/brainzoo.json',
				'font_type'         => 'opentype_svg',
				'color_svg'         => true,
				'category'          => 'color_svg',
				'letter_spacing'    => 0.05,
				'uppercase'         => true,
			),
			'purple_magic' => array(
				'id'                => 'purple_magic',
				'name'              => 'Purple Magic (Bubblegum 3D OpenType-SVG)',
				'family'            => 'Purple Magic',
				'aliases'           => array( 'Purple Magic', 'purple_magic', 'PurplemagicdemoSvg' ),
				'file'              => 'PurplemagicdemoSvg-x32mO.otf',
				'format'            => 'opentype',
				'url'               => MR_TEE_EDITOR_URL . 'assets/fonts/PurplemagicdemoSvg-x32mO.otf',
				'path'              => MR_TEE_EDITOR_DIR . 'assets/fonts/PurplemagicdemoSvg-x32mO.otf',
				'data_url'          => MR_TEE_EDITOR_URL . 'assets/fonts/cache/purple_magic.json',
				'font_type'         => 'opentype_svg',
				'color_svg'         => true,
				'category'          => 'color_svg',
				'letter_spacing'    => 0.05,
				'uppercase'         => true,
			),
			'jellyjam' => array(
				'id'                => 'jellyjam',
				'name'              => 'Jellyjam (Gelée Rubis 3D OpenType-SVG)',
				'family'            => 'Jellyjam',
				'aliases'           => array( 'Jellyjam', 'jellyjam', 'JellyjampersonaluseBold' ),
				'file'              => 'JellyjampersonaluseBold-Rpjev.otf',
				'format'            => 'opentype',
				'url'               => MR_TEE_EDITOR_URL . 'assets/fonts/JellyjampersonaluseBold-Rpjev.otf',
				'path'              => MR_TEE_EDITOR_DIR . 'assets/fonts/JellyjampersonaluseBold-Rpjev.otf',
				'data_url'          => MR_TEE_EDITOR_URL . 'assets/fonts/cache/jellyjam.json',
				'font_type'         => 'opentype_svg',
				'color_svg'         => true,
				'category'          => 'color_svg',
				'letter_spacing'    => 0.05,
				'uppercase'         => true,
			),
			'lovespink_black' => array(
				'id'                => 'lovespink_black',
				'name'              => 'Lovespink Black (Origami Rose 3D OpenType-SVG)',
				'family'            => 'Lovespink Black',
				'aliases'           => array( 'Lovespink Black', 'lovespink_black', 'LovespinkpersonaluseBlack' ),
				'file'              => 'LovespinkpersonaluseBlack-1Gy8L.otf',
				'format'            => 'opentype',
				'url'               => MR_TEE_EDITOR_URL . 'assets/fonts/LovespinkpersonaluseBlack-1Gy8L.otf',
				'path'              => MR_TEE_EDITOR_DIR . 'assets/fonts/LovespinkpersonaluseBlack-1Gy8L.otf',
				'data_url'          => MR_TEE_EDITOR_URL . 'assets/fonts/cache/lovespink_black.json',
				'font_type'         => 'opentype_svg',
				'color_svg'         => true,
				'category'          => 'color_svg',
				'letter_spacing'    => 0.05,
				'uppercase'         => true,
			),
		);
	}

	/**
	 * Return all registered fancy fonts (built-in bundled fonts + merchant custom fonts).
	 *
	 * @return array<string, array<string, mixed>>
	 */
	public static function get_fonts() {
		if ( null !== self::$fonts_cache ) {
			return apply_filters( 'mr_tee_registered_fonts', self::$fonts_cache );
		}

		$fonts = self::get_builtin_fonts();

		// Merge persistent custom fonts uploaded by merchant (never allow overriding built-in core IDs)
		$custom = get_option( 'mr_tee_custom_fonts', array() );
		if ( ! empty( $custom ) && is_array( $custom ) ) {
			foreach ( $custom as $c_id => $c_def ) {
				if ( ! is_array( $c_def ) || isset( $fonts[ $c_id ] ) ) {
					continue;
				}
				if ( ! empty( $c_def['file'] ) && 'otf' === strtolower( pathinfo( (string) $c_def['file'], PATHINFO_EXTENSION ) ) ) {
					$c_def['format'] = 'opentype';
				}
				$c_def['is_custom'] = true;
				$fonts[ $c_id ]     = $c_def;
			}
		}

		// Enrich OpenType-SVG fonts from the persistent package index (zero disk I/O in steady state)
		$svg_font_ids = array();
		foreach ( $fonts as $f_id => $f_data ) {
			if ( ( ! empty( $f_data['font_type'] ) && 'opentype_svg' === $f_data['font_type'] ) || ! empty( $f_data['color_svg'] ) ) {
				$svg_font_ids[] = $f_id;
			}
		}
		if ( ! empty( $svg_font_ids ) ) {
			$pkg_urls = SvgFontPackager::get_indexed_package_urls( $svg_font_ids );
			foreach ( $pkg_urls as $f_id => $meta ) {
				if ( ! empty( $meta['manifest_url'] ) ) {
					$fonts[ $f_id ]['manifest_url'] = $meta['manifest_url'];
				}
				if ( ! empty( $meta['thumb_url'] ) ) {
					$fonts[ $f_id ]['thumb_url'] = $meta['thumb_url'];
				}
			}
		}

		self::$fonts_cache = $fonts;

		return apply_filters( 'mr_tee_registered_fonts', self::$fonts_cache );
	}

	/**
	 * Register WordPress AJAX and WP-Cron hooks for font management and background packaging.
	 */
	public static function register_hooks() {
		add_action( 'wp_ajax_mr_tee_upload_font', array( __CLASS__, 'ajax_upload_font' ) );
		add_action( 'wp_ajax_mr_tee_get_svg_font_data', array( __CLASS__, 'ajax_get_svg_font_data' ) );
		add_action( 'wp_ajax_nopriv_mr_tee_get_svg_font_data', array( __CLASS__, 'ajax_get_svg_font_data' ) );
		add_action( SvgFontPackager::CRON_HOOK, array( SvgFontPackager::class, 'build_all' ) );
	}

	/**
	 * Validate that a file on disk is a genuine SFNT (TrueType / OpenType) binary
	 * and within the 30 MB size cap.
	 *
	 * @param string $file_path Absolute path to font binary.
	 * @return bool
	 */
	private static function is_valid_sfnt_file( $file_path ) {
		if ( ! is_string( $file_path ) || '' === $file_path || ! file_exists( $file_path ) || ! is_readable( $file_path ) ) {
			return false;
		}
		$max_bytes = 30 * 1024 * 1024; // 30 MB
		$size      = @filesize( $file_path ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		if ( false === $size || $size < 12 || $size > $max_bytes ) {
			return false;
		}
		$fh = @fopen( $file_path, 'rb' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen, WordPress.PHP.NoSilencedErrors.Discouraged
		if ( ! $fh ) {
			return false;
		}
		$magic = fread( $fh, 4 ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fread
		fclose( $fh ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose

		return in_array( $magic, array( "\x00\x01\x00\x00", 'OTTO', 'true', 'typ1' ), true );
	}

	/**
	 * Check whether an SFNT font binary contains both `COLR` and `CPAL` color tables
	 * (true hybrid COLR/CPAL font such as Palestine 1445).
	 *
	 * @param string $file_path Absolute path to font binary.
	 * @return bool
	 */
	private static function has_colr_cpal_tables( $file_path ) {
		if ( ! is_string( $file_path ) || '' === $file_path || ! file_exists( $file_path ) || ! is_readable( $file_path ) ) {
			return false;
		}
		$fh = @fopen( $file_path, 'rb' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen, WordPress.PHP.NoSilencedErrors.Discouraged
		if ( ! $fh ) {
			return false;
		}
		$header = fread( $fh, 12 ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fread
		if ( strlen( $header ) < 12 ) {
			fclose( $fh ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
			return false;
		}
		$num_tables = unpack( 'n', substr( $header, 4, 2 ) )[1];
		$has_colr   = false;
		$has_cpal   = false;
		for ( $i = 0; $i < $num_tables; $i++ ) {
			$entry = fread( $fh, 16 ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fread
			if ( strlen( $entry ) < 16 ) {
				break;
			}
			$tag = substr( $entry, 0, 4 );
			if ( 'COLR' === $tag ) {
				$has_colr = true;
			} elseif ( 'CPAL' === $tag ) {
				$has_cpal = true;
			}
			if ( $has_colr && $has_cpal ) {
				fclose( $fh ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
				return true;
			}
		}
		fclose( $fh ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
		return false;
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
		$norm_target = wp_normalize_path( $real_target );
		$norm_parent = trailingslashit( wp_normalize_path( $real_parent ) );
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
	 * Handle admin AJAX font upload (.ttf, .otf, .zip).
	 *
	 * Always stores custom fonts inside `wp-content/uploads/mr_tee_editor/fonts/` with unique
	 * filenames and validates SFNT magic bytes so bundled core fonts are never overwritten.
	 */
	public static function ajax_upload_font() {
		if ( false === check_ajax_referer( 'mr_tee_admin_nonce', 'nonce', false ) ) {
			wp_send_json_error( array( 'message' => __( 'Nonce de sécurité invalide ou expiré.', 'mr-tee-editor' ) ), 403 );
		}

		if ( ! current_user_can( 'manage_woocommerce' ) && ! current_user_can( 'edit_products' ) ) {
			wp_send_json_error( array( 'message' => __( 'Permissions insuffisantes.', 'mr-tee-editor' ) ), 403 );
		}

		if ( empty( $_FILES['font_file'] ) || ! is_array( $_FILES['font_file'] ) ) {
			wp_send_json_error( array( 'message' => __( 'Aucun fichier fourni.', 'mr-tee-editor' ) ), 400 );
		}

		$file = $_FILES['font_file']; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		if ( ! empty( $file['error'] ) && UPLOAD_ERR_OK !== intval( $file['error'] ) ) {
			wp_send_json_error( array( 'message' => __( 'Erreur lors du téléversement du fichier police.', 'mr-tee-editor' ) ), 400 );
		}

		$max_bytes = 30 * 1024 * 1024; // 30 MB
		if ( ! empty( $file['size'] ) && intval( $file['size'] ) > $max_bytes ) {
			wp_send_json_error( array( 'message' => __( 'Fichier trop volumineux (max 30 Mo).', 'mr-tee-editor' ) ), 400 );
		}

		$orig_filename = ! empty( $file['name'] ) ? sanitize_file_name( wp_unslash( $file['name'] ) ) : '';
		$ext           = strtolower( pathinfo( $orig_filename, PATHINFO_EXTENSION ) );

		if ( ! in_array( $ext, array( 'ttf', 'otf', 'zip' ), true ) ) {
			wp_send_json_error( array( 'message' => __( 'Format non supporté. Veuillez envoyer un fichier .ttf, .otf ou .zip.', 'mr-tee-editor' ) ), 400 );
		}

		// Always persist custom fonts in uploads/mr_tee_editor/fonts/ (never overwrite bundled assets/fonts/).
		$upload_paths = \MrTeeEditor\Export\PrintAssetGenerator::get_upload_dir( 'fonts' );
		$fonts_dir    = trailingslashit( $upload_paths['dir'] );
		$fonts_url    = trailingslashit( $upload_paths['url'] );

		if ( ! is_dir( $fonts_dir ) ) {
			wp_mkdir_p( $fonts_dir );
		}

		$uploaded_fonts = array();

		if ( 'zip' === $ext ) {
			if ( ! class_exists( 'ZipArchive' ) ) {
				wp_send_json_error( array( 'message' => __( 'Extension ZipArchive manquante sur le serveur.', 'mr-tee-editor' ) ), 500 );
			}
			$zip = new \ZipArchive();
			if ( true === $zip->open( $file['tmp_name'] ) ) {
				$max_entries = min( (int) $zip->numFiles, 50 );
				for ( $i = 0; $i < $max_entries; $i++ ) {
					$filename = (string) $zip->getNameIndex( $i );
					$f_ext    = strtolower( pathinfo( $filename, PATHINFO_EXTENSION ) );
					if ( ! in_array( $f_ext, array( 'ttf', 'otf' ), true ) || false !== strpos( $filename, '__MACOSX' ) ) {
						continue;
					}
					$stat = $zip->statIndex( $i );
					if ( ! is_array( $stat ) || empty( $stat['size'] ) || (int) $stat['size'] > $max_bytes ) {
						continue;
					}
					$base_name   = sanitize_file_name( basename( $filename ) );
					$unique_name = wp_unique_filename( $fonts_dir, $base_name );
					$target_path = $fonts_dir . $unique_name;
					$stream      = $zip->getStream( $filename );
					if ( ! $stream ) {
						continue;
					}
					$out_fp = @fopen( $target_path, 'wb' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen, WordPress.PHP.NoSilencedErrors.Discouraged
					if ( ! $out_fp ) {
						fclose( $stream ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
						continue;
					}
					$copied = 0;
					$ok     = true;
					while ( ! feof( $stream ) ) {
						$buf = fread( $stream, 65536 ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fread
						if ( false === $buf ) {
							$ok = false;
							break;
						}
						$copied += strlen( $buf );
						if ( $copied > $max_bytes ) {
							$ok = false;
							break;
						}
						if ( '' !== $buf && false === fwrite( $out_fp, $buf ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite
							$ok = false;
							break;
						}
					}
					fclose( $stream ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
					fclose( $out_fp ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose

					if ( $ok && self::is_valid_sfnt_file( $target_path ) ) {
						$uploaded_fonts[] = $target_path;
					} else {
						@unlink( $target_path ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
					}
				}
				$zip->close();
			}
		} else {
			$unique_name = wp_unique_filename( $fonts_dir, $orig_filename );
			$target_path = $fonts_dir . $unique_name;
			if ( move_uploaded_file( $file['tmp_name'], $target_path ) || @copy( $file['tmp_name'], $target_path ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
				if ( self::is_valid_sfnt_file( $target_path ) ) {
					$uploaded_fonts[] = $target_path;
				} else {
					@unlink( $target_path ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
				}
			}
		}

		if ( empty( $uploaded_fonts ) ) {
			wp_send_json_error( array( 'message' => __( 'Aucune police TTF/OTF valide trouvée (signature SFNT invalide ou fichier corrompu).', 'mr-tee-editor' ) ), 400 );
		}

		$builtin_fonts      = self::get_builtin_fonts();
		$registered_results = array();
		$custom_fonts       = get_option( 'mr_tee_custom_fonts', array() );
		if ( ! is_array( $custom_fonts ) ) {
			$custom_fonts = array();
		}

		foreach ( $uploaded_fonts as $font_path ) {
			$filename_clean = pathinfo( $font_path, PATHINFO_FILENAME );
			$f_ext          = strtolower( pathinfo( $font_path, PATHINFO_EXTENSION ) );
			$base_id        = sanitize_key( str_replace( array( '-', ' ' ), '_', $filename_clean ) );
			if ( '' === $base_id ) {
				$base_id = 'custom_font_' . substr( md5( basename( $font_path ) ), 0, 8 );
			}

			// Never collide with built-in font IDs or overwrite another existing custom font.
			$font_id = $base_id;
			if ( isset( $builtin_fonts[ $font_id ] ) ) {
				$font_id = $base_id . '_custom';
			}
			$suffix = 2;
			while ( isset( $builtin_fonts[ $font_id ] ) || isset( $custom_fonts[ $font_id ] ) ) {
				$font_id = $base_id . '_' . $suffix;
				$suffix++;
			}

			$font_name   = ucwords( str_replace( array( '-', '_' ), ' ', $filename_clean ) );
			$is_ot_svg   = OpenTypeSvgParser::is_opentype_svg( $font_path );
			$font_format = ( 'otf' === $f_ext ) ? 'opentype' : 'truetype';

			$font_def = array(
				'id'             => $font_id,
				'name'           => $is_ot_svg ? $font_name . ' (OpenType-SVG)' : $font_name,
				'family'         => $font_name,
				'aliases'        => array( $font_id, $font_name ),
				'file'           => basename( $font_path ),
				'format'         => $font_format,
				'url'            => $fonts_url . basename( $font_path ),
				'path'           => $font_path,
				'font_type'      => $is_ot_svg ? 'opentype_svg' : 'truetype',
				'color_svg'      => $is_ot_svg,
				'category'       => $is_ot_svg ? 'color_svg' : 'display',
				'letter_spacing' => 0.05,
				'uppercase'      => true,
				'is_custom'      => true,
			);

			if ( ! $is_ot_svg ) {
				$font_def['multi_color']  = false;
				$font_def['palette']      = array( '#111827' );
				$font_def['stroke']       = 'transparent';
				$font_def['stroke_width'] = 0;
				$font_def['outside_only'] = false;
			}

			if ( $is_ot_svg ) {
				if ( self::has_colr_cpal_tables( $font_path ) ) {
					$font_def['colr_hybrid'] = true;
				}
				SvgFontPackager::invalidate_index( $font_id );
				$manifest = SvgFontPackager::build( $font_path, $font_id, $font_name );
				if ( ! empty( $manifest ) ) {
					$font_def['manifest_url'] = SvgFontPackager::manifest_url( $font_id );
					$font_def['thumb_url']    = SvgFontPackager::thumb_url( $font_id );
					$font_def['glyph_count']  = ! empty( $manifest['glyphs'] ) ? count( (array) $manifest['glyphs'] ) : 0;
				} else {
					SvgFontPackager::invalidate_index( $font_id );
					$cache_data              = OpenTypeSvgParser::get_or_create_cache( $font_path, $font_id, $font_name );
					$font_def['data_url']    = $fonts_url . 'cache/' . $font_id . '.json';
					$font_def['glyph_count'] = ! empty( $cache_data['glyphs'] ) ? count( $cache_data['glyphs'] ) : 0;
				}
			}

			$custom_fonts[ $font_id ] = $font_def;

			$public_def = $font_def;
			unset( $public_def['path'] );
			$registered_results[] = $public_def;
		}

		update_option( 'mr_tee_custom_fonts', $custom_fonts, false );
		self::$fonts_cache     = null;
		self::$svg_data_cache  = array();
		self::$file_stat_cache = array();

		wp_send_json_success(
			array(
				'message' => sprintf( __( '%d police(s) traitée(s) avec succès !', 'mr-tee-editor' ), count( $registered_results ) ),
				'fonts'   => $registered_results,
			)
		);
	}

	/**
	 * Handle admin AJAX deletion of a merchant-uploaded custom font.
	 *
	 * Refuses deletion of built-in bundled fonts, removes the font from `mr_tee_custom_fonts`,
	 * unlinks the uploaded font file and its font-cache directory, invalidates the package index,
	 * and returns the updated font registry.
	 */
	public static function ajax_delete_custom_font() {
		if ( false === check_ajax_referer( 'mr_tee_admin_nonce', 'nonce', false ) ) {
			wp_send_json_error( array( 'message' => __( 'Nonce de sécurité invalide ou expiré.', 'mr-tee-editor' ) ), 403 );
		}

		if ( ! current_user_can( 'edit_products' ) && ! current_user_can( 'manage_woocommerce' ) ) {
			wp_send_json_error( array( 'message' => __( 'Permissions insuffisantes.', 'mr-tee-editor' ) ), 403 );
		}

		$raw_id  = isset( $_POST['font_id'] ) ? wp_unslash( $_POST['font_id'] ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized,WordPress.Security.NonceVerification.Missing
		$font_id = sanitize_key( (string) $raw_id );
		if ( '' === $font_id ) {
			wp_send_json_error( array( 'message' => __( 'Identifiant de police manquant.', 'mr-tee-editor' ) ), 400 );
		}

		$builtin_fonts = self::get_builtin_fonts();
		if ( isset( $builtin_fonts[ $font_id ] ) ) {
			wp_send_json_error( array( 'message' => __( 'Impossible de supprimer une police intégrée au plugin.', 'mr-tee-editor' ) ), 400 );
		}

		$custom_fonts = get_option( 'mr_tee_custom_fonts', array() );
		if ( ! is_array( $custom_fonts ) || ! isset( $custom_fonts[ $font_id ] ) ) {
			wp_send_json_error( array( 'message' => __( 'Police personnalisée introuvable.', 'mr-tee-editor' ) ), 404 );
		}

		$target = $custom_fonts[ $font_id ];

		// Safely unlink custom font binary (and any companion .woff2) only if strictly inside uploads/mr_tee_editor/fonts/.
		$wp_up         = wp_upload_dir();
		$uploads_root  = ! empty( $wp_up['basedir'] ) ? trailingslashit( $wp_up['basedir'] ) . 'mr_tee_editor/' : '';
		$uploads_fonts = $uploads_root ? $uploads_root . 'fonts/' : '';

		if ( ! empty( $target['path'] ) && is_string( $target['path'] ) && file_exists( $target['path'] ) && '' !== $uploads_fonts && is_dir( $uploads_fonts ) ) {
			$real_file = realpath( $target['path'] );
			$real_dir  = realpath( rtrim( $uploads_fonts, '/' ) );
			if ( false !== $real_file && false !== $real_dir ) {
				$norm_file = wp_normalize_path( $real_file );
				$norm_dir  = trailingslashit( wp_normalize_path( $real_dir ) );
				if ( 0 === strpos( $norm_file, $norm_dir ) ) {
					@unlink( $real_file ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
					$woff2_companion = (string) preg_replace( '/\.(?:ttf|otf)$/i', '.woff2', $real_file );
					if ( $woff2_companion !== $real_file && file_exists( $woff2_companion ) ) {
						@unlink( $woff2_companion ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
					}
				}
			}
		}

		// Clean up packaged cache directories, static memo caches, and persistent WP option index.
		SvgFontPackager::delete_package( $font_id );
		if ( '' !== $uploads_fonts && file_exists( $uploads_fonts . 'cache/' . $font_id . '.json' ) ) {
			@unlink( $uploads_fonts . 'cache/' . $font_id . '.json' ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		}

		unset( $custom_fonts[ $font_id ] );
		update_option( 'mr_tee_custom_fonts', $custom_fonts, false );

		self::$fonts_cache     = null;
		self::$svg_data_cache  = array();
		self::$file_stat_cache = array();

		$updated_fonts = array();
		foreach ( self::get_fonts() as $fid => $fmeta ) {
			if ( ! is_array( $fmeta ) ) {
				continue;
			}
			unset( $fmeta['path'], $fmeta['data_url'] );
			if ( ! empty( $fmeta['url'] ) ) {
				$fmeta['url'] = \MrTeeEditor\Domain\SceneGraphSchema::normalize_url_scheme( $fmeta['url'] );
			}
			if ( ! empty( $fmeta['thumb_url'] ) ) {
				$fmeta['thumb_url'] = \MrTeeEditor\Domain\SceneGraphSchema::normalize_url_scheme( $fmeta['thumb_url'] );
			}
			$updated_fonts[ $fid ] = $fmeta;
		}

		wp_send_json_success(
			array(
				'deleted_id' => $font_id,
				'message'    => __( 'Police personnalisée supprimée avec succès.', 'mr-tee-editor' ),
				'fonts'      => $updated_fonts,
			)
		);
	}

	/**
	 * Read a small legacy monolithic JSON font cache only when its file size is within `$max_bytes`.
	 * Prevents storefront memory/TTFB spikes from 9–27 MB legacy bitmap JSON caches.
	 *
	 * @param string $font_path Absolute path to source font binary.
	 * @param string $font_id   Sanitized font identifier.
	 * @param int    $max_bytes Maximum allowed legacy JSON file size in bytes.
	 * @return array<string, mixed>|null
	 */
	private static function read_small_legacy_json( $font_path, $font_id, $max_bytes ) {
		$clean_id          = sanitize_key( (string) $font_id );
		$legacy_candidates = array(
			dirname( (string) $font_path ) . '/cache/' . $clean_id . '.json',
			SvgFontPackager::uploads_cache_base_dir() . $clean_id . '.json',
		);

		foreach ( array_values( array_unique( $legacy_candidates ) ) as $cand ) {
			if ( ! file_exists( $cand ) || ! is_readable( $cand ) ) {
				continue;
			}
			$size = @filesize( $cand ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
			if ( false === $size || $size <= 0 || $size > $max_bytes ) {
				continue;
			}
			$cached = @file_get_contents( $cand ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents, WordPress.PHP.NoSilencedErrors.Discouraged
			if ( false === $cached || '' === $cached ) {
				continue;
			}
			$parsed = json_decode( $cached, true );
			if ( is_array( $parsed ) && ! empty( $parsed['glyphs'] ) ) {
				return $parsed;
			}
		}

		return null;
	}

	/**
	 * AJAX endpoint to return parsed SVG font data by font ID.
	 *
	 * Strictly validates `$font_id` against registered fonts (returns 404 if unknown, no fallback),
	 * restricts `fullMap` to privileged staff (`manage_woocommerce`, `edit_products`, `edit_shop_orders`),
	 * never triggers synchronous `SvgFontPackager::build()` for unauthenticated visitors, and
	 * refuses decoding legacy monolithic `.json` files larger than 1 MB for storefront visitors.
	 */
	public static function ajax_get_svg_font_data() {
		$raw_id  = ! empty( $_GET['font_id'] ) ? wp_unslash( $_GET['font_id'] ) : ( ! empty( $_POST['font_id'] ) ? wp_unslash( $_POST['font_id'] ) : '' ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended,WordPress.Security.NonceVerification.Missing,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		$font_id = sanitize_key( (string) $raw_id );
		if ( '' === $font_id ) {
			wp_send_json_error( array( 'message' => 'Paramètre font_id manquant.' ), 400 );
		}

		$all_fonts = self::get_fonts();
		if ( ! isset( $all_fonts[ $font_id ] ) ) {
			wp_send_json_error( array( 'message' => 'Police introuvable.' ), 404 );
		}

		$font      = $all_fonts[ $font_id ];
		$is_ot_svg = ( ! empty( $font['font_type'] ) && 'opentype_svg' === $font['font_type'] ) || ! empty( $font['color_svg'] );
		if ( ! $is_ot_svg || empty( $font['path'] ) || ! file_exists( $font['path'] ) ) {
			wp_send_json_error( array( 'message' => 'Police non trouvée ou non compatible SVG.' ), 404 );
		}

		$can_admin_export = current_user_can( 'manage_woocommerce' ) || current_user_can( 'edit_products' ) || current_user_can( 'edit_shop_orders' );

		if ( ! $can_admin_export ) {
			// Unauthenticated / customer request: verify manifest freshness non-blockingly (schedules cron if stale;
			// falls back to readable v2.1-v2.3 manifests) and never decodes legacy monolithic .json > 1 MB.
			$data     = null;
			$manifest = SvgFontPackager::get_manifest(
				$font['path'],
				$font_id,
				isset( $font['name'] ) ? $font['name'] : $font_id,
				false
			);
			if ( ! empty( $manifest ) ) {
				$data = SvgFontPackager::hydrate( $manifest, $font_id, false );
			}

			if ( empty( $data ) ) {
				$data = self::read_small_legacy_json( $font['path'], $font_id, 1048576 ); // 1 MB hard cap
			}

			if ( empty( $data ) ) {
				wp_send_json_error( array( 'message' => 'Cache de police non disponible.' ), 404 );
			}

			unset( $data['fullMap'] );
			$data['preview'] = false;
			wp_send_json_success( $data );
		}

		$data = self::get_svg_font_data( $font_id, true, true );
		if ( ! empty( $data ) ) {
			wp_send_json_success( $data );
		} else {
			wp_send_json_error( array( 'message' => 'Police non trouvée ou non compatible SVG.' ), 404 );
		}
	}

	/**
	 * Get parsed OpenType-SVG glyph and metrics dataset for an OpenType-SVG font.
	 *
	 * @param string $font_id          Font identifier.
	 * @param bool   $include_full_map Whether to include 300 DPI `fullMap` URLs (default false on storefront).
	 * @param bool   $allow_build      Whether synchronous package building is permitted when stale/missing.
	 * @return array<string, mixed>|null
	 */
	public static function get_svg_font_data( $font_id, $include_full_map = false, $allow_build = true ) {
		// Preserve 300 DPI fullMap for legacy 1-arg privileged admin export callers (e.g. Plugin::load_svg_fonts_for_ids).
		if ( func_num_args() < 2 ) {
			$effective_full_map = (
				function_exists( 'is_admin' )
				&& is_admin()
				&& function_exists( 'current_user_can' )
				&& ( current_user_can( 'manage_woocommerce' ) || current_user_can( 'edit_products' ) || current_user_can( 'edit_shop_orders' ) )
			);
		} else {
			$effective_full_map = (bool) $include_full_map;
		}
		$allow_build = (bool) $allow_build;

		$cache_key = $font_id . '|' . ( $effective_full_map ? '1' : '0' ) . '|' . ( $allow_build ? '1' : '0' );
		if ( array_key_exists( $cache_key, self::$svg_data_cache ) ) {
			return self::$svg_data_cache[ $cache_key ];
		}

		$font = self::get_font( $font_id );
		if ( empty( $font ) || empty( $font['path'] ) || ! file_exists( $font['path'] ) ) {
			self::$svg_data_cache[ $cache_key ] = null;
			return null;
		}

		$is_known_ot_svg = ( ! empty( $font['font_type'] ) && 'opentype_svg' === $font['font_type'] ) || ! empty( $font['color_svg'] );
		$is_ot_svg       = $is_known_ot_svg || OpenTypeSvgParser::is_opentype_svg( $font['path'] );

		if ( ! $is_ot_svg ) {
			self::$svg_data_cache[ $cache_key ] = null;
			return null;
		}

		$manifest = SvgFontPackager::get_manifest(
			$font['path'],
			$font['id'],
			isset( $font['name'] ) ? $font['name'] : $font['id'],
			$allow_build
		);
		if ( ! empty( $manifest ) ) {
			self::$svg_data_cache[ $cache_key ] = SvgFontPackager::hydrate( $manifest, $font['id'], $effective_full_map );
			return self::$svg_data_cache[ $cache_key ];
		}

		if ( ! $allow_build ) {
			// Storefront non-blocking fallback: NEVER decode legacy monolithic .json files larger than 512 KB.
			$legacy = self::read_small_legacy_json( $font['path'], $font['id'], 524288 ); // 512 KB hard cap
			if ( is_array( $legacy ) && ! $effective_full_map ) {
				unset( $legacy['fullMap'] );
				$legacy['preview'] = false;
			}
			self::$svg_data_cache[ $cache_key ] = $legacy;
			return self::$svg_data_cache[ $cache_key ];
		}

		$legacy = OpenTypeSvgParser::get_or_create_cache( $font['path'], $font['id'], isset( $font['name'] ) ? $font['name'] : $font['id'] );
		if ( is_array( $legacy ) && ! $effective_full_map ) {
			unset( $legacy['fullMap'] );
			$legacy['preview'] = false;
		}
		self::$svg_data_cache[ $cache_key ] = ! empty( $legacy ) ? $legacy : null;
		return self::$svg_data_cache[ $cache_key ];
	}

	/**
	 * Get a single font definition by ID with fallback to first registered font.
	 *
	 * @param string $font_id Font identifier.
	 * @return array<string, mixed>
	 */
	public static function get_font( $font_id ) {
		$fonts = self::get_fonts();
		if ( isset( $fonts[ $font_id ] ) ) {
			return $fonts[ $font_id ];
		}
		// Check aliases
		foreach ( $fonts as $f ) {
			if ( ! empty( $f['aliases'] ) && in_array( $font_id, $f['aliases'], true ) ) {
				return $f;
			}
			if ( ! empty( $f['family'] ) && strcasecmp( $f['family'], $font_id ) === 0 ) {
				return $f;
			}
		}
		// Dynamic fallback to the first registered font rather than hardcoding
		$first_key = array_key_first( $fonts );
		return isset( $fonts[ $first_key ] ) ? $fonts[ $first_key ] : reset( $fonts );
	}

	/**
	 * Determine whether a registered font should receive browser `@font-face` rules.
	 *
	 * Emits `@font-face` ONLY for standard vector TTF/OTF fonts and true COLR/CPAL hybrids
	 * (such as `palestine_1445`). Skips both bitmap OpenType-SVG fonts and pure-vector
	 * OpenType-SVG fonts (`use_your_imagination_blue/green/red`), which render via inline SVG paths.
	 *
	 * @param array<string, mixed> $font Font definition.
	 * @return bool
	 */
	private static function is_font_face_eligible( array $font ) {
		$is_ot_svg = ( ! empty( $font['font_type'] ) && 'opentype_svg' === $font['font_type'] ) || ! empty( $font['color_svg'] );
		if ( ! $is_ot_svg ) {
			return true;
		}
		return ! empty( $font['colr_hybrid'] ) || ( ! empty( $font['id'] ) && 'palestine_1445' === $font['id'] );
	}

	/**
	 * Cached filemtime() lookup for font binaries and `.woff2` companions (0 = missing/unreadable).
	 *
	 * @param string $path Absolute filesystem path.
	 * @return int
	 */
	private static function get_file_mtime_cached( $path ) {
		if ( ! is_string( $path ) || '' === $path ) {
			return 0;
		}
		if ( ! array_key_exists( $path, self::$file_stat_cache ) ) {
			self::$file_stat_cache[ $path ] = file_exists( $path ) ? (int) @filemtime( $path ) : 0; // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		}
		return self::$file_stat_cache[ $path ];
	}

	/**
	 * Append a `?v=<mtime>` (or `&v=<mtime>`) cache-busting parameter to a font URL.
	 *
	 * @param string $url   Font URL.
	 * @param int    $mtime File modification timestamp.
	 * @return string
	 */
	private static function append_version_query( $url, $mtime ) {
		if ( '' === $url || $mtime <= 0 ) {
			return $url;
		}
		if ( false !== strpos( $url, '?v=' ) || false !== strpos( $url, '&v=' ) ) {
			return $url;
		}
		$sep = ( false === strpos( $url, '?' ) ) ? '?' : '&';
		return $url . $sep . 'v=' . $mtime;
	}

	/**
	 * Resolve normalized, versioned `.woff2` (if companion exists on disk) and `.ttf`/`.otf`
	 * asset URLs for a `@font-face`-eligible font definition.
	 *
	 * Shared by `get_font_face_css()` and `get_primary_woff2_or_ttf_url()` so `<link rel="preload">`
	 * and `@font-face` URLs always match byte-for-byte.
	 *
	 * @param array<string, mixed> $font Font definition.
	 * @return array{woff2_url: string, fallback_url: string, format: string, mime: string}|null
	 */
	private static function resolve_font_asset_urls( array $font ) {
		if ( empty( $font['url'] ) || ! is_string( $font['url'] ) ) {
			return null;
		}

		$f_path        = ! empty( $font['path'] ) ? (string) $font['path'] : '';
		$primary_mtime = 0;
		if ( '' !== $f_path ) {
			$primary_mtime = self::get_file_mtime_cached( $f_path );
			// Skip local fonts whose file no longer exists on disk (e.g. deleted custom fonts).
			if ( $primary_mtime <= 0 ) {
				return null;
			}
		}

		if ( null === self::$host_cache ) {
			$site_host        = ( function_exists( 'home_url' ) && function_exists( 'wp_parse_url' ) )
				? strtolower( (string) wp_parse_url( home_url(), PHP_URL_HOST ) )
				: '';
			$http_host        = isset( $_SERVER['HTTP_HOST'] )
				? strtolower( (string) preg_replace( '/:\d+$/', '', sanitize_text_field( wp_unslash( $_SERVER['HTTP_HOST'] ) ) ) )
				: '';
			self::$host_cache = array(
				'site_host' => $site_host,
				'http_host' => $http_host,
			);
		}

		$parsed    = function_exists( 'wp_parse_url' ) ? wp_parse_url( $font['url'] ) : parse_url( $font['url'] ); // phpcs:ignore WordPress.WP.AlternativeFunctions.parse_url_parse_url
		$url_host  = ! empty( $parsed['host'] ) ? strtolower( (string) $parsed['host'] ) : '';
		$site_host = self::$host_cache['site_host'];
		$http_host = self::$host_cache['http_host'];
		$same_host = ( '' === $url_host || $url_host === $site_host || ( '' !== $http_host && $url_host === $http_host ) );

		if ( $same_host && ! empty( $parsed['path'] ) ) {
			$font_url = $parsed['path'] . ( ! empty( $parsed['query'] ) ? '?' . $parsed['query'] : '' );
		} else {
			// Preserve external CDN hostnames untouched.
			$font_url = $font['url'];
		}

		$fallback_url = self::append_version_query( $font_url, $primary_mtime );

		// Detect companion .woff2 next to .ttf/.otf on disk.
		$woff2_url = '';
		if ( '' !== $f_path && preg_match( '/\.(?:ttf|otf)$/i', $f_path ) ) {
			$woff2_path  = (string) preg_replace( '/\.(?:ttf|otf)$/i', '.woff2', $f_path );
			$woff2_mtime = self::get_file_mtime_cached( $woff2_path );
			if ( $woff2_mtime > 0 ) {
				$q_pos     = strpos( $font_url, '?' );
				$base_no_q = ( false !== $q_pos ) ? substr( $font_url, 0, $q_pos ) : $font_url;
				$woff2_raw = (string) preg_replace( '/\.(?:ttf|otf)$/i', '.woff2', $base_no_q );
				if ( $woff2_raw !== $base_no_q ) {
					$woff2_url = self::append_version_query( $woff2_raw, $woff2_mtime );
				}
			}
		}

		$format = ! empty( $font['format'] ) ? (string) $font['format'] : ( preg_match( '/\.otf$/i', $f_path ) ? 'opentype' : 'truetype' );
		$mime   = ( 'opentype' === $format ) ? 'font/otf' : 'font/ttf';

		return array(
			'woff2_url'    => $woff2_url,
			'fallback_url' => $fallback_url,
			'format'       => $format,
			'mime'         => $mime,
		);
	}

	/**
	 * Return the primary browser font URL and MIME type (`font/woff2` when a companion `.woff2`
	 * exists next to the `.ttf`, otherwise `font/ttf` / `font/otf`) for `<link rel="preload" as="font">`
	 * on Slide 0's active standard vector font (or COLR/CPAL hybrid).
	 *
	 * Returns `null` for non-COLR OpenType-SVG fonts or missing fonts.
	 *
	 * @param string $font_id Font identifier, alias, or family name.
	 * @return array{url: string, type: string}|null
	 */
	public static function get_primary_woff2_or_ttf_url( $font_id ) {
		if ( ! is_string( $font_id ) || '' === trim( $font_id ) ) {
			return null;
		}

		$fonts    = self::get_fonts();
		$clean_id = sanitize_key( $font_id );
		$font     = null;

		if ( isset( $fonts[ $font_id ] ) ) {
			$font = $fonts[ $font_id ];
		} elseif ( '' !== $clean_id && isset( $fonts[ $clean_id ] ) ) {
			$font = $fonts[ $clean_id ];
		} else {
			foreach ( $fonts as $f ) {
				if ( ! empty( $f['aliases'] ) && in_array( $font_id, $f['aliases'], true ) ) {
					$font = $f;
					break;
				}
				if ( ! empty( $f['family'] ) && 0 === strcasecmp( $f['family'], $font_id ) ) {
					$font = $f;
					break;
				}
			}
		}

		if ( ! is_array( $font ) || ! self::is_font_face_eligible( $font ) ) {
			return null;
		}

		$urls = self::resolve_font_asset_urls( $font );
		if ( null === $urls ) {
			return null;
		}

		if ( '' !== $urls['woff2_url'] ) {
			return array(
				'url'  => $urls['woff2_url'],
				'type' => 'font/woff2',
			);
		}

		return array(
			'url'  => $urls['fallback_url'],
			'type' => $urls['mime'],
		);
	}

	/**
	 * Build clean inline @font-face CSS declarations for registered standard vector fonts
	 * and true COLR/CPAL hybrids (such as `palestine_1445`), preferring companion `.woff2`
	 * files with `?v=<mtime>` versioning and `.ttf`/`.otf` fallback.
	 *
	 * Skips bitmap OpenType-SVG fonts and pure-vector OpenType-SVG fonts (`use_your_imagination_*`)
	 * because those render via SvgRenderer glyph packages/paths rather than browser `@font-face`.
	 *
	 * @param array<int, string>|null $only_font_ids Optional array of allowed font IDs to filter by.
	 * @return string
	 */
	public static function get_font_face_css( $only_font_ids = null ) {
		$css       = '';
		$all_fonts = self::get_fonts();
		$fonts     = $all_fonts;

		if ( is_array( $only_font_ids ) ) {
			$allowed_map = array_flip( $only_font_ids );
			// Always include 'bungee' (~40 KB WOFF2) as a universal bold vector fallback for missing OpenType-SVG digits/symbols
			$allowed_map['bungee'] = true;
			$filtered              = array();
			foreach ( $all_fonts as $fid => $fdef ) {
				if ( isset( $allowed_map[ $fid ] ) ) {
					$filtered[ $fid ] = $fdef;
				}
			}
			$fonts = $filtered;
		}

		foreach ( $fonts as $font ) {
			if ( ! self::is_font_face_eligible( $font ) ) {
				continue;
			}

			$urls = self::resolve_font_asset_urls( $font );
			if ( null === $urls ) {
				continue;
			}

			if ( '' !== $urls['woff2_url'] ) {
				$src_css = sprintf(
					"url('%s') format('woff2'), url('%s') format('%s')",
					esc_url( $urls['woff2_url'] ),
					esc_url( $urls['fallback_url'] ),
					esc_attr( $urls['format'] )
				);
			} else {
				$src_css = sprintf(
					"url('%s') format('%s')",
					esc_url( $urls['fallback_url'] ),
					esc_attr( $urls['format'] )
				);
			}

			// Primary font-family declaration
			$css .= sprintf(
				"@font-face { font-family: '%s'; src: %s; font-weight: normal; font-style: normal; font-display: swap; }\n",
				esc_attr( $font['family'] ),
				$src_css
			);

			// Family aliases declarations (e.g. 'SuperMario256' for 'Super Mario Wii')
			if ( ! empty( $font['aliases'] ) && is_array( $font['aliases'] ) ) {
				foreach ( $font['aliases'] as $alias ) {
					if ( 0 !== strcasecmp( $alias, $font['family'] ) ) {
						$css .= sprintf(
							"@font-face { font-family: '%s'; src: %s; font-weight: normal; font-style: normal; font-display: swap; }\n",
							esc_attr( $alias ),
							$src_css
						);
					}
				}
			}
		}
		return $css;
	}
}
