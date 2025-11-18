<?php
/**
 * LGL API Compatibility Shim
 * 
 * Provides backward compatibility for legacy code that references LGL_API class
 * 
 * @package UpstateInternational\LGL
 * @since 2.1.0
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * LGL_API Compatibility Class
 * 
 * This class exists ONLY to maintain compatibility with legacy code in includes/
 * It delegates to the modern architecture.
 * 
 * @deprecated Use modern classes from src/ namespace instead
 */
class LGL_API {
    
    /**
     * Singleton instance
     */
    private static $instance = null;
    
    /**
     * Helper instance (legacy)
     */
    public $helper;
    
    /**
     * Connection instance (legacy)
     */
    public $connection;
    
    /**
     * Constants for legacy hooks
     */
    const UI_DELETE_MEMBERS = 'ui_members_monthly_hook';
    
    /**
     * Get singleton instance
     */
    public static function get_instance() {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    /**
     * Constructor - delegates to modern services
     */
    private function __construct() {
        // Get modern services from container
        if (function_exists('lgl_get_container')) {
            try {
                $container = lgl_get_container();
                
                // Map legacy properties to modern services
                if ($container->has('lgl.helper')) {
                    $this->helper = $container->get('lgl.helper');
                }
                
                if ($container->has('lgl.connection')) {
                    $this->connection = $container->get('lgl.connection');
                }
            } catch (\Exception $e) {
                // Container not available, use fallback
                if (class_exists('\UpstateInternational\LGL\LGL\Helper')) {
                    $this->helper = \UpstateInternational\LGL\LGL\Helper::getInstance();
                }
                
                if (class_exists('\UpstateInternational\LGL\LGL\Connection')) {
                    $this->connection = \UpstateInternational\LGL\LGL\Connection::getInstance();
                }
            }
        }
    }
    
    /**
     * Initialize shortcodes (legacy compatibility)
     * 
     * Note: Shortcodes are now handled by ShortcodeRegistry in the modern architecture.
     * This method exists only for backward compatibility and does nothing.
     * 
     * @return void
     */
    public function shortcode_init() {
        // Shortcodes are now registered via ShortcodeRegistry in the modern architecture
        // This method exists only for backward compatibility with legacy code
        // that calls $lgl_api->shortcode_init()
    }
    
    /**
     * Magic method to delegate to modern services
     */
    public function __call($method, $args) {
        // Attempt to call on connection first
        if ($this->connection && method_exists($this->connection, $method)) {
            return call_user_func_array([$this->connection, $method], $args);
        }
        
        // Then try helper
        if ($this->helper && method_exists($this->helper, $method)) {
            return call_user_func_array([$this->helper, $method], $args);
        }
        
        // Method not found
        trigger_error("Method {$method} not found in LGL_API compatibility shim", E_USER_WARNING);
        return null;
    }
    
    /**
     * Magic property getter
     */
    public function __get($property) {
        // Attempt to get from connection first
        if ($this->connection && property_exists($this->connection, $property)) {
            return $this->connection->$property;
        }
        
        // Then try helper
        if ($this->helper && property_exists($this->helper, $property)) {
            return $this->helper->$property;
        }
        
        return null;
    }
}

