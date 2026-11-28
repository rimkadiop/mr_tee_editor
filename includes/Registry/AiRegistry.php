<?php
/**
 * Mr. Tee Editor - Extensible AI Provider & Model Registry
 *
 * Central global manager (modeled on FontRegistry) for:
 * - Runware API Key (stored strictly server-side in wp_options; never exposed to storefront JS)
 * - Global Default AI Model
 * - Extensible AI Image Generation Model catalog (add, edit, delete multiple models)
 * - Per-product / per-field model resolution
 *
 * @package MrTeeEditor\Registry
 */

namespace MrTeeEditor\Registry;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class AiRegistry {

	/**
	 * WordPress option key storing global AI settings & models.
	 */
	const OPTION_KEY = 'mr_tee_ai_settings';

	/**
	 * Per-request in-memory cache of settings.
	 *
	 * @var array<string, mixed>|null
	 */
	private static $settings_cache = null;

	/**
	 * Return built-in starter AI models pre-seeded on first use.
	 * Admin can freely add, edit, or delete models in the AI Manager.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	public static function get_default_models() {
		return array(
			'nano_banana_2_1' => array(
				'id'              => 'nano_banana_2_1',
				'name'            => 'Google Nano Banana 2.1 (Multi-Ref / Swap)',
				'air_id'          => 'google:nano-banana@2.1',
				'quality'         => '1k',
				'dimensions'      => '1024x1024, 896x1200, 1200x896, 848x1264, 1264x848, 768x1376, 1376x768',
				'negative_prompt' => '',
				'ref_format'      => 'auto',
				'is_custom'       => false,
			),
			'nano_banana_pro' => array(
				'id'              => 'nano_banana_pro',
				'name'            => 'Google Nano Banana Pro (google:4@2)',
				'air_id'          => 'google:4@2',
				'quality'         => '1k',
				'dimensions'      => '1024x1024, 896x1200, 1200x896, 848x1264, 1264x848, 768x1376, 1376x768',
				'negative_prompt' => '',
				'ref_format'      => 'auto',
				'is_custom'       => false,
			),
			'seedream_5_5'    => array(
				'id'              => 'seedream_5_5',
				'name'            => 'ByteDance Seedream 5.5 (Editing / Swap)',
				'air_id'          => 'bytedance:seedream@5.5',
				'quality'         => '2k',
				'dimensions'      => '2048x2048, 1664x2496, 2496x1664, 1728x2304, 2304x1728, 1440x2560, 2560x1440',
				'negative_prompt' => '',
				'ref_format'      => 'auto',
				'is_custom'       => false,
			),
			'seedream_4_5'    => array(
				'id'              => 'seedream_4_5',
				'name'            => 'ByteDance Seedream 4.5 (2K / 4K)',
				'air_id'          => 'bytedance:seedream@4.5',
				'quality'         => '2k',
				'dimensions'      => '2048x2048, 1664x2496, 2496x1664, 1728x2304, 2304x1728, 1440x2560, 2560x1440',
				'negative_prompt' => '',
				'ref_format'      => 'auto',
				'is_custom'       => false,
			),
			'gpt_image_2'     => array(
				'id'              => 'gpt_image_2',
				'name'            => 'OpenAI GPT Image 2 (Multi-Ref)',
				'air_id'          => 'openai:gpt-image@2',
				'quality'         => 'medium',
				'dimensions'      => '1024x1024, 1024x1536, 1536x1024',
				'negative_prompt' => '',
				'ref_format'      => 'auto',
				'is_custom'       => false,
			),
			'qwen_image_edit_2511' => array(
				'id'              => 'qwen_image_edit_2511',
				'name'            => 'Alibaba Qwen-Image-Edit-2511 (Chinois Multi-Ref · Éco)',
				'air_id'          => 'alibaba:qwen-image-edit@2511',
				'quality'         => '',
				'dimensions'      => '1024x1024, 896x1152, 1152x896, 768x1024, 1024x768, 768x1344, 1344x768',
				'negative_prompt' => '',
				'ref_format'      => 'inputs',
				'is_custom'       => false,
			),
			'qwen_image_edit_plus' => array(
				'id'              => 'qwen_image_edit_plus',
				'name'            => 'Qwen-Image-Edit-Plus (Chinois Multi-Ref · Runware)',
				'air_id'          => 'runware:108@22',
				'quality'         => '20',
				'dimensions'      => '1024x1024, 896x1152, 1152x896, 768x1024, 1024x768, 768x1344, 1344x768',
				'negative_prompt' => '',
				'ref_format'      => 'inputs',
				'is_custom'       => false,
			),
			'flux_3_klein'         => array(
				'id'              => 'flux_3_klein',
				'name'            => 'BFL FLUX 3 Image Klein (Rapide)',
				'air_id'          => 'bfl:flux@3-image-klein',
				'quality'         => '',
				'dimensions'      => '1024x1024, 896x1152, 1152x896, 832x1248, 1248x832, 768x1344, 1344x768',
				'negative_prompt' => '',
				'ref_format'      => 'auto',
				'is_custom'       => false,
			),
			'flux_schnell'         => array(
				'id'              => 'flux_schnell',
				'name'            => 'Runware FLUX.1 Schnell (runware:100@1)',
				'air_id'          => 'runware:100@1',
				'quality'         => '4',
				'dimensions'      => '1024x1024, 896x1152, 1152x896, 832x1216, 1216x832, 768x1344, 1344x768',
				'negative_prompt' => '',
				'ref_format'      => 'legacy',
				'is_custom'       => false,
			),
		);
	}

	/**
	 * Clear per-request cached settings.
	 */
	public static function clear_cache() {
		self::$settings_cache = null;
	}

	/**
	 * Load normalized global AI settings.
	 *
	 * @return array<string, mixed>
	 */
	public static function get_settings() {
		if ( null !== self::$settings_cache ) {
			return self::$settings_cache;
		}

		$raw = get_option( self::OPTION_KEY, array() );
		if ( ! is_array( $raw ) ) {
			$raw = array();
		}

		$api_key = isset( $raw['api_key'] ) && is_string( $raw['api_key'] ) ? trim( $raw['api_key'] ) : '';

		// Seed starter models if none have ever been saved yet.
		$has_saved_models = array_key_exists( 'models', $raw ) && is_array( $raw['models'] );
		$raw_models       = $has_saved_models ? $raw['models'] : self::get_default_models();
		$models           = array();

		foreach ( $raw_models as $key => $entry ) {
			if ( ! is_array( $entry ) ) {
				continue;
			}
			$san = self::sanitize_model_entry( $entry, is_string( $key ) ? $key : '' );
			if ( '' !== $san['id'] && '' !== $san['air_id'] ) {
				$models[ $san['id'] ] = $san;
			}
		}

		if ( empty( $models ) && ! $has_saved_models ) {
			$models = self::get_default_models();
		}

		$default_model_id = isset( $raw['default_model_id'] ) ? sanitize_key( (string) $raw['default_model_id'] ) : '';
		if ( '' === $default_model_id || ! isset( $models[ $default_model_id ] ) ) {
			$first_key        = array_key_first( $models );
			$default_model_id = $first_key ? (string) $first_key : '';
		}

		$cleanup_days = isset( $raw['cleanup_days'] ) ? max( 1, min( 365, absint( $raw['cleanup_days'] ) ) ) : 14;

		self::$settings_cache = array(
			'api_key'          => $api_key,
			'default_model_id' => $default_model_id,
			'models'           => $models,
			'cleanup_days'     => $cleanup_days,
		);

		return self::$settings_cache;
	}

	/**
	 * Persist global AI settings cleanly without autoloading secrets unnecessarily on frontend.
	 *
	 * @param array<string, mixed> $settings Settings array.
	 * @return bool
	 */
	public static function save_settings( array $settings ) {
		$current = self::get_settings();
		$merged  = array(
			'api_key'          => isset( $settings['api_key'] ) ? trim( (string) $settings['api_key'] ) : $current['api_key'],
			'default_model_id' => isset( $settings['default_model_id'] ) ? sanitize_key( (string) $settings['default_model_id'] ) : $current['default_model_id'],
			'models'           => isset( $settings['models'] ) && is_array( $settings['models'] ) ? $settings['models'] : $current['models'],
			'cleanup_days'     => isset( $settings['cleanup_days'] ) ? max( 1, min( 365, absint( $settings['cleanup_days'] ) ) ) : $current['cleanup_days'],
		);

		$clean_models = array();
		foreach ( $merged['models'] as $k => $m ) {
			if ( ! is_array( $m ) ) {
				continue;
			}
			$san = self::sanitize_model_entry( $m, is_string( $k ) ? $k : '' );
			if ( '' !== $san['id'] && '' !== $san['air_id'] ) {
				$clean_models[ $san['id'] ] = $san;
			}
		}
		$merged['models'] = $clean_models;

		if ( '' === $merged['default_model_id'] || ! isset( $clean_models[ $merged['default_model_id'] ] ) ) {
			$first_key                  = array_key_first( $clean_models );
			$merged['default_model_id'] = $first_key ? (string) $first_key : '';
		}

		$exists = ( false !== get_option( self::OPTION_KEY, false ) );
		if ( $exists ) {
			update_option( self::OPTION_KEY, $merged, false );
		} else {
			add_option( self::OPTION_KEY, $merged, '', 'no' );
		}

		self::clear_cache();
		return true;
	}

	/**
	 * Sanitize a single AI model definition.
	 *
	 * @param array<string, mixed> $entry       Raw model array.
	 * @param string               $fallback_id Optional fallback slug.
	 * @return array<string, mixed>
	 */
	public static function sanitize_model_entry( array $entry, $fallback_id = '' ) {
		$air_id = isset( $entry['air_id'] ) ? trim( sanitize_text_field( (string) $entry['air_id'] ) ) : '';
		$air_id = preg_replace( '/[^a-zA-Z0-9:_@.\-\/]/', '', $air_id );

		$name = isset( $entry['name'] ) ? trim( sanitize_text_field( (string) $entry['name'] ) ) : '';
		if ( '' === $name && '' !== $air_id ) {
			$name = $air_id;
		}

		$raw_id = isset( $entry['id'] ) ? (string) $entry['id'] : $fallback_id;
		$id     = sanitize_key( $raw_id );
		if ( '' === $id && '' !== $name ) {
			$id = sanitize_key( str_replace( array( ':', '@', '.', '-', ' ' ), '_', strtolower( $name ) ) );
		}
		if ( '' === $id && '' !== $air_id ) {
			$id = sanitize_key( str_replace( array( ':', '@', '.', '-' ), '_', strtolower( $air_id ) ) );
		}
		if ( strlen( $id ) > 48 ) {
			$id = substr( $id, 0, 48 );
		}

		$quality = isset( $entry['quality'] ) ? trim( sanitize_text_field( (string) $entry['quality'] ) ) : '';
		if ( strlen( $quality ) > 64 ) {
			$quality = substr( $quality, 0, 64 );
		}

		$dimensions = isset( $entry['dimensions'] ) ? trim( sanitize_text_field( (string) $entry['dimensions'] ) ) : '';
		if ( strlen( $dimensions ) > 400 ) {
			$dimensions = substr( $dimensions, 0, 400 );
		}

		$negative_prompt = isset( $entry['negative_prompt'] ) ? trim( sanitize_textarea_field( (string) $entry['negative_prompt'] ) ) : '';
		if ( strlen( $negative_prompt ) > 1000 ) {
			$negative_prompt = substr( $negative_prompt, 0, 1000 );
		}

		$ref_format = isset( $entry['ref_format'] ) ? sanitize_key( (string) $entry['ref_format'] ) : 'auto';
		if ( ! in_array( $ref_format, array( 'auto', 'inputs', 'legacy' ), true ) ) {
			$ref_format = 'auto';
		}

		return array(
			'id'              => $id,
			'name'            => $name,
			'air_id'          => $air_id,
			'quality'         => $quality,
			'dimensions'      => $dimensions,
			'negative_prompt' => $negative_prompt,
			'ref_format'      => $ref_format,
			'is_custom'       => ! empty( $entry['is_custom'] ),
		);
	}

	/**
	 * Return raw Runware API key (server-side use ONLY).
	 *
	 * @return string
	 */
	public static function get_api_key() {
		$settings = self::get_settings();
		return ! empty( $settings['api_key'] ) ? (string) $settings['api_key'] : '';
	}

	/**
	 * Check whether an API key is configured.
	 *
	 * @return bool
	 */
	public static function has_api_key() {
		return '' !== self::get_api_key();
	}

	/**
	 * Return a safe masked representation of the saved API key for WP Admin UI.
	 *
	 * @return string
	 */
	public static function get_masked_api_key() {
		$key = self::get_api_key();
		if ( '' === $key ) {
			return '';
		}
		$len = strlen( $key );
		if ( $len <= 8 ) {
			return str_repeat( '•', $len );
		}
		return substr( $key, 0, 3 ) . str_repeat( '•', min( 16, $len - 7 ) ) . substr( $key, -4 );
	}

	/**
	 * Return all registered AI models.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	public static function get_models() {
		$settings = self::get_settings();
		return ! empty( $settings['models'] ) && is_array( $settings['models'] ) ? $settings['models'] : array();
	}

	/**
	 * Return the global default model ID.
	 *
	 * @return string
	 */
	public static function get_default_model_id() {
		$settings = self::get_settings();
		return ! empty( $settings['default_model_id'] ) ? (string) $settings['default_model_id'] : '';
	}

	/**
	 * Resolve a model by ID (or by AIR ID fallback), falling back to global default if empty.
	 *
	 * @param string $model_id Optional model key or AIR ID.
	 * @return array<string, mixed>|null
	 */
	public static function resolve_model( $model_id = '' ) {
		$models = self::get_models();
		if ( empty( $models ) ) {
			return null;
		}

		$key = trim( (string) $model_id );
		if ( '' !== $key ) {
			$san_key = sanitize_key( $key );
			if ( isset( $models[ $san_key ] ) ) {
				return $models[ $san_key ];
			}
			// Match by AIR ID directly if a raw AIR ID was stored.
			foreach ( $models as $m ) {
				if ( isset( $m['air_id'] ) && $m['air_id'] === $key ) {
					return $m;
				}
			}
		}

		$default_id = self::get_default_model_id();
		if ( '' !== $default_id && isset( $models[ $default_id ] ) ) {
			return $models[ $default_id ];
		}

		$first = reset( $models );
		return is_array( $first ) ? $first : null;
	}

	/**
	 * Add or update a model in the global catalog.
	 *
	 * @param array<string, mixed> $model_data Raw model payload.
	 * @param bool                 $set_default Optionally set as global default.
	 * @return array<string, mixed>|\WP_Error
	 */
	public static function upsert_model( array $model_data, $set_default = false ) {
		$san = self::sanitize_model_entry( $model_data );
		if ( '' === $san['air_id'] ) {
			return new \WP_Error( 'missing_air_id', __( 'L\'identifiant technique du modèle (AIR ID, ex : google:nano-banana@2.1) est obligatoire.', 'mr-tee-editor' ) );
		}
		if ( '' === $san['name'] ) {
			$san['name'] = $san['air_id'];
		}

		$settings = self::get_settings();
		$models   = $settings['models'];

		// Generate unique ID when creating a new model without explicit ID collision intent.
		if ( empty( $model_data['editing_id'] ) ) {
			$base_id = $san['id'];
			$counter = 2;
			while ( isset( $models[ $san['id'] ] ) ) {
				$san['id'] = substr( $base_id, 0, 42 ) . '_' . $counter;
				$counter++;
			}
			$san['is_custom'] = true;
		} else {
			$edit_id = sanitize_key( (string) $model_data['editing_id'] );
			if ( '' !== $edit_id && isset( $models[ $edit_id ] ) ) {
				$san['id']        = $edit_id;
				$san['is_custom'] = ! empty( $models[ $edit_id ]['is_custom'] );
			}
		}

		$models[ $san['id'] ] = $san;
		$default_id           = $settings['default_model_id'];
		if ( $set_default || '' === $default_id || ! isset( $models[ $default_id ] ) ) {
			$default_id = $san['id'];
		}

		self::save_settings(
			array(
				'models'           => $models,
				'default_model_id' => $default_id,
			)
		);

		return $san;
	}

	/**
	 * Delete a model by ID from the global catalog.
	 *
	 * @param string $model_id Model key.
	 * @return bool|\WP_Error
	 */
	public static function delete_model( $model_id ) {
		$id       = sanitize_key( (string) $model_id );
		$settings = self::get_settings();
		$models   = $settings['models'];

		if ( '' === $id || ! isset( $models[ $id ] ) ) {
			return new \WP_Error( 'not_found', __( 'Modèle IA introuvable.', 'mr-tee-editor' ) );
		}

		unset( $models[ $id ] );
		$default_id = $settings['default_model_id'];
		if ( $default_id === $id ) {
			$first_key  = array_key_first( $models );
			$default_id = $first_key ? (string) $first_key : '';
		}

		self::save_settings(
			array(
				'models'           => $models,
				'default_model_id' => $default_id,
			)
		);

		return true;
	}

	/**
	 * Parse a model's comma-separated dimensions string into validated `[width, height]` pairs.
	 *
	 * @param string $dimensions_str Raw dimensions string (e.g. "1024x1024, 896x1152").
	 * @return array<int, array{0: int, 1: int}>
	 */
	public static function parse_dimensions_list( $dimensions_str ) {
		$raw = trim( (string) $dimensions_str );
		if ( '' === $raw ) {
			return array();
		}
		$parts = preg_split( '/[,;\n]+/', $raw );
		$pairs = array();
		foreach ( $parts as $p ) {
			$p = trim( $p );
			if ( preg_match( '/^(\d{3,4})\s*[xX×*:\-]\s*(\d{3,4})$/', $p, $m ) ) {
				$w = max( 128, min( 6400, (int) $m[1] ) );
				$h = max( 128, min( 6400, (int) $m[2] ) );
				$pairs[] = array( $w, $h );
			}
		}
		return $pairs;
	}

	/**
	 * Return safe admin-client snapshot of AI registry state (NEVER includes raw API key).
	 *
	 * @return array<string, mixed>
	 */
	public static function get_admin_payload() {
		$settings = self::get_settings();
		return array(
			'hasApiKey'      => self::has_api_key(),
			'maskedApiKey'   => self::get_masked_api_key(),
			'defaultModelId' => $settings['default_model_id'],
			'cleanupDays'    => $settings['cleanup_days'],
			'models'         => array_values( $settings['models'] ),
		);
	}
}
