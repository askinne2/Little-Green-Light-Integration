<?php
/**
 * Statistics Partial
 * 
 * Displays integration statistics for the dashboard.
 * 
 * @package UpstateInternational\LGL
 * @since 2.1.0
 * 
 * @var \UpstateInternational\LGL\LGL\Helper $helper Helper instance
 */

// Get statistics (these would come from actual data in production)
$total_synced = get_option('lgl_total_synced_constituents', 0);
$total_memberships = get_option('lgl_total_memberships', 0);
$total_payments = get_option('lgl_total_payments', 0);
$last_sync = get_option('lgl_last_sync_time', '');
$last_sync_display = $last_sync ? human_time_diff(strtotime($last_sync), current_time('timestamp')) . ' ago' : 'Never';
?>

<div class="lgl-statistics">
    <div class="lgl-stat-grid">
        <div class="lgl-stat-item">
            <div class="lgl-stat-value"><?php echo number_format($total_synced); ?></div>
            <div class="lgl-stat-label">Constituents Synced</div>
        </div>
        
        <div class="lgl-stat-item">
            <div class="lgl-stat-value"><?php echo number_format($total_memberships); ?></div>
            <div class="lgl-stat-label">Active Memberships</div>
        </div>
        
        <div class="lgl-stat-item">
            <div class="lgl-stat-value"><?php echo number_format($total_payments); ?></div>
            <div class="lgl-stat-label">Payments Processed</div>
        </div>
        
        <div class="lgl-stat-item">
            <div class="lgl-stat-value"><?php echo esc_html($last_sync_display); ?></div>
            <div class="lgl-stat-label">Last Sync</div>
        </div>
    </div>
</div>

