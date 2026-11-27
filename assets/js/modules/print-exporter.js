/**
 * Mr. Tee Editor - Print Exporter Module
 *
 * Preloads fonts and external images as base64 data URIs and rasterizes
 * the scene-graph to 300 DPI transparent print PNG (2400x3000px) and vector SVG.
 *
 * @package MrTeeEditor\Assets\Modules
 */

import { SvgRenderer } from './svg-renderer.js?v=2.5.9';

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

	static isSameOriginImage(imgEl) {
		if (!imgEl || !imgEl.complete || imgEl.naturalWidth <= 2 || imgEl.naturalHeight <= 2) return false;
		const src = imgEl.currentSrc || imgEl.getAttribute('src') || '';
		if (!src || src.startsWith('data:image/svg+xml') || src.startsWith('data:image/gif')) return false;
		if (src.startsWith('data:')) return true;
		try {
			const parsed = new URL(src, window.location.origin);
			return parsed.origin === window.location.origin;
		} catch (e) {
			return false;
		}
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
			const resp = await fetch(fetchUrl, ctrl ? { signal: ctrl.signal, cache: 'force-cache' } : { cache: 'force-cache' });
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

	static async getThumbnailImageDataUrl(rawUrl, maxDim = 420) {
		if (!rawUrl || typeof rawUrl !== 'string') return '';
		const normUrl = this.normalizeSameHostUrl(rawUrl);
		if (typeof window !== 'undefined') {
			window.mrTeeThumbB64Cache = window.mrTeeThumbB64Cache || {};
			if (!rawUrl.startsWith('data:')) {
				if (window.mrTeeThumbB64Cache[rawUrl]) {
					return window.mrTeeThumbB64Cache[rawUrl];
				}
				if (normUrl && window.mrTeeThumbB64Cache[normUrl]) {
					return window.mrTeeThumbB64Cache[normUrl];
				}
			}

			// Fast path: reuse already-decoded same-origin HTMLImageElement from live stage prewarm cache
			if (window.mrTeePrewarmedImages && typeof window.mrTeePrewarmedImages.get === 'function') {
				const preImg = window.mrTeePrewarmedImages.get(rawUrl) || (normUrl ? window.mrTeePrewarmedImages.get(normUrl) : null);
				if (preImg && this.isSameOriginImage(preImg)) {
					try {
						const natW = preImg.naturalWidth || maxDim;
						const natH = preImg.naturalHeight || maxDim;
						const longest = Math.max(natW, natH);
						const scale = longest > maxDim ? (maxDim / longest) : 1.0;
						const canvas = document.createElement('canvas');
						canvas.width = Math.max(1, Math.round(natW * scale));
						canvas.height = Math.max(1, Math.round(natH * scale));
						const ctx = canvas.getContext('2d');
						if (ctx) {
							ctx.clearRect(0, 0, canvas.width, canvas.height);
							ctx.drawImage(preImg, 0, 0, canvas.width, canvas.height);
							const cleanPath = String(normUrl || rawUrl).split('?')[0].toLowerCase();
							const needsAlpha = cleanPath.endsWith('.png') || cleanPath.endsWith('.webp');
							const fastDataUrl = needsAlpha ? canvas.toDataURL('image/png') : canvas.toDataURL('image/jpeg', 0.82);
							if (fastDataUrl && fastDataUrl.startsWith('data:image/')) {
								window.mrTeeThumbB64Cache[rawUrl] = fastDataUrl;
								if (normUrl) window.mrTeeThumbB64Cache[normUrl] = fastDataUrl;
								return fastDataUrl;
							}
						}
					} catch (e) {
						// Fall through to standard fetch path if canvas read is blocked
					}
				}
			}
		}

		const fullDataUrl = await this.fetchImageAsDataUrl(rawUrl);
		if (!fullDataUrl) return '';
		if (fullDataUrl.length <= 80 * 1024 || fullDataUrl.startsWith('data:image/svg+xml')) {
			if (typeof window !== 'undefined' && !rawUrl.startsWith('data:')) {
				window.mrTeeThumbB64Cache[rawUrl] = fullDataUrl;
				if (normUrl) window.mrTeeThumbB64Cache[normUrl] = fullDataUrl;
			}
			return fullDataUrl;
		}

		try {
			const img = await this.loadHtmlImage(fullDataUrl, false);
			const natW = img.naturalWidth || maxDim;
			const natH = img.naturalHeight || maxDim;
			const longest = Math.max(natW, natH);
			let resultUrl = fullDataUrl;
			if (longest > maxDim) {
				const scale = maxDim / longest;
				const canvas = document.createElement('canvas');
				canvas.width = Math.max(1, Math.round(natW * scale));
				canvas.height = Math.max(1, Math.round(natH * scale));
				const ctx = canvas.getContext('2d');
				ctx.clearRect(0, 0, canvas.width, canvas.height);
				ctx.drawImage(img, 0, 0, canvas.width, canvas.height);
				const hasAlphaMime = fullDataUrl.startsWith('data:image/png') || fullDataUrl.startsWith('data:image/webp');
				resultUrl = hasAlphaMime ? canvas.toDataURL('image/png') : canvas.toDataURL('image/jpeg', 0.80);
			}
			if (typeof window !== 'undefined' && !rawUrl.startsWith('data:')) {
				window.mrTeeThumbB64Cache[rawUrl] = resultUrl;
				if (normUrl) window.mrTeeThumbB64Cache[normUrl] = resultUrl;
			}
			return resultUrl;
		} catch (e) {
			return fullDataUrl;
		}
	}

	static findProductGalleryImageElement(viewId = null) {
		if (viewId) {
			const galleryRoot = document.getElementById('mr-tee-gallery-root');
			if (galleryRoot) {
				const slides = galleryRoot.querySelectorAll('.mr-tee-slide[data-view-id]');
				for (const slide of slides) {
					if (slide.getAttribute('data-view-id') === String(viewId)) {
						const img = slide.querySelector('img.mr-tee-slide-img') || slide.querySelector('img:not(.emoji):not(.zoomImg)');
						if (img) return img;
					}
				}
				return null;
			}
			// Legacy gallery fallback when #mr-tee-gallery-root is absent:
			// preserve explicit distinct secondary view images, while letting primary view fall through
			// to the active legacy gallery image so WooCommerce variation swaps are respected.
			const pData = (typeof window !== 'undefined' && window.mrTeeProductData) ? window.mrTeeProductData : null;
			const pViews = (pData && pData.config && Array.isArray(pData.config.preview_views)) ? pData.config.preview_views : [];
			const viewIdx = pViews.findIndex(v => v && String(v.id) === String(viewId));
			if (viewIdx > 0 && pViews[viewIdx] && pViews[viewIdx].image_url) {
				return null;
			}
		}

		const selectors = [
			'#mr-tee-gallery-root .mr-tee-slide.is-active img.mr-tee-slide-img',
			'#mr-tee-gallery-root img.mr-tee-slide-img',
			'.woocommerce-product-gallery .flex-active-slide img:not(.emoji):not(.zoomImg)',
			'.woocommerce-product-gallery__image.flex-active-slide img:not(.emoji):not(.zoomImg)',
			'.product-gallery-slider .slide.is-selected img:not(.emoji):not(.zoomImg)',
			'.flickity-slider > .slide.is-selected img:not(.emoji):not(.zoomImg)',
			'.product-gallery-slider .woocommerce-product-gallery__image.is-selected img:not(.emoji):not(.zoomImg)',
			'.wc-block-product-gallery-large-image img:not(.emoji):not(.zoomImg)',
			'.wc-block-components-product-image img:not(.emoji):not(.zoomImg)',
			'.woocommerce-product-gallery__image:first-child img:not(.emoji):not(.zoomImg)',
			'.product-gallery-slider .slide:first-child img:not(.emoji):not(.zoomImg)',
			'.mr-tee-stage-garment-img',
			'.woocommerce-product-gallery img:not(.emoji):not(.zoomImg)',
			'.product .images img:not(.emoji):not(.zoomImg)',
		];
		for (const sel of selectors) {
			const candidates = document.querySelectorAll(sel);
			for (const el of candidates) {
				if (el && !el.closest('.easyzoom-flyout')) {
					return el;
				}
			}
		}
		return null;
	}

	static resolveProductBackgroundUrl(viewId = null) {
		const imgEl = this.findProductGalleryImageElement(viewId);
		if (imgEl) {
			// Prefer already-sized single-product image (~800px) over multi-MB full-res master for 800px cart composites
			const candidates = [
				imgEl.currentSrc,
				imgEl.getAttribute('src'),
				imgEl.getAttribute('data-lazy-src'),
				imgEl.getAttribute('data-src'),
				imgEl.getAttribute('data-large_image'),
			];
			for (const cand of candidates) {
				if (cand && !cand.startsWith('data:image/svg+xml') && !cand.startsWith('data:image/gif')) {
					return cand;
				}
			}
		}
		const data = (typeof window !== 'undefined' && window.mrTeeProductData) ? window.mrTeeProductData : {};
		if (viewId && data.config && Array.isArray(data.config.preview_views)) {
			const matched = data.config.preview_views.find(v => v && v.id === String(viewId) && v.image_url);
			if (matched) return matched.image_url;
		}
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
		const normUrl = this.normalizeSameHostUrl(rawUrl);
		// Fast path: same-origin relative URLs decode directly via native browser image cache without canvas tainting
		if (normUrl && (normUrl.startsWith('/') || normUrl.startsWith('data:'))) {
			try {
				const directImg = await this.loadHtmlImage(normUrl, false);
				if (directImg && directImg.naturalWidth > 0) {
					return { img: directImg, naturalWidth: directImg.naturalWidth, naturalHeight: directImg.naturalHeight, taintedSafe: true };
				}
			} catch (e) {}
		}
		const dataUrl = await this.fetchImageAsDataUrl(rawUrl);
		if (dataUrl) {
			try {
				const img = await this.loadHtmlImage(dataUrl, false);
				if (img && img.naturalWidth > 0) {
					return { img, naturalWidth: img.naturalWidth, naturalHeight: img.naturalHeight, taintedSafe: true };
				}
			} catch (e) {}
		}
		try {
			const img = await this.loadHtmlImage(normUrl, true);
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
		const data = (typeof window !== 'undefined' && (window.mrTeeProductData || window.mrTeeOrderExportData || window.mrTeeAdminData)) || {};
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

	static async uploadBlobInChunks(blob, { artifact, designKey = '', fileName = '', nonce = '', suffix = '', isPrimary = false }) {
		if (!blob || blob.size === 0) {
			return null;
		}
		const productData = (typeof window !== 'undefined' && window.mrTeeProductData) ? window.mrTeeProductData : {};
		const orderExportData = (typeof window !== 'undefined' && window.mrTeeOrderExportData) ? window.mrTeeOrderExportData : {};
		const adminData = (typeof window !== 'undefined' && window.mrTeeAdminData) ? window.mrTeeAdminData : {};
		const resolvedNonce = nonce || productData.uploadNonce || orderExportData.nonce || adminData.nonce || '';
		if (!resolvedNonce) {
			throw new Error('Missing upload nonce');
		}

		const ajaxUrl = this.resolveAjaxUrl();
		const chunkSize = this.CHUNK_SIZE_BYTES;
		const totalChunks = Math.max(1, Math.ceil(blob.size / chunkSize));
		const uploadId = this.generateUploadId();
		const maxAttempts = 3;
		let finalResult = null;

		for (let chunkIndex = 0; chunkIndex < totalChunks; chunkIndex++) {
			const start = chunkIndex * chunkSize;
			const end = Math.min(blob.size, start + chunkSize);
			const slice = blob.slice(start, end, blob.type || 'application/octet-stream');

			let attempt = 0;
			let okResp = null;
			let lastErr = null;
			while (attempt < maxAttempts && !okResp) {
				attempt++;
				const formData = new FormData();
				formData.append('action', 'mr_tee_upload_design_artifact');
				formData.append('nonce', resolvedNonce);
				formData.append('artifact', artifact);
				formData.append('upload_id', uploadId);
				formData.append('chunk_index', String(chunkIndex));
				formData.append('total_chunks', String(totalChunks));
				if (designKey) formData.append('design_key', designKey);
				if (fileName) formData.append('file_name', fileName);
				if (suffix) formData.append('suffix', String(suffix));
				if (isPrimary) formData.append('is_primary', '1');
				formData.append('chunk_file', slice, fileName || `${artifact}.part`);

				const ctrl = typeof AbortController !== 'undefined' ? new AbortController() : null;
				const timer = ctrl ? setTimeout(() => ctrl.abort(), 30000) : null;
				try {
					const resp = await fetch(ajaxUrl, ctrl ? { method: 'POST', body: formData, signal: ctrl.signal } : { method: 'POST', body: formData });
					if (timer) clearTimeout(timer);

					const rawText = await resp.text();
					let json = null;
					try {
						json = rawText ? JSON.parse(rawText) : null;
					} catch (parseErr) {
						json = null;
					}

					if (!resp.ok) {
						if (json && json.success === false && json.data && json.data.message && resp.status >= 400 && resp.status < 500 && resp.status !== 408 && resp.status !== 429) {
							throw new Error(json.data.message);
						}
						if (rawText.trim() === '-1' || resp.status === 403) {
							throw new Error('Session expirée ou accès refusé. Rechargez la page et réessayez.');
						}
						lastErr = new Error(`Upload HTTP ${resp.status} (${artifact} chunk ${chunkIndex + 1}/${totalChunks})`);
					} else if (!json) {
						if (rawText.trim() === '-1') {
							throw new Error('Session expirée (nonce invalide). Rechargez la page et réessayez.');
						}
						lastErr = new Error(`Réponse serveur invalide (${artifact} chunk ${chunkIndex + 1}/${totalChunks})`);
					} else if (!json.success) {
						const errMsg = (json.data && json.data.message) ? json.data.message : `Upload error (${artifact})`;
						throw new Error(errMsg);
					} else {
						okResp = json.data;
					}
				} catch (err) {
					if (timer) clearTimeout(timer);
					const isAbort = err && (err.name === 'AbortError' || String(err.message || '').includes('aborted'));
					const isNetwork = isAbort || (err instanceof TypeError);
					if (!isNetwork) {
						throw err;
					}
					lastErr = isAbort
						? new Error(`Délai dépassé lors de l'envoi (${artifact} chunk ${chunkIndex + 1}/${totalChunks})`)
						: err;
				}

				if (!okResp && attempt < maxAttempts) {
					await new Promise((r) => setTimeout(r, 350 * attempt));
				}
			}

			if (!okResp) {
				throw lastErr || new Error(`Upload failed (${artifact} chunk ${chunkIndex + 1}/${totalChunks})`);
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
	 * Compute cropped physical & 300 DPI print dimensions for a specific print side
	 * using that side's `print_sides` dimensions and its reference `full` view placements.
	 * Prefers order-time snapshot metadata inside `editorData.payload` over live product `config`.
	 *
	 * @param {Object}      config     Product configuration.
	 * @param {Object|null} editorData Optional saved order item _mr_tee_editor_data snapshot.
	 * @param {string|null} sideId     Target print side ID (defaults to primary side / 'front').
	 * @return {Object} Computed print metrics & optional cropBoundingBox.
	 */
	static computeSideExportMetrics(config = {}, editorData = null, sideId = null) {
		const payload = (editorData && editorData.payload) ? editorData.payload : {};
		const printSides = (Array.isArray(payload.print_sides) && payload.print_sides.length > 0)
			? payload.print_sides
			: (Array.isArray(config.print_sides) ? config.print_sides : []);
		const previewViews = (Array.isArray(payload.preview_views) && payload.preview_views.length > 0)
			? payload.preview_views
			: (Array.isArray(config.preview_views) ? config.preview_views : []);

		const primarySideId = (printSides.length > 0 && printSides[0] && printSides[0].id) ? String(printSides[0].id) : 'front';
		const targetSideId = sideId ? String(sideId) : primarySideId;
		const isPrimarySide = (targetSideId === primarySideId);
		const sideDef = printSides.find(s => s && String(s.id) === targetSideId) || null;

		const sideViews = previewViews.filter(v => v && String(v.side_id || 'front') === targetSideId);
		const refView = sideViews.find(v => v.view_role === 'full') || sideViews[0] || null;
		const refViewId = refView && refView.id ? String(refView.id) : null;

		const isFlatObject = ((sideDef && sideDef.product_type) || config.product_type || (editorData && editorData.product_type)) === 'flat_object';
		const lockAspectRatio = (sideDef && sideDef.lock_aspect_ratio !== undefined)
			? Boolean(sideDef.lock_aspect_ratio)
			: Boolean(config.lock_aspect_ratio);

		const realW = (sideDef && parseFloat(sideDef.print_real_width))
			|| (editorData && parseFloat(editorData.ref_real_width))
			|| parseFloat(config.print_real_width)
			|| 30.0;
		const realH = (sideDef && parseFloat(sideDef.print_real_height))
			|| (editorData && parseFloat(editorData.ref_real_height))
			|| parseFloat(config.print_real_height)
			|| 40.0;
		const unit = (sideDef && sideDef.print_unit)
			|| (payload && payload.print_unit)
			|| (editorData && editorData.print_unit)
			|| config.print_unit
			|| 'cm';
		const dpi = parseInt((sideDef && sideDef.dpi) || (payload && payload.dpi) || config.dpi || 300, 10) || 300;
		const toInches = unit === 'mm' ? (1 / 25.4) : (unit === 'in' ? 1.0 : (1 / 2.54));

		const payloadLinesHaveGeometry = Array.isArray(payload.text_lines) && payload.text_lines.some(
			l => l && (
				(l.placements && typeof l.placements === 'object' && Object.keys(l.placements).length > 0)
				|| (l.box_left !== undefined && l.box_width !== undefined)
			)
		);
		const allLines = payloadLinesHaveGeometry
			? payload.text_lines
			: ((Array.isArray(config.text_lines) && config.text_lines.length > 0)
				? config.text_lines
				: (Array.isArray(payload.text_lines) ? payload.text_lines : []));
		const sideLines = allLines.filter(l => l && String(l.side_id || l.sideId || 'front') === targetSideId);

		// Index saved reference values so empty text/image fields don't bloat cropBoundingBox
		const refMap = new Map();
		const pvRefs = (payload.perso_visual && Array.isArray(payload.perso_visual.references))
			? payload.perso_visual.references
			: [];
		for (const r of pvRefs) {
			if (r && r.id) refMap.set(String(r.id), r);
		}
		const hasSnapshotValues = pvRefs.length > 0 || (Array.isArray(payload.text_lines) && payload.text_lines.length > 0);
		const payloadLineById = new Map();
		if (Array.isArray(payload.text_lines)) {
			for (const pl of payload.text_lines) {
				if (pl && pl.ref_id) payloadLineById.set(String(pl.ref_id), pl);
				if (pl && pl.id) payloadLineById.set(`id:${pl.id}`, pl);
			}
		}

		const isLineActiveWithContent = (line) => {
			if (!hasSnapshotValues) return true;
			const fType = line.field_type || line.fieldType || 'text';
			const ref = line.ref_id ? refMap.get(String(line.ref_id)) : null;
			const pLine = (line.ref_id && payloadLineById.get(String(line.ref_id))) || (line.id && payloadLineById.get(`id:${line.id}`)) || null;
			if (fType === 'image') {
				if (ref) return Boolean(ref.url && String(ref.url).trim());
				if (pLine && pLine.imageUrl !== undefined) return Boolean(String(pLine.imageUrl || '').trim());
				return Boolean(line.imageUrl || line.default_image);
			}
			if (ref && ref.value !== undefined) {
				return String(ref.value).trim().length > 0;
			}
			if (pLine && pLine.value !== undefined) {
				return String(pLine.value).trim().length > 0;
			}
			return true;
		};

		const resolveLineBox = (line) => {
			if (refViewId && line.placements && line.placements[refViewId]) {
				const p = line.placements[refViewId];
				if (p && p.hidden === true) {
					return null;
				}
				if (p && p.box_left !== undefined && p.box_width !== undefined) {
					return {
						left: parseFloat(p.box_left) || 0,
						top: parseFloat(p.box_top) || 0,
						width: parseFloat(p.box_width) || 10,
						height: parseFloat(p.box_height) || 10,
						rot: parseFloat(p.box_rotate !== undefined ? p.box_rotate : (p.rotate !== undefined ? p.rotate : 0)) || 0,
					};
				}
			}
			if (line.box_left !== undefined && line.box_width !== undefined) {
				return {
					left: parseFloat(line.box_left) || 0,
					top: parseFloat(line.box_top) || 0,
					width: parseFloat(line.box_width) || 10,
					height: parseFloat(line.box_height) || 10,
					rot: parseFloat(line.box_rotate !== undefined ? line.box_rotate : (line.rotate !== undefined ? line.rotate : 0)) || 0,
				};
			}
			return null;
		};

		const allSideBoxes = [];
		const activeSideBoxes = [];
		for (const line of sideLines) {
			const box = resolveLineBox(line);
			if (!box) continue;
			allSideBoxes.push(box);
			if (isLineActiveWithContent(line)) {
				activeSideBoxes.push(box);
			}
		}
		const boxes = activeSideBoxes.length > 0 ? activeSideBoxes : allSideBoxes;
		const hasPerLineBoxes = allSideBoxes.length > 0;
		const hasRepeaterOrClipart = isPrimarySide && Boolean(
			(config.repeater && config.repeater.enabled)
			|| (config.vector_clipart && (config.vector_clipart.enabled || config.vector_clipart.path))
			|| (editorData && editorData.repeater_enabled)
		);

		// Compute full platen pixel dimensions strictly from physical platen dimensions & DPI
		const defaultFullPxW = Math.max(10, Math.round(realW * toInches * dpi)) || 2400;
		const defaultFullPxH = Math.max(10, Math.round(realH * toInches * dpi)) || 3000;

		let cropBoundingBox = null;
		let exportRealW = realW;
		let exportRealH = realH;
		let exportPxW = defaultFullPxW;
		let exportPxH = defaultFullPxH;
		let exportRatio = (realW > 0 && realH > 0) ? (realW / realH) : 1.0;

		if (isFlatObject || hasRepeaterOrClipart || !hasPerLineBoxes) {
			exportRealW = realW;
			exportRealH = realH;
			exportPxW = defaultFullPxW;
			exportPxH = defaultFullPxH;
			exportRatio = (realW > 0 && realH > 0) ? (realW / realH) : 1.0;
		} else {
			let xMin = 100, yMin = 100, xMax = 0, yMax = 0;
			boxes.forEach(({ left, top, width, height, rot }) => {
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
			});

			// Clamp bounding box strictly within [0, 100]% platen bounds
			xMin = Math.max(0, Math.min(100, xMin));
			yMin = Math.max(0, Math.min(100, yMin));
			xMax = Math.max(0, Math.min(100, xMax));
			yMax = Math.max(0, Math.min(100, yMax));

			const spanW = Math.max(1, xMax - xMin);
			const spanH = Math.max(1, yMax - yMin);

			exportRealW = Number(((spanW / 100) * realW).toFixed(2));
			exportRealH = Number(((spanH / 100) * realH).toFixed(2));

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

		return {
			sideId: targetSideId,
			refViewId,
			isFlatObject,
			lockAspectRatio,
			hasPerLineBoxes,
			hasRepeaterOrClipart,
			realW,
			realH,
			unit,
			dpi,
			exportRealW,
			exportRealH,
			exportPxW,
			exportPxH,
			exportRatio,
			cropBoundingBox,
		};
	}

	/**
	 * Compute cropped physical & 300 DPI print dimensions consistently across frontend metadata
	 * and WP Admin print file generation (delegates to computeSideExportMetrics for primary side).
	 *
	 * @param {Object} config Product configuration.
	 * @param {Object|null} editorData Optional saved order item _mr_tee_editor_data snapshot.
	 * @return {Object} Computed print metrics & optional cropBoundingBox.
	 */
	static computeExportMetrics(config = {}, editorData = null) {
		const payload = (editorData && editorData.payload) ? editorData.payload : {};
		const printSides = (Array.isArray(payload.print_sides) && payload.print_sides.length > 0)
			? payload.print_sides
			: (Array.isArray(config.print_sides) ? config.print_sides : []);
		const primarySideId = (printSides.length > 0 && printSides[0] && printSides[0].id) ? String(printSides[0].id) : 'front';
		return this.computeSideExportMetrics(config, editorData, primarySideId);
	}

	/**
	 * Ensure any OpenType-SVG color font referenced by layers across any garment side
	 * (including unvisited secondary sides like Back) has its manifest loaded before cart rendering.
	 *
	 * @param {Array} layers Merged scene-graph layers.
	 * @param {Object} config Product configuration.
	 * @return {Promise<void>}
	 */
	static async ensureActiveSvgFontsLoaded(layers, config = {}) {
		if (typeof window === 'undefined') return;
		const fonts = (window.mrTeeProductData && window.mrTeeProductData.fonts)
			|| (window.mrTeeAdminData && window.mrTeeAdminData.fonts)
			|| (window.mrTeeOrderExportData && window.mrTeeOrderExportData.fonts)
			|| (config && config.fonts)
			|| {};
		const neededIds = new Set();

		const walk = (nodes) => {
			if (!Array.isArray(nodes)) return;
			for (const n of nodes) {
				if (!n || typeof n !== 'object') continue;
				if (n.fontId) neededIds.add(String(n.fontId));
				if (Array.isArray(n.items)) walk(n.items);
			}
		};
		walk(layers);

		if (Array.isArray(config.text_lines)) {
			for (const tl of config.text_lines) {
				if (tl && tl.font) neededIds.add(String(tl.font));
			}
		}

		const tasks = [];
		for (const fId of neededIds) {
			const cleanKey = fId.toLowerCase().replace(/[\s\-]+/g, '_');
			const fDef = fonts[fId] || fonts[cleanKey] || null;
			if (!fDef || (fDef.font_type !== 'opentype_svg' && !fDef.color_svg)) continue;
			if (!SvgRenderer.getSvgFontData(fId)) {
				tasks.push(SvgRenderer.ensureSvgFontLoaded(fId, fDef, { prewarmGlyphs: false }).catch(() => null));
			}
		}
		if (tasks.length > 0) {
			await Promise.all(tasks);
		}
	}

	/**
	 * Prepare ONLY the lightweight composite thumbnail preview(s) and scene-graph payload
	 * on the customer product page. Full 300 DPI PNG & vector SVG generation are deferred
	 * exclusively to WP Admin order screens for maximum client speed and IP protection.
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

		await this.ensureActiveSvgFontsLoaded(layers, config);

		const payload = store.getExportPayload();
		const snapshotEditorData = { payload };
		const primaryMetrics = this.computeExportMetrics(config, snapshotEditorData);
		const allPreviewViews = Array.isArray(config.preview_views) ? config.preview_views.filter(Boolean) : [];
		const allPrintSides = Array.isArray(config.print_sides) ? config.print_sides.filter(Boolean) : [];

		let cartViews = [];
		if (allPreviewViews.length > 0) {
			cartViews = allPreviewViews.filter(v => v.is_cart_preview !== false);
			if (cartViews.length === 0) {
				if (allPrintSides.length > 0) {
					for (const side of allPrintSides) {
						const sid = side.id || 'front';
						const sideViews = allPreviewViews.filter(v => (v.side_id || 'front') === sid);
						const fullView = sideViews.find(v => v.view_role === 'full') || sideViews[0];
						if (fullView) cartViews.push(fullView);
					}
				}
				if (cartViews.length === 0) {
					cartViews = [allPreviewViews[0]];
				}
			}
		} else if (allPrintSides.length > 1) {
			cartViews = allPrintSides.map((s, idx) => ({
				id: s.id || (idx === 0 ? 'front' : `side_${idx}`),
				side_id: s.id || (idx === 0 ? 'front' : `side_${idx}`),
				label: s.label || (idx === 0 ? 'Recto' : `Face ${idx + 1}`),
				image_url: '',
				blend_mode: 'normal',
				isLegacy: false,
			}));
		} else {
			cartViews = [{
				id: '',
				side_id: (allPrintSides[0] && allPrintSides[0].id) || 'front',
				label: (allPrintSides[0] && allPrintSides[0].label) || 'Recto',
				image_url: '',
				blend_mode: 'normal',
				isLegacy: true,
			}];
		}

		const fontFaceCss = this.extractFontFaceCss();
		const renderedViews = await Promise.all(cartViews.map(async (view, i) => {
			const sideId = view.side_id || 'front';
			const viewId = view.id || null;
			const sideMetrics = this.computeSideExportMetrics(config, snapshotEditorData, sideId);

			let domGarmentImg = viewId ? this.findProductGalleryImageElement(viewId) : (i === 0 ? this.findProductGalleryImageElement() : null);
			if (!domGarmentImg && i === 0 && !view.image_url) {
				domGarmentImg = this.findProductGalleryImageElement();
			}
			const hasFastDomBg = this.isSameOriginImage(domGarmentImg);
			const bgUrl = this.resolveProductBackgroundUrl(viewId) || view.image_url || '';
			const bgAsset = hasFastDomBg ? null : (bgUrl ? await this.loadBackgroundImage(bgUrl) : null);
			const isLocked = Boolean(sideMetrics.lockAspectRatio) || sideMetrics.isFlatObject;

			let compositeRatio = (sideMetrics.realW > 0 && sideMetrics.realH > 0)
				? (sideMetrics.realW / sideMetrics.realH)
				: (parseFloat(config.print_aspect_ratio) || 1.0);
			if (!isLocked) {
				if (domGarmentImg && domGarmentImg.naturalWidth > 0 && domGarmentImg.naturalHeight > 0) {
					compositeRatio = domGarmentImg.naturalWidth / domGarmentImg.naturalHeight;
				} else if (bgAsset && bgAsset.naturalWidth > 0 && bgAsset.naturalHeight > 0) {
					compositeRatio = bgAsset.naturalWidth / bgAsset.naturalHeight;
				}
			}

			// Build full-canvas preview SVG strictly at thumbnail resolution (never generates print SVG/PNG)
			const svgOptions = {
				isExport: false,
				useThumbCache: true,
				aspectRatio: compositeRatio,
				hasPerLineBoxes: sideMetrics.hasPerLineBoxes,
				fontFaceCss,
			};
			if (!view.isLegacy) {
				svgOptions.sideId = sideId;
				if (viewId) svgOptions.viewId = viewId;
			}

			const fullPreviewSvg = SvgRenderer.renderToString(layers, zoomTransform, svgOptions);
			const inlinedImagesSvg = await this.inlineExternalImages(fullPreviewSvg, {
				useThumbCache: true,
				maxThumbDim: 760,
			});
			const inlinedFullPreviewSvg = await this.inlineExternalFonts(inlinedImagesSvg);

			// Render crisp 800px JPEG composite mockup preview for cart thumbnail & full-screen cart zoom modal
			const compositeB64 = await this.renderCompositePreview(inlinedFullPreviewSvg, config, {
				bgAsset,
				domGarmentImg,
				compositeRatio,
				canvasWidth: 800,
				quality: 0.85,
				blendMode: view.blend_mode || 'normal',
				isFlatObject: sideMetrics.isFlatObject,
				isLocked,
				hasPerLineBoxes: sideMetrics.hasPerLineBoxes,
				realW: sideMetrics.realW,
				realH: sideMetrics.realH,
			});

			return {
				view,
				b64: compositeB64 || '',
			};
		}));

		const primaryEntry = renderedViews[0] || { view: cartViews[0], b64: '' };
		const compositeB64 = primaryEntry.b64;

		const sideExportMetrics = {};
		const sidesForMetrics = allPrintSides.length > 0 ? allPrintSides : [{ id: primaryMetrics.sideId || 'front' }];
		for (const sideDef of sidesForMetrics) {
			const sid = (sideDef && sideDef.id) ? String(sideDef.id) : 'front';
			const sm = this.computeSideExportMetrics(config, snapshotEditorData, sid);
			sideExportMetrics[sid] = {
				crop_print_width: sm.exportRealW,
				crop_print_height: sm.exportRealH,
				crop_width_px: sm.exportPxW,
				crop_height_px: sm.exportPxH,
				print_unit: sm.unit,
				dpi: sm.dpi,
			};
		}

		payload.crop_print_width = primaryMetrics.exportRealW;
		payload.crop_print_height = primaryMetrics.exportRealH;
		payload.crop_width_px = primaryMetrics.exportPxW;
		payload.crop_height_px = primaryMetrics.exportPxH;
		payload.print_unit = primaryMetrics.unit;
		payload.dpi = primaryMetrics.dpi;
		payload.side_export_metrics = sideExportMetrics;
		const payloadJson = JSON.stringify(payload);

		const designKeyInput = document.getElementById('mr_tee_design_key');
		const payloadInput = document.getElementById('mr_tee_editor_payload');
		const previewInput = document.getElementById('mr_tee_preview_png');
		let previewsInput = document.getElementById('mr_tee_previews_json');
		const designKey = this.generateDesignKey();

		let primaryUploadedUrl = '';
		const secondaryB64Bytes = renderedViews.slice(1).reduce((sum, item) => sum + ((item && item.b64) ? item.b64.length : 0), 0);
		const totalInlineBytes = payloadJson.length + (compositeB64 ? compositeB64.length : 0) + secondaryB64Bytes;
		const inlineAllPreviews = totalInlineBytes < 260 * 1024;
		const approxBytes = payloadJson.length + (compositeB64 ? compositeB64.length : 0);

		if (inlineAllPreviews || approxBytes < 180 * 1024) {
			if (designKeyInput) designKeyInput.value = designKey;
			if (payloadInput) payloadInput.value = payloadJson;
			if (previewInput) previewInput.value = compositeB64 || '';
		} else {
			const previewBlob = this.dataUrlToBlob(compositeB64);
			const previewArtifact = (compositeB64 && compositeB64.includes('image/jpeg')) ? 'preview_jpg' : 'preview_png';
			if (previewBlob) {
				const upRes = await this.uploadBlobInChunks(previewBlob, { artifact: previewArtifact, designKey });
				if (upRes && upRes.url) primaryUploadedUrl = upRes.url;
			}
			if (designKeyInput) designKeyInput.value = designKey;
			if (payloadInput) payloadInput.value = payloadJson;
			if (previewInput) previewInput.value = '';
		}

		// Persist additional cart preview views (index 1..N) inline when compact, or upload in parallel if large
		const previewsMeta = [];
		if (renderedViews.length > 1) {
			const v0 = primaryEntry.view || {};
			const secondaryEntries = await Promise.all(renderedViews.slice(1).map(async (item, idx) => {
				const i = idx + 1;
				const v = item.view || {};
				const rawViewId = v.id || `view_${i}`;
				const safeSuffix = String(rawViewId).replace(/[^a-zA-Z0-9_-]/g, '').slice(0, 32) || `view_${i}`;
				if (inlineAllPreviews && item.b64) {
					return {
						view_id: safeSuffix,
						side_id: v.side_id || 'front',
						label: v.label || `Vue ${i + 1}`,
						url: '',
						b64: item.b64,
					};
				}
				const blob = this.dataUrlToBlob(item.b64);
				const previewArtifact = (item.b64 && item.b64.includes('image/jpeg')) ? 'preview_jpg' : 'preview_png';
				let uploadedUrl = '';
				if (blob) {
					const upRes = await this.uploadBlobInChunks(blob, {
						artifact: previewArtifact,
						designKey,
						suffix: safeSuffix,
					});
					if (upRes && upRes.url) {
						uploadedUrl = upRes.url;
					}
				}
				return {
					view_id: safeSuffix,
					side_id: v.side_id || 'front',
					label: v.label || `Vue ${i + 1}`,
					url: uploadedUrl,
				};
			}));

			previewsMeta.push(
				{
					view_id: v0.id || v0.side_id || 'front',
					side_id: v0.side_id || 'front',
					label: v0.label || 'Recto',
					url: primaryUploadedUrl || '',
				},
				...secondaryEntries
			);

			if (!previewsInput && designKeyInput && designKeyInput.parentNode) {
				previewsInput = document.createElement('input');
				previewsInput.type = 'hidden';
				previewsInput.id = 'mr_tee_previews_json';
				previewsInput.name = 'mr_tee_previews_json';
				designKeyInput.parentNode.appendChild(previewsInput);
			}
		}

		if (previewsInput) {
			previewsInput.value = previewsMeta.length > 1 ? JSON.stringify(previewsMeta) : '';
		}

		return true;
	}

	/**
	 * Convert external image URLs (and oversized local data URIs in thumbnail mode)
	 * inside an SVG to compact base64 data URIs so HTML5 canvas draws without tainting or stalling.
	 *
	 * @param {string} svgMarkup SVG XML markup.
	 * @param {Object} options Optional flags ({ useThumbCache, maxThumbDim, strict }).
	 * @return {Promise<string>} Inlined SVG XML markup.
	 */
	static async inlineExternalImages(svgMarkup, options = {}) {
		if (!svgMarkup) return '';
		const useThumbCache = Boolean(options.useThumbCache);
		const maxThumbDim = options.maxThumbDim || 420;
		const strict = Boolean(options.strict);
		const matches = [...svgMarkup.matchAll(/(xlink:href|href)="([^"]+)"/g)];
		const urlsToFetch = [];
		for (const m of matches) {
			const url = m[2];
			const isRemoteOrRel = url.startsWith('http://') || url.startsWith('https://') || url.startsWith('/');
			const isOversizedData = useThumbCache && url.startsWith('data:image/') && !url.startsWith('data:image/svg+xml') && url.length > 80 * 1024;
			if (isRemoteOrRel || isOversizedData) {
				if (!urlsToFetch.includes(url)) {
					urlsToFetch.push(url);
				}
			}
		}

		if (urlsToFetch.length === 0) {
			return svgMarkup;
		}

		const urlMap = Object.create(null);
		const failedUrls = [];
		await Promise.all(urlsToFetch.map(async (url) => {
			const cleanUrl = url.replace(/&amp;/g, '&');
			const b64 = useThumbCache
				? await this.getThumbnailImageDataUrl(cleanUrl, maxThumbDim)
				: await this.fetchImageAsDataUrl(cleanUrl);
			if (b64 && b64 !== url) {
				urlMap[url] = b64;
			} else if (!b64 && !url.startsWith('data:')) {
				failedUrls.push(cleanUrl);
			}
		}));

		if (strict && failedUrls.length > 0) {
			throw new Error(`Impossible d'intégrer ${failedUrls.length} image(s) externe(s) pour l'export 300 DPI.`);
		}

		return svgMarkup.replace(/(xlink:href|href)="([^"]+)"/g, (full, attr, rawUrl) => {
			if (Object.prototype.hasOwnProperty.call(urlMap, rawUrl)) {
				return `${attr}="${urlMap[rawUrl]}"`;
			}
			return full;
		});
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
			const resp = await fetch(fetchUrl, ctrl ? { signal: ctrl.signal, cache: 'force-cache' } : { cache: 'force-cache' });
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
	 * @param {Object} options Optional flags ({ stripUnused }).
	 * @return {Promise<string>}
	 */
	static async inlineExternalFonts(svgMarkup, options = {}) {
		if (!svgMarkup) return '';
		const stripUnused = Boolean(options.stripUnused);

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

		if (usedFamilies.size === 0 && !stripUnused) {
			return svgMarkup;
		}

		const fontUrls = [];
		if (usedFamilies.size > 0) {
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
		}

		const fontMap = Object.create(null);
		if (fontUrls.length > 0) {
			await Promise.all(fontUrls.map(async (u) => {
				const cleanUrl = decodeEntities(u);
				const b64 = await this.fetchFontAsDataUrl(cleanUrl);
				if (b64) {
					fontMap[u] = b64;
				}
			}));
		}

		if (!stripUnused && Object.keys(fontMap).length === 0) {
			return svgMarkup;
		}

		return svgMarkup.replace(/<style\b([^>]*)>([\s\S]*?)<\/style>/gi, (fullStyle, attrs, cssBody) => {
			const rewrittenCss = cssBody.replace(/@font-face\s*\{([^}]*)\}/gi, (ruleFull, ruleBody) => {
				const famMatch = ruleBody.match(/font-family\s*:\s*(?:'([^']*)'|"([^"]*)"|([^;]+))/i);
				if (!famMatch) return stripUnused ? '' : ruleFull;
				const famName = decodeEntities(famMatch[1] || famMatch[2] || famMatch[3] || '')
					.trim()
					.replace(/^['"]+|['"]+$/g, '')
					.trim()
					.toLowerCase();
				if (!famName || !usedFamilies.has(famName)) {
					return stripUnused ? '' : ruleFull;
				}
				return ruleFull.replace(/url\(\s*(?:(['"])([^'"]+)\1|([^)\s'"]+))\s*\)/gi, (um, q, quotedUrl, bareUrl) => {
					const rawUrl = (quotedUrl || bareUrl || '').trim();
					if (Object.prototype.hasOwnProperty.call(fontMap, rawUrl)) {
						return `url('${fontMap[rawUrl]}')`;
					}
					return um;
				});
			});
			if (stripUnused && !rewrittenCss.trim()) {
				return '';
			}
			return `<style${attrs}>${rewrittenCss}</style>`;
		});
	}

	/**
	 * Compute IEEE 802.3 CRC32 checksum over a Uint8Array (for PNG chunk encoding).
	 *
	 * @param {Uint8Array} bytes Byte slice.
	 * @return {number} Unsigned 32-bit CRC.
	 */
	static _crc32(bytes) {
		if (!this._crcTable) {
			const table = new Uint32Array(256);
			for (let n = 0; n < 256; n++) {
				let c = n;
				for (let k = 0; k < 8; k++) {
					c = (c & 1) ? (0xedb88320 ^ (c >>> 1)) : (c >>> 1);
				}
				table[n] = c >>> 0;
			}
			this._crcTable = table;
		}
		let crc = 0xffffffff;
		for (let i = 0; i < bytes.length; i++) {
			crc = this._crcTable[(crc ^ bytes[i]) & 0xff] ^ (crc >>> 8);
		}
		return (crc ^ 0xffffffff) >>> 0;
	}

	/**
	 * Inject an exact DPI PNG `pHYs` chunk (pixels per meter) right after `IHDR`
	 * so DTF/DTG RIP software, Photoshop, and Illustrator open exported PNGs at true 300 DPI.
	 *
	 * @param {Blob}   pngBlob Raw PNG Blob from HTML5 Canvas.
	 * @param {number} dpi     Target DPI (default 300).
	 * @return {Promise<Blob>} PNG Blob with embedded `pHYs` metadata.
	 */
	static async injectPngDpiPhysChunk(pngBlob, dpi = 300) {
		if (!pngBlob || typeof pngBlob.arrayBuffer !== 'function') return pngBlob;
		try {
			const buf = await pngBlob.arrayBuffer();
			const data = new Uint8Array(buf);
			// Verify 8-byte PNG signature + IHDR chunk header
			if (
				data.length < 33 ||
				data[0] !== 0x89 || data[1] !== 0x50 || data[2] !== 0x4e || data[3] !== 0x47 ||
				data[12] !== 0x49 || data[13] !== 0x48 || data[14] !== 0x44 || data[15] !== 0x52
			) {
				return pngBlob;
			}

			const view = new DataView(buf);
			const ihdrLen = view.getUint32(8, false);
			const insertAt = 8 + 4 + 4 + ihdrLen + 4;
			if (insertAt > data.length) {
				return pngBlob;
			}

			// Build 21-byte pHYs chunk: 4B len(9) + 4B 'pHYs' + 4B X ppm + 4B Y ppm + 1B unit(1=meter) + 4B CRC
			const ppm = Math.max(1, Math.round((Number(dpi) || 300) / 0.0254));
			const phys = new Uint8Array(21);
			const pv = new DataView(phys.buffer);
			pv.setUint32(0, 9, false);
			phys[4] = 0x70; // 'p'
			phys[5] = 0x48; // 'H'
			phys[6] = 0x59; // 'Y'
			phys[7] = 0x73; // 's'
			pv.setUint32(8, ppm, false);
			pv.setUint32(12, ppm, false);
			phys[16] = 1; // unit specifier: meter
			pv.setUint32(17, this._crc32(phys.subarray(4, 17)), false);

			// Copy remaining chunks while stripping any pre-existing pHYs chunk
			const parts = [data.subarray(0, insertAt), phys];
			let offset = insertAt;
			let segStart = insertAt;
			while (offset + 12 <= data.length) {
				const cLen = view.getUint32(offset, false);
				const nextOffset = offset + 12 + cLen;
				if (nextOffset > data.length) break;
				const isPhys = data[offset + 4] === 0x70 && data[offset + 5] === 0x48 && data[offset + 6] === 0x59 && data[offset + 7] === 0x73;
				const isIdatOrIend = (data[offset + 4] === 0x49 && data[offset + 5] === 0x44 && data[offset + 6] === 0x41 && data[offset + 7] === 0x54)
					|| (data[offset + 4] === 0x49 && data[offset + 5] === 0x45 && data[offset + 6] === 0x4e && data[offset + 7] === 0x44);
				if (isPhys) {
					if (offset > segStart) {
						parts.push(data.subarray(segStart, offset));
					}
					segStart = nextOffset;
				}
				offset = nextOffset;
				if (isIdatOrIend) break;
			}
			if (segStart < data.length) {
				parts.push(data.subarray(segStart));
			}

			return new Blob(parts, { type: 'image/png' });
		} catch (e) {
			console.warn('[MrTee PrintExporter] Failed to inject PNG pHYs chunk:', e);
			return pngBlob;
		}
	}

	/**
	 * Rasterize SVG markup onto a high-resolution transparent HTML5 canvas with optional
	 * Blob streaming and embedded 300 DPI PNG `pHYs` chunk metadata.
	 *
	 * @param {string} svgMarkup SVG XML markup.
	 * @param {number} width     Canvas width in pixels (e.g. 2400).
	 * @param {number} height    Canvas height in pixels (e.g. 3000).
	 * @param {Object} options   Optional flags ({ dpi = 300, asBlob = false }).
	 * @return {Promise<string|Blob>} PNG Blob (when `asBlob: true`) or Base64 data URL.
	 */
	static rasterizeSvgToPng(svgMarkup, width, height, options = {}) {
		const safeW = Math.max(1, Math.round(Number(width) || 0));
		const safeH = Math.max(1, Math.round(Number(height) || 0));
		const dpi = parseInt(options.dpi, 10) || 300;
		const asBlob = Boolean(options.asBlob);

		if (safeW > 16384 || safeH > 16384 || (safeW * safeH) > 120000000) {
			return Promise.reject(
				new Error(`Dimensions d'export trop élevées pour le navigateur (${safeW}×${safeH} px). Réduisez la résolution DPI ou les dimensions d'impression.`)
			);
		}

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
					canvas.width = safeW;
					canvas.height = safeH;
					const ctx = canvas.getContext('2d');
					if (!ctx) {
						URL.revokeObjectURL(url);
						reject(new Error('Canvas 2D context unavailable for 300 DPI export'));
						return;
					}

					ctx.clearRect(0, 0, safeW, safeH);
					ctx.drawImage(img, 0, 0, safeW, safeH);
					URL.revokeObjectURL(url);

					if (asBlob && typeof canvas.toBlob === 'function') {
						canvas.toBlob((rawBlob) => {
							if (!rawBlob || rawBlob.size === 0) {
								reject(new Error('Canvas PNG export returned an empty blob'));
								return;
							}
							this.injectPngDpiPhysChunk(rawBlob, dpi)
								.then(resolve)
								.catch(() => resolve(rawBlob));
						}, 'image/png');
					} else if (asBlob) {
						const fallbackBlob = this.dataUrlToBlob(canvas.toDataURL('image/png'));
						if (!fallbackBlob) {
							reject(new Error('Canvas PNG export failed'));
							return;
						}
						this.injectPngDpiPhysChunk(fallbackBlob, dpi)
							.then(resolve)
							.catch(() => resolve(fallbackBlob));
					} else {
						resolve(canvas.toDataURL('image/png'));
					}
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
				reject(err || new Error('SVG rasterization image decode error'));
			};

			img.src = url;
		});
	}

	/**
	 * Render composite mockup image (garment photo + overlay design) as a lightweight JPEG thumbnail.
	 *
	 * @param {string} svgMarkup SVG markup.
	 * @param {Object} config Product configuration.
	 * @param {Object} options Optional preloaded background assets and size overrides ({ bgAsset, domGarmentImg, compositeRatio, canvasWidth, quality }).
	 * @return {Promise<string>} Base64 composite image data URL.
	 */
	static async renderCompositePreview(svgMarkup, config, options = {}) {
		const bgAsset = options.bgAsset !== undefined ? options.bgAsset : await this.loadBackgroundImage(this.resolveProductBackgroundUrl());
		const domGarmentImg = options.domGarmentImg !== undefined ? options.domGarmentImg : this.findProductGalleryImageElement();

		const isFlatObject = options.isFlatObject !== undefined ? Boolean(options.isFlatObject) : (config.product_type === 'flat_object');
		const isLocked = options.isLocked !== undefined ? Boolean(options.isLocked) : (Boolean(config.lock_aspect_ratio) || isFlatObject);
		const hasPerLineBoxes = options.hasPerLineBoxes !== undefined
			? Boolean(options.hasPerLineBoxes)
			: (Array.isArray(config.text_lines) && config.text_lines.some(l => l.box_left !== undefined && l.box_width !== undefined));
		const realW = parseFloat(options.realW) || parseFloat(config.print_real_width) || 0;
		const realH = parseFloat(options.realH) || parseFloat(config.print_real_height) || 0;
		const realRatio = (realW > 0 && realH > 0)
			? (realW / realH)
			: (parseFloat(config.print_aspect_ratio) || 1.0);
		const blendMode = options.blendMode || 'normal';

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

		const canvasW = Math.max(180, Math.min(1200, parseInt(options.canvasWidth, 10) || 800));
		const canvasH = Math.max(120, Math.min(1400, Math.round(canvasW / compositeRatio)));
		const quality = (typeof options.quality === 'number' && options.quality > 0 && options.quality <= 1) ? options.quality : 0.74;
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
					finish(canvas.toDataURL('image/jpeg', quality));
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
						if (blendMode === 'multiply') {
							ctx.globalCompositeOperation = 'multiply';
							ctx.globalAlpha = 0.96;
						} else if (blendMode === 'soft_ink') {
							ctx.globalCompositeOperation = 'source-over';
							ctx.globalAlpha = 0.91;
						}
						ctx.drawImage(designImg, left, top, w, h);
						ctx.globalCompositeOperation = 'source-over';
						ctx.globalAlpha = 1.0;
						const dataUrl = canvas.toDataURL('image/jpeg', quality);
						URL.revokeObjectURL(url);
						finish(dataUrl);
					} catch (err) {
						ctx.globalCompositeOperation = 'source-over';
						ctx.globalAlpha = 1.0;
						// Recover from tainted canvas if background came from cross-origin DOM element
						try {
							const cleanCanvas = document.createElement('canvas');
							cleanCanvas.width = canvasW;
							cleanCanvas.height = canvasH;
							const cleanCtx = cleanCanvas.getContext('2d');
							cleanCtx.fillStyle = '#ffffff';
							cleanCtx.fillRect(0, 0, canvasW, canvasH);
							cleanCtx.drawImage(designImg, left, top, w, h);
							const cleanUrl = cleanCanvas.toDataURL('image/jpeg', quality);
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
						finish(canvas.toDataURL('image/jpeg', quality));
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
		if (typeof window !== 'undefined') {
			const preCss = (window.mrTeeProductData && window.mrTeeProductData.fontFaceCss)
				|| (window.mrTeeOrderExportData && window.mrTeeOrderExportData.fontFaceCss)
				|| '';
			if (preCss && (!preCss.includes('data:font') || preCss.length < 50000)) {
				return preCss;
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
