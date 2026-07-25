<?php

use PHPUnit\Framework\TestCase;

class PricingTest extends TestCase {

    private function base_state() {
        return array(
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

    public function test_calculate_price_standard_20x30_inches_qty1() {
        $results = fac_calculate_price( $this->base_state() );

        $this->assertEquals( 3, $results['selectedRule'] );
        $this->assertEquals( 'width', $results['selectedOrientation'] );
        $this->assertEquals( 2, $results['nestingFactor'] );
        $this->assertEquals( 1, $results['passes'] );
        // 1 pass × 76.2 cm feed × 111 cm roll × $0.0414/cm²
        $this->assertEqualsWithDelta( 350.17, $results['totalPrice'], 0.05 );
    }

    public function test_calculate_price_standard_20x30_inches_qty2() {
        $state             = $this->base_state();
        $state['quantity'] = 2;

        $results = fac_calculate_price( $state );

        $this->assertTrue( $results['canPrint'] );
        $this->assertTrue( $results['isValid'] );
        $this->assertEquals( 3, $results['selectedRule'] );
        $this->assertEquals( 'width', $results['selectedOrientation'] );
        $this->assertEquals( 2, $results['nestingFactor'] );
        $this->assertEquals( 1, $results['passes'] );
        $this->assertEqualsWithDelta( 350.17, $results['totalPrice'], 0.05 );
    }

    public function test_rush_multiplier_applied() {
        $state = $this->base_state();
        $state['turnaround'] = 'rush';

        $standard = fac_calculate_price( $this->base_state() );
        $rush     = fac_calculate_price( $state );

        $this->assertEqualsWithDelta( $standard['subtotal'] * 1.15, $rush['totalPrice'], 0.01 );
    }

    public function test_mounting_cost_added() {
        $state = $this->base_state();
        $state['mounting'] = 'white_gatorboard';

        $results = fac_calculate_price( $state );

        $this->assertGreaterThan( 0, $results['mountingCost'] );
        $this->assertEqualsWithDelta(
            $results['printCost'] + $results['mountingCost'],
            $results['subtotal'],
            0.01
        );
    }

    public function test_cannot_print_oversized_dimensions() {
        $state = $this->base_state();
        $state['width']  = '200';
        $state['height'] = '200';

        $results = fac_validate_calculator_state( $state );

        $this->assertTrue( is_wp_error( $results ) );
        $this->assertEquals( 'cannot_print', $results->get_error_code() );
    }

    public function test_invalid_paper_rejected() {
        $state = $this->base_state();
        $state['selectedPaperSlug'] = 'nonexistent_paper';

        $results = fac_validate_calculator_state( $state );

        $this->assertTrue( is_wp_error( $results ) );
        $this->assertEquals( 'invalid_paper', $results->get_error_code() );
    }

    public function test_invalid_roll_rejected() {
        $state = $this->base_state();
        $state['rollKey'] = '999';

        $results = fac_validate_calculator_state( $state );

        $this->assertTrue( is_wp_error( $results ) );
        $this->assertEquals( 'invalid_roll', $results->get_error_code() );
    }

    public function test_paper_roll_mismatch_rejected() {
        $state = $this->base_state();
        $state['rollKey']           = '64';
        $state['selectedPaperSlug'] = 'rice_paper'; // 44" only

        $results = fac_validate_calculator_state( $state );

        $this->assertTrue( is_wp_error( $results ) );
        $this->assertEquals( 'paper_roll_mismatch', $results->get_error_code() );
    }

    public function test_format_rush_fee_label_uses_configured_rate() {
        $label = fac_format_rush_fee_label( 100.0, 'rush' );

        $this->assertStringContainsString( '+15%', $label );
        $this->assertStringContainsString( '$15.00', $label );
    }

    public function test_format_rush_fee_label_null_for_standard() {
        $this->assertNull( fac_format_rush_fee_label( 100.0, 'standard' ) );
    }

    public function test_cart_display_rows_include_paper_and_dimensions() {
        $state   = $this->base_state();
        $results = fac_calculate_price( $state );

        $rows = fac_build_cart_item_display_rows( array(
            'state'            => $state,
            'results'          => $results,
            'calculated_price' => $results['totalPrice'],
        ) );

        $keys = array_column( $rows, 'key' );
        $this->assertContains( 'Paper Style', $keys );
        $this->assertContains( 'Dimensions', $keys );
        $this->assertContains( 'Roll Width', $keys );
    }

    public function test_estimated_weight_scales_with_quantity() {
        $state             = $this->base_state();
        $state['quantity'] = 3;

        $results = fac_calculate_price( $state );

        $this->assertEqualsWithDelta( 20 * 30 * 0.0006 * 3, $results['estimatedWeight'], 0.0001 );
        $this->assertEqualsWithDelta(
            $results['estimatedWeight'] / 3,
            $results['estimatedWeight'] / max( 1, $state['quantity'] ),
            0.0001
        );
    }

    public function test_rule3_uses_width_orientation_for_60x30_cm() {
        $state = array(
            'rollKey'           => '44',
            'brand'             => 'Hahnemühle',
            'finish'            => 'Matt Smooth',
            'selectedPaperSlug' => 'photo_rag',
            'units'             => 'centimeters',
            'width'             => '60',
            'height'            => '30',
            'quantity'          => 3,
            'mounting'          => 'no_mounting',
            'turnaround'        => 'standard',
        );

        $results = fac_calculate_price( $state );

        $this->assertEquals( 3, $results['selectedRule'] );
        $this->assertEquals( 'width', $results['selectedOrientation'] );
        $this->assertEquals( 1, $results['nestingFactor'] );
        $this->assertEquals( 30, $results['feedCm'] );
        $this->assertEquals( 3, $results['passes'] );
        $this->assertEqualsWithDelta( 413.59, $results['totalPrice'], 0.05 );
    }

    public function test_paper_rate_affects_price() {
        $state = $this->base_state();
        $state['finish']            = 'Glossy';
        $state['selectedPaperSlug'] = 'photo_rag_metallic';

        $results = fac_calculate_price( $state );

        $this->assertEqualsWithDelta( 0.0432, $results['paperRate'], 0.0001 );
        $this->assertEqualsWithDelta( 365.39, $results['totalPrice'], 0.05 );
    }

    public function test_gatorboard_rejected_when_print_exceeds_48x96_inches() {
        $state = $this->base_state();
        $state['width']     = '50';
        $state['height']    = '70';
        $state['mounting']  = 'white_gatorboard';

        $results = fac_validate_calculator_state( $state );

        $this->assertTrue( is_wp_error( $results ) );
        $this->assertEquals( 'gatorboard_too_large', $results->get_error_code() );
    }

    public function test_gatorboard_allowed_for_48x96_inches() {
        $state = $this->base_state();
        $state['rollKey']           = '64';
        $state['selectedPaperSlug'] = 'photo_rag_ultra_smooth';
        $state['width']             = '48';
        $state['height']            = '96';
        $state['mounting']          = 'black_gatorboard';

        $results = fac_validate_calculator_state( $state );

        $this->assertFalse( is_wp_error( $results ) );
        $this->assertGreaterThan( 0, $results['mountingCost'] );
    }

    public function test_gatorboard_allowed_when_rotated_to_fit_48x96() {
        $state = $this->base_state();
        $state['width']    = '96';
        $state['height']   = '40';
        $state['mounting'] = 'white_gatorboard';

        $results = fac_validate_calculator_state( $state );

        $this->assertFalse( is_wp_error( $results ) );
    }

    public function test_can_print_false_for_invalid_dimensions() {
        $results = fac_calculate_price( array(
            'rollKey'           => '44',
            'brand'             => 'Hahnemühle',
            'finish'            => 'Matt Smooth',
            'selectedPaperSlug' => 'photo_rag',
            'units'             => 'inches',
            'width'             => '0',
            'height'            => '30',
            'quantity'          => 1,
            'mounting'          => 'no_mounting',
            'turnaround'        => 'standard',
        ) );

        $this->assertFalse( $results['isValid'] );
        $this->assertFalse( $results['canPrint'] );
    }

    public function test_invalid_units_rejected() {
        $state = $this->base_state();
        $state['units'] = 'feet';

        $results = fac_validate_calculator_state( $state );

        $this->assertTrue( is_wp_error( $results ) );
        $this->assertEquals( 'invalid_units', $results->get_error_code() );
    }

    public function test_invalid_mounting_rejected() {
        $state = $this->base_state();
        $state['mounting'] = 'plywood';

        $results = fac_validate_calculator_state( $state );

        $this->assertTrue( is_wp_error( $results ) );
        $this->assertEquals( 'invalid_mounting', $results->get_error_code() );
    }

    public function test_invalid_turnaround_rejected() {
        $state = $this->base_state();
        $state['turnaround'] = 'overnight';

        $results = fac_validate_calculator_state( $state );

        $this->assertTrue( is_wp_error( $results ) );
        $this->assertEquals( 'invalid_turnaround', $results->get_error_code() );
    }

    public function test_dimensions_above_limit_rejected() {
        $state = $this->base_state();
        $state['width'] = '5000';

        $results = fac_validate_calculator_state( $state );

        $this->assertTrue( is_wp_error( $results ) );
        $this->assertEquals( 'dimensions_too_large', $results->get_error_code() );
    }

    public function test_quantity_above_limit_rejected() {
        $state = $this->base_state();
        $state['quantity'] = 9999;

        $results = fac_validate_calculator_state( $state );

        $this->assertTrue( is_wp_error( $results ) );
        $this->assertEquals( 'quantity_too_large', $results->get_error_code() );
    }

    public function test_cart_quantity_matches_state() {
        $state = $this->base_state();

        $this->assertTrue( fac_cart_quantity_matches_state( 1, $state ) );
        $this->assertFalse( fac_cart_quantity_matches_state( 3, $state ) );
    }

    public function test_centimeters_units_calculate_price() {
        $state = $this->base_state();
        $state['units']     = 'centimeters';
        $state['width']     = '50.8';
        $state['height']    = '76.2';
        $state['quantity']  = 2;

        $results = fac_calculate_price( $state );

        $this->assertTrue( $results['canPrint'] );
        $this->assertEqualsWithDelta( 350.17, $results['totalPrice'], 0.05 );
    }

    private function inkjet_state() {
        return array(
            'calculatorType'    => 'inkjet',
            'rollKey'           => '44',
            'selectedPaperSlug' => 'epson_premium_luster_photo_260',
            'units'             => 'inches',
            'width'             => '20',
            'height'            => '30',
            'quantity'          => 1,
            'mounting'          => 'no_mounting',
            'turnaround'        => 'standard',
        );
    }

    public function test_find_inkjet_paper_by_slug() {
        $paper = fac_find_inkjet_paper( 'epson_premium_luster_photo_260' );

        $this->assertNotNull( $paper );
        $this->assertEquals( 'Epson Premium Luster Photo 260 gsm', $paper['name'] );
    }

    public function test_inkjet_validate_without_brand_finish() {
        $results = fac_validate_calculator_state( $this->inkjet_state() );

        $this->assertFalse( is_wp_error( $results ) );
        $this->assertEqualsWithDelta( 350.17, $results['totalPrice'], 0.05 );
    }

    public function test_inkjet_cart_display_includes_print_type() {
        $state   = $this->inkjet_state();
        $results = fac_calculate_price( $state );

        $rows = fac_build_cart_item_display_rows( array(
            'state'            => $state,
            'results'          => $results,
            'calculated_price' => $results['totalPrice'],
        ) );

        $keys = array_column( $rows, 'key' );
        $this->assertContains( 'Print Type', $keys );
        $this->assertContains( 'Paper Style', $keys );
    }
}
