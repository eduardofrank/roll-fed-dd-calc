<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Quote Links — admin-configured calculator states shared with a prospective
 * client as a single URL.
 *
 * A quote link stores one or more *items* — each a complete calculator state
 * with an optional negotiated price — plus the terms they are offered under:
 *
 *   - editable       Customer may change the configuration before buying.
 *   - totalOverride  Package price for the whole quote, replacing the sum.
 *   - reusable       Link survives its first purchase.
 *   - expires        Optional last day the link works.
 *   - status         Manual on/off switch, independent of expiry.
 *
 * Each item additionally carries its own optional customPrice.
 *
 * Any negotiated number — an item price or a total override — forces the link
 * to be locked (editable = false). If the studio has quoted a figure, the
 * customer must not be able to move the options it was quoted against. The rule
 * is enforced twice: once in fac_quote_sanitize_input() when the link is saved,
 * and again in fac_quote_resolve() when it is redeemed, so a record edited
 * directly in the database still can't price customer-chosen options.
 *
 * When a total override is set the line prices are apportioned across the items
 * in proportion to what each is worth, with the rounding remainder absorbed by
 * the last line so the lines always sum to the quoted total exactly — see
 * fac_quote_apportion().
 *
 * Stored as a `fac_quote` custom post type rather than a single serialized
 * option (the pattern the paper/roll/rate settings use) because links accumulate
 * indefinitely and need per-record lookup by token.
 */

define( 'FAC_QUOTE_POST_TYPE', 'fac_quote' );
define( 'FAC_QUOTE_QUERY_VAR', 'fac_quote' );
define( 'FAC_QUOTE_MAX_PRICE', 1000000 );
define( 'FAC_QUOTE_MAX_ITEMS', 25 );

/* ================================================================
   Storage: custom post type
================================================================ */

add_action( 'init', 'fac_register_quote_post_type' );

/**
 * Register the quote link post type.
 *
 * Not public and not exposed in the WordPress admin UI — quote links are managed
 * entirely through Roll Fed Calc → Quote Links, so they never appear as an
 * editable post type or a front-end URL of their own.
 *
 * @return void
 */
function fac_register_quote_post_type() {
    register_post_type(
        FAC_QUOTE_POST_TYPE,
        array(
            'labels'              => array(
                'name'          => __( 'Quote Links', 'fine-art-calculator' ),
                'singular_name' => __( 'Quote Link', 'fine-art-calculator' ),
            ),
            'public'              => false,
            'publicly_queryable'  => false,
            'show_ui'             => false,
            'show_in_menu'        => false,
            'show_in_rest'        => false,
            'exclude_from_search' => true,
            'has_archive'         => false,
            'rewrite'             => false,
            'query_var'           => false,
            'supports'            => array( 'title' ),
            'capability_type'     => 'post',
            'map_meta_cap'        => true,
        )
    );
}

/**
 * Generate an unguessable quote token.
 *
 * A token is effectively a purchase credential when the link carries a custom
 * price, so this uses a CSPRNG rather than anything derived from the post ID.
 *
 * @return string 32-character hex token.
 */
function fac_quote_generate_token() {
    return bin2hex( random_bytes( 16 ) );
}

/* ================================================================
   Validation / sanitization (pure — unit tested)
================================================================ */

/**
 * Sanitize and validate a quote link payload from the admin form.
 *
 * @param mixed $raw Raw decoded payload.
 * @return array|WP_Error Normalized quote input, or error describing the fix.
 */
function fac_quote_sanitize_input( $raw ) {
    if ( ! is_array( $raw ) ) {
        return new WP_Error( 'invalid_quote', 'Quote data must be an object.' );
    }

    $label = sanitize_text_field( (string) ( $raw['label'] ?? '' ) );
    if ( $label === '' ) {
        return new WP_Error( 'quote_label_required', 'Add a label so you can find this link later.' );
    }

    $type = ( ( $raw['calculatorType'] ?? 'archival' ) === 'inkjet' ) ? 'inkjet' : 'archival';

    /*
     * Accepts either the multi-item shape or the single-state shape a v2.6
     * client would post, so an older cached bundle can't start rejecting saves
     * mid-deploy.
     */
    $raw_items = $raw['items'] ?? null;
    if ( ! is_array( $raw_items ) ) {
        $raw_items = array(
            array(
                'state'          => $raw['state'] ?? array(),
                'hasCustomPrice' => ! empty( $raw['hasCustomPrice'] ),
                'customPrice'    => $raw['customPrice'] ?? 0,
            ),
        );
    }

    $items = fac_quote_sanitize_items( $raw_items, $type );
    if ( is_wp_error( $items ) ) {
        return $items;
    }

    $total_override = '';
    if ( ! empty( $raw['hasTotalOverride'] ) ) {
        $total_override = round( floatval( $raw['totalOverride'] ?? 0 ), 2 );

        if ( $total_override <= 0 ) {
            return new WP_Error( 'quote_total_invalid', 'Enter a package total above zero, or turn off the total override.' );
        }
        if ( $total_override > FAC_QUOTE_MAX_PRICE ) {
            return new WP_Error( 'quote_total_too_large', 'Package total exceeds the supported maximum.' );
        }
    }

    // Any negotiated figure locks the link. See the file header.
    $negotiated = ( $total_override !== '' ) || fac_quote_items_have_custom_price( $items );
    $editable   = $negotiated ? false : ! empty( $raw['editable'] );

    $expires = fac_quote_sanitize_date( $raw['expires'] ?? '' );
    if ( is_wp_error( $expires ) ) {
        return $expires;
    }

    return array(
        'label'          => $label,
        'calculatorType' => $type,
        'items'          => $items,
        'totalOverride'  => $total_override,
        'editable'       => $editable,
        'reusable'       => ! empty( $raw['reusable'] ),
        'expires'        => $expires,
        'status'         => ( ( $raw['status'] ?? 'active' ) === 'disabled' ) ? 'disabled' : 'active',
        'pageId'         => absint( $raw['pageId'] ?? 0 ),
    );
}

/**
 * Whether any item on the quote carries a negotiated price.
 *
 * @param array<int,array> $items Sanitized items.
 * @return bool
 */
function fac_quote_items_have_custom_price( $items ) {
    foreach ( (array) $items as $item ) {
        if ( isset( $item['customPrice'] ) && $item['customPrice'] !== '' && floatval( $item['customPrice'] ) > 0 ) {
            return true;
        }
    }

    return false;
}

/**
 * Sanitize a calculator state for storage on a quote link.
 *
 * Runs the state through the same validator the public cart endpoint uses, so a
 * link can never be created for a job the calculator itself would refuse to
 * sell (dimensions past the roll width, gatorboard over 48x96, a paper that
 * isn't stocked on the chosen roll).
 *
 * @param mixed  $raw  Raw state payload.
 * @param string $type archival|inkjet
 * @return array|WP_Error
 */
function fac_quote_sanitize_state( $raw, $type ) {
    if ( ! is_array( $raw ) || empty( $raw ) ) {
        return new WP_Error( 'invalid_quote_state', 'Configure the print options before saving this link.' );
    }

    $state = array(
        'calculatorType'    => $type,
        'rollKey'           => sanitize_key( (string) ( $raw['rollKey'] ?? '' ) ),
        'brand'             => sanitize_text_field( (string) ( $raw['brand'] ?? '' ) ),
        'finish'            => sanitize_text_field( (string) ( $raw['finish'] ?? '' ) ),
        'selectedPaperSlug' => sanitize_key( (string) ( $raw['selectedPaperSlug'] ?? '' ) ),
        'units'             => ( ( $raw['units'] ?? 'inches' ) === 'centimeters' ) ? 'centimeters' : 'inches',
        'width'             => (string) floatval( $raw['width'] ?? 0 ),
        'height'            => (string) floatval( $raw['height'] ?? 0 ),
        'quantity'          => max( 1, absint( $raw['quantity'] ?? 1 ) ),
        'mounting'          => sanitize_key( (string) ( $raw['mounting'] ?? 'no_mounting' ) ),
        'turnaround'        => sanitize_key( (string) ( $raw['turnaround'] ?? 'standard' ) ),
    );

    if ( $type === 'inkjet' ) {
        $state['inkjetCategory'] = sanitize_key( (string) ( $raw['inkjetCategory'] ?? '' ) );
        $state['brand']          = '';
        $state['finish']         = '';
    }

    $results = fac_validate_calculator_state( $state );
    if ( is_wp_error( $results ) ) {
        return $results;
    }

    return $state;
}

/**
 * Validate an optional expiry date.
 *
 * @param mixed $raw Raw Y-m-d string, or empty for no expiry.
 * @return string|WP_Error Normalized date or empty string.
 */
function fac_quote_sanitize_date( $raw ) {
    $raw = trim( (string) $raw );

    if ( $raw === '' ) {
        return '';
    }

    $date = DateTime::createFromFormat( 'Y-m-d', $raw );
    if ( ! $date || $date->format( 'Y-m-d' ) !== $raw ) {
        return new WP_Error( 'quote_date_invalid', 'Use the date picker to set an expiry date, or leave it empty for no expiry.' );
    }

    return $raw;
}

/**
 * Sanitize the list of items on a quote.
 *
 * @param mixed  $raw  Raw items payload.
 * @param string $type archival|inkjet
 * @return array|WP_Error
 */
function fac_quote_sanitize_items( $raw, $type ) {
    if ( ! is_array( $raw ) || empty( $raw ) ) {
        return new WP_Error( 'quote_no_items', 'Add at least one print to this quote.' );
    }

    if ( count( $raw ) > FAC_QUOTE_MAX_ITEMS ) {
        return new WP_Error(
            'quote_too_many_items',
            sprintf( 'A quote can hold at most %d prints.', FAC_QUOTE_MAX_ITEMS )
        );
    }

    $items = array();

    foreach ( array_values( $raw ) as $index => $raw_item ) {
        if ( ! is_array( $raw_item ) ) {
            return new WP_Error( 'quote_bad_item', 'One of the prints on this quote is malformed.' );
        }

        $state = fac_quote_sanitize_state( $raw_item['state'] ?? array(), $type );
        if ( is_wp_error( $state ) ) {
            // Say which one, or the studio is left guessing across five tabs.
            return new WP_Error(
                $state->get_error_code(),
                sprintf( 'Print %d: %s', $index + 1, $state->get_error_message() )
            );
        }

        $custom_price = '';
        if ( ! empty( $raw_item['hasCustomPrice'] ) ) {
            $custom_price = round( floatval( $raw_item['customPrice'] ?? 0 ), 2 );

            if ( $custom_price <= 0 ) {
                return new WP_Error(
                    'quote_price_invalid',
                    sprintf( 'Print %d: enter a custom price above zero, or turn off custom pricing for it.', $index + 1 )
                );
            }
            if ( $custom_price > FAC_QUOTE_MAX_PRICE ) {
                return new WP_Error(
                    'quote_price_too_large',
                    sprintf( 'Print %d: custom price exceeds the supported maximum.', $index + 1 )
                );
            }
        }

        $items[] = array(
            'state'       => $state,
            'customPrice' => $custom_price,
        );
    }

    return $items;
}

/**
 * What a single item is worth: its negotiated price, or the engine price.
 *
 * @param array $item Sanitized item.
 * @return float
 */
function fac_quote_item_price( $item ) {
    if ( isset( $item['customPrice'] ) && $item['customPrice'] !== '' && floatval( $item['customPrice'] ) > 0 ) {
        return round( floatval( $item['customPrice'] ), 2 );
    }

    $results = fac_calculate_price( $item['state'] );

    return round( floatval( $results['totalPrice'] ), 2 );
}

/**
 * Split a package total across lines in proportion to what each is worth.
 *
 * WooCommerce prices per line, so a package total has to become line prices.
 * Rounding each line independently would drift away from the quoted figure, so
 * the last line absorbs the remainder: the returned prices always sum to
 * $total exactly, to the cent.
 *
 * @param float             $total   Quoted package total.
 * @param array<int,float>  $weights Per-line value, in line order.
 * @return array<int,float> Per-line prices summing to $total.
 */
function fac_quote_apportion( $total, $weights ) {
    $count = count( $weights );

    if ( $count === 0 ) {
        return array();
    }
    if ( $count === 1 ) {
        return array( round( (float) $total, 2 ) );
    }

    $sum = array_sum( $weights );
    $out = array();

    // All lines worthless (or a zero total) — split evenly rather than divide by zero.
    if ( $sum <= 0 ) {
        $each = round( $total / $count, 2 );
        for ( $i = 0; $i < $count - 1; $i++ ) {
            $out[] = $each;
        }
        $out[] = round( $total - ( $each * ( $count - 1 ) ), 2 );

        return $out;
    }

    $running = 0.0;
    for ( $i = 0; $i < $count - 1; $i++ ) {
        $line     = round( $total * ( $weights[ $i ] / $sum ), 2 );
        $running += $line;
        $out[]    = $line;
    }
    $out[] = round( $total - $running, 2 );

    return $out;
}

/* ================================================================
   Terms (pure — unit tested)
================================================================ */

/**
 * Whether the link carries any studio-negotiated figure — an item price or a
 * package total.
 *
 * @param array $quote Hydrated quote.
 * @return bool
 */
function fac_quote_has_custom_price( $quote ) {
    if ( isset( $quote['totalOverride'] ) && $quote['totalOverride'] !== '' && floatval( $quote['totalOverride'] ) > 0 ) {
        return true;
    }

    return fac_quote_items_have_custom_price( $quote['items'] ?? array() );
}

/**
 * Whether the customer is prevented from changing the configuration.
 *
 * Any negotiated figure forces this true regardless of the stored `editable`
 * flag, so a record edited outside the authoring UI can't hand out a quoted
 * price on options the customer picked themselves.
 *
 * @param array $quote Hydrated quote.
 * @return bool
 */
function fac_quote_is_locked( $quote ) {
    if ( fac_quote_has_custom_price( $quote ) ) {
        return true;
    }

    return empty( $quote['editable'] );
}

/**
 * Whether a quote link may still be redeemed.
 *
 * @param array       $quote Hydrated quote.
 * @param string|null $now   Y-m-d date to test against; defaults to site time.
 * @return true|WP_Error True when usable, otherwise an error explaining why not.
 */
function fac_quote_check_usable( $quote, $now = null ) {
    if ( ! is_array( $quote ) || empty( $quote ) ) {
        return new WP_Error( 'quote_not_found', 'This quote link is no longer available. Contact the studio for a new one.' );
    }

    if ( ( $quote['status'] ?? 'active' ) !== 'active' ) {
        return new WP_Error( 'quote_disabled', 'This quote link has been turned off. Contact the studio for a new one.' );
    }

    $expires = (string) ( $quote['expires'] ?? '' );
    if ( $expires !== '' ) {
        $now = ( $now === null ) ? current_time( 'Y-m-d' ) : (string) $now;
        // Expiry is inclusive: the link works through the end of that day.
        if ( $now > $expires ) {
            return new WP_Error( 'quote_expired', 'This quote link expired. Contact the studio for a new one.' );
        }
    }

    if ( empty( $quote['reusable'] ) && (int) ( $quote['uses'] ?? 0 ) > 0 ) {
        return new WP_Error( 'quote_used', 'This quote link has already been used. Contact the studio for a new one.' );
    }

    return true;
}

/**
 * Resolve the authoritative lines and total for a cart request.
 *
 * For a locked link the client's posted configuration is discarded entirely and
 * the stored items are used instead — checking only the price would let a
 * customer keep a negotiated number while enlarging the print.
 *
 * @param array|null $quote         Hydrated quote, or null for a normal cart request.
 * @param array      $client_state  State posted by the browser (single-item shape).
 * @param array|null $client_items  Items posted by the browser, for an editable
 *                                  multi-item link. Falls back to $client_state.
 * @return array|WP_Error {
 *     @type array $lines        Each { state, results, price }.
 *     @type float $total        Sum of the line prices.
 *     @type bool  $locked
 *     @type bool  $customPriced
 *     @type array $state        Alias of lines[0]['state'] — single-line callers.
 *     @type array $results      Alias of lines[0]['results'].
 *     @type float $price        Alias of $total.
 * }
 */
function fac_quote_resolve( $quote, $client_state, $client_items = null ) {
    if ( empty( $quote ) ) {
        $results = fac_validate_calculator_state( $client_state );
        if ( is_wp_error( $results ) ) {
            return $results;
        }

        $price = round( floatval( $results['totalPrice'] ), 2 );

        return fac_quote_resolution(
            array( array( 'state' => $client_state, 'results' => $results, 'price' => $price ) ),
            $price,
            false,
            false
        );
    }

    $locked = fac_quote_is_locked( $quote );

    if ( $locked ) {
        $items = $quote['items'];
    } else {
        // An editable link still has to describe the same number of prints —
        // the customer may adjust them, not add or drop them.
        $posted = is_array( $client_items ) && ! empty( $client_items )
            ? $client_items
            : array( array( 'state' => $client_state ) );

        if ( count( $posted ) !== count( $quote['items'] ) ) {
            return new WP_Error( 'quote_item_count', 'This quote covers a different number of prints. Please refresh and try again.' );
        }

        $items = array();
        foreach ( array_values( $posted ) as $index => $posted_item ) {
            $items[] = array(
                'state' => is_array( $posted_item['state'] ?? null ) ? $posted_item['state'] : array(),
                // Item prices are never client-supplied; an editable link has none.
                'customPrice' => '',
            );
        }
    }

    $lines   = array();
    $weights = array();

    foreach ( $items as $index => $item ) {
        $results = fac_validate_calculator_state( $item['state'] );

        if ( is_wp_error( $results ) ) {
            if ( $locked ) {
                // The stored configuration stopped validating after the link was
                // created — usually a paper removed from the catalogue or taken
                // off this roll width. Fail loudly rather than silently repricing.
                return new WP_Error(
                    'quote_stale',
                    'This quote link uses options that are no longer available. Contact the studio for an updated link.'
                );
            }

            return new WP_Error(
                $results->get_error_code(),
                count( $items ) > 1
                    ? sprintf( 'Print %d: %s', $index + 1, $results->get_error_message() )
                    : $results->get_error_message()
            );
        }

        $price     = fac_quote_item_price( $item );
        $weights[] = $price;
        $lines[]   = array( 'state' => $item['state'], 'results' => $results, 'price' => $price );
    }

    $custom_priced = fac_quote_has_custom_price( $quote );
    $override      = ( isset( $quote['totalOverride'] ) && $quote['totalOverride'] !== '' )
        ? round( floatval( $quote['totalOverride'] ), 2 )
        : null;

    if ( $override !== null ) {
        // A package total replaces the sum; spread it back over the lines so
        // WooCommerce can price each one and they still add up to the figure
        // the customer was quoted.
        $apportioned = fac_quote_apportion( $override, $weights );
        foreach ( $apportioned as $index => $line_price ) {
            $lines[ $index ]['price'] = $line_price;
        }
        $total = $override;
    } else {
        $total = round( array_sum( array_column( $lines, 'price' ) ), 2 );
    }

    // Mark up the per-line results so cart display drops a breakdown that would
    // no longer sum to what is being charged.
    if ( $custom_priced ) {
        foreach ( $lines as $index => $line ) {
            $lines[ $index ]['results']['totalPrice']   = $line['price'];
            $lines[ $index ]['results']['customPriced'] = true;
        }
    }

    return fac_quote_resolution( $lines, $total, $locked, $custom_priced );
}

/**
 * Shape a resolution, with single-line aliases for callers that predate
 * multi-item quotes.
 *
 * @param array $lines
 * @param float $total
 * @param bool  $locked
 * @param bool  $custom_priced
 * @return array
 */
function fac_quote_resolution( $lines, $total, $locked, $custom_priced ) {
    return array(
        'lines'        => $lines,
        'total'        => round( (float) $total, 2 ),
        'locked'       => (bool) $locked,
        'customPriced' => (bool) $custom_priced,
        'state'        => $lines[0]['state'] ?? array(),
        'results'      => $lines[0]['results'] ?? array(),
        'price'        => round( (float) $total, 2 ),
    );
}

/* ================================================================
   Storage layer
================================================================ */

/**
 * Create or update a quote link.
 *
 * @param array $input    Output of fac_quote_sanitize_input().
 * @param int   $quote_id Existing quote ID, or 0 to create.
 * @return int|WP_Error Quote ID.
 */
function fac_quote_save( $input, $quote_id = 0 ) {
    $postarr = array(
        'post_type'   => FAC_QUOTE_POST_TYPE,
        'post_title'  => $input['label'],
        'post_status' => 'publish',
    );

    if ( $quote_id > 0 ) {
        if ( get_post_type( $quote_id ) !== FAC_QUOTE_POST_TYPE ) {
            return new WP_Error( 'quote_not_found', 'That quote link no longer exists.' );
        }
        $postarr['ID'] = $quote_id;
        $saved_id      = wp_update_post( $postarr, true );
    } else {
        $saved_id = wp_insert_post( $postarr, true );
    }

    if ( is_wp_error( $saved_id ) ) {
        return $saved_id;
    }

    $saved_id = (int) $saved_id;

    if ( ! get_post_meta( $saved_id, '_fac_quote_token', true ) ) {
        update_post_meta( $saved_id, '_fac_quote_token', fac_quote_generate_token() );
    }
    if ( get_post_meta( $saved_id, '_fac_quote_uses', true ) === '' ) {
        update_post_meta( $saved_id, '_fac_quote_uses', 0 );
    }

    update_post_meta( $saved_id, '_fac_quote_type', $input['calculatorType'] );
    update_post_meta( $saved_id, '_fac_quote_items', $input['items'] );
    update_post_meta( $saved_id, '_fac_quote_total_override', $input['totalOverride'] );

    // Legacy single-state keys, written by versions before multi-item quotes.
    // Cleared so fac_quote_hydrate() never falls back to a stale copy.
    delete_post_meta( $saved_id, '_fac_quote_state' );
    delete_post_meta( $saved_id, '_fac_quote_custom_price' );
    update_post_meta( $saved_id, '_fac_quote_editable', $input['editable'] ? '1' : '0' );
    update_post_meta( $saved_id, '_fac_quote_reusable', $input['reusable'] ? '1' : '0' );
    update_post_meta( $saved_id, '_fac_quote_expires', $input['expires'] );
    update_post_meta( $saved_id, '_fac_quote_status', $input['status'] );
    update_post_meta( $saved_id, '_fac_quote_page_id', $input['pageId'] );

    return $saved_id;
}

/**
 * Build a quote array from a post ID.
 *
 * @param int $quote_id Quote post ID.
 * @return array|null
 */
function fac_quote_hydrate( $quote_id ) {
    $quote_id = (int) $quote_id;

    if ( ! $quote_id || get_post_type( $quote_id ) !== FAC_QUOTE_POST_TYPE ) {
        return null;
    }

    $items = fac_quote_read_items( $quote_id );
    if ( ! $items ) {
        return null;
    }

    $override = get_post_meta( $quote_id, '_fac_quote_total_override', true );

    return array(
        'id'             => $quote_id,
        'label'          => (string) get_post_field( 'post_title', $quote_id ),
        'token'          => (string) get_post_meta( $quote_id, '_fac_quote_token', true ),
        'calculatorType' => get_post_meta( $quote_id, '_fac_quote_type', true ) === 'inkjet' ? 'inkjet' : 'archival',
        'items'          => $items,
        'totalOverride'  => ( $override === '' || $override === null ) ? '' : floatval( $override ),
        'editable'       => get_post_meta( $quote_id, '_fac_quote_editable', true ) === '1',
        'reusable'       => get_post_meta( $quote_id, '_fac_quote_reusable', true ) === '1',
        'expires'        => (string) get_post_meta( $quote_id, '_fac_quote_expires', true ),
        'status'         => get_post_meta( $quote_id, '_fac_quote_status', true ) === 'disabled' ? 'disabled' : 'active',
        'uses'           => (int) get_post_meta( $quote_id, '_fac_quote_uses', true ),
        'pageId'         => (int) get_post_meta( $quote_id, '_fac_quote_page_id', true ),
    );
}

/**
 * Read a quote's items, upgrading the pre-multi-item shape on the fly.
 *
 * Links created before multi-item quotes stored one `_fac_quote_state` and one
 * `_fac_quote_custom_price`. Those links are already out in customers' inboxes,
 * so they are read as a single-item quote rather than migrated destructively —
 * the new keys are only written when the link is next saved.
 *
 * @param int $quote_id
 * @return array<int,array>|null
 */
function fac_quote_read_items( $quote_id ) {
    $items = get_post_meta( $quote_id, '_fac_quote_items', true );

    if ( is_array( $items ) && ! empty( $items ) ) {
        $clean = array();
        foreach ( $items as $item ) {
            if ( ! is_array( $item ) || ! isset( $item['state'] ) || ! is_array( $item['state'] ) ) {
                continue;
            }
            $clean[] = array(
                'state'       => $item['state'],
                'customPrice' => ( ( $item['customPrice'] ?? '' ) === '' ) ? '' : floatval( $item['customPrice'] ),
            );
        }

        return $clean ? $clean : null;
    }

    $legacy_state = get_post_meta( $quote_id, '_fac_quote_state', true );
    if ( ! is_array( $legacy_state ) ) {
        return null;
    }

    $legacy_price = get_post_meta( $quote_id, '_fac_quote_custom_price', true );

    return array(
        array(
            'state'       => $legacy_state,
            'customPrice' => ( $legacy_price === '' || $legacy_price === null ) ? '' : floatval( $legacy_price ),
        ),
    );
}

/**
 * Look up a quote link by its public token.
 *
 * @param string $token Token from the link URL.
 * @return array|null
 */
function fac_quote_get_by_token( $token ) {
    $token = sanitize_text_field( (string) $token );

    // Tokens are always 32 hex characters; reject anything else before querying.
    if ( ! preg_match( '/^[a-f0-9]{32}$/', $token ) ) {
        return null;
    }

    $posts = get_posts(
        array(
            'post_type'        => FAC_QUOTE_POST_TYPE,
            'post_status'      => 'publish',
            'posts_per_page'   => 1,
            'no_found_rows'    => true,
            'suppress_filters' => false,
            'meta_key'         => '_fac_quote_token',
            'meta_value'       => $token,
            'fields'           => 'ids',
        )
    );

    if ( empty( $posts ) ) {
        return null;
    }

    return fac_quote_hydrate( $posts[0] );
}

/**
 * Quote links, newest first, for the authoring table.
 *
 * @param string $type Optional archival|inkjet filter. Each calculator page
 *                     lists only its own links.
 * @return array<int,array>
 */
function fac_quote_list( $type = '' ) {
    $posts = get_posts(
        array(
            'post_type'      => FAC_QUOTE_POST_TYPE,
            'post_status'    => 'publish',
            'posts_per_page' => 200,
            'orderby'        => 'date',
            'order'          => 'DESC',
            'no_found_rows'  => true,
            'fields'         => 'ids',
        )
    );

    $quotes = array();
    foreach ( $posts as $post_id ) {
        $quote = fac_quote_hydrate( $post_id );

        if ( ! $quote ) {
            continue;
        }
        if ( $type !== '' && $quote['calculatorType'] !== $type ) {
            continue;
        }

        $quotes[] = fac_quote_for_admin( $quote );
    }

    return $quotes;
}

/**
 * Delete a quote link permanently.
 *
 * @param int $quote_id Quote ID.
 * @return true|WP_Error
 */
function fac_quote_delete( $quote_id ) {
    $quote_id = (int) $quote_id;

    if ( ! $quote_id || get_post_type( $quote_id ) !== FAC_QUOTE_POST_TYPE ) {
        return new WP_Error( 'quote_not_found', 'That quote link no longer exists.' );
    }

    $deleted = wp_delete_post( $quote_id, true );

    if ( ! $deleted ) {
        return new WP_Error( 'quote_delete_failed', 'Could not remove that quote link. Try again.' );
    }

    return true;
}

/**
 * Record a redemption against a quote link.
 *
 * Called when an order is created rather than at add-to-cart, so an abandoned
 * cart never burns a single-use link.
 *
 * @param int $quote_id Quote ID.
 * @param int $order_id Order the quote was redeemed on.
 * @return void
 */
function fac_quote_mark_used( $quote_id, $order_id ) {
    $quote_id = (int) $quote_id;

    if ( ! $quote_id || get_post_type( $quote_id ) !== FAC_QUOTE_POST_TYPE ) {
        return;
    }

    $uses = (int) get_post_meta( $quote_id, '_fac_quote_uses', true );
    update_post_meta( $quote_id, '_fac_quote_uses', $uses + 1 );

    $orders = get_post_meta( $quote_id, '_fac_quote_orders', true );
    $orders = is_array( $orders ) ? $orders : array();

    if ( ! in_array( (int) $order_id, $orders, true ) ) {
        $orders[] = (int) $order_id;
        update_post_meta( $quote_id, '_fac_quote_orders', $orders );
    }
}

/* ================================================================
   Presentation helpers
================================================================ */

/**
 * Public URL for a quote link.
 *
 * @param array $quote Hydrated quote.
 * @return string Empty string when no target page is set.
 */
function fac_quote_build_url( $quote ) {
    $page_id = (int) ( $quote['pageId'] ?? 0 );

    if ( ! $page_id ) {
        return '';
    }

    $permalink = get_permalink( $page_id );
    if ( ! $permalink ) {
        return '';
    }

    return add_query_arg( FAC_QUOTE_QUERY_VAR, $quote['token'], $permalink );
}

/**
 * Shape a quote for the admin table, adding derived display fields.
 *
 * @param array $quote Hydrated quote.
 * @return array
 */
function fac_quote_for_admin( $quote ) {
    $usable = fac_quote_check_usable( $quote );
    $items  = array();
    $engine = 0.0;

    foreach ( $quote['items'] as $item ) {
        $results  = fac_calculate_price( $item['state'] );
        $line     = round( floatval( $results['totalPrice'] ), 2 );
        $engine  += $line;
        $items[]  = array(
            'state'       => $item['state'],
            'customPrice' => $item['customPrice'],
            'enginePrice' => $line,
            'price'       => fac_quote_item_price( $item ),
            'paperName'   => $results['paperName'],
        );
    }

    $quote['itemsView']   = $items;
    $quote['itemCount']   = count( $items );
    $quote['url']         = fac_quote_build_url( $quote );
    $quote['locked']      = fac_quote_is_locked( $quote );
    $quote['enginePrice'] = round( $engine, 2 );
    $quote['effectivePrice'] = ( $quote['totalOverride'] !== '' )
        ? round( floatval( $quote['totalOverride'] ), 2 )
        : round( array_sum( array_column( $items, 'price' ) ), 2 );
    $quote['usable']      = ( $usable === true );
    $quote['usableNote']  = ( $usable === true ) ? '' : $usable->get_error_message();

    return $quote;
}

/**
 * Published pages available as a quote link target, flagged with whether they
 * actually contain the matching calculator shortcode.
 *
 * @param string $type archival|inkjet
 * @return array<int,array{id:int,title:string,hasShortcode:bool}>
 */
function fac_quote_get_target_pages( $type = 'archival' ) {
    $shortcode = ( $type === 'inkjet' ) ? 'inkjet_calculator_embed' : 'fine_art_calculator_embed';
    $pages     = get_pages( array( 'post_status' => 'publish' ) );
    $options   = array();

    foreach ( $pages as $page ) {
        $options[] = array(
            'id'           => (int) $page->ID,
            'title'        => $page->post_title,
            'hasShortcode' => has_shortcode( (string) $page->post_content, $shortcode ),
        );
    }

    return $options;
}

/**
 * Quote payload handed to the front-end calculator.
 *
 * Only usable links produce a payload — an expired or disabled link falls back
 * to a normal calculator with a notice rather than a dead page.
 *
 * @param array|null $quote Hydrated quote.
 * @return array|null
 */
function fac_quote_build_js_payload( $quote ) {
    if ( empty( $quote ) ) {
        return null;
    }

    $items  = array();
    $prices = array();

    foreach ( $quote['items'] as $item ) {
        $price    = fac_quote_item_price( $item );
        $prices[] = $price;
        $items[]  = array(
            'state'        => $item['state'],
            'customPrice'  => $item['customPrice'],
            'price'        => $price,
            'customPriced' => ( $item['customPrice'] !== '' && floatval( $item['customPrice'] ) > 0 ),
        );
    }

    $override = ( $quote['totalOverride'] !== '' ) ? round( floatval( $quote['totalOverride'] ), 2 ) : null;

    if ( $override !== null ) {
        // Show each line what it will actually be charged, not its share of a
        // sum the customer is not paying.
        foreach ( fac_quote_apportion( $override, $prices ) as $index => $line_price ) {
            $items[ $index ]['price']        = $line_price;
            $items[ $index ]['customPriced'] = true;
        }
    }

    return array(
        'token'         => $quote['token'],
        'label'         => $quote['label'],
        'items'         => $items,
        'locked'        => fac_quote_is_locked( $quote ),
        'customPriced'  => fac_quote_has_custom_price( $quote ),
        'totalOverride' => $override,
        'price'         => ( $override !== null ) ? $override : round( array_sum( $prices ), 2 ),
    );
}

/* ================================================================
   Front-end authoring mode

   Quote links are configured in the real calculator rather than a
   back-office replica of it: the admin sees exactly the screen the
   customer will see, priced by the same engine. The wp-admin submenus
   are just doors into this page.
================================================================ */

define( 'FAC_QUOTE_ADMIN_VAR', 'fac_quote_admin' );

/**
 * Whether the current request is an admin authoring a quote link.
 *
 * The capability check is the security boundary for the entire authoring
 * surface — every caller of this decides whether to expose the quote list,
 * the admin nonce, and the terms controls.
 *
 * @return bool
 */
function fac_quote_admin_mode_active() {
    return isset( $_GET[ FAC_QUOTE_ADMIN_VAR ] )
        && $_GET[ FAC_QUOTE_ADMIN_VAR ] === '1'
        && is_user_logged_in()
        && current_user_can( 'manage_options' );
}

/**
 * Record that a calculator actually rendered on this request.
 *
 * Called from the shortcode, which is the only place that knows for certain.
 * Everything else — post content, page scans — is inference, and inference
 * breaks the moment a page builder is involved: Oxygen keeps its structure in
 * its own meta and renders through a template that isn't the post at all, so
 * the shortcode never appears in post_content and never will.
 *
 * @param string $type archival|inkjet
 * @return void
 */
function fac_quote_note_render( $type ) {
    $GLOBALS['fac_rendered_calculator_type'] = $type;

    /*
     * Memoise against the queried object, not the loop post: that's the ID the
     * admin bar sees on a later request, and the one get_permalink() should
     * resolve to. Inside an Oxygen template the loop can be somewhere else
     * entirely, so the two are not interchangeable.
     */
    $post_id = (int) get_queried_object_id();
    if ( ! $post_id ) {
        $post_id = (int) get_the_ID();
    }
    if ( ! $post_id ) {
        return;
    }

    /*
     * Remember where it rendered so the wp-admin submenus have somewhere to
     * send an admin. Self-discovering: whatever page, product, or builder
     * template the shortcode lives on, one front-end view teaches the plugin
     * where it is. Only written when it changes, so this isn't a write on
     * every request.
     */
    $option = 'fac_calculator_location_' . ( $type === 'inkjet' ? 'inkjet' : 'archival' );
    if ( (int) get_option( $option, 0 ) !== $post_id ) {
        update_option( $option, $post_id );
    }
}

/**
 * Which calculator, if any, rendered on this request.
 *
 * @return string archival|inkjet, or '' when no calculator rendered.
 */
function fac_quote_rendered_calculator_type() {
    return $GLOBALS['fac_rendered_calculator_type'] ?? '';
}

/**
 * Which calculator, if any, the current page embeds — best effort, before the
 * content has rendered.
 *
 * Only sees a shortcode sitting literally in post_content. A page builder that
 * stores its structure elsewhere will not be detected here, which is why the
 * admin bar also gets a late pass once the shortcode has actually run — see
 * fac_quote_admin_bar_late().
 *
 * @return string archival|inkjet, or ''.
 */
function fac_quote_detect_calculator_type() {
    $detected = '';

    // 1. A calculator has already rendered on this request. Only true for late
    //    callers, but when it's true it's certain.
    $rendered = fac_quote_rendered_calculator_type();
    if ( $rendered ) {
        $detected = $rendered;
    }

    /*
     * 2. This is a page a calculator has been seen rendering on before.
     *
     * This is what makes the admin bar work on a page builder without relying
     * on hook order: once any front-end view has been through the shortcode,
     * the branch is known by post ID from that point on — no content to scan,
     * nothing to infer, and available early enough for admin_bar_menu.
     */
    if ( ! $detected && is_singular() ) {
        $post_id = (int) get_queried_object_id();

        if ( $post_id ) {
            foreach ( array( 'archival', 'inkjet' ) as $branch ) {
                if ( (int) get_option( 'fac_calculator_location_' . $branch, 0 ) === $post_id ) {
                    $detected = $branch;
                    break;
                }
            }
        }
    }

    // 3. The shortcode sits literally in the post content — a plain page.
    if ( ! $detected && is_singular() ) {
        $post = get_post();

        if ( $post ) {
            if ( has_shortcode( (string) $post->post_content, 'inkjet_calculator_embed' ) ) {
                $detected = 'inkjet';
            } elseif ( has_shortcode( (string) $post->post_content, 'fine_art_calculator_embed' ) ) {
                $detected = 'archival';
            }
        }
    }

    /**
     * Filter the detected calculator branch for the current request.
     *
     * An escape hatch for setups where none of the above identifies the page —
     * return 'archival' or 'inkjet' to force it.
     *
     * @param string $detected archival|inkjet, or '' for none.
     */
    return apply_filters( 'fac_quote_calculator_type', $detected );
}

/**
 * Display name for a calculator branch, taken from its WooCommerce product.
 *
 * Falls back to a generic label when no product is configured yet, so the
 * admin bar never renders "Edit" with nothing after it.
 *
 * @param string $type archival|inkjet
 * @return string
 */
function fac_quote_product_name( $type ) {
    $product_id = fac_get_configured_product_id( $type );

    if ( $product_id && function_exists( 'wc_get_product' ) ) {
        $product = wc_get_product( $product_id );
        if ( $product ) {
            return $product->get_name();
        }
    }

    return ( $type === 'inkjet' )
        ? __( 'Inkjet Print', 'fine-art-calculator' )
        : __( 'Archival Print', 'fine-art-calculator' );
}

/**
 * Front-end URL that opens a calculator in quote authoring mode.
 *
 * Prefers where the calculator was last seen rendering, because that is fact
 * rather than inference — it works whether the shortcode sits on a plain page,
 * a WooCommerce product, or inside an Oxygen or Elementor template, none of
 * which a post_content scan can see. Falls back to scanning pages so a fresh
 * install has an answer before anyone has viewed the calculator once.
 *
 * @param string $type archival|inkjet
 * @return string Empty when the calculator hasn't been located yet.
 */
function fac_quote_admin_entry_url( $type ) {
    $type = ( $type === 'inkjet' ) ? 'inkjet' : 'archival';
    $url  = '';

    $known = (int) get_option( 'fac_calculator_location_' . $type, 0 );
    if ( $known && get_post_status( $known ) === 'publish' ) {
        $permalink = get_permalink( $known );
        if ( $permalink ) {
            $url = $permalink;
        }
    }

    if ( ! $url ) {
        foreach ( fac_quote_get_target_pages( $type ) as $page ) {
            if ( $page['hasShortcode'] ) {
                $permalink = get_permalink( $page['id'] );
                if ( $permalink ) {
                    $url = $permalink;
                    break;
                }
            }
        }
    }

    /**
     * Filter the front-end URL the Quote Links submenu opens.
     *
     * @param string $url  Resolved URL, or '' when the calculator hasn't been located.
     * @param string $type archival|inkjet
     */
    $url = apply_filters( 'fac_quote_entry_url', $url, $type );

    return $url ? add_query_arg( FAC_QUOTE_ADMIN_VAR, '1', $url ) : '';
}

/**
 * Authoring payload for the front-end calculator.
 *
 * Returns null for everyone who isn't an administrator in authoring mode —
 * this is what keeps the quote list and the admin nonce off a public page.
 *
 * @param string $type archival|inkjet
 * @return array|null
 */
function fac_quote_build_admin_payload( $type ) {
    if ( ! fac_quote_admin_mode_active() ) {
        return null;
    }

    return array(
        'active'      => true,
        'productName' => fac_quote_product_name( $type ),
        'pageId'      => (int) get_the_ID(),
        'quotes'      => fac_quote_list( $type ),
        'nonce'       => wp_create_nonce( 'fac_admin_nonce' ),
        'ajaxUrl'     => admin_url( 'admin-ajax.php' ),
        'exitUrl'     => remove_query_arg( FAC_QUOTE_ADMIN_VAR ),
    );
}

/* ================================================================
   Admin bar entry point
================================================================ */

add_action( 'admin_bar_menu', 'fac_quote_admin_bar_menu', 80 );
add_action( 'wp_footer', 'fac_quote_admin_bar_late', 999 );

/**
 * Add "Edit {product}" to the front-end admin bar on calculator pages.
 *
 * Runs before the content does, so it can only act on what post_content shows.
 * fac_quote_admin_bar_late() picks up everything this misses.
 *
 * @param WP_Admin_Bar $wp_admin_bar
 * @return void
 */
function fac_quote_admin_bar_menu( $wp_admin_bar ) {
    if ( is_admin() || ! current_user_can( 'manage_options' ) ) {
        return;
    }

    $type = fac_quote_detect_calculator_type();
    if ( ! $type ) {
        return;
    }

    fac_quote_add_admin_bar_node( $wp_admin_bar, $type );
}

/**
 * Second chance at the admin bar, once the shortcode has actually run.
 *
 * admin_bar_menu fires on template_redirect — long before the content renders —
 * so a calculator inside a page builder's template is invisible to it. The
 * admin bar isn't painted until wp_admin_bar_render() on wp_footer priority
 * 1000 though, so a node added at 999 still lands, and by then the shortcode
 * has told us exactly what rendered. That makes this correct for Oxygen,
 * Elementor, product templates, and anything else that keeps its layout out of
 * post_content.
 *
 * @return void
 */
function fac_quote_admin_bar_late() {
    global $wp_admin_bar;

    if ( ! is_admin_bar_showing() || ! is_object( $wp_admin_bar ) ) {
        return;
    }
    if ( ! current_user_can( 'manage_options' ) ) {
        return;
    }

    $type = fac_quote_rendered_calculator_type();
    if ( ! $type ) {
        return;
    }

    // The early pass already got it.
    if ( $wp_admin_bar->get_node( 'fac-quote-edit' ) ) {
        return;
    }

    fac_quote_add_admin_bar_node( $wp_admin_bar, $type );
}

/**
 * Build the admin bar node for a calculator branch.
 *
 * The name comes from the WooCommerce product the branch is wired to, so the
 * label reads the way the studio thinks about the thing being sold rather than
 * the way the plugin is built.
 *
 * @param WP_Admin_Bar $wp_admin_bar
 * @param string       $type archival|inkjet
 * @return void
 */
function fac_quote_add_admin_bar_node( $wp_admin_bar, $type ) {
    if ( fac_quote_admin_mode_active() ) {
        $wp_admin_bar->add_node(
            array(
                'id'    => 'fac-quote-edit',
                'title' => __( 'Done editing', 'fine-art-calculator' ),
                'href'  => esc_url( fac_quote_toggle_url( false ) ),
                'meta'  => array( 'title' => __( 'Leave quote authoring and view this page as a customer', 'fine-art-calculator' ) ),
            )
        );
        return;
    }

    $wp_admin_bar->add_node(
        array(
            'id'    => 'fac-quote-edit',
            'title' => sprintf(
                /* translators: %s: WooCommerce product name for this calculator. */
                __( 'Edit %s', 'fine-art-calculator' ),
                fac_quote_product_name( $type )
            ),
            'href'  => esc_url( fac_quote_toggle_url( true ) ),
            'meta'  => array( 'title' => __( 'Configure a quote link for this calculator', 'fine-art-calculator' ) ),
        )
    );
}

/**
 * The current URL with authoring mode switched on or off.
 *
 * Built from the current request rather than a permalink so any other query
 * args on the page survive the round trip.
 *
 * @param bool $on True to enter authoring mode, false to leave it.
 * @return string
 */
function fac_quote_toggle_url( $on ) {
    $request = isset( $_SERVER['REQUEST_URI'] ) ? wp_unslash( $_SERVER['REQUEST_URI'] ) : '';
    $current = home_url( $request );

    return $on
        ? add_query_arg( FAC_QUOTE_ADMIN_VAR, '1', $current )
        : remove_query_arg( FAC_QUOTE_ADMIN_VAR, $current );
}
