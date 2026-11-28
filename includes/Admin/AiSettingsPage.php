<?php
/**
 * Mr. Tee Editor - Dedicated General AI Manager (WP Admin Page + Reusable Metabox Panel)
 *
 * Modeled directly after the Font Manager:
 * - Standalone General Settings page under WooCommerce -> Mr. Tee IA
 * - Reusable `render_manager_panel()` embedded in the Product Configurator's "✨ IA" tab
 * - Manage global Runware API Key (masked, testable) + add/edit/delete multiple AI image
 *   generation models that become immediately selectable per AI Image field in every product.
 *
 * @package MrTeeEditor\Admin
 */

namespace MrTeeEditor\Admin;

use MrTeeEditor\Registry\AiRegistry;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class AiSettingsPage {

	const MENU_SLUG = 'mr-tee-ai-manager';

	/**
	 * Register WP Admin submenu & dedicated page assets.
	 */
	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'register_menu' ), 65 );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue_page_assets' ) );
	}

	/**
	 * Add "Mr. Tee IA" under WooCommerce menu (or Settings fallback if WooCommerce menu unavailable).
	 */
	public static function register_menu() {
		$parent = class_exists( 'WooCommerce' ) ? 'woocommerce' : 'options-general.php';
		add_submenu_page(
			$parent,
			__( 'Mr. Tee Editor — Gestionnaire IA', 'mr-tee-editor' ),
			__( 'Mr. Tee IA', 'mr-tee-editor' ),
			'manage_woocommerce',
			self::MENU_SLUG,
			array( __CLASS__, 'render_page' )
		);
	}

	/**
	 * Enqueue styles & standalone AI Manager script strictly on our dedicated AI Manager admin screen.
	 *
	 * @param string $hook Current admin screen hook.
	 */
	public static function enqueue_page_assets( $hook ) {
		if ( false === strpos( (string) $hook, self::MENU_SLUG ) ) {
			return;
		}

		$css_path = MR_TEE_EDITOR_DIR . 'assets/css/mr-tee-admin.css';
		$css_ver  = file_exists( $css_path ) ? (string) filemtime( $css_path ) : MR_TEE_EDITOR_VERSION;
		wp_enqueue_style(
			'mr-tee-admin-style',
			MR_TEE_EDITOR_URL . 'assets/css/mr-tee-admin.css',
			array(),
			$css_ver
		);

		$js_path = MR_TEE_EDITOR_DIR . 'assets/js/mr-tee-ai-manager.js';
		$js_ver  = file_exists( $js_path ) ? (string) filemtime( $js_path ) : MR_TEE_EDITOR_VERSION;
		wp_enqueue_script(
			'mr-tee-ai-manager-js',
			MR_TEE_EDITOR_URL . 'assets/js/mr-tee-ai-manager.js',
			array( 'jquery' ),
			$js_ver,
			true
		);

		$proto = is_ssl() ? 'https' : 'http';
		wp_localize_script(
			'mr-tee-ai-manager-js',
			'mrTeeAiManagerData',
			array(
				'ajaxUrl' => admin_url( 'admin-ajax.php', $proto ),
				'nonce'   => wp_create_nonce( 'mr_tee_admin_nonce' ),
				'state'   => AiRegistry::get_admin_payload(),
			)
		);
	}

	/**
	 * Render the standalone WP Admin General AI Manager page.
	 */
	public static function render_page() {
		if ( ! current_user_can( 'manage_woocommerce' ) && ! current_user_can( 'edit_products' ) ) {
			wp_die( esc_html__( 'Permissions insuffisantes.', 'mr-tee-editor' ) );
		}
		?>
		<div class="wrap mr-tee-ai-page-wrap">
			<div class="mr-tee-ai-page-header">
				<h1>
					<span aria-hidden="true">✨</span>
					<?php esc_html_e( 'Mr. Tee Editor — Gestionnaire IA (Runware)', 'mr-tee-editor' ); ?>
				</h1>
				<p class="description">
					<?php esc_html_e( 'Configurez votre clé API Runware et gérez tous vos modèles de génération d\'images IA. Chaque champ « Image IA » dans vos produits pourra ensuite sélectionner l\'un des modèles configurés ici.', 'mr-tee-editor' ); ?>
				</p>
			</div>

			<div class="mr-tee-admin-wrap mr-tee-ai-standalone-container">
				<?php self::render_manager_panel( 'page' ); ?>
			</div>
		</div>
		<?php
	}

	/**
	 * Render the reusable AI Manager UI panel (used on BOTH the General Settings page AND
	 * the "✨ IA" tab inside the Product Configurator metabox — just like the Font Manager).
	 *
	 * @param string $context 'page' | 'metabox'.
	 */
	public static function render_manager_panel( $context = 'metabox' ) {
		$settings       = AiRegistry::get_settings();
		$has_key        = AiRegistry::has_api_key();
		$masked_key     = AiRegistry::get_masked_api_key();
		$models         = AiRegistry::get_models();
		$default_id     = AiRegistry::get_default_model_id();
		$cleanup_days   = ! empty( $settings['cleanup_days'] ) ? (int) $settings['cleanup_days'] : 14;
		$page_url       = admin_url( ( class_exists( 'WooCommerce' ) ? 'admin.php' : 'options-general.php' ) . '?page=' . self::MENU_SLUG );
		?>
		<div class="mr-tee-ai-manager-panel" data-ai-context="<?php echo esc_attr( $context ); ?>">
			<!-- 1. Runware API Credentials & Global Defaults Card -->
			<div class="mr-tee-ai-card mr-tee-ai-credentials-card">
				<div class="mr-tee-ai-card-header">
					<div>
						<h4 class="mr-tee-ai-card-title">
							<span aria-hidden="true">🔑</span> <?php esc_html_e( 'Clé API Runware & Paramètres Globaux', 'mr-tee-editor' ); ?>
						</h4>
						<p class="description">
							<?php esc_html_e( 'Une seule clé API Runware donne accès à tous vos modèles (Google Nano Banana, ByteDance Seedream, OpenAI GPT Image, BFL FLUX, etc.). La clé est stockée uniquement côté serveur.', 'mr-tee-editor' ); ?>
						</p>
					</div>
					<div class="mr-tee-ai-header-badges">
						<span class="mr-tee-ai-key-status-pill <?php echo $has_key ? 'is-configured' : 'is-missing'; ?>" data-ai-key-pill>
							<?php
							echo $has_key
								? esc_html( sprintf( __( '✓ Clé active (%s)', 'mr-tee-editor' ), $masked_key ) )
								: esc_html__( '⚠️ Clé API non configurée', 'mr-tee-editor' );
							?>
						</span>
						<?php if ( 'metabox' === $context ) : ?>
							<a href="<?php echo esc_url( $page_url ); ?>" target="_blank" rel="noopener noreferrer" class="button button-small">
								<?php esc_html_e( 'Ouvrir en pleine page ↗', 'mr-tee-editor' ); ?>
							</a>
						<?php endif; ?>
					</div>
				</div>

				<div class="mr-tee-ai-credentials-grid">
					<div class="mr-tee-ai-input-col mr-tee-ai-input-col-wide">
						<label class="mr-tee-ai-field-label" for="mr_tee_ai_api_key_input_<?php echo esc_attr( $context ); ?>">
							<?php esc_html_e( 'Clé API Runware (Bearer Token)', 'mr-tee-editor' ); ?>
						</label>
						<div class="mr-tee-ai-key-row">
							<input
								type="password"
								id="mr_tee_ai_api_key_input_<?php echo esc_attr( $context ); ?>"
								class="regular-text mr-tee-ai-api-key-input"
								autocomplete="new-password"
								placeholder="<?php echo $has_key ? esc_attr__( 'Laisser vide pour conserver la clé enregistrée (ou coller une nouvelle clé)...', 'mr-tee-editor' ) : esc_attr__( 'Collez votre clé API Runware (https://my.runware.ai)...', 'mr-tee-editor' ); ?>"
							/>
							<button type="button" class="button button-primary mr-tee-ai-btn-save-settings">
								<?php esc_html_e( 'Enregistrer', 'mr-tee-editor' ); ?>
							</button>
							<button type="button" class="button button-secondary mr-tee-ai-btn-test-key">
								<span aria-hidden="true">⚡</span> <?php esc_html_e( 'Tester la connexion', 'mr-tee-editor' ); ?>
							</button>
						</div>
					</div>

					<div class="mr-tee-ai-input-col">
						<label class="mr-tee-ai-field-label" for="mr_tee_ai_default_model_<?php echo esc_attr( $context ); ?>">
							<?php esc_html_e( 'Modèle par défaut (global)', 'mr-tee-editor' ); ?>
						</label>
						<select id="mr_tee_ai_default_model_<?php echo esc_attr( $context ); ?>" class="mr-tee-ai-default-model-select">
							<?php foreach ( $models as $m_id => $m ) : ?>
								<option value="<?php echo esc_attr( $m_id ); ?>" <?php selected( $default_id, $m_id ); ?>>
									<?php echo esc_html( $m['name'] . ' [' . $m['air_id'] . ']' ); ?>
								</option>
							<?php endforeach; ?>
						</select>
					</div>

					<div class="mr-tee-ai-input-col mr-tee-ai-input-col-narrow">
						<label class="mr-tee-ai-field-label" for="mr_tee_ai_cleanup_days_<?php echo esc_attr( $context ); ?>">
							<?php esc_html_e( 'Nettoyage auto (jours)', 'mr-tee-editor' ); ?>
						</label>
						<input
							type="number"
							min="1"
							max="365"
							id="mr_tee_ai_cleanup_days_<?php echo esc_attr( $context ); ?>"
							class="small-text mr-tee-ai-cleanup-days-input"
							value="<?php echo esc_attr( $cleanup_days ); ?>"
							title="<?php esc_attr_e( 'Supprime les images IA temporaires non commandées après ce nombre de jours', 'mr-tee-editor' ); ?>"
						/>
					</div>
				</div>
				<div class="mr-tee-ai-settings-status" aria-live="polite"></div>
			</div>

			<!-- 2. Add / Edit AI Image Generation Model Card (Modeled after Font Importer) -->
			<div class="mr-tee-ai-card mr-tee-ai-add-model-card">
				<div class="mr-tee-ai-card-header">
					<div>
						<h4 class="mr-tee-ai-card-title mr-tee-ai-form-heading">
							<span aria-hidden="true">➕</span> <?php esc_html_e( 'Ajouter un Modèle de Génération d\'Image IA', 'mr-tee-editor' ); ?>
						</h4>
						<p class="description">
							<?php esc_html_e( 'Ajoutez autant de modèles Runware que souhaité (ex : google:nano-banana@2.1, bytedance:seedream@5.5, openai:gpt-image@2, bfl:flux@3-image, runware:100@1). Chaque champ « Image IA » du produit pourra sélectionner son propre modèle.', 'mr-tee-editor' ); ?>
						</p>
					</div>
				</div>

				<div class="mr-tee-ai-model-form" data-editing-id="">
					<div class="mr-tee-ai-model-form-grid">
						<div>
							<label class="mr-tee-ai-field-label"><?php esc_html_e( 'Nom du modèle *', 'mr-tee-editor' ); ?></label>
							<input type="text" class="regular-text mr-tee-ai-mf-name" placeholder="<?php esc_attr_e( 'Ex : Nano Banana 2.1 (Swap Personne)', 'mr-tee-editor' ); ?>" />
						</div>
						<div>
							<label class="mr-tee-ai-field-label"><?php esc_html_e( 'Model ID (Runware AIR ID) *', 'mr-tee-editor' ); ?></label>
							<input type="text" class="regular-text mr-tee-ai-mf-air" placeholder="<?php esc_attr_e( 'Ex : google:nano-banana@2.1', 'mr-tee-editor' ); ?>" />
						</div>
						<div>
							<label class="mr-tee-ai-field-label"><?php esc_html_e( 'Qualité / Résolution / Steps', 'mr-tee-editor' ); ?></label>
							<input type="text" class="regular-text mr-tee-ai-mf-quality" placeholder="<?php esc_attr_e( 'Ex : 1k, 2k, high, ou 28', 'mr-tee-editor' ); ?>" />
						</div>
						<div>
							<label class="mr-tee-ai-field-label"><?php esc_html_e( 'Format des images de référence', 'mr-tee-editor' ); ?></label>
							<select class="mr-tee-ai-mf-ref-format">
								<option value="auto"><?php esc_html_e( 'Auto (Recommandé)', 'mr-tee-editor' ); ?></option>
								<option value="inputs"><?php esc_html_e( 'Moderne — inputs.referenceImages (Nano Banana, Seedream, GPT, FLUX 3)', 'mr-tee-editor' ); ?></option>
								<option value="legacy"><?php esc_html_e( 'Classique — referenceImages / seedImage (FLUX.1, SDXL)', 'mr-tee-editor' ); ?></option>
							</select>
						</div>
						<div class="mr-tee-ai-mf-span2">
							<label class="mr-tee-ai-field-label"><?php esc_html_e( 'Dimensions supportées (optionnel, séparées par virgules)', 'mr-tee-editor' ); ?></label>
							<input type="text" class="regular-text mr-tee-ai-mf-dimensions" placeholder="<?php esc_attr_e( 'Ex : 1024x1024, 896x1200, 1200x896, 848x1264 (laisser vide pour détection auto)', 'mr-tee-editor' ); ?>" />
						</div>
						<div class="mr-tee-ai-mf-span2">
							<label class="mr-tee-ai-field-label"><?php esc_html_e( 'Negative Prompt par défaut (optionnel — modèles Diffusion)', 'mr-tee-editor' ); ?></label>
							<textarea rows="2" class="large-text mr-tee-ai-mf-neg" placeholder="<?php esc_attr_e( 'Optionnel : blurry, low quality, watermark...', 'mr-tee-editor' ); ?>"></textarea>
						</div>
					</div>
					<div class="mr-tee-ai-model-form-actions">
						<label class="mr-tee-inline-check">
							<input type="checkbox" class="mr-tee-ai-mf-set-default" value="1" />
							<?php esc_html_e( 'Définir comme modèle global par défaut', 'mr-tee-editor' ); ?>
						</label>
						<div class="mr-tee-ai-model-form-btns">
							<button type="button" class="button button-secondary mr-tee-ai-mf-cancel" style="display:none;">
								<?php esc_html_e( 'Annuler la modification', 'mr-tee-editor' ); ?>
							</button>
							<button type="button" class="button button-primary mr-tee-ai-mf-submit">
								<span aria-hidden="true">✨</span> <?php esc_html_e( 'Ajouter le modèle IA', 'mr-tee-editor' ); ?>
							</button>
						</div>
					</div>
					<div class="mr-tee-ai-model-form-status" aria-live="polite"></div>
				</div>
			</div>

			<!-- 3. Registered AI Models Library Grid (Just like Font Library Grid) -->
			<div class="mr-tee-ai-card mr-tee-ai-models-library-card">
				<div class="mr-tee-ai-card-header">
					<div>
						<h4 class="mr-tee-ai-card-title">
							<span aria-hidden="true">🤖</span>
							<?php esc_html_e( 'Bibliothèque des Modèles IA Configurés', 'mr-tee-editor' ); ?>
							(<span class="mr-tee-ai-models-count"><?php echo esc_html( count( $models ) ); ?></span>)
						</h4>
						<p class="description">
							<?php esc_html_e( 'Tous ces modèles sont immédiatement disponibles dans le sélecteur « Modèle IA » de chaque champ « ✨ Image IA » de vos produits.', 'mr-tee-editor' ); ?>
						</p>
					</div>
					<?php if ( 'metabox' === $context ) : ?>
						<button type="button" class="button button-small mr-tee-ai-btn-back-to-studio">
							← <?php esc_html_e( 'Retour au Studio Visuel', 'mr-tee-editor' ); ?>
						</button>
					<?php endif; ?>
				</div>

				<div class="mr-tee-ai-models-grid">
					<?php foreach ( $models as $m_id => $m ) : ?>
						<?php
						$is_def = ( $m_id === $default_id );
						?>
						<div class="mr-tee-ai-model-card <?php echo $is_def ? 'is-default' : ''; ?>" data-model-id="<?php echo esc_attr( $m_id ); ?>">
							<div class="mr-tee-ai-model-card-top">
								<div class="mr-tee-ai-model-card-title-wrap">
									<strong class="mr-tee-ai-model-card-name"><?php echo esc_html( $m['name'] ); ?></strong>
									<code class="mr-tee-ai-model-card-air"><?php echo esc_html( $m['air_id'] ); ?></code>
								</div>
								<div class="mr-tee-ai-model-card-badges">
									<?php if ( $is_def ) : ?>
										<span class="mr-tee-ai-badge is-default"><?php esc_html_e( '★ Défaut', 'mr-tee-editor' ); ?></span>
									<?php endif; ?>
									<?php if ( ! empty( $m['quality'] ) ) : ?>
										<span class="mr-tee-ai-badge"><?php echo esc_html( strtoupper( $m['quality'] ) ); ?></span>
									<?php endif; ?>
								</div>
							</div>
							<?php if ( ! empty( $m['dimensions'] ) ) : ?>
								<div class="mr-tee-ai-model-card-dims">
									<span><?php esc_html_e( 'Formats :', 'mr-tee-editor' ); ?></span>
									<?php echo esc_html( $m['dimensions'] ); ?>
								</div>
							<?php endif; ?>
							<div class="mr-tee-ai-model-card-actions">
								<?php if ( ! $is_def ) : ?>
									<button type="button" class="button button-small mr-tee-ai-card-set-default" data-model-id="<?php echo esc_attr( $m_id ); ?>">
										★ <?php esc_html_e( 'Par défaut', 'mr-tee-editor' ); ?>
									</button>
								<?php endif; ?>
								<button type="button" class="button button-small mr-tee-ai-card-edit" data-model-id="<?php echo esc_attr( $m_id ); ?>">
									✏️ <?php esc_html_e( 'Modifier', 'mr-tee-editor' ); ?>
								</button>
								<button type="button" class="button button-small mr-tee-ai-card-delete" data-model-id="<?php echo esc_attr( $m_id ); ?>">
									✕ <?php esc_html_e( 'Supprimer', 'mr-tee-editor' ); ?>
								</button>
							</div>
						</div>
					<?php endforeach; ?>
				</div>
			</div>
		</div>
		<?php
	}
}
