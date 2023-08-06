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

        public function setup_payment($lgl, $payment_type, $uid) {
			
			if (strcmp($payment_type, 'Membership') === 0) {
				
				$payment_types = $lgl->get_lgl_data('PAYMENT_TYPES');
				if ($payment_types) $payment_types = $payment_types->items;
				
				$gift_types = $lgl->get_lgl_data('GIFT_TYPES');
				if ($gift_types) $gift_types = $gift_types->items;
				//$lgl->debug('GIFTTYPES:' , $gift_types);
				
				$gift_categories = $lgl->get_lgl_data('GIFT_CATEGORIES');
				if ($gift_categories) $gift_categories = $gift_categories->items;
				//$lgl->debug('GIFT CATEGORIES:' , $gift_categories);
				
				$campaigns = $lgl->get_lgl_data('CAMPAIGNS');
				if ($campaigns) $campaigns = $campaigns->items;
				//$lgl->debug('CAMPAIGNS:' , $campaigns);
				
				$funds = $lgl->get_lgl_data('FUNDS');
				if ($funds) $funds = $funds->items;
				//$lgl->debug('FUNDS:' , $funds);
				
				$gift = $this->find_lgl_object_key('Other Income', 'name', $gift_types);
				$category = $this->find_lgl_object_key('Memberships', 'display_name', $gift_categories);
				$campaign = $this->find_lgl_object_key('Membership Fees', 'name', $campaigns);
				$fund = $this->find_lgl_object_key('Membership', 'name', $funds);
				
			} else if (strcmp($payment_type, 'Language Class') === 0) {
				
			} else {
				$lgl->debug('wrong payment type to lgl_setup_payment()');
			}
			
			if ($gift && $category && $campaign && $fund) {
				$p = array(
					"gift_type_id" => $gift->id,
					"gift_type_name" => $gift->name,
					"gift_category_id" => $category->id,
					"gift_category_name" => $category->display_name,
					"campaign_id" => $campaign->id,
					"campaign_name" => $campaign->name,
					"fund_id" => $fund->id,
					"fund_name" => $fund->name,
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
					"team_member" => ""
					
				);
				
			} else {
				$lgl->debug('Missing Necessary Payment Info', $gift->name);
			}
			
			if ($p) {
				return $p;
			} else {
				$lgl->debug('no payment to return in lgl_setup_payment()');
				return false;
			}
		}
        
    }
}