<?php

use PHPUnit\Framework\TestCase;

/**
 * Drives the real fac_ajax_add_to_cart() endpoint with quote link payloads —
 * the path a customer's browser actually takes.
 */
class QuoteCartFlowTest extends TestCase {

    protected function setUp(): void {
        $GLOBALS['fac_test_options']     = array();
        $GLOBALS['fac_test_transients']  = array();
        $GLOBALS['fac_test_nonce_valid'] = true;
        fac_reset_test_wc_state();
        fac_reset_test_post_state();

        update_option( 'fac_paper_data', fac_get_default_paper_data() );
        update_option( 'fac_roll_widths', fac_get_default_roll_widths() );
        update_option( 'fac_mounting_rates', fac_get_default_mounting_rates() );
        update_option( 'fac_turnaround_rates', fac_get_default_turnaround_rates() );
        update_option( 'fac_woocommerce_product_id', 101 );

        $GLOBALS['fac_test_wc_products'][101] = new FAC_Test_WC_Product( true, true, true );

        $_POST                     = array();
        $_SERVER['REMOTE_ADDR']    = '127.0.0.1';
        $_SERVER['HTTP_USER_AGENT'] = 'phpunit-quotes';
    }

    private function state( $width = '20', $height = '30', $quantity = 1 ) {
        return array(
            'calculatorType'    => 'archival',
            'rollKey'           => '44',
            'brand'             => 'Hahnemühle',
            'finish'            => 'Matt Smooth',
            'selectedPaperSlug' => 'photo_rag',
            'units'             => 'inches',
            'width'             => $width,
            'height'            => $height,
            'quantity'          => $quantity,
            'mounting'          => 'no_mounting',
            'turnaround'        => 'standard',
        );
    }

    /** Create a saved quote link and return its hydrated record. */
    private function make_quote( $overrides = array() ) {
        $input = fac_quote_sanitize_input(
            array_merge(
                array(
                    'label'          => 'Jane Doe — wedding portrait',
                    'calculatorType' => 'archival',
                    'state'          => $this->state(),
                    'hasCustomPrice' => false,
                    'customPrice'    => 0,
                    'editable'       => true,
                    'reusable'       => true,
                    'expires'        => '',
                    'status'         => 'active',
                    'pageId'         => 12,
                ),
                $overrides
            )
        );

        $this->assertFalse( is_wp_error( $input ), 'fixture quote should be valid' );

        $id = fac_quote_save( $input );
        $this->assertFalse( is_wp_error( $id ) );

        return fac_quote_hydrate( $id );
    }

    /** Populate $_POST the way WordPress does — see AjaxAddToCartFlowTest. */
    private function post( $payload ) {
        $_POST['nonce']        = 'ok';
        $_POST['product_data'] = addslashes( wp_json_encode( $payload ) );
    }

    private function payload( $state, $price, $token = '' ) {
        $data = array(
            'product_id'      => 101,
            'quantity'        => $state['quantity'],
            'calculator_data' => array(
                'state'            => $state,
                'results'          => fac_calculate_price( $state ),
                'calculated_price' => $price,
            ),
        );

        if ( $token ) {
            $data['quote_token'] = $token;
        }

        return $data;
    }

    private function send( $payload ) {
        $this->post( $payload );

        try {
            fac_ajax_add_to_cart();
            $this->fail( 'Expected a JSON response.' );
        } catch ( FAC_Test_JSON_Response_Exception $response ) {
            return $response;
        }
    }

    /* ---------------------------------------------------------------
       Round trip
    --------------------------------------------------------------- */

    public function test_saved_quote_round_trips_with_a_shareable_url() {
        $quote = $this->make_quote();

        $this->assertSame( 1, preg_match( '/^[a-f0-9]{32}$/', $quote['token'] ) );
        $this->assertSame( 'Jane Doe — wedding portrait', $quote['label'] );
        $this->assertSame( 0, $quote['uses'] );
        $this->assertStringContainsString( 'fac_quote=' . $quote['token'], fac_quote_build_url( $quote ) );
        $this->assertSame( $quote['id'], fac_quote_get_by_token( $quote['token'] )['id'] );
    }

    public function test_unknown_token_is_rejected() {
        $this->assertNull( fac_quote_get_by_token( str_repeat( 'f', 32 ) ) );
        $this->assertNull( fac_quote_get_by_token( 'not-a-token' ) );

        $response = $this->send( $this->payload( $this->state(), 350.17, str_repeat( 'f', 32 ) ) );

        $this->assertFalse( $response->success );
        $this->assertSame( 404, $response->status_code );
        $this->assertSame( 'quote_not_found', $response->data['code'] );
    }

    /* ---------------------------------------------------------------
       Locked links
    --------------------------------------------------------------- */

    public function test_locked_link_charges_the_quoted_price_despite_a_tampered_payload() {
        $quote = $this->make_quote( array( 'hasCustomPrice' => true, 'customPrice' => 250, 'editable' => true ) );
        $this->assertTrue( $quote['editable'] === false, 'custom price must force the link to lock' );

        // Customer posts a 40x60 at qty 5 while claiming the quoted $250.
        $tampered = $this->state( '40', '60', 5 );
        $response = $this->send( $this->payload( $tampered, 250.00, $quote['token'] ) );

        $this->assertTrue( $response->success );

        $cart = WC()->cart->items;
        $this->assertCount( 1, $cart );

        $line = end( $cart );
        $this->assertSame( 250.00, $line['cart_item_data']['calculator_data']['calculated_price'] );
        $this->assertSame( '20', $line['cart_item_data']['calculator_data']['state']['width'] );
        $this->assertSame( '30', $line['cart_item_data']['calculator_data']['state']['height'] );
        $this->assertSame( 1, $line['quantity'], 'a locked link dictates quantity too' );
    }

    public function test_locked_link_at_the_engine_price_still_ignores_a_tampered_payload() {
        $quote = $this->make_quote( array( 'editable' => false ) );

        $response = $this->send( $this->payload( $this->state( '40', '60', 5 ), 350.17, $quote['token'] ) );

        $this->assertTrue( $response->success );

        $line = end( WC()->cart->items );
        $this->assertSame( '20', $line['cart_item_data']['calculator_data']['state']['width'] );
        $this->assertEqualsWithDelta( 350.17, $line['cart_item_data']['calculator_data']['calculated_price'], 0.05 );
    }

    /* ---------------------------------------------------------------
       Editable links
    --------------------------------------------------------------- */

    public function test_editable_link_lets_the_customer_change_options_and_reprices() {
        $quote = $this->make_quote( array( 'editable' => true ) );

        // qty 3 needs 2 passes -> double the qty 1 price.
        $state    = $this->state( '20', '30', 3 );
        $response = $this->send( $this->payload( $state, fac_calculate_price( $state )['totalPrice'], $quote['token'] ) );

        $this->assertTrue( $response->success );

        $line = end( WC()->cart->items );
        $this->assertSame( 3, $line['cart_item_data']['calculator_data']['state']['quantity'] );
        $this->assertEqualsWithDelta( 700.34, $line['cart_item_data']['calculator_data']['calculated_price'], 0.05 );
    }

    public function test_editable_link_still_enforces_the_client_price_check() {
        $quote = $this->make_quote( array( 'editable' => true ) );

        $response = $this->send( $this->payload( $this->state(), 1.00, $quote['token'] ) );

        $this->assertFalse( $response->success );
        $this->assertSame( 'price_mismatch', $response->data['code'] );
    }

    /* ---------------------------------------------------------------
       Terms enforced at the endpoint
    --------------------------------------------------------------- */

    public function test_disabled_link_cannot_be_redeemed() {
        $quote = $this->make_quote( array( 'status' => 'disabled' ) );

        $response = $this->send( $this->payload( $this->state(), 350.17, $quote['token'] ) );

        $this->assertFalse( $response->success );
        $this->assertSame( 409, $response->status_code );
        $this->assertSame( 'quote_disabled', $response->data['code'] );
    }

    public function test_single_use_link_is_spent_by_the_first_order() {
        $quote = $this->make_quote( array( 'reusable' => false ) );

        $this->assertTrue( $this->send( $this->payload( $this->state(), 350.17, $quote['token'] ) )->success );

        // Nothing is spent until an order exists.
        $this->assertSame( 0, fac_quote_hydrate( $quote['id'] )['uses'] );

        fac_quote_mark_used( $quote['id'], 5001 );

        $refreshed = fac_quote_hydrate( $quote['id'] );
        $this->assertSame( 1, $refreshed['uses'] );

        $response = $this->send( $this->payload( $this->state(), 350.17, $quote['token'] ) );
        $this->assertFalse( $response->success );
        $this->assertSame( 'quote_used', $response->data['code'] );
    }

    public function test_reusable_link_survives_an_order() {
        $quote = $this->make_quote( array( 'reusable' => true ) );
        fac_quote_mark_used( $quote['id'], 5001 );

        $this->assertTrue( $this->send( $this->payload( $this->state(), 350.17, $quote['token'] ) )->success );
        $this->assertSame( 1, fac_quote_hydrate( $quote['id'] )['uses'] );
    }

    public function test_deleting_a_link_stops_it_working() {
        $quote = $this->make_quote();
        $token = $quote['token'];

        $this->assertTrue( fac_quote_delete( $quote['id'] ) );
        $this->assertNull( fac_quote_get_by_token( $token ) );

        $response = $this->send( $this->payload( $this->state(), 350.17, $token ) );
        $this->assertSame( 'quote_not_found', $response->data['code'] );
    }

    /* ---------------------------------------------------------------
       Cart display
    --------------------------------------------------------------- */

    public function test_quoted_price_replaces_the_cost_breakdown_in_the_cart() {
        $quote = $this->make_quote( array( 'hasCustomPrice' => true, 'customPrice' => 250 ) );
        $this->send( $this->payload( $this->state(), 250.00, $quote['token'] ) );

        $line = end( WC()->cart->items );
        $rows = fac_build_cart_item_display_rows( $line['cart_item_data']['calculator_data'] );
        $keys = array_column( $rows, 'key' );

        $this->assertContains( 'Pricing', $keys );
        $this->assertContains( 'Quoted by ArtMedia Studio', array_column( $rows, 'value' ) );
        // Component costs would no longer sum to the quoted total.
        $this->assertFalse( in_array( 'Print Cost', $keys, true ) );
    }

    public function test_engine_priced_cart_still_shows_the_breakdown() {
        $quote = $this->make_quote( array( 'editable' => true ) );
        $this->send( $this->payload( $this->state(), 350.17, $quote['token'] ) );

        $line = end( WC()->cart->items );
        $keys = array_column( fac_build_cart_item_display_rows( $line['cart_item_data']['calculator_data'] ), 'key' );

        $this->assertContains( 'Print Cost', $keys );
        $this->assertFalse( in_array( 'Pricing', $keys, true ) );
    }

    /* ---------------------------------------------------------------
       Normal (non-quote) traffic is untouched
    --------------------------------------------------------------- */

    public function test_a_cart_request_without_a_token_behaves_exactly_as_before() {
        $response = $this->send( $this->payload( $this->state(), 350.17 ) );

        $this->assertTrue( $response->success );

        $line = end( WC()->cart->items );
        $this->assertFalse( isset( $line['cart_item_data']['calculator_data']['quote'] ) );
        $this->assertEqualsWithDelta( 350.17, $line['cart_item_data']['calculator_data']['calculated_price'], 0.05 );
    }
}
