<?php
/**
 * Mr. Tee Editor - AI AJAX Controller
 *
 * Handles:
 * - Storefront AI generation (`mr_tee_ai_generate`) & async recovery polling (`mr_tee_ai_poll`):
 *   Reads hidden admin prompt, hidden reference image(s), selected AI model, and defined field
 *   box geometry strictly server-side from product config — NEVER exposed to the browser.
 * - Admin live test (`mr_tee_ai_admin_test`): allows testing a field's prompt & reference swap
 *   directly in the Product Configurator before saving/publishing.
 * - Admin AI Manager CRUD (`mr_tee_ai_save_settings`, `mr_tee_ai_save_model`,
 *   `mr_tee_ai_delete_model`, `mr_tee_ai_set_default_model`, `mr_tee_ai_test_connection`).
 *
 * @package MrTeeEditor\AI
 */

namespace MrTeeEditor\AI;

use MrTeeEditor\Domain\SceneGraphSchema;
use MrTeeEditor\Registry\AiRegistry;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class AiAjaxController {

	/**
	 * Register all AI AJAX hooks.
	 */
	public static function register_hooks() {
		// Storefront generation & async task polling (available to guests & logged-in shoppers).
		add_action( 'wp_ajax_mr_tee_ai_generate', array( __CLASS__, 'handle_storefront_generate' ) );
		add_action( 'wp_ajax_nopriv_mr_tee_ai_generate', array( __CLASS__, 'handle_storefront_generate' ) );
		add_action( 'wp_ajax_mr_tee_ai_poll', array( __CLASS__, 'handle_poll_task' ) );
		add_action( 'wp_ajax_nopriv_mr_tee_ai_poll', array( __CLASS__, 'handle_poll_task' ) );

		// Admin-only Product Studio test & Global AI Manager CRUD.
		add_action( 'wp_ajax_mr_tee_ai_admin_test', array( __CLASS__, 'handle_admin_test_field' ) );
		add_action( 'wp_ajax_mr_tee_ai_save_settings', array( __CLASS__, 'handle_admin_save_settings' ) );
		add_action( 'wp_ajax_mr_tee_ai_save_model', array( __CLASS__, 'handle_admin_save_model' ) );
		add_action( 'wp_ajax_mr_tee_ai_delete_model', array( __CLASS__, 'handle_admin_delete_model' ) );
		add_action( 'wp_ajax_mr_tee_ai_set_default_model', array( __CLASS__, 'handle_admin_set_default_model' ) );
		add_action( 'wp_ajax_mr_tee_ai_test_connection', array( __CLASS__, 'handle_admin_test_connection' ) );
	}

	/**
	 * Verify admin capability & nonce for AI Manager and Studio test endpoints.
	 */
	private static function verify_admin_request() {
		if ( false === check_ajax_referer( 'mr_tee_admin_nonce', 'nonce', false ) ) {
			wp_send_json_error( array( 'message' => __( 'Session administrateur expirée. Rechargez la page.', 'mr-tee-editor' ) ), 403 );
		}
		if ( ! current_user_can( 'manage_woocommerce' ) && ! current_user_can( 'edit_products' ) ) {
			wp_send_json_error( array( 'message' => __( 'Permissions insuffisantes.', 'mr-tee-editor' ) ), 403 );
		}
	}

	/**
	 * Storefront AI Generation Handler.
	 * Accepts ONLY product_id, field_id/ref_id, task_uuid, and customer uploaded image.
	 * All prompts, reference images, and model IDs are loaded strictly server-side.
	 */
	public static function handle_storefront_generate() {
		if ( false === check_ajax_referer( 'mr_tee_frontend_upload', 'nonce', false ) ) {
			wp_send_json_error( array( 'message' => __( 'Vérification de sécurité expirée. Veuillez recharger la page.', 'mr-tee-editor' ) ), 403 );
		}

		$product_id = isset( $_POST['product_id'] ) ? absint( $_POST['product_id'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Missing
		$field_id   = isset( $_POST['field_id'] ) ? preg_replace( '/[^a-zA-Z0-9_-]/', '', (string) wp_unslash( $_POST['field_id'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		$ref_id     = isset( $_POST['ref_id'] ) ? preg_replace( '/[^a-zA-Z0-9_-]/', '', (string) wp_unslash( $_POST['ref_id'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		$task_uuid  = isset( $_POST['task_uuid'] ) ? preg_replace( '/[^a-zA-Z0-9\-]/', '', (string) wp_unslash( $_POST['task_uuid'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized

		if ( '' === $task_uuid || strlen( $task_uuid ) < 16 ) {
			$task_uuid = wp_generate_uuid4();
		}

		if ( $product_id <= 0 ) {
			wp_send_json_error( array( 'message' => __( 'Produit invalide.', 'mr-tee-editor' ) ), 400 );
		}

		$config = SceneGraphSchema::get_product_config( $product_id, array( 'compile_scene_graph' => false ) );
		if ( empty( $config['engine'] ) || 'mr_tee_editor' !== $config['engine'] ) {
			wp_send_json_error( array( 'message' => __( 'Personnalisation non active sur ce produit.', 'mr-tee-editor' ) ), 400 );
		}

		$target_line = self::find_ai_field_in_config( $config, $field_id, $ref_id );
		if ( null === $target_line ) {
			wp_send_json_error( array( 'message' => __( 'Champ IA introuvable pour ce produit.', 'mr-tee-editor' ) ), 404 );
		}

		// Extract optional/required customer uploaded image.
		$customer_data_uri = self::extract_uploaded_customer_photo();
		if ( is_wp_error( $customer_data_uri ) ) {
			wp_send_json_error( array( 'message' => $customer_data_uri->get_error_message() ), 400 );
		}

		// Build ordered server-side reference list: [Admin Ref #1, Admin Ref #2, ..., Customer Photo].
		$ref_list       = self::collect_admin_reference_images( $target_line );
		$admin_ref_cnt  = count( $ref_list );
		$customer_index = 0;

		if ( '' !== $customer_data_uri ) {
			$ref_list[]     = $customer_data_uri;
			$customer_index = $admin_ref_cnt + 1;
		} elseif ( 0 === $admin_ref_cnt ) {
			wp_send_json_error( array( 'message' => __( 'Veuillez importer votre image avant de générer.', 'mr-tee-editor' ) ), 400 );
		}

		$raw_prompt = isset( $target_line['ai_prompt'] ) ? trim( (string) $target_line['ai_prompt'] ) : '';
		$prompt     = self::resolve_prompt_tokens( $raw_prompt, $customer_index );

		$model_id = ! empty( $target_line['ai_model'] ) ? (string) $target_line['ai_model'] : '';
		$model    = AiRegistry::resolve_model( $model_id );
		if ( empty( $model ) ) {
			wp_send_json_error( array( 'message' => __( 'Service IA momentanément indisponible (aucun modèle configuré).', 'mr-tee-editor' ) ), 503 );
		}

		$size_mode    = ( isset( $target_line['ai_size_mode'] ) && 'match_reference' === $target_line['ai_size_mode'] ) ? 'match_reference' : 'auto_box';
		$remove_bg    = ! empty( $target_line['ai_remove_bg'] );
		$target_ratio = self::compute_field_visual_ratio( $product_id, $config, $target_line );

		set_transient(
			'mr_tee_ai_task_' . md5( $task_uuid ),
			array(
				'remove_bg' => $remove_bg,
				'created'   => time(),
			),
			15 * MINUTE_IN_SECONDS
		);

		$result = RunwareClient::generate_image(
			array(
				'model'            => $model,
				'prompt'           => $prompt,
				'reference_images' => $ref_list,
				'target_ratio'     => $target_ratio,
				'size_mode'        => $size_mode,
				'task_uuid'        => $task_uuid,
			)
		);

		if ( is_wp_error( $result ) ) {
			wp_send_json_error( array( 'message' => self::friendly_storefront_error( $result ) ), 502 );
		}

		self::finalize_and_send_generation_response( $result, $remove_bg );
	}

	/**
	 * Poll an in-progress Runware task (recovers seamlessly after slow responses / gateway timeouts).
	 */
	public static function handle_poll_task() {
		$valid_front = ( false !== check_ajax_referer( 'mr_tee_frontend_upload', 'nonce', false ) );
		$valid_admin = ( ! $valid_front && false !== check_ajax_referer( 'mr_tee_admin_nonce', 'nonce', false ) && ( current_user_can( 'manage_woocommerce' ) || current_user_can( 'edit_products' ) ) );
		if ( ! $valid_front && ! $valid_admin ) {
			wp_send_json_error( array( 'message' => __( 'Vérification de sécurité expirée.', 'mr-tee-editor' ) ), 403 );
		}

		$task_uuid = isset( $_POST['task_uuid'] ) ? preg_replace( '/[^a-zA-Z0-9\-]/', '', (string) wp_unslash( $_POST['task_uuid'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		if ( '' === $task_uuid ) {
			wp_send_json_error( array( 'message' => __( 'Identifiant de génération manquant.', 'mr-tee-editor' ) ), 400 );
		}

		$task_meta = get_transient( 'mr_tee_ai_task_' . md5( $task_uuid ) );
		$remove_bg = is_array( $task_meta ) && ! empty( $task_meta['remove_bg'] );

		$result = RunwareClient::poll_task( $task_uuid );
		if ( is_wp_error( $result ) ) {
			wp_send_json_error( array( 'message' => $valid_admin ? $result->get_error_message() : self::friendly_storefront_error( $result ) ), 502 );
		}

		self::finalize_and_send_generation_response( $result, $remove_bg );
	}

	/**
	 * Admin-only test generation directly inside Product Visual Placement Stage
	 * (works even BEFORE saving the product so admin can fine-tune prompt & references live).
	 */
	public static function handle_admin_test_field() {
		self::verify_admin_request();

		$product_id  = isset( $_POST['product_id'] ) ? absint( $_POST['product_id'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Missing
		$model_id    = isset( $_POST['ai_model'] ) ? sanitize_key( (string) wp_unslash( $_POST['ai_model'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing
		$raw_prompt  = isset( $_POST['ai_prompt'] ) ? trim( sanitize_textarea_field( (string) wp_unslash( $_POST['ai_prompt'] ) ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing
		$size_mode   = ( isset( $_POST['ai_size_mode'] ) && 'match_reference' === $_POST['ai_size_mode'] ) ? 'match_reference' : 'auto_box'; // phpcs:ignore WordPress.Security.NonceVerification.Missing
		$remove_bg   = ! empty( $_POST['ai_remove_bg'] ); // phpcs:ignore WordPress.Security.NonceVerification.Missing
		$use_def_ref = ! empty( $_POST['ai_use_default_as_ref'] ); // phpcs:ignore WordPress.Security.NonceVerification.Missing
		$def_img_url = isset( $_POST['default_image'] ) ? esc_url_raw( trim( (string) wp_unslash( $_POST['default_image'] ) ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing
		$def_img_id  = isset( $_POST['default_image_id'] ) ? absint( $_POST['default_image_id'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Missing
		$box_w       = isset( $_POST['box_width'] ) ? max( 2.0, min( 100.0, floatval( $_POST['box_width'] ) ) ) : 40.0; // phpcs:ignore WordPress.Security.NonceVerification.Missing
		$box_h       = isset( $_POST['box_height'] ) ? max( 2.0, min( 100.0, floatval( $_POST['box_height'] ) ) ) : 30.0; // phpcs:ignore WordPress.Security.NonceVerification.Missing
		$stage_ratio = isset( $_POST['stage_ratio'] ) && floatval( $_POST['stage_ratio'] ) > 0 ? max( 0.25, min( 4.0, floatval( $_POST['stage_ratio'] ) ) ) : 1.0; // phpcs:ignore WordPress.Security.NonceVerification.Missing
		$task_uuid   = isset( $_POST['task_uuid'] ) ? preg_replace( '/[^a-zA-Z0-9\-]/', '', (string) wp_unslash( $_POST['task_uuid'] ) ) : wp_generate_uuid4(); // phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized

		$raw_refs = array();
		if ( ! empty( $_POST['ai_reference_images'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing
			$decoded = json_decode( (string) wp_unslash( $_POST['ai_reference_images'] ), true ); // phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
			if ( is_array( $decoded ) ) {
				$raw_refs = $decoded;
			}
		}

		$synthetic_line = array(
			'default_image'         => $def_img_url,
			'default_image_id'      => $def_img_id,
			'ai_use_default_as_ref' => $use_def_ref,
			'ai_reference_images'   => $raw_refs,
		);

		$customer_data_uri = self::extract_uploaded_customer_photo();
		if ( is_wp_error( $customer_data_uri ) ) {
			wp_send_json_error( array( 'message' => $customer_data_uri->get_error_message() ), 400 );
		}

		$ref_list       = self::collect_admin_reference_images( $synthetic_line );
		$customer_index = 0;
		if ( '' !== $customer_data_uri ) {
			$ref_list[]     = $customer_data_uri;
			$customer_index = count( $ref_list );
		}

		$prompt = self::resolve_prompt_tokens( $raw_prompt, $customer_index );
		if ( '' === $prompt && empty( $ref_list ) ) {
			wp_send_json_error( array( 'message' => __( 'Ajoutez un prompt prédéfini ou une image de référence avant de tester.', 'mr-tee-editor' ) ), 400 );
		}

		$model = AiRegistry::resolve_model( $model_id );
		if ( empty( $model ) ) {
			wp_send_json_error( array( 'message' => __( 'Aucun modèle IA sélectionné ou configuré.', 'mr-tee-editor' ) ), 400 );
		}

		$target_ratio = ( $box_w * $stage_ratio ) / max( 1.0, $box_h );

		set_transient(
			'mr_tee_ai_task_' . md5( $task_uuid ),
			array(
				'remove_bg' => $remove_bg,
				'created'   => time(),
			),
			15 * MINUTE_IN_SECONDS
		);

		$result = RunwareClient::generate_image(
			array(
				'model'            => $model,
				'prompt'           => $prompt,
				'reference_images' => $ref_list,
				'target_ratio'     => $target_ratio,
				'size_mode'        => $size_mode,
				'task_uuid'        => $task_uuid,
			)
		);

		if ( is_wp_error( $result ) ) {
			wp_send_json_error( array( 'message' => $result->get_error_message() ), 502 );
		}

		self::finalize_and_send_generation_response( $result, $remove_bg );
	}

	/**
	 * Admin AI Manager: save global Runware API key, default model, and cleanup days.
	 */
	public static function handle_admin_save_settings() {
		self::verify_admin_request();

		$update = array();
		if ( isset( $_POST['api_key'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing
			$raw_key = trim( (string) wp_unslash( $_POST['api_key'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
			// Only overwrite if user entered a non-empty key OR explicitly requested clearing it.
			if ( '' !== $raw_key ) {
				$update['api_key'] = $raw_key;
			} elseif ( ! empty( $_POST['clear_api_key'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing
				$update['api_key'] = '';
			}
		}
		if ( isset( $_POST['default_model_id'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing
			$update['default_model_id'] = sanitize_key( (string) wp_unslash( $_POST['default_model_id'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Missing
		}
		if ( isset( $_POST['cleanup_days'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing
			$update['cleanup_days'] = max( 1, min( 365, absint( $_POST['cleanup_days'] ) ) ); // phpcs:ignore WordPress.Security.NonceVerification.Missing
		}

		AiRegistry::save_settings( $update );

		wp_send_json_success(
			array(
				'message' => __( 'Paramètres IA enregistrés.', 'mr-tee-editor' ),
				'state'   => AiRegistry::get_admin_payload(),
			)
		);
	}

	/**
	 * Admin AI Manager: add or update an AI image generation model.
	 */
	public static function handle_admin_save_model() {
		self::verify_admin_request();

		$model_data = array(
			'editing_id'      => isset( $_POST['editing_id'] ) ? sanitize_key( (string) wp_unslash( $_POST['editing_id'] ) ) : '', // phpcs:ignore WordPress.Security.NonceVerification.Missing
			'name'            => isset( $_POST['name'] ) ? sanitize_text_field( (string) wp_unslash( $_POST['name'] ) ) : '', // phpcs:ignore WordPress.Security.NonceVerification.Missing
			'air_id'          => isset( $_POST['air_id'] ) ? sanitize_text_field( (string) wp_unslash( $_POST['air_id'] ) ) : '', // phpcs:ignore WordPress.Security.NonceVerification.Missing
			'quality'         => isset( $_POST['quality'] ) ? sanitize_text_field( (string) wp_unslash( $_POST['quality'] ) ) : '', // phpcs:ignore WordPress.Security.NonceVerification.Missing
			'dimensions'      => isset( $_POST['dimensions'] ) ? sanitize_text_field( (string) wp_unslash( $_POST['dimensions'] ) ) : '', // phpcs:ignore WordPress.Security.NonceVerification.Missing
			'negative_prompt' => isset( $_POST['negative_prompt'] ) ? sanitize_textarea_field( (string) wp_unslash( $_POST['negative_prompt'] ) ) : '', // phpcs:ignore WordPress.Security.NonceVerification.Missing
			'ref_format'      => isset( $_POST['ref_format'] ) ? sanitize_key( (string) wp_unslash( $_POST['ref_format'] ) ) : 'auto', // phpcs:ignore WordPress.Security.NonceVerification.Missing
		);
		$set_default = ! empty( $_POST['set_default'] ); // phpcs:ignore WordPress.Security.NonceVerification.Missing

		$saved = AiRegistry::upsert_model( $model_data, $set_default );
		if ( is_wp_error( $saved ) ) {
			wp_send_json_error( array( 'message' => $saved->get_error_message() ), 400 );
		}

		wp_send_json_success(
			array(
				'message' => __( 'Modèle IA enregistré avec succès.', 'mr-tee-editor' ),
				'model'   => $saved,
				'state'   => AiRegistry::get_admin_payload(),
			)
		);
	}

	/**
	 * Admin AI Manager: delete an AI model from the global list.
	 */
	public static function handle_admin_delete_model() {
		self::verify_admin_request();

		$model_id = isset( $_POST['model_id'] ) ? sanitize_key( (string) wp_unslash( $_POST['model_id'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing
		$res      = AiRegistry::delete_model( $model_id );
		if ( is_wp_error( $res ) ) {
			wp_send_json_error( array( 'message' => $res->get_error_message() ), 400 );
		}

		wp_send_json_success(
			array(
				'message' => __( 'Modèle IA supprimé.', 'mr-tee-editor' ),
				'state'   => AiRegistry::get_admin_payload(),
			)
		);
	}

	/**
	 * Admin AI Manager: quickly promote any model as global default.
	 */
	public static function handle_admin_set_default_model() {
		self::verify_admin_request();

		$model_id = isset( $_POST['model_id'] ) ? sanitize_key( (string) wp_unslash( $_POST['model_id'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing
		$models   = AiRegistry::get_models();
		if ( '' === $model_id || ! isset( $models[ $model_id ] ) ) {
			wp_send_json_error( array( 'message' => __( 'Modèle IA introuvable.', 'mr-tee-editor' ) ), 404 );
		}

		AiRegistry::save_settings( array( 'default_model_id' => $model_id ) );

		wp_send_json_success(
			array(
				'message' => __( 'Modèle par défaut mis à jour.', 'mr-tee-editor' ),
				'state'   => AiRegistry::get_admin_payload(),
			)
		);
	}

	/**
	 * Admin AI Manager: verify Runware API key via zero-cost test call.
	 */
	public static function handle_admin_test_connection() {
		self::verify_admin_request();

		$candidate_key = isset( $_POST['api_key'] ) ? trim( (string) wp_unslash( $_POST['api_key'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		$res           = RunwareClient::test_connection( $candidate_key );
		if ( is_wp_error( $res ) ) {
			wp_send_json_error( array( 'message' => $res->get_error_message() ), 400 );
		}

		wp_send_json_success( $res );
	}

	/**
	 * Complete post-processing (optional background removal + local sideload + WebP preview)
	 * and send clean JSON response.
	 *
	 * @param array<string, mixed> $result    RunwareClient response array.
	 * @param bool                 $remove_bg Whether to run Runware background removal.
	 */
	private static function finalize_and_send_generation_response( array $result, $remove_bg = false ) {
		// Mirror transient if RunwareClient rotated task_uuid during an automatic parameter retry.
		if ( ! empty( $result['task_uuid'] ) && (string) $result['task_uuid'] !== '' ) {
			set_transient(
				'mr_tee_ai_task_' . md5( (string) $result['task_uuid'] ),
				array(
					'remove_bg' => $remove_bg,
					'created'   => time(),
				),
				15 * MINUTE_IN_SECONDS
			);
		}

		if ( isset( $result['status'] ) && 'processing' === $result['status'] ) {
			wp_send_json_success(
				array(
					'status'    => 'processing',
					'task_uuid' => ! empty( $result['task_uuid'] ) ? (string) $result['task_uuid'] : '',
				)
			);
		}

		$image_url = ! empty( $result['image_url'] ) ? (string) $result['image_url'] : '';
		if ( '' === $image_url ) {
			wp_send_json_error( array( 'message' => __( 'Aucune image n\'a été retournée. Veuillez réessayer.', 'mr-tee-editor' ) ), 502 );
		}

		if ( $remove_bg ) {
			$bg_res = RunwareClient::remove_background( $image_url );
			if ( ! is_wp_error( $bg_res ) && ! empty( $bg_res['image_url'] ) ) {
				$image_url = (string) $bg_res['image_url'];
			}
		}

		$stored = AiMediaStore::sideload_generated_image( $image_url );
		if ( is_wp_error( $stored ) ) {
			wp_send_json_error( array( 'message' => $stored->get_error_message() ), 500 );
		}

		if ( ! empty( $result['task_uuid'] ) ) {
			delete_transient( 'mr_tee_ai_task_' . md5( (string) $result['task_uuid'] ) );
		}

		wp_send_json_success(
			array(
				'status'      => 'completed',
				'url'         => $stored['url'],
				'preview_url' => $stored['preview_url'],
				'filename'    => $stored['filename'],
				'width'       => $stored['width'],
				'height'      => $stored['height'],
				'task_uuid'   => ! empty( $result['task_uuid'] ) ? (string) $result['task_uuid'] : '',
			)
		);
	}

	/**
	 * Locate the matching `ai_image` field entry inside a product configuration.
	 *
	 * @param array<string, mixed> $config   Product config.
	 * @param string               $field_id Field ID.
	 * @param string               $ref_id   Reference ID.
	 * @return array<string, mixed>|null
	 */
	private static function find_ai_field_in_config( array $config, $field_id, $ref_id ) {
		if ( empty( $config['text_lines'] ) || ! is_array( $config['text_lines'] ) ) {
			return null;
		}
		foreach ( $config['text_lines'] as $line ) {
			if ( ! is_array( $line ) || empty( $line['field_type'] ) || 'ai_image' !== $line['field_type'] ) {
				continue;
			}
			if ( '' !== $field_id && isset( $line['id'] ) && (string) $line['id'] === $field_id ) {
				return $line;
			}
			if ( '' !== $ref_id && isset( $line['ref_id'] ) && (string) $line['ref_id'] === $ref_id ) {
				return $line;
			}
		}
		return null;
	}

	/**
	 * Read and validate customer uploaded photo from `$_FILES['customer_image']`.
	 *
	 * @return string|\WP_Error Compact data URI (empty string if no file sent).
	 */
	private static function extract_uploaded_customer_photo() {
		if ( empty( $_FILES['customer_image'] ) || ( isset( $_FILES['customer_image']['error'] ) && UPLOAD_ERR_NO_FILE === (int) $_FILES['customer_image']['error'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing
			return '';
		}

		$file = $_FILES['customer_image']; // phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		if ( ! empty( $file['error'] ) ) {
			return new \WP_Error( 'upload_error', __( 'Erreur lors de l\'envoi de votre photo.', 'mr-tee-editor' ) );
		}

		$max_bytes = 15 * 1024 * 1024;
		if ( ! empty( $file['size'] ) && (int) $file['size'] > $max_bytes ) {
			return new \WP_Error( 'file_too_large', __( 'Photo trop volumineuse (max 15 Mo).', 'mr-tee-editor' ) );
		}

		$raw = @file_get_contents( $file['tmp_name'] ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents, WordPress.PHP.NoSilencedErrors.Discouraged
		if ( false === $raw || '' === $raw ) {
			return new \WP_Error( 'unreadable_file', __( 'Impossible de lire votre photo.', 'mr-tee-editor' ) );
		}

		$data_uri = AiMediaStore::bytes_to_compact_data_uri( $raw, 1536 );
		if ( '' === $data_uri ) {
			return new \WP_Error( 'invalid_image', __( 'Le fichier sélectionné n\'est pas une image valide (PNG, JPG ou WebP).', 'mr-tee-editor' ) );
		}

		return $data_uri;
	}

	/**
	 * Collect ordered admin reference images as compact data URIs / URLs for Runware.
	 *
	 * @param array<string, mixed> $line Field config.
	 * @return string[]
	 */
	private static function collect_admin_reference_images( array $line ) {
		$refs = array();
		$seen = array();

		if ( ! empty( $line['ai_use_default_as_ref'] ) && ( ! empty( $line['default_image'] ) || ! empty( $line['default_image_id'] ) ) ) {
			$def_url = ! empty( $line['default_image'] ) ? (string) $line['default_image'] : '';
			$def_id  = ! empty( $line['default_image_id'] ) ? absint( $line['default_image_id'] ) : 0;
			$prep    = AiMediaStore::prepare_reference_image( $def_id, $def_url );
			if ( '' !== $prep ) {
				$refs[]                  = $prep;
				$seen[ md5( $def_url ) ] = true;
			}
		}

		if ( ! empty( $line['ai_reference_images'] ) && is_array( $line['ai_reference_images'] ) ) {
			foreach ( $line['ai_reference_images'] as $ref_item ) {
				if ( count( $refs ) >= 6 ) {
					break;
				}
				$r_id  = 0;
				$r_url = '';
				if ( is_array( $ref_item ) ) {
					$r_id  = ! empty( $ref_item['id'] ) ? absint( $ref_item['id'] ) : 0;
					$r_url = ! empty( $ref_item['url'] ) ? trim( (string) $ref_item['url'] ) : '';
				} elseif ( is_string( $ref_item ) ) {
					$r_url = trim( $ref_item );
				}
				if ( '' === $r_url && $r_id <= 0 ) {
					continue;
				}
				if ( '' !== $r_url && isset( $seen[ md5( $r_url ) ] ) ) {
					continue;
				}
				$prep = AiMediaStore::prepare_reference_image( $r_id, $r_url );
				if ( '' !== $prep ) {
					$refs[] = $prep;
					if ( '' !== $r_url ) {
						$seen[ md5( $r_url ) ] = true;
					}
				}
			}
		}

		return $refs;
	}

	/**
	 * Replace optional admin convenience tokens in predefined prompt (e.g. `{{customer_image}}`).
	 *
	 * @param string $prompt         Raw admin prompt.
	 * @param int    $customer_index 1-based position of customer photo inside referenceImages array (0 if none).
	 * @return string
	 */
	private static function resolve_prompt_tokens( $prompt, $customer_index = 0 ) {
		$out = trim( (string) $prompt );
		if ( $customer_index > 0 ) {
			$out = str_ireplace(
				array( '{{customer_image}}', '{{client_image}}', '{{uploaded_image}}' ),
				'image ' . $customer_index,
				$out
			);
		}
		return $out;
	}

	/**
	 * Compute the exact visual aspect ratio (width / height) of the admin-defined field box
	 * on its printable side's reference garment mockup (honoring `lock_aspect_ratio`, `flat_object`,
	 * per-side physical dimensions, and per-view reference placements) so Runware generates artwork
	 * matching the defined print area natively.
	 *
	 * @param int                  $product_id Product ID.
	 * @param array<string, mixed> $config     Product config.
	 * @param array<string, mixed> $line       Target AI field config.
	 * @return float
	 */
	private static function compute_field_visual_ratio( $product_id, array $config, array $line ) {
		$side_id  = ! empty( $line['side_id'] ) ? sanitize_key( (string) $line['side_id'] ) : 'front';
		$views    = ! empty( $config['preview_views'] ) && is_array( $config['preview_views'] ) ? $config['preview_views'] : array();
		$ref_view = SceneGraphSchema::get_side_reference_view( $views, $side_id );

		$box_w = isset( $line['box_width'] ) ? max( 1.0, floatval( $line['box_width'] ) ) : 40.0;
		$box_h = isset( $line['box_height'] ) ? max( 1.0, floatval( $line['box_height'] ) ) : 30.0;
		if ( is_array( $ref_view ) && ! empty( $ref_view['id'] ) && ! empty( $line['placements'][ $ref_view['id'] ] ) && is_array( $line['placements'][ $ref_view['id'] ] ) ) {
			$rp = $line['placements'][ $ref_view['id'] ];
			if ( isset( $rp['box_width'] ) && floatval( $rp['box_width'] ) > 0 ) {
				$box_w = max( 1.0, floatval( $rp['box_width'] ) );
			}
			if ( isset( $rp['box_height'] ) && floatval( $rp['box_height'] ) > 0 ) {
				$box_h = max( 1.0, floatval( $rp['box_height'] ) );
			}
		}

		$side_def = null;
		if ( ! empty( $config['print_sides'] ) && is_array( $config['print_sides'] ) ) {
			foreach ( $config['print_sides'] as $ps ) {
				if ( is_array( $ps ) && ! empty( $ps['id'] ) && (string) $ps['id'] === $side_id ) {
					$side_def = $ps;
					break;
				}
			}
		}

		$is_locked = ( null !== $side_def && array_key_exists( 'lock_aspect_ratio', $side_def ) )
			? ( ! empty( $side_def['lock_aspect_ratio'] ) || ( isset( $side_def['product_type'] ) && 'flat_object' === $side_def['product_type'] ) )
			: ( ! empty( $config['lock_aspect_ratio'] ) || ( isset( $config['product_type'] ) && 'flat_object' === $config['product_type'] ) );
		$side_rw   = ( null !== $side_def && ! empty( $side_def['print_real_width'] ) )
			? floatval( $side_def['print_real_width'] )
			: ( ! empty( $config['print_real_width'] ) ? floatval( $config['print_real_width'] ) : 0.0 );
		$side_rh   = ( null !== $side_def && ! empty( $side_def['print_real_height'] ) )
			? floatval( $side_def['print_real_height'] )
			: ( ! empty( $config['print_real_height'] ) ? floatval( $config['print_real_height'] ) : 0.0 );

		$stage_ratio = 0.0;
		if ( $is_locked && $side_rw > 0 && $side_rh > 0 ) {
			$stage_ratio = $side_rw / $side_rh;
		}

		if ( $stage_ratio <= 0 && is_array( $ref_view ) ) {
			$view_att_id = ! empty( $ref_view['image_id'] ) ? absint( $ref_view['image_id'] ) : 0;
			if ( $view_att_id <= 0 && ! empty( $ref_view['image_url'] ) && function_exists( 'attachment_url_to_postid' ) ) {
				$view_att_id = absint( attachment_url_to_postid( (string) $ref_view['image_url'] ) );
			}
			if ( $view_att_id > 0 && function_exists( 'wp_get_attachment_image_src' ) ) {
				$src = wp_get_attachment_image_src( $view_att_id, 'full' );
				if ( is_array( $src ) && ! empty( $src[1] ) && ! empty( $src[2] ) && (int) $src[2] > 0 ) {
					$stage_ratio = (float) $src[1] / (float) $src[2];
				}
			}
		}

		if ( $stage_ratio <= 0 && $product_id > 0 && function_exists( 'get_post_thumbnail_id' ) && function_exists( 'wp_get_attachment_image_src' ) ) {
			$thumb_id = get_post_thumbnail_id( $product_id );
			if ( $thumb_id ) {
				$src = wp_get_attachment_image_src( $thumb_id, 'full' );
				if ( is_array( $src ) && ! empty( $src[1] ) && ! empty( $src[2] ) && (int) $src[2] > 0 ) {
					$stage_ratio = (float) $src[1] / (float) $src[2];
				}
			}
		}

		if ( $stage_ratio <= 0 ) {
			if ( $side_rw > 0 && $side_rh > 0 ) {
				$stage_ratio = $side_rw / $side_rh;
			} else {
				$stage_ratio = ! empty( $config['print_aspect_ratio'] ) && floatval( $config['print_aspect_ratio'] ) > 0
					? floatval( $config['print_aspect_ratio'] )
					: 1.0;
			}
		}

		return max( 0.25, min( 4.0, ( $box_w * $stage_ratio ) / $box_h ) );
	}

	/**
	 * Convert technical Runware errors into clear, safe customer-facing messages without leaking internal details.
	 *
	 * @param \WP_Error $error Error object.
	 * @return string
	 */
	private static function friendly_storefront_error( \WP_Error $error ) {
		$code = $error->get_error_code();
		$msg  = strtolower( (string) $error->get_error_message() );

		if ( 'missing_api_key' === $code || 'missing_model' === $code ) {
			return __( 'Le générateur IA est momentanément indisponible. Veuillez réessayer plus tard.', 'mr-tee-editor' );
		}
		if ( false !== strpos( $msg, 'nsfw' ) || false !== strpos( $msg, 'safety' ) || false !== strpos( $msg, 'blocked' ) || false !== strpos( $msg, 'policy' ) ) {
			return __( 'Cette image ne peut pas être traitée par notre filtre de sécurité. Veuillez choisir une autre photo.', 'mr-tee-editor' );
		}
		if ( false !== strpos( $msg, 'timeout' ) || false !== strpos( $msg, 'timed out' ) ) {
			return __( 'La génération prend un peu plus de temps que prévu. Veuillez réessayer.', 'mr-tee-editor' );
		}
		return __( 'Impossible de générer l\'image pour le moment. Veuillez réessayer avec une photo nette.', 'mr-tee-editor' );
	}
}
