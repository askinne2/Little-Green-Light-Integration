<?php
/**
* @link              https://github.com/askinne2/Little-Green-Light-API
* @since             1.0.0
* @package           [lgl_api]
*
* @wordpress-plugin
* Plugin Name:       Little Green Light API
* Plugin URI:        https://github.com/askinne2/Little-Green-Light-API
*
* Description:       Creates a customizable api between website and a Little Green Light database account
*
*
* Version:           0.0.1
* Author:            Andrew Skinner
* Author URI:        https://www.21adsmedia.com
* License:           GPL-2.0+
* License URI:       http://www.gnu.org/licenses/gpl-2.0.txt
* Text Domain:       lgl_api
* Domain Path:       /languages
* GitHub Plugin URI: https://github.com/askinne2/Little-Green-Light-API

*/

if (!defined('ABSPATH')) {
	exit; // Exit if accessed directly
}



define('PLUGIN_DEBUG', TRUE);
define('REMOVE_TRANSIENT', true);
define('LOCAL_JSON', false);

require_once 'includes/lgl-api-includes.php';
require_once 'includes/lgl-api-settings.php';
require_once 'includes/lgl-wp-users.php';
require_once 'includes/lgl-constituents.php';
require_once 'includes/lgl-relations-manager.php';

add_action('template_redirect', 'lgl_shortcode', 10, );
function lgl_shortcode($response) {
	
	$lgl = LGL_API::get_instance();
	$lgl->shortcode_init();
	
}

if (!class_exists("LGL_API")) {
	/**
	* class:   Little Green Light_API_Settings
	* desc:    Creates the settings pages for the Little Green Light API plugin
	*/
	class LGL_API
	{
		/**
		* Class instance
		*
		* @var null|LGL_API
		*/
		private static $instance = null;
		var $membership_levels = array();
		
		/**
		* Get instance
		*
		* @return LGL_API_
		*/
		public static function get_instance() {
			if (is_null(self::$instance)) {
				self::$instance = new self();
			}
			return self::$instance;
		}
		
		
		const CRON_HOOK = 'lgl_cron_hook';
		
		var $request_uri;
		var $args;
		var $lgl_current_object;
		var $single_constituent;
		
		
		public function __construct()
		{
			add_action( 'jet-form-builder/custom-action/lgl-register-user', array($this, 'lgl_register_user'), 10, 2);
			
			$this->lgl_current_object = (object) array(
				'items_count' => 0, 
				'data' => array()
			);
			
			$this->single_constituent = LGL_Constituents::get_instance();
			
			
			
			
			// hook into custom post type actions and filters
			//add_action('trashed_post', array($this, 'delete_animal_post'));
			//add_filter('pre_get_posts', array($this, 'animals_change_posts_per_page'));
			//add_filter('template_include', array($this, 'shelterluv_archive_animal_template'), 9999);
			//add_filter('template_include', array($this, 'shelterluv_single_animal_template'), 9999);
			
			
			// functionality added for shortcode [animals_slideshow]
			
		}
		public function shortcode_init() {
			
			add_shortcode('lgl', array($this, 'run_update'));
		}
		
		/**
		* Hook into the WordPress activate hook
		*/
		public static function activate()
		{
			
			// Do something
			//Use wp_next_scheduled to check if the event is already scheduled
			$timestamp = wp_next_scheduled(self::CRON_HOOK);
			
			
		}
		
		/**
		* Hook into the WordPress deactivate hook
		*/
		public static function deactivate()
		{
			// Do something
			// Get the timestamp for the next event.
			$timestamp = wp_next_scheduled(self::CRON_HOOK);
			wp_unschedule_event($timestamp, self::CRON_HOOK);
			
		}
		
		public function debug($string, $data=NULL) {
			printf('<h6 style="color: red;">%s</h3><pre>', $string);
			print_r($data);
			printf('</pre>');
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
		
		/*
		* returns an generic GET request wrapper to LGL API
		*
		*/
		public function get_lgl_data($get_setting_flag, $lgl_id=NULL) 
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
			
			if (PLUGIN_DEBUG) $this->debug( 'get_lgl_data() request URI:', $this->request_uri);
			
			$raw_response = wp_remote_get($this->request_uri, $this->args);
			if (is_wp_error($raw_response) || '200' != wp_remote_retrieve_response_code($raw_response)) {
				if (PLUGIN_DEBUG) $this->debug('wp_error', $raw_response);
				return false;
			}
			$response = json_decode(wp_remote_retrieve_body($raw_response));
			
			if (!empty($response)) {
				return $response;
			} else {
				if (PLUGIN_DEBUG) $this->debug('No JSON to decode', NULL);					
				return;
			}				
		} // end get_lgl_data()
		
		/*
		* returns an generic GET request wrapper to LGL API
		*
		*/
		public function get_request($request_uri, $args = array())
		{
			$raw_response = wp_remote_get($request_uri, $args);
			if (is_wp_error($raw_response) || '200' != wp_remote_retrieve_response_code($raw_response)) {
				if (PLUGIN_DEBUG) $this->debug('wp_error', $raw_response);
				return 0;
			}
			$response = json_decode(wp_remote_retrieve_body($raw_response));
			
			if (!empty($response)) {
				return $response;
			} else {
				if (PLUGIN_DEBUG) $this->debug('No JSON to decode', NULL);					
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
				if (PLUGIN_DEBUG) $this->debug('wp_error', $raw_response);			
				return 0;
			}
			$response = json_decode(wp_remote_retrieve_body($raw_response));
			
			if (!empty($response)) {
				return $response;
			} else {
				if (PLUGIN_DEBUG) $this->debug('No JSON to decode', NULL);					
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
			
			do {
				$request_uri_with_offset = add_query_arg('offset', $offset, $request_uri);
				if (PLUGIN_DEBUG) $this->debug($request_uri_with_offset, NULL);
				
				
				$raw_response = wp_remote_get($request_uri_with_offset, $args);
				if (is_wp_error($raw_response) || '200' != wp_remote_retrieve_response_code($raw_response)) {
					if (PLUGIN_DEBUG) $this->debug('wp_error', $raw_response);
					return 0;
				}
				
				$response = json_decode(wp_remote_retrieve_body($raw_response));
				if (empty($response)) {
					if (PLUGIN_DEBUG) $this->debug('NO JSON to decode', NULL);
					
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
			
			// Save the final response in the transient for caching
			//	set_transient('shelterluv_pets', $final_response, DAY_IN_SECONDS);
			
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
					if (PLUGIN_DEBUG) $this->debug('No Constitudent Data retrieved from Little Green Light', NULL);					
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
		
		
		public function shelterluv_remove_transient()
		{
			delete_transient('shelterluv_pets');
		}
		
		
		/** 
		* retrieves all constituent data from LGL API
		* @var name = FIRST %20 LAST - note the %20 is hardcoded!
		* @return TRUE if found | FALSE if no match
		*
		*/
		public function lgl_search_by_name($name) {
			
			// set up request arguments (API Key + Request URI)
			$lgl_settings = LGL_API_Settings::get_instance();
			$api_key = $lgl_settings->lgl_get_setting('api_key');
			$this->set_request_args($api_key);
			$this->request_uri = $lgl_settings->lgl_get_setting('constituents_uri');
			
			$search_query = '/search.json?q%5B%5D=name%3D' . $name;			
			
			$this->request_uri = $this->request_uri . $search_query; 
			if (PLUGIN_DEBUG) $this->debug('searching for', $this->request_uri);
			
			$result = $this->get_request($this->request_uri, $this->args);
			if ($result && $result->items_count > 0) {
				printf('<pre>') . print_r($result) . printf('</pre>');
				//		if (PLUGIN_DEBUG) $this->debug('duplicate', $result);
				return TRUE;
			} else {
				return FALSE;
			}		
		}
		
		public function lgl_add_constituent($user_id) {
			
			$constituent = LGL_Constituents::get_instance();			
			$constituent->set_data($user_id);
			
			printf('<h2>%s %s</h2>', $constituent->personal_data->first_name, $constituent->personal_data->last_name);
			
			
			if ($this->lgl_search_by_name($constituent->personal_data->first_name . '%20' . $constituent->personal_data->last_name) == FALSE) {
				
				if (PLUGIN_DEBUG) $this->debug('UNIQUE CONTACT<br>pre-enocde', $constituent->personal_data);
				
				
				$personal_content = json_encode($constituent->personal_data);
				
				$lgl_settings = LGL_API_Settings::get_instance();
				$this->request_uri = $lgl_settings->lgl_get_setting('constituents_uri');
				$api_key = $lgl_settings->lgl_get_setting('api_key');
				
				$this->set_request_args($api_key, $personal_content);
				
				$response = $this->post_request($this->request_uri, $this->args, $personal_content);
				if ($response) {
					/* success now add the other fields */
					if (PLUGIN_DEBUG) $this->debug("NEW CONTACT", $response);
					$new_constitudent_id = $response->id;
					$this->lgl_add_object($response->id, $constituent->email_data, 'email_addresses.json' );
					$this->lgl_add_object($response->id, $constituent->phone_data, 'phone_numbers.json' );
					$this->lgl_add_object($response->id, $constituent->address_data, 'street_addresses.json');
					$this->lgl_add_object($response->id, $constituent->membership_data, 'memberships.json');
				}
			}
		}
		
		/**
		* lgl_add_object()
		*
		* @var lgl_id 	| ID of new user in Little Green Light
		* @var data 	| data to add
		* @var uri 		| API endpoint URI
		* @return lgl_id upon success
		*/
		public function lgl_add_object($lgl_id, $data, $uri) {
			
			// set up request arguments (API Key + Request URI)
			$lgl_settings = LGL_API_Settings::get_instance();
			$api_key = $lgl_settings->lgl_get_setting('api_key');
			$this->set_request_args($api_key);
			
			$constituent_uri = $lgl_settings->lgl_get_setting('constituents_uri');
			
			
			
			$endpoint = '/' . $lgl_id . '/' . $uri;
			
			$this->request_uri = $constituent_uri . $endpoint;
			$this->debug('', $this->request_uri);
			
			$this->set_request_args($api_key, json_encode($data));
			$response = $this->post_request($this->request_uri, $this->args);
			if ($response) {
				/* success now add the other fields */
				if (PLUGIN_DEBUG) $this->debug("DATA ADDED", $response);
				return $response->item_id;
			} else {
				if (PLUGIN_DEBUG) $this->debug("adding email FAILURE", $response);
				return;
			}		
		}

		/**
		* lgl_memberships()
		*
		* @var lgl_id 	| ID of new user in Little Green Light
		* @var data 	| data to add
		* @var uri 		| API endpoint URI
		* @return lgl_id upon success
		*/
		public function lgl_memberships($lgl_id, $data, $uri) {
			
			$constituent = LGL_Constituents::get_instance();			

			// set up request arguments (API Key + Request URI)
			$lgl_settings = LGL_API_Settings::get_instance();
			$api_key = $lgl_settings->lgl_get_setting('api_key');
			$this->set_request_args($api_key);
			
			$constituent_uri = $lgl_settings->lgl_get_setting('constituents_uri');			
			$endpoint = '/' . $lgl_id . '/' . $uri;
			$this->request_uri = $constituent_uri . $endpoint;
			$this->set_request_args($api_key, json_encode($data));

			$response = $this->post_request($this->request_uri, $this->args);
			if ($response) {
				if (PLUGIN_DEBUG) $this->debug("DATA ADDED", $response);
				return $response->id;
			} else {
				if (PLUGIN_DEBUG) $this->debug("adding email FAILURE", $response);
				return;
			}		

		}

		
		
		
		public function lgl_register_user($request, $action_handler) {
			
			$post_id = ! empty( $request['inserted_post_id'] ) ? $request['inserted_post_id'] : false;
			if ( ! $post_id ) {
				return;
			}
			
			
			$lgl_settings = LGL_API_Settings::get_instance();
			$api_key = $lgl_settings->lgl_get_setting('api_key');
			$this->set_request_args($api_key);
			$this->request_uri = $lgl_settings->lgl_get_setting('constituents_uri');
			echo "<h1>FUCK</h1>";
			
			
			
			
			
			$response = wp_remote_get( $this->request_uri, $this->args );
			$body = wp_remote_retrieve_body( $response );
			$body = json_decode( $body, true );
			
			
			if ( $body ) {
				$data = $body['data'][0];
				printf('<hr/><hr/><pre>%s</pre>', $body);
				//	update_post_meta( $post_id, 'api-response', $data['name'] );
			}
			
			$redirect = get_permalink( $post_id );
			
			if ( ! $request['__is_ajax'] ) {
				wp_safe_redirect( $redirect );
				die();
			} else {
				$action_handler->response_data['redirect'] = $redirect;
			}
			
		}
		
		public function lgl_delete_record($url, $args) {
			
			
			$args = array(
				'method' => 'DELETE'
			);
			$response = wp_remote_request( $url, $args );
		}
		
		public function run_update()
		{
			ob_start();
			
			if (REMOVE_TRANSIENT) {
				$this->shelterluv_remove_transient();
			}
			//$this->debug ('user', wp_get_current_user());


			//$lgl_constituents = $this->get_all_constituents();
			//if (PLUGIN_DEBUG) $this->debug("constitudents", $lgl_constituents);
			
			

			$payment_types = $this->get_lgl_data('PAYMENT_TYPES')->items;
			$gift_types = $this->get_lgl_data('GIFT_TYPES')->items;
			$this->debug('GIFTTYPES:' , $gift_types);
			
			$gift_categories = $this->get_lgl_data('GIFT_CATEGORIES')->items;
			$this->debug('GIFT CATEGORIES:' , $gift_categories);

			$campaigns = $this->get_lgl_data('CAMPAIGNS')->items;
			$this->debug('CAMPAIGNS:' , $campaigns);

			$funds = $this->get_lgl_data('FUNDS')->items;
			$this->debug('FUNDS:' , $funds);

			$key = array_search('Other Income', $gift_types);

			$key = array_search('Other Income', array_column($gift_types, 'name'));
			if ($key !== false) {
				$this->debug('key', $gift_types[$key]);	
				$payment = $gift_types[$key];
			}
			else {
				printf('<h2>oh no...</h2>');
			}

			$p = array(
				"gift_type_id" => $payment->id,
				"gift_type_name" => $payment->name,
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
				"team_member" => ""

			);

		//	$this->lgl_add_constituent( wp_get_current_user()->data->ID);
			
			$r = LGL_Relations_Manager::get_instance();
			$r->get_all_relations();

			$lgl_users = LGL_WP_Users::get_instance();
			$user_orders = $lgl_users->get_child_objects( $lgl_users->get_current_user_id() );
			if (!$user_orders) {
				printf('<h3>no users orders</h3>');
			} else {
				printf('<pre>');
				foreach($user_orders as $order) {
					$myvals = get_post_meta($order);
					foreach($myvals as $key=>$val)
					{
						echo $key . ' : ' . $val[0] . '<br/>';
					}
				}
				printf('</pre>');
			}
			
			
			
			
			//$this->create_and_update_animals($this->lgl_current_object);
			//$this->delete_adopted_animals($this->petID_array);
			
			
			return ob_get_clean();
		}
	}
}
