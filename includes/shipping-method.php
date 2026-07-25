<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Custom WooCommerce shipping method: "Shipping Quote (Calculated After Packaging)"
 *
 * A White/Black Gatorboard-mounted print can't be weighed or measured for an
 * accurate shipping cost until the finished piece is actually built and
 * boxed (see the "IMPORTANT NOTICE" banner shown in the calculator itself —
 * frontend/src/Calculator.jsx — for the matching customer-facing copy).
 * Rather than let the table-rate plugin guess at a per-lb cost for those
 * orders, this adds a $0.00 placeholder shipping option at checkout that
 * makes that expectation explicit, and only appears when it's relevant.
 *
 * This file does NOT touch, replace, or duplicate the existing shipping
 * class logic in cart-meta.php (fac_get_shipping_class_for_mounting /
 * fac_get_shipping_class_term_id) or the woocommerce_before_calculate_totals
 * overrides in ajax.php — it reuses that same mounting -> classification
 * helper as its single source of truth and otherwise leaves the existing
 * rolled-print / mounted-flat shipping-class behavior completely alone.
 * Table Rate Pro (or any other configured method) keeps working exactly as
 * before; this is purely an additional, conditionally-shown checkout option.
 */

/**
 * Register the method so WooCommerce lists it under
 * WooCommerce -> Settings -> Shipping -> [Zone] -> Add shipping method.
 * Registering here is safe at normal plugin-load time — it only hands
 * WooCommerce the class *name* as a string; WooCommerce doesn't try to
 * instantiate it until shipping is actually calculated, by which point
 * woocommerce_shipping_init (below) has already run.
 */
add_filter( 'woocommerce_shipping_methods', 'fac_register_shipping_quote_method' );
function fac_register_shipping_quote_method( $methods ) {
    $methods['fac_shipping_quote'] = 'FAC_Shipping_Quote';
    return $methods;
}

/**
 * Define the FAC_Shipping_Quote class only once WooCommerce has loaded its
 * own WC_Shipping_Method base class. This mirrors WooCommerce's own
 * documented pattern for custom shipping methods — defining the class at
 * plugin-load time (alongside the other includes/*.php files) would risk a
 * fatal "class not found" error if this plugin happens to load before
 * WooCommerce does.
 */
add_action( 'woocommerce_shipping_init', 'fac_init_shipping_quote_class' );
function fac_init_shipping_quote_class() {

    if ( class_exists( 'FAC_Shipping_Quote' ) ) {
        return;
    }

    class FAC_Shipping_Quote extends WC_Shipping_Method {

        public function __construct( $instance_id = 0 ) {
            $this->id                 = 'fac_shipping_quote';
            $this->instance_id        = absint( $instance_id );
            $this->method_title       = __( 'Shipping Quote (Calculated After Packaging)', 'fine-art-calculator' );
            $this->method_description = __( 'Zero-cost placeholder shipping option that only appears when the cart contains a Gatorboard-mounted print — its real shipping cost is quoted separately once the finished piece is packaged and measured.', 'fine-art-calculator' );

            // Per-zone instance settings, same as WooCommerce's own Flat Rate method.
            $this->supports = array(
                'shipping-zones',
                'instance-settings',
                'instance-settings-modal',
            );

            $this->init();
        }

        public function init() {
            $this->init_form_fields();
            $this->init_settings();

            $this->enabled = $this->get_option( 'enabled', 'yes' );
            $this->title   = $this->get_option( 'title', $this->method_title );
            $this->cost    = $this->get_option( 'cost', '0' );

            add_action( 'woocommerce_update_options_shipping_' . $this->id, array( $this, 'process_admin_options' ) );
        }

        public function init_form_fields() {
            $this->instance_form_fields = array(
                'enabled' => array(
                    'title'   => __( 'Enable', 'fine-art-calculator' ),
                    'type'    => 'checkbox',
                    'default' => 'yes',
                ),
                'title'   => array(
                    'title'       => __( 'Title', 'fine-art-calculator' ),
                    'type'        => 'text',
                    'description' => __( 'What the customer sees at checkout for this shipping option.', 'fine-art-calculator' ),
                    'default'     => __( 'Shipping Quote (Calculated After Packaging)', 'fine-art-calculator' ),
                    'desc_tip'    => true,
                ),
                'cost'    => array(
                    'title'       => __( 'Cost', 'fine-art-calculator' ),
                    'type'        => 'text',
                    'description' => __( 'Kept at 0 — the real shipping charge is invoiced separately once the mounted print is packaged and measured.', 'fine-art-calculator' ),
                    'default'     => '0',
                    'desc_tip'    => true,
                ),
            );
        }

        /**
         * Only offer this method for a package that contains at least one
         * Gatorboard-mounted calculator item (White or Black). Detection is
         * delegated to fac_package_has_gatorboard_mounting() below, which
         * reuses fac_get_shipping_class_for_mounting() from cart-meta.php —
         * the same mounting classification the shipping-class feature
         * already uses — so there's exactly one place in the codebase that
         * defines "what counts as a Gatorboard mounting."
         */
        public function is_available( $package ) {
            if ( ! parent::is_available( $package ) ) {
                return false;
            }

            return fac_package_has_gatorboard_mounting( $package );
        }

        public function calculate_shipping( $package = array() ) {
            $this->add_rate(
                array(
                    'id'      => $this->get_rate_id(),
                    'label'   => $this->title,
                    'cost'    => floatval( $this->cost ),
                    'package' => $package,
                )
            );
        }
    }
}

/**
 * True if any item in a WooCommerce shipping package is a calculator line
 * with a Gatorboard mounting selection (White or Black).
 *
 * Reads the exact same cart item data the rest of the plugin already
 * relies on — $cart_item['calculator_data']['state']['mounting'], set in
 * fac_ajax_add_to_cart() (includes/ajax.php) when the item is added to the
 * cart, with values 'no_mounting' | 'white_gatorboard' | 'black_gatorboard'
 * (see fac_validate_calculator_state() in includes/pricing.php). No new
 * field, no re-implemented mounting logic — this just asks the existing
 * fac_get_shipping_class_for_mounting() helper the same question the
 * shipping-class feature already asks it.
 *
 * @param array $package WooCommerce shipping package, as passed to
 *                        WC_Shipping_Method::is_available()/calculate_shipping().
 * @return bool
 */
function fac_package_has_gatorboard_mounting( $package ) {
    if ( empty( $package['contents'] ) || ! is_array( $package['contents'] ) ) {
        return false;
    }

    foreach ( $package['contents'] as $cart_item ) {
        if ( ! isset( $cart_item['calculator_data']['state']['mounting'] ) ) {
            continue; // Not a calculator line (or malformed) — ignore, don't guess.
        }

        $mounting = $cart_item['calculator_data']['state']['mounting'];

        if ( fac_get_shipping_class_for_mounting( $mounting ) === FAC_SHIPPING_CLASS_MOUNTED_FLAT ) {
            return true;
        }
    }

    return false;
}
