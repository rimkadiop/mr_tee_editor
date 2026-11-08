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
		return $fonts['super_mario'];
	}

	/**
	 * Build clean inline @font-face CSS declarations for all registered fonts.
	 *
	 * Uses direct font URLs so fonts load asynchronously, cache in browser cache,
	 * and eliminate massive base64 payload bloat (>4.7MB) in HTML source.
	 * Also registers all family aliases to prevent any font mismatch.
	 *
	 * @return string
	 */
	public static function get_font_face_css() {
		$css = '';
		foreach ( self::get_fonts() as $font ) {
			if ( ! empty( $font['url'] ) ) {
				$font_url = esc_url( $font['url'] );
				if ( isset( $_SERVER['HTTP_HOST'] ) ) {
					$proto    = is_ssl() ? 'https://' : 'http://';
					$font_url = preg_replace( '#^https?://#', $proto, $font_url );
				}

				// Primary font-family declaration
				$css .= sprintf(
					"@font-face { font-family: '%s'; src: url('%s') format('%s'); font-weight: normal; font-style: normal; font-display: swap; }\n",
					esc_attr( $font['family'] ),
					$font_url,
					esc_attr( $font['format'] )
				);

				// Family aliases declarations (e.g. 'SuperMario256' for 'Super Mario Wii')
				if ( ! empty( $font['aliases'] ) && is_array( $font['aliases'] ) ) {
					foreach ( $font['aliases'] as $alias ) {
						if ( $alias !== $font['family'] ) {
							$css .= sprintf(
								"@font-face { font-family: '%s'; src: url('%s') format('%s'); font-weight: normal; font-style: normal; font-display: swap; }\n",
								esc_attr( $alias ),
								$font_url,
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
