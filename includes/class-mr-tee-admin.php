<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Mr_Tee_Admin {

    public function __construct() {
        add_action( 'add_meta_boxes', array( $this, 'register_meta_boxes' ), 25 );
        add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_admin_assets' ) );
        add_action( 'woocommerce_process_product_meta', array( $this, 'save_product_meta' ), 30, 1 );
    }

    /**
     * Register the Mr. Tee Editor & Customizer Engine Switcher metabox on WooCommerce products.
     */
    public function register_meta_boxes() {
        add_meta_box(
            'mr_tee_editor_metabox',
            '✨ Personnalisation Produit : Choix de l\'Éditeur (NBDesigner vs Mr. Tee Live Form Editor)',
            array( $this, 'render_metabox' ),
            'product',
            'normal',
            'high'
        );
    }

    /**
     * Enqueue admin styles, fonts, and interactive design area configurator JS.
     */
    public function enqueue_admin_assets( $hook ) {
        if ( ! in_array( $hook, array( 'post.php', 'post-new.php' ), true ) ) {
            return;
        }
        $screen = get_current_screen();
        if ( ! $screen || 'product' !== $screen->post_type ) {
            return;
        }

        wp_enqueue_media();

        wp_enqueue_style(
            'mr-tee-admin-css',
            MR_TEE_PLUGIN_URL . 'assets/css/mr-tee-admin.css',
            array(),
            MR_TEE_VERSION
        );

        wp_enqueue_script(
            'mr-tee-admin-js',
            MR_TEE_PLUGIN_URL . 'assets/js/mr-tee-admin.js',
            array( 'jquery' ),
            MR_TEE_VERSION,
            true
        );

        wp_localize_script( 'mr-tee-admin-js', 'MR_TEE_ADMIN', array(
            'pluginUrl' => MR_TEE_PLUGIN_URL,
            'fonts'     => mr_tee_get_available_fonts(),
            'icons'     => mr_tee_get_available_icons(),
            'palettes'  => mr_tee_get_color_palettes(),
            'mockups'   => array(
                'model_white' => MR_TEE_PLUGIN_URL . 'assets/images/mockups/mockup-model-white.jpg',
                'flat_white'  => MR_TEE_PLUGIN_URL . 'assets/images/mockups/mockup-flat-white.jpg',
            ),
        ) );
    }

    /**
     * Render the Product Customizer Engine Switcher & Mr. Tee Design Area Configurator.
     */
    public function render_metabox( $post ) {
        $config   = mr_tee_get_product_config( $post->ID );
        $fonts    = mr_tee_get_available_fonts();
        $palettes = mr_tee_get_color_palettes();
        $mode     = $config['editor_mode'];

        wp_nonce_field( 'mr_tee_save_product_meta', 'mr_tee_meta_nonce' );
        ?>
        <!-- Embedded @font-face rules for live admin preview -->
        <style>
            <?php foreach ( $fonts as $font_key => $font_info ) : ?>
            @font-face {
                font-family: "<?php echo esc_attr( $font_info['family'] ); ?>";
                src: url("<?php echo esc_url( $font_info['url'] ); ?>") format("truetype");
                font-display: swap;
            }
            <?php endforeach; ?>
        </style>

        <div class="mr-tee-admin-wrap">
            <!-- 1. ENGINE SELECTOR CARDS -->
            <div class="mr-tee-mode-selector">
                <div class="mr-tee-mode-header">
                    <h3>1. Choisissez le Moteur de Personnalisation pour ce Produit</h3>
                    <p>Sélectionnez si ce produit utilise l'éditeur natif <strong>NBDesigner</strong> (canvas complet) ou le nouvel éditeur <strong>Mr. Tee Live Form Editor</strong> (aperçu en direct sur la fiche produit style Monsieur TSHIRT).</p>
                </div>

                <div class="mr-tee-mode-cards">
                    <!-- Mode: None -->
                    <label class="mr-tee-mode-card <?php echo ( 'none' === $mode ) ? 'active' : ''; ?>">
                        <input type="radio" name="_mr_tee_editor_mode" value="none" <?php checked( $mode, 'none' ); ?> />
                        <span class="mr-tee-mode-badge">Standard</span>
                        <strong>Produit Simple (Sans Personnalisation)</strong>
                        <span>Aucune zone de personnalisation affichée sur la fiche produit.</span>
                    </label>

                    <!-- Mode: Native NBDesigner -->
                    <label class="mr-tee-mode-card <?php echo ( 'nbdesigner' === $mode ) ? 'active' : ''; ?>">
                        <input type="radio" name="_mr_tee_editor_mode" value="nbdesigner" <?php checked( $mode, 'nbdesigner' ); ?> />
                        <span class="mr-tee-mode-badge badge-nbd">NBDesigner Natif</span>
                        <strong>Activer NBDesigner (Canvas Classique)</strong>
                        <span>Affiche la boîte <em>Setting Cmsmart</em> ci-dessous pour configurer la zone NBDesigner classique.</span>
                    </label>

                    <!-- Mode: Mr. Tee Live Form Editor -->
                    <label class="mr-tee-mode-card <?php echo ( 'mr_tee_editor' === $mode ) ? 'active' : ''; ?>">
                        <input type="radio" name="_mr_tee_editor_mode" value="mr_tee_editor" <?php checked( $mode, 'mr_tee_editor' ); ?> />
                        <span class="mr-tee-mode-badge badge-mrtee">✨ Mr. Tee Live Editor</span>
                        <strong>Activer Mr. Tee Editor (Formulaire + Aperçu Live)</strong>
                        <span>Configurez la zone d'aperçu en direct sur l'image produit avec polices 3D et icônes dynamiques.</span>
                    </label>
                </div>
            </div>

            <!-- 2. MR. TEE EDITOR CONFIGURATION PANEL (Visible when mode == mr_tee_editor) -->
            <div id="mr-tee-config-panel" class="mr-tee-config-panel" style="<?php echo ( 'mr_tee_editor' === $mode ) ? '' : 'display:none;'; ?>">
                <div class="mr-tee-admin-grid">

                    <!-- LEFT COLUMN: INTERACTIVE DESIGN AREA ("EDITOR AREA") CONFIGURATOR -->
                    <div class="mr-tee-admin-col-visual">
                        <div class="mr-tee-panel-card">
                            <div class="mr-tee-card-title">
                                <h4>🎯 2. Définir la Zone d'Aperçu ("Editor Area") sur l'Image Produit</h4>
                                <p>Glissez et redimensionnez le cadre bleu directement sur l'image pour définir où le texte 3D et les champignons s'afficheront en direct sur la page produit.</p>
                            </div>

                            <!-- Mockup Image Source Toolbar -->
                            <div class="mr-tee-mockup-toolbar">
                                <input type="hidden" name="mr_tee_config[mockup_image]" id="mr_tee_mockup_image" value="<?php echo esc_attr( $config['mockup_image'] ); ?>" />
                                <button type="button" class="button button-secondary" id="mr-tee-upload-mockup-btn">
                                    📷 Choisir une Image Media WP
                                </button>
                                <button type="button" class="button" id="mr-tee-preset-model-btn" title="Charger le mannequin T-shirt blanc Monsieur T-shirt">
                                    👕 Mannequin Blanc
                                </button>
                                <button type="button" class="button" id="mr-tee-preset-flat-btn" title="Charger le T-shirt blanc à plat">
                                    🧥 T-shirt à Plat
                                </button>
                            </div>

                            <!-- Quick Area Placement Presets -->
                            <div class="mr-tee-area-presets">
                                <span>Positions rapides :</span>
                                <button type="button" class="button button-small mr-tee-area-preset"
                                        data-left="38.5" data-top="45.0" data-width="26.0" data-height="22.0" data-rotate="-1.4">
                                    📍 Poitrine Cœur (Style Monsieur T-shirt)
                                </button>
                                <button type="button" class="button button-small mr-tee-area-preset"
                                        data-left="28.0" data-top="32.0" data-width="44.0" data-height="36.0" data-rotate="0">
                                    🎯 Poitrine Centre (Large)
                                </button>
                                <button type="button" class="button button-small mr-tee-area-preset"
                                        data-left="25.0" data-top="25.0" data-width="50.0" data-height="45.0" data-rotate="0">
                                    📐 Grand Visuel Face
                                </button>
                            </div>

                            <!-- Interactive Visual Canvas Stage -->
                            <div class="mr-tee-visual-stage-wrapper">
                                <div id="mr-tee-admin-stage" class="mr-tee-admin-stage">
                                    <img id="mr-tee-admin-mockup-img" src="<?php echo esc_url( $config['mockup_image'] ); ?>" alt="Product Mockup" />

                                    <!-- Draggable & Resizable Editor Area Box -->
                                    <div id="mr-tee-admin-area-box" class="mr-tee-admin-area-box"
                                         style="left: <?php echo esc_attr( $config['area_left'] ); ?>%;
                                                top: <?php echo esc_attr( $config['area_top'] ); ?>%;
                                                width: <?php echo esc_attr( $config['area_width'] ); ?>%;
                                                height: <?php echo esc_attr( $config['area_height'] ); ?>%;
                                                transform: rotate(<?php echo esc_attr( $config['area_rotate'] ); ?>deg);">
                                        <div class="mr-tee-area-badge">Zone Live Preview (Glisser / Redimensionner)</div>

                                        <!-- Live SVG Preview inside the Admin Editor Area -->
                                        <div id="mr-tee-admin-live-svg-mount" class="mr-tee-admin-live-svg-mount"></div>

                                        <!-- Resize Handles -->
                                        <span class="mr-tee-resize-handle handle-nw" data-dir="nw"></span>
                                        <span class="mr-tee-resize-handle handle-ne" data-dir="ne"></span>
                                        <span class="mr-tee-resize-handle handle-sw" data-dir="sw"></span>
                                        <span class="mr-tee-resize-handle handle-se" data-dir="se"></span>
                                    </div>
                                </div>
                            </div>

                            <!-- Numeric Coordinate Inputs (Two-way synced with drag/resize) -->
                            <div class="mr-tee-coords-grid">
                                <div class="mr-tee-coord-field">
                                    <label>Position X (Left %)</label>
                                    <input type="number" step="0.1" min="0" max="95" name="mr_tee_config[area_left]" id="mr_tee_area_left" value="<?php echo esc_attr( $config['area_left'] ); ?>" />
                                </div>
                                <div class="mr-tee-coord-field">
                                    <label>Position Y (Top %)</label>
                                    <input type="number" step="0.1" min="0" max="95" name="mr_tee_config[area_top]" id="mr_tee_area_top" value="<?php echo esc_attr( $config['area_top'] ); ?>" />
                                </div>
                                <div class="mr-tee-coord-field">
                                    <label>Largeur Zone (%)</label>
                                    <input type="number" step="0.1" min="5" max="100" name="mr_tee_config[area_width]" id="mr_tee_area_width" value="<?php echo esc_attr( $config['area_width'] ); ?>" />
                                </div>
                                <div class="mr-tee-coord-field">
                                    <label>Hauteur Zone (%)</label>
                                    <input type="number" step="0.1" min="5" max="100" name="mr_tee_config[area_height]" id="mr_tee_area_height" value="<?php echo esc_attr( $config['area_height'] ); ?>" />
                                </div>
                                <div class="mr-tee-coord-field">
                                    <label>Inclinaison (° deg)</label>
                                    <input type="number" step="0.1" min="-45" max="45" name="mr_tee_config[area_rotate]" id="mr_tee_area_rotate" value="<?php echo esc_attr( $config['area_rotate'] ); ?>" />
                                </div>
                            </div>

                            <!-- Physical Print Dimensions for 300 DPI Export -->
                            <div class="mr-tee-print-specs-bar">
                                <strong>🖨️ Dimensions Réelles d'Impression (Export 300 DPI & NBDesigner) :</strong>
                                <div class="mr-tee-coords-grid" style="margin-top:8px;">
                                    <div class="mr-tee-coord-field">
                                        <label>Largeur Réelle (cm)</label>
                                        <input type="number" step="0.1" min="1" max="100" name="mr_tee_config[print_width_cm]" value="<?php echo esc_attr( $config['print_width_cm'] ); ?>" />
                                    </div>
                                    <div class="mr-tee-coord-field">
                                        <label>Hauteur Réelle (cm)</label>
                                        <input type="number" step="0.1" min="1" max="100" name="mr_tee_config[print_height_cm]" value="<?php echo esc_attr( $config['print_height_cm'] ); ?>" />
                                    </div>
                                    <div class="mr-tee-coord-field">
                                        <label>Résolution Export (DPI)</label>
                                        <input type="number" step="1" min="72" max="600" name="mr_tee_config[print_dpi]" value="<?php echo esc_attr( $config['print_dpi'] ); ?>" />
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- RIGHT COLUMN: FORM FIELDS, FANCY FONTS & REPEATER SETTINGS -->
                    <div class="mr-tee-admin-col-settings">

                        <!-- SECTION A: LINE 1 (PREFIX / HEADER e.g. "SUPER") -->
                        <div class="mr-tee-panel-card">
                            <div class="mr-tee-card-title">
                                <h4>🔤 3. Ligne 1 : Titre / Préfixe en Police 3D (ex: "SUPER")</h4>
                            </div>
                            <div class="mr-tee-field-row">
                                <label>
                                    <input type="checkbox" name="mr_tee_config[line1_enable]" id="mr_tee_line1_enable" value="1" <?php checked( $config['line1_enable'], 1 ); ?> />
                                    Activer la Ligne 1
                                </label>
                                <label style="margin-left: 18px;">
                                    <input type="checkbox" name="mr_tee_config[line1_editable]" id="mr_tee_line1_editable" value="1" <?php checked( $config['line1_editable'], 1 ); ?> />
                                    Modifiable par le client
                                </label>
                            </div>
                            <div class="mr-tee-field-grid">
                                <div>
                                    <label>Texte par défaut (Ligne 1)</label>
                                    <input type="text" name="mr_tee_config[line1_default]" id="mr_tee_line1_default" value="<?php echo esc_attr( $config['line1_default'] ); ?>" />
                                </div>
                                <div>
                                    <label>Label du champ (si modifiable)</label>
                                    <input type="text" name="mr_tee_config[line1_label]" value="<?php echo esc_attr( $config['line1_label'] ); ?>" />
                                </div>
                                <div>
                                    <label>Police Fancy (Ligne 1)</label>
                                    <select name="mr_tee_config[line1_font]" id="mr_tee_line1_font">
                                        <?php foreach ( $fonts as $f_key => $f_data ) : ?>
                                            <option value="<?php echo esc_attr( $f_key ); ?>" <?php selected( $config['line1_font'], $f_key ); ?>>
                                                <?php echo esc_html( $f_data['name'] ); ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div>
                                    <label>Palette de Couleurs par Lettre</label>
                                    <select name="mr_tee_config[line1_color_mode]" id="mr_tee_line1_color_mode">
                                        <?php foreach ( $palettes as $p_key => $p_data ) : ?>
                                            <option value="<?php echo esc_attr( $p_key ); ?>" <?php selected( $config['line1_color_mode'], $p_key ); ?>>
                                                <?php echo esc_html( $p_data['label'] ); ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div>
                                    <label>Couleur Unie (si mode Couleur Unie)</label>
                                    <input type="color" name="mr_tee_config[line1_solid_color]" id="mr_tee_line1_solid_color" value="<?php echo esc_attr( $config['line1_solid_color'] ); ?>" />
                                </div>
                                <div>
                                    <label>Épaisseur Contour Noir</label>
                                    <input type="number" step="0.2" min="0" max="10" name="mr_tee_config[line1_stroke_width]" id="mr_tee_line1_stroke_width" value="<?php echo esc_attr( $config['line1_stroke_width'] ); ?>" />
                                </div>
                            </div>
                        </div>

                        <!-- SECTION B: LINE 2 (MAIN CUSTOM NAME e.g. "PAPA" / "MEDAM") -->
                        <div class="mr-tee-panel-card">
                            <div class="mr-tee-card-title">
                                <h4>✍️ 4. Ligne 2 : Champ Nom Principal en Direct (ex: "Modifier le nom")</h4>
                            </div>
                            <div class="mr-tee-field-row">
                                <label>
                                    <input type="checkbox" name="mr_tee_config[line2_enable]" id="mr_tee_line2_enable" value="1" <?php checked( $config['line2_enable'], 1 ); ?> />
                                    Activer le champ Nom Principal
                                </label>
                                <label style="margin-left: 18px;">
                                    <input type="checkbox" name="mr_tee_config[line2_allow_font_switch]" id="mr_tee_line2_allow_font_switch" value="1" <?php checked( $config['line2_allow_font_switch'], 1 ); ?> />
                                    Permettre au client de changer la police Fancy en direct
                                </label>
                            </div>
                            <div class="mr-tee-field-grid">
                                <div>
                                    <label>Intitulé du champ (Label)</label>
                                    <input type="text" name="mr_tee_config[line2_label]" value="<?php echo esc_attr( $config['line2_label'] ); ?>" />
                                </div>
                                <div>
                                    <label>Texte par défaut (ex: Papa, Medam)</label>
                                    <input type="text" name="mr_tee_config[line2_default]" id="mr_tee_line2_default" value="<?php echo esc_attr( $config['line2_default'] ); ?>" />
                                </div>
                                <div>
                                    <label>Nombre max. de caractères</label>
                                    <input type="number" min="1" max="30" name="mr_tee_config[line2_max_chars]" value="<?php echo esc_attr( $config['line2_max_chars'] ); ?>" />
                                </div>
                                <div>
                                    <label>Police Fancy par défaut (Ligne 2)</label>
                                    <select name="mr_tee_config[line2_font]" id="mr_tee_line2_font">
                                        <?php foreach ( $fonts as $f_key => $f_data ) : ?>
                                            <option value="<?php echo esc_attr( $f_key ); ?>" <?php selected( $config['line2_font'], $f_key ); ?>>
                                                <?php echo esc_html( $f_data['name'] ); ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div>
                                    <label>Palette de Couleurs par Lettre</label>
                                    <select name="mr_tee_config[line2_color_mode]" id="mr_tee_line2_color_mode">
                                        <?php foreach ( $palettes as $p_key => $p_data ) : ?>
                                            <option value="<?php echo esc_attr( $p_key ); ?>" <?php selected( $config['line2_color_mode'], $p_key ); ?>>
                                                <?php echo esc_html( $p_data['label'] ); ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div>
                                    <label>Couleur Unie (si mode Couleur Unie)</label>
                                    <input type="color" name="mr_tee_config[line2_solid_color]" id="mr_tee_line2_solid_color" value="<?php echo esc_attr( $config['line2_solid_color'] ); ?>" />
                                </div>
                                <div>
                                    <label>Épaisseur Contour Noir</label>
                                    <input type="number" step="0.2" min="0" max="10" name="mr_tee_config[line2_stroke_width]" id="mr_tee_line2_stroke_width" value="<?php echo esc_attr( $config['line2_stroke_width'] ); ?>" />
                                </div>
                                <div>
                                    <label style="margin-top:22px;display:block;">
                                        <input type="checkbox" name="mr_tee_config[line2_bevel_3d]" id="mr_tee_line2_bevel_3d" value="1" <?php checked( $config['line2_bevel_3d'], 1 ); ?> />
                                        Effet Relief 3D Lumineux (SVG Bevel)
                                    </label>
                                </div>
                            </div>
                        </div>

                        <!-- SECTION C: REPEATER ("NOMBRE DE CHAMPIGNONS") -->
                        <div class="mr-tee-panel-card">
                            <div class="mr-tee-card-title">
                                <h4>🍄 5. Répéteur Dynamique : Icônes & Prénoms Enfants ("Nombre de champignons")</h4>
                            </div>
                            <div class="mr-tee-field-row">
                                <label>
                                    <input type="checkbox" name="mr_tee_config[repeater_enable]" id="mr_tee_repeater_enable" value="1" <?php checked( $config['repeater_enable'], 1 ); ?> />
                                    Activer la sélection d'icônes & sous-prénoms (0 à 6 éléments)
                                </label>
                            </div>
                            <div class="mr-tee-field-grid">
                                <div>
                                    <label>Titre du bouton compteur</label>
                                    <input type="text" name="mr_tee_config[repeater_title]" value="<?php echo esc_attr( $config['repeater_title'] ); ?>" />
                                </div>
                                <div>
                                    <label>Intitulé de chaque élément</label>
                                    <input type="text" name="mr_tee_config[repeater_item_label]" value="<?php echo esc_attr( $config['repeater_item_label'] ); ?>" />
                                </div>
                                <div>
                                    <label>Nombre par défaut</label>
                                    <input type="number" min="0" max="6" name="mr_tee_config[repeater_default_count]" id="mr_tee_repeater_default_count" value="<?php echo esc_attr( $config['repeater_default_count'] ); ?>" />
                                </div>
                                <div>
                                    <label>Nombre maximum (1 à 6)</label>
                                    <input type="number" min="1" max="6" name="mr_tee_config[repeater_max]" value="<?php echo esc_attr( $config['repeater_max'] ); ?>" />
                                </div>
                                <div>
                                    <label>Caractères max. par prénom</label>
                                    <input type="number" min="1" max="20" name="mr_tee_config[repeater_max_chars]" value="<?php echo esc_attr( $config['repeater_max_chars'] ); ?>" />
                                </div>
                                <div>
                                    <label>Police des sous-prénoms</label>
                                    <select name="mr_tee_config[repeater_font]" id="mr_tee_repeater_font">
                                        <?php foreach ( $fonts as $f_key => $f_data ) : ?>
                                            <option value="<?php echo esc_attr( $f_key ); ?>" <?php selected( $config['repeater_font'], $f_key ); ?>>
                                                <?php echo esc_html( $f_data['name'] ); ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                            </div>
                        </div>

                        <!-- SECTION D: GARMENT COLOR SWATCHES -->
                        <div class="mr-tee-panel-card">
                            <div class="mr-tee-card-title">
                                <h4>🎨 6. Pastilles Couleurs du T-shirt (Swatches)</h4>
                            </div>
                            <div class="mr-tee-field-row">
                                <label>
                                    <input type="checkbox" name="mr_tee_config[swatches_enable]" value="1" <?php checked( $config['swatches_enable'], 1 ); ?> />
                                    Afficher les pastilles de couleur au-dessus du bloc Personnaliser
                                </label>
                            </div>
                        </div>

                    </div>
                </div>
            </div>
        </div>
        <?php
    }

    /**
     * Save Product Meta and synchronize coexistence flags with NBDesigner.
     */
    public function save_product_meta( $post_id ) {
        if ( ! isset( $_POST['mr_tee_meta_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['mr_tee_meta_nonce'] ) ), 'mr_tee_save_product_meta' ) ) {
            return;
        }

        $mode = isset( $_POST['_mr_tee_editor_mode'] ) ? sanitize_text_field( wp_unslash( $_POST['_mr_tee_editor_mode'] ) ) : 'none';
        update_post_meta( $post_id, '_mr_tee_editor_mode', $mode );

        $raw_config = isset( $_POST['mr_tee_config'] ) && is_array( $_POST['mr_tee_config'] ) ? wp_unslash( $_POST['mr_tee_config'] ) : array();
        $defaults   = mr_tee_get_default_config( $post_id );

        $clean_config = array(
            'editor_mode'             => $mode,
            'mockup_image'            => isset( $raw_config['mockup_image'] ) ? esc_url_raw( $raw_config['mockup_image'] ) : $defaults['mockup_image'],
            'area_left'               => isset( $raw_config['area_left'] ) ? floatval( $raw_config['area_left'] ) : $defaults['area_left'],
            'area_top'                => isset( $raw_config['area_top'] ) ? floatval( $raw_config['area_top'] ) : $defaults['area_top'],
            'area_width'              => isset( $raw_config['area_width'] ) ? floatval( $raw_config['area_width'] ) : $defaults['area_width'],
            'area_height'             => isset( $raw_config['area_height'] ) ? floatval( $raw_config['area_height'] ) : $defaults['area_height'],
            'area_rotate'             => isset( $raw_config['area_rotate'] ) ? floatval( $raw_config['area_rotate'] ) : $defaults['area_rotate'],
            'print_width_cm'          => isset( $raw_config['print_width_cm'] ) ? floatval( $raw_config['print_width_cm'] ) : $defaults['print_width_cm'],
            'print_height_cm'         => isset( $raw_config['print_height_cm'] ) ? floatval( $raw_config['print_height_cm'] ) : $defaults['print_height_cm'],
            'print_dpi'               => isset( $raw_config['print_dpi'] ) ? absint( $raw_config['print_dpi'] ) : 300,
            'line1_enable'            => ! empty( $raw_config['line1_enable'] ) ? 1 : 0,
            'line1_editable'          => ! empty( $raw_config['line1_editable'] ) ? 1 : 0,
            'line1_label'             => isset( $raw_config['line1_label'] ) ? sanitize_text_field( $raw_config['line1_label'] ) : $defaults['line1_label'],
            'line1_default'           => isset( $raw_config['line1_default'] ) ? sanitize_text_field( $raw_config['line1_default'] ) : $defaults['line1_default'],
            'line1_max_chars'         => 10,
            'line1_font'              => isset( $raw_config['line1_font'] ) ? sanitize_text_field( $raw_config['line1_font'] ) : $defaults['line1_font'],
            'line1_color_mode'        => isset( $raw_config['line1_color_mode'] ) ? sanitize_text_field( $raw_config['line1_color_mode'] ) : $defaults['line1_color_mode'],
            'line1_solid_color'       => isset( $raw_config['line1_solid_color'] ) ? sanitize_hex_color( $raw_config['line1_solid_color'] ) : $defaults['line1_solid_color'],
            'line1_stroke_width'      => isset( $raw_config['line1_stroke_width'] ) ? floatval( $raw_config['line1_stroke_width'] ) : $defaults['line1_stroke_width'],
            'line1_stroke_color'      => '#000000',
            'line1_bevel_3d'          => 1,
            'line2_enable'            => ! empty( $raw_config['line2_enable'] ) ? 1 : 0,
            'line2_label'             => isset( $raw_config['line2_label'] ) ? sanitize_text_field( $raw_config['line2_label'] ) : $defaults['line2_label'],
            'line2_default'           => isset( $raw_config['line2_default'] ) ? sanitize_text_field( $raw_config['line2_default'] ) : $defaults['line2_default'],
            'line2_max_chars'         => isset( $raw_config['line2_max_chars'] ) ? absint( $raw_config['line2_max_chars'] ) : 10,
            'line2_font'              => isset( $raw_config['line2_font'] ) ? sanitize_text_field( $raw_config['line2_font'] ) : $defaults['line2_font'],
            'line2_allow_font_switch' => ! empty( $raw_config['line2_allow_font_switch'] ) ? 1 : 0,
            'line2_color_mode'        => isset( $raw_config['line2_color_mode'] ) ? sanitize_text_field( $raw_config['line2_color_mode'] ) : $defaults['line2_color_mode'],
            'line2_solid_color'       => isset( $raw_config['line2_solid_color'] ) ? sanitize_hex_color( $raw_config['line2_solid_color'] ) : $defaults['line2_solid_color'],
            'line2_stroke_width'      => isset( $raw_config['line2_stroke_width'] ) ? floatval( $raw_config['line2_stroke_width'] ) : $defaults['line2_stroke_width'],
            'line2_stroke_color'      => '#000000',
            'line2_bevel_3d'          => ! empty( $raw_config['line2_bevel_3d'] ) ? 1 : 0,
            'repeater_enable'         => ! empty( $raw_config['repeater_enable'] ) ? 1 : 0,
            'repeater_title'          => isset( $raw_config['repeater_title'] ) ? sanitize_text_field( $raw_config['repeater_title'] ) : $defaults['repeater_title'],
            'repeater_item_label'     => isset( $raw_config['repeater_item_label'] ) ? sanitize_text_field( $raw_config['repeater_item_label'] ) : $defaults['repeater_item_label'],
            'repeater_name_label'     => 'Modifier le nom',
            'repeater_min'            => 0,
            'repeater_max'            => isset( $raw_config['repeater_max'] ) ? min( 6, max( 1, absint( $raw_config['repeater_max'] ) ) ) : 6,
            'repeater_default_count'  => isset( $raw_config['repeater_default_count'] ) ? min( 6, max( 0, absint( $raw_config['repeater_default_count'] ) ) ) : 3,
            'repeater_max_chars'      => isset( $raw_config['repeater_max_chars'] ) ? absint( $raw_config['repeater_max_chars'] ) : 9,
            'repeater_font'           => isset( $raw_config['repeater_font'] ) ? sanitize_text_field( $raw_config['repeater_font'] ) : $defaults['repeater_font'],
            'repeater_text_color'     => '#000000',
            'swatches_enable'         => ! empty( $raw_config['swatches_enable'] ) ? 1 : 0,
            'swatches'                => $defaults['swatches'],
        );

        update_post_meta( $post_id, '_mr_tee_config', $clean_config );

        // Synchronize with NBDesigner so the two editors act as clean competitors
        if ( 'mr_tee_editor' === $mode ) {
            // Disable NBDesigner frontend button so Mr. Tee Live Form Editor owns the product page
            update_post_meta( $post_id, '_nbdesigner_enable', 0 );
        } elseif ( 'nbdesigner' === $mode ) {
            update_post_meta( $post_id, '_nbdesigner_enable', 1 );
        }
    }
}
