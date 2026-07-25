<?php
/**
 * Plugin Name: Roll Fed Calc
 * Plugin URI:  https://artmedia.studio
 * Description: Roll-fed print calculators for Archival Fine Art and Inkjet via shortcodes [fine_art_calculator_embed] and [inkjet_calculator_embed]. Manage paper options and prices from the WordPress admin.
 * Version:     2.23.11
 * Author:      ArtMedia Studio
 * Requires PHP: 7.4
 * License:     GPL-2.0+
 * Text Domain: fine-art-calculator
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

define( 'FAC_VERSION',    '2.23.11' );
define( 'FAC_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'FAC_PLUGIN_URL', plugin_dir_url( __FILE__ ) );

require_once FAC_PLUGIN_DIR . 'includes/logging.php';
require_once FAC_PLUGIN_DIR . 'includes/default-data.php';
require_once FAC_PLUGIN_DIR . 'includes/settings.php';
require_once FAC_PLUGIN_DIR . 'includes/export-import.php';
require_once FAC_PLUGIN_DIR . 'includes/pricing.php';
require_once FAC_PLUGIN_DIR . 'includes/cart-meta.php';
require_once FAC_PLUGIN_DIR . 'includes/shipping-method.php';
require_once FAC_PLUGIN_DIR . 'includes/quotes.php';
require_once FAC_PLUGIN_DIR . 'admin/admin-page.php';
require_once FAC_PLUGIN_DIR . 'includes/shortcode.php';
require_once FAC_PLUGIN_DIR . 'includes/ajax.php';
require_once FAC_PLUGIN_DIR . 'includes/layout-images.php';
require_once FAC_PLUGIN_DIR . 'includes/ops-digest.php';

add_action( 'before_woocommerce_init', 'fac_declare_wc_compatibility' );

/**
 * Declare WooCommerce HPOS (custom order tables) compatibility.
 *
 * Order data is only touched through the order item API on the
 * woocommerce_checkout_create_order_line_item hook — no direct
 * post/postmeta order queries (product searches use WP_Query, which
 * HPOS does not affect).
 *
 * @return void
 */
function fac_declare_wc_compatibility() {
    if ( class_exists( \Automattic\WooCommerce\Utilities\FeaturesUtil::class ) ) {
        \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', __FILE__, true );
    }
}

add_action( 'plugins_loaded', 'fac_load_textdomain' );

/**
 * Load the plugin text domain so the gettext calls are translatable.
 *
 * @return void
 */
function fac_load_textdomain() {
    load_plugin_textdomain( 'fine-art-calculator', false, dirname( plugin_basename( __FILE__ ) ) . '/languages' );
}

/* ---------------------------------------------------------------
   The canonical paper image URL map.
   update_option is called unconditionally every time so these
   URLs are always written — regardless of whether the option
   already exists in the database.
--------------------------------------------------------------- */
function fac_get_default_paper_images() {
    return array(
        // Hahnemühle — Matt Smooth
        'photo_rag'              => 'https://artmedia.studio/wp-content/uploads/2023/03/Photo-Rag.jpg',
        'photo_rag_ultra_smooth' => 'https://artmedia.studio/wp-content/uploads/2023/03/Photo-Rag-Ultra-Smooth.jpg',
        'photo_rag_bright_white' => 'https://artmedia.studio/wp-content/uploads/2023/03/Photo-Rag-BW.jpg',
        'rice_paper'             => 'https://artmedia.studio/wp-content/uploads/2023/03/Rice-Paper.jpg',
        // Hahnemühle — Matt Textured
        'agave'                  => 'https://artmedia.studio/wp-content/uploads/2023/03/Agave.jpg',
        'hemp'                   => 'https://artmedia.studio/wp-content/uploads/2023/03/Hemp.jpg',
        'german_etching'         => 'https://artmedia.studio/wp-content/uploads/2023/03/German-Etching.jpg',
        'william_turner'         => 'https://artmedia.studio/wp-content/uploads/2023/03/William-Turner.jpg',
        // Hahnemühle — Glossy
        'fineart_baryta_satin'   => 'https://artmedia.studio/wp-content/uploads/2023/03/FineArt-Baryta-Satin.jpg',
        'photo_rag_satin'        => 'https://artmedia.studio/wp-content/uploads/2023/03/Photo-Rag-Satin.jpg',
        'photo_rag_baryta'       => 'https://artmedia.studio/wp-content/uploads/2023/03/Photo-Rag-Baryta.jpg',
        'fineart_baryta'         => 'https://artmedia.studio/wp-content/uploads/2023/03/FineArt-Baryta.jpg',
        'photo_rag_metallic'     => 'https://artmedia.studio/wp-content/uploads/2023/03/Phota-Rag-Metallic.jpg',
        // Hahnemühle — Canvas
        'cezanne_canvas'         => 'https://artmedia.studio/wp-content/uploads/2023/03/Cezanne-Canvas.jpg',
        'goya_canvas'            => 'https://artmedia.studio/wp-content/uploads/2023/03/Goya-Canvas.jpg',
        'daguerre_canvas'        => 'https://artmedia.studio/wp-content/uploads/2023/03/Daguerre-Canvas.jpg',
        'canvas_metallic'        => 'https://artmedia.studio/wp-content/uploads/2023/03/Canvas-Metallic.jpg',
        // Canson Infinity — Matt Smooth
        'baryta_photographique_ii_matt' => 'https://artmedia.studio/wp-content/uploads/2023/03/Baryta-Phographique-II-Matt.jpg',
        'rag_photographique_310'        => 'https://artmedia.studio/wp-content/uploads/2023/03/Rag-Photographique-310.jpg',
        'arches_88'                     => 'https://artmedia.studio/wp-content/uploads/2023/03/Arches-88.jpg',
        // Canson Infinity — Matt Textured
        'arches_bfk_rives_white'      => 'https://artmedia.studio/wp-content/uploads/2023/03/Arches-BFK-Rives-White.jpg',
        'arches_bfk_rives_pure_white' => 'https://artmedia.studio/wp-content/uploads/2023/03/Arches-BFK-Rives-Pure-White.jpg',
        'edition_etching_rag'         => 'https://artmedia.studio/wp-content/uploads/2023/03/Edition-Etching-Rag.jpg',
        'aquarelle_rag_310'           => 'https://artmedia.studio/wp-content/uploads/2023/03/Aquarelle-Rag-310.jpg',
        // Canson Infinity — Glossy
        'baryta_photographique_ii'    => 'https://artmedia.studio/wp-content/uploads/2023/03/Baryta-Photographique-II.jpg',
        'platine_fibre_rag'           => 'https://artmedia.studio/wp-content/uploads/2023/03/Platine-Fibre-Rag.jpg',
        'baryta_prestige_ii'          => 'https://artmedia.studio/wp-content/uploads/2023/03/Baryta-Prestige-II.jpg',
        // Canson Infinity — Canvas
        'photoart_pro_canvas_matte'       => 'https://artmedia.studio/wp-content/uploads/2023/03/PhotoArt-Pro-Canvas-Matte-395.jpg',
        'museum_pro_canvas_matte'         => 'https://artmedia.studio/wp-content/uploads/2023/03/Museum-Art-Pro-Canvas-Matte.jpg',
        'photoart_pro_canvas_lustre'      => 'https://artmedia.studio/wp-content/uploads/2023/03/PhotoArt-Pro-Canvas-Lustre-395.jpg',
        'museum_pro_canvas_lustre_canson' => 'https://artmedia.studio/wp-content/uploads/2023/03/Museum-Art-Pro-Canvas-Lustre.jpg',
    );
}

register_activation_hook( __FILE__, 'fac_activate' );
function fac_activate() {
    if ( ! get_option( 'fac_paper_data' ) ) {
        update_option( 'fac_paper_data', fac_get_default_paper_data() );
    }
    if ( ! get_option( 'fac_roll_widths' ) ) {
        update_option( 'fac_roll_widths', fac_get_default_roll_widths() );
    }
    if ( ! get_option( 'fac_mounting_rates' ) ) {
        update_option( 'fac_mounting_rates', fac_get_default_mounting_rates() );
    }
    if ( ! get_option( 'fac_turnaround_rates' ) ) {
        update_option( 'fac_turnaround_rates', fac_get_default_turnaround_rates() );
    }
    if ( ! get_option( 'fac_woocommerce_product_id' ) ) {
        update_option( 'fac_woocommerce_product_id', 0 );
    }
    if ( ! get_option( 'fac_inkjet_paper_data' ) ) {
        update_option( 'fac_inkjet_paper_data', fac_get_default_inkjet_paper_data() );
    }
    if ( ! get_option( 'fac_inkjet_woocommerce_product_id' ) ) {
        update_option( 'fac_inkjet_woocommerce_product_id', 0 );
    }
    // Seed defaults; preserve any admin overrides already stored.
    update_option(
        'fac_paper_images',
        fac_merge_paper_image_options( fac_get_default_paper_images(), get_option( 'fac_paper_images', array() ) )
    );
}

/*
 * Force-write the paper image URLs on every request if the plugin is already
 * active (activation hook won't re-run for existing installs).
 * We use a version stamp so it only writes once per plugin version.
 */
add_action( 'init', 'fac_sync_paper_images' );
function fac_sync_paper_images() {
    $stamped = get_option( 'fac_paper_images_version' );
    if ( $stamped === FAC_VERSION ) {
        return; // Already written for this version
    }
    update_option(
        'fac_paper_images',
        fac_merge_paper_image_options( fac_get_default_paper_images(), get_option( 'fac_paper_images', array() ) )
    );
    update_option( 'fac_paper_images_version', FAC_VERSION );
}

add_action( 'init', 'fac_seed_inkjet_paper_data' );
function fac_seed_inkjet_paper_data() {
    if ( get_option( 'fac_inkjet_paper_data' ) ) {
        return;
    }
    update_option( 'fac_inkjet_paper_data', fac_get_default_inkjet_paper_data() );
}

add_action( 'init', 'fac_sync_inkjet_paper_categories', 11 );
function fac_sync_inkjet_paper_categories() {
    $stored = get_option( 'fac_inkjet_paper_data' );
    if ( ! is_array( $stored ) || ! fac_inkjet_paper_data_needs_category_migration( $stored ) ) {
        return;
    }

    update_option( 'fac_inkjet_paper_data', fac_normalize_inkjet_paper_data( $stored ) );
}
