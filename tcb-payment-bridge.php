<?php
/*
Plugin Name: TCB Custom Checkout Bridge
Plugin URI: https://github.com/fareed-rifaideen-ecom/tcb-payment-bridge
Description: Hybrid checkout generator supporting both secure shortcodes and static Elementor HTML blocks.
Version: 5.1
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
        <table class="wp-list-table widefat fixed striped" style="margin-top:15px;">
            <thead>
                <tr>
                    <th style="width: 120px;">Date</th>
                    <th>Order Ref</th>
                    <th>Item</th>
                    <th>Customer</th>
                    <th>Email</th>
                    <th>Phone</th>
                    <th style="width: 60px;">Sets</th>
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
                    ?>
                        <tr>
                            <td><?php echo esc_html(date('M d, H:i', strtotime($row->order_date))); ?></td>
                            <td><strong><?php echo esc_html($row->order_ref); ?></strong></td>
                            <td><?php echo esc_html($row->item_name); ?></td>
                            <td><?php echo esc_html($row->customer_name); ?></td>
                            <td><a href="mailto:<?php echo esc_attr($row->customer_email); ?>"><?php echo esc_html($row->customer_email); ?></a></td>
                            <td><?php echo esc_html($row->customer_phone); ?></td>
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
// 3. SETTINGS DASHBOARD & EXPORT
// ==========================================
add_action('admin_init', 'tcb_register_settings');

function tcb_register_settings() {
    register_setting('tcb_settings_group', 'tcb_notification_emails');
}

function tcb_render_settings_page() {
    ?>
    <div class="wrap">
        <h1>Bridge Settings</h1>
        <form method="post" action="options.php">
            <?php settings_fields('tcb_settings_group'); ?>
            <table class="form-table">
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
    header('Content-Disposition: attachment; filename=checkout-orders-' . date('Y-m-d') . '.csv');

    $output = fopen('php://output', 'w');
    fputcsv($output, array('DB ID', 'Order Ref', 'Item Name', 'Customer Name', 'Customer Email', 'Customer Phone', 'Preferred Date', 'Notes', 'Qty', 'Promo Code', 'Discount (AED)', 'Final Amount (AED)', 'N-Genius Ref', 'Status', 'Order Date'));

    foreach ($results as $row) { fputcsv($output, $row); }
    fclose($output);
    exit;
}

// ==========================================
// 4. PROMO CODE MANAGEMENT (CPT)
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
// 5. SHORTCODE FORM GENERATOR
// ==========================================
add_shortcode('tcb_checkout', 'tcb_render_checkout_form');

function tcb_render_checkout_form($atts) {
    $a = shortcode_atts(array(
        'item'  => 'Turkey Takeaway Set 2026',
        'price' => '600'
    ), $atts);

    $item_name = sanitize_text_field($a['item']);
    $price = floatval($a['price']);
    $price_hash = wp_hash($item_name . '|' . $price, 'nonce');

    ob_start();
    ?>
    <form class="cb-form cb-reveal is-visible" id="cb-booking-form">
        <input type="hidden" name="cb_item_name" id="cb_item_name" value="<?php echo esc_attr($item_name); ?>">
        <input type="hidden" name="cb_base_price" id="cb_base_price" value="<?php echo esc_attr($price); ?>">
        <input type="hidden" name="cb_price_hash" id="cb_price_hash" value="<?php echo esc_attr($price_hash); ?>">

        <h3>Reserve <?php echo esc_html($item_name); ?></h3>
        <p class="cb-form__intro">Fields marked with * are required. Base Price: AED <?php echo esc_html($price); ?></p>
        
        <div class="cb-form__grid">
            <div class="cb-field"><label for="cb-first-name">First name *</label><input class="cb-input" id="cb-first-name" name="firstName" required autocomplete="given-name"></div>
            <div class="cb-field"><label for="cb-last-name">Last name *</label><input class="cb-input" id="cb-last-name" name="lastName" required autocomplete="family-name"></div>
            <div class="cb-field"><label for="cb-phone">Mobile or WhatsApp *</label><input class="cb-input" id="cb-phone" name="phone" type="tel" required autocomplete="tel"></div>
            <div class="cb-field"><label for="cb-email">Email address *</label><input class="cb-input" id="cb-email" name="email" type="email" required autocomplete="email"></div>
            
            <div class="cb-field"><label for="cb-sets">Quantity *</label>
                <select class="cb-input" id="cb-sets" name="sets" required>
                    <option value="">Select</option><option value="1">1</option><option value="2">2</option><option value="3">3</option><option value="4">4</option><option value="5">5</option>
                </select>
            </div>
            
            <div class="cb-field"><label for="cb-date">Preferred date *</label><input class="cb-input" id="cb-date" name="date" type="date" required></div>
            
            <div class="cb-field cb-field--full" style="margin-top: 10px;">
                <label for="cb-promo">Promo Code (Optional)</label>
                <div style="display: flex; gap: 10px;">
                    <input class="cb-input" id="cb-promo" name="promo" style="text-transform: uppercase;" placeholder="Enter code">
                    <button type="button" id="cb-apply-promo" class="cb-btn cb-btn--outline" style="padding: 10px 20px; min-height: auto;">Apply</button>
                </div>
                <div id="cb-promo-msg" style="font-size: 13px; margin-top: 5px; font-weight: 700;"></div>
            </div>

            <div class="cb-field cb-field--full" style="background: #f4fdf4; padding: 15px 20px; border-radius: 12px; border: 1px solid #76c84b; margin: 15px 0;">
                <div style="display: flex; justify-content: space-between; align-items: center; font-size: 16px;">
                    <span>Subtotal:</span>
                    <span id="cb-subtotal-display">AED 0.00</span>
                </div>
                <div id="cb-discount-row" style="display: none; justify-content: space-between; align-items: center; font-size: 16px; color: #176b3b; margin-top: 5px;">
                    <span>Discount:</span>
                    <span id="cb-discount-display">- AED 0.00</span>
                </div>
                <div style="display: flex; justify-content: space-between; align-items: center; font-size: 20px; font-weight: 800; border-top: 1px solid #76c84b; padding-top: 10px; margin-top: 10px;">
                    <span>Order Total:</span>
                    <span id="cb-total-display">AED 0.00</span>
                </div>
            </div>

            <div class="cb-field cb-field--full"><label for="cb-notes">Additional notes</label><textarea class="cb-input" id="cb-notes" name="notes" placeholder="Collection or delivery preference, allergies or other notes"></textarea></div>
        </div>
        <button class="cb-btn cb-btn--primary cb-form__submit" type="submit">Place Order</button>
        <p class="cb-form__status" id="cb-form-status" role="status"></p>
    </form>

    <script>
    (function(){
        var form = document.querySelector('#cb-booking-form');
        if(!form) return;
        
        var submitBtn = document.querySelector('.cb-form__submit');
        var status = document.querySelector('#cb-form-status');
        var setsDropdown = document.querySelector('#cb-sets');
        var promoInput = document.querySelector('#cb-promo');
        var applyPromoBtn = document.querySelector('#cb-apply-promo');
        var promoMsg = document.querySelector('#cb-promo-msg');
        
        var subtotalDisp = document.querySelector('#cb-subtotal-display');
        var discountRow = document.querySelector('#cb-discount-row');
        var discountDisp = document.querySelector('#cb-discount-display');
        var totalDisp = document.querySelector('#cb-total-display');

        var basePrice = parseFloat(document.getElementById('cb_base_price').value);
        var activeDiscount = 0;

        function updatePricing() {
            var sets = parseInt(setsDropdown.value) || 0;
            var subtotal = sets * basePrice;
            
            if (sets === 0) {
                activeDiscount = 0;
                promoInput.value = '';
                promoMsg.innerText = '';
            }

            var finalTotal = Math.max(0, subtotal - activeDiscount);
            subtotalDisp.innerText = 'AED ' + subtotal.toFixed(2);
            
            if (activeDiscount > 0) {
                discountRow.style.display = 'flex';
                discountDisp.innerText = '- AED ' + activeDiscount.toFixed(2);
            } else {
                discountRow.style.display = 'none';
            }
            totalDisp.innerText = 'AED ' + finalTotal.toFixed(2);
        }

        setsDropdown.addEventListener('change', function() {
            if (promoInput.value && activeDiscount > 0) { applyPromoBtn.click(); } else { updatePricing(); }
        });

        applyPromoBtn.addEventListener('click', function() {
            var promoCode = promoInput.value.trim();
            var sets = parseInt(setsDropdown.value) || 0;

            if (sets < 1) {
                promoMsg.style.color = '#991b1b'; promoMsg.innerText = 'Select a quantity first.'; return;
            }
            if (!promoCode) {
                promoMsg.style.color = '#991b1b'; promoMsg.innerText = 'Enter a code.';
                activeDiscount = 0; updatePricing(); return;
            }

            applyPromoBtn.innerText = '...'; applyPromoBtn.disabled = true;

            var formData = new URLSearchParams();
            formData.append('action', 'tcb_validate_promo');
            formData.append('promo', promoCode);
            formData.append('sets', sets);
            formData.append('cb_item_name', document.getElementById('cb_item_name').value);
            formData.append('cb_base_price', basePrice);
            formData.append('cb_price_hash', document.getElementById('cb_price_hash').value);

            fetch('/wp-admin/admin-ajax.php', { method: 'POST', body: formData })
            .then(res => res.json())
            .then(response => {
                if (response.success) {
                    activeDiscount = parseFloat(response.data.discount);
                    promoMsg.style.color = '#176b3b'; promoMsg.innerText = '✓ Applied!';
                } else {
                    activeDiscount = 0;
                    promoMsg.style.color = '#991b1b'; promoMsg.innerText = response.data;
                }
                updatePricing();
                applyPromoBtn.innerText = 'Apply'; applyPromoBtn.disabled = false;
            })
            .catch(err => {
                activeDiscount = 0; promoMsg.style.color = '#991b1b'; promoMsg.innerText = 'Connection error.';
                updatePricing(); applyPromoBtn.innerText = 'Apply'; applyPromoBtn.disabled = false;
            });
        });

        form.addEventListener('submit', function(e){
            e.preventDefault();
            if(!form.checkValidity()){ form.reportValidity(); return; }

            submitBtn.innerText = 'Redirecting...'; submitBtn.disabled = true;
            status.classList.remove('is-visible');

            var formData = new FormData(form);
            formData.append('action', 'tcb_process_turkey');

            fetch('/wp-admin/admin-ajax.php', { method: 'POST', body: formData })
            .then(res => res.json())
            .then(response => {
                if (response.success) { window.location.href = response.data.payment_url; } 
                else {
                    status.innerText = 'Error: ' + (response.data || 'Failed to connect.');
                    status.classList.add('is-visible'); status.style.background = '#fef2f2'; status.style.color = '#991b1b';
                    submitBtn.innerText = 'Place Order'; submitBtn.disabled = false;
                }
            })
            .catch(err => {
                status.innerText = 'Connection error. Please try again.';
                status.classList.add('is-visible'); status.style.background = '#fef2f2'; status.style.color = '#991b1b';
                submitBtn.innerText = 'Place Order'; submitBtn.disabled = false;
            });
        });
    }());
    </script>
    <?php
    return ob_get_clean();
}

// ==========================================
// 6. SERVER-SIDE SECURITY & MATH HELPERS
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
        // STATIC HTML FALLBACK: Allows Elementor custom block to function without strict hash
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
// 7. AJAX: FINAL PAYMENT PROCESSOR
// ==========================================
add_action('wp_ajax_nopriv_tcb_process_turkey', 'tcb_process_turkey_payment');
add_action('wp_ajax_tcb_process_turkey', 'tcb_process_turkey_payment');

function tcb_process_turkey_payment() {
    global $wpdb;
    
    $first_name = isset($_POST['firstName']) ? sanitize_text_field($_POST['firstName']) : '';
    $last_name  = isset($_POST['lastName']) ? sanitize_text_field($_POST['lastName']) : '';
    $email      = isset($_POST['email']) ? sanitize_email($_POST['email']) : '';
    $phone      = isset($_POST['phone']) ? sanitize_text_field($_POST['phone']) : '';
    $date       = isset($_POST['date']) ? sanitize_text_field($_POST['date']) : '';
    $notes      = isset($_POST['notes']) ? sanitize_textarea_field($_POST['notes']) : '';
    $sets       = isset($_POST['sets']) ? intval($_POST['sets']) : 0;
    $promo      = isset($_POST['promo']) ? strtoupper(sanitize_text_field($_POST['promo'])) : '';
    
    $item_name  = isset($_POST['cb_item_name']) ? sanitize_text_field($_POST['cb_item_name']) : '';
    $base_price = isset($_POST['cb_base_price']) ? floatval($_POST['cb_base_price']) : 0;
    $hash       = isset($_POST['cb_price_hash']) ? sanitize_text_field($_POST['cb_price_hash']) : '';

    $is_valid = false;
    if (!empty($hash)) {
        $is_valid = tcb_verify_checkout_payload($item_name, $base_price, $hash);
    } else {
        // STATIC HTML FALLBACK: Allows Elementor custom block to function without strict hash
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
// 8. VERIFICATION SHORTCODE & EMAIL FIRING
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
    $message .= "--- ITEM DETAILS ---\n";
    $message .= "Item: " . $order_data['item_name'] . "\n";
    $message .= "Quantity: " . $order_data['sets'] . "\n\n";
    
    $message .= "--- CUSTOMER DETAILS ---\n";
    $message .= "Name: " . $order_data['customer_name'] . "\n";
    $message .= "Email: " . $order_data['customer_email'] . "\n";
    $message .= "Phone: " . $order_data['customer_phone'] . "\n\n";
    
    $message .= "--- FULFILLMENT ---\n";
    $message .= "Preferred Date: " . $order_data['preferred_date'] . "\n";
    $message .= "Notes: " . ($order_data['notes'] ?: 'None provided') . "\n\n";
    
    $message .= "--- PAYMENT DETAILS ---\n";
    $message .= "Amount Paid: AED " . $order_data['amount'] . "\n";
    $message .= "Promo Code Used: " . ($order_data['promo_code'] ?: 'None') . "\n";
    $message .= "Gateway Ref: " . ($order_data['ngenius_ref'] ?: 'N/A (100% Free VIP)') . "\n";
    $message .= "Current Status: " . $order_data['status'] . "\n";
    
    $headers = array('Content-Type: text/plain; charset=UTF-8');
    wp_mail($to, $subject, $message, $headers);
}
