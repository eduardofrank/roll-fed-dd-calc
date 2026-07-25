<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/* ================================================================
   FRONT-END: Add custom print to WooCommerce cart
   (merges logic from both supplied code snippets)
================================================================ */
add_action( 'wp_ajax_add_custom_fine_art_print_to_cart',        'fac_ajax_add_to_cart' );
add_action( 'wp_ajax_nopriv_add_custom_fine_art_print_to_cart', 'fac_ajax_add_to_cart' );

define( 'FAC_MAX_CART_PAYLOAD_BYTES', 25000 );
define( 'FAC_RATE_LIMIT_WINDOW_SECONDS', 60 );
define( 'FAC_RATE_LIMIT_MAX_REQUESTS', 40 );

/**
 * Build a consistent AJAX error payload.
 *
 * @param string $code    Machine-friendly error code.
 * @param string $message Human-readable message.
 * @return array<string,string>
 */
function fac_ajax_build_error_payload( $code, $message ) {
    return array(
        'code'    => sanitize_key( (string) $code ),
        'message' => (string) $message,
    );
}

/**
 * Send a JSON error response with explicit HTTP status code.
 *
 * @param string $code        Machine-friendly error code.
 * @param string $message     Human-readable message.
 * @param int    $status_code HTTP status code.
 * @return void
 */
function fac_ajax_send_error( $code, $message, $status_code = 400 ) {
    wp_send_json_error( fac_ajax_build_error_payload( $code, $message ), (int) $status_code );
}

/**
 * Write security-relevant event logs in debug mode.
 *
 * @param string $reason Short reason code.
 * @param array  $context Additional event context.
 * @return void
 */
function fac_security_log( $reason, $context = array() ) {
    fac_log_warning( 'Security: ' . sanitize_key( $reason ), $context );
}

/**
 * Best-effort request fingerprint for anonymous rate limiting.
 *
 * @return string
 */
function fac_request_fingerprint() {
    $ip = sanitize_text_field( $_SERVER['REMOTE_ADDR'] ?? 'unknown' );
    $ua = sanitize_text_field( $_SERVER['HTTP_USER_AGENT'] ?? '' );
    return md5( $ip . '|' . $ua );
}

/**
 * Return true when request should be throttled.
 *
 * @return bool
 */
function fac_is_rate_limited() {
    $key      = 'fac_rl_' . fac_request_fingerprint();
    $attempts = (int) get_transient( $key );
    $attempts++;
    set_transient( $key, $attempts, FAC_RATE_LIMIT_WINDOW_SECONDS );

    return $attempts > FAC_RATE_LIMIT_MAX_REQUESTS;
}

/**
 * Return true when posted product_data exceeds configured byte cap.
 *
 * @param string $raw_product_data Raw posted product_data.
 * @return bool
 */
function fac_ajax_product_data_too_large( $raw_product_data ) {
    return strlen( (string) $raw_product_data ) > FAC_MAX_CART_PAYLOAD_BYTES;
}

/**
 * Decode a JSON request field and return array payload.
 *
 * @param string $raw Raw request JSON string.
 * @return array|WP_Error
 */
function fac_ajax_decode_json_payload( $raw ) {
    $decoded = json_decode( wp_unslash( (string) $raw ), true );
    if ( json_last_error() !== JSON_ERROR_NONE || ! is_array( $decoded ) ) {
        return new WP_Error( 'invalid_json', 'Invalid JSON payload.' );
    }

    return $decoded;
}

function fac_ajax_add_to_cart() {
    if ( fac_is_rate_limited() ) {
        fac_security_log( 'rate_limited' );
        fac_ajax_send_error( 'rate_limited', 'Too many requests. Please wait a moment and try again.', 429 );
    }

    if ( ! isset( $_POST['product_data'] ) ) {
        fac_ajax_send_error( 'missing_product_data', 'No product data received.', 400 );
    }

    $raw_product_data = (string) ( $_POST['product_data'] ?? '' );
    if ( fac_ajax_product_data_too_large( $raw_product_data ) ) {
        fac_security_log( 'payload_too_large', array( 'bytes' => strlen( $raw_product_data ) ) );
        fac_ajax_send_error( 'payload_too_large', 'Request payload is too large.', 413 );
    }

    // CSRF protection for public cart endpoint.
    $nonce = isset( $_POST['nonce'] ) ? sanitize_text_field( wp_unslash( $_POST['nonce'] ) ) : '';
    if ( ! wp_verify_nonce( $nonce, 'fac_nonce' ) ) {
        fac_security_log( 'nonce_failed' );
        fac_ajax_send_error( 'nonce_failed', 'Security check failed. Please refresh the page and try again.', 403 );
    }

    $decoded = fac_ajax_decode_json_payload( $raw_product_data );
    if ( is_wp_error( $decoded ) ) {
        fac_ajax_send_error( 'invalid_json', 'Invalid JSON product data.', 400 );
    }

    $product_id      = absint( $decoded['product_id'] ?? 0 );
    $quantity        = max( 1, absint( $decoded['quantity'] ?? 1 ) );
    $calculator_data = $decoded['calculator_data'] ?? array();
    $state           = $calculator_data['state'] ?? array();

    /*
     * Measure the roll length from the layout the server already holds, and
     * overwrite anything the browser claimed about it. The length is a price
     * input, so it is established here rather than accepted.
     */
    $state = fac_apply_layout_feed_to_state( $state );

    /*
     * Quote links: substitute the stored configuration before anything else
     * reads $state.
     *
     * A locked link discards the posted state entirely. Checking only the price
     * would let a customer keep a negotiated number while enlarging the print,
     * so the whole state — size, paper, quantity, and branch — has to come from
     * the server. Pricing itself is still resolved further down, at the point
     * the endpoint has always validated, so error precedence is unchanged.
     */
    $quote       = null;
    $quote_token = sanitize_text_field( (string) ( $decoded['quote_token'] ?? '' ) );

    if ( $quote_token !== '' ) {
        $quote = fac_quote_get_by_token( $quote_token );

        if ( ! $quote ) {
            fac_security_log( 'quote_not_found' );
            fac_ajax_send_error( 'quote_not_found', 'This quote link is no longer available. Contact the studio for a new one.', 404 );
        }

        $usable = fac_quote_check_usable( $quote );
        if ( is_wp_error( $usable ) ) {
            fac_ajax_send_error( (string) $usable->get_error_code(), $usable->get_error_message(), 409 );
        }

        if ( fac_quote_is_locked( $quote ) ) {
            $state    = $quote['items'][0]['state'];
            $quantity = max( 1, (int) ( $state['quantity'] ?? 1 ) );
        }
    }

    // An editable multi-item link posts every print it covers.
    $client_items = isset( $decoded['calculator_data']['items'] ) && is_array( $decoded['calculator_data']['items'] )
        ? $decoded['calculator_data']['items']
        : null;

    if ( ! $product_id ) {
        fac_ajax_send_error( 'invalid_product_id', 'Invalid product ID.', 400 );
    }

    // Validate against the product ID set in the plugin settings
    $calc_type     = ( $state['calculatorType'] ?? 'archival' ) === 'inkjet' ? 'inkjet' : 'archival';
    $configured_id = fac_get_configured_product_id( $calc_type );
    if ( ! $configured_id ) {
        fac_ajax_send_error( 'calculator_not_configured', 'Calculator is not configured. Select a WooCommerce product in Roll Fed Calc → WooCommerce.', 409 );
    }
    if ( $product_id !== $configured_id ) {
        fac_security_log( 'product_id_mismatch', array( 'posted' => $product_id, 'expected' => $configured_id ) );
        fac_ajax_send_error( 'product_id_mismatch', 'Product ID mismatch.', 409 );
    }

    $product = function_exists( 'wc_get_product' ) ? wc_get_product( $product_id ) : null;
    if ( ! $product || ! $product->exists() || ! $product->is_purchasable() ) {
        fac_ajax_send_error( 'product_not_purchasable', 'Selected WooCommerce product is not purchasable.', 409 );
    }
    if ( ! $product->is_in_stock() ) {
        fac_ajax_send_error( 'product_out_of_stock', 'Selected WooCommerce product is out of stock.', 409 );
    }

    if ( ! fac_cart_quantity_matches_state( $quantity, $state ) ) {
        fac_ajax_send_error( 'quantity_mismatch', 'Quantity mismatch. Please refresh and try again.', 409 );
    }

    if ( ! function_exists( 'WC' ) || is_null( WC()->cart ) ) {
        fac_ajax_send_error( 'wc_cart_unavailable', 'WooCommerce cart is not initialised.', 503 );
    }

    // Server-side price recalculation — never trust client totals.
    $resolved = fac_quote_resolve( $quote, $state, $client_items );
    if ( is_wp_error( $resolved ) ) {
        fac_ajax_send_error( (string) $resolved->get_error_code(), $resolved->get_error_message(), 400 );
    }

    $state        = $resolved['state'];
    $results      = $resolved['results'];
    $server_price = $resolved['price'];

    /*
     * The client-price tripwire only means something for engine-priced requests.
     * A custom-priced link's total is dictated by the stored quote, so there is
     * no client number worth comparing it against.
     */
    if ( ! $resolved['customPriced'] ) {
        $client_price = isset( $calculator_data['calculated_price'] )
            ? round( floatval( $calculator_data['calculated_price'] ), 2 )
            : 0;

        if ( abs( $server_price - $client_price ) > 0.02 ) {
            fac_ajax_send_error(
                'price_mismatch',
                sprintf(
                    'Price mismatch detected (client: $%s, server: $%s). Please refresh and try again.',
                    number_format( $client_price, 2 ),
                    number_format( $server_price, 2 )
                ),
                409
            );
        }
    }

    /*
     * One cart line per item.
     *
     * WooCommerce derives a cart line's identity by hashing cart_item_data, so
     * two prints with identical options would hash the same and get merged into
     * one line at quantity 2 — priced as one. Seeding each line with its item
     * index keeps them distinct, which matters immediately: "two of the same
     * 10x10" is a normal thing to quote.
     */
    $added   = array();
    $line_no = 0;

    foreach ( $resolved['lines'] as $index => $line ) {
        $line_data = $calculator_data;

        // Persist the resolved state, not the posted one — for a locked link
        // these differ, and the cart must record what the studio actually sold.
        $line_data['state']            = $line['state'];
        $line_data['results']          = $line['results'];
        $line_data['calculated_price'] = $line['price'];
        unset( $line_data['items'] );

        if ( $quote ) {
            $line_data['quote'] = array(
                'id'           => (int) $quote['id'],
                'label'        => (string) $quote['label'],
                'locked'       => (bool) $resolved['locked'],
                'customPriced' => (bool) $resolved['customPriced'],
                'itemIndex'    => (int) $index,
                'itemCount'    => count( $resolved['lines'] ),
            );
        }

        $line_quantity = max( 1, (int) ( $line['state']['quantity'] ?? 1 ) );
        if ( ! $quote ) {
            $line_quantity = $quantity;
        }

        $cart_item_key = WC()->cart->add_to_cart(
            $product_id,
            $line_quantity,
            0,
            array(),
            array( 'calculator_data' => $line_data )
        );

        if ( ! $cart_item_key ) {
            fac_ajax_send_error(
                'wc_add_to_cart_failed',
                $line_no > 0
                    ? sprintf( 'Added %d of %d prints, then WooCommerce refused the rest. Check your cart before trying again.', $line_no, count( $resolved['lines'] ) )
                    : 'Could not add product to WooCommerce cart. Check stock or visibility.',
                409
            );
        }

        $added[] = $cart_item_key;
        $line_no++;
    }

    wp_send_json_success( array(
        'message'       => count( $added ) > 1
            ? sprintf( '%d prints added to cart successfully!', count( $added ) )
            : 'Product added to cart successfully!',
        'cart_url'      => wc_get_cart_url(),
        'cart_item_key' => $added[0],
        'cart_item_keys' => $added,
        'added'         => count( $added ),
    ) );
}

/* ================================================================
   FRONT-END: Set dynamic price AND weight before totals calculate
================================================================ */
add_action( 'woocommerce_before_calculate_totals', 'fac_set_custom_cart_item_price_and_weight', 10, 1 );

function fac_set_custom_cart_item_price_and_weight( $cart ) {
    if ( is_admin() && ! defined( 'DOING_AJAX' ) ) {
        return;
    }

    // Guard against infinite recursion from other plugins
    if ( did_action( 'woocommerce_before_calculate_totals' ) >= 2 ) {
        return;
    }

    foreach ( $cart->get_cart() as $cart_item ) {
        if ( ! isset( $cart_item['calculator_data'] ) ) {
            continue;
        }
        $data = $cart_item['calculator_data'];

        // Dynamic price — divide by qty so WooCommerce multiplies back correctly
        if ( isset( $data['calculated_price'] ) ) {
            $unit_price = floatval( $data['calculated_price'] ) / max( 1, intval( $cart_item['quantity'] ) );
            $cart_item['data']->set_price( $unit_price );
        }

        // Dynamic weight — divide by qty so WooCommerce multiplies back correctly
        if ( isset( $data['results']['estimatedWeight'] ) ) {
            $unit_weight = floatval( $data['results']['estimatedWeight'] ) / max( 1, intval( $cart_item['quantity'] ) );
            $cart_item['data']->set_weight( $unit_weight );
        }
    }
}

/* ================================================================
   FRONT-END: Assign shipping class dynamically based on Product Mounting
   - No Mounting                          -> rolled-print
   - White Gatorboard / Black Gatorboard  -> mounted-flat

   Every archival (or inkjet) order shares one WooCommerce product ID, so
   the shipping class can't just be set once on the product itself — it
   has to be computed per cart line, the same way price/weight are above.
   Runs on the same hook so the correct class (and therefore shipping
   rate) is in effect as soon as the item is added to the cart, and on
   every later totals recalculation (cart, checkout, shipping calculator).
================================================================ */
add_action( 'woocommerce_before_calculate_totals', 'fac_set_custom_cart_item_shipping_class', 10, 1 );

function fac_set_custom_cart_item_shipping_class( $cart ) {
    if ( is_admin() && ! defined( 'DOING_AJAX' ) ) {
        return;
    }

    // Guard against infinite recursion from other plugins.
    if ( did_action( 'woocommerce_before_calculate_totals' ) >= 2 ) {
        return;
    }

    foreach ( $cart->get_cart() as $cart_item ) {
        if ( ! isset( $cart_item['calculator_data'] ) || empty( $cart_item['data'] ) ) {
            continue;
        }

        $mounting           = $cart_item['calculator_data']['state']['mounting'] ?? 'no_mounting';
        $shipping_slug      = fac_get_shipping_class_for_mounting( $mounting );
        $shipping_class_id  = fac_get_shipping_class_term_id( $shipping_slug );

        // Only override when the shipping class actually exists; otherwise
        // leave whatever is already set on the product untouched.
        if ( $shipping_class_id ) {
            $cart_item['data']->set_shipping_class_id( $shipping_class_id );
        }
    }
}

/* ================================================================
   FRONT-END: Display print details on Cart & Checkout pages
================================================================ */
add_filter( 'woocommerce_get_item_data', 'fac_display_cart_item_data', 10, 2 );

function fac_display_cart_item_data( $item_data, $cart_item ) {
    if ( ! isset( $cart_item['calculator_data'] ) ) {
        return $item_data;
    }

    return array_merge( $item_data, fac_build_cart_item_display_rows( $cart_item['calculator_data'] ) );
}

/* ================================================================
   CHECKOUT: Save all calculator data permanently to the order item
================================================================ */
add_action( 'woocommerce_checkout_create_order_line_item', 'fac_save_data_to_order_item', 10, 4 );

function fac_save_data_to_order_item( $item, $cart_item_key, $values, $order ) {
    if ( ! isset( $values['calculator_data'] ) ) {
        return;
    }

    fac_persist_order_item_meta( $item, $values['calculator_data'] );
}

/* ================================================================
   ADMIN: Save WooCommerce product ID setting
================================================================ */
add_action( 'wp_ajax_fac_save_woo_product', 'fac_ajax_save_woo_product' );

function fac_ajax_save_woo_product() {
    check_ajax_referer( 'fac_admin_nonce', 'nonce' );

    if ( ! current_user_can( 'manage_options' ) ) {
        wp_send_json_error( 'Unauthorized' );
    }

    $archival_id = absint( $_POST['product_id'] ?? 0 );
    $inkjet_id   = absint( $_POST['inkjet_product_id'] ?? 0 );

    if ( $archival_id > 0 && get_post_type( $archival_id ) !== 'product' ) {
        wp_send_json_error( 'Invalid archival product selection.' );
    }
    if ( $inkjet_id > 0 && get_post_type( $inkjet_id ) !== 'product' ) {
        wp_send_json_error( 'Invalid inkjet product selection.' );
    }

    $digest = fac_sanitize_ops_digest_settings(
        array(
            'enabled'   => absint( $_POST['digest_enabled'] ?? 0 ),
            'recipient' => sanitize_text_field( wp_unslash( $_POST['digest_recipient'] ?? '' ) ),
        )
    );
    if ( is_wp_error( $digest ) ) {
        wp_send_json_error( $digest->get_error_message() );
    }

    update_option( 'fac_woocommerce_product_id', $archival_id );
    update_option( 'fac_inkjet_woocommerce_product_id', $inkjet_id );
    update_option( 'fac_ops_digest', $digest );

    wp_send_json_success( array(
        'product_id'        => $archival_id,
        'inkjet_product_id' => $inkjet_id,
        'ops_digest'        => $digest,
    ) );
}

/* ================================================================
   ADMIN: Search WooCommerce products for the product picker
================================================================ */
add_action( 'wp_ajax_fac_search_products', 'fac_ajax_search_products' );

function fac_ajax_search_products() {
    check_ajax_referer( 'fac_admin_nonce', 'nonce' );

    if ( ! current_user_can( 'manage_options' ) ) {
        wp_send_json_error( 'Unauthorized' );
    }

    if ( ! class_exists( 'WooCommerce' ) ) {
        wp_send_json_error( 'WooCommerce not active' );
    }

    $term = sanitize_text_field( $_GET['term'] ?? '' );

    $args = array(
        'post_type'      => 'product',
        'post_status'    => 'publish',
        'posts_per_page' => 20,
        'orderby'        => 'title',
        'order'          => 'ASC',
    );

    if ( $term ) {
        $args['s'] = $term;
    }

    $query    = new WP_Query( $args );
    $products = array();

    foreach ( $query->posts as $post ) {
        $products[] = array(
            'id'    => $post->ID,
            'title' => $post->post_title,
            'sku'   => get_post_meta( $post->ID, '_sku', true ),
            'price' => get_post_meta( $post->ID, '_price', true ),
        );
    }

    wp_send_json_success( $products );
}

/* ================================================================
   ADMIN: Save paper data
================================================================ */
add_action( 'wp_ajax_fac_save_paper_data', 'fac_ajax_save_paper_data' );

function fac_ajax_save_paper_data() {
    check_ajax_referer( 'fac_admin_nonce', 'nonce' );

    if ( ! current_user_can( 'manage_options' ) ) {
        wp_send_json_error( 'Unauthorized' );
    }

    $data = fac_ajax_decode_json_payload( $_POST['paper_data'] ?? '' );
    if ( is_wp_error( $data ) ) {
        wp_send_json_error( 'Invalid JSON' );
    }

    $sanitized = fac_sanitize_archival_paper_data( $data );
    if ( is_wp_error( $sanitized ) ) {
        wp_send_json_error( $sanitized->get_error_message() );
    }

    fac_update_option_audited( 'fac_paper_data', $sanitized );
    wp_send_json_success( 'Paper data saved.' );
}

/* ================================================================
   ADMIN: Save inkjet paper data
================================================================ */
add_action( 'wp_ajax_fac_save_inkjet_paper_data', 'fac_ajax_save_inkjet_paper_data' );

function fac_ajax_save_inkjet_paper_data() {
    check_ajax_referer( 'fac_admin_nonce', 'nonce' );

    if ( ! current_user_can( 'manage_options' ) ) {
        wp_send_json_error( 'Unauthorized' );
    }

    $data = fac_ajax_decode_json_payload( $_POST['paper_data'] ?? '' );
    if ( is_wp_error( $data ) ) {
        wp_send_json_error( 'Invalid JSON' );
    }

    $sanitized = fac_sanitize_inkjet_paper_data( $data );
    if ( is_wp_error( $sanitized ) ) {
        wp_send_json_error( $sanitized->get_error_message() );
    }

    fac_update_option_audited( 'fac_inkjet_paper_data', $sanitized );
    wp_send_json_success( 'Inkjet paper data saved.' );
}

/* ================================================================
   ADMIN: Save roll widths
================================================================ */
add_action( 'wp_ajax_fac_save_roll_widths', 'fac_ajax_save_roll_widths' );

function fac_ajax_save_roll_widths() {
    check_ajax_referer( 'fac_admin_nonce', 'nonce' );

    if ( ! current_user_can( 'manage_options' ) ) {
        wp_send_json_error( 'Unauthorized' );
    }

    $data = fac_ajax_decode_json_payload( $_POST['roll_widths'] ?? '' );
    if ( is_wp_error( $data ) ) {
        wp_send_json_error( 'Invalid JSON' );
    }

    $sanitized = fac_sanitize_roll_widths_data( $data );
    if ( is_wp_error( $sanitized ) ) {
        wp_send_json_error( $sanitized->get_error_message() );
    }

    fac_update_option_audited( 'fac_roll_widths', $sanitized );
    wp_send_json_success( 'Roll widths saved.' );
}

/* ================================================================
   ADMIN: Save mounting & turnaround rates
================================================================ */
add_action( 'wp_ajax_fac_save_rates', 'fac_ajax_save_rates' );

function fac_ajax_save_rates() {
    check_ajax_referer( 'fac_admin_nonce', 'nonce' );

    if ( ! current_user_can( 'manage_options' ) ) {
        wp_send_json_error( 'Unauthorized' );
    }

    $mounting = fac_ajax_decode_json_payload( $_POST['mounting_rates'] ?? '' );
    if ( is_wp_error( $mounting ) ) {
        wp_send_json_error( 'Invalid mounting rates payload.' );
    }

    $turnaround = fac_ajax_decode_json_payload( $_POST['turnaround_rates'] ?? '' );
    if ( is_wp_error( $turnaround ) ) {
        wp_send_json_error( 'Invalid turnaround rates payload.' );
    }

    $sanitized = fac_sanitize_rates_data( $mounting, $turnaround );
    if ( is_wp_error( $sanitized ) ) {
        wp_send_json_error( $sanitized->get_error_message() );
    }

    fac_update_option_audited( 'fac_mounting_rates', $sanitized['mounting'] );
    fac_update_option_audited( 'fac_turnaround_rates', $sanitized['turnaround'] );

    wp_send_json_success( 'Rates saved.' );
}

/* ================================================================
   ADMIN: Save paper image URLs (flat slug → URL map)
   Called by the Paper Options admin page when imageUrl is saved
   as part of a paper object — the flat map is rebuilt from
   fac_paper_data automatically via fac_get_paper_images().
   This endpoint handles direct overrides from the Paper Images
   admin page if needed in the future.
================================================================ */
add_action( 'wp_ajax_fac_save_paper_images', 'fac_ajax_save_paper_images' );

function fac_ajax_save_paper_images() {
    check_ajax_referer( 'fac_admin_nonce', 'nonce' );

    if ( ! current_user_can( 'manage_options' ) ) {
        wp_send_json_error( 'Unauthorized' );
    }

    $raw  = isset( $_POST['paper_images'] ) ? wp_unslash( $_POST['paper_images'] ) : '';
    $data = json_decode( $raw, true );

    if ( ! is_array( $data ) ) {
        wp_send_json_error( 'Invalid JSON' );
    }

    // Sanitize: only keep non-empty URL strings keyed by slug
    $clean = array();
    foreach ( $data as $slug => $url ) {
        $slug = sanitize_key( $slug );
        $url  = esc_url_raw( trim( $url ) );
        if ( $slug && $url ) {
            $clean[ $slug ] = $url;
        }
    }

    update_option( 'fac_paper_images', $clean );
    wp_send_json_success( array( 'saved' => count( $clean ) ) );
}

/* ================================================================
   CHECKOUT: Record quote link redemptions

   Counted when the order is created rather than at add-to-cart, so an
   abandoned cart never burns a single-use link. Counted once per order even
   if the same link produced several lines.
================================================================ */
add_action( 'woocommerce_checkout_order_processed', 'fac_quote_record_order_usage', 10, 3 );

function fac_quote_record_order_usage( $order_id, $posted_data, $order = null ) {
    if ( ! $order && function_exists( 'wc_get_order' ) ) {
        $order = wc_get_order( $order_id );
    }
    if ( ! $order ) {
        return;
    }

    $counted = array();

    foreach ( $order->get_items() as $item ) {
        $data     = $item->get_meta( '_calculator_data', true );
        $quote_id = (int) ( $data['quote']['id'] ?? 0 );

        if ( ! $quote_id || isset( $counted[ $quote_id ] ) ) {
            continue;
        }

        $counted[ $quote_id ] = true;
        fac_quote_mark_used( $quote_id, (int) $order_id );
    }
}

/* ================================================================
   ADMIN: Quote links — create / update
================================================================ */
add_action( 'wp_ajax_fac_save_quote', 'fac_ajax_save_quote' );

function fac_ajax_save_quote() {
    check_ajax_referer( 'fac_admin_nonce', 'nonce' );

    if ( ! current_user_can( 'manage_options' ) ) {
        wp_send_json_error( 'Unauthorized' );
    }

    $raw = fac_ajax_decode_json_payload( $_POST['quote'] ?? '' );
    if ( is_wp_error( $raw ) ) {
        wp_send_json_error( 'Invalid JSON' );
    }

    $input = fac_quote_sanitize_input( $raw );
    if ( is_wp_error( $input ) ) {
        wp_send_json_error( $input->get_error_message() );
    }

    $saved = fac_quote_save( $input, absint( $raw['id'] ?? 0 ) );
    if ( is_wp_error( $saved ) ) {
        fac_log_error(
            'Quote save failed',
            array( 'code' => $saved->get_error_code(), 'quote_id' => absint( $raw['id'] ?? 0 ) )
        );
        wp_send_json_error( $saved->get_error_message() );
    }

    wp_send_json_success( array(
        'savedId' => $saved,
        'quotes'  => fac_quote_list(),
    ) );
}

/* ================================================================
   ADMIN: Quote links — delete
================================================================ */
add_action( 'wp_ajax_fac_delete_quote', 'fac_ajax_delete_quote' );

function fac_ajax_delete_quote() {
    check_ajax_referer( 'fac_admin_nonce', 'nonce' );

    if ( ! current_user_can( 'manage_options' ) ) {
        wp_send_json_error( 'Unauthorized' );
    }

    $deleted = fac_quote_delete( absint( $_POST['quote_id'] ?? 0 ) );
    if ( is_wp_error( $deleted ) ) {
        wp_send_json_error( $deleted->get_error_message() );
    }

    wp_send_json_success( array( 'quotes' => fac_quote_list() ) );
}

/* ================================================================
   ADMIN: Quote links — turn on / off without deleting
================================================================ */
add_action( 'wp_ajax_fac_toggle_quote', 'fac_ajax_toggle_quote' );

function fac_ajax_toggle_quote() {
    check_ajax_referer( 'fac_admin_nonce', 'nonce' );

    if ( ! current_user_can( 'manage_options' ) ) {
        wp_send_json_error( 'Unauthorized' );
    }

    $quote_id = absint( $_POST['quote_id'] ?? 0 );
    $quote    = fac_quote_hydrate( $quote_id );

    if ( ! $quote ) {
        wp_send_json_error( 'That quote link no longer exists.' );
    }

    $status = ( $quote['status'] === 'active' ) ? 'disabled' : 'active';
    update_post_meta( $quote_id, '_fac_quote_status', $status );

    wp_send_json_success( array(
        'status' => $status,
        'quotes' => fac_quote_list(),
    ) );
}

/* ================================================================
   ADMIN: Quote links — engine price preview

   The admin form asks the server for the price instead of recomputing it in
   admin JS. Adding a third copy of the nesting algorithm (after PHP and the
   React bundle) would be a third thing to keep in sync.
================================================================ */
add_action( 'wp_ajax_fac_quote_preview', 'fac_ajax_quote_preview' );

function fac_ajax_quote_preview() {
    check_ajax_referer( 'fac_admin_nonce', 'nonce' );

    if ( ! current_user_can( 'manage_options' ) ) {
        wp_send_json_error( 'Unauthorized' );
    }

    $raw = fac_ajax_decode_json_payload( $_POST['state'] ?? '' );
    if ( is_wp_error( $raw ) ) {
        wp_send_json_error( 'Invalid JSON' );
    }

    $type  = ( ( $raw['calculatorType'] ?? 'archival' ) === 'inkjet' ) ? 'inkjet' : 'archival';
    $state = fac_quote_sanitize_state( $raw, $type );

    if ( is_wp_error( $state ) ) {
        wp_send_json_error( $state->get_error_message() );
    }

    $results = fac_calculate_price( $state );

    wp_send_json_success( array(
        'totalPrice'      => round( floatval( $results['totalPrice'] ), 2 ),
        'printCost'       => round( floatval( $results['printCost'] ), 2 ),
        'mountingCost'    => round( floatval( $results['mountingCost'] ), 2 ),
        'paperName'       => $results['paperName'],
        'nestingFactor'   => (int) $results['nestingFactor'],
        'passes'          => (int) $results['passes'],
        'estimatedWeight' => round( floatval( $results['estimatedWeight'] ), 3 ),
        'weightUnit'      => $results['weightUnit'],
    ) );
}
