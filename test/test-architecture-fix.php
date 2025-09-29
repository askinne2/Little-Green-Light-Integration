<?php
/**
 * Test Modern Architecture Fix
 * 
 * Quick test to verify the ServiceContainer and Plugin classes load correctly
 */

// Simulate WordPress constants
if (!defined('ABSPATH')) {
    define('ABSPATH', '/Users/andrewskinner/Local Sites/upstate-international/app/public/');
}

// Plugin constants
define('LGL_PLUGIN_VERSION', '2.0.0');
define('LGL_PLUGIN_FILE', __FILE__);
define('LGL_PLUGIN_DIR', __DIR__ . '/');
define('LGL_PLUGIN_URL', 'http://upstate-international.local/wp-content/plugins/Integrate-LGL/');
define('LGL_PLUGIN_BASENAME', 'Integrate-LGL/lgl-api.php');

// Load Composer autoloader
require_once __DIR__ . '/vendor/autoload.php';

echo "🧪 Testing Modern Architecture Fix...\n\n";

try {
    // Test 1: ServiceContainer
    echo "1. Testing ServiceContainer...\n";
    $container = \UpstateInternational\LGL\Core\ServiceContainer::getInstance();
    echo "   ✅ ServiceContainer loaded successfully\n";
    echo "   📊 Container has " . count($container->getServiceIds()) . " registered services\n\n";
    
    // Test 2: HookManager
    echo "2. Testing HookManager...\n";
    $hookManager = new \UpstateInternational\LGL\Core\HookManager($container);
    echo "   ✅ HookManager loaded successfully\n\n";
    
    // Test 3: Plugin class (without WordPress functions)
    echo "3. Testing Plugin class instantiation...\n";
    
    // Mock WordPress functions that Plugin class might need
    if (!function_exists('plugin_dir_path')) {
        function plugin_dir_path($file) { return dirname($file) . '/'; }
    }
    if (!function_exists('plugin_dir_url')) {
        function plugin_dir_url($file) { return 'http://example.com/'; }
    }
    if (!function_exists('add_action')) {
        function add_action($hook, $callback, $priority = 10, $args = 1) { return true; }
    }
    if (!function_exists('register_activation_hook')) {
        function register_activation_hook($file, $callback) { return true; }
    }
    if (!function_exists('register_deactivation_hook')) {
        function register_deactivation_hook($file, $callback) { return true; }
    }
    
    $plugin = \UpstateInternational\LGL\Core\Plugin::getInstance(__FILE__);
    echo "   ✅ Plugin class loaded successfully\n";
    echo "   📋 Plugin version: " . $plugin->getVersion() . "\n\n";
    
    echo "🎉 All tests passed! The architecture fix is working.\n";
    echo "🚀 The fatal error should now be resolved.\n\n";
    
    // Show some stats
    echo "📊 Architecture Statistics:\n";
    echo "   - Modern classes available: " . count(get_declared_classes()) . " total classes in memory\n";
    echo "   - ServiceContainer services: " . count($container->getServiceIds()) . "\n";
    echo "   - Plugin status: Initialized successfully\n";
    
} catch (Exception $e) {
    echo "❌ Error: " . $e->getMessage() . "\n";
    echo "📍 File: " . $e->getFile() . " (Line " . $e->getLine() . ")\n";
    echo "🔍 This error needs to be fixed before the plugin will work.\n";
}
