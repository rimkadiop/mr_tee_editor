<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Mr_Tee_Cart_Order {

    public function __construct() {
        // AJAX endpoint to save 300 DPI PNG, Vector SVG, Composite Preview & NBDesigner Handshake
        add_action( 'wp_ajax_mr_tee_save_design', array( $this, 'ajax_save_design' ) );
        add_action( 'wp_ajax_nopriv_mr_tee_save_design', array( $this, 'ajax_save_design' ) );

        // WooCommerce Cart & Checkout hooks
        add_filter( 'woocommerce_add_cart_item_data', array( $this, 'add_cart_item_data' ), 25, 3 );
        add_filter( 'woocommerce_get_item_data', array( $this, 'display_cart_item_data' ), 25, 2 );
        add_filter( 'woocommerce_cart_item_thumbnail', array( $this, 'override_cart_item_thumbnail' ), 30, 3 );

        // WooCommerce Order persistence & Admin Order print export panel
        add_action( 'woocommerce_checkout_create_order_line_item', array( $this, 'save_order_line_item_meta' ), 25, 4 );
        add_action( 'woocommerce_after_order_itemmeta', array( $this, 'render_admin_order_item_print_panel' ), 20, 3 );
    }

    /**
     * Decode base64 data URI to raw binary.
     */
    private function decode_data_uri( $data_uri ) {
        if ( empty( $data_uri ) || strpos( $data_uri, 'base64,' ) === false ) {
            return false;
        }
        $parts = explode( 'base64,', $data_uri );
        return base64_decode( $parts[1] );
    }

    /**
     * AJAX Handler: Save 300 DPI print files and synchronize with NBDesigner folder structure.
     */
    public function ajax_save_design() {
        check_ajax_referer( 'mr_tee_save_design_nonce', 'nonce' );

        $product_id  = isset( $_POST['product_id'] ) ? absint( $_POST['product_id'] ) : 0;
        $state_raw   = isset( $_POST['state_json'] ) ? wp_unslash( $_POST['state_json'] ) : '{}';
        $svg_content = isset( $_POST['svg_content'] ) ? wp_unslash( $_POST['svg_content'] ) : '';
        $print_png   = isset( $_POST['print_png'] ) ? wp_unslash( $_POST['print_png'] ) : '';
        $preview_png = isset( $_POST['preview_png'] ) ? wp_unslash( $_POST['preview_png'] ) : '';

        $state  = json_decode( $state_raw, true );
        if ( ! is_array( $state ) ) {
            $state = array();
        }
        $config = mr_tee_get_product_config( $product_id );

        $upload_dir = wp_upload_dir();
        $design_key = 'mrtee_' . substr( md5( uniqid( (string) $product_id, true ) ), 0, 10 );

        // 1. Save in dedicated mr-tee-designs directory
        $mr_dir = trailingslashit( $upload_dir['basedir'] ) . 'mr-tee-designs/' . $design_key;
        $mr_url = trailingslashit( $upload_dir['baseurl'] ) . 'mr-tee-designs/' . $design_key;
        wp_mkdir_p( $mr_dir );

        $print_bin   = $this->decode_data_uri( $print_png );
        $preview_bin = $this->decode_data_uri( $preview_png );

        if ( $print_bin ) {
            file_put_contents( $mr_dir . '/frame_0.png', $print_bin );
        }
        if ( $preview_bin ) {
            file_put_contents( $mr_dir . '/preview.png', $preview_bin );
        }
        if ( ! empty( $svg_content ) ) {
            file_put_contents( $mr_dir . '/frame_0_svg.svg', $svg_content );
        }
        file_put_contents( $mr_dir . '/state.json', wp_json_encode( $state, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE ) );

        // 2. Also write NBDesigner 5-artifact folder so NBDesigner's native Admin Order export tools work too
        $nbd_item_key = substr( md5( $design_key ), 0, 7 ) . time();
        $nbd_base     = defined( 'NBDESIGNER_CUSTOMER_DIR' )
            ? NBDESIGNER_CUSTOMER_DIR
            : trailingslashit( $upload_dir['basedir'] ) . 'nbdesigner/designs';
        $nbd_dir      = trailingslashit( $nbd_base ) . $nbd_item_key;
        $nbd_prev_dir = $nbd_dir . '/preview';

        wp_mkdir_p( $nbd_prev_dir );

        if ( $print_bin ) {
            file_put_contents( $nbd_dir . '/frame_0.png', $print_bin );
        }
        if ( $preview_bin ) {
            file_put_contents( $nbd_prev_dir . '/frame_0.png', $preview_bin );
        }
        if ( ! empty( $svg_content ) ) {
            file_put_contents( $nbd_dir . '/frame_0_svg.svg', $svg_content );
        }

        // Build NBDesigner-compatible design.json layer list
        $headline_full = trim( ( isset( $state['line1Text'] ) ? $state['line1Text'] : '' ) . ' ' . ( isset( $state['line2Text'] ) ? $state['line2Text'] : '' ) );
        $nbd_objects   = array(
            array(
                'type'       => 'i-text',
                'text'       => $headline_full,
                'fontFamily' => isset( $state['fontFamily'] ) ? $state['fontFamily'] : 'Super Mario Wii',
                'fontSize'   => 36,
                'fill'       => '#e42616',
                'left'       => 100,
                'top'        => 60,
                'width'      => 300,
                'height'     => 80,
            ),
        );
        if ( ! empty( $state['items'] ) && is_array( $state['items'] ) ) {
            foreach ( $state['items'] as $idx => $item ) {
                $nbd_objects[] = array(
                    'type'       => 'i-text',
                    'text'       => sprintf( 'Champignon %d: %s (%s)', $idx + 1, $item['name'], $item['iconName'] ),
                    'fontFamily' => $config['repeater_font'],
                    'fontSize'   => 18,
                    'fill'       => '#111111',
                    'left'       => 60 + ( $idx * 80 ),
                    'top'        => 180,
                    'width'      => 120,
                    'height'     => 30,
                );
            }
        }

        $design_json = array(
            'frame_0' => array(
                'version' => '3.4.0',
                'objects' => $nbd_objects,
            ),
        );
        file_put_contents( $nbd_dir . '/design.json', wp_json_encode( $design_json ) );

        $config_json = array(
            'dpi'     => isset( $config['print_dpi'] ) ? absint( $config['print_dpi'] ) : 300,
            'unit'    => 'cm',
            'product' => array(
                array(
                    'orientation_name'   => 'Front',
                    'real_width'         => isset( $config['print_width_cm'] ) ? floatval( $config['print_width_cm'] ) : 12,
                    'real_height'        => isset( $config['print_height_cm'] ) ? floatval( $config['print_height_cm'] ) : 10,
                    'area_design_width'  => 240,
                    'area_design_height' => 240,
                ),
            ),
        );
        file_put_contents( $nbd_dir . '/config.json', wp_json_encode( $config_json ) );

        $used_fonts = array(
            array(
                'alias' => isset( $state['fontFamily'] ) ? $state['fontFamily'] : 'Super Mario Wii',
                'name'  => isset( $state['fontFamily'] ) ? $state['fontFamily'] : 'Super Mario Wii',
                'type'  => 'ttf',
            ),
        );
        file_put_contents( $nbd_dir . '/used_font.json', wp_json_encode( $used_fonts ) );

        wp_send_json_success( array(
            'design_key'   => $design_key,
            'nbd_item_key' => $nbd_item_key,
            'preview_url'  => $mr_url . '/preview.png',
            'print_url'    => $mr_url . '/frame_0.png',
            'svg_url'      => $mr_url . '/frame_0_svg.svg',
        ) );
    }

    /**
     * Attach personalization metadata & file URLs to the WooCommerce Cart Item.
     */
    public function add_cart_item_data( $cart_item_data, $product_id, $variation_id ) {
        $config = mr_tee_get_product_config( $product_id );
        if ( ! isset( $config['editor_mode'] ) || 'mr_tee_editor' !== $config['editor_mode'] ) {
            return $cart_item_data;
        }

        if ( ! empty( $_POST['mr_tee_state_json'] ) ) {
            $state = json_decode( wp_unslash( $_POST['mr_tee_state_json'] ), true );
            if ( is_array( $state ) ) {
                $cart_item_data['mr_tee_state'] = $state;
            }
        }

        if ( ! empty( $_POST['mr_tee_design_key'] ) ) {
            $design_key  = sanitize_text_field( wp_unslash( $_POST['mr_tee_design_key'] ) );
            $upload_dir  = wp_upload_dir();
            $mr_url      = trailingslashit( $upload_dir['baseurl'] ) . 'mr-tee-designs/' . $design_key;

            $cart_item_data['mr_tee_design_key']  = $design_key;
            $cart_item_data['mr_tee_preview_url'] = $mr_url . '/preview.png';
            $cart_item_data['mr_tee_print_url']   = $mr_url . '/frame_0.png';
            $cart_item_data['mr_tee_svg_url']     = $mr_url . '/frame_0_svg.svg';
        }

        if ( ! empty( $_POST['mr_tee_nbd_item_key'] ) ) {
            $nbd_key = sanitize_text_field( wp_unslash( $_POST['mr_tee_nbd_item_key'] ) );
            $cart_item_data['mr_tee_nbd_item_key'] = $nbd_key;
            if ( ! isset( $cart_item_data['nbd_item_meta_ds'] ) ) {
                $cart_item_data['nbd_item_meta_ds'] = array();
            }
            $cart_item_data['nbd_item_meta_ds']['nbd'] = $nbd_key;
        }

        // Ensure unique cart hash per customized item
        $cart_item_data['mr_tee_unique_hash'] = md5( wp_json_encode( $cart_item_data ) . microtime() );

        return $cart_item_data;
    }

    /**
     * Display human-readable customization details in Cart & Checkout.
     */
    public function display_cart_item_data( $item_data, $cart_item ) {
        if ( empty( $cart_item['mr_tee_state'] ) || ! is_array( $cart_item['mr_tee_state'] ) ) {
            return $item_data;
        }

        $state = $cart_item['mr_tee_state'];

        if ( ! empty( $state['colorName'] ) ) {
            $item_data[] = array(
                'key'   => 'Couleur T-shirt',
                'value' => sanitize_text_field( $state['colorName'] ),
            );
        }

        $headline = trim( ( isset( $state['line1Text'] ) ? $state['line1Text'] : '' ) . ' ' . ( isset( $state['line2Text'] ) ? $state['line2Text'] : '' ) );
        if ( ! empty( $headline ) ) {
            $item_data[] = array(
                'key'   => 'Texte personnalisé',
                'value' => sanitize_text_field( $headline ),
            );
        }

        if ( ! empty( $state['fontFamily'] ) ) {
            $item_data[] = array(
                'key'   => 'Police Fancy',
                'value' => sanitize_text_field( $state['fontFamily'] ),
            );
        }

        if ( isset( $state['repeaterCount'] ) ) {
            $item_data[] = array(
                'key'   => 'Nombre de champignons',
                'value' => (string) absint( $state['repeaterCount'] ),
            );
        }

        if ( ! empty( $state['items'] ) && is_array( $state['items'] ) ) {
            foreach ( $state['items'] as $item ) {
                $num  = isset( $item['number'] ) ? absint( $item['number'] ) : 1;
                $name = isset( $item['name'] ) ? sanitize_text_field( $item['name'] ) : '';
                $icon = isset( $item['iconName'] ) ? sanitize_text_field( $item['iconName'] ) : '';
                $item_data[] = array(
                    'key'   => sprintf( 'Champignon %d', $num ),
                    'value' => sprintf( '%s (%s)', $name, $icon ),
                );
            }
        }

        return $item_data;
    }

    /**
     * Replace Cart thumbnail with the customer's live composite t-shirt preview image.
     */
    public function override_cart_item_thumbnail( $thumbnail, $cart_item, $cart_item_key ) {
        if ( ! empty( $cart_item['mr_tee_preview_url'] ) ) {
            return sprintf(
                '<img src="%s" alt="Aperçu personnalisé" class="mr-tee-cart-thumb" style="width:80px;height:auto;border-radius:8px;border:1px solid #e2e8f0;" />',
                esc_url( $cart_item['mr_tee_preview_url'] )
            );
        }
        return $thumbnail;
    }

    /**
     * Save customization metadata & print asset URLs to the WooCommerce Order Line Item.
     */
    public function save_order_line_item_meta( $item, $cart_item_key, $values, $order ) {
        if ( ! empty( $values['mr_tee_state'] ) && is_array( $values['mr_tee_state'] ) ) {
            $state = $values['mr_tee_state'];
            $item->add_meta_data( '_mr_tee_state', $state, true );

            if ( ! empty( $state['colorName'] ) ) {
                $item->add_meta_data( 'Couleur T-shirt', sanitize_text_field( $state['colorName'] ), true );
            }
            $headline = trim( ( isset( $state['line1Text'] ) ? $state['line1Text'] : '' ) . ' ' . ( isset( $state['line2Text'] ) ? $state['line2Text'] : '' ) );
            if ( ! empty( $headline ) ) {
                $item->add_meta_data( 'Texte personnalisé', sanitize_text_field( $headline ), true );
            }
            if ( ! empty( $state['fontFamily'] ) ) {
                $item->add_meta_data( 'Police Fancy', sanitize_text_field( $state['fontFamily'] ), true );
            }
            if ( isset( $state['repeaterCount'] ) ) {
                $item->add_meta_data( 'Nombre de champignons', (string) absint( $state['repeaterCount'] ), true );
            }
            if ( ! empty( $state['items'] ) && is_array( $state['items'] ) ) {
                foreach ( $state['items'] as $sub ) {
                    $item->add_meta_data(
                        sprintf( 'Champignon %d', absint( $sub['number'] ) ),
                        sprintf( '%s (%s)', sanitize_text_field( $sub['name'] ), sanitize_text_field( $sub['iconName'] ) ),
                        true
                    );
                }
            }
        }

        if ( ! empty( $values['mr_tee_design_key'] ) ) {
            $item->add_meta_data( '_mr_tee_design_key', sanitize_text_field( $values['mr_tee_design_key'] ), true );
            $item->add_meta_data( '_mr_tee_preview_url', esc_url_raw( $values['mr_tee_preview_url'] ), true );
            $item->add_meta_data( '_mr_tee_print_url', esc_url_raw( $values['mr_tee_print_url'] ), true );
            $item->add_meta_data( '_mr_tee_svg_url', esc_url_raw( $values['mr_tee_svg_url'] ), true );
        }

        if ( ! empty( $values['mr_tee_nbd_item_key'] ) ) {
            $nbd_key = sanitize_text_field( $values['mr_tee_nbd_item_key'] );
            $item->add_meta_data( '_nbd', $nbd_key, true );
            $order->update_meta_data( '_nbd', 1 );
        }
    }

    /**
     * Render 1-Click Print Export Box inside WooCommerce Admin Order Details.
     */
    public function render_admin_order_item_print_panel( $item_id, $item, $product ) {
        if ( ! is_admin() || ! is_callable( array( $item, 'get_meta' ) ) ) {
            return;
        }

        $design_key  = $item->get_meta( '_mr_tee_design_key' );
        $preview_url = $item->get_meta( '_mr_tee_preview_url' );
        $print_url   = $item->get_meta( '_mr_tee_print_url' );
        $svg_url     = $item->get_meta( '_mr_tee_svg_url' );

        if ( empty( $design_key ) ) {
            return;
        }
        ?>
        <div class="mr-tee-admin-order-export-box" style="margin-top:12px;padding:12px 14px;background:#f8fafc;border:1px solid #cbd5e1;border-radius:8px;">
            <strong style="display:block;margin-bottom:8px;color:#1e40af;font-size:13px;">
                🖨️ Mr. Tee Editor — Fichiers d'Impression Prêts (300 DPI)
            </strong>
            <div style="display:flex;gap:14px;align-items:center;flex-wrap:wrap;">
                <?php if ( $preview_url ) : ?>
                    <a href="<?php echo esc_url( $preview_url ); ?>" target="_blank" title="Voir l'aperçu T-shirt">
                        <img src="<?php echo esc_url( $preview_url ); ?>" alt="Aperçu" style="width:70px;height:auto;border-radius:6px;border:1px solid #cbd5e1;background:#fff;" />
                    </a>
                <?php endif; ?>
                <?php if ( $print_url ) : ?>
                    <a href="<?php echo esc_url( $print_url ); ?>" target="_blank" title="Voir le calque transparent 300 DPI">
                        <img src="<?php echo esc_url( $print_url ); ?>" alt="Calque 300 DPI" style="width:70px;height:auto;border-radius:6px;border:1px dashed #94a3b8;background:#fff;" />
                    </a>
                <?php endif; ?>
                <div style="display:flex;flex-direction:column;gap:6px;">
                    <?php if ( $print_url ) : ?>
                        <a class="button button-primary button-small" href="<?php echo esc_url( $print_url ); ?>" download="mrtee-print-300dpi-<?php echo esc_attr( $design_key ); ?>.png">
                            ⬇️ Télécharger PNG Transparent 300 DPI
                        </a>
                    <?php endif; ?>
                    <?php if ( $svg_url ) : ?>
                        <a class="button button-secondary button-small" href="<?php echo esc_url( $svg_url ); ?>" download="mrtee-vector-<?php echo esc_attr( $design_key ); ?>.svg">
                            ⬇️ Télécharger SVG Vectoriel (Polices Intégrées)
                        </a>
                    <?php endif; ?>
                    <?php if ( $preview_url ) : ?>
                        <a class="button button-small" href="<?php echo esc_url( $preview_url ); ?>" download="mrtee-mockup-<?php echo esc_attr( $design_key ); ?>.png">
                            🖼️ Télécharger Aperçu T-Shirt Client
                        </a>
                    <?php endif; ?>
                </div>
            </div>
        </div>
        <?php
    }
}
