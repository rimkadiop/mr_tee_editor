<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Mr_Tee_Frontend {

    public function __construct() {
        add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_frontend_assets' ) );
        add_action( 'woocommerce_before_add_to_cart_button', array( $this, 'render_product_customizer_form' ), 15 );
        // Ensure NBDesigner's "Start Design" button is suppressed on products using mr_tee_editor
        add_filter( 'nbdesigner_enable', array( $this, 'suppress_nbdesigner_on_mr_tee_products' ), 20, 2 );
    }

    /**
     * Check if the current product uses mr_tee_editor.
     */
    public function is_mr_tee_product( $product_id = 0 ) {
        if ( ! $product_id ) {
            $product_id = get_the_ID();
        }
        if ( ! $product_id ) {
            return false;
        }
        $config = mr_tee_get_product_config( $product_id );
        return ( isset( $config['editor_mode'] ) && 'mr_tee_editor' === $config['editor_mode'] );
    }

    /**
     * Suppress NBDesigner frontend button when mr_tee_editor is selected for this product.
     */
    public function suppress_nbdesigner_on_mr_tee_products( $enabled, $product_id = 0 ) {
        if ( $this->is_mr_tee_product( $product_id ) ) {
            return false;
        }
        return $enabled;
    }

    /**
     * Enqueue frontend CSS, @font-face declarations, and reactive live preview JS.
     */
    public function enqueue_frontend_assets() {
        if ( ! function_exists( 'is_product' ) || ! is_product() ) {
            return;
        }
        $product_id = get_the_ID();
        if ( ! $this->is_mr_tee_product( $product_id ) ) {
            return;
        }

        $config   = mr_tee_get_product_config( $product_id );
        $fonts    = mr_tee_get_available_fonts();
        $icons    = mr_tee_get_available_icons();
        $palettes = mr_tee_get_color_palettes();

        wp_enqueue_style(
            'mr-tee-frontend-css',
            MR_TEE_PLUGIN_URL . 'assets/css/mr-tee-frontend.css',
            array(),
            MR_TEE_VERSION
        );

        // Inject @font-face declarations inline
        $font_css = '';
        foreach ( $fonts as $f_info ) {
            $font_css .= sprintf(
                '@font-face { font-family: "%s"; src: url("%s") format("truetype"); font-display: block; }' . "\n",
                esc_attr( $f_info['family'] ),
                esc_url( $f_info['url'] )
            );
        }
        wp_add_inline_style( 'mr-tee-frontend-css', $font_css );

        wp_enqueue_script(
            'mr-tee-frontend-js',
            MR_TEE_PLUGIN_URL . 'assets/js/mr-tee-frontend.js',
            array( 'jquery' ),
            MR_TEE_VERSION,
            true
        );

        wp_localize_script( 'mr-tee-frontend-js', 'MR_TEE_FRONT', array(
            'ajaxUrl'   => admin_url( 'admin-ajax.php' ),
            'nonce'     => wp_create_nonce( 'mr_tee_save_design_nonce' ),
            'productId' => $product_id,
            'config'    => $config,
            'fonts'     => $fonts,
            'icons'     => $icons,
            'palettes'  => $palettes,
        ) );
    }

    /**
     * Render the Monsieur TSHIRT style Personalization Form inside the WooCommerce Add-to-Cart form.
     */
    public function render_product_customizer_form() {
        global $product;
        if ( ! $product ) {
            return;
        }
        $product_id = $product->get_id();
        if ( ! $this->is_mr_tee_product( $product_id ) ) {
            return;
        }

        $config = mr_tee_get_product_config( $product_id );
        $fonts  = mr_tee_get_available_fonts();
        $icons  = mr_tee_get_available_icons();
        $palettes = mr_tee_get_color_palettes();
        ?>
        <div id="mr-tee-customizer-root" class="mr-tee-customizer-root">

            <!-- 1. GARMENT COLOR SWATCHES (Round Circles above Personalizer Box) -->
            <?php if ( ! empty( $config['swatches_enable'] ) && ! empty( $config['swatches'] ) ) : ?>
                <div class="mr-tee-swatches-bar">
                    <div class="mr-tee-swatches-label">
                        Couleur : <strong id="mr-tee-active-color-name"><?php echo esc_html( $config['swatches'][0]['name'] ); ?></strong>
                    </div>
                    <div class="mr-tee-swatches-list">
                        <?php foreach ( $config['swatches'] as $idx => $swatch ) : ?>
                            <button type="button"
                                    class="mr-tee-color-swatch <?php echo ( 0 === $idx ) ? 'active' : ''; ?>"
                                    data-color-name="<?php echo esc_attr( $swatch['name'] ); ?>"
                                    data-color-hex="<?php echo esc_attr( $swatch['hex'] ); ?>"
                                    data-mockup="<?php echo esc_attr( $swatch['mockup'] ); ?>"
                                    title="<?php echo esc_attr( $swatch['name'] ); ?>"
                                    style="background-color: <?php echo esc_attr( $swatch['hex'] ); ?>;">
                            </button>
                        <?php endforeach; ?>
                    </div>
                </div>
            <?php endif; ?>

            <!-- 2. MAIN PERSONALIZER CARD (Monsieur TSHIRT Light-Blue Style) -->
            <div class="mr-tee-perso-card">
                <div class="mr-tee-perso-header">PERSONNALISER</div>

                <!-- Repeater Count Button ("Nombre de champignons") -->
                <?php if ( ! empty( $config['repeater_enable'] ) ) : ?>
                    <button type="button" id="mr-tee-open-count-modal" class="mr-tee-count-trigger-btn">
                        <span id="mr-tee-count-btn-label"><?php echo esc_html( $config['repeater_title'] ); ?></span>
                        <span class="mr-tee-count-badge" id="mr-tee-count-badge"><?php echo esc_html( $config['repeater_default_count'] ); ?></span>
                    </button>

                    <!-- Inline Popover / Modal for Selecting Count (0 to Max) -->
                    <div id="mr-tee-count-drawer" class="mr-tee-count-drawer" style="display:none;">
                        <div class="mr-tee-count-drawer-title">
                            <?php echo esc_html( $config['repeater_title'] ); ?>
                            <small>(min <?php echo esc_html( $config['repeater_min'] ); ?> - max <?php echo esc_html( $config['repeater_max'] ); ?>)</small>
                        </div>
                        <div class="mr-tee-count-pills">
                            <?php for ( $c = $config['repeater_min']; $c <= $config['repeater_max']; $c++ ) : ?>
                                <button type="button"
                                        class="mr-tee-count-pill <?php echo ( intval( $config['repeater_default_count'] ) === $c ) ? 'active' : ''; ?>"
                                        data-count="<?php echo esc_attr( $c ); ?>">
                                    <?php echo esc_html( $c ); ?>
                                </button>
                            <?php endfor; ?>
                        </div>
                    </div>
                <?php endif; ?>

                <!-- Optional Editable Line 1 Input (if line1_editable is enabled) -->
                <?php if ( ! empty( $config['line1_enable'] ) && ! empty( $config['line1_editable'] ) ) : ?>
                    <div class="mr-tee-floating-field">
                        <label class="mr-tee-floating-label" for="mr-tee-input-line1">
                            <?php echo esc_html( $config['line1_label'] ); ?>
                        </label>
                        <input type="text"
                               id="mr-tee-input-line1"
                               class="mr-tee-text-input"
                               maxlength="<?php echo esc_attr( $config['line1_max_chars'] ); ?>"
                               value="<?php echo esc_attr( $config['line1_default'] ); ?>"
                               autocomplete="off" />
                        <span class="mr-tee-char-counter" id="mr-tee-counter-line1">
                            <?php echo esc_html( mb_strlen( $config['line1_default'] ) . ' / ' . $config['line1_max_chars'] ); ?>
                        </span>
                    </div>
                <?php endif; ?>

                <!-- Main Custom Name Input ("Modifier le nom" — e.g. "Medam" 5 / 10) -->
                <?php if ( ! empty( $config['line2_enable'] ) ) : ?>
                    <div class="mr-tee-floating-field">
                        <label class="mr-tee-floating-label" for="mr-tee-input-line2">
                            <?php echo esc_html( $config['line2_label'] ); ?>
                        </label>
                        <input type="text"
                               id="mr-tee-input-line2"
                               class="mr-tee-text-input"
                               maxlength="<?php echo esc_attr( $config['line2_max_chars'] ); ?>"
                               value="<?php echo esc_attr( $config['line2_default'] ); ?>"
                               placeholder="Votre texte..."
                               autocomplete="off" />
                        <span class="mr-tee-char-counter" id="mr-tee-counter-line2">
                            <?php echo esc_html( mb_strlen( $config['line2_default'] ) . ' / ' . $config['line2_max_chars'] ); ?>
                        </span>
                    </div>
                <?php endif; ?>

                <!-- Fancy Font & Color Palette Switcher (if enabled by Admin) -->
                <?php if ( ! empty( $config['line2_allow_font_switch'] ) ) : ?>
                    <div class="mr-tee-font-switcher-wrap">
                        <div class="mr-tee-font-switcher-label">Style de police Fancy :</div>
                        <div class="mr-tee-font-pills">
                            <?php foreach ( $fonts as $f_key => $f_data ) : ?>
                                <button type="button"
                                        class="mr-tee-font-pill <?php echo ( $config['line2_font'] === $f_key ) ? 'active' : ''; ?>"
                                        data-font="<?php echo esc_attr( $f_key ); ?>"
                                        style="font-family: '<?php echo esc_attr( $f_data['family'] ); ?>', sans-serif;">
                                    Abc
                                    <span><?php echo esc_html( preg_replace( '/\s*\(.*\)/', '', $f_data['name'] ) ); ?></span>
                                </button>
                            <?php endforeach; ?>
                        </div>
                    </div>
                <?php endif; ?>

                <!-- Dynamic Accordion List for Mushrooms / Characters -->
                <?php if ( ! empty( $config['repeater_enable'] ) ) : ?>
                    <div id="mr-tee-repeater-accordions" class="mr-tee-repeater-accordions"></div>
                <?php endif; ?>

            </div>

            <!-- Hidden Inputs for WooCommerce Cart & NBDesigner Handshake -->
            <input type="hidden" name="mr_tee_design_key" id="mr_tee_design_key" value="" />
            <input type="hidden" name="mr_tee_nbd_item_key" id="mr_tee_nbd_item_key" value="" />
            <input type="hidden" name="mr_tee_state_json" id="mr_tee_state_json" value="" />
        </div>
        <?php
    }
}
