<?php
/**
 * Setup Rotated Edit Areas for Product 19318.
 */
$product_id = 19318;
$cfg        = get_post_meta( $product_id, '_mr_tee_config', true );
if ( ! is_array( $cfg ) ) {
	$cfg = array();
}

$cfg['text_lines'] = array(
	array(
		'id'              => 'line_1',
		'ref_id'          => 'id-ref-line-1',
		'color_ref_id'    => 'id-ref-color-line-1',
		'label'           => 'Titre Oblique (40°)',
		'default_text'    => 'OBLIQUE 40°',
		'font'            => 'super_mario',
		'allow_font_pick' => true,
		'color_mode'      => 'multi',
		'box_left'        => 30.0,
		'box_top'         => 20.0,
		'box_width'       => 38.0,
		'box_height'      => 8.0,
		'box_rotate'      => 40.0,
	),
	array(
		'id'              => 'line_2',
		'ref_id'          => 'id-ref-line-2',
		'color_ref_id'    => 'id-ref-color-line-2',
		'label'           => 'Badge Vertical (90°)',
		'default_text'    => 'VERTICAL 90°',
		'font'            => 'bungee',
		'allow_font_pick' => true,
		'color_mode'      => 'multi',
		'box_left'        => 18.0,
		'box_top'         => 38.0,
		'box_width'       => 30.0,
		'box_height'      => 7.5,
		'box_rotate'      => 90.0,
	),
	array(
		'id'              => 'line_3',
		'ref_id'          => 'id-ref-line-3',
		'color_ref_id'    => 'id-ref-color-line-3',
		'label'           => 'Style Rétro (-15°)',
		'default_text'    => 'RETRO -15°',
		'font'            => 'press_start',
		'allow_font_pick' => true,
		'color_mode'      => 'multi',
		'box_left'        => 45.0,
		'box_top'         => 42.0,
		'box_width'       => 35.0,
		'box_height'      => 7.0,
		'box_rotate'      => -15.0,
	),
	array(
		'id'              => 'line_4',
		'ref_id'          => 'id-ref-line-4',
		'color_ref_id'    => 'id-ref-color-line-4',
		'label'           => 'Base Horizontale (0°)',
		'default_text'    => 'HORIZONTAL 0°',
		'font'            => 'bebas',
		'allow_font_pick' => true,
		'color_mode'      => 'multi',
		'box_left'        => 28.0,
		'box_top'         => 64.0,
		'box_width'       => 44.0,
		'box_height'      => 8.5,
		'box_rotate'      => 0.0,
	),
);

$cfg['product_type']      = 'garment';
$cfg['print_real_width']  = 30.0;
$cfg['print_real_height'] = 40.0;
$cfg['print_unit']        = 'cm';
$cfg['dpi']               = 300;
$cfg['lock_aspect_ratio'] = 0;

$schema = \MrTeeEditor\Domain\SceneGraphSchema::compile_scene_graph( $cfg );
$cfg['declarative_scene_graph'] = $schema;

$cum = \MrTeeEditor\Domain\SceneGraphSchema::compute_cumulative_design_area( $cfg );
$cfg['cumulative_design_area'] = $cum;

update_post_meta( $product_id, '_mr_tee_config', $cfg );

echo "\nROT_SETUP_SUCCESS: " . json_encode( $cum ) . "\n";
