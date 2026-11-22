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
	 * Filter a scene-graph layer tree so only layers belonging to `sideId` remain.
	 * Layers without an explicit `side_id` / `sideId` default to `'front'`.
	 *
	 * @param {Array} layers Scene-graph layers array.
	 * @param {string} sideId Target print side identifier (e.g. 'front', 'back').
	 * @return {Array}
	 */
	static filterLayersBySide(layers, sideId) {
		if (!sideId || !Array.isArray(layers)) {
			return Array.isArray(layers) ? layers : [];
		}
		const target = String(sideId);
		const filtered = [];
		for (const node of layers) {
			if (!node || node.hide) continue;
			if (node.type === 'GROUP') {
				const groupSide = node.side_id || node.sideId || null;
				if (groupSide && String(groupSide) !== target) {
					continue;
				}
				const childItems = this.filterLayersBySide(node.items || [], target);
				if (childItems.length > 0) {
					filtered.push(Object.assign({}, node, { items: childItems }));
				}
			} else if (node.type === 'MASK') {
				const firstChild = Array.isArray(node.items) && node.items[0] ? node.items[0] : null;
				const maskSide = node.side_id || node.sideId || (firstChild && (firstChild.side_id || firstChild.sideId)) || 'front';
				if (String(maskSide) === target) {
					filtered.push(node);
				}
			} else {
				const nodeSide = node.side_id || node.sideId || 'front';
				if (String(nodeSide) === target) {
					filtered.push(node);
				}
			}
		}
		return filtered;
	}

	/**
	 * Resolve optional per-view placement override (`node.placements[viewId]`) for a scene-graph node.
	 *
	 * @param {Object} node Scene-graph node.
	 * @param {string} viewId Target preview view identifier.
	 * @return {Object|null}
	 */
	static resolveNodeViewPlacement(node, viewId) {
		if (!viewId || !node || !node.placements || typeof node.placements !== 'object') {
			return null;
		}
		const vp = node.placements[viewId];
		return (vp && typeof vp === 'object') ? vp : null;
	}

	/**
	 * Build complete SVG string from merged layers and zoom transform.
	 *
	 * @param {Array} layers Merged scene-graph layers.
	 * @param {Object} zoomTransform { scale, translateX, translateY }
	 * @param {Object} options Rendering options (e.g. isExport, fontFaceCss, idPrefix, sideId, viewId).
	 * @return {string} Valid SVG XML markup string.
	 */
	static renderToString(layers, zoomTransform = { scale: 1, translateX: 0, translateY: 0 }, options = {}) {
		const z = zoomTransform || { scale: 1, translateX: 0, translateY: 0 };
		const scale = z.scale !== undefined ? z.scale : 1;
		const tx = z.translateX !== undefined ? z.translateX : 0;
		const ty = z.translateY !== undefined ? z.translateY : 0;
		const idPrefix = options.idPrefix ? String(options.idPrefix).replace(/[^a-zA-Z0-9_-]/g, '') : '';
		const activeLayers = options.sideId ? this.filterLayersBySide(layers, options.sideId) : (Array.isArray(layers) ? layers : []);

		const needsStandaloneFontCss = Boolean(options.isExport || options.useThumbCache || options.fontFaceCss);
		const fontFaceCss = needsStandaloneFontCss
			? (options.fontFaceCss
				|| (typeof window !== 'undefined' && window.mrTeeOrderExportData && window.mrTeeOrderExportData.fontFaceCss)
				|| (typeof window !== 'undefined' && window.mrTeeProductData && window.mrTeeProductData.fontFaceCss)
				|| '')
			: '';
		let defs = '';
		if (fontFaceCss) {
			defs += `<style><![CDATA[\n${fontFaceCss}\n]]></style>`;
		}

		// Crisp proportional-friendly drop shadow & embroidery relief filters
		defs += `
			<filter id="${idPrefix}mrtee-3d-shadow" x="-20%" y="-20%" width="140%" height="140%">
				<feDropShadow dx="0" dy="0.35" stdDeviation="0.18" flood-color="#000000" flood-opacity="0.28" />
			</filter>
			<filter id="${idPrefix}mrtee-embroidery-relief" x="-12%" y="-12%" width="124%" height="124%">
				<feDropShadow dx="0" dy="0.22" stdDeviation="0.12" flood-color="#000000" flood-opacity="0.26" />
			</filter>
		`;

		// Append OpenType-SVG font defs (gradients, masks, filters) if present
		if (Array.isArray(activeLayers)) {
			const appendFontDefs = (nodes) => {
				for (const n of nodes) {
					if (!n) continue;
					const fKey = n.fontId || n.fontFamily;
					if (fKey) {
						const sData = this.getSvgFontData(fKey, options);
						if (sData && sData.defs) {
							const fontDefs = idPrefix ? this.scopeMarkupIds(sData.defs, idPrefix) : sData.defs;
							if (!defs.includes(fontDefs)) {
								defs += `\n<!-- Font Defs for ${sData.id} -->\n${fontDefs}\n`;
							}
						}
					}
					if (Array.isArray(n.items)) appendFontDefs(n.items);
				}
			};
			appendFontDefs(activeLayers);
		}

		const nodeHasBox = (n) => Boolean(
			n && (
				n.box_top !== undefined
				|| (options.viewId && n.placements && n.placements[options.viewId] && n.placements[options.viewId].box_top !== undefined)
			)
		);
		const hasPerLineBoxes = Boolean(options.hasPerLineBoxes) || (Array.isArray(activeLayers) && activeLayers.some(n => nodeHasBox(n) || (Array.isArray(n.items) && n.items.some(c => nodeHasBox(c)))));
		const ratio = (options.aspectRatio && parseFloat(options.aspectRatio) > 0)
			? parseFloat(options.aspectRatio)
			: (options.realWidth && options.realHeight ? (parseFloat(options.realWidth) / parseFloat(options.realHeight)) : 1.0);

		const vbWidth = 100;
		const vbHeight = (hasPerLineBoxes && ratio > 0 && Math.abs(ratio - 1.0) > 0.001) ? Number((100 / ratio).toFixed(4)) : 100;
		const viewBoxAttr = `viewBox="0 0 ${vbWidth} ${vbHeight}"`;

		const extraDefs = [];
		const layersMarkup = this.renderLayersMarkup(activeLayers, { ...options, idPrefix, ratio, hasPerLineBoxes, vbHeight, _extraDefs: extraDefs });
		if (extraDefs.length > 0) {
			defs += '\n' + extraDefs.join('\n');
		}
		const widthAttr = (options.isExport && options.realWidth) ? `${options.realWidth}${options.unit || 'cm'}` : '100%';
		const heightAttr = (options.isExport && options.realHeight) ? `${options.realHeight}${options.unit || 'cm'}` : '100%';
		const realDimAttrs = (options.realWidth && options.realHeight) ? `data-real-width="${options.realWidth}" data-real-height="${options.realHeight}" data-unit="${options.unit || 'cm'}" data-dpi="${options.dpi || 300}"` : '';

		const innerContent = hasPerLineBoxes
			? `<g id="${idPrefix}mr-tee-scene-root" class="mr-tee-scene-root">${layersMarkup}</g>`
			: `<g id="${idPrefix}mr-tee-scene-root" class="mr-tee-scene-root" transform="translate(${tx.toFixed(3)}, ${ty.toFixed(3)}) translate(50, 50) scale(${scale.toFixed(4)}) translate(-50, -50)">${layersMarkup}</g>`;

		return `
<svg
	id="${idPrefix}mr-tee-svg-element"
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
	<defs id="${idPrefix}mr-tee-svg-defs" class="mr-tee-svg-defs">
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
		const idPrefix = options.idPrefix ? String(options.idPrefix).replace(/[^a-zA-Z0-9_-]/g, '') : '';
		const layerOptions = (options.idPrefix && options.idPrefix !== idPrefix)
			? { ...options, idPrefix }
			: options;

		let html = '';
		for (const node of layers) {
			if (!node || node.hide) continue;

			if (node.type === 'GROUP') {
				const groupClass = node.name ? `class="mrtee-group mrtee-group-${node.name.toLowerCase().replace(/\s+/g, '-')}"` : '';
				const childrenHtml = this.renderLayersMarkup(node.items || [], layerOptions);
				html += `<g ${groupClass}>${childrenHtml}</g>\n`;
			} else if (node.type === 'TEXT') {
				html += this.renderTextNode(node, layerOptions) + '\n';
			} else if (node.type === 'IMAGE') {
				html += this.renderImageNode(node, layerOptions) + '\n';
			} else if (node.type === 'PATH') {
				html += this.renderPathNode(node, layerOptions) + '\n';
			} else if (node.type === 'MASK') {
				html += this.renderMaskNode(node, layerOptions) + '\n';
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
	 * Measure a single text line's width in `em` units (i.e. width / fontSize) using a
	 * cached offscreen 2D Canvas when the custom font is loaded, falling back to `getFontMetrics()`.
	 *
	 * @param {string} text Single line of text.
	 * @param {string} fontFamily CSS font-family stack.
	 * @param {number} letterSpacingEm Letter spacing in em units.
	 * @param {string} rawFont Raw font family identifier for fallback metrics.
	 * @return {number} Width in em units.
	 */
	static measureTextWidthUnits(text, fontFamily, letterSpacingEm = 0, rawFont = '') {
		const str = String(text || '');
		if (!str) return 0;

		const spacing = Number.isFinite(letterSpacingEm) ? letterSpacingEm : 0;
		const spacingUnits = Math.max(0, str.length - 1) * spacing;

		if (typeof document !== 'undefined' && typeof document.createElement === 'function') {
			const primaryFamily = String(fontFamily || '').split(',')[0].trim().replace(/^['"]+|['"]+$/g, '');
			const isGeneric = /^(sans-serif|serif|monospace|cursive|fantasy|system-ui|arial|helvetica|georgia|impact)$/i.test(primaryFamily);
			let fontReady = isGeneric;

			if (!fontReady && primaryFamily && document.fonts && typeof document.fonts.check === 'function') {
				try {
					fontReady = document.fonts.check(`100px "${primaryFamily}"`);
				} catch (e) {
					fontReady = false;
				}
			}

			if (fontReady) {
				this._textWidthCache = this._textWidthCache || new Map();
				const cacheKey = `${fontFamily}|${spacing.toFixed(4)}|${str}`;
				if (this._textWidthCache.has(cacheKey)) {
					return this._textWidthCache.get(cacheKey);
				}

				if (!this._measureCtx) {
					const canvas = document.createElement('canvas');
					this._measureCtx = canvas.getContext('2d');
				}
				if (this._measureCtx) {
					const refPx = 100;
					this._measureCtx.font = `${refPx}px ${fontFamily}`;
					const measured = this._measureCtx.measureText(str).width;
					if (measured > 0) {
						const units = (measured / refPx) + spacingUnits;
						if (this._textWidthCache.size >= 500) {
							this._textWidthCache.clear();
						}
						this._textWidthCache.set(cacheKey, units);
						return units;
					}
				}
			}
		}

		const metrics = this.getFontMetrics(rawFont || fontFamily);
		return (str.length * metrics.charRatio) + spacingUnits;
	}

	/**
	 * Render a TEXT node with per-letter COLOR_SET cycling, uniform auto-fit scaling, and outsideOnly stroke.
	 *
	 * @param {Object} node TEXT node definition.
	 * @param {Object} options Rendering options.
	 * @return {string}
	 */
	static renderTextNode(node, options = {}) {
		const val = String(node.value || node.text || '');
		if (!val.trim()) return '';

		const vp = this.resolveNodeViewPlacement(node, options.viewId);
		if (vp && (vp.hidden === true || vp.visible === false)) {
			return '';
		}

		const idPrefix = options.idPrefix ? String(options.idPrefix).replace(/[^a-zA-Z0-9_-]/g, '') : '';
		const ratio = (options.ratio && options.ratio > 0) ? options.ratio : 1.0;
		const hasPerLine = Boolean(options.hasPerLineBoxes);

		const effBoxW = (vp && vp.box_width !== undefined) ? vp.box_width : node.box_width;
		const effBoxH = (vp && vp.box_height !== undefined) ? vp.box_height : node.box_height;
		const effBoxL = (vp && vp.box_left !== undefined) ? vp.box_left : node.box_left;
		const effBoxT = (vp && vp.box_top !== undefined) ? vp.box_top : node.box_top;
		const effRotate = (vp && vp.box_rotate !== undefined)
			? vp.box_rotate
			: ((vp && vp.rotate !== undefined) ? vp.rotate : (node.box_rotate !== undefined ? node.box_rotate : node.rotate));

		const hasBox = (effBoxW !== undefined && effBoxH !== undefined);
		let boxW = hasBox ? parseFloat(effBoxW) : (parseFloat(node.maxWidth) || 84.0);
		let boxH = hasBox ? parseFloat(effBoxH) : (parseFloat(node.fontSize) ? parseFloat(node.fontSize) * 1.5 : 12.0);
		let boxL = (effBoxL !== undefined) ? parseFloat(effBoxL) : ((parseFloat(node.x) || 50) - (boxW / 2));
		let boxT = (effBoxT !== undefined) ? parseFloat(effBoxT) : ((parseFloat(node.y) || 50) - (boxH / 2));

		if (options.cropBoundingBox && options.cropBoundingBox.spanW > 0 && options.cropBoundingBox.spanH > 0) {
			const c = options.cropBoundingBox;
			boxL = ((boxL - c.xMin) / c.spanW) * 100;
			boxT = ((boxT - c.yMin) / c.spanH) * 100;
			boxW = (boxW / c.spanW) * 100;
			boxH = (boxH / c.spanH) * 100;
		}

		const x = boxL + (boxW / 2);
		const y = (hasPerLine && ratio !== 1.0) ? ((boxT + (boxH / 2)) / ratio) : (boxT + (boxH / 2));

		const rawFont = node.fontFamily || (node.fontId ? node.fontId : 'sans-serif');
		const svgFontData = this.getSvgFontData(node.fontId || rawFont, options);
		if (svgFontData && svgFontData.type === 'opentype_svg') {
			return this.renderOpenTypeSvgTextNode(node, svgFontData, {
				boxL, boxT, boxW, boxH, x, y, hasBox, hasPerLine, ratio, val, rotateAngle: parseFloat(effRotate) || 0
			}, options);
		}

		let genericFallback = 'sans-serif';
		const fLower = String(rawFont).toLowerCase();
		if (fLower.includes('pacifico') || fLower.includes('cursive') || fLower.includes('script') || fLower.includes('marker')) {
			genericFallback = 'cursive, sans-serif';
		} else if (fLower.includes('cinzel') || fLower.includes('abril') || fLower.includes('serif')) {
			genericFallback = 'Georgia, serif';
		} else if (fLower.includes('press start') || fLower.includes('monospace') || fLower.includes('arcade')) {
			genericFallback = 'monospace';
		} else if (fLower.includes('bebas') || fLower.includes('bungee') || fLower.includes('luckiest') || fLower.includes('cocogoose')) {
			genericFallback = 'Impact, sans-serif';
		}

		const fontFamily = (rawFont.includes(',') || rawFont.includes('sans-serif') || rawFont.includes('serif') || rawFont.includes('cursive') || rawFont.includes('monospace'))
			? rawFont
			: `'${rawFont}', ${genericFallback}`;

		const metrics = this.getFontMetrics(rawFont);
		const letterSpacingEm = node.letterSpacing !== undefined ? parseFloat(node.letterSpacing) : metrics.letterSpacingEm;
		const letterSpacing = `${letterSpacingEm}em`;
		const casing = node.casing || (node.uppercase === true ? 'UPPER' : 'ORIGINAL');
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

		// Measure real line widths in em units (uniform scaling without horizontal distortion)
		let maxLineWidthUnits = 0;
		for (const l of lines) {
			const units = this.measureTextWidthUnits(l, fontFamily, letterSpacingEm, rawFont);
			if (units > maxLineWidthUnits) maxLineWidthUnits = units;
		}

		// Calculate unscaled text width & height
		const maxWidth = hasBox ? (boxW * 0.95) : (parseFloat(node.maxWidth) || 84.0);
		const maxHeight = hasBox ? ((hasPerLine && ratio !== 1.0) ? ((boxH * 0.92) / ratio) : (boxH * 0.92)) : (parseFloat(node.maxHeight) || 80.0);
		const estimatedRawWidth = baseFontSize * maxLineWidthUnits;

		// Uniform width scale factor
		let scaleW = 1.0;
		if (estimatedRawWidth > maxWidth && maxWidth > 0) {
			scaleW = maxWidth / estimatedRawWidth;
		}

		// Multi-line height constraint if applicable
		let scaleH = 1.0;
		const rawTotalHeight = baseFontSize + ((numLines - 1) * baseFontSize * lineHeight);
		if (rawTotalHeight > maxHeight && maxHeight > 0) {
			scaleH = maxHeight / rawTotalHeight;
		}

		// Combined uniform auto-fit scale factor (no lengthAdjust horizontal stretching!)
		const autoFitScale = Math.min(1.0, scaleW, scaleH);
		const effectiveFontSize = Math.max(1.5, baseFontSize * autoFitScale);

		// Stroke styling (scaled proportionally to font size)
		let baseStrokeWidth = 0;
		if (node.stroke && node.stroke.width !== undefined) {
			baseStrokeWidth = parseFloat(node.stroke.width);
		} else if (node.strokeWidth !== undefined) {
			baseStrokeWidth = parseFloat(node.strokeWidth);
		} else if (node.colorSet && node.colorSet.strokeWidth !== undefined) {
			baseStrokeWidth = parseFloat(node.colorSet.strokeWidth);
		} else {
			baseStrokeWidth = 0;
		}

		const strokeWidth = (baseStrokeWidth * autoFitScale).toFixed(3);
		let strokeColor = (node.stroke && node.stroke.color)
			? node.stroke.color
			: ((node.colorSet && node.colorSet.strokeHex) ? node.colorSet.strokeHex : 'transparent');

		if (baseStrokeWidth <= 0 || parseFloat(strokeWidth) <= 0) {
			strokeColor = 'transparent';
		}
		const paintOrder = 'paint-order: stroke fill; stroke-linejoin: round; stroke-linecap: round;';

		// Color set handling (multi-color vs solid)
		const colorSet = node.colorSet || {};
		const isMulti = colorSet.colorMode === 'multi' && Array.isArray(colorSet.palette) && colorSet.palette.length > 0;
		const palette = isMulti ? colorSet.palette : [node.fill?.color?.hex || node.fill || colorSet.colorHex || '#ffffff'];

		// Filter choice: embroidery relief vs 3d shadow (suppressed inside <mask> for sharp cutout edges)
		const isEmbroidery = node.fontStyle === 'embroidery' || (colorSet.id && colorSet.id.includes('embroidery')) || strokeColor === 'transparent';
		const filterAttr = node._inMask ? '' : (isEmbroidery ? `filter="url(#${idPrefix}mrtee-embroidery-relief)"` : `filter="url(#${idPrefix}mrtee-3d-shadow)"`);

		let tspans = '';
		let charIndex = 0;

		if (isMultiLine) {
			const totalLines = lines.length;
			const startYOffset = -((totalLines - 1) / 2) * (effectiveFontSize * lineHeight);

			for (let lineIdx = 0; lineIdx < totalLines; lineIdx++) {
				const lineText = lines[lineIdx];
				const dy = lineIdx === 0 ? startYOffset : (effectiveFontSize * lineHeight);

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

		const rotateAngle = parseFloat(effRotate) || 0;
		const transformAttr = (rotateAngle !== 0) ? `transform="rotate(${rotateAngle.toFixed(2)}, ${x.toFixed(2)}, ${y.toFixed(2)})"` : '';

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
			${transformAttr}
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
		let url = node.url || '';
		if (!url) return '';

		const vp = this.resolveNodeViewPlacement(node, options.viewId);
		if (vp && (vp.hidden === true || vp.visible === false)) {
			return '';
		}

		if (!options.isExport && typeof window !== 'undefined') {
			if (options.useThumbCache) {
				if (window.mrTeeThumbB64Cache && window.mrTeeThumbB64Cache[url]) {
					url = window.mrTeeThumbB64Cache[url];
				}
			} else if (window.mrTeeImageB64Cache && window.mrTeeImageB64Cache[url]) {
				url = window.mrTeeImageB64Cache[url];
			}
		}

		const idPrefix = options.idPrefix ? String(options.idPrefix).replace(/[^a-zA-Z0-9_-]/g, '') : '';
		const ratio = (options.ratio && options.ratio > 0) ? options.ratio : 1.0;
		const hasPerLine = Boolean(options.hasPerLineBoxes);

		const effBoxL = (vp && vp.box_left !== undefined) ? vp.box_left : node.box_left;
		const effBoxT = (vp && vp.box_top !== undefined) ? vp.box_top : node.box_top;
		const effBoxW = (vp && vp.box_width !== undefined) ? vp.box_width : node.box_width;
		const effBoxH = (vp && vp.box_height !== undefined) ? vp.box_height : node.box_height;
		const effRotate = (vp && vp.box_rotate !== undefined)
			? vp.box_rotate
			: ((vp && vp.rotate !== undefined) ? vp.rotate : (node.box_rotate !== undefined ? node.box_rotate : node.rotate));

		const isFieldImage = Boolean(node.isFieldImage || (effBoxW !== undefined && effBoxH !== undefined));

		if (isFieldImage) {
			let boxL = (effBoxL !== undefined) ? parseFloat(effBoxL) : (parseFloat(node.x) || 30);
			let boxT = (effBoxT !== undefined) ? parseFloat(effBoxT) : (parseFloat(node.y) || 30);
			let boxW = (effBoxW !== undefined) ? parseFloat(effBoxW) : (parseFloat(node.width) || 35);
			let boxH = (effBoxH !== undefined) ? parseFloat(effBoxH) : (parseFloat(node.height) || 30);

			if (options.cropBoundingBox && options.cropBoundingBox.spanW > 0 && options.cropBoundingBox.spanH > 0) {
				const c = options.cropBoundingBox;
				boxL = ((boxL - c.xMin) / c.spanW) * 100;
				boxT = ((boxT - c.yMin) / c.spanH) * 100;
				boxW = (boxW / c.spanW) * 100;
				boxH = (boxH / c.spanH) * 100;
			}

			const x = boxL;
			const w = Math.max(0.1, boxW);
			const y = (hasPerLine && ratio !== 1.0) ? (boxT / ratio) : boxT;
			const h = Math.max(0.1, (hasPerLine && ratio !== 1.0) ? (boxH / ratio) : boxH);

			const cx = x + (w / 2);
			const cy = y + (h / 2);
			const rotateAngle = parseFloat(effRotate) || 0;
			const transformAttr = (rotateAngle !== 0) ? ` transform="rotate(${rotateAngle.toFixed(2)}, ${cx.toFixed(4)}, ${cy.toFixed(4)})"` : '';

			const rawId = String(node.id || node.reference || node.name || `img_${Math.round(x * 100)}_${Math.round(y * 100)}`);
			const safeId = rawId.replace(/[^a-zA-Z0-9_-]/g, '_');
			const clipId = `${idPrefix}mrtee-img-clip-${safeId}`;
			const clipMarkup = `<clipPath id="${clipId}" clipPathUnits="userSpaceOnUse"><rect x="${x.toFixed(4)}" y="${y.toFixed(4)}" width="${w.toFixed(4)}" height="${h.toFixed(4)}" /></clipPath>`;

			let inlineDefs = '';
			if (Array.isArray(options._extraDefs)) {
				options._extraDefs.push(clipMarkup);
			} else {
				inlineDefs = `<defs>${clipMarkup}</defs>`;
			}

			const preserveRatio = (node.imageFit === 'cover') ? 'xMidYMid slice' : 'xMidYMid meet';
			const nodeIdAttr = node.reference ? ` data-node-id="${this.escapeXml(node.reference)}"` : (node.id ? ` data-node-id="${this.escapeXml(node.id)}"` : '');

			return `
		<g class="mrtee-image-field"${nodeIdAttr}${transformAttr}>
			${inlineDefs}
			<g clip-path="url(#${clipId})">
				<image
					href="${this.escapeXml(url)}"
					x="${x.toFixed(4)}"
					y="${y.toFixed(4)}"
					width="${w.toFixed(4)}"
					height="${h.toFixed(4)}"
					preserveAspectRatio="${preserveRatio}"
				/>
			</g>
		</g>`;
		}

		let x = parseFloat(node.x) || 30;
		let rawY = parseFloat(node.y) || 50;
		let w = parseFloat(node.width) || 21;
		let rawH = parseFloat(node.height) || 19;

		if (options.cropBoundingBox && options.cropBoundingBox.spanW > 0 && options.cropBoundingBox.spanH > 0) {
			const c = options.cropBoundingBox;
			x = ((x - c.xMin) / c.spanW) * 100;
			rawY = ((rawY - c.yMin) / c.spanH) * 100;
			w = (w / c.spanW) * 100;
			rawH = (rawH / c.spanH) * 100;
		}

		const y = (hasPerLine && ratio !== 1.0) ? (rawY / ratio) : rawY;
		const h = (hasPerLine && ratio !== 1.0) ? (rawH / ratio) : rawH;

		return `
		<image
			href="${this.escapeXml(url)}"
			x="${x}"
			y="${y}"
			width="${w}"
			height="${h}"
			preserveAspectRatio="xMidYMid meet"
			filter="url(#${idPrefix}mrtee-3d-shadow)"
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

		const idPrefix = options.idPrefix ? String(options.idPrefix).replace(/[^a-zA-Z0-9_-]/g, '') : '';
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
			filter="url(#${idPrefix}mrtee-3d-shadow)"
		/>`;
	}

	/**
	 * Render a MASK node (e.g. Leopard texture filled text).
	 *
	 * @param {Object} node MASK node definition.
	 * @param {Object} options Rendering options.
	 * @return {string}
	 */
	static renderMaskNode(node, options = {}) {
		const idPrefix = options.idPrefix ? String(options.idPrefix).replace(/[^a-zA-Z0-9_-]/g, '') : '';
		const maskId = `${idPrefix}mrtee-mask-${Math.random().toString(36).substr(2, 9)}`;
		const childItems = node.items || [];
		const textureUrl = node.textureUrl || (node.image && node.image.url) || this.getLocalAssetUrl('assets/images/leopard-pattern.png');

		const ratio = (options.ratio && options.ratio > 0) ? options.ratio : 1.0;
		const hasPerLine = Boolean(options.hasPerLineBoxes);
		const firstTextChild = childItems.find(c => c && c.type === 'TEXT') || {};
		const vp = this.resolveNodeViewPlacement(node, options.viewId) || this.resolveNodeViewPlacement(firstTextChild, options.viewId);
		if (vp && (vp.hidden === true || vp.visible === false)) {
			return '';
		}

		let boxL = (vp && vp.box_left !== undefined) ? parseFloat(vp.box_left)
			: ((node.box_left !== undefined) ? parseFloat(node.box_left)
				: (node.x !== undefined ? parseFloat(node.x)
					: (firstTextChild.box_left !== undefined ? parseFloat(firstTextChild.box_left) : 15)));
		let boxT = (vp && vp.box_top !== undefined) ? parseFloat(vp.box_top)
			: ((node.box_top !== undefined) ? parseFloat(node.box_top)
				: (node.y !== undefined ? parseFloat(node.y)
					: (firstTextChild.box_top !== undefined ? parseFloat(firstTextChild.box_top) : 20)));
		let boxW = (vp && vp.box_width !== undefined) ? parseFloat(vp.box_width)
			: ((node.box_width !== undefined) ? parseFloat(node.box_width)
				: (node.width !== undefined ? parseFloat(node.width)
					: (firstTextChild.box_width !== undefined ? parseFloat(firstTextChild.box_width) : 70)));
		let boxH = (vp && vp.box_height !== undefined) ? parseFloat(vp.box_height)
			: ((node.box_height !== undefined) ? parseFloat(node.box_height)
				: (node.height !== undefined ? parseFloat(node.height)
					: (firstTextChild.box_height !== undefined ? parseFloat(firstTextChild.box_height) : 60)));

		if (options.cropBoundingBox && options.cropBoundingBox.spanW > 0 && options.cropBoundingBox.spanH > 0) {
			const c = options.cropBoundingBox;
			boxL = ((boxL - c.xMin) / c.spanW) * 100;
			boxT = ((boxT - c.yMin) / c.spanH) * 100;
			boxW = (boxW / c.spanW) * 100;
			boxH = (boxH / c.spanH) * 100;
		}

		const x = boxL;
		const w = Math.max(0.1, boxW);
		const y = (hasPerLine && ratio !== 1.0) ? (boxT / ratio) : boxT;
		const h = Math.max(0.1, (hasPerLine && ratio !== 1.0) ? (boxH / ratio) : boxH);

		const cx = x + (w / 2);
		const cy = y + (h / 2);
		const rawRotate = (vp && vp.box_rotate !== undefined) ? vp.box_rotate
			: ((vp && vp.rotate !== undefined) ? vp.rotate
				: (node.box_rotate !== undefined ? node.box_rotate
					: (node.rotate !== undefined ? node.rotate
						: (firstTextChild.box_rotate !== undefined ? firstTextChild.box_rotate : firstTextChild.rotate))));
		const rotateAngle = parseFloat(rawRotate) || 0;
		const transformAttr = (rotateAngle !== 0) ? ` transform="rotate(${rotateAngle.toFixed(2)}, ${cx.toFixed(4)}, ${cy.toFixed(4)})"` : '';

		let maskContent = '';
		for (const child of childItems) {
			if (child.type === 'TEXT') {
				// Render mask text in solid crisp white (#ffffff) with zero stroke or blur
				const maskTextNode = Object.assign({}, child, {
					fill: '#ffffff',
					colorSet: null,
					stroke: { width: 0, color: 'transparent' },
					strokeWidth: 0,
					_inMask: true,
				});
				if (vp && (!child.placements || !child.placements[options.viewId])) {
					maskTextNode.box_left = (vp.box_left !== undefined) ? vp.box_left : maskTextNode.box_left;
					maskTextNode.box_top = (vp.box_top !== undefined) ? vp.box_top : maskTextNode.box_top;
					maskTextNode.box_width = (vp.box_width !== undefined) ? vp.box_width : maskTextNode.box_width;
					maskTextNode.box_height = (vp.box_height !== undefined) ? vp.box_height : maskTextNode.box_height;
					if (vp.box_rotate !== undefined || vp.rotate !== undefined) {
						maskTextNode.box_rotate = vp.box_rotate !== undefined ? vp.box_rotate : vp.rotate;
					}
				}
				maskContent += this.renderTextNode(maskTextNode, options);
			}
		}

		let textureHref = textureUrl;
		if (!options.isExport && typeof window !== 'undefined') {
			if (options.useThumbCache) {
				if (window.mrTeeThumbB64Cache && window.mrTeeThumbB64Cache[textureHref]) {
					textureHref = window.mrTeeThumbB64Cache[textureHref];
				}
			} else if (window.mrTeeImageB64Cache && window.mrTeeImageB64Cache[textureHref]) {
				textureHref = window.mrTeeImageB64Cache[textureHref];
			}
		}

		return `
		<mask id="${maskId}" maskUnits="userSpaceOnUse">
			${maskContent}
		</mask>
		<g mask="url(#${maskId})" filter="url(#${idPrefix}mrtee-3d-shadow)">
			<image
				href="${this.escapeXml(textureHref)}"
				x="${x.toFixed(4)}"
				y="${y.toFixed(4)}"
				width="${w.toFixed(4)}"
				height="${h.toFixed(4)}"
				preserveAspectRatio="xMidYMid slice"${transformAttr}
			/>
		</g>`;
	}

	/**
	 * Safely scope DOM IDs and internal fragment references (`id="X"`, `url(#X)`, `href="#X"`, `xlink:href="#X"`)
	 * inside an SVG markup string without touching data URLs, external URLs, or `data-*-id` attributes.
	 *
	 * @param {string} markup SVG markup string.
	 * @param {string} prefix Prefix to prepend to element IDs and local fragment references.
	 * @return {string}
	 */
	static scopeMarkupIds(markup, prefix) {
		if (!markup || !prefix) return markup;
		const cleanPrefix = String(prefix).replace(/[^a-zA-Z0-9_-]/g, '');
		if (!cleanPrefix) return markup;

		const str = String(markup);
		const preservedDataUrls = [];
		const work = str.includes('data:')
			? str.replace(/((?:xlink:href|href|src)\s*=\s*(?:"data:[^"]*"|'data:[^']*')|url\(\s*(?:"data:[^"]*"|'data:[^']*'|data:[^)\s]*)\s*\))/gi, (m) => {
				const token = `___MRTEE_PRESERVED_DATA_${preservedDataUrls.length}___`;
				preservedDataUrls.push(m);
				return token;
			})
			: str;

		const prefixId = (id) => (id && !id.startsWith(cleanPrefix) ? `${cleanPrefix}${id}` : id);

		let scoped = work
			.replace(/(^|[\s"'<])id=(["'])([^"']+)\2/g, (m, lead, quote, id) => {
				return `${lead}id=${quote}${prefixId(id)}${quote}`;
			})
			.replace(/url\(\s*(['"]?)#([^)'"\s]+)\1\s*\)/g, (m, quote, id) => {
				return `url(${quote}#${prefixId(id)}${quote})`;
			})
			.replace(/(^|[\s"'<])(xlink:href|href)=(["'])#([^"']+)\3/g, (m, lead, attr, quote, id) => {
				return `${lead}${attr}=${quote}#${prefixId(id)}${quote}`;
			});

		if (preservedDataUrls.length > 0) {
			scoped = scoped.replace(/___MRTEE_PRESERVED_DATA_(\d+)___/g, (_, idx) => preservedDataUrls[Number(idx)]);
		}
		return scoped;
	}

	/**
	 * Resolve a plugin-relative local asset path using localized `pluginUrl`,
	 * normalizing same-origin URLs to relative paths matching `hydrateManifest()`.
	 *
	 * @param {string} relativePath Asset path relative to plugin root.
	 * @return {string}
	 */
	static getLocalAssetUrl(relativePath = '') {
		const cleanRel = String(relativePath || '').replace(/^\/+/, '');
		const rawPluginUrl = (typeof window !== 'undefined' && window.mrTeeProductData && window.mrTeeProductData.pluginUrl)
			|| (typeof window !== 'undefined' && window.mrTeeAdminData && window.mrTeeAdminData.pluginUrl)
			|| (typeof window !== 'undefined' && window.mrTeeOrderExportData && window.mrTeeOrderExportData.pluginUrl)
			|| '/wp-content/plugins/mr_tee_editor/';

		let baseUrl = rawPluginUrl;
		try {
			const hasWin = typeof window !== 'undefined' && window.location;
			const origin = (hasWin && window.location.origin && window.location.origin !== 'null')
				? window.location.origin
				: 'http://localhost';
			const parsed = new URL(rawPluginUrl, origin);
			if (!hasWin || parsed.hostname === window.location.hostname) {
				baseUrl = parsed.pathname;
			} else {
				baseUrl = parsed.href;
			}
		} catch (e) {
			baseUrl = rawPluginUrl;
		}
		if (!baseUrl.endsWith('/')) baseUrl += '/';
		return `${baseUrl}${cleanRel}`;
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

	/**
	 * Retrieve parsed OpenType-SVG font dataset.
	 *
	 * @param {string} fontKey Font identifier or family name.
	 * @param {Object} options Rendering options.
	 * @return {Object|null}
	 */
	static getSvgFontData(fontKey, options = {}) {
		if (!fontKey) return null;
		const cleanKey = String(fontKey).toLowerCase().replace(/[\s\-]+/g, '_');

		if (options.svgFonts && options.svgFonts[cleanKey]) {
			return this.prepareFontData(options.svgFonts[cleanKey], cleanKey);
		}
		if (typeof window !== 'undefined') {
			window.mrTeeSvgFonts = window.mrTeeSvgFonts || {};
			if (window.mrTeeSvgFonts[cleanKey]) {
				return this.prepareFontData(window.mrTeeSvgFonts[cleanKey], cleanKey);
			}
			if (window.mrTeeSvgFonts[fontKey]) {
				return this.prepareFontData(window.mrTeeSvgFonts[fontKey], cleanKey);
			}

			// Preloaded fonts in mrTeeProductData, mrTeeAdminData, or mrTeeOrderExportData
			const preloaded = (window.mrTeeProductData && window.mrTeeProductData.svgFonts)
				|| (window.mrTeeAdminData && window.mrTeeAdminData.svgFonts)
				|| (window.mrTeeOrderExportData && window.mrTeeOrderExportData.svgFonts)
				|| {};
			if (preloaded[cleanKey]) {
				const prepared = this.prepareFontData(preloaded[cleanKey], cleanKey);
				window.mrTeeSvgFonts[cleanKey] = prepared;
				return prepared;
			}
			if (preloaded[fontKey]) {
				const prepared = this.prepareFontData(preloaded[fontKey], cleanKey);
				window.mrTeeSvgFonts[fontKey] = prepared;
				return prepared;
			}

			// Check registry definitions for aliases
			const fonts = (window.mrTeeProductData && window.mrTeeProductData.fonts)
				|| (window.mrTeeAdminData && window.mrTeeAdminData.fonts)
				|| (window.mrTeeOrderExportData && window.mrTeeOrderExportData.fonts)
				|| {};
			for (const [id, def] of Object.entries(fonts)) {
				if (id === fontKey || id === cleanKey || (def.family && def.family.toLowerCase() === String(fontKey).toLowerCase())) {
					if (window.mrTeeSvgFonts[id]) return this.prepareFontData(window.mrTeeSvgFonts[id], id);
					if (preloaded[id]) {
						const prepared = this.prepareFontData(preloaded[id], id);
						window.mrTeeSvgFonts[id] = prepared;
						return prepared;
					}
				}
			}
		}

		return null;
	}

	/**
	 * Strip redundant `id="..."` attributes (e.g. duplicate `id="Layer_1_copy_2"` emitted by
	 * Adobe Illustrator font exporters) that are never referenced by `url(#...)` or `href="#..."`.
	 *
	 * @param {string} svgStr Glyph SVG markup.
	 * @param {string} extraContext Optional font-level `<defs>` markup to scan for fragment references.
	 * @return {string}
	 */
	static stripUnreferencedIds(svgStr, extraContext = '') {
		if (!svgStr || !svgStr.includes('id=')) return svgStr || '';
		const combined = extraContext ? `${svgStr}\n${extraContext}` : svgStr;
		const referenced = new Set();

		const urlRe = /url\(\s*['"]?#([^)'"\s]+)['"]?\s*\)/g;
		let m;
		while ((m = urlRe.exec(combined)) !== null) {
			if (m[1]) referenced.add(m[1]);
		}
		const hrefRe = /(?:xlink:href|href)\s*=\s*["']#([^"']+)["']/g;
		while ((m = hrefRe.exec(combined)) !== null) {
			if (m[1]) referenced.add(m[1]);
		}

		return svgStr.replace(/\s+id=(["'])([^"']+)\1/g, (full, quote, id) => {
			return referenced.has(id) ? full : '';
		});
	}

	/**
	 * Inspect ancestor `<g transform="...">` wrappers around an `<image>` glyph element to compute
	 * its actual `[minX, maxX, minY, maxY]` bounds in font design units.
	 *
	 * @param {string} svgStr Glyph SVG markup.
	 * @return {{ minX: number, maxX: number, minY: number, maxY: number }|null}
	 */
	static parseImageGlyphGeometry(svgStr) {
		if (!svgStr || !svgStr.includes('<image')) return null;

		const cleaned = svgStr.replace(/<g\b[^>]*>\s*<\/g>/gi, '');
		const imgMatch = cleaned.match(/<image\b([^>]*)>/i);
		if (!imgMatch) return null;

		const attrs = imgMatch[1];
		const hMatch = attrs.match(/\bheight\s*=\s*["']([^"']+)["']/i);
		const wMatch = attrs.match(/\bwidth\s*=\s*["']([^"']+)["']/i);
		const xMatch = attrs.match(/\bx\s*=\s*["']([^"']+)["']/i);
		const yMatch = attrs.match(/\by\s*=\s*["']([^"']+)["']/i);

		const imgH = hMatch ? parseFloat(hMatch[1]) : 0;
		const imgW = wMatch ? parseFloat(wMatch[1]) : 0;
		if (!(imgH > 0)) return null;
		const imgX = xMatch ? (parseFloat(xMatch[1]) || 0) : 0;
		const imgY = yMatch ? (parseFloat(yMatch[1]) || 0) : 0;

		const beforeImg = cleaned.slice(0, imgMatch.index);
		const stack = [];
		const tagRe = /<\/?g\b[^>]*>/gi;
		let tm;
		while ((tm = tagRe.exec(beforeImg)) !== null) {
			const tag = tm[0];
			if (tag.startsWith('</')) {
				if (stack.length) stack.pop();
			} else if (!tag.endsWith('/>')) {
				const trMatch = tag.match(/\btransform\s*=\s*["']([^"']+)["']/i);
				stack.push(trMatch ? trMatch[1] : '');
			}
		}

		let sx = 1;
		let sy = 1;
		let tx = 0;
		let ty = 0;

		const opRe = /(translate|scale)\(\s*([^)]+)\)/gi;
		for (const tr of stack) {
			if (!tr) continue;
			opRe.lastIndex = 0;
			let om;
			while ((om = opRe.exec(tr)) !== null) {
				const fn = om[1].toLowerCase();
				const nums = om[2].trim().split(/[\s,]+/).map(parseFloat).filter(n => !isNaN(n));
				if (fn === 'translate' && nums.length >= 1) {
					const dx = nums[0];
					const dy = nums.length >= 2 ? nums[1] : 0;
					tx += sx * dx;
					ty += sy * dy;
				} else if (fn === 'scale' && nums.length >= 1) {
					const nsx = nums[0];
					const nsy = nums.length >= 2 ? nums[1] : nsx;
					sx *= nsx;
					sy *= nsy;
				}
			}
		}

		const y1 = ty + (sy * imgY);
		const y2 = ty + (sy * (imgY + imgH));
		const x1 = tx + (sx * imgX);
		const x2 = tx + (sx * (imgX + imgW));

		return {
			minX: Math.min(x1, x2),
			maxX: Math.max(x1, x2),
			minY: Math.min(y1, y2),
			maxY: Math.max(y1, y2),
		};
	}

	/**
	 * Compute horizontal `[minX, maxX]` coordinate span across vector `<path d="...">` glyph commands.
	 *
	 * @param {string} svgStr Glyph SVG markup.
	 * @return {{ minX: number, maxX: number }|null}
	 */
	static parseVectorPathBoundsX(svgStr) {
		if (!svgStr || !svgStr.includes('<path')) return null;
		let minX = Infinity;
		let maxX = -Infinity;
		const pathRe = /\bd\s*=\s*["']([^"']+)["']/gi;
		let pm;

		while ((pm = pathRe.exec(svgStr)) !== null) {
			const d = pm[1];
			const tokenRe = /([MLCQSTHVZmlcqsthvz])|(-?\d*\.?\d+(?:e[-+]?\d+)?)/g;
			let cmd = '';
			let argIdx = 0;
			let curX = 0;
			let tm;

			while ((tm = tokenRe.exec(d)) !== null) {
				if (tm[1]) {
					cmd = tm[1];
					argIdx = 0;
					continue;
				}
				const val = parseFloat(tm[2]);
				if (isNaN(val)) continue;

				if (cmd === 'M' || cmd === 'L' || cmd === 'T' || cmd === 'C' || cmd === 'Q' || cmd === 'S') {
					if (argIdx % 2 === 0) {
						curX = val;
						if (val < minX) minX = val;
						if (val > maxX) maxX = val;
					}
					argIdx++;
				} else if (cmd === 'H') {
					curX = val;
					if (val < minX) minX = val;
					if (val > maxX) maxX = val;
					argIdx++;
				} else if (cmd === 'm' || cmd === 'l' || cmd === 't') {
					if (argIdx % 2 === 0) {
						curX += val;
						if (curX < minX) minX = curX;
						if (curX > maxX) maxX = curX;
					}
					argIdx++;
				} else if (cmd === 'h') {
					curX += val;
					if (curX < minX) minX = curX;
					if (curX > maxX) maxX = curX;
					argIdx++;
				} else {
					argIdx++;
				}
			}
		}

		return (minX < Infinity && maxX > -Infinity) ? { minX, maxX } : null;
	}

	/**
	 * Calculate true optical vertical center factor (relative to `ascent`) for an OpenType-SVG font
	 * by inspecting uppercase bitmap glyph `<g transform="..."><image height="...">` coordinates.
	 * Falls back to `0.42` for vector OpenType-SVG fonts without `<image>`.
	 *
	 * @param {Object} fontData OpenType-SVG font dataset.
	 * @return {number}
	 */
	static computeFontVisualCenterFactor(fontData) {
		if (!fontData) return 0.42;
		if (typeof fontData._visualCenterFactor === 'number' && fontData._visualCenterFactor > 0) {
			return fontData._visualCenterFactor;
		}

		const glyphs = fontData.glyphs || {};
		const ascent = fontData.ascent || 800;
		const sampleChars = ['A', 'B', 'E', 'H', 'M', 'O', 'S', 'T', 'N', 'R'];
		const factors = [];

		for (const ch of sampleChars) {
			const g = glyphs[ch] || glyphs[ch.toLowerCase()];
			if (!g || !g.svg) continue;
			const geom = this.parseImageGlyphGeometry(g.svg);
			if (geom && ascent > 0) {
				const midY = (geom.minY + geom.maxY) / 2;
				const factor = (-midY) / ascent;
				if (Number.isFinite(factor) && factor >= 0.15 && factor <= 0.85) {
					factors.push(factor);
				}
			}
		}

		let resolved = 0.42;
		if (factors.length > 0) {
			const sum = factors.reduce((acc, v) => acc + v, 0);
			resolved = Math.max(0.2, Math.min(0.75, sum / factors.length));
		}

		fontData._visualCenterFactor = resolved;
		return resolved;
	}

	/**
	 * Pre-compile glyph SVG templates (`liveSvg` with `decoding="sync"`, `exportSvg` with 300 DPI URLs,
	 * `glyphKey` for in-place DOM reconciliation, stripped duplicate IDs, optical vertical center factor,
	 * per-glyph horizontal bounds, and prioritized `_previewUrls`) once per font.
	 *
	 * @param {Object} fontData Hydrated or server-inlined OpenType-SVG font dataset.
	 * @param {string} fontKey Font identifier.
	 * @return {Object|null}
	 */
	static prepareFontData(fontData, fontKey = '') {
		if (!fontData || fontData._prepared) return fontData;

		const fId = String(fontData.id || fontKey || 'ot').toLowerCase().replace(/[\s\-]+/g, '_');
		const fullMap = fontData.fullMap || null;
		const glyphs = fontData.glyphs || {};
		const fontDefs = fontData.defs || '';
		const unitsPerEm = fontData.unitsPerEm || 1000;
		const urlSet = new Set();

		const extractPreviewUrls = (svgStr) => {
			if (!svgStr) return;
			const re = /(?:xlink:href|href)="([^"]+)"/g;
			let match;
			while ((match = re.exec(svgStr)) !== null) {
				if (match[1] && !match[1].startsWith('#') && !match[1].startsWith('data:')) {
					urlSet.add(match[1]);
				}
			}
		};

		// Prioritize primary uppercase alphabet & digits first so typed letters are ready immediately
		const priorityChars = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789';
		for (let i = 0; i < priorityChars.length; i++) {
			const ch = priorityChars[i];
			const g = glyphs[ch] || glyphs[ch.toLowerCase()];
			if (g && g.svg) {
				extractPreviewUrls(g.svg);
			}
		}

		for (const [ch, g] of Object.entries(glyphs)) {
			if (!g) continue;
			const cleanSvg = this.stripUnreferencedIds(g.svg || '', fontDefs);
			extractPreviewUrls(cleanSvg);

			let liveSvg = cleanSvg;
			if (liveSvg.includes('decoding=')) {
				liveSvg = liveSvg.replace(/decoding="async"/g, 'decoding="sync"');
			} else {
				liveSvg = liveSvg.replace(/<image /g, '<image decoding="sync" ');
			}

			let exportSvg = cleanSvg;
			if (fullMap) {
				exportSvg = exportSvg.replace(/(xlink:href|href)="([^"]+)"/g, (m, attr, url) => {
					const fullUrl = fullMap[url] || url;
					return `${attr}="${fullUrl}"`;
				});
			}

			// Precompute horizontal visual span (detects vector & bitmap overhang past advance width)
			const adv = (g.adv && g.adv > 0) ? g.adv : (fontData.defaultAdv || 500);
			let visualMinX = 0;
			let visualMaxX = adv;
			if (cleanSvg.includes('<image')) {
				const geom = this.parseImageGlyphGeometry(cleanSvg);
				if (geom) {
					visualMinX = Math.max(-unitsPerEm * 0.25, Math.min(0, geom.minX));
					visualMaxX = Math.max(adv, Math.min(adv + unitsPerEm * 0.6, geom.maxX));
				}
			} else if (cleanSvg.includes('<path')) {
				const bounds = this.parseVectorPathBoundsX(cleanSvg);
				if (bounds) {
					visualMinX = Math.max(-unitsPerEm * 0.25, Math.min(adv * 0.4, bounds.minX));
					visualMaxX = Math.max(adv, Math.min(adv + unitsPerEm * 0.65, bounds.maxX));
				}
			}

			g.liveSvg = liveSvg;
			g.exportSvg = exportSvg;
			g.glyphKey = `${fId}:${g.gid !== undefined ? g.gid : ch}`;
			g._visualMinX = visualMinX;
			g._visualMaxX = visualMaxX;
		}

		this.computeFontVisualCenterFactor(fontData);
		fontData._previewUrls = Array.from(urlSet);
		fontData._prepared = true;
		return fontData;
	}

	/**
	 * Stage 1 Priority Pre-warm: Immediately decode ONLY the glyph bitmaps used by a specific text string
	 * (e.g. initial `default_text`) with high priority so critical first paint has zero competition.
	 *
	 * @param {Object} fontData Hydrated OpenType-SVG font dataset.
	 * @param {string} text Text string whose characters should be warmed immediately.
	 */
	/**
	 * Resolve an OpenType-SVG glyph entry for a character, falling back across
	 * case variants and NFD diacritic-stripped base Latin characters (e.g. É -> E).
	 *
	 * @param {Object} glyphs Font glyph map.
	 * @param {string} ch Single character.
	 * @param {number} defaultAdv Fallback advance width.
	 * @return {{ adv: number, svg?: string, liveSvg?: string, exportSvg?: string, glyphKey?: string }}
	 */
	static resolveSvgGlyph(glyphs, ch, defaultAdv = 500) {
		if (!glyphs || !ch) {
			return { adv: defaultAdv, svg: '' };
		}
		const isSpace = (ch === ' ' || ch === '\t' || ch === '\n' || ch === '\r');
		const candidates = [ch, ch.toUpperCase(), ch.toLowerCase()];
		if (typeof ch.normalize === 'function') {
			const base = ch.normalize('NFD').replace(/[\u0300-\u036f]/g, '');
			if (base && base !== ch) {
				candidates.push(base, base.toUpperCase(), base.toLowerCase());
			}
		}
		let firstHit = null;
		for (let i = 0; i < candidates.length; i++) {
			const key = candidates[i];
			const g = key ? glyphs[key] : null;
			if (g) {
				if (!firstHit) firstHit = g;
				if (g.svg || isSpace) {
					return g;
				}
			}
		}
		return firstHit || { adv: defaultAdv, svg: '' };
	}

	static prewarmTextGlyphs(fontData, text = '') {
		if (typeof window === 'undefined' || !fontData || !text) return;
		this.prepareFontData(fontData, fontData.id || '');

		window.mrTeePrewarmedImages = window.mrTeePrewarmedImages || new Map();
		const glyphs = fontData.glyphs || {};
		const re = /(?:xlink:href|href)="([^"]+)"/g;

		for (let i = 0; i < text.length; i++) {
			const ch = text[i];
			if (ch === ' ' || ch === '\n' || ch === '\r' || ch === '\t') continue;
			const g = this.resolveSvgGlyph(glyphs, ch, fontData.defaultAdv || 500);
			if (!g || !g.svg) continue;

			re.lastIndex = 0;
			let match;
			while ((match = re.exec(g.svg)) !== null) {
				const url = match[1];
				if (!url || url.startsWith('#') || url.startsWith('data:') || window.mrTeePrewarmedImages.has(url)) {
					continue;
				}
				const img = new Image();
				img.decoding = 'sync';
				if ('fetchPriority' in img) {
					img.fetchPriority = 'high';
				}
				img.src = url;
				if (typeof img.decode === 'function') {
					img.decode().catch(() => {});
				}
				window.mrTeePrewarmedImages.set(url, img);
			}
		}
	}

	/**
	 * Stage 2 Throttled Alphabet Pre-warm: Fetch and decode all preview glyph bitmaps (`A-Z`, `0-9`, etc.)
	 * into persistent browser RAM (`window.mrTeePrewarmedImages`) in small concurrent batches (6 at a time)
	 * so typing any new letter is 0ms instant without saturating network bandwidth during initial page paint.
	 *
	 * @param {Object} fontData Hydrated OpenType-SVG font dataset.
	 * @param {Object} options Optional settings ({ batchSize: 6, immediate: false }).
	 */
	static prewarmFontGlyphs(fontData, options = {}) {
		if (typeof window === 'undefined' || !fontData) return;
		this.prepareFontData(fontData, fontData.id || '');
		if (fontData._prewarmed) return;
		fontData._prewarmed = true;

		window.mrTeePrewarmedImages = window.mrTeePrewarmedImages || new Map();
		const allUrls = fontData._previewUrls || [];
		const pending = [];

		for (let i = 0; i < allUrls.length; i++) {
			const url = allUrls[i];
			if (url && !window.mrTeePrewarmedImages.has(url)) {
				pending.push(url);
			}
		}

		if (pending.length === 0) return;

		const batchSize = options.batchSize || 6;
		let cursor = 0;

		const loadNext = () => {
			if (cursor >= pending.length) return;
			const url = pending[cursor++];
			if (!url || window.mrTeePrewarmedImages.has(url)) {
				loadNext();
				return;
			}

			const img = new Image();
			img.decoding = options.immediate ? 'sync' : 'async';
			const done = () => {
				if (typeof img.decode === 'function') {
					img.decode().catch(() => {});
				}
				loadNext();
			};
			img.onload = done;
			img.onerror = done;
			img.src = url;
			window.mrTeePrewarmedImages.set(url, img);
		};

		const concurrency = options.immediate ? Math.min(12, pending.length) : Math.min(batchSize, pending.length);
		for (let c = 0; c < concurrency; c++) {
			loadNext();
		}
	}

	/**
	 * Hydrate a lightweight font manifest (~20 KB) into the full client-side glyph lookup table.
	 *
	 * @param {Object} manifest Raw manifest from manifest.json.
	 * @param {string} fontId Font key.
	 * @return {Object}
	 */
	static hydrateManifest(manifest, fontId) {
		if (!manifest) return null;
		if (manifest.hydrated) return this.prepareFontData(manifest, fontId);

		const allowFullPrint = typeof window !== 'undefined' && Boolean(window.mrTeeAdminData || window.mrTeeOrderExportData);
		const rawPluginUrl = (typeof window !== 'undefined' && window.mrTeeProductData && window.mrTeeProductData.pluginUrl)
			|| (typeof window !== 'undefined' && window.mrTeeAdminData && window.mrTeeAdminData.pluginUrl)
			|| (typeof window !== 'undefined' && window.mrTeeOrderExportData && window.mrTeeOrderExportData.pluginUrl)
			|| '/wp-content/plugins/mr_tee_editor/';
		let pluginPath = '/wp-content/plugins/mr_tee_editor/';
		try {
			pluginPath = new URL(rawPluginUrl, (typeof window !== 'undefined' && window.location) ? window.location.origin : 'http://localhost').pathname;
		} catch (e) {
			pluginPath = rawPluginUrl;
		}
		if (!pluginPath.endsWith('/')) pluginPath += '/';
		let base = manifest.base || `${pluginPath}assets/fonts/cache/${fontId}/`;
		try {
			base = new URL(base, (typeof window !== 'undefined' && window.location) ? window.location.origin : 'http://localhost').pathname;
		} catch (e) {}
		if (!base.endsWith('/')) base += '/';
		const ver = manifest.v || '';
		const images = manifest.images || {};
		const previewUrls = {};
		const fullMap = {};

		for (const [key, img] of Object.entries(images)) {
			const p = `${base}${img.p}?v=${ver}`;
			previewUrls[key] = p;
			if (allowFullPrint && img.f) {
				fullMap[p] = `${base}${img.f}?v=${ver}`;
			}
		}

		const resolve = (svg) => {
			if (!svg || !svg.includes('{{p:')) return svg;
			return svg.replace(/\{\{p:([a-z0-9]+)\}\}/g, (m, k) => previewUrls[k] || '');
		};

		const hydratedGlyphs = {};
		for (const [ch, g] of Object.entries(manifest.glyphs || {})) {
			hydratedGlyphs[ch] = {
				gid: g.gid,
				adv: g.adv,
				svg: resolve(g.svg || ''),
			};
		}

		const hydrated = {
			...manifest,
			glyphs: hydratedGlyphs,
			defs: resolve(manifest.defs || ''),
			fullMap: allowFullPrint ? fullMap : {},
			preview: allowFullPrint && Object.keys(fullMap).length > 0,
			hydrated: true,
		};

		return this.prepareFontData(hydrated, fontId);
	}

	/**
	 * Asynchronously ensure an OpenType-SVG font is loaded into client memory.
	 *
	 * @param {string} fontId Font key.
	 * @param {Object} fontDef Font definition.
	 * @param {Object} options Optional behavior flags ({ prewarmGlyphs: true, priorityText: '' }).
	 * @return {Promise<Object|null>}
	 */
	static async ensureSvgFontLoaded(fontId, fontDef = null, options = {}) {
		if (typeof window === 'undefined') return null;
		window.mrTeeSvgFonts = window.mrTeeSvgFonts || {};

		const shouldPrewarm = options.prewarmGlyphs !== false;
		const priorityText = options.priorityText || '';

		const applyWarmup = (fontData) => {
			if (!fontData) return fontData;
			if (priorityText) {
				this.prewarmTextGlyphs(fontData, priorityText);
			}
			if (shouldPrewarm) {
				this.prewarmFontGlyphs(fontData);
			}
			return fontData;
		};

		const cleanKey = String(fontId).toLowerCase().replace(/[\s\-]+/g, '_');
		if (window.mrTeeSvgFonts[cleanKey]) {
			const cached = this.prepareFontData(window.mrTeeSvgFonts[cleanKey], cleanKey);
			return applyWarmup(cached);
		}

		const existing = this.getSvgFontData(fontId);
		if (existing) {
			window.mrTeeSvgFonts[cleanKey] = existing;
			return applyWarmup(existing);
		}

		const allowFullPrint = Boolean(window.mrTeeAdminData || window.mrTeeOrderExportData);
		const fonts = (window.mrTeeProductData && window.mrTeeProductData.fonts)
			|| (window.mrTeeAdminData && window.mrTeeAdminData.fonts)
			|| (window.mrTeeOrderExportData && window.mrTeeOrderExportData.fonts)
			|| {};
		const def = fontDef || fonts[fontId] || fonts[cleanKey];
		if (!def) return null;

		const pluginUrl = (window.mrTeeProductData && window.mrTeeProductData.pluginUrl)
			|| (window.mrTeeAdminData && window.mrTeeAdminData.pluginUrl)
			|| (window.mrTeeOrderExportData && window.mrTeeOrderExportData.pluginUrl)
			|| '';

		const toSameOriginUrl = (raw) => {
			if (!raw) return '';
			try {
				const parsed = new URL(raw, window.location.origin);
				if (parsed.hostname === window.location.hostname) {
					return parsed.pathname + parsed.search;
				}
			} catch (e) {}
			return raw;
		};

		const sanitizeFontPayload = (rawObj) => {
			if (!allowFullPrint && rawObj && typeof rawObj === 'object') {
				rawObj.fullMap = {};
				rawObj.preview = false;
			}
			return rawObj;
		};

		// 1. Fast mobile-first: try lightweight manifest (~20 KB vs 25 MB!)
		const manifestUrl = toSameOriginUrl(def.manifest_url || `${pluginUrl}assets/fonts/cache/${cleanKey}/manifest.json`);
		if (manifestUrl) {
			try {
				const resp = await fetch(manifestUrl);
				if (resp.ok) {
					const rawManifest = await resp.json();
					const data = this.hydrateManifest(rawManifest, cleanKey);
					window.mrTeeSvgFonts[cleanKey] = data;
					window.mrTeeSvgFonts[fontId] = data;
					return applyWarmup(data);
				}
			} catch (mErr) {
				// Continue to fallback
			}
		}

		// 2. Legacy static JSON cache fallback
		const dataUrl = toSameOriginUrl(def.data_url || `${pluginUrl}assets/fonts/cache/${cleanKey}.json`);
		try {
			const resp = await fetch(dataUrl);
			if (resp.ok) {
				const rawData = sanitizeFontPayload(await resp.json());
				const data = this.prepareFontData(rawData, cleanKey);
				window.mrTeeSvgFonts[cleanKey] = data;
				window.mrTeeSvgFonts[fontId] = data;
				return applyWarmup(data);
			}
		} catch (err) {
			console.warn('[MrTee SvgRenderer] Failed to load static SVG font cache, trying AJAX fallback:', err);
		}

		// 3. AJAX Fallback
		const ajaxUrl = toSameOriginUrl(
			(window.mrTeeProductData && window.mrTeeProductData.ajaxUrl)
			|| (window.mrTeeAdminData && window.mrTeeAdminData.ajaxUrl)
			|| (window.mrTeeOrderExportData && window.mrTeeOrderExportData.ajaxUrl)
		);
		if (ajaxUrl) {
			try {
				const resp = await fetch(`${ajaxUrl}?action=mr_tee_get_svg_font_data&font_id=${encodeURIComponent(cleanKey)}`);
				if (resp.ok) {
					const json = await resp.json();
					if (json.success && json.data) {
						const data = this.prepareFontData(sanitizeFontPayload(json.data), cleanKey);
						window.mrTeeSvgFonts[cleanKey] = data;
						window.mrTeeSvgFonts[fontId] = data;
						return applyWarmup(data);
					}
				}
			} catch (ajaxErr) {
				console.error('[MrTee SvgRenderer] AJAX SVG font retrieval failed:', ajaxErr);
			}
		}

		return null;
	}

	/**
	 * Resolve effective advance width and SVG status for a character in an OpenType-SVG font,
	 * normalizing oversized demo watermark advances for digits/symbols that lack SVG bitmaps.
	 *
	 * @param {Object} fontData OpenType-SVG font dataset.
	 * @param {string} ch Single character.
	 * @param {number} unitsPerEm Font design units per em.
	 * @return {{ g: Object, hasSvg: boolean, isSpace: boolean, adv: number }}
	 */
	static getEffectiveOpenTypeGlyph(fontData, ch, unitsPerEm = 1000) {
		const g = this.resolveSvgGlyph(fontData.glyphs, ch, fontData.defaultAdv || 500);
		const isSpace = /^\s$/.test(ch);
		const hasSvg = Boolean(g && g.svg && g.svg.trim() !== '');
		if (hasSvg || isSpace) {
			return {
				g,
				hasSvg,
				isSpace,
				adv: (g && g.adv > 0) ? g.adv : (isSpace ? Math.round(unitsPerEm * 0.32) : (fontData.defaultAdv || 500)),
			};
		}
		let ratio = 0.58;
		if (/^[!.,:;'’‘|`1Iil]$/.test(ch)) {
			ratio = 0.42;
		} else if (/^[&?%@MWmw#]$/.test(ch)) {
			ratio = 0.66;
		}
		return {
			g,
			hasSvg: false,
			isSpace: false,
			adv: Math.round(unitsPerEm * ratio),
		};
	}

	/**
	 * Render an OpenType-SVG text node into native color vector SVG elements.
	 *
	 * Preserves full vector artwork, multi-color gradient fills, transparent drop shadows,
	 * horizontal advance metrics, auto-fit boundaries, and rotation, while providing a
	 * 3D-styled vector fallback for digits and symbols omitted from demo SVG tables.
	 *
	 * @param {Object} node TEXT node definition.
	 * @param {Object} fontData OpenType-SVG font metrics and glyph dataset.
	 * @param {Object} layout Precalculated box layout properties.
	 * @param {Object} options Rendering options.
	 * @return {string} SVG group markup.
	 */
	static renderOpenTypeSvgTextNode(node, fontData, layout, options = {}) {
		const idPrefix = options.idPrefix ? String(options.idPrefix).replace(/[^a-zA-Z0-9_-]/g, '') : '';
		const { boxL, boxT, boxW, boxH, x, y, hasBox, hasPerLine, ratio, val } = layout;
		const fId = String(node.fontId || fontData.id || 'ot').toLowerCase().replace(/[\s\-]+/g, '_');

		this.prepareFontData(fontData, fId);

		const casing = node.casing || (node.uppercase === true ? 'UPPER' : 'ORIGINAL');
		const displayText = (casing === 'UPPER') ? val.toUpperCase() : val;
		const lines = displayText.split('\n');
		const numLines = Math.max(1, lines.length);
		const lineHeight = parseFloat(node.lineHeight) || 0.95;

		const unitsPerEm = fontData.unitsPerEm || 1000;
		const ascent = fontData.ascent || 800;
		const visualCenterFactor = this.computeFontVisualCenterFactor(fontData);
		const letterSpacingEm = node.letterSpacing !== undefined ? parseFloat(node.letterSpacing) : (fontData.letter_spacing || 0.05);
		const letterSpacingUnits = letterSpacingEm * unitsPerEm;

		// Base font size derived directly from box height
		let baseFontSize = parseFloat(node.fontSize);
		if (isNaN(baseFontSize) || baseFontSize <= 0 || hasBox) {
			const effectiveBoxH = (hasPerLine && ratio !== 1.0) ? (boxH / ratio) : boxH;
			const heightTarget = (effectiveBoxH * 0.85) / (1 + (numLines - 1) * lineHeight * 0.8);
			baseFontSize = (!isNaN(parseFloat(node.fontSize)) && parseFloat(node.fontSize) > 0 && !hasBox)
				? parseFloat(node.fontSize)
				: heightTarget;
		}

		const maxWidth = hasBox ? (boxW * 0.95) : (parseFloat(node.maxWidth) || 84.0);
		const maxHeight = hasBox ? ((hasPerLine && ratio !== 1.0) ? ((boxH * 0.92) / ratio) : (boxH * 0.92)) : (parseFloat(node.maxHeight) || 80.0);

		let maxLineDesignWidth = 0;
		const lineMetrics = lines.map(lineText => {
			let penX = 0;
			let minVisualX = Infinity;
			let maxVisualX = -Infinity;

			for (let i = 0; i < lineText.length; i++) {
				const ch = lineText[i];
				const eff = this.getEffectiveOpenTypeGlyph(fontData, ch, unitsPerEm);
				const g = eff.g;
				if (!eff.isSpace) {
					const gMinX = (eff.hasSvg && g && typeof g._visualMinX === 'number') ? g._visualMinX : 0;
					const gMaxX = (eff.hasSvg && g && typeof g._visualMaxX === 'number') ? g._visualMaxX : eff.adv;
					const leftEdge = penX + gMinX;
					const rightEdge = penX + gMaxX;
					if (leftEdge < minVisualX) minVisualX = leftEdge;
					if (rightEdge > maxVisualX) maxVisualX = rightEdge;
				}
				penX += eff.adv + (i < lineText.length - 1 ? letterSpacingUnits : 0);
			}

			if (minVisualX === Infinity || maxVisualX === -Infinity) {
				minVisualX = 0;
				maxVisualX = penX;
			}
			const visualWidthUnits = Math.max(penX, maxVisualX - minVisualX);
			if (visualWidthUnits > maxLineDesignWidth) maxLineDesignWidth = visualWidthUnits;
			return { text: lineText, widthUnits: penX, minVisualX, maxVisualX, visualWidthUnits };
		});

		const estimatedRawWidth = (maxLineDesignWidth / unitsPerEm) * baseFontSize;
		let scaleW = 1.0;
		if (estimatedRawWidth > maxWidth && maxWidth > 0) {
			scaleW = maxWidth / estimatedRawWidth;
		}

		let scaleH = 1.0;
		const rawTotalHeight = baseFontSize + ((numLines - 1) * baseFontSize * lineHeight);
		if (rawTotalHeight > maxHeight && maxHeight > 0) {
			scaleH = maxHeight / rawTotalHeight;
		}

		const autoFitScale = Math.min(1.0, scaleW, scaleH);
		const effectiveFontSize = Math.max(1.5, baseFontSize * autoFitScale);
		const scale = effectiveFontSize / unitsPerEm;

		const totalHeight = effectiveFontSize + (numLines - 1) * effectiveFontSize * lineHeight;
		const firstLineCenterY = y - (totalHeight / 2) + (effectiveFontSize / 2);

		const rawFamily = String(node.fontFamily || fontData.name || 'Bungee').replace(/['"]/g, '');
		const fbFontStack = `'${this.escapeXml(rawFamily)}', 'Bungee', 'Impact', sans-serif`;
		const fbFontSize = Math.round(unitsPerEm * 0.78);
		const fbStrokeWidth = Math.round(unitsPerEm * 0.11);

		let glyphsMarkup = '';
		lineMetrics.forEach((lm, lineIdx) => {
			const visualSpanW = (lm.maxVisualX - lm.minVisualX) * scale;
			const lineCenterY = firstLineCenterY + (lineIdx * effectiveFontSize * lineHeight);
			// True optical baseline position derived from glyph image geometry (0.42 fallback for vector fonts)
			const baselineY = lineCenterY + (ascent * visualCenterFactor * scale);

			const anchor = node.textAnchor || 'middle';
			let cursorX = x - (visualSpanW / 2) - (lm.minVisualX * scale);
			if (anchor === 'start' || anchor === 'left') {
				cursorX = boxL + (boxW * 0.025) - (lm.minVisualX * scale);
			} else if (anchor === 'end' || anchor === 'right') {
				cursorX = (boxL + boxW * 0.975) - (lm.maxVisualX * scale);
			}

			for (let i = 0; i < lm.text.length; i++) {
				const ch = lm.text[i];
				const eff = this.getEffectiveOpenTypeGlyph(fontData, ch, unitsPerEm);
				const g = eff.g;
				if (eff.hasSvg) {
					const rawGlyphSvg = options.isExport ? (g.exportSvg || g.svg) : (g.liveSvg || g.svg);
					const glyphSvg = idPrefix ? this.scopeMarkupIds(rawGlyphSvg, idPrefix) : rawGlyphSvg;
					const glyphKeyAttr = g.glyphKey ? ` data-glyph-key="${g.glyphKey}"` : '';
					glyphsMarkup += `<g class="mrtee-ot-glyph"${glyphKeyAttr} transform="translate(${cursorX.toFixed(3)}, ${baselineY.toFixed(3)}) scale(${scale.toFixed(5)})">${glyphSvg}</g>\n`;
				} else if (!eff.isSpace) {
					const escapedCh = this.escapeXml(ch);
					const fbCenterX = (eff.adv / 2).toFixed(1);
					glyphsMarkup += `<g class="mrtee-ot-glyph mrtee-ot-glyph-fallback" data-glyph-key="${this.escapeXml(fId)}:fb:${escapedCh}" transform="translate(${cursorX.toFixed(3)}, ${baselineY.toFixed(3)}) scale(${scale.toFixed(5)})">` +
						`<text x="${fbCenterX}" y="0" text-anchor="middle" font-family="${fbFontStack}" font-weight="900" font-size="${fbFontSize}" fill="#FFFFFF" stroke="#1E293B" stroke-width="${fbStrokeWidth}" stroke-linejoin="round" stroke-linecap="round" style="paint-order: stroke fill;" filter="url(#${idPrefix}mrtee-3d-shadow)">${escapedCh}</text>` +
						`</g>\n`;
				}
				cursorX += (eff.adv + letterSpacingUnits) * scale;
			}
		});

		const rotateAngle = (layout.rotateAngle !== undefined)
			? (parseFloat(layout.rotateAngle) || 0)
			: (parseFloat(node.box_rotate !== undefined ? node.box_rotate : (node.rotate !== undefined ? node.rotate : 0)) || 0);
		const transformAttr = (rotateAngle !== 0) ? ` transform="rotate(${rotateAngle.toFixed(2)}, ${x.toFixed(2)}, ${y.toFixed(2)})"` : '';
		const rawNodeId = node.id || node.name || node.reference;
		const nodeIdAttr = rawNodeId ? ` data-node-id="${String(rawNodeId).replace(/"/g, '&quot;')}"` : '';

		return `<g class="mrtee-opentype-svg-text"${nodeIdAttr}${transformAttr}>\n${glyphsMarkup}</g>`;
	}
}
