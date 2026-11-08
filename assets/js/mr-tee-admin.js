/**
 * Mr. Tee Editor - Admin Orchestrator & Visual Stage Drag/Resize
 *
 * Manages the engine competitor selector, tab switching, and interactive
 * placement of the Editor Area box (`box_left`, `box_top`, `box_width`, `box_height`)
 * over the garment mockup with real-time SVG preview.
 *
 * @package MrTeeEditor\Assets
 */

(function($) {
	'use strict';

	class MrTeeAdmin {
		constructor() {
			this.data = window.mrTeeAdminData || {};
			this.config = this.data.config || {};
			this.$wrap = $('.mr-tee-admin-wrap');
			this.$box = $('#mr-tee-editor-box');
			this.$canvasWrap = $('#mr-tee-stage-canvas-wrap');
			this.$previewHolder = $('#mr-tee-admin-svg-preview');

			this.init();
		}

		init() {
			this.bindEngineSelector();
			this.bindTabs();
			this.bindInteractiveBox();
			this.bindInputs();
			this.bindPresetReset();
			this.renderAdminPreview();
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
			$('.mr-tee-tab-btn').on('click', function() {
				const tabId = $(this).data('tab');
				$('.mr-tee-tab-btn').removeClass('is-active');
				$(this).addClass('is-active');

				$('.mr-tee-tab-pane').removeClass('is-active');
				$('#' + tabId).addClass('is-active');
			});
		}

		/**
		 * Interactive Red-Dashed Box Drag & Resize.
		 */
		bindInteractiveBox() {
			if (!this.$box.length || !this.$canvasWrap.length) return;

			const self = this;
			let isDragging = false;
			let isResizing = false;
			let currentHandle = null;
			let startX, startY, startLeft, startTop, startWidth, startHeight;

			// Dragging the box
			this.$box.on('mousedown', function(e) {
				if ($(e.target).hasClass('mr-tee-handle')) return;
				e.preventDefault();

				isDragging = true;
				startX = e.clientX;
				startY = e.clientY;

				const wrapRect = self.$canvasWrap[0].getBoundingClientRect();
				const boxRect = self.$box[0].getBoundingClientRect();

				startLeft = (boxRect.left - wrapRect.left) / wrapRect.width * 100;
				startTop = (boxRect.top - wrapRect.top) / wrapRect.height * 100;

				$(document).on('mousemove.mrTeeDrag', function(moveEvent) {
					if (!isDragging) return;
					const dx = (moveEvent.clientX - startX) / wrapRect.width * 100;
					const dy = (moveEvent.clientY - startY) / wrapRect.height * 100;

					let newLeft = Math.max(0, Math.min(100 - parseFloat($('#mr_tee_box_width').val() || 30), startLeft + dx));
					let newTop = Math.max(0, Math.min(100 - parseFloat($('#mr_tee_box_height').val() || 30), startTop + dy));

					newLeft = Math.round(newLeft * 10) / 10;
					newTop = Math.round(newTop * 10) / 10;

					self.updateBoxPosition(newLeft, newTop);
				});

				$(document).on('mouseup.mrTeeDrag', function() {
					isDragging = false;
					$(document).off('.mrTeeDrag');
				});
			});

			// Resizing the box via handles
			this.$box.find('.mr-tee-handle').on('mousedown', function(e) {
				e.preventDefault();
				e.stopPropagation();

				isResizing = true;
				currentHandle = $(this).data('handle');
				startX = e.clientX;
				startY = e.clientY;

				const wrapRect = self.$canvasWrap[0].getBoundingClientRect();
				const boxRect = self.$box[0].getBoundingClientRect();

				startLeft = (boxRect.left - wrapRect.left) / wrapRect.width * 100;
				startTop = (boxRect.top - wrapRect.top) / wrapRect.height * 100;
				startWidth = boxRect.width / wrapRect.width * 100;
				startHeight = boxRect.height / wrapRect.height * 100;

				$(document).on('mousemove.mrTeeResize', function(moveEvent) {
					if (!isResizing) return;
					const dx = (moveEvent.clientX - startX) / wrapRect.width * 100;
					const dy = (moveEvent.clientY - startY) / wrapRect.height * 100;

					let newW = startWidth;
					let newH = startHeight;
					let newL = startLeft;
					let newT = startTop;

					if (currentHandle.includes('e')) newW = Math.max(10, Math.min(100 - startLeft, startWidth + dx));
					if (currentHandle.includes('s')) newH = Math.max(10, Math.min(100 - startTop, startHeight + dy));
					if (currentHandle.includes('w')) {
						newW = Math.max(10, startWidth - dx);
						newL = startLeft + dx;
					}
					if (currentHandle.includes('n')) {
						newH = Math.max(10, startHeight - dy);
						newT = startTop + dy;
					}

					newW = Math.round(newW * 10) / 10;
					newH = Math.round(newH * 10) / 10;
					newL = Math.round(newL * 10) / 10;
					newT = Math.round(newT * 10) / 10;

					self.updateBoxBounds(newL, newT, newW, newH);
				});

				$(document).on('mouseup.mrTeeResize', function() {
					isResizing = false;
					$(document).off('.mrTeeResize');
				});
			});
		}

		/**
		 * Numeric Inputs Binding (two-way sync with interactive box).
		 */
		bindInputs() {
			const self = this;
			$('.mr-tee-coord-input').on('input change', function() {
				const l = parseFloat($('#mr_tee_box_left').val()) || 26.0;
				const t = parseFloat($('#mr_tee_box_top').val()) || 23.0;
				const w = parseFloat($('#mr_tee_box_width').val()) || 48.0;
				const h = parseFloat($('#mr_tee_box_height').val()) || 54.0;

				self.$box.css({
					left: l + '%',
					top: t + '%',
					width: w + '%',
					height: h + '%',
				});
			});
		}

		updateBoxPosition(left, top) {
			$('#mr_tee_box_left').val(left);
			$('#mr_tee_box_top').val(top);
			this.$box.css({ left: left + '%', top: top + '%' });
		}

		updateBoxBounds(left, top, width, height) {
			$('#mr_tee_box_left').val(left);
			$('#mr_tee_box_top').val(top);
			$('#mr_tee_box_width').val(width);
			$('#mr_tee_box_height').val(height);

			this.$box.css({
				left: left + '%',
				top: top + '%',
				width: width + '%',
				height: height + '%',
			});
		}

		/**
		 * Reset coordinates to reference Monsieur TSHIRT defaults for selected preset.
		 */
		bindPresetReset() {
			const self = this;
			$('#mr_tee_template_preset').on('change', function() {
				const preset = $(this).val();
				self.applyPresetCoords(preset);
				self.renderAdminPreview();
			});

			$('#mr_tee_btn-reset-coords').on('click', function() {
				const preset = $('#mr_tee_template_preset').val() || 'super_mushroom_24987';
				self.applyPresetCoords(preset);
				self.renderAdminPreview();
			});
		}

		applyPresetCoords(preset) {
			switch (preset) {
				case 'multicolor_embroidered_34225':
					this.updateBoxBounds(31.0, 32.0, 38.0, 36.0);
					$('#mr_tee_perso_scale').val(0.42);
					$('#mr_tee_perso_tx').val(0.0);
					$('#mr_tee_perso_ty').val(0.0);
					break;
				case 'leopard_pattern_mask_24000':
					this.updateBoxBounds(28.0, 28.0, 44.0, 48.0);
					$('#mr_tee_perso_scale').val(0.44);
					$('#mr_tee_perso_tx').val(0.0);
					$('#mr_tee_perso_ty').val(0.0);
					break;
				case 'vintage_date_names_34359':
					this.updateBoxBounds(30.0, 26.0, 40.0, 48.0);
					$('#mr_tee_perso_scale').val(0.40);
					$('#mr_tee_perso_tx').val(0.0);
					$('#mr_tee_perso_ty').val(0.0);
					break;
				case 'super_mushroom_24987':
				default:
					this.updateBoxBounds(26.0, 23.0, 48.0, 54.0);
					$('#mr_tee_perso_scale').val(0.34);
					$('#mr_tee_perso_tx').val(1.54);
					$('#mr_tee_perso_ty').val(-0.49);
					break;
			}
		}

		/**
		 * Render live SVG inside the admin Editor Area box according to active preset.
		 */
		renderAdminPreview() {
			if (!this.$previewHolder.length) return;

			const preset = $('#mr_tee_template_preset').val() || 'super_mushroom_24987';
			let innerSvg = '';

			if (preset === 'multicolor_embroidered_34225') {
				innerSvg = `
					<text x="50" y="24" font-family="'Bebas Neue', Arial Black, sans-serif" font-size="18" text-anchor="middle" dominant-baseline="central" filter="url(#admin-shadow)">
						<tspan fill="#e1523d">P</tspan><tspan fill="#ebb042">A</tspan><tspan fill="#6aa6a3">P</tspan><tspan fill="#395c6b">A</tspan>
					</text>
					<text x="50" y="44" font-family="'Bebas Neue', Arial Black, sans-serif" font-size="18" text-anchor="middle" dominant-baseline="central" filter="url(#admin-shadow)">
						<tspan fill="#e6c1a6">D</tspan><tspan fill="#4d4038">E</tspan>
					</text>
					<text x="50" y="64" font-family="'Bebas Neue', Arial Black, sans-serif" font-size="18" text-anchor="middle" dominant-baseline="central" filter="url(#admin-shadow)">
						<tspan fill="#99b39a">J</tspan><tspan fill="#2f3e46">U</tspan><tspan fill="#a8dadc">L</tspan><tspan fill="#e63946">E</tspan><tspan fill="#e1523d">S</tspan>
					</text>
					<text x="50" y="84" font-family="'Bebas Neue', Arial Black, sans-serif" font-size="18" text-anchor="middle" dominant-baseline="central" filter="url(#admin-shadow)">
						<tspan fill="#ebb042">&amp; </tspan><tspan fill="#6aa6a3">L</tspan><tspan fill="#395c6b">É</tspan><tspan fill="#e6c1a6">O</tspan>
					</text>
				`;
			} else if (preset === 'leopard_pattern_mask_24000') {
				innerSvg = `
					<mask id="admin-leopard-mask" maskUnits="userSpaceOnUse">
						<text x="50" y="40" font-family="'Arial Black', Impact, sans-serif" font-size="24" font-weight="900" text-anchor="middle" dominant-baseline="central" fill="#ffffff">MAMAN</text>
					</mask>
					<image href="/wp-content/plugins/mr_tee_editor/assets/images/leopard-pattern.png" x="15" y="25" width="70" height="30" preserveAspectRatio="xMidYMid slice" mask="url(#admin-leopard-mask)" filter="url(#admin-shadow)" />
					<text x="50" y="70" font-family="'Abril Fatface', Georgia, serif" font-size="13" text-anchor="middle" dominant-baseline="central" fill="#934a32" filter="url(#admin-shadow)">
						Léo, Chloé, Arthur
					</text>
				`;
			} else if (preset === 'vintage_date_names_34359') {
				innerSvg = `
					<text x="50" y="26" font-family="'Pacifico', cursive" font-size="22" text-anchor="middle" dominant-baseline="central" fill="#111827" filter="url(#admin-shadow)">
						Maman
					</text>
					<text x="50" y="42" font-family="'Cinzel Decorative', serif" font-size="9" font-weight="bold" letter-spacing="0.1em" text-anchor="middle" dominant-baseline="central" fill="#111827" filter="url(#admin-shadow)">
						DEPUIS 2021
					</text>
					<path d="M12 21.35l-1.45-1.32C5.4 15.36 2 12.28 2 8.5 2 5.42 4.42 3 7.5 3c1.74 0 3.41.81 4.5 2.09C13.09 3.81 14.76 3 16.5 3 19.58 3 22 5.42 22 8.5c0 3.78-3.4 6.86-8.55 11.54L12 21.35z" fill="#111827" transform="translate(47.5, 49) scale(0.22)" filter="url(#admin-shadow)" />
					<text x="50" y="66" font-family="'Abril Fatface', Georgia, serif" font-size="12" text-anchor="middle" dominant-baseline="central" fill="#111827" filter="url(#admin-shadow)">
						Oscar
					</text>
					<text x="50" y="77" font-family="'Abril Fatface', Georgia, serif" font-size="12" text-anchor="middle" dominant-baseline="central" fill="#111827" filter="url(#admin-shadow)">
						Anna
					</text>
					<text x="50" y="88" font-family="'Abril Fatface', Georgia, serif" font-size="12" text-anchor="middle" dominant-baseline="central" fill="#111827" filter="url(#admin-shadow)">
						Gaby
					</text>
				`;
			} else {
				innerSvg = `
					<text x="50" y="24" font-family="SuperMario256, Arial Black, sans-serif" font-size="15" text-anchor="middle" dominant-baseline="central" stroke="#000" stroke-width="0.7" style="paint-order:stroke fill;" filter="url(#admin-shadow)">
						<tspan fill="#209cee">S</tspan><tspan fill="#ffdd57">U</tspan><tspan fill="#ff3860">P</tspan><tspan fill="#23d160">E</tspan><tspan fill="#ffdd57">R</tspan>
					</text>
					<text x="50" y="44" font-family="SuperMario256, Arial Black, sans-serif" font-size="15" text-anchor="middle" dominant-baseline="central" stroke="#000" stroke-width="0.7" style="paint-order:stroke fill;" filter="url(#admin-shadow)">
						<tspan fill="#ff3860">P</tspan><tspan fill="#209cee">A</tspan><tspan fill="#ffdd57">P</tspan><tspan fill="#23d160">A</tspan>
					</text>
					<circle cx="40" cy="70" r="8" fill="#e52521" stroke="#000" stroke-width="0.8" />
					<text x="40" y="86" font-family="Arial, sans-serif" font-size="4" font-weight="bold" text-anchor="middle">Léo</text>
					<circle cx="60" cy="70" r="8" fill="#43b047" stroke="#000" stroke-width="0.8" />
					<text x="60" y="86" font-family="Arial, sans-serif" font-size="4" font-weight="bold" text-anchor="middle">Emma</text>
				`;
			}

			const svg = `
				<svg viewBox="0 0 100 100" width="100%" height="100%" style="overflow:visible;">
					<defs>
						<filter id="admin-shadow">
							<feDropShadow dx="0" dy="0.8" stdDeviation="0.4" flood-color="#000000" flood-opacity="0.35" />
						</filter>
					</defs>
					${innerSvg}
				</svg>
			`;

			this.$previewHolder.html(svg);
		}
	}

	$(document).ready(function() {
		new MrTeeAdmin();
	});

})(jQuery);
