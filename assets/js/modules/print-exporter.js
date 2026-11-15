/**
 * Mr. Tee Editor - Print Exporter Module
 *
 * Preloads fonts and external images as base64 data URIs and rasterizes
 * the scene-graph to 300 DPI transparent print PNG (2400x3000px) and vector SVG.
 *
 * @package MrTeeEditor\Assets\Modules
 */

import { SvgRenderer } from './svg-renderer.js?v=2.3.17';

export class PrintExporter {
	static CHUNK_SIZE_BYTES = 600 * 1024; // 600 KB binary chunks fit cleanly under 1 MB proxy/WAF limits while minimizing round-trips

	static normalizeSameHostUrl(rawUrl) {
		if (!rawUrl || typeof rawUrl !== 'string') return '';
		if (rawUrl.startsWith('data:')) return rawUrl;
		try {
			const parsed = new URL(rawUrl, window.location.origin);
			if (parsed.hostname === window.location.hostname) {
				return parsed.pathname + parsed.search;
			}
		} catch (e) {}
		return rawUrl;
	}

	static async fetchImageAsDataUrl(rawUrl) {
		if (!rawUrl || typeof rawUrl !== 'string') return '';
		if (rawUrl.startsWith('data:image/')) {
			return rawUrl;
		}
		if (typeof window !== 'undefined') {
			window.mrTeeImageB64Cache = window.mrTeeImageB64Cache || {};
			if (window.mrTeeImageB64Cache[rawUrl]) {
				return window.mrTeeImageB64Cache[rawUrl];
			}
		}
		const fetchUrl = this.normalizeSameHostUrl(rawUrl);
		try {
			const ctrl = typeof AbortController !== 'undefined' ? new AbortController() : null;
			const timer = ctrl ? setTimeout(() => ctrl.abort(), 12000) : null;
			const resp = await fetch(fetchUrl, ctrl ? { signal: ctrl.signal } : undefined);
			if (timer) clearTimeout(timer);
			if (resp.ok) {
				const blob = await resp.blob();
				const b64 = await new Promise((res, rej) => {
					const reader = new FileReader();
					reader.onloadend = () => res(reader.result);
					reader.onerror = rej;
					reader.readAsDataURL(blob);
				});
				if (typeof window !== 'undefined' && b64) {
					window.mrTeeImageB64Cache[rawUrl] = b64;
					if (fetchUrl !== rawUrl) window.mrTeeImageB64Cache[fetchUrl] = b64;
				}
				return b64 || '';
			}
		} catch (e) {
			console.warn('[MrTee PrintExporter] Failed to fetch image URL:', rawUrl, e);
		}
		return '';
	}

	static findProductGalleryImageElement() {
		const selectors = [
			'.woocommerce-product-gallery .flex-active-slide img:not(.emoji):not(.zoomImg)',
			'.woocommerce-product-gallery__image.flex-active-slide img:not(.emoji):not(.zoomImg)',
			'.woocommerce-product-gallery__image:first-child img:not(.emoji):not(.zoomImg)',
			'.mr-tee-stage-garment-img',
			'.product .images img:not(.emoji):not(.zoomImg)',
		];
		for (const sel of selectors) {
			const el = document.querySelector(sel);
			if (el) return el;
		}
		return null;
	}

	static resolveProductBackgroundUrl() {
		const imgEl = this.findProductGalleryImageElement();
		if (imgEl) {
			const candidates = [
				imgEl.currentSrc,
				imgEl.getAttribute('src'),
				imgEl.getAttribute('data-large_image'),
				imgEl.getAttribute('data-src'),
				imgEl.getAttribute('data-lazy-src'),
			];
			for (const cand of candidates) {
				if (cand && !cand.startsWith('data:image/svg+xml')) {
					return cand;
				}
			}
		}
		const data = (typeof window !== 'undefined' && window.mrTeeProductData) ? window.mrTeeProductData : {};
		return data.productImageUrl || '';
	}

	static loadHtmlImage(src, allowCors = false) {
		return new Promise((resolve, reject) => {
			if (!src) {
				reject(new Error('Empty image src'));
				return;
			}
			const img = new Image();
			if (allowCors) img.crossOrigin = 'anonymous';
			let settled = false;
			const timer = setTimeout(() => {
				if (!settled) {
					settled = true;
					reject(new Error('Image load timeout'));
				}
			}, 12000);
			img.onload = () => {
				if (settled) return;
				settled = true;
				clearTimeout(timer);
				resolve(img);
			};
			img.onerror = (err) => {
				if (settled) return;
				settled = true;
				clearTimeout(timer);
				reject(err || new Error('Image load failed'));
			};
			img.src = src;
		});
	}

	static async loadBackgroundImage(rawUrl) {
		if (!rawUrl) return null;
		const dataUrl = await this.fetchImageAsDataUrl(rawUrl);
		if (dataUrl) {
			try {
				const img = await this.loadHtmlImage(dataUrl, false);
				if (img && img.naturalWidth > 0) {
					return { img, naturalWidth: img.naturalWidth, naturalHeight: img.naturalHeight, taintedSafe: true };
				}
			} catch (e) {}
		}
		const fallbackUrl = this.normalizeSameHostUrl(rawUrl);
		try {
			const img = await this.loadHtmlImage(fallbackUrl, true);
			if (img && img.naturalWidth > 0) {
				return { img, naturalWidth: img.naturalWidth, naturalHeight: img.naturalHeight, taintedSafe: false };
			}
		} catch (e) {}
		return null;
	}

	static generateDesignKey() {
		if (typeof crypto !== 'undefined' && typeof crypto.randomUUID === 'function') {
			return `mrtee_${crypto.randomUUID()}`;
		}
		const rnd = () => Math.floor((1 + Math.random()) * 0x10000).toString(16).slice(1);
		return `mrtee_${rnd()}${rnd()}-${rnd()}-4${rnd().slice(1)}-${rnd()}-${rnd()}${rnd()}${rnd()}`;
	}

	static generateUploadId() {
		if (typeof crypto !== 'undefined' && typeof crypto.randomUUID === 'function') {
			return crypto.randomUUID().replace(/-/g, '').slice(0, 24);
		}
		return `${Date.now().toString(36)}_${Math.random().toString(36).slice(2, 12)}`;
	}

	static resolveAjaxUrl() {
		const data = (typeof window !== 'undefined' && window.mrTeeProductData) ? window.mrTeeProductData : {};
		const rawAjaxUrl = data.ajaxUrl || '/wp-admin/admin-ajax.php';
		try {
			const u = new URL(rawAjaxUrl, window.location.origin);
			if (u.hostname === window.location.hostname) {
				return u.pathname + u.search;
			}
		} catch (e) {}
		return rawAjaxUrl;
	}

	static dataUrlToBlob(dataUrl) {
		if (!dataUrl || typeof dataUrl !== 'string' || !dataUrl.includes(',')) {
			return null;
		}
		const commaIdx = dataUrl.indexOf(',');
		const header = dataUrl.slice(0, commaIdx);
		const body = dataUrl.slice(commaIdx + 1);
		const mimeMatch = header.match(/data:([^;]+)/i);
		const mime = mimeMatch ? mimeMatch[1] : 'application/octet-stream';

		if (header.includes(';base64')) {
			const binStr = atob(body);
			const len = binStr.length;
			const bytes = new Uint8Array(len);
			for (let i = 0; i < len; i++) {
				bytes[i] = binStr.charCodeAt(i);
			}
			return new Blob([bytes], { type: mime });
		}
		return new Blob([decodeURIComponent(body)], { type: mime });
	}

	static async uploadBlobInChunks(blob, { artifact, designKey = '', fileName = '' }) {
		if (!blob || blob.size === 0) {
			return null;
		}
		const data = (typeof window !== 'undefined' && window.mrTeeProductData) ? window.mrTeeProductData : {};
		const nonce = data.uploadNonce || '';
		if (!nonce) {
			throw new Error('Missing upload nonce');
		}

		const ajaxUrl = this.resolveAjaxUrl();
		const chunkSize = this.CHUNK_SIZE_BYTES;
		const totalChunks = Math.max(1, Math.ceil(blob.size / chunkSize));
		const uploadId = this.generateUploadId();
		let finalResult = null;

		for (let chunkIndex = 0; chunkIndex < totalChunks; chunkIndex++) {
			const start = chunkIndex * chunkSize;
			const end = Math.min(blob.size, start + chunkSize);
			const slice = blob.slice(start, end, blob.type || 'application/octet-stream');

			let attempt = 0;
			let okResp = null;
			while (attempt < 2 && !okResp) {
				attempt++;
				const formData = new FormData();
				formData.append('action', 'mr_tee_upload_design_artifact');
				formData.append('nonce', nonce);
				formData.append('artifact', artifact);
				formData.append('upload_id', uploadId);
				formData.append('chunk_index', String(chunkIndex));
				formData.append('total_chunks', String(totalChunks));
				if (designKey) formData.append('design_key', designKey);
				if (fileName) formData.append('file_name', fileName);
				formData.append('chunk_file', slice, fileName || `${artifact}.part`);

				const resp = await fetch(ajaxUrl, { method: 'POST', body: formData });
				if (!resp.ok) {
					if (attempt >= 2) {
						throw new Error(`Upload HTTP ${resp.status} (${artifact} chunk ${chunkIndex + 1}/${totalChunks})`);
					}
					continue;
				}
				const json = await resp.json();
				if (!json || !json.success) {
					const errMsg = (json && json.data && json.data.message) ? json.data.message : `Upload error (${artifact})`;
					throw new Error(errMsg);
				}
				okResp = json.data;
			}
			finalResult = okResp;
		}

		return finalResult;
	}

	static async ensureStoreImagesUploaded(store, config) {
		if (!store || !config || !Array.isArray(config.text_lines)) return;
		for (const line of config.text_lines) {
			if (line.field_type !== 'image' || !line.ref_id) continue;
			const ref = store.getReference(line.ref_id);
			if (!ref || typeof ref.url !== 'string' || !ref.url.startsWith('data:image/')) continue;

			const dataUrl = ref.url;
			const props = Array.isArray(ref.customProperties) ? ref.customProperties : [];
			const fileProp = props.find(p => p.key === 'fileName');
			const mimeMatch = dataUrl.match(/^data:image\/([a-z0-9+.-]+)/i);
			const rawExt = mimeMatch ? mimeMatch[1].toLowerCase() : 'png';
			const safeExt = rawExt === 'jpeg' ? 'jpg' : (rawExt.includes('svg') ? 'svg' : rawExt);
			const origName = (fileProp && fileProp.value) ? fileProp.value : `customer-image.${safeExt}`;
			const blob = this.dataUrlToBlob(dataUrl);
			if (!blob) continue;

			const res = await this.uploadBlobInChunks(blob, {
				artifact: 'customer_image',
				fileName: origName,
			});
			if (res && res.url) {
				if (typeof window !== 'undefined') {
					window.mrTeeImageB64Cache = window.mrTeeImageB64Cache || {};
					window.mrTeeImageB64Cache[res.url] = dataUrl;
					const normUrl = this.normalizeSameHostUrl(res.url);
					if (normUrl) window.mrTeeImageB64Cache[normUrl] = dataUrl;
				}
				store.setImageValue(line.ref_id, res.url, res.filename || origName, true);
			}
		}
	}

	/**
	 * Prepare complete print export assets, upload binary artifacts in chunks,
	 * and populate lightweight form inputs.
	 *
	 * @param {Array} layers Merged scene-graph layers.
	 * @param {Object} zoomTransform { scale, translateX, translateY }
	 * @param {Object} store State store instance.
	 * @param {Object} config Product configuration.
	 * @return {Promise<boolean>}
	 */
	static async prepareExport(layers, zoomTransform, store, config) {
		await this.ensureStoreImagesUploaded(store, config);

		if (store && store.state && typeof window !== 'undefined' && window.mrTeeSceneGraphEngine) {
			const merged = window.mrTeeSceneGraphEngine.getLayersMergedWithRefs(store.state.layers, store.state.references);
			layers = window.mrTeeSceneGraphEngine.applyRowCentering(merged, store.state.activeCount);
		}

		const isFlatObject = config.product_type === 'flat_object';
		const hasPerLineBoxes = Array.isArray(config.text_lines) && config.text_lines.some(l => l.box_left !== undefined && l.box_width !== undefined);
		const hasRepeaterOrClipart = Boolean((config.repeater && config.repeater.enabled) || (config.vector_clipart && config.vector_clipart.enabled));
		const realW  = parseFloat(config.print_real_width) || 30.0;
		const realH  = parseFloat(config.print_real_height) || 40.0;
		const unit   = config.print_unit || 'cm';
		const dpi    = parseInt(config.dpi, 10) || 300;

		let cropBoundingBox = null;
		let exportRealW = realW;
		let exportRealH = realH;
		let exportPxW = parseInt(config.print_width_px, 10) || 2400;
		let exportPxH = parseInt(config.print_height_px, 10) || 3000;
		let exportRatio = (realW > 0 && realH > 0) ? (realW / realH) : 1.0;

		if (!isFlatObject && hasPerLineBoxes && !hasRepeaterOrClipart) {
			let xMin = 100, yMin = 100, xMax = 0, yMax = 0;
			config.text_lines.forEach(l => {
				if (l.box_left !== undefined && l.box_width !== undefined) {
					const left = parseFloat(l.box_left) || 0;
					const top = parseFloat(l.box_top) || 0;
					const width = parseFloat(l.box_width) || 10;
					const height = parseFloat(l.box_height) || 10;
					const rot = parseFloat(l.box_rotate !== undefined ? l.box_rotate : (l.rotate !== undefined ? l.rotate : 0)) || 0;

					if (rot !== 0) {
						const rad = (rot * Math.PI) / 180;
						const cos = Math.abs(Math.cos(rad));
						const sin = Math.abs(Math.sin(rad));
						const refRatio = (realW > 0 && realH > 0) ? (realW / realH) : 1.0;
						const halfW = (width * cos + (height / refRatio) * sin) / 2;
						const halfH = ((width * refRatio) * sin + height * cos) / 2;
						const cx = left + (width / 2);
						const cy = top + (height / 2);

						xMin = Math.min(xMin, cx - halfW);
						yMin = Math.min(yMin, cy - halfH);
						xMax = Math.max(xMax, cx + halfW);
						yMax = Math.max(yMax, cy + halfH);
					} else {
						xMin = Math.min(xMin, left);
						yMin = Math.min(yMin, top);
						xMax = Math.max(xMax, left + width);
						yMax = Math.max(yMax, top + height);
					}
				}
			});

			const spanW = Math.max(1, xMax - xMin);
			const spanH = Math.max(1, yMax - yMin);

			exportRealW = Number(((spanW / 100) * realW).toFixed(2));
			exportRealH = Number(((spanH / 100) * realH).toFixed(2));

			const toInches = unit === 'mm' ? (1 / 25.4) : (unit === 'in' ? 1.0 : (1 / 2.54));
			exportPxW = Math.max(10, Math.round(exportRealW * toInches * dpi));
			exportPxH = Math.max(10, Math.round(exportRealH * toInches * dpi));
			exportRatio = exportRealH > 0 ? (exportRealW / exportRealH) : 1.0;

			cropBoundingBox = {
				xMin,
				yMin,
				xMax,
				yMax,
				spanW,
				spanH,
				cropRealW: exportRealW,
				cropRealH: exportRealH,
				cropPxW: exportPxW,
				cropPxH: exportPxH,
				cropRatio: exportRatio,
			};
		}

		// Resolve product background image before generating fullPreviewSvg so its exact
		// natural aspect ratio matches the live stage on un-locked garment products.
		const bgUrl = this.resolveProductBackgroundUrl();
		const bgAsset = await this.loadBackgroundImage(bgUrl);
		const domGarmentImg = this.findProductGalleryImageElement();
		const isLocked = Boolean(config.lock_aspect_ratio) || isFlatObject;

		let compositeRatio = (realW > 0 && realH > 0) ? (realW / realH) : (parseFloat(config.print_aspect_ratio) || 1.0);
		if (!isLocked) {
			if (bgAsset && bgAsset.naturalWidth > 0 && bgAsset.naturalHeight > 0) {
				compositeRatio = bgAsset.naturalWidth / bgAsset.naturalHeight;
			} else if (domGarmentImg && domGarmentImg.naturalWidth > 0 && domGarmentImg.naturalHeight > 0) {
				compositeRatio = domGarmentImg.naturalWidth / domGarmentImg.naturalHeight;
			}
		}

		// 1. Build self-contained, clean vector SVG markup (tightly cropped to cumulative design area)
		const svgMarkup = SvgRenderer.renderToString(layers, zoomTransform, {
			isExport: true,
			realWidth: exportRealW,
			realHeight: exportRealH,
			unit: unit,
			dpi: dpi,
			aspectRatio: exportRatio,
			hasPerLineBoxes: hasPerLineBoxes,
			cropBoundingBox: cropBoundingBox,
			fontFaceCss: this.extractFontFaceCss(),
		});

		// 2. Build full uncropped SVG for visual composite preview on mockup photo
		const fullPreviewSvg = SvgRenderer.renderToString(layers, zoomTransform, {
			isExport: false,
			aspectRatio: compositeRatio,
			hasPerLineBoxes: hasPerLineBoxes,
			fontFaceCss: this.extractFontFaceCss(),
		});

		// Inline external glyph, artwork images, and active @font-face font binaries for crisp 300 DPI SVG rasterization
		const inlinedExportSvg = await this.inlineExternalFonts(await this.inlineExternalImages(svgMarkup));
		const inlinedFullPreviewSvg = await this.inlineExternalFonts(await this.inlineExternalImages(fullPreviewSvg));

		// 3. Rasterize to 300 DPI Transparent PNG (tightly cropped, lossless print output)
		const print300DpiB64 = await this.rasterizeSvgToPng(inlinedExportSvg, exportPxW, exportPxH);

		// 4. Render Composite Mockup Preview (Full Product Background + Design) as compact JPEG
		const compositeB64 = await this.renderCompositePreview(inlinedFullPreviewSvg, config, {
			bgAsset,
			domGarmentImg,
			compositeRatio,
		});

		// 5. Serialize lightweight payload with print dimensions attached
		const payload = store.getExportPayload();
		payload.crop_print_width = exportRealW;
		payload.crop_print_height = exportRealH;
		payload.crop_width_px = exportPxW;
		payload.crop_height_px = exportPxH;
		payload.print_unit = unit;
		payload.dpi = dpi;
		const payloadJson = JSON.stringify(payload);

		const designKeyInput = document.getElementById('mr_tee_design_key');
		const payloadInput = document.getElementById('mr_tee_editor_payload');
		const previewInput = document.getElementById('mr_tee_preview_png');
		const svgInput = document.getElementById('mr_tee_vector_svg');
		const printInput = document.getElementById('mr_tee_print_300dpi_png');

		const exportSvgText = inlinedExportSvg || svgMarkup;
		const designKey = this.generateDesignKey();
		let uploadedToServer = false;

		try {
			const previewBlob = this.dataUrlToBlob(compositeB64);
			const previewArtifact = (compositeB64 && compositeB64.includes('image/jpeg')) ? 'preview_jpg' : 'preview_png';
			const svgBlob = exportSvgText ? new Blob([exportSvgText], { type: 'image/svg+xml;charset=utf-8' }) : null;
			const printBlob = this.dataUrlToBlob(print300DpiB64);

			const tasks = [];
			if (previewBlob) tasks.push(this.uploadBlobInChunks(previewBlob, { artifact: previewArtifact, designKey }));
			if (svgBlob) tasks.push(this.uploadBlobInChunks(svgBlob, { artifact: 'svg', designKey }));
			if (printBlob) tasks.push(this.uploadBlobInChunks(printBlob, { artifact: 'print_png', designKey }));

			await Promise.all(tasks);
			uploadedToServer = true;
		} catch (uploadErr) {
			const approxBytes = payloadJson.length + (compositeB64 || '').length + exportSvgText.length + (print300DpiB64 || '').length;
			if (approxBytes >= 180 * 1024) {
				throw uploadErr;
			}
			console.warn('[MrTee PrintExporter] Chunk upload failed on small payload, using inline fallback:', uploadErr);
		}

		if (uploadedToServer) {
			if (designKeyInput) designKeyInput.value = designKey;
			if (payloadInput) payloadInput.value = payloadJson;
			if (previewInput) previewInput.value = '';
			if (svgInput) svgInput.value = '';
			if (printInput) printInput.value = '';
		} else {
			if (designKeyInput) designKeyInput.value = '';
			if (payloadInput) payloadInput.value = payloadJson;
			if (previewInput) previewInput.value = compositeB64;
			if (svgInput) svgInput.value = exportSvgText;
			if (printInput) printInput.value = print300DpiB64;
		}

		return true;
	}

	/**
	 * Convert external image URLs inside an SVG to base64 data URIs so that HTML5 canvas
	 * can draw the SVG without cross-origin or blob-tainting restrictions.
	 *
	 * @param {string} svgMarkup SVG XML markup.
	 * @return {Promise<string>} Inlined SVG XML markup.
	 */
	static async inlineExternalImages(svgMarkup) {
		if (!svgMarkup) return '';
		const matches = [...svgMarkup.matchAll(/(xlink:href|href)="([^"]+)"/g)];
		const urlsToFetch = [];
		for (const m of matches) {
			const url = m[2];
			if (url.startsWith('http://') || url.startsWith('https://') || url.startsWith('/')) {
				if (!urlsToFetch.includes(url)) {
					urlsToFetch.push(url);
				}
			}
		}

		if (urlsToFetch.length === 0) {
			return svgMarkup;
		}

		const urlMap = {};
		await Promise.all(urlsToFetch.map(async (url) => {
			const cleanUrl = url.replace(/&amp;/g, '&');
			const b64 = await this.fetchImageAsDataUrl(cleanUrl);
			if (b64) {
				urlMap[url] = b64;
			}
		}));

		let inlined = svgMarkup;
		for (const [url, b64] of Object.entries(urlMap)) {
			inlined = inlined.split(url).join(b64);
		}
		return inlined;
	}

	/**
	 * Determine canonical font MIME type from a font file URL.
	 *
	 * @param {string} url Font resource URL.
	 * @return {string}
	 */
	static getFontMimeType(url) {
		const clean = String(url || '').split('?')[0].split('#')[0].toLowerCase();
		if (clean.endsWith('.woff2')) return 'font/woff2';
		if (clean.endsWith('.woff')) return 'font/woff';
		if (clean.endsWith('.otf')) return 'font/otf';
		return 'font/ttf';
	}

	/**
	 * Fetch a font binary once and return a cached base64 data URI with explicit font MIME type.
	 *
	 * @param {string} rawUrl Font file URL.
	 * @return {Promise<string>}
	 */
	static async fetchFontAsDataUrl(rawUrl) {
		if (!rawUrl || typeof rawUrl !== 'string') return '';
		if (rawUrl.startsWith('data:')) {
			return rawUrl;
		}
		const fetchUrl = this.normalizeSameHostUrl(rawUrl);
		if (typeof window !== 'undefined') {
			window.mrTeeFontB64Cache = window.mrTeeFontB64Cache || {};
			if (window.mrTeeFontB64Cache[rawUrl]) {
				return window.mrTeeFontB64Cache[rawUrl];
			}
			if (window.mrTeeFontB64Cache[fetchUrl]) {
				window.mrTeeFontB64Cache[rawUrl] = window.mrTeeFontB64Cache[fetchUrl];
				return window.mrTeeFontB64Cache[fetchUrl];
			}
		}
		try {
			const ctrl = typeof AbortController !== 'undefined' ? new AbortController() : null;
			const timer = ctrl ? setTimeout(() => ctrl.abort(), 12000) : null;
			const resp = await fetch(fetchUrl, ctrl ? { signal: ctrl.signal } : undefined);
			if (timer) clearTimeout(timer);
			if (resp.ok) {
				const buf = await resp.arrayBuffer();
				const typedBlob = new Blob([buf], { type: this.getFontMimeType(fetchUrl) });
				const b64 = await new Promise((res, rej) => {
					const reader = new FileReader();
					reader.onloadend = () => res(reader.result);
					reader.onerror = rej;
					reader.readAsDataURL(typedBlob);
				});
				if (typeof window !== 'undefined' && b64) {
					window.mrTeeFontB64Cache[rawUrl] = b64;
					if (fetchUrl !== rawUrl) window.mrTeeFontB64Cache[fetchUrl] = b64;
				}
				return b64 || '';
			}
		} catch (e) {
			console.warn('[MrTee PrintExporter] Failed to fetch font URL:', rawUrl, e);
		}
		return '';
	}

	/**
	 * Inline active @font-face font binaries referenced by SVG nodes so standalone SVGs and
	 * Blob-rendered rasterization canvases display exact custom TTF/OTF typography.
	 *
	 * @param {string} svgMarkup SVG XML markup.
	 * @return {Promise<string>}
	 */
	static async inlineExternalFonts(svgMarkup) {
		if (!svgMarkup) return '';

		const markupWithoutStyles = svgMarkup.replace(/<style\b[^>]*>[\s\S]*?<\/style>/gi, '');
		const usedFamilies = new Set();
		const decodeEntities = (val) => String(val || '')
			.replace(/&apos;|&#0*39;/gi, "'")
			.replace(/&quot;/gi, '"')
			.replace(/&amp;/gi, '&');

		const addFamiliesFromList = (rawList) => {
			if (!rawList) return;
			const decoded = decodeEntities(rawList);
			for (const part of decoded.split(',')) {
				const cleaned = part.trim().replace(/^['"]+|['"]+$/g, '').trim().toLowerCase();
				if (cleaned) {
					usedFamilies.add(cleaned);
				}
			}
		};

		for (const m of markupWithoutStyles.matchAll(/\bfont-family\s*=\s*(?:"([^"]*)"|'([^']*)')/gi)) {
			addFamiliesFromList(m[1] !== undefined ? m[1] : m[2]);
		}
		for (const m of markupWithoutStyles.matchAll(/\bstyle\s*=\s*(?:"([^"]*)"|'([^']*)')/gi)) {
			const styleStr = decodeEntities(m[1] !== undefined ? m[1] : m[2]);
			for (const fm of styleStr.matchAll(/\bfont-family\s*:\s*([^;]+)/gi)) {
				addFamiliesFromList(fm[1]);
			}
		}

		if (usedFamilies.size === 0) {
			return svgMarkup;
		}

		const fontUrls = [];
		for (const sm of svgMarkup.matchAll(/<style\b[^>]*>([\s\S]*?)<\/style>/gi)) {
			const cssBody = sm[1];
			for (const ruleMatch of cssBody.matchAll(/@font-face\s*\{([^}]*)\}/gi)) {
				const ruleBody = ruleMatch[1];
				const famMatch = ruleBody.match(/font-family\s*:\s*(?:'([^']*)'|"([^"]*)"|([^;]+))/i);
				if (!famMatch) continue;
				const famName = decodeEntities(famMatch[1] || famMatch[2] || famMatch[3] || '')
					.trim()
					.replace(/^['"]+|['"]+$/g, '')
					.trim()
					.toLowerCase();
				if (!famName || !usedFamilies.has(famName)) continue;

				for (const urlMatch of ruleBody.matchAll(/url\(\s*(?:['"]([^'"]+)['"]|([^)\s'"]+))\s*\)/gi)) {
					const rawUrl = (urlMatch[1] || urlMatch[2] || '').trim();
					if (rawUrl && !rawUrl.startsWith('data:') && !fontUrls.includes(rawUrl)) {
						fontUrls.push(rawUrl);
					}
				}
			}
		}

		if (fontUrls.length === 0) {
			return svgMarkup;
		}

		const fontMap = {};
		await Promise.all(fontUrls.map(async (u) => {
			const cleanUrl = decodeEntities(u);
			const b64 = await this.fetchFontAsDataUrl(cleanUrl);
			if (b64) {
				fontMap[u] = b64;
			}
		}));

		if (Object.keys(fontMap).length === 0) {
			return svgMarkup;
		}

		return svgMarkup.replace(/<style\b([^>]*)>([\s\S]*?)<\/style>/gi, (fullStyle, attrs, cssBody) => {
			const rewrittenCss = cssBody.replace(/@font-face\s*\{([^}]*)\}/gi, (ruleFull, ruleBody) => {
				const famMatch = ruleBody.match(/font-family\s*:\s*(?:'([^']*)'|"([^"]*)"|([^;]+))/i);
				if (!famMatch) return ruleFull;
				const famName = decodeEntities(famMatch[1] || famMatch[2] || famMatch[3] || '')
					.trim()
					.replace(/^['"]+|['"]+$/g, '')
					.trim()
					.toLowerCase();
				if (!famName || !usedFamilies.has(famName)) {
					return ruleFull;
				}
				return ruleFull.replace(/url\(\s*(?:(['"])([^'"]+)\1|([^)\s'"]+))\s*\)/gi, (um, q, quotedUrl, bareUrl) => {
					const rawUrl = (quotedUrl || bareUrl || '').trim();
					if (fontMap[rawUrl]) {
						return `url('${fontMap[rawUrl]}')`;
					}
					return um;
				});
			});
			return `<style${attrs}>${rewrittenCss}</style>`;
		});
	}

	/**
	 * Rasterize SVG markup onto a high-resolution transparent HTML5 canvas.
	 *
	 * @param {string} svgMarkup SVG XML markup.
	 * @param {number} width Canvas width in pixels (e.g. 2400).
	 * @param {number} height Canvas height in pixels (e.g. 3000).
	 * @return {Promise<string>} Base64 data URL.
	 */
	static rasterizeSvgToPng(svgMarkup, width, height) {
		return new Promise((resolve, reject) => {
			const blob = new Blob([svgMarkup], { type: 'image/svg+xml;charset=utf-8' });
			const url = URL.createObjectURL(blob);
			const img = new Image();
			let settled = false;

			const timer = setTimeout(() => {
				if (!settled) {
					settled = true;
					URL.revokeObjectURL(url);
					reject(new Error('SVG rasterization timeout'));
				}
			}, 25000);

			img.onload = () => {
				if (settled) return;
				settled = true;
				clearTimeout(timer);
				try {
					const canvas = document.createElement('canvas');
					canvas.width = width;
					canvas.height = height;
					const ctx = canvas.getContext('2d');

					ctx.clearRect(0, 0, width, height);
					ctx.drawImage(img, 0, 0, width, height);
					URL.revokeObjectURL(url);

					resolve(canvas.toDataURL('image/png'));
				} catch (err) {
					URL.revokeObjectURL(url);
					reject(err);
				}
			};

			img.onerror = (err) => {
				if (settled) return;
				settled = true;
				clearTimeout(timer);
				URL.revokeObjectURL(url);
				reject(err);
			};

			img.src = url;
		});
	}

	/**
	 * Render composite mockup image (garment photo + overlay design) as a lightweight high-quality JPEG.
	 *
	 * @param {string} svgMarkup SVG markup.
	 * @param {Object} config Product configuration.
	 * @param {Object} options Optional preloaded background assets ({ bgAsset, domGarmentImg, compositeRatio }).
	 * @return {Promise<string>} Base64 composite image data URL.
	 */
	static async renderCompositePreview(svgMarkup, config, options = {}) {
		const bgAsset = options.bgAsset !== undefined ? options.bgAsset : await this.loadBackgroundImage(this.resolveProductBackgroundUrl());
		const domGarmentImg = options.domGarmentImg || this.findProductGalleryImageElement();

		const isFlatObject = config.product_type === 'flat_object';
		const isLocked = Boolean(config.lock_aspect_ratio) || isFlatObject;
		const hasPerLineBoxes = Array.isArray(config.text_lines) && config.text_lines.some(l => l.box_left !== undefined && l.box_width !== undefined);
		const realRatio = (parseFloat(config.print_real_width) && parseFloat(config.print_real_height))
			? (parseFloat(config.print_real_width) / parseFloat(config.print_real_height))
			: (parseFloat(config.print_aspect_ratio) || 1.0);

		let compositeRatio = parseFloat(options.compositeRatio) || 0;
		if (!compositeRatio || compositeRatio <= 0) {
			if (isLocked) {
				compositeRatio = realRatio;
			} else if (bgAsset && bgAsset.naturalWidth > 0 && bgAsset.naturalHeight > 0) {
				compositeRatio = bgAsset.naturalWidth / bgAsset.naturalHeight;
			} else if (domGarmentImg && domGarmentImg.naturalWidth > 0 && domGarmentImg.naturalHeight > 0) {
				compositeRatio = domGarmentImg.naturalWidth / domGarmentImg.naturalHeight;
			} else {
				compositeRatio = realRatio || 1.0;
			}
		}

		const canvasW = 900;
		const canvasH = Math.max(240, Math.min(1800, Math.round(canvasW / compositeRatio)));
		const canvas = document.createElement('canvas');
		canvas.width = canvasW;
		canvas.height = canvasH;
		const ctx = canvas.getContext('2d');

		// Solid white backdrop for JPEG export
		ctx.fillStyle = '#ffffff';
		ctx.fillRect(0, 0, canvasW, canvasH);

		const bgSource = (bgAsset && bgAsset.img) ||
			((domGarmentImg && domGarmentImg.complete && domGarmentImg.naturalWidth > 0) ? domGarmentImg : null);

		const drawBackground = (targetCtx) => {
			if (!bgSource) return false;
			if (!isLocked) {
				targetCtx.drawImage(bgSource, 0, 0, canvasW, canvasH);
				return true;
			}
			const bgNatW = bgSource.naturalWidth || canvasW;
			const bgNatH = bgSource.naturalHeight || canvasH;
			const bgRatio = bgNatW / bgNatH;
			const targetRatio = canvasW / canvasH;
			let drawW = canvasW;
			let drawH = canvasH;
			let drawX = 0;
			let drawY = 0;
			if (Math.abs(bgRatio - targetRatio) > 0.002) {
				if (bgRatio > targetRatio) {
					drawW = canvasW;
					drawH = canvasW / bgRatio;
					drawY = (canvasH - drawH) / 2;
				} else {
					drawH = canvasH;
					drawW = canvasH * bgRatio;
					drawX = (canvasW - drawW) / 2;
				}
			}
			targetCtx.drawImage(bgSource, drawX, drawY, drawW, drawH);
			return true;
		};

		return new Promise((resolve) => {
			let settled = false;
			const finish = (val) => {
				if (!settled) {
					settled = true;
					clearTimeout(safetyTimer);
					resolve(val);
				}
			};
			const safetyTimer = setTimeout(() => {
				try {
					finish(canvas.toDataURL('image/jpeg', 0.88));
				} catch (e) {
					finish('');
				}
			}, 15000);

			const drawDesign = () => {
				const blob = new Blob([svgMarkup], { type: 'image/svg+xml;charset=utf-8' });
				const url = URL.createObjectURL(blob);
				const designImg = new Image();

				const getPlacement = () => {
					if (isFlatObject || hasPerLineBoxes) {
						return { left: 0, top: 0, w: canvasW, h: canvasH };
					}
					return {
						left: (parseFloat(config.box_left) || 26.0) / 100 * canvasW,
						top: (parseFloat(config.box_top) || 23.0) / 100 * canvasH,
						w: (parseFloat(config.box_width) || 48.0) / 100 * canvasW,
						h: (parseFloat(config.box_height) || 54.0) / 100 * canvasH,
					};
				};

				designImg.onload = () => {
					const { left, top, w, h } = getPlacement();
					try {
						ctx.drawImage(designImg, left, top, w, h);
						const dataUrl = canvas.toDataURL('image/jpeg', 0.88);
						URL.revokeObjectURL(url);
						finish(dataUrl);
					} catch (err) {
						// Recover from tainted canvas if background came from cross-origin DOM element
						try {
							const cleanCanvas = document.createElement('canvas');
							cleanCanvas.width = canvasW;
							cleanCanvas.height = canvasH;
							const cleanCtx = cleanCanvas.getContext('2d');
							cleanCtx.fillStyle = '#ffffff';
							cleanCtx.fillRect(0, 0, canvasW, canvasH);
							cleanCtx.drawImage(designImg, left, top, w, h);
							const cleanUrl = cleanCanvas.toDataURL('image/jpeg', 0.88);
							URL.revokeObjectURL(url);
							finish(cleanUrl);
						} catch (fallbackErr) {
							URL.revokeObjectURL(url);
							finish('');
						}
					}
				};

				designImg.onerror = () => {
					URL.revokeObjectURL(url);
					try {
						finish(canvas.toDataURL('image/jpeg', 0.88));
					} catch (e) {
						finish('');
					}
				};

				designImg.src = url;
			};

			if (bgSource) {
				try {
					drawBackground(ctx);
					drawDesign();
				} catch (e) {
					drawDesign();
				}
			} else {
				drawDesign();
			}
		});
	}

	/**
	 * Extract lightweight @font-face CSS rules from page stylesheets, strictly excluding giant base64 bloat.
	 *
	 * @return {string}
	 */
	static extractFontFaceCss() {
		if (typeof window !== 'undefined' && window.mrTeeProductData && window.mrTeeProductData.fontFaceCss) {
			const css = window.mrTeeProductData.fontFaceCss;
			if (!css.includes('data:font') || css.length < 50000) {
				return css;
			}
		}

		let css = '';
		try {
			for (const sheet of document.styleSheets) {
				try {
					const rules = sheet.cssRules || sheet.rules;
					for (const rule of rules) {
						if (rule.type === CSSRule.FONT_FACE_RULE) {
							// Filter out rules with massive embedded base64 to keep SVG payload lightweight
							if (!rule.cssText.includes('data:font') || rule.cssText.length < 5000) {
								css += rule.cssText + '\n';
							}
						}
					}
				} catch (e) {
					// Cross-origin stylesheet access restriction - continue
				}
			}
		} catch (err) {
			console.warn('[MrTee PrintExporter] extractFontFaceCss error:', err);
		}
		return css;
	}
}
