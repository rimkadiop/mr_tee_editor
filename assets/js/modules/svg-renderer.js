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

		const hasPerLineBoxes = Boolean(options.hasPerLineBoxes) || (Array.isArray(layers) && layers.some(n => n.box_top !== undefined || (Array.isArray(n.items) && n.items.some(c => c.box_top !== undefined))));
		const ratio = (options.aspectRatio && parseFloat(options.aspectRatio) > 0)
			? parseFloat(options.aspectRatio)
			: (options.realWidth && options.realHeight ? (parseFloat(options.realWidth) / parseFloat(options.realHeight)) : 1.0);

		const vbWidth = 100;
		const vbHeight = (hasPerLineBoxes && ratio > 0 && Math.abs(ratio - 1.0) > 0.001) ? Number((100 / ratio).toFixed(4)) : 100;
		const viewBoxAttr = `viewBox="0 0 ${vbWidth} ${vbHeight}"`;

		const layersMarkup = this.renderLayersMarkup(layers, { ...options, ratio, hasPerLineBoxes, vbHeight });
		const widthAttr = (options.isExport && options.realWidth) ? `${options.realWidth}${options.unit || 'cm'}` : '100%';
		const heightAttr = (options.isExport && options.realHeight) ? `${options.realHeight}${options.unit || 'cm'}` : '100%';
		const realDimAttrs = (options.realWidth && options.realHeight) ? `data-real-width="${options.realWidth}" data-real-height="${options.realHeight}" data-unit="${options.unit || 'cm'}" data-dpi="${options.dpi || 300}"` : '';

		const innerContent = hasPerLineBoxes
			? layersMarkup
			: `<g transform="translate(${tx.toFixed(3)}, ${ty.toFixed(3)}) translate(50, 50) scale(${scale.toFixed(4)}) translate(-50, -50)">${layersMarkup}</g>`;

		return `
<svg
	id="mr-tee-svg-element"
	class="mr-tee-live-svg"
	xmlns="http://www.w3.org/2000/svg"
	xmlns:xlink="http://www.w3.org/1999/xlink"
	${viewBoxAttr}
	width="${widthAttr}"
	height="${heightAttr}"
	${realDimAttrs}
	preserveAspectRatio="xMidYMid meet"
	style="overflow:visible;"
>
	<defs>
		${defs}
	</defs>
	<!-- Scene-Graph Render Container -->
	${innerContent}
</svg>`.trim();
	}

	/**
	 * Render child layers array to SVG markup.
	 *
	 * @param {Array} layers List of scene-graph nodes.
	 * @param {Object} options Rendering options.
	 * @return {string}
	 */
	static renderLayersMarkup(layers, options = {}) {
		let html = '';
		for (const node of layers) {
			if (!node || node.hide) continue;

			if (node.type === 'GROUP') {
				const groupClass = node.name ? `class="mrtee-group mrtee-group-${node.name.toLowerCase().replace(/\s+/g, '-')}"` : '';
				const childrenHtml = this.renderLayersMarkup(node.items || [], options);
				html += `<g ${groupClass}>${childrenHtml}</g>\n`;
			} else if (node.type === 'TEXT') {
				html += this.renderTextNode(node, options) + '\n';
			} else if (node.type === 'IMAGE') {
				html += this.renderImageNode(node, options) + '\n';
			} else if (node.type === 'PATH') {
				html += this.renderPathNode(node, options) + '\n';
			} else if (node.type === 'MASK') {
				html += this.renderMaskNode(node, options) + '\n';
			}
		}
		return html;
	}

	/**
	 * Compute approximate character width ratio and letter spacing for font families.
	 *
	 * @param {string} rawFont Font family name or declaration.
	 * @return {{ charRatio: number, letterSpacingEm: number }}
	 */
	static getFontMetrics(rawFont) {
		const f = String(rawFont || '').toLowerCase();
		if (f.includes('bebas')) {
			return { charRatio: 0.42, letterSpacingEm: 0.10 };
		}
		if (f.includes('press start') || f.includes('pressstart') || f.includes('arcade')) {
			return { charRatio: 0.72, letterSpacingEm: 0.05 };
		}
		if (f.includes('super mario') || f.includes('mario')) {
			return { charRatio: 0.62, letterSpacingEm: 0.15 };
		}
		if (f.includes('luckiest')) {
			return { charRatio: 0.64, letterSpacingEm: 0.06 };
		}
		if (f.includes('bungee')) {
			return { charRatio: 0.65, letterSpacingEm: 0.08 };
		}
		if (f.includes('cocogoose')) {
			return { charRatio: 0.60, letterSpacingEm: 0.12 };
		}
		if (f.includes('pacifico')) {
			return { charRatio: 0.48, letterSpacingEm: 0.02 };
		}
		if (f.includes('cinzel')) {
			return { charRatio: 0.62, letterSpacingEm: 0.10 };
		}
		if (f.includes('marker')) {
			return { charRatio: 0.58, letterSpacingEm: 0.05 };
		}
		if (f.includes('abril')) {
			return { charRatio: 0.55, letterSpacingEm: 0.04 };
		}
		return { charRatio: 0.56, letterSpacingEm: 0.08 };
	}

	/**
	 * Render a TEXT node with per-letter COLOR_SET cycling, auto-fit boundary constraints, and outsideOnly stroke.
	 *
	 * @param {Object} node TEXT node definition.
	 * @return {string}
	 */
	static renderTextNode(node, options = {}) {
		const val = String(node.value || node.text || '');
		if (!val.trim()) return '';

		const ratio = (options.ratio && options.ratio > 0) ? options.ratio : 1.0;
		const hasPerLine = Boolean(options.hasPerLineBoxes);

		const hasBox = (node.box_width !== undefined && node.box_height !== undefined);
		let boxW = hasBox ? parseFloat(node.box_width) : (parseFloat(node.maxWidth) || 84.0);
		let boxH = hasBox ? parseFloat(node.box_height) : (parseFloat(node.fontSize) ? parseFloat(node.fontSize) * 1.5 : 12.0);
		let boxL = (node.box_left !== undefined) ? parseFloat(node.box_left) : ((parseFloat(node.x) || 50) - (boxW / 2));
		let boxT = (node.box_top !== undefined) ? parseFloat(node.box_top) : ((parseFloat(node.y) || 50) - (boxH / 2));

		if (options.cropBoundingBox && options.cropBoundingBox.spanW > 0 && options.cropBoundingBox.spanH > 0) {
			const c = options.cropBoundingBox;
			boxL = ((boxL - c.xMin) / c.spanW) * 100;
			boxT = ((boxT - c.yMin) / c.spanH) * 100;
			boxW = (boxW / c.spanW) * 100;
			boxH = (boxH / c.spanH) * 100;
		}

		const x = boxL + (boxW / 2);
		const y = (hasPerLine && ratio !== 1.0) ? ((boxT + (boxH / 2)) / ratio) : (boxT + (boxH / 2));

		const rawFont = node.fontFamily || 'Super Mario Wii';
		const fontFamily = (rawFont.includes('sans-serif') || rawFont.includes('serif'))
			? rawFont
			: `'${rawFont}', 'Super Mario Wii', 'SuperMario256', Arial Black, Impact, -apple-system, sans-serif`;

		const metrics = this.getFontMetrics(rawFont);
		const letterSpacingEm = node.letterSpacing !== undefined ? parseFloat(node.letterSpacing) : metrics.letterSpacingEm;
		const letterSpacing = `${letterSpacingEm}em`;
		const casing = node.casing || (node.uppercase === false ? 'ORIGINAL' : 'UPPER');
		const displayText = (casing === 'UPPER') ? val.toUpperCase() : val;
		const lineHeight = parseFloat(node.lineHeight) || 0.95;

		// Calculate text lines
		const lines = displayText.split('\n');
		const isMultiLine = lines.length > 1 || node.multiLine;
		const numLines = Math.max(1, lines.length);

		// Base font size derived directly from box height if box is present
		let baseFontSize = parseFloat(node.fontSize);
		if (isNaN(baseFontSize) || baseFontSize <= 0 || hasBox) {
			const effectiveBoxH = (hasPerLine && ratio !== 1.0) ? (boxH / ratio) : boxH;
			const heightTarget = (effectiveBoxH * 0.85) / (1 + (numLines - 1) * lineHeight * 0.8);
			baseFontSize = (!isNaN(parseFloat(node.fontSize)) && parseFloat(node.fontSize) > 0 && !hasBox)
				? parseFloat(node.fontSize)
				: heightTarget;
		}

		// Calculate maximum line length
		let maxLineLength = 0;
		for (const l of lines) {
			if (l.length > maxLineLength) maxLineLength = l.length;
		}

		// Calculate unscaled text width & height
		const maxWidth = hasBox ? (boxW * 0.95) : (parseFloat(node.maxWidth) || 84.0);
		const maxHeight = hasBox ? ((hasPerLine && ratio !== 1.0) ? ((boxH * 0.92) / ratio) : (boxH * 0.92)) : (parseFloat(node.maxHeight) || 80.0);
		const estimatedRawWidth = baseFontSize * (maxLineLength * metrics.charRatio + Math.max(0, maxLineLength - 1) * letterSpacingEm);

		// Calculate width scale factor
		let scaleW = 1.0;
		if (estimatedRawWidth > maxWidth && maxWidth > 0) {
			scaleW = maxWidth / estimatedRawWidth;
		}

		// Calculate multi-line height constraint if applicable
		let scaleH = 1.0;
		const rawTotalHeight = baseFontSize + ((numLines - 1) * baseFontSize * lineHeight);
		if (rawTotalHeight > maxHeight && maxHeight > 0) {
			scaleH = maxHeight / rawTotalHeight;
		}

		// Combined auto-fit scale factor
		const autoFitScale = Math.min(1.0, scaleW, scaleH);
		const effectiveFontSize = Math.max(2.0, baseFontSize * autoFitScale);
		const effectiveLineWidth = Math.min(maxWidth, estimatedRawWidth * autoFitScale);

		// SVG Native Absolute Barrier: textLength constraint when auto-scaled
		const textLengthAttr = (!isMultiLine && autoFitScale < 0.99)
			? `textLength="${effectiveLineWidth.toFixed(2)}" lengthAdjust="spacingAndGlyphs"`
			: '';

		// Stroke styling (scaled proportionally to font size)
		const baseStrokeWidth = (node.stroke && node.stroke.width) ? parseFloat(node.stroke.width) : (node.strokeWidth !== undefined ? parseFloat(node.strokeWidth) : 0.72);
		const strokeWidth = (baseStrokeWidth * autoFitScale).toFixed(3);
		const strokeColor = (node.colorSet && node.colorSet.strokeHex) ? node.colorSet.strokeHex : (node.stroke && node.stroke.color ? node.stroke.color : '#000000');
		const paintOrder = 'paint-order: stroke fill; stroke-linejoin: round; stroke-linecap: round;';

		// Color set handling (multi-color vs solid)
		const colorSet = node.colorSet || {};
		const isMulti = colorSet.colorMode === 'multi' && Array.isArray(colorSet.palette) && colorSet.palette.length > 0;
		const palette = isMulti ? colorSet.palette : [node.fill?.color?.hex || node.fill || colorSet.colorHex || '#ffffff'];

		// Filter choice: embroidery relief vs 3d shadow
		const isEmbroidery = node.fontStyle === 'embroidery' || (colorSet.id && colorSet.id.includes('embroidery')) || strokeColor === 'transparent';
		const filterAttr = isEmbroidery ? 'filter="url(#mrtee-embroidery-relief)"' : 'filter="url(#mrtee-3d-shadow)"';

		let tspans = '';
		let charIndex = 0;

		if (isMultiLine) {
			const totalLines = lines.length;
			const startYOffset = -((totalLines - 1) / 2) * (effectiveFontSize * lineHeight);

			for (let lineIdx = 0; lineIdx < totalLines; lineIdx++) {
				const lineText = lines[lineIdx];
				const dy = lineIdx === 0 ? startYOffset : (effectiveFontSize * lineHeight);

				// Line-level textLength constraint if line exceeds box width
				const lineEstW = effectiveFontSize * (lineText.length * metrics.charRatio + Math.max(0, lineText.length - 1) * letterSpacingEm);
				const lineLengthAttr = (lineEstW > maxWidth && maxWidth > 0)
					? ` textLength="${maxWidth.toFixed(2)}" lengthAdjust="spacingAndGlyphs"`
					: '';

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

				tspans += `<tspan x="${x}" dy="${dy.toFixed(2)}"${lineLengthAttr}>${lineTspans}</tspan>`;
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
			font-size="${effectiveFontSize.toFixed(2)}"
			text-anchor="${node.textAnchor || 'middle'}"
			dominant-baseline="central"
			letter-spacing="${letterSpacing}"
			stroke="${strokeColor}"
			stroke-width="${strokeWidth}"
			style="${paintOrder}"
			${textLengthAttr}
			${filterAttr}
		>${tspans}</text>`;
	}

	/**
	 * Render an IMAGE node (e.g. Mushroom packshot or texture).
	 *
	 * @param {Object} node IMAGE node definition.
	 * @return {string}
	 */
	static renderImageNode(node, options = {}) {
		const url = node.url || '';
		if (!url) return '';

		const ratio = (options.ratio && options.ratio > 0) ? options.ratio : 1.0;
		const hasPerLine = Boolean(options.hasPerLineBoxes);

		const x = parseFloat(node.x) || 30;
		const rawY = parseFloat(node.y) || 50;
		const y = (hasPerLine && ratio !== 1.0) ? (rawY / ratio) : rawY;
		const w = parseFloat(node.width) || 21;
		const rawH = parseFloat(node.height) || 19;
		const h = (hasPerLine && ratio !== 1.0) ? (rawH / ratio) : rawH;

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
	 * @param {Object} options Rendering options.
	 * @return {string}
	 */
	static renderPathNode(node, options = {}) {
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
