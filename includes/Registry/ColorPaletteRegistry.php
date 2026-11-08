<?php
/**
 * Mr. Tee Editor - Extensible Color Palette & COLOR_SET Registry
 *
 * Implements Monsieur TSHIRT's COLOR_SET reference specification
 * (multiple: true/false, variationType: LETTER/WORD/LINE, variationFrequency: 1,
 * outsideOnly stroke) alongside solid ink swatches and garment color backdrops.
 *
 * @package MrTeeEditor\Registry
 */

namespace MrTeeEditor\Registry;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ColorPaletteRegistry {

	/**
	 * Return registered COLOR_SET palettes (matching Monsieur TSHIRT's COLOR_SET schema).
	 *
	 * @return array<string, array<string, mixed>>
	 */
	public static function get_color_sets() {
		$sets = array(
			'super_mario_palette' => array(
				'id'                    => 'super_mario_palette',
				'name'                  => 'Super Mario (Multicolore)',
				'multiple'              => true,
				'colors'                => array( '#209cee', '#ffdd57', '#ff3860', '#23d160' ),
				'stroke'                => '#000000',
				'stroke_width'          => 0.72,
				'outside_only'          => true,
				'colorizeConfiguration' => array(
					'variationType'      => 'LETTER',
					'variationFrequency' => 1,
					'variationReset'     => null,
					'applyTo'            => 'ALL',
				),
			),
			'arcade_gold' => array(
				'id'                    => 'arcade_gold',
				'name'                  => 'Arcade Gold (Multicolore)',
				'multiple'              => true,
				'colors'                => array( '#ffdd57', '#ff3860', '#209cee', '#23d160' ),
				'stroke'                => '#000000',
				'stroke_width'          => 0.65,
				'outside_only'          => true,
				'colorizeConfiguration' => array(
					'variationType'      => 'LETTER',
					'variationFrequency' => 1,
					'variationReset'     => null,
					'applyTo'            => 'ALL',
				),
			),
			'fire_flower' => array(
				'id'                    => 'fire_flower',
				'name'                  => 'Fire Flower (Rouge/Jaune/Orange)',
				'multiple'              => true,
				'colors'                => array( '#e52521', '#fbd000', '#ff7b00', '#ffffff' ),
				'stroke'                => '#000000',
				'stroke_width'          => 0.72,
				'outside_only'          => true,
				'colorizeConfiguration' => array(
					'variationType'      => 'LETTER',
					'variationFrequency' => 1,
					'variationReset'     => null,
					'applyTo'            => 'ALL',
				),
			),
			'classic_white' => array(
				'id'                    => 'classic_white',
				'name'                  => 'Blanc Contour Noir',
				'multiple'              => false,
				'colors'                => array( '#ffffff' ),
				'stroke'                => '#000000',
				'stroke_width'          => 0.72,
				'outside_only'          => true,
				'colorizeConfiguration' => null,
			),
			'retro_black' => array(
				'id'                    => 'retro_black',
				'name'                  => 'Noir Classique',
				'multiple'              => false,
				'colors'                => array( '#111827' ),
				'stroke'                => 'transparent',
				'stroke_width'          => 0,
				'outside_only'          => false,
				'colorizeConfiguration' => null,
			),
			'embroidery_multicolor' => array(
				'id'                    => 'embroidery_multicolor',
				'name'                  => 'Broderie Multicolore (Fil Pastel & Pop)',
				'multiple'              => true,
				'colors'                => array( '#e1523d', '#ebb042', '#6aa6a3', '#395c6b', '#e6c1a6', '#4d4038', '#99b39a', '#2f3e46', '#a8dadc', '#e63946' ),
				'stroke'                => 'transparent',
				'stroke_width'          => 0,
				'outside_only'          => false,
				'colorizeConfiguration' => array(
					'variationType'      => 'LETTER',
					'variationFrequency' => 1,
					'variationReset'     => null,
					'applyTo'            => 'ALL',
				),
			),
			'embroidery_threads' => array(
				'id'                    => 'embroidery_threads',
				'name'                  => 'Fils de Broderie Atelier',
				'multiple'              => false,
				'type'                  => 'SELECTOR',
				'colors'                => array(
					'#ffffff' => 'Blanc',
					'#000000' => 'Noir',
					'#08086d' => 'Bleu marine',
					'#045018' => 'Vert',
					'#762c14' => 'Marron',
					'#c20909' => 'Rouge',
					'#ff9656' => 'Orange',
					'#ffe5d3' => 'Rose pâle',
				),
				'stroke'                => 'transparent',
				'stroke_width'          => 0,
				'outside_only'          => false,
				'colorizeConfiguration' => null,
			),
		);

		return apply_filters( 'mr_tee_registered_color_sets', $sets );
	}

	/**
	 * Return solid single-ink swatches selectable by customers on the frontend.
	 *
	 * @return array<int, array<string, string>>
	 */
	public static function get_solid_swatches() {
		$swatches = array(
			array( 'id' => 'multi',   'name' => 'Multi Mario', 'hex' => 'multi' ),
			array( 'id' => 'black',   'name' => 'Noir',        'hex' => '#111827' ),
			array( 'id' => 'white',   'name' => 'Blanc',       'hex' => '#ffffff' ),
			array( 'id' => 'red',     'name' => 'Rouge',       'hex' => '#e52521' ),
			array( 'id' => 'blue',    'name' => 'Bleu',        'hex' => '#0062ff' ),
			array( 'id' => 'yellow',  'name' => 'Jaune',       'hex' => '#fbd000' ),
			array( 'id' => 'green',   'name' => 'Vert',        'hex' => '#43b047' ),
			array( 'id' => 'pink',    'name' => 'Rose',        'hex' => '#ff4d94' ),
			array( 'id' => 'gold',    'name' => 'Or',          'hex' => '#d97706' ),
		);

		return apply_filters( 'mr_tee_registered_solid_swatches', $swatches );
	}

	/**
	 * Return default garment color presets matching Monsieur TSHIRT Product #24987.
	 *
	 * @return array<int, array<string, string>>
	 */
	public static function get_default_garment_colors() {
		$colors = array(
			array( 'slug' => 'blanc',        'name' => 'Blanc',         'hex' => '#ffffff' ),
			array( 'slug' => 'noir',         'name' => 'Noir',          'hex' => '#18181b' ),
			array( 'slug' => 'bleu-marine',  'name' => 'Bleu marine',   'hex' => '#1c2645' ),
			array( 'slug' => 'gris-chine',   'name' => 'Gris chiné',    'hex' => '#9ca3af' ),
			array( 'slug' => 'bordeaux',     'name' => 'Bordeaux',      'hex' => '#5b1321' ),
			array( 'slug' => 'vert-bouteille','name' => 'Vert bouteille','hex' => '#173c2d' ),
		);

		return apply_filters( 'mr_tee_default_garment_colors', $colors );
	}
}
