/**
 * Mr. Tee Editor - Form Controller Module
 *
 * Coordinates client-side interactive form elements:
 * - Floating-label text inputs with live character counters
 * - Font selection pills with font-family previews
 * - Mushroom repeater count selector modal with minus/plus stepper and dot buttons
 * - Individual mushroom accordion cards with vector SVG icon pickers
 * - Unedited confirmation modal (matching Monsieur TSHIRT's IGNORE_PERSO_WARNINGS_LABEL)
 *
 * @package MrTeeEditor\Assets\Modules
 */

import { SvgRenderer } from './svg-renderer.js?v=2.3.12';

export class FormController {
	constructor(store, config, onExportRequested) {
		this.store = store;
		this.config = config || {};
		this.onExportRequested = onExportRequested;
		this.bypassWarning = false;
		this.pendingUploads = [];

		this.initDom();
		this.bindEvents();
	}

	initDom() {
		this.container = document.getElementById('mr-tee-editor-container');
		this.countModal = document.getElementById('mr-tee-count-modal');
		this.warningModal = document.getElementById('mr-tee-warning-modal');
		this.activeCountDisplay = document.getElementById('mr-tee-active-count-display');
		this.stepVal = document.getElementById('mr-tee-step-val');
		this.addToCartBtn = document.querySelector('form.cart button[type="submit"]') || document.querySelector('.single_add_to_cart_button');
	}

	bindEvents() {
		if (!this.container) return;

		// 1. Text Inputs with Live Character Counters & Stage Sync
		this.container.querySelectorAll('.mr-tee-text-inputs-wrap .mr-tee-text-input').forEach((input) => {
			const group = input.closest('.mr-tee-field-group');
			const lineIdx = parseInt(group.getAttribute('data-line-index'), 10);
			const lineConfig = (this.config.text_lines && this.config.text_lines[lineIdx]) || {};
			const charCounter = group.querySelector('.mr-tee-char-count');

			const warmLineFont = () => {
				const activeFontId = lineConfig.font || (Array.isArray(lineConfig.allowed_fonts) && lineConfig.allowed_fonts[0]);
				if (activeFontId) {
					const sData = SvgRenderer.getSvgFontData(activeFontId);
					if (sData) {
						SvgRenderer.prewarmFontGlyphs(sData, { immediate: true });
					} else {
						const fonts = (window.mrTeeProductData && window.mrTeeProductData.fonts) || {};
						if (fonts[activeFontId] && (fonts[activeFontId].font_type === 'opentype_svg' || fonts[activeFontId].color_svg)) {
							SvgRenderer.ensureSvgFontLoaded(activeFontId, fonts[activeFontId], { prewarmGlyphs: true }).catch(() => {});
						}
					}
				}
			};

			input.addEventListener('pointerdown', warmLineFont, { passive: true });
			input.addEventListener('focus', warmLineFont);

			input.addEventListener('input', () => {
				const val = input.value;
				if (charCounter) {
					charCounter.textContent = val.length;
				}
				if (lineConfig.ref_id) {
					this.store.setTextValue(lineConfig.ref_id, val);
				}
			});
		});

		// 2. Font Selector Pills
		this.container.querySelectorAll('.mr-tee-font-pill').forEach((pill) => {
			pill.addEventListener('pointerdown', () => {
				const fontId = pill.getAttribute('data-font-id');
				const fonts = (window.mrTeeProductData && window.mrTeeProductData.fonts) || {};
				const fontDef = fonts[fontId] || null;
				if (fontDef && (fontDef.font_type === 'opentype_svg' || fontDef.color_svg)) {
					SvgRenderer.ensureSvgFontLoaded(fontId, fontDef, { prewarmGlyphs: false }).catch(() => {});
				}
			}, { passive: true });

			pill.addEventListener('click', () => {
				const pillsContainer = pill.closest('.mr-tee-font-pills');
				const lineIdx = parseInt(pillsContainer.getAttribute('data-line-index'), 10);
				const lineConfig = (this.config.text_lines && this.config.text_lines[lineIdx]) || {};
				const fontId = pill.getAttribute('data-font-id');

				// Highlight active
				pillsContainer.querySelectorAll('.mr-tee-font-pill').forEach(p => p.classList.remove('is-active'));
				pill.classList.add('is-active');

				const fonts = (window.mrTeeProductData && window.mrTeeProductData.fonts)
					|| (window.mrTeeProductData && window.mrTeeProductData.config && window.mrTeeProductData.config.fonts)
					|| {};
				const fontDef = fonts[fontId] || null;
				const isOtSvg = Boolean(fontDef && (fontDef.font_type === 'opentype_svg' || fontDef.color_svg));

				const refId = lineConfig.ref_id || `id-ref-text-line-${lineIdx + 1}`;
				lineConfig.font = fontId;
				const currentInput = document.getElementById(`mr_tee_line_${lineIdx}`);
				const currentText = currentInput ? currentInput.value : (lineConfig.default_text || '');

				if (isOtSvg) {
					const isUpper = (lineConfig.casing === 'UPPER') || Boolean(fontDef && fontDef.uppercase);
					const warmText = isUpper ? currentText.toUpperCase() : currentText;
					SvgRenderer.ensureSvgFontLoaded(fontId, fontDef, { prewarmGlyphs: true, priorityText: warmText }).then(() => {
						this.store.setTextFont(refId, fontId, fontDef);
					});
				} else {
					this.store.setTextFont(refId, fontId, fontDef);
				}
			});
		});

		// 2b. Color Swatch Selector Pills (Embroidery Thread & Ink Palettes)
		this.container.querySelectorAll('.mr-tee-color-swatch-pill').forEach((pill) => {
			pill.addEventListener('click', () => {
				const group = pill.closest('.mr-tee-color-swatches-wrap');
				const colorRefId = group ? group.getAttribute('data-color-ref-id') : 'id-ref-color-selector';
				const colorHex = pill.getAttribute('data-color-hex');

				if (group) {
					group.querySelectorAll('.mr-tee-color-swatch-pill').forEach(p => p.classList.remove('is-active'));
				}
				pill.classList.add('is-active');

				if (colorHex) {
					this.store.setColorSelection(colorRefId, colorHex);
				}
			});
		});

		// 3. Count Selector Modal Triggers
		const openCountBtn = document.getElementById('mr-tee-open-count-modal');
		const closeCountBtn = document.getElementById('mr-tee-close-count-modal');
		const confirmCountBtn = document.getElementById('mr-tee-confirm-count');
		const stepMinusBtn = document.getElementById('mr-tee-step-minus');
		const stepPlusBtn = document.getElementById('mr-tee-step-plus');

		let tempCount = this.store.state.activeCount;

		if (openCountBtn && this.countModal) {
			openCountBtn.addEventListener('click', () => {
				tempCount = this.store.state.activeCount;
				this.updateModalCountDisplay(tempCount);
				this.countModal.style.display = 'flex';
			});
		}

		if (closeCountBtn && this.countModal) {
			closeCountBtn.addEventListener('click', () => {
				this.countModal.style.display = 'none';
			});
		}

		if (confirmCountBtn && this.countModal) {
			confirmCountBtn.addEventListener('click', () => {
				this.store.updateVisibilityFromCount(tempCount, true);
				this.countModal.style.display = 'none';
				this.updateAccordionCardsVisibility(tempCount);
				if (this.activeCountDisplay) {
					this.activeCountDisplay.textContent = tempCount;
				}
			});
		}

		if (stepMinusBtn) {
			stepMinusBtn.addEventListener('click', () => {
				if (tempCount > 0) {
					tempCount--;
					this.updateModalCountDisplay(tempCount);
				}
			});
		}

		if (stepPlusBtn) {
			stepPlusBtn.addEventListener('click', () => {
				const max = (this.config.repeater && this.config.repeater.max_items) || 6;
				if (tempCount < max) {
					tempCount++;
					this.updateModalCountDisplay(tempCount);
				}
			});
		}

		// Dot buttons inside modal
		if (this.countModal) {
			this.countModal.querySelectorAll('.mr-tee-count-dot-btn').forEach((dot) => {
				dot.addEventListener('click', () => {
					tempCount = parseInt(dot.getAttribute('data-count'), 10);
					this.updateModalCountDisplay(tempCount);
				});
			});
		}

		// 4. Individual Mushroom Accordions
		this.container.querySelectorAll('.mr-tee-repeater-item-card').forEach((card) => {
			const itemNum = parseInt(card.getAttribute('data-item-num'), 10);
			const itemIdx = parseInt(card.getAttribute('data-item-index'), 10);
			const header = card.querySelector('.mr-tee-rep-card-header');
			const nameInput = card.querySelector('.mr-tee-rep-name-input');
			const charCounter = card.querySelector('.mr-tee-char-count');
			const summaryText = card.querySelector('.mr-tee-rep-summary-text');
			const thumbImg = card.querySelector('.mr-tee-rep-thumb img');

			// Accordion expand/collapse
			if (header) {
				header.addEventListener('click', (e) => {
					if (e.target && e.target.closest('input')) return;
					card.classList.toggle('is-open');
				});
			}

			// Name input
			if (nameInput) {
				nameInput.addEventListener('input', () => {
					const val = nameInput.value;
					if (charCounter) charCounter.textContent = val.length;
					if (summaryText) summaryText.textContent = val || 'Sans nom';
					this.store.setMushroomName(itemIdx, val);
				});
			}

			// Icon radio picker
			card.querySelectorAll('.mr-tee-icon-option input[type="radio"]').forEach((radio) => {
				radio.addEventListener('change', () => {
					card.querySelectorAll('.mr-tee-icon-option').forEach(o => o.classList.remove('is-selected'));
					const parentOption = radio.closest('.mr-tee-icon-option');
					if (parentOption) parentOption.classList.add('is-selected');

					const iconKey = radio.value;
					const iconImg = parentOption ? parentOption.querySelector('img') : null;
					const iconUrl = iconImg ? iconImg.src : '';

					if (thumbImg && iconUrl) {
						thumbImg.src = iconUrl;
					}

					this.store.setMushroomIcon(itemIdx, iconKey, iconUrl);
				});
			});
		});

		// 4b. Customer Image Fields (Upload / Preview / Reset)
		this.bindImageFields();

		// 5. Intercept Add-to-Cart for Unedited Field Validation (IGNORE_PERSO_WARNINGS_LABEL)
		if (this.addToCartBtn) {
			this.addToCartBtn.addEventListener('click', (e) => {
				if (this.bypassWarning) {
					return; // Allow submission
				}

				this.store.validateWarnings();
				if (this.store.state.hasUneditedWarnings) {
					e.preventDefault();
					e.stopPropagation();
					this.showWarningModal();
					return false;
				}

				// If no warnings, proceed with 300 DPI rasterization and submission
				e.preventDefault();
				e.stopPropagation();
				this.runExportAndSubmit();
			});
		}

		// Warning Modal buttons
		const btnModify = document.getElementById('mr-tee-btn-modify');
		const btnProceed = document.getElementById('mr-tee-btn-proceed');
		const closeWarnBtn = document.getElementById('mr-tee-close-warning-modal');

		if (btnModify && this.warningModal) {
			btnModify.addEventListener('click', () => {
				this.warningModal.style.display = 'none';
				// Focus first unedited text or image field
				const firstInput = this.container.querySelector('.mr-tee-text-input[data-warning="1"], .mr-tee-image-file-input[data-warning="1"]');
				if (firstInput) {
					if (firstInput.classList.contains('mr-tee-image-file-input')) {
						const card = firstInput.closest('.mr-tee-image-field-card');
						const btn = card ? card.querySelector('.mr-tee-image-upload-btn') : null;
						if (card) card.scrollIntoView({ behavior: 'smooth', block: 'center' });
						if (btn) btn.focus();
					} else {
						firstInput.focus();
						firstInput.scrollIntoView({ behavior: 'smooth', block: 'center' });
					}
				}
			});
		}

		if (closeWarnBtn && this.warningModal) {
			closeWarnBtn.addEventListener('click', () => {
				this.warningModal.style.display = 'none';
			});
		}

		if (btnProceed && this.warningModal) {
			btnProceed.addEventListener('click', () => {
				this.warningModal.style.display = 'none';
				this.runExportAndSubmit();
			});
		}
	}

	async runExportAndSubmit() {
		if (this.pendingUploads.length > 0) {
			try {
				await Promise.allSettled(this.pendingUploads);
			} catch (e) {}
		}
		if (this.onExportRequested) {
			this.onExportRequested(() => {
				this.submitCartForm();
			});
		}
	}

	bindImageFields() {
		if (!this.container) return;
		const i18n = (window.mrTeeProductData && window.mrTeeProductData.i18n) || {};

		this.container.querySelectorAll('.mr-tee-image-field-card').forEach((card) => {
			const fileInput = card.querySelector('.mr-tee-image-file-input');
			const uploadBtn = card.querySelector('.mr-tee-image-upload-btn');
			const resetBtn = card.querySelector('.mr-tee-image-reset-btn');
			const thumbWrap = card.querySelector('.mr-tee-img-field-thumb');
			const statusEl = card.querySelector('.mr-tee-img-field-status');
			const defaultImg = card.getAttribute('data-default-image') || '';

			if (uploadBtn && fileInput) {
				uploadBtn.addEventListener('click', () => {
					fileInput.click();
				});
			}

			if (resetBtn && fileInput) {
				resetBtn.addEventListener('click', () => {
					card._uploadToken = (card._uploadToken || 0) + 1;
					fileInput.value = '';
					const refId = fileInput.getAttribute('data-ref-id');
					if (thumbWrap) {
						if (defaultImg) {
							thumbWrap.classList.remove('is-empty');
							thumbWrap.innerHTML = `<img src="${SvgRenderer.escapeXml(defaultImg)}" alt="" />`;
						} else {
							thumbWrap.classList.add('is-empty');
							thumbWrap.innerHTML = '<span class="mr-tee-img-field-placeholder-icon" aria-hidden="true">🖼️</span>';
						}
					}
					if (statusEl) {
						statusEl.textContent = defaultImg ? (i18n.imageDefault || 'Image par défaut') : (i18n.imageEmpty || 'Aucune image sélectionnée');
						statusEl.style.color = '';
						statusEl.removeAttribute('data-upload-state');
					}
					if (uploadBtn) {
						uploadBtn.textContent = defaultImg ? (i18n.changeImage || 'Changer l\'image') : (i18n.importImage || 'Importer une image');
					}
					resetBtn.style.display = 'none';
					if (refId) {
						this.store.setImageValue(refId, defaultImg, '', false);
					}
				});
			}

			if (fileInput) {
				fileInput.addEventListener('change', async () => {
					const file = fileInput.files && fileInput.files[0];
					if (!file) return;

					const refId = fileInput.getAttribute('data-ref-id');
					const token = (card._uploadToken || 0) + 1;
					card._uploadToken = token;

					let previewDataUrl = '';
					try {
						previewDataUrl = await this.readImageAsDataUrl(file);
					} catch (err) {
						if (statusEl) {
							statusEl.textContent = i18n.imageUploadError || 'Erreur lors du chargement de l\'image.';
							statusEl.style.color = '#dc2626';
							statusEl.setAttribute('data-upload-state', 'error');
						}
						return;
					}

					if (card._uploadToken !== token) return;

					if (thumbWrap) {
						thumbWrap.classList.remove('is-empty');
						thumbWrap.innerHTML = `<img src="${previewDataUrl}" alt="" />`;
					}
					if (statusEl) {
						statusEl.textContent = `… ${file.name}`;
						statusEl.style.color = '#2563eb';
						statusEl.setAttribute('data-upload-state', 'uploading');
					}
					if (uploadBtn) {
						uploadBtn.textContent = i18n.changeImage || 'Changer l\'image';
					}
					if (resetBtn && (defaultImg || previewDataUrl)) {
						resetBtn.style.display = 'inline-flex';
					}

					// Immediate 0ms local preview in SVG scene graph
					if (refId) {
						this.store.setImageValue(refId, previewDataUrl, file.name, true);
					}

					// Upload file to server in background for lightweight persistent order storage
					const uploadPromise = this.uploadCustomerImage(file).then((serverUrl) => {
						if (card._uploadToken !== token) return;
						if (serverUrl) {
							if (typeof window !== 'undefined') {
								window.mrTeeImageB64Cache = window.mrTeeImageB64Cache || {};
								window.mrTeeImageB64Cache[serverUrl] = previewDataUrl;
							}
							if (refId) {
								this.store.setImageValue(refId, serverUrl, file.name, true);
							}
							if (statusEl) {
								statusEl.textContent = `✓ ${file.name}`;
								statusEl.style.color = '#059669';
								statusEl.setAttribute('data-upload-state', 'done');
							}
						} else if (statusEl) {
							statusEl.textContent = file.name;
							statusEl.style.color = '#0f172a';
							statusEl.setAttribute('data-upload-state', 'local');
						}
					}).catch(() => {
						if (card._uploadToken !== token) return;
						if (statusEl) {
							statusEl.textContent = file.name;
							statusEl.style.color = '#0f172a';
							statusEl.setAttribute('data-upload-state', 'local');
						}
					});

					this.pendingUploads.push(uploadPromise);
					uploadPromise.finally(() => {
						const idx = this.pendingUploads.indexOf(uploadPromise);
						if (idx !== -1) this.pendingUploads.splice(idx, 1);
					});
				});
			}
		});
	}

	readImageAsDataUrl(file) {
		return new Promise((resolve, reject) => {
			const reader = new FileReader();
			reader.onerror = reject;
			reader.onload = () => {
				const rawResult = String(reader.result || '');
				if (!rawResult || file.type === 'image/svg+xml' || !file.type.startsWith('image/')) {
					resolve(rawResult);
					return;
				}
				const img = new Image();
				img.onload = () => {
					const maxDim = 2400;
					if (img.naturalWidth <= maxDim && img.naturalHeight <= maxDim) {
						resolve(rawResult);
						return;
					}
					const scale = Math.min(maxDim / img.naturalWidth, maxDim / img.naturalHeight);
					const canvas = document.createElement('canvas');
					canvas.width = Math.max(1, Math.round(img.naturalWidth * scale));
					canvas.height = Math.max(1, Math.round(img.naturalHeight * scale));
					const ctx = canvas.getContext('2d');
					ctx.clearRect(0, 0, canvas.width, canvas.height);
					ctx.drawImage(img, 0, 0, canvas.width, canvas.height);
					const outType = (file.type === 'image/jpeg') ? 'image/jpeg' : 'image/png';
					resolve(canvas.toDataURL(outType, 0.92));
				};
				img.onerror = () => resolve(rawResult);
				img.src = rawResult;
			};
			reader.readAsDataURL(file);
		});
	}

	async uploadCustomerImage(file) {
		const data = window.mrTeeProductData || {};
		const rawAjaxUrl = data.ajaxUrl || '/wp-admin/admin-ajax.php';
		let ajaxUrl = rawAjaxUrl;
		try {
			const u = new URL(rawAjaxUrl, window.location.origin);
			if (u.hostname === window.location.hostname) {
				ajaxUrl = u.pathname + u.search;
			}
		} catch (e) {}

		const formData = new FormData();
		formData.append('action', 'mr_tee_upload_customer_image');
		formData.append('nonce', data.uploadNonce || '');
		formData.append('product_id', String(this.config.productId || data.productId || 0));
		formData.append('image_file', file);

		const resp = await fetch(ajaxUrl, { method: 'POST', body: formData });
		if (!resp.ok) return '';
		const json = await resp.json();
		if (json && json.success && json.data && json.data.url) {
			return String(json.data.url);
		}
		return '';
	}

	submitCartForm() {
		this.bypassWarning = true;
		const form = this.addToCartBtn ? this.addToCartBtn.closest('form') : document.querySelector('form.cart');
		if (form) {
			if (location.protocol === 'http:' && form.action && form.action.startsWith('https:')) {
				form.action = form.action.replace('https:', 'http:');
			}
			if (typeof form.requestSubmit === 'function') {
				form.requestSubmit(this.addToCartBtn);
			} else {
				form.submit();
			}
		} else if (this.addToCartBtn) {
			this.addToCartBtn.click();
		}
	}

	updateModalCountDisplay(count) {
		if (this.stepVal) {
			this.stepVal.textContent = count;
		}
		if (this.countModal) {
			this.countModal.querySelectorAll('.mr-tee-count-dot-btn').forEach((dot) => {
				const dotCount = parseInt(dot.getAttribute('data-count'), 10);
				dot.classList.toggle('is-active', dotCount === count);
			});
		}
	}

	updateAccordionCardsVisibility(count) {
		if (!this.container) return;
		this.container.querySelectorAll('.mr-tee-repeater-item-card').forEach((card) => {
			const itemNum = parseInt(card.getAttribute('data-item-num'), 10);
			const isVisible = (itemNum <= count);
			card.classList.toggle('is-visible', isVisible);
			card.classList.toggle('is-hidden', !isVisible);
		});
	}

	showWarningModal() {
		if (this.warningModal) {
			this.warningModal.style.display = 'flex';
		}
	}
}
