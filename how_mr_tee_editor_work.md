# How Mr. Tee Editor Works — Complete Architectural & Operational Guide

**Plugin Name**: Mr. Tee Editor (`mr_tee_editor`)  
**Package / Namespace**: `MrTeeEditor`  
**Current Version**: `2.3.19`  
**Repository**: `rimkadiop/mr_tee_editor`  
**Target Environment**: WordPress 6.x+, WooCommerce 8.x+, PHP 7.4–8.3+  

---

## 1. What Is Mr. Tee Editor?

**Mr. Tee Editor** is a lightweight, ultra-fast visual personalization engine and WooCommerce plugin inspired by **Monsieur TSHIRT** (Product #24987 and related catalog items). It provides:

1. **3D OpenType-SVG Color Fonts & Typography**: Real-time rendering of bubblegum, 3D shadows, embroidery thread relief, and multi-color gradient vector fonts.
2. **Dynamic Mushroom Repeater**: Multi-item accordions (e.g. "Prénom 1", "Prénom 2", up to 6+ family members) with custom character limits and icon selectors.
3. **Customer Photo & Image Uploads**: Immediate in-browser preview with client-side image scaling and asynchronous chunked uploads.
4. **Instant Add-to-Cart & IP Protection**: Rapid cart redirection using a lightweight composite preview thumbnail (~30–45 KB JPEG), while strictly withholding 300 DPI print files and raw vector SVGs from the customer's browser.
5. **Deferred High-Resolution Admin Exporter**: Generates 300 DPI transparent print PNGs (2400×3000px) and vector SVGs automatically in the WordPress Admin order screen, synchronizing assets directly into **NBDesigner** (Net-Basics Online Designer).

---

## 2. High-Level Architecture & Data Flow

```
                                      ┌─────────────────────────────────────────────────────────┐
                                      │                    Product Admin                        │
                                      │  EngineSelectorMetabox / SceneGraphSchema Presets       │
                                      └──────────────────────────┬──────────────────────────────┘
                                                                 │
                                                       (Saves _mr_tee_config)
                                                                 ▼
┌───────────────────────────────────────────────────────────────────────────────────────────────┐
│                                   Single Product Page (Frontend)                              │
│                                                                                               │
│  ProductPageRenderer.php  ──► Inlines HTML inputs, default glyphs, & config in mrTeeProductData│
│                                                                                               │
│  [ES6 Client Orchestrator: mr-tee-frontend.js]                                                │
│  ├── StateStore.js            : Reactive state store for text, fonts, colors, and repeaters   │
│  ├── SceneGraphEngine.js      : Coordinate math, 2-row centering, auto-zoom, and bounding box │
│  ├── SvgRenderer.js           : Pure SVG DOM generation, OpenType-SVG glyphs, filters & masks │
│  ├── GalleryStage.js          : Pins SVG overlay onto WooCommerce gallery / Flickity / Blocks │
│  ├── FormController.js        : Inputs, char counters, count drawer modal, unedited warnings  │
│  └── PrintExporter.js         : Generates fast 360px JPEG mockup; handles chunked photo upload│
└───────────────────────────────────────────────┬───────────────────────────────────────────────┘
                                                │
                                    (Click: Add to Cart)
                                                ▼
┌───────────────────────────────────────────────────────────────────────────────────────────────┐
│                                 WooCommerce Cart & Checkout                                   │
│                                                                                               │
│  CartIntegration.php                                                                          │
│  ├── capture_cart_item_data() : Saves design payload & compact preview JPEG into cart item    │
│  ├── filter_add_to_cart_redirect() : Fast redirect to cart page                               │
│  ├── filter_cart_item_thumbnail() : Replaces blank mockup with single composite preview image │
│  ├── filter_store_api_cart_item_images() : Supports modern WooCommerce Cart Blocks           │
│  └── save_order_line_item_data() : Persists customization to order meta; syncs _nbd flag      │
└───────────────────────────────────────────────┬───────────────────────────────────────────────┘
                                                │
                                        (Customer Purchases)
                                                ▼
┌───────────────────────────────────────────────────────────────────────────────────────────────┐
│                                    WP Admin Order Management                                  │
│                                                                                               │
│  OrderAdminPanel.php          : HPOS & legacy CPT order metabox + item action buttons         │
│  mr-tee-admin-order-export.js : Automatically detects unrasterized orders (data-has-print="0")│
│                                 Generates 300 DPI PNG + vector SVG via headless client canvas │
│                                 Uploads artifacts & writes them to NBDesigner folder structure│
└───────────────────────────────────────────────────────────────────────────────────────────────┘
```

---

## 3. Directory Structure & Key Files

```
mr_tee_editor/
├── mr-tee-editor.php                  # Main entry point: version constant, HPOS declaration, plugin init
├── includes/
│   ├── Core/
│   │   └── Plugin.php                 # Core asset registration, admin script tags, hooks
│   ├── Domain/
│   │   └── SceneGraphSchema.php       # Declarative scene-graph schema, preset definitions, configs
│   ├── Frontend/
│   │   └── ProductPageRenderer.php    # Renders frontend inputs, modals, modulepreloads, Dashicon SVGs
│   ├── Commerce/
│   │   ├── CartIntegration.php        # Cart item capture, 1-image cart thumbnails, Blocks & checkout
│   │   └── OrderAdminPanel.php        # Admin order metabox, HPOS compatibility, item actions
│   ├── Admin/
│   │   ├── EngineSelectorMetabox.php  # Metabox to toggle between Mr. Tee Editor & NBDesigner
│   │   └── VisualPlacementStage.php   # Visual admin placement tool
│   ├── Export/
│   │   ├── PrintAssetGenerator.php    # Base64 file writer, filesystem helper
│   │   └── NBDesignerBridge.php       # Two-way sync with NBDesigner files (frame_0.png, config.json)
│   └── Registry/
│       ├── FontRegistry.php           # Catalog of TTF and OpenType-SVG fonts
│       ├── OpenTypeSvgParser.php      # Server-side SVG parser for OpenType-SVG fonts
│       ├── SvgFontPackager.php        # Generates lightweight client manifests (~20 KB vs 25 MB)
│       ├── ColorPaletteRegistry.php   # Embroidery threads, ink colors, and garment swatches
│       └── IconSetRegistry.php        # Clipart icons (e.g. mushrooms, badges)
├── assets/
│   ├── css/
│   │   ├── mr-tee-frontend.css        # Clean, Monsieur TSHIRT style: light-blue cards, pills, modals
│   │   └── mr-tee-admin.css           # Admin styling for metaboxes and placement stage
│   ├── js/
│   │   ├── mr-tee-frontend.js         # Frontend orchestrator bootstrap
│   │   ├── mr-tee-admin.js            # Admin metabox UI script
│   │   ├── mr-tee-admin-order-export.js# Deferred 300 DPI & SVG generator for admin order screen
│   │   └── modules/
│   │       ├── state-store.js         # Reactive store with subscription callbacks
│   │       ├── scene-graph-engine.js  # Math engine: 2-row layout, auto-centering, zoom
│   │       ├── svg-renderer.js        # Pure SVG scene-graph generator & OpenType-SVG glyph resolver
│   │       ├── gallery-stage.js       # Live overlay pinned to Woo gallery / PhotoSwipe / Flickity
│   │       ├── form-controller.js     # Input events, count stepper modal, unedited warnings
│   │       └── print-exporter.js      # Composite JPEG mockup rasterization & chunked uploads
│   ├── fonts/                         # Bundled local fonts and OpenType-SVG manifests
│   └── images/                        # Clipart icons, mushrooms, leopard textures
├── docs/                              # Architecture specifications & PDF connection guides
├── resume.md                          # Latest milestone progress and handoff notes
└── how_mr_tee_editor_work.md          # This file
```

---

## 4. Deep-Dive: Core Mechanisms

### 4.1. Fast Initial Page Load & Mobile 60 FPS Typing
- **Lightweight Manifests**: Instead of downloading entire 25 MB `.otf` color font files, `SvgFontPackager` compiles each OpenType-SVG font into a tiny JSON manifest (~20 KB) containing SVG templates and glyph advances.
- **Stage 1 (Critical First Paint)**: Only the glyph bitmaps required for the product's `default_text` are preloaded in `<head>` via `<link rel="preload">` and decoded immediately with `decoding="sync"`. The customized shirt appears with 0ms delay.
- **Stage 2 (Idle Background Warmup)**: In the background, `requestIdleCallback` batches the remaining alphabet (`A-Z`, `0-9`) into persistent browser RAM (`window.mrTeePrewarmedImages`).
- **DOM Reconciliation**: When the customer types, `GalleryStage.patchSvgInPlace()` updates the existing SVG DOM tree without re-creating nodes, avoiding image re-decoding flicker.

### 4.2. Intellectual Property (IP) Protection & Rapid Cart Redirection
- **The Problem**: Previous customizers generated high-resolution 300 DPI print files (5–10 MB) directly on the customer's mobile browser, causing 10–20 second checkout hangs and exposing print-ready vector files to theft.
- **The Solution**: 
  1. On **Add-to-Cart**, the frontend only generates a compressed, lightweight **360px JPEG thumbnail mockup** (~35 KB) of the shirt with the design.
  2. The customer is redirected to the cart immediately (1–2 seconds).
  3. No raw `/f/` glyphs, no vector SVGs, and no 300 DPI PNGs are ever created on the frontend or exposed to the customer.

### 4.3. Deferred High-Resolution Admin Rasterization & NBDesigner Sync
- When an order is placed, it is saved with `data-has-print="0"`.
- When a store manager or fulfillment operator opens the order in WooCommerce Admin (`/wp-admin/post.php?post=123&action=edit` or HPOS `admin.php?page=wc-orders&action=edit`):
  1. `mr-tee-admin-order-export.js` activates automatically.
  2. It renders the full 300 DPI transparent print PNG (2400×3000px) and vector SVG.
  3. It uploads them in safe 600 KB binary chunks via `mr_tee_upload_design_artifact` to `/wp-content/uploads/mr_tee_editor/designs/<key>/`.
  4. It calls `NBDesignerBridge` to create matching `frame_0.png` and `frame_0.svg` in `/wp-content/uploads/nbdesigner/designs/<nbd_key>/` so standard print shop fulfillment pipelines work without modification.
  5. The item flips to `data-has-print="1"` with download links for fulfillment.

### 4.4. Universal Theme & Plugin Compatibility (v2.3.19)
- **WooCommerce HPOS**: Declared compatible via `FeaturesUtil::declare_compatibility('custom_order_tables')`.
- **Cart & Checkout Blocks**: Declared compatible via `cart_checkout_blocks`. Store API item thumbnails are automatically replaced with the personalized preview.
- **Gallery Engine Detection**: Works with standard WooCommerce Flexslider, Flatsome Flickity, and Block Gallery (`.wc-block-product-gallery-large-image`).
- **No Dashicons Dependency**: All frontend icons (chevrons, warnings) use inline SVG vectors so themes that do not enqueue WordPress Dashicons display cleanly.
- **Read-Only Filesystem Resilience**: If `/wp-content/plugins/` is read-only, all font packaging and design caching falls back automatically to `/wp-content/uploads/mr_tee_editor/`.

---

## 5. What Does The Project Need To Work?

1. **WordPress & Server**:
   - PHP 7.4 to 8.3 with `gd` or `imagick`, `mbstring`, `json`, and `fileinfo` extensions.
   - Writable `/wp-content/uploads/` directory (standard WordPress permissions).
2. **WooCommerce**:
   - WooCommerce 7.0+ (supports both legacy Custom Post Type orders and modern HPOS).
3. **Optional (NBDesigner Integration)**:
   - If NBDesigner (`web-to-print-online-designer`) is installed, Mr. Tee Editor automatically detects it, suppresses duplicate frontend buttons, and populates NBDesigner order metadata and folder structures during admin order fulfillment.

---

## 6. How To Test & Deploy

The repository includes specialized diagnostic and deployment tools in the `scratch/` directory:

### Deploy Code to Staging:
```bash
python3 scratch/deploy_live.py
```
*Uploads modified files in chunks to the staging server and clears OPcache / WP object cache.*

### Run Playwright Multi-Theme Compatibility Test:
```bash
uv run --with playwright --with pillow python3 scratch/test_themes.py
```
*Switches between themes (Shoptimizer, Storefront, Flatsome, GeneratePress, TwentyTwentyFive, Edubin), tests responsiveness, Add-to-Cart speed, single-image cart thumbnails, and restores the original theme state.*

### Run Full End-to-End E2E Test Suite:
```bash
uv run --with playwright --with pillow python3 scratch/e2e_v2318.py
```
*Tests desktop PDP, font switching, photo upload, Cart redirect speed, Checkout review, order placement, customer email HTML, and WP Admin automatic 300 DPI generation.*
