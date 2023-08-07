<?php
/**
* File Name: lgl-api-settings.php
* Version: 1.0
* Plugin URI:  https://github.com/askinne2/Little-Green-Light-API
* Description: This class creates the settings/option page for the Little Green Light API connector
* Author URI: http://github.com/askinne2
*/


if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly
}

use Carbon_Fields\Container;
use Carbon_Fields\Field;

add_action( 'after_setup_theme', 'lgl_api_settings' );
function lgl_api_settings() {
    
    $lgl = LGL_API_Settings::get_instance();
    $lgl->lgl_init();
    require_once( 'vendor/autoload.php' );
    \Carbon_Fields\Carbon_Fields::boot();
    
}   




if (!class_exists("LGL_API_Settings")) {
    /**
    * class:   Little Green Light_API_Settings
    * desc:    Creates the settings pages for the Little Green Light API plugin
    */
    class LGL_API_Settings3
    {
        /**
        * Class instance
        *
        * @var null|LGL_API_Settings
        */
        private static $instance = null;
        /**
        * Get instance
        *
        * @return LGL_API_Settings
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
        
        public function lgl_init() {
            add_action( 'carbon_fields_register_fields', array($this, 'lgl_settings_page') );
           // add_action( 'carbon_fields_register_fields', array($this, 'set_membership_fields'));
        }
        
        
        public function lgl_settings_page() 
        {
            Container::make( 'theme_options', __( 'Little Green Light Settings' ) )
            ->set_page_parent( 'options-general.php' )
            ->add_fields( array(

                Field::make( 'text', 'api_key', __('API Key') ),

                Field::make( 'text', 'results_limit', __('Results Limit') )
                ->set_attribute( 'min', 1 )
                ->set_attribute( 'max', 100 )
                ->set_default_value( 25 ),
                
                Field::make( 'text', 'results_offset', __('Default Offset') )
                ->set_attribute( 'min', 25 )
                ->set_attribute( 'max', 100 )
                ->set_default_value( 25 ),
                
                Field::make( 'hidden', 'constituents_uri', __('Constituents URL'))
                ->set_default_value('https://api.littlegreenlight.com/api/v1/constituents.json'),
                Field::make( 'html', 'endpoints_constituents', __('Database Endpoints') )
                ->set_html( sprintf( '<p>https://api.littlegreenlight.com/api/v1/constituents.json</p>')),

                
                Field::make( 'hidden', 'constituents_uri_search', __('Constituents Search URL'))
                ->set_default_value('https://api.littlegreenlight.com/api/v1/constituents/search.json'),
                Field::make( 'html', 'endpoints_constituents_search', __('Database Endpoints') )
                ->set_html( sprintf( '<p>https://api.littlegreenlight.com/api/v1/constituents/search.json</p>')),
                
                Field::make( 'hidden', 'membership_levels_uri', __('Memberships URL'))
                ->set_default_value('https://api.littlegreenlight.com/api/v1/membership_levels.json'),
                Field::make( 'html', 'endpoints_membership_level', __('Database Endpoints') )
                ->set_html( sprintf( '<p>https://api.littlegreenlight.com/v1/membership_levels.json</p>')),
                
                

                Field::make( 'complex', 'membership_levels', __('Membership Level') )
                ->add_fields( array (
                    Field::make( 'text', 'membership_type', __('Membership Type ') )->set_default_value( 'Individual' )->set_required(TRUE),

                    Field::make( 'text', 'membership_id', __('Membership ID ') )->set_default_value( 412 )->set_required(TRUE),
                )),
               

                ) );

            }
            

            public function set_membership_fields() {

                $lgl = LGL_API::get_instance();
                $response = $lgl->get_lgl_data( 'MEMBERSHIPS');
                if ($response) {
                    $lgl_membership_levels = $response->items;
                  //  print_r($lgl_membership_levels);
                } 

                $levels = array_column($lgl_membership_levels, 'name');
                //$this->debug ('levels', $levels);
                $index = 0;
                foreach ($lgl_membership_levels as $level) {
                    $level = (array) $level;
                    carbon_set_post_meta (10, 'membership_levels[' . $index . ']' , array(
                        array ( 'membership_type' => $level['name'] ),
                        array ( 'membership_id' => $level['id']),
                    ));
                  //  $this->debug ('setting', $index . $level['name']);
                    $index++;
                  }
                
            }
  
            
            public function lgl_get_setting( string $setting_name ) {
                return carbon_get_theme_option( $setting_name );
            }

            public function debug($string, $data=NULL) {
                printf('<h6 style="color: red;">%s</h3><pre>', $string);
                print_r($data);
                printf('</pre>');
            }
        }
    }
    