<?php

use PHPUnit\Framework\TestCase;

class CartRequoteTest extends TestCase {

    private function valid_state() {
        return array(
            'calculatorType'    => 'archival',
            'rollKey'           => '44',
            'brand'             => 'Hahnemühle',
            'finish'            => 'Matt Smooth',
            'selectedPaperSlug' => 'photo_rag',
            'units'             => 'inches',
            'width'             => '20',
            'height'            => '30',
            'quantity'          => 1,
            'mounting'          => 'no_mounting',
            'turnaround'        => 'standard',
        );
    }

    protected function setUp(): void {
        $GLOBALS['fac_test_options'] = array();
        $GLOBALS['fac_test_transients'] = array();
        fac_reset_test_wc_state();

        update_option( 'fac_paper_data', fac_get_default_paper_data() );
        update_option( 'fac_roll_widths', fac_get_default_roll_widths() );
        update_option( 'fac_mounting_rates', fac_get_default_mounting_rates() );
        update_option( 'fac_turnaround_rates', fac_get_default_turnaround_rates() );
    }

    private function put_item_in_cart( $state, $stored_price ) {
        WC()->cart->cart_contents['item-1'] = array(
            'quantity'        => 1,
            'calculator_data' => array(
                'state'            => $state,
                'results'          => fac_calculate_price( $state ),
                'calculated_price' => $stored_price,
            ),
        );
    }

    public function test_fresh_price_passes_silently() {
        $state = $this->valid_state();
        $this->put_item_in_cart( $state, round( fac_calculate_price( $state )['totalPrice'], 2 ) );

        fac_validate_cart_calculator_quotes();

        $this->assertCount( 0, $GLOBALS['fac_test_wc_notices'] );
        $this->assertArrayHasKey( 'item-1', WC()->cart->cart_contents );
    }

    public function test_stale_price_is_corrected_with_a_notice() {
        $state = $this->valid_state();
        $this->put_item_in_cart( $state, 1.00 );

        fac_validate_cart_calculator_quotes();

        $fresh = round( fac_calculate_price( $state )['totalPrice'], 2 );
        $this->assertSame( $fresh, WC()->cart->cart_contents['item-1']['calculator_data']['calculated_price'] );
        $this->assertCount( 1, $GLOBALS['fac_test_wc_notices'] );
        $this->assertSame( 'notice', $GLOBALS['fac_test_wc_notices'][0]['type'] );
        $this->assertStringContainsString( '$1.00', $GLOBALS['fac_test_wc_notices'][0]['message'] );
    }

    public function test_invalid_state_removes_the_item_with_an_error() {
        $state = $this->valid_state();
        $state['selectedPaperSlug'] = 'discontinued_paper';
        $this->put_item_in_cart( $state, 100.00 );

        fac_validate_cart_calculator_quotes();

        $this->assertArrayNotHasKey( 'item-1', WC()->cart->cart_contents );
        $this->assertSame( 'error', $GLOBALS['fac_test_wc_notices'][0]['type'] );
    }

    public function test_non_calculator_items_are_ignored() {
        WC()->cart->cart_contents['plain'] = array( 'quantity' => 2 );

        fac_validate_cart_calculator_quotes();

        $this->assertCount( 0, $GLOBALS['fac_test_wc_notices'] );
        $this->assertArrayHasKey( 'plain', WC()->cart->cart_contents );
    }

    /**
     * A custom-priced quote item's negotiated total must survive checkout
     * re-validation untouched — this is the load-bearing merge invariant.
     */
    public function test_custom_priced_quote_item_is_left_untouched() {
        $state = $this->valid_state();
        WC()->cart->cart_contents['q1'] = array(
            'quantity'        => 1,
            'calculator_data' => array(
                'state'            => $state,
                'results'          => fac_calculate_price( $state ),
                'calculated_price' => 999.00, // negotiated, far from the standard quote
                'quote'            => array( 'id' => 5, 'customPriced' => true, 'locked' => true ),
            ),
        );

        fac_validate_cart_calculator_quotes();

        $this->assertSame( 999.00, WC()->cart->cart_contents['q1']['calculator_data']['calculated_price'] );
        $this->assertCount( 0, $GLOBALS['fac_test_wc_notices'] );
    }

    public function test_locked_quote_item_with_invalid_state_is_still_kept() {
        $state = $this->valid_state();
        $state['selectedPaperSlug'] = 'discontinued_paper';
        WC()->cart->cart_contents['q2'] = array(
            'quantity'        => 1,
            'calculator_data' => array(
                'state'            => $state,
                'results'          => array(),
                'calculated_price' => 450.00,
                'quote'            => array( 'id' => 6, 'customPriced' => false, 'locked' => true ),
            ),
        );

        fac_validate_cart_calculator_quotes();

        // Locked item is preserved at its agreed price, not removed.
        $this->assertArrayHasKey( 'q2', WC()->cart->cart_contents );
        $this->assertSame( 450.00, WC()->cart->cart_contents['q2']['calculator_data']['calculated_price'] );
    }

    public function test_editable_standard_priced_quote_item_is_re_quoted() {
        $state = $this->valid_state();
        WC()->cart->cart_contents['q3'] = array(
            'quantity'        => 1,
            'calculator_data' => array(
                'state'            => $state,
                'results'          => fac_calculate_price( $state ),
                'calculated_price' => 1.00, // stale
                'quote'            => array( 'id' => 7, 'customPriced' => false, 'locked' => false ),
            ),
        );

        fac_validate_cart_calculator_quotes();

        $fresh = round( fac_calculate_price( $state )['totalPrice'], 2 );
        $this->assertSame( $fresh, WC()->cart->cart_contents['q3']['calculator_data']['calculated_price'] );
        $this->assertSame( 'notice', $GLOBALS['fac_test_wc_notices'][0]['type'] );
    }
}
