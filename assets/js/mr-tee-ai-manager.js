/**
 * Mr. Tee Editor — Dedicated AI Manager Controller (WP Admin)
 *
 * Powers BOTH:
 * 1. Standalone General AI Settings page (WooCommerce -> Mr. Tee IA)
 * 2. Product Configurator "✨ IA" tab (right beside "🔤 Polices" Font Manager)
 *
 * Keeps all AI model dropdowns across every `✨ Champ Image IA` field synchronized
 * live whenever a model is added, edited, deleted, or promoted to default.
 */
(function($) {
	'use strict';

	function getConfig() {
		if (window.mrTeeAiManagerData) {
			return {
				ajaxUrl: window.mrTeeAiManagerData.ajaxUrl,
				nonce: window.mrTeeAiManagerData.nonce,
				state: window.mrTeeAiManagerData.state || {},
			};
		}
		if (window.mrTeeAdminData) {
			return {
				ajaxUrl: window.mrTeeAdminData.ajaxUrl,
				nonce: window.mrTeeAdminData.nonce,
				state: window.mrTeeAdminData.ai || {},
			};
		}
		return null;
	}

	function escapeHtml(str) {
		return String(str === undefined || str === null ? '' : str)
			.replace(/&/g, '&amp;')
			.replace(/</g, '&lt;')
			.replace(/>/g, '&gt;')
			.replace(/"/g, '&quot;')
			.replace(/'/g, '&#039;');
	}

	function setStatus($el, message, type) {
		if (!$el || !$el.length) return;
		$el.removeClass('is-ok is-error is-loading').text(message || '');
		if (type) {
			$el.addClass('is-' + type);
		}
	}

	function syncGlobalState(newState) {
		if (!newState || typeof newState !== 'object') return;
		if (window.mrTeeAiManagerData) {
			window.mrTeeAiManagerData.state = newState;
		}
		if (window.mrTeeAdminData) {
			window.mrTeeAdminData.ai = newState;
		}

		// Update all AI Manager panels on the page
		$('.mr-tee-ai-manager-panel').each(function() {
			renderPanelFromState($(this), newState);
		});

		// Update Product Configurator tab badge count
		const count = Array.isArray(newState.models) ? newState.models.length : 0;
		$('#mr-tee-tab-ai-badge').text(count);

		// Sync every `ai_image` field's model <select> & API key warning in Visual Placement Stage live
		syncAllFieldModelDropdowns(newState);
		$('.mr-tee-ai-key-warn').toggle(!newState.hasApiKey);

		// Notify any external listeners
		$(document).trigger('mrTeeAiStateUpdated', [newState]);
	}

	function syncAllFieldModelDropdowns(state) {
		const models = Array.isArray(state.models) ? state.models : [];
		const defId = state.defaultModelId || '';
		const defModel = models.find(m => m && m.id === defId) || models[0] || null;
		const defLabel = defModel ? `Par défaut (${defModel.name})` : 'Par défaut (Réglages IA)';

		$('.mr-tee-field-ai-model').each(function() {
			const $sel = $(this);
			const prevVal = $sel.val() || '';
			let html = `<option value="">${escapeHtml(defLabel)}</option>`;
			models.forEach(m => {
				if (!m || !m.id) return;
				const label = `${m.name} [${m.air_id}]`;
				const selected = (prevVal === m.id) ? ' selected' : '';
				html += `<option value="${escapeHtml(m.id)}"${selected}>${escapeHtml(label)}</option>`;
			});
			$sel.html(html);
		});
	}

	function renderPanelFromState($panel, state) {
		const models = Array.isArray(state.models) ? state.models : [];
		const defId = state.defaultModelId || '';

		// 1. Key status pill
		const $pill = $panel.find('[data-ai-key-pill]');
		if (state.hasApiKey) {
			$pill
				.removeClass('is-missing')
				.addClass('is-configured')
				.text(`✓ Clé active (${state.maskedApiKey || '••••'})`);
			$panel.find('.mr-tee-ai-api-key-input').attr('placeholder', 'Laisser vide pour conserver la clé enregistrée (ou coller une nouvelle clé)...');
		} else {
			$pill
				.removeClass('is-configured')
				.addClass('is-missing')
				.text('⚠️ Clé API non configurée');
		}

		// 2. Global default model select
		const $defSel = $panel.find('.mr-tee-ai-default-model-select');
		if ($defSel.length) {
			let optsHtml = '';
			models.forEach(m => {
				if (!m || !m.id) return;
				const sel = m.id === defId ? ' selected' : '';
				optsHtml += `<option value="${escapeHtml(m.id)}"${sel}>${escapeHtml(m.name)} [${escapeHtml(m.air_id)}]</option>`;
			});
			$defSel.html(optsHtml);
		}

		// 3. Cleanup days
		if (state.cleanupDays) {
			$panel.find('.mr-tee-ai-cleanup-days-input').val(state.cleanupDays);
		}

		// 4. Models count & grid
		$panel.find('.mr-tee-ai-models-count').text(models.length);
		const $grid = $panel.find('.mr-tee-ai-models-grid');
		if (!$grid.length) return;

		if (!models.length) {
			$grid.html('<p class="description">Aucun modèle IA configuré. Utilisez le formulaire ci-dessus pour ajouter votre premier modèle Runware.</p>');
			return;
		}

		let gridHtml = '';
		models.forEach(m => {
			if (!m || !m.id) return;
			const isDef = m.id === defId;
			gridHtml += `
				<div class="mr-tee-ai-model-card ${isDef ? 'is-default' : ''}" data-model-id="${escapeHtml(m.id)}">
					<div class="mr-tee-ai-model-card-top">
						<div class="mr-tee-ai-model-card-title-wrap">
							<strong class="mr-tee-ai-model-card-name">${escapeHtml(m.name)}</strong>
							<code class="mr-tee-ai-model-card-air">${escapeHtml(m.air_id)}</code>
						</div>
						<div class="mr-tee-ai-model-card-badges">
							${isDef ? '<span class="mr-tee-ai-badge is-default">★ Défaut</span>' : ''}
							${m.quality ? `<span class="mr-tee-ai-badge">${escapeHtml(String(m.quality).toUpperCase())}</span>` : ''}
						</div>
					</div>
					${m.dimensions ? `<div class="mr-tee-ai-model-card-dims"><span>Formats :</span> ${escapeHtml(m.dimensions)}</div>` : ''}
					<div class="mr-tee-ai-model-card-actions">
						${!isDef ? `<button type="button" class="button button-small mr-tee-ai-card-set-default" data-model-id="${escapeHtml(m.id)}">★ Par défaut</button>` : ''}
						<button type="button" class="button button-small mr-tee-ai-card-edit" data-model-id="${escapeHtml(m.id)}">✏️ Modifier</button>
						<button type="button" class="button button-small mr-tee-ai-card-delete" data-model-id="${escapeHtml(m.id)}">✕ Supprimer</button>
					</div>
				</div>
			`;
		});
		$grid.html(gridHtml);
	}

	function resetModelForm($panel) {
		const $form = $panel.find('.mr-tee-ai-model-form');
		$form.attr('data-editing-id', '');
		$form.find('.mr-tee-ai-mf-name').val('');
		$form.find('.mr-tee-ai-mf-air').val('');
		$form.find('.mr-tee-ai-mf-quality').val('');
		$form.find('.mr-tee-ai-mf-ref-format').val('auto');
		$form.find('.mr-tee-ai-mf-dimensions').val('');
		$form.find('.mr-tee-ai-mf-neg').val('');
		$form.find('.mr-tee-ai-mf-set-default').prop('checked', false);
		$panel.find('.mr-tee-ai-form-heading').html('<span aria-hidden="true">➕</span> Ajouter un Modèle de Génération d\'Image IA');
		$form.find('.mr-tee-ai-mf-submit').html('<span aria-hidden="true">✨</span> Ajouter le modèle IA');
		$form.find('.mr-tee-ai-mf-cancel').hide();
	}

	function initAiManager() {
		if (window.__mrTeeAiManagerBound) return;
		window.__mrTeeAiManagerBound = true;

		// Save Global Runware API Key, Default Model & Cleanup Days
		$(document).on('click', '.mr-tee-ai-btn-save-settings', function() {
			const cfg = getConfig();
			if (!cfg) return;

			const $btn = $(this);
			const $panel = $btn.closest('.mr-tee-ai-manager-panel');
			const $status = $panel.find('.mr-tee-ai-settings-status');
			const $keyInput = $panel.find('.mr-tee-ai-api-key-input');

			$btn.prop('disabled', true);
			setStatus($status, 'Enregistrement en cours...', 'loading');

			$.post(cfg.ajaxUrl, {
				action: 'mr_tee_ai_save_settings',
				nonce: cfg.nonce,
				api_key: ($keyInput.val() || '').trim(),
				default_model_id: $panel.find('.mr-tee-ai-default-model-select').val() || '',
				cleanup_days: $panel.find('.mr-tee-ai-cleanup-days-input').val() || 14,
			})
				.done(function(resp) {
					if (resp && resp.success && resp.data) {
						$keyInput.val('');
						syncGlobalState(resp.data.state);
						setStatus($status, resp.data.message || 'Paramètres enregistrés.', 'ok');
					} else {
						setStatus($status, (resp && resp.data && resp.data.message) || 'Erreur lors de l\'enregistrement.', 'error');
					}
				})
				.fail(function(xhr) {
					const msg = (xhr && xhr.responseJSON && xhr.responseJSON.data && xhr.responseJSON.data.message) || 'Erreur réseau lors de l\'enregistrement.';
					setStatus($status, '✕ ' + msg, 'error');
				})
				.always(function() {
					$btn.prop('disabled', false);
				});
		});

		// Instant save when changing default model dropdown
		$(document).on('change', '.mr-tee-ai-default-model-select', function() {
			const cfg = getConfig();
			if (!cfg) return;
			const $sel = $(this);
			const $panel = $sel.closest('.mr-tee-ai-manager-panel');
			const $status = $panel.find('.mr-tee-ai-settings-status');
			const modelId = $sel.val() || '';
			if (!modelId) return;

			$.post(cfg.ajaxUrl, {
				action: 'mr_tee_ai_set_default_model',
				nonce: cfg.nonce,
				model_id: modelId,
			}).done(function(resp) {
				if (resp && resp.success && resp.data) {
					syncGlobalState(resp.data.state);
					setStatus($status, resp.data.message || 'Modèle par défaut mis à jour.', 'ok');
				}
			});
		});

		// Test Runware API Connection
		$(document).on('click', '.mr-tee-ai-btn-test-key', function() {
			const cfg = getConfig();
			if (!cfg) return;

			const $btn = $(this);
			const $panel = $btn.closest('.mr-tee-ai-manager-panel');
			const $status = $panel.find('.mr-tee-ai-settings-status');
			const typedKey = ($panel.find('.mr-tee-ai-api-key-input').val() || '').trim();

			$btn.prop('disabled', true);
			setStatus($status, 'Test de connexion à l\'API Runware...', 'loading');

			$.post(cfg.ajaxUrl, {
				action: 'mr_tee_ai_test_connection',
				nonce: cfg.nonce,
				api_key: typedKey,
			})
				.done(function(resp) {
					if (resp && resp.success && resp.data) {
						setStatus($status, '✓ ' + (resp.data.message || 'Connexion réussie !'), 'ok');
					} else {
						setStatus($status, '✕ ' + ((resp && resp.data && resp.data.message) || 'Échec de connexion Runware.'), 'error');
					}
				})
				.fail(function(xhr) {
					const msg = (xhr && xhr.responseJSON && xhr.responseJSON.data && xhr.responseJSON.data.message) || 'Impossible de joindre le serveur.';
					setStatus($status, '✕ ' + msg, 'error');
				})
				.always(function() {
					$btn.prop('disabled', false);
				});
		});

		// Add or Update AI Model
		$(document).on('click', '.mr-tee-ai-mf-submit', function() {
			const cfg = getConfig();
			if (!cfg) return;

			const $btn = $(this);
			const $panel = $btn.closest('.mr-tee-ai-manager-panel');
			const $form = $panel.find('.mr-tee-ai-model-form');
			const $status = $panel.find('.mr-tee-ai-model-form-status');

			const name = ($form.find('.mr-tee-ai-mf-name').val() || '').trim();
			const airId = ($form.find('.mr-tee-ai-mf-air').val() || '').trim();
			if (!airId) {
				setStatus($status, 'Veuillez renseigner le Model ID (AIR ID, ex : google:nano-banana@2.1).', 'error');
				$form.find('.mr-tee-ai-mf-air').trigger('focus');
				return;
			}

			$btn.prop('disabled', true);
			setStatus($status, 'Enregistrement du modèle IA...', 'loading');

			$.post(cfg.ajaxUrl, {
				action: 'mr_tee_ai_save_model',
				nonce: cfg.nonce,
				editing_id: $form.attr('data-editing-id') || '',
				name: name || airId,
				air_id: airId,
				quality: ($form.find('.mr-tee-ai-mf-quality').val() || '').trim(),
				ref_format: $form.find('.mr-tee-ai-mf-ref-format').val() || 'auto',
				dimensions: ($form.find('.mr-tee-ai-mf-dimensions').val() || '').trim(),
				negative_prompt: ($form.find('.mr-tee-ai-mf-neg').val() || '').trim(),
				set_default: $form.find('.mr-tee-ai-mf-set-default').is(':checked') ? 1 : 0,
			})
				.done(function(resp) {
					if (resp && resp.success && resp.data) {
						syncGlobalState(resp.data.state);
						resetModelForm($panel);
						setStatus($status, '✓ ' + (resp.data.message || 'Modèle IA enregistré.'), 'ok');
					} else {
						setStatus($status, '✕ ' + ((resp && resp.data && resp.data.message) || 'Erreur lors de l\'enregistrement.'), 'error');
					}
				})
				.fail(function(xhr) {
					const msg = (xhr && xhr.responseJSON && xhr.responseJSON.data && xhr.responseJSON.data.message) || 'Erreur réseau.';
					setStatus($status, '✕ ' + msg, 'error');
				})
				.always(function() {
					$btn.prop('disabled', false);
				});
		});

		// Cancel Editing Model
		$(document).on('click', '.mr-tee-ai-mf-cancel', function() {
			const $panel = $(this).closest('.mr-tee-ai-manager-panel');
			resetModelForm($panel);
			setStatus($panel.find('.mr-tee-ai-model-form-status'), '', '');
		});

		// Edit Model Card -> populate form
		$(document).on('click', '.mr-tee-ai-card-edit', function() {
			const cfg = getConfig();
			if (!cfg || !cfg.state || !Array.isArray(cfg.state.models)) return;

			const modelId = $(this).attr('data-model-id') || '';
			const model = cfg.state.models.find(m => m && m.id === modelId);
			if (!model) return;

			const $panel = $(this).closest('.mr-tee-ai-manager-panel');
			const $form = $panel.find('.mr-tee-ai-model-form');

			$form.attr('data-editing-id', model.id);
			$form.find('.mr-tee-ai-mf-name').val(model.name || '');
			$form.find('.mr-tee-ai-mf-air').val(model.air_id || '');
			$form.find('.mr-tee-ai-mf-quality').val(model.quality || '');
			$form.find('.mr-tee-ai-mf-ref-format').val(model.ref_format || 'auto');
			$form.find('.mr-tee-ai-mf-dimensions').val(model.dimensions || '');
			$form.find('.mr-tee-ai-mf-neg').val(model.negative_prompt || '');
			$form.find('.mr-tee-ai-mf-set-default').prop('checked', model.id === cfg.state.defaultModelId);

			$panel.find('.mr-tee-ai-form-heading').html(`<span aria-hidden="true">✏️</span> Modifier le modèle : ${escapeHtml(model.name)}`);
			$form.find('.mr-tee-ai-mf-submit').html('<span aria-hidden="true">💾</span> Mettre à jour le modèle');
			$form.find('.mr-tee-ai-mf-cancel').show();
			$form.find('.mr-tee-ai-mf-name').trigger('focus');
		});

		// Set Default Model from Card
		$(document).on('click', '.mr-tee-ai-card-set-default', function() {
			const cfg = getConfig();
			if (!cfg) return;

			const $btn = $(this);
			const modelId = $btn.attr('data-model-id') || '';
			if (!modelId) return;

			$btn.prop('disabled', true);
			$.post(cfg.ajaxUrl, {
				action: 'mr_tee_ai_set_default_model',
				nonce: cfg.nonce,
				model_id: modelId,
			})
				.done(function(resp) {
					if (resp && resp.success && resp.data) {
						syncGlobalState(resp.data.state);
					}
				})
				.always(function() {
					$btn.prop('disabled', false);
				});
		});

		// Delete Model from Card
		$(document).on('click', '.mr-tee-ai-card-delete', function() {
			const cfg = getConfig();
			if (!cfg) return;

			const $btn = $(this);
			const modelId = $btn.attr('data-model-id') || '';
			if (!modelId) return;

			const cardName = $btn.closest('.mr-tee-ai-model-card').find('.mr-tee-ai-model-card-name').text() || modelId;
			if (!window.confirm(`Supprimer le modèle IA « ${cardName} » ?`)) {
				return;
			}

			$btn.prop('disabled', true);
			$.post(cfg.ajaxUrl, {
				action: 'mr_tee_ai_delete_model',
				nonce: cfg.nonce,
				model_id: modelId,
			})
				.done(function(resp) {
					if (resp && resp.success && resp.data) {
						syncGlobalState(resp.data.state);
					} else {
						window.alert((resp && resp.data && resp.data.message) || 'Impossible de supprimer ce modèle.');
					}
				})
				.always(function() {
					$btn.prop('disabled', false);
				});
		});

		// Metabox convenience button: Back to Visual Studio tab
		$(document).on('click', '.mr-tee-ai-btn-back-to-studio', function() {
			$('#mr-tee-tab-btn-stage').trigger('click');
		});

		// Metabox convenience link inside AI field inspector: Jump to AI Manager tab
		$(document).on('click', '.mr-tee-btn-open-ai-manager-tab', function(e) {
			e.preventDefault();
			$('#mr-tee-tab-btn-ai').trigger('click');
		});
	}

	$(document).ready(initAiManager);
})(jQuery);
