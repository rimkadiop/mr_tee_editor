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
	constructor(initialConfig) {
		this.config = initialConfig || {};
		this.listeners = new Set();

		const perso = this.config.perso_visual || {};
		this.state = {
			productId: this.config.productId || 0,
			layers: Array.isArray(perso.layers) ? JSON.parse(JSON.stringify(perso.layers)) : [],
			references: Array.isArray(perso.references) ? JSON.parse(JSON.stringify(perso.references)) : [],
			persoVisualCfg: perso.perso_visual_cfg || { sections: [] },
			area: perso.area || { width: 2400, height: 3000, higherAnchor: 'top', widerAnchor: 'middle' },
			garmentColor: this.config.garment_colors && this.config.garment_colors[0] ? this.config.garment_colors[0] : null,
			activeCount: (this.config.repeater && this.config.repeater.default_count) || 2,
			isDirty: false,
			hasUneditedWarnings: false,
			warnings: [],
		};

		this.initReferencesIndex();
		this.updateVisibilityFromCount(this.state.activeCount, false);
	}

	/**
	 * Build fast ID lookup index for references.
	 */
	initReferencesIndex() {
		this.refMap = new Map();
		const indexRefs = (list) => {
			if (!Array.isArray(list)) return;
			for (const ref of list) {
				if (ref && ref.id) {
					this.refMap.set(ref.id, ref);
				}
				if (ref && Array.isArray(ref.items)) {
					indexRefs(ref.items);
				}
			}
		};
		indexRefs(this.state.references);
	}

	/**
	 * Subscribe to state changes.
	 *
	 * @param {Function} listener Callback receiving (state, changeType).
	 * @return {Function} Unsubscribe function.
	 */
	subscribe(listener) {
		this.listeners.add(listener);
		return () => this.listeners.delete(listener);
	}

	/**
	 * Notify all subscribers of state mutation.
	 *
	 * @param {string} changeType Identifier for the change event.
	 */
	notify(changeType) {
		for (const listener of this.listeners) {
			try {
				listener(this.state, changeType);
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
	 * @param {string} refId Reference ID.
	 * @param {string} value New text value.
	 */
	setTextValue(refId, value) {
		const ref = this.getReference(refId);
		if (!ref) return;

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
		this.validateWarnings();
		this.notify('text_change');
	}

	/**
	 * Update font family for a text line.
	 *
	 * @param {string} refId Reference ID.
	 * @param {string} fontId Font key.
	 * @param {Object} fontDef Font definition.
	 */
	setTextFont(refId, fontId, fontDef = null) {
		const ref = this.getReference(refId);
		if (!ref) return;

		ref.fontId = fontId;
		if (!fontDef) {
			const fonts = (window.mrTeeProductData && window.mrTeeProductData.fonts)
				|| (window.mrTeeProductData && window.mrTeeProductData.config && window.mrTeeProductData.config.fonts)
				|| {};
			fontDef = fonts[fontId] || null;
		}

		if (fontDef) {
			ref.fontFamily = fontDef.family;
			if (fontDef.palette && ref.colorRefId) {
				const colorRef = this.getReference(ref.colorRefId);
				if (colorRef && colorRef.colorMode === 'multi') {
					colorRef.palette = fontDef.palette;
				}
			}
			if (fontDef.stroke !== undefined && ref.colorRefId) {
				const colorRef = this.getReference(ref.colorRefId);
				if (colorRef) {
					colorRef.strokeHex = fontDef.stroke;
				}
			}
		}

		this.state.isDirty = true;
		this.notify('font_change');
	}

	/**
	 * Update color selection for a COLOR_SET selector reference.
	 *
	 * @param {string} colorRefId COLOR_SET reference ID.
	 * @param {string} colorHex Selected color hex code (e.g. '#08086d').
	 */
	setColorSelection(colorRefId, colorHex) {
		const colorRef = this.getReference(colorRefId);
		if (colorRef) {
			colorRef.colorHex = colorHex;
			colorRef.color = { hex: colorHex };
		}
		// Also update any layer fill pointing to this colorRefId
		for (const layer of this.state.layers) {
			if (layer.fill && (layer.fill.colorRefId === colorRefId || layer.colorRefId === colorRefId)) {
				layer.fill.color = { hex: colorHex };
			}
		}
		this.notify('color_change');
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
		this.notify('icon_change');
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

		this.validateWarnings();
		if (triggerNotify) {
			this.notify('count_change');
		}
	}

	/**
	 * Validate unedited field warnings (matching Monsieur TSHIRT's `R=e=>{...}` warning checker).
	 */
	validateWarnings() {
		const warnings = [];
		const checkRef = (ref) => {
			if (!ref) return;
			const props = ref.customProperties || [];
			const warningProp = props.find(p => p.key === 'warning');
			const editedProp = props.find(p => p.key === 'edited');

			if (warningProp && warningProp.value && (!editedProp || !editedProp.value)) {
				warnings.push({
					id: ref.id,
					name: ref.name,
					value: ref.value,
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
			return {
				id: line.id,
				label: line.label,
				value: ref ? ref.value : line.default_text,
				fontId: ref ? ref.fontId : line.font,
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
