<?php
/*
Plugin Name: Simple Shopping Cart
Description: A simple shopping cart plugin with Stripe checkout integration.
Version: 1.2.6
Author: Tyson Brooks
Author URI: https://frostlineworks.com
Tested up to: 6.2
*/

// Prevent direct access to this file.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

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
				add_action( 'wp_ajax_ssc_update_cart', [ $this, 'update_cart' ] );
				add_action( 'wp_ajax_nopriv_ssc_update_cart', [ $this, 'update_cart' ] );
				add_action( 'wp_ajax_ssc_checkout', [ $this, 'process_checkout' ] );
				add_action( 'wp_ajax_nopriv_ssc_checkout', [ $this, 'process_checkout' ] );
				add_action( 'admin_menu', [ $this, 'register_submenu' ] );
				add_action( 'init', function() {
					if ( ! is_user_logged_in() && ! isset( $_COOKIE['ssc_uid'] ) ) {
						$uid = 'guest_' . wp_generate_uuid4();
						setcookie( 'ssc_uid', $uid, time() + ( 3600 * 24 * 30 ), COOKIEPATH, COOKIE_DOMAIN );
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
					PRIMARY KEY (id)
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
					PRIMARY KEY (id)
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
                    '1.1.7.2',
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
                    'pickup_types'   => $pickup_types
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
					<div class="ssc-product" data-product="<?php echo esc_attr( $atts['name'] ); ?>" data-price="<?php echo esc_attr( $atts['price'] ); ?>">
						<button class="ssc-add-to-cart">Add to Cart</button>
					</div>
					<?php
				}
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
                                <?php foreach ( $items as $item ) : ?>
                                    <tr data-product="<?php echo esc_attr( $item->product_name ); ?>">
                                        <td><?php echo esc_html( $item->product_name ); ?></td>
                                        <td class="ssc-item-price">$<?php echo esc_html( $item->product_price ); ?></td>
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
				if ( empty( $_POST['product'] ) || empty( $_POST['action_type'] ) ) {
					wp_send_json_error( 'Missing parameters' );
				}
				$product    = sanitize_text_field( wp_unslash( $_POST['product'] ) );
				$actionType = sanitize_text_field( wp_unslash( $_POST['action_type'] ) );
				$uid        = $this->get_user_uid();

				global $wpdb;
				$table = $wpdb->prefix . 'flw_shopping_cart';
				$item  = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM $table WHERE uid = %s AND product_name = %s", $uid, $product ) );

				if ( 'add' === $actionType ) {
					if ( $item ) {
						$new_quantity = $item->quantity + 1;
						$wpdb->update( $table, [ 'quantity' => $new_quantity ], [ 'id' => $item->id ] );
						wp_send_json_success( [ 'quantity' => $new_quantity ] );
					} else {
						$price = isset( $_POST['price'] ) ? floatval( wp_unslash( $_POST['price'] ) ) : 0;
						$wpdb->insert( $table, [
							'uid'           => $uid,
							'product_name'  => $product,
							'product_price' => $price,
							'quantity'      => 1,
							'added_at'      => current_time( 'mysql' )
						] );
						wp_send_json_success( [ 'quantity' => 1 ] );
					}
				}

				if ( ! $item ) {
					wp_send_json_error( 'Item not found' );
				}

				if ( 'plus' === $actionType ) {
					$new_quantity = $item->quantity + 1;
				} elseif ( 'minus' === $actionType ) {
					$new_quantity = ( $item->quantity > 1 ) ? $item->quantity - 1 : 1;
				} elseif ( 'remove' === $actionType ) {
					$wpdb->delete( $table, [ 'id' => $item->id ] );
					wp_send_json_success( [ 'quantity' => 0 ] );
				} else {
					wp_send_json_error( 'Invalid action' );
				}

				$wpdb->update( $table, [ 'quantity' => $new_quantity ], [ 'id' => $item->id ] );
				wp_send_json_success( [ 'quantity' => $new_quantity ] );
			}

			public function process_checkout() {
                $name          = sanitize_text_field( wp_unslash( $_POST['name'] ) );
                $email         = isset( $_POST['email'] ) ? sanitize_email( wp_unslash( $_POST['email'] ) ) : '';
                $password      = isset( $_POST['password'] ) ? wp_unslash( $_POST['password'] ) : '';
                $phone         = sanitize_text_field( wp_unslash( $_POST['phone'] ) );
                $paymentMethod = sanitize_text_field( wp_unslash( $_POST['paymentMethod'] ) );

                $enable_pickup = get_option( 'ssc_enable_pickup_options', 1 );
                if ( $enable_pickup ) {
                    $pickup_type = sanitize_text_field( wp_unslash( $_POST['pickup_type'] ) );
                    $pickup_date = sanitize_text_field( wp_unslash( $_POST['pickup_date'] ) );
                    $pickup_time = sanitize_text_field( wp_unslash( $_POST['pickup_time'] ) );
                    $pickup_datetime_str = $pickup_date . ' ' . $pickup_time;
                    $pickup_datetime = DateTime::createFromFormat( 'Y-m-d H:i', $pickup_datetime_str );
                    if ( ! $pickup_datetime ) {
                        wp_send_json_error( 'Invalid pickup date/time format. Please use the provided date and time pickers.' );
                    }
                } else {
                    $pickup_type = '';
                    $pickup_time = '';
                }

                $uid = $this->get_user_uid();

                global $wpdb;
                $cart_table  = $wpdb->prefix . 'flw_shopping_cart';
                $order_table = $wpdb->prefix . 'flw_order_history';
                $items       = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM $cart_table WHERE uid = %s", $uid ) );

                if ( ! $items ) {
                    wp_send_json_error( 'Cart is empty' );
                }

                if ( get_option( 'ssc_global_orders_disabled', 0 ) ) {
                    wp_send_json_error( 'Online orders are currently disabled.' );
                }

                if ( $enable_pickup ) {
                    $closed_days       = array_map( 'intval', (array) get_option( 'ssc_closed_days', [] ) );
                    $after_hours_start = get_option( 'ssc_after_hours_start', '18:00' );
                    $after_hours_end   = get_option( 'ssc_after_hours_end', '08:00' );

                    if ( in_array( intval( $pickup_datetime->format( 'N' ) ), $closed_days, true ) ) {
                        wp_send_json_error( 'The selected day is closed for orders.' );
                    }

                    global $wpdb;
                    $pickup_table = $wpdb->prefix . 'flw_pickup_types';
                    $pickup_types_db = $wpdb->get_results("SELECT * FROM $pickup_table", ARRAY_A);
                    $selected_pt = null;
                    if ( is_array($pickup_types_db) ) {
                        foreach ($pickup_types_db as $pt) {
                            if ( isset($pt['name']) && $pt['name'] === $pickup_type ) {
                                $selected_pt = $pt;
                                break;
                            }
                        }
                    }
                    if ( ! $selected_pt ) {
                        wp_send_json_error( 'Invalid pickup type selected.' );
                    }
                    $min_lead_time_hours = isset($selected_pt['min_lead_time']) ? intval($selected_pt['min_lead_time']) : 0;
                    $allowed_time_blocks = isset($selected_pt['time_blocks']) ? wp_json_decode($selected_pt['time_blocks'], true) : [];
                    
                    $current_time = new DateTime();
                    $min_allowed_time = clone $current_time;
                    $min_allowed_time->add(new DateInterval('PT' . $min_lead_time_hours . 'H'));
                    if ( $pickup_datetime < $min_allowed_time ) {
                        wp_send_json_error( 'Pickup time must be at least ' . $min_lead_time_hours . ' hours from now.' );
                    }
                    
                    $day_of_week = $pickup_datetime->format('D');
                    if ( isset($allowed_time_blocks[$day_of_week]) && is_array($allowed_time_blocks[$day_of_week]) ) {
                        $is_valid_time = false;
                        foreach ( $allowed_time_blocks[$day_of_week] as $time_range ) {
                            list( $start, $end ) = explode('-', $time_range );
                            $pickup_time_only = $pickup_datetime->format('H:i');
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

                $total = 0;
                foreach ( $items as $item ) {
                    $total += floatval( $item->product_price ) * intval( $item->quantity );
                }
                $amount = intval( $total * 100 );

                $stripe_secret = get_option( 'flw_stripe_secret_key' );
                if ( ! $stripe_secret ) {
                    wp_send_json_error( 'Stripe secret key not found' );
                }

                $order_id = 'ORDER-' . time();

                $ch = curl_init( 'https://api.stripe.com/v1/payment_intents' );
                $return_url = site_url( '/checkout' );
                $intent_data = http_build_query( [
                    'amount'              => $amount,
                    'currency'            => 'usd',
                    'payment_method'      => $paymentMethod,
                    'confirmation_method' => 'automatic',
                    'confirm'             => 'true',
                    'return_url'          => $return_url,
                    'description'         => 'Charge for ' . $name,
                    'metadata[order_id]'  => $order_id,
                ] );
                curl_setopt( $ch, CURLOPT_USERPWD, $stripe_secret . ':' );
                curl_setopt( $ch, CURLOPT_POST, true );
                curl_setopt( $ch, CURLOPT_POSTFIELDS, $intent_data );
                curl_setopt( $ch, CURLOPT_RETURNTRANSFER, true );
                $intent_response = curl_exec( $ch );
                $intent_result   = json_decode( $intent_response, true );
                curl_close( $ch );

                if ( isset( $intent_result['error'] ) ) {
                    wp_send_json_error( 'Stripe PaymentIntent error: ' . $intent_result['error']['message'] );
                }

                if ( ! is_user_logged_in() ) {
                    if ( email_exists( $email ) === false ) {
                        $user_id = wp_create_user( $email, $password, $email );
                        if ( is_wp_error( $user_id ) ) {
                            wp_send_json_error( 'User creation failed' );
                        }
                    }
                }

                $admin_email = get_option( 'ssc_order_admin_email' );
                if ( ! $admin_email ) {
                    $admin_email = get_option( 'admin_email' );
                }
                $subject = 'New Order Received: ' . $order_id;
                $message  = "New Order Received\n\n";
                $message .= "Customer Details:\n";
                $message .= "Name: " . $name . "\n";
                if ( ! empty( $email ) ) {
                    $message .= "Email: " . $email . "\n";
                }
                if ( ! empty( $phone ) ) {
                    $message .= "Phone: " . $phone . "\n";
                }
                if ( $enable_pickup ) {
                    $message .= "Pickup Type: " . $pickup_type . "\n";
                    $message .= "Pickup Time: " . $pickup_date . " " . $pickup_time . "\n";
                }
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
                        'purchased_at'  => current_time( 'mysql' )
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
			}

			public function render_settings_page() {
                if ( isset( $_POST['ssc_save_settings'] ) ) {
                    update_option( 'ssc_order_admin_email', sanitize_email( wp_unslash( $_POST['order_admin_email'] ) ) );
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
                    if ( isset($_POST['pickup_types']) && is_array($_POST['pickup_types']) ) {
                        global $wpdb;
                        $pickup_table = $wpdb->prefix . 'flw_pickup_types';
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
                    $enable_pickup = isset( $_POST['ssc_enable_pickup_options'] ) ? 1 : 0;
                    update_option( 'ssc_enable_pickup_options', $enable_pickup );
                    echo '<div class="updated"><p>Settings saved.</p></div>';
                }
                $order_admin_email = get_option( 'ssc_order_admin_email', get_option( 'admin_email' ) );
                $store_hours = maybe_unserialize( get_option( 'ssc_store_hours', [] ) );
                global $wpdb;
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
                $days = array(
                    'sunday'    => 'Sunday',
                    'monday'    => 'Monday',
                    'tuesday'   => 'Tuesday',
                    'wednesday' => 'Wednesday',
                    'thursday'  => 'Thursday',
                    'friday'    => 'Friday',
                    'saturday'  => 'Saturday',
                );
                ?>
                <div class="wrap">
                    <h1>Shopping Cart Settings</h1>
                    <form method="post" action="">
                        <table class="form-table">
                            <tr>
                                <th scope="row">
                                    <label for="order_admin_email">Admin Order Email:</label>
                                </th>
                                <td>
                                    <input type="email" id="order_admin_email" name="order_admin_email" value="<?php echo esc_attr( $order_admin_email ); ?>" required>
                                </td>
                            </tr>
                        </table>
                        <hr>
                        <h2>General Checkout Settings</h2>
                        <p>
                            <label>
                                <input type="checkbox" name="ssc_enable_pickup_options" value="1" <?php checked( $enable_pickup, 1 ); ?>>
                                Enable Pickup Options on Checkout
                            </label>
                        </p>
                        <hr>
                        <h2>Store Hours</h2>
                        <p>
                            Set the open and close times for each day using the browser’s native time picker.
                            (Note: most browsers will display times in 24‑hour format.)
                        </p>
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
                                <?php foreach ( $days as $day_key => $day_name ) : 
                                    $open_time  = isset( $store_hours[ $day_key ]['open'] ) ? $store_hours[ $day_key ]['open'] : '';
                                    $close_time = isset( $store_hours[ $day_key ]['close'] ) ? $store_hours[ $day_key ]['close'] : '';
                                    $closed     = isset( $store_hours[ $day_key ]['closed'] ) ? $store_hours[ $day_key ]['closed'] : 0;
                                ?>
                                <tr>
                                    <td><?php echo esc_html( $day_name ); ?></td>
                                    <td>
                                        <input type="time" name="store_hours[<?php echo esc_attr( $day_key ); ?>][open]" value="<?php echo esc_attr( $open_time ); ?>">
                                    </td>
                                    <td>
                                        <input type="time" name="store_hours[<?php echo esc_attr( $day_key ); ?>][close]" value="<?php echo esc_attr( $close_time ); ?>">
                                    </td>
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
                        <h2>Pickup Types Management</h2>
                        <p>
                            Define the pickup types for your orders. Each pickup type includes:
                            <br>
                            - <strong>Name</strong>
                            <br>
                            - <strong>Minimum Lead Time</strong> (in hours)
                            <br>
                            - <strong>Allowed Time Blocks</strong> for each day (enter one or more time blocks in the format <code>HH:MM-HH:MM</code>, separated by commas)
                        </p>
                        <div id="pickup-types-container">
                            <?php
                            $index = 0;
                            foreach ( $pickup_types as $pt ):
                            ?>
                            <div class="pickup-type" data-index="<?php echo esc_attr( $index ); ?>" style="border:1px solid #ccc; padding:10px; margin-bottom:10px;">
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
                                        <?php foreach ( $days as $day_key => $day_name ): 
                                            $tb = '';
                                            if ( isset( $pt['time_blocks'][ $day_key ] ) ) {
                                                if ( is_array( $pt['time_blocks'][ $day_key ] ) ) {
                                                    $tb = implode( ',', $pt['time_blocks'][ $day_key ] );
                                                } else {
                                                    $tb = $pt['time_blocks'][ $day_key ];
                                                }
                                            }
                                        ?>
                                        <tr>
                                            <td><?php echo esc_html( $day_name ); ?></td>
                                            <td>
                                                <input type="text" name="pickup_types[<?php echo $index; ?>][time_blocks][<?php echo esc_attr( $day_key ); ?>]" value="<?php echo esc_attr( $tb ); ?>" placeholder="e.g., 08:00-10:00">
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
                        $(this).closest(".pickup-type").remove();
                    });
                });
                </script>
                <?php
			}
		}

		new SimpleShoppingCart_Plugin();

	} else {
		add_action( 'admin_notices', function () {
			echo '<div class="notice notice-error"><p>The FLW Plugin Library must be activated for Simple Shopping Cart to work.</p></div>';
		});
	}
});
