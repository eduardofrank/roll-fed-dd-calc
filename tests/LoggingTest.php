<?php

use PHPUnit\Framework\TestCase;

class LoggingTest extends TestCase {
    protected function setUp(): void {
        $GLOBALS['fac_test_options'] = array();
        $GLOBALS['fac_test_transients'] = array();
        fac_reset_test_wc_state();
    }

    public function test_log_writes_to_wc_logger_with_source_and_context() {
        fac_log_error( 'Something failed', array( 'paper' => 'photo_rag' ) );

        $this->assertCount( 1, $GLOBALS['fac_test_logs'] );
        $entry = $GLOBALS['fac_test_logs'][0];
        $this->assertSame( 'error', $entry['level'] );
        $this->assertSame( 'roll-fed-calc', $entry['source'] );
        $this->assertStringContainsString( '"paper":"photo_rag"', $entry['message'] );
    }

    public function test_unknown_levels_downgrade_to_info() {
        fac_log( 'catastrophic-nonsense', 'msg' );

        $this->assertSame( 'info', $GLOBALS['fac_test_logs'][0]['level'] );
    }

    public function test_error_logging_increments_daily_counter() {
        fac_log_error( 'boom' );
        fac_log_error( 'boom again' );
        fac_log_warning( 'not counted' );

        $counts = get_option( 'fac_error_counts' );
        $this->assertSame( 2, $counts[ gmdate( 'Y-m-d' ) ] );
        $this->assertCount( 1, $counts );
    }

    public function test_security_log_routes_through_central_logger_as_warning() {
        fac_security_log( 'rate_limited', array( 'ip' => '1.2.3.4' ) );

        $entry = $GLOBALS['fac_test_logs'][0];
        $this->assertSame( 'warning', $entry['level'] );
        $this->assertStringContainsString( 'Security: rate_limited', $entry['message'] );
    }

    public function test_audited_option_update_logs_old_and_new_leaf_values() {
        $GLOBALS['fac_test_current_user_id'] = 7;
        update_option( 'fac_audit_probe', array( 'white_gatorboard' => array( 'rate' => 10.0 ) ) );
        $GLOBALS['fac_test_logs'] = array();

        fac_update_option_audited( 'fac_audit_probe', array( 'white_gatorboard' => array( 'rate' => 12.5 ) ) );

        $entry = $GLOBALS['fac_test_logs'][0];
        $this->assertSame( 'info', $entry['level'] );
        $this->assertStringContainsString( 'Option updated: fac_audit_probe', $entry['message'] );
        $this->assertStringContainsString( '"white_gatorboard.rate":{"from":10,"to":12.5}', $entry['message'] );
        $this->assertStringContainsString( '"user_id":7', $entry['message'] );
        $this->assertSame( 12.5, get_option( 'fac_audit_probe' )['white_gatorboard']['rate'] );
    }

    public function test_import_writes_are_audited_with_via_import() {
        update_option( 'fac_roll_widths', fac_get_default_roll_widths() );
        $GLOBALS['fac_test_logs'] = array();

        $widths = fac_get_default_roll_widths();
        $first  = array_key_first( $widths );
        $widths[ $first ]['usable'] = 1.23;
        fac_update_option_audited( 'fac_roll_widths', $widths, 'import' );

        $this->assertStringContainsString( '"via":"import"', $GLOBALS['fac_test_logs'][0]['message'] );

        // Don't leak the mutated real option into later test classes.
        unset( $GLOBALS['fac_test_options']['fac_roll_widths'] );
    }

    public function test_audited_option_update_is_silent_when_nothing_changed() {
        $value = array( 'rate' => 2.0 );
        update_option( 'fac_audit_probe', $value );
        $GLOBALS['fac_test_logs'] = array();

        fac_update_option_audited( 'fac_audit_probe', $value );

        $this->assertCount( 0, $GLOBALS['fac_test_logs'] );
    }
}
