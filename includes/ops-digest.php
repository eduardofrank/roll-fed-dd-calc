<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Daily operations digest.
 *
 * Opt-in morning email (07:00 site time via WP-Cron) summarizing what needs
 * attention: orders in production (processing) and yesterday's logged error
 * count. Ported from sheet-fed-calc; roll-fed has no artwork/preflight
 * concept yet, so the digest starts with orders + errors.
 */

add_action( 'init', 'fac_schedule_ops_digest' );
add_action( 'fac_daily_ops_digest', 'fac_send_ops_digest' );

/**
 * Digest settings with defaults applied.
 *
 * An empty stored recipient falls back to the admin email so an enabled
 * digest can never silently stop sending.
 *
 * @return array{enabled:int,recipient:string}
 */
function fac_get_ops_digest_settings() {
    $stored = get_option( 'fac_ops_digest', array() );
    $stored = is_array( $stored ) ? $stored : array();

    $recipient = trim( (string) ( $stored['recipient'] ?? '' ) );
    if ( '' === $recipient ) {
        $recipient = (string) get_option( 'admin_email', '' );
    }

    return array(
        'enabled'   => empty( $stored['enabled'] ) ? 0 : 1,
        'recipient' => $recipient,
    );
}

/**
 * Sanitize digest settings.
 *
 * @param mixed $value Raw settings array.
 * @return array{enabled:int,recipient:string}|WP_Error
 */
function fac_sanitize_ops_digest_settings( $value ) {
    if ( ! is_array( $value ) ) {
        return new WP_Error( 'invalid_ops_digest', 'Ops digest settings must be an array.' );
    }

    $recipient = trim( (string) ( $value['recipient'] ?? '' ) );
    if ( '' !== $recipient && ! is_email( $recipient ) ) {
        return new WP_Error( 'invalid_ops_digest', 'Invalid digest recipient email.' );
    }

    return array(
        'enabled'   => empty( $value['enabled'] ) ? 0 : 1,
        'recipient' => $recipient,
    );
}

/**
 * Schedule the daily digest at the next 07:00 site time.
 *
 * @return void
 */
function fac_schedule_ops_digest() {
    if ( ! function_exists( 'wp_next_scheduled' ) || ! function_exists( 'wp_schedule_event' ) ) {
        return;
    }

    if ( wp_next_scheduled( 'fac_daily_ops_digest' ) ) {
        return;
    }

    $offset = (int) round( (float) get_option( 'gmt_offset', 0 ) * HOUR_IN_SECONDS );
    $next   = strtotime( gmdate( 'Y-m-d' ) . ' 07:00:00 UTC' ) - $offset;
    while ( $next <= time() ) {
        $next += DAY_IN_SECONDS;
    }

    wp_schedule_event( $next, 'daily', 'fac_daily_ops_digest' );
}

/**
 * Collect digest data.
 *
 * @return array{processingOrders:array,errorsYesterday:int}
 */
function fac_build_ops_digest_data() {
    $orders = array();

    $found = function_exists( 'wc_get_orders' )
        ? wc_get_orders( array( 'status' => array( 'processing' ), 'limit' => 100 ) )
        : array();

    foreach ( (array) $found as $order ) {
        if ( is_object( $order ) && method_exists( $order, 'get_order_number' ) ) {
            $orders[] = (string) $order->get_order_number();
        }
    }

    $counts    = get_option( 'fac_error_counts', array() );
    $yesterday = gmdate( 'Y-m-d', time() - DAY_IN_SECONDS );

    return array(
        'processingOrders' => $orders,
        'errorsYesterday'  => is_array( $counts ) ? (int) ( $counts[ $yesterday ] ?? 0 ) : 0,
    );
}

/**
 * Render the digest email body as plain text.
 *
 * @param array $data Digest data.
 * @return string
 */
function fac_render_ops_digest_body( $data ) {
    $lines   = array();
    $lines[] = __( 'Operations digest', 'fine-art-calculator' ) . ' — ' . gmdate( 'Y-m-d' );
    $lines[] = '';

    $orders  = (array) $data['processingOrders'];
    $lines[] = __( 'Orders in production (processing):', 'fine-art-calculator' ) . ' ' . count( $orders );
    foreach ( $orders as $number ) {
        $lines[] = '  - #' . $number;
    }
    $lines[] = '';
    $lines[] = __( 'Errors logged yesterday:', 'fine-art-calculator' ) . ' ' . (int) $data['errorsYesterday'];

    if ( empty( $orders ) && empty( $data['errorsYesterday'] ) ) {
        $lines[] = '';
        $lines[] = __( 'All clear.', 'fine-art-calculator' );
    }

    return implode( "\n", $lines ) . "\n";
}

/**
 * Build and send the digest email.
 *
 * @return bool Whether an email was sent.
 */
function fac_send_ops_digest() {
    $settings = fac_get_ops_digest_settings();
    if ( empty( $settings['enabled'] ) || '' === $settings['recipient'] || ! function_exists( 'wp_mail' ) ) {
        return false;
    }

    $data    = fac_build_ops_digest_data();
    $shop    = function_exists( 'get_bloginfo' ) ? (string) get_bloginfo( 'name' ) : '';
    $subject = ( '' !== $shop ? '[' . $shop . '] ' : '' )
        . __( 'Operations digest', 'fine-art-calculator' ) . ' — ' . gmdate( 'Y-m-d' );

    $sent = wp_mail( $settings['recipient'], $subject, fac_render_ops_digest_body( $data ) );

    $context = array(
        'recipient'        => $settings['recipient'],
        'processingOrders' => count( $data['processingOrders'] ),
        'errorsYesterday'  => $data['errorsYesterday'],
    );

    if ( $sent ) {
        fac_log_info( 'Ops digest sent', $context );
    } else {
        fac_log_error( 'Ops digest email failed to send', $context );
    }

    return (bool) $sent;
}
