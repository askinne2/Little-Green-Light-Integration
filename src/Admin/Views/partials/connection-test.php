<?php
/**
 * Connection Test Partial
 * 
 * Connection testing interface for the dashboard.
 * 
 * @package UpstateInternational\LGL
 * @since 2.1.0
 * 
 * @var \UpstateInternational\LGL\Admin\SettingsManager $settingsManager Settings manager instance
 */

$settings = $settingsManager->getAll();
$api_url = $settings['api_url'] ?? '';
$api_key = $settings['api_key'] ?? '';
?>

<div class="lgl-connection-test">
    <p>Test your API connection to ensure proper integration with Little Green Light.</p>
    
    <div class="lgl-connection-form">
        <input type="text" id="lgl-test-api-url" 
               value="<?php echo esc_attr($api_url); ?>" 
               placeholder="API URL"
               class="regular-text" />
        
        <input type="password" id="lgl-test-api-key" 
               value="<?php echo esc_attr($api_key); ?>" 
               placeholder="API Key"
               class="regular-text" />
        
        <?php
        echo lgl_partial('components/button', [
            'text' => 'Test Connection',
            'type' => 'primary',
            'attrs' => [
                'id' => 'lgl-test-connection-btn',
                'data-nonce' => wp_create_nonce('lgl_admin_nonce')
            ]
        ]);
        ?>
    </div>
    
    <div id="lgl-connection-result" class="lgl-connection-result" style="display: none; margin-top: 15px;"></div>
</div>

