<?php
/**
 * Test Dependency Resolution Fix
 */

// Plugin constants
define('LGL_PLUGIN_VERSION', '2.0.0');
define('LGL_PLUGIN_FILE', __FILE__);
define('LGL_PLUGIN_DIR', __DIR__ . '/');

// Mock WordPress functions
if (!function_exists('add_action')) {
    function add_action($hook, $callback, $priority = 10, $args = 1) { return true; }
}

// Load Composer autoloader
require_once __DIR__ . '/vendor/autoload.php';

echo "🧪 Testing Dependency Resolution Fix...\n\n";

try {
    // Get ServiceContainer
    $container = \UpstateInternational\LGL\Core\ServiceContainer::getInstance();
    echo "1. ServiceContainer loaded with " . count($container->getServiceIds()) . " services\n\n";
    
    // Test singleton services first
    echo "2. Testing singleton services...\n";
    $helper = $container->get('lgl.helper');
    echo "   ✅ lgl.helper resolved: " . get_class($helper) . "\n";
    
    $wpUsers = $container->get('lgl.wp_users');
    echo "   ✅ lgl.wp_users resolved: " . get_class($wpUsers) . "\n\n";
    
    // Test JetFormBuilder action services
    echo "3. Testing JetFormBuilder action services...\n";
    $userRegistrationAction = $container->get('jetformbuilder.user_registration_action');
    echo "   ✅ user_registration_action resolved: " . get_class($userRegistrationAction) . "\n";
    
    $classRegistrationAction = $container->get('jetformbuilder.class_registration_action');
    echo "   ✅ class_registration_action resolved: " . get_class($classRegistrationAction) . "\n\n";
    
    // Test WooCommerce handler services  
    echo "4. Testing WooCommerce handler services...\n";
    $membershipHandler = $container->get('woocommerce.membership_handler');
    echo "   ✅ membership_handler resolved: " . get_class($membershipHandler) . "\n";
    
    $classHandler = $container->get('woocommerce.class_handler');
    echo "   ✅ class_handler resolved: " . get_class($classHandler) . "\n";
    
    $eventHandler = $container->get('woocommerce.event_handler');
    echo "   ✅ event_handler resolved: " . get_class($eventHandler) . "\n\n";
    
    // Test main WooCommerce services
    echo "5. Testing main WooCommerce services...\n";
    $orderProcessor = $container->get('woocommerce.order_processor');
    echo "   ✅ order_processor resolved: " . get_class($orderProcessor) . "\n";
    
    $subscriptionHandler = $container->get('woocommerce.subscription_handler');
    echo "   ✅ subscription_handler resolved: " . get_class($subscriptionHandler) . "\n\n";
    
    // Test email services
    echo "6. Testing email services...\n";
    $orderCustomizer = $container->get('email.order_customizer');
    echo "   ✅ order_customizer resolved: " . get_class($orderCustomizer) . "\n\n";
    
    echo "🎉 ALL DEPENDENCY RESOLUTION TESTS PASSED!\n\n";
    echo "🚀 The WordPress fatal error should now be resolved.\n";
    
} catch (Exception $e) {
    echo "❌ Error: " . $e->getMessage() . "\n";
    echo "📍 File: " . $e->getFile() . " (Line " . $e->getLine() . ")\n";
    exit(1);
}

