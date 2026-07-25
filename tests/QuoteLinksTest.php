<?php

use PHPUnit\Framework\TestCase;

class QuoteLinksTest extends TestCase {

    protected function setUp(): void {
        $GLOBALS['fac_test_options'] = array();

        update_option( 'fac_paper_data', fac_get_default_paper_data() );
        update_option( 'fac_roll_widths', fac_get_default_roll_widths() );
        update_option( 'fac_mounting_rates', fac_get_default_mounting_rates() );
        update_option( 'fac_turnaround_rates', fac_get_default_turnaround_rates() );
    }

    private function valid_state( $width = '20', $height = '30', $quantity = 1 ) {
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

    /** @param array $overrides Quote-level overrides; 'customPrice' targets item 0. */
    private function quote( $overrides = array() ) {
        $item_price = $overrides['customPrice'] ?? '';
        unset( $overrides['customPrice'] );

        return array_merge(
            array(
                'id'             => 1,
                'label'          => 'Jane Doe — wedding portrait',
                'token'          => str_repeat( 'a', 32 ),
                'calculatorType' => 'archival',
                'items'          => array(
                    array( 'state' => $this->valid_state(), 'customPrice' => $item_price ),
                ),
                'totalOverride'  => '',
                'editable'       => false,
                'reusable'       => true,
                'expires'        => '',
                'status'         => 'active',
                'uses'           => 0,
                'pageId'         => 0,
            ),
            $overrides
        );
    }

    private function form_input( $overrides = array() ) {
        return array_merge(
            array(
                'label'          => 'Jane Doe — wedding portrait',
                'calculatorType' => 'archival',
                'state'          => $this->valid_state(),
                'hasCustomPrice' => false,
                'customPrice'    => 0,
                'editable'       => true,
                'reusable'       => true,
                'expires'        => '',
                'status'         => 'active',
                'pageId'         => 12,
            ),
            $overrides
        );
    }

    /* ---------------------------------------------------------------
       Saving a link
    --------------------------------------------------------------- */

    public function test_label_is_required() {
        $result = fac_quote_sanitize_input( $this->form_input( array( 'label' => '   ' ) ) );

        $this->assertTrue( is_wp_error( $result ) );
        $this->assertSame( 'quote_label_required', $result->get_error_code() );
    }

    public function test_editable_link_without_custom_price_stays_editable() {
        $result = fac_quote_sanitize_input( $this->form_input() );

        $this->assertFalse( is_wp_error( $result ) );
        $this->assertTrue( $result['editable'] );
        $this->assertSame( '', $result['items'][0]['customPrice'] );
    }

    public function test_custom_price_forces_the_link_to_lock() {
        $result = fac_quote_sanitize_input(
            $this->form_input(
                array(
                    'hasCustomPrice' => true,
                    'customPrice'    => 250,
                    'editable'       => true, // explicitly asked for — must be overridden
                )
            )
        );

        $this->assertFalse( is_wp_error( $result ) );
        $this->assertFalse( $result['editable'] );
        $this->assertSame( 250.00, $result['items'][0]['customPrice'] );
    }

    public function test_custom_price_must_be_above_zero() {
        $result = fac_quote_sanitize_input(
            $this->form_input( array( 'hasCustomPrice' => true, 'customPrice' => 0 ) )
        );

        $this->assertTrue( is_wp_error( $result ) );
        $this->assertSame( 'quote_price_invalid', $result->get_error_code() );
    }

    public function test_custom_price_is_capped() {
        $result = fac_quote_sanitize_input(
            $this->form_input( array( 'hasCustomPrice' => true, 'customPrice' => FAC_QUOTE_MAX_PRICE + 1 ) )
        );

        $this->assertTrue( is_wp_error( $result ) );
        $this->assertSame( 'quote_price_too_large', $result->get_error_code() );
    }

    public function test_configuration_must_pass_calculator_validation() {
        // Wider than the 44in roll in both orientations — the calculator would
        // refuse to sell this, so a link must not be creatable for it either.
        $result = fac_quote_sanitize_input(
            $this->form_input( array( 'state' => $this->valid_state( '90', '90' ) ) )
        );

        $this->assertTrue( is_wp_error( $result ) );
        $this->assertSame( 'cannot_print', $result->get_error_code() );
    }

    public function test_gatorboard_limit_is_enforced_on_save() {
        $state             = $this->valid_state( '60', '99' );
        $state['mounting'] = 'white_gatorboard';

        $result = fac_quote_sanitize_input( $this->form_input( array( 'state' => $state ) ) );

        $this->assertTrue( is_wp_error( $result ) );
        $this->assertSame( 'gatorboard_too_large', $result->get_error_code() );
    }

    public function test_expiry_date_must_be_well_formed() {
        $this->assertSame( '', fac_quote_sanitize_date( '' ) );
        $this->assertSame( '2026-08-01', fac_quote_sanitize_date( '2026-08-01' ) );

        $bad = fac_quote_sanitize_date( '01/08/2026' );
        $this->assertTrue( is_wp_error( $bad ) );
        $this->assertSame( 'quote_date_invalid', $bad->get_error_code() );

        $impossible = fac_quote_sanitize_date( '2026-02-31' );
        $this->assertTrue( is_wp_error( $impossible ) );
    }

    /* ---------------------------------------------------------------
       Terms
    --------------------------------------------------------------- */

    public function test_disabled_link_is_refused() {
        $result = fac_quote_check_usable( $this->quote( array( 'status' => 'disabled' ) ), '2026-07-15' );

        $this->assertTrue( is_wp_error( $result ) );
        $this->assertSame( 'quote_disabled', $result->get_error_code() );
    }

    public function test_expiry_is_inclusive_of_the_final_day() {
        $quote = $this->quote( array( 'expires' => '2026-07-15' ) );

        $this->assertTrue( fac_quote_check_usable( $quote, '2026-07-15' ) );

        $expired = fac_quote_check_usable( $quote, '2026-07-16' );
        $this->assertTrue( is_wp_error( $expired ) );
        $this->assertSame( 'quote_expired', $expired->get_error_code() );
    }

    public function test_single_use_link_is_refused_after_first_purchase() {
        $fresh = $this->quote( array( 'reusable' => false, 'uses' => 0 ) );
        $this->assertTrue( fac_quote_check_usable( $fresh, '2026-07-15' ) );

        $spent = fac_quote_check_usable( $this->quote( array( 'reusable' => false, 'uses' => 1 ) ), '2026-07-15' );
        $this->assertTrue( is_wp_error( $spent ) );
        $this->assertSame( 'quote_used', $spent->get_error_code() );
    }

    public function test_reusable_link_survives_previous_purchases() {
        $quote = $this->quote( array( 'reusable' => true, 'uses' => 9 ) );

        $this->assertTrue( fac_quote_check_usable( $quote, '2026-07-15' ) );
    }

    /* ---------------------------------------------------------------
       Redemption
    --------------------------------------------------------------- */

    public function test_locked_link_ignores_a_tampered_client_state() {
        $quote = $this->quote( array( 'editable' => false ) );

        // Customer posts a much larger print than the studio configured.
        $tampered = $this->valid_state( '40', '60', 5 );
        $resolved = fac_quote_resolve( $quote, $tampered );

        $this->assertFalse( is_wp_error( $resolved ) );
        $this->assertTrue( $resolved['locked'] );
        $this->assertCount( 1, $resolved['lines'] );
        $this->assertSame( '20', $resolved['lines'][0]['state']['width'] );
        $this->assertSame( '30', $resolved['lines'][0]['state']['height'] );
        $this->assertSame( 1, $resolved['lines'][0]['state']['quantity'] );
        $this->assertEqualsWithDelta( 350.17, $resolved['total'], 0.05 );
    }

    public function test_custom_price_survives_a_tampered_client_state() {
        $quote = $this->quote( array( 'customPrice' => 250.00, 'editable' => false ) );

        $resolved = fac_quote_resolve( $quote, $this->valid_state( '40', '60', 5 ) );

        $this->assertFalse( is_wp_error( $resolved ) );
        $this->assertSame( 250.00, $resolved['total'] );
        $this->assertSame( '20', $resolved['lines'][0]['state']['width'] );
        $this->assertTrue( $resolved['lines'][0]['results']['customPriced'] );
        $this->assertSame( 250.00, $resolved['lines'][0]['results']['totalPrice'] );
    }

    public function test_custom_price_locks_even_if_the_editable_flag_was_tampered_with() {
        // Defends the case where a record is edited outside the admin form:
        // a custom price must never apply to customer-chosen options.
        $quote = $this->quote( array( 'customPrice' => 250.00, 'editable' => true ) );

        $this->assertTrue( fac_quote_is_locked( $quote ) );

        $resolved = fac_quote_resolve( $quote, $this->valid_state( '40', '60', 5 ) );

        $this->assertSame( '20', $resolved['lines'][0]['state']['width'] );
        $this->assertSame( 250.00, $resolved['total'] );
    }

    public function test_editable_link_uses_the_customer_state_at_the_engine_price() {
        $quote = $this->quote( array( 'editable' => true ) );

        // qty 3 of 20x30 needs 2 passes, so it costs double the qty 1 price.
        $resolved = fac_quote_resolve( $quote, $this->valid_state( '20', '30', 3 ) );

        $this->assertFalse( is_wp_error( $resolved ) );
        $this->assertFalse( $resolved['locked'] );
        $this->assertSame( 3, $resolved['lines'][0]['state']['quantity'] );
        $this->assertEqualsWithDelta( 700.34, $resolved['total'], 0.05 );
    }

    public function test_editable_link_still_rejects_an_invalid_customer_state() {
        $quote    = $this->quote( array( 'editable' => true ) );
        $resolved = fac_quote_resolve( $quote, $this->valid_state( '90', '90' ) );

        $this->assertTrue( is_wp_error( $resolved ) );
        $this->assertSame( 'cannot_print', $resolved->get_error_code() );
    }

    public function test_locked_link_reports_clearly_when_its_paper_is_withdrawn() {
        $quote = $this->quote( array( 'editable' => false ) );

        // The studio removes the paper catalogue after the link was sent out.
        update_option( 'fac_paper_data', array() );

        $resolved = fac_quote_resolve( $quote, $this->valid_state() );

        $this->assertTrue( is_wp_error( $resolved ) );
        $this->assertSame( 'quote_stale', $resolved->get_error_code() );
        $this->assertStringContainsString( 'no longer available', $resolved->get_error_message() );
    }

    public function test_a_request_without_a_quote_prices_normally() {
        $resolved = fac_quote_resolve( null, $this->valid_state() );

        $this->assertFalse( is_wp_error( $resolved ) );
        $this->assertFalse( $resolved['locked'] );
        $this->assertFalse( $resolved['customPriced'] );
        $this->assertCount( 1, $resolved['lines'] );
        $this->assertEqualsWithDelta( 350.17, $resolved['total'], 0.05 );
    }

    /* ---------------------------------------------------------------
       Tokens
    --------------------------------------------------------------- */

    public function test_tokens_are_random_and_well_formed() {
        $seen = array();

        for ( $i = 0; $i < 200; $i++ ) {
            $token = fac_quote_generate_token();
            $this->assertSame( 1, preg_match( '/^[a-f0-9]{32}$/', $token ) );
            $seen[ $token ] = true;
        }

        $this->assertCount( 200, $seen );
    }
}
