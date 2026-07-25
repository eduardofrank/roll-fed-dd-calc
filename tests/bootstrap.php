<?php
/**
 * Minimal WordPress stubs for unit testing plugin logic without a full WP install.
 */

define( 'ABSPATH', __DIR__ . '/../' );
define( 'FAC_VERSION', '2.7.3' );

$GLOBALS['fac_test_options'] = array();
$GLOBALS['fac_test_transients'] = array();
$GLOBALS['fac_test_nonce_valid'] = true;
$GLOBALS['fac_test_wc_products'] = array();
$GLOBALS['fac_test_wc_cart_key'] = 'test-cart-key';
$GLOBALS['fac_test_shipping_class_terms'] = array(); // slug => term_id
$GLOBALS['fac_test_posts'] = array();          // id => post record (quote links)
$GLOBALS['fac_test_next_post_id'] = 1;

function get_option( $key, $default = false ) {
    return $GLOBALS['fac_test_options'][ $key ] ?? $default;
}

function update_option( $key, $value ) {
    $GLOBALS['fac_test_options'][ $key ] = $value;
    // Counted so tests can prove the calculator-location memo isn't a write on
    // every front-end request.
    $GLOBALS['fac_test_option_writes'] = ( $GLOBALS['fac_test_option_writes'] ?? 0 ) + 1;
    return true;
}

function delete_option( $key ) {
    unset( $GLOBALS['fac_test_options'][ $key ] );
    return true;
}

function get_site_url() {
    return 'https://source.test';
}

function current_time( $type ) {
    return '2026-06-20T12:00:00+00:00';
}

function get_bloginfo( $show ) {
    return '6.0';
}

function __( $text, $domain = 'default' ) {
    return $text;
}

function esc_html( $text ) {
    return htmlspecialchars( (string) $text, ENT_QUOTES, 'UTF-8' );
}

function absint( $value ) {
    return abs( (int) $value );
}

function sanitize_key( $key ) {
    return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( (string) $key ) );
}

function sanitize_text_field( $text ) {
    $text = (string) $text;
    $text = strip_tags( $text );
    $text = preg_replace( '/[\r\n\t]+/', ' ', $text );
    return trim( $text );
}

function wp_unslash( $value ) {
    if ( is_array( $value ) ) {
        return array_map( 'wp_unslash', $value );
    }
    return stripslashes( (string) $value );
}

function esc_url_raw( $url ) {
    return filter_var( $url, FILTER_SANITIZE_URL ) ?: '';
}

function wp_json_encode( $data ) {
    return json_encode( $data );
}

function add_action( $hook, $callback, $priority = 10, $accepted_args = 1 ) {}

function add_filter( $hook, $callback, $priority = 10, $accepted_args = 1 ) {}

/** No filters registered under test — return the value untouched. */
function apply_filters( $hook, $value ) {
    return $value;
}

function did_action( $hook ) {
    return 0;
}

function is_admin() {
    return false;
}

function wp_verify_nonce( $nonce, $action ) {
    return (bool) $GLOBALS['fac_test_nonce_valid'];
}

function check_ajax_referer( $action, $query_arg = false ) {
    return true;
}

function get_transient( $key ) {
    return $GLOBALS['fac_test_transients'][ $key ] ?? false;
}

function set_transient( $key, $value, $expiration ) {
    $GLOBALS['fac_test_transients'][ $key ] = $value;
    return true;
}

/**
 * Minimal in-memory post store, backing the quote-link custom post type.
 * Only the handful of post/meta functions includes/quotes.php actually calls
 * are implemented. Anything not registered here still reports 'product', which
 * is what the pre-existing WooCommerce product-ID tests expect.
 */
function get_post_type( $post_id = null ) {
    $posts = $GLOBALS['fac_test_posts'] ?? array();

    if ( isset( $posts[ (int) $post_id ] ) ) {
        return $posts[ (int) $post_id ]['post_type'];
    }

    return 'product';
}

function wp_insert_post( $postarr, $wp_error = false ) {
    $id = ( $GLOBALS['fac_test_next_post_id'] ?? 1 );
    $GLOBALS['fac_test_next_post_id'] = $id + 1;

    $GLOBALS['fac_test_posts'][ $id ] = array(
        'ID'         => $id,
        'post_type'  => $postarr['post_type'] ?? 'post',
        'post_title' => $postarr['post_title'] ?? '',
        'meta'       => array(),
    );

    return $id;
}

function wp_update_post( $postarr, $wp_error = false ) {
    $id = (int) ( $postarr['ID'] ?? 0 );

    if ( ! isset( $GLOBALS['fac_test_posts'][ $id ] ) ) {
        return new WP_Error( 'invalid_post', 'No post to update.' );
    }

    if ( isset( $postarr['post_title'] ) ) {
        $GLOBALS['fac_test_posts'][ $id ]['post_title'] = $postarr['post_title'];
    }

    return $id;
}

function wp_delete_post( $post_id, $force_delete = false ) {
    $post_id = (int) $post_id;

    if ( ! isset( $GLOBALS['fac_test_posts'][ $post_id ] ) ) {
        return false;
    }

    unset( $GLOBALS['fac_test_posts'][ $post_id ] );
    return true;
}

function get_post_field( $field, $post_id ) {
    return $GLOBALS['fac_test_posts'][ (int) $post_id ][ $field ] ?? '';
}

function delete_post_meta( $post_id, $key, $value = '' ) {
    unset( $GLOBALS['fac_test_posts'][ (int) $post_id ]['meta'][ $key ] );
    return true;
}

function get_post_meta( $post_id, $key = '', $single = false ) {
    $value = $GLOBALS['fac_test_posts'][ (int) $post_id ]['meta'][ $key ] ?? '';
    return $single ? $value : ( $value === '' ? array() : array( $value ) );
}

function update_post_meta( $post_id, $key, $value ) {
    if ( ! isset( $GLOBALS['fac_test_posts'][ (int) $post_id ] ) ) {
        return false;
    }

    $GLOBALS['fac_test_posts'][ (int) $post_id ]['meta'][ $key ] = $value;
    return true;
}

/**
 * Supports only the two shapes includes/quotes.php queries with: a meta_key /
 * meta_value token lookup, and an unfiltered list of a post type.
 */
function get_posts( $args = array() ) {
    $out = array();

    foreach ( $GLOBALS['fac_test_posts'] ?? array() as $id => $post ) {
        if ( isset( $args['post_type'] ) && $post['post_type'] !== $args['post_type'] ) {
            continue;
        }
        if ( isset( $args['meta_key'] ) && ( $post['meta'][ $args['meta_key'] ] ?? null ) !== $args['meta_value'] ) {
            continue;
        }
        $out[] = $id;
    }

    if ( isset( $args['order'] ) && $args['order'] === 'DESC' ) {
        $out = array_reverse( $out );
    }

    if ( isset( $args['posts_per_page'] ) && $args['posts_per_page'] > 0 ) {
        $out = array_slice( $out, 0, (int) $args['posts_per_page'] );
    }

    return $out;
}

function get_permalink( $post_id ) {
    return $post_id ? 'https://source.test/calculator/' : false;
}

function add_query_arg( $key, $value, $url = false ) {
    if ( false === $url ) {
        $url = $GLOBALS['fac_test_current_url'] ?? 'https://source.test/calculator/';
    }
    return $url . ( strpos( $url, '?' ) === false ? '?' : '&' ) . $key . '=' . rawurlencode( $value );
}

function register_post_type( $type, $args = array() ) {}

/* ---------------------------------------------------------------
   Front-end / capability stubs, for the quote authoring surface
--------------------------------------------------------------- */

function admin_url( $path = '' ) {
    return 'https://source.test/wp-admin/' . ltrim( (string) $path, '/' );
}

function wp_create_nonce( $action = -1 ) {
    return 'test-nonce-' . md5( (string) $action );
}

function is_user_logged_in() {
    return ! empty( $GLOBALS['fac_test_logged_in'] );
}

function current_user_can( $capability ) {
    return ! empty( $GLOBALS['fac_test_caps'][ $capability ] );
}

function is_singular( $types = '' ) {
    return ! empty( $GLOBALS['fac_test_is_singular'] );
}

function get_post( $post = null ) {
    return $GLOBALS['fac_test_current_post'] ?? null;
}

function get_the_ID() {
    $post = $GLOBALS['fac_test_current_post'] ?? null;
    return $post ? (int) $post->ID : 0;
}

function has_shortcode( $content, $tag ) {
    return strpos( (string) $content, '[' . $tag ) !== false;
}

function get_pages( $args = array() ) {
    return $GLOBALS['fac_test_pages'] ?? array();
}

function nocache_headers() {}

function get_post_status( $post_id ) {
    return isset( $GLOBALS['fac_test_posts'][ (int) $post_id ] ) ? 'publish' : ( $GLOBALS['fac_test_post_status'] ?? 'publish' );
}

function is_admin_bar_showing() {
    return ! empty( $GLOBALS['fac_test_admin_bar'] );
}

function get_queried_object_id() {
    $post = $GLOBALS['fac_test_current_post'] ?? null;
    return $post ? (int) $post->ID : 0;
}

function home_url( $path = '' ) {
    return 'https://source.test' . $path;
}

function esc_url( $url ) {
    return $url;
}

function remove_query_arg( $key, $url = false ) {
    if ( false === $url ) {
        $url = $GLOBALS['fac_test_current_url'] ?? 'https://source.test/calculator/';
    }
    return preg_replace( '/[?&]' . preg_quote( $key, '/' ) . '=[^&]*/', '', $url );
}

function wp_safe_redirect( $location, $status = 302 ) {
    $GLOBALS['fac_test_redirect'] = $location;
    return true;
}

function fac_reset_test_frontend_state() {
    $GLOBALS['fac_test_logged_in']     = false;
    $GLOBALS['fac_test_caps']          = array();
    $GLOBALS['fac_test_is_singular']   = false;
    $GLOBALS['fac_test_current_post']  = null;
    $GLOBALS['fac_test_pages']         = array();
    $GLOBALS['fac_test_redirect']      = '';
    $GLOBALS['fac_test_admin_bar']     = false;
    $GLOBALS['fac_test_post_status']   = 'publish';
    unset( $GLOBALS['fac_rendered_calculator_type'] );
    unset( $_GET[ 'fac_quote_admin' ] );
}

function fac_reset_test_post_state() {
    $GLOBALS['fac_test_posts'] = array();
    $GLOBALS['fac_test_next_post_id'] = 1;
}

/**
 * Minimal stand-in for WordPress' get_term_by(), backed by the
 * fac_test_shipping_class_terms fixture (slug => term_id). Only the
 * 'slug' and 'id' lookup fields are implemented — the only ones this
 * plugin uses.
 */
function get_term_by( $field, $value, $taxonomy = '' ) {
    $terms = $GLOBALS['fac_test_shipping_class_terms'] ?? array();

    if ( $field === 'slug' ) {
        if ( ! isset( $terms[ $value ] ) ) {
            return false;
        }
        return (object) array(
            'term_id'  => (int) $terms[ $value ],
            'slug'     => (string) $value,
            'taxonomy' => $taxonomy,
        );
    }

    if ( $field === 'id' ) {
        foreach ( $terms as $slug => $term_id ) {
            if ( (int) $term_id === (int) $value ) {
                return (object) array(
                    'term_id'  => (int) $term_id,
                    'slug'     => $slug,
                    'taxonomy' => $taxonomy,
                );
            }
        }
        return false;
    }

    return false;
}

/**
 * Minimal stand-in for WP_Admin_Bar — just the surface the quote node touches.
 */
class FAC_Test_Admin_Bar {
    public $nodes = array();
    public $added = 0;

    public function add_node( $args ) {
        $this->nodes[ $args['id'] ] = $args;
        $this->added++;
    }

    public function get_node( $id ) {
        return $this->nodes[ $id ] ?? null;
    }
}

class FAC_Test_WC_Product {
    private $exists;
    private $purchasable;
    private $in_stock;
    private $name;

    public function __construct( $exists = true, $purchasable = true, $in_stock = true, $name = 'Test Product' ) {
        $this->exists      = $exists;
        $this->purchasable = $purchasable;
        $this->in_stock    = $in_stock;
        $this->name        = $name;
    }

    /** Used by fac_quote_product_name() for the admin bar label. */
    public function get_name() {
        return $this->name;
    }

    public function exists() {
        return $this->exists;
    }

    public function is_purchasable() {
        return $this->purchasable;
    }

    public function is_in_stock() {
        return $this->in_stock;
    }
}

class FAC_Test_WC_Cart {
    public $items = array();

    /**
     * Keyed like real WC_Cart::cart_contents — each entry may hold
     * 'calculator_data' and a 'data' => FAC_Test_WC_Cart_Item_Product.
     * Tests populate this directly to exercise woocommerce_before_calculate_totals
     * callbacks (fac_set_custom_cart_item_price_and_weight,
     * fac_set_custom_cart_item_shipping_class) without a full WC stack.
     */
    public $cart_contents = array();

    public function add_to_cart( $product_id, $quantity, $variation_id = 0, $variation = array(), $cart_item_data = array() ) {
        $this->items[] = array(
            'product_id'     => $product_id,
            'quantity'       => $quantity,
            'variation_id'   => $variation_id,
            'variation'      => $variation,
            'cart_item_data' => $cart_item_data,
        );

        return $GLOBALS['fac_test_wc_cart_key'];
    }

    public function get_cart() {
        return $this->cart_contents;
    }

    public function remove_cart_item( $key ) {
        unset( $this->cart_contents[ $key ] );
        return true;
    }
}

/**
 * Minimal stand-in for the WC_Product clone WooCommerce stores at
 * $cart_item['data']. Supports the setters/getters this plugin calls
 * when dynamically overriding price, weight, and shipping class per
 * cart line (see includes/ajax.php).
 */
class FAC_Test_WC_Cart_Item_Product {
    private $price;
    private $weight;
    private $shipping_class_id = 0;

    public function set_price( $price ) {
        $this->price = $price;
    }

    public function get_price() {
        return $this->price;
    }

    public function set_weight( $weight ) {
        $this->weight = $weight;
    }

    public function get_weight() {
        return $this->weight;
    }

    public function set_shipping_class_id( $id ) {
        $this->shipping_class_id = (int) $id;
    }

    public function get_shipping_class_id() {
        return $this->shipping_class_id;
    }
}

/**
 * Minimal stand-in for WooCommerce's own WC_Shipping_Method / WC_Settings_API
 * base class — just enough surface area for FAC_Shipping_Quote
 * (includes/shipping-method.php) to be defined and exercised here without
 * loading real WooCommerce. Real WooCommerce always defines the genuine
 * class before this plugin's shipping method is instantiated (see
 * woocommerce_shipping_init in includes/shipping-method.php); this stub
 * exists purely so the same production class can run under test.
 */
class WC_Shipping_Method {
    public $id;
    public $instance_id = 0;
    public $method_title;
    public $method_description;
    public $supports = array();
    public $enabled = 'yes';
    public $title = '';
    public $cost = '0';
    public $instance_form_fields = array();
    public $rates_added = array();

    public function init_settings() {
        // No-op — tests read settings straight from instance_form_fields defaults via get_option().
    }

    public function get_option( $key, $default = '' ) {
        if ( isset( $this->instance_form_fields[ $key ]['default'] ) ) {
            return $this->instance_form_fields[ $key ]['default'];
        }
        return $default;
    }

    public function is_available( $package ) {
        return $this->enabled === 'yes';
    }

    public function get_rate_id() {
        return $this->id;
    }

    public function add_rate( $args = array() ) {
        $this->rates_added[] = $args;
    }

    public function process_admin_options() {
        // No-op in tests.
    }
}

class FAC_Test_JSON_Response_Exception extends RuntimeException {
    public $success;
    public $data;
    public $status_code;

    public function __construct( $success, $data, $status_code ) {
        parent::__construct( 'JSON response emitted in test bootstrap.' );
        $this->success     = (bool) $success;
        $this->data        = $data;
        $this->status_code = (int) $status_code;
    }
}

function wc_get_product( $product_id ) {
    return $GLOBALS['fac_test_wc_products'][ (int) $product_id ] ?? null;
}

function wc_get_cart_url() {
    return '/cart';
}

if ( ! defined( 'HOUR_IN_SECONDS' ) ) { define( 'HOUR_IN_SECONDS', 3600 ); }
if ( ! defined( 'DAY_IN_SECONDS' ) ) { define( 'DAY_IN_SECONDS', 86400 ); }
if ( ! defined( 'MINUTE_IN_SECONDS' ) ) { define( 'MINUTE_IN_SECONDS', 60 ); }

function trailingslashit( $string ) {
    return rtrim( (string) $string, "/\\" ) . '/';
}

function is_email( $email ) {
    return false !== filter_var( $email, FILTER_VALIDATE_EMAIL ) ? $email : false;
}

function wc_get_orders( $args = array() ) {
    return $GLOBALS['fac_test_order_query_results'] ?? array();
}

function wp_mail( $to, $subject, $message, $headers = '', $attachments = array() ) {
    $GLOBALS['fac_test_mail'][] = array( 'to' => $to, 'subject' => $subject, 'message' => $message );
    return $GLOBALS['fac_test_mail_result'] ?? true;
}

class FAC_Test_WC_Order_Stub {
    private $id;
    public function __construct( $id ) { $this->id = (int) $id; }
    public function get_order_number() { return (string) $this->id; }
}

function get_locale() {
    return $GLOBALS['fac_test_locale'] ?? 'en_US';
}

class FAC_Test_WC_Logger {
    public function log( $level, $message, $context = array() ) {
        $GLOBALS['fac_test_logs'][] = array(
            'level'   => $level,
            'message' => $message,
            'source'  => $context['source'] ?? '',
        );
    }
}

function wc_get_logger() {
    return new FAC_Test_WC_Logger();
}

function wp_get_current_user() {
    return (object) array( 'ID' => (int) ( $GLOBALS['fac_test_current_user_id'] ?? 0 ) );
}

function wc_add_notice( $message, $type = 'notice' ) {
    $GLOBALS['fac_test_wc_notices'][] = array( 'message' => (string) $message, 'type' => (string) $type );
}

/**
 * Minimal WooCommerce session for layout-images.php (planner manifest).
 */
class FAC_Test_WC_Session {
    private $data = array();

    public function get( $key, $default = null ) {
        return array_key_exists( $key, $this->data ) ? $this->data[ $key ] : $default;
    }

    public function set( $key, $value ) {
        $this->data[ $key ] = $value;
    }

    public function reset() {
        $this->data = array();
    }
}

function WC() {
    static $instance = null;

    if ( ! $instance ) {
        $instance = (object) array(
            'cart'    => new FAC_Test_WC_Cart(),
            'session' => new FAC_Test_WC_Session(),
        );
    }

    return $instance;
}

function fac_reset_test_wc_state() {
    $GLOBALS['fac_test_wc_products'] = array();
    $GLOBALS['fac_test_wc_cart_key'] = 'test-cart-key';
    $GLOBALS['fac_test_shipping_class_terms'] = array();
    // Hardening stubs (logging, ops digest, checkout re-validation).
    $GLOBALS['fac_test_logs'] = array();
    $GLOBALS['fac_test_mail'] = array();
    $GLOBALS['fac_test_mail_result'] = true;
    $GLOBALS['fac_test_order_query_results'] = array();
    $GLOBALS['fac_test_current_user_id'] = 0;
    $GLOBALS['fac_test_wc_notices'] = array();

    // WC() memoises its cart in a static, so lines added by one test would
    // otherwise still be there in the next one. Anything asserting on cart
    // contents needs this cleared, not just the fixtures above.
    WC()->cart->items = array();
    WC()->cart->cart_contents = array();
    if ( isset( WC()->session ) && method_exists( WC()->session, 'reset' ) ) {
        WC()->session->reset();
    }
}

function wp_send_json_error( $data = null, $status_code = null, $options = 0 ) {
    throw new FAC_Test_JSON_Response_Exception( false, $data, $status_code ?? 200 );
}

function wp_send_json_success( $data = null, $status_code = null, $options = 0 ) {
    throw new FAC_Test_JSON_Response_Exception( true, $data, $status_code ?? 200 );
}

function sanitize_file_name( $filename ) {
    return preg_replace( '/[^A-Za-z0-9._-]/', '', (string) $filename );
}

function wp_check_filetype_and_ext( $file, $filename, $mimes = null ) {
    $ext = strtolower( pathinfo( (string) $filename, PATHINFO_EXTENSION ) );
    return array(
        'ext' => $ext,
        'type' => $ext === 'json' ? 'application/json' : '',
        'proper_filename' => $filename,
    );
}

class WP_Error {
    private $code;
    private $message;

    public function __construct( $code, $message ) {
        $this->code    = $code;
        $this->message = $message;
    }

    public function get_error_message() {
        return $this->message;
    }

    public function get_error_code() {
        return $this->code;
    }
}

function is_wp_error( $thing ) {
    return $thing instanceof WP_Error;
}

require_once __DIR__ . '/../includes/logging.php';
require_once __DIR__ . '/../includes/default-data.php';
require_once __DIR__ . '/../includes/settings.php';
require_once __DIR__ . '/../includes/export-import.php';
require_once __DIR__ . '/../includes/pricing.php';
// Fork-specific: layout feed stamping used by ajax/cart-meta (not in upstream).
require_once __DIR__ . '/../includes/layout-images.php';
require_once __DIR__ . '/../includes/cart-meta.php';
require_once __DIR__ . '/../includes/shipping-method.php';
require_once __DIR__ . '/../includes/quotes.php';
require_once __DIR__ . '/../includes/ajax.php';
require_once __DIR__ . '/../includes/ops-digest.php';

// Seed default options for tests.
update_option( 'fac_paper_data', fac_get_default_paper_data() );
update_option( 'fac_roll_widths', fac_get_default_roll_widths() );
update_option( 'fac_mounting_rates', fac_get_default_mounting_rates() );
update_option( 'fac_turnaround_rates', fac_get_default_turnaround_rates() );
