<?php
/**
 * LGL API Connection Test
 * 
 * Tests the LGL API connection and credentials
 * 
 * Usage: Add [test_lgl_connection] shortcode to any page
 * 
 * @package UpstateInternational\LGL
 * @since 2.0.0
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Test LGL connection shortcode
 */
add_shortcode('test_lgl_connection', 'test_lgl_connection_shortcode');

function test_lgl_connection_shortcode($atts) {
    // Only allow admins
    if (!current_user_can('manage_options')) {
        return '<p>Access denied. Admin privileges required.</p>';
    }
    
    ob_start();
    ?>
    <div class="lgl-connection-test" style="background: #f9f9f9; padding: 20px; border-radius: 8px; margin: 20px 0; font-family: monospace;">
        <h3>🔌 LGL API Connection Test</h3>
        <p>This test verifies your Little Green Light API connection and credentials.</p>
        
        <?php
        try {
            // Test 1: Check if modern Connection class is available
            echo "<h4>📋 Test 1: Connection Class Availability</h4>";
            
            if (!class_exists('\UpstateInternational\LGL\LGL\Connection')) {
                echo "<p>❌ Connection class not found</p>";
                return ob_get_clean();
            }
            
            $connection = \UpstateInternational\LGL\LGL\Connection::getInstance();
            echo "<p>✅ Connection class available</p>";
            
            // Test 2: Check API Settings
            echo "<h4>📋 Test 2: API Settings</h4>";
            
            if (!class_exists('\UpstateInternational\LGL\LGL\ApiSettings')) {
                echo "<p>❌ ApiSettings class not found</p>";
                return ob_get_clean();
            }
            
            $apiSettings = \UpstateInternational\LGL\LGL\ApiSettings::getInstance();
            $api_url = $apiSettings->getApiUrl();
            $api_key = $apiSettings->getApiKey();
            
            echo "<div style='background: white; padding: 15px; border-radius: 5px; margin: 10px 0;'>";
            echo "<p><strong>API URL:</strong> " . ($api_url ? '✅ ' . esc_html($api_url) : '❌ Not configured') . "</p>";
            echo "<p><strong>API Key:</strong> " . ($api_key ? '✅ Configured (' . strlen($api_key) . ' characters)' : '❌ Not configured') . "</p>";
            echo "<p><strong>Debug Mode:</strong> " . ($apiSettings->isDebugMode() ? '✅ Enabled' : '❌ Disabled') . "</p>";
            echo "</div>";
            
            if (!$api_url || !$api_key) {
                echo "<div style='background: #fff3cd; padding: 15px; border-radius: 5px; margin: 10px 0;'>";
                echo "<h5>⚠️ Configuration Required</h5>";
                echo "<p>Please configure your LGL API settings:</p>";
                echo "<ol>";
                echo "<li>Go to <strong>Settings → LGL API</strong></li>";
                echo "<li>Enter your API URL and API Key</li>";
                echo "<li>Save settings</li>";
                echo "<li>Run this test again</li>";
                echo "</ol>";
                echo "</div>";
                return ob_get_clean();
            }
            
            // Test 3: Test API Connection
            echo "<h4>📋 Test 3: API Connection Test</h4>";
            echo "<p>🔄 Testing connection to LGL API...</p>";
            
            $connection_test = $connection->testConnection();
            
            if ($connection_test['success']) {
                echo "<p>✅ <strong>SUCCESS:</strong> " . esc_html($connection_test['message']) . "</p>";
                echo "<p><strong>API Version:</strong> " . esc_html($connection_test['api_version']) . "</p>";
            } else {
                echo "<p>❌ <strong>FAILED:</strong> " . esc_html($connection_test['message']) . "</p>";
                echo "<div style='background: #f8d7da; padding: 15px; border-radius: 5px; margin: 10px 0;'>";
                echo "<h5>🔧 Troubleshooting Steps:</h5>";
                echo "<ul>";
                echo "<li>Verify your API URL is correct</li>";
                echo "<li>Check your API Key is valid and active</li>";
                echo "<li>Ensure your LGL account has API access enabled</li>";
                echo "<li>Check if there are any firewall restrictions</li>";
                echo "</ul>";
                echo "</div>";
                return ob_get_clean();
            }
            
            // Test 4: Test searchByName method
            echo "<h4>📋 Test 4: Search Function Test</h4>";
            echo "<p>🔄 Testing searchByName method...</p>";
            
            $search_result = $connection->searchByName('Test User', 'test@example.com');
            
            if ($search_result === false) {
                echo "<p>✅ searchByName method works (no match found for test data - expected)</p>";
            } else {
                echo "<p>✅ searchByName method works (found LGL ID: " . esc_html($search_result) . ")</p>";
            }
            
            // Test 5: Connection Statistics
            echo "<h4>📋 Test 5: Connection Statistics</h4>";
            
            $stats = $connection->getConnectionStats();
            
            echo "<div style='background: white; padding: 15px; border-radius: 5px; margin: 10px 0;'>";
            echo "<ul>";
            foreach ($stats as $key => $value) {
                $display_value = is_bool($value) ? ($value ? 'Yes' : 'No') : $value;
                echo "<li><strong>" . ucwords(str_replace('_', ' ', $key)) . ":</strong> " . esc_html($display_value) . "</li>";
            }
            echo "</ul>";
            echo "</div>";
            
            // Success summary
            echo "<div style='background: #d4edda; padding: 15px; border-radius: 5px; margin: 20px 0;'>";
            echo "<h4>🎉 All Tests Passed!</h4>";
            echo "<p><strong>✅ Your LGL API connection is working correctly!</strong></p>";
            echo "<p>The membership registration should now work properly.</p>";
            echo "</div>";
            
        } catch (Exception $e) {
            echo "<p>❌ Test failed: " . esc_html($e->getMessage()) . "</p>";
            echo "<p>File: " . esc_html($e->getFile()) . " Line: " . $e->getLine() . "</p>";
        }
        ?>
        
    </div>
    <?php
    return ob_get_clean();
}
?>
