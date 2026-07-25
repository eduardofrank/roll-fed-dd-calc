<?php

use PHPUnit\Framework\TestCase;

class AjaxAddToCartFlowTest extends TestCase {

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

    private function post_payload( $product_id, $state, $price = null ) {
        $results = fac_calculate_price( $state );
        $price   = $price === null ? $results['totalPrice'] : $price;

        return array(
            'product_id' => $product_id,
            'quantity'   => $state['quantity'],
            'calculator_data' => array(
                'state'            => $state,
                'results'          => $results,
                'calculated_price' => $price,
            ),
        );
    }

    /**
     * WordPress slash-escapes request superglobals before handlers run and
     * fac_ajax_decode_json_payload() unslashes accordingly; posting raw JSON
     * would corrupt escape sequences (e.g. the ü in Hahnemühle).
     */
    private function set_posted_product_data( array $payload ) {
        $_POST['product_data'] = addslashes( wp_json_encode( $payload ) );
    }

    protected function setUp(): void {
        $GLOBALS['fac_test_options'] = array();
        $GLOBALS['fac_test_transients'] = array();
        $GLOBALS['fac_test_nonce_valid'] = true;
        fac_reset_test_wc_state();

        update_option( 'fac_paper_data', fac_get_default_paper_data() );
        update_option( 'fac_roll_widths', fac_get_default_roll_widths() );
        update_option( 'fac_mounting_rates', fac_get_default_mounting_rates() );
        update_option( 'fac_turnaround_rates', fac_get_default_turnaround_rates() );
        update_option( 'fac_woocommerce_product_id', 101 );

        $_POST = array();
        $_SERVER['REMOTE_ADDR'] = '127.0.0.1';
        $_SERVER['HTTP_USER_AGENT'] = 'phpunit-e2e';
    }

    public function test_add_to_cart_success_flow() {
        $GLOBALS['fac_test_wc_products'][101] = new FAC_Test_WC_Product( true, true, true );

        $state = $this->valid_state();
        $_POST['nonce'] = 'ok';
        $this->set_posted_product_data( $this->post_payload( 101, $state ) );

        try {
            fac_ajax_add_to_cart();
            $this->fail( 'Expected JSON response exception was not thrown.' );
        } catch ( FAC_Test_JSON_Response_Exception $response ) {
            $this->assertTrue( $response->success );
            $this->assertSame( 200, $response->status_code );
            $this->assertSame( '/cart', $response->data['cart_url'] );
        }
    }

    public function test_add_to_cart_nonce_failure() {
        $GLOBALS['fac_test_nonce_valid'] = false;
        $state = $this->valid_state();
        $_POST['nonce'] = 'bad';
        $this->set_posted_product_data( $this->post_payload( 101, $state ) );

        try {
            fac_ajax_add_to_cart();
            $this->fail( 'Expected JSON error was not thrown.' );
        } catch ( FAC_Test_JSON_Response_Exception $response ) {
            $this->assertFalse( $response->success );
            $this->assertSame( 403, $response->status_code );
            $this->assertSame( 'nonce_failed', $response->data['code'] );
        }
    }

    public function test_add_to_cart_rate_limited() {
        $state = $this->valid_state();
        $_POST['nonce'] = 'ok';
        $this->set_posted_product_data( $this->post_payload( 101, $state ) );

        $key = 'fac_rl_' . fac_request_fingerprint();
        $GLOBALS['fac_test_transients'][ $key ] = FAC_RATE_LIMIT_MAX_REQUESTS;

        try {
            fac_ajax_add_to_cart();
            $this->fail( 'Expected JSON error was not thrown.' );
        } catch ( FAC_Test_JSON_Response_Exception $response ) {
            $this->assertFalse( $response->success );
            $this->assertSame( 429, $response->status_code );
            $this->assertSame( 'rate_limited', $response->data['code'] );
        }
    }

    public function test_add_to_cart_product_id_mismatch() {
        $state = $this->valid_state();
        $_POST['nonce'] = 'ok';
        $this->set_posted_product_data( $this->post_payload( 999, $state ) );

        try {
            fac_ajax_add_to_cart();
            $this->fail( 'Expected JSON error was not thrown.' );
        } catch ( FAC_Test_JSON_Response_Exception $response ) {
            $this->assertFalse( $response->success );
            $this->assertSame( 409, $response->status_code );
            $this->assertSame( 'product_id_mismatch', $response->data['code'] );
        }
    }

    public function test_add_to_cart_price_mismatch() {
        $GLOBALS['fac_test_wc_products'][101] = new FAC_Test_WC_Product( true, true, true );
        $state = $this->valid_state();
        $_POST['nonce'] = 'ok';
        $this->set_posted_product_data( $this->post_payload( 101, $state, 1.00 ) );

        try {
            fac_ajax_add_to_cart();
            $this->fail( 'Expected JSON error was not thrown.' );
        } catch ( FAC_Test_JSON_Response_Exception $response ) {
            $this->assertFalse( $response->success );
            $this->assertSame( 409, $response->status_code );
            $this->assertSame( 'price_mismatch', $response->data['code'] );
        }
    }
}
