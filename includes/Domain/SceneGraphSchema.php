<?php
/**
 * Mr. Tee Editor - Declarative Scene-Graph & Reference Schema
 *
 * Implements the exact Monsieur TSHIRT PersoVisual architecture:
 * - `layers`: Hierarchical scene-graph tree (GROUP, TEXT, IMAGE, RECT) in 0..100 coordinates
 *   including the 2-row mushroom layout (`Ligne 1` [1..3] and `Ligne 2` [4..6]) and relative anchors.
 * - `references`: Typed binding registry (TEXT, COLOR_SET, GROUP_REFS, IMAGE, VISIBILITY).
 * - `perso_visual_cfg`: Dynamic count section mapping (`visibility_ref_id` + `group_ref_id`).
 * - `perso_placement`: Synchronized PersoPlacement transform and print-zone box coordinates.
 *
 * @package MrTeeEditor\Domain
 */

namespace MrTeeEditor\Domain;

use MrTeeEditor\Registry\FontRegistry;
use MrTeeEditor\Registry\ColorPaletteRegistry;
use MrTeeEditor\Registry\IconSetRegistry;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class SceneGraphSchema {

	/**
	 * Return the default product configuration structure.
	 *
	/**
	 * Return the default product configuration structure for a given preset.
	 *
	 * @param string $preset_id One of 'super_mushroom_24987', 'multicolor_embroidered_34225', 'leopard_pattern_mask_24000', 'vintage_date_names_34359'.
	 * @return array<string, mixed>
	 */
	public static function get_default_config( $preset_id = 'super_mushroom_24987' ) {
		$icons = IconSetRegistry::get_mushroom_icons();

		switch ( $preset_id ) {
			case 'multicolor_embroidered_34225':
				return array(
					'engine'              => 'mr_tee_editor',
					'template_preset'     => 'multicolor_embroidered_34225',
					'nbd_handshake'       => true,
					'print_Effect3D'      => true,
					'dpi'                 => 300,
					'print_width_px'      => 2400,
					'print_height_px'     => 2400,
					'box_left'            => 31.0,
					'box_top'             => 32.0,
					'box_width'           => 38.0,
					'box_height'          => 36.0,
					'perso_scale'         => 0.42,
					'perso_translate_x'   => 0.0,
					'perso_translate_y'   => 0.0,
					'perso_rotate_degree' => 0.0,
					'garment_colors'      => ColorPaletteRegistry::get_default_garment_colors(),
					'text_lines'          => array(
						array(
							'id'               => 'line_1',
							'ref_id'           => 'id-ref-bebas-brode-1',
							'color_ref_id'     => 'id-ref-color-bebas-1',
							'label'            => 'Texte brodé (jusqu\'à 4 lignes)',
							'default_text'     => "PAPA\nDE\nJULES\n& LÉO",
							'multiline'        => true,
							'max_lines'        => 4,
							'line_height'      => 0.88,
							'max_chars'        => 40,
							'font'             => 'bebas_brode',
							'color_mode'       => 'multi',
							'color_set_id'     => 'embroidery_multicolor',
							'color_hex'        => '#ffffff',
							'stroke_hex'       => 'transparent',
							'y_pct'            => 50.0,
							'font_size'        => 19.0,
							'max_width'        => 88.0,
							'casing'           => 'UPPER',
							'allow_font_pick'  => true,
							'allow_color_pick' => false,
							'warning_unedited' => false,
						),
					),
					'repeater'            => array(
						'enabled'       => false,
						'label'         => 'Nombre d\'éléments',
						'default_count' => 0,
						'max_items'     => 0,
					),
					'icons'               => $icons,
				);

			case 'leopard_pattern_mask_24000':
				return array(
					'engine'              => 'mr_tee_editor',
					'template_preset'     => 'leopard_pattern_mask_24000',
					'nbd_handshake'       => true,
					'print_Effect3D'      => true,
					'dpi'                 => 300,
					'print_width_px'      => 2400,
					'print_height_px'     => 3000,
					'box_left'            => 28.0,
					'box_top'             => 28.0,
					'box_width'           => 44.0,
					'box_height'          => 48.0,
					'perso_scale'         => 0.44,
					'perso_translate_x'   => 0.0,
					'perso_translate_y'   => 0.0,
					'perso_rotate_degree' => 0.0,
					'garment_colors'      => ColorPaletteRegistry::get_default_garment_colors(),
					'text_lines'          => array(
						array(
							'id'               => 'line_1',
							'ref_id'           => 'id-ref-mask-word-1',
							'color_ref_id'     => 'id-ref-color-mask-1',
							'label'            => 'Texte motif léopard (ex: MAMAN, MAMIE)',
							'default_text'     => 'MAMAN',
							'max_chars'        => 12,
							'font'             => 'gunterz',
							'color_mode'       => 'solid',
							'color_hex'        => '#ffffff',
							'stroke_hex'       => 'transparent',
							'is_masked'        => true,
							'texture_url'      => MR_TEE_EDITOR_URL . 'assets/images/leopard-pattern.png',
							'y_pct'            => 38.0,
							'font_size'        => 24.0,
							'max_width'        => 86.0,
							'casing'           => 'UPPER',
							'allow_font_pick'  => false,
							'allow_color_pick' => false,
							'warning_unedited' => false,
						),
						array(
							'id'               => 'line_2',
							'ref_id'           => 'id-ref-script-names-2',
							'color_ref_id'     => 'id-ref-color-script-2',
							'label'            => 'Prénoms des enfants',
							'default_text'     => 'Léo, Chloé, Arthur',
							'max_chars'        => 40,
							'font'             => 'villagers',
							'color_mode'       => 'solid',
							'color_hex'        => '#934a32',
							'stroke_hex'       => 'transparent',
							'y_pct'            => 64.0,
							'font_size'        => 13.0,
							'max_width'        => 86.0,
							'casing'           => 'ORIGINAL',
							'allow_font_pick'  => true,
							'allow_color_pick' => true,
							'warning_unedited' => true,
						),
					),
					'repeater'            => array(
						'enabled'       => false,
						'label'         => 'Nombre d\'éléments',
						'default_count' => 0,
						'max_items'     => 0,
					),
					'icons'               => $icons,
				);

			case 'vintage_date_names_34359':
				return array(
					'engine'              => 'mr_tee_editor',
					'template_preset'     => 'vintage_date_names_34359',
					'nbd_handshake'       => true,
					'print_Effect3D'      => true,
					'dpi'                 => 300,
					'print_width_px'      => 2400,
					'print_height_px'     => 2400,
					'box_left'            => 30.0,
					'box_top'             => 26.0,
					'box_width'           => 40.0,
					'box_height'          => 48.0,
					'perso_scale'         => 0.40,
					'perso_translate_x'   => 0.0,
					'perso_translate_y'   => 0.0,
					'perso_rotate_degree' => 0.0,
					'garment_colors'      => ColorPaletteRegistry::get_default_garment_colors(),
					'vector_clipart'      => array(
						'name'         => 'Coeur',
						'path'         => 'M254.9,93.2c0,3.1,0,6.1,0,9.2c-0.3,0.9-0.1,1.9-0.2,2.8c-0.4,2.9-1.2,5.8-1.9,8.6c-1.2,4.6-2.8,9.1-4.4,13.6c-2.5,6.7-5.5,13.1-8.8,19.4c-3.1,6-6.7,11.7-10.4,17.3c-3,4.4-6.1,8.7-9.3,12.9c-3.3,4.2-6.7,8.3-10.3,12.3c-3.3,3.8-6.8,7.4-10.3,11c-3,3.2-6.2,6.2-9.4,9.2c-3.1,3-6.3,5.9-9.6,8.8c-4.2,3.8-8.5,7.4-12.9,11c-3.6,3-7.2,5.9-10.9,8.8c-3.1,2.4-6.2,4.9-9.4,7.2c-0.6,0.5-1.2,0.4-1.9,0c-2.9-1.7-5.7-3.6-8.6-5.4c-3.8-2.4-7.5-4.9-11.2-7.4c-2.5-1.7-5-3.5-7.5-5.3c-3.4-2.4-6.8-4.9-10.1-7.4c-5.9-4.6-11.7-9.3-17.3-14.2c-5-4.4-10-9-14.7-13.8c-5.4-5.4-10.5-11.1-15.3-17.1c-4.1-5.1-8.1-10.4-11.7-16c-5-7.7-9.3-15.7-12.8-24.2c-2.1-5.2-3.9-10.5-5.2-16c-0.8-3.2-1.3-6.5-1.8-9.7c-0.8-5-0.8-10-0.7-15c0.1-4.2,0.6-8.3,1.5-12.4c1.2-5.4,3-10.5,5.5-15.4c1.8-3.5,4-6.7,6.4-9.8c2.7-3.4,5.8-6.3,9.2-8.9c4.3-3.2,9.1-5.7,14.2-7.4c4.4-1.4,8.8-2.1,13.4-2.2c2.9,0,5.8,0,8.6,0.4c2.7,0.4,5.3,1.1,7.9,1.9c6.5,2,12.4,5.2,17.9,9.3c3.7,2.7,7.1,5.8,10.3,9.2c3.6,3.8,6.8,7.8,9.7,12.1c2.9,4.2,5.5,8.7,7.8,13.2c1,2,2,4.1,3,6.1c0.3,0.7,0.5,0.5,0.9,0.1c3-3.9,6.1-7.7,9.4-11.3c2.7-3.1,5.6-6.1,8.5-9c3.1-3.1,6.3-6.1,9.6-9c5.7-4.8,12-8.4,19.2-10.5c5.2-1.4,10.5-2,15.8-1.7c2.1,0.1,4.1,0.4,6.2,0.8c2.6,0.5,5.2,1.1,7.6,2c4.9,1.8,9.6,4.1,13.8,7.3c2.5,1.9,5,3.9,7.1,6.3c3.5,3.8,6.4,7.9,8.6,12.5c1.5,3.2,2.7,6.5,3.5,10c0.4,1.7,0.7,3.5,0.7,5.3C254.8,92.8,254.8,93,254.9,93.2z',
						'color_hex'    => '#000000',
						'color_ref_id' => 'id-ref-color-shared-1',
						'transform'    => 'translate(50, 52) scale(0.021) translate(-141.577, -141.669)',
					),
					'text_lines'          => array(
						array(
							'id'               => 'line_1',
							'ref_id'           => 'id-ref-vintage-title-1',
							'color_ref_id'     => 'id-ref-color-shared-1',
							'label'            => 'Titre (Calligraphie)',
							'default_text'     => 'Maman',
							'max_chars'        => 16,
							'font'             => 'adede',
							'color_mode'       => 'solid',
							'color_hex'        => '#000000',
							'stroke_hex'       => 'transparent',
							'y_pct'            => 26.0,
							'font_size'        => 24.0,
							'max_width'        => 80.0,
							'casing'           => 'ORIGINAL',
							'allow_font_pick'  => false,
							'allow_color_pick' => true,
							'color_swatches'   => array(
								'#ffffff' => 'Blanc',
								'#000000' => 'Noir',
								'#08086d' => 'Bleu marine',
								'#045018' => 'Vert',
								'#762c14' => 'Marron',
								'#c20909' => 'Rouge',
								'#ff9656' => 'Orange',
								'#ffe5d3' => 'Rose pâle',
							),
							'warning_unedited' => false,
						),
						array(
							'id'               => 'line_2',
							'ref_id'           => 'id-ref-vintage-date-2',
							'color_ref_id'     => 'id-ref-color-shared-1',
							'label'            => 'Sous-titre (Date)',
							'default_text'     => 'depuis 2021',
							'max_chars'        => 20,
							'font'             => 'hanley_brode',
							'color_mode'       => 'solid',
							'color_hex'        => '#000000',
							'stroke_hex'       => 'transparent',
							'y_pct'            => 42.0,
							'font_size'        => 9.0,
							'max_width'        => 70.0,
							'casing'           => 'UPPER',
							'allow_font_pick'  => false,
							'allow_color_pick' => false,
							'warning_unedited' => false,
						),
						array(
							'id'               => 'line_3',
							'ref_id'           => 'id-ref-vintage-names-3',
							'color_ref_id'     => 'id-ref-color-shared-1',
							'label'            => 'Prénoms des enfants (1 par ligne)',
							'default_text'     => "Oscar\nAnna\nGaby",
							'max_chars'        => 50,
							'max_lines'        => 4,
							'multiline'        => true,
							'line_height'      => 0.88,
							'font'             => 'villagers',
							'color_mode'       => 'solid',
							'color_hex'        => '#000000',
							'stroke_hex'       => 'transparent',
							'y_pct'            => 68.0,
							'font_size'        => 13.0,
							'max_width'        => 75.0,
							'casing'           => 'ORIGINAL',
							'allow_font_pick'  => false,
							'allow_color_pick' => false,
							'warning_unedited' => true,
						),
					),
					'repeater'            => array(
						'enabled'       => false,
						'label'         => 'Nombre d\'éléments',
						'default_count' => 0,
						'max_items'     => 0,
					),
					'icons'               => $icons,
				);

			case 'super_mushroom_24987':
			default:
				return array(
					'engine'              => 'none', // 'none' | 'nbdesigner' | 'mr_tee_editor'
					'template_preset'     => 'super_mushroom_24987',
					'nbd_handshake'       => true,
					'print_Effect3D'      => true,
					'dpi'                 => 300,
					'print_width_px'      => 2400,
					'print_height_px'     => 3000,
					// Visual Editor Area placement (% of garment image container)
					'box_left'            => 26.0,
					'box_top'             => 23.0,
					'box_width'           => 48.0,
					'box_height'          => 54.0,
					// Monsieur TSHIRT PersoPlacement parameters
					'perso_scale'         => 0.34,
					'perso_translate_x'   => 1.54,
					'perso_translate_y'   => -0.49,
					'perso_rotate_degree' => 0.0,
					// Garment color swatches
					'garment_colors'      => ColorPaletteRegistry::get_default_garment_colors(),
					// Primary Text Lines ("Super" + "Prénom")
					'text_lines'          => array(
						array(
							'id'               => 'line_1',
							'ref_id'           => 'id-ref-super-line-1',
							'color_ref_id'     => 'id-ref-color-line-1',
							'label'            => 'Texte 1',
							'default_text'     => 'Super',
							'max_chars'        => 15,
							'font'             => 'super_mario',
							'color_mode'       => 'multi',
							'color_set_id'     => 'super_mario_palette',
							'color_hex'        => '#ffffff',
							'stroke_hex'       => '#000000',
							'y_pct'            => 13.5,
							'font_size'        => 15.5,
							'max_width'        => 84.0,
							'allow_font_pick'  => true,
							'allow_color_pick' => true,
							'warning_unedited' => false,
						),
						array(
							'id'               => 'line_2',
							'ref_id'           => 'id-ref-prenom-line-2',
							'color_ref_id'     => 'id-ref-color-line-2',
							'label'            => 'Texte 2 (Prénom)',
							'default_text'     => 'Papa',
							'max_chars'        => 15,
							'font'             => 'super_mario',
							'color_mode'       => 'multi',
							'color_set_id'     => 'super_mario_palette',
							'color_hex'        => '#ffffff',
							'stroke_hex'       => '#000000',
							'y_pct'            => 29.5,
							'font_size'        => 15.5,
							'max_width'        => 84.0,
							'allow_font_pick'  => true,
							'allow_color_pick' => true,
							'warning_unedited' => true,
						),
					),
					// Repeater Group ("Nombre de champignons" - 2-Row Layout Ligne 1 [1..3] + Ligne 2 [4..6])
					'repeater'            => array(
						'enabled'       => true,
						'section_id'    => 5832,
						'label'         => 'Nombre de champignons',
						'subtitle'      => '(Modifiable à tout moment)',
						'item_label'    => 'Champignon',
						'min_items'     => 0,
						'max_items'     => 6,
						'default_count' => 2,
						'max_chars'     => 12,
						'items_per_row' => 3,
						'row1_y_pct'    => 50.2,
						'row1_text_y'   => 62.263,
						'row2_y_pct'    => 73.2,
						'row2_text_y'   => 85.476,
						'icon_width'    => 21.0,
						'icon_height'   => 19.0,
						'name_size'     => 3.5,
						'name_max_w'    => 17.0,
						'col_x_centers' => array( 30.0, 50.0, 70.0 ),
						'defaults'      => array(
							array( 'name' => 'Léo',   'icon' => 'mushroom_red' ),
							array( 'name' => 'Emma',  'icon' => 'mushroom_green' ),
							array( 'name' => 'Lucas', 'icon' => 'mushroom_blue' ),
							array( 'name' => 'Chloé', 'icon' => 'mushroom_yellow' ),
							array( 'name' => 'Hugo',  'icon' => 'mushroom_purple' ),
							array( 'name' => 'Lina',  'icon' => 'mushroom_pink' ),
						),
					),
					'icons'               => $icons,
				);
		}
	}

	/**
	 * Retrieve normalized configuration for a given WooCommerce product ID,
	 * including its compiled Monsieur TSHIRT scene-graph (`perso_visual`).
	 *
	 * @param int $product_id WooCommerce Product ID.
	 * @return array<string, mixed>
	 */
	public static function get_product_config( $product_id ) {
		$saved  = get_post_meta( $product_id, '_mr_tee_config', true );
		$engine = get_post_meta( $product_id, '_mr_tee_engine_mode', true );

		if ( ! is_array( $saved ) ) {
			$saved = array();
		}

		$preset   = ! empty( $saved['template_preset'] ) ? $saved['template_preset'] : 'super_mushroom_24987';
		$defaults = self::get_default_config( $preset );

		$config = wp_parse_args( $saved, $defaults );

		if ( empty( $engine ) ) {
			$engine = get_post_meta( $product_id, '_mr_tee_editor_mode', true );
		}
		if ( empty( $engine ) && ! empty( $saved['editor_mode'] ) ) {
			$engine = $saved['editor_mode'];
		}
		$config['engine']        = ! empty( $engine ) ? $engine : ( isset( $defaults['engine'] ) ? $defaults['engine'] : 'none' );
		$config['nbd_handshake'] = isset( $saved['nbd_handshake'] ) ? (bool) $saved['nbd_handshake'] : true;

		// Preset-aware text lines parsing
		if ( empty( $config['text_lines'] ) || ! is_array( $config['text_lines'] ) ) {
			$config['text_lines'] = $defaults['text_lines'];
		} else {
			$normalized_lines = array();
			foreach ( $defaults['text_lines'] as $idx => $def_line ) {
				if ( isset( $config['text_lines'][ $idx ] ) ) {
					$normalized_lines[ $idx ] = wp_parse_args( $config['text_lines'][ $idx ], $def_line );
				} else {
					$normalized_lines[ $idx ] = $def_line;
				}
			}
			$config['text_lines'] = $normalized_lines;
		}

		// Clipart layer passthrough
		if ( isset( $defaults['vector_clipart'] ) && ! isset( $config['vector_clipart'] ) ) {
			$config['vector_clipart'] = $defaults['vector_clipart'];
		}

		if ( empty( $config['repeater'] ) || ! is_array( $config['repeater'] ) ) {
			$config['repeater'] = $defaults['repeater'];
		} else {
			$config['repeater'] = wp_parse_args( $config['repeater'], $defaults['repeater'] );
			if ( empty( $config['repeater']['defaults'] ) || ! is_array( $config['repeater']['defaults'] ) ) {
				$config['repeater']['defaults'] = isset( $defaults['repeater']['defaults'] ) ? $defaults['repeater']['defaults'] : array();
			}
		}

		if ( empty( $config['garment_colors'] ) || ! is_array( $config['garment_colors'] ) ) {
			$config['garment_colors'] = $defaults['garment_colors'];
		}

		$config['icons']        = IconSetRegistry::get_mushroom_icons();
		$config['color_sets']   = ColorPaletteRegistry::get_color_sets();
		$config['solid_colors'] = ColorPaletteRegistry::get_solid_swatches();
		$config['perso_visual'] = self::compile_scene_graph( $config );

		return $config;
	}

	/**
	 * Return catalog of pre-configured design presets reverse-engineered from Monsieur TSHIRT.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	public static function get_presets() {
		return array(
			'super_mushroom_24987' => array(
				'id'          => 'super_mushroom_24987',
				'name'        => 'Super Mario & Champignons (T-Shirt 2 Rangées d\'Avatars)',
				'description' => 'Aperçu 2 rangées d\'avatars, polices fancy, auto-zoom et centrage dynamique',
			),
			'multicolor_embroidered_34225' => array(
				'id'          => 'multicolor_embroidered_34225',
				'name'        => 'Broderie Multicolore Casquette (Papa / Mum de prénoms)',
				'description' => 'Texte empilé brodé avec cycle de couleurs de fil pastel & pop (Bebas Neue brodé)',
			),
			'leopard_pattern_mask_24000' => array(
				'id'          => 'leopard_pattern_mask_24000',
				'name'        => 'Motif Léopard Découpé & Prénoms Cursifs (Sac XXL / Tote bag)',
				'description' => 'Masque SVG découpant un motif léopard dans le texte + prénoms cursifs dessous',
			),
			'vintage_date_names_34359' => array(
				'id'          => 'vintage_date_names_34359',
				'name'        => 'Vintage Maman Depuis Date & Liste d\'Enfants (Casquette & Mug)',
				'description' => 'Titre script calligraphie + année vintage + coeur vectoriel + prénoms et sélecteur de fil',
			),
		);
	}

	/**
	 * Compile a product configuration into the exact Monsieur TSHIRT `PersoVisual`
	 * scene-graph (`layers`, `references`, `perso_visual_cfg`, `area`).
	 *
	 * Matches Product #24987, #34225, #24000, #34835, #34359, #34455 with:
	 * - Multi-line and Single-line declarative text layers
	 * - SVG Texture Masking (`type: MASK`, Leopard pattern)
	 * - Vector Clipart (`type: PATH`, e.g. Heart divider)
	 * - `Ligne 1` & `Ligne 2` 2-row avatar layout when repeater is enabled
	 * - `GROUP_REFS`, `VISIBILITY`, `TEXT`, `COLOR_SET`, and `IMAGE` references
	 *
	 * @param array<string, mixed> $config Normalized product configuration.
	 * @return array<string, mixed>
	 */
	public static function compile_scene_graph( array $config ) {
		$fonts      = FontRegistry::get_fonts();
		$color_sets = ColorPaletteRegistry::get_color_sets();
		$icons      = IconSetRegistry::get_mushroom_icons();
		$rep        = isset( $config['repeater'] ) ? $config['repeater'] : array( 'enabled' => false );
		$def_count  = isset( $rep['default_count'] ) ? intval( $rep['default_count'] ) : 2;
		$max_items  = isset( $rep['max_items'] ) ? intval( $rep['max_items'] ) : 6;

		$layers     = array();
		$references = array();

		// 1. Build Primary Text Line Layers & References
		$created_color_refs = array();
		foreach ( $config['text_lines'] as $idx => $line ) {
			$line_num     = $idx + 1;
			$text_ref_id  = ! empty( $line['ref_id'] ) ? $line['ref_id'] : 'id-ref-text-line-' . $line_num;
			$color_ref_id = ! empty( $line['color_ref_id'] ) ? $line['color_ref_id'] : 'id-ref-color-line-' . $line_num;
			$font_id      = isset( $line['font'] ) ? $line['font'] : 'super_mario';
			$font_def     = isset( $fonts[ $font_id ] ) ? $fonts[ $font_id ] : $fonts['super_mario'];
			$color_mode   = isset( $line['color_mode'] ) ? $line['color_mode'] : 'multi';

			$references[] = array(
				'id'               => $text_ref_id,
				'type'             => 'TEXT',
				'name'             => $line['label'],
				'value'            => $line['default_text'],
				'editable'         => true,
				'multiLine'        => ! empty( $line['multiline'] ),
				'maxLines'         => ! empty( $line['max_lines'] ) ? intval( $line['max_lines'] ) : 1,
				'lineHeight'       => isset( $line['line_height'] ) ? floatval( $line['line_height'] ) : 0.95,
				'maxCharsPerLine'  => intval( $line['max_chars'] ),
				'validCharacters'  => "^[a-zA-Z0-9À-ÿ\\s'&.!\\-\n]*$",
				'fontId'           => $font_id,
				'fontFamily'       => $font_def['family'],
				'allowFontPick'    => ! empty( $line['allow_font_pick'] ),
				'allowColorPick'   => ! empty( $line['allow_color_pick'] ),
				'colorRefId'       => $color_ref_id,
				'customProperties' => array(
					array( 'key' => 'edited',  'value' => false ),
					array( 'key' => 'warning', 'value' => ! empty( $line['warning_unedited'] ) ),
				),
			);

			if ( ! in_array( $color_ref_id, $created_color_refs, true ) ) {
				$references[] = array(
					'id'           => $color_ref_id,
					'type'         => 'COLOR_SET',
					'name'         => 'Couleur ' . $line['label'],
					'editable'     => ! empty( $line['allow_color_pick'] ),
					'colorMode'    => $color_mode,
					'colorHex'     => isset( $line['color_hex'] ) ? $line['color_hex'] : '#ffffff',
					'strokeHex'    => isset( $line['stroke_hex'] ) ? $line['stroke_hex'] : '#000000',
					'palette'      => $font_def['palette'],
					'outsideOnly'  => true,
					'strokeWidth'  => $font_def['stroke_width'],
					'variationType'=> 'LETTER',
				);
				$created_color_refs[] = $color_ref_id;
			}

			$text_layer = array(
				'type'               => 'TEXT',
				'name'               => $line['label'],
				'reference'          => $text_ref_id,
				'colorReference'     => $color_ref_id,
				'x'                  => 50.0,
				'y'                  => floatval( $line['y_pct'] ),
				'fontSize'           => floatval( $line['font_size'] ),
				'maxWidth'           => isset( $line['max_width'] ) ? floatval( $line['max_width'] ) : 84.0,
				'textAnchor'         => 'middle',
				'textAnchorVertical' => 'middle',
				'multiLine'          => ! empty( $line['multiline'] ),
				'lineHeight'         => isset( $line['line_height'] ) ? floatval( $line['line_height'] ) : 0.95,
				'casing'             => isset( $line['casing'] ) ? $line['casing'] : ( ! empty( $font_def['uppercase'] ) ? 'UPPER' : 'ORIGINAL' ),
				'letterSpacing'      => isset( $font_def['letter_spacing'] ) ? floatval( $font_def['letter_spacing'] ) : 0.1,
				'stroke'             => array(
					'width'       => floatval( $font_def['stroke_width'] ),
					'outsideOnly' => true,
				),
			);

			if ( ! empty( $line['is_masked'] ) ) {
				$layers[] = array(
					'type'       => 'MASK',
					'name'       => 'Masque ' . $line['label'],
					'textureUrl' => ! empty( $line['texture_url'] ) ? $line['texture_url'] : MR_TEE_EDITOR_URL . 'assets/images/leopard-pattern.png',
					'x'          => 15.0,
					'y'          => floatval( $line['y_pct'] ) - 15.0,
					'width'      => 70.0,
					'height'     => 30.0,
					'items'      => array( $text_layer ),
				);
			} else {
				$layers[] = array(
					'type'  => 'GROUP',
					'name'  => $line['label'],
					'items' => array( $text_layer ),
				);
			}
		}

		// 1b. Build Vector Clipart Layer (e.g. Heart divider in #34455)
		if ( ! empty( $config['vector_clipart'] ) && is_array( $config['vector_clipart'] ) ) {
			$clip = $config['vector_clipart'];
			$layers[] = array(
				'type'           => 'PATH',
				'name'           => ! empty( $clip['name'] ) ? $clip['name'] : 'Clipart',
				'path'           => $clip['path'],
				'fill'           => array( 'color' => array( 'hex' => ! empty( $clip['color_hex'] ) ? $clip['color_hex'] : '#000000' ) ),
				'transform'      => isset( $clip['transform'] ) ? $clip['transform'] : '',
				'colorReference' => isset( $clip['color_ref_id'] ) ? $clip['color_ref_id'] : null,
				'y'              => isset( $clip['y_pct'] ) ? floatval( $clip['y_pct'] ) : 50.0,
			);
		}

		// 2. Build 2-Row Mushroom Repeater Layers (`Ligne 1` [1..3] and `Ligne 2` [4..6])
		$cfg_items = array();
		if ( ! empty( $rep['enabled'] ) ) {
			$vis_row1_id = 'id-ref-vis-ligne-1';
			$vis_row2_id = 'id-ref-vis-ligne-2';

			$references[] = array(
				'id'       => $vis_row1_id,
				'type'     => 'VISIBILITY',
				'name'     => 'Visibilité Ligne 1',
				'editable' => false,
				'visible'  => $def_count >= 1,
			);
			$references[] = array(
				'id'       => $vis_row2_id,
				'type'     => 'VISIBILITY',
				'name'     => 'Visibilité Ligne 2',
				'editable' => false,
				'visible'  => $def_count >= 4,
			);

			$row1_items = array();
			$row2_items = array();
			$col_xs     = ! empty( $rep['col_x_centers'] ) ? $rep['col_x_centers'] : array( 30.0, 50.0, 70.0 );

			for ( $i = 0; $i < $max_items; $i++ ) {
				$item_num   = $i + 1;
				$row_index  = ( $i < 3 ) ? 1 : 2;
				$col_index  = $i % 3;
				$center_x   = isset( $col_xs[ $col_index ] ) ? floatval( $col_xs[ $col_index ] ) : ( 30.0 + $col_index * 20.0 );
				$icon_y     = ( 1 === $row_index ) ? floatval( $rep['row1_y_pct'] ) : floatval( $rep['row2_y_pct'] );
				$text_y     = ( 1 === $row_index ) ? floatval( $rep['row1_text_y'] ) : floatval( $rep['row2_text_y'] );
				$icon_w     = floatval( $rep['icon_width'] );
				$icon_h     = floatval( $rep['icon_height'] );
				$name_size  = floatval( $rep['name_size'] );
				$name_max_w = floatval( $rep['name_max_w'] );

				$def_item   = isset( $rep['defaults'][ $i ] ) ? $rep['defaults'][ $i ] : array( 'name' => 'Prénom ' . $item_num, 'icon' => 'mushroom_red' );
				$icon_key   = isset( $def_item['icon'] ) && isset( $icons[ $def_item['icon'] ] ) ? $def_item['icon'] : 'mushroom_red';
				$is_active  = ( $item_num <= $def_count );

				$vis_ref_id   = 'id-ref-vis-champ-' . $item_num;
				$group_ref_id = 'id-ref-group-champ-' . $item_num;
				$text_ref_id  = 'id-ref-text-champ-' . $item_num;
				$img_ref_id   = 'id-ref-img-champ-' . $item_num;

				$references[] = array(
					'id'       => $vis_ref_id,
					'type'     => 'VISIBILITY',
					'name'     => 'Visibilité ' . $rep['item_label'] . ' ' . $item_num,
					'editable' => false,
					'visible'  => $is_active,
				);

				$references[] = array(
					'id'       => $group_ref_id,
					'type'     => 'GROUP_REFS',
					'name'     => $rep['item_label'] . ' ' . $item_num,
					'editable' => $is_active,
					'items'    => array(
						array(
							'id'               => $text_ref_id,
							'type'             => 'TEXT',
							'name'             => 'Prénom ' . $item_num,
							'value'            => $def_item['name'],
							'editable'         => true,
							'multiLine'        => false,
							'maxLines'         => 1,
							'maxCharsPerLine'  => intval( $rep['max_chars'] ),
							'validCharacters'  => "^[a-zA-Z0-9À-ÿ\\s'&.!\\-]*$",
							'customProperties' => array(
								array( 'key' => 'edited',  'value' => false ),
								array( 'key' => 'warning', 'value' => true ),
							),
						),
						array(
							'id'               => $img_ref_id,
							'type'             => 'IMAGE',
							'name'             => 'Visuel',
							'iconKey'          => $icon_key,
							'url'              => $icons[ $icon_key ]['url'],
							'editable'         => true,
							'customProperties' => array(
								array( 'key' => 'id_perso_img_set', 'value' => 693 ),
								array( 'key' => 'edited',           'value' => false ),
								array( 'key' => 'warning',          'value' => false ),
							),
						),
					),
				);

				$cfg_items[] = array(
					'index'             => $item_num,
					'visibility_ref_id' => $vis_ref_id,
					'group_ref_id'      => $group_ref_id,
				);

				$champ_group = array(
					'type'       => 'GROUP',
					'name'       => $rep['item_label'] . ' ' . $item_num,
					'slotIndex'  => $i,
					'rowIndex'   => $row_index,
					'colIndex'   => $col_index,
					'visibility' => array( 'reference' => $vis_ref_id ),
					'items'      => array(
						array(
							'type'               => 'TEXT',
							'name'               => 'Prénom ' . $item_num,
							'reference'          => $text_ref_id,
							'x'                  => $center_x,
							'y'                  => $text_y,
							'fontSize'           => $name_size,
							'maxWidth'           => $name_max_w,
							'fontFamily'         => 'BebasNeue, Arial Black, sans-serif',
							'textAnchor'         => 'middle',
							'textAnchorVertical' => 'middle',
							'fill'               => '#111827',
						),
						array(
							'type'      => 'IMAGE',
							'name'      => 'Visuel ' . $item_num,
							'reference' => $img_ref_id,
							'x'         => $center_x - ( $icon_w / 2.0 ),
							'y'         => $icon_y - ( $icon_h / 2.0 ),
							'centerX'   => $center_x,
							'centerY'   => $icon_y,
							'width'     => $icon_w,
							'height'    => $icon_h,
						),
					),
				);

				if ( 1 === $row_index ) {
					$row1_items[] = $champ_group;
				} else {
					$row2_items[] = $champ_group;
				}
			}

			$layers[] = array(
				'type'          => 'GROUP',
				'name'          => 'Ligne 1',
				'rowNumber'     => 1,
				'itemAnchorsId' => 'ancre-ligne-1',
				'visibility'    => array( 'reference' => $vis_row1_id ),
				'items'         => $row1_items,
			);

			$layers[] = array(
				'type'          => 'GROUP',
				'name'          => 'Ligne 2',
				'rowNumber'     => 2,
				'itemAnchorsId' => 'ancre-ligne-2',
				'visibility'    => array( 'reference' => $vis_row2_id ),
				'items'         => $row2_items,
			);
		}

		return array(
			'id_perso_visual'  => 5779,
			'layers'           => $layers,
			'references'       => $references,
			'perso_visual_cfg' => array(
				'title'    => 'Personnaliser',
				'sections' => array(
					array(
						'id_perso_visual_cfg_section' => intval( $rep['section_id'] ),
						'title'                       => $rep['label'],
						'subtitle'                    => $rep['subtitle'],
						'min'                         => intval( $rep['min_items'] ),
						'max'                         => $max_items,
						'default_count'               => $def_count,
						'row1_vis_ref_id'             => 'id-ref-vis-ligne-1',
						'row2_vis_ref_id'             => 'id-ref-vis-ligne-2',
						'items'                       => $cfg_items,
					),
				),
			),
			'area'             => array(
				'width'        => intval( $config['print_width_px'] ),
				'height'       => intval( $config['print_height_px'] ),
				'higherAnchor' => 'top',
				'widerAnchor'  => 'middle',
				'transform'    => array(
					'scale'  => floatval( $config['perso_scale'] ),
					'x'      => floatval( $config['perso_translate_x'] ),
					'y'      => floatval( $config['perso_translate_y'] ),
					'rotate' => floatval( $config['perso_rotate_degree'] ),
				),
			),
		);
	}
}
