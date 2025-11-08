<?php
/**
 * Little Green Light API Integration Plugin
 * 
 * Modern WordPress plugin for integrating with Little Green Light CRM.
 * Provides membership management, event registration, and payment processing.
 * 
 * @link              https://github.com/askinne2/Little-Green-Light-Integration
 * @since             2.0.0
 * @package           UpstateInternational\LGL
*
* @wordpress-plugin
 * Plugin Name:       Little Green Light API Integration
 * Plugin URI:        https://github.com/askinne2/Little-Green-Light-Integration
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
 * GitHub Plugin URI: https://github.com/askinne2/Little-Green-Light-Integration
*/

if (!defined('ABSPATH')) {
	exit; // Exit if accessed directly
}

// Plugin constants
define('LGL_PLUGIN_VERSION', '2.0.0');
define('LGL_PLUGIN_FILE', __FILE__);
define('LGL_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('LGL_PLUGIN_URL', plugin_dir_url(__FILE__));
define('LGL_PLUGIN_BASENAME', plugin_basename(__FILE__));

/**
 * Initialize modern architecture
 */
function lgl_init_modern_architecture() {
    // Load Composer autoloader
    $autoloader = LGL_PLUGIN_DIR . 'vendor/autoload.php';
    
    if (!file_exists($autoloader)) {
        add_action('admin_notices', function() {
            if (current_user_can('manage_options')) {
                echo '<div class="notice notice-warning"><p><strong>LGL Plugin:</strong> Modern features require Composer autoloader. Run <code>composer install</code> or <code>./refresh-autoloader.sh</code> in the plugin directory for enhanced performance and features.</p></div>';
            }
        });
				return;
			}
    
    require_once $autoloader;
    
    // Load admin helper functions
    $admin_functions = LGL_PLUGIN_DIR . 'src/Admin/functions.php';
    if (file_exists($admin_functions)) {
        require_once $admin_functions;
    }
    
    // Initialize modern plugin architecture
    add_action('plugins_loaded', function() {
        try {
            $plugin = \UpstateInternational\LGL\Core\Plugin::getInstance(LGL_PLUGIN_FILE);
            error_log('LGL Plugin: Modern architecture initialized successfully');
        } catch (Exception $e) {
            error_log('LGL Plugin Modern Architecture Error: ' . $e->getMessage());
        }
    }, 5); // Load early to ensure modern classes are available
}

/**
 * Get modern plugin instance (utility function)
 */
function lgl_plugin() {
    static $instance = null;
    
    if ($instance === null && class_exists('\UpstateInternational\LGL\Core\Plugin')) {
        $instance = \UpstateInternational\LGL\Core\Plugin::getInstance(LGL_PLUGIN_FILE);
    }
    
    return $instance;
}

// Initialize modern architecture
lgl_init_modern_architecture();

// Modern activation/deactivation hooks
register_activation_hook(__FILE__, function() {
    // Modern activation
    if (function_exists('lgl_plugin') && lgl_plugin()) {
        lgl_plugin()->onActivation();
    }
    
    error_log('LGL Plugin: Activation completed');
});

register_deactivation_hook(__FILE__, function() {
    // Modern deactivation
    if (function_exists('lgl_plugin') && lgl_plugin()) {
        lgl_plugin()->onDeactivation();
    }
    
    error_log('LGL Plugin: Deactivation completed');
});

// Legacy shortcode support (temporary compatibility)
add_action('template_redirect', function() {
    // Legacy LGL shortcode
    if (class_exists('LGL_API')) {
        $lgl = LGL_API::get_instance();
        $lgl->shortcode_init();
    }
    
    // Legacy membership shortcodes
    if (class_exists('LGL_WP_Users')) {
	$lgl_users = LGL_WP_Users::get_instance();
	$lgl_users->shortcode_init();
}
	
    if (class_exists('UI_Memberships')) {
	$ui_members = UI_Memberships::get_instance();
	$ui_members->shortcode_init();
}
}, 10);

// Development helpers (only when LGL debug mode is enabled)
// Check if our plugin's debug mode is enabled (independent of WP_DEBUG)
$lgl_debug_enabled = false;
if (class_exists('UpstateInternational\\LGL\\LGL\\ApiSettings')) {
    try {
        $api_settings = \UpstateInternational\LGL\LGL\ApiSettings::getInstance();
        $lgl_debug_enabled = $api_settings->isDebugMode();
    } catch (Exception $e) {
        // Fallback to WP_DEBUG if our settings aren't available yet
        $lgl_debug_enabled = defined('WP_DEBUG') && WP_DEBUG;
    }
}

if ($lgl_debug_enabled) {
    // Load core testing utilities
    require_once LGL_PLUGIN_DIR . 'test/test-shortcode.php';
    require_once LGL_PLUGIN_DIR . 'test/debug-membership-test.php';
    require_once LGL_PLUGIN_DIR . 'test/test-lgl-connection.php';
    require_once LGL_PLUGIN_DIR . 'test/test-order-processing-flow.php';
    require_once LGL_PLUGIN_DIR . 'test/test-phase5-memberships.php';
    
    add_action('admin_notices', function() {
        if (!function_exists('current_user_can') || !current_user_can('manage_options')) {
            return;
        }
        
        echo '<div style="background: #f1f1f1; padding: 10px; margin: 10px 0; border-left: 4px solid #0073aa;">';
        echo '<h4>🚀 LGL Plugin Status (v' . LGL_PLUGIN_VERSION . ') - MODERN ARCHITECTURE</h4>';
        echo '<p><strong>Modern Architecture:</strong> ' . (class_exists('\UpstateInternational\LGL\Core\Plugin') ? '✅ Active' : '❌ Not Available') . '</p>';
        echo '<p><strong>Autoloader:</strong> ' . (file_exists(LGL_PLUGIN_DIR . 'vendor/autoload.php') ? '✅ Available' : '❌ Missing') . '</p>';
        echo '<p><strong>Performance Mode:</strong> 🚀 Pure modern architecture, legacy compatibility maintained</p>';
        echo '<p><strong>Admin Interface:</strong> <a href="' . admin_url('admin.php?page=lgl-integration') . '">🔗 LGL Integration Dashboard</a></p>';
        echo '<p><strong>Testing Suite:</strong> <a href="' . admin_url('admin.php?page=lgl-testing') . '">🧪 Unified Testing Interface</a></p>';
        echo '<p><strong>Testing Shortcodes (LGL Debug Mode):</strong> <code>[lgl_test_flow]</code> | <code>[debug_membership_test]</code> | <code>[test_lgl_connection]</code></p>';
        echo '<p><strong>Professional Testing:</strong> Available in <strong>LGL Integration > Testing Suite</strong> in WordPress Admin</p>';
        echo '</div>';
    });
}
