<?php
/*
Plugin Name: Reportana
Description: Reportana is a complete solution for e-commerce and digital businesses that want to increase their sales, optimize communication with their customers and manage their operations efficiently. With our platform, you can automate messages via email, WhatsApp, SMS and phone calls, as well as offer automatic support by creating your own chatbot and monitor the main metrics of your business.
Version: 1.3
Author: Reportana
Author URI: https://reportana.com/
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html
Text Domain: reportana
*/

if ( ! defined( 'ABSPATH' ) ) exit; // Exit if accessed directly

if ( ! in_array( 'woocommerce/woocommerce.php', apply_filters( 'active_plugins', get_option( 'active_plugins' ) ) ) ) {
    exit;
}

// Function to create tables
function reportana_create_tables() {
    global $wpdb;

    $table_name = $wpdb->prefix . 'reportana_settings';

    // Table characteristics
    $charset_collate = $wpdb->get_charset_collate();

    // Table reportana_settings
    $table_name_settings = $wpdb->prefix . 'reportana_settings';
    $sql_settings = "CREATE TABLE $table_name_settings (
        id BIGINT(20) NOT NULL AUTO_INCREMENT,
        client_id VARCHAR(255) NOT NULL,
        client_secret VARCHAR(255) NOT NULL,
        shop_id VARCHAR(255) NOT NULL,
        shop_token VARCHAR(255) NOT NULL,
        consumer_key VARCHAR(255) NOT NULL,
        consumer_secret VARCHAR(255) NOT NULL,
        truncated_key VARCHAR(255) NOT NULL,
        PRIMARY KEY (id)
    ) $charset_collate;";

    // Table reportana_abandoned_checkouts
    $table_name_abandoned_checkouts = $wpdb->prefix . 'reportana_abandoned_checkouts';
    $sql_abandoned_checkouts = "CREATE TABLE $table_name_abandoned_checkouts (
        id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
        cart_token VARCHAR(255) NOT NULL,
        cart_data LONGTEXT NOT NULL,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP NOT NULL,
        PRIMARY KEY  (id),
        UNIQUE KEY cart_token (cart_token)
    ) $charset_collate;";

    require_once( ABSPATH . 'wp-admin/includes/upgrade.php' );

    dbDelta( $sql_settings );
    dbDelta( $sql_abandoned_checkouts );
}

// Register activation hook
register_activation_hook( __FILE__, 'reportana_create_tables' );

// Function to delete tables
function reportana_delete_tables() {
    global $wpdb;

    // Table names
    $table_name_settings = $wpdb->prefix . 'reportana_settings';
    $table_name_abandoned_checkouts = $wpdb->prefix . 'reportana_abandoned_checkouts';
    $table_name_api_keys = $wpdb->prefix . 'woocommerce_api_keys'; // Nome da tabela de chaves da API

    // Execute queries to drop the tables
    $wpdb->query( "DROP TABLE IF EXISTS {$table_name_settings};" );
    $wpdb->query( "DROP TABLE IF EXISTS {$table_name_abandoned_checkouts};" );

    // Remove the API Key from the woocommerce_api_keys table
    $wpdb->delete( $table_name_api_keys, array( 'description' => 'Reportana API Key' ) );
}

// Register uninstallation hook
register_deactivation_hook( __FILE__, 'reportana_delete_tables' );
register_uninstall_hook( __FILE__, 'reportana_delete_tables' );

// Function to create the Rest API Key
function reportana_create_api_key() {
    // Check if the "admin" user exists
    $user = get_user_by( 'login', 'admin' );

    if ( ! $user ) {
        // If the admin user does not exist, get the first administrator
        $user_query = new WP_User_Query( array( 'role' => 'administrator' ) );
        $administrators = $user_query->get_results();
        if ( ! empty( $administrators ) ) {
            $user = $administrators[0];
        }
    }

    // Check if the user was found
    if ( $user ) {
        $user_id = $user->ID;

        // Check if an API Key already exists
        global $wpdb;
        $table_name = $wpdb->prefix . 'woocommerce_api_keys';
        $existing_key = $wpdb->get_row(
            $wpdb->prepare( "SELECT * FROM $table_name WHERE user_id = %d AND description = %s", $user_id, 'Reportana API Key' )
        );

        // Insert the API Key into the WooCommerce API keys table
        if ( ! $existing_key ) {
            // Create the REST API Key with read and write permissions
            $consumer_key    = 'ck_' . wc_rand_hash();
            $consumer_secret = 'cs_' . wc_rand_hash();

            // Prepare the data for WooCommerce API keys
            $api_key_data = array(
                'user_id'         => $user_id,
                'description'     => 'Reportana API Key',
                'permissions'     => 'read_write',
                'consumer_key'    => wc_api_hash( $consumer_key ),
                'consumer_secret' => $consumer_secret,
                'truncated_key'   => substr( $consumer_key, -7 ),
                'last_access'     => null,
                'nonces'          => '',
            );

            $wpdb->insert( $table_name, $api_key_data );

            // Store keys in reportana_settings table
            $table_settings = $wpdb->prefix . 'reportana_settings';
            $data = array(
                'consumer_key'    => $consumer_key,
                'consumer_secret' => $consumer_secret,
                'truncated_key'   => substr( $consumer_key, -7 ),
            );

            // Insert or update the keys in the settings table
            $existing_settings = $wpdb->get_row( "SELECT * FROM $table_settings LIMIT 1" );
            if ( $existing_settings ) {
                // Update record
                $wpdb->update(
                    $table_settings,
                    $data,
                    [ 'id' => $existing_settings->id ]
                );
            } else {
                // Insert new record
                $wpdb->insert(
                    $table_settings,
                    $data
                );
            }

            // Show the created keys in the log
            error_log( 'Consumer Key: ' . $consumer_key );
            error_log( 'Consumer Secret: ' . $consumer_secret );
        }
    } else {
        error_log( 'No administrator user found.' );
    }
}

// Add settings page to menu
function reportana_add_settings_page() {
    add_submenu_page(
        'woocommerce',  // Parent menu (WooCommerce)
        __( 'Reportana', 'reportana' ),  // Page title
        __( 'Reportana', 'reportana' ),  // Menu name
        'manage_options',  // Permissions
        'reportana-settings',  // Page slug
        'reportana_render_settings_page',  // Function that renders the page
        99  // Menu position (the higher the number, the further down)
    );
}
add_action( 'admin_menu', 'reportana_add_settings_page' );

// Add settings link on the Plugins page
function reportana_add_settings_link( $links ) {
    $settings_link = '<a href="admin.php?page=reportana-settings">' . __( 'Settings', 'reportana' ) . '</a>';
    array_unshift( $links, $settings_link );
    return $links;
}
add_filter( 'plugin_action_links_' . plugin_basename(__FILE__), 'reportana_add_settings_link' );

// Render the settings page
function reportana_render_settings_page() {
    global $wpdb;
    $table_name = $wpdb->prefix . 'reportana_settings';

    // Check if the form was submitted
    if ( isset( $_SERVER['REQUEST_METHOD'] ) && $_SERVER['REQUEST_METHOD'] == 'POST' ) {
        // Verify nonce
        check_admin_referer( 'reportana_nonce_action', 'reportana_nonce' );

        // Initialize errors array
        $errors = [];

        // Validate fields
        $client_id     = isset( $_POST['client_id'] ) ? sanitize_text_field( wp_unslash( $_POST['client_id'] ) ) : '';
        $client_secret = isset( $_POST['client_secret'] ) ? sanitize_text_field( wp_unslash( $_POST['client_secret'] ) ) : '';

        if ( empty( $client_id ) ) {
            $errors[] = __( 'Client ID cannot be empty', 'reportana' );
        }

        if ( empty( $client_secret ) ) {
            $errors[] = __( 'Client Secret cannot be empty', 'reportana' );
        }

        // If there are no errors, make the HTTP call
        if ( empty( $errors ) ) {
            // Create basic authentication for the request header
            $auth = base64_encode( $client_id . ':' . $client_secret );

            // Configure the HTTP GET request
            $response = wp_remote_get( 'https://api.reportana.com/2022-05/shop', array(
                'headers' => array(
                    'Authorization' => 'Basic ' . $auth,
                ),
            ) );

            // Check if the response was successful
            if ( is_wp_error( $response ) ) {
                $errors[] = __( 'Failed to connect to Reportana API', 'reportana' );
            } else {
                $response_body = wp_remote_retrieve_body( $response );
                $response_data = json_decode( $response_body, true );

                if ( isset( $response_data['data']['id'] ) && isset( $response_data['data']['token'] ) ) {
                    $shop_id    = sanitize_text_field( $response_data['data']['id'] );
                    $shop_token = sanitize_text_field( $response_data['data']['token'] );

                    // Create the API keys
                    reportana_create_api_key();

                    // Retrieve the consumer keys from reportana_settings
                    $existing_settings = $wpdb->get_row( "SELECT * FROM $table_name LIMIT 1" );

                    if ( $existing_settings ) {
                        $consumer_key    = $existing_settings->consumer_key;
                        $consumer_secret = $existing_settings->consumer_secret;

                        // Get the shop URL
                        $shop_url = parse_url( get_site_url(), PHP_URL_HOST ); // Retrieves only the domain of the WordPress site

                        // Make the POST request to the endpoint
                        $post_data = wp_json_encode( array(
                            'configs' => array(
                                'shop_url'        => $shop_url,
                                'consumer_key'    => $consumer_key,
                                'consumer_secret' => $consumer_secret,
                            ),
                            'is_active' => true,
                        ) );

                        // Make the HTTP POST call
                        $post_response = wp_remote_post( 'https://api.reportana.com/2022-05/modules/woocommerce', array(
                            'method'  => 'POST',
                            'body'    => $post_data,
                            'headers' => array(
                                'Authorization' => 'Basic ' . $auth,
                                'Content-Type'  => 'application/json',
                            ),
                        ) );

                        // Check if the POST request was successful
                        if ( is_wp_error( $post_response ) ) {
                            $errors[] = __( 'Failed to send WooCommerce API Key to Reportana', 'reportana' );
                        }
                    } else {
                        $errors[] = __( 'API Key not found. Please ensure the API Key is created.', 'reportana' );
                    }
                } else {
                    $errors[] = __( 'Invalid response from Reportana API', 'reportana' );
                }
            }
        }

        // If there are no errors, update or create the record
        if ( empty( $errors ) ) {
            $existing_settings = $wpdb->get_row( "SELECT * FROM $table_name LIMIT 1" );

            $data = [
                'client_id'     => $client_id,
                'client_secret' => $client_secret,
                'shop_id'       => isset( $shop_id ) ? $shop_id : null,
                'shop_token'    => isset( $shop_token ) ? $shop_token : null,
            ];

            if ( $existing_settings ) {
                // Update record
                $wpdb->update(
                    $table_name,
                    $data,
                    [ 'id' => $existing_settings->id ]
                );
            } else {
                // Insert new record
                $wpdb->insert(
                    $table_name,
                    $data
                );
            }

            echo '<div class="updated"><p>' . esc_html__( 'Settings saved successfully', 'reportana' ) . '</p></div>';
        } else {
            // Display errors
            foreach ( $errors as $error ) {
                echo '<div class="error"><p>' . esc_html($error) . '</p></div>';
            }
        }
    }

    // Retrieve saved values for display in the form
    $existing_settings = $wpdb->get_row( "SELECT * FROM $table_name LIMIT 1" );
    $client_id         = $existing_settings ? $existing_settings->client_id : '';
    $client_secret     = $existing_settings ? $existing_settings->client_secret : '';

    // Configuration form
    ?>
    <div class="wrap">
        <h2><?php esc_html_e( 'Reportana', 'reportana' ); ?></h2>
        <div class="notice notice-info">
            <h2><?php esc_html_e( 'Installation instructions', 'reportana' ); ?></h2>
            <ol>
                <li><?php _e( 'In your Reportana account, navigate to Settings in the sidebar menu → Gear → Change platform → WooCommerce. Or go to: <a target="_blank" href="https://app.reportana.com/#/settings/platforms/woocommerce">WooCommerce Integration.</a>', 'reportana' ); ?></li>
                <li><?php esc_html_e( 'Copy the Client ID and Client Secret.', 'reportana' ); ?></li>
                <li><?php esc_html_e( 'Go to the Reportana plugin settings and paste the Client ID and Client Secret into the respective fields.', 'reportana' ); ?></li>
                <li><?php esc_html_e( 'Click Save settings.', 'reportana' ); ?></li>
            </ol>
        </div>
        <form method="POST">
            <?php wp_nonce_field( 'reportana_nonce_action', 'reportana_nonce' ); ?> <!-- Add nonce field -->
            <table class="form-table">
                <tr>
                    <th scope="row"><label for="client_id"><?php esc_html_e( 'Client ID', 'reportana' ); ?></label></th>
                    <td><input name="client_id" type="text" id="client_id" value="<?php echo esc_attr( $client_id ); ?>" class="regular-text" /></td>
                </tr>
                <tr>
                    <th scope="row"><label for="client_secret"><?php esc_html_e( 'Client Secret', 'reportana' ); ?></label></th>
                    <td><input name="client_secret" type="text" id="client_secret" value="<?php echo esc_attr( $client_secret ); ?>" class="regular-text" /></td>
                </tr>
            </table>
            <p class="submit">
                <input type="submit" class="button-primary" value="<?php esc_html_e( 'Save settings', 'reportana' ); ?>" />
            </p>
        </form>
    </div>
    <?php
}

// Load the translation file
function reportana_load_textdomain() {
    load_plugin_textdomain( 'reportana', false, dirname( plugin_basename( __FILE__ ) ) . '/languages/' );
}
add_action( 'plugins_loaded', 'reportana_load_textdomain' );

// Function to add the script on all pages
function reportana_add_woocommerce_script() {
    if ( class_exists( 'WooCommerce' ) ) {
        global $wpdb;
        $table_name = $wpdb->prefix . 'reportana_settings';

        // Retrieve shop_id from the reportana_settings table
        $settings = $wpdb->get_row( "SELECT shop_id FROM $table_name LIMIT 1" );
        $shop_id  = $settings ? $settings->shop_id : null;

        if ( $shop_id ) {
            // Add the external script with the shop_id parameter
            $script_url = 'https://app.reportana.com/woocommerce/script.js?shop_id=' . esc_attr( $shop_id );
            wp_enqueue_script( 'reportana-script', $script_url, array(), null, true );

            // Pass the correct admin-ajax.php URL to JavaScript
            wp_localize_script( 'reportana-script', 'rptn_wc_vars', array(
                'ajax_url' => admin_url( 'admin-ajax.php' ),
                'nonce'    => wp_create_nonce( 'reportana_nonce_action' ) // Create the nonce
            ));
        }
    }
}
add_action( 'wp_enqueue_scripts', 'reportana_add_woocommerce_script' );

// Function to save or update abandoned cart
function reportana_save_abandoned_checkout() {
    global $wpdb;

    // Check if the reference_id (cart_token) was passed via Ajax
    if ( empty( $_POST['reference_id'] ) ) {
        wp_die(); // If empty, the function does nothing and exits
    }

    // Verify nonce for Ajax requests
    check_ajax_referer( 'reportana_nonce_action', 'security' );

    // Access the reference_id passed via Ajax
    $cart_token = sanitize_text_field( wp_unslash( $_POST['reference_id'] ) );

    // Access cart data
    $cart = WC()->cart->get_cart();

    // Get additional data via Ajax
    $customer = array(
        'email'                => isset($_POST['email']) ? sanitize_text_field( wp_unslash( $_POST['email'] ) ) : '',
        'shipping_first_name'  => isset($_POST['shipping_first_name']) ? sanitize_text_field( wp_unslash( $_POST['shipping_first_name'] ) ) : '',
        'shipping_last_name'   => isset($_POST['shipping_last_name']) ? sanitize_text_field( wp_unslash( $_POST['shipping_last_name'] ) ) : '',
        'shipping_company'     => isset($_POST['shipping_company']) ? sanitize_text_field( wp_unslash( $_POST['shipping_company'] ) ) : '',
        'shipping_address_1'   => isset($_POST['shipping_address_1']) ? sanitize_text_field( wp_unslash( $_POST['shipping_address_1'] ) ) : '',
        'shipping_address_2'   => isset($_POST['shipping_address_2']) ? sanitize_text_field( wp_unslash( $_POST['shipping_address_2'] ) ) : '',
        'shipping_city'        => isset($_POST['shipping_city']) ? sanitize_text_field( wp_unslash( $_POST['shipping_city'] ) ) : '',
        'shipping_state'       => isset($_POST['shipping_state']) ? sanitize_text_field( wp_unslash( $_POST['shipping_state'] ) ) : '',
        'shipping_postcode'    => isset($_POST['shipping_postcode']) ? sanitize_text_field( wp_unslash( $_POST['shipping_postcode'] ) ) : '',
        'shipping_country'     => isset($_POST['shipping_country']) ? sanitize_text_field( wp_unslash( $_POST['shipping_country'] ) ) : '',
        'shipping_phone'       => isset($_POST['shipping_phone']) ? sanitize_text_field( wp_unslash( $_POST['shipping_phone'] ) ) : '',
        'billing_first_name'   => isset($_POST['billing_first_name']) ? sanitize_text_field( wp_unslash( $_POST['billing_first_name'] ) ) : '',
        'billing_last_name'    => isset($_POST['billing_last_name']) ? sanitize_text_field( wp_unslash( $_POST['billing_last_name'] ) ) : '',
        'billing_company'      => isset($_POST['billing_company']) ? sanitize_text_field( wp_unslash( $_POST['billing_company'] ) ) : '',
        'billing_address_1'    => isset($_POST['billing_address_1']) ? sanitize_text_field( wp_unslash( $_POST['billing_address_1'] ) ) : '',
        'billing_address_2'    => isset($_POST['billing_address_2']) ? sanitize_text_field( wp_unslash( $_POST['billing_address_2'] ) ) : '',
        'billing_city'         => isset($_POST['billing_city']) ? sanitize_text_field( wp_unslash( $_POST['billing_city'] ) ) : '',
        'billing_state'        => isset($_POST['billing_state']) ? sanitize_text_field( wp_unslash( $_POST['billing_state'] ) ) : '',
        'billing_postcode'     => isset($_POST['billing_postcode']) ? sanitize_text_field( wp_unslash( $_POST['billing_postcode'] ) ) : '',
        'billing_country'      => isset($_POST['billing_country']) ? sanitize_text_field( wp_unslash( $_POST['billing_country'] ) ) : '',
        'billing_phone'        => isset($_POST['billing_phone']) ? sanitize_text_field( wp_unslash( $_POST['billing_phone'] ) ) : ''
    );

    // Serialize cart data and user data
    $data = array(
        'cart' => $cart,
        'customer' => $customer
    );

    $cart_data = maybe_serialize( $data );

    // Check if the cart_token already exists in the database
    $table_name = $wpdb->prefix . 'reportana_abandoned_checkouts';
    $existing_cart = $wpdb->get_var( $wpdb->prepare(
        "SELECT cart_token FROM $table_name WHERE cart_token = %s",
        $cart_token
    ));

    // If the cart_token already exists, update the record
    if ( $existing_cart ) {
        $wpdb->update(
            $table_name,
            array(
                'cart_data' => $cart_data,
            ),
            array( 'cart_token' => $cart_token ),
            array( '%s' ),
            array( '%s' )
        );
    } else {
        // Otherwise, create a new record
        $wpdb->insert(
            $table_name,
            array(
                'cart_token'   => $cart_token,
                'cart_data'  => $cart_data,
            ),
            array(
                '%s',
                '%s',
            )
        );
    }

    // Prepare cart items for JSON response
    $line_items = array();
    foreach ( $cart as $cart_item_key => $cart_item ) {
        // Get the product object
        $product = $cart_item['data'];

        // Get the product image URL (thumbnail)
        $thumbnail_url = wp_get_attachment_image_url( $product->get_image_id(), 'thumbnail' );

        // Get the variant title (if applicable)
        $variant_title = '';
        if ( $product->is_type( 'variation' ) ) {
            $attributes = $product->get_attributes();
            foreach ( $attributes as $attribute => $value ) {
                $variant_title .= $attribute . ': ' . $value . ' ';
            }
            $variant_title = trim( $variant_title );
        }

        // Get the product link
        $product_url = get_permalink( $cart_item['product_id'] );

        // Add items to the array
        $line_items[] = array(
            'title'           => $product->get_name(),
            'variant_title'   => $variant_title,
            'quantity'        => $cart_item['quantity'],
            'price'           => $product->get_price(),
            'path'            => $product_url,
            'image_url'       => $thumbnail_url,
            'tracking_number' => '',
        );
    }

    // Get the total cart value (unformatted)
    $cart_total = WC()->cart->get_total(''); // Total value

    // Create the JSON response with cart items and total
    $response = array(
        'line_items'    => $line_items,
        'total_price'    => $cart_total, // Total value added to the response
    );

    // Return the response in JSON
    wp_send_json_success( $response );
}
add_action( 'wp_ajax_reportana_save_abandoned_checkout', 'reportana_save_abandoned_checkout' );
add_action( 'wp_ajax_nopriv_reportana_save_abandoned_checkout', 'reportana_save_abandoned_checkout' );

// Function to retrieve abandoned cart
function reportana_load_abandoned_checkout() {
    if ( isset( $_GET['cart_token'] ) ) {
        global $wpdb;

        // Get the cart identifier from the URL
        $cart_token = sanitize_text_field( wp_unslash( $_GET['cart_token'] ) );

        // Query the cart data in the database
        $cart_data = $wpdb->get_var( $wpdb->prepare(
            "SELECT cart_data FROM {$wpdb->prefix}reportana_abandoned_checkouts WHERE cart_token = %s",
            $cart_token
        ));

        if ( $cart_data ) {
            // Unserialize the cart and user data
            $data = maybe_unserialize( $cart_data );
            $cart = $data['cart'];
            $customer = $data['customer'];

            // Clear the current cart
            WC()->cart->empty_cart();

            // Reintroduce items to the cart
            foreach ( $cart as $cart_item ) {
                // Check if the 'cart_item_data' key exists, otherwise set to an empty array
                $cart_item_data = isset( $cart_item['cart_item_data'] ) ? $cart_item['cart_item_data'] : array();

                // Add the item to the cart
                WC()->cart->add_to_cart(
                    $cart_item['product_id'],
                    $cart_item['quantity'],
                    $cart_item['variation_id'],
                    $cart_item['variation'],
                    $cart_item_data
                );
            }

            // Restore user data in the checkout
            WC()->customer->set_props(array(
                'billing_first_name'  => $customer['billing_first_name'],
                'billing_last_name'   => $customer['billing_last_name'],
                'billing_company'     => $customer['billing_company'],
                'billing_address_1'   => $customer['billing_address_1'],
                'billing_address_2'   => $customer['billing_address_2'],
                'billing_city'        => $customer['billing_city'],
                'billing_state'       => $customer['billing_state'],
                'billing_postcode'    => $customer['billing_postcode'],
                'billing_country'     => $customer['billing_country'],
                'billing_phone'       => $customer['billing_phone'],
                'billing_email'       => $customer['email'],
                'shipping_first_name' => $customer['shipping_first_name'],
                'shipping_last_name'  => $customer['shipping_last_name'],
                'shipping_company'    => $customer['shipping_company'],
                'shipping_address_1'  => $customer['shipping_address_1'],
                'shipping_address_2'  => $customer['shipping_address_2'],
                'shipping_city'       => $customer['shipping_city'],
                'shipping_state'      => $customer['shipping_state'],
                'shipping_postcode'   => $customer['shipping_postcode'],
                'shipping_country'    => $customer['shipping_country']
            ));
        }
    }
}
add_action( 'wp', 'reportana_load_abandoned_checkout' );
