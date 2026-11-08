/**
 * Mr. Tee Editor - Gallery Stage Overlay Module
 *
 * Mounts and synchronizes the live SVG scene-graph overlay directly on top
 * of WooCommerce product gallery images using exact Editor Area percentages
 * (`box_left`, `box_top`, `box_width`, `box_height`) and PersoPlacement transforms.
 *
 * Fully responsive and mobile-resilient:
 * - Preserves live SVG markup across mobile keyboard events and viewport resizes
 * - Synchronizes personalized SVG artwork with PhotoSwipe fullscreen zoom
 * - Real-time mini-preview sync in mobile sticky bar
 *
 * @package MrTeeEditor\Assets\Modules
 */

export class GalleryStage {
	constructor(config) {
		this.config = config || {};
		this.overlayElem = null;
		this.svgHolder = null;
		this.attachedImage = null;
		this.currentSvgMarkup = '';
		this.lastWindowWidth = typeof window !== 'undefined' ? window.innerWidth : 0;

		this.init();
	}

	/**
	 * Mount overlay on the active WooCommerce gallery slide.
	 */
	init() {
		this.mountOverlay();
		this.initStickyPreview();
		this.bindGalleryEvents();
		this.suppressZoom();
		this.initPhotoSwipeSync();
	}

	/**
	 * Permanently suppress WooCommerce/Shoptimizer raw zoomImg from covering the personalized overlay.
	 */
	suppressZoom() {
		const killZoom = () => {
			const gallery = document.querySelector('.woocommerce-product-gallery');
			const target = gallery || document;
			target.querySelectorAll('.zoomImg').forEach((img) => {
				img.style.setProperty('display', 'none', 'important');
				img.style.setProperty('opacity', '0', 'important');
				img.style.setProperty('pointer-events', 'none', 'important');
			});
		};

		killZoom();

		if (!this.zoomObserverInitialized) {
			this.zoomObserverInitialized = true;
			const gallery = document.querySelector('.woocommerce-product-gallery');
			if (gallery && window.MutationObserver) {
				const zoomObserver = new MutationObserver(() => killZoom());
				zoomObserver.observe(gallery, { childList: true, subtree: true });
			}
		}
	}

	/**
	 * Search for the main WooCommerce product image container and mount overlay.
	 */
	mountOverlay() {
		// Detect WooCommerce gallery targets in priority order
		const selectors = [
			'.woocommerce-product-gallery .flex-active-slide',
			'.woocommerce-product-gallery__image--placeholder',
			'.woocommerce-product-gallery__image:first-child',
			'.woocommerce-product-gallery__wrapper > div:first-child',
			'.woocommerce-product-gallery',
		];

		let container = null;
		for (const sel of selectors) {
			const el = document.querySelector(sel);
			if (el && el.offsetParent !== null) {
				container = el;
				break;
			}
		}

		if (!container) {
			container = document.querySelector('.woocommerce-product-gallery') || document.querySelector('.product .images');
		}

		if (!container) return;

		// Ensure container has relative positioning
		if (window.getComputedStyle(container).position === 'static') {
			container.style.position = 'relative';
		}

		// Check if overlay already exists in this container
		let overlay = container.querySelector('.mr-tee-live-stage-overlay:not(.mr-tee-live-stage-overlay-pswp)');
		let svgHolder = overlay ? overlay.querySelector('.mr-tee-live-svg-container') : null;

		if (overlay && svgHolder && overlay.parentElement === container) {
			// Already properly mounted in this container!
			this.overlayElem = overlay;
			this.svgHolder = svgHolder;
			this.recalibrateOverlayPosition(container, overlay);
			if (this.currentSvgMarkup && (!this.svgHolder.innerHTML || !this.svgHolder.querySelector('svg'))) {
				this.svgHolder.innerHTML = this.currentSvgMarkup;
			}
			return;
		}

		// Remove any misplaced or orphaned overlays from other elements
		document.querySelectorAll('.mr-tee-live-stage-overlay:not(.mr-tee-live-stage-overlay-pswp)').forEach((el) => {
			if (el !== overlay) el.remove();
		});

		// Create stage overlay if not already existing
		if (!overlay) {
			overlay = document.createElement('div');
			overlay.className = 'mr-tee-live-stage-overlay';
			overlay.style.position = 'absolute';
			overlay.style.pointerEvents = 'none';
			overlay.style.zIndex = '50';
			overlay.style.display = 'flex';
			overlay.style.alignItems = 'center';
			overlay.style.justifyContent = 'center';

			svgHolder = document.createElement('div');
			svgHolder.className = 'mr-tee-live-svg-container';
			svgHolder.style.width = '100%';
			svgHolder.style.height = '100%';
			overlay.appendChild(svgHolder);

			container.appendChild(overlay);
		}

		this.overlayElem = overlay;
		this.svgHolder = svgHolder;

		// Calculate precise position against rendered image bounds
		this.recalibrateOverlayPosition(container, overlay);

		// Listen to image load if image is still loading
		const img = container.querySelector('img');
		if (img && !img.complete) {
			img.addEventListener('load', () => this.recalibrateOverlayPosition(container, overlay), { once: true });
		}

		// Immediately restore current SVG markup if available
		if (this.currentSvgMarkup && this.svgHolder) {
			this.svgHolder.innerHTML = this.currentSvgMarkup;
		}
	}

	/**
	 * Calibrate overlay positioning strictly against the rendered garment image bounds.
	 *
	 * Accounts for any letterboxing, flexbox centering margins, or padding introduced
	 * by WordPress themes, ensuring the design area remains pixel-pinned to the garment fabric.
	 *
	 * @param {HTMLElement} container Slide / gallery wrapper.
	 * @param {HTMLElement} overlay Stage overlay element.
	 */
	recalibrateOverlayPosition(container, overlay) {
		if (!container || !overlay) return;

		const hasPerLineBoxes = Array.isArray(this.config.text_lines) && this.config.text_lines.some(l => l.box_left !== undefined && l.box_width !== undefined);

		let baseLeft = hasPerLineBoxes ? 0.0 : (parseFloat(this.config.box_left) || 26.0);
		let baseTop = hasPerLineBoxes ? 0.0 : (parseFloat(this.config.box_top) || 23.0);
		let baseWidth = hasPerLineBoxes ? 100.0 : (parseFloat(this.config.box_width) || 48.0);
		let baseHeight = hasPerLineBoxes ? 100.0 : (parseFloat(this.config.box_height) || 54.0);

		const img = container.querySelector('img.wp-post-image, .woocommerce-product-gallery__image img, img');
		if (img && img.offsetWidth > 0 && container.offsetWidth > 0) {
			const imgLeft = img.offsetLeft;
			const imgTop = img.offsetTop;
			const imgW = img.offsetWidth;
			const imgH = img.offsetHeight;
			const contW = container.offsetWidth;
			const contH = container.offsetHeight;

			// Check if image has offset inside container or doesn't occupy 100% of container dimensions
			if (imgLeft !== 0 || imgTop !== 0 || Math.abs(imgW - contW) > 2 || Math.abs(imgH - contH) > 2) {
				const leftPct = ((imgLeft + (imgW * (baseLeft / 100))) / contW) * 100;
				const topPct = ((imgTop + (imgH * (baseTop / 100))) / contH) * 100;
				const widthPct = ((imgW * (baseWidth / 100)) / contW) * 100;
				const heightPct = ((imgH * (baseHeight / 100)) / contH) * 100;

				overlay.style.left = `${leftPct.toFixed(3)}%`;
				overlay.style.top = `${topPct.toFixed(3)}%`;
				overlay.style.width = `${widthPct.toFixed(3)}%`;
				overlay.style.height = `${heightPct.toFixed(3)}%`;
				return;
			}
		}

		// Direct percentage alignment
		overlay.style.left = `${baseLeft}%`;
		overlay.style.top = `${baseTop}%`;
		overlay.style.width = `${baseWidth}%`;
		overlay.style.height = `${baseHeight}%`;
	}

	/**
	 * Synchronize live customized overlay with WooCommerce PhotoSwipe fullscreen lightbox.
	 */
	initPhotoSwipeSync() {
		const attachToPswp = () => {
			const pswp = document.querySelector('.pswp--open');
			if (!pswp) return;

			// Target the active zoom-wrap
			const zoomWrap = pswp.querySelector('.pswp__zoom-wrap');
			if (!zoomWrap) return;

			// Ensure position relative for absolute positioning
			if (window.getComputedStyle(zoomWrap).position === 'static') {
				zoomWrap.style.position = 'relative';
			}

			const hasPerLineBoxes = Array.isArray(this.config.text_lines) && this.config.text_lines.some(l => l.box_left !== undefined && l.box_width !== undefined);
			const left = hasPerLineBoxes ? 0.0 : (parseFloat(this.config.box_left) || 26.0);
			const top = hasPerLineBoxes ? 0.0 : (parseFloat(this.config.box_top) || 23.0);
			const width = hasPerLineBoxes ? 100.0 : (parseFloat(this.config.box_width) || 48.0);
			const height = hasPerLineBoxes ? 100.0 : (parseFloat(this.config.box_height) || 54.0);

			let pswpOverlay = zoomWrap.querySelector('.mr-tee-live-stage-overlay-pswp');
			let pswpHolder = null;

			if (!pswpOverlay) {
				pswpOverlay = document.createElement('div');
				pswpOverlay.className = 'mr-tee-live-stage-overlay mr-tee-live-stage-overlay-pswp';
				pswpOverlay.style.position = 'absolute';
				pswpOverlay.style.left = `${left}%`;
				pswpOverlay.style.top = `${top}%`;
				pswpOverlay.style.width = `${width}%`;
				pswpOverlay.style.height = `${height}%`;
				pswpOverlay.style.pointerEvents = 'none';
				pswpOverlay.style.zIndex = '100';
				pswpOverlay.style.display = 'flex';
				pswpOverlay.style.alignItems = 'center';
				pswpOverlay.style.justifyContent = 'center';

				pswpHolder = document.createElement('div');
				pswpHolder.className = 'mr-tee-live-svg-container';
				pswpHolder.style.width = '100%';
				pswpHolder.style.height = '100%';
				pswpOverlay.appendChild(pswpHolder);

				zoomWrap.appendChild(pswpOverlay);
			} else {
				pswpHolder = pswpOverlay.querySelector('.mr-tee-live-svg-container');
			}

			if (pswpHolder && this.currentSvgMarkup && pswpHolder.innerHTML !== this.currentSvgMarkup) {
				pswpHolder.innerHTML = this.currentSvgMarkup;
			}
		};

		// 1. Listen for clicks on the zoom trigger and image links
		document.addEventListener('click', (e) => {
			if (e.target && e.target.closest('.woocommerce-product-gallery__trigger, .woocommerce-product-gallery__image a')) {
				setTimeout(attachToPswp, 100);
				setTimeout(attachToPswp, 300);
				setTimeout(attachToPswp, 600);
			}
		});

		// 2. Observe PhotoSwipe element ONLY for class changes (opening / closing)
		const pswpElem = document.querySelector('.pswp');
		if (pswpElem && window.MutationObserver) {
			let wasOpen = false;
			const pswpObserver = new MutationObserver(() => {
				const isOpen = pswpElem.classList.contains('pswp--open');
				if (isOpen && !wasOpen) {
					wasOpen = true;
					setTimeout(attachToPswp, 100);
					setTimeout(attachToPswp, 300);
					setTimeout(attachToPswp, 600);
				} else if (!isOpen && wasOpen) {
					wasOpen = false;
					// PhotoSwipe closed: ensure main overlay is pristine
					this.mountOverlay();
				}
			});
			pswpObserver.observe(pswpElem, { attributes: true, attributeFilter: ['class'] });
		}
	}

	/**
	 * Initialize mobile sticky preview bar showing live updates when gallery is scrolled out of view.
	 */
	initStickyPreview() {
		this.stickyBar = document.getElementById('mr-tee-mobile-sticky-preview');
		const btnScroll = document.getElementById('mr-tee-btn-scroll-mockup');

		if (btnScroll) {
			btnScroll.addEventListener('click', () => {
				const target = document.querySelector('.woocommerce-product-gallery') || document.querySelector('.product .images') || document.body;
				target.scrollIntoView({ behavior: 'smooth', block: 'start' });
			});
		}

		const checkScroll = () => {
			if (!this.stickyBar) return;
			const isMobile = window.innerWidth <= 768;
			if (!isMobile) {
				this.stickyBar.style.display = 'none';
				return;
			}

			const gallery = document.querySelector('.woocommerce-product-gallery') || document.querySelector('.product .images');
			if (!gallery) return;

			const rect = gallery.getBoundingClientRect();
			// If bottom of gallery is near top of viewport (user scrolled to form), show sticky preview
			if (rect.bottom < 220) {
				this.stickyBar.style.display = 'block';
			} else {
				this.stickyBar.style.display = 'none';
			}
		};

		window.addEventListener('scroll', checkScroll, { passive: true });
		window.addEventListener('resize', checkScroll, { passive: true });
		checkScroll();
	}

	/**
	 * Listen to WooCommerce gallery change events (Flexslider, thumbnail clicks, variation swatch changes).
	 */
	bindGalleryEvents() {
		// When user clicks a gallery thumbnail
		document.addEventListener('click', (e) => {
			if (e.target && e.target.closest('.flex-control-thumbs li, .woocommerce-product-gallery__wrapper')) {
				setTimeout(() => {
					this.mountOverlay();
					this.suppressZoom();
				}, 150);
			}
		});

		// Listen to jQuery WooCommerce events if jQuery is present
		if (window.jQuery) {
			window.jQuery(document).on('woocommerce_gallery_init_zoom woocommerce_variation_has_changed flexslider_after', () => {
				setTimeout(() => {
					this.mountOverlay();
					this.suppressZoom();
				}, 100);
			});
		}

		// Responsive resize listener: debounce and ignore pure height changes (virtual keyboard)
		let resizeTimer = null;
		window.addEventListener('resize', () => {
			clearTimeout(resizeTimer);
			resizeTimer = setTimeout(() => {
				const currentWidth = window.innerWidth;
				// Only re-check mount on actual horizontal layout changes or orientation switch
				if (Math.abs(currentWidth - this.lastWindowWidth) > 10) {
					this.lastWindowWidth = currentWidth;
					this.mountOverlay();
				} else {
					// If width didn't change (e.g. keyboard open/close), ensure SVG is still intact
					if (this.svgHolder && this.currentSvgMarkup && (!this.svgHolder.innerHTML || !this.svgHolder.querySelector('svg'))) {
						this.svgHolder.innerHTML = this.currentSvgMarkup;
					}
				}
				this.suppressZoom();
			}, 100);
		});
	}

	/**
	 * Update the live SVG preview content.
	 *
	 * @param {string} svgMarkup Raw SVG markup string.
	 */
	updateSvg(svgMarkup) {
		this.currentSvgMarkup = svgMarkup || '';

		if (!this.svgHolder || !this.svgHolder.isConnected) {
			this.mountOverlay();
		}

		if (this.svgHolder) {
			this.svgHolder.innerHTML = this.currentSvgMarkup;
		}

		// Synchronize mobile sticky preview
		const miniOverlay = document.querySelector('.mr-tee-mobile-overlay-mini');
		if (miniOverlay) {
			miniOverlay.innerHTML = this.currentSvgMarkup;
		}

		// Synchronize PhotoSwipe overlay if currently open
		const pswpHolder = document.querySelector('.pswp--open .mr-tee-live-stage-overlay-pswp .mr-tee-live-svg-container');
		if (pswpHolder) {
			pswpHolder.innerHTML = this.currentSvgMarkup;
		}
	}
}
