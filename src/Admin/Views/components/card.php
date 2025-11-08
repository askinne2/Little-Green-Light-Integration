<?php
/**
 * Card Component
 * 
 * Reusable card component for admin pages.
 * 
 * @package UpstateInternational\LGL
 * @since 2.1.0
 * 
 * @var string $title Card title
 * @var string $icon Card icon (emoji or HTML)
 * @var string $content Card content (HTML)
 * @var string $class Additional CSS classes
 */

$defaults = [
    'title' => '',
    'icon' => '',
    'content' => '',
    'class' => ''
];
$args = wp_parse_args($args ?? [], $defaults);
?>

<div class="lgl-card <?php echo esc_attr($args['class']); ?>">
    <?php if ($args['title']): ?>
        <h2 class="lgl-card-title">
            <?php if ($args['icon']): ?>
                <span class="lgl-card-icon"><?php echo $args['icon']; ?></span>
            <?php endif; ?>
            <?php echo esc_html($args['title']); ?>
        </h2>
    <?php endif; ?>
    <div class="lgl-card-content">
        <?php echo $args['content']; ?>
    </div>
</div>

