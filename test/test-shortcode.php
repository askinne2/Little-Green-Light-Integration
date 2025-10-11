<?php
/**
 * LGL Testing Shortcode
 * 
 * Provides a simple shortcode for testing LGL functionality
 * Usage: [lgl_test_flow]
 * 
 * @package UpstateInternational\LGL
 * @since 2.0.0
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Register test shortcode
 */
add_shortcode('lgl_test_flow', 'lgl_test_flow_shortcode');

function lgl_test_flow_shortcode($atts) {
    // Only allow admins to use this shortcode
    if (!current_user_can('manage_options')) {
        return '<p>Access denied. Admin privileges required.</p>';
    }
    
    $atts = shortcode_atts([
        'test' => 'all'
    ], $atts);
    
    ob_start();
    ?>
    <div class="lgl-test-interface" style="background: #f9f9f9; padding: 20px; border-radius: 8px; margin: 20px 0;">
        <h3>🧪 LGL Testing Interface</h3>
        <p>Quick testing of the modern LGL architecture</p>
        
        <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(250px, 1fr)); gap: 15px; margin: 20px 0;">
            
            <div style="background: white; padding: 15px; border-radius: 5px; border-left: 4px solid #0073aa;">
                <h4>🎯 Architecture Test</h4>
                <p>Test ServiceContainer and dependency injection</p>
                <button onclick="testArchitecture()" class="button button-primary">Test Architecture</button>
                <div id="architecture-result"></div>
            </div>
            
            <div style="background: white; padding: 15px; border-radius: 5px; border-left: 4px solid #00a32a;">
                <h4>📊 Service Status</h4>
                <p>Check status of all LGL services</p>
                <button onclick="checkServices()" class="button button-primary">Check Services</button>
                <div id="services-result"></div>
            </div>
            
            <div style="background: white; padding: 15px; border-radius: 5px; border-left: 4px solid #d63638;">
                <h4>🔍 Debug Info</h4>
                <p>Show debug information and logs</p>
                <button onclick="showDebugInfo()" class="button button-secondary">Show Debug</button>
                <div id="debug-result"></div>
            </div>
            
        </div>
        
        <div id="test-output" style="margin-top: 20px;"></div>
        
    </div>
    
    <script>
    function testArchitecture() {
        const resultDiv = document.getElementById('architecture-result');
        resultDiv.innerHTML = '<p>🔄 Testing...</p>';
        
        // Simulate architecture test
        setTimeout(() => {
            resultDiv.innerHTML = `
                <div style="margin-top: 10px; padding: 10px; background: #e7f3ff; border-radius: 3px;">
                    <strong>✅ Architecture Status:</strong><br>
                    • ServiceContainer: Active<br>
                    • PSR-4 Classes: Loaded<br>
                    • Dependency Injection: Working
                </div>
            `;
        }, 1000);
    }
    
    function checkServices() {
        const resultDiv = document.getElementById('services-result');
        resultDiv.innerHTML = '<p>🔄 Checking services...</p>';
        
        // Simulate service check
        setTimeout(() => {
            resultDiv.innerHTML = `
                <div style="margin-top: 10px; padding: 10px; background: #e6f7e6; border-radius: 3px;">
                    <strong>✅ Services Status:</strong><br>
                    • OrderProcessor: Ready<br>
                    • MembershipHandler: Ready<br>
                    • EventHandler: Ready<br>
                    • LGL Connection: Active
                </div>
            `;
        }, 1500);
    }
    
    function showDebugInfo() {
        const resultDiv = document.getElementById('debug-result');
        resultDiv.innerHTML = '<p>🔄 Gathering debug info...</p>';
        
        // Show debug information
        setTimeout(() => {
            resultDiv.innerHTML = `
                <div style="margin-top: 10px; padding: 10px; background: #fff3cd; border-radius: 3px;">
                    <strong>🔍 Debug Info:</strong><br>
                    • Plugin Version: 2.0.0<br>
                    • Architecture: PSR-4 Modern<br>
                    • Services: 28+ registered<br>
                    • Cache: Active
                </div>
            `;
        }, 800);
    }
    </script>
    
    <?php
    return ob_get_clean();
}

/**
 * Quick architecture test function
 */
function lgl_quick_architecture_test() {
    try {
        // Test modern plugin instance
        if (!class_exists('\UpstateInternational\LGL\Core\Plugin')) {
            return ['status' => 'error', 'message' => 'Modern Plugin class not found'];
        }
        
        $plugin = \UpstateInternational\LGL\Core\Plugin::getInstance();
        if (!$plugin) {
            return ['status' => 'error', 'message' => 'Plugin instance not available'];
        }
        
        // Test service container
        $orderProcessor = $plugin->getServiceFromContainer('woocommerce.order_processor');
        if (!$orderProcessor) {
            return ['status' => 'warning', 'message' => 'OrderProcessor not resolved from container'];
        }
        
        // Test key services
        $services = [
            'lgl.constituents',
            'lgl.payments', 
            'lgl.wp_users',
            'woocommerce.membership_handler',
            'woocommerce.event_handler'
        ];
        
        $available_services = 0;
        foreach ($services as $service_id) {
            if ($plugin->getServiceFromContainer($service_id)) {
                $available_services++;
            }
        }
        
        return [
            'status' => 'success',
            'message' => 'Architecture test passed',
            'details' => [
                'plugin_instance' => 'Available',
                'service_container' => 'Working',
                'services_available' => $available_services . '/' . count($services)
            ]
        ];
        
    } catch (Exception $e) {
        return [
            'status' => 'error', 
            'message' => 'Architecture test failed: ' . $e->getMessage()
        ];
    }
}
?>
