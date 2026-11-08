# Forensic Analysis: Monsieur TSHIRT Form-Driven Customizer

> **Target Analyzed**: `https://www.monsieurtshirt.com/t-shirt-homme/24987-super-prenom-jeu-video-et-ses-champignons?color=blanc&style=col-rond&size=m`  
> **Methodology**: Evidence-First Reverse Engineering (inspired by `morluto/rea`).

---

## 1. REA Deconstruction & Evidence Ledger

Following the REA reverse engineering principles, we separate observed facts from structural derivations and architectural inferences:

| Category | Finding / Specification |
| :--- | :--- |
| **Observation** | The product page features a dual-column layout: Left column = Interactive Live Mockup Preview; Right column = Structured Customization Form + E-commerce Attributes (Size, Color, Add to Cart). |
| **Observation** | There are NO canvas manipulation handles (no bounding boxes, rotate gizmos, layer reordering tabs, or font-size sliders). The customer interacts purely via form inputs. |
| **Observation** | The design comprises two distinct visual tiers:<br>1. **Headline Block**: "Super [Nom/Prénom]" rendered in a 3D retro gaming/arcade typography.<br>2. **Dynamic Repeater Row**: 0 to 6 mushroom avatars, each paired with an individual child's name directly underneath. |
| **Observation** | Modifying any input field (typing a name, altering the mushroom count, selecting a different mushroom icon) updates the chest artwork **instantly in real time** without page reload or visible lag. |
| **Observation** | Selecting a different garment color (Blanc, Noir, Gris chiné, Bordeaux, Marine) swaps the underlying t-shirt mockup image while preserving the exact personalized artwork overlay. |
| **Derivation** | The rendering engine utilizes a reactive vector/DOM overlay (SVG or GPU-accelerated Canvas) calibrated to a fixed normalized chest bounding box over the apparel mockup. |
| **Derivation** | The horizontal distribution of the repeater items follows an automated dynamic reflow formula: as $N$ changes, item coordinates and font sizes auto-scale to maintain visual balance and prevent chest overflow. |
| **Inference** | When the customer clicks "Ajouter au panier", the frontend bundles both the human-readable customization choices (for packing slips and order emails) and the high-resolution vector/raster print assets (for atelier DTG / sublimation printing). |

---

## 2. Anatomy of the Customizer UI & UX

```
┌──────────────────────────────────────┬──────────────────────────────────────────┐
│         LIVE PREVIEW STAGE           │            CUSTOMIZATION FORM            │
├──────────────────────────────────────┼──────────────────────────────────────────┤
│                                      │  1. Apparel Attributes                   │
│   ┌──────────────────────────────┐   │     - Size Selector (XS, S, M, L, XL...) │
│   │                              │   │     - Color Swatches (Blanc, Noir...)    │
│   │         [ T-SHIRT ]          │   │                                          │
│   │      ┌────────────────┐      │   │  2. Headline Personalization             │
│   │      │ S u p e r      │      │   │     - Field: "Modifier le nom"           │
│   │      │    P A P A     │      │   │     - Counter: [ 4 / 10 caractères ]     │
│   │      │                │      │   │                                          │
│   │      │ 🍄   🍄   🍄   │      │   │  3. Repeater: "Nombre de champignons"    │
│   │      │Emma Oscar Felix│      │   │     - Counter: [ - ] 3 [ + ] (min 0, max 6)│
│   │      └────────────────┘      │   │                                          │
│   │                              │   │  4. Dynamic Character Rows              │
│   │                              │   │     - Item 1: [Icon Picker] [Nom: Emma]  │
│   │                              │   │     - Item 2: [Icon Picker] [Nom: Oscar] │
│   │                              │   │     - Item 3: [Icon Picker] [Nom: Felix] │
│   └──────────────────────────────┘   │                                          │
│                                      │  [ AJOUTER AU PANIER - 33,21 € ]         │
└──────────────────────────────────────┴──────────────────────────────────────────┘
```

---

## 3. The Reactive Layout & Typography Engine

### 3.1 The Fancy 3D Retro Font Engine
The headline ("SUPER PAPA") requires a distinct visual treatment that cannot be achieved with standard flat text:
1. **Multi-Pass SVG / Canvas Rendering**:
   - **Base Shadow**: A solid offset shadow simulating depth.
   - **Extrusion / 3D Block**: Stepped layers or SVG bevel filter along the $(X, Y)$ angle vector.
   - **Stroke Border**: High-contrast black outline (`stroke-linejoin: round`, `stroke-width: 8px-12px`).
   - **Front Fill**: Vibrant retro color gradient or solid white fill.
2. **Dynamic Text Scaling (Auto-Fit)**:
   - When the user enters a longer name (e.g. "GRAND-PÈRE" vs "PAPA"), the engine computes the bounding box text width:
     $$\text{Scale Factor} = \min\left(1.0, \frac{\text{Max Allowed Width}}{\text{Measured Text Width}}\right)$$
   - This prevents long names from clipping outside the printable chest boundary.

### 3.2 Dynamic Item Reflow (The Mushroom Layout Math)
For $N$ items ($0 \le N \le 6$):
- **When $N = 0$**: The headline automatically centers vertically in the printable area.
- **When $1 \le N \le 6$**:
  - The total printable width is defined as $W_{\text{zone}}$.
  - The available width per item is $w_{\text{slot}} = \frac{W_{\text{zone}}}{N}$.
  - The center position for item $i$ (0-indexed) is:
    $$X_i = \left(i + 0.5\right) \times w_{\text{slot}}$$
  - Each item contains:
    1. **Avatar Icon**: Rendered at $(X_i - \frac{w_{\text{icon}}}{2}, Y_{\text{icon}})$.
    2. **Child Name**: Rendered at $(X_i, Y_{\text{text}})$ with `text-anchor: middle`. If the child's name exceeds $w_{\text{slot}}$, the font size for that individual label is scaled down dynamically.

---

## 4. State Management Contract

The entire editor UI is driven by a single normalized reactive state object:

```typescript
interface MonsieurTeeState {
  productId: number;
  variationId: number;
  selectedColor: string;       // e.g. "blanc"
  selectedSize: string;        // e.g. "m"
  selectedStyle: string;       // e.g. "col-rond"
  
  // Customization Payload
  headline: {
    prefix: string;            // e.g. "Super" (fixed or editable)
    name: string;              // e.g. "Papa" (max 10 chars)
  };
  
  repeater: {
    count: number;             // 0 to 6
    items: Array<{
      id: string;              // unique UUID
      iconId: string;          // e.g. "mushroom_red", "mushroom_green", "mushroom_blue"
      name: string;            // e.g. "Emma" (max 9 chars)
    }>;
  };
}
```

---

## 5. Add-to-Cart & Print Serialization Strategy

When the customer submits the form:
1. **Human-Readable Cart Meta**: Formatted into WooCommerce line item metadata so store operators and customers see:
   - *Titre personnalisé*: "Super Papa"
   - *Nombre d'enfants*: "3"
   - *Enfants*: "Emma (Rouge), Oscar (Vert), Felix (Bleu)"
2. **Production Print Export**:
   - The SVG engine exports a clean, standalone vector SVG file ($72\text{ pt/in}$) with all text converted to paths or using embedded TrueType fonts.
   - The engine generates a transparent 300 DPI high-resolution PNG.
   - Both assets are handed over to the NBDesigner backend endpoints (`nbd_save_customer_design`) as documented in our master specification.
