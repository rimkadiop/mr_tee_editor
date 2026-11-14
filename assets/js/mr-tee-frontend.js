/**
 * Mr. Tee Editor - Frontend Modular Orchestrator
 *
 * Bootstraps the client-side personalization application by interconnecting
 * StateStore, SceneGraphEngine, SvgRenderer, GalleryStage, FormController, and PrintExporter.
 *
 * @package MrTeeEditor\Assets
 */

import { StateStore } from './modules/state-store.js?v=2.3.12';
import { SceneGraphEngine } from './modules/scene-graph-engine.js?v=2.3.12';
import { SvgRenderer } from './modules/svg-renderer.js?v=2.3.12';
import { GalleryStage } from './modules/gallery-stage.js?v=2.3.12';
import { FormController } from './modules/form-controller.js?v=2.3.12';
import { PrintExporter } from './modules/print-exporter.js?v=2.3.12';

window.mrTeePrintExporter = PrintExporter;
window.mrTeeSvgRenderer = SvgRenderer;
window.mrTeeSceneGraphEngine = SceneGraphEngine;

class MrTeeFrontendApp {
	constructor() {
		window.mrTeePrintExporter = PrintExporter;
		window.mrTeeSvgRenderer = SvgRenderer;
		window.mrTeeSceneGraphEngine = SceneGraphEngine;

		this.data = window.mrTeeProductData || {};
		this.config = this.data.config || {};

		this.store = null;
		this.stage = null;
		this.formController = null;
		this.currentLayers = [];
		this.currentZoom = { scale: 1, translateX: 0, translateY: 0 };

		this.init();
	}

	init() {
		if (!document.getElementById('mr-tee-editor-container')) {
			return;
		}

		// Ensure form action protocol matches current protocol to prevent 503 SSL redirection errors
		const cartForm = document.querySelector('form.cart');
		if (cartForm && location.protocol === 'http:' && cartForm.action && cartForm.action.startsWith('https:')) {
			cartForm.action = cartForm.action.replace('https:', 'http:');
		}

		// 1. Initialize State Store
		this.store = new StateStore(this.config);

		// 2. Initialize Gallery Stage Overlay
		this.stage = new GalleryStage(this.config);

		// 3. Initialize Form Controller
		this.formController = new FormController(
			this.store,
			this.config,
			(onDone) => this.handleExport(onDone)
		);

		// 4. Subscribe to Store State Changes (RAF throttled for buttery-smooth 60 FPS mobile typing)
		this._rafRenderId = null;
		this.store.subscribe((state, changeType) => {
			if (this._rafRenderId) {
				cancelAnimationFrame(this._rafRenderId);
			}
			this._rafRenderId = requestAnimationFrame(() => {
				this._rafRenderId = null;
				this.renderSceneGraph();
			});
		});

		// Initial Render
		this.renderSceneGraph();

		// 5. Stage 1 (Critical Initial Paint): Immediately decode ONLY the default_text glyphs
		//    so the visible product preview renders with 0ms delay and zero bandwidth contention.
		this.prewarmDefaultTextGlyphs();

		// 6. Stage 2 (Post-Load / Idle): Pre-warm the remaining A-Z, 0-9 alphabet in throttled batches
		//    and lazy-fetch secondary allowed OpenType-SVG manifests in the background.
		this.scheduleStageTwoWarmup();
	}

	/**
	 * Stage 1: Immediately decode only the glyph bitmaps used by each line's initial default_text.
	 */
	prewarmDefaultTextGlyphs() {
		const preloaded = (this.data && this.data.svgFonts) || {};
		const fonts = (this.data && this.data.fonts) || {};
		const textLines = this.config.text_lines || [];

		for (const line of textLines) {
			if (line.field_type === 'image') continue;
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
	 * Schedule Stage 2 background alphabet pre-warming and secondary manifest prefetching
	 * after initial page load completes so LCP and initial default_text glyphs have 100% network priority.
	 */
	scheduleStageTwoWarmup() {
		const runStageTwo = () => {
			const idleExec = typeof window.requestIdleCallback === 'function'
				? (fn) => window.requestIdleCallback(fn, { timeout: 1500 })
				: (fn) => setTimeout(fn, 250);

			idleExec(() => {
				this.prewarmActiveLineFonts();
				setTimeout(() => this.prefetchAllowedSvgFonts(), 600);
			});
		};

		if (document.readyState === 'complete') {
			runStageTwo();
		} else {
			window.addEventListener('load', runStageTwo, { once: true });
		}
	}

	/**
	 * Stage 2: Pre-warm all glyph bitmaps (A-Z, 0-9) in throttled batches for the active font on each line.
	 */
	prewarmActiveLineFonts() {
		const fonts = (this.data && this.data.fonts) || {};
		const preloaded = (this.data && this.data.svgFonts) || {};
		for (const [, sData] of Object.entries(preloaded)) {
			if (sData) {
				SvgRenderer.prewarmFontGlyphs(sData);
			}
		}

		const textLines = this.config.text_lines || [];
		for (const line of textLines) {
			if (line.field_type === 'image') continue;
			const activeFontId = line.font || (Array.isArray(line.allowed_fonts) && line.allowed_fonts[0]);
			if (!activeFontId) continue;
			const fDef = fonts[activeFontId];
			if (fDef && (fDef.font_type === 'opentype_svg' || fDef.color_svg)) {
				SvgRenderer.ensureSvgFontLoaded(activeFontId, fDef, { prewarmGlyphs: true }).catch(() => {});
			}
		}
	}

	/**
	 * Compute scene-graph layout and update live SVG overlay.
	 */
	renderSceneGraph() {
		const state = this.store.state;

		// 1. Merge references into declarative scene-graph layers
		let mergedLayers = SceneGraphEngine.getLayersMergedWithRefs(state.layers, state.references);

		// 2. Apply 2-row dynamic centering (Ligne 1: 1..3, Ligne 2: 4..6)
		mergedLayers = SceneGraphEngine.applyRowCentering(mergedLayers, state.activeCount);

		// 3. Compute accurate bounding box of all visible layers
		const bbox = SceneGraphEngine.getAllLayersBBox(mergedLayers);

		// 4. Compute auto-zoom and vertical auto-centering
		const hasPerLineBoxes = Array.isArray(this.config.text_lines) && this.config.text_lines.some(l => l.box_left !== undefined && l.box_width !== undefined);
		const zoom = hasPerLineBoxes
			? { scale: 1.0, translateX: 0.0, translateY: 0.0 }
			: SceneGraphEngine.getAreaTransformValuesZoom(bbox, state.area && state.area.transform);

		let ratio = this._cachedAspectRatio || 0;
		if (!ratio || ratio <= 0) {
			const isLocked = Boolean(this.config.lock_aspect_ratio) || this.config.product_type === 'flat_object';
			const realW = parseFloat(this.config.print_real_width) || 0;
			const realH = parseFloat(this.config.print_real_height) || 0;

			if (isLocked && realW > 0 && realH > 0) {
				ratio = realW / realH;
				this._cachedAspectRatio = ratio;
			} else if (isLocked && parseFloat(this.config.print_aspect_ratio) > 0) {
				ratio = parseFloat(this.config.print_aspect_ratio);
				this._cachedAspectRatio = ratio;
			} else {
				const img = document.querySelector('.woocommerce-product-gallery__image img:not(.emoji):not(.zoomImg)');
				if (img && img.naturalWidth > 0 && img.naturalHeight > 0) {
					ratio = img.naturalWidth / img.naturalHeight;
					this._cachedAspectRatio = ratio;
				} else if (img && img.offsetWidth > 0 && img.offsetHeight > 0) {
					ratio = img.offsetWidth / img.offsetHeight;
				} else if (realW > 0 && realH > 0) {
					ratio = realW / realH;
				} else {
					ratio = 1.0;
				}
			}
		}

		const realW = parseFloat(this.config.print_real_width) || 0;
		const realH = parseFloat(this.config.print_real_height) || 0;

		this.currentLayers = mergedLayers;
		this.currentZoom = zoom;

		// 5. Render SVG and mount into gallery stage
		const svgMarkup = SvgRenderer.renderToString(mergedLayers, zoom, {
			aspectRatio: ratio,
			realWidth: realW,
			realHeight: realH,
			unit: this.config.print_unit || 'cm',
			dpi: this.config.dpi || 300,
			hasPerLineBoxes: hasPerLineBoxes,
		});
		this.stage.updateSvg(svgMarkup);
	}

	/**
	 * Idle-prefetch secondary allowed OpenType-SVG font manifests (~20 KB JSON only, without
	 * downloading all 36 glyph bitmaps per unselected font) so tapping any font pill is instant.
	 */
	prefetchAllowedSvgFonts() {
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
	 * Handle Add-to-Cart export request: rasterizes 300 DPI PNG and submits.
	 *
	 * @param {Function} onDone Callback invoked after inputs are populated.
	 */
	async handleExport(onDone) {
		try {
			await PrintExporter.prepareExport(
				this.currentLayers,
				this.currentZoom,
				this.store,
				this.config
			);
		} catch (err) {
			console.error('[MrTee Editor] Export preparation failed: ' + (err && err.stack ? err.stack : err));
		}

		if (typeof onDone === 'function') {
			onDone();
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
