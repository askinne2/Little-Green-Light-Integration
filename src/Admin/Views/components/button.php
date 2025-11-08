<?php
/**
 * Button Component
 * 
 * Reusable button component.
 * 
 * @package UpstateInternational\LGL
 * @since 2.1.0
 * 
 * @var string $text Button text
 * @var string $type Button type: primary, secondary, link
 * @var string $href Link URL (for link buttons)
 * @var string $class Additional CSS classes
 * @var array $attrs Additional HTML attributes
 */

$defaults = [
    'text' => 'Button',
    'type' => 'primary',
    'href' => '',
    'class' => '',
    'attrs' => []
];
$args = wp_parse_args($args ?? [], $defaults);

$button_class = 'button';
if ($args['type'] === 'primary') {
    $button_class .= ' button-primary';
} elseif ($args['type'] === 'link') {
    $button_class .= ' button-link';
}
$button_class .= ' ' . $args['class'];

$attrs_html = '';
foreach ($args['attrs'] as $key => $value) {
    $attrs_html .= ' ' . esc_attr($key) . '="' . esc_attr($value) . '"';
}
?>

<?php if ($args['href']): ?>
    <a href="<?php echo esc_url($args['href']); ?>" class="<?php echo esc_attr($button_class); ?>"<?php echo $attrs_html; ?>>
        <?php echo esc_html($args['text']); ?>
    </a>
<?php else: ?>
    <button type="button" class="<?php echo esc_attr($button_class); ?>"<?php echo $attrs_html; ?>>
        <?php echo esc_html($args['text']); ?>
    </button>
<?php endif; ?>

