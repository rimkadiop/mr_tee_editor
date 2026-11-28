<?php
/**
 * Mr. Tee Editor - Runware AI API Client
 *
 * Unified HTTP client for https://api.runware.ai/v1 supporting:
 * - Multi-reference image editing & generation (`inputs.referenceImages` for Google Nano Banana,
 *   ByteDance Seedream, OpenAI GPT Image, BFL FLUX 2/3, MiniMax, etc., plus legacy Runware diffusion fallback)
 * - Automatic aspect-ratio dimension snapping matched to the admin-defined print area
 * - Async task recovery polling (`taskType: "getResponse"`)
 * - Optional AI Background Removal (`taskType: "removeBackground"`, `runware:110@1`)
 * - Zero-cost API key connection testing (`taskType: "modelSearch"`)
 *
 * @package MrTeeEditor\AI
 */

namespace MrTeeEditor\AI;

use MrTeeEditor\Registry\AiRegistry;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class RunwareClient {

	const API_URL = 'https://api.runware.ai/v1';

	/**
	 * Test Runware API key validity using a zero-cost utility task (`modelSearch`).
	 *
	 * @param string $api_key Optional explicit key to test; defaults to saved key.
	 * @return array<string, mixed>|\WP_Error
	 */
	public static function test_connection( $api_key = '' ) {
		$key = trim( (string) $api_key );
		if ( '' === $key ) {
			$key = AiRegistry::get_api_key();
		}
		if ( '' === $key ) {
			return new \WP_Error( 'missing_api_key', __( 'Aucune clé API Runware renseignée.', 'mr-tee-editor' ) );
		}

		$payload = array(
			array(
				'taskType' => 'modelSearch',
				'taskUUID' => wp_generate_uuid4(),
				'search'   => 'flux',
				'limit'    => 1,
			),
		);

		$res = self::post_tasks( $payload, $key, 20 );
		if ( is_wp_error( $res ) ) {
			return $res;
		}

		return array(
			'ok'      => true,
			'message' => __( 'Connexion à l\'API Runware réussie ! Clé API valide.', 'mr-tee-editor' ),
		);
	}

	/**
	 * Execute an AI image generation / multi-reference swap task on Runware.
	 *
	 * @param array<string, mixed> $params {
	 *   @type array<string, mixed> $model              Resolved model entry from AiRegistry.
	 *   @type string               $prompt             Resolved positive prompt (server-side only).
	 *   @type string[]             $reference_images   Ordered reference images (URLs or data URIs).
	 *   @type float                $target_ratio       Target visual width/height ratio of defined print field.
	 *   @type string               $size_mode          'auto_box' | 'match_reference'.
	 *   @type string               $task_uuid          Optional UUID for async polling correlation.
	 * }
	 * @return array<string, mixed>|\WP_Error
	 */
	public static function generate_image( array $params ) {
		$api_key = AiRegistry::get_api_key();
		if ( '' === $api_key ) {
			return new \WP_Error(
				'missing_api_key',
				__( 'Clé API Runware non configurée. Veuillez l\'ajouter dans le Gestionnaire IA.', 'mr-tee-editor' )
			);
		}

		$model = ! empty( $params['model'] ) && is_array( $params['model'] )
			? $params['model']
			: AiRegistry::resolve_model();

		if ( empty( $model ) || empty( $model['air_id'] ) ) {
			return new \WP_Error(
				'missing_model',
				__( 'Aucun modèle IA n\'est configuré. Ajoutez un modèle dans le Gestionnaire IA.', 'mr-tee-editor' )
			);
		}

		$prompt = isset( $params['prompt'] ) ? trim( (string) $params['prompt'] ) : '';
		$refs   = ! empty( $params['reference_images'] ) && is_array( $params['reference_images'] )
			? array_values( array_filter( $params['reference_images'] ) )
			: array();

		if ( '' === $prompt && empty( $refs ) ) {
			return new \WP_Error(
				'empty_prompt',
				__( 'Le champ IA n\'a ni prompt prédéfini ni image de référence configurée.', 'mr-tee-editor' )
			);
		}

		$air_id       = (string) $model['air_id'];
		$provider     = self::detect_provider( $air_id );
		$ref_format   = self::resolve_ref_format( $model, $provider, $air_id );
		$target_ratio = isset( $params['target_ratio'] ) && floatval( $params['target_ratio'] ) > 0
			? max( 0.25, min( 4.0, floatval( $params['target_ratio'] ) ) )
			: 1.0;
		$size_mode    = ( isset( $params['size_mode'] ) && 'match_reference' === $params['size_mode'] ) ? 'match_reference' : 'auto_box';
		$task_uuid    = ! empty( $params['task_uuid'] ) ? sanitize_text_field( (string) $params['task_uuid'] ) : wp_generate_uuid4();

		$task = self::build_inference_task(
			array(
				'model'        => $model,
				'air_id'       => $air_id,
				'provider'     => $provider,
				'ref_format'   => $ref_format,
				'prompt'       => $prompt,
				'refs'         => $refs,
				'target_ratio' => $target_ratio,
				'size_mode'    => $size_mode,
				'task_uuid'    => $task_uuid,
			)
		);

		$response = self::post_tasks( array( $task ), $api_key, 95 );

		// Smart single retry if a custom model expects the alternate referenceImages placement
		// or rejects an optional parameter (e.g. negativePrompt / resolution).
		if ( is_wp_error( $response ) ) {
			$err_msg = strtolower( $response->get_error_message() );
			$retried = false;

			if ( ! empty( $refs ) && count( $refs ) > 1 && ( false !== strpos( $err_msg, 'between 1 and 1' ) || false !== strpos( $err_msg, 'invalid number of elements' ) || false !== strpos( $err_msg, 'at most 1' ) ) ) {
				unset( $task['inputs'], $task['referenceImages'] );
				$task['seedImage']       = $refs[0];
				$task['strength']        = 0.78;
				$task['referenceImages'] = array( $refs[ count( $refs ) - 1 ] );
				$task['taskUUID']        = wp_generate_uuid4();
				$task_uuid               = $task['taskUUID'];
				$response                = self::post_tasks( array( $task ), $api_key, 95 );
				$retried                 = true;
			} elseif ( ! empty( $refs ) && ( false !== strpos( $err_msg, 'referenceimages' ) || false !== strpos( $err_msg, 'inputs' ) || false !== strpos( $err_msg, 'seedimage' ) ) ) {
				$alt_format = ( 'inputs' === $ref_format ) ? 'legacy' : 'inputs';
				$alt_uuid   = wp_generate_uuid4();
				$alt_task   = self::build_inference_task(
					array(
						'model'        => $model,
						'air_id'       => $air_id,
						'provider'     => $provider,
						'ref_format'   => $alt_format,
						'prompt'       => $prompt,
						'refs'         => $refs,
						'target_ratio' => $target_ratio,
						'size_mode'    => $size_mode,
						'task_uuid'    => $alt_uuid,
					)
				);
				$response   = self::post_tasks( array( $alt_task ), $api_key, 95 );
				$task_uuid  = $alt_uuid;
				$retried    = true;
			} elseif ( false !== strpos( $err_msg, 'negativeprompt' ) || false !== strpos( $err_msg, 'steps' ) || false !== strpos( $err_msg, 'resolution' ) || false !== strpos( $err_msg, 'providersettings' ) || false !== strpos( $err_msg, 'settings' ) ) {
				unset( $task['negativePrompt'], $task['steps'], $task['providerSettings'], $task['settings'], $task['enhancePrompt'] );
				if ( isset( $task['resolution'] ) ) {
					unset( $task['resolution'] );
					list( $fw, $fh ) = self::resolve_dimensions( $model, $provider, $air_id, $target_ratio );
					$task['width']   = $fw;
					$task['height']  = $fh;
				}
				$task['taskUUID'] = wp_generate_uuid4();
				$task_uuid        = $task['taskUUID'];
				$response         = self::post_tasks( array( $task ), $api_key, 95 );
				$retried          = true;
			}

			if ( $retried && is_wp_error( $response ) ) {
				return $response;
			}
			if ( is_wp_error( $response ) ) {
				return $response;
			}
		}

		return self::extract_image_result( $response, $task_uuid );
	}

	/**
	 * Poll Runware for an asynchronous / in-progress task result (`getResponse`).
	 *
	 * @param string $task_uuid Task UUID.
	 * @return array<string, mixed>|\WP_Error
	 */
	public static function poll_task( $task_uuid ) {
		$api_key = AiRegistry::get_api_key();
		if ( '' === $api_key ) {
			return new \WP_Error( 'missing_api_key', __( 'Clé API Runware non configurée.', 'mr-tee-editor' ) );
		}

		$uuid = preg_replace( '/[^a-zA-Z0-9\-]/', '', (string) $task_uuid );
		if ( '' === $uuid ) {
			return new \WP_Error( 'invalid_task_uuid', __( 'Identifiant de tâche invalide.', 'mr-tee-editor' ) );
		}

		$payload = array(
			array(
				'taskType' => 'getResponse',
				'taskUUID' => $uuid,
			),
		);

		$response = self::post_tasks( $payload, $api_key, 25 );
		if ( is_wp_error( $response ) ) {
			return $response;
		}

		return self::extract_image_result( $response, $uuid );
	}

	/**
	 * Remove background from an image using Runware `removeBackground` (`runware:110@1`).
	 *
	 * @param string $image_input Public URL or data URI.
	 * @return array<string, mixed>|\WP_Error
	 */
	public static function remove_background( $image_input ) {
		$api_key = AiRegistry::get_api_key();
		if ( '' === $api_key ) {
			return new \WP_Error( 'missing_api_key', __( 'Clé API Runware non configurée.', 'mr-tee-editor' ) );
		}

		$task_uuid = wp_generate_uuid4();
		$payload   = array(
			array(
				'taskType'     => 'removeBackground',
				'taskUUID'     => $task_uuid,
				'inputImage'   => (string) $image_input,
				'model'        => 'runware:110@1',
				'outputType'   => 'URL',
				'outputFormat' => 'PNG',
			),
		);

		$response = self::post_tasks( $payload, $api_key, 60 );
		if ( is_wp_error( $response ) ) {
			return $response;
		}

		return self::extract_image_result( $response, $task_uuid );
	}

	/**
	 * Build the provider-aware Runware `imageInference` task object.
	 *
	 * @param array<string, mixed> $ctx Build context.
	 * @return array<string, mixed>
	 */
	private static function build_inference_task( array $ctx ) {
		$model        = $ctx['model'];
		$air_id       = $ctx['air_id'];
		$provider     = $ctx['provider'];
		$ref_format   = $ctx['ref_format'];
		$prompt       = $ctx['prompt'];
		$refs         = $ctx['refs'];
		$target_ratio = $ctx['target_ratio'];
		$size_mode    = $ctx['size_mode'];
		$task_uuid    = $ctx['task_uuid'];

		$quality_raw   = isset( $model['quality'] ) ? trim( (string) $model['quality'] ) : '';
		$quality_parts = '' !== $quality_raw ? array_map( 'trim', explode( ';', $quality_raw ) ) : array( '' );
		$main_quality  = strtolower( $quality_parts[0] );

		$task = array(
			'taskType'       => 'imageInference',
			'taskUUID'       => $task_uuid,
			'model'          => $air_id,
			'positivePrompt' => '' !== $prompt ? $prompt : 'High quality realistic photograph',
			'numberResults'  => 1,
			'outputType'     => 'URL',
			'outputFormat'   => 'PNG',
		);

		// Determine output sizing: NEVER send `resolution` AND `width`/`height` together.
		$supports_resolution_tier = in_array( $provider, array( 'google', 'bytedance', 'bfl', 'minimax', 'klingai' ), true )
			&& in_array( $main_quality, array( '1k', '2k', '4k' ), true );

		if ( 'match_reference' === $size_mode && ! empty( $refs ) && $supports_resolution_tier ) {
			$task['resolution'] = $main_quality;
		} else {
			list( $w, $h )  = self::resolve_dimensions( $model, $provider, $air_id, $target_ratio, $main_quality );
			$task['width']  = $w;
			$task['height'] = $h;
		}

		// Attach reference images in exact array order (index 0 = image 1, index 1 = image 2, ...).
		if ( ! empty( $refs ) ) {
			if ( 'inputs' === $ref_format ) {
				$task['inputs'] = array(
					'referenceImages' => $refs,
				);
			} else {
				$is_flux_legacy = (
					false !== strpos( $air_id, 'runware:10' ) ||
					false !== strpos( $air_id, 'runware:40' ) ||
					false !== stripos( $air_id, 'flux' )
				);
				if ( $is_flux_legacy ) {
					if ( count( $refs ) > 1 ) {
						// Legacy single-ref FLUX models (e.g. runware:100@1) accept max 1 item in referenceImages:
						// pass admin reference as seedImage and customer photo as referenceImages[0].
						$task['seedImage']       = $refs[0];
						$task['strength']        = 0.78;
						$task['referenceImages'] = array( $refs[ count( $refs ) - 1 ] );
					} else {
						$task['referenceImages'] = array( $refs[0] );
					}
				} else {
					$task['seedImage'] = $refs[0];
					$task['strength']  = 0.75;
				}
			}
		}

		// Provider-specific quality / steps / negative prompt options.
		if ( 'openai' === $provider ) {
			if ( in_array( $main_quality, array( 'low', 'medium', 'high', 'auto', 'standard', 'hd' ), true ) ) {
				if ( false !== strpos( $air_id, 'gpt-image@2' ) || false !== strpos( $air_id, 'gpt-image@' ) ) {
					$task['settings'] = array( 'quality' => $main_quality );
				} else {
					$task['providerSettings'] = array(
						'openai' => array( 'quality' => $main_quality ),
					);
				}
			}
		} elseif ( 'runware' === $provider || 'civitai' === $provider ) {
			if ( is_numeric( $main_quality ) && (int) $main_quality > 0 ) {
				$task['steps'] = max( 1, min( 60, (int) $main_quality ) );
			}
			if ( ! empty( $model['negative_prompt'] ) ) {
				$task['negativePrompt'] = (string) $model['negative_prompt'];
			}
		} elseif ( ! empty( $model['negative_prompt'] ) && 'legacy' === $ref_format ) {
			$task['negativePrompt'] = (string) $model['negative_prompt'];
		}

		return $task;
	}

	/**
	 * Detect provider prefix from an AIR identifier (e.g. "google:nano-banana@2.1" -> "google").
	 *
	 * @param string $air_id AIR identifier.
	 * @return string
	 */
	private static function detect_provider( $air_id ) {
		$parts = explode( ':', strtolower( trim( (string) $air_id ) ) );
		return ! empty( $parts[0] ) ? $parts[0] : 'runware';
	}

	/**
	 * Resolve whether a model expects `inputs.referenceImages` (modern Runware schema)
	 * or top-level `referenceImages` / `seedImage` (legacy diffusion schema).
	 *
	 * @param array<string, mixed> $model    Model record.
	 * @param string               $provider Provider prefix.
	 * @param string               $air_id   AIR identifier.
	 * @return string 'inputs' | 'legacy'
	 */
	private static function resolve_ref_format( array $model, $provider, $air_id ) {
		$explicit = isset( $model['ref_format'] ) ? (string) $model['ref_format'] : 'auto';
		if ( 'inputs' === $explicit || 'legacy' === $explicit ) {
			return $explicit;
		}
		if ( in_array( $provider, array( 'google', 'bytedance', 'openai', 'bfl', 'minimax', 'ideogram', 'klingai', 'qwen', 'alibaba', 'prunaai', 'meta', 'xai', 'vidu', 'recraft' ), true ) ) {
			return 'inputs';
		}
		if ( 0 === strpos( $air_id, 'runware:40' ) || 0 === strpos( $air_id, 'runware:106@' ) || 0 === strpos( $air_id, 'runware:108@' ) ) {
			return 'inputs';
		}
		return 'legacy';
	}

	/**
	 * Resolve the closest valid `[width, height]` pair matching the admin-defined field's visual aspect ratio.
	 *
	 * @param array<string, mixed> $model        Model record.
	 * @param string               $provider     Provider prefix.
	 * @param string               $air_id       AIR identifier.
	 * @param float                $target_ratio Desired width/height ratio.
	 * @param string               $quality      Optional quality tier ('1k', '2k', '4k').
	 * @return array{0: int, 1: int}
	 */
	public static function resolve_dimensions( array $model, $provider, $air_id, $target_ratio, $quality = '' ) {
		$candidates = array();

		// 1. Prefer admin-configured model dimensions table when provided.
		if ( ! empty( $model['dimensions'] ) ) {
			$candidates = AiRegistry::parse_dimensions_list( (string) $model['dimensions'] );
		}

		// 2. Otherwise use verified provider resolution tables.
		if ( empty( $candidates ) ) {
			$candidates = self::get_provider_default_dimensions( $provider, $air_id, $quality );
		}

		// 3. Pick candidate whose aspect ratio is closest to the defined print field ratio.
		if ( ! empty( $candidates ) ) {
			$best      = $candidates[0];
			$best_diff = null;
			foreach ( $candidates as $pair ) {
				if ( $pair[1] <= 0 ) {
					continue;
				}
				$cand_ratio = $pair[0] / $pair[1];
				// Log-ratio distance treats 3:4 vs 4:3 symmetrically.
				$diff = abs( log( $cand_ratio / max( 0.05, $target_ratio ) ) );
				if ( null === $best_diff || $diff < $best_diff ) {
					$best_diff = $diff;
					$best      = $pair;
				}
			}
			return $best;
		}

		// 4. Generic Runware multiple-of-64 computation around ~1 megapixel.
		$base_area = 1024 * 1024;
		$raw_w     = sqrt( $base_area * $target_ratio );
		$raw_h     = $raw_w / max( 0.1, $target_ratio );
		$w         = max( 512, min( 2048, (int) round( $raw_w / 64 ) * 64 ) );
		$h         = max( 512, min( 2048, (int) round( $raw_h / 64 ) * 64 ) );
		return array( $w, $h );
	}

	/**
	 * Built-in supported dimension presets per provider family.
	 *
	 * @param string $provider Provider prefix.
	 * @param string $air_id   AIR identifier.
	 * @param string $quality  Quality/resolution tier.
	 * @return array<int, array{0: int, 1: int}>
	 */
	private static function get_provider_default_dimensions( $provider, $air_id, $quality = '' ) {
		if ( 'openai' === $provider ) {
			if ( 'openai:2@3' === $air_id ) {
				return array( array( 1024, 1024 ), array( 1792, 1024 ), array( 1024, 1792 ) );
			}
			return array( array( 1024, 1024 ), array( 1536, 1024 ), array( 1024, 1536 ) );
		}

		if ( 'google' === $provider ) {
			if ( '4k' === $quality ) {
				return array(
					array( 4096, 4096 ),
					array( 3712, 4608 ),
					array( 4608, 3712 ),
					array( 3584, 4800 ),
					array( 4800, 3584 ),
					array( 3392, 5096 ),
					array( 5096, 3392 ),
					array( 3072, 5504 ),
					array( 5504, 3072 ),
				);
			}
			if ( '2k' === $quality ) {
				return array(
					array( 2048, 2048 ),
					array( 1856, 2304 ),
					array( 2304, 1856 ),
					array( 1792, 2400 ),
					array( 2400, 1792 ),
					array( 1696, 2528 ),
					array( 2528, 1696 ),
					array( 1536, 2752 ),
					array( 2752, 1536 ),
				);
			}
			return array(
				array( 1024, 1024 ),
				array( 928, 1152 ),
				array( 1152, 928 ),
				array( 896, 1200 ),
				array( 1200, 896 ),
				array( 848, 1264 ),
				array( 1264, 848 ),
				array( 768, 1376 ),
				array( 1376, 768 ),
			);
		}

		if ( 'bytedance' === $provider ) {
			return array(
				array( 2048, 2048 ),
				array( 1728, 2304 ),
				array( 2304, 1728 ),
				array( 1664, 2496 ),
				array( 2496, 1664 ),
				array( 1440, 2560 ),
				array( 2560, 1440 ),
			);
		}

		return array(
			array( 1024, 1024 ),
			array( 896, 1152 ),
			array( 1152, 896 ),
			array( 832, 1216 ),
			array( 1216, 832 ),
			array( 768, 1344 ),
			array( 1344, 768 ),
		);
	}

	/**
	 * Send a batch of tasks to the Runware HTTP API.
	 *
	 * @param array<int, array<string, mixed>> $tasks   Task array.
	 * @param string                           $api_key Bearer API key.
	 * @param int                              $timeout Timeout in seconds.
	 * @return array<string, mixed>|\WP_Error
	 */
	private static function post_tasks( array $tasks, $api_key, $timeout = 90 ) {
		$body = wp_json_encode( $tasks );
		if ( false === $body ) {
			return new \WP_Error( 'json_encode_error', __( 'Impossible de sérialiser la requête IA.', 'mr-tee-editor' ) );
		}

		$response = wp_remote_post(
			self::API_URL,
			array(
				'body'        => $body,
				'headers'     => array(
					'Content-Type'  => 'application/json',
					'Authorization' => 'Bearer ' . $api_key,
				),
				'timeout'     => max( 10, (int) $timeout ),
				'data_format' => 'body',
			)
		);

		if ( is_wp_error( $response ) ) {
			return new \WP_Error(
				'runware_http_error',
				sprintf( __( 'Erreur de connexion Runware : %s', 'mr-tee-editor' ), $response->get_error_message() )
			);
		}

		$code     = (int) wp_remote_retrieve_response_code( $response );
		$raw_body = (string) wp_remote_retrieve_body( $response );
		$decoded  = json_decode( $raw_body, true );

		if ( ! is_array( $decoded ) ) {
			return new \WP_Error(
				'runware_invalid_json',
				sprintf( __( 'Réponse invalide de Runware (HTTP %d).', 'mr-tee-editor' ), $code )
			);
		}

		if ( ! empty( $decoded['errors'] ) && is_array( $decoded['errors'] ) ) {
			$first = $decoded['errors'][0];
			$msg   = is_array( $first ) && ! empty( $first['message'] )
				? (string) $first['message']
				: __( 'Erreur retournée par Runware.', 'mr-tee-editor' );
			return new \WP_Error( 'runware_api_error', $msg );
		}

		if ( isset( $decoded['error'] ) ) {
			$msg = is_array( $decoded['error'] ) && ! empty( $decoded['error']['message'] )
				? (string) $decoded['error']['message']
				: ( is_string( $decoded['error'] ) ? $decoded['error'] : __( 'Erreur retournée par Runware.', 'mr-tee-editor' ) );
			return new \WP_Error( 'runware_api_error', $msg );
		}

		if ( $code >= 400 ) {
			return new \WP_Error(
				'runware_http_status',
				sprintf( __( 'Erreur API Runware (HTTP %d).', 'mr-tee-editor' ), $code )
			);
		}

		return $decoded;
	}

	/**
	 * Extract image URL / base64 or async processing status from a Runware response body.
	 *
	 * @param array<string, mixed> $decoded   Decoded JSON body.
	 * @param string               $task_uuid Task UUID.
	 * @return array<string, mixed>|\WP_Error
	 */
	private static function extract_image_result( array $decoded, $task_uuid ) {
		$items = isset( $decoded['data'] ) && is_array( $decoded['data'] ) ? $decoded['data'] : array();

		foreach ( $items as $item ) {
			if ( ! is_array( $item ) ) {
				continue;
			}
			if ( isset( $item['status'] ) && 'processing' === strtolower( (string) $item['status'] ) ) {
				return array(
					'status'    => 'processing',
					'task_uuid' => ! empty( $item['taskUUID'] ) ? (string) $item['taskUUID'] : $task_uuid,
				);
			}
			if ( ! empty( $item['imageURL'] ) ) {
				return array(
					'status'    => 'completed',
					'image_url' => (string) $item['imageURL'],
					'task_uuid' => ! empty( $item['taskUUID'] ) ? (string) $item['taskUUID'] : $task_uuid,
				);
			}
			if ( ! empty( $item['imageBase64Data'] ) ) {
				return array(
					'status'    => 'completed',
					'image_url' => 'data:image/png;base64,' . (string) $item['imageBase64Data'],
					'task_uuid' => ! empty( $item['taskUUID'] ) ? (string) $item['taskUUID'] : $task_uuid,
				);
			}
		}

		return array(
			'status'    => 'processing',
			'task_uuid' => $task_uuid,
		);
	}
}
