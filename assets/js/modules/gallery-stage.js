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
	constructor(config) {
		this.config = config || {};
		this.overlayElem = null;
		this.svgHolder = null;
		this.attachedImage = null;
		this.currentSvgMarkup = '';
		this.lastWindowWidth = typeof window !== 'undefined' ? window.innerWidth : 0;

		this.init();
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
	 * Permanently suppress WooCommerce/Shoptimizer raw zoomImg from covering the personalized overlay.
	 */
	suppressZoom() {
		const killZoom = () => {
			const gallery = document.querySelector('.woocommerce-product-gallery');
			const target = gallery || document;
			target.querySelectorAll('.zoomImg').forEach((img) => {
				img.style.setProperty('display', 'none', 'important');
				img.style.setProperty('opacity', '0', 'important');
				img.style.setProperty('pointer-events', 'none', 'important');
			});
		};

		killZoom();

		if (!this.zoomObserverInitialized) {
			this.zoomObserverInitialized = true;
			const gallery = document.querySelector('.woocommerce-product-gallery');
			if (gallery && window.MutationObserver) {
				const zoomObserver = new MutationObserver(() => killZoom());
				zoomObserver.observe(gallery, { childList: true, subtree: true });
			}
		}
	}

	/**
	 * Search for the main WooCommerce product image container and mount overlay.
	 */
	mountOverlay() {
		// Detect WooCommerce gallery targets in priority order
		const selectors = [
			'.woocommerce-product-gallery .flex-active-slide',
			'.woocommerce-product-gallery__image--placeholder',
			'.woocommerce-product-gallery__image:first-child',
			'.woocommerce-product-gallery__wrapper > div:first-child',
			'.woocommerce-product-gallery',
		];

		let container = null;
		for (const sel of selectors) {
			const el = document.querySelector(sel);
			if (el && el.offsetParent !== null) {
				container = el;
				break;
			}
		}

		if (!container) {
			container = document.querySelector('.woocommerce-product-gallery') || document.querySelector('.product .images');
		}

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
			this.recalibrateOverlayPosition(container, overlay);
			if (this.currentSvgMarkup && (!this.svgHolder.innerHTML || !this.svgHolder.querySelector('svg'))) {
				this.svgHolder.innerHTML = this.currentSvgMarkup;
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
			overlay.style.zIndex = '20';
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
		const img = container.querySelector('img.wp-post-image, img:not(.emoji):not(.zoomImg)');
		if (img && !img.complete) {
			img.addEventListener('load', () => {
				this.applyAspectRatioLock(container);
				this.recalibrateOverlayPosition(container, overlay);
			}, { once: true });
		}

		// Immediately restore current SVG markup if available
		if (this.currentSvgMarkup && this.svgHolder) {
			this.svgHolder.innerHTML = this.currentSvgMarkup;
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

		const img = container.querySelector('img.wp-post-image, img:not(.emoji):not(.zoomImg)');
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

		const img = container.querySelector('img.wp-post-image, .woocommerce-product-gallery__image img:not(.emoji):not(.zoomImg), img:not(.emoji):not(.zoomImg)');
		if (img && !img.classList.contains('emoji') && !img.classList.contains('zoomImg') && img.offsetWidth > 50 && container.offsetWidth > 0) {
			const imgLeft = img.offsetLeft;
			const imgTop = img.offsetTop;
			const imgW = img.offsetWidth;
			const imgH = img.offsetHeight;
			const contW = container.offsetWidth;
			const contH = container.offsetHeight;

			// Check if image has offset inside container or doesn't occupy 100% of container dimensions
			if (imgLeft !== 0 || imgTop !== 0 || Math.abs(imgW - contW) > 2 || Math.abs(imgH - contH) > 2) {
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

		// Direct percentage alignment
		overlay.style.left = `${baseLeft}%`;
		overlay.style.top = `${baseTop}%`;
		overlay.style.width = `${baseWidth}%`;
		overlay.style.height = `${baseHeight}%`;
	}

	/**
	 * Synchronize live customized overlay with WooCommerce PhotoSwipe fullscreen lightbox.
	 */
	initPhotoSwipeSync() {
		const attachToPswp = () => {
			const pswp = document.querySelector('.pswp--open');
			if (!pswp) return;

			// Target the active zoom-wrap
			const zoomWrap = pswp.querySelector('.pswp__zoom-wrap');
			if (!zoomWrap) return;

			// Ensure position relative for absolute positioning
			if (window.getComputedStyle(zoomWrap).position === 'static') {
				zoomWrap.style.position = 'relative';
			}

			const hasPerLineBoxes = Array.isArray(this.config.text_lines) && this.config.text_lines.some(l => l.box_left !== undefined && l.box_width !== undefined);
			const left = hasPerLineBoxes ? 0.0 : (parseFloat(this.config.box_left) || 26.0);
			const top = hasPerLineBoxes ? 0.0 : (parseFloat(this.config.box_top) || 23.0);
			const width = hasPerLineBoxes ? 100.0 : (parseFloat(this.config.box_width) || 48.0);
			const height = hasPerLineBoxes ? 100.0 : (parseFloat(this.config.box_height) || 54.0);

			let pswpOverlay = zoomWrap.querySelector('.mr-tee-live-stage-overlay-pswp');
			let pswpHolder = null;

			if (!pswpOverlay) {
				pswpOverlay = document.createElement('div');
				pswpOverlay.className = 'mr-tee-live-stage-overlay mr-tee-live-stage-overlay-pswp';
				pswpOverlay.style.position = 'absolute';
				pswpOverlay.style.left = `${left}%`;
				pswpOverlay.style.top = `${top}%`;
				pswpOverlay.style.width = `${width}%`;
				pswpOverlay.style.height = `${height}%`;
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

			if (pswpHolder && this.currentSvgMarkup && pswpHolder.innerHTML !== this.currentSvgMarkup) {
				pswpHolder.innerHTML = this.currentSvgMarkup;
			}
		};

		// 1. Listen for clicks on the zoom trigger and image links
		document.addEventListener('click', (e) => {
			if (e.target && e.target.closest('.woocommerce-product-gallery__trigger, .woocommerce-product-gallery__image a')) {
				setTimeout(attachToPswp, 100);
				setTimeout(attachToPswp, 300);
				setTimeout(attachToPswp, 600);
			}
		});

		// 2. Observe PhotoSwipe element ONLY for class changes (opening / closing)
		const pswpElem = document.querySelector('.pswp');
		if (pswpElem && window.MutationObserver) {
			let wasOpen = false;
			const pswpObserver = new MutationObserver(() => {
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
	}

	/**
	 * Listen to WooCommerce gallery change events (Flexslider, thumbnail clicks, variation swatch changes).
	 */
	bindGalleryEvents() {
		// When user clicks a gallery thumbnail
		document.addEventListener('click', (e) => {
			if (e.target && e.target.closest('.flex-control-thumbs li, .woocommerce-product-gallery__wrapper')) {
				setTimeout(() => {
					this.mountOverlay();
					this.suppressZoom();
				}, 150);
			}
		});

		// Listen to jQuery WooCommerce events if jQuery is present
		if (window.jQuery) {
			window.jQuery(document).on('woocommerce_gallery_init_zoom woocommerce_variation_has_changed flexslider_after', () => {
				setTimeout(() => {
					this.mountOverlay();
					this.suppressZoom();
				}, 100);
			});
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
					if (this.svgHolder && this.currentSvgMarkup && (!this.svgHolder.innerHTML || !this.svgHolder.querySelector('svg'))) {
						this.svgHolder.innerHTML = this.currentSvgMarkup;
					}
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
			const existingSvg = this.svgHolder.querySelector('svg#mr-tee-svg-element');
			if (!existingSvg || !this.currentSvgMarkup) {
				this.svgHolder.innerHTML = this.currentSvgMarkup;
				const mountedDefs = this.svgHolder.querySelector('#mr-tee-svg-defs');
				this._lastDefsHtml = mountedDefs ? mountedDefs.innerHTML : '';
			} else {
				this.patchSvgInPlace(existingSvg, this.currentSvgMarkup);
			}
		}

		// Synchronize PhotoSwipe overlay if currently open
		const pswpHolder = document.querySelector('.pswp--open .mr-tee-live-stage-overlay-pswp .mr-tee-live-svg-container');
		if (pswpHolder) {
			const existingPswpSvg = pswpHolder.querySelector('svg#mr-tee-svg-element');
			if (!existingPswpSvg || !this.currentSvgMarkup) {
				pswpHolder.innerHTML = this.currentSvgMarkup;
			} else {
				this.patchSvgInPlace(existingPswpSvg, this.currentSvgMarkup);
			}
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
			const newSvg = doc.querySelector('svg#mr-tee-svg-element') || doc.documentElement;
			if (!newSvg || newSvg.nodeName.toLowerCase() !== 'svg') {
				existingSvg.parentNode.innerHTML = svgMarkup;
				return;
			}

			// 1. Sync root viewBox if changed
			const newViewBox = newSvg.getAttribute('viewBox');
			if (newViewBox && existingSvg.getAttribute('viewBox') !== newViewBox) {
				existingSvg.setAttribute('viewBox', newViewBox);
			}

			// 2. Sync <defs> only when font definitions actually change
			const existingDefs = existingSvg.querySelector('#mr-tee-svg-defs') || existingSvg.querySelector('defs');
			const newDefs = newSvg.querySelector('#mr-tee-svg-defs') || newSvg.querySelector('defs');
			if (existingDefs && newDefs) {
				const newDefsHtml = newDefs.innerHTML;
				if (this._lastDefsHtml !== newDefsHtml && existingDefs.innerHTML !== newDefsHtml) {
					existingDefs.innerHTML = newDefsHtml;
					this._lastDefsHtml = newDefsHtml;
				}
			}

			// 3. Reconcile #mr-tee-scene-root in-place (recursively traversing .mrtee-group containers)
			const existingRoot = existingSvg.querySelector('#mr-tee-scene-root');
			const newRoot = newSvg.querySelector('#mr-tee-scene-root');
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
