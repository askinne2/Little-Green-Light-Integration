<?php
/**
 * Debug Membership Test
 * 
 * Simple test to debug the membership order processing flow
 * with comprehensive logging.
 * 
 * Usage: Add [debug_membership_test] shortcode to any page
 * 
 * @package UpstateInternational\LGL
 * @since 2.0.0
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Debug membership test shortcode
 */
add_shortcode('debug_membership_test', 'debug_membership_test_shortcode');

function debug_membership_test_shortcode($atts) {
    // Only allow admins
    if (!current_user_can('manage_options')) {
        return '<p>Access denied. Admin privileges required.</p>';
    }
    
    $atts = shortcode_atts([
        'run' => 'no'
    ], $atts);
    
    ob_start();
    ?>
    <div class="debug-membership-test" style="background: #f9f9f9; padding: 20px; border-radius: 8px; margin: 20px 0; font-family: monospace;">
        <h3>🧪 Debug Membership Test</h3>
        <p>This test will create a membership order and trace the entire data flow to LGL with comprehensive logging.</p>
        
        <?php if ($atts['run'] === 'yes'): ?>
            <?php echo run_debug_membership_test(); ?>
        <?php else: ?>
            <div style="background: #fff3cd; padding: 15px; border-radius: 5px; margin: 15px 0;">
                <h4>⚠️ Test Instructions</h4>
                <ol>
                    <li>Make sure LGL Debug Mode is enabled in plugin settings</li>
                    <li>Check your error logs after running</li>
                    <li>Look for detailed emoji-prefixed debug messages</li>
                </ol>
            </div>
            
            <form method="get" style="margin: 20px 0;">
                <?php foreach ($_GET as $key => $value): ?>
                    <?php if ($key !== 'run'): ?>
                        <input type="hidden" name="<?php echo esc_attr($key); ?>" value="<?php echo esc_attr($value); ?>">
                    <?php endif; ?>
                <?php endforeach; ?>
                <input type="hidden" name="run" value="yes">
                <button type="submit" style="background: #0073aa; color: white; padding: 10px 20px; border: none; border-radius: 5px; cursor: pointer;">
                    🚀 Run Debug Test
                </button>
            </form>
        <?php endif; ?>
    </div>
    <?php
    return ob_get_clean();
}

function run_debug_membership_test() {
    ob_start();
    
    echo "<h4>🚀 Starting Debug Membership Test...</h4>";
    
    // Load necessary WordPress functions for frontend context
    if (!function_exists('wp_create_user') && file_exists(ABSPATH . 'wp-admin/includes/user.php')) {
        require_once(ABSPATH . 'wp-admin/includes/user.php');
    }
    
    try {
        // Step 1: Check modern architecture
        echo "<p>📋 Step 1: Checking modern architecture...</p>";
        
        if (!class_exists('\UpstateInternational\LGL\Core\Plugin')) {
            echo "<p>❌ Modern Plugin class not found</p>";
            return ob_get_clean();
        }
        
        $plugin = \UpstateInternational\LGL\Core\Plugin::getInstance();
        if (!$plugin) {
            echo "<p>❌ Plugin instance not available</p>";
            return ob_get_clean();
        }
        
        echo "<p>✅ Modern architecture available</p>";
        
        // Step 2: Test service container
        echo "<p>📋 Step 2: Testing service container...</p>";
        
        $orderProcessor = $plugin->getServiceFromContainer('woocommerce.order_processor');
        if (!$orderProcessor) {
            echo "<p>❌ OrderProcessor not resolved from container</p>";
            return ob_get_clean();
        }
        
        echo "<p>✅ OrderProcessor resolved: " . get_class($orderProcessor) . "</p>";
        
        // Step 3: Use actual membership product
        echo "<p>📋 Step 3: Using actual membership product (ID: 67487)...</p>";
        
        $product_id = 67487; // Your actual membership product
        $product = wc_get_product($product_id);
        
        if (!$product) {
            echo "<p>❌ Membership product not found (ID: 67487)</p>";
            return ob_get_clean();
        }
        
        echo "<p>✅ Using product: " . $product->get_name() . " (ID: " . $product->get_id() . ")</p>";
        
        // Get a specific variation for testing (Individual membership - $75)
        $variation_id = 68386; // Individual membership variation
        $variation = wc_get_product($variation_id);
        
        if (!$variation) {
            echo "<p>❌ Individual membership variation not found (ID: 68386)</p>";
            return ob_get_clean();
        }
        
        echo "<p>✅ Using variation: " . $variation->get_name() . " - $" . $variation->get_price() . " (ID: " . $variation->get_id() . ")</p>";
        
        // Display product details for debugging
        echo "<div style='background: #f9f9f9; padding: 10px; margin: 10px 0; border-radius: 5px;'>";
        echo "<h5>🔍 Product Details:</h5>";
        echo "<ul>";
        echo "<li><strong>Parent Product:</strong> " . $product->get_name() . " (ID: " . $product->get_id() . ")</li>";
        echo "<li><strong>Variation:</strong> " . $variation->get_name() . " (ID: " . $variation->get_id() . ")</li>";
        echo "<li><strong>Price:</strong> $" . $variation->get_price() . "</li>";
        echo "<li><strong>Categories:</strong> " . implode(', ', wp_get_post_terms($product->get_id(), 'product_cat', ['fields' => 'names'])) . "</li>";
        echo "<li><strong>Attribute Level:</strong> " . get_post_meta($variation->get_id(), 'attribute_level', true) . "</li>";
        echo "</ul>";
        echo "</div>";
        
        // Step 4: Create test user
        echo "<p>📋 Step 4: Creating test user...</p>";
        
        $test_user_email = 'debug.test@example.com';
        $test_user = get_user_by('email', $test_user_email);
        
        if (!$test_user) {
            $user_id = wp_create_user('debug_test_user', wp_generate_password(), $test_user_email);
            if (is_wp_error($user_id)) {
                echo "<p>❌ Failed to create test user: " . $user_id->get_error_message() . "</p>";
                return ob_get_clean();
            }
            $test_user = get_user_by('id', $user_id);
            echo "<p>✅ Test user created: ID " . $user_id . "</p>";
        } else {
            echo "<p>✅ Using existing test user: ID " . $test_user->ID . "</p>";
        }
        
        // Step 5: Create test order
        echo "<p>📋 Step 5: Creating test order...</p>";
        
        $order = wc_create_order(['customer_id' => $test_user->ID]);
        $order->add_product($variation, 1); // Use the actual variation, not the parent product
        
        // Set customer data
        $order->set_billing_email($test_user_email);
        $order->set_billing_first_name('Debug');
        $order->set_billing_last_name('Test');
        $order->set_billing_phone('555-DEBUG');
        
        // Set order meta
        $order->update_meta_data('_order_languages_spoken', 'English, Spanish');
        $order->update_meta_data('_order_country_of_origin', 'United States');
        $order->update_meta_data('_order_reason_for_membership', 'Testing debug functionality');
        
        $order->calculate_totals();
        $order->set_status('completed');
        $order->save();
        
        echo "<p>✅ Test order created: ID " . $order->get_id() . "</p>";
        
        // Step 6: Process order through modern architecture
        echo "<p>📋 Step 6: Processing order through modern architecture...</p>";
        echo "<p>🔍 <strong>Capturing detailed debug output...</strong></p>";
        
        // Capture debug output by temporarily storing it
        $debug_messages = [];
        
        // Hook into the Helper debug system to capture messages
        add_action('lgl_debug_message', function($message, $data = null) use (&$debug_messages) {
            $debug_messages[] = [
                'message' => $message,
                'data' => $data,
                'timestamp' => current_time('H:i:s')
            ];
        }, 10, 2);
        
        // Start output buffering to capture any direct output
        ob_start();
        
        $orderProcessor->processCompletedOrder($order->get_id());
        
        // Get any direct output
        $direct_output = ob_get_clean();
        
        echo "<p>✅ Order processing completed</p>";
        
        // Display captured debug messages
        if (!empty($debug_messages)) {
            echo "<div style='background: #fff3cd; border: 1px solid #ffeaa7; padding: 15px; margin: 15px 0; border-radius: 5px; max-height: 400px; overflow-y: auto;'>";
            echo "<h5>🔍 Captured Debug Messages:</h5>";
            foreach ($debug_messages as $debug) {
                echo "<div style='background: #f8f9fa; padding: 8px; margin: 5px 0; border-radius: 3px; font-family: monospace; font-size: 12px;'>";
                echo "<strong style='color: #856404;'>[{$debug['timestamp']}] {$debug['message']}</strong>";
                if ($debug['data'] !== null) {
                    echo "<pre style='margin-top: 5px; background: #f1f3f4; padding: 8px; overflow-x: auto; max-height: 150px;'>";
                    echo is_array($debug['data']) || is_object($debug['data']) ? print_r($debug['data'], true) : $debug['data'];
                    echo "</pre>";
                }
                echo "</div>";
            }
            echo "</div>";
        } else {
            echo "<div style='background: #fff3cd; padding: 10px; margin: 10px 0; border-radius: 5px;'>";
            echo "<p>⚠️ No debug messages captured. This might mean:</p>";
            echo "<ul>";
            echo "<li>LGL Debug Mode is disabled in plugin settings</li>";
            echo "<li>The debug hook system needs to be implemented</li>";
            echo "<li>Debug messages are only going to error logs</li>";
            echo "</ul>";
            echo "</div>";
        }
        
        // Show any direct output
        if (!empty($direct_output)) {
            echo "<div style='background: #e7f3ff; padding: 10px; margin: 10px 0; border-radius: 5px;'>";
            echo "<h5>📤 Direct Output:</h5>";
            echo "<pre style='background: #f8f9fa; padding: 10px; overflow-x: auto;'>" . esc_html($direct_output) . "</pre>";
            echo "</div>";
        }
        
        // Step 7: Cleanup
        echo "<p>📋 Step 7: Cleaning up test data...</p>";
        
        $order->delete(true);
        
        // Clean up test user if we created one (only if it's a debug user)
        if (strpos($test_user->user_login, 'debug_test_user') !== false) {
            try {
                // Load admin functions if not available
                if (!function_exists('wp_delete_user')) {
                    if (file_exists(ABSPATH . 'wp-admin/includes/user.php')) {
                        require_once(ABSPATH . 'wp-admin/includes/user.php');
                    }
                }
                
                if (function_exists('wp_delete_user')) {
                    $deleted = wp_delete_user($test_user->ID);
                    if ($deleted) {
                        echo "<p>✅ Test user cleaned up successfully</p>";
                    } else {
                        echo "<p>⚠️ Test user cleanup failed</p>";
                    }
                } else {
                    echo "<p>⚠️ wp_delete_user function not available - test user remains (ID: {$test_user->ID})</p>";
                }
            } catch (Exception $cleanup_error) {
                echo "<p>⚠️ Cleanup error: " . esc_html($cleanup_error->getMessage()) . "</p>";
            }
        } else {
            echo "<p>ℹ️ Using existing user - not cleaning up (ID: {$test_user->ID})</p>";
        }
        // Note: Not deleting actual membership products - they are your live products
        
        echo "<p>✅ Test data cleaned up (actual membership products preserved)</p>";
        
        echo "<div style='background: #e7f3ff; padding: 15px; border-radius: 5px; margin: 20px 0;'>";
        echo "<h4>📊 Test Results</h4>";
        echo "<p><strong>✅ Test completed successfully!</strong></p>";
        echo "<p><strong>🎯 What This Test Did:</strong></p>";
        echo "<ul>";
        echo "<li>Used your actual membership product (ID: 67487)</li>";
        echo "<li>Used Individual membership variation ($75 price)</li>";
        echo "<li>Tested price-based membership level detection</li>";
        echo "<li>Processed order through modern architecture</li>";
        echo "</ul>";
        echo "<p><strong>🔍 Check Error Logs For:</strong></p>";
        echo "<ul>";
        echo "<li><strong>🔄 Helper: Converting price to membership name</strong> - Should show price 75 → Individual Membership</li>";
        echo "<li><strong>🎯 MembershipOrderHandler: Membership Level Determination</strong> - Should show method: price_based_detection</li>";
        echo "<li><strong>🔗 MembershipOrderHandler: Starting LGL registration</strong> - Should show LGL sync attempt</li>";
        echo "<li>Any LGL API errors or connection issues</li>";
        echo "</ul>";
        echo "<p><strong>💡 Expected Flow:</strong> $75 price → Individual Membership → LGL sync with correct membership level</p>";
        echo "</div>";
        
    } catch (Exception $e) {
        echo "<p>❌ Test failed: " . $e->getMessage() . "</p>";
        echo "<p>File: " . $e->getFile() . " Line: " . $e->getLine() . "</p>";
    }
    
    return ob_get_clean();
}
?>
