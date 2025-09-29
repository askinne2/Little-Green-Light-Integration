<?php
/**
 * Test Singleton Fix
 * 
 * Quick test to verify singleton services can be resolved from ServiceContainer
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

echo "🧪 Testing Singleton ServiceContainer Fix...\n\n";

try {
    // Get ServiceContainer
    echo "1. Getting ServiceContainer instance...\n";
    $container = \UpstateInternational\LGL\Core\ServiceContainer::getInstance();
    echo "   ✅ ServiceContainer loaded\n";
    echo "   📊 Registered services: " . count($container->getServiceIds()) . "\n\n";
    
    // Test singleton service resolution
    echo "2. Testing singleton service resolution...\n";
    
    $singletonServices = [
        'lgl.wp_users' => 'WpUsers',
        'lgl.connection' => 'Connection', 
        'lgl.helper' => 'Helper',
        'lgl.constituents' => 'Constituents',
        'test.requests' => 'TestRequests'
    ];
    
    foreach ($singletonServices as $serviceId => $serviceName) {
        try {
            echo "   Testing {$serviceName}...\n";
            $service = $container->get($serviceId);
            echo "   ✅ {$serviceName} resolved successfully\n";
            echo "   📋 Class: " . get_class($service) . "\n";
        } catch (Exception $e) {
            echo "   ❌ {$serviceName} failed: " . $e->getMessage() . "\n";
        }
    }
    
    echo "\n3. Testing non-singleton services...\n";
    
    $regularServices = [
        'cache.manager' => 'CacheManager',
        'utilities' => 'Utilities'
    ];
    
    foreach ($regularServices as $serviceId => $serviceName) {
        try {
            echo "   Testing {$serviceName}...\n";
            $service = $container->get($serviceId);
            echo "   ✅ {$serviceName} resolved successfully\n";
            echo "   📋 Class: " . get_class($service) . "\n";
        } catch (Exception $e) {
            echo "   ❌ {$serviceName} failed: " . $e->getMessage() . "\n";
        }
    }
    
    echo "\n🎉 Singleton fix test completed!\n";
    echo "🚀 The WordPress fatal error should now be resolved.\n";
    
} catch (Exception $e) {
    echo "❌ Critical Error: " . $e->getMessage() . "\n";
    echo "📍 File: " . $e->getFile() . " (Line " . $e->getLine() . ")\n";
}

