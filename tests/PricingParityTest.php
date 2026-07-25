<?php

use PHPUnit\Framework\TestCase;

class PricingParityTest extends TestCase {

    public function test_pricing_matrix_matches_expected_outputs() {
        $path     = __DIR__ . '/fixtures/pricing-matrix.json';
        $fixtures = json_decode( file_get_contents( $path ), true );

        $this->assertIsArray( $fixtures );

        foreach ( $fixtures as $fixture ) {
            $results = fac_calculate_price( $fixture['state'] );
            $label   = $fixture['name'] ?? 'unnamed fixture';

            $this->assertEqualsWithDelta(
                $fixture['expected']['totalPrice'],
                $results['totalPrice'],
                0.05,
                $label . ': totalPrice'
            );
            $this->assertSame(
                $fixture['expected']['selectedRule'],
                $results['selectedRule'],
                $label . ': selectedRule'
            );
            $this->assertSame(
                $fixture['expected']['selectedOrientation'],
                $results['selectedOrientation'],
                $label . ': selectedOrientation'
            );
            $this->assertSame(
                $fixture['expected']['nestingFactor'],
                $results['nestingFactor'],
                $label . ': nestingFactor'
            );
            $this->assertSame(
                $fixture['expected']['passes'],
                $results['passes'],
                $label . ': passes'
            );
        }
    }
}
