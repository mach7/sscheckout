<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class SSC_Admin {

    public function __construct() {
        add_action( 'admin_menu', array( $this, 'register_admin_page' ) );
        add_action( 'admin_init', array( $this, 'register_settings' ) );
    }
    
    /**
     * Adds a top-level menu page.
     */
    public function register_admin_page() {
        add_menu_page(
            'Simple Stripe Checkout',
            'Simple Stripe Checkout',
            'manage_options',
            'flw-plugins',
            array( $this, 'admin_page_callback' ),
            SSCHECKOUT_PLUGIN_URL . 'assets/images/logo.png',
            6
        );
    }
    
    /**
     * Outputs the admin page content.
     */
    public function admin_page_callback() {
        ?>
        <div class="wrap">
            <h1>Simple Stripe Checkout</h1>
            <h2>Settings</h2>
            <form method="post" action="options.php">
                <?php
                settings_fields( 'sscheckout_settings_group' );
                do_settings_sections( 'sscheckout_settings_group' );
                ?>
                <table class="form-table">
                    <tr valign="top">
                        <th scope="row">Stripe Public Key</th>
                        <td>
                            <input type="text" name="flw_stripe_public_key" value="<?php echo esc_attr( get_option( 'flw_stripe_public_key' ) ); ?>" />
                        </td>
                    </tr>
                    <tr valign="top">
                        <th scope="row">Stripe Secret Key</th>
                        <td>
                            <input type="text" name="flw_stripe_secret_key" value="<?php echo esc_attr( get_option( 'flw_stripe_secret_key' ) ); ?>" />
                        </td>
                    </tr>
                </table>
                <?php submit_button(); ?>
            </form>
            
            <?php
            // Include the orders list below the settings form.
            include SSCHECKOUT_PLUGIN_DIR . 'admin/orders-list.php';
            ?>
        </div>
        <?php
    }
    
    /**
     * Registers the settings used by the plugin.
     */
    public function register_settings() {
        register_setting( 'sscheckout_settings_group', 'flw_stripe_public_key' );
        register_setting( 'sscheckout_settings_group', 'flw_stripe_secret_key' );
    }
}

new SSC_Admin();
