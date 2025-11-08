<?php
/**
 * Status Item Component
 * 
 * Displays a status item with label, value, and status indicator.
 * 
 * @package UpstateInternational\LGL
 * @since 2.1.0
 * 
 * @var string $label Status label
 * @var string $value Status value
 * @var string $status Status type: success, warning, error, info
 */

$defaults = [
    'label' => '',
    'value' => '',
    'status' => 'info'
];
$args = wp_parse_args($args ?? [], $defaults);

$icons = [
    'success' => '✅',
    'warning' => '⚠️',
    'error' => '❌',
    'info' => 'ℹ️'
];
$icon = $icons[$args['status']] ?? $icons['info'];
?>

<div class="lgl-status-item lgl-status-<?php echo esc_attr($args['status']); ?>">
    <span class="lgl-status-label"><?php echo esc_html($args['label']); ?></span>
    <span class="lgl-status-value">
        <span class="lgl-status-icon"><?php echo $icon; ?></span>
        <?php echo esc_html($args['value']); ?>
    </span>
</div>

