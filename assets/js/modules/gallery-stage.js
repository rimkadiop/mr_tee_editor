/**
 * Mr. Tee Editor - Gallery Stage Overlay Module
 *
 * Mounts and synchronizes the live SVG scene-graph overlay directly on top
 * of WooCommerce product gallery images using exact Editor Area percentages
 * (`box_left`, `box_top`, `box_width`, `box_height`) and PersoPlacement transforms.
 *
 * Fully responsive and mobile-resilient:
 * - Preserves live SVG markup across mobile keyboard events and viewport resizes
 * - Synchronizes personalized SVG artwork with PhotoSwipe fullscreen zoom
 *
 * @package MrTeeEditor\Assets\Modules
 */

export class GalleryStage {
	constructor(config, options = {}) {
		this.config = config || {};
		this.options = options || {};
		this.renderSvgWithPrefix = typeof this.options.renderSvgWithPrefix === 'function'
			? this.options.renderSvgWithPrefix
			: null;
		this.overlayElem = null;
		this.svgHolder = null;
		this.attachedImage = null;
		this.currentSvgMarkup = '';
		this.lastWindowWidth = typeof window !== 'undefined' ? window.innerWidth : 0;

		this.init();
	}

	/**
	 * Safely scope DOM IDs and internal fragment references (`id="X"`, `url(#X)`, `href="#X"`, `xlink:href="#X"`)
	 * inside an SVG markup string without touching data URLs, external URLs, or `data-*-id` attributes.
	 *
	 * @param {string} svgMarkup Raw SVG markup string.
	 * @param {string} prefix Prefix to prepend to element IDs and local fragment references.
	 * @return {string}
	 */
	static scopeSvgMarkup(svgMarkup, prefix) {
		if (!svgMarkup || !prefix) return svgMarkup || '';
		const cleanPrefix = String(prefix).replace(/[^a-zA-Z0-9_-]/g, '');
		if (!cleanPrefix) return svgMarkup;

		const str = String(svgMarkup);
		const preservedDataUrls = [];
		const work = str.includes('data:')
			? str.replace(/((?:xlink:href|href|src)\s*=\s*(?:"data:[^"]*"|'data:[^']*')|url\(\s*(?:"data:[^"]*"|'data:[^']*'|data:[^)\s]*)\s*\))/gi, (m) => {
				const token = `___MRTEE_PRESERVED_DATA_${preservedDataUrls.length}___`;
				preservedDataUrls.push(m);
				return token;
			})
			: str;

		const prefixId = (id) => (id && !id.startsWith(cleanPrefix) ? `${cleanPrefix}${id}` : id);

		let scoped = work
			.replace(/(^|[\s"'<])id=(["'])([^"']+)\2/g, (m, lead, quote, id) => {
				return `${lead}id=${quote}${prefixId(id)}${quote}`;
			})
			.replace(/url\(\s*(['"]?)#([^)'"\s]+)\1\s*\)/g, (m, quote, id) => {
				return `url(${quote}#${prefixId(id)}${quote})`;
			})
			.replace(/(^|[\s"'<])(xlink:href|href)=(["'])#([^"']+)\3/g, (m, lead, attr, quote, id) => {
				return `${lead}${attr}=${quote}#${prefixId(id)}${quote}`;
			});

		if (preservedDataUrls.length > 0) {
			scoped = scoped.replace(/___MRTEE_PRESERVED_DATA_(\d+)___/g, (_, idx) => preservedDataUrls[Number(idx)]);
		}
		return scoped;
	}

	/**
	 * Build or scope lightbox SVG markup with a dedicated DOM ID prefix (`pswp-` or `mfp-`).
	 *
	 * @param {string} prefix Prefix to apply to lightbox SVG IDs.
	 * @return {string}
	 */
	getLightboxSvgMarkup(prefix) {
		if (this.renderSvgWithPrefix) {
			const rendered = this.renderSvgWithPrefix(prefix);
			if (typeof rendered === 'string') {
				return rendered;
			}
		}
		return GalleryStage.scopeSvgMarkup(this.currentSvgMarkup, prefix);
	}

	/**
	 * Mount overlay on the active WooCommerce gallery slide.
	 */
	init() {
		this.mountOverlay();
		this.bindGalleryEvents();
		this.suppressZoom();
		this.initPhotoSwipeSync();
	}

	/**
	 * Check whether a DOM element is currently visible (not hidden via CSS display/visibility or collapsed ancestor).
	 *
	 * @param {Element} el DOM element to test.
	 * @return {boolean}
	 */
	isVisibleElement(el) {
		if (!el || !el.isConnected) return false;
		const style = window.getComputedStyle(el);
		if (!style || style.display === 'none' || style.visibility === 'hidden') {
			return false;
		}
		if (el.offsetParent !== null || style.position === 'fixed') {
			return true;
		}
		const rect = typeof el.getBoundingClientRect === 'function' ? el.getBoundingClientRect() : null;
		return Boolean(rect && (rect.width > 0 || rect.height > 0));
	}

	/**
	 * Discover the active primary gallery slide/container across WooCommerce Flexslider,
	 * Flatsome Flickity, WooCommerce Block themes, and generic theme layouts.
	 *
	 * @return {HTMLElement|null}
	 */
	findMainSlideContainer() {
		const selectors = [
			// 1. Standard WooCommerce Flexslider active slide
			'.woocommerce-product-gallery .flex-active-slide',
			'.woocommerce-product-gallery__image.flex-active-slide',
			// 2. Flatsome / Flickity active slide
			'.product-gallery-slider .slide.is-selected',
			'.flickity-slider > .slide.is-selected',
			'.product-gallery-slider .woocommerce-product-gallery__image.is-selected',
			// 3. WooCommerce Block themes (Product Gallery / Product Image blocks)
			'.wc-block-product-gallery-large-image',
			'.wc-block-components-product-image',
			'[data-block-name="woocommerce/product-image-gallery"] .woocommerce-product-gallery__image:first-child',
			// 4. Generic WooCommerce / Theme fallbacks
			'.woocommerce-product-gallery__image--placeholder',
			'.woocommerce-product-gallery__image:first-child',
			'.product-gallery-slider .slide:first-child',
			'.woocommerce-product-gallery__wrapper > div:first-child',
			'.woocommerce-product-gallery',
			'.product .images',
		];

		for (const sel of selectors) {
			const matches = document.querySelectorAll(sel);
			for (const el of matches) {
				if (this.isVisibleElement(el)) {
					return el;
				}
			}
		}

		// Fallback if layout dimensions aren't computed yet
		for (const sel of selectors) {
			const matches = document.querySelectorAll(sel);
			for (const el of matches) {
				const style = window.getComputedStyle(el);
				if (style && style.display !== 'none' && style.visibility !== 'hidden') {
					return el;
				}
			}
		}

		return document.querySelector('.woocommerce-product-gallery') || document.querySelector('.product .images');
	}

	/**
	 * Locate the primary visible product image inside a gallery slide container,
	 * excluding zoom flyout clones (`.easyzoom-flyout img`, `.zoomImg`) and emojis.
	 *
	 * @param {HTMLElement} container Slide or gallery container element.
	 * @return {HTMLImageElement|null}
	 */
	findMainGalleryImage(container) {
		if (!container) return null;

		const selectors = [
			'img.wp-post-image',
			'.wc-block-product-gallery-large-image__image',
			'.woocommerce-product-gallery__image img:not(.emoji):not(.zoomImg)',
			'img:not(.emoji):not(.zoomImg)',
		];

		const isExcluded = (img) => {
			if (!img || img.classList.contains('emoji') || img.classList.contains('zoomImg')) {
				return true;
			}
			if (img.closest('.easyzoom-flyout, .drift-zoom-pane')) {
				return true;
			}
			const thumbParent = img.closest('.flex-control-thumbs, .product-thumbnails, .wc-block-product-gallery-thumbnails');
			if (thumbParent && container.contains(thumbParent) && thumbParent !== container) {
				return true;
			}
			return false;
		};

		for (const sel of selectors) {
			const imgs = container.querySelectorAll(sel);
			for (const img of imgs) {
				if (isExcluded(img)) continue;
				if (this.isVisibleElement(img)) {
					return img;
				}
			}
		}

		// Fallback for images still loading before dimensions settle
		for (const sel of selectors) {
			const imgs = container.querySelectorAll(sel);
			for (const img of imgs) {
				if (isExcluded(img)) continue;
				const style = window.getComputedStyle(img);
				if (style && style.display !== 'none' && style.visibility !== 'hidden') {
					return img;
				}
			}
		}

		return null;
	}

	/**
	 * Permanently suppress WooCommerce/Shoptimizer/Flatsome/theme hover zoom flyouts
	 * (`.zoomImg`, `.drift-zoom-pane`, `.easyzoom-flyout`) from covering the personalized overlay,
	 * while leaving interactive `.zoom-button` triggers untouched.
	 */
	suppressZoom() {
		const gallerySelector = '.woocommerce-product-gallery, .product-gallery-slider, .product-images, .wc-block-product-gallery-large-image, .product';
		const flyoutSelector = '.zoomImg, .drift-zoom-pane, .easyzoom-flyout';

		const killZoom = () => {
			const matchedRoots = Array.from(document.querySelectorAll(gallerySelector));
			const targets = matchedRoots.length > 0 ? matchedRoots : [document];
			targets.forEach((target) => {
				target.querySelectorAll(flyoutSelector).forEach((el) => {
					if (el.classList.contains('zoom-button')) return;
					if (el.style.getPropertyValue('display') !== 'none' || el.style.getPropertyPriority('display') !== 'important') {
						el.style.setProperty('display', 'none', 'important');
					}
					if (el.style.getPropertyValue('opacity') !== '0' || el.style.getPropertyPriority('opacity') !== 'important') {
						el.style.setProperty('opacity', '0', 'important');
					}
					if (el.style.getPropertyValue('pointer-events') !== 'none' || el.style.getPropertyPriority('pointer-events') !== 'important') {
						el.style.setProperty('pointer-events', 'none', 'important');
					}
				});
			});
		};

		killZoom();

		if (window.MutationObserver) {
			this.zoomObserverInitialized = true;
			this._observedZoomRoots = this._observedZoomRoots || new WeakSet();
			const targets = Array.from(document.querySelectorAll(gallerySelector));
			const observeTargets = targets.filter(el => !targets.some(other => other !== el && typeof other.contains === 'function' && other.contains(el)));

			observeTargets.forEach((root) => {
				if (this._observedZoomRoots.has(root)) return;
				this._observedZoomRoots.add(root);
				const zoomObserver = new window.MutationObserver((mutations) => {
					for (const m of mutations) {
						if (!m.addedNodes || m.addedNodes.length === 0) continue;
						if (m.target && m.target.closest && m.target.closest('#mr-tee-editor-container')) continue;
						killZoom();
						break;
					}
				});
				zoomObserver.observe(root, { childList: true, subtree: true });
			});
		}
	}

	/**
	 * Search for the main WooCommerce product image container and mount overlay.
	 */
	mountOverlay() {
		// Detect WooCommerce gallery targets in priority order
		const container = this.findMainSlideContainer();
		if (!container) return;

		// Ensure container has relative positioning
		if (window.getComputedStyle(container).position === 'static') {
			container.style.position = 'relative';
		}

		// Check if overlay already exists in this container
		let overlay = container.querySelector('.mr-tee-live-stage-overlay:not(.mr-tee-live-stage-overlay-pswp)');
		let svgHolder = overlay ? overlay.querySelector('.mr-tee-live-svg-container') : null;

		if (overlay && svgHolder && overlay.parentElement === container) {
			// Already properly mounted in this container!
			this.overlayElem = overlay;
			this.svgHolder = svgHolder;
			this.applyAspectRatioLock(container);
			this.recalibrateOverlayPosition(container, overlay);

			const activeImg = this.findMainGalleryImage(container);
			if (activeImg && !activeImg.complete && !activeImg._mrTeeLoadBound) {
				activeImg._mrTeeLoadBound = true;
				activeImg.addEventListener('load', () => {
					activeImg._mrTeeLoadBound = false;
					this.applyAspectRatioLock(container);
					this.recalibrateOverlayPosition(container, overlay);
				}, { once: true });
			}

			if (this.currentSvgMarkup && (!this.svgHolder.innerHTML || !this.svgHolder.querySelector('svg.mr-tee-live-svg, svg'))) {
				this.svgHolder.innerHTML = this.currentSvgMarkup;
				const mountedDefs = this.svgHolder.querySelector('defs.mr-tee-svg-defs, defs');
				const mountedSvg = this.svgHolder.querySelector('svg.mr-tee-live-svg, svg');
				if (mountedSvg && mountedDefs) {
					mountedSvg._lastDefsHtml = mountedDefs.innerHTML;
				}
			}
			return;
		}

		// Remove any misplaced or orphaned overlays from other elements
		document.querySelectorAll('.mr-tee-live-stage-overlay:not(.mr-tee-live-stage-overlay-pswp)').forEach((el) => {
			if (el !== overlay) el.remove();
		});

		// Create stage overlay if not already existing
		if (!overlay) {
			overlay = document.createElement('div');
			overlay.className = 'mr-tee-live-stage-overlay';
			overlay.style.position = 'absolute';
			overlay.style.pointerEvents = 'none';
			overlay.style.zIndex = '2';
			overlay.style.display = 'flex';
			overlay.style.alignItems = 'center';
			overlay.style.justifyContent = 'center';
			overlay.style.overflow = 'hidden';

			svgHolder = document.createElement('div');
			svgHolder.className = 'mr-tee-live-svg-container';
			svgHolder.style.width = '100%';
			svgHolder.style.height = '100%';
			overlay.appendChild(svgHolder);

			container.appendChild(overlay);
		}

		this.overlayElem = overlay;
		this.svgHolder = svgHolder;

		// Calculate precise position against rendered image bounds
		this.applyAspectRatioLock(container);
		this.recalibrateOverlayPosition(container, overlay);

		// Listen to image load if image is still loading
		const img = this.findMainGalleryImage(container);
		if (img && !img.complete && !img._mrTeeLoadBound) {
			img._mrTeeLoadBound = true;
			img.addEventListener('load', () => {
				img._mrTeeLoadBound = false;
				this.applyAspectRatioLock(container);
				this.recalibrateOverlayPosition(container, overlay);
			}, { once: true });
		}

		// Immediately restore current SVG markup if available
		if (this.currentSvgMarkup && this.svgHolder) {
			this.svgHolder.innerHTML = this.currentSvgMarkup;
			const mountedDefs = this.svgHolder.querySelector('defs.mr-tee-svg-defs, defs');
			const mountedSvg = this.svgHolder.querySelector('svg.mr-tee-live-svg, svg');
			if (mountedSvg && mountedDefs) {
				mountedSvg._lastDefsHtml = mountedDefs.innerHTML;
			}
		}
	}

	/**
	 * Apply locked physical aspect ratio to gallery container and product image.
	 *
	 * Ensures the product image container on mobile, tablet, and desktop always
	 * matches the exact physical aspect ratio (e.g. 8.9:5.1 for business card,
	 * 20:15 for plaque, 30:40 for apparel), eliminating letterboxing and coordinate drift.
	 *
	 * @param {HTMLElement} container Gallery container element.
	 */
	applyAspectRatioLock(container) {
		if (!container) return;

		const isLocked = Boolean(this.config.lock_aspect_ratio) || this.config.product_type === 'flat_object';
		if (!isLocked) return;

		const realW = parseFloat(this.config.print_real_width) || 0;
		const realH = parseFloat(this.config.print_real_height) || 0;
		const ratio = (realW > 0 && realH > 0) ? (realW / realH) : (parseFloat(this.config.print_aspect_ratio) || 1.0);

		container.style.aspectRatio = ratio.toString();
		container.style.maxWidth = '100%';

		const img = this.findMainGalleryImage(container);
		if (img) {
			img.style.aspectRatio = ratio.toString();
			img.style.width = '100%';
			img.style.height = '100%';
			img.style.objectFit = 'contain';
		}
	}

	/**
	 * Calibrate overlay positioning strictly against the rendered garment image bounds.
	 *
	 * Accounts for any letterboxing, flexbox centering margins, or padding introduced
	 * by WordPress themes, ensuring the design area remains pixel-pinned to the garment fabric.
	 *
	 * @param {HTMLElement} container Slide / gallery wrapper.
	 * @param {HTMLElement} overlay Stage overlay element.
	 */
	recalibrateOverlayPosition(container, overlay) {
		if (!container || !overlay) return;

		const isFlatObject = this.config.product_type === 'flat_object';
		const hasPerLineBoxes = Array.isArray(this.config.text_lines) && this.config.text_lines.some(l => l.box_left !== undefined && l.box_width !== undefined);

		let baseLeft = (isFlatObject || hasPerLineBoxes) ? 0.0 : (parseFloat(this.config.box_left) || 26.0);
		let baseTop = (isFlatObject || hasPerLineBoxes) ? 0.0 : (parseFloat(this.config.box_top) || 23.0);
		let baseWidth = (isFlatObject || hasPerLineBoxes) ? 100.0 : (parseFloat(this.config.box_width) || 48.0);
		let baseHeight = (isFlatObject || hasPerLineBoxes) ? 100.0 : (parseFloat(this.config.box_height) || 54.0);

		const img = this.findMainGalleryImage(container);
		this.attachedImage = img || null;

		if (img && !img.classList.contains('emoji') && !img.classList.contains('zoomImg')) {
			const contRect = typeof container.getBoundingClientRect === 'function' ? container.getBoundingClientRect() : null;
			const imgRect = typeof img.getBoundingClientRect === 'function' ? img.getBoundingClientRect() : null;
			const contStyle = window.getComputedStyle(container);
			const imgStyle = window.getComputedStyle(img);

			const borderL = parseFloat(contStyle.borderLeftWidth) || 0;
			const borderT = parseFloat(contStyle.borderTopWidth) || 0;
			const borderR = parseFloat(contStyle.borderRightWidth) || 0;
			const borderB = parseFloat(contStyle.borderBottomWidth) || 0;

			const padL = parseFloat(imgStyle.paddingLeft) || 0;
			const padT = parseFloat(imgStyle.paddingTop) || 0;
			const padR = parseFloat(imgStyle.paddingRight) || 0;
			const padB = parseFloat(imgStyle.paddingBottom) || 0;

			let contW = 0;
			let contH = 0;
			let imgLeft = 0;
			let imgTop = 0;
			let imgW = 0;
			let imgH = 0;

			if (contRect && imgRect && contRect.width > 0 && contRect.height > 0 && imgRect.width > 50) {
				contW = Math.max(0, contRect.width - borderL - borderR);
				contH = Math.max(0, contRect.height - borderT - borderB);
				imgLeft = (imgRect.left - contRect.left - borderL) + padL;
				imgTop = (imgRect.top - contRect.top - borderT) + padT;
				imgW = Math.max(0, imgRect.width - padL - padR);
				imgH = Math.max(0, imgRect.height - padT - padB);
			} else if (img.offsetWidth > 50 && container.offsetWidth > 0 && container.offsetHeight > 0) {
				contW = container.offsetWidth;
				contH = container.offsetHeight;
				imgLeft = img.offsetLeft + padL;
				imgTop = img.offsetTop + padT;
				imgW = Math.max(0, img.offsetWidth - padL - padR);
				imgH = Math.max(0, img.offsetHeight - padT - padB);
			}

			if (contW > 0 && contH > 0 && imgW > 50 && imgH > 0) {
				// Check if image has offset inside container or doesn't occupy 100% of container dimensions
				if (Math.abs(imgLeft) > 0.5 || Math.abs(imgTop) > 0.5 || Math.abs(imgW - contW) > 2 || Math.abs(imgH - contH) > 2) {
					const leftPct = ((imgLeft + (imgW * (baseLeft / 100))) / contW) * 100;
					const topPct = ((imgTop + (imgH * (baseTop / 100))) / contH) * 100;
					const widthPct = ((imgW * (baseWidth / 100)) / contW) * 100;
					const heightPct = ((imgH * (baseHeight / 100)) / contH) * 100;

					overlay.style.left = `${leftPct.toFixed(3)}%`;
					overlay.style.top = `${topPct.toFixed(3)}%`;
					overlay.style.width = `${widthPct.toFixed(3)}%`;
					overlay.style.height = `${heightPct.toFixed(3)}%`;
					return;
				}
			}
		}

		// Direct percentage alignment
		overlay.style.left = `${baseLeft}%`;
		overlay.style.top = `${baseTop}%`;
		overlay.style.width = `${baseWidth}%`;
		overlay.style.height = `${baseHeight}%`;
	}

	/**
	 * Synchronize live customized overlay with WooCommerce PhotoSwipe and Flatsome Magnific Popup lightboxes.
	 */
	initPhotoSwipeSync() {
		const getBaseBox = () => {
			const isFlatObject = this.config.product_type === 'flat_object';
			const hasPerLineBoxes = Array.isArray(this.config.text_lines) && this.config.text_lines.some(l => l.box_left !== undefined && l.box_width !== undefined);
			return {
				left: (isFlatObject || hasPerLineBoxes) ? 0.0 : (parseFloat(this.config.box_left) || 26.0),
				top: (isFlatObject || hasPerLineBoxes) ? 0.0 : (parseFloat(this.config.box_top) || 23.0),
				width: (isFlatObject || hasPerLineBoxes) ? 100.0 : (parseFloat(this.config.box_width) || 48.0),
				height: (isFlatObject || hasPerLineBoxes) ? 100.0 : (parseFloat(this.config.box_height) || 54.0),
			};
		};

		const mountLightboxSvg = (holder, markup) => {
			if (!holder || !markup) return;
			const existingSvg = holder.querySelector('svg.mr-tee-live-svg') || holder.querySelector('svg');
			if (!existingSvg) {
				holder.innerHTML = markup;
				holder._lastMountedMarkup = markup;
				const mountedDefs = holder.querySelector('defs.mr-tee-svg-defs, defs');
				const mountedSvg = holder.querySelector('svg.mr-tee-live-svg, svg');
				if (mountedSvg && mountedDefs) {
					mountedSvg._lastDefsHtml = mountedDefs.innerHTML;
				}
			} else if (holder._lastMountedMarkup !== markup) {
				this.patchSvgInPlace(existingSvg, markup);
				holder._lastMountedMarkup = markup;
			}
		};

		const attachToPswp = () => {
			const pswp = document.querySelector('.pswp--open');
			if (!pswp) return;

			// Target the active zoom-wrap
			const zoomWraps = Array.from(pswp.querySelectorAll('.pswp__zoom-wrap'));
			if (!zoomWraps.length) return;

			let zoomWrap = zoomWraps[0];
			if (zoomWraps.length > 1) {
				const vw = window.innerWidth || document.documentElement.clientWidth || 1024;
				const inView = zoomWraps.find((zw) => {
					const r = zw.getBoundingClientRect();
					return r && r.width > 0 && r.right > 20 && r.left < vw - 20;
				});
				if (inView) zoomWrap = inView;
			}

			// Ensure only one PhotoSwipe overlay exists across slide wrappers
			zoomWraps.forEach((zw) => {
				if (zw !== zoomWrap) {
					const stale = zw.querySelector('.mr-tee-live-stage-overlay-pswp');
					if (stale) stale.remove();
				}
			});

			// Ensure position relative for absolute positioning
			if (window.getComputedStyle(zoomWrap).position === 'static') {
				zoomWrap.style.position = 'relative';
			}

			const pswpImg = zoomWrap.querySelector('img.pswp__img:not(.pswp__img--placeholder)')
				|| zoomWrap.querySelector('img.pswp__img');
			if (pswpImg && !pswpImg.complete && !pswpImg._mrTeePswpLoadBound) {
				pswpImg._mrTeePswpLoadBound = true;
				pswpImg.addEventListener('load', () => {
					pswpImg._mrTeePswpLoadBound = false;
					attachToPswp();
				}, { once: true });
			}

			const box = getBaseBox();
			let pswpOverlay = zoomWrap.querySelector('.mr-tee-live-stage-overlay-pswp');
			let pswpHolder = null;

			if (!pswpOverlay) {
				pswpOverlay = document.createElement('div');
				pswpOverlay.className = 'mr-tee-live-stage-overlay mr-tee-live-stage-overlay-pswp';
				pswpOverlay.style.position = 'absolute';
				pswpOverlay.style.pointerEvents = 'none';
				pswpOverlay.style.zIndex = '100';
				pswpOverlay.style.display = 'flex';
				pswpOverlay.style.alignItems = 'center';
				pswpOverlay.style.justifyContent = 'center';

				pswpHolder = document.createElement('div');
				pswpHolder.className = 'mr-tee-live-svg-container';
				pswpHolder.style.width = '100%';
				pswpHolder.style.height = '100%';
				pswpOverlay.appendChild(pswpHolder);

				zoomWrap.appendChild(pswpOverlay);
			} else {
				pswpHolder = pswpOverlay.querySelector('.mr-tee-live-svg-container');
			}

			let imgL = 0;
			let imgT = 0;
			let imgW = 0;
			let imgH = 0;
			if (pswpImg) {
				imgL = pswpImg.offsetLeft || 0;
				imgT = pswpImg.offsetTop || 0;
				imgW = parseFloat(pswpImg.style.width) || pswpImg.offsetWidth || 0;
				imgH = parseFloat(pswpImg.style.height) || pswpImg.offsetHeight || 0;
				if (imgW <= 0 || imgH <= 0) {
					const zwRect = typeof zoomWrap.getBoundingClientRect === 'function' ? zoomWrap.getBoundingClientRect() : null;
					const imRect = typeof pswpImg.getBoundingClientRect === 'function' ? pswpImg.getBoundingClientRect() : null;
					if (zwRect && imRect && imRect.width > 0 && imRect.height > 0) {
						imgL = imRect.left - zwRect.left;
						imgT = imRect.top - zwRect.top;
						imgW = imRect.width;
						imgH = imRect.height;
					}
				}
			}

			if (imgW > 0 && imgH > 0) {
				pswpOverlay.style.left = `${(imgL + (imgW * (box.left / 100))).toFixed(2)}px`;
				pswpOverlay.style.top = `${(imgT + (imgH * (box.top / 100))).toFixed(2)}px`;
				pswpOverlay.style.width = `${(imgW * (box.width / 100)).toFixed(2)}px`;
				pswpOverlay.style.height = `${(imgH * (box.height / 100)).toFixed(2)}px`;
			} else {
				pswpOverlay.style.left = `${box.left}%`;
				pswpOverlay.style.top = `${box.top}%`;
				pswpOverlay.style.width = `${box.width}%`;
				pswpOverlay.style.height = `${box.height}%`;
			}

			const scopedMarkup = this.getLightboxSvgMarkup('pswp-');
			mountLightboxSvg(pswpHolder, scopedMarkup);
		};

		const attachToMfp = () => {
			const mfpWrap = document.querySelector('.mfp-wrap');
			if (!mfpWrap || mfpWrap.classList.contains('mfp-removing')) return;

			const mfpFigure = mfpWrap.querySelector('.mfp-figure');
			const mfpImg = (mfpFigure && mfpFigure.querySelector('.mfp-img')) || mfpWrap.querySelector('.mfp-img');
			if (!mfpImg) return;

			if (!mfpImg.complete) {
				if (!mfpImg._mrTeeMfpLoadBound) {
					mfpImg._mrTeeMfpLoadBound = true;
					mfpImg.addEventListener('load', () => {
						mfpImg._mrTeeMfpLoadBound = false;
						attachToMfp();
					}, { once: true });
				}
				return;
			}

			const mfpHost = mfpImg.parentElement || mfpFigure;
			if (!mfpHost) return;

			if (window.getComputedStyle(mfpHost).position === 'static') {
				mfpHost.style.position = 'relative';
			}

			// Clean up any stale overlays inside .mfp-wrap that aren't inside current mfpHost
			mfpWrap.querySelectorAll('.mr-tee-live-stage-overlay-pswp').forEach((el) => {
				if (el.parentElement !== mfpHost) el.remove();
			});

			let mfpOverlay = mfpHost.querySelector('.mr-tee-live-stage-overlay-pswp');
			let mfpHolder = null;

			if (!mfpOverlay) {
				mfpOverlay = document.createElement('div');
				mfpOverlay.className = 'mr-tee-live-stage-overlay mr-tee-live-stage-overlay-pswp';
				mfpOverlay.style.position = 'absolute';
				mfpOverlay.style.pointerEvents = 'none';
				mfpOverlay.style.zIndex = '100';
				mfpOverlay.style.display = 'flex';
				mfpOverlay.style.alignItems = 'center';
				mfpOverlay.style.justifyContent = 'center';

				mfpHolder = document.createElement('div');
				mfpHolder.className = 'mr-tee-live-svg-container';
				mfpHolder.style.width = '100%';
				mfpHolder.style.height = '100%';
				mfpOverlay.appendChild(mfpHolder);

				mfpHost.appendChild(mfpOverlay);
			} else {
				mfpHolder = mfpOverlay.querySelector('.mr-tee-live-svg-container');
			}

			const box = getBaseBox();
			const hostStyle = window.getComputedStyle(mfpHost);
			const imgStyle = window.getComputedStyle(mfpImg);

			const borderL = parseFloat(hostStyle.borderLeftWidth) || 0;
			const borderT = parseFloat(hostStyle.borderTopWidth) || 0;
			const borderR = parseFloat(hostStyle.borderRightWidth) || 0;
			const borderB = parseFloat(hostStyle.borderBottomWidth) || 0;

			const padL = parseFloat(imgStyle.paddingLeft) || 0;
			const padT = parseFloat(imgStyle.paddingTop) || 0;
			const padR = parseFloat(imgStyle.paddingRight) || 0;
			const padB = parseFloat(imgStyle.paddingBottom) || 0;

			const hostRect = typeof mfpHost.getBoundingClientRect === 'function' ? mfpHost.getBoundingClientRect() : null;
			const imgRect = typeof mfpImg.getBoundingClientRect === 'function' ? mfpImg.getBoundingClientRect() : null;

			let hostW = 0;
			let hostH = 0;
			let contentLeft = 0;
			let contentTop = 0;
			let contentWidth = 0;
			let contentHeight = 0;

			if (hostRect && imgRect && hostRect.width > 0 && hostRect.height > 0 && imgRect.width > 0 && imgRect.height > 0) {
				hostW = Math.max(0, hostRect.width - borderL - borderR);
				hostH = Math.max(0, hostRect.height - borderT - borderB);
				contentLeft = (imgRect.left - hostRect.left - borderL) + padL;
				contentTop = (imgRect.top - hostRect.top - borderT) + padT;
				contentWidth = Math.max(0, imgRect.width - padL - padR);
				contentHeight = Math.max(0, imgRect.height - padT - padB);
			} else if (mfpHost.offsetWidth > 0 && mfpHost.offsetHeight > 0 && mfpImg.offsetWidth > 0 && mfpImg.offsetHeight > 0) {
				hostW = mfpHost.offsetWidth;
				hostH = mfpHost.offsetHeight;
				contentLeft = mfpImg.offsetLeft + padL;
				contentTop = mfpImg.offsetTop + padT;
				contentWidth = Math.max(0, mfpImg.offsetWidth - padL - padR);
				contentHeight = Math.max(0, mfpImg.offsetHeight - padT - padB);
			}

			if (hostW > 0 && hostH > 0 && contentWidth > 0 && contentHeight > 0) {
				const leftPct = ((contentLeft + (contentWidth * (box.left / 100))) / hostW) * 100;
				const topPct = ((contentTop + (contentHeight * (box.top / 100))) / hostH) * 100;
				const widthPct = ((contentWidth * (box.width / 100)) / hostW) * 100;
				const heightPct = ((contentHeight * (box.height / 100)) / hostH) * 100;

				mfpOverlay.style.left = `${leftPct.toFixed(3)}%`;
				mfpOverlay.style.top = `${topPct.toFixed(3)}%`;
				mfpOverlay.style.width = `${widthPct.toFixed(3)}%`;
				mfpOverlay.style.height = `${heightPct.toFixed(3)}%`;
			} else {
				mfpOverlay.style.left = `${box.left}%`;
				mfpOverlay.style.top = `${box.top}%`;
				mfpOverlay.style.width = `${box.width}%`;
				mfpOverlay.style.height = `${box.height}%`;
			}

			const scopedMarkup = this.getLightboxSvgMarkup('mfp-');
			mountLightboxSvg(mfpHolder, scopedMarkup);
		};

		const attachAllLightboxes = () => {
			attachToPswp();
			attachToMfp();
		};
		this._syncLightboxes = attachAllLightboxes;

		// 1. Listen for clicks on zoom triggers, gallery links, and lightbox navigation arrows
		const lightboxTriggerSelectors = [
			'.woocommerce-product-gallery__trigger',
			'.woocommerce-product-gallery__image a',
			'.zoom-button',
			'.product-gallery-slider a',
			'.mfp-arrow',
			'.pswp__button--arrow--left',
			'.pswp__button--arrow--right',
		].join(', ');

		document.addEventListener('click', (e) => {
			if (e.target && e.target.closest(lightboxTriggerSelectors)) {
				setTimeout(attachAllLightboxes, 100);
				setTimeout(attachAllLightboxes, 300);
				setTimeout(attachAllLightboxes, 600);
			}
		});

		// 2. Observe PhotoSwipe element ONLY for class changes (opening / closing)
		const observePswpElem = () => {
			const pswpElem = document.querySelector('.pswp');
			if (pswpElem && window.MutationObserver && !pswpElem._mrTeeObserved) {
				pswpElem._mrTeeObserved = true;
				let wasOpen = pswpElem.classList.contains('pswp--open');
				const pswpObserver = new window.MutationObserver(() => {
					const isOpen = pswpElem.classList.contains('pswp--open');
					if (isOpen && !wasOpen) {
						wasOpen = true;
						setTimeout(attachToPswp, 100);
						setTimeout(attachToPswp, 300);
						setTimeout(attachToPswp, 600);
					} else if (!isOpen && wasOpen) {
						wasOpen = false;
						// PhotoSwipe closed: ensure main overlay is pristine
						this.mountOverlay();
					}
				});
				pswpObserver.observe(pswpElem, { attributes: true, attributeFilter: ['class'] });
			}
		};
		observePswpElem();

		// 3. Observe document.body mutations for Flatsome Magnific Popup (.mfp-wrap) opening/closing
		if (document.body && window.MutationObserver) {
			let wasMfpOpen = Boolean(document.querySelector('.mfp-wrap'));
			const isMfpOrPswpMutation = (mutations) => {
				for (const m of mutations) {
					if (m.target && m.target.closest) {
						if (m.target.closest('.mr-tee-live-stage-overlay-pswp')) continue;
						if (m.target.closest('.mfp-wrap')) return true;
					}
					if (m.target === document.body) {
						const checkNodes = (nodeList) => {
							for (const n of nodeList) {
								if (!n || n.nodeType !== 1) continue;
								if (n.classList && (n.classList.contains('mfp-wrap') || n.classList.contains('pswp'))) return true;
								if (n.querySelector && n.querySelector('.mfp-wrap, .pswp')) return true;
							}
							return false;
						};
						if ((m.addedNodes && checkNodes(m.addedNodes)) || (m.removedNodes && checkNodes(m.removedNodes))) {
							return true;
						}
					} else if (m.addedNodes && m.addedNodes.length > 0) {
						for (const n of m.addedNodes) {
							if (n && n.nodeType === 1 && ((n.classList && (n.classList.contains('mfp-wrap') || n.classList.contains('mfp-figure') || n.classList.contains('mfp-img') || n.classList.contains('pswp'))) || (n.querySelector && n.querySelector('.mfp-wrap, .mfp-figure, .mfp-img, .pswp')))) {
								return true;
							}
						}
					}
				}
				return false;
			};

			const bodyObserver = new window.MutationObserver((mutations) => {
				if (!isMfpOrPswpMutation(mutations)) return;
				observePswpElem();
				if (document.querySelector('.pswp--open') && !document.querySelector('.pswp--open .mr-tee-live-stage-overlay-pswp')) {
					setTimeout(attachToPswp, 50);
					setTimeout(attachToPswp, 200);
				}

				const isMfpOpen = Boolean(document.querySelector('.mfp-wrap'));
				if (isMfpOpen) {
					if (!wasMfpOpen) {
						wasMfpOpen = true;
						setTimeout(attachToMfp, 50);
						setTimeout(attachToMfp, 150);
						setTimeout(attachToMfp, 350);
					} else {
						attachToMfp();
					}
				} else if (wasMfpOpen) {
					wasMfpOpen = false;
					// Magnific Popup closed: ensure main overlay is pristine
					this.mountOverlay();
				}
			});
			bodyObserver.observe(document.body, { childList: true, subtree: true });
		}
	}

	/**
	 * Listen to WooCommerce gallery change events (Flexslider, Flickity, thumbnail clicks, variation swatch changes).
	 */
	bindGalleryEvents() {
		const thumbClickSelectors = [
			'.flex-control-thumbs li',
			'.woocommerce-product-gallery__wrapper',
			'.product-thumbnails .col',
			'.flickity-prev-next-button',
			'.wc-block-product-gallery-thumbnails img',
			'.wp-block-woocommerce-product-image-gallery img',
		].join(', ');

		// When user clicks a gallery thumbnail or slider arrow
		document.addEventListener('click', (e) => {
			if (e.target && e.target.closest(thumbClickSelectors)) {
				setTimeout(() => {
					this.mountOverlay();
					if (this.overlayElem && this.overlayElem.parentElement) {
						this.recalibrateOverlayPosition(this.overlayElem.parentElement, this.overlayElem);
					}
					this.suppressZoom();
				}, 150);
			}
		});

		// Listen to jQuery WooCommerce & Flatsome Flickity events if jQuery is present
		if (window.jQuery) {
			const $ = window.jQuery;
			const refreshOverlay = () => {
				setTimeout(() => {
					this.mountOverlay();
					if (this.overlayElem && this.overlayElem.parentElement) {
						this.recalibrateOverlayPosition(this.overlayElem.parentElement, this.overlayElem);
					}
					this.suppressZoom();
				}, 100);
			};

			const boundFlickitySliders = new WeakSet();
			const bindFlickityEvents = () => {
				$('.product-gallery-slider').each((_, sliderEl) => {
					if (boundFlickitySliders.has(sliderEl)) return;
					boundFlickitySliders.add(sliderEl);
					$(sliderEl).on('change.flickity select.flickity settle.flickity', refreshOverlay);
				});
			};

			$(document).on(
				'woocommerce_gallery_init_zoom woocommerce_variation_has_changed flexslider_after flatsome-flickity-ready',
				() => {
					bindFlickityEvents();
					refreshOverlay();
				}
			);

			bindFlickityEvents();
		}

		// Responsive resize listener: debounce and ignore pure height changes (virtual keyboard)
		let resizeTimer = null;
		window.addEventListener('resize', () => {
			clearTimeout(resizeTimer);
			resizeTimer = setTimeout(() => {
				const currentWidth = window.innerWidth;
				// Only re-check mount on actual horizontal layout changes or orientation switch
				if (Math.abs(currentWidth - this.lastWindowWidth) > 10) {
					this.lastWindowWidth = currentWidth;
					this.mountOverlay();
				} else {
					// If width didn't change (e.g. keyboard open/close), ensure SVG is still intact
					if (this.svgHolder && this.currentSvgMarkup && (!this.svgHolder.innerHTML || !this.svgHolder.querySelector('svg.mr-tee-live-svg, svg'))) {
						this.svgHolder.innerHTML = this.currentSvgMarkup;
						const mountedDefs = this.svgHolder.querySelector('defs.mr-tee-svg-defs, defs');
						const mountedSvg = this.svgHolder.querySelector('svg.mr-tee-live-svg, svg');
						if (mountedSvg && mountedDefs) {
							mountedSvg._lastDefsHtml = mountedDefs.innerHTML;
						}
					}
					if (this.overlayElem && this.overlayElem.parentElement) {
						this.recalibrateOverlayPosition(this.overlayElem.parentElement, this.overlayElem);
					}
				}
				if (typeof this._syncLightboxes === 'function') {
					this._syncLightboxes();
				}
				this.suppressZoom();
			}, 100);
		});
	}

	/**
	 * Update the live SVG preview content immediately with zero lag using keyed in-place DOM reconciliation.
	 *
	 * @param {string} svgMarkup Raw SVG markup string.
	 */
	updateSvg(svgMarkup) {
		this.currentSvgMarkup = svgMarkup || '';

		if (!this.svgHolder || !this.svgHolder.isConnected) {
			this.mountOverlay();
		}

		if (this.svgHolder) {
			const existingSvg = this.svgHolder.querySelector('svg.mr-tee-live-svg') || this.svgHolder.querySelector('svg');
			if (!existingSvg || !this.currentSvgMarkup) {
				this.svgHolder.innerHTML = this.currentSvgMarkup;
				const mountedDefs = this.svgHolder.querySelector('defs.mr-tee-svg-defs, defs');
				const mountedSvg = this.svgHolder.querySelector('svg.mr-tee-live-svg') || this.svgHolder.querySelector('svg');
				this._lastDefsHtml = mountedDefs ? mountedDefs.innerHTML : '';
				if (mountedSvg && mountedDefs) {
					mountedSvg._lastDefsHtml = this._lastDefsHtml;
				}
			} else {
				this.patchSvgInPlace(existingSvg, this.currentSvgMarkup);
			}
		}

		// Synchronize PhotoSwipe overlay if currently open
		const pswpHolder = document.querySelector('.pswp--open .mr-tee-live-stage-overlay-pswp .mr-tee-live-svg-container');
		if (pswpHolder) {
			const pswpMarkup = this.getLightboxSvgMarkup('pswp-');
			const existingPswpSvg = pswpHolder.querySelector('svg.mr-tee-live-svg') || pswpHolder.querySelector('svg');
			if (!existingPswpSvg || !pswpMarkup) {
				pswpHolder.innerHTML = pswpMarkup;
			} else {
				this.patchSvgInPlace(existingPswpSvg, pswpMarkup);
			}
			pswpHolder._lastMountedMarkup = pswpMarkup;
		}

		// Synchronize Flatsome Magnific Popup overlay if currently open
		const mfpHolder = document.querySelector('.mfp-wrap .mr-tee-live-stage-overlay-pswp .mr-tee-live-svg-container');
		if (mfpHolder) {
			const mfpMarkup = this.getLightboxSvgMarkup('mfp-');
			const existingMfpSvg = mfpHolder.querySelector('svg.mr-tee-live-svg') || mfpHolder.querySelector('svg');
			if (!existingMfpSvg || !mfpMarkup) {
				mfpHolder.innerHTML = mfpMarkup;
			} else {
				this.patchSvgInPlace(existingMfpSvg, mfpMarkup);
			}
			mfpHolder._lastMountedMarkup = mfpMarkup;
		}
	}

	/**
	 * Patch an existing mounted <svg> element in-place so <defs> and already-rendered
	 * OpenType-SVG glyph <image> nodes are never destroyed or re-decoded during typing.
	 *
	 * @param {SVGElement} existingSvg Currently mounted <svg> element.
	 * @param {string} svgMarkup New SVG markup string.
	 */
	patchSvgInPlace(existingSvg, svgMarkup) {
		try {
			this._domParser = this._domParser || new DOMParser();
			const doc = this._domParser.parseFromString(svgMarkup, 'image/svg+xml');
			const newSvg = doc.querySelector('svg.mr-tee-live-svg') || doc.querySelector('svg') || doc.documentElement;
			if (!newSvg || newSvg.nodeName.toLowerCase() !== 'svg') {
				existingSvg.parentNode.innerHTML = svgMarkup;
				return;
			}

			// 1. Sync root viewBox if changed
			const newViewBox = newSvg.getAttribute('viewBox');
			if (newViewBox && existingSvg.getAttribute('viewBox') !== newViewBox) {
				existingSvg.setAttribute('viewBox', newViewBox);
			}

			// 2. Sync <defs> only when font/clip definitions actually change for this specific SVG element
			const existingDefs = existingSvg.querySelector('defs.mr-tee-svg-defs') || existingSvg.querySelector('defs');
			const newDefs = newSvg.querySelector('defs.mr-tee-svg-defs') || newSvg.querySelector('defs');
			if (existingDefs && newDefs) {
				const newDefsHtml = newDefs.innerHTML;
				if (existingSvg._lastDefsHtml !== newDefsHtml && existingDefs.innerHTML !== newDefsHtml) {
					const importedDefs = Array.from(newDefs.childNodes).map(n => document.importNode(n, true));
					existingDefs.replaceChildren(...importedDefs);
					existingSvg._lastDefsHtml = newDefsHtml;
				}
			}

			// 3. Reconcile scene root in-place (recursively traversing .mrtee-group containers)
			const existingRoot = existingSvg.querySelector('.mr-tee-scene-root, [id$="mr-tee-scene-root"]');
			const newRoot = newSvg.querySelector('.mr-tee-scene-root, [id$="mr-tee-scene-root"]');
			if (!existingRoot || !newRoot) {
				existingSvg.parentNode.innerHTML = svgMarkup;
				return;
			}

			this.reconcileContainer(existingRoot, newRoot);
		} catch (err) {
			if (existingSvg && existingSvg.parentNode) {
				existingSvg.parentNode.innerHTML = svgMarkup;
			}
		}
	}

	/**
	 * Recursively reconcile an SVG container (#mr-tee-scene-root or .mrtee-group)
	 * in-place so existing .mrtee-opentype-svg-text glyph <image> nodes are never destroyed.
	 *
	 * @param {Element} existingContainer Currently mounted container element.
	 * @param {Element} newContainer Newly parsed container element.
	 */
	reconcileContainer(existingContainer, newContainer) {
		const newTransform = newContainer.getAttribute('transform');
		if (newTransform) {
			if (existingContainer.getAttribute('transform') !== newTransform) {
				existingContainer.setAttribute('transform', newTransform);
			}
		} else if (existingContainer.hasAttribute('transform')) {
			existingContainer.removeAttribute('transform');
		}

		const newClass = newContainer.getAttribute('class');
		if (newClass !== null) {
			if (existingContainer.getAttribute('class') !== newClass) {
				existingContainer.setAttribute('class', newClass);
			}
		} else if (existingContainer.hasAttribute('class')) {
			existingContainer.removeAttribute('class');
		}

		const exChildren = existingContainer.children;
		const nwChildren = newContainer.children;

		if (exChildren.length === nwChildren.length) {
			for (let i = 0; i < nwChildren.length; i++) {
				const exChild = exChildren[i];
				const nwChild = nwChildren[i];
				if (
					exChild.classList.contains('mrtee-opentype-svg-text') &&
					nwChild.classList.contains('mrtee-opentype-svg-text')
				) {
					this.reconcileOpenTypeTextGroup(exChild, nwChild);
				} else if (
					exChild.nodeName.toLowerCase() === 'g' &&
					nwChild.nodeName.toLowerCase() === 'g' &&
					(exChild.classList.contains('mrtee-group') || exChild.querySelector('.mrtee-opentype-svg-text, .mrtee-group')) &&
					(nwChild.classList.contains('mrtee-group') || nwChild.querySelector('.mrtee-opentype-svg-text, .mrtee-group'))
				) {
					this.reconcileContainer(exChild, nwChild);
				} else if (exChild.outerHTML !== nwChild.outerHTML) {
					existingContainer.replaceChild(document.importNode(nwChild, true), exChild);
				}
			}
		} else {
			// Preserve existing OpenType-SVG text groups & .mrtee-group containers across line additions/removals
			const existingMap = new Map();
			const pushExisting = (key, el) => {
				if (!existingMap.has(key)) existingMap.set(key, []);
				existingMap.get(key).push(el);
			};
			const popExisting = (key) => {
				const list = existingMap.get(key);
				return list && list.length ? list.shift() : null;
			};

			for (let i = 0; i < exChildren.length; i++) {
				const c = exChildren[i];
				if (c.classList.contains('mrtee-opentype-svg-text')) {
					const nid = c.getAttribute('data-node-id') || `__idx_${i}`;
					pushExisting(`ot:${nid}`, c);
				} else if (c.classList.contains('mrtee-image-field')) {
					const nid = c.getAttribute('data-node-id') || `__idx_${i}`;
					pushExisting(`img:${nid}`, c);
				} else if (c.nodeName.toLowerCase() === 'g' && c.classList.contains('mrtee-group')) {
					const cls = c.getAttribute('class') || `__idx_${i}`;
					pushExisting(`grp:${cls}`, c);
				}
			}

			const nextNodes = [];
			for (let i = 0; i < nwChildren.length; i++) {
				const nwChild = nwChildren[i];
				if (nwChild.classList.contains('mrtee-opentype-svg-text')) {
					const nid = nwChild.getAttribute('data-node-id') || `__idx_${i}`;
					const matchedEx = popExisting(`ot:${nid}`);
					if (matchedEx) {
						this.reconcileOpenTypeTextGroup(matchedEx, nwChild);
						nextNodes.push(matchedEx);
						continue;
					}
				} else if (nwChild.classList.contains('mrtee-image-field')) {
					const nid = nwChild.getAttribute('data-node-id') || `__idx_${i}`;
					const matchedEx = popExisting(`img:${nid}`);
					if (matchedEx) {
						if (matchedEx.outerHTML !== nwChild.outerHTML) {
							nextNodes.push(document.importNode(nwChild, true));
						} else {
							nextNodes.push(matchedEx);
						}
						continue;
					}
				} else if (nwChild.nodeName.toLowerCase() === 'g' && nwChild.classList.contains('mrtee-group')) {
					const cls = nwChild.getAttribute('class') || `__idx_${i}`;
					const matchedEx = popExisting(`grp:${cls}`);
					if (matchedEx) {
						this.reconcileContainer(matchedEx, nwChild);
						nextNodes.push(matchedEx);
						continue;
					}
				}
				nextNodes.push(document.importNode(nwChild, true));
			}

			existingContainer.replaceChildren(...nextNodes);
		}
	}

	/**
	 * Reconcile an OpenType-SVG text group (.mrtee-opentype-svg-text) in-place by
	 * preserving existing <g class="mrtee-ot-glyph"> nodes and only updating their transform.
	 *
	 * @param {Element} existingGroup Mounted .mrtee-opentype-svg-text element.
	 * @param {Element} newGroup Newly rendered .mrtee-opentype-svg-text element.
	 */
	reconcileOpenTypeTextGroup(existingGroup, newGroup) {
		const newTransform = newGroup.getAttribute('transform');
		if (newTransform) {
			if (existingGroup.getAttribute('transform') !== newTransform) {
				existingGroup.setAttribute('transform', newTransform);
			}
		} else if (existingGroup.hasAttribute('transform')) {
			existingGroup.removeAttribute('transform');
		}

		const newNodeId = newGroup.getAttribute('data-node-id');
		if (newNodeId && existingGroup.getAttribute('data-node-id') !== newNodeId) {
			existingGroup.setAttribute('data-node-id', newNodeId);
		}

		const newGlyphs = newGroup.children;
		const targetLen = newGlyphs.length;

		for (let i = 0; i < targetLen; i++) {
			const nwGlyph = newGlyphs[i];
			const nwKey = nwGlyph.getAttribute('data-glyph-key');
			const nwTransform = nwGlyph.getAttribute('transform') || '';
			const exGlyph = existingGroup.children[i];

			if (exGlyph && nwKey && exGlyph.getAttribute('data-glyph-key') === nwKey) {
				// Same glyph at same index: keep <image> untouched, only update transform!
				if (exGlyph.getAttribute('transform') !== nwTransform) {
					exGlyph.setAttribute('transform', nwTransform);
				}
			} else if (exGlyph) {
				// Different glyph at this position: check if the next sibling matches (e.g. single-char deletion)
				const nextEx = existingGroup.children[i + 1];
				if (nextEx && nwKey && nextEx.getAttribute('data-glyph-key') === nwKey) {
					existingGroup.removeChild(exGlyph);
					if (nextEx.getAttribute('transform') !== nwTransform) {
						nextEx.setAttribute('transform', nwTransform);
					}
				} else {
					existingGroup.replaceChild(document.importNode(nwGlyph, true), exGlyph);
				}
			} else {
				// Appended glyph at end of text
				existingGroup.appendChild(document.importNode(nwGlyph, true));
			}
		}

		// Remove any extra trailing glyphs (e.g. backspace at end of text)
		while (existingGroup.children.length > targetLen) {
			existingGroup.removeChild(existingGroup.lastChild);
		}
	}
}
