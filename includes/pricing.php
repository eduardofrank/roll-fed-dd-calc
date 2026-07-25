<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

define( 'FAC_MAX_DIMENSION_INCHES', 1200 );
define( 'FAC_MAX_DIMENSION_CENTIMETERS', 3048 );
define( 'FAC_MAX_QUANTITY', 500 );

/*
 * The printer will not accept a job whose print length — the distance the roll
 * advances — is under 279 mm (10.985 in). A shorter job is not refused in
 * practice: the press still feeds the minimum, so the roll is consumed whether
 * the image covers it or not.
 *
 * The order is therefore never blocked. Instead the paper charge is floored at
 * this length, which is what the studio actually pays for. One 5x7 costs the
 * same roll feed as 10.985 in of it; nesting more prints into that run is how
 * the shopper gets the rest of the length they have already bought.
 *
 * 27.9 cm is the authoritative figure (the spec is metric); 10.985 in is the
 * rounded-up inch equivalent used for display only.
 */
define( 'FAC_MIN_PRINT_LENGTH_CM', 27.9 );
define( 'FAC_MIN_PRINT_LENGTH_INCHES', 10.985 );

/*
 * Bill the roll length the shopper actually laid out when a layout feed is
 * present (see fac_artwork_layout_geometry() / fac_layout_feed_cm_for_state()).
 * Nesting alone invents extra passes for mixed gangs that already fit in one
 * run; the server-stamped layout feed is authoritative for paper in that case.
 *
 * Client and server must agree within $0.02 or add-to-cart returns 409.
 */
if ( ! defined( 'FAC_LAYOUT_DRIVEN_PRICING' ) ) {
    define( 'FAC_LAYOUT_DRIVEN_PRICING', true );
}

/**
 * Look up a paper record by brand, finish, and slug.
 *
 * @param string $brand           Paper brand (archival only).
 * @param string $finish          Paper finish (archival only).
 * @param string $slug            Paper slug.
 * @param string $calculator_type archival|inkjet
 * @return array|null Paper array or null if not found.
 */
function fac_find_paper( $brand, $finish, $slug, $calculator_type = 'archival' ) {
    if ( $calculator_type === 'inkjet' ) {
        return fac_find_inkjet_paper( $slug );
    }

    $paper_data = fac_get_paper_data();

    if ( ! isset( $paper_data[ $brand ][ $finish ] ) ) {
        return null;
    }

    foreach ( $paper_data[ $brand ][ $finish ] as $paper ) {
        if ( isset( $paper['slug'] ) && $paper['slug'] === $slug ) {
            return $paper;
        }
    }

    return null;
}

/**
 * Look up roll width config by key.
 *
 * @return array|null Roll width array or null if not found.
 */
function fac_find_roll_width( $roll_key ) {
    $rolls = fac_get_roll_widths();

    foreach ( $rolls as $roll ) {
        if ( isset( $roll['key'] ) && $roll['key'] === (string) $roll_key ) {
            return $roll;
        }
    }

    return null;
}

/**
 * Minimum print length rendered in the shopper's units.
 *
 * @param string $units inches|centimeters.
 * @return string e.g. "10.985 in" or "27.9 cm".
 */
function fac_format_min_print_length( $units = 'inches' ) {
    if ( $units === 'centimeters' ) {
        return rtrim( rtrim( number_format( FAC_MIN_PRINT_LENGTH_CM, 1 ), '0' ), '.' ) . ' cm';
    }

    return rtrim( rtrim( number_format( FAC_MIN_PRINT_LENGTH_INCHES, 3 ), '0' ), '.' ) . ' in';
}

/**
 * Roll length actually consumed by a job, after the printer's minimum feed.
 *
 * @param int   $passes  Number of passes down the roll.
 * @param float $feed_cm Linear feed per pass in cm.
 * @return float Billed roll length in cm.
 */
function fac_billed_feed_length_cm( $passes, $feed_cm ) {
    $requested = max( 0.0, floatval( $passes ) * floatval( $feed_cm ) );

    if ( $requested <= 0 ) {
        return 0.0;
    }

    return max( $requested, FAC_MIN_PRINT_LENGTH_CM );
}

/**
 * Compute print cost for a single nesting orientation.
 *
 * The roll length is floored at FAC_MIN_PRINT_LENGTH_CM: a job shorter than the
 * printer's minimum feed still advances — and consumes — that much paper.
 *
 * @param int   $quantity       Print quantity.
 * @param int   $nesting_factor Prints across the roll width per pass.
 * @param float $feed_cm        Linear feed per pass in cm.
 * @param float $roll_width_cm  Usable roll width in cm.
 * @param float $paper_rate     Paper rate per cm².
 * @return float Print cost for this orientation.
 */
function fac_nesting_print_cost( $quantity, $nesting_factor, $feed_cm, $roll_width_cm, $paper_rate ) {
    if ( $nesting_factor <= 0 ) {
        return 0.0;
    }

    $passes = (int) ceil( $quantity / $nesting_factor );

    return fac_billed_feed_length_cm( $passes, $feed_cm ) * $roll_width_cm * $paper_rate;
}

/**
 * Calculate print price using the same roll-nesting algorithm as the front-end.
 *
 * @param array $state Calculator state (rollKey, brand, finish, selectedPaperSlug,
 *                     units, width, height, quantity, mounting, turnaround).
 * @return array Calculation results matching the React bundle output shape.
 */
function fac_calculate_price( $state ) {
    $roll_key   = $state['rollKey'] ?? '44';
    $width      = floatval( $state['width'] ?? 0 );
    $height     = floatval( $state['height'] ?? 0 );
    $quantity   = max( 1, (int) round( floatval( $state['quantity'] ?? 1 ) ) );
    $units      = $state['units'] ?? 'inches';
    $brand      = $state['brand'] ?? '';
    $finish     = $state['finish'] ?? '';
    $paper_slug = $state['selectedPaperSlug'] ?? '';
    $mounting   = $state['mounting'] ?? 'no_mounting';
    $turnaround = $state['turnaround'] ?? 'standard';
    $calc_type  = ( $state['calculatorType'] ?? 'archival' ) === 'inkjet' ? 'inkjet' : 'archival';

    $roll         = fac_find_roll_width( $roll_key );
    $roll_width_cm = $roll ? floatval( $roll['usableCm'] ) : 111.0;

    $paper      = fac_find_paper( $brand, $finish, $paper_slug, $calc_type );
    $paper_rate = $paper ? floatval( $paper['rate'] ) : 0.0414;
    $paper_name = $paper ? $paper['name'] : 'Unknown';

    $width_cm  = $units === 'inches' ? $width * 2.54 : $width;
    $height_cm = $units === 'inches' ? $height * 2.54 : $height;

    $tolerance = 0.0005;
    $factor_w  = $width_cm > 0 ? (int) floor( ( $roll_width_cm + $tolerance ) / $width_cm ) : 0;
    $factor_h  = $height_cm > 0 ? (int) floor( ( $roll_width_cm + $tolerance ) / $height_cm ) : 0;

    $is_valid  = $width > 0 && $height > 0;
    $can_print = $factor_w >= 1 || $factor_h >= 1;

    $selected_rule        = null;
    $selected_orientation = null;
    $nesting_factor       = 0;
    $feed_cm              = 0;

    if ( $is_valid && $can_print ) {
        if ( $factor_w >= 1 && $factor_h >= 1 ) {
            $selected_rule        = 3;
            $selected_orientation = 'width';
            $nesting_factor       = $factor_w;
            $feed_cm              = $height_cm;
        } elseif ( $factor_w >= 1 ) {
            $selected_rule        = 1;
            $selected_orientation = 'width';
            $nesting_factor     = $factor_w;
            $feed_cm            = $height_cm;
        } elseif ( $factor_h >= 1 ) {
            $selected_rule        = 2;
            $selected_orientation = 'height';
            $nesting_factor     = $factor_h;
            $feed_cm            = $width_cm;
        }
    }

    $passes     = $nesting_factor > 0 ? (int) ceil( $quantity / $nesting_factor ) : 0;

    /*
     * When a server-measured layout feed is present (stamped by
     * fac_apply_layout_feed_to_state — never trusted from the browser), that
     * length is the paper charge. Nesting must not invent extra passes for a
     * mixed gang that already fits in one run of roll.
     *
     * Without a layout feed, bill ideal nesting as before.
     */
    $nesting_feed_cm = $passes * $feed_cm;
    $layout_feed_cm  = FAC_LAYOUT_DRIVEN_PRICING && isset( $state['layoutFeedCm'] )
        ? max( 0.0, floatval( $state['layoutFeedCm'] ) )
        : 0.0;

    $requested_feed_cm  = $layout_feed_cm > 0 ? $layout_feed_cm : $nesting_feed_cm;
    $billed_feed_cm     = $requested_feed_cm > 0 ? max( $requested_feed_cm, FAC_MIN_PRINT_LENGTH_CM ) : 0.0;
    $min_length_applied = $billed_feed_cm > $requested_feed_cm + 0.0005;
    $layout_driven      = $layout_feed_cm > 0;

    $print_cost = $nesting_factor > 0 ? $billed_feed_cm * $roll_width_cm * $paper_rate : 0.0;

    $mounting_rates  = fac_get_mounting_rates();
    $unit_key        = $units === 'inches' ? 'inches' : 'centimeters';
    $gatorboard_rate = floatval( $mounting_rates[ $unit_key ][ $mounting ] ?? 0 );
    $mounting_cost   = $width * $height * $gatorboard_rate * $quantity;

    $subtotal = $print_cost + $mounting_cost;

    $turnaround_rates = fac_get_turnaround_rates();
    $multiplier       = floatval( $turnaround_rates[ $turnaround ] ?? 1 );
    $total_price      = $subtotal * $multiplier;

    if ( $units === 'inches' ) {
        $estimated_weight = $width * $height * 0.0006 * $quantity;
        $weight_unit      = 'lbs';
    } else {
        $estimated_weight = $width * $height * 0.000093 * $quantity;
        $weight_unit      = 'kg';
    }

    return array(
        'widthCm'          => $width_cm,
        'heightCm'         => $height_cm,
        'rollWidthCm'      => $roll_width_cm,
        'factorW'          => $factor_w,
        'factorH'          => $factor_h,
        'orientationRatio' => $factor_h > 0 ? $factor_w / $factor_h : 0,
        'isValid'          => $is_valid,
        'canPrint'             => $is_valid ? $can_print : false,
        'selectedRule'         => $selected_rule,
        'selectedOrientation'  => $selected_orientation,
        'nestingFactor'        => $nesting_factor,
        'feedCm'           => $feed_cm,
        'passes'           => $passes,
        'requestedFeedCm'  => $requested_feed_cm,
        'nestingFeedCm'    => $nesting_feed_cm,
        'layoutFeedCm'     => $layout_feed_cm,
        'layoutDriven'     => $layout_driven,
        'billedFeedCm'     => $billed_feed_cm,
        'minLengthApplied' => $min_length_applied,
        'minFeedCm'        => FAC_MIN_PRINT_LENGTH_CM,
        'paperRate'        => $paper_rate,
        'gatorboardRate'   => $gatorboard_rate,
        'printCost'        => $print_cost,
        'mountingCost'     => $mounting_cost,
        'subtotal'         => $subtotal,
        'totalPrice'       => $total_price,
        'estimatedWeight'  => $estimated_weight,
        'weightUnit'       => $weight_unit,
        'paperName'        => $paper_name,
    );
}

/**
 * Whether print dimensions fit a 48×96 in gatorboard sheet in either orientation.
 *
 * @param float|int|string $width  Print width.
 * @param float|int|string $height Print height.
 * @param string           $units  inches|centimeters.
 * @return bool
 */
function fac_fits_gatorboard( $width, $height, $units = 'inches' ) {
    $w = floatval( $width );
    $h = floatval( $height );

    if ( $w <= 0 || $h <= 0 ) {
        return true;
    }

    if ( $units === 'centimeters' ) {
        $w /= 2.54;
        $h /= 2.54;
    }

    return min( $w, $h ) <= 48 && max( $w, $h ) <= 96;
}

/**
 * Validate calculator state and return server-computed results.
 *
 * @param array $state Calculator state from the client.
 * @return array|WP_Error Results array or error.
 */
function fac_validate_calculator_state( $state ) {
    if ( ! is_array( $state ) || empty( $state ) ) {
        return new WP_Error( 'invalid_state', 'Missing calculator configuration.' );
    }

    $calc_type = ( $state['calculatorType'] ?? 'archival' ) === 'inkjet' ? 'inkjet' : 'archival';
    $units     = $state['units'] ?? 'inches';
    $mounting  = $state['mounting'] ?? 'no_mounting';
    $turnaround = $state['turnaround'] ?? 'standard';

    if ( $calc_type === 'inkjet' ) {
        $required = array( 'rollKey', 'selectedPaperSlug', 'width', 'height', 'quantity' );
    } else {
        $required = array( 'rollKey', 'brand', 'finish', 'selectedPaperSlug', 'width', 'height', 'quantity' );
    }

    foreach ( $required as $field ) {
        if ( ! isset( $state[ $field ] ) || $state[ $field ] === '' ) {
            return new WP_Error( 'missing_field', sprintf( 'Missing required field: %s', $field ) );
        }
    }

    if ( ! fac_find_roll_width( $state['rollKey'] ) ) {
        return new WP_Error( 'invalid_roll', 'Invalid roll width selected.' );
    }

    if ( ! in_array( $units, array( 'inches', 'centimeters' ), true ) ) {
        return new WP_Error( 'invalid_units', 'Invalid units selection.' );
    }

    if ( ! in_array( $mounting, array( 'no_mounting', 'white_gatorboard', 'black_gatorboard' ), true ) ) {
        return new WP_Error( 'invalid_mounting', 'Invalid mounting option selected.' );
    }

    if ( ! in_array( $turnaround, array( 'standard', 'rush' ), true ) ) {
        return new WP_Error( 'invalid_turnaround', 'Invalid turnaround option selected.' );
    }

    $brand  = $state['brand'] ?? '';
    $finish = $state['finish'] ?? '';
    $paper  = fac_find_paper( $brand, $finish, $state['selectedPaperSlug'], $calc_type );
    if ( ! $paper ) {
        return new WP_Error( 'invalid_paper', 'Invalid paper selection.' );
    }

    if ( ! empty( $paper['availableRolls'] ) && ! in_array( (string) $state['rollKey'], $paper['availableRolls'], true ) ) {
        return new WP_Error( 'paper_roll_mismatch', 'Selected paper is not available on this roll width.' );
    }

    $width  = floatval( $state['width'] );
    $height = floatval( $state['height'] );
    if ( $width <= 0 || $height <= 0 ) {
        return new WP_Error( 'invalid_dimensions', 'Width and height must be greater than zero.' );
    }

    $dimension_limit = $units === 'centimeters' ? FAC_MAX_DIMENSION_CENTIMETERS : FAC_MAX_DIMENSION_INCHES;
    if ( $width > $dimension_limit || $height > $dimension_limit ) {
        return new WP_Error( 'dimensions_too_large', 'Width or height exceeds the supported maximum size.' );
    }

    $quantity = max( 1, (int) round( floatval( $state['quantity'] ?? 1 ) ) );
    if ( $quantity > FAC_MAX_QUANTITY ) {
        return new WP_Error( 'quantity_too_large', 'Requested quantity exceeds the supported maximum.' );
    }

    if ( $mounting !== 'no_mounting' && ! fac_fits_gatorboard( $width, $height, $units ) ) {
        return new WP_Error( 'gatorboard_too_large', 'Gatorboard mounting is unavailable for prints larger than 48×96 inches.' );
    }

    $results = fac_calculate_price( $state );

    if ( ! $results['canPrint'] ) {
        return new WP_Error( 'cannot_print', 'Print dimensions exceed the selected roll width.' );
    }

    return $results;
}

/**
 * Ensure cart line quantity matches calculator state quantity.
 *
 * @param int   $quantity Cart quantity from the request payload.
 * @param array $state    Calculator state array.
 * @return bool
 */
function fac_cart_quantity_matches_state( $quantity, $state ) {
    $cart_qty  = max( 1, (int) $quantity );
    $state_qty = (int) ( $state['quantity'] ?? 0 );

    return $state_qty === $cart_qty;
}

/**
 * Format rush fee label using configured turnaround rates.
 *
 * @param float  $subtotal   Pre-rush subtotal.
 * @param string $turnaround Turnaround key (standard|rush).
 * @return string|null Rush fee display string or null if not rush.
 */
function fac_format_rush_fee_label( $subtotal, $turnaround ) {
    if ( $turnaround !== 'rush' ) {
        return null;
    }

    $rates      = fac_get_turnaround_rates();
    $multiplier = floatval( $rates['rush'] ?? 1.15 );
    $pct        = round( ( $multiplier - 1 ) * 100 );
    $fee        = $subtotal * ( $multiplier - 1 );

    return sprintf( '+%d%% ($%s)', $pct, number_format( $fee, 2 ) );
}
