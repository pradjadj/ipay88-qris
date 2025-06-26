<?php
/**
 * Plugin Name: iPay88 QRIS Gateway
 * Plugin URI: https://sgnet.co.id
 * Description: iPay88 Payment Gateway with QRIS for WooCommerce - Display QR directly on checkout page
 * Version: 1.1
 * Author: Pradja DJ
 * Author URI: https://sgnet.co.id
 */

defined('ABSPATH') or exit;

// Add settings link
add_filter('plugin_action_links_' . plugin_basename(__FILE__), 'ipay88_qris_gateway_plugin_action_links');
function ipay88_qris_gateway_plugin_action_links($links) {
    $settings_link = '<a href="' . admin_url('admin.php?page=wc-settings&tab=checkout&section=ipay88_qris') . '">Settings</a>';
    array_unshift($links, $settings_link);
    return $links;
}

// Add payment gateway
add_filter('woocommerce_payment_gateways', 'ipay88_qris_gateway_add_gateway_class');
function ipay88_qris_gateway_add_gateway_class($gateways) {
    $gateways[] = 'WC_Gateway_IPay88_QRIS';
    return $gateways;
}

// Initialize gateway
add_action('woocommerce_loaded', 'ipay88_qris_gateway_init_gateway_class');
function ipay88_qris_gateway_init_gateway_class() {
    class WC_Gateway_IPay88_QRIS extends WC_Payment_Gateway {
        
        private $merchant_key;
        private $merchant_code;
        private $environment;
        private $payment_id = '120'; // QRIS Dynamic
        private $expiry_minutes;
        private $check_interval = 5; // Interval cek pembayaran dalam detik
        private $status_after_payment;
        
        public function __construct() {
            // Prevent session start warning
            if (!headers_sent() && !session_id()) {
                @session_start();
            }

            $this->id = 'ipay88_qris';
            $this->has_fields = true;
            $this->method_title = 'iPay88 QRIS';
            $this->method_description = 'Terima pembayaran via QRIS melalui iPay88 tanpa redirect dari halaman checkout.';
            
            $this->init_form_fields();
            $this->init_settings();
            
            $this->title = $this->get_option('title');
            $this->description = $this->get_option('description');
            $this->merchant_key = $this->get_option('merchant_key');
            $this->merchant_code = $this->get_option('merchant_code');
            $this->environment = $this->get_option('environment');
            $this->expiry_minutes = $this->get_option('expiry_minutes', 10);
            $this->status_after_payment = $this->get_option('status_after_payment', 'processing');
            
            add_action('woocommerce_update_options_payment_gateways_' . $this->id, array($this, 'process_admin_options'));
            add_action('woocommerce_thankyou_' . $this->id, array($this, 'thankyou_page'));
            add_action('woocommerce_api_wc_gateway_ipay88_qris', array($this, 'handle_ipay88_response'));
            
            // AJAX handler
            add_action('wp_ajax_ipay88_qris_check_payment', array($this, 'check_payment_status'));
            add_action('wp_ajax_nopriv_ipay88_qris_check_payment', array($this, 'check_payment_status'));
            
            if (!$this->is_valid_for_use()) {
                $this->enabled = 'no';
            }
        }
        
        public function is_valid_for_use() {
            return in_array(get_woocommerce_currency(), array('IDR'));
        }
        
        public function admin_options() {
            if ($this->is_valid_for_use()) {
                parent::admin_options();
            } else {
                echo '<div class="inline error"><p><strong>Gateway Disabled</strong>: iPay88 QRIS tidak mendukung mata uang toko Anda.</p></div>';
            }
        }
        
        public function init_form_fields() {
            $this->form_fields = array(
                'enabled' => array(
                    'title'       => 'Aktif/Nonaktif',
                    'label'       => 'Aktifkan iPay88 QRIS',
                    'type'        => 'checkbox',
                    'description' => '',
                    'default'     => 'no'
                ),
                'title' => array(
                    'title'       => 'Judul',
                    'type'        => 'text',
                    'description' => 'Judul metode pembayaran yang dilihat pelanggan.',
                    'default'     => 'QRIS via iPay88',
                    'desc_tip'    => true,
                ),
                'description' => array(
                    'title'       => 'Deskripsi',
                    'type'        => 'textarea',
                    'description' => 'Deskripsi metode pembayaran yang dilihat pelanggan.',
                    'default'     => 'Bayar dengan QRIS melalui iPay88. Scan QR code yang muncul setelah klik Place Order.',
                ),
                'merchant_code' => array(
                    'title'       => 'Merchant Code',
                    'type'        => 'text',
                    'description' => 'Merchant Code diberikan oleh iPay88.',
                    'default'     => '',
                    'desc_tip'    => true,
                ),
                'merchant_key' => array(
                    'title'       => 'Merchant Key',
                    'type'        => 'text',
                    'description' => 'Merchant Key diberikan oleh iPay88.',
                    'default'     => '',
                    'desc_tip'    => true,
                ),
                'expiry_minutes' => array(
                    'title'       => 'Waktu Kedaluwarsa (menit)',
                    'type'        => 'number',
                    'description' => 'Waktu kedaluwarsa transaksi dalam menit.',
                    'default'     => '10',
                    'desc_tip'    => true,
                    'custom_attributes' => array(
                        'min'  => '1',
                        'step' => '1'
                    )
                ),
                'status_after_payment' => array(
                    'title'       => 'Status Setelah Pembayaran',
                    'type'        => 'select',
                    'class'       => 'wc-enhanced-select',
                    'description' => 'Status order setelah pembayaran berhasil.',
                    'default'     => 'processing',
                    'options'     => array(
                        'processing' => 'Processing',
                        'completed'  => 'Completed'
                    )
                ),
                'environment' => array(
                    'title'       => 'Environment',
                    'type'        => 'select',
                    'class'       => 'wc-enhanced-select',
                    'description' => 'Pilih environment untuk transaksi.',
                    'default'     => 'sandbox',
                    'desc_tip'    => true,
                    'options'     => array(
                        'sandbox'    => 'Sandbox',
                        'production' => 'Production'
                    )
                ),
                'debug' => array(
                    'title'       => 'Debug Log',
                    'type'        => 'checkbox',
                    'label'       => 'Aktifkan logging',
                    'default'     => 'no',
                    'description' => 'Log iPay88 QRIS events di <a href="' . esc_url(admin_url('admin.php?page=wc-status&tab=logs')) . '">System Status</a>',
                ),
            );
        }
        
        public function process_payment($order_id) {
            $order = wc_get_order($order_id);
            
            // Cek apakah sudah ada transaksi sebelumnya
            if (!$order->get_meta('_ipay88_ref_no')) {
                $order->update_status('pending', 'Menunggu pembayaran QRIS');
                $order->update_meta_data('_ipay88_expiry', time() + ($this->expiry_minutes * 60));
                $order->save();
            }
            
            wc_reduce_stock_levels($order_id);
            WC()->cart->empty_cart();
            
            return array(
                'result'    => 'success',
                'redirect' => $this->get_return_url($order)
            );
        }
        
        public function thankyou_page($order_id) {
            $order = wc_get_order($order_id);
            
            if ($order->get_payment_method() === $this->id) {
                if ($order->is_paid()) {
                    // Show payment received message if already paid
                    echo '<div class="ipay88-qris-container" style="text-align: center; margin: 20px 0;">';
                    echo '<div class="woocommerce-message" style="font-size: 1.2em; padding: 15px; background-color: #f5f5f5; border-left: 4px solid #46b450;">';
                    echo 'Pembayaran Diterima';
                    echo '</div>';
                    echo '</div>';
                } elseif ($order->has_status('pending')) {
                    // Cek apakah sudah ada transaksi sebelumnya
                    if (!$order->get_meta('_ipay88_ref_no')) {
                        $this->generate_ipay88_request($order);
                    } else {
                        $this->display_qr_code($order->get_meta('_ipay88_qr_content'), $order);
                    }
                }
            }
        }
        
        private function generate_product_description($order) {
            $product_names = array();
            foreach ($order->get_items() as $item) {
                $product_names[] = $item->get_name();
            }
            return implode(', ', $product_names);
        }
        
        private function generate_ipay88_request($order) {
            $merchant_key = $this->merchant_key;
            $merchant_code = $this->merchant_code;
            $ref_no = date('Ymd') . '-' . $order->get_id();
            $amount = number_format($order->get_total(), 0, '', '');
            $currency = 'IDR';
            $expiry_date = date('Y-m-d H:i:s', time() + ($this->expiry_minutes * 60));
            
            $signature_string = '||' . $merchant_key . '||' . $merchant_code . '||' . $ref_no . '||' . $amount . '||' . $currency . '||';
            $signature = hash('sha256', $signature_string);
            
            $this->log('Signature Generation Details:');
            $this->log('MerchantKey: ' . $merchant_key);
            $this->log('MerchantCode: ' . $merchant_code);
            $this->log('RefNo: ' . $ref_no);
            $this->log('Amount: ' . $amount);
            $this->log('Currency: ' . $currency);
            $this->log('Signature String: ' . $signature_string);
            $this->log('Generated Signature: ' . $signature);
            
            $request_data = array(
                'APIVersion' => '2.0',
                'MerchantCode' => $merchant_code,
                'PaymentId' => $this->payment_id,
                'Currency' => $currency,
                'RefNo' => $ref_no,
                'Amount' => $amount,
                'ProdDesc' => $this->generate_product_description($order),
                'RequestType' => 'SEAMLESS',
                'UserName' => $order->get_billing_first_name() . ' ' . $order->get_billing_last_name(),
                'UserEmail' => $order->get_billing_email(),
                'UserContact' => $order->get_billing_phone(),
                'Remark' => '',
                'Lang' => 'UTF-8',
                'ResponseURL' => add_query_arg('wc-api', 'WC_Gateway_IPay88_QRIS', home_url('/')),
                'BackendURL' => add_query_arg('wc-api', 'WC_Gateway_IPay88_QRIS', home_url('/')),
                'TransactionExpiryDate' => $expiry_date,
                'Signature' => $signature
            );
            
            $endpoint = ($this->environment === 'production') 
                ? 'https://payment.ipay88.co.id/ePayment/WebService/PaymentAPI/Checkout'
                : 'https://sandbox.ipay88.co.id/ePayment/WebService/PaymentAPI/Checkout';
            
            $this->log('iPay88 Request: ' . print_r($request_data, true));
            
            $response = wp_remote_post($endpoint, array(
                'headers' => array('Content-Type' => 'application/json'),
                'body' => json_encode($request_data),
                'timeout' => 60
            ));
            
            if (is_wp_error($response)) {
                $this->log('iPay88 Request Error: ' . $response->get_error_message());
                wc_add_notice('Error processing payment. Please try again.', 'error');
                return;
            }
            
            $response_body = wp_remote_retrieve_body($response);
            $response_data = json_decode($response_body, true);
            
            $this->log('iPay88 Response: ' . print_r($response_data, true));
            
            if (isset($response_data['Code']) && $response_data['Code'] === '1') {
                $order->update_meta_data('_ipay88_checkout_id', $response_data['CheckoutID']);
                $order->update_meta_data('_ipay88_ref_no', $ref_no);
                $order->update_meta_data('_ipay88_qr_content', $response_data['VirtualAccountAssigned']);
                $order->update_meta_data('_ipay88_expiry', strtotime($expiry_date));
                $order->save();
                
                $this->display_qr_code($response_data['VirtualAccountAssigned'], $order);
            } else {
                $error_message = isset($response_data['Message']) ? $response_data['Message'] : 'Unknown error occurred';
                $this->log('iPay88 Error: ' . $error_message);
                wc_add_notice('Error processing payment: ' . $error_message, 'error');
            }
        }
        
        private function display_qr_code($qr_content, $order) {
            wp_enqueue_script('qrcodejs', 'https://cdn.jsdelivr.net/npm/qrcodejs@1.0.0/qrcode.min.js', array(), '1.0.0', true);
            
            $expiry_timestamp = $order->get_meta('_ipay88_expiry');
            $current_time = time();
            $time_left = max(0, $expiry_timestamp - $current_time);
            
            echo '<div class="ipay88-qris-container" style="text-align: center; margin: 20px 0;">';
            
            // Check if order is already paid
            if ($order->is_paid()) {
                echo '<div class="woocommerce-message" style="font-size: 1.2em; padding: 15px; background-color: #f5f5f5; border-left: 4px solid #46b450;">';
                echo 'Pembayaran Diterima';
                echo '</div>';
            } else {
                echo '<div id="ipay88-qris-content">';
                echo '<h3>Scan QRIS untuk Pembayaran</h3>';
                
                echo '<div id="ipay88-qris-qrcode" style="display: inline-block; margin: 0 auto;"></div>';
                
                echo '<div id="ipay88-countdown" style="margin: 15px 0; font-weight: bold; color: #d63638;">';
                echo 'Selesaikan pembayaran dalam: <span id="ipay88-countdown-timer">' . gmdate("i:s", $time_left) . '</span>';
                echo '</div>';
                
                echo '<p>Silakan scan QR code di atas menggunakan aplikasi mobile banking atau e-wallet yang mendukung QRIS.</p>';
                
                echo '<button id="ipay88-refresh-page" class="button alt" style="margin: 10px 0; padding: 10px 20px; font-size: 1.2em;">';
                echo 'Refresh Status Pembayaran';
                echo '</button>';
                echo '</div>';
                
                echo '<div id="ipay88-payment-status" style="margin-top: 20px;"></div>';
            }
            
            $ajax_nonce = wp_create_nonce('ipay88_qris_check_payment_nonce');
            
            if (!$order->is_paid()) {
                wc_enqueue_js('
                    jQuery(document).ready(function($) {
                        new QRCode(document.getElementById("ipay88-qris-qrcode"), {
                            text: "' . esc_js($qr_content) . '",
                            width: 300,
                            height: 300,
                            colorDark : "#000000",
                            colorLight : "#ffffff",
                            correctLevel : QRCode.CorrectLevel.H
                        });
                        
                        var countdown = ' . $time_left . ';
                        var countdownElement = $("#ipay88-countdown-timer");
                        var countdownInterval;
                        var isPaid = false;
                        
                        function updateCountdown() {
                            countdown--;
                            if (countdown <= 0) {
                                clearInterval(countdownInterval);
                                $("#ipay88-qris-content").hide();
                                $("#ipay88-payment-status").html("<div class=\"woocommerce-error\" style=\"text-align: center;\">Waktu pembayaran telah habis. Silakan buat pesanan baru.</div>");
                                return;
                            }
                            
                            var minutes = Math.floor(countdown / 60);
                            var seconds = countdown % 60;
                            countdownElement.text((minutes < 10 ? "0" + minutes : minutes) + ":" + (seconds < 10 ? "0" + seconds : seconds));
                        }
                        
                        countdownInterval = setInterval(updateCountdown, 1000);
                        
                        $("#ipay88-refresh-page").on("click", function() {
                            window.location.reload();
                        });
                        
                        function checkPaymentStatus() {
                            if (isPaid) return;
                            
                            $.ajax({
                                url: "' . admin_url('admin-ajax.php') . '",
                                type: "POST",
                                data: {
                                    action: "ipay88_qris_check_payment",
                                    order_id: "' . $order->get_id() . '",
                                    security: "' . $ajax_nonce . '"
                                },
                                dataType: "json",
                                success: function(response) {
                                    if (response.success && response.data.paid) {
                                        isPaid = true;
                                        clearInterval(countdownInterval);
                                        $("#ipay88-qris-content").hide();
                                        $("#ipay88-payment-status").html("<div class=\"woocommerce-message\" style=\"text-align: center;\">Pembayaran berhasil diterima! Halaman akan diperbarui...</div>");
                                        setTimeout(function() {
                                            window.location.reload();
                                        }, 2000);
                                    }
                                }
                            });
                        }
                        
                        setInterval(checkPaymentStatus, ' . ($this->check_interval * 1000) . ');
                        checkPaymentStatus();
                    });
                ');
            }
            
            echo '</div>';
        }

        public function handle_ipay88_response() {
            if ($_SERVER['REQUEST_METHOD'] === 'POST') {
                $raw_post = file_get_contents('php://input');
                $response = json_decode($raw_post, true);
                
                $this->log('BackendPost Received: ' . print_r($response, true));
                
                if ($response && isset($response['RefNo'])) {
                    $ref_parts = explode('-', $response['RefNo']);
                    $order_id = end($ref_parts);
                    $order = wc_get_order($order_id);
                    
                    if ($order && $order->get_payment_method() === $this->id) {
                        $signature_string = '||' . $this->merchant_key . '||' . $response['MerchantCode'] . '||' . 
                                          $response['PaymentId'] . '||' . $response['RefNo'] . '||' . 
                                          $response['Amount'] . '||' . $response['Currency'] . '||' . 
                                          $response['TransactionStatus'] . '||';
                        $generated_signature = hash('sha256', $signature_string);
                        
                        $this->log('BackendPost Signature Verification:');
                        $this->log('Received Signature: ' . $response['Signature']);
                        $this->log('Generated Signature: ' . $generated_signature);
                        
                        if ($generated_signature === $response['Signature']) {
                            if ($response['TransactionStatus'] === '1') {
                                // Update order status based on setting
                                $new_status = $this->status_after_payment;
                                $order->update_status($new_status, 'Pembayaran berhasil via iPay88. TransID: ' . $response['TransId']);
                                
                                // Add payment complete
                                $order->payment_complete($response['TransId']);
                                
                                header('Content-Type: application/json');
                                echo json_encode(array(
                                    'Code' => '1',
                                    'Message' => array(
                                        'English' => 'Status Received',
                                        'Indonesian' => 'Pembayaran diterima'
                                    )
                                ));
                                exit;
                            }
                        } else {
                            $this->log('BackendPost Signature Mismatch');
                            header('Content-Type: application/json');
                            echo json_encode(array(
                                'Code' => '0',
                                'Message' => array(
                                    'English' => 'Invalid Signature',
                                    'Indonesian' => 'Signature tidak valid'
                                )
                            ));
                            exit;
                        }
                    }
                }
            }
            
            // Default response if validation fails
            header('Content-Type: application/json');
            echo json_encode(array(
                'Code' => '0',
                'Message' => array(
                    'English' => 'Invalid Request',
                    'Indonesian' => 'Permintaan tidak valid'
                )
            ));
            exit;
        }
        
        public function check_payment_status() {
            check_ajax_referer('ipay88_qris_check_payment_nonce', 'security');
            
            $order_id = isset($_POST['order_id']) ? absint($_POST['order_id']) : 0;
            $order = wc_get_order($order_id);
            
            if (!$order) {
                wp_send_json_error(array('message' => 'Order tidak ditemukan'));
                return;
            }
            
            $paid = $order->is_paid();
            $expired = false;
            
            // Check if order has expired and is still pending
            $expiry = $order->get_meta('_ipay88_expiry');
            if (!$paid && $expiry && time() > $expiry && $order->has_status('pending')) {
                // Add random delay between 1-5 seconds to avoid race conditions
                sleep(rand(1, 5));
                
                // Restore stock
                wc_increase_stock_levels($order->get_id());
                
                // Cancel order
                $order->update_status(
                    'cancelled', 
                    sprintf(
                        'Pembayaran QRIS kadaluarsa (waktu habis: %s)',
                        date('Y-m-d H:i:s', $expiry)
                    )
                );
                
                $this->log(sprintf(
                    'Order #%d cancelled due to QRIS payment expiry',
                    $order->get_id()
                ));
                
                $expired = true;
            }
            
            wp_send_json_success(array(
                'paid' => $paid,
                'expired' => $expired,
                'message' => $paid ? 'Pembayaran telah diterima' : 
                            ($expired ? 'Waktu pembayaran telah habis' : 'Menunggu pembayaran')
            ));
        }
        
        private function log($message) {
            if ($this->get_option('debug') === 'yes') {
                $logger = wc_get_logger();
                $logger->debug($message, array('source' => 'ipay88-qris-gateway'));
            }
        }
    }
}