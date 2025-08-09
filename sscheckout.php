<?php
/*
Plugin Name: Simple Shopping Cart
Description: A simple shopping cart plugin with Stripe checkout integration.
Version: 1.3.0
Author: Tyson Brooks
Author URI: https://frostlineworks.com
Tested up to: 6.2
*/
// Prevent direct access to this file.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
function ssc_display_disabled_banner() {
    if ( get_option( 'ssc_global_orders_disabled', 0 ) ) {
        echo '<div style="
            background: #fdd;
            color: #900;
            text-align: center;
            padding: 10px;
            font-weight: bold;
            border-bottom: 2px solid #900;
            ">
            Online orders are currently disabled.
        </div>';
    }
}
add_action( 'wp_head', 'ssc_display_disabled_banner' );
function enqueue_datepicker_assets() {
    wp_enqueue_script( 'jquery-ui-datepicker' );
    wp_enqueue_style( 'jquery-ui-css', 'https://code.jquery.com/ui/1.12.1/themes/base/jquery-ui.css' );
}
add_action( 'wp_enqueue_scripts', 'enqueue_datepicker_assets' );
// Ensure the FLW Plugin Library is loaded before running the plugin.
add_action('plugins_loaded', function () {
	// Check if the FLW Plugin Update Checker class exists.
	if ( class_exists( 'FLW_Plugin_Update_Checker' ) ) {
		$pluginSlug = basename( dirname( __FILE__ ) );
		FLW_Plugin_Update_Checker::initialize( __FILE__, $pluginSlug );
		add_filter('site_transient_update_plugins', function ($transient) {
			if ( isset( $transient->response ) ) {
				foreach ( $transient->response as $plugin_slug => $plugin_data ) {
					if ( $plugin_slug === plugin_basename( __FILE__ ) ) {
						$icon_url = plugins_url( 'assets/logo-128x128.png', __FILE__ );
						$transient->response[$plugin_slug]->icons = [
							'default' => $icon_url,
							'1x'      => $icon_url,
							'2x'      => plugins_url( 'assets/logo-256x256.png', __FILE__ ),
						];
					}
				}
			}
			return $transient;
		});
	} else {
		add_action('admin_notices', function () {
			if ( ! function_exists( 'get_plugins' ) ) {
				require_once ABSPATH . 'wp-admin/includes/plugin.php';
			}
			$pluginSlug = 'flwpluginlibrary/flwpluginlibrary.php';
			$plugins    = get_plugins();
			if ( ! isset( $plugins[ $pluginSlug ] ) ) {
				echo '<div class="notice notice-error"><p>The FLW Plugin Library is not installed. Please install and activate it to enable update functionality.</p></div>';
			} elseif ( ! is_plugin_active( $pluginSlug ) ) {
				$activateUrl = wp_nonce_url(
					admin_url( 'plugins.php?action=activate&plugin=' . $pluginSlug ),
					'activate-plugin_' . $pluginSlug
				);
				echo '<div class="notice notice-error"><p>The FLW Plugin Library is installed but not active. Please <a href="' . esc_url( $activateUrl ) . '">activate</a> it to enable update functionality.</p></div>';
			}
		});
	}
	if ( class_exists( 'FLW_Plugin_Library' ) ) {
		class SimpleShoppingCart_Plugin {
			/**
			 * Constructor – sets up activation, shortcodes, AJAX handlers, scripts, and admin menu.
			 */
            public function __construct() {
                register_activation_hook( __FILE__, [ __CLASS__, 'activate' ] );
                add_action( 'init', [ $this, 'maybe_create_tables' ] );
                add_action( 'init', [ $this, 'register_shortcodes' ] );
                add_action( 'wp_enqueue_scripts', [ $this, 'enqueue_scripts' ] );
                // Enqueue script in admin area as well.
                add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_scripts' ] );
                add_action( 'wp_ajax_ssc_update_cart', [ $this, 'update_cart' ] );
                add_action( 'wp_ajax_nopriv_ssc_update_cart', [ $this, 'update_cart' ] );
                // New SCA-compliant Stripe flow: create intent + finalize order
                add_action( 'wp_ajax_ssc_create_intent', [ $this, 'ajax_create_payment_intent' ] );
                add_action( 'wp_ajax_nopriv_ssc_create_intent', [ $this, 'ajax_create_payment_intent' ] );
                add_action( 'wp_ajax_ssc_finalize_order', [ $this, 'ajax_finalize_order' ] );
                add_action( 'wp_ajax_nopriv_ssc_finalize_order', [ $this, 'ajax_finalize_order' ] );
                add_action( 'admin_menu', [ $this, 'register_submenu' ] );
                add_action( 'wp_ajax_ssc_remove_pickup_type', [ $this, 'remove_pickup_type_ajax' ] );
                // Optionally, if non-logged-in users should be allowed (if applicable):
                // add_action( 'wp_ajax_nopriv_ssc_remove_pickup_type', [ $this, 'remove_pickup_type_ajax' ] );
                // Nothing else required here for gift cards
                add_action( 'init', function() {
                    if ( ! is_user_logged_in() && ! isset( $_COOKIE['ssc_uid'] ) ) {
                        $uid = 'guest_' . wp_generate_uuid4();
                        // Set secure cookie flags
                        $cookie_args = [
                            'expires'  => time() + ( 3600 * 24 * 30 ),
                            'path'     => COOKIEPATH,
                            'domain'   => COOKIE_DOMAIN,
                            'secure'   => is_ssl(),
                            'httponly' => true,
                            'samesite' => 'Lax',
                        ];
                        if ( function_exists( 'setcookie' ) ) {
                            setcookie( 'ssc_uid', $uid, $cookie_args );
                        }
                    }
                });
            }
            
			/**
			 * Plugin activation callback to create required database tables.
			 */
			public static function activate() {
				global $wpdb;
				require_once ABSPATH . 'wp-admin/includes/upgrade.php';
				$charset_collate = $wpdb->get_charset_collate();
				// Shopping Cart table.
				$table1 = $wpdb->prefix . 'flw_shopping_cart';
                $sql1 = "CREATE TABLE $table1 (
					id mediumint(9) NOT NULL AUTO_INCREMENT,
					uid varchar(100) NOT NULL,
					product_name varchar(255) NOT NULL,
					product_price decimal(10,2) NOT NULL,
					quantity int NOT NULL DEFAULT 1,
					added_at datetime DEFAULT CURRENT_TIMESTAMP,
                    PRIMARY KEY (id),
                    UNIQUE KEY uid_product (uid, product_name)
				) $charset_collate;";
				dbDelta( $sql1 );
				// Order History table.
				$table2 = $wpdb->prefix . 'flw_order_history';
				$sql2 = "CREATE TABLE $table2 (
					id mediumint(9) NOT NULL AUTO_INCREMENT,
					uid varchar(100) NOT NULL,
					order_id varchar(100) NOT NULL,
					product_name varchar(255) NOT NULL,
					product_price decimal(10,2) NOT NULL,
					quantity int NOT NULL DEFAULT 1,
					purchased_at datetime DEFAULT CURRENT_TIMESTAMP,
					PRIMARY KEY (id)
				) $charset_collate;";
				dbDelta( $sql2 );
				// Pickup Types table.
				$table3 = $wpdb->prefix . 'flw_pickup_types';
				$sql3 = "CREATE TABLE $table3 (
					id mediumint(9) NOT NULL AUTO_INCREMENT,
					name varchar(255) NOT NULL,
					min_lead_time int NOT NULL DEFAULT 0,
					time_blocks text NOT NULL,
					created_at datetime DEFAULT CURRENT_TIMESTAMP,
					PRIMARY KEY (id)
				) $charset_collate;";
				dbDelta( $sql3 );
			}
			/**
			 * Checks if the custom tables exist; if not, calls activation.
			 */
			public function maybe_create_tables() {
				global $wpdb;
				$table1 = $wpdb->prefix . 'flw_shopping_cart';
				$table2 = $wpdb->prefix . 'flw_order_history';
				$table3 = $wpdb->prefix . 'flw_pickup_types';
				$exists1 = $wpdb->get_var( $wpdb->prepare( "SHOW TABLES LIKE %s", $table1 ) );
				$exists2 = $wpdb->get_var( $wpdb->prepare( "SHOW TABLES LIKE %s", $table2 ) );
				$exists3 = $wpdb->get_var( $wpdb->prepare( "SHOW TABLES LIKE %s", $table3 ) );
				if ( ! $exists1 || ! $exists2 || ! $exists3 ) {
					self::activate();
				}
			}
			/**
			 * Upgrades the database structure by running the current SQL schema.
			 */
			public function upgrade_database() {
				global $wpdb;
				require_once ABSPATH . 'wp-admin/includes/upgrade.php';
				$charset_collate = $wpdb->get_charset_collate();
				$table1 = $wpdb->prefix . 'flw_shopping_cart';
                $sql1 = "CREATE TABLE $table1 (
					id mediumint(9) NOT NULL AUTO_INCREMENT,
					uid varchar(100) NOT NULL,
					product_name varchar(255) NOT NULL,
					product_price decimal(10,2) NOT NULL,
					quantity int NOT NULL DEFAULT 1,
					added_at datetime DEFAULT CURRENT_TIMESTAMP,
                    PRIMARY KEY (id),
                    UNIQUE KEY uid_product (uid, product_name)
				) $charset_collate;";
				dbDelta( $sql1 );
				$table2 = $wpdb->prefix . 'flw_order_history';
				$sql2 = "CREATE TABLE $table2 (
					id mediumint(9) NOT NULL AUTO_INCREMENT,
					uid varchar(100) NOT NULL,
					order_id varchar(100) NOT NULL,
					product_name varchar(255) NOT NULL,
					product_price decimal(10,2) NOT NULL,
					quantity int NOT NULL DEFAULT 1,
					purchased_at datetime DEFAULT CURRENT_TIMESTAMP,
					PRIMARY KEY (id)
				) $charset_collate;";
				dbDelta( $sql2 );
				$table3 = $wpdb->prefix . 'flw_pickup_types';
				$sql3 = "CREATE TABLE $table3 (
					id mediumint(9) NOT NULL AUTO_INCREMENT,
					name varchar(255) NOT NULL,
					min_lead_time int NOT NULL DEFAULT 0,
					time_blocks text NOT NULL,
					created_at datetime DEFAULT CURRENT_TIMESTAMP,
					PRIMARY KEY (id)
				) $charset_collate;";
				dbDelta( $sql3 );
				echo '<div class="updated"><p>Database upgraded successfully.</p></div>';
			}
			/**
			 * Registers shortcodes.
			 */
			public function register_shortcodes() {
				add_shortcode( 'add_to_cart', [ $this, 'add_to_cart_shortcode' ] );
				add_shortcode( 'checkout', [ $this, 'checkout_shortcode' ] );
                add_shortcode( 'gift_cards', [ $this, 'gift_cards_shortcode' ] );
			}
			/**
			 * Enqueues front-end JavaScript and CSS.
			 */
            public function enqueue_scripts() {
                wp_enqueue_script( 'stripe-js', 'https://js.stripe.com/v3/', array(), null, true );
                wp_enqueue_script(
                    'simple-shopping-cart',
                    plugins_url( 'assets/js/simple-shopping-cart.js', __FILE__ ),
                    [ 'jquery' ],
                    '1.2.0',
                    true
                );
				global $wpdb;
				$table = $wpdb->prefix . 'flw_pickup_types';
				$pickup_types = $wpdb->get_results("SELECT * FROM $table", ARRAY_A);
				if( is_array($pickup_types) ) {
					foreach($pickup_types as &$pt) {
						if ( function_exists('wp_json_decode') ) {
                            $blocks = wp_json_decode($pt['time_blocks'], true);
                        } else {
                            $blocks = json_decode($pt['time_blocks'], true);
                        }
					}
					unset($pt);
				}
                wp_localize_script( 'simple-shopping-cart', 'sscheckout_params', [
                    'ajax_url'       => admin_url( 'admin-ajax.php' ),
                    'publishableKey' => get_option( 'flw_stripe_public_key' ),
                    'enable_pickup'  => get_option( 'ssc_enable_pickup_options', 1 ),
                    'global_restrictions' => [
                         'closed_days'      => get_option('ssc_closed_days', []),
                         'after_hours_start'=> get_option('ssc_after_hours_start', '18:00'),
                         'after_hours_end'  => get_option('ssc_after_hours_end', '08:00')
                    ],
                    'pickup_types'   => $pickup_types,
                    // Nonces
                    'cart_nonce'     => wp_create_nonce( 'ssc_cart' ),
                    'checkout_nonce' => wp_create_nonce( 'ssc_checkout' ),
                ] );
                wp_enqueue_style(
                    'simple-shopping-cart',
                    plugins_url( 'assets/css/simple-shopping-cart.css', __FILE__ )
                );
			}
			/**
			 * Returns a unique identifier for the current user.
			 */
			public function get_user_uid() {
				if ( is_user_logged_in() ) {
					return 'user_' . get_current_user_id();
				}
				if ( isset( $_COOKIE['ssc_uid'] ) ) {
					return sanitize_text_field( wp_unslash( $_COOKIE['ssc_uid'] ) );
				}
				return 'guest_' . wp_generate_uuid4();
			}
			/**
			 * [add_to_cart] shortcode output.
			 */
            public function add_to_cart_shortcode( $atts ) {
				$atts = shortcode_atts(
					[
						'price' => '0',
						'name'  => '',
					],
					$atts,
					'add_to_cart'
				);
				$uid = $this->get_user_uid();
				global $wpdb;
				$table = $wpdb->prefix . 'flw_shopping_cart';
				$existing = $wpdb->get_row(
					$wpdb->prepare(
						"SELECT * FROM $table WHERE uid = %s AND product_name = %s",
						$uid,
						$atts['name']
					)
				);
                // Prepare a signed price token to prevent tampering
                $price_float = floatval( $atts['price'] );
                $price_str   = number_format( $price_float, 2, '.', '' );
                $sig_payload = $atts['name'] . '|' . $price_str;
                $price_sig   = hash_hmac( 'sha256', $sig_payload, wp_salt( 'auth' ) );
                ob_start();
				if ( $existing ) {
					?>
					<div class="ssc-product" data-product="<?php echo esc_attr( $atts['name'] ); ?>">
						<button class="ssc-minus" data-action="minus">–</button>
						<span class="ssc-quantity"><?php echo intval( $existing->quantity ); ?></span>
						<button class="ssc-plus" data-action="plus">+</button>
						<button class="ssc-remove" data-action="remove">🗑️</button>
					</div>
					<?php
				} else {
					?>
                    <div class="ssc-product" data-product="<?php echo esc_attr( $atts['name'] ); ?>" data-price="<?php echo esc_attr( $price_str ); ?>" data-sig="<?php echo esc_attr( $price_sig ); ?>">
						<button class="ssc-add-to-cart">Add to Cart</button>
					</div>
					<?php
				}
				return ob_get_clean();
			}
            /**
             * [gift_cards] shortcode output – renders enabled gift card denominations.
             */
            public function gift_cards_shortcode() {
                $gift_cards = get_option( 'ssc_gift_cards', [] );
                if ( ! is_array( $gift_cards ) || empty( $gift_cards ) ) {
                    return '<p>No gift cards available at this time.</p>';
                }
                // Build output using existing add_to_cart renderer to keep behavior consistent
                ob_start();
                echo '<div class="ssc-gift-cards">';
                foreach ( $gift_cards as $gc ) {
                    $enabled = isset( $gc['enabled'] ) ? intval( $gc['enabled'] ) : 0;
                    $price   = isset( $gc['price'] ) ? floatval( $gc['price'] ) : 0;
                    $stock   = isset( $gc['stock'] ) ? intval( $gc['stock'] ) : 0;
                    if ( ! $enabled || $price <= 0 || $stock < 0 ) {
                        continue;
                    }
                    $name = 'Gift Card $' . number_format( $price, 2 );
                    echo '<div class="ssc-gift-card">';
                    echo '<div class="ssc-product-title">' . esc_html( $name ) . '</div>';
                    // Render the add_to_cart block for this gift card
                    echo $this->add_to_cart_shortcode( [ 'name' => $name, 'price' => (string) $price ] );
                    echo '</div>';
                }
                echo '</div>';
                return ob_get_clean();
            }
			/**
			 * [checkout] shortcode output.
			 */
			public function checkout_shortcode() {
                $uid = $this->get_user_uid();
                global $wpdb;
                $table = $wpdb->prefix . 'flw_shopping_cart';
                $items = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM $table WHERE uid = %s", $uid ) );
            
                $pickup_table = $wpdb->prefix . 'flw_pickup_types';
                $pickup_types = $wpdb->get_results("SELECT * FROM $pickup_table", ARRAY_A);
                if( is_array($pickup_types) ) {
					foreach($pickup_types as &$pt) {
						if ( function_exists('wp_json_decode') ) {
                            $blocks = wp_json_decode($pt['time_blocks'], true);
                        } else {
                            $blocks = json_decode($pt['time_blocks'], true);
                        }
						foreach ($blocks as $day => $arr) {
							$blocks[$day] = implode(', ', $arr);
						}
						$pt['time_blocks'] = $blocks;
					}
					unset($pt);
				}
                $enable_pickup = get_option( 'ssc_enable_pickup_options', 1 );
                ?>
                <div class="ssc-checkout">
                <h2>Your Cart</h2>
                <?php if ( $items ) : ?>
                    <table class="ssc-cart-table">
                        <thead>
                            <tr>
                                <th>Product</th>
                                <th>Price</th>
                                <th class="qualityCol">Quantity</th>
                                <th class="actionsCol">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php 
                            $cart_total = 0; // Initialize total cart price
                            foreach ( $items as $item ) : 
                                $item_total = floatval($item->product_price) * intval($item->quantity);
                                $cart_total += $item_total;
                            ?>
                                <tr data-product="<?php echo esc_attr( $item->product_name ); ?>">
                                    <td><?php echo esc_html( $item->product_name ); ?></td>
                                    <td class="ssc-item-price">$<?php echo number_format($item->product_price, 2); ?></td>
                                    <td class="ssc-item-quantity"><?php echo intval( $item->quantity ); ?></td>
                                    <td class="ssc-item-actions">
                                        <button class="ssc-minus" data-action="minus">–</button>
                                        <button class="ssc-plus" data-action="plus">+</button>
                                        <button class="ssc-remove" data-action="remove">🗑️</button>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                    <!-- Cart Total Section -->
                    <div class="ssc-cart-total">
                        <h3>Total: $<?php echo number_format($cart_total, 2); ?></h3>
                    </div>
                <?php else : ?>
                    <p>Your cart is empty.</p>
                <?php endif; ?>
            
                    <h2>Checkout</h2>
                    <form id="ss-checkout-form">
                        <label>Name: <input type="text" name="name" required></label><br>
                        <?php if ( ! is_user_logged_in() ) : ?>
                            <label>Email: <input type="email" name="email" required></label><br>
                            <label>Password: <input type="password" name="password" required></label><br>
                        <?php endif; ?>
                        <label>Phone: <input type="text" name="phone"></label><br>
            
                        <?php if ( $enable_pickup ) : ?>
                            <h3>Pickup Options</h3>
                            <label for="pickup_type">Pickup Type:</label>
                            <select name="pickup_type" id="pickup_type" required>
                                <?php 
                                if ( ! empty( $pickup_types ) && is_array( $pickup_types ) ) {
                                    foreach ( $pickup_types as $type ) {
                                        echo '<option value="' . esc_attr( $type['name'] ) . '">' . esc_html( $type['name'] ) . '</option>';
                                    }
                                } else {
                                    echo '<option value="">No pickup types configured</option>';
                                }
                                ?>
                            </select>
                            <br>
                            <label for="pickup_date">Pickup Date:</label>
                            <input type="date" name="pickup_date" id="pickup_date" required>
                            <br>
                            <label for="pickup_time">Pickup Time:</label>
                            <input type="time" name="pickup_time" id="pickup_time" required>
                            <div id="pickup-time-error" style="color:red;"></div>
                            <br><br>
                        <?php endif; ?>
            
                        <h3>Payment Details</h3>
                        <div id="card-element"><!-- Stripe Element will be inserted here --></div>
                        <div id="card-errors" role="alert"></div>
                        <input type="hidden" name="action" value="ssc_checkout">
                        <button type="submit">Submit Payment</button>
                    </form>
                    <div id="ss-checkout-response"></div>
                </div>
                <?php
                return ob_get_clean();
			}
            public function update_cart() {
                // CSRF protection
                check_ajax_referer( 'ssc_cart', 'nonce' );
                if ( empty( $_POST['product'] ) || empty( $_POST['action_type'] ) ) {
                    wp_send_json_error( 'Missing parameters' );
                }
                $product    = sanitize_text_field( wp_unslash( $_POST['product'] ) );
                $actionType = sanitize_text_field( wp_unslash( $_POST['action_type'] ) );
                $uid        = $this->get_user_uid();
            
                global $wpdb;
                $table = $wpdb->prefix . 'flw_shopping_cart';
                $item  = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM $table WHERE uid = %s AND product_name = %s", $uid, $product ) );

                // Gift card inventory check helper
                $is_gift_card = $this->is_gift_card_product( $product );
                $available_left = null;
                if ( $is_gift_card ) {
                    $available_left = $this->get_gift_card_available( $product );
                }
            
                if ( 'add' === $actionType ) {
                    if ( $item ) {
                        // Inventory gate for gift cards
                        if ( $is_gift_card ) {
                            $requested_qty = intval( $item->quantity ) + 1;
                            if ( $available_left <= 0 ) {
                                wp_send_json_error( 'Gift card is out of stock.' );
                            }
                            if ( $requested_qty > $available_left ) {
                                wp_send_json_error( 'Only ' . intval( $available_left ) . ' gift card(s) remaining.' );
                            }
                        }
                        $new_quantity = $item->quantity + 1;
                        $wpdb->update( $table, [ 'quantity' => $new_quantity ], [ 'id' => $item->id ] );
                    } else {
                        // Verify signed price to prevent tampering
                        $posted_price = isset( $_POST['price'] ) ? wp_unslash( $_POST['price'] ) : '0';
                        $posted_sig   = isset( $_POST['sig'] ) ? sanitize_text_field( wp_unslash( $_POST['sig'] ) ) : '';
                        $price_str    = number_format( floatval( $posted_price ), 2, '.', '' );
                        $expected_sig = hash_hmac( 'sha256', $product . '|' . $price_str, wp_salt( 'auth' ) );
                        if ( ! hash_equals( $expected_sig, $posted_sig ) ) {
                            wp_send_json_error( 'Invalid price signature' );
                        }
                        // Inventory gate for gift cards
                        if ( $is_gift_card ) {
                            if ( $available_left <= 0 ) {
                                wp_send_json_error( 'Gift card is out of stock.' );
                            }
                        }
                        $price = floatval( $price_str );
                        // Try insert; if unique constraint triggers, fall back to update
                        $inserted = $wpdb->insert( $table, [
                            'uid'           => $uid,
                            'product_name'  => $product,
                            'product_price' => $price,
                            'quantity'      => 1,
                            'added_at'      => current_time( 'mysql' )
                        ] );
                        if ( false === $inserted ) {
                            // Re-fetch and increment
                            $item = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM $table WHERE uid = %s AND product_name = %s", $uid, $product ) );
                            if ( $item ) {
                                $new_quantity = $item->quantity + 1;
                                $wpdb->update( $table, [ 'quantity' => $new_quantity ], [ 'id' => $item->id ] );
                            } else {
                                wp_send_json_error( 'Could not add item' );
                            }
                        } else {
                            $new_quantity = 1;
                        }
                    }
                } elseif ( 'plus' === $actionType ) {
                    if ( ! $item ) {
                        wp_send_json_error( 'Item not found' );
                    }
                    if ( $is_gift_card ) {
                        $requested_qty = intval( $item->quantity ) + 1;
                        if ( $available_left <= 0 ) {
                            wp_send_json_error( 'Gift card is out of stock.' );
                        }
                        if ( $requested_qty > $available_left ) {
                            wp_send_json_error( 'Only ' . intval( $available_left ) . ' gift card(s) remaining.' );
                        }
                    }
                    $new_quantity = $item->quantity + 1;
                    $wpdb->update( $table, [ 'quantity' => $new_quantity ], [ 'id' => $item->id ] );
                } elseif ( 'minus' === $actionType ) {
                    if ( ! $item ) {
                        wp_send_json_error( 'Item not found' );
                    }
                    $new_quantity = max( 1, $item->quantity - 1 );
                    $wpdb->update( $table, [ 'quantity' => $new_quantity ], [ 'id' => $item->id ] );
                } elseif ( 'remove' === $actionType ) {
                    if ( ! $item ) {
                        wp_send_json_error( 'Item not found' );
                    }
                    $wpdb->delete( $table, [ 'id' => $item->id ] );
                    $new_quantity = 0;
                } else {
                    wp_send_json_error( 'Invalid action' );
                }
            
                // Calculate updated cart total
                $cart_total = $wpdb->get_var( $wpdb->prepare(
                    "SELECT SUM(product_price * quantity) FROM $table WHERE uid = %s", 
                    $uid
                ));
                $cart_total = number_format($cart_total, 2);
            
                wp_send_json_success( [
                    'quantity'   => $new_quantity,
                    'cart_total' => $cart_total
                ] );
            }

            /**
             * Parse a standardized price from a product name like "Gift Card $50" or "Gift Card $50.00".
             */
            private function parse_gift_card_price_from_name( $product_name ) {
                if ( ! is_string( $product_name ) ) { return null; }
                if ( preg_match( '/^\s*Gift\s+Card\s+\$([0-9]+(?:\.[0-9]{1,2})?)\s*$/i', $product_name, $m ) ) {
                    return round( floatval( $m[1] ), 2 );
                }
                return null;
            }

            /**
             * Determine if the given product name corresponds to a configured gift card.
             */
            private function is_gift_card_product( $product_name ) {
                $price_from_name = $this->parse_gift_card_price_from_name( $product_name );
                if ( null === $price_from_name ) { return false; }
                $gift_cards = get_option( 'ssc_gift_cards', [] );
                if ( ! is_array( $gift_cards ) || empty( $gift_cards ) ) { return false; }
                foreach ( $gift_cards as $gc ) {
                    $enabled = ! empty( $gc['enabled'] );
                    $price   = isset( $gc['price'] ) ? round( floatval( $gc['price'] ), 2 ) : 0;
                    if ( $enabled && $price > 0 && abs( $price - $price_from_name ) < 0.001 ) {
                        return true;
                    }
                }
                return false;
            }

            /**
             * Get available remaining quantity for a gift card product name.
             * Computed as configured stock minus sold quantity.
             */
            private function get_gift_card_available( $product_name ) {
                global $wpdb;
                $price_from_name = $this->parse_gift_card_price_from_name( $product_name );
                if ( null === $price_from_name ) { return PHP_INT_MAX; }
                $gift_cards = get_option( 'ssc_gift_cards', [] );
                if ( ! is_array( $gift_cards ) || empty( $gift_cards ) ) { return PHP_INT_MAX; }
                $configured_stock = null;
                foreach ( $gift_cards as $gc ) {
                    $enabled = ! empty( $gc['enabled'] );
                    $price   = isset( $gc['price'] ) ? round( floatval( $gc['price'] ), 2 ) : 0;
                    $stock   = isset( $gc['stock'] ) ? intval( $gc['stock'] ) : 0;
                    if ( $enabled && $price > 0 && abs( $price - $price_from_name ) < 0.001 ) {
                        $configured_stock = max( 0, $stock );
                        break;
                    }
                }
                if ( null === $configured_stock ) { return PHP_INT_MAX; }
                // Sum sold quantities for both common name formats
                $name_a = 'Gift Card $' . number_format( $price_from_name, 2 );
                $name_b = 'Gift Card $' . number_format( $price_from_name, 0 );
                $order_table = $wpdb->prefix . 'flw_order_history';
                $sold = intval( $wpdb->get_var( $wpdb->prepare( "SELECT COALESCE(SUM(quantity),0) FROM $order_table WHERE product_name IN (%s,%s)", $name_a, $name_b ) ) );
                $available = max( 0, $configured_stock - $sold );
                return $available;
            }
            
            // Step 1: Create PaymentIntent (no confirmation)
            public function ajax_create_payment_intent() {
                check_ajax_referer( 'ssc_checkout', 'nonce' );
                $name     = sanitize_text_field( wp_unslash( $_POST['name'] ?? '' ) );
                $email    = isset( $_POST['email'] ) ? sanitize_email( wp_unslash( $_POST['email'] ) ) : '';
                $phone    = sanitize_text_field( wp_unslash( $_POST['phone'] ?? '' ) );
                $enable_pickup = get_option( 'ssc_enable_pickup_options', 1 );
                $pickup_type = $pickup_date = $pickup_time = '';
                if ( $enable_pickup ) {
                    $pickup_type = sanitize_text_field( wp_unslash( $_POST['pickup_type'] ?? '' ) );
                    $pickup_date = sanitize_text_field( wp_unslash( $_POST['pickup_date'] ?? '' ) );
                    $pickup_time = sanitize_text_field( wp_unslash( $_POST['pickup_time'] ?? '' ) );
                    $pickup_datetime_str = $pickup_date . ' ' . $pickup_time;
                    $pickup_datetime = DateTime::createFromFormat( 'Y-m-d H:i', $pickup_datetime_str );
                    if ( ! $pickup_datetime ) {
                        wp_send_json_error( 'Invalid pickup date/time format. Please use the provided date and time pickers.' );
                    }
                    $closed_days = array_map( 'intval', (array) get_option( 'ssc_closed_days', [] ) );
                    $pickup_day  = intval( $pickup_datetime->format( 'N' ) );
                    if ( in_array( $pickup_day, $closed_days, true ) ) {
                        wp_send_json_error( 'The selected day is closed for orders.' );
                    }
                    global $wpdb;
                    $pickup_table    = $wpdb->prefix . 'flw_pickup_types';
                    $pickup_types_db = $wpdb->get_results( "SELECT * FROM $pickup_table", ARRAY_A );
                    $selected_pt     = null;
                    if ( is_array( $pickup_types_db ) ) {
                        foreach ( $pickup_types_db as $pt ) {
                            if ( isset( $pt['name'] ) && $pt['name'] === $pickup_type ) {
                                $selected_pt = $pt;
                                break;
                            }
                        }
                    }
                    if ( ! $selected_pt ) {
                        wp_send_json_error( 'Invalid pickup type selected.' );
                    }
                    $min_lead_time_hours = isset( $selected_pt['min_lead_time'] ) ? intval( $selected_pt['min_lead_time'] ) : 0;
                    $allowed_time_blocks = isset( $selected_pt['time_blocks'] ) ? wp_json_decode( $selected_pt['time_blocks'], true ) : [];
                    $current_time     = new DateTime();
                    $min_allowed_time = clone $current_time;
                    $min_allowed_time->add( new DateInterval( 'PT' . $min_lead_time_hours . 'H' ) );
                    if ( $pickup_datetime < $min_allowed_time ) {
                        wp_send_json_error( 'Pickup time must be at least ' . $min_lead_time_hours . ' hours from now.' );
                    }
                    $day_of_week = $pickup_datetime->format( 'D' );
                    if ( isset( $allowed_time_blocks[ $day_of_week ] ) && is_array( $allowed_time_blocks[ $day_of_week ] ) ) {
                        $is_valid_time = false;
                        foreach ( $allowed_time_blocks[ $day_of_week ] as $time_range ) {
                            list( $start, $end ) = explode( '-', $time_range );
                            $pickup_time_only = $pickup_datetime->format( 'H:i' );
                            if ( $pickup_time_only >= $start && $pickup_time_only <= $end ) {
                                $is_valid_time = true;
                                break;
                            }
                        }
                        if ( ! $is_valid_time ) {
                            wp_send_json_error( 'The selected pickup time is outside the allowed time blocks for ' . esc_html( $pickup_type ) . '.' );
                        }
                    }
                }
                $uid = $this->get_user_uid();
                global $wpdb;
                $cart_table = $wpdb->prefix . 'flw_shopping_cart';
                $items      = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM $cart_table WHERE uid = %s", $uid ) );
                if ( ! $items ) {
                    wp_send_json_error( 'Cart is empty' );
                }
                if ( get_option( 'ssc_global_orders_disabled', 0 ) ) {
                    wp_send_json_error( 'Online orders are currently disabled.' );
                }
                // Gift card stock re-validation before creating payment
                foreach ( $items as $ci ) {
                    $product_name = $ci->product_name;
                    if ( $this->is_gift_card_product( $product_name ) ) {
                        $available = $this->get_gift_card_available( $product_name );
                        if ( intval( $ci->quantity ) > $available ) {
                            wp_send_json_error( 'Insufficient stock for ' . esc_html( $product_name ) . '. Only ' . intval( $available ) . ' remaining.' );
                        }
                    }
                }
                $total = 0;
                foreach ( $items as $item ) {
                    $total += floatval( $item->product_price ) * intval( $item->quantity );
                }
                $amount = intval( round( $total * 100 ) );
                $stripe_secret = get_option( 'flw_stripe_secret_key' );
                if ( ! $stripe_secret ) {
                    wp_send_json_error( 'Stripe secret key not found' );
                }
                $order_id = 'ORDER-' . time();
                $ch = curl_init( 'https://api.stripe.com/v1/payment_intents' );
                $intent_data = http_build_query( [
                    'amount'             => $amount,
                    'currency'           => 'usd',
                    'description'        => 'Charge for ' . $name,
                    'metadata[order_id]' => $order_id,
                ] );
                curl_setopt( $ch, CURLOPT_USERPWD, $stripe_secret . ':' );
                curl_setopt( $ch, CURLOPT_POST, true );
                curl_setopt( $ch, CURLOPT_POSTFIELDS, $intent_data );
                curl_setopt( $ch, CURLOPT_RETURNTRANSFER, true );
                $intent_response = curl_exec( $ch );
                $intent_result   = json_decode( $intent_response, true );
                curl_close( $ch );
                if ( isset( $intent_result['error'] ) ) {
                    wp_send_json_error( 'Stripe error: ' . $intent_result['error']['message'] );
                }
                if ( empty( $intent_result['client_secret'] ) || empty( $intent_result['id'] ) ) {
                    wp_send_json_error( 'Failed to create payment.' );
                }
                wp_send_json_success( [
                    'client_secret' => $intent_result['client_secret'],
                    'order_id'      => $order_id,
                ] );
            }
            // Step 2: Finalize order after client confirmation
            public function ajax_finalize_order() {
                check_ajax_referer( 'ssc_checkout', 'nonce' );
                $name     = sanitize_text_field( wp_unslash( $_POST['name'] ?? '' ) );
                $email    = isset( $_POST['email'] ) ? sanitize_email( wp_unslash( $_POST['email'] ) ) : '';
                $password = isset( $_POST['password'] ) ? wp_unslash( $_POST['password'] ) : '';
                $phone    = sanitize_text_field( wp_unslash( $_POST['phone'] ?? '' ) );
                $pi_id    = sanitize_text_field( wp_unslash( $_POST['payment_intent_id'] ?? '' ) );
                if ( empty( $pi_id ) ) {
                    wp_send_json_error( 'Missing payment intent.' );
                }
                // Verify PaymentIntent status
                $stripe_secret = get_option( 'flw_stripe_secret_key' );
                if ( ! $stripe_secret ) {
                    wp_send_json_error( 'Stripe secret key not found' );
                }
                $ch = curl_init( 'https://api.stripe.com/v1/payment_intents/' . rawurlencode( $pi_id ) );
                curl_setopt( $ch, CURLOPT_USERPWD, $stripe_secret . ':' );
                curl_setopt( $ch, CURLOPT_RETURNTRANSFER, true );
                $pi_response = curl_exec( $ch );
                curl_close( $ch );
                $pi = json_decode( $pi_response, true );
                if ( isset( $pi['error'] ) ) {
                    wp_send_json_error( 'Stripe error: ' . $pi['error']['message'] );
                }
                if ( empty( $pi['status'] ) || ! in_array( $pi['status'], [ 'succeeded', 'processing', 'requires_capture' ], true ) ) {
                    wp_send_json_error( 'Payment not completed.' );
                }
                $uid = $this->get_user_uid();
                global $wpdb;
                $cart_table  = $wpdb->prefix . 'flw_shopping_cart';
                $order_table = $wpdb->prefix . 'flw_order_history';
                $items       = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM $cart_table WHERE uid = %s", $uid ) );
                if ( ! $items ) {
                    wp_send_json_error( 'Cart is empty' );
                }
                // Create user if needed
                if ( ! is_user_logged_in() && $email ) {
                    if ( email_exists( $email ) === false ) {
                        $user_id = wp_create_user( $email, $password, $email );
                        if ( is_wp_error( $user_id ) ) {
                            wp_send_json_error( 'User creation failed' );
                        }
                    }
                }
                $order_id = isset( $pi['metadata']['order_id'] ) ? $pi['metadata']['order_id'] : ( 'ORDER-' . time() );
                // Send admin email
                $admin_email = get_option( 'ssc_order_admin_email' );
                if ( ! $admin_email ) {
                    $admin_email = get_option( 'admin_email' );
                }
                $subject = 'New Order Received: ' . $order_id;
                $message  = "New Order Received\n\n";
                $message .= "Customer Details:\n";
                $message .= "Name: " . $name . "\n";
                if ( ! empty( $email ) ) { $message .= "Email: " . $email . "\n"; }
                if ( ! empty( $phone ) ) { $message .= "Phone: " . $phone . "\n"; }
                // Note gift card delivery method (in-store pickup only per configuration)
                $message .= "\nGift Card Delivery: In-store pickup\n";
                $message .= "\nOrder Details:\n";
                foreach ( $items as $item ) {
                    $message .= $item->product_name . ' x ' . $item->quantity . ' - $' . $item->product_price . "\n";
                }
                wp_mail( $admin_email, $subject, $message );
                foreach ( $items as $item ) {
                    $wpdb->insert( $order_table, [
                        'uid'           => $uid,
                        'order_id'      => $order_id,
                        'product_name'  => $item->product_name,
                        'product_price' => $item->product_price,
                        'quantity'      => $item->quantity,
                        'purchased_at'  => current_time( 'mysql' ),
                    ] );
                }
                $wpdb->delete( $cart_table, [ 'uid' => $uid ] );
                wp_send_json_success( 'Payment successful and order processed. Order Number: ' . $order_id );
            }
			public function render_stripe_transactions_page() {
				$stripe_secret = get_option( 'flw_stripe_secret_key' );
				if ( ! $stripe_secret ) {
					echo '<div class="error"><p>Stripe secret key not set.</p></div>';
					return;
				}
				$ch = curl_init( 'https://api.stripe.com/v1/charges?limit=20' );
				curl_setopt( $ch, CURLOPT_USERPWD, $stripe_secret . ':' );
				curl_setopt( $ch, CURLOPT_RETURNTRANSFER, true );
				$response = curl_exec( $ch );
				curl_close( $ch );
				$charges = json_decode( $response, true );
				if ( isset( $charges['error'] ) ) {
					echo '<div class="error"><p>Error retrieving transactions: ' . esc_html( $charges['error']['message'] ) . '</p></div>';
					return;
				}
				echo '<div class="wrap"><h1>Stripe Transactions</h1>';
				echo '<table class="wp-list-table widefat fixed striped">';
				echo '<thead><tr>';
				echo '<th>Customer</th>';
				echo '<th>Order Number</th>';
				echo '<th>ID</th>';
				echo '<th>Amount</th>';
				echo '<th>Status</th>';
				echo '<th>Created</th>';
				echo '</tr></thead><tbody>';
				foreach ( $charges['data'] as $charge ) {
					$created = date( 'Y-m-d H:i:s', $charge['created'] );
					$customer_name = 'N/A';
					if ( isset( $charge['source']['name'] ) && ! empty( $charge['source']['name'] ) ) {
						$customer_name = $charge['source']['name'];
					} elseif ( isset( $charge['billing_details']['name'] ) && ! empty( $charge['billing_details']['name'] ) ) {
						$customer_name = $charge['billing_details']['name'];
					}
					$order_id = 'N/A';
					if ( isset( $charge['metadata']['order_id'] ) && ! empty( $charge['metadata']['order_id'] ) ) {
						$order_id_text = $charge['metadata']['order_id'];
						$order_link = admin_url( 'admin.php?page=simple-shopping-cart-order-details&order_id=' . urlencode( $order_id_text ) );
						$order_id = '<a href="' . esc_url( $order_link ) . '">' . esc_html( $order_id_text ) . '</a>';
					}
					echo '<tr>';
					echo '<td>' . esc_html( $customer_name ) . '</td>';
					echo '<td>' . $order_id . '</td>';
					echo '<td>' . esc_html( $charge['id'] ) . '</td>';
					echo '<td>' . esc_html( number_format( $charge['amount'] / 100, 2 ) ) . ' ' . esc_html( strtoupper( $charge['currency'] ) ) . '</td>';
					echo '<td>' . esc_html( $charge['status'] ) . '</td>';
					echo '<td>' . esc_html( $created ) . '</td>';
					echo '</tr>';
				}
				echo '</tbody></table></div>';
			}
			public function render_order_details_page() {
				if ( ! isset( $_GET['order_id'] ) ) {
					echo '<div class="wrap"><h1>Order Details</h1><p>No order selected.</p></div>';
					return;
				}
				$order_id = sanitize_text_field( wp_unslash( $_GET['order_id'] ) );
				global $wpdb;
				$order_table = $wpdb->prefix . 'flw_order_history';
				$items = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM $order_table WHERE order_id = %s", $order_id ) );
				
				echo '<div class="wrap"><h1>Order Details: ' . esc_html( $order_id ) . '</h1>';
				if ( $items ) {
					echo '<table class="wp-list-table widefat fixed striped">';
					echo '<thead><tr>';
					echo '<th>Product</th>';
					echo '<th>Price</th>';
					echo '<th>Quantity</th>';
					echo '<th>Total</th>';
					echo '</tr></thead><tbody>';
					$order_total = 0;
					foreach ( $items as $item ) {
						$item_total = floatval( $item->product_price ) * intval( $item->quantity );
						$order_total += $item_total;
						echo '<tr>';
						echo '<td>' . esc_html( $item->product_name ) . '</td>';
						echo '<td>' . esc_html( number_format( $item->product_price, 2 ) ) . '</td>';
						echo '<td>' . esc_html( $item->quantity ) . '</td>';
						echo '<td>' . esc_html( number_format( $item_total, 2 ) ) . '</td>';
						echo '</tr>';
					}
					echo '<tr>';
					echo '<td colspan="3"><strong>Order Total</strong></td>';
					echo '<td><strong>' . esc_html( number_format( $order_total, 2 ) ) . '</strong></td>';
					echo '</tr>';
					echo '</tbody></table>';
				} else {
					echo '<p>No items found for this order.</p>';
				}
				echo '</div>';
			}
			public function register_submenu() {
				FLW_Plugin_Library::add_submenu(
					'Shopping Cart Settings',
					'simple-shopping-cart',
					[ $this, 'render_settings_page' ]
				);
				FLW_Plugin_Library::add_submenu(
					'Order Details',
					'simple-shopping-cart-order-details',
					[ $this, 'render_order_details_page' ]
				);
				FLW_Plugin_Library::add_submenu(
					'Stripe Transactions',
					'simple-shopping-cart-transactions',
					[ $this, 'render_stripe_transactions_page' ]
				);
                FLW_Plugin_Library::add_submenu(
                    'Instructions',
                    'simple-shopping-cart-instructions',
                    [ $this, 'render_instructions_page' ]
                );
			}
            public function render_instructions_page() {
                // Static instructions page for admins
                echo '<div class="wrap">';
                echo '<h1>Simple Shopping Cart — Instructions</h1>';
                echo '<p>Use the shortcodes and settings below to add products, sell physical gift cards with limited stock, and collect payments via Stripe.</p>';

                echo '<h2>Shortcodes</h2>';
                echo '<ul>';
                echo '<li><strong>[add_to_cart]</strong> — Add an individual product button. Example: <code>[add_to_cart name="Espresso" price="3.50"]</code></li>';
                echo '<li><strong>[gift_cards]</strong> — Render configured gift card denominations as add-to-cart blocks.</li>';
                echo '<li><strong>[checkout]</strong> — Display the cart and checkout/payment form.</li>';
                echo '</ul>';

                echo '<h2>Gift Cards</h2>';
                echo '<ol>';
                echo '<li>Navigate to <em>Shopping Cart Settings</em> → <strong>Gift Cards</strong>.</li>';
                echo '<li>Enable Gift Cards and add one or more denominations with total stock.</li>';
                echo '<li>Place the <code>[gift_cards]</code> shortcode on a page to sell them.</li>';
                echo '<li>Gift card product names are standardized as <code>Gift Card $PRICE</code> (e.g., <code>Gift Card $50.00</code>).</li>';
                echo '<li>Stock is enforced when adding to cart and again before payment is created.</li>';
                echo '<li>Gift cards can be purchased with other items in the same cart.</li>';
                echo '</ol>';

                echo '<h2>Pickup Options</h2>';
                echo '<p>Enable pickup options in <em>Shopping Cart Settings</em> → <strong>General Checkout Settings</strong>. Configure pickup types and time blocks under <strong>Pickup Types Management</strong>. The checkout will require an in-store pickup selection for gift cards.</p>';

                echo '<h2>Online Order Controls</h2>';
                echo '<p>Use <strong>Disable Online Orders</strong> to temporarily stop new orders. A banner appears site-wide while disabled.</p>';

                echo '<h2>Admin Email</h2>';
                echo '<p>Set the destination email for new order notifications under <strong>Admin Order Email</strong> in settings.</p>';

                echo '<h2>Stripe</h2>';
                echo '<p>Ensure your Stripe <em>Publishable</em> and <em>Secret</em> keys are saved in options <code>flw_stripe_public_key</code> and <code>flw_stripe_secret_key</code>. Charges are created via Payment Intents and confirmed client-side.</p>';

                echo '<h2>Database</h2>';
                echo '<p>If you update the plugin and are prompted to upgrade the database, use the <strong>Upgrade Database Structure</strong> button at the bottom of the settings page.</p>';

                echo '<h2>Troubleshooting</h2>';
                echo '<ul>';
                echo '<li><strong>Cart not updating?</strong> Check browser console and ensure AJAX endpoint is reachable. Nonces are generated via <code>wp_localize_script</code>.</li>';
                echo '<li><strong>Stripe errors?</strong> Verify keys, and check the <em>Stripe Transactions</em> page for recent charges.</li>';
                echo '<li><strong>Gift card stock issues?</strong> Stock is computed as configured total minus sold items in order history.</li>';
                echo '</ul>';

                echo '</div>';
            }
            public function render_settings_page() {
                global $wpdb;
                
                // Handle Upgrade Database button.
                if ( isset($_POST['upgrade_db']) ) {
                    if ( ! isset($_POST['upgrade_db_nonce']) || ! wp_verify_nonce($_POST['upgrade_db_nonce'], 'upgrade_db_action') ) {
                        echo '<div class="error"><p>Security check failed. Please try again.</p></div>';
                    } else {
                        $this->upgrade_database();
                        echo '<div class="updated"><p>Database upgraded successfully.</p></div>';
                    }
                }
                
                // Process form submission for general settings.
                if ( isset( $_POST['ssc_save_settings'] ) ) {
                    if ( ! isset( $_POST['ssc_settings_nonce'] ) || ! wp_verify_nonce( $_POST['ssc_settings_nonce'], 'ssc_settings_save' ) ) {
                        echo '<div class="error"><p>Security check failed. Please refresh and try again.</p></div>';
                    } else {
                    // Save admin email.
                    update_option( 'ssc_order_admin_email', sanitize_email( wp_unslash( $_POST['order_admin_email'] ) ) );
                    
                    // Process store hours.
                    if ( isset( $_POST['store_hours'] ) && is_array( $_POST['store_hours'] ) ) {
                        $store_hours = [];
                        foreach ( $_POST['store_hours'] as $day => $data ) {
                            $store_hours[ $day ] = [
                                'open'   => sanitize_text_field( $data['open'] ),
                                'close'  => sanitize_text_field( $data['close'] ),
                                'closed' => isset( $data['closed'] ) ? 1 : 0,
                            ];
                        }
                        update_option( 'ssc_store_hours', $store_hours );
                    }
                    
                    // Process pickup types from the new menu interface.
                    if ( isset($_POST['pickup_types']) && is_array($_POST['pickup_types']) ) {
                        $pickup_table = $wpdb->prefix . 'flw_pickup_types';
                        // For simplicity, clear existing pickup types and re-insert.
                        $wpdb->query("TRUNCATE TABLE $pickup_table");
                        foreach ($_POST['pickup_types'] as $pt) {
                            $time_blocks = array();
                            if ( isset($pt['time_blocks']) && is_array($pt['time_blocks']) ) {
                                foreach ( $pt['time_blocks'] as $day => $blocks_str ) {
                                    $blocks = array();
                                    $parts = explode(',', $blocks_str);
                                    foreach ($parts as $p) {
                                        $p = trim($p);
                                        if (!empty($p)) {
                                            $blocks[] = $p;
                                        }
                                    }
                                    $time_blocks[$day] = $blocks;
                                }
                            }
                            $data = array(
                                'name'         => sanitize_text_field($pt['name']),
                                'min_lead_time'=> intval($pt['min_lead_time']),
                                'time_blocks'  => wp_json_encode($time_blocks),
                                'created_at'   => current_time('mysql'),
                            );
                            $wpdb->insert($pickup_table, $data);
                        }
                    }
                    
                    // Process the pickup options toggle.
                    $enable_pickup = isset( $_POST['ssc_enable_pickup_options'] ) ? 1 : 0;
                    update_option( 'ssc_enable_pickup_options', $enable_pickup );
                    
                    // Process global online orders toggle.
                    $global_orders_disabled = isset($_POST['ssc_global_orders_disabled']) ? 1 : 0;
                    update_option( 'ssc_global_orders_disabled', $global_orders_disabled );
                    
                    // Save gift cards configuration
                    $gift_cards_clean = [];
                    if ( isset( $_POST['ssc_enable_gift_cards'] ) ) {
                        $gift_cards_input = isset( $_POST['gift_cards'] ) && is_array( $_POST['gift_cards'] ) ? $_POST['gift_cards'] : [];
                        foreach ( $gift_cards_input as $gc ) {
                            $price   = isset( $gc['price'] ) ? floatval( $gc['price'] ) : 0;
                            $stock   = isset( $gc['stock'] ) ? intval( $gc['stock'] ) : 0;
                            $enabled = isset( $gc['enabled'] ) ? 1 : 0;
                            if ( $price > 0 && $stock >= 0 ) {
                                $gift_cards_clean[] = [
                                    'price'   => $price,
                                    'stock'   => $stock,
                                    'enabled' => $enabled,
                                ];
                            }
                        }
                    }
                    update_option( 'ssc_gift_cards_enabled', isset( $_POST['ssc_enable_gift_cards'] ) ? 1 : 0 );
                    update_option( 'ssc_gift_cards', $gift_cards_clean );

                    echo '<div class="updated"><p>Settings saved.</p></div>';
                    }
                }
                
                // Retrieve saved settings.
                $order_admin_email = get_option( 'ssc_order_admin_email', get_option( 'admin_email' ) );
                $store_hours       = maybe_unserialize( get_option( 'ssc_store_hours', [] ) );
                $enable_pickup     = get_option( 'ssc_enable_pickup_options', 1 );
                $global_orders_disabled = get_option( 'ssc_global_orders_disabled', 0 );
                $gift_cards_enabled = get_option( 'ssc_gift_cards_enabled', 0 );
                $gift_cards = get_option( 'ssc_gift_cards', [] );
                
                // Retrieve pickup types from the database.
                $pickup_table = $wpdb->prefix . 'flw_pickup_types';
                $pickup_types = $wpdb->get_results("SELECT * FROM $pickup_table", ARRAY_A);
                if ( is_array($pickup_types) ) {
                    foreach($pickup_types as &$pt) {
                        $blocks = json_decode($pt['time_blocks'], true);
                        if ( is_array($blocks) ) {
                            foreach ($blocks as $day => $arr) {
                                $blocks[$day] = implode(', ', $arr);
                            }
                        } else {
                            $blocks = array();
                        }
                        $pt['time_blocks'] = $blocks;
                    }
                    unset($pt);
                }
                if ( empty( $pickup_types ) ) {
                    $pickup_types[] = array(
                        'id' => '',
                        'name' => '',
                        'min_lead_time' => 0,
                        'time_blocks' => array(
                            'sunday'    => '',
                            'monday'    => '',
                            'tuesday'   => '',
                            'wednesday' => '',
                            'thursday'  => '',
                            'friday'    => '',
                            'saturday'  => '',
                        ),
                        'created_at' => ''
                    );
                }
                
                $days = array(
                    'sunday'    => 'Sunday',
                    'monday'    => 'Monday',
                    'tuesday'   => 'Tuesday',
                    'wednesday' => 'Wednesday',
                    'thursday'  => 'Thursday',
                    'friday'    => 'Friday',
                    'saturday'  => 'Saturday',
                );
                
                // Generate a nonce for AJAX pickup type removal.
                $remove_nonce = wp_create_nonce('ssc_remove_pickup_type_nonce');
                ?>
                <div class="wrap">
                    <h1>Shopping Cart Settings</h1>
                    <form method="post" action="">
                        <?php wp_nonce_field( 'ssc_settings_save', 'ssc_settings_nonce' ); ?>
                        <!-- Admin Email -->
                        <table class="form-table">
                            <tr>
                                <th scope="row"><label for="order_admin_email">Admin Order Email:</label></th>
                                <td><input type="email" id="order_admin_email" name="order_admin_email" value="<?php echo esc_attr( $order_admin_email ); ?>" required></td>
                            </tr>
                        </table>
                        <hr>
                        <!-- General Checkout Settings -->
                        <h2>General Checkout Settings</h2>
                        <p>
                            <label>
                                <input type="checkbox" name="ssc_enable_pickup_options" value="1" <?php checked( $enable_pickup, 1 ); ?>>
                                Enable Pickup Options on Checkout
                            </label>
                        </p>
                        <!-- Online Order Controls -->
                        <h2>Online Order Controls</h2>
                        <p>
                            <label>
                                <input type="checkbox" name="ssc_global_orders_disabled" value="1" <?php checked( $global_orders_disabled, 1 ); ?>>
                                Disable Online Orders
                            </label>
                        </p>
                        <hr>
                        <!-- Gift Cards -->
                        <h2>Gift Cards</h2>
                        <p>Manage physical gift cards with limited stock. These will render via the <code>[gift_cards]</code> shortcode and can also be added with <code>[add_to_cart]</code> using the standardized name "Gift Card $PRICE".</p>
                        <p>
                            <label>
                                <input type="checkbox" name="ssc_enable_gift_cards" value="1" <?php checked( $gift_cards_enabled, 1 ); ?>>
                                Enable Gift Cards
                            </label>
                        </p>
                        <div id="gift-cards-container" style="margin-bottom:10px; <?php echo $gift_cards_enabled ? '' : 'display:none;'; ?>">
                            <table class="widefat">
                                <thead>
                                    <tr>
                                        <th>Enabled</th>
                                        <th>Denomination (Price)</th>
                                        <th>Total Stock</th>
                                        <th>Actions</th>
                                    </tr>
                                </thead>
                                <tbody id="gift-cards-rows">
                                    <?php if ( is_array( $gift_cards ) && ! empty( $gift_cards ) ) : ?>
                                        <?php foreach ( $gift_cards as $idx => $gc ) :
                                            $gc_price = isset( $gc['price'] ) ? floatval( $gc['price'] ) : 0;
                                            $gc_stock = isset( $gc['stock'] ) ? intval( $gc['stock'] ) : 0;
                                            $gc_enabled = ! empty( $gc['enabled'] );
                                        ?>
                                        <tr>
                                            <td><input type="checkbox" name="gift_cards[<?php echo intval( $idx ); ?>][enabled]" <?php checked( $gc_enabled, true ); ?>></td>
                                            <td><input type="number" step="0.01" min="0" name="gift_cards[<?php echo intval( $idx ); ?>][price]" value="<?php echo esc_attr( $gc_price ); ?>" required></td>
                                            <td><input type="number" min="0" name="gift_cards[<?php echo intval( $idx ); ?>][stock]" value="<?php echo esc_attr( $gc_stock ); ?>" required></td>
                                            <td><button type="button" class="button remove-gift-card">Remove</button></td>
                                        </tr>
                                        <?php endforeach; ?>
                                    <?php else : ?>
                                        <tr>
                                            <td><input type="checkbox" name="gift_cards[0][enabled]" checked></td>
                                            <td><input type="number" step="0.01" min="0" name="gift_cards[0][price]" value="25" required></td>
                                            <td><input type="number" min="0" name="gift_cards[0][stock]" value="10" required></td>
                                            <td><button type="button" class="button remove-gift-card">Remove</button></td>
                                        </tr>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                            <p><button type="button" class="button" id="add-gift-card">Add Denomination</button></p>
                        </div>
                        <hr>
                        <!-- Store Hours -->
                        <h2>Store Hours</h2>
                        <p>Set the open and close times for each day using the browser’s native time picker. (Note: most browsers will display times in 24‑hour format.)</p>
                        <table class="widefat">
                            <thead>
                                <tr>
                                    <th>Day</th>
                                    <th>Open Time</th>
                                    <th>Close Time</th>
                                    <th>Closed</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($days as $day_key => $day_name): 
                                    $open_time  = isset( $store_hours[ $day_key ]['open'] ) ? $store_hours[ $day_key ]['open'] : '';
                                    $close_time = isset( $store_hours[ $day_key ]['close'] ) ? $store_hours[ $day_key ]['close'] : '';
                                    $closed     = isset( $store_hours[ $day_key ]['closed'] ) ? $store_hours[ $day_key ]['closed'] : 0;
                                ?>
                                <tr>
                                    <td><?php echo esc_html( $day_name ); ?></td>
                                    <td><input type="time" name="store_hours[<?php echo esc_attr( $day_key ); ?>][open]" value="<?php echo esc_attr( $open_time ); ?>"></td>
                                    <td><input type="time" name="store_hours[<?php echo esc_attr( $day_key ); ?>][close]" value="<?php echo esc_attr( $close_time ); ?>"></td>
                                    <td>
                                        <label>
                                            <input type="checkbox" name="store_hours[<?php echo esc_attr( $day_key ); ?>][closed]" value="1" <?php checked( $closed, 1 ); ?>>
                                            Closed
                                        </label>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                        <hr>
                        <!-- Pickup Types Management -->
                        <h2>Pickup Types Management</h2>
                        <p>
                            Define the pickup types for your orders. Each pickup type includes:
                            <br>- <strong>Name</strong>
                            <br>- <strong>Minimum Lead Time</strong> (in hours)
                            <br>- <strong>Allowed Time Blocks</strong> for each day (enter one or more time blocks in the format <code>HH:MM-HH:MM</code> separated by commas)
                        </p>
                        <div id="pickup-types-container">
                            <?php
                            $index = 0;
                            foreach ( $pickup_types as $pt ):
                                $pickup_id_attr = !empty($pt['id']) ? ' data-pickup-id="' . esc_attr($pt['id']) . '"' : '';
                                ?>
                                <div class="pickup-type" data-index="<?php echo esc_attr( $index ); ?>"<?php echo $pickup_id_attr; ?> style="border:1px solid #ccc; padding:10px; margin-bottom:10px;">
                                    <h3>Pickup Type <?php echo ($index + 1); ?></h3>
                                    <label>
                                        Name:
                                        <input type="text" name="pickup_types[<?php echo $index; ?>][name]" value="<?php echo esc_attr( $pt['name'] ); ?>" required>
                                    </label>
                                    <br>
                                    <label>
                                        Minimum Lead Time (hours):
                                        <input type="number" name="pickup_types[<?php echo $index; ?>][min_lead_time]" value="<?php echo esc_attr( $pt['min_lead_time'] ); ?>" min="0" required>
                                    </label>
                                    <br>
                                    <table class="widefat">
                                        <thead>
                                            <tr>
                                                <th>Day</th>
                                                <th>Allowed Time Blocks (comma separated)</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php foreach ($days as $day_key => $day_name): 
                                                $tb = isset($pt['time_blocks'][$day_key]) ? $pt['time_blocks'][$day_key] : '';
                                                ?>
                                                <tr>
                                                    <td><?php echo esc_html($day_name); ?></td>
                                                    <td>
                                                        <input type="text" name="pickup_types[<?php echo $index; ?>][time_blocks][<?php echo esc_attr($day_key); ?>]" value="<?php echo esc_attr($tb); ?>" placeholder="e.g., 08:00-10:00">
                                                    </td>
                                                </tr>
                                            <?php endforeach; ?>
                                        </tbody>
                                    </table>
                                    <button type="button" class="remove-pickup-type">Remove This Pickup Type</button>
                                </div>
                                <?php
                                $index++;
                            endforeach;
                            ?>
                        </div>
                        <button type="button" id="add-pickup-type">Add Pickup Type</button>
                        <hr>
                        <?php submit_button( 'Save Settings', 'primary', 'ssc_save_settings' ); ?>
                    </form>
                </div>
                <form method="post" action="">
                    <h2>Upgrade Database Structure</h2>
                    <p>If you have updated the plugin and need to upgrade the database structure, click the button below.</p>
                    <?php wp_nonce_field( 'upgrade_db_action', 'upgrade_db_nonce' ); ?>
                    <?php submit_button( 'Upgrade Database Structure', 'secondary', 'upgrade_db' ); ?>
                </form>
                <script>
                jQuery(document).ready(function($){
                    // Gift cards UI toggles
                    $(document).on('change', 'input[name="ssc_enable_gift_cards"]', function(){
                        if ($(this).is(':checked')) { $('#gift-cards-container').slideDown(); } else { $('#gift-cards-container').slideUp(); }
                    });
                    var gcIndex = $('#gift-cards-rows tr').length;
                    $('#add-gift-card').on('click', function(){
                        var row = '<tr>' +
                            '<td><input type="checkbox" name="gift_cards[' + gcIndex + '][enabled]" checked></td>' +
                            '<td><input type="number" step="0.01" min="0" name="gift_cards[' + gcIndex + '][price]" value="50" required></td>' +
                            '<td><input type="number" min="0" name="gift_cards[' + gcIndex + '][stock]" value="10" required></td>' +
                            '<td><button type="button" class="button remove-gift-card">Remove</button></td>' +
                        '</tr>';
                        $('#gift-cards-rows').append(row);
                        gcIndex++;
                    });
                    $(document).on('click', '.remove-gift-card', function(){
                        $(this).closest('tr').remove();
                    });
                    var pickupIndex = <?php echo $index; ?>;
                    $("#add-pickup-type").click(function(){
                        var newBlock = `
                        <div class="pickup-type" data-index="` + pickupIndex + `" style="border:1px solid #ccc; padding:10px; margin-bottom:10px;">
                            <h3>Pickup Type ` + (pickupIndex + 1) + `</h3>
                            <label>
                                Name: <input type="text" name="pickup_types[` + pickupIndex + `][name]" value="" required>
                            </label>
                            <br>
                            <label>
                                Minimum Lead Time (hours): <input type="number" name="pickup_types[` + pickupIndex + `][min_lead_time]" value="0" min="0" required>
                            </label>
                            <br>
                            <table class="widefat">
                                <thead>
                                    <tr>
                                        <th>Day</th>
                                        <th>Allowed Time Blocks (comma separated)</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <tr>
                                        <td>Sunday</td>
                                        <td><input type="text" name="pickup_types[` + pickupIndex + `][time_blocks][sunday]" placeholder="e.g., 08:00-10:00"></td>
                                    </tr>
                                    <tr>
                                        <td>Monday</td>
                                        <td><input type="text" name="pickup_types[` + pickupIndex + `][time_blocks][monday]" placeholder="e.g., 08:00-10:00"></td>
                                    </tr>
                                    <tr>
                                        <td>Tuesday</td>
                                        <td><input type="text" name="pickup_types[` + pickupIndex + `][time_blocks][tuesday]" placeholder="e.g., 08:00-10:00"></td>
                                    </tr>
                                    <tr>
                                        <td>Wednesday</td>
                                        <td><input type="text" name="pickup_types[` + pickupIndex + `][time_blocks][wednesday]" placeholder="e.g., 08:00-10:00"></td>
                                    </tr>
                                    <tr>
                                        <td>Thursday</td>
                                        <td><input type="text" name="pickup_types[` + pickupIndex + `][time_blocks][thursday]" placeholder="e.g., 08:00-10:00"></td>
                                    </tr>
                                    <tr>
                                        <td>Friday</td>
                                        <td><input type="text" name="pickup_types[` + pickupIndex + `][time_blocks][friday]" placeholder="e.g., 08:00-10:00"></td>
                                    </tr>
                                    <tr>
                                        <td>Saturday</td>
                                        <td><input type="text" name="pickup_types[` + pickupIndex + `][time_blocks][saturday]" placeholder="e.g., 08:00-10:00"></td>
                                    </tr>
                                </tbody>
                            </table>
                            <button type="button" class="remove-pickup-type">Remove This Pickup Type</button>
                        </div>`;
                        $("#pickup-types-container").append(newBlock);
                        pickupIndex++;
                    });
                    
                    $(document).on("click", ".remove-pickup-type", function(){
                        var $block = $(this).closest(".pickup-type");
                        var pickupID = $block.data("pickup-id");
                        if ( pickupID ) {
                            $.ajax({
                                url: sscheckout_params.ajax_url,
                                type: "POST",
                                data: {
                                    action: "ssc_remove_pickup_type",
                                    pickup_id: pickupID,
                                    nonce: "<?php echo $remove_nonce; ?>"
                                },
                                success: function(response) {
                                    if (response.success) {
                                        $block.remove();
                                    } else {
                                        alert("Error: " + response.data);
                                    }
                                },
                                error: function() {
                                    alert("AJAX error while removing pickup type.");
                                }
                            });
                        } else {
                            $block.remove();
                        }
                    });
                });
                </script>
                <?php
            }
            
            public function remove_pickup_type_ajax() {
                if ( ! current_user_can( 'manage_options' ) ) {
                    wp_send_json_error( 'Not allowed' );
                }
                $nonce = isset($_POST['nonce']) ? sanitize_text_field($_POST['nonce']) : '';
                if ( ! wp_verify_nonce( $nonce, 'ssc_remove_pickup_type_nonce' ) ) {
                    wp_send_json_error( 'Security check failed.' );
                }
                $pickup_id = isset($_POST['pickup_id']) ? intval($_POST['pickup_id']) : 0;
                if ( ! $pickup_id ) {
                    wp_send_json_error( 'Invalid pickup type ID.' );
                }
                global $wpdb;
                $table = $wpdb->prefix . 'flw_pickup_types';
                $result = $wpdb->delete( $table, array( 'id' => $pickup_id ) );
                if ( false !== $result ) {
                    wp_send_json_success( 'Pickup type removed successfully.' );
                } else {
                    wp_send_json_error( 'Error removing pickup type.' );
                }
            }
            
            
		}
		new SimpleShoppingCart_Plugin();
	} else {
		add_action( 'admin_notices', function () {
			echo '<div class="notice notice-error"><p>The FLW Plugin Library must be activated for Simple Shopping Cart to work.</p></div>';
		});
	}
});
