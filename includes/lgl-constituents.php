<?php
/**
* File Name => lgl-constituents.php
* Version => 1.0
* Plugin URI =>  https =>//github.com/askinne2/Little-Green-Light-API
* Description => This class defines a Class for LGL Constituents
* Author URI => http =>//github.com/askinne2
*/

if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly
}


define('CONSTITUENTS_DEBUG', true);

define('FILE_PATH', plugin_dir_path( __FILE__ ));
require_once FILE_PATH . '../lgl-api.php';
require_once FILE_PATH . 'lgl-api-settings.php';
require_once FILE_PATH . 'lgl-helper.php';

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
        
        var $lgl;
        
        var $remove_previous_email_addresses;
        var $remove_previous_phone_numbers;
        var $remove_previous_street_addresses;
        var $remove_previous_web_addresses;
        
        
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
        public function __construct() 
        {
            $this->lgl = LGL_API::get_instance();
            
            $this->remove_previous_email_addresses = FALSE;
            $this->remove_previous_phone_numbers = FALSE;
            $this->remove_previous_street_addresses = FALSE;
            $this->remove_previous_web_addresses = FALSE;
            
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
                $this->address_data->city = get_user_meta($user_id, 'user-city', true);
                $this->address_data->state = get_user_meta($user_id, 'user-state', true);
                $this->address_data->postal_code = get_user_meta($user_id, 'user-postal-code', true);
                
            }
            
            public function find_setting_key($itemNameToFind, $listColumnName, $list) {
                if (!$list) {
                    $this->lgl->helper->debug('The list is empty. Nothing to search.'); 
                    return; 
                }
                
                $foundItem = (object) array();
                
                $itemIndex = array_search($itemNameToFind, array_column($list, $listColumnName));
                $this->lgl->helper->debug('Item Index' , $itemIndex);
                $this->lgl->helper->debug('Looking for ' . $itemNameToFind); // . ' in this object: ', $list);
                
                if ($itemIndex !== false) {
                    $foundItem = $list[$itemIndex];
                } else {
                    $this->lgl->helper->debug('Couldn\'t find ' . $itemNameToFind);
                }
                
                if ($foundItem) {
                    return $foundItem;
                }
            }
            
            
            public function set_membership($user_id, $note=NULL, $method=NULL, $parent_uid=NULL) {
                $lgl_settings = LGL_API_Settings::get_instance();
                $level_settings = $lgl_settings->lgl_get_setting('membership_levels');
                /*
                $this->lgl->helper->debug('level settings:', $level_settings);
                $response = $this->lgl->connection->get_lgl_data('MEMBERSHIPS');
                $this->lgl->helper->debug('MEMBERSHIPS returned:', $response);
                */
                
                if (!$method) {
                    $level = get_user_meta($user_id, 'user-membership-type', true);
                    if (!$level) {
                        /*
                        $user_meta = get_userdata($user_id);
                        $this->lgl->helper->debug('user meta: ', $user_meta);
                        $level = $user_meta['user-membership-type'][0];
                        */
                        $this->lgl->helper->debug('**** constituents->set_membership(): NO MEMEBERSHIP TYPE ****', $level); //, $user_meta . '    ' . $level);
                    }
                } else {
                    $level = get_user_meta($parent_uid, 'user-membership-type', true);
                }
                
                if (is_numeric($level)) {
                    $this->lgl->helper->debug('****** UI Memberships Price -> Name SWITCH *****');
                    $level = $this->lgl->helper->ui_membership_price_to_name($level);
                }
                
                $this->lgl->helper->debug('level: ', $level);
                $level_setting = $this->find_setting_key($level, 'membership_type', $level_settings);
                // $this->lgl->helper->debug('set_membership() level_setting_object search:', $level_setting);
                
                if (strcmp($level,'Individual Membership')  === 0) {
                    
                    $this->membership_data->membership_level_name = $level_setting['membership_type'];
                    $this->membership_data->membership_level_id = $level_setting['membership_id'];
                    
                } else if (strcmp($level, 'Family Membership')  === 0) {
                    
                    $this->membership_data->membership_level_name = $level_setting['membership_type'];
                    $this->membership_data->membership_level_id = $level_setting['membership_id'];
                    
                } else if ( strcmp($level, 'Patron Membership') === 0) {
                    
                    $this->membership_data->membership_level_name = $level_setting['membership_type'];
                    $this->membership_data->membership_level_id = $level_setting['membership_id'];
                    
                    
                } else if ( strcmp($level, 'Patron Family Membership') === 0) {
                    
                    $this->membership_data->membership_level_name = $level_setting['membership_type'];
                    $this->membership_data->membership_level_id = $level_setting['membership_id'];
                    
                } else if ( strcmp($level, 'Daily Plan') === 0) {
                    
                    $this->membership_data->membership_level_name = $level_setting['membership_type'];
                    $this->membership_data->membership_level_id = $level_setting['membership_id'];
                    
                } else {                    
                    $this->lgl->helper->debug ('bad data in LGL_Constituents::set_membership() ', $level);
                    
                }
                
                if (!$method) {
                    
                    $start_date = get_user_meta($user_id, 'user-membership-start-date', true);                    
                    $renewal_date = get_user_meta($user_id, 'user-membership-renewal-date', true);
                    // Convert Unix timestamp to DateTime
                    $start_date_dt = new DateTime("@$start_date");
                    $renewal_date_dt = new DateTime("@$renewal_date");                    
                    // Format the DateTime objects
                    $this->membership_data->date_start = $start_date_dt->format('Y-m-d');
                    $this->membership_data->finish_date = $renewal_date_dt->format('Y-m-d');

                } else { 
                    
                    $start_date = get_user_meta($parent_uid, 'user-membership-start-date', true);                    
                    $renewal_date = get_user_meta($parent_uid, 'user-membership-renewal-date', true);
                    // Convert Unix timestamp to DateTime
                    $start_date_dt = new DateTime("@$start_date");
                    $renewal_date_dt = new DateTime("@$renewal_date");
                    // Format the DateTime objects
                    $this->membership_data->date_start = $start_date_dt->format('Y-m-d');
                    $this->membership_data->finish_date = $renewal_date_dt->format('Y-m-d');
                } 
                
                
                if ($note) {
                    $this->membership_data->note = $note;
                } else {
                    $this->membership_data->note = 'Renewal via WP_LGL_API';
                }
                
                //$this->lgl->helper->debug(' Membership info: ', $this->membership_data);
                
            }
            
            public function set_data($user_id, $skip_membership=NULL) {
                
                $user_info = get_userdata($user_id);
                //$this->lgl->helper->debug('USER INFO', $user_info);
                
                $firstname = ucfirst(get_user_meta( $user_id, 'first_name', true ));
                $lastname = ucfirst(get_user_meta( $user_id, 'last_name', true ));
                $emailaddress = get_user_meta( $user_id, 'user_email', true); 
                if (!$emailaddress) {
					$user_info = get_userdata($user_id);
					$emailaddress = $user_info->data->user_email;
				}
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
                $this->personal_data->date_added = date('Y-m-d', time());
                
                
                $this->set_address($user_id);
                if (!$skip_membership) {
                    $this->set_membership($user_id, 'Renewal via WP_LGL_API');
                } else {
                    $this->lgl->helper->debug('constituent->set_data() inside skip membership');
                }
                
                /*
                $this->lgl->helper->debug('email address', $this->email_data);
                $this->lgl->helper->debug('user-phone', $this->phone_data);
                $this->lgl->helper->debug('address', $this->address_data);          
                */
                
            }
            
            public function set_data_and_update($user_id, $request) {
                
                if (!$user_id || empty($request)) {
                    return;
                }
                $flags = array(
                    'remove_previous_email_addresses' => TRUE,  
                    'remove_previous_phone_numbers' => TRUE,
                    'remove_previous_street_addresses' => TRUE,
                    'remove_previous_web_addresses' => TRUE,
                );
                
                $firstname = ucfirst($request['user_firstname']);
                $lastname = ucfirst($request['user_lastname']);
                $emailaddress = $request['user_email'];
                $phone = $request['user_phone'];
                
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
                
                $this->address_data->street = $request['user-address-1'] . ' ' . $request['user-address-2'];
                $this->address_data->city = $request['user-city'];
                $this->address_data->state = $request['user-state'];
                $this->address_data->postal_code = $request['user-postal-code'];
                
                //$this->set_address($user_id);
                
                // Convert nested stdClass objects to arrays
                $personal_data = (array) $this->personal_data;
                $email_data = (array) $this->email_data;
                $phone_data = (array) $this->phone_data;
                $address_data = (array) $this->address_data;
                $category_data = (array) $this->category_data;
                $groups_data = (array) $this->groups_data;
                $custom_data = (array) $this->custom_data;
                
                
                // Combine arrays into the final structure
                $update_data = array_merge(
                    $personal_data,
                    $flags,
                    array("email_addresses" => array($email_data)),
                    array("phone_numbers" => array($phone_data)),
                    array("street_addresses" => array($address_data)),
                );
                return $update_data;
                
            }
        }
    }