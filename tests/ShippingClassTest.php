<?php

use PHPUnit\Framework\TestCase;

class ShippingClassTest extends TestCase {

    protected function setUp(): void {
        fac_reset_test_wc_state();
    }

    /* ------------------------------------------------------------
       fac_get_shipping_class_for_mounting() — pure mapping logic
    ------------------------------------------------------------ */

    public function test_no_mounting_maps_to_rolled_print() {
        $this->assertSame( 'rolled-print', fac_get_shipping_class_for_mounting( 'no_mounting' ) );
    }

    public function test_white_gatorboard_maps_to_mounted_flat() {
        $this->assertSame( 'mounted-flat', fac_get_shipping_class_for_mounting( 'white_gatorboard' ) );
    }

    public function test_black_gatorboard_maps_to_mounted_flat() {
        $this->assertSame( 'mounted-flat', fac_get_shipping_class_for_mounting( 'black_gatorboard' ) );
    }

    public function test_unrecognized_mounting_defaults_to_rolled_print() {
        // Defensive default — mirrors the `?? 'no_mounting'` fallback used
        // everywhere else in the codebase for an unset/invalid mounting value.
        $this->assertSame( 'rolled-print', fac_get_shipping_class_for_mounting( 'plywood' ) );
        $this->assertSame( 'rolled-print', fac_get_shipping_class_for_mounting( '' ) );
    }

    public function test_shipping_class_slugs_match_the_configured_constants() {
        $this->assertSame( 'rolled-print', FAC_SHIPPING_CLASS_ROLLED_PRINT );
        $this->assertSame( 'mounted-flat', FAC_SHIPPING_CLASS_MOUNTED_FLAT );
    }

    /* ------------------------------------------------------------
       fac_get_shipping_class_term_id() — taxonomy term resolution
    ------------------------------------------------------------ */

    public function test_get_shipping_class_term_id_resolves_existing_term() {
        $GLOBALS['fac_test_shipping_class_terms']['rolled-print'] = 12;

        $this->assertSame( 12, fac_get_shipping_class_term_id( 'rolled-print' ) );
    }

    public function test_get_shipping_class_term_id_returns_zero_when_class_not_created_yet() {
        // No shipping classes registered — simulates a store where the
        // admin hasn't created "rolled-print" / "mounted-flat" yet.
        $this->assertSame( 0, fac_get_shipping_class_term_id( 'rolled-print' ) );
    }

    public function test_get_shipping_class_term_id_returns_zero_for_empty_slug() {
        $this->assertSame( 0, fac_get_shipping_class_term_id( '' ) );
    }

    /* ------------------------------------------------------------
       fac_set_custom_cart_item_shipping_class() — full cart integration
    ------------------------------------------------------------ */

    private function seed_shipping_class_terms() {
        $GLOBALS['fac_test_shipping_class_terms'] = array(
            'rolled-print' => 21,
            'mounted-flat' => 34,
        );
    }

    public function test_no_mounting_cart_item_is_assigned_rolled_print_class() {
        $this->seed_shipping_class_terms();

        $product = new FAC_Test_WC_Cart_Item_Product();
        $cart    = new FAC_Test_WC_Cart();
        $cart->cart_contents['item_1'] = array(
            'quantity'        => 1,
            'data'            => $product,
            'calculator_data' => array( 'state' => array( 'mounting' => 'no_mounting' ) ),
        );

        fac_set_custom_cart_item_shipping_class( $cart );

        $this->assertSame( 21, $product->get_shipping_class_id() );
    }

    public function test_white_gatorboard_cart_item_is_assigned_mounted_flat_class() {
        $this->seed_shipping_class_terms();

        $product = new FAC_Test_WC_Cart_Item_Product();
        $cart    = new FAC_Test_WC_Cart();
        $cart->cart_contents['item_1'] = array(
            'quantity'        => 1,
            'data'            => $product,
            'calculator_data' => array( 'state' => array( 'mounting' => 'white_gatorboard' ) ),
        );

        fac_set_custom_cart_item_shipping_class( $cart );

        $this->assertSame( 34, $product->get_shipping_class_id() );
    }

    public function test_black_gatorboard_cart_item_is_assigned_mounted_flat_class() {
        $this->seed_shipping_class_terms();

        $product = new FAC_Test_WC_Cart_Item_Product();
        $cart    = new FAC_Test_WC_Cart();
        $cart->cart_contents['item_1'] = array(
            'quantity'        => 1,
            'data'            => $product,
            'calculator_data' => array( 'state' => array( 'mounting' => 'black_gatorboard' ) ),
        );

        fac_set_custom_cart_item_shipping_class( $cart );

        $this->assertSame( 34, $product->get_shipping_class_id() );
    }

    public function test_missing_mounting_key_defaults_to_rolled_print() {
        $this->seed_shipping_class_terms();

        $product = new FAC_Test_WC_Cart_Item_Product();
        $cart    = new FAC_Test_WC_Cart();
        $cart->cart_contents['item_1'] = array(
            'quantity'        => 1,
            'data'            => $product,
            'calculator_data' => array( 'state' => array() ), // no 'mounting' key at all
        );

        fac_set_custom_cart_item_shipping_class( $cart );

        $this->assertSame( 21, $product->get_shipping_class_id() );
    }

    public function test_non_calculator_cart_items_are_left_untouched() {
        $this->seed_shipping_class_terms();

        $product = new FAC_Test_WC_Cart_Item_Product();
        $product->set_shipping_class_id( 999 ); // pre-existing class from a normal product

        $cart = new FAC_Test_WC_Cart();
        $cart->cart_contents['item_1'] = array(
            'quantity' => 1,
            'data'     => $product,
            // No 'calculator_data' — an ordinary WooCommerce product sharing the cart.
        );

        fac_set_custom_cart_item_shipping_class( $cart );

        $this->assertSame( 999, $product->get_shipping_class_id() );
    }

    public function test_shipping_class_left_unset_when_taxonomy_term_does_not_exist() {
        // Deliberately do NOT seed fac_test_shipping_class_terms, simulating
        // a site where the shipping classes haven't been created yet.
        $product = new FAC_Test_WC_Cart_Item_Product();
        $cart    = new FAC_Test_WC_Cart();
        $cart->cart_contents['item_1'] = array(
            'quantity'        => 1,
            'data'            => $product,
            'calculator_data' => array( 'state' => array( 'mounting' => 'white_gatorboard' ) ),
        );

        fac_set_custom_cart_item_shipping_class( $cart );

        // Should be safely skipped rather than assigning a bogus/zero class.
        $this->assertSame( 0, $product->get_shipping_class_id() );
    }

    public function test_mixed_cart_assigns_each_line_its_own_shipping_class() {
        // Reproduces the core scenario this feature exists for: many carts
        // share the same underlying WooCommerce product ID, so each line's
        // shipping class must be computed independently, not read from the
        // shared product itself.
        $this->seed_shipping_class_terms();

        $rolled_product = new FAC_Test_WC_Cart_Item_Product();
        $flat_product_1 = new FAC_Test_WC_Cart_Item_Product();
        $flat_product_2 = new FAC_Test_WC_Cart_Item_Product();

        $cart = new FAC_Test_WC_Cart();
        $cart->cart_contents = array(
            'item_1' => array(
                'quantity'        => 1,
                'data'            => $rolled_product,
                'calculator_data' => array( 'state' => array( 'mounting' => 'no_mounting' ) ),
            ),
            'item_2' => array(
                'quantity'        => 1,
                'data'            => $flat_product_1,
                'calculator_data' => array( 'state' => array( 'mounting' => 'white_gatorboard' ) ),
            ),
            'item_3' => array(
                'quantity'        => 2,
                'data'            => $flat_product_2,
                'calculator_data' => array( 'state' => array( 'mounting' => 'black_gatorboard' ) ),
            ),
        );

        fac_set_custom_cart_item_shipping_class( $cart );

        $this->assertSame( 21, $rolled_product->get_shipping_class_id() );
        $this->assertSame( 34, $flat_product_1->get_shipping_class_id() );
        $this->assertSame( 34, $flat_product_2->get_shipping_class_id() );
    }
}
