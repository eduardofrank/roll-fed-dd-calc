<?php

use PHPUnit\Framework\TestCase;

class ExportImportTest extends TestCase {

    protected function setUp(): void {
        $GLOBALS['fac_test_options'] = array();
        update_option( 'fac_paper_images', array(
            'photo_rag' => 'https://source.test/wp-content/uploads/photo-rag.jpg',
        ) );
        update_option( 'fac_paper_images_version', '2.3.0' );
    }

    public function test_export_relativizes_site_image_urls() {
        $payload = fac_ei_build_export( array( 'fac_paper_images' ) );

        $this->assertSame(
            '/wp-content/uploads/photo-rag.jpg',
            $payload['settings']['fac_paper_images']['photo_rag']
        );
        $this->assertSame( 'roll-fed-calc', $payload['_meta']['plugin'] );
    }

    public function test_import_absolutizes_and_clears_image_version_stamp() {
        $payload = array(
            'settings' => array(
                'fac_paper_images' => array(
                    'photo_rag' => '/wp-content/uploads/imported.jpg',
                ),
            ),
        );

        $result = fac_ei_apply_import( $payload, array( 'fac_paper_images' ), true );

        $this->assertSame( 1, $result['imported'] );
        $this->assertSame(
            'https://source.test/wp-content/uploads/imported.jpg',
            get_option( 'fac_paper_images' )['photo_rag']
        );
        $this->assertFalse( get_option( 'fac_paper_images_version' ) );
    }

    public function test_external_urls_survive_round_trip() {
        $external = 'https://cdn.example.com/paper.webp';
        update_option( 'fac_paper_images', array( 'agave' => $external ) );

        $payload = fac_ei_build_export( array( 'fac_paper_images' ) );
        $this->assertSame( $external, $payload['settings']['fac_paper_images']['agave'] );

        $GLOBALS['fac_test_options'] = array();
        $result = fac_ei_apply_import( $payload, array( 'fac_paper_images' ), true );
        $this->assertSame( 1, $result['imported'] );
        $this->assertSame( $external, get_option( 'fac_paper_images' )['agave'] );
    }

    public function test_import_rejects_payload_without_settings_array() {
        $result = fac_ei_apply_import(
            array( '_meta' => array( 'plugin' => 'roll-fed-calc' ) ),
            array( 'fac_paper_images' ),
            true
        );

        $this->assertTrue( is_wp_error( $result ) );
        $this->assertSame( 'bad_payload', $result->get_error_code() );
    }

    public function test_import_counts_skipped_missing_keys() {
        $payload = array(
            'settings' => array(
                'fac_roll_widths' => array(
                    array(
                        'key'         => '44',
                        'label'       => '44',
                        'widthInches' => 44,
                        'usableInches'=> 43.7,
                        'usableCm'    => 111,
                    ),
                ),
            ),
        );

        $result = fac_ei_apply_import(
            $payload,
            array( 'fac_roll_widths', 'fac_paper_images' ),
            false
        );

        $this->assertSame( 1, $result['imported'] );
        $this->assertSame( 1, $result['skipped'] );
    }

    public function test_import_rejects_invalid_option_shape() {
        $payload = array(
            'settings' => array(
                'fac_turnaround_rates' => 'rush',
            ),
        );

        $result = fac_ei_apply_import(
            $payload,
            array( 'fac_turnaround_rates' ),
            false
        );

        $this->assertTrue( is_wp_error( $result ) );
        $this->assertSame( 'invalid_turnaround_rates', $result->get_error_code() );
    }
}
