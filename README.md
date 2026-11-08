# Mr. Tee Editor (`mr_tee_editor`)

> **High-Converting, Form-Driven Live Product Customizer for WooCommerce & NBDesigner Backend**  
> Inspired by [Monsieur TSHIRT](https://www.monsieurtshirt.com/) UX architecture.

---

## 🎯 Executive Vision

Traditional web-to-print product customizers (e.g. native NBDesigner, Canva-style canvas tools) require customers to manually manipulate bounding boxes, scale handles, rotate stickers, and arrange layers. While powerful for blank-canvas design, this creates high friction and cart abandonment for gift products, humor t-shirts, and family merchandise.

**Mr. Tee Editor** (`mr_tee_editor`) introduces the proven **Monsieur TSHIRT UX paradigm**:
1. **Zero Canvas Friction**: Customers do not drag or resize anything.
2. **Form-Driven Customization**: Customers simply type names in text fields, select counters (e.g. number of children/characters), and pick avatar swatches.
3. **Instant Live Preview**: The t-shirt mockup updates in real time with fancy retro/gaming 3D fonts, dynamic item reflow, and color synchronization.
4. **100% NBDesigner Backend Handshake**: Leverages NBDesigner for composite mockup generation, WooCommerce cart persistence, and 300 DPI print-ready vector PDF/SVG exports for store administrators.

---

## 📂 Repository Structure

```
mr_tee_editor/
├── README.md                                # Project overview and quickstart
├── docs/
│   ├── MONSIEUR_TSHIRT_REVERSE_ENGINEERING.md # Forensic analysis of Monsieur T-shirt UX & tech
│   ├── ARCHITECTURE_AND_HANDSHAKE_SPEC.md     # Technical handshake with NBDesigner & WooCommerce
│   └── IMPLEMENTATION_PLAN.md                 # Phased engineering roadmap and decision matrix
└── plugin/                                  # (Planned) WordPress / WooCommerce Plugin source
    ├── mr-tee-editor.php
    ├── includes/
    ├── templates/
    └── assets/
```

---

## 📑 Core Documentation
- [Forensic Analysis of Monsieur T-shirt](docs/MONSIEUR_TSHIRT_REVERSE_ENGINEERING.md)
- [Architecture & Handshake Specification](docs/ARCHITECTURE_AND_HANDSHAKE_SPEC.md)
- [Step-by-Step Implementation Plan](docs/IMPLEMENTATION_PLAN.md)
