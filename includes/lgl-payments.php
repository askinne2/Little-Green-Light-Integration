<?php
/**
* File Name: lgl-payments.php
* Version: 1.0
* Plugin URI: https://github.com/askinne2/Little-Green-Light-API
* Description: This class defines a Class for LGL Payment Management
* Author URI: http://github.com/askinne2
*/

if (!defined('ABSPATH')) {
	exit; // Exit if accessed directly
}


define('PAYMENT_FILE_PATH', plugin_dir_path( __FILE__ ));
require_once PAYMENT_FILE_PATH . '../lgl-api.php';
require_once PAYMENT_FILE_PATH . 'lgl-api-settings.php';

if (!class_exists("LGL_Payments")) {
	/**
	* class:   Little Green Light_API_Payments
	* desc:    Creates the settings pages for the Little Green Light API plugin
	*/
	class LGL_Payments
	{
		/**
		* Class instance
		*
		* @var null|LGL_Constituents
		*/
		private static $instance = null;
		
		var $payment_data;
		
		var $lgl_fundraising = array(); /* for setting transient
		* and includes the fields below
		*/
		
		var $payment = array();
		var $gift = array();
		var $category = array();
		var $campaign = array();
		var $fund = array();
		
		var $lgl;
		
		/**
		* Get instance
		*
		* @return LGL_Constituents
		*/
		public static function get_instance() {
			if (is_null(self::$instance)) {
				self::$instance = new self();
			}
			
			return self::$instance;
		}
		/**
		* Add prerequisites if needed
		*/
		public function __construct() {
			
			
			$this->payment_data = (object) array(
				"external_id" => 0,
				"is_anon" => false,
				"gift_type_id" => 0,
				"gift_type_name" => "",
				"gift_category_id" => 0,
				"gift_category_name" => "",
				"campaign_id" => 0,
				"campaign_name" => "",
				"fund_id" => 0,
				"fund_name" => "",
				"appeal_id" => 0,
				"appeal_name" => "",
				"event_id" => 0,
				"event_name" => "",
				"received_amount" => 0,
				"received_date" => "date",
				"payment_type_id" => 0,
				"payment_type_name" => "",
				"check_number" => "",
				"deductible_amount" => 0,
				"note" => "",
				"ack_template_name" => "",
				"deposit_date" => "date",
				"deposited_amount" => 0,
				"parent_gift_id" => 0,
				"parent_external_id" => 0,
				"team_member" => "",
			);
			
			$this->lgl = LGL_API::get_instance();
			$this->payment = (object) array();
			$this->gift = (object) array();
			$this->category = (object) array();
			$this->campaign = (object) array();
			$this->fund = (object) array();
			
			$this->lgl_fundraising = (object) array();			
		}
		
		public function debug($string, $data=NULL) {
			if (PLUGIN_DEBUG) {
				printf('<h6 style="color: red;">%s</h3><pre>', $string);
				print_r($data);
				printf('</pre>');
			}
		}
		
		public function find_lgl_object_key($itemNameToFind, $listColumnName, $list) {
			if (!$list) {
				$this->lgl->helper->warning('LGL Payments: Empty list provided for search', ['item' => $itemNameToFind]); 
				return; 
			}
			
			$foundItem = (object) array();
			
			$itemIndex = array_search($itemNameToFind, array_column($list, $listColumnName));
			
			if ($itemIndex !== false) {
				$foundItem = $list[$itemIndex];
			} else {
				$this->lgl->helper->warning('LGL Payments: Could not find payment type', ['item' => $itemNameToFind, 'column' => $listColumnName]);
			}
			
			if ($foundItem) {
				return $foundItem;
			}
		}
		
		public function retrieve_payment_types($method='Memberships', $fund=NULL, $payment_type=NULL, $lgl_class_id=NULL, $payment_gateway=NULL)
		{
			if (strcmp($method, 'Memberships') === 0) {
				$payment_types = $this->lgl->connection->get_lgl_data('PAYMENT_TYPES');
				if ($payment_types) $payment_types = $payment_types->items;				
				
				$gift_types = $this->lgl->connection->get_lgl_data('GIFT_TYPES');
				if ($gift_types) $gift_types = $gift_types->items;
				
				$gift_categories = $this->lgl->connection->get_lgl_data('GIFT_CATEGORIES');
				if ($gift_categories) $gift_categories = $gift_categories->items;
				
				$campaigns = $this->lgl->connection->get_lgl_data('CAMPAIGNS');
				if ($campaigns) $campaigns = $campaigns->items;
				
				$transient = get_transient('lgl_funds');
				if (!empty($transient)) {
					$funds = $transient;
				} else {
					$funds = $this->lgl->connection->get_all_funds();
					set_transient('lgl_funds', $funds, DAY_IN_SECONDS);
				}
				
				
				if (!$payment_type || $payment_type === 'credit-card') {
					if ($payment_gateway === 'stripe_cc') {
						//$this->payment = $this->find_lgl_object_key('Stripe', 'name', $payment_types);
						$this->payment = $this->find_lgl_object_key('Stripe', 'name', $payment_types);
					} else if ($payment_gateway === 'paypal') {
						//$this->payment = $this->find_lgl_object_key('Paypal', 'name', $payment_types);
						$this->payment = $this->find_lgl_object_key('PayPal', 'name', $payment_types);
					}
				} else if ($payment_type === 'check' || $payment_type === 'cash') {
					$this->payment = $this->find_lgl_object_key(ucfirst($payment_type), 'name', $payment_types);
				}
				
				
				$this->gift = $this->find_lgl_object_key('Other Income', 'name', $gift_types);
				//$this->category = $this->find_lgl_object_key('Memberships', 'display_name', $gift_categories);
				$this->category = $this->find_lgl_object_key('Donation', 'display_name', $gift_categories);
				$this->campaign = $this->find_lgl_object_key('Membership Fees', 'name', $campaigns);
				$this->fund = $this->find_lgl_object_key('Membership', 'name', $funds);
				
				$this->lgl_fundraising = (object) array (
					'payment_type' => $this->payment,
					'gift_type' => $this->gift,
					'gift_category' => $this->category,
					'campaign' => $this->campaign,
					'fund' => $this->fund,
				);
				
				
				// Save the final response in the transient for caching
				//set_transient('lgl_membership_payment', $this->lgl_fundraising, DAY_IN_SECONDS);
				
			} else if (strcmp($method, 'Language Class') === 0 && $fund != NULL) {
				
				$payment_types = $this->lgl->connection->get_lgl_data('PAYMENT_TYPES');
				if ($payment_types) $payment_types = $payment_types->items;				
				
				$gift_types = $this->lgl->connection->get_lgl_data('GIFT_TYPES');
				if ($gift_types) $gift_types = $gift_types->items;
				
				$gift_categories = $this->lgl->connection->get_lgl_data('GIFT_CATEGORIES');
				if ($gift_categories) $gift_categories = $gift_categories->items;
				
				$campaigns = $this->lgl->connection->get_lgl_data('CAMPAIGNS');
				if ($campaigns) $campaigns = $campaigns->items;
				
				
				$transient = get_transient('lgl_funds');
				if (!empty($transient)) {
					$funds = $transient;
				} else {
					$funds = $this->lgl->connection->get_all_funds();
					set_transient('lgl_funds', $funds, DAY_IN_SECONDS);
				}
				
				if (!$payment_type || $payment_type === 'credit-card') {
					if ($payment_gateway === 'stripe_cc') {
						//$this->payment = $this->find_lgl_object_key('Stripe', 'name', $payment_types);
						$this->payment = $this->find_lgl_object_key('Stripe', 'name', $payment_types);
					} else if ($payment_gateway === 'paypal') {
						//$this->payment = $this->find_lgl_object_key('Paypal', 'name', $payment_types);
						$this->payment = $this->find_lgl_object_key('PayPal', 'name', $payment_types);
					}
				} else if ($payment_type === 'check' || $payment_type === 'cash') {
					$this->payment = $this->find_lgl_object_key(ucfirst($payment_type), 'name', $payment_types);
				}
				
				$this->gift = $this->find_lgl_object_key('Other Income', 'name', $gift_types);
				$this->category = $this->find_lgl_object_key('Entry Fee', 'display_name', $gift_categories);
				$this->campaign = $this->find_lgl_object_key('Language Class', 'name', $campaigns);
				$this->fund = $this->find_lgl_object_key((int)$lgl_class_id, 'id', $funds);
				
				$this->lgl_fundraising = (object) array (
					'payment_type' => $this->payment,
					'gift_type' => $this->gift,
					'gift_category' => $this->category,
					'campaign' => $this->campaign,
					'fund' => $this->fund,
				);
				
				
				
				
			} else if (strcmp($method, 'Event') === 0 && $fund != NULL) {
				
				$payment_types = $this->lgl->connection->get_lgl_data('PAYMENT_TYPES');
				if ($payment_types) $payment_types = $payment_types->items;				
				
				$gift_types = $this->lgl->connection->get_lgl_data('GIFT_TYPES');
				if ($gift_types) $gift_types = $gift_types->items;
				
				$gift_categories = $this->lgl->connection->get_lgl_data('GIFT_CATEGORIES');
				if ($gift_categories) $gift_categories = $gift_categories->items;
				
				$campaigns = $this->lgl->connection->get_lgl_data('CAMPAIGNS');
				if ($campaigns) $campaigns = $campaigns->items;
				
				
				$transient = get_transient('lgl_funds');
				if (!empty($transient)) {
					$funds = $transient;
				} else {
					$funds = $this->lgl->connection->get_all_funds();
					set_transient('lgl_funds', $funds, DAY_IN_SECONDS);
				}
				
				
				if (!$payment_type || $payment_type === 'credit-card') {
					if ($payment_gateway === 'stripe_cc') {
						//$this->payment = $this->find_lgl_object_key('Stripe', 'name', $payment_types);
						$this->payment = $this->find_lgl_object_key('Stripe', 'name', $payment_types);
					} else if ($payment_gateway === 'paypal') {
						//$this->payment = $this->find_lgl_object_key('Paypal', 'name', $payment_types);
						$this->payment = $this->find_lgl_object_key('PayPal', 'name', $payment_types);
					}
				} else if ($payment_type === 'check' || $payment_type === 'cash') {
					$this->payment = $this->find_lgl_object_key(ucfirst($payment_type), 'name', $payment_types);
				}
				
				$this->gift = $this->find_lgl_object_key('Other Income', 'name', $gift_types);
				$this->category = $this->find_lgl_object_key('Entry Fee', 'display_name', $gift_categories);
				$this->campaign = $this->find_lgl_object_key('WACU Programming', 'name', $campaigns);
				$this->fund = $this->find_lgl_object_key((int)$lgl_class_id, 'id', $funds);
				
				$this->lgl_fundraising = (object) array (
					'payment_type' => $this->payment,
					'gift_type' => $this->gift,
					'gift_category' => $this->category,
					'campaign' => $this->campaign,
					'fund' => $this->fund,
				);
				
				
				
				
			} else {
				$this->lgl->helper->error('LGL Payments: Improper call to retrieve_payment_types()', ['method' => $method, 'fund' => $fund]);
			}
		}
		
		/**
		* setup_membership payment()
		* @var lgl - LGL_API() class object
		* @var order_id - inserted post from JetForm
		* @var price - price of membership
		* @var date - date order was placed
		* @return $p - payment_data object
		*/
		
		public function setup_membership_payment($lgl, $order_id, $price, $date, $payment_type=NULL) {
			
			
			// Retrieve the payment method ID (e.g., 'paypal', 'stripe').
			$order = wc_get_order($order_id);
			
			// Retrieve the payment method ID (e.g., 'paypal', 'stripe').
			$payment_gateway = $order->get_payment_method();
			
			$transient = get_transient('lgl_membership_payment');
			if (!empty($transient)) {
				$this->payment = $transient->payment_type;
				$this->gift = $transient->gift_type;
				$this->category = $transient->gift_category;
				$this->campaign = $transient->campaign;
				$this->fund = $transient->fund;
			} else {
				$this->retrieve_payment_types('Memberships', null, $payment_type, null, $payment_gateway);
			}
			
			if ($this->gift && $this->category && $this->campaign && $this->fund) {
				
				$order = wc_get_order($order_id);
				$customer_notes = $order->get_customer_note();
				
				$notes = 'Website Registration, Order #' . $order_id;
				if ($customer_notes) {
					$notes = $notes . '  | Additional Notes: ' . $customer_notes;
				}
				
				$p = array(
					"external_id" => $order_id,
					"is_anon" => false,
					"gift_type_id" => $this->gift->id,
					"gift_type_name" => $this->gift->name,
					"gift_category_id" => $this->category->id,
					"gift_category_name" => $this->category->display_name,
					"campaign_id" => $this->campaign->id,
					"campaign_name" => $this->campaign->name,
					"fund_id" => $this->fund->id,
					"fund_name" => $this->fund->name,
					"appeal_id" => 0,
					"appeal_name" => "",
					"event_id" => 0,
					"event_name" => "",
					"received_amount" => $price,
					"received_date" => $date,
					"payment_type_id" => $this->payment->id,
					"payment_type_name" => $this->payment->name,
					"check_number" => "",
					"deductible_amount" => $price,
					"note" => $notes,
					"ack_template_name" => "",
					"deposit_date" => $date,
					"deposited_amount" => $price,
					"parent_gift_id" => 0,
					"parent_external_id" => 0,
					"team_member" => ""
					
				);
				
			} else {
				$lgl->helper->error('LGL Payments: Missing necessary payment info for membership payment', ['order_id' => $order_id, 'gift' => isset($this->gift) ? $this->gift->name : null]);
			}
			
			if ($p) {
				$this->payment_data = $p;
				$this->lgl->helper->info('LGL Payments: Membership payment setup completed', ['order_id' => $order_id, 'amount' => $price]);
				return $p;
			} else {
				$lgl->helper->error('LGL Payments: Failed to setup membership payment', ['order_id' => $order_id]);
				return false;
			}
		}
		/**
		* setup_class_payment()
		* @var lgl - LGL_API() class object
		* @var order_id - inserted post from JetForm
		* @var price - price of membership
		* @var date - date order was placed
		* @return $p - payment_data object
		*/
		
		public function setup_class_payment($lgl, $order_id, $price, $date, $class_type, $lgl_class_id) {
			
			$order = wc_get_order($order_id);
			$customer_notes = $order->get_customer_note();
			
			$notes = 'Website Registration, Order #' . $order_id;
			if ($customer_notes) {
				$notes = $notes . '  | Additional Notes: ' . $customer_notes;
			}
			
			// Retrieve the payment method ID (e.g., 'paypal', 'stripe').
			$payment_gateway = $order->get_payment_method();
			
			$transient = get_transient('lgl_class_payment');
			if (!empty($transient)) {
				$this->payment = $transient->payment_type;
				$this->gift = $transient->gift_type;
				$this->category = $transient->gift_category;
				$this->campaign = $transient->campaign;
				$funds = $this->lgl->connection->get_lgl_data('FUNDS');
				if ($funds) $funds = $funds->items;
				$this->fund = $this->find_lgl_object_key((int)$lgl_class_id, 'id', $funds);
			} else {
				$this->retrieve_payment_types('Language Class', $class_type, null, $lgl_class_id, $payment_gateway);
			}
			
			
			/*
			$this->fund->id = $class_type['id'];
			$this->fund->name = $class_type['name'];
			$lgl->helper->debug('fund ID & name: ', $this->fund->id . '     ' . $this->fund->name);
			*/
			
			if ($this->gift && $this->category && $this->campaign && $this->fund) {
				$p = array(
					"external_id" => $order_id,
					"is_anon" => false,
					"gift_type_id" => $this->gift->id,
					"gift_type_name" => $this->gift->name,
					"gift_category_id" => $this->category->id,
					"gift_category_name" => $this->category->display_name,
					"campaign_id" => $this->campaign->id,
					"campaign_name" => $this->campaign->name,
					"fund_id" => $this->fund->id,
					"fund_name" => $this->fund->name,
					"appeal_id" => 0,
					"appeal_name" => "",
					"event_id" => 0,
					"event_name" => "",
					"received_amount" => $price,
					"received_date" => $date,
					"payment_type_id" => $this->payment->id,
					"payment_type_name" => $this->payment->name,
					"check_number" => "",
					"deductible_amount" => $price,
					"note" => $notes,
					"ack_template_name" => "",
					"deposit_date" => $date,
					"deposited_amount" => $price,
					"parent_gift_id" => 0,
					"parent_external_id" => 0,
					"team_member" => ""
					
				);
				
			} else {
				$lgl->helper->error('LGL Payments: Missing necessary payment info for class payment', ['order_id' => $order_id]);
			}
			
			if ($p) {
				$this->payment_data = $p;
				$this->lgl->helper->info('LGL Payments: Class payment setup completed', ['order_id' => $order_id, 'amount' => $price, 'class_id' => $lgl_class_id]);
				return $p;
			} else {
				$lgl->helper->error('LGL Payments: Failed to setup class payment', ['order_id' => $order_id]);
				return false;
			}
		}
		
		/**
		* setup_event_payment()
		* @var lgl - LGL_API() class object
		* @var order_id - inserted post from JetForm
		* @var price - price of membership
		* @var date - date order was placed
		* @return $p - payment_data object
		*/
		
		public function setup_event_payment($lgl, $order_id, $price, $date, $event_name, $lgl_class_id) {
			
			$order = wc_get_order($order_id);
			$customer_notes = $order->get_customer_note();
			
			$notes = 'Website Event Registration, Order #' . $order_id;
			if ($customer_notes) {
				$notes = $notes . '  | Additional Notes: ' . $customer_notes;
			}
			
			// Retrieve the payment method ID (e.g., 'paypal', 'stripe').
			$payment_gateway = $order->get_payment_method();
			
			$transient = get_transient('lgl_class_payment');
			if (!empty($transient)) {
				$this->payment = $transient->payment_type;
				$this->gift = $transient->gift_type;
				$this->category = $transient->gift_category;
				$this->campaign = $transient->campaign;
				$funds = $this->lgl->connection->get_lgl_data('FUNDS');
				if ($funds) $funds = $funds->items;
				$this->fund = $this->find_lgl_object_key((int)$lgl_class_id, 'id', $funds);
			} else {
				$this->retrieve_payment_types('Event', $event_name, null, $lgl_class_id, $payment_gateway);
			}
			
			
			/*
			$this->fund->id = $class_type['id'];
			$this->fund->name = $class_type['name'];
			$lgl->helper->debug('fund ID & name: ', $this->fund->id . '     ' . $this->fund->name);
			*/
			
			if ($this->gift && $this->category && $this->campaign && $this->fund) {
				$p = array(
					"external_id" => $order_id,
					"is_anon" => false,
					"gift_type_id" => $this->gift->id,
					"gift_type_name" => $this->gift->name,
					"gift_category_id" => $this->category->id,
					"gift_category_name" => $this->category->display_name,
					"campaign_id" => $this->campaign->id,
					"campaign_name" => $this->campaign->name,
					"fund_id" => $this->fund->id,
					"fund_name" => $this->fund->name,
					"appeal_id" => 0,
					"appeal_name" => "",
					"event_id" => 0,
					"event_name" => "",
					"received_amount" => $price,
					"received_date" => $date,
					"payment_type_id" => $this->payment->id,
					"payment_type_name" => $this->payment->name,
					"check_number" => "",
					"deductible_amount" => $price,
					"note" => $notes,
					"ack_template_name" => "",
					"deposit_date" => $date,
					"deposited_amount" => $price,
					"parent_gift_id" => 0,
					"parent_external_id" => 0,
					"team_member" => ""
					
				);
				
			} else {
				$lgl->helper->error('LGL Payments: Missing necessary payment info for event payment', ['order_id' => $order_id]);
			}
			
			if ($p) {
				$this->payment_data = $p;
				$this->lgl->helper->info('LGL Payments: Event payment setup completed', ['order_id' => $order_id, 'amount' => $price, 'event_name' => $event_name]);
				return $p;
			} else {
				$lgl->helper->error('LGL Payments: Failed to setup event payment', ['order_id' => $order_id]);
				return false;
			}
		}
		
		public function add_funds() {
			/* Map Rows and Loop Through Them */
			$rows   = array_map('str_getcsv', file(PAYMENT_FILE_PATH . '../json-examples/FundsExport2023-11-11-part2.csv'));
			$header = array_shift($rows);
			$funds    = array();
			foreach($rows as $row) {
				$funds[] = array_combine($header, $row);
			}
			
			foreach($funds as $fund) {
				$this->lgl->helper->debug('Fund: ', $fund['fund_name']);
				$uid = wp_get_current_user()->data->ID;
				$user_lgl_id = get_user_meta($uid, 'lgl_id', true);				
				$existing_user = $this->lgl->connection->get_lgl_data('SINGLE_CONSTITUENT', $user_lgl_id);
				if ($existing_user) {
					$lgl_id = $existing_user->id;
					$this->lgl->helper->debug('LGL USER EXISTS: ', $lgl_id);
					
					if ($lgl_id) {
						
						$p = $this->manual_setup_class_payment($this, $fund);
						$this->lgl->helper->debug('payment: ', $p);
						
						$lgl_payment_id = $this->lgl->connection->lgl_add_object($lgl_id, $p, 'gifts.json');
						
						
					} else {
						$this->lgl->helper->debug('Cannot find user with id, lgl_add_class_registration()', $lgl_id);
					}
				}
				
			}
		}
		
		public function manual_setup_class_payment($lgl, $fund) {
			
			$price = 1;
			$timestamp = get_user_meta(wp_get_current_user()->data->ID, 'user-membership-start-date', true);
			$prefix = 'GIFT'; // You can customize the prefix
			$unique_id = $prefix . uniqid(); // Generates a unique ID
			
			
			$date = date('Y-m-d', strtotime($timestamp));
			
			$p = array(
				"external_id" => $unique_id,
				"is_anon" => false,
				"gift_type_id" => 5,
				"gift_type_name" => 'Other Income',
				"gift_category_id" => 687,
				"gift_category_name" => 'Language Classes',
				"campaign_id" => 862,
				"campaign_name" => 'Language Class',
				"fund_id" => $fund['fund_id'],
				"fund_name" => $fund['fund_name'],
				"appeal_id" => 0,
				"appeal_name" => "",
				"event_id" => 0,
				"event_name" => "",
				"received_amount" => $price,
				"received_date" => $date,
				"payment_type_id" => 1537,
				"payment_type_name" => 'Paypal',
				"check_number" => "",
				"deductible_amount" => $price,
				"note" => "Website Registration",
				"ack_template_name" => "",
				"deposit_date" => $date,
				"deposited_amount" => $price,
				"parent_gift_id" => 0,
				"parent_external_id" => 0,
				"team_member" => ""
				
			);
			
			
			if ($p) {
				$this->payment_data = $p;
				return $p;
			} else {
				$lgl->helper->error('LGL Payments: Failed to setup manual class payment');
				return false;
			}
		}
		
	}
}