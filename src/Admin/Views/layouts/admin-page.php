<?php
/**
 * Admin Page Layout
 * 
 * Standard layout for admin pages.
 * 
 * @package UpstateInternational\LGL
 * @since 2.1.0
 * 
 * @var string $title Page title
 * @var string $description Page description (optional)
 * @var string $content Page content (HTML)
 */

$defaults = [
    'title' => '',
    'description' => '',
    'content' => ''
];
$args = wp_parse_args($args ?? [], $defaults);

// Get environment info for notice
$environment_notice = '';
if (function_exists('lgl_get_container')) {
    try {
        $container = lgl_get_container();
        if ($container->has('admin.settings_manager')) {
            $settingsManager = $container->get('admin.settings_manager');
            $environment = $settingsManager->getEnvironment();
            $api_url = $settingsManager->getApiUrlForEnvironment();
            
            // Extract database name from API URL
            // LGL URLs can be: https://[db].littlegreenlight.com/api/v1 or https://api.littlegreenlight.com/api/v1
            $database_name = 'Unknown';
            if (!empty($api_url)) {
                // Try to extract subdomain (database name)
                if (preg_match('/https?:\/\/([^\.]+)\.littlegreenlight\.com/', $api_url, $matches)) {
                    $database_name = $matches[1];
                } elseif (strpos($api_url, 'api.littlegreenlight.com') !== false) {
                    // Standard API URL - database name might be in API key or we can't determine it
                    $database_name = 'Standard API';
                } else {
                    // Extract hostname as fallback
                    $parsed = parse_url($api_url);
                    if (isset($parsed['host'])) {
                        $database_name = $parsed['host'];
                    }
                }
            }
            
            $env_label = ucfirst($environment);
            $env_color = $environment === 'dev' ? '#d63638' : '#00a32a';
            
            $environment_notice = sprintf(
                '<div class="notice notice-info" style="border-left-color: %s; margin: 15px 0;">
                    <p style="margin: 0.5em 0;">
                        <strong>🌐 Current Environment:</strong> 
                        <span style="color: %s; font-weight: bold;">%s</span> | 
                        <strong>Database:</strong> <code>%s</code>
                    </p>
                </div>',
                esc_attr($env_color),
                esc_attr($env_color),
                esc_html($env_label),
                esc_html($database_name)
            );
        }
    } catch (\Exception $e) {
        // Silently fail if container not available
    }
}

// Use helper function if available
if (function_exists('lgl_render_environment_notice')) {
    $environment_notice = lgl_render_environment_notice();
}
?>

<div class="wrap lgl-admin-page">
    <h1><?php echo esc_html($args['title']); ?></h1>
    <?php if ($args['description']): ?>
        <p class="lgl-page-description"><?php echo esc_html($args['description']); ?></p>
    <?php endif; ?>
    <?php if ($environment_notice): ?>
        <?php echo $environment_notice; ?>
        <?php if (function_exists('lgl_render_environment_notice_script')): ?>
            <?php echo lgl_render_environment_notice_script(); ?>
        <?php endif; ?>
    <?php endif; ?>
    <div class="lgl-page-content">
        <?php echo $args['content']; ?>
    </div>
</div>

