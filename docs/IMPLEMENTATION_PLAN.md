# Implementation Plan: `mr_tee_editor` WordPress Plugin

> **Objective**: Build a high-converting, form-driven live product customizer inspired by Monsieur T-shirt that handshakes natively with WooCommerce and NBDesigner's backend print engine.

---

## 🗺️ Roadmap & Phase Breakdown

```
Phase 1: Architecture & Data Modeling (Admin Schema)
  │
  ▼
Phase 2: Frontend Reactive UI & Typography Engine
  │
  ▼
Phase 3: NBDesigner Backend Handshake Adapter
  │
  ▼
Phase 4: WooCommerce Cart, Checkout & Order Line Binding
  │
  ▼
Phase 5: Quality Assurance, Rigorous Testing & Production Deployment
```

---

## Phase 1: Architecture & Data Modeling

### 1.1 Product Template Configuration (WP Admin)
We need an intuitive way for the store admin to configure a product as a "Mr. Tee Form Customizer":
- **Product Type Meta**: Checkbox or select box on the Product Edit screen:
  - `_custom_editor_engine`: `mr_tee_editor`
- **Template Configuration Schema**:
  - **Headline Settings**:
    - Label (e.g. "Modifier le titre / nom")
    - Default Value (e.g. "PAPA")
    - Max Character Limit (e.g. 10)
    - Font Family & Style (e.g. "SuperMario3D", "RetroArcade", "Varsity")
    - Text Effects (3D Bevel, Drop Shadow, Stroke Color, Fill Color)
  - **Repeater Settings (Sub-items / Avatars)**:
    - Enabled (Yes / No)
    - Item Name Singular/Plural (e.g. "Champignon / Champignons", "Enfant / Enfants")
    - Min Items (0) & Max Items (6)
    - Default Count (3)
    - Available Icon Library (e.g. Mario Red Mushroom, Green 1-Up Mushroom, Blue Mushroom, Yellow Star, Fire Flower)
    - Sub-label Character Limit (e.g. 9 chars)

---

## Phase 2: Frontend Reactive UI & Typography Engine

### 2.1 UI Layout on Single Product Page
- Replaces or enhances the standard WooCommerce product page layout:
  - **Left Gallery Column**: Becomes the interactive live stage.
    - Base garment mockup image (dynamically changes with color swatch selection).
    - Positioned SVG overlay mapped to the chest print zone.
  - **Right Column**: The structured form.
    - Size buttons (XS, S, M, L, XL, XXL).
    - Color swatch selector.
    - Headline input with real-time character counter.
    - Number stepper for repeater items (`[-] 3 [+]`).
    - Dynamic row inputs for each child/character (Icon Picker + Name input).
    - Sticky "Ajouter au panier" button with live price.

### 2.2 SVG Typography & Dynamic Reflow Math
- Multi-layer SVG `<text>` elements for 3D extrusion, stroke, and gradient/color fill.
- Real-time text measurement using `getBBox()` to dynamically auto-scale font size if a long name is typed.
- Centered grid reflow math for the avatar row:
  $$X_i = \left(i + 0.5\right) \times \left(\frac{W_{\text{zone}}}{N}\right)$$

---

## Phase 3: NBDesigner Backend Handshake Adapter

### 3.1 Client-Side Export Pipeline
Upon clicking "Ajouter au panier":
1. **Raster 300 DPI PNG**: Render SVG into an offscreen `<canvas>` at $300\text{ DPI}$ physical pixel resolution $\rightarrow$ export `frame_0.png` Blob.
2. **Vector SVG in Points**: Generate root SVG with $W_{\text{pt}} = (\text{width\_cm} / 2.54) \times 72$ for TCPDF $\rightarrow$ export `frame_0_svg.svg` Blob.
3. **JSON Manifests**:
   - `design.json`: Formatted to match NBDesigner layer inspector in WooCommerce Orders.
   - `config.json`: Physical print metrics (`dpi: 300`, `unit: 'cm'`).
4. **Multipart POST**: Dispatched to `nbdConfig.ajax_url` with action `nbd_save_customer_design`.
5. **Server Composite**: NBDesigner automatically merges the transparent artwork with the t-shirt base mockup and wrinkle overlay into `preview/frame_0.png`.

---

## Phase 4: WooCommerce Cart & Order Binding

### 4.1 Line Item Metadata Persistence
- Hooks into `woocommerce_add_cart_item_data`:
  - Attaches `_nbd` session key.
  - Attaches human-readable item attributes:
    - `Titre personnalisé`: "Super Papa"
    - `Nombre d'éléments`: 3
    - `Détails`: "Emma (Rouge), Oscar (Vert), Felix (Bleu)"
- Hooks into `woocommerce_checkout_create_order_line_item`:
  - Saves all attributes permanently to the order.
  - Appears cleanly on the customer receipt, invoice, and WooCommerce admin order view.

---

## Phase 5: Testing & Verification Protocol

1. **Visual Testing**: Verify across screen sizes (mobile, tablet, desktop) and ensure 60 FPS typing responsiveness.
2. **Coexistence Testing**: Verify that standard NBDesigner products and non-customized WooCommerce products continue to function normally without conflicts.
3. **Print Integrity Testing**:
   - Download the 300 DPI Vector PDF and Print PNG from WooCommerce admin orders.
   - Verify exact physical scale and font crispness.
