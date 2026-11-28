/**
 * Mr. Tee Editor - Storefront AI Image Field Controller (Lazy-Loaded ES Module)
 *
 * Loaded strictly on demand ONLY when a product page contains one or more `ai_image` fields.
 * Zero bytes downloaded on standard text/image products (preserves v2.6.0 mobile load speed).
 *
 * Customer-facing UX:
 * - Never displays any prompt or admin reference images.
 * - Clean upload photo button + Generate button + single-result preview that fits inside the
 *   exact admin-defined print box on the live garment mockup.
 * - Client-side downscaling (<=1280px JPEG) for fast mobile 4G uploads.
 * - Automatic async task polling recovery (`mr_tee_ai_poll`) on slow/long generations.
 */

import { SvgRenderer } from './svg-renderer.js?v=2.7.1';

const MAX_UPLOAD_BYTES = 15 * 1024 * 1024;
const CLIENT_RESIZE_MAX_EDGE = 1280;
const POLL_INTERVAL_MS = 2500;
const MAX_POLL_ATTEMPTS = 45;

const SVG_PLACEHOLDER_AI = '<svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M12 3l1.9 5.8L20 10.7l-5.8 1.9L12 18.5l-1.9-5.9L4 10.7l6.1-1.9L12 3z"/><path d="M5 3v4"/><path d="M3 5h4"/><path d="M19 17v4"/><path d="M17 19h4"/></svg>';
const SVG_SPARKLE_ICON = '<svg class="mr-tee-ai-btn-sparkle" width="14" height="14" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><path d="M12 2l2.1 6.4L20.5 10.5l-6.4 2.1L12 19l-2.1-6.4-6.4-2.1 6.4-2.1L12 2z"/><path d="M19 15l1 3 3 1-3 1-1 3-1-3-3-1 3-1 1-3z"/></svg>';

function generateUuidV4() {
	if (typeof crypto !== 'undefined' && typeof crypto.randomUUID === 'function') {
		return crypto.randomUUID();
	}
	return 'xxxxxxxx-xxxx-4xxx-yxxx-xxxxxxxxxxxx'.replace(/[xy]/g, (c) => {
		const r = (Math.random() * 16) | 0;
		const v = c === 'x' ? r : ((r & 0x3) | 0x8);
		return v.toString(16);
	});
}

function isAllowedPhotoFile(file) {
	if (!file) return false;
	const t = (file.type || '').toLowerCase();
	if (t === 'image/png' || t === 'image/jpeg' || t === 'image/jpg' || t === 'image/webp') {
		return true;
	}
	if (!t && file.name) {
		return /\.(png|jpe?g|webp)$/i.test(file.name);
	}
	return false;
}

/**
 * Downscale large mobile camera photos client-side (<= 1280px JPEG q0.86) before upload
 * so 10 MB phone photos become ~180 KB and transfer in <0.5 s on mobile 4G.
 *
 * @param {File} file
 * @returns {Promise<Blob>}
 */
function compressPhotoForAiUpload(file) {
	return new Promise((resolve) => {
		if (!file || file.size <= 350 * 1024) {
			resolve(file);
			return;
		}
		const objectUrl = URL.createObjectURL(file);
		const img = new Image();
		img.onload = () => {
			URL.revokeObjectURL(objectUrl);
			try {
				const w = img.naturalWidth || img.width || 0;
				const h = img.naturalHeight || img.height || 0;
				if (!w || !h) {
					resolve(file);
					return;
				}
				const scale = Math.min(1, CLIENT_RESIZE_MAX_EDGE / Math.max(w, h));
				const dstW = Math.max(1, Math.round(w * scale));
				const dstH = Math.max(1, Math.round(h * scale));
				const canvas = document.createElement('canvas');
				canvas.width = dstW;
				canvas.height = dstH;
				const ctx = canvas.getContext('2d');
				if (!ctx) {
					resolve(file);
					return;
				}
				const rawMime = (file.type || '').toLowerCase();
				const keepAlpha = (rawMime === 'image/png' || rawMime === 'image/webp');
				if (!keepAlpha) {
					ctx.fillStyle = '#ffffff';
					ctx.fillRect(0, 0, dstW, dstH);
				} else {
					ctx.clearRect(0, 0, dstW, dstH);
				}
				ctx.drawImage(img, 0, 0, dstW, dstH);
				const targetMime = keepAlpha ? 'image/webp' : 'image/jpeg';
				canvas.toBlob(
					(blob) => {
						if (blob && blob.size > 0) {
							resolve(blob);
						} else if (keepAlpha) {
							canvas.toBlob((pngBlob) => resolve(pngBlob && pngBlob.size > 0 ? pngBlob : file), 'image/png');
						} else {
							resolve(file);
						}
					},
					targetMime,
					keepAlpha ? 0.88 : 0.86
				);
			} catch (_e) {
				resolve(file);
			}
		};
		img.onerror = () => {
			URL.revokeObjectURL(objectUrl);
			resolve(file);
		};
		img.src = objectUrl;
	});
}

function sleep(ms) {
	return new Promise((r) => setTimeout(r, ms));
}

/**
 * Bind all `.mr-tee-ai-field-card` elements inside the personalization form.
 *
 * @param {HTMLElement} container
 * @param {Object}      store
 * @param {Object}      formController
 */
export function bindAiImageFields(container, store, formController) {
	if (!container || !store) return;

	const cards = container.querySelectorAll('.mr-tee-ai-field-card');
	if (!cards.length) return;

	const cfg = (typeof window !== 'undefined' && window.mrTeeProductData) ? window.mrTeeProductData : {};
	const i18n = cfg.i18n || {};
	let ajaxUrl = cfg.ajaxUrl || '/wp-admin/admin-ajax.php';
	try {
		const u = new URL(ajaxUrl, window.location.origin);
		if (u.hostname === window.location.hostname) {
			ajaxUrl = u.pathname + u.search;
		}
	} catch (_e) {}
	const nonce = cfg.uploadNonce || '';
	const productId = cfg.productId || 0;

	const syncGlobalStageGenerating = () => {
		const anyGenerating = Boolean(container.querySelector('.mr-tee-ai-field-card.is-generating'));
		const stages = document.querySelectorAll('.mr-tee-gallery-stage, .mr-tee-ded-slide-inner');
		stages.forEach((s) => s.classList.toggle('is-ai-generating', anyGenerating));
	};

	cards.forEach((card) => {
		if (card.dataset.mrTeeAiBound === '1') return;
		card.dataset.mrTeeAiBound = '1';

		const fileInput = card.querySelector('.mr-tee-ai-file-input');
		const uploadBtn = card.querySelector('.mr-tee-ai-upload-btn');
		const generateBtn = card.querySelector('.mr-tee-ai-generate-btn');
		const resetBtn = card.querySelector('.mr-tee-ai-reset-btn');
		const thumbWrap = card.querySelector('.mr-tee-img-field-thumb');
		const statusEl = card.querySelector('.mr-tee-img-field-status');

		const defaultImg = card.getAttribute('data-default-image') || '';
		const defaultThumb = card.getAttribute('data-default-thumb') || defaultImg;
		const fieldId = card.getAttribute('data-field-id') || '';
		const refId = fileInput ? (fileInput.getAttribute('data-ref-id') || '') : (card.getAttribute('data-ref-id') || '');
		const sideId = card.closest('.mr-tee-field-group')?.getAttribute('data-side-id') || null;
		const baseBtnLabel = (generateBtn && generateBtn.getAttribute('data-base-label')) || i18n.aiGenerate || 'Générer';

		let selectedFile = null;
		let selectedThumbUrl = '';
		let hasGenerated = false;
		let activeJobToken = 0;

		const setStatus = (text, state = '') => {
			if (!statusEl) return;
			statusEl.textContent = text;
			statusEl.removeAttribute('data-upload-state');
			statusEl.style.color = '';
			if (state === 'error') {
				statusEl.style.color = '#dc2626';
				statusEl.setAttribute('data-upload-state', 'error');
			} else if (state === 'loading') {
				statusEl.style.color = '#7c3aed';
				statusEl.setAttribute('data-upload-state', 'uploading');
			} else if (state === 'ready') {
				statusEl.style.color = '#047857';
				statusEl.setAttribute('data-upload-state', 'ready');
			}
		};

		const setThumb = (src) => {
			if (!thumbWrap) return;
			if (src) {
				thumbWrap.classList.remove('is-empty');
				thumbWrap.innerHTML = `<img src="${SvgRenderer.escapeXml(src)}" alt="" />`;
			} else {
				thumbWrap.classList.add('is-empty');
				thumbWrap.innerHTML = `<span class="mr-tee-img-field-placeholder-icon" aria-hidden="true">${SVG_PLACEHOLDER_AI}</span>`;
			}
		};

		const syncButtonStates = (isLoading = false) => {
			card.classList.toggle('is-generating', isLoading);
			syncGlobalStageGenerating();
			if (uploadBtn) {
				uploadBtn.disabled = isLoading;
				uploadBtn.textContent = selectedFile
					? (i18n.aiChangePhoto || 'Changer la photo')
					: (i18n.aiImportPhoto || 'Importer une image');
			}
			if (generateBtn) {
				generateBtn.disabled = isLoading || !selectedFile;
				if (isLoading) {
					generateBtn.innerHTML = `<span class="mr-tee-ai-spinner" aria-hidden="true"></span> ${SvgRenderer.escapeXml(i18n.aiGenerating || 'Génération...')}`;
				} else {
					const label = hasGenerated ? (i18n.aiRegenerate || 'Régénérer') : baseBtnLabel;
					generateBtn.innerHTML = `${SVG_SPARKLE_ICON} ${SvgRenderer.escapeXml(label)}`;
				}
			}
			if (resetBtn) {
				resetBtn.style.display = (!isLoading && (hasGenerated || selectedFile)) ? 'inline-flex' : 'none';
			}
		};

		if (uploadBtn && fileInput) {
			uploadBtn.addEventListener('click', () => {
				fileInput.click();
			});
		}

		if (fileInput) {
			fileInput.addEventListener('change', () => {
				const file = fileInput.files && fileInput.files[0];
				fileInput.value = '';
				if (!file) return;

				if (!isAllowedPhotoFile(file)) {
					setStatus(i18n.aiInvalidType || 'Format non pris en charge (utilisez PNG, JPG ou WebP).', 'error');
					return;
				}
				if (file.size > MAX_UPLOAD_BYTES) {
					setStatus(i18n.imageTooLarge || 'L\'image dépasse la taille maximale autorisée (15 Mo).', 'error');
					return;
				}

				if (selectedThumbUrl && selectedThumbUrl.startsWith('blob:')) {
					URL.revokeObjectURL(selectedThumbUrl);
				}
				selectedFile = file;
				selectedThumbUrl = URL.createObjectURL(file);
				setThumb(selectedThumbUrl);
				setStatus(i18n.aiPhotoReady || 'Photo prête — cliquez sur Générer', '');
				syncButtonStates(false);
			});
		}

		const pollTaskUntilComplete = async (taskUuid, jobToken) => {
			for (let attempt = 0; attempt < MAX_POLL_ATTEMPTS; attempt++) {
				await sleep(POLL_INTERVAL_MS);
				if (activeJobToken !== jobToken) return null;

				const fd = new FormData();
				fd.append('action', 'mr_tee_ai_poll');
				fd.append('nonce', nonce);
				fd.append('task_uuid', taskUuid);

				let json = null;
				try {
					const resp = await fetch(ajaxUrl, { method: 'POST', body: fd, credentials: 'same-origin' });
					json = await resp.json();
				} catch (_e) {
					continue;
				}

				if (activeJobToken !== jobToken) return null;

				if (json && json.success && json.data) {
					if (json.data.status === 'completed' && json.data.url) {
						return json.data;
					}
					continue;
				}
				if (json && json.success === false && json.data && json.data.message) {
					throw new Error(json.data.message);
				}
			}
			throw new Error(i18n.aiTimeout || 'La génération a expiré. Veuillez réessayer.');
		};

		if (generateBtn) {
			generateBtn.addEventListener('click', () => {
				if (!selectedFile || !ajaxUrl) return;

				const jobToken = ++activeJobToken;
				const taskUuid = generateUuidV4();

				if (formController && typeof formController.notifySideFocus === 'function') {
					formController.notifySideFocus(generateBtn);
				}
				if (formController && typeof formController.prefetchPrintExporter === 'function') {
					formController.prefetchPrintExporter();
				}

				syncButtonStates(true);
				setStatus(i18n.aiGeneratingStatus || 'Génération de votre aperçu en cours...', 'loading');

				const runJob = (async () => {
					try {
						const uploadBlob = await compressPhotoForAiUpload(selectedFile);
						if (activeJobToken !== jobToken) return;

						const ext = (uploadBlob && uploadBlob.type === 'image/webp') ? 'webp' : ((uploadBlob && uploadBlob.type === 'image/png') ? 'png' : 'jpg');
						const fd = new FormData();
						fd.append('action', 'mr_tee_ai_generate');
						fd.append('nonce', nonce);
						fd.append('product_id', String(productId));
						fd.append('field_id', fieldId);
						fd.append('ref_id', refId);
						fd.append('task_uuid', taskUuid);
						fd.append('customer_image', uploadBlob, selectedFile.name || `photo.${ext}`);

						let resultData = null;
						let shouldPoll = false;
						let pollUuid = taskUuid;

						try {
							const resp = await fetch(ajaxUrl, {
								method: 'POST',
								body: fd,
								credentials: 'same-origin',
							});
							const json = await resp.json();
							if (activeJobToken !== jobToken) return;

							if (json && json.success && json.data) {
								if (json.data.status === 'completed' && json.data.url) {
									resultData = json.data;
								} else if (json.data.status === 'processing') {
									if (json.data.task_uuid) {
										pollUuid = String(json.data.task_uuid);
									}
									shouldPoll = true;
								}
							} else {
								const errMsg = (json && json.data && json.data.message) || i18n.aiError || 'Impossible de générer l\'image. Veuillez réessayer.';
								throw new Error(errMsg);
							}
						} catch (fetchErr) {
							// Network / gateway timeout: attempt seamless async recovery via Runware getResponse.
							if (fetchErr && fetchErr.name === 'TypeError') {
								shouldPoll = true;
							} else if (fetchErr && /json|unexpected token|504|502|524/i.test(String(fetchErr.message || ''))) {
								shouldPoll = true;
							} else {
								throw fetchErr;
							}
						}

						if (shouldPoll && !resultData) {
							setStatus(i18n.aiFinalizing || 'Finalisation de votre image...', 'loading');
							resultData = await pollTaskUntilComplete(pollUuid, jobToken);
						}

						if (activeJobToken !== jobToken) return;
						if (!resultData || !resultData.url) {
							throw new Error(i18n.aiError || 'Aucune image générée. Veuillez réessayer.');
						}

						const fullUrl = resultData.url;
						const previewUrl = resultData.preview_url || fullUrl;
						const fileName = resultData.filename || 'ai-design.png';

						hasGenerated = true;
						setThumb(previewUrl);
						setStatus(i18n.aiReady || 'Aperçu prêt !', 'ready');

						if (refId) {
							store.setImageValue(refId, fullUrl, fileName, true, sideId, previewUrl);
						}
						if (formController && typeof formController.notifySideFocus === 'function') {
							formController.notifySideFocus(card);
						}
					} catch (err) {
						if (activeJobToken !== jobToken) return;
						setStatus((err && err.message) || i18n.aiError || 'Erreur lors de la génération.', 'error');
					} finally {
						if (activeJobToken === jobToken) {
							syncButtonStates(false);
						}
					}
				})();

				if (formController && Array.isArray(formController.pendingUploads)) {
					formController.pendingUploads.push(runJob);
					runJob.finally(() => {
						const idx = formController.pendingUploads.indexOf(runJob);
						if (idx !== -1) formController.pendingUploads.splice(idx, 1);
					});
				}
			});
		}

		if (resetBtn) {
			resetBtn.addEventListener('click', () => {
				activeJobToken++;
				if (selectedThumbUrl && selectedThumbUrl.startsWith('blob:')) {
					URL.revokeObjectURL(selectedThumbUrl);
				}
				selectedFile = null;
				selectedThumbUrl = '';
				hasGenerated = false;
				if (fileInput) fileInput.value = '';
				setThumb(defaultThumb);
				setStatus(i18n.aiInitialHint || 'Importez votre image puis cliquez sur Générer', '');
				syncButtonStates(false);

				if (refId) {
					store.setImageValue(refId, defaultImg, '', false, sideId, null);
				}
				if (formController && typeof formController.notifySideFocus === 'function') {
					formController.notifySideFocus(resetBtn);
				}
			});
		}

		syncButtonStates(false);
	});
}
