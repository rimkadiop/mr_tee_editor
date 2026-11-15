/**
 * Mr. Tee Editor - WP Admin Deferred Order Print Exporter
 *
 * Runs strictly on WooCommerce WP Admin Order edit screens (HPOS & legacy CPT).
 * Automatically generates 300 DPI transparent print PNG and vector SVG files
 * for order line items saved with deferred print status (`data-has-print="0"`),
 * supports on-demand regeneration, and synchronizes artifacts into NBDesigner.
 *
 * @package MrTeeEditor\Assets
 */

import { SceneGraphEngine } from './modules/scene-graph-engine.js?v=2.3.19.1';
import { SvgRenderer } from './modules/svg-renderer.js?v=2.3.19.1';
import { PrintExporter } from './modules/print-exporter.js?v=2.3.19.1';

class MrTeeAdminOrderExporter {
	constructor() {
		this.data = (typeof window !== 'undefined' && window.mrTeeOrderExportData) ? window.mrTeeOrderExportData : null;
		this.activeItems = new Set();
		this.init();
	}

	/**
	 * Initialize button listeners and start automatic queue for pending items.
	 */
	init() {
		if (!this.data || !this.data.nonce) {
			return;
		}

		document.addEventListener('click', (e) => {
			const btn = e.target.closest('.mr-tee-admin-gen-print-btn');
			if (!btn) return;
			e.preventDefault();

			const orderId = parseInt(btn.getAttribute('data-order-id'), 10) || parseInt(this.data.orderId, 10) || 0;
			const itemId = parseInt(btn.getAttribute('data-item-id'), 10) || 0;
			if (orderId > 0 && itemId > 0) {
				this.generateForItem(orderId, itemId, true).catch(() => {});
			}
		});

		this.processPendingItemsSequentially().catch((err) => {
			console.warn('[MrTee AdminOrderExport] Auto-queue error:', err);
		});
	}

	/**
	 * Discover all order items missing 300 DPI PNG / SVG and generate them one at a time.
	 */
	async processPendingItemsSequentially() {
		const seen = new Set();
		const queue = [];

		const containers = document.querySelectorAll('.mr-tee-order-item-export[data-has-print="0"], .mr-tee-metabox-item[data-has-print="0"]');
		containers.forEach((el) => {
			const orderId = parseInt(el.getAttribute('data-order-id'), 10) || parseInt(this.data.orderId, 10) || 0;
			const itemId = parseInt(el.getAttribute('data-item-id'), 10) || 0;
			if (orderId <= 0 || itemId <= 0) return;
			const lockKey = `${orderId}:${itemId}`;
			if (!seen.has(lockKey)) {
				seen.add(lockKey);
				queue.push({ orderId, itemId });
			}
		});

		for (const job of queue) {
			await this.generateForItem(job.orderId, job.itemId, false);
		}
	}

	/**
	 * Find all line-item and sidebar metabox containers matching an order item.
	 *
	 * @param {number} orderId Order ID.
	 * @param {number} itemId  Order item ID.
	 * @return {Element[]}
	 */
	getItemContainers(orderId, itemId) {
		return Array.from(
			document.querySelectorAll(
				`.mr-tee-order-item-export[data-order-id="${orderId}"][data-item-id="${itemId}"], .mr-tee-metabox-item[data-order-id="${orderId}"][data-item-id="${itemId}"]`
			)
		);
	}

	/**
	 * Update UI status, buttons, and download links across all matching item panels.
	 *
	 * @param {number} orderId Order ID.
	 * @param {number} itemId  Order item ID.
	 * @param {string} state   'working' | 'ready' | 'error'
	 * @param {Object} details Optional details ({ message, printUrl, svgUrl }).
	 */
	setItemUiState(orderId, itemId, state, details = {}) {
		const containers = this.getItemContainers(orderId, itemId);
		containers.forEach((box) => {
			const isMetabox = box.classList.contains('mr-tee-metabox-item');
			const statusEl = box.querySelector(isMetabox ? '.mr-tee-metabox-status' : '.mr-tee-print-status');
			const pngBtn = box.querySelector('.mr-tee-btn-print-png');
			const svgBtn = box.querySelector('.mr-tee-btn-print-svg');
			const genBtn = box.querySelector('.mr-tee-admin-gen-print-btn');

			if (state === 'working') {
				if (statusEl) {
					statusEl.style.color = '#1d4ed8';
					statusEl.textContent = details.message || 'Génération 300 DPI & SVG en cours...';
				}
				if (genBtn) {
					genBtn.disabled = true;
					genBtn.style.opacity = '0.7';
					this.setGenButtonLabel(genBtn, isMetabox, 'Génération en cours...');
				}
			} else if (state === 'ready') {
				box.setAttribute('data-has-print', '1');
				if (statusEl) {
					statusEl.style.color = '#065f46';
					statusEl.textContent = isMetabox
						? '300 DPI & SVG prêts'
						: 'Fichiers d\'impression haute résolution (300 DPI & SVG) prêts.';
				}
				if (pngBtn && details.printUrl) {
					pngBtn.setAttribute('href', details.printUrl);
					pngBtn.style.display = '';
				}
				if (svgBtn && details.svgUrl) {
					svgBtn.setAttribute('href', details.svgUrl);
					svgBtn.style.display = '';
				}
				if (genBtn) {
					genBtn.disabled = false;
					genBtn.style.opacity = '';
					this.setGenButtonLabel(genBtn, isMetabox, 'Régénérer 300 DPI & SVG');
				}
			} else if (state === 'error') {
				if (statusEl) {
					statusEl.style.color = '#b91c1c';
					statusEl.textContent = details.message || 'Erreur lors de la génération 300 DPI & SVG.';
				}
				if (genBtn) {
					genBtn.disabled = false;
					genBtn.style.opacity = '';
					const hasPrint = box.getAttribute('data-has-print') === '1';
					this.setGenButtonLabel(genBtn, isMetabox, hasPrint ? 'Régénérer 300 DPI & SVG' : 'Générer 300 DPI & SVG');
				}
			}
		});
	}

	/**
	 * Preserve optional dashicon inside line-item regenerate buttons while updating label text.
	 *
	 * @param {Element} btn       Button element.
	 * @param {boolean} isMetabox Whether button is inside the sidebar metabox.
	 * @param {string}  label     Button text label.
	 */
	setGenButtonLabel(btn, isMetabox, label) {
		if (!btn) return;
		if (isMetabox) {
			btn.textContent = label;
			return;
		}
		btn.innerHTML = `<span class="dashicons dashicons-update" style="vertical-align:middle;font-size:14px;width:14px;height:14px;"></span> ${SvgRenderer.escapeXml(label)}`;
	}

	/**
	 * Collect all font IDs/family strings referenced across payload and product config.
	 *
	 * @param {Object} editorData Order item _mr_tee_editor_data snapshot.
	 * @param {Object} config     Product config.
	 * @return {string[]}
	 */
	collectFontKeys(editorData, config) {
		const keys = new Set();
		const payload = (editorData && editorData.payload) ? editorData.payload : {};

		if (Array.isArray(payload.text_lines)) {
			for (const tl of payload.text_lines) {
				if (tl && tl.fontId) keys.add(String(tl.fontId));
			}
		}

		const walk = (nodes) => {
			if (!Array.isArray(nodes)) return;
			for (const n of nodes) {
				if (!n || typeof n !== 'object') continue;
				if (n.fontId) keys.add(String(n.fontId));
				if (n.fontFamily) keys.add(String(n.fontFamily));
				if (Array.isArray(n.items)) walk(n.items);
			}
		};
		const pv = payload.perso_visual || {};
		walk(pv.references);
		walk(pv.layers);

		if (Array.isArray(config.allowed_fonts)) {
			config.allowed_fonts.forEach((fid) => {
				if (fid) keys.add(String(fid));
			});
		}
		if (Array.isArray(config.text_lines)) {
			config.text_lines.forEach((tl) => {
				if (tl && tl.font) keys.add(String(tl.font));
			});
		}

		return Array.from(keys);
	}

	/**
	 * Ensure all full-res OpenType-SVG fonts required for export have intact `fullMap` glyph URLs.
	 *
	 * @param {Object} ctx Context returned by mr_tee_admin_get_order_item_context.
	 */
	async ensureAdminFontsLoaded(ctx) {
		window.mrTeeOrderExportData = window.mrTeeOrderExportData || {};
		window.mrTeeSvgFonts = window.mrTeeSvgFonts || {};

		if (ctx.fonts && typeof ctx.fonts === 'object') {
			window.mrTeeOrderExportData.fonts = Object.assign({}, window.mrTeeOrderExportData.fonts || {}, ctx.fonts);
		}
		if (ctx.fontFaceCss) {
			window.mrTeeOrderExportData.fontFaceCss = ctx.fontFaceCss;
		}
		if (ctx.svgFonts && typeof ctx.svgFonts === 'object') {
			window.mrTeeOrderExportData.svgFonts = Object.assign({}, window.mrTeeOrderExportData.svgFonts || {}, ctx.svgFonts);
			for (const [fid, sData] of Object.entries(ctx.svgFonts)) {
				if (!sData || typeof sData !== 'object') continue;
				delete sData._prepared;
				const prepared = SvgRenderer.prepareFontData(sData, fid);
				window.mrTeeSvgFonts[fid] = prepared;
				const cleanId = String(fid).toLowerCase().replace(/[\s\-]+/g, '_');
				window.mrTeeSvgFonts[cleanId] = prepared;
			}
		}

		const allFonts = window.mrTeeOrderExportData.fonts || {};
		const neededKeys = this.collectFontKeys(ctx.editor_data, ctx.config || {});

		for (const rawKey of neededKeys) {
			const cleanKey = String(rawKey).toLowerCase().replace(/[\s\-]+/g, '_');
			let matchedId = '';
			let matchedDef = null;

			if (allFonts[rawKey]) {
				matchedId = rawKey;
				matchedDef = allFonts[rawKey];
			} else if (allFonts[cleanKey]) {
				matchedId = cleanKey;
				matchedDef = allFonts[cleanKey];
			} else {
				for (const [fid, fDef] of Object.entries(allFonts)) {
					if (fDef && fDef.family && fDef.family.toLowerCase() === String(rawKey).toLowerCase()) {
						matchedId = fid;
						matchedDef = fDef;
						break;
					}
				}
			}

			const isOtSvg = Boolean(matchedDef && (matchedDef.font_type === 'opentype_svg' || matchedDef.color_svg));
			if (!isOtSvg) continue;

			const checkId = (matchedId || cleanKey).toLowerCase().replace(/[\s\-]+/g, '_');
			const cached = window.mrTeeSvgFonts[checkId] || window.mrTeeSvgFonts[matchedId];
			const hasFullMap = Boolean(cached && cached.fullMap && Object.keys(cached.fullMap).length > 0);
			if (!hasFullMap) {
				delete window.mrTeeSvgFonts[checkId];
				if (matchedId) delete window.mrTeeSvgFonts[matchedId];
				await SvgRenderer.ensureSvgFontLoaded(matchedId || checkId, matchedDef, { prewarmGlyphs: false });
			}
		}
	}

	/**
	 * Generate high-resolution vector SVG and 300 DPI transparent print PNG for an order line item,
	 * upload them in chunks, and bind them to both WooCommerce order metadata and NBDesigner.
	 *
	 * @param {number}  orderId Order ID.
	 * @param {number}  itemId  Order item ID.
	 * @param {boolean} force   Whether triggered manually.
	 * @return {Promise<boolean>}
	 */
	async generateForItem(orderId, itemId, force = false) {
		const lockKey = `${orderId}:${itemId}`;
		if (this.activeItems.has(lockKey)) {
			return false;
		}
		this.activeItems.add(lockKey);

		const ajaxUrl = PrintExporter.resolveAjaxUrl();
		this.setItemUiState(orderId, itemId, 'working', {
			message: force ? 'Régénération 300 DPI & SVG en cours...' : 'Génération 300 DPI & SVG en cours...',
		});

		try {
			// 1. Fetch full scene-graph & font context from WP Admin AJAX
			const ctxBody = new URLSearchParams({
				action: 'mr_tee_admin_get_order_item_context',
				nonce: this.data.nonce,
				order_id: String(orderId),
				item_id: String(itemId),
			});
			const ctxResp = await fetch(ajaxUrl, { method: 'POST', body: ctxBody });
			const ctxJson = await ctxResp.json();
			if (!ctxResp.ok || !ctxJson || !ctxJson.success || !ctxJson.data) {
				throw new Error((ctxJson && ctxJson.data && ctxJson.data.message) || 'Impossible de charger les données de la commande.');
			}

			const ctx = ctxJson.data;
			const editorData = ctx.editor_data || {};
			const config = ctx.config || {};
			const designKey = ctx.design_key || editorData.key || '';
			if (!designKey) {
				throw new Error('Clé de création manquante pour cet article.');
			}

			// 2. Hydrate fullMap OpenType-SVG datasets (never exposed to customers)
			await this.ensureAdminFontsLoaded(ctx);

			// 3. Reconstruct scene-graph layers & zoom transform identically to frontend
			const payload = editorData.payload || {};
			const perso = payload.perso_visual || config.perso_visual || {};
			const rawLayers = Array.isArray(perso.layers) ? perso.layers : [];
			const rawRefs = Array.isArray(perso.references) ? perso.references : [];
			const mergedLayers = SceneGraphEngine.getLayersMergedWithRefs(rawLayers, rawRefs);
			const activeCount = payload.repeater_count !== undefined
				? (parseInt(payload.repeater_count, 10) || 0)
				: ((config.repeater && config.repeater.enabled) ? ((config.repeater.default_count !== undefined) ? config.repeater.default_count : 2) : 0);
			const layers = SceneGraphEngine.applyRowCentering(mergedLayers, activeCount);

			const metrics = PrintExporter.computeExportMetrics(config, editorData);
			const bbox = SceneGraphEngine.getAllLayersBBox(layers);
			const zoomTransform = metrics.hasPerLineBoxes
				? { scale: 1.0, translateX: 0.0, translateY: 0.0 }
				: SceneGraphEngine.getAreaTransformValuesZoom(bbox, perso.area && perso.area.transform);

			// 4. Render 300 DPI Vector SVG & Transparent Print PNG
			this.setItemUiState(orderId, itemId, 'working', {
				message: `Rendu SVG & PNG 300 DPI (${metrics.exportPxW}×${metrics.exportPxH} px)...`,
			});

			const fontFaceCss = (window.mrTeeOrderExportData && window.mrTeeOrderExportData.fontFaceCss) || ctx.fontFaceCss || '';
			const rawSvgMarkup = SvgRenderer.renderToString(layers, zoomTransform, {
				isExport: true,
				realWidth: metrics.exportRealW,
				realHeight: metrics.exportRealH,
				unit: metrics.unit,
				dpi: metrics.dpi,
				aspectRatio: metrics.exportRatio,
				hasPerLineBoxes: metrics.hasPerLineBoxes,
				cropBoundingBox: metrics.cropBoundingBox,
				fontFaceCss,
			});

			const inlinedImagesSvg = await PrintExporter.inlineExternalImages(rawSvgMarkup);
			const inlinedSvg = await PrintExporter.inlineExternalFonts(inlinedImagesSvg);
			const printB64 = await PrintExporter.rasterizeSvgToPng(inlinedSvg, metrics.exportPxW, metrics.exportPxH);

			const svgBlob = new Blob([inlinedSvg], { type: 'image/svg+xml;charset=utf-8' });
			const printBlob = PrintExporter.dataUrlToBlob(printB64);
			if (!svgBlob || svgBlob.size === 0 || !printBlob || printBlob.size === 0) {
				throw new Error('La rastérisation 300 DPI a produit un fichier vide.');
			}

			// 5. Upload vector SVG & 300 DPI PNG in chunks under admin nonce
			this.setItemUiState(orderId, itemId, 'working', {
				message: 'Téléversement des fichiers haute résolution...',
			});

			await PrintExporter.uploadBlobInChunks(svgBlob, {
				artifact: 'svg',
				designKey,
				nonce: this.data.nonce,
			});
			await PrintExporter.uploadBlobInChunks(printBlob, {
				artifact: 'print_png',
				designKey,
				nonce: this.data.nonce,
			});

			// 6. Bind saved print files to WooCommerce order item meta + NBDesigner folder
			const saveBody = new URLSearchParams({
				action: 'mr_tee_admin_save_print_artifacts',
				nonce: this.data.nonce,
				order_id: String(orderId),
				item_id: String(itemId),
				design_key: designKey,
			});
			const saveResp = await fetch(ajaxUrl, { method: 'POST', body: saveBody });
			const saveJson = await saveResp.json();
			if (!saveResp.ok || !saveJson || !saveJson.success || !saveJson.data) {
				throw new Error((saveJson && saveJson.data && saveJson.data.message) || 'Impossible de lier les fichiers 300 DPI à la commande.');
			}

			this.setItemUiState(orderId, itemId, 'ready', {
				printUrl: saveJson.data.print_url,
				svgUrl: saveJson.data.svg_url,
			});

			return true;
		} catch (err) {
			console.error('[MrTee AdminOrderExport] Failed for item ' + itemId + ':', err);
			this.setItemUiState(orderId, itemId, 'error', {
				message: (err && err.message) ? err.message : 'Erreur lors de la génération 300 DPI & SVG.',
			});
			return false;
		} finally {
			this.activeItems.delete(lockKey);
		}
	}
}

if (document.readyState === 'loading') {
	document.addEventListener('DOMContentLoaded', () => {
		window.mrTeeAdminOrderExporter = new MrTeeAdminOrderExporter();
	});
} else {
	window.mrTeeAdminOrderExporter = new MrTeeAdminOrderExporter();
}
