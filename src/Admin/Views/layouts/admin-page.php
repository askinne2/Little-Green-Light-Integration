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
?>

<div class="wrap lgl-admin-page">
    <h1><?php echo esc_html($args['title']); ?></h1>
    <?php if ($args['description']): ?>
        <p class="lgl-page-description"><?php echo esc_html($args['description']); ?></p>
    <?php endif; ?>
    <div class="lgl-page-content">
        <?php echo $args['content']; ?>
    </div>
</div>

