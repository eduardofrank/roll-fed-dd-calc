<?php
/**
 * Uninstall cleanup.
 *
 * Data policy:
 * - All fac_* options (paper catalogs, roll widths, rates, product
 *   mappings, paper images, error counters, auto-detected calculator page
 *   locations) are DELETED — they are plugin configuration, reproducible
 *   from a settings JSON export or self-healing on next render.
 * - Order item meta written at checkout (print details on past orders) is
 *   KEPT — it belongs to the shop's order history, not to the plugin.
 * - Saved quote records (the fac_quote post type and their _fac_quote_*
 *   meta) are KEPT — a quote can carry a negotiated price and references
 *   the orders it produced (_fac_quote_orders), so they are business
 *   records like orders, not disposable plugin state.
 * - Rate-limit transients (fac_rl_*) expire on their own within the hour.
 * - WooCommerce-owned shipping-method settings live in WC's shipping-zone
 *   tables and are left to WooCommerce to manage.
 *
 * Export settings JSON before uninstalling if you may reinstall later.
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
    exit;
}

$fac_option_keys = array(
    'fac_paper_data',
    'fac_inkjet_paper_data',
    'fac_roll_widths',
    'fac_mounting_rates',
    'fac_turnaround_rates',
    'fac_woocommerce_product_id',
    'fac_inkjet_woocommerce_product_id',
    'fac_paper_images',
    'fac_paper_images_version',
    'fac_error_counts',
    'fac_ops_digest',
    'fac_calculator_location_archival',
    'fac_calculator_location_inkjet',
);

foreach ( $fac_option_keys as $fac_option_key ) {
    delete_option( $fac_option_key );
}

if ( function_exists( 'wp_clear_scheduled_hook' ) ) {
    wp_clear_scheduled_hook( 'fac_daily_ops_digest' );
}
