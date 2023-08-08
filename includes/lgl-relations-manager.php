<?php
/**
* File Name: lgl-relations_manager.php
* Version: 1.0
* Plugin URI:  https://github.com/askinne2/Little-Green-Light-API
* Description: This class interfaces between the JetEngine Relatioships
* Author URI: http://github.com/askinne2
*/

if (!defined('ABSPATH')) {
	exit; // Exit if accessed directly
}

define('RELATIONS_DEBUG', true);

if (!class_exists("LGL_Relations_Manager")) {
    /**
    * class:   Little Green Light_API_Settings
    * desc:    Creates the settings pages for the Little Green Light API plugin
    */
    class LGL_Relations_Manager
    {
        /**
        * Class instance
        *
        * @var null|LGL_WP_Users
        */
        private static $instance = null;

        var $user_to_family;
        var $order_to_classes;
        var $orders_to_memberships;
        var $user_to_class_registrations;
        var $user_to_orders;
        var $all_relations;


        /**
        * Get instance
        *
        * @return LGL_Relations_Manager
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

            $this->user_to_family = array( 
                'id' => 24,
                'url' => get_site_url(). '/wp-json/jet-rel/24'
            );
            $this->order_to_classes = array( 
                'id' => 23,
                'url' => get_site_url(). '/wp-json/jet-rel/23'
            );
            $this->orders_to_memberships = array( 
                'id' => 22,
                'url' => get_site_url(). '/wp-json/jet-rel/22'
            );
            $this->user_to_class_registrations = array( 
                'id' => 16,
                'url' => get_site_url(). '/wp-json/jet-rel/16'
            );
            $this->user_to_orders = array( 
                'id' => 11,
                'url' => get_site_url(). '/wp-json/jet-rel/11'
            );
            $this->all_relations = array(
        
                'user_to_family' => $this->user_to_family, 
                'order_to_classes' => $this->order_to_classes, 
                'orders_to_memberships' => $this->orders_to_memberships,
                'user_to_class_registrations' => $this->user_to_class_registrations,
                'user_to_orders' => $this->user_to_orders
            );
        }
        
        public function debug($string, $data=NULL) {
            if (RELATIONS_DEBUG) {
            printf('<h6 style="color: red;">%s</h3><pre>', $string);
            print_r($data);
            printf('</pre>');
            }
        }
        
        public function get_all_relations() {
            $related_url = get_site_url() . '/wp-json/jet-rel/';
            //if (RELATIONS_DEBUG) $this->debug('ALL RELATIONS', $related_url);
            $raw_response = wp_remote_get($related_url);
            if (is_wp_error($raw_response) || '200' != wp_remote_retrieve_response_code($raw_response)) {
                $this->debug('wp_error', $raw_response);
                return false;
            }
            
            $relations = json_decode(wp_remote_retrieve_body($raw_response));
            if ($relations) {
                return $relations;
            }

            //$this->debug('DECODED', $this->all_relations);
        }
    }
}