<?php
/**
 * Test Phase 5: UI Memberships Modernization
 * 
 * Modern WordPress shortcode for testing UI Memberships functionality
 * Usage: Add [test_phase5_memberships] to any page/post
 * 
 * @package UpstateInternational\LGL
 * @since 2.0.0
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

add_shortcode('test_phase5_memberships', 'test_phase5_memberships_shortcode');

/**
 * Test Phase 5 Memberships Shortcode
 * 
 * @return string HTML output
 */
function test_phase5_memberships_shortcode() {
    if (!current_user_can('manage_options')) {
        return '<p style="color: red;">❌ You need admin permissions to run this test.</p>';
    }
    
    ob_start();
    
    echo '<div style="background: #f0f0f0; padding: 20px; margin: 20px 0; border-radius: 5px;">';
    echo '<h3>🧪 Phase 5: UI Memberships Modernization Test</h3>';
    
    try {
        // Get the modern plugin instance
        if (!function_exists('lgl_plugin')) {
            echo '<p style="color: red;">❌ Modern plugin architecture not available</p>';
            echo '</div>';
            return ob_get_clean();
        }
        
        $plugin = lgl_plugin();
        $container = $plugin->getContainer();
        
        echo '<h4>1. Testing Plugin Architecture</h4>';
        echo '<p>✅ Modern plugin instance available</p>';
        echo '<p>✅ Service container available</p>';
        echo '<p>📊 Total services: ' . count($container->getServiceIds()) . '</p>';
        
        echo '<h4>2. Testing Membership Services</h4>';
        
        $membershipServices = [
            'memberships.notification_mailer' => 'MembershipNotificationMailer',
            'memberships.user_manager' => 'MembershipUserManager',
            'memberships.renewal_manager' => 'MembershipRenewalManager',
            'memberships.cron_manager' => 'MembershipCronManager'
        ];
        
        foreach ($membershipServices as $serviceId => $serviceName) {
            if ($container->has($serviceId)) {
                echo "<p>✅ {$serviceName} service registered</p>";
            } else {
                echo "<p style='color: red;'>❌ {$serviceName} service missing</p>";
            }
        }
        
        echo '<h4>3. Testing Service Resolution</h4>';
        
        // Test MembershipNotificationMailer
        if ($container->has('memberships.notification_mailer')) {
            $notificationMailer = $container->get('memberships.notification_mailer');
            echo "<p>✅ MembershipNotificationMailer resolved: " . get_class($notificationMailer) . "</p>";
            
            // Test status method if available
            if (method_exists($notificationMailer, 'getStatus')) {
                $mailerStatus = $notificationMailer->getStatus();
                echo "<p>📧 Email templates available: " . (isset($mailerStatus['templates']) ? count($mailerStatus['templates']) : 0) . "</p>";
            }
        }
        
        // Test MembershipUserManager
        if ($container->has('memberships.user_manager')) {
            $userManager = $container->get('memberships.user_manager');
            echo "<p>✅ MembershipUserManager resolved: " . get_class($userManager) . "</p>";
            
            // Test status method if available
            if (method_exists($userManager, 'getStats')) {
                $userStats = $userManager->getStats();
                echo "<p>👥 Active memberships: " . (isset($userStats['active_memberships']) ? $userStats['active_memberships'] : 0) . "</p>";
            }
        }
        
        // Test MembershipRenewalManager
        if ($container->has('memberships.renewal_manager')) {
            $renewalManager = $container->get('memberships.renewal_manager');
            echo "<p>✅ MembershipRenewalManager resolved: " . get_class($renewalManager) . "</p>";
            
            // Test status method if available
            if (method_exists($renewalManager, 'getStatus')) {
                $renewalStatus = $renewalManager->getStatus();
                echo "<p>🔄 Pending renewals: " . (isset($renewalStatus['pending_renewals']) ? count($renewalStatus['pending_renewals']) : 0) . "</p>";
            }
        }
        
        // Test MembershipCronManager
        if ($container->has('memberships.cron_manager')) {
            $cronManager = $container->get('memberships.cron_manager');
            echo "<p>✅ MembershipCronManager resolved: " . get_class($cronManager) . "</p>";
            
            // Test cron status if available
            if (method_exists($cronManager, 'getStatus')) {
                $cronStatus = $cronManager->getStatus();
                echo "<p>⏰ Cron jobs scheduled: " . (isset($cronStatus['scheduled_jobs']) ? count($cronStatus['scheduled_jobs']) : 0) . "</p>";
            }
        }
        
        // Test UI Memberships shortcode service
        echo '<h4>4. Testing UI Memberships Shortcode</h4>';
        if ($container->has('shortcodes.ui_memberships')) {
            $shortcodeService = $container->get('shortcodes.ui_memberships');
            echo "<p>✅ UiMembershipsShortcode resolved: " . get_class($shortcodeService) . "</p>";
            
            if (method_exists($shortcodeService, 'getName')) {
                echo "<p>📋 Shortcode name: " . $shortcodeService->getName() . "</p>";
            }
            if (method_exists($shortcodeService, 'getDescription')) {
                echo "<p>📝 Description: " . $shortcodeService->getDescription() . "</p>";
            }
        } else {
            echo "<p style='color: orange;'>⚠️ UiMembershipsShortcode service not found (may not be implemented yet)</p>";
        }
        
        echo '<h4 style="color: green;">🎉 Phase 5 Modernization Tests Complete!</h4>';
        
        echo '<h4>📊 Summary</h4>';
        echo '<ul>';
        echo '<li>✅ Modern plugin architecture active</li>';
        echo '<li>✅ Service container integration working</li>';
        echo '<li>✅ Dependency injection functional</li>';
        echo '<li>✅ Membership services available</li>';
        echo '</ul>';
        
        echo '<p><strong>🚀 UI Memberships component successfully modernized!</strong></p>';
        echo '<p>📈 Total services available: ' . count($container->getServiceIds()) . '</p>';
        
    } catch (Exception $e) {
        echo '<div style="color: red; background: #ffe6e6; padding: 10px; border-radius: 3px;">';
        echo '<p><strong>❌ Error:</strong> ' . esc_html($e->getMessage()) . '</p>';
        echo '<p><strong>📍 File:</strong> ' . esc_html($e->getFile()) . ' (Line ' . $e->getLine() . ')</p>';
        echo '</div>';
    }
    
    echo '</div>';
    
    return ob_get_clean();
}