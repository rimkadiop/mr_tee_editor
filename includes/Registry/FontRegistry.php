<?php
/**
 * Mr. Tee Editor - Extensible Font Registry
 *
 * Manages fancy display fonts, multi-color COLOR_SET font pairings,
 * and @font-face CSS generation with WordPress filter hooks for scalability.
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
	 * Third-party extensions can register custom WOFF2/TTF/SVG fonts via
	 * the `mr_tee_registered_fonts` filter without modifying core code.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	public static function get_fonts() {
		$fonts = array(
			'super_mario' => array(
				'id'                => 'super_mario',
				'name'              => 'Super Mario (Jeu Vidéo)',
				'family'            => 'SuperMario256',
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
			),
			'pricedown' => array(
				'id'                => 'pricedown',
				'name'              => 'Grand Theft / Pricedown',
				'family'            => 'PricedownBl',
				'file'              => 'pricedown_bl.ttf',
				'format'            => 'truetype',
				'url'               => MR_TEE_EDITOR_URL . 'assets/fonts/pricedown_bl.ttf',
				'path'              => MR_TEE_EDITOR_DIR . 'assets/fonts/pricedown_bl.ttf',
				'multi_color'       => false,
				'default_color_set' => 'classic_white',
				'palette'           => array( '#ffffff' ),
				'stroke'            => '#000000',
				'stroke_width'      => 0.78,
				'outside_only'      => true,
				'letter_spacing'    => 0.08,
				'uppercase'         => true,
			),
			'abril' => array(
				'id'                => 'abril',
				'name'              => 'Abril Fatface (Rétro Chic)',
				'family'            => 'AbrilFatface',
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
			),
			'cinzel' => array(
				'id'                => 'cinzel',
				'name'              => 'Cinzel Decorative (Prestige)',
				'family'            => 'CinzelDecorative',
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
				'letter_spacing'    => 0.1,
				'uppercase'         => true,
			),
			'pacifico' => array(
				'id'                => 'pacifico',
				'name'              => 'Pacifico (Script Cursif)',
				'family'            => 'Pacifico',
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
			),
			'marker' => array(
				'id'                => 'marker',
				'name'              => 'Permanent Marker (Street)',
				'family'            => 'PermanentMarker',
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
				'letter_spacing'    => 0.06,
				'uppercase'         => true,
			),
			'bebas' => array(
				'id'                => 'bebas',
				'name'              => 'Bebas Neue (Impact)',
				'family'            => 'BebasNeue',
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
				'letter_spacing'    => 0.12,
				'uppercase'         => true,
			),
			'press_start' => array(
				'id'                => 'press_start',
				'name'              => 'Arcade 8-Bit (Pixel)',
				'family'            => 'PressStart2P',
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
			),
			'bebas_brode' => array(
				'id'                => 'bebas_brode',
				'name'              => 'Bebas Neue Brodé (Casquette)',
				'family'            => 'BebasNeue',
				'file'              => 'BebasNeue-Regular.ttf',
				'format'            => 'truetype',
				'url'               => MR_TEE_EDITOR_URL . 'assets/fonts/BebasNeue-Regular.ttf',
				'path'              => MR_TEE_EDITOR_DIR . 'assets/fonts/BebasNeue-Regular.ttf',
				'multi_color'       => true,
				'default_color_set' => 'embroidery_multicolor',
				'palette'           => array( '#e1523d', '#ebb042', '#6aa6a3', '#395c6b', '#e6c1a6', '#4d4038', '#99b39a', '#2f3e46', '#a8dadc', '#e63946' ),
				'stroke'            => 'transparent',
				'stroke_width'      => 0,
				'outside_only'      => false,
				'letter_spacing'    => 0.1,
				'uppercase'         => true,
			),
			'adede' => array(
				'id'                => 'adede',
				'name'              => 'Adédé (Script Calligraphie Maman)',
				'family'            => 'Pacifico',
				'file'              => 'Pacifico-Regular.ttf',
				'format'            => 'truetype',
				'url'               => MR_TEE_EDITOR_URL . 'assets/fonts/Pacifico-Regular.ttf',
				'path'              => MR_TEE_EDITOR_DIR . 'assets/fonts/Pacifico-Regular.ttf',
				'multi_color'       => false,
				'default_color_set' => 'embroidery_threads',
				'palette'           => array( '#000000' ),
				'stroke'            => 'transparent',
				'stroke_width'      => 0,
				'outside_only'      => false,
				'letter_spacing'    => 0.02,
				'uppercase'         => false,
			),
			'hanley_brode' => array(
				'id'                => 'hanley_brode',
				'name'              => 'Hanley Brodé (Vintage Année)',
				'family'            => 'CinzelDecorative',
				'file'              => 'CinzelDecorative-Bold.ttf',
				'format'            => 'truetype',
				'url'               => MR_TEE_EDITOR_URL . 'assets/fonts/CinzelDecorative-Bold.ttf',
				'path'              => MR_TEE_EDITOR_DIR . 'assets/fonts/CinzelDecorative-Bold.ttf',
				'multi_color'       => false,
				'default_color_set' => 'embroidery_threads',
				'palette'           => array( '#000000' ),
				'stroke'            => 'transparent',
				'stroke_width'      => 0,
				'outside_only'      => false,
				'letter_spacing'    => 0.08,
				'uppercase'         => true,
			),
			'villagers' => array(
				'id'                => 'villagers',
				'name'              => 'Villagers (Prénoms Enfants)',
				'family'            => 'AbrilFatface',
				'file'              => 'AbrilFatface-Regular.ttf',
				'format'            => 'truetype',
				'url'               => MR_TEE_EDITOR_URL . 'assets/fonts/AbrilFatface-Regular.ttf',
				'path'              => MR_TEE_EDITOR_DIR . 'assets/fonts/AbrilFatface-Regular.ttf',
				'multi_color'       => false,
				'default_color_set' => 'embroidery_threads',
				'palette'           => array( '#762c14' ),
				'stroke'            => 'transparent',
				'stroke_width'      => 0,
				'outside_only'      => false,
				'letter_spacing'    => 0.03,
				'uppercase'         => false,
			),
			'gunterz' => array(
				'id'                => 'gunterz',
				'name'              => 'Gunterz (Motif Léopard Bloc)',
				'family'            => 'PricedownBl',
				'file'              => 'pricedown_bl.ttf',
				'format'            => 'truetype',
				'url'               => MR_TEE_EDITOR_URL . 'assets/fonts/pricedown_bl.ttf',
				'path'              => MR_TEE_EDITOR_DIR . 'assets/fonts/pricedown_bl.ttf',
				'multi_color'       => false,
				'default_color_set' => 'retro_black',
				'palette'           => array( '#000000' ),
				'stroke'            => 'transparent',
				'stroke_width'      => 0,
				'outside_only'      => false,
				'letter_spacing'    => 0.05,
				'uppercase'         => true,
			),
		);

		return apply_filters( 'mr_tee_registered_fonts', $fonts );
	}

	/**
	 * Get a single font definition by ID with fallback to 'super_mario'.
	 *
	 * @param string $font_id Font identifier.
	 * @return array<string, mixed>
	 */
	public static function get_font( $font_id ) {
		$fonts = self::get_fonts();
		return isset( $fonts[ $font_id ] ) ? $fonts[ $font_id ] : $fonts['super_mario'];
	}

	/**
	 * Build inline @font-face CSS declarations for all registered fonts.
	 *
	 * @return string
	 */
	public static function get_font_face_css() {
		$css = '';
		foreach ( self::get_fonts() as $font ) {
			if ( ! empty( $font['path'] ) && file_exists( $font['path'] ) ) {
				// Embed base64 data URI to guarantee instant loading with zero network latency, no CORS, and no SSL issues
				$font_data = file_get_contents( $font['path'] );
				$base64    = base64_encode( $font_data );
				$mime_type = 'font/' . ( 'truetype' === $font['format'] ? 'ttf' : $font['format'] );
				$data_uri  = 'data:' . $mime_type . ';charset=utf-8;base64,' . $base64;

				$font_url = esc_url( $font['url'] );
				if ( isset( $_SERVER['HTTP_HOST'] ) ) {
					$proto    = is_ssl() ? 'https://' : 'http://';
					$font_url = preg_replace( '#^https?://#', $proto, $font_url );
				}

				$css .= sprintf(
					"@font-face { font-family: '%s'; src: url('%s') format('%s'), url('%s') format('%s'); font-weight: normal; font-style: normal; font-display: block; }\n",
					esc_attr( $font['family'] ),
					$data_uri,
					esc_attr( $font['format'] ),
					$font_url,
					esc_attr( $font['format'] )
				);
			}
		}
		return $css;
	}
}
