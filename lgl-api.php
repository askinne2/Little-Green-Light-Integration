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
define('REMOVE_TRANSIENT', false);
define('LOCAL_JSON', false);

require_once 'includes/lgl-api-includes.php';
require_once 'includes/lgl-api-settings.php';

add_action('template_redirect', 'lgl_shortcode', 10, 2);
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
        
		
		const CRON_HOOK = 'shelterluv_update_animals';
		
		var $request_uri;
		var $args;
		var $little_green_light_api;
		var $petID_array;
		var $lgl_current_object;
		var $animal_images = array();
		
		
		public function __construct()
		{
			
			
			
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
		
		public function set_request_uri($request_uri)
		{
			$this->request_uri = $request_uri;
		}
		
		public function set_request_args(string $api_key)
		{
			$this->args = array(
				'headers' => array(
					'Authorization' => 'Bearer ' . $api_key,
				),
			);
			if ($this->args['headers']['Authorization'] == NULL) {
				echo "<h5>Website cannot connect to LGL server. API Key needed.</h5>";
				return ob_get_clean();
			}
		}
		
		/*
		* returns an unsorted $pets object of all published animals from shelterluv
		*
		*/
		public function make_request($request_uri, $args = array())
		{
			
			$transient = get_transient('shelterluv_pets');
			if (!empty($transient)) {
				if (PLUGIN_DEBUG) {
					printf('<h2 class="red_pet">TRANSIENT FOUND</h2>');
				}
				return $transient;
			} else {
				
				$raw_response = wp_remote_get($request_uri, $args);
				if (is_wp_error($raw_response) || '200' != wp_remote_retrieve_response_code($raw_response)) {
					if (PLUGIN_DEBUG) {
						print('wp_error:');
						printf('<pre>');
						print_r($raw_response);
						printf('</pre>');
					}
					
					return 0;
				}
				$pets = json_decode(wp_remote_retrieve_body($raw_response));
				
				if (empty($pets)) {
					if (PLUGIN_DEBUG) {
						print('No JSON to decode');
					}
					
					return;
				} else {
					printf('<pre>');
					var_dump($pets);
					printf('</pre>');
					return $pets;
				}
				
				
			} // end transient check
			
		} // end make_request()
		
		public function shelterluv_remove_transient()
		{
			delete_transient('shelterluv_pets');
		}
		
		public function request_and_sort($request_uri, $args = array())
		{
			
			if (LOCAL_JSON) {
				//$pets = $this->make_local_request();
				if (empty($pets)) {
					echo "<h5>Our apologies. We are experiencing technical difficulties.</h5>";
					echo "<p>Please try again later</p>";
					return;
				}
			} else {
				
				$pets = $this->make_request($request_uri, $args);
				if (empty($pets)) {
					echo "<h5>Our apologies. We are experiencing technical difficulties.</h5>";
					echo "<p>Please try again later</p>";
					return;
				}
			}
		}

		public function run_update()
		{
			
			if (REMOVE_TRANSIENT) {
				$this->shelterluv_remove_transient();
			}
			
			// set up request arguments (API Key + Request URI)
			$lgl = LGL_API_Settings::get_instance();
			$api_key = $lgl->lgl_get_setting('api_key');
			$this->set_request_args($api_key);
			$this->request_uri = $lgl->lgl_get_setting('constituents_uri');
			
			ob_start();
			
			if (PLUGIN_DEBUG) {
				printf('<p>API Key: %s</p>', $api_key);
				printf('<p>%s</p>', $this->request_uri);
			}
			
			
			$this->lgl_current_object = $this->request_and_sort($this->request_uri, $this->args);
			//$this->create_and_update_animals($this->lgl_current_object);
			//$this->delete_adopted_animals($this->petID_array);
			//$this->check_duplicate_animals();
			
			return ob_get_clean();
		}
	}
}
	