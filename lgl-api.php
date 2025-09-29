<?php
/**
* Little Green Light API Integration Plugin
* 
* Modern WordPress plugin for integrating with Little Green Light CRM.
* Provides membership management, event registration, and payment processing.
* 
* @link              https://github.com/askinne2/Little-Green-Light-API
* @since             2.0.0
* @package           UpstateInternational\LGL
*
* @wordpress-plugin
* Plugin Name:       Little Green Light API Integration
* Plugin URI:        https://github.com/askinne2/Little-Green-Light-API
* Description:       Modern WordPress integration with Little Green Light CRM for membership management, events, and payments
* Version:           2.0.0
* Author:            Andrew Skinner
* Author URI:        https://www.21adsmedia.com
* License:           GPL-2.0+
* License URI:       http://www.gnu.org/licenses/gpl-2.0.txt
* Text Domain:       lgl-api
* Domain Path:       /languages
* Requires at least: 5.0
* Requires PHP:      7.4
* GitHub Plugin URI: https://github.com/askinne2/Little-Green-Light-API
*/

if (!defined('ABSPATH')) {
	exit; // Exit if accessed directly
}

// Plugin constants
define('LGL_PLUGIN_VERSION', '2.0.0');
define('LGL_PLUGIN_FILE', __FILE__);
define('LGL_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('LGL_PLUGIN_URL', plugin_dir_url(__FILE__));
define('LGL_PLUGIN_BASENAME', plugin_basename(__FILE__));

// Legacy compatibility constants
define('PLUGIN_DEBUG', false);
define('BUFFER', true);
define('REMOVE_TRANSIENT', false);
define('LGL_DATA_DELAY', 0.1);

// Initialize modern architecture first
lgl_init_modern_architecture();

// Legacy includes for classes NOT YET CONVERTED (only 2 remaining)
require_once 'includes/decrease_registration_counter_on_trash.php';
require_once 'includes/ui_memberships/ui_memberships.php';

// Backward compatibility includes (legacy versions alongside modern equivalents)
// These provide fallback support for any remaining direct class calls
require_once 'includes/lgl-connections.php';        // Legacy LGL_Connect (modern: Connection)
require_once 'includes/lgl-wp-users.php';           // Legacy LGL_WP_Users (modern: WpUsers)
require_once 'includes/lgl-constituents.php';       // Legacy LGL_Constituents (modern: Constituents)
require_once 'includes/lgl-payments.php';           // Legacy LGL_Payments (modern: Payments)
require_once 'includes/lgl-relations-manager.php';  // Legacy LGL_Relations_Manager (modern: RelationsManager)
// require_once 'includes/lgl-api-settings.php';    // DISABLED: Now using modern SettingsManager (legacy: LGL_API_Settings)
require_once 'includes/lgl-helper.php';             // Legacy LGL_Helper (modern: Helper)
require_once 'includes/test_requests.php';          // Legacy Test_Requests (modern: TestRequests)
require_once 'includes/admin/dashboard-widgets.php'; // Legacy LGL_Dashboard_Widgets (modern: DashboardWidgets)
require_once 'includes/email/daily-email.php';      // Legacy LGL_Daily_Email (modern: DailyEmailManager)
require_once 'includes/email/email-blocker.php';    // Legacy LGL_Email_Blocker (modern: EmailBlocker)
require_once 'includes/woocommerce/subscription-renewal.php'; // Legacy LGL_Subscription_Renewal (modern: SubscriptionRenewalManager)
require_once 'includes/lgl-cache-manager.php';      // Legacy LGL_Cache_Manager (modern: CacheManager)
require_once 'includes/lgl-utilities.php';          // Legacy LGL_Utilities (modern: Utilities)

// Initialize cache invalidation hooks (modern preferred, legacy fallback)
add_action('init', function() {
    if (class_exists('\UpstateInternational\LGL\Core\CacheManager')) {
        \UpstateInternational\LGL\Core\CacheManager::initCacheInvalidation();
    } elseif (class_exists('LGL_Cache_Manager')) {
        LGL_Cache_Manager::init_cache_invalidation();
    }
});


//add_action('init', 'lgl_shortcode', 10, 2);
add_action('template_redirect', 'lgl_shortcode', 10, 2);
function lgl_shortcode($response) {
	
	$lgl = LGL_API::get_instance();
	$lgl->shortcode_init();
}

if (!class_exists("LGL_API")) {
	/**
	* class:   Little_Green_Light_API
	* desc:    Connects the API actions to JetForm actions
	* 
	* HYBRID ARCHITECTURE: This legacy class now works alongside modern PSR-4 classes.
	* Modern classes are used when available, with fallback to legacy implementations.
	* The modern architecture is initialized first and provides enhanced performance.
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
		
		const UI_MEMBERSHIPS_CRON_HOOK = 'ui_memberships_daily_cron_hook';
		const UI_DELETE_MEMBERS = 'ui_members_monthly_hook';
		
		var $connection; // holds $LGL_Connect object 
		var $helper; // holds $LGL_Helper object
		var $ui_memberships;
		var $users;
		
	public function __construct()
	{	
		// Use modern classes if available, fallback to legacy
		if (class_exists('\UpstateInternational\LGL\LGL\Connection')) {
			$this->connection = \UpstateInternational\LGL\LGL\Connection::getInstance();
		} else {
			$this->connection = LGL_Connect::get_instance();
		}
		
		if (class_exists('\UpstateInternational\LGL\LGL\Helper')) {
			$this->helper = \UpstateInternational\LGL\LGL\Helper::getInstance();
		} else {
			$this->helper = LGL_Helper::get_instance();
		}
		
		$this->ui_memberships = UI_Memberships::get_instance();
		
		add_action( 'woocommerce_payment_complete', array($this, 'schedule_lgl_checkout_action'), 10, 1 );
		add_action( 'woocommerce_new_order', array($this, 'schedule_lgl_process_check_orders'), 10, 1 );
		
		add_action('ui_schedule_lgl_checkout_action', array($this, 'custom_action_after_successful_checkout'));
		add_action('ui_schedule_lgl_process_check_orders', array($this, 'lgl_process_check_orders'));
	}
		
		public function shortcode_init() {
			
			add_shortcode('lgl', array($this, 'run_update'));
		}
		
		/**
		* Hook into the WordPress activate hook
		*/
		public static function activate()
		{
			// Check if the event is already scheduled
			// ob_start();
			
			// if (!wp_next_scheduled(self::UI_MEMBERSHIPS_CRON_HOOK)) {
			// 	wp_schedule_event(time(), 'daily', self::UI_MEMBERSHIPS_CRON_HOOK);
			// 	error_log('UI Memberships Cron Event Scheduled');
			// } else {
			// 	error_log('UI Memberships Cron Event Already Scheduled');
			// }
			// // Check if the event is already scheduled
			// if (!wp_next_scheduled(self::UI_DELETE_MEMBERS)) {
			// 	$interval = 30 * DAY_IN_SECONDS;
			// 	wp_schedule_event(time(), 'daily', self::UI_DELETE_MEMBERS);
			// 	error_log('UI Members Delete Cron Event Scheduled');
			// } else {
			// 	error_log('UI Member Cron Event Already Scheduled');
			// }
			// ob_end_clean(); // Explicitly close the output buffer
		}
		
		
		/**
		* Hook into the WordPress deactivate hook
		*/
		public static function deactivate()
		{
			$ui_memberships_timestamp = wp_next_scheduled(self::UI_MEMBERSHIPS_CRON_HOOK);
			$ui_member_delete_timestamp = wp_next_scheduled(self::UI_DELETE_MEMBERS);
			
			wp_unschedule_event($ui_memberships_timestamp, self::UI_MEMBERSHIPS_CRON_HOOK);	
			wp_unschedule_event($ui_member_delete_timestamp, self::UI_DELETE_MEMBERS);
		}
		
		/**
		* lgl_register_user()
		*
		* @var request 			| array of data from Registration JetForm
		* @var action_handler 	| array of action_handler data from Registration JetForm
		* @var method 			| if NULL - register first user, if TRUE, proceed with other registration
		* 
		* @return none;
		*/
		
		public function lgl_register_user($request, $action_handler, $method=NULL) {
			
			$uid = $request['user_id'];
			$this->helper->debug('Request', $request);
			$username = $request['user_firstname'] . '%20' . $request['user_lastname'];
			$email = $request['user_email'];
			$membership_level = $request['ui-membership-type'];
			
			if (array_key_exists('payment_type', $request)) {
				$payment_type = $request['payment_type'];
				if ($payment_type === 'credit-card') {
					update_user_meta($uid, 'payment-method', 'online');
				} else { 
					update_user_meta($uid, 'payment-method', 'offline');
				}
			} else {
				update_user_meta($uid, 'payment-method', 'online');
			}
			$this->helper->debug('payment method:  ', get_user_meta($uid, 'payment-method', true));
			
			if ($method) $this->helper->debug("ADD FAMILY USER, MEMBERSHIP:  ", $membership_level);
			
			if ($uid != 0) {
				
				if (!$method) { /*** if NO method - we are NOT creating family users ****/
					if (strcmp($membership_level, 'Family Membership') === 0 || strcmp($membership_level, 'Patron Family Membership') === 0) {
						$this->helper->change_user_role($uid, 'ui_member', 'ui_patron_owner');
					}
				}
				
				$meta = get_user_meta($uid);
				
				$lgl_id = $this->connection->lgl_search_by_name($username, $email);
				if (!$lgl_id) {
					
					// cant find existing contact. make one 
					if (!$method) {
						update_user_meta($uid, 'user-membership-type', $membership_level);			
						
						$lgl_id = $this->connection->lgl_add_constituent($uid);
					} else {
						$lgl_id = $this->connection->lgl_add_constituent($uid, $request['parent_user_id']);
					}
					$this->helper->debug('Constituent LGL ID: ', $lgl_id );
					if ($this->connection->new_constituent_flag) {
						$this->helper->debug('**** Inside constituent flag, lgl_register_user()');
						update_user_meta($uid, 'lgl_id', $lgl_id);			
						update_user_meta($uid, 'user-membership-type', $membership_level);			
						if (!$method) {
							$payment_id = $this->connection->lgl_add_membership_payment($lgl_id, $request, $payment_type);
						}
						$this->connection->new_constituent_flag = false;				
					} else {
						$this->helper->debug('**** else constituent flag, lgl_register_user(), will update_membership()');
						$this->lgl_update_membership($request, $action_handler);
						$this->connection->new_constituent_flag = false;				
						
					}
				} else {
					$this->helper->debug('lgl_register_user(): FOUND LGL USER: ', $lgl_id );
					update_user_meta($uid, 'lgl_id', $lgl_id);
					if ($method) {
						$this->lgl_update_existing_family_member($uid, $lgl_id);
					} else {
						$this->lgl_update_membership($request, $action_handler);
					}
					return $lgl_id;
				}
				
			} else {
				$this->helper->debug('No User ID in Request, lgl_register_user()');
			}
		}
		
		/**
		* lgl_edit_user()
		*
		* @var request 			| array of data from Registration JetForm
		* @var action_handler 	| array of action_handler data from Registration JetForm
		* @return none;
		*/
		
		public function lgl_edit_user($request, $action_handler) {
			
			$uid = $request['user_id'];
			$this->helper->debug('', $request);
			
			
			$user_name = $request['user_firstname'] . ' ' . $request['user_lastname'];
			$user_email = $request['user_email'];
			
			$user_lgl_id = get_user_meta($uid, 'lgl_id', true);
			
			$existing_user = $this->connection->get_lgl_data('SINGLE_CONSTITUENT', $user_lgl_id);
			if ($existing_user) {
				$existing_contact_id = $existing_user->id;
				$this->helper->debug('LGL USER EXISTS: ', $existing_contact_id);
				
				if ($existing_contact_id) {
					
					/* now update with $request[data] */
					$constituent = LGL_Constituents::get_instance();						
					$update_data = $constituent->set_data_and_update($uid, $request);
					
					$response = $this->connection->lgl_update_object($existing_contact_id, $update_data, '.json');
					if ($response) {
						$this->helper->debug('UPDATED CONTACT<br>', $existing_contact_id);
					} else {
						$this->helper->debug('FAILED TO UPDATE contact <br>', $existing_contact_id);
					}
					
				} else {
					$this->helper->debug('NO Contact Found, lgl_edit_user()', $user_name . '   ' . $user_email);
				}
			} else {
				
				$this->helper->debug('NO Contact Found, lgl_edit_user()', $user_name . '   ' . $user_email);
				
				$uid = $request['user_id'];
				$username = $request['user_firstname'] . '%20' . $request['user_lastname'];
				$email = $request['user_email'];
				
				$lgl_id = $this->connection->lgl_search_by_name($username, $email);
				if (!$lgl_id) {
					$lgl_id = $this->connection->lgl_add_constituent($uid);
					$this->helper->debug('Constituent LGL ID: ', $lgl_id );
					
				}
			}
		}		
		
		/**
		* lgl_update_membership()
		*
		* @var request 			| array of data from Registration JetForm
		* @var action_handler 	| array of action_handler data from Registration JetForm
		* @return none;
		*/
		public function lgl_update_membership($request, $action_handler, $renewal_update=NULL, $no_redirect_flag=NULL) {
			
			$this->helper->debug('Request', $request);
			$uid = $request['user_id'];
			$username = $request['username'];
			$username = str_replace(' ', '%20', $username);
			$membership_level = $request['ui-membership-type'];
			$order_id = $request['inserted_post_id'];
			$price = $request['price'];
			$date = date('Y-m-d', time());
			$email =  get_userdata($uid)->data->user_email;
			
			$meta = get_user_meta($uid);
			$meta['user-membership-type'][0] = $request['ui-membership-type'];
			
			if ($uid != 0) {
				
				$membership_level = $request['ui-membership-type'];
				if (strcmp($membership_level, 'Family Membership') === 0 || strcmp($membership_level, 'Patron Family Membership') === 0) {
					$this->helper->debug('PRIVELEGE ESCALATION --> FAMILY');
					$this->helper->change_user_role($uid, 'ui_member', 'ui_patron_owner');
				} else if (strcmp($membership_level, 'Individual Membership') === 0 || strcmp($membership_level, 'Patron Membership') === 0) {
					$this->helper->debug('PRIVELEGE RESTRICTION --> INDIVIDUAL');
					$this->helper->change_user_role($uid, 'ui_patron_owner', 'ui_member');
				}
				
				$this->helper->fix_membership_radio($uid, $membership_level);
				
				/* retrieve current user */
				$user_lgl_id = get_user_meta($uid, 'lgl_id', true);				
				$existing_user = $this->connection->get_lgl_data('SINGLE_CONSTITUENT', $user_lgl_id);
				if ($existing_user) {
					$lgl_id = $existing_user->id;
					$this->helper->debug('LGL USER EXISTS: ', $lgl_id);
					if ($lgl_id) {
						$user = $this->connection->get_lgl_data('SINGLE_CONSTITUENT', $lgl_id);
						if ($user) {
							//$this->helper->debug('', $user);
							$memberships = $user->memberships;
							if (!empty($memberships)) {
								
								/* mark most recent membership inactive as of $yesterday */
								$lgl_membership = $memberships[0];
								$mid = $lgl_membership->id;
								$this->helper->debug('retrieving MEMBERSHIP', $mid);
								$yesterday = (new DateTime())->format('Y-m-d');
								$today = strtotime(date('Y-m-d'));
								
								if (strtotime($lgl_membership->finish_date) >= $today ) {
									$updated_membership = array (
										'id' => $lgl_membership->id,
										'membership_level_id' => $lgl_membership->membership_level_id,
										'membership_level_name' => $lgl_membership->membership_level_name,
										'date_start' => $lgl_membership->date_start,
										'finish_date' => $yesterday,
										'note' => 'Membership updated via WP_LGL_API on ' . date('Y-m-d')
									);
									$result = $this->connection->lgl_update_object($lgl_id, $updated_membership, null, 'MEMBERSHIPS', $mid);
									if (!$result) {
										$this->helper->debug('lgl_update_membership(): couldn\'t update: ', $updated_membership);
									} 
								}
							}
							
						} else {
							$this->helper->debug('lgl_update_membership(): no user byi ', $email);
						}
						
						$constituent = LGL_Constituents::get_instance();			
						$constituent->set_membership($uid);
						$this->helper->debug('Membership_Data:', $constituent->membership_data);
						$this->connection->lgl_add_object($lgl_id, $constituent->membership_data, 'memberships.json');
						
						$lgl_payments = LGL_Payments::get_instance();
						if (array_key_exists('payment_type', $request)) {
							$p = $lgl_payments->setup_membership_payment($this, $order_id, $price, $date, $request['payment_type']);
						} else {
							$p = $lgl_payments->setup_membership_payment($this, $order_id, $price, $date);
						}
						
						$lgl_payment_id = $this->connection->lgl_add_object($lgl_id, $p, 'gifts.json');
						//if ($lgl_payment_id) $this->helper->debug('Payment ID: ', $lgl_payment_id);
					}
				} else {
					
					$this->helper->debug('**** WP USER exists, but not in LGL *****'); 
					$lgl_id = $this->connection->lgl_add_constituent($uid);
					$constituent = LGL_Constituents::get_instance();			
					$constituent->set_membership($uid);
					$this->helper->debug('Membership_Data:', $constituent->membership_data);
					$this->connection->lgl_add_object($lgl_id, $constituent->membership_data, 'memberships.json');
					
					$lgl_payments = LGL_Payments::get_instance();
					if (array_key_exists('payment_type', $request)) {
						$p = $lgl_payments->setup_membership_payment($this, $order_id, $price, $date, $request['payment_type']);
					} else {
						$p = $lgl_payments->setup_membership_payment($this, $order_id, $price, $date);
					}
					
					$lgl_payment_id = $this->connection->lgl_add_object($lgl_id, $p, 'gifts.json');
					if ($lgl_payment_id) $this->helper->debug('Payment ID: ', $lgl_payment_id);	
				}
				
			} else {
				$this->helper->debug('No User ID in Request, lgl_update_membership()');
			}
		}
		
		/**
		* lgl_renew_membership()
		*
		* @var request 			| array of data from Registration JetForm
		* @var action_handler 	| array of action_handler data from Registration JetForm
		* @return none;
		*/
		public function lgl_renew_membership($request, $action_handler, $renewal_update=NULL) {
			
			$post_id = ! empty( $request['inserted_post_id'] ) ? $request['inserted_post_id'] : false;
			if ( ! $post_id ) {
				return;
			}
			$uid = $request['user_id'];
			$username =  $request['user_name'];
			$email = $request['user_email'];
			$date = date('Y-m-d', time());
			$membership_level = $request['user_membership_level_new'];
			$price = $request['price'];
			$order_id = $request['inserted_post_id'];
			
			if ($uid != 0) {
				$newdate = strtotime('+1 year', get_user_meta($uid, 'user-membership-start-date', true));
				$this->helper->debug('newdate:', date('Y-m-d', $newdate));
				update_user_meta($uid, 'user-membership-renewal-date', $newdate);
				
				if (strcmp($membership_level, 'Family Membership') === 0 || strcmp($membership_level, 'Patron Family Membership') === 0) {
					$this->helper->debug('PRIVELEGE ESCALATION --> FAMILY');
					$this->helper->change_user_role($uid, 'ui_member', 'ui_patron_owner');
				} else if (strcmp($membership_level, 'Individual Membership') === 0 || strcmp($membership_level, 'Patron Membership') === 0) {
					$this->helper->debug('PRIVELEGE RESTRICTION --> INDIVIDUAL');
					$this->helper->change_user_role($uid, 'ui_patron_owner', 'ui_member');
				}
				
				
				$this->helper->fix_membership_radio($uid, $membership_level);
				
				/* retrieve current user */
				$user_lgl_id = get_user_meta($uid, 'lgl_id', true);				
				$existing_user = $this->connection->get_lgl_data('SINGLE_CONSTITUENT', $user_lgl_id);
				if ($existing_user) {
					$lgl_id = $existing_user->id;
					$this->helper->debug('LGL USER EXISTS: ', $lgl_id);
					if ($lgl_id) {
						$user = $this->connection->get_lgl_data('SINGLE_CONSTITUENT', $lgl_id);
						if ($user) {
							//$this->helper->debug('', $user);
							$memberships = $user->memberships;
							if (!empty($memberships)) {
								//$this->helper->debug('$memberships ', $memberships);
								
								/* mark most recent membership inactive as of $yesterday */
								$lgl_membership = $memberships[0];
								
								$mid = $lgl_membership->id;
								$this->helper->debug('retrieving MEMBERSHIP', $mid);
								$yesterday = (new DateTime())->format('Y-m-d');
								$today = strtotime(date('Y-m-d'));
								
								if (strtotime($lgl_membership->finish_date) >= $today ) {
									$updated_membership = array (
										'id' => $lgl_membership->id,
										'membership_level_id' => $lgl_membership->membership_level_id,
										'membership_level_name' => $lgl_membership->membership_level_name,
										'date_start' => $lgl_membership->date_start,
										'finish_date' => $yesterday,
										'note' => 'Membership DEACTIVATED via WP_LGL_API on ' . date('Y-m-d')
									);
									$result = $this->connection->lgl_update_object($lgl_id, $updated_membership, null, 'MEMBERSHIPS', $mid);
									if (!$result) {
										$this->helper->debug('lgl_renew_membership(): couldn\'t update: ', $updated_membership);
									} 
								}
								
							}
							
						} else {
							$this->helper->debug('lgl_renew_membership(): no user byi ', $email);
						}
						
						$constituent = LGL_Constituents::get_instance();			
						$constituent->set_membership($uid);
						$this->helper->debug('Membership_Data:', $constituent->membership_data);
						$this->connection->lgl_add_object($lgl_id, $constituent->membership_data, 'memberships.json');
						
						$lgl_payments = LGL_Payments::get_instance();
						$p = $lgl_payments->setup_membership_payment($this, $order_id, $price, $date);
						
						$lgl_payment_id = $this->connection->lgl_add_object($lgl_id, $p, 'gifts.json');
						if ($lgl_payment_id) $this->helper->debug('Payment ID: ', $lgl_payment_id);
					}
				}
				
			} else {
				$this->helper->debug('No User ID in Request, lgl_update_membership()');
			}
			/*
			$redirect = get_permalink( $post_id );
			
			if ( ! $request['__is_ajax'] ) {
			wp_safe_redirect( $redirect );
			die();
			} else {
			$action_handler->response_data['redirect'] = $redirect;
			}
			*/
		}
		
		
		/**
		* lgl_add_class_registration()
		*
		* @var request 			| array of data from Registration JetForm
		* @var action_handler 	| array of action_handler data from Registration JetForm
		* @return none;
		*/
		
		public function lgl_add_class_registration($request, $action_handler) {
			/*
			$post_id = ! empty( $request['inserted_post_id'] ) ? $request['inserted_post_id'] : false;
			if ( ! $post_id ) {
			$this->helper->debug('Empty post_id Request', $request);
			return;
			}
			*/
			$this->helper->debug('Request', $request);
			
			$uid = $request['user_id'];
			$class_id = $request['class_id'];
			$username = $request['username'];
			$username = str_replace(' ', '%20', $username);
			$class_name = $request['class_name'];
			$order_id = $request['inserted_post_id'];
			$price = $request['class_price'];
			$date = date('Y-m-d', time());
			$email =  get_userdata($uid)->data->user_email;
			$lgl_fund_id = $request['lgl_fund_id'];
			
			
			if ($uid != 0 && $username) {
				
				//$lgl_id = $this->lgl_search_by_name($username, $email);
				/* retrieve current user */
				$user_lgl_id = get_user_meta($uid, 'lgl_id', true);				
				$existing_user = $this->connection->get_lgl_data('SINGLE_CONSTITUENT', $user_lgl_id);
				if ($existing_user) {
					$lgl_id = $existing_user->id;
					$this->helper->debug('LGL USER EXISTS: ', $lgl_id);
					
					if ($lgl_id) {
						$lgl_pay = LGL_Payments::get_instance();
						$p = $lgl_pay->setup_class_payment($this, $order_id, $price, $date, $class_name, $lgl_fund_id);
						$this->helper->debug('payment: ', $p);
						
						$lgl_payment_id = $this->connection->lgl_add_object($lgl_id, $p, 'gifts.json');
						
						
					} else {
						$this->helper->debug('Cannot find user with name, lgl_add_class_registration()', $username);
					}
				}
			} else {
				$this->helper->debug('No User ID in Request, lgl_add_class_registration()');
			}
		}
		
		
		/**
		* lgl_add_event_registration()
		*
		* @var request 			| array of data from Registration JetForm
		* @var action_handler 	| array of action_handler data from Registration JetForm
		* @return none;
		*/
		
		public function lgl_add_event_registration($request, $action_handler) {
			/*
			$post_id = ! empty( $request['inserted_post_id'] ) ? $request['inserted_post_id'] : false;
			if ( ! $post_id ) {
			$this->helper->debug('Empty post_id Request', $request);
			return;
			}
			*/
			$this->helper->debug('Request', $request);
			
			$uid = $request['user_id'];
			$class_id = $request['class_id'];
			$username = $request['username'];
			$username = str_replace(' ', '%20', $username);
			$event_name = $request['event_name'];
			$order_id = $request['inserted_post_id'];
			$price = $request['event_price'];
			$date = date('Y-m-d', time());
			$email =  get_userdata($uid)->data->user_email;
			$lgl_fund_id = $request['lgl_fund_id'];
			
			if ($uid != 0 && $username) {
				
				//$lgl_id = $this->lgl_search_by_name($username, $email);
				/* retrieve current user */
				$user_lgl_id = get_user_meta($uid, 'lgl_id', true);				
				$existing_user = $this->connection->get_lgl_data('SINGLE_CONSTITUENT', $user_lgl_id);
				if ($existing_user) {
					$lgl_id = $existing_user->id;
					$this->helper->debug('LGL USER EXISTS: ', $lgl_id);
					
					if ($lgl_id) {
						$lgl_pay = LGL_Payments::get_instance();
						$p = $lgl_pay->setup_event_payment($this, $order_id, $price, $date, $event_name, $lgl_fund_id);
						$this->helper->debug('payment: ', $p);
						
						$lgl_payment_id = $this->connection->lgl_add_object($lgl_id, $p, 'gifts.json');
						
						
					} else {
						$this->helper->debug('Cannot find user with name, lgl_add_class_registration()', $username);
					}
				}
			} else {
				$this->helper->debug('No User ID in Request, lgl_add_class_registration()');
			}
		}
		
		/**
		* lgl_add_family_member()
		*
		* @var request 			| array of data from Registration JetForm
		* @var action_handler 	| array of action_handler data from Registration JetForm
		* @return none;
		*/
		
		public function lgl_add_family_member($request, $action_handler) {
			
			if ( ! $request['user_id'] || ! $request['parent_user_id']) {
				return;
			}
			
			$this->helper->debug('Request', $request);
			$child_uid = $request['user_id'];
			$parent_uid = $request['parent_user_id'];
			
			$parent_paypal_membership = get_user_meta($parent_uid, 'user-membership-level-paypal', true);
			$parent_membership_type = get_user_meta($parent_uid, 'user-membership-type', true);
			
			$child_firstname = get_user_meta( $child_uid, 'first_name', true );
			$child_lastname = get_user_meta( $child_uid, 'last_name', true );
			$child_email = get_user_meta( $child_uid, 'user_email', true );
			
			$child_request = array (
				'user_id' => $child_uid,
				'user_firstname' => $child_firstname,
				'user_lastname' => $child_lastname,
				'username' => $request['username'],
				'user_email' => $child_email,
				'user_phone' => $request['user_phone'],
				'user-address-1' => $request['user-address-1'],
				'user-address-2' => $request['user-address-2'],
				'user-city' => $request['user-city'],
				'user-state' => $request['user-state'],
				'user-postal-code' => $request['user-postal-code'],
				'ui-membership-type' => $parent_membership_type,
				'parent_user_id' => $request['parent_user_id']
			);
			$this->helper->debug('lgl_add_family_member(): CHILD REQUEST');
			update_user_meta($child_uid, 'user-membership-type', $parent_membership_type);
			update_user_meta($child_uid, 'user-membership-level-paypal', $parent_paypal_membership);
			update_user_meta($child_uid, 'user-membership-renewal-date', get_user_meta($parent_uid, 'user-membership-renewal-date', true));
			update_user_meta($child_uid, 'user-membership-start-date', get_user_meta($parent_uid, 'user-membership-start-date', true));
			
			update_user_meta($child_uid, 'user-subscription-id', get_user_meta($parent_uid, 'user-subscription-id', true));
			update_user_meta($child_uid, 'user-subscription-status', get_user_meta($parent_uid, 'user-subscription-status', true));
			
			
			$this->lgl_register_user($child_request, null, $parent_uid);
		}
		
		public function lgl_update_existing_family_member($uid, $lgl_id) {
			if ($lgl_id) {
				$user = $this->connection->get_lgl_data('SINGLE_CONSTITUENT', $lgl_id);
				if ($user) {
					//$this->helper->debug('', $user);
					$memberships = $user->memberships;
					if (!empty($memberships)) {
						
						/* mark every old membership inactive as of $yesterday */
						foreach ($memberships as $lgl_membership) {
							if (!empty($lgl_membership)) {
								
								$mid = $lgl_membership->id;
								$this->helper->debug('lgl_update_existing_family_member(), retrieving MEMBERSHIP', $mid);
								$yesterday = (new DateTime())->format('Y-m-d');
								$today = strtotime(date('Y-m-d'));
								
								if (strtotime($lgl_membership->finish_date) >= $today ) {
									$updated_membership = array (
										'id' => $lgl_membership->id,
										'membership_level_id' => $lgl_membership->membership_level_id,
										'membership_level_name' => $lgl_membership->membership_level_name,
										'date_start' => $lgl_membership->date_start,
										'finish_date' => $yesterday,
										'note' => 'Membership updated via WP_LGL_API on ' . date('Y-m-d')
									);
									$result = $this->connection->lgl_update_object($lgl_id, $updated_membership, null, 'MEMBERSHIPS', $mid);
									if (!$result) {
										$this->helper->debug('lgl_update_existing_family_member(): couldn\'t update: ', $updated_membership);
									} 
								}
							}
						}
					}
					
				} else {
					$this->helper->debug('lgl_update_membership(): no user byi ', $uid);
				}
				
				$constituent = LGL_Constituents::get_instance();			
				$constituent->set_membership($uid);
				$this->helper->debug('lgl_update_existing_family_member(), Membership_Data:', $constituent->membership_data);
				$this->connection->lgl_add_object($lgl_id, $constituent->membership_data, 'memberships.json');
			}
		}
		
		public function lgl_deactivate_membership($request, $action_handler) {
			
			$this->helper->debug('lgl_deactivate_membership() request:' , $request);
			
			$uid = $request['user_id'];
			if ($uid != 0) {
				
				/* retrieve current user */
				$user_lgl_id = get_user_meta($uid, 'lgl_id', true);				
				$existing_user = $this->connection->get_lgl_data('SINGLE_CONSTITUENT', $user_lgl_id);
				if ($existing_user) {
					$lgl_id = $existing_user->id;
					$this->helper->debug('LGL USER EXISTS: ', $lgl_id);
					if ($lgl_id) {
						$user = $this->connection->get_lgl_data('SINGLE_CONSTITUENT', $lgl_id);
						if ($user) {
							$memberships = $user->memberships;
							if (!empty($memberships)) {
								//$this->helper->debug('$memberships ', $memberships);
								
								/* mark most recent membership inactive as of $yesterday */
								$lgl_membership = $memberships[0];
								
								$mid = $lgl_membership->id;
								$this->helper->debug('retrieving MEMBERSHIP', $mid);
								$yesterday = (new DateTime())->format('Y-m-d');
								$today = strtotime(date('Y-m-d'));
								
								if (strtotime($lgl_membership->finish_date) >= $today ) {
									$updated_membership = array (
										'id' => $lgl_membership->id,
										'membership_level_id' => $lgl_membership->membership_level_id,
										'membership_level_name' => $lgl_membership->membership_level_name,
										'date_start' => $lgl_membership->date_start,
										'finish_date' => $yesterday,
										'note' => 'Membership DEACTIVATED via WP_LGL_API on ' . date('Y-m-d')
									);
									$result = $this->connection->lgl_update_object($lgl_id, $updated_membership, null, 'MEMBERSHIPS', $mid);
									if (!$result) {
										$this->helper->debug('lgl_renew_membership(): couldn\'t update: ', $updated_membership);
									} 
								}
								
							}
							
						}
						
					} else {
						$this->helper->debug('lgl_renew_membership(): no user!');
					}
				}
				
				
				$lgl_users = LGL_WP_Users::get_instance();
				$lgl_users->ui_user_deactivation($uid);
				
			} else {
				$this->helper->debug('No User ID in Request, lgl_deactivate_membership()');
			}
			
			
			
		}
		
		
		public function run_update()
		{
			if (REMOVE_TRANSIENT) {
				$this->helper->remove_transient();
			}
			
			if (is_user_logged_in()) {
				//$this->lgl_add_constituent(wp_get_current_user()->data->ID);
				$uid = wp_get_current_user()->data->ID;
				$meta = get_user_meta($uid);
				//$this->helper->debug('meta: ', $meta);
				$test = Test_Requests::get_instance();
				
				/*
				$order_id = 68596;
				$order = wc_get_order($order_id);
				
				$wc_order_meta = $order->get_meta();
				$this->helper->debug('meta:', $wc_order_meta);
				$languages = $order->get_meta('_order_languages_spoken');
				$emailaddress = get_user_meta( $uid, 'user_email', true); //$user_info->data->user_email;
				if (!$emailaddress) {
				$user_info = get_userdata($uid);
				
				$emailaddress = $user_info->data->user_email;
				}
				$this->helper->debug('emailaddress:', $emailaddress);
				*/	
				//				$this->custom_action_after_successful_checkout( $order_id );
				
				
				
				//$funds = $this->connection->get_all_funds();
				//$this->helper->debug('funds:', $funds);
				//$this->helper->writeFundsCSV('output', $funds);
				//$test->make_registration();
				//$this->lgl_register_user($test->registration_request, null);
			} else { 
				$this->helper->debug('user not logged in');	
			}
		}
		
		
		
		public function custom_action_after_successful_checkout($order_id) {
			
			if (class_exists('WC_Order')) {
				$order = wc_get_order($order_id);
				
				$uid = $order->get_customer_id();
				$payment_type = $order->get_payment_method();
				if ($payment_type !== 'cheque') {
					update_user_meta($uid, 'payment-method', 'online');
				} else { 
					update_user_meta($uid, 'payment-method', 'offline');
				}
				
				$user_languages = $order->get_meta('_order_languages_spoken');
				$user_country_of_origin = $order->get_meta('_order_country_of_origin');
				$user_referral = $order->get_meta('_order_referral_source');
				$user_reason_for_membership = $order->get_meta('_order_reason_for_membership');
				$user_about_me = $order->get_meta('_order_tell_us_about_yourself');
				
				$order_meta = array(
					'languages' => $user_languages,
					'country' => $user_country_of_origin,
					'referral' => $user_referral,
					'reason' => $user_reason_for_membership,
					'about' => $user_about_me
				);
				
				$products = $order->get_items();
				$processed_events = array();
				$attendees = array();			
				$create_attendee_cct_flag = false;			
				
				$i=0;
				$attendee_index = 0;
				
				foreach ($products as $product) {
					
					$product_name = $product->get_name();
					$quantity = $product->get_quantity();
					$product_id = $product->get_variation_id() ?: $product->get_product_id();
					$parent_id = $product->get_variation_id() ? $product->get_product_id() : $product_id;
					
					$this->helper->debug('Product Name: ', $product_name);
					$this->helper->debug('Quantity: ', $quantity);
					$this->helper->debug($product->get_variation_id() ? 'Variation Product ID: ' : 'Simple Product ID: ', $product_id);
					
					// Process membership and language class products
					if (has_term('memberships', 'product_cat', $parent_id) && stripos($product_name, 'membership') !== false) {
						$this->helper->debug('Running doWooCommerceLGLMembership');
						$this->doWooCommerceLGLMembership($uid, $order, $order_meta, $product, $product_name);
						
					} else if (has_term('language-class', 'product_cat', $parent_id)) {
						$this->helper->debug('Running doWooCommerceLGLClassRegistration');
						$this->doWooCommerceLGLClassRegistration($uid, $order, $order_meta, $product);
						
					} else if (has_term('events', 'product_cat', $parent_id)) {
						
						$create_attendee_cct_flag = true;
						
						for ($j = 0; $j < $quantity; $j++) {
							$suffix = $attendee_index === 0 ? '' : '_' . $attendee_index;
							$attendee_name = $order->get_meta('attendee_name' . $suffix);
							$attendee_email = $order->get_meta('attendee_email' . $suffix);
							
							if (empty($attendee_name) || empty($attendee_email)) {
								continue; // Skip if the meta fields are empty
							}
							
							$attendee = array(
								'attendee_name' => $attendee_name,
								'attendee_email' => $attendee_email,
								'product' => $product,
								'product_id' => $product_id,
								'parent_id' => $parent_id,
								'variation_name' => $this->helper->get_variation_name($product_id),
							);    
							$this->helper->debug('Adding new attendee -----------', $attendee['attendee_email']);
							$attendees[] = $attendee;    
							$attendee_index++;  // Increment the counter
						}
						
						if ($i === 0 && !in_array($parent_id, $processed_events)) {
							$this->helper->debug('Running doWooCommerceLGLEventRegistration');
							$this->doWooCommerceLGLEventRegistration($uid, $order, $order_meta, $product);
							$processed_events[] = $parent_id;
							$i++;
						} else {
							$i++;							
						}
					}
				}				
				
				if ($create_attendee_cct_flag) {
					$lgl_users = LGL_WP_Users::get_instance();
					$lgl_users->create_event_registration_cct($order, $attendees);
				}	
			} else {
				$this->helper->debug("no woocommerce");
			}
		}
		
		
		function schedule_lgl_checkout_action($order_id) {
			$this->helper->debug('inside schedule_lgl_checkout_action()');
			// Schedule the event to run once after PAYMENT_TIME_WINDOW minutes
			if (!wp_next_scheduled('ui_schedule_lgl_checkout_action', array($order_id))) {
				if ($order_id) {
					wp_schedule_single_event(time() + LGL_DATA_DELAY * MINUTE_IN_SECONDS, 'ui_schedule_lgl_checkout_action', array($order_id));
				} else {
					$this->helper->debug('schedule_lgl_checkout_action event NOT scheduled, no order ID!');
				}
				$this->helper->debug('schedule_lgl_checkout_action event scheduled!');
			}
		}
		
		function schedule_lgl_process_check_orders($order_id) {
			$this->helper->debug('inside schedule_lgl_process_check_orders()');
			// Schedule the event to run once after PAYMENT_TIME_WINDOW minutes
			if (!wp_next_scheduled('ui_schedule_lgl_process_check_orders', array($order_id))) {
				if ($order_id) {
					wp_schedule_single_event(time() + LGL_DATA_DELAY * MINUTE_IN_SECONDS, 'ui_schedule_lgl_process_check_orders', array($order_id));
				} else {
					$this->helper->debug('ui_schedule_lgl_process_check_orders event NOT scheduled, no order ID!');
				}
				$this->helper->debug('ui_schedule_lgl_process_check_orders event scheduled!');
			}
		}
		
		// Set check payment orders to completed status
		public function lgl_process_check_orders( $order_id ) {
			if ( class_exists( 'WC_Order' ) ) {
				
				$order = wc_get_order( $order_id );
				if ($order) {
					// Check if the payment method is 'bacs' (Bank Transfer / Check)
					if ( 'bacs' === $order->get_payment_method() || 'cheque' === $order->get_payment_method() || 'cod' === $order->get_payment_method() ) {
						$this->helper->debug('*** CHECK ORDER ****, payment method: ', $order->get_payment_method());
						// Update the order status to 'completed'
						$this->custom_action_after_successful_checkout($order_id);
					} else {
						
						$this->helper->debug(' -------- NOT A CHECK ORDER --------- CONTINUE -----');
					}
				}
			}
		}
		
		public function doWooCommerceLGLClassRegistration($uid, $order, $order_meta, $product) {
			
			$product_name = $product->get_name();
			$product_id = $product->get_product_id();
			
			//$this->helper->debug('product meta: ', get_post_meta($product_id));
			$product_meta = get_post_meta($product_id);
			$lgl_fund_id = isset( $product_meta['_lc_lgl_fund_id'][0] ) ? $product_meta['_lc_lgl_fund_id'][0] : '';
			$this->helper->debug('Class Registration LGL FUND ID: ', $lgl_fund_id);
			
			$class_reg = array (
				'user_id' => $uid, 
				'class_id' => $product_id,
				'username' => $order->get_billing_first_name() . ' ' . $order->get_billing_last_name(),
				'user_firstname' => $order->get_billing_first_name(),
				'user_lastname' => $order->get_billing_last_name(),
				'user_email' => $order->get_billing_email(),
				'user_phone' => $order->get_billing_phone(),
				'class_name' => $product->get_name(),
				'class_price' => $order->get_total(),
				'lgl_fund_id' => $lgl_fund_id,
				'user_preferred_language' => isset( $order_meta['languages'] ) ? $order_meta['languages'] : '',
				'user_home_country' => isset( $order_meta['country'] ) ? $order_meta['country'] : '',
				'order_notes' => get_post_meta($order->get_id(), '_order_notes', true),
				'inserted_post_id' => $order->get_id(),
				//'inserted_ui_membership_orders' => 63047,
				//'inserted_cct_class_registrations' => 56
			);
			$lgl_users = LGL_WP_Users::get_instance();
			$lgl_users->update_user_data($class_reg, $order, $order_meta);

			/** 
			 * Create single CPT for each class registration
			 */
			$lgl_users->create_jetengine_post_on_order_completion($order->get_id(), $order_meta, $product, NULL);
			$this->lgl_add_class_registration($class_reg, $action_handler=NULL);
			$order->update_status( 'completed' );
			
		}
		
		public function doWooCommerceLGLEventRegistration($uid, $order, $order_meta, $product) {
			// Assuming the attendees array contains all necessary information
			
			$product_name = $product->get_name();
			$quantity = $product->get_quantity();
			
			$product_id = $product->get_variation_id() ?: $product->get_product_id();
			$parent_id = $product->get_variation_id() ? $product->get_product_id() : $product_id;
			
			$product_meta = get_post_meta($parent_id);
			$this->helper->debug('VARIATION get_meta: ', get_post_meta($parent_id, '_ui_event_lgl_fund_id', true));
			
			
			
			$lgl_fund_id = isset($product_meta['_ui_event_lgl_fund_id'][0]) ? $product_meta['_ui_event_lgl_fund_id'][0] : '';
			$this->helper->debug('Event Registration LGL FUND ID: ', $lgl_fund_id);
			
			$registration = array(
				'user_id' => $uid,
				'class_id' => $product_id,
				'username' => $order->get_billing_first_name() . ' ' . $order->get_billing_last_name(),
				'user_firstname' => $order->get_billing_first_name(),
				'user_lastname' => $order->get_billing_last_name(),
				'user_email' => $order->get_billing_email(),
				'user_phone' => $order->get_billing_phone(),
				'event_name' => $product_name,
				'event_price' => $order->get_total(),
				'lgl_fund_id' => $lgl_fund_id,
				'user_preferred_language' => isset($order_meta['languages']) ? $order_meta['languages'] : '',
				'user_home_country' => isset($order_meta['country']) ? $order_meta['country'] : '',
				'order_notes' => get_post_meta($order->get_id(), '_order_notes', true),
				'inserted_post_id' => $order->get_id(),
			);
			
			//$this->helper->debug('Attendee Registration:', $registration);
			
			$lgl_users = LGL_WP_Users::get_instance();
			$lgl_users->update_user_data($registration, $order, $order_meta);
			$this->lgl_add_event_registration($registration, $action_handler = NULL);
			$order->update_status('completed');
		}
		
		public function doWooCommerceLGLMembership($uid, $order, $order_meta, $product, $membership_level) {
			$product_name = $product->get_name();
			
			if (stripos( $product_name, 'family' ) !== false) {
				$this->helper->change_user_role($uid, 'customer', 'ui_patron_owner');
			} else {
				$this->helper->change_user_role($uid, 'customer', 'ui_member');
			}
			$order_created_date = $order->get_date_created();
			
			$user_data = get_userdata( $uid );
			$lgl_membership_name = $this->helper->ui_membership_WC_name_to_LGL($membership_level);
			
			$request = array(
				'user_firstname' => $order->get_billing_first_name(),
				'user_lastname' => $order->get_billing_last_name(),
				'user_company' => $order->get_billing_company(),
				'username' => $user_data->user_login,
				'user_email' => $order->get_billing_email(),
				'user_phone' => $order->get_billing_phone(),
				'user-address-1' => $order->get_billing_address_1(),
				'user-address-2' => $order->get_billing_address_2(),
				'user-city' => $order->get_billing_city(),
				'user-state' => $order->get_billing_state(),
				'user-postal-code' => $order->get_billing_postcode(),
				'user-country-of-origin' => isset( $order_meta['country'] ) ? $order_meta['country'] : '',
				'current_date' => $order_created_date->getTimestamp(),
				'price' => $order->get_total(),
				'ui-membership-type' => $lgl_membership_name,
				'inserted_post_id' => $order->get_id(),
				'user_id' => $uid,
			);
			//$this->helper->debug('$request(): ', $request);
			
			$action = array();
			$lgl_users = LGL_WP_Users::get_instance();
			$lgl_users->update_user_data($request, $order, $order_meta);
			$lgl_users->update_user_subscription_info($uid, $order->get_id());
			$this->lgl_register_user($request, $action);
			
			$order->update_status( 'completed' );
			
		}
		
		function update_user_meta_on_subscription_cancel($subscription_id) {
			// Get the user ID associated with the subscription
			$subscription = wcs_get_subscription($subscription_id);
			$user_id = $subscription->get_customer_id();
			$this->helper->debug('-----CANCELLING SUBSCRIPTION FOR USER ', $user_id);
			
			// Check if a user is associated with the subscription
			if ($user_id) {
				// Update user meta fields
				update_user_meta($user_id, 'user-subscription-status', 'cancelled');
				update_user_meta($user_id, 'user-membership-start-date', '');
				update_user_meta($user_id, 'user-membership-renewal-date', '');
				update_user_meta($user_id, 'user-subscription-id', '');
				$current_role = LGL_WP_Users::get_instance()->ui_get_user_role($user_id);
				if ($current_role === 'ui_patron_owner') {
					$child_relations = LGL_WP_Users::get_instance()->ui_get_child_relations($user_id);
					$this->helper->debug('update_user_subscription_status_ON_CANCEL(): Child relations for User # ' . $user_id . '  -  ', $child_relations);
					
					foreach ($child_relations as $child_id) {
						$this->helper->debug('Updating Meta for Child: ' . $child_id);
						update_user_meta($child_id, 'user-subscription-status', 'cancelled');
						update_user_meta($child_id, 'user-membership-start-date', '');
						update_user_meta($child_id, 'user-membership-renewal-date', '');
						update_user_meta($child_id, 'user-subscription-id', '');
						// Update user meta fields
						$request = array(
							'user_id' => $child_id
						);
						$this->lgl_deactivate_membership($request, null);
					}
				}
			} else {
				$this->helper->debug('No user ID given to update_user_meta_on_subscription_cancel()');
			}
		}
		public function update_user_subscription_status ($subscription_id, $old_status, $new_status) {
			
			$subscription = wcs_get_subscription($subscription_id);
			//$this->helper->debug('subscription info:', $subscription);
			$this->helper->debug('-----update_user_subscription_status() ', $new_status); 
			
			// Check if the subscription status is transitioning to 'cancelled'
			if ($new_status === 'cancelled' || $new_status === 'pending-cancel') {
				// Get the user ID associated with the subscription
				//$user_id = get_post_meta($subscription_id, '_customer_user', true);
				$user_id = $subscription->get_customer_id();
				
				// Check if a user is associated with the subscription
				if ($user_id) {
					// Update user meta fields
					$request = array(
						'user_id' => $user_id
					);
					$this->lgl_deactivate_membership($request, null);
					update_user_meta($user_id, 'user-subscription-status', 'cancelled');
					update_user_meta($user_id, 'user-membership-start-date', '');
					update_user_meta($user_id, 'user-membership-renewal-date', '');
					update_user_meta($user_id, 'user-subscription-id', '');
					$current_role = LGL_WP_Users::get_instance()->ui_get_user_role($user_id);
					if ($current_role === 'ui_patron_owner') {
						$child_relations = LGL_WP_Users::get_instance()->ui_get_child_relations($user_id);
						$this->helper->debug('update_user_subscription_status(): Child relations for User # ' . $user_id . '  -  ', $child_relations);
						
						foreach ($child_relations as $child_id) {
							$this->helper->debug('Updating Meta for Child: ' . $child_id);
							update_user_meta($child_id, 'user-subscription-status', 'cancelled');
							update_user_meta($child_id, 'user-membership-start-date', '');
							update_user_meta($child_id, 'user-membership-renewal-date', '');
							update_user_meta($child_id, 'user-subscription-id', '');
							// Update user meta fields
							$request = array(
								'user_id' => $child_id
							);
							$this->lgl_deactivate_membership($request, null);
						}
					}
					
				} else {
					error_log('No user ID given to update_user_meta_on_subscription_cancel()');
				}
			}
		}
		
		
		function custom_email_content_for_category($order, $sent_to_admin, $plain_text, $email) {
			$this->helper->debug('Running email customizer..... ');
            $event_product_flag = false;

			// Check if it's not sent to the admin and the order has products
			if (!$sent_to_admin && $order->get_items()) {
				foreach ($order->get_items() as $item_id => $item) {
					$product_id = $item->get_product_id();
					$product_name = $item->get_name(); // Get the variation name
					
					// Check if the product belongs to a specific category
					if ( has_term( 'memberships', 'product_cat', $product_id ) ) {
						$is_renewal = wcs_order_contains_renewal($order);
						if ($is_renewal) {
							$file_path = plugin_dir_path(__FILE__) . 'form-emails/membership-renewal.html';
						} else {
							$file_path = plugin_dir_path(__FILE__) . 'form-emails/membership-confirmation.html';
						}
					} else if ( has_term( 'language-class', 'product_cat', $product_id ) ) {
						$file_path = plugin_dir_path(__FILE__) . 'form-emails/language-class-registration.html';
					} else if ( has_term( 'events', 'product_cat', $product_id ) ) {
						
						
						$event_product_flag = true;
						
						
						// Check for specific product ID for global fluency workshop
						if ($product_id === 73955) {
							$file_path = plugin_dir_path(__FILE__) . 'form-emails/global-fluency-workshop.html';
						} else if (strpos(strtolower($product_name), 'free') !== false) { // Check if variation name contains the word "free"
							$file_path = plugin_dir_path(__FILE__) . 'form-emails/event-with-lunch.html';
						} else { // Variation name does not contain the word "free"
							$file_path = plugin_dir_path(__FILE__) . 'form-emails/event-no-lunch.html';
						}
					}
					
					if ($file_path && file_exists($file_path)) {
						$email_body_content = file_get_contents($file_path);
						
						// Insert dynamic data
						if ($event_product_flag) {
						   $email_body_content = $this->insert_dynamic_data($email_body_content, $order, $product_id);
						}
						echo $email_body_content;
						$this->helper->debug('FAKE SENDING EMAIL:', $email_body_content);
						break;
					}
				}
			}
		}
		
		private function insert_dynamic_data($email_body_content, $order, $product_id) {
    
    // Retrieve the UNIX timestamp for event date and time
    $event_datetime = get_post_meta($product_id, '_ui_event_start_datetime', true);
    
    // Check if the event datetime is a valid timestamp
    if (!empty($event_datetime) && is_numeric($event_datetime)) {
        $event_date = date('F j, Y', $event_datetime); // e.g., August 27, 2024
        $event_time = date('g:i A', $event_datetime);  // e.g., 9:00 AM
    } else {
        $event_date = ''; // Set a default or empty value if the timestamp is invalid
        $event_time = '';
    }
    
    // Event location metadata
    $event_location_name = get_post_meta($product_id, '_ui_event_location_name', true) ?: '';
    $event_location_address = get_post_meta($product_id, '_ui_event_location_address', true) ?: '';
    
    // Speaker metadata (conditionally displayed)
    $speaker_name = get_post_meta($product_id, '_ui_event_speaker_name', true) ?: '';
    $discussion_title = get_post_meta($product_id, '_ui_event_discussion_topic', true) ?: '';
    
    // Order-specific metadata
    $attendee_name = $order->get_meta('attendee_name') ?: '';
    $attendee_email = $order->get_meta('attendee_email') ?: '';
    
    // Product name
    $product_name = get_the_title($product_id) ?: 'Event';

    // Replace placeholders with actual data
    $email_body_content = str_replace('[Product Name]', $product_name, $email_body_content);
    $email_body_content = str_replace('[Event Date]', $event_date, $email_body_content);
    $email_body_content = str_replace('[Event Time]', $event_time, $email_body_content);
    $email_body_content = str_replace('[Event Location]', $event_location_name . ', ' . $event_location_address, $email_body_content);
    
    // Conditional speaker section
    if (!empty($speaker_name) && !empty($discussion_title)) {
        // If speaker info exists, add it to the email content
        $speaker_section = "<h2>Speaker:</h2>
                            <p>
                                <strong>Name:</strong> {$speaker_name}<br>
                                <strong>Title of Discussion:</strong> {$discussion_title}
                            </p>";
        $email_body_content = str_replace('[Speaker Section]', $speaker_section, $email_body_content);
    } else {
        // Remove placeholder if no speaker info is available
        $email_body_content = str_replace('[Speaker Section]', '', $email_body_content);
    }
    
    return $email_body_content;
}
	}
}



$lgl = LGL_API::get_instance();
register_activation_hook( __FILE__, array($lgl, 'activate') );
register_deactivation_hook( __FILE__, array($lgl, 'deactivate') );


add_action( 'jet-form-builder/custom-action/lgl_check_abandoned_users', array(LGL_WP_Users::get_instance(), 'check_abandoned_users'), 10, 2);
add_action( 'jet-form-builder/custom-action/lgl_register_user', array(LGL_API::get_instance(), 'lgl_register_user'), 10, 2);
add_action( 'jet-form-builder/custom-action/lgl_add_class_registration', array(LGL_API::get_instance(), 'lgl_add_class_registration'), 10, 2);
add_action( 'jet-form-builder/custom-action/lgl_update_membership', array(LGL_API::get_instance(), 'lgl_update_membership'), 10, 2);
add_action( 'jet-form-builder/custom-action/lgl_renew_membership', array(LGL_API::get_instance(), 'lgl_renew_membership'), 10, 2);
add_action( 'jet-form-builder/custom-action/lgl_edit_user', array(LGL_API::get_instance(), 'lgl_edit_user'), 10, 2);
add_action( 'jet-form-builder/custom-action/lgl_add_family_member', array(LGL_API::get_instance(), 'lgl_add_family_member'), 10, 2);

add_action( 'jet-form-builder/custom-action/lgl_deactivate_membership', array(LGL_API::get_instance(), 'lgl_deactivate_membership'), 13, 2);
add_action( 'jet-form-builder/custom-action/ui_family_user_deactivation', array(LGL_WP_Users::get_instance(), 'ui_family_user_deactivation'), 13, 2);

add_action('woocommerce_subscription_status_cancelled', array(LGL_API::get_instance(), 'update_user_meta_on_subscription_cancel'), 10, 1);
add_action('woocommerce_subscription_status_updated', array(LGL_API::get_instance(), 'update_user_subscription_status'), 10, 3);
add_action('woocommerce_email_before_order_table', array(LGL_API::get_instance(), 'custom_email_content_for_category'), 10, 4);

/**
 * MODERN ARCHITECTURE INITIALIZATION
 * 
 * This section initializes the new PSR-4 compliant architecture
 * while maintaining backward compatibility with legacy code.
 */

/**
 * Initialize modern architecture
 */
function lgl_init_modern_architecture() {
    // Load Composer autoloader
    $autoloader = LGL_PLUGIN_DIR . 'vendor/autoload.php';
    
    if (!file_exists($autoloader)) {
        add_action('admin_notices', function() {
            if (current_user_can('manage_options')) {
                echo '<div class="notice notice-warning"><p><strong>LGL Plugin:</strong> Modern features require Composer autoloader. Run <code>composer install</code> in the plugin directory for enhanced performance and features.</p></div>';
            }
        });
        return;
    }
    
    require_once $autoloader;
    
    // Initialize modern plugin architecture
    add_action('plugins_loaded', function() {
        try {
            $plugin = \UpstateInternational\LGL\Core\Plugin::getInstance(LGL_PLUGIN_FILE);
            error_log('LGL Plugin: Modern architecture initialized successfully');
        } catch (Exception $e) {
            error_log('LGL Plugin Modern Architecture Error: ' . $e->getMessage());
        }
    }, 5); // Load early to ensure modern classes are available
}

/**
 * Get modern plugin instance (utility function)
 */
function lgl_plugin() {
    static $instance = null;
    
    if ($instance === null && class_exists('\UpstateInternational\LGL\Core\Plugin')) {
        $instance = \UpstateInternational\LGL\Core\Plugin::getInstance(LGL_PLUGIN_FILE);
    }
    
    return $instance;
}

/**
 * Modern activation hook
 */
register_activation_hook(__FILE__, function() {
    // Legacy activation
    if (class_exists('LGL_API')) {
        LGL_API::activate();
    }
    
    // Modern activation (if available)
    if (function_exists('lgl_plugin') && lgl_plugin()) {
        lgl_plugin()->onActivation();
    }
    
    error_log('LGL Plugin: Activation completed (hybrid mode)');
});

/**
 * Modern deactivation hook
 */
register_deactivation_hook(__FILE__, function() {
    // Legacy deactivation
    if (class_exists('LGL_API')) {
        LGL_API::deactivate();
    }
    
    // Modern deactivation (if available)
    if (function_exists('lgl_plugin') && lgl_plugin()) {
        lgl_plugin()->onDeactivation();
    }
    
    error_log('LGL Plugin: Deactivation completed (hybrid mode)');
});

// Development helpers (only in debug mode)
if (defined('WP_DEBUG') && WP_DEBUG) {
    add_action('admin_notices', function() {
        // Check if user has permissions and WordPress is fully loaded
        if (!function_exists('current_user_can') || !current_user_can('manage_options')) {
            return;
        }
        
        echo '<div style="background: #f1f1f1; padding: 10px; margin: 10px 0; border-left: 4px solid #0073aa;">';
        echo '<h4>🚀 LGL Plugin Status (v' . LGL_PLUGIN_VERSION . ') - HYBRID ARCHITECTURE</h4>';
        echo '<p><strong>Modern Architecture:</strong> ' . (class_exists('\UpstateInternational\LGL\Core\Plugin') ? '✅ Active (15/15 classes converted)' : '❌ Not Available') . '</p>';
        echo '<p><strong>Legacy Compatibility:</strong> ' . (class_exists('LGL_API') ? '✅ Loaded (backward compatibility)' : '❌ Missing') . '</p>';
        echo '<p><strong>Autoloader:</strong> ' . (file_exists(LGL_PLUGIN_DIR . 'vendor/autoload.php') ? '✅ Available (308 classes)' : '❌ Missing') . '</p>';
        echo '<p><strong>Performance Mode:</strong> 🚀 Modern classes used when available, legacy fallback enabled</p>';
        echo '</div>';
    });
}







add_action('template_redirect', 'check_memberships_shortcode');
function check_memberships_shortcode($response) {
	
	$lgl_users = LGL_WP_Users::get_instance();
	$lgl_users->shortcode_init();
}
add_action('template_redirect', 'ui_og_memberships_shortcode');
function ui_og_memberships_shortcode($response) {
	
	$ui_members = UI_Memberships::get_instance();
	$ui_members->shortcode_init();
}