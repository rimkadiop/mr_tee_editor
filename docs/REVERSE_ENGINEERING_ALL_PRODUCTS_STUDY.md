# REA Deep Forensic Analysis & Reverse-Engineering Study
## Analysis of 5 Diverse Monsieur T-shirt Personalization Engines

**Toolchain:** `morluto/rea` (v6.8.0) AST & Normalized Apollo Graph Reconstructor  
**Target Platform:** `monsieurtshirt.com`  
**Repository:** `mr_tee_editor` (WordPress / WooCommerce / NBDesigner Bridge)  

---

## 1. Executive Summary

While our initial implementation was benchmarked against the video game mushroom customizer (Product #24987), Monsieur TSHIRT uses a unified **Declarative Scene-Graph Engine** across its entire catalog (caps, tote bags, mugs, hoodies, t-shirts). 

Through AST decompilation and Apollo cache reconstruction of 5 production products, we uncovered that all personalization workflows are built on **6 fundamental primitives**:

```
                       ┌─────────────────────────┐
                       │  Scene-Graph Engine     │
                       └────────────┬────────────┘
                                    │
    ┌──────────────┬────────────────┼───────────────┬──────────────┐
    ▼              ▼                ▼               ▼              ▼
┌────────┐   ┌───────────┐   ┌────────────┐   ┌───────────┐  ┌───────────┐
│ TEXT   │   │ MASK      │   │ PATH       │   │ COLOR_SET │  │ ANCHORS   │
│ Single │   │ Texture / │   │ Vector     │   │ Cycle vs  │  │ Dynamic   │
│ Multi  │   │ Pattern   │   │ Clipart    │   │ Selector  │  │ Centering │
└────────┘   └───────────┘   └────────────┘   └───────────┘  └───────────┘
```

---

## 2. In-Depth Product Forensic Profiles

### Target 1: Casquette Papa de prénoms multicolore brodé
* **URL:** `https://www.monsieurtshirt.com/casquette/34225-papa-de-prenoms-multicolore-brode`
* **Product ID:** `34225` | **Visual ID:** `16044` | **Category:** Casquette (6 panneaux)
* **Design Anatomy:**
  * **Typography:** `Bebas Neue brodé` (`fontFamily: "BebasNeue"`, `fontSize: 20`, `lineHeight: 0.9`). All-caps condensed athletic typeface.
  * **Input Type:** Multi-line text (`maxLines: 4`, `maxCharsPerLine: 10`, default: `"PAPA\nDE\nJULES\n& LÉO"`).
  * **Coloration:** `COLOR_SET` in `CYCLE` mode across 10 embroidery thread hues (`#e1523d`, `#ebb042`, `#6aa6a3`, `#395c6b`, `#e6c1a6`, `#4d4038`, `#99b39a`, `#2f3e46`, `#a8dadc`, `#e63946`).
  * **Fabric Rendering:** Zero stroke, with a subtle embroidery thread depth filter.

### Target 2: Sac XXL Texte personnalisé léopard et prénom des enfants
* **URL:** `https://www.monsieurtshirt.com/sac-xxl/24000-texte-personnalise-leopard-et-prenom-des-enfants`
* **Product ID:** `24000` | **Visual ID:** `5812` | **Category:** Sac XXL
* **Design Anatomy:**
  * **Texture Cutout:** **SVG Clipping Mask (`type: "MASK"`)**.
    * Contains a background high-resolution leopard print `<image>` tile.
    * A `<mask id="...">` enclosing the user's custom word (`"MAMAN"`, `"MAMIE"`).
    * Font: `Gunterz` (heavy ultra-bold geometric block display font).
  * **Children's Subtitle:**
    * Script text (`"Léo, Chloé, Arthur"`) in `Adédé` / `Villagers` font.
    * Color: Terra-cotta brown (`#934a32`).
    * Dynamically anchored below the leopard mask container.

### Target 3: Casquette Mum of brodé coloré
* **URL:** `https://www.monsieurtshirt.com/casquette/34835-mum-of-brode-colore`
* **Product ID:** `34835` | **Visual ID:** `15712` | **Category:** Casquette
* **Design Anatomy:**
  * **Typography:** `Bebas Neue brodé` (`fontSize: 24`, `lineHeight: 0.85`).
  * **Layout:** 3-line vertical stack (`"MUM\nOF\nTWO"`).
  * **Coloration:** Pastel embroidery thread cycling per letter or per line.

### Target 4: Casquette Maman depuis date et prénom des enfants brodé
* **URL:** `https://www.monsieurtshirt.com/casquette/34359-maman-depuis-date-et-prenom-des-enfants-brode`
* **Product ID:** `34359` | **Visual ID:** `15126` | **Category:** Casquette
* **Design Anatomy:**
  * **3-Tier Hierarchy:**
    1. Title: `"Maman"` in `Adédé` script (`fontSize: 32`).
    2. Subtitle: `"depuis 2021"` in `Hanley brodé` (`fontSize: 12`).
    3. Names: `"Léa & Tom"` in `Villagers` font (`fontSize: 16`).
  * **Color Swatch Selector:** `COLOR_SET` in `SELECTOR` mode. Customer selects from 8 embroidery thread colors (Blanc, Noir, Bleu marine, Vert, Marron, Rouge, Orange, Rose pâle). All 3 lines inherit the selected thread color in real time.

### Target 5: Mug Maman depuis date et prénom des enfants
* **URL:** `https://www.monsieurtshirt.com/mug/34455-maman-depuis-date-et-prenom-des-enfants`
* **Product ID:** `34455` | **Visual ID:** `15221` | **Category:** Mug Céramique
* **Design Anatomy:**
  * **Composition:**
    * Headline `"maman"` (`Adédé`, `fontSize: 34`).
    * Subline `"depuis 2022"` (`Hanley brodé`, `fontSize: 11.9`).
    * Clipart: Vector Heart icon (`type: "PATH"`, `name: "Coeur"`).
    * Children Names: Vertical multi-line list (`"Oscar\nAnna\nGaby"`, `maxLines: 5`, `Villagers`, `fontSize: 18`, `lineHeight: 0.8`).
  * **Color Palette Selector:** 7 ceramic ink colors (`#000000`, `#08086d`, `#045018`, `#762c14`, `#c20909`, `#ff9656`, `#ffe5d3`). Both text and the heart clipart update synchronously.

---

## 3. Comparison Matrix Across All 5 Targets

| Feature | #24987 (Mario) | #34225 (Papa Cap) | #24000 (Leopard Bag) | #34835 (Mum Cap) | #34359 (Maman Cap) | #34455 (Mug) |
| :--- | :--- | :--- | :--- | :--- | :--- | :--- |
| **Product Type** | T-Shirt | Casquette | Sac XXL | Casquette | Casquette | Mug |
| **Headline Font** | SuperMario256 | Bebas Neue | Gunterz (Masked) | Bebas Neue | Adédé | Adédé |
| **Secondary Font** | SuperMario256 | N/A | Villagers | N/A | Hanley brodé | Hanley brodé |
| **Names Font** | SuperMario256 | N/A | Adédé | N/A | Villagers | Villagers |
| **Fill Style** | Multi-color cycle | Thread cycle | Leopard Texture | Thread cycle | Single thread pick | Single ink pick |
| **Masking** | No | No | **Yes (SVG Mask)** | No | No | No |
| **Clipart Vector** | PNG/SVG icons | None | None | None | None | **SVG Path (Heart)** |
| **Multi-line Text** | No (2 fields) | **Yes (4 lines)** | **Yes (3 lines)** | **Yes (3 lines)** | Single line | **Yes (5 lines)** |
| **Color Selector** | Pre-set palette | Pre-set palette | Fixed | Pre-set palette | **8 Swatches** | **7 Swatches** |
| **Repeater Grid** | 2-row avatars | None | None | None | None | None |

---

## 4. Architectural Enhancements for `mr_tee_editor`

To elevate `mr_tee_editor` from a single-product customizer into a **universal product personalization engine**, we implement the following modular enhancements:

1. **`SvgRenderer` Primitive Expansion:**
   * Support `type: 'MASK'` for pattern textures (Leopard, Camo, Floral, Glitter).
   * Support `type: 'PATH'` for vector cliparts (Hearts, Stars, Dividers).
   * Support `multiLine: true` text rendering with `<tspan x="..." dy="...">`.
   * Support `casing: 'ORIGINAL' | 'UPPER'`.
   * Add `#mrtee-embroidery-relief` SVG filter for realistic thread texture.
2. **`ColorPaletteRegistry` Additions:**
   * `embroidery_multicolor`: 10-color pastel/pop embroidery thread cycle.
   * `embroidery_threads`: 8-color thread selector swatches.
3. **`FontRegistry` Expansion:**
   * `Bebas Neue brodé`, `Adédé`, `Hanley brodé`, `Villagers`, `Gunterz`.
4. **`SceneGraphSchema` Product Presets:**
   * `mario_mushrooms` (Product #24987)
   * `multicolor_embroidered` (Product #34225, #34835)
   * `leopard_pattern_mask` (Product #24000)
   * `vintage_date_names` (Product #34359, #34455)
5. **Frontend Form Controls (`FormController` & `ProductPageRenderer`):**
   * Multi-line `<textarea>` inputs for name lists.
   * Interactive color swatch pills (`mr-tee-color-swatch-pill`).
