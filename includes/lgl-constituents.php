<?php
/**
* File Name => lgl-constituents.php
* Version => 1.0
* Plugin URI =>  https =>//github.com/askinne2/Little-Green-Light-API
* Description => This class defines a Class for LGL Constituents
* Author URI => http =>//github.com/askinne2
*/

define('CONSTITUENTS_DEBUG', false);

define('FILE_PATH', plugin_dir_path( __FILE__ ));
require_once FILE_PATH . '../lgl-api.php';
require_once FILE_PATH . 'lgl-api-settings.php';

if (!class_exists("LGL_Constituents")) {
    /**
    * class =>   Little Green Light_API_Settings
    * desc =>    Creates the settings pages for the Little Green Light API plugin
    */
    class LGL_Constituents
    {
        /**
        * Class instance
        *
        * @var null|LGL_Constituents
        */
        private static $instance = null;
        var $personal_data;
        var $email_data;
        var $phone_data;
        var $address_data;
        var $category_data;
        var $groups_data;
        var $custom_data;
        var $membership_data;
        var $payment_data;

        var $membership_types;
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
            
            $this->personal_data = (object) array (
                "external_constituent_id" => 0,
                "is_org" => false,
                "constituent_contact_type_id" => 1247,
                "constituent_contact_type_name" => "Primary",
                "prefix" => "",
                "first_name" => "",
                "middle_name" => "",
                "last_name" => "",                
                "suffix" => "",
                "spouse_name" => "",
                "org_name" => "",
                "job_title" => "",
                "addressee" => "",
                "salutation" => "",
                "is_deceased" => false,
                "deceased_date" => "",
                "annual_report_name" => "",
                "birthday" => "date",
                "gender" => "",
                "maiden_name" => "",
                "nick_name" => "",
                "spouse_nick_name" => "",
                "date_added" => "date",
                "alt_salutation" => "",
                "alt_addressee" => "",
                "honorary_name" => "",
                "assistant_name" => "",
                "marital_status_id" => 0,
                "marital_status_name" => "",
                "is_anon" => false
            );
            $this->email_data = (object) array (
                "address" => "",
                "email_address_type_id" => 1,
                "email_type_name" => "Home",
                "is_preferred" => true,
                "not_current" => false
                
            );
            $this->phone_data = (object) array (
                "number" => "",
                "phone_number_type_id" => 1,
                "phone_type_name" => "Home",
                "is_preferred" => true,
                "not_current" => false
                
            );
            $this->address_data = (object) array (
                "street" => "",
                "street_address_type_id" => 1,
                "street_type_name" => "Home",
                "city" => "",
                "state" => "",
                "postal_code" => "",
                "county" => "",
                "country" => "",
                "seasonal_from" => "01-01",
                "seasonal_to" => "12-31",
                "seasonal" => false,
                "is_preferred" => true,
                "not_current" => false
            );
            $this->category_data = (object) array (
                "id" => 0,
                "name" => "",
                "key" => "",
                "keywords" => array(
                    "id" => 0,
                    "name" => "",
                    "short_code" => ""
                    )
                );
                $this->groups_data = (object) array (
                    "group_id" => 0,
                    "group_name" => "",
                    "date_start" => "date",
                    "date_end" => "date",
                    "is_current" => false
                );
                $this->custom_data = (object) array (
                    "id" => 0,
                    "key" => "",
                    "value" => ""
                );
                
                $this->membership_data = (object) array (
                    "membership_level_id" => 0,
                    "membership_level_name" => "",
                    "date_start" => "date",
                    "finish_date" => "date",
                    "note" => ""
                    
                );

                
            } // end __construct()

            public function debug($string, $data=NULL) {
                printf('<h6 style="color: red;">%s</h3><pre>', $string);
                print_r($data);
                printf('</pre>');
            }

            public function set_membership_types($types) {
                $this->membership_types = $types;
            }

            public function set_name($first, $last) {
                
                $this->personal_data->first_name = $first;
                $this->personal_data->last_name = $last;
                
            }
            
            public function set_email($emailaddress) {
                $this->email_data->address = $emailaddress;
            }
            
            public function set_phone($phone) {
                $this->phone_data->number = $phone;           
            }
            
            public function set_address($user_id) {
                
                $this->address_data->street = get_user_meta($user_id, 'user-address-1', true) . ' ' . get_user_meta($user_id, 'user-address-2', true);
                if (CONSTITUENTS_DEBUG) $this->debug('address', $this->address_data->address);
                $this->address_data->city = get_user_meta($user_id, 'user-city', true);
                $this->address_data->state = get_user_meta($user_id, 'user-state', true);
                $this->address_data->postal_code = get_user_meta($user_id, 'user-postal-code', true);
                
            }
            
            public function set_membership($user_id) {

                $lgl_settings = LGL_API_Settings::get_instance();
                $level_settings = $lgl_settings->lgl_get_setting('membership_levels');

                $level = get_user_meta($user_id, 'user-membership-type', true);
                if ($level === 'Individual Membership') {
                    $key = array_search('Individual', $level_settings);
                    $this->membership_data->membership_level_name = $level_settings[$key]['membership_type'];
                    $this->membership_data->membership_level_id = $level_settings[$key]['membership_id'];
                } else if ($level === 'Family Membership') {
                    $key = array_search('Family', $level_settings);
                    $this->membership_data->membership_level_name = $level_settings[$key]['membership_type'];
                    $this->membership_data->membership_level_id = $level_settings[$key]['membership_id'];
                } else if ($level === 'Patron Membership') {
                    $key = array_search('Patron Indiv', $level_settings);
                    $this->membership_data->membership_level_name = $level_settings[$key]['membership_type'];
                    $this->membership_data->membership_level_id = $level_settings[$key]['membership_id'];
                } else if ($level === 'Patron Family Membership') {
                    $key = array_search('Patron Family', $level_settings);
                    $this->membership_data->membership_level_name = $level_settings[$key]['membership_type'];
                    $this->membership_data->membership_level_id = $level_settings[$key]['membership_id'];
                } else {
                    if (PLUGIN_DEBUG) {
                        $this->debug ('bad data in LGL_Constituents::set_membership() ');
                    }
                }
                
                $user_info = get_userdata($user_id);

                $this->membership_data->date_start = date( 'Y-m-d', strtotime($user_info->data->user_registered));
                $this->membership_data->finish_date = date('Y-m-d', strtotime('+1 year', strtotime($this->membership_data->date_start)) );
                $this->membership_data->note = 'Renewal via WP_LGL_API';
                
                
            }
            
            public function set_data($user_id) {
                
                $user_info = get_userdata($user_id);
                if (CONSTITUENTS_DEBUG) $this->debug('USER INFO', $user_info);
                
                $firstname = get_user_meta( $user_id, 'first_name', true );
                $lastname = get_user_meta( $user_id, 'last_name', true );
                $emailaddress = $user_info->data->user_email;
                $phone = get_user_meta( $user_id, 'user-phone', true);
                
                $this->personal_data->external_constituent_id = $user_id;
                $this->set_name($firstname, $lastname);
                $this->set_email($emailaddress);
                $this->set_phone($phone);
                
                $this->personal_data->constituent_contact_type_id = 1247;
                $this->personal_data->constituent_contact_type_name = 'Primary';
                $this->personal_data->addressee = $firstname . ' ' . $lastname;
                $this->personal_data->salutation = $firstname;
                $this->personal_data->annual_report_name = $firstname . ' ' . $lastname;
                $this->personal_data->org_name  = get_user_meta($user_id, 'user-company', true);
                
                
                $this->set_address($user_id);
                
                if (CONSTITUENTS_DEBUG) {
                    $this->debug('email address', $this->email_data);
                    $this->debug('user-phone', $this->phone_data);
                    $this->debug('address', $this->address_data);
                }

                $this->set_membership($user_id);

            }
        }
    }