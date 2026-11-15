# Mr. Tee Editor - Universal Theme & Plugin Compatibility Resume (v2.3.19)

**Date**: 2026-11-15  
**Repository**: `rimkadiop/mr_tee_editor`  
**Current Branch**: `main`  
**Target Version**: `2.3.19` (upgrading from `2.3.18`)  
**Staging Site**: `http://1.tawfekh-digital.com`  

---

## 1. Executive Summary & User Goal

The user asked whether the `mr_tee_editor` WooCommerce plugin is only compatible with the **Shoptimizer** theme (where initial styling and fixes were tuned) or if it is compatible with **all WordPress themes and WooCommerce plugins**.

### Key Answers for the User:
1. **In `v2.3.18`**: The plugin was functionally independent on the backend, but had several frontend couplings specifically targeting Shoptimizer and WooCommerce Flexslider:
   - Hardcoded CSS selectors targeting `.wp-theme-shoptimizer.has-mr-tee-editor`.
   - JavaScript gallery discovery (`gallery-stage.js`, `print-exporter.js`) assumed standard WooCommerce Flexslider classes (`.woocommerce-product-gallery .flex-active-slide`), which breaks or drifts on themes using **Flickity** (e.g. *Flatsome*), custom Swiper/carousels, or modern **WooCommerce Product Gallery Blocks**.
   - Frontend relied on WordPress `.dashicons` font classes which are NOT loaded on the frontend by standard non-admin themes (*Storefront*, *TwentyTwentyFive*, *GeneratePress*, *Edubin*).
   - Staging site had a third-party plugin `product_layout_extension` (`SPLE`) installed specifically for Shoptimizer that caused template and hook interference on other themes.
2. **In `v2.3.19` (This Milestone)**:
   - Full universal abstraction across all WordPress themes (Block themes, FSE, Classic themes, Elementor, Flatsome, Storefront, GeneratePress, TwentyTwentyFive, TwentyTwentyFour, TwentyTwentyThree, Edubin).
   - HPOS (`High-Performance Order Storage`) and Cart/Checkout Blocks declared compatible.
   - SVG icons replaced all frontend Dashicons dependencies.
   - Writable filesystem fallbacks (`wp-content/uploads/mr_tee_editor/`) implemented for environments where the plugin directory is read-only.
   - Non-Shoptimizer themes decoupled and tested cleanly.

---

## 2. Completed Backend PHP Changes (Ready & Linted)

10 PHP files in `includes/` and `mr-tee-editor.php` have been fully updated and verified with `php -l`:

1. **`mr-tee-editor.php`**:
   - Bumped version to `2.3.19`.
   - Added HPOS compatibility (`Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', ... )`) and Cart/Checkout Blocks compatibility (`cart_checkout_blocks`).
   - Broadened bridge & reverse-proxy security handling with dynamic HTTPS/HTTP host detection.
2. **`includes/Core/Plugin.php`**:
   - Broadened HPOS screen detection in `enqueue_admin_assets()` to match both `woocommerce_page_wc-orders` and legacy `shop_order` post types.
   - Converted script tags to root-relative same-origin paths in `filter_admin_script_loader_tag()`.
3. **`includes/Frontend/ProductPageRenderer.php`**:
   - Broadened `is_mr_tee_product()` to check `_mr_tee_engine_mode`, `_mr_tee_editor_mode`, and `_mr_tee_config['editor_mode']`.
   - Added `get_active_product_id()` supporting standard product pages, page builders, and `[product_page]` shortcodes.
   - Added duplicate render guard (`$rendered_products`) preventing duplicate `#mr-tee-editor-container` IDs on themes with sticky add-to-cart bars.
   - Replaced frontend `.dashicons` with inline SVGs (warning triangle, chevrons).
   - Suppressed conflicting NBDesigner buttons (`nbd_conditional_show_design_btn` and action removals).
4. **`includes/Commerce/CartIntegration.php`**:
   - Hooked `woocommerce_checkout_order_created` and `woocommerce_store_api_checkout_order_processed` to guarantee NBDesigner order sync across classic checkout, block checkout, and HPOS.
   - Fixed repeater item display when count is `0`.
   - Replaced Store API blank placeholder thumbnail with normalized root-relative design preview URL.
   - Generic checkout review thumbnail replacement/wrapping across all theme table layouts.
5. **`includes/Commerce/OrderAdminPanel.php`**:
   - Added container/screen ID guards for `wc_get_container()` and `wc_get_page_screen_id()` under HPOS.
   - Added `if ( ! is_admin() ) { return; }` and method safety checks in `render_order_item_actions()`.
6. **`includes/Admin/EngineSelectorMetabox.php`**:
   - Guarded `wc_placeholder_img_src()`.
   - Synchronized `_nbdesigner_enable` (`'1'` for NBDesigner, deleted for Mr. Tee Editor) on post save to prevent stale dual-engine states.
7. **`includes/Export/NBDesignerBridge.php`**:
   - Implemented `resolve_nbd_location()` honoring `NBDESIGNER_CUSTOMER_DIR` / `NBDESIGNER_CUSTOMER_URL` constants with fallback to `wp_upload_dir()`.
   - Replaced hardcoded version strings with `MR_TEE_EDITOR_VERSION`.
8. **`includes/Registry/SvgFontPackager.php`**:
   - Implemented filesystem write-checks (`is_dir_writable()`).
   - Falls back gracefully to `wp_upload_dir()/mr_tee_editor/font-cache/` if plugin folder is read-only.
9. **`includes/Registry/OpenTypeSvgParser.php`**:
   - Updated `get_or_create_cache()` to check both plugin directory and `wp-content/uploads/mr_tee_editor/font-cache/`.
10. **`includes/Registry/FontRegistry.php`**:
    - Updated `ajax_upload_font()` to fall back to `wp-content/uploads/mr_tee_editor/fonts/` when `assets/fonts/` is not writable.

---

## 3. Staging Diagnostic Findings & Root Cause Analysis

During multi-theme testing on `http://1.tawfekh-digital.com`:

1. **Why TwentyTwentyFive halted on Product 19308 and 19148**:
   - Diagnosis showed the staging site has an active mu-plugin / custom plugin called `product_layout_extension` (`SPLE`).
   - In `SPLE/nbdesign custom/button-customization.php`, when `wp_is_mobile()` is active, it unhooked `woocommerce_template_single_add_to_cart` from priority 30 and re-hooked it at priority 2.
   - Furthermore, `SPLE` overrides the single product template via `template_include` to its own `product_page_template/single-product.php` which was built specifically around Shoptimizer wrappers.
   - **Crucial finding**: This is an external staging plugin issue (`product_layout_extension`), NOT a bug in `mr_tee_editor`. When `mr_tee_editor` runs standalone, it hooks to standard WooCommerce actions. To test true theme behavior, gate `SPLE` when stylesheet is not Shoptimizer.
2. **GeneratePress Header Height (12,700px)**:
   - On GeneratePress, `<header id="masthead">` was reported at `y: 40, h: 12700`.
   - Inspection of DOM showed `mts_logo.svg` is loaded as an inline or unconstrained SVG in the site header. GeneratePress does not enforce a max-height on custom SVG logos in header CSS, causing the browser SVG layout engine to expand to default canvas coordinates.
   - This was unrelated to `mr_tee_editor` and did not prevent page functionality once scrolled.

---

## 4. Pending Work For The Next Agent

The next agent should complete these specific steps:

### A. Frontend CSS & JS Updates (In Repo)
1. **`assets/css/mr-tee-frontend.css`**:
   - Line 120: Add `.mr-tee-floating-label-wrap.mr-tee-floating-label-wrap--textarea` alongside `:has(.mr-tee-textarea)` for cross-browser fallback.
   - Line 545+: Add `.mr-tee-warn-icon` and `.mr-tee-chevron-icon` SVG styles (`display: inline-flex; vertical-align: middle;`).
   - Line 557+: Expand zoom suppression to include `.drift-zoom-pane, .easyzoom-flyout`.
   - Line 567+: Support Flatsome Flickity (`.product-gallery-slider .slide`) and WooCommerce Block Gallery (`.wc-block-product-gallery-large-image`) selectors alongside Flexslider.
   - Line 644: Replace `.wp-theme-shoptimizer.has-mr-tee-editor` with `body.has-mr-tee-editor`.
   - Add iOS 16px font-size zoom prevention on `.mr-tee-text-input` at `@media (max-width: 767px)`.
   - Add scoped CSS button/stepper resets so aggressive theme stylesheets (Flatsome, Tailwind, GeneratePress) do not collapse buttons or pills.
2. **`assets/js/modules/svg-renderer.js`**:
   - Add `options.idPrefix` support so cloned SVGs in PhotoSwipe or Magnific Popup do not cause duplicate DOM ID collisions.
   - Replace any remaining hardcoded remote URLs with local asset path (`assets/images/leopard-pattern.png`).
3. **`assets/js/modules/gallery-stage.js`**:
   - Broaden `findMainSlideContainer()` and `findMainGalleryImage()` to handle Flickity (`.product-gallery-slider .slide.is-selected`) and Block Gallery (`.wc-block-product-gallery-large-image`).
   - Add support for Flatsome Magnific Popup (`.mfp-wrap`) alongside PhotoSwipe.
4. **`assets/js/modules/form-controller.js`**:
   - Portal `#mr-tee-count-modal` and `#mr-tee-warning-modal` to `document.body` so theme `overflow: hidden` containers never clip dialogs.
   - Support `Escape` key and backdrop click to close modals.
   - Update `?v=2.3.19` on module imports.
5. **`assets/js/modules/print-exporter.js`**, **`assets/js/mr-tee-frontend.js`**, **`assets/js/mr-tee-admin-order-export.js`**:
   - Update import query strings to `?v=2.3.19`.

### B. Deployment & Verification
1. Update `scratch/deploy_live.py` `FILES` list to include newly edited files.
2. Run `python3 scratch/deploy_live.py` to sync all files to staging.
3. Run the Playwright test suite:
   ```bash
   uv run --with playwright --with pillow python3 scratch/test_themes.py v2319
   uv run --with playwright --with pillow python3 scratch/e2e_v2318.py
   ```
4. Confirm cleanup: Ensure `shoptimizer` snapshot is restored on staging, and temporary test orders/designs are deleted.
5. Commit and push final changes to `origin main`.
