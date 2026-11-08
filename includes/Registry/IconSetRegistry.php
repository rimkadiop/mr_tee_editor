<?php
/**
 * Mr. Tee Editor - Extensible Icon Set Registry (id_perso_img_set)
 *
 * Manages vector SVG icon sets for repeater groups (such as the 6 Super
 * Mushroom characters on Monsieur TSHIRT Product #24987).
 *
 * @package MrTeeEditor\Registry
 */

namespace MrTeeEditor\Registry;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class IconSetRegistry {

	/**
	 * Return all registered icon sets (`id_perso_img_set`).
	 *
	 * @return array<string, array<string, mixed>>
	 */
	public static function get_icon_sets() {
		$sets = array(
			'mushrooms_693' => array(
				'id'          => 'mushrooms_693',
				'numeric_id'  => 693,
				'name'        => 'Champignons Jeu Vidéo (Set #693)',
				'items'       => self::get_mushroom_icons(),
			),
		);

		return apply_filters( 'mr_tee_registered_icon_sets', $sets );
	}

	/**
	 * Return the 6 Super Mushroom vector SVG icons matching Monsieur TSHIRT Set #693.
	 *
	 * @return array<string, array<string, string>>
	 */
	public static function get_mushroom_icons() {
		$icons = array(
			'mushroom_red' => array(
				'id'    => 'mushroom_red',
				'name'  => 'Champignon Rouge (Super)',
				'color' => '#e52521',
				'url'   => MR_TEE_EDITOR_URL . 'assets/icons/mushroom_red.svg',
				'path'  => MR_TEE_EDITOR_DIR . 'assets/icons/mushroom_red.svg',
			),
			'mushroom_green' => array(
				'id'    => 'mushroom_green',
				'name'  => 'Champignon Vert (1-UP)',
				'color' => '#43b047',
				'url'   => MR_TEE_EDITOR_URL . 'assets/icons/mushroom_green.svg',
				'path'  => MR_TEE_EDITOR_DIR . 'assets/icons/mushroom_green.svg',
			),
			'mushroom_blue' => array(
				'id'    => 'mushroom_blue',
				'name'  => 'Champignon Bleu (Mini)',
				'color' => '#0062ff',
				'url'   => MR_TEE_EDITOR_URL . 'assets/icons/mushroom_blue.svg',
				'path'  => MR_TEE_EDITOR_DIR . 'assets/icons/mushroom_blue.svg',
			),
			'mushroom_yellow' => array(
				'id'    => 'mushroom_yellow',
				'name'  => 'Champignon Jaune (Gold)',
				'color' => '#fbd000',
				'url'   => MR_TEE_EDITOR_URL . 'assets/icons/mushroom_yellow.svg',
				'path'  => MR_TEE_EDITOR_DIR . 'assets/icons/mushroom_yellow.svg',
			),
			'mushroom_purple' => array(
				'id'    => 'mushroom_purple',
				'name'  => 'Champignon Violet (Poison)',
				'color' => '#8b2be2',
				'url'   => MR_TEE_EDITOR_URL . 'assets/icons/mushroom_purple.svg',
				'path'  => MR_TEE_EDITOR_DIR . 'assets/icons/mushroom_purple.svg',
			),
			'mushroom_pink' => array(
				'id'    => 'mushroom_pink',
				'name'  => 'Champignon Rose (Princess)',
				'color' => '#ff4d94',
				'url'   => MR_TEE_EDITOR_URL . 'assets/icons/mushroom_pink.svg',
				'path'  => MR_TEE_EDITOR_DIR . 'assets/icons/mushroom_pink.svg',
			),
		);

		return apply_filters( 'mr_tee_registered_mushroom_icons', $icons );
	}
}
