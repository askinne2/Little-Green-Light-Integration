<?php
/**
 * Email Blocker Diagnostic Tool
 * 
 * Access via: https://upstateint.tempurl.host/wp-admin/?lgl_email_diagnostic=1
 * 
 * This file is automatically loaded by the plugin when the diagnostic parameter is present.
 * No need to manually include this file - it's integrated into Plugin.php
 * 
 * REMOVE THIS FILE AFTER TROUBLESHOOTING!
 */

// Security check (should already be done by the hook, but double-check)
if (!current_user_can('manage_options')) {
    wp_die('Unauthorized access');
}

// Prevent output buffering issues
if (ob_get_level()) {
    ob_end_clean();
}

// Set proper headers
header('Content-Type: text/html; charset=utf-8');
?>
<!DOCTYPE html>
<html>
<head>
    <title>LGL Email Blocker Diagnostic</title>
    <style>
        body { font-family: Arial, sans-serif; margin: 20px; background: #f5f5f5; }
        .container { max-width: 1200px; margin: 0 auto; background: white; padding: 20px; border-radius: 5px; box-shadow: 0 2px 5px rgba(0,0,0,0.1); }
        h1 { color: #333; border-bottom: 2px solid #0073aa; padding-bottom: 10px; }
        .section { margin: 20px 0; padding: 15px; background: #f9f9f9; border-left: 4px solid #0073aa; }
        .success { border-left-color: #46b450; background: #f0f9f0; }
        .error { border-left-color: #dc3232; background: #fef7f7; }
        .warning { border-left-color: #ffb900; background: #fffbf0; }
        .info { border-left-color: #00a0d2; background: #f0f8ff; }
        table { width: 100%; border-collapse: collapse; margin: 10px 0; }
        th, td { padding: 10px; text-align: left; border-bottom: 1px solid #ddd; }
        th { background: #0073aa; color: white; }
        code { background: #f4f4f4; padding: 2px 6px; border-radius: 3px; font-family: monospace; }
        .test-button { display: inline-block; padding: 10px 20px; background: #0073aa; color: white; text-decoration: none; border-radius: 3px; margin: 10px 0; }
        .test-button:hover { background: #005a87; }
    </style>
</head>
<body>
    <div class="container">
        <h1>🔍 LGL Email Blocker Diagnostic Tool</h1>
        <p><strong>Generated:</strong> <?php echo date('Y-m-d H:i:s'); ?></p>
        
        <?php
        // Check if plugin is active
        if (!defined('LGL_PLUGIN_FILE')) {
            echo '<div class="section error">';
            echo '<h2>❌ Plugin Not Active</h2>';
            echo '<p>The Integrate-LGL plugin does not appear to be active.</p>';
            echo '</div>';
            exit;
        }
        
        // Check if EmailBlocker class exists
        if (!class_exists('\UpstateInternational\LGL\Email\EmailBlocker')) {
            echo '<div class="section error">';
            echo '<h2>❌ EmailBlocker Class Not Found</h2>';
            echo '<p>The EmailBlocker class is not loaded. Check that the plugin files are present.</p>';
            echo '</div>';
            exit;
        }
        
        try {
            // Get plugin instance
            $plugin = \UpstateInternational\LGL\Core\Plugin::getInstance(LGL_PLUGIN_FILE);
            $container = $plugin->getContainer();
            $emailBlocker = $container->get('email.blocker');
            
            // Get status
            $status = $emailBlocker->getBlockingStatus();
            $level = $emailBlocker->getBlockingLevel();
            $isEnabled = $emailBlocker->isBlockingEnabled();
            $isForce = $emailBlocker->isForceBlocking();
            $isDev = $emailBlocker->isDevelopmentEnvironment();
            
            // Environment info
            $host = $_SERVER['HTTP_HOST'] ?? 'Unknown';
            $site_url = get_site_url();
            $server_addr = $_SERVER['SERVER_ADDR'] ?? 'Unknown';
            
            // Check if wp_mail filter is registered
            global $wp_filter;
            $has_filter = false;
            $filter_priority = null;
            if (isset($wp_filter['wp_mail'])) {
                foreach ($wp_filter['wp_mail']->callbacks as $priority => $callbacks) {
                    foreach ($callbacks as $callback) {
                        if (is_array($callback['function']) && 
                            is_object($callback['function'][0]) && 
                            get_class($callback['function'][0]) === 'UpstateInternational\LGL\Email\EmailBlocker') {
                            $has_filter = true;
                            $filter_priority = $priority;
                            break 2;
                        }
                    }
                }
            }
            
            // Settings check
            $settings = get_option('lgl_integration_settings', []);
            $force_blocking_setting = $settings['force_email_blocking'] ?? false;
            $blocking_level_setting = $settings['email_blocking_level'] ?? 'not_set';
            
            // Blocked emails count
            $blocked_count = $emailBlocker->getStats()['total_blocked'] ?? 0;
            
            ?>
            
            <!-- Status Summary -->
            <div class="section <?php echo $isEnabled ? 'success' : 'error'; ?>">
                <h2><?php echo $isEnabled ? '✅' : '❌'; ?> Email Blocker Status</h2>
                <table>
                    <tr>
                        <th>Setting</th>
                        <th>Value</th>
                        <th>Status</th>
                    </tr>
                    <tr>
                        <td>Blocking Enabled</td>
                        <td><?php echo $isEnabled ? 'YES' : 'NO'; ?></td>
                        <td><?php echo $isEnabled ? '✅ Active' : '❌ Inactive'; ?></td>
                    </tr>
                    <tr>
                        <td>Force Blocking</td>
                        <td><?php echo $isForce ? 'YES' : 'NO'; ?></td>
                        <td><?php echo $isForce ? '✅ Enabled' : '⚠️ Disabled'; ?></td>
                    </tr>
                    <tr>
                        <td>Development Environment Detected</td>
                        <td><?php echo $isDev ? 'YES' : 'NO'; ?></td>
                        <td><?php echo $isDev ? '✅ Detected' : '❌ Not Detected'; ?></td>
                    </tr>
                    <tr>
                        <td>Blocking Level</td>
                        <td><code><?php echo esc_html($level); ?></code></td>
                        <td><?php echo $level !== 'none' ? '✅ Set' : '❌ Not Set'; ?></td>
                    </tr>
                    <tr>
                        <td>wp_mail Filter Registered</td>
                        <td><?php echo $has_filter ? 'YES (Priority: ' . $filter_priority . ')' : 'NO'; ?></td>
                        <td><?php echo $has_filter ? '✅ Registered' : '❌ NOT REGISTERED'; ?></td>
                    </tr>
                    <tr>
                        <td>Is Actively Blocking</td>
                        <td><?php echo $status['is_actively_blocking'] ? 'YES' : 'NO'; ?></td>
                        <td><?php echo $status['is_actively_blocking'] ? '✅ Blocking' : '❌ Not Blocking'; ?></td>
                    </tr>
                    <tr>
                        <td>Temporarily Disabled</td>
                        <td><?php echo $status['is_temporarily_disabled'] ? 'YES' : 'NO'; ?></td>
                        <td><?php echo $status['is_temporarily_disabled'] ? '⚠️ Paused' : '✅ Active'; ?></td>
                    </tr>
                    <tr>
                        <td>Blocked Emails Count</td>
                        <td><?php echo $blocked_count; ?></td>
                        <td><?php echo $blocked_count > 0 ? '📧 ' . $blocked_count . ' blocked' : '📭 None yet'; ?></td>
                    </tr>
                </table>
            </div>
            
            <!-- Environment Information -->
            <div class="section info">
                <h2>🌐 Environment Information</h2>
                <table>
                    <tr>
                        <th>Setting</th>
                        <th>Value</th>
                    </tr>
                    <tr>
                        <td>HTTP Host</td>
                        <td><code><?php echo esc_html($host); ?></code></td>
                    </tr>
                    <tr>
                        <td>Site URL</td>
                        <td><code><?php echo esc_html($site_url); ?></code></td>
                    </tr>
                    <tr>
                        <td>Server Address</td>
                        <td><code><?php echo esc_html($server_addr); ?></code></td>
                    </tr>
                    <tr>
                        <td>Environment Info</td>
                        <td><code><?php echo esc_html($status['environment_info']); ?></code></td>
                    </tr>
                </table>
            </div>
            
            <!-- Settings Check -->
            <div class="section <?php echo $force_blocking_setting ? 'success' : 'warning'; ?>">
                <h2>⚙️ Settings Check</h2>
                <table>
                    <tr>
                        <th>Setting</th>
                        <th>Database Value</th>
                        <th>Status</th>
                    </tr>
                    <tr>
                        <td>force_email_blocking</td>
                        <td><code><?php echo $force_blocking_setting ? 'true' : 'false'; ?></code></td>
                        <td><?php echo $force_blocking_setting ? '✅ Enabled' : '❌ Disabled'; ?></td>
                    </tr>
                    <tr>
                        <td>email_blocking_level</td>
                        <td><code><?php echo esc_html($blocking_level_setting); ?></code></td>
                        <td><?php echo $blocking_level_setting !== 'not_set' ? '✅ Set' : '⚠️ Not Set'; ?></td>
                    </tr>
                </table>
            </div>
            
            <!-- Recommendations -->
            <div class="section <?php echo $isEnabled && $has_filter ? 'success' : 'error'; ?>">
                <h2>💡 Recommendations</h2>
                <ul>
                    <?php if (!$isEnabled): ?>
                        <li><strong>❌ CRITICAL:</strong> Email blocking is not enabled. 
                            <a href="<?php echo admin_url('admin.php?page=lgl-email-blocking'); ?>">Enable it here</a></li>
                    <?php endif; ?>
                    
                    <?php if (!$has_filter): ?>
                        <li><strong>❌ CRITICAL:</strong> The wp_mail filter is NOT registered. 
                            This means emails are NOT being blocked. Try deactivating and reactivating the plugin.</li>
                    <?php endif; ?>
                    
                    <?php if (!$isForce && !$isDev): ?>
                        <li><strong>⚠️ WARNING:</strong> Neither force blocking nor development environment detection is active. 
                            <a href="<?php echo admin_url('admin.php?page=lgl-email-blocking'); ?>">Enable "Force Block All Emails"</a> to activate blocking.</li>
                    <?php endif; ?>
                    
                    <?php if ($isEnabled && $has_filter): ?>
                        <li><strong>✅ GOOD:</strong> Email blocker appears to be working correctly!</li>
                    <?php endif; ?>
                </ul>
            </div>
            
            <!-- Test Email -->
            <div class="section info">
                <h2>🧪 Test Email Blocking</h2>
                <p>Click the button below to send a test email. It should be blocked if the email blocker is working.</p>
                <a href="<?php echo admin_url('?lgl_email_diagnostic=1&test_email=1'); ?>" class="test-button">Send Test Email</a>
                
                <?php
                if (isset($_GET['test_email'])) {
                    echo '<div style="margin-top: 20px; padding: 15px; background: #f0f8ff; border-left: 4px solid #00a0d2;">';
                    echo '<h3>Test Email Result:</h3>';
                    
                    // Send test email
                    $test_result = wp_mail(
                        'test@example.com',
                        'LGL Email Blocker Test - ' . date('Y-m-d H:i:s'),
                        'This is a test email to verify the email blocker is working. If you receive this, blocking is NOT working!'
                    );
                    
                    if ($test_result) {
                        echo '<p style="color: #dc3232;"><strong>❌ EMAIL WAS SENT!</strong> This means blocking is NOT working.</p>';
                    } else {
                        echo '<p style="color: #46b450;"><strong>✅ EMAIL WAS BLOCKED!</strong> The email blocker is working correctly.</p>';
                    }
                    
                    echo '<p><strong>Check the blocked emails log:</strong> <a href="' . admin_url('admin.php?page=lgl-email-blocking') . '">View Log</a></p>';
                    echo '</div>';
                }
                ?>
            </div>
            
            <!-- Quick Actions -->
            <div class="section info">
                <h2>🔧 Quick Actions</h2>
                <ul>
                    <li><a href="<?php echo admin_url('admin.php?page=lgl-email-blocking'); ?>">Go to Email Blocking Settings</a></li>
                    <li><a href="<?php echo admin_url('admin.php?page=lgl-testing'); ?>">Go to Testing Suite</a></li>
                    <li><a href="<?php echo admin_url('plugins.php'); ?>">Manage Plugins</a></li>
                </ul>
            </div>
            
            <?php
            
        } catch (Exception $e) {
            echo '<div class="section error">';
            echo '<h2>❌ Error</h2>';
            echo '<p><strong>Error Message:</strong> ' . esc_html($e->getMessage()) . '</p>';
            echo '<p><strong>Stack Trace:</strong></p>';
            echo '<pre>' . esc_html($e->getTraceAsString()) . '</pre>';
            echo '</div>';
        }
        ?>
        
        <div class="section warning">
            <h2>⚠️ Security Note</h2>
            <p><strong>REMOVE THIS DIAGNOSTIC TOOL AFTER TROUBLESHOOTING!</strong></p>
            <p>This tool exposes sensitive information and should not be left accessible on production sites.</p>
        </div>
    </div>
</body>
</html>
<?php
exit;

