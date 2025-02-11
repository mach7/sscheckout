<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Returns the unique user ID stored in a cookie.
 * Creates a new one if not already set.
 */
function sscheckout_get_user_id() {
    if ( isset( $_COOKIE['sscheckout_user'] ) ) {
        return sanitize_text_field( $_COOKIE['sscheckout_user'] );
    } else {
        $user_id = uniqid( 'sscheckout_', true );
        // Set cookie for 30 days
        setcookie( 'sscheckout_user', $user_id, time() + ( 3600 * 24 * 30 ), COOKIEPATH, COOKIE_DOMAIN );
        $_COOKIE['sscheckout_user'] = $user_id;
        return $user_id;
    }
}
