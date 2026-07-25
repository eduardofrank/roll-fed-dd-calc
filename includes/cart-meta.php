<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Shipping class slugs assigned to calculator cart items based on the
 * selected Product Mounting option. These must exist as WooCommerce
 * Product Shipping Classes (WooCommerce → Settings → Shipping → Classes)
 * with matching slugs so shipping zone rates can target them.
 */
define( 'FAC_SHIPPING_CLASS_ROLLED_PRINT', 'rolled-print' );
define( 'FAC_SHIPPING_CLASS_MOUNTED_FLAT', 'mounted-flat' );

/**
 * Build cart/checkout display rows from calculator data.
 *
 * @param array $calculator_data Full calculator_data cart meta.
 * @return array WooCommerce item_data rows.
 */
function fac_build_cart_item_display_rows( $calculator_data ) {
    $state      = $calculator_data['state'] ?? array();
    $results    = $calculator_data['results'] ?? array();
    $rows       = array();
    $calc_type  = ( $state['calculatorType'] ?? 'archival' ) === 'inkjet' ? 'inkjet' : 'archival';

    $rows[] = array(
        'key'   => __( 'Print Type', 'fine-art-calculator' ),
        'value' => $calc_type === 'inkjet'
            ? __( 'Inkjet', 'fine-art-calculator' )
            : __( 'Archival Museum Quality', 'fine-art-calculator' ),
    );

    if ( ! empty( $results['paperName'] ) ) {
        $paper_value = $calc_type === 'inkjet'
            ? $results['paperName']
            : $results['paperName'] . ' (' . ( $state['brand'] ?? '' ) . ' — ' . ( $state['finish'] ?? '' ) . ')';

        $rows[] = array(
            'key'   => __( 'Paper Style', 'fine-art-calculator' ),
            'value' => esc_html( $paper_value ),
        );
    }

    if ( ! empty( $state['width'] ) && ! empty( $state['height'] ) ) {
        $rows[] = array(
            'key'   => __( 'Dimensions', 'fine-art-calculator' ),
            'value' => esc_html( $state['width'] . ' × ' . $state['height'] . ' ' . ( $state['units'] ?? '' ) ),
        );
    }

    if ( ! empty( $state['rollKey'] ) ) {
        $rows[] = array(
            'key'   => __( 'Roll Width', 'fine-art-calculator' ),
            'value' => esc_html( $state['rollKey'] . '"' ),
        );
    }

    $mounting = $state['mounting'] ?? 'no_mounting';
    if ( $mounting !== 'no_mounting' ) {
        $labels = array(
            'white_gatorboard' => 'White Gatorboard',
            'black_gatorboard' => 'Black Gatorboard',
        );
        $rows[] = array(
            'key'   => __( 'Mounting Option', 'fine-art-calculator' ),
            'value' => esc_html( $labels[ $mounting ] ?? ucwords( str_replace( '_', ' ', $mounting ) ) ),
        );
    }

    $turnaround = $state['turnaround'] ?? 'standard';
    $rows[]     = array(
        'key'   => __( 'Turnaround', 'fine-art-calculator' ),
        'value' => $turnaround === 'rush'
            ? __( '3 Business Days (Rush)', 'fine-art-calculator' )
            : __( '5 Business Days', 'fine-art-calculator' ),
    );

    if ( isset( $results['nestingFactor'], $results['passes'] ) ) {
        $rows[] = array(
            'key'   => __( 'Nesting', 'fine-art-calculator' ),
            'value' => esc_html( $results['nestingFactor'] . ' per pass, ' . $results['passes'] . ' passes' ),
        );
    }

    /*
     * A short run is billed at the printer's minimum feed, so the paper charge
     * will not match the print size. Say so on the line rather than leaving the
     * studio and the customer to work out the discrepancy later.
     */
    if ( ! empty( $results['minLengthApplied'] ) ) {
        $rows[] = array(
            'key'   => __( 'Roll Feed', 'fine-art-calculator' ),
            'value' => esc_html(
                sprintf(
                    /* translators: %s: minimum print length in inches. */
                    __( '%s minimum (printer minimum print length)', 'fine-art-calculator' ),
                    fac_format_min_print_length( $state['units'] ?? 'inches' )
                )
            ),
        );
    }

    /*
     * A quote link with a negotiated price replaces the engine total, so the
     * per-component costs no longer sum to what is being charged. Showing them
     * would read as an arithmetic error, so the quoted total stands alone.
     */
    $custom_priced = ! empty( $results['customPriced'] );

    if ( $custom_priced ) {
        $rows[] = array(
            'key'   => __( 'Pricing', 'fine-art-calculator' ),
            'value' => __( 'Quoted by ArtMedia Studio', 'fine-art-calculator' ),
        );
    } else {
        if ( isset( $results['printCost'] ) ) {
            $rows[] = array(
                'key'   => __( 'Print Cost', 'fine-art-calculator' ),
                'value' => '$' . number_format( floatval( $results['printCost'] ), 2 ),
            );
        }

        if ( ! empty( $results['mountingCost'] ) ) {
            $rows[] = array(
                'key'   => __( 'Mounting Cost', 'fine-art-calculator' ),
                'value' => '$' . number_format( floatval( $results['mountingCost'] ), 2 ),
            );
        }

        $rush_label = fac_format_rush_fee_label( floatval( $results['subtotal'] ?? 0 ), $turnaround );
        if ( $rush_label ) {
            $rows[] = array(
                'key'   => __( 'Rush Fee', 'fine-art-calculator' ),
                'value' => $rush_label,
            );
        }
    }

    if ( isset( $results['estimatedWeight'], $results['weightUnit'] ) ) {
        $rows[] = array(
            'key'   => __( 'Est. Weight', 'fine-art-calculator' ),
            'value' => sprintf( '%.2f %s', floatval( $results['estimatedWeight'] ), esc_html( $results['weightUnit'] ) ),
        );
    }

    return $rows;
}

/**
 * Persist calculator data as order line item meta.
 *
 * @param WC_Order_Item_Product $item   Order line item.
 * @param array                 $calculator_data Full calculator_data.
 */
function fac_persist_order_item_meta( $item, $calculator_data ) {
    $state      = $calculator_data['state'] ?? array();
    $results    = $calculator_data['results'] ?? array();
    $calc_type  = ( $state['calculatorType'] ?? 'archival' ) === 'inkjet' ? 'inkjet' : 'archival';

    $item->add_meta_data(
        __( 'Print Type', 'fine-art-calculator' ),
        $calc_type === 'inkjet' ? 'Inkjet' : 'Archival Museum Quality',
        true
    );

    if ( ! empty( $results['paperName'] ) ) {
        $paper_value = $calc_type === 'inkjet'
            ? $results['paperName']
            : $results['paperName'] . ' (' . ( $state['brand'] ?? '' ) . ' — ' . ( $state['finish'] ?? '' ) . ')';

        $item->add_meta_data(
            __( 'Paper Style', 'fine-art-calculator' ),
            esc_html( $paper_value ),
            true
        );
    }

    if ( ! empty( $state['width'] ) && ! empty( $state['height'] ) ) {
        $item->add_meta_data(
            __( 'Dimensions', 'fine-art-calculator' ),
            esc_html( $state['width'] . ' × ' . $state['height'] . ' ' . ( $state['units'] ?? '' ) ),
            true
        );
    }

    if ( ! empty( $state['rollKey'] ) ) {
        $item->add_meta_data( __( 'Roll Width', 'fine-art-calculator' ), esc_html( $state['rollKey'] . '"' ), true );
    }

    $mounting = $state['mounting'] ?? 'no_mounting';
    if ( $mounting !== 'no_mounting' ) {
        $labels = array( 'white_gatorboard' => 'White Gatorboard', 'black_gatorboard' => 'Black Gatorboard' );
        $item->add_meta_data(
            __( 'Mounting Option', 'fine-art-calculator' ),
            esc_html( $labels[ $mounting ] ?? ucwords( str_replace( '_', ' ', $mounting ) ) ),
            true
        );
    }

    $turnaround = $state['turnaround'] ?? 'standard';
    $item->add_meta_data(
        __( 'Turnaround', 'fine-art-calculator' ),
        $turnaround === 'rush' ? '3 Business Days (Rush)' : '5 Business Days',
        true
    );

    if ( isset( $results['nestingFactor'], $results['passes'] ) ) {
        $item->add_meta_data(
            __( 'Nesting', 'fine-art-calculator' ),
            esc_html( $results['nestingFactor'] . ' per pass, ' . $results['passes'] . ' passes' ),
            true
        );
    }

    // Kept on the order too, so a short run reads the same on the invoice as it
    // did in the cart.
    if ( ! empty( $results['minLengthApplied'] ) ) {
        $item->add_meta_data(
            __( 'Roll Feed', 'fine-art-calculator' ),
            esc_html(
                sprintf(
                    /* translators: %s: minimum print length in inches. */
                    __( '%s minimum (printer minimum print length)', 'fine-art-calculator' ),
                    fac_format_min_print_length( $state['units'] ?? 'inches' )
                )
            ),
            true
        );
    }

    // See fac_build_cart_item_display_rows(): a quoted total replaces the
    // breakdown rather than sitting alongside figures that no longer add up.
    if ( empty( $results['customPriced'] ) ) {
        if ( isset( $results['printCost'] ) ) {
            $item->add_meta_data( 'Print Feed Cost', '$' . number_format( floatval( $results['printCost'] ), 2 ), true );
        }

        if ( ! empty( $results['mountingCost'] ) ) {
            $item->add_meta_data( 'Mounting Cost', '$' . number_format( floatval( $results['mountingCost'] ), 2 ), true );
        }

        $rush_label = fac_format_rush_fee_label( floatval( $results['subtotal'] ?? 0 ), $turnaround );
        if ( $rush_label ) {
            $item->add_meta_data( 'Rush Fee', $rush_label, true );
        }
    }

    // Records which quote link produced the sale, so the studio can tie an
    // order back to the conversation it came from.
    if ( ! empty( $calculator_data['quote']['label'] ) ) {
        $item->add_meta_data( 'Quote Link', $calculator_data['quote']['label'], true );
    }

    if ( isset( $results['estimatedWeight'], $results['weightUnit'] ) ) {
        $item->add_meta_data(
            __( 'Weight', 'fine-art-calculator' ),
            sprintf( '%.2f %s', floatval( $results['estimatedWeight'] ), esc_html( $results['weightUnit'] ) ),
            true
        );
    }

    if ( isset( $calculator_data['calculated_price'] ) ) {
        $item->add_meta_data( 'Final Calculated Price', '$' . number_format( floatval( $calculator_data['calculated_price'] ), 2 ), true );
    }

    $item->add_meta_data( '_calculator_state',   $state,   true );
    $item->add_meta_data( '_calculator_results', $results, true );
    $item->add_meta_data( '_calculator_data',    $calculator_data, true );
}

/**
 * Map a Product Mounting selection to its WooCommerce shipping class slug.
 *
 * - No Mounting                          -> rolled-print  (ships rolled in a tube)
 * - White Gatorboard / Black Gatorboard  -> mounted-flat  (ships flat / boxed)
 *
 * Unrecognized or missing values fall back to `rolled-print`, matching the
 * `no_mounting` default used throughout the rest of the calculator state.
 *
 * @param string $mounting Mounting key from calculator state.
 * @return string Shipping class slug.
 */
function fac_get_shipping_class_for_mounting( $mounting ) {
    if ( $mounting === 'white_gatorboard' || $mounting === 'black_gatorboard' ) {
        return FAC_SHIPPING_CLASS_MOUNTED_FLAT;
    }

    return FAC_SHIPPING_CLASS_ROLLED_PRINT;
}

/**
 * Resolve a `product_shipping_class` term slug to its term ID.
 *
 * @param string $slug Shipping class slug.
 * @return int Term ID, or 0 if no shipping class with that slug exists yet
 *             (e.g. it hasn't been created in WooCommerce → Settings →
 *             Shipping → Classes). Callers should treat 0 as "leave the
 *             cart item's shipping class unchanged".
 */
function fac_get_shipping_class_term_id( $slug ) {
    if ( ! $slug ) {
        return 0;
    }

    $term = get_term_by( 'slug', $slug, 'product_shipping_class' );

    return ( $term && ! is_wp_error( $term ) ) ? (int) $term->term_id : 0;
}

/* ================================================================
   CHECKOUT: re-validate calculator cart items from stored state
================================================================ */
add_action( 'woocommerce_check_cart_items', 'fac_validate_cart_calculator_quotes' );

/**
 * Re-quote calculator cart items from stored state before checkout.
 *
 * The price stored at add-to-cart goes stale when admin rates change and says
 * nothing about tampering. Items whose configuration no longer validates
 * (paper or roll removed) are dropped with an error notice; standard-price
 * drift beyond a cent is corrected to the fresh server quote with a notice.
 *
 * A cart item that came from a **negotiated / locked quote link** is exempt:
 * its price and configuration were resolved authoritatively by
 * fac_quote_resolve() at add-to-cart (from the stored quote items or a total
 * override), so re-quoting from state here would recompute the *standard*
 * price and silently overwrite the agreed number. Editable, standard-priced
 * quote items carry no such flag and are re-quoted like any normal item.
 *
 * @return void
 */
function fac_validate_cart_calculator_quotes() {
    if ( ! function_exists( 'WC' ) || null === WC()->cart ) {
        return;
    }

    foreach ( WC()->cart->cart_contents as $key => $cart_item ) {
        if ( ! isset( $cart_item['calculator_data']['state'] ) || ! is_array( $cart_item['calculator_data']['state'] ) ) {
            continue;
        }

        // Preserve a negotiated/locked quote's authoritative price.
        $quote_link = $cart_item['calculator_data']['quote'] ?? null;
        if ( is_array( $quote_link ) && ( ! empty( $quote_link['customPriced'] ) || ! empty( $quote_link['locked'] ) ) ) {
            continue;
        }

        // Re-measure the layout: the shopper may have rearranged the roll after
        // adding this line, and the layout that ships is the one that prints.
        $cart_item['calculator_data']['state'] = fac_apply_layout_feed_to_state(
            $cart_item['calculator_data']['state']
        );
        WC()->cart->cart_contents[ $key ]['calculator_data']['state'] = $cart_item['calculator_data']['state'];

        $results = fac_validate_calculator_state( $cart_item['calculator_data']['state'] );

        if ( is_wp_error( $results ) ) {
            WC()->cart->remove_cart_item( $key );
            wc_add_notice(
                __( 'A configured print in your cart is no longer available and was removed. Please configure it again.', 'fine-art-calculator' ),
                'error'
            );
            continue;
        }

        $fresh  = round( floatval( $results['totalPrice'] ), 2 );
        $stored = round( floatval( $cart_item['calculator_data']['calculated_price'] ?? 0 ), 2 );

        if ( abs( $fresh - $stored ) > 0.02 ) {
            WC()->cart->cart_contents[ $key ]['calculator_data']['calculated_price'] = $fresh;
            WC()->cart->cart_contents[ $key ]['calculator_data']['results']          = $results;
            wc_add_notice(
                sprintf(
                    /* translators: 1: old price, 2: new price */
                    __( 'Print prices were updated since you configured your order: $%1$s is now $%2$s.', 'fine-art-calculator' ),
                    number_format( $stored, 2 ),
                    number_format( $fresh, 2 )
                ),
                'notice'
            );
        }
    }
}
