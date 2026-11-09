/**
 * Mr. Tee Editor - Print Exporter Module
 *
 * Preloads fonts and external images as base64 data URIs and rasterizes
 * the scene-graph to 300 DPI transparent print PNG (2400x3000px) and vector SVG.
 *
 * @package MrTeeEditor\Assets\Modules
 */

import { SvgRenderer } from './svg-renderer.js?v=2.3.1';

export class PrintExporter {
	/**
	 * Prepare complete print export assets and populate form inputs.
	 *
	 * @param {Array} layers Merged scene-graph layers.
	 * @param {Object} zoomTransform { scale, translateX, translateY }
	 * @param {Object} store State store instance.
	 * @param {Object} config Product configuration.
	 * @return {Promise<boolean>}
	 */
	static async prepareExport(layers, zoomTransform, store, config) {
		const isFlatObject = config.product_type === 'flat_object';
		const hasPerLineBoxes = Array.isArray(config.text_lines) && config.text_lines.some(l => l.box_left !== undefined && l.box_width !== undefined);
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

		if (!isFlatObject && hasPerLineBoxes) {
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
						const halfW = (width * cos + height * sin) / 2;
						const halfH = (width * sin + height * cos) / 2;
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
			aspectRatio: (realW > 0 && realH > 0) ? (realW / realH) : 1.0,
			hasPerLineBoxes: hasPerLineBoxes,
			fontFaceCss: this.extractFontFaceCss(),
		});

		// 3. Rasterize to 300 DPI Transparent PNG (tightly cropped, lossless print output)
		const print300DpiB64 = await this.rasterizeSvgToPng(svgMarkup, exportPxW, exportPxH);

		// 4. Render Composite Mockup Preview (Garment + Design) as compact high-quality JPEG
		const compositeB64 = await this.renderCompositePreview(fullPreviewSvg, config);

		// 5. Serialize payload with print dimensions attached
		const payload = store.getExportPayload();
		payload.crop_print_width = exportRealW;
		payload.crop_print_height = exportRealH;
		payload.crop_width_px = exportPxW;
		payload.crop_height_px = exportPxH;
		payload.print_unit = unit;
		payload.dpi = dpi;

		// 5. Populate hidden form inputs
		const payloadInput = document.getElementById('mr_tee_editor_payload');
		const previewInput = document.getElementById('mr_tee_preview_png');
		const svgInput = document.getElementById('mr_tee_vector_svg');
		const printInput = document.getElementById('mr_tee_print_300dpi_png');

		if (payloadInput) payloadInput.value = JSON.stringify(payload);
		if (previewInput) previewInput.value = compositeB64;
		if (svgInput) svgInput.value = svgMarkup;
		if (printInput) printInput.value = print300DpiB64;

		return true;
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

			img.onload = () => {
				const canvas = document.createElement('canvas');
				canvas.width = width;
				canvas.height = height;
				const ctx = canvas.getContext('2d');

				ctx.clearRect(0, 0, width, height);
				ctx.drawImage(img, 0, 0, width, height);
				URL.revokeObjectURL(url);

				resolve(canvas.toDataURL('image/png'));
			};

			img.onerror = (err) => {
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
	 * @return {Promise<string>} Base64 composite image data URL.
	 */
	static renderCompositePreview(svgMarkup, config) {
		return new Promise((resolve) => {
			const garmentImg = document.querySelector('.woocommerce-product-gallery__image img:not(.emoji):not(.zoomImg)') ||
				document.querySelector('.mr-tee-stage-garment-img') ||
				document.querySelector('.product .images img:not(.emoji):not(.zoomImg)');

			const isFlatObject = config.product_type === 'flat_object';
			const hasPerLineBoxes = Array.isArray(config.text_lines) && config.text_lines.some(l => l.box_left !== undefined && l.box_width !== undefined);
			const ratio = (parseFloat(config.print_real_width) && parseFloat(config.print_real_height)) ? (parseFloat(config.print_real_width) / parseFloat(config.print_real_height)) : (parseFloat(config.print_aspect_ratio) || 1.0);

			const canvasW = 800;
			const canvasH = isFlatObject ? Math.max(200, Math.round(800 / ratio)) : 800;
			const canvas = document.createElement('canvas');
			canvas.width = canvasW;
			canvas.height = canvasH;
			const ctx = canvas.getContext('2d');

			// Solid white backdrop for JPEG export
			ctx.fillStyle = '#ffffff';
			ctx.fillRect(0, 0, canvasW, canvasH);

			const drawDesign = () => {
				const blob = new Blob([svgMarkup], { type: 'image/svg+xml;charset=utf-8' });
				const url = URL.createObjectURL(blob);
				const designImg = new Image();

				designImg.onload = () => {
					let left, top, w, h;
					if (isFlatObject || hasPerLineBoxes) {
						left = 0;
						top = 0;
						w = canvasW;
						h = canvasH;
					} else {
						left = (parseFloat(config.box_left) || 26.0) / 100 * canvasW;
						top = (parseFloat(config.box_top) || 23.0) / 100 * canvasH;
						w = (parseFloat(config.box_width) || 48.0) / 100 * canvasW;
						h = (parseFloat(config.box_height) || 54.0) / 100 * canvasH;
					}

					ctx.drawImage(designImg, left, top, w, h);
					URL.revokeObjectURL(url);
					resolve(canvas.toDataURL('image/jpeg', 0.85));
				};

				designImg.onerror = () => {
					URL.revokeObjectURL(url);
					resolve(canvas.toDataURL('image/jpeg', 0.85));
				};

				designImg.src = url;
			};

			if (garmentImg && garmentImg.complete && garmentImg.naturalWidth > 0) {
				try {
					ctx.drawImage(garmentImg, 0, 0, canvasW, canvasH);
					drawDesign();
				} catch (e) {
					// CORS or tainted canvas fallback
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
