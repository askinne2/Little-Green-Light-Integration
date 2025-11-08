<?php
/**
 * Sync Log Page
 *
 * Presents recent WooCommerce → LGL sync attempts with auditing details.
 *
 * @package UpstateInternational\LGL
 * @since 2.0.0
 */

namespace UpstateInternational\LGL\Admin;

use DateTimeInterface;
use UpstateInternational\LGL\LGL\Helper;

class SyncLogPage {
    private Helper $helper;

    public function __construct(Helper $helper) {
        $this->helper = $helper;
    }

    /**
     * Render the sync log admin page.
     */
    public function render(): void {
        if (!class_exists('WooCommerce')) {
            echo '<div class="notice notice-warning"><p>WooCommerce is required to view sync logs.</p></div>';
            return;
        }

        $filter = isset($_GET['lgl_sync_filter']) ? sanitize_text_field($_GET['lgl_sync_filter']) : 'all';
        $logs = $this->fetchRecentLogs($filter);

        ?>
        <div class="wrap lgl-admin-page">
            <h1>📜 LGL Sync Log</h1>
            <p>Review the latest WooCommerce orders and JetForm submissions synchronized with Little Green Light.</p>

            <form method="get" class="lgl-sync-filter">
                <?php foreach ($_GET as $key => $value) : ?>
                    <?php if ($key === 'lgl_sync_filter') { continue; } ?>
                    <input type="hidden" name="<?php echo esc_attr($key); ?>" value="<?php echo esc_attr($value); ?>">
                <?php endforeach; ?>
                <label for="lgl_sync_filter">Filter by status:</label>
                <select name="lgl_sync_filter" id="lgl_sync_filter" onchange="this.form.submit()">
                    <option value="all" <?php selected($filter, 'all'); ?>>All</option>
                    <option value="synced" <?php selected($filter, 'synced'); ?>>Synced</option>
                    <option value="partial" <?php selected($filter, 'partial'); ?>>Partial</option>
                    <option value="unsynced" <?php selected($filter, 'unsynced'); ?>>Unsynced</option>
                </select>
            </form>

            <?php if (empty($logs)) : ?>
                <div class="notice notice-info">
                    <p>No sync activity found for the selected filters.</p>
                </div>
            <?php else : ?>
                <table class="wp-list-table widefat fixed striped table-view-list lgl-sync-table">
                    <thead>
                        <tr>
                            <th scope="col">Order</th>
                            <th scope="col">Date</th>
                            <th scope="col">Status</th>
                            <th scope="col">LGL Constituent</th>
                            <th scope="col">Match Method</th>
                            <th scope="col">Payment</th>
                            <th scope="col">Verification</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($logs as $log) : ?>
                            <tr>
                                <td>
                                    <strong>
                                        <a href="<?php echo esc_url($log['admin_url']); ?>">
                                            #<?php echo esc_html($log['order_number']); ?>
                                        </a>
                                    </strong>
                                    <div class="row-actions">
                                        <span class="view">
                                            <a href="<?php echo esc_url($log['admin_url']); ?>">View</a>
                                        </span>
                                    </div>
                                </td>
                                <td><?php echo esc_html($log['date_display']); ?></td>
                                <td>
                                    <span class="lgl-sync-status lgl-sync-status-<?php echo esc_attr($log['status']); ?>">
                                        <?php echo esc_html(ucfirst($log['status'])); ?>
                                    </span>
                                </td>
                                <td>
                                    <?php if ($log['lgl_id']) : ?>
                                        <code><?php echo esc_html($log['lgl_id']); ?></code>
                                    <?php else : ?>
                                        <span class="dashicons dashicons-warning"></span>
                                    <?php endif; ?>
                                    <?php if ($log['matched_email']) : ?>
                                        <div class="description"><?php echo esc_html($log['matched_email']); ?></div>
                                    <?php endif; ?>
                                </td>
                                <td><?php echo $log['match_method'] ? esc_html(ucfirst($log['match_method'])) : '—'; ?></td>
                                <td>
                                    <?php if ($log['payment_id']) : ?>
                                        <code><?php echo esc_html($log['payment_id']); ?></code>
                                    <?php else : ?>
                                        —
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <details>
                                        <summary>Details</summary>
                                        <div class="lgl-sync-details">
                                            <strong>Constituent Response:</strong>
                                            <pre><?php echo esc_html($log['constituent_summary']); ?></pre>
                                            <strong>Payment Response:</strong>
                                            <pre><?php echo esc_html($log['payment_summary']); ?></pre>
                                        </div>
                                    </details>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </div>

        <style>
            .lgl-sync-filter {
                margin: 0 0 15px;
            }
            .lgl-sync-status {
                display: inline-block;
                padding: 3px 8px;
                border-radius: 999px;
                font-size: 11px;
                text-transform: uppercase;
                letter-spacing: 0.5px;
            }
            .lgl-sync-status-synced {
                background: #e3f9e5;
                color: #1b5e20;
            }
            .lgl-sync-status-partial {
                background: #fff3cd;
                color: #856404;
            }
            .lgl-sync-status-unsynced {
                background: #fdecea;
                color: #751f1c;
            }
            .lgl-sync-details {
                margin-top: 8px;
            }
            .lgl-sync-details pre {
                background: #f6f7f7;
                padding: 10px;
                max-height: 180px;
                overflow: auto;
            }
        </style>
        <?php
    }

    /**
     * Fetch recent sync logs from WooCommerce orders.
     *
     * @param string $statusFilter
     * @return array<int,array<string,mixed>>
     */
    private function fetchRecentLogs(string $statusFilter): array {
        if (!function_exists('wc_get_orders')) {
            return [];
        }

        $query = new \WC_Order_Query([
            'limit' => 50,
            'orderby' => 'date',
            'order' => 'DESC',
            'meta_query' => [
                [
                    'key' => '_lgl_sync_status',
                    'compare' => 'EXISTS'
                ]
            ]
        ]);

        $orders = $query->get_orders();
        $logs = [];

        foreach ($orders as $order) {
            $status = $order->get_meta('_lgl_sync_status');
            if (!$status) {
                continue;
            }
            if ($statusFilter !== 'all' && $status !== $statusFilter) {
                continue;
            }

            $constituentResponse = $order->get_meta('_lgl_constituent_response');
            $paymentResponse = $order->get_meta('_lgl_payment_response');

            $logs[] = [
                'order_id' => $order->get_id(),
                'order_number' => $order->get_order_number(),
                'admin_url' => $order->get_edit_order_url(),
                'date_display' => $this->formatOrderDate($order->get_date_created()),
                'status' => $status,
                'lgl_id' => $order->get_meta('_lgl_lgl_id'),
                'match_method' => $order->get_meta('_lgl_match_method'),
                'matched_email' => $order->get_meta('_lgl_matched_email'),
                'payment_id' => $order->get_meta('_lgl_payment_id'),
                'constituent_summary' => $this->summarizeResponse($constituentResponse),
                'payment_summary' => $this->summarizeResponse($paymentResponse)
            ];
        }

        return $logs;
    }

    /**
     * Format order date for display.
     *
     * @param DateTimeInterface|null $date
     * @return string
     */
    private function formatOrderDate(?DateTimeInterface $date): string {
        if (!$date) {
            return '—';
        }
        return $date->format('M j, Y g:i a');
    }

    /**
     * Summarize raw JSON responses for compact display.
     *
     * @param string|null $response
     * @return string
     */
    private function summarizeResponse(?string $response): string {
        if (empty($response)) {
            return 'No data';
        }

        $decoded = json_decode($response, true);
        if (json_last_error() !== JSON_ERROR_NONE || !is_array($decoded)) {
            return $response;
        }

        $httpCode = $decoded['http_code'] ?? ($decoded['data']['http_code'] ?? null);
        $success = $decoded['success'] ?? null;

        $summary = [
            'success' => $success ? 'true' : 'false',
            'http_code' => $httpCode
        ];

        if (isset($decoded['error'])) {
            $summary['error'] = $decoded['error'];
        }

        if (isset($decoded['data']['id'])) {
            $summary['id'] = $decoded['data']['id'];
        }

        return wp_json_encode($summary, JSON_PRETTY_PRINT);
    }
}

