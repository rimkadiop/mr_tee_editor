/**
 * Mr. Tee Editor - Admin Orchestrator & Multi-Rectangle Stage Configurator
 *
 * Manages the engine competitor selector, tab switching, and modular
 * multi-rectangle per-field design area system. Merchants can draw, position,
 * and resize independent design rectangles directly on the garment/product mockup.
 *
 * @package MrTeeEditor\Assets
 */

(function($) {
	'use strict';

	const FIELD_COLORS = [
		'#2563eb', // Blue
		'#059669', // Emerald
		'#7c3aed', // Purple
		'#d97706', // Amber
		'#db2777', // Rose
		'#0891b2', // Cyan
		'#dc2626', // Red
		'#4f46e5', // Indigo
	];

	class MrTeeAdmin {
		constructor() {
			this.data = window.mrTeeAdminData || {};
			this.config = this.data.config || {};
			this.$canvasWrap = $('#mr-tee-stage-canvas-wrap');
			this.activeFieldIndex = 0;
			this._pendingSvgFetches = {};
			this._loadedTtfFonts = new Set();
			this.allowedFonts = this.readInitialAllowedFonts();

			// Track TTF fonts already rendered in @font-face CSS by Plugin::enqueue_admin_assets()
			this.seedInitiallyLoadedTtfFonts();

			this.init();
		}

		init() {
			this.bindEngineSelector();
			this.bindTabs();
			this.bindFieldSwitcher();
			this.bindInteractiveBoxes();
			this.bindInputSync();
			this.bindAddField();
			this.bindDeleteField();
			this.bindPresetReset();
			this.bindDimensionControls();
			this.bindFontUploader();
			this.bindProductAllowedFonts();
			this.bindStagePreviewRefreshes();
			this.renderAllBoxesPreviews();
		}

		isSvgFont(fontDef) {
			return Boolean(fontDef && (fontDef.font_type === 'opentype_svg' || fontDef.color_svg));
		}

		toSameOriginPath(rawUrl) {
			if (!rawUrl) return '';
			try {
				const u = new URL(rawUrl, window.location.origin);
				if (u.hostname === window.location.hostname) {
					return u.pathname + u.search;
				}
				return rawUrl;
			} catch (e) {
				return rawUrl;
			}
		}

		readInitialAllowedFonts() {
			const fonts = (window.mrTeeAdminData && window.mrTeeAdminData.fonts) || {};
			const fontKeys = Object.keys(fonts);
			let parsed = [];
			const $input = $('#mr_tee_allowed_fonts_json');
			if ($input.length) {
				try {
					const raw = JSON.parse($input.val() || '[]');
					if (Array.isArray(raw)) {
						parsed = raw;
					}
				} catch (e) {
					parsed = [];
				}
			}
			if (!parsed.length && Array.isArray(this.config.allowed_fonts)) {
				parsed = this.config.allowed_fonts.slice();
			}
			const valid = [];
			parsed.forEach(fid => {
				if (fid && (!fontKeys.length || fonts[fid]) && !valid.includes(fid)) {
					valid.push(fid);
				}
			});
			if (!valid.length && fontKeys.length) {
				valid.push(fontKeys[0]);
			} else if (!valid.length) {
				valid.push('super_mario');
			}
			return valid;
		}

		getAllowedFonts() {
			const fonts = (window.mrTeeAdminData && window.mrTeeAdminData.fonts) || {};
			const fontKeys = Object.keys(fonts);
			if (Array.isArray(this.allowedFonts) && this.allowedFonts.length) {
				const filtered = this.allowedFonts.filter(fid => !fontKeys.length || Boolean(fonts[fid]));
				if (filtered.length) return filtered;
			}
			this.allowedFonts = this.readInitialAllowedFonts();
			return this.allowedFonts;
		}

		seedInitiallyLoadedTtfFonts() {
			const initialIds = new Set();
			if (Array.isArray(this.config.allowed_fonts)) {
				this.config.allowed_fonts.forEach(fid => fid && initialIds.add(fid));
			} else if (Array.isArray(this.config.text_lines) && this.config.text_lines[0] && Array.isArray(this.config.text_lines[0].allowed_fonts)) {
				this.config.text_lines[0].allowed_fonts.forEach(fid => fid && initialIds.add(fid));
			}
			if (Array.isArray(this.config.text_lines)) {
				this.config.text_lines.forEach(tl => {
					if (tl && tl.font) initialIds.add(tl.font);
				});
			}
			initialIds.forEach(fid => this._loadedTtfFonts.add(fid));
		}

		ensureTtfFontLoaded(fontId) {
			if (!fontId || this._loadedTtfFonts.has(fontId)) return;
			const fonts = (window.mrTeeAdminData && window.mrTeeAdminData.fonts) || {};
			const fontDef = fonts[fontId];
			if (!fontDef || this.isSvgFont(fontDef) || !fontDef.url) return;

			this._loadedTtfFonts.add(fontId);

			let styleEl = document.getElementById('mr-tee-dynamic-font-faces');
			if (!styleEl) {
				styleEl = document.createElement('style');
				styleEl.id = 'mr-tee-dynamic-font-faces';
				document.head.appendChild(styleEl);
			}

			const fontUrl = this.toSameOriginPath(fontDef.url);
			const format = fontDef.format || 'truetype';
			const families = [fontDef.family];
			if (Array.isArray(fontDef.aliases)) {
				fontDef.aliases.forEach(alias => {
					if (alias && !families.includes(alias)) families.push(alias);
				});
			}

			let css = '';
			families.forEach(fam => {
				if (fam) {
					css += `@font-face { font-family: '${fam}'; src: url('${fontUrl}') format('${format}'); font-weight: normal; font-style: normal; font-display: swap; }\n`;
				}
			});
			styleEl.textContent += css;

			if (document.fonts && typeof document.fonts.load === 'function' && fontDef.family) {
				document.fonts.load(`16px "${fontDef.family}"`).then(() => {
					this.renderAllBoxesPreviews();
				}).catch(() => {});
			}
		}

		bindStagePreviewRefreshes() {
			const self = this;
			$('#mr-tee-stage-garment-img').on('load', function() {
				self.renderAllBoxesPreviews();
			});

			let resizeTimer = null;
			$(window).on('resize.mrTeeAdmin', function() {
				if (resizeTimer) clearTimeout(resizeTimer);
				resizeTimer = setTimeout(() => {
					self.renderAllBoxesPreviews();
				}, 120);
			});
		}

		/**
		 * Engine Competitor Selector Radio Buttons.
		 */
		bindEngineSelector() {
			const self = this;
			$('input[name="mr_tee_engine_mode"]').on('change', function() {
				const val = $(this).val();
				$('.mr-tee-engine-option').removeClass('is-selected');
				$(this).closest('.mr-tee-engine-option').addClass('is-selected');

				if (val === 'mr_tee_editor') {
					$('#mr-tee-settings-panel').slideDown(200, function() {
						self.renderAllBoxesPreviews();
					});
				} else {
					$('#mr-tee-settings-panel').slideUp(200);
				}
			});
		}

		/**
		 * Tabs Navigation.
		 */
		bindTabs() {
			const self = this;
			$('.mr-tee-tab-btn').on('click', function() {
				const tabId = $(this).data('tab');
				$('.mr-tee-tab-btn').removeClass('is-active');
				$(this).addClass('is-active');

				$('.mr-tee-tab-pane').removeClass('is-active');
				$('#' + tabId).addClass('is-active');

				if (tabId === 'tab-stage') {
					self.renderAllBoxesPreviews();
				}
			});
		}

		/**
		 * Field Selection Switcher (Pills and direct box clicks).
		 */
		bindFieldSwitcher() {
			const self = this;

			// Click on pill
			$(document).on('click', '.mr-tee-field-pill', function() {
				const idx = parseInt($(this).attr('data-field-index'), 10);
				if (!Number.isNaN(idx)) self.setActiveField(idx);
			});

			// Direct click on box in stage
			$(document).on('mousedown', '.mr-tee-field-box', function(e) {
				if ($(e.target).hasClass('mr-tee-handle') || $(e.target).hasClass('mr-tee-handle-rot')) return;
				const idx = parseInt($(this).attr('data-field-index'), 10);
				if (!Number.isNaN(idx) && self.activeFieldIndex !== idx) {
					self.setActiveField(idx);
				}
			});
		}

		setActiveField(index) {
			this.activeFieldIndex = index;

			// Update Pills
			$('.mr-tee-field-pill').removeClass('is-active');
			$(`.mr-tee-field-pill[data-field-index="${index}"]`).addClass('is-active');

			// Update Boxes
			$('.mr-tee-field-box').removeClass('is-active');
			$(`#mr-tee-field-box-${index}`).addClass('is-active');

			// Update Panels
			$('.mr-tee-field-panel').removeClass('is-active');
			$(`#mr-tee-field-panel-${index}`).addClass('is-active');
		}

		/**
		 * Interactive Box Drag & Resize for Multi-Rectangle System.
		 */
		bindInteractiveBoxes() {
			if (!this.$canvasWrap.length) return;

			const self = this;
			let isDragging = false;
			let isResizing = false;
			let currentHandle = null;
			let startX, startY, startLeft, startTop, startWidth, startHeight;
			let currentIdx = 0;

			// Dragging active box
			$(document).on('mousedown', '.mr-tee-field-box.is-active', function(e) {
				if ($(e.target).hasClass('mr-tee-handle') || $(e.target).hasClass('mr-tee-handle-rot')) return;
				e.preventDefault();

				currentIdx = parseInt($(this).attr('data-field-index'), 10);
				const wrapRect = self.$canvasWrap[0].getBoundingClientRect();

				isDragging = true;
				startX = e.clientX;
				startY = e.clientY;

				// Use logical percentage inputs rather than getBoundingClientRect to prevent rotation transforms from causing coordinate shifts
				startLeft = parseFloat($(`#mr_tee_field_left_${currentIdx}`).val()) || 0;
				startTop = parseFloat($(`#mr_tee_field_top_${currentIdx}`).val()) || 0;

				const curW = parseFloat($(`#mr_tee_field_width_${currentIdx}`).val()) || 40.0;
				const curH = parseFloat($(`#mr_tee_field_height_${currentIdx}`).val()) || 8.5;

				let dragRaf = null;
				$(document).on('mousemove.mrTeeDrag', function(moveEvent) {
					if (!isDragging) return;
					if (dragRaf) cancelAnimationFrame(dragRaf);
					dragRaf = requestAnimationFrame(() => {
						const dx = (moveEvent.clientX - startX) / wrapRect.width * 100;
						const dy = (moveEvent.clientY - startY) / wrapRect.height * 100;

						let newLeft = Math.max(0, Math.min(100 - curW, startLeft + dx));
						let newTop = Math.max(0, Math.min(100 - curH, startTop + dy));

						newLeft = Math.round(newLeft * 10) / 10;
						newTop = Math.round(newTop * 10) / 10;

						self.updateBoxPosition(currentIdx, newLeft, newTop);
					});
				});

				$(document).on('mouseup.mrTeeDrag', function() {
					isDragging = false;
					if (dragRaf) cancelAnimationFrame(dragRaf);
					$(document).off('.mrTeeDrag');
				});
			});

			// Resizing active box via corner handles
			$(document).on('mousedown', '.mr-tee-field-box.is-active .mr-tee-handle:not(.mr-tee-handle-rot)', function(e) {
				e.preventDefault();
				e.stopPropagation();

				const $box = $(this).closest('.mr-tee-field-box');
				currentIdx = parseInt($box.attr('data-field-index'), 10);
				currentHandle = $(this).data('handle');

				const wrapRect = self.$canvasWrap[0].getBoundingClientRect();

				isResizing = true;
				startX = e.clientX;
				startY = e.clientY;

				startLeft = parseFloat($(`#mr_tee_field_left_${currentIdx}`).val()) || 0;
				startTop = parseFloat($(`#mr_tee_field_top_${currentIdx}`).val()) || 0;
				startWidth = parseFloat($(`#mr_tee_field_width_${currentIdx}`).val()) || 40.0;
				startHeight = parseFloat($(`#mr_tee_field_height_${currentIdx}`).val()) || 8.5;

				let resizeRaf = null;
				$(document).on('mousemove.mrTeeResize', function(moveEvent) {
					if (!isResizing) return;
					if (resizeRaf) cancelAnimationFrame(resizeRaf);
					resizeRaf = requestAnimationFrame(() => {
						const dx = (moveEvent.clientX - startX) / wrapRect.width * 100;
						const dy = (moveEvent.clientY - startY) / wrapRect.height * 100;

						let newW = startWidth;
						let newH = startHeight;
						let newL = startLeft;
						let newT = startTop;

						if (currentHandle.includes('e')) newW = Math.max(5, Math.min(100 - startLeft, startWidth + dx));
						if (currentHandle.includes('s')) newH = Math.max(3, Math.min(100 - startTop, startHeight + dy));
						if (currentHandle.includes('w')) {
							newW = Math.max(5, startWidth - dx);
							newL = startLeft + dx;
						}
						if (currentHandle.includes('n')) {
							newH = Math.max(3, startHeight - dy);
							newT = startTop + dy;
						}

						newW = Math.round(newW * 10) / 10;
						newH = Math.round(newH * 10) / 10;
						newL = Math.round(newL * 10) / 10;
						newT = Math.round(newT * 10) / 10;

						self.updateBoxBounds(currentIdx, newL, newT, newW, newH);
					});
				});

				$(document).on('mouseup.mrTeeResize', function() {
					isResizing = false;
					if (resizeRaf) cancelAnimationFrame(resizeRaf);
					$(document).off('.mrTeeResize');
				});
			});

			// Rotating active box via top rotation handle
			$(document).on('mousedown', '.mr-tee-field-box.is-active .mr-tee-handle-rot', function(e) {
				e.preventDefault();
				e.stopPropagation();

				const $handle = $(this);
				const $box = $handle.closest('.mr-tee-field-box');
				currentIdx = parseInt($box.attr('data-field-index'), 10);
				$handle.addClass('is-rotating');

				const boxRect = $box[0].getBoundingClientRect();
				const centerX = (boxRect.left + boxRect.right) / 2;
				const centerY = (boxRect.top + boxRect.bottom) / 2;

				let rotRaf = null;
				$(document).on('mousemove.mrTeeRot', function(moveEvent) {
					if (rotRaf) cancelAnimationFrame(rotRaf);
					rotRaf = requestAnimationFrame(() => {
						const dx = moveEvent.clientX - centerX;
						const dy = moveEvent.clientY - centerY;

						// Vector angle relative to 12 o'clock stem (stem pointing up = 0 deg)
						let deg = (Math.atan2(dy, dx) * 180 / Math.PI) + 90;
						while (deg > 180) deg -= 360;
						while (deg < -180) deg += 360;

						// Snap to common angles
						if (Math.abs(deg) < 3) deg = 0;
						if (Math.abs(deg - 90) < 3) deg = 90;
						if (Math.abs(deg + 90) < 3) deg = -90;
						if (Math.abs(deg - 45) < 3) deg = 45;
						if (Math.abs(deg + 45) < 3) deg = -45;
						if (Math.abs(deg - 40) < 2) deg = 40;
						if (Math.abs(deg + 15) < 2) deg = -15;

						deg = Math.round(deg);
						self.updateBoxRotation(currentIdx, deg);
					});
				});

				$(document).on('mouseup.mrTeeRot', function() {
					$handle.removeClass('is-rotating');
					if (rotRaf) cancelAnimationFrame(rotRaf);
					$(document).off('.mrTeeRot');
				});
			});
		}

		updateBoxPosition(idx, left, top) {
			$(`#mr_tee_field_left_${idx}`).val(left);
			$(`#mr_tee_field_top_${idx}`).val(top);
			$(`#mr-tee-field-box-${idx}`).css({ left: left + '%', top: top + '%' });
			this.recalculateDimensions();
		}

		updateBoxRotation(idx, deg) {
			$(`#mr_tee_field_rotate_${idx}`).val(deg);
			$(`#mr-tee-field-box-${idx}`).css({
				transform: `rotate(${deg}deg)`,
				'transform-origin': 'center center',
			});

			const curW = parseFloat($(`#mr_tee_field_width_${idx}`).val()) || 40.0;
			const curH = parseFloat($(`#mr_tee_field_height_${idx}`).val()) || 8.5;
			const rotTxt = deg !== 0 ? ` (${deg}°)` : '';
			$(`.mr-tee-field-pill[data-field-index="${idx}"] .mr-tee-pill-dims`).text(`${curW}×${curH}%${rotTxt}`);

			this.recalculateDimensions();
		}

		updateBoxBounds(idx, left, top, width, height) {
			$(`#mr_tee_field_left_${idx}`).val(left);
			$(`#mr_tee_field_top_${idx}`).val(top);
			$(`#mr_tee_field_width_${idx}`).val(width);
			$(`#mr_tee_field_height_${idx}`).val(height);

			$(`#mr-tee-field-box-${idx}`).css({
				left: left + '%',
				top: top + '%',
				width: width + '%',
				height: height + '%',
			});

			// Update pill dimensions
			const r = parseFloat($(`#mr_tee_field_rotate_${idx}`).val()) || 0;
			const rotTxt = r !== 0 ? ` (${r}°)` : '';
			$(`.mr-tee-field-pill[data-field-index="${idx}"] .mr-tee-pill-dims`).text(`${width}×${height}%${rotTxt}`);

			// Re-render preview text inside this box
			this.renderBoxPreview(idx);
			this.recalculateDimensions();
		}

		/**
		 * Two-Way Input Synchronization.
		 */
		bindInputSync() {
			const self = this;
			const getFieldIdx = ($el) => {
				const raw = $el.attr('data-field-index') || $el.closest('.mr-tee-field-panel').attr('data-field-index');
				const idx = parseInt(raw, 10);
				return Number.isNaN(idx) ? 0 : idx;
			};

			// Coords Inputs (Position & Size)
			$(document).on('input change', '.mr-tee-field-coord:not(.mr-tee-field-coord-rotate)', function() {
				const idx = getFieldIdx($(this));
				const l = parseFloat($(`#mr_tee_field_left_${idx}`).val()) || 0;
				const t = parseFloat($(`#mr_tee_field_top_${idx}`).val()) || 0;
				const w = parseFloat($(`#mr_tee_field_width_${idx}`).val()) || 40;
				const h = parseFloat($(`#mr_tee_field_height_${idx}`).val()) || 8.5;
				const r = parseFloat($(`#mr_tee_field_rotate_${idx}`).val()) || 0;
				const rotTxt = r !== 0 ? ` (${r}°)` : '';

				$(`#mr-tee-field-box-${idx}`).css({
					left: l + '%',
					top: t + '%',
					width: w + '%',
					height: h + '%',
				});

				$(`.mr-tee-field-pill[data-field-index="${idx}"] .mr-tee-pill-dims`).text(`${w}×${h}%${rotTxt}`);
				self.renderBoxPreview(idx);
				self.recalculateDimensions();
			});

			// Rotation Angle Input
			$(document).on('input change', '.mr-tee-field-coord-rotate', function() {
				const idx = getFieldIdx($(this));
				const deg = parseFloat($(this).val()) || 0;
				self.updateBoxRotation(idx, deg);
			});

			// Quick Rotation Preset Buttons
			$(document).on('click', '.mr-tee-btn-rotate-preset', function(e) {
				e.preventDefault();
				const idx = getFieldIdx($(this));
				const deg = parseInt($(this).attr('data-deg') || $(this).data('deg'), 10) || 0;
				self.updateBoxRotation(idx, deg);
			});

			// Label Input
			$(document).on('input change', '.mr-tee-field-label-input', function() {
				const idx = getFieldIdx($(this));
				const val = $(this).val() || `Champ ${idx + 1}`;
				$(`.mr-tee-field-pill[data-field-index="${idx}"] .mr-tee-pill-label`).text(val);
				$(`#mr-tee-field-box-${idx} .mr-tee-box-badge`).text(`${idx + 1}. ${val}`);
				$(`#mr-tee-field-panel-${idx} .mr-tee-panel-title`).text(`Champ #${idx + 1} : ${val}`);
			});

			// Default Text Input
			$(document).on('input change', '.mr-tee-field-text-input', function() {
				const idx = getFieldIdx($(this));
				self.renderBoxPreview(idx);
			});

			// Rectangle Font Select + Two-Way Sync with Allowed Fonts #1 DÉFAUT
			$(document).on('change', '.mr-tee-field-font-select', function() {
				const idx = getFieldIdx($(this));
				const newFontId = $(this).val();
				if (idx === 0 && newFontId && self.allowedFonts.includes(newFontId) && self.allowedFonts[0] !== newFontId) {
					self.allowedFonts = [newFontId, ...self.allowedFonts.filter(id => id !== newFontId)];
					if (typeof self.renderAllowedFontsList === 'function') {
						self.renderAllowedFontsList({ skipBoxSelectSync: true });
					}
				}
				self.renderBoxPreview(idx);
			});

			// Color Mode change
			$(document).on('change', '.mr-tee-field-color-mode', function() {
				const idx = getFieldIdx($(this));
				self.renderBoxPreview(idx);
			});

			// Multiline toggle: swap <input> <-> <textarea> so newlines aren't stripped
			$(document).on('change', '.mr-tee-field-multiline', function() {
				const idx = getFieldIdx($(this));
				const isMulti = $(this).is(':checked');
				const $field = $(`[name="mr_tee_config[text_lines][${idx}][default_text]"]`);
				if (!$field.length) return;

				const currentVal = $field.val() || '';
				if (isMulti && !$field.is('textarea')) {
					const $ta = $('<textarea rows="2" class="regular-text mr-tee-field-text-input" style="min-height: 54px;"></textarea>')
						.attr('name', $field.attr('name'))
						.attr('data-field-index', idx)
						.val(currentVal);
					$field.replaceWith($ta);
				} else if (!isMulti && $field.is('textarea')) {
					const singleVal = currentVal.replace(/\r?\n/g, ' ');
					const $inp = $('<input type="text" class="regular-text mr-tee-field-text-input" />')
						.attr('name', $field.attr('name'))
						.attr('data-field-index', idx)
						.val(singleVal);
					$field.replaceWith($inp);
				}
				self.renderBoxPreview(idx);
			});
		}

		/**
		 * Add New Field Rectangle.
		 */
		bindAddField() {
			const self = this;
			$(document).on('click', '#mr-tee-btn-add-field-pill, #mr-tee-btn-add-field', function() {
				const newIdx = $('.mr-tee-field-box').length;
				const color = FIELD_COLORS[newIdx % FIELD_COLORS.length];
				const prevIdx = newIdx - 1;

				let prevTop = 25.0;
				let prevHeight = 8.5;
				if (prevIdx >= 0) {
					prevTop = parseFloat($(`#mr_tee_field_top_${prevIdx}`).val()) || 25.0;
					prevHeight = parseFloat($(`#mr_tee_field_height_${prevIdx}`).val()) || 8.5;
				}

				const newLeft = 30.0;
				const newTop = Math.min(88.0, Math.round((prevTop + prevHeight + 2.0) * 10) / 10);
				const newWidth = 40.0;
				const newHeight = 8.5;
				const fieldLabel = `Texte ${newIdx + 1}`;
				const defaultText = `Ligne ${newIdx + 1}`;

				const allowedFonts = self.getAllowedFonts();
				const defaultFontId = allowedFonts[0] || '';
				const fontSelectOptions = self.generateFontSelectOptions(defaultFontId, allowedFonts);

				// 1. Add Pill
				const pillHtml = `
					<button
						type="button"
						class="mr-tee-field-pill"
						data-field-index="${newIdx}"
						style="--pill-color: ${color};"
					>
						<span class="mr-tee-pill-badge" style="background: ${color};">${newIdx + 1}</span>
						<span class="mr-tee-pill-label">${fieldLabel}</span>
						<span class="mr-tee-pill-dims">${newWidth}×${newHeight}%</span>
					</button>
				`;
				$('#mr-tee-pills-list').append(pillHtml);

				// 2. Add Box
				const boxHtml = `
					<div
						id="mr-tee-field-box-${newIdx}"
						class="mr-tee-field-box"
						data-field-index="${newIdx}"
						style="left: ${newLeft}%; top: ${newTop}%; width: ${newWidth}%; height: ${newHeight}%; transform: rotate(0deg); transform-origin: center center; --field-color: ${color};"
					>
						<span class="mr-tee-box-badge" style="background: ${color};">
							${newIdx + 1}. ${fieldLabel}
						</span>
						<div class="mr-tee-box-svg-holder" id="mr-tee-box-svg-${newIdx}"></div>
						<div class="mr-tee-handle-rot-stem" style="background: ${color};"></div>
						<div class="mr-tee-handle-rot" data-handle="rot" style="border-color: ${color};" title="Faire pivoter le texte"></div>
						<div class="mr-tee-handle mr-tee-handle-nw" data-handle="nw" style="border-color: ${color};"></div>
						<div class="mr-tee-handle mr-tee-handle-ne" data-handle="ne" style="border-color: ${color};"></div>
						<div class="mr-tee-handle mr-tee-handle-sw" data-handle="sw" style="border-color: ${color};"></div>
						<div class="mr-tee-handle mr-tee-handle-se" data-handle="se" style="border-color: ${color};"></div>
					</div>
				`;
				$('#mr-tee-boxes-container').append(boxHtml);

				// 3. Add Panel
				const panelHtml = `
					<div
						class="mr-tee-field-panel"
						id="mr-tee-field-panel-${newIdx}"
						data-field-index="${newIdx}"
					>
						<div class="mr-tee-panel-badge-row">
							<span class="mr-tee-panel-indicator" style="background: ${color};"></span>
							<strong class="mr-tee-panel-title">Champ #${newIdx + 1} : ${fieldLabel}</strong>
						</div>
						<table class="form-table mr-tee-coords-table">
							<tr>
								<th><label>Nom du Champ</label></th>
								<td>
									<input type="text" name="mr_tee_config[text_lines][${newIdx}][label]" value="${fieldLabel}" class="regular-text mr-tee-field-label-input" data-field-index="${newIdx}" />
								</td>
							</tr>
							<tr>
								<th><label>Texte par Défaut</label></th>
								<td>
									<input type="text" name="mr_tee_config[text_lines][${newIdx}][default_text]" value="${defaultText}" class="regular-text mr-tee-field-text-input" data-field-index="${newIdx}" />
								</td>
							</tr>
							<tr>
								<th><label>Police par Défaut</label></th>
								<td>
									<select name="mr_tee_config[text_lines][${newIdx}][font]" class="mr-tee-field-font-select" data-field-index="${newIdx}">
										${fontSelectOptions}
									</select>
									<div class="mr-tee-font-upload-quick" style="margin-top:6px;">
										<label class="button button-small" style="cursor:pointer; display:inline-flex; align-items:center; gap:4px;">
											<span class="dashicons dashicons-upload" style="font-size:16px; width:16px; height:16px; line-height:16px;"></span>
											<span>Ajouter une Police OpenType-SVG (.ttf / .zip)</span>
											<input type="file" class="mr-tee-quick-font-file" accept=".ttf,.otf,.zip" style="display:none;" data-field-index="${newIdx}" />
										</label>
										<span class="mr-tee-font-upload-status" style="font-size:11px; margin-left:6px; color:#64748b;"></span>
									</div>
								</td>
							</tr>
							<tr>
								<th><label>Mode Couleur</label></th>
								<td>
									<select name="mr_tee_config[text_lines][${newIdx}][color_mode]" class="mr-tee-field-color-mode" data-field-index="${newIdx}">
										<option value="multi">Multicolore (COLOR_SET cyclique)</option>
										<option value="solid">Couleur Unie</option>
									</select>
								</td>
							</tr>
							<tr>
								<th><label>Max Caractères</label></th>
								<td>
									<input type="number" min="1" max="100" name="mr_tee_config[text_lines][${newIdx}][max_chars]" value="25" class="small-text mr-tee-field-max-chars" data-field-index="${newIdx}" />
								</td>
							</tr>
							<tr>
								<th><label>Options</label></th>
								<td>
									<label><input type="checkbox" name="mr_tee_config[text_lines][${newIdx}][allow_font_pick]" value="1" checked class="mr-tee-field-font-pick" data-field-index="${newIdx}" /> Choix de police client</label><br />
									<label><input type="checkbox" name="mr_tee_config[text_lines][${newIdx}][multiline]" value="1" class="mr-tee-field-multiline" data-field-index="${newIdx}" /> Autoriser retours à la ligne</label><br />
									<label><input type="checkbox" name="mr_tee_config[text_lines][${newIdx}][warning_unedited]" value="1" class="mr-tee-field-warning" data-field-index="${newIdx}" /> Alerter si non personnalisé</label>
								</td>
							</tr>
							<tr>
								<th><label>Position X (Left %)</label></th>
								<td>
									<input type="number" step="0.1" min="0" max="100" id="mr_tee_field_left_${newIdx}" name="mr_tee_config[text_lines][${newIdx}][box_left]" value="${newLeft}" class="small-text mr-tee-coord-input mr-tee-field-coord mr-tee-field-coord-left" data-field-index="${newIdx}" /> %
								</td>
							</tr>
							<tr>
								<th><label>Position Y (Top %)</label></th>
								<td>
									<input type="number" step="0.1" min="0" max="100" id="mr_tee_field_top_${newIdx}" name="mr_tee_config[text_lines][${newIdx}][box_top]" value="${newTop}" class="small-text mr-tee-coord-input mr-tee-field-coord mr-tee-field-coord-top" data-field-index="${newIdx}" /> %
								</td>
							</tr>
							<tr>
								<th><label>Largeur (Width %)</label></th>
								<td>
									<input type="number" step="0.1" min="5" max="100" id="mr_tee_field_width_${newIdx}" name="mr_tee_config[text_lines][${newIdx}][box_width]" value="${newWidth}" class="small-text mr-tee-coord-input mr-tee-field-coord mr-tee-field-coord-width" data-field-index="${newIdx}" /> %
								</td>
							</tr>
							<tr>
								<th><label>Hauteur (Height %)</label></th>
								<td>
									<input type="number" step="0.1" min="3" max="100" id="mr_tee_field_height_${newIdx}" name="mr_tee_config[text_lines][${newIdx}][box_height]" value="${newHeight}" class="small-text mr-tee-coord-input mr-tee-field-coord mr-tee-field-coord-height" data-field-index="${newIdx}" /> %
								</td>
							</tr>
							<tr>
								<th><label>Orientation / Rotation (°)</label></th>
								<td>
									<div style="display:flex; align-items:center; gap:8px; margin-bottom:6px;">
										<input type="number" step="1" min="-180" max="180" id="mr_tee_field_rotate_${newIdx}" name="mr_tee_config[text_lines][${newIdx}][box_rotate]" value="0" class="small-text mr-tee-coord-input mr-tee-field-coord mr-tee-field-coord-rotate" data-field-index="${newIdx}" /> °
										<span class="description" style="font-size:11px;">(-180° à 180°)</span>
									</div>
									<div class="mr-tee-rotate-presets" style="display:flex; flex-wrap:wrap; gap:4px;">
										<button type="button" class="button button-small mr-tee-btn-rotate-preset" data-field-index="${newIdx}" data-deg="0">0° (Horizontal)</button>
										<button type="button" class="button button-small mr-tee-btn-rotate-preset" data-field-index="${newIdx}" data-deg="90">90° (Vertical ⬇)</button>
										<button type="button" class="button button-small mr-tee-btn-rotate-preset" data-field-index="${newIdx}" data-deg="-90">-90° (Vertical ⬆)</button>
										<button type="button" class="button button-small mr-tee-btn-rotate-preset" data-field-index="${newIdx}" data-deg="40">40° (Oblique ↗)</button>
										<button type="button" class="button button-small mr-tee-btn-rotate-preset" data-field-index="${newIdx}" data-deg="-15">-15° (Rétro ↘)</button>
									</div>
								</td>
							</tr>
						</table>
						<div class="mr-tee-field-actions">
							<button type="button" class="button button-link-delete mr-tee-btn-delete-field" data-field-index="${newIdx}">
								Supprimer ce champ
							</button>
						</div>
					</div>
				`;
				$('#mr-tee-field-panels-container').append(panelHtml);

				// Show delete buttons
				$('.mr-tee-btn-delete-field').show();

				// Activate new field
				self.setActiveField(newIdx);
				self.renderBoxPreview(newIdx);
				self.recalculateDimensions();
			});
		}

		/**
		 * Delete a Field Rectangle.
		 */
		bindDeleteField() {
			const self = this;
			$(document).on('click', '.mr-tee-btn-delete-field', function() {
				const idx = parseInt($(this).attr('data-field-index'), 10);
				const count = $('.mr-tee-field-box').length;
				if (count <= 1 || Number.isNaN(idx)) return;

				if (!confirm('Voulez-vous vraiment supprimer ce rectangle et son champ de texte ?')) {
					return;
				}

				// Remove elements
				$(`.mr-tee-field-pill[data-field-index="${idx}"]`).remove();
				$(`#mr-tee-field-box-${idx}`).remove();
				$(`#mr-tee-field-panel-${idx}`).remove();

				// Re-index remaining elements
				self.reindexFields();
				const remaining = $('.mr-tee-field-box').length;
				self.setActiveField(Math.max(0, Math.min(idx, remaining - 1)));
				self.renderAllBoxesPreviews();
				self.recalculateDimensions();
			});
		}

		reindexFields() {
			const $pills = $('.mr-tee-field-pill');
			const $boxes = $('.mr-tee-field-box');
			const $panels = $('.mr-tee-field-panel');

			$panels.each(function(i) {
				const color = FIELD_COLORS[i % FIELD_COLORS.length];
				const labelVal = $(this).find('.mr-tee-field-label-input').val() || `Champ ${i + 1}`;
				$(this).attr('id', `mr-tee-field-panel-${i}`);
				$(this).attr('data-field-index', i);
				$(this).find('.mr-tee-panel-indicator').css('background', color);
				$(this).find('.mr-tee-panel-title').text(`Champ #${i + 1} : ${labelVal}`);
				$(this).find('.mr-tee-field-label-input').attr('name', `mr_tee_config[text_lines][${i}][label]`).attr('data-field-index', i);
				$(this).find('.mr-tee-field-text-input').attr('name', `mr_tee_config[text_lines][${i}][default_text]`).attr('data-field-index', i);
				$(this).find('.mr-tee-field-font-select').attr('name', `mr_tee_config[text_lines][${i}][font]`).attr('data-field-index', i);
				$(this).find('.mr-tee-quick-font-file').attr('data-field-index', i);
				$(this).find('.mr-tee-field-color-mode').attr('name', `mr_tee_config[text_lines][${i}][color_mode]`).attr('data-field-index', i);
				$(this).find('.mr-tee-field-max-chars').attr('name', `mr_tee_config[text_lines][${i}][max_chars]`).attr('data-field-index', i);
				$(this).find('.mr-tee-field-font-pick').attr('name', `mr_tee_config[text_lines][${i}][allow_font_pick]`).attr('data-field-index', i);
				$(this).find('.mr-tee-field-multiline').attr('name', `mr_tee_config[text_lines][${i}][multiline]`).attr('data-field-index', i);
				$(this).find('.mr-tee-field-warning').attr('name', `mr_tee_config[text_lines][${i}][warning_unedited]`).attr('data-field-index', i);
				$(this).find('.mr-tee-field-coord-left').attr('id', `mr_tee_field_left_${i}`).attr('name', `mr_tee_config[text_lines][${i}][box_left]`).attr('data-field-index', i);
				$(this).find('.mr-tee-field-coord-top').attr('id', `mr_tee_field_top_${i}`).attr('name', `mr_tee_config[text_lines][${i}][box_top]`).attr('data-field-index', i);
				$(this).find('.mr-tee-field-coord-width').attr('id', `mr_tee_field_width_${i}`).attr('name', `mr_tee_config[text_lines][${i}][box_width]`).attr('data-field-index', i);
				$(this).find('.mr-tee-field-coord-height').attr('id', `mr_tee_field_height_${i}`).attr('name', `mr_tee_config[text_lines][${i}][box_height]`).attr('data-field-index', i);
				$(this).find('.mr-tee-field-coord-rotate').attr('id', `mr_tee_field_rotate_${i}`).attr('name', `mr_tee_config[text_lines][${i}][box_rotate]`).attr('data-field-index', i);
				$(this).find('.mr-tee-btn-rotate-preset').attr('data-field-index', i);
				$(this).find('.mr-tee-btn-delete-field').attr('data-field-index', i);
			});

			$pills.each(function(i) {
				const color = FIELD_COLORS[i % FIELD_COLORS.length];
				$(this).attr('data-field-index', i);
				$(this).css('--pill-color', color);
				$(this).find('.mr-tee-pill-badge').text(i + 1).css('background', color);
			});

			$boxes.each(function(i) {
				const color = FIELD_COLORS[i % FIELD_COLORS.length];
				const labelVal = $(`#mr-tee-field-panel-${i} .mr-tee-field-label-input`).val() || `Champ ${i + 1}`;
				$(this).attr('id', `mr-tee-field-box-${i}`);
				$(this).attr('data-field-index', i);
				$(this).css('--field-color', color);
				$(this).find('.mr-tee-box-badge').text(`${i + 1}. ${labelVal}`).css('background', color);
				$(this).find('.mr-tee-box-svg-holder').attr('id', `mr-tee-box-svg-${i}`);
				$(this).find('.mr-tee-handle').css('border-color', color);
				$(this).find('.mr-tee-handle-rot-stem').css('background', color);
				$(this).find('.mr-tee-handle-rot').css('border-color', color);
			});

			if ($boxes.length <= 1) {
				$('.mr-tee-btn-delete-field').hide();
			} else {
				$('.mr-tee-btn-delete-field').show();
			}
		}

		/**
		 * Render live SVG inside each rectangle box.
		 */
		renderAllBoxesPreviews() {
			const count = $('.mr-tee-field-box').length;
			for (let i = 0; i < count; i++) {
				this.renderBoxPreview(i);
			}
		}

		getBoxMetrics(fontDef) {
			const f = String((fontDef && (fontDef.family || fontDef.id)) || '').toLowerCase();
			let charRatio = 0.56;
			if (f.includes('bebas')) charRatio = 0.42;
			else if (f.includes('press start') || f.includes('pressstart') || f.includes('arcade')) charRatio = 0.72;
			else if (f.includes('super mario') || f.includes('mario')) charRatio = 0.62;
			else if (f.includes('luckiest')) charRatio = 0.64;
			else if (f.includes('bungee')) charRatio = 0.65;
			else if (f.includes('cocogoose')) charRatio = 0.60;
			else if (f.includes('pacifico')) charRatio = 0.48;
			else if (f.includes('cinzel')) charRatio = 0.62;
			else if (f.includes('marker')) charRatio = 0.58;
			else if (f.includes('abril')) charRatio = 0.55;
			const letterSpacingEm = (fontDef && fontDef.letter_spacing !== undefined) ? parseFloat(fontDef.letter_spacing) : 0.05;
			return { charRatio, letterSpacingEm };
		}

		renderBoxPreview(idx) {
			const $holder = $(`#mr-tee-box-svg-${idx}`);
			if (!$holder.length) return;

			const rawTextVal = $(`[name="mr_tee_config[text_lines][${idx}][default_text]"]`).val();
			const textVal = (rawTextVal !== undefined && String(rawTextVal).trim() !== '') ? String(rawTextVal) : `Texte ${idx + 1}`;

			const fonts = (window.mrTeeAdminData && window.mrTeeAdminData.fonts) || {};
			const allowed = this.getAllowedFonts();
			const fallbackKey = allowed[0] || Object.keys(fonts)[0] || 'super_mario';
			const fontId = $(`select[name="mr_tee_config[text_lines][${idx}][font]"]`).val() || fallbackKey;
			const fontDef = fonts[fontId] || fonts[fallbackKey] || null;

			// Compute box visual aspect ratio so SVG glyphs and fonts render without horizontal stretching
			const el = $holder[0];
			let boxRatio = 0;
			if (el && el.clientWidth > 0 && el.clientHeight > 0) {
				boxRatio = el.clientWidth / el.clientHeight;
			} else {
				const bw = parseFloat($(`#mr_tee_field_width_${idx}`).val()) || 40;
				const bh = parseFloat($(`#mr_tee_field_height_${idx}`).val()) || 8.5;
				const rw = parseFloat($('#mr_tee_real_width').val()) || 30;
				const rh = parseFloat($('#mr_tee_real_height').val()) || 40;
				const stageRatio = rh > 0 ? (rw / rh) : 0.75;
				boxRatio = (bw / Math.max(1, bh)) * stageRatio;
			}
			boxRatio = Math.max(0.2, Math.min(20, boxRatio || 1));
			const vbH = 100;
			const vbW = Number((vbH * boxRatio).toFixed(2));

			// Handle OpenType-SVG Vector Color Fonts
			if (this.isSvgFont(fontDef)) {
				const svgFonts = (window.mrTeeAdminData && window.mrTeeAdminData.svgFonts) || {};
				const svgData = svgFonts[fontId];

				if (svgData && svgData.glyphs) {
					const unitsPerEm = svgData.unitsPerEm || 1000;
					const ascent = svgData.ascent || 800;
					const casing = fontDef.uppercase ? 'UPPER' : 'ORIGINAL';
					const displayVal = (casing === 'UPPER') ? textVal.toUpperCase() : textVal;
					const lines = displayVal.split(/\r?\n/);
					const numLines = Math.max(1, lines.length);
					const lineHeightRatio = 0.92;
					const letterSpacingUnits = (fontDef.letter_spacing !== undefined ? fontDef.letter_spacing : 0.05) * unitsPerEm;

					const lineWidths = [];
					let maxWidthUnits = 0;
					for (const lineStr of lines) {
						let wUnits = 0;
						for (let i = 0; i < lineStr.length; i++) {
							const ch = lineStr[i];
							const g = svgData.glyphs[ch] || svgData.glyphs[ch.toUpperCase()] || svgData.glyphs[ch.toLowerCase()] || { adv: svgData.defaultAdv || 500 };
							wUnits += (g.adv || 500) + (i < lineStr.length - 1 ? letterSpacingUnits : 0);
						}
						lineWidths.push(wUnits);
						if (wUnits > maxWidthUnits) maxWidthUnits = wUnits;
					}

					const maxDesignW = vbW * 0.92;
					const maxDesignH = (vbH * 0.84) / (1 + (numLines - 1) * lineHeightRatio);
					const rawW = (maxWidthUnits / unitsPerEm) * maxDesignH;
					const autoScale = (rawW > maxDesignW && maxDesignW > 0) ? (maxDesignW / rawW) : 1.0;
					const effectiveFontSize = maxDesignH * autoScale;
					const scale = effectiveFontSize / unitsPerEm;
					const lineStepY = effectiveFontSize * lineHeightRatio;
					const startCenterY = (vbH / 2) - (((numLines - 1) * lineStepY) / 2);

					let glyphsMarkup = '';
					for (let li = 0; li < lines.length; li++) {
						const lineStr = lines[li];
						const lineW = (lineWidths[li] / unitsPerEm) * effectiveFontSize;
						let cursorX = (vbW / 2) - (lineW / 2);
						const lineCenterY = startCenterY + (li * lineStepY);
						const baselineY = lineCenterY + (ascent * 0.35 * scale);

						for (let i = 0; i < lineStr.length; i++) {
							const ch = lineStr[i];
							const g = svgData.glyphs[ch] || svgData.glyphs[ch.toUpperCase()] || svgData.glyphs[ch.toLowerCase()] || { adv: svgData.defaultAdv || 500, svg: '' };
							if (g.svg) {
								glyphsMarkup += `<g class="mrtee-ot-glyph" transform="translate(${cursorX.toFixed(3)}, ${baselineY.toFixed(3)}) scale(${scale.toFixed(5)})">${g.svg}</g>\n`;
							}
							cursorX += ((g.adv || 500) + letterSpacingUnits) * scale;
						}
					}

					const defs = svgData.defs ? `<defs>${svgData.defs}</defs>` : '';
					const svg = `
						<svg viewBox="0 0 ${vbW} ${vbH}" width="100%" height="100%" preserveAspectRatio="xMidYMid meet" style="overflow:visible;">
							${defs}
							<g class="mrtee-ot-admin-glyph-group">
								${glyphsMarkup}
							</g>
						</svg>
					`;
					$holder.html(svg);
					return;
				}

				// Lazy-load OpenType-SVG manifest on demand and re-render if box still uses this font
				this.fetchSvgFontData(fontId, () => {
					const activeVal = $(`select[name="mr_tee_config[text_lines][${idx}][font]"]`).val() || fallbackKey;
					if (activeVal === fontId) {
						this.renderBoxPreview(idx);
					}
				});
			}

			// Lazy-inject @font-face for any unselected TTF font picked dynamically
			this.ensureTtfFontLoaded(fontId);

			const fontFamily = fontDef ? `'${fontDef.family}', sans-serif` : 'sans-serif';
			const rawStrokeW = (fontDef && fontDef.stroke_width !== undefined) ? parseFloat(fontDef.stroke_width) : 0;
			const strokeColor = (fontDef && fontDef.stroke && rawStrokeW > 0) ? fontDef.stroke : 'transparent';
			const textTransform = (fontDef && fontDef.uppercase) ? 'uppercase' : 'none';
			const displayVal = (fontDef && fontDef.uppercase) ? textVal.toUpperCase() : textVal;
			const lines = displayVal.split(/\r?\n/);
			const numLines = Math.max(1, lines.length);
			const lineHeightRatio = 0.92;
			const metrics = this.getBoxMetrics(fontDef);
			const letterSpacing = `${metrics.letterSpacingEm}em`;

			const maxDesignW = vbW * 0.92;
			const baseFontSize = (vbH * 0.80) / (1 + (numLines - 1) * lineHeightRatio);
			let maxEstW = 0;
			for (const l of lines) {
				const estW = baseFontSize * (l.length * metrics.charRatio + Math.max(0, l.length - 1) * metrics.letterSpacingEm);
				if (estW > maxEstW) maxEstW = estW;
			}
			const autoScale = (maxEstW > maxDesignW && maxEstW > 0) ? (maxDesignW / maxEstW) : 1.0;
			const effectiveFontSize = Math.max(8, baseFontSize * autoScale);
			const scaledStrokeW = rawStrokeW > 0 ? ((rawStrokeW * effectiveFontSize) / 16).toFixed(2) : '0';

			let textBody = '';
			if (numLines > 1) {
				const stepY = effectiveFontSize * lineHeightRatio;
				const startDy = -(((numLines - 1) * stepY) / 2);
				lines.forEach((lineStr, li) => {
					const dy = (li === 0) ? startDy : stepY;
					const lineEstW = effectiveFontSize * (lineStr.length * metrics.charRatio + Math.max(0, lineStr.length - 1) * metrics.letterSpacingEm);
					const lenAttr = (lineEstW > maxDesignW) ? ` textLength="${maxDesignW.toFixed(1)}" lengthAdjust="spacingAndGlyphs"` : '';
					textBody += `<tspan x="${(vbW / 2).toFixed(2)}" dy="${dy.toFixed(2)}"${lenAttr}>${this.escapeXml(lineStr || ' ')}</tspan>`;
				});
			} else {
				const lineEstW = effectiveFontSize * (displayVal.length * metrics.charRatio + Math.max(0, displayVal.length - 1) * metrics.letterSpacingEm);
				const lenAttr = (lineEstW > maxDesignW) ? `textLength="${maxDesignW.toFixed(1)}" lengthAdjust="spacingAndGlyphs"` : '';
				textBody = `${this.escapeXml(displayVal)}`;
				const svg = `
					<svg viewBox="0 0 ${vbW} ${vbH}" width="100%" height="100%" preserveAspectRatio="xMidYMid meet" style="overflow:visible;">
						<text
							x="${(vbW / 2).toFixed(2)}"
							y="50"
							font-family="${fontFamily}"
							font-size="${effectiveFontSize.toFixed(2)}"
							letter-spacing="${letterSpacing}"
							text-anchor="middle"
							dominant-baseline="central"
							fill="#ffffff"
							stroke="${strokeColor}"
							stroke-width="${scaledStrokeW}"
							${lenAttr}
							style="paint-order: stroke fill; stroke-linejoin: round; text-transform: ${textTransform}; filter: drop-shadow(0 1px 2px rgba(0,0,0,0.45));"
						>${textBody}</text>
					</svg>
				`;
				$holder.html(svg);
				return;
			}

			const svg = `
				<svg viewBox="0 0 ${vbW} ${vbH}" width="100%" height="100%" preserveAspectRatio="xMidYMid meet" style="overflow:visible;">
					<text
						x="${(vbW / 2).toFixed(2)}"
						y="50"
						font-family="${fontFamily}"
						font-size="${effectiveFontSize.toFixed(2)}"
						letter-spacing="${letterSpacing}"
						text-anchor="middle"
						dominant-baseline="central"
						fill="#ffffff"
						stroke="${strokeColor}"
						stroke-width="${scaledStrokeW}"
						style="paint-order: stroke fill; stroke-linejoin: round; text-transform: ${textTransform}; filter: drop-shadow(0 1px 2px rgba(0,0,0,0.45));"
					>${textBody}</text>
				</svg>
			`;

			$holder.html(svg);
		}

		fetchSvgFontData(fontId, callback) {
			window.mrTeeAdminData = window.mrTeeAdminData || {};
			window.mrTeeAdminData.svgFonts = window.mrTeeAdminData.svgFonts || {};

			if (window.mrTeeAdminData.svgFonts[fontId]) {
				if (callback) callback(window.mrTeeAdminData.svgFonts[fontId]);
				return;
			}

			if (this._pendingSvgFetches[fontId]) {
				this._pendingSvgFetches[fontId].then((data) => {
					if (data && callback) callback(data);
				});
				return;
			}

			const fonts = window.mrTeeAdminData.fonts || {};
			const def = fonts[fontId] || {};
			const pluginPath = this.toSameOriginPath(window.mrTeeAdminData.pluginUrl || '/wp-content/plugins/mr_tee_editor/');
			const normPluginPath = pluginPath.endsWith('/') ? pluginPath : `${pluginPath}/`;
			const manifestUrl = this.toSameOriginPath(def.manifest_url || `${normPluginPath}assets/fonts/cache/${fontId}/manifest.json`);

			const hydrate = (manifest) => {
				const base = this.toSameOriginPath(manifest.base || `${normPluginPath}assets/fonts/cache/${fontId}/`);
				const ver = manifest.v || '';
				const previewUrls = {};
				for (const [k, img] of Object.entries(manifest.images || {})) {
					previewUrls[k] = `${base}${img.p}?v=${ver}`;
				}
				const resolve = (svg) => {
					if (!svg || !svg.includes('{{p:')) return svg;
					return svg.replace(/\{\{p:([a-z0-9]+)\}\}/g, (m, k) => previewUrls[k] || '');
				};
				const glyphs = {};
				for (const [ch, g] of Object.entries(manifest.glyphs || {})) {
					glyphs[ch] = {
						gid: g.gid,
						adv: g.adv,
						svg: resolve(g.svg || ''),
					};
				}
				return {
					...manifest,
					defs: resolve(manifest.defs || ''),
					glyphs,
					hydrated: true,
				};
			};

			this._pendingSvgFetches[fontId] = fetch(manifestUrl)
				.then(res => {
					if (res.ok) return res.json();
					throw new Error('Manifest not found');
				})
				.then(manifest => hydrate(manifest))
				.catch(() => {
					const dataUrl = this.toSameOriginPath(def.data_url || `${normPluginPath}assets/fonts/cache/${fontId}.json`);
					return fetch(dataUrl)
						.then(res => (res.ok ? res.json() : null))
						.then(data => {
							if (data) return data;
							throw new Error('Fallback failed');
						})
						.catch(() => {
							const ajaxUrl = this.toSameOriginPath(window.mrTeeAdminData.ajaxUrl || '');
							if (!ajaxUrl) return null;
							return fetch(`${ajaxUrl}?action=mr_tee_get_svg_font_data&font_id=${encodeURIComponent(fontId)}`)
								.then(res => (res.ok ? res.json() : null))
								.then(res => (res && res.success && res.data ? res.data : null))
								.catch(() => null);
						});
				})
				.then(data => {
					if (data) {
						window.mrTeeAdminData.svgFonts[fontId] = data;
					}
					return data;
				})
				.finally(() => {
					delete this._pendingSvgFetches[fontId];
				});

			this._pendingSvgFetches[fontId].then((data) => {
				if (data && callback) callback(data);
			});
		}

		bindFontUploader() {
			const self = this;

			// Tab 2: Choose File button
			$('#mr_tee_tab2_btn_select').on('click', function(e) {
				e.preventDefault();
				$('#mr_tee_tab2_font_input').trigger('click');
			});

			$('#mr_tee_tab2_font_input').on('change', function() {
				const file = this.files && this.files[0];
				if (file) {
					$('#mr_tee_tab2_file_name').text(file.name);
					$('#mr_tee_tab2_btn_upload').show();
					$('#mr_tee_tab2_upload_status').text('').css('color', '#334155');
				}
			});

			$('#mr_tee_tab2_btn_upload').on('click', function(e) {
				e.preventDefault();
				const file = $('#mr_tee_tab2_font_input')[0].files[0];
				if (!file) return;

				const $status = $('#mr_tee_tab2_upload_status');
				$status.text('⏳ Analyse binaire du SFNT et extraction SVG en cours...').css('color', '#2563eb');

				self.uploadFontFile(file, $status, () => {
					$('#mr_tee_tab2_file_name').text('');
					$('#mr_tee_tab2_btn_upload').hide();
					$('#mr_tee_tab2_font_input').val('');
				});
			});

			// Tab 2: Drag & Drop
			const $dropZone = $('.mr-tee-font-upload-card');
			if ($dropZone.length) {
				$dropZone.on('dragover dragenter', function(e) {
					e.preventDefault();
					e.stopPropagation();
					$(this).css('background', '#eff6ff').css('border-color', '#2563eb');
				});
				$dropZone.on('dragleave drop', function(e) {
					e.preventDefault();
					e.stopPropagation();
					$(this).css('background', '#f8fafc').css('border-color', '#94a3b8');
				});
				$dropZone.on('drop', function(e) {
					const dt = e.originalEvent.dataTransfer;
					if (dt && dt.files && dt.files.length) {
						const file = dt.files[0];
						$('#mr_tee_tab2_file_name').text(file.name);
						$('#mr_tee_tab2_btn_upload').show();
						const $status = $('#mr_tee_tab2_upload_status');
						$status.text('⏳ Analyse binaire et extraction SVG...').css('color', '#2563eb');
						self.uploadFontFile(file, $status, () => {
							$('#mr_tee_tab2_file_name').text('');
							$('#mr_tee_tab2_btn_upload').hide();
							$('#mr_tee_tab2_font_input').val('');
						});
					}
				});
			}

			// Quick Upload on Stage Controls (in each field panel)
			$(document).on('change', '.mr-tee-quick-font-file', function() {
				const inputEl = this;
				const file = inputEl.files && inputEl.files[0];
				if (!file) return;

				const idx = parseInt($(inputEl).attr('data-field-index') || $(inputEl).closest('.mr-tee-field-panel').attr('data-field-index'), 10);
				const $panel = $(`#mr-tee-field-panel-${idx}`);
				const $status = $panel.find('.mr-tee-font-upload-status');
				$status.text('⏳ Import en cours...').css('color', '#2563eb');

				self.uploadFontFile(file, $status, (fonts) => {
					inputEl.value = '';
					if (fonts && fonts.length) {
						const newest = fonts[0];
						fonts.forEach(f => {
							if (!self.allowedFonts.includes(f.id)) {
								self.allowedFonts.push(f.id);
							}
						});
						if (idx === 0 && newest.id) {
							self.allowedFonts = [newest.id, ...self.allowedFonts.filter(id => id !== newest.id)];
						}
						if (typeof self.renderAllowedFontsList === 'function') {
							self.renderAllowedFontsList();
						}
						const $select = $panel.find('.mr-tee-field-font-select');
						$select.val(newest.id).trigger('change');
					}
				});
			});
		}

		/**
		 * Product Allowed Fonts & Ordering Selector Management.
		 */
		bindProductAllowedFonts() {
			const self = this;
			const $input = $('#mr_tee_allowed_fonts_json');
			if (!$input.length) return;

			self.allowedFonts = self.readInitialAllowedFonts();

			const updateHiddenAndSync = (opts = {}) => {
				$input.val(JSON.stringify(self.allowedFonts));
				$('#mr-tee-allowed-fonts-count').text(self.allowedFonts.length);

				// Keep every rectangle's font dropdown strictly restricted to allowedFonts
				if (!opts.skipBoxSelectSync) {
					self.refreshAllFontSelects();
				}

				// Update add picker dropdown options
				$('#mr_tee_add_font_picker option').each(function() {
					const val = $(this).val();
					if (!val) return;
					const inList = self.allowedFonts.includes(val);
					$(this).prop('disabled', inList);
					const text = $(this).text().replace(' (déjà active)', '');
					$(this).text(inList ? `${text} (déjà active)` : text);
				});

				self.renderAllBoxesPreviews();
			};

			const renderAllowedList = (opts = {}) => {
				const fonts = (window.mrTeeAdminData && window.mrTeeAdminData.fonts) || {};
				const $list = $('#mr-tee-allowed-fonts-list, #mr_tee_allowed_fonts_list');
				$list.empty();

				self.allowedFonts.forEach((fId, idx) => {
					const fInfo = fonts[fId] || { name: fId, font_type: 'ttf' };
					const isSvg = self.isSvgFont(fInfo);
					const isDefault = (idx === 0);
					const thumbUrl = fInfo.thumb_url ? self.toSameOriginPath(fInfo.thumb_url) : '';

					const $item = $(`
						<div class="mr-tee-allowed-font-item ${isDefault ? 'is-default-font' : ''}" data-font-id="${fId}">
							<div class="mr-tee-allowed-font-info">
								<span class="mr-tee-font-order-badge ${isDefault ? 'is-default' : 'is-option'}">
									${isDefault ? '#1 DÉFAUT' : '#' + (idx + 1) + ' OPTION'}
								</span>
								<span class="mr-tee-font-type-pill ${isSvg ? 'is-svg' : 'is-ttf'}">
									${isSvg ? 'SVG 3D' : 'TTF'}
								</span>
								${thumbUrl ? `<img src="${thumbUrl}" alt="" style="width:18px; height:18px; object-fit:contain;" />` : ''}
								<strong class="mr-tee-font-name-label">${self.escapeXml(fInfo.name || fId)}</strong>
							</div>
							<div class="mr-tee-allowed-font-actions">
								<button type="button" class="mr-tee-btn-font-action mr-tee-btn-font-up" title="Monter" ${isDefault ? 'disabled' : ''}>▲</button>
								<button type="button" class="mr-tee-btn-font-action mr-tee-btn-font-down" title="Descendre" ${idx === self.allowedFonts.length - 1 ? 'disabled' : ''}>▼</button>
								<button type="button" class="mr-tee-btn-font-action mr-tee-btn-font-delete" title="Retirer cette police" ${self.allowedFonts.length <= 1 ? 'disabled' : ''}>✕</button>
							</div>
						</div>
					`);
					$list.append($item);
				});

				updateHiddenAndSync(opts);
			};

			self.renderAllowedFontsList = renderAllowedList;

			// Move Up
			$(document).on('click', '.mr-tee-btn-font-up', function() {
				const $item = $(this).closest('.mr-tee-allowed-font-item');
				const fId = $item.attr('data-font-id');
				const idx = self.allowedFonts.indexOf(fId);
				if (idx > 0) {
					const temp = self.allowedFonts[idx - 1];
					self.allowedFonts[idx - 1] = self.allowedFonts[idx];
					self.allowedFonts[idx] = temp;
					renderAllowedList();
				}
			});

			// Move Down
			$(document).on('click', '.mr-tee-btn-font-down', function() {
				const $item = $(this).closest('.mr-tee-allowed-font-item');
				const fId = $item.attr('data-font-id');
				const idx = self.allowedFonts.indexOf(fId);
				if (idx !== -1 && idx < self.allowedFonts.length - 1) {
					const temp = self.allowedFonts[idx + 1];
					self.allowedFonts[idx + 1] = self.allowedFonts[idx];
					self.allowedFonts[idx] = temp;
					renderAllowedList();
				}
			});

			// Delete / Exclude
			$(document).on('click', '.mr-tee-btn-font-delete', function() {
				if (self.allowedFonts.length <= 1) return;
				const $item = $(this).closest('.mr-tee-allowed-font-item');
				const fId = $item.attr('data-font-id');
				self.allowedFonts = self.allowedFonts.filter(id => id !== fId);
				renderAllowedList();
			});

			// Add Font
			$('#mr_tee_btn_add_allowed_font').on('click', function() {
				const fId = $('#mr_tee_add_font_picker').val();
				if (!fId) return;
				if (!self.allowedFonts.includes(fId)) {
					self.allowedFonts.push(fId);
					renderAllowedList();
					$('#mr_tee_add_font_picker').val('');
				}
			});

			// Preset: Only #1
			$('#mr_tee_preset_only_first').on('click', function() {
				if (self.allowedFonts.length > 0) {
					self.allowedFonts = [self.allowedFonts[0]];
					renderAllowedList();
				}
			});

			// Preset: OpenType-SVG Fonts
			$('#mr_tee_preset_all_svg').on('click', function() {
				const fonts = (window.mrTeeAdminData && window.mrTeeAdminData.fonts) || {};
				const canonicalSvg = ['pizzeria', 'partyboy', 'brainzoo', 'purple_magic', 'jellyjam', 'lovespink_black'];
				const existingSvgKeys = canonicalSvg.filter(k => Boolean(fonts[k]));
				Object.keys(fonts).forEach(k => {
					if (self.isSvgFont(fonts[k]) && !existingSvgKeys.includes(k)) {
						existingSvgKeys.push(k);
					}
				});
				if (existingSvgKeys.length) {
					self.allowedFonts = existingSvgKeys;
					renderAllowedList();
				}
			});

			// Preset: All Fonts
			$('#mr_tee_preset_all_fonts').on('click', function() {
				const fonts = (window.mrTeeAdminData && window.mrTeeAdminData.fonts) || {};
				self.allowedFonts = Object.keys(fonts);
				renderAllowedList();
			});

			// Initial render and sync
			renderAllowedList();
		}

		registerUploadedFontsInUi(uploadedFonts) {
			if (!Array.isArray(uploadedFonts) || !uploadedFonts.length) return;
			const $picker = $('#mr_tee_add_font_picker');
			const $svgGroup = $picker.find('optgroup').eq(0);
			const $stdGroup = $picker.find('optgroup').eq(1);
			const $grid = $('.mr-tee-registered-svg-fonts-grid');

			uploadedFonts.forEach(f => {
				if (!f || !f.id) return;
				const isSvg = this.isSvgFont(f);

				if ($picker.length && !$picker.find(`option[value="${f.id}"]`).length) {
					const optHtml = `<option value="${this.escapeXml(f.id)}">${this.escapeXml(f.name || f.id)}</option>`;
					if (isSvg && $svgGroup.length) {
						$svgGroup.append(optHtml);
					} else if ($stdGroup.length) {
						$stdGroup.append(optHtml);
					} else {
						$picker.append(optHtml);
					}
				}

				if (isSvg && $grid.length && !$grid.find(`[data-font-id="${f.id}"]`).length) {
					const cardHtml = `
						<div class="mr-tee-svg-font-card" data-font-id="${this.escapeXml(f.id)}" style="background:#f1f5f9; border:1px solid #e2e8f0; border-radius:6px; padding:10px 14px; display:flex; align-items:center; justify-content:space-between;">
							<div>
								<strong style="color:#0f172a; font-size:13px; display:block;">${this.escapeXml(f.name || f.id)}</strong>
								<span style="font-size:11px; color:#64748b; font-family:monospace;">${this.escapeXml(f.file || '')}</span>
							</div>
							<span style="background:#dbeafe; color:#1d4ed8; font-size:10px; font-weight:700; padding:3px 7px; border-radius:12px; text-transform:uppercase;">SVG COLOR</span>
						</div>
					`;
					$grid.append(cardHtml);
				}
			});
		}

		uploadFontFile(file, $statusElem, onSuccess) {
			const self = this;
			const formData = new FormData();
			formData.append('action', 'mr_tee_upload_font');
			formData.append('nonce', window.mrTeeAdminData ? window.mrTeeAdminData.nonce : '');
			formData.append('font_file', file);

			const ajaxUrl = self.toSameOriginPath((window.mrTeeAdminData && window.mrTeeAdminData.ajaxUrl) || '/wp-admin/admin-ajax.php');

			$.ajax({
				url: ajaxUrl,
				type: 'POST',
				data: formData,
				processData: false,
				contentType: false,
				success: function(resp) {
					if (resp.success && resp.data && resp.data.fonts) {
						const fonts = resp.data.fonts;
						$statusElem.text(`✅ ${resp.data.message || 'Police importée avec succès !'}`).css('color', '#059669');

						// Register fonts into local admin data
						window.mrTeeAdminData = window.mrTeeAdminData || {};
						window.mrTeeAdminData.fonts = window.mrTeeAdminData.fonts || {};
						fonts.forEach(f => {
							window.mrTeeAdminData.fonts[f.id] = f;
						});

						self.registerUploadedFontsInUi(fonts);
						self.refreshAllFontSelects();
						self.renderAllBoxesPreviews();

						if (onSuccess) onSuccess(fonts);
					} else {
						const errMsg = (resp.data && resp.data.message) ? resp.data.message : 'Erreur lors de l\'importation de la police.';
						$statusElem.text(`❌ ${errMsg}`).css('color', '#dc2626');
					}
				},
				error: function(xhr, status, error) {
					$statusElem.text(`❌ Erreur réseau / serveur : ${error}`).css('color', '#dc2626');
				}
			});
		}

		refreshAllFontSelects() {
			const self = this;
			const allowed = self.getAllowedFonts();
			const defaultFontId = allowed[0] || '';

			$('.mr-tee-field-font-select').each(function(domIdx) {
				const attrIdx = parseInt($(this).attr('data-field-index'), 10);
				const idx = Number.isNaN(attrIdx) ? domIdx : attrIdx;
				const prevVal = $(this).val();
				const targetVal = (idx === 0 || !prevVal || !allowed.includes(prevVal)) ? defaultFontId : prevVal;

				const html = self.generateFontSelectOptions(targetVal, allowed);
				$(this).html(html);
				if (targetVal) {
					$(this).val(targetVal);
				}
			});
		}

		generateFontSelectOptions(selectedId = 'super_mario', allowedIds = null) {
			const fonts = (window.mrTeeAdminData && window.mrTeeAdminData.fonts) || {};
			const idsToRender = Array.isArray(allowedIds) ? allowedIds : this.getAllowedFonts();
			let svgOptions = '';
			let stdOptions = '';

			idsToRender.forEach(id => {
				const f = fonts[id];
				if (!f) return;
				const isSel = (id === selectedId) ? 'selected' : '';
				const name = this.escapeXml(f.name || id);
				if (this.isSvgFont(f)) {
					svgOptions += `<option value="${id}" ${isSel}>${name}</option>`;
				} else {
					stdOptions += `<option value="${id}" ${isSel}>${name}</option>`;
				}
			});

			let html = '';
			if (svgOptions) {
				html += `<optgroup label="✨ Polices OpenType-SVG (Couleurs & Dégradés)">${svgOptions}</optgroup>`;
			}
			if (stdOptions) {
				html += `<optgroup label="🔤 Polices Stylisées Standalone (TTF / Multi-Couleurs)">${stdOptions}</optgroup>`;
			}
			return html;
		}

		bindPresetReset() {
			const self = this;
			$('#mr_tee_template_preset').on('change', function() {
				// Preset switched - form can be submitted or preview updated
				self.renderAllBoxesPreviews();
			});
		}

		/**
		 * Physical Dimensions & Automatic Aspect Ratio Controls.
		 */
		bindDimensionControls() {
			const self = this;

			// Listen to dimension inputs on Stage Toolbar
			$(document).on('input change', '#mr_tee_real_width, #mr_tee_real_height, #mr_tee_print_unit, #mr_tee_dpi, #mr_tee_product_type, #mr_tee_lock_aspect_ratio', function() {
				// Sync to TAB 4
				$('#mr_tee_tab4_product_type').val($('#mr_tee_product_type').val());
				$('#mr_tee_tab4_real_width').val($('#mr_tee_real_width').val());
				$('#mr_tee_tab4_real_height').val($('#mr_tee_real_height').val());
				$('#mr_tee_tab4_print_unit').val($('#mr_tee_print_unit').val());
				$('#mr_tee_tab4_dpi').val($('#mr_tee_dpi').val());
				$('#mr_tee_tab4_lock_ratio').prop('checked', $('#mr_tee_lock_aspect_ratio').is(':checked'));

				self.recalculateDimensions();
				requestAnimationFrame(() => self.renderAllBoxesPreviews());
			});

			// Listen to dimension inputs on TAB 4
			$(document).on('input change', '#mr_tee_tab4_real_width, #mr_tee_tab4_real_height, #mr_tee_tab4_print_unit, #mr_tee_tab4_dpi, #mr_tee_tab4_product_type, #mr_tee_tab4_lock_ratio', function() {
				// Sync to Stage Toolbar
				$('#mr_tee_product_type').val($('#mr_tee_tab4_product_type').val());
				$('#mr_tee_real_width').val($('#mr_tee_tab4_real_width').val());
				$('#mr_tee_real_height').val($('#mr_tee_tab4_real_height').val());
				$('#mr_tee_print_unit').val($('#mr_tee_tab4_print_unit').val());
				$('#mr_tee_dpi').val($('#mr_tee_tab4_dpi').val());
				$('#mr_tee_lock_aspect_ratio').prop('checked', $('#mr_tee_tab4_lock_ratio').is(':checked'));

				self.recalculateDimensions();
				requestAnimationFrame(() => self.renderAllBoxesPreviews());
			});

			// Quick Dimension Presets
			$(document).on('click', '.mr-tee-btn-preset-dim', function(e) {
				e.preventDefault();
				const pType = $(this).data('type') || 'garment';
				const pUnit = $(this).data('unit') || 'cm';
				const pW = parseFloat($(this).data('w')) || 30;
				const pH = parseFloat($(this).data('h')) || 40;
				const pLock = parseInt($(this).data('lock'), 10) === 1;

				$('#mr_tee_product_type').val(pType);
				$('#mr_tee_print_unit').val(pUnit);
				$('#mr_tee_real_width').val(pW);
				$('#mr_tee_real_height').val(pH);
				$('#mr_tee_lock_aspect_ratio').prop('checked', pLock);

				// Sync TAB 4
				$('#mr_tee_tab4_product_type').val(pType);
				$('#mr_tee_tab4_print_unit').val(pUnit);
				$('#mr_tee_tab4_real_width').val(pW);
				$('#mr_tee_tab4_real_height').val(pH);
				$('#mr_tee_tab4_lock_ratio').prop('checked', pLock);

				self.recalculateDimensions();
				requestAnimationFrame(() => self.renderAllBoxesPreviews());
			});

			// Initial calculation
			this.recalculateDimensions();
		}

		recalculateDimensions() {
			const w = parseFloat($('#mr_tee_real_width').val()) || 30.0;
			const h = parseFloat($('#mr_tee_real_height').val()) || 40.0;
			const unit = $('#mr_tee_print_unit').val() || 'cm';
			const dpi = parseInt($('#mr_tee_dpi').val(), 10) || 300;
			const pType = $('#mr_tee_product_type').val() || 'garment';
			const isLocked = $('#mr_tee_lock_aspect_ratio').is(':checked') || pType === 'flat_object';

			const refRatio = (h > 0) ? (w / h) : 1.0;

			// Calculate cumulative bounding box across all active fields
			let xMin = 100, yMin = 100, xMax = 0, yMax = 0;
			let boxCount = 0;
			$('.mr-tee-field-box').each(function() {
				const idx = parseInt($(this).attr('data-field-index'), 10);
				if (Number.isNaN(idx)) return;
				const l = parseFloat($(`#mr_tee_field_left_${idx}`).val()) || 0;
				const t = parseFloat($(`#mr_tee_field_top_${idx}`).val()) || 0;
				const bw = parseFloat($(`#mr_tee_field_width_${idx}`).val()) || 10;
				const bh = parseFloat($(`#mr_tee_field_height_${idx}`).val()) || 10;
				const r = parseFloat($(`#mr_tee_field_rotate_${idx}`).val()) || 0;

				if (r !== 0) {
					const rad = (r * Math.PI) / 180;
					const cos = Math.abs(Math.cos(rad));
					const sin = Math.abs(Math.sin(rad));
					const halfW = (bw * cos + (bh / refRatio) * sin) / 2;
					const halfH = ((bw * refRatio) * sin + bh * cos) / 2;
					const cx = l + (bw / 2);
					const cy = t + (bh / 2);

					xMin = Math.min(xMin, cx - halfW);
					yMin = Math.min(yMin, cy - halfH);
					xMax = Math.max(xMax, cx + halfW);
					yMax = Math.max(yMax, cy + halfH);
				} else {
					xMin = Math.min(xMin, l);
					yMin = Math.min(yMin, t);
					xMax = Math.max(xMax, l + bw);
					yMax = Math.max(yMax, t + bh);
				}
				boxCount++;
			});

			if (boxCount === 0 || pType === 'flat_object') {
				xMin = 0; yMin = 0; xMax = 100; yMax = 100;
			}

			const spanW = Math.max(1, xMax - xMin);
			const spanH = Math.max(1, yMax - yMin);

			// Real physical dimensions of the cumulative design area
			const cumRealW = Number(((spanW / 100) * w).toFixed(2));
			const cumRealH = Number(((spanH / 100) * h).toFixed(2));

			let cumInchW = cumRealW / 2.54;
			let cumInchH = cumRealH / 2.54;
			if (unit === 'mm') {
				cumInchW = cumRealW / 25.4;
				cumInchH = cumRealH / 25.4;
			} else if (unit === 'in') {
				cumInchW = cumRealW;
				cumInchH = cumRealH;
			}

			const pxW = Math.max(10, Math.round(cumInchW * dpi));
			const pxH = Math.max(10, Math.round(cumInchH * dpi));
			const cumRatio = (cumRealH > 0) ? (cumRealW / cumRealH) : 1.0;
			const simplified = this.getSimplifiedRatio(cumRealW, cumRealH);

			// Update Live Badge
			$('#mr-tee-badge-cum-val').text(`${cumRealW} × ${cumRealH} ${unit}`);
			$('#mr-tee-badge-ratio-val').text(`${simplified} (~${cumRatio.toFixed(3)} : 1)`);
			$('#mr-tee-badge-px-val').text(`${pxW} × ${pxH} px`);

			// Update TAB 4 pixel inputs
			$('#mr_tee_tab4_width_px').val(pxW);
			$('#mr_tee_tab4_height_px').val(pxH);

			// Update Stage Canvas Wrap aspect-ratio
			if (this.$canvasWrap.length) {
				if (isLocked) {
					this.$canvasWrap.addClass('is-aspect-locked');
					this.$canvasWrap.css('aspect-ratio', refRatio.toString());
				} else {
					this.$canvasWrap.removeClass('is-aspect-locked');
					this.$canvasWrap.css('aspect-ratio', '');
				}
			}
		}

		getSimplifiedRatio(w, h) {
			if (!w || !h || w <= 0 || h <= 0) return '1:1';
			const ratio = w / h;
			if (Math.abs(ratio - (21.0 / 29.7)) < 0.015) {
				return '1:1.414 (1:√2)';
			}
			if (Math.abs(ratio - (29.7 / 21.0)) < 0.015) {
				return '1.414:1 (√2:1)';
			}

			const factor = 10;
			let a = Math.round(w * factor);
			let b = Math.round(h * factor);
			const origA = a;
			const origB = b;

			while (b !== 0) {
				const t = b;
				b = a % b;
				a = t;
			}
			const gcd = a > 0 ? a : 1;
			const numW = origA / gcd;
			const numH = origB / gcd;

			if (numW > 100 || numH > 100) {
				return `${ratio.toFixed(2)}:1`;
			}
			return `${numW}:${numH}`;
		}

		escapeXml(str) {
			return String(str)
				.replace(/&/g, '&amp;')
				.replace(/</g, '&lt;')
				.replace(/>/g, '&gt;')
				.replace(/"/g, '&quot;')
				.replace(/'/g, '&apos;');
		}
	}

	if (document.readyState === 'loading') {
		$(document).ready(function() {
			new MrTeeAdmin();
		});
	} else {
		new MrTeeAdmin();
	}

})(jQuery);
