<?php
/**
* File Name: ui-memberships-wp-users.php
* Version: 1.0
* Plugin URI:  https://github.com/askinne2/Little-Green-Light-API
* Description: This class interfaces between the JetEngine/WP User custom fields & settings
* Author URI: http://github.com/askinne2
*/

define('UI_MEMBERS_USER_DEBUG', true);
define('UI_SEND_MAIL', true);
require_once 'ui-memberships-mailer.php';

define('UI_MEMBER_USERS_FILE_PATH', plugin_dir_path( __FILE__ ));
require_once UI_MEMBER_USERS_FILE_PATH . '../../lgl-wp-users.php';

require_once WP_PLUGIN_DIR . '/jet-engine/jet-engine.php';
require_once WP_PLUGIN_DIR . '/jet-engine/includes/components/relations/relation.php';

if (!class_exists("UI_Memberships_WP_Users")) {
    /**
    * class:   UI Memberships WP Users
    * desc:    Interfaces between WP Users & UI Memberships
    */
    class UI_Memberships_WP_Users
    {
        
        
        var $ui_mailer;
        /**
        * Class instance
        *
        * @var null|UI_Memberships_WP_Users
        */
        private static $instance = null;
        
        var $lgl;
        
        /**
        * Get instance
        *
        * @return UI_Memberships_WP_Users
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
            $this->ui_mailer = UI_Memberships_Mailer::get_instance();
            $this->lgl = LGL_API::get_instance();
        }
        
        public function check_subscription_status($user_id) {
            global $wpdb;
            
            $table_name = $wpdb->prefix . 'ueqdu6vhs3_jet_fb_subscriptions';
            
            // Use $wpdb->prepare() to sanitize the user_id and prevent SQL injection
            $user_id = $wpdb->prepare('%d', $user_id);
            
            // Your SQL query
            //$query = "SELECT * FROM wp_ueqdu6vhs3_jet_fb_subscriptions AS jet_fb_subscriptions  WHERE status = 'active' AND user_id = '$user_id'";
            $query = "SELECT * FROM wp_ueqdu6vhs3_jet_fb_subscriptions AS jet_fb_subscriptions  WHERE user_id = '$user_id'";
            
            // Execute the query
            $results = $wpdb->get_results($query);
            
            // Process the results as needed
            if (!empty($results)) {
                foreach ($results as $subscription) {
                    // Do something with each subscription
                    // For example, check $subscription->subscription_field
                    // $this->lgl->helper->debug('Query Subscription Results ', $subscription);
                    return $subscription->status;
                }
            }
        }
        
        public function check_renewal_date_and_send($user_id, $active_subscription=TRUE) {
            if (!$user_id) {
                $this->lgl->helper->debug('No user_id, ui-memberships-wp-users::check_renewal_date_and_send()');
                return;
            }
            $user_email = get_user_meta($user_id, 'user_email', true);
            $user_data = get_userdata($user_id);
            if ($user_email === '') {
                $user_email = $user_data->user_email;
            }   
            
            $user_firstname = ucfirst(get_user_meta( $user_id, 'first_name', true ));
            $renewal_date_timestamp = get_user_meta($user_id, 'user-membership-renewal-date', true);
            $subscription_status = get_user_meta($user_id, 'user-subscription-status', true);
            $renewal_date = new DateTime('@' . $renewal_date_timestamp, new DateTimeZone('America/New_York'));
            
            $today = new DateTime();
            $interval = $today->diff($renewal_date);
            $days = (int) $interval->format('%r%a');
            
            //$subscription_status = $this->check_subscription_status($user_id);
            
            $this->lgl->helper->debug('Email: ' . $user_email);
            //$this->lgl->helper->debug('Renewal date timestamp: ', $renewal_date_timestamp);
            $this->lgl->helper->debug('Renewal Date: ', $renewal_date->format('Y-m-d H:i:s'));
            $this->lgl->helper->debug('Interval: ', $days);
            $this->lgl->helper->debug('Subscription Status: ', $subscription_status);
            

            /**
             * grace period elapsed, now mark as ui_inactive_member
             * and schedule for deletion via WP Cron Hook after 30 days 
             **/ 
            if ($days == -30) {
                
                $subject = ', Your Upstate International Membership is now INACTIVE';
                $this->set_up_mail_and_send($user_id, $user_email, $user_firstname, $subject, $days, $subscription_status);
                $lgl_users = LGL_WP_Users::get_instance();
                $lgl_users->ui_user_deactivation($user_id, 'cron_job');
                
           /* } else if ($days < 0 && $days >= -29 && $days % 7 === 0) { */
			} else if ($days === -7) {
                
                $subject = ', Your Upstate International Membership Renewal Date has passed!';
                $this->set_up_mail_and_send($user_id, $user_email, $user_firstname, $subject, $days, $subscription_status);
                
            } else if ($days == 0 ) {
                
                $subject = ', Your Upstate International Membership Renewal Date is Today!';
                $this->set_up_mail_and_send($user_id, $user_email, $user_firstname, $subject, $days, $subscription_status);                    

            } else if ($days == 7 || $days == 14 || $days == 30) {
                $subject = ', Your Upstate International Membership Renewal is Coming!';
                $this->set_up_mail_and_send($user_id, $user_email, $user_firstname, $subject, $days, $subscription_status);             
            } 
            
        }
        
        public function set_up_mail_and_send($user_id, $user_email, $user_firstname, $subject, $days, $subscription_status) {
            
            if ($subscription_status === 'in-person') {
                $this->ui_mailer = UI_Memberships_Mailer::get_instance();
                $this->ui_mailer->recipient = $user_email;
                $this->ui_mailer->subject = $user_firstname . $subject;
                $this->ui_mailer->set_content($days);
                if (UI_SEND_MAIL) 
                { $this->ui_mailer->send(); 
                $this->lgl->helper->debug('-----------MAIL SENT! ---------'); 
                }
                
            } 
        }

        public function list_ui_members() {
            $blogusers = get_users( array( 
                'role__in' => array( 'ui_member', 'ui_patron_owner' ) 
            ));
            // Array of WP_User objects.
            foreach ( $blogusers as $user ) {
                $this->lgl->helper->debug('-------------------');
                $user_id = $user->ID;
                $user_data = get_userdata($user_id);
                $subscription_status = get_user_meta($user_id, 'user-subscription-status', true);
                $renewal_date_timestamp = get_user_meta($user_id, 'user-membership-renewal-date', true);
                $payment_method = get_user_meta($user_id, 'payment-method', true);
                $this->lgl->helper->debug('ID:  ' . $user_id);                
                $this->lgl->helper->debug('firstname:  ' . $user_data->first_name);                
                /*
                $this->lgl->helper->debug('Subscription Status: ', $subscription_status);
                $this->lgl->helper->debug('Renewal date timestamp: ', $renewal_date_timestamp);
                $this->lgl->helper->debug('Payment Method: ', $payment_method); 
                */
                
                /**
                * if $renewal_date_timestamp is not set, no code 
                * below here should be excuted. Skip to next user.
                */
                if ($renewal_date_timestamp === '') { // || $payment_method === 'offline') {
                    $this->lgl->helper->debug('Skipping to next user, blank renewal date');
                    continue;
                }
                
                $username = $user_data->user_login;
                //$paypal_subscription_id = get_user_meta($user_id, 'paypal_subscription_id', true);
                if ($subscription_status === 'in-person') {
                    $this->lgl->helper->debug('Old Membership version for ', $username);
                    $this->check_renewal_date_and_send($user_id, false);
                    
                } /* else {
                    $this->check_renewal_date_and_send($user_id, true);
   
                }*/
                
                
            }
        }
        
    }
}