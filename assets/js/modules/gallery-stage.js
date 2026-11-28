/**
 * Mr. Tee Editor - Gallery Stage Overlay Module (v2.6.0)
 *
 * Mounts and synchronizes the live SVG scene-graph overlay directly on top
 * of DedicatedGallery slides, dynamically importing `legacy-gallery-stage.js`
 * ONLY when a theme bypasses DedicatedGallery.
 *
 * Features keyed in-place SVG DOM reconciliation with cached `_mrTeeMarkup`
 * strings so unchanged sibling `<g>` nodes skip expensive `outerHTML`
 * serialization and DOM replacement on every keystroke.
 *
 * @package MrTeeEditor\Assets\Modules
 */

import { DedicatedGallery } from './dedicated-gallery.js?v=2.6.0';
import { SvgRenderer } from './svg-renderer.js?v=2.6.0';

export class GalleryStage {
	constructor(config, options = {}) {
		this.config = config || {};
		this.options = options || {};
		this.renderSvgWithPrefix = typeof this.options.renderSvgWithPrefix === 'function'
			? this.options.renderSvgWithPrefix
			: null;
		this.onViewChange = typeof this.options.onViewChange === 'function'
			? this.options.onViewChange
			: null;
		this.onSwipeStart = typeof this.options.onSwipeStart === 'function'
			? this.options.onSwipeStart
			: null;
		this.onSlideImageChanged = typeof this.options.onSlideImageChanged === 'function'
			? this.options.onSlideImageChanged
			: null;

		this.dedicatedGallery = null;
		this.isDedicated = false;
		this.viewSvgMarkup = new Map();

		this.overlayElem = null;
		this.svgHolder = null;
		this.attachedImage = null;
		this.currentSvgMarkup = '';
		this.lastWindowWidth = typeof window !== 'undefined' ? window.innerWidth : 0;

		this._legacyController = null;
		this._legacyLoadPromise = null;

		this.init();
	}

	/**
	 * Safely scope DOM IDs and internal fragment references by delegating to SvgRenderer.scopeMarkupIds.
	 *
	 * @param {string} svgMarkup Raw SVG markup string.
	 * @param {string} prefix Prefix to prepend to element IDs and local fragment references.
	 * @return {string}
	 */
	static scopeSvgMarkup(svgMarkup, prefix) {
		return SvgRenderer.scopeMarkupIds(svgMarkup || '', prefix);
	}

	/**
	 * Build or scope lightbox SVG markup with a dedicated DOM ID prefix (`fs-`, `pswp-`, or `mfp-`).
	 *
	 * @param {string} prefix Prefix to apply to lightbox SVG IDs.
	 * @param {string} viewId Optional view ID for multi-view galleries.
	 * @return {string}
	 */
	getLightboxSvgMarkup(prefix, viewId = '') {
		const targetViewId = viewId || this.getActiveViewId();
		if (this.renderSvgWithPrefix) {
			const rendered = this.renderSvgWithPrefix(prefix, targetViewId);
			if (typeof rendered === 'string') {
				return rendered;
			}
		}
		const baseMarkup = (targetViewId && this.viewSvgMarkup.has(targetViewId))
			? this.viewSvgMarkup.get(targetViewId)
			: this.currentSvgMarkup;
		return GalleryStage.scopeSvgMarkup(baseMarkup, prefix);
	}

	/**
	 * Initialize DedicatedGallery when present (or mountable via fallback), otherwise
	 * dynamically import the legacy single-overlay WooCommerce gallery module.
	 */
	init() {
		this.dedicatedGallery = new DedicatedGallery(this.config, {
			onViewChange: (viewId, slideIdx, slideMeta) => {
				if (this.onViewChange) {
					this.onViewChange(viewId, slideIdx, slideMeta);
				}
			},
			onSwipeStart: (neighborViewIds) => {
				if (this.onSwipeStart) {
					this.onSwipeStart(neighborViewIds);
				}
			},
			onSlideImageChanged: (viewId, imgEl) => {
				if (this.onSlideImageChanged) {
					this.onSlideImageChanged(viewId, imgEl);
				}
			},
			getFullscreenSvg: (viewId) => this.getLightboxSvgMarkup('fs-', viewId),
			patchSvgFn: (existingSvg, markup) => this.patchSvgInPlace(existingSvg, markup),
		});

		if (this.dedicatedGallery && this.dedicatedGallery.isMounted) {
			this.isDedicated = true;
			return;
		}

		this.loadLegacyStage();
	}

	/**
	 * Dynamically import `legacy-gallery-stage.js` strictly when DedicatedGallery is not mounted.
	 *
	 * @return {Promise<Object|null>}
	 */
	loadLegacyStage() {
		if (this._legacyController) {
			return Promise.resolve(this._legacyController);
		}
		if (!this._legacyLoadPromise) {
			this._legacyLoadPromise = import('./legacy-gallery-stage.js?v=2.6.0')
				.then((mod) => {
					const LegacyCls = mod && mod.LegacyGalleryStage;
					if (!LegacyCls) return null;
					this._legacyController = new LegacyCls(this);
					this._legacyController.init();
					return this._legacyController;
				})
				.catch((err) => {
					this._legacyLoadPromise = null;
					console.error('[MrTee GalleryStage] Failed to load legacy gallery fallback:', err);
					return null;
				});
		}
		return this._legacyLoadPromise;
	}

	/**
	 * Focus a garment side (e.g. 'front', 'back') in the dedicated multi-view gallery.
	 *
	 * @param {string} sideId Target print side identifier.
	 */
	focusSide(sideId) {
		if (this.isDedicated && this.dedicatedGallery) {
			this.dedicatedGallery.focusSide(sideId);
		}
	}

	/**
	 * Return currently active view ID (empty string in legacy single-overlay mode).
	 *
	 * @return {string}
	 */
	getActiveViewId() {
		if (this.isDedicated && this.dedicatedGallery) {
			return this.dedicatedGallery.getActiveViewId();
		}
		return '';
	}

	/**
	 * Return active slide metadata object when running in dedicated gallery mode.
	 *
	 * @return {Object|null}
	 */
	getActiveSlide() {
		if (this.isDedicated && this.dedicatedGallery) {
			return this.dedicatedGallery.getActiveSlide();
		}
		return null;
	}

	/**
	 * Return customizable slide metadata list when running in dedicated gallery mode.
	 *
	 * @return {Array<Object>}
	 */
	getCustomizableViews() {
		if (this.isDedicated && this.dedicatedGallery) {
			return this.dedicatedGallery.getCustomizableViews();
		}
		return [];
	}

	/**
	 * Return the garment `<img>` element associated with `viewId`.
	 *
	 * @param {string} viewId Optional view ID.
	 * @return {HTMLImageElement|null}
	 */
	getViewImageElement(viewId = '') {
		if (this.isDedicated && this.dedicatedGallery) {
			return this.dedicatedGallery.getViewImageElement(viewId);
		}
		if (this._legacyController) {
			return this._legacyController.getViewImageElement();
		}
		if (this.attachedImage && this.attachedImage.isConnected) {
			return this.attachedImage;
		}
		return document.querySelector('.woocommerce-product-gallery img.wp-post-image, .woocommerce-product-gallery img:not(.emoji):not(.zoomImg)');
	}

	/**
	 * Return the `.mr-tee-live-svg-container` element associated with `viewId`.
	 *
	 * @param {string} viewId Optional view ID.
	 * @return {HTMLElement|null}
	 */
	getViewOverlayContainer(viewId = '') {
		if (this.isDedicated && this.dedicatedGallery) {
			return this.dedicatedGallery.getViewOverlayContainer(viewId);
		}
		return this.svgHolder;
	}

	/* Legacy method delegates (preserve 100% public API compatibility) */

	isVisibleElement(el) {
		if (this._legacyController) return this._legacyController.isVisibleElement(el);
		if (!el || !el.isConnected) return false;
		const style = window.getComputedStyle(el);
		return Boolean(style && style.display !== 'none' && style.visibility !== 'hidden');
	}

	findMainSlideContainer() {
		if (this._legacyController) return this._legacyController.findMainSlideContainer();
		return document.querySelector('.woocommerce-product-gallery .flex-active-slide, .woocommerce-product-gallery');
	}

	findMainGalleryImage(container) {
		if (this._legacyController) return this._legacyController.findMainGalleryImage(container);
		return container ? container.querySelector('img.wp-post-image, img:not(.emoji):not(.zoomImg)') : null;
	}

	suppressZoom() {
		if (this._legacyController) {
			this._legacyController.suppressZoom();
		} else if (!this.isDedicated) {
			this.loadLegacyStage();
		}
	}

	mountOverlay() {
		if (this._legacyController) {
			this._legacyController.mountOverlay();
		} else if (!this.isDedicated) {
			this.loadLegacyStage();
		}
	}

	applyAspectRatioLock(container) {
		if (this._legacyController) {
			this._legacyController.applyAspectRatioLock(container);
		}
	}

	recalibrateOverlayPosition(container, overlay) {
		if (this._legacyController) {
			this._legacyController.recalibrateOverlayPosition(container, overlay);
		}
	}

	initPhotoSwipeSync() {
		if (this._legacyController) {
			this._legacyController.initPhotoSwipeSync();
		} else if (!this.isDedicated) {
			this.loadLegacyStage();
		}
	}

	bindGalleryEvents() {
		if (this._legacyController) {
			this._legacyController.bindGalleryEvents();
		} else if (!this.isDedicated) {
			this.loadLegacyStage();
		}
	}

	/**
	 * Update the live SVG markup for a specific preview view using keyed in-place DOM reconciliation,
	 * and synchronize the built-in fullscreen lightbox if currently open on that view.
	 *
	 * @param {string} viewId Target view ID.
	 * @param {string} svgMarkup Raw SVG markup string.
	 */
	updateViewSvg(viewId, svgMarkup) {
		if (!this.isDedicated || !this.dedicatedGallery) {
			this.updateSvg(svgMarkup);
			return;
		}

		const targetViewId = viewId || this.getActiveViewId();
		if (!targetViewId) return;

		const markup = svgMarkup || '';
		this.viewSvgMarkup.set(targetViewId, markup);
		if (targetViewId === this.getActiveViewId()) {
			this.currentSvgMarkup = markup;
		}

		const holder = this.dedicatedGallery.getViewOverlayContainer(targetViewId);
		if (holder) {
			const existingSvg = holder.querySelector('svg.mr-tee-live-svg') || holder.querySelector('svg');
			if (!existingSvg || !markup) {
				holder.innerHTML = markup;
				const mountedDefs = holder.querySelector('defs.mr-tee-svg-defs, defs');
				const mountedSvg = holder.querySelector('svg.mr-tee-live-svg') || holder.querySelector('svg');
				if (mountedSvg && mountedDefs) {
					mountedSvg._lastDefsHtml = mountedDefs.innerHTML;
				}
			} else {
				this.patchSvgInPlace(existingSvg, markup);
			}
		}

		// Sync built-in fullscreen lightbox if open on this view (skip if goToIndex is already refreshing it)
		if (
			this.dedicatedGallery.isLightboxOpen &&
			!this.dedicatedGallery._suppressLightboxSvgSync &&
			targetViewId === this.getActiveViewId()
		) {
			const fsMarkup = this.getLightboxSvgMarkup('fs-', targetViewId);
			this.dedicatedGallery.syncFullscreenSvg(fsMarkup, (existingSvg, nextMarkup) => {
				this.patchSvgInPlace(existingSvg, nextMarkup);
			});
		}
	}

	/**
	 * Update the live SVG preview content immediately with zero lag using keyed in-place DOM reconciliation.
	 *
	 * @param {string} svgMarkup Raw SVG markup string.
	 */
	updateSvg(svgMarkup) {
		if (this.isDedicated && this.dedicatedGallery) {
			this.updateViewSvg(this.getActiveViewId(), svgMarkup);
			return;
		}

		this.currentSvgMarkup = svgMarkup || '';
		if (this._legacyController) {
			this._legacyController.updateSvg(this.currentSvgMarkup);
		} else {
			this.loadLegacyStage();
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
			if (doc.querySelector('parsererror')) {
				existingSvg.parentNode.innerHTML = svgMarkup;
				return;
			}
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
	 * in-place so existing .mrtee-opentype-svg-text glyph <image> nodes are never destroyed,
	 * and cache `_mrTeeMarkup` on leaf children so unchanged siblings skip expensive
	 * `exChild.outerHTML` serialization & DOM replacement on every keystroke.
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
				} else {
					const nwMarkup = nwChild.outerHTML;
					const exMarkup = exChild._mrTeeMarkup !== undefined ? exChild._mrTeeMarkup : exChild.outerHTML;
					if (exMarkup !== nwMarkup) {
						const imported = document.importNode(nwChild, true);
						imported._mrTeeMarkup = nwMarkup;
						existingContainer.replaceChild(imported, exChild);
					} else {
						exChild._mrTeeMarkup = nwMarkup;
					}
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
						const nwMarkup = nwChild.outerHTML;
						const exMarkup = matchedEx._mrTeeMarkup !== undefined ? matchedEx._mrTeeMarkup : matchedEx.outerHTML;
						if (exMarkup !== nwMarkup) {
							const imported = document.importNode(nwChild, true);
							imported._mrTeeMarkup = nwMarkup;
							nextNodes.push(imported);
						} else {
							matchedEx._mrTeeMarkup = nwMarkup;
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
				const imported = document.importNode(nwChild, true);
				if (!nwChild.classList.contains('mrtee-opentype-svg-text') && !nwChild.classList.contains('mrtee-group')) {
					imported._mrTeeMarkup = nwChild.outerHTML;
				}
				nextNodes.push(imported);
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
				// 1. Check single-character insertion at index `i`: next new glyph matches current existing glyph
				const nextNw = newGlyphs[i + 1];
				const exKey = exGlyph.getAttribute('data-glyph-key');
				if (nextNw && exKey && nextNw.getAttribute('data-glyph-key') === exKey) {
					existingGroup.insertBefore(document.importNode(nwGlyph, true), exGlyph);
					continue;
				}
				// 2. Check single-character deletion at index `i`: next existing glyph matches current new glyph
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
