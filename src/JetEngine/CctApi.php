<?php
/**
 * JetEngine CCT API Wrapper
 * 
 * Provides wrapper functions for JetEngine Custom Content Types API.
 * This is a critical dependency for CCT registrations.
 * 
 * @package UpstateInternational\LGL\JetEngine
 * @since 2.0.0
 */

namespace UpstateInternational\LGL\JetEngine;

/**
 * CCT API Wrapper Class
 * 
 * Wraps JetEngine CCT API functions for use throughout the plugin.
 * Maintains backward compatibility with legacy function calls.
 */
class CctApi {
    
    /**
     * Initialize CCT API functions
     * 
     * Loads the legacy CCT API file which provides global functions.
     * This ensures backward compatibility while allowing for future refactoring.
     * 
     * @return void
     */
    public static function init(): void {
        $legacy_file = LGL_PLUGIN_DIR . 'includes/jet-engine-cct-api.php';
        if (file_exists($legacy_file)) {
            require_once $legacy_file;
        }
    }
    
    /**
     * Query CCT items
     * 
     * @param string $slug CCT slug
     * @param array $args Query arguments
     * @param int $limit Limit results
     * @param int $offset Offset results
     * @param array $order Order arguments
     * @return array|false Query results or false on failure
     */
    public static function query(string $slug, array $args = [], int $limit = 0, int $offset = 0, array $order = []) {
        if (!function_exists('jet_cct_api_query')) {
            return false;
        }
        return jet_cct_api_query($slug, $args, $limit, $offset, $order);
    }
    
    /**
     * Update or insert CCT item
     * 
     * @param string $slug CCT slug
     * @param array $itemarray Item data
     * @return int|false Item ID or false on failure
     */
    public static function updateItem(string $slug, array $itemarray) {
        if (!function_exists('jet_cct_api_update_item')) {
            return false;
        }
        return jet_cct_api_update_item($slug, $itemarray);
    }
    
    /**
     * Get CCT item by ID
     * 
     * @param string $slug CCT slug
     * @param int $item_id Item ID
     * @return array|object|false Item data or false on failure
     */
    public static function getItem(string $slug, int $item_id) {
        if (!function_exists('jet_cct_api_get_item')) {
            return false;
        }
        return jet_cct_api_get_item($slug, $item_id);
    }
    
    /**
     * Get CCT property
     * 
     * @param array $atts Attributes array
     * @return mixed Property value or false on failure
     */
    public static function getProp(array $atts = []) {
        if (!function_exists('jet_cct_api_get_prop')) {
            return false;
        }
        return jet_cct_api_get_prop($atts);
    }
}

