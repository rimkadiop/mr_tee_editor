/**
 * Mr. Tee Editor - Print Exporter Module
 *
 * Preloads fonts and external images as base64 data URIs and rasterizes
 * the scene-graph to 300 DPI transparent print PNG (2400x3000px) and vector SVG.
 *
 * @package MrTeeEditor\Assets\Modules
 */

import { SvgRenderer } from './svg-renderer.js';

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
		const printW = parseInt(config.print_width_px, 10) || 2400;
		const printH = parseInt(config.print_height_px, 10) || 3000;

		// 1. Build self-contained, clean vector SVG markup (clean @font-face URLs, no base64 bloat)
		const svgMarkup = SvgRenderer.renderToString(layers, zoomTransform, {
			isExport: true,
			fontFaceCss: this.extractFontFaceCss(),
		});

		// 2. Rasterize to 300 DPI Transparent PNG (lossless print output)
		const print300DpiB64 = await this.rasterizeSvgToPng(svgMarkup, printW, printH);

		// 3. Render Composite Mockup Preview (Garment + Design) as compact high-quality JPEG
		const compositeB64 = await this.renderCompositePreview(svgMarkup, config);

		// 4. Serialize payload
		const payload = store.getExportPayload();

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

			const size = 800;
			const canvas = document.createElement('canvas');
			canvas.width = size;
			canvas.height = size;
			const ctx = canvas.getContext('2d');

			// Solid white backdrop for JPEG export
			ctx.fillStyle = '#ffffff';
			ctx.fillRect(0, 0, size, size);

			const drawDesign = () => {
				const blob = new Blob([svgMarkup], { type: 'image/svg+xml;charset=utf-8' });
				const url = URL.createObjectURL(blob);
				const designImg = new Image();

				designImg.onload = () => {
					const left = (parseFloat(config.box_left) || 26.0) / 100 * size;
					const top = (parseFloat(config.box_top) || 23.0) / 100 * size;
					const w = (parseFloat(config.box_width) || 48.0) / 100 * size;
					const h = (parseFloat(config.box_height) || 54.0) / 100 * size;

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
					ctx.drawImage(garmentImg, 0, 0, size, size);
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
