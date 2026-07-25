<?php

use PHPUnit\Framework\TestCase;

/**
 * Multi-item quotes: several prints on one link, each its own cart line.
 *
 * The load-bearing bits here are apportionment (line prices must sum to the
 * quoted total exactly) and line identity (two identical prints must not
 * collapse into one WooCommerce line).
 */
class QuoteMultiItemTest extends TestCase {

    protected function setUp(): void {
        $GLOBALS['fac_test_options']     = array();
        $GLOBALS['fac_test_transients']  = array();
        $GLOBALS['fac_test_nonce_valid'] = true;
        fac_reset_test_wc_state();
        fac_reset_test_post_state();
        fac_reset_test_frontend_state();

        update_option( 'fac_paper_data', fac_get_default_paper_data() );
        update_option( 'fac_roll_widths', fac_get_default_roll_widths() );
        update_option( 'fac_mounting_rates', fac_get_default_mounting_rates() );
        update_option( 'fac_turnaround_rates', fac_get_default_turnaround_rates() );
        update_option( 'fac_woocommerce_product_id', 101 );

        $GLOBALS['fac_test_wc_products'][101] = new FAC_Test_WC_Product( true, true, true, 'Archival Fine Art Print' );

        $_POST                      = array();
        $_SERVER['REMOTE_ADDR']     = '127.0.0.1';
        $_SERVER['HTTP_USER_AGENT'] = 'phpunit-multi';
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

    private function item( $width = '20', $height = '30', $quantity = 1, $custom = null ) {
        return array(
            'state'          => $this->state( $width, $height, $quantity ),
            'hasCustomPrice' => $custom !== null,
            'customPrice'    => $custom === null ? 0 : $custom,
        );
    }

    private function make( $items, $overrides = array() ) {
        $input = fac_quote_sanitize_input(
            array_merge(
                array(
                    'label'            => 'Gallery package',
                    'calculatorType'   => 'archival',
                    'items'            => $items,
                    'hasTotalOverride' => false,
                    'totalOverride'    => 0,
                    'editable'         => false,
                    'reusable'         => true,
                    'expires'          => '',
                    'status'           => 'active',
                    'pageId'           => 12,
                ),
                $overrides
            )
        );

        $this->assertFalse( is_wp_error( $input ), is_wp_error( $input ) ? $input->get_error_message() : '' );

        $id = fac_quote_save( $input );
        $this->assertFalse( is_wp_error( $id ) );

        return fac_quote_hydrate( $id );
    }

    private function send( $quote, $items = null ) {
        // The client posts the whole quote's total, the way the bundle does.
        $posted = $items !== null ? $items : $quote['items'];
        $total  = 0.0;
        foreach ( $posted as $item ) {
            $total += round( floatval( fac_calculate_price( $item['state'] )['totalPrice'] ), 2 );
        }

        $data = array(
            'product_id'      => 101,
            'quantity'        => 1,
            'quote_token'     => $quote['token'],
            'calculator_data' => array(
                'state'            => $quote['items'][0]['state'],
                'results'          => fac_calculate_price( $quote['items'][0]['state'] ),
                'calculated_price' => round( $total, 2 ),
            ),
        );

        if ( $items !== null ) {
            $data['calculator_data']['items'] = $items;
        }

        $_POST['nonce']        = 'ok';
        $_POST['product_data'] = addslashes( wp_json_encode( $data ) );

        try {
            fac_ajax_add_to_cart();
            $this->fail( 'Expected a JSON response.' );
        } catch ( FAC_Test_JSON_Response_Exception $response ) {
            return $response;
        }
    }

    /* ---------------------------------------------------------------
       Apportionment — the arithmetic that must not drift
    --------------------------------------------------------------- */

    public function test_apportioned_lines_always_sum_to_the_quoted_total() {
        // A deliberately awkward split: 100 / 3 doesn't divide into cents.
        $lines = fac_quote_apportion( 100.00, array( 1.0, 1.0, 1.0 ) );

        $this->assertCount( 3, $lines );
        $this->assertSame( 100.00, round( array_sum( $lines ), 2 ) );
    }

    public function test_apportionment_follows_what_each_line_is_worth() {
        // 900 split across lines worth 100 / 200 / 300 -> 150 / 300 / 450.
        $lines = fac_quote_apportion( 900.00, array( 100.0, 200.0, 300.0 ) );

        $this->assertSame( array( 150.00, 300.00, 450.00 ), $lines );
        $this->assertSame( 900.00, round( array_sum( $lines ), 2 ) );
    }

    public function test_apportionment_survives_awkward_ratios() {
        foreach ( array( 0.01, 9.99, 100.00, 1234.57, 99999.99 ) as $total ) {
            foreach ( array( array( 1.0, 2.0, 3.0, 7.0 ), array( 350.17, 350.17 ), array( 1.0, 999.0 ) ) as $weights ) {
                $lines = fac_quote_apportion( $total, $weights );

                $this->assertCount( count( $weights ), $lines );
                $this->assertSame(
                    round( $total, 2 ),
                    round( array_sum( $lines ), 2 ),
                    sprintf( 'total %s across %d lines', $total, count( $weights ) )
                );
            }
        }
    }

    public function test_apportionment_does_not_divide_by_zero() {
        $lines = fac_quote_apportion( 90.00, array( 0.0, 0.0, 0.0 ) );

        $this->assertSame( 90.00, round( array_sum( $lines ), 2 ) );
        $this->assertSame( 30.00, $lines[0] );
    }

    /* ---------------------------------------------------------------
       Pricing a multi-item quote
    --------------------------------------------------------------- */

    public function test_total_is_the_sum_of_the_items_by_default() {
        $quote    = $this->make( array( $this->item( '20', '30' ), $this->item( '20', '30' ) ) );
        $resolved = fac_quote_resolve( $quote, $this->state() );

        $this->assertCount( 2, $resolved['lines'] );
        $this->assertEqualsWithDelta( 700.34, $resolved['total'], 0.05 );
    }

    public function test_a_per_item_custom_price_replaces_only_that_item() {
        $quote    = $this->make( array( $this->item( '20', '30', 1, 100 ), $this->item( '20', '30' ) ) );
        $resolved = fac_quote_resolve( $quote, $this->state() );

        $this->assertSame( 100.00, $resolved['lines'][0]['price'] );
        $this->assertEqualsWithDelta( 350.17, $resolved['lines'][1]['price'], 0.05 );
        $this->assertEqualsWithDelta( 450.17, $resolved['total'], 0.05 );
    }

    public function test_a_total_override_replaces_the_sum_and_reprices_every_line() {
        $quote = $this->make(
            array( $this->item( '20', '30' ), $this->item( '20', '30' ) ),
            array( 'hasTotalOverride' => true, 'totalOverride' => 500 )
        );

        $resolved = fac_quote_resolve( $quote, $this->state() );

        $this->assertSame( 500.00, $resolved['total'] );
        $this->assertSame( 500.00, round( array_sum( array_column( $resolved['lines'], 'price' ) ), 2 ) );
        // Equal-value items, so an even split.
        $this->assertSame( 250.00, $resolved['lines'][0]['price'] );
        $this->assertSame( 250.00, $resolved['lines'][1]['price'] );
    }

    public function test_a_total_override_beats_per_item_prices() {
        $quote = $this->make(
            array( $this->item( '20', '30', 1, 100 ), $this->item( '20', '30', 1, 300 ) ),
            array( 'hasTotalOverride' => true, 'totalOverride' => 200 )
        );

        $resolved = fac_quote_resolve( $quote, $this->state() );

        $this->assertSame( 200.00, $resolved['total'] );
        // Apportioned by the item prices: 100:300 -> 50 / 150.
        $this->assertSame( 50.00, $resolved['lines'][0]['price'] );
        $this->assertSame( 150.00, $resolved['lines'][1]['price'] );
    }

    /* ---------------------------------------------------------------
       Terms
    --------------------------------------------------------------- */

    public function test_a_total_override_locks_the_link() {
        $quote = $this->make(
            array( $this->item() ),
            array( 'hasTotalOverride' => true, 'totalOverride' => 500, 'editable' => true )
        );

        $this->assertFalse( $quote['editable'] );
        $this->assertTrue( fac_quote_is_locked( $quote ) );
    }

    public function test_one_item_custom_price_locks_the_whole_link() {
        $quote = $this->make(
            array( $this->item( '20', '30' ), $this->item( '20', '30', 1, 100 ) ),
            array( 'editable' => true )
        );

        $this->assertFalse( $quote['editable'] );
        $this->assertTrue( fac_quote_is_locked( $quote ) );
    }

    public function test_a_multi_item_link_with_no_negotiated_figure_can_stay_editable() {
        $quote = $this->make(
            array( $this->item( '20', '30' ), $this->item( '8', '10' ) ),
            array( 'editable' => true )
        );

        $this->assertTrue( $quote['editable'] );
        $this->assertFalse( fac_quote_is_locked( $quote ) );
    }

    public function test_a_quote_needs_at_least_one_item() {
        $result = fac_quote_sanitize_input(
            array( 'label' => 'Empty', 'calculatorType' => 'archival', 'items' => array() )
        );

        $this->assertTrue( is_wp_error( $result ) );
        $this->assertSame( 'quote_no_items', $result->get_error_code() );
    }

    public function test_an_unprintable_item_names_itself() {
        $result = fac_quote_sanitize_input(
            array(
                'label'          => 'Gallery',
                'calculatorType' => 'archival',
                'items'          => array( $this->item( '20', '30' ), $this->item( '90', '90' ) ),
            )
        );

        $this->assertTrue( is_wp_error( $result ) );
        $this->assertStringContainsString( 'Print 2', $result->get_error_message() );
    }

    /* ---------------------------------------------------------------
       Cart
    --------------------------------------------------------------- */

    public function test_each_item_becomes_its_own_cart_line() {
        $quote    = $this->make( array( $this->item( '20', '30' ), $this->item( '8', '10' ) ) );
        $response = $this->send( $quote );

        $this->assertTrue( $response->success );
        $this->assertSame( 2, $response->data['added'] );

        $cart = WC()->cart->items;
        $this->assertCount( 2, $cart );
        $this->assertSame( '20', $cart[0]['cart_item_data']['calculator_data']['state']['width'] );
        $this->assertSame( '8', $cart[1]['cart_item_data']['calculator_data']['state']['width'] );
    }

    public function test_two_identical_prints_stay_two_distinct_cart_lines() {
        // WooCommerce hashes cart_item_data to identify a line, so without the
        // item index these two would merge into one line at quantity 2 — priced
        // as one. "Two of the same 10x10" is an ordinary thing to quote.
        $quote    = $this->make( array( $this->item( '10', '10' ), $this->item( '10', '10' ) ) );
        $response = $this->send( $quote );

        $this->assertSame( 2, $response->data['added'] );

        $cart = WC()->cart->items;
        $this->assertCount( 2, $cart );

        $first  = $cart[0]['cart_item_data']['calculator_data'];
        $second = $cart[1]['cart_item_data']['calculator_data'];

        $this->assertSame( 0, $first['quote']['itemIndex'] );
        $this->assertSame( 1, $second['quote']['itemIndex'] );
        $this->assertFalse(
            wp_json_encode( $first ) === wp_json_encode( $second ),
            'identical prints must still produce distinct cart_item_data'
        );
    }

    public function test_a_locked_multi_item_link_ignores_a_tampered_payload() {
        $quote = $this->make(
            array( $this->item( '20', '30' ), $this->item( '8', '10' ) ),
            array( 'hasTotalOverride' => true, 'totalOverride' => 400 )
        );

        // Customer posts two enormous prints instead.
        $tampered = array(
            array( 'state' => $this->state( '40', '60', 9 ) ),
            array( 'state' => $this->state( '40', '60', 9 ) ),
        );
        $response = $this->send( $quote, $tampered );

        $this->assertTrue( $response->success );

        $cart = WC()->cart->items;
        $this->assertCount( 2, $cart );
        $this->assertSame( '20', $cart[0]['cart_item_data']['calculator_data']['state']['width'] );
        $this->assertSame( '8', $cart[1]['cart_item_data']['calculator_data']['state']['width'] );
        $this->assertSame( 1, $cart[0]['quantity'] );

        $charged = array_sum(
            array_map(
                function ( $line ) {
                    return $line['cart_item_data']['calculator_data']['calculated_price'];
                },
                $cart
            )
        );
        $this->assertSame( 400.00, round( $charged, 2 ) );
    }

    public function test_an_editable_multi_item_link_accepts_customer_changes() {
        $quote = $this->make(
            array( $this->item( '20', '30' ), $this->item( '8', '10' ) ),
            array( 'editable' => true )
        );

        $changed = array(
            array( 'state' => $this->state( '20', '30', 3 ) ),
            array( 'state' => $this->state( '8', '10', 1 ) ),
        );
        $response = $this->send( $quote, $changed );

        $this->assertTrue( $response->success );

        $cart = WC()->cart->items;
        $this->assertSame( 3, $cart[0]['cart_item_data']['calculator_data']['state']['quantity'] );
        // qty 3 of 20x30 needs 2 passes, so it reprices to double.
        $this->assertEqualsWithDelta( 700.34, $cart[0]['cart_item_data']['calculator_data']['calculated_price'], 0.05 );
    }

    public function test_an_editable_link_cannot_have_prints_added_to_it() {
        $quote = $this->make(
            array( $this->item( '20', '30' ) ),
            array( 'editable' => true )
        );

        $response = $this->send(
            $quote,
            array(
                array( 'state' => $this->state( '20', '30' ) ),
                array( 'state' => $this->state( '40', '60' ) ), // smuggled in
            )
        );

        $this->assertFalse( $response->success );
        $this->assertSame( 'quote_item_count', $response->data['code'] );
    }

    /* ---------------------------------------------------------------
       The contract with assets/data-bridge.js

       The bridge is plain script read off window.facData at runtime, so
       neither this suite nor the React build imports it. That blind spot
       shipped a broken v2.7.0: this payload moved from `state` to `items`,
       the bridge kept guarding on `state`, and every quote link silently
       rendered an ordinary calculator. These assertions pin the PHP half;
       frontend/scripts/verify-data-bridge.mjs pins the JS half against the
       same fixture.
    --------------------------------------------------------------- */

    public function test_js_payload_matches_the_shape_the_data_bridge_reads() {
        $contract = json_decode( file_get_contents( __DIR__ . '/fixtures/quote-payload.json' ), true );

        $quote   = $this->make(
            array( $this->item( '20', '30' ), $this->item( '8', '10' ) ),
            array( 'hasTotalOverride' => true, 'totalOverride' => 900 )
        );
        $payload = fac_quote_build_js_payload( $quote );

        $this->assertIsArray( $payload );

        $expected = $contract['payloadKeys'];
        $actual   = array_keys( $payload );
        sort( $expected );
        sort( $actual );
        $this->assertSame( $expected, $actual, 'quote payload keys drifted from the data-bridge contract' );

        $expected_item = $contract['itemKeys'];
        $actual_item   = array_keys( $payload['items'][0] );
        sort( $expected_item );
        sort( $actual_item );
        $this->assertSame( $expected_item, $actual_item, 'quote item keys drifted from the data-bridge contract' );
    }

    public function test_js_payload_line_prices_sum_to_the_quoted_total() {
        $quote = $this->make(
            array( $this->item( '20', '30' ), $this->item( '8', '10' ), $this->item( '30', '40' ) ),
            array( 'hasTotalOverride' => true, 'totalOverride' => 900 )
        );

        $payload = fac_quote_build_js_payload( $quote );

        // What the tabs show has to add up to what the button charges.
        $this->assertSame( 900.00, $payload['price'] );
        $this->assertSame( 900.00, round( array_sum( array_column( $payload['items'], 'price' ) ), 2 ) );
    }

    public function test_js_payload_carries_every_item() {
        $quote   = $this->make( array( $this->item( '20', '30' ), $this->item( '8', '10' ) ) );
        $payload = fac_quote_build_js_payload( $quote );

        $this->assertCount( 2, $payload['items'] );
        $this->assertSame( '20', $payload['items'][0]['state']['width'] );
        $this->assertSame( '8', $payload['items'][1]['state']['width'] );
    }

    /* ---------------------------------------------------------------
       Links created before multi-item quotes existed
    --------------------------------------------------------------- */

    public function test_a_legacy_single_state_link_still_resolves() {
        // Written the way v2.6 stored it, then read back by the current code.
        $id = wp_insert_post( array( 'post_type' => FAC_QUOTE_POST_TYPE, 'post_title' => 'Old link' ) );
        update_post_meta( $id, '_fac_quote_token', str_repeat( 'b', 32 ) );
        update_post_meta( $id, '_fac_quote_type', 'archival' );
        update_post_meta( $id, '_fac_quote_state', $this->state() );
        update_post_meta( $id, '_fac_quote_custom_price', 250.00 );
        update_post_meta( $id, '_fac_quote_editable', '0' );
        update_post_meta( $id, '_fac_quote_reusable', '1' );
        update_post_meta( $id, '_fac_quote_status', 'active' );
        update_post_meta( $id, '_fac_quote_uses', 0 );
        update_post_meta( $id, '_fac_quote_page_id', 12 );

        $quote = fac_quote_hydrate( $id );

        $this->assertIsArray( $quote );
        $this->assertCount( 1, $quote['items'] );
        $this->assertSame( 250.00, $quote['items'][0]['customPrice'] );
        $this->assertTrue( fac_quote_is_locked( $quote ) );

        $resolved = fac_quote_resolve( $quote, $this->state( '40', '60', 5 ) );
        $this->assertSame( 250.00, $resolved['total'] );
        $this->assertSame( '20', $resolved['lines'][0]['state']['width'] );
    }
}
