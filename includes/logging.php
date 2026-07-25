<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Operational logging.
 *
 * Failures worth acting on — storage errors, blocked checkouts, price
 * mismatches — are written to the WooCommerce logger under the
 * "roll-fed-calc" source (WooCommerce → Status → Logs). Customer
 * mid-configuration validation errors are deliberately not logged; they are
 * normal traffic, not incidents. Ported from sheet-fed-calc.
 */

/**
 * Write a log entry.
 *
 * @param string $level   error | warning | info.
 * @param string $message Human-readable message.
 * @param array  $context Structured context, JSON-appended.
 * @return void
 */
function fac_log( $level, $message, $context = array() ) {
    $level = in_array( $level, array( 'error', 'warning', 'info' ), true ) ? $level : 'info';
    $line  = (string) $message;

    if ( ! empty( $context ) ) {
        $line .= ' ' . wp_json_encode( $context );
    }

    if ( 'error' === $level && function_exists( 'update_option' ) ) {
        // Rolling per-day error counts feed the (future) daily ops digest.
        $counts = get_option( 'fac_error_counts', array() );
        $counts = is_array( $counts ) ? $counts : array();
        $day    = gmdate( 'Y-m-d' );

        $counts[ $day ] = (int) ( $counts[ $day ] ?? 0 ) + 1;
        ksort( $counts );
        $counts = array_slice( $counts, -7, null, true );
        update_option( 'fac_error_counts', $counts, false );
    }

    if ( function_exists( 'wc_get_logger' ) ) {
        wc_get_logger()->log( $level, $line, array( 'source' => 'roll-fed-calc' ) );
        return;
    }

    if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
        error_log( '[roll-fed-calc] ' . strtoupper( $level ) . ': ' . $line ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions
    }
}

/**
 * Convenience wrappers.
 *
 * @param string $message Message.
 * @param array  $context Context.
 * @return void
 */
function fac_log_error( $message, $context = array() ) {
    fac_log( 'error', $message, $context );
}

function fac_log_warning( $message, $context = array() ) { // phpcs:ignore Squiz.Commenting.FunctionComment.Missing
    fac_log( 'warning', $message, $context );
}

function fac_log_info( $message, $context = array() ) { // phpcs:ignore Squiz.Commenting.FunctionComment.Missing
    fac_log( 'info', $message, $context );
}

/**
 * Flatten a nested option value into dot-path => scalar leaves.
 *
 * @param mixed  $value  Option value.
 * @param string $prefix Accumulated key path.
 * @return array
 */
function fac_flatten_option_value( $value, $prefix = '' ) {
    if ( ! is_array( $value ) ) {
        return array( $prefix => $value );
    }

    $flat = array();
    foreach ( $value as $key => $item ) {
        $path = '' === $prefix ? (string) $key : $prefix . '.' . $key;
        $flat = array_merge( $flat, fac_flatten_option_value( $item, $path ) );
    }

    return $flat;
}

/**
 * Update an option and log an audit trail of old→new leaf values.
 *
 * Paper rates and pricing multipliers are the business-critical options;
 * this records who changed which values and when so any pricing dispute or
 * mistake can be traced (WooCommerce → Status → Logs, source roll-fed-calc).
 *
 * @param string $option_key Option name.
 * @param mixed  $new_value  Sanitized new value.
 * @param string $via        Save path label, e.g. "settings" or "import".
 * @return void
 */
function fac_update_option_audited( $option_key, $new_value, $via = 'settings' ) {
    $old_value = get_option( $option_key, null );
    update_option( $option_key, $new_value );

    $old_flat = null === $old_value ? array() : fac_flatten_option_value( $old_value );
    $new_flat = fac_flatten_option_value( $new_value );

    $changes = array();
    foreach ( $new_flat as $path => $value ) {
        $old = array_key_exists( $path, $old_flat ) ? $old_flat[ $path ] : null;
        if ( $old !== $value ) {
            $changes[ $path ] = array( 'from' => $old, 'to' => $value );
        }
    }
    foreach ( $old_flat as $path => $value ) {
        if ( ! array_key_exists( $path, $new_flat ) ) {
            $changes[ $path ] = array( 'from' => $value, 'to' => null );
        }
    }

    if ( empty( $changes ) ) {
        return;
    }

    $total = count( $changes );
    if ( $total > 50 ) {
        $changes = array_slice( $changes, 0, 50, true );
    }

    $user    = function_exists( 'wp_get_current_user' ) ? wp_get_current_user() : null;
    $context = array(
        'option'       => $option_key,
        'via'          => $via,
        'user_id'      => isset( $user->ID ) ? (int) $user->ID : 0,
        'user_login'   => isset( $user->user_login ) ? (string) $user->user_login : '',
        'change_count' => $total,
        'changes'      => $changes,
    );
    if ( null === $old_value ) {
        $context['first_save'] = true;
    }

    fac_log_info( 'Option updated: ' . $option_key, $context );
}
