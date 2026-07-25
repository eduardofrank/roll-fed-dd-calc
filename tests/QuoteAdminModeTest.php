<?php

use PHPUnit\Framework\TestCase;

/**
 * Quote authoring moved into the front-end calculator, which means an admin-only
 * surface now renders on a public page. fac_quote_build_admin_payload() is the
 * boundary: if it ever returns non-null for a non-admin, the quote list and the
 * admin nonce leak. Most of this file exists to hold that line.
 */
class QuoteAdminModeTest extends TestCase {

    protected function setUp(): void {
        $GLOBALS['fac_test_options'] = array();
        fac_reset_test_post_state();
        fac_reset_test_frontend_state();

        update_option( 'fac_paper_data', fac_get_default_paper_data() );
        update_option( 'fac_roll_widths', fac_get_default_roll_widths() );
        update_option( 'fac_mounting_rates', fac_get_default_mounting_rates() );
        update_option( 'fac_turnaround_rates', fac_get_default_turnaround_rates() );
        update_option( 'fac_woocommerce_product_id', 101 );
    }

    private function on_calculator_page( $shortcode = 'fine_art_calculator_embed' ) {
        $GLOBALS['fac_test_is_singular']  = true;
        $GLOBALS['fac_test_current_post'] = (object) array(
            'ID'           => 12,
            'post_title'   => 'Fine Art Calculator',
            'post_content' => 'Configure your print: [' . $shortcode . ']',
        );
    }

    private function sign_in_as_admin() {
        $GLOBALS['fac_test_logged_in'] = true;
        $GLOBALS['fac_test_caps']      = array( 'manage_options' => true );
    }

    private function request_authoring_mode() {
        $_GET['fac_quote_admin'] = '1';
    }

    /* ---------------------------------------------------------------
       The capability boundary
    --------------------------------------------------------------- */

    public function test_authoring_is_off_unless_it_is_asked_for() {
        $this->sign_in_as_admin();

        $this->assertFalse( fac_quote_admin_mode_active() );
        $this->assertNull( fac_quote_build_admin_payload( 'archival' ) );
    }

    public function test_logged_out_visitor_cannot_open_authoring_mode() {
        $this->request_authoring_mode();

        $this->assertFalse( fac_quote_admin_mode_active() );
        $this->assertNull( fac_quote_build_admin_payload( 'archival' ) );
    }

    public function test_logged_in_non_admin_cannot_open_authoring_mode() {
        $GLOBALS['fac_test_logged_in'] = true;
        $GLOBALS['fac_test_caps']      = array( 'read' => true, 'edit_posts' => true );
        $this->request_authoring_mode();

        $this->assertFalse( fac_quote_admin_mode_active() );
        $this->assertNull( fac_quote_build_admin_payload( 'archival' ) );
    }

    public function test_admin_who_asks_gets_the_authoring_payload() {
        $this->on_calculator_page();
        $this->sign_in_as_admin();
        $this->request_authoring_mode();

        $this->assertTrue( fac_quote_admin_mode_active() );

        $payload = fac_quote_build_admin_payload( 'archival' );

        $this->assertIsArray( $payload );
        $this->assertTrue( $payload['active'] );
        $this->assertIsArray( $payload['quotes'] );
        $this->assertSame( 12, $payload['pageId'] );
        $this->assertStringContainsString( 'test-nonce-', $payload['nonce'] );
    }

    public function test_a_public_page_render_never_carries_the_admin_nonce_or_quote_list() {
        // A real link exists, so there is something worth leaking.
        fac_quote_save(
            fac_quote_sanitize_input(
                array(
                    'label'          => 'Confidential — Jane Doe',
                    'calculatorType' => 'archival',
                    'state'          => $this->state(),
                    'hasCustomPrice' => true,
                    'customPrice'    => 250,
                    'editable'       => false,
                    'reusable'       => true,
                    'expires'        => '',
                    'status'         => 'active',
                    'pageId'         => 12,
                )
            )
        );

        $this->on_calculator_page();
        $this->request_authoring_mode(); // anonymous visitor forging the query arg

        $data = fac_build_js_data( 'archival', null, '' );
        $json = wp_json_encode( $data );

        $this->assertNull( $data['quoteAdmin'] );
        $this->assertFalse( strpos( $json, 'Confidential' ) !== false, 'quote labels must not reach a public page' );
        $this->assertFalse( strpos( $json, 'test-nonce-' . md5( 'fac_admin_nonce' ) ) !== false, 'the admin nonce must not reach a public page' );
    }

    private function state() {
        return array(
            'calculatorType'    => 'archival',
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

    /* ---------------------------------------------------------------
       Admin bar naming
    --------------------------------------------------------------- */

    public function test_product_name_comes_from_woocommerce() {
        $GLOBALS['fac_test_wc_products'][101] = new FAC_Test_WC_Product( true, true, true, 'Archival Fine Art Print' );

        $this->assertSame( 'Archival Fine Art Print', fac_quote_product_name( 'archival' ) );
    }

    public function test_product_name_falls_back_when_no_product_is_wired_up() {
        update_option( 'fac_woocommerce_product_id', 0 );

        // Never renders "Edit " with nothing after it.
        $this->assertSame( 'Archival Print', fac_quote_product_name( 'archival' ) );
        $this->assertSame( 'Inkjet Print', fac_quote_product_name( 'inkjet' ) );
    }

    /* ---------------------------------------------------------------
       Page detection
    --------------------------------------------------------------- */

    public function test_calculator_type_is_detected_from_the_shortcode_on_the_page() {
        $this->on_calculator_page( 'fine_art_calculator_embed' );
        $this->assertSame( 'archival', fac_quote_detect_calculator_type() );

        $this->on_calculator_page( 'inkjet_calculator_embed' );
        $this->assertSame( 'inkjet', fac_quote_detect_calculator_type() );
    }

    public function test_a_page_without_a_calculator_is_not_one() {
        $GLOBALS['fac_test_is_singular']  = true;
        $GLOBALS['fac_test_current_post'] = (object) array(
            'ID'           => 3,
            'post_title'   => 'About',
            'post_content' => 'We print things.',
        );

        $this->assertSame( '', fac_quote_detect_calculator_type() );
    }

    public function test_archive_pages_are_not_calculator_pages() {
        $GLOBALS['fac_test_is_singular'] = false;

        $this->assertSame( '', fac_quote_detect_calculator_type() );
    }

    /* ---------------------------------------------------------------
       The wp-admin door
    --------------------------------------------------------------- */

    public function test_submenu_entry_url_points_at_the_page_holding_the_shortcode() {
        $GLOBALS['fac_test_pages'] = array(
            (object) array( 'ID' => 3,  'post_title' => 'About',      'post_content' => 'nothing here' ),
            (object) array( 'ID' => 12, 'post_title' => 'Fine Art',   'post_content' => '[fine_art_calculator_embed]' ),
        );

        $url = fac_quote_admin_entry_url( 'archival' );

        $this->assertStringContainsString( 'fac_quote_admin=1', $url );
    }

    public function test_entry_url_is_empty_when_no_page_carries_the_shortcode() {
        $GLOBALS['fac_test_pages'] = array(
            (object) array( 'ID' => 3, 'post_title' => 'About', 'post_content' => 'nothing here' ),
        );

        // The submenu then renders its "add the shortcode first" screen instead
        // of redirecting into nowhere.
        $this->assertSame( '', fac_quote_admin_entry_url( 'archival' ) );
    }

    /* ---------------------------------------------------------------
       Page builders

       Oxygen, Elementor and friends keep their layout in their own meta and
       render through a template that isn't the post — so the shortcode never
       appears in post_content and a scan for it finds nothing. Detection has to
       come from the shortcode actually running, which is the one fact nothing
       else can fake.
    --------------------------------------------------------------- */

    /** Reproduces an Oxygen single-product template holding the shortcode. */
    private function on_builder_rendered_product( $type = 'archival' ) {
        $GLOBALS['fac_test_is_singular']  = true;
        $GLOBALS['fac_test_current_post'] = (object) array(
            'ID'           => 101,
            'post_title'   => 'Archival Fine Art Print',
            // Oxygen renders from ct_builder_shortcodes meta on a separate
            // template post; the product's own content is empty.
            'post_content' => '',
        );
    }

    public function test_a_builder_template_is_invisible_to_content_detection() {
        $this->on_builder_rendered_product();

        // The honest baseline: nothing in post_content, nothing to find.
        $this->assertSame( '', fac_quote_detect_calculator_type() );
    }

    public function test_a_rendered_calculator_identifies_itself_whatever_rendered_it() {
        $this->on_builder_rendered_product();

        fac_quote_note_render( 'archival' );

        $this->assertSame( 'archival', fac_quote_rendered_calculator_type() );
    }

    public function test_the_admin_bar_still_appears_on_a_builder_template() {
        $this->on_builder_rendered_product();
        $this->sign_in_as_admin();
        $GLOBALS['fac_test_admin_bar'] = true;
        $GLOBALS['fac_test_wc_products'][101] = new FAC_Test_WC_Product( true, true, true, 'Archival Fine Art Print' );

        $bar = new FAC_Test_Admin_Bar();
        $GLOBALS['wp_admin_bar'] = $bar;

        // Early pass sees nothing — post_content is empty.
        fac_quote_admin_bar_menu( $bar );
        $this->assertNull( $bar->get_node( 'fac-quote-edit' ) );

        // Then the shortcode runs, and the late pass picks it up.
        fac_quote_note_render( 'archival' );
        fac_quote_admin_bar_late();

        $node = $bar->get_node( 'fac-quote-edit' );
        $this->assertNotNull( $node, 'the admin bar link must survive a page builder' );
        $this->assertSame( 'Edit Archival Fine Art Print', $node['title'] );
    }

    public function test_the_late_pass_does_not_duplicate_the_early_one() {
        $this->on_calculator_page();
        $this->sign_in_as_admin();
        $GLOBALS['fac_test_admin_bar'] = true;

        $bar = new FAC_Test_Admin_Bar();
        $GLOBALS['wp_admin_bar'] = $bar;

        fac_quote_admin_bar_menu( $bar );
        fac_quote_note_render( 'archival' );
        fac_quote_admin_bar_late();

        $this->assertSame( 1, $bar->added, 'the node must be added once, not twice' );
    }

    public function test_the_admin_bar_stays_away_from_non_admins_on_a_builder_page() {
        $this->on_builder_rendered_product();
        $GLOBALS['fac_test_logged_in'] = true;
        $GLOBALS['fac_test_caps']      = array( 'read' => true );
        $GLOBALS['fac_test_admin_bar'] = true;

        $bar = new FAC_Test_Admin_Bar();
        $GLOBALS['wp_admin_bar'] = $bar;

        fac_quote_note_render( 'archival' );
        fac_quote_admin_bar_late();

        $this->assertNull( $bar->get_node( 'fac-quote-edit' ) );
    }

    public function test_the_button_appears_early_on_a_page_the_calculator_has_rendered_on() {
        $this->on_builder_rendered_product();
        $this->sign_in_as_admin();
        $GLOBALS['fac_test_admin_bar'] = true;
        $GLOBALS['fac_test_wc_products'][101] = new FAC_Test_WC_Product( true, true, true, 'Fine Art Printing' );

        // First view: the shortcode runs and reports where it is.
        fac_quote_note_render( 'archival' );
        unset( $GLOBALS['fac_rendered_calculator_type'] ); // new request

        // Second view: admin_bar_menu fires long before any content renders,
        // and must still recognise the page — this is what makes the button
        // work under Oxygen without depending on wp_footer at all.
        $bar = new FAC_Test_Admin_Bar();
        $GLOBALS['wp_admin_bar'] = $bar;
        fac_quote_admin_bar_menu( $bar );

        $node = $bar->get_node( 'fac-quote-edit' );
        $this->assertNotNull( $node, 'the button must appear from the early pass on a known calculator page' );
        $this->assertSame( 'Edit Fine Art Printing', $node['title'] );
    }

    public function test_the_button_appends_the_parameter_to_the_current_url() {
        $this->on_builder_rendered_product();
        $this->sign_in_as_admin();
        $_SERVER['REQUEST_URI'] = '/product/fine-art-printing/';

        $bar = new FAC_Test_Admin_Bar();
        fac_quote_note_render( 'archival' );
        fac_quote_add_admin_bar_node( $bar, 'archival' );

        $this->assertSame(
            'https://source.test/product/fine-art-printing/?fac_quote_admin=1',
            $bar->get_node( 'fac-quote-edit' )['href']
        );
    }

    public function test_the_button_preserves_other_query_args() {
        $this->on_builder_rendered_product();
        $this->sign_in_as_admin();
        $_SERVER['REQUEST_URI'] = '/product/fine-art-printing/?utm_source=email';

        $bar = new FAC_Test_Admin_Bar();
        fac_quote_add_admin_bar_node( $bar, 'archival' );

        $this->assertStringContainsString( 'utm_source=email', $bar->get_node( 'fac-quote-edit' )['href'] );
        $this->assertStringContainsString( 'fac_quote_admin=1', $bar->get_node( 'fac-quote-edit' )['href'] );
    }

    public function test_the_button_becomes_an_exit_once_editing() {
        $this->on_builder_rendered_product();
        $this->sign_in_as_admin();
        $this->request_authoring_mode();
        $_SERVER['REQUEST_URI'] = '/product/fine-art-printing/?fac_quote_admin=1';

        $bar = new FAC_Test_Admin_Bar();
        fac_quote_add_admin_bar_node( $bar, 'archival' );

        $node = $bar->get_node( 'fac-quote-edit' );
        $this->assertSame( 'Done editing', $node['title'] );
        $this->assertFalse( strpos( $node['href'], 'fac_quote_admin' ) !== false, 'leaving must strip the parameter' );
    }

    public function test_the_two_branches_do_not_claim_each_others_pages() {
        $GLOBALS['fac_test_is_singular'] = true;

        $GLOBALS['fac_test_current_post'] = (object) array( 'ID' => 101, 'post_title' => 'Fine Art', 'post_content' => '' );
        fac_quote_note_render( 'archival' );
        unset( $GLOBALS['fac_rendered_calculator_type'] );

        $GLOBALS['fac_test_current_post'] = (object) array( 'ID' => 202, 'post_title' => 'Inkjet', 'post_content' => '' );
        fac_quote_note_render( 'inkjet' );
        unset( $GLOBALS['fac_rendered_calculator_type'] );

        $this->assertSame( 'inkjet', fac_quote_detect_calculator_type() );

        $GLOBALS['fac_test_current_post'] = (object) array( 'ID' => 101, 'post_title' => 'Fine Art', 'post_content' => '' );
        $this->assertSame( 'archival', fac_quote_detect_calculator_type() );

        $GLOBALS['fac_test_current_post'] = (object) array( 'ID' => 999, 'post_title' => 'Other', 'post_content' => '' );
        $this->assertSame( '', fac_quote_detect_calculator_type() );
    }

    public function test_the_submenu_finds_a_calculator_it_has_seen_render() {
        // Nothing on any page — a scan would come up empty.
        $GLOBALS['fac_test_pages'] = array(
            (object) array( 'ID' => 3, 'post_title' => 'About', 'post_content' => 'nothing here' ),
        );
        $this->assertSame( '', fac_quote_admin_entry_url( 'archival' ) );

        // One front-end view of the Oxygen-rendered product teaches it.
        $this->on_builder_rendered_product();
        fac_quote_note_render( 'archival' );

        $url = fac_quote_admin_entry_url( 'archival' );
        $this->assertStringContainsString( 'fac_quote_admin=1', $url );
        $this->assertSame( 101, (int) get_option( 'fac_calculator_location_archival', 0 ) );
    }

    public function test_each_branch_remembers_its_own_location() {
        $GLOBALS['fac_test_is_singular']  = true;
        $GLOBALS['fac_test_current_post'] = (object) array( 'ID' => 101, 'post_title' => 'A', 'post_content' => '' );
        fac_quote_note_render( 'archival' );

        $GLOBALS['fac_test_current_post'] = (object) array( 'ID' => 202, 'post_title' => 'B', 'post_content' => '' );
        fac_quote_note_render( 'inkjet' );

        $this->assertSame( 101, (int) get_option( 'fac_calculator_location_archival', 0 ) );
        $this->assertSame( 202, (int) get_option( 'fac_calculator_location_inkjet', 0 ) );
    }

    public function test_the_remembered_location_only_writes_when_it_changes() {
        $GLOBALS['fac_test_is_singular']  = true;
        $GLOBALS['fac_test_current_post'] = (object) array( 'ID' => 101, 'post_title' => 'A', 'post_content' => '' );

        fac_quote_note_render( 'archival' );
        $writes = $GLOBALS['fac_test_option_writes'] ?? 0;

        // Ten more views of the same page must not mean ten more writes.
        for ( $i = 0; $i < 10; $i++ ) {
            fac_quote_note_render( 'archival' );
        }

        $this->assertSame( $writes, $GLOBALS['fac_test_option_writes'] ?? 0 );
    }

    /* ---------------------------------------------------------------
       Per-calculator lists
    --------------------------------------------------------------- */

    public function test_each_calculator_lists_only_its_own_links() {
        update_option( 'fac_inkjet_paper_data', fac_get_default_inkjet_paper_data() );

        fac_quote_save(
            fac_quote_sanitize_input(
                array(
                    'label' => 'Archival job', 'calculatorType' => 'archival', 'state' => $this->state(),
                    'hasCustomPrice' => false, 'customPrice' => 0, 'editable' => true,
                    'reusable' => true, 'expires' => '', 'status' => 'active', 'pageId' => 12,
                )
            )
        );

        $inkjet_state = array(
            'calculatorType'    => 'inkjet',
            'rollKey'           => '44',
            'inkjetCategory'    => 'papers',
            'selectedPaperSlug' => fac_get_default_inkjet_paper_data()[0]['slug'],
            'units'             => 'inches',
            'width'             => '20',
            'height'            => '30',
            'quantity'          => 1,
            'mounting'          => 'no_mounting',
            'turnaround'        => 'standard',
        );
        $inkjet_input = fac_quote_sanitize_input(
            array(
                'label' => 'Inkjet job', 'calculatorType' => 'inkjet', 'state' => $inkjet_state,
                'hasCustomPrice' => false, 'customPrice' => 0, 'editable' => true,
                'reusable' => true, 'expires' => '', 'status' => 'active', 'pageId' => 13,
            )
        );
        $this->assertFalse( is_wp_error( $inkjet_input ), 'inkjet fixture should validate' );
        fac_quote_save( $inkjet_input );

        $this->assertCount( 2, fac_quote_list() );
        $this->assertCount( 1, fac_quote_list( 'archival' ) );
        $this->assertCount( 1, fac_quote_list( 'inkjet' ) );
        $this->assertSame( 'Archival job', fac_quote_list( 'archival' )[0]['label'] );
        $this->assertSame( 'Inkjet job', fac_quote_list( 'inkjet' )[0]['label'] );
    }

    public function test_inkjet_category_survives_a_save() {
        update_option( 'fac_inkjet_paper_data', fac_get_default_inkjet_paper_data() );

        $state = array(
            'calculatorType'    => 'inkjet',
            'rollKey'           => '44',
            'inkjetCategory'    => 'papers',
            'selectedPaperSlug' => fac_get_default_inkjet_paper_data()[0]['slug'],
            'units'             => 'inches',
            'width'             => '20',
            'height'            => '30',
            'quantity'          => 1,
            'mounting'          => 'no_mounting',
            'turnaround'        => 'standard',
        );

        $input = fac_quote_sanitize_input(
            array(
                'label' => 'Inkjet job', 'calculatorType' => 'inkjet', 'state' => $state,
                'hasCustomPrice' => false, 'customPrice' => 0, 'editable' => false,
                'reusable' => true, 'expires' => '', 'status' => 'active', 'pageId' => 13,
            )
        );

        $this->assertFalse( is_wp_error( $input ) );
        // A locked inkjet link renders with its category selected only if this
        // round-trips — the front end no longer auto-corrects locked state.
        $this->assertSame( 'papers', $input['items'][0]['state']['inkjetCategory'] );
    }
}
