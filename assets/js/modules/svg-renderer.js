/**
 * Mr. Tee Editor - Pure SVG Scene-Graph Renderer Module
 *
 * Renders declarative scene-graph layers into high-precision SVG DOM elements
 * or standalone XML markup strings with:
 * - 3D Lighting & Drop-Shadow Filters + Embroidery Thread Relief
 * - Per-Letter COLOR_SET Palette Cycling via <tspan>
 * - Multi-Line Dynamic Stack Rendering with Custom Line Heights
 * - SVG Texture Masking (`type: MASK`, e.g. Leopard, Camo, Floral Pattern Fills)
 * - Vector Clipart & Icons (`type: PATH`, e.g. Hearts, Stars, Badges)
 * - Outside-Only Stroke (`paint-order: stroke fill`)
 * - Dynamic Auto-Zoom / Auto-Centering Group Transforms
 *
 * @package MrTeeEditor\Assets\Modules
 */

export class SvgRenderer {
	/**
	 * Build complete SVG string from merged layers and zoom transform.
	 *
	 * @param {Array} layers Merged scene-graph layers.
	 * @param {Object} zoomTransform { scale, translateX, translateY }
	 * @param {Object} options Rendering options (e.g. isExport, fontFaceCss).
	 * @return {string} Valid SVG XML markup string.
	 */
	static renderToString(layers, zoomTransform = { scale: 1, translateX: 0, translateY: 0 }, options = {}) {
		const z = zoomTransform || { scale: 1, translateX: 0, translateY: 0 };
		const scale = z.scale !== undefined ? z.scale : 1;
		const tx = z.translateX !== undefined ? z.translateX : 0;
		const ty = z.translateY !== undefined ? z.translateY : 0;

		const fontFaceCss = options.fontFaceCss || (typeof window !== 'undefined' && window.mrTeeProductData && window.mrTeeProductData.fontFaceCss) || '';
		let defs = '';
		if (fontFaceCss) {
			defs += `<style><![CDATA[\n${fontFaceCss}\n]]></style>`;
		}

		// 3D Drop shadow filter
		defs += `
			<filter id="mrtee-3d-shadow" x="-20%" y="-20%" width="140%" height="140%">
				<feDropShadow dx="0" dy="0.9" stdDeviation="0.4" flood-color="#000000" flood-opacity="0.32" />
			</filter>
			<filter id="mrtee-embroidery-relief" x="-10%" y="-10%" width="120%" height="120%">
				<feDropShadow dx="0" dy="0.6" stdDeviation="0.3" flood-color="#000000" flood-opacity="0.35" />
			</filter>
		`;

		const layersMarkup = this.renderLayersMarkup(layers);

		return `
<svg
	id="mr-tee-svg-element"
	class="mr-tee-live-svg"
	xmlns="http://www.w3.org/2000/svg"
	xmlns:xlink="http://www.w3.org/1999/xlink"
	viewBox="0 0 100 100"
	width="100%"
	height="100%"
	preserveAspectRatio="xMidYMid meet"
	style="overflow:visible;"
>
	<defs>
		${defs}
	</defs>
	<!-- Dynamic Auto-Zoom & Auto-Centering Container -->
	<g transform="translate(${tx.toFixed(3)}, ${ty.toFixed(3)}) translate(50, 50) scale(${scale.toFixed(4)}) translate(-50, -50)">
		${layersMarkup}
	</g>
</svg>`.trim();
	}

	/**
	 * Render child layers array to SVG markup.
	 *
	 * @param {Array} layers List of scene-graph nodes.
	 * @return {string}
	 */
	static renderLayersMarkup(layers) {
		let html = '';
		for (const node of layers) {
			if (!node || node.hide) continue;

			if (node.type === 'GROUP') {
				const groupClass = node.name ? `class="mrtee-group mrtee-group-${node.name.toLowerCase().replace(/\s+/g, '-')}"` : '';
				const childrenHtml = this.renderLayersMarkup(node.items || []);
				html += `<g ${groupClass}>${childrenHtml}</g>\n`;
			} else if (node.type === 'TEXT') {
				html += this.renderTextNode(node) + '\n';
			} else if (node.type === 'IMAGE') {
				html += this.renderImageNode(node) + '\n';
			} else if (node.type === 'PATH') {
				html += this.renderPathNode(node) + '\n';
			} else if (node.type === 'MASK') {
				html += this.renderMaskNode(node) + '\n';
			}
		}
		return html;
	}

	/**
	 * Render a TEXT node with per-letter COLOR_SET cycling, multi-line support, and outsideOnly stroke.
	 *
	 * @param {Object} node TEXT node definition.
	 * @return {string}
	 */
	static renderTextNode(node) {
		const val = String(node.value || node.text || '');
		if (!val.trim()) return '';

		const x = parseFloat(node.x) || 50;
		const y = parseFloat(node.y) || 50;
		const fontSize = parseFloat(node.fontSize) || 12;
		const rawFont = node.fontFamily || 'SuperMario256';
		const fontFamily = (rawFont.includes('sans-serif') || rawFont.includes('serif'))
			? rawFont
			: `'${rawFont}', 'SuperMario256', Arial Black, Impact, -apple-system, sans-serif`;
		const letterSpacing = node.letterSpacing ? `${node.letterSpacing}em` : '0.08em';
		const casing = node.casing || (node.uppercase === false ? 'ORIGINAL' : 'UPPER');
		const displayText = (casing === 'UPPER') ? val.toUpperCase() : val;
		const lineHeight = parseFloat(node.lineHeight) || 0.95;

		// Stroke styling
		const strokeWidth = (node.stroke && node.stroke.width) ? parseFloat(node.stroke.width) : (node.strokeWidth !== undefined ? parseFloat(node.strokeWidth) : 0.72);
		const strokeColor = (node.colorSet && node.colorSet.strokeHex) ? node.colorSet.strokeHex : (node.stroke && node.stroke.color ? node.stroke.color : '#000000');
		const paintOrder = 'paint-order: stroke fill; stroke-linejoin: round; stroke-linecap: round;';

		// Color set handling (multi-color vs solid)
		const colorSet = node.colorSet || {};
		const isMulti = colorSet.colorMode === 'multi' && Array.isArray(colorSet.palette) && colorSet.palette.length > 0;
		const palette = isMulti ? colorSet.palette : [node.fill?.color?.hex || node.fill || colorSet.colorHex || '#ffffff'];

		// Check for multi-line text
		const lines = displayText.split('\n');
		const isMultiLine = lines.length > 1 || node.multiLine;

		// Filter choice: embroidery relief vs 3d shadow
		const isEmbroidery = node.fontStyle === 'embroidery' || (colorSet.id && colorSet.id.includes('embroidery')) || strokeColor === 'transparent';
		const filterAttr = isEmbroidery ? 'filter="url(#mrtee-embroidery-relief)"' : 'filter="url(#mrtee-3d-shadow)"';

		let tspans = '';
		let charIndex = 0;

		if (isMultiLine) {
			const totalLines = lines.length;
			const startYOffset = -((totalLines - 1) / 2) * (fontSize * lineHeight);

			for (let lineIdx = 0; lineIdx < totalLines; lineIdx++) {
				const lineText = lines[lineIdx];
				const dy = lineIdx === 0 ? startYOffset : (fontSize * lineHeight);

				let lineTspans = '';
				for (let i = 0; i < lineText.length; i++) {
					const char = lineText[i];
					if (char === ' ') {
						lineTspans += `<tspan xml:space="preserve"> </tspan>`;
					} else {
						const fill = palette[charIndex % palette.length];
						lineTspans += `<tspan fill="${fill}">${this.escapeXml(char)}</tspan>`;
						charIndex++;
					}
				}

				tspans += `<tspan x="${x}" dy="${dy.toFixed(2)}">${lineTspans}</tspan>`;
			}
		} else {
			for (let i = 0; i < displayText.length; i++) {
				const char = displayText[i];
				if (char === ' ') {
					tspans += `<tspan xml:space="preserve"> </tspan>`;
				} else {
					const fill = palette[charIndex % palette.length];
					tspans += `<tspan fill="${fill}">${this.escapeXml(char)}</tspan>`;
					charIndex++;
				}
			}
		}

		return `
		<text
			x="${x}"
			y="${y}"
			font-family="${fontFamily}"
			font-size="${fontSize}"
			text-anchor="${node.textAnchor || 'middle'}"
			dominant-baseline="central"
			letter-spacing="${letterSpacing}"
			stroke="${strokeColor}"
			stroke-width="${strokeWidth}"
			style="${paintOrder}"
			${filterAttr}
		>${tspans}</text>`;
	}

	/**
	 * Render an IMAGE node (e.g. Mushroom packshot or texture).
	 *
	 * @param {Object} node IMAGE node definition.
	 * @return {string}
	 */
	static renderImageNode(node) {
		const url = node.url || '';
		if (!url) return '';

		const x = parseFloat(node.x) || 30;
		const y = parseFloat(node.y) || 50;
		const w = parseFloat(node.width) || 21;
		const h = parseFloat(node.height) || 19;

		return `
		<image
			href="${this.escapeXml(url)}"
			xlink:href="${this.escapeXml(url)}"
			x="${x}"
			y="${y}"
			width="${w}"
			height="${h}"
			preserveAspectRatio="xMidYMid meet"
			filter="url(#mrtee-3d-shadow)"
		/>`;
	}

	/**
	 * Render a PATH node (e.g. Vector Heart or Clipart divider).
	 *
	 * @param {Object} node PATH node definition.
	 * @return {string}
	 */
	static renderPathNode(node) {
		const d = node.path || node.d || '';
		if (!d) return '';

		const fill = node.fill?.color?.hex || node.fill || '#000000';
		const stroke = node.stroke?.color || node.stroke || 'transparent';
		const strokeWidth = node.strokeWidth || 0;
		const transform = node.transform ? `transform="${node.transform}"` : '';

		return `
		<path
			d="${d}"
			fill="${fill}"
			stroke="${stroke}"
			stroke-width="${strokeWidth}"
			${transform}
			filter="url(#mrtee-3d-shadow)"
		/>`;
	}

	/**
	 * Render a MASK node (e.g. Leopard texture filled text).
	 *
	 * @param {Object} node MASK node definition.
	 * @return {string}
	 */
	static renderMaskNode(node) {
		const maskId = `mrtee-mask-${Math.random().toString(36).substr(2, 9)}`;
		const childItems = node.items || [];
		const textureUrl = node.textureUrl || (node.image && node.image.url) || 'https://cdn.monsieurtshirt.com/textures/leopard.png';

		let maskContent = '';
		for (const child of childItems) {
			if (child.type === 'TEXT') {
				// Render mask text in solid white (#ffffff)
				const maskTextNode = Object.assign({}, child, {
					fill: '#ffffff',
					colorSet: null,
					stroke: 'transparent',
					strokeWidth: 0
				});
				maskContent += this.renderTextNode(maskTextNode);
			}
		}

		return `
		<mask id="${maskId}" maskUnits="userSpaceOnUse">
			${maskContent}
		</mask>
		<image
			href="${this.escapeXml(textureUrl)}"
			xlink:href="${this.escapeXml(textureUrl)}"
			x="${parseFloat(node.x) || 15}"
			y="${parseFloat(node.y) || 20}"
			width="${parseFloat(node.width) || 70}"
			height="${parseFloat(node.height) || 60}"
			preserveAspectRatio="xMidYMid slice"
			mask="url(#${maskId})"
			filter="url(#mrtee-3d-shadow)"
		/>`;
	}

	/**
	 * Escape special XML characters for SVG text.
	 *
	 * @param {string} str Raw string.
	 * @return {string}
	 */
	static escapeXml(str) {
		return String(str)
			.replace(/&/g, '&amp;')
			.replace(/</g, '&lt;')
			.replace(/>/g, '&gt;')
			.replace(/"/g, '&quot;')
			.replace(/'/g, '&apos;');
	}
}
