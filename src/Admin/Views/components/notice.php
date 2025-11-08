<?php
/**
 * Notice Component
 * 
 * WordPress admin notice component.
 * 
 * @package UpstateInternational\LGL
 * @since 2.1.0
 * 
 * @var string $message Notice message
 * @var string $type Notice type: success, warning, error, info
 * @var bool $dismissible Whether notice is dismissible
 */

$defaults = [
    'message' => '',
    'type' => 'info',
    'dismissible' => false
];
$args = wp_parse_args($args ?? [], $defaults);

$notice_class = 'notice notice-' . $args['type'];
if ($args['dismissible']) {
    $notice_class .= ' is-dismissible';
}
?>

<div class="<?php echo esc_attr($notice_class); ?>">
    <p><?php echo esc_html($args['message']); ?></p>
</div>

