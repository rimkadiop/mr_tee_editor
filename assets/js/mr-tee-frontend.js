/**
 * Mr. Tee Editor - Frontend Modular Orchestrator (v2.5.9)
 *
 * Bootstraps the client-side personalization application by interconnecting
 * StateStore, SceneGraphEngine, SvgRenderer, GalleryStage, FormController, and lazy-loaded PrintExporter
 * with dirty-tracked lazy multi-view SVG rendering at 60 FPS.
 *
 * @package MrTeeEditor\Assets
 */

import { StateStore } from './modules/state-store.js?v=2.5.9';
import { SceneGraphEngine } from './modules/scene-graph-engine.js?v=2.5.9';
import { SvgRenderer } from './modules/svg-renderer.js?v=2.5.9';
import { GalleryStage } from './modules/gallery-stage.js?v=2.5.9';
import { FormController } from './modules/form-controller.js?v=2.5.9';

window.mrTeeSvgRenderer = SvgRenderer;
window.mrTeeSceneGraphEngine = SceneGraphEngine;

class MrTeeFrontendApp {
	constructor() {
		this.data = window.mrTeeProductData || {};
		this.config = this.data.config || {};

		this.store = null;
		this.stage = null;
		this.formController = null;
		this.currentLayers = [];
		this.currentZoom = { scale: 1, translateX: 0, translateY: 0 };

		this.dirtyViews = new Set();
		this.renderedViews = new Set();
		this._pendingFontUpgrades = new Set();
		this._siblingSyncTimer = null;
		this._printExporterClass = null;

		this.init();
	}

	/**
	 * Dynamically import PrintExporter strictly on demand (Add-to-Cart) to keep initial page load lean.
	 *
	 * @return {Promise<Function>}
	 */
	async loadPrintExporter() {
		if (this._printExporterClass) return this._printExporterClass;
		const mod = await import('./modules/print-exporter.js?v=2.5.9');
		this._printExporterClass = mod.PrintExporter;
		window.mrTeePrintExporter = mod.PrintExporter;
		return this._printExporterClass;
	}

	init() {
		if (!document.getElementById('mr-tee-editor-container')) {
			return;
		}

		if (document.body) {
			document.body.classList.add('has-mr-tee-editor');
			if (Boolean(this.config.lock_aspect_ratio) || this.config.product_type === 'flat_object') {
				document.body.classList.add('mr-tee-locked-aspect-ratio');
			}
		}

		// Ensure form action protocol matches current protocol to prevent 503 SSL redirection errors
		const cartForm = document.querySelector('form.cart');
		if (cartForm && location.protocol === 'http:' && cartForm.action && cartForm.action.startsWith('https:')) {
			cartForm.action = cartForm.action.replace('https:', 'http:');
		}

		// 1. Initialize State Store with localized productId
		this.store = new StateStore(this.config, this.data.productId || 0);

		// 2. Initialize Gallery Stage (Dedicated multi-view gallery with legacy fallback)
		this.stage = new GalleryStage(this.config, {
			renderSvgWithPrefix: (idPrefix, viewId) => this.renderViewSvg(viewId || this.stage.getActiveViewId(), { idPrefix }),
			onViewChange: (viewId, slideIdx, slideMeta) => {
				this.renderActiveViewIfNeeded(viewId, false);
				if (this.formController && typeof this.formController.setActiveSide === 'function') {
					const meta = slideMeta || this.getViewMeta(viewId);
					this.formController.setActiveSide(meta && meta.sideId ? meta.sideId : '');
				}
			},
			onSwipeStart: (neighborViewIds) => {
				if (Array.isArray(neighborViewIds)) {
					for (const vid of neighborViewIds) {
						this.renderActiveViewIfNeeded(vid, false);
					}
				}
			},
			onSlideImageChanged: (viewId) => {
				if (viewId) {
					this.dirtyViews.add(viewId);
					if (viewId === this.stage.getActiveViewId()) {
						this.renderActiveViewIfNeeded(viewId, true);
					}
				} else {
					this.renderSceneGraph();
				}
			},
		});

		// 3. Initialize Form Controller with side-aware auto-swipe callback
		this.formController = new FormController(
			this.store,
			this.config,
			(onDone) => this.handleExport(onDone),
			{
				onFieldSideFocus: (sideId) => {
					if (this.stage) {
						this.stage.focusSide(sideId);
					}
				},
			}
		);

		if (this.stage && this.stage.isDedicated && typeof this.formController.setActiveSide === 'function') {
			const initSlide = this.stage.getActiveSlide();
			if (initSlide && initSlide.sideId) {
				this.formController.setActiveSide(initSlide.sideId);
			}
		}

		// 4. Subscribe to Store State Changes (RAF throttled for buttery-smooth 60 FPS typing)
		this._rafRenderId = null;
		this.store.subscribe(() => {
			if (this._rafRenderId) {
				cancelAnimationFrame(this._rafRenderId);
			}
			this._rafRenderId = requestAnimationFrame(() => {
				this._rafRenderId = null;
				this.renderSceneGraph();
			});
		});

		// Initial Render (renders ONLY the active visible view immediately; sibling views sync quietly after idle)
		this.renderSceneGraph();

		// Re-sync SVG aspect ratio once legacy gallery image finishes loading if running in legacy mode
		if (!this.stage.isDedicated) {
			const galleryImg = this.stage.getViewImageElement();
			if (galleryImg && (!galleryImg.complete || galleryImg.naturalWidth <= 2)) {
				galleryImg.addEventListener('load', () => {
					this.renderSceneGraph();
				}, { once: true });
			}
		}

		// Re-measure standard TTF text widths once custom @font-face fonts finish loading
		if (typeof document !== 'undefined' && document.fonts) {
			const onFontsLoaded = () => {
				if (SvgRenderer._textWidthCache) {
					SvgRenderer._textWidthCache.clear();
				}
				this.markAllViewsDirty();
				if (this.stage && this.stage.isDedicated) {
					this.renderActiveViewIfNeeded(this.stage.getActiveViewId(), true);
				} else {
					this.renderSceneGraph();
				}
			};
			if (document.fonts.ready && typeof document.fonts.ready.then === 'function') {
				document.fonts.ready.then(onFontsLoaded).catch(() => {});
			}
			if (typeof document.fonts.addEventListener === 'function') {
				document.fonts.addEventListener('loadingdone', onFontsLoaded);
			}
		}

		// 5. Stage 1 (Critical Initial Paint): Immediately decode ONLY Slide 0's default_text glyphs
		//    so the visible product preview renders with 0ms delay and zero bandwidth contention.
		this.prewarmDefaultTextGlyphs();

		// 6. Post-Paint Off-Screen Default Text (~550ms): Quietly decode off-screen sides' default_text
		//    glyphs (e.g. Verso ~8 WebP letters) so tapping Verso renders 100% crisp 3D text instantly.
		setTimeout(() => this.prewarmActiveLineFonts(), 550);

		// 7. Stage 2 (On First Interaction): Lazy-fetch secondary unselected OpenType-SVG manifests.
		this.scheduleStageTwoWarmup();
	}

	/**
	 * Resolve slide/view metadata for a given viewId from the dedicated gallery or product config.
	 *
	 * @param {string} viewId Target view ID.
	 * @return {Object|null}
	 */
	getViewMeta(viewId) {
		if (!viewId) return null;
		const key = String(viewId);

		if (this.stage && this.stage.isDedicated && this.stage.dedicatedGallery) {
			const slide = this.stage.dedicatedGallery.slidesByViewId.get(key);
			if (slide) return slide;
		}

		const dataSlides = Array.isArray(this.data.gallerySlides) ? this.data.gallerySlides : [];
		const matchedDataSlide = dataSlides.find(s => s && String(s.view_id) === key);
		if (matchedDataSlide) {
			return {
				viewId: String(matchedDataSlide.view_id),
				sideId: matchedDataSlide.side_id || 'front',
				viewRole: matchedDataSlide.view_role || 'full',
				blendMode: matchedDataSlide.blend_mode || 'normal',
				customizable: Boolean(matchedDataSlide.is_customizable),
				aspectRatio: parseFloat(matchedDataSlide.aspect_ratio) || 0,
			};
		}

		const cfgViews = Array.isArray(this.config.preview_views) ? this.config.preview_views : [];
		const matchedCfg = cfgViews.find(v => v && String(v.id || v.view_id) === key);
		if (matchedCfg) {
			return {
				viewId: String(matchedCfg.id || matchedCfg.view_id),
				sideId: matchedCfg.side_id || 'front',
				viewRole: matchedCfg.view_role || 'full',
				blendMode: matchedCfg.blend_mode || 'normal',
				customizable: true,
				aspectRatio: parseFloat(matchedCfg.aspect_ratio) || 0,
			};
		}

		return null;
	}

	/**
	 * Mark all customizable views dirty so off-screen slides render lazily when switched into view.
	 */
	markAllViewsDirty() {
		if (this.stage && this.stage.isDedicated) {
			const views = this.stage.getCustomizableViews();
			for (const v of views) {
				if (v && v.viewId) {
					this.dirtyViews.add(v.viewId);
				}
			}
			return;
		}
		const cfgViews = Array.isArray(this.config.preview_views) ? this.config.preview_views : [];
		for (const v of cfgViews) {
			const vid = v && (v.id || v.view_id);
			if (vid) this.dirtyViews.add(String(vid));
		}
	}

	/**
	 * Resolve print side definition for a given sideId from product config.
	 *
	 * @param {string} sideId Print side identifier.
	 * @return {Object|null}
	 */
	getSideDef(sideId) {
		const sides = Array.isArray(this.config.print_sides) ? this.config.print_sides : [];
		if (sides.length === 0) return null;
		if (!sideId) return sides[0] || null;
		const key = String(sideId);
		return sides.find(s => s && String(s.id) === key) || sides[0] || null;
	}

	/**
	 * Read live natural aspect ratio for a specific view's garment image (ignoring <= 2px placeholders).
	 * Never caches a stale ratio that could be poisoned by unloaded lazy images or variation swaps.
	 *
	 * @param {string} viewId Target view ID.
	 * @return {number}
	 */
	computeAspectRatioForView(viewId = '') {
		const meta = this.getViewMeta(viewId);
		const sideDef = this.getSideDef(meta && meta.sideId);
		const isLocked = (sideDef && sideDef.lock_aspect_ratio !== undefined)
			? (Boolean(sideDef.lock_aspect_ratio) || sideDef.product_type === 'flat_object')
			: (Boolean(this.config.lock_aspect_ratio) || this.config.product_type === 'flat_object');
		const realW = (sideDef && parseFloat(sideDef.print_real_width)) || parseFloat(this.config.print_real_width) || 0;
		const realH = (sideDef && parseFloat(sideDef.print_real_height)) || parseFloat(this.config.print_real_height) || 0;

		if (isLocked && realW > 0 && realH > 0) {
			return realW / realH;
		}
		if (isLocked && parseFloat(this.config.print_aspect_ratio) > 0) {
			return parseFloat(this.config.print_aspect_ratio);
		}

		const img = (this.stage ? this.stage.getViewImageElement(viewId) : null)
			|| document.querySelector('#mr-tee-gallery-root .mr-tee-slide.is-active img.mr-tee-slide-img, .woocommerce-product-gallery img.wp-post-image');
		if (img) {
			if (img.complete && img.naturalWidth > 2 && img.naturalHeight > 2) {
				return img.naturalWidth / img.naturalHeight;
			}
			const attrW = parseInt(img.getAttribute('width'), 10);
			const attrH = parseInt(img.getAttribute('height'), 10);
			if (attrW > 2 && attrH > 2) {
				return attrW / attrH;
			}
			if (img.offsetWidth > 2 && img.offsetHeight > 2) {
				return img.offsetWidth / img.offsetHeight;
			}
		}

		if (meta && meta.aspectRatio > 0) {
			return meta.aspectRatio;
		}
		if (realW > 0 && realH > 0) {
			return realW / realH;
		}
		return 1.0;
	}

	/**
	 * Merge current reactive store state into declarative scene-graph layers & zoom transform.
	 */
	computeMergedScene() {
		const state = this.store.state;

		// 1. Merge references into declarative scene-graph layers
		let mergedLayers = SceneGraphEngine.getLayersMergedWithRefs(state.layers, state.references);

		// 2. Apply 2-row dynamic centering (Ligne 1: 1..3, Ligne 2: 4..6)
		mergedLayers = SceneGraphEngine.applyRowCentering(mergedLayers, state.activeCount);

		// 3. Compute auto-zoom and vertical auto-centering (skip bbox work when per-line boxes are active)
		const hasPerLineBoxes = Array.isArray(this.config.text_lines) && this.config.text_lines.some(
			l => (l.box_left !== undefined && l.box_width !== undefined) || (l.placements && Object.keys(l.placements).length > 0)
		);
		const zoom = hasPerLineBoxes
			? { scale: 1.0, translateX: 0.0, translateY: 0.0 }
			: SceneGraphEngine.getAreaTransformValuesZoom(
				SceneGraphEngine.getAllLayersBBox(mergedLayers),
				state.area && state.area.transform
			);

		this.currentLayers = mergedLayers;
		this.currentZoom = zoom;
		this._hasPerLineBoxes = hasPerLineBoxes;
	}

	/**
	 * Ensure any OpenType-SVG fonts used by layers on `sideId` are loaded in memory,
	 * and trigger an automatic view re-render the moment a lazy manifest finishes loading.
	 *
	 * @param {string} sideId Target print side identifier (empty string checks all layers).
	 */
	ensureMissingSvgFontsForSide(sideId = '') {
		const fonts = (this.data && this.data.fonts) || {};
		const targetLayers = sideId
			? SvgRenderer.filterLayersBySide(this.currentLayers || [], sideId)
			: (this.currentLayers || []);
		if (!Array.isArray(targetLayers) || targetLayers.length === 0) return;

		const inspectNodes = (nodes) => {
			if (!Array.isArray(nodes)) return;
			for (const node of nodes) {
				if (!node || node.hide) continue;
				if (node.type === 'TEXT') {
					const fId = node.fontId || '';
					const fDef = fId ? fonts[fId] : null;
					const isOt = Boolean(
						node.fontType === 'opentype_svg'
						|| (fDef && (fDef.font_type === 'opentype_svg' || fDef.color_svg))
					);
					if (isOt && fId && !SvgRenderer.getSvgFontData(fId)) {
						const upgradeKey = String(fId).toLowerCase().replace(/[\s\-]+/g, '_');
						if (!this._pendingFontUpgrades.has(upgradeKey)) {
							this._pendingFontUpgrades.add(upgradeKey);
							const rawText = String(node.value || '');
							const isUpper = (node.casing === 'UPPER') || Boolean(fDef && fDef.uppercase);
							const warmText = isUpper ? rawText.toUpperCase() : rawText;
							SvgRenderer.ensureSvgFontLoaded(fId, fDef, {
								prewarmGlyphs: false,
								priorityText: warmText,
							})
								.then((loaded) => {
									this._pendingFontUpgrades.delete(upgradeKey);
									if (!loaded) return;
									this.markAllViewsDirty();
									if (this.stage && this.stage.isDedicated) {
										this.renderActiveViewIfNeeded(this.stage.getActiveViewId(), true);
										this.scheduleSiblingViewsSync();
									} else {
										this.renderSceneGraph();
									}
								})
								.catch(() => {
									this._pendingFontUpgrades.delete(upgradeKey);
								});
						}
					}
				}
				if (Array.isArray(node.items)) {
					inspectNodes(node.items);
				}
			}
		};

		inspectNodes(targetLayers);
	}

	/**
	 * Render SVG markup for any specific preview view ID (also exposed on `window.mrTeeApp.renderViewSvg`
	 * so `PrintExporter` can render every customizable view cleanly during Add-to-Cart).
	 *
	 * @param {string} viewId Target view ID (or empty string in legacy single-overlay mode).
	 * @param {Object} extraOpts Optional overrides merged into SvgRenderer options.
	 * @return {string}
	 */
	renderViewSvg(viewId = '', extraOpts = {}) {
		if (!this.currentLayers || this.currentLayers.length === 0) {
			this.computeMergedScene();
		}

		const meta = viewId ? this.getViewMeta(viewId) : null;
		if (meta && meta.customizable === false) {
			return '';
		}

		const activeSideId = (extraOpts && extraOpts.sideId) || (meta && meta.sideId) || '';
		this.ensureMissingSvgFontsForSide(activeSideId);

		const sideDef = this.getSideDef(meta && meta.sideId);
		const ratio = (extraOpts && extraOpts.aspectRatio > 0)
			? extraOpts.aspectRatio
			: this.computeAspectRatioForView(viewId);
		const realW = (sideDef && parseFloat(sideDef.print_real_width)) || parseFloat(this.config.print_real_width) || 0;
		const realH = (sideDef && parseFloat(sideDef.print_real_height)) || parseFloat(this.config.print_real_height) || 0;

		let autoIdPrefix = '';
		if (viewId && !(extraOpts && extraOpts.idPrefix)) {
			const safeViewId = String(viewId).replace(/[^a-zA-Z0-9_-]/g, '');
			if (safeViewId) {
				autoIdPrefix = `v-${safeViewId}-`;
			}
		}

		const renderOptions = {
			aspectRatio: ratio,
			realWidth: realW,
			realHeight: realH,
			unit: (sideDef && sideDef.print_unit) || this.config.print_unit || 'cm',
			dpi: (sideDef && parseInt(sideDef.dpi, 10)) || this.config.dpi || 300,
			hasPerLineBoxes: Boolean(this._hasPerLineBoxes),
			viewId: viewId || undefined,
			sideId: (meta && meta.sideId) ? meta.sideId : undefined,
			...(autoIdPrefix ? { idPrefix: autoIdPrefix } : {}),
			...(extraOpts || {}),
		};

		return SvgRenderer.renderToString(this.currentLayers, this.currentZoom, renderOptions);
	}

	/**
	 * Lazily render a view only if it is dirty or has never been rendered yet.
	 *
	 * @param {string} viewId Target view ID (defaults to active visible slide).
	 * @param {boolean} force Force re-render even if clean.
	 */
	renderActiveViewIfNeeded(viewId = '', force = false) {
		if (!this.stage || !this.stage.isDedicated) return;

		const targetViewId = viewId || this.stage.getActiveViewId();
		if (!targetViewId) return;

		const meta = this.getViewMeta(targetViewId);
		if (!meta || !meta.customizable) return;

		if (force || this.dirtyViews.has(targetViewId) || !this.renderedViews.has(targetViewId)) {
			const svgMarkup = this.renderViewSvg(targetViewId);
			this.stage.updateViewSvg(targetViewId, svgMarkup);
			this.dirtyViews.delete(targetViewId);
			this.renderedViews.add(targetViewId);
		}
	}

	/**
	 * Stage 1: Immediately decode only the glyph bitmaps used by Slide 0's initial default_text.
	 */
	prewarmDefaultTextGlyphs() {
		const preloaded = (this.data && this.data.svgFonts) || {};
		const fonts = (this.data && this.data.fonts) || {};
		const textLines = this.config.text_lines || [];
		const activeViewId = this.stage ? this.stage.getActiveViewId() : '';
		const activeMeta = activeViewId ? this.getViewMeta(activeViewId) : null;
		const activeSideId = (activeMeta && activeMeta.sideId) ? String(activeMeta.sideId) : '';

		for (const line of textLines) {
			if (line.field_type === 'image') continue;
			if (activeSideId && String(line.side_id || 'front') !== activeSideId) continue;
			const activeFontId = line.font || (Array.isArray(line.allowed_fonts) && line.allowed_fonts[0]);
			let defaultText = line.default_text || '';
			if (!activeFontId || !defaultText) continue;

			const fDef = fonts[activeFontId];
			const isUpper = (line.casing === 'UPPER') || Boolean(fDef && fDef.uppercase);
			if (isUpper) {
				defaultText = defaultText.toUpperCase();
			}

			const sData = preloaded[activeFontId] || SvgRenderer.getSvgFontData(activeFontId);
			if (sData) {
				SvgRenderer.prewarmTextGlyphs(sData, defaultText);
			}
		}
	}

	/**
	 * Schedule Stage 2 manifest & default-text glyph warmup upon first user interaction with
	 * the editor/gallery so initial page load downloads zero unused off-screen assets, and
	 * gallery swiping only fetches lightweight ~4 KB manifests + off-screen default_text glyphs
	 * (full A-Z/0-9 alphabet prewarming occurs on input focus in FormController).
	 */
	scheduleStageTwoWarmup() {
		let warmed = false;
		const triggerWarmup = () => {
			if (warmed) return;
			warmed = true;
			targets.forEach((el) => {
				if (!el) return;
				events.forEach((evt) => el.removeEventListener(evt, triggerWarmup));
			});
			this.prewarmActiveLineFonts();
			setTimeout(() => this.prefetchAllowedSvgFonts(), 300);
		};

		const events = ['pointerdown', 'touchstart', 'focusin', 'mouseenter', 'keydown'];
		const targets = [
			document.getElementById('mr-tee-editor-container'),
			document.getElementById('mr-tee-gallery-root'),
		];
		targets.forEach((el) => {
			if (!el) return;
			events.forEach((evt) => el.addEventListener(evt, triggerWarmup, { once: true, passive: true }));
		});
	}

	/**
	 * Stage 2: Load off-screen active OpenType-SVG manifests and pre-decode ONLY their default_text
	 * glyphs (saving ~70–100 unused alphabet image requests when browsing views).
	 */
	prewarmActiveLineFonts() {
		const fonts = (this.data && this.data.fonts) || {};
		const textLines = this.config.text_lines || [];
		for (const line of textLines) {
			if (line.field_type === 'image') continue;
			const activeFontId = line.font || (Array.isArray(line.allowed_fonts) && line.allowed_fonts[0]);
			if (!activeFontId) continue;
			const fDef = fonts[activeFontId];
			if (fDef && (fDef.font_type === 'opentype_svg' || fDef.color_svg)) {
				let defaultText = line.default_text || '';
				const isUpper = (line.casing === 'UPPER') || Boolean(fDef && fDef.uppercase);
				if (isUpper) {
					defaultText = defaultText.toUpperCase();
				}
				const alreadyLoaded = Boolean(SvgRenderer.getSvgFontData(activeFontId));
				SvgRenderer.ensureSvgFontLoaded(activeFontId, fDef, {
					prewarmGlyphs: false,
					priorityText: defaultText,
				})
					.then((loaded) => {
						if (!alreadyLoaded && loaded) {
							this.markAllViewsDirty();
							if (this.stage && this.stage.isDedicated) {
								this.renderActiveViewIfNeeded(this.stage.getActiveViewId(), true);
								this.scheduleSiblingViewsSync();
							}
						}
					})
					.catch(() => {});
			}
		}
	}

	/**
	 * Debounced (~180ms) background sync of dirty customizable sibling views so adjacent
	 * slides (e.g. Full T-Shirt view paired with Cut-Area Closeup) are already fresh before swiping,
	 * while keeping active keystroke rendering strictly 1-view at 60 FPS.
	 */
	scheduleSiblingViewsSync() {
		if (!this.stage || !this.stage.isDedicated) return;
		if (this._siblingSyncTimer) {
			clearTimeout(this._siblingSyncTimer);
		}
		this._siblingSyncTimer = setTimeout(() => {
			this._siblingSyncTimer = null;
			if (!this.stage || !this.stage.isDedicated) return;
			const activeSlide = this.stage.getActiveSlide();
			const activeViewId = activeSlide ? activeSlide.viewId : '';
			const activeSideId = activeSlide ? activeSlide.sideId : '';
			const customViews = this.stage.getCustomizableViews();
			for (const v of customViews) {
				if (!v || !v.viewId || v.viewId === activeViewId) continue;
				const isSameSide = Boolean(activeSideId && v.sideId === activeSideId);
				const isImmediateNeighbor = activeSlide && Math.abs(v.index - activeSlide.index) === 1;
				if ((isSameSide || isImmediateNeighbor) && (this.dirtyViews.has(v.viewId) || !this.renderedViews.has(v.viewId))) {
					this.renderActiveViewIfNeeded(v.viewId, false);
				}
			}
		}, 180);
	}

	/**
	 * Compute scene-graph layout, mark customizable views dirty, and immediately update
	 * ONLY the currently visible view (60 FPS lazy multi-view rendering), scheduling
	 * a debounced background sync for sibling/neighbor slides.
	 */
	renderSceneGraph() {
		this.computeMergedScene();

		if (this.stage && this.stage.isDedicated) {
			this.markAllViewsDirty();
			this.renderActiveViewIfNeeded(this.stage.getActiveViewId(), true);
			this.scheduleSiblingViewsSync();
			return;
		}

		const svgMarkup = this.renderViewSvg('');
		if (this.stage) {
			this.stage.updateSvg(svgMarkup);
		}
	}

	/**
	 * Idle-prefetch secondary allowed OpenType-SVG font manifests (~4 KB JSON only, without
	 * downloading glyph bitmaps) so tapping any font pill is instant.
	 */
	prefetchAllowedSvgFonts() {
		const saveData = Boolean(typeof navigator !== 'undefined' && navigator.connection && navigator.connection.saveData);
		if (saveData) return;

		const fonts = (this.data && this.data.fonts) || {};
		const textLines = this.config.text_lines || [];
		const toPrefetch = new Set();

		for (const line of textLines) {
			if (line.field_type === 'image') continue;
			const allowed = Array.isArray(line.allowed_fonts) ? line.allowed_fonts : Object.keys(fonts);
			for (const fId of allowed) {
				const fDef = fonts[fId];
				if (fDef && (fDef.font_type === 'opentype_svg' || fDef.color_svg)) {
					toPrefetch.add(fId);
				}
			}
		}

		for (const fId of toPrefetch) {
			const fDef = fonts[fId];
			SvgRenderer.ensureSvgFontLoaded(fId, fDef, { prewarmGlyphs: false }).catch(() => {});
		}
	}

	/**
	 * Handle Add-to-Cart export request: prepares lightweight composite thumbnail preview and payload.
	 *
	 * @param {Function} onDone Callback invoked after inputs are populated with (ok, err).
	 */
	async handleExport(onDone) {
		let ok = false;
		let exportErr = null;
		try {
			if (this._rafRenderId) {
				cancelAnimationFrame(this._rafRenderId);
				this._rafRenderId = null;
			}
			this.renderSceneGraph();

			const PrintExporter = await this.loadPrintExporter();
			await PrintExporter.prepareExport(
				this.currentLayers,
				this.currentZoom,
				this.store,
				this.config
			);
			ok = true;
		} catch (err) {
			exportErr = err;
			console.error('[MrTee Editor] Export preparation failed: ' + (err && err.stack ? err.stack : err));
		}

		if (typeof onDone === 'function') {
			onDone(ok, exportErr);
		}
	}
}

// Bootstrap on DOM ready
if (document.readyState === 'loading') {
	document.addEventListener('DOMContentLoaded', () => {
		window.mrTeeApp = new MrTeeFrontendApp();
	});
} else {
	window.mrTeeApp = new MrTeeFrontendApp();
}
