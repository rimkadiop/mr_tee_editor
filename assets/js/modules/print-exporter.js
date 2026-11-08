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

		// 1. Build self-contained vector SVG markup
		const svgMarkup = SvgRenderer.renderToString(layers, zoomTransform, {
			isExport: true,
			fontFaceCss: this.extractFontFaceCss(),
		});

		// 2. Rasterize to 300 DPI Transparent PNG
		const print300DpiB64 = await this.rasterizeSvgToPng(svgMarkup, printW, printH);

		// 3. Render Composite Mockup Preview (Garment + Design)
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
	 * Render composite mockup image (garment photo + overlay design).
	 *
	 * @param {string} svgMarkup SVG markup.
	 * @param {Object} config Product configuration.
	 * @return {Promise<string>} Base64 composite image data URL.
	 */
	static renderCompositePreview(svgMarkup, config) {
		return new Promise((resolve) => {
			const garmentImg = document.querySelector('.woocommerce-product-gallery__image img') ||
				document.querySelector('.mr-tee-stage-garment-img') ||
				document.querySelector('.product .images img');

			const canvas = document.createElement('canvas');
			canvas.width = 1200;
			canvas.height = 1200;
			const ctx = canvas.getContext('2d');

			const drawDesign = () => {
				const blob = new Blob([svgMarkup], { type: 'image/svg+xml;charset=utf-8' });
				const url = URL.createObjectURL(blob);
				const designImg = new Image();

				designImg.onload = () => {
					const left = (parseFloat(config.box_left) || 26.0) / 100 * 1200;
					const top = (parseFloat(config.box_top) || 23.0) / 100 * 1200;
					const w = (parseFloat(config.box_width) || 48.0) / 100 * 1200;
					const h = (parseFloat(config.box_height) || 54.0) / 100 * 1200;

					ctx.drawImage(designImg, left, top, w, h);
					URL.revokeObjectURL(url);
					resolve(canvas.toDataURL('image/png'));
				};

				designImg.onerror = () => {
					URL.revokeObjectURL(url);
					resolve(canvas.toDataURL('image/png'));
				};

				designImg.src = url;
			};

			if (garmentImg && garmentImg.complete && garmentImg.naturalWidth > 0) {
				try {
					ctx.drawImage(garmentImg, 0, 0, 1200, 1200);
					drawDesign();
				} catch (e) {
					// CORS or tainted canvas fallback
					ctx.fillStyle = '#ffffff';
					ctx.fillRect(0, 0, 1200, 1200);
					drawDesign();
				}
			} else {
				ctx.fillStyle = '#f3f4f6';
				ctx.fillRect(0, 0, 1200, 1200);
				drawDesign();
			}
		});
	}

	/**
	 * Extract active @font-face CSS rules from page stylesheets.
	 *
	 * @return {string}
	 */
	static extractFontFaceCss() {
		let css = '';
		try {
			for (const sheet of document.styleSheets) {
				try {
					const rules = sheet.cssRules || sheet.rules;
					for (const rule of rules) {
						if (rule.type === CSSRule.FONT_FACE_RULE) {
							css += rule.cssText + '\n';
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
