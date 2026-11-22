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

import { SceneGraphEngine } from './modules/scene-graph-engine.js?v=2.4.0.1';
import { SvgRenderer } from './modules/svg-renderer.js?v=2.4.0.1';
import { PrintExporter } from './modules/print-exporter.js?v=2.4.0.1';

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
	 * Build a labeled per-side download anchor matching WP Admin OrderAdminPanel styling.
	 *
	 * @param {Object}  side      Normalized side export ({ side_id, label, print_url, svg_url }).
	 * @param {string}  kind      'png' | 'svg'
	 * @param {boolean} isMetabox Whether rendered inside sidebar metabox.
	 * @return {HTMLAnchorElement}
	 */
	createSideExportButton(side, kind, isMetabox) {
		const a = document.createElement('a');
		const isPng = kind === 'png';
		a.href = isPng ? side.print_url : side.svg_url;
		a.target = '_blank';
		a.setAttribute('download', '');
		a.setAttribute('data-side-id', side.side_id || '');

		const labelText = isPng
			? `${side.label} — PNG 300 DPI`
			: `${side.label} — Vector SVG`;

		if (isMetabox) {
			a.className = isPng
				? 'button button-primary mr-tee-btn-side-export mr-tee-btn-side-png'
				: 'button mr-tee-btn-side-export mr-tee-btn-side-svg';
			a.style.textAlign = 'center';
			a.textContent = labelText;
		} else {
			a.className = isPng
				? 'button button-primary button-small mr-tee-btn-side-export mr-tee-btn-side-png'
				: 'button button-secondary button-small mr-tee-btn-side-export mr-tee-btn-side-svg';
			const iconClass = isPng ? 'dashicons-download' : 'dashicons-media-code';
			a.innerHTML = `<span class="dashicons ${iconClass}" style="vertical-align:middle;font-size:14px;width:14px;height:14px;"></span> ${SvgRenderer.escapeXml(labelText)}`;
		}
		return a;
	}

	/**
	 * Update UI status, buttons, and download links across all matching item panels.
	 *
	 * @param {number} orderId Order ID.
	 * @param {number} itemId  Order item ID.
	 * @param {string} state   'working' | 'ready' | 'error'
	 * @param {Object} details Optional details ({ message, printUrl, svgUrl, sideExports }).
	 */
	setItemUiState(orderId, itemId, state, details = {}) {
		const containers = this.getItemContainers(orderId, itemId);
		const sideList = details.sideExports
			? (Array.isArray(details.sideExports) ? details.sideExports : Object.values(details.sideExports))
				.filter((s) => s && (s.print_url || s.svg_url))
			: [];
		const isMultiSides = sideList.length > 1;

		containers.forEach((box) => {
			const isMetabox = box.classList.contains('mr-tee-metabox-item');
			const statusEl = box.querySelector(isMetabox ? '.mr-tee-metabox-status' : '.mr-tee-print-status');
			const actionsEl = box.querySelector(isMetabox ? '.mr-tee-metabox-actions' : '.mr-tee-print-actions');
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

				if (actionsEl) {
					actionsEl.querySelectorAll('.mr-tee-btn-side-export').forEach((el) => el.remove());
				}

				if (isMultiSides && actionsEl) {
					if (pngBtn) pngBtn.style.display = 'none';
					if (svgBtn) svgBtn.style.display = 'none';

					const anchor = pngBtn || genBtn || actionsEl.firstChild;
					sideList.forEach((side) => {
						if (side.print_url) {
							actionsEl.insertBefore(this.createSideExportButton(side, 'png', isMetabox), anchor);
						}
						if (side.svg_url) {
							actionsEl.insertBefore(this.createSideExportButton(side, 'svg', isMetabox), anchor);
						}
					});

					const dimsEl = box.querySelector('.mr-tee-print-dimensions span');
					if (dimsEl) {
						const parts = sideList
							.filter((s) => s.print_dimensions)
							.map((s) => `${s.label} : ${s.print_dimensions}`);
						if (parts.length > 0) {
							dimsEl.textContent = parts.join(' | ');
						}
					}
				} else {
					if (pngBtn && details.printUrl) {
						pngBtn.setAttribute('href', details.printUrl);
						pngBtn.style.display = '';
					}
					if (svgBtn && details.svgUrl) {
						svgBtn.setAttribute('href', details.svgUrl);
						svgBtn.style.display = '';
					}
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
	 * Hydrate missing side_id / placements metadata on merged layers from product/payload text_lines.
	 *
	 * @param {Array}  layers  Merged scene-graph layers.
	 * @param {Object} config  Product config.
	 * @param {Object} payload Order item payload.
	 * @return {Map<string, string>} Reference ID -> side_id lookup map.
	 */
	hydrateLayerSideMetadata(layers, config, payload) {
		const refMetaMap = new Map();
		const refSideMap = new Map();

		const registerLines = (lines) => {
			if (!Array.isArray(lines)) return;
			for (const l of lines) {
				if (!l || !l.ref_id) continue;
				const sid = l.side_id || l.sideId || 'front';
				if (!refSideMap.has(l.ref_id)) {
					refSideMap.set(l.ref_id, sid);
				}
				if (!refMetaMap.has(l.ref_id)) {
					refMetaMap.set(l.ref_id, {
						side_id: sid,
						placements: (l.placements && typeof l.placements === 'object') ? l.placements : null,
					});
				}
			}
		};

		registerLines(config && config.text_lines);
		registerLines(payload && payload.text_lines);

		const walk = (nodes, inheritedSide = null) => {
			if (!Array.isArray(nodes)) return;
			for (const node of nodes) {
				if (!node || typeof node !== 'object') continue;
				const meta = node.reference ? refMetaMap.get(node.reference) : null;
				if (!node.side_id && !node.sideId) {
					if (meta && meta.side_id) {
						node.side_id = meta.side_id;
					} else if (inheritedSide) {
						node.side_id = inheritedSide;
					}
				}
				if (!node.placements && meta && meta.placements) {
					node.placements = meta.placements;
				}
				const nextSide = node.side_id || node.sideId || inheritedSide;
				if (Array.isArray(node.items)) {
					walk(node.items, nextSide);
				}
			}
		};
		walk(layers, null);

		return refSideMap;
	}

	/**
	 * Check whether a given print side has at least one visible non-empty layer.
	 *
	 * @param {Array}               layers     Merged scene-graph layers.
	 * @param {string}              sideId     Target print side ID.
	 * @param {Map<string, string>} refSideMap Reference ID -> side_id lookup map.
	 * @return {boolean}
	 */
	sideHasVisibleLayer(layers, sideId, refSideMap) {
		const target = String(sideId || 'front');
		const inspect = (nodes, inheritedSide = null) => {
			if (!Array.isArray(nodes)) return false;
			for (const node of nodes) {
				if (!node || node.hide) continue;
				const nodeSide = node.side_id || node.sideId || (node.reference && refSideMap && refSideMap.get(node.reference)) || inheritedSide;
				if (node.type === 'GROUP' || node.type === 'MASK') {
					if (nodeSide && String(nodeSide) !== target) {
						continue;
					}
					if (inspect(node.items || [], nodeSide || inheritedSide)) {
						return true;
					}
				} else if (node.type === 'TEXT') {
					const effSide = String(nodeSide || 'front');
					if (effSide === target && String(node.value || node.text || '').trim().length > 0) {
						return true;
					}
				} else if (node.type === 'IMAGE') {
					const effSide = String(nodeSide || 'front');
					if (effSide === target && Boolean(node.url)) {
						return true;
					}
				} else if (node.type === 'PATH') {
					const effSide = String(nodeSide || 'front');
					if (effSide === target && Boolean(node.path)) {
						return true;
					}
				}
			}
			return false;
		};
		return inspect(layers, null);
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
	 * Generate high-resolution vector SVG and 300 DPI transparent print PNG for each active side
	 * of an order line item, upload them in chunks, and bind them to WooCommerce + NBDesigner.
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
			const refSideMap = this.hydrateLayerSideMetadata(layers, config, payload);

			const printSides = (Array.isArray(payload.print_sides) && payload.print_sides.length > 0)
				? payload.print_sides
				: ((Array.isArray(config.print_sides) && config.print_sides.length > 0)
					? config.print_sides
					: [{ id: 'front', label: 'Recto' }]);
			const previewViews = (Array.isArray(payload.preview_views) && payload.preview_views.length > 0)
				? payload.preview_views
				: (Array.isArray(config.preview_views) ? config.preview_views : []);

			const activeSides = printSides.length > 1
				? printSides.filter((s, idx) => idx === 0 || this.sideHasVisibleLayer(layers, (s && s.id) || 'front', refSideMap))
				: [printSides[0]];
			const sidesToExport = activeSides.length > 0 ? activeSides : [printSides[0]];
			const isMultiSide = sidesToExport.length > 1;

			const bbox = SceneGraphEngine.getAllLayersBBox(layers);
			const fontFaceCss = (window.mrTeeOrderExportData && window.mrTeeOrderExportData.fontFaceCss) || ctx.fontFaceCss || '';
			const exportedSides = [];

			// 4 & 5. Render and upload 300 DPI Vector SVG & Transparent Print PNG for each active side
			for (let i = 0; i < sidesToExport.length; i++) {
				const side = sidesToExport[i] || { id: 'front', label: 'Recto' };
				const sideId = side.id || (i === 0 ? 'front' : `side_${i}`);
				const sideLabel = side.label || (i === 0 ? 'Recto' : sideId);
				const sideViews = previewViews.filter((v) => v && (v.side_id || 'front') === sideId);
				const refView = sideViews.find((v) => v.view_role === 'full') || sideViews[0] || null;

				const metrics = PrintExporter.computeSideExportMetrics(config, editorData, sideId);
				const zoomTransform = metrics.hasPerLineBoxes
					? { scale: 1.0, translateX: 0.0, translateY: 0.0 }
					: SceneGraphEngine.getAreaTransformValuesZoom(bbox, perso.area && perso.area.transform);

				this.setItemUiState(orderId, itemId, 'working', {
					message: isMultiSide
						? `Rendu ${sideLabel} (${i + 1}/${sidesToExport.length}) — ${metrics.exportPxW}×${metrics.exportPxH} px...`
						: `Rendu SVG & PNG 300 DPI (${metrics.exportPxW}×${metrics.exportPxH} px)...`,
				});

				const renderOptions = {
					isExport: true,
					realWidth: metrics.exportRealW,
					realHeight: metrics.exportRealH,
					unit: metrics.unit,
					dpi: metrics.dpi,
					aspectRatio: metrics.exportRatio,
					hasPerLineBoxes: metrics.hasPerLineBoxes,
					cropBoundingBox: metrics.cropBoundingBox,
					fontFaceCss,
				};
				if (isMultiSide || printSides.length > 1 || refView) {
					renderOptions.sideId = sideId;
					if (refView && refView.id) {
						renderOptions.viewId = refView.id;
					}
				}

				const rawSvgMarkup = SvgRenderer.renderToString(layers, zoomTransform, renderOptions);
				const inlinedImagesSvg = await PrintExporter.inlineExternalImages(rawSvgMarkup);
				const inlinedSvg = await PrintExporter.inlineExternalFonts(inlinedImagesSvg);
				const printB64 = await PrintExporter.rasterizeSvgToPng(inlinedSvg, metrics.exportPxW, metrics.exportPxH);

				const svgBlob = new Blob([inlinedSvg], { type: 'image/svg+xml;charset=utf-8' });
				const printBlob = PrintExporter.dataUrlToBlob(printB64);
				if (!svgBlob || svgBlob.size === 0 || !printBlob || printBlob.size === 0) {
					throw new Error(`La rastérisation 300 DPI (${sideLabel}) a produit un fichier vide.`);
				}

				this.setItemUiState(orderId, itemId, 'working', {
					message: isMultiSide
						? `Téléversement ${sideLabel} (${i + 1}/${sidesToExport.length})...`
						: 'Téléversement des fichiers haute résolution...',
				});

				const suffix = isMultiSide ? String(sideId).replace(/[^a-zA-Z0-9_-]/g, '').slice(0, 32) : '';
				const isPrimary = (i === 0);

				const svgUpload = await PrintExporter.uploadBlobInChunks(svgBlob, {
					artifact: 'svg',
					designKey,
					nonce: this.data.nonce,
					suffix,
					isPrimary,
				});
				const printUpload = await PrintExporter.uploadBlobInChunks(printBlob, {
					artifact: 'print_png',
					designKey,
					nonce: this.data.nonce,
					suffix,
					isPrimary,
				});

				exportedSides.push({
					side_id: sideId,
					label: sideLabel,
					suffix,
					svg_file_name: (svgUpload && svgUpload.file_name) || (suffix ? `design_${suffix}.svg` : 'design.svg'),
					print_file_name: (printUpload && printUpload.file_name) || (suffix ? `print_300dpi_${suffix}.png` : 'print_300dpi.png'),
					print_dimensions: `${metrics.exportRealW} × ${metrics.exportRealH} ${metrics.unit} (${metrics.dpi} DPI | ${metrics.exportPxW} × ${metrics.exportPxH} px)`,
				});
			}

			// 6. Bind saved print files to WooCommerce order item meta + NBDesigner folder
			const saveBody = new URLSearchParams({
				action: 'mr_tee_admin_save_print_artifacts',
				nonce: this.data.nonce,
				order_id: String(orderId),
				item_id: String(itemId),
				design_key: designKey,
				sides_json: JSON.stringify(exportedSides),
			});
			const saveResp = await fetch(ajaxUrl, { method: 'POST', body: saveBody });
			const saveJson = await saveResp.json();
			if (!saveResp.ok || !saveJson || !saveJson.success || !saveJson.data) {
				throw new Error((saveJson && saveJson.data && saveJson.data.message) || 'Impossible de lier les fichiers 300 DPI à la commande.');
			}

			this.setItemUiState(orderId, itemId, 'ready', {
				printUrl: saveJson.data.print_url,
				svgUrl: saveJson.data.svg_url,
				sideExports: saveJson.data.side_exports || null,
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
