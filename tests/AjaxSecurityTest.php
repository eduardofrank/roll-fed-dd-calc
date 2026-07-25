<?php

use PHPUnit\Framework\TestCase;

class AjaxSecurityTest extends TestCase {

    protected function setUp(): void {
        $GLOBALS['fac_test_transients'] = array();
        $GLOBALS['fac_test_nonce_valid'] = true;
        $_SERVER['REMOTE_ADDR'] = '127.0.0.1';
        $_SERVER['HTTP_USER_AGENT'] = 'phpunit';
    }

    public function test_ajax_error_payload_includes_code_and_message() {
        $payload = fac_ajax_build_error_payload( 'Bad Code !!', 'Readable message' );

        $this->assertSame( 'badcode', $payload['code'] );
        $this->assertSame( 'Readable message', $payload['message'] );
    }

    public function test_product_data_payload_size_guard() {
        $small = str_repeat( 'a', FAC_MAX_CART_PAYLOAD_BYTES );
        $large = str_repeat( 'a', FAC_MAX_CART_PAYLOAD_BYTES + 1 );

        $this->assertFalse( fac_ajax_product_data_too_large( $small ) );
        $this->assertTrue( fac_ajax_product_data_too_large( $large ) );
    }

    public function test_rate_limit_triggers_after_threshold() {
        $limited = false;
        for ( $i = 0; $i <= FAC_RATE_LIMIT_MAX_REQUESTS; $i++ ) {
            $limited = fac_is_rate_limited();
        }

        $this->assertTrue( $limited );
    }

    public function test_json_decode_payload_rejects_invalid_json() {
        $result = fac_ajax_decode_json_payload( '{"bad":' );

        $this->assertTrue( is_wp_error( $result ) );
        $this->assertSame( 'invalid_json', $result->get_error_code() );
    }
}
