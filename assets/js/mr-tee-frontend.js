/**
 * Mr. Tee Editor - Frontend Modular Orchestrator (v2.6.0)
 *
 * Bootstraps the client-side personalization application by interconnecting
 * StateStore, SceneGraphEngine, SvgRenderer, GalleryStage, FormController, and lazy-loaded PrintExporter
 * with side-scoped dirty-tracked lazy multi-view SVG rendering at 60 FPS.
 *
 * @package MrTeeEditor\Assets
 */

import { StateStore } from './modules/state-store.js?v=2.7.1';
import { SceneGraphEngine } from './modules/scene-graph-engine.js?v=2.7.1';
import { SvgRenderer } from './modules/svg-renderer.js?v=2.7.1';
import { GalleryStage } from './modules/gallery-stage.js?v=2.7.1';
import { FormController } from './modules/form-controller.js?v=2.7.1';

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
		this._pendingChangedSides = new Set();
		this._pendingAllSidesDirty = false;
		this._hasUserInteracted = false;
		this._siblingSyncTimer = null;
		this._printExporterClass = null;
		this._printExporterPromise = null;

		this.init();
	}

	/**
	 * Dynamically import PrintExporter strictly on demand (Add-to-Cart or prefetched on edit/hover)
	 * to keep initial page load lean, deduplicating concurrent requests and sharing `window.mrTeePrintExporter`.
	 *
	 * @return {Promise<Function>}
	 */
	async loadPrintExporter() {
		if (this._printExporterClass) return this._printExporterClass;
		if (typeof window !== 'undefined' && window.mrTeePrintExporter) {
			this._printExporterClass = window.mrTeePrintExporter;
			return this._printExporterClass;
		}
		if (!this._printExporterPromise) {
			this._printExporterPromise = import('./modules/print-exporter.js?v=2.7.1')
				.then((mod) => {
					this._printExporterClass = mod.PrintExporter;
					window.mrTeePrintExporter = mod.PrintExporter;
					return this._printExporterClass;
				})
				.catch((err) => {
					this._printExporterPromise = null;
					throw err;
				});
		}
		return this._printExporterPromise;
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

		// 2. Initialize Gallery Stage (Dedicated multi-view gallery with lazy legacy fallback)
		this.stage = new GalleryStage(this.config, {
			renderSvgWithPrefix: (idPrefix, viewId) => this.renderViewSvg(viewId || this.stage.getActiveViewId(), { idPrefix }),
			onViewChange: (viewId, slideIdx, slideMeta) => {
				this._hasUserInteracted = true;
				this.renderActiveViewIfNeeded(viewId, false);
				if (this.formController && typeof this.formController.setActiveSide === 'function') {
					const meta = slideMeta || this.getViewMeta(viewId);
					this.formController.setActiveSide(meta && meta.sideId ? meta.sideId : '');
				}
			},
			onSwipeStart: (neighborViewIds) => {
				this._hasUserInteracted = true;
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
					this.renderSceneGraph(null);
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
					this._hasUserInteracted = true;
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

		// 4. Subscribe to Store State Changes (side-scoped & RAF throttled for 60 FPS typing)
		this._rafRenderId = null;
		this.store.subscribe((state, changeType, changedSideId) => {
			this._hasUserInteracted = true;
			const resolvedSide = changedSideId !== undefined ? changedSideId : this.store.lastChangedSideId;
			if (!resolvedSide) {
				this._pendingAllSidesDirty = true;
			} else {
				this._pendingChangedSides.add(String(resolvedSide));
			}

			if (this._rafRenderId) {
				cancelAnimationFrame(this._rafRenderId);
			}
			this._rafRenderId = requestAnimationFrame(() => {
				this._rafRenderId = null;
				const sidesToRender = this._pendingAllSidesDirty
					? null
					: (this._pendingChangedSides.size > 0 ? new Set(this._pendingChangedSides) : null);
				this._pendingAllSidesDirty = false;
				this._pendingChangedSides.clear();
				this.renderSceneGraph(sidesToRender);
			});
		});

		// Initial Render (renders ONLY the active visible view immediately; same-side siblings sync quietly after idle)
		this.renderSceneGraph(null);

		// Re-sync SVG aspect ratio once legacy gallery image finishes loading if running in legacy mode
		if (!this.stage.isDedicated) {
			const galleryImg = this.stage.getViewImageElement();
			if (galleryImg && (!galleryImg.complete || galleryImg.naturalWidth <= 2)) {
				galleryImg.addEventListener('load', () => {
					this.renderSceneGraph(null);
				}, { once: true });
			}
		}

		// Re-measure standard TTF text widths ONLY when a newly loaded font matches a family used in currentLayers
		if (typeof document !== 'undefined' && document.fonts) {
			const normalizeFamily = (raw) => String(raw || '').trim().replace(/^['"]+|['"]+$/g, '').trim().toLowerCase();
			const onFontsLoaded = (evt) => {
				const usedFamilies = this.getActiveLayerFontFamilies();
				if (usedFamilies.size === 0) return;

				if (evt && evt.fontfaces) {
					let matchedUsed = false;
					for (const ff of evt.fontfaces) {
						const fam = normalizeFamily(ff && ff.family);
						if (fam && usedFamilies.has(fam)) {
							matchedUsed = true;
							break;
						}
					}
					if (!matchedUsed) return;
				}

				if (SvgRenderer._textWidthCache) {
					SvgRenderer._textWidthCache.clear();
				}
				this.markAllViewsDirty();
				if (this.stage && this.stage.isDedicated) {
					this.renderActiveViewIfNeeded(this.stage.getActiveViewId(), true);
				} else {
					this.renderSceneGraph(null);
				}
			};
			if (document.fonts.ready && typeof document.fonts.ready.then === 'function') {
				document.fonts.ready.then(() => onFontsLoaded(null)).catch(() => {});
			}
			if (typeof document.fonts.addEventListener === 'function') {
				document.fonts.addEventListener('loadingdone', onFontsLoaded);
			}
		}

		// 5. Stage 1 (Critical Initial Paint): Immediately decode ONLY Slide 0's default_text glyphs
		//    so the visible product preview renders with 0ms delay and zero bandwidth contention.
		this.prewarmDefaultTextGlyphs();

		// 6. Stage 2 (On First Interaction OR Idle Post-Load): Upgrade partial bootstrap fonts,
		//    warm off-screen active line manifests, and idle-prefetch secondary manifests with max concurrency 2.
		this.scheduleStageTwoWarmup();
	}

	/**
	 * Collect normalized lowercase font-family names actually used by standard (non-OpenType-SVG)
	 * visible TEXT nodes in `this.currentLayers` so `document.fonts` events from font-pill
	 * previews never trigger redundant canvas text re-measurement or SVG re-renders.
	 *
	 * @return {Set<string>}
	 */
	getActiveLayerFontFamilies() {
		const families = new Set();
		const fonts = (this.data && this.data.fonts) || {};
		const walk = (nodes) => {
			if (!Array.isArray(nodes)) return;
			for (const node of nodes) {
				if (!node || node.hide) continue;
				if (node.type === 'TEXT') {
					const fId = node.fontId || '';
					const fDef = fId ? fonts[fId] : null;
					const isLoadedOtSvg = Boolean(
						(node.fontType === 'opentype_svg' || (fDef && (fDef.font_type === 'opentype_svg' || fDef.color_svg)))
						&& SvgRenderer.getSvgFontData(fId || node.fontFamily)
					);
					if (!isLoadedOtSvg && node.fontFamily) {
						for (const part of String(node.fontFamily).split(',')) {
							const cleaned = part.trim().replace(/^['"]+|['"]+$/g, '').trim().toLowerCase();
							if (cleaned) families.add(cleaned);
						}
					}
				}
				if (Array.isArray(node.items)) {
					walk(node.items);
				}
			}
		};
		walk(this.currentLayers);
		return families;
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
	 * Mark ONLY customizable views belonging to `sideId` dirty (`null` / empty marks all views).
	 *
	 * @param {string|null} sideId Target print side ID.
	 */
	markSideViewsDirty(sideId = null) {
		if (!sideId) {
			this.markAllViewsDirty();
			return;
		}
		const target = String(sideId);
		if (this.stage && this.stage.isDedicated) {
			const views = this.stage.getCustomizableViews();
			for (const v of views) {
				if (v && v.viewId && String(v.sideId || 'front') === target) {
					this.dirtyViews.add(v.viewId);
				}
			}
			return;
		}
		const cfgViews = Array.isArray(this.config.preview_views) ? this.config.preview_views : [];
		for (const v of cfgViews) {
			const vid = v && (v.id || v.view_id);
			if (vid && String(v.side_id || 'front') === target) {
				this.dirtyViews.add(String(vid));
			}
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
	computeAspectRatioForView(viewId = '', extraOpts = null) {
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

		const dg = (this.stage && this.stage.isDedicated) ? this.stage.dedicatedGallery : null;
		if (extraOpts && extraOpts.idPrefix === 'fs-' && dg && dg.isLightboxOpen && dg.lbImgEl && dg.lbImgEl.complete && dg.lbImgEl.naturalWidth > 2 && dg.lbImgEl.naturalHeight > 2) {
			const lbSlide = dg.slides[dg.activeIndex];
			if (!viewId || (lbSlide && lbSlide.viewId === viewId)) {
				return dg.lbImgEl.naturalWidth / dg.lbImgEl.naturalHeight;
			}
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
		}

		if (dg && dg.isLightboxOpen && dg.lbImgEl && dg.lbImgEl.complete && dg.lbImgEl.naturalWidth > 2 && dg.lbImgEl.naturalHeight > 2) {
			const lbSlide = dg.slides[dg.activeIndex];
			if (!viewId || (lbSlide && lbSlide.viewId === viewId)) {
				return dg.lbImgEl.naturalWidth / dg.lbImgEl.naturalHeight;
			}
		}

		if (meta && meta.aspectRatio > 0) {
			return meta.aspectRatio;
		}
		if (img && img.offsetWidth > 2 && img.offsetHeight > 2) {
			return img.offsetWidth / img.offsetHeight;
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

		// 1. Merge references into declarative scene-graph layers (reusing Store's refMap)
		let mergedLayers = SceneGraphEngine.getLayersMergedWithRefs(state.layers, state.references, this.store.refMap);

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
	 * Ensure any OpenType-SVG fonts used by layers on `sideId` are loaded in memory
	 * (or upgraded from a `partial: true` bootstrap when a typed character is missing),
	 * triggering an automatic side-scoped re-render the moment the manifest finishes loading.
	 *
	 * @param {string} sideId Target print side identifier (empty string checks all layers).
	 */
	ensureMissingSvgFontsForSide(sideId = '') {
		const fonts = (this.data && this.data.fonts) || {};
		const targetLayers = sideId
			? SvgRenderer.filterLayersBySide(this.currentLayers || [], sideId)
			: (this.currentLayers || []);
		if (!Array.isArray(targetLayers) || targetLayers.length === 0) return;

		const hasMissingPartialChar = (fontData, text) => {
			if (!fontData || !fontData.partial || !text) return false;
			const glyphs = fontData.glyphs || {};
			for (const ch of Array.from(String(text))) {
				if (/^\s$/.test(ch)) continue;
				const g = SvgRenderer.resolveSvgGlyph(glyphs, ch, fontData.defaultAdv || 500);
				if (!g || !g.svg) return true;
			}
			return false;
		};

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
					if (isOt && fId) {
						const currentData = SvgRenderer.getSvgFontData(fId);
						const rawText = String(node.value || '');
						const isUpper = (node.casing === 'UPPER') || Boolean(fDef && fDef.uppercase);
						const warmText = isUpper ? rawText.toUpperCase() : rawText;
						const needsLoad = !currentData || hasMissingPartialChar(currentData, warmText);
						if (needsLoad) {
							const upgradeKey = String(fId).toLowerCase().replace(/[\s\-]+/g, '_');
							if (!this._pendingFontUpgrades.has(upgradeKey)) {
								this._pendingFontUpgrades.add(upgradeKey);
								SvgRenderer.ensureSvgFontLoaded(fId, fDef, {
									prewarmGlyphs: false,
									priorityText: warmText,
								})
									.then((loaded) => {
										this._pendingFontUpgrades.delete(upgradeKey);
										if (!loaded) return;
										this.markSideViewsDirty(sideId || null);
										if (this.stage && this.stage.isDedicated) {
											this.renderActiveViewIfNeeded(this.stage.getActiveViewId(), false);
											this.scheduleSiblingViewsSync();
										} else {
											this.renderSceneGraph(sideId || null);
										}
									})
									.catch(() => {
										this._pendingFontUpgrades.delete(upgradeKey);
									});
							}
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
			: this.computeAspectRatioForView(viewId, extraOpts);
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
			if (line.field_type === 'image' || line.field_type === 'ai_image') continue;
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
	 * Upgrade any `partial: true` OpenType-SVG bootstrap font on the currently active side
	 * so full-alphabet glyph definitions are already in RAM before the customer starts typing.
	 */
	upgradePartialActiveSideFonts() {
		const fonts = (this.data && this.data.fonts) || {};
		const textLines = this.config.text_lines || [];
		const activeViewId = this.stage ? this.stage.getActiveViewId() : '';
		const activeMeta = activeViewId ? this.getViewMeta(activeViewId) : null;
		const activeSideId = (activeMeta && activeMeta.sideId) ? String(activeMeta.sideId) : '';

		for (const line of textLines) {
			if (line.field_type === 'image' || line.field_type === 'ai_image') continue;
			const lineSide = String(line.side_id || 'front');
			if (activeSideId && lineSide !== activeSideId) continue;
			const ref = (this.store && line.ref_id) ? this.store.getReference(line.ref_id) : null;
			const activeFontId = (ref && ref.fontId) || line.font || (Array.isArray(line.allowed_fonts) && line.allowed_fonts[0]);
			if (!activeFontId) continue;
			const existing = SvgRenderer.getSvgFontData(activeFontId);
			if (!existing || !existing.partial) continue;

			const fDef = fonts[activeFontId];
			if (!fDef) continue;
			let priorityText = (ref && ref.value !== undefined) ? String(ref.value) : (line.default_text || '');
			const isUpper = (line.casing === 'UPPER') || Boolean(fDef.uppercase);
			if (isUpper) priorityText = priorityText.toUpperCase();

			SvgRenderer.ensureSvgFontLoaded(activeFontId, fDef, {
				prewarmGlyphs: false,
				priorityText,
			})
				.then((loaded) => {
					if (loaded && !loaded.partial) {
						this.markSideViewsDirty(lineSide);
						if (this.stage && this.stage.isDedicated) {
							this.renderActiveViewIfNeeded(this.stage.getActiveViewId(), false);
						}
					}
				})
				.catch(() => {});
		}
	}

	/**
	 * Schedule Stage 2 warmup on first user interaction OR quietly after `window.load` + `requestIdleCallback`
	 * (2000ms fallback) — completely off the initial LCP critical path.
	 * Upgrades any `partial: true` active-side font, warms active line manifests, and gates
	 * secondary manifest prefetch behind `requestIdleCallback` with max concurrency 2.
	 */
	scheduleStageTwoWarmup() {
		let warmed = false;
		const events = ['pointerdown', 'touchstart', 'focusin', 'mouseenter', 'keydown'];
		const targets = [
			document.getElementById('mr-tee-editor-container'),
			document.getElementById('mr-tee-gallery-root'),
		];

		const runWarmup = (fromUserInteraction = false) => {
			if (fromUserInteraction) {
				this._hasUserInteracted = true;
				this.prefetchAllowedSvgFonts();
			}
			if (warmed) return;
			warmed = true;
			this.upgradePartialActiveSideFonts();
			this.prewarmActiveLineFonts();
		};

		const onInteraction = () => {
			targets.forEach((el) => {
				if (!el) return;
				events.forEach((evt) => el.removeEventListener(evt, onInteraction));
			});
			runWarmup(true);
		};
		targets.forEach((el) => {
			if (!el) return;
			events.forEach((evt) => el.addEventListener(evt, onInteraction, { once: true, passive: true }));
		});

		// Quiet idle post-load fallback so typing/swiping is warm even if user reads before interacting
		if (typeof window !== 'undefined') {
			const queueIdleWarmup = () => {
				if (warmed) return;
				if (typeof window.requestIdleCallback === 'function') {
					window.requestIdleCallback(() => runWarmup(false), { timeout: 2000 });
				} else {
					setTimeout(() => runWarmup(false), 2000);
				}
			};
			if (document.readyState === 'complete') {
				queueIdleWarmup();
			} else {
				window.addEventListener('load', queueIdleWarmup, { once: true });
			}
		}
	}

	/**
	 * Stage 2: Load off-screen active OpenType-SVG manifests (and upgrade any partial bootstrap fonts)
	 * and pre-decode ONLY their default_text glyphs (saving ~70–100 unused alphabet image requests).
	 */
	prewarmActiveLineFonts() {
		const fonts = (this.data && this.data.fonts) || {};
		const textLines = this.config.text_lines || [];
		for (const line of textLines) {
			if (line.field_type === 'image' || line.field_type === 'ai_image') continue;
			const activeFontId = line.font || (Array.isArray(line.allowed_fonts) && line.allowed_fonts[0]);
			if (!activeFontId) continue;
			const fDef = fonts[activeFontId];
			if (fDef && (fDef.font_type === 'opentype_svg' || fDef.color_svg)) {
				let defaultText = line.default_text || '';
				const isUpper = (line.casing === 'UPPER') || Boolean(fDef && fDef.uppercase);
				if (isUpper) {
					defaultText = defaultText.toUpperCase();
				}
				const existingData = SvgRenderer.getSvgFontData(activeFontId);
				const needsFullLoad = !existingData || Boolean(existingData.partial);
				if (!needsFullLoad && !defaultText) continue;
				const lineSide = String(line.side_id || 'front');
				SvgRenderer.ensureSvgFontLoaded(activeFontId, fDef, {
					prewarmGlyphs: false,
					priorityText: defaultText,
				})
					.then((loaded) => {
						if (needsFullLoad && loaded) {
							this.markSideViewsDirty(lineSide);
							if (this.stage && this.stage.isDedicated) {
								this.renderActiveViewIfNeeded(this.stage.getActiveViewId(), false);
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
	 * slides on the same side are fresh before swiping. During initial boot (before user interaction),
	 * ONLY pre-syncs `isSameSide` views and never renders different-side neighbors (e.g. Verso).
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
				const isImmediateNeighbor = this._hasUserInteracted && activeSlide && Math.abs(v.index - activeSlide.index) === 1;
				if ((isSameSide || isImmediateNeighbor) && (this.dirtyViews.has(v.viewId) || !this.renderedViews.has(v.viewId))) {
					this.renderActiveViewIfNeeded(v.viewId, false);
				}
			}
		}, 180);
	}

	/**
	 * Compute scene-graph layout, mark affected customizable views dirty (side-scoped when known),
	 * and immediately update ONLY the currently visible view if dirty (60 FPS lazy multi-view rendering),
	 * scheduling a debounced background sync for sibling slides.
	 *
	 * @param {string|Set<string>|null} changedSides Specific side ID(s) modified, or `null` for all sides.
	 */
	renderSceneGraph(changedSides = null) {
		this.computeMergedScene();

		if (this.stage && this.stage.isDedicated) {
			if (changedSides === null) {
				this.markAllViewsDirty();
			} else if (typeof changedSides === 'string') {
				this.markSideViewsDirty(changedSides);
			} else if (changedSides instanceof Set) {
				if (changedSides.size === 0) {
					this.markAllViewsDirty();
				} else {
					for (const sid of changedSides) {
						this.markSideViewsDirty(sid);
					}
				}
			} else {
				this.markAllViewsDirty();
			}
			this.renderActiveViewIfNeeded(this.stage.getActiveViewId(), false);
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
	 * downloading glyph bitmaps) gated behind `requestIdleCallback` with max concurrency 2,
	 * strictly skipping constrained networks and any font lacking a fresh `manifest_url`.
	 */
	prefetchAllowedSvgFonts() {
		const conn = typeof navigator !== 'undefined' ? navigator.connection : null;
		if (conn && (conn.saveData || conn.effectiveType === '2g' || conn.effectiveType === 'slow-2g')) {
			return;
		}
		if (this._allowedFontsPrefetched) return;
		this._allowedFontsPrefetched = true;

		const fonts = (this.data && this.data.fonts) || {};
		const textLines = this.config.text_lines || [];
		const queue = [];
		const seen = new Set();

		for (const line of textLines) {
			if (line.field_type === 'image' || line.field_type === 'ai_image') continue;
			const allowed = Array.isArray(line.allowed_fonts) ? line.allowed_fonts : Object.keys(fonts);
			for (const fId of allowed) {
				if (!fId || seen.has(fId)) continue;
				const fDef = fonts[fId];
				if (!fDef || (fDef.font_type !== 'opentype_svg' && !fDef.color_svg)) continue;
				if (!fDef.manifest_url) continue;
				if (String(fId).startsWith('use_your_imagination_')) continue;
				const existing = SvgRenderer.getSvgFontData(fId);
				if (existing && !existing.partial) continue;
				seen.add(fId);
				queue.push({ fId, fDef });
			}
		}

		if (queue.length === 0) return;

		const runPrefetchQueue = () => {
			let cursor = 0;
			const maxConcurrency = 2;
			const worker = async () => {
				while (cursor < queue.length) {
					const item = queue[cursor++];
					if (!item) break;
					try {
						await SvgRenderer.ensureSvgFontLoaded(item.fId, item.fDef, { prewarmGlyphs: false });
					} catch (e) {}
				}
			};
			const workers = Math.min(maxConcurrency, queue.length);
			for (let i = 0; i < workers; i++) {
				worker();
			}
		};

		if (typeof window !== 'undefined' && typeof window.requestIdleCallback === 'function') {
			window.requestIdleCallback(runPrefetchQueue, { timeout: 2500 });
		} else {
			setTimeout(runPrefetchQueue, 400);
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
			const hadPendingRaf = Boolean(this._rafRenderId);
			if (this._rafRenderId) {
				cancelAnimationFrame(this._rafRenderId);
				this._rafRenderId = null;
			}
			if (hadPendingRaf || !this.currentLayers || this.currentLayers.length === 0) {
				const pendingSides = this._pendingAllSidesDirty
					? null
					: (this._pendingChangedSides.size > 0 ? new Set(this._pendingChangedSides) : null);
				this._pendingAllSidesDirty = false;
				this._pendingChangedSides.clear();
				this.renderSceneGraph(pendingSides);
			}

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
