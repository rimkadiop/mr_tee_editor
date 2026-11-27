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

import { SvgRenderer } from './svg-renderer.js?v=2.5.9';

const SVG_PLACEHOLDER_IMAGE = '<svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect x="3" y="3" width="18" height="18" rx="2" ry="2"/><circle cx="8.5" cy="8.5" r="1.5"/><polyline points="21 15 16 10 5 21"/></svg>';
const ALLOWED_IMAGE_MIMES = new Set(['image/png', 'image/jpeg', 'image/webp', 'image/gif', 'image/svg+xml']);
const MAX_IMAGE_BYTES = 15 * 1024 * 1024;

function parseHexRgb(hex) {
	const raw = String(hex || '').trim().toLowerCase().replace(/^#/, '');
	const norm = raw.length === 3 ? raw.split('').map((c) => c + c).join('') : raw;
	if (!/^[0-9a-f]{6}$/.test(norm)) return null;
	return [
		parseInt(norm.slice(0, 2), 16),
		parseInt(norm.slice(2, 4), 16),
		parseInt(norm.slice(4, 6), 16),
	];
}

function findClosestSwatchHex(targetHex, swatchHexes) {
	const norm = String(targetHex || '').trim().toLowerCase();
	if (!norm || !norm.startsWith('#') || !Array.isArray(swatchHexes) || swatchHexes.length === 0) {
		return '';
	}
	if (swatchHexes.includes(norm)) return norm;
	const tRgb = parseHexRgb(norm);
	if (!tRgb) return '';
	let bestHex = '';
	let bestDist = 33;
	for (const candidate of swatchHexes) {
		const cRgb = parseHexRgb(candidate);
		if (!cRgb) continue;
		const dist = Math.hypot(tRgb[0] - cRgb[0], tRgb[1] - cRgb[1], tRgb[2] - cRgb[2]);
		if (dist < bestDist) {
			bestDist = dist;
			bestHex = candidate;
		}
	}
	return bestHex;
}

function normalizeSameHostUrl(rawUrl) {
	if (!rawUrl || typeof rawUrl !== 'string') return '';
	if (rawUrl.startsWith('data:')) return rawUrl;
	try {
		const u = new URL(rawUrl, window.location.origin);
		if (u.hostname === window.location.hostname) return u.pathname + u.search;
	} catch (e) {}
	return rawUrl;
}

function resolveAjaxUrl() {
	const data = (typeof window !== 'undefined' && window.mrTeeProductData) || {};
	return normalizeSameHostUrl(data.ajaxUrl || '/wp-admin/admin-ajax.php');
}

export class FormController {
	constructor(store, config, onExportRequested, options = {}) {
		this.store = store;
		this.config = config || {};
		this.onExportRequested = onExportRequested;
		this.options = options || {};
		this.onFieldSideFocus = typeof this.options.onFieldSideFocus === 'function'
			? this.options.onFieldSideFocus
			: null;
		this.bypassWarning = false;
		this.isSubmitting = false;
		this.pendingUploads = [];
		this._printExporterClass = null;

		this.initDom();
		this.bindEvents();
	}

	/**
	 * Dynamically load PrintExporter module strictly on demand (e.g., large chunked customer uploads).
	 *
	 * @return {Promise<Function>}
	 */
	async loadPrintExporter() {
		if (this._printExporterClass) return this._printExporterClass;
		const mod = await import('./print-exporter.js?v=2.5.9');
		this._printExporterClass = mod.PrintExporter;
		if (typeof window !== 'undefined') {
			window.mrTeePrintExporter = mod.PrintExporter;
		}
		return this._printExporterClass;
	}

	/**
	 * Notify gallery stage when a user focuses or interacts with a control inside
	 * a `.mr-tee-field-group[data-side-id]`, `.mr-tee-side-section[data-side-id]`, or explicit side ID string.
	 *
	 * @param {Element|string|null} targetEl Interacted control element or explicit side ID.
	 */
	notifySideFocus(targetEl) {
		if (!this.onFieldSideFocus || !targetEl) return;
		if (typeof targetEl === 'string') {
			this.onFieldSideFocus(targetEl);
			return;
		}
		if (!targetEl.closest) return;
		const group = targetEl.closest('.mr-tee-field-group[data-side-id], .mr-tee-side-section[data-side-id]');
		if (!group) return;
		const sideId = group.getAttribute('data-side-id');
		if (sideId) {
			this.onFieldSideFocus(sideId);
		}
	}

	/**
	 * Highlight side sections and field groups belonging to the currently active gallery side on multi-side products.
	 *
	 * @param {string} sideId Active print side identifier.
	 */
	setActiveSide(sideId) {
		if (!this.container) return;
		const sections = Array.from(this.container.querySelectorAll('.mr-tee-side-section[data-side-id]'));
		const groups = Array.from(this.container.querySelectorAll('.mr-tee-field-group[data-side-id]'));
		const distinctSides = new Set(
			[...sections, ...groups].map(el => el.getAttribute('data-side-id')).filter(Boolean)
		);
		if (distinctSides.size <= 1) return;
		const target = sideId ? String(sideId) : '';
		sections.forEach((sec) => {
			sec.classList.toggle('is-active-side', Boolean(target && sec.getAttribute('data-side-id') === target));
		});
		groups.forEach((g) => {
			g.classList.toggle('is-active-side', Boolean(target && g.getAttribute('data-side-id') === target));
		});
	}

	/**
	 * Update character counter using Unicode code-point count and apply amber/red threshold classes.
	 *
	 * @param {Element|null} counterWrapEl `.mr-tee-char-counter` element.
	 * @param {Element|null} countEl `.mr-tee-char-count` element.
	 * @param {string} val Current text value.
	 * @param {number} maxChars Maximum allowed character count.
	 */
	updateCharCounter(counterWrapEl, countEl, val, maxChars) {
		const len = Array.from(String(val || '')).length;
		if (countEl) {
			countEl.textContent = String(len);
		}
		if (counterWrapEl && maxChars > 0) {
			const ratio = len / maxChars;
			counterWrapEl.classList.toggle('is-at-limit', len >= maxChars);
			counterWrapEl.classList.toggle('is-near-limit', len < maxChars && ratio >= 0.85);
		}
	}

	/**
	 * Close any open mobile collapsible color trays (optionally preserving one active field group).
	 *
	 * @param {Element|null} exceptGroup Field group to leave open, if any.
	 */
	closeAllColorTrays(exceptGroup = null) {
		if (!this.container) return;
		this.container.querySelectorAll('.mr-tee-field-group.is-color-open').forEach((openGroup) => {
			if (exceptGroup && openGroup === exceptGroup) return;
			openGroup.classList.remove('is-color-open');
			const btn = openGroup.querySelector('.mr-tee-color-trigger-btn');
			if (btn) btn.setAttribute('aria-expanded', 'false');
		});
	}

	/**
	 * Keep the compact left-hand mobile Color Switch trigger button in sync with the active swatch
	 * or hide it cleanly whenever the active font does not support custom colors (e.g., OpenType-SVG).
	 *
	 * @param {Element|null} fieldGroup `.mr-tee-field-group` element.
	 * @param {Object}       state      Trigger display state.
	 */
	syncColorTrigger(fieldGroup, { hidden = false, isMulti = false, hex = '', name = '' } = {}) {
		if (!fieldGroup) return;
		const triggerBtn = fieldGroup.querySelector('.mr-tee-color-trigger-btn');
		if (!triggerBtn) return;

		if (hidden) {
			triggerBtn.style.display = 'none';
			triggerBtn.setAttribute('aria-expanded', 'false');
			fieldGroup.classList.remove('is-color-open');
			return;
		}

		triggerBtn.style.display = '';
		const dot = triggerBtn.querySelector('.mr-tee-color-trigger-dot');
		if (dot) {
			dot.classList.toggle('mr-tee-color-trigger-dot--multi', Boolean(isMulti));
			if (isMulti) {
				dot.style.backgroundColor = '';
			} else if (hex) {
				dot.style.backgroundColor = hex;
			}
		}

		const i18n = (typeof window !== 'undefined' && window.mrTeeProductData && window.mrTeeProductData.i18n) || {};
		const colorLabel = i18n.colorLabel || 'Couleur';
		const fallbackChoose = i18n.chooseColor || 'Choisir';
		const displayName = name || fallbackChoose;
		triggerBtn.setAttribute('aria-label', `${colorLabel} : ${displayName}`);
		triggerBtn.setAttribute('title', name || colorLabel);
	}

	/**
	 * Synchronize color swatch picker visibility, mobile color switch trigger state,
	 * Multicolore pill availability, active pill state, and color name readout whenever a line
	 * initializes or switches fonts.
	 *
	 * @param {Element|null} fieldGroup `.mr-tee-field-group` element.
	 * @param {boolean}      isOtSvg    Whether the active font is an OpenType-SVG font.
	 * @param {Object|null}  fontDef    Optional active font definition.
	 */
	syncSwatchesForLine(fieldGroup, isOtSvg, fontDef = null) {
		const swatchesWrap = fieldGroup ? fieldGroup.querySelector('.mr-tee-color-swatches-wrap') : null;
		if (!swatchesWrap) {
			this.syncColorTrigger(fieldGroup, { hidden: true });
			return;
		}

		const isMasked = swatchesWrap.getAttribute('data-masked') === '1';
		if (isOtSvg || isMasked) {
			swatchesWrap.style.display = 'none';
			this.syncColorTrigger(fieldGroup, { hidden: true });
			return;
		}
		swatchesWrap.style.display = '';

		const i18n = (typeof window !== 'undefined' && window.mrTeeProductData && window.mrTeeProductData.i18n) || {};
		const colorRefId = swatchesWrap.getAttribute('data-color-ref-id');
		const colorRef = colorRefId ? this.store.getReference(colorRefId) : null;
		const supportsMulti = Boolean(
			(fontDef && fontDef.multi_color)
			|| (fontDef && Array.isArray(fontDef.palette) && fontDef.palette.length > 1)
			|| (colorRef && colorRef.colorMode === 'multi')
			|| (colorRef && Array.isArray(colorRef._initialMultiPalette) && colorRef._initialMultiPalette.length > 1 && (!fontDef || fontDef.multi_color !== false))
		);

		const multiBtn = swatchesWrap.querySelector('.mr-tee-color-swatch-pill--multi, [data-color-hex="multi"]');
		if (multiBtn) {
			multiBtn.style.display = supportsMulti ? '' : 'none';
		}

		const isMultiActive = Boolean(colorRef && colorRef.colorMode === 'multi' && supportsMulti);
		const rawActiveHex = (!isMultiActive && colorRef && colorRef.colorHex)
			? String(colorRef.colorHex).toLowerCase()
			: '';

		const pills = Array.from(swatchesWrap.querySelectorAll('.mr-tee-color-swatch-pill'));
		const solidHexes = pills
			.map((sp) => String(sp.getAttribute('data-color-hex') || '').toLowerCase())
			.filter((h) => h && h !== 'multi');
		const resolvedActiveHex = (!isMultiActive && rawActiveHex)
			? (findClosestSwatchHex(rawActiveHex, solidHexes) || rawActiveHex)
			: '';

		let matchedName = '';
		let matchedHex = resolvedActiveHex || rawActiveHex;

		pills.forEach((sp) => {
			const spHex = String(sp.getAttribute('data-color-hex') || '').toLowerCase();
			const isActive = (spHex === 'multi')
				? isMultiActive
				: Boolean(!isMultiActive && resolvedActiveHex && spHex === resolvedActiveHex);
			sp.classList.toggle('is-active', isActive);
			sp.setAttribute('aria-pressed', isActive ? 'true' : 'false');
			if (isActive) {
				matchedName = sp.getAttribute('data-color-name') || sp.getAttribute('title') || '';
				if (spHex !== 'multi') {
					matchedHex = spHex;
				}
			}
		});

		if (isMultiActive && !matchedName) {
			matchedName = i18n.multicolorLabel || 'Multicolore';
		}

		const nameReadout = swatchesWrap.querySelector('.mr-tee-active-color-name');
		if (nameReadout) {
			nameReadout.textContent = matchedName;
		}

		this.syncColorTrigger(fieldGroup, {
			hidden: false,
			isMulti: isMultiActive,
			hex: isMultiActive ? '' : matchedHex,
			name: matchedName,
		});
	}

	/**
	 * Lazily apply inline `font-family` previews onto inactive `.mr-tee-font-pill` buttons
	 * after initial paint or on user interaction so every font pill displays its real typeface.
	 *
	 * @param {Element|null} scroller `.mr-tee-font-pills` container element.
	 */
	hydrateFontPillsInScroller(scroller) {
		if (!scroller || scroller._mrTeeFontsHydrated) return;
		scroller._mrTeeFontsHydrated = true;
		let appliedAny = false;
		scroller.querySelectorAll('.mr-tee-font-pill[data-font-family]').forEach((pill) => {
			const fam = pill.getAttribute('data-font-family');
			if (fam && !pill.style.fontFamily) {
				pill.style.fontFamily = `'${fam}', sans-serif`;
				appliedAny = true;
			}
		});
		if (appliedAny && typeof document !== 'undefined' && document.fonts && document.fonts.ready && typeof document.fonts.ready.then === 'function') {
			document.fonts.ready.then(() => this.scrollActiveFontPillsIntoView()).catch(() => {});
		}
	}

	/**
	 * Horizontally scroll each `.mr-tee-font-pills` strip so its active pill is completely clear
	 * of the trailing fade mask on load without leaving awkward sliver edges or vertical page scroll (LTR & RTL).
	 */
	scrollActiveFontPillsIntoView() {
		if (!this.container) return;
		const fadeClearance = 26;
		this.container.querySelectorAll('.mr-tee-font-pills').forEach((scroller) => {
			if (scroller._mrTeeUserScrolled) return;
			const activePill = scroller.querySelector('.mr-tee-font-pill.is-active');
			if (!activePill) return;
			const viewW = scroller.clientWidth || 0;
			if (viewW <= 0 || scroller.scrollWidth <= viewW + 1) {
				scroller.classList.remove('is-scrolled-left');
				return;
			}

			const isRtl = typeof window !== 'undefined' && window.getComputedStyle(scroller).direction === 'rtl';
			if (isRtl) {
				const sRect = scroller.getBoundingClientRect();
				const pRect = activePill.getBoundingClientRect();
				if (pRect.left < sRect.left + fadeClearance || pRect.right > sRect.right) {
					const delta = (pRect.left - sRect.left) - Math.max(fadeClearance, Math.round((viewW - pRect.width) / 2));
					scroller.scrollLeft += delta;
				}
				scroller.classList.toggle('is-scrolled-left', Math.abs(scroller.scrollLeft) > 4);
				return;
			}

			const pillLeft = activePill.offsetLeft;
			const pillRight = pillLeft + activePill.offsetWidth;
			const currentLeft = scroller.scrollLeft;
			if (pillLeft < currentLeft || pillRight > currentLeft + viewW - fadeClearance) {
				const maxScroll = Math.max(0, scroller.scrollWidth - viewW);
				const centered = Math.max(0, pillLeft - Math.max(8, Math.round((viewW - activePill.offsetWidth) / 2)));
				const clearRight = Math.max(0, pillRight + fadeClearance - viewW);
				let targetLeft = Math.min(maxScroll, Math.max(centered, clearRight));

				// Snap past any tiny (<26px) sliced trailing edge of a previous pill so the leftmost visible pill starts crisp
				const pills = Array.from(scroller.querySelectorAll('.mr-tee-font-pill'));
				for (let i = 0; i < pills.length - 1; i++) {
					const p = pills[i];
					const pEnd = p.offsetLeft + p.offsetWidth;
					const visibleRemnant = pEnd - targetLeft;
					if (p.offsetLeft < targetLeft && visibleRemnant > 0 && visibleRemnant < 26) {
						const nextLeft = Math.max(0, pills[i + 1].offsetLeft - 1);
						if (nextLeft <= pillLeft && nextLeft <= maxScroll) {
							targetLeft = nextLeft;
						}
						break;
					}
				}
				scroller.scrollLeft = targetLeft;
			}
			scroller.classList.toggle('is-scrolled-left', scroller.scrollLeft > 4);
		});
	}

	initDom() {
		this.container = document.getElementById('mr-tee-editor-container');
		this.countModal = document.getElementById('mr-tee-count-modal');
		this.warningModal = document.getElementById('mr-tee-warning-modal');

		// Portal modals directly to document.body so ancestor overflow:hidden / transforms / stacking contexts never clip dialogs
		[this.countModal, this.warningModal].forEach((modal) => {
			if (modal && document.body && modal.parentElement !== document.body) {
				document.body.appendChild(modal);
			}
		});

		this.activeCountDisplay = document.getElementById('mr-tee-active-count-display');
		this.stepVal = document.getElementById('mr-tee-step-val');
		const parentCartForm = this.container ? this.container.closest('form.cart') : null;
		this.cartForm = parentCartForm || document.querySelector('form.cart');
		this.addToCartBtn = (parentCartForm && parentCartForm.querySelector('button[type="submit"], input[type="submit"], .single_add_to_cart_button'))
			|| document.querySelector('form.cart button[type="submit"], form.cart input[type="submit"], .single_add_to_cart_button');

		this.initMobileFormRelocation();

		if (this.container) {
			const pillScrollers = Array.from(this.container.querySelectorAll('.mr-tee-font-pills'));
			pillScrollers.forEach((scroller) => {
				scroller.addEventListener('scroll', () => {
					scroller.classList.toggle('is-scrolled-left', Math.abs(scroller.scrollLeft) > 4);
				}, { passive: true });
				scroller.addEventListener('pointerdown', () => {
					scroller._mrTeeUserScrolled = true;
					this.hydrateFontPillsInScroller(scroller);
				}, { once: true, passive: true });
				scroller.addEventListener('wheel', () => {
					scroller._mrTeeUserScrolled = true;
					this.hydrateFontPillsInScroller(scroller);
				}, { once: true, passive: true });
				['pointerenter', 'touchstart', 'focusin'].forEach((evt) => {
					scroller.addEventListener(evt, () => this.hydrateFontPillsInScroller(scroller), { once: true, passive: true });
				});
			});

			// Hydrate real visual typeface previews on all font pills right after first paint (unless user enabled Data-Saver)
			const saveData = Boolean(typeof navigator !== 'undefined' && navigator.connection && navigator.connection.saveData);
			if (!saveData && pillScrollers.length > 0) {
				const hydrateAllIdle = () => pillScrollers.forEach((s) => this.hydrateFontPillsInScroller(s));
				if (typeof window !== 'undefined' && typeof window.requestIdleCallback === 'function') {
					setTimeout(() => window.requestIdleCallback(hydrateAllIdle, { timeout: 800 }), 250);
				} else {
					setTimeout(hydrateAllIdle, 350);
				}
			}

			requestAnimationFrame(() => this.scrollActiveFontPillsIntoView());
			if (document.fonts && document.fonts.ready && typeof document.fonts.ready.then === 'function') {
				document.fonts.ready.then(() => this.scrollActiveFontPillsIntoView()).catch(() => {});
			}
		}
	}

	/**
	 * On mobile (< 992px), place the live `<form class="cart">` (Personalization + Variations + Add to Cart)
	 * directly beneath the gallery/view pills and ABOVE product title & description so shoppers customize
	 * right under the garment preview. Automatically restores the exact desktop position at >= 992px.
	 * Works across Classic WooCommerce themes (preserving `.entry-summary` CSS selectors), Block/FSE themes,
	 * and Page Builders (Elementor, Divi, Bricks, Flatsome).
	 */
	initMobileFormRelocation() {
		if (typeof window === 'undefined' || !this.container || !this.cartForm) return;
		const cartForm = this.cartForm;
		if (!cartForm.parentNode || !cartForm.contains(this.container)) return;

		const galleryRoot = document.getElementById('mr-tee-gallery-root')
			|| document.querySelector('.woocommerce-product-gallery, .wp-block-woocommerce-product-image-gallery');
		if (!galleryRoot || !galleryRoot.parentNode) return;

		// Preserve exact original DOM position via an invisible comment anchor
		const originAnchor = document.createComment('mr-tee-cart-form-origin');
		cartForm.parentNode.insertBefore(originAnchor, cartForm);

		let mobileSlot = null;
		const mql = typeof window.matchMedia === 'function' ? window.matchMedia('(max-width: 991px)') : null;

		const isAdjacentClassicSummary = (summaryEl) => {
			if (!summaryEl || !galleryRoot) return false;
			if (galleryRoot.nextElementSibling === summaryEl) return true;
			// Allow lightweight hidden/badge siblings between gallery and summary inside same product wrapper
			if (galleryRoot.parentNode === summaryEl.parentNode) {
				let sib = galleryRoot.nextElementSibling;
				while (sib && sib !== summaryEl) {
					const cs = window.getComputedStyle(sib);
					const h = sib.getBoundingClientRect ? sib.getBoundingClientRect().height : 0;
					if (cs.display !== 'none' && h > 16) return false;
					sib = sib.nextElementSibling;
				}
				return sib === summaryEl;
			}
			return false;
		};

		const applyPlacement = () => {
			const isMobile = mql ? mql.matches : window.innerWidth <= 991;
			if (isMobile) {
				const summary = originAnchor.parentNode && originAnchor.parentNode.closest
					? originAnchor.parentNode.closest('.summary, .entry-summary')
					: null;
				if (summary && isAdjacentClassicSummary(summary)) {
					// Classic WooCommerce themes: prepend inside .entry-summary so ALL theme `.entry-summary form.cart` CSS stays intact
					if (summary.firstChild !== cartForm) {
						summary.insertBefore(cartForm, summary.firstChild);
					}
					if (mobileSlot && mobileSlot.parentNode) {
						mobileSlot.remove();
					}
				} else {
					// Block/FSE themes & Page Builders: place in dedicated slot immediately after #mr-tee-gallery-root
					if (!mobileSlot) {
						mobileSlot = document.createElement('div');
						mobileSlot.id = 'mr-tee-mobile-form-slot';
						mobileSlot.className = 'mr-tee-mobile-form-slot';
					}
					if (mobileSlot.previousElementSibling !== galleryRoot) {
						galleryRoot.insertAdjacentElement('afterend', mobileSlot);
					}
					if (cartForm.parentNode !== mobileSlot) {
						mobileSlot.appendChild(cartForm);
					}
				}
				cartForm.classList.add('mr-tee-mobile-form-relocated');
			} else {
				// Desktop (>= 992px): restore exact original DOM position inside right summary column
				if (originAnchor.parentNode && originAnchor.nextSibling !== cartForm) {
					originAnchor.parentNode.insertBefore(cartForm, originAnchor.nextSibling);
				}
				cartForm.classList.remove('mr-tee-mobile-form-relocated');
				if (mobileSlot && mobileSlot.parentNode) {
					mobileSlot.remove();
				}
			}
		};

		applyPlacement();
		if (mql) {
			if (typeof mql.addEventListener === 'function') {
				mql.addEventListener('change', applyPlacement);
			} else if (typeof mql.addListener === 'function') {
				mql.addListener(applyPlacement);
			}
		} else {
			window.addEventListener('resize', applyPlacement, { passive: true });
		}
	}

	/**
	 * Resolve the currently active font definition for a `.mr-tee-field-group`.
	 *
	 * @param {Element|null} fieldGroup `.mr-tee-field-group` element.
	 * @return {{ fontId: string, fontDef: Object|null, isOtSvg: boolean }}
	 */
	resolveFieldActiveFont(fieldGroup) {
		if (!fieldGroup) return { fontId: '', fontDef: null, isOtSvg: false };
		const lineIdx = parseInt(fieldGroup.getAttribute('data-line-index'), 10);
		const lineConfig = (!Number.isNaN(lineIdx) && this.config.text_lines && this.config.text_lines[lineIdx]) || {};
		const ref = lineConfig.ref_id ? this.store.getReference(lineConfig.ref_id) : null;
		const activePill = fieldGroup.querySelector('.mr-tee-font-pill.is-active');
		const fontId = (ref && ref.fontId)
			|| (activePill && activePill.getAttribute('data-font-id'))
			|| lineConfig.font
			|| (Array.isArray(lineConfig.allowed_fonts) && lineConfig.allowed_fonts[0])
			|| '';
		const fonts = (window.mrTeeProductData && window.mrTeeProductData.fonts)
			|| (window.mrTeeProductData && window.mrTeeProductData.config && window.mrTeeProductData.config.fonts)
			|| {};
		const fontDef = (fontId && fonts[fontId]) ? fonts[fontId] : null;
		const isOtSvg = Boolean(fontDef && (fontDef.font_type === 'opentype_svg' || fontDef.color_svg));
		return { fontId, fontDef, isOtSvg };
	}

	bindEvents() {
		if (!this.container) return;
		const i18n = (window.mrTeeProductData && window.mrTeeProductData.i18n) || {};

		// 0. Side Section Headers (Recto / Verso clickable card headers sync directly with gallery stage)
		this.container.querySelectorAll('.mr-tee-side-section-header[data-side-id]').forEach((headerBtn) => {
			headerBtn.addEventListener('click', () => {
				const sideId = headerBtn.getAttribute('data-side-id');
				if (sideId) {
					this.setActiveSide(sideId);
					this.notifySideFocus(sideId);
				}
			});
		});

		// Synchronize initial color picker visibility across all field groups on mount
		this.container.querySelectorAll('.mr-tee-field-group').forEach((fieldGroup) => {
			if (fieldGroup.getAttribute('data-field-type') === 'image') return;
			const { fontDef, isOtSvg } = this.resolveFieldActiveFont(fieldGroup);
			this.syncSwatchesForLine(fieldGroup, isOtSvg, fontDef);
		});

		// 1. Text Inputs with Live Character Counters, Quick Clear Button, Max-Lines Enforcement & Stage Sync
		this.container.querySelectorAll('.mr-tee-text-inputs-wrap .mr-tee-text-input').forEach((input) => {
			const group = input.closest('.mr-tee-field-group');
			const floatWrap = input.closest('.mr-tee-floating-label-wrap');
			const lineIdx = parseInt(group.getAttribute('data-line-index'), 10);
			const lineConfig = (this.config.text_lines && this.config.text_lines[lineIdx]) || {};
			const counterWrap = group.querySelector('.mr-tee-char-counter');
			const charCounter = group.querySelector('.mr-tee-char-count');
			const clearBtn = group.querySelector('.mr-tee-input-clear-btn');
			const maxChars = parseInt(input.getAttribute('maxlength') || lineConfig.max_chars || 0, 10) || 0;
			const maxLines = input.tagName === 'TEXTAREA'
				? (parseInt(lineConfig.max_lines || input.getAttribute('data-max-lines') || 0, 10) || 0)
				: 0;

			const syncClearState = (val) => {
				const hasVal = String(val || '').length > 0;
				if (floatWrap) {
					floatWrap.classList.toggle('has-value', hasVal);
				}
				if (clearBtn) {
					clearBtn.style.display = hasVal ? '' : 'none';
				}
			};

			this.updateCharCounter(counterWrap, charCounter, input.value, maxChars);
			syncClearState(input.value);

			if (clearBtn) {
				clearBtn.addEventListener('click', (e) => {
					e.preventDefault();
					this.notifySideFocus(input);
					input.value = '';
					this.updateCharCounter(counterWrap, charCounter, '', maxChars);
					syncClearState('');
					if (lineConfig.ref_id) {
						this.store.setTextValue(lineConfig.ref_id, '');
					}
					input.focus();
				});
			}

			const warmLineFont = () => {
				const { fontId: activeFontId, fontDef } = this.resolveFieldActiveFont(group);
				if (activeFontId) {
					const sData = SvgRenderer.getSvgFontData(activeFontId);
					if (sData) {
						SvgRenderer.prewarmFontGlyphs(sData, { immediate: true });
					} else if (fontDef && (fontDef.font_type === 'opentype_svg' || fontDef.color_svg)) {
						SvgRenderer.ensureSvgFontLoaded(activeFontId, fontDef, { prewarmGlyphs: true }).catch(() => {});
					}
				}
			};

			input.addEventListener('pointerdown', warmLineFont, { passive: true });
			input.addEventListener('focus', () => {
				this.closeAllColorTrays(group);
				warmLineFont();
			});

			if (maxLines > 0) {
				input.addEventListener('keydown', (e) => {
					if (e.key !== 'Enter') return;
					const normalized = input.value.replace(/\r\n?/g, '\n');
					const curLines = normalized.split('\n').length;
					if (curLines >= maxLines) {
						const start = input.selectionStart || 0;
						const end = input.selectionEnd || 0;
						const selectedHasNewline = input.value.slice(start, end).includes('\n');
						if (!selectedHasNewline) {
							e.preventDefault();
						}
					}
				});
			}

			input.addEventListener('input', () => {
				this.notifySideFocus(input);
				let val = input.value;
				if (maxLines > 0) {
					const normalized = val.replace(/\r\n?/g, '\n');
					const parts = normalized.split('\n');
					if (parts.length > maxLines) {
						val = parts.slice(0, maxLines).join('\n');
						input.value = val;
					}
				}
				this.updateCharCounter(counterWrap, charCounter, val, maxChars);
				syncClearState(val);
				if (lineConfig.ref_id) {
					this.store.setTextValue(lineConfig.ref_id, val);
				}
			});
		});

		// 2. Font Selector Pills
		this.container.querySelectorAll('.mr-tee-font-pill').forEach((pill) => {
			pill.addEventListener('pointerdown', () => {
				const scroller = pill.closest('.mr-tee-font-pills');
				if (scroller) this.hydrateFontPillsInScroller(scroller);
				const fontId = pill.getAttribute('data-font-id');
				const fonts = (window.mrTeeProductData && window.mrTeeProductData.fonts) || {};
				const fontDef = fonts[fontId] || null;
				if (fontDef && (fontDef.font_type === 'opentype_svg' || fontDef.color_svg)) {
					SvgRenderer.ensureSvgFontLoaded(fontId, fontDef, { prewarmGlyphs: false }).catch(() => {});
				}
			}, { passive: true });

			pill.addEventListener('focus', () => {
				const scroller = pill.closest('.mr-tee-font-pills');
				if (scroller) this.hydrateFontPillsInScroller(scroller);
			});

			pill.addEventListener('click', () => {
				this.notifySideFocus(pill);
				const pillsContainer = pill.closest('.mr-tee-font-pills');
				if (pillsContainer) this.hydrateFontPillsInScroller(pillsContainer);
				const fieldGroup = pill.closest('.mr-tee-field-group');
				this.closeAllColorTrays(fieldGroup);
				const lineIdx = parseInt(pillsContainer.getAttribute('data-line-index'), 10);
				const lineConfig = (this.config.text_lines && this.config.text_lines[lineIdx]) || {};
				const fontId = pill.getAttribute('data-font-id');

				const refId = lineConfig.ref_id || `id-ref-text-line-${lineIdx + 1}`;
				const ref = this.store.getReference(refId);
				const committedFontId = (ref && ref.fontId) ? ref.fontId : lineConfig.font;
				const committedPill = committedFontId
					? Array.from(pillsContainer.querySelectorAll('.mr-tee-font-pill')).find(
						(p) => p.getAttribute('data-font-id') === String(committedFontId)
					)
					: pillsContainer.querySelector('.mr-tee-font-pill.is-active');

				// Highlight active immediately for snappy feedback
				pillsContainer.querySelectorAll('.mr-tee-font-pill').forEach((p) => {
					p.classList.remove('is-active', 'is-loading');
					p.removeAttribute('aria-busy');
				});
				pill.classList.add('is-active');

				const fonts = (window.mrTeeProductData && window.mrTeeProductData.fonts)
					|| (window.mrTeeProductData && window.mrTeeProductData.config && window.mrTeeProductData.config.fonts)
					|| {};
				const fontDef = fonts[fontId] || null;
				const isOtSvg = Boolean(fontDef && (fontDef.font_type === 'opentype_svg' || fontDef.color_svg));

				// Immediately hide color picker when tapping an OpenType-SVG pill so UI feels instant
				if (isOtSvg) {
					this.syncSwatchesForLine(fieldGroup, true, fontDef);
				}

				const reqToken = (lineConfig._fontLoadToken || 0) + 1;
				lineConfig._fontLoadToken = reqToken;
				lineConfig.font = fontId;
				const currentInput = document.getElementById(`mr_tee_line_${lineIdx}`);
				const currentText = currentInput ? currentInput.value : (lineConfig.default_text || '');

				if (isOtSvg) {
					const isAlreadyLoaded = Boolean(SvgRenderer.getSvgFontData(fontId));
					if (!isAlreadyLoaded) {
						pill.classList.add('is-loading');
						pill.setAttribute('aria-busy', 'true');
					}
					const isUpper = (lineConfig.casing === 'UPPER') || Boolean(fontDef && fontDef.uppercase);
					const warmText = isUpper ? currentText.toUpperCase() : currentText;
					const revertSelection = () => {
						if (lineConfig._fontLoadToken !== reqToken) return;
						lineConfig.font = committedFontId;
						pill.classList.remove('is-active', 'is-loading');
						pill.removeAttribute('aria-busy');
						if (committedPill && committedPill !== pill) {
							committedPill.classList.add('is-active');
						}
						const prevDef = committedFontId ? (fonts[committedFontId] || null) : null;
						const prevIsOt = Boolean(prevDef && (prevDef.font_type === 'opentype_svg' || prevDef.color_svg));
						this.syncSwatchesForLine(fieldGroup, prevIsOt, prevDef);
					};
					SvgRenderer.ensureSvgFontLoaded(fontId, fontDef, { prewarmGlyphs: true, priorityText: warmText })
						.then((loadedData) => {
							pill.classList.remove('is-loading');
							pill.removeAttribute('aria-busy');
							if (lineConfig._fontLoadToken !== reqToken || lineConfig.font !== fontId) return;
							if (!loadedData) {
								revertSelection();
								return;
							}
							this.store.setTextFont(refId, fontId, fontDef);
							this.syncSwatchesForLine(fieldGroup, true, fontDef);
						})
						.catch(() => {
							revertSelection();
						});
				} else {
					this.store.setTextFont(refId, fontId, fontDef);
					this.syncSwatchesForLine(fieldGroup, false, fontDef);
				}
			});
		});

		// 2a. Mobile Left Color Switch Trigger & Collapsible Inline Color Tray
		this.container.querySelectorAll('.mr-tee-color-trigger-btn').forEach((triggerBtn) => {
			triggerBtn.addEventListener('click', (e) => {
				e.preventDefault();
				e.stopPropagation();
				const fieldGroup = triggerBtn.closest('.mr-tee-field-group');
				if (!fieldGroup) return;
				const willOpen = !fieldGroup.classList.contains('is-color-open');
				this.closeAllColorTrays(willOpen ? fieldGroup : null);
				fieldGroup.classList.toggle('is-color-open', willOpen);
				triggerBtn.setAttribute('aria-expanded', willOpen ? 'true' : 'false');
				if (willOpen) {
					const list = fieldGroup.querySelector('.mr-tee-color-swatches-list');
					const activeDot = list ? list.querySelector('.mr-tee-color-swatch-pill.is-active') : null;
					if (list && activeDot && list.scrollWidth > list.clientWidth + 1) {
						const dotLeft = activeDot.offsetLeft;
						const dotRight = dotLeft + activeDot.offsetWidth;
						if (dotLeft < list.scrollLeft || dotRight > list.scrollLeft + list.clientWidth) {
							list.scrollLeft = Math.max(0, dotLeft - Math.round((list.clientWidth - activeDot.offsetWidth) / 2));
						}
					}
				}
			});
		});

		this.container.querySelectorAll('.mr-tee-color-tray-close').forEach((closeBtn) => {
			closeBtn.addEventListener('click', (e) => {
				e.preventDefault();
				e.stopPropagation();
				this.closeAllColorTrays();
			});
		});

		// 2b. Color Swatch Selector Pills (Embroidery Thread, Solid Ink & Multicolore Palettes)
		this.container.querySelectorAll('.mr-tee-color-swatch-pill').forEach((pill) => {
			pill.addEventListener('click', () => {
				this.notifySideFocus(pill);
				const group = pill.closest('.mr-tee-color-swatches-wrap');
				const fieldGroup = pill.closest('.mr-tee-field-group');
				const colorRefId = group ? group.getAttribute('data-color-ref-id') : 'id-ref-color-selector';
				const colorHex = pill.getAttribute('data-color-hex');
				const colorName = pill.getAttribute('data-color-name') || pill.getAttribute('title') || '';
				const { fontDef } = this.resolveFieldActiveFont(fieldGroup);

				if (group) {
					group.querySelectorAll('.mr-tee-color-swatch-pill').forEach((p) => {
						p.classList.remove('is-active');
						p.setAttribute('aria-pressed', 'false');
					});
					const nameReadout = group.querySelector('.mr-tee-active-color-name');
					if (nameReadout) {
						nameReadout.textContent = colorName;
					}
				}
				pill.classList.add('is-active');
				pill.setAttribute('aria-pressed', 'true');

				if (colorHex) {
					this.syncColorTrigger(fieldGroup, {
						hidden: false,
						isMulti: colorHex === 'multi',
						hex: colorHex === 'multi' ? '' : colorHex,
						name: colorName,
					});
					this.store.setColorSelection(colorRefId, colorHex, fontDef);
				}
			});
		});

		// 3. Count Selector Modal Triggers
		const openCountBtn = document.getElementById('mr-tee-open-count-modal');
		const closeCountBtn = document.getElementById('mr-tee-close-count-modal');
		const confirmCountBtn = document.getElementById('mr-tee-confirm-count');
		const stepMinusBtn = document.getElementById('mr-tee-step-minus');
		const stepPlusBtn = document.getElementById('mr-tee-step-plus');

		const minItems = (this.config.repeater && this.config.repeater.min_items !== undefined)
			? Math.max(0, parseInt(this.config.repeater.min_items, 10) || 0)
			: 0;
		const maxItems = (this.config.repeater && this.config.repeater.max_items !== undefined)
			? Math.max(minItems, parseInt(this.config.repeater.max_items, 10) || 0)
			: 6;
		this._repeaterMin = minItems;
		this._repeaterMax = maxItems;
		this._stepMinusBtn = stepMinusBtn;
		this._stepPlusBtn = stepPlusBtn;

		let tempCount = Math.max(minItems, Math.min(maxItems, this.store.state.activeCount));

		if (openCountBtn && this.countModal) {
			openCountBtn.addEventListener('click', () => {
				tempCount = Math.max(minItems, Math.min(maxItems, this.store.state.activeCount));
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
				this.notifySideFocus('front');
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
				if (tempCount > minItems) {
					tempCount--;
					this.updateModalCountDisplay(tempCount);
				}
			});
		}

		if (stepPlusBtn) {
			stepPlusBtn.addEventListener('click', () => {
				if (tempCount < maxItems) {
					tempCount++;
					this.updateModalCountDisplay(tempCount);
				}
			});
		}

		// Dot buttons inside modal
		if (this.countModal) {
			this.countModal.querySelectorAll('.mr-tee-count-dot-btn').forEach((dot) => {
				dot.addEventListener('click', () => {
					const rawDot = parseInt(dot.getAttribute('data-count'), 10);
					if (!Number.isNaN(rawDot)) {
						tempCount = Math.max(minItems, Math.min(maxItems, rawDot));
						this.updateModalCountDisplay(tempCount);
					}
				});
			});

			this.countModal.addEventListener('click', (e) => {
				if (e.target === this.countModal) {
					this.countModal.style.display = 'none';
				}
			});
		}

		// 4. Individual Mushroom Accordions
		this.container.querySelectorAll('.mr-tee-repeater-item-card').forEach((card) => {
			const itemIdx = parseInt(card.getAttribute('data-item-index'), 10);
			const header = card.querySelector('.mr-tee-rep-card-header');
			const toggleBtn = card.querySelector('.mr-tee-rep-toggle-btn');
			const nameInput = card.querySelector('.mr-tee-rep-name-input');
			const counterWrap = card.querySelector('.mr-tee-char-counter');
			const charCounter = card.querySelector('.mr-tee-char-count');
			const summaryText = card.querySelector('.mr-tee-rep-summary-text');
			const thumbImg = card.querySelector('.mr-tee-rep-thumb img');
			const repMaxChars = nameInput
				? (parseInt(nameInput.getAttribute('maxlength') || (this.config.repeater && this.config.repeater.max_chars) || 0, 10) || 0)
				: 0;

			if (nameInput) {
				this.updateCharCounter(counterWrap, charCounter, nameInput.value, repMaxChars);
			}

			// Accordion expand/collapse
			if (header) {
				header.addEventListener('click', (e) => {
					if (e.target && e.target.closest('input')) return;
					const isOpen = card.classList.toggle('is-open');
					if (toggleBtn) toggleBtn.setAttribute('aria-expanded', isOpen ? 'true' : 'false');
				});
			}

			// Name input
			if (nameInput) {
				nameInput.addEventListener('input', () => {
					this.notifySideFocus('front');
					const val = nameInput.value;
					this.updateCharCounter(counterWrap, charCounter, val, repMaxChars);
					if (summaryText) summaryText.textContent = val || (i18n.unnamed || 'Sans nom');
					this.store.setMushroomName(itemIdx, val);
				});
			}

			// Icon radio picker
			card.querySelectorAll('.mr-tee-icon-option input[type="radio"]').forEach((radio) => {
				radio.addEventListener('change', () => {
					this.notifySideFocus('front');
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

		// 5. Capture-phase Add-to-Cart click + form submit interceptors (beats theme AJAX cart handlers)
		const handleAddToCartIntercept = (e) => {
			if (this.bypassWarning) {
				return; // Allow final submission after export completes
			}
			if (this.isSubmitting) {
				e.preventDefault();
				e.stopImmediatePropagation();
				return false;
			}
			if (
				this.addToCartBtn && (
					this.addToCartBtn.disabled ||
					this.addToCartBtn.classList.contains('disabled') ||
					this.addToCartBtn.classList.contains('wc-variation-selection-needed')
				)
			) {
				return; // Let WooCommerce handle unselected variation alert natively
			}

			this.store.validateWarnings();
			if (this.store.state.hasUneditedWarnings) {
				e.preventDefault();
				e.stopImmediatePropagation();
				this.showWarningModal();
				return false;
			}

			// No warnings: pause submission, prepare preview & payload, then re-submit
			e.preventDefault();
			e.stopImmediatePropagation();
			this.runExportAndSubmit();
		};

		if (this.addToCartBtn) {
			this.addToCartBtn.addEventListener('click', handleAddToCartIntercept, { capture: true });
		}
		const targetForm = (this.addToCartBtn && this.addToCartBtn.closest('form')) || this.cartForm;
		if (targetForm) {
			targetForm.addEventListener('submit', handleAddToCartIntercept, { capture: true });
		}

		// Warning Modal buttons
		const btnModify = document.getElementById('mr-tee-btn-modify');
		const btnProceed = document.getElementById('mr-tee-btn-proceed');
		const closeWarnBtn = document.getElementById('mr-tee-close-warning-modal');

		if (btnModify && this.warningModal) {
			btnModify.addEventListener('click', () => {
				this.warningModal.style.display = 'none';
				let targetInput = null;
				const warnings = (this.store && this.store.state && Array.isArray(this.store.state.warnings)) ? this.store.state.warnings : [];
				for (const w of warnings) {
					if (w && w.id) {
						const safeId = (typeof CSS !== 'undefined' && typeof CSS.escape === 'function')
							? CSS.escape(String(w.id))
							: String(w.id).replace(/"/g, '\\"');
						const el = this.container.querySelector(
							`input[data-ref-id="${safeId}"], textarea[data-ref-id="${safeId}"], [data-ref-id="${safeId}"]`
						);
						if (el) {
							targetInput = el;
							break;
						}
					}
				}
				if (!targetInput) {
					targetInput = this.container.querySelector('.mr-tee-text-input[data-warning="1"], .mr-tee-image-file-input[data-warning="1"]');
				}
				if (targetInput) {
					const repCard = targetInput.closest('.mr-tee-repeater-item-card');
					if (repCard) {
						this.notifySideFocus('front');
						if (!repCard.classList.contains('is-open')) {
							repCard.classList.add('is-open');
							const repToggle = repCard.querySelector('.mr-tee-rep-toggle-btn');
							if (repToggle) repToggle.setAttribute('aria-expanded', 'true');
						}
					} else {
						this.notifySideFocus(targetInput);
					}
					if (targetInput.classList.contains('mr-tee-image-file-input')) {
						const card = targetInput.closest('.mr-tee-image-field-card');
						const btn = card ? card.querySelector('.mr-tee-image-upload-btn') : null;
						if (card) card.scrollIntoView({ behavior: 'smooth', block: 'center' });
						if (btn) btn.focus();
					} else {
						targetInput.scrollIntoView({ behavior: 'smooth', block: 'center' });
						targetInput.focus();
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

		if (this.warningModal) {
			this.warningModal.addEventListener('click', (e) => {
				if (e.target === this.warningModal) {
					this.warningModal.style.display = 'none';
				}
			});
		}

		document.addEventListener('click', (e) => {
			if (!this.container) return;
			const openGroup = this.container.querySelector('.mr-tee-field-group.is-color-open');
			if (!openGroup) return;
			if (!e.target || !openGroup.contains(e.target)) {
				this.closeAllColorTrays();
			}
		});

		document.addEventListener('keydown', (e) => {
			if (e.key !== 'Escape') return;
			this.closeAllColorTrays();
			if (this.countModal && this.countModal.style.display !== 'none') {
				this.countModal.style.display = 'none';
			}
			if (this.warningModal && this.warningModal.style.display !== 'none') {
				this.warningModal.style.display = 'none';
			}
		});
	}

	async runExportAndSubmit() {
		if (this.isSubmitting) return;
		this.isSubmitting = true;

		const i18n = (window.mrTeeProductData && window.mrTeeProductData.i18n) || {};
		const btn = this.addToCartBtn;
		let origText = '';
		let origHtml = '';
		if (btn) {
			origText = btn.tagName === 'INPUT' ? btn.value : btn.textContent;
			origHtml = btn.tagName === 'INPUT' ? '' : btn.innerHTML;
			btn.setAttribute('aria-busy', 'true');
			btn.style.pointerEvents = 'none';
			btn.style.opacity = '0.75';
			if (btn.tagName === 'INPUT') {
				btn.value = i18n.preparingOrder || 'Préparation...';
			} else {
				btn.textContent = i18n.preparingOrder || 'Préparation...';
			}
		}

		const restoreBtn = () => {
			this.isSubmitting = false;
			this.bypassWarning = false;
			this._awaitingCartRedirect = false;
			if (btn) {
				btn.removeAttribute('aria-busy');
				btn.style.pointerEvents = '';
				btn.style.opacity = '';
				if (btn.tagName === 'INPUT') {
					btn.value = origText;
				} else if (origHtml) {
					btn.innerHTML = origHtml;
				}
			}
		};

		if (this.pendingUploads.length > 0) {
			try {
				await Promise.allSettled(this.pendingUploads.slice());
			} catch (e) {}
		}
		if (this.onExportRequested) {
			this.onExportRequested((ok = true) => {
				if (ok === false) {
					restoreBtn();
					window.alert(i18n.exportUploadError || 'Impossible d\'envoyer votre personnalisation. Veuillez réessayer.');
					return;
				}
				if (btn) {
					btn.style.pointerEvents = '';
					btn.style.opacity = '';
				}
				this.submitCartForm(restoreBtn);
			});
		} else {
			restoreBtn();
		}
	}

	bindImageFields() {
		if (!this.container) return;
		const i18n = (window.mrTeeProductData && window.mrTeeProductData.i18n) || {};

		const isAllowedImageFile = (file) => {
			if (!file) return false;
			if (file.type && ALLOWED_IMAGE_MIMES.has(file.type.toLowerCase())) {
				return true;
			}
			if (!file.type && file.name) {
				return /\.(png|jpe?g|webp|gif|svg)$/i.test(file.name);
			}
			return false;
		};

		this.container.querySelectorAll('.mr-tee-image-field-card').forEach((card) => {
			const fileInput = card.querySelector('.mr-tee-image-file-input');
			const uploadBtn = card.querySelector('.mr-tee-image-upload-btn');
			const resetBtn = card.querySelector('.mr-tee-image-reset-btn');
			const thumbWrap = card.querySelector('.mr-tee-img-field-thumb');
			const statusEl = card.querySelector('.mr-tee-img-field-status');
			const defaultImg = card.getAttribute('data-default-image') || '';

			const detachActiveUpload = () => {
				if (card._activeUploadPromise) {
					const idx = this.pendingUploads.indexOf(card._activeUploadPromise);
					if (idx !== -1) this.pendingUploads.splice(idx, 1);
					card._activeUploadPromise = null;
				}
			};

			if (uploadBtn && fileInput) {
				uploadBtn.addEventListener('click', () => {
					fileInput.click();
				});
			}

			if (resetBtn && fileInput) {
				resetBtn.addEventListener('click', () => {
					this.notifySideFocus(resetBtn);
					card._uploadToken = (card._uploadToken || 0) + 1;
					detachActiveUpload();
					fileInput.value = '';
					const refId = fileInput.getAttribute('data-ref-id');
					if (thumbWrap) {
						if (defaultImg) {
							thumbWrap.classList.remove('is-empty');
							thumbWrap.innerHTML = `<img src="${SvgRenderer.escapeXml(defaultImg)}" alt="" />`;
						} else {
							thumbWrap.classList.add('is-empty');
							thumbWrap.innerHTML = `<span class="mr-tee-img-field-placeholder-icon" aria-hidden="true">${SVG_PLACEHOLDER_IMAGE}</span>`;
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
					fileInput.value = '';
					if (!file) return;

					const setUploadError = (msg) => {
						if (statusEl) {
							statusEl.textContent = msg;
							statusEl.style.color = '#dc2626';
							statusEl.setAttribute('data-upload-state', 'error');
						}
					};

					if (!isAllowedImageFile(file)) {
						setUploadError(i18n.imageInvalidType || 'Format d\'image non pris en charge (PNG, JPG, WebP, GIF, SVG).');
						return;
					}
					if (file.size > MAX_IMAGE_BYTES) {
						setUploadError(i18n.imageTooLarge || 'L\'image dépasse la taille maximale autorisée (15 Mo).');
						return;
					}

					const refId = fileInput.getAttribute('data-ref-id');
					const token = (card._uploadToken || 0) + 1;
					card._uploadToken = token;
					detachActiveUpload();

					let previewDataUrl = '';
					try {
						previewDataUrl = await this.readImageAsDataUrl(file);
					} catch (err) {
						setUploadError(i18n.imageUploadError || 'Erreur lors du chargement de l\'image.');
						return;
					}

					if (card._uploadToken !== token) return;

					if (typeof window !== 'undefined' && previewDataUrl) {
						window.mrTeeImageB64Cache = window.mrTeeImageB64Cache || {};
						window.mrTeeImageB64Cache[previewDataUrl] = previewDataUrl;
					}

					if (thumbWrap) {
						thumbWrap.classList.remove('is-empty');
						thumbWrap.innerHTML = `<img src="${previewDataUrl}" alt="" />`;
					}
					if (statusEl) {
						statusEl.textContent = `${i18n.uploadingImage || 'Envoi en cours...'} (${file.name})`;
						statusEl.style.color = '#2563eb';
						statusEl.setAttribute('data-upload-state', 'uploading');
					}
					if (uploadBtn) {
						uploadBtn.textContent = i18n.changeImage || 'Changer l\'image';
					}
					if (resetBtn && (defaultImg || previewDataUrl)) {
						resetBtn.style.display = 'inline-flex';
					}

					// Automatically focus the corresponding garment view once a new image is actually added
					this.notifySideFocus(fileInput);

					// Immediate 0ms local preview in SVG scene graph
					if (refId) {
						this.store.setImageValue(refId, previewDataUrl, file.name, true);
					}

					// Upload ORIGINAL full-res file to server in background so 300 DPI prints stay crisp
					const uploadPromise = this.uploadCustomerImage(file).then((serverUrl) => {
						if (card._uploadToken !== token) return;
						if (serverUrl) {
							if (typeof window !== 'undefined') {
								window.mrTeeImageB64Cache = window.mrTeeImageB64Cache || {};
								window.mrTeeImageB64Cache[serverUrl] = previewDataUrl;
								const normUrl = normalizeSameHostUrl(serverUrl);
								if (normUrl) window.mrTeeImageB64Cache[normUrl] = previewDataUrl;
							}
							if (refId) {
								this.store.setImageValue(refId, serverUrl, file.name, true);
							}
							if (statusEl) {
								statusEl.textContent = `✓ ${file.name}`;
								statusEl.style.color = '#059669';
								statusEl.setAttribute('data-upload-state', 'done');
							}
						} else {
							setUploadError(i18n.imageUploadError || 'Erreur lors du chargement de l\'image.');
						}
					}).catch(() => {
						if (card._uploadToken !== token) return;
						setUploadError(i18n.imageUploadError || 'Erreur lors du chargement de l\'image.');
					});

					card._activeUploadPromise = uploadPromise;
					this.pendingUploads.push(uploadPromise);
					uploadPromise.finally(() => {
						if (card._activeUploadPromise === uploadPromise) {
							card._activeUploadPromise = null;
						}
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
		const ajaxUrl = resolveAjaxUrl();
		const uploadName = file.name || 'image.png';

		if (file.size > 550 * 1024) {
			const PrintExporter = await this.loadPrintExporter();
			const res = await PrintExporter.uploadBlobInChunks(file, {
				artifact: 'customer_image',
				fileName: uploadName,
			});
			return (res && res.url) ? String(res.url) : '';
		}

		try {
			const formData = new FormData();
			formData.append('action', 'mr_tee_upload_customer_image');
			formData.append('nonce', data.uploadNonce || '');
			formData.append('product_id', String(this.config.productId || data.productId || 0));
			formData.append('image_file', file, uploadName);

			const resp = await fetch(ajaxUrl, { method: 'POST', body: formData });
			if (resp.ok) {
				const json = await resp.json();
				if (json && json.success && json.data && json.data.url) {
					return String(json.data.url);
				}
			}
		} catch (e) {}

		// Fallback to resilient chunked uploader on any transient single-POST error
		const PrintExporter = await this.loadPrintExporter();
		const fallbackRes = await PrintExporter.uploadBlobInChunks(file, {
			artifact: 'customer_image',
			fileName: uploadName,
		});
		return (fallbackRes && fallbackRes.url) ? String(fallbackRes.url) : '';
	}

	submitCartForm(restoreBtn) {
		this.bypassWarning = true;
		this._awaitingCartRedirect = true;

		const cartUrl = (window.mrTeeProductData && window.mrTeeProductData.cartUrl) || '';
		if (window.jQuery && cartUrl && !this._boundAjaxRedirect) {
			this._boundAjaxRedirect = true;
			window.jQuery(document.body).on('added_to_cart.mrTeeRedirect', () => {
				if (this._awaitingCartRedirect) {
					this._awaitingCartRedirect = false;
					window.location.href = normalizeSameHostUrl(cartUrl) || cartUrl;
				}
			});
		}

		const form = (this.addToCartBtn ? this.addToCartBtn.closest('form') : null) || this.cartForm || document.querySelector('form.cart');
		if (form) {
			if (form.action && location.protocol === 'http:' && form.action.startsWith('https:')) {
				form.action = form.action.replace('https:', 'http:');
			} else if (form.action && location.protocol === 'https:' && form.action.startsWith('http:')) {
				form.action = form.action.replace('http:', 'https:');
			}
			const btn = this.addToCartBtn;
			const canUseSubmitter = Boolean(
				btn &&
				form.contains(btn) &&
				!btn.disabled &&
				(btn.type === 'submit' || (btn.tagName === 'BUTTON' && !btn.getAttribute('type')))
			);
			if (typeof form.requestSubmit === 'function') {
				try {
					if (canUseSubmitter) {
						form.requestSubmit(btn);
					} else {
						form.requestSubmit();
					}
				} catch (submitErr) {
					form.submit();
				}
			} else {
				form.submit();
			}
		} else if (this.addToCartBtn) {
			this.addToCartBtn.click();
		}

		setTimeout(() => {
			if (typeof restoreBtn === 'function') {
				restoreBtn();
			} else {
				this.bypassWarning = false;
				this.isSubmitting = false;
				this._awaitingCartRedirect = false;
			}
		}, 2500);
	}

	updateModalCountDisplay(count) {
		if (this.stepVal) {
			this.stepVal.textContent = count;
		}
		if (this._stepMinusBtn) {
			this._stepMinusBtn.disabled = count <= (this._repeaterMin || 0);
		}
		if (this._stepPlusBtn) {
			this._stepPlusBtn.disabled = count >= (this._repeaterMax !== undefined ? this._repeaterMax : 6);
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
		if (!this.warningModal) return;
		const i18n = (window.mrTeeProductData && window.mrTeeProductData.i18n) || {};
		const textEl = document.getElementById('mr-tee-warning-text');
		const warnings = (this.store && this.store.state && Array.isArray(this.store.state.warnings)) ? this.store.state.warnings : [];
		if (textEl) {
			if (warnings.length > 0) {
				const maxShow = 3;
				const labels = warnings.slice(0, maxShow).map((w) => {
					const name = w.name || i18n.fieldDefault || 'Champ';
					if (w.type === 'IMAGE') {
						return name;
					}
					const rawVal = String(w.value || '').trim().replace(/\s+/g, ' ');
					if (rawVal && rawVal !== name) {
						const clipped = rawVal.length > 24 ? `${rawVal.slice(0, 21)}...` : rawVal;
						return `${name} ("${clipped}")`;
					}
					return name;
				});
				const extra = warnings.length > maxShow ? ` (+${warnings.length - maxShow})` : '';
				const summary = `${labels.join(', ')}${extra}`;
				const singleTpl = i18n.warningSingle || 'Le champ suivant n\'a pas été personnalisé : %s. Voulez-vous continuer avec cette valeur par défaut ou modifier votre personnalisation ?';
				const multiTpl = i18n.warningMulti || 'Les champs suivants n\'ont pas été personnalisés : %s. Voulez-vous continuer avec les valeurs par défaut ou modifier votre personnalisation ?';
				textEl.textContent = (warnings.length === 1 ? singleTpl : multiTpl).replace('%s', summary);
			} else {
				textEl.textContent = i18n.warningMessage || 'Un ou plusieurs champs n\'ont pas été personnalisés. Souhaitez-vous continuer avec les valeurs par défaut ?';
			}
		}
		this.warningModal.style.display = 'flex';
	}
}
