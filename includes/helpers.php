<?php
if (!defined('ABSPATH')) {
    exit;
}

class SSC_Helpers {
    /**
     * Get the current user ID based on login status or cookie.
     */
    public static function get_user_id() {
        if (is_user_logged_in()) {
            return get_current_user_id();
        }
        if (!isset($_COOKIE['ssc_user_id'])) {
            setcookie('ssc_user_id', uniqid(), time() + (86400 * 30), '/');
        }
        return $_COOKIE['ssc_user_id'];
    }

    /**
     * Format price from cents to dollars.
     */
    public static function format_price($amount) {
        return '$' . number_format($amount / 100, 2);
    }

    /**
     * Sanitize input data to prevent XSS and SQL injection.
     */
    public static function sanitize_input($input) {
        return sanitize_text_field($input);
    }
}