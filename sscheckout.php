<?php
/*
Plugin Name: Simple Shopping Cart
Description: A simple shopping cart plugin with Stripe checkout integration.
Version: 1.0.0
Author: Tyson Brooks
Author URI: https://frostlineworks.com
Tested up to: 6.2
*/

// Prevent direct access to this file
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Ensure the FLW Plugin Library is loaded before running the plugin
add_action( 'plugins_loaded', function () {

	// Check if the FLW Plugin Update Checker class exists
	if ( class_exists( 'FLW_Plugin_Update_Checker' ) ) {
		$pluginSlug = basename( dirname( __FILE__ ) ); // Dynamically get the plugin slug

		// Initialize the update checker
		FLW_Plugin_Update_Checker::initialize( __FILE__, $pluginSlug );

		// Replace the update icon
		add_filter( 'site_transient_update_plugins', function ( $transient ) {
			if ( isset( $transient->response ) ) {
				foreach ( $transient->response as $plugin_slug => $plugin_data ) {
					if ( $plugin_slug === plugin_basename( __FILE__ ) ) {
						$icon_url = plugins_url( 'assets/logo-128x128.png', __FILE__ );
						$transient->response[ $plugin_slug ]->icons = [
							'default' => $icon_url,
							'1x'      => $icon_url,
							'2x'      => plugins_url( 'assets/logo-256x256.png', __FILE__ ),
						];
					}
				}
			}
			return $transient;
		} );
	} else {
		// Admin notice for missing FLW Plugin Library
		add_action( 'admin_notices', function () {
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
		} );
	}

	// If the FLW Plugin Library is available, run the plugin code
	if ( class_exists( 'FLW_Plugin_Library' ) ) {

		class SimpleShoppingCart_Plugin {

			/**
			 * Constructor – sets up hooks, shortcodes, AJAX and admin menu.
			 */
			public function __construct() {
				// Activation hook to create required tables.
				register_activation_hook( __FILE__, [ __CLASS__, 'activate' ] );

				// Check on every load if the tables exist.
				add_action( 'init', [ $this, 'maybe_create_tables' ] );

				// Register our shortcodes.
				add_action( 'init', [ $this, 'register_shortcodes' ] );

				// Enqueue JavaScript and CSS.
				add_action( 'wp_enqueue_scripts', [ $this, 'enqueue_scripts' ] );

				// AJAX actions for updating cart and processing checkout.
				add_action( 'wp_ajax_ssc_update_cart', [ $this, 'update_cart' ] );
				add_action( 'wp_ajax_nopriv_ssc_update_cart', [ $this, 'update_cart' ] );
				add_action( 'wp_ajax_ssc_checkout', [ $this, 'process_checkout' ] );
				add_action( 'wp_ajax_nopriv_ssc_checkout', [ $this, 'process_checkout' ] );

				// Register admin settings submenu.
				add_action( 'admin_menu', [ $this, 'register_submenu' ] );
			}

			/**
			 * Plugin activation callback to create required tables.
			 */
			public static function activate() {
				global $wpdb;
				require_once( ABSPATH . 'wp-admin/includes/upgrade.php' );
				$charset_collate = $wpdb->get_charset_collate();

				// Table for shopping cart items.
				$table1 = $wpdb->prefix . 'flw_shopping_cart';
				$sql1   = "CREATE TABLE $table1 (
					id mediumint(9) NOT NULL AUTO_INCREMENT,
					uid varchar(100) NOT NULL,
					product_name varchar(255) NOT NULL,
					product_price decimal(10,2) NOT NULL,
					quantity int NOT NULL DEFAULT 1,
					added_at datetime DEFAULT CURRENT_TIMESTAMP,
					PRIMARY KEY (id)
				) $charset_collate;";

				// Table for order history.
				$table2 = $wpdb->prefix . 'flw_order_history';
				$sql2   = "CREATE TABLE $table2 (
					id mediumint(9) NOT NULL AUTO_INCREMENT,
					uid varchar(100) NOT NULL,
					order_id varchar(100) NOT NULL,
					product_name varchar(255) NOT NULL,
					product_price decimal(10,2) NOT NULL,
					quantity int NOT NULL DEFAULT 1,
					purchased_at datetime DEFAULT CURRENT_TIMESTAMP,
					PRIMARY KEY (id)
				) $charset_collate;";

				dbDelta( $sql1 );
				dbDelta( $sql2 );
			}

			/**
			 * Checks if the custom tables exist and creates them if they don't.
			 */
			public function maybe_create_tables() {
				global $wpdb;
				$table1 = $wpdb->prefix . 'flw_shopping_cart';
				$table2 = $wpdb->prefix . 'flw_order_history';

				$exists1 = $wpdb->get_var( $wpdb->prepare( "SHOW TABLES LIKE %s", $table1 ) );
				$exists2 = $wpdb->get_var( $wpdb->prepare( "SHOW TABLES LIKE %s", $table2 ) );

				if ( ! $exists1 || ! $exists2 ) {
					require_once( ABSPATH . 'wp-admin/includes/upgrade.php' );
					$charset_collate = $wpdb->get_charset_collate();

					$sql1 = "CREATE TABLE $table1 (
						id mediumint(9) NOT NULL AUTO_INCREMENT,
						uid varchar(100) NOT NULL,
						product_name varchar(255) NOT NULL,
						product_price decimal(10,2) NOT NULL,
						quantity int NOT NULL DEFAULT 1,
						added_at datetime DEFAULT CURRENT_TIMESTAMP,
						PRIMARY KEY (id)
					) $charset_collate;";

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

					dbDelta( $sql1 );
					dbDelta( $sql2 );
				}
			}

			/**
			 * Register our shortcodes.
			 */
			public function register_shortcodes() {
				add_shortcode( 'add_to_cart', [ $this, 'add_to_cart_shortcode' ] );
				add_shortcode( 'checkout', [ $this, 'checkout_shortcode' ] );
			}

			/**
			 * Enqueue front-end scripts and styles.
			 */
			public function enqueue_scripts() {
				wp_enqueue_script(
					'simple-shopping-cart',
					plugins_url( 'assets/js/simple-shopping-cart.js', __FILE__ ),
					[ 'jquery' ],
					'1.0.0',
					true
				);
				wp_localize_script( 'simple-shopping-cart', 'ssc_ajax', [ 'ajax_url' => admin_url( 'admin-ajax.php' ) ] );
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
				} else {
					if ( isset( $_COOKIE['ssc_uid'] ) ) {
						return sanitize_text_field( wp_unslash( $_COOKIE['ssc_uid'] ) );
					} else {
						$uid = 'guest_' . wp_generate_uuid4();
						setcookie( 'ssc_uid', $uid, time() + ( 3600 * 24 * 30 ), COOKIEPATH, COOKIE_DOMAIN );
						return $uid;
					}
				}
			}

			/**
			 * Renders the [add_to_cart] shortcode.
			 *
			 * Expects attributes: price and name.
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

				// Check if this product is already in the cart.
				$existing = $wpdb->get_row(
					$wpdb->prepare(
						"SELECT * FROM $table WHERE uid = %s AND product_name = %s",
						$uid,
						$atts['name']
					)
				);

				ob_start();
				if ( $existing ) {
					// Show the quantity with minus, plus and remove buttons.
					?>
					<div class="ssc-product" data-product="<?php echo esc_attr( $atts['name'] ); ?>">
						<button class="ssc-minus" data-action="minus">–</button>
						<span class="ssc-quantity"><?php echo intval( $existing->quantity ); ?></span>
						<button class="ssc-plus" data-action="plus">+</button>
						<button class="ssc-remove" data-action="remove">🗑️</button>
					</div>
					<?php
				} else {
					// Show the "Add to Cart" button.
					?>
					<div class="ssc-product" data-product="<?php echo esc_attr( $atts['name'] ); ?>" data-price="<?php echo esc_attr( $atts['price'] ); ?>">
						<button class="ssc-add-to-cart">Add to Cart</button>
					</div>
					<?php
				}
				return ob_get_clean();
			}

			/**
			 * Renders the [checkout] shortcode.
			 *
			 * Displays an itemized table of the cart and a checkout form.
			 */
			public function checkout_shortcode() {
				$uid = $this->get_user_uid();
				global $wpdb;
				$table = $wpdb->prefix . 'flw_shopping_cart';
				$items = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM $table WHERE uid = %s", $uid ) );

				ob_start();
				?>
				<div class="ssc-checkout">
					<h2>Your Cart</h2>
					<?php if ( $items ) : ?>
						<table class="ssc-cart-table">
							<thead>
								<tr>
									<th>Product</th>
									<th>Price</th>
									<th>Quantity</th>
									<th>Actions</th>
								</tr>
							</thead>
							<tbody>
								<?php foreach ( $items as $item ) : ?>
									<tr data-product="<?php echo esc_attr( $item->product_name ); ?>">
										<td><?php echo esc_html( $item->product_name ); ?></td>
										<td><?php echo esc_html( $item->product_price ); ?></td>
										<td class="ssc-item-quantity"><?php echo intval( $item->quantity ); ?></td>
										<td>
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
					<form id="ssc-checkout-form">
						<label>Name: <input type="text" name="name" required></label><br>
						<?php if ( ! is_user_logged_in() ) : ?>
							<label>Email: <input type="email" name="email" required></label><br>
							<label>Password: <input type="password" name="password" required></label><br>
						<?php endif; ?>
						<label>Phone: <input type="text" name="phone"></label><br>
						<h3>Payment Details</h3>
						<label>Credit Card Number: <input type="text" name="cc_number" required></label><br>
						<label>CVV: <input type="text" name="cc_cvc" required></label><br>
						<label>Expiration (MM/YY): <input type="text" name="cc_exp" required></label><br>
						<label>Zip: <input type="text" name="cc_zip" required></label><br>
						<input type="hidden" name="action" value="ssc_checkout">
						<button type="submit">Submit Payment</button>
					</form>
					<div id="ssc-checkout-response"></div>
				</div>
				<?php
				return ob_get_clean();
			}

			/**
			 * AJAX handler to update the shopping cart.
			 *
			 * Expected POST parameters:
			 * - product: product name
			 * - action_type: one of "add", "plus", "minus", "remove"
			 * - (if adding) price: product price
			 */
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

				// Handle adding a new item.
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

				// Handle update actions for existing items.
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

			/**
			 * AJAX handler to process checkout.
			 *
			 * Validates form data, calculates the total,
			 * creates a Stripe card token and charge via cURL,
			 * creates a WP user (if needed), sends an order email,
			 * moves cart items to order history, and clears the cart.
			 */
			public function process_checkout() {
				$name      = sanitize_text_field( wp_unslash( $_POST['name'] ) );
				$email     = isset( $_POST['email'] ) ? sanitize_email( wp_unslash( $_POST['email'] ) ) : '';
				$password  = isset( $_POST['password'] ) ? wp_unslash( $_POST['password'] ) : '';
				$phone     = sanitize_text_field( wp_unslash( $_POST['phone'] ) );
				$cc_number = sanitize_text_field( wp_unslash( $_POST['cc_number'] ) );
				$cc_cvc    = sanitize_text_field( wp_unslash( $_POST['cc_cvc'] ) );
				$cc_exp    = sanitize_text_field( wp_unslash( $_POST['cc_exp'] ) );
				$cc_zip    = sanitize_text_field( wp_unslash( $_POST['cc_zip'] ) );
				$uid       = $this->get_user_uid();

				global $wpdb;
				$cart_table  = $wpdb->prefix . 'flw_shopping_cart';
				$order_table = $wpdb->prefix . 'flw_order_history';
				$items       = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM $cart_table WHERE uid = %s", $uid ) );

				if ( ! $items ) {
					wp_send_json_error( 'Cart is empty' );
				}

				// Calculate the total amount (assumes product_price is in dollars).
				$total  = 0;
				foreach ( $items as $item ) {
					$total += floatval( $item->product_price ) * intval( $item->quantity );
				}
				$amount = intval( $total * 100 ); // Convert to cents.

				// Retrieve Stripe secret key (assumes it is stored as an option by another plugin).
				$stripe_secret = get_option( 'flw_stripe_secret_key' );
				if ( ! $stripe_secret ) {
					wp_send_json_error( 'Stripe secret key not found' );
				}

				// --- Create a card token via Stripe API ---
				$ch = curl_init( 'https://api.stripe.com/v1/tokens' );
				$exp = explode( '/', $cc_exp );
				if ( count( $exp ) !== 2 ) {
					wp_send_json_error( 'Invalid expiration date' );
				}
				$exp_month = trim( $exp[0] );
				$exp_year  = trim( $exp[1] );
				$card_data = http_build_query( [
					'card[number]'      => $cc_number,
					'card[cvc]'         => $cc_cvc,
					'card[exp_month]'   => $exp_month,
					'card[exp_year]'    => $exp_year,
					'card[address_zip]' => $cc_zip,
				] );
				curl_setopt( $ch, CURLOPT_USERPWD, $stripe_secret . ':' );
				curl_setopt( $ch, CURLOPT_POST, true );
				curl_setopt( $ch, CURLOPT_POSTFIELDS, $card_data );
				curl_setopt( $ch, CURLOPT_RETURNTRANSFER, true );
				$token_response = curl_exec( $ch );
				$token_result   = json_decode( $token_response, true );
				if ( isset( $token_result['error'] ) ) {
					wp_send_json_error( 'Stripe token error: ' . $token_result['error']['message'] );
				}
				$token_id = $token_result['id'];
				curl_close( $ch );

				// --- Create a charge via Stripe API ---
				$ch = curl_init( 'https://api.stripe.com/v1/charges' );
				$charge_data = http_build_query( [
					'amount'      => $amount,
					'currency'    => 'usd',
					'source'      => $token_id,
					'description' => 'Charge for ' . $name,
				] );
				curl_setopt( $ch, CURLOPT_USERPWD, $stripe_secret . ':' );
				curl_setopt( $ch, CURLOPT_POST, true );
				curl_setopt( $ch, CURLOPT_POSTFIELDS, $charge_data );
				curl_setopt( $ch, CURLOPT_RETURNTRANSFER, true );
				$charge_response = curl_exec( $ch );
				$charge_result   = json_decode( $charge_response, true );
				curl_close( $ch );

				if ( isset( $charge_result['error'] ) ) {
					wp_send_json_error( 'Stripe charge error: ' . $charge_result['error']['message'] );
				}

				// --- If not logged in, create a new WP user using the provided email ---
				if ( ! is_user_logged_in() ) {
					if ( email_exists( $email ) === false ) {
						$user_id = wp_create_user( $email, $password, $email );
						if ( is_wp_error( $user_id ) ) {
							wp_send_json_error( 'User creation failed' );
						}
					}
				}

				// --- Send order email to admin ---
				$admin_email = get_option( 'ssc_order_admin_email' );
				if ( ! $admin_email ) {
					$admin_email = get_option( 'admin_email' );
				}
				$order_id = 'ORDER-' . time();
				$subject  = 'New Order Received: ' . $order_id;
				$message  = "Order Details:\n";
				foreach ( $items as $item ) {
					$message .= $item->product_name . ' x ' . $item->quantity . ' - $' . $item->product_price . "\n";
				}
				wp_mail( $admin_email, $subject, $message );

				// --- Move cart items to order history ---
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

				// --- Clear the shopping cart for this user ---
				$wpdb->delete( $cart_table, [ 'uid' => $uid ] );

				wp_send_json_success( 'Payment successful and order processed.' );
			}

			/**
			 * Register a settings submenu under FLW Plugins.
			 */
			public function register_submenu() {
				FLW_Plugin_Library::add_submenu(
					'Shopping Cart Settings', // Title
					'simple-shopping-cart',   // Slug
					[ $this, 'render_settings_page' ] // Callback
				);
			}

			/**
			 * Render the plugin settings page.
			 */
			public function render_settings_page() {
				if ( isset( $_POST['ssc_save_settings'] ) ) {
					update_option( 'ssc_order_admin_email', sanitize_email( wp_unslash( $_POST['order_admin_email'] ) ) );
					echo '<div class="updated"><p>Settings saved.</p></div>';
				}
				$order_admin_email = get_option( 'ssc_order_admin_email', get_option( 'admin_email' ) );
				?>
				<div class="wrap">
					<h1>Shopping Cart Settings</h1>
					<form method="post" action="">
						<label>Admin Order Email: <input type="email" name="order_admin_email" value="<?php echo esc_attr( $order_admin_email ); ?>" required></label>
						<?php submit_button( 'Save Settings', 'primary', 'ssc_save_settings' ); ?>
					</form>
				</div>
				<?php
			}
		}

		// Initialize the plugin.
		new SimpleShoppingCart_Plugin();

	} else {
		// Show an admin notice if the FLW Plugin Library is not active.
		add_action( 'admin_notices', function () {
			echo '<div class="notice notice-error"><p>The FLW Plugin Library must be activated for Simple Shopping Cart to work.</p></div>';
		} );
	}
} );
