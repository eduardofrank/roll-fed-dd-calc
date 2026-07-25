<?php

use PHPUnit\Framework\TestCase;

class SettingsSanitizeTest extends TestCase {

    public function test_sanitize_archival_data_filters_invalid_entries() {
        $raw = array(
            'Brand A' => array(
                'Matt' => array(
                    array(
                        'name'           => 'Paper One',
                        'slug'           => 'paper_one',
                        'rate'           => '0.0500',
                        'gsm'            => '310',
                        'availableRolls' => array( '44', '999', '44' ),
                        'description'    => '<b>desc</b>',
                        'imageUrl'       => 'https://example.com/test.jpg',
                    ),
                    array(
                        'name' => '',
                        'slug' => 'bad',
                    ),
                ),
            ),
            '' => array(
                'Matte' => array(),
            ),
        );

        $clean = fac_sanitize_archival_paper_data( $raw );

        $this->assertFalse( is_wp_error( $clean ) );
        $this->assertSame( array( '44' ), $clean['Brand A']['Matt'][0]['availableRolls'] );
        $this->assertSame( 'desc', $clean['Brand A']['Matt'][0]['description'] );
        $this->assertCount( 1, $clean['Brand A']['Matt'] );
    }

    public function test_sanitize_inkjet_data_requires_valid_records() {
        $raw = array(
            array(
                'name'           => '  Epson Luster ',
                'slug'           => 'EPSon_luster',
                'rate'           => '-1',
                'gsm'            => '260',
                'availableRolls' => array( '44', 'bogus' ),
                'description'    => 'Sample',
            ),
            array(
                'name' => '',
                'slug' => '',
            ),
        );

        $clean = fac_sanitize_inkjet_paper_data( $raw );

        $this->assertFalse( is_wp_error( $clean ) );
        $this->assertSame( 'Epson Luster', $clean[0]['name'] );
        $this->assertSame( 'epson_luster', $clean[0]['slug'] );
        $this->assertSame( 0.0, $clean[0]['rate'] );
        $this->assertSame( array( '44' ), $clean[0]['availableRolls'] );
        $this->assertSame( 'other', $clean[0]['category'] );
        $this->assertCount( 1, $clean );
    }

    public function test_sanitize_inkjet_data_preserves_valid_category() {
        $clean = fac_sanitize_inkjet_paper_data(
            array(
                array(
                    'name'           => 'Canvas Sample',
                    'slug'           => 'canvas_sample',
                    'category'       => 'canvas',
                    'rate'           => 0.05,
                    'gsm'            => 0,
                    'availableRolls' => array( '44' ),
                    'description'    => 'Sample canvas',
                ),
            )
        );

        $this->assertFalse( is_wp_error( $clean ) );
        $this->assertSame( 'canvas', $clean[0]['category'] );
    }

    public function test_default_inkjet_papers_include_expected_categories() {
        $papers = fac_get_default_inkjet_paper_data();
        $by_slug = array();

        foreach ( $papers as $paper ) {
            $by_slug[ $paper['slug'] ] = $paper['category'];
        }

        $this->assertSame( 'papers', $by_slug['epson_premium_luster_photo_260'] );
        $this->assertSame( 'canvas', $by_slug['artdeco_22_5_mil_canvas_metallic_pearl'] );
        $this->assertSame( 'vinyl_fabric', $by_slug['sihl_3988_classic_vinyl_psa_matte'] );
        $this->assertSame( 'other', $by_slug['sihl_3148_absolute_clear_film_with_interleaf_paper'] );
    }

    public function test_normalize_inkjet_paper_data_assigns_categories_from_slug() {
        $normalized = fac_normalize_inkjet_paper_data(
            array(
                array(
                    'name'           => 'Epson Premium Luster Photo 260 gsm',
                    'slug'           => 'epson_premium_luster_photo_260',
                    'rate'           => 0.0414,
                    'gsm'            => 260,
                    'availableRolls' => array( '44' ),
                    'description'    => 'Premium luster photo paper. Wt: 260 GSM',
                ),
                array(
                    'name'           => 'Sihl 3988 Classic Vinyl PSA Matte',
                    'slug'           => 'sihl_3988_classic_vinyl_psa_matte',
                    'rate'           => 0.0414,
                    'gsm'            => 0,
                    'availableRolls' => array( '44' ),
                    'description'    => 'Classic vinyl PSA matte.',
                ),
            )
        );

        $this->assertSame( 'papers', $normalized[0]['category'] );
        $this->assertSame( 'vinyl_fabric', $normalized[1]['category'] );
    }

    public function test_sanitize_roll_widths_rejects_empty_payload() {
        $result = fac_sanitize_roll_widths_data( array(
            array(
                'key'      => '',
                'usableCm' => 0,
            ),
        ) );

        $this->assertTrue( is_wp_error( $result ) );
        $this->assertSame( 'empty_roll_data', $result->get_error_code() );
    }

    public function test_sanitize_rates_enforces_non_negative_and_rush_not_below_standard() {
        $rates = fac_sanitize_rates_data(
            array(
                'inches' => array(
                    'white_gatorboard' => '-1',
                    'black_gatorboard' => '0.08',
                ),
                'centimeters' => array(
                    'white_gatorboard' => '0.01',
                    'black_gatorboard' => '-4',
                ),
            ),
            array(
                'standard' => '1.2',
                'rush'     => '1.1',
            )
        );

        $this->assertFalse( is_wp_error( $rates ) );
        $this->assertSame( 0.0, $rates['mounting']['inches']['white_gatorboard'] );
        $this->assertSame( 1.2, $rates['turnaround']['standard'] );
        $this->assertSame( 1.2, $rates['turnaround']['rush'] );
    }
}
