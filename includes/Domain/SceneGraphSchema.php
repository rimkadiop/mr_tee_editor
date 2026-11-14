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
					'print_unit'          => 'cm',
					'print_real_width'    => 20.0,
					'print_real_height'   => 20.0,
					'print_aspect_ratio'  => 1.0,
					'product_type'        => 'garment',
					'lock_aspect_ratio'   => false,
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
							'box_left'         => 31.0,
							'box_top'          => 32.0,
							'box_width'        => 38.0,
							'box_height'       => 36.0,
							'multiline'        => true,
							'max_lines'        => 4,
							'line_height'      => 0.88,
							'max_chars'        => 40,
							'font'             => 'bebas',
							'allowed_fonts'    => array( 'bebas', 'cocogoose', 'bungee', 'marker' ),
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
					'print_unit'          => 'cm',
					'print_real_width'    => 30.0,
					'print_real_height'   => 38.0,
					'print_aspect_ratio'  => 0.7895,
					'product_type'        => 'garment',
					'lock_aspect_ratio'   => false,
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
							'box_left'         => 28.0,
							'box_top'          => 28.0,
							'box_width'        => 44.0,
							'box_height'       => 18.0,
							'max_chars'        => 12,
							'font'             => 'cocogoose',
							'allowed_fonts'    => array( 'cocogoose', 'bebas', 'bungee' ),
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
							'box_left'         => 28.0,
							'box_top'          => 48.0,
							'box_width'        => 44.0,
							'box_height'       => 10.0,
							'max_chars'        => 40,
							'font'             => 'pacifico',
							'allowed_fonts'    => array( 'pacifico', 'abril', 'marker' ),
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
					'print_unit'          => 'cm',
					'print_real_width'    => 20.0,
					'print_real_height'   => 20.0,
					'print_aspect_ratio'  => 1.0,
					'product_type'        => 'garment',
					'lock_aspect_ratio'   => false,
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
							'box_left'         => 30.0,
							'box_top'          => 26.0,
							'box_width'        => 40.0,
							'box_height'       => 12.0,
							'max_chars'        => 16,
							'font'             => 'pacifico',
							'allowed_fonts'    => array( 'pacifico', 'abril' ),
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
							'box_left'         => 35.0,
							'box_top'          => 39.0,
							'box_width'        => 30.0,
							'box_height'       => 6.0,
							'max_chars'        => 20,
							'font'             => 'cinzel',
							'allowed_fonts'    => array( 'cinzel', 'bebas' ),
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
							'box_left'         => 32.0,
							'box_top'          => 48.0,
							'box_width'        => 36.0,
							'box_height'       => 24.0,
							'max_chars'        => 50,
							'max_lines'        => 4,
							'multiline'        => true,
							'line_height'      => 0.88,
							'font'             => 'abril',
							'allowed_fonts'    => array( 'abril', 'pacifico', 'marker' ),
							'color_mode'       => 'solid',
							'color_hex'        => '#000000',
							'stroke_hex'       => 'transparent',
							'y_pct'            => 68.0,
							'font_size'        => 13.0,
							'max_width'        => 75.0,
							'casing'           => 'ORIGINAL',
							'allow_font_pick'  => true,
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
					'print_unit'          => 'cm',
					'print_real_width'    => 30.0,
					'print_real_height'   => 40.0,
					'print_aspect_ratio'  => 0.75,
					'product_type'        => 'garment',
					'lock_aspect_ratio'   => false,
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
							'box_left'         => 30.0,
							'box_top'          => 25.0,
							'box_width'        => 40.0,
							'box_height'       => 8.5,
							'max_chars'        => 15,
							'font'             => 'super_mario',
							'allowed_fonts'    => array( 'super_mario', 'press_start', 'bungee', 'luckiest_guy', 'cocogoose', 'bebas' ),
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
							'box_left'         => 30.0,
							'box_top'          => 34.5,
							'box_width'        => 40.0,
							'box_height'       => 8.5,
							'max_chars'        => 15,
							'font'             => 'super_mario',
							'allowed_fonts'    => array( 'super_mario', 'press_start', 'bungee', 'luckiest_guy', 'cocogoose', 'bebas' ),
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
						'box_left'      => 28.0,
						'box_top'       => 45.0,
						'box_width'     => 44.0,
						'box_height'    => 28.0,
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

		// Normalize allowed_fonts at product level (ordered array of chosen fonts)
		if ( isset( $saved['allowed_fonts'] ) && is_array( $saved['allowed_fonts'] ) ) {
			$config['allowed_fonts'] = array_values( array_filter( array_map( 'sanitize_key', $saved['allowed_fonts'] ) ) );
		} elseif ( isset( $config['allowed_fonts'] ) && is_array( $config['allowed_fonts'] ) ) {
			$config['allowed_fonts'] = array_values( array_filter( array_map( 'sanitize_key', $config['allowed_fonts'] ) ) );
		} else {
			$config['allowed_fonts'] = null;
		}

		// Dynamic fields parsing supporting arbitrary number of custom text & image fields
		if ( empty( $config['text_lines'] ) || ! is_array( $config['text_lines'] ) ) {
			$config['text_lines'] = $defaults['text_lines'];
		} else {
			$normalized_lines = array();
			$line_index = 0;
			foreach ( $config['text_lines'] as $idx => $line_data ) {
				$line_num   = $line_index + 1;
				$field_type = ( isset( $line_data['field_type'] ) && 'image' === $line_data['field_type'] ) ? 'image' : 'text';

				if ( 'image' === $field_type ) {
					$def_line = array(
						'id'                 => 'custom_line_' . $line_num,
						'ref_id'             => 'id-ref-image-line-' . $line_num,
						'color_ref_id'       => 'id-ref-color-line-' . $line_num,
						'field_type'         => 'image',
						'label'              => sprintf( __( 'Image %d', 'mr-tee-editor' ), $line_num ),
						'default_text'       => '',
						'default_image'      => '',
						'default_image_id'   => 0,
						'image_fit'          => 'contain',
						'allow_image_upload' => true,
						'box_left'           => 30.0,
						'box_top'            => 25.0 + ( $line_index * 12.0 ),
						'box_width'          => 40.0,
						'box_height'         => 30.0,
						'warning_unedited'   => false,
					);
				} else {
					$def_line = isset( $defaults['text_lines'][ $line_index ] ) ? $defaults['text_lines'][ $line_index ] : array(
						'id'               => 'custom_line_' . $line_num,
						'ref_id'           => 'id-ref-text-line-' . $line_num,
						'color_ref_id'     => 'id-ref-color-line-' . $line_num,
						'field_type'       => 'text',
						'label'            => sprintf( __( 'Champ %d', 'mr-tee-editor' ), $line_num ),
						'default_text'     => sprintf( __( 'Texte %d', 'mr-tee-editor' ), $line_num ),
						'box_left'         => 25.0,
						'box_top'          => 20.0 + ( $line_index * 12.0 ),
						'box_width'        => 50.0,
						'box_height'       => 10.0,
						'max_chars'        => 30,
						'font'             => 'bebas',
						'allowed_fonts'    => array_keys( FontRegistry::get_fonts() ),
						'allow_font_pick'  => true,
						'allow_color_pick' => true,
						'color_mode'       => 'solid',
						'color_hex'        => '#111827',
					);
				}

				$merged               = wp_parse_args( $line_data, $def_line );
				$merged['field_type'] = $field_type;

				if ( 'image' === $field_type ) {
					$merged['default_image']      = ! empty( $merged['default_image'] ) ? esc_url_raw( $merged['default_image'] ) : '';
					$merged['default_image_id']   = ! empty( $merged['default_image_id'] ) ? absint( $merged['default_image_id'] ) : 0;
					$merged['image_fit']          = ( isset( $merged['image_fit'] ) && 'cover' === $merged['image_fit'] ) ? 'cover' : 'contain';
					$merged['allow_image_upload'] = isset( $merged['allow_image_upload'] ) ? (bool) $merged['allow_image_upload'] : true;
				} else {
					// Enforce product-level allowed_fonts if specified
					if ( ! empty( $config['allowed_fonts'] ) && is_array( $config['allowed_fonts'] ) ) {
						$merged['allowed_fonts'] = $config['allowed_fonts'];
						// First font in allowed_fonts is the primary / default font
						if ( empty( $merged['font'] ) || ! in_array( $merged['font'], $config['allowed_fonts'], true ) ) {
							$merged['font'] = $config['allowed_fonts'][0];
						}
					} else {
						if ( empty( $merged['allowed_fonts'] ) || ! is_array( $merged['allowed_fonts'] ) ) {
							$merged['allowed_fonts'] = array_keys( FontRegistry::get_fonts() );
						}
						if ( ! empty( $merged['font'] ) && ! in_array( $merged['font'], $merged['allowed_fonts'], true ) ) {
							array_unshift( $merged['allowed_fonts'], $merged['font'] );
						}
						if ( empty( $config['allowed_fonts'] ) ) {
							$config['allowed_fonts'] = $merged['allowed_fonts'];
						}
					}
				}

				// Ensure per-line box coordinates exist
				if ( ! isset( $merged['box_width'] ) || ! isset( $merged['box_height'] ) || ! isset( $merged['box_left'] ) || ! isset( $merged['box_top'] ) ) {
					$box_w = isset( $merged['max_width'] ) ? floatval( $merged['max_width'] ) : 40.0;
					$box_h = isset( $merged['font_size'] ) ? floatval( $merged['font_size'] ) * 1.5 : 8.5;
					$box_l = 50.0 - ( $box_w / 2.0 );
					$box_t = isset( $merged['y_pct'] ) ? floatval( $merged['y_pct'] ) - ( $box_h / 2.0 ) : ( 25.0 + $line_index * 12.0 );
					$merged['box_left']   = isset( $merged['box_left'] ) ? floatval( $merged['box_left'] ) : $box_l;
					$merged['box_top']    = isset( $merged['box_top'] ) ? floatval( $merged['box_top'] ) : $box_t;
					$merged['box_width']  = isset( $merged['box_width'] ) ? floatval( $merged['box_width'] ) : $box_w;
					$merged['box_height'] = isset( $merged['box_height'] ) ? floatval( $merged['box_height'] ) : $box_h;
				} else {
					$merged['box_left']   = floatval( $merged['box_left'] );
					$merged['box_top']    = floatval( $merged['box_top'] );
					$merged['box_width']  = floatval( $merged['box_width'] );
					$merged['box_height'] = floatval( $merged['box_height'] );
				}
				$merged['box_rotate'] = isset( $merged['box_rotate'] ) ? floatval( $merged['box_rotate'] ) : 0.0;

				$normalized_lines[] = $merged;
				$line_index++;
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

		// Physical print dimensions & responsive aspect ratio normalization
		$print_unit   = ! empty( $config['print_unit'] ) ? sanitize_key( $config['print_unit'] ) : 'cm';
		$real_w       = isset( $config['print_real_width'] ) ? floatval( $config['print_real_width'] ) : ( isset( $defaults['print_real_width'] ) ? floatval( $defaults['print_real_width'] ) : 30.0 );
		$real_h       = isset( $config['print_real_height'] ) ? floatval( $config['print_real_height'] ) : ( isset( $defaults['print_real_height'] ) ? floatval( $defaults['print_real_height'] ) : 40.0 );
		$dpi          = isset( $config['dpi'] ) ? max( 72, intval( $config['dpi'] ) ) : 300;
		$product_type = ! empty( $config['product_type'] ) ? sanitize_key( $config['product_type'] ) : 'garment';
		$lock_ratio   = isset( $config['lock_aspect_ratio'] ) ? ! empty( $config['lock_aspect_ratio'] ) : ( 'flat_object' === $product_type );

		$computed_dims   = self::compute_print_dimensions( $real_w, $real_h, $print_unit, $dpi );
		$cumulative_dims = self::compute_cumulative_design_area( $config );

		$config['print_unit']              = $print_unit;
		$config['print_real_width']        = $real_w;
		$config['print_real_height']       = $real_h;
		$config['dpi']                     = $dpi;
		$config['product_type']            = $product_type;
		$config['lock_aspect_ratio']       = $lock_ratio;
		$config['print_aspect_ratio']      = $computed_dims['aspect_ratio'];
		$config['print_ratio_str']         = $computed_dims['ratio_str'];
		$config['cumulative_design_area']  = $cumulative_dims;

		// The actual print-ready file dimensions are governed by the cumulative design area
		$config['print_width_px']          = $cumulative_dims['width_px'];
		$config['print_height_px']         = $cumulative_dims['height_px'];
		$config['crop_print_width']        = $cumulative_dims['print_real_width'];
		$config['crop_print_height']       = $cumulative_dims['print_real_height'];
		$config['crop_aspect_ratio']       = $cumulative_dims['aspect_ratio'];
		$config['crop_ratio_str']          = $cumulative_dims['ratio_str'];
		$config['crop_simplified_ratio']   = $cumulative_dims['simplified_ratio'];

		$config['icons']        = IconSetRegistry::get_mushroom_icons();
		$config['fonts']        = FontRegistry::get_fonts();
		$config['color_sets']   = ColorPaletteRegistry::get_color_sets();
		$config['solid_colors'] = ColorPaletteRegistry::get_solid_swatches();
		$config['perso_visual'] = self::compile_scene_graph( $config );

		return $config;
	}

	/**
	 * Compute print pixel dimensions and aspect ratio from real physical dimensions.
	 *
	 * @param float|int|string $width  Real width.
	 * @param float|int|string $height Real height.
	 * @param string           $unit   Unit ('cm', 'mm', 'in').
	 * @param int              $dpi    DPI resolution (e.g. 300).
	 * @return array{width_px: int, height_px: int, aspect_ratio: float, ratio_str: string}
	 */
	public static function compute_print_dimensions( $width, $height, $unit = 'cm', $dpi = 300 ) {
		$dpi   = max( 72, intval( $dpi ) );
		$w_val = floatval( $width );
		$h_val = floatval( $height );
		if ( $w_val <= 0 ) {
			$w_val = 30.0;
		}
		if ( $h_val <= 0 ) {
			$h_val = 40.0;
		}

		$unit = strtolower( trim( $unit ) );
		switch ( $unit ) {
			case 'mm':
				$w_in = $w_val / 25.4;
				$h_in = $h_val / 25.4;
				break;
			case 'in':
			case 'inch':
			case 'inches':
				$w_in = $w_val;
				$h_in = $h_val;
				break;
			case 'cm':
			default:
				$unit = 'cm';
				$w_in = $w_val / 2.54;
				$h_in = $h_val / 2.54;
				break;
		}

		$px_w  = (int) round( $w_in * $dpi );
		$px_h  = (int) round( $h_in * $dpi );
		$ratio = ( $h_val > 0 ) ? round( $w_val / $h_val, 4 ) : 1.0;

		return array(
			'width_px'         => $px_w,
			'height_px'        => $px_h,
			'aspect_ratio'     => $ratio,
			'ratio_str'        => sprintf( '%s:%s', round( $w_val, 1 ), round( $h_val, 1 ) ),
			'simplified_ratio' => self::calculate_simplified_ratio( $w_val, $h_val ),
		);
	}

	/**
	 * Calculate simplified integer aspect ratio (e.g., 17:11, 3:2, 40:19, 1:1, 3:4).
	 *
	 * @param float $w Width.
	 * @param float $h Height.
	 * @return string Simplified ratio string.
	 */
	public static function calculate_simplified_ratio( $w, $h ) {
		$w = floatval( $w );
		$h = floatval( $h );
		if ( $w <= 0 || $h <= 0 ) {
			return '1:1';
		}

		$ratio = $w / $h;

		// A4 ISO standard ratio (~1:1.414 or ~0.707:1)
		if ( abs( $ratio - ( 21.0 / 29.7 ) ) < 0.015 ) {
			return '1:1.414 (1:√2)';
		}
		if ( abs( $ratio - ( 29.7 / 21.0 ) ) < 0.015 ) {
			return '1.414:1 (√2:1)';
		}

		// Scale decimal to integer (handling tenths, e.g. 8.5 -> 85, 5.5 -> 55)
		$factor = 10;
		$int_w  = (int) round( $w * $factor );
		$int_h  = (int) round( $h * $factor );

		// Greatest common divisor (GCD)
		$a = $int_w;
		$b = $int_h;
		while ( $b !== 0 ) {
			$t = $b;
			$b = $a % $b;
			$a = $t;
		}
		$gcd = ( $a > 0 ) ? $a : 1;

		$num_w = $int_w / $gcd;
		$num_h = $int_h / $gcd;

		if ( $num_w > 100 || $num_h > 100 ) {
			return sprintf( '%s:1', round( $ratio, 2 ) );
		}

		return sprintf( '%d:%d', $num_w, $num_h );
	}

	/**
	 * Compute cumulative design area bounding box for print export.
	 *
	 * Encloses all active multi-rectangle fields into a single tight bounding box.
	 * Calculates real physical dimensions (e.g. 3 × 4 cm) relative to the product image reference size (e.g. 12 × 20 cm)
	 * and derives the 300 DPI pixel dimensions for the final print-ready output.
	 *
	 * @param array $config Product configuration.
	 * @return array{
	 *     box_x_min: float, box_y_min: float, box_x_max: float, box_y_max: float,
	 *     span_w_pct: float, span_h_pct: float,
	 *     print_real_width: float, print_real_height: float,
	 *     width_px: int, height_px: int,
	 *     aspect_ratio: float, ratio_str: string, simplified_ratio: string
	 * }
	 */
	public static function compute_cumulative_design_area( $config ) {
		$prod_type = isset( $config['product_type'] ) ? $config['product_type'] : 'garment';
		$real_w    = isset( $config['print_real_width'] ) ? floatval( $config['print_real_width'] ) : 30.0;
		$real_h    = isset( $config['print_real_height'] ) ? floatval( $config['print_real_height'] ) : 40.0;
		$unit      = ! empty( $config['print_unit'] ) ? $config['print_unit'] : 'cm';
		$dpi       = isset( $config['dpi'] ) ? max( 72, intval( $config['dpi'] ) ) : 300;

		if ( 'flat_object' === $prod_type ) {
			$dims = self::compute_print_dimensions( $real_w, $real_h, $unit, $dpi );
			return array_merge( $dims, array(
				'box_x_min'         => 0.0,
				'box_y_min'         => 0.0,
				'box_x_max'         => 100.0,
				'box_y_max'         => 100.0,
				'span_w_pct'        => 100.0,
				'span_h_pct'        => 100.0,
				'print_real_width'  => $real_w,
				'print_real_height' => $real_h,
			) );
		}

		$boxes = array();
		if ( ! empty( $config['text_lines'] ) && is_array( $config['text_lines'] ) ) {
			foreach ( $config['text_lines'] as $line ) {
				if ( isset( $line['box_left'] ) && isset( $line['box_width'] ) ) {
					$boxes[] = array(
						'l' => floatval( $line['box_left'] ),
						't' => isset( $line['box_top'] ) ? floatval( $line['box_top'] ) : 0.0,
						'w' => floatval( $line['box_width'] ),
						'h' => isset( $line['box_height'] ) ? floatval( $line['box_height'] ) : 10.0,
						'r' => isset( $line['box_rotate'] ) ? floatval( $line['box_rotate'] ) : 0.0,
					);
				}
			}
		}

		if ( empty( $boxes ) ) {
			$bl = isset( $config['box_left'] ) ? floatval( $config['box_left'] ) : 28.0;
			$bt = isset( $config['box_top'] ) ? floatval( $config['box_top'] ) : 28.0;
			$bw = isset( $config['box_width'] ) ? floatval( $config['box_width'] ) : 44.0;
			$bh = isset( $config['box_height'] ) ? floatval( $config['box_height'] ) : 48.0;
			$boxes[] = array( 'l' => $bl, 't' => $bt, 'w' => $bw, 'h' => $bh, 'r' => 0.0 );
		}

		$x_min = 100.0;
		$y_min = 100.0;
		$x_max = 0.0;
		$y_max = 0.0;

		$real_w    = isset( $config['print_real_width'] ) ? floatval( $config['print_real_width'] ) : 30.0;
		$real_h    = isset( $config['print_real_height'] ) ? floatval( $config['print_real_height'] ) : 40.0;
		$ref_ratio = ( $real_w > 0 && $real_h > 0 ) ? ( $real_w / $real_h ) : 1.0;

		foreach ( $boxes as $b ) {
			$rot = isset( $b['r'] ) ? floatval( $b['r'] ) : 0.0;
			if ( 0.0 !== $rot ) {
				$rad    = deg2rad( $rot );
				$cos    = abs( cos( $rad ) );
				$sin    = abs( sin( $rad ) );
				$half_w = ( $b['w'] * $cos + ( $b['h'] / $ref_ratio ) * $sin ) / 2.0;
				$half_h = ( ( $b['w'] * $ref_ratio ) * $sin + $b['h'] * $cos ) / 2.0;
				$cx     = $b['l'] + ( $b['w'] / 2.0 );
				$cy     = $b['t'] + ( $b['h'] / 2.0 );

				$x_min = min( $x_min, $cx - $half_w );
				$y_min = min( $y_min, $cy - $half_h );
				$x_max = max( $x_max, $cx + $half_w );
				$y_max = max( $y_max, $cy + $half_h );
			} else {
				$x_min = min( $x_min, $b['l'] );
				$y_min = min( $y_min, $b['t'] );
				$x_max = max( $x_max, $b['l'] + $b['w'] );
				$y_max = max( $y_max, $b['t'] + $b['h'] );
			}
		}

		$span_w = max( 1.0, $x_max - $x_min );
		$span_h = max( 1.0, $y_max - $y_min );

		// Physical dimensions of the cumulative design area
		$print_w = round( ( $span_w / 100.0 ) * $real_w, 2 );
		$print_h = round( ( $span_h / 100.0 ) * $real_h, 2 );

		$dims = self::compute_print_dimensions( $print_w, $print_h, $unit, $dpi );

		return array_merge( $dims, array(
			'box_x_min'         => round( $x_min, 2 ),
			'box_y_min'         => round( $y_min, 2 ),
			'box_x_max'         => round( $x_max, 2 ),
			'box_y_max'         => round( $y_max, 2 ),
			'span_w_pct'        => round( $span_w, 2 ),
			'span_h_pct'        => round( $span_h, 2 ),
			'print_real_width'  => $print_w,
			'print_real_height' => $print_h,
		) );
	}

	/**
	 * Return standard physical product presets for POD items.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	public static function get_standard_physical_presets() {
		return array(
			'business_card' => array(
				'id'               => 'business_card',
				'name'             => __( 'Carte de Visite', 'mr-tee-editor' ),
				'dimensions'       => '8.5 × 5.5 cm',
				'width'            => 8.5,
				'height'           => 5.5,
				'unit'             => 'cm',
				'simplified_ratio' => '17:11',
				'decimal_ratio'    => 1.545,
				'orientation'      => 'Landscape',
				'product_type'     => 'flat_object',
				'lock_ratio'       => true,
				'print_px_300dpi'  => '1004 × 650 px',
			),
			'postcard' => array(
				'id'               => 'postcard',
				'name'             => __( 'Carte Postale / Photo', 'mr-tee-editor' ),
				'dimensions'       => '15 × 10 cm',
				'width'            => 15.0,
				'height'           => 10.0,
				'unit'             => 'cm',
				'simplified_ratio' => '3:2',
				'decimal_ratio'    => 1.500,
				'orientation'      => 'Landscape',
				'product_type'     => 'flat_object',
				'lock_ratio'       => true,
				'print_px_300dpi'  => '1772 × 1181 px',
			),
			'mug_wrap' => array(
				'id'               => 'mug_wrap',
				'name'             => __( 'Mug Céramique (Wrap Complet)', 'mr-tee-editor' ),
				'dimensions'       => '20 × 9.5 cm',
				'width'            => 20.0,
				'height'           => 9.5,
				'unit'             => 'cm',
				'simplified_ratio' => '40:19',
				'decimal_ratio'    => 2.105,
				'orientation'      => 'Panoramic strip',
				'product_type'     => 'flat_object',
				'lock_ratio'       => true,
				'print_px_300dpi'  => '2362 × 1122 px',
			),
			'poster_a4' => array(
				'id'               => 'poster_a4',
				'name'             => __( 'Flyer / Affiche Poster (A4)', 'mr-tee-editor' ),
				'dimensions'       => '21 × 29.7 cm',
				'width'            => 21.0,
				'height'           => 29.7,
				'unit'             => 'cm',
				'simplified_ratio' => '1:1.414 (~5:7)',
				'decimal_ratio'    => 0.707,
				'orientation'      => 'Portrait',
				'product_type'     => 'flat_object',
				'lock_ratio'       => true,
				'print_px_300dpi'  => '2480 × 3508 px',
			),
			'coaster_square' => array(
				'id'               => 'coaster_square',
				'name'             => __( 'Sous-verre / Patch Carré', 'mr-tee-editor' ),
				'dimensions'       => '10 × 10 cm',
				'width'            => 10.0,
				'height'           => 10.0,
				'unit'             => 'cm',
				'simplified_ratio' => '1:1',
				'decimal_ratio'    => 1.000,
				'orientation'      => 'Square',
				'product_type'     => 'flat_object',
				'lock_ratio'       => true,
				'print_px_300dpi'  => '1181 × 1181 px',
			),
			'tshirt_platen' => array(
				'id'               => 'tshirt_platen',
				'name'             => __( 'T-Shirt Textile (Plateau DTG)', 'mr-tee-editor' ),
				'dimensions'       => '30 × 40 cm',
				'width'            => 30.0,
				'height'           => 40.0,
				'unit'             => 'cm',
				'simplified_ratio' => '3:4',
				'decimal_ratio'    => 0.750,
				'orientation'      => 'Portrait',
				'product_type'     => 'garment',
				'lock_ratio'       => false,
				'print_px_300dpi'  => '3543 × 4724 px',
			),
		);
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

		// 1. Build Primary Field Layers & References (Text & Image fields)
		$created_color_refs = array();
		foreach ( $config['text_lines'] as $idx => $line ) {
			$line_num   = $idx + 1;
			$field_type = ( isset( $line['field_type'] ) && 'image' === $line['field_type'] ) ? 'image' : 'text';
			$box_w      = isset( $line['box_width'] ) ? floatval( $line['box_width'] ) : ( isset( $line['max_width'] ) ? floatval( $line['max_width'] ) : 40.0 );
			$box_h      = isset( $line['box_height'] ) ? floatval( $line['box_height'] ) : ( isset( $line['font_size'] ) ? floatval( $line['font_size'] ) * 1.5 : 8.5 );
			$box_l      = isset( $line['box_left'] ) ? floatval( $line['box_left'] ) : ( 50.0 - ( $box_w / 2.0 ) );
			$box_t      = isset( $line['box_top'] ) ? floatval( $line['box_top'] ) : ( isset( $line['y_pct'] ) ? floatval( $line['y_pct'] ) - ( $box_h / 2.0 ) : 30.0 );
			$box_r      = isset( $line['box_rotate'] ) ? floatval( $line['box_rotate'] ) : 0.0;

			if ( 'image' === $field_type ) {
				$img_ref_id      = ! empty( $line['ref_id'] ) ? $line['ref_id'] : 'id-ref-image-line-' . $line_num;
				$default_img_url = ! empty( $line['default_image'] ) ? $line['default_image'] : '';
				$image_fit       = ( isset( $line['image_fit'] ) && 'cover' === $line['image_fit'] ) ? 'cover' : 'contain';

				$references[] = array(
					'id'               => $img_ref_id,
					'type'             => 'IMAGE',
					'name'             => $line['label'],
					'url'              => $default_img_url,
					'defaultUrl'       => $default_img_url,
					'imageFit'         => $image_fit,
					'editable'         => ! isset( $line['allow_image_upload'] ) || ! empty( $line['allow_image_upload'] ),
					'isFieldImage'     => true,
					'customProperties' => array(
						array( 'key' => 'edited',   'value' => false ),
						array( 'key' => 'warning',  'value' => ! empty( $line['warning_unedited'] ) ),
						array( 'key' => 'fileName', 'value' => '' ),
					),
				);

				$image_layer = array(
					'type'         => 'IMAGE',
					'id'           => ! empty( $line['id'] ) ? $line['id'] : 'line_' . $line_num,
					'name'         => $line['label'],
					'reference'    => $img_ref_id,
					'isFieldImage' => true,
					'url'          => $default_img_url,
					'imageFit'     => $image_fit,
					'x'            => $box_l,
					'y'            => $box_t,
					'width'        => $box_w,
					'height'       => $box_h,
					'box_left'     => $box_l,
					'box_top'      => $box_t,
					'box_width'    => $box_w,
					'box_height'   => $box_h,
					'box_rotate'   => $box_r,
					'rotate'       => $box_r,
				);

				$layers[] = array(
					'type'  => 'GROUP',
					'name'  => $line['label'],
					'items' => array( $image_layer ),
				);
				continue;
			}

			$text_ref_id  = ! empty( $line['ref_id'] ) ? $line['ref_id'] : 'id-ref-text-line-' . $line_num;
			$color_ref_id = ! empty( $line['color_ref_id'] ) ? $line['color_ref_id'] : 'id-ref-color-line-' . $line_num;
			$font_id      = isset( $line['font'] ) ? $line['font'] : array_key_first( $fonts );
			$font_def     = isset( $fonts[ $font_id ] ) ? $fonts[ $font_id ] : FontRegistry::get_font( $font_id );
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
				'maxCharsPerLine'  => ! empty( $line['max_chars'] ) ? intval( $line['max_chars'] ) : 40,
				'validCharacters'  => "^[a-zA-Z0-9À-ÿ\\s'&.!\\-\n]*$",
				'fontId'           => $font_id,
				'fontFamily'       => $font_def['family'],
				'letterSpacing'    => isset( $font_def['letter_spacing'] ) ? floatval( $font_def['letter_spacing'] ) : 0.05,
				'strokeWidth'      => isset( $font_def['stroke_width'] ) ? floatval( $font_def['stroke_width'] ) : 0.0,
				'strokeColor'      => isset( $font_def['stroke'] ) ? $font_def['stroke'] : 'transparent',
				'casing'           => ! empty( $font_def['uppercase'] ) ? 'UPPER' : 'ORIGINAL',
				'multiColor'       => ! empty( $font_def['multi_color'] ),
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
					'strokeHex'    => isset( $line['stroke_hex'] ) ? $line['stroke_hex'] : ( isset( $font_def['stroke'] ) ? $font_def['stroke'] : '#000000' ),
					'palette'      => $font_def['palette'],
					'outsideOnly'  => true,
					'strokeWidth'  => isset( $font_def['stroke_width'] ) ? floatval( $font_def['stroke_width'] ) : 0.0,
					'variationType'=> 'LETTER',
				);
				$created_color_refs[] = $color_ref_id;
			}

			$center_x  = $box_l + ( $box_w / 2.0 );
			$center_y  = $box_t + ( $box_h / 2.0 );
			$font_size = isset( $line['font_size'] ) && floatval( $line['font_size'] ) > 0 ? floatval( $line['font_size'] ) : ( $box_h * 0.85 );

			$text_layer = array(
				'type'               => 'TEXT',
				'name'               => $line['label'],
				'reference'          => $text_ref_id,
				'colorReference'     => $color_ref_id,
				'fontId'             => $font_id,
				'fontFamily'         => $font_def['family'],
				'x'                  => $center_x,
				'y'                  => $center_y,
				'box_left'           => $box_l,
				'box_top'            => $box_t,
				'box_width'          => $box_w,
				'box_height'         => $box_h,
				'box_rotate'         => $box_r,
				'rotate'             => $box_r,
				'fontSize'           => $font_size,
				'maxWidth'           => $box_w * 0.95,
				'maxHeight'          => $box_h * 0.92,
				'textAnchor'         => 'middle',
				'textAnchorVertical' => 'middle',
				'multiLine'          => ! empty( $line['multiline'] ),
				'lineHeight'         => isset( $line['line_height'] ) ? floatval( $line['line_height'] ) : 0.95,
				'casing'             => isset( $line['casing'] ) ? $line['casing'] : ( ! empty( $font_def['uppercase'] ) ? 'UPPER' : 'ORIGINAL' ),
				'letterSpacing'      => isset( $font_def['letter_spacing'] ) ? floatval( $font_def['letter_spacing'] ) : 0.05,
				'stroke'             => array(
					'width'       => floatval( $font_def['stroke_width'] ),
					'outsideOnly' => true,
					'color'       => isset( $font_def['stroke'] ) ? $font_def['stroke'] : 'transparent',
				),
				'multiColor'         => ! empty( $font_def['multi_color'] ),
			);

			if ( ! empty( $line['is_masked'] ) ) {
				$layers[] = array(
					'type'       => 'MASK',
					'name'       => 'Masque ' . $line['label'],
					'textureUrl' => ! empty( $line['texture_url'] ) ? $line['texture_url'] : MR_TEE_EDITOR_URL . 'assets/images/leopard-pattern.png',
					'x'          => $box_l,
					'y'          => $box_t,
					'width'      => $box_w,
					'height'     => $box_h,
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
							'maxCharsPerLine'  => ! empty( $rep['max_chars'] ) ? intval( $rep['max_chars'] ) : 20,
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
							'fontFamily'         => "'Bebas Neue', 'BebasNeue', Arial Black, sans-serif",
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
				'real_width'   => isset( $config['print_real_width'] ) ? floatval( $config['print_real_width'] ) : 30.0,
				'real_height'  => isset( $config['print_real_height'] ) ? floatval( $config['print_real_height'] ) : 40.0,
				'unit'         => isset( $config['print_unit'] ) ? $config['print_unit'] : 'cm',
				'aspect_ratio' => isset( $config['print_aspect_ratio'] ) ? floatval( $config['print_aspect_ratio'] ) : 0.75,
				'product_type' => isset( $config['product_type'] ) ? $config['product_type'] : 'garment',
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
