<?php
/*
Plugin Name: Simple Stripe Checkout
Description: A lightweight Stripe checkout plugin for adding simple cart functionality with embedded payments.
Version: 1.0.0
Author: Tyson Brooks
Author URI: https://frostlineworks.com
Tested up to: 6.4
*/

// Prevent direct access to this file
if (!defined('ABSPATH')) {
    exit;
}

// Ensure the FLW Plugin Library is loaded before running the plugin
add_action('plugins_loaded', function () {

    // Check if the FLW Plugin Library is active
    if (class_exists('FLW_Plugin_Update_Checker')) {
        $pluginSlug = basename(dirname(__FILE__)); // Dynamically get the plugin slug

        // Initialize the update checker
        FLW_Plugin_Update_Checker::initialize(__FILE__, $pluginSlug);

        // Replace the update icon
        add_filter('site_transient_update_plugins', function ($transient) {
            if (isset($transient->response)) {
                foreach ($transient->response as $plugin_slug => $plugin_data) {
                    // Use the plugin's main file path to determine its asset URL
                    if ($plugin_slug === plugin_basename(__FILE__)) {
                        $icon_url = plugins_url('assets/logo-128x128.png', __FILE__);
                        $transient->response[$plugin_slug]->icons = [
                            'default' => $icon_url,
                            '1x' => $icon_url,
                            '2x' => plugins_url('assets/logo-256x256.png', __FILE__),
                        ];
                    }
                }
            }
            return $transient;
        });
    } else {
        // Admin notice for missing library
        add_action('admin_notices', function () {
            $pluginSlug = 'flwpluginlibrary/flwpluginlibrary.php';
            $plugins = get_plugins();

            if (!isset($plugins[$pluginSlug])) {
                echo '<div class="notice notice-error"><p>The FLW Plugin Library is not installed. Please install and activate it to enable update functionality.</p></div>';
            } elseif (!is_plugin_active($pluginSlug)) {
                $activateUrl = wp_nonce_url(
                    admin_url('plugins.php?action=activate&plugin=' . $pluginSlug),
                    'activate-plugin_' . $pluginSlug
                );
                echo '<div class="notice notice-error"><p>The FLW Plugin Library is installed but not active. Please <a href="' . esc_url($activateUrl) . '">activate</a> it to enable update functionality.</p></div>';
            }
        });
    }

    // Check if the FLW Plugin Library is available
    if (class_exists('FLW_Plugin_Library')) {
        class SSC_Plugin {
            /**
             * Constructor to initialize the plugin.
             */
            public function __construct() {
                add_action('admin_menu', [$this, 'register_submenu']);
            }

            /**
             * Register the submenu under the FLW Plugins menu.
             */
            public function register_submenu() {
                FLW_Plugin_Library::add_submenu(
                    'Simple Stripe Checkout', // Title
                    'sscheckout', // Slug
                    [$this, 'render_settings_page'] // Callback function
                );
            }

            /**
             * Render the settings page content.
             */
            public function render_settings_page() {
                echo '<div class="wrap">';
                echo '<h1>Simple Stripe Checkout</h1>';
                echo '<form method="post" action="options.php">';
                // Settings fields and save button would go here
                echo '<p>Here you can manage settings for Simple Stripe Checkout.</p>';
                echo '</form>';
                echo '</div>';
            }
        }

        // Initialize the plugin
        new SSC_Plugin();
    } else {
        // Show an admin notice if the FLW Plugin Library is not active
        add_action('admin_notices', function () {
            echo '<div class="notice notice-error"><p>The FLW Plugin Library must be activated for Simple Stripe Checkout to work.</p></div>';
        });
    }
});
