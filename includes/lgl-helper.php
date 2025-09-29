<?php
/**
* File Name => lgl-helper.php
* Version => 1.0
* Plugin URI =>  https =>//github.com/askinne2/Little-Green-Light-API
* Description => This class defines helper functions for the LGL_API class
* Author URI => http =>//github.com/askinne2
*/

if (!defined('ABSPATH')) {
	exit; // Exit if accessed directly
}
require_once plugin_dir_path( __FILE__ ) . '../lgl-api.php';

define('HELPER_LOG', plugin_dir_path( __FILE__ ) . 'logs/lgl-api.log');

define('HELPER_PLUGIN_DEBUG', false);

if (!class_exists("LGL_Helper")) {
	/**
	* class:   LGL_Helper
	* desc:    This class manages the get/push/update operations for the API
	*/
	class LGL_Helper
	{
		/**
		* Class instance
		*
		* @var null|LGL_Connect
		*/
		private static $instance = null;
		
		/**
		* Get instance
		*
		* @return LGL_Connect
		*/
		public static function get_instance() {
			if (is_null(self::$instance)) {
				self::$instance = new self();
			}
			return self::$instance;
		}
		
		public function __construct()
		{
			
			
		}
		
		public function debug($string, $data=NULL) {
			
			if (HELPER_PLUGIN_DEBUG) {
				/*
				if (BUFFER) ob_start();
				printf('<h6 style="color: red;">%s</h3><pre>', $string);
				print_r($data);
				printf('</pre>');
				if (BUFFER) ob_get_clean();
				*/
				
				error_log($string . ' ' . print_r($data, true));
				/*
				$log_file = HELPER_LOG; // Set the path to your log file
				
				$log_message = date('Y-m-d H:i:s') . ' - ' . $string . ' ' . print_r($data, true) . PHP_EOL;
				
				// Write the log message to the file
				file_put_contents($log_file, $log_message, FILE_APPEND);
				
				if (defined('HELPER_PLUGIN_DEBUG') && HELPER_PLUGIN_DEBUG) {
					// Check if it's a local environment
					if ($_SERVER['REMOTE_ADDR'] === '127.0.0.1' || $_SERVER['REMOTE_ADDR'] === '::1') {
						// Log to both PHP error log and file log
						error_log($string . ' ' . print_r($data, true));
						$log_message = date('Y-m-d H:i:s') . ' - ' . $string . ' ' . print_r($data, true) . PHP_EOL;
						file_put_contents(HELPER_LOG, $log_message, FILE_APPEND);
					} else {
						// Log only to the file log
						$log_message = date('Y-m-d H:i:s') . ' - ' . $string . ' ' . print_r($data, true) . PHP_EOL;
						file_put_contents(HELPER_LOG, $log_message, FILE_APPEND);
					}
				}
					*/
				}
				
				
				
			}
			
			
			public function remove_transient() {
				delete_transient('lgl_membership_payment');
				delete_transient('lgl_class_payment');
			}
			
			/**
			* Fix the User Meta Box value not being checked
			* on initial registration
			*/
			
			public function fix_membership_radio($uid, $membership_level) {
				if ($membership_level === 'Individual Membership') {
					update_user_meta($uid, 'user-membership-type', 75);
				} else if ($membership_level === 'Family Membership') {
					update_user_meta($uid, 'user-membership-type', 100);
				} else if ($membership_level === 'Patron Membership') {
					update_user_meta($uid, 'user-membership-type', 200);
				} else if ($membership_level === 'Patron Family Membership') {
					update_user_meta($uid, 'user-membership-type', 250);
				} else if ($membership_level === 'Daily Plan') {
					update_user_meta($uid, 'user-membership-type', 5);
				}
			}
			
			public function ui_membership_name_to_price($membership_name) {
				if (!$membership_name) {
					$this->debug('bad membership name, ui_membership_name_to_price(): ', $membership_name);
					return 0;
				} else {
					switch ($membership_name) 
					{
						case 'Individual Membership' ;
						return 75;
						
						case 'Family Membership' ;
						return 100;
						
						case 'Patron Membership' ;
						return 200;
						
						case 'Patron Family Membership' ;
						return 250;
						
						case 'Dail Membership Daily Plan' ;
						return 5;
						
						default ;
						$this->debug('bad membership name, ui_membership_name_to_price(): ', $membership_name);
						return;
					}
				}
			}
			public function ui_membership_WC_name_to_LGL($membership_name) {
				if (!$membership_name) {
					$this->debug('bad membership name, ui_membership_WC_name_to_LGL(): ', $membership_name);
					return 0;
				} else {
					switch ($membership_name) 
					{
						case 'Membership - Individual' ;
						return 'Individual Membership';
						
						case 'Membership - Family';
						return 'Family Membership' ;
						
						case 'Membership - Patron';
						return 'Patron Membership' ;
						
						case 'Membership - Patron Family' ;
						return 'Patron Family Membership' ;
						
						case 'Daily Membership - Daily' ;
						return 'Daily Plan';
						
						default ;
						$this->debug('bad membership name, ui_membership_WC_name_to_LGL(): ', $membership_name);
						return;
					}
				}
			}
			
			public function ui_membership_price_to_name($membership_price) {
				if (!$membership_price) {
					$this->debug('bad membership name, ui_membership_name_to_price(): ', $membership_price);
					return '';
				} else {
					switch ($membership_price) 
					{
						case 75; 
						return 'Individual Membership';
						
						case 100;
						return 'Family Membership' ;
						
						case 200;
						return 'Patron Membership' ;
						
						case 250;
						return 'Patron Family Membership' ;
						
						case 5;
						return 'Daily Plan' ;
						
						default ;
						$this->debug('bad membership price, ui_membership_price_to_name(): ', $membership_price);
						return;
					}
				}
			}
			
			public function change_user_role($current_user_id, $old_role, $new_role) {
				
				// Check your condition, for example, you want to change the role for users with a specific capability
				if ($current_user_id) {
					// Load the user object
					$user = new WP_User($current_user_id);
					$current_roles = $user->roles;
					
					foreach ($current_roles as $role) {
						if (strcmp('ui_inactive_member', $role) === 0) {
							$user->remove_role($role);
						}
					}			
					// Set the new role
					//$new_role = $old_role . ', '. $new_role;
					$user->add_role($new_role);
					$this->debug('changing roles ', $current_user_id . ' ' . $old_role . '+' . $new_role);
				}
				
			}
			
			public function writeFundsCSV($filename, $funds) {
				// CSV file path
				$csvFilePath = plugin_dir_path( __FILE__ ) . $filename . '.csv';
				
				// Open file for writing
				$csvFile = fopen($csvFilePath, 'w');
				
				// Write header (keys of the first object)
				$header = array_keys((array)$funds[0]);
				fputcsv($csvFile, $header);
				
				// Write rows
				foreach ($funds as $object) {
					$row=[];
					foreach($object as $value) {
						$row[] = $value;
					}
					fputcsv($csvFile, $row);
				}
				
				// Close the file
				fclose($csvFile);
				
				echo 'CSV file created successfully.';
			}


			function get_variation_name($product_variation_id) {
				// Get the product variation object
				$product_variation = wc_get_product($product_variation_id);
			
				if ($product_variation && $product_variation->is_type('variation')) {
					// Get the full name of the variation
					$full_name = $product_variation->get_name();
			
					// Split the name by hyphens
					$name_parts = explode(' - ', $full_name);
			
					// Retrieve the part of the name after the second hyphen
					if (count($name_parts) >= 3) {
						$variation_name = $name_parts[2];
						return $variation_name;
					} else {
						// If the name does not contain enough parts, return the full name
						return $full_name;
					}
				} else {
					return null;
				}
			}
			
			
			function get_parent_product_name($variation_id) {
				// Get the product variation object
				$variation = wc_get_product($variation_id);
				
				if ($variation && $variation->is_type('variation')) {
					// Get the parent product ID
					$parent_id = $variation->get_parent_id();
					
					// Get the parent product object
					$parent_product = wc_get_product($parent_id);
					
					if ($parent_product) {
						// Get and return the parent product name
						return $parent_product->get_name();
					} else {
						return 'Parent product not found';
					}
				} else {
					return 'Invalid product variation ID';
				}
			}

			function get_total_products_purchased($order_id) {
				$order = wc_get_order($order_id);
				$total_quantity = 0;
			
				if ($order) {
					foreach ($order->get_items() as $item) {
						$total_quantity += $item->get_quantity();
					}
				}
			
				return $total_quantity;
			}

			function get_total_event_products_purchased($order_id) {
				$order = wc_get_order($order_id);
				$total_event_quantity = 0;
			
				if ($order) {
					foreach ($order->get_items() as $item) {
						$product_id = $item->get_product_id();
						if (has_term('events', 'product_cat', $product_id)) {
							$total_event_quantity += $item->get_quantity();
						}
					}
				}
			
				return $total_event_quantity;
			}


			function get_variation_price($product_variation_id) {
				// Get the product variation object
				$product_variation = wc_get_product($product_variation_id);
			
				if ($product_variation && $product_variation->is_type('variation')) {
					// Get the price of the variation
					$variation_price = $product_variation->get_price();
					return $variation_price;
				} else {
					return null;
				}
			}


			function is_cart_mixed() {
				$cart = WC()->cart->get_cart();
				$parent_products = array();
			
				foreach ($cart as $cart_item_key => $cart_item) {
					$product_id = $cart_item['product_id'];
					$variation_id = $cart_item['variation_id'];
			
					if ($variation_id) {
						// If it's a variation, get the parent product ID
						$parent_id = wp_get_post_parent_id($variation_id);
						if (!$parent_id) {
							$parent_id = $product_id; // Fallback in case wp_get_post_parent_id() returns false
						}
			
						// Add the variation ID to the parent product array
						if (!isset($parent_products[$parent_id])) {
							$parent_products[$parent_id] = array();
						}
			
						$parent_products[$parent_id][] = $variation_id;
					}
				}
			
				// Check if any parent product has more than one variation in the cart
				foreach ($parent_products as $variations) {
					if (count($variations) > 1) {
						return true; // The cart is mixed
					}
				}
			
				return false; // The cart is not mixed
			}

			function is_order_mixed($order_id) {
				$order = wc_get_order($order_id);
				$parent_products = array();
			
				foreach ($order->get_items() as $item_id => $item) {
					$product_id = $item->get_product_id();
					$variation_id = $item->get_variation_id();
			
					if ($variation_id) {
						// If it's a variation, get the parent product ID
						$parent_id = wp_get_post_parent_id($variation_id);
						if (!$parent_id) {
							$parent_id = $product_id; // Fallback in case wp_get_post_parent_id() returns false
						}
			
						// Add the variation ID to the parent product array
						if (!isset($parent_products[$parent_id])) {
							$parent_products[$parent_id] = array();
						}
			
						$parent_products[$parent_id][] = $variation_id;
					}
				}
			
				// Check if any parent product has more than one variation in the order
				foreach ($parent_products as $variations) {
					if (count($variations) > 1) {
						return true; // The order is mixed
					}
				}
			
				return false; // The order is not mixed
			}
		} // end class definition
	}