<?php

/**
* File Name: lgl-wp-users.php
* Version: 1.0
* Plugin URI:  https://github.com/askinne2/Little-Green-Light-API
* Description: This class interfaces between the JetEngine/WP User custom fields & settings
* Author URI: http://github.com/askinne2
*/

if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly
}

define('USERS_DEBUG', false);
define('PAYMENT_TIME_WINDOW', 10); 
define('DELETE_MEMBERS', false);

define('USERS_FILE_PATH', plugin_dir_path( __FILE__ ));
require_once USERS_FILE_PATH . '../lgl-api.php';
require_once USERS_FILE_PATH . '/jet-engine-cct-api.php'; // CRITICAL: Required for CCT registrations - migrated to src/JetEngine/CctApi.php but kept here for compatibility

require_once WP_PLUGIN_DIR . '/jet-engine/jet-engine.php';
require_once WP_PLUGIN_DIR . '/jet-engine/includes/components/relations/relation.php';


if (!class_exists("LGL_WP_Users")) {
    /**
    * class:   Little Green Light_API_Settings
    * desc:    Creates the settings pages for the Little Green Light API plugin
    */
    class LGL_WP_Users
    {
        /**
        * Class instance
        *
        * @var null|LGL_WP_Users
        */
        private static $instance = null;
        var $lgl;
        var $relation;
        const CRON_HOOK = 'ui_members_cron_hook';
        const UI_DELETE_MEMBERS = 'ui_members_monthly_hook';
        
        
        
        /**
        * Get instance
        *
        * @return LGL_WP_Users
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
            $this->lgl = LGL_API::get_instance();
            add_action(LGL_API::UI_DELETE_MEMBERS, array($this, 'run_monthly_update'));
            // Hook to execute the function when the scheduled event runs
            // add_action('ui_check_user_orders_event',  array($this,'check_orders'));

            add_action('wp_dashboard_setup',  array($this,'register_sync_dashboard_widget'));

            
        }
        
        public function shortcode_init() {
            add_shortcode('lgl_check_memberships', array($this, 'user_deletion'));
            
        }
        
        function ui_family_user_deactivation($request, $action_handler) {
            
            // Validate required fields
            if (empty($request['parent_user_id'])) {
                $this->lgl->helper->warning('LGL WP Users: Missing parent_user_id in family deactivation request');
                return;
            }
            
            if (empty($request['child_users'])) {
                $this->lgl->helper->warning('LGL WP Users: Missing child_users in family deactivation request');
                return;
            }
            
            $user_id = (int) $request['parent_user_id'];
            $children_ids = $request['child_users'];
            
            // Handle child_users if it's a JSON string (from JetFormBuilder)
            if (is_string($children_ids)) {
                $decoded = json_decode($children_ids, true);
                if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
                    $children_ids = $decoded;
                } else {
                    // If not JSON, try to parse as comma-separated or single value
                    $children_ids = array_filter(array_map('trim', explode(',', $children_ids)));
                }
            }
            
            // Ensure it's an array
            if (!is_array($children_ids)) {
                $children_ids = [$children_ids];
            }
            
            // Convert all to integers
            $children_ids = array_map('intval', array_filter($children_ids));
            
            if (empty($children_ids)) {
                $this->lgl->helper->warning('LGL WP Users: No valid child user IDs to remove', ['parent_id' => $user_id]);
                return;
            }
            
            // Check if the user is logged in
            if (!is_user_logged_in()) {
                $this->lgl->helper->warning('LGL WP Users: User not logged in for family deactivation');
                return;
            }
            
            // Verify parent user exists and has correct role
            $user = get_user_by('ID', $user_id);
            if (!$user) {
                $this->lgl->helper->error('LGL WP Users: Parent user not found for family deactivation', ['user_id' => $user_id]);
                return;
            }
            
            $current_role = $user->roles;
            
            // Check if the user has the correct role
            if (!in_array('ui_patron_owner', $current_role)) {
                $this->lgl->helper->warning('LGL WP Users: User does not have ui_patron_owner role', ['user_id' => $user_id, 'roles' => $current_role]);
                return;
            }
            
            // Initialize relation object
            $this->relation = jet_engine()->relations->get_active_relations(24);
            if (!$this->relation) {
                $this->lgl->helper->error('LGL WP Users: Could not get JetEngine relation 24');
                return;
            }
            
            // Get actual child relations to verify they exist
            $actual_child_relations = $this->ui_get_child_relations($user_id);
            
            // Remove only the selected children
            $removed_count = 0;
            foreach ($children_ids as $child_id) {
                $child_id = (int) $child_id;
                
                // Verify this child actually belongs to this parent
                if (!in_array($child_id, $actual_child_relations)) {
                    $this->lgl->helper->warning('LGL WP Users: Child does not belong to parent', [
                        'child_id' => $child_id,
                        'parent_id' => $user_id
                    ]);
                    continue;
                }
                
                // Deactivate/delete the child user
                $this->ui_deactivate_user($child_id, true);
                
                // Remove the relationship
                $this->ui_remove_relation($this->relation, $user_id, $child_id);
                // Note: ui_remove_relation() now handles slot syncing internally
                
                $removed_count++;
            }
            
            // Final sync after all removals (belt and suspenders)
            if ($this->lgl && $this->lgl->helper) {
                $this->lgl->helper->syncUsedFamilySlotsMeta($user_id);
                
                $total_purchased = (int) get_user_meta($user_id, 'user_total_family_slots_purchased', true);
                $actual_used = $this->lgl->helper->getActualUsedFamilySlots($user_id);
                $new_available = $total_purchased - $actual_used;
                update_user_meta($user_id, 'user_available_family_slots', max(0, $new_available));
                
                $this->lgl->helper->info('LGL WP Users: Family user deactivation completed', [
                    'parent_id' => $user_id,
                    'removed_count' => $removed_count
                ]);
            }
        }
        
        function ui_user_deactivation($uid, $cron_job=null) {
            
            // Check if the user is logged in
            if (is_user_logged_in()) {
                $current_role = $this->ui_get_user_role($uid);
                
                // Check if the user has one of the roles to deactivate
                if ($current_role == 'ui_member' || $current_role == 'ui_patron_owner') {
                    
                    // If the user is 'ui_patron_owner', handle child deactivation
                    if ($current_role == 'ui_patron_owner') {
                        $child_relations = $this->ui_get_child_relations($uid);
                        
                        foreach ($child_relations as $child_id) {
                            $this->ui_deactivate_user($child_id);
                            $this->ui_remove_relation($this->relation, $uid, $child_id);
                            // Note: ui_remove_relation() now handles slot syncing internally
                        }
                        
                        // Final sync after all removals
                        if ($this->lgl && $this->lgl->helper) {
                            $this->lgl->helper->syncUsedFamilySlotsMeta($uid);
                            
                            $total_purchased = (int) get_user_meta($uid, 'user_total_family_slots_purchased', true);
                            $actual_used = $this->lgl->helper->getActualUsedFamilySlots($uid);
                            $new_available = $total_purchased - $actual_used;
                            update_user_meta($uid, 'user_available_family_slots', max(0, $new_available));
                        }
                    }
                    
                    // Log the user out, reset the password, and redirect
                    // Deactivate the user and set the new role
                    $this->ui_deactivate_user($uid);
                    $this->lgl->helper->info('LGL WP Users: User deactivated', ['user_id' => $uid, 'role' => $current_role]);
                    //wp_logout($user_id);           
                    wp_redirect(home_url());
                    
                }
            } else if ($cron_job) {
                $current_role = $this->ui_get_user_role($uid);
                
                // Check if the user has one of the roles to deactivate
                if ($current_role == 'ui_member' || $current_role == 'ui_patron_owner') {
                    
                    // If the user is 'ui_patron_owner', handle child deactivation
                    if ($current_role == 'ui_patron_owner') {
                        $child_relations = $this->ui_get_child_relations($uid);
                        
                        foreach ($child_relations as $child_id) {
                            $this->ui_deactivate_user($child_id);
                            $this->ui_remove_relation($this->relation, $uid, $child_id);
                            // Note: ui_remove_relation() now handles slot syncing internally
                        }
                        
                        // Final sync after all removals
                        if ($this->lgl && $this->lgl->helper) {
                            $this->lgl->helper->syncUsedFamilySlotsMeta($uid);
                            
                            $total_purchased = (int) get_user_meta($uid, 'user_total_family_slots_purchased', true);
                            $actual_used = $this->lgl->helper->getActualUsedFamilySlots($uid);
                            $new_available = $total_purchased - $actual_used;
                            update_user_meta($uid, 'user_available_family_slots', max(0, $new_available));
                        }
                    }
                    
                    // Log the user out, reset the password, and redirect
                    // Deactivate the user and set the new role
                    $this->ui_deactivate_user($uid);
                    $this->lgl->helper->info('LGL WP Users: User deactivated via cron', ['user_id' => $uid, 'role' => $current_role]);
                }
            }
        }
        
        // Helper function to deactivate the user and set the new role
        function ui_deactivate_user($user_id, $delete=NULL) {
            if ($delete) {
                require_once(ABSPATH.'wp-admin/includes/user.php');
                wp_delete_user($user_id);
                $this->lgl->helper->info('LGL WP Users: User deleted', ['user_id' => $user_id]);
            } else {
                $user = new WP_User($user_id);
                $user->add_role('ui_inactive_member');
                wp_set_password($this->generate_random_password(), $user_id);
            }
            
        }
        
        // Helper function to get the user's role
        function ui_get_user_role($user_id) {
            $user = get_user_by('ID', $user_id);
            $user_roles = $user->roles;
            if (in_array('ui_patron_owner', $user_roles)) {
                return 'ui_patron_owner';
            } elseif (in_array('ui_member', $user_roles)) {
                return 'ui_member';
            }
            return '';
        }
        
        function generate_random_password($length = 12) {
            $characters = '0123456789abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ!@#$%^&*()_+';
            $password = '';
            
            for ($i = 0; $i < $length; $i++) {
                $index = random_int(0, strlen($characters) - 1);
                $password .= $characters[$index];
            }
            
            return $password;
        }
        
        
        public function run_monthly_update() {
            // Check if it's the first day of the month
            if (date('j') === '4' && date('n') % 2 === 0) {
                $this->lgl->helper->info('LGL WP Users: Running monthly inactive user update');
                require_once(ABSPATH.'wp-admin/includes/user.php');
                if (DELETE_MEMBERS) $this->user_deletion();
            }
        }
        
        public function user_deletion() {
            // MEMORY FIX: Process users in batches to prevent memory exhaustion
            $batch_size = 50;
            $page = 1;
            $has_more = true;
            $deleted_count = 0;
            
            while ($has_more) {
                $blogusers = get_users(array( 
                    'role__in' => array('ui_inactive_member'),
                    'number' => $batch_size,
                    'offset' => ($page - 1) * $batch_size
                ));
                
                if (empty($blogusers)) {
                    $has_more = false;
                    break;
                }
                
                foreach ($blogusers as $user) {
                    $user_id = $user->ID;
                    $this->family_member_deletion($user_id);
                    
                    // MEMORY FIX: Process posts in batches instead of loading all at once
                    $post_page = 1;
                    $has_posts = true;
                    while ($has_posts) {
                        $user_posts = get_posts(array(
                            'author' => $user_id,
                            'posts_per_page' => 50, // Process 50 posts at a time
                            'paged' => $post_page,
                            'fields' => 'ids' // Only get IDs to save memory
                        ));
                        
                        if (empty($user_posts)) {
                            $has_posts = false;
                            break;
                        }
                        
                        foreach ($user_posts as $post_id) {
                            // Delete each post
                            wp_delete_post($post_id, true); // Set the second parameter to true to bypass the trash
                        }
                        
                        if (count($user_posts) < 50) {
                            $has_posts = false;
                        } else {
                            $post_page++;
                        }
                        
                        unset($user_posts);
                        
                        // Safety limit
                        if ($post_page > 100) {
                            break;
                        }
                    }
                    
                    // Delete the user
                    require_once(ABSPATH.'wp-admin/includes/user.php');
                    wp_delete_user($user_id);
                    $deleted_count++;
                    
                    // Free memory
                    unset($user);
                }
                
                if (count($blogusers) < $batch_size) {
                    $has_more = false;
                } else {
                    $page++;
                }
                
                unset($blogusers);
                
                // Safety limit
                if ($page > 100) {
                    $this->lgl->helper->warning('LGL WP Users: user_deletion hit safety limit', ['page' => $page]);
                    break;
                }
            }
            
            $this->lgl->helper->info('LGL WP Users: Monthly user deletion completed', ['deleted_count' => $deleted_count]);
        }
        
        function family_member_deletion($user_id) {
            
            $current_role = $this->ui_get_user_role($user_id);
            
            // If the user is 'ui_patron_owner', handle child deactivation
            if ($current_role == 'ui_patron_owner') {
                $child_relations = $this->ui_get_child_relations($user_id);
                
                foreach ($child_relations as $child_id) {
                    $this->ui_remove_relation($this->relation, $user_id, $child_id);
                    // Note: ui_remove_relation() now handles slot syncing internally
                    
                    // MEMORY FIX: Process posts in batches instead of loading all at once
                    $post_page = 1;
                    $has_posts = true;
                    while ($has_posts) {
                        $user_posts = get_posts(array(
                            'author' => $child_id,
                            'posts_per_page' => 50, // Process 50 posts at a time
                            'paged' => $post_page,
                            'fields' => 'ids' // Only get IDs to save memory
                        ));
                        
                        if (empty($user_posts)) {
                            $has_posts = false;
                            break;
                        }
                        
                        foreach ($user_posts as $post_id) {
                            // Delete each post
                            wp_delete_post($post_id, true); // Set the second parameter to true to bypass the trash
                        }
                        
                        if (count($user_posts) < 50) {
                            $has_posts = false;
                        } else {
                            $post_page++;
                        }
                        
                        unset($user_posts);
                        
                        // Safety limit
                        if ($post_page > 100) {
                            break;
                        }
                    }
                    
                    // Delete the user
                    require_once(ABSPATH.'wp-admin/includes/user.php');
                    wp_delete_user($child_id);
                }
                
                // Final sync after all deletions
                if ($this->lgl && $this->lgl->helper) {
                    $this->lgl->helper->syncUsedFamilySlotsMeta($user_id);
                    
                    $total_purchased = (int) get_user_meta($user_id, 'user_total_family_slots_purchased', true);
                    $actual_used = $this->lgl->helper->getActualUsedFamilySlots($user_id);
                    $new_available = $total_purchased - $actual_used;
                    update_user_meta($user_id, 'user_available_family_slots', max(0, $new_available));
                }
            }
            
            // Log the user out, reset the password, and redirect
            // Deactivate the user and set the new role
            $this->ui_deactivate_user($user_id);
        }
        
        
        // Helper function to get child relations of a 'ui_patron_owner'
        function ui_get_child_relations($parent_id) {
            
            $this->relation = jet_engine()->relations->get_active_relations(24);            
            $related_ids = $this->relation->get_children( $parent_id, 'ids' );
            return $related_ids;
        }
        
        // Helper function to remove a relationship
        function ui_remove_relation($relation, $parent_id, $child_id) {
            
            $this->relation->delete_rows( $parent_id, $child_id, true );
            
            // Sync family slots after relationship removal
            if ($this->lgl && $this->lgl->helper) {
                $this->lgl->helper->syncUsedFamilySlotsMeta($parent_id);
                
                // Recalculate available slots: total_purchased - actual_used
                $total_purchased = (int) get_user_meta($parent_id, 'user_total_family_slots_purchased', true);
                $actual_used = $this->lgl->helper->getActualUsedFamilySlots($parent_id);
                $new_available = $total_purchased - $actual_used;
                update_user_meta($parent_id, 'user_available_family_slots', max(0, $new_available));
            }
        }        
        
        // Function to get the most recently created user ID
        function get_most_recent_user_id() {
            // Query to get the most recently created user
            $users = get_users(array(
                'number' => 1,
                'orderby' => 'registered',
                'order' => 'DESC',
            ));
            
            // Check if users were found
            if (!empty($users)) {
                $most_recent_user = array_shift($users);
                return $most_recent_user->ID;
            }
            
            // Return 0 or any default value if no users are found
            return 0;
        }
        
        // Create a WP Cron Event
        function schedule_user_deletion_event($user_id) {
            // Schedule the event to run once after PAYMENT_TIME_WINDOW minutes
            if (!wp_next_scheduled('ui_check_user_orders_event')) {
                if ($user_id) {
                    wp_schedule_single_event(time() + PAYMENT_TIME_WINDOW * MINUTE_IN_SECONDS, 'ui_check_user_orders_event', array($user_id));
                } else {
                    wp_schedule_single_event(time() + PAYMENT_TIME_WINDOW * MINUTE_IN_SECONDS, 'ui_check_user_orders_event');
                }
            }
        }
        
        function check_abandoned_users($request) {
            
            $uid = $request['user_id'];
            if ($uid) {
                $this->schedule_user_deletion_event($uid);
            } else {
                $this->schedule_user_deletion_event(null);
            }
            
        }
        
        public function update_user_data($request, $order, $order_meta) {
            if (!$request['user_id']) {
                $this->lgl->helper->warning('LGL WP Users: No user ID in request for update_user_data()');
                return;
            }
            
            $uid = $request['user_id'];
            update_user_meta( $uid, 'user-phone', $order->get_billing_phone());
            update_user_meta( $uid, 'user-company', $order->get_billing_company());
            update_user_meta( $uid, 'user-address-1', $order->get_billing_address_1());
            update_user_meta( $uid, 'user-address-2', $order->get_billing_address_2());
            update_user_meta( $uid, 'user-city', $order->get_billing_city());
            update_user_meta( $uid, 'user-state', $order->get_billing_state());
            update_user_meta( $uid, 'user-postal-code', $order->get_billing_postcode());
            
            update_user_meta( $uid, 'user-languages', isset( $order_meta['languages'] ) ? $order_meta['languages'] : '' );
            update_user_meta( $uid, 'user-country-of-origin', isset( $order_meta['country'] ) ? $order_meta['country'] : '' );
            update_user_meta( $uid, 'user-referral', isset( $order_meta['referral'] ) ? $order_meta['referral'] : '' );
            update_user_meta( $uid, 'user-reason-for-membership', isset( $order_meta['reason'] ) ? $order_meta['reason'] : '' );
            update_user_meta( $uid, 'about-me', isset( $order_meta['about'] ) ? $order_meta['about'] : '' );
        }
        
        public function update_user_subscription_info($uid, $order_id) {
            
            $subscriptions = wcs_get_subscriptions_for_order($order_id, array('order_type' => 'any'));
            
            if ($subscriptions) {
                // Sort subscriptions by creation date in descending order
                usort($subscriptions, function($a, $b) {
                    return strtotime($b->get_date_created()) - strtotime($a->get_date_created());
                });
                
                $most_recent_subscription = reset($subscriptions);
                
                
                // Now you can use $most_recent_subscription as the most recent subscription object
                if ($most_recent_subscription) {
                    $subscription_id = $most_recent_subscription->get_id();
                    // Get the subscription object
                    if ($subscription_id) {
                        
                        $subscription = wcs_get_subscription($subscription_id);
                        
                        if ($subscription) {
                            // Retrieve subscription details
                            $status = $subscription->get_status(); // Subscription status (e.g., active, on-hold, cancelled)
                            
                            $start_date = $subscription->get_time('start'); // Subscription start date
                            $renewal_date = $subscription->get_time('next_payment');
                            if ($renewal_date === 0) { 
                                $renewal_date = strtotime('today');
                            }
                            
                            $billing_interval = $subscription->get_billing_interval(); // Billing interval (e.g., 1 for annual subscription)
                            $billing_period = $subscription->get_billing_period(); // Billing period (e.g., year)
                            
                            // Update user meta with subscription details
                            update_user_meta($uid, 'user-subscription-status', $status);
                            update_user_meta($uid, 'user-membership-start-date', $start_date);
                            update_user_meta($uid, 'user-membership-renewal-date', $renewal_date);
                            update_user_meta($uid, 'user-subscription-id', $subscription_id);
                            
                            $this->lgl->helper->info('LGL WP Users: User subscription info updated', [
                                'user_id' => $uid,
                                'order_id' => $order_id,
                                'subscription_id' => $subscription_id,
                                'status' => $status
                            ]);
                        }
                        
                    }
                }
            }
            
            
        }
        
        function create_jetengine_post_on_order_completion($order_id, $order_meta, $product, $attendee = NULL) {
            // Get the order object
            $order = wc_get_order($order_id);
            $order_date = $order->get_date_created(); // Get the order's creation date

            
            // Iterate through each item in the order
            foreach ($order->get_items() as $item_id => $item) {
                $product_id = $item->get_product_id();
                $product_variation_id = $item->get_variation_id();
                
                // Get the correct product object
                $product = wc_get_product($product_variation_id ? $product_variation_id : $product_id);
                
                // Check if the product is of type 'language_class'
                if (has_term('language-class', 'product_cat', $product_id)) {
                    // Extract relevant data from the product
                    $class_name = $item->get_name();
                    $class_level = get_post_meta($product_id, '_lc_class_level', true); // Replace with the actual meta key
                    
                    // Add more meta data as needed
                    $product_meta = get_post_meta($product_id);
                //    $this->lgl->helper->debug('CCT creation, $product_meta', $product_meta);
                    
                    // Create an array of data for the new post
                    $post_data = array(
                        '_ID' => $order_id,
                        'user_id' => $order->get_customer_id(),
                        'user_firstname' => $order->get_billing_first_name(),
                        'user_lastname' => $order->get_billing_last_name(),
                        'user_email' => $order->get_billing_email(),
                        'user_phone' => $order->get_billing_phone(),
                        'user_preferred_language' => isset($order_meta['languages']) ? $order_meta['languages'] : '',
                        'user_home_country' => isset($order_meta['country']) ? $order_meta['country'] : '',
                        'class_name' => $class_name,
                        'class_price' => $order->get_total(),
                        'class_semester' => isset($product_meta['_lc_class_semester'][0]) ? $product_meta['_lc_class_semester'][0] : '',
                        'class_meeting_days' => isset($product_meta['_lc_class_meeting_days'][0]) ? $product_meta['_lc_class_meeting_days'][0] : '',
                        'class_post_id' => $product_id,
                        'class_order_id' => $order_id,
                        'created_at' => $order_date ? $order_date->format('Y-m-d H:i:s') : current_time('mysql'), // Match order date

                    );
                    
                    // Insert the new post using JetEngine's function
                    $post_id = jet_cct_api_update_item('class_registrations', $post_data);
                    if ($post_id) {
                        $this->lgl->helper->info('LGL WP Users: Class registration CCT created', ['order_id' => $order_id, 'cct_id' => $post_id, 'class_name' => $class_name]);
                    }
                }
            }
        }

        public function create_event_registration_cct($order, $attendees) {
            if (!$order) {
                $this->lgl->helper->error('LGL WP Users: Invalid order object for event registration CCT');
                return;
            }

            $order_date = $order->get_date_created(); // Get the order's creation date

        
            foreach ($attendees as $attendee) {
                $product = $attendee['product'];
                $product_id = $attendee['product_id'];
                $parent_id = $attendee['parent_id'];
        
                $product_meta = get_post_meta($parent_id);
                $event_datetime = get_post_meta($parent_id, '_ui_event_start_datetime', true);
        
                $post_data = array(
                    '_ID' => $order->get_id(),
                    'user_id' => $order->get_customer_id(),
                    'user_name' => $attendee['attendee_name'],
                    'user_email' => $attendee['attendee_email'],
                    'user_phone' => $order->get_billing_phone(),
                    'event_name' => $product->get_name(),
                    'event_option' => $attendee['variation_name'],
                    'event_datetime' => $event_datetime,
                    'event_price' => $this->lgl->helper->get_variation_price($product_id),
                    'event_associated_order' => $order->get_id(),
                    'created_at' => $order_date ? $order_date->format('Y-m-d H:i:s') : current_time('mysql'), // Match order date

                );
        
                $post_id = jet_cct_api_update_item('_ui_event_registrations', $post_data);
                if ($post_id) {
                    $this->lgl->helper->info('LGL WP Users: Event registration CCT created', [
                        'order_id' => $order->get_id(),
                        'cct_id' => $post_id,
                        'attendee_name' => $attendee['attendee_name'],
                        'event_name' => $product->get_name()
                    ]);
                }
            }
        }
        


        function sync_orders_to_ccts($date_from = null, $date_to = null) {
            // Set default date range if not provided
            $date_from = $date_from ?: '2024-12-17'; // Last known sync date
            $date_to = $date_to ?: date('Y-m-d'); // Current date
        
            // MEMORY FIX: Process orders in batches to prevent memory exhaustion
            $batch_size = 50; // Process 50 orders at a time
            $page = 1;
            $has_more = true;
            
            $event_orders_synced = [];
            $language_orders_synced = [];
            
            while ($has_more) {
                // Query WooCommerce orders in batches
                $args = [
                    'status' => 'completed', // Only completed orders
                    'date_created' => $date_from . '...' . $date_to,
                    'limit' => $batch_size, // Process in batches
                    'page' => $page,
                    'orderby' => 'date',
                    'order' => 'ASC'
                ];
                $orders = wc_get_orders($args);
        
                if (empty($orders)) {
                    $has_more = false;
                    break;
                }
                
                // Process this batch
                foreach ($orders as $order) {
                    // Skip refunds
                    if (is_a($order, 'WC_Order_Refund')) {
                        continue;
                    }                
                    
                    $order_id = $order->get_id();

                    // Check if a CCT for this order_id already exists using jet_cct_api_query()
                    $existing_ccts = jet_cct_api_query('_ui_event_registrations', [
                        [
                            'field'    => 'event_associated_order',
                            'operator' => '=',
                            'value'    => $order_id,
                        ],
                    ]);

                    if (!empty($existing_ccts)) {
                        continue; // Skip this order
                    }
            
                    // Check if order has already been synced (additional layer of safety)
                    if ($order->get_meta('_cct_synced')) {
                        continue; // Skip already-synced orders
                    }
            
                    $create_attendee_cct_flag = false;
                    $attendee_index = 0;
                    $attendees = [];
                    $uid = $order->get_customer_id();
                    $order_meta = [
                        'languages' => $order->get_meta('_order_languages_spoken'),
                        'country' => $order->get_meta('_order_country_of_origin'),
                        'referral' => $order->get_meta('_order_referral_source'),
                        'reason' => $order->get_meta('_order_reason_for_membership'),
                        'about' => $order->get_meta('_order_tell_us_about_yourself'),
                    ];
            

                    // Iterate through each product in the order
                    foreach ($order->get_items() as $product) {
                        $product_name = $product->get_name();
                        $quantity = $product->get_quantity();
                        $product_id = $product->get_variation_id() ?: $product->get_product_id();
                        $parent_id = $product->get_variation_id() ? $product->get_product_id() : $product_id;
            
                        if (has_term('language-class', 'product_cat', $parent_id)) {
                            // Sync Language Class Registration
                            $this->create_jetengine_post_on_order_completion($order->get_id(), $order_meta, $product);
                            $language_orders_synced[] = $order->get_id();

                        } elseif (has_term('events', 'product_cat', $parent_id)) {
                            // Sync Event Registration
                            $create_attendee_cct_flag = true;
            
                            for ($j = 0; $j < $quantity; $j++) {
                                $suffix = $attendee_index === 0 ? '' : '_' . $attendee_index;
                                $attendee_name = $order->get_meta('attendee_name' . $suffix);
                                $attendee_email = $order->get_meta('attendee_email' . $suffix);
            
                                if (empty($attendee_name) || empty($attendee_email)) {
                                    continue; // Skip if the meta fields are empty
                                }
            
                                $attendee = [
                                    'attendee_name' => $attendee_name,
                                    'attendee_email' => $attendee_email,
                                    'product' => $product,
                                    'product_id' => $product_id,
                                    'parent_id' => $parent_id,
                                    'variation_name' => $this->lgl->helper->get_variation_name($product_id),
                                ];
                                $attendees[] = $attendee;
                                $attendee_index++; // Increment the counter
                            }
            
                            if ($create_attendee_cct_flag) {
                                $this->create_event_registration_cct($order, $attendees);
                                // add the given order to an $orders_synced array so we can tell how many orders have been synced.
                                $event_orders_synced[] = $order->get_id();
                            }
                            
                        }
                        
                    }
            
                    // Mark the order as synced
                    $order->update_meta_data('_cct_synced', true);
                    $order->save();
                    
                    // Free memory by unsetting order object
                    unset($order);
                }
                
                // Check if we got fewer orders than requested (last page)
                if (count($orders) < $batch_size) {
                    $has_more = false;
                } else {
                    $page++;
                }
                
                // Free memory by unsetting orders array
                unset($orders);
                
                // Safety limit to prevent infinite loops
                if ($page > 1000) {
                    $this->lgl->helper->warning('LGL WP Users: sync_orders_to_ccts hit safety limit', ['page' => $page]);
                    break;
                }
            }
            
            $event_count = count($event_orders_synced);
            $language_count = count($language_orders_synced);
            
            if ($event_count > 0 || $language_count > 0) {
                $this->lgl->helper->info('LGL WP Users: Orders synced to CCTs', [
                    'event_orders' => $event_count,
                    'language_orders' => $language_count
                ]);
            }
            
            $output = '';
            $output = $event_count . ' event orders synced successfully.   ';
            $output .= $language_count . ' language orders synced successfully.';

            return $output;
        }
        

        public function register_sync_dashboard_widget() {
            wp_add_dashboard_widget(
                'cct_sync_widget', // Widget slug
                'Sync Orders to CCTs', // Widget title
                [ $this, 'render_sync_dashboard_widget' ] // Callback function
            );
        }
        
        public function render_sync_dashboard_widget() {
            ?>
            <form method="post" action="">
                <p>Sync orders and update JetEngine CCTs.</p>
                <label for="date_from">From:</label>
                <input type="date" name="date_from" id="date_from" value="2024-12-17">
                <label for="date_to">To:</label>
                <input type="date" name="date_to" id="date_to" value="<?php echo date('Y-m-d'); ?>">
                <?php wp_nonce_field('sync_ccts', 'sync_ccts_nonce'); ?>
                <button type="submit" name="sync_orders" class="button button-primary">Sync Orders</button>
                <button type="submit" name="reset_sync" class="button button-secondary">Reset Sync</button>
            </form>
            <?php
        
            // Handle form submission
            if ($_SERVER['REQUEST_METHOD'] === 'POST') {
                if (isset($_POST['sync_orders']) && check_admin_referer('sync_ccts', 'sync_ccts_nonce')) {
                    $date_from = sanitize_text_field($_POST['date_from']);
                    $date_to = sanitize_text_field($_POST['date_to']);
                    $result = $this->sync_orders_to_ccts($date_from, $date_to);
                    echo '<p><strong>' . esc_html($result) . '</strong></p>';
                }
        
                if (isset($_POST['reset_sync']) && check_admin_referer('sync_ccts', 'sync_ccts_nonce')) {
                    $result = $this->reset_cct_sync_status();
                    echo '<p><strong>' . esc_html($result) . '</strong></p>';
                }
            }
        }
        

        function reset_cct_sync_status() {
            // MEMORY FIX: Process orders in batches to prevent memory exhaustion
            $batch_size = 50; // Process 50 orders at a time
            $page = 1;
            $has_more = true;
            $total_reset = 0;
            
            while ($has_more) {
                // Query WooCommerce orders in batches
                $args = [
                    'limit' => $batch_size,
                    'page' => $page,
                    'orderby' => 'date',
                    'order' => 'DESC'
                ];
                $orders = wc_get_orders($args);
        
                if (empty($orders)) {
                    $has_more = false;
                    break;
                }
        
                foreach ($orders as $order) {
                    // Reset the '_cct_synced' meta field
                    $order->update_meta_data('_cct_synced', false);
                    $order->save();
                    $total_reset++;
                    
                    // Free memory
                    unset($order);
                }
                
                // Check if we got fewer orders than requested (last page)
                if (count($orders) < $batch_size) {
                    $has_more = false;
                } else {
                    $page++;
                }
                
                // Free memory
                unset($orders);
                
                // Safety limit to prevent infinite loops
                if ($page > 1000) {
                    $this->lgl->helper->warning('LGL WP Users: reset_cct_sync_status hit safety limit', ['page' => $page]);
                    break;
                }
            }
        
            return $total_reset . ' orders reset successfully.';
        }
        
    } // end class defintion
}