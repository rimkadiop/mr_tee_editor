/**
 * Mr. Tee Editor - Frontend Modular Orchestrator
 *
 * Bootstraps the client-side personalization application by interconnecting
 * StateStore, SceneGraphEngine, SvgRenderer, GalleryStage, FormController, and PrintExporter.
 *
 * @package MrTeeEditor\Assets
 */

import { StateStore } from './modules/state-store.js?v=2.2.1';
import { SceneGraphEngine } from './modules/scene-graph-engine.js?v=2.2.1';
import { SvgRenderer } from './modules/svg-renderer.js?v=2.2.1';
import { GalleryStage } from './modules/gallery-stage.js?v=2.2.1';
import { FormController } from './modules/form-controller.js?v=2.2.1';
import { PrintExporter } from './modules/print-exporter.js?v=2.2.1';

class MrTeeFrontendApp {
	constructor() {
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

		// 4. Subscribe to Store State Changes
		this.store.subscribe((state, changeType) => {
			this.renderSceneGraph();
		});

		// Initial Render
		this.renderSceneGraph();
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

		const realW = parseFloat(this.config.print_real_width) || 0;
		const realH = parseFloat(this.config.print_real_height) || 0;
		let ratio = (realW > 0 && realH > 0) ? (realW / realH) : (parseFloat(this.config.print_aspect_ratio) || 0);
		if (!ratio || ratio <= 0) {
			const img = document.querySelector('.woocommerce-product-gallery__image img:not(.emoji):not(.zoomImg)');
			if (img && img.naturalWidth > 0 && img.naturalHeight > 0) {
				ratio = img.naturalWidth / img.naturalHeight;
			} else if (img && img.offsetWidth > 0 && img.offsetHeight > 0) {
				ratio = img.offsetWidth / img.offsetHeight;
			} else {
				ratio = 1.0;
			}
		}

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
			console.error('[MrTee Editor] Export preparation failed:', err);
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
