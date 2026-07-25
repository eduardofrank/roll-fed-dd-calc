<?php

use PHPUnit\Framework\TestCase;

class ShippingQuoteMethodTest extends TestCase {

    protected function setUp(): void {
        fac_reset_test_wc_state();
        // Real WooCommerce calls this on woocommerce_shipping_init; the test
        // bootstrap's add_action() is a no-op, so we invoke it directly.
        // It's guarded by class_exists() internally, so repeat calls are safe.
        fac_init_shipping_quote_class();
    }

    private function calculator_item( $mounting ) {
        return array(
            'quantity'        => 1,
            'data'            => new FAC_Test_WC_Cart_Item_Product(),
            'calculator_data' => array( 'state' => array( 'mounting' => $mounting ) ),
        );
    }

    /* ------------------------------------------------------------
       fac_package_has_gatorboard_mounting() — pure detection logic
    ------------------------------------------------------------ */

    public function test_empty_package_has_no_gatorboard_mounting() {
        $this->assertFalse( fac_package_has_gatorboard_mounting( array() ) );
        $this->assertFalse( fac_package_has_gatorboard_mounting( array( 'contents' => array() ) ) );
    }

    public function test_no_mounting_only_package_has_no_gatorboard_mounting() {
        $package = array(
            'contents' => array(
                'item_1' => $this->calculator_item( 'no_mounting' ),
            ),
        );

        $this->assertFalse( fac_package_has_gatorboard_mounting( $package ) );
    }

    public function test_white_gatorboard_item_is_detected() {
        $package = array(
            'contents' => array(
                'item_1' => $this->calculator_item( 'white_gatorboard' ),
            ),
        );

        $this->assertTrue( fac_package_has_gatorboard_mounting( $package ) );
    }

    public function test_black_gatorboard_item_is_detected() {
        $package = array(
            'contents' => array(
                'item_1' => $this->calculator_item( 'black_gatorboard' ),
            ),
        );

        $this->assertTrue( fac_package_has_gatorboard_mounting( $package ) );
    }

    public function test_mixed_cart_with_one_gatorboard_item_is_detected() {
        // Spec "Example 4": one rolled print (No Mounting) + one mounted
        // print (Black Gatorboard) in the same cart.
        $package = array(
            'contents' => array(
                'item_1' => $this->calculator_item( 'no_mounting' ),
                'item_2' => $this->calculator_item( 'black_gatorboard' ),
            ),
        );

        $this->assertTrue( fac_package_has_gatorboard_mounting( $package ) );
    }

    public function test_non_calculator_items_are_ignored_not_guessed() {
        // An ordinary WooCommerce product sharing the cart — no calculator_data at all.
        $package = array(
            'contents' => array(
                'item_1' => array(
                    'quantity' => 1,
                    'data'     => new FAC_Test_WC_Cart_Item_Product(),
                ),
            ),
        );

        $this->assertFalse( fac_package_has_gatorboard_mounting( $package ) );
    }

    public function test_malformed_calculator_data_is_ignored_gracefully() {
        $package = array(
            'contents' => array(
                'item_1' => array(
                    'quantity'        => 1,
                    'data'            => new FAC_Test_WC_Cart_Item_Product(),
                    'calculator_data' => array( 'state' => array() ), // no 'mounting' key
                ),
            ),
        );

        $this->assertFalse( fac_package_has_gatorboard_mounting( $package ) );
    }

    /* ------------------------------------------------------------
       fac_register_shipping_quote_method() — registration filter
    ------------------------------------------------------------ */

    public function test_registers_under_the_expected_method_id() {
        $methods = fac_register_shipping_quote_method( array( 'flat_rate' => 'WC_Shipping_Flat_Rate' ) );

        $this->assertSame( 'FAC_Shipping_Quote', $methods['fac_shipping_quote'] );
        // Existing methods must be preserved, not replaced.
        $this->assertSame( 'WC_Shipping_Flat_Rate', $methods['flat_rate'] );
    }

    /* ------------------------------------------------------------
       FAC_Shipping_Quote — the shipping method itself
    ------------------------------------------------------------ */

    public function test_class_is_defined_after_shipping_init() {
        $this->assertTrue( class_exists( 'FAC_Shipping_Quote' ) );
    }

    public function test_default_title_matches_required_spec_string() {
        $method = new FAC_Shipping_Quote();

        $this->assertSame( 'Shipping Quote (Calculated After Packaging)', $method->title );
        $this->assertSame( 'Shipping Quote (Calculated After Packaging)', $method->method_title );
    }

    public function test_default_cost_is_zero() {
        $method = new FAC_Shipping_Quote();

        $this->assertSame( '0', $method->cost );
    }

    public function test_unavailable_when_cart_has_no_mounting_only() {
        $method  = new FAC_Shipping_Quote();
        $package = array(
            'contents' => array( 'item_1' => $this->calculator_item( 'no_mounting' ) ),
        );

        $this->assertFalse( $method->is_available( $package ) );
    }

    public function test_available_when_cart_has_white_gatorboard() {
        $method  = new FAC_Shipping_Quote();
        $package = array(
            'contents' => array( 'item_1' => $this->calculator_item( 'white_gatorboard' ) ),
        );

        $this->assertTrue( $method->is_available( $package ) );
    }

    public function test_available_when_cart_has_black_gatorboard() {
        $method  = new FAC_Shipping_Quote();
        $package = array(
            'contents' => array( 'item_1' => $this->calculator_item( 'black_gatorboard' ) ),
        );

        $this->assertTrue( $method->is_available( $package ) );
    }

    public function test_available_when_mixed_cart_contains_any_gatorboard_item() {
        // Spec "Example 4" again, exercised through the method itself.
        $method  = new FAC_Shipping_Quote();
        $package = array(
            'contents' => array(
                'item_1' => $this->calculator_item( 'no_mounting' ),
                'item_2' => $this->calculator_item( 'black_gatorboard' ),
            ),
        );

        $this->assertTrue( $method->is_available( $package ) );
    }

    public function test_disabled_method_stays_unavailable_even_with_gatorboard_in_cart() {
        // Admin can still turn the method off per zone; that must win even
        // when a Gatorboard item is present.
        $method          = new FAC_Shipping_Quote();
        $method->enabled = 'no';
        $package         = array(
            'contents' => array( 'item_1' => $this->calculator_item( 'white_gatorboard' ) ),
        );

        $this->assertFalse( $method->is_available( $package ) );
    }

    public function test_calculate_shipping_adds_a_zero_cost_rate_using_the_configured_title() {
        $method        = new FAC_Shipping_Quote();
        $method->title = 'Custom Quote Label'; // simulates an admin override
        $package       = array(
            'contents' => array( 'item_1' => $this->calculator_item( 'white_gatorboard' ) ),
        );

        $method->calculate_shipping( $package );

        $this->assertCount( 1, $method->rates_added );
        $this->assertSame( 'Custom Quote Label', $method->rates_added[0]['label'] );
        $this->assertSame( 0.0, $method->rates_added[0]['cost'] );
    }
}
