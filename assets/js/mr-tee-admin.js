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
			this._cachedStageRatio = 0;
			this.defaultThumbUrl = this.normalizeAdminAssetUrl(
				$('.mr-tee-admin-wrap').attr('data-thumb-url')
				|| $('#mr-tee-stage-garment-img').attr('src')
				|| ''
			);
			this.activeFieldIndex = 0;
			this._pendingSvgFetches = {};
			this._loadedTtfFonts = new Set();
			this._syncingSideDims = false;
			this._multiviewInitialized = false;
			this.allowedFonts = this.readInitialAllowedFonts();
			this.printSides = this.readInitialPrintSides();
			this.previewViews = this.readInitialPreviewViews();
			this.activeViewId = this.previewViews.length ? this.previewViews[0].id : 'view_1';

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
			this.bindMultiViewManager();
			this.bindFontUploader();
			this.bindProductAllowedFonts();
			this.bindStagePreviewRefreshes();
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

		normalizeAdminAssetUrl(rawUrl) {
			if (!rawUrl) return '';
			try {
				const u = new URL(rawUrl, window.location.origin);
				if (u.hostname === window.location.hostname) {
					return window.location.origin + u.pathname + u.search;
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

		getFirstTextFieldIndex() {
			let firstText = 0;
			const $panels = $('.mr-tee-field-panel');
			if (!$panels.length) return 0;
			for (let i = 0; i < $panels.length; i++) {
				if (this.getFieldType(i) !== 'image') {
					firstText = i;
					break;
				}
			}
			return firstText;
		}

		seedInitiallyLoadedTtfFonts() {
			const initialIds = new Set();
			if (Array.isArray(this.config.allowed_fonts)) {
				this.config.allowed_fonts.forEach(fid => fid && initialIds.add(fid));
			} else if (Array.isArray(this.config.text_lines)) {
				const firstText = this.config.text_lines.find(tl => tl && tl.field_type !== 'image' && Array.isArray(tl.allowed_fonts));
				if (firstText) {
					firstText.allowed_fonts.forEach(fid => fid && initialIds.add(fid));
				}
			}
			if (Array.isArray(this.config.text_lines)) {
				this.config.text_lines.forEach(tl => {
					if (tl && tl.field_type !== 'image' && tl.font) initialIds.add(tl.font);
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
				self._cachedStageRatio = 0;
				self.renderAllBoxesPreviews();
			});

			let resizeTimer = null;
			$(window).on('resize.mrTeeAdmin', function() {
				self._cachedStageRatio = 0;
				if (resizeTimer) clearTimeout(resizeTimer);
				resizeTimer = setTimeout(() => {
					self.renderAllBoxesPreviews();
				}, 120);
			});

			if (typeof window.ResizeObserver === 'function' && this.$canvasWrap && this.$canvasWrap[0]) {
				let prevW = 0;
				let prevH = 0;
				const ro = new window.ResizeObserver(entries => {
					const entry = entries && entries[0];
					if (!entry || !entry.contentRect) return;
					const w = Math.round(entry.contentRect.width);
					const h = Math.round(entry.contentRect.height);
					if (w > 20 && h > 20 && (Math.abs(w - prevW) > 1 || Math.abs(h - prevH) > 1)) {
						prevW = w;
						prevH = h;
						self._cachedStageRatio = w / h;
						self.renderAllBoxesPreviews();
					}
				});
				ro.observe(this.$canvasWrap[0]);
			}
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
						self._cachedStageRatio = 0;
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
					self._cachedStageRatio = 0;
					self.renderAllBoxesPreviews();
				}
			});
		}

		/**
		 * Field Selection Switcher (Pills and direct box clicks).
		 */
		bindFieldSwitcher() {
			const self = this;

			// Click on pill (auto-switches view if field belongs to another printable side)
			$(document).on('click', '.mr-tee-field-pill', function() {
				const idx = parseInt($(this).attr('data-field-index'), 10);
				if (Number.isNaN(idx)) return;
				const fieldSide = self.getFieldSideId(idx);
				const activeView = self.getActiveView();
				if (activeView && fieldSide && activeView.side_id !== fieldSide) {
					const targetView = self.getPrimaryViewForSide(fieldSide, true);
					if (targetView) {
						self.setActiveView(targetView.id, { preferredFieldIndex: idx });
						return;
					}
				}
				self.setActiveField(idx);
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
		 * Interactive Box Drag & Resize for Multi-Rectangle System (60/120 FPS GPU-Accelerated).
		 */
		bindInteractiveBoxes() {
			if (!this.$canvasWrap.length) return;

			const self = this;
			let isDragging = false;
			let isResizing = false;
			let currentHandle = null;
			let startX, startY, startLeft, startTop, startWidth, startHeight;
			let currentIdx = 0;

			// Dragging active box (GPU translate3d during mousemove, committed on mouseup)
			$(document).on('mousedown', '.mr-tee-field-box.is-active', function(e) {
				if ($(e.target).hasClass('mr-tee-handle') || $(e.target).hasClass('mr-tee-handle-rot')) return;
				e.preventDefault();

				const boxEl = this;
				currentIdx = parseInt(boxEl.getAttribute('data-field-index'), 10);
				const wrapRect = self.$canvasWrap[0].getBoundingClientRect();
				if (!wrapRect.width || !wrapRect.height) return;

				const leftInput = document.getElementById(`mr_tee_field_left_${currentIdx}`);
				const topInput = document.getElementById(`mr_tee_field_top_${currentIdx}`);
				const widthInput = document.getElementById(`mr_tee_field_width_${currentIdx}`);
				const heightInput = document.getElementById(`mr_tee_field_height_${currentIdx}`);
				const rotInput = document.getElementById(`mr_tee_field_rotate_${currentIdx}`);

				isDragging = true;
				startX = e.clientX;
				startY = e.clientY;

				// Use logical percentage inputs rather than getBoundingClientRect to prevent rotation transforms from causing coordinate shifts
				startLeft = leftInput ? (parseFloat(leftInput.value) || 0) : 0;
				startTop = topInput ? (parseFloat(topInput.value) || 0) : 0;

				const curW = widthInput ? (parseFloat(widthInput.value) || 40.0) : 40.0;
				const curH = heightInput ? (parseFloat(heightInput.value) || 8.5) : 8.5;
				const startRotDeg = rotInput ? (parseFloat(rotInput.value) || 0) : 0;

				let latestLeft = startLeft;
				let latestTop = startTop;
				boxEl.classList.add('is-interacting');

				const applyDragDelta = (clientX, clientY) => {
					const dx = ((clientX - startX) / wrapRect.width) * 100;
					const dy = ((clientY - startY) / wrapRect.height) * 100;

					let newLeft = Math.max(0, Math.min(100 - curW, startLeft + dx));
					let newTop = Math.max(0, Math.min(100 - curH, startTop + dy));

					newLeft = Math.round(newLeft * 10) / 10;
					newTop = Math.round(newTop * 10) / 10;

					latestLeft = newLeft;
					latestTop = newTop;

					const dxPx = ((newLeft - startLeft) / 100) * wrapRect.width;
					const dyPx = ((newTop - startTop) / 100) * wrapRect.height;

					boxEl.style.transform = `translate3d(${dxPx.toFixed(2)}px, ${dyPx.toFixed(2)}px, 0) rotate(${startRotDeg}deg)`;
					if (leftInput) leftInput.value = newLeft;
					if (topInput) topInput.value = newTop;
				};

				let dragRaf = null;
				let pendingX = startX;
				let pendingY = startY;

				$(document).on('mousemove.mrTeeDrag', function(moveEvent) {
					if (!isDragging) return;
					pendingX = moveEvent.clientX;
					pendingY = moveEvent.clientY;
					if (dragRaf) return;
					dragRaf = requestAnimationFrame(() => {
						dragRaf = null;
						if (!isDragging) return;
						applyDragDelta(pendingX, pendingY);
					});
				});

				$(document).on('mouseup.mrTeeDrag', function(upEvent) {
					if (!isDragging) return;
					isDragging = false;
					if (dragRaf) {
						cancelAnimationFrame(dragRaf);
						dragRaf = null;
						applyDragDelta(upEvent && upEvent.clientX !== undefined ? upEvent.clientX : pendingX, upEvent && upEvent.clientY !== undefined ? upEvent.clientY : pendingY);
					}
					$(document).off('.mrTeeDrag');

					boxEl.classList.remove('is-interacting');
					boxEl.style.left = `${latestLeft}%`;
					boxEl.style.top = `${latestTop}%`;
					boxEl.style.transform = `rotate(${startRotDeg}deg)`;
					if (leftInput) leftInput.value = latestLeft;
					if (topInput) topInput.value = latestTop;

					self.saveFieldPlacementForActiveView(currentIdx);
					self.recalculateDimensions();
				});
			});

			// Resizing active box via corner handles (zero DOM rebuilds / zero forced layout reads during drag)
			$(document).on('mousedown', '.mr-tee-field-box.is-active .mr-tee-handle:not(.mr-tee-handle-rot)', function(e) {
				e.preventDefault();
				e.stopPropagation();

				const $box = $(this).closest('.mr-tee-field-box');
				const boxEl = $box[0];
				if (!boxEl) return;

				currentIdx = parseInt(boxEl.getAttribute('data-field-index'), 10);
				currentHandle = $(this).data('handle') || '';

				const wrapRect = self.$canvasWrap[0].getBoundingClientRect();
				if (!wrapRect.width || !wrapRect.height) return;
				const stageRatio = self.getStageAspectRatio(wrapRect);

				const leftInput = document.getElementById(`mr_tee_field_left_${currentIdx}`);
				const topInput = document.getElementById(`mr_tee_field_top_${currentIdx}`);
				const widthInput = document.getElementById(`mr_tee_field_width_${currentIdx}`);
				const heightInput = document.getElementById(`mr_tee_field_height_${currentIdx}`);
				const rotInput = document.getElementById(`mr_tee_field_rotate_${currentIdx}`);
				const pillDimsEl = document.querySelector(`.mr-tee-field-pill[data-field-index="${currentIdx}"] .mr-tee-pill-dims`);

				isResizing = true;
				startX = e.clientX;
				startY = e.clientY;

				startLeft = leftInput ? (parseFloat(leftInput.value) || 0) : 0;
				startTop = topInput ? (parseFloat(topInput.value) || 0) : 0;
				startWidth = widthInput ? (parseFloat(widthInput.value) || 40.0) : 40.0;
				startHeight = heightInput ? (parseFloat(heightInput.value) || 8.5) : 8.5;

				const rotDeg = rotInput ? (parseFloat(rotInput.value) || 0) : 0;
				const rotTxt = rotDeg !== 0 ? ` (${rotDeg}°)` : '';

				let latestL = startLeft;
				let latestT = startTop;
				let latestW = startWidth;
				let latestH = startHeight;
				boxEl.classList.add('is-interacting');

				const applyResizeDelta = (clientX, clientY) => {
					const dx = ((clientX - startX) / wrapRect.width) * 100;
					const dy = ((clientY - startY) / wrapRect.height) * 100;

					const rightEdge = startLeft + startWidth;
					const bottomEdge = startTop + startHeight;

					let newW = startWidth;
					let newH = startHeight;
					let newL = startLeft;
					let newT = startTop;

					if (currentHandle.includes('e')) {
						newW = Math.max(5, Math.min(100 - startLeft, startWidth + dx));
					}
					if (currentHandle.includes('s')) {
						newH = Math.max(3, Math.min(100 - startTop, startHeight + dy));
					}
					if (currentHandle.includes('w')) {
						newL = Math.max(0, Math.min(Math.max(0, rightEdge - 5), startLeft + dx));
						newW = Math.max(5, rightEdge - newL);
					}
					if (currentHandle.includes('n')) {
						newT = Math.max(0, Math.min(Math.max(0, bottomEdge - 3), startTop + dy));
						newH = Math.max(3, bottomEdge - newT);
					}

					newW = Math.round(newW * 10) / 10;
					newH = Math.round(newH * 10) / 10;
					newL = Math.round(newL * 10) / 10;
					newT = Math.round(newT * 10) / 10;

					if (newL + newW > 100) newW = Math.max(5, Math.round((100 - newL) * 10) / 10);
					if (newT + newH > 100) newH = Math.max(3, Math.round((100 - newT) * 10) / 10);

					latestL = newL;
					latestT = newT;
					latestW = newW;
					latestH = newH;

					boxEl.style.left = `${newL}%`;
					boxEl.style.top = `${newT}%`;
					boxEl.style.width = `${newW}%`;
					boxEl.style.height = `${newH}%`;

					if (leftInput) leftInput.value = newL;
					if (topInput) topInput.value = newT;
					if (widthInput) widthInput.value = newW;
					if (heightInput) heightInput.value = newH;
					if (pillDimsEl) pillDimsEl.textContent = `${newW}×${newH}%${rotTxt}`;

					self.renderBoxPreview(currentIdx, { bw: newW, bh: newH, stageRatio });
				};

				let resizeRaf = null;
				let pendingX = startX;
				let pendingY = startY;

				$(document).on('mousemove.mrTeeResize', function(moveEvent) {
					if (!isResizing) return;
					pendingX = moveEvent.clientX;
					pendingY = moveEvent.clientY;
					if (resizeRaf) return;
					resizeRaf = requestAnimationFrame(() => {
						resizeRaf = null;
						if (!isResizing) return;
						applyResizeDelta(pendingX, pendingY);
					});
				});

				$(document).on('mouseup.mrTeeResize', function(upEvent) {
					if (!isResizing) return;
					isResizing = false;
					if (resizeRaf) {
						cancelAnimationFrame(resizeRaf);
						resizeRaf = null;
						applyResizeDelta(upEvent && upEvent.clientX !== undefined ? upEvent.clientX : pendingX, upEvent && upEvent.clientY !== undefined ? upEvent.clientY : pendingY);
					}
					$(document).off('.mrTeeResize');

					boxEl.classList.remove('is-interacting');
					self.saveFieldPlacementForActiveView(currentIdx);
					self.renderBoxPreview(currentIdx, { bw: latestW, bh: latestH, stageRatio });
					self.recalculateDimensions();
				});
			});

			// Rotating active box via top rotation handle
			$(document).on('mousedown', '.mr-tee-field-box.is-active .mr-tee-handle-rot', function(e) {
				e.preventDefault();
				e.stopPropagation();

				const $handle = $(this);
				const $box = $handle.closest('.mr-tee-field-box');
				const boxEl = $box[0];
				if (!boxEl) return;

				currentIdx = parseInt(boxEl.getAttribute('data-field-index'), 10);
				$handle.addClass('is-rotating');
				boxEl.classList.add('is-interacting');

				const rotInput = document.getElementById(`mr_tee_field_rotate_${currentIdx}`);
				const widthInput = document.getElementById(`mr_tee_field_width_${currentIdx}`);
				const heightInput = document.getElementById(`mr_tee_field_height_${currentIdx}`);
				const pillDimsEl = document.querySelector(`.mr-tee-field-pill[data-field-index="${currentIdx}"] .mr-tee-pill-dims`);
				const curW = widthInput ? (parseFloat(widthInput.value) || 40.0) : 40.0;
				const curH = heightInput ? (parseFloat(heightInput.value) || 8.5) : 8.5;

				const boxRect = boxEl.getBoundingClientRect();
				const centerX = (boxRect.left + boxRect.right) / 2;
				const centerY = (boxRect.top + boxRect.bottom) / 2;

				const applyRotateDelta = (clientX, clientY) => {
					const dx = clientX - centerX;
					const dy = clientY - centerY;

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
					if (rotInput) rotInput.value = deg;
					boxEl.style.transform = `rotate(${deg}deg)`;
					boxEl.style.transformOrigin = 'center center';
					if (pillDimsEl) {
						const rotTxt = deg !== 0 ? ` (${deg}°)` : '';
						pillDimsEl.textContent = `${curW}×${curH}%${rotTxt}`;
					}
				};

				let rotRaf = null;
				let pendingX = e.clientX;
				let pendingY = e.clientY;
				let isRotating = true;

				$(document).on('mousemove.mrTeeRot', function(moveEvent) {
					if (!isRotating) return;
					pendingX = moveEvent.clientX;
					pendingY = moveEvent.clientY;
					if (rotRaf) return;
					rotRaf = requestAnimationFrame(() => {
						rotRaf = null;
						if (!isRotating) return;
						applyRotateDelta(pendingX, pendingY);
					});
				});

				$(document).on('mouseup.mrTeeRot', function(upEvent) {
					if (!isRotating) return;
					isRotating = false;
					if (rotRaf) {
						cancelAnimationFrame(rotRaf);
						rotRaf = null;
						applyRotateDelta(upEvent && upEvent.clientX !== undefined ? upEvent.clientX : pendingX, upEvent && upEvent.clientY !== undefined ? upEvent.clientY : pendingY);
					}
					$handle.removeClass('is-rotating');
					boxEl.classList.remove('is-interacting');
					$(document).off('.mrTeeRot');

					self.saveFieldPlacementForActiveView(currentIdx);
					self.recalculateDimensions();
				});
			});
		}

		updateBoxPosition(idx, left, top) {
			$(`#mr_tee_field_left_${idx}`).val(left);
			$(`#mr_tee_field_top_${idx}`).val(top);
			$(`#mr-tee-field-box-${idx}`).css({ left: left + '%', top: top + '%' });
			this.saveFieldPlacementForActiveView(idx);
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

			this.saveFieldPlacementForActiveView(idx);
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

			this.saveFieldPlacementForActiveView(idx);

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
				self.saveFieldPlacementForActiveView(idx);
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
				const prefix = self.getFieldType(idx) === 'image' ? '🖼️ ' : '';
				$(`.mr-tee-field-pill[data-field-index="${idx}"] .mr-tee-pill-label`).text(val);
				$(`#mr-tee-field-box-${idx} .mr-tee-box-badge`).text(`${idx + 1}. ${prefix}${val}`);
				$(`#mr-tee-field-panel-${idx} .mr-tee-panel-title`).text(`Champ #${idx + 1} : ${val}`);
			});

			// Field Type Switcher (Text <-> Image)
			$(document).on('change', '.mr-tee-field-type-select', function() {
				const idx = getFieldIdx($(this));
				const newType = $(this).val() === 'image' ? 'image' : 'text';
				const isImage = newType === 'image';
				const $panel = $(`#mr-tee-field-panel-${idx}`);
				const $box = $(`#mr-tee-field-box-${idx}`);
				const $pill = $(`.mr-tee-field-pill[data-field-index="${idx}"]`);

				$panel.attr('data-field-type', newType).toggleClass('is-image-panel', isImage);
				$box.attr('data-field-type', newType).toggleClass('is-image-field', isImage);
				$pill.attr('data-field-type', newType).toggleClass('is-image-pill', isImage);

				$panel.find('.mr-tee-row-text-only, .mr-tee-opt-text-only').toggle(!isImage);
				$panel.find('.mr-tee-row-image-only, .mr-tee-opt-image-only').toggle(isImage);

				// Update pill icon & box badge
				$pill.find('.mr-tee-pill-type-icon').remove();
				if (isImage) {
					$pill.find('.mr-tee-pill-badge').after('<span class="mr-tee-pill-type-icon" aria-hidden="true">🖼️</span>');
				}
				const labelVal = $panel.find('.mr-tee-field-label-input').val() || `Champ ${idx + 1}`;
				$box.find('.mr-tee-box-badge').text(`${idx + 1}. ${isImage ? '🖼️ ' : ''}${labelVal}`);

				// Give image fields a practical initial height if switching from a thin 1-line text box
				if (isImage) {
					const curTop = parseFloat($(`#mr_tee_field_top_${idx}`).val()) || 25.0;
					const curW = parseFloat($(`#mr_tee_field_width_${idx}`).val()) || 35.0;
					const curH = parseFloat($(`#mr_tee_field_height_${idx}`).val()) || 8.5;
					if (curH < 15.0) {
						const nextH = Math.max(15.0, Math.min(100 - curTop, 30.0));
						const curL = parseFloat($(`#mr_tee_field_left_${idx}`).val()) || 30.0;
						self.updateBoxBounds(idx, curL, curTop, curW, Math.round(nextH * 10) / 10);
						return;
					}
				}

				self.renderBoxPreview(idx);
				self.recalculateDimensions();
			});

			// Default Image URL input & Fit Mode
			$(document).on('input change', '.mr-tee-field-default-image-input', function() {
				const idx = getFieldIdx($(this));
				const url = ($(this).val() || '').trim();
				self.syncDefaultImagePreviewUi(idx, url);
				self.renderBoxPreview(idx);
			});

			$(document).on('change', '.mr-tee-field-image-fit', function() {
				const idx = getFieldIdx($(this));
				self.renderBoxPreview(idx);
			});

			// Clear Default Image
			$(document).on('click', '.mr-tee-btn-clear-default-image', function(e) {
				e.preventDefault();
				const idx = getFieldIdx($(this));
				const $panel = $(`#mr-tee-field-panel-${idx}`);
				$panel.find('.mr-tee-field-default-image-input').val('');
				$panel.find('.mr-tee-field-default-image-id').val('0');
				self.syncDefaultImagePreviewUi(idx, '');
				self.renderBoxPreview(idx);
			});

			// Open WordPress Media Library picker for Default Image
			$(document).on('click', '.mr-tee-btn-select-default-image', function(e) {
				e.preventDefault();
				const idx = getFieldIdx($(this));
				const $panel = $(`#mr-tee-field-panel-${idx}`);
				if (!window.wp || !window.wp.media) {
					$panel.find('.mr-tee-quick-image-file').trigger('click');
					return;
				}
				const frame = window.wp.media({
					title: 'Choisir une image par défaut',
					button: { text: 'Utiliser cette image' },
					library: { type: 'image' },
					multiple: false,
				});
				frame.on('select', function() {
					const att = frame.state().get('selection').first().toJSON();
					const url = (att && att.url) ? att.url : '';
					const attId = (att && att.id) ? att.id : 0;
					$panel.find('.mr-tee-field-default-image-id').val(attId);
					$panel.find('.mr-tee-field-default-image-input').val(url);
					self.syncDefaultImagePreviewUi(idx, url);
					self.renderBoxPreview(idx);
				});
				frame.open();
			});

			// Direct File Upload Fallback for Default Image
			$(document).on('change', '.mr-tee-quick-image-file', function() {
				const inputEl = this;
				const file = inputEl.files && inputEl.files[0];
				if (!file) return;
				const idx = getFieldIdx($(this));
				const $panel = $(`#mr-tee-field-panel-${idx}`);
				const $status = $panel.find('.mr-tee-img-upload-status');
				$status.text('⏳ Envoi de l\'image...').css('color', '#2563eb');

				const formData = new FormData();
				formData.append('action', 'mr_tee_upload_default_image');
				formData.append('nonce', window.mrTeeAdminData ? window.mrTeeAdminData.nonce : '');
				formData.append('product_id', window.mrTeeAdminData ? (window.mrTeeAdminData.productId || 0) : 0);
				formData.append('image_file', file);

				const ajaxUrl = self.toSameOriginPath((window.mrTeeAdminData && window.mrTeeAdminData.ajaxUrl) || '/wp-admin/admin-ajax.php');
				$.ajax({
					url: ajaxUrl,
					type: 'POST',
					data: formData,
					processData: false,
					contentType: false,
					success: function(resp) {
						inputEl.value = '';
						if (resp && resp.success && resp.data && resp.data.url) {
							$panel.find('.mr-tee-field-default-image-id').val(resp.data.id || 0);
							$panel.find('.mr-tee-field-default-image-input').val(resp.data.url);
							self.syncDefaultImagePreviewUi(idx, resp.data.url);
							self.renderBoxPreview(idx);
							$status.text('✅ Image définie !').css('color', '#059669');
						} else {
							const msg = (resp && resp.data && resp.data.message) ? resp.data.message : 'Erreur lors de l\'import.';
							$status.text(`❌ ${msg}`).css('color', '#dc2626');
						}
					},
					error: function(xhr, status, err) {
						inputEl.value = '';
						$status.text(`❌ Erreur réseau : ${err}`).css('color', '#dc2626');
					}
				});
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
				const firstTextIdx = self.getFirstTextFieldIndex();
				if (idx === firstTextIdx && newFontId && self.allowedFonts.includes(newFontId) && self.allowedFonts[0] !== newFontId) {
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

		getFieldType(idx) {
			const $sel = $(`#mr-tee-field-panel-${idx} .mr-tee-field-type-select`);
			if ($sel.length) {
				return $sel.val() === 'image' ? 'image' : 'text';
			}
			return $(`#mr-tee-field-panel-${idx}`).attr('data-field-type') === 'image' ? 'image' : 'text';
		}

		syncDefaultImagePreviewUi(idx, url) {
			const $panel = $(`#mr-tee-field-panel-${idx}`);
			const $wrap = $panel.find('.mr-tee-default-img-preview-wrap');
			const $clearBtn = $panel.find('.mr-tee-btn-clear-default-image');
			if (url) {
				$wrap.html(`<img src="${this.escapeXml(url)}" alt="" class="mr-tee-default-img-preview" />`);
				$clearBtn.show();
			} else {
				$wrap.html('<span class="mr-tee-default-img-empty">Aucune image par défaut</span>');
				$clearBtn.hide();
			}
		}

		/**
		 * Add New Field Rectangle (Text or Image).
		 */
		bindAddField() {
			const self = this;
			$(document).on('click', '#mr-tee-btn-add-field-pill, #mr-tee-btn-add-field', function() {
				self.addField('text');
			});
			$(document).on('click', '#mr-tee-btn-add-image-field-pill, #mr-tee-btn-add-image-field', function() {
				self.addField('image');
			});
		}

		addField(fieldType = 'text') {
			const isImage = fieldType === 'image';
			const newIdx = $('.mr-tee-field-box').length;
			const color = FIELD_COLORS[newIdx % FIELD_COLORS.length];
			const activeSideId = this.getActiveSideId();
			const safeSideId = this.escapeXml(activeSideId);

			this._uidCounter = (this._uidCounter || 0) + 1;
			const uid = 'f' + Date.now().toString(36) + '_' + this._uidCounter + '_' + Math.random().toString(36).slice(2, 5);
			const fieldId = `line_${uid}`;
			const refId = `id-ref-${isImage ? 'image' : 'text'}-${uid}`;
			const colorRefId = `id-ref-color-${uid}`;

			// Stack below the last field on the SAME printable side (or start at 25% on a fresh side)
			let lastSameSideIdx = -1;
			for (let i = newIdx - 1; i >= 0; i--) {
				if (this.getFieldSideId(i) === activeSideId) {
					lastSameSideIdx = i;
					break;
				}
			}

			const newWidth = isImage ? 35.0 : 40.0;
			const newHeight = isImage ? 30.0 : 8.5;
			const newLeft = isImage ? 32.5 : 30.0;
			const maxTop = isImage ? Math.max(5.0, 96.0 - newHeight) : 88.0;
			let newTop = 25.0;
			if (lastSameSideIdx >= 0) {
				const prevTop = parseFloat($(`#mr_tee_field_top_${lastSameSideIdx}`).val()) || 25.0;
				const prevHeight = parseFloat($(`#mr_tee_field_height_${lastSameSideIdx}`).val()) || 8.5;
				newTop = Math.min(maxTop, Math.round((prevTop + prevHeight + 2.0) * 10) / 10);
			}

			const fieldLabel = isImage ? `Image ${newIdx + 1}` : `Texte ${newIdx + 1}`;
			const defaultText = isImage ? '' : `Ligne ${newIdx + 1}`;

			const allowedFonts = this.getAllowedFonts();
			const defaultFontId = allowedFonts[0] || '';
			const fontSelectOptions = this.generateFontSelectOptions(defaultFontId, allowedFonts);
			const sideSelectOptions = this.generateSideSelectOptions(activeSideId);

			// Seed placements for all views belonging to activeSideId
			const initialPlacements = {};
			const seedCoords = {
				box_left: newLeft,
				box_top: newTop,
				box_width: newWidth,
				box_height: newHeight,
				box_rotate: 0,
			};
			this.previewViews.forEach(v => {
				if (v && v.side_id === activeSideId) {
					initialPlacements[v.id] = { ...seedCoords };
				}
			});
			if (this.activeViewId && !initialPlacements[this.activeViewId]) {
				initialPlacements[this.activeViewId] = { ...seedCoords };
			}
			const placementsJsonAttr = this.escapeXml(JSON.stringify(initialPlacements));

			// 1. Add Pill
			const pillHtml = `
				<button
					type="button"
					class="mr-tee-field-pill ${isImage ? 'is-image-pill' : ''}"
					data-field-index="${newIdx}"
					data-field-type="${fieldType}"
					data-side-id="${safeSideId}"
					style="--pill-color: ${color};"
				>
					<span class="mr-tee-pill-badge" style="background: ${color};">${newIdx + 1}</span>
					${isImage ? '<span class="mr-tee-pill-type-icon" aria-hidden="true">🖼️</span>' : ''}
					<span class="mr-tee-pill-label">${fieldLabel}</span>
					<span class="mr-tee-pill-dims">${newWidth}×${newHeight}%</span>
				</button>
			`;
			$('#mr-tee-pills-list').append(pillHtml);

			// 2. Add Box
			const boxHtml = `
				<div
					id="mr-tee-field-box-${newIdx}"
					class="mr-tee-field-box ${isImage ? 'is-image-field' : ''}"
					data-field-index="${newIdx}"
					data-field-type="${fieldType}"
					data-side-id="${safeSideId}"
					style="left: ${newLeft}%; top: ${newTop}%; width: ${newWidth}%; height: ${newHeight}%; transform: rotate(0deg); transform-origin: center center; --field-color: ${color};"
				>
					<span class="mr-tee-box-badge" style="background: ${color};">
						${newIdx + 1}. ${isImage ? '🖼️ ' : ''}${fieldLabel}
					</span>
					<div class="mr-tee-box-svg-holder" id="mr-tee-box-svg-${newIdx}"></div>
					<div class="mr-tee-handle-rot-stem" style="background: ${color};"></div>
					<div class="mr-tee-handle-rot" data-handle="rot" style="border-color: ${color};" title="Faire pivoter la zone"></div>
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
					class="mr-tee-field-panel ${isImage ? 'is-image-panel' : ''}"
					id="mr-tee-field-panel-${newIdx}"
					data-field-index="${newIdx}"
					data-field-type="${fieldType}"
					data-side-id="${safeSideId}"
				>
					<input type="hidden" name="mr_tee_config[text_lines][${newIdx}][id]" value="${fieldId}" class="mr-tee-field-id" data-field-index="${newIdx}" />
					<input type="hidden" name="mr_tee_config[text_lines][${newIdx}][ref_id]" value="${refId}" class="mr-tee-field-ref-id" data-field-index="${newIdx}" />
					<input type="hidden" name="mr_tee_config[text_lines][${newIdx}][color_ref_id]" value="${colorRefId}" class="mr-tee-field-color-ref-id" data-field-index="${newIdx}" />
					<input type="hidden" id="mr_tee_field_placements_${newIdx}" name="mr_tee_config[text_lines][${newIdx}][placements]" value="${placementsJsonAttr}" class="mr-tee-field-placements-json" data-field-index="${newIdx}" />
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
							<th><label>Face d'Impression</label></th>
							<td>
								<select name="mr_tee_config[text_lines][${newIdx}][side_id]" class="mr-tee-field-side-select" data-field-index="${newIdx}">
									${sideSelectOptions}
								</select>
							</td>
						</tr>
						<tr>
							<th><label>Type de Champ</label></th>
							<td>
								<select name="mr_tee_config[text_lines][${newIdx}][field_type]" class="mr-tee-field-type-select" data-field-index="${newIdx}">
									<option value="text" ${!isImage ? 'selected' : ''}>📝 Champ Texte</option>
									<option value="image" ${isImage ? 'selected' : ''}>🖼️ Champ Image (Strictement dans le rectangle)</option>
								</select>
							</td>
						</tr>
						<tr class="mr-tee-row-image-only" ${isImage ? '' : 'style="display:none;"'}>
							<th><label>Image par Défaut</label></th>
							<td>
								<div class="mr-tee-default-img-picker" data-field-index="${newIdx}">
									<div class="mr-tee-default-img-preview-wrap">
										<span class="mr-tee-default-img-empty">Aucune image par défaut</span>
									</div>
									<input type="hidden" name="mr_tee_config[text_lines][${newIdx}][default_image_id]" value="0" class="mr-tee-field-default-image-id" data-field-index="${newIdx}" />
									<input type="url" name="mr_tee_config[text_lines][${newIdx}][default_image]" value="" placeholder="https://..." class="regular-text mr-tee-field-default-image-input" data-field-index="${newIdx}" style="margin-bottom:6px; width:100%;" />
									<div class="mr-tee-default-img-actions">
										<button type="button" class="button button-secondary button-small mr-tee-btn-select-default-image" data-field-index="${newIdx}">📁 Bibliothèque WP</button>
										<label class="button button-small" style="cursor:pointer; display:inline-flex; align-items:center; gap:4px;">
											<span>⬆️ Importer fichier</span>
											<input type="file" accept="image/*" class="mr-tee-quick-image-file" style="display:none;" data-field-index="${newIdx}" />
										</label>
										<button type="button" class="button button-small mr-tee-btn-clear-default-image" data-field-index="${newIdx}" style="display:none;">✕ Retirer</button>
									</div>
									<span class="mr-tee-img-upload-status" style="display:block; font-size:11px; margin-top:4px; color:#64748b;"></span>
								</div>
							</td>
						</tr>
						<tr class="mr-tee-row-image-only" ${isImage ? '' : 'style="display:none;"'}>
							<th><label>Ajustement dans le rectangle</label></th>
							<td>
								<select name="mr_tee_config[text_lines][${newIdx}][image_fit]" class="mr-tee-field-image-fit" data-field-index="${newIdx}">
									<option value="contain" selected>Ajuster entier (Contain — sans rogner)</option>
									<option value="cover">Remplir le rectangle (Cover — centré &amp; recadré)</option>
								</select>
							</td>
						</tr>
						<tr class="mr-tee-row-text-only" ${isImage ? 'style="display:none;"' : ''}>
							<th><label>Texte par Défaut</label></th>
							<td>
								<input type="text" name="mr_tee_config[text_lines][${newIdx}][default_text]" value="${defaultText}" class="regular-text mr-tee-field-text-input" data-field-index="${newIdx}" />
							</td>
						</tr>
						<tr class="mr-tee-row-text-only" ${isImage ? 'style="display:none;"' : ''}>
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
						<tr class="mr-tee-row-text-only" ${isImage ? 'style="display:none;"' : ''}>
							<th><label>Mode Couleur</label></th>
							<td>
								<select name="mr_tee_config[text_lines][${newIdx}][color_mode]" class="mr-tee-field-color-mode" data-field-index="${newIdx}">
									<option value="multi">Multicolore (COLOR_SET cyclique)</option>
									<option value="solid">Couleur Unie</option>
								</select>
							</td>
						</tr>
						<tr class="mr-tee-row-text-only" ${isImage ? 'style="display:none;"' : ''}>
							<th><label>Max Caractères</label></th>
							<td>
								<input type="number" min="1" max="100" name="mr_tee_config[text_lines][${newIdx}][max_chars]" value="25" class="small-text mr-tee-field-max-chars" data-field-index="${newIdx}" />
							</td>
						</tr>
						<tr>
							<th><label>Options</label></th>
							<td>
								<span class="mr-tee-opt-text-only" ${isImage ? 'style="display:none;"' : ''}>
									<input type="hidden" name="mr_tee_config[text_lines][${newIdx}][allow_font_pick]" value="0" />
									<label><input type="checkbox" name="mr_tee_config[text_lines][${newIdx}][allow_font_pick]" value="1" checked class="mr-tee-field-font-pick" data-field-index="${newIdx}" /> Choix de police client</label><br />
									<label><input type="checkbox" name="mr_tee_config[text_lines][${newIdx}][multiline]" value="1" class="mr-tee-field-multiline" data-field-index="${newIdx}" /> Autoriser retours à la ligne</label><br />
								</span>
								<span class="mr-tee-opt-image-only" ${isImage ? '' : 'style="display:none;"'}>
									<input type="hidden" name="mr_tee_config[text_lines][${newIdx}][allow_image_upload]" value="0" />
									<label><input type="checkbox" name="mr_tee_config[text_lines][${newIdx}][allow_image_upload]" value="1" checked class="mr-tee-field-allow-image-upload" data-field-index="${newIdx}" /> Autoriser le client à changer/importer une image</label><br />
								</span>
								<input type="hidden" name="mr_tee_config[text_lines][${newIdx}][warning_unedited]" value="0" />
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

			// Activate new field & refresh side counters
			this.updateEmptySideNotice(activeSideId, false);
			this.renderSidesBar();
			this.setActiveField(newIdx);
			this.renderBoxPreview(newIdx);
			this.recalculateDimensions();
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

				if (!confirm('Voulez-vous vraiment supprimer ce rectangle et son champ ?')) {
					return;
				}

				// Remove elements
				$(`.mr-tee-field-pill[data-field-index="${idx}"]`).remove();
				$(`#mr-tee-field-box-${idx}`).remove();
				$(`#mr-tee-field-panel-${idx}`).remove();

				// Re-index remaining elements
				self.reindexFields();
				const remaining = $('.mr-tee-field-box').length;
				const activeSideId = self.getActiveSideId();
				let nextActiveIdx = -1;
				for (let i = 0; i < remaining; i++) {
					if (self.getFieldSideId(i) === activeSideId) {
						nextActiveIdx = i;
						if (i >= idx) break;
					}
				}
				self.renderSidesBar();
				if (nextActiveIdx !== -1) {
					self.updateEmptySideNotice(activeSideId, false);
					self.setActiveField(nextActiveIdx);
				} else {
					self.updateEmptySideNotice(activeSideId, true);
					$('.mr-tee-field-box, .mr-tee-field-pill, .mr-tee-field-panel').removeClass('is-active');
				}
				self.renderAllBoxesPreviews();
				self.recalculateDimensions();
			});
		}

		reindexFields() {
			const self = this;
			const $pills = $('.mr-tee-field-pill');
			const $boxes = $('.mr-tee-field-box');
			const $panels = $('.mr-tee-field-panel');

			$panels.each(function(i) {
				const color = FIELD_COLORS[i % FIELD_COLORS.length];
				const labelVal = $(this).find('.mr-tee-field-label-input').val() || `Champ ${i + 1}`;
				const sideVal = $(this).find('.mr-tee-field-side-select').val()
					|| $(this).attr('data-side-id')
					|| (self.printSides[0] ? self.printSides[0].id : 'front');
				$(this).attr('id', `mr-tee-field-panel-${i}`);
				$(this).attr('data-field-index', i);
				$(this).attr('data-side-id', sideVal);
				$(this).find('.mr-tee-field-id').attr('name', `mr_tee_config[text_lines][${i}][id]`).attr('data-field-index', i);
				$(this).find('.mr-tee-field-ref-id').attr('name', `mr_tee_config[text_lines][${i}][ref_id]`).attr('data-field-index', i);
				$(this).find('.mr-tee-field-color-ref-id').attr('name', `mr_tee_config[text_lines][${i}][color_ref_id]`).attr('data-field-index', i);
				$(this).find('.mr-tee-field-placements-json')
					.attr('id', `mr_tee_field_placements_${i}`)
					.attr('name', `mr_tee_config[text_lines][${i}][placements]`)
					.attr('data-field-index', i);
				$(this).find('.mr-tee-field-side-select')
					.attr('name', `mr_tee_config[text_lines][${i}][side_id]`)
					.attr('data-field-index', i);
				$(this).find('.mr-tee-panel-indicator').css('background', color);
				$(this).find('.mr-tee-panel-title').text(`Champ #${i + 1} : ${labelVal}`);
				$(this).find('.mr-tee-field-label-input').attr('name', `mr_tee_config[text_lines][${i}][label]`).attr('data-field-index', i);
				$(this).find('.mr-tee-field-type-select').attr('name', `mr_tee_config[text_lines][${i}][field_type]`).attr('data-field-index', i);
				$(this).find('.mr-tee-default-img-picker').attr('data-field-index', i);
				$(this).find('.mr-tee-field-default-image-id').attr('name', `mr_tee_config[text_lines][${i}][default_image_id]`).attr('data-field-index', i);
				$(this).find('.mr-tee-field-default-image-input').attr('name', `mr_tee_config[text_lines][${i}][default_image]`).attr('data-field-index', i);
				$(this).find('.mr-tee-btn-select-default-image, .mr-tee-quick-image-file, .mr-tee-btn-clear-default-image').attr('data-field-index', i);
				$(this).find('.mr-tee-field-image-fit').attr('name', `mr_tee_config[text_lines][${i}][image_fit]`).attr('data-field-index', i);
				$(this).find('.mr-tee-field-text-input').attr('name', `mr_tee_config[text_lines][${i}][default_text]`).attr('data-field-index', i);
				$(this).find('.mr-tee-field-font-select').attr('name', `mr_tee_config[text_lines][${i}][font]`).attr('data-field-index', i);
				$(this).find('.mr-tee-quick-font-file').attr('data-field-index', i);
				$(this).find('.mr-tee-field-color-mode').attr('name', `mr_tee_config[text_lines][${i}][color_mode]`).attr('data-field-index', i);
				$(this).find('.mr-tee-field-max-chars').attr('name', `mr_tee_config[text_lines][${i}][max_chars]`).attr('data-field-index', i);
				$(this).find('input[type="hidden"][name*="[allow_font_pick]"]').attr('name', `mr_tee_config[text_lines][${i}][allow_font_pick]`);
				$(this).find('.mr-tee-field-font-pick').attr('name', `mr_tee_config[text_lines][${i}][allow_font_pick]`).attr('data-field-index', i);
				$(this).find('.mr-tee-field-multiline').attr('name', `mr_tee_config[text_lines][${i}][multiline]`).attr('data-field-index', i);
				$(this).find('input[type="hidden"][name*="[allow_image_upload]"]').attr('name', `mr_tee_config[text_lines][${i}][allow_image_upload]`);
				$(this).find('.mr-tee-field-allow-image-upload').attr('name', `mr_tee_config[text_lines][${i}][allow_image_upload]`).attr('data-field-index', i);
				$(this).find('input[type="hidden"][name*="[warning_unedited]"]').attr('name', `mr_tee_config[text_lines][${i}][warning_unedited]`);
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
				const sideVal = $(`#mr-tee-field-panel-${i}`).attr('data-side-id') || $(this).attr('data-side-id') || 'front';
				$(this).attr('data-field-index', i);
				$(this).attr('data-side-id', sideVal);
				$(this).css('--pill-color', color);
				$(this).find('.mr-tee-pill-badge').text(i + 1).css('background', color);
			});

			$boxes.each(function(i) {
				const color = FIELD_COLORS[i % FIELD_COLORS.length];
				const $panel = $(`#mr-tee-field-panel-${i}`);
				const isImg = $panel.find('.mr-tee-field-type-select').val() === 'image' || $panel.attr('data-field-type') === 'image';
				const labelVal = $panel.find('.mr-tee-field-label-input').val() || `Champ ${i + 1}`;
				const sideVal = $panel.attr('data-side-id') || $(this).attr('data-side-id') || 'front';
				$(this).attr('id', `mr-tee-field-box-${i}`);
				$(this).attr('data-field-index', i);
				$(this).attr('data-side-id', sideVal);
				$(this).css('--field-color', color);
				$(this).find('.mr-tee-box-badge').text(`${i + 1}. ${isImg ? '🖼️ ' : ''}${labelVal}`).css('background', color);
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
		 * Render live SVG inside each rectangle box visible on the active side.
		 */
		renderAllBoxesPreviews() {
			const count = $('.mr-tee-field-box').length;
			for (let i = 0; i < count; i++) {
				const $box = $(`#mr-tee-field-box-${i}`);
				if ($box.hasClass('is-other-side')) continue;
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

		getStageAspectRatio(cachedWrapRect) {
			if (cachedWrapRect && cachedWrapRect.width > 0 && cachedWrapRect.height > 0) {
				this._cachedStageRatio = cachedWrapRect.width / cachedWrapRect.height;
				return this._cachedStageRatio;
			}
			if (this._cachedStageRatio && this._cachedStageRatio > 0) {
				return this._cachedStageRatio;
			}
			const wrapEl = this.$canvasWrap && this.$canvasWrap[0];
			if (wrapEl && wrapEl.clientWidth > 0 && wrapEl.clientHeight > 0) {
				this._cachedStageRatio = wrapEl.clientWidth / wrapEl.clientHeight;
				return this._cachedStageRatio;
			}
			const rw = parseFloat($('#mr_tee_real_width').val()) || 30;
			const rh = parseFloat($('#mr_tee_real_height').val()) || 40;
			return rh > 0 ? (rw / rh) : 0.75;
		}

		renderBoxPreview(idx, opts = {}) {
			const el = document.getElementById(`mr-tee-box-svg-${idx}`);
			if (!el) return;

			// Image field branch: render native GPU-scaled <img> strictly clipped inside the box rectangle (ZERO DOM rebuilds on resize!)
			if (this.getFieldType(idx) === 'image') {
				const rawImgUrl = ($(`[name="mr_tee_config[text_lines][${idx}][default_image]"]`).val() || '').trim();
				const imgUrl = this.normalizeAdminAssetUrl(rawImgUrl);
				const imgFit = $(`[name="mr_tee_config[text_lines][${idx}][image_fit]"]`).val() === 'cover' ? 'cover' : 'contain';

				if (!imgUrl) {
					if (el._renderMode !== 'img-empty') {
						el.innerHTML = '<div class="mr-tee-admin-img-placeholder"><span>🖼️ Zone Image</span><small>Choisissez une image par défaut</small></div>';
						el._renderMode = 'img-empty';
						el._imgEl = null;
						el._svgEl = null;
						el._groupEl = null;
					}
					return;
				}

				if (el._renderMode === 'img' && el._imgEl) {
					if (el._imgUrl !== imgUrl) {
						el._imgEl.src = imgUrl;
						el._imgUrl = imgUrl;
					}
					if (el._imgFit !== imgFit) {
						el._imgEl.style.objectFit = imgFit;
						el._imgFit = imgFit;
					}
					return;
				}

				const safeUrl = this.escapeXml(imgUrl);
				el.innerHTML = `<img class="mr-tee-admin-box-img" src="${safeUrl}" alt="" decoding="async" draggable="false" style="width:100%;height:100%;object-fit:${imgFit};display:block;pointer-events:none;user-select:none;" />`;
				el._renderMode = 'img';
				el._imgUrl = imgUrl;
				el._imgFit = imgFit;
				el._imgEl = el.querySelector('.mr-tee-admin-box-img');
				el._svgEl = null;
				el._groupEl = null;
				return;
			}

			// Compute box visual aspect ratio from logical % inputs + cached stage ratio to avoid forced synchronous layout reads
			const bw = (opts && opts.bw > 0) ? opts.bw : (parseFloat((document.getElementById(`mr_tee_field_width_${idx}`) || {}).value) || 40);
			const bh = (opts && opts.bh > 0) ? opts.bh : (parseFloat((document.getElementById(`mr_tee_field_height_${idx}`) || {}).value) || 8.5);
			const stageRatio = (opts && opts.stageRatio > 0) ? opts.stageRatio : this.getStageAspectRatio();
			let boxRatio = (bw / Math.max(0.5, bh)) * stageRatio;
			boxRatio = Math.max(0.2, Math.min(20, boxRatio || 1));
			const vbH = 100;
			const vbW = Number((vbH * boxRatio).toFixed(2));

			const rawTextVal = $(`[name="mr_tee_config[text_lines][${idx}][default_text]"]`).val();
			const textVal = (rawTextVal !== undefined && String(rawTextVal).trim() !== '') ? String(rawTextVal) : `Texte ${idx + 1}`;

			const fonts = (window.mrTeeAdminData && window.mrTeeAdminData.fonts) || {};
			const allowed = this.getAllowedFonts();
			const fallbackKey = allowed[0] || Object.keys(fonts)[0] || 'super_mario';
			const fontId = $(`select[name="mr_tee_config[text_lines][${idx}][font]"]`).val() || fallbackKey;
			const fontDef = fonts[fontId] || fonts[fallbackKey] || null;

			// Handle OpenType-SVG Vector Color Fonts
			if (this.isSvgFont(fontDef)) {
				const svgFonts = (window.mrTeeAdminData && window.mrTeeAdminData.svgFonts) || {};
				const svgData = svgFonts[fontId];

				if (svgData && svgData.glyphs) {
					const casing = fontDef.uppercase ? 'UPPER' : 'ORIGINAL';
					const displayVal = (casing === 'UPPER') ? textVal.toUpperCase() : textVal;
					const glyphsKey = `otsvg::${fontId}::${displayVal}`;

					// O(1) Resize Fast-Path: if text & font are unchanged, update ONLY viewBox and outer <g> transform (2 setAttribute calls, 0 innerHTML parse!)
					if (el._renderMode === 'otsvg' && el._glyphsKey === glyphsKey && el._svgEl && el._groupEl) {
						if (el._lastVbW !== vbW) {
							const maxDesignW = vbW * 0.92;
							const autoScale = (el._rawW > maxDesignW && maxDesignW > 0) ? (maxDesignW / el._rawW) : 1.0;
							el._svgEl.setAttribute('viewBox', `0 0 ${vbW} ${vbH}`);
							el._groupEl.setAttribute('transform', `translate(${(vbW / 2).toFixed(2)}, 50) scale(${autoScale.toFixed(5)})`);
							el._lastVbW = vbW;
						}
						return;
					}

					const unitsPerEm = svgData.unitsPerEm || 1000;
					const lines = displayVal.split(/\r?\n/);
					const numLines = Math.max(1, lines.length);
					const lineHeightRatio = 0.92;
					const letterSpacingUnits = (fontDef.letter_spacing !== undefined ? fontDef.letter_spacing : 0.05) * unitsPerEm;

					const resolveAdminGlyph = (ch) => {
						const isSpace = /^\s$/.test(ch);
						const cands = [ch, ch.toUpperCase(), ch.toLowerCase()];
						if (typeof ch.normalize === 'function') {
							const base = ch.normalize('NFD').replace(/[\u0300-\u036f]/g, '');
							if (base && base !== ch) cands.push(base, base.toUpperCase(), base.toLowerCase());
						}
						let firstHit = null;
						for (const k of cands) {
							const entry = k ? svgData.glyphs[k] : null;
							if (entry) {
								if (!firstHit) firstHit = entry;
								if ((entry.svg && entry.svg.trim() !== '') || isSpace) {
									return {
										svg: entry.svg || '',
										hasSvg: Boolean(entry.svg && entry.svg.trim() !== ''),
										isSpace,
										adv: (entry.adv > 0) ? entry.adv : (isSpace ? Math.round(unitsPerEm * 0.32) : (svgData.defaultAdv || 500)),
									};
								}
							}
						}
						let ratio = 0.58;
						if (/^[!.,:;'’‘|`1Iil]$/.test(ch)) {
							ratio = 0.42;
						} else if (/^[&?%@MWmw#]$/.test(ch)) {
							ratio = 0.66;
						}
						return {
							svg: '',
							hasSvg: false,
							isSpace,
							adv: isSpace ? ((firstHit && firstHit.adv > 0) ? firstHit.adv : Math.round(unitsPerEm * 0.32)) : Math.round(unitsPerEm * ratio),
						};
					};

					const lineWidths = [];
					let maxWidthUnits = 0;
					for (const lineStr of lines) {
						let wUnits = 0;
						for (let i = 0; i < lineStr.length; i++) {
							const ch = lineStr[i];
							const g = resolveAdminGlyph(ch);
							wUnits += g.adv + (i < lineStr.length - 1 ? letterSpacingUnits : 0);
						}
						lineWidths.push(wUnits);
						if (wUnits > maxWidthUnits) maxWidthUnits = wUnits;
					}

					const maxDesignW = vbW * 0.92;
					const baseFontSize = (vbH * 0.84) / (1 + (numLines - 1) * lineHeightRatio);
					const rawW = (maxWidthUnits / unitsPerEm) * baseFontSize;
					const autoScale = (rawW > maxDesignW && maxDesignW > 0) ? (maxDesignW / rawW) : 1.0;
					const baseScale = baseFontSize / unitsPerEm;
					const baseStepY = baseFontSize * lineHeightRatio;
					const baseStartCenterY = -(((numLines - 1) * baseStepY) / 2);
					const fbFontSize = Math.round(unitsPerEm * 0.78);
					const fbStrokeWidth = Math.round(unitsPerEm * 0.11);
					const baselineOffsetUnits = this.getOpenTypeSvgBaselineOffset(svgData);

					let glyphsMarkup = '';
					for (let li = 0; li < lines.length; li++) {
						const lineStr = lines[li];
						const baseLineW = (lineWidths[li] / unitsPerEm) * baseFontSize;
						let cursorX = -(baseLineW / 2);
						const lineCenterY = baseStartCenterY + (li * baseStepY);
						const baselineY = lineCenterY + (baselineOffsetUnits * baseScale);

						for (let i = 0; i < lineStr.length; i++) {
							const ch = lineStr[i];
							const g = resolveAdminGlyph(ch);
							if (g.hasSvg) {
								glyphsMarkup += `<g class="mrtee-ot-glyph" transform="translate(${cursorX.toFixed(3)}, ${baselineY.toFixed(3)}) scale(${baseScale.toFixed(5)})">${g.svg}</g>\n`;
							} else if (!g.isSpace) {
								const escapedCh = this.escapeXml(ch);
								const fbCenterX = (g.adv / 2).toFixed(1);
								glyphsMarkup += `<g class="mrtee-ot-glyph mrtee-ot-glyph-fallback" transform="translate(${cursorX.toFixed(3)}, ${baselineY.toFixed(3)}) scale(${baseScale.toFixed(5)})"><text x="${fbCenterX}" y="0" text-anchor="middle" font-family="'Bungee', 'Luckiest Guy', 'Impact', sans-serif" font-weight="900" font-size="${fbFontSize}" fill="#FFFFFF" stroke="#1E293B" stroke-width="${fbStrokeWidth}" stroke-linejoin="round" stroke-linecap="round" style="paint-order: stroke fill; filter: drop-shadow(0 2px 3px rgba(0,0,0,0.45));">${escapedCh}</text></g>\n`;
							}
							cursorX += (g.adv + letterSpacingUnits) * baseScale;
						}
					}

					const defs = svgData.defs ? `<defs>${svgData.defs}</defs>` : '';
					el.innerHTML = `
						<svg viewBox="0 0 ${vbW} ${vbH}" width="100%" height="100%" preserveAspectRatio="xMidYMid meet" style="overflow:visible;">
							${defs}
							<g class="mrtee-ot-admin-glyph-group" transform="translate(${(vbW / 2).toFixed(2)}, 50) scale(${autoScale.toFixed(5)})">
								${glyphsMarkup}
							</g>
						</svg>
					`;
					el._renderMode = 'otsvg';
					el._glyphsKey = glyphsKey;
					el._rawW = rawW;
					el._lastVbW = vbW;
					el._svgEl = el.querySelector('svg');
					el._groupEl = el.querySelector('.mrtee-ot-admin-glyph-group');
					el._imgEl = null;
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
			const glyphsKey = `ttf::${fontId}::${displayVal}::${strokeColor}::${rawStrokeW}`;

			// O(1) Resize Fast-Path for TTF text boxes: update ONLY viewBox and outer <g> transform
			if (el._renderMode === 'ttf' && el._glyphsKey === glyphsKey && el._svgEl && el._groupEl) {
				if (el._lastVbW !== vbW) {
					const maxDesignW = vbW * 0.92;
					const autoScale = (el._rawW > maxDesignW && maxDesignW > 0) ? (maxDesignW / el._rawW) : 1.0;
					el._svgEl.setAttribute('viewBox', `0 0 ${vbW} ${vbH}`);
					el._groupEl.setAttribute('transform', `translate(${(vbW / 2).toFixed(2)}, 50) scale(${autoScale.toFixed(5)})`);
					el._lastVbW = vbW;
				}
				return;
			}

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
			const scaledStrokeW = rawStrokeW > 0 ? ((rawStrokeW * baseFontSize) / 16).toFixed(2) : '0';

			let textBody = '';
			if (numLines > 1) {
				const stepY = baseFontSize * lineHeightRatio;
				const startDy = -(((numLines - 1) * stepY) / 2);
				lines.forEach((lineStr, li) => {
					const dy = (li === 0) ? startDy : stepY;
					textBody += `<tspan x="0" dy="${dy.toFixed(2)}">${this.escapeXml(lineStr || ' ')}</tspan>`;
				});
			} else {
				textBody = `${this.escapeXml(displayVal)}`;
			}

			el.innerHTML = `
				<svg viewBox="0 0 ${vbW} ${vbH}" width="100%" height="100%" preserveAspectRatio="xMidYMid meet" style="overflow:visible;">
					<g class="mrtee-ttf-admin-glyph-group" transform="translate(${(vbW / 2).toFixed(2)}, 50) scale(${autoScale.toFixed(5)})">
						<text
							x="0"
							y="0"
							font-family="${fontFamily}"
							font-size="${baseFontSize.toFixed(2)}"
							letter-spacing="${letterSpacing}"
							text-anchor="middle"
							dominant-baseline="central"
							fill="#ffffff"
							stroke="${strokeColor}"
							stroke-width="${scaledStrokeW}"
							style="paint-order: stroke fill; stroke-linejoin: round; text-transform: ${textTransform}; filter: drop-shadow(0 1px 2px rgba(0,0,0,0.45));"
						>${textBody}</text>
					</g>
				</svg>
			`;
			el._renderMode = 'ttf';
			el._glyphsKey = glyphsKey;
			el._rawW = maxEstW;
			el._lastVbW = vbW;
			el._svgEl = el.querySelector('svg');
			el._groupEl = el.querySelector('.mrtee-ttf-admin-glyph-group');
			el._imgEl = null;
		}

		/**
		 * Extract the transformed vertical [minY, maxY] bounds (in font design units)
		 * of the primary <image> inside an OpenType-SVG glyph markup string.
		 */
		extractGlyphImageYBounds(svgStr) {
			if (!svgStr || !svgStr.includes('<image')) return null;
			const stack = [];
			const tagRe = /<(\/?)(g|image)\b([^>]*?)(\/?)>/gi;
			let m;
			let best = null;

			while ((m = tagRe.exec(svgStr)) !== null) {
				const isClose = m[1] === '/';
				const tag = m[2].toLowerCase();
				const attrs = m[3] || '';
				const isSelfClose = m[4] === '/';

				if (tag === 'g') {
					if (isClose) {
						if (stack.length) stack.pop();
					} else if (!isSelfClose) {
						const tm = attrs.match(/transform\s*=\s*(?:"([^"]*)"|'([^']*)')/i);
						stack.push(tm ? (tm[1] || tm[2] || '') : '');
					}
				} else if (tag === 'image' && !isClose) {
					const hm = attrs.match(/\bheight\s*=\s*(?:"([+-]?[\d.]+)"|'([+-]?[\d.]+)')/i);
					const h = hm ? parseFloat(hm[1] || hm[2]) : NaN;
					if (!Number.isFinite(h) || h <= 0) continue;

					const ym = attrs.match(/\by\s*=\s*(?:"([+-]?[\d.]+)"|'([+-]?[\d.]+)')/i);
					let y0 = ym ? parseFloat(ym[1] || ym[2]) : 0;
					if (!Number.isFinite(y0)) y0 = 0;
					let y1 = y0 + h;

					// Apply ancestor <g> transforms innermost -> outermost, right-to-left per SVG spec
					for (let si = stack.length - 1; si >= 0; si--) {
						const tStr = stack[si];
						if (!tStr) continue;
						const ops = [];
						const opRe = /(translate|scale|matrix)\s*\(([^)]+)\)/gi;
						let om;
						while ((om = opRe.exec(tStr)) !== null) {
							const fn = om[1].toLowerCase();
							const nums = om[2].trim().split(/[\s,]+/).map(Number).filter(Number.isFinite);
							ops.push({ fn, nums });
						}
						for (let oi = ops.length - 1; oi >= 0; oi--) {
							const { fn, nums } = ops[oi];
							if (fn === 'translate' && nums.length >= 2) {
								y0 += nums[1];
								y1 += nums[1];
							} else if (fn === 'scale' && nums.length >= 1) {
								const sy = nums.length >= 2 ? nums[1] : nums[0];
								y0 *= sy;
								y1 *= sy;
							} else if (fn === 'matrix' && nums.length >= 6) {
								y0 = nums[3] * y0 + nums[5];
								y1 = nums[3] * y1 + nums[5];
							}
						}
					}

					const minY = Math.min(y0, y1);
					const maxY = Math.max(y0, y1);
					if (!best || (maxY - minY) > (best.maxY - best.minY)) {
						best = { minY, maxY };
					}
				}
			}

			return best;
		}

		/**
		 * Compute the optical baseline offset (in positive font units below box vertical center)
		 * for an OpenType-SVG font, matching frontend optical centering.
		 */
		getOpenTypeSvgBaselineOffset(svgData) {
			if (!svgData) return 800 * 0.42;
			if (typeof svgData._opticalBaselineOffset === 'number' && Number.isFinite(svgData._opticalBaselineOffset)) {
				return svgData._opticalBaselineOffset;
			}

			const unitsPerEm = svgData.unitsPerEm || 1000;
			const ascent = svgData.ascent || 800;
			const glyphs = svgData.glyphs || {};
			const refChars = ['H', 'E', 'A', 'M', 'O', 'T', 'B', 'R', 'S', 'N', 'D', 'P'];
			const offsets = [];

			for (let i = 0; i < refChars.length; i++) {
				const ch = refChars[i];
				const g = glyphs[ch] || glyphs[ch.toLowerCase()];
				if (g && g.svg) {
					const bounds = this.extractGlyphImageYBounds(g.svg);
					if (bounds) {
						offsets.push(-((bounds.minY + bounds.maxY) / 2));
					}
				}
			}

			if (!offsets.length) {
				const keys = Object.keys(glyphs);
				for (let i = 0; i < keys.length && offsets.length < 10; i++) {
					const g = glyphs[keys[i]];
					if (g && g.svg) {
						const bounds = this.extractGlyphImageYBounds(g.svg);
						if (bounds) {
							offsets.push(-((bounds.minY + bounds.maxY) / 2));
						}
					}
				}
			}

			let computed = ascent * 0.42;
			if (offsets.length) {
				const avg = offsets.reduce((sum, val) => sum + val, 0) / offsets.length;
				if (Number.isFinite(avg) && avg > 0 && avg < unitsPerEm * 1.5) {
					computed = avg;
				}
			}

			svgData._opticalBaselineOffset = computed;
			return computed;
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
						if (idx === self.getFirstTextFieldIndex() && newest.id) {
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
				if (!opts.skipBoxSelectSync && !opts.isInitial) {
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

				if (!opts.isInitial) {
					self.renderAllBoxesPreviews();
				}
			};

			const renderAllowedList = (opts = {}) => {
				const fonts = (window.mrTeeAdminData && window.mrTeeAdminData.fonts) || {};
				const $list = $('#mr-tee-allowed-fonts-list, #mr_tee_allowed_fonts_list');

				// On initial boot, if PHP VisualPlacementStage already rendered the exact items, preserve them without re-requesting 11 thumbnails
				if (opts.isInitial && $list.children('.mr-tee-allowed-font-item').length === self.allowedFonts.length) {
					updateHiddenAndSync(opts);
					return;
				}

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
								${thumbUrl ? `<img src="${thumbUrl}" alt="" loading="lazy" decoding="async" style="width:18px; height:18px; object-fit:contain;" />` : ''}
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

			// Initial sync (preserves server-rendered DOM)
			renderAllowedList({ isInitial: true });
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
			const firstTextIdx = self.getFirstTextFieldIndex();

			$('.mr-tee-field-font-select').each(function(domIdx) {
				const attrIdx = parseInt($(this).attr('data-field-index'), 10);
				const idx = Number.isNaN(attrIdx) ? domIdx : attrIdx;
				const prevVal = $(this).val();
				const targetVal = (idx === firstTextIdx || !prevVal || !allowed.includes(prevVal)) ? defaultFontId : prevVal;

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
				if (self._syncingSideDims) return;
				self._cachedStageRatio = 0;

				// Sync to TAB 4
				$('#mr_tee_tab4_product_type').val($('#mr_tee_product_type').val());
				$('#mr_tee_tab4_real_width').val($('#mr_tee_real_width').val());
				$('#mr_tee_tab4_real_height').val($('#mr_tee_real_height').val());
				$('#mr_tee_tab4_print_unit').val($('#mr_tee_print_unit').val());
				$('#mr_tee_tab4_dpi').val($('#mr_tee_dpi').val());
				$('#mr_tee_tab4_lock_ratio').prop('checked', $('#mr_tee_lock_aspect_ratio').is(':checked'));

				self.updateActiveSideDimensionsFromToolbar();
				self.recalculateDimensions();
				requestAnimationFrame(() => self.renderAllBoxesPreviews());
			});

			// Listen to dimension inputs on TAB 4
			$(document).on('input change', '#mr_tee_tab4_real_width, #mr_tee_tab4_real_height, #mr_tee_tab4_print_unit, #mr_tee_tab4_dpi, #mr_tee_tab4_product_type, #mr_tee_tab4_lock_ratio', function() {
				if (self._syncingSideDims) return;
				self._cachedStageRatio = 0;

				// Sync to Stage Toolbar
				$('#mr_tee_product_type').val($('#mr_tee_tab4_product_type').val());
				$('#mr_tee_real_width').val($('#mr_tee_tab4_real_width').val());
				$('#mr_tee_real_height').val($('#mr_tee_tab4_real_height').val());
				$('#mr_tee_print_unit').val($('#mr_tee_tab4_print_unit').val());
				$('#mr_tee_dpi').val($('#mr_tee_tab4_dpi').val());
				$('#mr_tee_lock_aspect_ratio').prop('checked', $('#mr_tee_tab4_lock_ratio').is(':checked'));

				self.updateActiveSideDimensionsFromToolbar();
				self.recalculateDimensions();
				requestAnimationFrame(() => self.renderAllBoxesPreviews());
			});

			// Quick Dimension Presets
			$(document).on('click', '.mr-tee-btn-preset-dim', function(e) {
				e.preventDefault();
				self._cachedStageRatio = 0;
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

				self.updateActiveSideDimensionsFromToolbar();
				self.recalculateDimensions();
				requestAnimationFrame(() => self.renderAllBoxesPreviews());
			});
		}

		recalculateDimensions() {
			const self = this;
			const rwEl = document.getElementById('mr_tee_real_width');
			const rhEl = document.getElementById('mr_tee_real_height');
			const unitEl = document.getElementById('mr_tee_print_unit');
			const dpiEl = document.getElementById('mr_tee_dpi');
			const pTypeEl = document.getElementById('mr_tee_product_type');
			const lockEl = document.getElementById('mr_tee_lock_aspect_ratio');

			const w = rwEl ? (parseFloat(rwEl.value) || 30.0) : 30.0;
			const h = rhEl ? (parseFloat(rhEl.value) || 40.0) : 40.0;
			const unit = unitEl ? (unitEl.value || 'cm') : 'cm';
			const dpi = dpiEl ? (parseInt(dpiEl.value, 10) || 300) : 300;
			const pType = pTypeEl ? (pTypeEl.value || 'garment') : 'garment';
			const isLocked = Boolean(lockEl && lockEl.checked) || pType === 'flat_object';

			const refRatio = (h > 0) ? (w / h) : 1.0;
			const activeSideId = this.getActiveSideId();

			// Calculate cumulative bounding box across all fields on the active printable side
			let xMin = 100, yMin = 100, xMax = 0, yMax = 0;
			let boxCount = 0;
			const boxEls = document.querySelectorAll('.mr-tee-field-box');
			for (let bi = 0; bi < boxEls.length; bi++) {
				const boxEl = boxEls[bi];
				const idx = parseInt(boxEl.getAttribute('data-field-index'), 10);
				if (Number.isNaN(idx)) continue;
				if (activeSideId && self.getFieldSideId(idx) !== activeSideId) continue;

				const lEl = document.getElementById(`mr_tee_field_left_${idx}`);
				const tEl = document.getElementById(`mr_tee_field_top_${idx}`);
				const wEl = document.getElementById(`mr_tee_field_width_${idx}`);
				const hEl = document.getElementById(`mr_tee_field_height_${idx}`);
				const rEl = document.getElementById(`mr_tee_field_rotate_${idx}`);

				const l = lEl ? (parseFloat(lEl.value) || 0) : 0;
				const t = tEl ? (parseFloat(tEl.value) || 0) : 0;
				const bw = wEl ? (parseFloat(wEl.value) || 10) : 10;
				const bh = hEl ? (parseFloat(hEl.value) || 10) : 10;
				const r = rEl ? (parseFloat(rEl.value) || 0) : 0;

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
			}

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

			// Update Live Badge (only mutate DOM text if value changed)
			const badgeCumEl = document.getElementById('mr-tee-badge-cum-val');
			const badgeRatioEl = document.getElementById('mr-tee-badge-ratio-val');
			const badgePxEl = document.getElementById('mr-tee-badge-px-val');
			const nextCumText = `${cumRealW} × ${cumRealH} ${unit}`;
			const nextRatioText = `${simplified} (~${cumRatio.toFixed(3)} : 1)`;
			const nextPxText = `${pxW} × ${pxH} px`;

			if (badgeCumEl && badgeCumEl.textContent !== nextCumText) badgeCumEl.textContent = nextCumText;
			if (badgeRatioEl && badgeRatioEl.textContent !== nextRatioText) badgeRatioEl.textContent = nextRatioText;
			if (badgePxEl && badgePxEl.textContent !== nextPxText) badgePxEl.textContent = nextPxText;

			// Update TAB 4 pixel inputs
			const tab4WEl = document.getElementById('mr_tee_tab4_width_px');
			const tab4HEl = document.getElementById('mr_tee_tab4_height_px');
			if (tab4WEl) tab4WEl.value = pxW;
			if (tab4HEl) tab4HEl.value = pxH;

			// Update Stage Canvas Wrap aspect-ratio only when lock state or ratio actually changes
			const wrapEl = this.$canvasWrap && this.$canvasWrap[0];
			if (wrapEl) {
				const lockSig = isLocked ? refRatio.toFixed(4) : 'unlocked';
				if (wrapEl._lastLockSig !== lockSig) {
					wrapEl._lastLockSig = lockSig;
					if (isLocked) {
						wrapEl.classList.add('is-aspect-locked');
						wrapEl.style.aspectRatio = refRatio.toString();
					} else {
						wrapEl.classList.remove('is-aspect-locked');
						wrapEl.style.aspectRatio = '';
					}
				}
			}
		}

		/* =====================================================================
		 * Multi-Side & Multi-View Stage Manager (v2.4.0)
		 * ===================================================================== */

		readInitialPrintSides() {
			const fallbackSide = {
				id: 'front',
				label: 'Recto (Face)',
				print_real_width: parseFloat($('#mr_tee_real_width').val()) || parseFloat(this.config.print_real_width) || 30.0,
				print_real_height: parseFloat($('#mr_tee_real_height').val()) || parseFloat(this.config.print_real_height) || 40.0,
				print_unit: $('#mr_tee_print_unit').val() || this.config.print_unit || 'cm',
				dpi: parseInt($('#mr_tee_dpi').val(), 10) || parseInt(this.config.dpi, 10) || 300,
				product_type: $('#mr_tee_product_type').val() || this.config.product_type || 'garment',
				lock_aspect_ratio: $('#mr_tee_lock_aspect_ratio').length
					? $('#mr_tee_lock_aspect_ratio').is(':checked')
					: Boolean(this.config.lock_aspect_ratio),
			};

			let parsed = null;
			const $input = $('#mr_tee_print_sides_json');
			if ($input.length) {
				try {
					const raw = JSON.parse($input.val() || '[]');
					if (Array.isArray(raw) && raw.length) {
						parsed = raw;
					}
				} catch (e) {
					parsed = null;
				}
			}
			if (!parsed && Array.isArray(this.config.print_sides) && this.config.print_sides.length) {
				parsed = this.config.print_sides;
			}
			if (!parsed || !parsed.length) {
				return [fallbackSide];
			}

			const seen = new Set();
			const normalized = [];
			parsed.forEach((s, idx) => {
				if (!s || typeof s !== 'object') return;
				let id = String(s.id || (idx === 0 ? 'front' : `side_${idx + 1}`)).trim();
				if (!id) id = `side_${idx + 1}`;
				if (seen.has(id)) return;
				seen.add(id);
				const prodType = (s.product_type === 'flat_object') ? 'flat_object' : 'garment';
				normalized.push({
					id,
					label: String(s.label || (id === 'front' ? 'Recto (Face)' : (id === 'back' ? 'Verso (Dos)' : id))).trim(),
					print_real_width: Math.max(0.1, parseFloat(s.print_real_width) || fallbackSide.print_real_width),
					print_real_height: Math.max(0.1, parseFloat(s.print_real_height) || fallbackSide.print_real_height),
					print_unit: ['cm', 'mm', 'in'].includes(s.print_unit) ? s.print_unit : fallbackSide.print_unit,
					dpi: Math.max(72, parseInt(s.dpi, 10) || fallbackSide.dpi),
					product_type: prodType,
					lock_aspect_ratio: Boolean(s.lock_aspect_ratio) || prodType === 'flat_object',
				});
			});

			return normalized.length ? normalized : [fallbackSide];
		}

		readInitialPreviewViews() {
			const defaultSideId = (this.printSides && this.printSides[0]) ? this.printSides[0].id : 'front';
			const fallbackImg = this.defaultThumbUrl || $('#mr-tee-stage-garment-img').attr('src') || '';
			const fallbackView = {
				id: 'view_1',
				label: 'Vue Principale',
				side_id: defaultSideId,
				view_role: 'full',
				image_id: 0,
				image_url: fallbackImg,
				blend_mode: 'normal',
				is_cart_preview: true,
			};

			let parsed = null;
			const $input = $('#mr_tee_preview_views_json');
			if ($input.length) {
				try {
					const raw = JSON.parse($input.val() || '[]');
					if (Array.isArray(raw) && raw.length) {
						parsed = raw;
					}
				} catch (e) {
					parsed = null;
				}
			}
			if (!parsed && Array.isArray(this.config.preview_views) && this.config.preview_views.length) {
				parsed = this.config.preview_views;
			}
			if (!parsed || !parsed.length) {
				return [fallbackView];
			}

			const validSideIds = new Set(this.printSides.map(s => s.id));
			const seen = new Set();
			const normalized = [];
			parsed.forEach((v, idx) => {
				if (!v || typeof v !== 'object') return;
				let id = String(v.id || `view_${idx + 1}`).trim();
				if (!id) id = `view_${idx + 1}`;
				if (seen.has(id)) return;
				seen.add(id);
				const sideId = (v.side_id && validSideIds.has(v.side_id)) ? v.side_id : defaultSideId;
				const role = ['full', 'closeup', 'detail'].includes(v.view_role) ? v.view_role : 'full';
				const blend = ['normal', 'multiply', 'soft_ink'].includes(v.blend_mode) ? v.blend_mode : 'normal';
				normalized.push({
					id,
					label: String(v.label || `Vue ${idx + 1}`).trim(),
					side_id: sideId,
					view_role: role,
					image_id: parseInt(v.image_id, 10) || 0,
					image_url: this.normalizeAdminAssetUrl(String(v.image_url || '').trim()),
					blend_mode: blend,
					is_cart_preview: v.is_cart_preview !== undefined ? Boolean(v.is_cart_preview) : (role === 'full'),
				});
			});

			return normalized.length ? normalized : [fallbackView];
		}

		getSideById(sideId) {
			if (!Array.isArray(this.printSides) || !this.printSides.length) return null;
			return this.printSides.find(s => s.id === sideId) || this.printSides[0];
		}

		getViewById(viewId) {
			if (!Array.isArray(this.previewViews) || !this.previewViews.length) return null;
			return this.previewViews.find(v => v.id === viewId) || this.previewViews[0];
		}

		getActiveView() {
			return this.getViewById(this.activeViewId);
		}

		getActiveSideId() {
			const v = this.getActiveView();
			if (v && v.side_id) return v.side_id;
			return (this.printSides && this.printSides[0]) ? this.printSides[0].id : 'front';
		}

		getPrimaryViewForSide(sideId, strict = false) {
			if (!Array.isArray(this.previewViews) || !this.previewViews.length) return null;
			const fullMatch = this.previewViews.find(v => v.side_id === sideId && v.view_role === 'full');
			if (fullMatch) return fullMatch;
			const anyMatch = this.previewViews.find(v => v.side_id === sideId);
			if (anyMatch) return anyMatch;
			return strict ? null : this.previewViews[0];
		}

		generateUniqueSideId(label) {
			let base = String(label || 'side');
			if (typeof base.normalize === 'function') {
				base = base.normalize('NFD').replace(/[\u0300-\u036f]/g, '');
			}
			base = base.toLowerCase().replace(/[^a-z0-9]+/g, '_').replace(/^_+|_+$/g, '');
			if (!base) base = 'side';

			const used = new Set(this.printSides.map(s => s.id));
			if (!used.has(base)) return base;
			let n = 2;
			while (used.has(`${base}_${n}`)) n++;
			return `${base}_${n}`;
		}

		generateUniqueViewId(base = 'view') {
			const used = new Set(this.previewViews.map(v => v.id));
			if (base !== 'view' && !used.has(base)) return base;
			let n = (base === 'view') ? (this.previewViews.length + 1) : 2;
			while (used.has(`${base}_${n}`)) n++;
			return `${base}_${n}`;
		}

		generateSideSelectOptions(selectedSideId) {
			return this.printSides.map(s => {
				const sel = (s.id === selectedSideId) ? 'selected' : '';
				return `<option value="${this.escapeXml(s.id)}" ${sel}>${this.escapeXml(s.label || s.id)}</option>`;
			}).join('');
		}

		refreshAllSideSelects() {
			const self = this;
			const activeView = this.getActiveView();
			const defaultSideId = this.printSides[0] ? this.printSides[0].id : 'front';
			const validIds = new Set(this.printSides.map(s => s.id));

			const $viewSide = $('#mr_tee_view_side_id');
			if ($viewSide.length) {
				const curSide = (activeView && validIds.has(activeView.side_id)) ? activeView.side_id : defaultSideId;
				$viewSide.html(this.generateSideSelectOptions(curSide)).val(curSide);
			}

			$('.mr-tee-field-side-select').each(function() {
				const $sel = $(this);
				const idx = parseInt($sel.attr('data-field-index'), 10);
				let curVal = $sel.val() || $(`#mr-tee-field-panel-${idx}`).attr('data-side-id') || defaultSideId;
				if (!validIds.has(curVal)) curVal = defaultSideId;
				$sel.html(self.generateSideSelectOptions(curVal)).val(curVal);
			});
		}

		getFieldSideId(idx) {
			const defaultSideId = (this.printSides && this.printSides[0]) ? this.printSides[0].id : 'front';
			const panelEl = document.getElementById(`mr-tee-field-panel-${idx}`);
			if (panelEl) {
				const selEl = panelEl.querySelector('.mr-tee-field-side-select');
				if (selEl && selEl.value) {
					return String(selEl.value);
				}
				const panelSide = panelEl.getAttribute('data-side-id');
				if (panelSide) return String(panelSide);
			}
			const boxEl = document.getElementById(`mr-tee-field-box-${idx}`);
			if (boxEl) {
				const boxSide = boxEl.getAttribute('data-side-id');
				if (boxSide) return String(boxSide);
			}
			return defaultSideId;
		}

		getFieldCurrentCoords(idx) {
			const defaultH = this.getFieldType(idx) === 'image' ? 30.0 : 8.5;
			const round1 = (el, fallback) => {
				const n = el ? parseFloat(el.value) : NaN;
				return Number.isFinite(n) ? (Math.round(n * 10) / 10) : fallback;
			};
			const rEl = document.getElementById(`mr_tee_field_rotate_${idx}`);
			const rawRot = rEl ? parseFloat(rEl.value) : NaN;
			return {
				box_left: round1(document.getElementById(`mr_tee_field_left_${idx}`), 30.0),
				box_top: round1(document.getElementById(`mr_tee_field_top_${idx}`), 25.0),
				box_width: round1(document.getElementById(`mr_tee_field_width_${idx}`), 40.0),
				box_height: round1(document.getElementById(`mr_tee_field_height_${idx}`), defaultH),
				box_rotate: Number.isFinite(rawRot) ? Math.round(rawRot) : 0,
			};
		}

		getFieldPlacements(idx) {
			let inputEl = document.getElementById(`mr_tee_field_placements_${idx}`);
			if (!inputEl) {
				const panelEl = document.getElementById(`mr-tee-field-panel-${idx}`);
				inputEl = panelEl ? panelEl.querySelector('.mr-tee-field-placements-json') : null;
			}
			if (!inputEl) return {};
			try {
				const raw = JSON.parse(inputEl.value || '{}');
				return (raw && typeof raw === 'object' && !Array.isArray(raw)) ? raw : {};
			} catch (e) {
				return {};
			}
		}

		setFieldPlacements(idx, placementsObj) {
			let inputEl = document.getElementById(`mr_tee_field_placements_${idx}`);
			if (!inputEl) {
				const panelEl = document.getElementById(`mr-tee-field-panel-${idx}`);
				inputEl = panelEl ? panelEl.querySelector('.mr-tee-field-placements-json') : null;
			}
			if (!inputEl) return;
			inputEl.value = JSON.stringify(placementsObj || {});
		}

		saveFieldPlacementForActiveView(idx) {
			const activeView = this.getActiveView();
			if (!activeView || !activeView.id) return;
			if (this.getFieldSideId(idx) !== activeView.side_id) return;

			const placements = this.getFieldPlacements(idx);
			placements[activeView.id] = this.getFieldCurrentCoords(idx);
			this.setFieldPlacements(idx, placements);
		}

		flushActiveViewPlacements() {
			const activeView = this.getActiveView();
			if (!activeView || !activeView.id) return;
			const count = $('.mr-tee-field-box').length;
			for (let i = 0; i < count; i++) {
				if (this.getFieldSideId(i) === activeView.side_id) {
					this.saveFieldPlacementForActiveView(i);
				}
			}
		}

		applyFieldCoordsToDom(idx, coords) {
			if (!coords || typeof coords !== 'object') return;
			const defaultH = this.getFieldType(idx) === 'image' ? 30.0 : 8.5;
			const l = Number.isFinite(parseFloat(coords.box_left)) ? parseFloat(coords.box_left) : 30.0;
			const t = Number.isFinite(parseFloat(coords.box_top)) ? parseFloat(coords.box_top) : 25.0;
			const w = Number.isFinite(parseFloat(coords.box_width)) ? parseFloat(coords.box_width) : 40.0;
			const h = Number.isFinite(parseFloat(coords.box_height)) ? parseFloat(coords.box_height) : defaultH;
			const r = Number.isFinite(parseFloat(coords.box_rotate)) ? parseFloat(coords.box_rotate) : 0;

			$(`#mr_tee_field_left_${idx}`).val(l);
			$(`#mr_tee_field_top_${idx}`).val(t);
			$(`#mr_tee_field_width_${idx}`).val(w);
			$(`#mr_tee_field_height_${idx}`).val(h);
			$(`#mr_tee_field_rotate_${idx}`).val(r);

			$(`#mr-tee-field-box-${idx}`).css({
				left: l + '%',
				top: t + '%',
				width: w + '%',
				height: h + '%',
				transform: `rotate(${r}deg)`,
				'transform-origin': 'center center',
			});

			const rotTxt = r !== 0 ? ` (${r}°)` : '';
			$(`.mr-tee-field-pill[data-field-index="${idx}"] .mr-tee-pill-dims`).text(`${w}×${h}%${rotTxt}`);
		}

		loadSideDimensionsToToolbar(sideId) {
			const side = this.getSideById(sideId);
			if (!side) return;

			this._syncingSideDims = true;
			try {
				$('#mr_tee_real_width').val(side.print_real_width);
				$('#mr_tee_real_height').val(side.print_real_height);
				$('#mr_tee_print_unit').val(side.print_unit);
				$('#mr_tee_dpi').val(side.dpi);
				$('#mr_tee_product_type').val(side.product_type);
				$('#mr_tee_lock_aspect_ratio').prop('checked', Boolean(side.lock_aspect_ratio));

				$('#mr_tee_tab4_real_width').val(side.print_real_width);
				$('#mr_tee_tab4_real_height').val(side.print_real_height);
				$('#mr_tee_tab4_print_unit').val(side.print_unit);
				$('#mr_tee_tab4_dpi').val(side.dpi);
				$('#mr_tee_tab4_product_type').val(side.product_type);
				$('#mr_tee_tab4_lock_ratio').prop('checked', Boolean(side.lock_aspect_ratio));
			} finally {
				this._syncingSideDims = false;
			}
		}

		updateActiveSideDimensionsFromToolbar(opts = {}) {
			const activeSide = this.getSideById(this.getActiveSideId());
			if (!activeSide) return;

			const w = parseFloat($('#mr_tee_real_width').val());
			const h = parseFloat($('#mr_tee_real_height').val());
			const unit = $('#mr_tee_print_unit').val() || 'cm';
			const dpi = parseInt($('#mr_tee_dpi').val(), 10);
			const prodType = $('#mr_tee_product_type').val() === 'flat_object' ? 'flat_object' : 'garment';
			const lockRatio = $('#mr_tee_lock_aspect_ratio').is(':checked') || prodType === 'flat_object';

			if (Number.isFinite(w) && w > 0) activeSide.print_real_width = w;
			if (Number.isFinite(h) && h > 0) activeSide.print_real_height = h;
			activeSide.print_unit = ['cm', 'mm', 'in'].includes(unit) ? unit : 'cm';
			if (Number.isFinite(dpi) && dpi >= 72) activeSide.dpi = dpi;
			activeSide.product_type = prodType;
			activeSide.lock_aspect_ratio = lockRatio;

			if (!opts.skipJsonSync) {
				this.syncHiddenMultiViewJson();
				this.renderSidesBar();
			}
		}

		syncHiddenMultiViewJson() {
			const $sidesInput = $('#mr_tee_print_sides_json');
			if ($sidesInput.length) {
				$sidesInput.val(JSON.stringify(this.printSides));
			}
			const $viewsInput = $('#mr_tee_preview_views_json');
			if ($viewsInput.length) {
				$viewsInput.val(JSON.stringify(this.previewViews));
			}
		}

		/**
		 * Flush multi-view hidden JSON inputs and optionally sync each field's
		 * top-level box_* inputs to the primary/full view of its side before form submit.
		 */
		syncMultiViewInputs(syncPrimaryBoxes = false) {
			this.flushActiveViewPlacements();
			this.updateActiveSideDimensionsFromToolbar({ skipJsonSync: true });
			this.syncHiddenMultiViewJson();

			if (!syncPrimaryBoxes) return;

			const count = $('.mr-tee-field-box').length;
			for (let i = 0; i < count; i++) {
				const fieldSide = this.getFieldSideId(i);
				const primaryView = this.getPrimaryViewForSide(fieldSide);
				const placements = this.getFieldPlacements(i);
				if (primaryView && placements[primaryView.id] && placements[primaryView.id].box_left !== undefined) {
					const p = placements[primaryView.id];
					$(`#mr_tee_field_left_${i}`).val(p.box_left);
					$(`#mr_tee_field_top_${i}`).val(p.box_top);
					$(`#mr_tee_field_width_${i}`).val(p.box_width);
					$(`#mr_tee_field_height_${i}`).val(p.box_height);
					$(`#mr_tee_field_rotate_${i}`).val(p.box_rotate !== undefined ? p.box_rotate : 0);
				}
			}

			// Ensure top-level legacy product dimension inputs reflect the primary side (printSides[0])
			if (this.printSides.length > 1 && this.printSides[0]) {
				this.loadSideDimensionsToToolbar(this.printSides[0].id);
			}
		}

		renderSidesBar() {
			const $bar = $('#mr-tee-sides-bar');
			if (!$bar.length) return;

			const activeSideId = this.getActiveSideId();
			const fieldCountBySide = {};
			const totalFields = $('.mr-tee-field-box').length;
			for (let i = 0; i < totalFields; i++) {
				const sid = this.getFieldSideId(i);
				fieldCountBySide[sid] = (fieldCountBySide[sid] || 0) + 1;
			}

			const pillsHtml = this.printSides.map(side => {
				const isActive = side.id === activeSideId;
				const fCount = fieldCountBySide[side.id] || 0;
				const dimsText = `${side.print_real_width}×${side.print_real_height} ${side.print_unit}`;
				return `
					<button
						type="button"
						class="mr-tee-side-pill ${isActive ? 'is-active' : ''}"
						data-side-id="${this.escapeXml(side.id)}"
					>
						<span class="mr-tee-side-pill-dot" aria-hidden="true"></span>
						<span class="mr-tee-side-pill-label">${this.escapeXml(side.label || side.id)}</span>
						<span class="mr-tee-side-pill-dims">${this.escapeXml(dimsText)} · ${fCount}</span>
					</button>
				`;
			}).join('');

			const $list = $('#mr-tee-sides-list').length
				? $('#mr-tee-sides-list')
				: $bar.find('.mr-tee-sides-list').first();

			if ($list.length) {
				$list.html(pillsHtml);
			} else {
				$bar.find('.mr-tee-side-pill').remove();
				const $anchor = $bar.children('.mr-tee-sides-actions, #mr-tee-btn-add-back-side, #mr-tee-btn-add-side').first();
				if ($anchor.length) {
					$(pillsHtml).insertBefore($anchor);
				} else {
					$bar.append(pillsHtml);
				}
			}

			const hasBack = this.printSides.some(s => s.id === 'back');
			$('#mr-tee-btn-add-back-side').toggleClass('has-back-side', hasBack).toggle(!hasBack);
		}

		renderViewsBar() {
			const $list = $('#mr-tee-views-list');
			if (!$list.length) return;

			const roleLabels = {
				full: 'Complet',
				closeup: 'Zoom',
				detail: 'Détail',
			};

			const tabsHtml = this.previewViews.map((view, idx) => {
				const isActive = view.id === this.activeViewId;
				const side = this.getSideById(view.side_id);
				const sideLabel = side ? (side.label || side.id) : view.side_id;
				const role = view.view_role || 'full';
				const roleLabel = roleLabels[role] || role;
				const thumbUrl = this.normalizeAdminAssetUrl(view.image_url || (idx === 0 ? this.defaultThumbUrl : ''));

				return `
					<button
						type="button"
						class="mr-tee-view-tab ${isActive ? 'is-active' : ''}"
						data-view-id="${this.escapeXml(view.id)}"
						data-side-id="${this.escapeXml(view.side_id)}"
						data-view-role="${this.escapeXml(role)}"
					>
						<span class="mr-tee-view-tab-thumb">
							${thumbUrl
								? `<img src="${this.escapeXml(thumbUrl)}" alt="" loading="lazy" decoding="async" />`
								: '<span class="dashicons dashicons-format-image" aria-hidden="true"></span>'}
						</span>
						<span class="mr-tee-view-tab-meta">
							<span class="mr-tee-view-tab-label">${this.escapeXml(view.label || `Vue ${idx + 1}`)}</span>
							<span class="mr-tee-view-tab-badges">
								<span class="mr-tee-view-side-badge">${this.escapeXml(sideLabel)}</span>
								<span class="mr-tee-view-role-badge is-role-${this.escapeXml(role)}">${this.escapeXml(roleLabel)}</span>
								${view.is_cart_preview ? '<span class="mr-tee-view-badge-cart" title="Aperçu panier">🛒</span>' : ''}
							</span>
						</span>
					</button>
				`;
			}).join('');

			$list.html(tabsHtml);
		}

		updateEmptySideNotice(sideId, isEmpty) {
			const $container = $('#mr-tee-field-panels-container');
			if (!$container.length) return;

			let $notice = $('#mr-tee-empty-side-notice');
			if (!isEmpty) {
				if ($notice.length) $notice.hide();
				return;
			}

			const side = this.getSideById(sideId);
			const sideLabel = side ? (side.label || side.id) : sideId;
			if (!$notice.length) {
				$notice = $('<div id="mr-tee-empty-side-notice" class="mr-tee-empty-side-notice"></div>');
				$container.before($notice);
			}
			$notice
				.html(`<strong>Face « ${this.escapeXml(sideLabel)} » sans zone</strong><span>Cliquez sur « + Ajouter un Texte » ou « + Ajouter une Image » pour créer une zone sur cette face.</span>`)
				.show();
		}

		setViewUploadStatus(message, tone = 'info') {
			const $bar = $('#mr-tee-active-view-bar');
			if (!$bar.length) return;
			let $status = $('#mr-tee-view-upload-status');
			if (!$status.length) {
				$status = $bar.find('.mr-tee-view-upload-status').first();
			}
			if (!$status.length) {
				$status = $('<span id="mr-tee-view-upload-status" class="mr-tee-view-upload-status"></span>');
				$bar.append($status);
			}
			const colors = {
				info: '#2563eb',
				success: '#059669',
				error: '#dc2626',
				muted: '#64748b',
			};
			$status.text(message || '').css('color', colors[tone] || colors.muted);
		}

		highlightViewImagePrompt(message) {
			const $btn = $('#mr_tee_view_image_btn');
			if ($btn.length) {
				$btn.removeClass('mr-tee-attention-pulse');
				// Force reflow so animation replays reliably
				void $btn[0].offsetWidth;
				$btn.addClass('mr-tee-attention-pulse');
				clearTimeout(this._viewImgPulseTimer);
				this._viewImgPulseTimer = setTimeout(() => {
					$btn.removeClass('mr-tee-attention-pulse');
				}, 3600);
			}
			if (message) {
				this.setViewUploadStatus(message, 'info');
			}
		}

		setActiveView(viewId, opts = {}) {
			const nextView = this.getViewById(viewId);
			if (!nextView) return;

			// Flush previously active view placements & side dims before switching
			if (!opts.isInitial && this._multiviewInitialized && this.activeViewId && this.activeViewId !== nextView.id) {
				const prevView = this.previewViews.find(v => v.id === this.activeViewId);
				if (prevView) {
					this.flushActiveViewPlacements();
					this.updateActiveSideDimensionsFromToolbar({ skipJsonSync: true });
				}
			}

			this.activeViewId = nextView.id;
			const activeSideId = nextView.side_id;

			// 1. Highlight active view tab and printable side pill
			$('.mr-tee-view-tab').removeClass('is-active').filter(function() {
				return $(this).attr('data-view-id') === nextView.id;
			}).addClass('is-active');
			$('.mr-tee-side-pill').removeClass('is-active').filter(function() {
				return $(this).attr('data-side-id') === activeSideId;
			}).addClass('is-active');

			// 2. Swap stage garment mockup image
			const targetImgUrl = this.normalizeAdminAssetUrl(nextView.image_url || this.defaultThumbUrl || '');
			const $img = $('#mr-tee-stage-garment-img');
			if ($img.length && targetImgUrl && $img.attr('src') !== targetImgUrl) {
				this._cachedStageRatio = 0;
				$img.attr('src', targetImgUrl);
			}

			// 3. Populate Active View Settings bar
			$('#mr-tee-active-view-bar').attr('data-active-view-id', nextView.id);
			$('#mr_tee_view_label').val(nextView.label || '');
			if ($('#mr_tee_view_side_id').length) {
				$('#mr_tee_view_side_id').val(activeSideId);
			}
			$('#mr_tee_view_role').val(nextView.view_role || 'full');
			$('#mr_tee_view_blend_mode').val(nextView.blend_mode || 'normal');
			$('#mr_tee_view_is_cart_preview').prop('checked', Boolean(nextView.is_cart_preview));

			const viewIdx = this.previewViews.findIndex(v => v.id === nextView.id);
			$('#mr_tee_view_move_left').prop('disabled', viewIdx <= 0);
			$('#mr_tee_view_move_right').prop('disabled', viewIdx < 0 || viewIdx >= this.previewViews.length - 1);
			$('#mr_tee_view_delete_btn').prop('disabled', this.previewViews.length <= 1);

			// 4. Apply live blend preview attribute on stage wrap & boxes container
			const blendMode = nextView.blend_mode || 'normal';
			$('#mr-tee-boxes-container').attr('data-blend', blendMode);
			if (this.$canvasWrap.length) {
				this.$canvasWrap
					.attr('data-active-view-id', nextView.id)
					.attr('data-active-side-id', activeSideId)
					.attr('data-blend-mode', blendMode);
			}

			// 5. Filter canvas boxes to active side & load/seed placements[viewId]
			let firstMatchingIdx = -1;
			let activeFieldMatchesSide = false;
			const count = $('.mr-tee-field-box').length;

			for (let i = 0; i < count; i++) {
				const fieldSide = this.getFieldSideId(i);
				const matches = (fieldSide === activeSideId);
				const $box = $(`#mr-tee-field-box-${i}`);
				const $pill = $(`.mr-tee-field-pill[data-field-index="${i}"]`);
				const $panel = $(`#mr-tee-field-panel-${i}`);

				$box.attr('data-side-id', fieldSide);
				$pill.attr('data-side-id', fieldSide).toggleClass('is-other-side', !matches);
				$panel.attr('data-side-id', fieldSide);

				if (matches) {
					$box.show().removeClass('is-other-side');
					if (firstMatchingIdx === -1) firstMatchingIdx = i;
					if (i === this.activeFieldIndex) activeFieldMatchesSide = true;

					const placements = this.getFieldPlacements(i);
					let placement = placements[nextView.id];
					if (!placement || typeof placement !== 'object' || placement.box_left === undefined) {
						const primaryView = this.getPrimaryViewForSide(activeSideId, true);
						const seed = (primaryView && placements[primaryView.id] && placements[primaryView.id].box_left !== undefined)
							? placements[primaryView.id]
							: this.getFieldCurrentCoords(i);
						const defaultH = this.getFieldType(i) === 'image' ? 30.0 : 8.5;
						placement = {
							box_left: Number.isFinite(parseFloat(seed.box_left)) ? parseFloat(seed.box_left) : 30.0,
							box_top: Number.isFinite(parseFloat(seed.box_top)) ? parseFloat(seed.box_top) : 25.0,
							box_width: Number.isFinite(parseFloat(seed.box_width)) ? parseFloat(seed.box_width) : 40.0,
							box_height: Number.isFinite(parseFloat(seed.box_height)) ? parseFloat(seed.box_height) : defaultH,
							box_rotate: Number.isFinite(parseFloat(seed.box_rotate)) ? parseFloat(seed.box_rotate) : 0,
						};
						placements[nextView.id] = placement;
						this.setFieldPlacements(i, placements);
					}
					this.applyFieldCoordsToDom(i, placement);
				} else {
					$box.hide().addClass('is-other-side');
				}
			}

			// 6. Auto-activate first field on activeSideId if current active field belongs to another side
			const preferredIdx = (opts.preferredFieldIndex !== undefined && opts.preferredFieldIndex !== null)
				? opts.preferredFieldIndex
				: null;
			if (preferredIdx !== null && preferredIdx >= 0 && preferredIdx < count && this.getFieldSideId(preferredIdx) === activeSideId) {
				this.setActiveField(preferredIdx);
			} else if (!activeFieldMatchesSide && firstMatchingIdx !== -1) {
				this.setActiveField(firstMatchingIdx);
			} else if (firstMatchingIdx === -1) {
				$('.mr-tee-field-box, .mr-tee-field-pill, .mr-tee-field-panel').removeClass('is-active');
			}

			this.updateEmptySideNotice(activeSideId, firstMatchingIdx === -1);

			// 7. Sync physical dimension toolbar to active side
			this.loadSideDimensionsToToolbar(activeSideId);

			// 8. Recalculate aspect ratio / dimensions first, then re-render box previews
			this.recalculateDimensions();
			this.renderAllBoxesPreviews();
			this.syncHiddenMultiViewJson();
		}

		bindMultiViewManager() {
			const self = this;

			// Initial render & dropdown synchronization
			this.renderSidesBar();
			this.renderViewsBar();
			this.refreshAllSideSelects();

			// Switch active view by clicking a view tab
			$(document).on('click', '.mr-tee-view-tab', function(e) {
				e.preventDefault();
				const viewId = $(this).attr('data-view-id');
				if (viewId) {
					self.setViewUploadStatus('');
					self.setActiveView(viewId);
				}
			});

			// Switch active side by clicking a side pill
			$(document).on('click', '.mr-tee-side-pill', function(e) {
				e.preventDefault();
				const sideId = $(this).attr('data-side-id');
				if (!sideId) return;
				const currentView = self.getActiveView();
				if (currentView && currentView.side_id === sideId) return;

				let targetView = self.getPrimaryViewForSide(sideId, true);
				if (!targetView) {
					const side = self.getSideById(sideId);
					targetView = {
						id: self.generateUniqueViewId(`view_${sideId}`),
						label: side ? (side.label || sideId) : sideId,
						side_id: sideId,
						view_role: 'full',
						image_id: 0,
						image_url: '',
						blend_mode: currentView ? (currentView.blend_mode || 'normal') : 'normal',
						is_cart_preview: true,
					};
					self.previewViews.push(targetView);
					self.renderViewsBar();
				}
				self.setViewUploadStatus('');
				self.setActiveView(targetView.id);
			});

			// Add New View (1-click Close-Up / Full pairing on the active side)
			$(document).on('click', '#mr-tee-btn-add-view', function(e) {
				e.preventDefault();
				const activeView = self.getActiveView();
				const sideId = activeView ? activeView.side_id : (self.printSides[0] ? self.printSides[0].id : 'front');
				const side = self.getSideById(sideId);
				const nextRole = (activeView && activeView.view_role === 'full') ? 'closeup' : 'full';
				const isCartPreview = (nextRole === 'full');
				const sideViewsCount = self.previewViews.filter(v => v.side_id === sideId).length;
				const sideTitle = side ? (side.label || sideId) : sideId;
				const defaultLabel = nextRole === 'closeup'
					? `${sideTitle} — Zoom`
					: `${sideTitle} — Vue ${sideViewsCount + 1}`;

				const newView = {
					id: self.generateUniqueViewId('view'),
					label: defaultLabel,
					side_id: sideId,
					view_role: nextRole,
					image_id: 0,
					image_url: '',
					blend_mode: activeView ? (activeView.blend_mode || 'normal') : 'normal',
					is_cart_preview: isCartPreview,
				};

				self.flushActiveViewPlacements();
				self.previewViews.push(newView);

				// Seed placements for all fields on this side
				const fieldCount = $('.mr-tee-field-box').length;
				for (let i = 0; i < fieldCount; i++) {
					if (self.getFieldSideId(i) !== sideId) continue;
					const placements = self.getFieldPlacements(i);
					const seed = (activeView && placements[activeView.id]) ? placements[activeView.id] : self.getFieldCurrentCoords(i);
					placements[newView.id] = { ...seed };
					self.setFieldPlacements(i, placements);
				}

				self.renderViewsBar();
				self.syncMultiViewInputs();
				self.setActiveView(newView.id);
				self.highlightViewImagePrompt('👆 Sélectionnez la photo mockup pour cette nouvelle vue.');
			});

			// 1-Click Recto/Verso Wizard
			$(document).on('click', '#mr-tee-btn-add-back-side', function(e) {
				e.preventDefault();
				self.flushActiveViewPlacements();
				self.updateActiveSideDimensionsFromToolbar({ skipJsonSync: true });

				const refSide = self.getSideById(self.getActiveSideId()) || self.printSides[0];
				const activeView = self.getActiveView();

				if (!self.printSides.some(s => s.id === 'back')) {
					self.printSides.push({
						id: 'back',
						label: 'Verso (Dos)',
						print_real_width: refSide ? refSide.print_real_width : 30.0,
						print_real_height: refSide ? refSide.print_real_height : 40.0,
						print_unit: refSide ? refSide.print_unit : 'cm',
						dpi: refSide ? refSide.dpi : 300,
						product_type: refSide ? refSide.product_type : 'garment',
						lock_aspect_ratio: refSide ? Boolean(refSide.lock_aspect_ratio) : false,
					});
				}

				let backView = self.previewViews.find(v => v.id === 'view_back' || v.side_id === 'back');
				if (!backView) {
					backView = {
						id: self.generateUniqueViewId('view_back'),
						label: 'T-Shirt Verso',
						side_id: 'back',
						view_role: 'full',
						image_id: 0,
						image_url: '',
						blend_mode: activeView ? (activeView.blend_mode || 'normal') : 'normal',
						is_cart_preview: true,
					};
					self.previewViews.push(backView);
				}

				self.renderSidesBar();
				self.renderViewsBar();
				self.refreshAllSideSelects();
				self.syncMultiViewInputs();
				self.setActiveView(backView.id);
				self.highlightViewImagePrompt('👆 Face Verso prête ! Choisissez l\'image mockup du dos.');
			});

			// Add Custom Printable Side (e.g. "Manche Gauche")
			$(document).on('click', '#mr-tee-btn-add-side', function(e) {
				e.preventDefault();
				const rawName = window.prompt('Nom de la nouvelle face d\'impression (ex : Manche Gauche, Poche, Capuche) :', '');
				if (!rawName || !rawName.trim()) return;

				const label = rawName.trim();
				const sideId = self.generateUniqueSideId(label);
				self.flushActiveViewPlacements();
				self.updateActiveSideDimensionsFromToolbar({ skipJsonSync: true });

				const refSide = self.getSideById(self.getActiveSideId()) || self.printSides[0];
				const activeView = self.getActiveView();

				self.printSides.push({
					id: sideId,
					label,
					print_real_width: refSide ? refSide.print_real_width : 30.0,
					print_real_height: refSide ? refSide.print_real_height : 40.0,
					print_unit: refSide ? refSide.print_unit : 'cm',
					dpi: refSide ? refSide.dpi : 300,
					product_type: refSide ? refSide.product_type : 'garment',
					lock_aspect_ratio: refSide ? Boolean(refSide.lock_aspect_ratio) : false,
				});

				const newView = {
					id: self.generateUniqueViewId(`view_${sideId}`),
					label,
					side_id: sideId,
					view_role: 'full',
					image_id: 0,
					image_url: '',
					blend_mode: activeView ? (activeView.blend_mode || 'normal') : 'normal',
					is_cart_preview: true,
				};
				self.previewViews.push(newView);

				self.renderSidesBar();
				self.renderViewsBar();
				self.refreshAllSideSelects();
				self.syncMultiViewInputs();
				self.setActiveView(newView.id);
				self.highlightViewImagePrompt(`👆 Face « ${label} » ajoutée ! Choisissez son image mockup.`);
			});

			// Active View Settings: Label
			$(document).on('input change', '#mr_tee_view_label', function() {
				const view = self.getActiveView();
				if (!view) return;
				const nextLabel = ($(this).val() || '').trim() || view.id;
				view.label = nextLabel;
				$(`.mr-tee-view-tab[data-view-id="${view.id}"] .mr-tee-view-tab-label`).text(nextLabel);
				self.syncHiddenMultiViewJson();
			});

			// Active View Settings: Linked Side
			$(document).on('change', '#mr_tee_view_side_id', function() {
				const view = self.getActiveView();
				if (!view) return;
				const nextSideId = $(this).val();
				if (!nextSideId || nextSideId === view.side_id) return;

				self.flushActiveViewPlacements();
				self.updateActiveSideDimensionsFromToolbar({ skipJsonSync: true });
				view.side_id = nextSideId;
				self.renderViewsBar();
				self.renderSidesBar();
				self.syncHiddenMultiViewJson();
				self.setActiveView(view.id);
			});

			// Active View Settings: View Role (full / closeup / detail)
			$(document).on('change', '#mr_tee_view_role', function() {
				const view = self.getActiveView();
				if (!view) return;
				const role = $(this).val();
				view.view_role = ['full', 'closeup', 'detail'].includes(role) ? role : 'full';
				self.renderViewsBar();
				self.syncHiddenMultiViewJson();
			});

			// Active View Settings: Blend Mode (normal / multiply / soft_ink)
			$(document).on('change', '#mr_tee_view_blend_mode', function() {
				const view = self.getActiveView();
				if (!view) return;
				const blend = $(this).val();
				view.blend_mode = ['normal', 'multiply', 'soft_ink'].includes(blend) ? blend : 'normal';
				$('#mr-tee-boxes-container').attr('data-blend', view.blend_mode);
				if (self.$canvasWrap.length) {
					self.$canvasWrap.attr('data-blend-mode', view.blend_mode);
				}
				self.syncHiddenMultiViewJson();
			});

			// Active View Settings: Cart Preview checkbox
			$(document).on('change', '#mr_tee_view_is_cart_preview', function() {
				const view = self.getActiveView();
				if (!view) return;
				view.is_cart_preview = $(this).is(':checked');
				self.renderViewsBar();
				self.syncHiddenMultiViewJson();
			});

			// Active View Image Picker (WP Media Library with file upload fallback)
			$(document).on('click', '#mr_tee_view_image_btn', function(e) {
				e.preventDefault();
				$(this).removeClass('mr-tee-attention-pulse');
				const view = self.getActiveView();
				if (!view) return;

				if (!window.wp || !window.wp.media) {
					$('#mr_tee_view_image_upload').trigger('click');
					return;
				}

				const frame = window.wp.media({
					title: `Image mockup — ${view.label || view.id}`,
					button: { text: 'Utiliser cette image' },
					library: { type: 'image' },
					multiple: false,
				});

				frame.on('select', function() {
					const att = frame.state().get('selection').first().toJSON();
					const url = (att && att.url) ? att.url : '';
					const attId = (att && att.id) ? parseInt(att.id, 10) : 0;
					if (!url) return;

					view.image_id = attId || 0;
					view.image_url = url;
					$('#mr-tee-stage-garment-img').attr('src', url);
					self.renderViewsBar();
					self.syncHiddenMultiViewJson();
					self.setViewUploadStatus('✅ Image mockup mise à jour !', 'success');
				});

				frame.open();
			});

			// Active View Direct File Upload (via existing mr_tee_upload_default_image AJAX action)
			$(document).on('change', '#mr_tee_view_image_upload', function() {
				const inputEl = this;
				const file = inputEl.files && inputEl.files[0];
				if (!file) return;

				const view = self.getActiveView();
				if (!view) return;

				self.setViewUploadStatus('⏳ Envoi de l\'image mockup...', 'info');

				const formData = new FormData();
				formData.append('action', 'mr_tee_upload_default_image');
				formData.append('nonce', window.mrTeeAdminData ? window.mrTeeAdminData.nonce : '');
				formData.append('product_id', window.mrTeeAdminData ? (window.mrTeeAdminData.productId || 0) : 0);
				formData.append('image_file', file);

				const ajaxUrl = self.toSameOriginPath((window.mrTeeAdminData && window.mrTeeAdminData.ajaxUrl) || '/wp-admin/admin-ajax.php');
				$.ajax({
					url: ajaxUrl,
					type: 'POST',
					data: formData,
					processData: false,
					contentType: false,
					success: function(resp) {
						inputEl.value = '';
						if (resp && resp.success && resp.data && resp.data.url) {
							view.image_id = parseInt(resp.data.id, 10) || 0;
							view.image_url = resp.data.url;
							$('#mr_tee_view_image_btn').removeClass('mr-tee-attention-pulse');
							if (self.activeViewId === view.id) {
								$('#mr-tee-stage-garment-img').attr('src', resp.data.url);
							}
							self.renderViewsBar();
							self.syncHiddenMultiViewJson();
							self.setViewUploadStatus('✅ Image mockup importée !', 'success');
						} else {
							const msg = (resp && resp.data && resp.data.message) ? resp.data.message : 'Erreur lors de l\'import.';
							self.setViewUploadStatus(`❌ ${msg}`, 'error');
						}
					},
					error: function(xhr, status, err) {
						inputEl.value = '';
						self.setViewUploadStatus(`❌ Erreur réseau : ${err}`, 'error');
					},
				});
			});

			// Move Active View Left
			$(document).on('click', '#mr_tee_view_move_left', function(e) {
				e.preventDefault();
				const idx = self.previewViews.findIndex(v => v.id === self.activeViewId);
				if (idx <= 0) return;
				const tmp = self.previewViews[idx - 1];
				self.previewViews[idx - 1] = self.previewViews[idx];
				self.previewViews[idx] = tmp;
				self.renderViewsBar();
				self.syncHiddenMultiViewJson();
				$('#mr_tee_view_move_left').prop('disabled', idx - 1 <= 0);
				$('#mr_tee_view_move_right').prop('disabled', false);
			});

			// Move Active View Right
			$(document).on('click', '#mr_tee_view_move_right', function(e) {
				e.preventDefault();
				const idx = self.previewViews.findIndex(v => v.id === self.activeViewId);
				if (idx < 0 || idx >= self.previewViews.length - 1) return;
				const tmp = self.previewViews[idx + 1];
				self.previewViews[idx + 1] = self.previewViews[idx];
				self.previewViews[idx] = tmp;
				self.renderViewsBar();
				self.syncHiddenMultiViewJson();
				$('#mr_tee_view_move_left').prop('disabled', false);
				$('#mr_tee_view_move_right').prop('disabled', idx + 1 >= self.previewViews.length - 1);
			});

			// Delete Active View (never delete the last remaining view)
			$(document).on('click', '#mr_tee_view_delete_btn', function(e) {
				e.preventDefault();
				if (self.previewViews.length <= 1) return;
				const idx = self.previewViews.findIndex(v => v.id === self.activeViewId);
				if (idx < 0) return;

				const victim = self.previewViews[idx];
				if (!confirm(`Supprimer la vue « ${victim.label || victim.id} » ?`)) {
					return;
				}

				self.previewViews.splice(idx, 1);

				// Clean deleted view key out of field placements maps
				const fieldCount = $('.mr-tee-field-box').length;
				for (let i = 0; i < fieldCount; i++) {
					const placements = self.getFieldPlacements(i);
					if (placements && Object.prototype.hasOwnProperty.call(placements, victim.id)) {
						delete placements[victim.id];
						self.setFieldPlacements(i, placements);
					}
				}

				const nextView = self.previewViews[Math.max(0, Math.min(idx, self.previewViews.length - 1))];
				self.renderViewsBar();
				self.renderSidesBar();
				self.syncHiddenMultiViewJson();
				self.setViewUploadStatus('');
				if (nextView) {
					self.setActiveView(nextView.id);
				}
			});

			// Per-field Side Selector Change
			$(document).on('change', '.mr-tee-field-side-select', function() {
				const rawIdx = $(this).attr('data-field-index') || $(this).closest('.mr-tee-field-panel').attr('data-field-index');
				const idx = parseInt(rawIdx, 10);
				if (Number.isNaN(idx)) return;

				const newSideId = $(this).val();
				if (!newSideId) return;

				// Update data-side-id on box, pill, and panel
				$(`#mr-tee-field-box-${idx}`).attr('data-side-id', newSideId);
				$(`.mr-tee-field-pill[data-field-index="${idx}"]`).attr('data-side-id', newSideId);
				$(`#mr-tee-field-panel-${idx}`).attr('data-side-id', newSideId);

				// Seed placements for all views belonging to the newly selected side
				const currentCoords = self.getFieldCurrentCoords(idx);
				const placements = self.getFieldPlacements(idx);
				self.previewViews.forEach(v => {
					if (v.side_id === newSideId && !placements[v.id]) {
						placements[v.id] = { ...currentCoords };
					}
				});
				self.setFieldPlacements(idx, placements);
				self.renderSidesBar();

				// Switch to first view of newly chosen side if active view belongs to a different side
				const activeView = self.getActiveView();
				if (!activeView || activeView.side_id !== newSideId) {
					let targetView = self.getPrimaryViewForSide(newSideId, true);
					if (!targetView) {
						const side = self.getSideById(newSideId);
						targetView = {
							id: self.generateUniqueViewId(`view_${newSideId}`),
							label: side ? (side.label || newSideId) : newSideId,
							side_id: newSideId,
							view_role: 'full',
							image_id: 0,
							image_url: '',
							blend_mode: activeView ? (activeView.blend_mode || 'normal') : 'normal',
							is_cart_preview: true,
						};
						self.previewViews.push(targetView);
						placements[targetView.id] = { ...currentCoords };
						self.setFieldPlacements(idx, placements);
						self.renderViewsBar();
					}
					self.setActiveView(targetView.id, { preferredFieldIndex: idx });
				} else {
					$(`#mr-tee-field-box-${idx}`).show().removeClass('is-other-side');
					$(`.mr-tee-field-pill[data-field-index="${idx}"]`).removeClass('is-other-side');
					self.updateEmptySideNotice(newSideId, false);
					self.saveFieldPlacementForActiveView(idx);
					self.renderBoxPreview(idx);
					self.recalculateDimensions();
				}
			});

			// Flush all multi-view JSON + primary box coords right before WordPress post form submit (Classic & Block Editor)
			$(document).on('submit.mrTeeMultiView', 'form#post, form.metabox-location-normal', function() {
				self.syncMultiViewInputs(true);
			});
			$(document).on('mousedown.mrTeeMultiView click.mrTeeMultiView', '#publish, #save-post, .editor-post-publish-button, .editor-post-save-draft', function() {
				self.syncMultiViewInputs(true);
			});

			// Activate initial view
			this.setActiveView(this.activeViewId, { isInitial: true });
			this._multiviewInitialized = true;
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
