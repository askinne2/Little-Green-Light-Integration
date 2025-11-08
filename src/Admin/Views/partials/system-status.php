<?php
/**
 * System Status Partial
 * 
 * Displays system status information for the dashboard.
 * 
 * @package UpstateInternational\LGL
 * @since 2.1.0
 * 
 * @var \UpstateInternational\LGL\Admin\SettingsManager $settingsManager Settings manager instance
 */

// Get settings
$settings = $settingsManager->getAll();
$api_url = $settings['api_url'] ?? '';
$api_key = $settings['api_key'] ?? '';
$has_api_credentials = !empty($api_url) && !empty($api_key);

// Check plugin requirements
$php_version = phpversion();
$php_ok = version_compare($php_version, '7.4', '>=');
$wp_version = get_bloginfo('version');
$wp_ok = version_compare($wp_version, '5.0', '>=');
?>

<div class="lgl-system-status">
    <?php
    echo lgl_partial('components/status-item', [
        'label' => 'API Connection',
        'value' => $has_api_credentials ? 'Configured' : 'Not Configured',
        'status' => $has_api_credentials ? 'success' : 'warning'
    ]);
    
    echo lgl_partial('components/status-item', [
        'label' => 'PHP Version',
        'value' => $php_version,
        'status' => $php_ok ? 'success' : 'error'
    ]);
    
    echo lgl_partial('components/status-item', [
        'label' => 'WordPress Version',
        'value' => $wp_version,
        'status' => $wp_ok ? 'success' : 'warning'
    ]);
    
    echo lgl_partial('components/status-item', [
        'label' => 'Debug Mode',
        'value' => ($settings['debug_mode'] ?? false) ? 'Enabled' : 'Disabled',
        'status' => 'info'
    ]);
    
    echo lgl_partial('components/status-item', [
        'label' => 'Test Mode',
        'value' => ($settings['test_mode'] ?? false) ? 'Enabled' : 'Disabled',
        'status' => 'info'
    ]);
    ?>
</div>

