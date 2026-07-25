<?php
if ( ! defined( 'ABSPATH' ) ) exit;

add_shortcode( 'fine_art_calculator_embed', 'fac_render_archival_shortcode' );
add_shortcode( 'inkjet_calculator_embed', 'fac_render_inkjet_shortcode' );

function fac_render_archival_shortcode( $atts ) {
    return fac_render_calculator_shortcode( 'archival' );
}

function fac_render_inkjet_shortcode( $atts ) {
    return fac_render_calculator_shortcode( 'inkjet' );
}

/**
 * Enqueue assets and render calculator root for a branch.
 *
 * @param string $type archival|inkjet
 * @return string
 */
function fac_render_calculator_shortcode( $type ) {
    $type = $type === 'inkjet' ? 'inkjet' : 'archival';

    // Tell the rest of the plugin a calculator really rendered here, and where.
    // Nothing else can know it reliably: a page builder keeps its layout out of
    // post_content, so scanning for the shortcode finds nothing.
    fac_quote_note_render( $type );

    /*
     * Quote links arrive as ?fac_quote=TOKEN. A token that can't be honoured
     * (expired, disabled, already spent, or pointed at the wrong calculator)
     * degrades to a normal calculator with an explanation rather than a dead
     * page — the customer can still buy, just at the engine price.
     */
    $quote        = null;
    $quote_notice = '';

    if ( isset( $_GET[ FAC_QUOTE_QUERY_VAR ] ) ) {
        $candidate = fac_quote_get_by_token( wp_unslash( $_GET[ FAC_QUOTE_QUERY_VAR ] ) );

        if ( ! $candidate ) {
            $quote_notice = __( 'That quote link is no longer available. Configure your print below, or contact the studio for a new link.', 'fine-art-calculator' );
        } elseif ( $candidate['calculatorType'] !== $type ) {
            $quote_notice = __( 'That quote link is for a different calculator. Configure your print below, or contact the studio for a new link.', 'fine-art-calculator' );
        } else {
            $usable = fac_quote_check_usable( $candidate );

            if ( is_wp_error( $usable ) ) {
                $quote_notice = $usable->get_error_message();
            } else {
                $quote = $candidate;
            }
        }
    }

    /*
     * Authoring mode renders the quote list and an admin nonce into the page.
     * Logged-in requests bypass most page caches already, but say so explicitly
     * rather than depend on every host's cache being configured that way.
     */
    if ( fac_quote_admin_mode_active() ) {
        nocache_headers();
    }

    wp_enqueue_style(
        'fac-calculator-css',
        FAC_PLUGIN_URL . 'assets/calculator.css',
        array(),
        FAC_VERSION
    );

    // Print Layout Planner styles. Depends on the calculator stylesheet only for
    // ordering — it inherits the --fac-* design tokens defined there.
    wp_enqueue_style(
        'fac-layout-planner-css',
        FAC_PLUGIN_URL . 'assets/layout-planner.css',
        array( 'fac-calculator-css' ),
        FAC_VERSION
    );

    wp_enqueue_script(
        'fac-data-bridge',
        FAC_PLUGIN_URL . 'assets/data-bridge.js',
        array(),
        FAC_VERSION,
        true
    );

    wp_localize_script( 'fac-data-bridge', 'facData', fac_build_js_data( $type, $quote, $quote_notice ) );

    wp_enqueue_script(
        'fac-calculator-js',
        FAC_PLUGIN_URL . 'assets/calculator.js',
        array( 'fac-data-bridge' ),
        FAC_VERSION,
        true
    );

    // Companion planner. Loads after the React bundle so the __FAC_* globals and
    // the calculator DOM exist; it self-gates and polls for the mounted UI, then
    // renders into #fac-layout-planner (a sibling of #root, never inside it).
    wp_enqueue_script(
        'fac-layout-planner-js',
        FAC_PLUGIN_URL . 'assets/layout-planner.js',
        array( 'fac-calculator-js' ),
        FAC_VERSION,
        true
    );

    $product_id = fac_get_configured_product_id( $type );
    $paper_images = $type === 'inkjet' ? array() : fac_get_paper_images();

    ob_start();
    ?>
    <script type="text/javascript">
        window.wp_ajax_url            = '<?php echo esc_url( admin_url( 'admin-ajax.php' ) ); ?>';
        window.woocommerce_product_id = <?php echo (int) $product_id; ?>;
        window.wp_paper_images        = <?php echo wp_json_encode( $paper_images ); ?>;
        window.fac_artwork            = {
            ajaxUrl:    '<?php echo esc_url( admin_url( 'admin-ajax.php' ) ); ?>',
            nonce:      '<?php echo esc_js( wp_create_nonce( 'fac_artwork_nonce' ) ); ?>',
            maxBytes:   <?php echo (int) fac_artwork_max_file_bytes(); ?>,
            maxFiles:   <?php echo (int) FAC_ARTWORK_MAX_FILES; ?>,
            chunkBytes: <?php echo (int) fac_artwork_chunk_bytes(); ?>
        };
        // Whether the roll length the shopper actually lays out drives the
        // price. Off until the React bundle computes the same figure — see
        // FAC_LAYOUT_DRIVEN_PRICING in includes/pricing.php.
        window.__FAC_LAYOUT_PRICING = <?php echo FAC_LAYOUT_DRIVEN_PRICING ? 'true' : 'false'; ?>;
    </script>

    <style>
      #root.fac,
      #root.fac > *,
      .fac__main,
      .fac__grid {
        overflow: visible !important;
      }
    </style>
    <?php
    /*
     * The image-based Print Layout Planner is a customer-facing tool: visitors
     * lay their own artwork out on the roll and it drives the single quantity
     * field. Quote-authoring mode uses multi-print tabs instead, so the planner
     * would fight that workflow — skip its container there. The script also
     * self-gates on locked/custom-priced quote links.
     *
     * The container is emitted BEFORE #root so the planner leads the form. The
     * planner script polls for the mounted calculator rather than assuming it
     * already exists, so coming first in the DOM is safe.
     */
    if ( ! fac_quote_admin_mode_active() ) {
        echo '<div id="fac-layout-planner"></div>';
    }
    ?>
    <div id="root" class="fac" data-calculator-type="<?php echo esc_attr( $type ); ?>"></div>
    <?php
    return ob_get_clean();
}
