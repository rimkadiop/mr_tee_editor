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
			this.renderAllBoxesPreviews();
		}

		/**
		 * Engine Competitor Selector Radio Buttons.
		 */
		bindEngineSelector() {
			$('input[name="mr_tee_engine_mode"]').on('change', function() {
				const val = $(this).val();
				$('.mr-tee-engine-option').removeClass('is-selected');
				$(this).closest('.mr-tee-engine-option').addClass('is-selected');

				if (val === 'mr_tee_editor') {
					$('#mr-tee-settings-panel').slideDown(200);
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
				const idx = parseInt($(this).data('field-index'), 10);
				self.setActiveField(idx);
			});

			// Direct click on box in stage
			$(document).on('mousedown', '.mr-tee-field-box', function(e) {
				if ($(e.target).hasClass('mr-tee-handle')) return;
				const idx = parseInt($(this).data('field-index'), 10);
				if (self.activeFieldIndex !== idx) {
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
				if ($(e.target).hasClass('mr-tee-handle')) return;
				e.preventDefault();

				currentIdx = parseInt($(this).data('field-index'), 10);
				const $box = $(this);
				const wrapRect = self.$canvasWrap[0].getBoundingClientRect();
				const boxRect = $box[0].getBoundingClientRect();

				isDragging = true;
				startX = e.clientX;
				startY = e.clientY;

				startLeft = (boxRect.left - wrapRect.left) / wrapRect.width * 100;
				startTop = (boxRect.top - wrapRect.top) / wrapRect.height * 100;

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
			$(document).on('mousedown', '.mr-tee-field-box.is-active .mr-tee-handle', function(e) {
				e.preventDefault();
				e.stopPropagation();

				const $box = $(this).closest('.mr-tee-field-box');
				currentIdx = parseInt($box.data('field-index'), 10);
				currentHandle = $(this).data('handle');

				const wrapRect = self.$canvasWrap[0].getBoundingClientRect();
				const boxRect = $box[0].getBoundingClientRect();

				isResizing = true;
				startX = e.clientX;
				startY = e.clientY;

				startLeft = (boxRect.left - wrapRect.left) / wrapRect.width * 100;
				startTop = (boxRect.top - wrapRect.top) / wrapRect.height * 100;
				startWidth = boxRect.width / wrapRect.width * 100;
				startHeight = boxRect.height / wrapRect.height * 100;

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
		}

		updateBoxPosition(idx, left, top) {
			$(`#mr_tee_field_left_${idx}`).val(left);
			$(`#mr_tee_field_top_${idx}`).val(top);
			$(`#mr-tee-field-box-${idx}`).css({ left: left + '%', top: top + '%' });
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
			$(`.mr-tee-field-pill[data-field-index="${idx}"] .mr-tee-pill-dims`).text(`${width}×${height}%`);

			// Re-render preview text inside this box
			this.renderBoxPreview(idx);
		}

		/**
		 * Two-Way Input Synchronization.
		 */
		bindInputSync() {
			const self = this;

			// Coords Inputs
			$(document).on('input change', '.mr-tee-field-coord', function() {
				const idx = parseInt($(this).data('field-index'), 10);
				const l = parseFloat($(`#mr_tee_field_left_${idx}`).val()) || 0;
				const t = parseFloat($(`#mr_tee_field_top_${idx}`).val()) || 0;
				const w = parseFloat($(`#mr_tee_field_width_${idx}`).val()) || 40;
				const h = parseFloat($(`#mr_tee_field_height_${idx}`).val()) || 8.5;

				$(`#mr-tee-field-box-${idx}`).css({
					left: l + '%',
					top: t + '%',
					width: w + '%',
					height: h + '%',
				});

				$(`.mr-tee-field-pill[data-field-index="${idx}"] .mr-tee-pill-dims`).text(`${w}×${h}%`);
				self.renderBoxPreview(idx);
			});

			// Label Input
			$(document).on('input change', '.mr-tee-field-label-input', function() {
				const idx = parseInt($(this).data('field-index'), 10);
				const val = $(this).val() || `Champ ${idx + 1}`;
				$(`.mr-tee-field-pill[data-field-index="${idx}"] .mr-tee-pill-label`).text(val);
				$(`#mr-tee-field-box-${idx} .mr-tee-box-badge`).text(`${idx + 1}. ${val}`);
				$(`#mr-tee-field-panel-${idx} .mr-tee-panel-title`).text(`Champ #${idx + 1} : ${val}`);
			});

			// Text & Font Inputs
			$(document).on('input change', '.mr-tee-field-text-input, .mr-tee-field-font-select', function() {
				const idx = parseInt($(this).data('field-index'), 10);
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
						style="left: ${newLeft}%; top: ${newTop}%; width: ${newWidth}%; height: ${newHeight}%; --field-color: ${color};"
					>
						<span class="mr-tee-box-badge" style="background: ${color};">
							${newIdx + 1}. ${fieldLabel}
						</span>
						<div class="mr-tee-box-svg-holder" id="mr-tee-box-svg-${newIdx}"></div>
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
										<option value="super_mario">Super Mario</option>
										<option value="press_start">Arcade 8-Bit (Press Start 2P)</option>
										<option value="bungee">Bungee Pop</option>
										<option value="luckiest_guy">Luckiest Guy Comic</option>
										<option value="cocogoose">Cocogoose Block</option>
										<option value="bebas">Bebas Neue Brodé</option>
										<option value="pacifico">Pacifico Script Cursif</option>
										<option value="cinzel">Cinzel Décoratif</option>
										<option value="marker">Permanent Marker</option>
										<option value="abril">Abril Serif Élégant</option>
									</select>
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
			});
		}

		/**
		 * Delete a Field Rectangle.
		 */
		bindDeleteField() {
			const self = this;
			$(document).on('click', '.mr-tee-btn-delete-field', function() {
				const idx = parseInt($(this).data('field-index'), 10);
				const count = $('.mr-tee-field-box').length;
				if (count <= 1) return;

				if (!confirm('Voulez-vous vraiment supprimer ce rectangle et son champ de texte ?')) {
					return;
				}

				// Remove elements
				$(`.mr-tee-field-pill[data-field-index="${idx}"]`).remove();
				$(`#mr-tee-field-box-${idx}`).remove();
				$(`#mr-tee-field-panel-${idx}`).remove();

				// Re-index remaining elements
				self.reindexFields();
				self.setActiveField(0);
				self.renderAllBoxesPreviews();
			});
		}

		reindexFields() {
			const self = this;
			const $pills = $('.mr-tee-field-pill');
			const $boxes = $('.mr-tee-field-box');
			const $panels = $('.mr-tee-field-panel');

			$pills.each(function(i) {
				const color = FIELD_COLORS[i % FIELD_COLORS.length];
				$(this).attr('data-field-index', i);
				$(this).css('--pill-color', color);
				$(this).find('.mr-tee-pill-badge').text(i + 1).css('background', color);
			});

			$boxes.each(function(i) {
				const color = FIELD_COLORS[i % FIELD_COLORS.length];
				$(this).attr('id', `mr-tee-field-box-${i}`);
				$(this).attr('data-field-index', i);
				$(this).css('--field-color', color);
				$(this).find('.mr-tee-box-badge').css('background', color);
				$(this).find('.mr-tee-box-svg-holder').attr('id', `mr-tee-box-svg-${i}`);
				$(this).find('.mr-tee-handle').css('border-color', color);
			});

			$panels.each(function(i) {
				const color = FIELD_COLORS[i % FIELD_COLORS.length];
				$(this).attr('id', `mr-tee-field-panel-${i}`);
				$(this).attr('data-field-index', i);
				$(this).find('.mr-tee-panel-indicator').css('background', color);
				$(this).find('.mr-tee-field-label-input').attr('name', `mr_tee_config[text_lines][${i}][label]`).attr('data-field-index', i);
				$(this).find('.mr-tee-field-text-input').attr('name', `mr_tee_config[text_lines][${i}][default_text]`).attr('data-field-index', i);
				$(this).find('.mr-tee-field-font-select').attr('name', `mr_tee_config[text_lines][${i}][font]`).attr('data-field-index', i);
				$(this).find('.mr-tee-field-color-mode').attr('name', `mr_tee_config[text_lines][${i}][color_mode]`).attr('data-field-index', i);
				$(this).find('.mr-tee-field-max-chars').attr('name', `mr_tee_config[text_lines][${i}][max_chars]`).attr('data-field-index', i);
				$(this).find('.mr-tee-field-font-pick').attr('name', `mr_tee_config[text_lines][${i}][allow_font_pick]`).attr('data-field-index', i);
				$(this).find('.mr-tee-field-multiline').attr('name', `mr_tee_config[text_lines][${i}][multiline]`).attr('data-field-index', i);
				$(this).find('.mr-tee-field-warning').attr('name', `mr_tee_config[text_lines][${i}][warning_unedited]`).attr('data-field-index', i);
				$(this).find('.mr-tee-field-coord-left').attr('id', `mr_tee_field_left_${i}`).attr('name', `mr_tee_config[text_lines][${i}][box_left]`).attr('data-field-index', i);
				$(this).find('.mr-tee-field-coord-top').attr('id', `mr_tee_field_top_${i}`).attr('name', `mr_tee_config[text_lines][${i}][box_top]`).attr('data-field-index', i);
				$(this).find('.mr-tee-field-coord-width').attr('id', `mr_tee_field_width_${i}`).attr('name', `mr_tee_config[text_lines][${i}][box_width]`).attr('data-field-index', i);
				$(this).find('.mr-tee-field-coord-height').attr('id', `mr_tee_field_height_${i}`).attr('name', `mr_tee_config[text_lines][${i}][box_height]`).attr('data-field-index', i);
				$(this).find('.mr-tee-btn-delete-field').attr('data-field-index', i);
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

		renderBoxPreview(idx) {
			const $holder = $(`#mr-tee-box-svg-${idx}`);
			if (!$holder.length) return;

			const textVal = $(`input[name="mr_tee_config[text_lines][${idx}][default_text]"]`).val() || `Texte ${idx + 1}`;
			const fontId = $(`select[name="mr_tee_config[text_lines][${idx}][font]"]`).val() || 'super_mario';

			let fontFamily = `'Super Mario Wii', 'SuperMario256', Arial Black, sans-serif`;
			let strokeColor = '#000000';
			let strokeWidth = '0.7';

			if (fontId.includes('press_start')) {
				fontFamily = `'Press Start 2P', monospace`;
				strokeWidth = '0.4';
			} else if (fontId.includes('bungee')) {
				fontFamily = `'Bungee', sans-serif`;
			} else if (fontId.includes('bebas')) {
				fontFamily = `'Bebas Neue', Arial Black, sans-serif`;
				strokeColor = 'transparent';
			} else if (fontId.includes('pacifico')) {
				fontFamily = `'Pacifico', cursive`;
				strokeColor = 'transparent';
			} else if (fontId.includes('abril')) {
				fontFamily = `'Abril Fatface', Georgia, serif`;
				strokeColor = 'transparent';
			} else if (fontId.includes('cinzel')) {
				fontFamily = `'Cinzel Decorative', serif`;
				strokeColor = 'transparent';
			}

			// Render pure text scaled to fit inside this box
			const svg = `
				<svg viewBox="0 0 100 100" width="100%" height="100%" preserveAspectRatio="none" style="overflow:visible;">
					<text
						x="50"
						y="50"
						font-family="${fontFamily}"
						font-size="70"
						text-anchor="middle"
						dominant-baseline="central"
						fill="#ffffff"
						stroke="${strokeColor}"
						stroke-width="${strokeWidth}"
						textLength="92"
						lengthAdjust="spacingAndGlyphs"
						style="paint-order: stroke fill;"
					>${this.escapeXml(textVal)}</text>
				</svg>
			`;

			$holder.html(svg);
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

			const ratio = (h > 0) ? (w / h) : 1.0;

			let inchW = w / 2.54;
			let inchH = h / 2.54;
			if (unit === 'mm') {
				inchW = w / 25.4;
				inchH = h / 25.4;
			} else if (unit === 'in') {
				inchW = w;
				inchH = h;
			}

			const pxW = Math.round(inchW * dpi);
			const pxH = Math.round(inchH * dpi);

			// Update Live Badge
			$('#mr-tee-badge-ratio-val').text(`${Math.round(w * 10) / 10} : ${Math.round(h * 10) / 10} (${ratio.toFixed(3)})`);
			$('#mr-tee-badge-px-val').text(`${pxW} × ${pxH} px`);

			// Update TAB 4 pixel inputs
			$('#mr_tee_tab4_width_px').val(pxW);
			$('#mr_tee_tab4_height_px').val(pxH);

			// Update Stage Canvas Wrap aspect-ratio
			if (this.$canvasWrap.length) {
				if (isLocked) {
					this.$canvasWrap.addClass('is-aspect-locked');
					this.$canvasWrap.css('aspect-ratio', ratio.toString());
				} else {
					this.$canvasWrap.removeClass('is-aspect-locked');
					this.$canvasWrap.css('aspect-ratio', '');
				}
			}
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

	$(document).ready(function() {
		new MrTeeAdmin();
	});

})(jQuery);
