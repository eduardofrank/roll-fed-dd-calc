<?php

use PHPUnit\Framework\TestCase;

class OpsDigestTest extends TestCase {
    protected function setUp(): void {
        $GLOBALS['fac_test_options'] = array();
        $GLOBALS['fac_test_transients'] = array();
        fac_reset_test_wc_state();
    }

    public function test_digest_collects_orders_and_error_counts() {
        $GLOBALS['fac_test_order_query_results'] = array(
            new FAC_Test_WC_Order_Stub( 700 ),
            new FAC_Test_WC_Order_Stub( 701 ),
        );
        update_option( 'fac_error_counts', array( gmdate( 'Y-m-d', time() - DAY_IN_SECONDS ) => 3 ) );

        $data = fac_build_ops_digest_data();

        $this->assertSame( array( '700', '701' ), $data['processingOrders'] );
        $this->assertSame( 3, $data['errorsYesterday'] );
    }

    public function test_digest_sends_and_logs_when_enabled() {
        update_option( 'fac_ops_digest', array( 'enabled' => 1, 'recipient' => 'shop@example.com' ) );
        $GLOBALS['fac_test_order_query_results'] = array( new FAC_Test_WC_Order_Stub( 700 ) );

        $this->assertTrue( fac_send_ops_digest() );

        $mail = $GLOBALS['fac_test_mail'][0];
        $this->assertSame( 'shop@example.com', $mail['to'] );
        $this->assertStringContainsString( 'Operations digest', $mail['subject'] );
        $this->assertStringContainsString( '#700', $mail['message'] );
        $this->assertStringContainsString( 'Ops digest sent', $GLOBALS['fac_test_logs'][0]['message'] );
    }

    public function test_disabled_digest_does_not_send() {
        $this->assertFalse( fac_send_ops_digest() );
        $this->assertCount( 0, $GLOBALS['fac_test_mail'] );
    }

    public function test_empty_recipient_falls_back_to_admin_email() {
        update_option( 'admin_email', 'admin@example.com' );
        update_option( 'fac_ops_digest', array( 'enabled' => 1, 'recipient' => '' ) );

        $this->assertSame( 'admin@example.com', fac_get_ops_digest_settings()['recipient'] );
        $this->assertTrue( fac_send_ops_digest() );
        $this->assertSame( 'admin@example.com', $GLOBALS['fac_test_mail'][0]['to'] );
    }

    public function test_mail_failure_is_logged_as_error() {
        update_option( 'fac_ops_digest', array( 'enabled' => 1, 'recipient' => 'shop@example.com' ) );
        $GLOBALS['fac_test_mail_result'] = false;

        $this->assertFalse( fac_send_ops_digest() );
        $this->assertSame( 'error', $GLOBALS['fac_test_logs'][0]['level'] );
    }

    public function test_sanitizer_rejects_bad_email() {
        $this->assertTrue( is_wp_error( fac_sanitize_ops_digest_settings( array( 'enabled' => 1, 'recipient' => 'nope' ) ) ) );
        $this->assertSame(
            array( 'enabled' => 1, 'recipient' => 'shop@example.com' ),
            fac_sanitize_ops_digest_settings( array( 'enabled' => '1', 'recipient' => ' shop@example.com ' ) )
        );
    }
}
