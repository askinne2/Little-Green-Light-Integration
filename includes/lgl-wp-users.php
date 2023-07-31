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
            
        }
        
        public function debug($string, $data=NULL) {
			printf('<h6 style="color: red;">%s</h3><pre>', $string);
			print_r($data);
			printf('</pre>');
		}

        public function get_current_user() {
            return wp_get_current_user();
        }
        
        public function get_current_user_id() {
            $user_id = wp_get_current_user()->data->ID;
            if(USERS_DEBUG) {        
                $this->debug('User ID', $user_id);
            }
            return $user_id;         
        }
        
        
        
        /*        
        add_filter( 'rest_authentication_errors', function( $result ) {
            // If a previous authentication check was applied,
            // pass that result along without modification.
            if ( true === $result || is_wp_error( $result ) ) {
                return $result;
            }
            
            // No authentication has been performed yet.
            // Return an error if user is not logged in.
            if ( ! is_user_logged_in() ) {
                return new WP_Error(
                    'rest_not_logged_in',
                    __( 'You are not currently logged in.' ),
                    array( 'status' => 401 )
                );
            }
            
            // Our custom authentication check should have no effect
            // on logged-in requests
            return $result;
        });
        
        */       
        /**** THIS IS COMPLETELY UNSECURE 
        *  Circle Back to this lateR!!
        * 
        */
        public function get_child_objects( $child_id ) {
            $related_url = get_site_url() . '/wp-json/jet-rel/11/children/'.$child_id;

            $this->debug('RELATED URL', $related_url);

            /* perhaps a more secure method? 
            $request = new WP_REST_Request( 'GET', $related_url );
            $request->set_param( 'per_page', 20 );
            $response = rest_do_request( $request );
            $data = rest_get_server()->response_to_data( $response, true );
            //var_dump( $data );
            */
            
            $raw_response = wp_remote_get($related_url);
            if (is_wp_error($raw_response) || '200' != wp_remote_retrieve_response_code($raw_response)) {
                if (USERS_DEBUG) {
                    print('wp_error:');
                    printf('<pre>');
                    print_r($raw_response);
                    printf('</pre>');
                }                
                return 0;
            }
            
            $order_ids = array();
            $children = json_decode(wp_remote_retrieve_body($raw_response));
            foreach ($children as $child) {
                $order_ids[] = $child->child_object_id;
                
                if (USERS_DEBUG) {
                    printf('<hr/><pre>');
                    print_r($child);
                    
                    $myvals = get_post_meta($child->child_object_id);
                    
                    foreach($myvals as $key=>$val)
                    {
                        echo $key . ' : ' . $val[0] . '<br/>';
                    }
                    print('</pre>');
                }
                
            }
            return $order_ids;
        }
    }
}