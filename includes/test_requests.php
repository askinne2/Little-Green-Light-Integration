<?php 

class Test_Requests {
    private static $instance = null;
    
    var $registration_request;
    var $class_reg;
    var $update_membership;
    var $add_family_member_request;
    
    /**
    * Get instance
    *
    * @return 
    */
    public static function get_instance() {
        if (is_null(self::$instance)) {
            self::$instance = new self();
        }
        
        return self::$instance;
    }
    public function __construct() 
    {
    }
    
    public function make_registration() {
        
        $this->registration_request = array(
            'payment_type' => 'credit-card',
            'ui-membership-prices' => '100',
            'price_display_only' => '100.00',
            'user_firstname' => 'Andrew',
            'user_lastname' => 'Skinner',
            'user_company' => '21 ads media',
            'username' => 'test_lgl',
            'user_email' =>'test_lgl@g.com',
            '_password' => 'merrimack1',
            '_confirm_password' => 'merrimack1',
            'user_phone' => '314 - 234 - 1324',
            'user-address-1' =>'57 Blake Street',
            'user-address-2' => '',
            'user-city' => 'Greenville',
            'user-state' => 'South Carolina',
            'user-postal-code' => '29605',
            
            'user-country-of-origin' => 'US',
            'user-languages' => Array(
                0 => 'English'
            ),
            'user-referral' => '',
            'tell_us_about_yourself' => '',
            'user-reason-for-membership' => '',
            'current_date' => time(),
            'start_date' => time(),
            
            'price' => '100.00',
            'ui-membership-type' => 'Daily Plan',
            'ui-membership-level-paypal' => 'P-69A66110YV400061FMVG7T2Y',
            'gateway_subscription_id' => '50',
            
            'user_id' => 1214,
            'inserted_post_id' => 65671,
            'inserted_ui_membership_orders' => 65671,
            'inserted_cct_membership_orders' => 166
        );
    }
    public function make_class_registration() {
        $this->class_reg = array (
            'user_id' => 207,
            'class_id' => 62208,
            'username' => 'Andrew Skinner',
            'user_firstname' => 'Andrew',
            'user_lastname' => 'Skinner',
            'user_email' => 'askinne22@gmail.com',
            'user_phone' => '865-312-0285',
            'class_name' => 'Spanish A2',
            'class_level' => 'A2',
            'class_semester' => 'Fall 2023',
            'class_type' => 'Regular',
            'class_price' => 150,
            'class_start_time' => '09:00',
            'class_end_time' => '10:00',
            'class_meeting_days' => 150,
            'lgl_fund_id' => '3317',
            'user_preferred_language' => 'English',
            'user_home_country' => 'US',
            'order_notes' => '',
            'inserted_post_id' => 63047,
            'inserted_ui_membership_orders' => 63047,
            'inserted_cct_class_registrations' => 56
        );
    }
    
    public function make_update_membership() {
        
        $this->update_membership = array(
            'user_id' => 88,
            'user_name' => 'merrimack merrimack',
            'user_email' => 'merrimack2@m.com',
            'current_date' => 1691608359,
            'user_membership_level_new' => 'Family Membership',
            'price' => '100.00',
            'inserted_post_id' => 59162,
            'inserted_ui_membership_orders' => 59162,
            'inserted_cct_membership_orders' => 44,
            
        );
    }
    
    public function set_family_member_request() {
        $this->add_family_member_request =  array
        (
            'user_firstname' => get_user_meta($uid, 'first_name', true),
            'user_lastname' =>  get_user_meta($uid, 'last_name', true),
            'username' =>  get_user_meta($uid, 'username', true),
            'user_email' => get_user_meta($uid, 'user_email', true),
            '_password' => get_user_meta($uid, '_password', true),
            '_confirm_password' => get_user_meta($uid, '_confirm_password', true),
            'user_phone' => get_user_meta($uid, 'user-phone', true),
            'user-address-1' => get_user_meta($uid, 'user-address-1', true),
            'user-address-2' => get_user_meta($uid, 'user-address-2', true),
            'user-city' => get_user_meta($uid, 'user-city', true),
            'user-state' => get_user_meta($uid, 'user-state', true),
            'user-postal-code' => get_user_meta($uid, 'user-postal-code', true),
            'user-reason-for-membership' => get_user_meta($uid, 'user-reason-for-membership', true),
            'current_date' => strtotime(date('Y-m-d')),
            'parent_user_id' => 130,
            'parent-user-membership-level' => 'Family Membership',
            '__form_id' => 1133,
            '__refer' => 'http://localhost:10046/account/add-family-members/',
            '__is_ajax' => '',
            'user_id' => $uid
        );
    }
    

}
