/**
 * Mr. Tee Editor - Reactive State Store Module
 *
 * Implements a reactive Pub/Sub state store managing:
 * - `references`: typed personalization bindings (TEXT, COLOR_SET, GROUP_REFS, IMAGE, VISIBILITY)
 * - `persoVisual`: declarative layers tree and layout metadata
 * - `activeGarmentColor`: current garment swatch
 * - `dirtyTracking`: unedited warning validation (matching Monsieur TSHIRT's IGNORE_PERSO_WARNINGS_LABEL)
 *
 * @package MrTeeEditor\Assets\Modules
 */

export class StateStore {
	constructor(initialConfig, productId = 0) {
		this.config = initialConfig || {};
		this.listeners = new Set();

		const perso = this.config.perso_visual || {};
		const rep = this.config.repeater || {};
		const minCount = rep.min_items !== undefined ? Math.max(0, parseInt(rep.min_items, 10) || 0) : 0;
		const parsedDef = (rep.default_count !== undefined && rep.default_count !== '')
			? parseInt(rep.default_count, 10)
			: 2;
		const initialCount = Number.isNaN(parsedDef) ? Math.max(minCount, 2) : Math.max(minCount, parsedDef);

		this.state = {
			productId: parseInt(productId || this.config.productId || 0, 10) || 0,
			layers: Array.isArray(perso.layers) ? JSON.parse(JSON.stringify(perso.layers)) : [],
			references: Array.isArray(perso.references) ? JSON.parse(JSON.stringify(perso.references)) : [],
			area: perso.area || { width: 2400, height: 3000, higherAnchor: 'top', widerAnchor: 'middle' },
			garmentColor: this.config.garment_colors && this.config.garment_colors[0] ? this.config.garment_colors[0] : null,
			activeCount: initialCount,
			isDirty: false,
			hasUneditedWarnings: false,
			warnings: [],
		};

		this.lastChangedSideId = null;
		this.initReferencesIndex();
		this.updateVisibilityFromCount(this.state.activeCount, false);
	}

	/**
	 * Build fast ID lookup index for references and resolve each reference to its print side.
	 */
	initReferencesIndex() {
		this.refMap = new Map();
		this.refSideMap = new Map();
		const indexRefs = (list) => {
			if (!Array.isArray(list)) return;
			for (const ref of list) {
				if (ref && ref.id) {
					if (ref.type === 'IMAGE') {
						if (ref._defaultUrl === undefined) {
							ref._defaultUrl = ref.url || '';
						}
						if (ref._defaultPreviewUrl === undefined && ref.previewUrl) {
							ref._defaultPreviewUrl = ref.previewUrl;
						}
					}
					this.refMap.set(ref.id, ref);
				}
				if (ref && Array.isArray(ref.items)) {
					indexRefs(ref.items);
				}
			}
		};
		indexRefs(this.state.references);

		const registerRefSide = (id, sideId) => {
			if (!id || !sideId) return;
			const key = String(id);
			const sid = String(sideId);
			if (!this.refSideMap.has(key)) {
				this.refSideMap.set(key, sid);
			} else if (this.refSideMap.get(key) !== sid) {
				// Shared across multiple sides -> invalidate all sides
				this.refSideMap.set(key, null);
			}
		};

		if (Array.isArray(this.config.text_lines)) {
			for (const line of this.config.text_lines) {
				if (!line) continue;
				const sid = line.side_id || line.sideId || 'front';
				if (line.ref_id) {
					registerRefSide(line.ref_id, sid);
					const ref = this.refMap.get(line.ref_id);
					if (ref && ref.colorRefId) {
						registerRefSide(ref.colorRefId, sid);
					}
				}
				if (line.color_ref_id) {
					registerRefSide(line.color_ref_id, sid);
				}
			}
		}

		const indexLayerSides = (nodes, inheritedSide = null) => {
			if (!Array.isArray(nodes)) return;
			for (const node of nodes) {
				if (!node || typeof node !== 'object') continue;
				const nodeSide = node.side_id || node.sideId || inheritedSide || 'front';
				if (node.reference) {
					registerRefSide(node.reference, nodeSide);
				}
				const cKey = node.colorReference || node.colorRefId;
				if (cKey) {
					registerRefSide(cKey, nodeSide);
				}
				if (Array.isArray(node.items)) {
					indexLayerSides(node.items, node.side_id || node.sideId || inheritedSide);
				}
			}
		};
		indexLayerSides(this.state.layers, null);
	}

	/**
	 * Resolve print side ID affected by a given reference ID (`null` means all sides).
	 *
	 * @param {string} refId Reference ID.
	 * @return {string|null}
	 */
	resolveSideIdForRef(refId) {
		if (!refId || !this.refSideMap) return null;
		const key = String(refId);
		if (this.refSideMap.has(key)) {
			return this.refSideMap.get(key);
		}
		return null;
	}

	/**
	 * Subscribe to state changes.
	 *
	 * @param {Function} listener Callback receiving (state, changeType, sideId).
	 * @return {Function} Unsubscribe function.
	 */
	subscribe(listener) {
		this.listeners.add(listener);
		return () => this.listeners.delete(listener);
	}

	/**
	 * Notify all subscribers of state mutation.
	 *
	 * @param {string}      changeType Identifier for the change event.
	 * @param {string|null} sideId     Affected print side ID (`null` means all sides).
	 */
	notify(changeType, sideId = null) {
		this.lastChangedSideId = sideId || null;
		for (const listener of this.listeners) {
			try {
				listener(this.state, changeType, this.lastChangedSideId);
			} catch (err) {
				console.error('[MrTee StateStore] Listener error:', err);
			}
		}
	}

	/**
	 * Get reference by ID.
	 *
	 * @param {string} id Reference ID.
	 * @return {Object|null}
	 */
	getReference(id) {
		return this.refMap.get(id) || null;
	}

	/**
	 * Update text reference value and mark as edited.
	 *
	 * @param {string}      refId  Reference ID.
	 * @param {string}      value  New text value.
	 * @param {string|null} sideId Optional explicit print side ID.
	 */
	setTextValue(refId, value, sideId = null) {
		const ref = this.getReference(refId);
		if (!ref) return;
		if (ref.value === value) return;

		ref.value = value;
		ref.customProperties = ref.customProperties || [];

		// Mark edited
		let editedProp = ref.customProperties.find(p => p.key === 'edited');
		if (editedProp) {
			editedProp.value = true;
		} else {
			ref.customProperties.push({ key: 'edited', value: true });
		}

		this.state.isDirty = true;
		this.notify('text_change', sideId || this.resolveSideIdForRef(refId));
	}

	/**
	 * Update image reference URL and track customization state.
	 *
	 * @param {string}      refId    Reference ID.
	 * @param {string}      imageUrl New image URL or data URI.
	 * @param {string}      fileName Optional uploaded file name.
	 * @param {boolean}     isCustom Whether customer uploaded/changed from default.
	 * @param {string|null} sideId   Optional explicit print side ID.
	 */
	setImageValue(refId, imageUrl, fileName = '', isCustom = true, sideId = null) {
		const ref = this.getReference(refId);
		if (!ref) return;

		const nextUrl = imageUrl || '';
		const props = ref.customProperties = ref.customProperties || [];
		const editedProp = props.find(p => p.key === 'edited');
		const wasEdited = Boolean(editedProp && editedProp.value);
		const nextEdited = Boolean(isCustom);

		if (ref.url === nextUrl && wasEdited === nextEdited) return;

		ref.url = nextUrl;
		if (nextEdited || (ref._defaultUrl !== undefined && nextUrl !== ref._defaultUrl)) {
			delete ref.previewUrl;
		} else if (ref._defaultPreviewUrl) {
			ref.previewUrl = ref._defaultPreviewUrl;
		}

		if (editedProp) {
			editedProp.value = nextEdited;
		} else {
			props.push({ key: 'edited', value: nextEdited });
		}

		let fileProp = props.find(p => p.key === 'fileName');
		if (fileProp) {
			fileProp.value = fileName || '';
		} else {
			props.push({ key: 'fileName', value: fileName || '' });
		}

		if (nextEdited) {
			this.state.isDirty = true;
		}
		this.notify('image_change', sideId || this.resolveSideIdForRef(refId));
	}

	/**
	 * Update font family for a text line.
	 *
	 * @param {string}      refId   Reference ID.
	 * @param {string}      fontId  Font key.
	 * @param {Object|null} fontDef Font definition.
	 * @param {string|null} sideId  Optional explicit print side ID.
	 */
	setTextFont(refId, fontId, fontDef = null, sideId = null) {
		const ref = this.getReference(refId);
		if (!ref) return;
		if (ref.fontId === fontId) return;

		ref.fontId = fontId;
		if (!fontDef) {
			const fonts = (window.mrTeeProductData && window.mrTeeProductData.fonts)
				|| (window.mrTeeProductData && window.mrTeeProductData.config && window.mrTeeProductData.config.fonts)
				|| {};
			fontDef = fonts[fontId] || null;
		}

		if (fontDef) {
			ref.fontType = fontDef.font_type || (fontDef.color_svg ? 'opentype_svg' : 'truetype');
			ref.fontFamily = fontDef.family;
			ref.letterSpacing = (fontDef.letter_spacing !== undefined) ? parseFloat(fontDef.letter_spacing) : 0.05;
			ref.strokeWidth = (fontDef.stroke_width !== undefined) ? parseFloat(fontDef.stroke_width) : 0;
			ref.strokeColor = fontDef.stroke || 'transparent';
			const matchedLine = Array.isArray(this.config.text_lines)
				? this.config.text_lines.find(l => l && l.ref_id === refId)
				: null;
			const explicitCasing = (matchedLine && typeof matchedLine.casing === 'string' && matchedLine.casing)
				? matchedLine.casing
				: '';
			ref.casing = fontDef.uppercase ? 'UPPER' : (explicitCasing || 'ORIGINAL');
			ref.multiColor = Boolean(fontDef.multi_color);

			if (ref.colorRefId) {
				const colorRef = this.getReference(ref.colorRefId);
				if (colorRef) {
					if (colorRef._initialColorHex === undefined) {
						colorRef._initialColorHex = colorRef.colorHex || '';
						colorRef._initialColorMode = colorRef.colorMode || 'solid';
					}
					if (colorRef._initialMultiPalette === undefined && Array.isArray(colorRef.palette) && colorRef.palette.length > 1) {
						colorRef._initialMultiPalette = colorRef.palette.slice();
					}
					const fontMultiPalette = (Array.isArray(fontDef.palette) && fontDef.palette.length > 1)
						? fontDef.palette.slice()
						: (Array.isArray(colorRef._initialMultiPalette) ? colorRef._initialMultiPalette.slice() : null);
					const wantsMulti = Boolean(fontDef.multi_color) && Boolean(fontMultiPalette && fontMultiPalette.length > 1);

					if (wantsMulti && !colorRef._userPickedColor) {
						colorRef.colorMode = 'multi';
						colorRef.palette = fontMultiPalette;
					} else {
						colorRef.colorMode = 'solid';
						if (Array.isArray(fontDef.palette) && fontDef.palette.length > 0) {
							colorRef.palette = fontDef.palette.slice();
						}
						if (!colorRef._userPickedColor) {
							const fallbackHex = (Array.isArray(fontDef.palette) && fontDef.palette[0]) ? fontDef.palette[0] : '#111827';
							const savedSolid = (colorRef._initialColorMode === 'solid' && colorRef._initialColorHex) ? colorRef._initialColorHex : '';
							const nextHex = savedSolid || ((!colorRef.colorHex || colorRef.colorHex.toLowerCase() === '#ffffff') ? fallbackHex : colorRef.colorHex);
							colorRef.colorHex = nextHex;
							colorRef.color = { hex: nextHex };
						}
					}

					colorRef.strokeHex = fontDef.stroke || 'transparent';
					colorRef.strokeWidth = (fontDef.stroke_width !== undefined) ? parseFloat(fontDef.stroke_width) : 0;
				}
			}
		}

		this.state.isDirty = true;
		this.notify('font_change', sideId || this.resolveSideIdForRef(refId));
	}

	/**
	 * Update color selection for a COLOR_SET selector reference (supports solid hex or 'multi').
	 *
	 * @param {string}      colorRefId COLOR_SET reference ID.
	 * @param {string}      colorHex   Selected color hex code (e.g. '#08086d') or 'multi'.
	 * @param {Object|null} fontDef    Optional active font definition when restoring 'multi' palette.
	 * @param {string|null} sideId     Optional explicit print side ID.
	 */
	setColorSelection(colorRefId, colorHex, fontDef = null, sideId = null) {
		const colorRef = this.getReference(colorRefId);
		if (!colorRef) return;

		if (colorRef._initialMultiPalette === undefined && Array.isArray(colorRef.palette) && colorRef.palette.length > 1) {
			colorRef._initialMultiPalette = colorRef.palette.slice();
		}

		const resolvedSide = sideId || this.resolveSideIdForRef(colorRefId);

		if (String(colorHex).toLowerCase() === 'multi') {
			if (colorRef.colorMode === 'multi') return;
			colorRef._userPickedColor = false;
			colorRef.colorMode = 'multi';
			const nextPalette = (fontDef && Array.isArray(fontDef.palette) && fontDef.palette.length > 1)
				? fontDef.palette.slice()
				: (Array.isArray(colorRef._initialMultiPalette) && colorRef._initialMultiPalette.length > 1
					? colorRef._initialMultiPalette.slice()
					: (Array.isArray(colorRef.palette) && colorRef.palette.length > 1
						? colorRef.palette.slice()
						: ['#f9b617', '#44983a', '#4483bc', '#db160d']));
			colorRef.palette = nextPalette;
			if (!colorRef.colorHex || !String(colorRef.colorHex).startsWith('#')) {
				colorRef.colorHex = nextPalette[0] || '#f9b617';
				colorRef.color = { hex: colorRef.colorHex };
			}
			this.state.isDirty = true;
			this.notify('color_change', resolvedSide);
			return;
		}

		if (colorRef.colorMode === 'solid' && colorRef.colorHex === colorHex) return;

		colorRef._userPickedColor = true;
		colorRef.colorMode = 'solid';
		colorRef.colorHex = colorHex;
		colorRef.color = { hex: colorHex };

		// Also update any layer fill pointing to this colorRefId
		for (const layer of this.state.layers) {
			if (layer.fill && (layer.fill.colorRefId === colorRefId || layer.colorRefId === colorRefId)) {
				layer.fill.color = { hex: colorHex };
			}
		}
		this.state.isDirty = true;
		this.notify('color_change', resolvedSide);
	}

	/**
	 * Update repeater mushroom icon for a specific item index.
	 *
	 * @param {number} itemIndex 0-indexed item number.
	 * @param {string} iconKey Key of icon (e.g. 'mushroom_green').
	 * @param {string} iconUrl URL of icon image.
	 */
	setMushroomIcon(itemIndex, iconKey, iconUrl) {
		const imgRefId = `id-ref-img-champ-${itemIndex + 1}`;
		const ref = this.getReference(imgRefId);
		if (ref) {
			ref.iconKey = iconKey;
			ref.url = iconUrl;
		}
		this.notify('icon_change', this.resolveSideIdForRef(imgRefId) || 'front');
	}

	/**
	 * Update repeater mushroom name.
	 *
	 * @param {number} itemIndex 0-indexed item number.
	 * @param {string} name Custom name.
	 */
	setMushroomName(itemIndex, name) {
		const textRefId = `id-ref-text-champ-${itemIndex + 1}`;
		this.setTextValue(textRefId, name);
	}

	/**
	 * Synchronize item visibility and group editable states according to count.
	 * Implements Monsieur TSHIRT's `handleChangeReferences` section math.
	 *
	 * @param {number} count Total active mushroom count (0..6).
	 * @param {boolean} triggerNotify Whether to fire subscribers.
	 */
	updateVisibilityFromCount(count, triggerNotify = true) {
		this.state.activeCount = count;
		const max = (this.config.repeater && this.config.repeater.max_items) || 6;

		// Row 1 visibility (visible if count >= 1)
		const visRow1 = this.getReference('id-ref-vis-ligne-1');
		if (visRow1) visRow1.visible = (count >= 1);

		// Row 2 visibility (visible if count >= 4)
		const visRow2 = this.getReference('id-ref-vis-ligne-2');
		if (visRow2) visRow2.visible = (count >= 4);

		// Per-mushroom visibility and group editable state
		for (let i = 1; i <= max; i++) {
			const visRef = this.getReference(`id-ref-vis-champ-${i}`);
			const grpRef = this.getReference(`id-ref-group-champ-${i}`);
			const isActive = (i <= count);

			if (visRef) visRef.visible = isActive;
			if (grpRef) grpRef.editable = isActive;
		}

		if (triggerNotify) {
			this.notify('count_change', null);
		}
	}

	/**
	 * Validate unedited field warnings (matching Monsieur TSHIRT's `R=e=>{...}` warning checker).
	 */
	validateWarnings() {
		const warnings = [];
		const checkRef = (ref) => {
			if (!ref || ref.editable === false) return;
			const props = ref.customProperties || [];
			const warningProp = props.find(p => p.key === 'warning');
			const editedProp = props.find(p => p.key === 'edited');
			const isTextRef = !ref.type || ref.type === 'TEXT';
			const isBlankText = isTextRef && String(ref.value !== undefined ? ref.value : '').trim() === '';

			if (warningProp && warningProp.value && (!editedProp || !editedProp.value || isBlankText)) {
				warnings.push({
					id: ref.id,
					type: ref.type || 'TEXT',
					name: ref.name,
					value: ref.value !== undefined ? ref.value : (ref.name || ''),
				});
			}

			if (Array.isArray(ref.items)) {
				for (const item of ref.items) checkRef(item);
			}
		};

		// Check primary text lines
		if (this.config.text_lines) {
			for (const line of this.config.text_lines) {
				const ref = this.getReference(line.ref_id);
				if (ref) checkRef(ref);
			}
		}

		// Check active repeater items (if repeater enabled)
		if (this.config.repeater && this.config.repeater.enabled) {
			for (let i = 1; i <= this.state.activeCount; i++) {
				const grpRef = this.getReference(`id-ref-group-champ-${i}`);
				if (grpRef && grpRef.editable) {
					checkRef(grpRef);
				}
			}
		}

		this.state.warnings = warnings;
		this.state.hasUneditedWarnings = (warnings.length > 0);
	}

	/**
	 * Return clean JSON state payload for order submission.
	 *
	 * @return {Object}
	 */
	getExportPayload() {
		const textLines = (this.config.text_lines || []).map(line => {
			const ref = this.getReference(line.ref_id);
			const fieldType = line.field_type === 'image' ? 'image' : 'text';
			const sideId = line.side_id || line.sideId || 'front';
			const placements = (line.placements && typeof line.placements === 'object') ? line.placements : {};
			const boxRotate = parseFloat(line.box_rotate !== undefined ? line.box_rotate : (line.rotate !== undefined ? line.rotate : 0.0)) || 0.0;
			const boxGeometry = { box_rotate: boxRotate };
			if (line.box_left !== undefined && line.box_left !== null && !Number.isNaN(parseFloat(line.box_left))) {
				boxGeometry.box_left = parseFloat(line.box_left);
			}
			if (line.box_top !== undefined && line.box_top !== null && !Number.isNaN(parseFloat(line.box_top))) {
				boxGeometry.box_top = parseFloat(line.box_top);
			}
			if (line.box_width !== undefined && line.box_width !== null && !Number.isNaN(parseFloat(line.box_width))) {
				boxGeometry.box_width = parseFloat(line.box_width);
			}
			if (line.box_height !== undefined && line.box_height !== null && !Number.isNaN(parseFloat(line.box_height))) {
				boxGeometry.box_height = parseFloat(line.box_height);
			}

			if (fieldType === 'image') {
				const props = (ref && Array.isArray(ref.customProperties)) ? ref.customProperties : [];
				const fileProp = props.find(p => p.key === 'fileName');
				const editedProp = props.find(p => p.key === 'edited');
				const imageUrl = ref ? (ref.url || '') : (line.default_image || '');
				const isEdited = Boolean(editedProp && editedProp.value);
				const fileName = (fileProp && fileProp.value) ? fileProp.value : '';
				const displaySummary = isEdited
					? (fileName ? `Image personnalisée (${fileName})` : 'Image personnalisée')
					: (imageUrl ? 'Image par défaut' : '');
				return {
					id: line.id,
					ref_id: line.ref_id || '',
					label: line.label,
					fieldType: 'image',
					sideId,
					placements,
					value: displaySummary,
					imageUrl: imageUrl,
					fileName: fileName,
					edited: isEdited,
					imageFit: line.image_fit === 'cover' ? 'cover' : 'contain',
					colorHex: '',
					...boxGeometry,
					rotate: boxRotate,
				};
			}
			const colorRefId = (ref && ref.colorRefId) || line.color_ref_id || '';
			const colorRef = colorRefId ? this.getReference(colorRefId) : null;
			const colorHex = (colorRef && colorRef.colorHex) ? colorRef.colorHex : (line.color_hex || '');
			return {
				id: line.id,
				ref_id: line.ref_id || '',
				label: line.label,
				fieldType: 'text',
				sideId,
				placements,
				value: ref ? ref.value : line.default_text,
				fontId: ref ? ref.fontId : line.font,
				colorHex,
				...boxGeometry,
				rotate: boxRotate,
			};
		});

		const repeaterItems = [];
		if (this.config.repeater && this.config.repeater.enabled) {
			for (let i = 1; i <= this.state.activeCount; i++) {
				const textRef = this.getReference(`id-ref-text-champ-${i}`);
				const imgRef = this.getReference(`id-ref-img-champ-${i}`);
				repeaterItems.push({
					index: i,
					name: textRef ? textRef.value : `Champignon ${i}`,
					icon: imgRef ? imgRef.iconKey : 'mushroom_red',
				});
			}
		}

		return {
			productId: this.state.productId,
			templatePreset: this.config.template_preset || 'super_mushroom_24987',
			activeGarmentColor: this.state.garmentColor,
			print_sides: Array.isArray(this.config.print_sides) ? this.config.print_sides : [],
			preview_views: Array.isArray(this.config.preview_views) ? this.config.preview_views : [],
			text_lines: textLines,
			repeater_count: (this.config.repeater && this.config.repeater.enabled) ? this.state.activeCount : 0,
			repeater_items: repeaterItems,
			perso_visual: {
				layers: this.state.layers,
				references: this.state.references,
				area: this.state.area,
			},
		};
	}
}
