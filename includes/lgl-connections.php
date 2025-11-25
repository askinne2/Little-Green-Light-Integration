<?php
/**
* File Name: lgl-connections.php
* Version: 1.0
* Plugin URI:  https://github.com/askinne2/Little-Green-Light-API
* Description: This class manages the get/push/update operations for the API
* Author URI: http://github.com/askinne2
*/


if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly
}

require_once plugin_dir_path( __FILE__ ) . '../lgl-api.php';
require_once 'lgl-api-settings.php';
require_once 'lgl-wp-users.php';
require_once 'lgl-constituents.php';
require_once 'lgl-payments.php';
require_once 'lgl-relations-manager.php';

if (!class_exists("LGL_Connect")) {
	/**
	* class:   LGL_Connect
	* desc:    This class manages the get/push/update operations for the API
	*/
	class LGL_Connect
	{
		/**
		* Class instance
		*
		* @var null|LGL_Connect
		*/
		private static $instance = null;
		
        var $lgl;
        var $request_uri;
		var $args;
        var $lgl_current_object;
        var $new_constituent_flag;

		
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
			$this->lgl_current_object = (object) array(
				'items_count' => 0, 
				'data' => array()
			);			
		}

        public function debug($string, $data=NULL) {
			if (PLUGIN_DEBUG) {
				if (BUFFER) ob_start();
				printf('<h6 style="color: red;">%s</h3><pre>', $string);
				print_r($data);
				printf('</pre>');
				if (BUFFER) ob_get_clean();
				
				\UpstateInternational\LGL\LGL\Helper::getInstance()->debug($string, $data);
				
				
			}
		}
		
        public function set_request_uri($request_uri)
		{
			$this->request_uri = $request_uri;
		}
		
		public function set_request_args(string $api_key, $body=NULL)
		{
			$this->args = array(
				'headers' => array(
					'Authorization' => 'Bearer ' . $api_key,
					'Content-Type'  => 'application/json',
				),
				'body' => $body
			);
			if ($this->args['headers']['Authorization'] == NULL) {
				echo "<h5>Website cannot connect to LGL server. API Key needed.</h5>";
				return ob_get_clean();
			}
		}
		public function set_request_args_put(string $api_key, $body=NULL, $method=NULL)
		{
			$this->args = array(
				'headers' => array(
					'Authorization' => 'Bearer ' . $api_key,
					'Content-Type'  => 'application/json',
				),
				'body' => $body,
				'method' => $method
			);
			if ($this->args['headers']['Authorization'] == NULL) {
				echo "<h5>Website cannot connect to LGL server. API Key needed.</h5>";
				return ob_get_clean();
			}
		}
        /*
		* returns an generic GET request wrapper to LGL API
		*
		*/
		public function get_lgl_data($get_setting_flag, $lgl_id=NULL, $membership_id=NULL) 
		{
			// set up request arguments (API Key + Request URI)
			$lgl_settings = LGL_API_Settings::get_instance();
			$api_key = $lgl_settings->lgl_get_setting('api_key');
			$this->set_request_args($api_key);
			if ($get_setting_flag) {
				switch ($get_setting_flag) 
				{
					case 'CONSTITUENTS' ;
					$this->request_uri = $lgl_settings->lgl_get_setting('constituents_uri');
					break;
					
					case 'MEMBERSHIPS' ;
					$this->request_uri = $lgl_settings->lgl_get_setting('membership_levels_uri');
					break;
					
					case 'USER_MEMBERSHIP' ;
					$base = $lgl_settings->lgl_get_setting('constituents_uri');
					$endpoint = '/' . $lgl_id . '/memberships.json';
					$this->request_uri = $base . $endpoint;
					break;
					
					case 'SINGLE_MEMBERSHIP' ;
					if ($membership_id) {
						$base = 'https://api.littlegreenlight.com/api/v1/memberships';
						$endpoint = '/' . $membership_id . '.json';
						$this->request_uri = $base . $endpoint;
						break;
					}
					
					case 'EMAILS' ;
					$base = $lgl_settings->lgl_get_setting('constituents_uri');
					$endpoint = '/' . $lgl_id . '/email_addresses.json';
					$this->request_uri = $base . $endpoint;
					break;
					
					case 'SINGLE_CONSTITUENT' ;
					$base = $lgl_settings->lgl_get_setting('constituents_uri');
					$endpoint = '/' . $lgl_id . '.json';
					$this->request_uri = $base . $endpoint;
					break;
					
					case 'PAYMENT_TYPES' ;
					$base = 'https://api.littlegreenlight.com/api/v1';
					$endpoint = '/payment_types.json';
					$this->request_uri = $base . $endpoint;
					break;
					
					case 'GIFT_TYPES' ;
					$base = 'https://api.littlegreenlight.com/api/v1';
					$endpoint = '/gift_types.json';
					$this->request_uri = $base . $endpoint;
					break;
					
					case 'GIFT_CATEGORIES' ;
					$base = 'https://api.littlegreenlight.com/api/v1';
					$endpoint = '/gift_categories.json';
					$this->request_uri = $base . $endpoint;
					break;
					
					case 'CAMPAIGNS' ;
					$base = 'https://api.littlegreenlight.com/api/v1';
					$endpoint = '/campaigns.json';
					$this->request_uri = $base . $endpoint;
					break;
					
					case 'FUNDS' ;
					$base = 'https://api.littlegreenlight.com/api/v1';
					$endpoint = '/funds.json';
					$this->request_uri = $base . $endpoint;
					break;
					
					
					default ;
					$this->debug('INCORRECT get_setting_flag');
					return;
				}
			} else {
				$this->debug ('get_lgl_data() needs a setting flag!');
				return;
			}
			
			//$this->debug( 'get_lgl_data() request URI:', $this->request_uri);
			
			$raw_response = wp_remote_get($this->request_uri, $this->args);
			if (is_wp_error($raw_response) || '200' != wp_remote_retrieve_response_code($raw_response)) {
				$this->debug('get_lgl_data() error:', json_decode($raw_response['body']));			
				return false;
			}
			$response = json_decode(wp_remote_retrieve_body($raw_response));
			
			if (!empty($response)) {
				return $response;
			} else {
				$this->debug('No JSON to decode: ', $get_setting_flag . ' ' . $lgl_id);					
				return;
			}				
		} // end get_lgl_data()

        /** 
		* returns an generic GET request wrapper to LGL API
		*
		*/
		public function get_request($request_uri, $args = array())
		{
			$raw_response = wp_remote_get($request_uri, $args);
			if (is_wp_error($raw_response) || '200' != wp_remote_retrieve_response_code($raw_response)) {
				$this->debug('get_request() error:', json_decode($raw_response['body']));
				return false;
			}
			$response = json_decode(wp_remote_retrieve_body($raw_response));
			
			if (!empty($response)) {
				return $response;
			} else {
				$this->debug('No JSON to decode', NULL);					
				return;
			}				
		} // end get_request()

        		/*
		* returns an generic request wrapper to post content to LGL API
		*
		*/
		public function post_request($request_uri, $args = array())
		{
			$raw_response = wp_remote_post($request_uri, $args);
			if (is_wp_error($raw_response) || '200' != wp_remote_retrieve_response_code($raw_response)) {
				$error_message = is_wp_error($raw_response) ? $raw_response->get_error_message() : json_decode($raw_response['body']);
				$this->debug('post_request() error:', $error_message);
				return false;
			}
			$response = json_decode(wp_remote_retrieve_body($raw_response));
			
			if (!empty($response)) {
				return $response;
			} else {
				$this->debug('No JSON to decode', NULL);					
				return;
			}				
		} // end post_request()
        		/** 
		* recurring retrieves paginated data from LGL API
		*
		*/
		public function make_paginated_request($request_uri, $args = array())
		{
			
			$responses = array();
			$offset = isset($args['offset']) ? intval($args['offset']) : 0; // Get the initial offset from $args
			$this->debug($request_uri, NULL);
			
			
			do {
				$request_uri_with_offset = add_query_arg('offset', $offset, $request_uri);
				$this->debug($request_uri_with_offset, NULL);
				
				
				$raw_response = wp_remote_get($request_uri_with_offset, $args);
				if (is_wp_error($raw_response) || '200' != wp_remote_retrieve_response_code($raw_response)) {
					$this->debug('wp_error', $raw_response);
					return false;
				}
				
				$response = json_decode(wp_remote_retrieve_body($raw_response));
				if (empty($response)) {
					$this->debug('NO JSON to decode', NULL);
					
					return;
					
				} else {		
					// Merge the current response data into the $responses array
					$responses = array_merge($responses, $response->items);
					
					// Check if there are more items to fetch based on 'total_items' and 'limit'
					$results_offset = count($responses);
					$lgl_settings = LGL_API_Settings::get_instance();
					$results_limit = $lgl_settings->lgl_get_setting('results_limit');
					$remaining_items = $response->total_items - $results_offset;
					if ($remaining_items > 0) {
						$args['offset'] = $results_offset; // Set the offset for the next request
					} else {
						// If no more items, break the loop
						break;
					}
				}
				$offset += $results_limit;
			} while ($remaining_items > 0);
			
			// Create a final PHP object containing all paginated data
			$final_response = (object) array(
				'total_items' => count($responses),
				'data' => $responses,
			);
			
			return $final_response;
		} // end make_paginated_request()

		/** 
		* retrieves all constituent data from LGL API
		* @return lgl_current_object;
		*
		*/
		public function get_all_constituents() {
			// set up request arguments (API Key + Request URI)
			$lgl_settings = LGL_API_Settings::get_instance();
			$api_key = $lgl_settings->lgl_get_setting('api_key');
			$this->set_request_args($api_key);
			$offset = $lgl_settings->lgl_get_setting('results_offset');
			
			do {
				$this->request_uri = $lgl_settings->lgl_get_setting('constituents_uri')
				. '?limit=' . $lgl_settings->lgl_get_setting('results_limit')
				. '&offset=' . $offset;
				
				
				$response = $this->make_paginated_request($this->request_uri, $this->args);
				if ($response === null) {
					$this->debug('No Constitudent Data retrieved from Little Green Light', NULL);					
					break;
				}
				// Merge the current response data into the final response object
				if ($response && !empty($response->data)) {
					$this->lgl_current_object->data = array_merge($this->lgl_current_object->data, $response->data);
					$this->lgl_current_object->items_count = count($this->lgl_current_object->data);
				}
				
				// Update the offset for the next iteration
				$offset += $lgl_settings->lgl_get_setting('results_limit');
				
				// Check if there are more items to fetch based on the total_items and the number of items fetched so far
			} while ($response && count($this->lgl_current_object->data) < $response->total_items);
			
			/* DISPLAY OUTPUT 
			printf('<pre>') . print_r($this->lgl_current_object) . printf('</pre>');
			*/
			return $this->lgl_current_object;
			
		}
		/** 
		* retrieves all funds data from LGL API
		* @return lgl_current_object;
		*
		*/
		public function get_all_funds() {
			// set up request arguments (API Key + Request URI)
			$lgl_settings = LGL_API_Settings::get_instance();
			$api_key = $lgl_settings->lgl_get_setting('api_key');
			$this->set_request_args($api_key);
			$offset = 0;
			
			$base = 'https://api.littlegreenlight.com/api/v1';
			$endpoint = '/funds.json';
			//$this->request_uri = $base . $endpoint;

			do {
				$this->request_uri = $base . $endpoint  
				. '?limit=' . $lgl_settings->lgl_get_setting('results_limit')
				. '&offset=' . $offset;
				
				
				$response = $this->make_paginated_request($this->request_uri, $this->args);
				if ($response === null) {
					$this->debug('No Fund Data retrieved from Little Green Light', NULL);					
					break;
				}
				// Merge the current response data into the final response object
				if ($response && !empty($response->data)) {
					$this->lgl_current_object->data = array_merge($this->lgl_current_object->data, $response->data);
					$this->lgl_current_object->items_count = count($this->lgl_current_object->data);
				}
				
				// Update the offset for the next iteration
				$offset += $lgl_settings->lgl_get_setting('results_limit');
				
				// Check if there are more items to fetch based on the total_items and the number of items fetched so far
			} while ($response && count($this->lgl_current_object->data) < $response->total_items);
			
			/* DISPLAY OUTPUT */
			//$this->debug('get_all_funds() returning: ', $this->lgl_current_object);
			
			return $this->lgl_current_object->data;
			
		}

        		/** 
		* retrieves all constituent data from LGL API
		* @var name = FIRST %20 LAST - note the %20 is hardcoded!
		* @return TRUE if found | FALSE if no match
		*
		*/
		public function lgl_search_by_name($name, $email) {
			
			// set up request arguments (API Key + Request URI)
			$lgl_settings = LGL_API_Settings::get_instance();
			$api_key = $lgl_settings->lgl_get_setting('api_key');
			$this->set_request_args($api_key);
			$this->request_uri = $lgl_settings->lgl_get_setting('constituents_uri');
			
			$search_query = '/search.json?q%5B%5D=name%3D' . $name;			
			$this->request_uri = $this->request_uri . $search_query; 
			
			$this->debug('searching for', $name);
			$result = $this->get_request($this->request_uri, $this->args);
			
			/* we have a name match, now search by email address */
			if ($result && $result->items_count > 0) {
				$this->debug('NAME MATCH');
				
				foreach ($result->items as $result) {
					$result_emails = $this->get_lgl_data('EMAILS', $result->id);
					//$this->debug('result_emails', $result_emails);
					if ($result_emails) {
						foreach ($result_emails->items as $result_email) {
							if (strcmp($result_email->address, $email) === 0) {
								$this->debug('EMAIL MATCH', $result_email->address . '    '. $result->id);
								// return LGL ID of matching constituent
								return $result->id;
							}
						}
					} 	
				}		
				$this->debug('no match on name or email, lgl_search()');
				return FALSE;
			} else {
				$this->debug('no match on name or email, lgl_search()');
				return FALSE;
			}		
		}
		
		
		
		public function lgl_add_constituent($user_id, $parent_uid=NULL) {
			
			$constituent = LGL_Constituents::get_instance();			
			if (!$parent_uid) {
				$this->debug("********  REGULAR USER REGISTRATION  ********");
				$constituent->set_data($user_id, false);
			} else if ($parent_uid) {
				$constituent->set_data($user_id, true);
				$parent_lgl_id = get_user_meta($parent_uid, 'lgl_id', true);
				$constituent->set_membership($user_id, 'Child Member of LGL User: ' . $parent_lgl_id, 'CHILD', $parent_uid);
			}
			
			printf('<h2>%s %s</h2>', $constituent->personal_data->first_name, $constituent->personal_data->last_name);
			$name = $constituent->personal_data->first_name . '%20' . $constituent->personal_data->last_name;
			$email = $constituent->email_data->address;
			
			$existing_contact_id = $this->lgl_search_by_name($name, $email);
			if ($existing_contact_id === FALSE) {
				
				$this->debug('UNIQUE CONTACT ********');
				$personal_content = json_encode($constituent->personal_data);
				
				$lgl_settings = LGL_API_Settings::get_instance();
				$this->request_uri = $lgl_settings->lgl_get_setting('constituents_uri');
				$api_key = $lgl_settings->lgl_get_setting('api_key');
				
				$this->set_request_args($api_key, $personal_content);
				
				$response = $this->post_request($this->request_uri, $this->args, $personal_content);
				if ($response) {
					/* success now add the other fields */
					$this->debug("NEW CONTACT", $response);
					$new_constituent_id = $response->id;
					
					$this->lgl_add_object($response->id, $constituent->email_data, 'email_addresses.json' );
					$this->lgl_add_object($response->id, $constituent->phone_data, 'phone_numbers.json' );
					$this->lgl_add_object($response->id, $constituent->address_data, 'street_addresses.json');
					$this->lgl_add_object($response->id, $constituent->membership_data, 'memberships.json');
					
					$this->new_constituent_flag = true;
					return $new_constituent_id;
					
				} else {
					$this->debug('failed to insert NEW CONTACT');
					return false;
				}
			} else if ($existing_contact_id) {
				$this->debug('EXISTING CONTACT ********');

				$this->new_constituent_flag = false;
				return $existing_contact_id;
				
			}
		}
		
		/**
		* lgl_add_object()
		*
		* @var lgl_id 	| ID of user to add data to in Little Green Light
		* @var data 	| data to add
		* @var uri 		| API endpoint URI
		* @return lgl_id upon success
		*/
		public function lgl_add_object($lgl_id, $data, $uri) {
			
			// set up request arguments (API Key + Request URI)
			$lgl_settings = LGL_API_Settings::get_instance();
			$api_key = $lgl_settings->lgl_get_setting('api_key');
			//			$this->set_request_args($api_key);
			
			$constituent_uri = $lgl_settings->lgl_get_setting('constituents_uri');			
			
			$endpoint = '/' . $lgl_id . '/' . $uri;
			
			$this->request_uri = $constituent_uri . $endpoint;
			$this->debug('', $this->request_uri);
			//$this->debug('lgl_add_object() data: ', $data);
			
			$this->set_request_args($api_key, json_encode($data));
			$response = $this->post_request($this->request_uri, $this->args);
			if ($response) {
				/* success now add the other fields */
				$this->debug("DATA ADDED", $response);
				return $response;
			} else {
				$this->debug("adding " . $uri . " FAILURE");
				return false;
			}		
		}
		
		/**
		* lgl_update_object()
		*
		* @var lgl_id 	| ID of user to add data to in Little Green Light
		* @var data 	| data to add
		* @var uri 		| API endpoint URI
		* @return lgl_id upon success
		*/
		public function lgl_update_object($lgl_id, $data, $uri, $flag=null, $membership_id=null) {
			
			// set up request arguments (API Key + Request URI)
			$lgl_settings = LGL_API_Settings::get_instance();
			$api_key = $lgl_settings->lgl_get_setting('api_key');
			
			if (!$flag) {
				$constituent_uri = $lgl_settings->lgl_get_setting('constituents_uri');			
				$endpoint = '/' . $lgl_id . $uri;
				$this->request_uri = $constituent_uri . $endpoint;
				
			} else if (strcmp($flag, 'MEMBERSHIPS') === 0) {
				if ($membership_id) {
					$base = 'https://api.littlegreenlight.com/api/v1/memberships';
					$endpoint = '/' . $membership_id . '.json';
					$this->request_uri = $base . $endpoint;
				}	
			}			
			
			$this->debug('', $this->request_uri);
			
			$this->set_request_args_put($api_key, json_encode($data), 'PUT');
			$response = $this->post_request($this->request_uri, $this->args);
			if ($response) {
				/* success now add the other fields */
				$this->debug("DATA ADDED", $response);
				return $response;
			} else {
				$this->debug("adding " . $uri . " FAILURE");
				return false;
			}		
		}
		
		/**
		* lgl_add_membership_payment()
		*
		* @var lgl_id 	| ID of new user in Little Green Light
		* @var request 	| data to add from Registration Form
		* @return lgl_payment_id contains ID of newly added payment object in LGL
		*/
		public function lgl_add_membership_payment($lgl_id, $request, $payment_type=NULL) {
			
			if (!$lgl_id || !$request) {
				$this->debug('No LGL object or request in lgl_add_membership_payment', $lgl_id . $request);
				return;
			}
			
			$new_order_id = $request['inserted_post_id'];
			$price = $request['price'];
			$date = date('Y-m-d', $request['current_date']);
			
			$lgl_payments = LGL_Payments::get_instance();
			$p = $lgl_payments->setup_membership_payment($this, $new_order_id, $price, $date, $payment_type);
			//$this->debug('Payment Info ------', $p);
			
			$lgl_payment_id = $this->lgl_add_object($lgl_id, $p, 'gifts.json');
			return $lgl_payment_id;			
		}
		
    }
}