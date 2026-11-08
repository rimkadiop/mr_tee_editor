/**
 * Mr. Tee Editor - Frontend Modular Orchestrator
 *
 * Bootstraps the client-side personalization application by interconnecting
 * StateStore, SceneGraphEngine, SvgRenderer, GalleryStage, FormController, and PrintExporter.
 *
 * @package MrTeeEditor\Assets
 */

import { StateStore } from './modules/state-store.js?ver=2.0.5';
import { SceneGraphEngine } from './modules/scene-graph-engine.js?ver=2.0.5';
import { SvgRenderer } from './modules/svg-renderer.js?ver=2.0.5';
import { GalleryStage } from './modules/gallery-stage.js?ver=2.0.5';
import { FormController } from './modules/form-controller.js?ver=2.0.5';
import { PrintExporter } from './modules/print-exporter.js?ver=2.0.5';

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
		const zoom = SceneGraphEngine.getAreaTransformValuesZoom(bbox, state.area && state.area.transform);

		this.currentLayers = mergedLayers;
		this.currentZoom = zoom;

		// 5. Render SVG and mount into gallery stage
		const svgMarkup = SvgRenderer.renderToString(mergedLayers, zoom);
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
