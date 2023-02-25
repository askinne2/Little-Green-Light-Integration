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
    class LGL_API_Settings
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
        }
        
        
        public function lgl_settings_page() {
            Container::make( 'theme_options', __( 'Example Plugin Page' ) )
            ->set_page_parent( 'options-general.php' )
            ->add_fields( array(
                Field::make( 'text', 'dbi_api_key', 'API Key' ),
                Field::make( 'text', 'dbi_results_limit', 'Results Limit' )
                ->set_attribute( 'min', 1 )
                ->set_attribute( 'max', 100 )
                ->set_default_value( 25 ),
                ) );
            }
            
            
            
            
            public function lgl_get_api_setting( string $setting_name ) {
                return carbon_get_theme_option( $setting_name );
            }
        }
    }
    