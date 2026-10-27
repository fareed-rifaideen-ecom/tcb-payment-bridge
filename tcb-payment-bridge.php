<?php
/*
Plugin Name: TCB Custom Checkout Bridge
Plugin URI: https://github.com/fareed-rifaideen-ecom/tcb-payment-bridge
Description: Handles custom Elementor form checkouts, calculates discounts, creates local logs, and connects to The Cycle Hub payment bridge.
Version: 1.1
Author: Fareed M Rifaideen
*/

if ( ! defined( 'ABSPATH' ) ) { exit; }

// --- 1. PROMO CODE CONFIGURATION ---
// Manage your active discounts here. You can add fixed amounts or percentages.
function tcb_get_valid_promos() {
    return array(
        'VIP2026'   => array('type' => 'percent', 'value' => 10),  // 10% off total
        'STAFF'     => array('type' => 'percent', 'value' => 100), // 100% free bypass
        'EARLYBIRD' => array('type' => 'fixed',   'value' => 50),  // 50 AED off total
    );
}

// --- 2. DATABASE SETUP ---
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
    dbDelta( $sql ); // dbDelta safely adds the new promo columns to your existing table
}

// --- 3. AJAX: LIVE PROMO VALIDATOR (For Frontend) ---
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
        
        wp_send_json_success(array(
            'code' => $promo,
            'discount' => round($discount_amt, 2)
        ));
    } else {
        wp_send_json_error('Invalid or expired promo code.');
    }
}

// --- 4. AJAX: FINAL PAYMENT PROCESSOR ---
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

    // Server-Side Math
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

    // 100% Free Bypass Logic (Avoids sending a 0 AED transaction to N-Genius)
    if ($final_amount <= 0) {
        $wpdb->update($table_name, array('status' => 'Paid - 100% Free VIP'), array('order_ref' => $local_order_id));
        wp_send_json_success( array( 'payment_url' => site_url('/thank-you/?ref=' . $local_order_id . '-VIP') ) );
    }

    // Send Payload to Main Site Proxy
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

// --- 5. VERIFICATION SHORTCODE ---
add_shortcode('tcb_verify_payment', 'tcb_render_verification_status');

function tcb_render_verification_status() {
    if ( !isset($_GET['ref']) || empty($_GET['ref']) ) {
        return '<h3>Order Received</h3><p>We are reviewing your submission. No payment reference found.</p>';
    }

    $reference = sanitize_text_field($_GET['ref']);
    
    // Auto-approve 100% Free VIP bypasses
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
