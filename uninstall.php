<?php
/**
 * Uninstall script for iPay88 QRIS Gateway plugin
 */

if (!defined('WP_UNINSTALL_PLUGIN')) {
    exit;
}

// Delete WooCommerce payment gateway settings option
delete_option('woocommerce_ipay88_qris_settings');

// Clean up order meta keys starting with '_ipay88_'
global $wpdb;

// Get all order IDs that have meta keys starting with '_ipay88_'
$order_ids = $wpdb->get_col("
    SELECT DISTINCT post_id FROM {$wpdb->postmeta}
    WHERE meta_key LIKE '\_ipay88\_%'
");

// Delete all meta keys starting with '_ipay88_' for these orders
if (!empty($order_ids)) {
    foreach ($order_ids as $order_id) {
        $wpdb->query(
            $wpdb->prepare(
                "DELETE FROM {$wpdb->postmeta} WHERE post_id = %d AND meta_key LIKE '\_ipay88\_%'",
                $order_id
            )
        );
    }
}
