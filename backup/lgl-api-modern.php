<?php
/**
 * Little Green Light API Integration Plugin
 * 
 * Modern WordPress plugin for integrating with Little Green Light CRM.
 * Provides membership management, event registration, and payment processing.
 * 
 * @link              https://github.com/askinne2/Little-Green-Light-API
 * @since             2.0.0
 * @package           UpstateInternational\LGL
 *
 * @wordpress-plugin
 * Plugin Name:       Little Green Light API Integration
 * Plugin URI:        https://github.com/askinne2/Little-Green-Light-API
 * Description:       Modern WordPress integration with Little Green Light CRM for membership management, events, and payments
 * Version:           2.0.0
 * Author:            Andrew Skinner
 * Author URI:        https://www.21adsmedia.com
 * License:           GPL-2.0+
 * License URI:       http://www.gnu.org/licenses/gpl-2.0.txt
 * Text Domain:       lgl-api
 * Domain Path:       /languages
 * Requires at least: 5.0
 * Requires PHP:      7.4
 * GitHub Plugin URI: https://github.com/askinne2/Little-Green-Light-API
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

// Plugin constants
define('LGL_PLUGIN_VERSION', '2.0.0');
define('LGL_PLUGIN_FILE', __FILE__);
define('LGL_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('LGL_PLUGIN_URL', plugin_dir_url(__FILE__));
define('LGL_PLUGIN_BASENAME', plugin_basename(__FILE__));

// Legacy compatibility constants
define('PLUGIN_DEBUG', false);
define('BUFFER', true);
define('REMOVE_TRANSIENT', false);
define('LGL_DATA_DELAY', 0.1);

/**
 * Initialize the plugin
 */
function lgl_init_plugin() {
    // Load Composer autoloader
    $autoloader = LGL_PLUGIN_DIR . 'vendor/autoload.php';
    
    if (!file_exists($autoloader)) {
        add_action('admin_notices', function() {
            echo '<div class="error"><p><strong>LGL Plugin Error:</strong> Composer autoloader not found. Please run <code>composer install</code> in the plugin directory.</p></div>';
        });
        return;
    }
    
    require_once $autoloader;
    
    // Initialize modern plugin architecture
    try {
        $plugin = \UpstateInternational\LGL\Core\Plugin::getInstance(LGL_PLUGIN_FILE);
        
        // Legacy compatibility - load remaining legacy classes
        lgl_load_legacy_classes();
        
        error_log('LGL Plugin: Modern architecture initialized successfully');
        
    } catch (Exception $e) {
        error_log('LGL Plugin Initialization Error: ' . $e->getMessage());
        
        add_action('admin_notices', function() use ($e) {
            echo '<div class="error"><p><strong>LGL Plugin Error:</strong> ' . esc_html($e->getMessage()) . '</p></div>';
        });
    }
}

/**
 * Load legacy classes for backward compatibility
 */
function lgl_load_legacy_classes() {
    // Legacy includes that haven't been converted yet
    $legacy_includes = [
        'includes/lgl-connections.php',
        'includes/lgl-wp-users.php',
        'includes/lgl-constituents.php',
        'includes/lgl-payments.php',
        'includes/lgl-relations-manager.php',
        'includes/lgl-api-settings.php',
        'includes/decrease_registration_counter_on_trash.php',
        'includes/ui_memberships/ui_memberships.php',
    ];
    
    foreach ($legacy_includes as $file) {
        $file_path = LGL_PLUGIN_DIR . $file;
        if (file_exists($file_path)) {
            require_once $file_path;
        } else {
            error_log('LGL Plugin: Legacy file not found: ' . $file);
        }
    }
    
    // Initialize legacy LGL_API class if it exists
    if (class_exists('LGL_API')) {
        add_action('template_redirect', 'lgl_legacy_shortcode_init', 10);
    }
}

/**
 * Legacy shortcode initialization
 */
function lgl_legacy_shortcode_init() {
    if (class_exists('LGL_API')) {
        $lgl = LGL_API::get_instance();
        $lgl->shortcode_init();
    }
}

/**
 * Plugin activation hook
 */
function lgl_activate_plugin() {
    try {
        // Ensure autoloader is available
        $autoloader = LGL_PLUGIN_DIR . 'vendor/autoload.php';
        if (file_exists($autoloader)) {
            require_once $autoloader;
            
            // Initialize plugin for activation
            $plugin = \UpstateInternational\LGL\Core\Plugin::getInstance(LGL_PLUGIN_FILE);
            $plugin->onActivation();
        }
        
        // Legacy activation
        if (class_exists('LGL_API')) {
            LGL_API::activate();
        }
        
        error_log('LGL Plugin: Activation completed successfully');
        
    } catch (Exception $e) {
        error_log('LGL Plugin Activation Error: ' . $e->getMessage());
        wp_die('Plugin activation failed: ' . $e->getMessage());
    }
}

/**
 * Plugin deactivation hook
 */
function lgl_deactivate_plugin() {
    try {
        // Modern deactivation
        $autoloader = LGL_PLUGIN_DIR . 'vendor/autoload.php';
        if (file_exists($autoloader)) {
            require_once $autoloader;
            
            $plugin = \UpstateInternational\LGL\Core\Plugin::getInstance(LGL_PLUGIN_FILE);
            $plugin->onDeactivation();
        }
        
        // Legacy deactivation
        if (class_exists('LGL_API')) {
            LGL_API::deactivate();
        }
        
        error_log('LGL Plugin: Deactivation completed successfully');
        
    } catch (Exception $e) {
        error_log('LGL Plugin Deactivation Error: ' . $e->getMessage());
    }
}

/**
 * Check plugin requirements
 */
function lgl_check_requirements() {
    $errors = [];
    
    // Check PHP version
    if (version_compare(PHP_VERSION, '7.4', '<')) {
        $errors[] = 'PHP 7.4 or higher is required. Current version: ' . PHP_VERSION;
    }
    
    // Check WordPress version
    if (version_compare(get_bloginfo('version'), '5.0', '<')) {
        $errors[] = 'WordPress 5.0 or higher is required. Current version: ' . get_bloginfo('version');
    }
    
    // Check for required plugins
    if (!class_exists('WooCommerce')) {
        $errors[] = 'WooCommerce plugin is required for full functionality';
    }
    
    if (!empty($errors)) {
        add_action('admin_notices', function() use ($errors) {
            echo '<div class="error"><p><strong>LGL Plugin Requirements:</strong></p><ul>';
            foreach ($errors as $error) {
                echo '<li>' . esc_html($error) . '</li>';
            }
            echo '</ul></div>';
        });
        
        return false;
    }
    
    return true;
}

/**
 * Get plugin instance (for external access)
 */
function lgl_plugin() {
    static $instance = null;
    
    if ($instance === null) {
        $autoloader = LGL_PLUGIN_DIR . 'vendor/autoload.php';
        if (file_exists($autoloader)) {
            require_once $autoloader;
            $instance = \UpstateInternational\LGL\Core\Plugin::getInstance(LGL_PLUGIN_FILE);
        }
    }
    
    return $instance;
}

/**
 * Utility function to get cache manager
 */
function lgl_cache() {
    return \UpstateInternational\LGL\Core\CacheManager::class;
}

/**
 * Utility function to get utilities
 */
function lgl_utilities() {
    return \UpstateInternational\LGL\Core\Utilities::class;
}

// Register hooks
register_activation_hook(__FILE__, 'lgl_activate_plugin');
register_deactivation_hook(__FILE__, 'lgl_deactivate_plugin');

// Initialize plugin after WordPress loads
add_action('plugins_loaded', function() {
    if (lgl_check_requirements()) {
        lgl_init_plugin();
    }
}, 10);

// Add plugin action links
add_filter('plugin_action_links_' . LGL_PLUGIN_BASENAME, function($links) {
    $settings_link = '<a href="' . admin_url('admin.php?page=lgl-settings') . '">Settings</a>';
    array_unshift($links, $settings_link);
    return $links;
});

// Add plugin meta links
add_filter('plugin_row_meta', function($links, $file) {
    if ($file === LGL_PLUGIN_BASENAME) {
        $links[] = '<a href="https://github.com/askinne2/Little-Green-Light-API" target="_blank">GitHub</a>';
        $links[] = '<a href="https://github.com/askinne2/Little-Green-Light-API/issues" target="_blank">Support</a>';
    }
    return $links;
}, 10, 2);

// Load plugin text domain
add_action('plugins_loaded', function() {
    load_plugin_textdomain('lgl-api', false, dirname(LGL_PLUGIN_BASENAME) . '/languages');
});

// Development/Debug helpers (only in debug mode)
if (defined('WP_DEBUG') && WP_DEBUG) {
    
    /**
     * Debug function to display plugin status
     */
    function lgl_debug_status() {
        if (!current_user_can('manage_options')) {
            return;
        }
        
        echo '<div style="background: #f1f1f1; padding: 10px; margin: 10px 0; border-left: 4px solid #0073aa;">';
        echo '<h4>LGL Plugin Debug Status</h4>';
        echo '<p><strong>Version:</strong> ' . LGL_PLUGIN_VERSION . '</p>';
        echo '<p><strong>PHP Version:</strong> ' . PHP_VERSION . '</p>';
        echo '<p><strong>WordPress Version:</strong> ' . get_bloginfo('version') . '</p>';
        echo '<p><strong>WooCommerce:</strong> ' . (class_exists('WooCommerce') ? 'Active' : 'Not Active') . '</p>';
        echo '<p><strong>Autoloader:</strong> ' . (file_exists(LGL_PLUGIN_DIR . 'vendor/autoload.php') ? 'Available' : 'Missing') . '</p>';
        
        if (function_exists('lgl_plugin') && lgl_plugin()) {
            echo '<p><strong>Modern Architecture:</strong> ✅ Loaded</p>';
        } else {
            echo '<p><strong>Modern Architecture:</strong> ❌ Failed to Load</p>';
        }
        
        echo '</div>';
    }
    
    // Show debug status to admins
    add_action('admin_notices', 'lgl_debug_status');
}

/**
 * Legacy compatibility wrapper
 * 
 * Maintains compatibility with existing code that expects the old structure
 */
if (!class_exists('LGL_API_Compat')) {
    class LGL_API_Compat {
        public static function get_modern_plugin() {
            return lgl_plugin();
        }
        
        public static function get_cache_manager() {
            return \UpstateInternational\LGL\Core\CacheManager::class;
        }
        
        public static function get_utilities() {
            return \UpstateInternational\LGL\Core\Utilities::class;
        }
    }
}

// Backward compatibility constants and functions
if (!defined('LGL_LEGACY_SUPPORT')) {
    define('LGL_LEGACY_SUPPORT', true);
}

// Log successful plugin file load
error_log('LGL Plugin: Modern plugin file loaded successfully');
