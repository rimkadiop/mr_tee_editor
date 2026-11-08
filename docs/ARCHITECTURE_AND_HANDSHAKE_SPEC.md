# Complete Handshake Architecture: `mr_tee_editor` ⟷ NBDesigner Backend

This document details the verified **10-artifact handshake architecture** that connects the custom `mr_tee_editor` live preview customizer to the native NBDesigner (`web-to-print-online-designer` v2.8.5) backend without replacing NBDesigner.

---

## 1. Handshake Topology & Directory Contract

NBDesigner manages customized orders through disk-persisted folders indexed by a unique alphanumeric key (`{nbd_item_key}`). When an item is added to the cart or ordered via `mr_tee_editor`, the [`NBDesignerBridge`](file:///home/antigravity/rimkadiop/mr_tee_editor/includes/Export/NBDesignerBridge.php) creates the following structure at:

```
wp-content/uploads/nbdesigner/designs/{nbd_item_key}/
├── frame_0.png              <-- 300 DPI transparent raster (NBDesigner print generator side 0)
├── print_file_front.png     <-- Raster print alias
├── print_300dpi.png         <-- 300 DPI high-res alias
├── frame_0.svg              <-- Root vector SVG scene-graph (72 pt typography units)
├── design.svg               <-- Root SVG alias
├── design_preview.png       <-- Realistic garment mockup preview
├── config.json              <-- JSON canvas configuration (dpi: 300, unit: "cm", product sides)
├── product.json             <-- PHP serialized array of product sides
├── option.json              <-- PHP serialized array of dpi, unit, and layout options
├── used_font.json           <-- Safe fonts JSON registry (avoids TCPDF Google Fonts 404 aborts)
├── design.json              <-- Fabric/NBDesigner scene-graph layer schema (texts and images)
├── preview/
│   └── frame_0.png          <-- Preview thumbnail REQUIRED by WooCommerce Order metabox
└── svg/
    └── frame_0.svg          <-- Vector SVG REQUIRED by NBDesigner "Download SVG" ZIP exporter
```

---

## 2. The 10 Handshake Artifacts Specification

### Artifact 1: `frame_0.png` (High-Res 300 DPI Raster)
- **Role:** Primary raster file used by NBDesigner's TCPDF pipeline and ZIP image packager.
- **Critical Requirement:** In [`includes/class-util.php` line 2673](file:///home/antigravity/rimkadiop/woo_designer/includes/class-util.php#L2673), NBDesigner splits image filenames using `explode('_', $arr[0])[1]` to determine the product side index. The file **must be named `frame_0.png`** for side 0 (Front). Any other naming pattern results in an undefined side key and empty PDF export.

### Artifact 2 & 3: `frame_0.svg` and `svg/frame_0.svg` (Vector Scene-Graph)
- **Role:** Pure vector representation used for high-fidelity vector rendering and SVG download.
- **Critical Requirement:** In [`includes/class.nbdesigner.php` lines 1970–1975](file:///home/antigravity/rimkadiop/woo_designer/includes/class.nbdesigner.php#L1970-L1975), when an administrator clicks "Download SVG", NBDesigner scans the `svg/` subfolder:
  ```php
  $files = Nbdesigner_IO::get_list_files_by_type( $path . '/svg', 1, 'svg' );
  ```
  Pre-populating `svg/frame_0.svg` guarantees instant, lossless SVG packaging into `customer-design-{nbd_item_key}-svg.zip`.

### Artifact 4: `preview/frame_0.png` (Order Metabox Thumbnail)
- **Role:** Visual thumbnail in the WooCommerce Edit Order screen.
- **Critical Requirement:** In [`views/box-order-metadata.php` line 41](file:///home/antigravity/rimkadiop/woo_designer/views/box-order-metadata.php#L41), NBDesigner renders the Customer Design metabox thumbnail by executing:
  ```php
  $list_images = Nbdesigner_IO::get_list_images( NBDESIGNER_CUSTOMER_DIR . '/' . $nbd_item_key . '/preview', 1 );
  ```
  If `preview/` is missing or empty, `$list_images` is empty and the design thumbnail will NOT render. Placing the garment composite at `preview/frame_0.png` ensures immediate visual rendering.

### Artifact 5: `design.json` (Layer-by-Layer Resource Schema)
- **Role:** Populates the "Images" and "Texts" inspection tables in NBDesigner's "View detail" admin screen (`admin.php?page=nbdesigner_detail_order`).
- **Schema:**
  ```json
  {
    "frame_0": {
      "version": "3.4.0",
      "objects": [
        {
          "type": "i-text",
          "text": "SUPER",
          "fontFamily": "Super Mario Wii",
          "fontSize": 36,
          "ptFontSize": 27,
          "fill": "#e42616",
          "left": 100,
          "top": 50,
          "width": 240,
          "height": 50
        },
        {
          "type": "image",
          "src": "https://domain.com/.../mushroom_red.svg",
          "origin_url": "https://domain.com/.../mushroom_red.svg",
          "left": 60,
          "top": 190,
          "width": 50,
          "height": 50
        }
      ]
    }
  }
  ```

### Artifact 6 & 7: `product.json` and `option.json` (Serialized Side & DPI Settings)
- **Role:** Consumed by `nbd_export_pdfs()` and `nbdesigner_detail_order()`.
- **Format:**
  - `product.json`: `serialize( array( array( 'orientation_name' => 'Front', 'product_width' => 30, 'product_height' => 40, 'real_width' => 20, 'real_height' => 26, 'real_top' => 5, 'real_left' => 5, 'area_design_width' => 2400, 'area_design_height' => 3000, 'img_src' => '', 'bg_type' => 'color', 'bg_color_value' => '#ffffff', 'bleed_top_bottom' => 0, 'bleed_left_right' => 0, 'include_background' => 0 ) ) )`
  - `option.json`: `serialize( array( 'dpi' => 300, 'unit' => 'cm', 'layout' => 'm' ) )`

### Artifact 8: `config.json` (Canvas Configuration)
- **Role:** Configuration container specifying `$config->dpi` (300), `$config->unit` ("cm"), and the `$config->product` sides array.

### Artifact 9: `used_font.json` (Safe Font Registry)
- **Role:** In [`includes/class-util.php` line 2623](file:///home/antigravity/rimkadiop/woo_designer/includes/class-util.php#L2623), NBDesigner iterates through `$used_font` to call `nbd_download_google_font()`. Storing `[]` (empty JSON array) allows the TCPDF raster/vector engine to render standard embedded graphics without crashing on non-Google font download attempts.

### Artifact 10: `design_preview.png`, `print_file_front.png`, `print_300dpi.png` (Compatibility Aliases)
- **Role:** Root-level aliases for third-party ERP, direct download, and POS systems.

---

## 3. Order-Level and Item-Level Metadata Contract

To guarantee that NBDesigner's WooCommerce administration hooks fire properly:

### 1. Order-Level Postmeta (`_nbd = 1`)
In [`includes/class.nbdesigner.php` lines 2244 & 2256](file:///home/antigravity/rimkadiop/woo_designer/includes/class.nbdesigner.php#L2244):
```php
$has_design = get_post_meta( $order_id, '_nbd', true );
if ( $has_design || $has_upload ) {
    include_once( NBDESIGNER_PLUGIN_DIR . 'views/box-order-metadata.php' );
}
```
If `$has_design` is falsy, the Customer Design metabox is skipped completely.
In [`CartIntegration::save_order_line_item_data`](file:///home/antigravity/rimkadiop/mr_tee_editor/includes/Commerce/CartIntegration.php):
```php
if ( $order && is_a( $order, '\WC_Order' ) ) {
    $order->update_meta_data( '_nbd', '1' );
    update_post_meta( $order->get_id(), '_nbd', '1' );
    $order->save();
}
```
Setting both HPOS order meta and classic postmeta guarantees 100% compatibility across all WooCommerce storage engines.

### 2. Line Item Meta
Each customized line item receives:
- `_nbd`: `{nbd_item_key}` (The primary key linking to the designs folder)
- `_nbd_cart_item_key`: `{nbd_item_key}`
- `_nbdesigner_data`: Serialized array containing URLs for preview, svg, print_file, and config.

---

## 4. Verification & Testing Pipeline

To verify the handshake on any WooCommerce installation:
1. Check order item meta: `wp post meta get {order_id} _nbd` returns `1`.
2. Inspect the design directory: verify all 10 artifacts exist in `wp-content/uploads/nbdesigner/designs/{nbd_item_key}/`.
3. Test PDF generation directly:
   ```php
   $pdf_res = nbd_export_pdfs( '{nbd_item_key}', false, false, 'no' );
   ```
   Generates a 300 DPI vector PDF at `customer-pdfs/{nbd_item_key}_0.pdf`.
4. Open the WooCommerce Admin order: The `Customer Design` metabox renders the thumbnail with checkboxes, "Download all", and "View detail" link.
5. In "View detail", all text lines and repeater mushroom images appear in the resource summary tables ready for 1-click SVG/PNG/PDF download.
