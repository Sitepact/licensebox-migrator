<?php
/*
Plugin Name: License Migrator
Description: Migrates licenses from an old platform to a new platform using LicenseBoxInternalAPI.
Version: 1.0
Author: Ryon Whyte
Text Domain: license-migrator

*/
require_once plugin_dir_path(__FILE__) . 'licenseBoxInternalAPI.php';

add_action( 'init', 'wpdocs_load_textdomain' );
 
/**
 * Load plugin textdomain.
 */
function wpdocs_load_textdomain() {
  load_plugin_textdomain( 'license-migrator', false, false ); 
}


   function write_to_log ( $log )  {
      if ( is_array( $log ) || is_object( $log ) ) {
         error_log( print_r( $log, true ) );
      } else {
         error_log( $log );
      }
   }

add_action( 'wp_loaded','migrate_license_batch' );

// Function to migrate licenses
function migrate_license_batch() {
    global $wpdb;

    // Define the table name
    $table_name = $wpdb->prefix . 'woocommerce_software_licenses';

    // Fetch the next license that hasn't been migrated yet
    $license = $wpdb->get_row("SELECT * FROM $table_name WHERE migrated = 0 LIMIT 1");

    if (!$license) {
        // No more licenses to migrate
        return;
    }

    $order_id = $license->order_id;
    $activation_email = $license->activation_email;
    $license_key = $license->license_key;
    $activations_limit = $license->activations_limit;
    
    write_to_log($license_key);

    // Get the order object
    $order = wc_get_order($order_id);

    if (!$order) {
        // If order not found, mark license as migrated to avoid future processing
        $wpdb->update($table_name, array('migrated' => 1), array('key_id' => $license->key_id));
        return;
    }

    // Get the subscription associated with the order
    $subscriptions = wcs_get_subscriptions_for_order($order_id);

    if (!$subscriptions) {
        // If no subscriptions found, mark license as migrated to avoid future processing
        $wpdb->update($table_name, array('migrated' => 1), array('key_id' => $license->key_id));
        return;
    }

    $api = new LicenseBoxInternalAPI();

    foreach ($subscriptions as $subscription) {
        // Check if the subscription status is active or pending-cancellation
        if (in_array($subscription->get_status(), ['active', 'pending-cancel', 'cancelled'])) {
            // Get the expiry date of the subscription
            $expiry_date = $subscription->get_date('next_payment');
            if(empty($expiry_date)){
                $expiry_date = $subscription->get_date('end');
            }

            
            // Prepare data for the API call
            $data = array(
                "product_id" => '8A9817F4',
                "license_code" => $license_key,
                "invoice_number" => (!empty($order_id)) ? "#" . strval($order_id) : null,
                "client_name" => $activation_email,
                "client_email" => $activation_email,
                "expiry_date" => (!empty($expiry_date)) ? $expiry_date : null,
                "updates_end_date" => (!empty($expiry_date)) ? $expiry_date : null,
                "license_parallel_uses" => intval($activations_limit)
            );
            

            // Call the API to create the license
            $create_license_response = $api->create_license('ADD YOUR OWN', $data, $license_key);

            if (!empty($create_license_response['license_code'])) {
                // Add the license key as meta to the order
                foreach ($order->get_items() as $item){
                    $prod_id = $item->get_variation_id() ? $item->get_variation_id() : $item->get_product_id();
                    update_post_meta($order_id, '_license_'.$prod_id, $create_license_response['license_code']);
                }

                // Mark the license as migrated in the custom table
                $wpdb->update($table_name, array('migrated' => 1), array('key_id' => $license->key_id));

                // Add a note to the order
                $order->add_order_note( sprintf( __("License key %s migrated successfully.", 'license-migrator'), $create_license_response['license_code']) );

            } else {
                $order->add_order_note( sprintf( __("Failed to migrate license key for order ID %s .", 'license-migrator'), $order_id) );            
            }
            
        } else {
            // Mark license as migrated if the subscription is not active or pending-cancellation
            $wpdb->update($table_name, array('migrated' => 1), array('key_id' => $license->key_id));
        }
    }
}
