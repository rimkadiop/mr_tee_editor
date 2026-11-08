/**
 * Mr. Tee Editor - Gallery Stage Overlay Module
 *
 * Mounts and synchronizes the live SVG scene-graph overlay directly on top
 * of WooCommerce product gallery images using exact Editor Area percentages
 * (`box_left`, `box_top`, `box_width`, `box_height`) and PersoPlacement transforms.
 *
 * @package MrTeeEditor\Assets\Modules
 */

export class GalleryStage {
	constructor(config) {
		this.config = config || {};
		this.overlayElem = null;
		this.svgHolder = null;
		this.attachedImage = null;

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
	}

	/**
	 * Permanently suppress WooCommerce/Shoptimizer zoomImg from covering the personalized overlay.
	 */
	suppressZoom() {
		const killZoom = () => {
			document.querySelectorAll('.zoomImg').forEach((img) => {
				img.style.setProperty('display', 'none', 'important');
				img.style.setProperty('opacity', '0', 'important');
				img.style.setProperty('pointer-events', 'none', 'important');
			});
		};

		killZoom();
		setTimeout(killZoom, 300);
		setTimeout(killZoom, 1000);

		if (window.MutationObserver) {
			const zoomObserver = new MutationObserver(() => killZoom());
			zoomObserver.observe(document.body, { childList: true, subtree: true });
		}
	}

	/**
	 * Search for the main WooCommerce product image container and mount overlay.
	 */
	mountOverlay() {
		// Detect WooCommerce gallery targets
		const selectors = [
			'.woocommerce-product-gallery__image--placeholder',
			'.woocommerce-product-gallery .flex-active-slide',
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

		// Remove existing overlay if any
		const existing = container.querySelector('.mr-tee-live-stage-overlay');
		if (existing) {
			existing.remove();
		}

		// Create stage overlay
		const left = parseFloat(this.config.box_left) || 26.0;
		const top = parseFloat(this.config.box_top) || 23.0;
		const width = parseFloat(this.config.box_width) || 48.0;
		const height = parseFloat(this.config.box_height) || 54.0;

		const overlay = document.createElement('div');
		overlay.className = 'mr-tee-live-stage-overlay';
		overlay.style.position = 'absolute';
		overlay.style.left = `${left}%`;
		overlay.style.top = `${top}%`;
		overlay.style.width = `${width}%`;
		overlay.style.height = `${height}%`;
		overlay.style.pointerEvents = 'none';
		overlay.style.zIndex = '50';
		overlay.style.display = 'flex';
		overlay.style.alignItems = 'center';
		overlay.style.justifyContent = 'center';

		const svgHolder = document.createElement('div');
		svgHolder.className = 'mr-tee-live-svg-container';
		svgHolder.style.width = '100%';
		svgHolder.style.height = '100%';
		overlay.appendChild(svgHolder);

		container.appendChild(overlay);

		this.overlayElem = overlay;
		this.svgHolder = svgHolder;
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

		window.addEventListener('resize', () => {
			this.mountOverlay();
			this.suppressZoom();
		});
	}

	/**
	 * Update the live SVG preview content.
	 *
	 * @param {string} svgMarkup Raw SVG markup string.
	 */
	updateSvg(svgMarkup) {
		if (!this.svgHolder || !this.svgHolder.isConnected) {
			this.mountOverlay();
		}

		if (this.svgHolder) {
			this.svgHolder.innerHTML = svgMarkup;
		}

		// Synchronize mobile sticky preview
		const miniOverlay = document.querySelector('.mr-tee-mobile-overlay-mini');
		if (miniOverlay) {
			miniOverlay.innerHTML = svgMarkup;
		}
	}
}
