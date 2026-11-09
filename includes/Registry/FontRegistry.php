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
	 * Return all registered fancy fonts.
	 *
	 * Every font in this registry is physically bundled inside assets/fonts/,
	 * guaranteeing 100% offline availability, zero CORS issues, and identical
	 * rendering across frontend product pages and high-resolution print exports.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	public static function get_fonts() {
		$fonts = array(
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

		// Merge persistent custom fonts uploaded by merchant
		$custom = get_option( 'mr_tee_custom_fonts', array() );
		if ( ! empty( $custom ) && is_array( $custom ) ) {
			$fonts = array_merge( $fonts, $custom );
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

		return apply_filters( 'mr_tee_registered_fonts', $fonts );
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
	 * Handle admin AJAX font upload (.ttf, .otf, .zip).
	 */
	public static function ajax_upload_font() {
		check_ajax_referer( 'mr_tee_admin_nonce', 'nonce' );

		if ( ! current_user_can( 'manage_woocommerce' ) && ! current_user_can( 'edit_products' ) ) {
			wp_send_json_error( array( 'message' => __( 'Permissions insuffisantes.', 'mr-tee-editor' ) ) );
		}

		if ( empty( $_FILES['font_file'] ) ) {
			wp_send_json_error( array( 'message' => __( 'Aucun fichier fourni.', 'mr-tee-editor' ) ) );
		}

		$file = $_FILES['font_file'];
		$ext  = strtolower( pathinfo( $file['name'], PATHINFO_EXTENSION ) );

		if ( ! in_array( $ext, array( 'ttf', 'otf', 'zip' ), true ) ) {
			wp_send_json_error( array( 'message' => __( 'Format non supporté. Veuillez envoyer un fichier .ttf, .otf ou .zip.', 'mr-tee-editor' ) ) );
		}

		$fonts_dir = MR_TEE_EDITOR_DIR . 'assets/fonts/';
		if ( ! is_dir( $fonts_dir ) ) {
			wp_mkdir_p( $fonts_dir );
		}

		$uploaded_fonts = array();

		if ( 'zip' === $ext ) {
			if ( ! class_exists( 'ZipArchive' ) ) {
				wp_send_json_error( array( 'message' => __( 'Extension ZipArchive manquante sur le serveur.', 'mr-tee-editor' ) ) );
			}
			$zip = new \ZipArchive();
			if ( true === $zip->open( $file['tmp_name'] ) ) {
				for ( $i = 0; $i < $zip->numFiles; $i++ ) {
					$filename = $zip->getNameIndex( $i );
					$f_ext    = strtolower( pathinfo( $filename, PATHINFO_EXTENSION ) );
					if ( in_array( $f_ext, array( 'ttf', 'otf' ), true ) && ! str_contains( $filename, '__MACOSX' ) ) {
						$target_name = sanitize_file_name( basename( $filename ) );
						$target_path = $fonts_dir . $target_name;
						copy( 'zip://' . $file['tmp_name'] . '#' . $filename, $target_path );
						$uploaded_fonts[] = $target_path;
					}
				}
				$zip->close();
			}
		} else {
			$target_name = sanitize_file_name( $file['name'] );
			$target_path = $fonts_dir . $target_name;
			if ( move_uploaded_file( $file['tmp_name'], $target_path ) || copy( $file['tmp_name'], $target_path ) ) {
				$uploaded_fonts[] = $target_path;
			}
		}

		if ( empty( $uploaded_fonts ) ) {
			wp_send_json_error( array( 'message' => __( 'Aucune police TTF/OTF valide trouvée dans l\'archive.', 'mr-tee-editor' ) ) );
		}

		$registered_results = array();
		$custom_fonts       = get_option( 'mr_tee_custom_fonts', array() );

		foreach ( $uploaded_fonts as $font_path ) {
			$filename_clean = pathinfo( $font_path, PATHINFO_FILENAME );
			$font_id        = sanitize_key( str_replace( array( '-', ' ' ), '_', $filename_clean ) );
			$font_name      = ucwords( str_replace( array( '-', '_' ), ' ', $filename_clean ) );
			$is_ot_svg      = OpenTypeSvgParser::is_opentype_svg( $font_path );

			$font_def = array(
				'id'             => $font_id,
				'name'           => $is_ot_svg ? $font_name . ' (OpenType-SVG)' : $font_name,
				'family'         => $font_name,
				'aliases'        => array( $font_id, $font_name ),
				'file'           => basename( $font_path ),
				'format'         => 'truetype',
				'url'            => MR_TEE_EDITOR_URL . 'assets/fonts/' . basename( $font_path ),
				'path'           => $font_path,
				'font_type'      => $is_ot_svg ? 'opentype_svg' : 'truetype',
				'color_svg'      => $is_ot_svg,
				'category'       => $is_ot_svg ? 'color_svg' : 'display',
				'letter_spacing' => 0.05,
				'uppercase'      => true,
			);

			if ( $is_ot_svg ) {
				$manifest = SvgFontPackager::build( $font_path, $font_id, $font_name );
				if ( ! empty( $manifest ) ) {
					$font_def['manifest_url'] = SvgFontPackager::manifest_url( $font_id );
					$font_def['thumb_url']    = SvgFontPackager::thumb_url( $font_id );
					$font_def['glyph_count']  = ! empty( $manifest['glyphs'] ) ? count( (array) $manifest['glyphs'] ) : 0;
				} else {
					$cache_data = OpenTypeSvgParser::get_or_create_cache( $font_path, $font_id, $font_name );
					$font_def['data_url'] = MR_TEE_EDITOR_URL . 'assets/fonts/cache/' . $font_id . '.json';
					$font_def['glyph_count'] = ! empty( $cache_data['glyphs'] ) ? count( $cache_data['glyphs'] ) : 0;
				}
			}

			$custom_fonts[ $font_id ] = $font_def;
			$registered_results[]     = $font_def;
		}

		update_option( 'mr_tee_custom_fonts', $custom_fonts );

		wp_send_json_success(
			array(
				'message' => sprintf( __( '%d police(s) traitée(s) avec succès !', 'mr-tee-editor' ), count( $registered_results ) ),
				'fonts'   => $registered_results,
			)
		);
	}

	/**
	 * AJAX endpoint to return parsed SVG font data by font ID.
	 */
	public static function ajax_get_svg_font_data() {
		$font_id = ! empty( $_GET['font_id'] ) ? sanitize_key( $_GET['font_id'] ) : ( ! empty( $_POST['font_id'] ) ? sanitize_key( $_POST['font_id'] ) : '' );
		if ( empty( $font_id ) ) {
			wp_send_json_error( array( 'message' => 'Paramètre font_id manquant.' ) );
		}

		$data = self::get_svg_font_data( $font_id );
		if ( ! empty( $data ) ) {
			wp_send_json_success( $data );
		} else {
			wp_send_json_error( array( 'message' => 'Police non trouvée ou non compatible SVG.' ) );
		}
	}

	/**
	 * Get parsed OpenType-SVG glyph and metrics dataset for an OpenType-SVG font.
	 *
	 * @param string $font_id Font identifier.
	 * @return array<string, mixed>|null
	 */
	public static function get_svg_font_data( $font_id ) {
		$font = self::get_font( $font_id );
		if ( empty( $font ) || empty( $font['path'] ) || ! file_exists( $font['path'] ) ) {
			return null;
		}

		if ( ! empty( $font['font_type'] ) && 'opentype_svg' === $font['font_type'] ) {
			$manifest = SvgFontPackager::get_manifest( $font['path'], $font['id'], $font['name'] );
			if ( ! empty( $manifest ) ) {
				return SvgFontPackager::hydrate( $manifest, $font['id'] );
			}
			return OpenTypeSvgParser::get_or_create_cache( $font['path'], $font['id'], $font['name'] );
		}

		// Check dynamically if the font file is an OpenType-SVG font
		if ( OpenTypeSvgParser::is_opentype_svg( $font['path'] ) ) {
			$manifest = SvgFontPackager::get_manifest( $font['path'], $font['id'], $font['name'] );
			if ( ! empty( $manifest ) ) {
				return SvgFontPackager::hydrate( $manifest, $font['id'] );
			}
			return OpenTypeSvgParser::get_or_create_cache( $font['path'], $font['id'], $font['name'] );
		}

		return null;
	}

	/**
	 * Get a single font definition by ID with fallback to 'super_mario'.
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
	 * Build clean inline @font-face CSS declarations for all registered fonts.
	 *
	 * Uses root-relative or direct font URLs so fonts load asynchronously, cache in browser,
	 * eliminate mixed-content/CORS blocking, and avoid giant base64 data URIs.
	 * Also registers all family aliases to prevent any font mismatch.
	 *
	 * @param array<int, string>|null $allowed_font_ids Optional array of allowed font IDs to filter by.
	 * @return string
	 */
	public static function get_font_face_css( $allowed_font_ids = null ) {
		$css   = '';
		$fonts = self::get_fonts();

		if ( is_array( $allowed_font_ids ) ) {
			$allowed_map = array_flip( $allowed_font_ids );
			$filtered    = array();
			foreach ( $fonts as $fid => $fdef ) {
				if ( isset( $allowed_map[ $fid ] ) ) {
					$filtered[ $fid ] = $fdef;
				}
			}
			$fonts = $filtered;
		}

		foreach ( $fonts as $font ) {
			if ( ! empty( $font['url'] ) ) {
				$parsed   = wp_parse_url( $font['url'] );
				$font_url = ! empty( $parsed['path'] ) ? $parsed['path'] : esc_url( $font['url'] );

				// Primary font-family declaration
				$css .= sprintf(
					"@font-face { font-family: '%s'; src: url('%s') format('%s'); font-weight: normal; font-style: normal; font-display: swap; }\n",
					esc_attr( $font['family'] ),
					esc_url( $font_url ),
					esc_attr( $font['format'] )
				);

				// Family aliases declarations (e.g. 'SuperMario256' for 'Super Mario Wii')
				if ( ! empty( $font['aliases'] ) && is_array( $font['aliases'] ) ) {
					foreach ( $font['aliases'] as $alias ) {
						if ( $alias !== $font['family'] ) {
							$css .= sprintf(
								"@font-face { font-family: '%s'; src: url('%s') format('%s'); font-weight: normal; font-style: normal; font-display: swap; }\n",
								esc_attr( $alias ),
								esc_url( $font_url ),
								esc_attr( $font['format'] )
							);
						}
					}
				}
			}
		}
		return $css;
	}
}
