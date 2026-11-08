# Deep Forensic Reverse Engineering: Real-Time Fancy Font & Live Product Rendering Engine

> **Investigation Target**: `https://www.monsieurtshirt.com/t-shirt-homme/24987-super-prenom-jeu-video-et-ses-champignons`  
> **Source Evidence Analyzed**:
> 1. Attached User Screenshots (`Screenshot_20261107-185415_Chrome.jpg` & `Screenshot_20261107-185446_Chrome.jpg`).
> 2. Live Apollo GraphQL State extracted from DOM (`461,995 bytes`).
> 3. Decompiled Webpack Bundles (`product_gql.js`, `chunk2951.js`, `chunk4972.js`, `super-mario-wii.css`, and `cocogoose-caps/info.json`).

---

## 1. Executive Answer: How It Works Under the Hood

The live personalization on Monsieur TSHIRT operates through a **pure client-side reactive SVG vector pipeline** coupled to a **declarative Apollo JSON layer schema**. There are **no server requests made while typing**.

```
┌────────────────────────────────────────────────────────────────────────┐
│                      1. Form Input Layer (DOM)                         │
│  - User types "Medam" in input field "Modifier le nom" (5 / 10 chars)  │
│  - Input dispatches onChange(ref.id, "Medam")                          │
└───────────────────────────────────┬────────────────────────────────────┘
                                    │ Instant in-memory state update
                                    ▼
┌────────────────────────────────────────────────────────────────────────┐
│                      2. Reactive State Model                           │
│  - Updates reference `id-ref-QBYwxupzDSn6n5QugEMqA`.value = "Medam"    │
│  - Computes `colorizeConfiguration` (Letter 0=Red, 1=Green, 2=Yellow...)│
└───────────────────────────────────┬────────────────────────────────────┘
                                    │ Triggers 60 FPS SVG re-render
                                    ▼
┌────────────────────────────────────────────────────────────────────────┐
│                   3. Vector Rendering Stage (SVG)                      │
│  - Garment Base Image: <image href="..." /> (changes with color swatch)│
│  - Print Viewport: <svg viewBox="0 0 240 240" class="previewSvg">      │
│    • Font: @font-face "Super Mario Wii" loaded via CSS                 │
│    • 3D Bevel Filter: <feDiffuseLighting> + <feSpecularLighting>       │
│    • Outer Stroke: stroke="#000000" stroke-width="3"                   │
│      paint-order="stroke fill" (prevents stroke from eating text)      │
│    • Multi-Color Fill: per-letter <tspan> or stepped <linearGradient>  │
│    • Mushrooms: Dynamic visibility toggle & auto-centered spacing      │
└───────────────────────────────────┬────────────────────────────────────┘
                                    │ Result
                                    ▼
       Flawless, instant, sharp 3D gaming badge on the t-shirt chest!
```

---

## 2. Deconstructing the Fancy 3D Font Engine

### 2.1 The Actual Font Files
In the extracted Apollo GraphQL state, the product uses two distinct fonts:
1. **Headline Font ("SUPER" & Custom Name "MEDAM")**:
   - **Font Name**: `"Super Mario Wii"`
   - **CSS URL**: `https://cdn.monsieurtshirt.com/perso/fonts/super-mario-wii.css`
   - **TTF Source**: `https://cdn.monsieurtshirt.com/perso/fonts/super-mario-wii.ttf`
   - Registered in the browser via standard CSS:
     ```css
     @font-face {
       font-family: "Super Mario Wii";
       src: url("https://cdn.monsieurtshirt.com/perso/fonts/super-mario-wii.ttf") format("truetype");
     }
     ```
2. **Sub-item Font (Child Names under Mushrooms: "EMMA", "OSCAR", "FELIX")**:
   - **Font Name**: `"COCOGOOSE CAPS"`
   - **Font Family**: Geometric Sans-Serif bold uppercase.
   - **Metrics URL**: `https://cdn.monsieurtshirt.com/fonts/cocogoose-caps/info.json`

### 2.2 Pre-Computed Kerning & Glyph Metrics (`info.json`)
Monsieur TSHIRT downloads an `info.json` companion file for each font. This JSON contains:
- `lsb` (Left Side Bearing)
- `rsb` (Right Side Bearing)
- `r` (Aspect ratio width/height per glyph)
- `kerns` (Exact kerning pairs for character combinations like `AV`, `PA`, `ED`)

**Why this is done**:
By calculating character positions from the kerning table mathematically in JavaScript, the text never "flickers" or shifts awkwardly while the user types, and the bounding box (`bbox`) is calculated with 100% mathematical precision before the browser finishes font rasterization.

---

## 3. How the Multi-Color Per-Letter Effect Works (`COLOR_SET`)

In `Screenshot_20261107-185415_Chrome.jpg`, notice:
- In **"SUPER"**: `S` is Blue, `U` is Yellow, `P` is Red, `E` is Green, `R` is Yellow.
- In **"MEDAM"**: `M` is Red, `E` is Green, `D` is Yellow, `A` is Blue, `M` is Yellow.

In the decompiled `product_gql.js` bundle, this is managed by the `COLOR_SET` engine with `colorizeConfiguration`:

```json
{
  "colorizeConfiguration": {
    "variationType": "LETTER",
    "variationFrequency": 1,
    "items": [
      { "hex": "#e42616", "name": "Red" },
      { "hex": "#4eae3e", "name": "Green" },
      { "hex": "#feca17", "name": "Yellow" },
      { "hex": "#0f99d6", "name": "Blue" },
      { "hex": "#feca17", "name": "Yellow" }
    ]
  }
}
```

### The Letter Coloring Algorithm:
When rendering the word `"MEDAM"` (length = 5):
$$\text{Color Index for Character } i = i \pmod{\text{Palette Length}}$$
- Character 0 (`M`): Palette[0] $\rightarrow$ `#e42616` (Red)
- Character 1 (`E`): Palette[1] $\rightarrow$ `#4eae3e` (Green)
- Character 2 (`D`): Palette[2] $\rightarrow$ `#feca17` (Yellow)
- Character 3 (`A`): Palette[3] $\rightarrow$ `#0f99d6` (Blue)
- Character 4 (`M`): Palette[4] $\rightarrow$ `#feca17` (Yellow)

In the SVG, this is rendered as:
```xml
<text x="50" y="30" font-family="Super Mario Wii" font-size="20" text-anchor="middle"
      stroke="#000000" stroke-width="3" paint-order="stroke fill">
  <tspan fill="#e42616">M</tspan>
  <tspan fill="#4eae3e">E</tspan>
  <tspan fill="#feca17">D</tspan>
  <tspan fill="#0f99d6">A</tspan>
  <tspan fill="#feca17">M</tspan>
</text>
```

---

## 4. The 3D Bevel & Contrast Stroke Technique

Notice that the Mario letters have a bold black cartoon outline that **does not shrink or distort the letters**.

### 4.1 The `paint-order="stroke fill"` Secret
In default SVG, `stroke` is centered on the glyph boundary. A stroke width of 3px will expand 1.5px outward and 1.5px inward, destroying thin character counters (like inside the `e`, `a`, or `p`).

Monsieur TSHIRT solves this using:
```css
paint-order: stroke fill;
stroke-linejoin: round;
stroke-linecap: round;
stroke-width: 3px;
stroke: #000000;
```
Because the `stroke` is painted **first** and the `fill` is painted **on top**, the entire inward 1.5px stroke is covered by the colored letter face! Only the outward 1.5px stroke remains visible, creating a **flawless outside-only sticker border**.

### 4.2 The SVG 3D Lighting Filter
In module `12866` of `chunk2951.js`, the 3D surface depth is produced by an SVG lighting filter:
```xml
<filter id="mario-3d-filter" x="-20%" y="-20%" width="140%" height="140%" style="color-interpolation-filters: sRGB">
  <!-- 1. Height map from alpha channel -->
  <feGaussianBlur in="SourceAlpha" stdDeviation="0.8" result="hmap" />
  
  <!-- 2. Diffuse 3D lighting with directional distant light -->
  <feDiffuseLighting in="hmap" surfaceScale="1.5" diffuseConstant="0.65" result="diff">
    <feDistantLight azimuth="255" elevation="75" />
  </feDiffuseLighting>
  
  <!-- 3. Multiply shaded light with graphic -->
  <feComposite in="diff" in2="SourceGraphic" operator="arithmetic" k1="0.62" k2="0" k3="0.38" k4="0" result="shaded" />
  
  <!-- 4. Specular shiny highlight -->
  <feSpecularLighting in="hmap" surfaceScale="1.5" specularConstant="0.35" specularExponent="3" result="spec">
    <feDistantLight azimuth="255" elevation="75" />
  </feSpecularLighting>
  
  <!-- 5. Merge shadow stroke + 3D shaded face + specular highlight -->
  <feMerge>
    <feMergeNode in="stroke" />
    <feMergeNode in="shaded" />
    <feMergeNode in="spec" />
  </feMerge>
</filter>
```

---

## 5. The Dynamic Mushroom Repeater ("Nombre de champignons")

In `Screenshot_20261107-185446_Chrome.jpg`, the form shows:
- Button: `"Nombre de champignons"`
- In `apollo_state.json`:
  ```json
  "perso_visual_cfg": {
    "title": "Nombre de champignons",
    "sections": [{
      "items": [
        { "visibility_ref_id": "id-ref-63xuw8z6yMyd4wzQC-5qt", "group_ref_id": "id-ref-tQGdRUiVnKM4xfUa3lkq-" },
        { "visibility_ref_id": "id-ref-7ZP7faXdNtiKe-N0AG-6o", "group_ref_id": "id-ref-jbLU32iYBhrc8o_TPNebN" },
        { "visibility_ref_id": "id-ref-sBIONcd--lxFAqLMorJnr", "group_ref_id": "id-ref-54qQ6MjPeDaIZcrzKPUvh" },
        { "visibility_ref_id": "id-ref-jtY61BnJaxrALo11umrrc", "group_ref_id": "id-ref-cIJuqaD1qdGHXvxNSi4Zq" },
        { "visibility_ref_id": "id-ref-xV3E5T9M2pKaw_0AHQeac", "group_ref_id": "id-ref-_wxLbO1AJ76m2cEtGB_pq" },
        { "visibility_ref_id": "id-ref-n2jTr958_LqzDqjJ4Aidz", "group_ref_id": "id-ref-UZFfuK4uCffOL-fRTxfIU" }
      ]
    }]
  }
  ```

### How Selecting "3 Champignons" Works:
1. The user picks `3`.
2. The code in `chunk4972.js` slices the section items:
   ```javascript
   const activeItems = section.items.slice(0, 3);   // items 0, 1, 2
   const hiddenItems = section.items.slice(3, 6);   // items 3, 4, 5
   
   activeItems.forEach(item => setVisibility(item.visibility_ref_id, true));
   hiddenItems.forEach(item => setVisibility(item.visibility_ref_id, false));
   ```
3. Each group has pre-defined coordinates or uses dynamic auto-centering:
   - Mushroom 1 (Emma): Active $\rightarrow$ Shows Red Mushroom (`id_perso_img_set: 1790`) + text `"Emma"`
   - Mushroom 2 (Oscar): Active $\rightarrow$ Shows Green Mushroom + text `"Oscar"`
   - Mushroom 3 (Felix): Active $\rightarrow$ Shows Blue Mushroom + text `"Felix"`
   - Mushrooms 4, 5, 6: Inactive $\rightarrow$ Hidden (`visible: false`)

---

## 6. How to Clone This Exactly in `mr_tee_editor`

To reproduce this 1:1 on your website:

1. **Lightweight SVG Stage**:
   Create a `<div class="mr-tee-mockup-wrapper">` containing:
   - Garment Base Image (`<img>`) that switches with color swatches (`Blanc`, `Gris`, `Noir`, etc.).
   - Responsive overlay `<svg viewBox="0 0 100 100" class="mr-tee-print-zone">` absolutely positioned over the left chest print zone.
2. **Font Loading**:
   Load `super-mario-wii.ttf` and `cocogoose-caps.ttf` via `@font-face`.
3. **Reactive State**:
   A simple 100-line vanilla JS or Alpine/React state object:
   - `headline`: `"Medam"`
   - `mushrooms`: `[{ icon: 'red', name: 'Emma' }, { icon: 'green', name: 'Oscar' }, { icon: 'blue', name: 'Felix' }]`
4. **Per-Letter Color Function**:
   Cycle the 5 retro colors `#e42616`, `#4eae3e`, `#feca17`, `#0f99d6`, `#feca17` across each letter.
5. **Outer Stroke**:
   Apply `stroke="#000000" stroke-width="3" paint-order="stroke fill" stroke-linejoin="round"`.
6. **Handshake on Add to Cart**:
   Convert the SVG to 300 DPI PNG (`frame_0.png`) and point-based SVG (`frame_0_svg.svg`) and post to NBDesigner's `nbd_save_customer_design` endpoint!
