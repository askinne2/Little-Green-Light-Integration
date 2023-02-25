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

define('PLUGIN_DEBUG', false);
define('REMOVE_TRANSIENT', false);
define('LOCAL_JSON', false);


require_once 'includes/lgl-api-includes.php';
require_once 'includes/lgl-api-settings.php';



class LGL_API
{

	const CRON_HOOK = 'shelterluv_update_animals';

	var $request_uri;
	var $args;
	var $little_green_light_api;
	var $petID_array;
	var $shelterluv_pets_object;
	var $animal_images = array();


	public function __construct()
	{


		// set up request arguments (API Key)
		$this->set_request_args();
		// hook into custom post type actions and filters
		//add_action('trashed_post', array($this, 'delete_animal_post'));
		//add_filter('pre_get_posts', array($this, 'animals_change_posts_per_page'));
		//add_filter('template_include', array($this, 'shelterluv_archive_animal_template'), 9999);
		//add_filter('template_include', array($this, 'shelterluv_single_animal_template'), 9999);

		// run the Shelterluv_Animals() program

		add_shortcode('Shelterluv_Animals_Update', array($this, 'run_update'));

		// functionality added for shortcode [animals_slideshow]

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

	public function set_request_args()
	{

		ob_start();

		$this->args = array(
			'headers' => array(
				'Authorization' => 'Bearer ' . 'jzoqChDdn58cE5kZknzQSO0HsqZG9YyFkCNaBDawu9SvmgXAqXBu8aRoE7h4ynYwJI71AnK6DsF1Tvj9IAUD8A',
			),
		);
		if ($this->args['headers']['Authorization'] == NULL) {
			echo "<h5>Website cannot connect to LGL server. API Key needed.</h5>";
			return ob_get_clean();
		}

		return ob_get_clean();
	
	
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
					print_r($raw_response);
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
				print_r($pets);
				return $pets;
			}

/*
			if ($pets->total_count < 100) {

				// low animal count, we can just set transient and return animals 0 -> 100.
				if (PLUGIN_DEBUG) {
					printf('<h2 class="red_pet">SET TRANSIENT LOW ANIMALS</h2>');
				}
				set_transient('shelterluv_pets', $pets->animals, HOUR_IN_SECONDS);
				return $pets->animals;
			} else {

				if (PLUGIN_DEBUG) {
					printf('<h2>multiple requests</h2>');
				}
				// total animals published in ShelterLuv
				$animal_count = $pets->total_count;
				if (PLUGIN_DEBUG) {
					echo "<p>Animal Count   " . ($animal_count) . "</p>";
				}
				$total_requests = (($animal_count / 100) % 10) + 1;
				if (PLUGIN_DEBUG) {
					echo "<p>Total Request   " . ($total_requests) . "</p>";
				}

				$jsonpets = array();
				$all_pets = array();
				$request_uri = array();


				// Build our array of request URI's and make more calls
				for ($i = 0; $i < $total_requests; $i++) {
					$request_uri[$i] = 'https://www.shelterluv.com/api/v1/animals/?status_type=publishable&offset=' . $i . '00&limit=100';
					if (PLUGIN_DEBUG) {
						echo "<p>fetching - " . $request_uri[$i] . "</p>";
						printf('<p>i - %s,', $i);
					}

					$jsonpets[$i] = wp_remote_get($request_uri[$i], $this->args);
					if (is_wp_error($jsonpets[$i]) || '200' != wp_remote_retrieve_response_code($jsonpets[$i])) {
						if (PLUGIN_DEBUG) {
							echo "<p>Bad wp_remote_get Request. in Multiple Request. </p>";
						}
						return;
					} else {
						$all_pets[] = json_decode(wp_remote_retrieve_body($jsonpets[$i]))->animals;
						$animals = call_user_func_array('array_merge', $all_pets);
					}

					if (empty($animals)) {
						if (PLUGIN_DEBUG) {
							echo "<p>make_request(): No pets to json_decode </p>";
						}
						return;
					} else {

						if (PLUGIN_DEBUG) {
							printf('<h2 class="red_pet">SET TRANSIENT 100+ ANIMALS</h2>');
						}
						set_transient('shelterluv_pets', $animals, HOUR_IN_SECONDS);
						return $animals;
					}
				} // end multiple request check

			} // end empty(pets) check
			*/
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
				echo "<h5>Uh oh. Our shelter is experiencing technical difficulties.</h5>";
				echo "<p>Please try again later</p>";
				return;
			}
		} else {

			$pets = $this->make_request($request_uri); //, $args);
			if (empty($pets)) {
				echo "<h5>Uh oh. Our shelter is experiencing technical difficulties.</h5>";
				echo "<p>Please try again later</p>";
				return;
			}
		}

		$cats = array();
		$dogs = array();
		$others = array();

		/* loop through $pets object and sort according to
				         * statuses. 
						 * Default statuses are:

			$status1 = "Available For Adoption";
			$status2 = "Available for Adoption - Awaiting Spay/Neuter";
			$status3 = "Available for Adoption - In Foster";
			$status4 = "Awaiting Spay/Neuter - In Foster";
		*/
		foreach ($pets as $pet) {

		}

		if (PLUGIN_DEBUG) {
			echo '<h1 class="red_pet">The number of cats is:  ' . count($cats) . '</h1>';
			echo '<h1 class="red_pet">The number of dogs is:  ' . count($dogs) . '</h1>';
			echo '<h1 class="red_pet">The number of others is:  ' . count($others) . '</h1>';
		}
		$pets_object = array(
			'dogs' => $dogs,
			'cats' => $cats,
			'others' => $others,
		);
		return $pets_object;
	}
	public function run_update()
	{

		if (REMOVE_TRANSIENT) {
			$this->shelterluv_remove_transient();
		}
		$lgl = LGL_API_Settings::get_instance();
		$api_key = $lgl->lgl_get_api_setting('dbi_api_key');
		printf('<p>%s</p>', $api_key);
		$this->request_uri = 'https://api.littlegreenlight.com/api/v1/constituents?access_token=' . $api_key; //jzoqChDdn58cE5kZknzQSO0HsqZG9YyFkCNaBDawu9SvmgXAqXBu8aRoE7h4ynYwJI71AnK6DsF1Tvj9IAUD8A';

		ob_start();

		printf('<p>%s</p>', $this->request_uri);
		$this->shelterluv_pets_object = $this->request_and_sort($this->request_uri); //, $this->args);
		//$this->create_and_update_animals($this->shelterluv_pets_object);
		//$this->delete_adopted_animals($this->petID_array);
		//$this->check_duplicate_animals();

		return ob_get_clean();
	}
}


$pets = new LGL_API();
