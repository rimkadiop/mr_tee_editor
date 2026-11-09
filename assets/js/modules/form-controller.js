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

import { SvgRenderer } from './svg-renderer.js?v=2.3.4';

export class FormController {
	constructor(store, config, onExportRequested) {
		this.store = store;
		this.config = config || {};
		this.onExportRequested = onExportRequested;
		this.bypassWarning = false;

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

		// 1. Text Inputs with Live Character Counters & Inline Typography Sync
		this.container.querySelectorAll('.mr-tee-text-inputs-wrap .mr-tee-text-input').forEach((input) => {
			const group = input.closest('.mr-tee-field-group');
			const lineIdx = parseInt(group.getAttribute('data-line-index'), 10);
			const lineConfig = (this.config.text_lines && this.config.text_lines[lineIdx]) || {};
			const charCounter = group.querySelector('.mr-tee-char-count');
			const previewText = document.getElementById(`mr-tee-preview-text-${lineIdx}`);

			input.addEventListener('input', () => {
				const val = input.value;
				if (charCounter) {
					charCounter.textContent = val.length;
				}
				if (previewText) {
					previewText.textContent = val || '(vide)';
				}
				if (lineConfig.ref_id) {
					this.store.setTextValue(lineConfig.ref_id, val);
				}
			});
		});

		// 2. Font Selector Pills with Live Typography Sync
		this.container.querySelectorAll('.mr-tee-font-pill').forEach((pill) => {
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

				const previewText = document.getElementById(`mr-tee-preview-text-${lineIdx}`);
				if (previewText && fontDef) {
					previewText.style.fontFamily = `'${fontDef.family}', sans-serif`;
					previewText.style.letterSpacing = fontDef.letter_spacing !== undefined ? `${fontDef.letter_spacing}em` : '0.05em';
					previewText.style.textTransform = fontDef.uppercase ? 'uppercase' : 'none';
				}

				const refId = lineConfig.ref_id || `id-ref-text-line-${lineIdx + 1}`;
				lineConfig.font = fontId;

				if (fontDef && (fontDef.font_type === 'opentype_svg' || fontDef.color_svg)) {
					SvgRenderer.ensureSvgFontLoaded(fontId, fontDef).then(() => {
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
					const previewText = group ? group.closest('.mr-tee-field-group')?.querySelector('.mr-tee-inline-preview-text') : null;
					if (previewText) {
						previewText.style.color = colorHex;
					}
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
				if (this.onExportRequested) {
					this.onExportRequested(() => {
						this.submitCartForm();
					});
				}
			});
		}

		// Warning Modal buttons
		const btnModify = document.getElementById('mr-tee-btn-modify');
		const btnProceed = document.getElementById('mr-tee-btn-proceed');
		const closeWarnBtn = document.getElementById('mr-tee-close-warning-modal');

		if (btnModify && this.warningModal) {
			btnModify.addEventListener('click', () => {
				this.warningModal.style.display = 'none';
				// Focus first unedited field
				const firstInput = this.container.querySelector('.mr-tee-text-input[data-warning="1"]');
				if (firstInput) {
					firstInput.focus();
					firstInput.scrollIntoView({ behavior: 'smooth', block: 'center' });
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
				if (this.onExportRequested) {
					this.onExportRequested(() => {
						this.submitCartForm();
					});
				}
			});
		}
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
