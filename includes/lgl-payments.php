<?php
/**
* File Name => lgl-payments.php
* Version => 1.0
* Plugin URI =>  https =>//github.com/askinne2/Little-Green-Light-API
* Description => This class defines a Class for LGL Payment Management
* Author URI => http =>//github.com/askinne2
*/

if (!defined('ABSPATH')) {
	exit; // Exit if accessed directly
}


define('PAYMENT_FILE_PATH', plugin_dir_path( __FILE__ ));
require_once PAYMENT_FILE_PATH . '../lgl-api.php';
require_once PAYMENT_FILE_PATH . 'lgl-api-settings.php';

if (!class_exists("LGL_Payments")) {
	/**
	* class =>   Little Green Light_API_Payments
	* desc =>    Creates the settings pages for the Little Green Light API plugin
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

			$payment_types = $this->lgl->get_lgl_data('PAYMENT_TYPES');
			if ($payment_types) $payment_types = $payment_types->items;
			//$lgl->debug('PAYMENT TYPE:' , $payment_types);

			
			$gift_types = $this->lgl->get_lgl_data('GIFT_TYPES');
			if ($gift_types) $gift_types = $gift_types->items;
			//$lgl->debug('GIFTTYPES:' , $gift_types);
			
			$gift_categories = $this->lgl->get_lgl_data('GIFT_CATEGORIES');
			if ($gift_categories) $gift_categories = $gift_categories->items;
			//$lgl->debug('GIFT CATEGORIES:' , $gift_categories);
			
			$campaigns = $this->lgl->get_lgl_data('CAMPAIGNS');
			if ($campaigns) $campaigns = $campaigns->items;
			//$lgl->debug('CAMPAIGNS:' , $campaigns);
			
			$funds = $this->lgl->get_lgl_data('FUNDS');
			if ($funds) $funds = $funds->items;
			//$lgl->debug('FUNDS:' , $funds);
			
			$this->payment = $this->find_lgl_object_key('Paypal', 'name', $payment_types);
			$this->gift = $this->find_lgl_object_key('Other Income', 'name', $gift_types);
			$this->category = $this->find_lgl_object_key('Memberships', 'display_name', $gift_categories);
			$this->campaign = $this->find_lgl_object_key('Membership Fees', 'name', $campaigns);
			$this->fund = $this->find_lgl_object_key('Membership', 'name', $funds);
			
		}
		
		public function debug($string, $data=NULL) {
			if (PLUGIN_DEBUG) {
				printf('<h6 style="color: red;">%s</h3><pre>', $string);
				print_r($data);
				printf('</pre>');
			}
		}
		
		public function find_lgl_object_key($object_name, $column_name, $object) {
			if (!$object) {
				$this->debug('No object to search in - find_lgl_object_key()');
				return;
			}
			$type_object = (object) array();
			$key = array_search($object_name, array_column($object, $column_name));
			if ($key !== false) {
				//$this->debug($object_name . ' key', $object[$key]);	
				$type_object = $object[$key];
			} else {
				$this->debug('Couldn\'t find Array key');
			}
			
			if ($type_object) {
				return $type_object;
			}
		}
		
		/**
		* setup_membership payment()
		* @var $lgl - LGL_API() class object
		* @var $payment_type - string to identify type of payment
		* @var $uid - WP User ID
		* @var $order_meta - WP JetEngine Order Meta
		* @return $p - payment_data object
		*/
		public function setup_membership_payment($lgl, $uid, $post_order, $order_meta) {
			
			if ($this->gift && $this->category && $this->campaign && $this->fund) {
				$p = array(
					"external_id" => $post_order->ID,
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
					"received_amount" => implode($order_meta['price']),
					"received_date" => $post_order->post_date,
					"payment_type_id" => $this->payment->id,
					"payment_type_name" => $this->payment->name,
					"check_number" => "",
					"deductible_amount" => implode($order_meta['price']),
					"note" => "Website Registration",
					"ack_template_name" => "",
					"deposit_date" => $post_order->post_date,
					"deposited_amount" => implode($order_meta['price']),
					"parent_gift_id" => 0,
					"parent_external_id" => 0,
					"team_member" => ""
					
				);
				
			} else {
				$lgl->debug('Missing Necessary Payment Info', $this->gift->name);
			}
			
			if ($p) {
				$this->payment_data = $p;
				return $p;
			} else {
				$lgl->debug('no payment to return in lgl_setup_payment()');
				return false;
			}
		}
		
	}
}