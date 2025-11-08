<?php
/**
 * Table Component
 * 
 * Reusable table component with headers and rows.
 * 
 * @package UpstateInternational\LGL
 * @since 2.1.0
 * 
 * @var array $headers Table headers (array of strings)
 * @var array $rows Table rows (array of arrays)
 * @var string $class Additional CSS classes
 */

$defaults = [
    'headers' => [],
    'rows' => [],
    'class' => ''
];
$args = wp_parse_args($args ?? [], $defaults);
?>

<table class="widefat lgl-table <?php echo esc_attr($args['class']); ?>">
    <?php if (!empty($args['headers'])): ?>
        <thead>
            <tr>
                <?php foreach ($args['headers'] as $header): ?>
                    <th><?php echo esc_html($header); ?></th>
                <?php endforeach; ?>
            </tr>
        </thead>
    <?php endif; ?>
    <tbody>
        <?php if (empty($args['rows'])): ?>
            <tr>
                <td colspan="<?php echo count($args['headers']); ?>">No data available</td>
            </tr>
        <?php else: ?>
            <?php foreach ($args['rows'] as $row): ?>
                <tr>
                    <?php foreach ($row as $cell): ?>
                        <td><?php echo esc_html($cell); ?></td>
                    <?php endforeach; ?>
                </tr>
            <?php endforeach; ?>
        <?php endif; ?>
    </tbody>
</table>

