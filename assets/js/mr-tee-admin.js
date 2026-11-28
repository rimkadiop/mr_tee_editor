/**
 * Mr. Tee Editor - Admin Orchestrator & Multi-Rectangle Stage Configurator
 *
 * Manages the engine selector, 3-tab studio layout, physical dimensions & export summary,
 * multi-side / multi-view preview manager, font library, and GPU-accelerated Pointer Events
 * multi-rectangle stage configurator.
 *
 * @package MrTeeEditor\Assets
 */

(function($) {
	'use strict';

	const FIELD_COLORS = [
		'#2563eb', // Blue
		'#047857', // Emerald (WCAG AA)
		'#7c3aed', // Purple
		'#b45309', // Amber (WCAG AA)
		'#db2777', // Rose
		'#0e7490', // Cyan (WCAG AA)
		'#dc2626', // Red
		'#4f46e5', // Indigo
	];

	const DEFAULT_MULTI_PALETTE = ['#209cee', '#ffdd57', '#ff3860', '#23d160'];

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
			this._renderAllRaf = null;
			this._lastSubmitSyncTs = 0;
			this.allowedFonts = this.readInitialAllowedFonts();
			this.printSides = this.readInitialPrintSides();
			this.previewViews = this.readInitialPreviewViews();
			this.activeViewId = this.previewViews.length ? this.previewViews[0].id : 'view_1';

			// Track TTF fonts already rendered in @font-face CSS by Plugin::enqueue_admin_assets()
			this.seedInitiallyLoadedTtfFonts();

			this.init();
		}

		init() {
			this._bootstrapping = true;
			this._history = [];
			this._historyIdx = -1;
			this._failedSvgFetches = new Set();

			this.bindEngineSelector();
			this.bindTabs();
			this.bindFieldSwitcher();
			this.bindInteractiveBoxes();
			this.bindKeyboardNudge();
			this.bindCanvasMicroToolbar();
			this.bindInputSync();
			this.bindAddField();
			this.bindDuplicateField();
			this.bindDeleteField();
			this.bindFieldReorder();
			this.bindAiFieldControls();
			this.bindPresetApply();
			this.bindDimensionControls();
			this.bindMultiViewManager();
			this.bindFontUploader();
			this.bindProductAllowedFonts();
			this.bindFontLibraryCatalog();
			this.bindCustomFontDelete();
			this.bindRepeaterToggle();
			this.bindStagePreviewRefreshes();
			this.bindDirtyTracking();
			this.bindSubmitGuards();

			// Keep the engine fieldset / compact state in sync with the (possibly browser-restored) radio.
			const $checkedEngine = $('input[name="mr_tee_engine_mode"]:checked');
			if ($checkedEngine.length) {
				const isMrTee = $checkedEngine.val() === 'mr_tee_editor';
				$('#mr-tee-settings-fieldset').prop('disabled', !isMrTee);
				$('#mr-tee-engine-selector-box').toggleClass('is-compact', isMrTee);
			}

			// Sync initial color controls visibility & toolbar button state
			const count = $('.mr-tee-field-panel').length;
			for (let i = 0; i < count; i++) {
				this.syncFieldColorUi(i);
			}
			this.updateFieldToolbarState();
			this.renderExportSummary();
			this.syncFontLibraryCards();
			this.updateHistoryButtons();

			// Establish the undo baseline after the first layout pass, then start tracking real edits.
			setTimeout(() => {
				this._bootstrapping = false;
				this._history = [this.snapshotGeometry()];
				this._historyIdx = 0;
				this.updateHistoryButtons();
			}, 400);
		}

		/**
		 * Capture-phase `invalid` listener so keyboard-Enter submit never fails silently on a hidden tab.
		 */
		bindSubmitGuards() {
			const self = this;
			const form = document.getElementById('post');
			if (!form) return;
			form.addEventListener('invalid', function() {
				if (typeof self.ensureMetaboxInputsValid === 'function') {
					self.ensureMetaboxInputsValid();
				}
			}, true);
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

		round1(val) {
			return Math.round(val * 10) / 10;
		}

		clampBoxCoords(l, t, w, h) {
			let cw = Number.isFinite(w) ? w : 40;
			let ch = Number.isFinite(h) ? h : 10;
			let cl = Number.isFinite(l) ? l : 0;
			let ct = Number.isFinite(t) ? t : 0;

			cw = Math.max(2, Math.min(100, cw));
			ch = Math.max(2, Math.min(100, ch));
			cl = Math.max(0, Math.min(100 - cw, cl));
			ct = Math.max(0, Math.min(100 - ch, ct));

			return {
				l: this.round1(cl),
				t: this.round1(ct),
				w: this.round1(cw),
				h: this.round1(ch),
			};
		}

		syncBoxBadgePosition(idx, topPct, heightPct) {
			const boxEl = document.getElementById(`mr-tee-field-box-${idx}`);
			if (!boxEl) return;
			const t = Number.isFinite(topPct) ? topPct : (parseFloat((document.getElementById(`mr_tee_field_top_${idx}`) || {}).value) || 0);
			const h = Number.isFinite(heightPct) ? heightPct : (parseFloat((document.getElementById(`mr_tee_field_height_${idx}`) || {}).value) || 8.5);
			boxEl.classList.toggle('is-badge-bottom', t < 8);
			boxEl.classList.toggle('is-badge-inside', (t + h) > 92);
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
			} else if (Array.isArray(this.config.text_lines)) {
				const firstText = this.config.text_lines.find(tl => tl && tl.field_type !== 'image' && tl.field_type !== 'ai_image' && Array.isArray(tl.allowed_fonts));
				if (firstText) {
					firstText.allowed_fonts.forEach(fid => fid && initialIds.add(fid));
				}
			}
			if (Array.isArray(this.config.text_lines)) {
				this.config.text_lines.forEach(tl => {
					if (tl && tl.field_type !== 'image' && tl.field_type !== 'ai_image' && tl.font) initialIds.add(tl.font);
				});
			}
			initialIds.forEach(fid => this._loadedTtfFonts.add(fid));
		}

		ensureTtfFontLoaded(fontId, opts = {}) {
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

			const fontUrl = this.toSameOriginPath(fontDef.url).replace(/['"\\\n\r]/g, '');
			const format = String(fontDef.format || 'truetype').replace(/['"\\\n\r]/g, '');
			const families = [fontDef.family];
			if (Array.isArray(fontDef.aliases)) {
				fontDef.aliases.forEach(alias => {
					if (alias && !families.includes(alias)) families.push(alias);
				});
			}

			let css = '';
			families.forEach(fam => {
				if (fam) {
					const safeFam = String(fam).replace(/['"\\\n\r]/g, '');
					css += `@font-face { font-family: '${safeFam}'; src: url('${fontUrl}') format('${format}'); font-weight: normal; font-style: normal; font-display: swap; }\n`;
				}
			});
			styleEl.textContent += css;

			if (!opts.skipLoad && document.fonts && typeof document.fonts.load === 'function' && fontDef.family) {
				document.fonts.load(`100px "${String(fontDef.family).replace(/["\\]/g, '')}"`).then(() => {
					if (this._adminTextWidthCache) this._adminTextWidthCache.clear();
					document.querySelectorAll('.mr-tee-box-svg-holder').forEach(el => { el._glyphsKey = ''; });
					this.renderAllBoxesPreviews();
				}).catch(() => {});
			}
		}

		/**
		 * Lazily register @font-face rules for every TTF in the library so each "Aa" card
		 * previews its real typeface (the browser only downloads faces actually painted).
		 */
		ensureAllLibraryTtfFontsLoaded() {
			if (this._libraryFontsRegistered) return;
			this._libraryFontsRegistered = true;
			const fonts = (window.mrTeeAdminData && window.mrTeeAdminData.fonts) || {};
			Object.keys(fonts).forEach(fid => this.ensureTtfFontLoaded(fid, { skipLoad: true }));
		}

		/* =====================================================================
		 * Dirty indicator + geometry Undo / Redo history
		 * ===================================================================== */

		markDirty() {
			if (this._bootstrapping || this._restoringHistory) return;
			this._dirty = true;
			const badge = document.getElementById('mr-tee-dirty-badge');
			if (badge && badge.hasAttribute('hidden')) badge.removeAttribute('hidden');
		}

		clearDirty() {
			this._dirty = false;
			const badge = document.getElementById('mr-tee-dirty-badge');
			if (badge) badge.setAttribute('hidden', '');
		}

		bindDirtyTracking() {
			const self = this;
			$(document).on('input change', '.mr-tee-admin-wrap input, .mr-tee-admin-wrap select, .mr-tee-admin-wrap textarea', function(e) {
				const el = e.target;
				if (!el || el.id === 'mr_tee_font_lib_search' || el.hasAttribute('data-no-dirty')) return;
				if (el.type === 'file') return;
				self.markDirty();
			});
			$('form#post').on('submit.mrTeeDirty', function() {
				self.clearDirty();
			});
		}

		snapshotGeometry() {
			const count = $('.mr-tee-field-box').length;
			const fields = [];
			for (let i = 0; i < count; i++) {
				fields.push(this.getFieldCurrentCoords(i));
			}
			return { viewId: this.activeViewId, fields };
		}

		sameSnapshot(a, b) {
			return Boolean(a && b) && JSON.stringify(a) === JSON.stringify(b);
		}

		resetHistory() {
			this._history = [];
			this._historyIdx = -1;
			if (this._histTimer) { clearTimeout(this._histTimer); this._histTimer = null; }
			this.updateHistoryButtons();
		}

		ensureHistoryBase() {
			if (!this._history || !this._history.length) {
				this._history = [this.snapshotGeometry()];
				this._historyIdx = 0;
			}
		}

		scheduleHistoryCommit() {
			if (this._restoringHistory || this._bootstrapping) return;
			if (this._histTimer) clearTimeout(this._histTimer);
			this._histTimer = setTimeout(() => {
				this._histTimer = null;
				this.commitHistory();
			}, 180);
		}

		commitHistory() {
			if (this._restoringHistory) return;
			const snap = this.snapshotGeometry();
			if (!this._history || !this._history.length) {
				this._history = [snap];
				this._historyIdx = 0;
				this.updateHistoryButtons();
				return;
			}
			const top = this._history[this._historyIdx];
			if (this.sameSnapshot(top, snap)) return;
			this._history = this._history.slice(0, this._historyIdx + 1);
			this._history.push(snap);
			if (this._history.length > 40) this._history.shift();
			this._historyIdx = this._history.length - 1;
			this.markDirty();
			this.updateHistoryButtons();
		}

		updateHistoryButtons() {
			const canUndo = Boolean(this._history) && this._historyIdx > 0;
			const canRedo = Boolean(this._history) && this._historyIdx >= 0 && this._historyIdx < this._history.length - 1;
			$('.mr-tee-canvas-tool-btn[data-action="undo"]').prop('disabled', !canUndo);
			$('.mr-tee-canvas-tool-btn[data-action="redo"]').prop('disabled', !canRedo);
		}

		restoreSnapshot(snap) {
			if (!snap || snap.viewId !== this.activeViewId) return false;
			const count = $('.mr-tee-field-box').length;
			if (snap.fields.length !== count) return false;
			this._restoringHistory = true;
			try {
				for (let i = 0; i < count; i++) {
					this.applyFieldCoordsToDom(i, snap.fields[i]);
					this.saveFieldPlacementForActiveView(i);
				}
				this.recalculateDimensions();
				this._cachedStageRatio = 0;
				this.renderAllBoxesPreviews();
			} finally {
				this._restoringHistory = false;
			}
			this.markDirty();
			return true;
		}

		undo() {
			if (this._histTimer) { clearTimeout(this._histTimer); this._histTimer = null; this.commitHistory(); }
			if (!this._history || this._historyIdx <= 0) return;
			if (this.restoreSnapshot(this._history[this._historyIdx - 1])) {
				this._historyIdx--;
			}
			this.updateHistoryButtons();
		}

		redo() {
			if (!this._history || this._historyIdx < 0 || this._historyIdx >= this._history.length - 1) return;
			if (this.restoreSnapshot(this._history[this._historyIdx + 1])) {
				this._historyIdx++;
			}
			this.updateHistoryButtons();
		}

		/* ---- Live transform feedback (snap guides + HUD chip) ---- */

		showSnapGuides(showV, showH) {
			const v = document.getElementById('mr-tee-snap-guide-v');
			const h = document.getElementById('mr-tee-snap-guide-h');
			if (v) v.classList.toggle('is-visible', Boolean(showV));
			if (h) h.classList.toggle('is-visible', Boolean(showH));
		}

		showTransformHud(text) {
			const hud = document.getElementById('mr-tee-transform-hud');
			if (!hud) return;
			hud.textContent = text;
			hud.classList.add('is-visible');
		}

		hideTransformUi() {
			this.showSnapGuides(false, false);
			const hud = document.getElementById('mr-tee-transform-hud');
			if (hud) hud.classList.remove('is-visible');
		}

		/**
		 * Library catalog (Tab 2): search + type filters + card activation state.
		 */
		syncFontLibraryCards() {
			const allowed = new Set(this.allowedFonts || []);
			document.querySelectorAll('#mr-tee-fonts-library-grid .mr-tee-font-lib-card').forEach(card => {
				const on = allowed.has(card.getAttribute('data-font-id'));
				card.classList.toggle('is-allowed', on);
				card.setAttribute('data-is-allowed', on ? '1' : '0');
				const btn = card.querySelector('.mr-tee-font-lib-toggle');
				if (btn) btn.setAttribute('aria-pressed', on ? 'true' : 'false');
				const st = card.querySelector('.mr-tee-font-lib-state');
				if (st) st.textContent = on ? '✓ Active' : '+ Activer';
			});
			this.applyFontLibraryFilter();
		}

		applyFontLibraryFilter() {
			const cards = document.querySelectorAll('#mr-tee-fonts-library-grid .mr-tee-font-lib-card');
			if (!cards.length) return;
			const searchEl = document.getElementById('mr_tee_font_lib_search');
			const q = searchEl ? String(searchEl.value || '').trim().toLowerCase() : '';
			const activePill = document.querySelector('.mr-tee-filter-pill.is-active');
			const filter = activePill ? (activePill.getAttribute('data-filter') || 'all') : 'all';
			let visible = 0;
			cards.forEach(card => {
				const hay = card.getAttribute('data-search') || '';
				let show = !q || hay.indexOf(q) !== -1;
				if (show) {
					if (filter === 'svg') show = card.getAttribute('data-font-type') === 'svg';
					else if (filter === 'ttf') show = card.getAttribute('data-font-type') === 'ttf';
					else if (filter === 'active') show = card.getAttribute('data-is-allowed') === '1';
					else if (filter === 'custom') show = card.getAttribute('data-is-custom') === '1';
				}
				card.style.display = show ? '' : 'none';
				if (show) visible++;
			});
			const countEl = document.getElementById('mr-tee-library-visible-count');
			if (countEl) countEl.textContent = String(visible);
			const emptyEl = document.getElementById('mr-tee-library-empty');
			if (emptyEl) emptyEl.hidden = visible > 0;
		}

		bindFontLibraryCatalog() {
			const self = this;
			$(document).on('input', '#mr_tee_font_lib_search', function() {
				self.applyFontLibraryFilter();
			});
			$(document).on('click', '.mr-tee-filter-pill', function(e) {
				e.preventDefault();
				$('.mr-tee-filter-pill').removeClass('is-active').attr('aria-pressed', 'false');
				$(this).addClass('is-active').attr('aria-pressed', 'true');
				self.applyFontLibraryFilter();
			});
			$(document).on('click', '.mr-tee-font-lib-toggle', function(e) {
				e.preventDefault();
				const id = $(this).attr('data-font-id');
				if (!id) return;
				if (self.allowedFonts.includes(id)) {
					if (self.allowedFonts.length <= 1) {
						const $card = $(this).closest('.mr-tee-font-lib-card');
						$card.addClass('is-shaking');
						setTimeout(() => $card.removeClass('is-shaking'), 450);
						return;
					}
					self.allowedFonts = self.allowedFonts.filter(x => x !== id);
				} else {
					self.allowedFonts.push(id);
					self.ensureTtfFontLoaded(id);
				}
				if (typeof self.renderAllowedFontsList === 'function') {
					self.renderAllowedFontsList();
				}
			});
		}

		bindStagePreviewRefreshes() {
			const self = this;
			$('#mr-tee-stage-garment-img')
				.on('load', function() {
					self._cachedStageRatio = 0;
					self.recalculateDimensions();
					self.renderAllBoxesPreviews();
				})
				.on('error', function() {
					const src = $(this).attr('src');
					if (src && src.trim() !== '') {
						$(this).hide();
						$('#mr-tee-stage-empty-overlay').show();
						if (self.$canvasWrap.length) {
							self.$canvasWrap.addClass('is-no-image');
						}
					}
				});

			let resizeTimer = null;
			$(window).on('resize.mrTeeAdmin', function() {
				self._cachedStageRatio = 0;
				if (resizeTimer) clearTimeout(resizeTimer);
				resizeTimer = setTimeout(() => {
					self.renderAllBoxesPreviews();
				}, 120);
			});

			if (typeof document !== 'undefined' && document.fonts) {
				const refreshLoadedFonts = () => {
					if (self._adminTextWidthCache) self._adminTextWidthCache.clear();
					document.querySelectorAll('.mr-tee-box-svg-holder').forEach(el => {
						if (el._renderMode === 'ttf') el._glyphsKey = '';
					});
					self.renderAllBoxesPreviews();
				};
				if (document.fonts.ready && typeof document.fonts.ready.then === 'function') {
					document.fonts.ready.then(refreshLoadedFonts).catch(() => {});
				}
				if (typeof document.fonts.addEventListener === 'function') {
					document.fonts.addEventListener('loadingdone', refreshLoadedFonts);
				}
			}

			if (typeof window.ResizeObserver === 'function' && this.$canvasWrap && this.$canvasWrap[0]) {
				let prevW = 0;
				let prevH = 0;
				const ro = new window.ResizeObserver(entries => {
					const entry = entries && entries[0];
					if (!entry || !entry.contentRect) return;
					const w = entry.contentRect.width;
					const h = entry.contentRect.height;
					if (w > 20 && h > 20 && (Math.abs(w - prevW) > 0.5 || Math.abs(h - prevH) > 0.5)) {
						prevW = w;
						prevH = h;
						self._cachedStageRatio = 0;
						self.renderAllBoxesPreviews();
						self.updateFieldToolbarState();
					}
				});
				ro.observe(this.$canvasWrap[0]);
			}
		}

		/**
		 * Engine Mode Selector Radio Buttons + Disabled Fieldset Guard.
		 */
		bindEngineSelector() {
			const self = this;
			$('input[name="mr_tee_engine_mode"]').on('change', function() {
				const val = $(this).val();
				const isMrTee = val === 'mr_tee_editor';
				$('.mr-tee-engine-option').removeClass('is-selected');
				$(this).closest('.mr-tee-engine-option').addClass('is-selected');
				$('#mr-tee-engine-selector-box').toggleClass('is-compact', isMrTee);
				$('#mr-tee-settings-fieldset').prop('disabled', !isMrTee);

				if (isMrTee) {
					$('#mr-tee-settings-panel').slideDown(180, function() {
						self._cachedStageRatio = 0;
						self.renderAllBoxesPreviews();
						self.updateFieldToolbarState();
					});
				} else {
					$('#mr-tee-settings-panel').slideUp(180);
				}
				self.markDirty();
			});
		}

		/**
		 * 3-Tab Navigation & Quick-Jump Controls.
		 */
		bindTabs() {
			const self = this;

			const switchTab = (tabId) => {
				if (!tabId) return;
				$('.mr-tee-tab-btn').removeClass('is-active').attr('aria-selected', 'false');
				$(`.mr-tee-tab-btn[data-tab="${tabId}"]`).addClass('is-active').attr('aria-selected', 'true');

				$('.mr-tee-tab-pane').removeClass('is-active');
				$('#' + tabId).addClass('is-active');

				if (tabId === 'tab-stage') {
					self._cachedStageRatio = 0;
					self.renderAllBoxesPreviews();
					self.updateFieldToolbarState();
				} else if (tabId === 'tab-export') {
					self.renderExportSummary();
				} else if (tabId === 'tab-fonts') {
					self.ensureAllLibraryTtfFontsLoaded();
				}
			};
			self.switchTab = switchTab;

			$(document).on('click', '.mr-tee-btn-edit-side-dims', function(e) {
				e.preventDefault();
				const sideId = $(this).attr('data-side-id');
				switchTab('tab-stage');
				const view = sideId ? self.getPrimaryViewForSide(sideId, true) : null;
				if (view && typeof self.setActiveView === 'function') {
					self.setActiveView(view.id);
				}
				const $drawer = $('#mr-tee-dimension-toolbar');
				if (!$drawer.is(':visible')) {
					$('#mr-tee-toggle-dim-drawer').addClass('is-open').attr('aria-expanded', 'true');
					$drawer.slideDown(150);
				}
				const drawerEl = $drawer[0];
				if (drawerEl && typeof drawerEl.scrollIntoView === 'function') {
					drawerEl.scrollIntoView({ block: 'nearest', behavior: 'smooth' });
				}
			});

			$('.mr-tee-tab-btn').on('click', function() {
				switchTab($(this).data('tab'));
			});

			$(document).on('click', '#mr-tee-jump-to-fonts', function(e) {
				e.preventDefault();
				switchTab('tab-fonts');
			});

			$(document).on('click', '#mr-tee-btn-back-to-studio', function(e) {
				e.preventDefault();
				switchTab('tab-stage');
			});

			// Collapsible Format & Dimensions Drawer Toggle
			$(document).on('click', '#mr-tee-toggle-dim-drawer', function(e) {
				e.preventDefault();
				const $btn = $(this);
				const $drawer = $('#mr-tee-dimension-toolbar');
				const willOpen = !$drawer.is(':visible');
				$btn.toggleClass('is-open', willOpen).attr('aria-expanded', willOpen ? 'true' : 'false');
				$drawer.slideToggle(150);
			});
		}

		/**
		 * Advanced Module (2-Row Avatar Repeater) Visibility Toggle in Tab 3.
		 */
		bindRepeaterToggle() {
			const self = this;
			$(document).on('change', '#mr_tee_repeater_enabled', function() {
				const enabled = $(this).is(':checked');
				if (enabled) {
					const $max = $('#mr_tee_rep_max_items');
					const $def = $('#mr_tee_rep_default_count');
					if ($max.length && (parseInt($max.val(), 10) || 0) === 0) {
						$max.val(6);
						if ($def.length && (parseInt($def.val(), 10) || 0) === 0) {
							$def.val(2);
						}
					}
				}
				$('#mr-tee-repeater-body').stop(true, true)[enabled ? 'slideDown' : 'slideUp'](150);
				self.recalculateDimensions();
				self.renderExportSummary();
			});
		}

		/**
		 * Field Selection Switcher (Pills).
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
		}

		setActiveField(index) {
			this.activeFieldIndex = index;

			$('.mr-tee-field-pill').removeClass('is-active');
			$('.mr-tee-field-box').removeClass('is-active');
			$('.mr-tee-field-panel').removeClass('is-active');

			if (index >= 0) {
				$(`.mr-tee-field-pill[data-field-index="${index}"]`).addClass('is-active');
				$(`#mr-tee-field-box-${index}`).addClass('is-active');
				$(`#mr-tee-field-panel-${index}`).addClass('is-active');
			}

			this.updateFieldToolbarState();
		}

		hasActiveVisibleField() {
			if (this.activeFieldIndex < 0) return false;
			const $box = $(`#mr-tee-field-box-${this.activeFieldIndex}`);
			return Boolean($box.length && !$box.hasClass('is-other-side') && $box.css('display') !== 'none');
		}

		updateFieldToolbarState() {
			const total = $('.mr-tee-field-box').length;
			const idx = this.activeFieldIndex;
			const hasVisible = this.hasActiveVisibleField();

			$('#mr-tee-btn-field-move-left').prop('disabled', total <= 1 || idx <= 0);
			$('#mr-tee-btn-field-move-right').prop('disabled', total <= 1 || idx < 0 || idx >= total - 1);
			$('.mr-tee-canvas-tool-btn').not('[data-action="undo"], [data-action="redo"]').prop('disabled', !hasVisible);
			this.updateHistoryButtons();
		}

		/**
		 * Interactive Box Drag, 8-Handle Resize (Rotated-Aware), & Rotation via Pointer Events.
		 * Single-click on any box immediately activates AND starts dragging (no 2-click friction).
		 */
		bindInteractiveBoxes() {
			if (!this.$canvasWrap.length) return;

			const self = this;

			// 1. Single-Click Activate + Drag on ANY field box
			$(document).on('pointerdown', '.mr-tee-field-box', function(e) {
				if (e.pointerType === 'mouse' && e.button !== 0) return;
				if ($(e.target).hasClass('mr-tee-handle') || $(e.target).hasClass('mr-tee-handle-rot')) return;
				e.preventDefault();

				const boxEl = this;
				const currentIdx = parseInt(boxEl.getAttribute('data-field-index'), 10);
				if (Number.isNaN(currentIdx)) return;

				if (self.activeFieldIndex !== currentIdx) {
					self.setActiveField(currentIdx);
				}

				const wrapRect = self.getStageInnerRect();
				if (!wrapRect || !wrapRect.width || !wrapRect.height) return;

				const leftInput = document.getElementById(`mr_tee_field_left_${currentIdx}`);
				const topInput = document.getElementById(`mr_tee_field_top_${currentIdx}`);
				const widthInput = document.getElementById(`mr_tee_field_width_${currentIdx}`);
				const heightInput = document.getElementById(`mr_tee_field_height_${currentIdx}`);
				const rotInput = document.getElementById(`mr_tee_field_rotate_${currentIdx}`);

				const pointerId = e.pointerId;
				const startX = e.clientX;
				const startY = e.clientY;

				const startLeft = leftInput ? (parseFloat(leftInput.value) || 0) : 0;
				const startTop = topInput ? (parseFloat(topInput.value) || 0) : 0;
				const curW = widthInput ? (parseFloat(widthInput.value) || 40.0) : 40.0;
				const curH = heightInput ? (parseFloat(heightInput.value) || 8.5) : 8.5;
				const startRotDeg = rotInput ? (parseFloat(rotInput.value) || 0) : 0;

				let isDragging = true;
				let didMove = false;
				let latestLeft = startLeft;
				let latestTop = startTop;
				let pendingAlt = false;
				$(document).off('.mrTeeDrag');
				self.ensureHistoryBase();
				boxEl.classList.add('is-interacting');
				try { self.$canvasWrap[0].focus({ preventScroll: true }); } catch (focusErr) { /* noop */ }

				const SNAP_PCT = 1.2;
				const applyDragDelta = (clientX, clientY) => {
					if (Math.abs(clientX - startX) > 1 || Math.abs(clientY - startY) > 1) {
						didMove = true;
					}
					const dx = ((clientX - startX) / wrapRect.width) * 100;
					const dy = ((clientY - startY) / wrapRect.height) * 100;

					let newLeft = Math.max(0, Math.min(100 - curW, startLeft + dx));
					let newTop = Math.max(0, Math.min(100 - curH, startTop + dy));

					let snapV = false;
					let snapH = false;
					if (!pendingAlt) {
						if (Math.abs((newLeft + curW / 2) - 50) <= SNAP_PCT) {
							newLeft = Math.max(0, Math.min(100 - curW, 50 - curW / 2));
							snapV = true;
						}
						if (Math.abs((newTop + curH / 2) - 50) <= SNAP_PCT) {
							newTop = Math.max(0, Math.min(100 - curH, 50 - curH / 2));
							snapH = true;
						}
					}
					newLeft = self.round1(newLeft);
					newTop = self.round1(newTop);

					latestLeft = newLeft;
					latestTop = newTop;

					const dxPx = ((newLeft - startLeft) / 100) * wrapRect.width;
					const dyPx = ((newTop - startTop) / 100) * wrapRect.height;

					boxEl.style.transform = `translate3d(${dxPx.toFixed(2)}px, ${dyPx.toFixed(2)}px, 0) rotate(${startRotDeg}deg)`;
					if (leftInput) leftInput.value = newLeft;
					if (topInput) topInput.value = newTop;
					self.syncBoxBadgePosition(currentIdx, newTop, curH);
					self.showSnapGuides(snapV, snapH);
					if (didMove) self.showTransformHud(`X : ${newLeft.toFixed(1)}%  ·  Y : ${newTop.toFixed(1)}%`);
				};

				const cancelDrag = () => {
					if (!isDragging) return;
					isDragging = false;
					if (dragRaf) { cancelAnimationFrame(dragRaf); dragRaf = null; }
					$(document).off('.mrTeeDrag');
					self._cancelTransform = null;
					boxEl.classList.remove('is-interacting');
					boxEl.style.left = `${startLeft}%`;
					boxEl.style.top = `${startTop}%`;
					boxEl.style.transform = `rotate(${startRotDeg}deg)`;
					if (leftInput) leftInput.value = startLeft;
					if (topInput) topInput.value = startTop;
					self.syncBoxBadgePosition(currentIdx, startTop, curH);
					self.hideTransformUi();
				};
				self._cancelTransform = cancelDrag;

				let dragRaf = null;
				let pendingX = startX;
				let pendingY = startY;

				$(document).on('pointermove.mrTeeDrag', function(moveEvent) {
					if (!isDragging) return;
					if (pointerId !== undefined && moveEvent.pointerId !== undefined && moveEvent.pointerId !== pointerId) return;
					pendingX = moveEvent.clientX;
					pendingY = moveEvent.clientY;
					pendingAlt = Boolean(moveEvent.altKey);
					if (dragRaf) return;
					dragRaf = requestAnimationFrame(() => {
						dragRaf = null;
						if (!isDragging) return;
						applyDragDelta(pendingX, pendingY);
					});
				});

				$(document).on('pointerup.mrTeeDrag pointercancel.mrTeeDrag', function(upEvent) {
					if (!isDragging) return;
					if (pointerId !== undefined && upEvent.pointerId !== undefined && upEvent.pointerId !== pointerId) return;
					if (upEvent.type === 'pointercancel') {
						cancelDrag();
						return;
					}
					isDragging = false;
					self._cancelTransform = null;
					if (dragRaf) {
						cancelAnimationFrame(dragRaf);
						dragRaf = null;
						applyDragDelta(
							upEvent && upEvent.clientX !== undefined ? upEvent.clientX : pendingX,
							upEvent && upEvent.clientY !== undefined ? upEvent.clientY : pendingY
						);
					}
					$(document).off('.mrTeeDrag');
					self.hideTransformUi();

					boxEl.classList.remove('is-interacting');
					boxEl.style.left = `${latestLeft}%`;
					boxEl.style.top = `${latestTop}%`;
					boxEl.style.transform = `rotate(${startRotDeg}deg)`;
					if (leftInput) leftInput.value = latestLeft;
					if (topInput) topInput.value = latestTop;
					self.syncBoxBadgePosition(currentIdx, latestTop, curH);

					if (didMove) {
						self.saveFieldPlacementForActiveView(currentIdx);
						self.commitHistory();
						self.recalculateDimensions();
					}
				});
			});

			// 2. Resizing active box via 8 handles (NW/NE/SW/SE corners + N/S/E/W edges) with inverse-rotation math
			$(document).on('pointerdown', '.mr-tee-field-box.is-active .mr-tee-handle:not(.mr-tee-handle-rot)', function(e) {
				if (e.pointerType === 'mouse' && e.button !== 0) return;
				e.preventDefault();
				e.stopPropagation();

				const $box = $(this).closest('.mr-tee-field-box');
				const boxEl = $box[0];
				if (!boxEl) return;

				const currentIdx = parseInt(boxEl.getAttribute('data-field-index'), 10);
				const currentHandle = String($(this).data('handle') || '');
				const wrapRect = self.getStageInnerRect();
				if (!wrapRect || !wrapRect.width || !wrapRect.height) return;
				const stageRatio = self.getStageAspectRatio(wrapRect);

				const leftInput = document.getElementById(`mr_tee_field_left_${currentIdx}`);
				const topInput = document.getElementById(`mr_tee_field_top_${currentIdx}`);
				const widthInput = document.getElementById(`mr_tee_field_width_${currentIdx}`);
				const heightInput = document.getElementById(`mr_tee_field_height_${currentIdx}`);
				const rotInput = document.getElementById(`mr_tee_field_rotate_${currentIdx}`);
				const pillDimsEl = document.querySelector(`.mr-tee-field-pill[data-field-index="${currentIdx}"] .mr-tee-pill-dims`);

				const pointerId = e.pointerId;
				const startX = e.clientX;
				const startY = e.clientY;
				const startLeft = leftInput ? (parseFloat(leftInput.value) || 0) : 0;
				const startTop = topInput ? (parseFloat(topInput.value) || 0) : 0;
				const startWidth = widthInput ? (parseFloat(widthInput.value) || 40.0) : 40.0;
				const startHeight = heightInput ? (parseFloat(heightInput.value) || 8.5) : 8.5;

				const rotDeg = rotInput ? (parseFloat(rotInput.value) || 0) : 0;
				const rotRad = (rotDeg * Math.PI) / 180;
				const cosNeg = Math.cos(-rotRad);
				const sinNeg = Math.sin(-rotRad);
				const cosPos = Math.cos(rotRad);
				const sinPos = Math.sin(rotRad);
				const isRotated = Math.abs(rotDeg) > 0.01;
				const rotTxt = rotDeg !== 0 ? ` (${rotDeg}°)` : '';

				const startWPx = (startWidth / 100) * wrapRect.width;
				const startHPx = (startHeight / 100) * wrapRect.height;
				const startCxPx = ((startLeft + startWidth / 2) / 100) * wrapRect.width;
				const startCyPx = ((startTop + startHeight / 2) / 100) * wrapRect.height;
				const minWPx = 0.02 * wrapRect.width;
				const minHPx = 0.02 * wrapRect.height;

				let isResizing = true;
				let latestW = startWidth;
				let latestH = startHeight;
				$(document).off('.mrTeeResize');
				self.ensureHistoryBase();
				boxEl.classList.add('is-interacting');
				try { self.$canvasWrap[0].focus({ preventScroll: true }); } catch (focusErr) { /* noop */ }

				const MIN_PCT = 2;
				let lastValid = { l: startLeft, t: startTop, w: startWidth, h: startHeight };
				let didResize = false;
				const applyResizeDelta = (clientX, clientY) => {
					const dxPx = clientX - startX;
					const dyPx = clientY - startY;

					let newL = startLeft;
					let newT = startTop;
					let newW = startWidth;
					let newH = startHeight;

					if (!isRotated) {
						const dx = (dxPx / wrapRect.width) * 100;
						const dy = (dyPx / wrapRect.height) * 100;
						const rightEdge = startLeft + startWidth;
						const bottomEdge = startTop + startHeight;

						if (currentHandle.includes('e')) {
							newW = Math.max(MIN_PCT, Math.min(100 - startLeft, startWidth + dx));
						}
						if (currentHandle.includes('s')) {
							newH = Math.max(MIN_PCT, Math.min(100 - startTop, startHeight + dy));
						}
						if (currentHandle.includes('w')) {
							newL = Math.max(0, Math.min(Math.max(0, rightEdge - MIN_PCT), startLeft + dx));
							newW = Math.max(MIN_PCT, rightEdge - newL);
						}
						if (currentHandle.includes('n')) {
							newT = Math.max(0, Math.min(Math.max(0, bottomEdge - MIN_PCT), startTop + dy));
							newH = Math.max(MIN_PCT, bottomEdge - newT);
						}
					} else {
						// Transform screen pixel delta into local unrotated box axes by -rotRad
						const localDxPx = dxPx * cosNeg - dyPx * sinNeg;
						const localDyPx = dxPx * sinNeg + dyPx * cosNeg;

						let nextWPx = startWPx;
						let nextHPx = startHPx;
						let localCenterDxPx = 0;
						let localCenterDyPx = 0;

						if (currentHandle.includes('e')) {
							nextWPx = Math.max(minWPx, Math.min(wrapRect.width, startWPx + localDxPx));
							localCenterDxPx = (nextWPx - startWPx) / 2;
						} else if (currentHandle.includes('w')) {
							nextWPx = Math.max(minWPx, Math.min(wrapRect.width, startWPx - localDxPx));
							localCenterDxPx = -(nextWPx - startWPx) / 2;
						}

						if (currentHandle.includes('s')) {
							nextHPx = Math.max(minHPx, Math.min(wrapRect.height, startHPx + localDyPx));
							localCenterDyPx = (nextHPx - startHPx) / 2;
						} else if (currentHandle.includes('n')) {
							nextHPx = Math.max(minHPx, Math.min(wrapRect.height, startHPx - localDyPx));
							localCenterDyPx = -(nextHPx - startHPx) / 2;
						}

						// Rotate local center shift back into stage axes so the opposite edge stays visually anchored
						const worldCenterDxPx = localCenterDxPx * cosPos - localCenterDyPx * sinPos;
						const worldCenterDyPx = localCenterDxPx * sinPos + localCenterDyPx * cosPos;
						const nextCxPx = startCxPx + worldCenterDxPx;
						const nextCyPx = startCyPx + worldCenterDyPx;

						newW = (nextWPx / wrapRect.width) * 100;
						newH = (nextHPx / wrapRect.height) * 100;
						newL = ((nextCxPx - nextWPx / 2) / wrapRect.width) * 100;
						newT = ((nextCyPx - nextHPx / 2) / wrapRect.height) * 100;

						newW = Math.max(MIN_PCT, Math.min(100, newW));
						newH = Math.max(MIN_PCT, Math.min(100, newH));
						// Out of the platen: keep the last valid geometry so the anchored edge never drifts.
						if (newL < -0.05 || newT < -0.05 || (newL + newW) > 100.05 || (newT + newH) > 100.05) {
							if (lastValid) {
								newL = lastValid.l; newT = lastValid.t; newW = lastValid.w; newH = lastValid.h;
							} else {
								newL = Math.max(0, Math.min(100 - newW, newL));
								newT = Math.max(0, Math.min(100 - newH, newT));
							}
						}
					}

					newW = self.round1(newW);
					newH = self.round1(newH);
					newL = self.round1(newL);
					newT = self.round1(newT);

					if (newL + newW > 100) newW = Math.max(MIN_PCT, self.round1(100 - newL));
					if (newT + newH > 100) newH = Math.max(MIN_PCT, self.round1(100 - newT));
					lastValid = { l: newL, t: newT, w: newW, h: newH };
					didResize = true;
					self.showTransformHud(`${newW.toFixed(1)} × ${newH.toFixed(1)}%`);

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
					self.syncBoxBadgePosition(currentIdx, newT, newH);

					self.renderBoxPreview(currentIdx, { bw: newW, bh: newH, stageRatio });
				};

				let resizeRaf = null;
				let pendingX = startX;
				let pendingY = startY;

				$(document).on('pointermove.mrTeeResize', function(moveEvent) {
					if (!isResizing) return;
					if (pointerId !== undefined && moveEvent.pointerId !== undefined && moveEvent.pointerId !== pointerId) return;
					pendingX = moveEvent.clientX;
					pendingY = moveEvent.clientY;
					if (resizeRaf) return;
					resizeRaf = requestAnimationFrame(() => {
						resizeRaf = null;
						if (!isResizing) return;
						applyResizeDelta(pendingX, pendingY);
					});
				});

				const cancelResize = () => {
					if (!isResizing) return;
					isResizing = false;
					if (resizeRaf) { cancelAnimationFrame(resizeRaf); resizeRaf = null; }
					$(document).off('.mrTeeResize');
					self._cancelTransform = null;
					boxEl.classList.remove('is-interacting');
					boxEl.style.left = `${startLeft}%`;
					boxEl.style.top = `${startTop}%`;
					boxEl.style.width = `${startWidth}%`;
					boxEl.style.height = `${startHeight}%`;
					if (leftInput) leftInput.value = startLeft;
					if (topInput) topInput.value = startTop;
					if (widthInput) widthInput.value = startWidth;
					if (heightInput) heightInput.value = startHeight;
					if (pillDimsEl) pillDimsEl.textContent = `${startWidth}×${startHeight}%${rotTxt}`;
					self.syncBoxBadgePosition(currentIdx, startTop, startHeight);
					self.renderBoxPreview(currentIdx, { bw: startWidth, bh: startHeight, stageRatio });
					self.hideTransformUi();
				};
				self._cancelTransform = cancelResize;

				$(document).on('pointerup.mrTeeResize pointercancel.mrTeeResize', function(upEvent) {
					if (!isResizing) return;
					if (pointerId !== undefined && upEvent.pointerId !== undefined && upEvent.pointerId !== pointerId) return;
					if (upEvent.type === 'pointercancel') {
						cancelResize();
						return;
					}
					isResizing = false;
					self._cancelTransform = null;
					if (resizeRaf) {
						cancelAnimationFrame(resizeRaf);
						resizeRaf = null;
						applyResizeDelta(
							upEvent && upEvent.clientX !== undefined ? upEvent.clientX : pendingX,
							upEvent && upEvent.clientY !== undefined ? upEvent.clientY : pendingY
						);
					}
					$(document).off('.mrTeeResize');
					self.hideTransformUi();

					boxEl.classList.remove('is-interacting');
					if (didResize) {
						self.saveFieldPlacementForActiveView(currentIdx);
						self.commitHistory();
						self.renderBoxPreview(currentIdx, { bw: latestW, bh: latestH, stageRatio });
						self.recalculateDimensions();
					}
				});
			});

			// 3. Rotating active box via top rotation handle (Pointer Events, clean cardinal/45° snaps only)
			$(document).on('pointerdown', '.mr-tee-field-box.is-active .mr-tee-handle-rot', function(e) {
				if (e.pointerType === 'mouse' && e.button !== 0) return;
				e.preventDefault();
				e.stopPropagation();

				const $handle = $(this);
				const $box = $handle.closest('.mr-tee-field-box');
				const boxEl = $box[0];
				if (!boxEl) return;

				const currentIdx = parseInt(boxEl.getAttribute('data-field-index'), 10);
				const pointerId = e.pointerId;
				$(document).off('.mrTeeRot');
				self.ensureHistoryBase();
				$handle.addClass('is-rotating');
				boxEl.classList.add('is-interacting');
				try { self.$canvasWrap[0].focus({ preventScroll: true }); } catch (focusErr) { /* noop */ }

				const rotInput = document.getElementById(`mr_tee_field_rotate_${currentIdx}`);
				const widthInput = document.getElementById(`mr_tee_field_width_${currentIdx}`);
				const heightInput = document.getElementById(`mr_tee_field_height_${currentIdx}`);
				const pillDimsEl = document.querySelector(`.mr-tee-field-pill[data-field-index="${currentIdx}"] .mr-tee-pill-dims`);
				const curW = widthInput ? (parseFloat(widthInput.value) || 40.0) : 40.0;
				const curH = heightInput ? (parseFloat(heightInput.value) || 8.5) : 8.5;
				const startRot = rotInput ? (parseFloat(rotInput.value) || 0) : 0;
				let didRotate = false;

				const boxRect = boxEl.getBoundingClientRect();
				const centerX = (boxRect.left + boxRect.right) / 2;
				const centerY = (boxRect.top + boxRect.bottom) / 2;

				const paintRotation = (deg) => {
					if (rotInput) rotInput.value = deg;
					boxEl.style.transform = `rotate(${deg}deg)`;
					boxEl.style.transformOrigin = 'center center';
					if (pillDimsEl) {
						const rotTxt = deg !== 0 ? ` (${deg}°)` : '';
						pillDimsEl.textContent = `${curW}×${curH}%${rotTxt}`;
					}
				};

				const applyRotateDelta = (clientX, clientY) => {
					const dx = clientX - centerX;
					const dy = clientY - centerY;

					let deg = (Math.atan2(dy, dx) * 180 / Math.PI) + 90;
					while (deg > 180) deg -= 360;
					while (deg < -180) deg += 360;

					// Clean magnetic snaps at cardinal & 45° angles only
					const snapAngles = [0, 45, -45, 90, -90, 180, -180];
					for (let si = 0; si < snapAngles.length; si++) {
						if (Math.abs(deg - snapAngles[si]) < 3) {
							deg = snapAngles[si];
							break;
						}
					}

					deg = Math.round(deg);
					didRotate = true;
					paintRotation(deg);
					self.showTransformHud(`${deg}°`);
				};

				let rotRaf = null;
				let pendingX = e.clientX;
				let pendingY = e.clientY;
				let isRotating = true;

				const cancelRotate = () => {
					if (!isRotating) return;
					isRotating = false;
					if (rotRaf) { cancelAnimationFrame(rotRaf); rotRaf = null; }
					$(document).off('.mrTeeRot');
					self._cancelTransform = null;
					$handle.removeClass('is-rotating');
					boxEl.classList.remove('is-interacting');
					paintRotation(startRot);
					self.hideTransformUi();
				};
				self._cancelTransform = cancelRotate;

				$(document).on('pointermove.mrTeeRot', function(moveEvent) {
					if (!isRotating) return;
					if (pointerId !== undefined && moveEvent.pointerId !== undefined && moveEvent.pointerId !== pointerId) return;
					pendingX = moveEvent.clientX;
					pendingY = moveEvent.clientY;
					if (rotRaf) return;
					rotRaf = requestAnimationFrame(() => {
						rotRaf = null;
						if (!isRotating) return;
						applyRotateDelta(pendingX, pendingY);
					});
				});

				$(document).on('pointerup.mrTeeRot pointercancel.mrTeeRot', function(upEvent) {
					if (!isRotating) return;
					if (pointerId !== undefined && upEvent.pointerId !== undefined && upEvent.pointerId !== pointerId) return;
					if (upEvent.type === 'pointercancel') {
						cancelRotate();
						return;
					}
					isRotating = false;
					self._cancelTransform = null;
					if (rotRaf) {
						cancelAnimationFrame(rotRaf);
						rotRaf = null;
						applyRotateDelta(
							upEvent && upEvent.clientX !== undefined ? upEvent.clientX : pendingX,
							upEvent && upEvent.clientY !== undefined ? upEvent.clientY : pendingY
						);
					}
					$handle.removeClass('is-rotating');
					boxEl.classList.remove('is-interacting');
					$(document).off('.mrTeeRot');
					self.hideTransformUi();

					if (didRotate) {
						self.saveFieldPlacementForActiveView(currentIdx);
						self.commitHistory();
						self.recalculateDimensions();
					}
				});
			});
		}

		/**
		 * Keyboard Arrow Nudging for Active Box (Arrow = 0.5%, Shift+Arrow = 2%).
		 */
		bindKeyboardNudge() {
			const self = this;
			const isTypingTarget = (el) => {
				if (!el) return false;
				const tag = (el.tagName || '').toUpperCase();
				return tag === 'INPUT' || tag === 'TEXTAREA' || tag === 'SELECT' || Boolean(el.isContentEditable);
			};

			$(document).on('keydown.mrTeeAdmin', function(e) {
				// Escape aborts an in-progress drag / resize / rotate.
				if (e.key === 'Escape' && typeof self._cancelTransform === 'function') {
					e.preventDefault();
					self._cancelTransform();
					return;
				}

				if (!$('#tab-stage').hasClass('is-active') || !$('#mr-tee-settings-panel').is(':visible')) return;
				if ($('.media-modal:visible').length) return;
				const activeEl = document.activeElement;
				if (isTypingTarget(activeEl)) return;

				// Undo / Redo (Ctrl/Cmd+Z, Ctrl/Cmd+Shift+Z, Ctrl+Y)
				if ((e.ctrlKey || e.metaKey) && !e.altKey) {
					const k = String(e.key || '').toLowerCase();
					if (k === 'z' && !e.shiftKey) { e.preventDefault(); self.undo(); return; }
					if ((k === 'z' && e.shiftKey) || k === 'y') { e.preventDefault(); self.redo(); return; }
					return;
				}

				if (!['ArrowLeft', 'ArrowRight', 'ArrowUp', 'ArrowDown'].includes(e.key)) return;
				if (e.altKey || e.ctrlKey || e.metaKey) return;
				// Only nudge while the stage itself (or a box inside it) owns the focus — never hijack page scroll.
				if (!activeEl || !$(activeEl).closest('#mr-tee-stage-canvas-wrap').length) return;
				if (!self.hasActiveVisibleField()) return;

				e.preventDefault();
				const idx = self.activeFieldIndex;
				const step = e.shiftKey ? 2.0 : 0.5;
				const curL = parseFloat($(`#mr_tee_field_left_${idx}`).val()) || 0;
				const curT = parseFloat($(`#mr_tee_field_top_${idx}`).val()) || 0;
				const curW = parseFloat($(`#mr_tee_field_width_${idx}`).val()) || 40;
				const curH = parseFloat($(`#mr_tee_field_height_${idx}`).val()) || 8.5;

				let nextL = curL;
				let nextT = curT;
				if (e.key === 'ArrowLeft') nextL = curL - step;
				else if (e.key === 'ArrowRight') nextL = curL + step;
				else if (e.key === 'ArrowUp') nextT = curT - step;
				else if (e.key === 'ArrowDown') nextT = curT + step;

				nextL = self.round1(Math.max(0, Math.min(100 - curW, nextL)));
				nextT = self.round1(Math.max(0, Math.min(100 - curH, nextT)));
				self.ensureHistoryBase();
				self.updateBoxPosition(idx, nextL, nextT);
			});
		}

		/**
		 * Canvas Micro-Toolbar Actions (Center H / Center V / Center Both / Reset 0° / Duplicate).
		 */
		bindCanvasMicroToolbar() {
			const self = this;
			$(document).on('click', '.mr-tee-canvas-tool-btn', function(e) {
				e.preventDefault();
				const action = $(this).data('action');
				if (action === 'undo') { self.undo(); return; }
				if (action === 'redo') { self.redo(); return; }
				if (!self.hasActiveVisibleField()) return;
				self.ensureHistoryBase();
				const idx = self.activeFieldIndex;

				const curL = parseFloat($(`#mr_tee_field_left_${idx}`).val()) || 0;
				const curT = parseFloat($(`#mr_tee_field_top_${idx}`).val()) || 0;
				const curW = parseFloat($(`#mr_tee_field_width_${idx}`).val()) || 40;
				const curH = parseFloat($(`#mr_tee_field_height_${idx}`).val()) || 8.5;

				if (action === 'center-h') {
					self.updateBoxPosition(idx, self.round1(Math.max(0, (100 - curW) / 2)), curT);
				} else if (action === 'center-v') {
					self.updateBoxPosition(idx, curL, self.round1(Math.max(0, (100 - curH) / 2)));
				} else if (action === 'center-both') {
					self.updateBoxPosition(
						idx,
						self.round1(Math.max(0, (100 - curW) / 2)),
						self.round1(Math.max(0, (100 - curH) / 2))
					);
				} else if (action === 'reset-rot') {
					self.updateBoxRotation(idx, 0);
				} else if (action === 'duplicate') {
					self.duplicateField(idx);
				}
			});
		}

		updateBoxPosition(idx, left, top) {
			const curH = parseFloat($(`#mr_tee_field_height_${idx}`).val()) || 8.5;
			$(`#mr_tee_field_left_${idx}`).val(left);
			$(`#mr_tee_field_top_${idx}`).val(top);
			$(`#mr-tee-field-box-${idx}`).css({ left: left + '%', top: top + '%' });
			this.syncBoxBadgePosition(idx, top, curH);
			this.saveFieldPlacementForActiveView(idx);
			this.recalculateDimensions();
		}

		updateBoxRotation(idx, deg) {
			const clamped = Math.max(-180, Math.min(180, Math.round(Number.isFinite(deg) ? deg : 0)));
			$(`#mr_tee_field_rotate_${idx}`).val(clamped);
			$(`#mr-tee-field-box-${idx}`).css({
				transform: `rotate(${clamped}deg)`,
				'transform-origin': 'center center',
			});

			const curW = parseFloat($(`#mr_tee_field_width_${idx}`).val()) || 40.0;
			const curH = parseFloat($(`#mr_tee_field_height_${idx}`).val()) || 8.5;
			const rotTxt = clamped !== 0 ? ` (${clamped}°)` : '';
			$(`.mr-tee-field-pill[data-field-index="${idx}"] .mr-tee-pill-dims`).text(`${curW}×${curH}%${rotTxt}`);

			this.saveFieldPlacementForActiveView(idx);
			this.recalculateDimensions();
		}

		updateBoxBounds(idx, left, top, width, height) {
			const c = this.clampBoxCoords(left, top, width, height);
			$(`#mr_tee_field_left_${idx}`).val(c.l);
			$(`#mr_tee_field_top_${idx}`).val(c.t);
			$(`#mr_tee_field_width_${idx}`).val(c.w);
			$(`#mr_tee_field_height_${idx}`).val(c.h);

			$(`#mr-tee-field-box-${idx}`).css({
				left: c.l + '%',
				top: c.t + '%',
				width: c.w + '%',
				height: c.h + '%',
			});
			this.syncBoxBadgePosition(idx, c.t, c.h);

			const r = parseFloat($(`#mr_tee_field_rotate_${idx}`).val()) || 0;
			const rotTxt = r !== 0 ? ` (${r}°)` : '';
			$(`.mr-tee-field-pill[data-field-index="${idx}"] .mr-tee-pill-dims`).text(`${c.w}×${c.h}%${rotTxt}`);

			this.saveFieldPlacementForActiveView(idx);
			this.renderBoxPreview(idx);
			this.recalculateDimensions();
		}

		/**
		 * Show/hide Color Mode, Solid Color Picker, and OpenType-SVG Color Notice for a text field.
		 */
		syncFieldColorUi(idx) {
			const $panel = $(`#mr-tee-field-panel-${idx}`);
			if (!$panel.length) return;
			const isImage = this.isImageLikeType(this.getFieldType(idx));
			if (isImage) {
				$panel.find('.mr-tee-row-svg-color-notice, .mr-tee-row-color-mode, .mr-tee-row-solid-color').hide();
				return;
			}

			const fonts = (window.mrTeeAdminData && window.mrTeeAdminData.fonts) || {};
			const fontId = $panel.find('.mr-tee-field-font-select').val() || '';
			const fontDef = fonts[fontId] || null;
			const isSvg = this.isSvgFont(fontDef);
			const colorMode = $panel.find('.mr-tee-field-color-mode').val() || 'multi';

			$panel.find('.mr-tee-row-svg-color-notice').toggle(isSvg);
			$panel.find('.mr-tee-row-color-mode').toggle(!isSvg);
			$panel.find('.mr-tee-row-solid-color').toggle(!isSvg && colorMode === 'solid');
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

			// Manual Coordinate Inputs (Position & Size) with strict [0, 100] clamping
			$(document).on('input change', '.mr-tee-field-coord:not(.mr-tee-field-coord-rotate)', function(e) {
				const idx = getFieldIdx($(this));
				const rawL = parseFloat($(`#mr_tee_field_left_${idx}`).val());
				const rawT = parseFloat($(`#mr_tee_field_top_${idx}`).val());
				const rawW = parseFloat($(`#mr_tee_field_width_${idx}`).val());
				const rawH = parseFloat($(`#mr_tee_field_height_${idx}`).val());
				const clamped = self.clampBoxCoords(
					Number.isFinite(rawL) ? rawL : 0,
					Number.isFinite(rawT) ? rawT : 0,
					Number.isFinite(rawW) ? rawW : 40,
					Number.isFinite(rawH) ? rawH : 8.5
				);

				if (e.type === 'change') {
					$(`#mr_tee_field_left_${idx}`).val(clamped.l);
					$(`#mr_tee_field_top_${idx}`).val(clamped.t);
					$(`#mr_tee_field_width_${idx}`).val(clamped.w);
					$(`#mr_tee_field_height_${idx}`).val(clamped.h);
				}

				const r = parseFloat($(`#mr_tee_field_rotate_${idx}`).val()) || 0;
				const rotTxt = r !== 0 ? ` (${r}°)` : '';

				$(`#mr-tee-field-box-${idx}`).css({
					left: clamped.l + '%',
					top: clamped.t + '%',
					width: clamped.w + '%',
					height: clamped.h + '%',
				});
				self.syncBoxBadgePosition(idx, clamped.t, clamped.h);

				$(`.mr-tee-field-pill[data-field-index="${idx}"] .mr-tee-pill-dims`).text(`${clamped.w}×${clamped.h}%${rotTxt}`);
				const rawMatchesClamped = Number.isFinite(rawL) && Number.isFinite(rawT) && Number.isFinite(rawW) && Number.isFinite(rawH)
					&& rawL === clamped.l && rawT === clamped.t && rawW === clamped.w && rawH === clamped.h;
				if (e.type === 'change' || rawMatchesClamped) {
					self.ensureHistoryBase();
					self.saveFieldPlacementForActiveView(idx);
				}
				self.renderBoxPreview(idx, { bw: clamped.w, bh: clamped.h });
				self.recalculateDimensions();
			});

			// Rotation Angle Input
			$(document).on('input change', '.mr-tee-field-coord-rotate', function(e) {
				const idx = getFieldIdx($(this));
				const deg = parseFloat($(this).val());
				if (e.type === 'change' || Number.isFinite(deg)) {
					self.updateBoxRotation(idx, Number.isFinite(deg) ? deg : 0);
				}
			});

			// Quick Rotation Preset Buttons (0°, -90°, 90°)
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
				const ft = self.getFieldType(idx);
				const prefix = ft === 'ai_image' ? '✨ ' : (ft === 'image' ? '🖼️ ' : '');
				$(`.mr-tee-field-pill[data-field-index="${idx}"] .mr-tee-pill-label`).text(val);
				$(`#mr-tee-field-box-${idx} .mr-tee-box-badge`).text(`${idx + 1}. ${prefix}${val}`);
				$(`#mr-tee-field-panel-${idx} .mr-tee-panel-title`).text(`Champ #${idx + 1} : ${val}`);
			});

			// Field Type Switcher (Text <-> Image <-> AI Image)
			$(document).on('change', '.mr-tee-field-type-select', function() {
				const idx = getFieldIdx($(this));
				const rawVal = $(this).val();
				const newType = rawVal === 'ai_image' ? 'ai_image' : (rawVal === 'image' ? 'image' : 'text');
				const isAiImage = newType === 'ai_image';
				const isImage = newType === 'image' || isAiImage;
				const isPlainImage = newType === 'image';
				const $panel = $(`#mr-tee-field-panel-${idx}`);
				const $box = $(`#mr-tee-field-box-${idx}`);
				const $pill = $(`.mr-tee-field-pill[data-field-index="${idx}"]`);

				const prevType = $panel.attr('data-field-type') || 'text';
				$panel.attr('data-field-type', newType).toggleClass('is-image-panel', isImage).toggleClass('is-ai-image-panel', isAiImage);
				$box.attr('data-field-type', newType).toggleClass('is-image-field', isImage).toggleClass('is-ai-image-field', isAiImage);
				$pill.attr('data-field-type', newType).toggleClass('is-image-pill', isImage).toggleClass('is-ai-image-pill', isAiImage);

				// Keep default ref_id prefix aligned when switching field types in the live Studio
				const $refInput = $panel.find('.mr-tee-field-ref-id');
				if ($refInput.length && prevType !== newType) {
					const curRef = String($refInput.val() || '');
					const prevSlug = `id-ref-${prevType.replace(/_/g, '-')}-`;
					const nextSlug = `id-ref-${newType.replace(/_/g, '-')}-`;
					if (curRef.indexOf(prevSlug) === 0) {
						$refInput.val(nextSlug + curRef.slice(prevSlug.length));
					}
				}

				$panel.find('.mr-tee-row-text-only, .mr-tee-opt-text-only').toggle(!isImage);
				$panel.find('.mr-tee-row-image-only').toggle(isImage);
				$panel.find('.mr-tee-opt-image-only').toggle(isPlainImage);
				$panel.find('.mr-tee-row-ai-only').toggle(isAiImage);
				$panel.find('.mr-tee-lbl-default-image').text(isAiImage ? 'Aperçu par défaut' : 'Image');
				self.syncFieldColorUi(idx);

				$pill.find('.mr-tee-pill-type-icon').remove();
				if (isAiImage) {
					$pill.find('.mr-tee-pill-badge').after('<span class="mr-tee-pill-type-icon" aria-hidden="true">✨</span>');
				} else if (isImage) {
					$pill.find('.mr-tee-pill-badge').after('<span class="mr-tee-pill-type-icon" aria-hidden="true">🖼️</span>');
				}
				const labelVal = $panel.find('.mr-tee-field-label-input').val() || `Champ ${idx + 1}`;
				const boxPrefix = isAiImage ? '✨ ' : (isImage ? '🖼️ ' : '');
				$box.find('.mr-tee-box-badge').text(`${idx + 1}. ${boxPrefix}${labelVal}`);

				if (isAiImage) {
					self.renderAiRefsStrip(idx);
					self.syncAiPromptLegend(idx);
				}

				// Give image / AI image fields a practical initial height, clamping curTop cleanly when curTop > 85
				if (isImage) {
					const numOr = (sel, fb) => { const n = parseFloat($(sel).val()); return Number.isFinite(n) ? n : fb; };
					const curL = numOr(`#mr_tee_field_left_${idx}`, 30.0);
					const curTop = numOr(`#mr_tee_field_top_${idx}`, 25.0);
					const curW = numOr(`#mr_tee_field_width_${idx}`, 35.0);
					const curH = numOr(`#mr_tee_field_height_${idx}`, 8.5);
					if (curH < 15.0) {
						const nextH = self.round1(Math.min(30.0, Math.max(15.0, curH)));
						const nextTop = self.round1(Math.max(0, Math.min(curTop, 100 - nextH)));
						self.updateBoxBounds(idx, curL, nextTop, curW, nextH);
						return;
					}
				}

				self.renderBoxPreview(idx);
				self.recalculateDimensions();
			});

			// Default Image URL input: reset default_image_id to 0 on manual edit
			$(document).on('input change', '.mr-tee-field-default-image-input', function() {
				const idx = getFieldIdx($(this));
				const url = ($(this).val() || '').trim();
				$(`#mr-tee-field-panel-${idx} .mr-tee-field-default-image-id`).val('0');
				self.syncDefaultImagePreviewUi(idx, url);
				self.syncAiPromptLegend(idx);
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
					const liveIdx = parseInt($panel.attr('data-field-index'), 10);
					if (Number.isNaN(liveIdx) || !document.contains($panel[0])) return;
					$panel.find('.mr-tee-field-default-image-id').val(attId);
					$panel.find('.mr-tee-field-default-image-input').val(url);
					self.syncDefaultImagePreviewUi(liveIdx, url);
					self.renderBoxPreview(liveIdx);
					self.markDirty();
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
				$status.text('⏳ Envoi de l\'image...').removeClass('is-success is-error is-muted').addClass('is-info');

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
						if (!document.contains($panel[0])) return;
						const liveIdx = parseInt($panel.attr('data-field-index'), 10);
						if (resp && resp.success && resp.data && resp.data.url && !Number.isNaN(liveIdx)) {
							$panel.find('.mr-tee-field-default-image-id').val(resp.data.id || 0);
							$panel.find('.mr-tee-field-default-image-input').val(resp.data.url);
							self.syncDefaultImagePreviewUi(liveIdx, resp.data.url);
							self.renderBoxPreview(liveIdx);
							self.markDirty();
							$status.text('✅ Image définie !').removeClass('is-info is-error is-muted').addClass('is-success');
						} else {
							const msg = (resp && resp.data && resp.data.message) ? resp.data.message : 'Erreur lors de l\'import.';
							$status.text(`❌ ${msg}`).removeClass('is-info is-success is-muted').addClass('is-error');
						}
					},
					error: function(xhr, status, err) {
						inputEl.value = '';
						$status.text(`❌ Erreur réseau : ${err}`).removeClass('is-info is-success is-muted').addClass('is-error');
					}
				});
			});

			// Default Text Input
			$(document).on('input change', '.mr-tee-field-text-input', function() {
				const idx = getFieldIdx($(this));
				self.renderBoxPreview(idx);
			});

			// Font Select + SVG Color Controls Auto-Hide
			$(document).on('change', '.mr-tee-field-font-select', function() {
				const idx = getFieldIdx($(this));
				self.syncFieldColorUi(idx);
				self.renderBoxPreview(idx);
			});

			// Color Mode Change
			$(document).on('change', '.mr-tee-field-color-mode', function() {
				const idx = getFieldIdx($(this));
				self.syncFieldColorUi(idx);
				self.renderBoxPreview(idx);
			});

			// Solid Text Color Picker Change
			$(document).on('input change', '.mr-tee-field-color-hex', function() {
				const idx = getFieldIdx($(this));
				const hex = String($(this).val() || '#111827').toUpperCase();
				$(`#mr-tee-field-panel-${idx} .mr-tee-color-hex-readout`).text(hex);
				self.renderBoxPreview(idx);
			});

			// Multiline toggle: swap <input> <-> <textarea> so newlines aren't stripped
			$(document).on('change', '.mr-tee-field-multiline', function() {
				const idx = getFieldIdx($(this));
				const isMulti = $(this).is(':checked');
				const $field = $(`[name="mr_tee_config[text_lines][${idx}][default_text]"]`);
				if (!$field.length) return;

				const currentVal = $field.val() || '';
				const fieldDomId = `mr_tee_field_text_${idx}`;
				if (isMulti && !$field.is('textarea')) {
					const $ta = $('<textarea rows="2" class="regular-text mr-tee-field-text-input"></textarea>')
						.attr('id', fieldDomId)
						.attr('name', $field.attr('name'))
						.attr('data-field-index', idx)
						.val(currentVal);
					$field.replaceWith($ta);
				} else if (!isMulti && $field.is('textarea')) {
					const singleVal = currentVal.replace(/\r?\n/g, ' ');
					const $inp = $('<input type="text" class="regular-text mr-tee-field-text-input" />')
						.attr('id', fieldDomId)
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
				const v = $sel.val();
				return v === 'ai_image' ? 'ai_image' : (v === 'image' ? 'image' : 'text');
			}
			const attr = $(`#mr-tee-field-panel-${idx}`).attr('data-field-type');
			return attr === 'ai_image' ? 'ai_image' : (attr === 'image' ? 'image' : 'text');
		}

		isImageLikeType(fieldType) {
			return fieldType === 'image' || fieldType === 'ai_image';
		}

		generateAiModelSelectOptions(selectedModelId = '') {
			const aiState = (window.mrTeeAdminData && window.mrTeeAdminData.ai) || {};
			const models = Array.isArray(aiState.models) ? aiState.models : [];
			const defId = aiState.defaultModelId || '';
			const defModel = models.find(m => m && m.id === defId) || models[0] || null;
			const defLabel = defModel ? `Par défaut (${defModel.name})` : 'Par défaut (Réglages IA)';
			let html = `<option value="">${this.escapeXml(defLabel)}</option>`;
			models.forEach(m => {
				if (!m || !m.id) return;
				const sel = selectedModelId === m.id ? ' selected' : '';
				html += `<option value="${this.escapeXml(m.id)}"${sel}>${this.escapeXml(m.name)} [${this.escapeXml(m.air_id)}]</option>`;
			});
			return html;
		}

		getAiRefsArray(idx) {
			const raw = $(`#mr-tee-field-panel-${idx} .mr-tee-field-ai-refs-json`).val() || '[]';
			try {
				const parsed = JSON.parse(raw);
				return Array.isArray(parsed) ? parsed : [];
			} catch (_e) {
				return [];
			}
		}

		setAiRefsArray(idx, refs) {
			const clean = Array.isArray(refs) ? refs.slice(0, 6) : [];
			$(`#mr-tee-field-panel-${idx} .mr-tee-field-ai-refs-json`).val(JSON.stringify(clean));
			this.renderAiRefsStrip(idx, clean);
			this.syncAiPromptLegend(idx);
			this.markDirty();
		}

		renderAiRefsStrip(idx, refs = null) {
			const list = Array.isArray(refs) ? refs : this.getAiRefsArray(idx);
			const $panel = $(`#mr-tee-field-panel-${idx}`);
			const $strip = $panel.find('.mr-tee-ai-refs-strip');
			if (!$strip.length) return;

			const useDef = $panel.find('.mr-tee-field-ai-use-def-ref').is(':checked');
			const defUrl = ($panel.find('.mr-tee-field-default-image-input').val() || '').trim();
			const offset = (useDef && defUrl) ? 1 : 0;

			let html = '';
			list.forEach((item, rIdx) => {
				const url = this.normalizeAdminAssetUrl((item && item.url) || '');
				if (!url) return;
				html += `
					<div class="mr-tee-ai-ref-thumb" data-ref-index="${rIdx}">
						<img src="${this.escapeXml(url)}" alt="" loading="lazy" decoding="async" />
						<span class="mr-tee-ai-ref-badge">#${offset + rIdx + 1}</span>
						<button type="button" class="mr-tee-ai-ref-remove" data-field-index="${idx}" data-ref-index="${rIdx}" aria-label="Retirer cette référence">&times;</button>
					</div>
				`;
			});
			$strip.html(html);
		}

		syncAiPromptLegend(idx) {
			const $panel = $(`#mr-tee-field-panel-${idx}`);
			const $legend = $panel.find('.mr-tee-ai-prompt-legend');
			if (!$legend.length) return;

			const useDef = $panel.find('.mr-tee-field-ai-use-def-ref').is(':checked');
			const defUrl = ($panel.find('.mr-tee-field-default-image-input').val() || '').trim();
			const refs = this.getAiRefsArray(idx).filter(r => r && r.url);
			const adminTotal = (useDef && defUrl ? 1 : 0) + refs.length;
			const clientNum = adminTotal + 1;

			let html = '';
			if (adminTotal > 0) {
				const label = adminTotal === 1
					? 'Image 1 = Référence admin'
					: `Images 1–${adminTotal} = Références admin`;
				html += `<span class="mr-tee-ai-legend-pill">${this.escapeXml(label)}</span>`;
			}
			html += `<span class="mr-tee-ai-legend-pill is-client">Image ${clientNum} = Photo importée par le client</span>`;
			$legend.html(html);
		}

		bindAiFieldControls() {
			const self = this;
			const getIdx = ($el) => {
				const raw = $el.attr('data-field-index') || $el.closest('.mr-tee-field-panel').attr('data-field-index');
				const idx = parseInt(raw, 10);
				return Number.isNaN(idx) ? 0 : idx;
			};

			// Toggle "Use Default Image as Reference #1"
			$(document).on('change', '.mr-tee-field-ai-use-def-ref', function() {
				const idx = getIdx($(this));
				self.renderAiRefsStrip(idx);
				self.syncAiPromptLegend(idx);
			});

			// Add hidden reference image via WP Media Library
			$(document).on('click', '.mr-tee-btn-add-ai-ref', function(e) {
				e.preventDefault();
				const idx = getIdx($(this));
				if (typeof wp === 'undefined' || !wp.media) {
					window.alert('Médiathèque WordPress indisponible sur cette page.');
					return;
				}
				const frame = wp.media({
					title: 'Choisir une image de référence IA (cachée au client)',
					button: { text: 'Ajouter comme référence IA' },
					library: { type: 'image' },
					multiple: false,
				});
				frame.on('select', function() {
					const sel = frame.state().get('selection').first();
					if (!sel) return;
					const att = sel.toJSON();
					const url = (att && att.url) ? String(att.url) : '';
					if (!url) return;
					const refs = self.getAiRefsArray(idx);
					refs.push({ id: parseInt(att.id, 10) || 0, url });
					self.setAiRefsArray(idx, refs);
				});
				frame.open();
			});

			// Remove hidden reference image
			$(document).on('click', '.mr-tee-ai-ref-remove', function(e) {
				e.preventDefault();
				const idx = getIdx($(this));
				const refIdx = parseInt($(this).attr('data-ref-index'), 10);
				if (Number.isNaN(refIdx)) return;
				const refs = self.getAiRefsArray(idx);
				refs.splice(refIdx, 1);
				self.setAiRefsArray(idx, refs);
			});

			// Show chosen admin test photo filename
			$(document).on('change', '.mr-tee-ai-admin-test-file', function() {
				const $row = $(this).closest('.mr-tee-ai-admin-test-row');
				const file = this.files && this.files[0];
				$row.find('.mr-tee-ai-admin-test-status')
					.removeClass('is-error is-ok')
					.text(file ? `Photo prête : ${file.name}` : '');
			});

			const compressAdminTestPhoto = (file) => new Promise((resolve) => {
				if (!file || file.size <= 350 * 1024) {
					resolve(file);
					return;
				}
				const objUrl = URL.createObjectURL(file);
				const img = new Image();
				img.onload = () => {
					URL.revokeObjectURL(objUrl);
					try {
						const w = img.naturalWidth || img.width || 0;
						const h = img.naturalHeight || img.height || 0;
						if (!w || !h) { resolve(file); return; }
						const scale = Math.min(1, 1280 / Math.max(w, h));
						const dw = Math.max(1, Math.round(w * scale));
						const dh = Math.max(1, Math.round(h * scale));
						const c = document.createElement('canvas');
						c.width = dw;
						c.height = dh;
						const ctx = c.getContext('2d');
						if (!ctx) { resolve(file); return; }
						const keepAlpha = (file.type === 'image/png' || file.type === 'image/webp');
						if (!keepAlpha) {
							ctx.fillStyle = '#ffffff';
							ctx.fillRect(0, 0, dw, dh);
						}
						ctx.drawImage(img, 0, 0, dw, dh);
						c.toBlob((blob) => resolve(blob && blob.size > 0 ? blob : file), keepAlpha ? 'image/webp' : 'image/jpeg', 0.86);
					} catch (_e) {
						resolve(file);
					}
				};
				img.onerror = () => { URL.revokeObjectURL(objUrl); resolve(file); };
				img.src = objUrl;
			});

			const makeAdminUuid = () => {
				if (typeof crypto !== 'undefined' && typeof crypto.randomUUID === 'function') {
					return crypto.randomUUID();
				}
				return 'xxxxxxxx-xxxx-4xxx-yxxx-xxxxxxxxxxxx'.replace(/[xy]/g, (c) => {
					const r = (Math.random() * 16) | 0;
					return (c === 'x' ? r : ((r & 0x3) | 0x8)).toString(16);
				});
			};

			// Run Live Admin Test Generation directly inside the Visual Placement Stage box (with async polling support)
			$(document).on('click', '.mr-tee-btn-ai-admin-test', async function(e) {
				e.preventDefault();
				const idx = getIdx($(this));
				const $panel = $(`#mr-tee-field-panel-${idx}`);
				const $row = $panel.find('.mr-tee-ai-admin-test-row');
				const $btn = $(this);
				const $status = $row.find('.mr-tee-ai-admin-test-status');
				const fileInput = $row.find('.mr-tee-ai-admin-test-file')[0];
				const testFile = (fileInput && fileInput.files && fileInput.files[0]) ? fileInput.files[0] : null;
				const cfg = window.mrTeeAdminData || {};
				const ajaxUrl = self.toSameOriginPath(cfg.ajaxUrl || '/wp-admin/admin-ajax.php');

				$btn.prop('disabled', true);
				$status.removeClass('is-error is-ok').text('⏳ Génération test en cours...');

				try {
					const uploadBlob = testFile ? await compressAdminTestPhoto(testFile) : null;
					const coords = self.getFieldCurrentCoords(idx);
					const taskUuid = makeAdminUuid();
					const fd = new FormData();
					fd.append('action', 'mr_tee_ai_admin_test');
					fd.append('nonce', cfg.nonce || '');
					fd.append('product_id', String(cfg.productId || 0));
					fd.append('task_uuid', taskUuid);
					fd.append('ai_model', $panel.find('.mr-tee-field-ai-model').val() || '');
					fd.append('ai_prompt', $panel.find('.mr-tee-field-ai-prompt').val() || '');
					fd.append('ai_size_mode', $panel.find('.mr-tee-field-ai-size-mode').val() || 'auto_box');
					fd.append('ai_remove_bg', $panel.find('.mr-tee-field-ai-remove-bg').is(':checked') ? '1' : '0');
					fd.append('ai_use_default_as_ref', $panel.find('.mr-tee-field-ai-use-def-ref').is(':checked') ? '1' : '0');
					fd.append('default_image', ($panel.find('.mr-tee-field-default-image-input').val() || '').trim());
					fd.append('default_image_id', $panel.find('.mr-tee-field-default-image-id').val() || '0');
					fd.append('ai_reference_images', $panel.find('.mr-tee-field-ai-refs-json').val() || '[]');
					fd.append('box_width', String(coords.box_width || 40));
					fd.append('box_height', String(coords.box_height || 30));
					fd.append('stage_ratio', String(self.getStageAspectRatio() || 1));
					if (uploadBlob) {
						fd.append('customer_image', uploadBlob, (testFile && testFile.name) || 'test.jpg');
					}

					let resultData = null;
					let shouldPoll = false;
					let pollUuid = taskUuid;

					try {
						const resp = await fetch(ajaxUrl, { method: 'POST', body: fd, credentials: 'same-origin' });
						const json = await resp.json();
						if (json && json.success && json.data) {
							if (json.data.status === 'completed' && json.data.url) {
								resultData = json.data;
							} else if (json.data.status === 'processing') {
								if (json.data.task_uuid) pollUuid = String(json.data.task_uuid);
								shouldPoll = true;
							}
						} else {
							throw new Error((json && json.data && json.data.message) || 'Erreur lors du test IA.');
						}
					} catch (reqErr) {
						if (reqErr && (reqErr.name === 'TypeError' || /json|unexpected token|504|502|524/i.test(String(reqErr.message || '')))) {
							shouldPoll = true;
						} else {
							throw reqErr;
						}
					}

					if (shouldPoll && !resultData) {
						$status.removeClass('is-error is-ok').text('⏳ Finalisation de l\'image IA...');
						for (let attempt = 0; attempt < 45; attempt++) {
							await new Promise((r) => setTimeout(r, 2500));
							const pfd = new FormData();
							pfd.append('action', 'mr_tee_ai_poll');
							pfd.append('nonce', cfg.nonce || '');
							pfd.append('task_uuid', pollUuid);
							try {
								const presp = await fetch(ajaxUrl, { method: 'POST', body: pfd, credentials: 'same-origin' });
								const pjson = await presp.json();
								if (pjson && pjson.success && pjson.data && pjson.data.status === 'completed' && pjson.data.url) {
									resultData = pjson.data;
									break;
								}
								if (pjson && pjson.success === false && pjson.data && pjson.data.message) {
									throw new Error(pjson.data.message);
								}
							} catch (pollErr) {
								if (pollErr && pollErr.message && !/fetch|json|network/i.test(pollErr.message)) {
									throw pollErr;
								}
							}
						}
					}

					if (!resultData || !resultData.url) {
						throw new Error('Aucune image retournée par le test IA.');
					}

					const liveIdx = parseInt($panel.attr('data-field-index'), 10);
					const targetIdx = Number.isNaN(liveIdx) ? idx : liveIdx;
					const previewUrl = resultData.preview_url || resultData.url;
					const el = document.getElementById(`mr-tee-box-svg-${targetIdx}`);
					const imgFit = $panel.find('.mr-tee-field-image-fit').val() === 'cover' ? 'cover' : 'contain';
					if (el) {
						el.innerHTML = `<img class="mr-tee-admin-box-img" src="${self.escapeXml(previewUrl)}" alt="" draggable="false" style="width:100%;height:100%;object-fit:${imgFit};display:block;pointer-events:none;user-select:none;" />`;
						el._renderMode = 'img';
						el._imgUrl = previewUrl;
						el._imgFit = imgFit;
						el._imgEl = el.querySelector('.mr-tee-admin-box-img');
					}
					$status.removeClass('is-error').addClass('is-ok').text('✓ Test réussi (affiché dans la zone) !');
				} catch (err) {
					$status.removeClass('is-ok').addClass('is-error').text('✕ ' + ((err && err.message) || 'Erreur lors du test IA.'));
				} finally {
					$btn.prop('disabled', false);
				}
			});
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
			this.renderAiRefsStrip(idx);
			this.syncAiPromptLegend(idx);
		}

		/**
		 * Add New Field Rectangle (Text, Image, or AI Image).
		 */
		bindAddField() {
			const self = this;
			$(document).on('click', '#mr-tee-btn-add-field-pill', function() {
				self.addField('text');
			});
			$(document).on('click', '#mr-tee-btn-add-image-field-pill', function() {
				self.addField('image');
			});
			$(document).on('click', '#mr-tee-btn-add-ai-image-field-pill', function() {
				self.addField('ai_image');
			});
		}

		bindDuplicateField() {
			const self = this;
			$(document).on('click', '.mr-tee-btn-duplicate-field', function(e) {
				e.preventDefault();
				const idx = parseInt($(this).attr('data-field-index'), 10);
				if (!Number.isNaN(idx)) {
					self.duplicateField(idx);
				}
			});
		}

		duplicateField(srcIdx) {
			const $srcPanel = $(`#mr-tee-field-panel-${srcIdx}`);
			if (!$srcPanel.length) return;

			this.saveFieldPlacementForActiveView(srcIdx);
			const fieldType = this.getFieldType(srcIdx);
			const isImageLike = this.isImageLikeType(fieldType);
			const sideId = this.getFieldSideId(srcIdx);
			const coords = this.getFieldCurrentCoords(srcIdx);
			const srcPlacements = this.getFieldPlacements(srcIdx);

			const offsetCoords = (c) => {
				const bw = Number.isFinite(parseFloat(c.box_width)) ? parseFloat(c.box_width) : 40;
				const bh = Number.isFinite(parseFloat(c.box_height)) ? parseFloat(c.box_height) : (isImageLike ? 30 : 8.5);
				const bl = Number.isFinite(parseFloat(c.box_left)) ? parseFloat(c.box_left) : 30;
				const bt = Number.isFinite(parseFloat(c.box_top)) ? parseFloat(c.box_top) : 25;
				const br = Number.isFinite(parseFloat(c.box_rotate)) ? parseFloat(c.box_rotate) : 0;
				return {
					box_left: this.round1(Math.max(0, Math.min(100 - bw, bl + 3))),
					box_top: this.round1(Math.max(0, Math.min(100 - bh, bt + 3))),
					box_width: this.round1(bw),
					box_height: this.round1(bh),
					box_rotate: Math.round(br),
				};
			};

			const clonedPlacements = {};
			Object.keys(srcPlacements).forEach(vid => {
				clonedPlacements[vid] = offsetCoords(srcPlacements[vid]);
			});
			const nextCoords = offsetCoords(coords);
			if (this.activeViewId && !clonedPlacements[this.activeViewId]) {
				clonedPlacements[this.activeViewId] = { ...nextCoords };
			}

			const origLabel = $srcPanel.find('.mr-tee-field-label-input').val() || `Champ ${srcIdx + 1}`;
			const cloneSpec = {
				sideId,
				label: `${origLabel} (copie)`,
				coords: nextCoords,
				placements: clonedPlacements,
				defaultText: $srcPanel.find('.mr-tee-field-text-input').val() || '',
				fontId: $srcPanel.find('.mr-tee-field-font-select').val() || '',
				colorMode: $srcPanel.find('.mr-tee-field-color-mode').val() || 'multi',
				colorHex: $srcPanel.find('.mr-tee-field-color-hex').val() || '#111827',
				allowColorPick: $srcPanel.find('.mr-tee-field-allow-color-pick').is(':checked'),
				maxChars: parseInt($srcPanel.find('.mr-tee-field-max-chars').val(), 10) || 25,
				allowFontPick: $srcPanel.find('.mr-tee-field-font-pick').is(':checked'),
				multiline: $srcPanel.find('.mr-tee-field-multiline').is(':checked'),
				warningUnedited: $srcPanel.find('.mr-tee-field-warning').is(':checked'),
				defaultImage: $srcPanel.find('.mr-tee-field-default-image-input').val() || '',
				defaultImageId: parseInt($srcPanel.find('.mr-tee-field-default-image-id').val(), 10) || 0,
				imageFit: $srcPanel.find('.mr-tee-field-image-fit').val() === 'cover' ? 'cover' : 'contain',
				allowImageUpload: $srcPanel.find('.mr-tee-field-allow-image-upload').is(':checked'),
				aiModel: $srcPanel.find('.mr-tee-field-ai-model').val() || '',
				aiPrompt: $srcPanel.find('.mr-tee-field-ai-prompt').val() || '',
				aiUseDefaultAsRef: $srcPanel.find('.mr-tee-field-ai-use-def-ref').is(':checked'),
				aiReferenceImages: this.getAiRefsArray(srcIdx),
				aiSizeMode: $srcPanel.find('.mr-tee-field-ai-size-mode').val() === 'match_reference' ? 'match_reference' : 'auto_box',
				aiRemoveBg: $srcPanel.find('.mr-tee-field-ai-remove-bg').is(':checked'),
				aiButtonLabel: $srcPanel.find('.mr-tee-field-ai-btn-label').val() || 'Générer',
			};

			this.addField(fieldType, cloneSpec);
		}

		addField(fieldType = 'text', cloneSpec = null) {
			const normType = fieldType === 'ai_image' ? 'ai_image' : (fieldType === 'image' ? 'image' : 'text');
			const isAiImage = normType === 'ai_image';
			const isImage = normType === 'image' || isAiImage;
			const isPlainImage = normType === 'image';
			const newIdx = $('.mr-tee-field-box').length;
			const color = FIELD_COLORS[newIdx % FIELD_COLORS.length];
			const activeSideId = (cloneSpec && cloneSpec.sideId) ? cloneSpec.sideId : this.getActiveSideId();
			const safeSideId = this.escapeXml(activeSideId);

			this._uidCounter = (this._uidCounter || 0) + 1;
			const uid = 'f' + Date.now().toString(36) + '_' + this._uidCounter + '_' + Math.random().toString(36).slice(2, 5);
			const fieldId = `line_${uid}`;
			const refId = `id-ref-${isAiImage ? 'ai-image' : (isImage ? 'image' : 'text')}-${uid}`;
			const colorRefId = `id-ref-color-${uid}`;

			let newLeft = isImage ? 32.5 : 30.0;
			let newTop = 25.0;
			let newWidth = isImage ? 35.0 : 40.0;
			let newHeight = isImage ? 30.0 : 8.5;
			let newRotate = 0;

			if (cloneSpec && cloneSpec.coords) {
				newLeft = cloneSpec.coords.box_left;
				newTop = cloneSpec.coords.box_top;
				newWidth = cloneSpec.coords.box_width;
				newHeight = cloneSpec.coords.box_height;
				newRotate = cloneSpec.coords.box_rotate || 0;
			} else {
				let lastSameSideIdx = -1;
				for (let i = newIdx - 1; i >= 0; i--) {
					if (this.getFieldSideId(i) === activeSideId) {
						lastSameSideIdx = i;
						break;
					}
				}
				const maxTop = isImage ? Math.max(5.0, 96.0 - newHeight) : 88.0;
				if (lastSameSideIdx >= 0) {
					const rawPrevTop = parseFloat($(`#mr_tee_field_top_${lastSameSideIdx}`).val());
					const rawPrevH = parseFloat($(`#mr_tee_field_height_${lastSameSideIdx}`).val());
					const prevTop = Number.isFinite(rawPrevTop) ? rawPrevTop : 25.0;
					const prevHeight = Number.isFinite(rawPrevH) ? rawPrevH : 8.5;
					newTop = Math.min(maxTop, this.round1(prevTop + prevHeight + 2.0));
				}
			}

			const defaultFieldLabel = isAiImage ? `Image IA ${newIdx + 1}` : (isImage ? `Image ${newIdx + 1}` : `Texte ${newIdx + 1}`);
			const fieldLabel = (cloneSpec && cloneSpec.label) ? cloneSpec.label : defaultFieldLabel;
			const defaultText = (cloneSpec && cloneSpec.defaultText !== undefined) ? cloneSpec.defaultText : (isImage ? '' : `Ligne ${newIdx + 1}`);
			const allowedFonts = this.getAllowedFonts();
			const defaultFontId = (cloneSpec && cloneSpec.fontId && allowedFonts.includes(cloneSpec.fontId))
				? cloneSpec.fontId
				: (allowedFonts[0] || '');
			const fonts = (window.mrTeeAdminData && window.mrTeeAdminData.fonts) || {};
			const fontDef = fonts[defaultFontId] || null;
			const isSvg = this.isSvgFont(fontDef);
			const colorMode = (cloneSpec && cloneSpec.colorMode) ? cloneSpec.colorMode : 'multi';
			const colorHex = (cloneSpec && cloneSpec.colorHex) ? cloneSpec.colorHex : '#111827';
			const allowColorPick = cloneSpec ? Boolean(cloneSpec.allowColorPick) : true;
			const maxChars = (cloneSpec && cloneSpec.maxChars) ? cloneSpec.maxChars : 25;
			const allowFontPick = cloneSpec ? Boolean(cloneSpec.allowFontPick) : true;
			const multiline = cloneSpec ? Boolean(cloneSpec.multiline) : false;
			const warningUnedited = cloneSpec ? Boolean(cloneSpec.warningUnedited) : false;
			const defaultImage = (cloneSpec && cloneSpec.defaultImage) ? cloneSpec.defaultImage : '';
			const defaultImageId = (cloneSpec && cloneSpec.defaultImageId) ? cloneSpec.defaultImageId : 0;
			const imageFit = (cloneSpec && cloneSpec.imageFit === 'cover') ? 'cover' : 'contain';
			const allowImageUpload = cloneSpec ? Boolean(cloneSpec.allowImageUpload) : true;

			const aiModel = (cloneSpec && cloneSpec.aiModel) ? cloneSpec.aiModel : '';
			const aiPrompt = (cloneSpec && cloneSpec.aiPrompt) ? cloneSpec.aiPrompt : '';
			const aiUseDefAsRef = (cloneSpec && cloneSpec.aiUseDefaultAsRef !== undefined) ? Boolean(cloneSpec.aiUseDefaultAsRef) : false;
			const aiRefs = (cloneSpec && Array.isArray(cloneSpec.aiReferenceImages)) ? cloneSpec.aiReferenceImages : [];
			const aiSizeMode = (cloneSpec && cloneSpec.aiSizeMode === 'match_reference') ? 'match_reference' : 'auto_box';
			const aiRemoveBg = cloneSpec ? Boolean(cloneSpec.aiRemoveBg) : false;
			const aiButtonLabel = (cloneSpec && cloneSpec.aiButtonLabel) ? cloneSpec.aiButtonLabel : 'Générer';
			const aiState = (window.mrTeeAdminData && window.mrTeeAdminData.ai) || {};
			const aiHasKey = Boolean(aiState.hasApiKey);

			const fontSelectOptions = this.generateFontSelectOptions(defaultFontId, allowedFonts);
			const sideSelectOptions = this.generateSideSelectOptions(activeSideId);
			const aiModelSelectOptions = this.generateAiModelSelectOptions(aiModel);

			let initialPlacements = {};
			if (cloneSpec && cloneSpec.placements && Object.keys(cloneSpec.placements).length) {
				initialPlacements = cloneSpec.placements;
			} else {
				const seedCoords = {
					box_left: newLeft,
					box_top: newTop,
					box_width: newWidth,
					box_height: newHeight,
					box_rotate: newRotate,
				};
				this.previewViews.forEach(v => {
					if (v && v.side_id === activeSideId) {
						initialPlacements[v.id] = { ...seedCoords };
					}
				});
				if (this.activeViewId && !initialPlacements[this.activeViewId]) {
					initialPlacements[this.activeViewId] = { ...seedCoords };
				}
			}

			const placementsJsonAttr = this.escapeXml(JSON.stringify(initialPlacements));
			const aiRefsJsonAttr = this.escapeXml(JSON.stringify(aiRefs));
			const safeLabel = this.escapeXml(fieldLabel);
			const safeDefaultText = this.escapeXml(defaultText);
			const safeDefaultImage = this.escapeXml(defaultImage);
			const safeAiPrompt = this.escapeXml(aiPrompt);
			const safeAiBtnLabel = this.escapeXml(aiButtonLabel);
			const rotTxt = newRotate !== 0 ? ` (${newRotate}°)` : '';
			const badgeClasses = `${newTop < 8 ? ' is-badge-bottom' : ''}${(newTop + newHeight) > 92 ? ' is-badge-inside' : ''}`;
			const boxPrefix = isAiImage ? '✨ ' : (isImage ? '🖼️ ' : '');

			// 1. Add Pill
			const pillIconHtml = isAiImage
				? '<span class="mr-tee-pill-type-icon" aria-hidden="true">✨</span>'
				: (isImage ? '<span class="mr-tee-pill-type-icon" aria-hidden="true">🖼️</span>' : '');
			const pillHtml = `
				<button
					type="button"
					class="mr-tee-field-pill ${isImage ? 'is-image-pill' : ''} ${isAiImage ? 'is-ai-image-pill' : ''}"
					data-field-index="${newIdx}"
					data-field-type="${normType}"
					data-side-id="${safeSideId}"
					style="--pill-color: ${color};"
				>
					<span class="mr-tee-pill-badge" style="background: ${color};">${newIdx + 1}</span>
					${pillIconHtml}
					<span class="mr-tee-pill-label">${safeLabel}</span>
					<span class="mr-tee-pill-dims">${newWidth}×${newHeight}%${rotTxt}</span>
				</button>
			`;
			$('#mr-tee-pills-list').append(pillHtml);

			// 2. Add Box (8 resize handles + rotation stem & handle, exact parity with VisualPlacementStage.php)
			const boxHtml = `
				<div
					id="mr-tee-field-box-${newIdx}"
					class="mr-tee-field-box ${isImage ? 'is-image-field' : ''} ${isAiImage ? 'is-ai-image-field' : ''}${badgeClasses}"
					data-field-index="${newIdx}"
					data-field-type="${normType}"
					data-side-id="${safeSideId}"
					style="left: ${newLeft}%; top: ${newTop}%; width: ${newWidth}%; height: ${newHeight}%; transform: rotate(${newRotate}deg); transform-origin: center center; --field-color: ${color};"
				>
					<span class="mr-tee-box-badge" style="background: ${color};">
						${newIdx + 1}. ${boxPrefix}${safeLabel}
					</span>
					<div class="mr-tee-box-svg-holder" id="mr-tee-box-svg-${newIdx}"></div>
					<div class="mr-tee-handle-rot-stem" style="background: ${color};"></div>
					<div class="mr-tee-handle-rot" data-handle="rot" style="border-color: ${color};" title="Faire pivoter la zone"></div>
					<div class="mr-tee-handle mr-tee-handle-nw" data-handle="nw" style="border-color: ${color};"></div>
					<div class="mr-tee-handle mr-tee-handle-ne" data-handle="ne" style="border-color: ${color};"></div>
					<div class="mr-tee-handle mr-tee-handle-sw" data-handle="sw" style="border-color: ${color};"></div>
					<div class="mr-tee-handle mr-tee-handle-se" data-handle="se" style="border-color: ${color};"></div>
					<div class="mr-tee-handle mr-tee-handle-edge mr-tee-handle-n" data-handle="n" style="border-color: ${color};"></div>
					<div class="mr-tee-handle mr-tee-handle-edge mr-tee-handle-s" data-handle="s" style="border-color: ${color};"></div>
					<div class="mr-tee-handle mr-tee-handle-edge mr-tee-handle-e" data-handle="e" style="border-color: ${color};"></div>
					<div class="mr-tee-handle mr-tee-handle-edge mr-tee-handle-w" data-handle="w" style="border-color: ${color};"></div>
				</div>
			`;
			$('#mr-tee-boxes-container').append(boxHtml);

			// 3. Add Inspector Panel (100% DOM parity with VisualPlacementStage.php)
			const textInputHtml = multiline
				? `<textarea rows="2" id="mr_tee_field_text_${newIdx}" name="mr_tee_config[text_lines][${newIdx}][default_text]" class="regular-text mr-tee-field-text-input" data-field-index="${newIdx}">${safeDefaultText}</textarea>`
				: `<input type="text" id="mr_tee_field_text_${newIdx}" name="mr_tee_config[text_lines][${newIdx}][default_text]" value="${safeDefaultText}" class="regular-text mr-tee-field-text-input" data-field-index="${newIdx}" />`;

			const imgPreviewHtml = defaultImage
				? `<img src="${safeDefaultImage}" alt="" loading="lazy" decoding="async" class="mr-tee-default-img-preview" />`
				: '<span class="mr-tee-default-img-empty">Aucune image par défaut</span>';

			const panelHtml = `
				<div
					class="mr-tee-field-panel ${isImage ? 'is-image-panel' : ''} ${isAiImage ? 'is-ai-image-panel' : ''}"
					id="mr-tee-field-panel-${newIdx}"
					data-field-index="${newIdx}"
					data-field-type="${normType}"
					data-side-id="${safeSideId}"
				>
					<input type="hidden" name="mr_tee_config[text_lines][${newIdx}][id]" value="${fieldId}" class="mr-tee-field-id" data-field-index="${newIdx}" />
					<input type="hidden" name="mr_tee_config[text_lines][${newIdx}][ref_id]" value="${refId}" class="mr-tee-field-ref-id" data-field-index="${newIdx}" />
					<input type="hidden" name="mr_tee_config[text_lines][${newIdx}][color_ref_id]" value="${colorRefId}" class="mr-tee-field-color-ref-id" data-field-index="${newIdx}" />
					<input type="hidden" id="mr_tee_field_placements_${newIdx}" name="mr_tee_config[text_lines][${newIdx}][placements]" value="${placementsJsonAttr}" class="mr-tee-field-placements-json" data-field-index="${newIdx}" />
					<div class="mr-tee-panel-badge-row">
						<span class="mr-tee-panel-indicator" style="background: ${color};"></span>
						<strong class="mr-tee-panel-title">Champ #${newIdx + 1} : ${safeLabel}</strong>
					</div>
					<table class="form-table mr-tee-coords-table">
						<tr>
							<th><label for="mr_tee_field_label_${newIdx}" class="mr-tee-lbl-label">Nom</label></th>
							<td>
								<input type="text" id="mr_tee_field_label_${newIdx}" name="mr_tee_config[text_lines][${newIdx}][label]" value="${safeLabel}" class="regular-text mr-tee-field-label-input" data-field-index="${newIdx}" />
							</td>
						</tr>
						<tr>
							<th><label for="mr_tee_field_type_${newIdx}" class="mr-tee-lbl-type">Type</label></th>
							<td>
								<select id="mr_tee_field_type_${newIdx}" name="mr_tee_config[text_lines][${newIdx}][field_type]" class="mr-tee-field-type-select" data-field-index="${newIdx}">
									<option value="text" ${!isImage ? 'selected' : ''}>📝 Champ Texte</option>
									<option value="image" ${isPlainImage ? 'selected' : ''}>🖼️ Champ Image</option>
									<option value="ai_image" ${isAiImage ? 'selected' : ''}>✨ Champ Image IA</option>
								</select>
							</td>
						</tr>
						<tr>
							<th><label for="mr_tee_field_side_${newIdx}" class="mr-tee-lbl-side">Face</label></th>
							<td>
								<select id="mr_tee_field_side_${newIdx}" name="mr_tee_config[text_lines][${newIdx}][side_id]" class="mr-tee-field-side-select" data-field-index="${newIdx}">
									${sideSelectOptions}
								</select>
							</td>
						</tr>
						<tr class="mr-tee-row-image-only" ${isImage ? '' : 'style="display:none;"'}>
							<th><label for="mr_tee_field_default_image_${newIdx}" class="mr-tee-lbl-default-image">${isAiImage ? 'Aperçu par défaut' : 'Image'}</label></th>
							<td>
								<div class="mr-tee-default-img-picker" data-field-index="${newIdx}">
									<div class="mr-tee-default-img-preview-wrap">${imgPreviewHtml}</div>
									<input type="hidden" name="mr_tee_config[text_lines][${newIdx}][default_image_id]" value="${defaultImageId}" class="mr-tee-field-default-image-id" data-field-index="${newIdx}" />
									<input type="text" id="mr_tee_field_default_image_${newIdx}" name="mr_tee_config[text_lines][${newIdx}][default_image]" value="${safeDefaultImage}" placeholder="https://..." class="regular-text mr-tee-field-default-image-input" data-field-index="${newIdx}" />
									<div class="mr-tee-default-img-actions">
										<button type="button" class="button button-secondary button-small mr-tee-btn-select-default-image" data-field-index="${newIdx}"><span aria-hidden="true">📁</span> Médiathèque</button>
										<label class="button button-small mr-tee-upload-btn-label">
											<span><span aria-hidden="true">⬆️</span> Importer</span>
											<input type="file" accept="image/*" class="screen-reader-text mr-tee-quick-image-file" data-field-index="${newIdx}" />
										</label>
										<button type="button" class="button button-small mr-tee-btn-clear-default-image" data-field-index="${newIdx}" aria-label="Retirer l'image" ${defaultImage ? '' : 'style="display:none;"'}>✕</button>
									</div>
									<span class="mr-tee-img-upload-status" aria-live="polite"></span>
								</div>
							</td>
						</tr>
						<tr class="mr-tee-row-image-only" ${isImage ? '' : 'style="display:none;"'}>
							<th><label for="mr_tee_field_image_fit_${newIdx}" class="mr-tee-lbl-image-fit">Ajustement</label></th>
							<td>
								<select id="mr_tee_field_image_fit_${newIdx}" name="mr_tee_config[text_lines][${newIdx}][image_fit]" class="mr-tee-field-image-fit" data-field-index="${newIdx}">
									<option value="contain" ${imageFit === 'contain' ? 'selected' : ''}>Entier (Contain — sans rogner)</option>
									<option value="cover" ${imageFit === 'cover' ? 'selected' : ''}>Remplir (Cover — recadré)</option>
								</select>
							</td>
						</tr>
						<tr class="mr-tee-row-ai-only" ${isAiImage ? '' : 'style="display:none;"'}>
							<th><label for="mr_tee_field_ai_model_${newIdx}" class="mr-tee-lbl-ai-model">Modèle IA</label></th>
							<td>
								<div class="mr-tee-ai-model-select-row">
									<select id="mr_tee_field_ai_model_${newIdx}" name="mr_tee_config[text_lines][${newIdx}][ai_model]" class="mr-tee-field-ai-model" data-field-index="${newIdx}">
										${aiModelSelectOptions}
									</select>
									<button type="button" class="button button-small mr-tee-btn-open-ai-manager-tab" title="Gérer la clé API et les modèles IA">✨ Gérer</button>
								</div>
								${!aiHasKey ? '<p class="description mr-tee-ai-key-warn">⚠️ Aucune clé API Runware configurée. Ouvrez l\'onglet ✨ IA pour l\'ajouter.</p>' : ''}
							</td>
						</tr>
						<tr class="mr-tee-row-ai-only" ${isAiImage ? '' : 'style="display:none;"'}>
							<th><span>Références IA</span></th>
							<td>
								<div class="mr-tee-ai-refs-box" data-field-index="${newIdx}">
									<label class="mr-tee-ai-use-default-ref-label">
										<input type="hidden" name="mr_tee_config[text_lines][${newIdx}][ai_use_default_as_ref]" value="0" />
										<input type="checkbox" name="mr_tee_config[text_lines][${newIdx}][ai_use_default_as_ref]" value="1" class="mr-tee-field-ai-use-def-ref" data-field-index="${newIdx}" ${aiUseDefAsRef ? 'checked' : ''} />
										Utiliser l'aperçu par défaut comme Image 1
									</label>
									<input type="hidden" name="mr_tee_config[text_lines][${newIdx}][ai_reference_images]" value="${aiRefsJsonAttr}" class="mr-tee-field-ai-refs-json" data-field-index="${newIdx}" />
									<div class="mr-tee-ai-refs-strip" data-field-index="${newIdx}"></div>
									<div class="mr-tee-ai-refs-actions">
										<button type="button" class="button button-small mr-tee-btn-add-ai-ref" data-field-index="${newIdx}"><span aria-hidden="true">📁</span> + Référence IA</button>
										<span class="description">Cachée au client.</span>
									</div>
								</div>
							</td>
						</tr>
						<tr class="mr-tee-row-ai-only" ${isAiImage ? '' : 'style="display:none;"'}>
							<th><label for="mr_tee_field_ai_prompt_${newIdx}" class="mr-tee-lbl-ai-prompt">Prompt IA</label></th>
							<td>
								<div class="mr-tee-ai-prompt-legend" data-field-index="${newIdx}"></div>
								<textarea rows="3" id="mr_tee_field_ai_prompt_${newIdx}" name="mr_tee_config[text_lines][${newIdx}][ai_prompt]" class="large-text mr-tee-field-ai-prompt" data-field-index="${newIdx}" placeholder="Ex : Swap the person wearing the blue t-shirt in image 1 with the person in image 2. Keep the celebrity, pose, lighting and background identical.">${safeAiPrompt}</textarea>
								<p class="description">Caché au client. Utilisez « image 1 », « image 2 » (ou {{customer_image}}) pour désigner chaque photo.</p>
							</td>
						</tr>
						<tr class="mr-tee-row-ai-only" ${isAiImage ? '' : 'style="display:none;"'}>
							<th><label for="mr_tee_field_ai_size_mode_${newIdx}" class="mr-tee-lbl-ai-size-mode">Cadrage IA</label></th>
							<td>
								<select id="mr_tee_field_ai_size_mode_${newIdx}" name="mr_tee_config[text_lines][${newIdx}][ai_size_mode]" class="mr-tee-field-ai-size-mode" data-field-index="${newIdx}">
									<option value="auto_box" ${aiSizeMode === 'auto_box' ? 'selected' : ''}>Adapter automatiquement à la zone définie</option>
									<option value="match_reference" ${aiSizeMode === 'match_reference' ? 'selected' : ''}>Conserver le format de l'image de référence</option>
								</select>
								<div class="mr-tee-ai-suboptions">
									<label class="mr-tee-inline-check">
										<input type="hidden" name="mr_tee_config[text_lines][${newIdx}][ai_remove_bg]" value="0" />
										<input type="checkbox" name="mr_tee_config[text_lines][${newIdx}][ai_remove_bg]" value="1" class="mr-tee-field-ai-remove-bg" data-field-index="${newIdx}" ${aiRemoveBg ? 'checked' : ''} />
										Détourer automatiquement le fond (PNG transparent)
									</label>
								</div>
							</td>
						</tr>
						<tr class="mr-tee-row-ai-only" ${isAiImage ? '' : 'style="display:none;"'}>
							<th><label for="mr_tee_field_ai_btn_label_${newIdx}" class="mr-tee-lbl-ai-btn-label">Bouton client</label></th>
							<td>
								<input type="text" id="mr_tee_field_ai_btn_label_${newIdx}" name="mr_tee_config[text_lines][${newIdx}][ai_button_label]" value="${safeAiBtnLabel}" class="regular-text mr-tee-field-ai-btn-label" data-field-index="${newIdx}" placeholder="Générer" />
								<div class="mr-tee-ai-admin-test-row" data-field-index="${newIdx}">
									<label class="button button-small">
										<span>📷 Photo test</span>
										<input type="file" accept="image/png,image/jpeg,image/webp" class="screen-reader-text mr-tee-ai-admin-test-file" data-field-index="${newIdx}" />
									</label>
									<button type="button" class="button button-secondary button-small mr-tee-btn-ai-admin-test" data-field-index="${newIdx}"><span aria-hidden="true">✨</span> Tester la génération</button>
									<span class="mr-tee-ai-admin-test-status" aria-live="polite"></span>
								</div>
							</td>
						</tr>
						<tr class="mr-tee-row-text-only" ${isImage ? 'style="display:none;"' : ''}>
							<th><label for="mr_tee_field_text_${newIdx}" class="mr-tee-lbl-text">Texte</label></th>
							<td>${textInputHtml}</td>
						</tr>
						<tr class="mr-tee-row-text-only" ${isImage ? 'style="display:none;"' : ''}>
							<th><label for="mr_tee_field_font_${newIdx}" class="mr-tee-lbl-font">Police</label></th>
							<td>
								<select id="mr_tee_field_font_${newIdx}" name="mr_tee_config[text_lines][${newIdx}][font]" class="mr-tee-field-font-select" data-field-index="${newIdx}">
									${fontSelectOptions}
								</select>
							</td>
						</tr>
						<tr class="mr-tee-row-text-only mr-tee-row-svg-color-notice" ${!isImage && isSvg ? '' : 'style="display:none;"'}>
							<th><span>Couleur</span></th>
							<td><span class="mr-tee-svg-color-notice"><span aria-hidden="true">✨</span> Couleurs intégrées à la police SVG</span></td>
						</tr>
						<tr class="mr-tee-row-text-only mr-tee-row-color-mode" ${!isImage && !isSvg ? '' : 'style="display:none;"'}>
							<th><label for="mr_tee_field_color_mode_${newIdx}" class="mr-tee-lbl-color-mode">Mode Couleur</label></th>
							<td>
								<select id="mr_tee_field_color_mode_${newIdx}" name="mr_tee_config[text_lines][${newIdx}][color_mode]" class="mr-tee-field-color-mode" data-field-index="${newIdx}">
									<option value="multi" ${colorMode === 'multi' ? 'selected' : ''}>Multicolore (Palette)</option>
									<option value="solid" ${colorMode === 'solid' ? 'selected' : ''}>Couleur Unie</option>
								</select>
								<label class="mr-tee-inline-check">
									<input type="hidden" name="mr_tee_config[text_lines][${newIdx}][allow_color_pick]" value="0" />
									<input type="checkbox" name="mr_tee_config[text_lines][${newIdx}][allow_color_pick]" value="1" class="mr-tee-field-allow-color-pick" data-field-index="${newIdx}" ${allowColorPick ? 'checked' : ''} />
									Choix de couleur client
								</label>
							</td>
						</tr>
						<tr class="mr-tee-row-text-only mr-tee-row-solid-color" ${!isImage && !isSvg && colorMode === 'solid' ? '' : 'style="display:none;"'}>
							<th><label for="mr_tee_field_color_hex_${newIdx}" class="mr-tee-lbl-color-hex">Couleur du texte</label></th>
							<td>
								<div class="mr-tee-color-picker-row">
									<input type="color" id="mr_tee_field_color_hex_${newIdx}" name="mr_tee_config[text_lines][${newIdx}][color_hex]" value="${this.escapeXml(colorHex)}" class="mr-tee-field-color-hex" data-field-index="${newIdx}" />
									<span class="mr-tee-color-hex-readout">${this.escapeXml(colorHex.toUpperCase())}</span>
								</div>
							</td>
						</tr>
						<tr class="mr-tee-row-text-only" ${isImage ? 'style="display:none;"' : ''}>
							<th><label for="mr_tee_field_max_chars_${newIdx}" class="mr-tee-lbl-max-chars">Max Caract.</label></th>
							<td>
								<input type="number" step="1" min="1" max="500" id="mr_tee_field_max_chars_${newIdx}" name="mr_tee_config[text_lines][${newIdx}][max_chars]" value="${maxChars}" class="small-text mr-tee-field-max-chars" data-field-index="${newIdx}" />
							</td>
						</tr>
						<tr>
							<th><span>Options</span></th>
							<td class="mr-tee-options-cell">
								<span class="mr-tee-opt-text-only" ${isImage ? 'style="display:none;"' : ''}>
									<label>
										<input type="hidden" name="mr_tee_config[text_lines][${newIdx}][allow_font_pick]" value="0" />
										<input type="checkbox" name="mr_tee_config[text_lines][${newIdx}][allow_font_pick]" value="1" ${allowFontPick ? 'checked' : ''} class="mr-tee-field-font-pick" data-field-index="${newIdx}" />
										Choix de police client
									</label>
									<label>
										<input type="hidden" name="mr_tee_config[text_lines][${newIdx}][multiline]" value="0" />
										<input type="checkbox" name="mr_tee_config[text_lines][${newIdx}][multiline]" value="1" ${multiline ? 'checked' : ''} class="mr-tee-field-multiline" data-field-index="${newIdx}" />
										Autoriser retours à la ligne
									</label>
								</span>
								<span class="mr-tee-opt-image-only" ${isPlainImage ? '' : 'style="display:none;"'}>
									<label>
										<input type="hidden" name="mr_tee_config[text_lines][${newIdx}][allow_image_upload]" value="0" />
										<input type="checkbox" name="mr_tee_config[text_lines][${newIdx}][allow_image_upload]" value="1" ${allowImageUpload ? 'checked' : ''} class="mr-tee-field-allow-image-upload" data-field-index="${newIdx}" />
										Import image par le client
									</label>
								</span>
								<label>
									<input type="hidden" name="mr_tee_config[text_lines][${newIdx}][warning_unedited]" value="0" />
									<input type="checkbox" name="mr_tee_config[text_lines][${newIdx}][warning_unedited]" value="1" ${warningUnedited ? 'checked' : ''} class="mr-tee-field-warning" data-field-index="${newIdx}" />
									Alerter si non personnalisé
								</label>
							</td>
						</tr>
					</table>
					<div class="mr-tee-geom-section">
						<div class="mr-tee-geom-grid">
							<label class="mr-tee-geom-cell">
								<span>X %</span>
								<input type="number" step="any" min="0" max="100" id="mr_tee_field_left_${newIdx}" name="mr_tee_config[text_lines][${newIdx}][box_left]" value="${newLeft}" class="mr-tee-coord-input mr-tee-field-coord mr-tee-field-coord-left" data-field-index="${newIdx}" />
							</label>
							<label class="mr-tee-geom-cell">
								<span>Y %</span>
								<input type="number" step="any" min="0" max="100" id="mr_tee_field_top_${newIdx}" name="mr_tee_config[text_lines][${newIdx}][box_top]" value="${newTop}" class="mr-tee-coord-input mr-tee-field-coord mr-tee-field-coord-top" data-field-index="${newIdx}" />
							</label>
							<label class="mr-tee-geom-cell">
								<span>Largeur %</span>
								<input type="number" step="any" min="2" max="100" id="mr_tee_field_width_${newIdx}" name="mr_tee_config[text_lines][${newIdx}][box_width]" value="${newWidth}" class="mr-tee-coord-input mr-tee-field-coord mr-tee-field-coord-width" data-field-index="${newIdx}" />
							</label>
							<label class="mr-tee-geom-cell">
								<span>Hauteur %</span>
								<input type="number" step="any" min="2" max="100" id="mr_tee_field_height_${newIdx}" name="mr_tee_config[text_lines][${newIdx}][box_height]" value="${newHeight}" class="mr-tee-coord-input mr-tee-field-coord mr-tee-field-coord-height" data-field-index="${newIdx}" />
							</label>
						</div>
						<div class="mr-tee-rotate-inline">
							<label for="mr_tee_field_rotate_${newIdx}" class="mr-tee-rotate-label">Rotation °</label>
							<input type="number" step="1" min="-180" max="180" id="mr_tee_field_rotate_${newIdx}" name="mr_tee_config[text_lines][${newIdx}][box_rotate]" value="${newRotate}" class="mr-tee-coord-input mr-tee-field-coord mr-tee-field-coord-rotate" data-field-index="${newIdx}" />
							<div class="mr-tee-rotate-presets">
								<button type="button" class="button button-small mr-tee-btn-rotate-preset" data-field-index="${newIdx}" data-deg="0">0°</button>
								<button type="button" class="button button-small mr-tee-btn-rotate-preset" data-field-index="${newIdx}" data-deg="-90">-90°</button>
								<button type="button" class="button button-small mr-tee-btn-rotate-preset" data-field-index="${newIdx}" data-deg="90">90°</button>
							</div>
						</div>
					</div>
					<div class="mr-tee-field-actions">
						<button type="button" class="button button-small mr-tee-btn-duplicate-field" data-field-index="${newIdx}"><svg class="mr-tee-icon" width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect x="9" y="9" width="13" height="13" rx="2" ry="2"/><path d="M5 15H4a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2h9a2 2 0 0 1 2 2v1"/></svg>Dupliquer</button>
						<button type="button" class="button button-small button-link-delete mr-tee-btn-delete-field" data-field-index="${newIdx}">Supprimer</button>
					</div>
				</div>
			`;
			$('#mr-tee-field-panels-container').append(panelHtml);

			if (isAiImage) {
				this.renderAiRefsStrip(newIdx, aiRefs);
				this.syncAiPromptLegend(newIdx);
			}

			$('.mr-tee-btn-delete-field').show();
			this.syncFieldColorUi(newIdx);
			this.updateEmptySideNotice(activeSideId, false);
			this.renderSidesBar();
			this.setActiveField(newIdx);
			this.renderBoxPreview(newIdx);
			this.recalculateDimensions();
			this.resetHistory();
			this.ensureHistoryBase();
			this.markDirty();
		}

		/**
		 * Reorder Active Field (◀ / ▶) to change storefront form order.
		 */
		bindFieldReorder() {
			const self = this;
			const moveField = (delta) => {
				const $pills = $('#mr-tee-pills-list .mr-tee-field-pill');
				const $boxes = $('#mr-tee-boxes-container .mr-tee-field-box');
				const $panels = $('#mr-tee-field-panels-container .mr-tee-field-panel');
				const count = $boxes.length;
				const idx = self.activeFieldIndex;
				const targetIdx = idx + delta;
				if (count <= 1 || idx < 0 || targetIdx < 0 || targetIdx >= count) return;

				if (delta < 0) {
					$pills.eq(idx).insertBefore($pills.eq(targetIdx));
					$boxes.eq(idx).insertBefore($boxes.eq(targetIdx));
					$panels.eq(idx).insertBefore($panels.eq(targetIdx));
				} else {
					$pills.eq(idx).insertAfter($pills.eq(targetIdx));
					$boxes.eq(idx).insertAfter($boxes.eq(targetIdx));
					$panels.eq(idx).insertAfter($panels.eq(targetIdx));
				}

				self.reindexFields();
				self.setActiveField(targetIdx);
				self.renderAllBoxesPreviews();
				self.recalculateDimensions();
				self.resetHistory();
				self.ensureHistoryBase();
				self.markDirty();
			};

			$(document).on('click', '#mr-tee-btn-field-move-left', function(e) {
				e.preventDefault();
				moveField(-1);
			});

			$(document).on('click', '#mr-tee-btn-field-move-right', function(e) {
				e.preventDefault();
				moveField(1);
			});
		}

		/**
		 * Delete a Field Rectangle (handles deleting the last field on the active side cleanly).
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

				$(`.mr-tee-field-pill[data-field-index="${idx}"]`).remove();
				$(`#mr-tee-field-box-${idx}`).remove();
				$(`#mr-tee-field-panel-${idx}`).remove();

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
					self.setActiveField(-1);
				}
				self.renderAllBoxesPreviews();
				self.recalculateDimensions();
				self.resetHistory();
				self.ensureHistoryBase();
				self.markDirty();
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
					.attr('id', `mr_tee_field_side_${i}`)
					.attr('name', `mr_tee_config[text_lines][${i}][side_id]`)
					.attr('data-field-index', i);
				$(this).find('.mr-tee-lbl-side').attr('for', `mr_tee_field_side_${i}`);
				$(this).find('.mr-tee-panel-indicator').css('background', color);
				$(this).find('.mr-tee-panel-title').text(`Champ #${i + 1} : ${labelVal}`);
				$(this).find('.mr-tee-field-label-input').attr('id', `mr_tee_field_label_${i}`).attr('name', `mr_tee_config[text_lines][${i}][label]`).attr('data-field-index', i);
				$(this).find('.mr-tee-lbl-label').attr('for', `mr_tee_field_label_${i}`);
				$(this).find('.mr-tee-field-type-select').attr('id', `mr_tee_field_type_${i}`).attr('name', `mr_tee_config[text_lines][${i}][field_type]`).attr('data-field-index', i);
				$(this).find('.mr-tee-lbl-type').attr('for', `mr_tee_field_type_${i}`);
				$(this).find('.mr-tee-default-img-picker').attr('data-field-index', i);
				$(this).find('.mr-tee-field-default-image-id').attr('name', `mr_tee_config[text_lines][${i}][default_image_id]`).attr('data-field-index', i);
				$(this).find('.mr-tee-field-default-image-input').attr('id', `mr_tee_field_default_image_${i}`).attr('name', `mr_tee_config[text_lines][${i}][default_image]`).attr('data-field-index', i);
				$(this).find('.mr-tee-lbl-default-image').attr('for', `mr_tee_field_default_image_${i}`);
				$(this).find('.mr-tee-btn-select-default-image, .mr-tee-quick-image-file, .mr-tee-btn-clear-default-image').attr('data-field-index', i);
				$(this).find('.mr-tee-field-image-fit').attr('id', `mr_tee_field_image_fit_${i}`).attr('name', `mr_tee_config[text_lines][${i}][image_fit]`).attr('data-field-index', i);
				$(this).find('.mr-tee-lbl-image-fit').attr('for', `mr_tee_field_image_fit_${i}`);
				$(this).find('.mr-tee-field-ai-model').attr('id', `mr_tee_field_ai_model_${i}`).attr('name', `mr_tee_config[text_lines][${i}][ai_model]`).attr('data-field-index', i);
				$(this).find('.mr-tee-lbl-ai-model').attr('for', `mr_tee_field_ai_model_${i}`);
				$(this).find('.mr-tee-ai-refs-box, .mr-tee-ai-refs-strip, .mr-tee-btn-add-ai-ref, .mr-tee-ai-ref-remove, .mr-tee-ai-prompt-legend, .mr-tee-ai-admin-test-row, .mr-tee-ai-admin-test-file, .mr-tee-btn-ai-admin-test').attr('data-field-index', i);
				$(this).find('input[type="hidden"][name*="[ai_use_default_as_ref]"]').attr('name', `mr_tee_config[text_lines][${i}][ai_use_default_as_ref]`);
				$(this).find('.mr-tee-field-ai-use-def-ref').attr('name', `mr_tee_config[text_lines][${i}][ai_use_default_as_ref]`).attr('data-field-index', i);
				$(this).find('.mr-tee-field-ai-refs-json').attr('name', `mr_tee_config[text_lines][${i}][ai_reference_images]`).attr('data-field-index', i);
				$(this).find('.mr-tee-field-ai-prompt').attr('id', `mr_tee_field_ai_prompt_${i}`).attr('name', `mr_tee_config[text_lines][${i}][ai_prompt]`).attr('data-field-index', i);
				$(this).find('.mr-tee-lbl-ai-prompt').attr('for', `mr_tee_field_ai_prompt_${i}`);
				$(this).find('.mr-tee-field-ai-size-mode').attr('id', `mr_tee_field_ai_size_mode_${i}`).attr('name', `mr_tee_config[text_lines][${i}][ai_size_mode]`).attr('data-field-index', i);
				$(this).find('.mr-tee-lbl-ai-size-mode').attr('for', `mr_tee_field_ai_size_mode_${i}`);
				$(this).find('input[type="hidden"][name*="[ai_remove_bg]"]').attr('name', `mr_tee_config[text_lines][${i}][ai_remove_bg]`);
				$(this).find('.mr-tee-field-ai-remove-bg').attr('name', `mr_tee_config[text_lines][${i}][ai_remove_bg]`).attr('data-field-index', i);
				$(this).find('.mr-tee-field-ai-btn-label').attr('id', `mr_tee_field_ai_btn_label_${i}`).attr('name', `mr_tee_config[text_lines][${i}][ai_button_label]`).attr('data-field-index', i);
				$(this).find('.mr-tee-lbl-ai-btn-label').attr('for', `mr_tee_field_ai_btn_label_${i}`);
				$(this).find('.mr-tee-field-text-input').attr('id', `mr_tee_field_text_${i}`).attr('name', `mr_tee_config[text_lines][${i}][default_text]`).attr('data-field-index', i);
				$(this).find('.mr-tee-lbl-text').attr('for', `mr_tee_field_text_${i}`);
				$(this).find('.mr-tee-field-font-select').attr('id', `mr_tee_field_font_${i}`).attr('name', `mr_tee_config[text_lines][${i}][font]`).attr('data-field-index', i);
				$(this).find('.mr-tee-lbl-font').attr('for', `mr_tee_field_font_${i}`);
				$(this).find('.mr-tee-field-color-mode').attr('id', `mr_tee_field_color_mode_${i}`).attr('name', `mr_tee_config[text_lines][${i}][color_mode]`).attr('data-field-index', i);
				$(this).find('.mr-tee-lbl-color-mode').attr('for', `mr_tee_field_color_mode_${i}`);
				$(this).find('.mr-tee-field-color-hex').attr('id', `mr_tee_field_color_hex_${i}`).attr('name', `mr_tee_config[text_lines][${i}][color_hex]`).attr('data-field-index', i);
				$(this).find('.mr-tee-lbl-color-hex').attr('for', `mr_tee_field_color_hex_${i}`);
				$(this).find('input[type="hidden"][name*="[allow_color_pick]"]').attr('name', `mr_tee_config[text_lines][${i}][allow_color_pick]`);
				$(this).find('.mr-tee-field-allow-color-pick').attr('name', `mr_tee_config[text_lines][${i}][allow_color_pick]`).attr('data-field-index', i);
				$(this).find('.mr-tee-field-max-chars').attr('id', `mr_tee_field_max_chars_${i}`).attr('name', `mr_tee_config[text_lines][${i}][max_chars]`).attr('data-field-index', i);
				$(this).find('.mr-tee-lbl-max-chars').attr('for', `mr_tee_field_max_chars_${i}`);
				$(this).find('input[type="hidden"][name*="[allow_font_pick]"]').attr('name', `mr_tee_config[text_lines][${i}][allow_font_pick]`);
				$(this).find('.mr-tee-field-font-pick').attr('name', `mr_tee_config[text_lines][${i}][allow_font_pick]`).attr('data-field-index', i);
				$(this).find('input[type="hidden"][name*="[multiline]"]').attr('name', `mr_tee_config[text_lines][${i}][multiline]`);
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
				$(this).find('.mr-tee-rotate-label').attr('for', `mr_tee_field_rotate_${i}`);
				$(this).find('.mr-tee-btn-rotate-preset').attr('data-field-index', i);
				$(this).find('.mr-tee-btn-duplicate-field').attr('data-field-index', i);
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
				const rawType = $panel.find('.mr-tee-field-type-select').val() || $panel.attr('data-field-type') || 'text';
				const prefix = rawType === 'ai_image' ? '✨ ' : (rawType === 'image' ? '🖼️ ' : '');
				const labelVal = $panel.find('.mr-tee-field-label-input').val() || `Champ ${i + 1}`;
				const sideVal = $panel.attr('data-side-id') || $(this).attr('data-side-id') || 'front';
				$(this).attr('id', `mr-tee-field-box-${i}`);
				$(this).attr('data-field-index', i);
				$(this).attr('data-side-id', sideVal);
				$(this).css('--field-color', color);
				$(this).find('.mr-tee-box-badge').text(`${i + 1}. ${prefix}${labelVal}`).css('background', color);
				$(this).find('.mr-tee-box-svg-holder').attr('id', `mr-tee-box-svg-${i}`);
				$(this).find('.mr-tee-handle').css('border-color', color);
				$(this).find('.mr-tee-handle-rot-stem').css('background', color);
				$(this).find('.mr-tee-handle-rot').css('border-color', color);
				self.syncBoxBadgePosition(i);
			});

			if ($boxes.length <= 1) {
				$('.mr-tee-btn-delete-field').hide();
			} else {
				$('.mr-tee-btn-delete-field').show();
			}
			this.updateFieldToolbarState();
		}

		/**
		 * Render live SVG inside each rectangle box visible on the active side.
		 * Coalesced via requestAnimationFrame so rapid boot/resize triggers never render redundantly.
		 */
		renderAllBoxesPreviews(opts = {}) {
			if (opts && opts.immediate) {
				if (this._renderAllRaf) {
					cancelAnimationFrame(this._renderAllRaf);
					this._renderAllRaf = null;
				}
				this._renderAllBoxesNow();
				return;
			}
			if (this._renderAllRaf) return;
			this._renderAllRaf = requestAnimationFrame(() => {
				this._renderAllRaf = null;
				this._renderAllBoxesNow();
			});
		}

		_renderAllBoxesNow() {
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
			let defaultSpacing = 0.05;
			if (f.includes('bebas')) { charRatio = 0.42; defaultSpacing = 0.10; }
			else if (f.includes('press start') || f.includes('pressstart') || f.includes('arcade')) { charRatio = 0.72; defaultSpacing = 0.05; }
			else if (f.includes('super mario') || f.includes('mario')) { charRatio = 0.62; defaultSpacing = 0.15; }
			else if (f.includes('luckiest')) { charRatio = 0.64; defaultSpacing = 0.06; }
			else if (f.includes('bungee')) { charRatio = 0.65; defaultSpacing = 0.08; }
			else if (f.includes('cocogoose')) { charRatio = 0.60; defaultSpacing = 0.12; }
			else if (f.includes('pacifico')) { charRatio = 0.48; defaultSpacing = 0.02; }
			else if (f.includes('cinzel')) { charRatio = 0.62; defaultSpacing = 0.10; }
			else if (f.includes('marker')) { charRatio = 0.58; defaultSpacing = 0.05; }
			else if (f.includes('abril')) { charRatio = 0.55; defaultSpacing = 0.04; }
			const letterSpacingEm = (fontDef && fontDef.letter_spacing !== undefined) ? parseFloat(fontDef.letter_spacing) : defaultSpacing;
			return { charRatio, letterSpacingEm };
		}

		getAdminLineConfig(idx) {
			const lines = (this.config && Array.isArray(this.config.text_lines)) ? this.config.text_lines : [];
			if (!lines.length) return null;
			const idEl = document.querySelector(`input[name="mr_tee_config[text_lines][${idx}][id]"]`);
			const fieldId = idEl ? String(idEl.value || '').trim() : '';
			if (fieldId) {
				const found = lines.find(l => l && String(l.id || '') === fieldId);
				if (found) return found;
			}
			return lines[idx] || null;
		}

		buildAdminFontStack(rawFont) {
			const raw = String(rawFont || 'sans-serif');
			if (raw.includes(',') || raw.includes('sans-serif') || raw.includes('serif') || raw.includes('cursive') || raw.includes('monospace')) {
				return raw;
			}
			const fLower = raw.toLowerCase();
			let genericFallback = 'sans-serif';
			if (fLower.includes('pacifico') || fLower.includes('cursive') || fLower.includes('script') || fLower.includes('marker')) {
				genericFallback = 'cursive, sans-serif';
			} else if (fLower.includes('cinzel') || fLower.includes('abril') || fLower.includes('serif')) {
				genericFallback = 'Georgia, serif';
			} else if (fLower.includes('press start') || fLower.includes('monospace') || fLower.includes('arcade')) {
				genericFallback = 'monospace';
			} else if (fLower.includes('bebas') || fLower.includes('bungee') || fLower.includes('luckiest') || fLower.includes('cocogoose')) {
				genericFallback = 'Impact, sans-serif';
			}
			return `'${raw}', ${genericFallback}`;
		}

		measureAdminTextWidthUnits(text, fontFamily, letterSpacingEm = 0, fontDef = null) {
			const str = String(text || '');
			if (!str) return 0;

			const spacing = Number.isFinite(letterSpacingEm) ? letterSpacingEm : 0;
			const charLen = Array.from(str).length;
			const spacingUnits = Math.max(0, charLen - 1) * spacing;

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
					this._adminTextWidthCache = this._adminTextWidthCache || new Map();
					const cacheKey = `${fontFamily}|${spacing.toFixed(4)}|${str}`;
					if (this._adminTextWidthCache.has(cacheKey)) {
						return this._adminTextWidthCache.get(cacheKey);
					}

					if (!this._adminMeasureCtx) {
						const canvas = document.createElement('canvas');
						this._adminMeasureCtx = canvas.getContext('2d');
					}
					if (this._adminMeasureCtx) {
						const refPx = 100;
						this._adminMeasureCtx.font = `${refPx}px ${fontFamily}`;
						const measured = this._adminMeasureCtx.measureText(str).width;
						if (measured > 0) {
							const units = (measured / refPx) + spacingUnits;
							if (this._adminTextWidthCache.size >= 500) {
								this._adminTextWidthCache.clear();
							}
							this._adminTextWidthCache.set(cacheKey, units);
							return units;
						}
					}
				}
			}

			const metrics = this.getBoxMetrics(fontDef || { family: fontFamily });
			return (charLen * metrics.charRatio) + spacingUnits;
		}

		getStageInnerRect() {
			const wrapEl = this.$canvasWrap && this.$canvasWrap[0];
			if (!wrapEl) return null;
			const r = wrapEl.getBoundingClientRect();
			const bl = wrapEl.clientLeft || 0;
			const bt = wrapEl.clientTop || 0;
			const cw = wrapEl.clientWidth || 0;
			const ch = wrapEl.clientHeight || 0;
			if (cw > 0 && ch > 0 && (bl > 0 || bt > 0)) {
				const sx = r.width / (wrapEl.offsetWidth || cw);
				const sy = r.height / (wrapEl.offsetHeight || ch);
				return {
					left: r.left + bl * sx,
					top: r.top + bt * sy,
					width: cw * sx,
					height: ch * sy,
				};
			}
			return r;
		}

		getStageAspectRatio(cachedWrapRect) {
			const wrapEl = this.$canvasWrap && this.$canvasWrap[0];
			const isLocked = Boolean(wrapEl && wrapEl.classList.contains('is-aspect-locked'));

			if (isLocked) {
				const rw = parseFloat($('#mr_tee_real_width').val()) || 0;
				const rh = parseFloat($('#mr_tee_real_height').val()) || 0;
				if (rw > 0 && rh > 0) {
					this._cachedStageRatio = rw / rh;
					return this._cachedStageRatio;
				}
			} else {
				const imgEl = document.getElementById('mr-tee-stage-garment-img');
				if (imgEl && imgEl.style.display !== 'none' && imgEl.complete && imgEl.naturalWidth > 2 && imgEl.naturalHeight > 2) {
					this._cachedStageRatio = imgEl.naturalWidth / imgEl.naturalHeight;
					return this._cachedStageRatio;
				}
			}

			if (cachedWrapRect && cachedWrapRect.width > 0 && cachedWrapRect.height > 0) {
				this._cachedStageRatio = cachedWrapRect.width / cachedWrapRect.height;
				return this._cachedStageRatio;
			}
			if (this._cachedStageRatio && this._cachedStageRatio > 0) {
				return this._cachedStageRatio;
			}
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

			// Image / AI Image field branch
			const fieldType = this.getFieldType(idx);
			if (this.isImageLikeType(fieldType)) {
				const rawImgUrl = ($(`[name="mr_tee_config[text_lines][${idx}][default_image]"]`).val() || '').trim();
				const imgUrl = this.normalizeAdminAssetUrl(rawImgUrl);
				const imgFit = $(`[name="mr_tee_config[text_lines][${idx}][image_fit]"]`).val() === 'cover' ? 'cover' : 'contain';

				if (!imgUrl) {
					const emptyMode = fieldType === 'ai_image' ? 'ai-img-empty' : 'img-empty';
					if (el._renderMode !== emptyMode) {
						el.innerHTML = fieldType === 'ai_image'
							? '<div class="mr-tee-admin-img-placeholder is-ai-placeholder"><span>✨ Zone Image IA</span><small>L\'image générée par l\'IA s\'affichera ici</small></div>'
							: '<div class="mr-tee-admin-img-placeholder"><span>🖼️ Zone Image</span><small>Choisissez une image par défaut</small></div>';
						el._renderMode = emptyMode;
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

			// Text field branch: clearing default_text renders a clean empty preview (no fake "Texte N")
			const rawTextVal = $(`[name="mr_tee_config[text_lines][${idx}][default_text]"]`).val();
			const normTextVal = rawTextVal !== undefined ? String(rawTextVal).replace(/\r\n?/g, '\n') : '';
			const textVal = (typeof normTextVal.normalize === 'function') ? normTextVal.normalize('NFC') : normTextVal;
			if (textVal.trim() === '') {
				if (el._renderMode !== 'empty') {
					el.innerHTML = '';
					el._renderMode = 'empty';
					el._glyphsKey = '';
					el._svgEl = null;
					el._groupEl = null;
					el._imgEl = null;
				}
				return;
			}

			const bw = (opts && opts.bw > 0) ? opts.bw : (parseFloat((document.getElementById(`mr_tee_field_width_${idx}`) || {}).value) || 40);
			const bh = (opts && opts.bh > 0) ? opts.bh : (parseFloat((document.getElementById(`mr_tee_field_height_${idx}`) || {}).value) || 8.5);
			const stageRatio = (opts && opts.stageRatio > 0) ? opts.stageRatio : this.getStageAspectRatio();
			let boxRatio = (bw / Math.max(0.5, bh)) * stageRatio;
			boxRatio = Math.max(0.05, Math.min(50, boxRatio || 1));
			const vbH = 100;
			const vbW = Number((vbH * boxRatio).toFixed(3));
			const unitScaleK = vbW / Math.max(0.5, bw);

			const fonts = (window.mrTeeAdminData && window.mrTeeAdminData.fonts) || {};
			const allowed = this.getAllowedFonts();
			const fallbackKey = allowed[0] || Object.keys(fonts)[0] || 'super_mario';
			const fontId = $(`select[name="mr_tee_config[text_lines][${idx}][font]"]`).val() || fallbackKey;
			const fontDef = fonts[fontId] || fonts[fallbackKey] || null;
			const savedLine = this.getAdminLineConfig(idx);
			const savedLineHeight = (savedLine && parseFloat(savedLine.line_height) > 0) ? parseFloat(savedLine.line_height) : 0.95;
			const savedCasing = (savedLine && typeof savedLine.casing === 'string' && savedLine.casing) ? savedLine.casing : '';
			const resolvedCasing = (fontDef && fontDef.uppercase) ? 'UPPER' : (savedCasing || 'ORIGINAL');

			// Handle OpenType-SVG Vector Color Fonts (100% aligned with Storefront SvgRenderer.renderOpenTypeSvgTextNode)
			if (this.isSvgFont(fontDef)) {
				const svgFonts = (window.mrTeeAdminData && window.mrTeeAdminData.svgFonts) || {};
				const svgData = svgFonts[fontId];

				if (svgData && svgData.glyphs) {
					const displayVal = (resolvedCasing === 'UPPER') ? textVal.toUpperCase() : textVal;
					const glyphsKey = `otsvg::${fontId}::${displayVal}::${savedLineHeight}`;

					if (el._renderMode === 'otsvg' && el._glyphsKey === glyphsKey && el._svgEl && el._groupEl) {
						if (el._lastVbW !== vbW) {
							const maxDesignW = vbW * 0.95;
							const scaleW = (el._rawW > maxDesignW && maxDesignW > 0) ? (maxDesignW / el._rawW) : 1.0;
							const autoScale = Math.min(1.0, scaleW, el._scaleH || 1.0);
							el._svgEl.setAttribute('viewBox', `0 0 ${vbW} ${vbH}`);
							el._groupEl.setAttribute('transform', `translate(${(vbW / 2).toFixed(2)}, 50) scale(${autoScale.toFixed(5)})`);
							el._lastVbW = vbW;
						}
						return;
					}

					const unitsPerEm = svgData.unitsPerEm || 1000;
					const defaultAdv = svgData.defaultAdv || 500;
					const lines = displayVal.split('\n');
					const numLines = Math.max(1, lines.length);
					const lineHeightRatio = savedLineHeight;
					const letterSpacingUnits = (fontDef.letter_spacing !== undefined ? parseFloat(fontDef.letter_spacing) : 0.05) * unitsPerEm;

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
									const hasSvg = Boolean(entry.svg && entry.svg.trim() !== '');
									const adv = (entry.adv > 0) ? entry.adv : (isSpace ? Math.round(unitsPerEm * 0.32) : defaultAdv);
									const vBounds = hasSvg ? this.getAdminGlyphVisualXBounds(entry, unitsPerEm, adv) : { minX: 0, maxX: adv };
									return {
										svg: entry.svg || '',
										hasSvg,
										isSpace,
										adv,
										minX: vBounds.minX,
										maxX: vBounds.maxX,
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
						const fbAdv = isSpace ? ((firstHit && firstHit.adv > 0) ? firstHit.adv : Math.round(unitsPerEm * 0.32)) : Math.round(unitsPerEm * ratio);
						return {
							svg: '',
							hasSvg: false,
							isSpace,
							adv: fbAdv,
							minX: 0,
							maxX: fbAdv,
						};
					};

					let maxLineDesignWidth = 0;
					const lineMetrics = lines.map((lineStr) => {
						let penX = 0;
						let minVisualX = Infinity;
						let maxVisualX = -Infinity;
						const chars = Array.from(lineStr);

						for (let i = 0; i < chars.length; i++) {
							const g = resolveAdminGlyph(chars[i]);
							if (!g.isSpace) {
								const leftEdge = penX + g.minX;
								const rightEdge = penX + g.maxX;
								if (leftEdge < minVisualX) minVisualX = leftEdge;
								if (rightEdge > maxVisualX) maxVisualX = rightEdge;
							}
							penX += g.adv + (i < chars.length - 1 ? letterSpacingUnits : 0);
						}

						if (minVisualX === Infinity || maxVisualX === -Infinity) {
							minVisualX = 0;
							maxVisualX = penX;
						}
						const visualWidthUnits = Math.max(penX, maxVisualX - minVisualX);
						if (visualWidthUnits > maxLineDesignWidth) maxLineDesignWidth = visualWidthUnits;
						return { chars, minVisualX, maxVisualX };
					});

					const maxDesignW = vbW * 0.95;
					const maxDesignH = vbH * 0.92;
					const baseFontSize = (vbH * 0.85) / (1 + (numLines - 1) * lineHeightRatio * 0.8);
					const rawW = (maxLineDesignWidth / unitsPerEm) * baseFontSize;
					const scaleW = (rawW > maxDesignW && maxDesignW > 0) ? (maxDesignW / rawW) : 1.0;
					const rawTotalH = baseFontSize + ((numLines - 1) * baseFontSize * lineHeightRatio);
					const scaleH = (rawTotalH > maxDesignH && maxDesignH > 0) ? (maxDesignH / rawTotalH) : 1.0;
					const autoScale = Math.min(1.0, scaleW, scaleH);
					const baseScale = baseFontSize / unitsPerEm;
					const baseStepY = baseFontSize * lineHeightRatio;
					const baseStartCenterY = -(((numLines - 1) * baseStepY) / 2);
					const fbFontSize = Math.round(unitsPerEm * 0.78);
					const fbStrokeWidth = Math.round(unitsPerEm * 0.11);
					const baselineOffsetUnits = this.getOpenTypeSvgBaselineOffset(svgData);

					let glyphsMarkup = '';
					for (let li = 0; li < lineMetrics.length; li++) {
						const lm = lineMetrics[li];
						const visualSpanW = (lm.maxVisualX - lm.minVisualX) * baseScale;
						let cursorX = -(visualSpanW / 2) - (lm.minVisualX * baseScale);
						const lineCenterY = baseStartCenterY + (li * baseStepY);
						const baselineY = lineCenterY + (baselineOffsetUnits * baseScale);

						for (let i = 0; i < lm.chars.length; i++) {
							const ch = lm.chars[i];
							const g = resolveAdminGlyph(ch);
							if (g.hasSvg) {
								glyphsMarkup += `<g class="mrtee-ot-glyph" transform="translate(${cursorX.toFixed(3)}, ${baselineY.toFixed(3)}) scale(${baseScale.toFixed(5)})">${g.svg}</g>\n`;
							} else if (!g.isSpace) {
								const escapedCh = this.escapeXml(ch);
								const fbCenterX = (g.adv / 2).toFixed(1);
								glyphsMarkup += `<g class="mrtee-ot-glyph mrtee-ot-glyph-fallback" transform="translate(${cursorX.toFixed(3)}, ${baselineY.toFixed(3)}) scale(${baseScale.toFixed(5)})"><text x="${fbCenterX}" y="0" text-anchor="middle" text-rendering="geometricPrecision" font-family="'Bungee', 'Luckiest Guy', 'Impact', sans-serif" font-weight="900" font-size="${fbFontSize}" fill="#FFFFFF" stroke="#1E293B" stroke-width="${fbStrokeWidth}" stroke-linejoin="round" stroke-linecap="round" style="paint-order: stroke fill; text-rendering: geometricPrecision; filter: drop-shadow(0 2px 3px rgba(0,0,0,0.45));">${escapedCh}</text></g>\n`;
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
					el._scaleH = scaleH;
					el._lastVbW = vbW;
					el._svgEl = el.querySelector('svg');
					el._groupEl = el.querySelector('.mrtee-ot-admin-glyph-group');
					el._textEl = null;
					el._imgEl = null;
					return;
				}

				this.fetchSvgFontData(fontId, () => {
					const total = $('.mr-tee-field-box').length;
					for (let bi = 0; bi < total; bi++) {
						const activeVal = $(`select[name="mr_tee_config[text_lines][${bi}][font]"]`).val() || fallbackKey;
						if (activeVal === fontId) {
							this.renderBoxPreview(bi);
						}
					}
				});
			}

			// Lazy-inject @font-face for any unselected TTF font picked dynamically
			this.ensureTtfFontLoaded(fontId);

			const rawFamily = (fontDef && fontDef.family) ? fontDef.family : 'sans-serif';
			const fontFamily = this.buildAdminFontStack(rawFamily);
			const rawStrokeW = (fontDef && fontDef.stroke_width !== undefined) ? parseFloat(fontDef.stroke_width) : 0;
			const strokeColor = (fontDef && fontDef.stroke && rawStrokeW > 0) ? fontDef.stroke : 'transparent';
			const textTransform = (resolvedCasing === 'UPPER') ? 'uppercase' : 'none';
			const displayVal = (resolvedCasing === 'UPPER') ? textVal.toUpperCase() : textVal;

			// True WYSIWYG color resolution (fixes white-on-white bug for stroke-less TTF fonts!)
			const colorMode = $(`select[name="mr_tee_config[text_lines][${idx}][color_mode]"]`).val() || 'multi';
			const rawColorHex = ($(`input[name="mr_tee_config[text_lines][${idx}][color_hex]"]`).val() || '').trim();
			const fontPalette = (fontDef && Array.isArray(fontDef.palette) && fontDef.palette.length) ? fontDef.palette : [];
			const isMultiMode = (colorMode === 'multi');

			let resolvedPalette;
			let solidFill = '#111827';
			if (isMultiMode) {
				if (fontPalette.length > 1) {
					resolvedPalette = fontPalette;
				} else if (fontDef && fontDef.multi_color) {
					resolvedPalette = DEFAULT_MULTI_PALETTE;
				} else if (fontPalette.length === 1) {
					const single = String(fontPalette[0] || '#111827');
					resolvedPalette = (single.toLowerCase() === '#ffffff' && rawStrokeW <= 0) ? ['#111827'] : [single];
				} else {
					resolvedPalette = DEFAULT_MULTI_PALETTE;
				}
				solidFill = resolvedPalette[0] || '#111827';
			} else {
				const fallbackSolid = (fontPalette[0] && (rawStrokeW > 0 || String(fontPalette[0]).toLowerCase() !== '#ffffff'))
					? fontPalette[0]
					: '#111827';
				solidFill = rawColorHex || fallbackSolid;
				if (!rawColorHex && solidFill.toLowerCase() === '#ffffff' && rawStrokeW <= 0) {
					solidFill = '#111827';
				}
				resolvedPalette = [solidFill];
			}

			const colorSig = `${colorMode}:${solidFill}:${resolvedPalette.join(',')}`;
			const glyphsKey = `ttf::${fontId}::${displayVal}::${savedLineHeight}::${strokeColor}::${rawStrokeW}::${colorSig}`;
			const scaledStrokeW = rawStrokeW > 0 ? (rawStrokeW * unitScaleK).toFixed(3) : '0';

			if (el._renderMode === 'ttf' && el._glyphsKey === glyphsKey && el._svgEl && el._groupEl) {
				if (el._lastVbW !== vbW || el._lastUnitScaleK !== unitScaleK) {
					const maxDesignW = vbW * 0.95;
					const scaleW = (el._rawW > maxDesignW && maxDesignW > 0) ? (maxDesignW / el._rawW) : 1.0;
					const autoScale = Math.min(1.0, scaleW, el._scaleH || 1.0);
					el._svgEl.setAttribute('viewBox', `0 0 ${vbW} ${vbH}`);
					el._groupEl.setAttribute('transform', `translate(${(vbW / 2).toFixed(2)}, 50) scale(${autoScale.toFixed(5)})`);
					if (el._textEl && rawStrokeW > 0) {
						el._textEl.setAttribute('stroke-width', scaledStrokeW);
					}
					el._lastVbW = vbW;
					el._lastUnitScaleK = unitScaleK;
				}
				return;
			}

			const lines = displayVal.split('\n');
			const numLines = Math.max(1, lines.length);
			const lineHeightRatio = savedLineHeight;
			const metrics = this.getBoxMetrics(fontDef);
			const letterSpacing = `${metrics.letterSpacingEm}em`;

			const maxDesignW = vbW * 0.95;
			const maxDesignH = vbH * 0.92;
			const baseFontSize = (vbH * 0.85) / (1 + (numLines - 1) * lineHeightRatio * 0.8);
			let maxLineWidthUnits = 0;
			for (const l of lines) {
				const units = this.measureAdminTextWidthUnits(l, fontFamily, metrics.letterSpacingEm, fontDef);
				if (units > maxLineWidthUnits) maxLineWidthUnits = units;
			}
			const maxEstW = baseFontSize * maxLineWidthUnits;
			const scaleW = (maxEstW > maxDesignW && maxDesignW > 0) ? (maxDesignW / maxEstW) : 1.0;
			const rawTotalH = baseFontSize + ((numLines - 1) * baseFontSize * lineHeightRatio);
			const scaleH = (rawTotalH > maxDesignH && maxDesignH > 0) ? (maxDesignH / rawTotalH) : 1.0;
			const autoScale = Math.min(1.0, scaleW, scaleH);

			const useMultiSpans = isMultiMode && resolvedPalette.length > 1;
			let charIdx = 0;
			const buildLineMarkup = (str) => {
				if (str === '') {
					return '&#160;';
				}
				if (!useMultiSpans) {
					return this.escapeXml(str);
				}
				let out = '';
				const chars = Array.from(str);
				for (let ci = 0; ci < chars.length; ci++) {
					const ch = chars[ci];
					if (ch === ' ') {
						out += '<tspan xml:space="preserve"> </tspan>';
					} else {
						const fill = resolvedPalette[charIdx % resolvedPalette.length];
						out += `<tspan fill="${this.escapeXml(fill)}">${this.escapeXml(ch)}</tspan>`;
						charIdx++;
					}
				}
				return out || '&#160;';
			};

			let textBody = '';
			if (numLines > 1) {
				const stepY = baseFontSize * lineHeightRatio;
				const startDy = -(((numLines - 1) * stepY) / 2);
				lines.forEach((lineStr, li) => {
					const dy = (li === 0) ? startDy : stepY;
					const xmlSpace = (!useMultiSpans && lineStr !== '') ? ' xml:space="preserve"' : '';
					textBody += `<tspan x="0" dy="${dy.toFixed(2)}"${xmlSpace}>${buildLineMarkup(lineStr)}</tspan>`;
				});
			} else {
				textBody = !useMultiSpans
					? `<tspan xml:space="preserve">${buildLineMarkup(displayVal)}</tspan>`
					: buildLineMarkup(displayVal);
			}

			const shadowStyle = (rawStrokeW > 0 || solidFill.toLowerCase() === '#ffffff')
				? 'filter: drop-shadow(0 1px 2px rgba(0,0,0,0.45));'
				: 'filter: drop-shadow(0 1px 1px rgba(0,0,0,0.18));';
			const safeFontAttr = fontFamily.replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');

			el.innerHTML = `
				<svg viewBox="0 0 ${vbW} ${vbH}" width="100%" height="100%" preserveAspectRatio="xMidYMid meet" style="overflow:visible;">
					<g class="mrtee-ttf-admin-glyph-group" transform="translate(${(vbW / 2).toFixed(2)}, 50) scale(${autoScale.toFixed(5)})">
						<text
							x="0"
							y="0"
							font-family="${safeFontAttr}"
							font-size="${baseFontSize.toFixed(2)}"
							letter-spacing="${letterSpacing}"
							text-anchor="middle"
							dominant-baseline="central"
							text-rendering="geometricPrecision"
							fill="${this.escapeXml(solidFill)}"
							stroke="${strokeColor}"
							stroke-width="${scaledStrokeW}"
							style="paint-order: stroke fill; stroke-linejoin: round; stroke-linecap: round; text-rendering: geometricPrecision; -webkit-text-size-adjust: none; text-size-adjust: none; text-transform: ${textTransform}; ${shadowStyle}"
						>${textBody}</text>
					</g>
				</svg>
			`;
			el._renderMode = 'ttf';
			el._glyphsKey = glyphsKey;
			el._rawW = maxEstW;
			el._scaleH = scaleH;
			el._lastVbW = vbW;
			el._lastUnitScaleK = unitScaleK;
			el._svgEl = el.querySelector('svg');
			el._groupEl = el.querySelector('.mrtee-ttf-admin-glyph-group');
			el._textEl = el.querySelector('text');
			el._imgEl = null;
		}

		/**
		 * Extract the transformed [minX, maxX, minY, maxY] bounds (in font design units)
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
					const wm = attrs.match(/\bwidth\s*=\s*(?:"([+-]?[\d.]+)"|'([+-]?[\d.]+)')/i);
					const h = hm ? parseFloat(hm[1] || hm[2]) : NaN;
					const w = wm ? parseFloat(wm[1] || wm[2]) : 0;
					if (!Number.isFinite(h) || h <= 0) continue;

					const xm = attrs.match(/\bx\s*=\s*(?:"([+-]?[\d.]+)"|'([+-]?[\d.]+)')/i);
					const ym = attrs.match(/\by\s*=\s*(?:"([+-]?[\d.]+)"|'([+-]?[\d.]+)')/i);
					let x0 = xm ? parseFloat(xm[1] || xm[2]) : 0;
					let y0 = ym ? parseFloat(ym[1] || ym[2]) : 0;
					if (!Number.isFinite(x0)) x0 = 0;
					if (!Number.isFinite(y0)) y0 = 0;
					let x1 = x0 + (Number.isFinite(w) ? w : 0);
					let y1 = y0 + h;

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
							if (fn === 'translate' && nums.length >= 1) {
								const dx = nums[0];
								const dy = nums.length >= 2 ? nums[1] : 0;
								x0 += dx;
								x1 += dx;
								y0 += dy;
								y1 += dy;
							} else if (fn === 'scale' && nums.length >= 1) {
								const sx = nums[0];
								const sy = nums.length >= 2 ? nums[1] : nums[0];
								x0 *= sx;
								x1 *= sx;
								y0 *= sy;
								y1 *= sy;
							} else if (fn === 'matrix' && nums.length >= 6) {
								x0 = nums[0] * x0 + nums[4];
								x1 = nums[0] * x1 + nums[4];
								y0 = nums[3] * y0 + nums[5];
								y1 = nums[3] * y1 + nums[5];
							}
						}
					}

					const minX = Math.min(x0, x1);
					const maxX = Math.max(x0, x1);
					const minY = Math.min(y0, y1);
					const maxY = Math.max(y0, y1);
					if (!best || (maxY - minY) > (best.maxY - best.minY)) {
						best = { minX, maxX, minY, maxY };
					}
				}
			}

			return best;
		}

		parseAdminVectorPathBoundsX(svgStr) {
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

		getAdminGlyphVisualXBounds(entry, unitsPerEm, adv) {
			if (!entry) return { minX: 0, maxX: adv };
			if (typeof entry._visualMinX === 'number' && typeof entry._visualMaxX === 'number') {
				return { minX: entry._visualMinX, maxX: entry._visualMaxX };
			}
			let visualMinX = 0;
			let visualMaxX = adv;
			const svgStr = entry.svg || '';
			if (svgStr.includes('<image')) {
				const geom = this.extractGlyphImageYBounds(svgStr);
				if (geom && Number.isFinite(geom.minX) && Number.isFinite(geom.maxX) && geom.maxX > geom.minX) {
					visualMinX = Math.max(-unitsPerEm * 0.25, Math.min(0, geom.minX));
					visualMaxX = Math.max(adv, Math.min(adv + unitsPerEm * 0.6, geom.maxX));
				}
			} else if (svgStr.includes('<path')) {
				const bounds = this.parseAdminVectorPathBoundsX(svgStr);
				if (bounds) {
					visualMinX = Math.max(-unitsPerEm * 0.25, Math.min(adv * 0.4, bounds.minX));
					visualMaxX = Math.max(adv, Math.min(adv + unitsPerEm * 0.65, bounds.maxX));
				}
			}
			entry._visualMinX = visualMinX;
			entry._visualMaxX = visualMaxX;
			return { minX: visualMinX, maxX: visualMaxX };
		}

		getOpenTypeSvgBaselineOffset(svgData) {
			if (!svgData) return 800 * 0.42;
			if (typeof svgData._opticalBaselineOffset === 'number' && Number.isFinite(svgData._opticalBaselineOffset)) {
				return svgData._opticalBaselineOffset;
			}

			const ascent = svgData.ascent || 800;
			const glyphs = svgData.glyphs || {};
			const sampleChars = ['A', 'B', 'E', 'H', 'M', 'O', 'S', 'T', 'N', 'R'];
			const factors = [];

			for (let i = 0; i < sampleChars.length; i++) {
				const ch = sampleChars[i];
				const g = glyphs[ch] || glyphs[ch.toLowerCase()];
				if (g && g.svg) {
					const bounds = this.extractGlyphImageYBounds(g.svg);
					if (bounds && ascent > 0) {
						const midY = (bounds.minY + bounds.maxY) / 2;
						const factor = (-midY) / ascent;
						if (Number.isFinite(factor) && factor >= 0.15 && factor <= 0.85) {
							factors.push(factor);
						}
					}
				}
			}

			let visualCenterFactor = 0.42;
			if (factors.length > 0) {
				const sum = factors.reduce((acc, v) => acc + v, 0);
				visualCenterFactor = Math.max(0.2, Math.min(0.75, sum / factors.length));
			}

			const computed = ascent * visualCenterFactor;
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

			if (this._failedSvgFetches && this._failedSvgFetches.has(fontId)) {
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
					} else if (this._failedSvgFetches) {
						this._failedSvgFetches.add(fontId);
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

		/**
		 * Tab 2 Font Uploader (auto-adds uploaded fonts to allowed_fonts + full library grid).
		 */
		bindFontUploader() {
			const self = this;

			$('#mr_tee_tab2_btn_select').on('click', function(e) {
				e.preventDefault();
				$('#mr_tee_tab2_font_input').trigger('click');
			});

			$('#mr_tee_tab2_font_input').on('change', function() {
				const file = this.files && this.files[0];
				if (file) {
					$('#mr_tee_tab2_file_name').text(file.name);
					$('#mr_tee_tab2_btn_upload').show();
					$('#mr_tee_tab2_upload_status').text('').removeClass('is-info is-success is-error is-muted');
				}
			});

			const handleUploadDone = () => {
				$('#mr_tee_tab2_file_name').text('');
				$('#mr_tee_tab2_btn_upload').hide();
				$('#mr_tee_tab2_font_input').val('');
			};

			$('#mr_tee_tab2_btn_upload').on('click', function(e) {
				e.preventDefault();
				const file = $('#mr_tee_tab2_font_input')[0].files[0];
				if (!file) return;

				const $status = $('#mr_tee_tab2_upload_status');
				$status.text('⏳ Analyse et enregistrement de la police...').removeClass('is-success is-error is-muted').addClass('is-info');
				self.uploadFontFile(file, $status, handleUploadDone);
			});

			// Drag & Drop with dragDepth counter so child elements don't cause flicker
			const $dropZone = $('.mr-tee-font-upload-card');
			if ($dropZone.length) {
				let dragDepth = 0;
				$dropZone.on('dragenter', function(e) {
					e.preventDefault();
					e.stopPropagation();
					dragDepth++;
					$(this).addClass('is-dragover');
				});
				$dropZone.on('dragover', function(e) {
					e.preventDefault();
					e.stopPropagation();
				});
				$dropZone.on('dragleave', function(e) {
					e.preventDefault();
					e.stopPropagation();
					dragDepth = Math.max(0, dragDepth - 1);
					if (dragDepth === 0) {
						$(this).removeClass('is-dragover');
					}
				});
				$dropZone.on('drop', function(e) {
					e.preventDefault();
					e.stopPropagation();
					dragDepth = 0;
					$(this).removeClass('is-dragover');
					const dt = e.originalEvent.dataTransfer;
					if (dt && dt.files && dt.files.length) {
						const file = dt.files[0];
						$('#mr_tee_tab2_file_name').text(file.name);
						$('#mr_tee_tab2_btn_upload').show();
						const $status = $('#mr_tee_tab2_upload_status');
						$status.text('⏳ Analyse et enregistrement de la police...').removeClass('is-success is-error is-muted').addClass('is-info');
						self.uploadFontFile(file, $status, handleUploadDone);
					}
				});
			}
		}

		updateFontCountersUi() {
			const fonts = (window.mrTeeAdminData && window.mrTeeAdminData.fonts) || {};
			const allKeys = Object.keys(fonts);
			let svgTotal = 0;
			allKeys.forEach(k => {
				if (this.isSvgFont(fonts[k])) svgTotal++;
			});
			$('#mr-tee-allowed-fonts-count').text(this.allowedFonts.length);
			$('#mr-tee-tab-fonts-badge').text(this.allowedFonts.length);
			$('#mr-tee-summary-fonts-count').text(this.allowedFonts.length);
			$('#mr-tee-svg-fonts-total-count').text(svgTotal);
			$('#mr-tee-library-total-count').text(allKeys.length);
		}

		/**
		 * Product Allowed Fonts & Ordering Selector Management (Tab 2).
		 */
		bindProductAllowedFonts() {
			const self = this;
			const $input = $('#mr_tee_allowed_fonts_json');
			if (!$input.length) return;

			self.allowedFonts = self.readInitialAllowedFonts();

			const updateHiddenAndSync = (opts = {}) => {
				$input.val(JSON.stringify(self.allowedFonts));
				self.updateFontCountersUi();
				self.syncFontLibraryCards();
				if (!opts.isInitial) {
					self.markDirty();
				}

				if (!opts.skipBoxSelectSync && !opts.isInitial) {
					self.refreshAllFontSelects();
				}

				// Update add picker dropdown options (properly trimmed!)
				$('#mr_tee_add_font_picker option').each(function() {
					const val = $(this).val();
					if (!val) return;
					const inList = self.allowedFonts.includes(val);
					$(this).prop('disabled', inList);
					const baseText = $(this).text().trim().replace(/\s*\(déjà active\)$/, '');
					$(this).text(inList ? `${baseText} (déjà active)` : baseText);
				});

				if (!opts.isInitial) {
					self.renderAllBoxesPreviews();
				}
			};

			const renderAllowedList = (opts = {}) => {
				const fonts = (window.mrTeeAdminData && window.mrTeeAdminData.fonts) || {};
				const $list = $('#mr-tee-allowed-fonts-list');

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
						<div class="mr-tee-allowed-font-item ${isDefault ? 'is-default-font' : ''}" data-font-id="${self.escapeXml(fId)}">
							<div class="mr-tee-allowed-font-info">
								<span class="mr-tee-font-order-badge ${isDefault ? 'is-default' : 'is-option'}">
									${isDefault ? '#1 DÉFAUT' : '#' + (idx + 1) + ' OPTION'}
								</span>
								<span class="mr-tee-font-type-pill ${isSvg ? 'is-svg' : 'is-ttf'}">
									${isSvg ? 'SVG 3D' : 'TTF'}
								</span>
								${thumbUrl ? `<img src="${self.escapeXml(thumbUrl)}" alt="" loading="lazy" decoding="async" class="mr-tee-font-mini-thumb" />` : ''}
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

			$(document).on('click', '.mr-tee-btn-font-up', function() {
				const fId = $(this).closest('.mr-tee-allowed-font-item').attr('data-font-id');
				const idx = self.allowedFonts.indexOf(fId);
				if (idx > 0) {
					const tmp = self.allowedFonts[idx - 1];
					self.allowedFonts[idx - 1] = self.allowedFonts[idx];
					self.allowedFonts[idx] = tmp;
					renderAllowedList();
				}
			});

			$(document).on('click', '.mr-tee-btn-font-down', function() {
				const fId = $(this).closest('.mr-tee-allowed-font-item').attr('data-font-id');
				const idx = self.allowedFonts.indexOf(fId);
				if (idx !== -1 && idx < self.allowedFonts.length - 1) {
					const tmp = self.allowedFonts[idx + 1];
					self.allowedFonts[idx + 1] = self.allowedFonts[idx];
					self.allowedFonts[idx] = tmp;
					renderAllowedList();
				}
			});

			$(document).on('click', '.mr-tee-btn-font-delete', function() {
				if (self.allowedFonts.length <= 1) return;
				const fId = $(this).closest('.mr-tee-allowed-font-item').attr('data-font-id');
				self.allowedFonts = self.allowedFonts.filter(id => id !== fId);
				renderAllowedList();
			});

			$('#mr_tee_btn_add_allowed_font').on('click', function() {
				const fId = $('#mr_tee_add_font_picker').val();
				if (!fId) return;
				if (!self.allowedFonts.includes(fId)) {
					self.allowedFonts.push(fId);
					renderAllowedList();
					$('#mr_tee_add_font_picker').val('');
				}
			});

			$('#mr_tee_preset_only_first').on('click', function() {
				if (self.allowedFonts.length > 0) {
					self.allowedFonts = [self.allowedFonts[0]];
					renderAllowedList();
				}
			});

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

			$('#mr_tee_preset_all_fonts').on('click', function() {
				const fonts = (window.mrTeeAdminData && window.mrTeeAdminData.fonts) || {};
				self.allowedFonts = Object.keys(fonts);
				renderAllowedList();
			});

			renderAllowedList({ isInitial: true });
		}

		/**
		 * Delete Custom Uploaded Fonts via AJAX (`action=mr_tee_delete_custom_font`).
		 */
		bindCustomFontDelete() {
			const self = this;
			$(document).on('click', '.mr-tee-btn-delete-custom-font', function(e) {
				e.preventDefault();
				const $btn = $(this);
				const fontId = $btn.attr('data-font-id');
				const fontName = $btn.attr('data-font-name') || fontId;
				if (!fontId) return;

				if (!confirm(`Supprimer définitivement la police personnalisée « ${fontName} » ?`)) {
					return;
				}

				$btn.prop('disabled', true);
				const ajaxUrl = self.toSameOriginPath((window.mrTeeAdminData && window.mrTeeAdminData.ajaxUrl) || '/wp-admin/admin-ajax.php');
				$.ajax({
					url: ajaxUrl,
					type: 'POST',
					data: {
						action: 'mr_tee_delete_custom_font',
						nonce: window.mrTeeAdminData ? window.mrTeeAdminData.nonce : '',
						font_id: fontId,
					},
					success: function(resp) {
						if (resp && resp.success) {
							if (window.mrTeeAdminData && window.mrTeeAdminData.fonts) {
								delete window.mrTeeAdminData.fonts[fontId];
							}
							if (window.mrTeeAdminData && window.mrTeeAdminData.svgFonts) {
								delete window.mrTeeAdminData.svgFonts[fontId];
							}

							$(`#mr-tee-fonts-library-grid .mr-tee-font-lib-card[data-font-id="${fontId}"]`).remove();
							$(`#mr_tee_add_font_picker option[value="${fontId}"]`).remove();

							self.allowedFonts = self.allowedFonts.filter(id => id !== fontId);
							if (!self.allowedFonts.length) {
								const remainingKeys = Object.keys((window.mrTeeAdminData && window.mrTeeAdminData.fonts) || {});
								if (remainingKeys.length) {
									self.allowedFonts = [remainingKeys[0]];
								}
							}
							if (typeof self.renderAllowedFontsList === 'function') {
								self.renderAllowedFontsList();
							} else {
								self.refreshAllFontSelects();
								self.renderAllBoxesPreviews();
							}
						} else {
							$btn.prop('disabled', false);
							const msg = (resp && resp.data && resp.data.message) ? resp.data.message : 'Impossible de supprimer cette police.';
							alert(msg);
						}
					},
					error: function() {
						$btn.prop('disabled', false);
						alert('Erreur réseau lors de la suppression de la police.');
					}
				});
			});
		}

		registerUploadedFontsInUi(uploadedFonts) {
			if (!Array.isArray(uploadedFonts) || !uploadedFonts.length) return;
			const $picker = $('#mr_tee_add_font_picker');
			const $svgGroup = $picker.find('optgroup').eq(0);
			const $stdGroup = $picker.find('optgroup').eq(1);
			const $grid = $('#mr-tee-fonts-library-grid');

			uploadedFonts.forEach(f => {
				if (!f || !f.id) return;
				const isSvg = this.isSvgFont(f);
				const isCustom = f.is_custom !== false;
				const safeId = this.escapeXml(f.id);
				const safeName = this.escapeXml(f.name || f.id);
				const safeFile = this.escapeXml(f.file || f.id);
				const searchHay = this.escapeXml(`${f.name || ''} ${f.file || ''} ${f.id}`.toLowerCase());

				if ($picker.length && !$picker.find(`option[value="${f.id}"]`).length) {
					const optHtml = `<option value="${safeId}">${safeName}</option>`;
					if (isSvg && $svgGroup.length) {
						$svgGroup.append(optHtml);
					} else if ($stdGroup.length) {
						$stdGroup.append(optHtml);
					} else {
						$picker.append(optHtml);
					}
				}

				// Auto-add newly uploaded font to product allowed_fonts
				if (!this.allowedFonts.includes(f.id)) {
					this.allowedFonts.push(f.id);
				}

				// Display BOTH TTF and OpenType-SVG fonts in the interactive library grid with delete button for custom fonts
				if ($grid.length && !$grid.find(`[data-font-id="${f.id}"]`).length) {
					const thumbUrl = f.thumb_url ? this.toSameOriginPath(f.thumb_url) : '';
					const family = this.escapeXml(String(f.family || 'sans-serif').replace(/['"]/g, ''));
					const previewHtml = thumbUrl
						? `<img src="${this.escapeXml(thumbUrl)}" alt="" loading="lazy" decoding="async" />`
						: `<span class="mr-tee-font-lib-sample" style="font-family: '${family}', sans-serif;">Aa</span>`;
					const deleteBtnHtml = isCustom
						? `<button type="button" class="mr-tee-btn-delete-custom-font" data-font-id="${safeId}" data-font-name="${safeName}" title="Supprimer définitivement cette police personnalisée" aria-label="Supprimer définitivement ${safeName}">✕</button>`
						: '';
					const cardHtml = `
						<div class="mr-tee-font-lib-card ${isSvg ? 'is-svg' : 'is-ttf'} is-allowed" data-font-id="${safeId}" data-font-type="${isSvg ? 'svg' : 'ttf'}" data-is-custom="${isCustom ? '1' : '0'}" data-is-allowed="1" data-search="${searchHay}">
							<button type="button" class="mr-tee-font-lib-toggle" data-font-id="${safeId}" aria-pressed="true">
								<span class="mr-tee-font-lib-preview">${previewHtml}</span>
								<span class="mr-tee-font-lib-meta">
									<strong>${safeName}</strong>
									<span class="mr-tee-font-lib-file">${safeFile}</span>
								</span>
								<span class="mr-tee-font-lib-badges">
									<span class="mr-tee-font-type-pill ${isSvg ? 'is-svg' : 'is-ttf'}">${isSvg ? 'SVG' : 'TTF'}</span>
									<span class="mr-tee-font-lib-state">✓ Active</span>
								</span>
							</button>
							${deleteBtnHtml}
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
					if (resp && resp.success && resp.data && resp.data.fonts) {
						const fonts = resp.data.fonts;
						$statusElem.text(`✅ ${resp.data.message || 'Police importée et ajoutée au produit !'}`).removeClass('is-info is-error is-muted').addClass('is-success');

						window.mrTeeAdminData = window.mrTeeAdminData || {};
						window.mrTeeAdminData.fonts = window.mrTeeAdminData.fonts || {};
						fonts.forEach(f => {
							f.is_custom = true;
							window.mrTeeAdminData.fonts[f.id] = f;
							if (!self.isSvgFont(f)) {
								self.ensureTtfFontLoaded(f.id);
							}
						});

						self.registerUploadedFontsInUi(fonts);
						if (typeof self.renderAllowedFontsList === 'function') {
							self.renderAllowedFontsList();
						} else {
							self.refreshAllFontSelects();
							self.renderAllBoxesPreviews();
						}

						if (onSuccess) onSuccess(fonts);
					} else {
						const errMsg = (resp && resp.data && resp.data.message) ? resp.data.message : 'Erreur lors de l\'importation de la police.';
						$statusElem.text(`❌ ${errMsg}`).removeClass('is-info is-success is-muted').addClass('is-error');
					}
				},
				error: function(xhr, status, error) {
					$statusElem.text(`❌ Erreur réseau / serveur : ${error}`).removeClass('is-info is-success is-muted').addClass('is-error');
				}
			});
		}

		refreshAllFontSelects() {
			const self = this;
			const allowed = self.getAllowedFonts();
			const defaultFontId = allowed[0] || '';

			$('.mr-tee-field-font-select').each(function() {
				const prevVal = $(this).val();
				const targetVal = (!prevVal || !allowed.includes(prevVal)) ? defaultFontId : prevVal;

				const html = self.generateFontSelectOptions(targetVal, allowed);
				$(this).html(html);
				if (targetVal) {
					$(this).val(targetVal);
				}
				const idx = parseInt($(this).attr('data-field-index'), 10);
				if (!Number.isNaN(idx)) {
					self.syncFieldColorUi(idx);
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
					svgOptions += `<option value="${this.escapeXml(id)}" ${isSel}>${name}</option>`;
				} else {
					stdOptions += `<option value="${this.escapeXml(id)}" ${isSel}>${name}</option>`;
				}
			});

			let html = '';
			if (svgOptions) {
				html += `<optgroup label="✨ Polices OpenType-SVG">${svgOptions}</optgroup>`;
			}
			if (stdOptions) {
				html += `<optgroup label="🔤 Polices TTF">${stdOptions}</optgroup>`;
			}
			return html;
		}

		/**
		 * Explicit Preset Apply Button (sets mr_tee_apply_preset=1 and saves without publishing drafts).
		 */
		bindPresetApply() {
			$(document).on('click', '#mr_tee_btn_apply_preset', function(e) {
				e.preventDefault();
				const presetLabel = $('#mr_tee_template_preset option:selected').text().trim();
				if (!confirm(`Appliquer le modèle « ${presetLabel} » ?\n\nCela remplacera les zones et paramètres actuels de ce produit et enregistrera les modifications.`)) {
					return;
				}
				$('#mr_tee_apply_preset').val('1');
				setTimeout(() => {
					$('#mr_tee_apply_preset').val('0');
				}, 2500);

				const origStatus = String($('#original_post_status').val() || '');
				const $saveDraftBtn = $('#save-post');
				const $publishBtn = $('#publish');
				if (origStatus && origStatus !== 'publish' && $saveDraftBtn.length && $saveDraftBtn.is(':visible')) {
					$saveDraftBtn.trigger('click');
				} else if ($publishBtn.length) {
					$publishBtn.trigger('click');
				} else {
					$('form#post').trigger('submit');
				}
			});
		}

		/**
		 * Physical Dimensions & Automatic Aspect Ratio Controls (Single Source of Truth in Tab 1 Drawer).
		 */
		bindDimensionControls() {
			const self = this;

			$(document).on('input change', '#mr_tee_real_width, #mr_tee_real_height, #mr_tee_print_unit, #mr_tee_dpi, #mr_tee_product_type, #mr_tee_lock_aspect_ratio', function(e) {
				if (self._syncingSideDims) return;
				self._cachedStageRatio = 0;

				// Visually check lock_aspect_ratio when flat_object is chosen
				if (this.id === 'mr_tee_product_type' && $(this).val() === 'flat_object') {
					$('#mr_tee_lock_aspect_ratio').prop('checked', true);
				}

				// Clamp DPI cleanly on change so 72..1200 matches server limits
				if (this.id === 'mr_tee_dpi' && e.type === 'change') {
					const rawDpi = parseInt($(this).val(), 10);
					const clampedDpi = Number.isFinite(rawDpi) ? Math.max(72, Math.min(1200, rawDpi)) : 300;
					$(this).val(clampedDpi);
				}

				self.updateActiveSideDimensionsFromToolbar();
				self.recalculateDimensions();
				self.renderAllBoxesPreviews();
			});

			// Quick POD Dimension Presets
			$(document).on('click', '.mr-tee-btn-preset-dim', function(e) {
				e.preventDefault();
				self._cachedStageRatio = 0;
				const pType = $(this).data('type') || 'garment';
				const pUnit = $(this).data('unit') || 'cm';
				const pW = parseFloat($(this).data('w')) || 30;
				const pH = parseFloat($(this).data('h')) || 40;
				const pLock = parseInt($(this).data('lock'), 10) === 1 || pType === 'flat_object';

				$('#mr_tee_product_type').val(pType);
				$('#mr_tee_print_unit').val(pUnit);
				$('#mr_tee_real_width').val(pW);
				$('#mr_tee_real_height').val(pH);
				$('#mr_tee_lock_aspect_ratio').prop('checked', pLock);

				self.updateActiveSideDimensionsFromToolbar();
				self.recalculateDimensions();
				self.renderAllBoxesPreviews();
				self.markDirty();
			});
		}

		computeSideExportData(side) {
			const w = Math.max(0.1, parseFloat(side.print_real_width) || 30.0);
			const h = Math.max(0.1, parseFloat(side.print_real_height) || 40.0);
			const unit = side.print_unit || 'cm';
			const dpi = Math.max(72, parseInt(side.dpi, 10) || 300);
			const pType = side.product_type === 'flat_object' ? 'flat_object' : 'garment';
			const refRatio = (h > 0) ? (w / h) : 1.0;

			let xMin = 100, yMin = 100, xMax = 0, yMax = 0;
			let boxCount = 0;
			const activeSideId = this.getActiveSideId();
			const refView = this.getPrimaryViewForSide(side.id, true);
			const refViewId = refView ? refView.id : null;

			const boxEls = document.querySelectorAll('.mr-tee-field-box');
			for (let bi = 0; bi < boxEls.length; bi++) {
				const idx = parseInt(boxEls[bi].getAttribute('data-field-index'), 10);
				if (Number.isNaN(idx)) continue;
				if (this.getFieldSideId(idx) !== side.id) continue;

				let coords;
				const isLiveOnRefView = (side.id === activeSideId) && (!refViewId || this.activeViewId === refViewId);
				if (isLiveOnRefView) {
					coords = this.getFieldCurrentCoords(idx);
				} else {
					const placements = this.getFieldPlacements(idx);
					coords = (refViewId && placements[refViewId]) ? placements[refViewId] : this.getFieldCurrentCoords(idx);
				}

				const l = parseFloat(coords.box_left) || 0;
				const t = parseFloat(coords.box_top) || 0;
				const bw = parseFloat(coords.box_width) || 10;
				const bh = parseFloat(coords.box_height) || 10;
				const r = parseFloat(coords.box_rotate) || 0;

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

			const sideIdx = this.printSides.findIndex(s => s.id === side.id);
			const hasClipart = Boolean(this.config.vector_clipart && this.config.vector_clipart.path);
			const repeaterOn = $('#mr_tee_repeater_enabled').is(':checked');
			const isFullPlaten = (sideIdx <= 0) && (repeaterOn || hasClipart);

			if (boxCount === 0 || pType === 'flat_object' || isFullPlaten) {
				xMin = 0; yMin = 0; xMax = 100; yMax = 100;
			}

			xMin = Math.max(0, Math.min(100, xMin));
			yMin = Math.max(0, Math.min(100, yMin));
			xMax = Math.max(0, Math.min(100, xMax));
			yMax = Math.max(0, Math.min(100, yMax));

			const spanW = Math.max(1, xMax - xMin);
			const spanH = Math.max(1, yMax - yMin);
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

			return {
				w, h, unit, dpi, pType, refRatio,
				cumRealW, cumRealH, pxW, pxH, cumRatio,
				simplified: this.getSimplifiedRatio(cumRealW, cumRealH),
			};
		}

		renderExportSummary() {
			const $container = $('#mr-tee-export-sides-summary');
			if (!$container.length || !Array.isArray(this.printSides)) return;

			const html = this.printSides.map(side => {
				const m = this.computeSideExportData(side);
				const typeLabel = m.pType === 'flat_object' ? 'Objet plat' : 'Vêtement';
				return `
					<div class="mr-tee-export-side-row">
						<div class="mr-tee-export-side-name">
							<strong>${this.escapeXml(side.label || side.id)}</strong>
							<span class="mr-tee-export-type-tag">${typeLabel}</span>
						</div>
						<div class="mr-tee-export-side-specs">
							<span>Surface : ${m.w} × ${m.h} ${this.escapeXml(m.unit)}</span>
							<span aria-hidden="true">·</span>
							<span>${m.dpi} DPI</span>
							<span aria-hidden="true">·</span>
							<strong class="mr-tee-export-px">Export : ${m.cumRealW} × ${m.cumRealH} ${this.escapeXml(m.unit)} (${m.pxW} × ${m.pxH} px)</strong>
							<button type="button" class="button button-small mr-tee-btn-edit-side-dims" data-side-id="${this.escapeXml(side.id)}">Modifier →</button>
						</div>
					</div>
				`;
			}).join('');

			$container.html(html);
		}

		recalculateDimensions() {
			const rwEl = document.getElementById('mr_tee_real_width');
			const rhEl = document.getElementById('mr_tee_real_height');
			const unitEl = document.getElementById('mr_tee_print_unit');
			const dpiEl = document.getElementById('mr_tee_dpi');
			const pTypeEl = document.getElementById('mr_tee_product_type');
			const lockEl = document.getElementById('mr_tee_lock_aspect_ratio');

			const activeSide = this.getSideById(this.getActiveSideId()) || {
				id: this.getActiveSideId(),
				print_real_width: 30.0,
				print_real_height: 40.0,
				print_unit: 'cm',
				dpi: 300,
				product_type: 'garment',
			};
			const rawW = rwEl ? parseFloat(rwEl.value) : NaN;
			const rawH = rhEl ? parseFloat(rhEl.value) : NaN;
			const rawDpi = dpiEl ? parseInt(dpiEl.value, 10) : NaN;

			const w = (Number.isFinite(rawW) && rawW > 0) ? rawW : (parseFloat(activeSide.print_real_width) || 30.0);
			const h = (Number.isFinite(rawH) && rawH > 0) ? rawH : (parseFloat(activeSide.print_real_height) || 40.0);
			const unit = unitEl ? (unitEl.value || 'cm') : 'cm';
			const dpi = Number.isFinite(rawDpi) ? Math.max(72, Math.min(1200, rawDpi)) : (parseInt(activeSide.dpi, 10) || 300);
			const pType = pTypeEl ? (pTypeEl.value || 'garment') : 'garment';
			if (pType === 'flat_object' && lockEl && !lockEl.checked) {
				lockEl.checked = true;
			}
			const isLocked = Boolean(lockEl && lockEl.checked) || pType === 'flat_object';

			const m = this.computeSideExportData({
				...activeSide,
				print_real_width: w,
				print_real_height: h,
				print_unit: unit,
				dpi,
				product_type: pType,
			});

			// Update Live Drawer Badge (including dynamic DPI label!)
			const badgeCumEl = document.getElementById('mr-tee-badge-cum-val');
			const badgeRatioEl = document.getElementById('mr-tee-badge-ratio-val');
			const badgePxEl = document.getElementById('mr-tee-badge-px-val');
			const badgeDpiEl = document.getElementById('mr-tee-badge-dpi-val');

			const nextCumText = `${m.cumRealW} × ${m.cumRealH} ${m.unit}`;
			const nextRatioText = `${m.simplified} (~${m.cumRatio.toFixed(3)} : 1)`;
			const nextPxText = `${m.pxW} × ${m.pxH} px`;
			const nextDpiStr = String(m.dpi);

			if (badgeCumEl && badgeCumEl.textContent !== nextCumText) badgeCumEl.textContent = nextCumText;
			if (badgeRatioEl && badgeRatioEl.textContent !== nextRatioText) badgeRatioEl.textContent = nextRatioText;
			if (badgePxEl && badgePxEl.textContent !== nextPxText) badgePxEl.textContent = nextPxText;
			if (badgeDpiEl && badgeDpiEl.textContent !== nextDpiStr) badgeDpiEl.textContent = nextDpiStr;

			// Update Compact Single-Row Summary Bar above the stage
			const sumTypeEl = document.getElementById('mr-tee-summary-type');
			const sumSurfaceEl = document.getElementById('mr-tee-summary-surface');
			const sumDpiEl = document.getElementById('mr-tee-summary-dpi');
			const sumCumEl = document.getElementById('mr-tee-summary-cum');
			const sumPxEl = document.getElementById('mr-tee-summary-px');

			const nextTypeText = m.pType === 'flat_object' ? '📇 Objet Plat' : '👕 Vêtement';
			const nextSurfaceText = `${m.w} × ${m.h} ${m.unit}`;
			const nextDpiLabel = `${m.dpi} DPI`;

			if (sumTypeEl && sumTypeEl.textContent !== nextTypeText) sumTypeEl.textContent = nextTypeText;
			if (sumSurfaceEl && sumSurfaceEl.textContent !== nextSurfaceText) sumSurfaceEl.textContent = nextSurfaceText;
			if (sumDpiEl && sumDpiEl.textContent !== nextDpiLabel) sumDpiEl.textContent = nextDpiLabel;
			if (sumCumEl && sumCumEl.textContent !== nextCumText) sumCumEl.textContent = nextCumText;
			if (sumPxEl && sumPxEl.textContent !== nextPxText) sumPxEl.textContent = nextPxText;

			// Update Stage Canvas Wrap aspect-ratio & --stage-ratio CSS var for Portrait lock fix
			const wrapEl = this.$canvasWrap && this.$canvasWrap[0];
			if (wrapEl) {
				const ratioStr = m.refRatio.toFixed(4);
				const lockSig = isLocked ? ratioStr : 'unlocked';
				if (wrapEl._lastLockSig !== lockSig) {
					wrapEl._lastLockSig = lockSig;
					if (isLocked) {
						wrapEl.classList.add('is-aspect-locked');
						wrapEl.style.aspectRatio = ratioStr;
						wrapEl.style.setProperty('--stage-ratio', ratioStr);
					} else {
						wrapEl.classList.remove('is-aspect-locked');
						wrapEl.style.aspectRatio = '';
						wrapEl.style.removeProperty('--stage-ratio');
					}
				}
			}

			if ($('#tab-export').hasClass('is-active')) {
				this.renderExportSummary();
			}
		}

		/* =====================================================================
		 * Multi-Side & Multi-View Stage Manager
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
			const defaultH = this.isImageLikeType(this.getFieldType(idx)) ? 30.0 : 8.5;
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
			this.scheduleHistoryCommit();
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
			const defaultH = this.isImageLikeType(this.getFieldType(idx)) ? 30.0 : 8.5;
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
			this.syncBoxBadgePosition(idx, t, h);

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
				$('#mr_tee_lock_aspect_ratio').prop('checked', Boolean(side.lock_aspect_ratio) || side.product_type === 'flat_object');
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

		ensureMetaboxInputsValid() {
			const metabox = document.getElementById('mr_tee_editor_config') || document.querySelector('.mr-tee-admin-wrap');
			if (!metabox) return;

			const controls = metabox.querySelectorAll('input, select, textarea');
			for (let i = 0; i < controls.length; i++) {
				const el = controls[i];
				if (el.disabled || el.type === 'hidden' || el.type === 'file' || el.type === 'button' || el.type === 'submit') {
					continue;
				}
				if (typeof el.checkValidity === 'function' && !el.checkValidity()) {
					if (el.type === 'number') {
						el.step = 'any';
						let val = parseFloat(el.value);
						const minAttr = el.min !== '' ? parseFloat(el.min) : null;
						const maxAttr = el.max !== '' ? parseFloat(el.max) : null;
						if (Number.isNaN(val)) {
							val = minAttr !== null ? minAttr : 0;
						}
						if (minAttr !== null && val < minAttr) val = minAttr;
						if (maxAttr !== null && val > maxAttr) val = maxAttr;
						el.value = String(val);
					} else if (el.type === 'url') {
						el.type = 'text';
					}
				}
			}
		}

		/**
		 * Flush multi-view hidden JSON inputs idempotently before WordPress form submit.
		 * Guarded with a 150ms throttle on submit so mousedown/click/submit on Publish never run 3x back-to-back.
		 */
		syncMultiViewInputs(isSubmitting = false) {
			if (isSubmitting) {
				const now = Date.now();
				if (now - this._lastSubmitSyncTs < 150) {
					return;
				}
				this._lastSubmitSyncTs = now;
			}
			this.flushActiveViewPlacements();
			this.updateActiveSideDimensionsFromToolbar({ skipJsonSync: true });
			this.syncHiddenMultiViewJson();

			if (isSubmitting) {
				this.ensureMetaboxInputsValid();
			}
		}

		isDefaultSideFirstView(view) {
			if (!view || !Array.isArray(this.previewViews) || !this.previewViews.length) return false;
			const defaultSideId = (this.printSides && this.printSides[0]) ? this.printSides[0].id : 'front';
			if (view.side_id !== defaultSideId) return false;
			const firstForDefault = this.previewViews.find(v => v && v.side_id === defaultSideId);
			return Boolean(firstForDefault && firstForDefault.id === view.id);
		}

		renderSidesBar() {
			const $list = $('#mr-tee-sides-list');
			if (!$list.length) return;

			const activeSideId = this.getActiveSideId();
			const fieldCountBySide = {};
			const totalFields = $('.mr-tee-field-box').length;
			for (let i = 0; i < totalFields; i++) {
				const sid = this.getFieldSideId(i);
				fieldCountBySide[sid] = (fieldCountBySide[sid] || 0) + 1;
			}

			const canDelete = this.printSides.length > 1;
			const pillsHtml = this.printSides.map(side => {
				const isActive = side.id === activeSideId;
				const fCount = fieldCountBySide[side.id] || 0;
				const dimsText = `${side.print_real_width}×${side.print_real_height} ${side.print_unit}`;
				const safeId = this.escapeXml(side.id);
				const safeLabel = this.escapeXml(side.label || side.id);
				return `
					<div class="mr-tee-side-pill ${isActive ? 'is-active' : ''}" data-side-id="${safeId}">
						<button type="button" class="mr-tee-side-pill-main" data-side-id="${safeId}" aria-pressed="${isActive ? 'true' : 'false'}">
							<span class="mr-tee-side-pill-dot" aria-hidden="true"></span>
							<span class="mr-tee-side-pill-label">${safeLabel}</span>
							<span class="mr-tee-side-pill-dims">${this.escapeXml(dimsText)} · ${fCount}</span>
						</button>
						<span class="mr-tee-side-pill-controls">
							<button type="button" class="mr-tee-side-action-btn mr-tee-side-rename-btn" data-side-id="${safeId}" title="Renommer cette face" aria-label="Renommer ${safeLabel}">✎</button>
							${canDelete ? `<button type="button" class="mr-tee-side-action-btn mr-tee-side-delete-btn" data-side-id="${safeId}" title="Supprimer cette face" aria-label="Supprimer ${safeLabel}">✕</button>` : ''}
						</span>
					</div>
				`;
			}).join('');

			$list.html(pillsHtml);

			const hasBack = this.printSides.some(s => s.id === 'back');
			$('#mr-tee-btn-add-back-side').toggle(!hasBack);
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
				const thumbUrl = this.normalizeAdminAssetUrl(
					view.image_url || (this.isDefaultSideFirstView(view) ? this.defaultThumbUrl : '')
				);

				return `
					<button
						type="button"
						class="mr-tee-view-tab ${isActive ? 'is-active' : ''}"
						data-view-id="${this.escapeXml(view.id)}"
						data-side-id="${this.escapeXml(view.side_id)}"
						data-view-role="${this.escapeXml(role)}"
						aria-pressed="${isActive ? 'true' : 'false'}"
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
								${view.is_cart_preview ? '<span class="mr-tee-view-badge-cart" title="Aperçu panier" aria-label="Aperçu panier">🛒</span>' : ''}
							</span>
						</span>
					</button>
				`;
			}).join('');

			$list.html(tabsHtml);
		}

		updateStageImageDisplay(imgUrl) {
			const $img = $('#mr-tee-stage-garment-img');
			const $overlay = $('#mr-tee-stage-empty-overlay');
			const hasImg = Boolean(imgUrl && imgUrl.trim() !== '');

			if (this.$canvasWrap.length) {
				this.$canvasWrap.toggleClass('is-no-image', !hasImg);
			}

			if (hasImg) {
				$overlay.hide();
				if ($img.length) {
					if ($img.attr('src') !== imgUrl) {
						this._cachedStageRatio = 0;
						$img.attr('src', imgUrl);
					}
					$img.show();
				}
			} else {
				if ($img.length) {
					$img.hide().attr('src', '');
				}
				$overlay.show();
			}
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
				.html(`<strong>Face « ${this.escapeXml(sideLabel)} » sans zone</strong><span>Cliquez sur « + Texte » ou « 🖼️ + Image » ci-dessus pour créer une zone sur cette face.</span>`)
				.show();
		}

		setViewUploadStatus(message, tone = 'info') {
			const $bar = $('#mr-tee-active-view-bar');
			if (!$bar.length) return;
			let $status = $('#mr-tee-view-upload-status');
			if (!$status.length) {
				$status = $('<span id="mr-tee-view-upload-status" class="mr-tee-view-upload-status" aria-live="polite"></span>');
				$bar.append($status);
			}
			const safeTone = ['info', 'success', 'error', 'muted'].includes(tone) ? tone : 'muted';
			$status
				.text(message || '')
				.removeClass('is-info is-success is-error is-muted')
				.addClass(`is-${safeTone}`);
		}

		highlightViewImagePrompt(message) {
			const $btn = $('#mr_tee_view_image_btn');
			if ($btn.length) {
				$btn.removeClass('mr-tee-attention-pulse');
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

			const viewChanged = Boolean(this.activeViewId && this.activeViewId !== nextView.id);
			if (!opts.isInitial && this._multiviewInitialized && viewChanged) {
				const prevView = this.previewViews.find(v => v.id === this.activeViewId);
				if (prevView) {
					this.flushActiveViewPlacements();
					this.updateActiveSideDimensionsFromToolbar({ skipJsonSync: true });
				}
			}

			this.activeViewId = nextView.id;
			const activeSideId = nextView.side_id;

			// 1. Highlight active view tab and printable side pill
			$('.mr-tee-view-tab').removeClass('is-active').attr('aria-pressed', 'false').filter(function() {
				return $(this).attr('data-view-id') === nextView.id;
			}).addClass('is-active').attr('aria-pressed', 'true');
			$('.mr-tee-side-pill').removeClass('is-active').each(function() {
				const active = $(this).attr('data-side-id') === activeSideId;
				$(this).toggleClass('is-active', active);
				$(this).find('.mr-tee-side-pill-main').attr('aria-pressed', active ? 'true' : 'false');
			});

			// 2. Swap stage garment mockup image (only default side's primary view falls back to WC featured thumb)
			const viewIdx = this.previewViews.findIndex(v => v.id === nextView.id);
			const effectiveImgUrl = nextView.image_url
				? this.normalizeAdminAssetUrl(nextView.image_url)
				: (this.isDefaultSideFirstView(nextView) && this.defaultThumbUrl ? this.defaultThumbUrl : '');
			this.updateStageImageDisplay(effectiveImgUrl);

			// 3. Populate Active View Settings bar
			$('#mr-tee-active-view-bar').attr('data-active-view-id', nextView.id);
			$('#mr_tee_view_label').val(nextView.label || '');
			if ($('#mr_tee_view_side_id').length) {
				$('#mr_tee_view_side_id').val(activeSideId);
			}
			$('#mr_tee_view_role').val(nextView.view_role || 'full');
			$('#mr_tee_view_blend_mode').val(nextView.blend_mode || 'normal');
			$('#mr_tee_view_is_cart_preview').prop('checked', Boolean(nextView.is_cart_preview));

			$('#mr_tee_view_move_left').prop('disabled', viewIdx <= 0);
			$('#mr_tee_view_move_right').prop('disabled', viewIdx < 0 || viewIdx >= this.previewViews.length - 1);
			$('#mr_tee_view_delete_btn').prop('disabled', this.previewViews.length <= 1);

			// 4. Apply live blend preview attribute
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
						const defaultH = this.isImageLikeType(this.getFieldType(i)) ? 30.0 : 8.5;
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
				this.setActiveField(-1);
			} else {
				this.updateFieldToolbarState();
			}

			this.updateEmptySideNotice(activeSideId, firstMatchingIdx === -1);

			// 7. Sync physical dimension toolbar to active side
			this.loadSideDimensionsToToolbar(activeSideId);

			// 8. Recalculate aspect ratio / dimensions, then schedule box preview render
			this.recalculateDimensions();
			this.renderAllBoxesPreviews();
			this.syncHiddenMultiViewJson();

			if (!opts.isInitial && viewChanged) {
				this.resetHistory();
				this.ensureHistoryBase();
			}
		}

		bindMultiViewManager() {
			const self = this;

			this.renderSidesBar();
			this.renderViewsBar();
			this.refreshAllSideSelects();

			// Empty Mockup Overlay Quick Buttons
			$(document).on('click', '.mr-tee-empty-pick-wp', function(e) {
				e.preventDefault();
				$('#mr_tee_view_image_btn').trigger('click');
			});
			$(document).on('click', '.mr-tee-empty-upload-file', function(e) {
				e.preventDefault();
				$('#mr_tee_view_image_upload').trigger('click');
			});

			// Switch active view
			$(document).on('click', '.mr-tee-view-tab', function(e) {
				e.preventDefault();
				const viewId = $(this).attr('data-view-id');
				if (viewId) {
					self.setViewUploadStatus('');
					self.setActiveView(viewId);
				}
			});

			// Switch active side (click or Enter/Space on side pill)
			const activateSideById = (sideId) => {
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
			};

			$(document).on('click', '.mr-tee-side-pill-main', function(e) {
				e.preventDefault();
				activateSideById($(this).attr('data-side-id'));
			});

			// Inline Rename Side (✎)
			$(document).on('click', '.mr-tee-side-rename-btn', function(e) {
				e.preventDefault();
				e.stopPropagation();
				const sideId = $(this).attr('data-side-id');
				const side = self.printSides.find(s => s.id === sideId);
				if (!side) return;

				const nextLabel = window.prompt('Renommer cette face d\'impression :', side.label || side.id);
				if (nextLabel === null || !nextLabel.trim()) return;

				side.label = nextLabel.trim();
				self.renderSidesBar();
				self.renderViewsBar();
				self.refreshAllSideSelects();
				self.syncHiddenMultiViewJson();
				self.renderExportSummary();
				self.markDirty();
			});

			// Inline Delete Side (✕) — keeps at least 1 side, migrates/removes views & fields cleanly
			$(document).on('click', '.mr-tee-side-delete-btn', function(e) {
				e.preventDefault();
				e.stopPropagation();
				if (self.printSides.length <= 1) return;

				const sideId = $(this).attr('data-side-id');
				const sideIdx = self.printSides.findIndex(s => s.id === sideId);
				if (sideIdx < 0) return;

				const victimSide = self.printSides[sideIdx];
				const affectedViews = self.previewViews.filter(v => v.side_id === sideId);
				const totalFields = $('.mr-tee-field-box').length;
				const affectedFieldIndices = [];
				for (let i = 0; i < totalFields; i++) {
					if (self.getFieldSideId(i) === sideId) {
						affectedFieldIndices.push(i);
					}
				}

				const msg = `Supprimer la face « ${victimSide.label || victimSide.id} » ?\n\n`
					+ `• ${affectedViews.length} vue(s) associée(s) seront supprimées.\n`
					+ (affectedFieldIndices.length === totalFields
						? `• Les ${affectedFieldIndices.length} champ(s) seront déplacés vers la face restante.`
						: `• ${affectedFieldIndices.length} champ(s) de cette face seront supprimés.`);
				if (!confirm(msg)) return;

				// Capture active field's stable ID before DOM removal / reindex
				const prevActiveFieldId = (self.activeFieldIndex >= 0)
					? ($(`#mr-tee-field-panel-${self.activeFieldIndex} .mr-tee-field-id`).val() || '')
					: '';

				// 1. Remove side
				self.printSides.splice(sideIdx, 1);
				const fallbackSide = self.printSides[0];

				// 2. Remove views on deleted side; guarantee at least 1 view remains
				const removedViewIds = new Set(affectedViews.map(v => v.id));
				self.previewViews = self.previewViews.filter(v => v.side_id !== sideId);
				if (!self.previewViews.length) {
					self.previewViews.push({
						id: self.generateUniqueViewId(`view_${fallbackSide.id}`),
						label: fallbackSide.label || 'Vue Principale',
						side_id: fallbackSide.id,
						view_role: 'full',
						image_id: 0,
						image_url: self.defaultThumbUrl || '',
						blend_mode: 'normal',
						is_cart_preview: true,
					});
				}
				const fallbackView = self.getPrimaryViewForSide(fallbackSide.id, false) || self.previewViews[0];

				// 3. Migrate or remove fields on deleted side
				if (affectedFieldIndices.length === totalFields) {
					// Keep fields alive by migrating them cleanly to fallbackSide
					for (let i = 0; i < totalFields; i++) {
						const curCoords = self.getFieldCurrentCoords(i);
						const newPlacements = {};
						self.previewViews.forEach(v => {
							if (v.side_id === fallbackSide.id) {
								newPlacements[v.id] = { ...curCoords };
							}
						});
						self.setFieldPlacements(i, newPlacements);
						$(`#mr-tee-field-panel-${i}`).attr('data-side-id', fallbackSide.id);
						$(`#mr-tee-field-box-${i}`).attr('data-side-id', fallbackSide.id);
						$(`.mr-tee-field-pill[data-field-index="${i}"]`).attr('data-side-id', fallbackSide.id);
					}
				} else if (affectedFieldIndices.length > 0) {
					for (let k = affectedFieldIndices.length - 1; k >= 0; k--) {
						const fIdx = affectedFieldIndices[k];
						$(`.mr-tee-field-pill[data-field-index="${fIdx}"]`).remove();
						$(`#mr-tee-field-box-${fIdx}`).remove();
						$(`#mr-tee-field-panel-${fIdx}`).remove();
					}
					self.reindexFields();
				}

				// 4. Clean removed view IDs from remaining fields' placements maps
				const remCount = $('.mr-tee-field-box').length;
				let preferredFieldIndex = -1;
				for (let i = 0; i < remCount; i++) {
					if (prevActiveFieldId && $(`#mr-tee-field-panel-${i} .mr-tee-field-id`).val() === prevActiveFieldId) {
						preferredFieldIndex = i;
					}
					const placements = self.getFieldPlacements(i);
					let mutated = false;
					removedViewIds.forEach(vid => {
						if (Object.prototype.hasOwnProperty.call(placements, vid)) {
							delete placements[vid];
							mutated = true;
						}
					});
					if (mutated) self.setFieldPlacements(i, placements);
				}

				// Refresh side <select> options BEFORE renderSidesBar() so field counts read updated values!
				self.activeFieldIndex = -1;
				self.refreshAllSideSelects();
				self.renderSidesBar();
				self.renderViewsBar();
				self.syncHiddenMultiViewJson();
				self.setViewUploadStatus('');

				const activeStillExists = self.previewViews.some(v => v.id === self.activeViewId);
				self.setActiveView(
					activeStillExists ? self.activeViewId : fallbackView.id,
					preferredFieldIndex >= 0 ? { preferredFieldIndex } : {}
				);
				self.renderExportSummary();
				self.resetHistory();
				self.ensureHistoryBase();
				self.markDirty();
			});

			// Add New View
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
				self.markDirty();
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
						label: 'Verso (Dos)',
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
				self.renderExportSummary();
				self.markDirty();
			});

			// Add Custom Printable Side
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
				self.renderExportSummary();
				self.markDirty();
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
				self.renderExportSummary();
			});

			// Active View Settings: View Role
			$(document).on('change', '#mr_tee_view_role', function() {
				const view = self.getActiveView();
				if (!view) return;
				const role = $(this).val();
				view.view_role = ['full', 'closeup', 'detail'].includes(role) ? role : 'full';
				self.renderViewsBar();
				self.syncHiddenMultiViewJson();
				self.recalculateDimensions();
				self.renderExportSummary();
			});

			// Active View Settings: Blend Mode
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

			// Active View Image Picker (WP Media Library)
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
					if (!self.previewViews.some(v => v.id === view.id)) return;
					const att = frame.state().get('selection').first().toJSON();
					const url = (att && att.url) ? att.url : '';
					const attId = (att && att.id) ? parseInt(att.id, 10) : 0;
					if (!url) return;

					const normUrl = self.normalizeAdminAssetUrl(url);
					view.image_id = attId || 0;
					view.image_url = normUrl;
					if (self.activeViewId === view.id) {
						self.updateStageImageDisplay(normUrl);
						self.recalculateDimensions();
						self.renderAllBoxesPreviews();
					}
					self.renderViewsBar();
					self.syncHiddenMultiViewJson();
					self.setViewUploadStatus('✅ Image mockup mise à jour !', 'success');
					self.markDirty();
				});

				frame.open();
			});

			// Active View Direct File Upload
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
						if (!self.previewViews.some(v => v.id === view.id)) return;
						if (resp && resp.success && resp.data && resp.data.url) {
							const normUrl = self.normalizeAdminAssetUrl(resp.data.url);
							view.image_id = parseInt(resp.data.id, 10) || 0;
							view.image_url = normUrl;
							$('#mr_tee_view_image_btn').removeClass('mr-tee-attention-pulse');
							if (self.activeViewId === view.id) {
								self.updateStageImageDisplay(normUrl);
								self.recalculateDimensions();
								self.renderAllBoxesPreviews();
							}
							self.renderViewsBar();
							self.syncHiddenMultiViewJson();
							self.setViewUploadStatus('✅ Image mockup importée !', 'success');
							self.markDirty();
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

			// Move Active View Left / Right
			const refreshAfterViewReorder = (nextIdx) => {
				const activeView = self.getActiveView();
				self.renderViewsBar();
				self.syncHiddenMultiViewJson();
				if (activeView && !activeView.image_url) {
					const effUrl = (self.isDefaultSideFirstView(activeView) && self.defaultThumbUrl) ? self.defaultThumbUrl : '';
					self.updateStageImageDisplay(effUrl);
				}
				self.recalculateDimensions();
				self.renderExportSummary();
				$('#mr_tee_view_move_left').prop('disabled', nextIdx <= 0);
				$('#mr_tee_view_move_right').prop('disabled', nextIdx >= self.previewViews.length - 1);
				self.markDirty();
			};

			$(document).on('click', '#mr_tee_view_move_left', function(e) {
				e.preventDefault();
				const idx = self.previewViews.findIndex(v => v.id === self.activeViewId);
				if (idx <= 0) return;
				const tmp = self.previewViews[idx - 1];
				self.previewViews[idx - 1] = self.previewViews[idx];
				self.previewViews[idx] = tmp;
				refreshAfterViewReorder(idx - 1);
			});

			$(document).on('click', '#mr_tee_view_move_right', function(e) {
				e.preventDefault();
				const idx = self.previewViews.findIndex(v => v.id === self.activeViewId);
				if (idx < 0 || idx >= self.previewViews.length - 1) return;
				const tmp = self.previewViews[idx + 1];
				self.previewViews[idx + 1] = self.previewViews[idx];
				self.previewViews[idx] = tmp;
				refreshAfterViewReorder(idx + 1);
			});

			// Delete Active View (never delete last remaining view)
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
				self.renderExportSummary();
				self.markDirty();
			});

			// Per-field Side Selector Change
			$(document).on('change', '.mr-tee-field-side-select', function() {
				const rawIdx = $(this).attr('data-field-index') || $(this).closest('.mr-tee-field-panel').attr('data-field-index');
				const idx = parseInt(rawIdx, 10);
				if (Number.isNaN(idx)) return;

				const newSideId = $(this).val();
				if (!newSideId) return;

				$(`#mr-tee-field-box-${idx}`).attr('data-side-id', newSideId);
				$(`.mr-tee-field-pill[data-field-index="${idx}"]`).attr('data-side-id', newSideId);
				$(`#mr-tee-field-panel-${idx}`).attr('data-side-id', newSideId);

				const currentCoords = self.getFieldCurrentCoords(idx);
				const prevPlacements = self.getFieldPlacements(idx);
				const nextPlacements = {};
				self.previewViews.forEach(v => {
					if (v.side_id === newSideId) {
						nextPlacements[v.id] = (prevPlacements[v.id] && prevPlacements[v.id].box_left !== undefined)
							? prevPlacements[v.id]
							: { ...currentCoords };
					}
				});
				self.setFieldPlacements(idx, nextPlacements);
				self.renderSidesBar();

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
						nextPlacements[targetView.id] = { ...currentCoords };
						self.setFieldPlacements(idx, nextPlacements);
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
				self.renderExportSummary();
			});

			// Flush multi-view JSON right before WordPress post form submit (throttled against 3x back-to-back calls)
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
