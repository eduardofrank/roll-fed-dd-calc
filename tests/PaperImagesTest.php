<?php

use PHPUnit\Framework\TestCase;

class PaperImagesTest extends TestCase {

    public function test_merge_preserves_admin_overrides() {
        $defaults = array(
            'photo_rag' => 'https://example.com/default.jpg',
            'agave'     => 'https://example.com/agave.jpg',
        );
        $existing = array(
            'photo_rag' => 'https://example.com/custom.jpg',
        );

        $merged = fac_merge_paper_image_options( $defaults, $existing );

        $this->assertSame( 'https://example.com/custom.jpg', $merged['photo_rag'] );
        $this->assertSame( 'https://example.com/agave.jpg', $merged['agave'] );
    }

    public function test_merge_adds_new_default_slugs() {
        $defaults = array(
            'photo_rag' => 'https://example.com/default.jpg',
            'new_paper' => 'https://example.com/new.jpg',
        );
        $existing = array(
            'photo_rag' => 'https://example.com/custom.jpg',
        );

        $merged = fac_merge_paper_image_options( $defaults, $existing );

        $this->assertSame( 'https://example.com/custom.jpg', $merged['photo_rag'] );
        $this->assertSame( 'https://example.com/new.jpg', $merged['new_paper'] );
    }
}
