<?php
/*
Plugin Name: TCB Custom Checkout Bridge
Plugin URI: https://github.com/fareed-rifaideen-ecom/tcb-payment-bridge
Description: Handles custom Elementor form checkouts, dynamic promos, and provides an Order Management Dashboard.
Version: 2.0
Author: Fareed M Rifaideen
*/

if ( ! defined( 'ABSPATH' ) ) { exit; }

// ==========================================
// 1. DATABASE SETUP FOR ORDERS
// ==========================================
register_activation_hook( __FILE__, 'tcb_create_local_transaction_table' );
add_action( 'plugins_loaded', 'tcb_create_local_transaction_table' );

function tcb_create_local_transaction_table() {
    global $wpdb;
    $table_name = $wpdb->prefix . 'tcb_transactions';
    
    $charset_collate = $wpdb->get_charset_collate();
    $sql = "CREATE TABLE $table_name (
        id mediumint(9) NOT NULL AUTO_INCREMENT,
        order_ref varchar(50) NOT NULL,
        customer_name varchar(100) NOT NULL,
        customer_email varchar(100) NOT NULL,
        sets int(3) NOT NULL,
        promo_code varchar(50) DEFAULT '' NOT NULL,
        discount_amount decimal(10,2) DEFAULT 0 NOT NULL,
        amount decimal(10,2) NOT NULL,
        ngenius_ref varchar(100) DEFAULT '' NOT NULL,
        status varchar(50) DEFAULT 'Pending' NOT NULL,
        order_date datetime DEFAULT CURRENT_TIMESTAMP NOT NULL,
        PRIMARY KEY  (id)
    ) $charset_collate;";
    require_once( ABSPATH . 'wp-admin/includes/upgrade.php' );
    dbDelta( $sql ); 
}

// ==========================================
// 2. ORDER MANAGEMENT DASHBOARD
// ==========================================
add_action('admin_menu', 'tcb_register_order_dashboard');

function tcb_register_order_dashboard() {
    add_menu_page(
        'Turkey Orders', 
        'Turkey Orders', 
        'manage_woocommerce', // Requires shop manager or admin capabilities
        'tcb-turkey-orders', 
        'tcb_render_order_dashboard', 
        'dashicons-list-view', 
        56
    );
}

function tcb_render_order_dashboard() {
    global $wpdb;
    $table_name = $wpdb->prefix . 'tcb_transactions';
    $results = $wpdb->get_results("SELECT * FROM $table_name ORDER BY order_date DESC LIMIT 200");
    ?>
    <div class="wrap">
        <h1 class="wp-heading-inline">Turkey Takeaway Orders 2026</h1>
        <p>Live transaction logs from the Elementor checkout bridge.</p>
        <table class="wp-list-table widefat fixed striped">
            <thead>
                <tr>
                    <th style="width: 120px;">Date</th>
                    <th>Order Ref</th>
                    <th>Customer</th>
                    <th>Email</th>
                    <th style="width: 60px;">Sets</th>
                    <th>Promo Used</th>
                    <th>Total Paid</th>
                    <th>Gateway Ref</th>
                    <th>Status</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($results)) : ?>
                    <tr><td colspan="9">No orders found.</td></tr>
                <?php else : ?>
                    <?php foreach ($results as $row) : 
                        $status_color = (strpos($row->status, 'Paid') !== false) ? 'color: #176b3b; font-weight: bold;' : ((strpos($row->status, 'Pending') !== false) ? 'color: #b45309;' : 'color: #dc2626;');
                    ?>
                        <tr>
                            <td><?php echo esc_html(date('M d, H:i', strtotime($row->order_date))); ?></td>
                            <td><strong><?php echo esc_html($row->order_ref); ?></strong></td>
                            <td><?php echo esc_html($row->customer_name); ?></td>
                            <td><a href="mailto:<?php echo esc_attr($row->customer_email); ?>"><?php echo esc_html($row->customer_email); ?></a></td>
                            <td><?php echo esc_html($row->sets); ?></td>
                            <td>
                                <?php if($row->promo_code): ?>
                                    <span style="background:#edf7df; color:#176b3b; padding:3px 6px; border-radius:4px; font-size:11px; font-weight:bold;"><?php echo esc_html($row->promo_code); ?></span>
                                <?php else: ?>
                                    -
                                <?php endif; ?>
                            </td>
                            <td><strong>AED <?php echo esc_html($row->amount); ?></strong></td>
                            <td><small><?php echo esc_html($row->ngenius_ref ?: '-'); ?></small></td>
                            <td style="<?php echo $status_color; ?>"><?php echo esc_html($row->status); ?></td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
    <?php
}

// ==========================================
// 3. PROMO CODE MANAGEMENT (CUSTOM POST TYPE)
// ==========================================
add_action('init', 'tcb_register_promo_cpt');

function tcb_register_promo_cpt() {
    register_post_type('tcb_promo', array(
        'labels' => array(
            'name'          => 'Promo Codes',
            'singular_name' => 'Promo Code',
            'add_new'       => 'Add New Promo',
            'add_new_item'  => 'Add New Promo Code',
            'edit_item'     => 'Edit Promo Code',
        ),
        'public'      => false,
        'show_ui'     => true,
        'show_in_menu'=> 'tcb-turkey-orders', // Place it under the Turkey Orders menu
        'supports'    => array('title'), // Title will be the Promo Code itself
    ));
}

// Add Meta Box for Promo Settings
add_action('add_meta_boxes', 'tcb_add_promo_meta_box');
function tcb_add_promo_meta_box() {
    add_meta_box('tcb_promo_details', 'Discount Details', 'tcb_render_promo_meta_box', 'tcb_promo', 'normal', 'high');
}

function tcb_render_promo_meta_box($post) {
    wp_nonce_field('tcb_save_promo_data', 'tcb_promo_nonce');
    $type = get_post_meta($post->ID, '_promo_type', true) ?: 'fixed';
    $value = get_post_meta($post->ID, '_promo_value', true) ?: '';
    ?>
    <p><strong style="color:#dc2626;">Instruction:</strong> Use the Title field above as the actual Promo Code (e.g., VIP2026, STAFF).</p>
    <table class="form-table">
        <tr>
            <th><label for="promo_type">Discount Type</label></th>
            <td>
                <select name="promo_type" id="promo_type">
                    <option value="fixed" <?php selected($type, 'fixed'); ?>>Fixed Amount (AED)</option>
                    <option value="percent" <?php selected($type, 'percent'); ?>>Percentage (%)</option>
                </select>
            </td>
        </tr>
        <tr>
            <th><label for="promo_value">Discount Value</label></th>
            <td>
                <input type="number" step="0.01" name="promo_value" id="promo_value" value="<?php echo esc_attr($value); ?>" class="regular-text" required>
                <p class="description">Enter the number without currency symbols (e.g., 50 for 50 AED or 100 for 100% Free).</p>
            </td>
        </tr>
    </table>
    <?php
}

add_action('save_post', 'tcb_save_promo_meta');
function tcb_save_promo_meta($post_id) {
    if (!isset($_POST['tcb_promo_nonce']) || !wp_verify_nonce($_POST['tcb_promo_nonce'], 'tcb_save_promo_data')) return;
    if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) return;
    if (!current_user_can('edit_post', $post_id)) return;

    if (isset($_POST['promo_type'])) { update_post_meta($post_id, '_promo_type', sanitize_text_field($_POST['promo_type'])); }
    if (isset($_POST['promo_value'])) { update_post_meta($post_id, '_promo_value', floatval($_POST['promo_value'])); }
}

// Fetch Promos Dynamically from the CPT
function tcb_get_valid_promos() {
    $promos = array();
    $query = new WP_Query(array('post_type' => 'tcb_promo', 'posts_per_page' => -1, 'post_status' => 'publish'));
    if ($query->have_posts()) {
        foreach ($query->posts as $p) {
            $type = get_post_meta($p->ID, '_promo_type', true);
            $val = get_post_meta($p->ID, '_promo_value', true);
            // Use the Post Title as the Promo Code, converted to uppercase
            $promos[strtoupper(trim($p->post_title))] = array('type' => $type, 'value' => (float)$val);
        }
    }
    return $promos;
}

// ==========================================
// 4. AJAX: LIVE PROMO VALIDATOR (For Frontend)
// ==========================================
add_action('wp_ajax_nopriv_tcb_validate_promo', 'tcb_ajax_validate_promo');
add_action('wp_ajax_tcb_validate_promo', 'tcb_ajax_validate_promo');

function tcb_ajax_validate_promo() {
    $promo = isset($_POST['promo']) ? strtoupper(sanitize_text_field($_POST['promo'])) : '';
    $sets  = isset($_POST['sets']) ? intval($_POST['sets']) : 0;
    
    if (empty($promo) || $sets < 1) { wp_send_json_error('Invalid request.'); }

    $promos = tcb_get_valid_promos();
    if (isset($promos[$promo])) {
        $base_total = $sets * 600;
        $discount_amt = 0;
        
        if ($promos[$promo]['type'] == 'fixed') {
            $discount_amt = $promos[$promo]['value'];
        } elseif ($promos[$promo]['type'] == 'percent') {
            $discount_amt = $base_total * ($promos[$promo]['value'] / 100);
        }
        
        // Cap discount so total doesn't go below 0
        $discount_amt = min($discount_amt, $base_total);

        wp_send_json_success(array('code' => $promo, 'discount' => round($discount_amt, 2)));
    } else {
        wp_send_json_error('Invalid or expired promo code.');
    }
}

// ==========================================
// 5. AJAX: FINAL PAYMENT PROCESSOR
// ==========================================
add_action('wp_ajax_nopriv_tcb_process_turkey', 'tcb_process_turkey_payment');
add_action('wp_ajax_tcb_process_turkey', 'tcb_process_turkey_payment');

function tcb_process_turkey_payment() {
    global $wpdb;
    
    $first_name = isset($_POST['firstName']) ? sanitize_text_field($_POST['firstName']) : '';
    $last_name  = isset($_POST['lastName']) ? sanitize_text_field($_POST['lastName']) : '';
    $email      = isset($_POST['email']) ? sanitize_email($_POST['email']) : '';
    $sets       = isset($_POST['sets']) ? intval($_POST['sets']) : 0;
    $promo      = isset($_POST['promo']) ? strtoupper(sanitize_text_field($_POST['promo'])) : '';
    
    if( empty($first_name) || empty($email) || $sets < 1 ) {
        wp_send_json_error('Missing required fields.');
    }

    $base_total = $sets * 600;
    $discount_amt = 0;
    $promos = tcb_get_valid_promos();
    
    if (!empty($promo) && isset($promos[$promo])) {
        if ($promos[$promo]['type'] == 'fixed') {
            $discount_amt = $promos[$promo]['value'];
        } elseif ($promos[$promo]['type'] == 'percent') {
            $discount_amt = $base_total * ($promos[$promo]['value'] / 100);
        }
    }
    
    $final_amount = max(0, $base_total - $discount_amt);
    $local_order_id = 'TCB-' . strtoupper(uniqid());
    $table_name = $wpdb->prefix . 'tcb_transactions';

    $wpdb->insert($table_name, array(
        'order_ref'      => $local_order_id,
        'customer_name'  => trim($first_name . ' ' . $last_name),
        'customer_email' => $email,
        'sets'           => $sets,
        'promo_code'     => $promo,
        'discount_amount'=> $discount_amt,
        'amount'         => $final_amount,
        'status'         => 'Pending'
    ));

    if ($final_amount <= 0) {
        $wpdb->update($table_name, array('status' => 'Paid - 100% Free VIP'), array('order_ref' => $local_order_id));
        wp_send_json_success( array( 'payment_url' => site_url('/thank-you/?ref=' . $local_order_id . '-VIP') ) );
    }

    $main_site_url = 'https://thecyclehub.com'; 
    $endpoint      = $main_site_url . '/wp-json/bistro-bridge/v1/pay';

    $payload = array(
        'order_id'   => $local_order_id,
        'amount'     => $final_amount,
        'currency'   => 'AED',
        'email'      => $email,
        'first_name' => $first_name,
        'last_name'  => $last_name,
        'return_url' => site_url('/thank-you/')
    );

    $response = wp_remote_post( $endpoint, array(
        'headers' => array( 'Content-Type' => 'application/json', 'x-bistro-token' => defined('BISTRO_BRIDGE_SECRET') ? BISTRO_BRIDGE_SECRET : '' ),
        'body'    => wp_json_encode( $payload ),
        'timeout' => 20,
    ));

    if ( is_wp_error( $response ) ) { wp_send_json_error( 'Bridge Error: ' . $response->get_error_message() ); }
    $body = json_decode( wp_remote_retrieve_body( $response ), true );

    if ( isset( $body['success'] ) && $body['success'] === true ) {
        $wpdb->update($table_name, array('ngenius_ref' => sanitize_text_field($body['reference'])), array('order_ref' => $local_order_id));
        wp_send_json_success( array( 'payment_url' => esc_url_raw( $body['payment_url'] ) ) );
    } else {
        wp_send_json_error( 'Gateway Error: Failed to generate payment link.' );
    }
}

// ==========================================
// 6. VERIFICATION SHORTCODE
// ==========================================
add_shortcode('tcb_verify_payment', 'tcb_render_verification_status');

function tcb_render_verification_status() {
    if ( !isset($_GET['ref']) || empty($_GET['ref']) ) {
        return '<h3>Order Received</h3><p>We are reviewing your submission. No payment reference found.</p>';
    }

    $reference = sanitize_text_field($_GET['ref']);
    
    if (strpos($reference, '-VIP') !== false) {
        return '<div style="padding:30px; background:#f4fdf4; border:2px solid #176b3b; text-align:center; border-radius:12px; margin-bottom: 20px;">
                    <h2 style="color:#176b3b; margin-top:0;">Reservation Confirmed!</h2>
                    <p>Your VIP Christmas turkey reservation is confirmed. Reference: <strong>' . esc_html(str_replace('-VIP', '', $reference)) . '</strong></p>
                </div>';
    }

    $main_site_url = 'https://thecyclehub.com';
    $endpoint      = $main_site_url . '/wp-json/bistro-bridge/v1/verify';

    $response = wp_remote_post( $endpoint, array(
        'headers' => array( 'Content-Type' => 'application/json', 'x-bistro-token' => defined('BISTRO_BRIDGE_SECRET') ? BISTRO_BRIDGE_SECRET : '' ),
        'body'    => wp_json_encode( array('reference' => $reference) ),
        'timeout' => 20,
    ));

    if ( is_wp_error( $response ) ) return '<p>Error confirming payment connection. Please contact support.</p>';
    $body = json_decode( wp_remote_retrieve_body( $response ), true );

    if ( isset($body['success']) && $body['success'] === true ) {
        global $wpdb;
        $table_name = $wpdb->prefix . 'tcb_transactions';
        $state = $body['state'];

        if ( in_array( $state, array('CAPTURED', 'AUTHORISED', 'PURCHASED'), true ) ) {
            $wpdb->update($table_name, array('status' => 'Paid - ' . $state), array('ngenius_ref' => $reference));
            return '<div style="padding:30px; background:#f4fdf4; border:2px solid #176b3b; text-align:center; border-radius:12px; margin-bottom: 20px;">
                        <h2 style="color:#176b3b; margin-top:0;">Payment Successful!</h2>
                        <p>Your Christmas turkey reservation is confirmed. Reference: <strong>' . esc_html($reference) . '</strong></p>
                    </div>';
        } else {
            $wpdb->update($table_name, array('status' => 'Failed - ' . $state), array('ngenius_ref' => $reference));
            return '<div style="padding:30px; background:#fef2f2; border:2px solid #991b1b; text-align:center; border-radius:12px; margin-bottom: 20px;">
                        <h2 style="color:#991b1b; margin-top:0;">Payment Declined</h2>
                        <p>Your transaction was not completed. Please try again or contact support.</p>
                    </div>';
        }
    }
    return '<p>Unable to verify payment status. Please contact the restaurant.</p>';
}
