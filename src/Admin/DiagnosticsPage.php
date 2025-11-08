<?php

namespace UpstateInternational\LGL\Admin;

class DiagnosticsPage {
    public function initialize(): void {
        \add_action('admin_menu', [$this, 'registerMenu']);
    }

    public function registerMenu(): void {
        \add_submenu_page(
            'tools.php',
            \__('LGL Diagnostics', 'integrate-lgl'),
            \__('LGL Diagnostics', 'integrate-lgl'),
            'manage_options',
            'lgl-diagnostics',
            [$this, 'renderPage']
        );
    }

    public function renderPage(): void {
        if (!\current_user_can('manage_options')) {
            return;
        }

        $cronStatus = $this->getCronStatus();
        $hookStatus = $this->getHookStatus();
        $logLines = $this->getRecentLogLines();
        ?>
        <div class="wrap">
            <h1><?php echo \esc_html__('LGL Diagnostics', 'integrate-lgl'); ?></h1>
            <p><?php echo \esc_html__('Use this page to verify that core LGL integration services are registered and running as expected.', 'integrate-lgl'); ?></p>

            <h2><?php echo \esc_html__('Cron Schedules', 'integrate-lgl'); ?></h2>
            <table class="widefat striped">
                <thead>
                    <tr>
                        <th><?php echo \esc_html__('Hook', 'integrate-lgl'); ?></th>
                        <th><?php echo \esc_html__('Next Run', 'integrate-lgl'); ?></th>
                        <th><?php echo \esc_html__('Status', 'integrate-lgl'); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($cronStatus as $hook => $status): ?>
                        <tr>
                            <td><?php echo \esc_html($hook); ?></td>
                            <td><?php echo \esc_html($status['next_run']); ?></td>
                            <td><?php echo \esc_html($status['status']); ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>

            <h2><?php echo \esc_html__('Hook Registration', 'integrate-lgl'); ?></h2>
            <table class="widefat striped">
                <thead>
                    <tr>
                        <th><?php echo \esc_html__('Hook', 'integrate-lgl'); ?></th>
                        <th><?php echo \esc_html__('Registered', 'integrate-lgl'); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($hookStatus as $hook => $registered): ?>
                        <tr>
                            <td><?php echo \esc_html($hook); ?></td>
                            <td><?php echo $registered ? '&#10003;' : '&mdash;'; ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>

            <h2><?php echo \esc_html__('Recent Debug Log Entries', 'integrate-lgl'); ?></h2>
            <pre style="background:#1e1e1e;color:#f5f5f5;padding:16px;max-height:320px;overflow:auto;">
<?php echo \esc_html($logLines ?: \__('No recent log entries were found.', 'integrate-lgl')); ?>
            </pre>
        </div>
        <?php
    }

    private function getCronStatus(): array {
        $hooks = [
            'ui_memberships_daily_update',
            'ui_memberships_delete_inactive',
            'lgl_manual_payment_queue'
        ];

        $status = [];
        foreach ($hooks as $hook) {
            $next = \wp_next_scheduled($hook);
            $status[$hook] = [
                'status' => $next ? \__('Scheduled', 'integrate-lgl') : \__('Not scheduled', 'integrate-lgl'),
                'next_run' => $next ? \wp_date(get_option('date_format') . ' ' . get_option('time_format'), $next) : \__('—', 'integrate-lgl')
            ];
        }
        return $status;
    }

    private function getHookStatus(): array {
        return [
            'JetForm: lgl_register_user' => (bool) \has_action('jet-form-builder/custom-action/lgl_register_user'),
            'JetForm: lgl_add_family_member' => (bool) \has_action('jet-form-builder/custom-action/lgl_add_family_member'),
            'WooCommerce: subscription_status_cancelled' => (bool) \has_action('woocommerce_subscription_status_cancelled'),
            'WooCommerce: order_completed' => (bool) \has_action('woocommerce_payment_complete'),
            'Shortcode: [lgl]' => \shortcode_exists('lgl'),
            'Shortcode: [ui_memberships]' => \shortcode_exists('ui_memberships')
        ];
    }

    private function getRecentLogLines(): string {
        $logFile = WP_CONTENT_DIR . '/debug.log';
        if (!file_exists($logFile)) {
            return '';
        }

        $lines = @file($logFile);
        if (empty($lines)) {
            return '';
        }

        $tail = array_slice($lines, -40);
        return implode('', $tail);
    }
}
