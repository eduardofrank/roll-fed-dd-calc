<?php
if ( ! defined( 'ABSPATH' ) ) exit;

function fac_get_paper_data() {
    return get_option( 'fac_paper_data', fac_get_default_paper_data() );
}

function fac_get_inkjet_paper_data() {
    $stored = get_option( 'fac_inkjet_paper_data', null );

    if ( null === $stored ) {
        return fac_get_default_inkjet_paper_data();
    }

    return fac_normalize_inkjet_paper_data( $stored );
}

/**
 * Ensure every inkjet paper entry has a valid category.
 *
 * @param mixed $data Raw stored payload.
 * @return array<int,array<string,mixed>>
 */
function fac_normalize_inkjet_paper_data( $data ) {
    if ( ! is_array( $data ) ) {
        return fac_get_default_inkjet_paper_data();
    }

    $normalized = array();

    foreach ( $data as $paper ) {
        if ( ! is_array( $paper ) ) {
            continue;
        }

        $slug = sanitize_key( (string) ( $paper['slug'] ?? '' ) );
        if ( '' === $slug ) {
            continue;
        }

        $paper['category'] = fac_sanitize_inkjet_category( $paper['category'] ?? '', $slug );
        $normalized[]      = $paper;
    }

    if ( empty( $normalized ) ) {
        return fac_get_default_inkjet_paper_data();
    }

    return $normalized;
}

/**
 * Detect legacy inkjet records that still need category migration.
 *
 * @param mixed $data Raw stored payload.
 * @return bool
 */
function fac_inkjet_paper_data_needs_category_migration( $data ) {
    if ( ! is_array( $data ) ) {
        return false;
    }

    foreach ( $data as $paper ) {
        if ( ! is_array( $paper ) ) {
            continue;
        }

        $slug = sanitize_key( (string) ( $paper['slug'] ?? '' ) );
        if ( '' === $slug ) {
            continue;
        }

        $current  = sanitize_key( str_replace( '-', '_', (string) ( $paper['category'] ?? '' ) ) );
        $expected = fac_sanitize_inkjet_category( $current, $slug );

        if ( $current !== $expected ) {
            return true;
        }
    }

    return false;
}

function fac_get_roll_widths() {
    return get_option( 'fac_roll_widths', fac_get_default_roll_widths() );
}

function fac_get_mounting_rates() {
    return get_option( 'fac_mounting_rates', fac_get_default_mounting_rates() );
}

function fac_get_turnaround_rates() {
    return get_option( 'fac_turnaround_rates', fac_get_default_turnaround_rates() );
}

function fac_get_product_id() {
    return absint( get_option( 'fac_woocommerce_product_id', 0 ) );
}

function fac_get_inkjet_product_id() {
    return absint( get_option( 'fac_inkjet_woocommerce_product_id', 0 ) );
}

/**
 * WooCommerce product ID for a calculator branch.
 *
 * @param string $type archival|inkjet
 * @return int
 */
function fac_get_configured_product_id( $type = 'archival' ) {
    return $type === 'inkjet' ? fac_get_inkjet_product_id() : fac_get_product_id();
}

/**
 * Look up an inkjet paper by slug.
 *
 * @param string $slug Paper slug.
 * @return array|null
 */
function fac_find_inkjet_paper( $slug ) {
    foreach ( fac_get_inkjet_paper_data() as $paper ) {
        if ( isset( $paper['slug'] ) && $paper['slug'] === $slug ) {
            return $paper;
        }
    }

    return null;
}

/**
 * Merge default paper image URLs with stored overrides.
 * Admin-saved URLs win for the same slug; new default slugs are added.
 *
 * @param array $defaults Default slug => URL map.
 * @param array $existing Stored slug => URL map.
 * @return array Merged map.
 */
function fac_merge_paper_image_options( $defaults, $existing ) {
    $existing = is_array( $existing ) ? $existing : array();

    return array_merge( $defaults, $existing );
}

/**
 * Returns a flat slug → URL map for all paper images.
 *
 * Priority order (highest wins):
 *   1. Dedicated paper-images option  (set on the Paper Images admin page)
 *   2. imageUrl stored inside each paper object in fac_paper_data
 *   3. Empty string (React shows no image)
 */
function fac_get_paper_images() {
    // Layer 1: dedicated overrides saved on the Paper Images page
    $overrides = get_option( 'fac_paper_images', array() );
    if ( ! is_array( $overrides ) ) {
        $overrides = array();
    }

    // Layer 2: imageUrl baked into every paper object in the catalogue
    $baked = array();
    foreach ( fac_get_paper_data() as $brand => $finishes ) {
        foreach ( $finishes as $finish => $papers ) {
            foreach ( $papers as $paper ) {
                if ( ! empty( $paper['slug'] ) && ! empty( $paper['imageUrl'] ) ) {
                    $baked[ $paper['slug'] ] = $paper['imageUrl'];
                }
            }
        }
    }

    // Merge: overrides win over baked-in values; strip empties
    $merged = array_filter(
        array_merge( $baked, $overrides ),
        function( $v ) { return $v !== ''; }
    );

    return $merged;
}

/**
 * Complete list of every paper slug grouped by brand for the admin UI.
 */
function fac_all_paper_slugs() {
    $grouped = array();

    foreach ( fac_get_paper_data() as $brand => $finishes ) {
        foreach ( $finishes as $papers ) {
            foreach ( $papers as $paper ) {
                if ( empty( $paper['slug'] ) ) {
                    continue;
                }
                if ( ! isset( $grouped[ $brand ] ) ) {
                    $grouped[ $brand ] = array();
                }
                $grouped[ $brand ][ $paper['slug'] ] = $paper['name'];
            }
        }
    }

    return $grouped;
}

/**
 * Option keys included in admin export / import.
 *
 * @return array<string>
 */
function fac_get_exportable_option_keys() {
    return array(
        'fac_paper_data',
        'fac_inkjet_paper_data',
        'fac_roll_widths',
        'fac_mounting_rates',
        'fac_turnaround_rates',
        'fac_woocommerce_product_id',
        'fac_inkjet_woocommerce_product_id',
        'fac_paper_images',
        'fac_ops_digest',
    );
}

/**
 * Human-readable labels for exportable option keys.
 *
 * @return array<string,string>
 */
function fac_get_exportable_option_labels() {
    return array(
        'fac_paper_data'                    => 'Archival Paper Catalogue',
        'fac_inkjet_paper_data'             => 'Inkjet Paper Catalogue',
        'fac_roll_widths'                   => 'Roll Widths',
        'fac_mounting_rates'                => 'Mounting Rates',
        'fac_turnaround_rates'              => 'Turnaround Rates',
        'fac_woocommerce_product_id'        => 'WooCommerce Product ID — Archival',
        'fac_inkjet_woocommerce_product_id' => 'WooCommerce Product ID — Inkjet',
        'fac_paper_images'                  => 'Paper Image URLs',
        'fac_ops_digest'                    => 'Daily Ops Digest Settings',
    );
}

/**
 * Export keys that are site-specific (product IDs differ between installs).
 *
 * @return array<string>
 */
function fac_get_exportable_site_specific_keys() {
    return array(
        'fac_woocommerce_product_id',
        'fac_inkjet_woocommerce_product_id',
    );
}

/**
 * Build allowed roll key lookup from configured roll widths.
 *
 * @return array<string,bool>
 */
function fac_get_allowed_roll_key_map() {
    $allowed = array();

    foreach ( fac_get_roll_widths() as $roll ) {
        if ( ! empty( $roll['key'] ) ) {
            $allowed[ (string) $roll['key'] ] = true;
        }
    }

    return $allowed;
}

/**
 * Sanitize and validate available roll keys.
 *
 * @param mixed $rolls Candidate list of roll keys.
 * @return array<string>
 */
function fac_sanitize_available_rolls( $rolls ) {
    if ( ! is_array( $rolls ) ) {
        return array();
    }

    $allowed = fac_get_allowed_roll_key_map();
    $clean   = array();

    foreach ( $rolls as $roll_key ) {
        $roll_key = sanitize_key( (string) $roll_key );
        if ( $roll_key !== '' && isset( $allowed[ $roll_key ] ) ) {
            $clean[] = $roll_key;
        }
    }

    return array_values( array_unique( $clean ) );
}

/**
 * Sanitize archival paper data payload.
 *
 * @param mixed $data Raw payload.
 * @return array|WP_Error
 */
function fac_sanitize_archival_paper_data( $data ) {
    if ( ! is_array( $data ) ) {
        return new WP_Error( 'invalid_archival_data', 'Paper data must be a brand/finish map.' );
    }

    $clean = array();

    foreach ( $data as $brand => $finishes ) {
        $brand = sanitize_text_field( (string) $brand );
        if ( $brand === '' || ! is_array( $finishes ) ) {
            continue;
        }

        $clean_finishes = array();
        foreach ( $finishes as $finish => $papers ) {
            $finish = sanitize_text_field( (string) $finish );
            if ( $finish === '' || ! is_array( $papers ) ) {
                continue;
            }

            $clean_papers = array();
            foreach ( $papers as $paper ) {
                if ( ! is_array( $paper ) ) {
                    continue;
                }

                $name = sanitize_text_field( (string) ( $paper['name'] ?? '' ) );
                $slug = sanitize_key( (string) ( $paper['slug'] ?? '' ) );
                if ( $name === '' || $slug === '' ) {
                    continue;
                }

                $clean_papers[] = array(
                    'name'           => $name,
                    'slug'           => $slug,
                    'colIndex'       => max( 1, absint( $paper['colIndex'] ?? 1 ) ),
                    'rate'           => max( 0.0, floatval( $paper['rate'] ?? 0 ) ),
                    'gsm'            => max( 0, absint( $paper['gsm'] ?? 0 ) ),
                    'availableRolls' => fac_sanitize_available_rolls( $paper['availableRolls'] ?? array() ),
                    'description'    => sanitize_text_field( (string) ( $paper['description'] ?? '' ) ),
                    'imageUrl'       => esc_url_raw( (string) ( $paper['imageUrl'] ?? '' ) ),
                );
            }

            if ( ! empty( $clean_papers ) ) {
                $clean_finishes[ $finish ] = $clean_papers;
            }
        }

        if ( ! empty( $clean_finishes ) ) {
            $clean[ $brand ] = $clean_finishes;
        }
    }

    if ( empty( $clean ) ) {
        return new WP_Error( 'empty_archival_data', 'No valid archival paper entries were provided.' );
    }

    return $clean;
}

/**
 * Sanitize inkjet paper data payload.
 *
 * @param mixed $data Raw payload.
 * @return array|WP_Error
 */
function fac_sanitize_inkjet_paper_data( $data ) {
    if ( ! is_array( $data ) ) {
        return new WP_Error( 'invalid_inkjet_data', 'Inkjet paper data must be an array.' );
    }

    $clean = array();
    foreach ( $data as $paper ) {
        if ( ! is_array( $paper ) ) {
            continue;
        }

        $name = sanitize_text_field( (string) ( $paper['name'] ?? '' ) );
        $slug = sanitize_key( (string) ( $paper['slug'] ?? '' ) );
        if ( $name === '' || $slug === '' ) {
            continue;
        }

        $clean[] = array(
            'name'           => $name,
            'slug'           => $slug,
            'category'       => fac_sanitize_inkjet_category( $paper['category'] ?? '', $slug ),
            'rate'           => max( 0.0, floatval( $paper['rate'] ?? 0 ) ),
            'gsm'            => max( 0, absint( $paper['gsm'] ?? 0 ) ),
            'availableRolls' => fac_sanitize_available_rolls( $paper['availableRolls'] ?? array() ),
            'description'    => sanitize_text_field( (string) ( $paper['description'] ?? '' ) ),
        );
    }

    if ( empty( $clean ) ) {
        return new WP_Error( 'empty_inkjet_data', 'No valid inkjet paper entries were provided.' );
    }

    return $clean;
}

/**
 * Sanitize roll widths payload.
 *
 * @param mixed $data Raw payload.
 * @return array|WP_Error
 */
function fac_sanitize_roll_widths_data( $data ) {
    if ( ! is_array( $data ) ) {
        return new WP_Error( 'invalid_roll_data', 'Roll width data must be an array.' );
    }

    $clean = array();
    foreach ( $data as $roll ) {
        if ( ! is_array( $roll ) ) {
            continue;
        }

        $key          = sanitize_key( (string) ( $roll['key'] ?? '' ) );
        $width_inches = max( 0.0, floatval( $roll['widthInches'] ?? 0 ) );
        $usable_in    = max( 0.0, floatval( $roll['usableInches'] ?? 0 ) );
        $usable_cm    = max( 0.0, floatval( $roll['usableCm'] ?? 0 ) );

        if ( $key === '' || $usable_cm <= 0 ) {
            continue;
        }

        $clean[] = array(
            'key'         => $key,
            'label'       => sanitize_text_field( (string) ( $roll['label'] ?? '' ) ),
            'widthInches' => $width_inches,
            'usableInches'=> $usable_in,
            'usableCm'    => $usable_cm,
        );
    }

    if ( empty( $clean ) ) {
        return new WP_Error( 'empty_roll_data', 'No valid roll widths were provided.' );
    }

    return $clean;
}

/**
 * Sanitize mounting and turnaround rate payloads.
 *
 * @param mixed $mounting_rates   Raw mounting rates payload.
 * @param mixed $turnaround_rates Raw turnaround rates payload.
 * @return array|WP_Error
 */
function fac_sanitize_rates_data( $mounting_rates, $turnaround_rates ) {
    if ( ! is_array( $mounting_rates ) || ! is_array( $turnaround_rates ) ) {
        return new WP_Error( 'invalid_rates', 'Mounting and turnaround rates must be arrays.' );
    }

    $clean_mounting = fac_sanitize_mounting_rates_data( $mounting_rates );
    $clean_turnaround = fac_sanitize_turnaround_rates_data( $turnaround_rates );

    return array(
        'mounting'   => $clean_mounting,
        'turnaround' => $clean_turnaround,
    );
}

/**
 * Sanitize mounting rates payload.
 *
 * @param mixed $mounting_rates Raw mounting rates payload.
 * @return array<string,array<string,float>>
 */
function fac_sanitize_mounting_rates_data( $mounting_rates ) {
    return array(
        'inches' => array(
            'no_mounting'      => 0.0,
            'white_gatorboard' => max( 0.0, floatval( $mounting_rates['inches']['white_gatorboard'] ?? 0 ) ),
            'black_gatorboard' => max( 0.0, floatval( $mounting_rates['inches']['black_gatorboard'] ?? 0 ) ),
        ),
        'centimeters' => array(
            'no_mounting'      => 0.0,
            'white_gatorboard' => max( 0.0, floatval( $mounting_rates['centimeters']['white_gatorboard'] ?? 0 ) ),
            'black_gatorboard' => max( 0.0, floatval( $mounting_rates['centimeters']['black_gatorboard'] ?? 0 ) ),
        ),
    );
}

/**
 * Sanitize turnaround rates payload.
 *
 * @param mixed $turnaround_rates Raw turnaround rates payload.
 * @return array<string,float>
 */
function fac_sanitize_turnaround_rates_data( $turnaround_rates ) {
    $standard = max( 0.0, floatval( $turnaround_rates['standard'] ?? 1 ) );
    $rush     = max( $standard, floatval( $turnaround_rates['rush'] ?? 1 ) );
    return array(
        'standard' => $standard,
        'rush'     => $rush,
    );
}

/**
 * Build the JS-compatible data object the front-end calculator needs.
 *
 * @param string     $type         archival|inkjet
 * @param array|null $quote        Hydrated quote link, when the page was opened
 *                                 with a valid ?fac_quote= token.
 * @param string     $quote_notice Message shown when a token was present but
 *                                 could not be honoured.
 * @return array
 */
function fac_build_js_data( $type = 'archival', $quote = null, $quote_notice = '' ) {
    $type = $type === 'inkjet' ? 'inkjet' : 'archival';

    return array(
        'calculatorType'     => $type,
        'paperData'          => $type === 'inkjet' ? fac_get_inkjet_paper_data() : fac_get_paper_data(),
        'rollWidths'         => fac_get_roll_widths(),
        'mountingRates'      => fac_get_mounting_rates(),
        'turnaroundRates'    => fac_get_turnaround_rates(),
        'ajaxUrl'            => admin_url( 'admin-ajax.php' ),
        'nonce'              => wp_create_nonce( 'fac_nonce' ),
        'wooProductId'       => fac_get_configured_product_id( $type ),
        'paperImages'        => $type === 'inkjet' ? array() : fac_get_paper_images(),
        'lang'               => 0 === strpos( get_locale(), 'es' ) ? 'es' : 'en',
        'quote'              => fac_quote_build_js_payload( $quote ),
        'quoteNotice'        => (string) $quote_notice,
        // Null for everyone who isn't an administrator in authoring mode. This
        // is what keeps the quote list and the admin nonce off a public page.
        'quoteAdmin'         => fac_quote_build_admin_payload( $type ),
    );
}
