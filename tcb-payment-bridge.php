<?php
/*
Plugin Name: TCB Custom Checkout Bridge
Plugin URI: https://github.com/fareed-rifaideen-ecom/tcb-payment-bridge
Description: Handles custom Elementor form checkouts, creates local logs, and connects to The Cycle Hub payment bridge.
Version: 1.0
Author: Fareed M Rifaideen
*/

if ( ! defined( 'ABSPATH' ) ) { exit; }

// 1. Create Local Transaction Table on Activation
register_activation_hook( __FILE__, 'tcb_create_local_transaction_table' );
add_action( 'plugins_loaded', 'tcb_create_local_transaction_table' );

function tcb_create_local_transaction_table() {
    global $wpdb;
    $table_name = $wpdb->prefix . 'tcb_transactions';
    
    if ( $wpdb->get_var("SHOW TABLES LIKE '$table_name'") != $table_name ) {
        $charset_collate = $wpdb->get_charset_collate();
        $sql = "CREATE TABLE $table_name (
            id mediumint(9) NOT NULL AUTO_INCREMENT,
            order_ref varchar(50) NOT NULL,
            customer_name varchar(100) NOT NULL,
            customer_email varchar(100) NOT NULL,
            sets int(3) NOT NULL,
            amount decimal(10,2) NOT NULL,
            ngenius_ref varchar(100) DEFAULT '' NOT NULL,
            status varchar(50) DEFAULT 'Pending' NOT NULL,
            order_date datetime DEFAULT CURRENT_TIMESTAMP NOT NULL,
            PRIMARY KEY  (id)
        ) $charset_collate;";
        require_once( ABSPATH . 'wp-admin/includes/upgrade.php' );
        dbDelta( $sql );
    }
}

// 2. AJAX Form Handler for Payment Processing
add_action('wp_ajax_nopriv_tcb_process_turkey', 'tcb_process_turkey_payment');
add_action('wp_ajax_tcb_process_turkey', 'tcb_process_turkey_payment');

function tcb_process_turkey_payment() {
    global $wpdb;
    
    // Sanitize Inputs
    $first_name = isset($_POST['firstName']) ? sanitize_text_field($_POST['firstName']) : '';
    $last_name  = isset($_POST['lastName']) ? sanitize_text_field($_POST['lastName']) : '';
    $email      = isset($_POST['email']) ? sanitize_email($_POST['email']) : '';
    $sets       = isset($_POST['sets']) ? intval($_POST['sets']) : 0;
    
    if( empty($first_name) || empty($email) || $sets < 1 ) {
        wp_send_json_error('Missing required fields.');
    }

    // Strict Server-Side Pricing Logic (AED 600 per set)
    $amount = $sets * 600;
    $local_order_id = 'TCB-' . strtoupper(uniqid());

    // Save to Local Database
    $table_name = $wpdb->prefix . 'tcb_transactions';
    $wpdb->insert($table_name, array(
        'order_ref'      => $local_order_id,
        'customer_name'  => trim($first_name . ' ' . $last_name),
        'customer_email' => $email,
        'sets'           => $sets,
        'amount'         => $amount,
        'status'         => 'Pending'
    ));

    // Send Payload to Main Site Proxy
    $main_site_url = 'https://thecyclehub.com'; 
    $endpoint      = $main_site_url . '/wp-json/bistro-bridge/v1/pay';

    $payload = array(
        'order_id'   => $local_order_id,
        'amount'     => $amount,
        'currency'   => 'AED',
        'email'      => $email,
        'first_name' => $first_name,
        'last_name'  => $last_name,
        'return_url' => site_url('/thank-you/') // The page where the user returns
    );

    $response = wp_remote_post( $endpoint, array(
        'headers' => array(
            'Content-Type'   => 'application/json',
            'x-bistro-token' => defined('BISTRO_BRIDGE_SECRET') ? BISTRO_BRIDGE_SECRET : ''
        ),
        'body'    => wp_json_encode( $payload ),
        'timeout' => 20,
    ));

    if ( is_wp_error( $response ) ) {
        wp_send_json_error( 'Bridge Error: ' . $response->get_error_message() );
    }

    $body = json_decode( wp_remote_retrieve_body( $response ), true );

    if ( isset( $body['success'] ) && $body['success'] === true ) {
        // Log the N-Genius reference locally
        $wpdb->update(
            $table_name,
            array('ngenius_ref' => sanitize_text_field($body['reference'])),
            array('order_ref' => $local_order_id)
        );
        
        wp_send_json_success( array( 'payment_url' => esc_url_raw( $body['payment_url'] ) ) );
    } else {
        $error_message = isset( $body['message'] ) ? $body['message'] : 'Failed to generate payment link.';
        wp_send_json_error( 'Gateway Error: ' . $error_message );
    }
}

// 3. Shortcode for Verification on the Thank You Page
add_shortcode('tcb_verify_payment', 'tcb_render_verification_status');

function tcb_render_verification_status() {
    if ( !isset($_GET['ref']) || empty($_GET['ref']) ) {
        return '<h3>Order Received</h3><p>We are reviewing your submission. No payment reference found.</p>';
    }

    $reference = sanitize_text_field($_GET['ref']);
    $main_site_url = 'https://thecyclehub.com';
    $endpoint      = $main_site_url . '/wp-json/bistro-bridge/v1/verify';

    $response = wp_remote_post( $endpoint, array(
        'headers' => array( 
            'Content-Type' => 'application/json', 
            'x-bistro-token' => defined('BISTRO_BRIDGE_SECRET') ? BISTRO_BRIDGE_SECRET : '' 
        ),
        'body'    => wp_json_encode( array('reference' => $reference) ),
        'timeout' => 20,
    ));

    if ( is_wp_error( $response ) ) {
        return '<p>Error confirming payment connection. Please contact support.</p>';
    }

    $body = json_decode( wp_remote_retrieve_body( $response ), true );

    if ( isset($body['success']) && $body['success'] === true ) {
        $state = $body['state'];
        
        global $wpdb;
        $table_name = $wpdb->prefix . 'tcb_transactions';

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
