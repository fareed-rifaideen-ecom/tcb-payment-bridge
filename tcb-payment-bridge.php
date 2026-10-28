<?php
/*
Plugin Name: TCB Custom Checkout Bridge
Plugin URI: https://github.com/fareed-rifaideen-ecom/tcb-payment-bridge
Description: Shortcode-based checkout generator with dynamic pricing, CSV exports, email notifications, dashboard management, deadline enforcement, and UI toggles.
Version: 6.0
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
        item_name varchar(150) DEFAULT '' NOT NULL,
        customer_name varchar(100) NOT NULL,
        customer_email varchar(100) NOT NULL,
        customer_phone varchar(50) DEFAULT '' NOT NULL,
        preferred_date varchar(50) DEFAULT '' NOT NULL,
        notes text,
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
// 2. ADMIN MENUS & DASHBOARD
// ==========================================
add_action('admin_menu', 'tcb_register_admin_menus');

function tcb_register_admin_menus() {
    add_menu_page('Catering Orders', 'Catering Orders', 'manage_options', 'tcb-turkey-orders', 'tcb_render_order_dashboard', 'dashicons-list-view', 56);
    add_submenu_page('tcb-turkey-orders', 'View Orders', 'View Orders', 'manage_options', 'tcb-turkey-orders', 'tcb_render_order_dashboard');
    add_submenu_page('tcb-turkey-orders', 'Settings', 'Settings', 'manage_options', 'tcb-settings', 'tcb_render_settings_page');
}

function tcb_render_order_dashboard() {
    global $wpdb;
    $table_name = $wpdb->prefix . 'tcb_transactions';
    $results = $wpdb->get_results("SELECT * FROM $table_name ORDER BY order_date DESC LIMIT 200");
    ?>
    <div class="wrap">
        <h1 class="wp-heading-inline">Checkout Bridge Orders</h1>
        <a href="<?php echo esc_url(admin_url('admin.php?action=tcb_export_csv')); ?>" class="page-title-action">Export to CSV</a>
        
        <?php if (isset($_GET['deleted']) && $_GET['deleted'] == '1') : ?>
            <div class="notice notice-success is-dismissible"><p>Order permanently deleted.</p></div>
        <?php endif; ?>

        <table class="wp-list-table widefat fixed striped" style="margin-top:15px;">
            <thead>
                <tr>
                    <th style="width: 100px;">Date</th>
                    <th style="width: 150px;">Order Ref</th>
                    <th>Item</th>
                    <th>Customer</th>
                    <th>Contact Info</th>
                    <th style="width: 140px;">Pickup Schedule</th>
                    <th style="width: 50px;">Qty</th>
                    <th>Promo</th>
                    <th>Total Paid</th>
                    <th>Status</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($results)) : ?>
                    <tr><td colspan="10">No orders found.</td></tr>
                <?php else : ?>
                    <?php foreach ($results as $row) : 
                        $status_color = (strpos($row->status, 'Paid') !== false) ? 'color: #176b3b; font-weight: bold;' : ((strpos($row->status, 'Pending') !== false) ? 'color: #b45309;' : 'color: #dc2626;');
                        $delete_url = wp_nonce_url(admin_url('admin-post.php?action=tcb_delete_order&id=' . $row->id), 'tcb_delete_order_' . $row->id);
                    ?>
                        <tr>
                            <td><?php echo esc_html(date('M d, H:i', strtotime($row->order_date))); ?></td>
                            <td>
                                <strong><?php echo esc_html($row->order_ref); ?></strong>
                                <div class="row-actions">
                                    <span class="trash"><a href="<?php echo esc_url($delete_url); ?>" style="color:#a00;" onclick="return confirm('Are you sure you want to permanently delete this order?');">Trash</a></span>
                                </div>
                            </td>
                            <td><?php echo esc_html($row->item_name); ?></td>
                            <td><?php echo esc_html($row->customer_name); ?></td>
                            <td>
                                <a href="mailto:<?php echo esc_attr($row->customer_email); ?>"><?php echo esc_html($row->customer_email); ?></a><br>
                                <small><?php echo esc_html($row->customer_phone); ?></small>
                            </td>
                            <td><strong><?php echo esc_html($row->preferred_date); ?></strong></td>
                            <td><?php echo esc_html($row->sets); ?></td>
                            <td>
                                <?php if($row->promo_code): ?>
                                    <span style="background:#edf7df; color:#176b3b; padding:3px 6px; border-radius:4px; font-size:11px; font-weight:bold;"><?php echo esc_html($row->promo_code); ?></span>
                                <?php else: ?>-<?php endif; ?>
                            </td>
                            <td><strong>AED <?php echo esc_html($row->amount); ?></strong></td>
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
// 3. DELETE ACTION HANDLER
// ==========================================
add_action('admin_post_tcb_delete_order', 'tcb_handle_delete_order');

function tcb_handle_delete_order() {
    if (!current_user_can('manage_options')) { wp_die('Unauthorized'); }
    
    $id = isset($_GET['id']) ? intval($_GET['id']) : 0;
    if (!$id || !isset($_GET['_wpnonce']) || !wp_verify_nonce($_GET['_wpnonce'], 'tcb_delete_order_' . $id)) {
        wp_die('Security check failed.');
    }

    global $wpdb;
    $table_name = $wpdb->prefix . 'tcb_transactions';
    $wpdb->delete($table_name, array('id' => $id), array('%d'));

    wp_redirect(admin_url('admin.php?page=tcb-turkey-orders&deleted=1'));
    exit;
}

// ==========================================
// 4. SETTINGS DASHBOARD & EXPORT
// ==========================================
add_action('admin_init', 'tcb_register_settings');

function tcb_register_settings() {
    register_setting('tcb_settings_group', 'tcb_notification_emails');
    register_setting('tcb_settings_group', 'tcb_enable_promo_field');
}

function tcb_render_settings_page() {
    $promo_enabled = get_option('tcb_enable_promo_field', 'yes');
    ?>
    <div class="wrap">
        <h1>Bridge Settings</h1>
        <form method="post" action="options.php">
            <?php settings_fields('tcb_settings_group'); ?>
            <table class="form-table">
                <tr valign="top">
                    <th scope="row">Enable Promo Code Field</th>
                    <td>
                        <input type="checkbox" name="tcb_enable_promo_field" id="tcb_enable_promo_field" value="yes" <?php checked($promo_enabled, 'yes'); ?> />
                        <label for="tcb_enable_promo_field">Display the promo code input box on the frontend checkout form.</label>
                    </td>
                </tr>
                <tr valign="top">
                    <th scope="row">Order Notification Emails</th>
                    <td>
                        <input type="text" name="tcb_notification_emails" value="<?php echo esc_attr(get_option('tcb_notification_emails', 'fareed@thecyclehub.com, marketing@thecyclehub.com')); ?>" class="regular-text" style="width: 100%; max-width: 600px;" />
                        <p class="description">Enter email addresses separated by commas.</p>
                    </td>
                </tr>
            </table>
            <?php submit_button(); ?>
        </form>
    </div>
    <?php
}

add_action('admin_action_tcb_export_csv', 'tcb_export_orders_csv');

function tcb_export_orders_csv() {
    if (!current_user_can('manage_options')) { wp_die('Unauthorized'); }
    global $wpdb;
    $table_name = $wpdb->prefix . 'tcb_transactions';
    $results = $wpdb->get_results("SELECT * FROM $table_name ORDER BY order_date DESC", ARRAY_A);

    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename=catering-orders-' . date('Y-m-d') . '.csv');

    $output = fopen('php://output', 'w');
    
    fputcsv($output, array(
        'Order Ref', 'Item Name', 'Customer Name', 'Email', 'Phone', 'Pickup Date', 'Pickup Time', 'Qty', 'Promo Code', 'Discount (AED)', 'Amount Paid (AED)', 'Gateway Ref', 'Status', 'Order Placed On', 'Notes'
    ));

    foreach ($results as $row) {
        $schedule = explode(' at ', $row['preferred_date']);
        $pickup_date = isset($schedule[0]) ? $schedule[0] : $row['preferred_date'];
        $pickup_time = isset($schedule[1]) ? $schedule[1] : '';

        fputcsv($output, array(
            $row['order_ref'], $row['item_name'], $row['customer_name'], $row['customer_email'], $row['customer_phone'], $pickup_date, $pickup_time, $row['sets'], $row['promo_code'], $row['discount_amount'], $row['amount'], $row['ngenius_ref'], $row['status'], $row['order_date'], $row['notes']
        ));
    }
    
    fclose($output);
    exit;
}

// ==========================================
// 5. FRONTEND DATA BRIDGE (JS GLOBALS)
// ==========================================
add_action('wp_head', 'tcb_inject_frontend_settings');

function tcb_inject_frontend_settings() {
    $show_promo = get_option('tcb_enable_promo_field', 'yes') === 'yes' ? 'true' : 'false';
    echo "<script>window.tcbSettings = { showPromo: {$show_promo} };</script>\n";
}

// ==========================================
// 6. PROMO CODE MANAGEMENT (CPT)
// ==========================================
add_action('init', 'tcb_register_promo_cpt');

function tcb_register_promo_cpt() {
    register_post_type('tcb_promo', array(
        'labels' => array('name' => 'Promo Codes', 'singular_name' => 'Promo Code', 'add_new' => 'Add New Promo', 'add_new_item' => 'Add New Promo Code', 'edit_item' => 'Edit Promo Code'),
        'public' => false, 'show_ui' => true, 'show_in_menu'=> 'tcb-turkey-orders', 'supports' => array('title'), 
    ));
}

add_action('add_meta_boxes', 'tcb_add_promo_meta_box');
function tcb_add_promo_meta_box() { add_meta_box('tcb_promo_details', 'Discount Details', 'tcb_render_promo_meta_box', 'tcb_promo', 'normal', 'high'); }

function tcb_render_promo_meta_box($post) {
    wp_nonce_field('tcb_save_promo_data', 'tcb_promo_nonce');
    $type = get_post_meta($post->ID, '_promo_type', true) ?: 'fixed';
    $value = get_post_meta($post->ID, '_promo_value', true) ?: '';
    ?>
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
            <td><input type="number" step="0.01" name="promo_value" id="promo_value" value="<?php echo esc_attr($value); ?>" class="regular-text" required></td>
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

function tcb_get_valid_promos() {
    $promos = array();
    $query = new WP_Query(array('post_type' => 'tcb_promo', 'posts_per_page' => -1, 'post_status' => 'publish'));
    if ($query->have_posts()) {
        foreach ($query->posts as $p) {
            $type = get_post_meta($p->ID, '_promo_type', true);
            $val = get_post_meta($p->ID, '_promo_value', true);
            $promos[strtoupper(trim($p->post_title))] = array('type' => $type, 'value' => (float)$val);
        }
    }
    return $promos;
}

// ==========================================
// 7. SERVER-SIDE SECURITY & MATH HELPERS
// ==========================================
function tcb_verify_checkout_payload($item_name, $base_price, $hash) {
    return wp_hash($item_name . '|' . $base_price, 'nonce') === $hash;
}

add_action('wp_ajax_nopriv_tcb_validate_promo', 'tcb_ajax_validate_promo');
add_action('wp_ajax_tcb_validate_promo', 'tcb_ajax_validate_promo');

function tcb_ajax_validate_promo() {
    $promo      = isset($_POST['promo']) ? strtoupper(sanitize_text_field($_POST['promo'])) : '';
    $sets       = isset($_POST['sets']) ? intval($_POST['sets']) : 0;
    $item_name  = isset($_POST['cb_item_name']) ? sanitize_text_field($_POST['cb_item_name']) : '';
    $base_price = isset($_POST['cb_base_price']) ? floatval($_POST['cb_base_price']) : 0;
    $hash       = isset($_POST['cb_price_hash']) ? sanitize_text_field($_POST['cb_price_hash']) : '';

    $is_valid = false;
    if (!empty($hash)) {
        $is_valid = tcb_verify_checkout_payload($item_name, $base_price, $hash);
    } else {
        $base_price = 600;
        $is_valid = true;
    }

    if (empty($promo) || $sets < 1 || !$is_valid) { 
        wp_send_json_error('Security validation failed.'); 
    }

    $promos = tcb_get_valid_promos();
    if (isset($promos[$promo])) {
        $base_total = $sets * $base_price;
        $discount_amt = ($promos[$promo]['type'] == 'fixed') ? $promos[$promo]['value'] : ($base_total * ($promos[$promo]['value'] / 100));
        $discount_amt = min($discount_amt, $base_total);
        wp_send_json_success(array('code' => $promo, 'discount' => round($discount_amt, 2)));
    } else {
        wp_send_json_error('Invalid code.');
    }
}

// ==========================================
// 8. AJAX: FINAL PAYMENT PROCESSOR
// ==========================================
add_action('wp_ajax_nopriv_tcb_process_turkey', 'tcb_process_turkey_payment');
add_action('wp_ajax_tcb_process_turkey', 'tcb_process_turkey_payment');

function tcb_process_turkey_payment() {
    $item_name  = isset($_POST['cb_item_name']) ? sanitize_text_field($_POST['cb_item_name']) : 'Turkey Takeaway 2026';
    
    // Enforce Deadline Server-Side for the Turkey Campaign (Dubai Time)
    if (strpos(strtolower($item_name), 'turkey') !== false) {
        $deadline_timestamp = strtotime('2026-12-22T23:59:59+04:00');
        if (time() > $deadline_timestamp) {
            wp_send_json_error('Booking is officially closed. No new orders can be accepted.');
        }
    }

    global $wpdb;
    
    $first_name = isset($_POST['firstName']) ? sanitize_text_field($_POST['firstName']) : '';
    $last_name  = isset($_POST['lastName']) ? sanitize_text_field($_POST['lastName']) : '';
    $email      = isset($_POST['email']) ? sanitize_email($_POST['email']) : '';
    $phone      = isset($_POST['phone']) ? sanitize_text_field($_POST['phone']) : '';
    $date       = isset($_POST['date']) ? sanitize_text_field($_POST['date']) : '';
    $notes      = isset($_POST['notes']) ? sanitize_textarea_field($_POST['notes']) : '';
    $sets       = isset($_POST['sets']) ? intval($_POST['sets']) : 0;
    $promo      = isset($_POST['promo']) ? strtoupper(sanitize_text_field($_POST['promo'])) : '';
    
    $base_price = isset($_POST['cb_base_price']) ? floatval($_POST['cb_base_price']) : 0;
    $hash       = isset($_POST['cb_price_hash']) ? sanitize_text_field($_POST['cb_price_hash']) : '';

    $is_valid = false;
    if (!empty($hash)) {
        $is_valid = tcb_verify_checkout_payload($item_name, $base_price, $hash);
    } else {
        $item_name = 'Turkey Takeaway 2026';
        $base_price = 600;
        $is_valid = true;
    }

    if (empty($first_name) || empty($email) || $sets < 1 || !$is_valid) {
        wp_send_json_error('Invalid submission data.');
    }

    $base_total = $sets * $base_price;
    $discount_amt = 0;
    $promos = tcb_get_valid_promos();
    
    if (!empty($promo) && isset($promos[$promo])) {
        $discount_amt = ($promos[$promo]['type'] == 'fixed') ? $promos[$promo]['value'] : ($base_total * ($promos[$promo]['value'] / 100));
    }
    
    $final_amount = max(0, $base_total - $discount_amt);
    $local_order_id = 'TCB-' . strtoupper(uniqid());
    $table_name = $wpdb->prefix . 'tcb_transactions';

    $wpdb->insert($table_name, array(
        'order_ref'      => $local_order_id,
        'item_name'      => $item_name,
        'customer_name'  => trim($first_name . ' ' . $last_name),
        'customer_email' => $email,
        'customer_phone' => $phone,
        'preferred_date' => $date,
        'notes'          => $notes,
        'sets'           => $sets,
        'promo_code'     => $promo,
        'discount_amount'=> $discount_amt,
        'amount'         => $final_amount,
        'status'         => 'Pending'
    ));

    if ($final_amount <= 0) {
        $wpdb->update($table_name, array('status' => 'Paid - 100% Free VIP'), array('order_ref' => $local_order_id));
        $order_data = $wpdb->get_row($wpdb->prepare("SELECT * FROM $table_name WHERE order_ref = %s", $local_order_id), ARRAY_A);
        if ($order_data) { tcb_send_new_order_email($order_data); }
        wp_send_json_success( array( 'payment_url' => site_url('/thank-you/?ref=' . $local_order_id . '-VIP') ) );
    }

    $endpoint = 'https://thecyclehub.com/wp-json/bistro-bridge/v1/pay';
    $payload = array(
        'order_id'   => $local_order_id,
        'amount'     => $final_amount,
        'currency'   => 'AED',
        'email'      => $email,
        'first_name' => $first_name,
        'last_name'  => $last_name,
        'return_url' => site_url('/thank-you/')
    );

    $response = wp_remote_post($endpoint, array(
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
// 9. VERIFICATION SHORTCODE & EMAIL FIRING
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
                    <p>Your VIP reservation is confirmed. Reference: <strong>' . esc_html(str_replace('-VIP', '', $reference)) . '</strong></p>
                </div>';
    }

    $endpoint = 'https://thecyclehub.com/wp-json/bistro-bridge/v1/verify';
    $response = wp_remote_post($endpoint, array(
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
            $order_data = $wpdb->get_row($wpdb->prepare("SELECT * FROM $table_name WHERE ngenius_ref = %s", $reference), ARRAY_A);
            
            if ($order_data && $order_data['status'] === 'Pending') {
                $wpdb->update($table_name, array('status' => 'Paid - ' . $state), array('ngenius_ref' => $reference));
                $order_data['status'] = 'Paid - ' . $state;
                tcb_send_new_order_email($order_data); 
            }

            return '<div style="padding:30px; background:#f4fdf4; border:2px solid #176b3b; text-align:center; border-radius:12px; margin-bottom: 20px;">
                        <h2 style="color:#176b3b; margin-top:0;">Payment Successful!</h2>
                        <p>Your reservation is confirmed. Reference: <strong>' . esc_html($reference) . '</strong></p>
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

function tcb_send_new_order_email($order_data) {
    $emails_option = get_option('tcb_notification_emails', 'fareed@thecyclehub.com, marketing@thecyclehub.com');
    $to = array_map('trim', explode(',', $emails_option));
    $subject = 'New Order Confirmed - ' . $order_data['order_ref'];
    
    $message = "A new order has been successfully paid via the checkout bridge.\n\n";
    
    $message .= "=================================\n";
    $message .= "ORDER SUMMARY\n";
    $message .= "=================================\n";
    $message .= "Order Reference: " . $order_data['order_ref'] . "\n";
    $message .= "Item Ordered: " . $order_data['item_name'] . "\n";
    $message .= "Quantity (Sets): " . $order_data['sets'] . "\n";
    $message .= "Status: " . $order_data['status'] . "\n\n";
    
    $message .= "=================================\n";
    $message .= "CUSTOMER DETAILS\n";
    $message .= "=================================\n";
    $message .= "Customer Name: " . $order_data['customer_name'] . "\n";
    $message .= "Email Address: " . $order_data['customer_email'] . "\n";
    $message .= "Phone Number: " . $order_data['customer_phone'] . "\n\n";
    
    $message .= "=================================\n";
    $message .= "FULFILLMENT DETAILS\n";
    $message .= "=================================\n";
    $message .= "Pickup Date & Time: " . $order_data['preferred_date'] . "\n";
    $message .= "Additional Notes: " . ($order_data['notes'] ?: 'None provided') . "\n\n";
    
    $message .= "=================================\n";
    $message .= "PAYMENT DETAILS\n";
    $message .= "=================================\n";
    $message .= "Promo Code Applied: " . ($order_data['promo_code'] ?: 'None') . "\n";
    $message .= "Discount Amount: AED " . $order_data['discount_amount'] . "\n";
    $message .= "Final Amount Paid: AED " . $order_data['amount'] . "\n";
    $message .= "N-Genius Gateway Ref: " . ($order_data['ngenius_ref'] ?: 'N/A (100% Free VIP)') . "\n";
    
    $headers = array('Content-Type: text/plain; charset=UTF-8');
    wp_mail($to, $subject, $message, $headers);
}
