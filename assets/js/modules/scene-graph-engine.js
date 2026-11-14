/**
 * Mr. Tee Editor - Scene-Graph Layout Engine Module
 *
 * Implements Monsieur TSHIRT's exact layout algorithms:
 * - `getLayersMergedWithRefs`: Declarative scene-graph merging with reference registry
 * - 2-Row Mushroom Layout: `Ligne 1` (items 1..3) and `Ligne 2` (items 4..6)
 * - Dynamic Row Centering: horizontally balances 1, 2, or 3 items per row
 * - `getAllLayersBBox`: Computes accurate bounding box of all visible layers
 * - `getAreaTransformValuesZoom`: Auto-scales & vertically centers the composite visual
 *
 * @package MrTeeEditor\Assets\Modules
 */

export class SceneGraphEngine {
	/**
	 * Deeply merge references into layers tree (matching Monsieur TSHIRT's `getLayersMergedWithRefs`).
	 *
	 * @param {Array} layers Scene-graph layers array.
	 * @param {Array} references Flat or nested references registry.
	 * @return {Array} Merged layers tree ready for rendering.
	 */
	static getLayersMergedWithRefs(layers, references) {
		const refMap = this.createRefsMap(references);
		const clone = this.fastCloneLayers(layers || []);

		const mergeNode = (node) => {
			if (!node || typeof node !== 'object') return node;

			// If node binds to a reference
			if (node.reference && refMap.has(node.reference)) {
				const ref = refMap.get(node.reference);
				if (ref.type === 'TEXT') {
					node.value = (ref.value !== undefined) ? ref.value : node.value;
					if (ref.fontFamily) node.fontFamily = ref.fontFamily;
					if (ref.fontId) node.fontId = ref.fontId;
					if (ref.fontType) node.fontType = ref.fontType;
					if (ref.letterSpacing !== undefined) node.letterSpacing = ref.letterSpacing;
					if (ref.casing !== undefined) node.casing = ref.casing;
					if (ref.multiColor !== undefined) node.multiColor = ref.multiColor;
					if (ref.strokeWidth !== undefined) {
						node.stroke = node.stroke || {};
						node.stroke.width = ref.strokeWidth;
						node.strokeWidth = ref.strokeWidth;
					}
					if (ref.strokeColor !== undefined) {
						node.stroke = node.stroke || {};
						node.stroke.color = ref.strokeColor;
					}
					if (ref.colorRefId && refMap.has(ref.colorRefId)) {
						node.colorReference = ref.colorRefId;
					}
				} else if (ref.type === 'IMAGE') {
					node.url = (ref.url !== undefined) ? ref.url : node.url;
					node.iconKey = ref.iconKey || node.iconKey;
					if (ref.imageFit) {
						node.imageFit = ref.imageFit;
					}
				}
			}

			// Color reference merge
			const colorRefKey = node.colorReference || node.colorRefId;
			if (colorRefKey && refMap.has(colorRefKey)) {
				const cRef = refMap.get(colorRefKey);
				node.colorSet = cRef;
				if (cRef.colorHex) {
					node.fill = cRef.colorHex;
				}
				if (cRef.strokeHex !== undefined) {
					node.stroke = node.stroke || {};
					node.stroke.color = cRef.strokeHex;
				}
				if (cRef.strokeWidth !== undefined) {
					node.stroke = node.stroke || {};
					node.stroke.width = cRef.strokeWidth;
					node.strokeWidth = cRef.strokeWidth;
				}
			}

			// Visibility reference merge
			if (node.visibility && node.visibility.reference && refMap.has(node.visibility.reference)) {
				const vRef = refMap.get(node.visibility.reference);
				node.hide = (vRef.visible === false);
			}

			// Recurse children
			if (Array.isArray(node.items)) {
				node.items = node.items.map(mergeNode);
			}

			return node;
		};

		return clone.map(mergeNode);
	}

	/**
	 * Ultra-fast structural cloner for scene-graph layers.
	 * Eliminates JSON.parse(JSON.stringify) memory churn and garbage collection spikes.
	 *
	 * @param {Array} layers List of scene-graph nodes.
	 * @return {Array} Cloned nodes array.
	 */
	static fastCloneLayers(layers) {
		if (!Array.isArray(layers)) return [];
		const len = layers.length;
		const out = new Array(len);
		for (let i = 0; i < len; i++) {
			const node = layers[i];
			if (!node || typeof node !== 'object') {
				out[i] = node;
				continue;
			}
			const copy = Object.assign({}, node);
			if (Array.isArray(node.items)) {
				copy.items = this.fastCloneLayers(node.items);
			}
			if (node.visibility && typeof node.visibility === 'object') {
				copy.visibility = Object.assign({}, node.visibility);
			}
			if (node.stroke && typeof node.stroke === 'object') {
				copy.stroke = Object.assign({}, node.stroke);
			}
			out[i] = copy;
		}
		return out;
	}

	/**
	 * Build fast ID lookup map for references.
	 *
	 * @param {Array} references References list.
	 * @return {Map<string, Object>}
	 */
	static createRefsMap(references) {
		const map = new Map();
		const recurse = (list) => {
			if (!Array.isArray(list)) return;
			for (const r of list) {
				if (r && r.id) map.set(r.id, r);
				if (r && Array.isArray(r.items)) recurse(r.items);
			}
		};
		recurse(references);
		return map;
	}

	/**
	 * Apply 2-Row Dynamic Mushroom Centering.
	 *
	 * Rows:
	 * - Ligne 1: holds up to 3 mushrooms (1..3).
	 * - Ligne 2: holds up to 3 mushrooms (4..6).
	 *
	 * When a row has:
	 * - 1 active item: centered at X = 50.0%
	 * - 2 active items: centered at X = 40.0% and X = 60.0%
	 * - 3 active items: centered at X = 30.0%, X = 50.0%, X = 70.0%
	 *
	 * @param {Array} layers Merged scene-graph layers.
	 * @param {number} totalActiveCount Total number of visible mushrooms (0..6).
	 * @return {Array} Adjusted layers tree.
	 */
	static applyRowCentering(layers, totalActiveCount) {
		const count = parseInt(totalActiveCount, 10) || 0;
		const r1Count = Math.min(count, 3);
		const r2Count = Math.max(0, Math.min(count - 3, 3));

		const getRowXPositions = (numItems) => {
			switch (numItems) {
				case 1:
					return [50.0];
				case 2:
					return [40.0, 60.0];
				case 3:
					return [30.0, 50.0, 70.0];
				default:
					return [];
			}
		};

		const r1Positions = getRowXPositions(r1Count);
		const r2Positions = getRowXPositions(r2Count);

		for (const layer of layers) {
			if (layer.type === 'GROUP' && (layer.name === 'Ligne 1' || layer.rowNumber === 1)) {
				let activeIndex = 0;
				for (const champ of (layer.items || [])) {
					if (!champ.hide && activeIndex < r1Positions.length) {
						const targetX = r1Positions[activeIndex];
						this.repositionMushroom(champ, targetX);
						activeIndex++;
					}
				}
			} else if (layer.type === 'GROUP' && (layer.name === 'Ligne 2' || layer.rowNumber === 2)) {
				let activeIndex = 0;
				for (const champ of (layer.items || [])) {
					if (!champ.hide && activeIndex < r2Positions.length) {
						const targetX = r2Positions[activeIndex];
						this.repositionMushroom(champ, targetX);
						activeIndex++;
					}
				}
			}
		}

		return layers;
	}

	/**
	 * Reposition a single mushroom group around a target center X coordinate.
	 *
	 * @param {Object} champGroup Group node containing text and image children.
	 * @param {number} centerX Target center X coordinate.
	 */
	static repositionMushroom(champGroup, centerX) {
		if (!champGroup || !Array.isArray(champGroup.items)) return;

		for (const item of champGroup.items) {
			if (item.type === 'TEXT') {
				item.x = centerX;
			} else if (item.type === 'IMAGE') {
				item.centerX = centerX;
				const w = parseFloat(item.width) || 21.0;
				item.x = centerX - (w / 2.0);
			}
		}
	}

	/**
	 * Measure accurate bounding box of all visible layers.
	 * Implements Monsieur TSHIRT's `getAllLayersBBox`.
	 *
	 * @param {Array} layers Merged & row-centered layers tree.
	 * @return {Object} { xMin, xMax, yMin, yMax, xCenter, yCenter, width, height }
	 */
	static getAllLayersBBox(layers) {
		let xMin = 100, xMax = 0, yMin = 100, yMax = 0;
		let hasVisible = false;

		const getFontMetrics = (rawFont) => {
			const f = String(rawFont || '').toLowerCase();
			if (f.includes('bebas')) return { charRatio: 0.42, letterSpacingEm: 0.10 };
			if (f.includes('press start') || f.includes('pressstart') || f.includes('arcade')) return { charRatio: 0.72, letterSpacingEm: 0.05 };
			if (f.includes('super mario') || f.includes('mario')) return { charRatio: 0.62, letterSpacingEm: 0.15 };
			if (f.includes('luckiest')) return { charRatio: 0.64, letterSpacingEm: 0.06 };
			if (f.includes('bungee')) return { charRatio: 0.65, letterSpacingEm: 0.08 };
			if (f.includes('cocogoose')) return { charRatio: 0.60, letterSpacingEm: 0.12 };
			if (f.includes('pacifico')) return { charRatio: 0.48, letterSpacingEm: 0.02 };
			if (f.includes('cinzel')) return { charRatio: 0.62, letterSpacingEm: 0.10 };
			if (f.includes('marker')) return { charRatio: 0.58, letterSpacingEm: 0.05 };
			if (f.includes('abril')) return { charRatio: 0.55, letterSpacingEm: 0.04 };
			return { charRatio: 0.56, letterSpacingEm: 0.08 };
		};

		const measureNode = (node) => {
			if (!node || node.hide) return;

			if (node.type === 'TEXT') {
				const hasBox = (node.box_width !== undefined && node.box_height !== undefined);
				const boxW = hasBox ? parseFloat(node.box_width) : (parseFloat(node.maxWidth) || 84.0);
				const boxH = hasBox ? parseFloat(node.box_height) : (parseFloat(node.fontSize) ? parseFloat(node.fontSize) * 1.5 : 12.0);
				const boxL = (node.box_left !== undefined) ? parseFloat(node.box_left) : ((parseFloat(node.x) || 50) - (boxW / 2));
				const boxT = (node.box_top !== undefined) ? parseFloat(node.box_top) : ((parseFloat(node.y) || 50) - (boxH / 2));

				const centerX = boxL + (boxW / 2);
				const centerY = boxT + (boxH / 2);

				const textVal = String(node.value || node.text || '');
				const lines = textVal.split('\n');
				const numLines = Math.max(1, lines.length);
				const lineHeight = parseFloat(node.lineHeight) || 0.95;

				let baseFontSize = parseFloat(node.fontSize);
				if (isNaN(baseFontSize) || baseFontSize <= 0 || hasBox) {
					const heightTarget = (boxH * 0.85) / (1 + (numLines - 1) * lineHeight * 0.8);
					baseFontSize = (!isNaN(parseFloat(node.fontSize)) && parseFloat(node.fontSize) > 0 && !hasBox)
						? parseFloat(node.fontSize)
						: heightTarget;
				}

				const maxWidth = hasBox ? (boxW * 0.95) : (parseFloat(node.maxWidth) || 84.0);
				const maxHeight = hasBox ? (boxH * 0.92) : (parseFloat(node.maxHeight) || 80.0);

				let maxLineLen = 0;
				for (const l of lines) {
					if (l.length > maxLineLen) maxLineLen = l.length;
				}

				const metrics = getFontMetrics(node.fontFamily);
				const letterSpacingEm = node.letterSpacing !== undefined ? parseFloat(node.letterSpacing) : metrics.letterSpacingEm;
				const estimatedRawWidth = baseFontSize * (maxLineLen * metrics.charRatio + Math.max(0, maxLineLen - 1) * letterSpacingEm);

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
				const effectiveFontSize = Math.max(2.0, baseFontSize * autoFitScale);
				const effectiveWidth = Math.min(maxWidth, estimatedRawWidth * autoFitScale);
				const totalHeight = effectiveFontSize + ((numLines - 1) * effectiveFontSize * lineHeight);

				const left = centerX - (effectiveWidth / 2);
				const right = centerX + (effectiveWidth / 2);
				const top = centerY - (totalHeight / 2);
				const bottom = centerY + (totalHeight / 2);

				xMin = Math.min(xMin, left);
				xMax = Math.max(xMax, right);
				yMin = Math.min(yMin, top);
				yMax = Math.max(yMax, bottom);
				hasVisible = true;
			} else if (node.type === 'IMAGE') {
				const hasBox = (node.box_width !== undefined && node.box_height !== undefined);
				const x = (node.box_left !== undefined) ? parseFloat(node.box_left) : (parseFloat(node.x) || 30);
				const y = (node.box_top !== undefined) ? parseFloat(node.box_top) : (parseFloat(node.y) || 50);
				const w = hasBox ? parseFloat(node.box_width) : (parseFloat(node.width) || 21);
				const h = hasBox ? parseFloat(node.box_height) : (parseFloat(node.height) || 19);

				xMin = Math.min(xMin, x);
				xMax = Math.max(xMax, x + w);
				yMin = Math.min(yMin, y);
				yMax = Math.max(yMax, y + h);
				hasVisible = true;
			} else if (node.type === 'PATH') {
				const y = parseFloat(node.y) || 52;
				const w = 8;
				const h = 8;
				xMin = Math.min(xMin, 50 - (w / 2));
				xMax = Math.max(xMax, 50 + (w / 2));
				yMin = Math.min(yMin, y - (h / 2));
				yMax = Math.max(yMax, y + (h / 2));
				hasVisible = true;
			}

			if (Array.isArray(node.items)) {
				for (const child of node.items) {
					measureNode(child);
				}
			}
		};

		for (const root of layers) {
			measureNode(root);
		}

		if (!hasVisible) {
			return { xMin: 10, xMax: 90, yMin: 10, yMax: 90, xCenter: 50, yCenter: 50, width: 80, height: 80 };
		}

		const width = Math.max(1, xMax - xMin);
		const height = Math.max(1, yMax - yMin);
		const xCenter = (xMin + xMax) / 2.0;
		const yCenter = (yMin + yMax) / 2.0;

		return {
			xMin,
			xMax,
			yMin,
			yMax,
			xCenter,
			yCenter,
			width,
			height,
		};
	}

	/**
	 * Compute auto-zoom and vertical auto-centering transform.
	 * Implements Monsieur TSHIRT's `getAreaTransformValuesZoom`.
	 *
	 * When the user chooses 0, 1, 2, 3, 4, 5, or 6 mushrooms, the design adapts
	 * so it never looks empty, cut off, or cramped!
	 *
	 * @param {Object} bbox Bounding box from getAllLayersBBox.
	 * @param {Object} defaultTransform Base transform from area config.
	 * @return {Object} { scale, translateX, translateY }
	 */
	static getAreaTransformValuesZoom(bbox, defaultTransform = {}) {
		const maxDim = Math.max(bbox.width, bbox.height);
		let zoomRatio = 1.0;

		if (maxDim > 0) {
			zoomRatio = 88.0 / maxDim; // Keep 6% padding inside 0..100 viewBox
		}

		// Clamp zoom ratio to reasonable bounds (0.75x .. 1.35x)
		zoomRatio = Math.max(0.75, Math.min(1.35, zoomRatio));

		// Center the bbox around (50, 50)
		let tx = (50.0 - bbox.xCenter) * zoomRatio;
		let ty = (50.0 - bbox.yCenter) * zoomRatio;

		// Add admin fine-tuning offsets if configured
		if (defaultTransform && typeof defaultTransform === 'object') {
			if (defaultTransform.x !== undefined && !isNaN(defaultTransform.x)) {
				tx += parseFloat(defaultTransform.x);
			}
			if (defaultTransform.y !== undefined && !isNaN(defaultTransform.y)) {
				ty += parseFloat(defaultTransform.y);
			}
			// Apply base scale multiplier if non-standard (e.g. customized in admin)
			const adminScale = parseFloat(defaultTransform.scale);
			if (!isNaN(adminScale) && adminScale > 0 && adminScale !== 0.34 && adminScale !== 1.0) {
				zoomRatio *= adminScale;
			}
		}

		return {
			scale: zoomRatio,
			translateX: tx,
			translateY: ty,
		};
	}
}
