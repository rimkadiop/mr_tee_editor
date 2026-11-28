/**
 * Mr. Tee Editor - Dedicated Storefront Gallery Controller (v2.5.0)
 *
 * Zero-dependency, GPU-accelerated multi-view swipe gallery with:
 * - Page-builder client-side fallback mounting from window.mrTeeProductData.gallerySlides
 * - Native-scroll-safe touch/pointer horizontal swipe (>36px threshold)
 * - View switcher pills, thumbnail strip, prev/next arrows, keyboard navigation
 * - Smart side auto-focus preferring closeup views without redundant jumps
 * - Built-in lightweight fullscreen lightbox with 175% vector-crisp zoom & scoped SVG
 * - WooCommerce variation swatch image synchronization
 *
 * @package MrTeeEditor\Assets\Modules
 */

const SVG_ICON_SHIRT = '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M20.38 3.46L16 2a4 4 0 0 1-8 0L3.62 3.46a2 2 0 0 0-1.34 2.23l.58 3.47a1 1 0 0 0 .99.84H6v10c0 1.1.9 2 2 2h8a2 2 0 0 0 2-2V10h2.15a1 1 0 0 0 .99-.84l.58-3.47a2 2 0 0 0-1.34-2.23z"/></svg>';
const SVG_ICON_ZOOM = '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="11" cy="11" r="7"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>';
const SVG_ICON_PHOTO = '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect x="3" y="3" width="18" height="18" rx="2" ry="2"/><circle cx="8.5" cy="8.5" r="1.5"/><polyline points="21 15 16 10 5 21"/></svg>';
const SVG_ICON_FLIP = '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><polyline points="17 1 21 5 17 9"/><path d="M3 11V9a4 4 0 0 1 4-4h14"/><polyline points="7 23 3 19 7 15"/><path d="M21 13v2a4 4 0 0 1-4 4H3"/></svg>';
const SVG_ICON_EYE = '<svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>';

export class DedicatedGallery {
	constructor(config = {}, options = {}) {
		this.config = config || {};
		this.options = options || {};
		this.onViewChange = typeof this.options.onViewChange === 'function' ? this.options.onViewChange : null;
		this.onSwipeStart = typeof this.options.onSwipeStart === 'function' ? this.options.onSwipeStart : null;
		this.onSlideImageChanged = typeof this.options.onSlideImageChanged === 'function' ? this.options.onSlideImageChanged : null;
		this.getFullscreenSvg = typeof this.options.getFullscreenSvg === 'function' ? this.options.getFullscreenSvg : null;
		this.patchSvgFn = typeof this.options.patchSvgFn === 'function' ? this.options.patchSvgFn : null;

		this.root = null;
		this.viewport = null;
		this.track = null;
		this.slides = [];
		this.slidesByViewId = new Map();
		this.pills = [];
		this.thumbs = [];
		this.activeIndex = 0;
		this.isMounted = false;

		this.lightboxEl = null;
		this.lbTitleEl = null;
		this.lbZoomBtn = null;
		this.lbStageEl = null;
		this.lbImgEl = null;
		this.lbOverlayEl = null;
		this.lbSvgHolder = null;
		this.lbPrevBtn = null;
		this.lbNextBtn = null;
		this.isLightboxOpen = false;
		this.isZoomed = false;
		this._suppressLightboxSvgSync = false;

		this.init();
	}

	/**
	 * Locate server-rendered #mr-tee-gallery-root or mount client-side fallback for page builders.
	 */
	init() {
		let root = document.getElementById('mr-tee-gallery-root');
		if (!root) {
			root = this.mountBuilderFallback();
		}
		if (!root) {
			this.isMounted = false;
			return;
		}

		this.root = root;
		this.viewport = root.querySelector('.mr-tee-gallery-viewport');
		this.track = root.querySelector('.mr-tee-gallery-track');
		if (!this.viewport || !this.track) {
			this.isMounted = false;
			return;
		}

		this.indexSlides();
		if (this.slides.length === 0) {
			this.isMounted = false;
			return;
		}

		this.isMounted = true;
		this.syncMobileBleed();
		if (typeof window !== 'undefined') {
			let bleedTimer = null;
			const onViewportResize = () => {
				clearTimeout(bleedTimer);
				bleedTimer = setTimeout(() => this.syncMobileBleed(), 80);
			};
			window.addEventListener('resize', onViewportResize, { passive: true });
			window.addEventListener('orientationchange', onViewportResize, { passive: true });
		}
		this.bindControls();
		this.bindSwipeGestures();
		this.bindImageLoadEvents();
		this.bindVariationSync();
		this.initMobilePreviewFab();
		this.goToIndex(0, { silent: true });
	}

	/**
	 * Synchronize exact scrollbar-free viewport width for edge-to-edge full-bleed mobile display
	 * (<= 767px), automatically opting out if the gallery is embedded inside a narrow sub-column.
	 */
	syncMobileBleed() {
		if (!this.root || typeof document === 'undefined') return;
		const docW = (document.documentElement && document.documentElement.clientWidth) || window.innerWidth || 0;
		const bodyW = (document.body && document.body.clientWidth) || docW;
		const cw = Math.min(docW || Infinity, bodyW || Infinity);
		if (!Number.isFinite(cw) || cw <= 0) return;
		if (window.innerWidth > 767) {
			this.root.removeAttribute('data-mobile-bleed');
			this.root.style.removeProperty('--mr-tee-mobile-vw');
			this.root.style.removeProperty('--mr-tee-mobile-ml');
			this.root.style.removeProperty('--mr-tee-mobile-mr');
			return;
		}

		// Walk up ancestors to respect any boxed overflow:hidden/clip container (e.g. Divi/Elementor boxed rows)
		let clipLeft = 0;
		let clipRight = cw;
		let anc = this.root.parentElement;
		while (anc && anc !== document.body && anc !== document.documentElement) {
			const cs = window.getComputedStyle(anc);
			if (cs && (cs.overflowX === 'hidden' || cs.overflowX === 'clip')) {
				const r = anc.getBoundingClientRect();
				if (r.width > 0) {
					clipLeft = Math.max(clipLeft, r.left);
					clipRight = Math.min(clipRight, r.right);
				}
			}
			anc = anc.parentElement;
		}

		const clipWidth = Math.max(0, clipRight - clipLeft);
		const parent = this.root.parentElement;
		const parentRect = parent && typeof parent.getBoundingClientRect === 'function' ? parent.getBoundingClientRect() : null;
		const parentCs = parent ? window.getComputedStyle(parent) : null;
		const padL = parentCs ? (parseFloat(parentCs.paddingLeft) || 0) : 0;
		const padR = parentCs ? (parseFloat(parentCs.paddingRight) || 0) : 0;
		const contentLeft = parentRect ? (parentRect.left + padL) : 0;
		const contentRight = parentRect ? (parentRect.right - padR) : cw;

		const canBleed = clipWidth >= cw * 0.80 && (!parentRect || (contentRight - contentLeft) >= cw * 0.70);
		this.root.setAttribute('data-mobile-bleed', canBleed ? '1' : '0');
		if (canBleed) {
			const ml = Math.round(clipLeft - contentLeft);
			const mr = Math.round(contentRight - clipRight);
			this.root.style.setProperty('--mr-tee-mobile-vw', `${Math.round(clipWidth)}px`);
			this.root.style.setProperty('--mr-tee-mobile-ml', `${ml}px`);
			this.root.style.setProperty('--mr-tee-mobile-mr', `${mr}px`);
		} else {
			this.root.style.removeProperty('--mr-tee-mobile-vw');
			this.root.style.removeProperty('--mr-tee-mobile-ml');
			this.root.style.removeProperty('--mr-tee-mobile-mr');
		}
	}

	/**
	 * Escape HTML special characters for safe client-side fallback rendering.
	 *
	 * @param {string} val Raw string.
	 * @return {string}
	 */
	static escHtml(val) {
		return String(val === undefined || val === null ? '' : val)
			.replace(/&/g, '&amp;')
			.replace(/</g, '&lt;')
			.replace(/>/g, '&gt;')
			.replace(/"/g, '&quot;')
			.replace(/'/g, '&#39;');
	}

	/**
	 * Normalize same-host URL protocol to match active window.location.protocol.
	 *
	 * @param {string} rawUrl Raw image URL.
	 * @return {string}
	 */
	static normalizeProtocol(rawUrl) {
		if (!rawUrl || typeof rawUrl !== 'string') return '';
		const trimmed = rawUrl.trim();
		if (!trimmed.startsWith('http://') && !trimmed.startsWith('https://')) {
			return trimmed;
		}
		try {
			const parsed = new URL(trimmed, window.location.origin);
			if (parsed.hostname === window.location.hostname) {
				parsed.protocol = window.location.protocol;
				return parsed.href;
			}
			if (window.location.protocol === 'https:' && parsed.protocol === 'http:') {
				parsed.protocol = 'https:';
				return parsed.href;
			}
		} catch (e) {}
		return trimmed;
	}

	/**
	 * Scroll an active pill or thumbnail horizontally inside its strip without ever triggering vertical page scroll.
	 *
	 * @param {HTMLElement} el Active child element inside a horizontal scroll strip.
	 */
	static scrollChildHorizontally(el) {
		if (!el || !el.parentElement) return;
		const container = el.parentElement;
		if (container.scrollWidth <= container.clientWidth + 1) return;
		const cRect = container.getBoundingClientRect();
		const eRect = el.getBoundingClientRect();
		if (eRect.left < cRect.left || eRect.right > cRect.right) {
			const delta = (eRect.left - cRect.left) - Math.max(8, (cRect.width - eRect.width) / 2);
			const nextLeft = container.scrollLeft + delta;
			if (typeof container.scrollTo === 'function') {
				container.scrollTo({ left: nextLeft, behavior: 'smooth' });
			} else {
				container.scrollLeft = nextLeft;
			}
		}
	}

	/**
	 * Render identical gallery markup into a detected page-builder gallery container
	 * when the active builder bypassed WooCommerce's single-product/product-image.php.
	 *
	 * @return {HTMLElement|null}
	 */
	mountBuilderFallback() {
		const data = (typeof window !== 'undefined' && window.mrTeeProductData) ? window.mrTeeProductData : {};
		const rawSlides = Array.isArray(data.gallerySlides) ? data.gallerySlides : [];
		if (rawSlides.length === 0) {
			return null;
		}

		const targetSelectors = [
			'.woocommerce-product-gallery',
			'.wp-block-woocommerce-product-image-gallery',
			'.wp-block-woocommerce-product-gallery',
			'.wc-block-product-gallery-large-image',
			'.elementor-widget-woocommerce-product-images .elementor-widget-container',
			'.et_pb_wc_images .et_pb_module_inner',
			'.et_pb_wc_images',
			'.brxe-product-gallery',
			'.bde-woo-product-images',
			'.breakdance-woocommerce-product-images',
			'.fusion-woo-product-images',
			'.ct-product-gallery-container',
			'.wd-product-gallery',
			'.product-gallery',
			'.product-images',
		];

		const isVisibleContainer = (el) => {
			if (!el || !el.isConnected) return false;
			const style = window.getComputedStyle(el);
			if (!style || style.display === 'none' || style.visibility === 'hidden') return false;
			if (el.offsetParent !== null || style.position === 'fixed') return true;
			const rect = typeof el.getBoundingClientRect === 'function' ? el.getBoundingClientRect() : null;
			return Boolean(rect && rect.width > 0);
		};

		let container = null;
		for (const sel of targetSelectors) {
			const matches = document.querySelectorAll(sel);
			for (const el of matches) {
				if (el && !el.closest('#mr-tee-editor-container') && isVisibleContainer(el)) {
					container = el;
					break;
				}
			}
			if (container) break;
		}
		if (!container) {
			return null;
		}

		const i18n = data.i18n || {};
		const isLocked = Boolean(this.config.lock_aspect_ratio) || this.config.product_type === 'flat_object';
		const lockRatio = parseFloat(this.config.print_aspect_ratio) || 1.0;
		const sidesById = new Map();
		if (Array.isArray(this.config.print_sides)) {
			for (const ps of this.config.print_sides) {
				if (ps && ps.id) sidesById.set(String(ps.id), ps);
			}
		}
		const count = rawSlides.length;

		let primaryVariationIdx = 0;
		for (let i = 0; i < rawSlides.length; i++) {
			const rs = rawSlides[i];
			if (rs && rs.is_customizable && (rs.side_id || 'front') === 'front' && (rs.view_role || 'full') === 'full') {
				primaryVariationIdx = i;
				break;
			}
		}

		const distinctSides = new Set();
		let hasStaticSlides = false;
		for (const rs of rawSlides) {
			if (!rs || !rs.is_customizable) {
				hasStaticSlides = true;
			} else if (rs.side_id) {
				distinctSides.add(String(rs.side_id));
			}
		}
		const hasMultiSide = distinctSides.size > 1;
		const showThumbStrip = count > 4 || (count > 1 && hasStaticSlides);

		const formatCompactLabel = (rawLabel, sideId = 'front', role = 'full', isCustom = true) => {
			const raw = String(rawLabel || '').trim();
			const lower = raw.toLowerCase();
			if (isCustom) {
				const isCloseup = role === 'closeup' || role === 'detail' || lower.includes('zoom') || lower.includes('gros plan');
				if (isCloseup) {
					if (lower.includes('cœur') || lower.includes('coeur') || lower.includes('poitrine')) return 'Zoom Cœur';
					if (sideId === 'back' || lower.includes('verso') || lower.includes('dos')) return 'Zoom Dos';
					return 'Zoom';
				}
				if (sideId === 'back') return 'Verso';
				if (sideId === 'front' && role === 'full') return hasMultiSide ? 'Recto' : 'Vue Complète';
			}
			let lbl = raw.replace(/^\s*#?\d+\s*[.\-):]\s*/, '');
			const stripped = lbl.replace(/\s*\([^)]*\)\s*/g, ' ').trim();
			if (stripped) lbl = stripped;
			lbl = lbl.replace(/\s{2,}/g, ' ').trim();
			return lbl || (!isCustom ? 'Photo' : 'Vue');
		};

		let pillsHtml = '';
		if (count > 1) {
			const pillItems = rawSlides.map((s, idx) => {
				let icon = SVG_ICON_SHIRT;
				if (!s.is_customizable) icon = SVG_ICON_PHOTO;
				else if (s.view_role === 'closeup' || s.view_role === 'detail') icon = SVG_ICON_ZOOM;
				else if (s.side_id === 'back') icon = SVG_ICON_FLIP;
				const shortLabel = formatCompactLabel(s.label, s.side_id || 'front', s.view_role || 'full', Boolean(s.is_customizable));
				return `<button type="button" role="tab" class="mr-tee-view-pill${idx === 0 ? ' is-active' : ''}" aria-selected="${idx === 0 ? 'true' : 'false'}" tabindex="${idx === 0 ? '0' : '-1'}" data-slide-index="${idx}" data-view-id="${DedicatedGallery.escHtml(s.view_id)}" data-side-id="${DedicatedGallery.escHtml(s.side_id || '')}" data-view-role="${DedicatedGallery.escHtml(s.view_role || 'full')}" title="${DedicatedGallery.escHtml(s.label || '')}"><span class="mr-tee-view-pill-icon" aria-hidden="true">${icon}</span><span class="mr-tee-view-pill-label">${DedicatedGallery.escHtml(shortLabel)}</span></button>`;
			}).join('');
			pillsHtml = `<div class="mr-tee-view-pills" role="tablist" aria-label="${DedicatedGallery.escHtml(i18n.viewsLabel || 'Vues du produit')}">${pillItems}</div>`;
		}

		let anySlideLocked = false;
		const slidesHtml = rawSlides.map((s, idx) => {
			const imgUrl = DedicatedGallery.normalizeProtocol(s.image_url || '');
			const srcsetAttr = s.srcset ? ` srcset="${DedicatedGallery.escHtml(s.srcset)}"` : '';
			const sizesAttr = s.sizes ? ` sizes="${DedicatedGallery.escHtml(s.sizes)}"` : '';
			const dimAttrs = (s.width && s.height) ? ` width="${parseInt(s.width, 10)}" height="${parseInt(s.height, 10)}"` : '';
			const loadAttrs = idx === 0
				? ' loading="eager" fetchpriority="high" decoding="sync"'
				: ' loading="lazy" decoding="async"';
			const sideDef = (s.side_id && sidesById.has(String(s.side_id))) ? sidesById.get(String(s.side_id)) : null;
			const slideLocked = Boolean(s.is_customizable) && (
				(sideDef && sideDef.lock_aspect_ratio !== undefined)
					? (Boolean(sideDef.lock_aspect_ratio) || sideDef.product_type === 'flat_object')
					: isLocked
			);
			const sideW = sideDef ? parseFloat(sideDef.print_real_width) : 0;
			const sideH = sideDef ? parseFloat(sideDef.print_real_height) : 0;
			const slideRatio = (sideW > 0 && sideH > 0) ? Number((sideW / sideH).toFixed(6)) : lockRatio;
			if (slideLocked) anySlideLocked = true;
			const stageClass = slideLocked ? 'mr-tee-slide-stage mr-tee-slide-stage--locked' : 'mr-tee-slide-stage';
			const stageStyle = slideLocked ? ` style="--mr-tee-slide-ratio:${slideRatio};aspect-ratio:${slideRatio};"` : '';
			const overlayHtml = s.is_customizable
				? `<div class="mr-tee-live-stage-overlay" data-view-id="${DedicatedGallery.escHtml(s.view_id)}" data-side-id="${DedicatedGallery.escHtml(s.side_id || 'front')}" data-blend="${DedicatedGallery.escHtml(s.blend_mode || 'normal')}"><div class="mr-tee-live-svg-container"></div></div>`
				: '';
			return `<div class="mr-tee-slide${idx === 0 ? ' is-active' : ''}" data-slide-index="${idx}" data-view-id="${DedicatedGallery.escHtml(s.view_id)}" data-side-id="${DedicatedGallery.escHtml(s.side_id || '')}" data-view-role="${DedicatedGallery.escHtml(s.view_role || 'full')}" data-customizable="${s.is_customizable ? '1' : '0'}"><div class="${stageClass}"${stageStyle}><img src="${DedicatedGallery.escHtml(imgUrl)}"${srcsetAttr}${sizesAttr} alt="${DedicatedGallery.escHtml(s.alt || s.label || '')}" class="mr-tee-slide-img${idx === primaryVariationIdx ? ' wp-post-image' : ''} skip-lazy no-lazyload" data-no-lazy="1" data-skip-lazy="1" data-large_image="${DedicatedGallery.escHtml(imgUrl)}"${dimAttrs}${loadAttrs} draggable="false" />${overlayHtml}</div></div>`;
		}).join('');

		const arrowsHtml = count > 1
			? `<button type="button" class="mr-tee-gallery-arrow mr-tee-gallery-arrow--prev" aria-label="${DedicatedGallery.escHtml(i18n.prevView || 'Vue précédente')}"><svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.3" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><polyline points="15 18 9 12 15 6"/></svg></button>` +
			  `<button type="button" class="mr-tee-gallery-arrow mr-tee-gallery-arrow--next" aria-label="${DedicatedGallery.escHtml(i18n.nextView || 'Vue suivante')}"><svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.3" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><polyline points="9 18 15 12 9 6"/></svg></button>`
			: '';

		const fullscreenHtml = `<button type="button" class="mr-tee-gallery-fullscreen-btn" aria-label="${DedicatedGallery.escHtml(i18n.fullscreen || 'Agrandir l\'aperçu')}" title="${DedicatedGallery.escHtml(i18n.fullscreen || 'Agrandir l\'aperçu')}"><svg width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.1" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><polyline points="15 3 21 3 21 9"/><polyline points="9 21 3 21 3 15"/><line x1="21" y1="3" x2="14" y2="10"/><line x1="3" y1="21" x2="10" y2="14"/></svg></button>`;

		let thumbsHtml = '';
		if (showThumbStrip) {
			const thumbItems = rawSlides.map((s, idx) => {
				const thumbUrl = DedicatedGallery.normalizeProtocol(s.thumb_url || s.image_url || '');
				return `<button type="button" class="mr-tee-gallery-thumb${idx === 0 ? ' is-active' : ''}"${idx === 0 ? ' aria-current="true"' : ''} data-slide-index="${idx}" data-view-id="${DedicatedGallery.escHtml(s.view_id)}" data-side-id="${DedicatedGallery.escHtml(s.side_id || '')}" aria-label="${DedicatedGallery.escHtml(s.label || '')}"><img src="${DedicatedGallery.escHtml(thumbUrl)}" alt="${DedicatedGallery.escHtml(s.label || '')}" class="skip-lazy no-lazyload" data-no-lazy="1" data-skip-lazy="1" loading="lazy" decoding="async" draggable="false" /><span class="mr-tee-gallery-thumb-label">${DedicatedGallery.escHtml(s.label || '')}</span></button>`;
			}).join('');
			thumbsHtml = `<div class="mr-tee-gallery-thumbs" role="group" aria-label="${DedicatedGallery.escHtml(i18n.thumbsLabel || 'Miniatures des vues')}">${thumbItems}</div>`;
		}

		const wrapper = document.createElement('div');
		wrapper.id = 'mr-tee-gallery-root';
		wrapper.className = `woocommerce-product-gallery images wp-block-woocommerce-product-image-gallery mr-tee-dedicated-gallery${anySlideLocked ? ' mr-tee-gallery--aspect-locked' : ''}`;
		wrapper.setAttribute('data-product-id', String(data.productId || 0));
		wrapper.setAttribute('data-columns', '4');
		wrapper.setAttribute('data-slide-count', String(count));
		wrapper.innerHTML = `<div class="mr-tee-gallery-viewport"><div class="mr-tee-gallery-track">${slidesHtml}</div>${arrowsHtml}${fullscreenHtml}</div>${pillsHtml}${thumbsHtml}`;

		// Preserve outer theme layout classes if replacing a .woocommerce-product-gallery node directly
		if (container.classList.contains('woocommerce-product-gallery')) {
			const skipClasses = new Set([
				'woocommerce-product-gallery--with-images',
				'woocommerce-product-gallery--without-images',
			]);
			container.classList.forEach((cls) => {
				if (cls && !skipClasses.has(cls) && !wrapper.classList.contains(cls)) {
					wrapper.classList.add(cls);
				}
			});
			container.replaceWith(wrapper);
		} else {
			container.innerHTML = '';
			container.appendChild(wrapper);
		}
		return wrapper;
	}

	/**
	 * Build internal index of slide DOM elements, metadata, pills, and thumbnails.
	 */
	indexSlides() {
		const dataSlides = (typeof window !== 'undefined' && window.mrTeeProductData && Array.isArray(window.mrTeeProductData.gallerySlides))
			? window.mrTeeProductData.gallerySlides
			: [];
		const metaByView = new Map();
		for (const item of dataSlides) {
			if (item && item.view_id) {
				metaByView.set(String(item.view_id), item);
			}
		}

		this.slides = [];
		this.slidesByViewId.clear();

		const slideEls = Array.from(this.root.querySelectorAll('.mr-tee-slide'));
		slideEls.forEach((el, idx) => {
			const viewId = el.getAttribute('data-view-id') || `view_${idx}`;
			const sideId = el.getAttribute('data-side-id') || '';
			const viewRole = el.getAttribute('data-view-role') || 'full';
			const customizable = el.getAttribute('data-customizable') === '1';
			const stageEl = el.querySelector('.mr-tee-slide-stage');
			const imgEl = el.querySelector('.mr-tee-slide-img');
			const overlayEl = el.querySelector('.mr-tee-live-stage-overlay');
			const svgContainerEl = overlayEl ? overlayEl.querySelector('.mr-tee-live-svg-container') : null;
			const blendMode = overlayEl ? (overlayEl.getAttribute('data-blend') || 'normal') : 'normal';
			const meta = metaByView.get(viewId) || dataSlides[idx] || {};
			const label = meta.label || (imgEl && imgEl.getAttribute('alt')) || viewId;
			const isLocked = Boolean(stageEl && stageEl.classList.contains('mr-tee-slide-stage--locked'));
			const lockRatio = stageEl ? (stageEl.style.getPropertyValue('--mr-tee-slide-ratio') || stageEl.style.aspectRatio || '').trim() : '';

			const slideObj = {
				index: idx,
				el,
				stageEl,
				imgEl,
				overlayEl,
				svgContainerEl,
				viewId,
				sideId,
				viewRole,
				blendMode,
				customizable,
				label,
				isLocked,
				lockRatio,
				aspectRatio: parseFloat(meta.aspect_ratio) || 0,
			};

			this.slides.push(slideObj);
			this.slidesByViewId.set(viewId, slideObj);
		});

		this.pills = Array.from(this.root.querySelectorAll('.mr-tee-view-pill'));
		this.thumbs = Array.from(this.root.querySelectorAll('.mr-tee-gallery-thumb'));
		this.slides.forEach((slide, idx) => {
			const pillEl = this.pills[idx];
			const pillLabelEl = pillEl ? pillEl.querySelector('.mr-tee-view-pill-label') : null;
			const pillText = pillLabelEl ? pillLabelEl.textContent.trim() : '';
			slide.shortLabel = pillText || slide.label;
		});
	}

	/**
	 * Bind clicks on view pills, thumbnails, prev/next arrows, fullscreen trigger, and keyboard arrows.
	 */
	bindControls() {
		this.pills.forEach((pill) => {
			pill.addEventListener('click', () => {
				const idx = parseInt(pill.getAttribute('data-slide-index'), 10);
				if (!isNaN(idx)) this.goToIndex(idx);
			});
		});

		this.thumbs.forEach((thumb) => {
			thumb.addEventListener('click', () => {
				const idx = parseInt(thumb.getAttribute('data-slide-index'), 10);
				if (!isNaN(idx)) this.goToIndex(idx);
			});
		});

		const prevBtn = this.root.querySelector('.mr-tee-gallery-arrow--prev');
		const nextBtn = this.root.querySelector('.mr-tee-gallery-arrow--next');
		if (prevBtn) {
			prevBtn.addEventListener('click', (e) => {
				e.stopPropagation();
				this.goToIndex(this.activeIndex - 1);
			});
		}
		if (nextBtn) {
			nextBtn.addEventListener('click', (e) => {
				e.stopPropagation();
				this.goToIndex(this.activeIndex + 1);
			});
		}

		const fsBtn = this.root.querySelector('.mr-tee-gallery-fullscreen-btn');
		if (fsBtn) {
			fsBtn.addEventListener('click', (e) => {
				e.stopPropagation();
				this.openFullscreen();
			});
		}

		// Keyboard navigation (Left/Right arrows inside gallery or lightbox, Escape closes lightbox)
		document.addEventListener('keydown', (e) => {
			if (this.isLightboxOpen) {
				if (e.key === 'Escape') {
					this.closeFullscreen();
				} else if (e.key === 'ArrowLeft' && this.slides.length > 1) {
					e.preventDefault();
					this.goToIndex(this.activeIndex - 1);
				} else if (e.key === 'ArrowRight' && this.slides.length > 1) {
					e.preventDefault();
					this.goToIndex(this.activeIndex + 1);
				}
				return;
			}

			if (this.slides.length <= 1) return;
			const activeEl = document.activeElement;
			if (!activeEl || !this.root.contains(activeEl)) return;
			const tag = activeEl.tagName;
			if (tag === 'INPUT' || tag === 'TEXTAREA' || tag === 'SELECT') return;

			if (e.key === 'ArrowLeft') {
				e.preventDefault();
				this.goToIndex(this.activeIndex - 1);
			} else if (e.key === 'ArrowRight') {
				e.preventDefault();
				this.goToIndex(this.activeIndex + 1);
			}
		});
	}

	/**
	 * Pure vanilla touch & mouse horizontal swipe on the viewport.
	 * Uses a >36px horizontal threshold and locks axis so vertical scrolling remains 100% native.
	 */
	bindSwipeGestures() {
		if (!this.viewport || !this.track || this.slides.length <= 1) return;
		this.viewport.classList.add('is-draggable');

		let dragging = false;
		let axis = null; // 'x' | 'y' | null
		let startX = 0;
		let startY = 0;
		let deltaX = 0;
		let viewportWidth = 1;
		let activePointerId = null;

		const isControlTarget = (target) => Boolean(
			target && target.closest && target.closest('.mr-tee-gallery-arrow, .mr-tee-gallery-fullscreen-btn, button, a')
		);

		const startDrag = (clientX, clientY) => {
			dragging = true;
			axis = null;
			startX = clientX;
			startY = clientY;
			deltaX = 0;
			viewportWidth = Math.max(1, this.viewport.clientWidth || 320);
		};

		const cancelDrag = () => {
			if (!dragging) return;
			dragging = false;
			axis = null;
			deltaX = 0;
			this.track.classList.remove('is-dragging');
			this.viewport.classList.remove('is-grabbing');
			this.track.style.transform = `translate3d(-${this.activeIndex * 100}%, 0, 0)`;
		};

		const moveDrag = (clientX, clientY) => {
			if (!dragging) return;
			const dx = clientX - startX;
			const dy = clientY - startY;

			if (!axis) {
				if (Math.abs(dx) < 8 && Math.abs(dy) < 8) return;
				axis = Math.abs(dx) > Math.abs(dy) ? 'x' : 'y';
				if (axis === 'y') {
					dragging = false;
					this.viewport.classList.remove('is-grabbing');
					return;
				}
				this.track.classList.add('is-dragging');
				const neighborIndices = [];
				if (this.activeIndex > 0) neighborIndices.push(this.activeIndex - 1);
				if (this.activeIndex < this.slides.length - 1) neighborIndices.push(this.activeIndex + 1);
				const neighborViewIds = [];
				for (const nIdx of neighborIndices) {
					this.preloadSlideImage(nIdx);
					const ns = this.slides[nIdx];
					if (ns && ns.customizable && ns.viewId) {
						neighborViewIds.push(ns.viewId);
					}
				}
				if (this.onSwipeStart && neighborViewIds.length > 0) {
					this.onSwipeStart(neighborViewIds);
				}
			}

			if (axis !== 'x') return;
			deltaX = dx;

			// Gentle rubber-band resistance past first/last slide
			let visualDx = dx;
			if ((this.activeIndex === 0 && dx > 0) || (this.activeIndex === this.slides.length - 1 && dx < 0)) {
				visualDx = dx * 0.3;
			}
			const basePct = -(this.activeIndex * 100);
			const offsetPct = (visualDx / viewportWidth) * 100;
			this.track.style.transform = `translate3d(${(basePct + offsetPct).toFixed(3)}%, 0, 0)`;
		};

		const endDrag = () => {
			if (!dragging) return;
			dragging = false;
			this.track.classList.remove('is-dragging');
			this.viewport.classList.remove('is-grabbing');

			if (axis === 'x' && Math.abs(deltaX) > 36) {
				if (deltaX < -36 && this.activeIndex < this.slides.length - 1) {
					this.goToIndex(this.activeIndex + 1);
					return;
				}
				if (deltaX > 36 && this.activeIndex > 0) {
					this.goToIndex(this.activeIndex - 1);
					return;
				}
			}

			// Snap cleanly back to current slide
			this.track.style.transform = `translate3d(-${this.activeIndex * 100}%, 0, 0)`;
		};

		// Touch events (passive so vertical scroll is never blocked!)
		this.viewport.addEventListener('touchstart', (e) => {
			if (!e.touches || e.touches.length !== 1 || isControlTarget(e.target)) {
				if (e.touches && e.touches.length > 1) cancelDrag();
				return;
			}
			startDrag(e.touches[0].clientX, e.touches[0].clientY);
		}, { passive: true });

		this.viewport.addEventListener('touchmove', (e) => {
			if (!dragging) return;
			if (!e.touches || e.touches.length !== 1) {
				cancelDrag();
				return;
			}
			moveDrag(e.touches[0].clientX, e.touches[0].clientY);
		}, { passive: true });

		this.viewport.addEventListener('touchend', endDrag, { passive: true });
		this.viewport.addEventListener('touchcancel', cancelDrag, { passive: true });

		// Desktop mouse drag via Pointer Events (ignores touch pointers already handled above)
		this.viewport.addEventListener('pointerdown', (e) => {
			if (e.pointerType !== 'mouse' || e.button !== 0 || isControlTarget(e.target)) return;
			activePointerId = e.pointerId;
			this.viewport.classList.add('is-grabbing');
			startDrag(e.clientX, e.clientY);
			try {
				this.viewport.setPointerCapture(e.pointerId);
			} catch (err) {}
		});

		this.viewport.addEventListener('pointermove', (e) => {
			if (e.pointerType !== 'mouse' || !dragging || e.pointerId !== activePointerId) return;
			moveDrag(e.clientX, e.clientY);
		});

		const endMousePointer = (e) => {
			if (e.pointerType !== 'mouse' || e.pointerId !== activePointerId) return;
			activePointerId = null;
			endDrag();
		};
		this.viewport.addEventListener('pointerup', endMousePointer);
		this.viewport.addEventListener('pointercancel', endMousePointer);
	}

	/**
	 * Listen for image load completion on customizable slides (especially lazy slides 1..N)
	 * so SVG viewBox aspect ratio updates cleanly as soon as real dimensions arrive.
	 */
	bindImageLoadEvents() {
		this.slides.forEach((slide) => {
			if (!slide.customizable || !slide.imgEl) return;
			const img = slide.imgEl;
			if (img.complete && img.naturalWidth > 2 && img.naturalHeight > 2 && !slide.isLocked) {
				slide.aspectRatio = img.naturalWidth / img.naturalHeight;
			}
			img.addEventListener('load', () => {
				if (img.naturalWidth > 2 && img.naturalHeight > 2) {
					if (!slide.isLocked) {
						slide.aspectRatio = img.naturalWidth / img.naturalHeight;
					}
					if (this.onSlideImageChanged) {
						this.onSlideImageChanged(slide.viewId, img);
					}
				}
			});
		});
	}

	/**
	 * Sync WooCommerce variation image switches onto the primary front full slide.
	 */
	bindVariationSync() {
		if (typeof window === 'undefined' || !window.jQuery) return;
		const $ = window.jQuery;

		const targetSlide = this.slides.find(s => s.customizable && s.sideId === 'front' && s.viewRole === 'full')
			|| this.slides[0];
		if (!targetSlide || !targetSlide.imgEl) return;

		const imgEl = targetSlide.imgEl;
		const thumbEl = this.thumbs[targetSlide.index] ? this.thumbs[targetSlide.index].querySelector('img') : null;

		if (!imgEl.dataset.mrTeeOrigSrc) {
			imgEl.dataset.mrTeeOrigSrc = imgEl.getAttribute('src') || '';
		}
		if (!imgEl.dataset.mrTeeOrigLarge) {
			imgEl.dataset.mrTeeOrigLarge = imgEl.getAttribute('data-large_image') || imgEl.dataset.mrTeeOrigSrc || '';
		}
		if (thumbEl && !thumbEl.dataset.mrTeeOrigSrc) {
			thumbEl.dataset.mrTeeOrigSrc = thumbEl.getAttribute('src') || '';
		}

		let syncToken = 0;

		const applySlideImage = (nextUrl, thumbUrl = '', largeUrl = '') => {
			const normalized = DedicatedGallery.normalizeProtocol(nextUrl);
			if (!normalized) return;
			const normalizedLarge = DedicatedGallery.normalizeProtocol(largeUrl || nextUrl) || normalized;
			const token = ++syncToken;

			imgEl.removeAttribute('srcset');
			imgEl.removeAttribute('sizes');
			imgEl.removeAttribute('width');
			imgEl.removeAttribute('height');
			imgEl.setAttribute('data-large_image', normalizedLarge);

			const notify = () => {
				if (token !== syncToken) return;
				if (imgEl.naturalWidth > 2 && imgEl.naturalHeight > 2) {
					targetSlide.aspectRatio = imgEl.naturalWidth / imgEl.naturalHeight;
				}
				if (this.onSlideImageChanged) {
					this.onSlideImageChanged(targetSlide.viewId, imgEl);
				}
				if (this.isLightboxOpen && this.activeIndex === targetSlide.index && this.lbImgEl) {
					this._lbTargetViewId = targetSlide.viewId;
					this.lbImgEl.src = normalizedLarge;
				}
			};

			const currentSrc = DedicatedGallery.normalizeProtocol(imgEl.getAttribute('src') || '');
			if (currentSrc !== normalized) {
				imgEl.addEventListener('load', notify, { once: true });
				imgEl.src = normalized;
			} else if (!imgEl.complete || imgEl.naturalWidth <= 2) {
				imgEl.addEventListener('load', notify, { once: true });
			} else {
				notify();
			}

			if (thumbEl) {
				const nextThumb = DedicatedGallery.normalizeProtocol(thumbUrl || normalized);
				if (nextThumb) {
					thumbEl.removeAttribute('srcset');
					thumbEl.src = nextThumb;
				}
			}
		};

		const $forms = $('form.variations_form');
		const $bindTarget = $forms.length ? $forms : $(document);

		$bindTarget.on('found_variation.mrTeeGallery', (event, variation) => {
			if (variation && variation.image && (variation.image.src || variation.image.full_src)) {
				const src = variation.image.src || variation.image.full_src;
				const large = variation.image.full_src || variation.image.src;
				const thumb = variation.image.gallery_thumbnail_src || variation.image.thumb_src || src;
				applySlideImage(src, thumb, large);
			}
		});

		$bindTarget.on('reset_image.mrTeeGallery', () => {
			if (imgEl.dataset.mrTeeOrigSrc) {
				applySlideImage(
					imgEl.dataset.mrTeeOrigSrc,
					thumbEl ? thumbEl.dataset.mrTeeOrigSrc : '',
					imgEl.dataset.mrTeeOrigLarge || imgEl.dataset.mrTeeOrigSrc
				);
			}
		});
	}

	/**
	 * Lightweight mobile floating "Aperçu" quick-scroll pill using IntersectionObserver.
	 */
	initMobilePreviewFab() {
		if (typeof window === 'undefined' || !('IntersectionObserver' in window) || !document.body) return;
		const editorEl = document.getElementById('mr-tee-editor-container');
		if (!editorEl) return;

		const i18n = (window.mrTeeProductData && window.mrTeeProductData.i18n) || {};
		const fab = document.createElement('button');
		fab.type = 'button';
		fab.className = 'mr-tee-mobile-preview-fab';
		fab.innerHTML = `${SVG_ICON_EYE}<span>${DedicatedGallery.escHtml(i18n.previewBtn || 'Aperçu')}</span>`;
		fab.addEventListener('click', () => {
			this.root.scrollIntoView({ behavior: 'smooth', block: 'start' });
		});
		document.body.appendChild(fab);

		let galleryInView = true;
		let editorInView = false;
		const syncFab = () => {
			const show = !galleryInView && editorInView;
			fab.classList.toggle('is-visible', show);
		};

		const observer = new window.IntersectionObserver((entries) => {
			for (const entry of entries) {
				if (entry.target === this.root) {
					galleryInView = entry.isIntersecting;
				} else if (entry.target === editorEl) {
					editorInView = entry.isIntersecting;
				}
			}
			syncFab();
		}, { threshold: 0.08 });

		observer.observe(this.root);
		observer.observe(editorEl);
	}

	/**
	 * Promote a lazy slide image to eager loading just before/during swipe or tab switch
	 * so adjacent slides decode immediately without hurting initial LCP bandwidth.
	 *
	 * @param {number} idx Slide index.
	 */
	preloadSlideImage(idx) {
		const slide = this.slides[idx];
		if (!slide || !slide.imgEl) return;
		if (slide.imgEl.getAttribute('loading') === 'lazy') {
			slide.imgEl.setAttribute('loading', 'eager');
		}
	}

	/**
	 * Navigate smoothly to slide by index.
	 *
	 * @param {number} idx Slide index (wraps safely).
	 * @param {Object} opts Optional flags ({ silent: false }).
	 */
	goToIndex(idx, { silent = false } = {}) {
		const count = this.slides.length;
		if (count === 0) return;

		const normalized = ((idx % count) + count) % count;
		const changed = normalized !== this.activeIndex;
		this.activeIndex = normalized;
		this.preloadSlideImage(normalized);

		if (this.track) {
			this.track.classList.remove('is-dragging');
			this.track.style.transform = `translate3d(-${normalized * 100}%, 0, 0)`;
		}

		this.slides.forEach((s, i) => {
			s.el.classList.toggle('is-active', i === normalized);
		});

		this.pills.forEach((p) => {
			const pIdx = parseInt(p.getAttribute('data-slide-index'), 10);
			const active = pIdx === normalized;
			p.classList.toggle('is-active', active);
			p.setAttribute('aria-selected', active ? 'true' : 'false');
			p.setAttribute('tabindex', active ? '0' : '-1');
			if (active && changed) {
				DedicatedGallery.scrollChildHorizontally(p);
			}
		});

		this.thumbs.forEach((t) => {
			const tIdx = parseInt(t.getAttribute('data-slide-index'), 10);
			const active = tIdx === normalized;
			t.classList.toggle('is-active', active);
			if (active) {
				t.setAttribute('aria-current', 'true');
			} else {
				t.removeAttribute('aria-current');
			}
			if (active && changed) {
				DedicatedGallery.scrollChildHorizontally(t);
			}
		});

		const activeSlide = this.slides[normalized];
		if (!silent && this.onViewChange && activeSlide) {
			this._suppressLightboxSvgSync = this.isLightboxOpen;
			try {
				this.onViewChange(activeSlide.viewId, normalized, activeSlide);
			} finally {
				this._suppressLightboxSvgSync = false;
			}
		}

		if (this.isLightboxOpen) {
			this.refreshLightboxContent();
		}
	}

	/**
	 * Focus a garment side (e.g. 'front' or 'back') when the user interacts with a field on that side.
	 * Does nothing if the currently active slide already shows `sideId`; otherwise prefers `closeup`
	 * for that side before falling back to the first slide for `sideId`.
	 *
	 * @param {string} sideId Target side ID.
	 */
	focusSide(sideId) {
		if (!sideId || this.slides.length <= 1) return;
		const targetSide = String(sideId);
		const current = this.slides[this.activeIndex];
		if (current && current.customizable && current.sideId === targetSide) {
			return;
		}

		const matching = this.slides.filter(s => s.customizable && s.sideId === targetSide);
		if (matching.length === 0) return;

		const preferred = matching.find(s => s.viewRole === 'closeup')
			|| matching.find(s => s.viewRole === 'detail')
			|| matching[0];
		this.goToIndex(preferred.index);
	}

	/**
	 * Return currently active view ID.
	 *
	 * @return {string}
	 */
	getActiveViewId() {
		const s = this.slides[this.activeIndex];
		return s ? s.viewId : '';
	}

	/**
	 * Return active slide metadata object.
	 *
	 * @return {Object|null}
	 */
	getActiveSlide() {
		return this.slides[this.activeIndex] || null;
	}

	/**
	 * Return all customizable slide metadata objects.
	 *
	 * @return {Array<Object>}
	 */
	getCustomizableViews() {
		return this.slides.filter(s => s.customizable);
	}

	/**
	 * Return the `<img>` element for a specific view ID (or active slide if omitted).
	 *
	 * @param {string} viewId Target view ID.
	 * @return {HTMLImageElement|null}
	 */
	getViewImageElement(viewId = '') {
		const slide = viewId ? this.slidesByViewId.get(String(viewId)) : this.slides[this.activeIndex];
		return (slide && slide.imgEl) ? slide.imgEl : null;
	}

	/**
	 * Return the `.mr-tee-live-svg-container` element for a specific view ID (or active slide if omitted).
	 *
	 * @param {string} viewId Target view ID.
	 * @return {HTMLElement|null}
	 */
	getViewOverlayContainer(viewId = '') {
		const slide = viewId ? this.slidesByViewId.get(String(viewId)) : this.slides[this.activeIndex];
		return (slide && slide.svgContainerEl) ? slide.svgContainerEl : null;
	}

	/**
	 * Lazily create and portal `#mr-tee-gallery-lightbox` directly to `document.body`.
	 */
	ensureLightboxDom() {
		if (this.lightboxEl && this.lightboxEl.isConnected) return;

		const i18n = (window.mrTeeProductData && window.mrTeeProductData.i18n) || {};
		const lb = document.createElement('div');
		lb.id = 'mr-tee-gallery-lightbox';
		lb.setAttribute('role', 'dialog');
		lb.setAttribute('aria-modal', 'true');

		const arrowsHtml = this.slides.length > 1
			? `<button type="button" class="mr-tee-lb-arrow mr-tee-lb-arrow--prev" aria-label="${DedicatedGallery.escHtml(i18n.prevView || 'Vue précédente')}"><svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round"><polyline points="15 18 9 12 15 6"/></svg></button>` +
			  `<button type="button" class="mr-tee-lb-arrow mr-tee-lb-arrow--next" aria-label="${DedicatedGallery.escHtml(i18n.nextView || 'Vue suivante')}"><svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round"><polyline points="9 18 15 12 9 6"/></svg></button>`
			: '';

		const lbPillsHtml = this.slides.length > 1
			? `<div class="mr-tee-lb-pills" role="tablist">${this.slides.map((s, idx) => `<button type="button" role="tab" class="mr-tee-lb-pill${idx === this.activeIndex ? ' is-active' : ''}" data-slide-index="${idx}">${DedicatedGallery.escHtml(s.shortLabel || s.label)}</button>`).join('')}</div>`
			: '';

		lb.innerHTML = `
			<div class="mr-tee-lb-toolbar">
				<div class="mr-tee-lb-title"></div>
				<div class="mr-tee-lb-actions">
					<button type="button" class="mr-tee-lb-btn mr-tee-lb-zoom-btn">${DedicatedGallery.escHtml(i18n.zoomIn || 'Zoom +')}</button>
					<button type="button" class="mr-tee-lb-btn mr-tee-lb-close" aria-label="${DedicatedGallery.escHtml(i18n.close || 'Fermer')}">&times;</button>
				</div>
			</div>
			<div class="mr-tee-lb-body">
				${arrowsHtml}
				<div class="mr-tee-lb-stage">
					<img class="mr-tee-lb-img" alt="" draggable="false" />
					<div class="mr-tee-live-stage-overlay mr-tee-lb-overlay">
						<div class="mr-tee-live-svg-container"></div>
					</div>
				</div>
			</div>
			${lbPillsHtml}
		`;

		document.body.appendChild(lb);

		this.lightboxEl = lb;
		this.lbTitleEl = lb.querySelector('.mr-tee-lb-title');
		this.lbZoomBtn = lb.querySelector('.mr-tee-lb-zoom-btn');
		this.lbStageEl = lb.querySelector('.mr-tee-lb-stage');
		this.lbImgEl = lb.querySelector('.mr-tee-lb-img');
		this.lbOverlayEl = lb.querySelector('.mr-tee-lb-overlay');
		this.lbSvgHolder = lb.querySelector('.mr-tee-live-svg-container');
		this.lbPrevBtn = lb.querySelector('.mr-tee-lb-arrow--prev');
		this.lbNextBtn = lb.querySelector('.mr-tee-lb-arrow--next');
		this.lbPills = Array.from(lb.querySelectorAll('.mr-tee-lb-pill'));

		if (this.lbImgEl) {
			this.lbImgEl.addEventListener('load', () => {
				if (!this.isLightboxOpen || !this.lightboxEl || !this.lbImgEl) return;
				const activeSlide = this.slides[this.activeIndex];
				if (!activeSlide || (this._lbTargetViewId && activeSlide.viewId !== this._lbTargetViewId)) return;
				if (this.lbImgEl.naturalWidth > 2 && this.lbImgEl.naturalHeight > 2) {
					const loadedRatio = this.lbImgEl.naturalWidth / this.lbImgEl.naturalHeight;
					this.lightboxEl.style.setProperty('--mr-tee-lb-ratio', String(Number(loadedRatio.toFixed(5))));
					const prevRatio = activeSlide.aspectRatio || 0;
					if (!activeSlide.isLocked) {
						activeSlide.aspectRatio = loadedRatio;
					}
					if (activeSlide.customizable) {
						if (this.onSlideImageChanged && Math.abs(loadedRatio - prevRatio) > 0.0005) {
							this.onSlideImageChanged(activeSlide.viewId, this.lbImgEl);
						}
						if (this.getFullscreenSvg) {
							this.syncFullscreenSvg(this.getFullscreenSvg(activeSlide.viewId));
						}
					}
				}
			});
		}

		this.lbPills.forEach((pill) => {
			pill.addEventListener('click', (e) => {
				e.stopPropagation();
				const idx = parseInt(pill.getAttribute('data-slide-index'), 10);
				if (!isNaN(idx)) this.goToIndex(idx);
			});
		});

		const closeBtn = lb.querySelector('.mr-tee-lb-close');
		const lbBody = lb.querySelector('.mr-tee-lb-body');
		let suppressNextStageClick = false;

		if (closeBtn) closeBtn.addEventListener('click', () => this.closeFullscreen());
		if (this.lbZoomBtn) this.lbZoomBtn.addEventListener('click', () => this.toggleFullscreenZoom());
		if (this.lbStageEl) {
			this.lbStageEl.addEventListener('click', () => {
				if (suppressNextStageClick) {
					suppressNextStageClick = false;
					return;
				}
				this.toggleFullscreenZoom();
			});
		}
		if (lbBody) {
			lbBody.addEventListener('click', (e) => {
				if (e.target === lbBody) this.closeFullscreen();
			});

			// Horizontal touch swipe inside lightbox when not zoomed
			if (this.slides.length > 1) {
				let lbDragging = false;
				let lbAxis = null;
				let lbStartX = 0;
				let lbStartY = 0;
				let lbDeltaX = 0;

				lbBody.addEventListener('touchstart', (e) => {
					if (this.isZoomed || !e.touches || e.touches.length !== 1) {
						lbDragging = false;
						return;
					}
					if (e.target && e.target.closest && e.target.closest('button')) return;
					lbDragging = true;
					lbAxis = null;
					lbStartX = e.touches[0].clientX;
					lbStartY = e.touches[0].clientY;
					lbDeltaX = 0;
				}, { passive: true });

				lbBody.addEventListener('touchmove', (e) => {
					if (!lbDragging) return;
					if (this.isZoomed || !e.touches || e.touches.length !== 1) {
						lbDragging = false;
						lbAxis = null;
						return;
					}
					const dx = e.touches[0].clientX - lbStartX;
					const dy = e.touches[0].clientY - lbStartY;
					if (!lbAxis) {
						if (Math.abs(dx) < 10 && Math.abs(dy) < 10) return;
						lbAxis = Math.abs(dx) > Math.abs(dy) ? 'x' : 'y';
						if (lbAxis === 'y') {
							lbDragging = false;
							return;
						}
					}
					if (lbAxis === 'x') {
						lbDeltaX = dx;
					}
				}, { passive: true });

				const finishLbSwipe = () => {
					if (!lbDragging) return;
					lbDragging = false;
					if (!this.isZoomed && lbAxis === 'x' && Math.abs(lbDeltaX) > 40) {
						suppressNextStageClick = true;
						setTimeout(() => { suppressNextStageClick = false; }, 320);
						this.goToIndex(this.activeIndex + (lbDeltaX < 0 ? 1 : -1));
					}
					lbAxis = null;
					lbDeltaX = 0;
				};

				lbBody.addEventListener('touchend', finishLbSwipe, { passive: true });
				lbBody.addEventListener('touchcancel', () => {
					lbDragging = false;
					lbAxis = null;
					lbDeltaX = 0;
				}, { passive: true });
			}
		}
		if (this.lbPrevBtn) {
			this.lbPrevBtn.addEventListener('click', (e) => {
				e.stopPropagation();
				this.goToIndex(this.activeIndex - 1);
			});
		}
		if (this.lbNextBtn) {
			this.lbNextBtn.addEventListener('click', (e) => {
				e.stopPropagation();
				this.goToIndex(this.activeIndex + 1);
			});
		}
	}

	/**
	 * Toggle 175% zoom inside fullscreen lightbox with exact proportional pixel sizing.
	 *
	 * @param {boolean|null} forceState Optional explicit zoom state.
	 */
	toggleFullscreenZoom(forceState = null) {
		if (!this.lightboxEl) return;
		const i18n = (window.mrTeeProductData && window.mrTeeProductData.i18n) || {};
		const nextZoom = forceState !== null ? Boolean(forceState) : !this.isZoomed;

		if (nextZoom && !this.isZoomed) {
			const measureEl = (this.lbStageEl && this.lbStageEl.classList.contains('mr-tee-lb-stage--locked'))
				? this.lbStageEl
				: this.lbImgEl;
			const rect = measureEl ? measureEl.getBoundingClientRect() : null;
			if (rect && rect.width > 40) {
				this.lightboxEl.style.setProperty('--mr-tee-lb-zoom-w', `${Math.round(rect.width * 1.75)}px`);
			}
		}

		this.isZoomed = nextZoom;
		this.lightboxEl.classList.toggle('is-zoomed', this.isZoomed);
		if (this.lbZoomBtn) {
			this.lbZoomBtn.textContent = this.isZoomed
				? (i18n.zoomOut || 'Zoom −')
				: (i18n.zoomIn || 'Zoom +');
		}
	}

	/**
	 * Refresh image, title, blend mode, and live SVG inside the open fullscreen lightbox.
	 */
	refreshLightboxContent() {
		if (!this.isLightboxOpen || !this.lightboxEl) return;
		const slide = this.slides[this.activeIndex];
		if (!slide) return;

		this.toggleFullscreenZoom(false);

		if (this.lbTitleEl) {
			const counter = this.slides.length > 1 ? ` (${this.activeIndex + 1}/${this.slides.length})` : '';
			this.lbTitleEl.textContent = `${slide.shortLabel || slide.label}${counter}`;
		}

		if (Array.isArray(this.lbPills)) {
			this.lbPills.forEach((p, idx) => {
				const isAct = idx === this.activeIndex;
				p.classList.toggle('is-active', isAct);
				p.setAttribute('aria-selected', isAct ? 'true' : 'false');
			});
		}

		this._lbTargetViewId = slide.viewId;
		if (this.lbImgEl && slide.imgEl) {
			const src = slide.imgEl.getAttribute('data-large_image') || slide.imgEl.currentSrc || slide.imgEl.src || '';
			this.lbImgEl.src = src;
			this.lbImgEl.alt = slide.imgEl.alt || slide.label || '';
		}

		let measuredRatio = 0;
		if (this.lbImgEl && this.lbImgEl.complete && this.lbImgEl.naturalWidth > 2 && this.lbImgEl.naturalHeight > 2) {
			measuredRatio = this.lbImgEl.naturalWidth / this.lbImgEl.naturalHeight;
		} else if (slide.imgEl && slide.imgEl.complete && slide.imgEl.naturalWidth > 2 && slide.imgEl.naturalHeight > 2) {
			measuredRatio = slide.imgEl.naturalWidth / slide.imgEl.naturalHeight;
		}

		const liveRatio = measuredRatio > 0
			? measuredRatio
			: (slide.aspectRatio > 0 ? slide.aspectRatio : 0.82);
		this.lightboxEl.style.setProperty('--mr-tee-lb-ratio', String(Number(liveRatio.toFixed(5))));
		if (!slide.isLocked && measuredRatio > 0) {
			slide.aspectRatio = measuredRatio;
		}

		if (this.lbStageEl) {
			if (slide.isLocked) {
				this.lbStageEl.classList.add('mr-tee-lb-stage--locked');
				if (slide.lockRatio) {
					this.lbStageEl.style.setProperty('--mr-tee-slide-ratio', slide.lockRatio);
					this.lbStageEl.style.aspectRatio = slide.lockRatio;
				}
			} else {
				this.lbStageEl.classList.remove('mr-tee-lb-stage--locked');
				this.lbStageEl.style.removeProperty('--mr-tee-slide-ratio');
				this.lbStageEl.style.aspectRatio = '';
			}
		}

		if (this.lbOverlayEl) {
			if (slide.customizable) {
				this.lbOverlayEl.style.display = '';
				this.lbOverlayEl.setAttribute('data-blend', slide.blendMode || 'normal');
				const svgMarkup = this.getFullscreenSvg ? this.getFullscreenSvg(slide.viewId) : '';
				this.syncFullscreenSvg(svgMarkup);
			} else {
				this.lbOverlayEl.style.display = 'none';
				if (this.lbSvgHolder) this.lbSvgHolder.innerHTML = '';
			}
		}
	}

	/**
	 * Open built-in fullscreen lightbox on the active slide.
	 */
	openFullscreen() {
		if (this.isLightboxOpen) return;
		this._lastFocusedEl = (typeof document !== 'undefined' && document.activeElement instanceof HTMLElement)
			? document.activeElement
			: null;
		this.ensureLightboxDom();
		this.isLightboxOpen = true;
		this.lightboxEl.classList.add('is-open');
		if (document.documentElement) {
			this._prevOverflow = document.documentElement.style.overflow;
			document.documentElement.style.overflow = 'hidden';
		}
		if (document.body) {
			this._prevBodyOverflow = document.body.style.overflow;
			document.body.style.overflow = 'hidden';
		}
		this.refreshLightboxContent();
		const closeBtn = this.lightboxEl.querySelector('.mr-tee-lb-close');
		if (closeBtn && typeof closeBtn.focus === 'function') {
			closeBtn.focus({ preventScroll: true });
		}
	}

	/**
	 * Close built-in fullscreen lightbox.
	 */
	closeFullscreen() {
		if (!this.lightboxEl) return;
		this.isLightboxOpen = false;
		this.toggleFullscreenZoom(false);
		this.lightboxEl.classList.remove('is-open');
		if (document.documentElement && this._prevOverflow !== undefined) {
			document.documentElement.style.overflow = this._prevOverflow;
		}
		if (document.body && this._prevBodyOverflow !== undefined) {
			document.body.style.overflow = this._prevBodyOverflow;
		}
		if (this._lastFocusedEl && this._lastFocusedEl.isConnected && typeof this._lastFocusedEl.focus === 'function') {
			this._lastFocusedEl.focus({ preventScroll: true });
		}
		this._lastFocusedEl = null;
	}

	/**
	 * Update live scoped SVG inside the open fullscreen lightbox (using keyed patch when available).
	 *
	 * @param {string} svgMarkup Scoped SVG markup (fs- prefix).
	 * @param {Function|null} patchFn Optional in-place patch callback.
	 */
	syncFullscreenSvg(svgMarkup, patchFn = null) {
		if (!this.isLightboxOpen || !this.lbSvgHolder) return;
		const markup = svgMarkup || '';
		const patch = patchFn || this.patchSvgFn;
		const existingSvg = this.lbSvgHolder.querySelector('svg.mr-tee-live-svg') || this.lbSvgHolder.querySelector('svg');

		if (!existingSvg || !markup || typeof patch !== 'function') {
			this.lbSvgHolder.innerHTML = markup;
			const mountedDefs = this.lbSvgHolder.querySelector('defs.mr-tee-svg-defs, defs');
			const mountedSvg = this.lbSvgHolder.querySelector('svg.mr-tee-live-svg, svg');
			if (mountedSvg && mountedDefs) {
				mountedSvg._lastDefsHtml = mountedDefs.innerHTML;
			}
		} else {
			patch(existingSvg, markup);
		}
	}
}
