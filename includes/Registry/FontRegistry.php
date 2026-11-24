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

		// Enrich OpenType-SVG fonts with lightweight package URLs (manifest & thumbnail)
		foreach ( $fonts as $f_id => &$f_data ) {
			if ( ! empty( $f_data['font_type'] ) && 'opentype_svg' === $f_data['font_type'] ) {
				$manifest_url = SvgFontPackager::manifest_url( $f_id );
				if ( $manifest_url ) {
					$f_data['manifest_url'] = $manifest_url;
				}
				$thumb_url = SvgFontPackager::thumb_url( $f_id );
				if ( $thumb_url ) {
					$f_data['thumb_url'] = $thumb_url;
				}
			}
		}
		unset( $f_data );

		self::$fonts_cache = $fonts;

		return apply_filters( 'mr_tee_registered_fonts', self::$fonts_cache );
	}

	/**
	 * Register WordPress AJAX endpoints for font management.
	 */
	public static function register_hooks() {
		add_action( 'wp_ajax_mr_tee_upload_font', array( __CLASS__, 'ajax_upload_font' ) );
		add_action( 'wp_ajax_mr_tee_get_svg_font_data', array( __CLASS__, 'ajax_get_svg_font_data' ) );
		add_action( 'wp_ajax_nopriv_mr_tee_get_svg_font_data', array( __CLASS__, 'ajax_get_svg_font_data' ) );
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
		check_ajax_referer( 'mr_tee_admin_nonce', 'nonce' );

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
				$manifest = SvgFontPackager::build( $font_path, $font_id, $font_name );
				if ( ! empty( $manifest ) ) {
					$font_def['manifest_url'] = SvgFontPackager::manifest_url( $font_id );
					$font_def['thumb_url']    = SvgFontPackager::thumb_url( $font_id );
					$font_def['glyph_count']  = ! empty( $manifest['glyphs'] ) ? count( (array) $manifest['glyphs'] ) : 0;
				} else {
					$cache_data              = OpenTypeSvgParser::get_or_create_cache( $font_path, $font_id, $font_name );
					$font_def['data_url']    = SvgFontPackager::uploads_cache_base_url() . $font_id . '.json';
					$font_def['glyph_count'] = ! empty( $cache_data['glyphs'] ) ? count( $cache_data['glyphs'] ) : 0;
				}
			}

			$custom_fonts[ $font_id ] = $font_def;

			$public_def = $font_def;
			unset( $public_def['path'] );
			$registered_results[] = $public_def;
		}

		update_option( 'mr_tee_custom_fonts', $custom_fonts );
		self::$fonts_cache    = null;
		self::$svg_data_cache = array();

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
	 * unlinks the uploaded font file and its font-cache directory, and returns the updated font registry.
	 */
	public static function ajax_delete_custom_font() {
		check_ajax_referer( 'mr_tee_admin_nonce', 'nonce' );

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

		// Safely unlink custom font binary only if strictly inside uploads/mr_tee_editor/fonts/.
		$wp_up          = wp_upload_dir();
		$uploads_root   = ! empty( $wp_up['basedir'] ) ? trailingslashit( $wp_up['basedir'] ) . 'mr_tee_editor/' : '';
		$uploads_fonts  = $uploads_root ? $uploads_root . 'fonts/' : '';
		$uploads_fcache = SvgFontPackager::uploads_cache_base_dir();

		if ( ! empty( $target['path'] ) && is_string( $target['path'] ) && file_exists( $target['path'] ) && '' !== $uploads_fonts && is_dir( $uploads_fonts ) ) {
			$real_file = realpath( $target['path'] );
			$real_dir  = realpath( rtrim( $uploads_fonts, '/' ) );
			if ( false !== $real_file && false !== $real_dir ) {
				$norm_file = wp_normalize_path( $real_file );
				$norm_dir  = trailingslashit( wp_normalize_path( $real_dir ) );
				if ( 0 === strpos( $norm_file, $norm_dir ) ) {
					@unlink( $real_file ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
				}
			}
		}

		// Clean up uploads font-cache directory and legacy JSON caches for this custom font.
		if ( is_dir( $uploads_fcache ) ) {
			self::recursive_rmdir_inside( $uploads_fcache . $font_id, $uploads_fcache );
			$legacy_json = $uploads_fcache . $font_id . '.json';
			if ( file_exists( $legacy_json ) ) {
				@unlink( $legacy_json ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
			}
		}
		if ( '' !== $uploads_fonts && file_exists( $uploads_fonts . 'cache/' . $font_id . '.json' ) ) {
			@unlink( $uploads_fonts . 'cache/' . $font_id . '.json' ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		}
		// Also clean any non-builtin cache folder created inside plugin cache dir if present.
		$plugin_cache_base = SvgFontPackager::get_plugin_dir() . 'assets/fonts/cache/';
		if ( is_dir( $plugin_cache_base . $font_id ) ) {
			self::recursive_rmdir_inside( $plugin_cache_base . $font_id, $plugin_cache_base );
		}

		unset( $custom_fonts[ $font_id ] );
		update_option( 'mr_tee_custom_fonts', $custom_fonts );

		self::$fonts_cache    = null;
		self::$svg_data_cache = array();

		$updated_fonts = array();
		foreach ( self::get_fonts() as $fid => $fmeta ) {
			if ( ! is_array( $fmeta ) ) {
				continue;
			}
			unset( $fmeta['path'], $fmeta['data_url'] );
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
	 * AJAX endpoint to return parsed SVG font data by font ID.
	 *
	 * Strictly validates `$font_id` against registered fonts (returns 404 if unknown, no fallback),
	 * restricts `fullMap` to privileged staff (`manage_woocommerce`, `edit_products`, `edit_shop_orders`),
	 * and never triggers synchronous `SvgFontPackager::build()` for unauthenticated visitors.
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
			// Unauthenticated / customer request: serve ONLY pre-cached package data; never invoke synchronous build().
			$data          = null;
			$manifest_file = SvgFontPackager::manifest_path( $font_id );
			if ( file_exists( $manifest_file ) ) {
				$json     = @file_get_contents( $manifest_file ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents, WordPress.PHP.NoSilencedErrors.Discouraged
				$manifest = json_decode( (string) $json, true );
				if ( is_array( $manifest ) && ! empty( $manifest['glyphs'] ) ) {
					$data = SvgFontPackager::hydrate( $manifest, $font_id );
				}
			}

			if ( empty( $data ) ) {
				$legacy_candidates = array(
					dirname( $font['path'] ) . '/cache/' . $font_id . '.json',
					SvgFontPackager::uploads_cache_base_dir() . $font_id . '.json',
				);
				foreach ( $legacy_candidates as $cand ) {
					if ( file_exists( $cand ) ) {
						$cached = @file_get_contents( $cand ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents, WordPress.PHP.NoSilencedErrors.Discouraged
						$parsed = json_decode( (string) $cached, true );
						if ( is_array( $parsed ) && ! empty( $parsed['glyphs'] ) ) {
							$data = $parsed;
							break;
						}
					}
				}
			}

			if ( empty( $data ) ) {
				wp_send_json_error( array( 'message' => 'Cache de police non disponible.' ), 404 );
			}

			unset( $data['fullMap'] );
			$data['preview'] = false;
			wp_send_json_success( $data );
		}

		$data = self::get_svg_font_data( $font_id );
		if ( ! empty( $data ) ) {
			wp_send_json_success( $data );
		} else {
			wp_send_json_error( array( 'message' => 'Police non trouvée ou non compatible SVG.' ), 404 );
		}
	}

	/**
	 * Get parsed OpenType-SVG glyph and metrics dataset for an OpenType-SVG font.
	 *
	 * @param string $font_id Font identifier.
	 * @return array<string, mixed>|null
	 */
	public static function get_svg_font_data( $font_id ) {
		if ( array_key_exists( $font_id, self::$svg_data_cache ) ) {
			return self::$svg_data_cache[ $font_id ];
		}

		$font = self::get_font( $font_id );
		if ( empty( $font ) || empty( $font['path'] ) || ! file_exists( $font['path'] ) ) {
			self::$svg_data_cache[ $font_id ] = null;
			return null;
		}

		if ( ! empty( $font['font_type'] ) && 'opentype_svg' === $font['font_type'] ) {
			$manifest = SvgFontPackager::get_manifest( $font['path'], $font['id'], $font['name'] );
			if ( ! empty( $manifest ) ) {
				self::$svg_data_cache[ $font_id ] = SvgFontPackager::hydrate( $manifest, $font['id'] );
				return self::$svg_data_cache[ $font_id ];
			}
			self::$svg_data_cache[ $font_id ] = OpenTypeSvgParser::get_or_create_cache( $font['path'], $font['id'], $font['name'] );
			return self::$svg_data_cache[ $font_id ];
		}

		// Check dynamically if the font file is an OpenType-SVG font
		if ( OpenTypeSvgParser::is_opentype_svg( $font['path'] ) ) {
			$manifest = SvgFontPackager::get_manifest( $font['path'], $font['id'], $font['name'] );
			if ( ! empty( $manifest ) ) {
				self::$svg_data_cache[ $font_id ] = SvgFontPackager::hydrate( $manifest, $font['id'] );
				return self::$svg_data_cache[ $font_id ];
			}
			self::$svg_data_cache[ $font_id ] = OpenTypeSvgParser::get_or_create_cache( $font['path'], $font['id'], $font['name'] );
			return self::$svg_data_cache[ $font_id ];
		}

		self::$svg_data_cache[ $font_id ] = null;
		return null;
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
	 * Build clean inline @font-face CSS declarations for registered standard vector fonts.
	 *
	 * Skips large bitmap OpenType-SVG color fonts (> 768 KB) because those are rendered via
	 * SvgRenderer WebP/PNG glyph packages and browsers ignore SVG color tables in standard
	 * HTML buttons/spans—preventing 9 MB to 26+ MB of wasted raw .otf binary downloads on page load.
	 *
	 * @param array<int, string>|null $allowed_font_ids Optional array of allowed font IDs to filter by.
	 * @return string
	 */
	public static function get_font_face_css( $allowed_font_ids = null ) {
		$css       = '';
		$all_fonts = self::get_fonts();
		$fonts     = $all_fonts;

		if ( is_array( $allowed_font_ids ) ) {
			$allowed_map = array_flip( $allowed_font_ids );
			// Always include 'bungee' (72 KB) as a universal bold vector fallback for missing OpenType-SVG digits/symbols
			$allowed_map['bungee'] = true;
			$filtered              = array();
			foreach ( $all_fonts as $fid => $fdef ) {
				if ( isset( $allowed_map[ $fid ] ) ) {
					$filtered[ $fid ] = $fdef;
				}
			}
			$fonts = $filtered;
		}

		$site_host = strtolower( (string) wp_parse_url( home_url(), PHP_URL_HOST ) );
		$http_host = isset( $_SERVER['HTTP_HOST'] )
			? strtolower( (string) preg_replace( '/:\d+$/', '', sanitize_text_field( wp_unslash( $_SERVER['HTTP_HOST'] ) ) ) )
			: '';

		foreach ( $fonts as $font ) {
			$f_path = ! empty( $font['path'] ) ? (string) $font['path'] : '';

			// Skip local fonts whose file no longer exists on disk (e.g. deleted custom fonts).
			if ( '' !== $f_path && ! file_exists( $f_path ) ) {
				continue;
			}

			$is_ot_svg = ( ! empty( $font['font_type'] ) && 'opentype_svg' === $font['font_type'] ) || ! empty( $font['color_svg'] );
			if ( $is_ot_svg ) {
				// Skip multi-megabyte bitmap OpenType-SVG fonts (> 768 KB), but emit @font-face for lightweight
				// hybrid COLR/CPAL/TrueType fonts (such as palestine_1445 at ~557 KB) so their vector outline glyphs render natively.
				if ( '' === $f_path || filesize( $f_path ) > 786432 ) {
					continue;
				}
			}

			if ( ! empty( $font['url'] ) ) {
				$parsed    = wp_parse_url( $font['url'] );
				$url_host  = ! empty( $parsed['host'] ) ? strtolower( (string) $parsed['host'] ) : '';
				$same_host = ( '' === $url_host || $url_host === $site_host || ( '' !== $http_host && $url_host === $http_host ) );

				if ( $same_host && ! empty( $parsed['path'] ) ) {
					$font_url = $parsed['path'] . ( ! empty( $parsed['query'] ) ? '?' . $parsed['query'] : '' );
				} else {
					// Preserve external CDN hostnames untouched.
					$font_url = $font['url'];
				}

				$format = ! empty( $font['format'] ) ? $font['format'] : 'truetype';

				// Primary font-family declaration
				$css .= sprintf(
					"@font-face { font-family: '%s'; src: url('%s') format('%s'); font-weight: normal; font-style: normal; font-display: swap; }\n",
					esc_attr( $font['family'] ),
					esc_url( $font_url ),
					esc_attr( $format )
				);

				// Family aliases declarations (e.g. 'SuperMario256' for 'Super Mario Wii')
				if ( ! empty( $font['aliases'] ) && is_array( $font['aliases'] ) ) {
					foreach ( $font['aliases'] as $alias ) {
						if ( 0 !== strcasecmp( $alias, $font['family'] ) ) {
							$css .= sprintf(
								"@font-face { font-family: '%s'; src: url('%s') format('%s'); font-weight: normal; font-style: normal; font-display: swap; }\n",
								esc_attr( $alias ),
								esc_url( $font_url ),
								esc_attr( $format )
							);
						}
					}
				}
			}
		}
		return $css;
	}
}
